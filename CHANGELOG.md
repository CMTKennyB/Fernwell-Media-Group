# Changelog

All notable changes to the Fernwell Media site code (the `fernwell` theme and mu-plugins) are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

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
