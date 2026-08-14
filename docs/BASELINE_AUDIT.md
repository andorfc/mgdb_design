# MaizeGDB Redesign — Baseline Audit

Date: 2026-08-14
Instance audited: `dev8.usda.iastate.edu` → `https://claude.maizegdb.org/`
Reference page: `/maize_meeting/`

## Application architecture

| Layer | Location | Notes |
| --- | --- | --- |
| Page controller | `/var/www/claude/html/<section>/index.php` | Plain PHP 8.2.28; builds a `Bauplan` object, registers CSS/JS, loads templates, calls `publish()` |
| Template engine | `/var/www/claude/html/lib/Bauplan.php` + `lib/libbau/` | In-house engine, `.bau` files, `$$SYNTAX-LEVEL 2` |
| Global shell | `templates/maizegdb-main.bau` | Header, logo, search box, megamenu, `*(body)` slot, footer |
| Page body | `templates/static/<page>.bau` | Loaded over HTTP via `loadRemote($root_url_private . '/templates/static/<page>.bau')` |
| Page CSS | `/css/<page>.css` | One file per page, 72 files total |
| Page JS | `/js/<page>.js` | |
| Charting | `/js/lib/plotly/plotly-2.25.2.min.js` | Served locally, already pinned |
| Config | `/var/www/claude/conf/mgdb.conf`, `conf/db.conf` | Contains DB credentials — never copy into the repo |

Scale: 308 controller PHP files, 743 `.bau` templates, 72 CSS files.

Cache busting is already handled well: `filemtime()` is appended as `?v=`.

## Reference page: `/maize_meeting/`

Already modernized (`maize_meeting_modern.css` / `.js` / `.bau`). It establishes the
visual family the spec asks new pages to join:

- Compact split hero (logo panel left, title/tagline/actions right)
- Metric grid dashboard (4 cards, colored top border + icon chip)
- Section headings with eyebrow + `h2` + right-aligned descriptive paragraph
- Restrained cards, 10–13px border radii, subtle shadows
- Two-column Plotly chart grid with text fallback and a data note
- Client-side search + period filter chips with live count and empty state

Palette in use:

| Token | Value |
| --- | --- |
| `--meeting-gold` | `#f2a515` |
| `--meeting-gold-dark` | `#9a6411` |
| `--meeting-orange` | `#e95e22` |
| `--meeting-red` | `#c82c22` |
| `--meeting-brown` | `#501719` |
| `--meeting-green` | `#285d46` |
| `--meeting-green-dark` | `#174634` |
| `--meeting-ink` | `#2d332f` |
| `--meeting-muted` | `#68716b` |
| `--meeting-line` | `#e2ddd3` |

## Blocking findings

These are properties of the **global shell**, not of any one page. Until they are
addressed, several spec requirements are unachievable on any page.

### 1. No DOCTYPE — every page renders in quirks mode

`lib/Bauplan.php:76` emits `<html>` with no doctype. Verified in-browser:
`document.compatMode === "BackCompat"`.

Consequence: legacy box model and inconsistent CSS behavior sitewide.

### 2. No viewport meta tag — mobile layouts never activate

No `<meta name="viewport">` is emitted. Verified in-browser at a 375×812 phone
viewport: the page renders the full 1280px desktop layout scaled down to
illegibility. The `@media (max-width: 760px)` and `(max-width: 520px)` rules in
`maize_meeting_modern.css` are effectively dead code on real phones.

Consequence: **the spec's responsive requirements cannot be met on any page**
without a shell change.

### 3. Fixed-width shell caps page width

`css/index.css`: `#wrapper { width: 1280px }`, `#content { width: 1080px }`.
Fixed pixel widths, not `max-width`.

Consequence: guaranteed horizontal scrolling below 1280px; no page body inside
`#content` can be fluid.

### 4. Global `h1` rule leaks into modernized pages

`css/index.css:15`:

```css
h1 { background-color: #386f0d; color: white; font-size: 12px; font-weight: bold; }
```

`maize_meeting_modern.css` overrides `color` and `font-size` but not
`background-color`, so a green block renders behind the hero `h1`. Visible on the
live dev page today.

### 5. Global body text is 11px

`getComputedStyle(document.body).fontSize === "11px"`. The modernized page's own
type scale runs 8–11px for body copy, labels, and card text.

The spec requires "avoid body text smaller than approximately 16px unless space is
genuinely constrained." The current exemplar does not meet this, and neither does
the site baseline. This is also the single largest Section 508 / WCAG gap.

### 6. Unpinned third-party scripts on every page

`templates/maizegdb-main.bau` loads jQuery 1.8.0 (2012) and jQuery UI 1.9.0 from
`cdnjs`, NGL 0.10.4 from `unpkg`, plus two Atlassian issue-collector scripts.
jQuery 1.8.0 has known XSS advisories. Versions are pinned in the URL, but the
libraries themselves are long out of support.

Not blocking for the redesign, but worth recording.

## Deployment constraints

- `git` is **not installed** on the dev server. The repo is therefore local
  (`/Users/carsonandorf/Documents/ClaudeCode/maizegdb-redesign`) and files are
  pushed with `deploy/deploy.sh` over `ssh`/`scp`.
- `rsync` is **not installed** either; `scp` and `tar` are available.
- The webroot is group-writable by `mgdbadmin`; the account is a member, so
  direct writes work.
- Every deploy snapshots the previous server copy into `backups/<timestamp>/`.

## Performance baseline

`GET https://claude.maizegdb.org/maize_meeting/` — HTTP 200, 49,712 bytes,
0.517 s total (single sample, cold). The page has no database queries; all chart
and archive data is hard-coded in `maize_meeting_modern.js`.
