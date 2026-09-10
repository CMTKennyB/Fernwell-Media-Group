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

define( 'FERNWELL_VERSION', '2.0.0' );

require get_theme_file_path( 'inc/post-meta.php' );

/**
 * Theme supports.
 */
function fernwell_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'editor-styles' );
	add_editor_style( array(
		'https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap',
		'assets/css/editor.css',
	) );
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
	if ( is_home() || is_singular( 'post' ) ) {
		wp_enqueue_script( 'fernwell-answers-nav', get_theme_file_uri( 'assets/js/answers-nav.js' ), array(), FERNWELL_VERSION, true );
	}
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
 * Canonical URL of the Fernwell Answers listing (the posts page).
 */
function fernwell_answers_url() {
	$page_for_posts = get_option( 'page_for_posts' );
	return $page_for_posts ? get_permalink( $page_for_posts ) : home_url( '/answers/' );
}

/**
 * One-line description of the Fernwell Answers section, shared by the
 * meta description and the Blog schema node. Mirrors the visible intro
 * copy in index.php; keep the three in sync.
 */
function fernwell_answers_description() {
	return "Direct answers to the questions you're asking about AI search and being visible where buying decisions now start.";
}

/**
 * Approximate reading time for a post, in whole minutes at 200 wpm.
 */
function fernwell_reading_time( $post = null ) {
	$post  = get_post( $post );
	$words = str_word_count( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) );
	return max( 1, (int) ceil( $words / 200 ) );
}

/**
 * The Fernwell Answers share card, if the theme ships one; null falls back
 * to the default FMG card. 1200x630.
 */
function fernwell_answers_card() {
	return file_exists( get_theme_file_path( 'assets/og-card-answers.png' ) )
		? get_theme_file_uri( 'assets/og-card-answers.png' )
		: null;
}

/**
 * Brand the Answers surfaces in the title tag: the listing page reads
 * "Fernwell Answers" and single posts carry it as the site suffix, so
 * every cited post names the section. The wordmark colon stays out of
 * data surfaces; the entity name is the clean string.
 */
function fernwell_document_title_separator() {
	return '|';
}
add_filter( 'document_title_separator', 'fernwell_document_title_separator' );

function fernwell_document_title_parts( $parts ) {
	if ( is_home() ) {
		$parts['title'] = 'Fernwell Answers';
	} elseif ( is_singular( 'post' ) ) {
		$parts['site'] = 'Fernwell Answers';
	}
	return $parts;
}
add_filter( 'document_title_parts', 'fernwell_document_title_parts' );

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

	$image       = get_theme_file_uri( 'assets/og-card.png' );
	$image_w     = 1200;
	$image_h     = 630;
	$image_alt   = 'Fernwell Media Group card: Every yes begins with an answer. Make sure it\'s yours.';
	$answers_alt = 'Fernwell Answers card: direct answers to the questions you\'re asking.';

	if ( is_singular() && ! is_front_page() ) {
		$url = get_permalink();
		$excerpt = get_the_excerpt();
		if ( $excerpt ) {
			$description = wp_strip_all_tags( $excerpt );
		}
		$override = fernwell_post_meta_description( get_the_ID() );
		if ( $override ) {
			$description = $override;
		}
		if ( is_singular( 'post' ) ) {
			$type = 'article';
			$answers_card = fernwell_answers_card();
			if ( $answers_card ) {
				$image     = $answers_card;
				$image_alt = $answers_alt;
			}
		}
		$custom_image = fernwell_post_social_image( get_the_ID() );
		if ( $custom_image ) {
			list( $image, $image_w, $image_h ) = $custom_image;
			$attachment_id  = (int) get_post_meta( get_the_ID(), '_fernwell_social_image_id', true );
			$attachment_alt = $attachment_id ? trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) : '';
			$image_alt      = $attachment_alt ? $attachment_alt : get_the_title();
		}
	} elseif ( is_home() && ! is_front_page() ) {
		$url          = fernwell_answers_url();
		$description  = fernwell_answers_description();
		$answers_card = fernwell_answers_card();
		if ( $answers_card ) {
			$image     = $answers_card;
			$image_alt = $answers_alt;
		}
	}

	echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
	echo '<meta property="og:type" content="' . esc_attr( $type ) . '">' . "\n";
	echo '<meta property="og:site_name" content="Fernwell Media Group">' . "\n";
	echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
	echo '<meta property="og:description" content="' . esc_attr( $description ) . '">' . "\n";
	echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
	echo '<meta property="og:image" content="' . esc_url( $image ) . '">' . "\n";
	echo '<meta property="og:image:width" content="' . esc_attr( $image_w ) . '">' . "\n";
	echo '<meta property="og:image:height" content="' . esc_attr( $image_h ) . '">' . "\n";
	echo '<meta property="og:image:alt" content="' . esc_attr( $image_alt ) . '">' . "\n";
	echo '<meta property="og:locale" content="en_US">' . "\n";
	echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
	echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
	echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '">' . "\n";
	echo '<meta name="twitter:image" content="' . esc_url( $image ) . '">' . "\n";
	echo '<meta name="twitter:image:alt" content="' . esc_attr( $image_alt ) . '">' . "\n";
}
add_action( 'wp_head', 'fernwell_social_meta', 4 );

/**
 * Front-page freshness: the homepage has no post of its own, so anchor its
 * dates to real site-content activity. Both are filterable.
 */
function fernwell_home_dates() {
	$newest = get_posts( array( 'numberposts' => 1, 'post_status' => 'publish', 'orderby' => 'modified', 'order' => 'DESC', 'fields' => 'ids' ) );
	$oldest = get_posts( array( 'numberposts' => 1, 'post_status' => 'publish', 'orderby' => 'date', 'order' => 'ASC', 'fields' => 'ids' ) );
	$modified  = $newest ? get_post_modified_time( 'c', false, $newest[0] ) : current_time( 'c' );
	$published = $oldest ? get_post_time( 'c', false, $oldest[0] ) : $modified;
	return array(
		'published' => apply_filters( 'fernwell_home_published', $published ),
		'modified'  => apply_filters( 'fernwell_home_modified', $modified ),
	);
}

function fernwell_home_freshness_meta() {
	if ( ! is_front_page() ) {
		return;
	}
	$home = fernwell_home_dates();
	printf( "<meta property=\"article:published_time\" content=\"%s\" />\n", esc_attr( $home['published'] ) );
	printf( "<meta property=\"article:modified_time\" content=\"%s\" />\n", esc_attr( $home['modified'] ) );
}
add_action( 'wp_head', 'fernwell_home_freshness_meta', 5 );

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
			$contents = file_get_contents( $file );
			$answers  = fernwell_llms_answers_section();
			if ( $answers ) {
				$pos = strpos( $contents, '## About' );
				if ( false !== $pos ) {
					$contents = substr_replace( $contents, $answers . "\n", $pos, 0 );
				} else {
					$contents .= "\n" . $answers;
				}
			}
			echo $contents;
			exit;
		}
	}
}
add_action( 'template_redirect', 'fernwell_llms_serve' );

/**
 * Dynamic llms.txt section listing every published answer, so the file AI
 * crawlers read always names the citable posts without manual upkeep.
 */
function fernwell_llms_answers_section() {
	$posts = get_posts( array( 'numberposts' => -1, 'post_status' => 'publish' ) );
	if ( ! $posts ) {
		return '';
	}
	$lines = array( '## Fernwell Answers', '' );
	foreach ( $posts as $answer ) {
		$desc    = trim( wp_strip_all_tags( $answer->post_excerpt ) );
		$lines[] = '- [' . $answer->post_title . '](' . get_permalink( $answer ) . ')' . ( $desc ? ': ' . $desc : '' );
	}
	$lines[] = '';
	return implode( "\n", $lines );
}

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
 * Comments and pingbacks are off, enforced in code so production state
 * can't drift no matter what the discussion settings say. The theme
 * renders no comment UI.
 */
add_filter( 'comments_open', '__return_false', 20 );
add_filter( 'pings_open', '__return_false', 20 );
add_filter( 'comments_array', '__return_empty_array', 20 );

/**
 * No tags. Categories stay for organizing the answer set; tags add editor
 * chrome and archive surfaces with no job to do here. Runs after core
 * registers the taxonomy (init 0), so unregistering sticks.
 */
function fernwell_disable_post_taxonomies() {
	unregister_taxonomy_for_object_type( 'post_tag', 'post' );
}
add_action( 'init', 'fernwell_disable_post_taxonomies', 20 );

/**
 * Stamp the lede paragraph of a post with a stable class. The first
 * paragraph is the direct answer; the speakable node targets it, and a
 * real class survives consumers whose CSS engines skip pseudo-selectors.
 */
function fernwell_lede_class( $content ) {
	if ( is_singular( 'post' ) && in_the_loop() ) {
		$content = preg_replace_callback(
			'/<p\b([^>]*)>/',
			function ( $m ) {
				$attrs = $m[1];
				if ( preg_match( '/\bclass="([^"]*)"/', $attrs, $c ) ) {
					$attrs = str_replace( $c[0], 'class="' . $c[1] . ' answer-lede"', $attrs );
				} else {
					$attrs .= ' class="answer-lede"';
				}
				return '<p' . $attrs . '>';
			},
			$content,
			1
		);
	}
	return $content;
}
add_filter( 'the_content', 'fernwell_lede_class', 9 );

/**
 * Listing excerpts end in an ellipsis so it's visually clear there's more
 * to read. Manual excerpts never get excerpt_more, so swap the trailing
 * period for one here. Scoped to the Answers listing loop; the excerpt
 * that feeds meta descriptions is untouched.
 */
function fernwell_excerpt_ellipsis( $excerpt ) {
	if ( is_home() && in_the_loop() ) {
		$excerpt = preg_replace( '/[\s.\x{2026}]+$/u', '', $excerpt ) . '&hellip;';
	}
	return $excerpt;
}
add_filter( 'get_the_excerpt', 'fernwell_excerpt_ellipsis', 20 );

/**
 * Feeds credit the public byline, matching the hardcoded byline in
 * single.php, instead of the account's display name.
 */
function fernwell_feed_author( $name ) {
	return is_feed() ? 'Kenneth Raymond' : $name;
}
add_filter( 'the_author', 'fernwell_feed_author' );

/**
 * No WordPress archives. Date, category, and tag archives 301 to the
 * Fernwell Answers listing; author archives already redirect home via the
 * hardening mu-plugin. The Answers listing itself (is_home) is not an
 * archive and is untouched.
 */
function fernwell_disable_archives() {
	if ( is_date() || is_category() || is_tag() ) {
		wp_safe_redirect( fernwell_answers_url(), 301 );
		exit;
	}
}
add_action( 'template_redirect', 'fernwell_disable_archives' );

/**
 * Keep archive URLs out of the core sitemap too, so engines are never
 * invited to pages that only redirect.
 */
function fernwell_sitemap_providers( $provider, $name ) {
	return 'taxonomies' === $name ? false : $provider;
}
add_filter( 'wp_sitemaps_add_provider', 'fernwell_sitemap_providers', 10, 2 );

/**
 * Structured data: one connected graph, every node cross-referenced by @id.
 *
 * Sitewide: ProfessionalService (#org), Person (#kenneth), WebSite (#website),
 * and a WebPage node for the current URL. Front page adds FAQPage and the four
 * Service nodes. The Answers surfaces (posts page + single posts) add a Blog
 * node named Fernwell Answers. Singular pages add BreadcrumbList; posts add
 * Article, anchored to the Blog node.
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
			'desc' => 'Find out whether AI names you, who it names instead, and why. We test the questions your customers actually ask across ChatGPT, Claude, Perplexity, Gemini, and Google AI Overviews, benchmark your namability against competitors, and deliver a prioritized list of fixes.',
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

	$reviewer_id = apply_filters( 'fernwell_reviewedby_id', $base . '#kenneth' );

	$graph = array(
		array(
			'@type'         => 'ProfessionalService',
			'@id'           => $base . '#org',
			'name'          => 'Fernwell Media Group',
			'alternateName' => 'FMG',
			'description'   => 'Independent Answer Engine Optimization (AEO) consultancy: AI visibility audits, schema and entity implementation, and AI visibility monitoring across ChatGPT, Claude, Perplexity, Gemini, and Google AI Overviews.',
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
			'sameAs'        => array(
				'https://github.com/CMTKennyB/Fernwell-Media',
			),
		),
		array(
			'@type'       => 'Person',
			'@id'         => $base . '#kenneth',
			'name'        => 'Kenneth Raymond',
			'jobTitle'    => 'Founder, Answer Engine Optimization Consultant',
			'description' => 'Communications systems and knowledge infrastructure specialist. Founder of Fernwell Media Group, an independent AEO consultancy.',
			'url'         => $base . '#about',
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
			'hasCredential' => array(
				'@type'              => 'EducationalOccupationalCredential',
				'credentialCategory' => 'degree',
				'educationalLevel'   => 'Bachelor of Science',
				'name'               => 'B.S. in Human Communication',
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
		array(
			'@type'      => 'WebPage',
			'@id'        => $current_url . '#webpage',
			'url'        => $current_url,
			'name'       => wp_get_document_title(),
			'isPartOf'   => array( '@id' => $base . '#website' ),
			'about'      => array( '@id' => $base . '#org' ),
			'reviewedBy' => array( '@id' => $reviewer_id ),
			'inLanguage' => 'en-US',
		),
	);

	if ( is_front_page() ) {
		// Anchor the homepage's dates to real site-content activity.
		$home   = fernwell_home_dates();
		$wp_key = array_key_last( $graph );
		$graph[ $wp_key ]['datePublished'] = $home['published'];
		$graph[ $wp_key ]['dateModified']  = $home['modified'];
	}

	$answers_url = fernwell_answers_url();

	if ( is_home() || is_singular( 'post' ) ) {
		$blog_posts = array();
		foreach ( get_posts( array( 'numberposts' => -1, 'post_status' => 'publish', 'fields' => 'ids' ) ) as $answer_id ) {
			$blog_posts[] = array( '@id' => get_permalink( $answer_id ) . '#article' );
		}
		$graph[] = array(
			'@type'       => 'Blog',
			'@id'         => $answers_url . '#blog',
			'name'        => 'Fernwell Answers',
			'description' => fernwell_answers_description(),
			'url'         => $answers_url,
			'publisher'   => array( '@id' => $base . '#org' ),
			'isPartOf'    => array( '@id' => $base . '#website' ),
			'blogPost'    => $blog_posts,
			'inLanguage'  => 'en-US',
		);
	}

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
		$abstract = fernwell_post_meta_description( $post->ID );
		if ( ! $abstract ) {
			$abstract = wp_strip_all_tags( get_the_excerpt( $post ) );
		}
		$article = array(
			'@type'            => 'BlogPosting',
			'@id'              => $current_url . '#article',
			'headline'         => get_the_title( $post ),
			'datePublished'    => get_the_date( 'c', $post ),
			'dateModified'     => get_the_modified_date( 'c', $post ),
			'author'           => array( '@id' => $base . '#kenneth' ),
			'reviewedBy'       => array( '@id' => $reviewer_id ),
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
		$graph[] = $article;
	}

	$schema = array(
		'@context' => 'https://schema.org',
		'@graph'   => $graph,
	);

	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
}
add_action( 'wp_head', 'fernwell_schema' );
