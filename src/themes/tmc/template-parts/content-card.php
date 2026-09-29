<?php
/**
 * One post in a list (archives, search results).
 */
?>
<li class="card">
	<p class="card-meta">
		<?php if ( 'post' === get_post_type() ) : ?>
			<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( tmc_date( (int) get_post_time( 'U', true ) ) ); ?></time>
		<?php else : ?>
			<?php echo esc_html( get_post_type_object( get_post_type() )->labels->singular_name ); ?>
		<?php endif; ?>
	</p>
	<h2 class="card-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
	<p class="card-text"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 28 ) ); ?></p>
</li>
