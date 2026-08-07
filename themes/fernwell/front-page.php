<?php
/**
 * © 2026 Kenneth Raymond — All rights reserved.
 * Part of the Fernwell WordPress theme.
 * Do not copy, modify, or redistribute without permission.
 *
 * File: Front Page Template
 * Purpose: Homepage sections: hero, stats, AEO explainer, process,
 * services, FAQ, about, and contact.
 *
 * Location:
 *   /wp-content/themes/fernwell/front-page.php
 */

get_header();
?>

<section class="hero" id="top">
  <div class="wrap">
    <span class="kicker">An AEO Consultancy</span>
    <h1>Every yes begins with an answer. Make sure it's yours.</h1>
    <p class="lede">Your customers are asking AI engines who to trust, what to buy, and where to go. Fernwell Media Group makes sure the answer includes you. Independent consultancy, fixed-scope engagements, reporting you can read without a glossary.</p>
    <div class="actions">
      <a class="btn primary" href="#contact">Request an AI Visibility Audit</a>
      <a class="btn ghost" href="#how">See how it works</a>
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
      <div class="src">SparkToro / Similarweb, 2026</div>
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
    <h2>See how an AI engine reads your site</h2>
    <p class="sub">Before we ever talk, run the same first check we do: a free, single-page scan that scores whether AI engines can actually read you. No account, no email.</p>
    <?php echo do_shortcode( '[fmg_readability_scan]' ); ?>
  </div>
</section>

<section class="block" id="what">
  <div class="wrap">
    <h2>What is Answer Engine Optimization?</h2>
    <div class="answer-first">
      <strong>Answer Engine Optimization (AEO)</strong> is the practice of structuring your website and content so that AI engines cite your business when they answer the questions your customers ask. Where SEO competes for rankings, AEO competes for citations.
    </div>
    <p class="sub">The debate about whether AI changes search already ended. Your customers increasingly get one synthesized answer instead of ten links, and if that answer names a competitor, no amount of ranking fixes it. AEO makes your business legible to the engines writing those answers: a clearly defined entity, structured data they can parse, and content shaped like the answers they assemble. Information architecture is how you solve for it, and this page is built that way on purpose. View the source and you'll see the method for sale.</p>
  </div>
</section>

<section class="block alt" id="how">
  <div class="wrap">
    <h2>How does an AEO engagement work?</h2>
    <p class="sub">Three phases, each with its own deliverable. You can stop after any of them and keep everything.</p>
    <div class="steps">
      <div class="step">
        <div class="n">1</div>
        <h3>Measure</h3>
        <p>We test the questions your customers actually ask across the top AI engines and record who gets named, who gets cited, and where you're absent while competitors appear.</p>
      </div>
      <div class="step">
        <div class="n">2</div>
        <h3>Fix</h3>
        <p>Schema markup, entity cleanup, and answer-first restructuring of the pages that matter. Engines get an unambiguous, machine-readable picture of who you are and what you do.</p>
      </div>
      <div class="step">
        <div class="n">3</div>
        <h3>Compound</h3>
        <p>A question-shaped content plan plus monthly citation tracking, so your share of AI answers grows and you can watch it happen in a report written in plain English.</p>
      </div>
    </div>
  </div>
</section>

<section class="block" id="services">
  <div class="wrap">
    <h2>Services</h2>
    <p class="sub">Agencies charge five figures before the work starts. Fernwell Media Group keeps engagements fixed-scope, fixed-price, and free of account managers. You work directly with the person who does the work.</p>
    <div class="services">
      <div class="card">
        <div class="tag">Start here</div>
        <h3>AI Visibility Audit</h3>
        <p>A systematic test of your presence across ChatGPT, Claude, Perplexity, Gemini, and Google AI Overviews, benchmarked against competitors, with a prioritized fix list. Fixed fee, delivered as a report plus a working session.</p>
        <a href="#contact">Request an audit &rarr;</a>
      </div>
      <div class="card">
        <div class="tag">Implementation</div>
        <h3>AEO Sprint</h3>
        <p>Four to six weeks executing the audit: structured data across your site, entity consistency everywhere your business appears, and answer-first rewrites of the pages engines pull from.</p>
        <a href="#contact">Discuss a sprint &rarr;</a>
      </div>
      <div class="card">
        <div class="tag">Ongoing</div>
        <h3>Citation Monitoring</h3>
        <p>Monthly prompt testing and citation tracking across engines, share-of-voice against competitors, and a plain-English report on what moved and why.</p>
        <a href="#contact">Set up monitoring &rarr;</a>
      </div>
      <div class="card">
        <div class="tag">For teams</div>
        <h3>Team Enablement</h3>
        <p>Workshops and advisory for marketing teams and agencies that want AEO capability in-house. Your team keeps the playbook.</p>
        <a href="#contact">Book a workshop &rarr;</a>
      </div>
    </div>
  </div>
</section>

<section class="block alt" id="faq">
  <div class="wrap">
    <h2>Frequently asked questions</h2>
    <div class="faq">
      <details>
        <summary>How is AEO different from SEO?</summary>
        <div class="a">SEO optimizes for a list of links; AEO optimizes for a single synthesized answer. Only 8% of ChatGPT citations overlap with Google's top organic results, so ranking well does not guarantee AI engines mention you. AEO adds entity definition, structured data, and answer-first content on top of a sound SEO foundation. The two work together; neither replaces the other.</div>
      </details>
      <details>
        <summary>How do I know if AI engines mention my business today?</summary>
        <div class="a">Ask them what your customers ask and see who gets named. That's exactly what the AI Visibility Audit does, systematically, across the top engines and the query types that matter for your market, with competitors benchmarked alongside you.</div>
      </details>
      <details>
        <summary>How long until results show up?</summary>
        <div class="a">Technical fixes can be reflected within weeks as engines recrawl. Citation share builds over one to two quarters as answer-first content gets published and picked up. Monitoring makes the progress visible month over month, so you're never guessing.</div>
      </details>
      <details>
        <summary>Do I need this if I already invest in SEO?</summary>
        <div class="a">Yes, and your SEO investment makes AEO cheaper to add. The overlap between Google's top results and AI citations is small, which means AI visibility is a separate battle fought with related weapons. An audit will tell you quickly whether there's a real gap.</div>
      </details>
    </div>
  </div>
</section>

<section class="block" id="about">
  <div class="wrap">
    <h2>Who's behind Fernwell Media Group?</h2>
    <div class="about-grid">
      <div class="headshot">Headshot<br>goes here</div>
      <div>
        <p><strong>Fernwell Media Group is Kenneth Raymond,</strong> a communications systems and knowledge infrastructure specialist. [One or two more sentences of bio: how you came to AEO, one credibility anchor such as a result, a client type, or relevant history.]</p>
        <p>The consultancy works solo and fixed-scope on purpose. You get the person who does the work, reports written for humans, and a site that practices what it sells: this page is structured, marked up, and written exactly the way yours will be.</p>
      </div>
    </div>
  </div>
</section>

<section class="block alt" id="contact">
  <div class="wrap">
    <div class="contact-box">
      <div class="stackmark">
        <div class="mono">F<span class="m">M</span>G</div>
        <div class="rule"></div>
        <div class="word">Fernwell Media Group</div>
      </div>
      <h3>Find out what AI says about your business.</h3>
      <p>Send your site and your three biggest competitors. You'll get back a free spot-check of one question your customers are asking AI right now.</p>
      <a class="btn primary" href="mailto:hello@fernwellmedia.com?subject=AI%20Visibility%20Spot-Check">Email for a free spot-check</a>
    </div>
  </div>
</section>

<?php
get_footer();
