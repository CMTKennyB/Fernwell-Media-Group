<?php
/**
 * © 2026 Kenneth Raymond. All rights reserved.
 * Part of the Fernwell WordPress theme.
 * Do not copy, modify, or redistribute without permission.
 *
 * File: 404 Template
 * Purpose: Not-found page as a re-entry point:
 * on-brand copy, destination buttons, and the latest answers.
 *
 * Location:
 *   /wp-content/themes/fernwell/404.php
 */

get_header();
?>

<div class="entry">
  <div class="wrap">
    <header class="entry-header">
      <h1>This page isn't the answer.</h1>
    </header>
    <div class="entry-content">
      <p>The URL you followed doesn't exist here, which is ironic for a site in the findability space, and we'd rather own this than show you a generic error message.</p>
      <p>Here's where the answers actually live:</p>
      <div class="reentry-actions">
        <a class="btn primary" href="<?php echo esc_url( home_url( '/' ) ); ?>">Back to the homepage</a>
        <a class="btn ghost" href="<?php echo esc_url( home_url( '/#scan' ) ); ?>">Run the free scan</a>
        <a class="btn ghost" href="<?php echo esc_url( fernwell_answers_url() ); ?>">Fernwell Answers</a>
      </div>
      <p>Or tell us what you were looking for at <a href="mailto:hello@fernwellgroup.com">hello@fernwellgroup.com</a> and a human will answer.</p>
    </div>
    <?php $fernwell_recent = get_posts( array( 'numberposts' => 3, 'post_status' => 'publish', 'meta_query' => fernwell_indexable_meta_query() ) ); ?>
    <?php if ( $fernwell_recent ) : ?>
    <div class="reentry-answers">
      <h2>Latest answers</h2>
      <div class="answers-strip">
        <?php foreach ( $fernwell_recent as $fernwell_answer ) : ?>
          <a class="answers-strip__item" href="<?php echo esc_url( get_permalink( $fernwell_answer ) ); ?>">
            <h3><?php echo esc_html( get_the_title( $fernwell_answer ) ); ?></h3>
            <span class="meta"><?php echo esc_html( get_the_date( '', $fernwell_answer ) ); ?> <span aria-hidden="true">&middot;</span> <?php echo esc_html( fernwell_reading_time( $fernwell_answer ) ); ?> min read</span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php
get_footer();
