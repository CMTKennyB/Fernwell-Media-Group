<?php
/**
 * © 2026 Kenneth Raymond — All rights reserved.
 * Part of the Fernwell WordPress theme.
 * Do not copy, modify, or redistribute without permission.
 *
 * File: Single Answer Template
 * Purpose: Single post layout with byline and publish/updated dates.
 *
 * Location:
 *   /wp-content/themes/fernwell/single.php
 */

get_header();
?>

<div class="entry">
  <div class="wrap">
    <?php while ( have_posts() ) : the_post(); ?>
      <header class="entry-header">
        <h1><?php the_title(); ?></h1>
        <div class="meta">By Kenneth Raymond &middot; <?php echo esc_html( get_the_date() ); ?><?php if ( get_the_modified_date() !== get_the_date() ) : ?> &middot; Updated <?php echo esc_html( get_the_modified_date() ); ?><?php endif; ?></div>
      </header>
      <div class="entry-content">
        <?php the_content(); ?>
      </div>
    <?php endwhile; ?>
  </div>
</div>

<?php
get_footer();
