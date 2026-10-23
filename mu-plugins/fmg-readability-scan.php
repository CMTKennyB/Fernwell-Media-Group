<?php
/**
 * Plugin Name: FMG AI Legibility & Citability Scanner
 * Description: Deterministic single-URL scan. Reports per-engine eligibility states from each operator's published rules, then scores whether a machine can read the page (Legibility) and whether the page carries what a citation needs (Citability). Shortcode: [fmg_readability_scan]. Logs anonymous scores for the Tools > Scan Scores dataset.
 * Version:     3.0.0
 * Author:      Fernwell Media Group
 *
 * Drop this file directly into wp-content/mu-plugins/ (no subfolder: mu-plugins
 * only autoloads top-level PHP files).
 *
 * Scoring model: eligibility is a set of engine-scoped states, scorability is a
 * state, and the two dials are formative composites of evidence-backed clusters
 * with not-applicable checks removed from the denominator. Integer weights
 * throughout.
 *
 * @package Fernwell
 */

defined( 'ABSPATH' ) || exit;


/** Scans allowed per IP per hour. */
const FMG_SCAN_RATE_LIMIT = 10;

/** How long an identical URL's result is reused, in seconds. */
const FMG_SCAN_CACHE_TTL = 900;

/** Cache window for a result whose access state is indeterminate (transient errors should not become a quarter-hour score). */
const FMG_SCAN_CACHE_TTL_SHORT = 120;

/** Hard ceiling on fetched (decoded) bytes per request. Matches Google's documented 2 MB cap. */
const FMG_SCAN_MAX_BYTES = 2097152;

/** Google's documented fetch ceiling for HTML, headers included, applied to uncompressed data. */
const FMG_SCAN_GOOGLE_CAP = 2097152;

/** Wall-clock budget for one scan, in seconds. Optional fetches are skipped past this. */
const FMG_SCAN_BUDGET = 60;

/** User agent the scanner identifies itself with. It never impersonates another operator's crawler. */
const FMG_SCAN_UA = 'FernwellReadabilityBot/3.0 (+https://fernwellgroup.com/scan)';

/** Browser user agent used only for the differential probe when the scanner UA is turned away. */
const FMG_SCAN_BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

/** Specification version recorded with every logged scan. */
const FMG_SCAN_SPEC = '3';

/** Below this many word tokens of server-side main text the page is unscorable. */
const FMG_SCAN_MIN_TOKENS = 50;

/*
|--------------------------------------------------------------------------
| REST endpoint
|--------------------------------------------------------------------------
*/

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'fmg/v1',
			'/scan',
			array(
				'methods'             => 'POST',
				'callback'            => 'fmg_scan_handle_request',
				'permission_callback' => '__return_true',
				'args'                => array(
					'url' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}
);

/**
 * Handle a scan request.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function fmg_scan_handle_request( $request ) {
	// Parse only: DNS waits until the request has passed the rate limit.
	$url = fmg_scan_normalize_url( (string) $request->get_param( 'url' ), false );
	if ( is_wp_error( $url ) ) {
		return $url;
	}

	// The cache is consulted before the rate limit, so a repeat of a recent scan
	// does not spend a visitor's hourly budget.
	$cache_key = 'fmg_scan_v' . FMG_SCAN_SPEC . '_' . md5( $url );
	$cached    = current_user_can( 'manage_options' ) ? false : get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		$cached['cached'] = true;
		return rest_ensure_response( $cached );
	}

	$limited = fmg_scan_check_rate_limit();
	if ( is_wp_error( $limited ) ) {
		return $limited;
	}

	$safe = fmg_scan_host_is_public( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	if ( is_wp_error( $safe ) ) {
		return $safe;
	}

	$result = fmg_scan_run( $url );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$ttl = ( 'ok' === ( $result['states']['access'] ?? 'ok' ) ) ? FMG_SCAN_CACHE_TTL : FMG_SCAN_CACHE_TTL_SHORT;
	set_transient( $cache_key, $result, $ttl );
	fmg_scan_log_result( $result );
	return rest_ensure_response( $result );
}

/*
|--------------------------------------------------------------------------
| Score log
|--------------------------------------------------------------------------
| An anonymous dataset of scan outcomes for developing the tool: one row per
| fresh, successful, public scan with the two dials (uncapped, or null when
| unscorable), each cluster's earned/max, each check's credit (0 to 1, or
| null when not applicable; notes store presence), the eligibility vector and
| the states. No URL, host, or IP is stored. Cached repeats, admin test scans,
| and scans of this site itself are left out so the dataset reflects
| third-party pages only. The readability column stores the Legibility dial.
| Rows with an empty spec_version predate the current scoring model.
*/

/** Bump when the table shape changes; the table is created or altered on the next request. */
const FMG_SCAN_LOG_DB_VERSION = '2';

function fmg_scan_log_table() {
	global $wpdb;
	return $wpdb->prefix . 'fmg_scan_log';
}

function fmg_scan_log_install() {
	if ( get_option( 'fmg_scan_log_db_version' ) === FMG_SCAN_LOG_DB_VERSION ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table   = fmg_scan_log_table();
	$charset = $wpdb->get_charset_collate();
	dbDelta(
		"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scanned_at datetime NOT NULL,
			spec_version varchar(8) NOT NULL DEFAULT '',
			readability tinyint(3) unsigned NULL,
			citability tinyint(3) unsigned NULL,
			categories text NOT NULL,
			checks text NOT NULL,
			eligibility text NOT NULL,
			states text NOT NULL,
			PRIMARY KEY  (id),
			KEY scanned_at (scanned_at)
		) {$charset};"
	);
	update_option( 'fmg_scan_log_db_version', FMG_SCAN_LOG_DB_VERSION );
}
add_action( 'init', 'fmg_scan_log_install' );

/**
 * Whether this result belongs in the dataset. Filterable.
 */
function fmg_scan_log_should_log( $result ) {
	$log = ! current_user_can( 'manage_options' );
	$own = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	$hit = strtolower( (string) ( $result['host'] ?? '' ) );
	if ( $own && ( $hit === $own || $hit === 'www.' . $own || 'www.' . $hit === $own ) ) {
		$log = false;
	}
	return (bool) apply_filters( 'fmg_scan_log_enabled', $log, $result );
}

/**
 * Record a fresh result. Never lets a logging problem break the scan.
 */
function fmg_scan_log_result( $result ) {
	if ( ! is_array( $result ) || ! fmg_scan_log_should_log( $result ) ) {
		return;
	}
	global $wpdb;
	$categories = array();
	$checks     = array();
	$labels     = get_option( 'fmg_scan_check_labels', array() );
	foreach ( array( 'legibility', 'citability' ) as $axis ) {
		foreach ( (array) ( $result[ $axis ]['clusters'] ?? array() ) as $key => $cat ) {
			$categories[ $key ] = array( 'earned' => round( (float) ( $cat['earned'] ?? 0 ), 2 ), 'max' => (int) ( $cat['max'] ?? 0 ) );
			foreach ( (array) ( $cat['checks'] ?? array() ) as $check ) {
				if ( 'na' === $check['state'] ) {
					$checks[ $check['id'] ] = null;
				} elseif ( 'info' === $check['state'] ) {
					$checks[ $check['id'] ] = $check['present'] ? 1 : 0;
				} else {
					$checks[ $check['id'] ] = round( $check['credit'], 2 );
				}
				$labels[ $check['id'] ] = array( 'label' => $check['label'], 'category' => $cat['label'], 'weight' => (int) $check['weight'] );
			}
		}
	}
	foreach ( (array) ( $result['observations'] ?? array() ) as $note ) {
		$checks[ $note['id'] ] = $note['present'] ? 1 : 0;
		$labels[ $note['id'] ] = array( 'label' => $note['label'], 'category' => 'Observations', 'weight' => 0 );
	}
	$wpdb->insert(
		fmg_scan_log_table(),
		array(
			'scanned_at'   => gmdate( 'Y-m-d H:i:s' ),
			'spec_version' => FMG_SCAN_SPEC,
			'readability'  => isset( $result['legibility']['score'] ) ? (int) $result['legibility']['score'] : null,
			'citability'   => isset( $result['citability']['score'] ) ? (int) $result['citability']['score'] : null,
			'categories'   => wp_json_encode( $categories ),
			'checks'       => wp_json_encode( $checks ),
			'eligibility'  => wp_json_encode( array_map( function ( $e ) { return array( $e['key'], $e['state'] ); }, (array) ( $result['eligibility'] ?? array() ) ) ),
			'states'       => wp_json_encode( array_diff_key( (array) ( $result['states'] ?? array() ), array( 'reasons' => true ) ) ), // Reasons can quote page URLs.
		),
		array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
	);
	update_option( 'fmg_scan_check_labels', $labels, false );
}

/*
|--------------------------------------------------------------------------
| Admin: Tools > Scan Scores
|--------------------------------------------------------------------------
*/

add_action(
	'admin_menu',
	function () {
		add_management_page( 'Scan Scores', 'Scan Scores', 'manage_options', 'fmg-scan-scores', 'fmg_scan_log_page' );
	}
);

/**
 * Rows for a window: 'all' or a number of days. Only rows of the current spec version.
 */
function fmg_scan_log_rows( $window ) {
	global $wpdb;
	$table = fmg_scan_log_table();
	if ( 'all' === $window ) {
		return $wpdb->get_results( $wpdb->prepare( "SELECT scanned_at, readability, citability, categories, checks, eligibility, states FROM {$table} WHERE spec_version = %s ORDER BY scanned_at ASC", FMG_SCAN_SPEC ), ARRAY_A );
	}
	return $wpdb->get_results(
		$wpdb->prepare( "SELECT scanned_at, readability, citability, categories, checks, eligibility, states FROM {$table} WHERE spec_version = %s AND scanned_at >= %s ORDER BY scanned_at ASC", FMG_SCAN_SPEC, gmdate( 'Y-m-d H:i:s', time() - (int) $window * DAY_IN_SECONDS ) ),
		ARRAY_A
	);
}

function fmg_scan_log_median( $values ) {
	sort( $values );
	$n = count( $values );
	if ( ! $n ) {
		return 0;
	}
	$mid = (int) floor( $n / 2 );
	return $n % 2 ? $values[ $mid ] : ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2;
}

function fmg_scan_log_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$window = isset( $_GET['window'] ) && '30' === $_GET['window'] ? '30' : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$rows   = fmg_scan_log_rows( $window );
	$labels = get_option( 'fmg_scan_check_labels', array() );
	$n      = count( $rows );

	$read = array();
	$cite = array();
	$unscorable = 0;
	foreach ( $rows as $row ) {
		if ( null === $row['readability'] || '' === $row['readability'] ) {
			$unscorable++;
			continue;
		}
		$read[] = (int) $row['readability'];
		$cite[] = (int) $row['citability'];
	}

	$bands = array( 'legibility' => array_fill( 0, 10, 0 ), 'citability' => array_fill( 0, 10, 0 ) );
	$cats  = array();
	$pass  = array();
	$elig  = array();
	foreach ( $rows as $row ) {
		if ( null !== $row['readability'] && '' !== $row['readability'] ) {
			$bands['legibility'][ min( 9, (int) floor( $row['readability'] / 10 ) ) ]++;
			$bands['citability'][ min( 9, (int) floor( $row['citability'] / 10 ) ) ]++;
		}
		foreach ( (array) json_decode( $row['categories'], true ) as $key => $c ) {
			$cats[ $key ]['earned'] = ( $cats[ $key ]['earned'] ?? 0 ) + $c['earned'];
			$cats[ $key ]['max']    = ( $cats[ $key ]['max'] ?? 0 ) + $c['max'];
		}
		foreach ( (array) json_decode( $row['checks'], true ) as $id => $p ) {
			if ( null === $p ) {
				continue;
			}
			$pass[ $id ]['n'] = ( $pass[ $id ]['n'] ?? 0 ) + 1;
			$pass[ $id ]['p'] = ( $pass[ $id ]['p'] ?? 0 ) + (float) $p;
		}
		foreach ( (array) json_decode( $row['eligibility'], true ) as $pair ) {
			if ( is_array( $pair ) && 2 === count( $pair ) ) {
				$elig[ $pair[0] ][ $pair[1] ] = ( $elig[ $pair[0] ][ $pair[1] ] ?? 0 ) + 1;
			}
		}
	}
	uasort( $pass, function ( $a, $b ) { return ( $a['p'] / max( 1, $a['n'] ) ) <=> ( $b['p'] / max( 1, $b['n'] ) ); } );

	$base       = admin_url( 'tools.php?page=fmg-scan-scores' );
	$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=fmg_scan_log_export&window=' . $window ), 'fmg_scan_log_export' );
	$stat       = function ( $values ) {
		return $values ? sprintf( 'mean %s, median %s, range %d to %d', number_format_i18n( array_sum( $values ) / count( $values ), 1 ), number_format_i18n( fmg_scan_log_median( $values ), 1 ), min( $values ), max( $values ) ) : 'no scored scans yet';
	};
	?>
	<div class="wrap fmg-scores">
		<h1>Scan Scores</h1>
		<p class="description">Anonymous outcomes of public scans under specification v<?php echo esc_html( FMG_SCAN_SPEC ); ?>: dials, cluster credit, check credit, eligibility states. No URLs. Cached repeats, admin test scans, and scans of this site are not counted.</p>
		<p>
			<a class="button<?php echo 'all' === $window ? ' button-primary' : ''; ?>" href="<?php echo esc_url( $base ); ?>">All time</a>
			<a class="button<?php echo '30' === $window ? ' button-primary' : ''; ?>" href="<?php echo esc_url( $base . '&window=30' ); ?>">Last 30 days</a>
			<?php if ( $n ) : ?><a class="button" href="<?php echo esc_url( $export_url ); ?>">Export CSV</a><?php endif; ?>
		</p>

		<h2><?php echo esc_html( number_format_i18n( $n ) ); ?> scan<?php echo 1 === $n ? '' : 's'; ?><?php if ( $n ) : ?>, <?php echo esc_html( wp_date( 'M j, Y', strtotime( $rows[0]['scanned_at'] ) ) ); ?> to <?php echo esc_html( wp_date( 'M j, Y', strtotime( end( $rows )['scanned_at'] ) ) ); ?>; <?php echo esc_html( number_format_i18n( $unscorable ) ); ?> unscorable<?php endif; ?></h2>

		<div class="fmg-scores__grid">
			<?php foreach ( array( 'legibility' => $read, 'citability' => $cite ) as $axis => $values ) : ?>
			<div class="fmg-scores__axis">
				<h3><?php echo esc_html( ucfirst( $axis ) ); ?></h3>
				<p><?php echo esc_html( $stat( $values ) ); ?></p>
				<table class="widefat striped fmg-scores__bands">
					<tbody>
					<?php for ( $b = 9; $b >= 0; $b-- ) : $count = $bands[ $axis ][ $b ]; ?>
						<tr>
							<th scope="row"><?php echo esc_html( 9 === $b ? '90 to 100' : ( $b * 10 ) . ' to ' . ( $b * 10 + 9 ) ); ?></th>
							<td class="fmg-scores__bar"><?php if ( $count ) : ?><span style="width:<?php echo esc_attr( round( $count / max( 1, count( $values ) ) * 100 ) ); ?>%"></span><?php endif; ?></td>
							<td class="fmg-scores__n"><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
						</tr>
					<?php endfor; ?>
					</tbody>
				</table>
			</div>
			<?php endforeach; ?>
		</div>

		<?php if ( $elig ) : ?>
		<h3>Eligibility states by engine</h3>
		<table class="widefat striped fmg-scores__table">
			<thead><tr><th>Engine</th><th>Eligible</th><th>Ineligible</th><th>Reduced</th><th>Indeterminate</th><th>Not documented</th></tr></thead>
			<tbody>
			<?php foreach ( $elig as $engine => $states ) : ?>
				<tr>
					<td><?php echo esc_html( $engine ); ?></td>
					<?php foreach ( array( 'eligible', 'ineligible', 'reduced', 'indeterminate', 'undocumented' ) as $s ) : ?>
					<td><?php echo esc_html( number_format_i18n( $states[ $s ] ?? 0 ) ); ?></td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<?php if ( $cats ) : ?>
		<h3>Average by cluster</h3>
		<table class="widefat striped fmg-scores__table">
			<thead><tr><th>Cluster</th><th>Average earned</th><th>Of</th><th>Rate</th></tr></thead>
			<tbody>
			<?php foreach ( $cats as $key => $c ) : ?>
				<tr>
					<td><?php echo esc_html( ucfirst( $key ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $c['earned'] / max( 1, $n ), 1 ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $c['max'] / max( 1, $n ), 1 ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $c['max'] ? $c['earned'] / $c['max'] * 100 : 0, 0 ) ); ?>%</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<?php if ( $pass ) : ?>
		<h3>Credit by check, hardest first</h3>
		<p class="description">Average credit earned per check among scans where it applied (a note with weight 0 shows how often it was present). A check most sites fail is either the signal that matters most or a check that needs another look.</p>
		<table class="widefat striped fmg-scores__table">
			<thead><tr><th>Check</th><th>Cluster</th><th>Weight</th><th>Scans</th><th>Credit</th></tr></thead>
			<tbody>
			<?php foreach ( $pass as $id => $p ) : $meta = $labels[ $id ] ?? array(); ?>
				<tr>
					<td><?php echo esc_html( $meta['label'] ?? $id ); ?></td>
					<td><?php echo esc_html( $meta['category'] ?? '' ); ?></td>
					<td><?php echo esc_html( isset( $meta['weight'] ) ? (int) $meta['weight'] : '' ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $p['n'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $p['p'] / max( 1, $p['n'] ) * 100, 0 ) ); ?>%</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</div>
	<style>
		.fmg-scores__grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px; max-width: 900px; margin: 12px 0 24px; }
		.fmg-scores__axis h3 { margin-bottom: 4px; }
		.fmg-scores__bands th { width: 90px; font-weight: 500; }
		.fmg-scores__bar { width: auto; }
		.fmg-scores__bar span { display: block; height: 14px; min-width: 2px; background: #156a58; border-radius: 3px; }
		.fmg-scores__n { width: 48px; text-align: right; font-variant-numeric: tabular-nums; }
		.fmg-scores__table { max-width: 900px; margin-bottom: 24px; }
	</style>
	<?php
}

/**
 * CSV export of the raw rows for the selected window. Admin only, nonced.
 */
add_action(
	'admin_post_fmg_scan_log_export',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'fmg_scan_log_export' );
		$window = isset( $_GET['window'] ) && '30' === $_GET['window'] ? '30' : 'all';
		$rows   = fmg_scan_log_rows( $window );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="fmg-scan-scores-' . $window . '-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'scanned_at_utc', 'legibility', 'citability', 'clusters_json', 'checks_json', 'eligibility_json', 'states_json' ) );
		foreach ( $rows as $row ) {
			fputcsv( $out, array( $row['scanned_at'], $row['readability'], $row['citability'], $row['categories'], $row['checks'], $row['eligibility'], $row['states'] ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
);

/**
 * Throttle by IP.
 *
 * @return true|WP_Error
 */
function fmg_scan_check_rate_limit() {
	// Site admins testing the scanner are not the traffic this throttles.
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}

	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	$ip  = (string) apply_filters( 'fmg_scan_client_ip', $ip );
	// One IPv6 subscriber holds a whole /64, so count the network, not the address;
	// IPv4-mapped addresses (::ffff:a.b.c.d) are IPv4 and stay per address.
	if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
		$hex = bin2hex( inet_pton( $ip ) );
		$ip  = 0 === strpos( $hex, '00000000000000000000ffff' ) ? inet_ntop( hex2bin( substr( $hex, 24 ) ) ) : substr( $hex, 0, 16 ) . '::/64';
	}
	$key = 'fmg_rl_' . md5( $ip );

	// A named lock serializes the read and write, so parallel requests from one
	// address cannot all read the same count and slip past the cap together.
	global $wpdb;
	if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $key ) ) ) {
		return new WP_Error( 'fmg_busy', 'The scanner is busy. Try again in a moment.', array( 'status' => 503 ) );
	}
	$hit = (int) get_transient( $key );
	if ( $hit < FMG_SCAN_RATE_LIMIT ) {
		set_transient( $key, $hit + 1, HOUR_IN_SECONDS );
	}
	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );

	if ( $hit >= FMG_SCAN_RATE_LIMIT ) {
		return new WP_Error(
			'fmg_rate_limited',
			'This is a lot of scans from one place. Try again in an hour.',
			array( 'status' => 429 )
		);
	}

	return true;
}

/*
|--------------------------------------------------------------------------
| URL safety and fetching
|--------------------------------------------------------------------------
*/

/**
 * Validate and normalize a submitted URL, rejecting anything that could reach internal hosts.
 * Scheme and host are lowercased, the query is kept, the fragment is dropped.
 *
 * @param string $raw        Raw user input.
 * @param bool   $check_host Resolve and vet the host; false parses only, with no DNS.
 * @return string|WP_Error
 */
function fmg_scan_normalize_url( $raw, $check_host = true ) {
	$raw = trim( $raw );
	if ( '' === $raw ) {
		return new WP_Error( 'fmg_bad_url', 'Enter a website address to scan.', array( 'status' => 400 ) );
	}

	if ( ! preg_match( '#^https?://#i', $raw ) ) {
		$raw = 'https://' . $raw;
	}

	$parts = wp_parse_url( $raw );
	if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
		return new WP_Error( 'fmg_bad_url', 'We cannot read this address. Try the full form, like https://example.com.', array( 'status' => 400 ) );
	}

	$scheme = strtolower( $parts['scheme'] );
	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
		return new WP_Error( 'fmg_bad_url', 'The scan covers only http and https addresses.', array( 'status' => 400 ) );
	}

	if ( isset( $parts['port'] ) && ! in_array( (int) $parts['port'], array( 80, 443 ), true ) ) {
		return new WP_Error( 'fmg_bad_url', 'The scan covers only standard web ports.', array( 'status' => 400 ) );
	}

	$host = strtolower( $parts['host'] );
	$safe = $check_host ? fmg_scan_host_is_public( $host ) : true;
	if ( is_wp_error( $safe ) ) {
		return $safe;
	}

	$path  = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
	$query = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';
	return $scheme . '://' . $host . $path . $query;
}

/**
 * Confirm a hostname resolves only to public addresses.
 *
 * @param string $host Hostname.
 * @return true|WP_Error
 */
function fmg_scan_host_is_public( $host ) {
	$blocked = array( 'localhost', 'localhost.localdomain', 'broadcasthost' );
	if ( in_array( $host, $blocked, true ) || substr( $host, -6 ) === '.local' ) {
		return new WP_Error( 'fmg_bad_host', 'This address is not reachable from the public web.', array( 'status' => 400 ) );
	}

	$ips = array();
	if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
		$ips[] = $host;
	} else {
		$v4 = gethostbynamel( $host );
		if ( is_array( $v4 ) ) {
			$ips = $v4;
		}
		$v6 = @dns_get_record( $host, DNS_AAAA );
		if ( is_array( $v6 ) ) {
			foreach ( $v6 as $record ) {
				if ( ! empty( $record['ipv6'] ) ) {
					$ips[] = $record['ipv6'];
				}
			}
		}
	}

	if ( empty( $ips ) ) {
		return new WP_Error( 'fmg_bad_host', 'This domain does not resolve. Check the spelling and try again.', array( 'status' => 400 ) );
	}

	$flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
	if ( defined( 'FILTER_FLAG_GLOBAL_RANGE' ) ) {
		$flags |= FILTER_FLAG_GLOBAL_RANGE;
	}
	foreach ( $ips as $ip ) {
		$public = filter_var( $ip, FILTER_VALIDATE_IP, $flags );
		if ( false === $public || fmg_scan_ip_is_special( $ip ) ) {
			return new WP_Error( 'fmg_bad_host', 'This address points somewhere private. The scan reads only the public web.', array( 'status' => 400 ) );
		}
	}

	fmg_scan_pinned_ip( $host, $ips[0] );
	return true;
}

/**
 * Remember, or look up, the address a host was validated against.
 *
 * @param string      $host Hostname.
 * @param string|null $ip   Address to record; omit to read.
 * @return string|null
 */
function fmg_scan_pinned_ip( $host, $ip = null ) {
	static $pins = array();
	if ( null !== $ip ) {
		$pins[ $host ] = $ip;
	}
	return isset( $pins[ $host ] ) ? $pins[ $host ] : null;
}

/**
 * Connect scanner requests to the address that passed validation. Without this,
 * cURL resolves the host again, and a DNS server that answers public for the
 * check and private for the connect (rebinding) would reach internal hosts.
 * A host the scanner never validated is sent to a name that cannot resolve.
 *
 * @param resource|CurlHandle $handle cURL handle.
 * @param array               $args   Request arguments.
 */
function fmg_scan_pin_resolve( $handle, $args ) {
	if ( empty( $args['fmg_scan'] ) ) {
		return;
	}
	$url  = (string) curl_getinfo( $handle, CURLINFO_EFFECTIVE_URL );
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	if ( '' === $host || filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return;
	}
	$ip = fmg_scan_pinned_ip( $host );
	if ( null === $ip && true === fmg_scan_host_is_public( $host ) ) {
		$ip = fmg_scan_pinned_ip( $host );
	}
	if ( null === $ip ) {
		curl_setopt( $handle, CURLOPT_URL, 'http://blocked.invalid/' );
		return;
	}
	$port = (int) wp_parse_url( $url, PHP_URL_PORT );
	if ( ! $port ) {
		$port = 'http' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ? 80 : 443;
	}
	$addr = false !== strpos( $ip, ':' ) ? '[' . $ip . ']' : $ip;
	curl_setopt( $handle, CURLOPT_RESOLVE, array( $host . ':' . $port . ':' . $addr ) );
}
add_action( 'http_api_curl', 'fmg_scan_pin_resolve', 10, 2 );

/**
 * The pin above exists only on the cURL transport. Where cURL is missing,
 * refuse scanner requests rather than fetch without it.
 *
 * @param false|array|WP_Error $pre  Short-circuit value.
 * @param array                $args Request arguments.
 * @return false|array|WP_Error
 */
function fmg_scan_require_curl( $pre, $args ) {
	if ( ! empty( $args['fmg_scan'] ) && ! WpOrg\Requests\Transport\Curl::test() ) {
		return new WP_Error( 'fmg_no_curl', 'The scanner needs cURL on this server.' );
	}
	return $pre;
}
add_filter( 'pre_http_request', 'fmg_scan_require_curl', 10, 2 );

/**
 * Ranges the PHP filter flags leave open on older versions: CGNAT, benchmarking,
 * IETF protocol assignments, 6to4 and NAT64 embeddings.
 */
function fmg_scan_ip_is_special( $ip ) {
	if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
		$long = ip2long( $ip );
		$ranges = array( array( '100.64.0.0', 10 ), array( '198.18.0.0', 15 ), array( '192.0.0.0', 24 ) );
		foreach ( $ranges as $r ) {
			$mask = -1 << ( 32 - $r[1] );
			if ( ( $long & $mask ) === ( ip2long( $r[0] ) & $mask ) ) {
				return true;
			}
		}
		return false;
	}
	$lower = strtolower( $ip );
	return 0 === strpos( $lower, '2002:' ) || 0 === strpos( $lower, '64:ff9b:' );
}

/**
 * Resolve a possibly relative URL against a base (RFC 3986 style: dot segments,
 * query-only and fragment-only references, directory bases).
 *
 * @param string $target Target.
 * @param string $base   Base URL.
 * @return string
 */
function fmg_scan_absolutize( $target, $base ) {
	$target = trim( $target );
	if ( preg_match( '#^https?://#i', $target ) ) {
		return $target;
	}
	$parts  = wp_parse_url( $base );
	if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return $target;
	}
	$origin = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );
	$bpath  = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';

	if ( 0 === strpos( $target, '//' ) ) {
		return strtolower( $parts['scheme'] ) . ':' . $target;
	}
	if ( '' === $target || '#' === $target[0] ) {
		return $origin . $bpath . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}
	if ( '?' === $target[0] ) {
		return $origin . $bpath . $target;
	}
	if ( '/' === $target[0] ) {
		$path = $target;
	} else {
		$dir  = '/' === substr( $bpath, -1 ) ? $bpath : substr( $bpath, 0, strrpos( $bpath, '/' ) + 1 );
		$path = $dir . $target;
	}
	// Remove dot segments.
	$q = '';
	if ( false !== strpos( $path, '?' ) ) {
		list( $path, $q ) = explode( '?', $path, 2 );
		$q = '?' . $q;
	}
	$out = array();
	foreach ( explode( '/', $path ) as $seg ) {
		if ( '..' === $seg ) {
			array_pop( $out );
		} elseif ( '.' !== $seg ) {
			$out[] = $seg;
		}
	}
	$path = implode( '/', $out );
	if ( '' === $path || '/' !== $path[0] ) {
		$path = '/' . $path;
	}
	return $origin . $path . $q;
}

/**
 * Per-hop timeout: the hop's own ceiling, or whatever remains before the deadline.
 *
 * @param int   $ceiling  Seconds one hop may take.
 * @param float $deadline Wall-clock time (microtime); 0 for none.
 * @return float Seconds; 0 or less when the deadline has passed.
 */
function fmg_scan_hop_timeout( $ceiling, $deadline ) {
	if ( ! $deadline ) {
		return $ceiling;
	}
	$left = $deadline - microtime( true );
	return $left < 1 ? 0 : min( $ceiling, $left );
}

/**
 * Fetch a URL, following redirects one hop at a time and revalidating every hop.
 *
 * @param string $url      Starting URL.
 * @param string $ua       User agent to send.
 * @param int    $max_hops Redirect ceiling (Google follows up to 10).
 * @param float  $deadline Wall-clock time (microtime) no hop may run past; 0 for none.
 * @return array|WP_Error  array( body, status, headers, header_bytes, final_url, hops, seconds, capped )
 */
function fmg_scan_fetch( $url, $ua = FMG_SCAN_UA, $max_hops = 10, $deadline = 0 ) {
	$hops  = 0;
	$start = microtime( true );

	for ( $i = 0; $i <= $max_hops; $i++ ) {
		$timeout = fmg_scan_hop_timeout( 12, $deadline );
		if ( $timeout <= 0 ) {
			return new WP_Error( 'fmg_timeout', 'This scan ran out of time following the address.', array( 'status' => 504 ) );
		}
		$response = wp_remote_get(
			$url,
			array(
				'fmg_scan'            => true,
				'timeout'             => $timeout,
				'redirection'         => 0,
				'user-agent'          => $ua,
				'headers'             => array(
					'Accept'          => 'text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.8',
					// The WordPress HTTP API decodes gzip and deflate but not Brotli;
					// advertising br risks a body the transport cannot inflate.
					'Accept-Encoding' => 'gzip, deflate',
				),
				// The response is capped at the transport layer; without this the
				// full body lands in memory before the substr below can trim it.
				'limit_response_size' => FMG_SCAN_MAX_BYTES + 1,
				'reject_unsafe_urls'  => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( in_array( $status, array( 301, 302, 303, 307, 308 ), true ) ) {
			$location = fmg_scan_location( $response );
			if ( empty( $location ) ) {
				return new WP_Error( 'fmg_redirect_blank', sprintf( 'This address answers with a %d redirect that points nowhere.', $status ), array( 'status' => 400 ) );
			}

			$next = fmg_scan_absolutize( $location, $url );
			$next = fmg_scan_normalize_url( $next );
			if ( is_wp_error( $next ) ) {
				return $next;
			}
			if ( $next === $url ) {
				return new WP_Error( 'fmg_redirect_loop', 'This address redirects to itself.', array( 'status' => 400 ) );
			}

			$url = $next;
			$hops++;
			continue;
		}

		$body   = (string) wp_remote_retrieve_body( $response );
		$capped = strlen( $body ) > FMG_SCAN_MAX_BYTES;
		if ( $capped ) {
			$body = substr( $body, 0, FMG_SCAN_MAX_BYTES );
		}

		return array(
			'body'         => $body,
			'status'       => $status,
			'headers'      => fmg_scan_headers_array( $response ),
			'header_bytes' => fmg_scan_header_bytes( $response ),
			'final_url'    => $url,
			'hops'         => $hops,
			'seconds'      => microtime( true ) - $start,
			'capped'       => $capped,
		);
	}

	return new WP_Error( 'fmg_redirects', 'This address redirects more than ten times. Google stops following after ten hops.', array( 'status' => 400 ) );
}

/**
 * Flatten response headers to a lowercase-keyed array of strings.
 */
function fmg_scan_headers_array( $response ) {
	$out     = array();
	$headers = wp_remote_retrieve_headers( $response );
	$all     = is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers;
	foreach ( (array) $all as $name => $value ) {
		$out[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
	}
	return $out;
}

/**
 * The Location header as one string. A repeated header comes back as an
 * array; the first value is the one browsers follow.
 */
function fmg_scan_location( $response ) {
	$location = wp_remote_retrieve_header( $response, 'location' );
	return trim( (string) ( is_array( $location ) ? reset( $location ) : $location ) );
}

/**
 * Approximate size of the response headers in bytes.
 */
function fmg_scan_header_bytes( $response ) {
	$bytes = 0;
	foreach ( fmg_scan_headers_array( $response ) as $name => $value ) {
		$bytes += strlen( $name ) + strlen( $value ) + 4;
	}
	return $bytes;
}

/**
 * Fetch a plain text resource.
 *
 * @param string $url         URL.
 * @param int    $limit       Byte cap.
 * @param int    $redirects   Redirect hops allowed.
 * @param float  $deadline    Wall-clock time (microtime) no hop may run past; 0 for none.
 * @return array array( status, body, error ) with body '' when absent or HTML.
 */
function fmg_scan_fetch_text( $url, $limit = 262144, $redirects = 2, $deadline = 0 ) {
	// Redirects are followed here, one hop at a time, so every hop passes the
	// same host validation and address pinning as the page fetch.
	for ( $i = 0; ; $i++ ) {
		$timeout = fmg_scan_hop_timeout( 8, $deadline );
		if ( $timeout <= 0 ) {
			return array( 'status' => 0, 'body' => '', 'error' => 'timeout' );
		}
		$response = wp_remote_get(
			$url,
			array(
				'fmg_scan'            => true,
				'timeout'             => $timeout,
				'redirection'         => 0,
				'user-agent'          => FMG_SCAN_UA,
				'limit_response_size' => $limit,
				'reject_unsafe_urls'  => true,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'status' => 0, 'body' => '', 'error' => $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( ! in_array( $status, array( 301, 302, 303, 307, 308 ), true ) ) {
			break;
		}
		// Past the hop limit, or a redirect to nowhere or to itself: report it the
		// way the robots.txt reader expects (status 0, an error naming the redirect).
		$location = fmg_scan_location( $response );
		if ( $i >= $redirects || '' === $location ) {
			return array( 'status' => 0, 'body' => '', 'error' => 'Too many redirects' );
		}
		$next = fmg_scan_normalize_url( fmg_scan_absolutize( $location, $url ) );
		if ( is_wp_error( $next ) ) {
			return array( 'status' => 0, 'body' => '', 'error' => $next->get_error_message() );
		}
		if ( $next === $url ) {
			return array( 'status' => 0, 'body' => '', 'error' => 'Too many redirects' );
		}
		$url = $next;
	}
	if ( 200 !== $status ) {
		return array( 'status' => $status, 'body' => '', 'error' => '' );
	}

	$type = wp_remote_retrieve_header( $response, 'content-type' );
	$body = substr( (string) wp_remote_retrieve_body( $response ), 0, $limit );
	if ( is_string( $type ) && false !== stripos( $type, 'text/html' ) && preg_match( '/<(html|body|head)\b/i', $body ) ) {
		return array( 'status' => $status, 'body' => '', 'error' => 'html' );
	}

	return array( 'status' => $status, 'body' => $body, 'error' => '' );
}

/*
|--------------------------------------------------------------------------
| Page model: parsing, tokens, region, dates, robots, fingerprints, page types
|--------------------------------------------------------------------------
*/

/**
 * Parse HTML into a DOMDocument.
 */
function fmg_scan_parse_html( $html ) {
	if ( ! class_exists( 'DOMDocument' ) || '' === trim( $html ) ) {
		return null;
	}
	$previous = libxml_use_internal_errors( true );
	$dom      = new DOMDocument();
	$flags    = defined( 'LIBXML_PARSEHUGE' ) ? LIBXML_PARSEHUGE : 0;
	$loaded   = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, $flags );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	return $loaded ? $dom : null;
}

/**
 * Pull every JSON-LD node, flattening @graph and walking nested objects.
 * CDATA and comment wrappers are stripped, control characters inside strings
 * are escaped, and IRI @type values are reduced to their last segment.
 *
 * @return array array( 'nodes' => array, 'errors' => int )
 */
function fmg_scan_extract_jsonld( $dom ) {
	$nodes  = array();
	$errors = 0;
	if ( ! $dom ) {
		return array( 'nodes' => $nodes, 'errors' => 0 );
	}
	$xpath   = new DOMXPath( $dom );
	$scripts = $xpath->query( '//script[translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="application/ld+json"]' );
	foreach ( $scripts as $script ) {
		$raw = trim( $script->textContent );
		$raw = preg_replace( '/^\s*<!--|-->\s*$/', '', $raw );
		$raw = preg_replace( '/^\s*\/\/\s*<!\[CDATA\[|\/\/\s*\]\]>\s*$/', '', $raw );
		$raw = preg_replace( '/^\s*<!\[CDATA\[|\]\]>\s*$/', '', $raw );
		$raw = preg_replace_callback( '/"(?:[^"\\\\]|\\\\.)*"/s', function ( $m ) {
			return preg_replace( '/[\x00-\x1F]/', ' ', $m[0] );
		}, trim( $raw ) );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			// One more try: a trailing comma before } or ].
			$data = json_decode( preg_replace( '/,\s*([}\]])/', '$1', $raw ), true );
		}
		if ( ! is_array( $data ) ) {
			$errors++;
			continue;
		}
		fmg_scan_collect_nodes( $data, $nodes, 0 );
	}
	return array( 'nodes' => $nodes, 'errors' => $errors );
}

/**
 * Collect typed objects from a JSON-LD structure, top level first.
 */
function fmg_scan_collect_nodes( $data, &$nodes, $depth ) {
	if ( ! is_array( $data ) || $depth > 6 ) {
		return;
	}
	if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
		foreach ( $data['@graph'] as $item ) {
			fmg_scan_collect_nodes( $item, $nodes, $depth + 1 );
		}
		return;
	}
	if ( isset( $data[0] ) ) {
		foreach ( $data as $item ) {
			fmg_scan_collect_nodes( $item, $nodes, $depth + 1 );
		}
		return;
	}
	if ( isset( $data['@type'] ) ) {
		$data['@type'] = fmg_scan_short_types( $data['@type'] );
		$nodes[]       = $data;
	}
	foreach ( $data as $key => $value ) {
		if ( '@' === substr( (string) $key, 0, 1 ) || ! is_array( $value ) ) {
			continue;
		}
		if ( isset( $value['@type'] ) || isset( $value[0] ) ) {
			fmg_scan_collect_nodes( $value, $nodes, $depth + 1 );
		}
	}
}

/**
 * Reduce @type values to their last path segment.
 */
function fmg_scan_short_types( $types ) {
	$out = array();
	foreach ( (array) $types as $t ) {
		$t = (string) $t;
		if ( preg_match( '#[/\#]([A-Za-z0-9_]+)$#', $t, $m ) ) {
			$t = $m[1];
		}
		$out[] = $t;
	}
	return count( $out ) === 1 ? $out[0] : $out;
}

/**
 * Does one node's @type match an exact value or end with a suffix.
 */
function fmg_scan_node_is( $node, $exact, $suffixes = array() ) {
	if ( empty( $node['@type'] ) ) {
		return false;
	}
	foreach ( (array) $node['@type'] as $type ) {
		$type = (string) $type;
		if ( in_array( $type, $exact, true ) ) {
			return true;
		}
		foreach ( $suffixes as $suffix ) {
			$len = strlen( $suffix );
			if ( strlen( $type ) > $len && 0 === substr_compare( $type, $suffix, -$len ) ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Find the first node whose @type matches.
 */
function fmg_scan_find_node( $nodes, $exact, $suffixes = array() ) {
	foreach ( $nodes as $node ) {
		if ( fmg_scan_node_is( $node, $exact, $suffixes ) ) {
			return $node;
		}
	}
	return null;
}

/**
 * Resolve a JSON-LD reference ({"@id": ...}) against the page's nodes.
 */
function fmg_scan_resolve_ref( $ref, $nodes ) {
	if ( ! is_array( $ref ) ) {
		return null;
	}
	if ( isset( $ref[0] ) ) {
		$ref = $ref[0];
		if ( ! is_array( $ref ) ) {
			return null;
		}
	}
	if ( isset( $ref['@id'] ) && count( $ref ) <= 2 ) {
		foreach ( $nodes as $node ) {
			if ( isset( $node['@id'] ) && $node['@id'] === $ref['@id'] ) {
				return $node;
			}
		}
	}
	return $ref;
}

/** Content-node types: the thing the page is about, where author, publisher and dates belong. */
function fmg_scan_content_types() {
	return array(
		'exact'    => array( 'Article', 'NewsArticle', 'BlogPosting', 'ScholarlyArticle', 'TechArticle', 'Report', 'MedicalWebPage', 'HowTo', 'FAQPage', 'QAPage', 'LiveBlogPosting', 'WebPage', 'Product', 'Recipe', 'Dataset', 'Course', 'Event', 'Service', 'CreativeWork', 'VideoObject', 'ProfilePage', 'AboutPage', 'ContactPage', 'CollectionPage', 'ItemPage' ),
		'suffixes' => array( 'Article', 'Posting', 'Page' ),
	);
}

/**
 * Word tokens: letter or digit runs, with each CJK ideograph, kana or Hangul
 * syllable counted as one token.
 */
function fmg_scan_tokens( $text ) {
	if ( '' === $text ) {
		return 0;
	}
	$cjk  = '/[\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}\x{F900}-\x{FAFF}]/u';
	$n    = preg_match_all( $cjk, $text );
	$rest = preg_replace( $cjk, ' ', $text );
	$w    = preg_match_all( '/[\p{L}\p{N}]+/u', $rest );
	return (int) $n + (int) $w;
}

/**
 * Text of a node with text nodes joined by single spaces, whitespace collapsed.
 */
function fmg_scan_node_text( $node ) {
	$parts = array();
	$doc   = $node instanceof DOMDocument ? $node : $node->ownerDocument;
	$xp    = new DOMXPath( $doc );
	foreach ( $xp->query( './/text()', $node ) as $t ) {
		$v = trim( $t->nodeValue );
		if ( '' !== $v ) {
			$parts[] = $v;
		}
	}
	return trim( preg_replace( '/\s+/u', ' ', implode( ' ', $parts ) ) );
}

/**
 * Porter stemmer (English), compact implementation. Never returns a stem shorter than 3 characters when the input was 3 or longer.
 */
function fmg_scan_stem( $w ) {
	if ( strlen( $w ) < 3 ) {
		return $w;
	}
	$c = '[^aeiou]'; $v = '[aeiouy]'; $C = $c . '[^aeiouy]*'; $V = $v . '[aeiou]*';
	$mgr0 = '^(' . $C . ')?' . $V . $C; $meq1 = '^(' . $C . ')?' . $V . $C . '(' . $V . ')?$'; $mgr1 = '^(' . $C . ')?' . $V . $C . $V . $C; $s_v = '^(' . $C . ')?' . $v;
	$orig = $w;
	if ( 'y' === $w[0] ) { $w = 'Y' . substr( $w, 1 ); }
	// Step 1a
	if ( preg_match( '/^(.+?)(ss|i)es$/', $w, $m ) ) { $w = $m[1] . $m[2]; }
	elseif ( preg_match( '/^(.+?)([^s])s$/', $w, $m ) ) { $w = $m[1] . $m[2]; }
	// Step 1b
	if ( preg_match( '/^(.+?)eed$/', $w, $m ) ) { if ( preg_match( '/' . $mgr0 . '/', $m[1] ) ) { $w = substr( $w, 0, -1 ); } }
	elseif ( preg_match( '/^(.+?)(ed|ing)$/', $w, $m ) ) {
		$stem = $m[1];
		if ( preg_match( '/' . $s_v . '/', $stem ) ) {
			$w = $stem;
			if ( preg_match( '/(at|bl|iz)$/', $w ) ) { $w .= 'e'; }
			elseif ( preg_match( '/([^aeiouylsz])\1$/', $w ) ) { $w = substr( $w, 0, -1 ); }
			elseif ( preg_match( '/^' . $C . $v . '[^aeiouwxy]$/', $w ) ) { $w .= 'e'; }
		}
	}
	// Step 1c
	if ( preg_match( '/^(.+?)y$/', $w, $m ) && preg_match( '/' . $s_v . '/', $m[1] ) ) { $w = $m[1] . 'i'; }
	// Step 2
	$step2 = array( 'ational' => 'ate', 'tional' => 'tion', 'enci' => 'ence', 'anci' => 'ance', 'izer' => 'ize', 'bli' => 'ble', 'alli' => 'al', 'entli' => 'ent', 'eli' => 'e', 'ousli' => 'ous', 'ization' => 'ize', 'ation' => 'ate', 'ator' => 'ate', 'alism' => 'al', 'iveness' => 'ive', 'fulness' => 'ful', 'ousness' => 'ous', 'aliti' => 'al', 'iviti' => 'ive', 'biliti' => 'ble', 'logi' => 'log' );
	if ( preg_match( '/^(.+?)(ational|tional|enci|anci|izer|bli|alli|entli|eli|ousli|ization|ation|ator|alism|iveness|fulness|ousness|aliti|iviti|biliti|logi)$/', $w, $m ) && preg_match( '/' . $mgr0 . '/', $m[1] ) ) { $w = $m[1] . $step2[ $m[2] ]; }
	// Step 3
	$step3 = array( 'icate' => 'ic', 'ative' => '', 'alize' => 'al', 'iciti' => 'ic', 'ical' => 'ic', 'ful' => '', 'ness' => '' );
	if ( preg_match( '/^(.+?)(icate|ative|alize|iciti|ical|ful|ness)$/', $w, $m ) && preg_match( '/' . $mgr0 . '/', $m[1] ) ) { $w = $m[1] . $step3[ $m[2] ]; }
	// Step 4
	if ( preg_match( '/^(.+?)(al|ance|ence|er|ic|able|ible|ant|ement|ment|ent|ou|ism|ate|iti|ous|ive|ize)$/', $w, $m ) ) { if ( preg_match( '/' . $mgr1 . '/', $m[1] ) ) { $w = $m[1]; } }
	elseif ( preg_match( '/^(.+?)(s|t)(ion)$/', $w, $m ) && preg_match( '/' . $mgr1 . '/', $m[1] . $m[2] ) ) { $w = $m[1] . $m[2]; }
	// Step 5
	if ( preg_match( '/^(.+?)e$/', $w, $m ) ) {
		$stem = $m[1];
		if ( preg_match( '/' . $mgr1 . '/', $stem ) || ( preg_match( '/' . $meq1 . '/', $stem ) && ! preg_match( '/^' . $C . $v . '[^aeiouwxy]$/', $stem ) ) ) { $w = $stem; }
	}
	if ( preg_match( '/ll$/', $w ) && preg_match( '/' . $mgr1 . '/', $w ) ) { $w = substr( $w, 0, -1 ); }
	if ( 'Y' === $w[0] ) { $w = 'y' . substr( $w, 1 ); }
	return strlen( $w ) < 3 ? $orig : $w;
}

/**
 * Content words of a string: lowercase, stopwords out, stemmed (Latin script only).
 */
function fmg_scan_content_words( $text ) {
	static $stop = null;
	if ( null === $stop ) {
		$stop = array_flip( explode( ' ', 'a an the and or but if then else of to in on at by for with from as is are was were be been being it its this that these those there here what which who whom whose how why when where your you we our us they them their he she his her i my me not no yes do does did done can could will would should may might must shall also than more most very so too into onto over under about above below after before between through during without within up down out off again further once all any both each few other some such only own same s t just don now get got make made use used using one two three per via' ) );
	}
	$out = array();
	if ( ! preg_match_all( '/[\p{L}\p{N}]+/u', mb_strtolower( $text ), $m ) ) {
		return $out;
	}
	foreach ( $m[0] as $w ) {
		if ( mb_strlen( $w ) < 3 || isset( $stop[ $w ] ) ) {
			continue;
		}
		if ( preg_match( '/^[a-z0-9]+$/', $w ) ) {
			$w = fmg_scan_stem( $w );
		}
		$out[ $w ] = true;
	}
	return array_keys( $out );
}

/**
 * Jaccard overlap of two content-word sets.
 */
function fmg_scan_overlap( $a, $b ) {
	if ( ! $a || ! $b ) {
		return 0;
	}
	$inter = count( array_intersect( $a, $b ) );
	$union = count( array_unique( array_merge( $a, $b ) ) );
	return $union ? $inter / $union : 0;
}

/**
 * Split a block of text into sentences.
 */
function fmg_scan_sentences( $text ) {
	$parts = preg_split( '/(?<=[.!?])\s+(?=[\p{Lu}\p{N}"(\x{201C}])/u', $text );
	$out   = array();
	foreach ( (array) $parts as $s ) {
		$s = trim( $s );
		if ( '' !== $s ) {
			$out[] = $s;
		}
	}
	return $out;
}

/**
 * Registrable domain using a curated multi-label public-suffix subset.
 */
function fmg_scan_registrable( $host ) {
	static $multi = null;
	if ( null === $multi ) {
		$multi = array_flip( explode( ' ', 'co.uk org.uk ac.uk gov.uk me.uk net.uk ltd.uk plc.uk sch.uk nhs.uk police.uk com.au net.au org.au edu.au gov.au asn.au id.au co.nz org.nz net.nz govt.nz ac.nz co.jp or.jp ne.jp ac.jp go.jp ed.jp co.kr or.kr go.kr ac.kr co.za org.za gov.za ac.za com.br org.br gov.br edu.br net.br com.mx org.mx gob.mx edu.mx com.ar gov.ar edu.ar com.cn gov.cn edu.cn org.cn net.cn com.tw org.tw gov.tw edu.tw com.hk gov.hk edu.hk org.hk com.sg gov.sg edu.sg org.sg co.in org.in gov.in ac.in net.in nic.in co.il org.il gov.il ac.il com.tr gov.tr edu.tr org.tr gc.ca on.ca qc.ca bc.ca ab.ca com.ph gov.ph edu.ph co.id go.id ac.id or.id com.my gov.my edu.my org.my com.eg gov.eg edu.eg com.sa gov.sa edu.sa com.pk gov.pk edu.pk com.ng gov.ng edu.ng co.ke go.ke ac.ke com.ua gov.ua edu.ua com.vn gov.vn edu.vn github.io gitlab.io blogspot.com wordpress.com wixsite.com squarespace.com webflow.io netlify.app vercel.app pages.dev herokuapp.com azurewebsites.net cloudfront.net amazonaws.com appspot.com web.app firebaseapp.com substack.com medium.com tumblr.com ghost.io notion.site carrd.co' ) );
	}
	$host  = strtolower( (string) $host );
	$parts = explode( '.', $host );
	$n     = count( $parts );
	if ( $n >= 3 && isset( $multi[ $parts[ $n - 2 ] . '.' . $parts[ $n - 1 ] ] ) ) {
		return implode( '.', array_slice( $parts, -3 ) );
	}
	return $n >= 2 ? implode( '.', array_slice( $parts, -2 ) ) : $host;
}

/**
 * Read a meta tag (case-insensitive attribute values).
 */
function fmg_scan_meta( $ctx, $key, $attr = 'property' ) {
	if ( ! $ctx['xpath'] ) {
		return '';
	}
	$lc = 'translate(@%s, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="%s"';
	foreach ( array( $attr, 'property' === $attr ? 'name' : 'property' ) as $a ) {
		$nodes = $ctx['xpath']->query( sprintf( '//meta[' . $lc . ']/@content', $a, strtolower( $key ) ) );
		if ( $nodes && $nodes->length > 0 ) {
			return trim( $nodes->item( 0 )->nodeValue );
		}
	}
	return '';
}

/**
 * All values of a meta tag.
 */
function fmg_scan_meta_all( $ctx, $key, $attr = 'name' ) {
	$out = array();
	if ( ! $ctx['xpath'] ) {
		return $out;
	}
	$q = sprintf( '//meta[translate(@%s, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="%s"]/@content', $attr, strtolower( $key ) );
	foreach ( $ctx['xpath']->query( $q ) as $n ) {
		$out[] = trim( $n->nodeValue );
	}
	return $out;
}

/**
 * Read the document title.
 */
function fmg_scan_title( $ctx ) {
	if ( ! $ctx['dom'] ) {
		return '';
	}
	$nodes = $ctx['dom']->getElementsByTagName( 'title' );
	return $nodes->length > 0 ? trim( preg_replace( '/\s+/u', ' ', $nodes->item( 0 )->textContent ) ) : '';
}

/**
 * Strict ISO 8601 date parse. Returns a timestamp or null.
 */
function fmg_scan_parse_iso( $s ) {
	$s = trim( (string) $s );
	if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?\s*(Z|[+-]\d{2}:?\d{2})?)?$/i', $s, $m ) ) {
		return null;
	}
	if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
		return null;
	}
	$ts = gmmktime( (int) ( $m[4] ?? 0 ), (int) ( $m[5] ?? 0 ), (int) ( $m[6] ?? 0 ), (int) $m[2], (int) $m[3], (int) $m[1] );
	if ( ! empty( $m[7] ) && 'z' !== strtolower( $m[7] ) ) {
		$sign = '-' === $m[7][0] ? -1 : 1;
		$tz   = preg_replace( '/[^0-9]/', '', $m[7] );
		$ts  -= $sign * ( (int) substr( $tz, 0, 2 ) * 3600 + (int) substr( $tz, 2, 2 ) * 60 );
	}
	return $ts;
}

/**
 * Parse a human-readable date from text (English month names, ISO, dotted or slashed numerals).
 * Returns a timestamp at 00:00 UTC or null.
 */
function fmg_scan_parse_visible_date( $s, $lang = 'en' ) {
	$months = array( 'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
		'januar' => 1, 'februar' => 2, 'märz' => 3, 'mai' => 5, 'juni' => 6, 'juli' => 7, 'okt' => 10, 'dez' => 12,
		'janvier' => 1, 'février' => 2, 'mars' => 3, 'avril' => 4, 'juin' => 6, 'juillet' => 7, 'août' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'décembre' => 12,
		'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12 );
	$s = mb_strtolower( trim( $s ) );
	$y = $mo = $d = 0;
	if ( preg_match( '/(\d{4})-(\d{2})-(\d{2})/', $s, $m ) ) {
		$y = (int) $m[1]; $mo = (int) $m[2]; $d = (int) $m[3];
	} elseif ( preg_match( '/\b(\p{L}{3,})\.?\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})\b/u', $s, $m ) ) {
		$k = mb_substr( $m[1], 0, 3 ); $mo = $months[ $m[1] ] ?? ( $months[ $k ] ?? 0 ); $d = (int) $m[2]; $y = (int) $m[3];
	} elseif ( preg_match( '/\b(\d{1,2})(?:st|nd|rd|th)?\.?\s+(?:de\s+|of\s+)?(\p{L}{3,})\.?,?\s+(?:de\s+)?(\d{4})\b/u', $s, $m ) ) {
		$k = mb_substr( $m[2], 0, 3 ); $mo = $months[ $m[2] ] ?? ( $months[ $k ] ?? 0 ); $d = (int) $m[1]; $y = (int) $m[3];
	} elseif ( preg_match( '/\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b/', $s, $m ) ) {
		$d = (int) $m[1]; $mo = (int) $m[2]; $y = (int) $m[3];
	} elseif ( preg_match( '#\b(\d{1,2})/(\d{1,2})/(\d{4})\b#', $s, $m ) ) {
		// Month-first only for United States English (en, en-US); British, Irish,
		// Australian, New Zealand, South African and Indian English are day-first,
		// as are all non-English locales.
		$l  = strtolower( str_replace( '_', '-', $lang ) );
		$mf = ( 'en' === $l || 'en-us' === $l );
		if ( $mf ) { $mo = (int) $m[1]; $d = (int) $m[2]; } else { $d = (int) $m[1]; $mo = (int) $m[2]; }
		$y = (int) $m[3];
	}
	if ( $y && $mo && $d && checkdate( $mo, $d, $y ) ) {
		return gmmktime( 0, 0, 0, $mo, $d, $y );
	}
	return null;
}

/**
 * Parse robots.txt into merged groups (same-token groups combined, as Google does),
 * sitemaps, and content-signal lines. Only the fields Google documents are directives.
 */
function fmg_scan_robots_parse( $robots ) {
	$groups   = array();
	$sitemaps = array();
	$signals  = array();
	// A leading UTF-8 byte-order mark (common on files edited on Windows) must be
	// stripped, or the first User-agent line fails to parse and the whole file is
	// read as allow-all.
	if ( 0 === strncmp( $robots, "\xEF\xBB\xBF", 3 ) ) {
		$robots = substr( $robots, 3 );
	}
	if ( '' === trim( $robots ) ) {
		return array( 'groups' => $groups, 'sitemaps' => $sitemaps, 'signals' => $signals );
	}
	$agents = array();
	$open   = false;
	foreach ( preg_split( '/\r\n|\r|\n/', $robots ) as $line ) {
		$line = trim( preg_replace( '/\s*#.*$/', '', $line ) );
		if ( '' === $line || ! preg_match( '/^([a-z\-]+)\s*:\s*(.*)$/i', $line, $m ) ) {
			continue;
		}
		$field = strtolower( $m[1] );
		$value = trim( $m[2] );
		if ( 'user-agent' === $field ) {
			if ( ! $open ) {
				$agents = array();
				$open   = true;
			}
			// Google ignores version suffixes: "googlebot/1.2", "googlebot 2.1" and
			// "googlebot*" all match googlebot. The product token is the leading
			// run of letters, digits, hyphens and underscores.
			$token = strtolower( $value );
			if ( '*' !== $token ) {
				if ( preg_match( '/^[a-z0-9_-]+/', $token, $tm ) ) {
					$token = $tm[0];
				} else {
					$token = preg_replace( '#[/*\s].*$#', '', $token );
				}
			}
			$agents[] = $token;
			if ( ! isset( $groups[ $token ] ) ) {
				$groups[ $token ] = array();
			}
			continue;
		}
		if ( 'sitemap' === $field ) {
			$sitemaps[] = $value;
			continue;
		}
		if ( 'content-signal' === $field || 'content-usage' === $field ) {
			$signals[] = $field . ': ' . $value;
			continue;
		}
		if ( 'allow' === $field || 'disallow' === $field ) {
			$open = false;
			// Google's spec: a rule path must start with "/" (or a "*" wildcard).
			// A non-empty value that does neither is not a valid path and is dropped,
			// matching Google, which never expands it to a leading-slash form. An
			// empty value is kept and ignored downstream (a rule without a path).
			if ( '' !== $value && '/' !== $value[0] && '*' !== $value[0] ) {
				continue;
			}
			foreach ( $agents as $token ) {
				$groups[ $token ][] = array( 'allow' => 'allow' === $field, 'path' => $value );
			}
		}
	}
	return array( 'groups' => $groups, 'sitemaps' => $sitemaps, 'signals' => $signals );
}

/**
 * Is a crawler token allowed to fetch a path (with query) under these rules.
 * Group: the token's own group, else the first fallback token's group, else *.
 * Rule: longest matching path wins; a tie goes to allow.
 *
 * @return array array( 'allowed' => bool, 'group' => string|null )
 */
function fmg_scan_robots_allowed( $parsed, $token, $path, $fallback = array() ) {
	// Google documents that Google-CloudVertexBot follows the Googlebot group.
	// It carries no fallback of its own, so add Googlebot to the chain here.
	if ( 'google-cloudvertexbot' === strtolower( $token ) ) {
		array_unshift( $fallback, 'Googlebot' );
	}
	$want = array_merge( array( strtolower( $token ) ), array_map( 'strtolower', $fallback ), array( '*' ) );
	$pick = null;
	$used = null;
	foreach ( $want as $name ) {
		if ( isset( $parsed['groups'][ $name ] ) ) {
			$pick = $parsed['groups'][ $name ];
			$used = $name;
			break;
		}
	}
	if ( null === $pick ) {
		return array( 'allowed' => true, 'group' => null );
	}
	$best_len   = -1;
	$best_allow = true;
	foreach ( $pick as $rule ) {
		$pattern = $rule['path'];
		if ( '' === $pattern ) {
			continue;
		}
		if ( fmg_scan_robots_match( $pattern, $path ) ) {
			$len = strlen( $pattern );
			if ( $len > $best_len || ( $len === $best_len && $rule['allow'] ) ) {
				$best_len   = $len;
				$best_allow = $rule['allow'];
			}
		}
	}
	return array( 'allowed' => $best_allow, 'group' => $used );
}

/**
 * robots.txt path pattern match with * and $ support.
 */
function fmg_scan_robots_match( $pattern, $path ) {
	// Google matches on percent-decoded octets, so a rule written with a literal
	// non-ASCII path and a percent-encoded request URL (or the reverse) still
	// match. Decode both sides; * and $ are not percent-encoded and pass through.
	$path    = rawurldecode( $path );
	$anchored = '$' === substr( $pattern, -1 );
	if ( $anchored ) {
		$pattern = substr( $pattern, 0, -1 );
	}
	$pattern = rawurldecode( $pattern );
	$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . ( $anchored ? '$' : '' ) . '#';
	return (bool) preg_match( $regex, $path );
}

/**
 * Plain text of a raw HTML string with script and style removed (cheap, for fingerprints).
 */
function fmg_scan_quick_text( $html ) {
	// Transcode a non-UTF-8 body first: otherwise the /u regex below fails on
	// invalid byte sequences and returns an empty string, which would read a
	// perfectly good ISO-8859-1 page as having no text.
	if ( '' !== $html && ! mb_check_encoding( $html, 'UTF-8' ) ) {
		$enc = 'Windows-1252';
		if ( preg_match( '/<meta[^>]+charset=["\']?\s*([a-z0-9\-]+)/i', $html, $cm ) ) {
			$enc = $cm[1];
		}
		$conv = @mb_convert_encoding( $html, 'UTF-8', $enc );
		if ( false !== $conv && '' !== $conv ) {
			$html = $conv;
		}
	}
	$t = preg_replace( '#<(script|style|noscript|template)\b[^>]*>.*?</\1>#is', ' ', $html );
	$t = preg_replace( '/<!--.*?-->/s', ' ', $t );
	$t = wp_strip_all_tags( $t );
	return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( $t, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
}

/**
 * Detect a bot challenge or soft-error body. Returns a reason or ''.
 */
function fmg_scan_detect_challenge( $page ) {
	if ( is_wp_error( $page ) ) {
		return '';
	}
	$h      = $page['headers'];
	$body   = $page['body'];
	$status = (int) $page['status'];
	$text   = fmg_scan_quick_text( $body );
	$tokens = fmg_scan_tokens( $text );
	$thin   = $tokens < 80;
	if ( isset( $h['cf-mitigated'] ) && false !== stripos( $h['cf-mitigated'], 'challenge' ) ) {
		return 'Cloudflare challenge page';
	}
	if ( isset( $h['x-amzn-waf-action'] ) ) {
		return 'AWS WAF ' . $h['x-amzn-waf-action'] . ' page';
	}
	if ( ( isset( $h['x-datadome'] ) || isset( $h['x-dd-b'] ) ) && ( $status >= 400 || $thin ) ) {
		return 'DataDome challenge page';
	}
	if ( false !== strpos( $body, '_cf_chl_opt' ) || false !== stripos( $body, '<title>Just a moment...</title>' ) ) {
		return 'Cloudflare challenge page';
	}
	if ( false !== strpos( $body, 'Vercel Security Checkpoint' ) ) {
		return 'Vercel challenge page';
	}
	if ( preg_match( '/id=["\']px-captcha["\']/', $body ) || false !== stripos( $text, 'Access to this page has been denied' ) ) {
		return 'HUMAN/PerimeterX challenge page';
	}
	// Akamai adds its reference header to ordinary origin errors too, so the
	// "access denied" label requires the access-denied wording, not merely a
	// 4xx or 5xx status.
	if ( isset( $h['akamai-grn'] ) && false !== stripos( $text, 'Access Denied' ) && false !== stripos( $text, 'Reference #' ) && ( $status >= 400 || $thin ) ) {
		return 'Akamai access denied page';
	}
	$title = '';
	if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $body, $m ) ) {
		$title = strtolower( trim( wp_strip_all_tags( $m[1] ) ) );
	}
	$h1 = '';
	if ( preg_match( '/<h1[^>]*>(.*?)<\/h1>/is', $body, $m ) ) {
		$h1 = strtolower( trim( wp_strip_all_tags( $m[1] ) ) );
	}
	if ( $thin && preg_match( '/(access denied|verify (that )?you are (a )?human|checking your browser|attention required|request blocked|bot detection|are you a robot|client challenge|enable javascript and cookies|you need to enable javascript to run this app)/i', $text )
		&& ( $status < 400 || preg_match( '/(denied|moment|attention|challenge|blocked|robot)/', $title ) ) ) {
		return 'access-denied or enable-JavaScript message served as the page';
	}
	// Soft 404: a "page not found" body in the title or the H1, served with a
	// 200 success status (not a redirect).
	if ( $thin && 200 === $status && preg_match( '/(page not found|not found|404|page doesn.t exist)/', $title . ' ' . $h1 ) ) {
		return 'a "page not found" body served with a success status (soft 404)';
	}
	return '';
}

/**
 * Detect a client-rendered app shell by framework fingerprint (name only; the gate is the token count).
 */
function fmg_scan_detect_app_shell( $html ) {
	if ( preg_match( '/<div[^>]+id=["\']__next["\'][^>]*>/i', $html ) || false !== strpos( $html, '__NEXT_DATA__' ) || false !== strpos( $html, 'self.__next_f' ) ) {
		return 'Next.js';
	}
	if ( preg_match( '/<div[^>]+id=["\']__nuxt["\'][^>]*>/i', $html ) || false !== strpos( $html, '__NUXT_DATA__' ) || false !== strpos( $html, '__NUXT__' ) ) {
		return 'Nuxt';
	}
	if ( preg_match( '/<app-root[^>]*>\s*<\/app-root>/i', $html ) ) {
		return 'Angular';
	}
	if ( preg_match( '/<div[^>]+id=["\'](root|app)["\'][^>]*>\s*<\/div>/i', $html ) || false !== stripos( $html, 'You need to enable JavaScript to run this app' ) ) {
		return 'JavaScript app';
	}
	return '';
}

/**
 * Build the main region and everything the content checks need from it.
 */
function fmg_scan_main_content( $ctx ) {
	$out = array(
		'text' => '', 'tokens' => 0, 'body_tokens' => 0, 'body_bytes' => 0, 'region' => 'none', 'region_share' => 0,
		'blocks' => array(), 'headings' => array(), 'prose_tokens' => 0, 'hidden_tokens' => 0, 'hidden_share' => 0, 'hidden_present' => false,
		'nosnippet_tokens' => 0, 'links' => array(), 'lists' => 0, 'tables' => 0, 'images' => 0, 'images_alt' => 0,
		'time_els' => array(), 'byline' => null, 'tel' => false, 'address_text' => '', 'h1' => '', 'h1_count' => 0, 'question_headings' => 0, 'pdf_links' => 0,
	);
	if ( ! $ctx['dom'] ) {
		return $out;
	}
	$clone = clone $ctx['dom'];
	$xp    = new DOMXPath( $clone );
	foreach ( $xp->query( '//script | //style | //noscript | //template | //svg | //iframe' ) as $node ) {
		if ( $node->parentNode ) {
			$node->parentNode->removeChild( $node );
		}
	}
	$body = $clone->getElementsByTagName( 'body' )->item( 0 );
	if ( ! $body ) {
		return $out;
	}
	$body_text          = fmg_scan_node_text( $body );
	$out['body_tokens'] = fmg_scan_tokens( $body_text );
	$out['body_bytes']  = strlen( $body_text );

	// Count H1s across the whole body (the N_h1_count note); the H1 used for
	// L3_h1_title is chosen region-first once the region is fixed, below.
	$h1s = $xp->query( '//body//h1' );
	$out['h1_count'] = $h1s ? $h1s->length : 0;
	// tel: links and address hints anywhere in the body (local-business detection).
	$out['tel'] = $xp->query( '//a[starts-with(translate(@href, "TEL", "tel"), "tel:")]' )->length > 0;
	if ( preg_match( '/\b\d{1,5}\s+[\p{L}\.]+(?:\s+[\p{L}\.]+){0,3}\s+(street|st|avenue|ave|road|rd|boulevard|blvd|drive|dr|lane|ln|way|court|ct|place|pl|square|sq|parkway|pkwy|highway|hwy|straße|strasse|rue|calle|via)\b/iu', $body_text, $m ) ) {
		$out['address_text'] = $m[0];
	}

	// Candidate regions: the one with the most tokens, if it holds at least a quarter of the body.
	$scope = null;
	$best  = 0;
	foreach ( $xp->query( '//main | //*[@role="main"] | //article' ) as $cand ) {
		$t = fmg_scan_tokens( fmg_scan_node_text( $cand ) );
		if ( $t > $best ) {
			$best  = $t;
			$scope = $cand;
		}
	}
	if ( $scope && $out['body_tokens'] > 0 && $best >= 0.25 * $out['body_tokens'] ) {
		$out['region']       = strtolower( $scope->nodeName ) . ( $scope->hasAttribute( 'role' ) ? '[role=main]' : '' );
		$out['region_share'] = $best / $out['body_tokens'];
	} else {
		$scope = $body;
		$out['region'] = 'body minus navigation, header, footer and aside';
		foreach ( $xp->query( '//nav | //header | //footer | //aside | //*[@role="navigation"] | //*[@role="banner"] | //*[@role="contentinfo"] | //*[@role="complementary"]' ) as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}
		foreach ( $xp->query( '//*[@id or @class]' ) as $el ) {
			$attr = strtolower( $el->getAttribute( 'id' ) . ' ' . $el->getAttribute( 'class' ) );
			if ( preg_match( '/(^|[\s_\-])(nav|navbar|menu|footer|sidebar|comments?|cookie|breadcrumbs?)([\s_\-]|$)/', $attr ) && $el->parentNode && ! in_array( strtolower( $el->nodeName ), array( 'body', 'html' ), true ) ) {
				$el->parentNode->removeChild( $el );
			}
		}
		$out['region_share'] = $out['body_tokens'] > 0 ? fmg_scan_tokens( fmg_scan_node_text( $body ) ) / $out['body_tokens'] : 0;
	}
	// Chrome inside the region is never content. Navigation is always removed.
	// A header or footer is page chrome (the banner/contentinfo landmark) only
	// when it is not nested inside sectioning content: a header or footer inside
	// an article, section, main or aside is that section's own heading/meta
	// block, not page chrome, so it is kept. This is where the article H1, byline
	// and time element live on a WordPress-style entry template.
	foreach ( $xp->query( './/nav', $scope ) as $node ) {
		if ( $node->parentNode && $node !== $scope ) {
			$node->parentNode->removeChild( $node );
		}
	}
	foreach ( $xp->query( './/header | .//footer | .//aside', $scope ) as $node ) {
		if ( ! $node->parentNode || $node === $scope ) {
			continue;
		}
		$landmark = true;
		$anc      = $node->parentNode;
		while ( $anc && XML_ELEMENT_NODE === $anc->nodeType && 'body' !== strtolower( $anc->nodeName ) ) {
			if ( in_array( strtolower( $anc->nodeName ), array( 'article', 'section', 'main', 'aside' ), true ) ) {
				$landmark = false;
				break;
			}
			$anc = $anc->parentNode;
		}
		if ( $landmark ) {
			$node->parentNode->removeChild( $node );
		}
	}

	// Region-first H1 for L3_h1_title: the first H1 inside the chosen region,
	// falling back to the first H1 in the body only when the region has none.
	$region_h1 = $xp->query( './/h1', $scope );
	if ( $region_h1 && $region_h1->length ) {
		$out['h1'] = fmg_scan_node_text( $region_h1->item( 0 ) );
	} elseif ( $h1s && $h1s->length ) {
		$out['h1'] = fmg_scan_node_text( $h1s->item( 0 ) );
	}

	// Hidden text: measure (outermost matches only), then remove the truly hidden
	// nodes. aria-hidden is excluded: it hides content from assistive technology
	// only, while the text is rendered on screen and present in the served HTML,
	// so a non-rendering reader sees it and it is not a legibility defect.
	$hidden_q = './/*[@hidden or contains(translate(@style, " ", ""), "display:none") or contains(concat(" ", normalize-space(@class), " "), " sr-only ") or contains(concat(" ", normalize-space(@class), " "), " visually-hidden ") or contains(concat(" ", normalize-space(@class), " "), " d-none ") or contains(concat(" ", normalize-space(@class), " "), " hidden ")]';
	$hidden_nodes = array();
	foreach ( $xp->query( $hidden_q, $scope ) as $node ) {
		$nested = false;
		foreach ( $hidden_nodes as $h ) {
			if ( fmg_scan_is_descendant( $node, $h ) ) {
				$nested = true;
				break;
			}
		}
		if ( ! $nested ) {
			$hidden_nodes[] = $node;
		}
	}
	$hidden = 0;
	foreach ( $hidden_nodes as $node ) {
		$hidden += fmg_scan_tokens( fmg_scan_node_text( $node ) );
	}
	$details = 0;
	foreach ( $xp->query( './/details', $scope ) as $node ) {
		$details += fmg_scan_tokens( fmg_scan_node_text( $node ) );
	}
	$region_tokens_before = fmg_scan_tokens( fmg_scan_node_text( $scope ) );
	$out['hidden_present'] = ( $hidden + $details ) > 0;
	$out['hidden_tokens']  = $hidden + (int) round( $details / 2 );
	$out['hidden_share']   = $region_tokens_before > 0 ? $out['hidden_tokens'] / $region_tokens_before : 0;
	foreach ( $hidden_nodes as $node ) {
		if ( $node->parentNode && $node !== $scope ) {
			$node->parentNode->removeChild( $node );
		}
	}

	// data-nosnippet (span, div, section only), outermost matches.
	$ns_nodes = array();
	foreach ( $xp->query( './/*[@data-nosnippet][self::span or self::div or self::section]', $scope ) as $node ) {
		$nested = false;
		foreach ( $ns_nodes as $h ) {
			if ( fmg_scan_is_descendant( $node, $h ) ) {
				$nested = true;
				break;
			}
		}
		if ( ! $nested ) {
			$ns_nodes[] = $node;
		}
	}
	foreach ( $ns_nodes as $node ) {
		$out['nosnippet_tokens'] += fmg_scan_tokens( fmg_scan_node_text( $node ) );
	}

	$out['text']   = fmg_scan_node_text( $scope );
	$out['tokens'] = fmg_scan_tokens( $out['text'] );

	// Ordered blocks. A block nested in another counted block is folded into its parent.
	$tags    = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'li', 'td', 'th', 'blockquote', 'pre', 'dd', 'dt', 'figcaption' );
	$prose   = array( 'p', 'blockquote', 'dd', 'figcaption' );
	$offset  = 0;
	$blocks  = array();
	foreach ( $xp->query( './/*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6 or self::p or self::li or self::td or self::th or self::blockquote or self::pre or self::dd or self::dt or self::figcaption]', $scope ) as $el ) {
		$parent = $el->parentNode;
		$nested = false;
		while ( $parent && $parent !== $scope && XML_ELEMENT_NODE === $parent->nodeType ) {
			if ( in_array( strtolower( $parent->nodeName ), $tags, true ) ) {
				$nested = true;
				break;
			}
			$parent = $parent->parentNode;
		}
		if ( $nested ) {
			continue;
		}
		$btext = fmg_scan_node_text( $el );
		if ( '' === $btext ) {
			continue;
		}
		$tag = strtolower( $el->nodeName );
		$tc  = fmg_scan_tokens( $btext );
		$blocks[] = array( 'tag' => $tag, 'text' => $btext, 'tokens' => $tc, 'offset' => $offset, 'prose' => in_array( $tag, $prose, true ) );
		$offset += $tc;
		if ( in_array( $tag, $prose, true ) ) {
			$out['prose_tokens'] += $tc;
		}
		if ( preg_match( '/^h([1-6])$/', $tag, $m ) ) {
			$level = (int) $m[1];
			if ( $el->hasAttribute( 'headingoffset' ) ) {
				$level = min( 6, $level + (int) $el->getAttribute( 'headingoffset' ) );
			}
			$out['headings'][] = array( 'level' => $level, 'text' => $btext );
			if ( '?' === substr( rtrim( $btext ), -1 ) ) {
				$out['question_headings']++;
			}
		}
	}
	$out['blocks'] = $blocks;

	foreach ( $xp->query( './/ul | .//ol', $scope ) as $list ) {
		if ( $xp->query( './li', $list )->length >= 3 ) {
			$out['lists']++;
		}
	}
	foreach ( $xp->query( './/table', $scope ) as $table ) {
		if ( $xp->query( './/tr', $table )->length >= 2 ) {
			$out['tables']++;
		}
	}
	foreach ( $xp->query( './/img', $scope ) as $img ) {
		$out['images']++;
		if ( '' !== trim( $img->getAttribute( 'alt' ) ) ) {
			$out['images_alt']++;
		}
	}
	foreach ( $xp->query( './/a[@href]', $scope ) as $a ) {
		$href = trim( $a->getAttribute( 'href' ) );
		$out['links'][] = array( 'href' => $href, 'text' => fmg_scan_node_text( $a ) );
		if ( preg_match( '/\.pdf(\?|#|$)/i', $href ) ) {
			$out['pdf_links']++;
		}
	}
	foreach ( $xp->query( './/time', $scope ) as $t ) {
		$out['time_els'][] = array( 'datetime' => trim( $t->getAttribute( 'datetime' ) ), 'text' => fmg_scan_node_text( $t ) );
	}

	// Visible byline inside the region, outside Review or Comment scopes.
	$byline_q = './/*[@rel="author" or translate(@itemprop, "AUTHOR", "author")="author" or contains(concat(" ", normalize-space(@class), " "), " author ") or contains(concat(" ", normalize-space(@class), " "), " byline ") or contains(concat(" ", normalize-space(@class), " "), " author-name ") or contains(concat(" ", normalize-space(@class), " "), " entry-author ")]';
	foreach ( $xp->query( $byline_q, $scope ) as $el ) {
		if ( fmg_scan_in_review_scope( $el, $scope ) ) {
			continue;
		}
		$name = fmg_scan_node_text( $el );
		$name = preg_replace( '/^(by|written by|author:?)\s+/iu', '', $name );
		if ( fmg_scan_name_shape_ok( $name ) ) {
			$link = 'a' === strtolower( $el->nodeName ) ? $el->getAttribute( 'href' ) : '';
			if ( '' === $link ) {
				$ln = $xp->query( './/a[@href]', $el );
				$link = $ln->length ? $ln->item( 0 )->getAttribute( 'href' ) : '';
			}
			$out['byline'] = array( 'name' => $name, 'link' => $link, 'how' => 'byline markup' );
			break;
		}
	}
	if ( null === $out['byline'] ) {
		foreach ( array_slice( $blocks, 0, 8 ) as $b ) {
			if ( preg_match( '/^(by|written by|author:?)\s+([\p{Lu}][\p{L}\'\-\.]+(?:\s+[\p{Lu}][\p{L}\'\-\.]+)+)/u', $b['text'], $m ) && fmg_scan_name_shape_ok( $m[2] ) ) {
				$out['byline'] = array( 'name' => $m[2], 'link' => '', 'how' => '"By" line' );
				break;
			}
		}
	}

	return $out;
}

/**
 * Is $node inside $ancestor.
 */
function fmg_scan_is_descendant( $node, $ancestor ) {
	$p = $node->parentNode;
	while ( $p ) {
		if ( $p === $ancestor ) {
			return true;
		}
		$p = $p->parentNode;
	}
	return false;
}

/**
 * Is an element inside a Review or Comment scope (microdata itemtype or a comments container).
 */
function fmg_scan_in_review_scope( $el, $stop ) {
	$p = $el;
	while ( $p && $p !== $stop && XML_ELEMENT_NODE === $p->nodeType ) {
		$type = strtolower( $p->getAttribute( 'itemtype' ) . ' ' . $p->getAttribute( 'class' ) . ' ' . $p->getAttribute( 'id' ) );
		if ( preg_match( '/(schema\.org\/(review|comment|useraccount))|(^|[\s_\-])(comments?|review|reviews|testimonials?)([\s_\-]|$)/', $type ) ) {
			return true;
		}
		$p = $p->parentNode;
	}
	return false;
}

/**
 * A plausible personal or organizational name: 2 to 8 tokens, not a role word.
 */
function fmg_scan_name_shape_ok( $name ) {
	$name = trim( $name );
	$n    = fmg_scan_tokens( $name );
	if ( $n < 2 || $n > 8 ) {
		return false;
	}
	return ! preg_match( '/^(admin|administrator|staff|team|editor|editorial team|guest|user|anonymous|webmaster|marketing)$/i', $name );
}

/**
 * Collect machine-readable dates from the content node, meta tags and in-region time elements.
 *
 * @return array array( 'published' => array( source => ts ), 'modified' => array( source => ts ), 'bad' => array( source ) )
 */
function fmg_scan_dates( $ctx ) {
	$published = array();
	$modified  = array();
	$bad       = array();
	$ct        = fmg_scan_content_types();
	$seen      = false;
	foreach ( $ctx['jsonld'] as $node ) {
		if ( ! fmg_scan_node_is( $node, $ct['exact'], $ct['suffixes'] ) ) {
			continue;
		}
		foreach ( array( 'datePublished' => 'published', 'dateModified' => 'modified' ) as $prop => $bucket ) {
			if ( ! empty( $node[ $prop ] ) && is_string( $node[ $prop ] ) ) {
				$seen = true;
				$ts   = fmg_scan_parse_iso( $node[ $prop ] );
				if ( null === $ts ) {
					$bad[] = 'schema ' . $prop . ' "' . $node[ $prop ] . '"';
				} elseif ( 'published' === $bucket ) {
					$published['schema'] = isset( $published['schema'] ) ? max( $published['schema'], $ts ) : $ts;
				} else {
					$modified['schema'] = isset( $modified['schema'] ) ? max( $modified['schema'], $ts ) : $ts;
				}
			}
		}
	}
	foreach ( array( 'article:published_time' => 'published', 'article:modified_time' => 'modified' ) as $key => $bucket ) {
		$v = fmg_scan_meta( $ctx, $key );
		if ( '' !== $v ) {
			$ts = fmg_scan_parse_iso( $v );
			if ( null === $ts ) {
				$bad[] = $key . ' "' . $v . '"';
			} elseif ( 'published' === $bucket ) {
				$published['meta'] = $ts;
			} else {
				$modified['meta'] = $ts;
			}
		}
	}
	foreach ( array( 'citation_publication_date', 'citation_date', 'citation_online_date', 'dc.date', 'dcterms.date', 'dc.date.issued', 'dcterms.issued', 'dcterms.modified' ) as $key ) {
		$v = fmg_scan_meta( $ctx, $key, 'name' );
		if ( '' !== $v ) {
			$ts = fmg_scan_parse_iso( $v );
			if ( null === $ts && preg_match( '#^(\d{4})/(\d{2})/(\d{2})$#', $v, $m ) ) {
				$ts = fmg_scan_parse_iso( $m[1] . '-' . $m[2] . '-' . $m[3] );
			}
			if ( null !== $ts ) {
				if ( false !== strpos( $key, 'modified' ) ) {
					$modified['citation'] = $ts;
				} else {
					$published['citation'] = isset( $published['citation'] ) ? max( $published['citation'], $ts ) : $ts;
				}
			}
		}
	}
	foreach ( $ctx['main']['time_els'] as $t ) {
		if ( '' === $t['datetime'] ) {
			continue;
		}
		// A time element's datetime is only a page date when it is a full
		// calendar date or date-time. Durations (PT30M), clock times (20:00),
		// week and month-only values (2026-W12, 2026-05) are valid time values
		// but not publication dates, so they are ignored rather than flagged.
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}([T\s]|$)/', trim( $t['datetime'] ) ) ) {
			continue;
		}
		$ts = fmg_scan_parse_iso( $t['datetime'] );
		if ( null === $ts ) {
			$bad[] = 'time datetime "' . $t['datetime'] . '"';
		} else {
			$modified['time'] = isset( $modified['time'] ) ? max( $modified['time'], $ts ) : $ts;
		}
	}
	return array( 'published' => $published, 'modified' => $modified, 'bad' => $bad );
}

/**
 * Objective page-type detection. Several signals are unioned so removing one cannot flip applicability.
 */
function fmg_scan_page_types( $ctx ) {
	$main   = $ctx['main'];
	$nodes  = $ctx['jsonld'];
	$path   = strtolower( (string) wp_parse_url( $ctx['url'], PHP_URL_PATH ) );
	$ogtype = strtolower( fmg_scan_meta( $ctx, 'og:type' ) );

	$product = (bool) fmg_scan_find_node( $nodes, array( 'Product', 'Offer', 'AggregateOffer', 'ProductGroup' ) ) || 'product' === $ogtype || '' !== fmg_scan_meta( $ctx, 'product:price:amount' );
	$qa      = (bool) fmg_scan_find_node( $nodes, array( 'FAQPage', 'QAPage' ) ) || $main['question_headings'] >= 3;
	$article_markup = (bool) fmg_scan_find_node( $nodes, array( 'Article', 'NewsArticle', 'BlogPosting', 'ScholarlyArticle', 'TechArticle', 'Report', 'MedicalWebPage', 'HowTo', 'LiveBlogPosting', 'Recipe' ), array( 'Article', 'Posting' ) )
		|| 'article' === $ogtype || '' !== fmg_scan_meta( $ctx, 'article:published_time' ) || '' !== fmg_scan_meta( $ctx, 'citation_title', 'name' );
	$article_visible = null !== $main['byline'] || count( $main['time_els'] ) > 0;
	$article_prose   = $main['prose_tokens'] >= 300 && ! $product;
	$article = $article_markup || $article_visible || $article_prose || $qa;

	$home  = '/' === $path || '' === $path;
	$about = (bool) fmg_scan_find_node( $nodes, array( 'AboutPage', 'ContactPage' ) ) || (bool) preg_match( '#/(about|about-us|company|who-we-are|our-team|team|imprint|impressum|contact|contact-us)/?$#', $path );
	$local = (bool) fmg_scan_find_node( $nodes, array( 'LocalBusiness' ), array( 'Business', 'Store', 'Restaurant', 'Hotel' ) ) || ( $main['tel'] && '' !== $main['address_text'] );

	return array( 'article' => $article, 'qa' => $qa, 'product' => $product, 'home' => $home, 'about' => $about, 'local' => $local );
}

/**
 * The site's own name as the page shows it: og:site_name, application-name, the title
 * suffix after a separator, a copyright holder, or a header logo alt.
 *
 * @return array array( 'name' => string, 'how' => string )
 */
function fmg_scan_site_identity( $ctx ) {
	$og = fmg_scan_meta( $ctx, 'og:site_name' );
	if ( '' !== $og ) {
		return array( 'name' => $og, 'how' => 'og:site_name' );
	}
	foreach ( array( 'application-name', 'apple-mobile-web-app-title', 'twitter:site' ) as $k ) {
		$v = fmg_scan_meta( $ctx, $k, 'name' );
		if ( '' !== $v && '@' !== $v[0] ) {
			return array( 'name' => $v, 'how' => 'meta ' . $k );
		}
	}
	$title = fmg_scan_title( $ctx );
	if ( preg_match( '/^(.*)\s+[\|\-\x{2013}\x{2014}:\x{00B7}\x{2022}]\s+([^\|\-\x{2013}\x{2014}:\x{00B7}\x{2022}]{2,60})$/u', $title, $m ) ) {
		$cand = trim( $m[2] );
		$n    = fmg_scan_tokens( $cand );
		if ( $n >= 1 && $n <= 6 && ! preg_match( '/^(home|blog|news|help|support|docs|documentation)$/i', $cand ) ) {
			return array( 'name' => $cand, 'how' => 'title suffix' );
		}
	}
	$text = fmg_scan_quick_text( $ctx['html'] );
	if ( preg_match( '/(?:©|\(c\)|copyright)\s*(?:\d{4}(?:\s*[\-\x{2013}]\s*\d{4})?)?[,.]?\s*(?:by\s+)?([\p{Lu}][^.|\n©]{1,60}?)(?:\.|\s+all rights|\s+alle rechte|\s+tous droits|\s*\||$)/iu', $text, $m ) ) {
		$cand = trim( $m[1] );
		if ( fmg_scan_tokens( $cand ) <= 8 ) {
			return array( 'name' => $cand, 'how' => 'copyright line' );
		}
	}
	if ( $ctx['xpath'] ) {
		foreach ( $ctx['xpath']->query( '//header//img/@alt | //*[contains(@class,"logo")]//img/@alt | //a[contains(@class,"logo")]/@aria-label | //a[contains(@class,"brand")]/@aria-label' ) as $n ) {
			$cand = trim( $n->nodeValue );
			if ( '' !== $cand && fmg_scan_tokens( $cand ) <= 6 && ! preg_match( '/^(logo|home|image)$/i', $cand ) ) {
				return array( 'name' => preg_replace( '/\s*(logo|home)$/i', '', $cand ), 'how' => 'header logo' );
			}
		}
	}
	return array( 'name' => '', 'how' => '' );
}

/*
|--------------------------------------------------------------------------
| The scan: fetch, states, eligibility, clusters, dials
|--------------------------------------------------------------------------
*/

/**
 * Run the full scan.
 *
 * @param string $url Normalized URL.
 * @return array|WP_Error
 */
function fmg_scan_run( $url ) {
	$t0           = microtime( true );
	$deadline     = $t0 + FMG_SCAN_BUDGET;
	$differential = null;
	$primary_desc = '';

	// Robots compliance for our own token: before fetching the page, read the entry
	// origin's robots.txt and honor a disallow that governs FernwellReadabilityBot.
	// The scanner never bypasses a robots rule aimed at its own token. The response
	// is kept and reused for the eligibility layer when the final origin is the same.
	$robots_pre  = null;
	$eparts_pre  = wp_parse_url( $url );
	$origin_pre  = ( ! empty( $eparts_pre['scheme'] ) && ! empty( $eparts_pre['host'] ) ) ? $eparts_pre['scheme'] . '://' . $eparts_pre['host'] : '';
	if ( '' !== $origin_pre ) {
		$path_pre   = ( isset( $eparts_pre['path'] ) && '' !== $eparts_pre['path'] ? $eparts_pre['path'] : '/' ) . ( isset( $eparts_pre['query'] ) ? '?' . $eparts_pre['query'] : '' );
		$robots_pre = fmg_scan_fetch_text( $origin_pre . '/robots.txt', 512000, 5, $deadline );
		if ( '' !== $robots_pre['body'] ) {
			$self = fmg_scan_robots_allowed( fmg_scan_robots_parse( $robots_pre['body'] ), 'FernwellReadabilityBot', $path_pre );
			if ( ! $self['allowed'] ) {
				return new WP_Error( 'fmg_self_disallowed', 'This site\'s robots.txt disallows our scanner (FernwellReadabilityBot) for this path, so we did not fetch the page. That is itself a finding: it tells the same crawlers keyed on other tokens nothing, but it does mean this address is closed to our reader.', array( 'status' => 403 ) );
			}
		}
	}

	$page = fmg_scan_fetch( $url, FMG_SCAN_UA, 10, $deadline );

	// A dropped connection is retried once before it is read as a refusal.
	if ( is_wp_error( $page ) && ! in_array( $page->get_error_code(), array( 'fmg_redirects', 'fmg_redirect_loop', 'fmg_redirect_blank', 'fmg_bad_url', 'fmg_bad_host', 'fmg_timeout' ), true ) ) {
		$page = fmg_scan_fetch( $url, FMG_SCAN_UA, 10, $deadline );
	}
	if ( is_wp_error( $page ) && in_array( $page->get_error_code(), array( 'fmg_redirects', 'fmg_redirect_loop', 'fmg_redirect_blank', 'fmg_bad_url', 'fmg_bad_host' ), true ) ) {
		return new WP_Error( 'fmg_unreachable', $page->get_error_message(), array( 'status' => 400 ) );
	}

	// Wall-clock budget. If the primary fetch alone spent the budget, stop here
	// rather than stack a browser probe and the optional fetches on top of it.
	if ( microtime( true ) - $t0 > FMG_SCAN_BUDGET || ( is_wp_error( $page ) && 'fmg_timeout' === $page->get_error_code() ) ) {
		return new WP_Error( 'fmg_timeout', sprintf( 'This scan passed its %d-second budget before the page finished answering our host. The page may be slow to serve automated readers; try again.', FMG_SCAN_BUDGET ), array( 'status' => 504 ) );
	}

	// Differential probe: when the scanner's own user agent is turned away,
	// challenged, or handed a page with no text, ask once more as a browser,
	// but only while enough budget remains for a second full fetch.
	$primary_bad = is_wp_error( $page ) || $page['status'] >= 400 || fmg_scan_page_is_thin( $page );
	if ( $primary_bad && ( FMG_SCAN_BUDGET - ( microtime( true ) - $t0 ) ) > 15 ) {
		$primary_desc = is_wp_error( $page ) ? 'no response' : ( $page['status'] >= 400 ? 'HTTP ' . $page['status'] : ( fmg_scan_detect_challenge( $page ) ?: 'a page with almost no text' ) );
		$alt = fmg_scan_fetch( $url, FMG_SCAN_BROWSER_UA, 10, $deadline );
		if ( ! is_wp_error( $alt ) && $alt['status'] < 400 && ! fmg_scan_page_is_thin( $alt ) ) {
			$differential = $primary_desc;
			$page         = $alt;
		}
	}

	if ( is_wp_error( $page ) ) {
		return new WP_Error(
			'fmg_unreachable',
			'This page did not answer two automated readers from our host. Check the address. If the site filters automated visitors, that is itself a finding: verified engine crawlers may be treated differently, and we cannot see that from here.',
			array( 'status' => 400 )
		);
	}

	if ( $page['status'] >= 400 ) {
		return new WP_Error(
			'fmg_unreachable',
			sprintf( 'This page returned HTTP %d to two automated readers from our host (a scanner user agent and a browser user agent). Verified engine crawlers on published IP ranges may be treated differently; we cannot see that from here.', $page['status'] ),
			array( 'status' => 400 )
		);
	}

	$html   = $page['body'];
	$final  = $page['final_url'];
	$parts  = wp_parse_url( $final );
	$origin = $parts['scheme'] . '://' . $parts['host'];
	$path_q = ( isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );

	// The entry address, kept so a same-origin redirect from a disallowed URL to an
	// allowed one can be disclosed: robots is evaluated for the final URL only.
	$eparts      = wp_parse_url( $url );
	$entry_origin = ( isset( $eparts['scheme'] ) && isset( $eparts['host'] ) ) ? $eparts['scheme'] . '://' . $eparts['host'] : '';
	$entry_path  = ( isset( $eparts['path'] ) && '' !== $eparts['path'] ? $eparts['path'] : '/' ) . ( isset( $eparts['query'] ) ? '?' . $eparts['query'] : '' );

	$dom    = fmg_scan_parse_html( $html );
	$ld     = fmg_scan_extract_jsonld( $dom );
	// Reuse the robots.txt already read for the self-token check when the final
	// origin matches the entry origin; otherwise read the final origin's file.
	$robots = ( null !== $robots_pre && $origin === $origin_pre ) ? $robots_pre : fmg_scan_fetch_text( $origin . '/robots.txt', 512000, 5, $deadline );

	$ctx = array(
		'url'          => $final,
		'origin'       => $origin,
		'host'         => $parts['host'],
		'path'         => $path_q,
		'html'         => $html,
		'dom'          => $dom,
		'xpath'        => $dom ? new DOMXPath( $dom ) : null,
		'jsonld'       => $ld['nodes'],
		'jsonld_errors'=> $ld['errors'],
		'robots'       => $robots,
		'scheme'       => $parts['scheme'],
		'hops'         => $page['hops'],
		'status'       => $page['status'],
		'headers'      => $page['headers'],
		'header_bytes' => $page['header_bytes'],
		'seconds'      => $page['seconds'],
		'capped'       => $page['capped'],
		'differential' => $differential,
		'entry_path'   => $entry_path,
		'entry_same_origin' => ( '' !== $entry_origin && $entry_origin === $origin ),
		'lang'         => '',
	);
	if ( $ctx['xpath'] ) {
		$l = $ctx['xpath']->query( '//html/@lang' );
		$ctx['lang'] = $l && $l->length ? strtolower( trim( $l->item( 0 )->nodeValue ) ) : '';
	}
	$ctx['robots_groups'] = fmg_scan_robots_parse( $robots['body'] );
	$ctx['challenge']     = fmg_scan_detect_challenge( $page );
	$ctx['main']          = fmg_scan_main_content( $ctx );
	$ctx['dates']         = fmg_scan_dates( $ctx );
	$ctx['types']         = fmg_scan_page_types( $ctx );
	$ctx['directives']    = fmg_scan_directives( $ctx );
	$ctx['canonical']     = fmg_scan_canonical( $ctx );
	$ctx['budget_left']   = FMG_SCAN_BUDGET - ( microtime( true ) - $t0 );

	// Optional fetches only while the budget allows.
	$ctx['llms']    = $ctx['budget_left'] > 20 ? fmg_scan_fetch_text( $origin . '/llms.txt', 262144, 2, $deadline ) : array( 'status' => 0, 'body' => '', 'error' => 'skipped' );
	$ctx['sitemap'] = null;
	if ( empty( $ctx['robots_groups']['sitemaps'] ) && $ctx['budget_left'] > 20 ) {
		$ctx['sitemap'] = fmg_scan_fetch_text( $origin . '/sitemap.xml', 4096, 2, $deadline );
	}

	$eligibility = fmg_scan_eligibility( $ctx );
	$unscorable  = $ctx['main']['tokens'] < FMG_SCAN_MIN_TOKENS;
	$access      = ( '' !== $ctx['challenge'] || null !== $differential || 'error' === $ctx['robots_state'] ) ? 'indeterminate' : 'ok';

	$states = array(
		'unscorable'    => $unscorable,
		'access'        => $access,
		'reasons'       => array(),
		'page_types'    => array_keys( array_filter( $ctx['types'] ) ),
	);
	if ( $unscorable ) {
		$shell = fmg_scan_detect_app_shell( $html );
		$states['reasons'][] = sprintf( 'Only %d word token(s) of main text in the served HTML%s. A crawler that does not run JavaScript cannot read what is not in the HTML: a December 2024 measurement (Vercel and MERJ) found the non-Google AI crawlers did not render pages, and Anthropic documents that its fetch tool does not run scripts. Google renders JavaScript and may see more.', $ctx['main']['tokens'], $shell ? ' (' . $shell . ' shell)' : '' );
	}
	if ( '' !== $ctx['challenge'] ) {
		$states['reasons'][] = 'Our scanner user agent received ' . $ctx['challenge'] . '. Verified engine crawlers may pass; we cannot see that from here.';
	}
	if ( null !== $differential ) {
		$states['reasons'][] = 'Our scanner user agent got ' . $differential . '; a browser user agent got the page. The scores below are read from the browser copy.';
	}
	if ( 'error' === $ctx['robots_state'] ) {
		$states['reasons'][] = 'robots.txt returned ' . ( $robots['status'] ? 'HTTP ' . $robots['status'] : 'no response' ) . '. Google documents that it stops crawling for 12 hours and then uses its cached copy; every engine state is indeterminate until the file answers.';
	}
	if ( 'elsewhere' === $ctx['canonical']['state'] ) {
		$states['reasons'][] = 'The canonical tag points to ' . $ctx['canonical']['target'] . '. Engines index and cite the canonical URL; scan that address for the page they will use.';
	}

	$legibility = null;
	$citability = null;
	if ( ! $unscorable ) {
		$legibility = array(
			'text'       => fmg_scan_cluster_text( $ctx ),
			'extraction' => fmg_scan_cluster_extraction( $ctx ),
			'structure'  => fmg_scan_cluster_structure( $ctx ),
			'dating'     => fmg_scan_cluster_dating( $ctx ),
		);
		$citability = array(
			'provenance'    => fmg_scan_cluster_provenance( $ctx ),
			'verifiability' => fmg_scan_cluster_verifiability( $ctx ),
			'answer'        => fmg_scan_cluster_answer( $ctx ),
		);
	}

	$leg_score  = $legibility ? fmg_scan_dial( $legibility ) : null;
	$cite_score = $citability ? fmg_scan_dial( $citability ) : null;

	return array(
		'url'          => $final,
		'host'         => $parts['host'],
		'spec'         => FMG_SCAN_SPEC,
		'eligibility'  => $eligibility,
		'states'       => $states,
		'legibility'   => array(
			'score'    => $leg_score,
			'band'     => null === $leg_score ? null : fmg_scan_band( $leg_score ),
			'clusters' => $legibility ?: array(),
		),
		'citability'   => array(
			'score'    => $cite_score,
			'band'     => null === $cite_score ? null : fmg_scan_cite_band( $cite_score ),
			'clusters' => $citability ?: array(),
		),
		'observations' => fmg_scan_observations( $ctx ),
		'scanned_at'   => gmdate( 'Y-m-d H:i' ) . ' UTC',
		'cached'       => false,
	);
}

/**
 * Is a fetched page a challenge, an error body, or a shell with no text.
 */
function fmg_scan_page_is_thin( $page ) {
	if ( '' !== fmg_scan_detect_challenge( $page ) ) {
		return true;
	}
	return fmg_scan_tokens( fmg_scan_quick_text( $page['body'] ) ) < FMG_SCAN_MIN_TOKENS;
}

/**
 * Robots meta and X-Robots-Tag directives, scoped by token.
 *
 * @return array token => array of directive strings ('robots' is the unscoped set)
 */
function fmg_scan_directives( $ctx ) {
	$out = array( 'robots' => array(), 'googlebot' => array(), 'bingbot' => array() );
	foreach ( array( 'robots', 'googlebot', 'bingbot' ) as $name ) {
		foreach ( fmg_scan_meta_all( $ctx, $name, 'name' ) as $v ) {
			$out[ $name ][] = strtolower( $v );
		}
	}
	if ( ! empty( $ctx['headers']['x-robots-tag'] ) ) {
		// A header value is "[user-agent:] directive(, directive)*". A user-agent
		// prefix scopes every directive that follows it in the value, until the
		// next prefix, so "googlebot: nofollow, noindex" scopes both to googlebot
		// and never leaks an unscoped noindex to the other engines.
		$scope = '';
		foreach ( explode( ',', $ctx['headers']['x-robots-tag'] ) as $part ) {
			$part = strtolower( trim( $part ) );
			if ( '' === $part ) {
				continue;
			}
			if ( preg_match( '/^([a-z0-9\-]+)\s*:\s*(.+)$/', $part, $m ) && ! preg_match( '/^(max-snippet|max-image-preview|max-video-preview|unavailable_after)$/', $m[1] ) ) {
				$scope = $m[1];
				$dir   = $m[2];
			} else {
				$dir = $part;
			}
			if ( '' === $scope ) {
				$out['robots'][] = $dir;
			} elseif ( isset( $out[ $scope ] ) ) {
				$out[ $scope ][] = $dir;
			} else {
				$out['other'][] = $scope . ': ' . $dir;
			}
		}
	}
	return $out;
}

/**
 * Does a directive set contain a token (noindex, nosnippet, etc.).
 */
function fmg_scan_has_directive( $set, $needle ) {
	$all = implode( ',', $set );
	if ( 'noindex' === $needle ) {
		return (bool) preg_match( '/(^|[\s,])(noindex|none)([\s,]|$)/', $all );
	}
	return (bool) preg_match( '/(^|[\s,])' . preg_quote( $needle, '/' ) . '([\s,:]|$)/', $all );
}

/**
 * max-snippet value across directive sets, or null.
 */
function fmg_scan_max_snippet( $set ) {
	$best = null;
	foreach ( $set as $v ) {
		if ( preg_match_all( '/max-snippet\s*:\s*(-?\d+)/', $v, $m ) ) {
			foreach ( $m[1] as $n ) {
				$n = (int) $n;
				if ( null === $best || ( $n >= 0 && ( $best < 0 || $n < $best ) ) ) {
					$best = $n;
				}
			}
		}
	}
	return $best;
}

/**
 * Canonical state: self, missing, or elsewhere. Reads the link element and the HTTP Link header.
 */
function fmg_scan_canonical( $ctx ) {
	$canonical = '';
	if ( $ctx['xpath'] ) {
		// Only a canonical inside <head> is valid; a link element in the body is ignored.
		$c = $ctx['xpath']->query( '//head//link[translate(@rel, "CANONICAL", "canonical")="canonical"]/@href' );
		if ( $c && $c->length ) {
			$canonical = trim( $c->item( 0 )->nodeValue );
		}
	}
	if ( '' === $canonical && ! empty( $ctx['headers']['link'] ) && preg_match( '/<([^>]+)>\s*;\s*rel="?canonical"?/i', $ctx['headers']['link'], $m ) ) {
		$canonical = trim( $m[1] );
	}
	if ( '' === $canonical ) {
		return array( 'state' => 'missing', 'target' => '' );
	}
	$abs  = fmg_scan_absolutize( $canonical, $ctx['url'] );
	$norm = function ( $u ) {
		$p = wp_parse_url( $u );
		if ( empty( $p['host'] ) ) {
			return '';
		}
		$host = preg_replace( '/^www\./', '', strtolower( $p['host'] ) );
		$path = isset( $p['path'] ) && '' !== $p['path'] ? rtrim( $p['path'], '/' ) : '';
		$q    = isset( $p['query'] ) ? '?' . $p['query'] : '';
		return $host . $path . $q;
	};
	return $norm( $abs ) === $norm( $ctx['url'] ) ? array( 'state' => 'self', 'target' => $abs ) : array( 'state' => 'elsewhere', 'target' => $abs );
}

/**
 * Per-engine eligibility states from each operator's documentation.
 */
function fmg_scan_eligibility( &$ctx ) {
	$robots = $ctx['robots'];
	$parsed = $ctx['robots_groups'];
	$path   = $ctx['path'];
	$d      = $ctx['directives'];

	// robots.txt fetch state per Google's spec: 4xx except 429 means no restrictions;
	// 5xx, 429, timeouts are server errors. A file that redirects past the hop limit
	// is treated as a 404 (no restrictions), and a 200 that returns HTML rather than
	// robots syntax parses to no directives, which is also allow-all.
	if ( '' !== $robots['body'] ) {
		$ctx['robots_state'] = 'ok';
	} elseif ( 200 === $robots['status'] ) {
		$ctx['robots_state'] = 'ok'; // empty file or HTML: no directives parsed, allow-all
	} elseif ( $robots['status'] >= 400 && $robots['status'] < 500 && 429 !== $robots['status'] ) {
		$ctx['robots_state'] = 'absent';
	} elseif ( 0 === $robots['status'] && false !== stripos( (string) ( $robots['error'] ?? '' ), 'redirect' ) ) {
		$ctx['robots_state'] = 'absent';
	} else {
		$ctx['robots_state'] = 'error';
	}
	$robots_err = 'error' === $ctx['robots_state'];

	$noindex_all = fmg_scan_has_directive( $d['robots'], 'noindex' );
	$noindex_g   = $noindex_all || fmg_scan_has_directive( $d['googlebot'], 'noindex' );
	$noindex_b   = $noindex_all || fmg_scan_has_directive( $d['bingbot'], 'noindex' );
	$nosnippet   = fmg_scan_has_directive( $d['robots'], 'nosnippet' ) || fmg_scan_has_directive( $d['googlebot'], 'nosnippet' );
	$maxsnip     = fmg_scan_max_snippet( array_merge( $d['robots'], $d['googlebot'] ) );
	$snip_zero   = $nosnippet || 0 === $maxsnip;
	$has_ld      = count( $ctx['jsonld'] ) > 0;
	$canon_else  = 'elsewhere' === $ctx['canonical']['state'];
	$challenge   = '' !== $ctx['challenge'] || null !== $ctx['differential'];

	$allowed = function ( $token, $fallback = array() ) use ( $parsed, $path ) {
		return fmg_scan_robots_allowed( $parsed, $token, $path, $fallback );
	};

	$rows = array();
	$add  = function ( $key, $engine, $state, $reason, $source ) use ( &$rows ) {
		$rows[] = array( 'key' => $key, 'engine' => $engine, 'state' => $state, 'reason' => $reason, 'source' => $source );
	};
	$wrap = function ( $state, $reason ) use ( $robots_err, $challenge, $canon_else ) {
		if ( 'ineligible' === $state ) {
			return array( $state, $reason );
		}
		if ( $robots_err ) {
			return array( 'indeterminate', 'robots.txt did not answer, so the crawl rule for this path is unknown.' );
		}
		if ( $challenge ) {
			return array( 'indeterminate', 'Automated visitors are challenged or turned away at this site; verified crawlers may pass. ' . $reason );
		}
		if ( $canon_else ) {
			return array( 'indeterminate', 'The canonical points to another URL; that URL is the one engines use. ' . $reason );
		}
		return array( $state, $reason );
	};

	// Google (AI Overviews, AI Mode)
	$g = $allowed( 'Googlebot' );
	if ( $noindex_g ) {
		list( $s, $r ) = $wrap( 'ineligible', 'noindex: Google requires a page to be indexed to be a supporting link in AI Overviews or AI Mode.' );
	} elseif ( $snip_zero ) {
		list( $s, $r ) = $wrap( 'ineligible', ( $nosnippet ? 'nosnippet' : 'max-snippet:0' ) . ': Google documents this prevents the content being used as a direct input for AI Overviews and AI Mode' . ( $has_ld ? ' (Google exempts content supplied as in-page structured data or under license from the max-snippet limit)' : '' ) . '.' );
	} elseif ( ! $g['allowed'] ) {
		list( $s, $r ) = $wrap( 'ineligible', 'Googlebot is disallowed for this path. A disallowed page may be indexed without a snippet, and AI Overviews and AI Mode require snippet eligibility.' );
	} else {
		list( $s, $r ) = $wrap( 'eligible', 'Googlebot allowed for this path; indexable; snippet controls permit use' . ( null !== $maxsnip && $maxsnip > 0 ? ' (max-snippet:' . $maxsnip . ' limits how much)' : '' ) . '.' );
	}
	$add( 'google', 'Google AI Overviews and AI Mode', $s, $r, 'developers.google.com: AI features and your website; robots meta tag' );

	// Gemini Apps grounding (Google-Extended)
	$ge = $allowed( 'Google-Extended' );
	list( $s, $r ) = $ge['allowed'] ? $wrap( 'eligible', 'Google-Extended is not disallowed.' ) : array( 'ineligible', 'Google-Extended is disallowed: Google documents this removes content from grounding in Gemini Apps and Vertex AI (it does not affect Search).' );
	$add( 'gemini', 'Gemini app grounding (Google-Extended)', $s, $r, 'developers.google.com: Google common crawlers' );

	// OpenAI
	$o = $allowed( 'OAI-SearchBot' );
	if ( $noindex_all ) {
		list( $s, $r ) = $wrap( 'ineligible', 'noindex: OpenAI documents the noindex meta tag as the opt-out from surfacing the page link and title in ChatGPT Atlas (readable only when the page is crawlable).' );
	} elseif ( ! $o['allowed'] ) {
		list( $s, $r ) = $wrap( 'ineligible', 'OAI-SearchBot is disallowed for this path: "will not be shown in ChatGPT search answers, though can still appear as navigational links".' );
	} else {
		list( $s, $r ) = $wrap( 'eligible', 'OAI-SearchBot allowed for this path.' );
	}
	$add( 'openai', 'ChatGPT search (OpenAI)', $s, $r, 'developers.openai.com: Overview of OpenAI Crawlers; Publishers FAQ' );

	// Microsoft Bing and Copilot
	$b = $allowed( 'bingbot' );
	if ( $noindex_b ) {
		list( $s, $r ) = $wrap( 'ineligible', 'noindex' . ( $noindex_all ? '' : ' (bingbot-scoped; Bing support for the bingbot meta name is unverified)' ) . ': the page asks not to be indexed.' );
	} elseif ( ! $b['allowed'] ) {
		list( $s, $r ) = $wrap( 'ineligible', 'bingbot is disallowed for this path. Inferred: Microsoft documents that Copilot queries the Bing search service, not that bingbot governs consumer Copilot answers.' );
	} else {
		list( $s, $r ) = $wrap( 'eligible', 'bingbot allowed for this path (Microsoft documents that Copilot queries the Bing search service).' );
	}
	$add( 'bing', 'Bing and Copilot (Microsoft)', $s, $r, 'learn.microsoft.com: manage public web access' );

	// Perplexity
	$p = $allowed( 'PerplexityBot' );
	list( $s, $r ) = $p['allowed'] ? $wrap( 'eligible', 'PerplexityBot allowed for this path.' ) : $wrap( 'undocumented', 'PerplexityBot is disallowed for this path. Perplexity documents that the bot surfaces and links sites in its results, but states no consequence for blocking it; Perplexity-User ignores robots.txt on user requests.' );
	$add( 'perplexity', 'Perplexity', $s, $r, 'docs.perplexity.ai: Perplexity crawlers' );

	// Anthropic
	$cs = $allowed( 'Claude-SearchBot' );
	$cu = $allowed( 'Claude-User' );
	if ( ! $cs['allowed'] || ! $cu['allowed'] ) {
		$which = implode( ' and ', array_filter( array( $cs['allowed'] ? '' : 'Claude-SearchBot', $cu['allowed'] ? '' : 'Claude-User' ) ) );
		list( $s, $r ) = $wrap( 'reduced', $which . ' disallowed for this path. Anthropic: blocking "may reduce your site\'s visibility" in Claude search results (a collective robots statement; no removal is documented).' );
	} else {
		list( $s, $r ) = $wrap( 'eligible', 'Claude-SearchBot and Claude-User allowed for this path.' );
	}
	$add( 'anthropic', 'Claude (Anthropic)', $s, $r, 'support.claude.com: Does Anthropic crawl data from the web' );

	// Apple
	$a = $allowed( 'Applebot', array( 'Googlebot' ) );
	if ( $noindex_all ) {
		list( $s, $r ) = $wrap( 'ineligible', 'noindex: Apple documents that Applebot will not index a page carrying the noindex robots directive.' );
	} elseif ( $nosnippet ) {
		list( $s, $r ) = $wrap( 'ineligible', 'nosnippet: Apple documents that Applebot "won\'t generate a description or web answer for the page".' );
	} elseif ( ! $a['allowed'] ) {
		list( $s, $r ) = $wrap( 'ineligible', 'Applebot is disallowed for this path' . ( 'googlebot' === $a['group'] ? ' (via the Googlebot group, which Apple documents as the fallback)' : '' ) . '. Inferred from Applebot\'s documented search purpose; Apple states no answer consequence for robots blocks.' );
	} else {
		list( $s, $r ) = $wrap( 'eligible', 'Applebot allowed for this path' . ( 'googlebot' === $a['group'] ? ' (Googlebot group, per Apple\'s documented fallback)' : '' ) . '.' );
	}
	$add( 'apple', 'Siri, Spotlight and Safari (Apple)', $s, $r, 'support.apple.com: About Applebot' );

	// DuckDuckGo
	$dd = $allowed( 'DuckAssistBot' );
	list( $s, $r ) = $dd['allowed'] ? $wrap( 'eligible', 'DuckAssistBot allowed for this path.' ) : array( 'ineligible', 'DuckAssistBot is disallowed for this path: DuckDuckGo documents this as opting out "of being a potential source for AI-assisted answers".' );
	$add( 'duckduckgo', 'DuckDuckGo AI-assisted answers', $s, $r, 'duckduckgo.com help: DuckAssistBot' );

	// Amazon Alexa
	$am = fmg_scan_robots_allowed( $parsed, 'Amzn-SearchBot', $path, array() );
	if ( null === $am['group'] || '*' === $am['group'] ) {
		$has_named = isset( $parsed['groups']['amzn-searchbot'] );
		if ( ! $has_named && count( $parsed['groups'] ) > ( isset( $parsed['groups']['*'] ) ? 1 : 0 ) ) {
			list( $s, $r ) = $wrap( 'indeterminate', 'No Amzn-SearchBot group. Amazon documents a fallback to "directives given to other search bots" without naming them, so the rule that applies is unknown.' );
		} else {
			list( $s, $r ) = $am['allowed'] ? $wrap( 'eligible', 'Amzn-SearchBot allowed for this path.' ) : $wrap( 'undocumented', 'Amzn-SearchBot is disallowed for this path. Amazon documents that allowing it makes content eligible for Alexa search; no removal statement is published.' );
		}
	} else {
		list( $s, $r ) = $am['allowed'] ? $wrap( 'eligible', 'Amzn-SearchBot allowed for this path.' ) : $wrap( 'undocumented', 'Amzn-SearchBot is disallowed for this path. Amazon documents that allowing it makes content eligible for Alexa search; no removal statement is published.' );
	}
	$add( 'amazon', 'Alexa search (Amazon)', $s, $r, 'developer.amazon.com: Amazonbot' );

	// Meta AI
	$mw = $allowed( 'meta-webindexer' );
	list( $s, $r ) = $mw['allowed'] ? $wrap( 'eligible', 'meta-webindexer allowed for this path.' ) : $wrap( 'undocumented', 'meta-webindexer is disallowed for this path. Meta documents that allowing it "helps us cite and link to your content in Meta AI\'s responses"; no removal statement is published.' );
	$add( 'meta', 'Meta AI', $s, $r, 'developers.facebook.com: Meta web crawlers' );

	// Mistral
	$mi = $allowed( 'MistralAI-Index' );
	list( $s, $r ) = $mi['allowed'] ? $wrap( 'eligible', 'MistralAI-Index allowed for this path.' ) : $wrap( 'undocumented', 'MistralAI-Index is disallowed for this path. Mistral documents that it indexes for Mistral search and does not train on the content; no consequence for blocking is published.' );
	$add( 'mistral', 'Mistral search', $s, $r, 'docs.mistral.ai: Mistral crawlers' );

	return $rows;
}

/*
|--------------------------------------------------------------------------
| Check rows, clusters, dials
|--------------------------------------------------------------------------
*/

/**
 * Build one scored check row.
 *
 * @param string     $id     Slug.
 * @param string     $label  Human label.
 * @param float|null $credit 0 to 1, or null when not applicable.
 * @param int        $weight Within-cluster weight.
 * @param string     $fix    What to do when short of full credit.
 * @param string     $detail Observed detail.
 * @return array
 */
function fmg_scan_check( $id, $label, $credit, $weight, $fix, $detail = '' ) {
	$weight = (int) $weight;
	if ( null === $credit ) {
		$state = 'na';
	} else {
		$credit = max( 0, min( 1, (float) $credit ) );
		$state  = $credit >= 1 ? 'pass' : ( $credit > 0 ? 'partial' : 'fail' );
	}
	return array(
		'id'      => $id,
		'label'   => $label,
		'state'   => $state,
		'credit'  => null === $credit ? null : round( $credit, 2 ),
		'weight'  => $weight,
		'earned'  => null === $credit ? 0 : round( $credit * $weight, 2 ),
		'present' => null !== $credit && $credit > 0,
		'fix'     => $fix,
		'detail'  => $detail,
	);
}

/**
 * A note row: weight 0, shown as observed, never scored.
 */
function fmg_scan_note( $id, $label, $present, $detail = '', $why = '' ) {
	return array(
		'id'      => $id,
		'label'   => $label,
		'state'   => 'info',
		'credit'  => null,
		'weight'  => 0,
		'earned'  => 0,
		'present' => (bool) $present,
		'fix'     => $why,
		'detail'  => $detail,
	);
}

/**
 * Assemble a cluster: credit over applicable checks, weighted.
 */
function fmg_scan_cluster( $key, $label, $blurb, $weight, $confidence, $checks ) {
	$num = 0;
	$den = 0;
	foreach ( $checks as $c ) {
		if ( 'na' === $c['state'] || 'info' === $c['state'] ) {
			continue;
		}
		$num += $c['credit'] * $c['weight'];
		$den += $c['weight'];
	}
	return array(
		'key'        => $key,
		'label'      => $label,
		'blurb'      => $blurb,
		'weight'     => (int) $weight,
		'confidence' => $confidence,
		'applicable' => $den > 0,
		'credit'     => $den > 0 ? round( $num / $den, 4 ) : null,
		'earned'     => $den > 0 ? round( $num / $den * $weight, 2 ) : 0,
		'max'        => $den > 0 ? (int) $weight : 0,
		'checks'     => $checks,
	);
}

/**
 * Dial from clusters: weighted mean of applicable cluster credits, integer.
 */
function fmg_scan_dial( $clusters ) {
	$num = 0;
	$den = 0;
	foreach ( $clusters as $c ) {
		if ( ! $c['applicable'] ) {
			continue;
		}
		$num += $c['credit'] * $c['weight'];
		$den += $c['weight'];
	}
	if ( 0 === $den ) {
		return null;
	}
	return (int) floor( 100 * $num / $den + 0.5 );
}

function fmg_scan_band( $score ) {
	if ( $score >= 70 ) {
		return array( 'label' => 'Legible', 'key' => 'legible', 'summary' => 'A machine that is allowed in can read this page: the text is in the HTML, nothing hides it, and the page has a shape and a date a parser can trust. Reading is the floor. Whether the page gives an engine something to credit is the next dial down.' );
	}
	if ( $score >= 40 ) {
		return array( 'label' => 'Partly legible', 'key' => 'partial', 'summary' => 'A machine can get at this page but has to work for it. The gaps below are the places a non-rendering reader gives up or misreads.' );
	}
	return array( 'label' => 'Hard to read', 'key' => 'invisible', 'summary' => 'A machine reading the served HTML comes away with little it can trust. This is a fixable problem, and it is where many sites start.' );
}

function fmg_scan_cite_band( $score ) {
	if ( $score >= 70 ) {
		return array( 'label' => 'Citable', 'key' => 'citable', 'summary' => 'This page carries what a citation needs: someone accountable a reader can reach, and on informational pages, sources to check and an opening a retriever can lift. This is raw material, not a forecast. Whether an engine names you depends mostly on things a crawl cannot see.' );
	}
	if ( $score >= 40 ) {
		return array( 'label' => 'Thin', 'key' => 'thin', 'summary' => 'An engine can read this page but has little on it to attribute. The gaps below are where a citation would go to someone else.' );
	}
	return array( 'label' => 'Anonymous', 'key' => 'anonymous', 'summary' => 'No accountable party a citation can point to, and little a reader could check. Readable, but nothing an engine would attribute to you by name.' );
}

/*
|--------------------------------------------------------------------------
| Legibility clusters
|--------------------------------------------------------------------------
*/

function fmg_scan_cluster_text( $ctx ) {
	$main = $ctx['main'];
	$t    = $main['tokens'];
	$credit = $t >= 120 ? 1 : ( $t >= FMG_SCAN_MIN_TOKENS ? 0.5 : 0 );
	$checks = array(
		fmg_scan_check(
			'L1_text',
			'Main text is in the served HTML',
			$credit,
			1,
			'Put the substance in the served HTML. OpenAI, Anthropic, Perplexity and Meta crawlers do not run JavaScript, and Anthropic documents that its fetch tool cannot read JavaScript-rendered pages. Google renders, so this is about every other engine.',
			$t . ' word token(s) in the main region (' . $main['region'] . ')'
		),
		fmg_scan_note( 'N_main_region', 'Main region used', 'body minus navigation, header, footer and aside' !== $main['region'], 'Read from ' . $main['region'] . ', holding ' . round( $main['region_share'] * 100 ) . '% of the body text. Google calls semantic HTML a good idea, not a requirement.' ),
		fmg_scan_note( 'N_text_ratio', 'Text-to-markup ratio', strlen( $ctx['html'] ) > 0 && $main['body_bytes'] / strlen( $ctx['html'] ) >= 0.1, ( strlen( $ctx['html'] ) > 0 ? round( $main['body_bytes'] / strlen( $ctx['html'] ) * 100, 1 ) : 0 ) . '% of the HTML is text. This mostly measures the front-end framework, so it is a note.' ),
	);
	return fmg_scan_cluster( 'text', 'Text', 'Whether the content exists in the HTML a non-rendering crawler receives.', 40, 'High', $checks );
}

function fmg_scan_cluster_extraction( $ctx ) {
	$main = $ctx['main'];
	$t    = max( 1, $main['tokens'] );

	$ns_pct = $main['nosnippet_tokens'] / $t * 100;
	$ns_credit = $ns_pct <= 0 ? 1 : ( $ns_pct <= 10 ? 0.5 : 0 );

	$hidden_credit = null;
	$hidden_detail = 'No hidden or collapsed elements in the main region';
	if ( $main['hidden_present'] ) {
		$hp = $main['hidden_share'] * 100;
		$hidden_credit = $hp <= 10 ? 1 : ( $hp <= 30 ? 0.5 : 0 );
		$hidden_detail = round( $hp ) . '% of main text is hidden or collapsed (details counted at half; Google reads collapsed DOM content, Microsoft says AI systems may not)';
	}

	$pdf_credit = null;
	$pdf_detail = 'No PDF links in the main region';
	if ( $main['pdf_links'] > 0 ) {
		$pdf_credit = ( $main['tokens'] < 120 ) ? 0 : 1;
		$pdf_detail = $main['pdf_links'] . ' PDF link(s); ' . ( $pdf_credit ? 'the page also carries its own HTML text' : 'the page has under 120 words of its own, so the PDF is the content' );
	}

	$total_bytes = strlen( $ctx['html'] ) + (int) $ctx['header_bytes'];
	$over        = $ctx['capped'] || $total_bytes >= FMG_SCAN_GOOGLE_CAP;
	$positions   = array();
	$last_ld     = strripos( $ctx['html'], 'application/ld+json' );
	if ( false !== $last_ld ) {
		$end = stripos( $ctx['html'], '</script>', $last_ld );
		$positions['structured data'] = false !== $end ? $end : $last_ld;
	}
	foreach ( array( '/<title\b/i' => 'title', '/rel=["\']?canonical/i' => 'canonical', '/name=["\']?robots/i' => 'meta robots' ) as $re => $label ) {
		if ( preg_match( $re, $ctx['html'], $m, PREG_OFFSET_CAPTURE ) ) {
			$positions[ $label ] = $m[0][1];
		}
	}
	$late = array();
	foreach ( $positions as $label => $p ) {
		if ( $p + (int) $ctx['header_bytes'] >= FMG_SCAN_GOOGLE_CAP ) {
			$late[] = $label;
		}
	}
	$bytes_credit = ( $over || $late ) ? 0 : 1;
	$bytes_detail = number_format( $total_bytes / 1024 ) . ' KB decoded, headers included' . ( $ctx['capped'] ? ' (fetch stopped at the 2 MB cap)' : '' ) . ( isset( $positions['structured data'] ) ? '; last structured data ends at byte ' . number_format( $positions['structured data'] ) : '' ) . ( $late ? '; past the cap: ' . implode( ', ', $late ) : '' );

	$checks = array(
		fmg_scan_check( 'L2_nosnippet', 'Main text is not excluded by data-nosnippet', $ns_credit, 3, 'Text inside data-nosnippet cannot be quoted or used as AI input by Google. Keep it off the answer-bearing parts of the page.', $main['nosnippet_tokens'] . ' token(s), ' . round( $ns_pct, 1 ) . '% of main text excluded' ),
		fmg_scan_check( 'L2_hidden', 'Key text is not hidden from non-rendering readers', $hidden_credit, 2, 'Much of the content sits in hidden or collapsed elements. Google reads collapsed content that is in the DOM; Microsoft says AI systems may not. Put the answer in plain view.', $hidden_detail ),
		fmg_scan_check( 'L2_pdf', 'Core content is HTML, not only a PDF', $pdf_credit, 2, 'Move the substance out of the PDF and into the page. Microsoft asks sites not to rely on PDFs for core information; keep the PDF as a download.', $pdf_detail ),
		fmg_scan_check( 'L2_bytes', 'Under Google\'s 2 MB fetch cap', $bytes_credit, 3, 'Googlebot reads the first 2 MB of a page (uncompressed, headers included) and ignores the rest. Keep the page under the cap; placing the title, canonical, meta robots and structured data high in the document is good practice but only the cap itself is scored.', $bytes_detail ),
		fmg_scan_note( 'N_alt', 'Images carry alt text', $main['images'] > 0 && $main['images_alt'] === $main['images'], $main['images'] ? $main['images_alt'] . ' of ' . $main['images'] . ' images have alt text (Microsoft: do not put key information only in images)' : 'No images in the main region' ),
	);
	return fmg_scan_cluster( 'extraction', 'Extraction', 'Whether what is in the HTML can be lifted whole: nothing excluded, hidden, stranded in a PDF, or past the byte cap.', 25, 'High', $checks );
}

function fmg_scan_cluster_structure( $ctx ) {
	$main  = $ctx['main'];
	$title = fmg_scan_title( $ctx );
	$site  = fmg_scan_meta( $ctx, 'og:site_name' );
	if ( '' !== $site ) {
		$title = preg_replace( '/\s*[\|\-\x{2013}\x{2014}:\x{00B7}\x{2022}]\s*' . preg_quote( $site, '/' ) . '\s*$/iu', '', $title );
	}
	$h1     = $main['h1'];
	$ov     = fmg_scan_overlap( fmg_scan_content_words( $title ), fmg_scan_content_words( $h1 ) );
	$h1_credit = '' === $h1 ? 0 : ( $ov >= 0.5 ? 1 : ( $ov >= 0.25 ? 0.5 : 0 ) );

	$levels_credit = null;
	$skips = 0;
	if ( count( $main['headings'] ) >= 2 ) {
		// WHATWG: a heading must be less than, equal to, or one greater than the
		// heading that precedes it. Compare each heading to its predecessor, not
		// to the deepest level seen so far.
		$prev = null;
		foreach ( $main['headings'] as $h ) {
			if ( null !== $prev && $h['level'] > $prev + 1 ) {
				$skips++;
			}
			$prev = $h['level'];
		}
		$levels_credit = 0 === $skips ? 1 : 0;
	}

	$desc = fmg_scan_meta( $ctx, 'description', 'name' );

	$stubs = 0;
	$sections = 0;
	$cur = null;
	foreach ( $main['blocks'] as $b ) {
		if ( preg_match( '/^h[2-3]$/', $b['tag'] ) ) {
			if ( null !== $cur ) {
				$sections++;
				if ( $cur < 35 ) {
					$stubs++;
				}
			}
			$cur = 0;
		} elseif ( null !== $cur ) {
			$cur += $b['tokens'];
		}
	}
	if ( null !== $cur ) {
		$sections++;
		if ( $cur < 35 ) {
			$stubs++;
		}
	}

	$checks = array(
		fmg_scan_check( 'L3_h1_title', 'A top-level heading reflects the title', $h1_credit, 3, 'Make the H1 say what the title says. Microsoft asks for an H1 that matches or closely reflects the page title; Google says headings help and that there is no ideal number of them.', '' === $h1 ? 'No H1' : round( $ov * 100 ) . '% shared content words between the first H1 and the title' ),
		fmg_scan_check( 'L3_levels', 'Heading levels do not skip', $levels_credit, 1, 'Do not jump from an H2 to an H4. The HTML standard makes a skipped level non-conforming; Google says the order does not matter to Search, so this is one point of hygiene.', count( $main['headings'] ) < 2 ? 'Fewer than two headings in the main region' : count( $main['headings'] ) . ' headings, ' . $skips . ' level skip(s)' ),
		fmg_scan_check( 'L3_meta', 'A meta description is present', '' !== $desc ? 1 : 0, 1, 'Write a meta description. Google uses it sometimes and sets no length limit.', '' !== $desc ? mb_strlen( $desc ) . ' characters' : 'Missing' ),
		fmg_scan_note( 'N_h1_count', 'Number of H1 elements', 1 === $main['h1_count'], $main['h1_count'] . ' H1 element(s) in the body. The HTML standard allows several; Google is indifferent.' ),
		fmg_scan_note( 'N_lists_tables', 'Lists and tables in the main region', $main['lists'] + $main['tables'] > 0, $main['lists'] . ' list(s), ' . $main['tables'] . ' table(s). Microsoft and Bing recommend them; controlled tests find formatting alone changes little.' ),
		fmg_scan_note( 'N_sections', 'Stub sections', $sections > 0 && 0 === $stubs, $sections ? $stubs . ' of ' . $sections . ' headed sections hold under 35 words' : 'No headed sections. Google: no requirement to break content into pieces.' ),
	);
	return fmg_scan_cluster( 'structure', 'Structure', 'Whether the page has a shape a parser can navigate.', 15, 'Medium', $checks );
}

function fmg_scan_cluster_dating( $ctx ) {
	$main  = $ctx['main'];
	$dates = $ctx['dates'];
	$applicable = $ctx['types']['article'] || count( $dates['published'] ) || count( $dates['modified'] ) || count( $dates['bad'] );

	$all_machine = array_merge( $dates['published'], $dates['modified'] );
	$newest = $all_machine ? max( $all_machine ) : null;
	$now    = time();

	$machine_credit = $applicable ? ( $newest ? 1 : 0 ) : null;
	$machine_detail = $newest ? 'Newest machine date ' . gmdate( 'Y-m-d', $newest ) . ' from ' . implode( ', ', array_keys( $all_machine ) ) : ( $dates['bad'] ? 'Dates present but not valid ISO 8601: ' . implode( '; ', array_slice( $dates['bad'], 0, 3 ) ) : 'No datePublished, dateModified, article:*_time, citation or time[datetime] date' );

	// Visible labelled date in the main region.
	$visible_ts = null;
	$visible_from = '';
	$visible_detail = 'No labelled visible date';
	$labels = 'published|posted|updated|last updated|last modified|last reviewed|reviewed|revised|effective(?: date)?|date|veröffentlicht|aktualisiert|publié|mis à jour|publicado|actualizado|pubblicato|aggiornato|gepubliceerd|bijgewerkt';
	if ( preg_match_all( '/\b(' . $labels . ')\b[:\s]*(on\s+|le\s+|el\s+|am\s+)?([^.;|]{4,40})/iu', $main['text'], $mm, PREG_SET_ORDER ) ) {
		foreach ( $mm as $m ) {
			$ts = fmg_scan_parse_visible_date( $m[3], $ctx['lang'] ?: 'en' );
			if ( null !== $ts ) {
				$visible_ts = null === $visible_ts ? $ts : max( $visible_ts, $ts );
				$visible_from = 'label';
				$visible_detail = 'Labelled date found: "' . trim( $m[0] ) . '"';
			}
		}
	}
	if ( null === $visible_ts ) {
		foreach ( $main['time_els'] as $t ) {
			if ( '' !== $t['text'] ) {
				$ts = fmg_scan_parse_visible_date( $t['text'], $ctx['lang'] ?: 'en' );
				if ( null === $ts && preg_match( '/^\d{4}-\d{2}-\d{2}([T\s]|$)/', trim( $t['datetime'] ) ) ) {
					$ts = fmg_scan_parse_iso( $t['datetime'] );
				}
				if ( null !== $ts ) {
					$visible_ts     = $ts;
					$visible_from   = 'time';
					$visible_detail = 'Visible time element: "' . $t['text'] . '"';
					break;
				}
			}
		}
	}
	$visible_credit = $applicable ? ( null !== $visible_ts ? 1 : 0 ) : null;

	// Agreement is only meaningful when the visible date and the machine date are
	// independent. A labelled date a person wrote can be cross-checked against any
	// machine date; a bare time element that supplied both the visible text and
	// the machine value is one source, so agreement is not computed for it unless
	// a machine date from a different source (schema, meta, citation) also exists.
	$independent_machine = $all_machine;
	if ( 'time' === $visible_from ) {
		unset( $independent_machine['time'] );
	}
	$agree_credit = null;
	$agree_detail = 'Needs both a visible and an independent machine date to compare';
	if ( null !== $visible_ts && $visible_from && $independent_machine ) {
		$cands = array_values( $independent_machine );
		$diff  = PHP_INT_MAX;
		foreach ( $cands as $c ) {
			$diff = min( $diff, abs( $c - $visible_ts ) );
		}
		$agree_credit = $diff <= DAY_IN_SECONDS + 1 ? 1 : 0;
		$agree_detail = $agree_credit ? 'Visible date matches a machine date' : 'Visible date ' . gmdate( 'Y-m-d', $visible_ts ) . ' does not match any machine date (' . implode( ', ', array_map( function ( $v ) { return gmdate( 'Y-m-d', $v ); }, $cands ) ) . ')';
	}

	// Future-date red flag applies to declared publication and modification dates
	// (schema, meta, citation), not to a bare time element, which may carry an
	// event or other in-content future date rather than a publication date.
	$flags = array();
	foreach ( $all_machine as $src => $ts ) {
		if ( 'time' === $src ) {
			continue;
		}
		if ( $ts > $now + DAY_IN_SECONDS ) {
			$flags[] = $src . ' date is in the future (' . gmdate( 'Y-m-d', $ts ) . ')';
		}
	}
	if ( isset( $dates['published']['schema'], $dates['modified']['schema'] ) && $dates['published']['schema'] > $dates['modified']['schema'] + 60 ) {
		$flags[] = 'datePublished is later than dateModified';
	}
	$resp_date = ! empty( $ctx['headers']['date'] ) ? strtotime( $ctx['headers']['date'] ) : $now;
	foreach ( $dates['modified'] as $src => $ts ) {
		if ( $resp_date && abs( $resp_date - $ts ) <= 600 ) {
			$flags[] = $src . ' modified date is within 10 minutes of the response time (a per-request stamp)';
		}
	}
	if ( ! empty( $ctx['headers']['last-modified'] ) ) {
		$lm = strtotime( $ctx['headers']['last-modified'] );
		if ( $lm && $resp_date && abs( $resp_date - $lm ) <= 600 && $all_machine ) {
			$flags[] = 'Last-Modified header equals the response time';
		}
	}
	if ( $dates['bad'] ) {
		$flags[] = 'a date that failed strict ISO 8601 parsing';
	}
	$clean_credit = $applicable ? ( $flags ? 0 : 1 ) : null;

	$age_note = 'No machine date to age';
	$age_present = false;
	if ( $newest ) {
		$days = (int) floor( ( $now - $newest ) / DAY_IN_SECONDS );
		$age_present = true;
		$age_note = 'Newest date is ' . $days . ' day(s) old. Age is not scored: the largest study shows citation rates flat from 30 days to 2 years, and Google lists changing a date without changing content as a negative practice.';
		if ( $ctx['types']['article'] && $days > 730 ) {
			$age_note .= ' For an article this old, the thing worth checking is whether the content is still accurate, not the date on it.';
		}
	}

	$checks = array(
		fmg_scan_check( 'L4_machine', 'A valid machine-readable date', $machine_credit, 2, 'Include datePublished and dateModified in the content schema, as full ISO 8601 dates with a time zone. Google names these as inputs to its date estimate.', $machine_detail ),
		fmg_scan_check( 'L4_visible', 'A date a reader can see, with a label', $visible_credit, 2, 'Show a labelled date (Published, Updated) in the content. Google asks for a visible, labelled date that matches the markup.', $visible_detail ),
		fmg_scan_check( 'L4_agree', 'The visible date matches the machine date', $agree_credit, 1, 'Make the date readers see and the date in the markup the same date. Google reads several date sources and asks that they agree.', $agree_detail ),
		fmg_scan_check( 'L4_clean', 'No date red flags', $clean_credit, 1, 'Remove future dates, a datePublished later than dateModified, and modified stamps that change on every request. Google and Bing both list these as practices that make dates untrustworthy.', $flags ? ucfirst( implode( '; ', $flags ) ) : 'No future dates, no per-request stamps, dates in order' ),
		fmg_scan_note( 'N_age', 'Age of the newest date', $age_present, $age_note ),
	);
	return fmg_scan_cluster( 'dating', 'Dating', 'Whether an engine can tell when this was published or changed, and trust it.', 20, 'High', $checks );
}

/*
|--------------------------------------------------------------------------
| Citability clusters
|--------------------------------------------------------------------------
*/

function fmg_scan_cluster_provenance( $ctx ) {
	$nodes = $ctx['jsonld'];
	$main  = $ctx['main'];
	$ct    = fmg_scan_content_types();

	// Named party: schema author or publisher on a content node, byline, meta author, citation_author, Organization or WebSite node, or og:site_name corroborated in the body.
	$named   = '';
	$how     = '';
	$resolve = '';
	foreach ( $nodes as $node ) {
		if ( ! fmg_scan_node_is( $node, $ct['exact'], $ct['suffixes'] ) && ! fmg_scan_node_is( $node, array( 'Organization', 'Person' ), array( 'Organization', 'Business' ) ) ) {
			continue;
		}
		foreach ( array( 'author', 'publisher', 'creator' ) as $rel ) {
			if ( empty( $node[ $rel ] ) ) {
				continue;
			}
			$who = fmg_scan_resolve_ref( $node[ $rel ], $nodes );
			$name = is_array( $who ) ? ( $who['name'] ?? '' ) : ( is_string( $node[ $rel ] ) ? $node[ $rel ] : '' );
			if ( is_string( $name ) && '' !== trim( $name ) && fmg_scan_name_shape_ok( $name ) ) {
				$named = $name;
				$how   = 'schema ' . $rel;
				if ( is_array( $who ) ) {
					foreach ( array( 'url', 'sameAs' ) as $p ) {
						$v = is_array( $who[ $p ] ?? null ) ? reset( $who[ $p ] ) : ( $who[ $p ] ?? '' );
						if ( is_string( $v ) && preg_match( '#^https?://#i', $v ) && false === strpos( $v, '#' ) && rtrim( $v, '/' ) !== rtrim( $ctx['url'], '/' ) ) {
							$resolve = $rel . '.' . $p;
							break;
						}
					}
				}
				break 2;
			}
		}
	}
	if ( '' === $named && null !== $main['byline'] ) {
		$named = $main['byline']['name'];
		$how   = 'visible ' . $main['byline']['how'];
		if ( '' !== $main['byline']['link'] && '#' !== $main['byline']['link'] ) {
			$resolve = 'byline link';
		}
	}
	if ( '' === $named ) {
		foreach ( array( 'citation_author', 'author' ) as $key ) {
			$v = fmg_scan_meta( $ctx, $key, 'name' );
			if ( '' !== $v && fmg_scan_name_shape_ok( $v ) ) {
				$named = $v;
				$how   = 'meta ' . $key;
				break;
			}
		}
	}
	$org = fmg_scan_find_node( $nodes, array( 'Organization', 'WebSite' ), array( 'Organization', 'Business' ) );
	$org_name = $org && ! empty( $org['name'] ) && is_string( $org['name'] ) ? $org['name'] : '';
	if ( '' === $named && '' !== $org_name ) {
		$named = $org_name;
		$how   = 'schema ' . implode( '/', (array) $org['@type'] );
	}
	$ident = fmg_scan_site_identity( $ctx );
	$site  = $ident['name'];
	if ( '' === $named && '' !== $site ) {
		$named = $site;
		$how   = $ident['how'];
	}

	// Resolves: author url or sameAs, byline link, ProfilePage, or an About, Contact, Team, Company or Imprint link anywhere on the page.
	$about = false;
	if ( $ctx['xpath'] ) {
		foreach ( $ctx['xpath']->query( '//a[@href]' ) as $a ) {
			$h = strtolower( $a->getAttribute( 'href' ) );
			$t = mb_strtolower( trim( $a->textContent ) );
			if ( preg_match( '#/(about|about-us|about-[a-z0-9\-]+|contact|contact-us|contact-[a-z0-9\-]+|team|our-team|company|imprint|impressum|who-we-are|people|help/contact)(/|\#|\?|$)#', $h ) || preg_match( '/^(about( us| [\p{L}\s]{1,30})?|contact( us| [\p{L}\s]{1,40})?|our team|the team|team|company|who we are|imprint|impressum|kontakt|über uns|à propos|contacto|acerca de)$/iu', $t ) ) {
				$about = true;
				break;
			}
		}
	}
	if ( '' === $resolve && ( (bool) fmg_scan_find_node( $nodes, array( 'ProfilePage' ) ) ) ) {
		$resolve = 'ProfilePage markup';
	}
	if ( '' === $resolve && $about ) {
		$resolve = 'About or Contact link';
	}

	// Identity on home, About and local pages only.
	$identity_credit = null;
	$identity_detail = 'Applies to the home page, an About or Contact page, or a local business page';
	if ( $ctx['types']['home'] || $ctx['types']['about'] || $ctx['types']['local'] ) {
		$norm = function ( $s ) {
			$s = mb_strtolower( trim( (string) $s ) );
			$s = preg_replace( '/\b(the|inc|inc\.|llc|ltd|ltd\.|limited|corp|corporation|co\.|gmbh|ag|plc|pty|pvt|s\.a\.|s\.r\.l\.|sas|sarl)\b/u', ' ', $s );
			$s = str_replace( '&', ' and ', $s );
			return trim( preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $s ) );
		};
		$name_ok = false;
		$vis = array( $site );
		if ( preg_match( '/(?:©|\(c\)|copyright)\s*(?:\d{4}(?:\s*[\-\x{2013}]\s*\d{4})?)?\s*([^.|\n]{2,80})/iu', $main['text'] . ' ' . fmg_scan_quick_text( $ctx['html'] ), $m ) ) {
			$vis[] = $m[1];
		}
		if ( $ctx['xpath'] ) {
			foreach ( $ctx['xpath']->query( '//header//img/@alt | //a[contains(@class,"logo")]//img/@alt | //*[contains(@class,"logo")]' ) as $n ) {
				$vis[] = trim( $n->nodeValue );
			}
		}
		if ( '' !== $org_name ) {
			$on = $norm( $org_name );
			foreach ( $vis as $v ) {
				$vn = $norm( $v );
				if ( '' !== $on && '' !== $vn && ( false !== strpos( $vn, $on ) || false !== strpos( $on, $vn ) ) ) {
					$name_ok = true;
					break;
				}
			}
			if ( ! $name_ok && ! empty( $org['alternateName'] ) ) {
				$an = $norm( is_array( $org['alternateName'] ) ? reset( $org['alternateName'] ) : $org['alternateName'] );
				foreach ( $vis as $v ) {
					$vn = $norm( $v );
					if ( '' !== $an && '' !== $vn && ( false !== strpos( $vn, $an ) || false !== strpos( $an, $vn ) ) ) {
						$name_ok = true;
						break;
					}
				}
			}
		}
		$same_ok = false;
		$same_as = array();
		if ( $org && ! empty( $org['sameAs'] ) ) {
			$same_as = array_values( array_filter( (array) $org['sameAs'], 'is_string' ) );
		}
		if ( $same_as ) {
			$valid = 0;
			foreach ( $same_as as $s ) {
				$h = strtolower( (string) wp_parse_url( $s, PHP_URL_HOST ) );
				$p = (string) wp_parse_url( $s, PHP_URL_PATH );
				if ( '' !== $h && preg_match( '#^https?://#i', $s ) && preg_match( '/(linkedin\.com|facebook\.com|instagram\.com|youtube\.com|x\.com|twitter\.com|threads\.net|bsky\.app|tiktok\.com|pinterest\.com|github\.com|crunchbase\.com|wikipedia\.org|wikidata\.org|wikimedia\.org|medium\.com|substack\.com|mastodon\.|glassdoor\.|yelp\.com|trustpilot\.com|g2\.com|capterra\.com|orcid\.org|scholar\.google\.com|researchgate\.net|imdb\.com|apple\.com|play\.google\.com)/', $h ) && strlen( trim( $p, '/' ) ) > 0 ) {
					$valid++;
				}
			}
			$same_ok = $valid === count( $same_as );
		}
		$local_ok = false;
		if ( $ctx['types']['local'] ) {
			$lb = fmg_scan_find_node( $nodes, array( 'LocalBusiness' ), array( 'Business', 'Store', 'Restaurant', 'Hotel' ) );
			if ( $lb && ! empty( $lb['address'] ) && $main['tel'] && '' !== $main['address_text'] ) {
				$addr = is_array( $lb['address'] ) ? ( $lb['address']['streetAddress'] ?? '' ) : (string) $lb['address'];
				$local_ok = '' !== $addr && false !== mb_stripos( $main['address_text'] . ' ' . $main['text'], mb_substr( (string) $addr, 0, 12 ) );
			}
		}
		$identity_credit = ( $name_ok ? 0.5 : 0 ) + ( ( $same_ok || $local_ok ) ? 0.5 : 0 );
		$identity_detail = ( '' !== $org_name ? 'Markup name "' . $org_name . '"' . ( $name_ok ? ' matches' : ' does not match' ) . ' the visible site name' : 'No Organization or WebSite name in markup' )
			. '; sameAs: ' . ( $same_as ? count( $same_as ) . ' link(s), ' . ( $same_ok ? 'all well-formed profile URLs' : 'not all well-formed profile URLs' ) : 'none' )
			. ( $ctx['types']['local'] ? '; local address ' . ( $local_ok ? 'matches the markup' : 'not matched to LocalBusiness markup' ) : '' );
	}

	$reviewed = false;
	$cred = false;
	foreach ( $nodes as $node ) {
		if ( ! empty( $node['reviewedBy'] ) || ! empty( $node['lastReviewed'] ) || fmg_scan_node_is( $node, array( 'MedicalWebPage' ) ) ) {
			$reviewed = true;
		}
		if ( ! empty( $node['jobTitle'] ) || ! empty( $node['hasCredential'] ) ) {
			$cred = true;
		}
	}
	$cred_text = (bool) preg_match( '/\b(reviewed by|medically reviewed|fact[- ]checked by|edited by)\s+[\p{Lu}]|\b[\p{Lu}][\p{L}\'\-]+(?:\s+[\p{Lu}][\p{L}\'\-\.]+)+,?\s+(MD|M\.D\.|PhD|Ph\.D\.|RN|R\.N\.|PE|P\.E\.|JD|J\.D\.|CPA|CFA|MSc|MBA|DDS|DVM|PharmD|Esq\.?)\b/u', $main['text'] );

	$ids = 0;
	$refs = 0;
	$idset = array();
	foreach ( $nodes as $node ) {
		if ( ! empty( $node['@id'] ) ) {
			$idset[ $node['@id'] ] = true;
			$ids++;
		}
	}
	foreach ( $nodes as $node ) {
		foreach ( $node as $k => $v ) {
			if ( '@id' === $k || ! is_array( $v ) ) {
				continue;
			}
			$cands = isset( $v[0] ) ? $v : array( $v );
			foreach ( $cands as $c ) {
				if ( is_array( $c ) && isset( $c['@id'] ) && count( $c ) <= 2 && isset( $idset[ $c['@id'] ] ) && ( $node['@id'] ?? '' ) !== $c['@id'] ) {
					$refs++;
				}
			}
		}
	}

	// Schema/visible agreement: names, headline, author, dates in markup that do not appear on the page.
	$invisible = array();
	$page_text = mb_strtolower( $main['text'] . ' ' . fmg_scan_title( $ctx ) );
	foreach ( $nodes as $node ) {
		if ( ! fmg_scan_node_is( $node, $ct['exact'], $ct['suffixes'] ) ) {
			continue;
		}
		foreach ( array( 'headline', 'name' ) as $p ) {
			if ( ! empty( $node[ $p ] ) && is_string( $node[ $p ] ) && mb_strlen( $node[ $p ] ) >= 8 && false === mb_stripos( $page_text, mb_strtolower( mb_substr( $node[ $p ], 0, 40 ) ) ) ) {
				$invisible[] = $p . ' "' . mb_substr( $node[ $p ], 0, 40 ) . '"';
			}
		}
		if ( ! empty( $node['author'] ) ) {
			$who = fmg_scan_resolve_ref( $node['author'], $nodes );
			$nm  = is_array( $who ) ? ( $who['name'] ?? '' ) : '';
			if ( is_string( $nm ) && '' !== $nm && false === mb_stripos( $page_text, mb_strtolower( $nm ) ) ) {
				$invisible[] = 'author "' . $nm . '"';
			}
		}
	}

	$checks = array(
		fmg_scan_check( 'C1_named', 'A person or organization is named as responsible', '' !== $named ? 1 : 0, 5, 'Name who stands behind the page: a person in a byline and in the schema author, or the organization as author or publisher. Google documents Person and Organization authorship; its raters look for who is responsible. A person and an organization earn the same credit.', '' !== $named ? '"' . $named . '" via ' . $how : 'No author, publisher, organization, or site name found' ),
		fmg_scan_check( 'C1_resolves', 'The responsible party leads somewhere', '' !== $resolve ? 1 : 0, 3, 'Link the author to a profile (author.url or sameAs in the schema, a linked byline, or ProfilePage markup), or link an About or Contact page. Google reads author.url and sameAs to disambiguate authors and asks whether bylines lead to more about the author.', '' !== $resolve ? 'Via ' . $resolve : 'No author link, profile page, or About/Contact link' ),
		fmg_scan_check( 'C1_identity', 'Site identity is consistent (home, About and local pages)', $identity_credit, 2, 'On the home or About page, give the Organization or WebSite markup the same name the page shows, and keep sameAs to well-formed profile URLs. Google reads the markup name, og:site_name, the title and headings together on the home page.', $identity_detail ),
		fmg_scan_note( 'N_credentials', 'Credentials or review markup', $reviewed || $cred || $cred_text, ( $reviewed ? 'reviewedBy, lastReviewed, or MedicalWebPage present' : '' ) . ( $cred ? ( $reviewed ? '; ' : '' ) . 'jobTitle or hasCredential present' : '' ) . ( $cred_text ? ( ( $reviewed || $cred ) ? '; ' : '' ) . 'credential or review wording after a personal name' : '' ) . ( ( $reviewed || $cred || $cred_text ) ? '. No engine documents using these.' : 'None. No engine documents using these.' ) ),
		fmg_scan_note( 'N_ids_sameas', 'Entity graph and sameAs', $refs > 0, $ids . ' node id(s), ' . $refs . ' cross-reference(s). Graph plumbing is undocumented by every engine and is a note.' ),
		fmg_scan_note( 'N_schema_visible', 'Structured data matches the visible text', count( $nodes ) > 0 && empty( $invisible ), $nodes ? ( $invisible ? 'In markup but not on the page: ' . implode( '; ', array_slice( $invisible, 0, 3 ) ) . '. Google: structured data must match the visible text.' : 'Marked-up names, headline and author appear on the page' ) : 'No JSON-LD on the page' ),
		fmg_scan_note( 'N_schema_parse', 'JSON-LD parses', count( $nodes ) > 0 && 0 === $ctx['jsonld_errors'], $ctx['jsonld_errors'] ? $ctx['jsonld_errors'] . ' JSON-LD block(s) failed to parse' : ( $nodes ? count( $nodes ) . ' node(s) parsed' : 'No JSON-LD' ) ),
	);
	return fmg_scan_cluster( 'provenance', 'Provenance', 'Whether a citation has a name to credit and a way to check it.', 50, 'High', $checks );
}

function fmg_scan_cluster_verifiability( $ctx ) {
	$main = $ctx['main'];
	$applicable = $ctx['types']['article'];

	$origin_reg = fmg_scan_registrable( $ctx['host'] );
	$own_hosts  = array( $origin_reg );
	$org = fmg_scan_find_node( $ctx['jsonld'], array( 'Organization', 'WebSite' ), array( 'Organization', 'Business' ) );
	if ( $org ) {
		foreach ( array_merge( (array) ( $org['url'] ?? array() ), (array) ( $org['sameAs'] ?? array() ) ) as $u ) {
			if ( is_string( $u ) ) {
				$h = (string) wp_parse_url( $u, PHP_URL_HOST );
				if ( '' !== $h ) {
					$own_hosts[] = fmg_scan_registrable( $h );
				}
			}
		}
	}
	$brand = preg_replace( '/\..*$/', '', $origin_reg );
	$noncitation = '/(^|\.)(facebook|fb|twitter|x|linkedin|instagram|youtube|youtu|pinterest|tiktok|reddit|threads|whatsapp|bsky|mastodon|t|bit|goo|tinyurl|ow|buff|lnkd|fonts\.googleapis|fonts\.gstatic|gstatic|googletagmanager|google-analytics|doubleclick|gravatar|jsdelivr|unpkg|cloudfront|maps\.google|docs\.google|drive\.google|amzn|amazon)\.(com|co|net|org|be|ly|gl|io|me|to|app|social)$/i';
	$domains = array();
	$classes = array();
	foreach ( $main['links'] as $link ) {
		$href = $link['href'];
		if ( ! preg_match( '#^https?://#i', $href ) ) {
			continue;
		}
		$host = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
		if ( '' === $host ) {
			continue;
		}
		$reg = fmg_scan_registrable( $host );
		if ( in_array( $reg, $own_hosts, true ) || ( strlen( $brand ) >= 5 && false !== strpos( $reg, $brand ) ) ) {
			continue;
		}
		if ( preg_match( $noncitation, $host ) || preg_match( '/[?&](tag|ref|affiliate|aff|utm_campaign)=/i', $href ) ) {
			continue;
		}
		$path = trim( (string) wp_parse_url( $href, PHP_URL_PATH ), '/' );
		$deep = ( '' !== $path && ! preg_match( '/^index\.(html?|php)$/i', $path ) ) || wp_parse_url( $href, PHP_URL_QUERY ) || wp_parse_url( $href, PHP_URL_FRAGMENT );
		if ( ! $deep ) {
			continue;
		}
		$domains[ $reg ] = true;
		if ( preg_match( '/(^|\.)(doi\.org|pubmed\.ncbi\.nlm\.nih\.gov|ncbi\.nlm\.nih\.gov|arxiv\.org|iso\.org|ieee\.org|w3\.org|ietf\.org|nist\.gov)$/', $host ) || preg_match( '/\.(gov|edu|int|mil|ac\.uk|gov\.uk|gc\.ca|gov\.au|edu\.au|go\.jp|gouv\.fr)$/', $host ) ) {
			$classes[ $reg ] = true;
		}
	}
	$n = count( $domains );
	$credit = $applicable ? ( $n >= 2 ? 1 : ( 1 === $n ? 0.5 : 0 ) ) : null;

	$text = $main['text'];
	$stats = 0;
	foreach ( $main['blocks'] as $b ) {
		if ( preg_match( '/^h[1-6]$/', $b['tag'] ) ) {
			continue;
		}
		foreach ( fmg_scan_sentences( $b['text'] ) as $s ) {
			if ( fmg_scan_tokens( $s ) < 6 || ! preg_match( '/\p{L}{3,}.*\p{L}{3,}/u', $s ) ) {
				continue;
			}
			$s2 = preg_replace( '/\b(19|20)\d{2}\b|\[\d+\]|\+?\d[\d\s().-]{7,}\d|\b[A-Z]{1,4}-?\d{2,}[A-Z0-9-]*\b|\b\d{5}(?:-\d{4})?\b/', ' ', $s );
			$stats += preg_match_all( '/(?:[$€£¥]\s?\d[\d,]*(?:\.\d+)?)|(?:\b\d[\d,]*(?:\.\d+)?\s?(?:%|percent|per cent|million|billion|thousand|mg|kg|g|km|mi|miles|hours?|minutes?|days?|weeks?|months?|years?|people|patients|users|customers|studies|countries))|(?:\b\d{2,}(?:\.\d+)?\b)/i', $s2 );
		}
	}
	$quotes = 0;
	$attributed = 0;
	foreach ( $main['blocks'] as $b ) {
		if ( 'blockquote' === $b['tag'] ) {
			$quotes++;
			continue;
		}
		if ( preg_match_all( '/[\x{201C}"\x{00AB}\x{201E}\x{300C}]([^\x{201D}"\x{00BB}\x{300D}]{25,400})[\x{201D}"\x{00BB}\x{300D}]/u', $b['text'], $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $q ) {
				if ( fmg_scan_tokens( $q[0] ) < 6 ) {
					continue;
				}
				$quotes++;
				$window = mb_substr( $b['text'], max( 0, mb_strlen( substr( $b['text'], 0, $q[1] ) ) - 80 ), mb_strlen( $q[0] ) + 160 );
				if ( preg_match( '/([\p{Lu}][\p{L}\'\-\.]+(?:\s+[\p{Lu}][\p{L}\'\-\.]+)*)\s*,?\s*(said|says|wrote|writes|told|explained|explains)\b|\b(said|says|wrote|writes|told|according to)\s+(Dr\.?\s+|Prof\.?\s+)?[\p{Lu}][\p{L}\'\-\.]+/u', $window ) ) {
					$attributed++;
				}
			}
		}
	}

	$checks = array(
		fmg_scan_check( 'C2_sources', 'Claims are backed by external sources a reader can check', $credit, 1, 'Link the specific pages your claims rest on, at two or more outside sources. This is reader verifiability; the evidence that outbound links raise AI citations is contested, so it counts only on informational pages and lightly.', $applicable ? $n . ' external domain(s) cited to a specific page' . ( $classes ? '; ' . count( $classes ) . ' primary or institutional (DOI, government, academic, standards)' : '' ) : 'Applies to informational (article-like) pages' ),
		fmg_scan_note( 'N_specificity', 'Numeric facts inside sentences', $stats > 0, $stats . ' number(s) in sentences, excluding years, dates, phone numbers, part numbers and bracketed markers. Not scored: the 2026 replication on modern engines finds no lift, and the count is easy to inflate.' ),
		fmg_scan_note( 'N_quotes', 'Attributed quotations', $attributed > 0, $quotes . ' quotation(s), ' . $attributed . ' with a named speaker. Not scored: genre-bound, and the 2026 replication finds no lift.' ),
	);
	return fmg_scan_cluster( 'verifiability', 'Verifiability', 'On informational pages, whether a reader can check the claims.', 25, 'Low', $checks );
}

function fmg_scan_cluster_answer( $ctx ) {
	$main = $ctx['main'];
	$applicable = $ctx['types']['article'] || $ctx['types']['qa'];
	$total = max( 1, $main['tokens'] );

	$lede = null;
	$after_h1 = false;
	foreach ( $main['blocks'] as $b ) {
		if ( 'h1' === $b['tag'] ) {
			$after_h1 = true;
			continue;
		}
		if ( ! $b['prose'] ) {
			continue;
		}
		if ( $b['tokens'] >= 25 ) {
			$depth = $b['offset'] / $total;
			if ( ( $after_h1 || '' === $main['h1'] ) && $b['offset'] <= 60 ) {
				$depth = 0;
			}
			$lede = array( 'text' => $b['text'], 'tokens' => $b['tokens'], 'depth' => $depth );
			break;
		}
	}
	$pos_credit = null;
	$pos_detail = 'Applies to article-like and question-and-answer pages';
	if ( $applicable ) {
		$pos_credit = $lede ? ( $lede['depth'] <= 0.10 ? 1 : ( $lede['depth'] <= 0.30 ? 0.5 : 0 ) ) : 0;
		$pos_detail = $lede ? 'First 25+ word paragraph starts ' . round( $lede['depth'] * 100 ) . '% into the main text' : 'No paragraph of 25 or more words';
	}

	$title = fmg_scan_title( $ctx );
	$site  = fmg_scan_meta( $ctx, 'og:site_name' );
	if ( '' !== $site ) {
		$title = preg_replace( '/\s*[\|\-\x{2013}\x{2014}:\x{00B7}\x{2022}]\s*' . preg_quote( $site, '/' ) . '\s*$/iu', '', $title );
	}
	$terms = array_unique( array_merge( fmg_scan_content_words( $title ), fmg_scan_content_words( $main['h1'] ) ) );
	$topic_credit = null;
	$topic_detail = $pos_detail;
	if ( $applicable ) {
		if ( $lede ) {
			$lw    = fmg_scan_content_words( $lede['text'] );
			$hit   = count( array_intersect( $lw, $terms ) );
			$sized = $lede['tokens'] >= 25 && $lede['tokens'] <= 150;
			$stuff = false;
			foreach ( $terms as $t ) {
				if ( strlen( $t ) >= 4 && preg_match_all( '/\b' . preg_quote( $t, '/' ) . '/iu', mb_strtolower( $lede['text'] ) ) > 3 ) {
					$stuff = true;
				}
			}
			$topic_credit = $stuff ? 0 : ( ( $hit > 0 ? 0.5 : 0 ) + ( $sized ? 0.5 : 0 ) );
			$topic_detail = $stuff ? 'A title term is repeated more than three times in the opening paragraph' : ( $hit . ' topic term(s) from the title or H1; ' . $lede['tokens'] . ' words' . ( $sized ? '' : ' (25 to 150 stands alone best)' ) );
		} else {
			$topic_credit = 0;
			$topic_detail = 'No opening paragraph to test';
		}
	}

	// Notes: definition sentence, sentence length and reading grade, promotional terms.
	$def = false;
	$seen = 0;
	foreach ( $main['blocks'] as $b ) {
		if ( $b['prose'] ) {
			foreach ( fmg_scan_sentences( $b['text'] ) as $s ) {
				if ( preg_match( '/^(?!there\s)(?!our\s)([^,.;:]{2,80}?)\b(is|are|means|refers to)\b/iu', $s, $m ) && count( array_intersect( fmg_scan_content_words( $m[1] ), $terms ) ) > 0 ) {
					$def = true;
					break 2;
				}
			}
		}
		$seen += $b['tokens'];
		if ( $seen / $total > 0.3 ) {
			break;
		}
	}
	$lengths = array();
	$syl = 0;
	$wc  = 0;
	foreach ( $main['blocks'] as $b ) {
		if ( preg_match( '/^h[1-6]$/', $b['tag'] ) ) {
			continue;
		}
		foreach ( fmg_scan_sentences( $b['text'] ) as $s ) {
			$n = fmg_scan_tokens( $s );
			$lengths[] = $n;
			$wc += $n;
			foreach ( preg_split( '/[^a-z]+/i', strtolower( $s ) ) as $w ) {
				if ( '' === $w ) {
					continue;
				}
				$c = preg_match_all( '/[aeiouy]+/', $w );
				if ( 'e' === substr( $w, -1 ) && $c > 1 && ! preg_match( '/(le|ee)$/', $w ) ) {
					$c--;
				}
				$syl += max( 1, $c );
			}
		}
	}
	$median = $lengths ? fmg_scan_log_median( $lengths ) : 0;
	$grade  = ( $lengths && $wc >= 100 ) ? 0.39 * ( $wc / count( $lengths ) ) + 11.8 * ( $syl / $wc ) - 15.59 : null;
	$promo  = preg_match_all( '/\b(world[- ]class|award[- ]winning|cutting[- ]edge|revolutionary|unmatched|unrivaled|unparalleled|industry[- ]leading|state[- ]of[- ]the[- ]art|game[- ]changing|second to none|number one)\b/iu', $main['text'] );

	$checks = array(
		fmg_scan_check( 'C3_position', 'A substantive paragraph opens the content', $pos_credit, 3, 'Open with a paragraph that answers the page\'s question. In ChatGPT data, 44% of cited passages come from the first 30% of a page; Google says no special writing style is needed, so this is low weight.', $pos_detail ),
		fmg_scan_check( 'C3_topic', 'The opening paragraph names the topic and stands on its own', $topic_credit, 2, 'Use the words of the title or H1 in the first paragraph, once, and keep it between 25 and 150 words. Controlled tests find a source that contains the query terms is far more likely to be cited; repeating them is penalized.', $topic_detail ),
		fmg_scan_note( 'N_definition', 'A definition or direct claim about the topic early', $def, $def ? 'Found in the first 30% of the main text' : 'None found in the first 30%. Not scored: single-engine observational evidence.' ),
		fmg_scan_note( 'N_sentences', 'Sentence length and reading grade', $median > 0 && $median <= 25, $lengths ? 'Median ' . round( $median ) . ' words per sentence' . ( null !== $grade ? ', Flesch-Kincaid grade ' . round( $grade, 1 ) : '' ) . '. Not scored: studies disagree on the ideal by engine.' : 'No sentences found' ),
		fmg_scan_note( 'N_promo', 'Promotional terms', 0 === $promo, $promo . ' promotional term(s). Not scored: the evidence points both ways.' ),
	);
	return fmg_scan_cluster( 'answer', 'Answer Shape', 'On informational pages, whether the opening can be lifted as an answer.', 25, 'Low', $checks );
}

/*
|--------------------------------------------------------------------------
| Observations (never scored)
|--------------------------------------------------------------------------
*/

function fmg_scan_observations( $ctx ) {
	$parsed = $ctx['robots_groups'];
	$path   = $ctx['path'];
	$notes  = array();

	$training = array(
		'GPTBot' => 'OpenAI: "Each setting is independent"; training only',
		'ClaudeBot' => 'Anthropic: training exclusion; answer effect not stated',
		'Applebot-Extended' => 'Apple: "can still be included in search results"',
		'Google-Extended' => 'Google: removes Gemini app grounding; no effect on Search',
		'CCBot' => 'Common Crawl: no statement on downstream use',
		'meta-externalagent' => 'Meta: training or "improving products by indexing content directly"',
		'Amazonbot' => 'Amazon: products and services; "may be used to train"',
		'Bytespider' => 'ByteDance: no first-party documentation found',
	);
	$blocked = array();
	if ( 'ok' === $ctx['robots_state'] ) {
		foreach ( $training as $token => $why ) {
			$r = fmg_scan_robots_allowed( $parsed, $token, $path );
			if ( ! $r['allowed'] && null !== $r['group'] && '*' !== $r['group'] ) {
				$blocked[] = $token . ' (' . $why . ')';
			}
		}
	}
	$notes[] = fmg_scan_note( 'N_training', 'Training and control tokens disallowed by name', ! empty( $blocked ), $blocked ? implode( '; ', $blocked ) : 'None disallowed by name' );
	$notes[] = fmg_scan_note( 'N_user_fetchers', 'User-triggered fetchers', true, 'ChatGPT-User, Perplexity-User, meta-externalfetcher, Amzn-User and Google-Agent fetch on a user\'s request and, per their operators, may ignore robots.txt. A robots rule does not control them.' );
	$notes[] = fmg_scan_note( 'N_signals', 'Content-Signal or Content-Usage line in robots.txt', ! empty( $parsed['signals'] ), $parsed['signals'] ? implode( '; ', array_slice( $parsed['signals'], 0, 3 ) ) . '. A stated preference; no engine documents honoring it.' : 'None' );
	$noarchive = fmg_scan_has_directive( $ctx['directives']['robots'], 'noarchive' ) || fmg_scan_has_directive( $ctx['directives']['robots'], 'nocache' ) || fmg_scan_has_directive( $ctx['directives']['bingbot'], 'noarchive' );
	$notes[] = fmg_scan_note( 'N_noarchive', 'noarchive or nocache directive', $noarchive, $noarchive ? 'Present. Bing documented in 2023 that noarchive excludes a page from Copilot answers and nocache limits it to title and snippet; the 2026 status is unverified. Google ignores both.' : 'None' );
	$notes[] = fmg_scan_note( 'N_https', 'HTTPS', 'https' === $ctx['scheme'], strtoupper( $ctx['scheme'] ) );
	$redir_detail = 'HTTP ' . $ctx['status'] . ' after ' . $ctx['hops'] . ' redirect(s). Google follows up to 10.';
	if ( $ctx['hops'] > 0 ) {
		$redir_detail .= ' The eligibility above is evaluated for the final URL.';
		if ( ! empty( $ctx['entry_same_origin'] ) && isset( $ctx['entry_path'] ) && $ctx['entry_path'] !== $ctx['path'] ) {
			$entry_ok = fmg_scan_robots_allowed( $parsed, 'Googlebot', $ctx['entry_path'] );
			if ( ! $entry_ok['allowed'] ) {
				$redir_detail .= ' The address entered (' . $ctx['entry_path'] . ') is itself disallowed for Googlebot; a crawler would be blocked before the redirect.';
			}
		}
	}
	$notes[] = fmg_scan_note( 'N_redirects', 'Redirects and final status', 0 === $ctx['hops'], $redir_detail );
	$sm = $parsed['sitemaps'] ? 'Declared in robots.txt' : ( $ctx['sitemap'] && '' !== $ctx['sitemap']['body'] && preg_match( '/<(urlset|sitemapindex)\b/i', $ctx['sitemap']['body'] ) ? 'Found at /sitemap.xml' : 'None found' );
	$notes[] = fmg_scan_note( 'N_sitemap', 'Sitemap', 'None found' !== $sm, $sm . '. Bing calls lastmod a recrawl-prioritization signal for AI-powered search.' );
	$validators = array();
	foreach ( array( 'etag' => 'ETag', 'last-modified' => 'Last-Modified' ) as $h => $label ) {
		if ( ! empty( $ctx['headers'][ $h ] ) ) {
			$validators[] = $label;
		}
	}
	$notes[] = fmg_scan_note( 'N_validators', 'HTTP caching validators', ! empty( $validators ), $validators ? implode( ', ', $validators ) . '. Google uses them for crawl efficiency; no engine ties them to answer eligibility.' : 'Neither ETag nor Last-Modified' );
	$notes[] = fmg_scan_note( 'N_response_time', 'Response time', $ctx['seconds'] <= 3, number_format( $ctx['seconds'], 1 ) . ' s to fetch' . ( $ctx['seconds'] > 3 ? ' (slow; page-speed evidence for AI citation is weak and contested)' : '' ) );
	$notes[] = fmg_scan_note( 'N_llms', 'llms.txt', '' !== $ctx['llms']['body'], ( '' !== $ctx['llms']['body'] ? 'Present' : 'Absent' ) . '. Google documents that Search does not use it; a 300,000-domain study finds no correlation with citations.' );
	$notes[] = fmg_scan_note( 'N_differential', 'Browser-agent probe', null !== $ctx['differential'], null !== $ctx['differential'] ? 'Ran: scanner user agent got ' . $ctx['differential'] . '; browser user agent got the page' : 'Not needed: the scanner user agent received the page' );
	$notes[] = fmg_scan_note( 'N_canonical', 'Canonical', 'self' === $ctx['canonical']['state'], 'self' === $ctx['canonical']['state'] ? 'Self-referencing' : ( 'missing' === $ctx['canonical']['state'] ? 'No canonical tag or Link header (Google picks one)' : 'Points to ' . $ctx['canonical']['target'] ) );
	return $notes;
}

/*
|--------------------------------------------------------------------------
| Front end
|--------------------------------------------------------------------------
*/

add_shortcode( 'fmg_readability_scan', 'fmg_scan_shortcode' );

/**
 * Render the scanner.
 */
function fmg_scan_shortcode() {
	wp_register_style( 'fmg-scan', false, array(), '3.0.0' );
	wp_enqueue_style( 'fmg-scan' );
	wp_add_inline_style( 'fmg-scan', fmg_scan_css() );

	wp_register_script( 'fmg-scan', '', array(), '3.0.0', true );
	wp_enqueue_script( 'fmg-scan' );
	wp_add_inline_script(
		'fmg-scan',
		'window.FMG_SCAN = ' . wp_json_encode(
			array(
				'endpoint' => esc_url_raw( rest_url( 'fmg/v1/scan' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
			)
		) . ';',
		'before'
	);
	wp_add_inline_script( 'fmg-scan', fmg_scan_js(), 'after' );

	ob_start();
	?>
	<div class="fmg-scan" id="fmg-scan">
		<form class="fmg-scan__form" novalidate>
			<label class="fmg-scan__label" for="fmg-scan-url">Your website address</label>
			<div class="fmg-scan__row">
				<input
					type="url"
					id="fmg-scan-url"
					class="fmg-scan__input"
					placeholder="example.com"
					autocomplete="url"
					spellcheck="false"
					required>
				<button type="submit" class="fmg-scan__submit">Scan my site</button>
			</div>
			<p class="fmg-scan__note">
				One page, read the way an AI crawler reads it. You get an eligibility check for each engine,
				from that engine&#8217;s own published rules, and two scores: Legibility, how well a machine can read you,
				and Citability, whether the page carries what a citation needs. Namability, who gets named in the answer, takes a benchmark across engines and questions, so a single-page scan does not score it.
			</p>
		</form>
		<div class="fmg-scan__status" role="status" aria-live="polite"></div>
		<div class="fmg-scan__results" hidden></div>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Scanner styles.
 */
function fmg_scan_css() {
	return <<<CSS
.fmg-scan {
  --fmg-ink: #17211d; --fmg-ink-soft: #4d5a55;
  --fmg-paper: #f8f8f5; --fmg-paper-alt: #eef0e9;
  --fmg-accent: #156a58; --fmg-accent-hover: #0e5244;
  --fmg-dark: #14201e; --fmg-dark-text: #bcc7c3;
  --fmg-apricot: #f4a860; --fmg-line: #dde1d9;
  --fmg-fail: #8a3416; --fmg-partial: #9a6a12;
  max-width: 760px; margin: 38px auto 0; color: var(--fmg-ink);
}
.fmg-scan .sr-only {
  position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
  overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0;
}
.fmg-score__band:focus-visible, .fmg-state:focus-visible { outline: 3px solid var(--fmg-apricot); outline-offset: 4px; }
.fmg-scan__label {
  display: block; font-size: .78rem; font-weight: 600; letter-spacing: .12em;
  text-transform: uppercase; color: var(--fmg-accent); margin-bottom: 10px;
}
.fmg-scan__row { display: flex; gap: 10px; flex-wrap: wrap; }
.fmg-scan__input {
  flex: 1 1 260px; padding: 14px 16px; font: inherit; font-size: 1.02rem;
  border: 1px solid var(--fmg-line); border-radius: 8px; background: #fff; color: var(--fmg-ink);
}
.fmg-scan__input:focus-visible { outline: 3px solid var(--fmg-accent); outline-offset: 2px; }
.fmg-scan__submit {
  padding: 14px 24px; font: inherit; font-weight: 600; border: 0; border-radius: 8px;
  background: var(--fmg-apricot); color: var(--fmg-dark); cursor: pointer;
}
.fmg-scan__submit:hover { background: #ee9848; }
.fmg-scan__submit:disabled { opacity: .6; cursor: progress; }
.fmg-scan__submit:focus-visible { outline: 3px solid var(--fmg-accent); outline-offset: 2px; }
.fmg-scan__note { margin: 14px 0 0; font-size: .9rem; color: var(--fmg-ink-soft); max-width: 60ch; }
.fmg-scan__fine { margin: 18px 0 0; font-size: .8rem; color: var(--fmg-ink-soft); max-width: 60ch; }
.fmg-scan__status { margin-top: 18px; font-size: .95rem; color: var(--fmg-ink-soft); }
.fmg-scan__status[data-state="error"] { color: var(--fmg-fail); font-weight: 500; }

.fmg-scan__results { margin-top: 34px; }
.fmg-section__title { font-size: .78rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: var(--fmg-accent); margin: 30px 0 10px; }
.fmg-state { background: var(--fmg-paper-alt); border-left: 4px solid var(--fmg-apricot); border-radius: 0 12px 12px 0; padding: 18px 22px; margin: 0 0 18px; }
.fmg-state--blocked { border-left-color: var(--fmg-fail); }
.fmg-state h3 { margin: 0 0 8px; font-size: 1.1rem; }
.fmg-state p { margin: 0 0 6px; font-size: .92rem; color: var(--fmg-ink-soft); }
.fmg-elig { width: 100%; border-collapse: collapse; font-size: .9rem; margin: 0 0 6px; }
.fmg-elig th, .fmg-elig td { text-align: left; vertical-align: top; padding: 10px 8px; border-bottom: 1px solid var(--fmg-line); }
.fmg-elig th { font-size: .78rem; text-transform: uppercase; letter-spacing: .08em; color: var(--fmg-ink-soft); font-weight: 600; }
.fmg-elig__engine { font-weight: 600; white-space: nowrap; }
.fmg-elig__state { font-weight: 700; white-space: nowrap; }
.fmg-elig--eligible .fmg-elig__state { color: var(--fmg-accent); }
.fmg-elig--ineligible .fmg-elig__state { color: var(--fmg-fail); }
.fmg-elig--reduced .fmg-elig__state, .fmg-elig--indeterminate .fmg-elig__state { color: var(--fmg-partial); }
.fmg-elig--undocumented .fmg-elig__state { color: var(--fmg-ink-soft); }
.fmg-elig__reason { color: var(--fmg-ink-soft); }
.fmg-elig__source { display: block; font-size: .78rem; color: var(--fmg-ink-soft); margin-top: 3px; }
.fmg-score {
  background: var(--fmg-dark); color: #fff; border-radius: 16px; padding: 38px 34px;
  display: flex; gap: 30px; align-items: center; flex-wrap: wrap;
}
.fmg-score__dial { position: relative; width: 132px; height: 132px; flex: 0 0 auto; }
.fmg-score__dial svg { transform: rotate(-90deg); width: 132px; height: 132px; }
.fmg-score__track { stroke: rgba(255,255,255,.14); }
.fmg-score__arc {
  stroke: var(--fmg-apricot); stroke-linecap: round;
  transition: stroke-dashoffset 1s cubic-bezier(.2,.7,.3,1);
}
.fmg-score__num {
  position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
  font-size: 2.7rem; font-weight: 700; letter-spacing: -.03em; color: var(--fmg-apricot);
}
.fmg-score__num--na { font-size: 1.1rem; text-align: center; padding: 0 12px; }
.fmg-score__copy { flex: 1 1 300px; min-width: 240px; }
.fmg-score__eyebrow {
  margin: 0 0 6px; font-size: .72rem; font-weight: 700; letter-spacing: .16em;
  text-transform: uppercase; color: var(--fmg-apricot);
}
.fmg-cats + .fmg-score { margin-top: 30px; }
.fmg-score__band { font-size: 1.45rem; font-weight: 700; letter-spacing: -.02em; margin: 0 0 8px; }
.fmg-score__summary { margin: 0; color: var(--fmg-dark-text); font-size: 1rem; }
.fmg-score__flag { margin: 12px 0 0; font-size: .88rem; color: #fff; border-left: 3px solid var(--fmg-apricot); padding-left: 12px; }
.fmg-score__meta { margin: 14px 0 0; font-size: .8rem; color: rgba(255,255,255,.5); word-break: break-word; }

.fmg-cats { margin-top: 26px; display: grid; gap: 12px; }
.fmg-cat { border: 1px solid var(--fmg-line); border-radius: 12px; background: var(--fmg-paper); overflow: hidden; }
.fmg-cat__head {
  display: flex; align-items: baseline; justify-content: space-between; gap: 14px;
  padding: 18px 22px 8px;
}
.fmg-cat__name { font-weight: 700; font-size: 1.08rem; margin: 0; }
.fmg-cat__conf { font-weight: 500; font-size: .78rem; color: var(--fmg-ink-soft); margin-left: 8px; }
.fmg-cat__pts { font-size: .85rem; color: var(--fmg-ink-soft); white-space: nowrap; }
.fmg-cat__blurb { margin: 0; padding: 0 22px 12px; font-size: .88rem; color: var(--fmg-ink-soft); }
.fmg-cat__bar { height: 4px; background: var(--fmg-paper-alt); margin: 0 22px 16px; border-radius: 2px; }
.fmg-cat__fill { height: 100%; background: var(--fmg-accent); border-radius: 2px; transition: width .8s ease; }
.fmg-checks { list-style: none; margin: 0; padding: 0 22px 18px; display: grid; gap: 12px; }
.fmg-check { display: grid; grid-template-columns: 22px 1fr; gap: 12px; align-items: start; }
.fmg-check__mark { font-weight: 700; line-height: 1.5; }
.fmg-check--pass .fmg-check__mark { color: var(--fmg-accent); }
.fmg-check--partial .fmg-check__mark { color: var(--fmg-partial); }
.fmg-check--fail .fmg-check__mark { color: var(--fmg-fail); }
.fmg-check--na .fmg-check__mark, .fmg-check--info .fmg-check__mark { color: var(--fmg-ink-soft); font-family: Georgia, serif; font-style: italic; }
.fmg-check--na .fmg-check__label, .fmg-check--info .fmg-check__label { font-weight: 500; color: var(--fmg-ink-soft); }
.fmg-check__label { font-weight: 600; font-size: .96rem; }
.fmg-check__pts { font-weight: 400; font-size: .84rem; color: var(--fmg-ink-soft); }
.fmg-check__detail { font-size: .84rem; color: var(--fmg-ink-soft); margin-top: 2px; }
.fmg-check__fix {
  font-size: .88rem; color: var(--fmg-ink-soft); margin-top: 6px;
  border-left: 3px solid var(--fmg-apricot); padding-left: 12px;
}
.fmg-obs { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; font-size: .88rem; }
.fmg-obs li { display: grid; grid-template-columns: 22px 1fr; gap: 12px; }
.fmg-obs__label { font-weight: 600; }
.fmg-obs__detail { color: var(--fmg-ink-soft); }

.fmg-cta { margin-top: 30px; background: var(--fmg-paper-alt); border-radius: 16px; padding: 34px 30px; }
.fmg-cta h3 { margin: 0 0 10px; font-size: 1.3rem; letter-spacing: -.02em; }
.fmg-cta p { margin: 0 0 22px; color: var(--fmg-ink-soft); max-width: 58ch; }
.fmg-cta__actions { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
.fmg-cta__btn {
  display: inline-block; padding: 14px 24px; border-radius: 8px; font-weight: 600;
  text-decoration: none; background: var(--fmg-accent); color: #fff; border: 0; font: inherit;
  font-weight: 600; cursor: pointer;
}
.fmg-cta__btn:hover { background: var(--fmg-accent-hover); color: #fff; }
.fmg-cta__btn:focus-visible { outline: 3px solid var(--fmg-accent); outline-offset: 2px; }

@media (max-width: 560px) {
  .fmg-score { padding: 28px 22px; gap: 22px; }
  .fmg-cta { padding: 26px 22px; }
  /* Stack the reason under engine and state instead of dropping it. */
  .fmg-elig thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
  .fmg-elig tr { display: grid; grid-template-columns: auto 1fr; column-gap: 12px; border-bottom: 1px solid var(--fmg-line); }
  .fmg-elig td { border-bottom: 0; }
  .fmg-elig td.fmg-elig__reason { grid-column: 1 / -1; padding-top: 0; }
  .fmg-elig__engine, .fmg-elig__state { white-space: normal; }
}
@media (prefers-reduced-motion: reduce) {
  .fmg-score__arc, .fmg-cat__fill { transition: none; }
}
CSS;
}

/**
 * Scanner behavior.
 */
function fmg_scan_js() {
	return <<<'JS'
(function () {
  var root = document.getElementById('fmg-scan');
  if (!root || !window.FMG_SCAN) return;

  var form = root.querySelector('.fmg-scan__form');
  var input = root.querySelector('.fmg-scan__input');
  var button = root.querySelector('.fmg-scan__submit');
  var status = root.querySelector('.fmg-scan__status');
  var results = root.querySelector('.fmg-scan__results');

  var esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };
  var pts = function (n) { return String(Math.round(n)); };
  var STATE_WORD = { eligible: 'Eligible', ineligible: 'Not eligible', reduced: 'Reduced', indeterminate: 'Indeterminate', undocumented: 'Not documented' };

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var url = input.value.trim();
    if (!url) { setStatus('Enter a website address to scan.', 'error'); return; }

    button.disabled = true;
    button.textContent = 'Scanning';
    results.hidden = true;
    results.innerHTML = '';
    setStatus('Reading the page the way a crawler would. This takes a few seconds.', 'busy');

    fetch(window.FMG_SCAN.endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.FMG_SCAN.nonce },
      body: JSON.stringify({ url: url })
    })
      .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
      .then(function (res) {
        if (!res.ok) {
          setStatus((res.body && res.body.message) || 'The scan did not complete. Try again in a moment.', 'error');
          return;
        }
        setStatus('', 'done');
        render(res.body);
      })
      .catch(function () {
        setStatus('The scan did not complete. Check the address and try again.', 'error');
      })
      .finally(function () {
        button.disabled = false;
        button.textContent = 'Scan my site';
      });
  });

  function setStatus(msg, state) {
    status.textContent = msg;
    status.setAttribute('data-state', state || '');
  }

  function eligibilityHtml(rows) {
    var html = '<p class="fmg-section__title">Eligibility, engine by engine</p>'
      + '<table class="fmg-elig"><thead><tr><th>Engine</th><th>State</th><th>Why, in the operator’s own terms</th></tr></thead><tbody>';
    rows.forEach(function (r) {
      html += '<tr class="fmg-elig--' + esc(r.state) + '">'
        + '<td class="fmg-elig__engine">' + esc(r.engine) + '</td>'
        + '<td class="fmg-elig__state">' + esc(STATE_WORD[r.state] || r.state) + '</td>'
        + '<td class="fmg-elig__reason">' + esc(r.reason) + '<span class="fmg-elig__source">' + esc(r.source) + '</span></td>'
        + '</tr>';
    });
    return html + '</tbody></table>';
  }

  function statesHtml(states) {
    if (!states.reasons || !states.reasons.length) return '';
    var head = states.unscorable ? 'This page cannot be scored from its HTML' : 'Access is indeterminate';
    var html = '<div class="fmg-state" tabindex="-1"><h3>' + esc(head) + '</h3>';
    states.reasons.forEach(function (r) { html += '<p>' + esc(r) + '</p>'; });
    return html + '</div>';
  }

  // Every documented answer surface is Ineligible: no row is Eligible, Reduced or
  // Indeterminate, and at least one is Ineligible. Undocumented rows do not count.
  function allIneligible(rows) {
    if (!rows || !rows.length) return false;
    var anyBlocked = false;
    for (var i = 0; i < rows.length; i++) {
      var s = rows[i].state;
      if (s === 'eligible' || s === 'reduced' || s === 'indeterminate') return false;
      if (s === 'ineligible') anyBlocked = true;
    }
    return anyBlocked;
  }

  function blockedBanner(rows) {
    var names = [];
    rows.forEach(function (r) { if (r.state === 'ineligible') names.push(r.engine); });
    return '<div class="fmg-state fmg-state--blocked" tabindex="-1">'
      + '<h3>Not eligible for AI answers</h3>'
      + '<p>Every answer surface with a documented rule is blocked for this page: '
      + esc(names.join('; ')) + '. The reasons, in each operator’s own terms, are in the table above.</p>'
      + '<p>The two dials below show what an engine would find if the block were removed; they do not change the eligibility above.</p>'
      + '</div>';
  }

  function scoreCard(group, eyebrow, meta) {
    var C = 2 * Math.PI * 58;
    var num, band, summary, flag = '';
    if (group.score === null || group.score === undefined) {
      num = '<div class="fmg-score__num fmg-score__num--na">Not scored</div>';
      band = 'Unscorable';
      summary = 'There is not enough server-side text to evaluate. See the note above.';
    } else {
      num = '<div class="fmg-score__num">' + group.score + '</div>';
      band = group.band.label;
      summary = group.band.summary;
      if ((group.score >= 37 && group.score <= 42) || (group.score >= 67 && group.score <= 72)) {
        flag = '<p class="fmg-score__flag">Near a band boundary. Small changes to the page can move the label.</p>';
      }
    }
    var offsetAttr = group.score === null || group.score === undefined ? C : C - (C * group.score) / 100;
    return '<div class="fmg-score">'
      + '<div class="fmg-score__dial">'
      + '<svg viewBox="0 0 132 132" aria-hidden="true">'
      + '<circle class="fmg-score__track" cx="66" cy="66" r="58" fill="none" stroke-width="9"></circle>'
      + '<circle class="fmg-score__arc" cx="66" cy="66" r="58" fill="none" stroke-width="9" '
      + 'stroke-dasharray="' + C + '" stroke-dashoffset="' + C + '" data-offset="' + offsetAttr + '"></circle>'
      + '</svg>' + num + '</div>'
      + '<div class="fmg-score__copy">'
      + '<p class="fmg-score__eyebrow">' + esc(eyebrow) + '</p>'
      + '<p class="fmg-score__band">' + esc(band)
      + '<span class="sr-only"> ' + esc(eyebrow) + (group.score === null ? ' not scored' : ' score, ' + group.score + ' out of 100') + '</span></p>'
      + '<p class="fmg-score__summary">' + esc(summary) + '</p>'
      + flag
      + (meta ? '<p class="fmg-score__meta">' + meta + '</p>' : '')
      + '</div></div>';
  }

  function markFor(state) {
    return { pass: '&#10003;', partial: '&#9685;', fail: '&#10007;', info: 'i', na: '&ndash;' }[state] || '';
  }
  function wordFor(state) {
    return { pass: 'pass', partial: 'partial credit', fail: 'needs work', info: 'noted', na: 'not applicable' }[state] || '';
  }

  function catsHtml(clusters) {
    var html = '<div class="fmg-cats">';
    Object.keys(clusters).forEach(function (key) {
      var cat = clusters[key];
      var pct = cat.applicable ? Math.round(cat.credit * 100) : 0;
      html += '<section class="fmg-cat">'
        + '<div class="fmg-cat__head"><h3 class="fmg-cat__name">' + esc(cat.label)
        + '<span class="fmg-cat__conf">evidence: ' + esc(cat.confidence) + '</span></h3>'
        + '<span class="fmg-cat__pts">' + (cat.applicable ? pts(cat.earned) + ' of ' + cat.weight : 'not applicable') + '</span></div>'
        + '<p class="fmg-cat__blurb">' + esc(cat.blurb) + '</p>'
        + '<div class="fmg-cat__bar"><div class="fmg-cat__fill" style="width:0%" data-pct="' + pct + '"></div></div>'
        + '<ul class="fmg-checks">';
      cat.checks.forEach(function (c) {
        var state = c.state;
        var score = (state === 'info' || state === 'na') ? '' : ' <span class="fmg-check__pts">' + pts(c.earned) + ' of ' + c.weight + '</span>';
        html += '<li class="fmg-check fmg-check--' + state + '">'
          + '<span class="fmg-check__mark" aria-hidden="true">' + markFor(state) + '</span>'
          + '<div><div class="fmg-check__label">' + esc(c.label) + score
          + '<span class="sr-only">: ' + wordFor(state) + '</span></div>'
          + (c.detail ? '<div class="fmg-check__detail">' + esc(c.detail) + '</div>' : '')
          + ((state === 'fail' || state === 'partial') && c.fix ? '<div class="fmg-check__fix">' + esc(c.fix) + '</div>' : '')
          + '</div></li>';
      });
      html += '</ul></section>';
    });
    return html + '</div>';
  }

  function obsHtml(notes) {
    var html = '<p class="fmg-section__title">Also observed</p><ul class="fmg-obs">';
    notes.forEach(function (n) {
      html += '<li><span aria-hidden="true">i</span><div><span class="fmg-obs__label">' + esc(n.label) + '</span> <span class="fmg-obs__detail">' + esc(n.detail) + '</span></div></li>';
    });
    return html + '</ul>';
  }

  function render(data) {
    var meta = esc(data.url) + ' &middot; scanned ' + esc(data.scanned_at) + ' &middot; page read as: ' + esc((data.states.page_types || []).join(', ') || 'unclassified');
    var blocked = allIneligible(data.eligibility || []);
    var html = eligibilityHtml(data.eligibility || [])
      + (blocked ? blockedBanner(data.eligibility || []) : '')
      + statesHtml(data.states || {})
      + '<p class="fmg-section__title">Legibility</p>'
      + scoreCard(data.legibility, 'Legibility', meta)
      + (data.legibility.score === null ? '' : catsHtml(data.legibility.clusters))
      + '<p class="fmg-section__title">Citability</p>'
      + scoreCard(data.citability, 'Citability', '')
      + (data.citability.score === null ? '' : catsHtml(data.citability.clusters))
      + obsHtml(data.observations || [])
      + '<p class="fmg-scan__fine">This free scan is informational. It reads one page against fixed rules from one host with two user agents, so it sees how the site treats our scanner, not how it treats a verified engine crawler. It cannot see whether the page is in any engine’s index, how it ranks when an engine searches, who mentions you elsewhere on the web, a Search Console generative AI setting, or a CDN rule aimed at verified crawlers. A scan is reproducible only for the same served page and the same scan date. A high score is raw material, not a forecast. A benchmark across engines and questions measures more, and its findings can differ.</p>';

    html += ''
      + '<div class="fmg-cta">'
      + '<h3>Two scores a crawler can see. One it cannot.</h3>'
      + '<p>Legibility measures how well a machine can read you. Citability measures whether the page carries what a citation needs. <strong>Namability measures whether an engine names you in the first place.</strong></p>'
      + '<p>A crawl by itself cannot measure namability. '
      + 'It requires asking real questions in leading search and answer engines, then measuring how often '
      + 'you’re named against your competitors.</p>'
      + '<div class="fmg-cta__actions">'
      + '<button type="button" class="fmg-cta__btn" id="fmg-copy">Copy results</button>'
      + '</div>'
      + '</div>';

    results.innerHTML = html;
    results.hidden = false;

    requestAnimationFrame(function () {
      results.querySelectorAll('.fmg-score__arc').forEach(function (arc) {
        arc.style.strokeDashoffset = arc.getAttribute('data-offset');
      });
      results.querySelectorAll('.fmg-cat__fill').forEach(function (el) {
        el.style.width = el.getAttribute('data-pct') + '%';
      });
    });

    wireActions(data);
    var focusEl = results.querySelector('.fmg-state') || results.querySelector('.fmg-score__band');
    if (focusEl) { focusEl.setAttribute('tabindex', '-1'); focusEl.focus(); }
  }

  function summarize(data) {
    var lines = [];
    var leg = data.legibility.score === null ? 'not scored' : data.legibility.score + '/100 (' + data.legibility.band.label + ')';
    var cit = data.citability.score === null ? 'not scored' : data.citability.score + '/100 (' + data.citability.band.label + ')';
    lines.push('AI Legibility Score: ' + leg);
    lines.push('AI Citability Score: ' + cit);
    lines.push('Site: ' + data.url);
    lines.push('Scanned: ' + data.scanned_at);
    lines.push('');
    lines.push('Eligibility by engine:');
    (data.eligibility || []).forEach(function (r) {
      lines.push('- ' + r.engine + ': ' + (STATE_WORD[r.state] || r.state));
    });
    if (data.states && data.states.reasons && data.states.reasons.length) {
      lines.push('');
      lines.push('States:');
      data.states.reasons.forEach(function (r) { lines.push('- ' + r); });
    }
    lines.push('');

    var failed = [];
    function group(label, axis) {
      if (axis.score === null) return;
      var scores = [];
      Object.keys(axis.clusters).forEach(function (key) {
        var cat = axis.clusters[key];
        scores.push(cat.label + ' ' + (cat.applicable ? pts(cat.earned) + '/' + cat.weight : 'n/a'));
        cat.checks.forEach(function (c) {
          if (c.state === 'fail' || c.state === 'partial') failed.push('- ' + cat.label + ': ' + c.label + ' (' + pts(c.earned) + '/' + c.weight + (c.detail ? ', ' + c.detail : '') + ')');
        });
      });
      lines.push(label + ' clusters: ' + scores.join(' | '));
    }
    group('Legibility', data.legibility);
    group('Citability', data.citability);

    lines.push('');
    if (failed.length) {
      lines.push('Checks short of full credit (' + failed.length + '):');
      lines = lines.concat(failed);
    } else if (data.legibility.score !== null) {
      lines.push('All applicable checks passed.');
    }
    lines.push('');
    lines.push('This free scan is informational. A benchmark across engines and questions measures more, and its findings can differ.');

    return lines.join('\n');
  }

  function wireActions(data) {
    var body = summarize(data);
    var leg = data.legibility.score === null ? 'unscored' : data.legibility.score + ' legible';
    var cit = data.citability.score === null ? 'unscored' : data.citability.score + ' citable';
    var heading = 'AI Visibility: ' + leg + '/' + cit + ' for ' + data.host;

    var copy = document.getElementById('fmg-copy');
    copy.addEventListener('click', function () {
      var payload = heading + '\n\n' + body;
      var done = function () {
        copy.textContent = 'Copied';
        setTimeout(function () { copy.textContent = 'Copy results'; }, 2200);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(payload).then(done).catch(fallback);
      } else {
        fallback();
      }
      function fallback() {
        var ta = document.createElement('textarea');
        ta.value = payload;
        ta.setAttribute('readonly', '');
        ta.style.position = 'absolute';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); done(); } catch (e) { copy.textContent = 'Select and copy manually'; }
        document.body.removeChild(ta);
      }
    });
  }
})();
JS;
}
