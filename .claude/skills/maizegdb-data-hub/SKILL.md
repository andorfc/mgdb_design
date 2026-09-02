---
name: maizegdb-data-hub
description: Bring a MaizeGDB page onto the shared Data Hub shell — the pale blue ground with white section cards, coloured section edges, a search-first section order, the shared reference card, and the shared chart layout. Use when modernizing or reviewing any Data Hub landing page (/data_center/*, /ai, /genome, /expression, /pan_gene_center, and the rest), when a hub's sections, references, metrics or figures need to match the others, or when asked to apply "the same changes" from one hub to another.
---

# MaizeGDB Data Hub shell

The canonical statement of this is **<https://claude.maizegdb.org/pattern_library/#pattern-hub>**
and the **Data Hub shell** section of `README.md` in the repo. Read the pattern
library first; where it disagrees with this file, it is right and this file is
out of date.

Repo: `~/Documents/ClaudeCode/maizegdb-redesign`. Deploy one file at a time with
`deploy/deploy.sh <path>` — a full run takes ~13 minutes.

## Opt in, do not restate

```php
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-hub.css?v=' . $v_hub);   // before the page sheet
$bauplan->includeCss('/css/mgdb-<page>.css?v=' . $v_css);
```

```html
<main class="mgdb-page mgdb-hub-page mgdb-<page>-page" id="...">
```

The shell then supplies the ground and surfaces, the coloured top edge per
section, the absence of a rule under a section title, the metric card colours,
the grid-tile hover, table zebra, the green Related resources wash, the
`mgdb-hub-field` form row, and the scroll offset. **The page stylesheet must not
repeat any of it** — it describes only that page's own furniture.

## Section order

Every hub is the same shape. Tab labels are their section heading, shortened but
never reworded.

1. **Search** — first section after the header. Example queries as buttons, and
   an advanced-criteria `<details>` directly under the main field.
2. **Results** — carries `hidden` until a search runs. Top 10 / 25 / 50 / all,
   pagination, sortable columns, a filter-within-results box. Not a nav tab.
3. …the hub's own sections…
4. **References** — `.mgdb-ref-list` filled by `mgdb_render_references()`.
5. **Metrics** — four cards, then any figures.
6. **Related resources** — exactly five, each marked Internal or External.

## References: name DOIs, never citations

```php
include_once('./include/references_lib.php');

$content->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    array('doi' => '10.1093/g3journal/jkae281'),
    array('doi' => '10.1101/2022.11.10.516002',      // not in the bibliography
          'fallback' => array('title' => '…', 'authors' => '…',
                              'journal' => 'bioRxiv', 'year' => 2022)),
)));
```

Content comes from `data/cite_journal_articles.json`, the curated bibliography
behind `/cite` — 60 records with verified authors, volumes, PubMed IDs and
abstracts. Pick DOIs from it with:

```bash
python3 -c "
import json
for a in json.load(open('src/data/cite_journal_articles.json')):
    print(a['year'], a['doi'], a['title'][:70])"
```

Copy citation / Copy DOI is bound by `mgdb-modern.js`; no page script needed.

## Metrics and caching

Four cards. Collection-wide counts go through `dashboardCache($system, $key, fn)`.
**Key the entry on the mtime of every input** — the data file *and* the
controller, because the renderers live in the controller:

```php
$cache_key = '<page>/page_' . $data_stamp . '_' . (int) @filemtime(__FILE__);
```

A key that watches only the data serves stale HTML after a markup edit with
nothing to show for it. Cost real debugging time twice.

## Figures

Use `MGDB.chart({target, traces, layout})`. **Do not set `legend.y`** — the
shared layout places the legend above the plot and reserves a band, and
`fitLegend()` grows it if the legend wraps. Pass `legendManual: true` only if a
figure genuinely needs its own position, and then own the margins too.

`.mgdb-chart` is a fixed 320px, so a tall chart needs the element height and the
Plotly height set from one variable:

```js
function sizeChart(id, h) { var el = document.getElementById(id); if (el) el.style.height = h + 'px'; return h; }
```
plus `.mgdb-<page>-page .mgdb-chart { height: auto; min-height: 320px; overflow: visible; }`

## Traps that have each cost a debugging cycle

- **A hub sheet's flat `border-color` is a shorthand and eats a card's top
  edge.** It loads after the page sheet, so a page-level `border-top-color` at
  equal specificity loses. Let the shell own the edge, or add one class.
- **`legend.y` is a fraction of the *plot* height**, so a fixed value drifts as
  a figure grows. Never place a legend below the plot and then grow a margin to
  fix it — that is unstable and reached `margin.t: 1809` on a 472px figure.
- **`.cartesianlayer` is not the plot rectangle.** Its bounding box spans the
  whole SVG. `rect.nsewdrag` is the real one.
- **`automargin` leaves no gap** — the margin ends up exactly as wide as the
  tick text, so a category label sits 1px from the first bar. The shell's base
  layout already reserves a standoff; do not re-add a `ticksuffix` hack.
- **Outside bar labels need `\u00A0`, not a space.** SVG collapses leading
  whitespace.
- **Section top-edge colours start at `nth-of-type(2)`** because the hero is the
  first `<section>`. Check the hero really is first before trusting the order.
- **`--mgdb-dur-fast` is defined nowhere.** Using it invalidates the whole
  `transition` declaration.
- **Bauplan escaping**: every literal `)` must be `&#41;` or `\)`, `;;` starts a
  comment, and `*( @( &( $( #(` are tokens. Check a new `.bau` with
  `grep -n ')' file.bau` — only the closing paren of the template should match.

## Verifying

The dev site is behind Cloudflare, so `curl` from the workstation gets a 403 and
the in-app browser is sometimes challenged. From the server:

```bash
ssh development-server "curl -s --resolve claude.maizegdb.org:80:10.24.27.235 http://claude.maizegdb.org/<route> -o /tmp/p.html -w 'HTTP %{http_code} %{size_download}\n'"
```

HTTP 200 never means the page rendered — grep the body for `Fatal error` and for
the markers you expect. In the browser pane, `IntersectionObserver` never fires
and programmatic `scrollTo` dispatches no `scroll` event, so drive a scrollspy
with `window.dispatchEvent(new Event('scroll'))`. Screenshots only paint at
scroll offset 0; to look at a lower section, hide its siblings instead of
scrolling.

Measure, do not eyeball:

```js
// distinct section colours, no rule under a title, no overflow
[...document.querySelectorAll('.mgdb-hub-page > section')].map(s =>
  s.id + ' ' + getComputedStyle(s).borderTopColor)
getComputedStyle(document.querySelector('.mgdb-section-heading')).borderBottomWidth
Math.round(window.visualViewport.width)   // not innerWidth: overflow inflates it
```

## Working alongside other agents

Claude, Codex and Gemini share one working copy and one `main`. `mgdb-hub.css`,
`mgdb-modern.css`, `deploy/manifest.txt` and `README.md` are shared — edit them
additively. Do not sweep another agent's half-finished page into a commit; leave
it uncommitted and say so.
