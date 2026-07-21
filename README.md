# Fernwell Media

WordPress site code for **Fernwell Media Group** (FMG), an independent Answer Engine Optimization (AEO) consultancy founded by Kenneth Raymond.

© 2026 Kenneth Raymond. All rights reserved. Do not copy, modify, or redistribute without permission.

## Repo convention

This repo is rooted at `wp-content/` per house convention. WordPress core, `wp-config.php`, uploads, and caches are not tracked. Custom themes and plugins are.

Branches: all work happens on `dev`; `main` receives merges when there is enough to merge.

## Theme: `themes/fernwell`

Custom theme, no page builder, no SEO plugin; the theme owns its own markup, meta, and structured data.

| File | Role |
| --- | --- |
| `style.css` | Grove design system: palette tokens, typography, components, blog styles |
| `functions.php` | Theme supports, font/style enqueue, favicon, meta description + Open Graph/Twitter output, JSON-LD schema graph, llms.txt rewrite |
| `header.php` / `footer.php` | Site chrome, FMG lockup, skip link, `<main>` landmark, self-updating copyright year |
| `front-page.php` | The homepage: hero, stats, AEO explainer, process, services, FAQ, about, contact |
| `index.php` | Answers (blog) listing |
| `single.php` / `page.php` | Post and page layouts |
| `page-privacy-policy.php`, `page-terms-of-service.php`, `page-cookie-policy.php` | Slug-matched legal page templates; content is version-controlled here, the WP pages exist only to provide URLs |
| `404.php` | Not-found page |
| `llms.txt` | Served at `/llms.txt` via rewrite rule (flush permalinks after changes) |
| `assets/og-card.png` | 1200x630 social share card |

### Design system (Grove)

Stone paper `#f8f8f5`, deep green ink `#17211d`, green accent `#156a58` for interactive text, apricot `#f4a860` fills with dark text for buttons/tiles/numerals, dark sections `#14201e`. Space Grotesk display over Inter body. All color pairings verified to WCAG AA; light accents are used only as large fills with dark text on top so every pairing clears 4.5:1.

Brand rule: the M and G never appear detached from the F. At small sizes the mark degrades to a lone F tile.

### Schema architecture

One connected JSON-LD graph per page, nodes cross-referenced by `@id`: ProfessionalService (`#org`) and Person (`#kenneth`) sitewide, WebSite + WebPage on every page, four Service nodes and FAQPage on the front page, BreadcrumbList on singular pages, Article on posts. FAQ text in `functions.php` must mirror the visible FAQ in `front-page.php`. Validates clean (0 errors, 0 warnings) at validator.schema.org.

## Local development

After theme activation or llms.txt/rewrite changes: Settings > Permalinks > Save Changes to flush rewrite rules.
