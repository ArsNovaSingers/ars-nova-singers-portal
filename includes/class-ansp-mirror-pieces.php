<?php
/**
 * Which piece a published mirror file sits under, and what it is called there.
 *
 * Piece Grouping Spec, Phases B and C, for files that arrive from the mirror.
 *
 * WHY THIS EXISTS. Until 1.38.0 every mirror row was filed under a piece named
 * after its own filename. Rivers & Streams therefore rendered a heading called
 * "ANS-Margutti-Rivers" holding one item called "ANS-Margutti-Rivers", once per
 * score and once per rehearsal note, beneath the pieces Zahnay had already
 * built by hand - two parallel lists of the same music, neither complete.
 *
 * THE RULE, in order:
 *   1. A saved entry for this work on this project wins. Always. (P4)
 *   2. A rehearsal note goes under "Rehearsal notes".
 *   3. A composer / folder token that names exactly ONE piece already on the
 *      project files it there. "Tedesco2026-mvt7" and a Tedesco-RehRecordings
 *      folder both find "Mario Castelnuovo-Tedesco, Romancero Gitano".
 *   4. A recording with no match goes under its Drive folder's name, so a
 *      folder of rehearsal takes still reads as one group.
 *   5. Anything else is unfiled and lands in "Other materials" (P3) - never
 *      hidden for lacking a label.
 *
 * Steps 3 and 4 read filenames and folder names. That is allowed here and
 * nowhere near the Librarian, for the reason the spec gives: a wrong piece is
 * cosmetic and reversible, a wrong identity is not. Nothing in this file
 * affects who can see a row (P1). The one exception is `hidden`, which is a
 * curation switch for everyone - "someone has to be able to say no, and say
 * it once" - and which the durable link and the player both honour because
 * they ask the same visible-materials question the page does.
 *
 * Storage: one post-meta array on the project, keyed by the worker's work_id,
 * because the canonical name can repeat across folders and the id cannot.
 *
 * @package ArsNovaSingersPortal
 */

defined( 'ABSPATH' ) || exit;

/**
 * Piece map for mirror rows.
 */
class ANSP_Mirror_Pieces {

	/** Project meta holding the saved map. */
	const META = '_ansp_mirror_pieces';

	/** Tokens too generic to identify a piece by. */
	const STOP_TOKENS = array( 'ans', 'root', 'pdfs', 'rehearsal', 'rehearsals', 'recordings', 'rehrecordings', 'notes', 'click', 'mvt', 'mvts', 'take', 'audio', 'score', 'scores' );

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . ANSP_CPT::POST_TYPE, array( __CLASS__, 'save_meta_box' ), 10, 2 );
	}

	/* -------------------------------------------------------------------
	 * The map
	 * ---------------------------------------------------------------- */

	/**
	 * The saved map for a project.
	 *
	 * @param int $project_id Project.
	 * @return array work_id => array( piece, label, order, hidden )
	 */
	public static function get_map( $project_id ) {
		$map = get_post_meta( (int) $project_id, self::META, true );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Merge entries into the saved map.
	 *
	 * An entry with every field empty is removed, so "clear it" is just sending
	 * blanks. Unknown work ids are accepted: a work can be mapped before the
	 * scan that publishes it has run on this environment.
	 *
	 * @param int     $project_id Project.
	 * @param array[] $entries    work_id => fields.
	 * @return array The map after the change.
	 */
	public static function merge( $project_id, $entries ) {
		$map = self::get_map( $project_id );
		foreach ( (array) $entries as $work_id => $fields ) {
			$work_id = sanitize_key( (string) $work_id );
			if ( '' === $work_id || ! is_array( $fields ) ) {
				continue;
			}
			$current = isset( $map[ $work_id ] ) ? $map[ $work_id ] : array();
			foreach ( array( 'piece', 'label' ) as $key ) {
				if ( array_key_exists( $key, $fields ) ) {
					$current[ $key ] = trim( sanitize_text_field( (string) $fields[ $key ] ) );
				}
			}
			if ( array_key_exists( 'order', $fields ) ) {
				$current['order'] = ( '' === $fields['order'] || null === $fields['order'] ) ? '' : (int) $fields['order'];
			}
			if ( array_key_exists( 'hidden', $fields ) ) {
				$current['hidden'] = (bool) filter_var( $fields['hidden'], FILTER_VALIDATE_BOOLEAN );
			}
			$current = array_filter(
				$current,
				static function ( $v ) {
					return '' !== $v && false !== $v && null !== $v;
				}
			);
			if ( empty( $current ) ) {
				unset( $map[ $work_id ] );
			} else {
				$map[ $work_id ] = $current;
			}
		}
		if ( empty( $map ) ) {
			delete_post_meta( (int) $project_id, self::META );
		} else {
			update_post_meta( (int) $project_id, self::META, $map );
		}
		return $map;
	}

	/* -------------------------------------------------------------------
	 * Applying it
	 * ---------------------------------------------------------------- */

	/**
	 * Piece labels written by hand on the project, in first-use order.
	 *
	 * @param int $project_id Project.
	 * @return string[]
	 */
	public static function hand_pieces( $project_id ) {
		$out = array();
		foreach ( ANSP_Materials::get_materials( (int) $project_id ) as $row ) {
			$piece = ANSP_Materials::get_piece( $row );
			if ( '' !== $piece && ! in_array( $piece, $out, true ) ) {
				$out[] = $piece;
			}
		}
		foreach ( self::get_map( $project_id ) as $entry ) {
			if ( ! empty( $entry['piece'] ) && ! in_array( $entry['piece'], $out, true ) ) {
				$out[] = $entry['piece'];
			}
		}
		return $out;
	}

	/**
	 * Drive file ids that hand-entered rows on this project already link to.
	 *
	 * @param int $project_id Project.
	 * @return array id => true
	 */
	public static function hand_linked_drive_ids( $project_id ) {
		$out = array();
		foreach ( ANSP_Materials::get_materials( (int) $project_id ) as $row ) {
			$id = ANSP_Materials::drive_file_id( isset( $row['url'] ) ? (string) $row['url'] : '' );
			if ( '' !== $id ) {
				$out[ $id ] = true;
			}
		}
		return $out;
	}

	/**
	 * Words in a name that could identify a piece: letters only, 4+ long.
	 *
	 * "ANS-Tedesco2026-mvts1-4" -> tedesco. "Margutti-RehRecordings" -> margutti.
	 *
	 * @param string $name Filename stem or folder name.
	 * @return string[]
	 */
	public static function tokens( $name ) {
		$name  = remove_accents( (string) $name );
		$parts = preg_split( '/[^A-Za-z]+|(?<=[a-z])(?=[A-Z])/', $name );
		$out   = array();
		foreach ( (array) $parts as $part ) {
			$part = strtolower( $part );
			if ( strlen( $part ) >= 4 && ! in_array( $part, self::STOP_TOKENS, true ) ) {
				$out[] = $part;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * The one piece a set of tokens names, or ''.
	 *
	 * Only the first token is trusted - by house convention that is the
	 * composer - and it must name exactly one piece. Two matches is a question,
	 * and questions become "Other materials", not a guess.
	 *
	 * @param string[] $tokens Candidate tokens, most trusted first.
	 * @param string[] $pieces Piece labels on the project.
	 * @return string
	 */
	protected static function match_piece( $tokens, $pieces ) {
		if ( empty( $tokens ) ) {
			return '';
		}
		$token = $tokens[0];
		$hits  = array();
		foreach ( $pieces as $piece ) {
			$hay = strtolower( remove_accents( $piece ) );
			if ( preg_match( '/(?<![a-z])' . preg_quote( $token, '/' ) . '(?![a-z])/', $hay ) ) {
				$hits[] = $piece;
			}
		}
		return 1 === count( $hits ) ? $hits[0] : '';
	}

	/**
	 * Decide the piece for one mirror row, and say why.
	 *
	 * @param array    $score  Worker library row.
	 * @param string   $kind   Material type the row was read as.
	 * @param array    $map    Saved map for the project.
	 * @param string[] $pieces Piece labels on the project.
	 * @return array( piece, source )
	 */
	public static function resolve( $score, $kind, $map, $pieces ) {
		$work_id = isset( $score['work_id'] ) ? sanitize_key( (string) $score['work_id'] ) : '';
		if ( '' !== $work_id && isset( $map[ $work_id ]['piece'] ) && '' !== $map[ $work_id ]['piece'] ) {
			return array( $map[ $work_id ]['piece'], 'saved' );
		}
		if ( 'rehearsal_note' === $kind ) {
			return array( __( 'Rehearsal notes', 'ans-singers-portal' ), 'notes' );
		}

		$folder    = isset( $score['project'] ) ? (string) $score['project'] : '';
		$canonical = isset( $score['canonical'] ) ? (string) $score['canonical'] : '';
		$is_audio  = isset( $score['media'] ) && 'audio' === $score['media'];

		// A recording's folder says more than its filename ("Mvt6-SOPclick");
		// a score's filename leads with the composer by house convention.
		$folder_leaf   = '_root' === $folder ? '' : basename( str_replace( '\\', '/', $folder ) );
		$from_folder   = self::tokens( $folder_leaf );
		$from_filename = self::tokens( preg_replace( '/^ANS[-_ ]+/i', '', $canonical ) );

		$order = $is_audio ? array( $from_folder, $from_filename ) : array( $from_filename, $from_folder );
		foreach ( $order as $tokens ) {
			$piece = self::match_piece( $tokens, $pieces );
			if ( '' !== $piece ) {
				return array( $piece, 'guessed' );
			}
		}

		if ( $is_audio && '' !== $folder_leaf ) {
			return array( $folder_leaf, 'folder' );
		}
		return array( '', 'unfiled' );
	}

	/**
	 * Apply the map to freshly built mirror rows: piece, label, order, hidden.
	 *
	 * Rows come back sorted within each piece by saved order, then by title in
	 * natural order, so "Mvt1" precedes "Mvt6" precedes "Mvt10".
	 *
	 * @param array[] $rows       Mirror rows, each carrying '_score' and '_kind'.
	 * @param int     $project_id Project.
	 * @return array[]
	 */
	public static function apply( $rows, $project_id ) {
		$map    = self::get_map( $project_id );
		$pieces = self::hand_pieces( $project_id );
		$linked = self::hand_linked_drive_ids( $project_id );
		$out    = array();

		foreach ( $rows as $row ) {
			$score   = isset( $row['_score'] ) ? $row['_score'] : array();
			$src     = isset( $score['source_file_id'] ) ? (string) $score['source_file_id'] : '';
			if ( '' !== $src && isset( $linked[ $src ] ) ) {
				// Somebody already put this exact Drive file on the project by
				// hand, with their own label and piece. Theirs wins (P4) and the
				// file is listed once. ANSP_Scores_Source::append_published_scores()
				// does the merge: a score keeps the hand row's words but is served
				// from the mirror; a recording keeps the hand row as it is.
				unset( $row['_score'], $row['_kind'] );
				$row['_merge_into'] = $src;
				$out[]              = $row;
				continue;
			}
			$kind    = isset( $row['_kind'] ) ? $row['_kind'] : $row['type'];
			$work_id = isset( $score['work_id'] ) ? sanitize_key( (string) $score['work_id'] ) : '';
			$entry   = ( '' !== $work_id && isset( $map[ $work_id ] ) ) ? $map[ $work_id ] : array();

			if ( ! empty( $entry['hidden'] ) ) {
				continue;
			}

			list( $row['piece'], $row['piece_source'] ) = self::resolve( $score, $kind, $map, $pieces );
			if ( ! empty( $entry['label'] ) ) {
				$row['title'] = $entry['label'];
			}
			$row['sort'] = isset( $entry['order'] ) && '' !== $entry['order'] ? (int) $entry['order'] : PHP_INT_MAX;
			unset( $row['_score'], $row['_kind'] );
			$out[] = $row;
		}

		usort(
			$out,
			static function ( $a, $b ) {
				if ( ! isset( $a['sort'] ) || ! isset( $b['sort'] ) ) {
					return isset( $a['sort'] ) ? -1 : ( isset( $b['sort'] ) ? 1 : 0 );
				}
				if ( $a['sort'] !== $b['sort'] ) {
					return $a['sort'] < $b['sort'] ? -1 : 1;
				}
				// Notes after music, so "Rehearsal notes" is the last heading
				// before Other materials; newest note first; the rest by title.
				$a_note = 'rehearsal_note' === $a['type'];
				$b_note = 'rehearsal_note' === $b['type'];
				if ( $a_note !== $b_note ) {
					return $a_note ? 1 : -1;
				}
				if ( $a_note ) {
					return strcmp( (string) $b['rehearsal_date'], (string) $a['rehearsal_date'] );
				}
				return strnatcasecmp( (string) $a['title'], (string) $b['title'] );
			}
		);

		return $out;
	}

	/**
	 * Every mirror row a project reads, with how each was filed. For the REST
	 * route and the admin table - the page itself uses apply().
	 *
	 * @param int $project_id Project.
	 * @return array[]
	 */
	public static function describe( $project_id ) {
		$map    = self::get_map( $project_id );
		$pieces = self::hand_pieces( $project_id );
		$linked = self::hand_linked_drive_ids( $project_id );
		$out    = array();
		foreach ( ANSP_Scores_Source::mirror_scores_for_project( $project_id ) as $pair ) {
			list( $score, $kind ) = $pair;
			$work_id = sanitize_key( (string) $score['work_id'] );
			$entry   = isset( $map[ $work_id ] ) ? $map[ $work_id ] : array();
			list( $piece, $source ) = self::resolve( $score, $kind, $map, $pieces );
			$out[] = array(
				'work_id'   => $work_id,
				'canonical' => isset( $score['canonical'] ) ? (string) $score['canonical'] : '',
				'folder'    => isset( $score['project'] ) ? (string) $score['project'] : '',
				'media'     => isset( $score['media'] ) ? (string) $score['media'] : 'pdf',
				'kind'      => $kind,
				'piece'     => $piece,
				'source'    => $source,
				'label'     => isset( $entry['label'] ) ? $entry['label'] : '',
				'order'     => isset( $entry['order'] ) ? $entry['order'] : '',
				'hidden'    => ! empty( $entry['hidden'] ),
				'shown_by_hand_row' => isset( $linked[ (string) ( isset( $score['source_file_id'] ) ? $score['source_file_id'] : '' ) ] ),
			);
		}
		return $out;
	}

	/* -------------------------------------------------------------------
	 * REST
	 * ---------------------------------------------------------------- */

	/**
	 * GET/POST ars-nova/v1 portal/project/<id>/pieces
	 */
	public static function register_routes() {
		register_rest_route(
			'ars-nova/v1',
			'/portal/project/(?P<id>\d+)/pieces',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => array( 'ANSP_Mirror_Rest', 'can_manage' ),
					'callback'            => array( __CLASS__, 'rest_get' ),
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => array( 'ANSP_Mirror_Rest', 'can_manage' ),
					'callback'            => array( __CLASS__, 'rest_set' ),
				),
			)
		);
	}

	/**
	 * Resolve and validate the project id.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return int|WP_Error
	 */
	protected static function project_id( $req ) {
		$id   = (int) $req->get_param( 'id' );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || ANSP_CPT::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'ansp_not_a_project', 'That id is not an ans_project.', array( 'status' => 404 ) );
		}
		return $id;
	}

	/**
	 * What every mirror file on this project is filed under, and why.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_get( $req ) {
		$id = self::project_id( $req );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return rest_ensure_response(
			array(
				'ok'          => true,
				'project_id'  => $id,
				'hand_pieces' => self::hand_pieces( $id ),
				'files'       => self::describe( $id ),
				'note'        => 'source: saved = set by a person; guessed = composer/folder matched one piece; folder = a recording filed under its Drive folder; notes = rehearsal notes; unfiled = shown under Other materials. POST {entries: {work_id: {piece, label, order, hidden}}} to change; blanks clear.',
			)
		);
	}

	/**
	 * Save entries.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_set( $req ) {
		$id = self::project_id( $req );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( ANSP_Mirror_Rest::is_production() && ! filter_var( $req->get_param( 'confirm_production' ), FILTER_VALIDATE_BOOLEAN ) ) {
			return new WP_Error( 'ansp_production_blocked', 'This is the production site. Resend with confirm_production=true to proceed.', array( 'status' => 403 ) );
		}
		$entries = $req->get_param( 'entries' );
		if ( ! is_array( $entries ) || empty( $entries ) ) {
			return new WP_Error( 'ansp_pieces_empty', 'Send entries: {work_id: {piece, label, order, hidden}}.', array( 'status' => 400 ) );
		}
		self::merge( $id, $entries );
		return self::rest_get( $req );
	}

	/* -------------------------------------------------------------------
	 * Admin
	 * ---------------------------------------------------------------- */

	/**
	 * The table on the project screen.
	 */
	public static function add_meta_box() {
		add_meta_box(
			'ansp_mirror_pieces',
			__( 'Mirror files: piece and label', 'ans-singers-portal' ),
			array( __CLASS__, 'render_meta_box' ),
			ANSP_CPT::POST_TYPE,
			'normal',
			'default'
		);
	}

	/**
	 * Render the table.
	 *
	 * @param WP_Post $post Project.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'ansp_save_mirror_pieces', 'ansp_mirror_pieces_nonce' );
		$files = self::describe( $post->ID );
		if ( empty( $files ) ) {
			echo '<p class="description">' . esc_html__( 'No published files reach this project yet. Set its mirror folders and scan.', 'ans-singers-portal' ) . '</p>';
			return;
		}
		$sources = array(
			'saved'   => __( 'set here', 'ans-singers-portal' ),
			'guessed' => __( 'matched by name', 'ans-singers-portal' ),
			'folder'  => __( 'its Drive folder', 'ans-singers-portal' ),
			'notes'   => __( 'rehearsal note', 'ans-singers-portal' ),
			'unfiled' => __( 'Other materials', 'ans-singers-portal' ),
		);
		?>
		<p class="description"><?php esc_html_e( 'Files published from Drive land here automatically. Leave Piece blank to accept the suggestion shown in grey; type a piece to override it. Label is what singers see instead of the filename. Hide keeps a file off the Hub for everyone.', 'ans-singers-portal' ); ?></p>
		<datalist id="ansp-mirror-piece-list">
			<?php foreach ( self::hand_pieces( $post->ID ) as $piece ) : ?>
				<option value="<?php echo esc_attr( $piece ); ?>"></option>
			<?php endforeach; ?>
		</datalist>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'File', 'ans-singers-portal' ); ?></th>
				<th><?php esc_html_e( 'Piece', 'ans-singers-portal' ); ?></th>
				<th><?php esc_html_e( 'Label', 'ans-singers-portal' ); ?></th>
				<th style="width:5em;"><?php esc_html_e( 'Order', 'ans-singers-portal' ); ?></th>
				<th style="width:4em;"><?php esc_html_e( 'Hide', 'ans-singers-portal' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $files as $file ) : ?>
				<?php $name = 'ansp_mirror_pieces[' . $file['work_id'] . ']'; ?>
				<tr>
					<td><code><?php echo esc_html( $file['canonical'] ); ?></code><br /><span class="description"><?php echo esc_html( $file['folder'] . ' · ' . $file['media'] ); ?></span></td>
					<td>
						<input type="text" class="widefat" list="ansp-mirror-piece-list"
							name="<?php echo esc_attr( $name ); ?>[piece]"
							value="<?php echo esc_attr( 'saved' === $file['source'] ? $file['piece'] : '' ); ?>"
							placeholder="<?php echo esc_attr( 'saved' === $file['source'] ? '' : ( '' === $file['piece'] ? $sources['unfiled'] : $file['piece'] ) ); ?>" />
						<?php if ( 'saved' !== $file['source'] ) : ?>
							<span class="description"><?php echo esc_html( $sources[ $file['source'] ] ); ?></span>
						<?php endif; ?>
					</td>
					<td><input type="text" class="widefat" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $file['label'] ); ?>" placeholder="<?php echo esc_attr( $file['canonical'] ); ?>" /></td>
					<td><input type="number" class="small-text" name="<?php echo esc_attr( $name ); ?>[order]" value="<?php echo esc_attr( (string) $file['order'] ); ?>" /></td>
					<td><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[hidden]" value="1" <?php checked( $file['hidden'] ); ?> /></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Save the table.
	 *
	 * @param int     $post_id Project.
	 * @param WP_Post $post    Project.
	 */
	public static function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST['ansp_mirror_pieces_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ansp_mirror_pieces_nonce'] ) ), 'ansp_save_mirror_pieces' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitised in merge().
		$posted  = isset( $_POST['ansp_mirror_pieces'] ) ? (array) wp_unslash( $_POST['ansp_mirror_pieces'] ) : array();
		$entries = array();
		foreach ( $posted as $work_id => $fields ) {
			$fields             = (array) $fields;
			$entries[ $work_id ] = array(
				'piece'  => isset( $fields['piece'] ) ? $fields['piece'] : '',
				'label'  => isset( $fields['label'] ) ? $fields['label'] : '',
				'order'  => isset( $fields['order'] ) ? $fields['order'] : '',
				'hidden' => ! empty( $fields['hidden'] ),
			);
		}
		self::merge( $post_id, $entries );
	}
}
