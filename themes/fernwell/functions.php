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

define( 'FERNWELL_VERSION', '1.0.0' );

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
 * Structured data: one connected graph, every node cross-referenced by @id.
 *
 * Sitewide: ProfessionalService (#org), Person (#kenneth), WebSite (#website),
 * and a WebPage node for the current URL. Front page adds FAQPage and the four
 * Service nodes. Singular pages add BreadcrumbList; posts add Article.
 * The FAQ answers here must mirror the visible FAQ content in front-page.php.
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
			'desc' => 'A systematic test of a business\'s presence across ChatGPT, Claude, Perplexity, Gemini, and Google AI Overviews, benchmarked against competitors, with a prioritized fix list. Fixed fee, delivered as a report plus a working session.',
		),
		array(
			'name' => 'AEO Sprint',
			'desc' => 'Four to six weeks of implementation: structured data across the site, entity consistency everywhere the business appears, and answer-first rewrites of the pages AI engines pull from.',
		),
		array(
			'name' => 'Citation Monitoring',
			'desc' => 'Monthly prompt testing and citation tracking across AI engines, share-of-voice against competitors, and a plain-English report on what moved and why.',
		),
		array(
			'name' => 'Team Enablement',
			'desc' => 'Workshops and advisory for marketing teams and agencies that want AEO capability in-house.',
		),
	);

	$graph = array(
		array(
			'@type'         => 'ProfessionalService',
			'@id'           => $base . '#org',
			'name'          => 'Fernwell Media Group',
			'alternateName' => 'FMG',
			'description'   => 'Independent Answer Engine Optimization (AEO) consultancy: AI visibility audits, schema and entity implementation, and citation monitoring across ChatGPT, Claude, Perplexity, Gemini, and Google AI Overviews.',
			'url'           => $base,
			'founder'       => array( '@id' => $base . '#kenneth' ),
			'areaServed'    => 'United States',
			'email'         => 'mailto:hello@fernwellmedia.com',
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
			'email'       => 'mailto:hello@fernwellmedia.com',
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
				'a' => 'Answer Engine Optimization (AEO) is the practice of structuring your website and content so that AI engines like ChatGPT, Claude, Perplexity, Gemini, and Google AI Overviews cite your business when they answer questions your customers ask. Where SEO competes for rankings, AEO competes for citations.',
			),
			array(
				'q' => 'How is AEO different from SEO?',
				'a' => 'SEO optimizes for a list of links; AEO optimizes for a single synthesized answer. Only 8% of ChatGPT citations overlap with Google\'s top organic results, so ranking well on Google does not guarantee AI engines will mention you. AEO adds entity definition, structured data, and answer-first content on top of a sound SEO foundation.',
			),
			array(
				'q' => 'How do I know if AI engines mention my business?',
				'a' => 'You test it directly: ask the questions your customers ask across ChatGPT, Claude, Perplexity, Gemini, and Google AI Overviews, and record who gets named and cited. An AI Visibility Audit does this systematically across the engines and query types that matter for your market, then compares your presence against competitors.',
			),
			array(
				'q' => 'How long does AEO take to show results?',
				'a' => 'Technical fixes like schema markup and entity cleanup can be reflected within weeks as engines recrawl. Citation share builds over one to two quarters as answer-first content is published and picked up. Monitoring makes the progress visible month over month.',
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
