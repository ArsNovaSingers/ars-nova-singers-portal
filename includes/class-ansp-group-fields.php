<?php
/**
 * Group term fields: Google Drive folder mapping + filter tag.
 *
 * Implements ANS_Org_Portal_Spec.md rev. 10 §2.1 — Phase 1a.
 *
 * The Drive folder is the ONLY content gate (spec §5.4): a singer sees a file
 * if and only if they hold the group whose folder contains it. Mapping a
 * folder is therefore the deliberate act that publishes it, and clearing the
 * mapping is how material comes down (§5.7). Nothing here grants or revokes
 * access on its own — it records which folder belongs to which group, and the
 * scraper (Phase 1b) reads that mapping.
 *
 * Two fields are added to the ans_group term editor:
 *
 *   ansp_group_drive_folder_id  The Drive folder, stored as an ID. Tom may
 *                               paste either a folder URL or a bare ID. IDs
 *                               are stored rather than paths because a path
 *                               breaks the moment anyone renames a folder.
 *   ansp_group_tag              A filter label used to sort materials. NOT an
 *                               access key. Stored without a leading
 *                               underscore; the filename parser supplies it.
 *
 * On save the folder is resolved through the Ars Nova Google Connector and the
 * folder's real name, path and file count are echoed back, because a mapping
 * that looks configured and silently delivers nothing is the worst failure
 * mode available here.
 *
 * @package ArsNovaSingersPortal
 * @since   1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_Group_Fields
 */
class ANSP_Group_Fields {

	/** Term meta keys. */
	const META_FOLDER_ID    = 'ansp_group_drive_folder_id';
	const META_FOLDER_NAME  = 'ansp_group_drive_folder_name';
	const META_FOLDER_DRIVE = 'ansp_group_drive_drive_id';
	const META_FOLDER_COUNT = 'ansp_group_drive_file_count';
	const META_FOLDER_STATE = 'ansp_group_drive_status';
	const META_FOLDER_TIME  = 'ansp_group_drive_checked';
	const META_TAG          = 'ansp_group_tag';

	/** Drive scope. Read-only: this plugin never writes to Drive. */
	const DRIVE_SCOPE = 'https://www.googleapis.com/auth/drive.readonly';

	/**
	 * Hook the term-form fields, the save handler and the admin notice.
	 */
	public function __construct() {
		add_action( 'ans_group_add_form_fields', array( __CLASS__, 'render_add_fields' ) );
		add_action( 'ans_group_edit_form_fields', array( __CLASS__, 'render_edit_fields' ), 10, 1 );
		add_action( 'created_ans_group', array( __CLASS__, 'save_fields' ) );
		add_action( 'edited_ans_group', array( __CLASS__, 'save_fields' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );

		// Surface the mapping in the Groups list so a missing or broken folder
		// is visible without opening each term.
		add_filter( 'manage_edit-ans_group_columns', array( __CLASS__, 'add_columns' ) );
		add_filter( 'manage_ans_group_custom_column', array( __CLASS__, 'render_column' ), 10, 3 );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Public accessors — the scraper and the portal read through these.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The mapped Drive folder ID for a group term.
	 *
	 * @param int $term_id Group term ID.
	 * @return string Folder ID, or '' when the group is unmapped.
	 */
	public static function get_folder_id( $term_id ) {
		return (string) get_term_meta( (int) $term_id, self::META_FOLDER_ID, true );
	}

	/**
	 * The filter tag for a group term, without a leading underscore.
	 *
	 * @param int $term_id Group term ID.
	 * @return string
	 */
	public static function get_tag( $term_id ) {
		return (string) get_term_meta( (int) $term_id, self::META_TAG, true );
	}

	/**
	 * Every mapped group as folder_id => term_id.
	 *
	 * This is the scraper's entry point: it walks exactly these folders and
	 * nothing else, which is what keeps unmapped material (the legacy archive)
	 * invisible by construction — spec §5.4 rule 1.
	 *
	 * @return array<string,int>
	 */
	public static function get_folder_map() {
		$map = array();

		if ( ! taxonomy_exists( 'ans_group' ) ) {
			return $map;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'ans_group',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return $map;
		}

		foreach ( $terms as $term ) {
			$folder = self::get_folder_id( $term->term_id );
			if ( '' !== $folder ) {
				$map[ $folder ] = (int) $term->term_id;
			}
		}

		return $map;
	}

	/**
	 * Resolve a filter tag to its group term. Case-insensitive.
	 *
	 * @param string $tag Tag with or without a leading underscore.
	 * @return WP_Term|null
	 */
	public static function get_group_by_tag( $tag ) {
		$needle = strtolower( self::normalize_tag( $tag ) );
		if ( '' === $needle || ! taxonomy_exists( 'ans_group' ) ) {
			return null;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'ans_group',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return null;
		}

		foreach ( $terms as $term ) {
			if ( strtolower( self::get_tag( $term->term_id ) ) === $needle ) {
				return $term;
			}
		}

		return null;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Input parsing
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Pull a Drive folder ID out of whatever Tom pasted.
	 *
	 * Accepts the folder URL forms Drive actually produces, plus a bare ID.
	 * Returns '' when nothing ID-shaped is present, so the caller can tell
	 * "cleared deliberately" from "typed something unusable".
	 *
	 * @param string $input Raw field value.
	 * @return string Folder ID or ''.
	 */
	public static function parse_folder_id( $input ) {
		$input = trim( (string) $input );

		if ( '' === $input ) {
			return '';
		}

		// https://drive.google.com/drive/folders/<id>
		// https://drive.google.com/drive/u/0/folders/<id>?usp=sharing
		if ( preg_match( '#/folders/([A-Za-z0-9_-]{10,})#', $input, $m ) ) {
			return $m[1];
		}

		// https://drive.google.com/open?id=<id>
		if ( preg_match( '#[?&]id=([A-Za-z0-9_-]{10,})#', $input, $m ) ) {
			return $m[1];
		}

		// A bare ID. Drive IDs are long and have no spaces or slashes.
		if ( preg_match( '#^[A-Za-z0-9_-]{10,}$#', $input ) ) {
			return $input;
		}

		return '';
	}

	/**
	 * Normalize a tag: trim, drop any leading underscores the user typed,
	 * and strip characters that cannot survive a filename round-trip.
	 *
	 * The underscore is the filename tag delimiter, so it can never appear
	 * inside a tag value.
	 *
	 * @param string $tag Raw tag.
	 * @return string
	 */
	public static function normalize_tag( $tag ) {
		$tag = trim( (string) $tag );
		$tag = ltrim( $tag, '_' );
		$tag = preg_replace( '#[^A-Za-z0-9\-]#', '', $tag );

		return (string) $tag;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Drive resolution
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Confirm the service account can actually read the folder, and gather
	 * enough detail to show it back.
	 *
	 * @param string $folder_id Drive folder ID.
	 * @return array|WP_Error array( name, drive_id, count ) on success.
	 */
	public static function resolve_folder( $folder_id ) {
		if ( ! function_exists( 'ansg_request' ) ) {
			return new WP_Error(
				'ansp_no_connector',
				__( 'The Ars Nova Google Connector is not active, so the folder could not be verified. The ID has been saved unverified.', 'ans-singers-portal' )
			);
		}

		$meta = ansg_request(
			'GET',
			'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $folder_id ),
			array(
				'scopes' => self::DRIVE_SCOPE,
				'query'  => array(
					'fields'            => 'id,name,mimeType,driveId,trashed',
					'supportsAllDrives' => 'true',
				),
			)
		);

		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		$body = isset( $meta['body'] ) && is_array( $meta['body'] ) ? $meta['body'] : array();

		if ( empty( $body['id'] ) ) {
			return new WP_Error( 'ansp_drive_empty', __( 'Google returned no folder for that ID.', 'ans-singers-portal' ) );
		}

		if ( ! empty( $body['trashed'] ) ) {
			return new WP_Error( 'ansp_drive_trashed', __( 'That folder is in the Drive trash.', 'ans-singers-portal' ) );
		}

		if ( isset( $body['mimeType'] ) && 'application/vnd.google-apps.folder' !== $body['mimeType'] ) {
			return new WP_Error(
				'ansp_drive_not_folder',
				__( 'That ID points at a file, not a folder. Map the folder that contains the materials.', 'ans-singers-portal' )
			);
		}

		return array(
			'name'     => isset( $body['name'] ) ? (string) $body['name'] : '',
			'drive_id' => isset( $body['driveId'] ) ? (string) $body['driveId'] : '',
			'count'    => self::count_files( $folder_id, isset( $body['driveId'] ) ? (string) $body['driveId'] : '' ),
		);
	}

	/**
	 * Count non-trashed items directly inside a folder.
	 *
	 * Advisory only — it exists so the person mapping a folder can see at a
	 * glance that they landed on the right one. A failure here is not a
	 * mapping failure, so it returns null rather than an error.
	 *
	 * @param string $folder_id Drive folder ID.
	 * @param string $drive_id  Shared drive ID, when the folder is on one.
	 * @return int|null
	 */
	protected static function count_files( $folder_id, $drive_id = '' ) {
		if ( ! function_exists( 'ansg_request' ) ) {
			return null;
		}

		$query = array(
			'q'                         => sprintf( "'%s' in parents and trashed = false", $folder_id ),
			'fields'                    => 'files(id)',
			'pageSize'                  => 1000,
			'supportsAllDrives'         => 'true',
			'includeItemsFromAllDrives' => 'true',
		);

		if ( '' !== $drive_id ) {
			$query['corpora'] = 'drive';
			$query['driveId'] = $drive_id;
		}

		$resp = ansg_request(
			'GET',
			'https://www.googleapis.com/drive/v3/files',
			array(
				'scopes' => self::DRIVE_SCOPE,
				'query'  => $query,
			)
		);

		if ( is_wp_error( $resp ) || empty( $resp['body']['files'] ) || ! is_array( $resp['body']['files'] ) ) {
			return is_wp_error( $resp ) ? null : 0;
		}

		return count( $resp['body']['files'] );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Term form UI
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Fields on the "Add Group" form.
	 *
	 * @return void
	 */
	public static function render_add_fields() {
		?>
		<div class="form-field">
			<label for="ansp_group_drive_folder"><?php esc_html_e( 'Google Drive folder', 'ans-singers-portal' ); ?></label>
			<input type="text" name="ansp_group_drive_folder" id="ansp_group_drive_folder" value="" placeholder="https://drive.google.com/drive/folders/..." />
			<p class="description">
				<?php esc_html_e( 'Paste the folder link or its ID. Everything in this folder — and its project subfolders — becomes visible to members of this group, and to nobody else. Leave empty and the group has no materials.', 'ans-singers-portal' ); ?>
			</p>
		</div>
		<div class="form-field">
			<label for="ansp_group_tag"><?php esc_html_e( 'Filter tag', 'ans-singers-portal' ); ?></label>
			<input type="text" name="ansp_group_tag" id="ansp_group_tag" value="" placeholder="CS" />
			<p class="description">
				<?php esc_html_e( 'Used to sort and filter materials, and to find this group\'s files on a phone or iPad. Not an access control — the folder above decides who can see what. Type it without the underscore.', 'ans-singers-portal' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Fields on the "Edit Group" form, including the last resolved state.
	 *
	 * @param WP_Term $term Group term being edited.
	 * @return void
	 */
	public static function render_edit_fields( $term ) {
		$term_id   = (int) $term->term_id;
		$folder_id = self::get_folder_id( $term_id );
		$tag       = self::get_tag( $term_id );
		$name      = (string) get_term_meta( $term_id, self::META_FOLDER_NAME, true );
		$count     = get_term_meta( $term_id, self::META_FOLDER_COUNT, true );
		$state     = (string) get_term_meta( $term_id, self::META_FOLDER_STATE, true );
		$checked   = (int) get_term_meta( $term_id, self::META_FOLDER_TIME, true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="ansp_group_drive_folder"><?php esc_html_e( 'Google Drive folder', 'ans-singers-portal' ); ?></label></th>
			<td>
				<input type="text" name="ansp_group_drive_folder" id="ansp_group_drive_folder" value="<?php echo esc_attr( $folder_id ); ?>" placeholder="https://drive.google.com/drive/folders/..." class="regular-text" />
				<p class="description">
					<?php esc_html_e( 'Paste the folder link or its ID. Everything in this folder — and its project subfolders — becomes visible to members of this group, and to nobody else. Clearing this takes the group\'s materials down without deleting a single file.', 'ans-singers-portal' ); ?>
				</p>
				<?php if ( '' !== $folder_id ) : ?>
					<p style="margin-top:8px;">
						<?php if ( 'ok' === $state ) : ?>
							<span style="color:#046b3f;font-weight:600;">&#10003; <?php echo esc_html( $name ); ?></span>
							<?php if ( '' !== $count && null !== $count ) : ?>
								<span style="color:#666;">
									&nbsp;&middot;&nbsp;
									<?php
									printf(
										/* translators: %d: number of files found directly in the folder. */
										esc_html( _n( '%d item in this folder', '%d items in this folder', (int) $count, 'ans-singers-portal' ) ),
										(int) $count
									);
									?>
								</span>
							<?php endif; ?>
						<?php elseif ( '' !== $state ) : ?>
							<span style="color:#b32d2e;font-weight:600;">&#9888; <?php echo esc_html( $state ); ?></span>
						<?php endif; ?>
						<?php if ( $checked ) : ?>
							<br /><span style="color:#888;font-size:12px;">
								<?php
								printf(
									/* translators: %s: human-readable time difference, e.g. "5 mins". */
									esc_html__( 'Last checked %s ago', 'ans-singers-portal' ),
									esc_html( human_time_diff( $checked, time() ) )
								);
								?>
								&nbsp;&middot;&nbsp;
								<a href="<?php echo esc_url( 'https://drive.google.com/drive/folders/' . rawurlencode( $folder_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open in Drive', 'ans-singers-portal' ); ?></a>
							</span>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="ansp_group_tag"><?php esc_html_e( 'Filter tag', 'ans-singers-portal' ); ?></label></th>
			<td>
				<input type="text" name="ansp_group_tag" id="ansp_group_tag" value="<?php echo esc_attr( $tag ); ?>" placeholder="CS" class="regular-text" />
				<p class="description">
					<?php esc_html_e( 'Used to sort and filter materials, and to find this group\'s files on a phone or iPad. Not an access control — the folder above decides who can see what. Type it without the underscore.', 'ans-singers-portal' ); ?>
					<?php if ( '' !== $tag ) : ?>
						<br /><code><?php echo esc_html( '_' . $tag ); ?></code> <?php esc_html_e( 'in a filename.', 'ans-singers-portal' ); ?>
					<?php endif; ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/*
	 * ---------------------------------------------------------------------
	 * Save
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Persist both fields, verifying the folder and enforcing tag uniqueness.
	 *
	 * Term forms carry core's own nonce, verified upstream by edit-tags.php;
	 * we additionally require the portal management capability.
	 *
	 * @param int $term_id Term being saved.
	 * @return void
	 */
	public static function save_fields( $term_id ) {
		if ( ! current_user_can( 'ansp_manage_portal' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$term_id = (int) $term_id;
		$notices = array();

		/*
		 * Filter tag. Defaults to the term slug so a new group is usable
		 * immediately, and is rejected — never silently merged — when it
		 * collides with another group.
		 */
		if ( isset( $_POST['ansp_group_tag'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- core term nonce verified by edit-tags.php.
			$raw = wp_unslash( $_POST['ansp_group_tag'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$tag = self::normalize_tag( sanitize_text_field( $raw ) );

			if ( '' === $tag ) {
				$term = get_term( $term_id, 'ans_group' );
				if ( $term instanceof WP_Term ) {
					$tag = self::normalize_tag( $term->slug );
				}
			}

			$clash = self::find_tag_clash( $tag, $term_id );

			if ( $clash instanceof WP_Term ) {
				$notices[] = array(
					'type' => 'error',
					'text' => sprintf(
						/* translators: 1: tag, 2: name of the group already using it. */
						__( 'The tag "%1$s" is already used by the group "%2$s". Tags must be unique, so this one was not saved.', 'ans-singers-portal' ),
						$tag,
						$clash->name
					),
				);
			} elseif ( '' === $tag ) {
				delete_term_meta( $term_id, self::META_TAG );
			} else {
				update_term_meta( $term_id, self::META_TAG, $tag );
			}
		}

		/*
		 * Drive folder. An unreadable mapping is worse than no mapping, so a
		 * folder that cannot be resolved is stored with its error recorded and
		 * reported rather than accepted quietly.
		 */
		if ( isset( $_POST['ansp_group_drive_folder'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw   = sanitize_text_field( wp_unslash( $_POST['ansp_group_drive_folder'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$saved = self::get_folder_id( $term_id );

			if ( '' === trim( $raw ) ) {
				if ( '' !== $saved ) {
					self::clear_folder_meta( $term_id );
					$notices[] = array(
						'type' => 'warning',
						'text' => __( 'Drive folder cleared. This group\'s materials are no longer shown in the portal. No files were deleted — re-paste the folder to bring them back.', 'ans-singers-portal' ),
					);
				}
			} else {
				$folder_id = self::parse_folder_id( $raw );

				if ( '' === $folder_id ) {
					$notices[] = array(
						'type' => 'error',
						'text' => __( 'That does not look like a Drive folder link or ID, so nothing was changed. Open the folder in Drive and copy the address bar.', 'ans-singers-portal' ),
					);
				} else {
					$duplicate = self::find_folder_clash( $folder_id, $term_id );

					if ( $duplicate instanceof WP_Term ) {
						$notices[] = array(
							'type' => 'error',
							'text' => sprintf(
								/* translators: %s: name of the group already mapped to this folder. */
								__( 'That folder is already mapped to the group "%s". A folder belongs to exactly one group — file shared repertoire once and give singers both groups instead.', 'ans-singers-portal' ),
								$duplicate->name
							),
						);
					} else {
						update_term_meta( $term_id, self::META_FOLDER_ID, $folder_id );
						update_term_meta( $term_id, self::META_FOLDER_TIME, time() );

						$resolved = self::resolve_folder( $folder_id );

						if ( is_wp_error( $resolved ) ) {
							update_term_meta( $term_id, self::META_FOLDER_STATE, $resolved->get_error_message() );
							delete_term_meta( $term_id, self::META_FOLDER_NAME );
							delete_term_meta( $term_id, self::META_FOLDER_COUNT );

							$notices[] = array(
								'type' => 'error',
								'text' => sprintf(
									/* translators: %s: the error Google or the connector returned. */
									__( 'Saved, but the folder could not be read: %s Share the folder with the site\'s service account, then save again — until it reads, singers will see nothing.', 'ans-singers-portal' ),
									$resolved->get_error_message()
								),
							);
						} else {
							update_term_meta( $term_id, self::META_FOLDER_STATE, 'ok' );
							update_term_meta( $term_id, self::META_FOLDER_NAME, $resolved['name'] );
							update_term_meta( $term_id, self::META_FOLDER_DRIVE, $resolved['drive_id'] );

							if ( null === $resolved['count'] ) {
								delete_term_meta( $term_id, self::META_FOLDER_COUNT );
							} else {
								update_term_meta( $term_id, self::META_FOLDER_COUNT, (int) $resolved['count'] );
							}

							$notices[] = array(
								'type' => 'success',
								'text' => sprintf(
									/* translators: 1: folder name, 2: item count. */
									__( 'Mapped to "%1$s" — %2$s found. Members of this group will see these materials once the scraper runs.', 'ans-singers-portal' ),
									$resolved['name'],
									null === $resolved['count']
										? __( 'contents not counted', 'ans-singers-portal' )
										: sprintf(
											/* translators: %d: number of items. */
											_n( '%d item', '%d items', (int) $resolved['count'], 'ans-singers-portal' ),
											(int) $resolved['count']
										)
								),
							);
						}
					}
				}
			}
		}

		if ( $notices ) {
			set_transient( 'ansp_group_notice_' . get_current_user_id(), $notices, 60 );
		}
	}

	/**
	 * Remove every cached scrap of a folder mapping.
	 *
	 * @param int $term_id Term ID.
	 * @return void
	 */
	protected static function clear_folder_meta( $term_id ) {
		delete_term_meta( $term_id, self::META_FOLDER_ID );
		delete_term_meta( $term_id, self::META_FOLDER_NAME );
		delete_term_meta( $term_id, self::META_FOLDER_DRIVE );
		delete_term_meta( $term_id, self::META_FOLDER_COUNT );
		delete_term_meta( $term_id, self::META_FOLDER_STATE );
		delete_term_meta( $term_id, self::META_FOLDER_TIME );
	}

	/**
	 * Another group already using this tag, if any.
	 *
	 * @param string $tag     Normalized tag.
	 * @param int    $term_id The term being saved, excluded from the search.
	 * @return WP_Term|null
	 */
	protected static function find_tag_clash( $tag, $term_id ) {
		if ( '' === $tag ) {
			return null;
		}

		$match = self::get_group_by_tag( $tag );

		if ( $match instanceof WP_Term && (int) $match->term_id !== (int) $term_id ) {
			return $match;
		}

		return null;
	}

	/**
	 * Another group already mapped to this folder, if any.
	 *
	 * Spec §2.1: a folder belongs to exactly one group. Shared repertoire is
	 * handled by giving a singer both groups, not by pointing two groups at
	 * one folder.
	 *
	 * @param string $folder_id Drive folder ID.
	 * @param int    $term_id   The term being saved, excluded from the search.
	 * @return WP_Term|null
	 */
	protected static function find_folder_clash( $folder_id, $term_id ) {
		foreach ( self::get_folder_map() as $mapped_folder => $mapped_term ) {
			if ( $mapped_folder === $folder_id && (int) $mapped_term !== (int) $term_id ) {
				$term = get_term( $mapped_term, 'ans_group' );
				if ( $term instanceof WP_Term ) {
					return $term;
				}
			}
		}

		return null;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Feedback
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Show whatever the last save produced.
	 *
	 * @return void
	 */
	public static function render_notices() {
		$key     = 'ansp_group_notice_' . get_current_user_id();
		$notices = get_transient( $key );

		if ( ! $notices || ! is_array( $notices ) ) {
			return;
		}

		delete_transient( $key );

		foreach ( $notices as $notice ) {
			$type = isset( $notice['type'] ) ? $notice['type'] : 'info';
			$text = isset( $notice['text'] ) ? $notice['text'] : '';

			if ( '' === $text ) {
				continue;
			}

			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $type ),
				esc_html( $text )
			);
		}
	}

	/**
	 * Add Tag and Drive folder columns to the Groups list.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function add_columns( $columns ) {
		$insert_after = 'slug';
		$new          = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( $insert_after === $key ) {
				$new['ansp_tag']    = __( 'Tag', 'ans-singers-portal' );
				$new['ansp_folder'] = __( 'Drive folder', 'ans-singers-portal' );
			}
		}

		// Fall back to appending if the slug column is not present.
		if ( ! isset( $new['ansp_tag'] ) ) {
			$new['ansp_tag']    = __( 'Tag', 'ans-singers-portal' );
			$new['ansp_folder'] = __( 'Drive folder', 'ans-singers-portal' );
		}

		return $new;
	}

	/**
	 * Render the custom Groups-list columns.
	 *
	 * @param string $content Existing content.
	 * @param string $column  Column key.
	 * @param int    $term_id Term ID.
	 * @return string
	 */
	public static function render_column( $content, $column, $term_id ) {
		if ( 'ansp_tag' === $column ) {
			$tag = self::get_tag( $term_id );
			return '' === $tag
				? '<span style="color:#999;">&mdash;</span>'
				: '<code>_' . esc_html( $tag ) . '</code>';
		}

		if ( 'ansp_folder' === $column ) {
			$folder = self::get_folder_id( $term_id );

			if ( '' === $folder ) {
				return '<span style="color:#999;">' . esc_html__( 'Not mapped — no materials', 'ans-singers-portal' ) . '</span>';
			}

			$state = (string) get_term_meta( (int) $term_id, self::META_FOLDER_STATE, true );
			$name  = (string) get_term_meta( (int) $term_id, self::META_FOLDER_NAME, true );
			$count = get_term_meta( (int) $term_id, self::META_FOLDER_COUNT, true );

			if ( 'ok' !== $state ) {
				return '<span style="color:#b32d2e;">&#9888; ' . esc_html__( 'Cannot be read', 'ans-singers-portal' ) . '</span>';
			}

			$out = '<span style="color:#046b3f;">&#10003; ' . esc_html( $name ) . '</span>';

			if ( '' !== $count && null !== $count ) {
				$out .= ' <span style="color:#666;">(' . (int) $count . ')</span>';
			}

			return $out;
		}

		return $content;
	}
}
