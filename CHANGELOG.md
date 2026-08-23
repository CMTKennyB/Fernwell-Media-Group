# Changelog

All notable changes to the Fernwell Media site code (the `fernwell` theme and mu-plugins) are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

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
- **Post navigation**: every post ends with a Previous / All answers / Next
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
  fall back to a section-branded og:image (`assets/og-card-answers.png`,
  generated in the FMG card's design language; replace the file to
  redesign). Per-post social images still override it.
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
  each with its own band (Citability bands: Citable / Thin / Anonymous). Both scores
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
- Scanner plugin header version set to 1.1.0 (dropping the -alpha tag) to match
  what production actually runs.
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
