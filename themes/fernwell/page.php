<?php
/**
 * Static page template.
 *
 * @package Fernwell
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
