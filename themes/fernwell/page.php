<?php
/**
 * © 2026 Kenneth Raymond — All rights reserved.
 * Part of the Fernwell WordPress theme.
 * Do not copy, modify, or redistribute without permission.
 *
 * File: Static Page Template
 * Purpose: Default layout for static pages.
 *
 * Location:
 *   /wp-content/themes/fernwell/page.php
 */

get_header();
?>

<div class="entry">
  <div class="wrap">
    <?php while ( have_posts() ) : the_post(); ?>
      <header class="entry-header">
        <h1><?php the_title(); ?></h1>
      </header>
      <div class="entry-content">
        <?php the_content(); ?>
      </div>
    <?php endwhile; ?>
  </div>
</div>

<?php
get_footer();
