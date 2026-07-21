<?php
/**
 * © 2026 Kenneth Raymond — All rights reserved.
 * Part of the Fernwell WordPress theme.
 * Do not copy, modify, or redistribute without permission.
 *
 * File: 404 Template
 * Purpose: Not-found page with on-brand copy and recovery paths.
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
      <p>The URL you followed doesn't exist here, which is ironic for a company in the findability business, and we'd rather own that than show you a generic error.</p>
      <p>Here's where the answers actually live:</p>
      <p>
        <a class="btn primary" href="<?php echo esc_url( home_url( '/' ) ); ?>">Back to the homepage</a>
      </p>
      <p>Or tell us what you were looking for at <a href="mailto:hello@fernwellmedia.com">hello@fernwellmedia.com</a> and a human will answer.</p>
    </div>
  </div>
</div>

<?php
get_footer();
