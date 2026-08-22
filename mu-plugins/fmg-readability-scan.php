<?php
/**
 * Plugin Name: FMG AI Readability & Citability Scanner
 * Description: Deterministic single-URL scan scoring whether AI engines can read a site (Readability) and whether its pages are shaped to be cited (Citability). Shortcode: [fmg_readability_scan]
 * Version:     1.1.0-alpha
 * Author:      Fernwell Media Group
 *
 * Drop this file directly into wp-content/mu-plugins/ (no subfolder: mu-plugins
 * only autoloads top-level PHP files).
 *
 * @package Fernwell
 */

defined( 'ABSPATH' ) || exit;

/** Where the "Talk to Fernwell" button sends results. Change here, changes everywhere. */
const FMG_SCAN_CONTACT = 'hello@fernwellgroup.com';

/** Scans allowed per IP per hour. */
const FMG_SCAN_RATE_LIMIT = 10;

/** How long an identical URL's result is reused, in seconds. */
const FMG_SCAN_CACHE_TTL = 900;

/** Hard ceiling on fetched bytes per request. */
const FMG_SCAN_MAX_BYTES = 2097152;

/** User agent the scanner identifies itself with. */
const FMG_SCAN_UA = 'FernwellReadabilityBot/1.0 (+https://fernwellgroup.com/scan)';

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
	$limited = fmg_scan_check_rate_limit();
	if ( is_wp_error( $limited ) ) {
		return $limited;
	}

	$url = fmg_scan_normalize_url( (string) $request->get_param( 'url' ) );
	if ( is_wp_error( $url ) ) {
		return $url;
	}

	$cache_key = 'fmg_scan_v2_' . md5( $url );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		$cached['cached'] = true;
		return rest_ensure_response( $cached );
	}

	$result = fmg_scan_run( $url );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	set_transient( $cache_key, $result, FMG_SCAN_CACHE_TTL );
	return rest_ensure_response( $result );
}

/**
 * Throttle by IP.
 *
 * @return true|WP_Error
 */
function fmg_scan_check_rate_limit() {
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	$key = 'fmg_rl_' . md5( $ip );
	$hit = (int) get_transient( $key );

	if ( $hit >= FMG_SCAN_RATE_LIMIT ) {
		return new WP_Error(
			'fmg_rate_limited',
			'That is a lot of scans from one place. Try again in an hour, or email us and we will run it for you.',
			array( 'status' => 429 )
		);
	}

	set_transient( $key, $hit + 1, HOUR_IN_SECONDS );
	return true;
}

/*
|--------------------------------------------------------------------------
| URL safety
|--------------------------------------------------------------------------
*/

/**
 * Validate and normalize a submitted URL, rejecting anything that could reach internal hosts.
 *
 * @param string $raw Raw user input.
 * @return string|WP_Error
 */
function fmg_scan_normalize_url( $raw ) {
	$raw = trim( $raw );
	if ( '' === $raw ) {
		return new WP_Error( 'fmg_bad_url', 'Enter a website address to scan.', array( 'status' => 400 ) );
	}

	if ( ! preg_match( '#^https?://#i', $raw ) ) {
		$raw = 'https://' . $raw;
	}

	$parts = wp_parse_url( $raw );
	if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
		return new WP_Error( 'fmg_bad_url', 'That address could not be read. Try the full form, like https://example.com.', array( 'status' => 400 ) );
	}

	if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
		return new WP_Error( 'fmg_bad_url', 'Only http and https addresses can be scanned.', array( 'status' => 400 ) );
	}

	if ( isset( $parts['port'] ) && ! in_array( (int) $parts['port'], array( 80, 443 ), true ) ) {
		return new WP_Error( 'fmg_bad_url', 'Only standard web ports can be scanned.', array( 'status' => 400 ) );
	}

	$host = strtolower( $parts['host'] );
	$safe = fmg_scan_host_is_public( $host );
	if ( is_wp_error( $safe ) ) {
		return $safe;
	}

	$path = isset( $parts['path'] ) ? $parts['path'] : '/';
	return $parts['scheme'] . '://' . $host . $path;
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
		return new WP_Error( 'fmg_bad_host', 'That address is not reachable from the public web.', array( 'status' => 400 ) );
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
		return new WP_Error( 'fmg_bad_host', 'That domain did not resolve. Check the spelling and try again.', array( 'status' => 400 ) );
	}

	foreach ( $ips as $ip ) {
		$public = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		if ( false === $public ) {
			return new WP_Error( 'fmg_bad_host', 'That address points somewhere private and cannot be scanned.', array( 'status' => 400 ) );
		}
	}

	return true;
}

/**
 * Fetch a URL, following redirects one hop at a time and revalidating every hop.
 *
 * @param string $url      Starting URL.
 * @param int    $max_hops Redirect ceiling.
 * @return array|WP_Error  array( body, status, headers, final_url, hops )
 */
function fmg_scan_fetch( $url, $max_hops = 3 ) {
	$hops = 0;

	for ( $i = 0; $i <= $max_hops; $i++ ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 12,
				'redirection'         => 0,
				'user-agent'          => FMG_SCAN_UA,
				'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.8' ),
				// The response is capped at the transport layer; without this the
				// full body lands in memory before the substr below can trim it.
				'limit_response_size' => FMG_SCAN_MAX_BYTES,
				'reject_unsafe_urls'  => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( in_array( $status, array( 301, 302, 303, 307, 308 ), true ) ) {
			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( empty( $location ) ) {
				break;
			}

			$next = fmg_scan_absolutize( $location, $url );
			$next = fmg_scan_normalize_url( $next );
			if ( is_wp_error( $next ) ) {
				return $next;
			}

			$url = $next;
			$hops++;
			continue;
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > FMG_SCAN_MAX_BYTES ) {
			$body = substr( $body, 0, FMG_SCAN_MAX_BYTES );
		}

		return array(
			'body'      => $body,
			'status'    => $status,
			'headers'   => wp_remote_retrieve_headers( $response ),
			'final_url' => $url,
			'hops'      => $hops,
		);
	}

	return new WP_Error( 'fmg_redirects', 'That address redirects too many times to scan reliably.', array( 'status' => 400 ) );
}

/**
 * Resolve a possibly relative URL against a base.
 *
 * @param string $target Target.
 * @param string $base   Base URL.
 * @return string
 */
function fmg_scan_absolutize( $target, $base ) {
	if ( preg_match( '#^https?://#i', $target ) ) {
		return $target;
	}

	$parts  = wp_parse_url( $base );
	$origin = $parts['scheme'] . '://' . $parts['host'];

	if ( 0 === strpos( $target, '//' ) ) {
		return $parts['scheme'] . ':' . $target;
	}
	if ( 0 === strpos( $target, '/' ) ) {
		return $origin . $target;
	}

	$dir = isset( $parts['path'] ) ? rtrim( dirname( $parts['path'] ), '/' ) : '';
	return $origin . $dir . '/' . $target;
}

/*
|--------------------------------------------------------------------------
| The scan
|--------------------------------------------------------------------------
*/

/**
 * Run the full readability scan.
 *
 * @param string $url Normalized URL.
 * @return array|WP_Error
 */
function fmg_scan_run( $url ) {
	$page = fmg_scan_fetch( $url );
	if ( is_wp_error( $page ) ) {
		return new WP_Error(
			'fmg_unreachable',
			'That page could not be reached. Check the address, or the site may be blocking automated visitors, which is itself a finding.',
			array( 'status' => 400 )
		);
	}

	if ( $page['status'] >= 400 ) {
		return new WP_Error(
			'fmg_unreachable',
			sprintf( 'That page returned a %d error. AI crawlers see the same thing.', $page['status'] ),
			array( 'status' => 400 )
		);
	}

	$html   = $page['body'];
	$final  = $page['final_url'];
	$parts  = wp_parse_url( $final );
	$origin = $parts['scheme'] . '://' . $parts['host'];

	$dom = fmg_scan_parse_html( $html );
	$ctx = array(
		'url'      => $final,
		'origin'   => $origin,
		'html'     => $html,
		'dom'      => $dom,
		'xpath'    => $dom ? new DOMXPath( $dom ) : null,
		'jsonld'   => fmg_scan_extract_jsonld( $dom ),
		'robots'   => fmg_scan_fetch_text( $origin . '/robots.txt' ),
		'llms'     => fmg_scan_fetch_text( $origin . '/llms.txt' ),
		'scheme'   => $parts['scheme'],
		'hops'     => $page['hops'],
	);

	// Two independently scored axes: can an engine READ you, and is the page
	// shaped to be CITED. Each normalizes to its own 0-100 dial, so the
	// readability number is unchanged by anything on the citability side.
	$readability = array(
		'entity'         => fmg_scan_check_entity( $ctx ),
		'extractability' => fmg_scan_check_extractability( $ctx ),
		'access'         => fmg_scan_check_access( $ctx ),
		'structure'      => fmg_scan_check_structure( $ctx ),
		'freshness'      => fmg_scan_check_freshness( $ctx ),
	);
	$citability = array(
		'citability' => fmg_scan_check_citability( $ctx ),
	);

	$read_score = fmg_scan_tally( $readability );
	$cite_score = fmg_scan_tally( $citability );

	return array(
		'url'         => $final,
		'host'        => $parts['host'],
		'readability' => array(
			'score'      => $read_score,
			'band'       => fmg_scan_band( $read_score ),
			'categories' => $readability,
		),
		'citability'  => array(
			'score'      => $cite_score,
			'band'       => fmg_scan_cite_band( $cite_score ),
			'categories' => $citability,
		),
		'scanned_at'  => gmdate( 'Y-m-d H:i' ) . ' UTC',
		'cached'      => false,
	);
}

/**
 * Sum a category group's earned points and normalize to 0-100.
 * Mutates each category to carry its own 'earned' total.
 *
 * @param array $categories Category group, by reference.
 * @return int
 */
function fmg_scan_tally( &$categories ) {
	$score = 0;
	$max   = 0;
	foreach ( $categories as $key => $cat ) {
		$earned = 0;
		foreach ( $cat['checks'] as $check ) {
			$earned += $check['earned'];
		}
		$categories[ $key ]['earned'] = $earned;
		$score                       += $earned;
		$max                         += $cat['max'];
	}
	return (int) round( ( $score / max( 1, $max ) ) * 100 );
}

/**
 * Band a score.
 *
 * @param int $score Score.
 * @return array
 */
function fmg_scan_band( $score ) {
	if ( $score >= 70 ) {
		return array(
			'label'   => 'Legible',
			'key'     => 'legible',
			'summary' => 'AI engines can read this site. Whether they cite it is the next question, and that one is decided off your own pages.',
		);
	}
	if ( $score >= 40 ) {
		return array(
			'label'   => 'Partly legible',
			'key'     => 'partial',
			'summary' => 'Engines can find this site but have to work for the facts. The gaps below are where they give up and quote someone else.',
		);
	}
	return array(
		'label'   => 'Hard to read',
		'key'     => 'invisible',
		'summary' => 'An AI engine reading this page comes away without a clear picture of who you are or what you claim. That is a fixable problem, and it is where most sites start.',
	);
}

/**
 * Band a citability score.
 *
 * @param int $score Score.
 * @return array
 */
function fmg_scan_cite_band( $score ) {
	if ( $score >= 70 ) {
		return array(
			'label'   => 'Citable',
			'key'     => 'citable',
			'summary' => 'This page hands an engine a name to credit and claims worth lifting. That is the raw material of a citation. Whether engines actually reach for it is what the audit measures.',
		);
	}
	if ( $score >= 40 ) {
		return array(
			'label'   => 'Thin',
			'key'     => 'thin',
			'summary' => 'An engine can read this page but has little reason to name you over a more established source. The gaps below are where the citation goes to someone else.',
		);
	}
	return array(
		'label'   => 'Anonymous',
		'key'     => 'anonymous',
		'summary' => 'No author to credit, and few sourced or self-contained claims to quote. Readable, but nothing an engine would attribute to you by name.',
	);
}

/**
 * Parse HTML into a DOMDocument.
 *
 * @param string $html HTML.
 * @return DOMDocument|null
 */
function fmg_scan_parse_html( $html ) {
	if ( ! class_exists( 'DOMDocument' ) || '' === trim( $html ) ) {
		return null;
	}

	$previous = libxml_use_internal_errors( true );
	$dom      = new DOMDocument();
	$loaded   = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );

	return $loaded ? $dom : null;
}

/**
 * Fetch a plain text resource, returning '' when absent.
 *
 * @param string $url URL.
 * @return string
 */
function fmg_scan_fetch_text( $url ) {
	$response = wp_remote_get(
		$url,
		array(
			'timeout'             => 8,
			'redirection'         => 2,
			'user-agent'          => FMG_SCAN_UA,
			// reject_unsafe_urls also revalidates each followed redirect, so a
			// robots.txt/llms.txt redirect cannot bounce the fetch to an internal host.
			'limit_response_size' => 262144,
			'reject_unsafe_urls'  => true,
		)
	);

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return '';
	}

	$type = wp_remote_retrieve_header( $response, 'content-type' );
	if ( is_string( $type ) && false !== stripos( $type, 'text/html' ) ) {
		return '';
	}

	return substr( (string) wp_remote_retrieve_body( $response ), 0, 200000 );
}

/**
 * Pull every JSON-LD block, flattening @graph.
 *
 * @param DOMDocument|null $dom DOM.
 * @return array
 */
function fmg_scan_extract_jsonld( $dom ) {
	$nodes = array();
	if ( ! $dom ) {
		return $nodes;
	}

	$xpath   = new DOMXPath( $dom );
	$scripts = $xpath->query( '//script[@type="application/ld+json"]' );

	foreach ( $scripts as $script ) {
		$data = json_decode( trim( $script->textContent ), true );
		if ( ! is_array( $data ) ) {
			continue;
		}

		if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			foreach ( $data['@graph'] as $item ) {
				if ( is_array( $item ) ) {
					$nodes[] = $item;
				}
			}
			continue;
		}

		if ( isset( $data[0] ) ) {
			foreach ( $data as $item ) {
				if ( is_array( $item ) ) {
					$nodes[] = $item;
				}
			}
			continue;
		}

		$nodes[] = $data;
	}

	return $nodes;
}

/**
 * Does any JSON-LD node carry one of these @type values.
 *
 * @param array $nodes JSON-LD nodes.
 * @param array $types Types to match.
 * @return array|null
 */
function fmg_scan_find_type( $nodes, $types ) {
	foreach ( $nodes as $node ) {
		if ( empty( $node['@type'] ) ) {
			continue;
		}
		$node_types = (array) $node['@type'];
		foreach ( $node_types as $node_type ) {
			if ( in_array( (string) $node_type, $types, true ) ) {
				return $node;
			}
		}
	}
	return null;
}

/**
 * Build one check row.
 *
 * @param string $id     Slug.
 * @param string $label  Human label.
 * @param bool   $pass   Passed.
 * @param int    $weight Points available.
 * @param string $fix    What to do when failed.
 * @param string $detail Optional observed detail.
 * @return array
 */
function fmg_scan_check( $id, $label, $pass, $weight, $fix, $detail = '' ) {
	return array(
		'id'     => $id,
		'label'  => $label,
		'pass'   => (bool) $pass,
		'weight' => $weight,
		'earned' => $pass ? $weight : 0,
		'fix'    => $fix,
		'detail' => $detail,
	);
}

/*
|--------------------------------------------------------------------------
| Category checks
|--------------------------------------------------------------------------
*/

/**
 * Entity: can an engine tell who this is.
 *
 * @param array $ctx Context.
 * @return array
 */
function fmg_scan_check_entity( $ctx ) {
	$nodes = $ctx['jsonld'];

	$org = fmg_scan_find_type(
		$nodes,
		array( 'Organization', 'LocalBusiness', 'ProfessionalService', 'Corporation', 'NGO', 'Person' )
	);

	$same_as = array();
	foreach ( $nodes as $node ) {
		if ( ! empty( $node['sameAs'] ) ) {
			$same_as = array_merge( $same_as, (array) $node['sameAs'] );
		}
	}
	$same_as = array_unique( $same_as );

	$has_ids = false;
	foreach ( $nodes as $node ) {
		if ( ! empty( $node['@id'] ) ) {
			$has_ids = true;
			break;
		}
	}

	$org_name = '';
	if ( $org && ! empty( $org['name'] ) && is_string( $org['name'] ) ) {
		$org_name = $org['name'];
	}

	$site_name = fmg_scan_meta( $ctx, 'og:site_name' );
	$title     = fmg_scan_title( $ctx );
	$haystack  = strtolower( $site_name . ' ' . $title );
	$name_ok   = '' !== $org_name && false !== strpos( $haystack, strtolower( $org_name ) );

	$checks = array(
		fmg_scan_check(
			'entity_schema',
			'Organization or Person markup',
			(bool) $org,
			6,
			'Add Organization (or Person, for a solo practice) JSON-LD naming who you are, what you do, and where.',
			$org ? 'Found ' . implode( '/', (array) $org['@type'] ) : 'No entity type found'
		),
		fmg_scan_check(
			'entity_sameas',
			'sameAs links to other profiles',
			count( $same_as ) >= 2,
			5,
			'Add a sameAs array linking your LinkedIn, socials, and directory listings so engines can merge those profiles into one entity.',
			count( $same_as ) . ' link(s) found'
		),
		fmg_scan_check(
			'entity_ids',
			'Connected entity graph (@id)',
			$has_ids,
			4,
			'Give your schema nodes @id values and reference them from each other, so the markup describes one entity instead of unrelated fragments.'
		),
		fmg_scan_check(
			'entity_name_match',
			'Name consistent between markup and page',
			$name_ok,
			6,
			'Make the name in your schema match the name in your title and og:site_name exactly. Variants read as different companies.',
			'' !== $org_name ? 'Markup says: ' . $org_name : 'No name in markup'
		),
		fmg_scan_check(
			'entity_llms',
			'llms.txt present',
			'' !== $ctx['llms'],
			4,
			'Publish an llms.txt at your root summarizing what you are and pointing to your key pages. Cheap, and increasingly read.'
		),
	);

	return array(
		'label'  => 'Entity',
		'blurb'  => 'Whether an engine can tell who you are and match you to yourself across the web.',
		'max'    => 25,
		'checks' => $checks,
	);
}

/**
 * Extractability: is there anything quotable.
 *
 * @param array $ctx Context.
 * @return array
 */
function fmg_scan_check_extractability( $ctx ) {
	$text  = '';
	$words = 0;

	if ( $ctx['dom'] ) {
		$clone = clone $ctx['dom'];
		$xp    = new DOMXPath( $clone );
		foreach ( $xp->query( '//script | //style | //noscript' ) as $node ) {
			$node->parentNode->removeChild( $node );
		}
		$body = $clone->getElementsByTagName( 'body' )->item( 0 );
		$text = $body ? trim( preg_replace( '/\s+/', ' ', $body->textContent ) ) : '';
		$words = str_word_count( $text );
	}

	$ratio = strlen( $ctx['html'] ) > 0 ? ( strlen( $text ) / strlen( $ctx['html'] ) ) * 100 : 0;

	$first_para = '';
	if ( $ctx['xpath'] ) {
		foreach ( $ctx['xpath']->query( '//p' ) as $p ) {
			$candidate = trim( preg_replace( '/\s+/', ' ', $p->textContent ) );
			if ( str_word_count( $candidate ) >= 15 ) {
				$first_para = $candidate;
				break;
			}
		}
	}

	$pdf_links = 0;
	if ( $ctx['xpath'] ) {
		foreach ( $ctx['xpath']->query( '//a[@href]' ) as $a ) {
			if ( preg_match( '/\.pdf(\?|#|$)/i', $a->getAttribute( 'href' ) ) ) {
				$pdf_links++;
			}
		}
	}

	$checks = array(
		fmg_scan_check(
			'extract_wordcount',
			'Substantive text in the HTML',
			$words >= 300,
			8,
			'Put real prose on the page. Engines cannot cite a headline and a hero image, and they will not run your JavaScript to find the rest.',
			$words . ' words rendered without JavaScript'
		),
		fmg_scan_check(
			'extract_ratio',
			'Healthy text-to-markup ratio',
			$ratio >= 8,
			6,
			'Too much of this page is markup and script relative to readable content. Trim, or move substance into the served HTML.',
			round( $ratio, 1 ) . '% text'
		),
		fmg_scan_check(
			'extract_answer_first',
			'Answer-first opening paragraph',
			str_word_count( $first_para ) >= 25,
			6,
			'Open with a direct, self-contained statement of what you do, in one paragraph an engine could lift verbatim with attribution.',
			'' !== $first_para ? 'First substantial paragraph: ' . str_word_count( $first_para ) . ' words' : 'No paragraph of 15+ words found'
		),
		fmg_scan_check(
			'extract_pdf',
			'Facts not stranded in PDFs',
			$pdf_links <= 3,
			5,
			'Move the substance out of PDFs and into HTML pages. Keep the PDFs as downloads, never as the only home of a fact.',
			$pdf_links . ' PDF link(s) on this page'
		),
	);

	return array(
		'label'  => 'Extractability',
		'blurb'  => 'Whether the page contains anything an engine could quote and attribute to you.',
		'max'    => 25,
		'checks' => $checks,
	);
}

/**
 * Access: are the crawlers allowed in.
 *
 * @param array $ctx Context.
 * @return array
 */
function fmg_scan_check_access( $ctx ) {
	$robots = $ctx['robots'];
	$agents = array( 'GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-Web', 'PerplexityBot', 'Google-Extended', 'CCBot', 'Applebot-Extended' );

	$blocked = array();
	if ( '' !== $robots ) {
		$lines   = preg_split( '/\r\n|\r|\n/', $robots );
		$current = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			if ( preg_match( '/^user-agent\s*:\s*(.+)$/i', $line, $m ) ) {
				$current[] = trim( $m[1] );
				continue;
			}
			if ( preg_match( '/^disallow\s*:\s*(.*)$/i', $line, $m ) ) {
				$path = trim( $m[1] );
				if ( '/' === $path ) {
					foreach ( $current as $agent ) {
						foreach ( $agents as $known ) {
							if ( 0 === strcasecmp( $agent, $known ) ) {
								$blocked[] = $known;
							}
						}
					}
				}
				$current = array();
				continue;
			}
			if ( preg_match( '/^(allow|crawl-delay|sitemap)\s*:/i', $line ) ) {
				$current = array();
			}
		}
	}
	$blocked = array_unique( $blocked );

	$sitemap_declared = ( '' !== $robots && preg_match( '/^sitemap\s*:/im', $robots ) );
	$sitemap_found    = $sitemap_declared || '' !== fmg_scan_fetch_text( $ctx['origin'] . '/sitemap.xml' );

	$checks = array(
		fmg_scan_check(
			'access_robots',
			'robots.txt reachable',
			'' !== $robots,
			4,
			'Publish a robots.txt. Its absence is not fatal, but it is the first file every crawler asks for.'
		),
		fmg_scan_check(
			'access_ai_agents',
			'AI crawlers not blocked',
			empty( $blocked ),
			8,
			'Your robots.txt turns away the crawlers that feed AI answers. If that is deliberate, keep it and accept the tradeoff. If it came with a plugin default, remove it.',
			empty( $blocked ) ? 'No AI agent blocked at root' : 'Blocked: ' . implode( ', ', $blocked )
		),
		fmg_scan_check(
			'access_sitemap',
			'Sitemap available',
			$sitemap_found,
			4,
			'Publish a sitemap.xml and declare it in robots.txt so crawlers find every page rather than the ones they stumble into.'
		),
		fmg_scan_check(
			'access_https',
			'HTTPS with a clean redirect path',
			'https' === $ctx['scheme'] && $ctx['hops'] <= 1,
			4,
			'Serve over HTTPS and resolve to your canonical address in one hop. Redirect chains lose crawlers and dilute the entity.',
			strtoupper( $ctx['scheme'] ) . ', ' . $ctx['hops'] . ' redirect(s)'
		),
	);

	return array(
		'label'  => 'Access',
		'blurb'  => 'Whether the crawlers behind AI answers are permitted to read you at all.',
		'max'    => 20,
		'checks' => $checks,
	);
}

/**
 * Structure: can a machine navigate the page.
 *
 * @param array $ctx Context.
 * @return array
 */
function fmg_scan_check_structure( $ctx ) {
	$h1 = 0;
	$h2 = 0;
	if ( $ctx['dom'] ) {
		$h1 = $ctx['dom']->getElementsByTagName( 'h1' )->length;
		$h2 = $ctx['dom']->getElementsByTagName( 'h2' )->length;
	}

	$desc     = fmg_scan_meta( $ctx, 'description', 'name' );
	$desc_len = strlen( $desc );

	$qa = fmg_scan_find_type(
		$ctx['jsonld'],
		array(
			// Generic content types.
			'FAQPage', 'QAPage', 'Article', 'BlogPosting', 'HowTo', 'Service', 'Product',
			// Medical content types: a health page declaring what kind of answer
			// it holds. Counted for citability already; credited here too so
			// medical sites are not penalised for using the correct schema.
			'MedicalWebPage', 'MedicalCondition', 'MedicalScholarlyArticle', 'MedicalGuideline', 'Drug',
			// Dataset: research/data pages (e.g. a DOI-anchored dataset).
			'Dataset',
		)
	);

	$checks = array(
		fmg_scan_check(
			'structure_h1',
			'Exactly one H1',
			1 === $h1,
			5,
			'Use one H1 stating what this page is about. Zero leaves the topic implicit; several leave it contested.',
			$h1 . ' H1 element(s)'
		),
		fmg_scan_check(
			'structure_h2',
			'Real subheadings',
			$h2 >= 2,
			5,
			'Break the page into H2 sections whose text names the question each section answers. Engines lift sections, not pages.',
			$h2 . ' H2 element(s)'
		),
		fmg_scan_check(
			'structure_meta',
			'Meta description present and sized',
			$desc_len >= 50 && $desc_len <= 200,
			4,
			'Write a meta description between 50 and 200 characters that states what the page is, in a sentence that would survive being quoted alone.',
			$desc_len > 0 ? $desc_len . ' characters' : 'Missing'
		),
		fmg_scan_check(
			'structure_content_schema',
			'Content-level schema',
			(bool) $qa,
			6,
			'Add content-level schema (FAQPage, Article, MedicalWebPage, Dataset, etc.) so the page declares what kind of answer it holds, not just that it exists.',
			$qa ? 'Found ' . implode( '/', (array) $qa['@type'] ) : 'None found'
		),
	);

	return array(
		'label'  => 'Structure',
		'blurb'  => 'Whether the page is shaped like an answer or like a brochure.',
		'max'    => 20,
		'checks' => $checks,
	);
}

/**
 * Freshness: can an engine date this.
 *
 * @param array $ctx Context.
 * @return array
 */
function fmg_scan_check_freshness( $ctx ) {
	$schema_date = false;
	foreach ( $ctx['jsonld'] as $node ) {
		if ( ! empty( $node['dateModified'] ) || ! empty( $node['datePublished'] ) ) {
			$schema_date = true;
			break;
		}
	}

	$meta_date = '' !== fmg_scan_meta( $ctx, 'article:modified_time' )
		|| '' !== fmg_scan_meta( $ctx, 'article:published_time' );

	$time_el = false;
	if ( $ctx['xpath'] ) {
		$time_el = $ctx['xpath']->query( '//time[@datetime]' )->length > 0;
	}

	$checks = array(
		fmg_scan_check(
			'fresh_schema',
			'Dates in structured data',
			$schema_date,
			5,
			'Include datePublished and dateModified in your schema. Engines discount content they cannot date.'
		),
		fmg_scan_check(
			'fresh_visible',
			'A date a reader can see',
			$meta_date || $time_el,
			5,
			'Show a published or updated date in the markup, using a time element with a datetime attribute.'
		),
	);

	return array(
		'label'  => 'Freshness',
		'blurb'  => 'Whether an engine can tell how current this is.',
		'max'    => 10,
		'checks' => $checks,
	);
}

/**
 * Citability: is the page shaped to be quoted and attributed.
 *
 * This is the on-page raw material of a citation, measured deterministically
 * from the served HTML. It does NOT test whether engines actually cite the
 * site (that is off-page and non-deterministic — the paid audit). Weights sum
 * to 100 so this category is its own 0-100 dial.
 *
 * @param array $ctx Context.
 * @return array
 */
function fmg_scan_check_citability( $ctx ) {
	$nodes = $ctx['jsonld'];
	$body  = fmg_scan_body_text( $ctx );

	// 1. Attribution: a name a citation could point to.
	$author_in_schema = false;
	foreach ( $nodes as $node ) {
		if ( ! empty( $node['author'] ) ) {
			$author_in_schema = true;
			break;
		}
	}
	$person = fmg_scan_find_type( $nodes, array( 'Person' ) );
	$byline = false;
	if ( $ctx['xpath'] ) {
		$q = $ctx['xpath']->query(
			'//*[@rel="author" or @itemprop="author"]'
			. ' | //*[contains(concat(" ", normalize-space(@class), " "), " author ")]'
			. ' | //*[contains(concat(" ", normalize-space(@class), " "), " byline ")]'
		);
		$byline = $q && $q->length > 0;
	}
	$has_attribution = $author_in_schema || (bool) $person || $byline;
	$attr_detail     = $author_in_schema ? 'Author named in structured data'
		: ( $person ? 'Person markup present'
		: ( $byline ? 'Byline found in page markup' : 'No author or byline found' ) );

	// 2. Expertise / E-E-A-T signals.
	$expertise_schema = false;
	foreach ( $nodes as $node ) {
		if ( ! empty( $node['reviewedBy'] ) || ! empty( $node['hasCredential'] )
			|| ( isset( $node['author']['jobTitle'] ) && '' !== $node['author']['jobTitle'] ) ) {
			$expertise_schema = true;
			break;
		}
	}
	$med            = fmg_scan_find_type( $nodes, array( 'MedicalWebPage', 'MedicalScholarlyArticle' ) );
	$expertise_text = (bool) preg_match( '/\b(medically reviewed|reviewed by|fact[- ]checked|board[- ]certified|Ph\.?\s?D|M\.?\s?D\.?|M\.?Sc|R\.?N\.?)\b/i', $body );
	$has_expertise  = $expertise_schema || (bool) $med || $expertise_text;
	$exp_detail     = $expertise_schema ? 'Credentials or reviewer in structured data'
		: ( $med ? 'Medical content markup present'
		: ( $expertise_text ? 'Credential or review language on the page' : 'No expertise or review signals found' ) );

	// 3. Quotable-claim density: self-contained sentences an engine could lift.
	$sentences = preg_split( '/(?<=[.!?])\s+/', $body );
	$quotable  = 0;
	foreach ( (array) $sentences as $sentence ) {
		$sentence = trim( $sentence );
		// Count digit-bearing tokens as words so numeric/dated claims (exactly the
		// citable ones) are not undercounted against the length window.
		$wc       = str_word_count( $sentence, 0, '0123456789' );
		if ( $wc < 8 || $wc > 34 || '?' === substr( $sentence, -1 ) ) {
			continue;
		}
		if ( preg_match( '/\d/', $sentence )
			|| preg_match( '/\b(is|are|was|were|causes?|affects?|means?|includes?|requires?|provides?|refers to|results? in)\b/i', $sentence ) ) {
			$quotable++;
		}
	}
	$has_quotable = $quotable >= 5;

	// 4. Sourced claims: outbound links to authoritative sources.
	$origin_host = (string) wp_parse_url( $ctx['origin'], PHP_URL_HOST );
	$sources     = 0;
	if ( $ctx['xpath'] ) {
		foreach ( $ctx['xpath']->query( '//a[@href]' ) as $a ) {
			$host = (string) wp_parse_url( $a->getAttribute( 'href' ), PHP_URL_HOST );
			if ( '' === $host || 0 === strcasecmp( $host, $origin_host ) ) {
				continue;
			}
			if ( preg_match( '/(\.gov|\.edu|\.ac\.uk|doi\.org|ncbi\.nlm\.nih\.gov|pubmed|nih\.gov|who\.int|wikipedia\.org|orcid\.org|omim\.org|ensembl\.org|clinicalgenome\.org|uniprot\.org|genenames\.org|gnomad\.broadinstitute\.org|cochranelibrary\.com)/i', $host ) ) {
				$sources++;
			}
		}
	}
	$has_sources = $sources >= 1;

	$checks = array(
		fmg_scan_check(
			'cite_attribution',
			'A name a citation can point to',
			$has_attribution,
			30,
			'Name an author or the organization on the page and in schema (author, Person, or a visible byline). Engines attribute to a name; with none, they paraphrase you away.',
			$attr_detail
		),
		fmg_scan_check(
			'cite_expertise',
			'Signals of who is qualified to say it',
			$has_expertise,
			25,
			'Show expertise the way engines weigh it: author credentials, a named reviewer, or medical/editorial review markup. In regulated topics this is decisive.',
			$exp_detail
		),
		fmg_scan_check(
			'cite_quotable',
			'Self-contained, quotable claims',
			$has_quotable,
			25,
			'Write more sentences that stand alone as a fact an engine could lift verbatim. Definitions and specific, dated, numeric claims travel; hedged marketing prose does not.',
			$quotable . ' quotable claim(s) detected'
		),
		fmg_scan_check(
			'cite_sources',
			'Claims backed by authoritative sources',
			$has_sources,
			20,
			'Link the claims that matter to primary or authoritative sources (.gov, .edu, journals, DOIs). Pages that cite tend to get cited.',
			$sources . ' authoritative source link(s)'
		),
	);

	return array(
		'label'  => 'Citability',
		'blurb'  => 'Whether the page gives an engine a name to credit and claims worth quoting: the on-page raw material of a citation. The live "does it actually get cited" test is the audit.',
		'max'    => 100,
		'checks' => $checks,
	);
}

/**
 * Extract visible body text with script/style/noscript stripped.
 *
 * @param array $ctx Context.
 * @return string
 */
function fmg_scan_body_text( $ctx ) {
	if ( ! $ctx['dom'] ) {
		return '';
	}
	$clone = clone $ctx['dom'];
	$xp    = new DOMXPath( $clone );
	foreach ( $xp->query( '//script | //style | //noscript' ) as $node ) {
		$node->parentNode->removeChild( $node );
	}
	$body = $clone->getElementsByTagName( 'body' )->item( 0 );
	return $body ? trim( preg_replace( '/\s+/', ' ', $body->textContent ) ) : '';
}

/**
 * Read a meta tag.
 *
 * @param array  $ctx  Context.
 * @param string $key  Property or name value.
 * @param string $attr Which attribute to match on.
 * @return string
 */
function fmg_scan_meta( $ctx, $key, $attr = 'property' ) {
	if ( ! $ctx['xpath'] ) {
		return '';
	}

	$nodes = $ctx['xpath']->query( sprintf( '//meta[@%s="%s"]/@content', $attr, $key ) );
	if ( $nodes->length > 0 ) {
		return trim( $nodes->item( 0 )->nodeValue );
	}

	$fallback = 'property' === $attr ? 'name' : 'property';
	$nodes    = $ctx['xpath']->query( sprintf( '//meta[@%s="%s"]/@content', $fallback, $key ) );

	return $nodes->length > 0 ? trim( $nodes->item( 0 )->nodeValue ) : '';
}

/**
 * Read the document title.
 *
 * @param array $ctx Context.
 * @return string
 */
function fmg_scan_title( $ctx ) {
	if ( ! $ctx['dom'] ) {
		return '';
	}
	$nodes = $ctx['dom']->getElementsByTagName( 'title' );
	return $nodes->length > 0 ? trim( $nodes->item( 0 )->textContent ) : '';
}

/*
|--------------------------------------------------------------------------
| Front end
|--------------------------------------------------------------------------
*/

add_shortcode( 'fmg_readability_scan', 'fmg_scan_shortcode' );

/**
 * Render the scanner.
 *
 * @return string
 */
function fmg_scan_shortcode() {
	wp_register_style( 'fmg-scan', false, array(), '1.0.0' );
	wp_enqueue_style( 'fmg-scan' );
	wp_add_inline_style( 'fmg-scan', fmg_scan_css() );

	wp_register_script( 'fmg-scan', '', array(), '1.0.0', true );
	wp_enqueue_script( 'fmg-scan' );
	wp_add_inline_script(
		'fmg-scan',
		'window.FMG_SCAN = ' . wp_json_encode(
			array(
				'endpoint' => esc_url_raw( rest_url( 'fmg/v1/scan' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'contact'  => FMG_SCAN_CONTACT,
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
				One page, read the way an AI crawler reads it. No account, no email required.
				You get two scores: whether engines <em>can</em> read you, and whether your page is
				shaped to be <em>cited</em>. The live test of who actually gets named is the audit.
			</p>
		</form>
		<div class="fmg-scan__status" role="status" aria-live="polite"></div>
		<div class="fmg-scan__results" hidden></div>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Scanner styles, built on the Grove theme tokens.
 *
 * @return string
 */
function fmg_scan_css() {
	return <<<CSS
.fmg-scan {
  --fmg-ink: #17211d; --fmg-ink-soft: #4d5a55;
  --fmg-paper: #f8f8f5; --fmg-paper-alt: #eef0e9;
  --fmg-accent: #156a58; --fmg-accent-hover: #0e5244;
  --fmg-dark: #14201e; --fmg-dark-text: #bcc7c3;
  --fmg-apricot: #f4a860; --fmg-line: #dde1d9;
  --fmg-fail: #8a3416;
  max-width: 760px; margin: 0 auto; color: var(--fmg-ink);
}
.fmg-scan .sr-only {
  position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
  overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0;
}
.fmg-score__band:focus-visible { outline: 3px solid var(--fmg-apricot); outline-offset: 4px; }
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
.fmg-scan__status { margin-top: 18px; font-size: .95rem; color: var(--fmg-ink-soft); }
.fmg-scan__status[data-state="error"] { color: var(--fmg-fail); font-weight: 500; }

.fmg-scan__results { margin-top: 34px; }
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
.fmg-score__copy { flex: 1 1 300px; min-width: 240px; }
.fmg-score__eyebrow {
  margin: 0 0 6px; font-size: .72rem; font-weight: 700; letter-spacing: .16em;
  text-transform: uppercase; color: var(--fmg-apricot);
}
.fmg-cats + .fmg-score { margin-top: 30px; }
.fmg-score__band { font-size: 1.45rem; font-weight: 700; letter-spacing: -.02em; margin: 0 0 8px; }
.fmg-score__summary { margin: 0; color: var(--fmg-dark-text); font-size: 1rem; }
.fmg-score__meta { margin: 14px 0 0; font-size: .8rem; color: rgba(255,255,255,.5); word-break: break-word; }

.fmg-cats { margin-top: 26px; display: grid; gap: 12px; }
.fmg-cat { border: 1px solid var(--fmg-line); border-radius: 12px; background: var(--fmg-paper); overflow: hidden; }
.fmg-cat__head {
  display: flex; align-items: baseline; justify-content: space-between; gap: 14px;
  padding: 18px 22px 8px;
}
.fmg-cat__name { font-weight: 700; font-size: 1.08rem; margin: 0; }
.fmg-cat__pts { font-size: .85rem; color: var(--fmg-ink-soft); white-space: nowrap; }
.fmg-cat__blurb { margin: 0; padding: 0 22px 12px; font-size: .88rem; color: var(--fmg-ink-soft); }
.fmg-cat__bar { height: 4px; background: var(--fmg-paper-alt); margin: 0 22px 16px; border-radius: 2px; }
.fmg-cat__fill { height: 100%; background: var(--fmg-accent); border-radius: 2px; transition: width .8s ease; }
.fmg-checks { list-style: none; margin: 0; padding: 0 22px 18px; display: grid; gap: 12px; }
.fmg-check { display: grid; grid-template-columns: 22px 1fr; gap: 12px; align-items: start; }
.fmg-check__mark { font-weight: 700; line-height: 1.5; }
.fmg-check--pass .fmg-check__mark { color: var(--fmg-accent); }
.fmg-check--fail .fmg-check__mark { color: var(--fmg-fail); }
.fmg-check__label { font-weight: 600; font-size: .96rem; }
.fmg-check__detail { font-size: .84rem; color: var(--fmg-ink-soft); margin-top: 2px; }
.fmg-check__fix {
  font-size: .88rem; color: var(--fmg-ink-soft); margin-top: 6px;
  border-left: 3px solid var(--fmg-apricot); padding-left: 12px;
}

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
.fmg-cta__btn--ghost { background: transparent; color: var(--fmg-accent); border: 1px solid #c2c8bc; }
.fmg-cta__btn--ghost:hover { background: transparent; color: var(--fmg-accent-hover); border-color: var(--fmg-accent); }
.fmg-cta__btn:focus-visible { outline: 3px solid var(--fmg-accent); outline-offset: 2px; }
.fmg-cta__hint { font-size: .82rem; color: var(--fmg-ink-soft); margin: 14px 0 0; }

@media (max-width: 560px) {
  .fmg-score { padding: 28px 22px; gap: 22px; }
  .fmg-cta { padding: 26px 22px; }
}
@media (prefers-reduced-motion: reduce) {
  .fmg-score__arc, .fmg-cat__fill { transition: none; }
}
CSS;
}

/**
 * Scanner behavior.
 *
 * @return string
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
          setStatus((res.body && res.body.message) || 'That scan did not complete. Try again in a moment.', 'error');
          return;
        }
        setStatus('', 'done');
        render(res.body);
      })
      .catch(function () {
        setStatus('That scan did not complete. Check the address and try again.', 'error');
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

  function scoreCard(group, eyebrow, meta) {
    var C = 2 * Math.PI * 58;
    var offset = C - (C * group.score) / 100;
    return '<div class="fmg-score">'
      + '<div class="fmg-score__dial">'
      + '<svg viewBox="0 0 132 132" aria-hidden="true">'
      + '<circle class="fmg-score__track" cx="66" cy="66" r="58" fill="none" stroke-width="9"></circle>'
      + '<circle class="fmg-score__arc" cx="66" cy="66" r="58" fill="none" stroke-width="9" '
      + 'stroke-dasharray="' + C + '" stroke-dashoffset="' + C + '" data-offset="' + offset + '"></circle>'
      + '</svg>'
      + '<div class="fmg-score__num">' + group.score + '</div>'
      + '</div>'
      + '<div class="fmg-score__copy">'
      + '<p class="fmg-score__eyebrow">' + esc(eyebrow) + '</p>'
      + '<p class="fmg-score__band">' + esc(group.band.label)
      + '<span class="sr-only"> ' + esc(eyebrow) + ' score, ' + group.score + ' out of 100</span></p>'
      + '<p class="fmg-score__summary">' + esc(group.band.summary) + '</p>'
      + (meta ? '<p class="fmg-score__meta">' + meta + '</p>' : '')
      + '</div></div>';
  }

  function catsHtml(categories) {
    var html = '<div class="fmg-cats">';
    Object.keys(categories).forEach(function (key) {
      var cat = categories[key];
      var pct = Math.round((cat.earned / cat.max) * 100);
      html += '<section class="fmg-cat">'
        + '<div class="fmg-cat__head"><h3 class="fmg-cat__name">' + esc(cat.label) + '</h3>'
        + '<span class="fmg-cat__pts">' + cat.earned + ' of ' + cat.max + '</span></div>'
        + '<p class="fmg-cat__blurb">' + esc(cat.blurb) + '</p>'
        + '<div class="fmg-cat__bar"><div class="fmg-cat__fill" style="width:0%" data-pct="' + pct + '"></div></div>'
        + '<ul class="fmg-checks">';
      cat.checks.forEach(function (c) {
        html += '<li class="fmg-check ' + (c.pass ? 'fmg-check--pass' : 'fmg-check--fail') + '">'
          + '<span class="fmg-check__mark" aria-hidden="true">' + (c.pass ? '&#10003;' : '&#10007;') + '</span>'
          + '<div><div class="fmg-check__label">' + esc(c.label)
          + '<span class="sr-only">: ' + (c.pass ? 'pass' : 'needs work') + '</span></div>'
          + (c.detail ? '<div class="fmg-check__detail">' + esc(c.detail) + '</div>' : '')
          + (c.pass ? '' : '<div class="fmg-check__fix">' + esc(c.fix) + '</div>')
          + '</div></li>';
      });
      html += '</ul></section>';
    });
    return html + '</div>';
  }

  function render(data) {
    var meta = esc(data.url) + ' &middot; scanned ' + esc(data.scanned_at);
    var html = scoreCard(data.readability, 'Readability', meta)
      + catsHtml(data.readability.categories)
      + scoreCard(data.citability, 'Citability', '')
      + catsHtml(data.citability.categories);

    html += ''
      + '<div class="fmg-cta">'
      + '<h3>Two scores a crawler can see. One it cannot.</h3>'
      + '<p>Readability says engines <em>can</em> read you. Citability says your page is <em>shaped</em> to be '
      + 'quoted. Whether an engine actually names you when a buyer asks is the third thing, and it is measured '
      + 'differently: real questions, run across the top engines, scored against your competitors. '
      + 'Send us this result and we will tell you what it means for your market.</p>'
      + '<div class="fmg-cta__actions">'
      + '<a class="fmg-cta__btn" id="fmg-mailto" href="#">Send these results to Fernwell</a>'
      + '<button type="button" class="fmg-cta__btn fmg-cta__btn--ghost" id="fmg-copy">Copy results</button>'
      + '</div>'
      + '<p class="fmg-cta__hint">Opens your email app with the summary already written. Nothing is sent until you send it.</p>'
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
    var band = results.querySelector('.fmg-score__band');
    band.setAttribute('tabindex', '-1');
    band.focus();
  }

  function summarize(data) {
    var lines = [];
    lines.push('AI Readability Score: ' + data.readability.score + '/100 (' + data.readability.band.label + ')');
    lines.push('AI Citability Score: ' + data.citability.score + '/100 (' + data.citability.band.label + ')');
    lines.push('Site: ' + data.url);
    lines.push('Scanned: ' + data.scanned_at);
    lines.push('');

    var failed = [];
    function group(label, axis) {
      var scores = [];
      Object.keys(axis.categories).forEach(function (key) {
        var cat = axis.categories[key];
        scores.push(cat.label + ' ' + cat.earned + '/' + cat.max);
        cat.checks.forEach(function (c) {
          if (!c.pass) failed.push('- ' + cat.label + ': ' + c.label + (c.detail ? ' (' + c.detail + ')' : ''));
        });
      });
      lines.push(label + ' categories: ' + scores.join(' | '));
    }
    group('Readability', data.readability);
    group('Citability', data.citability);

    lines.push('');
    if (failed.length) {
      lines.push('Failed checks (' + failed.length + '):');
      lines = lines.concat(failed);
    } else {
      lines.push('All checks passed.');
    }
    lines.push('');
    lines.push('I would like to know what this means for how AI engines answer questions in my market.');
    lines.push('');
    lines.push('My three biggest competitors:');
    lines.push('1.');
    lines.push('2.');
    lines.push('3.');

    return lines.join('\n');
  }

  function wireActions(data) {
    var body = summarize(data);
    var subject = 'AI Visibility: ' + data.readability.score + ' readable / ' + data.citability.score + ' citable for ' + data.host;
    var mailto = 'mailto:' + window.FMG_SCAN.contact
      + '?subject=' + encodeURIComponent(subject)
      + '&body=' + encodeURIComponent(body);

    var link = document.getElementById('fmg-mailto');
    if (mailto.length > 1900) {
      // Some mail clients truncate long mailto links. Trim to the score plus failures.
      var short = body.split('\n').slice(0, 12).join('\n')
        + '\n\n(Full results copied separately.)';
      mailto = 'mailto:' + window.FMG_SCAN.contact
        + '?subject=' + encodeURIComponent(subject)
        + '&body=' + encodeURIComponent(short);
    }
    link.setAttribute('href', mailto);

    var copy = document.getElementById('fmg-copy');
    copy.addEventListener('click', function () {
      var payload = subject + '\n\n' + body;
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
