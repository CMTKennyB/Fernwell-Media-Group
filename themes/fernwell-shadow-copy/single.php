<?php
/**
 * © 2026 Kenneth Raymond — All rights reserved.
 * Part of the Fernwell WordPress theme.
 * Do not copy, modify, or redistribute without permission.
 *
 * File: Single Answer Template
 * Purpose: Single post layout: Fernwell Answers kicker, question H1,
 * byline with publish/updated dates, answer-first content, closing CTA.
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
        <a class="kicker" href="<?php echo esc_url( fernwell_answers_url() ); ?>">Fernwell Answers:</a>
        <h1><?php the_title(); ?></h1>
        <div class="meta">By Kenneth Raymond <span aria-hidden="true">&middot;</span> <time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time> <span aria-hidden="true">&middot;</span> <?php echo esc_html( fernwell_reading_time() ); ?> min read</div>
      </header>
      <div class="entry-content">
        <?php the_content(); ?>
      </div>
      <?php
      // Show only for revisions made on a later day than publication: same-day
      // touches don't count, and scheduled posts carry a pre-publication
      // modified stamp that must never render as an update.
      if ( get_post_modified_time( 'U', true ) > get_post_time( 'U', true ) && get_the_modified_date() !== get_the_date() ) :
      ?>
      <div class="entry-updated">Updated <time datetime="<?php echo esc_attr( get_the_modified_date( 'c' ) ); ?>"><?php echo esc_html( get_the_modified_date() ); ?></time></div>
      <?php endif; ?>
      <aside class="post-cta">
        <h2>Does AI name you?</h2>
        <p>Start where every AI visibility strategy starts: can an engine actually read you? Run a free, single-page scan for readability and citability. No account. No email.</p>
        <div class="actions">
          <a class="btn primary" href="<?php echo esc_url( home_url( '/#scan' ) ); ?>">Run the free scan</a>
          <a class="btn ghost" href="mailto:hello@fernwellgroup.com?subject=Request%20your%20audit">Request your audit</a>
        </div>
      </aside>
      <?php
      $fernwell_prev = get_previous_post();
      $fernwell_next = get_next_post();
      ?>
      <nav class="post-nav" aria-label="More answers">
        <div class="post-nav__prev"><?php if ( $fernwell_prev ) : ?><a class="btn ghost" href="<?php echo esc_url( get_permalink( $fernwell_prev ) ); ?>" aria-label="Previous answer: <?php echo esc_attr( get_the_title( $fernwell_prev ) ); ?>" title="<?php echo esc_attr( get_the_title( $fernwell_prev ) ); ?>"><span aria-hidden="true">&larr;</span> Previous</a><?php endif; ?></div>
        <a class="btn ghost post-nav__back" href="<?php echo esc_url( fernwell_answers_url() ); ?>" data-answers-base="<?php echo esc_attr( wp_parse_url( fernwell_answers_url(), PHP_URL_PATH ) ); ?>">All answers</a>
        <div class="post-nav__next"><?php if ( $fernwell_next ) : ?><a class="btn ghost" href="<?php echo esc_url( get_permalink( $fernwell_next ) ); ?>" aria-label="Next answer: <?php echo esc_attr( get_the_title( $fernwell_next ) ); ?>" title="<?php echo esc_attr( get_the_title( $fernwell_next ) ); ?>">Next <span aria-hidden="true">&rarr;</span></a><?php endif; ?></div>
      </nav>
    <?php endwhile; ?>
  </div>
</div>

<?php
get_footer();
