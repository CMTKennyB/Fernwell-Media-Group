<?php
/**
 * © 2026 Kenneth Raymond — All rights reserved.
 * Part of the Fernwell WordPress theme.
 * Do not copy, modify, or redistribute without permission.
 *
 * File: Theme Functions
 * Purpose: Theme supports, style and font enqueue, SVG favicon fallback,
 * and the site-wide JSON-LD schema graph.
 *
 * Location:
 *   /wp-content/themes/fernwell/functions.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FERNWELL_VERSION', '1.1.0' );

/**
 * Theme supports.
 */
function fernwell_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );
	add_theme_support( 'automatic-feed-links' );
	register_nav_menus(
		array(
			'primary' => __( 'Primary Menu', 'fernwell' ),
		)
	);
}
add_action( 'after_setup_theme', 'fernwell_setup' );

/**
 * Styles and fonts.
 */
function fernwell_scripts() {
	wp_enqueue_style(
		'fernwell-fonts',
		'https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap',
		array(),
		null
	);
	wp_enqueue_style( 'fernwell-style', get_stylesheet_uri(), array( 'fernwell-fonts' ), FERNWELL_VERSION );
	wp_enqueue_script( 'fernwell-nav', get_theme_file_uri( 'assets/js/nav.js' ), array(), FERNWELL_VERSION, true );
}
add_action( 'wp_enqueue_scripts', 'fernwell_scripts' );

/**
 * SVG favicon (lone F on apricot tile) unless a site icon is set.
 */
function fernwell_favicon() {
	if ( has_site_icon() ) {
		return;
	}
	echo '<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 64 64\'%3E%3Crect width=\'64\' height=\'64\' rx=\'14\' fill=\'%23f4a860\'/%3E%3Ctext x=\'32\' y=\'45\' text-anchor=\'middle\' font-family=\'sans-serif\' font-weight=\'bold\' font-size=\'36\' fill=\'%2314201e\'%3EF%3C/text%3E%3C/svg%3E">' . "\n";
}
add_action( 'wp_head', 'fernwell_favicon', 5 );

/**
 * Meta description, Open Graph, and Twitter Card tags.
 * WordPress core emits none of these; the theme owns them so every share
 * and crawl gets a description and the FMG card image.
 */
function fernwell_social_meta() {
	$title       = wp_get_document_title();
	$description = 'Fernwell Media Group is an independent Answer Engine Optimization (AEO) consultancy founded by Kenneth Raymond. We help businesses get named and cited when AI engines answer.';
	$url         = home_url( '/' );
	$type        = 'website';

	if ( is_singular() && ! is_front_page() ) {
		$url = get_permalink();
		$excerpt = get_the_excerpt();
		if ( $excerpt ) {
			$description = wp_strip_all_tags( $excerpt );
		}
		if ( is_singular( 'post' ) ) {
			$type = 'article';
		}
	}

	$image = get_theme_file_uri( 'assets/og-card.png' );

	echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
	echo '<meta property="og:type" content="' . esc_attr( $type ) . '">' . "\n";
	echo '<meta property="og:site_name" content="Fernwell Media Group">' . "\n";
	echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
	echo '<meta property="og:description" content="' . esc_attr( $description ) . '">' . "\n";
	echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
	echo '<meta property="og:image" content="' . esc_url( $image ) . '">' . "\n";
	echo '<meta property="og:image:width" content="1200">' . "\n";
	echo '<meta property="og:image:height" content="630">' . "\n";
	echo '<meta property="og:locale" content="en_US">' . "\n";
	echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
	echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
	echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '">' . "\n";
	echo '<meta name="twitter:image" content="' . esc_url( $image ) . '">' . "\n";
}
add_action( 'wp_head', 'fernwell_social_meta', 4 );

/**
 * Serve /llms.txt from the theme so the file stays version-controlled.
 * Content lives in the theme's llms.txt; a rewrite rule maps the root URL
 * to it. After theme activation or changes here, flush permalinks once
 * (Settings > Permalinks > Save Changes).
 */
function fernwell_llms_rewrite() {
	add_rewrite_rule( '^llms\.txt$', 'index.php?fernwell_llms=1', 'top' );
}
add_action( 'init', 'fernwell_llms_rewrite' );

function fernwell_llms_query_var( $vars ) {
	$vars[] = 'fernwell_llms';
	return $vars;
}
add_filter( 'query_vars', 'fernwell_llms_query_var' );

function fernwell_llms_serve() {
	if ( get_query_var( 'fernwell_llms' ) ) {
		$file = get_theme_file_path( 'llms.txt' );
		if ( file_exists( $file ) ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
			readfile( $file );
			exit;
		}
	}
}
add_action( 'template_redirect', 'fernwell_llms_serve' );

/**
 * Let /llms.txt bypass the Password Protected gate so AI crawlers can always
 * read it, even while the rest of the site is private during the build.
 * Scoped to the exact llms.txt path only; every other URL stays gated.
 * No effect when the Password Protected plugin is inactive.
 */
function fernwell_llms_public( $is_active ) {
	$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
	if ( is_string( $path ) && untrailingslashit( $path ) === '/llms.txt' ) {
		return false;
	}
	return $is_active;
}
add_filter( 'password_protected_is_active', 'fernwell_llms_public' );

/**
 * Structured data: one connected graph, every node cross-referenced by @id.
 *
 * Sitewide: ProfessionalService (#org), Person (#kenneth), WebSite (#website),
 * and a WebPage node for the current URL. Front page adds FAQPage and the four
 * Service nodes. Singular pages add BreadcrumbList; posts add Article.
 * The FAQ answers here must mirror the visible copy in front-page.php: the
 * answer-first box (the "What is AEO?" entry) and the FAQ section, verbatim.
 */
function fernwell_schema() {
	$base = home_url( '/' );

	$current_url = $base;
	if ( is_singular() ) {
		$current_url = get_permalink();
	} elseif ( is_home() ) {
		$page_for_posts = get_option( 'page_for_posts' );
		$current_url    = $page_for_posts ? get_permalink( $page_for_posts ) : $base;
	}

	$services = array(
		array(
			'name' => 'AI Visibility Audit',
			'desc' => 'Find out whether AI names you, who it names instead, and why. We test the questions your customers actually ask across ChatGPT, Claude, Perplexity, Gemini, and Grok, benchmark your namability against competitors, and deliver a prioritized list of fixes.',
		),
		array(
			'name' => 'AEO Sprint',
			'desc' => 'We turn the audit into the stuff engines need: structured data, entity consistency, information architecture, and answer-first rewrites of the pages most likely to shape AI answers about you.',
		),
		array(
			'name' => 'AI Visibility Monitoring',
			'desc' => 'We continue testing the questions that matter in your market across leading AI engines and track namability, citation share, and competitor movement over time. You see what changed, what didn\'t, and what to do next.',
		),
		array(
			'name' => 'Team Enablement',
			'desc' => 'Workshops and advisory for marketing teams and agencies that want to build AEO capability in-house. We’ll teach the framework, measurement, and implementation. Your team keeps the method.',
		),
	);

	$graph = array(
		array(
			'@type'         => 'ProfessionalService',
			'@id'           => $base . '#org',
			'name'          => 'Fernwell Media Group',
			'alternateName' => 'FMG',
			'description'   => 'Independent Answer Engine Optimization (AEO) consultancy: AI visibility audits, schema and entity implementation, and AI visibility monitoring across ChatGPT, Claude, Perplexity, Gemini, and Grok.',
			'url'           => $base,
			'founder'       => array( '@id' => $base . '#kenneth' ),
			'areaServed'    => 'United States',
			'email'         => 'mailto:hello@fernwellgroup.com',
			'knowsAbout'    => array(
				'Answer Engine Optimization',
				'Generative Engine Optimization',
				'Structured data and schema.org markup',
				'Entity SEO',
				'AI search visibility',
			),
		),
		array(
			'@type'       => 'Person',
			'@id'         => $base . '#kenneth',
			'name'        => 'Kenneth Raymond',
			'jobTitle'    => 'Founder, Answer Engine Optimization Consultant',
			'description' => 'Communications systems and knowledge infrastructure specialist. Founder of Fernwell Media Group, an independent AEO consultancy.',
			'url'         => $base . 'about/',
			'email'       => 'mailto:hello@fernwellgroup.com',
			'worksFor'    => array( '@id' => $base . '#org' ),
			'knowsAbout'  => array(
				'Answer Engine Optimization',
				'Communications systems',
				'Knowledge infrastructure',
				'Information architecture',
			),
			'sameAs'      => array(
				'https://www.linkedin.com/in/kenny-raymond/',
			),
		),
		array(
			'@type'     => 'WebSite',
			'@id'       => $base . '#website',
			'name'      => 'Fernwell Media Group',
			'url'       => $base,
			'publisher' => array( '@id' => $base . '#org' ),
			'inLanguage' => 'en-US',
		),
		array(
			'@type'      => 'WebPage',
			'@id'        => $current_url . '#webpage',
			'url'        => $current_url,
			'name'       => wp_get_document_title(),
			'isPartOf'   => array( '@id' => $base . '#website' ),
			'about'      => array( '@id' => $base . '#org' ),
			'inLanguage' => 'en-US',
		),
	);

	if ( is_front_page() ) {
		foreach ( $services as $i => $svc ) {
			$graph[] = array(
				'@type'       => 'Service',
				'@id'         => $base . '#service-' . ( $i + 1 ),
				'name'        => $svc['name'],
				'description' => $svc['desc'],
				'serviceType' => 'Answer Engine Optimization',
				'provider'    => array( '@id' => $base . '#org' ),
				'areaServed'  => 'United States',
			);
		}
	}

	if ( is_singular() && ! is_front_page() ) {
		$graph[] = array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $current_url . '#breadcrumb',
			'itemListElement' => array(
				array(
					'@type'    => 'ListItem',
					'position' => 1,
					'name'     => 'Home',
					'item'     => $base,
				),
				array(
					'@type'    => 'ListItem',
					'position' => 2,
					'name'     => get_the_title(),
					'item'     => $current_url,
				),
			),
		);
	}

	if ( is_front_page() ) {
		$faqs = array(
			array(
				'q' => 'What is Answer Engine Optimization (AEO)?',
				'a' => 'Answer Engine Optimization (AEO) is the practice of making you understandable, citable, and nameable by the AI engines answering your customers’ questions. Where SEO competes for rankings, AEO competes for inclusion in the answer.',
			),
			array(
				'q' => 'What’s the difference between AEO and SEO?',
				'a' => 'SEO optimizes for visibility in ranked search results. AEO optimizes for inclusion in AI-generated answers. Strong SEO helps engines find you, but it does not guarantee they will understand, cite, or name you. AEO builds on this foundation with entity definition, structured data, information architecture, answer-first content, and direct measurement of AI answers.',
			),
			array(
				'q' => 'How do I know if AI engines are naming me?',
				'a' => 'Ask the questions your customers actually ask and measure who gets named, who gets cited, and who gets left out. A single prompt tells you very little. A repeatable benchmark across engines, questions, and competitors measures your namability and shows who is taking your place in the answer. This is what the AI Visibility Audit does.',
			),
			array(
				'q' => 'How long does AEO take to work?',
				'a' => 'There isn’t a universal timeline. Technical changes can appear as engines recrawl, while changes in namability and citation share can take longer and vary by engine. This is why we keep testing the same questions after changes are made. Monitoring shows what moved, what didn’t, and what to do next.',
			),
			array(
				'q' => 'Do I need AEO if I already invest in SEO?',
				'a' => 'Probably. Strong SEO gives AEO a better foundation, but it does not answer the same question. SEO tells you whether people can find you in search results. AEO tells you whether an engine understands, cites, and names you when it answers that question. An audit shows whether there is actually a gap before you invest in fixing one.',
			),
		);

		$main = array();
		foreach ( $faqs as $faq ) {
			$main[] = array(
				'@type'          => 'Question',
				'name'           => $faq['q'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $faq['a'],
				),
			);
		}
		$graph[] = array(
			'@type'      => 'FAQPage',
			'@id'        => $base . '#faq',
			'mainEntity' => $main,
		);
	}

	if ( is_singular( 'post' ) ) {
		global $post;
		$graph[] = array(
			'@type'            => 'Article',
			'@id'              => $current_url . '#article',
			'headline'         => get_the_title( $post ),
			'datePublished'    => get_the_date( 'c', $post ),
			'dateModified'     => get_the_modified_date( 'c', $post ),
			'author'           => array( '@id' => $base . '#kenneth' ),
			'publisher'        => array( '@id' => $base . '#org' ),
			'isPartOf'         => array( '@id' => $base . '#website' ),
			'mainEntityOfPage' => array( '@id' => $current_url . '#webpage' ),
			'inLanguage'       => 'en-US',
		);
	}

	$schema = array(
		'@context' => 'https://schema.org',
		'@graph'   => $graph,
	);

	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
}
add_action( 'wp_head', 'fernwell_schema' );
