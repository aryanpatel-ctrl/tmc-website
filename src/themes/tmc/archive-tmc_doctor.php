<?php
/**
 * Find a doctor: filter by name and department (plain GET form, works without JavaScript).
 */

global $wp_query;
get_header();
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public filter form
$tmc_department = absint( $_GET['department'] ?? 0 );
$tmc_name       = sanitize_text_field( wp_unslash( $_GET['doctor_name'] ?? '' ) );
// phpcs:enable
$tmc_departments = get_posts( array( 'post_type' => 'tmc_department', 'posts_per_page' => 100, 'orderby' => 'title', 'order' => 'ASC', 'suppress_filters' => false ) );

get_template_part( 'template-parts/page-header', null, array( 'title' => esc_html__( 'Find a doctor', 'tmc' ), 'intro' => __( 'Search our doctors by name or department.', 'tmc' ) ) );
?>
<div class="container page-body is-wide">
	<form class="filter-form" method="get" action="<?php echo esc_url( get_post_type_archive_link( 'tmc_doctor' ) ); ?>">
		<p>
			<label for="doctor-name"><?php esc_html_e( 'Name', 'tmc' ); ?></label>
			<input type="search" id="doctor-name" name="doctor_name" value="<?php echo esc_attr( $tmc_name ); ?>" autocomplete="off">
		</p>
		<p>
			<label for="doctor-department"><?php esc_html_e( 'Department', 'tmc' ); ?></label>
			<select id="doctor-department" name="department">
				<option value="0"><?php esc_html_e( 'All departments', 'tmc' ); ?></option>
				<?php foreach ( $tmc_departments as $tmc_dept ) : ?>
					<option value="<?php echo (int) $tmc_dept->ID; ?>"<?php selected( $tmc_department, $tmc_dept->ID ); ?>><?php echo esc_html( $tmc_dept->post_title ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="filter-actions">
			<button type="submit" class="button"><?php esc_html_e( 'Find', 'tmc' ); ?></button>
			<?php if ( $tmc_department || $tmc_name ) : ?>
				<a href="<?php echo esc_url( get_post_type_archive_link( 'tmc_doctor' ) ); ?>"><?php esc_html_e( 'Clear', 'tmc' ); ?></a>
			<?php endif; ?>
		</p>
	</form>

	<p class="search-count" role="status">
		<?php
		/* translators: %s: number of doctors */
		echo esc_html( sprintf( _n( '%s doctor found.', '%s doctors found.', (int) $wp_query->found_posts, 'tmc' ), number_format_i18n( (int) $wp_query->found_posts ) ) );
		?>
	</p>

	<?php if ( have_posts() ) : ?>
		<ul class="card-grid doctor-grid">
			<?php
			while ( have_posts() ) :
				the_post();
				get_template_part( 'template-parts/doctor-card' );
			endwhile;
			?>
		</ul>
		<?php the_posts_pagination( array( 'prev_text' => __( 'Previous', 'tmc' ), 'next_text' => __( 'Next', 'tmc' ), 'aria_label' => __( 'Pages', 'tmc' ) ) ); ?>
	<?php endif; ?>
</div>
<?php
get_footer();
