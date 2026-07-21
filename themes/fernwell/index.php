<?php
/**
 * Blog listing: the Answers section.
 *
 * @package Fernwell
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
          <div class="meta"><?php echo esc_html( get_the_date() ); ?></div>
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
