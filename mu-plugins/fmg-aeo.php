<?php
/**
 * Plugin Name: FMG AEO
 * Description: Serves /sitemap.xml with an FMG-branded stylesheet, declares it in robots.txt, and retires core's wp-sitemap.xml.
 * Version:     0.1.0
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
 *
 * @package Fernwell
 */

defined( 'ABSPATH' ) || exit;

define( 'FMG_AEO_VERSION', '0.1.0' );

/*
 * Core sitemaps off. WordPress keeps its wp-sitemap rewrite rules registered
 * so those URLs would 404; fmg_aeo_retire_core_sitemap() turns them into
 * 301s to /sitemap.xml instead, so anything that already learned the old
 * address lands on the new one.
 */
add_filter( 'wp_sitemaps_enabled', '__return_false' );

/*
 * Routes: /sitemap.xml and /sitemap.xsl, registered at the top so they win
 * over core's legacy sitemap\.xml rule.
 */
function fmg_aeo_rewrites() {
	add_rewrite_rule( '^sitemap\.xml$', 'index.php?fmg_sitemap=xml', 'top' );
	add_rewrite_rule( '^sitemap\.xsl$', 'index.php?fmg_sitemap=xsl', 'top' );
}
add_action( 'init', 'fmg_aeo_rewrites' );

function fmg_aeo_query_vars( $vars ) {
	$vars[] = 'fmg_sitemap';
	return $vars;
}
add_filter( 'query_vars', 'fmg_aeo_query_vars' );

/*
 * Flush rewrite rules once per plugin version, after every init callback
 * (the theme's llms.txt rule included) has registered, so a deploy never
 * needs a manual permalink save.
 */
function fmg_aeo_maybe_flush() {
	if ( get_option( 'fmg_aeo_rewrite_version' ) !== FMG_AEO_VERSION ) {
		flush_rewrite_rules( false );
		update_option( 'fmg_aeo_rewrite_version', FMG_AEO_VERSION );
	}
}
add_action( 'wp_loaded', 'fmg_aeo_maybe_flush' );

/*
 * Serve. Runs at priority 0 so the canonical redirect never gets a chance
 * to add a trailing slash; the slashed form is sent back to the bare path.
 */
function fmg_aeo_serve() {
	$what = get_query_var( 'fmg_sitemap' );
	if ( ! in_array( $what, array( 'xml', 'xsl' ), true ) ) {
		return;
	}
	$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
	if ( is_string( $path ) && '/' === substr( $path, -1 ) ) {
		wp_safe_redirect( home_url( '/sitemap.' . $what ), 301 );
		exit;
	}
	header( 'X-Robots-Tag: noindex, follow', true );
	if ( 'xsl' === $what ) {
		header( 'Content-Type: text/xsl; charset=UTF-8' );
		echo fmg_aeo_stylesheet();
		exit;
	}
	header( 'Content-Type: application/xml; charset=UTF-8' );
	echo fmg_aeo_sitemap_xml();
	exit;
}
add_action( 'template_redirect', 'fmg_aeo_serve', 0 );

function fmg_aeo_retire_core_sitemap() {
	if ( get_query_var( 'sitemap' ) || get_query_var( 'sitemap-stylesheet' ) ) {
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
 */
function fmg_aeo_robots( $output, $is_public ) {
	if ( ! $is_public ) {
		return $output;
	}

	$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

	$body = <<<'ROBOTS'
# Fernwell Media Group (%%HOST%%)
# Answer Engine Optimization consultancy. Crawlers are welcome, including
# AI, answer-engine, and training crawlers. We want this content found,
# cited, and named in answers; that is the business.
# LLM guidance: %%LLMS%%

# Default: everyone allowed, minus admin and internal search
User-agent: *
Allow: /
Disallow: /wp-admin/
Allow: /wp-admin/admin-ajax.php
Disallow: /?s=
Disallow: /page/*/?s=
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
 * Let the sitemap through the Password Protected gate, as the theme does
 * for llms.txt. No effect when that plugin is inactive.
 */
function fmg_aeo_sitemap_public( $is_active ) {
	$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
	if ( is_string( $path ) && in_array( untrailingslashit( $path ), array( '/sitemap.xml', '/sitemap.xsl' ), true ) ) {
		return false;
	}
	return $is_active;
}
add_filter( 'password_protected_is_active', 'fmg_aeo_sitemap_public' );

/**
 * The URL list: homepage first, then pages in creation order, then posts
 * newest first. Each entry is loc + lastmod (W3C); no changefreq or
 * priority, which engines ignore. A filter returning an empty entry drops
 * the URL.
 */
function fmg_aeo_sitemap_entries() {
	$entries    = array();
	$home       = home_url( '/' );
	$post_types = apply_filters( 'fmg_aeo_sitemap_post_types', array( 'page', 'post' ) );

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
			$entry = array(
				'loc'     => get_permalink( $post ),
				'lastmod' => wp_date( DATE_W3C, strtotime( $post->post_modified_gmt ) ),
			);
			$entry = apply_filters( 'fmg_aeo_sitemap_entry', $entry, $post );
			if ( ! empty( $entry['loc'] ) ) {
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
		if ( $newest ) {
			$entry['lastmod'] = wp_date( DATE_W3C, strtotime( $newest[0]->post_modified_gmt ) );
		}
		array_unshift( $entries, $entry );
	}

	return apply_filters( 'fmg_aeo_sitemap_entries', $entries );
}

function fmg_aeo_sitemap_xml() {
	$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	$xml .= '<?xml-stylesheet type="text/xsl" href="' . htmlspecialchars( home_url( '/sitemap.xsl' ), ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '"?>' . "\n";
	$xml .= '<!-- FMG AEO ' . FMG_AEO_VERSION . '. Not a machine? The one you want is at ' . htmlspecialchars( home_url( '/sitemap/' ), ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . ' -->' . "\n";
	$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
	foreach ( fmg_aeo_sitemap_entries() as $entry ) {
		$xml .= "\t<url>\n";
		$xml .= "\t\t<loc>" . htmlspecialchars( $entry['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . "</loc>\n";
		if ( ! empty( $entry['lastmod'] ) ) {
			$xml .= "\t\t<lastmod>" . htmlspecialchars( $entry['lastmod'], ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . "</lastmod>\n";
		}
		$xml .= "\t</url>\n";
	}
	$xml .= '</urlset>' . "\n";
	return $xml;
}

/**
 * Browser view of the sitemap. Engines never see it; the stylesheet only
 * tells a browser how to draw the XML. Styled as a Fernwell entry page:
 * the tokens, brand lockup, header, kicker, Answers-list rows, and footer
 * mirror style.css; keep them in sync when the theme's chrome changes.
 */
function fmg_aeo_stylesheet() {
	$xsl = <<<'XSL'
<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform" xmlns:sm="http://www.sitemaps.org/schemas/sitemap/0.9">
<xsl:output method="html" encoding="UTF-8" indent="yes"/>
<xsl:template match="/">
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>XML sitemap | Fernwell Media Group</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&amp;family=Inter:wght@400;500;600;700&amp;display=swap"/>
<style>
:root {
  --ink: #17211d; --ink-soft: #4d5a55; --paper: #f8f8f5; --paper-alt: #eef0e9;
  --accent: #156a58; --accent-hover: #0e5244; --fill: #f4a860; --fill-ink: #14201e;
  --line: #dde1d9; --max: 1060px;
  --font-display: "Space Grotesk", Inter, -apple-system, sans-serif;
  --font-body: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: var(--font-body); color: var(--ink); background: var(--paper); line-height: 1.65; font-size: 17px; }
h1 { font-family: var(--font-display); }
.wrap { max-width: var(--max); margin: 0 auto; padding: 0 24px; }
a { color: var(--accent); }
header.site { position: sticky; top: 0; z-index: 50; background: rgba(248,248,245,.92); backdrop-filter: blur(8px); border-bottom: 1px solid var(--line); }
.nav { display: flex; align-items: center; justify-content: space-between; height: 72px; }
.brand { display: flex; align-items: center; gap: 11px; text-decoration: none; }
.brand .tile { width: 42px; height: 42px; background: var(--fill); border-radius: 9px; display: flex; align-items: center; justify-content: center; font-family: var(--font-display); font-weight: 700; font-size: 16px; color: var(--fill-ink); letter-spacing: .02em; }
.brand .words { line-height: 1.08; }
.brand .words .top { font-size: 18px; font-weight: 700; color: var(--ink); font-family: var(--font-display); }
.brand .words .bot { font-size: 8.5px; letter-spacing: .22em; text-transform: uppercase; color: var(--accent); font-family: var(--font-body); font-weight: 600; margin-top: 2px; display: block; }
nav.links a { text-decoration: none; color: var(--ink-soft); font-size: .95rem; margin-left: 26px; }
nav.links a:hover { color: var(--ink); }
main { padding-bottom: 100px; }
.entry { padding: 84px 0 0; }
.entry-header .kicker { display: inline-block; font-family: var(--font-display); font-weight: 600; font-size: .88rem; letter-spacing: .1em; text-transform: uppercase; color: var(--accent); margin-bottom: 14px; }
.entry-header h1 { font-size: clamp(1.9rem, 4vw, 2.6rem); letter-spacing: -0.02em; max-width: 800px; line-height: 1.15; }
.entry-header .meta { font-size: .85rem; color: var(--ink-soft); margin-top: 12px; }
.entry-content { margin-top: 34px; max-width: 720px; overflow-wrap: break-word; }
.entry-content p { font-size: 1.16rem; line-height: 1.6; color: var(--ink); }
.urls { margin-top: 30px; max-width: 760px; list-style: none; }
.urls li { border-bottom: 1px solid var(--line); padding: 18px 0; }
.urls a { color: var(--ink); text-decoration: none; overflow-wrap: anywhere; font-weight: 500; }
.urls a:hover { color: var(--accent); }
.urls .meta { display: block; font-size: .82rem; color: var(--ink-soft); margin-top: 4px; text-transform: uppercase; letter-spacing: .08em; }
footer.site { border-top: 1px solid var(--line); padding: 36px 0; color: var(--ink-soft); font-size: .88rem; }
footer.site .wrap { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
footer.site a { color: var(--ink-soft); }
@media (max-width: 880px) { nav.links { display: none; } }
</style>
</head>
<body>
<header class="site">
  <div class="wrap nav">
    <a class="brand" href="%%HOME%%">
      <span class="tile">FMG</span>
      <span class="words">
        <span class="top">Fernwell</span><br/>
        <span class="bot">Media Group</span>
      </span>
    </a>
    <nav class="links" aria-label="Sitemap surfaces">
      <a href="%%HOME%%sitemap/">Sitemap for people</a>
      <a href="%%HOME%%llms.txt">llms.txt</a>
      <a href="%%HOME%%robots.txt">robots.txt</a>
    </nav>
  </div>
</header>
<main>
  <div class="entry">
    <div class="wrap">
      <header class="entry-header">
        <span class="kicker">For crawlers and engines</span>
        <h1>XML sitemap</h1>
        <div class="meta"><xsl:value-of select="count(sm:urlset/sm:url)"/> URLs</div>
      </header>
      <div class="entry-content">
        <p>The machine-readable index of this site: every URL engines are invited to, and when it last changed. Not a machine? The one you want is at <a href="%%HOME%%sitemap/">/sitemap/</a>.</p>
      </div>
      <ul class="urls">
        <xsl:for-each select="sm:urlset/sm:url">
          <li>
            <a href="{sm:loc}"><xsl:value-of select="sm:loc"/></a>
            <span class="meta">Last modified <xsl:value-of select="substring(sm:lastmod, 1, 10)"/></span>
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
	$launch = 2026;
	$year   = (int) wp_date( 'Y' );
	return str_replace(
		array( "\r\n", '%%HOME%%', '%%VERSION%%', '%%YEAR%%' ),
		array(
			"\n",
			htmlspecialchars( home_url( '/' ), ENT_XML1 | ENT_QUOTES, 'UTF-8' ),
			FMG_AEO_VERSION,
			$year > $launch ? $launch . '-' . $year : (string) $launch,
		),
		$xsl
	);
}
