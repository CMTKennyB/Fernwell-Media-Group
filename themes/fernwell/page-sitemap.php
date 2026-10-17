<?php
/**
 * © 2026 Kenneth Raymond. All rights reserved.
 * Part of the Fernwell WordPress theme.
 * Do not copy, modify, or redistribute without permission.
 *
 * File: Sitemap Template
 * Purpose: Slug-matched template (page-sitemap.php) rendering the HTML
 * sitemap at /sitemap/: the homepage and its sections, every indexable
 * answer, the legal pages, and the machine-readable feeds. Content is
 * built from the site at request time, so it never needs manual upkeep.
 * The WP page with slug "sitemap" exists only to provide the URL.
 *
 * Posts opted out of indexing in the Search & Social metabox (noindex or
 * a canonical pointing elsewhere) are left out, so this page invites
 * engines to the same URLs the XML sitemap does.
 *
 * Location:
 *   /wp-content/themes/fernwell/page-sitemap.php
 */

get_header();

$fernwell_home_sections = array(
	'#what'     => 'What is Answer Engine Optimization?',
	'#scan'     => 'See how an AI engine sees you (free scan)',
	'#how'      => 'How does AEO work in practice?',
	'#faq'      => 'Frequently asked questions',
	'#about'    => 'About Fernwell Media Group',
);
if ( fernwell_book_url() ) {
	$fernwell_home_sections = array_slice( $fernwell_home_sections, 0, 3, true )
		+ array( '#book' => 'The Fernwell Scanner 3.0 (book)' )
		+ array_slice( $fernwell_home_sections, 3, null, true );
}

$fernwell_sitemap_answers = get_posts(
	array(
		'numberposts' => -1,
		'post_status' => 'publish',
		'meta_query'  => fernwell_indexable_meta_query(),
	)
);
?>

<div class="entry">
  <div class="wrap">
    <header class="entry-header">
      <h1>Sitemap</h1>
      <div class="meta">Every page on this site, in one place.</div>
    </header>
    <div class="entry-content">

      <p>Everything published here, grouped the way the site is built. If you're a crawler, the machine-readable versions are at the bottom.</p>

      <h2>Homepage</h2>
      <ul>
        <li><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Homepage</a></li>
        <?php foreach ( $fernwell_home_sections as $fernwell_anchor => $fernwell_label ) : ?>
          <li><a href="<?php echo esc_url( home_url( '/' . $fernwell_anchor ) ); ?>"><?php echo esc_html( $fernwell_label ); ?></a></li>
        <?php endforeach; ?>
      </ul>

      <h2>Fernwell Answers</h2>
      <ul>
        <li><a href="<?php echo esc_url( fernwell_answers_url() ); ?>">All answers</a></li>
        <?php foreach ( $fernwell_sitemap_answers as $fernwell_answer ) : ?>
          <li><a href="<?php echo esc_url( get_permalink( $fernwell_answer ) ); ?>"><?php echo esc_html( get_the_title( $fernwell_answer ) ); ?></a></li>
        <?php endforeach; ?>
      </ul>

      <h2>Legal</h2>
      <ul>
        <li><a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>">Privacy Policy</a></li>
        <li><a href="<?php echo esc_url( home_url( '/terms-of-service/' ) ); ?>">Terms of Service</a></li>
        <li><a href="<?php echo esc_url( home_url( '/cookie-policy/' ) ); ?>">Cookie Policy</a></li>
      </ul>

      <h2>For crawlers and engines</h2>
      <ul>
        <li><a href="<?php echo esc_url( home_url( '/sitemap.xml' ) ); ?>">XML sitemap</a>: the index engines fetch, declared in robots.txt.</li>
        <li><a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>">llms.txt</a>: a plain-text summary of the business and its pages for AI crawlers.</li>
        <li><a href="<?php echo esc_url( get_feed_link() ); ?>">RSS feed</a>: every answer as it's published.</li>
      </ul>

    </div>
  </div>
</div>

<?php
get_footer();
