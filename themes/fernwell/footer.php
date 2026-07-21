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
<footer class="site">
  <div class="wrap">
    <div>&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> Fernwell Media Group. All rights reserved.</div>
    <div>
      <a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>">Privacy</a> &middot;
      <a href="<?php echo esc_url( home_url( '/terms-of-service/' ) ); ?>">Terms</a> &middot;
      <a href="<?php echo esc_url( home_url( '/cookie-policy/' ) ); ?>">Cookies</a> &middot;
      <a href="mailto:hello@fernwellmedia.com">hello@fernwellmedia.com</a> &middot;
      <a href="https://www.linkedin.com/in/kenny-raymond/">LinkedIn</a>
    </div>
  </div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
