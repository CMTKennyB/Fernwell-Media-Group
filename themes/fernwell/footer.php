<?php
/**
 * © 2026 Kenneth Raymond — All rights reserved.
 * Part of the Fernwell WordPress theme.
 * Do not copy, modify, or redistribute without permission.
 *
 * File: Site Footer
 * Purpose: Footer chrome, contact and LinkedIn links, wp_footer hook.
 *
 * Location:
 *   /wp-content/themes/fernwell/footer.php
 */
?>
</main>

<footer class="site">
  <div class="wrap">
    <div>&copy; <?php
      $fernwell_launch_year  = 2026;
      $fernwell_current_year = (int) wp_date( 'Y' );
      echo esc_html( $fernwell_current_year > $fernwell_launch_year ? $fernwell_launch_year . '-' . $fernwell_current_year : (string) $fernwell_launch_year );
    ?> Fernwell Media Group. All rights reserved.</div>
    <div>
      <a href="<?php echo esc_url( home_url( '/answers/' ) ); ?>">Answers</a> &middot;
      <a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>">Privacy</a> &middot;
      <a href="<?php echo esc_url( home_url( '/terms-of-service/' ) ); ?>">Terms</a> &middot;
      <a href="<?php echo esc_url( home_url( '/cookie-policy/' ) ); ?>">Cookies</a> &middot;
      <a href="mailto:hello@fernwellgroup.com">hello@fernwellgroup.com</a>
    </div>
  </div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
