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
    <p class="lede">Your customers are asking AI who to trust, what to buy, and where to go. Fernwell Media Group measures who gets named, who gets cited, and who gets left out. We show you where you stand, why competitors are taking your place in the answer, and what needs to change.</p>
    <div class="actions">
      <a class="btn primary" href="mailto:hello@fernwellgroup.com?subject=Request%20your%20audit">Request your audit</a>
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
    <h2>See how an AI engine sees you</h2>
    <p class="sub">Start with the first question every AI visibility strategy has to answer: can an engine actually read you? Run a free, single-page scan for readability and citability. No account. No email.</p>
    <?php echo do_shortcode( '[fmg_readability_scan]' ); ?>
  </div>
</section>

<section class="block" id="what">
  <div class="wrap">
    <h2>What is Answer Engine Optimization?</h2>
    <div class="answer-first">
      <strong>Answer Engine Optimization (AEO)</strong> is the practice of making you understandable, citable, and nameable by the AI engines answering your customers’ questions. Where SEO competes for rankings, AEO competes for inclusion in the answer.
    </div>
    <p class="sub">AI engines need to understand who you are before they can cite you. A readable website alone doesn’t put you in the answer. AEO connects the pieces through information architecture: clear entity information, structured data that machines can parse, and content built around real questions. This site puts those principles into practice, from the information you see to the markup underneath it. View the source code, and you'll see the method for sale.</p>
  </div>
</section>

<section class="block alt" id="how">
  <div class="wrap">
    <h2>How does an AEO engagement work?</h2>
    <p class="sub">Measure where you stand. Fix what’s holding you back. Monitor what happens next. You decide how far you want to go, and you keep all deliverables.</p>
    <div class="steps">
      <div class="step">
        <div class="n">1</div>
        <h3>Measure</h3>
        <p>We ask the questions your customers actually ask across leading AI engines and measure who gets named, who gets cited, and who gets left out. Then we benchmark your namability against the competitors who are taking your place in the answer.</p>
      </div>
      <div class="step">
        <div class="n">2</div>
        <h3>Fix</h3>
        <p>We fix what prevents engines from understanding and using you: schema markup, entity inconsistencies, underperforming information architecture, and content that obscures the answers they need.</p>
      </div>
      <div class="step">
        <div class="n">3</div>
        <h3>Monitor</h3>
        <p>We build for the questions your market is asking, and we continue to measure the answers. You see if your share of AI answers is growing, where competitors are gaining ground, and what to do next.</p>
      </div>
    </div>
  </div>
</section>

<section class="block" id="services">
  <div class="wrap">
    <h2>Services</h2>
    <p class="sub">Start with measurement. Fix what the data reveals. Monitor as the answer environment changes. Or bring the method in-house.</p>
    <div class="services">
      <div class="card">
        <div class="tag">Measure</div>
        <h3>AI Visibility Audit</h3>
        <p>Find out whether AI names you, who it names instead, and why. We test the questions your customers actually ask across ChatGPT, Claude, Perplexity, Gemini, and Google AI Overviews, benchmark your namability against competitors, and deliver a prioritized list of fixes.</p>
        <a href="mailto:hello@fernwellgroup.com?subject=Request%20an%20audit">Request an audit &rarr;</a>
      </div>
      <div class="card">
        <div class="tag">Fix</div>
        <h3>AEO Sprint</h3>
        <p>We turn the audit into the stuff engines need: structured data, entity consistency, information architecture, and answer-first rewrites of the pages most likely to shape AI answers about you.</p>
        <a href="mailto:hello@fernwellgroup.com?subject=Discuss%20a%20sprint">Discuss a sprint &rarr;</a>
      </div>
      <div class="card">
        <div class="tag">Monitor</div>
        <h3>AI Visibility Monitoring</h3>
        <p>We continue testing the questions that matter in your market across leading AI engines and track namability, citation share, and competitor movement over time. You see what changed, what didn't, and what to do next.</p>
        <a href="mailto:hello@fernwellgroup.com?subject=Set%20up%20monitoring">Set up monitoring &rarr;</a>
      </div>
      <div class="card">
        <div class="tag">Bring it in-house</div>
        <h3>Team Enablement</h3>
        <p>Workshops and advisory for marketing teams and agencies that want to build AEO capability in-house. We’ll teach the framework, measurement, and implementation. Your team keeps the method.</p>
        <a href="mailto:hello@fernwellgroup.com?subject=Book%20a%20workshop">Book a workshop &rarr;</a>
      </div>
    </div>
  </div>
</section>

<section class="block alt" id="faq">
  <div class="wrap">
    <h2>Frequently asked questions</h2>
    <div class="faq">
      <details>
        <summary>What’s the difference between AEO and SEO?</summary>
        <div class="a">SEO optimizes for visibility in ranked search results. AEO optimizes for inclusion in AI-generated answers. Strong SEO helps engines find you, but it does not guarantee they will understand, cite, or name you. AEO builds on this foundation with entity definition, structured data, information architecture, answer-first content, and direct measurement of AI answers.</div>
      </details>
      <details>
        <summary>How do I know if AI engines are naming me?</summary>
        <div class="a">Ask the questions your customers actually ask and measure who gets named, who gets cited, and who gets left out. A single prompt tells you very little. A repeatable benchmark across engines, questions, and competitors measures your namability and shows who is taking your place in the answer. This is what the AI Visibility Audit does.</div>
      </details>
      <details>
        <summary>How long does AEO take to work?</summary>
        <div class="a">There isn’t a universal timeline. Technical changes can appear as engines recrawl, while changes in namability and citation share can take longer and vary by engine. This is why we keep testing the same questions after changes are made. Monitoring shows what moved, what didn’t, and what to do next.</div>
      </details>
      <details>
        <summary>Do I need AEO if I already invest in SEO?</summary>
        <div class="a">Probably. Strong SEO gives AEO a better foundation, but it does not answer the same question. SEO tells you whether people can find you in search results. AEO tells you whether an engine understands, cites, and names you when it answers that question. An audit shows whether there is actually a gap before you invest in fixing one.</div>
      </details>
    </div>
  </div>
</section>

<section class="block" id="about">
  <div class="wrap">
    <h2>About Fernwell Media Group</h2>
    <p class="sub">Fernwell Media Group is an answer engine optimization consultancy built around measurement, implementation, and transparency. We help you understand how AI engines see you, fix what prevents them from using you in answers, and measure the difference these changes make.</p>
    <p class="sub">We don’t ask you to take our word for it. We show you what we measure, how we measure it, and what the results mean. You see where you stand, what needs to change, and the difference the work makes.</p>
  </div>
</section>

<section class="block alt" id="answers">
  <div class="wrap">
    <h2>Fernwell Answers:</h2>
    <p class="sub"><?php echo esc_html( fernwell_answers_description() ); ?></p>
    <div class="answers-strip">
      <?php foreach ( get_posts( array( 'numberposts' => 3, 'post_status' => 'publish' ) ) as $fernwell_answer ) : ?>
        <a class="answers-strip__item" href="<?php echo esc_url( get_permalink( $fernwell_answer ) ); ?>">
          <h3><?php echo esc_html( get_the_title( $fernwell_answer ) ); ?></h3>
          <span class="meta"><?php echo esc_html( get_the_date( '', $fernwell_answer ) ); ?> <span aria-hidden="true">&middot;</span> <?php echo esc_html( fernwell_reading_time( $fernwell_answer ) ); ?> min read</span>
        </a>
      <?php endforeach; ?>
    </div>
    <p class="answers-strip__all"><a class="btn ghost" href="<?php echo esc_url( fernwell_answers_url() ); ?>">All answers <span aria-hidden="true">&rarr;</span></a></p>
  </div>
</section>

<section class="block" id="contact">
  <div class="wrap">
    <div class="contact-box">
      <div class="stackmark">
        <div class="mono">F<span class="m">M</span>G</div>
        <div class="rule"></div>
        <div class="word">Fernwell Media Group</div>
      </div>
      <h2>Does AI name you?</h2>
      <p>Send us your site and your three biggest competitors. We’ll run a free spot-check on a question your customers are asking and show you who gets named.</p>
      <a class="btn primary" href="mailto:hello@fernwellgroup.com?subject=AI%20Visibility%20Spot-Check">Get your free spot-check</a>
    </div>
  </div>
</section>

<?php
get_footer();
