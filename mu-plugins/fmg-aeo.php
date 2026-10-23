<?php
/**
 * Plugin Name: FMG AEO
 * Description: Serves /sitemap.xml with an FMG-branded stylesheet, declares it in robots.txt, and retires core's wp-sitemap.xml.
 * Version:     0.2.0
 * Author:      Fernwell Media Group
 *
 * Drop this file directly into wp-content/mu-plugins/ (no subfolder: mu-plugins
 * only autoloads top-level PHP files).
 *
 * The plugin is generic on purpose: it lists published pages and posts and
 * exposes filters. Site-specific rules (which posts are opted out of
 * indexing, what lastmod the dynamic pages report) live in the theme and
 * hook in through:
 *
 *   fmg_aeo_sitemap_post_types  ( array $post_types )
 *   fmg_aeo_sitemap_query_args  ( array $args, string $post_type )
 *   fmg_aeo_sitemap_entry       ( array|false $entry, WP_Post $post )
 *   fmg_aeo_sitemap_entries     ( array $entries )
 *   fmg_aeo_sitemap_max_urls    ( int $max )         URLs per file, 50000 by default
 *   fmg_aeo_stylesheet_css_url  ( string $url )      theme stylesheet the browser view loads
 *   fmg_aeo_logo_url            ( string $url )      lockup the browser view shows
 *
 * @package Fernwell
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin version, read from the header above so there is one place to bump.
 * Only called while serving a sitemap surface.
 */
function fmg_aeo_version() {
	static $version = null;
	if ( null === $version ) {
		$data    = get_file_data( __FILE__, array( 'Version' => 'Version' ) );
		$version = $data['Version'] ? $data['Version'] : '0';
	}
	return $version;
}

/*
 * Core sitemaps off. WordPress keeps its wp-sitemap rewrite rules registered
 * so those URLs would 404; fmg_aeo_retire_core_sitemap() turns them into
 * 301s to /sitemap.xml instead, so anything that already learned the old
 * address lands on the new one.
 */
add_filter( 'wp_sitemaps_enabled', '__return_false' );

/*
 * Routes: /sitemap.xml, /sitemap-N.xml (only used once the site outgrows one
 * file), and /sitemap.xsl, registered at the top so they win over core's
 * legacy sitemap\.xml rule. The rules keep WordPress from treating these
 * URLs as 404s; what gets served is decided from the request path alone.
 */
function fmg_aeo_rewrites() {
	add_rewrite_rule( '^sitemap\.xml$', 'index.php?fmg_sitemap=xml', 'top' );
	add_rewrite_rule( '^sitemap-([1-9][0-9]*)\.xml$','index.php?fmg_sitemap=xml&fmg_sitemap_page=$matches[1]', 'top' );
	add_rewrite_rule( '^sitemap\.xsl$', 'index.php?fmg_sitemap=xsl', 'top' );
}
add_action( 'init', 'fmg_aeo_rewrites' );

/*
 * Core's sitemap query vars are dropped too: with core sitemaps off they
 * only let a "sitemap" parameter on any URL trigger core's sitemap
 * handling. The old wp-sitemap paths are still caught by path below.
 */
function fmg_aeo_query_vars( $vars ) {
	$vars   = array_diff( $vars, array( 'sitemap', 'sitemap-subtype', 'sitemap-stylesheet' ) );
	$vars[] = 'fmg_sitemap';
	$vars[] = 'fmg_sitemap_page';
	return array_values( $vars );
}
add_filter( 'query_vars', 'fmg_aeo_query_vars' );

/*
 * Flush rewrite rules whenever a rule added at the top (these, and the
 * theme's llms.txt rule) is missing from the saved set, so a deploy never
 * needs a manual permalink save. The signature of the wanted rules is
 * stored after each flush: if another plugin filters a rule out, the same
 * set is never flushed twice, so this cannot turn into a flush on every
 * request.
 */
function fmg_aeo_maybe_flush() {
	global $wp_rewrite;
	$wanted = $wp_rewrite->extra_rules_top;
	$saved  = get_option( 'rewrite_rules' );
	if ( is_array( $saved ) && ! array_diff_assoc( $wanted, $saved ) ) {
		return;
	}
	$signature = md5( wp_json_encode( $wanted ) );
	if ( get_option( 'fmg_aeo_rewrite_sig' ) === $signature ) {
		return;
	}
	flush_rewrite_rules( false );
	update_option( 'fmg_aeo_rewrite_sig', $signature );
	delete_option( 'fmg_aeo_rewrite_version' );
}
add_action( 'wp_loaded', 'fmg_aeo_maybe_flush' );

/**
 * The request path relative to the home URL, so a site installed in a
 * subdirectory matches the same surfaces as one at the root.
 */
function fmg_aeo_request_path() {
	$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
	if ( ! is_string( $path ) ) {
		return '';
	}
	$base = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
	if ( '' !== $base && 0 === strpos( $path, $base . '/' ) ) {
		$path = substr( $path, strlen( $base ) );
	}
	return $path;
}

/**
 * The canonical sitemap surface a path asks for (/sitemap.xml,
 * /sitemap-N.xml, /sitemap.xsl, with trailing slashes stripped), or an
 * empty string for every other path.
 */
function fmg_aeo_surface_path( $path ) {
	return preg_match( '#^/(?:sitemap(?:-[1-9][0-9]*)?\.xml|sitemap\.xsl)/*$#', $path ) ? rtrim( $path, '/' ) : '';
}

/**
 * Whether the request arrived on the home URL's host (and port). Default
 * ports are ignored, so "example.com:443" counts as "example.com".
 */
function fmg_aeo_on_canonical_host() {
	$home = wp_parse_url( home_url() );
	$want = strtolower( $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) );
	$have = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : $want;
	return preg_replace( '/:(?:80|443)$/', '', $have ) === preg_replace( '/:(?:80|443)$/', '', $want );
}

/*
 * Serve. Runs at priority 0, ahead of the canonical redirect, so this
 * handler does that job itself: the slashed form, and any other host
 * (www, a preview domain), 301 to the bare path on the home URL's host.
 * A fmg_sitemap query var on any other URL is ignored and that URL renders
 * as usual.
 */
function fmg_aeo_serve() {
	$path    = fmg_aeo_request_path();
	$surface = fmg_aeo_surface_path( $path );
	if ( '' === $surface ) {
		return;
	}
	if ( $surface !== $path || ! fmg_aeo_on_canonical_host() ) {
		wp_safe_redirect( home_url( $surface ), 301 );
		exit;
	}

	if ( '/sitemap.xsl' === $surface ) {
		fmg_aeo_send( 'text/xsl', fmg_aeo_stylesheet() );
	}

	$entries = fmg_aeo_cached_entries();
	$max     = max( 1, (int) apply_filters( 'fmg_aeo_sitemap_max_urls', 50000 ) );
	$chunks  = $entries ? array_chunk( $entries, $max ) : array( array() );
	$page    = preg_match( '#^/sitemap-([0-9]+)\.xml$#', $surface, $m ) ? (int) $m[1] : 0;

	if ( 0 === $page ) {
		$xml = count( $chunks ) > 1 ? fmg_aeo_sitemap_index_xml( $chunks ) : fmg_aeo_sitemap_xml( $chunks[0] );
		fmg_aeo_send( 'application/xml', $xml );
	}
	if ( count( $chunks ) > 1 && isset( $chunks[ $page - 1 ] ) ) {
		fmg_aeo_send( 'application/xml', fmg_aeo_sitemap_xml( $chunks[ $page - 1 ] ) );
	}

	// A numbered file that does not exist (or any numbered file while the
	// site fits in one): render the theme's 404.
	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	nocache_headers();
}
add_action( 'template_redirect', 'fmg_aeo_serve', 0 );

/**
 * Send one surface and stop. Anything already buffered (a BOM or stray
 * whitespace from another file, a notice) is discarded first, because a
 * single byte before the XML declaration breaks the whole document. The
 * ETag is the body's hash, so a conditional request gets a 304 only when
 * the document is byte-for-byte unchanged, deletions included.
 */
function fmg_aeo_send( $type, $body ) {
	while ( ob_get_level() > 0 && ob_end_clean() ) {
		// Drain every buffer that allows it.
	}
	$etag = '"' . md5( $body ) . '"';
	status_header( 200 );
	header( 'Content-Type: ' . $type . '; charset=UTF-8' );
	header( 'X-Robots-Tag: noindex, follow', true );
	header( 'Cache-Control: public, max-age=600' );
	header( 'ETag: ' . $etag );
	$match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) : '';
	if ( '' !== $match && in_array( $etag, array_map( 'trim', explode( ',', $match ) ), true ) ) {
		status_header( 304 );
		exit;
	}
	echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped above.
	exit;
}

/*
 * Old core sitemap addresses (wp-sitemap.xml, wp-sitemap-posts-post-1.xml,
 * wp-sitemap.xsl, ...) 301 to /sitemap.xml. Matched on the path, so a
 * "sitemap" parameter on any other URL is left alone.
 */
function fmg_aeo_retire_core_sitemap() {
	if ( preg_match( '#^/wp-sitemap[a-z0-9-]*\.(?:xml|xsl)/*$#', fmg_aeo_request_path() ) ) {
		wp_safe_redirect( home_url( '/sitemap.xml' ), 301 );
		exit;
	}
}
add_action( 'template_redirect', 'fmg_aeo_retire_core_sitemap', 0 );

/*
 * robots.txt: a curated, version-controlled crawler policy served through
 * the core filter (no physical file in the web root, which would override
 * this). Search, AI, answer-engine, and training crawlers are welcomed by
 * name so the site can be found and cited. Admin and internal
 * search stay out of the crawl, and parasitic SEO scrapers are turned
 * away. Runs last (PHP_INT_MAX) and returns the whole document. Honors
 * "Discourage search engines": when the site is private, core's default
 * output is left alone. Disallow only binds compliant crawlers; abusive
 * ones ignore robots.txt and belong at the host or CDN.
 *
 * A crawler obeys only the most specific group that names it and never
 * merges it with "User-agent: *", so the welcomed group repeats the
 * default group's Disallow lines. Keep the two in step.
 */
function fmg_aeo_robots( $output, $is_public ) {
	if ( ! $is_public ) {
		return $output;
	}

	$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

	$body = <<<'ROBOTS'
# Fernwell Media Group (%%HOST%%)
# Answer Engine Optimization research and education. Crawlers are welcome,
# including AI, answer-engine, and training crawlers. We want this content
# found, cited, and named in answers; this is the point.
# LLM guidance: %%LLMS%%

# Default: everyone allowed, minus admin and internal search
User-agent: *
Allow: /
Disallow: /wp-admin/
Allow: /wp-admin/admin-ajax.php
Disallow: /*?s=
Disallow: /*&s=
Disallow: /search/

# Explicitly welcomed: AI, answer engines, and training
User-agent: GPTBot
User-agent: OAI-SearchBot
User-agent: ChatGPT-User
User-agent: ClaudeBot
User-agent: Claude-SearchBot
User-agent: Claude-User
User-agent: anthropic-ai
User-agent: Claude-Web
User-agent: Google-Extended
User-agent: Google-CloudVertexBot
User-agent: Applebot
User-agent: Applebot-Extended
User-agent: Bingbot
User-agent: Meta-ExternalAgent
User-agent: PerplexityBot
User-agent: Perplexity-User
User-agent: Amazonbot
User-agent: DuckAssistBot
User-agent: MistralAI-User
User-agent: cohere-ai
User-agent: YouBot
User-agent: CCBot
User-agent: Bytespider
User-agent: xAI-Grok
User-agent: GrokBot
Allow: /
Disallow: /wp-admin/
Allow: /wp-admin/admin-ajax.php
Disallow: /*?s=
Disallow: /*&s=
Disallow: /search/

# Explicitly disallowed: parasitic SEO/scraper crawlers (no benefit to us)
User-agent: AhrefsBot
User-agent: SemrushBot
User-agent: MJ12bot
User-agent: DotBot
User-agent: rogerbot
User-agent: BLEXBot
User-agent: DataForSeoBot
User-agent: Barkrowler
User-agent: MegaIndex
Disallow: /

# No ad crawler (no ad landing pages)
User-agent: AdsBot-Google
Disallow: /

Sitemap: %%SITEMAP%%
ROBOTS;

	// The nowdoc inherits this file's line endings; serve LF regardless.
	return str_replace(
		array( "\r\n", '%%HOST%%', '%%LLMS%%', '%%SITEMAP%%' ),
		array( "\n", $host, home_url( '/llms.txt' ), home_url( '/sitemap.xml' ) ),
		$body
	) . "\n";
}
add_filter( 'robots_txt', 'fmg_aeo_robots', PHP_INT_MAX, 2 );

/*
 * Site Health: confirm the policy above is what crawlers actually get. A
 * physical robots.txt in the site root is served by the web server before
 * WordPress runs; a host, CDN, or another plugin can also rewrite the file
 * on the way out. Only checked while the site is public, since the policy
 * steps aside otherwise.
 */
function fmg_aeo_site_health_tests( $tests ) {
	if ( get_option( 'blog_public' ) ) {
		$tests['direct']['fmg_aeo_robots'] = array(
			'label' => 'FMG AEO robots.txt',
			'test'  => 'fmg_aeo_robots_health',
		);
	}
	return $tests;
}
add_filter( 'site_status_tests', 'fmg_aeo_site_health_tests' );

function fmg_aeo_robots_health() {
	$result = array(
		'label'       => 'robots.txt serves the FMG AEO crawler policy.',
		'status'      => 'good',
		'badge'       => array( 'label' => 'Crawlers', 'color' => 'blue' ),
		'description' => '',
		'actions'     => '',
		'test'        => 'fmg_aeo_robots',
	);

	if ( file_exists( ABSPATH . 'robots.txt' ) ) {
		$result['status'] = 'critical';
		$result['label']  = 'A robots.txt file in the site root replaces the FMG AEO crawler policy.';
		return $result;
	}

	$response = wp_remote_get( home_url( '/robots.txt' ), array(
		'timeout'   => 5,
		'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
	) );
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		$result['status'] = 'recommended';
		$result['label']  = 'Site Health couldn\'t load robots.txt to compare it with the FMG AEO policy.';
		return $result;
	}

	$served   = trim( str_replace( "\r\n", "\n", wp_remote_retrieve_body( $response ) ) );
	$expected = trim( fmg_aeo_robots( '', true ) );
	if ( $served !== $expected ) {
		$result['status'] = 'recommended';
		$result['label']  = 'The robots.txt this site serves doesn\'t match the FMG AEO policy. A host, CDN, or another plugin may be changing it.';
	}
	return $result;
}

/*
 * Let the sitemap through the Password Protected gate, as the theme does
 * for llms.txt. No effect when that plugin is inactive.
 */
function fmg_aeo_sitemap_public( $is_active ) {
	return '' !== fmg_aeo_surface_path( fmg_aeo_request_path() ) ? false : $is_active;
}
add_filter( 'password_protected_is_active', 'fmg_aeo_sitemap_public' );

/**
 * A GMT database date as W3C in the site's timezone, or an empty string
 * for a missing or zero date (0000-00-00 would otherwise come out as the
 * year -0001).
 */
function fmg_aeo_w3c( $gmt ) {
	if ( ! $gmt || 0 === strpos( $gmt, '0000-00-00' ) ) {
		return '';
	}
	$ts = strtotime( $gmt . ' UTC' );
	return false === $ts ? '' : wp_date( DATE_W3C, $ts );
}

/**
 * Normalize one entry from a filter: an http(s) loc is required, lastmod
 * is kept only when it parses as a date. Anything else drops the URL.
 */
function fmg_aeo_clean_entry( $entry ) {
	if ( ! is_array( $entry ) || empty( $entry['loc'] ) || ! is_string( $entry['loc'] ) ) {
		return false;
	}
	$loc = esc_url_raw( $entry['loc'], array( 'http', 'https' ) );
	if ( '' === $loc ) {
		return false;
	}
	$clean = array( 'loc' => $loc );
	if ( ! empty( $entry['lastmod'] ) && is_string( $entry['lastmod'] ) && false !== strtotime( $entry['lastmod'] ) ) {
		$clean['lastmod'] = $entry['lastmod'];
	}
	return $clean;
}

/**
 * The URL list: homepage first, then pages in creation order, then posts
 * newest first. Each entry is loc + lastmod (W3C); no changefreq or
 * priority, which engines ignore. A filter returning an empty entry drops
 * the URL.
 */
function fmg_aeo_sitemap_entries() {
	$entries    = array();
	$home       = home_url( '/' );
	$post_types = (array) apply_filters( 'fmg_aeo_sitemap_post_types', array( 'page', 'post' ) );

	foreach ( $post_types as $post_type ) {
		$args = array(
			'post_type'    => $post_type,
			'post_status'  => 'publish',
			'numberposts'  => -1,
			'has_password' => false,
			'orderby'      => 'date',
			'order'        => 'page' === $post_type ? 'ASC' : 'DESC',
		);
		$args = apply_filters( 'fmg_aeo_sitemap_query_args', $args, $post_type );

		foreach ( get_posts( $args ) as $post ) {
			$entry   = array( 'loc' => get_permalink( $post ) );
			$lastmod = fmg_aeo_w3c( $post->post_modified_gmt );
			$lastmod = $lastmod ? $lastmod : fmg_aeo_w3c( $post->post_date_gmt );
			if ( $lastmod ) {
				$entry['lastmod'] = $lastmod;
			}
			$entry = fmg_aeo_clean_entry( apply_filters( 'fmg_aeo_sitemap_entry', $entry, $post ) );
			if ( $entry ) {
				$entries[] = $entry;
			}
		}
	}

	// Homepage first. When the front page shows latest posts it has no page
	// row, so add one dated to the newest post.
	$front = array_filter( $entries, function ( $entry ) use ( $home ) {
		return $entry['loc'] === $home;
	} );
	if ( $front ) {
		$entries = array_merge( array_values( $front ), array_values( array_diff_key( $entries, $front ) ) );
	} else {
		$newest = get_posts( array( 'numberposts' => 1, 'post_status' => 'publish', 'orderby' => 'modified', 'order' => 'DESC' ) );
		$entry  = array( 'loc' => $home );
		if ( $newest && fmg_aeo_w3c( $newest[0]->post_modified_gmt ) ) {
			$entry['lastmod'] = fmg_aeo_w3c( $newest[0]->post_modified_gmt );
		}
		array_unshift( $entries, $entry );
	}

	$entries = apply_filters( 'fmg_aeo_sitemap_entries', $entries );
	return is_array( $entries ) ? array_values( array_filter( array_map( 'fmg_aeo_clean_entry', $entries ) ) ) : array();
}

/*
 * The URL list is cached for an hour and dropped whenever a post changes
 * or a setting that shapes URLs does. The key carries the plugin version,
 * so a deploy of this file starts fresh; a theme-only change to the
 * sitemap filters shows within the hour, or at once after
 * wp transient delete fmg_aeo_entries.
 */
function fmg_aeo_cached_entries() {
	$cached = get_transient( 'fmg_aeo_entries' );
	if ( is_array( $cached ) && isset( $cached['v'], $cached['e'] ) && fmg_aeo_version() === $cached['v'] ) {
		return $cached['e'];
	}
	$entries = fmg_aeo_sitemap_entries();
	set_transient( 'fmg_aeo_entries', array( 'v' => fmg_aeo_version(), 'e' => $entries ), HOUR_IN_SECONDS );
	return $entries;
}

function fmg_aeo_purge() {
	delete_transient( 'fmg_aeo_entries' );
}
add_action( 'clean_post_cache', 'fmg_aeo_purge' );
add_action( 'switch_theme', 'fmg_aeo_purge' );
foreach ( array( 'show_on_front', 'page_on_front', 'page_for_posts', 'permalink_structure', 'home', 'timezone_string', 'gmt_offset' ) as $fmg_aeo_option ) {
	add_action( 'update_option_' . $fmg_aeo_option, 'fmg_aeo_purge' );
}
unset( $fmg_aeo_option );

function fmg_aeo_xml_esc( $value ) {
	return htmlspecialchars( (string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
}

function fmg_aeo_sitemap_head() {
	$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	$xml .= '<?xml-stylesheet type="text/xsl" href="' . fmg_aeo_xml_esc( home_url( '/sitemap.xsl' ) ) . '"?>' . "\n";
	$xml .= '<!-- FMG AEO ' . fmg_aeo_xml_esc( fmg_aeo_version() ) . '. Not a machine? The one you want is at ' . fmg_aeo_xml_esc( home_url( '/sitemap/' ) ) . ' -->' . "\n";
	return $xml;
}

function fmg_aeo_sitemap_xml( $entries ) {
	$xml = fmg_aeo_sitemap_head() . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
	foreach ( $entries as $entry ) {
		$xml .= "\t<url>\n";
		$xml .= "\t\t<loc>" . fmg_aeo_xml_esc( $entry['loc'] ) . "</loc>\n";
		if ( ! empty( $entry['lastmod'] ) ) {
			$xml .= "\t\t<lastmod>" . fmg_aeo_xml_esc( $entry['lastmod'] ) . "</lastmod>\n";
		}
		$xml .= "\t</url>\n";
	}
	return $xml . '</urlset>' . "\n";
}

/**
 * Sitemap index for a site past fmg_aeo_sitemap_max_urls: one
 * /sitemap-N.xml per chunk, each dated to its newest entry.
 */
function fmg_aeo_sitemap_index_xml( $chunks ) {
	$xml = fmg_aeo_sitemap_head() . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
	foreach ( $chunks as $i => $chunk ) {
		$newest = 0;
		foreach ( $chunk as $entry ) {
			$ts     = empty( $entry['lastmod'] ) ? 0 : (int) strtotime( $entry['lastmod'] );
			$newest = max( $newest, $ts );
		}
		$xml .= "\t<sitemap>\n";
		$xml .= "\t\t<loc>" . fmg_aeo_xml_esc( home_url( '/sitemap-' . ( $i + 1 ) . '.xml' ) ) . "</loc>\n";
		if ( $newest ) {
			$xml .= "\t\t<lastmod>" . fmg_aeo_xml_esc( wp_date( DATE_W3C, $newest ) ) . "</lastmod>\n";
		}
		$xml .= "\t</sitemap>\n";
	}
	return $xml . '</sitemapindex>' . "\n";
}

/**
 * Browser view of the sitemap. Engines never see it; the stylesheet only
 * tells a browser how to draw the XML. It loads the theme's own stylesheet
 * and lockup, so the header, entry, and footer chrome follow the theme
 * with nothing copied here; the local rules cover only the URL list and
 * keep the three header links visible on narrow screens (the theme hides
 * them behind a JavaScript menu, and this page has no script).
 */
function fmg_aeo_stylesheet() {
	$xsl = <<<'XSL'
<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform" xmlns:sm="http://www.sitemaps.org/schemas/sitemap/0.9">
<xsl:output method="html" encoding="UTF-8" indent="yes" doctype-system="about:legacy-compat"/>
<xsl:template match="/">
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>XML sitemap | Fernwell Media Group</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&amp;family=Inter:wght@400;500;600;700&amp;display=swap"/>
<link rel="stylesheet" href="%%CSS%%"/>
<style>
.brand-text { font-family: var(--font-display); font-weight: 700; font-size: 18px; color: var(--ink); }
.urls { margin-top: 30px; max-width: 760px; list-style: none; }
.urls li { border-bottom: 1px solid var(--line); padding: 18px 0; }
.urls a { color: var(--ink); text-decoration: none; overflow-wrap: anywhere; font-weight: 500; }
.urls a:hover { color: var(--accent); }
.urls .meta { display: block; font-size: .82rem; color: var(--ink-soft); margin-top: 4px; text-transform: uppercase; letter-spacing: .08em; }
@media (max-width: 880px) {
  .nav { height: auto; min-height: 72px; flex-wrap: wrap; row-gap: 4px; padding-top: 12px; padding-bottom: 12px; }
  nav.links { display: flex; position: static; flex-direction: row; flex-wrap: wrap; gap: 4px 20px; padding: 0; background: none; border: 0; box-shadow: none; }
  nav.links a { margin: 0; padding: 4px 0; font-size: .95rem; border-bottom: 0; }
}
</style>
</head>
<body>
<header class="site">
  <div class="wrap nav">
    <a class="brand" href="%%HOME%%">%%BRAND%%</a>
    <nav class="links" aria-label="Sitemap surfaces">
      <a href="%%HOME%%sitemap/">Sitemap for people</a>
      <a href="%%HOME%%llms.txt">llms.txt</a>
      <a href="%%HOME%%robots.txt">robots.txt</a>
    </nav>
  </div>
</header>
<main id="main">
  <div class="entry">
    <div class="wrap">
      <header class="entry-header">
        <span class="kicker">For crawlers and engines</span>
        <h1>XML sitemap</h1>
        <div class="meta"><xsl:value-of select="count(sm:urlset/sm:url | sm:sitemapindex/sm:sitemap)"/> URLs</div>
      </header>
      <div class="entry-content">
        <p>The machine-readable index of this site: every URL engines are invited to, and when it last changed. Not a machine? The one you want is at <a href="%%HOME%%sitemap/">/sitemap/</a>.</p>
      </div>
      <ul class="urls">
        <xsl:for-each select="sm:urlset/sm:url | sm:sitemapindex/sm:sitemap">
          <li>
            <a href="{sm:loc}"><xsl:value-of select="sm:loc"/></a>
            <xsl:if test="sm:lastmod">
              <span class="meta">Last modified <xsl:value-of select="substring(sm:lastmod, 1, 10)"/></span>
            </xsl:if>
          </li>
        </xsl:for-each>
      </ul>
    </div>
  </div>
</main>
<footer class="site">
  <div class="wrap">
    <div>&#169; %%YEAR%% Fernwell Media Group. All rights reserved.</div>
    <div>Generated by FMG AEO %%VERSION%%</div>
  </div>
</footer>
</body>
</html>
</xsl:template>
</xsl:stylesheet>
XSL;
	$logo_file = get_theme_file_path( 'assets/images/fernwell-lockup.svg' );
	$logo      = (string) apply_filters( 'fmg_aeo_logo_url', file_exists( $logo_file ) ? get_theme_file_uri( 'assets/images/fernwell-lockup.svg' ) : '' );
	$brand     = '' !== $logo
		? '<img src="' . fmg_aeo_xml_esc( $logo ) . '" alt="Fernwell Media Group" width="246" height="50"/>'
		: '<span class="brand-text">Fernwell Media Group</span>';
	$css       = (string) apply_filters( 'fmg_aeo_stylesheet_css_url', get_stylesheet_uri() );
	$launch    = 2026;
	$year      = (int) wp_date( 'Y' );
	return str_replace(
		array( "\r\n", '%%HOME%%', '%%CSS%%', '%%BRAND%%', '%%VERSION%%', '%%YEAR%%' ),
		array(
			"\n",
			fmg_aeo_xml_esc( home_url( '/' ) ),
			fmg_aeo_xml_esc( $css ),
			$brand,
			fmg_aeo_xml_esc( fmg_aeo_version() ),
			$year > $launch ? $launch . '-' . $year : (string) $launch,
		),
		$xsl
	);
}
