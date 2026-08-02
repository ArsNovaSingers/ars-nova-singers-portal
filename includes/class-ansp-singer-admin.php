<?php
/**
 * The Singers list screen in wp-admin: fast editing for the people who
 * actually maintain the roster.
 *
 * Zahnay and Kim work in bulk — "these six joined, that one is on leave this
 * season". Opening a full edit screen per person to change one checkbox is the
 * difference between a two-minute job and a twenty-minute one, so voice part,
 * groups and the Active switch all live in Quick Edit.
 *
 * WordPress does NOT populate custom Quick Edit fields for you. The row must
 * carry its current values, and a small script copies them into the form when
 * it opens — otherwise every Quick Edit silently opens blank and saving wipes
 * whatever was there. That is the trap this file exists to avoid.
 *
 * Also drops the Author column: singer profiles were imported and their
 * "author" is whoever ran the import, which is noise.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_Singer_Admin
 */
class ANSP_Singer_Admin {

	/**
	 * Hook the list screen.
	 */
	public function __construct() {
		add_filter( 'manage_singer_posts_columns', array( __CLASS__, 'columns' ), 20 );
		add_action( 'manage_singer_posts_custom_column', array( __CLASS__, 'column_data' ), 20, 2 );
		add_action( 'quick_edit_custom_box', array( __CLASS__, 'quick_edit_box' ), 10, 2 );
		add_action( 'bulk_edit_custom_box', array( __CLASS__, 'bulk_edit_box' ), 10, 2 );
		add_action( 'save_post_singer', array( __CLASS__, 'save_quick_edit' ), 10, 2 );
		add_action( 'admin_footer-edit.php', array( __CLASS__, 'inline_script' ) );

		// Filters above the list, and the sortable On page column.
		add_action( 'restrict_manage_posts', array( __CLASS__, 'render_filters' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_filters_to_query' ) );
		add_filter( 'manage_edit-singer_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
	}

	/**
	 * Make the Groups and On page columns sortable.
	 *
	 * @param array<string,string> $columns Sortable columns.
	 * @return array<string,string>
	 */
	public static function sortable_columns( $columns ) {
		$columns['ansp_public'] = 'ansp_public';
		return $columns;
	}

	/**
	 * Active / Group dropdowns above the Singers list.
	 *
	 * @param string $post_type Current post type.
	 * @return void
	 */
	public static function render_filters( $post_type ) {
		if ( 'singer' !== $post_type ) {
			return;
		}

		$active = isset( $_GET['ansp_active_filter'] ) ? sanitize_key( wp_unslash( $_GET['ansp_active_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$group  = isset( $_GET['ansp_group_filter'] ) ? sanitize_key( wp_unslash( $_GET['ansp_group_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		?>
		<select name="ansp_active_filter">
			<option value=""><?php esc_html_e( 'All singers', 'ans-singers-portal' ); ?></option>
			<option value="active" <?php selected( $active, 'active' ); ?>><?php esc_html_e( 'Active — on the Singers page', 'ans-singers-portal' ); ?></option>
			<option value="inactive" <?php selected( $active, 'inactive' ); ?>><?php esc_html_e( 'Inactive — hidden', 'ans-singers-portal' ); ?></option>
		</select>
		<?php

		$terms = get_terms(
			array(
				'taxonomy'   => 'ans_group',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}
		?>
		<select name="ansp_group_filter">
			<option value=""><?php esc_html_e( 'All groups', 'ans-singers-portal' ); ?></option>
			<option value="__none" <?php selected( $group, '__none' ); ?>><?php esc_html_e( 'No group assigned', 'ans-singers-portal' ); ?></option>
			<?php foreach ( $terms as $term ) : ?>
				<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $group, $term->slug ); ?>>
					<?php echo esc_html( $term->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Apply the Active / Group filters and the On page sort.
	 *
	 * @param WP_Query $query Current query.
	 * @return void
	 */
	public static function apply_filters_to_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'singer' !== $query->get( 'post_type' ) ) {
			return;
		}

		$active = isset( $_GET['ansp_active_filter'] ) ? sanitize_key( wp_unslash( $_GET['ansp_active_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$group  = isset( $_GET['ansp_group_filter'] ) ? sanitize_key( wp_unslash( $_GET['ansp_group_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'active' === $active || 'inactive' === $active ) {
			/*
			 * Active is stored as ABSENCE of meta — only an explicit 'no'
			 * means hidden. So "active" has to match rows where the key does
			 * not exist as well as rows where it is not 'no'; a plain
			 * meta_query on the key alone would return almost nobody.
			 */
			if ( 'inactive' === $active ) {
				$query->set(
					'meta_query', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						array(
							'key'     => ANSP_Singers_Public::META_ACTIVE,
							'value'   => 'no',
							'compare' => '=',
						),
					)
				);
			} else {
				$query->set(
					'meta_query', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'relation' => 'OR',
						array(
							'key'     => ANSP_Singers_Public::META_ACTIVE,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => ANSP_Singers_Public::META_ACTIVE,
							'value'   => 'no',
							'compare' => '!=',
						),
					)
				);
			}
		}

		if ( '' !== $group ) {
			if ( '__none' === $group ) {
				$all = get_terms(
					array(
						'taxonomy'   => 'ans_group',
						'hide_empty' => false,
						'fields'     => 'ids',
					)
				);
				if ( ! is_wp_error( $all ) && ! empty( $all ) ) {
					$query->set(
						'tax_query', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						array(
							array(
								'taxonomy' => 'ans_group',
								'field'    => 'term_id',
								'terms'    => $all,
								'operator' => 'NOT IN',
							),
						)
					);
				}
			} else {
				$query->set(
					'tax_query', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						array(
							'taxonomy' => 'ans_group',
							'field'    => 'slug',
							'terms'    => $group,
						),
					)
				);
			}
		}
	}

	/**
	 * Drop Author, add a Groups column, keep the order sensible.
	 *
	 * @param array<string,string> $columns Existing columns.
	 * @return array<string,string>
	 */
	public static function columns( $columns ) {
		unset( $columns['author'] );

		/*
		 * No Groups column is added here on purpose. The ans_group taxonomy is
		 * registered with show_admin_column => true, so WordPress already
		 * renders one (`taxonomy-ans_group`). Adding our own produced TWO
		 * columns both headed "Groups", which is what shipped in v1.8.0.
		 */

		// Carries the Quick Edit payload; hidden with CSS, never shown.
		$columns['ansp_qe_data'] = '';

		return $columns;
	}

	/**
	 * Render the Groups column and the hidden Quick Edit payload.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public static function column_data( $column, $post_id ) {
		if ( 'ansp_qe_data' !== $column ) {
			return;
		}

		$parts  = (array) get_post_meta( $post_id, 'parts', true );
		$groups = wp_get_object_terms( $post_id, 'ans_group', array( 'fields' => 'slugs' ) );
		$groups = is_wp_error( $groups ) ? array() : $groups;
		$active = class_exists( 'ANSP_Singers_Public' ) ? ANSP_Singers_Public::is_active( $post_id ) : true;

		printf(
			'<span class="ansp-qe-data" data-parts="%1$s" data-groups="%2$s" data-active="%3$s"></span>',
			esc_attr( implode( '|', array_map( 'strval', $parts ) ) ),
			esc_attr( implode( '|', array_map( 'strval', $groups ) ) ),
			esc_attr( $active ? '1' : '0' )
		);
	}

	/**
	 * Render the Quick Edit fields.
	 *
	 * @param string $column    Column being rendered against.
	 * @param string $post_type Post type.
	 * @return void
	 */
	public static function quick_edit_box( $column, $post_type ) {
		if ( 'singer' !== $post_type || 'ansp_qe_data' !== $column ) {
			return;
		}
		self::render_fields( 'quick' );
	}

	/**
	 * Render the Bulk Edit fields.
	 *
	 * Bulk edit deliberately offers ONLY the Active switch and groups — bulk
	 * setting a voice part would be wrong for every singer at once, and is
	 * the kind of thing that quietly destroys a roster.
	 *
	 * @param string $column    Column being rendered against.
	 * @param string $post_type Post type.
	 * @return void
	 */
	public static function bulk_edit_box( $column, $post_type ) {
		if ( 'singer' !== $post_type || 'ansp_qe_data' !== $column ) {
			return;
		}
		self::render_fields( 'bulk' );
	}

	/**
	 * Shared field markup for Quick and Bulk edit.
	 *
	 * @param string $mode 'quick' or 'bulk'.
	 * @return void
	 */
	protected static function render_fields( $mode ) {
		$groups = get_terms(
			array(
				'taxonomy'   => 'ans_group',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $groups ) ) {
			$groups = array();
		}
		?>
		<fieldset class="inline-edit-col-right ansp-qe-fieldset">
			<div class="inline-edit-col">
				<?php wp_nonce_field( 'ansp_quick_edit', 'ansp_qe_nonce' ); ?>

				<span class="title"><?php esc_html_e( 'Ars Nova', 'ans-singers-portal' ); ?></span>

				<label class="alignleft" style="display:block;margin:6px 0;">
					<?php if ( 'bulk' === $mode ) : ?>
						<select name="ansp_qe_active">
							<option value=""><?php esc_html_e( '— Active: no change —', 'ans-singers-portal' ); ?></option>
							<option value="1"><?php esc_html_e( 'Active — show on Singers page', 'ans-singers-portal' ); ?></option>
							<option value="0"><?php esc_html_e( 'Inactive — hide', 'ans-singers-portal' ); ?></option>
						</select>
					<?php else : ?>
						<input type="checkbox" name="ansp_qe_active" value="1" class="ansp-qe-active" />
						<span class="checkbox-title"><strong><?php esc_html_e( 'Active singer', 'ans-singers-portal' ); ?></strong></span>
					<?php endif; ?>
				</label>

				<?php if ( 'quick' === $mode ) : ?>
					<div style="margin:8px 0;">
						<span class="title" style="display:block;"><?php esc_html_e( 'Voice part', 'ans-singers-portal' ); ?></span>
						<?php foreach ( ansp_voice_part_options() as $part ) : ?>
							<label style="display:inline-block;margin:0 10px 4px 0;">
								<input type="checkbox" name="ansp_qe_parts[]" class="ansp-qe-part" value="<?php echo esc_attr( $part ); ?>" />
								<?php echo esc_html( $part ); ?>
							</label>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<div style="margin:8px 0;">
					<span class="title" style="display:block;"><?php esc_html_e( 'Groups', 'ans-singers-portal' ); ?></span>
					<?php if ( empty( $groups ) ) : ?>
						<em><?php esc_html_e( 'No groups defined yet.', 'ans-singers-portal' ); ?></em>
					<?php else : ?>
						<?php foreach ( $groups as $group ) : ?>
							<label style="display:inline-block;margin:0 10px 4px 0;">
								<input type="checkbox" name="ansp_qe_groups[]" class="ansp-qe-group" value="<?php echo esc_attr( $group->slug ); ?>" />
								<?php echo esc_html( $group->name ); ?>
							</label>
						<?php endforeach; ?>
						<?php if ( 'bulk' === $mode ) : ?>
							<p class="description" style="margin:4px 0 0;">
								<?php esc_html_e( 'Ticked groups are ADDED to the selected singers. Existing groups are kept.', 'ans-singers-portal' ); ?>
							</p>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Save Quick Edit / Bulk Edit.
	 *
	 * Only ever acts when our own nonce is present, so a normal edit-screen
	 * save (which does not render these fields) can never blank them.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public static function save_quick_edit( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['ansp_qe_nonce'] ) ) {
			return; // Not one of our forms.
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ansp_qe_nonce'] ) ), 'ansp_quick_edit' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || 'singer' !== $post->post_type ) {
			return;
		}

		$is_bulk = ( isset( $_REQUEST['bulk_edit'] ) );

		// ---- Active -------------------------------------------------------
		if ( $is_bulk ) {
			$active_raw = isset( $_POST['ansp_qe_active'] ) ? sanitize_text_field( wp_unslash( $_POST['ansp_qe_active'] ) ) : '';
			if ( '1' === $active_raw ) {
				delete_post_meta( $post_id, ANSP_Singers_Public::META_ACTIVE );
			} elseif ( '0' === $active_raw ) {
				update_post_meta( $post_id, ANSP_Singers_Public::META_ACTIVE, 'no' );
			}
			// Empty means "no change" — deliberately do nothing.
		} elseif ( isset( $_POST['ansp_qe_active'] ) ) {
			delete_post_meta( $post_id, ANSP_Singers_Public::META_ACTIVE );
		} else {
			update_post_meta( $post_id, ANSP_Singers_Public::META_ACTIVE, 'no' );
		}

		// ---- Voice parts (Quick Edit only) --------------------------------
		if ( ! $is_bulk && isset( $_POST['ansp_qe_parts'] ) ) {
			$raw   = array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['ansp_qe_parts'] ) );
			$parts = array_values( array_intersect( ansp_voice_part_options(), $raw ) );
			update_post_meta( $post_id, 'parts', $parts );
		} elseif ( ! $is_bulk ) {
			update_post_meta( $post_id, 'parts', array() );
		}

		// ---- Groups -------------------------------------------------------
		$group_raw = isset( $_POST['ansp_qe_groups'] ) ? array_map( 'sanitize_key', wp_unslash( (array) $_POST['ansp_qe_groups'] ) ) : array();

		if ( $is_bulk ) {
			// Bulk ADDS groups rather than replacing them: a bulk action that
			// silently stripped everyone's existing groups would be a very
			// bad afternoon.
			if ( ! empty( $group_raw ) ) {
				wp_set_object_terms( $post_id, $group_raw, 'ans_group', true );
			}
		} else {
			wp_set_object_terms( $post_id, $group_raw, 'ans_group', false );
		}
	}

	/**
	 * Populate the Quick Edit fields from the row, and hide the payload cell.
	 *
	 * @return void
	 */
	public static function inline_script() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-singer' !== $screen->id ) {
			return;
		}
		?>
		<style>
			.column-ansp_qe_data { display: none !important; }
			.ansp-qe-fieldset .title { font-weight: 600; }

			/*
			 * Strip the core Quick Edit fields that mean nothing on a singer
			 * profile: Slug, Date, Author, and the Password / Private pair.
			 *
			 * HIDDEN, NOT REMOVED. These inputs still post their existing
			 * values; deleting them from the DOM would submit nothing and let
			 * WordPress blank the slug and date on every quick save.
			 */
			.inline-edit-row label:has( input[name="post_name"] ),
			.inline-edit-row label:has( select[name="post_author"] ),
			.inline-edit-row .inline-edit-date,
			.inline-edit-row .inline-edit-group {
				display: none !important;
			}
		</style>
		<script>
		( function () {
			if ( typeof inlineEditPost === 'undefined' ) {
				return;
			}
			var originalEdit = inlineEditPost.edit;

			inlineEditPost.edit = function ( id ) {
				originalEdit.apply( this, arguments );

				var postId = 0;
				if ( typeof id === 'object' ) {
					postId = parseInt( this.getId( id ), 10 );
				}
				if ( ! postId ) {
					return;
				}

				var row  = document.getElementById( 'post-' + postId );
				var data = row ? row.querySelector( '.ansp-qe-data' ) : null;
				var form = document.getElementById( 'edit-' + postId );
				if ( ! data || ! form ) {
					return;
				}

				var parts  = ( data.getAttribute( 'data-parts' ) || '' ).split( '|' );
				var groups = ( data.getAttribute( 'data-groups' ) || '' ).split( '|' );
				var active = data.getAttribute( 'data-active' ) === '1';

				form.querySelectorAll( '.ansp-qe-part' ).forEach( function ( box ) {
					box.checked = parts.indexOf( box.value ) !== -1;
				} );
				form.querySelectorAll( '.ansp-qe-group' ).forEach( function ( box ) {
					box.checked = groups.indexOf( box.value ) !== -1;
				} );
				var activeBox = form.querySelector( '.ansp-qe-active' );
				if ( activeBox ) {
					activeBox.checked = active;
				}

				/*
				 * Belt and braces for the CSS above: :has() is unsupported on
				 * older browsers, and a panel where half the clutter is hidden
				 * looks more broken than one where none of it is.
				 */
				[ 'input[name="post_name"]', 'select[name="post_author"]' ].forEach( function ( sel ) {
					var field = form.querySelector( sel );
					var label = field ? field.closest( 'label' ) : null;
					if ( label ) {
						label.style.display = 'none';
					}
				} );
				form.querySelectorAll( '.inline-edit-date, .inline-edit-group' ).forEach( function ( el ) {
					el.style.display = 'none';
				} );
			};
		}() );
		</script>
		<?php
	}
}
