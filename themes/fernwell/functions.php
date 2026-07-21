<?php
/**
 * Fernwell theme functions.
 *
 * @package Fernwell
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
 * Structured data: ProfessionalService + Person sitewide, FAQPage on the front page.
 * The FAQ answers here must mirror the visible FAQ content in front-page.php.
 */
function fernwell_schema() {
	$base = home_url( '/' );

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
			'serviceType'   => 'Answer Engine Optimization',
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
			'sameAs'      => array(
				'https://www.linkedin.com/in/YOUR-PROFILE',
			),
		),
	);

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
			'@type'         => 'Article',
			'headline'      => get_the_title( $post ),
			'datePublished' => get_the_date( 'c', $post ),
			'dateModified'  => get_the_modified_date( 'c', $post ),
			'author'        => array( '@id' => $base . '#kenneth' ),
			'publisher'     => array( '@id' => $base . '#org' ),
			'mainEntityOfPage' => get_permalink( $post ),
		);
	}

	$schema = array(
		'@context' => 'https://schema.org',
		'@graph'   => $graph,
	);

	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
}
add_action( 'wp_head', 'fernwell_schema' );
