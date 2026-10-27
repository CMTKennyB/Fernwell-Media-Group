<?php
/**
 * © 2026 Kenneth Raymond. All rights reserved.
 * Part of the Fernwell WordPress theme.
 * Do not copy, modify, or redistribute without permission.
 *
 * File: Front Page Template
 * Purpose: Homepage sections: hero, stats, scanner, AEO explainer,
 * process, book (once FERNWELL_BOOK_URL is set), FAQ, about, and Answers.
 *
 * Location:
 *   /wp-content/themes/fernwell/front-page.php
 */

get_header();
?>

<section class="hero" id="top">
  <div class="wrap">
    <span class="kicker">AEO Research and Education</span>
    <h1>Every yes begins with an answer. Make sure it's yours.</h1>
    <p class="lede">Your customers are asking AI who to trust, what to buy, and where to go. Fernwell Media Group studies who gets named, who gets cited, and who gets left out, and publishes what it learns: the method, the measurements, and a free tool to check your own site.</p>
    <div class="actions">
      <a class="btn primary" href="#scan">Run the free scan</a>
      <a class="btn ghost" href="<?php echo esc_url( fernwell_answers_url() ); ?>">Read the Answers</a>
    </div>
  </div>
</section>

<section class="stats" aria-label="Why AEO matters">
  <div class="wrap grid">
    <div class="stat">
      <div class="num">1B</div>
      <div class="label">people now use ChatGPT every month, the fastest app ever to get there</div>
      <div class="src">Sensor Tower via Reuters, 2026</div>
    </div>
    <div class="stat">
      <div class="num">68%</div>
      <div class="label">of US Google searches now end without a click</div>
      <div class="src">SparkToro/Similarweb, 2026</div>
    </div>
    <div class="stat">
      <div class="num">8%</div>
      <div class="label">of ChatGPT citations overlap with Google's top results</div>
      <div class="src">Ahrefs Brand Radar, 2026</div>
    </div>
    <div class="stat">
      <div class="num">+35%</div>
      <div class="label">more organic clicks for brands cited in AI Overviews</div>
      <div class="src">Seer Interactive, 2025</div>
    </div>
  </div>
</section>

<section class="block alt" id="scan">
  <div class="wrap">
    <h2>See how an AI engine sees you</h2>
    <p class="sub">Start with the first question every AI visibility strategy has to answer: can an engine actually read you? Run a free, single-page scan for legibility and citability. No account. No email.</p>
    <?php echo do_shortcode( '[fmg_readability_scan]' ); ?>
  </div>
</section>

<section class="block" id="what">
  <div class="wrap">
    <h2>What is Answer Engine Optimization?</h2>
    <?php
    // Copy comes from fernwell_faqs(), shared with the FAQPage schema.
    $fernwell_faqs = fernwell_faqs();
    $fernwell_lead = array_shift( $fernwell_faqs );
    ?>
    <div class="answer-first inset-pipe">
      <strong><?php echo esc_html( $fernwell_lead['term'] ); ?></strong><?php echo esc_html( substr( $fernwell_lead['a'], strlen( $fernwell_lead['term'] ) ) ); ?>
    </div>
    <p class="sub">AI engines need to understand who you are before they can cite you. A readable website alone doesn’t put you in the answer. AEO connects the pieces through information architecture: clear entity information, structured data that machines can parse, and content built around real questions. This site puts those principles into practice, from the information you see to the markup underneath it. View the source code, and you see the method at work.</p>
  </div>
</section>

<section class="block alt" id="how">
  <div class="wrap">
    <h2>How does AEO work in practice?</h2>
    <p class="sub">Measure where you stand. Fix what holds you back. Monitor what happens next. You can do each step yourself, and Fernwell Answers covers each one in depth.</p>
    <div class="steps">
      <div class="step">
        <div class="n">1</div>
        <h3>Measure</h3>
        <p>Ask the questions your customers actually ask across leading AI engines, and record who gets named, who gets cited, and who gets left out. Then benchmark your namability against the competitors who take your place in the answer.</p>
      </div>
      <div class="step">
        <div class="n">2</div>
        <h3>Fix</h3>
        <p>Fix what prevents engines from understanding and using you: schema markup, entity inconsistencies, weak information architecture, and content that obscures the answers engines need.</p>
      </div>
      <div class="step">
        <div class="n">3</div>
        <h3>Monitor</h3>
        <p>Keep asking the same questions after every change. Track whether your share of AI answers grows, where competitors gain ground, and what to change next.</p>
      </div>
    </div>
  </div>
</section>

<?php
// The book section renders only once FERNWELL_BOOK_URL is set. The sections
// after it alternate backgrounds, so their stripes shift with it.
$fernwell_book = fernwell_book_url();
$fernwell_alt  = $fernwell_book ? array( ' alt', '', ' alt' ) : array( '', ' alt', '' );
if ( $fernwell_book ) :
	?>
<section class="block book" id="book">
  <div class="wrap">
    <h2>The Fernwell Scanner 3.0</h2>
    <p class="sub">The full method behind the free scan: every check, why it earns its points, and the published engine rules it rests on. Read it to understand your score, or to run the same measurements yourself.</p>
    <div class="actions">
      <a class="btn primary" href="<?php echo esc_url( $fernwell_book ); ?>" target="_blank" rel="noopener">Pre-order on Amazon</a>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="block<?php echo esc_attr( $fernwell_alt[0] ); ?>" id="faq">
  <div class="wrap">
    <h2>Frequently asked questions</h2>
    <div class="faq">
      <?php foreach ( $fernwell_faqs as $fernwell_faq ) : ?>
      <details>
        <summary><?php echo esc_html( $fernwell_faq['q'] ); ?></summary>
        <div class="a"><?php echo esc_html( $fernwell_faq['a'] ); ?></div>
      </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="block<?php echo esc_attr( $fernwell_alt[1] ); ?>" id="about">
  <div class="wrap">
    <h2>About Fernwell Media Group</h2>
    <p class="sub">Fernwell Media Group is an answer engine optimization research and education project by Kenneth Raymond. It studies how AI engines read, cite, and name their sources, and publishes what it finds: Fernwell Answers, the free scanner, and the method behind both.</p>
    <p class="sub">We don’t ask you to take our word for it. We show what we measure, how we measure it, and what the results mean, so you can check your own site the same way.</p>
  </div>
</section>

<section class="block<?php echo esc_attr( $fernwell_alt[2] ); ?>" id="answers">
  <div class="wrap">
    <h2>Fernwell Answers:</h2>
    <p class="sub"><?php echo esc_html( fernwell_answers_description() ); ?></p>
    <div class="answers-strip">
      <?php foreach ( get_posts( array( 'numberposts' => 3, 'post_status' => 'publish', 'meta_query' => fernwell_indexable_meta_query() ) ) as $fernwell_answer ) : ?>
        <a class="answers-strip__item" href="<?php echo esc_url( get_permalink( $fernwell_answer ) ); ?>">
          <h3><?php echo esc_html( get_the_title( $fernwell_answer ) ); ?></h3>
          <span class="meta"><?php echo esc_html( get_the_date( '', $fernwell_answer ) ); ?> <span aria-hidden="true">&middot;</span> <?php echo esc_html( fernwell_reading_time( $fernwell_answer ) ); ?> min read</span>
        </a>
      <?php endforeach; ?>
    </div>
    <p class="answers-strip__all"><a class="btn ghost" href="<?php echo esc_url( fernwell_answers_url() ); ?>">All answers <span aria-hidden="true">&rarr;</span></a></p>
  </div>
</section>

<?php
get_footer();
