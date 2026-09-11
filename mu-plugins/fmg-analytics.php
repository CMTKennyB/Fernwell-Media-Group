<?php
/**
 * Plugin Name: FMG Analytics
 * Description: Google Analytics 4 for the Fernwell site, loaded only after the visitor grants statistics consent in WPConsent. Production only; admins excluded.
 * Version:     1.0.0
 * Author:      Fernwell Media Group
 *
 * Drop this file directly into wp-content/mu-plugins/ (no subfolder: mu-plugins
 * only autoloads top-level PHP files).
 *
 * How consent works here. The two Google tag scripts are printed already
 * blocked: type="text/plain" with WPConsent's data attributes, category
 * "statistics", service "google-analytics". Nothing is fetched and nothing
 * runs until WPConsent's unlockScripts() swaps them for live scripts, which
 * it does on an accept, on a preferences save that includes statistics, and
 * on every later page load while that choice is stored. WPConsent then
 * pushes a Google Consent Mode "update" (analytics_storage granted) into
 * the data layer, so the tag starts life with an explicit consent signal.
 * That is Google's "basic" consent mode and it keeps the banner's promise:
 * nothing optional runs before a choice. If WPConsent were ever removed the
 * scripts stay inert, which is the safe failure.
 *
 * Add "Google Analytics" as a statistics service in WPConsent > Cookies so
 * the preferences panel lists it.
 *
 * @package Fernwell
 */

defined( 'ABSPATH' ) || exit;

define( 'FMG_GA4_MEASUREMENT_ID', 'G-DGCGXLEQYF' );

/**
 * Whether to print the tag on this request: production only, never for
 * users who can manage the site, never in feeds or embeds. Filterable.
 */
function fmg_analytics_enabled() {
	$enabled = 'production' === wp_get_environment_type()
		&& ! current_user_can( 'manage_options' )
		&& ! is_feed()
		&& ! is_embed();
	return (bool) apply_filters( 'fmg_analytics_enabled', $enabled );
}

function fmg_analytics_tag() {
	if ( ! fmg_analytics_enabled() ) {
		return;
	}
	$id  = FMG_GA4_MEASUREMENT_ID;
	$src = 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $id );
	?>
<script type="text/plain" data-wpconsent-category="statistics" data-wpconsent-name="google-analytics" data-wpconsent-src="<?php echo esc_url( $src ); ?>" async></script>
<script type="text/plain" data-wpconsent-category="statistics" data-wpconsent-name="google-analytics">
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', '<?php echo esc_js( $id ); ?>', { anonymize_ip: true });
</script>
	<?php
}
add_action( 'wp_head', 'fmg_analytics_tag', 20 );
