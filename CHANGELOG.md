# Changelog

All notable changes to the Fernwell Media site code (the `fernwell` theme and mu-plugins) are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

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
