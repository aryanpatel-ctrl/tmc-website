<?php
/**
 * Search results: pages, news, every content type and documents (incl. text inside PDFs), in the
 * current language. Plain GET form and facet links (works without JavaScript); search.js adds
 * suggestions to the search box.
 */

get_header();

global $wp_query;
$tmc_query  = get_search_query( false );
$tmc_result = function_exists( 'tmc_search_current' ) ? tmc_search_current() : null;
$tmc_result = is_array( $tmc_result ) ? $tmc_result : array(
	'query'    => $tmc_query,
	'terms'    => array(),
	'type'     => '',
	'page'     => 1,
	'per_page' => 10,
	'total'    => (int) $wp_query->found_posts,
	'facets'   => array(),
);
$tmc_types  = function_exists( 'tmc_search_post_types' ) ? tmc_search_post_types() : array();

/* translators: %s: search terms */
$tmc_title = sprintf( __( 'Search results for “%s”', 'tmc' ), $tmc_query );
get_template_part( 'template-parts/page-header', null, array( 'title' => esc_html( $tmc_title ) ) );
?>
<div class="container page-body search-page">
	<form role="search" method="get" class="filter-form search-filter" action="<?php echo esc_url( tmc_home_url() ); ?>" data-tmc-suggest>
		<p class="search-filter-query">
			<label for="search-page-q"><?php esc_html_e( 'Search for', 'tmc' ); ?></label>
			<input type="search" id="search-page-q" name="s" value="<?php echo esc_attr( $tmc_query ); ?>" maxlength="100" autocomplete="off">
		</p>
		<p>
			<label for="search-page-type"><?php esc_html_e( 'Show', 'tmc' ); ?></label>
			<select id="search-page-type" name="type">
				<option value=""><?php esc_html_e( 'All content', 'tmc' ); ?></option>
				<?php foreach ( $tmc_types as $tmc_type ) : ?>
					<option value="<?php echo esc_attr( $tmc_type ); ?>"<?php selected( $tmc_result['type'], $tmc_type ); ?>><?php echo esc_html( tmc_search_type_label( $tmc_type, true ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="filter-actions">
			<button type="submit" class="button"><?php esc_html_e( 'Search', 'tmc' ); ?></button>
		</p>
	</form>

	<?php tmc_search_facets( $tmc_result ); ?>

	<p class="search-count" role="status"><?php echo esc_html( tmc_search_count_text( $tmc_result, (int) $wp_query->post_count ) ); ?></p>

	<?php if ( have_posts() ) : ?>
		<ul class="result-list search-results">
			<?php
			while ( have_posts() ) :
				the_post();
				get_template_part( 'template-parts/search-result', null, array( 'terms' => $tmc_result['terms'] ) );
			endwhile;
			?>
		</ul>
		<?php
		the_posts_pagination(
			array(
				'prev_text'          => __( 'Previous', 'tmc' ),
				'next_text'          => __( 'Next', 'tmc' ),
				'before_page_number' => '<span class="screen-reader-text">' . __( 'Page', 'tmc' ) . ' </span>',
				'aria_label'         => __( 'Search result pages', 'tmc' ),
			)
		);
		?>
	<?php else : ?>
		<div class="empty-state search-help">
			<p><strong><?php esc_html_e( 'No results found.', 'tmc' ); ?></strong> <?php esc_html_e( 'Suggestions:', 'tmc' ); ?></p>
			<ul>
				<li><?php esc_html_e( 'Check the spelling of your search words.', 'tmc' ); ?></li>
				<li><?php esc_html_e( 'Use fewer or more general words.', 'tmc' ); ?></li>
				<?php if ( '' !== $tmc_result['type'] ) : ?>
					<li><a href="<?php echo esc_url( tmc_search_url( $tmc_query ) ); ?>"><?php esc_html_e( 'Search all content types', 'tmc' ); ?></a></li>
				<?php endif; ?>
				<?php $tmc_sitemap = tmc_search_sitemap_url(); ?>
				<?php if ( $tmc_sitemap ) : ?>
					<li><a href="<?php echo esc_url( $tmc_sitemap ); ?>"><?php esc_html_e( 'Browse all pages in the sitemap', 'tmc' ); ?></a></li>
				<?php endif; ?>
			</ul>
		</div>
	<?php endif; ?>
</div>
<?php
get_footer();
