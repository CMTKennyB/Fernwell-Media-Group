<?php
/**
 * © 2026 Kenneth Raymond. All rights reserved.
 * Part of the Fernwell WordPress theme.
 * Do not copy, modify, or redistribute without permission.
 *
 * File: Structured Data
 * Purpose: The site-wide JSON-LD schema graph, printed in wp_head.
 *
 * Location:
 *   /wp-content/themes/fernwell/inc/schema.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Set a property on the graph's WebPage node (mainEntity, hasPart, ...).
 * Found by type, not position, so reordering the graph never misplaces it.
 *
 * @param array  $graph Schema graph nodes.
 * @param string $prop  Property name.
 * @param mixed  $value Property value.
 * @return array
 */
function fernwell_schema_webpage_set( array $graph, $prop, $value ) {
	foreach ( $graph as $i => $node ) {
		if ( 'WebPage' === $node['@type'] ) {
			$graph[ $i ][ $prop ] = $value;
		}
	}
	return $graph;
}

/**
 * Structured data: one connected graph, every node cross-referenced by @id.
 *
 * Sitewide: Organization (#org), Person (#kenneth), WebSite (#website).
 * Pages the theme models (front page, singular content, the Answers listing
 * and its paginated pages) add a WebPage node for their own URL; 404s and
 * other requests carry the sitewide nodes only. Front page adds FAQPage as a
 * part of the page, plus a Book node once FERNWELL_BOOK_URL is set. The
 * Answers surfaces add a Blog node named Fernwell Answers. Singular pages
 * add BreadcrumbList; posts add BlogPosting, anchored to the Blog node.
 * FAQ copy comes from fernwell_faqs(), the same source front-page.php renders.
 */
function fernwell_schema() {
	$base = home_url( '/' );

	// The URL this page describes. Only pages the theme models get a WebPage
	// node; a 404, search results, or anything else keeps the sitewide nodes
	// alone instead of borrowing another page's identity. Paginated Answers
	// pages describe themselves, matching their canonical.
	$current_url = '';
	if ( is_front_page() ) {
		$current_url = $base;
	} elseif ( is_singular() ) {
		$current_url = get_permalink();
	} elseif ( is_home() ) {
		$current_url = fernwell_answers_page_url();
	}

	$graph = array(
		array(
			'@type'         => 'Organization',
			'@id'           => $base . '#org',
			'name'          => 'Fernwell Media Group',
			'alternateName' => 'FMG',
			'description'   => 'Answer Engine Optimization (AEO) research and education: Fernwell Answers, a free AI readability and citability scanner, and the published method behind both.',
			'url'           => $base,
			'image'         => array(
				'@type'  => 'ImageObject',
				'url'    => get_theme_file_uri( 'assets/og-card.png' ),
				'width'  => 1200,
				'height' => 630,
			),
			'founder'       => array( '@id' => $base . '#kenneth' ),
			'email'         => 'mailto:hello@fernwellgroup.com',
			'knowsAbout'    => array(
				'Answer Engine Optimization',
				'Generative Engine Optimization',
				'Structured data and schema.org markup',
				'Entity SEO',
				'AI search visibility',
			),
			'sameAs'        => array(
				'https://github.com/CMTKennyB/Fernwell-Media-Group',
			),
		),
		array(
			'@type'       => 'Person',
			'@id'         => $base . '#kenneth',
			'name'        => 'Kenneth Raymond',
			'jobTitle'    => 'Founder, Fernwell Media Group',
			'description' => 'Author, researcher, and software developer working in information architecture, structured information systems, and AI-enabled applications. Founder of Fernwell Media Group, an AEO research and education project.',
			'url'         => $base . '#about',
			'email'       => 'mailto:hello@fernwellgroup.com',
			'worksFor'    => array( '@id' => $base . '#org' ),
			'knowsAbout'  => array(
				'Answer Engine Optimization',
				'Communications systems',
				'Knowledge infrastructure',
				'Information architecture',
				'Knowledge management',
				'Software development',
				'Cloud and serverless architecture',
				'API design and integration',
				'Data engineering',
				'Workflow automation',
				'AI-enabled applications',
			),
			'sameAs'      => array(
				'https://www.linkedin.com/in/kenny-raymond/',
				'https://www.amazon.com/stores/Kenneth-Raymond/author/B0BD39864J',
			),
			'hasCredential' => array(
				'@type'              => 'EducationalOccupationalCredential',
				'credentialCategory' => 'degree',
				'educationalLevel'   => 'Bachelor of Science',
				'name'               => 'BS Human Communication',
				'recognizedBy'       => array(
					'@type' => 'CollegeOrUniversity',
					'name'  => 'Arizona State University',
				),
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
	);

	if ( $current_url ) {
		$graph[] = array(
			'@type'      => 'WebPage',
			'@id'        => $current_url . '#webpage',
			'url'        => $current_url,
			'name'       => wp_get_document_title(),
			'isPartOf'   => array( '@id' => $base . '#website' ),
			'about'      => array( '@id' => $base . '#org' ),
			'inLanguage' => 'en-US',
		);
	}

	if ( is_front_page() ) {
		// Anchor the homepage's dates to real site-content activity.
		$home   = fernwell_home_dates();
		$wp_key = array_key_last( $graph );
		$graph[ $wp_key ]['datePublished'] = $home['published'];
		$graph[ $wp_key ]['dateModified']  = $home['modified'];
	}

	$answers_url = fernwell_answers_url();

	if ( is_home() || is_singular( 'post' ) ) {
		$blog = array(
			'@type'       => 'Blog',
			'@id'         => $answers_url . '#blog',
			'name'        => 'Fernwell Answers',
			'description' => fernwell_answers_description(),
			'url'         => $answers_url,
			'publisher'   => array( '@id' => $base . '#org' ),
			'isPartOf'    => array( '@id' => $base . '#website' ),
			'inLanguage'  => 'en-US',
		);
		// The listing enumerates the series. Single posts point up through
		// isPartOf instead: on a post page the other posts would be bare @id
		// references that validators flag, and the list grows with every post.
		if ( is_home() ) {
			// The listing page presents the Blog: link them both ways, matching
			// how posts pair BlogPosting with their page. Only page one is the
			// Blog's own page; later pages point at the Blog one way.
			if ( ! is_paged() ) {
				$blog['mainEntityOfPage'] = array( '@id' => $current_url . '#webpage' );
			}
			$graph = fernwell_schema_webpage_set( $graph, 'mainEntity', array( '@id' => $answers_url . '#blog' ) );
			$blog['blogPost'] = array();
			foreach ( get_posts( array( 'numberposts' => -1, 'post_status' => 'publish', 'fields' => 'ids', 'meta_query' => fernwell_indexable_meta_query() ) ) as $answer_id ) {
				$blog['blogPost'][] = array( '@id' => get_permalink( $answer_id ) . '#article' );
			}
		}
		$graph[] = $blog;
	}

	if ( is_front_page() && fernwell_book_url() ) {
		// Description mirrors the visible book section copy in front-page.php.
		$graph[] = array(
			'@type'       => 'Book',
			'@id'         => $base . '#book',
			'name'        => 'The Fernwell Scanner 3.0',
			'description' => 'The full method behind the free scan: every check, why it earns its points, and the published engine rules it rests on. Read it to understand your score, or to run the same measurements yourself.',
			'author'      => array( '@id' => $base . '#kenneth' ),
			'url'         => fernwell_book_url(),
			'about'       => 'Answer Engine Optimization',
			'inLanguage'  => 'en-US',
		);
	}

	if ( is_singular() && ! is_front_page() ) {
		$crumbs = array(
			array( 'name' => 'Home', 'item' => $base ),
		);
		if ( is_singular( 'post' ) ) {
			$crumbs[] = array( 'name' => 'Fernwell Answers', 'item' => $answers_url );
		}
		$crumbs[] = array( 'name' => get_the_title(), 'item' => $current_url );

		$items = array();
		foreach ( $crumbs as $i => $crumb ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => $crumb['name'],
				'item'     => $crumb['item'],
			);
		}
		$graph[] = array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $current_url . '#breadcrumb',
			'itemListElement' => $items,
		);
	}

	if ( is_front_page() ) {
		$main = array();
		foreach ( fernwell_faqs() as $faq ) {
			$main[] = array(
				'@type'          => 'Question',
				'name'           => $faq['q'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $faq['a'],
				),
			);
		}
		// The FAQ is one section of the homepage, not the whole page: link
		// the two through hasPart and isPartOf.
		$graph   = fernwell_schema_webpage_set( $graph, 'hasPart', array( '@id' => $base . '#faq' ) );
		$graph[] = array(
			'@type'      => 'FAQPage',
			'@id'        => $base . '#faq',
			'isPartOf'   => array( '@id' => $current_url . '#webpage' ),
			'mainEntity' => $main,
		);
	}

	if ( is_singular( 'post' ) ) {
		global $post;
		$abstract = fernwell_post_meta_description( $post->ID );
		if ( ! $abstract ) {
			$abstract = wp_strip_all_tags( get_the_excerpt( $post ) );
		}
		$article = array(
			'@type'            => 'BlogPosting',
			'@id'              => $current_url . '#article',
			'headline'         => get_the_title( $post ),
			'image'            => fernwell_post_schema_image( $post->ID ),
			'datePublished'    => get_the_date( 'c', $post ),
			'dateModified'     => wp_date( 'c', fernwell_post_modified_gmt( $post ) ),
			'author'           => array( '@id' => $base . '#kenneth' ),
			'publisher'        => array( '@id' => $base . '#org' ),
			'isPartOf'         => array( '@id' => $answers_url . '#blog' ),
			'mainEntityOfPage' => array( '@id' => $current_url . '#webpage' ),
			'wordCount'        => str_word_count( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) ),
			'timeRequired'     => 'PT' . fernwell_reading_time( $post ) . 'M',
			'speakable'        => array(
				'@type'       => 'SpeakableSpecification',
				'cssSelector' => array( '.entry-header h1', '.answer-lede' ),
			),
			'inLanguage'       => 'en-US',
		);
		if ( $abstract ) {
			$article['description'] = $abstract;
			$article['abstract']    = $abstract;
		}
		$graph   = fernwell_schema_webpage_set( $graph, 'mainEntity', array( '@id' => $current_url . '#article' ) );
		$graph[] = $article;
	}

	$schema = array(
		'@context' => 'https://schema.org',
		'@graph'   => $graph,
	);

	// JSON_HEX_TAG and JSON_HEX_AMP keep any "</script>" or entity inside a
	// value from ending or altering the script element.
	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>' . "\n";
}
add_action( 'wp_head', 'fernwell_schema' );
