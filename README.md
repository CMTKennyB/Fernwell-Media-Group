# Fernwell Media Group

WordPress site code for **Fernwell Media Group** (FMG), an independent Answer Engine Optimization (AEO) consultancy founded by Kenneth Raymond.

© 2026 Kenneth Raymond. All rights reserved. Do not copy, modify, or redistribute without permission.

## Repo convention

This repo is rooted at `wp-content/` per house convention. WordPress core, `wp-config.php`, uploads, and caches are not tracked. Custom themes and plugins are.

Branches: all work happens on `dev`; `main` receives merges when there is enough to merge.

Release history lives in [CHANGELOG.md](CHANGELOG.md).

## Theme: `themes/fernwell`

Custom theme, no page builder, no SEO plugin; the theme owns its own markup, meta, and structured data, with per-post overrides via a native Search & Social metabox.

| File | Role |
| --- | --- |
| `style.css` | Grove design system: palette tokens, typography, components, blog styles |
| `functions.php` | Theme supports, font/style enqueue, favicon, meta description + Open Graph/Twitter output (with og:image:alt), JSON-LD schema graph, llms.txt rewrite + dynamic answers listing, pipe title separator, reading time, archive 301s, comments/pings hard-off, no post tags |
| `inc/post-meta.php` | Search & Social metabox on posts: SEO title, meta description, canonical, social image, noindex, live share preview; feeds the head output |
| `header.php` / `footer.php` | Site chrome, FMG lockup, skip link, `<main>` landmark, self-updating copyright year |
| `front-page.php` | The homepage: hero, stats, scanner, AEO explainer, process, services, FAQ, about, Fernwell Answers strip, contact |
| `index.php` | Fernwell Answers listing ("Fernwell Answers:" wordmark, question links, reading times, ellipsis excerpts) |
| `single.php` / `page.php` | Post and page layouts; posts get the Answers kicker, byline with reading time, closing scan/audit CTA, and Previous / All answers / Next navigation |
| `page-privacy-policy.php`, `page-terms-of-service.php`, `page-cookie-policy.php` | Slug-matched legal page templates; content is version-controlled here, the WP pages exist only to provide URLs |
| `404.php` | Not-found page as re-entry point: destination buttons + latest answers |
| `assets/js/nav.js` | Mobile nav: hamburger disclosure toggle below 880px |
| `assets/js/answers-nav.js` | "All answers" place memory: returns the reader to the listing page and item they left from (sessionStorage) |
| `assets/js/admin-meta.js` | Metabox behavior: character counters, media picker, live share preview |
| `assets/css/editor.css` | Block editor mirrors the front-end entry typography; keep in sync with style.css |
| `llms.txt` | Served at `/llms.txt` via rewrite rule (flush permalinks after changes); a Fernwell Answers section listing every published post is appended at request time |
| `assets/og-card.png` / `assets/og-card-answers.png` | 1200x630 social share cards: FMG default and the Fernwell Answers default for posts/listing; per-post images override both |

### Design system (Grove)

Stone paper `#f8f8f5`, deep green ink `#17211d`, green accent `#156a58` for interactive text, apricot `#f4a860` fills with dark text for buttons/tiles/numerals, dark sections `#14201e`. Space Grotesk display over Inter body. All color pairings verified to WCAG AA; light accents are used only as large fills with dark text on top so every pairing clears 4.5:1.

Brand rule: the M and G never appear detached from the F. At small sizes the mark degrades to a lone F tile.

### Schema architecture

One connected JSON-LD graph per page, nodes cross-referenced by `@id`: ProfessionalService (`#org`) and Person (`#kenneth`) sitewide, WebSite + WebPage on every page, four Service nodes and FAQPage on the front page, BreadcrumbList on singular pages (posts get a Fernwell Answers level), a Blog node (`#blog`, named Fernwell Answers) on the Answers surfaces enumerating every published Article via `blogPost`, and BlogPosting on posts (the blogPost property's expected type) with wordCount, timeRequired, description/abstract, and a SpeakableSpecification targeting the question H1 and the lede's `.answer-lede` class. The FAQPage carries five Q&As mirroring the visible copy in `front-page.php` verbatim: the answer-first AEO definition plus the four FAQ items. Service node names and descriptions mirror the service cards the same way. Validates clean (0 errors, 0 warnings) at validator.schema.org, last confirmed 2026-08-23 against the full 2.0 graph including the blog nodes; revalidate after structural changes. While the site is password-gated, validate by pasting the full page source (the validator's URL fetch can't pass the gate).

Copy vocabulary: the site's three axes are Readability (can an engine read you), Citability (can it cite you), and Namability (does it name you in answers; measured by the audit, not the crawl). Use "engine" for answering and naming, "crawler" for fetching and reading.

## MU-plugins: `mu-plugins/`

| File | Role |
| --- | --- |
| `fmg-readability-scan.php` | FMG AI Readability & Citability Scanner: shortcode `[fmg_readability_scan]`, REST endpoint `POST /wp-json/fmg/v1/scan`, SSRF-guarded fetcher, per-IP rate limiting |
| `fmg-hardening.php` | Baseline security: headers, XML-RPC off, username enumeration closed on every surface (REST users, author archives, users sitemap, canonical redirect, oEmbed), version fingerprint removed |

## Local development

After theme activation or llms.txt/rewrite changes: Settings > Permalinks > Save Changes to flush rewrite rules.
