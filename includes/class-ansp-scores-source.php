<?php
/**
 * Published scores from the device-sync mirror, shown in the singer-facing list.
 *
 * Phase 3 of the Singers Hub device sync (`claude/portal/Device_Sync_Spec.md`).
 * Phase 1 built a worker that walks Tom's Drive folders, identifies each score by
 * its CONTENT rather than its filename, and publishes it into a private Google
 * Cloud Storage mirror under a name frozen at first publication. That is why
 * fifty singers' annotation layers keep working when Tom re-uploads a file with a
 * letter missing and a date appended.
 *
 * None of that reached a singer until this file. The worker's mirror is private;
 * this is the door.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THREE DESIGN DECISIONS WORTH KNOWING BEFORE YOU CHANGE ANYTHING HERE
 *
 * 1. READ-ONLY. Published scores are merged into the materials array at RENDER
 *    time and are never written into `_ansp_materials`. Two reasons. It keeps
 *    the mirror the single source of truth for what is published — there is no
 *    second copy to drift. And it means this does not depend on the
 *    `save_materials()` upsert-by-id fix (TSK-0216) landing first: that endpoint
 *    currently appends a duplicate row sharing an id rather than updating, which
 *    would be a bad thing to build a sync loop on top of. Persisting these as
 *    real rows is Phase D, deliberately later.
 *
 * 2. IT FAILS OPEN. If the worker is unreachable, misconfigured, slow or broken,
 *    every path here returns the materials array exactly as it arrived. A singer
 *    at a rehearsal must never lose access to music they already had because a
 *    Cloud Run service is having a bad morning. There is no failure mode in this
 *    file that removes anything from the page.
 *
 * 3. THE TOKEN NEVER REACHES THE BROWSER. Every worker call is server-side. What
 *    does reach the page is a short-lived signed URL per score, which is the
 *    intended delivery mechanism — it expires on its own and carries no
 *    credential. The portal is login-gated and not page-cached, so those URLs are
 *    not sitting in a public cache.
 *
 * The rows produced here are shaped exactly like hand-entered material rows, so
 * piece grouping, the tag filter and the existing row layout all work on them
 * without knowing they came from somewhere else.
 *
 * @package ArsNovaSingersPortal
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ANSP_Scores_Source
 */
class ANSP_Scores_Source {

	/** Option + constant names. A wp-config constant always wins over the option. */
	const OPT_URL       = 'ansp_scores_worker_url';
	const OPT_TOKEN     = 'ansp_scores_worker_token';
	const CONST_URL     = 'ANSP_SCORES_URL';
	const CONST_TOKEN   = 'ANSP_SCORES_TOKEN';

	/** Per-project meta naming the worker-side project this WP project maps to. */
	const META_PROJECT  = 'ansp_scores_project';

	/**
	 * Bumped whenever the worker URL or token changes.
	 *
	 * Library answers are cached per URL and group. Without this, changing the
	 * token leaves ten minutes of answers fetched with the old one - which reads
	 * as "the fix did not work" and is the sort of thing that gets a correct fix
	 * reverted.
	 */
	const OPT_CACHE_BUST = 'ansp_scores_cache_bust';

	/** Rows are prefixed so they are traceable and can never collide with a uniqid(). */
	const ID_PREFIX     = 'ansp_score_';

	/**
	 * How long a group's library is cached.
	 *
	 * Short on purpose. The signed URLs inside the response expire in 15 minutes,
	 * so caching for longer than that would hand singers dead links. Ten minutes
	 * leaves headroom for a page sitting open briefly before a click.
	 */
	const CACHE_MINUTES = 10;

	/** Seconds before a worker call is abandoned. A slow score list must not hang the page. */
	const TIMEOUT       = 8;

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'ansp_visible_materials', array( __CLASS__, 'append_published_scores' ), 10, 3 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . ANSP_CPT::POST_TYPE, array( __CLASS__, 'save_meta' ), 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/* -------------------------------------------------------------------
	 * Configuration
	 * ---------------------------------------------------------------- */

	/**
	 * Worker base URL, without a trailing slash. '' when not configured.
	 *
	 * @return string
	 */
	public static function worker_url() {
		$url = defined( self::CONST_URL ) ? (string) constant( self::CONST_URL ) : (string) get_option( self::OPT_URL, '' );
		return rtrim( trim( $url ), '/' );
	}

	/**
	 * Bearer token. '' when not configured.
	 *
	 * Read from a wp-config constant first so the token can live outside the
	 * database entirely, matching how the Google connector handles its key.
	 *
	 * @return string
	 */
	public static function worker_token() {
		$token = defined( self::CONST_TOKEN ) ? (string) constant( self::CONST_TOKEN ) : (string) get_option( self::OPT_TOKEN, '' );
		return trim( $token );
	}

	/**
	 * Is the mirror wired up at all?
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== self::worker_url() && '' !== self::worker_token();
	}

	/**
	 * Make every cached library answer stale, immediately.
	 *
	 * Called after the URL or token changes. Transients cannot be deleted by
	 * wildcard without touching the database directly, so the key carries a
	 * version instead and this moves it.
	 */
	public static function bust_cache() {
		update_option( self::OPT_CACHE_BUST, (string) time() );
	}

	/* -------------------------------------------------------------------
	 * Fetching
	 * ---------------------------------------------------------------- */

	/**
	 * Every published score for one group, as the worker reports it.
	 *
	 * Returns an empty array on ANY failure — unconfigured, network error, non-200,
	 * unparseable body. Callers must treat an empty array as "nothing to add",
	 * never as "nothing is published", because those look identical from here and
	 * only one of them is worth telling a singer about.
	 *
	 * @param string $group_slug The worker's group id, VERBATIM. Not a WP slug - see clean_group().
	 * @return array[]
	 */
	public static function library( $group_slug ) {
		$group_slug = static::clean_group( $group_slug );
		if ( '' === $group_slug || ! self::is_configured() ) {
			return array();
		}

		$cache_key = 'ansp_scores_lib_' . md5(
			self::worker_url() . '|' . $group_slug . '|' . (string) get_option( self::OPT_CACHE_BUST, '0' )
		);
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			self::worker_url() . '/library/' . rawurlencode( $group_slug ),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . self::worker_token(),
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::log( 'library fetch failed for ' . $group_slug . ': ' . $response->get_error_message() );
			return array();
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			self::log( 'library fetch returned HTTP ' . wp_remote_retrieve_response_code( $response ) . ' for ' . $group_slug );
			return array();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['ok'] ) || ! isset( $body['scores'] ) || ! is_array( $body['scores'] ) ) {
			self::log( 'library response was not the shape we expect for ' . $group_slug );
			return array();
		}

		set_transient( $cache_key, $body['scores'], self::CACHE_MINUTES * MINUTE_IN_SECONDS );
		return $body['scores'];
	}

	/* -------------------------------------------------------------------
	 * The filter
	 * ---------------------------------------------------------------- */

	/**
	 * Append this project's published scores to the visible materials array.
	 *
	 * Hooked to `ansp_visible_materials`, which fires at the end of
	 * ANSP_Permissions::get_visible_materials(). That placement matters: the
	 * permission decision has already been made on hand-entered rows by the time
	 * we get here, and we scope OUR rows by the groups on the project itself, so
	 * a singer only ever sees a mirror score for a project they can already open.
	 *
	 * Every early return hands back $materials untouched. That is the fail-open
	 * contract and it should stay that way.
	 *
	 * @param array[] $materials  Rows the permission engine decided are visible.
	 * @param int     $project_id Project post ID.
	 * @param int     $user_id    Viewer.
	 * @return array[]
	 */
	public static function append_published_scores( $materials, $project_id, $user_id = 0 ) {
		if ( ! is_array( $materials ) ) {
			return array();
		}
		if ( ! static::is_configured() ) {
			return $materials;
		}

		$project_id = (int) $project_id;
		if ( ! $project_id ) {
			return $materials;
		}

		$target = static::mirror_target( $project_id );
		$groups = $target['groups'];
		if ( empty( $groups ) ) {
			return $materials;
		}

		$wanted_project = $target['project'];
		$seen           = array();
		$added          = array();

		foreach ( $groups as $group_slug ) {
			foreach ( static::library( $group_slug ) as $score ) {
				if ( ! is_array( $score ) || empty( $score['work_id'] ) ) {
					continue;
				}
				// A singer holding two groups that both publish the same work sees it once.
				if ( isset( $seen[ $score['work_id'] ] ) ) {
					continue;
				}
				if ( ! self::score_belongs_to_project( $score, $wanted_project ) ) {
					continue;
				}
				$seen[ $score['work_id'] ] = true;
				$added[]                   = self::to_material_row( $score, $group_slug );
			}
		}

		if ( empty( $added ) ) {
			return $materials;
		}
		return array_merge( $materials, $added );
	}

	/**
	 * Where in the mirror this project's scores live.
	 *
	 * The mirror stores two coordinates, not one: published objects are
	 * `scores/<group>/<project>/<canonical>.pdf`. The group is NOT a WordPress
	 * slug - it is whatever free-text label was handed to the worker's /scan when
	 * that folder was first walked, and the worker compares it byte for byte.
	 * Deriving it from the ans_group slug was the bug this method exists to fix:
	 * Chamber Singers is `cs` in WordPress and `chamber-singers` in the mirror, so
	 * every lookup returned nothing and said nothing.
	 *
	 * One field therefore carries the whole address. `chamber-singers/26-27 CS`
	 * names both halves. A bare `26-27 CS` names only the project and lets the
	 * group come from the project's own ans_group terms. Empty falls back to the
	 * project title, which is a guess and should be expected to be wrong more
	 * often than right - the two systems were named by different people for
	 * different reasons and there is no cause for them to agree.
	 *
	 * Split on the FIRST slash only, so a nested project folder survives intact.
	 *
	 * @param int $project_id Project post ID.
	 * @return array Two keys: 'groups' (string[]) and 'project' (string).
	 */
	public static function mirror_target( $project_id ) {
		$project_id = (int) $project_id;
		$explicit   = trim( (string) get_post_meta( $project_id, self::META_PROJECT, true ) );

		if ( '' !== $explicit && false !== strpos( $explicit, '/' ) ) {
			$parts   = explode( '/', $explicit, 2 );
			$group   = static::clean_group( $parts[0] );
			$project = trim( $parts[1] );
			return array(
				'groups'  => '' === $group ? array() : array( $group ),
				'project' => $project,
			);
		}

		$project = '' !== $explicit ? $explicit : trim( (string) get_the_title( $project_id ) );
		return array(
			'groups'  => static::project_group_slugs( $project_id ),
			'project' => $project,
		);
	}

	/**
	 * The worker-side project name this WP project maps to.
	 *
	 * @param int $project_id Project post ID.
	 * @return string
	 */
	public static function project_key( $project_id ) {
		$target = static::mirror_target( $project_id );
		return $target['project'];
	}

	/**
	 * The worker-side group id(s) this WP project reads from, for display only.
	 *
	 * @param int $project_id Project post ID.
	 * @return string
	 */
	public static function project_group_key( $project_id ) {
		$target = static::mirror_target( $project_id );
		return empty( $target['groups'] ) ? '' : implode( ', ', $target['groups'] );
	}

	/**
	 * A group id fit to sit in a URL path, without mangling it.
	 *
	 * sanitize_title() used to do this job, and that was the defect: it
	 * lowercases and hyphenates, so a group scanned as "Full Group" would be
	 * asked for as "full-group" and match nothing, with no error anywhere. The
	 * worker compares the string exactly, so the only things worth removing here
	 * are the ones that would break the path or let a value escape its own
	 * segment. Everything else is passed through untouched, on purpose.
	 *
	 * @param string $group Raw group id.
	 * @return string
	 */
	protected static function clean_group( $group ) {
		$group = trim( (string) $group );
		if ( false !== strpos( $group, '/' ) || false !== strpos( $group, '\\' ) ) {
			return '';
		}
		if ( '.' === $group || '..' === $group ) {
			return '';
		}
		return trim( preg_replace( '/[\x00-\x1F\x7F]/', '', $group ) );
	}

	/**
	 * Does a published score belong to the project being rendered?
	 *
	 * Compared case-insensitively with whitespace collapsed, because "26-27 CS"
	 * and "26-27  cs" are the same folder to a human and the difference is a typo,
	 * not a decision. An EMPTY project key matches nothing — better to show a
	 * singer no mirror scores than to show them another project's music.
	 *
	 * @param array  $score          One row from the worker's library.
	 * @param string $wanted_project The project key we are rendering.
	 * @return bool
	 */
	public static function score_belongs_to_project( $score, $wanted_project ) {
		$wanted = self::normalise( $wanted_project );
		if ( '' === $wanted ) {
			return false;
		}
		return self::normalise( isset( $score['project'] ) ? $score['project'] : '' ) === $wanted;
	}

	/**
	 * Lowercase, whitespace-collapsed, for comparing project names only.
	 *
	 * @param string $value Raw.
	 * @return string
	 */
	protected static function normalise( $value ) {
		return strtolower( trim( preg_replace( '/\s+/', ' ', (string) $value ) ) );
	}

	/**
	 * Top-level and child group slugs attached to a project.
	 *
	 * @param int $project_id Project post ID.
	 * @return string[]
	 */
	protected static function project_group_slugs( $project_id ) {
		$terms = get_the_terms( (int) $project_id, 'ans_group' );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$slugs = array();
		foreach ( $terms as $term ) {
			if ( $term instanceof WP_Term ) {
				$slugs[] = $term->slug;
			}
		}
		return array_values( array_unique( $slugs ) );
	}

	/* -------------------------------------------------------------------
	 * Shaping
	 * ---------------------------------------------------------------- */

	/**
	 * Turn a worker library row into something the materials templates understand.
	 *
	 * Deliberately shaped as an ordinary material row rather than a new kind of
	 * thing, so `material-item.php`, the piece grouping and the tag filter all
	 * work on it with no special cases. The only tells are the id prefix and the
	 * `source` key, which nothing renders and everything can inspect.
	 *
	 * `groups` is left EMPTY on purpose. These rows were already scoped by the
	 * project's own groups before they got here, and populating it would put a
	 * second, quieter permission decision in a file that is not the permission
	 * engine. Groups stay ANSP_Permissions' job.
	 *
	 * @param array  $score      One row from the worker's library.
	 * @param string $group_slug The group whose library it came from.
	 * @return array
	 */
	protected static function to_material_row( $score, $group_slug ) {
		$canonical = isset( $score['canonical'] ) ? (string) $score['canonical'] : '';
		$pages     = isset( $score['pages'] ) ? (int) $score['pages'] : 0;
		$version   = isset( $score['version'] ) ? (int) $score['version'] : 1;
		$revised   = ! empty( $score['revised'] );

		$note = $pages ? sprintf(
			/* translators: %d: number of pages in the score */
			_n( '%d page', '%d pages', $pages, 'ans-singers-portal' ),
			$pages
		) : '';

		// Only say "updated" when it is actually true. A version badge on every
		// score would train singers to ignore the one that matters.
		if ( $revised ) {
			$note = trim(
				$note . ' ' . sprintf(
					/* translators: %d: version number of the score */
					__( '— updated (v%d)', 'ans-singers-portal' ),
					$version
				)
			);
		}

		return array(
			'id'     => self::ID_PREFIX . ( isset( $score['work_id'] ) ? sanitize_key( $score['work_id'] ) : '' ),
			'type'   => 'sheet_music',
			'title'  => $canonical,
			'url'    => isset( $score['url'] ) ? (string) $score['url'] : '',
			'note'   => $note,
			'piece'  => $canonical,
			'tags'   => $revised ? array( __( 'Updated', 'ans-singers-portal' ) ) : array(),
			'groups' => array(),
			'source' => 'scores-mirror',
		);
	}

	/* -------------------------------------------------------------------
	 * Admin: per-project mapping
	 * ---------------------------------------------------------------- */

	/**
	 * Register the per-project meta box.
	 */
	public static function add_meta_box() {
		add_meta_box(
			'ansp_scores_source',
			__( 'Sheet-Music Mirror', 'ans-singers-portal' ),
			array( __CLASS__, 'render_meta_box' ),
			ANSP_CPT::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * The per-project mapping field.
	 *
	 * @param WP_Post $post Current project.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'ansp_save_scores_source', 'ansp_scores_source_nonce' );
		$value  = (string) get_post_meta( $post->ID, self::META_PROJECT, true );
		$target = self::mirror_target( $post->ID );
		$actual = $target['project'];
		$groups = empty( $target['groups'] ) ? '' : implode( ', ', $target['groups'] );
		?>
		<p class="description">
			<?php esc_html_e( 'Which mirror folder this project reads its published sheet music from, as group/project - for example chamber-singers/26-27 CS. The group is the folder Tom scanned, not the WordPress group name; the two are not required to match and usually do not. Singers Portal > Sheet-Music Mirror lists the exact strings the worker has.', 'ans-singers-portal' ); ?>
		</p>
		<p>
			<input type="text" class="widefat" name="ansp_scores_project"
				value="<?php echo esc_attr( $value ); ?>"
				placeholder="<?php echo esc_attr( get_the_title( $post->ID ) ); ?>"
				spellcheck="false" autocomplete="off" />
		</p>
		<p class="description">
			<?php
			printf(
				/* translators: 1: mirror group id, 2: mirror project name */
				esc_html__( 'Currently reading group %1$s, project %2$s.', 'ans-singers-portal' ),
				'<code>' . esc_html( '' !== $groups ? $groups : '—' ) . '</code>',
				'<code>' . esc_html( '' !== $actual ? $actual : '—' ) . '</code>'
			);
			?>
		</p>
		<?php if ( ! self::is_configured() ) : ?>
			<p class="description" style="color:#b32d2e;">
				<?php esc_html_e( 'The mirror is not configured yet, so nothing will appear. Singers Portal → Sheet-Music Mirror.', 'ans-singers-portal' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Save the per-project mapping.
	 *
	 * @param int     $post_id Project post ID.
	 * @param WP_Post $post    Project.
	 */
	public static function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['ansp_scores_source_nonce'] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['ansp_scores_source_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'ansp_save_scores_source' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$value = isset( $_POST['ansp_scores_project'] )
			? trim( sanitize_text_field( wp_unslash( $_POST['ansp_scores_project'] ) ) )
			: '';
		if ( '' === $value ) {
			delete_post_meta( $post_id, self::META_PROJECT );
		} else {
			update_post_meta( $post_id, self::META_PROJECT, $value );
		}
	}

	/* -------------------------------------------------------------------
	 * Admin: settings
	 * ---------------------------------------------------------------- */

	/**
	 * Settings page under the portal menu.
	 */
	public static function add_settings_page() {
		add_submenu_page(
			'ansp-dashboard',
			__( 'Sheet-Music Mirror', 'ans-singers-portal' ),
			__( 'Sheet-Music Mirror', 'ans-singers-portal' ),
			'manage_options',
			'ansp-scores-mirror',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Register the two options.
	 */
	public static function register_settings() {
		register_setting(
			'ansp_scores_mirror',
			self::OPT_URL,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);
		register_setting(
			'ansp_scores_mirror',
			self::OPT_TOKEN,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
	}

	/**
	 * The settings screen, plus a live connection check.
	 *
	 * The check is the point of this page. "Saved" tells an admin nothing; a
	 * count of what the worker actually returned tells them whether singers will
	 * see anything.
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$url_locked   = defined( self::CONST_URL );
		$token_locked = defined( self::CONST_TOKEN );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Sheet-Music Mirror', 'ans-singers-portal' ); ?></h1>
			<p class="description" style="max-width:46em;">
				<?php esc_html_e( 'Sheet music published by the device-sync worker appears in each project\'s materials list automatically, under the piece it belongs to. Nothing is copied into WordPress — the mirror stays the source of truth, and this site only ever reads it.', 'ans-singers-portal' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'ansp_scores_mirror' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPT_URL ); ?>"><?php esc_html_e( 'Worker URL', 'ans-singers-portal' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="<?php echo esc_attr( self::OPT_URL ); ?>"
								name="<?php echo esc_attr( self::OPT_URL ); ?>"
								value="<?php echo esc_attr( get_option( self::OPT_URL, '' ) ); ?>"
								<?php disabled( $url_locked ); ?> />
							<?php if ( $url_locked ) : ?>
								<p class="description"><?php esc_html_e( 'Set in wp-config.php, which wins over this field.', 'ans-singers-portal' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPT_TOKEN ); ?>"><?php esc_html_e( 'Worker token', 'ans-singers-portal' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="<?php echo esc_attr( self::OPT_TOKEN ); ?>"
								name="<?php echo esc_attr( self::OPT_TOKEN ); ?>"
								value="<?php echo esc_attr( get_option( self::OPT_TOKEN, '' ) ); ?>"
								autocomplete="off" <?php disabled( $token_locked ); ?> />
							<p class="description">
								<?php esc_html_e( 'Used server-side only. Singers receive short-lived signed links, never this token. Prefer setting ANSP_SCORES_TOKEN in wp-config.php so it never touches the database.', 'ans-singers-portal' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Connection check', 'ans-singers-portal' ); ?></h2>
			<?php self::render_connection_check(); ?>
		</div>
		<?php
	}

	/**
	 * Ask the worker what it has, and say so plainly.
	 */
	protected static function render_connection_check() {
		if ( ! self::is_configured() ) {
			echo '<p>' . esc_html__( 'Not configured yet. Singers see their hand-entered materials exactly as before.', 'ans-singers-portal' ) . '</p>';
			return;
		}

		$probe = static::configured_mirror_groups();
		$terms = get_terms(
			array(
				'taxonomy'   => 'ans_group',
				'hide_empty' => false,
				'fields'     => 'slugs',
			)
		);
		if ( is_array( $terms ) ) {
			foreach ( $terms as $slug ) {
				$probe[] = (string) $slug;
			}
		}
		$probe = array_values( array_unique( array_filter( array_map( 'trim', $probe ) ) ) );

		if ( empty( $probe ) ) {
			echo '<p>' . esc_html__( 'Nothing to ask about yet: no project names a mirror folder, and no groups exist.', 'ans-singers-portal' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:52em;"><thead><tr><th>' .
			esc_html__( 'Group asked for', 'ans-singers-portal' ) . '</th><th>' .
			esc_html__( 'Scores', 'ans-singers-portal' ) . '</th><th>' .
			esc_html__( 'Paste one of these into a project', 'ans-singers-portal' ) . '</th></tr></thead><tbody>';

		$total = 0;
		foreach ( $probe as $group ) {
			$scores   = static::library( $group );
			$total   += count( $scores );
			$projects = array();
			foreach ( $scores as $score ) {
				if ( empty( $score['project'] ) ) {
					continue;
				}
				$path = $group . '/' . (string) $score['project'];
				if ( ! isset( $projects[ $path ] ) ) {
					$projects[ $path ] = 0;
				}
				$projects[ $path ]++;
			}
			$cells = array();
			foreach ( $projects as $path => $n ) {
				$cells[] = '<code>' . esc_html( $path ) . '</code> (' . esc_html( (string) $n ) . ')';
			}
			printf(
				'<tr><td><code>%s</code></td><td>%s</td><td>%s</td></tr>',
				esc_html( $group ),
				esc_html( (string) count( $scores ) ),
				$cells ? implode( '<br />', $cells ) : esc_html( '—' )
			);
		}
		echo '</tbody></table>';

		if ( ! $total ) {
			echo '<p class="description" style="color:#b32d2e;">' .
				esc_html__( 'Every name came back empty. That is what a name mismatch looks like: the worker answers fine and has nothing filed under any of these. The group id is whatever label was passed when the folder was scanned, not the WordPress group name, and nothing forces the two to agree.', 'ans-singers-portal' ) .
				'</p>';
		}
		echo '<p class="description">' .
			esc_html__( 'These are the names this site knows to ask about: every mirror folder already named on a project, plus every WordPress group slug as a guess. The worker has no endpoint that lists its groups, so a folder nobody has named here cannot appear in this table.', 'ans-singers-portal' ) .
			'</p>';
	}

	/**
	 * Mirror group ids already named on a project, so the check can ask about them.
	 *
	 * @return string[]
	 */
	public static function configured_mirror_groups() {
		$rows = get_posts(
			array(
				'post_type'        => 'ans_project',
				'post_status'      => 'any',
				'numberposts'      => 200,
				'fields'           => 'ids',
				'meta_key'         => self::META_PROJECT,
				'suppress_filters' => false,
			)
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$groups = array();
		foreach ( $rows as $id ) {
			$value = trim( (string) get_post_meta( (int) $id, self::META_PROJECT, true ) );
			if ( '' === $value || false === strpos( $value, '/' ) ) {
				continue;
			}
			$parts = explode( '/', $value, 2 );
			$group = static::clean_group( $parts[0] );
			if ( '' !== $group ) {
				$groups[] = $group;
			}
		}
		return array_values( array_unique( $groups ) );
	}

	/* -------------------------------------------------------------------
	 * Utility
	 * ---------------------------------------------------------------- */

	/**
	 * Log without ever being the reason a page dies.
	 *
	 * @param string $message What happened.
	 */
	protected static function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[ansp-scores-source] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
