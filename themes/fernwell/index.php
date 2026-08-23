<?php
/**
 * © 2026 Kenneth Raymond — All rights reserved.
 * Part of the Fernwell WordPress theme.
 * Do not copy, modify, or redistribute without permission.
 *
 * File: Answers Listing Template
 * Purpose: Blog index for the Answers section.
 *
 * Location:
 *   /wp-content/themes/fernwell/index.php
 */

get_header();
?>

<div class="answers-head">
  <div class="wrap">
    <h1>Answers</h1>
    <p>Direct answers to the questions businesses ask about AI search, AEO, and staying visible where buying decisions now start.</p>
  </div>
</div>

<div class="answers-list">
  <div class="wrap">
    <?php if ( have_posts() ) : ?>
      <?php while ( have_posts() ) : the_post(); ?>
        <article class="answer-item">
          <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
          <div class="meta"><time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time></div>
          <div class="excerpt"><?php the_excerpt(); ?></div>
        </article>
      <?php endwhile; ?>
      <?php the_posts_pagination(); ?>
    <?php else : ?>
      <p>No answers published yet. The first ones are on the way.</p>
    <?php endif; ?>
  </div>
</div>

<?php
get_footer();
