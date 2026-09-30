<?php
/**
 * Search form (used in the header and the 404 page). data-tmc-suggest: assets/js/features/search.js
 * turns the box into a suggestions combobox; without JavaScript it is a plain search form.
 */

$tmc_search_id = wp_unique_id( 'search-' );
?>
<form role="search" method="get" class="search-form" action="<?php echo esc_url( tmc_home_url() ); ?>" data-tmc-suggest>
	<label for="<?php echo esc_attr( $tmc_search_id ); ?>" class="screen-reader-text"><?php esc_html_e( 'Search this website', 'tmc' ); ?></label>
	<input type="search" id="<?php echo esc_attr( $tmc_search_id ); ?>" class="search-input" name="s" value="<?php echo esc_attr( get_search_query() ); ?>" placeholder="<?php esc_attr_e( 'Search this website', 'tmc' ); ?>" maxlength="100" autocomplete="off">
	<button type="submit" class="search-submit">
		<svg aria-hidden="true" focusable="false" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M10 2a8 8 0 0 1 6.32 12.9l5.39 5.4-1.41 1.4-5.4-5.39A8 8 0 1 1 10 2Zm0 2a6 6 0 1 0 0 12 6 6 0 0 0 0-12Z"/></svg>
		<span class="screen-reader-text"><?php esc_html_e( 'Search', 'tmc' ); ?></span>
	</button>
</form>
