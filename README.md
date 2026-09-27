# Fernwell Media Group

WordPress code for **Fernwell Media Group**, an independent Answer Engine Optimization (AEO) consultancy founded by Kenneth Raymond.

© 2026 Kenneth Raymond. All rights reserved. Do not copy, modify, or redistribute without permission.

## Repository layout

This repository is the site's `wp-content/` directory. It tracks the custom theme and must-use plugins. WordPress core, `wp-config.php`, uploads, and caches stay out of version control.

Release history: [CHANGELOG.md](CHANGELOG.md).

## Theme: `themes/fernwell`

A custom theme with no page builder and no SEO plugin. The theme writes its own markup, metadata, and structured data, and each post can override its search and social settings.

| File | Role |
| --- | --- |
| `style.css` | Design system: color tokens, typography, components, post styles |
| `functions.php` | Theme setup, head metadata (description, Open Graph, Twitter), the JSON-LD graph, llms.txt, sitemap rules, reading time, and removal of unused core output (comments, tags, emoji scripts) |
| `inc/post-meta.php` | Search & Social panel on posts: SEO title, meta description, canonical URL, social image, noindex, live share preview |
| `header.php`/`footer.php` | Site header and footer, skip link, main landmark, copyright year |
| `front-page.php` | Homepage |
| `index.php` | Fernwell Answers listing |
| `single.php`/`page.php` | Post and page layouts; posts carry a byline with reading time, a closing call to action, and previous/next navigation |
| `page-privacy-policy.php`, `page-terms-of-service.php`, `page-cookie-policy.php` | Legal pages, with their content kept in version control |
| `page-sitemap.php` | HTML sitemap at `/sitemap/` |
| `404.php` | Not-found page with links back into the site and the latest answers |
| `assets/js/nav.js` | Mobile navigation toggle |
| `assets/js/answers-nav.js` | Returns readers to their place on the Answers listing |
| `assets/js/admin-meta.js` | Search & Social panel behavior: character counters, image picker, share preview |
| `assets/css/editor.css` | Editor styles matching the front end |
| `llms.txt` | Served at `/llms.txt`, with the list of published answers added at request time |
| `assets/og-card.png`, `assets/og-card-answers.png` | Default social share images |

### Design

Stone paper background, deep green ink, and apricot accents, with Space Grotesk headings over Inter body text. Every color pairing meets WCAG AA contrast.

### Structured data

Each page outputs one connected JSON-LD graph: the organization and its founder on every page, WebSite and WebPage everywhere, Service and FAQPage on the homepage, BreadcrumbList on posts and pages, and Blog and BlogPosting on Fernwell Answers. FAQ and service entries match the visible page copy word for word.

## Must-use plugins: `mu-plugins/`

| File | Role |
| --- | --- |
| `fmg-aeo.php` | Serves the XML sitemap at `/sitemap.xml` in place of core's `wp-sitemap.xml`, and the robots.txt rules, which welcome AI and answer-engine crawlers by name |
| `fmg-analytics.php` | Google Analytics 4, blocked until the visitor consents through WPConsent; runs on production only and never for administrators |
| `fmg-readability-scan.php` | The FMG AI Legibility & Citability Scanner: shortcode `[fmg_readability_scan]` and REST endpoint `POST /wp-json/fmg/v1/scan`. Reports whether each major answer engine may use a page and scores the page on Legibility and Citability. Keeps an anonymous score log (no URLs, hosts, or IP addresses) with a report under Tools > Scan Scores |
| `fmg-hardening.php` | Security headers, XML-RPC disabled, username enumeration blocked, version numbers removed from output |
