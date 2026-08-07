# Changelog — FMG AI Readability Scanner

All notable changes to the `fmg-readability-scan` mu-plugin are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- FMG AI Readability Scanner mu-plugin: a deterministic single-URL scan that
  scores 0–100 whether AI engines can read a site.
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
