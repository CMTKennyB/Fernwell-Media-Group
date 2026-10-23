# Changelog

All notable changes to the Fernwell Media site code (the `fernwell` theme and mu-plugins) are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Changed

- **The site is now a research and education resource.** Fernwell Media
  Group no longer offers consulting. The homepage, header, posts, structured
  data, llms.txt and robots.txt now describe a project that studies how AI
  engines read, cite, and name their sources, and publishes the method.
- **Homepage**: the hero leads with the free scan and Fernwell Answers. "How
  does AEO work in practice?" walks through measuring, fixing, and
  monitoring as steps you can take yourself. The Services section and the
  spot-check offer are gone, and FAQ answers point to the free scan and to
  repeat measurement instead of paid work.
- **New logo**: the header shows the new Fernwell Media Group lockup in
  place of the FMG text badge.
- **Header and posts**: the header button and the closing call to action on
  every answer now say "Run the free scan."
- **Scanner**: the form note and both disclaimers now explain that
  namability takes a benchmark across engines and questions, rather than
  pointing to an audit. The scan limit message no longer offers to run scans
  by email. Results no longer offer to send your scan to Fernwell: the email
  button and its invitation are gone, "Copy results" remains, and the copied
  summary ends with your scores and checks, with no competitor prompt.
- **Legal pages**: the Terms and Privacy Policy no longer refer to consulting
  services or engagements.
- **Not-found page**: the message now describes Fernwell as a site, not a
  company.
- **Structured data**: the organization's `sameAs` now points to the public
  source repository, github.com/CMTKennyB/Fernwell-Media-Group. Pages
  and what they present now reference each other both ways (`mainEntity` and
  `mainEntityOfPage`): the Fernwell Answers listing with the blog, and each
  answer with its article. The homepage FAQ is now linked to the homepage as
  one of its parts.
- **Structured data accuracy**: later pages of the Fernwell Answers listing
  now describe their own address, matching their canonical link, instead of
  claiming to be the first page. Not-found pages and search results no
  longer describe themselves as the homepage; they carry only the site-wide
  organization, founder, and website details.
- **Structured data safety**: the data block now escapes characters that
  could otherwise end it early inside the page.
- **One source for the homepage FAQ**: the visible questions and answers and
  their structured data now come from the same copy, so the two cannot drift
  apart. The structured data code now lives in its own file,
  `themes/fernwell/inc/schema.php`.
- **Fernwell Answers byline**: every answer is now credited to Kenneth
  Raymond, on the page, in the feed, and in the structured data.
- **Founder details**: the founder's structured data now describes his work
  as an author, researcher, and software developer, lists his areas of
  expertise in full, and links his Amazon author page.

### Added

- **Privacy Policy: Free Page Scanner section** explains what the scanner
  keeps: a one-way hash of your IP address for the hourly scan limit, a
  fifteen-minute copy of each result, and an anonymous score record with no
  addresses.

- **Book support, hidden until launch**: once the store link for *The
  Fernwell Scanner 3.0* is set, the book appears on the homepage, in the
  header, at the end of every answer, in the sitemap, in llms.txt, and as a
  Book entry in the structured data.

- **Site Health: robots.txt check** confirms crawlers get the site's own
  crawler policy. It warns when a robots.txt file in the site root replaces
  the policy, or when a host, CDN, or another plugin changes the file on the
  way out.

### Fixed

- **Scanner safety**: the scanner now connects only to the exact address it
  checked, so a site cannot redirect it to a private network mid-scan. The
  hourly scan limit holds when many scans arrive at once, and every scan
  stops at its time limit, redirects included.
- **Scanner on phones**: the reason behind each engine's result now shows
  under the engine name instead of being hidden on small screens.
- **Scanner privacy**: the anonymous score record no longer keeps the
  written reasons behind a result, which could quote a page address.
- **Scanner reliability**: a site that sends two redirect locations no
  longer breaks the scan, and the hourly limit now applies before the
  scanner looks up an address.
- **Posts set to noindex** no longer appear in llms.txt, the blog's
  structured data, the homepage list of answers, or the 404 page, matching
  the sitemaps.
- **robots.txt for named crawlers**: the AI, answer-engine, and search
  crawlers welcomed by name, Bingbot among them, now skip the admin area and
  internal search results, like every other crawler. Search results are now
  kept out of the crawl on every page, not only the homepage.
- **Sitemap addresses**: the XML sitemap and its stylesheet now appear only
  at /sitemap.xml and /sitemap.xsl, never as a copy of another page. A
  "sitemap" parameter on an ordinary link no longer redirects visitors to
  the sitemap.
- **Sitemap reliability**: stray output from elsewhere on the site can no
  longer break the sitemap, a malformed entry is left out instead of
  corrupting the file, and a post with a missing date no longer shows the
  year -0001.
- **Sitemap speed**: the URL list is cached and refreshed whenever a post
  changes. Engines that already hold the current sitemap get a short "not
  modified" reply. Past 50,000 addresses, the sitemap becomes an index of
  numbered files, as the sitemap standard requires.
- **Sitemap browser view**: it shows the new Fernwell Media Group lockup,
  takes its look from the site's own stylesheet so the two stay matched,
  renders in standards mode, keeps its links visible on phones, and no
  longer prints an empty "Last modified" label.
- **One address for machine files**: the sitemap, its stylesheet, and
  llms.txt now send visitors from www and any other alternate address to
  the main domain, like every other page, instead of serving a second copy.
- **Permalinks after a deploy**: new or changed site addresses, llms.txt
  included, now take effect on their own without a manual permalink save.

## [3.0.0] - 2026-09-26

### Added

- **Scanner: eligibility layer** (`fmg-readability-scan.php`): per-engine
  states (Eligible, Ineligible, Reduced, Indeterminate, Not documented) for
  Google AI Overviews and AI Mode, Gemini app grounding (Google-Extended),
  ChatGPT search, Bing and Copilot, Perplexity, Claude, Apple, DuckDuckGo,
  Alexa search, Meta AI and Mistral search, from per-path robots.txt evaluation (Google
  matching rules, same-token group merging, Applebot fallback, path plus
  query), noindex scoped by meta name and X-Robots-Tag token, nosnippet and
  max-snippet:0, canonical (link element and HTTP Link header), and the
  robots.txt fetch outcome (4xx allow-all, 5xx and 429 indeterminate).
- **Scanner: scorability state**: under 50 word tokens of server-side main
  text suppresses both dials instead of capping them.
- **Scanner: page-type detection** (article-like, Q&A, product, home,
  About, local) used only for check applicability; not-applicable checks
  leave the denominator.
- **Scanner: checks** L4_agree (visible date matches machine date),
  L4_clean (date red flags: future dates, published after modified,
  per-request modified stamps, non-ISO dates), C1_identity (home, About and
  local pages: markup name matches the visible name; well-formed sameAs;
  LocalBusiness address matches the visible address).
- **Scanner: fetch** keeps the query string, lowercases scheme and host,
  follows up to 10 hops with an RFC 3986 resolver, retries a dropped
  connection once, runs the browser-agent probe only after a failure, and
  reads robots.txt with a 500 KiB cap and 5 redirect hops.
- **Scanner: parsing** joins text nodes with spaces, counts Unicode word
  tokens (CJK per character), chooses the largest main, role=main or
  article candidate with a 25% floor, strips chrome inside the region,
  excludes hidden text from every content metric, parses JSON-LD through
  CDATA and comment wrappers and trailing commas, reduces IRI @type values,
  walks nested nodes, parses dates as strict ISO 8601 only, reads time
  elements inside the main region only, and uses a Porter stemmer.
- **Scanner: challenge fingerprints** tightened to headers, thin bodies and
  error statuses; soft 404 detection.
- **Scan Scores page**: eligibility states by engine; spec_version,
  eligibility and states columns in `fmg_scan_log` (log db version 2);
  unscorable rows counted separately.
- **Front end**: eligibility table, state banners, not-applicable rows,
  cluster confidence labels, band-boundary flag, integer display, an
  observations list, and fine print naming what the crawl cannot see.

### Changed

- **Readability renamed Legibility** across the scanner: dial label, bands,
  copy, summary and mailto subject. The shortcode name, file name and the
  `readability` log column are unchanged.
- **Scanner scoring model** rebuilt: Legibility = Text 40, Extraction 25,
  Structure 15, Dating 20; Citability = Provenance 50, Verifiability 25,
  Answer Shape 25; 18 scored checks, integer weights, cluster-weighted
  aggregation. Check ids are L1 to L4, C1 to C3 and N_ notes.
- **Scanner copy**: Anthropic blocks read "may reduce"; Perplexity, Meta
  and Amazon blocks read "not documented"; 4xx errors read "two automated
  readers from our host".
- **Scanner cache** keyed on the query string and spec version; indeterminate
  results cached for 2 minutes; cache consulted before the rate limit.
- **Cookie and Privacy Policies match the site**: both pages now state that
  Google Analytics 4 runs only after a visitor allows statistics cookies in
  the consent banner, and the Privacy Policy names it among the service
  providers. Both take effect September 26, 2026.
- **License**: the theme header now reads Proprietary, all rights reserved,
  matching the README; the GPL notice is gone.
- **README** rewritten for outside readers.
- Scanner plugin version 3.0.0. Theme version 3.0.0 (`style.css` header and
  `FERNWELL_VERSION`); the fallback theme matches it.

### Removed

- **Scanner: scored checks** for crawler-token points, HTTPS, redirects,
  sitemap, ETag and Last-Modified, text ratio, main-region presence, exactly
  one H1, lists and tables, meta description length, entity property depth,
  sameAs count, @id graph, date age bands, machine-to-machine date
  agreement, definition sentence, lede pronoun rule, heading-to-section
  alignment, statistic density, quotations, credentials, publisher (merged
  into provenance), section sizing, hedge density, promotional density and
  sentence length. Those that remain observable are weight-0 notes; hedge
  density is removed outright.
- **Scanner: the 39-point cap** and the "Access" and "Indexability"
  categories (now states).

## [2.1.0] - 2026-09-11

The site stands up its own machine surfaces (XML sitemap, robots.txt,
llms.txt), measures visitors behind consent, and starts building a scanner
dataset. Theme 2.1.0, FMG AEO 0.1.0 (new), FMG Analytics 1.0.0 (new),
Scanner 1.2.0, Hardening 1.1.0.

### Added

- **Scanner: score log and Tools > Scan Scores (scanner 1.2.0)**: every
  fresh, successful public scan now records an anonymous row, the two
  scores plus each category's earned/max and each check's pass/fail, with
  no URL, host, or IP. Cached repeats, admin test scans, and scans of this
  site are left out. The admin page shows the count and date range, mean
  and median for both axes, ten-point distributions, average by category,
  pass rate by check sorted hardest first, an all-time or 30-day window,
  and a CSV export of the raw rows.
- **FMG Analytics mu-plugin (1.0.0)**: Google Analytics 4 for the site,
  wired through consent rather than around it. The tag is printed in
  WPConsent's blocked form under the statistics category, so nothing is
  fetched or run until the visitor opts in; WPConsent then loads it and
  pushes the Google Consent Mode update. Production only, site admins
  excluded, IP anonymization on.
- **Schema: post image**: the BlogPosting node now carries an ImageObject,
  the same picture og:image uses (the metabox social image if set, else
  the Fernwell Answers card), so the Article rich result has the one
  recommended field it was missing.
- **Schema: organization image**: the ProfessionalService node carries the
  FMG card as its image, the one recommended LocalBusiness field worth
  having; address, telephone, and priceRange stay out on purpose.
- **Sitemap page**: `/sitemap/` lists every page on the site in one place:
  the homepage and its sections, every indexable answer, the legal pages,
  and the machine-readable feeds (XML sitemap, llms.txt, RSS). It is built
  from the site at request time, so new answers appear without upkeep, and
  it is linked from the footer and llms.txt. Slug-matched template, same
  convention as the legal pages.
- **FMG AEO mu-plugin (0.1.0)**: owns the XML sitemap: core's `wp-sitemap.xml`
  is retired (its URLs 301 to the new one), `/sitemap.xml` is served by the
  plugin with an FMG-branded stylesheet at `/sitemap.xsl` so it reads cleanly
  in a browser, and robots.txt declares it. Rewrite rules flush themselves
  once per plugin version, so deploys need no permalink save. It also serves a
  curated robots.txt through the core filter: search, AI, answer-engine, and
  training crawlers welcomed by name, admin and internal search kept out of
  the crawl, parasitic SEO scrapers and the ads crawler turned away, llms.txt
  pointed to in the header. The "Discourage search engines" setting is
  honored. The plugin is generic and exposes filters; the theme hooks in the
  site-specific rules: posts opted out of indexing in the Search & Social
  metabox (noindex, or a canonical pointing elsewhere) are left out, the
  homepage reports the same dateModified its schema does, and the Answers
  listing and sitemap page report the newer of the page itself or the most
  recently modified answer.
- **Scanner: fine-print disclaimer**: results now close with a short note
  that the free scan is informational, reads one page against fixed rules,
  and cannot judge context, and that a full audit can reach different
  findings. The same line closes the copied and emailed summary.
- **Schema: founder credential**: the Person node now carries Kenneth's
  degree as an EducationalOccupationalCredential (BS Human Communication,
  Arizona State University), so engines have a verifiable expertise signal
  to attach to the byline.
- **Homepage freshness dates**: the homepage has no post of its own, so its
  schema datePublished and dateModified, plus article:published_time and
  article:modified_time meta tags, now anchor to real site activity: the
  oldest published post and the most recently modified one. Both values
  are filterable.

### Changed

- **Inset accent bar replaces the edge stroke**: the
  homepage answer-first box now has even corners and a thin, soft accent
  bar inside its padding (2px, accent green at 40%), in place of the green
  border on its left edge and the one-sided radius that went with it. The
  treatment is a reusable `.inset-pipe` class for any panel that wants an
  accent. Padding and the bar's
  inset tighten on small screens.
- **Head links trimmed**: the comments feed link (comments are hard-off)
  and the shortlink plus its Link header (permalinks are already short)
  are gone from every page.
- **Third-party front-end CSS trimmed**: a bundled WordPress AI plugin
  enqueued its summarization block stylesheet on every public
  page. The theme now dequeues it on the front end; the plugin stays active
  for the editor.
- **Emoji plumbing removed**: the site uses no emoji, so WordPress's
  detection module, its settings block, the inline CSS, and the s.w.org
  prefetch no longer ship on any page.
- **Schema: the series is enumerated on the listing only**: the Blog node's
  blogPost list of every published answer now appears on the Answers
  listing and not on single posts, which point up through isPartOf. On a
  post page the other posts were bare @id references that the Rich
  Results Test flagged as unnamed articles, and the list grew with every
  post published.
- **Updated date moved to the post footer**: the "Updated {date}" line now
  renders after the post content (left-aligned, meta styling) instead of in
  the byline, which now carries only author, publish date, and reading
  time. Schema dateModified is unchanged.
- **Engine list names Google AI Overviews instead of Grok**: the AI
  Visibility Audit description, the organization description in schema,
  and llms.txt now list ChatGPT, Claude, Perplexity, Gemini, and Google AI
  Overviews as the engines we test.
- **Scanner: institutional-source check replaced**: the Citability check
  that rewarded government, academic, and standards-body links is gone. It
  penalized any market whose real authorities are vendors, manufacturers,
  or practitioners. Its 8 points now reward citing the specific page that
  makes a claim rather than a source's homepage, which any page in any
  market can earn and which is what an engine needs to verify a citation.

### Fixed

- **Modified dates never precede publish dates**: a scheduled post carries
  a modified stamp from before it went live, so its schema dateModified
  and sitemap lastmod said it changed days before it existed. Both now use
  the later of the two dates, and the homepage's freshness date counts a
  scheduled post from its publish day. The visible "Updated" line keeps
  its own rule and is unchanged.
- **Answers listing declares its canonical**: WordPress emits rel=canonical
  only on singular pages, so the Fernwell Answers listing had none even
  though its og:url and schema already named the URL. It now declares the
  listing URL, and paginated pages declare themselves.
- **llms.txt served without a redirect**: `/llms.txt` was 301ing to
  `/llms.txt/` on every environment, including production, because
  WordPress's canonical redirect treated the request as the front page and
  added a trailing slash before the theme's handler ran. The bare path now
  serves directly, and the slashed form redirects back to it, the same way
  core handles robots.txt.
- **Scanner: reviewer credit follows the schema**: the Citability expertise
  check now credits `reviewedBy` only on WebPage nodes (and their subtypes),
  where schema.org defines it. Previously it rewarded the property on any
  node type, including Article and BlogPosting, where validators reject it.
- **Scanner: admins bypass the hourly scan limit and the result cache**:
  logged-in administrators are exempt from the per-IP throttle and always
  get a fresh scan, so testing changes on your own site no longer burns
  through the public allowance or waits out the 15-minute cache.

### Security

- **XML-RPC goes silent**: the endpoint already had every usable method
  removed, but WordPress re-adds three introspection methods after the
  filter, so a probe still got a 200 and a method list. Any XML-RPC
  request now gets a bare 403 at init, before the server class loads, and
  the head no longer advertises the endpoint through the EditURI/RSD link.
  Hardening mu-plugin 1.1.0.
- **HSTS enabled**: TLS responses now carry a one-year Strict-Transport-Security
  header, so browsers refuse to load the site over plain HTTP once they have
  seen it. Subdomains are not included yet.
- **PHP version fingerprint removed**: the X-Powered-By header no longer
  reports the PHP version.

## [2.0.0] - 2026-08-23

### Added

- **Fernwell Answers**: the site's blog, live at `/answers/`. The listing page
  carries the "Fernwell Answers:" wordmark; each post is a question as its H1
  with the direct answer as the opening paragraph. Single posts get a kicker
  linking back to the listing, a larger lede paragraph, and a closing
  scan/audit call-to-action card. New nav and footer links to Answers.
- **Answers wiring**: posts live under the `/answers/` permalink base with the
  Answers page as the posts page; the listing page titles as "Fernwell
  Answers" and single posts carry "Fernwell Answers" as the title-tag suffix.
- **Schema: Blog node**: the Answers surfaces add a Blog node named Fernwell
  Answers to the graph; Article nodes anchor to it via isPartOf, and post
  breadcrumbs gain a Fernwell Answers level (Home > Fernwell Answers > post).
- **Reading time**: posts show an approximate reading time (200 words per
  minute) in the single-post byline and on the Answers listing.
- **Search & Social metabox**: posts get a native metabox (no SEO plugin, no
  ACF) for per-post overrides of what the theme already emits: SEO title,
  meta description, canonical URL, social image (media-library picker), and
  a noindex toggle. Character counters on title (60) and description (155).
  Overrides flow into the title tag, meta description, OG/Twitter tags,
  rel=canonical, robots, and the Article schema abstract. New files:
  `inc/post-meta.php`, `assets/js/admin-meta.js`.
- **Schema: richer Article nodes**: posts now carry wordCount, timeRequired
  (matching the visible reading time), and description/abstract (from the
  meta description override or the excerpt).
- **Editor typography**: the block editor mirrors the front-end entry
  typography (fonts, sizes, colors, list indents, content width) via
  `assets/css/editor.css`, so posts are written in the type they publish in.
- **Post navigation**: every post ends with a Previous/All answers/Next
  button row. The "All answers" button remembers the reader's place: opening
  a post from the listing stores which page and item they left from
  (sessionStorage), and the button returns them to that exact spot, with the
  item's title highlighted. Direct visitors get the plain listing link.
  New file: `assets/js/answers-nav.js`.
- **Front page: Fernwell Answers strip**: the three latest answers render as
  cards between About and Contact, with the wordmark heading and an "All
  answers" link. Contact flips from alt to plain so section alternation
  holds.
- **llms.txt: dynamic answers listing**: the served file now includes a
  "## Fernwell Answers" section naming every published answer with its URL
  and one-line summary, generated at request time so it never goes stale.
- **Schema: Blog/Article linking + speakable**: the Blog node enumerates
  every published Article via blogPost, and each Article carries a
  SpeakableSpecification pointing at the question H1 and the answer lede.
- **Share card: Fernwell Answers default**: posts and the Answers listing
  fall back to a section-branded og:image (`assets/og-card-answers.png`).
  Per-post social images still override it.
- **Metabox: live share preview**: the Search & Social box shows a
  Yoast-style card preview (image, site, title with the Fernwell Answers
  suffix, description) that updates as you type and follows the social
  image field.
- **404 as re-entry point**: destination buttons
  (homepage, free scan, Fernwell Answers) plus a "Latest answers" card
  strip, so a dead URL always offers a way back in.
- **Comments and pingbacks off in code**: comments_open and pings_open are
  hard-false at the theme level, so production can't drift open regardless
  of discussion settings.
- **First post**: "Why does AI recommend my competitors instead of me?"

### Changed

- **No WordPress archives**: date, category, and tag archives 301 to the
  Fernwell Answers listing (author archives already redirect home), and the
  taxonomy sitemaps are gone; the core sitemap now lists only posts and
  pages. Archive pages are thin, uncited, and unused here.
- **Hardening: users sitemap removed** (`fmg-hardening.php`): the core
  wp-sitemap users provider leaked the author slug even with the REST users
  endpoint gated and author archives redirected. Enumeration is now closed
  on all three doors.
- **Hardening: author slug scrubbed everywhere** (`fmg-hardening.php`):
  `?author=N` no longer canonical-redirects through `/author/{slug}/` (the
  Location header leaked the slug before the redirect-home ran), and oEmbed
  responses drop their author_name and slug-based author_url fields.
- **No tags on posts**: the post_tag taxonomy is unregistered from posts.
  Categories stay for organizing the answer set.
- **Feeds credit the public byline**: dc:creator in feeds now says Kenneth
  Raymond, matching the visible byline, instead of the account display name.
- **Footer: LinkedIn link removed.** The schema Person node keeps its sameAs
  reference (machine-side entity corroboration, not a visible link).
- **Social image alt text**: og:image:alt and twitter:image:alt now emit on
  every page, describing whichever card serves (FMG default, Fernwell
  Answers, or a per-post image, which uses the attachment's media-library
  alt text and falls back to the post title).
- **Blog accessibility pass**: prev/next buttons announce their destination
  title to screen readers, arrow glyphs and separator dots are hidden from
  assistive tech, and the returning-reader highlight on the listing adds an
  underline so it never relies on color alone. All text/background token
  pairs verified at 5.6:1 contrast or better (WCAG AA floor is 4.5:1).
- **Blog mobile pass**: below 480px the post navigation restacks (Previous
  and Next side by side, All answers full-width beneath); long unbroken
  strings can no longer overflow post content; listing pagination is styled
  for when the answer set outgrows one page. Verified at 375px: no
  horizontal overflow, all tap targets 48px or taller.
- Schema: the organization now lists its GitHub repository in sameAs, and the
  founder's URL points to the homepage About section instead of a page that
  never existed.
- llms.txt: the Answers entry now reads "Fernwell Answers" with the section's
  new description.

### Fixed

- **Schema: post nodes are BlogPosting** (was Article). The blogPost
  property's expected type is BlogPosting; validator.schema.org flagged the
  mismatch. BlogPosting is an Article subtype, so nothing is lost.
- **Schema: speakable targets a real class.** The lede paragraph of every
  post now carries an `answer-lede` class (merged into the existing class
  attribute at render), and the SpeakableSpecification points at it instead
  of a `:first-of-type` pseudo-selector that some consumers can't evaluate.

## [1.1.0] - 2026-08-22

### Added

- **Theme: mobile navigation**: below 880px the header links collapse into an
  accessible hamburger menu (real button with aria-expanded, dropdown panel,
  closes on link tap, Escape, or outside tap). Previously the five nav links
  had no mobile handling at all and overflowed the header on phones.
  New file: `assets/js/nav.js`.
- **Theme: mobile spacing pass**: tighter hero/section/stats padding and a
  narrower contact-box inset below 640px; `scroll-padding-top` so anchor links
  no longer land content underneath the sticky header; `overflow-x: clip`
  guard against sideways scroll on small screens.
- **Security: FMG Security Hardening mu-plugin** (`fmg-hardening.php`):
  security headers (nosniff, X-Frame-Options, Referrer-Policy,
  Permissions-Policy), XML-RPC and X-Pingback disabled, REST users endpoint
  gated to logged-in users, author archives redirected home (username
  enumeration), WordPress version fingerprint removed, vague login errors.
- **Scanner: Citability score**: a second, independently scored 0-100 axis measuring
  whether a page is shaped to be *cited* (distinct from whether it can be *read*).
  Four deterministic, single-page checks: a name a citation can point to
  (author/Person/byline), expertise signals (credentials, reviewer, or
  medical-review markup), self-contained quotable-claim density, and claims
  backed by authoritative outbound sources (.gov/.edu/journal/DOI).
- **Scanner: two-dial results**: Readability and Citability render as separate dials,
  each with its own band (Citability bands: Citable/Thin/Anonymous). Both scores
  now appear in the email and copy-to-clipboard summaries and the mail subject.
- **Theme: public `/llms.txt`**: the exact `/llms.txt` path bypasses the Password
  Protected gate so AI crawlers can always read it while the rest of the site stays
  private during the build. No effect when the plugin is inactive.
- FMG AI Readability Scanner mu-plugin (`fmg-readability-scan.php`): a deterministic
  single-URL scan that scores 0-100 whether AI engines can read a site.
- `[fmg_readability_scan]` shortcode rendering a self-contained scan form; it
  self-enqueues its own inline CSS/JS, so it works in code-built templates with
  no editor block required.
- REST endpoint `POST /wp-json/fmg/v1/scan` backing the scan.
- Category scoring across Entity, Extractability, Access, Structure, and
  Freshness, each with per-check pass/fail and remediation guidance.
- Abuse and performance guards: per-IP rate limiting (10/hour), identical-URL
  result caching (900s TTL), a 2 MB fetch ceiling, and a dedicated
  `FernwellReadabilityBot/1.0` user agent.
- "Talk to Fernwell" result CTA wired to the `FMG_SCAN_CONTACT` address.
- Homepage integration: scanner section added to the `fernwell` theme's
  `front-page.php`, placed after the stats band.

### Changed

- **Homepage copy overhaul**: the front page now speaks one voice, anchored on
  "you" and built around three named axes: Readability, Citability, and
  Namability. The hero, AEO explainer, engagement phases (now Measure, Fix,
  Monitor), services, FAQ, About section (now brand-first), and contact box are
  all rewritten. Every audit and service button opens a pre-addressed email
  whose subject echoes the button clicked. The FAQPage markup, Service nodes,
  site meta description, and llms.txt mirror the visible copy word for word.
  The named engine list is now ChatGPT, Claude, Perplexity, Gemini, and Grok,
  and Citation Monitoring is renamed AI Visibility Monitoring.
- **Scanner copy aligned to the same voice**: result bands, category blurbs,
  check guidance, error messages, and the form caption all follow the site's
  language rules, and the scan form gets breathing room below the section
  intro.
- **Accessibility pass**: post dates now use semantic time markup (which also
  satisfies the scanner's visible-date check), smooth scrolling respects
  reduced-motion settings, the site nav is labeled for screen readers, and the
  contact heading joins the page outline.
- Scanner plugin header version set to 1.1.0 (dropping the -alpha tag).
- **Scanner: authoritative sources recognize biomedical databases**: the
  Citability check that counts outbound links to authoritative sources now
  recognizes the canonical genetics and medical databases (OMIM, Ensembl,
  ClinGen, UniProt, HGNC, gnomAD, Cochrane Library) alongside the existing
  .gov/.edu/journal/DOI hosts. Health and genetics pages that cite these
  databases were previously scored as having no authoritative sources at all;
  they now get proper credit for well-sourced claims.
- **Scanner: fetch hardening**: responses are now capped at the transport layer
  (`limit_response_size`), closing a memory exhaustion hole where a huge
  response was fully downloaded before being trimmed to the 2 MB ceiling; all
  scanner fetches also set `reject_unsafe_urls`, which revalidates followed
  redirects so a robots.txt or llms.txt redirect cannot bounce the fetch to an
  internal host.
- Theme version bumped to 1.1.0 (style.css header and `FERNWELL_VERSION`) so
  browsers cache-bust the updated CSS and pick up nav.js.
- **Domain and email migration**: every reference to the old fernwellmedia.com
  domain and old contact addresses now points to fernwellgroup.com and
  hello@fernwellgroup.com. Touches the theme templates (footer, front page,
  404, legal pages), `llms.txt`, `style.css` headers, the JSON-LD contact
  points, the scanner's `FMG_SCAN_CONTACT` constant, and the scanner bot's
  user-agent URL.
- Scanner result-cache key namespaced to `fmg_scan_v2_` so results cached under
  the earlier single-score shape are structurally bypassed instead of crashing
  the two-dial renderer.
- Scanner quotable-claim length check now counts digit-bearing tokens as words,
  so numeric and dated claims are not undercounted against the length window.
- Scanner Structure "content-level schema" check now credits medical content
  types (MedicalWebPage, MedicalCondition, MedicalScholarlyArticle,
  MedicalGuideline, Drug) and Dataset. Previously only generic types (FAQPage,
  Article, ...) counted, so health and research sites using the correct schema
  were wrongly marked "None found", inconsistent with the citability checks,
  which already credited MedicalWebPage.
