<?php
/**
 * The materials list for one project, bucketed under pieces.
 *
 * Both render paths - the live season tab and the past-projects archive - come
 * through here, so a material list has one shape in one place. `material-item`
 * stays parent-agnostic: it renders exactly one <li> and holds no opinion about
 * what it sits inside (Piece_Grouping_Spec.md invariant P5).
 *
 * When nothing on the project carries a piece, this renders the same flat list
 * it always did, with no headings at all. Grouping appears only once somebody
 * actually files something, so adopting pieces is opt-in per project and
 * existing projects look untouched.
 *
 * Expected in scope:
 *   $materials   array[] Material rows, already permission-filtered.
 *   $project_id  int     Project post ID.
 *   $selectable  bool    Optional. False suppresses the zip checkbox.
 *   $list_class  string  Optional. Extra classes for the outer <ul>.
 *
 * @package ArsNovaSingersPortal
 */

defined( 'ABSPATH' ) || exit;

if ( ! isset( $materials ) || ! is_array( $materials ) || ! $materials ) {
	return;
}

$ansp_list_pid        = isset( $project_id ) ? (int) $project_id : 0;
$ansp_list_selectable = isset( $selectable ) ? (bool) $selectable : true;
$ansp_list_class      = isset( $list_class ) ? (string) $list_class : '';
$ansp_buckets         = ANSP_Materials::group_by_piece( $materials );

// One bucket with no label means nothing here is filed under a piece. Render
// the plain list rather than inventing an "Other materials" heading for a
// project that has never heard of pieces.
$ansp_flat = ( 1 === count( $ansp_buckets ) && '' === $ansp_buckets[0]['piece'] );
?>
<ul class="ansp-materials ansp-materials--list<?php echo $ansp_list_class ? ' ' . esc_attr( $ansp_list_class ) : ''; ?><?php echo $ansp_flat ? '' : ' ansp-materials--pieced'; ?>" data-ansp-materials>
	<?php if ( $ansp_flat ) : ?>
		<?php
		foreach ( $ansp_buckets[0]['rows'] as $ansp_material ) {
			ansp_get_template(
				'material-item',
				array(
					'material'   => $ansp_material,
					'project_id' => $ansp_list_pid,
					'selectable' => $ansp_list_selectable,
				)
			);
		}
		?>
	<?php else : ?>
		<?php foreach ( $ansp_buckets as $ansp_bucket ) : ?>
			<?php
			$ansp_is_other    = ( '' === $ansp_bucket['piece'] );
			$ansp_piece_label = $ansp_is_other
				? __( 'Other materials', 'ans-singers-portal' )
				: $ansp_bucket['piece'];
			?>
			<li class="ansp-piece<?php echo $ansp_is_other ? ' ansp-piece--other' : ''; ?>" data-ansp-piece>
				<h4 class="ansp-piece-title"><?php echo esc_html( $ansp_piece_label ); ?></h4>
				<ul class="ansp-piece-items">
					<?php
					foreach ( $ansp_bucket['rows'] as $ansp_material ) {
						ansp_get_template(
							'material-item',
							array(
								'material'   => $ansp_material,
								'project_id' => $ansp_list_pid,
								'selectable' => $ansp_list_selectable,
							)
						);
					}
					?>
				</ul>
			</li>
		<?php endforeach; ?>
	<?php endif; ?>
</ul>
