<?php
/**
 * Materials: a native repeatable meta box on ans_project.
 *
 * All materials for a project live in ONE post-meta array (_ansp_materials).
 * Each row: id, type, title, url, note, tags[] (v1.2.0). Files themselves
 * stay in Google Drive / external hosts — only links are stored.
 *
 * v1.2.0 replaced per-material permissions with free-form TAGS: Tom can add
 * unlimited tags to each material (voice parts, "Video", "Rehearsal Note",
 * deadlines — anything). Nothing is gated: every portal member sees every
 * material in a project they can access; singers use a front-end tag filter
 * to narrow the list. Legacy `permission` keys on old rows are ignored.
 *
 * The admin UI (assets/admin.js) offers Add/Remove rows, a type select,
 * title, URL, note, and a tags input rendered as removable chips with a
 * suggestions datalist (voice parts + content types) — free typing allowed.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_Materials
 */
class ANSP_Materials {

	/**
	 * Post meta key holding the materials array.
	 */
	const META_KEY = '_ansp_materials';

	/**
	 * Hook the meta box + save handler.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_' . ANSP_CPT::POST_TYPE, array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Allowed material types (value => label).
	 *
	 * @return array<string,string>
	 */
	public static function types() {
		return array(
			'sheet_music'    => __( 'Sheet music', 'ans-singers-portal' ),
			'recording'      => __( 'Recording', 'ans-singers-portal' ),
			'image'          => __( 'Image', 'ans-singers-portal' ),
			'document'       => __( 'Document', 'ans-singers-portal' ),
			'video_link'     => __( 'Video link', 'ans-singers-portal' ),
			'drive_link'     => __( 'Drive link', 'ans-singers-portal' ),
			'rehearsal_note' => __( 'Rehearsal Note', 'ans-singers-portal' ),
			'rehearsal_date' => __( 'Rehearsal Date', 'ans-singers-portal' ),
		);
	}

	/**
	 * Auto content-type tag label for each material type (v1.2.0).
	 *
	 * These labels are appended to a material's manual tags so singers can
	 * filter by content type even when no tag was typed.
	 *
	 * @return array<string,string> type => tag label.
	 */
	public static function type_tag_labels() {
		return array(
			'video_link'     => __( 'Video', 'ans-singers-portal' ),
			'recording'      => __( 'Audio', 'ans-singers-portal' ),
			'sheet_music'    => __( 'Sheet Music', 'ans-singers-portal' ),
			'image'          => __( 'Image', 'ans-singers-portal' ),
			'document'       => __( 'Document', 'ans-singers-portal' ),
			'drive_link'     => __( 'Link', 'ans-singers-portal' ),
			'rehearsal_note' => __( 'Rehearsal Note', 'ans-singers-portal' ),
			'rehearsal_date' => __( 'Rehearsal Date', 'ans-singers-portal' ),
		);
	}

	/**
	 * Suggested tags for the admin palette / datalist: common voice parts and
	 * content types. Purely convenience — tags are free-form and unlimited.
	 *
	 * @return string[]
	 */
	public static function suggested_tags() {
		$suggestions = array(
			__( 'Soprano', 'ans-singers-portal' ),
			__( 'Mezzo-Soprano', 'ans-singers-portal' ),
			__( 'Alto', 'ans-singers-portal' ),
			__( 'Countertenor', 'ans-singers-portal' ),
			__( 'Tenor', 'ans-singers-portal' ),
			__( 'Baritone', 'ans-singers-portal' ),
			__( 'Bass', 'ans-singers-portal' ),
			__( 'Video', 'ans-singers-portal' ),
			__( 'Audio / MP3', 'ans-singers-portal' ),
			__( 'Sheet Music', 'ans-singers-portal' ),
			__( 'Rehearsal Note', 'ans-singers-portal' ),
			__( 'Rehearsal Date', 'ans-singers-portal' ),
			__( 'Image', 'ans-singers-portal' ),
			__( 'Link', 'ans-singers-portal' ),
		);
		return array_values( array_unique( $suggestions ) );
	}

	/**
	 * Sanitise a raw tags value (comma-separated string or array) into a
	 * clean, deduped list of free-form tag strings. Unlimited count.
	 *
	 * @param mixed $raw Raw tags input.
	 * @return string[]
	 */
	public static function sanitize_tags( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();
		$seen  = array();
		foreach ( $raw as $tag ) {
			$tag = trim( sanitize_text_field( (string) $tag ) );
			if ( '' === $tag ) {
				continue;
			}
			$key = strtolower( $tag );
			if ( isset( $seen[ $key ] ) ) {
				continue; // Case-insensitive dedupe, first spelling wins.
			}
			$seen[ $key ] = true;
			$clean[]      = $tag;
		}
		return $clean;
	}

	/**
	 * The manual tags stored on a material row.
	 *
	 * @param array $row Material row.
	 * @return string[]
	 */
	public static function get_tags( $row ) {
		if ( ! is_array( $row ) || empty( $row['tags'] ) ) {
			return array();
		}
		return self::sanitize_tags( $row['tags'] );
	}

	/**
	 * The ans_group taxonomy terms (Main/Small/Friday/Special Guests…), or [].
	 *
	 * @return array WP_Term[] (empty if the taxonomy has no terms yet).
	 */
	public static function group_terms() {
		if ( ! taxonomy_exists( 'ans_group' ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'ans_group',
				'hide_empty' => false,
			)
		);
		return is_array( $terms ) ? $terms : array();
	}

	/**
	 * The valid group slugs checked on a material row. Groups GATE access:
	 * a material is visible only to singers in one of its checked groups
	 * (none checked = visible to everyone). Managers/admins always see all.
	 *
	 * @param array $row Material row.
	 * @return string[] Valid ans_group slugs.
	 */
	public static function get_groups( $row ) {
		if ( ! is_array( $row ) || empty( $row['groups'] ) || ! is_array( $row['groups'] ) ) {
			return array();
		}
		$valid = wp_list_pluck( self::group_terms(), 'slug' );
		$out   = array();
		foreach ( $row['groups'] as $slug ) {
			$slug = sanitize_key( $slug );
			if ( '' !== $slug && in_array( $slug, $valid, true ) && ! in_array( $slug, $out, true ) ) {
				$out[] = $slug;
			}
		}
		return $out;
	}

	/**
	 * A material's EFFECTIVE tags: its manual tags PLUS the auto-derived
	 * content-type label from its `type` (e.g. video_link → "Video"), deduped
	 * case-insensitively. Used by the front-end filter and chips so content
	 * type is always filterable even when Tom didn't type it as a tag.
	 *
	 * @param array $row Material row.
	 * @return string[]
	 */
	public static function effective_tags( $row ) {
		if ( ! is_array( $row ) ) {
			return array();
		}
		$tags   = self::get_tags( $row );
		$type   = isset( $row['type'] ) ? (string) $row['type'] : '';
		$labels = self::type_tag_labels();
		if ( isset( $labels[ $type ] ) ) {
			$tags[] = $labels[ $type ];
		}
		return self::sanitize_tags( $tags );
	}

	/**
	 * Read the sanitised materials array off a project.
	 *
	 * @param int $post_id Project post ID.
	 * @return array[] Material rows (possibly empty).
	 */
	public static function get_materials( $post_id ) {
		$rows = get_post_meta( (int) $post_id, self::META_KEY, true );
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
	}

	/**
	 * Extract a Google Drive file ID from a standard share link.
	 * Supports /file/d/FILEID/… and open?id=FILEID forms.
	 *
	 * @param string $url Drive URL.
	 * @return string File ID or ''.
	 */
	public static function drive_file_id( $url ) {
		$url = (string) $url;
		if ( false === strpos( $url, 'drive.google.com' ) ) {
			return '';
		}
		if ( preg_match( '#/file/d/([a-zA-Z0-9_-]+)#', $url, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '#[?&]id=([a-zA-Z0-9_-]+)#', $url, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Best inline-preview URL for a material URL, or '' when there is none.
	 *
	 * - Drive file links     → https://drive.google.com/file/d/FILEID/preview
	 * - Google Docs/Sheets/… → the same document with /preview
	 *
	 * @param string $url Stored URL.
	 * @return string Preview iframe URL or ''.
	 */
	public static function preview_url( $url ) {
		$url     = (string) $url;
		$file_id = self::drive_file_id( $url );
		if ( $file_id ) {
			return 'https://drive.google.com/file/d/' . rawurlencode( $file_id ) . '/preview';
		}
		if ( preg_match( '#^https://docs\.google\.com/(document|spreadsheets|presentation|forms)/d/([a-zA-Z0-9_-]+)#', $url, $m ) ) {
			return 'https://docs.google.com/' . $m[1] . '/d/' . rawurlencode( $m[2] ) . '/preview';
		}
		return '';
	}

	/**
	 * Register the meta box.
	 *
	 * @return void
	 */
	public function add_meta_box() {
		add_meta_box(
			'ansp_materials',
			__( 'Materials & Tags', 'ans-singers-portal' ),
			array( $this, 'render_meta_box' ),
			ANSP_CPT::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render the repeatable materials table.
	 *
	 * @param WP_Post $post Current project.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'ansp_save_materials', 'ansp_materials_nonce' );

		$materials = self::get_materials( $post->ID );
		?>
		<p class="description">
			<?php esc_html_e( 'Paste a Google Drive share link, a video URL (YouTube/Vimeo) or a direct file URL for each material — files stay in Drive; the portal previews them inline. Check the Groups a material is for to control who sees it (leave all unchecked = everyone sees it). Use TAGS to help singers filter (voice parts, "Video", "Rehearsal Note", deadlines — anything, unlimited). Type a tag and press Enter or comma to add it; click × on a chip to remove it. Pick from the suggestions or type your own.', 'ans-singers-portal' ); ?>
		</p>
		<table class="widefat ansp-materials-table" id="ansp-materials-table">
			<thead>
				<tr>
					<th class="ansp-col-type"><?php esc_html_e( 'Type', 'ans-singers-portal' ); ?></th>
					<th class="ansp-col-main"><?php esc_html_e( 'Title / URL / Note', 'ans-singers-portal' ); ?></th>
					<th class="ansp-col-groups"><?php esc_html_e( 'Groups (who sees it)', 'ans-singers-portal' ); ?></th>
					<th class="ansp-col-tags"><?php esc_html_e( 'Tags (filter)', 'ans-singers-portal' ); ?></th>
					<th class="ansp-col-actions"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'ans-singers-portal' ); ?></span></th>
				</tr>
			</thead>
			<tbody id="ansp-materials-rows" data-next-index="<?php echo esc_attr( (string) count( $materials ) ); ?>">
				<?php
				$index = 0;
				foreach ( $materials as $row ) {
					self::render_row( (string) $index, $row );
					$index++;
				}
				?>
			</tbody>
		</table>
		<p>
			<button type="button" class="button button-secondary" id="ansp-add-material">
				<?php esc_html_e( '+ Add material', 'ans-singers-portal' ); ?>
			</button>
		</p>
		<datalist id="ansp-tag-suggestions">
			<?php foreach ( self::suggested_tags() as $suggestion ) : ?>
				<option value="<?php echo esc_attr( $suggestion ); ?>"></option>
			<?php endforeach; ?>
		</datalist>
		<script type="text/html" id="tmpl-ansp-material-row">
			<?php self::render_row( '__INDEX__', array() ); ?>
		</script>
		<?php
	}

	/**
	 * Render one material row (also used as the JS clone template with
	 * $index = '__INDEX__').
	 *
	 * @param string $index Numeric index or the literal '__INDEX__'.
	 * @param array  $row   Material row (empty array for the template).
	 * @return void
	 */
	protected static function render_row( $index, $row ) {
		$row = is_array( $row ) ? $row : array();

		$id    = isset( $row['id'] ) ? (string) $row['id'] : '';
		$type  = isset( $row['type'] ) ? (string) $row['type'] : 'drive_link';
		$title = isset( $row['title'] ) ? (string) $row['title'] : '';
		$url   = isset( $row['url'] ) ? (string) $row['url'] : '';
		$note  = isset( $row['note'] ) ? (string) $row['note'] : '';
		$tags        = self::get_tags( $row );
		$groups      = self::get_groups( $row );
		$group_terms = self::group_terms();
		$name        = 'ansp_materials[' . $index . ']';
		?>
		<tr class="ansp-material-row">
			<td class="ansp-col-type">
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo esc_attr( $id ); ?>" />
				<select name="<?php echo esc_attr( $name ); ?>[type]" aria-label="<?php esc_attr_e( 'Material type', 'ans-singers-portal' ); ?>">
					<?php foreach ( self::types() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $type, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td class="ansp-col-main">
				<input type="text" class="widefat" name="<?php echo esc_attr( $name ); ?>[title]" value="<?php echo esc_attr( $title ); ?>" placeholder="<?php esc_attr_e( 'Title (e.g. "Lux Aeterna — Soprano part")', 'ans-singers-portal' ); ?>" />
				<input type="url" class="widefat" name="<?php echo esc_attr( $name ); ?>[url]" value="<?php echo esc_url( $url ); ?>" placeholder="<?php esc_attr_e( 'https://drive.google.com/… or video / file URL', 'ans-singers-portal' ); ?>" />
				<input type="text" class="widefat" name="<?php echo esc_attr( $name ); ?>[note]" value="<?php echo esc_attr( $note ); ?>" placeholder="<?php esc_attr_e( 'Optional note (e.g. "learn by Oct 3")', 'ans-singers-portal' ); ?>" />
				<p class="ansp-drive-open">
					<a href="https://drive.google.com/drive/my-drive" target="_blank" rel="noopener noreferrer" class="ansp-drive-link" title="<?php esc_attr_e( 'Open Google Drive in a new tab to copy a file\'s share link', 'ans-singers-portal' ); ?>">
						<svg class="ansp-drive-ico" viewBox="0 0 87.3 78" width="16" height="16" aria-hidden="true" focusable="false"><path fill="#0066da" d="M6.6 66.85l3.85 6.65c.8 1.4 1.95 2.5 3.3 3.3l13.75-23.8H0c0 1.55.4 3.1 1.2 4.5z"/><path fill="#00ac47" d="M43.65 25L29.9 1.2c-1.35.8-2.5 1.9-3.3 3.3l-25.4 44C.4 49.9 0 51.45 0 53h27.5z"/><path fill="#ea4335" d="M73.55 76.8c1.35-.8 2.5-1.9 3.3-3.3l1.6-2.75 7.65-13.25c.8-1.4 1.2-2.95 1.2-4.5H59.8l5.85 11.5z"/><path fill="#00832d" d="M43.65 25L57.4 1.2C56.05.4 54.5 0 52.9 0H34.4c-1.6 0-3.15.45-4.5 1.2z"/><path fill="#2684fc" d="M59.8 53H27.5L13.75 76.8c1.35.8 2.9 1.2 4.5 1.2h50.8c1.6 0 3.15-.45 4.5-1.2z"/><path fill="#ffba00" d="M73.4 26.5L60.7 4.5c-.8-1.4-1.95-2.5-3.3-3.3L43.65 25 59.8 53h27.45c0-1.55-.4-3.1-1.2-4.5z"/></svg>
						<?php esc_html_e( 'Open Google Drive', 'ans-singers-portal' ); ?>
					</a>
				</p>
			</td>
			<td class="ansp-col-groups">
				<?php if ( ! empty( $group_terms ) ) : ?>
					<fieldset class="ansp-groups-field">
						<?php foreach ( $group_terms as $gterm ) : ?>
							<label class="ansp-group-check"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[groups][]" value="<?php echo esc_attr( $gterm->slug ); ?>" <?php checked( in_array( $gterm->slug, $groups, true ) ); ?> /> <?php echo esc_html( $gterm->name ); ?></label>
						<?php endforeach; ?>
					</fieldset>
					<span class="ansp-tags-hint"><?php esc_html_e( 'None checked = everyone sees it.', 'ans-singers-portal' ); ?></span>
				<?php else : ?>
					<span class="description"><?php esc_html_e( 'No groups yet', 'ans-singers-portal' ); ?></span>
				<?php endif; ?>
			</td>
			<td class="ansp-col-tags">
				<div class="ansp-tags-field" data-ansp-tags-field>
					<div class="ansp-tags-chips" data-ansp-tags-chips aria-live="polite"></div>
					<input
						type="text"
						class="widefat ansp-tags-input"
						name="<?php echo esc_attr( $name ); ?>[tags]"
						value="<?php echo esc_attr( implode( ', ', $tags ) ); ?>"
						list="ansp-tag-suggestions"
						autocomplete="off"
						aria-label="<?php esc_attr_e( 'Tags (comma or Enter to add — unlimited, free-form)', 'ans-singers-portal' ); ?>"
						placeholder="<?php esc_attr_e( 'Add tags — Soprano, Video, Rehearsal Note, "due Oct 3"…', 'ans-singers-portal' ); ?>"
					/>
					<span class="ansp-tags-hint"><?php esc_html_e( 'Comma or Enter adds a tag. Free-form, unlimited.', 'ans-singers-portal' ); ?></span>
				</div>
			</td>
			<td class="ansp-col-actions">
				<button type="button" class="button-link-delete ansp-remove-material"><?php esc_html_e( 'Remove', 'ans-singers-portal' ); ?></button>
			</td>
		</tr>
		<?php
	}

	/**
	 * Nonce-verified save handler for the materials array.
	 *
	 * @param int     $post_id Project ID.
	 * @param WP_Post $post    Project post object.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['ansp_materials_nonce'] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['ansp_materials_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'ansp_save_materials' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw   = isset( $_POST['ansp_materials'] ) ? wp_unslash( (array) $_POST['ansp_materials'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field sanitised below.
		$clean = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$title = isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : '';
			$url   = isset( $row['url'] ) ? esc_url_raw( trim( (string) $row['url'] ) ) : '';
			if ( '' === $title && '' === $url ) {
				continue; // Empty row.
			}

			$type = isset( $row['type'] ) ? sanitize_key( $row['type'] ) : '';
			if ( ! array_key_exists( $type, self::types() ) ) {
				$type = 'drive_link';
			}

			$id = isset( $row['id'] ) ? sanitize_key( $row['id'] ) : '';
			if ( '' === $id ) {
				$id = uniqid( 'ansp_', false );
			}

			// v1.2.0: free-form tags replace per-material permissions. Any
			// legacy `permission` key in old saved rows is simply dropped.
			$clean[] = array(
				'id'    => $id,
				'type'  => $type,
				'title' => $title,
				'url'   => $url,
				'note'  => isset( $row['note'] ) ? sanitize_text_field( $row['note'] ) : '',
				'tags'   => self::sanitize_tags( isset( $row['tags'] ) ? $row['tags'] : array() ),
				'groups' => self::get_groups( array( 'groups' => isset( $row['groups'] ) ? $row['groups'] : array() ) ),
			);
		}

		if ( $clean ) {
			update_post_meta( $post_id, self::META_KEY, $clean );
		} else {
			delete_post_meta( $post_id, self::META_KEY );
		}
	}
}
