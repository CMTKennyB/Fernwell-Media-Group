<?php
/**
 * © 2026 Kenneth Raymond. All rights reserved.
 * Part of the Fernwell WordPress theme.
 * Do not copy, modify, or redistribute without permission.
 *
 * File: Theme Functions
 * Purpose: Theme supports, style and font enqueue, SVG favicon fallback,
 * head metadata, llms.txt, and sitemap rules. The JSON-LD schema graph
 * lives in inc/schema.php.
 *
 * Location:
 *   /wp-content/themes/fernwell/functions.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FERNWELL_VERSION', '3.0.0' );

require get_theme_file_path( 'inc/post-meta.php' );
require get_theme_file_path( 'inc/schema.php' );

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
 * Store listing for The Fernwell Scanner 3.0 book. Empty until launch, and
 * while empty every book surface (homepage section, nav link, post CTA
 * button, sitemap entry, Book schema node, llms.txt line) stays hidden.
 * Set it here, or define it earlier in wp-config.php.
 */
if ( ! defined( 'FERNWELL_BOOK_URL' ) ) {
	define( 'FERNWELL_BOOK_URL', '' );
}

/**
 * The book's store URL, or an empty string before launch.
 */
function fernwell_book_url() {
	return (string) FERNWELL_BOOK_URL;
}

/**
 * Homepage questions and answers, the single source for the visible copy in
 * front-page.php and the FAQPage schema. The first entry is the answer-first
 * definition box; its 'term' is the bolded lead the answer opens with. The
 * rest render as the FAQ section, in order. Plain text: templates escape it.
 */
function fernwell_faqs() {
	return array(
		array(
			'q'    => 'What is Answer Engine Optimization (AEO)?',
			'term' => 'Answer Engine Optimization (AEO)',
			'a'    => 'Answer Engine Optimization (AEO) is the practice of making you understandable, citable, and nameable by the AI engines answering your customers’ questions. Where SEO competes for rankings, AEO competes for inclusion in the answer.',
		),
		array(
			'q' => 'What’s the difference between AEO and SEO?',
			'a' => 'SEO optimizes for visibility in ranked search results. AEO optimizes for inclusion in AI-generated answers. Strong SEO helps engines find you, but it does not guarantee they will understand, cite, or name you. AEO builds on this foundation with entity definition, structured data, information architecture, answer-first content, and direct measurement of AI answers.',
		),
		array(
			'q' => 'How do I know if AI engines are naming me?',
			'a' => 'Ask the questions your customers actually ask and measure who gets named, who gets cited, and who gets left out. A single prompt tells you very little. A repeatable benchmark across engines, questions, and competitors measures your namability and shows who is taking your place in the answer. The free scan above checks the groundwork: whether an engine can read and cite the page.',
		),
		array(
			'q' => 'How long does AEO take to work?',
			'a' => 'There isn’t a universal timeline. Technical changes can appear as engines recrawl, while changes in namability and citation share can take longer and vary by engine. This is why you keep testing the same questions after every change. Repeat measurement shows what moved, what didn’t, and what to do next.',
		),
		array(
			'q' => 'Do I need AEO if I already invest in SEO?',
			'a' => 'Probably. Strong SEO gives AEO a better foundation, but it does not answer the same question. SEO tells you whether people can find you in search results. AEO tells you whether an engine understands, cites, and names you when it answers that question. Measure first: a benchmark shows whether there is actually a gap before you invest in fixing one.',
		),
	);
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
 * A post's real "last changed" moment, as a Unix timestamp. Scheduled
 * posts carry a modified stamp from before they were published, so the
 * later of the two dates is the honest answer for schema and the sitemap.
 * The visible "Updated" line keeps its own stricter rule.
 */
function fernwell_post_modified_gmt( $post ) {
	$post = get_post( $post );
	return max( (int) get_post_modified_time( 'U', true, $post ), (int) get_post_time( 'U', true, $post ) );
}

/**
 * The post's picture for schema: the metabox social image if set, else the
 * Fernwell Answers card, else the FMG card. Same order og:image uses, so
 * every surface shows the same picture. Cards are 1200x630.
 */
function fernwell_post_schema_image( $post_id ) {
	$custom = fernwell_post_social_image( $post_id );
	if ( $custom ) {
		return array( '@type' => 'ImageObject', 'url' => $custom[0], 'width' => $custom[1], 'height' => $custom[2] );
	}
	$card = fernwell_answers_card();
	return array(
		'@type'  => 'ImageObject',
		'url'    => $card ? $card : get_theme_file_uri( 'assets/og-card.png' ),
		'width'  => 1200,
		'height' => 630,
	);
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
 * No emoji plumbing. The site uses none, so the detection module, its JSON
 * settings block, the inline CSS, and the s.w.org prefetch are dropped from
 * every page, front and admin.
 */
function fernwell_disable_emoji() {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
	remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
	remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
	add_filter( 'emoji_svg_url', '__return_false' );
}
add_action( 'init', 'fernwell_disable_emoji' );

/**
 * Third-party front-end assets the site does not use. The WordPress AI
 * plugin enqueues its summarization block styles on
 * every public page through enqueue_block_assets; the plugin stays active
 * for the editor, the public pages stop carrying its CSS.
 */
function fernwell_trim_third_party_assets() {
	if ( is_admin() ) {
		return;
	}
	wp_dequeue_style( 'ai_summarization' );
	wp_deregister_style( 'ai_summarization' );
}
add_action( 'enqueue_block_assets', 'fernwell_trim_third_party_assets', 20 );

/**
 * Head links that do not earn their place: the comments feed (comments are
 * hard-off) and the shortlink (permalinks are already short). The Link
 * response header for the shortlink goes with it. The posts feed stays.
 */
add_filter( 'feed_links_show_comments_feed', '__return_false' );
remove_action( 'wp_head', 'feed_links_extra', 3 );
remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
remove_action( 'template_redirect', 'wp_shortlink_header', 11 );

/**
 * Meta description, Open Graph, and Twitter Card tags.
 * WordPress core emits none of these; the theme owns them so every share
 * and crawl gets a description and the FMG card image.
 */
function fernwell_social_meta() {
	$title       = wp_get_document_title();
	$description = 'Fernwell Media Group is an Answer Engine Optimization (AEO) research and education project by Kenneth Raymond. It studies how AI engines read, cite, and name sources, and publishes the method.';
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
 * URL of the Answers listing page being viewed: the listing itself on page
 * one, the paginated URL after that. Shared by the canonical and the schema.
 */
function fernwell_answers_page_url() {
	return is_paged() ? get_pagenum_link( (int) get_query_var( 'paged' ) ) : fernwell_answers_url();
}

/**
 * rel=canonical for the Answers listing. Core emits one only on singular
 * pages, so the theme adds it here to match the listing's og:url and schema
 * (fernwell_answers_url()). Paginated pages point at themselves.
 */
function fernwell_answers_canonical() {
	if ( ! is_home() || is_front_page() ) {
		return;
	}
	echo '<link rel="canonical" href="' . esc_url( fernwell_answers_page_url() ) . '" />' . "\n";
}
add_action( 'wp_head', 'fernwell_answers_canonical', 4 );

/**
 * Front-page freshness: the homepage has no post of its own, so anchor its
 * dates to real site-content activity. Both are filterable.
 */
function fernwell_home_dates() {
	$newest = get_posts( array( 'numberposts' => 1, 'post_status' => 'publish', 'orderby' => 'modified', 'order' => 'DESC', 'fields' => 'ids' ) );
	$latest = get_posts( array( 'numberposts' => 1, 'post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids' ) );
	$oldest = get_posts( array( 'numberposts' => 1, 'post_status' => 'publish', 'orderby' => 'date', 'order' => 'ASC', 'fields' => 'ids' ) );
	// Newest activity is the later of "most recently modified" and "most
	// recently published", so a scheduled post counts from its publish day.
	$activity  = max(
		$newest ? (int) get_post_modified_time( 'U', true, $newest[0] ) : 0,
		$latest ? (int) get_post_time( 'U', true, $latest[0] ) : 0
	);
	$modified  = $activity ? wp_date( 'c', $activity ) : current_time( 'c' );
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
		// One canonical URL: the slashed form goes back to the bare file path.
		$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
		if ( is_string( $path ) && '/' === substr( $path, -1 ) ) {
			wp_safe_redirect( home_url( '/llms.txt' ), 301 );
			exit;
		}
		$file = get_theme_file_path( 'llms.txt' );
		if ( file_exists( $file ) ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
			$contents = file_get_contents( $file );
			if ( fernwell_book_url() ) {
				// The book joins the Resources list, last entry before Key Pages.
				$book     = '- [The Fernwell Scanner 3.0](' . fernwell_book_url() . '): The book. The full method behind the free scan: every check, why it earns its points, and the published engine rules it rests on.';
				$contents = str_replace( "\n\n## Key Pages", "\n" . $book . "\n\n## Key Pages", $contents );
			}
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
 * Keep the canonical redirect off /llms.txt. With only the theme's query
 * var set, WordPress treats the request as the front page and 301s it to
 * /llms.txt/. Disabling it here lets the handler above own the URL: the
 * bare path serves directly and the slashed form 301s back to it, like robots.txt.
 */
function fernwell_llms_canonical( $redirect_url ) {
	return get_query_var( 'fernwell_llms' ) ? false : $redirect_url;
}
add_filter( 'redirect_canonical', 'fernwell_llms_canonical' );

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
 * read it while the rest of the site is gated.
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
 * Public byline on Fernwell Answers, shared by single.php and feeds. The
 * schema credits the same person through the #kenneth node.
 */
function fernwell_byline() {
	return 'Kenneth Raymond';
}

/**
 * Feeds credit the public byline instead of the account's display name.
 */
function fernwell_feed_author( $name ) {
	return is_feed() ? fernwell_byline() : $name;
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
 * XML sitemap. The FMG AEO mu-plugin serves it at /sitemap.xml and retires
 * core's wp-sitemap.xml; the theme supplies the site-specific rules below
 * through the plugin's filters. The HTML counterpart for people is
 * /sitemap/ (page-sitemap.php).
 *
 * Belt and braces: should core sitemaps ever come back, keep archive URLs
 * out, so engines are never invited to pages that only redirect.
 */
function fernwell_sitemap_providers( $provider, $name ) {
	return 'taxonomies' === $name ? false : $provider;
}
add_filter( 'wp_sitemaps_add_provider', 'fernwell_sitemap_providers', 10, 2 );

/**
 * Meta query that keeps posts opted out of indexing in the Search & Social
 * metabox (noindex, or a canonical pointing elsewhere) out of any listing
 * meant for engines. The metabox deletes empty keys, so NOT EXISTS is the
 * whole test. Shared by the XML sitemap and the HTML sitemap page.
 */
function fernwell_indexable_meta_query() {
	return array(
		array( 'key' => '_fernwell_noindex', 'compare' => 'NOT EXISTS' ),
		array( 'key' => '_fernwell_canonical', 'compare' => 'NOT EXISTS' ),
	);
}

function fernwell_sitemap_query_args( $args, $post_type ) {
	if ( 'post' === $post_type ) {
		$args['meta_query'] = fernwell_indexable_meta_query();
	}
	return $args;
}
add_filter( 'fmg_aeo_sitemap_query_args', 'fernwell_sitemap_query_args', 10, 2 );

/**
 * lastmod for pages whose content is really the posts: the homepage reports
 * the same dateModified its schema does (fernwell_home_dates), and the
 * Answers listing and the sitemap page report whichever is newer, the page
 * itself or the most recently modified answer.
 */
function fernwell_sitemap_entry( $entry, $post ) {
	if ( 'post' === $post->post_type ) {
		$entry['lastmod'] = wp_date( DATE_W3C, fernwell_post_modified_gmt( $post ) );
		return $entry;
	}
	if ( 'page' !== $post->post_type ) {
		return $entry;
	}
	$home = fernwell_home_dates();
	if ( (int) get_option( 'page_on_front' ) === $post->ID ) {
		$entry['lastmod'] = $home['modified'];
		return $entry;
	}
	if ( (int) get_option( 'page_for_posts' ) === $post->ID || 'sitemap' === $post->post_name ) {
		$activity = strtotime( $home['modified'] );
		if ( $activity > (int) get_post_modified_time( 'U', true, $post ) ) {
			$entry['lastmod'] = wp_date( DATE_W3C, $activity );
		}
	}
	return $entry;
}
add_filter( 'fmg_aeo_sitemap_entry', 'fernwell_sitemap_entry', 10, 2 );
