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

**Size the margins from the figure's width, not as constants.** `MGDB.chart`
re-runs `Plotly.Plots.resize` on a resize, which rescales the figure but keeps
the margins it was drawn with, so a generous desktop gutter survives onto a
phone and squeezes the plot to nothing:

```js
function metrics() {
  var w = el.getBoundingClientRect().width, narrow = w > 0 && w < 560;
  return { narrow: narrow,
           margin: narrow ? { l: 104, r: 16, t: 8, b: 44 } : { l: 150, r: 96, t: 8, b: 44 },
           tickformat: narrow ? '~s' : ',d',
           nticks: narrow ? 3 : 0 };
}
```
Relayout when the breakpoint is crossed (`Plotly.relayout` with the new margin,
`xaxis.tickformat` and `xaxis.nticks`), and drop `textposition: 'outside'` on
narrow — the values table under the figure already carries the numbers.

Also load Plotly: `$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');`
**before** the page script. Without it `MGDB.chart` writes its fallback text and
nothing else goes wrong visibly.

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
- **A chart verified at 1280 has not been verified.** Fixed margins that look
  generous on a desktop are most of a 375px figure: measure bar widths and every
  text node's bounding box against the SVG box at 375 too. One chart drew every
  bar 1px wide there with no error anywhere.
- **`COUNT(*) FILTER (WHERE EXISTS ...)` is not one pass, it is N.** Counting a
  corpus and two subsets of it in a single statement reads like the cheap way
  to do it; the `EXISTS` is a correlated subquery re-run per candidate row, not
  a semi-join. One hub's metric build took 50 seconds that way and 0.9 seconds
  as three separate joined counts with identical numbers. Two counts over the
  same base are still two queries — write them as such and let the planner hash
  each join once.
- **`dashboardCache()` does not fold the caller's mtime into the key** — it uses
  the string it is handed plus a global stamp. Any payload whose *shape* is
  built in the controller needs `'<key>_' . (int) @filemtime(__FILE__)`, or a
  warm server keeps serving an entry that predates the new fields.
- **Set the `scroll-margin-top` ladder from the measured bar height, never from
  the tab count.** Label length decides how many rows the bar takes: one hub
  with six tabs still reached three rows at 375px and landed every section
  29-40px *behind* the bar. And some page sheets carry
  `flex-wrap: nowrap; overflow-x: auto` below 767px, which keeps the bar on one
  row on a phone and needs no deeper step at all. An offline probe that renders
  the tab labels under the shared CSS will get those hubs wrong, because it does
  not carry the per-hub overrides. Load the real page at 1280, ~900 and 375,
  read the bar height, and click every tab checking
  `section.top - bar.bottom >= 0`.
- **A hub with no corpus of its own does not get an invented search bar.** Some
  hubs are routes into other hubs. Keep whatever record-retrieval it does have
  (inline expanders, curated links), and say plainly that there is no search
  rather than adding a box that duplicates another hub or does nothing.
- **If a hub's Metrics follow the search, say so three ways.** The shell's
  Metrics are static corpus figures on every other hub, so a section that
  silently recomputes per query reads as if it never changed: add a standing
  badge in the heading, a scope line stating what is being counted right now
  (rendered server side too, so it is right before any script runs), and a
  visible busy state while the recomputing request is in flight.
- **`.mgdb-section-heading > p` is hidden by the shell.** That is how blurbs
  were removed everywhere at once — so any `<p>` in a heading that carries real
  live state disappears the moment a page adopts `mgdb-hub-page`. Move it out of
  the heading, or exempt it by name with a comment.
- **An OR of LIKE arms is not the same cost as the arms run separately.** Two
  `EXISTS` clauses ORed with two column matches became correlated subqueries per
  candidate row: 395 + 286 + 613 + 418 ms apart, 3,323 ms together. Rewrite as a
  `UNION` of independent arms joined back to the base table, and narrow each arm
  to rows that could possibly join (a column that is NULL 93% of the time is a
  free filter).
- **`COUNT(*) OVER ()` is not always the win.** It removes a second pass over an
  expensive matched set, but with no filter it has to materialise every row
  where a plain `COUNT(*)` is an index-only scan — 1,735 ms against 699 ms on
  one 781k-row corpus. Keep both shapes and pick by whether the expensive join
  is in play.
- **A hub is only split when *every* navigation surface is split.** Two hubs
  can have their own controllers, templates, sheets and libraries and still be
  one thing to a reader. Check `templates/home/megamenu_modern/data-centers.bau`,
  `tools/sitemap_data.py` (then re-run `tools/gen_sitemap.py`), **and
  `include/data_center_hub_catalog.php`**, which is the /data_center/ directory
  — a hub split months earlier was still a single combined card there, with the
  other hub's keywords in its search terms, so the directory sent readers to the
  wrong one.
- **A directory must count what its hubs count.** The /data_center/ page counted
  whole tables while every hub counts
  `JOIN id_num i ON i.id = x.id WHERE i.curation_lvl = 0`, so it advertised
  790,208 loci at a hub that says 781,395 and 87,397 stocks at a hub that says
  80,063. Take each number from the query the owning hub uses, and take counts
  that live outside the database (a manifest, a JSON file) from the same file
  that hub reads.
- **Plotly pins a category axis's values on the first draw.** A figure that
  swaps to shortened tick labels at a breakpoint cannot do it by restyling `y`
  — the short strings become *new* categories and the figure keeps the labels
  it was born with. Key the bars on the full labels and swap
  `yaxis.ticktext` with `relayout` instead (`tickmode: 'array'`,
  `tickvals: fullLabels`).
- **`resize_window` in the browser pane fires no `resize` event.** The viewport
  changes but the page never hears about it, so any breakpoint-crossing
  relayout does not run and a figure looks stale when it is not. Reload at each
  width, or `window.dispatchEvent(new Event('resize'))` by hand — the same
  caveat as `scrollTo` and scrollspy.
- **Sibling endpoints disagree about who decodes.** Three hubs in the same
  family shared one search script and three `*_results.php` endpoints; two read
  `urldecode(getCGIParam('term'))` and the third did not. Since the shared
  script sends `term: encodeURI(...)` and jQuery form-encodes on top of that,
  the odd endpoint received `%5ECL10` for `^CL10` and matched nothing — every
  anchored and wildcard search on that hub had been silently empty, including
  the example chips the page itself offered. Post the term raw, and before
  trusting any legacy endpoint, diff it against its siblings line by line: page
  size, decoding, and which types it filters on are all places they drift.
- **A search hint is a claim you have to test.** If the hint says `^` anchors
  and `%` is a wildcard, run one of each and compare the response size against
  the unanchored term. "It returned a page" is not the check.
- **`validate_input()` does nothing.** It calls `validate_string()`, which is
  `return $input;`. Any legacy endpoint relying on it to make a term safe is
  concatenating raw POST data into SQL. Probe with a single quote and read
  `logs/mgdb.log` for `SQLSTATE`; the page will usually report "no results"
  rather than an error.
- **Look for a capped export.** `format=tsv` handlers that reuse the search's
  MAX_RESULTS constant hand back a truncated file under a button that says
  "Download" — one returned 200 of 211 rows and would have got quietly worse as
  the corpus grew. The export is the whole matched set; `LIMIT ALL`.
- **A date computed at build time and cached is not a freshness stamp.**
  `date('F j, Y')` inside a `dashboardCache()` builder freezes at whenever the
  entry was written and then presents itself as meaningful. Derive the stamp
  from the data, or show none.
- **Check the page's request against the endpoint's contract, field by field.**
  One hub sent `source=grin` where the endpoint read `mode`, sent advanced
  filter values without the `f_<name>` flags that gate them, and read
  `data.total` where the payload has `summary.total`. Each silently disabled a
  feature — a dead toggle, filters that always found nothing, and "of 0" with
  one page of pagination over 7,841 results. Diff the params the JS builds
  against what the lib reads, and diff the payload keys against what the JS
  reads. Nothing errors when these disagree.
- **A search over shared text tables should be restricted to the entity type
  first.** `description`/`synonyms`/`ext_db_key` carry every entity in the
  database; if the hub only wants stocks, push `EXISTS (SELECT 1 FROM <entity>
  WHERE id = ...)` into the scan rather than leaving it to a later join. On one
  hub `ext_db_key` was 0.8% stocks and the broadest term went 4,787 -> 1,995 ms.
- **Enumerate `[id]` and look for duplicates before trusting the tab bar.** A
  section id and a form control shared a name on one hub, so
  `querySelector('#x')` returned the control and the tab scrolled to the wrong
  place. One line in the console finds it.
- **`SELECT DISTINCT` over many columns is the slow way to collapse a wide
  view.** When the extra columns are functionally dependent on one key, GROUP BY
  that key and take `min()` of the rest — one hash instead of N per row, and 4-7x
  faster on a 2.7M-row view. Verify the dependency with
  `GROUP BY key HAVING COUNT(DISTINCT col) > 1` for every column, and check that
  none mixes NULL with non-NULL, before relying on it.
- **A join that no filter references may still be running.** Check whether it can
  change the result set at all: if the joined table has exactly one row per key,
  an INNER JOIN neither filters nor multiplies, and it can be added only when a
  filter needs it.
- **Check what a metric card counts, not just that it renders.** Three cards on
  one hub named one thing and counted another -- "Trait Categories" showing the
  number of phenotypes that have a trait, not the number of categories. Read the
  SQL behind each number against the heading above it. The other failure mode is
  a card with no query behind it at all: two archive hubs had cards reading
  "25 bp", "Archived", "10" and "2", which were respectively a form's maximum
  input length, a word, the number of tiles further down the page, and the
  number of links in each tile. A metric card is a measurement of the
  collection; if it cannot be one, it is not a metric card.
- **When a narrow label set is derived from the wide one, check it shortens the
  longest member.** One figure's rule turned "Trinucleotide" into "Tri-nt" and
  left "Longer than six" untouched -- and that was the longest label, so
  `automargin` spent 120px of a 259px figure on the gutter and the plot had
  129px. Deriving the short labels from a different field entirely took the plot
  back to 169px.
- **Delete hard-coded stat fallbacks.** A try/catch that answers a failed query
  with literal numbers prints stale values with full confidence; one hub's had
  drifted by almost 2x. Show nothing rather than a confident wrong number.
- **Verify outbound links by response SIZE, not status.** Unknown
  `/data_center/<name>` routes answer **HTTP 200** with the generic 404 body at
  about 39.6 KB, so link checkers pass them. One dead route was sitting in the
  Related resources of seven redesigned pages. Compare `curl | wc -c` and the
  `<title>` across every link before shipping a hub.
- **Read every field from the DOM in the submit handler.** Advanced inputs that
  only update state on `change` silently drop a value the browser restored
  itself (autofill, bfcache), so the form can show a filter the query omits.
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
