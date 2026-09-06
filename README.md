# MaizeGDB Redesign

Modernization of MaizeGDB pages against the Page Modernization Standard, built
and verified on the `claude` development instance.

- Development host and web root: configured locally in `deploy/config.local.sh`
  (copy `deploy/config.example.sh`). Kept out of the repository.
- Development URL: <https://claude.maizegdb.org/>
- Pattern library: <https://claude.maizegdb.org/pattern_library/>

Git is not installed on the development server, so this repository is the
source of truth and files are pushed to the server with `deploy/deploy.sh`.

## Repository layout

```
src/css/mgdb-modern.css        Shared design system (tokens + components + chrome)
src/js/mgdb-modern.js          Shared behavior (search, filters, tables, charts)
src/js/mgdb-chrome.js          Header and megamenu enhancement
src/lib/Bauplan.php            Modernized document shell (opt-in)
src/templates/static/          Page body templates (.bau)
src/pages/                     Page controllers (index.php)
icons/                         The MaizeGDB icon set — source of truth
reference/                     Unmodified copies of the files being replaced
tools/redesign_status.py       Measures how much of the site is modernized
REDESIGN_STATUS.md             Its report. Generated; do not edit by hand
docs/BASELINE_AUDIT.md         Architecture and findings from the initial audit
ADMIN_DEPENDENCIES.md          Changes that need an administrator
tools/admin_index.py           Rebuilds that file's index. Only the index
backups/<timestamp>/           Automatic pre-deploy snapshot of every server file
deploy/manifest.txt            local path -> webroot destination mapping
```

## Icons

`icons/` is the source of truth for the MaizeGDB icon set: 39 line icons drawn
on one 24×24 grid at `stroke-width: 1.75`.

```
icons/maizegdb-icons-sprite.svg  All 39 as <symbol id="mzg-SLUG">
icons/index.tsv                  slug, human label, brand colour
icons/mono/  icons/color/        Standalone SVG, currentColor and brand-coloured
icons/png/16 /24 /48             Raster fallbacks
```

**How pages consume them.** The sprite is inlined once by
`templates/home/search-box-modern.bau`, which the modern shell puts in the
header of every modern page. A page therefore only writes the reference:

```html
<span class="mgdb-search-icon" data-cat="stock" aria-hidden="true">
  <svg focusable="false"><use href="#mzg-stocks-and-germplasm"></use></svg>
</span>
```

Each symbol carries its own `viewBox` and `stroke="currentColor"`, so colour
comes from the surrounding element — in practice from the
`.mgdb-search-icon[data-cat]` tint pairs in `mgdb-modern.css`. That is why the
homepage index can group-tint its rows (Tools teal, Data indigo, Resources
gold, Community wine) while still using per-resource artwork. `index.tsv` also
records a per-icon brand colour, which nothing currently uses.

**Adding or changing an icon:** edit `icons/`, then re-inline the sprite into
`search-box-modern.bau`. Keep the `mzg-` prefix; it is what separates this set
from the four surviving `mgdb-cat-*` shapes.

**The four `mgdb-cat-*` shapes** kept beside it — `all`, `id`, `page` and
`gene-model` — have no equivalent in the new set. The first three are search
*scopes* rather than data types; the fourth is the exon-box diagram the header's
"Gene models" option needs.

**Two vocabularies meet here, so match the icon to the visible label, not to
the key.** They collide on exactly two spellings:

| Key | In the header listbox | In the search/suggestion API |
| --- | --- | --- |
| `gene_model` | the option *Gene models* | a gene record — group *Genes* |
| `gene_product` | the option *Genes* | a protein or RNA — group *Gene products* |

The other twelve keys agree. `searchall_modern.php` reconciles the two by
mapping both header gene categories onto the single `gene` type, so nothing is
functionally wrong — but anything picking an icon *by key* will draw the wrong
one. Three places resolve it, and they must stay in step:

- `templates/home/search-box-modern.bau` — listbox vocabulary. *Genes* →
  `#mzg-genes`, *Gene models* → `#mgdb-cat-gene-model`.
- `js/mgdb-searchall.js` — API vocabulary, in its `ICONS` map.
- `js/mgdb-search.js` — **both**. Recent searches store `select.value`
  (listbox) and read the index directly; suggestions carry the API value and go
  through `apiIconHref()` first.

All three end up pointing *Genes* at `#mzg-genes`.

**One name per data type.** The header listbox, the suggestion groups and the
results rail each used to write their own label for the same set of records —
*Stocks*, *Stocks / germplasm* and *Stocks and germplasm* were one category
seen from three places. They now share one spelling, and adding a category
means writing it in all three:

| Key | Name |
| --- | --- |
| `probe` | Markers and probes |
| `stock` | Stocks and germplasm |
| `term` | Traits and terms |
| `phenotype` | Phenotypes and mutants |
| `variation` | Variations and alleles |
| `person` | People and organizations |

The three places are `templates/home/search-box-modern.bau` (the `<option>`
text, `data-label` and the row's `<strong>`), the `$groupMeta` map in
`controllers/search_engine/autocomplete.php`, and `label` in `saTypeRegistry()`
in `search/searchall/searchall_lib.php`. The longest of these outrun the
category column, so the toggle ellipsises them and carries the full name in a
`title`; the menu row and the accessible name are never truncated.

**A `cat` is a glyph, not a grouping.** Four registry types were declaring
another type's `cat` — `recomb` took `map`, `primer` took `probe`, `species`
took `genome`, `journal` took `reference` — so the rail listed *Maps* and
*Recombination data* under the same icon in the same colour and they read as
one category named twice. Each now has its own `cat`, its own palette line in
`mgdb-modern.css` and its own entry in the `ICONS` map in `mgdb-searchall.js`.
`recomb` needed artwork, so `#mzg-recombination-data` was drawn for it.

**Two of the glyphs were not maize.** *Stocks and germplasm* was a seed with a
midrib and *Phenotypes* was a dicot leaf with pinnate venation — neither shape
occurs on a maize plant. They are now an ear with kernel rows and a tasselled
plant with strap leaves. Source art is `icons/mono`, `icons/color` and
`icons/png`; the sprite is `icons/maizegdb-icons-sprite.svg`, and the copy the
site actually serves is inlined in `search-box-modern.bau`, so a change has to
be written to both.

## Photographs

Every photograph on the site comes from Wikimedia Commons under a licence that
permits reuse, and every one is recorded in a `CREDITS.json` beside the files —
venue, licence, creator, work title, and a link to the source page. CC BY and
CC BY-SA both *require* the credit, so the credit is part of shipping the
image, not an optional nicety. `src/images/maize_meeting/CREDITS.json` is the
worked example; the meeting page renders its archive credits from the same data
in `js/mgdb-maize-meeting.js`.

**Check that a free photograph of the subject actually exists before promising
one.** The United States has freedom of panorama for *architectural works*
only — 17 U.S.C. §120 — and not for sculpture. So a landmark that is a building
or a bridge can be photographed and the photograph freely licensed, and a
landmark that is a sculpture cannot:

- **Chicago's Cloud Gate, "the Bean", is a sculpture** by Anish Kapoor, and
  photographs of it are derivative works. Commons holds six files in
  `Category:Cloud Gate` and they are the sculpture under construction, tented,
  or behind a closure sign; the one general view that survives,
  `File:The Bean and McCormick Tribune Plaza.jpg`, is tagged
  `{{De minimis|Cloud Gate sculpture}}` — kept because the sculpture is
  incidental, which is exactly what it would stop being if it were used *as* a
  picture of the Bean. There is no free photograph of it to use. The 2027 card
  shows the skyline from Grant Park instead, which is the same park.
- **Sacramento's Tower Bridge is a bridge**, so it is unencumbered; the 2028
  card uses a CC0 photograph of it.

When the answer is "that landmark cannot be shown", say so and offer the
nearest thing rather than shipping an image that cannot be licensed.

## Deploying

```bash
./deploy/deploy.sh
```

Deploys every mapping in `deploy/manifest.txt`. Pass a single local path to
deploy just that file. Each run first copies the current server version of every
target into `backups/<timestamp>/`, so a rollback is always available.

```bash
./deploy/deploy.sh src/css/mgdb-modern.css
```

## Rollback

```bash
scp backups/<timestamp>/css/mgdb-modern.css development-server:<webroot>/css/mgdb-modern.css
```

To roll back the shell entirely, restore `lib/Bauplan.php` from the earliest
backup directory. Because the modernized shell is opt-in, restoring it only
affects pages that call `modern()`.

## Replacing a page (standing policy)

Modernized pages replace the real route rather than living at a parallel
`*_modern` URL. Before replacing one:

1. **Archive the originals** into `legacy/<page>/` in this repository — the
   controller, every template it loads, and its stylesheet. These are files the
   redesign shadows rather than overwrites, so `deploy/deploy.sh`'s automatic
   backup would not capture them.
2. Add the new controller and assets to `deploy/manifest.txt` and deploy.
3. Verify the real route serves the new page and that no legacy markup remains.

Rollback is per page. Where the new controller *shadows* the old one (nothing
was overwritten), deleting the new controller restores the original route
immediately. Where a file was overwritten, restore it from
`backups/<timestamp>/`, or from `legacy/<page>/` for the pre-redesign version.

| Page | Route | New controller | Originals archived in |
| --- | --- | --- | --- |
| How to cite MaizeGDB | `/cite` | `controllers/cite.php` | `legacy/cite/` |
| Genome Center | `/genome` | `controllers/genome/genome_center_modern.php` | `legacy/genome/` |
| Pan-gene search | `/pan_gene_center/pan_gene` | `controllers/pan_gene_center/pan_gene_search_modern.php` | `legacy/pan_gene/` |
| Stock search | `/data_center/stock` | `controllers/data_center/stock_search_modern.php` | `legacy/stock/` |
| Stock record | `/data_center/stock/{id}` | `controllers/data_center/stock_record_modern.php` | `legacy/stock-record/` |
| Reference record | `/data_center/reference?id={id}` | `controllers/data_center/reference_record_modern.php` | `legacy/reference-record/` |
| Gene record | `/gene_center/gene/{id}` | `controllers/gene_center/gene_record_modern.php` | `legacy/gene-record/` |
| BAC search | `/data_center/bac` | `controllers/data_center/bac_search_modern.php` | `legacy/bac/` |
| Cytogenetics | `/data_center/cytogenetic` | `controllers/data_center/cytogenetic_search_modern.php` | `legacy/cytogenetic/` |
| EST search | `/data_center/est` | `controllers/data_center/est_search_modern.php` | `legacy/est/` |
| Overgo search | `/data_center/overgo` | `controllers/data_center/overgo_search_modern.php` | `legacy/overgo/` |
| SSR search | `/data_center/ssr` | `controllers/data_center/ssr_search_modern.php` | `legacy/ssr/` |
| Maize genetics nomenclature | `/nomenclature` | `controllers/community/nomenclature.php` | `legacy/nomenclature/` — see the note there |
| UniformMu insertion resource | `/uniformmu` | `controllers/uniformmu.php` | `legacy/uniformmu/` |
| TYPSimSelector | `/TYPSimSelector` | `controllers/TYPSimSelector.php` | `legacy/typsimselector/` |
| Insertion Data Center | `/insertion` | `controllers/insertion/insertion_search_modern.php` | `legacy/insertion/` |
| Genetic Variation | `/genetic_variation` | `controllers/genetic_variation.php` | `legacy/genetic_variation/` |
| Homepage | `/` | `index.php` | `legacy/home/` |
| Feedback | `/feedback` | `controllers/feedback.php` | `legacy/feedback/` |
| BLAST front page | `/BLAST` | `controllers/BLAST.php` (form branch only) | `legacy/blast/` |

`/cite` had no top-level controller, so `controller.php` fell through to
`redirect.php`, which found `controllers/about/cite.php`. Because
`controller.php` checks `controllers/<CONTROLLER>.php` first, adding
`controllers/cite.php` takes the route without touching anything under
`controllers/about/` or `templates/about/`.

Note that `/about/cite` is a second route to the same content and still serves
the original page.

`/genome` is different: `controllers/genome.php` already existed and also serves
every genome sub-page, so it could not simply be shadowed. A guard at the top of
that file routes the bare `/genome` route to the modern controller and lets
every sub-page — assembly records, project pages, the browser tutorial — fall
through to the original code untouched. Rollback is deleting that guard.

`/pan_gene_center/pan_gene` follows the same guard pattern as `/genome` and
`/data_center/reference`: `controllers/pan_gene_center.php` routes the route
with no record id to the modern search page, and every pan-gene *record* page
falls through to the original code untouched.

`/data_center/stock` adds a second guard to `controllers/data_center.php`,
beside the one already there for `/data_center/reference`. Every other data
centre falls through unchanged.

`/data_center/stock/{id}` and `/data_center/reference?id={id}` add two more.
They differ from the others in that the modern controller can decline: it
returns `false` without publishing when the identifier does not resolve, and
the guard then falls through to the original code and its 404 handling rather
than the route being answered twice.

```php
if (PAGE == 'stock' && getCGIParam('id', 'G', ID)) {
  if (include('controllers/data_center/stock_record_modern.php')) {
    return;
  }
}
```

`/data_center/bac`, `/data_center/cytogenetic`, `/data_center/est`,
`/data_center/overgo` and `/data_center/ssr` add five more of the same shape.

### The guard has to live here, not on the server

Those five pages were built and wired up **on the development server**, and the
guards kept disappearing. `deploy/manifest.txt` maps
`src/controllers/data_center.php` onto `controllers/data_center.php`, so the
repository owns that entire file: every deploy of it — a full manifest run or a
single-file one — replaces the server copy wholesale. The guards went with it
each time, while the modern controllers stayed on disk with nothing routing to
them. The pages looked like they had been reverted; in fact they had never been
reachable from anything this repository deploys.

The deploy backups record it exactly. `backups/20260815-122402/` captured eight
modern guards in `data_center.php`; the snapshot thirteen seconds later has
four.

So, for any file the manifest owns:

- **Edit it here and deploy.** A change made only on the server survives until
  the next deploy of that file and no longer.
- **Put every file the page needs in the manifest** — controller, template,
  stylesheet, script. A page whose assets live only on the server is not backed
  up by anything; the five above were one `deploy.sh` away from being lost.
- `tools/redesign_status.py` catches this. A modern controller that no URL
  reaches shows up under *Built on the design system but not routed*, and a
  server copy that has drifted from the repository shows up as a file whose
  live response disagrees with its source.

`/genetic_variation` shadows `controllers/static/genetic_variation.php` the
same way `/uniformmu` shadows its documentation controller: `controller.php`
checks `controllers/<CONTROLLER>.php` before falling through to `redirect.php`,
so adding `controllers/genetic_variation.php` takes the route and deleting it
gives the route straight back. Nothing under `controllers/static/` or
`templates/static/genetic_variation.bau` was touched, and both are archived in
`legacy/genetic_variation/` as well.

Its dataset table mirrors the one SNPVersity 2 serves at
<https://wgs.maizegdb.org/>. The two are not linked by anything, so a new build
there has to be added to `src/data/genetic_variation/genetic_variation.json`
here — the JSON carries a note saying so, and the page prints the file's
modification time as its data date so it cannot claim to be fresher than it is.

`/nomenclature` is the same story with a worse ending. It was modernized by
overwriting `controllers/community/nomenclature.php` in place rather than
shadowing it, and since it had never been a manifest target there was no
pre-deploy snapshot to archive: the pre-redesign controller is unrecoverable.
The standard itself was never at risk — it lives in
`templates/community/nomenclature.bau`, which the modern page nests unchanged,
and which is deliberately **not** in the manifest because curators maintain it.
See `legacy/nomenclature/README.md`.

## Modernizing a page

**1. Opt the controller in to the modern shell.**

```php
$bauplan = new Bauplan('Page Title | MaizeGDB');
$bauplan->modern();   // DOCTYPE, viewport, <html lang>, body class

$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
```

`modern()` is opt-in by design. Legacy pages were authored against quirks-mode
box sizing and a fixed 1280px wrapper; switching them to standards mode or a
device-width viewport would change their layout. Pages that do not call it
render byte-identically to before.

Cache busting is automatic: `Bauplan::versionMarkup()` rewrites every emitted
`href`/`src` through `assetVersion()`, which appends `?v=` + `filemtime()`. Pass
bare paths. A hand-written `?v=` *disables* that logic, because `assetVersion()`
skips any path already containing `?`.

The modern shell already gives `#wrapper` the full 1280px `--mgdb-max-width`, so
data-dense pages need nothing extra. (`.mgdb-wide` is passed by two controllers
but has no rule in `mgdb-modern.css`; it does nothing.)

**2. Wrap the page body in `.mgdb-page`** and compose from the components in
the pattern library. Every rule in `mgdb-modern.css` is scoped under
`.mgdb-modern` or `.mgdb-page`, so nothing can leak into another page.

**3. Add page-specific CSS** in its own file, scoped under a unique page class
(`.mgdb-page.mgdb-<page>-page`), and reuse the shared tokens rather than
introducing new colors or spacing values.

## The JSON API

Record pages used to assemble themselves from a handful of Ajax calls, each
returning a fragment of HTML. `/api/v1/` replaces that with one request that
returns the whole record as data.

```bash
curl https://claude.maizegdb.org/api/v1/records/stock/CML277
```

### Endpoints

| Method | Path | Returns |
| --- | --- | --- |
| GET | `/api/` | The versions this server offers |
| GET | `/api/v1/` | What v1 offers, and its conventions |
| GET | `/api/v1/openapi` | OpenAPI 3.1 description |
| GET | `/api/v1/records` | The record types available |
| GET | `/api/v1/records/{type}/{id}` | One record, fully assembled |

`GET`, `HEAD`, and `OPTIONS` are answered. The API is read-only by design:
nothing in it can change the database, which is why it needs no CSRF
protection and no write authorization.

The OpenAPI document has no `.json` extension. The sitewide `.htaccess` skips
its rewrite for any URI matching the unanchored pattern `(.js)`, which
`openapi.json` satisfies, so that request would never reach the controller.
See ADMIN_DEPENDENCIES.md AD-011.

### Record types

| Type | Identifier accepts | Sections |
| --- | --- | --- |
| `stock` | id, name, alternate description, external accession such as `PI 595550` | `overview` `pedigree` `related` `references` `offsite` `grin` |
| `reference` | id, DOI, PubMed ID | `overview` `authors` `abstract` `citation` `describes` `links` `editorial` |
| `gene` | gene model name, transcript, protein, GenBank name, gene symbol, full name, synonym, numeric locus id | `overview` `structure` `function` `expression` `variation` `pan_gene` `orthologs` `locus` `references` `xrefs` `sequences` |

A DOI contains slashes, so it is given unencoded as the rest of the path:
`/api/v1/records/reference/10.1016/j.molp.2020.03.003`. An encoded `%2F` is
decoded by Apache before PHP sees the path, so that form does not survive.

### The envelope

Every record answers in the same shape, so a client that can read one type can
read them all.

```json
{
  "api_version": "1.0",
  "meta": {
    "request_id": "82cdc753b6ce0b23",
    "generated": "2026-08-15T13:47:27Z",
    "elapsed_ms": 118,
    "query_count": 6,
    "resolved_from": "CML277",
    "sections_returned": ["overview"],
    "sections_available": ["overview", "pedigree", "related", "references", "offsite", "grin"],
    "partial": true,
    "max_items": 500,
    "truncated": [],
    "counts": { "parents": 1, "progeny": 1, "phenotypes": 2 },
    "warnings": [{ "code": "grin_unavailable", "detail": "..." }]
  },
  "links": { "self": "...", "html": "...", "search": "..." },
  "data": {
    "type": "stock",
    "id": "105132",
    "attributes": { "name": "CML277", "status": "available" },
    "sections": { "overview": {} }
  }
}
```

`meta.counts` is an independent measurement of each section's size, so a client
can label tabs and skip empty sections without fetching them.
`meta.query_count` is what the response actually cost. `meta.warnings` appears
when part of the record could not be built — the response is still `200`, and
the client decides whether to say so.

### Query parameters

| Parameter | Default | Effect |
| --- | --- | --- |
| `fields` | all sections | Comma-separated sections to return. An unknown name is a `400`, never a silent empty — a typo must not look like missing data. |
| `max_items` | 500, maximum 5000 | Cap on any one embedded list. `meta.counts` keeps the true totals and `meta.truncated` names every list that was cut. |

```bash
curl "https://claude.maizegdb.org/api/v1/records/stock/B73?fields=overview"
```

### Conventions

| | |
| --- | --- |
| Errors | RFC 9457 problem details, `application/problem+json`, with a stable `type` URI |
| Caching | Strong `ETag` on every response; `If-None-Match` gets a `304` with no body |
| Status codes | `200` `304` `400` `404` `405` `406` `503` |
| Negotiation | `406` if the client demands something other than JSON |
| Absent data | `null` for a scalar, `[]` for a list. Never an empty string or a placeholder |
| Lists | Always an array, even with one item |
| References | Another record is always `{type, id, name, html}` |
| Timestamps | RFC 3339, UTC |
| Keys | `snake_case`, matching the search APIs already in this codebase |
| HTML | None. The API returns data; presentation is the client's |
| Compression | gzip when the client accepts it — a large record drops from 183 KB to 28 KB |

An error looks like this:

```json
{
  "type": "https://maizegdb.org/api/v1/problems/record-not-found",
  "title": "Stock not found",
  "status": 404,
  "detail": "No stock record matches that id, name, or accession.",
  "instance": "/api/v1/records/stock/zzznope",
  "request_id": "32713743307dc14a",
  "identifier": "zzznope"
}
```

### Conditional requests

Record data changes on a curation cycle, not per request, so a revisit should
cost nothing.

```bash
curl -sI https://claude.maizegdb.org/api/v1/records/stock/CML277 | grep -i etag
```

Send that value back as `If-None-Match` and an unchanged record answers `304`
with an empty body.

### Where the code lives

```
controllers/api.php                    Front controller and the record-type registry
include/api/v1/lib/mgdb_api.php        The contract: envelope, ETags, problems, params
include/api/v1/records/<type>.php      One file per record type
include/api/v1/openapi.php             The OpenAPI document, generated
```

The route is mounted by `controllers/api.php`, which `controller.php` finds the
same way it finds any other controller. The code sits under `include/api/v1/`
rather than a public `/api` directory for two reasons: a real directory at that
path would stop `.htaccess` rewriting `/api/...` to `controller.php` at all,
and the resource files must not run when their path is fetched directly. They
also refuse to execute unless `MGDB_API` is defined.

**Adding a record type** is a file in `include/api/v1/records/` and an entry in
`api_record_types()`. The file is handed `$api_identifier` and `$DBConn` and
calls `MgdbApi::send()`. Nothing in `include/api/v1/lib/mgdb_api.php` knows what
a stock or a reference is.

### The record pages on top of it

`/data_center/stock/{id}` and `/data_center/reference?id={id}` are built this
way, and they are the pattern for the rest.

The controller resolves the identifier and renders the identity itself — the
name or title, the type, the document title, the social preview. That is
deliberate: a crawler, a shared link, and a reader on a slow connection all
need to know what the record *is* before any script runs. It costs two indexed
queries.

Everything else is one `MGDB.request()` to the API. Section tabs are built from
`meta.counts`, so a record with no phenotypes never renders an empty Phenotypes
tab, and each tab carries its count before you click it. Anything the API held
back — a truncated list, an external service that timed out — is stated on the
page rather than left to look like the record simply contains less than it does.

The modern controller returns `false` without publishing when an identifier
does not resolve, so the guard in `data_center.php` falls through to the
original code and its 404 handling rather than the route being answered twice.

### Diagnosing a response

`meta.counts` and the section contents are independent measurements of the same
thing. When they disagree the response carries a `count_mismatch` warning: the
database layer in this codebase returns an empty result rather than raising, so
without that check a broken query is indistinguishable from a record with no
data. It caught three during development.

## The gene product record page

`/data_center/gene_product?id={id}` is a record page on the Data Hub shell:
`mgdb-hub-page` on `<main>`, the hub hero with the record's name where the
hub's tagline would be, and the hub's section order — Search first, the
record's own sections, References, Metrics, Related resources, and the API row
last. It resolves by numeric id, product name, or synonym, and the API row
prints the identifier as the reader typed it, so `?id=ferritin` shows
`records/gene_product/ferritin` rather than the internal id.

The page is one request to `/api/v1/records/gene_product/{id}`: twenty-one
parameterized queries, all indexed probes on the resolved id, in about 80 ms.
The legacy page fired five Ajax calls that ran a query per row for most of what
they listed and interpolated the request's `id` into SQL.

Every list on the record — encoding loci, UniProt entries, EC numbers, induced
expression, metabolic constituents and pathways, motif features, ontology
terms, related gene products, sequences, probes, external entries — is rendered
by one `collection()` helper in `js/mgdb-gene-product-record.js`: a table by
default with sortable columns and a grid of the same rows, a filter, a page
size of 10 / 25 / 50 / all, and a TSV of exactly the columns on screen. The
grid tiles are `.mgdb-card` inside `.mgdb-card-grid`, so the hub's coloured
top edge on hover comes free. References use the shell's `.mgdb-ref` card, the
same markup `include/references_lib.php` emits, built client-side from the
API's rows, with a table view beside it.

### The record page shell

Two files, added when the variation record page joined the gene product one on
this layout:

| File | What it owns |
| --- | --- |
| `css/mgdb-record.css` | The furniture: the header type scale, the facts grid, a list block and its toolbar, the tables and card grids, the pagers, curator notes, the image grid, the API row, and the not-found page's search and lead |
| `js/mgdb-record.js` | The engine: `collection()`, `notes()`, `images()`, `references()`, `metrics()`, the two figures, `tabs()` and `apiCard()` |

A record page loads both after `css/mgdb-hub.css`, puts `mgdb-record-page` on
its `<main>` beside `mgdb-hub-page`, and its own script is glue: it maps one
API payload onto those pieces and names its columns. The gene product script
went from 1,109 lines to 426 that way, and the variation script is 330.
Neither file knows what a gene product or a variation is.

The class names in the stylesheet are the ones the engine emits. The two are a
pair; changing a name in one without the other breaks the page silently.

### The record page layout, settled 2026-09-01

Three changes that apply to every record page on the hub shell, not just this
one:

- **The header is one tone.** A hub header is two-tone on purpose: the emblem
  panel beside the washed body is what tells a reader they are on a hub. A
  record is not a hub, so it drops the emblem column and the decorative ring
  and takes one flat shade. The shell carries this as
  `mgdb-hub-record-hero` in `css/mgdb-hub.css`; the section keeps `mgdb-hero`
  as well, so the shell's `> section:not(.mgdb-hero)` card treatment still
  passes it over.
- **No search section.** The page opens on Overview. A reader on a record
  already found what they were looking for; the search that matters is on the
  hub, and on the not-found page below.
- **An identifier that does not resolve gets a real 404**, not a soft 200.

The two-tone hero is still on `/data_center/variation?id=`, which was built
before this settled.


### A record page's references cannot show a DOI

The reference card is the same on a hub and on a record page, but they read
different sources, and it shows. A hub names DOIs from
`data/cite_journal_articles.json`, the curated bibliography behind `/cite`,
where every record has one. A record page reads `mgdb.reference`, where **538
of 55,171 references carry a DOI — 1.0%**, and none at all since 2023.

So a record page's card shows the badge, title, authors, citation and the
abstract, and simply has no DOI line or Full text button to show. That is the
data, not the markup; recorded as AD-036. The API already extracts a DOI from
the citation text when the column is empty, which is how the few that exist
are found.

**The variation resource was not returning abstracts at all**, which is what
made its references look unlike the hub's. It now selects the same abstract
subquery the stock and gene product resources use, plus the publication type
for the badge and the same DOI extraction. The abstracts were always in
`mgdb.reference_abstract`; nothing else had to change.

### The gene record page

`/gene_center/gene/{id}` moved onto the record shell on 2026-09-02, the ninth
and last record page. Its stylesheet went from 596 lines to 259 and it gained
a real 404, Metrics, and the standard Related resources and API sections.
Three things stayed as they were because the shell has nothing better for
them: the protein domain track, the pan-gene presence strip, and — rebuilt —
the eFP viewer.

**The eFP section was requesting atlases that do not exist.** Every image on it
was an HTTP 500. The names it inherited — `Maize_Atlas_V5`, `Maize_Seed_V5`,
`Maize_Leaf_V5`, `Maize_Tassel_V5`, `Maize_Embryonic_V5`, `Maize_Stress_V5`,
`Maize_Root_V5` — name nothing the BAR serves; its own form offers
`Hoopes_et_al_Atlas_V5`, `Sekhon_et_al_Atlas`, `Maize_Kernel_V5` and the rest.
Nine of the BAR's names return real PNGs and three still 500 for every gene, so
nine are offered. The service works: an Arabidopsis control returned a 190 KB
image throughout, which is how the maize failure was isolated as a name
problem rather than an outage.

**And it is now one atlas at a time.** An eFP figure is a labelled anatomical
diagram about 1,000 px wide; eight of them at 180 px each were unreadable even
when they loaded. One fills the column, with the other eight one click away, an
Absolute/Relative scale toggle, and a link to the BAR.

**v3 and v4 gene models get expression too.** The BAR resolves a v3, v4 or v5
identifier of the same gene to the same data — verified: the three identifiers
of `lg1` return a byte-identical image — so the atlases are offered for
`B73 RefGen_v3` and `Zm-B73-REFERENCE-GRAMENE-4.0` as well, paired with the
non-V5 datasets that match those annotations.

**B73 has seven annotations and the page now says which one you are on.**
RefGen_v1, v2 and v3, two GRAMENE-4.0 releases and NAM-5.0. `is_current` is
true *within* each of them, so it cannot tell a reader that v3 is superseded;
a record on an older B73 assembly now carries a notice naming the current one
and linking to it, and Assembly and annotation are header facts.

**Two legacy sections were missing and are back.** *Nearby loci* had been
dropped as "~22 queries producing a 73 KB fragment", which the legacy
implementation was — two queries per named map set, and a full re-request
whenever the reader moved the centimorgan window, through a control that had
been commented out as broken since 2013. One query returns the widest window
and the control filters in the browser, so it is instant and costs nothing.
53 ms, once the join stops casting `locus_coordinates.map` — it is
`numeric(10,0)` and the index is on the raw column. *Additional genetic
information* — primers and enzymes, related BACs, gel patterns, map scores,
recombination — had never been ported; five legacy readers became one UNION at
22 ms.

**A variable name cost an hour of confusion.** The genetic-information loop
used `$kind`, which is also the record's own kind, set 1,000 lines above and
read again below it. Every gene with genetic data reported itself as a
"recombination" record until it was renamed.

**The 404's suggestions are deliberately exact.** A contains or prefix match
cost 1.5–2.6 s: the collation is `en_US.UTF-8` so a btree cannot serve LIKE at
all, and `chado.gene_model` is a 1.88M-row matview. Matching the four spellings
a reader types against the raw indexed columns costs 2–12 ms, and the fuzzy
case goes to the hub search, which is already tiered for it.

#### Three follow-ups on the gene record page

**Its references did not look like anyone else's** because its query selected
only id, name, title, year and relevance. The shared reference card renders a
DOI line, a publication-type badge, an author line and an abstract preview, and
had none of them to render. Selecting the same columns the phenotype and
variation resources select — plus the DOI-from-citation fallback, since
`mgdb.reference.doi` is filled for 1.0% of rows — gives 48 DOIs, 94 abstracts
and 70 author lines on the 111 references of `lg1`, for about 20 ms.

**CDS was missing from Sequences and downloads.** Probing sequence2 directly:
`nuc` wants the gene model, `cds` and `cdna` want the transcript, `protein`
wants the protein; `mrna`, `transcript`, `genomic` and `pep` are not recognised.
CDS is the coding sequence without the UTRs, a different thing from cDNA, and it
is what most people mean by "the sequence" of a gene. The section is now a
per-transcript table with CDS, cDNA and Protein columns — the glue had also been
written against the wrong payload shape, so it was rendering almost nothing.
The service itself is intermittent: the same request answers with FASTA, with
"SEQUENCE SERVICE IS DOWN", or with a not-found error on different attempts, and
the section says so rather than letting a reader conclude the sequence is gone.

**Protein names cannot be derived from transcript names.**
`Zm00001eb334630_P001` returns a protein; `_P002` does not, though `_T002`
exists. Where the annotation does not name a protein the column stays empty.

**The JBrowse preview was lost in the port and is back.** The legacy Overview
embedded a 300px frame of the gene with 1,500 bp either side; it is 420px here,
because 300 cut the track stack off. It came back blank at first: `loc` and
`tracks` were percent-encoded, and JBrowse parses the track list on literal
commas and the location on a literal colon, so the tracks silently did not load.
Unencoded, the frame draws the v5, v4 and v3 models of the gene together. Only
JBrowse assemblies can be framed — B73 v3 and v4 point at GBrowse, which serves
a snapshot image — so those get the link alone.

### The stock record page

`/data_center/stock?id={id}` moved onto the record shell on 2026-09-02, the
last of the eight and the one the whole series was modelled on. Like the
reference page it was already modern, so this was a refactor.

**Its stylesheet went from 1,284 lines to 559**, and what is left is the three
things that are genuinely this page's own: the pedigree viewer, the
TYPSimSelector card, and the ordering panel. Page layout, tabs, facts, the
provenance footer, the lightbox and the list/table view switch are the shell's
and were deleted rather than duplicated. The script went from 1,236 lines to
747 the same way.

**Three things were kept as they were, deliberately.** The pedigree viewer is
not a shell collection — the graph is the point and the table beside it is a
second reading of the same rows, not an alternative layout of a list — so its
markup, its search, its sort and its CSV are untouched. The TYPSimSelector card
and its score bars are likewise its own. And the ordering panel keeps its
disabled button and its "Cart handoff coming soon" note: the cart is not built
yet, and a control that looks live but does nothing is worse than one that says
so.

**The image gallery came back to where it started.** The shell's gallery was
modelled on this page, so moving to it preserves the cards, the badges and the
lightbox exactly, and adds the table view, the filter, the page size and the
TSV. One thing went the other way: this page's `onerror` fallback to the site
mark, for a record naming an image file the image server no longer has, is now
in the shell for every record page.

**GRIN is fetched on its own now.** It is a live BrAPI call to
npgsWeb.ars-grin.gov and cost **461 ms of the record's 724**; everything else
answers in about 250 ms. The section and its tab appear when it lands.

**Which meant the ordering panel had to stop depending on it.** The panel picks
the Stock Center or GRIN, and it was reading the GRIN section — which would
have made it appear half a second late on every GRIN-distributed stock. The
GRIN *accession* is a MaizeGDB fact and the offsite list already carries it, so
the panel is settled by the first response.

**An unresolved identifier used to return a soft 200** here too. It now
publishes a real 404 whose first arm reads the term as a variation and lists
the stocks carrying it — `bz1` is an allele, and what someone typing it into a
stock URL wants is seed.

## The term and trait record pages

`/data_center/term?id={id}` and `/data_center/trait?id={id}` came onto the
record shell on 2026-09-02. They are **one record**: both legacy pages read
`mgdb.term`, and they differ only in which sections they draw — `/trait` shows
phenotypes, QTL analyses and trait values, `/term` shows related terms,
external entries and images. The modern page draws all of them and lets the
data decide, so both routes render the same record and the route chooses only
the noun in the title. 6,815 curated terms across **105 types**.

### The GWAS trait map is curated data, not a code smell

`check_gwas_trait()` was a 41-case switch mapping JBrowse GWAS display names to
term ids, with a comment saying that adding real ids to 52 GFF3 files was not
worth it. The obvious cleanup is to turn underscores into spaces and match on
name.

**That cleanup is wrong, and checking it is what proved it.** The rule
reproduces 6 of the 41, and for several it resolves to a *different record*:
`Plant_height` is term 3097755, "plant height, PANZEA", while the name rule
lands on 64851; `Stalk_strength` is "rind puncture resistance PANZEA";
`Nodes_above_ear` is "node number, tassel to ear". These are curated aliases.
The map is ported verbatim, with the evidence in the comment above it.

**Check a lookup table against the data before replacing it with a rule.**

### The trait-values download has been broken

The legacy trait page offers "Download all values for '<trait>' to a text file".
Its endpoint answers with a PHP fatal — `fwrite(): Argument #1 ($stream) must be
of type resource, bool given` — so it has been dead for every one of the **121
traits** that carry values. The modern page summarises them instead (count,
stocks, range, mean, units) and links the IBM/NAM viewer and the bulk download
directory, both of which work. The broken endpoint has other callers and was
not touched; it is reported, not fixed.

### The reference card is a shape, not a helper call

`R.references()` renders the shared publication card, but it reads **flat**
fields off each row — `citation`, `title`, `authors`, `year`, `doi`,
`pub_type`, `relevance`, `abstract`, `html`. Both the locus and term resources
first shipped a nested `{ref: {...}, title, year, contents}` instead, so the
helper found no `html`, no `authors` and no `doi`: the section rendered, and
rendered wrong — bare titles with no card, no DOI badge, no abstract, no Copy
citation. Nothing errored, because every missing field is optional.

Calling the shared helper is not the same as matching the shared shape. When a
section is meant to look like another page's, diff the **row keys** against a
page that already has it (`include/api/v1/records/marker.php` is the reference
implementation), not just the function name.

The DOI needs its own handling: it is stored bare, prefixed with `doi:`, or as
a full `doi.org` URL, and sometimes only inside the citation string. One
pattern pulls the bare DOI out of any of those, and the card's Copy DOI button
depends on it.

### The comparison caught a wrong route

The first draft linked a QTL experiment as `/data_center/qtl_analysis?id=`.
These are `mgdb.qtl_exp` rows, and that route renders an all-but-empty page for
them; `/data_center/qtl?id=` is what the legacy page uses and what works. Ten
terms compared by distinct linked id, **0 gaps** after the fix.

Also: `trait_means_values.value` is `numeric(20,10)`, so a plant height of 46
arrives as `46.0000000000` and a range line reads as noise until the trailing
zeros are trimmed for display.

## The locus record page

`/data_center/locus?id={id}` came onto the record shell on 2026-09-02 — the
site's **second-busiest record page**: 19,774 requests over six days of
production logs, across 9,444 distinct records and 15,771 client addresses,
every user agent a browser and none a crawler. It was the last big legacy
record page, and the one every modern hub and record already linked into.

### Twenty-six types, one section set

`mgdb.locus` holds 26 curated types — 686,356 Points, 48,301 Probed Sites,
26,115 Genes, 15,565 BACs, 1,758 QTL, then a tail of 21 types with 517 or
fewer. **They share one page.** The legacy code has exactly one type branch
(`type_name == 'Gene'` labels the identity fields "Gene symbol / Gene name"),
and everything else that differs between a Centromere and a QTL is which
sections have rows. So nothing in the modern page is conditioned on type
either: a section appears when its data does.

**Loci of type `Gene` never render here.** `check_id()` redirected them to
`/gene_center/gene/{id}` and the modern controller does the same, with a 302 —
which type a locus carries is curated data, and a 301 would be cached past the
next reload.

### Verifying that nothing was lost

Every distinct record the legacy page links was compared against the modern API
for the richest example of each of the 25 non-Gene types: **25 of 25 complete,
0 gaps.** Comparing *link counts* is not the check — the legacy HTML links the
same map or probe from two places, so raw counts differ by design. Comparing
the **set of distinct ids per record type** is.

That comparison is what caught the real bug, below.

### Six sections were silently empty

`SELECT DISTINCT … ORDER BY LOWER(col)` is rejected by Postgres when the
expression is not in the DISTINCT select list, and this codebase's database
layer turns that rejection into an **empty result rather than an error**. Six
queries were written that way — phenotypes, stocks, related BACs, recombination
data, variation external keys, and gene-product external keys — so six sections
rendered as "no data" on a record that had plenty. Nothing logged.

The fix is to put the ORDER BY outside the DISTINCT
(`SELECT * FROM (SELECT DISTINCT …) s ORDER BY LOWER(s.name)`). **Grep every
new resource for `DISTINCT` and an `ORDER BY` expression before trusting an
empty section.**

### A numeric join column cost 500 ms of a 120 ms record

`locus_coordinates.map` is `numeric(10,0)` and `map.id` is `bigint`. Written as
`m.id = lc.map`, Postgres casts the **indexed** side — the plan becomes
`lc.map = m.id::numeric`, the index on `map.id` is unusable, and a four-row
lookup becomes a parallel merge join over 66,005 buffers: **502 ms**. Casting
the numeric column instead (`m.id = lc.map::bigint`) makes it **0.4 ms**. The
whole record went 500 ms → 120 ms.

### Ported, dropped, and why

Kept: identity and description, functional statements, critical comments,
curator notes, properties, expression induction, gene products, the Maize Gene
Review flag, assembly issues, map positions, nearby loci on four mapsets,
alleles with their phenotypes and images, stocks, the probes that detect it,
primers and enzymes, related BACs, gel patterns, map scores, recombination
data, related loci, associated gene models, external entries at five levels,
ontology terms, and references.

Dropped with evidence, all recorded in `legacy/locus-record/README.md`:
Chromosome Coordinates (`showChrCoords()` opens with `return;` and a comment
saying it was removed on request), Molecular/Sequences (`mgdb.z_sequence` has 0
rows), domain experts (defined, never called), Quick Summary (commented out),
Jira issues (commented out, service 502s), and the logged-in curator's
annotation editor.

**Images hang off the variations, not the locus.** Joining `web_image` to the
locus finds 2 records; joining it through the locus's variations finds 75,020 —
which is the section the legacy page actually renders. Measuring the wrong join
would have looked like a dead feature.

**Physical positions are alive.** `gblade.usda.iastate.edu` still answers with
real coordinates, so the feature was ported — as an opt-in section
(`?fields=physical`) the page fetches after the rest has rendered, with both
assemblies requested together. It is the record's only outbound dependency.

### Two shell rules, relearned the hard way

- **Bauplan emits page scripts in `<head>`**, so the root element lookup at
  parse time returned null and the whole file returned early — the page sat on
  "Loading the full record" with no error anywhere. Same trap as the Metabolic
  Pathways hub, in the same week.
- **A figure must be drawn after its section is visible.** Plotly measures the
  container at draw time, and a container inside a `hidden` section measures
  zero, so the chart fell back to its own 700px default and overflowed a 533px
  column — taking the whole page into horizontal scroll.

## The section-tab sweep

Swept every template carrying `.mgdb-section-tabs` on 2026-09-02, after the
Metabolic Pathways hub shipped with an inert one. **65 templates have a tab
bar; eleven had no tab behaviour behind it at all** — the bar highlighted
whichever tab the template marked and never changed:

`/amaizing_project`, `/CAAS_FIL_project`, `/european_flints`, `/fatcat`,
`/14InbredsFISH`, `/HiLo_project`, `/historic`, `/genome/jbrowse2_tutorial`,
`/person`, `/genome/whole_genome`, `/pattern_library/`.

`/person` was the worst: no tab was marked at any scroll position, because its
template carries no static `is-current` either.

**Why it went unnoticed.** Clicking a tab works on inert markup — the browser
handles the `#` anchor itself — so a page tests fine unless you scroll and watch
the bar. Nothing logs and nothing throws. The check is one line, run at two
scroll positions; if the answer is the same both times, the bar is inert:

```js
[...document.querySelectorAll('.mgdb-section-tabs a')].filter(a => a.classList.contains('is-current'))
```

**The fix is one implementation, not a twelfth copy.** `MGDB.sectionTabs()` in
`mgdb-modern.js`: scroll, `IntersectionObserver` and resize together, the
trigger line read back from each section's own `scroll-margin-top`, a click-hold
so a smooth scroll does not drag the highlight through every section on the way,
at-bottom handling so the last section is reachable, and an optional `watch`
selector for a region whose height changes.

It is **opt-in, deliberately not auto-wired from `init()`** — the twenty pages
that already have their own copy would otherwise run two spies over one bar and
fight over the click hold. Each of the eleven now calls it in one line, and the
Metabolic Pathways hub's own private copy was replaced by the same call rather
than left as a twelfth duplicate.

The 13 record pages were never affected: `mgdb-record.js` has always driven
their tabs through `R.tabs()`, and it was not touched.

**One page left as found.** `/genomes_modern/` (`mgdb-genomes.js`) has an
`IntersectionObserver`-only spy — no scroll or resize fallback and no at-bottom
handling, so its last section may never be reachable. A weaker variant, not an
inert bar, so it was reported rather than changed.

## The Metabolic Pathways Data Hub

`/metabolic_pathways` came onto the Data Hub shell on 2026-09-02, together with
its three documentation pages, which `redirect.php` had been serving as raw
templates. It is also where the CornCyc instances MaizeGDB hosted were retired.

### The data was already here

The page had no search and no metrics because nobody had looked for a corpus.
**`mgdb.corncyc_gene_model_pathway` holds 23,957 rows** — gene model, CornCyc
enzyme, CornCyc pathway, for B73 RefGen_v3 and RefGen_v4 — which collapse to
**549 distinct pathways over 14,041 gene models and 1,474 enzymes**. That table
is MaizeGDB's own; it never lived on the hosted websites, so retiring them did
not touch it. Check for a table before concluding a page is a link list.

**A pathway is the record, not a row.** The census is built once (a full scan,
~150 ms) and cached; pathway search then runs over the cached array with no SQL
at all, and only a gene-model term reaches the database — on `gene_model`, the
table's one indexed column. Every query answers in **3–65 ms**.

### The names carry MetaCyc's own markup

2,414 pathway names and 2,337 enzyme names contain a tag — `<i>`, `<em>`,
`<sub>`, `<sup>`, `<small>` in either case — and 1,269 names are additionally
wrapped in literal double quotes (`"2,4,6-trinitrotoluene degradation"`).
Printing them raw puts database content into the page unescaped; escaping them
wholesale shows the reader `<i>de novo</i>`.

So `mpRich()` strips the wrapping quotes, escapes everything, then re-enables
exactly those seven tags **bare** — `&lt;i&gt;` is restored, `&lt;i onclick=…&gt;`
is not. A second helper, `mpPlain()`, strips them for the TSV, the title
attribute, and **for matching**: searching `de novo` has to hit
`<i>de novo</i>`, and it would not if the index held the markup.

### A pathway ID is sometimes also an English word

`GLYCOLYSIS` is the CornCyc ID of *glycolysis I (from glucose 6-phosphate)*, so
an arm order of "exact ID wins, stop" answered **glycolysis** with one pathway
when the corpus holds three. The ID and name arms are merged now, ID first,
de-duplicated — and `matched_by` says `pathway_id_and_name` rather than
claiming an ID match for a set that is mostly name matches. **A search that
reports which arm matched has to report the arm that actually produced the
rows.**

### Retiring CornCyc at MaizeGDB

`corncyc-b73-v4.maizegdb.org` and `corncyc-b73-v3.maizegdb.org` were both still
answering (HTTP 200, ~36 KB) when they were unlinked. They are unlinked from
**four** modern surfaces, not one — the page itself, the Tools megamenu
(`templates/home/megamenu_modern/tools.bau`), the site map (via
`tools/sitemap_data.py`, then re-run `tools/gen_sitemap.py`), and the EC-number
link list on every gene product record
(`include/api/v1/records/gene_product.php`, now
`pmn.plantcyc.org/CORN/substring-search`, the same Pathway Tools search on the
maintained host).

Three **legacy** surfaces still link the retired instances and are not under
repo control: `templates/home/megamenu/tools.bau`,
`templates/data_center/gene_product_sections.bau`, and
`search/alldata/syn_results.php` — the last pointing at `corncyc.maizegdb.org`,
which already 301s to an unreachable target. Editing files the manifest does not
own means the next deploy reverts them.

**Unlinking is not decommissioning.** Whether the two subdomains stay up is a
server decision and was not made here.

### An automated link check cannot confirm PMN

`pmn.plantcyc.org` sits behind an Incapsula bot check: from the dev server it
returns 212 bytes of challenge, and a real browser gets Michigan State's
"additional security check is required" page. That is not a dead link, and it
was **not** recorded as one — the same caution as the `m2m.dill-picl.org`
lookup on the cytogenetics hub. The pathway rows therefore offer MetaCyc
(`metacyc.org/pathway?orgid=META&id=…`, verified, 58 KB) as the reachable
second address for the same CornCyc ID.

`http://www.genome.jp/dbget-bin/get_linkdb?-t+2+gn:T01088`, the KEGG maize link
the old page carried, **is** dead: HTTP 400 with a browser user agent too.
Replaced with `kegg-bin/show_organism?menu_type=pathway_maps&org=zma`.

### Three shell rules this page relearned

- **`.mgdb-hub-field` is a labelled *cell*, not a search row.** It is
  `flex-direction: column`, so putting the input and the submit button in one
  stacked them full width. The form is the same
  `grid-template-columns: minmax(0,1fr) auto` every other hub search uses, with
  the field and the button as separate cells.
- **`.mgdb-hero` is a two-column grid with an emblem column.** A documentation
  page with only `.mgdb-hero-body` put its `<h1>` in the 150px column, one
  character per line. The shell already has the fix: `mgdb-hub-record-hero`
  collapses it to one column.
- **Bauplan emits page scripts in `<head>`.** Reading elements at parse time
  returned null for all of them, and because every use was guarded, nothing
  errored — the figure and the search simply never appeared. Every element
  lookup belongs in an `init()` behind the `readyState` check, which is what
  `mgdb-ai.js` and `mgdb-qtl.js` already do.
- **There was no shared scrollspy, and eleven other pages had none at all.**
  `.mgdb-section-tabs` is markup the shell styles; the behaviour was a per-page
  `initTabs()` that twenty pages each carried their own copy of. A page that
  ships without one has an inert tab bar — it highlights whatever the template
  marked `is-current` and never changes — and nothing errors, so it looks fine
  until someone scrolls. There is one implementation now,
  `MGDB.sectionTabs()`; see **The section-tab sweep** below.

Also: the chart height floor has to be the sheet's own `min-height`. A computed
304px lost to `min-height: 320px` and left a 304px element around a 320px
figure.

### The cytogenetics `?id=` route

`/data_center/cytogenetic?id={id}` came onto the record shell on 2026-09-02 —
as a **router**, because there is no cytogenetics record to put on it.

**Cytogenetics is not a record type.** There is no `cytogenetic.php` record
controller and no `cytogenetic_data.php`; the hub gathers three kinds of record
that each already have their own modern record page: cytological maps
(`mgdb.map`, `Cytological 1` = 40028) to `/data_center/map/{id}`, cytological
landmarks (`mgdb.locus`, types 121, 122, 24978, 111) to
`/data_center/locus?id=`, and structural-variant stocks (`mgdb.stock`, `922D` =
14566) to `/data_center/stock?id=`.

**What it did before was worse than a 404.** `?id=Cytological%201` answered
HTTP 200 with the *pre-redesign* search page and ignored the id, so a real
identifier looked like a page that had simply forgotten it. It now resolves the
identifier against all three collections in about 9 ms and issues a 302 to the
page that holds it, or renders a real 404 on the record shell with suggestions
drawn from all three.

**302, not 301.** Which collection an identifier belongs to is a property of
the data, and the data is reloaded. A permanent redirect would be cached by the
browser past the next curation change.

**A suggestion's second column has to say something the first does not.** The
Kind column already named the collection, and the map arm's type was the
literal `'Cytological map'` — so every map row read *Cytological map /
Cytological map*. The columns are Collection and Detail now, and a map's detail
is its chromosome, from `mgdb.linkage_group` (`m.linkage_group` does not join
to `mgdb.locus`, which is the obvious wrong guess).

### The BAC record page

`/data_center/bac?id={id}` came onto the record shell on 2026-09-02, the fourth
and largest probe collection: `mgdb.probe` type 171715, "BAC clone", 430,550
rows. Unlike SSR, overgo and EST it was **not** a twenty-line lib — the legacy
BAC page had eight sections where they had five or six.

**Four of those eight cannot produce content**, which is why the page is still
mostly the marker record. Sequence and Genome Browser both read
`mgdb.z_sequence` (0 rows) and `mgdb.zb_chr_v2_clone` (0 rows); Alignment is a
`file_exists()` check against `images/BAC_alignments`, which is not on the
server; Issues is a live Jira call to `collect.maizegdb.org`, which returns
502. Checked first, then not ported, with the evidence in
`legacy/bac-record/README.md`.

**Related probes was the one real gap, and it went on the marker resource.**
"This BAC is detected by overgo X" is a `mgdb.relation` row — 269,440 of them
for BACs alone — and the relation is not particular to BACs, so every marker
record page gets the section. Each row routes to whichever collection owns the
related probe, which the legacy page did by hand in a chain of type-id
comparisons and which is only possible now that all four collection pages
exist.

**BACs resolve by GenBank accession.** 303,536 of them carry one, in
`ext_db_key`, where the name and synonym arms do not look. The legacy page's own
documented test URL was `/data_center/bac/AC205396` and it threw a PHP fatal
there; it now resolves to `c0040M18`, in 6 ms.

### The EST record page

`/data_center/est?id={id}` came onto the record shell on 2026-09-02, the third
probe collection. `p-bcd98` is `mgdb.probe` id 110916 of type 34, "cDNA - EST"
— 59,308 of them. `include/est_record_lib.php` is twenty lines: the type id,
and four wrappers over `probe_collection_lib.php`. That was the point of
factoring it after the second one.

**And the third one found a bug in the shared code.** The suggestion query's
synonym arm was a prefix match, which makes Postgres scan `mgdb.synonyms`
(2.8M rows) — no index can serve LIKE under `en_US.UTF-8`, AD-030 again. It had
looked cheap because on 13,430 overgos the planner drove from the probe side
instead; at the EST collection's 59,308 it flipped to the scan and the 404 went
to **1.8 s**. **A plan that holds only because a table is small is not one to
rely on.** The synonym arm is now an exact match in the spellings a reader
types, which `idx_synonyms_synonyms` serves in 6 ms, and it still answers the
case it exists for. Every collection 404 is now 0.13–0.16 s, overgo and SSR
included.

### The overgo record page, and probe collections

`/data_center/overgo?id={id}` came onto the record shell on 2026-09-02,
immediately after the SSR page and for the same reason: `CL0_-2_ov` is
`mgdb.probe` id 389357 of type 393660, "Unigene-Overgo". The collection is that
type plus 747274, "Overgo" — 13,430 probes between them, the pair the modern
overgo search page already filters on.

**The second one is what makes it a pattern.** Everything the SSR and overgo
record pages share beyond the marker record itself now lives in
`include/probe_collection_lib.php`: resolving inside a set of probe types,
naming the marker a stray identifier actually reached, prefix suggestions
within the collection, and the cached collection total. Each page's own lib is
a dozen lines saying which types it contains. A third such page is a
`define` and a template.

**Sequence Match is a dead section and was not ported.** The legacy overgo page
joined `z_sequence` to `id_seq`; **`mgdb.z_sequence` has 0 rows**. 11,384
overgos carry 12,132 `id_seq` links and none resolve, so that section has been
empty for every overgo since the table was emptied. The marker record page's
own Sequences block reaches `z_sequence` by a different path and is empty for
the same reason — worth knowing before anyone reports it as a regression.

### The SSR record page

`/data_center/ssr?id={id}` came onto the record shell on 2026-09-02. It is the
tenth record page and the only one that is not really its own page.

**An SSR is a probe.** `p-umc1246` is `mgdb.probe` id 242172 with
`type = 104436`, "PCR - SSR" — the same table, and the same record shape, the
marker page already reads. `/api/v1/records/marker/242172` answers it in full:
17 queries, 68 ms, with the detected locus, its 20 map positions, 11 gel
patterns and primers, 6 external entries and a curator note.

**So the page shares the marker record's everything** — API resource, element
ids, script and stylesheet. What is its own is the framing, resolution inside
the SSR collection, and a 404 whose first arm names the marker page: an
identifier like `p-umc10` is a real probe of another type, not a mistake, and
the reader should be sent to it rather than told nothing exists. Writing a
second resource and a second script would have bought nothing and guaranteed
the two drifted.

All five legacy sections — Overview, Annotations, Related Data, Detected Loci,
Map Coordinates — are present, alongside the Offsite resources, Metrics,
Related resources and API sections the shell adds.

### The reference record page

`/data_center/reference?id={id}` moved onto the record shell on 2026-09-02.
It had been modernized before the shell existed, so this was a refactor rather
than a port: the API resource, which already answers in about 70 ms over nine
queries, did not change at all. What changed was everything above it.

**Its own 580-line stylesheet is gone.** The page had hand-built an author
list, a locus card, a chip grid, a citation block and a link list; all five are
shell collections now, which means each one gained a table view, a grid view, a
filter, a page size and a TSV that it did not have before. The script is the
same length it was and no longer knows what a reference is. The only CSS the
page needed that the shell did not have is the citation block, which went into
`css/mgdb-record.css` because it is record furniture rather than reference
furniture.

**An unresolved identifier used to return a soft 200.** The controller returned
false and let the legacy handler answer. It now publishes a real 404 with two
suggestion arms: the term read as an author, which is what someone typing
`Schnable` into an id parameter wants, and references whose title contains it.

**The corpus count on that 404 was most of its cost.** Counting 54,900
references against `id_num` takes about 320 ms; through `dashboardCache` the
page went from 555 ms to 245 ms. A number that describes the whole collection
belongs in that cache even on a page that is already an error.

**Volume 0, issue 0, and a pages field holding the DOI** are what this table
records when a paper was indexed before the publisher assigned them. Rendering
`0` as a volume would be a claim the data does not make, so Overview drops
those rows, and drops the pages row when it is holding the DOI that already has
its own.

**A paper can be curated against 36,006 records.** The API caps embedded lists,
and the block now says what it is a slice of rather than leaving a reader to
infer it from a count of 500.

**The Editorial Board's own comments are on the record now.** They live in
`mgdb.memo` under the term `Editorial Board Member Comment` (and its CODIE
variant), which is where `/hot_new_papers` has always read them; the record
page never showed them. 795 of the 845 picks carry one, and it is the only
prose on a reference record that MaizeGDB wrote rather than the publisher.

**The comment is attributed only where the data attributes it.** `memo.source`
is set on 31 of them. The nominating member is very often the person who wrote
the comment — 826 of 846 picks have a single nominator — but nothing in the
schema says so and the site has never claimed it, so the page does not either.
Many comments sign themselves in their own last line, which is the curators'
attribution and needs no help.

**A nomination can name two board members** and the API was reading only the
first. `ed_board_papers.person_id2` is set on 43 of its 881 rows; those names
were missing from the record and are now a column of the nominations table,
beside the month, which was also being dropped.

### The pan-gene record page

`/pan_gene_center/pan_gene/{id}` joined the record shell on 2026-09-02, the
sixth page on it and by a distance the largest: nineteen sections, against the
eleven of the phenotype record. New: a `pan_gene` record type in the API,
`include/pan_gene_record_lib.php`, the controller, two templates and 830 lines
of glue.

**Fifteen Ajax calls became one request.** Opened one after another the legacy
sections cost **9.3 s**; the replacement is twenty parameterized queries in
about **550 ms**, of which roughly 190 ms is a single parallel round trip to
another host to ask whether four files exist. The section-by-section table is
in `legacy/pan-gene-record/README.md`.

**One CTE defines the members, and every section joins against it.** A pan-gene
of 65 members has 30 with gene pages and 35 without, and the legacy page ran
two queries per page-less member to name its annotation and assembly, then
repeated that loop in two more sections. Recomputing the member set inside each
query — one indexed lookup on `pan_gene_name`, one lateral against
`chado.genome_metadata` by gene model prefix — replaces over 200 round trips
with one bind parameter and no escaping.

**Postgres will not take a PHP regex as written.** `getGeneModelPrefix()` is
`preg_replace('/(\w\w\d+\w+?)\d+.*/', '$1', $gm)`, and moving it into SQL
looked trivial. It is not: Postgres decides greedy or non-greedy for a *whole*
regular expression from its first quantifier, so the `\w+?` stops being
non-greedy and `Zd00001ab007194` captures as `Zd00001ab00719` rather than
`Zd00001ab`. The SQL uses explicit character classes instead.

**The exemplar arm of the resolver had to move last.** Five of the six
identifier forms hit a btree index and answer in 1–6 ms. The sixth,
`exemplar_gene_model`, has only a gin index, which serves containment rather
than equality: as part of the main UNION it made every lookup cost 124 ms. Run
only after the indexed arms miss — and against `chado.pan_gene_exemplar`, whose
seq scan of 177,953 rows costs 29 ms — it is a fallback for the **62 of 97,184
pan-genes** whose exemplar is not one of their own member rows.

**The section colour rotation now rotates.** `mgdb-hub.css` enumerated ten
colours over `nth-of-type(2)` to `(11)`, extended twice as pages got longer. At
nineteen sections enumerating stops being sensible, so the same ten colours
repeat with a period of ten. Positions 2 to 11 keep exactly the colours they
had.

**jQuery loads after the controller's own scripts.** The modern shell emits
jQuery from its header template, which comes after every `includeScript()` a
controller adds — and `js/phylotree.js` touches `$.ui.dialog` at load time. Any
page that reuses a legacy jQuery viewer has to load jQuery and jQuery UI itself,
first, or the viewer throws before it defines anything.

**Two defects in the legacy code**, both in
`legacy/pan-gene-record/README.md`: the CornCyc pathway lookup joined its gene
model and transcript lists with no separator between them, silently dropping
one of each from every result; and the insertions table captioned itself with
whichever gene model came last in the loop.

### The phenotype record page

`/data_center/phenotype?id={id}` joined the record shell on 2026-09-02, the
fifth page on it. Sections: Overview, Genes, Variations, Stocks, Images,
Offsite resources, Annotations, References, Metrics, Related resources, API.
New: a `phenotype` record type in the API,
`include/phenotype_record_lib.php`, the controller, two templates and 300
lines of glue. Fourteen queries, about 120 ms on `dwarf plant` — the largest
phenotype in the database, with 309 variations, 112 stocks and 203 images.

**The header counted rows the sections did not.** `phenotypeIdentity()` first
counted `var_pheno_effects` and `stock_phenotypes` unfiltered, so the header
claimed 311 variations and 203 stocks while the metric cards below said 309
and 112. Both now apply `id_num.curation_lvl = 0`, the filter every section
already used. Any record page that computes a count server-side for the header
has to apply the same filter as the resource that fills the body, or the page
contradicts itself above the fold.

**The images belong to the variations, not the phenotype.** A phenotype rarely
carries pictures of its own; the 203 on `dwarf plant` are pictures of the
variations that show it. Each card names its variation and links to that
record, which is what `subject` and `record` in the images section carry.

**Two figures beside each other are now the same shape.** The genes chart and
the connections chart sat side by side at 372px and 386px. `connectionsHeight()`
in `js/mgdb-record.js` exposes the height the connections chart would choose,
so a page can size a neighbouring figure to match before either is drawn, and
cut its own series to the number of bars that fits. `connectionsChart()` takes
that height as an optional fifth argument. Both are additive; the four earlier
record pages are unaffected.

**What the legacy page did that this one does not** is in
`legacy/phenotype-record/README.md`: a locus query per variation (309 extra
round trips on `dwarf plant`), genes rendered as a `<br>`-joined string of
links to the dead `/gene_center/gene/{id}` route with no counts, and stocks
packed into three unlabelled columns with the provider dropped.

### The marker record page

`/data_center/marker?id={id}` joined the record shell on 2026-09-02, the
fourth page on it and the first built from nothing: markers had no modern page
and no API resource, only the legacy Ajax page. New: a `marker` record type in
the API, `include/marker_record_lib.php`, the controller, two templates and
300 lines of glue. Sections: Overview, Detected loci, Map positions, Related
records, Offsite resources, Annotations, References, Metrics, Related
resources, API. Seventeen queries, about 75 ms.

**Names resolve with or without the `p-` prefix.** The record is `p-umc10`,
people type `umc10`, and `umc10` is also one of its synonyms. Both spellings
are tried against the name and the synonym table before any case-insensitive
pass, so both reach the record on an indexed probe.

**Two things in the legacy page could never have worked**, found while porting
and recorded in `legacy/marker-record/README.md`:

- **`show_detected_loci()` never advanced its counter.** `$count` is set to 0
  and incremented nowhere in the loop, so every locus overwrote
  `$loci_results[0]` and the page showed **one** locus however many the probe
  detected. `p-umc10` detects four.
- **`probe_contains_probe` does not exist** in the schema, and
  `read_contains()` selects from it twice. That section was always empty. Not
  ported.

The figure is map positions by chromosome. A probe that detects one locus sits
on one chromosome; one that detects several usually does not, and that is the
thing worth seeing. `p-umc10` has 73 positions across 4 chromosomes.

**A metric that restated another.** The first draft counted distinct maps
beside map positions, and on this record both read 73 — nearly every position
is on a different map, so the pair said one thing twice. Chromosomes replaced
it.

**Related resources now keeps its green edge on every record page.** The
shell rotates eight colours over `nth-of-type(2)` to `(9)` and the rotation is
declared after the Related resources block, so on a nine-section record page
the closing green panel took a rotation colour while a ten-section one stayed
green. `css/mgdb-record.css` pins it.

### The map record page

`/data_center/map/{id}` joined the record shell on 2026-09-01, the third page
on it. Sections: Overview, Mapped loci, Maps in this series, Other maps on
this chromosome, QTL experiments, References, Metrics, Related resources, API.
Its own 610-line stylesheet and 859-line script are gone; the script is 300
lines of glue.

**Two defects in its API, found by putting it on the shared engine:**

- **It was the only record type not using the standard envelope.** It called
  `MgdbApi::sendDocument()`, which takes a payload and a max-age and nothing
  else — so the third argument, carrying `counts` and `truncated`, was accepted
  by PHP and thrown away. No client ever learned that the coordinates list had
  been capped. `/data_center/map/978377` carries **5,271 loci and was sending
  500** with nothing to say so; the page now shows "Only the first 500
  coordinates are shown; the record has 5,271." It answers in the standard
  envelope now, like the other four.
- **`truncated` named `sections.coordinates`**, a path that matches no key
  anywhere else, so even a client that looked would not have matched it.

**The one figure a map deserves is marker density.** Twenty buckets between the
first and last coordinate, computed in the API over *every* locus rather than
the page of 500 — a histogram built from the capped list would describe the cap
rather than the map. On IBM2 2005 Neighbors 1 the densest 61 cM interval holds
636 loci.

The not-found page's suggestion arms are different again, because a
name-contains arm would be pointless here: `mapResolveId()` already ends with
`m.name ILIKE '%term%'`, so anything a contains-search could find has already
resolved to a record. What is left is the question people actually arrive with:

| Arm | Cost | Answers |
| --- | --- | --- |
| Locus | 9 ms | The term read as a locus name, and every map it is placed on with its coordinate |
| Largest maps | 130 ms | Somewhere to start when the term matches nothing |

`/data_center/map/bz1` is the case: bz1 is a locus, not a map, and the page
says so and lists the eight maps it sits on.

### Images, and where the reference card lives

Two corrections on 2026-09-01, both about a component appearing in more than
one shape:

- **The image gallery is the stock record page's**, ported into
  `js/mgdb-record.js` and `css/mgdb-record.css` so every record page shows
  images the same way: a card per image with the picture, a category chip, a
  linked subject, the caption, and Zoom / Record / Open file / Copy URL, over
  a lightbox. It is a `collection()` like any other, so the gallery is its
  grid view and the same block still offers a table of the rows, a filter, a
  page size and a TSV. The default page size is **16**, a four-by-four grid,
  which is what the group asked for; `collection()` now adds a non-standard
  size to its own select, or the control would read 10 while the block paged
  at 16.
- **The cited-paper card moved from `css/mgdb-hub.css` to
  `css/mgdb-modern.css`.** It is a shared component, not a hub one: the hubs,
  the record pages and the stock record page all render one, and only the
  first of those loads the hub sheet. Every page that loads the hub sheet
  loads the base sheet before it, so nothing changed for the hubs.

The stock record page was rendering its references as
`.reference-result-card` — the literature **search result** card — which is
why they did not look like the references anywhere else. It now emits the
standard `.mgdb-ref` markup, keeps its own pagination, and lets
`mgdb-modern.js` bind Copy citation and Copy DOI like every other page.

Three renderers now emit that card and have to agree:
`include/references_lib.php` server-side, `js/mgdb-record.js` from an API
payload, and `js/mgdb-stock-record.js` from its own.

### The variation record page

`/data_center/variation?id=` moved onto the same shell on 2026-09-01. It had
its own stylesheet, its own two-tone header with an emblem, its own collection
helper and its own palette; all of that is gone. Sections in the hub order:
Overview, Phenotypes, Stocks, Related records, Annotations, Images,
References, Metrics, Related resources, API. Images are the shared
sixteen-then-See-all grid with a lightbox.

Its not-found page has **two** suggestion arms rather than the gene product's
three, and the missing one is deliberate. `mgdb.variation` holds **1.7 million
rows**, so a contains-search costs 1,220 ms on the name alone and more on the
synonym table. What it offers instead:

| Arm | Cost | Answers |
| --- | --- | --- |
| Allele series | 10 ms | The term read as a gene symbol, and that locus's curated alleles, with a link to the whole series in the hub |
| Name prefix | 160 ms | Variations whose name begins with the term, in the three spellings the maize convention uses |

A trailing wildcard is the whole difference: `LIKE 'bz1-m%'` is 160 ms where
`ILIKE '%bz1-m%'` is 1,220 ms. Anything broader belongs to the hub's own
two-tier search, which is built for it and says when a result set is a bounded
sample.

Most classical symbols never reach this page, because the symbol is also a
variation name or a synonym: `wx1`, `sh2`, `y1`, `lg1`, `a1` and `tb1` all
resolve to a record. `ra1` is one that does not, and it shows the allele
series.

### The not-found page, and why 404

`/data_center/gene_product?id=adh1` answers **404 Not Found**. The legacy
route answered 200 with a "not found" template, which is wrong twice over: it
tells a crawler the page exists and should be indexed, and it tells a client's
error handling nothing. 404 is the correct code — the resource is absent, and
nothing suggests it ever existed at that URL, which is what 410 Gone would
claim. The page also carries `<meta name="robots" content="noindex">`.

The page is not an apology, it is a lookup. `geneProductSuggestions()` runs
three arms in 32 ms:

| Arm | Answers |
| --- | --- |
| Locus | The term read as a gene symbol, and the products that locus encodes |
| EC number | The term read as an EC number |
| Contains | Products whose name or a synonym contains the term |

`adh1` is the case that matters and the reason people land here: it is a
locus, not a gene product, and the page says so and links to alcohol
dehydrogenase. `1.1.1.1` finds the same product through the EC arm. A term
matching nothing renders no Suggestions section at all rather than an empty
one.

Two query notes. The locus arm matches three spellings exactly rather than
lowering the column, because `idx_locus_name` is a plain btree: `LOWER(name)
= ?` costs 128 ms where this costs 6 ms. And **`SELECT DISTINCT` with an
`ORDER BY` expression that is not in the select list is a Postgres error**,
which this codebase's database layer turns into an empty result rather than a
failure — the suggestions simply did not appear, on a page that otherwise
rendered perfectly. The ordering now sits outside the DISTINCT. That is the
same silent-failure mode the API's `count_mismatch` warning exists to catch,
and this page has no such check.

### Three things the legacy page got wrong, not ported

- **Ontology terms were looked up under the wrong table name.** The annotations
  call asked `getOBOTerms()` for `table_name = 'locus'`, copied from the locus
  page, so it could never match a gene product. The API asks under
  `gene_product`. No validated terms exist under that name either — the section
  is empty for every record, now for the right reason.
- **Relations were shown in one direction.** `mgdb.relation` stores a row once,
  so "Subunit of" appeared on one product and nothing on its partner. The API
  returns both directions and marks the inverse rows.
- **Related loci carried no gene model.** The B73 v5 model, and the count of
  earlier models, now sit beside each locus, from one `chado.gene_model` query
  on `locus_id` (indexed as `gene_model_i1`). The bin comes from
  `gene_prod_links`, a second table pairing the same product and locus.

### Things worth knowing about the data

- 80 of 2,474 gene products sit at curation levels other than 0 and do not
  resolve, matching the legacy `check_id()`.
- A few names are shared by two records; the lower id wins so a name resolves
  the same way every time.
- `perm_tables.id_ontology` has no index on `id`. The count subquery pairs it
  with `table_name`, which is indexed, and costs under 3 ms; on its own the
  same lookup is 600 ms.
- Ordering a `UNION` by `LOWER(name)` is a Postgres error, and the database
  layer returns an empty result rather than raising. The API's own
  `count_mismatch` warning caught it before the page did.
- A viewer's own pending community annotations were per-login; the API is
  public and carries only approved ones, as the stock record does.

Originals are archived under `legacy/gene-product-record/`.

## The gene record page

`/gene_center/gene/{id}` is the most visited page on the site and was the least
modern: nothing was server-rendered, so it had no `<h1>` and could not be
indexed; section visibility was stored in four namespaces of per-section
cookies; and the body was assembled from **nineteen** Ajax requests sharded
across `ajax0..6.maizegdb.org` to get around the browser's per-host connection
limit.

Those nineteen requests cost **over 1,700 database queries**. Measured for
`Zm00001eb067740`: overview 28, annotations 21, the locus tab about 22, and the
pan-genome tab **300 to 500** on its own — it called `queryPanGeneMembers()`
from scratch four times, and inside it two queries per name-only member plus two
per member for orthologs. `hasPanGenomeData()`, whose entire job was deciding
whether to print a tab label, ran the full member expansion: 223 queries before
a byte of content rendered.

The replacement is one server-rendered identity block plus one call to
`/api/v1/records/gene/{id}`, which answers in **23 queries**.

### One page, not four tabs

The legacy page had four tab groups — Gene Model, Sequence, Pan-gene, and Gene
(locus) — and the last of these gave the classical gene its own parallel
Overview, Annotations and External Links sections. `Zm00001eb067740` and `lg1`
are the same gene, and nobody thinks of them as two tabs. They are now one
record with a sticky section nav, and the locus sections simply do not exist for
the ~49% of B73 v5 gene models that have no classical locus.

### Identifier resolution

`include/gene_record_lib.php` replaces `check_id()` in
`controllers/gene_center/gene_functions.php`. The old function ran up to four
sequential SQL branches, two of them full parallel sequential scans of a 646 MB
materialized view:

| Identifier | Legacy | Now |
| --- | --- | --- |
| gene model name | index scan, 0.6 ms | one query, all arms |
| classical gene symbol | **seq scan, 270 ms, 91,162 buffers** | 0.23 ms, 13 buffers |
| transcript name | seq scan, 270 ms — and it never ran | index scan |

The symbol path is the one that mattered: every classical-gene URL paid 270 ms
and two extra worker backends. `chado.gene_model.locus_name` has no index and
carries trailing whitespace; going through `mgdb.locus`, which is indexed on
`name` and `full_name`, and reaching the gene model by the indexed `locus_id`
returns the same rows 1,170× faster. No new index is required.

Four defects in `check_id()` are not carried over: the transcript branch and the
`ext_db_key` branch both call `get_all_rows()` on a **stale statement handle**
and never execute their own SQL; `$ret['EXTRA_LOCI']` is assigned while `$ret`
is still `false`, which is fatal on PHP 8; and a `while` loop increments past
the end of its array before the bound is tested. Every branch also interpolated
the URL path straight into SQL — `validate_string()` in `include/db-api.php` is
literally `return $input;`. Everything in the new resolver is bound.

A withdrawn gene model now answers `410` with its replacement rather than `404`.

### What the data cannot support, stated rather than hidden

Three things are absent from the database and are said so on the page and in the
API, rather than left blank or faked:

- **Strand.** `chado.featureloc.strand` is NULL for all 4,701,925 rows and
  `chado.transcript.strand` is empty for every B73 v5 transcript. The record
  carries `overview.strand: null` beside a `strand_note`, and the page renders
  "not recorded".
- **Exon and UTR structure.** There are no `exon`, `CDS`, `five_prime_UTR` or
  `three_prime_UTR` features anywhere in `chado.feature`, for any organism, so
  no transcript diagram can be drawn from this database. The page says so and
  links to the genome browser.
- **Protein length.** `chado.feature.seqlen` is NULL for all 1,410,521
  polypeptide features. The legacy page filled the gap with
  `transcript_end - transcript_start` labelled "Canonical Length" — that is the
  **genomic span**, and it showed 4,010 for a gene whose protein is 399
  residues. The new page never publishes a "length" without saying which one:
  the transcript column is `span_bp`, and the true protein length is read from
  the sequence service.

That last call costs about 470 ms — more than the rest of the record together —
so it is opt-in with `?protein_length=1` and the page fetches it in a second,
parallel request. The page is interactive in about 130 ms and the protein domain
track fills in when the length arrives. Without it the domains are listed as a
table: scaling the track to the last domain's end would imply the protein stops
there, which for `lg1` would show a domain ending at residue 258 as if it were
the C-terminus.

### Things worth knowing about the data

- **`perm_tables.id_ontology.id` has no index** (11.7 M rows). It is only usable
  when paired with `table_name`; filtering on `id` alone is a 786 ms scan.
- **`mgdb.locus_coordinates.map` is `numeric` while `mgdb.id_num.id` and
  `mgdb.map.id` are `bigint`.** Joining them bare makes Postgres cast the
  *indexed* side, which discards both primary keys: 382 ms and 66,207 buffers to
  return 13 rows. Casting the numeric column instead gives 8 ms. The legacy
  query has the same shape and the same cost.
- **An insertion is recorded once per (transcript, gene structure) it touches**,
  so one event spanning an exon and an intron yields several
  `marker_gene_model` rows — `mu1013469` has eight. The API groups on the
  insertion locus so the row count matches `meta.counts.insertions`.
- `chado.gene_model.gene_name` is **not unique**: 1,878,909 rows for 1,623,561
  distinct names, because the matview fans out on (version × locus).
  `GRMZM2G078954` has 12 rows. Resolution dedupes and reports the rest in
  `meta.other_matches`.

### Bugs in the legacy page that were not ported

`gene_model_snps_traits.php:113` computes the transcript column from `$rec`
*before* the loop that defines `$rec`, so every transcript cell in the SNP table
renders empty. All four B73-relative lists in `gene_pangenome_sections.bau`
anchor their links at the current gene rather than the related one.
`annotation_lib.php:328` is missing a comma between two `IN`-list literals,
which silently drops **Plant Ontology (59 keys) and PATO (33 keys)**
cross-references. `getOfficialGeneModelID()` branches on `strcmp(...) != 0`,
which is true whenever the strings *differ*. `isRepresentativeGeneModel()` reads
a misspelled key and is always false. `gmMerged()` ignores its argument and
hard-codes a `LIKE` against one gene.

### Verification

Cloudflare's bot challenge sits in front of the development instance, so a
browser cannot load the page for checking. The render path is exercised instead
by running `js/mgdb-gene-record.js` against real API payloads under
JavaScriptCore with a small DOM shim — all eleven sections, the empty-record and
locus-less variants, the request-failure and abort paths, and the domain-track
geometry.

## Research projects

`/projects` is a third kind of page, alongside the data centres and the tools.
A data centre searches the production database and returns whatever is in it
today; a tool takes input and computes an answer; a project is a body of work
with a beginning and an end — a fixed result with its figures, tables, methods
and downloads, which does not change until the work is repeated. None of it
touches the database.

**It widened on 2026-09-05** from MaizeGDB's own analyses to the whole projects
directory, on Carson's call: "one /projects directory". The site had four
things called projects — this section, the six root-level `*_project` pages,
`/doc` with `/documentation/*`, and a third under-construction placeholder at
`/maizeprojects` — and the two legacy listings between them pointed at two dead
hosts and a PHP fatal error. See **The two listings /projects replaced** below.

Three categories, which is what `category` names in the registry:

| Category | What it is | Count | Pages served here |
| --- | --- | --- | --- |
| `analysis` | Run and published at MaizeGDB | 3 | 2 |
| `genome` | A sequencing and assembly effort whose genomes the site serves | 7 | 0 |
| `resource` | A mutant collection, map or protocol set hosted or documented here | 5 | 3 |

"Pages served here" is the number with a real `/projects/<slug>` page rather
than a registry entry pointing elsewhere. It went from 2 to 5 on 2026-09-05
when the three legacy resource pages were ported onto the shell; see
**Porting the three resource pages**.

**`maizegdb => true` marks a project MaizeGDB itself runs**, and those cards
carry the kernels from the site mark — `/images/kernels.png`, the same file the
homepage hero uses — in the upper right. It is an explicit flag rather than a
test on `lead`, so a project co-led with someone else can still carry it.

```
include/projects_lib.php                the registry: every project, the categories, the topics
controllers/projects.php                routes /projects, /projects/<slug>, and the 404
controllers/projects/<slug>.php         one project's page controller
templates/static/mgdb_project_<slug>.bau  its body
data/projects/<slug>/                   its payload, downloads and figures
```

The registry is the single source of truth: the listing cards, the routing, the
filter chips and the breadcrumbs all derive from it, so a project cannot appear
in one and be missing from another. An unrecognized slug is a real `404` rather
than the listing page with a message.

**Optional registry fields are omitted rather than faked.** `lead` is set only
where a source page states it — an invented consortium name is a failure this
part of the site has had once already, on a citation. `updated` is set only for
work published here, because a project run elsewhere has no date we can stand
behind and a card that printed the day it was served would claim the data had
just changed. `card_facts` values come from the project's own page or from a
query, never from memory: the UniformMu numbers are read from
`data/uniformmu/uniformmu_summary.json`, the same file `/uniformmu` reads, so
the card and the page cannot disagree.

There is deliberately **no `projects/` directory in the web root**. A real
directory at that path would stop `.htaccess` rewriting `/projects/...` to
`controller.php` at all — the same trap documented for `/api` above. Project
data therefore lives under `data/projects/<slug>/`, where Apache serves it
directly. For the same reason a slug must not contain the characters `js`: the
sitewide rewrite skips any URI matching the unanchored pattern `(.js)`.

**Adding a project** is an entry in `mgdb_projects()`, a controller, a body
template, and its data files in the manifest.

### The listing page, on the shell

`/projects` carries `mgdb-hub-page` and loads `css/mgdb-hub.css` before its own
sheet, so it has the pale ground, the white section cards with their coloured
top edges, the sticky tab bar and the green Related resources panel that the
data hubs have. Three sections — **Search**, **About**, **Related resources** —
and the tab labels are the section headings verbatim.

No Metrics and no References. There is nothing on this page worth counting that
the reader cannot see, and a project's references belong on the project's own
page, next to the work they support. The same call was taken for
`/nomenclature`.

**The filter chips run on the category axis**, not on topics. They were topic
chips until the section grew from three projects to fifteen, at which point the
useful first cut became what kind of project it is rather than what it is
about. Topics are still the pills on every card and still part of the text the
search box matches, so typing `immunity`, `cytogenetics` or `Dooner` finds a
project whose card copy never uses the word. Each chip carries its count, and a
category with no projects in it emits no chip. The URL key moved with the axis:
`?topic=` became `?category=`.

**Three columns, not four.** The shared grid is `minmax(280px, 1fr)`, which at
the page's 1200px of content fits four 288px tracks. That was invisible while
the section held three projects — `auto-fit` collapses the empty track, so
three cards took three 389px columns, which is the layout that was reviewed.
Fifteen cards fill the fourth, and at 288px these titles, which are sentences,
wrapped to five lines. The listing sets `minmax(320px, 1fr)`, which holds three
columns from 992px of content up to 1328px.

The bar is one 57px row at every width measured from 1280 down to 420, and
below that `css/mgdb-modern.css` makes it a scrolling rail rather than letting
it wrap, so it is still one row at 375. The section offset is therefore a flat
`65px`; the shell's step to `113px` under 1170px is for bars that wrap and is
56px too much here.

The tab bar's behaviour is `MGDB.sectionTabs()` from `js/mgdb-modern.js`, not a
copy. That helper is opt-in rather than automatic — a page that ships the bar
without calling it gets one that scrolls correctly and then names the wrong
section for the rest of the visit, which is how `/nomenclature` shipped once
already.

#### Making the row of cards one shape

The card grid gives the cards equal width and equal height, and their inner
bands still did not line up: the facts strip started 356, 316 and 329px down
the three cards and the updated line at 474, 488 and 501.

The cause is in the shared shell. `css/mgdb-hub.css` grows *every* paragraph in
a card:

```css
.mgdb-hub-page .mgdb-card-grid > .mgdb-card > p { flex: 1 1 auto; }
```

On a card with one paragraph that is right — the summary takes the slack and
everything under it sits on the floor of the card. These cards have two, the
summary and the updated line, so the slack was split between them by their
content heights and every band below the summary landed somewhere different in
each card. `margin-top: auto` cannot fix it: with both paragraphs growing there
is no free space left for an auto margin to take.

Four changes, all scoped to this page, put every band of every card on the same
line as its neighbours':

| Change | Why |
| --- | --- |
| The updated line stops growing | Only the summary takes the slack, so everything below the summary sits on the floor of the card |
| The topic pills move above the summary | Whatever sits above the growing paragraph can be one row on one card and two on the next without moving anything that follows |
| Three lines reserved for the title, two rows for the pills | Both sit above the summary, and reserving the taller case is what aligns the summary's own first line |
| Two lines reserved for each fact label | `ligand types` fits on one line and `gene assignments` does not; an 80px strip beside a 102px one puts the two footers at different heights |
| Two lines reserved for the meta line | Added 2026-09-05. Measured across the fifteen cards it is 22px or 45px and never more; a one-line meta lifts the facts strip a whole line above its neighbours', and reserving the taller case also pulls the one card with no facts strip at all back into line |

Below 720px the grid is a single column, the cards no longer sit beside each
other, and every one of those reservations is released. **The release for the
meta line has to be written after its own declaration**, not with the other
releases higher up the file: a media query carries no extra specificity, so the
later rule wins whatever the viewport is. Measured at 375px with it in the
earlier block and the reserve was still applying.

Measured across all fifteen cards at 1280px, per grid row, as offsets from the
top of the card: facts strip 435/435/435, 358/358/358, 358/358/358,
358/358/358, 384/384/— and meta line 528/528/528, 451/451/451, 451/451/451,
451/451/451, 477/477/477. The last card in the last row is Panzea, which has no
numbers and so no facts strip, and its meta line still lands with its
neighbours'.

#### The kernels mark

`maizegdb => true` puts `/images/kernels.png` in the card's upper right at
46px, which is the mark at about an eighth of its natural size. It goes
**inside the `<h3>`, floated right**, so the title wraps around it on the first
line and runs the full width of the card underneath.

The first version positioned it absolutely and padded the heading 58px to clear
it. That reserve applies to every line, not just the one beside the mark: it
cost each of the three marked cards three extra lines of title, and their pill
rows started 69px lower than their unmarked neighbours' in the same grid row —
the exact fault the four changes above exist to prevent. The heading's
`min-height` is three lines and the mark is 27px tall, so a float can never
escape the bottom of the heading.

It is decorative — empty `alt`, `aria-hidden` — because the card's meta line
already opens `MaizeGDB analysis · led by MaizeGDB` in words. Under
`forced-colors` it is hidden, where a decorative PNG can render as a grey
block.

The shell rule itself is left alone. It is shared by every hub with a card grid
and narrowing it to `> p:first-of-type` is a separate change with its own
verification.

### Listing a project that lives somewhere else

A registry entry may carry an explicit `url`. That marks it as **hosted
elsewhere**: it appears on the listing in the same format as the rest, links to
where the page actually is, and `/projects/<slug>` `301`s there rather than
404ing a slug the registry does recognise or including a controller that does
not exist. `mgdb_project()` returns such an entry early, without the derived
`controller`, `template` and `data_url` paths.

Ten of the fifteen entries are hosted elsewhere — twelve before the three
resource pages were ported — which changed what the card's meta line says. It used to name the internal path it redirects to; with
twelve of them that is twelve repetitions of a URL the card title already links
to, so the path is gone. **An offsite `url` is different**: `mgdb_project()`
sets `external` when the url begins `http`, and such a card opens in a new tab,
carries the `↗` cue, and names the host — `Community resource · hosted at
panzea.org`. That is a promise about where the click lands, so it is still
made. Panzea is the only one.

The first of these is **AlphaFill**. `/data_center/alphafill` is a searchable
page over its own corpus as well as a finished analysis, so it is served from
the data centre; it is the same kind of work as the projects around it — one
pipeline, one fixed result set, with its figures, its methods and its downloads
on the page — so it is listed here too. 624,456 ligand transplants across
16,933 B73 RefGen_v5 genes and 1,969 ligand types. Listing it is a registry
entry and nothing else: no controller, no template, no data directory, and no
change to the AlphaFill page.

It also brought the `methods` topic into use, so **Methods and benchmarking**
is now a filter chip. Chips are built from the topics the registry actually
uses, so that happened by itself.

### The two listings `/projects` replaced

`/doc` and `/documentation/projects` were both "project documentation" pages,
both in the legacy chrome, and the modern megamenu linked one while the legacy
megamenu linked the other. Both now `301` to `/projects`.

`/doc` — "MaizeGDB Project Documentation" — carried ten links. As of
2026-09-05:

| Link | State |
| --- | --- |
| Maize Gene Discovery Project → `cur.maizegdb.org` | **502**. `archive.maizegdb.org` does not resolve either, from inside the network or outside |
| Maize Mapping Project → `cur.maizegdb.org` | **502** |
| Other Maize Projects → `/popcorn/…/project_search.php` | **PHP fatal error**, `Cannot redeclare getSystemInfo()` |
| Project Documentation & Protocols → `/projects` | **Wrong target.** The redesign gave `/projects` a meaning and nobody updated this |
| Linking to MaizeGDB → `/api` | **Mislabelled.** `/api` returns a JSON service index |
| Cytogenetic Map of Maize → `/CMMprotocols` | Fine. Now a card |
| UniformMu → `/uniformmu` | Fine. Now a card |
| How to Cite MaizeGDB → `/cite` | Fine. On the site map |
| Maize Genetics Nomenclature → `/nomenclature` | Fine. On the site map |
| MaizeGDB Schema → `/docs/MaizeGDBSchema.pdf` | Fine, and this page was its **only** route. Moved to the site map's Documentation and help |

The name was the other half of the problem: "documentation" reads as
documentation *of the website*, and the page existed to give maize research
projects with no page of their own somewhere to put information.

`/documentation/projects` was the better of the two — eight entries, better
copy — and six of its eight are cards now. The two that are not are the Maize
Gene Discovery Project (already commented out in its own template) and the
Maize Mapping Project, whose controller `302`s to `curation.maizegdb.org`,
which `502`s. **A directory whose entries 502 is worse than one that does not
list them.**

Their placeholder pages are untouched and still serve: `/mgdp` and
`/maizemap` under `controllers/documentation/`, and `/maizeprojects` under
`controllers/community/`, all three of which render an "under construction"
image. Retiring them is separate work — see `## Retiring pages`.

Three inbound links were repointed at the same time:

| File | Was | Now |
| --- | --- | --- |
| `templates/home/megamenu_modern/about.bau` | About → Learn → **Documentation** → `/doc` | **Research projects** → `/projects` |
| `templates/home/megamenu/about.bau` | Helpful Links → **Project documentation** → `/doc` | **Research projects** → `/projects` |
| `templates/home/megamenu/community.bau` | Resources → **Project documentation** → `/documentation/projects` | **Research projects** → `/projects` |

`templates/static/ssr_protocols-content.bau` carried a fourth, in the trail
across the top of `/ssr_protocols`: "Project Documentation & Protocols: Maize
Mapping Project: SSR Protocols", where the first link was `/doc` and the second
was `archive.maizegdb.org`. Both were broken. The Maize Mapping Project is now
named without a link, because there is nowhere working to send anyone.

On the site map the entry moved section as well as name: `/doc` was under
**Documentation and help**, which is where documentation of the site itself
belongs; **Research projects** is under **Community and people**. Counts went
11 → 12 and 13 → 13 (one out, the schema PDF in). Edit
`tools/sitemap_data.py` and regenerate — and note that regenerating reverts
hand edits made straight to the `.bau`, which is how the `/handyref` entry,
renamed to "Genetic maps" on the server on 2026-09-04, came back as "Handy
reference" the first time. It is in the model now.

### Porting the three resource pages

Three of the five community resources were still in the legacy chrome when the
section widened. They came onto the shell on 2026-09-05 as ordinary
`/projects/<slug>` pages: a registry entry, a controller, a body template, and
for one of them a data file.

| Now | Was, both of which 301 |
|---|---|
| `/projects/cytogenetic_map` | `/CMMprotocols`, `/documentation/CMMprotocols` |
| `/projects/dooner_du_acds` | `/dooner_du_acds_insertions`, `/documentation/dooner_du_acds_insertions` |
| `/projects/fowler_insertion_validation` | `/fowler_insertion_validation`, `/documentation/fowler_insertion_validation` |

**One redirect file covers two URLs.** `controller.php` reaches
`controllers/documentation/<page>.php` for `/documentation/<page>`, and
`redirect.php` reaches *the same file* for the root-level `/<page>`, because it
falls through to `controllers/documentation/` when nothing else matches. Both
forms were live for all three pages, and one `header('Location: …', true, 301)`
retires both. The legacy Bauplan partials are archived under
`legacy/cytogenetic-map/`, `legacy/dooner-du-acds/` and
`legacy/fowler-insertion-validation/`.

They share `css/mgdb-project-resources.css` and `js/mgdb-project-resources.js`,
and carry `.mgdb-resource-page` on `<main>` alongside their own page class. The
tab bar is the shell's, not the listing's tinted one — five short jump links,
one 57px row at every width from 1280 down to 375, so the section offset is a
flat 65px and the shell's step to 113px under 1170px is overridden.

#### 47 primer sequences were being shown short

`/documentation/fowler_insertion_validation` carried its data as two tables that
were an Excel "save as HTML" export. Excel had split 47 cells so their last one
to three characters sat inside a hidden span:

```html
<td>GCAGCTGCAGTTGTACACAGTACA<span style='display:none'>GAG</span></td>
```

The browser rendered `GCAGCTGCAGTTGTACACAGTACA` and hid `GAG`. Text inside a
`display:none` element is not copied either, so **anyone who read a PCR primer
off that page, or copied one to order oligos, got a sequence up to three bases
short of the real one.** 46 of the 47 are in the two primer columns; the 47th
made an expression class read `vegetative_cell_hig`.

`tools/fowler_lines.py` parses the archived partials, keeps the hidden text as
part of the cell value, and writes both
`data/projects/fowler_insertion_validation/lines.json` and a TSV for download.
It asserts what the page's prose has always claimed — 64 verified, 19
unverified, 83 total, 10 with a significant male transmission defect, every
primer a run of ACGT — and exits non-zero if any of that stops holding, so a
re-run cannot quietly produce a different table. Nothing on the page is typed
from the rendered tables, which is the only way the bug could have survived the
port.

The two tables became one, with a Status column and a chip to filter it: Table A
was the 64 verified and Table B the 19 unverified, side by side with identical
columns, and the question a reader arrives with is about an allele rather than
about which table it is in.

#### What else the port changed

- **Dooner and Du: the two insertion counts are different numbers.** The
  published collection is 14,184 insertions; MaizeGDB holds 7,510 of them as
  records, with 18,428 alignments. The legacy page printed only 14,184 and then
  said the flanking sequence and genome position "can be found in this table" —
  a table that page never had. Both numbers are on the page now, each labelled,
  and the dangling reference is replaced by a Find these insertions section
  pointing at the Insertion Data Hub, the browsers and the Stock Center. The
  holdings figure is read from `data/insertion/insertion_summary.json`, the file
  `/insertion` reads, so the two cannot disagree.
- **The stock count is a range, not a prefix match.** `mgdb.stock` has a btree
  on `name` in the database collation, so a left-anchored `LIKE` cannot use it —
  that needs `text_pattern_ops`. Measured: `LIKE 'tdsg%'` seq scan 13.9 ms,
  `ILIKE 'tdsg%'` seq scan 58.4 ms, `name >= 'tdsg' AND name < 'tdsh'`
  **index-only scan 7.2 ms with zero heap fetches**. All three return the same
  13,145 rows.
- **Three dead links were not carried over.** `acdsinsertions.org`, the Dooner
  and Du project site, did not respond on 2026-09-05 and the page says so rather
  than sending readers to it; the BACman utilities at `chibba.agtec.uga.edu`
  are named in the published FISH method and are gone, so step 7 keeps the step
  and drops the link.
- **One citation was superseded.** The legacy Fowler page cited the 2020 bioRxiv
  preprint of the ear phenotyping paper. It was published in *The Plant Journal*
  in 2021 as `10.1111/tpj.15166`, which is what is cited now. All eight
  citations across the three pages were checked at Crossref and given abstracts
  from Europe PMC; the Cytogenetic Map page had carried no DOI at all.
- **A typo on `/gene_center/gene`.** The link to this project read "Abut the
  Dooner & Du Ac/Ds-GFP project".

#### In the site map

All three are listed under **Documentation and help**, beside SSR protocols and
Coordinate definitions, because that is what they are: the methods and naming
references for major maize projects, which is the material `/doc` used to
gather. The Cytogenetic Map is **cross-listed** in **Genomic data** next to FISH
karyotypes as well, since that is where someone hunting cytogenetics looks;
duplicates across sections are legitimate in `tools/gen_sitemap.py` and only
within one section are a mistake.

Adding them turned up a gap in the same list: **`/HiLo_project` and
`/amaizing_project` were missing from Genomic data** while their four siblings
— NAM, PanAnd, European flints and CAAS — were listed. Both are in now. Section
counts went 13 → 16 for Documentation and help and 14 → 17 for Genomic data.

### The first one: `/projects/interpro_domain_atlas`

A uniform InterProScan 5.78 re-scan of 46 Andropogoneae genomes and 6 outgroup
species. The controller reads one pipeline output —
`data/projects/interpro_domain_atlas/domain_center_data.json` — and renders the
metrics, the 36-class variance ranking, the 97-row genome × class matrix, the
immunity tables, the cross-species comparison and the download list server-side.
The browser fetches the same file for five Plotly figures. Every value in a
chart also appears in a table in the same section, so a failed fetch degrades
the page rather than emptying it.

Two things about this dataset are load-bearing and are stated on every panel:

- **Inclusive vs exclusive.** A gene is counted under every functional class
  whose domains it carries, so those counts overlap and must never be summed.
  The immunity classifier assigns each gene exactly one class by architecture
  precedence, so those counts do sum. B73 has 144 genes in the inclusive
  `Immunity: NLR (NBS-LRR)` class and 122 in the exclusive `NLR` class. An
  implementation that returns the same number for both has merged the measures.
- **Two annotation arms.** Curated and Helixer gene models are separate
  measurements of the same genomes. A difference between them is a statement
  about annotation method, not genome content, so they are never pooled,
  averaged, or silently defaulted between.

The matrix's cell shading is scaled per row group rather than on a fixed clip.
Within the Andropogoneae half the cells sit within 3% of their class median,
while against the outgroups the ratios are an order of magnitude wider; one
scale for both leaves one of them blank. The page states the resulting fold
change next to the legend.

`docs/DATA_SEMANTICS.md` and `docs/METHODS.md` ship with the downloads.

### The second one: `/projects/pathway_explorer`

E2P2 metabolic pathway annotation run identically on all 26 NAM founder genomes,
beside CornCyc 8.0 on B73 RefGen_v4. 694 pathways, 2,696 reaction steps,
259,709 gene-to-reaction assignments over 121,581 gene models. The page
describes the dataset, draws five figures over it, and carries the whole
explorer: browse with a class tree, a pathway detail panel, a 590 x 27
completeness heatmap, the reaction-gap list, a gene-to-pathway lookup and a
gene-list enrichment test.

```
controllers/projects/pathway_explorer.php            the page. Runs zero SQL
templates/static/mgdb_project_pathway_explorer.bau   its body
css/mgdb-project-pathway-explorer.css                its sheet, on the hub shell
js/mgdb-project-pathway-explorer.js                  the explorer
search/pathway_explorer/pathway_explorer_api.php     the gene lookup endpoint
search/pathway_explorer/pathway_explorer_lib.php     its reads. No SQL either
data/projects/pathway_explorer/                      the payload, ~53 MB
tools/pathway_explorer_index.py                      what writes it
```

**This is the first project page on the Data Hub shell.** It carries
`mgdb-hub-page` and loads `css/mgdb-hub.css` before its own sheet, so it gets
the pale ground, the white section cards, the coloured section top edges, the
table zebra and the sticky tab bar that every hub has. `/projects` and
`/projects/interpro_domain_atlas` do not yet, so the section is currently two
looks; bringing the older two across is a small change and has not been made.

#### How it is served

Everything except one action is a static file. `index.json` (694 pathway rows
plus the class tree), `matrix.json`, `gaps.json`, the 694 per-pathway records
and the 27 enrichment backgrounds are read by the browser straight off disk,
because Apache serving a file that Cloudflare compresses at the edge beats
anything PHP could do in front of it — and a static read cannot fail in a new
way. Measured: the page renders in 83 ms with no database contact at all.

The exception is the gene lookup. Served statically, resolving a pasted list
would be one 3 KB shard fetch per gene. `pathway_explorer_api.php` reads those
shards server-side instead: a 400-gene list scattered across the corpus is 401
local file reads in **25-37 ms**, one request, 55 KB. Gene models shard on the
sha1 of the lowercased ID, 4,096 ways — about 30 genes and 3 KB a shard, so a
single lookup decodes 3 KB to read 200 bytes. The depth is recorded in the
manifest rather than assumed.

SQLite was measured as an alternative (21 MB, one file, indexed) and not taken:
the shard layout is what `data/alphafill/` and `data/protein_structure/`
already do, it needs no new storage engine in the tree, and the heaviest read —
36 MB of pathway detail — never touches PHP at all.

#### Six ways to state a number wrong

Every one of these was found by recomputing the pipeline's own summaries from
its raw records, which is why `tools/pathway_explorer_index.py` recomputes
rather than copies, and writes anything that still disagrees into
`manifest.json` under `disagreements`.

- **CornCyc is a reference track, not one of the 26 genomes.** It is B73 v4,
  curated, from a different pipeline. Every per-genome statistic on the page is
  over the 26 founders and the track is shown beside them, never inside them.
  Note that `is_reference` is 1 for **two** tracks, CornCyc *and* B73, so
  filtering on it gives 25 founders.
- **"Absent" is two populations.** 17 pathways were tested by E2P2 and found in
  no genome; a further 104 are CornCyc-only and were never tested. The source
  labels both `absent`, so the naive count is 121. The page's absent filter
  returns 17 and its CornCyc-only filter returns the 104.
- **A step is not always a reaction.** 140 of the 2,836 step entries are a
  superpathway's references to its component pathways: no EC, no evidence, no
  genes. Counting them adds 140 gaps that can never be closed. 32 pathways
  consist of nothing else, so they are absent by construction.
- **Three numbers are called "reactions".** 2,089 distinct reactions; 2,696
  reaction steps, because a reaction is a step of more than one pathway; and
  the source's own 2,203, which is the 2,089 plus 114 sub-pathway references.
- **An assignment can be counted two ways.** 259,709 gene-to-reaction
  assignments, or 475,716 protein-model rows — 1.8x apart. The per-track
  protein-model count is not comparable across tracks at all: two founders
  carry about a quarter fewer without carrying fewer genes, which is why the
  per-genome figure plots genes and says so.
- **The evidence summary drops a bucket.** The pipeline's seven evidence codes
  sum to 423,799 of 475,716 rows, because assignments with no code have no
  entry. Recounted at gene level the eight buckets sum exactly, and the page's
  shares are taken over that.

The pipeline's own per-track record also mixes populations: CornCyc's pathway
count is over all 694 while its completeness is over the 590, so reading the
pair says it completes 269 of 596 when the within-scope figure is 269 of 534.
The build tool recomputes all three over the 590 so the row can be read as a
row. The 26 founders are unaffected, which is exactly why it stays invisible.

Four more the page itself got wrong first, found by an adversarial review pass
and fixed. Each is the same shape -- a field whose name is a fair description of
a different quantity:

- **`gap_class = 'complete'` means every founder genome, not any of them.** The
  page's metric card said "1,371 steps have a gene in at least one genome" and
  its gap table defined Complete as "at least one genome assigns a gene". Under
  that wording Complete and Variable overlap and a reader subtracting 1,371 from
  2,696 concludes 1,325 steps have no gene anywhere, when 106 of them do. The
  count filled by at least one founder is **1,477**, and 2,038 counting CornCyc.
- **`n_unique_steps` is scoped to the 26 founders**, so the CornCyc row of a
  per-track table is structurally 0 -- printed under "Steps only this track
  fills" that reads as "CornCyc contributes nothing unique", when over all 27
  tracks CornCyc is the only one with a gene at **561** of the 2,696 steps. The
  build tool now computes both scopes and the column is the 27-track one.
- **`gaps.json`'s `cc` is in_corncyc8 for the REACTION**, not "CornCyc assigns a
  gene to this step", and is 0 on 17 of the 578 rows classed "lost from
  CornCyc" -- a column built on it contradicts the class in the cell beside it.
  The build tool now writes the step's actual CornCyc gene count.
- **259,709 assignments are (gene, pathway, reaction) triples**, so a gene on
  one reaction in two pathways counts twice. Distinct (gene, reaction) pairs are
  **180,689**. And at the *gene* level CornCyc's 9,169 records sit inside the
  founders' 9,169-10,005, not at half of them -- the 2x gap is protein-model
  rows only, because CornCyc's records are one protein each.

Two rendering faults from the same pass are worth recording because both fail
silently. A pathway summary is curated prose already sanitized upstream to
`<i b em strong sub sup a p br>`, so running it through a bare-tag restorer
printed the tags as text on 553 of the 694 detail panels; and rebuilding its
anchors from the escaped stream cannot be done with a regex -- once `<` and `>`
are entities an attribute tail has no terminator, and a pattern permissive
enough to cross `target=&quot;_blank&quot;` walks into the next paragraph.
Anchors are dropped to their text instead. Some summaries also carry a
double-encoded entity, so the stored `&amp;beta;` needs collapsing twice.

And **Plotly keeps whatever width it was drawn at.** A figure in the two-column
chart grid was measured before the grid settled and stayed 700px wide in a 570px
box, taking the page's scrollWidth to 1,373 at a 1,280 viewport. `MGDB.chart()`
re-runs `Plotly.Plots.resize` on a window resize, but nothing fires one on load.
The page checks each figure against its box a few times after render, and the
chart box is `overflow: hidden` so a momentarily wrong figure cannot expand the
page while it is.

Three differences between this page and the CSV downloads are stated in
Methods: the CSVs place the 104 CornCyc-only pathways under a top-level class
that is not in the ontology the page reads (and for 42 of them the class is
genuinely different), the matrix CSV omits the 62 CornCyc-only pathways that do
carry genes, and the per-track CSV is the pipeline's own mixed-population copy.

#### Statistics

Enrichment is a one-sided hypergeometric test with Benjamini-Hochberg control,
run in the browser. Two things are done differently from the prototype it came
from. The background is the genes in the chosen track carrying at least one
pathway assignment — about 4,400 of a genome's ~40,000 gene models — not the
gene complement. And the correction is applied over **every pathway large
enough to be tested in that background** rather than only the pathways the list
hit; correcting over the hit set makes every q value smaller than it should be
and is not comparable with topGO or g:Profiler.

### Rebuilding the pathway explorer payload

`data/projects/pathway_explorer/` is **not** in `deploy/manifest.txt`. It is
~53 MB across 4,800 files, and the analysis output it is built from has never
lived on the web host. Build it and ship the tree as one tar:

```bash
python3 tools/pathway_explorer_index.py \
    --source <analysis>/data --downloads <analysis>/downloads \
    --out /tmp/pathway_explorer
tar -C /tmp -czf /tmp/pe.tgz pathway_explorer
scp /tmp/pe.tgz <host>:/tmp/
ssh <host> 'tar -C /var/www/claude/html/data/projects -xzf /tmp/pe.tgz && rm /tmp/pe.tgz'
```

The tool prints every summary it recomputed and every disagreement it found.
Read that output: a new disagreement means the analysis changed shape, and the
page states numbers the tool derived rather than numbers it was handed.

## The UniformMu insertion resource

`/uniformmu` replaces a 557-line static document written in 2012 and last
revised for the March 2011 data release. It described a collection of 26,211
insertions in 5,127 seed stocks. The collection now holds **77,990 insertion
records and 10,525 seed stocks**, every one of which is a record page on this
site, and the old page said none of that — its numbers were typed into the
prose by hand and nothing updated them.

```
controllers/uniformmu.php                     the page. Runs zero SQL
templates/static/mgdb_uniformmu.bau           its body
css/mgdb-uniformmu.css  js/mgdb-uniformmu.js  its assets
search/uniformmu/uniformmu_search_api.php     the live lookup
search/uniformmu/uniformmu_search_lib.php     its four queries
data/uniformmu/uniformmu_summary.json         the precomputed counts
tools/uniformmu_summary.php                   what writes them
```

`controller.php` checks `controllers/<CONTROLLER>.php` before falling through
to `redirect.php`, so `controllers/uniformmu.php` takes the route the same way
`/cite` did, without touching `controllers/documentation/uniformmu.php`.
Rollback is deleting the new controller. The originals are archived in
`legacy/uniformmu/`, and the nine methods figures — which existed only on the
server — are now in the repository and the manifest.

**`/documentation/uniformmu` is a second route to the old page and still
serves it**, the same way `/about/cite` still serves the pre-redesign cite
page. It differs in one respect worth deciding about: the old page states
26,211 insertions in 5,127 stocks, which is now wrong by a factor of three, so
this duplicate is stale rather than merely redundant. Nothing on the site links
to it — every internal link, in `templates/insertion/`, `data_center/`,
`gene_center/` and `documentation/projects.bau`, points at `/uniformmu` — so it
is reachable only from an old bookmark. Retiring it means replacing
`controllers/documentation/uniformmu.php` with a redirect, which puts a file
owned by another maintainer under this repository's manifest; that is a
judgement call and has deliberately not been made here.

### Measured numbers are precomputed; lookups are live

The page separates two things that used to be one paragraph of prose.

**The counts are precomputed.** Every collection-wide number comes from
`data/uniformmu/uniformmu_summary.json`. `perm_tables.marker_gene_model` holds
1,305,425 rows and is indexed on `gene_model`, `transcript` and `id` — nothing
on `source_id`, `assembly_version` or position — so the per-assembly rollup is a
sequential scan costing **1.6 s and 19,551 buffers**. Rendering the page
therefore runs **no SQL at all**. Re-run the tool and redeploy the file to
update the page:

```bash
scp tools/uniformmu_summary.php development-server:/tmp/
ssh development-server 'cd <webroot> && php /tmp/uniformmu_summary.php' > src/data/uniformmu/uniformmu_summary.json
./deploy/deploy.sh src/data/uniformmu/uniformmu_summary.json
```

The file's modification time is what the page reports as its data date, so the
page cannot claim to be fresher than its data. The tool checks its own output
and refuses to look healthy when a headline total comes back as zero.

**The lookup is live**, and it is the thing the old page did not have. Four
modes, all indexed, all bounded:

| Mode | Accepts | Cost |
| --- | --- | --- |
| `gene` | any identifier a gene page accepts | 9–10 queries, ~40 ms |
| `insertion` | `mu1013469`, or a bare locus id | 3 queries, ~20 ms |
| `stock` | `UFMu-01828`, `ufmu1828`, or `1828` | 4 queries, ~30 ms |
| `region` | assembly, chromosome, window ≤ 20 Mb | 3 queries, ~130 ms |

Each resolves a subject, collects insertion ids, and then answers two questions
about them in one query each — where each sits on every assembly, and what
variation and seed stock it leads to. That is why an insertion found through a
v3 gene name still shows its v5 coordinates.

The gene mode does one thing worth knowing about: it asks the locus which gene
model names it has answered to, and searches all of them. UniformMu alignments
are recorded against four assemblies whose gene names are unrelated strings —
`GRMZM2G036297`, `Zm00001d002005`, `Zm00001eb067740` are the same gene — so
searching only the name that was typed finds only the insertions aligned to
that one annotation.

### Three ways to be wrong about this data, and what the page does about them

- **The assembly rows are not additive.** They are one collection aligned four
  times. 58,102 insertions on v5 and 63,008 on v3 are mostly the same
  insertions.
- **Structure counts are alignments, not insertions.** An insertion is recorded
  once per (transcript, gene structure) it touches, so one event spanning an
  exon and an intron of a two-transcript gene yields four rows.
  `mu1013469` has fifteen across four assemblies and the page shows it as four.
- **W22 has no genic calls.** Every W22 alignment is recorded as a flanking
  region, so its "hit inside the transcript" column reads *none recorded*, not
  zero. And the v3 coverage share is measured against the whole 110,467-model
  working gene set rather than a filtered one, so it is not comparable with the
  v4 and v5 shares beside it. The page states both.

Two data problems found while building it are in `ADMIN_DEPENDENCIES.md`: nine
*Ac* insertions filed under the UniformMu source (AD-016), which both the tool
and the lookup work around by restricting to the `mu#####` naming convention,
and the missing positional index (AD-015) that makes the region lookup a
sequential scan and the summary a precomputed file rather than a query.

### Verification

Cloudflare's bot challenge means a browser cannot load the page for checking,
so the render path is exercised the same way the gene record page's is — under
JavaScriptCore against real captured payloads, with a small DOM shim:

```bash
tools/tests/run_uniformmu_render_test.sh
```

Forty-four checks: every link the result table emits, the assemblies it lists,
the three states that are easy to render as nothing (an insertion with no
coordinates, one with no seed, a stock with no mapped insertion), the two
inputs refused client-side, the figure-zoom overlay, and the chart geometry —
including that the chromosome axis stays in chromosome order rather than
sorting itself by count.

Two of those checks exist because of a bug this harness did not originally
model. `Bauplan::includeScript()` emits into `<head>`, so page scripts run
while the document is still parsing. The first version of
`mgdb-uniformmu.js` read `document.querySelector('.mgdb-uniformmu-page')` at
module scope, got `null`, and returned — **no charts and no lookup, with the
page showing "Loading chart…" indefinitely**, which reads as still working
rather than as given up. The shim now starts at `readyState: "loading"` with
an empty document and fires `DOMContentLoaded` afterwards, so anything that
touches the DOM too early fails the suite. Any new page script in this
repository has the same exposure.

## The insertion data center

`/insertion` is its own top-level controller, not a `/data_center/<page>` route,
so the guard lives at the top of `src/controllers/insertion.php` rather than in
`data_center.php`. Everything below that guard is the unmodified legacy code —
`/insertion/mu1000002` and every other record page still goes through it.

**One table, four collections.** `perm_tables.marker_gene_model` holds 1,305,425
rows, of which 1,269,215 are insertion alignments split across four
`mgdb.person` ids:

| Collection | `source_id` | Alignments | Insertions |
| --- | --- | --- | --- |
| UniformMu | 1226435 | 597,426 | 68,843 |
| BonnMu | 9045136 | 647,938 | 463,968 |
| Ds-GFP (Dooner-Du Ac/Ds) | 3229932 | 18,428 | 7,510 |
| Ac/Ds genome-wide (Volbrecht) | 9023179 | 5,423 | 1,638 |

Restricting to those four ids is what makes "all datasets" mean something
narrower than every row in the table.

**Three search modes, and what each costs.** Each resolves a bounded set of
insertion locus ids first, then asks two indexed questions about that set —
where each insertion sits, and what variation and stock it leads to. That keeps
every query's row count close to what the page renders, instead of joining
stocks onto alignments and de-duplicating the product.

| Mode | Cost | Why |
| --- | --- | --- |
| by gene model | ~30 ms | one index probe per gene, against `marker_gene_model_idx1`/`idx3` |
| by insertion name | ~20 ms | one `IN` against `idx_locus_name` |
| by genome window | ~150 ms | **no positional index** — a parallel sequential scan of 1.3 M rows. AD-015. |

The region window is capped at 20 Mb for that reason: the scan costs the same
whatever the width, so the cap bounds the pathological request rather than the
normal one.

`gene_model` is empty on every W22 alignment and the transcript carries the gene
name instead, so both are tested and the transcript's `_T###` suffix is stripped
before grouping. Counting distinct `gene_model` on W22 returns 10, which is not
a fact about the genome.

**The legacy endpoint is still live and still injectable.** The forms this page
replaces posted to `search/insertion/insertion_results.php`, which interpolates
the chromosome, coordinates, dataset, background, and the whole identifier list
straight into SQL. Recorded as AD-022. The modern library binds every value as a
named parameter, including expanding id lists to individually-bound
placeholders.

## The homepage

Built from the `design_handoff_maizegdb_homepage` bundle, direction 1c. The
handoff's mapping table was accurate: every component it named already existed,
and the page adds only three new rule groups in `src/css/mgdb-home.css` — the
two-column body, the banded index, and the right-rail lists.

**Where each number comes from, and why they are not all live.** The four metric
counts are full scans; measured together they cost **878 ms**, which the site's
most requested page should not pay to show figures that change once per release.
`tools/home_summary.php` measures them offline into
`src/data/home/home_summary.json` and the page reads that — the same arrangement
`/uniformmu` and `/insertion` use for their collection-wide totals.

| Value | Source | Cost |
| --- | --- | --- |
| Release / next update | `ctl`, live | 1.6 ms |
| Genome assemblies | precomputed | 8.6 ms |
| B73 gene models | precomputed | 348.6 ms |
| Seed stocks | precomputed | 220.4 ms |
| References | precomputed | 299.1 ms |

The dates stay live deliberately: a stale release date is a claim about the
data, and it is one indexed row.

Assemblies count *Zea mays* only — 129 of the 161 completed assemblies. The
other 32 are Andropogoneae from the PanAnd project, real data but not what
"genome assemblies" means to a reader on the maize homepage. Note `/genomes`
separately hard-codes 158 and so disagrees; that page has not been reconciled.

**News is derived, not authored.** `data/news.xml` has no title field — each
entry is a paragraph of curator prose with trusted HTML in it. The rail needs a
headline, so one is made: entities decoded, tags stripped, first sentence taken,
trimmed on a word boundary. It is a lossy summary of curator copy, which is why
the full entry stays one click away under the list. A `<title>` element in
`news.xml` would remove the guesswork.

**`index.php` is now owned by this repository.** It was `john:mgdbadmin` and
outside the manifest. Listing it means every deploy overwrites the server copy,
which is the fix for the drift documented against the data centers — and also
means a change made directly on the server will be silently erased.
`index_legacy.php` sits beside it: the verbatim pre-redesign controller, which
the new one includes for any `?page=` other than `home`, and which is the
rollback.

`?page=<anything>` returns a Bauplan error about a missing `blast_url`. That is
pre-existing — the codex instance's untouched copy returns the same bytes —
because the original only ever loaded the megamenu in its `home` branch. The
fallback reproduces it rather than quietly fixing it.

**Four resources were dropped from the index** by explicit decision:
Nomenclature, Mutants & Phenotypes, SNPs & Traits, and Metabolic Pathways. All
four are still in the Data Centers mega menu. `legacy/home/README.md` records
where to put them back.

## The protein structure data center

`/data_center/protein_structure` replaces a page that was two things bolted
together: a static header with three hand-typed counts, and a pair of NGL
viewers that each reloaded an HTML fragment through a jQuery `.ajax()` call.
Between them sat a complex search which was the only part with real data behind
it, and the part with the least room on the page.

This version inverts that. The structure workspace *is* the page: one search
resolves an identifier to every assembly state predicted for it — monomer,
homodimer, heterodimer — and the chosen model opens in a three-pane viewer with
per-residue confidence along the bottom.

    src/controllers/data_center/protein_structure_modern.php  guarded from data_center.php
    src/templates/static/mgdb_protein_structure.bau
    src/css/mgdb-protein-structure.css
    src/js/mgdb-protein-structure.js
    src/js/lib/3dmol/3Dmol-min.js                             vendored, see its LICENSE file
    src/search/protein_structure/protein_structure_api.php    suggest · lookup · esmfold · manifest
    src/search/protein_structure/protein_structure_lib.php
    src/tools/protein_structure_index.php                     builds data/protein_structure/

Pre-redesign originals are archived in `legacy/protein_structure/`, with a
README recording what was wrong with them.

### The viewer

Ported from a standalone Boltz-2 complex viewer: the AlphaFold pLDDT palette and
binning, the colour-function approach to 3Dmol styling, the per-residue
confidence strip, surfaces, legend and colourbar. Dropped on the way in: the
scripted camera tour, the movie recorder, presentation mode, the draggable pane
splitters, and the ligand affinity scorecard, which has no counterpart in
AlphaFold complex data.

One substantive change. The original shipped a precomputed `res_plddt` array
beside each structure. AlphaFold and ESMFold both write per-residue pLDDT into
the **B-factor column**, so here the profile is derived from the parsed model —
`residueProfile()`, one CA atom per residue. Nothing has to be precomputed, and
the strip works for any model the viewer can open.

**Two viewers coexist on this page** — the workspace one and the ESMFold one —
so every control is addressed by a `data-ps-*` attribute and looked up inside
its own `[data-ps-viewer]` root. Ids were tried first and were wrong: a shared
`id="ps-viewport"` resolves to whichever viewer comes first in the document,
with the effect that opening an ESMFold model rendered it into the workspace
viewer and overwrote that viewer's confidence strip. Do not reintroduce ids
here.

3Dmol is vendored rather than loaded from unpkg, which is where the old page got
NGL. When extracting it from a single-file viewer, **strip the trailing
`</script>`** — leaving it in produces a file that downloads as valid
`application/javascript`, throws `Unexpected token '<'`, and defines no global,
which looks nothing like the cause.

### Why the typeahead is an index and not a scan

The old `suggest` action read `suggestions.json` — 73,408 entries, 13 MB — and
`json_decode`d and walked all of it **on every keystroke**. Measured on codex:
164 ms median, 527 ms worst case, per request, repeated for every user typing at
once.

The obvious fix does not work, and the reason is worth keeping. Every one of the
73,042 gene models begins `Zm00001eb`, so the trigram `zm0` has 73,042 postings,
as does every prefix of that stem up to nine characters; TrEMBL accessions do
the same with `A0A` (65,707) and NCBI symbols with `LOC` (31,409). **An n-gram
index over this corpus is one giant posting list plus noise.**

What works is a prefix index that splits adaptively. Bucket at depth 3; any
prefix still over `SHARD_CAP` is *hot* and gets rebuilt one character deeper,
repeatedly. On the current export that settles at **3,847 shards, median 15
postings, maximum 400**, leaving only **185 hot prefixes** — a routing table
small enough to be a 2 KB file. A hot prefix is still a legitimate query, so each
one also gets a precomputed, already-ranked answer: a typeahead shows ten rows,
and there is no reason to rank 73,042 candidates at request time to fill them.

Because the shared stem carries no information, each gene model is **also**
indexed under its numeric tail — `168550` finds `Zm00001eb168550`. Without that,
typing the part of the identifier that actually distinguishes one gene from
another matches nothing.

Answering a keystroke is now one routing read, one shard read, and a prefix
filter over at most 400 short strings:

| query | old | new | |
| --- | ---: | ---: | ---: |
| `adh` | 176.0 ms | 1.5 ms | 117× |
| `sod` | 165.1 ms | 1.6 ms | 101× |
| `a0a` | 458.9 ms | 1.6 ms | 292× |
| `zm` | 527.1 ms | 1.8 ms | 298× |
| **median** | **164.2 ms** | **1.6 ms** | **102×** |

### Query cost

Rendering the page runs **zero SQL** — every headline count comes from
`data/protein_structure/manifest.json`, so the header cannot disagree with the
data the search answers out of. That was the old page's other bug: `39,299`,
`71,725` and `8 proteomes` were literals in the template, and the summary strip
below them carried different numbers from a different date.

`suggest` and `lookup` cost **no queries** on an index hit. The database is
reached only when the index has already missed, so that the page can distinguish
*this is not a gene* from *this is a gene with nothing predicted for it* — which
are different answers with different next steps. `esmfold` always queries,
because ESMFold models are named by protein isoform and only the database maps a
gene to its canonical one; it is a separate action so that searching stays at
zero queries and only a reader who opens that panel pays for it.

Every response carries `summary.elapsed_ms` and `summary.queries`. The old
endpoint's 164 ms was invisible until somebody measured it; leaving the
measurement in the response makes the next regression visible from the network
tab.

### Two traps in the data

**`wx1` is not in the collection.** No symbol in the export begins with `wx`.
The old substring matcher answered the page's own `wx1` example button with
`A0A804MWX1` — an unrelated magnesium transporter that merely contains those
letters — and presented it as the match. Prefix matching returns nothing there;
the lookup path resolves `wx1` through the gene database to `Zm00001eb378140`
(WAXY, five monomer models), which is what was being asked for.

**Rank dimers on ipSAE, not ipTM.** ipTM scores the whole complex and is
dominated by how well each chain folds alone, so two confidently folded proteins
parked next to each other without touching still score well. ipSAE is computed
over the interface. Sorting these lists by ipTM puts big well-folded
non-interactions at the top; `psSortModels()` uses ipSAE for dimers and pLDDT
for monomers.

### Rebuilding the payload

`data/protein_structure/` is generated on the server and is deliberately **not**
in `deploy/manifest.txt` — it is 110 MB across ~4,600 files. See AD-019 in
ADMIN_DEPENDENCIES for the command and when to run it.

## The Data Hub shell

`css/mgdb-hub.css` is the shared shell. A page opts in with `mgdb-hub-page` on
its `<main>` and gets the pale blue ground, the white section cards, the metric
top edges, the grid-tile hover, the zebra striping, the green Related resources
panel, the `.mgdb-hub-field` form row, and the scroll offset under the sticky
tab bar. `/ai` and `/data_center/variation` are on it.

### Section titles carry no rule; sections carry a colour

Two changes made together on 2026-09-01, because they answer the same thing.
`.mgdb-section-heading` in `mgdb-modern.css` closes with a `border-bottom`,
which was right when a section was a transparent band on one continuous sheet.
Once the section became a bordered white card that rule read as a **second
border a few pixels inside the first** — a box around the title. It is now
zeroed for hub pages, and the colour that used to distinguish one section from
the next moved to the card's own top edge.

The rotation is positional and **starts at `nth-of-type(2)`**, because the hero
is the first `<section>` on every hub and is excluded. Eight colours — green,
gold, blue, burgundy, leaf, orange, wine, dark gold — which is exactly the
number of content sections both hubs carry, so nothing repeats. A page that
wants to pin a section to a particular colour says so with `.mgdb-hub-tone-*`.

The rotation rules sit **after** the Related resources block on purpose: that
block sets a `border-color` shorthand for its green wash, which would otherwise
take the top edge with it.

### References

One shape for a cited paper, wherever it appears: `.mgdb-ref*` in
`css/mgdb-hub.css` and `include/references_lib.php` for the matching markup, so
a page cannot get one without the other. Taken from `/NAM_project`, which is
where the group settled it — journal and year as a pill, the DOI in mono, the
title in burgundy, then authors, citation, the abstract in a green-edged well,
and the links as buttons.

The content comes from `data/cite_journal_articles.json`, the same curated
bibliography `/cite` reads: verified titles, authors, volumes, DOIs, PubMed IDs
and abstracts. **A page names DOIs, not citations**, so there is no second copy
to drift. Anything outside that file — a preprint, say — supplies a `fallback`
and renders without an abstract rather than with an empty well. One record
stores a database URL where its abstract should be, 49 characters long, so the
well only opens for text past 120.

`.mgdb-ref` is the one block in `mgdb-hub.css` that does not require
`mgdb-hub-page`, so a page outside the hub set can load the sheet and get the
same references. Copy citation / Copy DOI is bound by `mgdb-modern.js` for every
`.mgdb-ref-copy` on the page — no page script asks for it.

### Legend placement, and why a page may not set it

`MGDB.chart()` now **normalises the legend** to sit above the plot, anchored to
the plot's top edge, and reserves a band for it. A page's own `legend.y` is
overridden unless it passes `legendManual: true`.

That is not tidiness. `legend.y` is in paper coordinates — a fraction of the
*plot* height — so any fixed value drifts as a figure gets taller. The old
`BASE_LAYOUT` value of `-0.22` sat on the tick labels of a 320px figure and was
drawn about 140px below a 700px one, outside the paper, bleeding over the
figcaption. Anchored above the plot there is no axis furniture to collide with,
and the offset cannot drift because the anchor moves with the plot.

`fitLegend()` then checks the drawn figure: a horizontal legend wraps when its
entries do not fit one row, and the reserved band was sized for one. It measures
**only whether the legend is clipped at the top of the figure** — with the
legend anchored to the plot it cannot overlap the plot by construction. Two
false starts are worth recording, both mine:

- Growing the top margin against a legend placed *below* the plot is unstable —
  shrinking the plot moves the legend further down. It reached `margin.t: 1809`
  on a 472px figure before the cap caught it. Normalising the position is what
  makes the correction safe.
- `.cartesianlayer` is **not** the plot rectangle. It is a `<g>` whose bounding
  box spans the whole SVG, so comparing the legend against it reported a 30px
  overlap on a figure that had none, and the margin grew every pass. If a plot
  rectangle is ever genuinely needed, `rect.nsewdrag` is the one.

### The gap between a tick label and the plot

`automargin` keeps a tick label from being cut off, but it makes the margin
*exactly* as wide as the text — so on a horizontal bar chart the longest
category name ends one pixel from the first bar and reads as running into it.
Measured at 1px on all three `/data_center/variation` figures.

`BASE_LAYOUT` now sets `ticks: 'outside'` with `tickcolor: 'rgba(0,0,0,0)'` and
a `ticklen` on both axes: that reserves the gap without drawing a mark. 10px on
the y axis, which carries the category names, 6px on the x. Measured at 14px
after. The two-space `ticksuffix` that stood in for it on the variation hub is
gone; it also leaked into the hover text.

Related: outside bar labels need `\u00A0`, not a space — SVG collapses leading
whitespace, so a plain space measures as no padding at all.

## Retired comparison routes

`/data_center/map2`, `/genome2` and `/data_center/stock2` were the three hubs
duplicated on the tinted ground so the group could hold the two grounds side by
side. The tint became the standard, so all three rendered identically to the
pages they were copies of and had nothing left to compare. Retired 2026-09-01.

**They are permanent redirects, not deletions.** A link saved during the
comparison still lands on the real page:

| Retired route | Redirects to | Lives in |
| --- | --- | --- |
| `/genome2` | `/genome` | `controllers/genome2.php` |
| `/data_center/map2` | `/data_center/map` | the `PAGE == 'map2'` branch in `controllers/data_center.php` |
| `/data_center/stock2` | `/data_center/stock` | the `PAGE == 'stock2'` branch in the same file |

`css/mgdb-hub-tinted.css` went with them: it had no consumers left, and
`css/mgdb-hub.css` is its generalisation. It is deleted from the repository, the
manifest and the web root. **Rollback is `git revert`** — the sheet and all
three branches come back together.

One wart worth knowing: `tools/redesign_status.py` walks the web root, so it
still finds `controllers/genome2.php` and lists `/genome2` as a page. It probes
the URL, follows the redirect to `/genome`, and therefore counts it as Modern —
one of the 97. The two `PAGE` branches are not files, so map2 and stock2 do not
appear at all. Deleting `controllers/genome2.php` outright would drop the row
and make the route 404; the redirect was judged worth more than the tidier
count.

## The Protein Structure Data Hub on the shell

`/data_center/protein_structure`. Tabs: Search, ESMFold isoforms, Comparison
tools, Interpreting predictions, Data sources, References, Metrics, Related
resources. Files:
`controllers/data_center/protein_structure_modern.php`,
`templates/static/mgdb_protein_structure.bau`,
`css/mgdb-protein-structure.css`, `js/mgdb-protein-structure.js`.

Rendering runs no SQL — every number comes from
`data/protein_structure/manifest.json` — so the page is 70 ms warm.

Seven eyebrows gone; the four metric cards onto the shell's markup with tones
(33,604 monomers · 4,302 homodimers · 3,089 heterodimers · 40,995 indexed);
References added; the related section onto the shell's green wash; "Statistics"
renamed **Metrics**; and every section heading now matches its tab label, which
none of them did — the Search tab pointed at a heading called "Find predicted
structures for a protein".

### The hero was showing a hard-coded date beside a computed one

The controller derives `data_date` from the manifest's own `generated` field,
falling back to its mtime, and the Data sources section prints it. The hero
stamp beside it read a literal **"August 2026"**. It now reads the same token,
which on the current manifest is 16 August 2026 — the page can no longer claim
to be fresher, or staler, than its data.

### The tab bar wrapped above the shell's own breakpoint

`css/mgdb-hub.css` steps its scroll offset from 65px to 113px at **1170px**,
which assumes a bar that is one row above that width. Measured here: eight
labels wrap to two rows at somewhere in **1176–1220**. So between 1171 and the
wrap the shell handed out 65px against a 105px bar and every anchor jump left
its heading 40px behind the bar. The band starts at 1220 instead.

The general form: **the shell's 1170 boundary is an assumption about the bar,
not a measurement of it.** Any hub whose labels are long enough to wrap above
1170 needs its own band, and the only way to know is to measure.

### mgdb-modern.css already turns the bar into a rail below 767px

Worth recording, because it changes what the other hubs should do.
`css/mgdb-modern.css` carries `@media (max-width: 767px) { .mgdb-section-tabs
{ overflow-x: auto; flex-wrap: nowrap } }` for every page. This hub inherits
it, so its bar is one 57px row at 375 rather than several stacked ones.

Every hub sheet that copied the family's tab block, though, sets
`flex-wrap: wrap` at a higher specificity and **defeats that rule** — which is
why those bars reach 153px at 375, and why the Gene and nomenclature pages
needed a rail written out by hand. Dropping `flex-wrap: wrap` from those blocks
would let the shared rail apply everywhere, but each one's ladder has to be
re-measured afterwards, so it has been left for a deliberate pass rather than
folded into this one.

### Verified

8/8 distinct section edge colours, no rule under a section title, five
reference cards, no duplicate `id`, all eight nav labels matching their `<h2>`,
four equal-height metric cards, five related links, and no horizontal overflow
at 375. Jumps clear by 8px at 1280, 900 and 375, and by 40px at 1190 — the one
width inside the widened band where the bar happens to be a single row, which
is the safe direction. The scrollspy was IntersectionObserver-only, which marks
nothing in embedded browsers that deliver no entries, and is the shared
scroll-driven one now.

## The Genetic Variation Data Hub on the shell

`/genetic_variation` — SNPs and traits. Tabs: Search, Tools, Projects,
Downloads, About, References, Metrics, Related resources. Files:
`controllers/genetic_variation.php`,
`templates/static/mgdb_genetic_variation.bau`,
`css/mgdb-genetic-variation.css`, `js/mgdb-genetic-variation.js`.

Rendering runs no SQL — everything comes from
`data/genetic_variation/genetic_variation.json` — so the page is 74 ms warm.

### What the shell changed

Eight eyebrows gone; the four metric cards moved onto the shell's markup with
tones; the "Related data hubs & submissions" section became the shell's badged
five on the green wash (its own `.gv-bottom-section` gradient was doing that
job by hand and is deleted); and References is new.

The section order needed a decision this hub had not made: it had no Search
tab at all, but it does have a corpus of its own — the eleven variant builds,
with a keyword filter and four property chips over them. So **Datasets became
Search** and moved to the front, and the panel intro says plainly that the
collection being searched is the builds, so every one is listed until you
filter. That is the same call the `/data_center/` directory needed.

`Types of variation data` became **About**, `Genetic variation in numbers`
became **Metrics**, and `Interactive variation tools` became **Tools** — the
tab labels and the headings now match, which they did not before.

### The chips sat 8px above the input they share a line with

`.gv-table-controls` is a two-column grid, filter input on the left and the
property chips on the right, with `align-items: end`. The chips were the
shared 36px and the input 44px, so they were bottom-aligned and their tops did
not line up — and the Reset button that appears beside them when a filter is
active is 44px, which made it worse. The chips take the input's height now, so
the whole control line shares one top and one height. Both tables, measured.

### Both filters advertise terms they can actually find

The two placeholders name examples — `B73 v5, imputation, NAM, Panzea…` and
`PRJNA641489, NAM, landrace, European…`. Each was typed into its own box: 10,
2, 2 and 3 datasets; 2, 1, 1 and 2 projects. None of them is a claim the
haystack cannot honour.

Worth noting for anyone testing these: `MGDB.filterList` debounces the input
handler by **200 ms**, so a test that dispatches `input` and reads the row
count 120 ms later sees no change and looks like a broken filter. It is not.

### Verified

8/8 distinct section edge colours, no rule under a section title, five
reference cards, no duplicate `id`, all eight nav labels matching their
`<h2>`, four equal-height metric cards, eleven dataset rows, five related
links, and no horizontal overflow at 375.

The tab bar is one 57px row from 1280 down to 641, and below 640 the page
sheet drops its stickiness altogether — so the ladder needs one band, 65px,
and 16px below 640 where a section has nothing to clear. Every jump clears by
8px at 1280 and 900, and lands 32px from the top at 375. The tab script was an
IntersectionObserver-only scrollspy, which marks nothing in embedded browsers
that deliver no entries; it is the shared scroll-driven one now. One
`transition` used `--mgdb-dur-fast`, which is defined nowhere and invalidated
the whole declaration; it is literal durations now.

## The Gene Data Hub on the shell

`/gene_center/gene`, the site's second most requested page and the largest of
the hubs: nine forms, three figures, 195 KB of markup. Tabs: Search, Sequence
and region, Gene model lists, Downloads, Nomenclature, About, References,
Metrics, Related resources. Files:
`controllers/gene_center/gene_search_modern.php`,
`include/gene_hub_lib.php`, `templates/static/mgdb_gene.bau`,
`css/mgdb-gene.css`, `js/mgdb-gene.js`.

The page was already modern — its own sheet, its own tab rail, four metric
cards, no eyebrows — but not on the shell, with no References and no
Related-resources panel. Cold build is still the documented 12 s (the 1.88M
row aggregate behind the annotation chart); warm is **105–117 ms**.

### Thirteen sections into nine

| Was | Now |
| --- | --- |
| Search | **Search** |
| Sequence search · Search by region | **Sequence and region** |
| Gene model lists | **Gene model lists** |
| Downloads | **Downloads** |
| Nomenclature · Gene model terms | **Nomenclature** |
| Reference assembly and annotation · Gene model issues | **About** |
| — | **References** |
| Gene models by annotation · The B73 reference annotation · Metrics | **Metrics** |
| Other resources (10 links) | **Related resources** (5, badged) |

The three figures moved under Metrics, which is where the shell puts them.
Every merged block kept its markup intact, so the nine form endpoints and
every id the page script hooks are untouched — verified by driving the page
afterwards.

### The advanced criteria started at nine different left edges

`.gene-criterion` was a flex row of checkbox, label and control, so **every
control began wherever its label happened to end**: 194, 262, 264, 295, 341,
350, 359, 585. Ten criteria, nine left edges, and the eye has nothing to
follow down the form.

It is a three-column grid now — checkbox, label, control — with the label
column wide enough (21rem) that the longest label still fits on one line.
Measured after: **every label at x=102, every control at x=446, every control
44px**. Two rows carry more than one control ("between these positions", and
the protein accession with its format hint) and those keep them in a nested
flex row inside the third column, so they start at the same x as every select.

The protein row's label was the reason a fixed column looked impossible — it
ran to 475px because it carried "(UniProt, PFam, EC)". That parenthetical is
a hint about the field, not part of the label, and it now sits beside the
input where hints belong.

### Nine tabs wrap to four rows on a phone

Measured: one row (57px) down to somewhere in 1101–1200, two rows (105px)
down to 768, three at 520, and **four rows — 201px, a quarter of the
viewport — at 375**, permanently sticky. Below 767px the bar becomes a single
scrolling row instead, which is the pattern the other long-barred hubs use,
so the ladder is 65px above 1200, 113px from 1200 to 768, and 65px below.
Every jump clears by exactly 8px at 1280, 1000, 760 and 375.

### Verified

9/9 distinct section edge colours, no rule under a section title, five
reference cards, no duplicate `id`, all nine nav labels matching their `<h2>`,
all three figures drawn, four equal-height metric cards, and no horizontal
overflow at 375.

**Every form on the page, measured with all `<details>` open** — at 1280 each
form's controls share one left edge, its full-width controls share one right
edge, and every input, select and button is 44px tall; at 375 the four search
controls are the same width, left edge and right edge, and nothing overflows.
The one defect that pass found was the search line at 375, where the submit
and reset kept their intrinsic widths under a full-width input and the
options select stopped 13px short of the query field; both are fixed.

Function was checked with a stubbed `fetch`: the main search and the example
chips call `gene_search_api.php?term=…&limit=100`, the advanced form adds
`mode=advanced` and its `use_*` flags, the three region radios switch both the
visible field group and the form's action between `gene_chr_position.php`,
`gene_marker_position.php` and `gene_gm_position.php`, and all six tool forms
still point at their own endpoints.

## The Data Hub directory

`/data_center/` on the shell. Tabs: Search, Guided paths, About, References,
Metrics, Related resources. Files:
`controllers/data_center/data_center_hub_modern.php`,
`include/data_center_hub_catalog.php`,
`templates/static/mgdb_data_center_hub.bau`, `css/mgdb-data-center-hub.css`,
`js/mgdb-data-center-hub.js`.

This is the one hub whose corpus is the other hubs. The shell's rule that
results stay hidden until a search is the wrong rule here — the results *are*
the directory, so all nineteen cards are visible and the search narrows them.
Everything else is the standard conversion: eyebrows, hero tagline and buttons,
and the `01`–`05` numerals on the guided-path tiles are gone; the section order
is search-first; and the Downloads & Support block became the shell's
Related resources with Internal/External badges.

### Six of ten metric numbers had no query behind them

`162` genome assemblies, `1.88M` gene models, `97K` pan-genes and `1M+`
predicted structures were literals in the catalog, and a `try/catch` answered
a failed query with a hard-coded default — the pattern the skill already warns
about. Every literal had drifted:

| Card said | Actually |
| --- | --- |
| 162 genome assemblies | **160** |
| 1,878,909 gene models | **1,878,920** |
| 1M+ predicted structures | **40,995** structure models |

The four that *did* run counted whole tables. Every hub on this site counts
`JOIN id_num i ON i.id = x.id WHERE i.curation_lvl = 0`, so **the directory
disagreed with the hubs it links to**: 790,208 loci against the Locus hub's
781,395, 87,397 stocks against the Stock hub's 80,063, 780,086 markers against
771,097, 55,171 references against 54,900.

Everything is now counted the way the hub that owns it counts it — the
assemblies come from the very query the Genome hub's table is built from, and
the structures from the manifest the structure hub reads — and there is no
fallback at all: a collection whose count fails is dropped from the figure
rather than drawn at a number nobody measured.

Four cards: **19** data hubs, **1.71M** variation records, **781K** locus
records, **771K** markers and probes. The other seven collections moved into
the scale figure below, which is where a list of ten belongs. Cold build
**3.8 s** (six curated counts over million-row tables), warm **73–76 ms** with
no SQL.

### The directory did not know about QTL

QTL was split out of the Loci hub earlier in this redesign, given its own
controller, template, sheet and header-menu entry — and the directory was never
told. Its Loci card was still called "Loci and QTL" and still carried `qtl` in
its search terms, so a reader searching the directory for QTL was sent to the
hub that no longer holds it. There are 19 cards now, the Loci card describes
loci, and its search terms no longer claim QTL.

That is the same trap as the megamenu one, one level up: **a hub is only split
when every navigation surface is split**, and the directory is a navigation
surface.

### A category axis keeps the labels it was born with

Both figures are rendered server side into one JSON block, so they draw with
no request of their own and the donut is right before any script runs — it used
to be tallied from the rendered cards.

The scale figure switches to shortened tick labels below 560px, and doing that
by restyling `y` does not work: **Plotly pins a category axis's values on the
first draw**, so a shortened set adds new categories rather than renaming the
existing ones, and the figure keeps whichever labels it was drawn with. The fix
is to key the bars on the full labels always and swap `yaxis.ticktext`
instead. The same latent defect was in the EST and SSR figures and is fixed in
the same commit.

Worth knowing when testing this: **the browser pane's `resize_window` changes
the viewport without dispatching a `resize` event to the page**, so a
breakpoint-crossing relayout never fires and the figure looks stale when it is
not. Reload at each width, or dispatch `new Event('resize')` by hand — the same
caveat that already applies to `scrollTo` and scrollspy.

### The tab ladder, measured

Seven collections on a log axis and six tabs. Measured by resizing the real
page: one row (57px) down to somewhere in 701–740, two rows (105px) down to
somewhere in 396–415, three (153px) below that. Every jump clears by 8px at
1280, 470 and 375, and by 40px at 740 — the single width where a band takes the
larger of the two heights it could straddle, which is the safe direction.

### Verified

6/6 distinct section edge colours, no rule under a section title, five
reference cards, no duplicate `id`, every nav label matching its `<h2>`, both
figures unclipped at 1280 and 375, no horizontal overflow at 375, and all
nineteen hub links alive by response size and title. The directory itself was
driven end to end: 19 shown at rest, `expression` → 2, `qtl` → Loci and QTL,
a nonsense term → 0 with the empty state, reset → 19 with the field cleared,
the Literature & media filter → Images and References, and the clear button
appearing only while the field has content.

## The SSR Data Hub

`/data_center/ssr` on the shell, and the last of the five archive hubs.
Tabs: Search, Mapped SSRs, Reports, About, References, Metrics, Related
resources — seven, one more than its siblings. Files:
`controllers/data_center/ssr_search_modern.php`,
`templates/static/mgdb_ssr.bau`, `css/mgdb-ssr.css`, `js/mgdb-ssr.js`.

The search still posts to the legacy endpoint, which for a 4,646-row corpus
answers in 80–180 ms even for a term matching everything, so its three nested
subqueries were left alone. Eyebrows, the hero tagline and its buttons, and
the decorative `01` are gone.

### Three of the four metric cards were counting the page

They read 4,646 · **10** · **2** · **Archived**. Only the first was a
measurement: the 10 was the number of chromosome tiles further down the page
and the 2 was the number of download formats in each tile, so both would have
gone stale the moment a tile changed, and neither said anything about the
collection. The four now are 4,646 SSR records, 2,034 with a repeat motif,
775 distinct motifs, and 1,970 placed on a bin map. Cold build **0.31 s**
across four statements, warm **71–74 ms** with no SQL.

That makes two hubs running — see the Overgo section above — where a metric
card turned out to be counting the page's own markup or was simply a word.

### Reading a free-text repeat column

`mgdb.probe.repeat` is not structured. `(AG)6`, `AG(15)`, `AT (10)` and a bare
`CCG` all appear, so the figure takes the **first run of nucleotide letters**
as the repeat unit and its length as the answer:

| Repeat unit | Length | Records |
| --- | --- | --- |
| Dinucleotide | 2 bp | 936 |
| Trinucleotide | 3 bp | 739 |
| Tetranucleotide | 4 bp | 215 |
| Pentanucleotide | 5 bp | 79 |
| Hexanucleotide | 6 bp | 52 |
| Longer than six | 7 bp and up | 9 |
| Mononucleotide | 1 bp | 3 |

Two edges are real and are named in the caption rather than hidden: 49 motifs
are compound — `(CT)6AT(CT)9`, `(GA)9N2(GA)28` — and are counted by their
first unit, and one record's motif reads "InDel" and has no unit at all, which
is why the bars total 2,033 against the card's 2,034.

### One reference is not in the bibliography

Four of the five cards name DOIs from `data/cite_journal_articles.json`. The
fifth is Vieira et al. 2016, a review of what microsatellite markers are and
why they are useful — the one reference on the page about SSRs rather than
about MaizeGDB, and not a MaizeGDB paper, so not in that file. It is supplied
through `mgdb_render_references()`'s `fallback`, which fills title, authors,
journal and year for a DOI the bibliography does not carry. Without a
fallback the card is skipped silently rather than rendered empty.

### Seven tabs wrap at different widths than six

The shell steps its scroll offset to 113px below 1170px; this bar is still one
row there and is three rows before the shell's ladder notices. Measured on the
real page: one row (57px) down to somewhere in 781–820, two rows (105px) down
to somewhere in 406–429, three rows (153px) below that. Both boundaries sit
well away from the Overgo hub's, which is the point — a ladder copied from a
sibling would have left 56px of dead space at 820 and again at 460. Every jump
now clears by exactly 8px at 1280, 900, 780, 430 and 375.

### The narrow figure labels come from the unit, not the name

Shortening `Trinucleotide` to `Tri-nt` leaves `Longer than six` untouched, and
it is the longest label, so `automargin` gave it a 120px gutter out of a 259px
figure — the plot had 129px left. Deriving the narrow labels from the unit
instead (`2 nt`, `7+ nt`) returned the plot to **169px**. The rule that
generalises: when a narrow label set is produced by transforming the wide one,
check the transformation actually shortens the *longest* member, not the
typical one.

### Verified

7/7 distinct section edge colours, no rule under a section title, five
reference cards, no duplicate `id`, all seven nav labels matching their
`<h2>`, results hidden until a search, the figure unclipped at 1280 and 375,
no horizontal overflow at 375, and the four form controls sharing one line and
one left edge at 1280 and stacking to one full-width column at 375. The search
was driven with a stubbed `jQuery.post`: `bnlg1079`, `^cshr002` and `(AAAT)5`
all reach `/search/ssr/ssr_results.php` with the term **raw**, an empty term
never reaches the network, an in-range limit is sent as typed, and one over
the field's maximum is stopped by the browser's own constraint validation
before the handler's clamp is needed.

## The Overgo Data Hub

`/data_center/overgo` on the shell. Tabs: Search, Collections, About,
References, Metrics, Related resources. Files:
`controllers/data_center/overgo_search_modern.php`,
`templates/static/mgdb_overgo.bau`, `css/mgdb-overgo.css`,
`js/mgdb-overgo.js`.

The page had two search panels side by side, numbered `01` and `02`, each
with its own form, heading and eyebrow. They are one search bar now, with a
**Search by** select that decides which of the two legacy endpoints the form
posts to and what the field is allowed to contain. Everything else follows
the BAC and EST conversions: eyebrows, hero tagline and buttons gone, four
counted metric cards, a figure with a values table under it.

### The metrics were two measurements and two constants

The old cards read 13,430 · 10,644 · **25 bp** · **Archived**. The third was
the maximum length of a sequence *query* and the fourth was a word — both
described the page rather than the collection. The four now are 13,430 Overgo
probes, 10,644 searchable sequences, 1,199 placed on a bin map, and 1,476
distinct loci those placements detect.

Cold build **0.30 s** across four statements, warm **67–71 ms** with no SQL.
The counts are deliberately separate statements rather than one with
`COUNT(*) FILTER (WHERE EXISTS …)` columns — see the EST hub above for what
that shape costs.

### The name families are the archive's real structure

One `GROUP BY` over probe type and a `CASE` on the name prefix carries the
total, the split between the two collections, and the figure's five bars:

| Family | Collection | Records |
| --- | --- | --- |
| PCO | Unigene-Overgo | 5,753 |
| CL | Unigene-Overgo | 3,332 |
| SOG | Overgo | 2,769 |
| si | Unigene-Overgo | 1,559 |
| AOG | Overgo | 17 |

Each family sits entirely within one collection, so the bars are coloured by
collection and need no legend. Only two families are documented in the
database: a curator note says the AOG probes were designed from conserved
Arabidopsis sequences, and the si probes carry notes naming Incyte as the
source of the clones. **SOG, PCO and CL have no annotation memo at all**, and
the page does not guess what the letters stand for. A search of every memo on
these records for "sorghum" returns nothing.

### Four things the endpoints get wrong

Converting this hub meant reading the two legacy endpoints closely, and each
of the four findings is recorded in `ADMIN_DEPENDENCIES.md`:

- **AD-043, a live SQL injection.** `overgo_seq_results.php` concatenates the
  term into four `LIKE` clauses. The only sanitiser applied is
  `validate_input()`, which calls `validate_string()`, which is
  `return $input;`. Posting `term=A'` raises `SQLSTATE[42725]` in the log and
  shows the reader "no matching sequences". The hub validates `^[ACGT]{1,25}$`
  before posting, which protects a person using the page and does nothing for
  the endpoint's public URL.
- **AD-044, a page-size parameter that is read from the config instead.**
  `bac_results.php` calls `getPageSize('bac_pagesize')`; the overgo endpoints
  do `$pagesize = $system['pagesize']`. So this hub gets no records-per-page
  select — an unusable control is worse than a stated number — and the hint
  beside Maximum results says "25 to a page" instead.
- **AD-045, 569 sequences the sequence search cannot see.** Unigene-Overgo
  sequences are memo type 487260, "Sequence". The Overgo collection's are memo
  type 107404, "Sequence Note" — same 40 bp strings, different type — and the
  query hard-codes the former. The metric card counts what the search can
  actually reach rather than every sequence in the archive.
- **AD-046, a missing `urldecode()`.** This one was breaking the page's own
  examples. `est_results.php` and `bac_results.php` read
  `urldecode(getCGIParam('term'))`; the overgo endpoints do not. The old page
  sent `term: encodeURI(query)`, which jQuery then form-encodes, so `^CL10`
  reached the database as the literal `%5ECL10`: **41,803 bytes of results
  against none**. Every anchored or wildcard search on this hub had been
  silently empty. The page now sends the term raw, which is correct because
  `jQuery.post` already encodes the body. Pagination past page one still goes
  through `getSearchData()`, which encodes, so the `urldecode()` is still
  wanted.

The lesson generalises: three sibling hubs share one shared search script and
three endpoints that disagree about who decodes. Diff the endpoint against its
siblings, not just against the page.

### The tab offset ladder, measured rather than assumed

The shell steps its scroll offset to 113px below 1170px, which is 56px more
than this bar needs there and 48px less than it needs once the bar wraps.
Measured by resizing the real page: the six labels sit on one row (57px) down
to somewhere in 681–700, two rows (105px) down to somewhere in 376–399, and
three rows (153px) below that. Each band takes the larger of the two heights
it could straddle, so every jump clears by exactly 8px at 1280, 800, 560 and
375 and no boundary can hide a heading.

### Verified

6 distinct section edge colours, no rule under a section title, five reference
cards, no duplicate `id`, every nav label matching its `<h2>`, results hidden
until a search, the figure unclipped at 1280 and 375, no horizontal overflow
at 375, and the five form controls sharing one line and one left edge at 1280
and stacking to one full-width column at 375. The search itself was driven
with a stubbed `jQuery.post`: name mode posts to
`/search/overgo/overgo_results.php`, sequence mode to
`/search/overgo_seq/overgo_seq_results.php`, ` gcat agga actg ` normalises to
`GCATAGGAACTG`, `ACGTX` never reaches the network, and `#ovterm` carries the
cleaned query so the legacy pagination scripts still find it.

## The EST Data Hub

`/data_center/est` on the shell. Tabs: Search, Mapped ESTs, About,
References, Metrics, Related resources. Files:
`controllers/data_center/est_search_modern.php`,
`templates/static/mgdb_est.bau`, `css/mgdb-est.css`, `js/mgdb-est.js`.

The search itself is untouched — it still posts to the legacy `search.js`
trio, the same arrangement Cytogenetics and BAC kept. What changed is the
page around it: the eyebrows, the hero tagline and its buttons, and the
115px decorative `01` are gone; the four metric cards are counted rather
than asserted; and a figure and a values table now stand where the page
used to describe the collection in prose.

### The three-count split, 50 s to 0.9 s

The metrics are three numbers over the same corpus — every EST, those with
an external accession, those placed on a bin map — so the obvious build is
one pass with two filtered counts:

```sql
SELECT COUNT(*),
       COUNT(*) FILTER (WHERE EXISTS (SELECT 1 FROM mgdb.ext_db_key k WHERE k.id = p.id)),
       COUNT(*) FILTER (WHERE EXISTS (SELECT 1 FROM mgdb.probe_bin pb WHERE pb.id = p.id))
FROM mgdb.probe p JOIN mgdb.id_num i ON i.id = p.id
WHERE p.type = 34 AND i.curation_lvl = 0;
```

That took **50 seconds**. An `EXISTS` inside an aggregate `FILTER` is not a
semi-join Postgres can plan; it is a correlated subquery re-executed for
each of the 59,308 candidate rows. Written as three separate statements,
each is a join the planner hashes once: **364 ms, 333 ms and 185 ms**,
identical numbers. The whole cold build, figure included, is **0.88 s**;
warm it is **65–72 ms** and issues no SQL, cached under
`est/stats_<mtime of the controller>`.

The lesson generalises past this page: `COUNT(*) FILTER (WHERE EXISTS …)`
reads like the one-pass version of several counts and is the opposite of
it. Two counts over the same base are still two queries.

### The chromosome comes from `floor()`, not a join

`mgdb.probe_bin.bin` is a numeric whose integer part is the chromosome and
whose fraction is the bin — `9.02` is bin 2 of chromosome 9. So the census
behind the figure groups on `floor(pb.bin)::int` and never touches
`linkage_group`. Ten bars, 1,967 mapped ESTs, chromosome 1 highest at 382.

The figure is a horizontal bar chart through `MGDB.chart()` with the
shared responsive-margin treatment, and the same values are repeated
underneath as `#est-chr-table` for anyone who cannot use the figure.

### Metrics

59,308 ESTs · 59,161 with an accession · 1,967 mapped · 10 chromosomes.
The first two being three digits apart is real: 147 EST records carry no
`ext_db_key` row.

### Verified

Distinct section edge colours 6/6, no rule under any section title, five
reference cards, no duplicate `id`, every nav label matching its `<h2>`,
results hidden until a search, figure text unclipped at 1280 and 375, tab
jumps clearing the bar at both widths (57px bar at 1280, 153px at 375),
no horizontal overflow at 375, and the four form controls sharing one line
and one left edge at 1280, one column at 375 — the alignment the BAC hub
was corrected to.

## The Map Data Hub

`/data_center/map` joined the shell on 2026-09-01. It is the page `/data_center/map2`
was a tinted copy of, so the conversion retires that comparison.

### Three correlated subqueries became one lateral

The locus count and the coordinate range came from three separate correlated
subqueries over `mgdb.locus_coordinates`, which has 738,826 rows. Postgres ran
all three per candidate row, and because two of the sort orders cannot use an
index the candidate set was often the whole corpus rather than the 25 rows being
returned. One `LEFT JOIN LATERAL` computes all three in a single pass per row.

Verified identical — same ids, counts and coordinate bounds — across six terms
and all three sort orders, eighteen combinations:

| query | before | after |
| --- | --- | --- |
| no term, name sort | 15,914 ms | **245 ms** |
| `NAM`, name sort | 3,494 ms | **251 ms** |
| `ISU`, name sort | 3,092 ms | **253 ms** |
| `UMC 98`, loci sort | 934 ms | **31 ms** |
| `IBM2`, name sort | 884 ms | **251 ms** |

End to end through the API, `IBM2` went 485 ms → 220 ms and the unfiltered
listing 1,097 ms → 494 ms. The count query is left alone: on a 2,192-row table
it costs 38 ms, so the probe trick the other hubs use would buy nothing.

### `/data_center/map2` is retired

map2 existed to hold the tinted ground beside the untinted one. The tint is the
standard now, so the branch that loaded `css/mgdb-hub-tinted.css` for that route
is gone. See **Retired comparison routes** below.

### A grid floor that could not shrink

The tinted sheet's form fix carried `minmax(220px, 1fr) minmax(260px, 1fr)
minmax(190px, auto)`. Those floors sum to 670px, which a grid cannot shrink
below: on a 375px phone the form stayed **694px wide and the browser zoomed the
whole page to 0.52** to fit it. The floors are 0 now and a breakpoint stacks the
row before the fields get too narrow, which is what the floors were reaching for.

Worth noting how it was found: the usual `visualViewport` check reported 727 and
so did `innerWidth`, which looks like agreement. `document.documentElement.clientWidth`
was the one still reading 375, and `visualViewport.scale` was 0.52. **On a page
zoomed out to fit overflow, `visualViewport.width` inflates too** — compare
against `clientWidth` and check the scale.

### One bad DOI in the shared bibliography

`data/cite_journal_articles.json` had one record whose `doi` held a
Bauplan-escaped `https\://doi.org/10.1155/2011/373875` instead of a bare DOI.
`/cite` printed it as-is and the reference renderer would have pasted it onto
`https://doi.org/` to make a broken link. The record is fixed, the renderer now
normalises a DOI that arrives as a URL, and the file was added to
`deploy/manifest.txt` — it had never been in it, so edits to it were not
deployable.

## The BAC Data Hub

`/data_center/bac` joined the shell on 2026-09-02. It is an explicitly archived
hub — the notice under the hero says so — and its search still runs through the
legacy AJAX helper, which works: a POST to `search/bac/bac_results.php` returns
results for `c0085K`.

### The fourth metric card was the word "Archived"

Three cards counted things; the fourth read **"Archived"** under the heading
"Collection status". That is not a metric, and the notice banner at the top of
the page already says it. It is a count now — **310,653 records carrying a
GenBank accession** — computed by the same rollup as the other three, so it
costs no extra query.

The figure is the three-way split by clone prefix, from counts the metric cards
already needed. It is the only place on the page that shows the **15,576**
records whose names begin with neither `b` nor `c`.

### `mgdb.zb_chr_v2_clone` is empty

The stats rollup UNIONs three arms, the third joining `locus` against
`zb_chr_v2_clone` to pick up loci named after a RefGen_v2 clone accession. That
table has **0 rows**, so the arm contributes nothing and costs a scan on every
cold cache build. It is **left in place deliberately**: if the v2 clone
placements are ever reloaded the arm matters again, and the query is behind
`dashboardCache`, so the cost is about a second a month. Recorded as AD-042 —
an empty `zb_chr_v2_clone` may itself be the finding.

### The sticky-tab offset ladder is per measured bar height, not per tab count

This is the correction to what the earlier hub sections say. The `scroll-margin-top`
ladder had been set from the number of tabs, on the assumption that six or seven
wrap to at most two rows. **Label length decides it, not count**: this hub has
six tabs and its bar still reaches three rows on a 375px screen, and sections
landed 29–40px *behind* it.

Checking every converted hub turned up something else worth knowing: three
sheets — pan-gene, QTL and locus — carry a `flex-wrap: nowrap; overflow-x: auto`
rule below 767px, so their tab bars stay on **one** row on a phone and never
needed a deeper step at all. A quick offline probe that rendered each hub's tab
labels under the shared CSS predicted the opposite for pan-gene, and following
it would have added 48px of dead space. **The probe was wrong because it did not
carry the per-hub overrides; the fix was to measure each page as it actually
renders.** Every hub has now been measured at 375 and every tab jump clears its
bar.

## The Cytogenetics Data Hub

`/data_center/cytogenetic` joined the shell on 2026-09-02. It is the one hub
with **no corpus and no search endpoint of its own**: cytogenetic material lives
in the map, locus, stock and image hubs, and this page is the route into them.
So there is no search bar here, and no invented one — what the page has instead
are expander cards that pull locus and stock records inline, and those are kept.

### The metrics were counting cards on the page

They read **10 / 3 / 9 / 3** under "Chromosome maps", "Landmark classes",
"Stock collections" and "Karyotype sets". Only the 10 was a measurement: the 3
and the 9 were *the number of expander cards further down this page*, and the
second 3 the number of karyotype cards. They would have gone stale the moment
someone added a card, and none of them counted any data.

All four are counted now, and they are the same four ideas properly sourced:

| card | was | is | from |
| --- | --- | --- | --- |
| Cytogenetic Maps | 10 | **20** | cytological, FISH and B chromosome maps |
| Landmark Loci | 3 | **183** | centromere, telomere, cytological structure, chromosomal segment |
| Cytogenetic Stocks | 9 | **4,109** | stocks of the ten structural-variant types |
| Variant Types | 3 | **10** | how many such types there are |

The figure — stocks by variant type — reuses the same `GROUP BY` the stock
metric needed, so it costs no query of its own.

### Three dead links and a page that renders nothing

- **`/data_center/RNmaps`** returns HTTP 200 and renders an *empty page*: just
  a title, no content. It is the destination of the "View RN maps" link. There
  are also no recombination-nodule maps in `mgdb.map` at all, so there was
  nothing to point it at — the card now describes what RN data is and links the
  Morgan2McClintock Translator, which is the tool built on it.
- **The UMN oat–maize addition lines page** 404s while the site root is fine.
  The "Related historical resource" line pointing at it is gone.
- **The FISH card linked 1 of the 7 FISH maps.** `FSU Cytogenetic FISH` exists
  for chromosomes 1, 3, 4, 5, 6, 8 and 9; the card offered only chromosome 9.
  All seven are listed now.

One more the map metric turned up: the third B chromosome map is recorded as
`B RAPDs TBs 1997`, which the obvious `LIKE 'b chromosome %'` misses. The page
links it, so the count includes it — 19 became 20.

### Eight tabs need a third scroll-offset step

Every other hub has six or seven tabs and a two-step `scroll-margin-top` ladder:
57px on one row, 105px on two. Eight tabs wrap to **three** rows on a 375px
screen — a 153px bar — and the two-step ladder landed every section **29 to 40px
behind it**. There is a third step now, and the breakpoints are chosen from the
measured bar heights rather than guessed.

### Also

`https://m2m.dill-picl.org` does not resolve from the development server, so
the Morgan2McClintock link could not be verified either way. It is left where it
already was, and is deliberately *not* promoted into Related resources — an
unverifiable link should not be featured. The three large historical documents
now say how large they are: 8&nbsp;MB, 40&nbsp;MB, and a 57&nbsp;MB slide deck.

## The Reference Data Hub, where Metrics are query-driven

`/data_center/reference` joined the shell on 2026-09-02. Its back end needed
nothing: one combined query already returns the results, the facets and the
counts together, the unfiltered case is cached, and a search answers in
784&ndash;2,005 ms over 54,900 references.

What makes this hub different is worth stating plainly, because the shell is
designed around the opposite assumption.

### On every other hub, Metrics never move. Here they are the search.

The four metric cards and the four charts are recomputed from **whatever the
search matched** — not the corpus, and not the page. Searching `QTL` takes them
from 54,900 / 8,949 / 490 / 1865&ndash;2026 to **1,855 / 530 / 35 /
1987&ndash;2026**, and the year chart from 150 bars to 39.

That is genuinely useful and very easy to miss, precisely because the section
looks identical to the static Metrics on nine other hubs. Three things now say
so:

- a standing pill in the section heading: **"These update with your search"**;
- a scope line under it that states what is being counted *right now* —
  "These figures cover all 1,855 matching references for “QTL”, not just the 25
  listed above", or, with no query, "These figures cover the whole collection —
  all 54,900 curated references. Search above and they narrow to your matches";
- a visible busy state while the request that recomputes them is in flight:
  `aria-busy`, the grids at 45% opacity, and "Updating…" appended to the scope
  line. On a hub where a search takes a second or two, the numbers changing with
  no warning is the thing that makes people miss that they changed at all.

The scope line is rendered server side as well as by the script, so it is right
before any JavaScript runs.

### The shell would have hidden the one line that explained this

`.mgdb-hub-page .mgdb-section-heading > p { display: none }` is how blurbs were
removed from every hub in one rule. This page's only statement that the figures
follow the search was such a `<p>` — adopting the shell would have silently
deleted it. It lives outside the heading now, and the badge, which *is* inside
one, is exempted by name with a comment saying why.

### And the page filter deliberately does not touch them

The results filter narrows the rows on screen. It leaves the figures alone —
they describe the matched set, and a box that filters one page should not appear
to move them. The status line says which number is which.

## The Locus Data Hub

`/data_center/locus` joined the shell on 2026-09-02, completing the split begun
with the QTL hub. It searches 781,395 curated loci, and it was **the slowest
search on the site**: 3.9 to 11.2 seconds.

### Four conditions ORed, evaluated per row

The term clause was one `WHERE` with four arms:

```sql
LOWER(l.name) LIKE ? OR LOWER(l.full_name) LIKE ?
OR EXISTS (SELECT 1 FROM mgdb.synonyms   s  WHERE s.id = l.id  AND LOWER(s.synonyms) LIKE ?)
OR EXISTS (SELECT 1 FROM chado.gene_model gm WHERE gm.locus_id = l.id AND LOWER(gm.gene_name) LIKE ?)
```

ORed like that the two `EXISTS` clauses become correlated subqueries run once
per candidate locus — 790,208 of them — and no arm can use an index because
every pattern has a leading wildcard. Measured on `b1`: the four arms cost 395,
286, 613 and 418 ms **run separately**, and **3,323 ms ORed together**. As a
`UNION` of four independent arms joined back to `mgdb.locus`, each is one pass.

Two arms are also narrowed to rows that could possibly match: `mgdb.synonyms`
is 2.8M rows of which 437,245 belong to a locus, and `chado.gene_model` is 1.9M
rows of which **1,741,224 — 93% — have a NULL `locus_id`** and so can never
join. Both restrictions are free.

### And it all ran twice

`locusSearch()` built the matched set for a `COUNT`, then built it again for the
page. The page carries its own total through `COUNT(*) OVER ()` now — but only
when there is a term. **With no term the window function is the wrong tool**: it
has to materialise all 781,395 rows to count them, where a plain `COUNT(*)` is
an index-only scan. Measured on the unfiltered listing, 699 ms with a separate
count against 1,735 ms with the window. So both shapes are kept and which one
runs depends on whether the expensive join is in play.

| query | before | after |
| --- | --- | --- |
| `zm` &#40;23,916&#41; | 11,188 ms | **4,917 ms** |
| `a` &#40;58,975&#41; | 10,228 ms | **5,156 ms** |
| `b1` &#40;7,459&#41; | 6,351 ms | **2,253 ms** |
| `lg1` &#40;405&#41; | 4,868 ms | **826 ms** |
| `wx1` &#40;4&#41; | 3,870 ms | **833 ms** |
| no term | 699 ms | **643 ms** |
| `type=101` | — | **81 ms** |

Verified equivalent — totals *and* the full result rows — across 13 filter
shapes on two pages each, old implementation against new in one process. The
exact-match ordering term is bound now rather than interpolated with
hand-doubled quotes.

### The export was 200 rows of up to 781,395

`format=tsv` reused `LOCUS_MAX_RESULTS`, which is 200. A search matching 58,975
loci downloaded 200 of them and said nothing about the rest. A cap is still
right on a corpus this size, but it has to be useful and it has to be declared:
hydration turns out to be cheap next to building the matched set at all — 200
rows cost 2,275 ms and 7,459 rows 2,857 ms on the same query — so the cap is
`LOCUS_EXPORT_MAX = 10,000`, the API reports it, and when a search matches more
than that the button reads **"Export first 10,000"** with the full count in its
tooltip.

### A figure where nine bars are slivers

`Point` is 686,356 of the 781,395 loci — 88% — so on a linear axis every other
type renders 1–2px wide. That is the true shape of the corpus and the value
label beside each bar stays readable, so the chart is kept and the caption says
what is happening rather than leaving the reader to wonder.

### Also

Same two fixes as the QTL hub, which shares this page's history: the endpoint
took `limit`/`offset` where every other hub takes `page`/`page_size` and now
accepts both, and the hero's `Updated: $(data_date)` was `date('F j, Y')`
captured into the dashboard cache — the day the cache entry was built,
presented as though it meant something. Removed.

## The QTL Data Hub, split out from Loci

`/data_center/qtl` joined the shell on 2026-09-02, and stopped sharing a
navigation entry with the Locus hub at the same time.

### They were one hub in the menu, not in the code

The two pages have had their own controllers, templates, sheets and search
libraries for a while. What still treated them as one thing was the **Data
Centers menu in the header**, which carried a single entry —

    Loci & QTL → /data_center/locus   "Locus records, including QTL."

— so there was no way to reach the QTL hub from the site chrome at all. It is
two entries now, `Loci` and `QTL`, each pointing at its own hub, and the same
split is made in `tools/sitemap_data.py` and regenerated into the `/sitemap`
partials. The site search already had a separate `qtl_exp` category, so nothing
was needed there.

**What is deliberately *not* split is the data.** QTL loci are a locus type —
1,758 rows of `mgdb.locus` with `type = 25396`, alongside 686,356 Points and
26,115 Genes — and the Locus hub still returns them. A reader who searches a
QTL name there should find it. The QTL hub owns the *analyses*: 211 curated
trait analyses over 62 traits and 58 experiments, which live in
`mgdb.trait_analysis` and were never in the Locus hub's corpus.

### The export handed back 200 of 211

`format=tsv` set `$limit = QTL_MAX_RESULTS`, which is 200. The corpus is 211
analyses, so "Download TSV" silently returned 200 of them — and would have gone
on quietly truncating as the corpus grew. The export is the whole matched set
now &#40;`LIMIT ALL`&#41;; verified at 211 records with 211 distinct ids.

### Two pagination shapes

This endpoint took `limit`/`offset`; every other hub's search takes
`page`/`page_size`, which is what the shared results controls send. It now
accepts both and reports both, so the page could get the standard
10/25/50/all selector and real pagination without breaking any existing caller.

### A freshness stamp with no data behind it

The hero read `Updated: $(data_date)`, and `data_date` was `date('F j, Y')` —
*today's date*, captured into the dashboard cache. So it displayed neither the
data's age nor the current date, but the date the cache entry happened to be
built, presented as though it meant something. Removed rather than replaced:
there is no last-curated date available for this corpus, and no stamp is better
than one that describes nothing.

### The figure reuses a query the page already ran

Same trick as the marker, phenotype and pan-gene hubs: the trait filter's option
list is already a `GROUP BY` over the 211 analyses, which is exactly what an
"analyses by trait" chart needs. The chart costs no query of its own. The tail
is long — 52 of the 62 traits account for 84 analyses between them — so
everything past the tenth is one bar, carrying no id so a click on it is inert.

The corpus is small and the search was already fast &#40;12-16 ms&#41;, so there was no
SQL work to do here beyond the export.

### The QTL hub linked an id the record page could not read

Found 2026-09-06, from Carson: "the search results in QTL go to broken pages".
Every one of them did.

The hub searches `mgdb.trait_analysis` — one row per trait evaluated per
experiment, which is what its result table shows: `plht14`, plant height,
Abler 1991f. It built each result link as `/data_center/qtl?id=<trait_analysis
id>`. But `/data_center/qtl` reads `mgdb.qtl_exp`. **The two id spaces do not
overlap**, so every result led to "Qtl record not found" — and the legacy page
answered that with **HTTP 200**, so nothing on the site or in the logs showed a
broken link. Two different ids rendered byte-for-byte identical pages, which is
what makes it easy to miss: the page looked like a page.

The experiment link in the same table went to `/data_center/qtl_exp?id=`, which
resolved but was the legacy record for a row the hub already owns.

**What was built instead of repointing the link.** There was no modern record
page for either type, so Carson's call was to build the substantial one: the
**QTL experiment** record at `/data_center/qtl?id=`. The experiment is where the
mapping panel, marker summary, contributors, detected QTL and the study's own
caveats live; the trait analysis is five fields, all of which are a row of the
experiment's Traits evaluated table. One modern page therefore replaces two
legacy ones — `/data_center/qtl` and `/data_center/trait_analysis`.

**Both id spaces resolve.** `qtlResolveId()` tries the experiment's own id
first, then a trait analysis id, then name and synonym. A hub link keeps
carrying the analysis id, deliberately: sending the experiment id instead would
lose which trait the reader clicked. Arriving that way, the page names the
analysis in a notice and marks its row, rather than silently showing a record
whose title does not match the link that was followed.

**The page opens at the top.** The first version also called `scrollIntoView`
on the marked row, which landed the reader in the middle of the Traits
evaluated table before they had seen the experiment's name or its overview —
Carson: "going into a QTL record page moves to the Trait anchor instead of the
top of the page". A record page that does not start at its own title reads as
broken, and the reader has no way back to a heading they never saw. The notice
links down to the row instead, which leaves the jump as the reader's choice;
it lands the section heading 104px down, clear of the 57px sticky tab bar.

**Three rows cannot be linked at all, and are no longer offered as links.**
Of 211 curated analyses, 208 reach a curated experiment. Two record no
experiment (`anthsr1`, `maysin1`) and one belongs to an experiment held at
curation level 10. `buildNameCell` already renders an unlinked name when a row
has no `url`, so the fix was to stop emitting one — a name that is not a link
beats a link to a 404.

**Two silent defects in the legacy queries, fixed on the way past.** Its trait
evaluation query inner-joined `qtl_link_analysis`, so an analysis with no
linkage analysis vanished from the table (6 of 243) and one with two was listed
twice (2 of them). It is a LEFT JOIN with the linkage analyses aggregated here,
so every analysis appears exactly once carrying however many it has. Its bin
lookup took whatever row an unordered query returned first; it is ordered now.

**One query where the page ran four.** The legacy page fetched `top`,
`overview`, `annotations` and `detected_QTL_Loci` as separate Ajax calls, plus a
`cache_data.php` POST that answers **403**, and inside those ran a bin lookup
per locus, an environment lookup per trait and a person and term lookup per
contributor. The record page makes one request to `/api/v1/records/qtl/{id}`.

**A 420 ms join, from a cast in the wrong direction.** `qtl_link_exp.high_score_var`
is `numeric` and `variation.id` is `bigint`, so Postgres cast the *column* and
scanned all of `mgdb.variation` — 21,316 buffers for four rows. Casting the
value instead (`hv.id = le.high_score_var::bigint`) keeps the primary key: 8 ms.

### The Search QTLs button sat 26px below the search bar

The same fault as the stock hub a day earlier. The form is `align-items: end`
and the example line was a child of the query field, so the button
bottom-aligned to the field — label, input and examples — rather than to the
input. The 26px is exactly the line's 22px plus the field's 4px gap. The line
moved onto its own full-width row, which fixes it structurally: both are 44px
and land on each other, and the line can wrap at narrow widths without pushing
the button off again. Measured after: top 681 / bottom 725 for both at 1280 and
at 900, and at 375 the form stacks bar, button, examples with the page itself
not scrolling sideways.

**Not a bug:** a Plotly figure measured 700px wide at 375px during this work,
which looked like a responsive failure. It was an artifact of changing the
emulated viewport after the chart had been drawn — `Plotly.Plots.resize` is
debounced by 150ms. On a fresh 375px load the plot is 259px inside a 309px
container and the page does not scroll horizontally.

## The Stock Data Hub

`/data_center/stock` joined the shell on 2026-09-02, finishing the partial
conversion that was already in the working tree. Most of what this one turned
up was not styling: **three separate places where the page and its endpoint did
not agree**, each of which silently broke a feature.

### The result count was read from the wrong place

`mgdb-stock.js` read `data.total`. The endpoint has never returned a top-level
`total` — it is `summary.total`. So `state.totalRecords` was `undefined || 0`
on every search, which meant:

- the status line read **"Showing 1–0 of 0 stocks"** under 25 visible rows, and
- `Math.ceil(0 / 25) || 1` gave **one page** of pagination, so there was no way
  to reach result 26 of 7,841.

### The GRIN toggle sent a parameter nothing reads

The MaizeGDB/GRIN switch sent `source=grin`. The endpoint reads `mode`. So the
toggle changed the button styling and nothing else: pressing "GRIN accessions"
re-ran the MaizeGDB search. `b73` returned **7,841 MaizeGDB stocks** where it
should return **8 GRIN accessions**.

Underneath it, a second bug that could not surface while the first one hid it:
a GRIN row carries `grin_id`, not `id`, and both renderers built the outbound
link from `row.id` — so every GRIN link would have gone to `?id=undefined`.

### The advanced filters were never sent as filters

The five selects and three checkboxes were sent as bare values with no `mode`
and none of the `f_<name>` flags the endpoint gates each filter behind. A
filter-only search therefore hit the simple path, found no term, and answered
`no-term` with zero results. Selecting a stock type and pressing Search found
nothing, always.

All three are fixed against the contract in `search/stock/stock_search_lib.php`,
and verified: `mode=grin&term=b73` returns 8, and
`mode=advanced&f_type=1&type=9018241` returns 32,930 in 307 ms.

### 0.8% of the rows scanned could possibly match

The simple search matches `LOWER(col) LIKE '%term%'` against `mgdb.description`,
`mgdb.synonyms` and `mgdb.ext_db_key`. Those tables are shared by every entity
type in the database, but only rows belonging to a stock survive the join
further down — and `ext_db_key` is **17,822 stock rows out of 2,319,829**, 0.8%.
`synonyms` is 24.8%. Restricting the two big scans to stock ids inside the scan,
rather than after it, avoids materializing millions of rows that cannot match:

| term | before | after |
| --- | --- | --- |
| `a` &#40;27,820&#41; | 4,787 ms | **1,995 ms** |
| `mu` &#40;22,993&#41; | 3,758 ms | **2,936 ms** |
| `b73` &#40;7,841&#41; | 1,294 ms | **1,047 ms** |
| `Tp1` &#40;3&#41; | 913 ms | 938 ms |

`description` is left alone on purpose — 93% of it is already stocks, so the
test costs more than it saves. A selective term pays about 25 ms for this; the
broadest saves nearly three seconds. Verified identical on eight terms.

What it cannot fix is the floor: every one of these is a leading-wildcard
`LIKE`, so both large tables are scanned however few rows match — 551 ms and
328 ms on their own. `pg_trgm` is installed but the application role cannot
`CREATE INDEX`. Recorded as AD-039.

### And the results section was never hidden

The bootstrap ended with an unconditional `fetchResults(false)`, so the results
section — `hidden` in the markup like every other hub — was opened and filled
before the reader asked for anything. It now runs only when the address bar
carries a query to restore.

### Elsewhere

The chart used raw `Plotly.newPlot` with `margin: { l: 200 }`, which is most of
a 259px figure on a phone: the plot area was about 35px and the axis title was
clipped off the side of the box. It is on `MGDB.chart` now with margins sized
from the figure. Stock type names are long enough that shortening the tick text
was needed too — "Sequence-indexed insertion" is 26 characters — so the full
name stays in the hover and in the values table under the figure.

The hero emblem is the Maize Genetics COOP Stock Center's own ear, which was
sitting in `src/images/stock/` unreferenced and not in the manifest.

## The Pan-Gene Data Hub

`/pan_gene_center/pan_gene` joined the shell on 2026-09-02. It was already the
most capable search on the site — two modes, twelve advanced filters, a real
size-distribution figure — and also the slowest: **4.5 to 4.8 seconds** for an
advanced query.

### One `GROUP BY` instead of one `DISTINCT`

`chado.pan_gene_search` is a materialized view of **2,694,483 rows**: one per
pan-gene per member gene model per protein per trait. The advanced search
collapsed it with `SELECT DISTINCT` over the seven pan-gene-level columns —
including `loci`, an array — so every query hashed seven values across 2.7M rows.

Those seven columns are all constant within a `pan_gene_name`, so grouping on
that one short varchar and taking `min()` of the rest is exactly equivalent and
hashes one value instead of seven. **That is checked, not assumed:** no
`pan_gene_name` in the view carries more than one distinct `pan_gene_analysis`,
`pan_gene_count`, `exemplar_gene_model`, `assembly_count`, `max_annots` or
`loci`, and none mixes NULL with non-NULL.

### The join that could not matter

The same query joined `chado.pan_gene_assemblies` unconditionally, but only two
of the twelve filters — `appear` and `not_appear` — reference it. It could not
change the result set either: that table holds exactly one row for each of the
97,184 `pan_gene_name`s in the view, so the `INNER JOIN` neither filtered nor
multiplied. It is added now only when a filter needs it.

### And the count that ran the same query twice

The endpoint counted first and paged second, so both passes built the same
matched set. The probe pattern removes the count whenever a page comes back
short — which is every identifier lookup, the common case here.

| query | before | after |
| --- | --- | --- |
| `lg1` &#40;simple, 1 hit&#41; | 444 ms | **225 ms** |
| `Zm00001eb067740` | 446 ms | **233 ms** |
| `min=60&max=80` &#40;21,577&#41; | 4,500 ms | **1,013 ms** |
| `locus=1` &#40;23,708&#41; | 4,587 ms | **1,092 ms** |
| `min_annots=95` &#40;14,171&#41; | 4,797 ms | **1,395 ms** |
| `min=1&max=1` &#40;5&#41; | — | **5 ms** |

Verified equivalent across **16 filter combinations**, each on four sort orders
and two pages — counts and the full result rows, old SQL against new, in one
process. That includes both filters that use the join &#40;`appear` 29,378,
`not_appear` 67,806, and the two combined 12,530&#41;.

### The page itself ran everything live

There was no `dashboardCache` at all: every request re-read the analysis
metadata, the 66 annotation rows, the size distribution, and — to fill a
dropdown with **one** option — a `DISTINCT` over all 2.7M rows, 131 ms to return
a single value. The whole payload is cached now, keyed with the controller's
mtime. **250 ms → 78 ms.**

### A stamp that contradicted the page under it

The hero read "Updated: January 2026" as a hard-coded literal, on a page
describing an analysis named `Pan-Zea, Aug 2025` and executed `2025-08-18`. It
is derived from the analysis date now, and reads August 2025.

### Two elements shared an id

The section the tab bar links to, `id="pan-gene-analysis"`, and the advanced
form's analysis dropdown had **the same id**. `querySelector('#pan-gene-analysis')`
returns the first in document order, so the "The analysis" tab scrolled to the
select in the search form instead of the section. The select is
`pan-gene-analysis-filter` now. Worth adding to the check list: enumerate
`[id]` and look for duplicates, which is how this surfaced.

### Also

The count reported at the top of the page &#40;97,202, summed from the pipeline's
own distribution table&#41; is 18 higher than the number of pan-genes the search can
actually return &#40;97,184 in the searchable view&#41;. Both are defensible readings
and the application cannot tell which is authoritative, so the number is
unchanged and the discrepancy is recorded as AD-038.

The bespoke green hero this page carried was dropped for the shared one, and a
Related resources card pointing at `/pan_effect` was **not** added after
checking: that route is dead, and `/pan_gene_center/pan_effect` merely re-renders
the pan-gene search. It links `/genomebrowser` instead.

## The Phenotype Data Hub

`/data_center/phenotype` joined the shell on 2026-09-02. **Its back end was
already fast** — 1,190 curated phenotypes, searches answering in 17–37 ms — so
almost nothing here is about speed. It is about counts and labels that did not
match, which is a different kind of wrong and a quieter one.

### Three metric cards named one thing and counted another

The four cards read 1,190 / 733 / 896 / 20,916 under the headings **Curated
Phenotypes**, **Trait Categories**, **Anatomical Structures** and **Linked
Stocks**. Two of those numbers do not measure what their heading says:

| heading | showed | which is | the heading's number |
| --- | --- | --- | --- |
| Trait Categories | 733 | phenotypes carrying a trait | **256** categories |
| Anatomical Structures | 896 | phenotypes naming a structure | **70** structures |

Both are real numbers, and both are interesting — they just are not categories
and structures. The cards now show the counts their headings name, and the
per-phenotype figures became the context line underneath: "Trait Ontology
categories in use, classifying 733 of these phenotypes."

The fourth card was right, and now says so more precisely: 20,916 is distinct
*stocks*, and the line under it adds that they cover 658 of the phenotypes.

### The fallback numbers were fabricated and stale

`getPhenotypeCorpusStats()` wrapped its queries in a try/catch that fell back to
hard-coded values: `1190 / 709 / 780 / 39769`. Three of the four had drifted
from the live values, the stock count by almost half — a database outage would
have printed **39,769 linked stocks** with the same confidence as the real
20,916. The fallback is gone. A page that cannot reach the database should not
print a confident wrong number.

### `embryo` was two options, and picking one lost the other

Two `term` rows are named `embryo`, ids `11087` and `983212`, both of type
Body Part, carrying 18 and 2 phenotypes. The filter was built one option per
term id, so it offered **`embryo (18)` and `embryo (2)`** — two identical
labels — and choosing either silently missed the other's phenotypes.

Both filter lists are grouped by term *name* now, so the ids collapse into one
option carrying both &#40;`value="11087,983212"`, labelled `embryo (20)`&#41;, and the
search takes an id list rather than a single id. Verified: `part=11087` returns
18, `part=983212` returns 2, and the merged value returns 20. Recorded as
AD-063, along with a phenotype trait id that has no `term` row at all.

### The figure reuses a query the page already ran

Same trick as the marker hub: the plant-structure filter's option list is
already a `GROUP BY` over `phenotype_body_parts`, which is exactly what a
"phenotypes by plant structure" chart needs. The chart costs no query of its
own. Structures past the tenth roll into one bar that carries no ids, which is
what makes clicking it inert.

Worth noting what that bar shows: the 60 remaining structures total 346, more
than `leaf` at 174. The longest bar in the figure is the tail, which is honest
and is the most interesting thing in it.

### Elsewhere: two routes that 404 with HTTP 200

Checking this page's outbound links turned up `/data_center/genome` — dead,
serving the generic "MaizeGDB genome Search Page" 404 body **under HTTP 200** —
linked from the Related resources of *seven* redesigned pages. `/data_center/mp`
is dead the same way, in the two gene product record templates. Also dead:
`/data_center/phenotypeTerms` and `/tools/ems-phenotype`, both on this page.

All are repointed: `/genome`, `/metabolic_pathways`, Planteome's ontology
browser, and — for the EMS card, which has no live destination anywhere — a
Locus Data Hub card in its place. **A 404 served as a 200 is invisible to every
link checker**, which is why these survived; comparing response *sizes* is what
found them &#40;every dead data-center route answers at almost exactly 39.6 KB&#41;.
Recorded as AD-037, along with three more the same check found in the **shared
megamenu** — `/doc`, `/foldseek` and `/effect/maize_v2`. Those are deliberately
*not* repointed: there is no evident live destination for any of them, and
guessing in the navigation every page carries is worse than leaving them visible.

## The Marker Data Hub

`/data_center/marker` joined the shell on 2026-09-01. The corpus is the largest
any hub searches so far — **771,097 curated markers and probes** — and that size
is what shaped both changes worth recording.

### The count was costing more than the results

`marker_search_lib.php` has no trigram index to lean on (confirmed against
`pg_indexes`: there is none on `probe.name` or the synonym table), so every term
search is a sequential scan of the probe corpus, about 1,750 ms per pass. The
API ran two of them — one for the page, one for `COUNT(*)` — to answer a query
that returns 25 rows.

The probe pattern the other hubs use applies directly: fetch `page_size + 1`
rows, and when fewer come back than were asked for, the page is the last one and
the total is `offset + count(rows)` — no second scan. When the page *is* full the
count still runs, so broad terms are unchanged.

| query | before | after | note |
| --- | --- | --- | --- |
| `umc1013` | 3,543 ms | **1,780 ms** | count skipped, `cache: derived` |
| `bnlg1867` | 3,540 ms | **1,776 ms** | count skipped, `cache: derived` |
| `bnlg1` (311 hits) | 3,604 ms | 3,604 ms | page full, count still needed |
| no term | 689 ms | **56 ms** | `cache: hit` |

Totals were compared before and after on every query used: 1,972 / 311 / 162 /
771,097, unchanged.

### The figure reuses a query the page already ran

The type filter's `<option>` list came from a `GROUP BY` over all 771,000
probes. That result is also exactly what a "markers by type" chart needs, so
`getMarkerTypeOptions()` was split into `getMarkerTypeRows()` — cached once —
plus `renderMarkerTypeOptions()` and `markerTypeChartData()` reading from it.
**The chart costs no query of its own.**

The distribution spans six orders of magnitude — 430,550 BAC clones down to a
single dCAPS marker — so the fourteen types past the tenth are rolled into one
"other types" bar rather than drawn as fourteen invisible slivers. That bar is
given no id, which is what stops a click on it from filtering the search. The
census also double-checks the metric: the 24 type counts sum to 771,097 exactly.

### The cache key had to grow the controller mtime

`dashboardCache()` keys on the string it is handed plus a global stamp — it does
**not** fold in the caller's mtime. Marker passed a bare `'marker/page'`, which
was fine while the payload was four integers from SQL. Adding `type_rows` changed
the payload's shape, and a warm server would have kept serving an entry that
predates the key, leaving the chart with nothing to draw. The key is now
`'marker/page_' . (int) @filemtime(__FILE__)`, matching `/insertion`. **Any hub
whose cached payload is shaped by controller code needs the mtime in its key.**

### Fixed chart margins are a phone-sized bug

`MGDB.chart` re-runs `Plotly.Plots.resize` on a window resize, which rescales a
figure but keeps **the margins it was drawn with**. This chart was drawn with
`margin: { l: 150, r: 96 }` — a label gutter and room for the value labels that
sit outside the bars. That is 246px of a 259px figure on a 375px phone: the plot
area was 13px and **every bar rendered as a 1px sliver**, with no error anywhere.

The margins are computed from the figure's own width now, and crossing the
breakpoint issues a `Plotly.relayout`. Below 560px the gutter shrinks, the
outside value labels come off (the table underneath carries every number), and
the axis switches to `~s` with `nticks: 3` — as full thousands, even as `100k`,
five ticks collide and Plotly silently turns them vertical, costing ~120px of
height. Measured: bars went from 1–12px wide to 1–109px.

Worth carrying to the remaining hubs: **a chart that passes at 1280 has not been
checked.** Measure bar widths and text bounding boxes against the SVG box at 375
as well.

### A field the form showed but the query ignored

The submit handler read the term and the type select out of the DOM but took the
advanced bin from `state`, which only a `change` event on that input ever set.
`change` does not fire for a value the *browser* restores — autofill, or coming
back through history — so the form could display a bin that was silently absent
from the query it described. The handler reads all three fields from the DOM now.

The API's `page_size` default was 24 against a UI that offers 10/25/50; it is 25
now, so an unparameterized call and the default UI state agree.

## The Insertion Data Hub

`/insertion` joined the shell on 2026-09-01. **Its back end needed nothing** —
the page was already behind `dashboardCache` at 66 ms and the search endpoint
answers a gene query in 132 ms with parameterized, capped queries. That is worth
saying: the conversions before it all turned up something slow, and this one did
not.

### Three modes stay three modes

Every other hub has one query field. This one has three — by gene model, by
genome position, by insertion name — and they are three different questions, not
three filters on one, so the mode tab set stays. What moved is everything
underneath: **collection, background and gene structure were duplicated across
the gene and region panels** and are now a single advanced panel below all
three, which also halved the background-sync wiring in the page script.

### The results table pages in the browser

The endpoint answers a whole search at once — it is bounded by the caps in
`insertion_search_lib.php` rather than paged — so the 10 / 25 / 50 / all control
and the filter work on the rendered rows. Paging is therefore instant, and the
TSV export still covers the whole result rather than the visible page. The
status line is written in two parts: the grouping line by `updateView`, then
what the page controls did to it, so the two cannot contradict each other.

The figure is the structure breakdown the summary file already carried —
`5'UTR`, `intron`, `proximal promoter` and the rest, in the database's own
casing. Selecting a bar sets the gene-structure filter, opens the advanced
panel, and switches to the gene mode that filter applies to.

## The Image Data Hub

`/data_center/image` joined the shell on 2026-09-01. Beyond the usual
conversion — six eyebrows and their blurbs out, tab labels matched to headings,
References added, "Image dataset at a glance" renamed to Metrics with a figure
after it — three things are worth recording.

### The page was paying two seconds for numbers that never change

`getImageCorpusStats()` ran live on every request: a `COUNT` over `web_image`
at 904 ms and a `GROUP BY` over the same join at 1,048 ms. **1,952 ms of a
2,132 ms page**, repeated for every visitor, for six figures that change only
when the database is reloaded. Behind `dashboardCache()` the page renders in
**73 ms**. Every other hub had this; this one had been missed.

### The gallery loaded itself before anyone asked

The page ran a search on load — 24 images, about two seconds — whether or not
the reader wanted any. The results section now carries `hidden` and the search
runs when there is something to search for: a term, a category in the URL, a
category card, or a bar in the figure.

### The category pill bar became a filter

The search panel carried a seven-button pill bar duplicating what the Categories
section below already offers. The pills are gone; the category lives in the
advanced panel like every other hub's secondary filter, and the category cards
and the figure both drive it, opening the panel so the filter is visible rather
than applied invisibly.

### What could not be fixed from here

A term search still costs about two seconds, and it is worth knowing why.
`wi.caption ILIKE '%term%'` cannot use an index, so every search scans the
113,851-row archive. Caption-only counts run in 41–64 ms, so the index would
buy nearly all of it — that is filed as **AD-035**. What the application could
do has been done: the redundant `COUNT` is skipped whenever a page is not full,
which took `teosinte` from 3,991 ms to 1,924 ms.

**The entity-name arms are not redundant.** Dropping them would make the search
fast and wrong: `umc90` finds 46 images through them and `B73` finds 3,507.
Checked before assuming, the same way the gene product hub's tiering was
checked and rejected.

Also fixed: `page_size` was clamped to a floor of 12, so the hub's smallest page
of 10 silently returned 12 rows and the pagination disagreed with the screen.

## The Gene Product Data Hub

`/data_center/gene_product` was brought onto the shell on 2026-09-01. It was in
better shape than most — already using `.mgdb-section-heading` and the shared
resource panel — so the work was the shell opt-in, five eyebrows and their
blurbs out, tab labels matched to their headings, the results section hidden
until a search runs and given a sortable table with pagination, a References
section, Corpus Statistics renamed to Metrics with a figure after it, and
Related resources trimmed from six to five.

### What could and could not be made faster

The search takes about 1.2 s, and it is worth recording why most of that stays.
`gene_product` holds only **2,474 rows**, but the term predicate is five
correlated `EXISTS` subqueries and two of them reach into `mgdb.locus` and
`chado.gene_model`. Measured on `kinase`: the name, synonym and EC arms cost
7 ms, 19 ms and 2 ms; the locus arm costs 134 ms and the gene-model arm 434 ms.

Two rewrites were tried and **rejected**:

- **As an uncorrelated `UNION` of the five arms** it returned identical counts
  on every term but ran twice as slow — 1,090 ms against 550 — because the arm
  over `chado.gene_model` then scans all 1.9 million rows instead of stopping
  early.
- **Running the three cheap arms first and the two expensive ones only on a
  miss** is 13 ms against 560, and wrong. A short locus-like term matches both
  sides: `b1` returns 763 products through the full predicate and 18 through the
  cheap arms. `a1`, `p1` and `o2` lose 76, 166 and 25. It would have been a
  silent, plausible-looking wrong answer.

What did work is the same probe the expression hub uses: fetch one row past the
page, let a short page report its own total, and pay for the `COUNT` only when
the page comes back full. The count and the id query cost the same, so that is
half the request. `alcohol dehydrogenase` went 1,200 ms → **647 ms**, `1.1.1.1`
1,256 ms → **693 ms**; full pages such as `kinase` and `b1` stay at ~1.2 s,
which is what they honestly cost.

### And a metric that was a constant

`total_assemblies`-style hardcoding turned up here too: the class filter's
GROUP BY already knew there are 27 functional classes, so one query now feeds
the filter, the hero line, the metric and the figure.

## The Genome Data Hub on the shell

`/genome` moved onto `css/mgdb-hub.css` on 2026-09-01, the same pass that
built the gene product record page. `/genome2`, the tinted comparison
variant, now renders identically because the tint it added is the shell.

What changed, so the next hub can copy it:

- **Section order is the hub order**: Search, Assembly statistics, Assemblies
  in progress, References, Metrics, Related resources. The two chart sections
  ("Genome datasets hosted", "Assembly counts by species") became the figures
  under Metrics, with their captions and the growth data note intact. Tab
  labels are the headings, shortened.
- **Search carries example buttons and an Advanced search disclosure** holding
  the status and quality filters and the species chips. The list stays on the
  page rather than hidden until a search runs: the assembly list *is* the
  collection, and the field filters it live.
- **Every list is a collection**: the assembly table and the in-progress table
  are read once from the server-rendered rows by `collectionFromTable()` in
  `js/mgdb-genome-center.js`, then rendered as a sortable table (default) or a
  grid of the same rows, with a page size of 10 / 25 / 50 / all and a TSV
  download. Without script the full table stays. The prototype statistics
  table gained the same view toggle, page size and pager on its own state.
- **A References section** names five DOIs from the curated bibliography (the
  26 NAM genomes, gapless chromosomes, W22, the pan-genomic database paper,
  GenomeQC) through `mgdb_render_references()`.
- **The history chart is the Codex `/genome2` drawing**, ported on
  2026-09-01: a hand-built SVG rather than Plotly, with a green area fill,
  gold landmark pins on stems, a crosshair tooltip, and a Year / Assemblies /
  Change / Landmark table behind a Show data table button. It spans the full
  width of the Metrics section, and the species chart stacks below it. The
  series, the landmark labels and their label heights all come from the
  controller as JSON, so the drawing decides nothing about the data.

### The release date column still cannot drive the growth chart

Checked again on 2026-09-01, because the column was said to have been updated.
It has not been, and the chart stays on the curated record:

| Source | Dated | Of |
| --- | --- | --- |
| `genome_metadata.release_date` | 44 | 161 |
| `analysisprop` `assembly_date` | 130 | 160 |
| Both, earliest wins | 132 | 160 |

Even the combined 82.5% is under the 90% floor `GC_GROWTH_COVERAGE_FLOOR`
sets, and the series it produces is visibly wrong: it reaches 132 by 2024 and
stops, because 28 assemblies carry no date anywhere — every G2F draft, most
of the CAAS founder lines, W22 v2, LH244 and the B chromosome. `release_date`
itself is 124 blank rows out of 170, and what is there is free text:
`19-Nov-25`, `1st of February 2017 (pre-release)`, `fall 2017`,
`11/19/202525`.

What did change: **the final point is now the live published total** rather
than the hand-kept 158, so the curve ends where the Metrics cards say it
does. The rest of the curated series is unchanged, and the data note under
the chart says so.
- **Bulk Downloads is marked External** — it carries its own host.
- **The page sheet lost what the shell owns**: its own sticky-tab styling, its
  metric top-edge block (which fought the shell's 4px edge with a 5px one),
  the section-blurb rules, and the phantom `.mgdb-chart-tall`. The species
  chart is sized by `sizeChart()`; the growth chart no longer sets `legend.y`.
- **The sticky identity column of the statistics table paints its own zebra
  band**, or the striped rows show white where the column overlaps them.

No API row: the hub has no record API, and that row belongs to record pages.

## The Expression Data Hub

`/expression` was brought onto the shell on 2026-09-01: sections converted from
`.mgdb-panel` to plain cards, blurbs removed, tab labels matched to their
headings, the results section hidden until a search runs and given pagination,
a References section added, and the metrics renamed with a figure after them.
Two things are worth recording beyond the conversion.

### The lookup was doing three times the work it needed

`expressionSearch()` matched the locus full name through
`EXISTS (SELECT 1 FROM mgdb.locus l WHERE l.id = gm.locus_id AND LOWER(l.full_name) LIKE ?)`.
`chado.gene_model` already **carries `locus_full_name` denormalised**, and the
two agree on all 1,878,920 rows — checked, zero disagreements. Reading the
column instead takes the count for a term like `adh1` from **1,340 ms to
468 ms**, with identical counts on every term tested, and lets the `LEFT JOIN`
come out of the row query too.

The other half was the `COUNT(*)`, which cost about as much as the page it was
counting and ran on every search. The query now fetches **one row past the
page**: a short page reports its own total \(`offset + rows`\) and only a full
one pays for the count. Measured end to end through the API:

| term | before | after |
| --- | --- | --- |
| `adh1` \(6 results\) | 2,724 ms | **489 ms** |
| `kn1` \(12\) | ~2,700 ms | **484 ms** |
| `Zm00001eb056510` \(1\) | ~2,700 ms | **491 ms** |
| `o2` \(194, full page\) | ~2,700 ms | **940 ms** |

### The metric that was a constant

`total_assemblies` was `29` written into the library with a comment explaining
the arithmetic. The GROUP BY that builds the assembly filter already knows the
answer — it is 36 — so the count comes from there now, and the same query feeds
the filter, the metric and the figure instead of running twice.

## The AI & Machine Learning Data Hub

`/ai` is built on `css/mgdb-hub.css`, the generalised Data Hub shell: it puts
`mgdb-hub-page` on `<main>` and takes the pale blue ground, the white section
cards, the coloured metric top edges, the gold edge a grid tile lifts on hover,
the zebra striping, the green Related resources panel, the shared form field and
the scroll offset from there. `css/mgdb-ai.css` describes only the page's own
furniture and repeats none of it.

It was first built against `css/mgdb-hub-tinted.css`, the sheet `/genome2` and
`/data_center/map2` load, and moved once the generalised shell turned up. The
two differ in one visible way: the tinted sheet leaves each `<section>`
transparent and lifts only the boxes inside it, while the shell makes the
section itself the white card. The second is what "make the individual sections
have white backgrounds" asks for, and it is the one a new hub should use — the
tinted sheet names every page it touches and stays with the comparison pairs.

**Its collection is a catalog, not a table, so the page runs no SQL at all.**
`data/ai/ai_resources.json` is the single source of truth for 27 resources —
8 analysis tools, 8 AI-ready datasets, 5 code repositories, 6 publications —
and `controllers/ai.php` derives four things from that one file:

```
the cards in the four sections     rendered server side, so the page reads with scripting off
the four metric values             counts of the catalog's own categories
the "resources by data type" series  each resource counted once per data type it covers
the client search index            name, summary, topics, genomes, link hosts, lowercased once
```

Adding a resource to the JSON therefore adds it to the section, the count, the
chart and the search together; there is no second list to keep in step. The
whole derivation is wrapped in `dashboardCache()`, so a warm page is one read
of a 39 KB cache entry — **about 60 ms**.

### The cache key carries both mtimes

`ai/page_<catalog mtime>_<controller mtime>`. Keying on the catalog alone was
tried first and is wrong: the *renderers* live in the controller, so editing a
card's markup left the page serving the old HTML with no sign anything had
happened. It cost a confusing ten minutes the first time. The cost of the two-
part key is that each edit orphans its predecessor, which
`tools/dashboard_cache.php --purge` already clears.

### Search

Twenty-seven rows is too few to be worth an HTTP round trip, so the index is
inlined as a JSON `<script>` block and the search runs in the client. All query
terms must match; where each one lands sets the rank — name, then summary, then
the keyword blob — and ties break by category, because someone searching
"structure" most likely wants a tool they can open before the paper about it.

The **link hosts** are folded into the haystack but not the paths: "github"
should find the five repositories, while the Box folder ids would only add
noise. The results table is hidden until a search runs, sorts on any column,
filters within itself, and pages at 10 / 25 / 50 / all.

A search is linkable: `/ai?q=embeddings`, or `/ai?topic=structure`, which is
also what selecting a bar in the figure does.

### External means "carries its own host"

One predicate — `ai_is_external()` — decides the arrow, the `target`, the `rel`
and the Internal/External chip, so a link cannot be labelled one way and behave
the other. It tests for a scheme and host, deliberately **not** for the
maizegdb.org domain: `snptools.maizegdb.org`, `feta.maizegdb.org` and
`mfs.maizegdb.org` are separate applications a reader leaves the site to reach,
and a first attempt that excluded the domain marked all three "Internal".

It renders its References through `include/references_lib.php` like every other
hub; see **The Data Hub shell** above for that component and for the section
top-edge colours.

### Two shared-CSS notes

- **`--mgdb-dur-fast` is defined nowhere.** Three page sheets use it inside a
  `transition`, which makes the whole declaration invalid — those transitions
  have never run. Written out here rather than propagated.
- **A hub sheet's flat `border-color` takes the top edge with it.** It is a
  shorthand, and both hub sheets set one on every white card. While `/ai` was
  still on `mgdb-hub-tinted.css` that silently flattened the three category
  colours on `.ai-card-tool` / `-data` / `-code` to the tint's blue-grey,
  because the shared sheet loads after the page sheet and the two selectors
  were the same specificity. On `mgdb-hub.css` the question does not arise: the
  shell owns the tile edge, neutral at rest and gold on hover, and the category
  is carried by the section the card is in.

While in `mgdb-hub-tinted.css`, its zebra rule was found to be cancelling the
table hover on every hub that loads it: `.mgdb-table tbody tr:nth-child(even)`
and `.mgdb-table tbody tr:hover` are the same specificity and the zebra comes
later, so even rows had no hover state. Fixed there, so `/genome2`,
`/data_center/map2` and `/data_center/stock2` get it too. `mgdb-hub.css`
already had the pair the right way round.

## AlphaFill ligand transplants

`/data_center/alphafill` publishes a proteome-wide AlphaFill 2.3.0 run over all
68,262 B73 RefGen_v5 AlphaFold models: **624,456 transplants**, collapsing 4.7x
to **133,489 gene x ligand pairs** across **16,933 genes**. It supplements the
protein structure hub rather than replacing anything -- that page answers what
shape a protein is, this one answers what it probably binds -- and the two
cross-link both ways.

```
controllers/data_center/alphafill_modern.php   the page. Runs zero SQL
templates/static/mgdb_alphafill.bau            its body
css/mgdb-alphafill.css  js/mgdb-alphafill.js   its assets
search/alphafill/alphafill_api.php             manifest·stats·suggest·gene·detail·ligand·targets
search/alphafill/alphafill_lib.php             the reads
search/alphafill/alphafill_download.php        four bulk files
tools/alphafill_index.py                       builds data/alphafill/
tools/alphafill_models.py                      extracts the AlphaFold models it serves
tools/alphafill_ligand_extract.py              runs on the cluster; splits filled mmCIFs
```

Reuses `js/lib/3dmol/` from the protein structure page.

### The three empty states

This is the thing the page exists to get right. A gene with no transplant is in
one of three situations and they are not the same fact:

| state | genes | what the page says |
| --- | ---: | --- |
| `transplant` | 16,933 | show it |
| `no_donor` | 21,427 | "no PDB homolog cleared the 25% identity floor -- **this is not evidence the protein binds nothing**" |
| `no_model` | 1,396 | "no AlphaFold model exists, so AlphaFill never saw it" |

The API returns the state and the page renders a different panel for each,
with its own colour. Collapsing them into "no results" is how an annotation
resource teaches people something false, and the middle one -- the large one --
is a statement about PDB coverage of maize protein families, not about maize.

### Three bugs in the source data, found by serving it

**1. `alphafill.by_gene.json` is not valid JSON.** It carries a bare `NaN` for
1,259 genes. Python's `json.loads` accepts that back, so it round-trips inside
the pipeline that wrote it and looks fine; PHP's `json_decode` and the browser's
`JSON.parse` both reject the whole file. It silently broke **1,076 of 4,096**
gene shards, 1,005 ligand files and `index.json` before it was caught. The
builder now scrubs non-finite floats and passes `allow_nan=False`, so a
recurrence is a build failure rather than a quarter of the site.

**2. The published pLDDT is the wrong isoform's.** 3,216 genes carry their
transplants on a non-canonical isoform, and the upstream table looked pLDDT up
under the *canonical* protein. Measured against the models themselves: for all
13,717 canonical genes the published value agrees exactly, and for the
non-canonical ones **85.5% disagree, by up to 46.3 pLDDT points**. Since pLDDT
>= 70 is part of the definition of the `strong` evidence class, that is not
cosmetic. `tools/alphafill_models.py` measures the real value from the
B-factor column of the model actually served, and the builder reports how many
it repaired (1,259 from NaN) and corrected (1,673 from the wrong isoform). The
evidence *classes* are deliberately left as published, so the site agrees with
the report the numbers appeared in.

**3. AlphaFill writes unquoted primes into mmCIF atom names.** `N1'`, `C4'`,
`C1'` -- and in CIF an unquoted apostrophe opens a string that never closes, so
a parser swallows the rest of the line. 3Dmol dies on it with a bare
`Cannot read properties of undefined (reading 'toUpperCase')` and renders
nothing. Primes are the atom-naming convention for sugars and nucleotides, so
the casualties are precisely the interesting compounds: over a 700-protein
sample it removed **13.1% of all ligand copies** and touched **half of all
proteins**, worst-hit ADP, ATP, AMP, GDP, GTP, FAD, UDP, SAH, FMN, SAM. The
extractor now quotes any value containing a quote character.

A fourth, already documented upstream, is real and was handled: AlphaFill writes
multi-character chain labels into `_atom_site.label_alt_id` once a protein
exceeds ~25 transplants, which strict parsers reject outright. The extractor
writes `.` into that column for *every* row, not only the long ones -- a
single-character value parses and then reads as an alternate conformation, which
a viewer may legitimately draw only one of. That failure mode is worse because
nothing errors; ligands simply do not appear.

### Why the viewer loads two files

Ligands are 4.6% of the atoms in a filled AlphaFill mmCIF, and the protein half
of every one is byte-identical to the AlphaFold model fed in. So the viewer
loads the model from `data/alphafill/models/` and the transplants from
`data/alphafill/lig/` as a coordinates-only mmCIF, split into one 3Dmol model
per `label_asym_id`. That is 4.9 GB down to 1.35 GB, and it is what lets pLDDT
colouring apply to the protein while each transplant keeps its own colour and
can be focused without re-fetching anything.

**The ESMFold models MaizeGDB already serves cannot substitute for the
AlphaFold ones.** For `Zm00001eb000660_P001` the two agree on sequence and
disagree by **1.53 A CA RMSD** after optimal superposition, and their B-factor
columns follow different conventions (AlphaFold per-residue constant, ESMFold
per-atom). The ligand coordinates are in the AlphaFold model's frame, and
1.53 A is the same magnitude as AlphaFill's entire benchmarked accuracy
(median 1.59 A pocket RMSD) -- so overlaying them on the ESMFold model would add
error as large as the signal, silently, and it would look plausible.

Models are stored heavy-atom only. They are Amber-relaxed, so 51% of their atoms
are hydrogens that no representation draws; dropping them halved the payload and
matched what the transplants were computed against.

### Query cost

Rendering the page runs **zero SQL**. Every headline number comes from
`data/alphafill/manifest.json`, and the cache key carries that file's mtime, so
rebuilding the payload invalidates the page's counts automatically -- the header
cannot disagree with the data its own search answers out of.

`suggest`, `gene`, `detail`, `ligand` and `targets` cost **no queries** on an
index hit. The database is reached only when the index has already missed, so
that `wx1` still resolves -- through the gene database to `Zm00001eb378140` --
and so that "not a maize gene" stays distinguishable from the three states
above. Measured on the development instance:

| action | elapsed | queries |
| --- | ---: | ---: |
| `suggest` (`zm0`, `flavin`, `nadp`) | 0-1 ms | 0 |
| `gene` (index hit) | 1 ms | 0 |
| `detail` (69 transplants) | 2 ms | 0 |
| `ligand` (NAD, 872 genes) | 2 ms | 0 |
| `targets` (1,954 genes) | 3 ms | 0 |

The typeahead reuses the adaptively split prefix index from
`tools/protein_structure_index.php` -- see that file for why an n-gram index
cannot work on a corpus where every identifier begins with the same nine
characters. Here it settles at 2,915 shards and 16 hot prefixes over 43,512
postings, and it indexes chemical *names* as well as codes, so "flavin" finds
FAD and "nadp" finds NAP and NDP.

### Two denominators, and saying which

Ions are **43.8% of the 624,456 transplants** but **29.7% of the 133,489 pairs**,
because one gene x ligand pair absorbs however many redundant placements came
from homologous donors. 43.8% is the number in the published report. The caveat
rail quotes the transplant basis and gives the pair basis in parentheses;
printing only the latter under the same sentence would look like the report was
wrong. The same applies to additives: 9.9% of transplants, 11.6% of pairs.

`p2rank_pockets.csv` has the same trap. Its `nearest_transplant_comp` is the
nearest transplant *at any distance* -- median 12.4 A -- so joining on it
without a cutoff labels thousands of pockets with a compound 30 A away. The
builder applies a 5 A cutoff, which leaves 5,152 of 132,378 pockets
ligand-supported, and puts that count in the manifest so the claim stays
auditable.

### Rebuilding the payload

`data/alphafill/` is **1.6 GB** and is neither in `deploy/manifest.txt` nor
built on the server -- the one place this differs from every other resource
page. Its source is a one-off research output that has never lived on a web
host. See **AD-028** for the full procedure, the Atlas paths, the file-mode trap
after unpacking, and the storage decision that needs an administrator.

One detail worth repeating here: structure files are served
`immutable, max-age=2592000`, so their URLs carry a `?v=<release>` stamp. Without
it Cloudflare serves a month-old copy of any file a rebuild corrects -- which it
did, during this build, and origin and CDN disagreed for long enough to be
confusing. `--release` overrides the stamp for a same-day rebuild.

## FATCAT structural ortholog comparison

`/fatcat` compares a maize protein with its closest matches in sorghum, rice,
soybean and Arabidopsis. Three methods each pick a top hit — DIAMOND by
sequence, Foldseek and FATCAT by structure — and **where they agree the ortholog
assignment is corroborated by two different kinds of evidence.**

```
controllers/fatcat.php                    the page. Runs zero SQL
templates/static/mgdb_fatcat.bau          its body
css/mgdb-fatcat.css  js/mgdb-fatcat.js    its assets
search/fatcat/fatcat_api.php              suggest · compare · alignment
search/fatcat/fatcat_lib.php              the upstream adapter and its cache
```

Reuses `js/lib/3dmol/` and, for the typeahead, `data/protein_structure/`.
Pre-redesign originals are archived in `legacy/fatcat/`.

### It is an adapter, not an index

The ortholog table lives inside the application at `fatcat.maizegdb.org` and
exists nowhere else — not in the database, not on codex, not in any export. So
this page fetches that page once per protein, parses it into JSON, and caches
it. That dependency is not new: the page this replaces was a 1,050px `<iframe>`
of the same service. What changes is the failure mode. An iframe that cannot
load leaves a blank rectangle in a page with no `<h1>` and the title "Welcome to
MaizeGDB"; a cached payload leaves a page that still has its documentation, its
search, its links and its last good answer.

**The load-bearing parse is the `loadFile` path in each viewer block**, not the
`var str = '<acc>'` beside it. The accession looks like the obvious anchor and
is not usable: some blocks put it before their `NGL.Stage` call and some after,
so pairing by proximity silently shifts every hit by one from the third viewer
onward. It produced a page where sorghum's Foldseek hit was a rice accession and
it looked entirely plausible. The `loadFile` path is definitionally correct — it
is the file the viewer actually loads — and it names the species directory, the
query, the target and the model version at once. Scores and annotations are
scraped more softly, so a failure there costs a blank metric rather than an
answer.

### Three things upstream that are broken, and are repaired here

**Every AlphaFold link on the live page is dead.** They point at `model_v3`;
EMBL-EBI is on **v6** and v1–v5 all 404, so the upstream viewer loads a 404 and
every download link goes nowhere. Rewritten to v6, with the version as a single
constant. Some accessions from the 2022 run have since been withdrawn from
UniProt and have no model at any version; confidence colouring is disabled for
those rather than silently doing nothing.

**The alignment files send no CORS header** and are typed
`application/vnd.palm`, so a browser on maizegdb.org cannot fetch them. They are
proxied — which is also where the RMSD is read out of the file's own REMARK
header, a number upstream computes, ships and never displays.

**The comparison the tool exists for was left to the reader.** Twelve accession
codes in twelve panels, and noticing that three methods agreed meant diffing
strings by eye. It is now a four-by-three matrix with the agreement computed:
cells matching the majority are filled, a dissenter is outlined, and each row
carries a verdict — `confirmed` (3/3), `supported` (2/3), `conflicting`
(three methods, three answers).

### The viewer, and the caveat it exists to enforce

The superposition is FATCAT's own twist output: chain A the ortholog, chain B
the maize protein rotated onto it. Three colourings:

| | shows | source |
| --- | --- | --- |
| chain | which backbone is which | the twist file |
| confidence | per-residue pLDDT | fetched from the two source models and mapped back by residue number — **the twist file has no B-factor column at all** |
| deviation | distance from each maize residue to the nearest ortholog backbone | computed in the browser |

Deviation is labelled as a nearest-CA distance rather than as the alignment's
own correspondence, because FATCAT's block correspondence is not in the file it
ships and implying otherwise would claim more than the data supports.

The reason confidence colouring is worth the two extra fetches is the caveat it
enforces: **two predicted structures diverging in a region that is low-pLDDT in
either of them says nothing about the proteins.** So the metrics panel reports
the mean pLDDT across the residues that diverge past 5 Å. On `bz1` against its
sorghum ortholog that is 41.9 — "low confidence, treat the divergence as
uncertain". Against the rice protein that FATCAT alone picked it is 91.2 —
"confidently modelled, the difference looks real". Same page, same reader, two
opposite conclusions that the old tool gave no way to reach.

### Query cost

Rendering runs **zero SQL** and makes no upstream request. `suggest` reads the
protein structure index — the identifiers are the same ones, so building a
second index would be duplicated bytes and a second thing to rebuild. `compare`
costs one upstream fetch on a miss and **1 ms** on a hit; `alignment` proxies
one file and caches it permanently, since a superposition never changes.

The cache lives at `<search_cache_path>/fatcat` and needs an SELinux label — a
directory created outside httpd's context is `user_home_t` and every write is
denied **silently**, so the page keeps working and simply never caches. The API
now reports `summary.cache_error` so the next occurrence is visible from the
network tab. See AD-029, which also records the three species that have
superpositions but no hit table, and what would remove the upstream dependency.

## TYPSimSelector

`/TYPSimSelector` ranks the USDA Ames maize inbred collection against one
chosen accession by identity by state.

```
controllers/TYPSimSelector.php                          the page. Runs zero SQL
templates/static/mgdb_typsimselector.bau                its body
css/mgdb-typsimselector.css  js/mgdb-typsimselector.js  its assets
search/typsimselector/typsimselector_search_api.php     the ranking and exports
search/typsimselector/typsimselector_search_lib.php     its queries
data/typsimselector/summary.json                        the collection counts
data/typsimselector/lines_curation.json                 3,679 accessions
data/typsimselector/lines_breeding.json                 2,831 lines
tools/typsimselector_index.php                          what writes all three
```

`controller.php` checks `controllers/<CONTROLLER>.php` before falling through
to `redirect.php`, so the top-level controller takes the route from
`controllers/tools/TYPSimSelector.php` without touching it. Rollback is
deleting the new controller. The originals are archived in
`legacy/typsimselector/`, along with two defects in them worth not repeating.

### Where the weight went

The page it replaces was **705 KB**, with no `<h1>`, no viewport, and the title
"Welcome to MaizeGDB". Almost all of that weight was four `<select>` elements —
the curation list twice and the breeding list twice, about 13,000 `<option>`
elements — built by four queries that ran on every page view whether or not
anyone opened a dropdown. One of them, `DISTINCT iid1` over the 4,005,865-row
`pidata.ames_merged`, is a 320 ms sequential scan.

Those lists are constants: the IBS matrices were computed once, in 2012, from a
fixed SNP export, and nothing writes to the tables. So they are built offline
into `data/typsimselector/` and fetched as static files, once, only after a
reader has chosen a dataset. The page is **53 KB** and renders with **zero
SQL**; a ranking costs three or four indexed queries and answers in 10–25 ms.

Rerun the index only if the `pidata` tables are ever reloaded:

```bash
ssh development-server 'cd <webroot> && php tools/typsimselector_index.php'
scp 'development-server:<webroot>/data/typsimselector/*.json' src/data/typsimselector/
./deploy/deploy.sh
```

### Three traps in pidata

All three are why the queries are shaped the way they are, and the first two are
bugs in the page being replaced.

**Every row of `pidata.snp_entry` exists exactly twice.** 8,952 rows, 4,476
ids, byte-identical pairs, no column distinguishing them. Any join to it
doubles unless it is read through `DISTINCT`.

**`pidata.custom_inventory` is keyed by `snp_entry_id`, not `inventory_id`.**
4,327 rows, 4,327 distinct `snp_entry_id`, but only 2,817 distinct
`inventory_id`. The legacy page joined on `inventory_id`, which is a
many-to-one collapse that happens to be harmless today only because the
duplicated inventory rows agree.

**`pidata.ames_merged` is the strict upper triangle.** 4,005,865 rows is
exactly `2831 * 2830 / 2`: no diagonal, and no pair stored in both orders. A
line has to be looked for in `iid1` and `iid2` both — and the last line in sort
order never appears in `iid1` at all, which is why the legacy dropdown, built
from `DISTINCT iid1`, was one line short. Its `dst` column is also
`character varying`, so it is cast before it is ordered.

`pidata.snp_entry_map`, by contrast, is a complete 4,476 × 4,476 square with a
diagonal of 1 and both directions in agreement, so one indexed scan of
`germplasm2_id` is the whole result set.

Neither `snp_entry` nor `custom_inventory` carries any index. Both are small
enough to hash — the joins cost about 2 ms — so nothing here waits on an
administrator, but an index on `snp_entry_id` in each would remove the hash.

### What the page adds

- **The replicate runs are reachable.** 347 curation accessions were genotyped
  more than once, one of them 28 times. Each run has its own row in the matrix
  and its own ranking. The old dropdown collapsed on the taxa string and kept
  the first id it saw, so every replicate after the first was unreachable from
  the interface while still appearing in results. The picker now offers the run.
- **Absent accessions are said, not guessed.** 829 of the 3,679 curation
  accessions carry the NCRPIS `TEMP` placeholder rather than a real accession
  number, all pointing at one shared inventory row. The old page linked them to
  GRIN anyway, every one to the same unrelated record — and, because the
  accession variables were never reset between rows, a row with no inventory
  match printed the previous row's accession.
- **A distribution, not just a list.** One aggregate over the same index range
  gives the five-number summary and a 50-bin histogram, so a reader can see
  whether a near-duplicate is genuinely unusual or whether the whole panel sits
  that close. The bins are fixed per dataset so two lines can be compared.
- **TSV and CSV exports** of the full ranking, up to 25,000 rows.
- Plotly is 3.6 MB and is fetched on first use rather than on page load,
  because the figure does not exist until a comparison has been run.

## The header search

The search field in the global chrome is shared by every modern page. Three
things about it are worth knowing before changing it.

**The category list carries an icon per data type.** `templates/home/search-box-modern.bau`
defines a single inline SVG sprite; the category listbox, the toggle, and the
suggestion rows all reference it with `<use>`, and the toggle retargets its own
`<use>` as the selection changes rather than keeping a second copy of the map in
script. The chip palette lives in one block of `css/mgdb-modern.css` keyed on
`[data-cat]`, whose values are the same category names the API returns. Adding a
data type means adding a `<symbol>`, a palette line, and a `cat` on the API item —
nothing else.

Colour is decoration. Every row keeps its label, the glyph shapes differ from one
another, and the selected category is marked with a check, so nothing is carried
by hue alone.

**The visible category and the submitted one can drift apart, and did.** The
native `<select>` is what submits; the styled toggle only mirrors it. Back-
forward navigation restores form controls *after* this script has run — the
document is reparsed and refilled once loading finishes, or it returns whole
from the back-forward cache — so the select came back holding the category that
was submitted while the toggle still showed the label it was built with. The
reader saw *All data*, typed a term, and was sent to a MaizeGDB ID lookup or
straight out to Google. `initCategory()` re-reads the select on `pageshow`,
which fires after restoration either way.

**A locus cannot be found by its own name through `all_text_search`.** That table
stores wx1's three names concatenated into the single token `gss1wx1waxy1`, so
neither `waxy` nor `waxy1` nor `Gss1` nor even `wx1` matches it there. The
autocomplete works around it with `acLocusNameLookup()`, which reads
`mgdb.locus.name`, `full_name`, and `plant_wide_gene_name` directly and unions in
the curated `mgdb.synonyms` list. Case-insensitivity is faked by probing four
spellings of the prefix, because the locus indexes are on the raw columns. See
AD-020 for the functional indexes that would collapse that to one probe.

**Gene models are not in `all_text_search` at all.** They live in
`chado.gene_model`, and a query shaped like an identifier is answered from there
directly.

A locus with gene models is presented as a gene and links to `/gene_center/gene/<name>`;
a locus without them stays a locus. The same rule is applied from both sides, so
a record never appears in both groups.

## The all-data search

`/search_engine/searchall` is the page the header search submits to. It has two
views over one dataset: an overview of the leading data types with the first few
records of each, and one data type at a time, paginated.

Files: `controllers/search_engine/searchall_modern.php` (shell),
`search/searchall/searchall_api.php` (JSON), `search/searchall/searchall_lib.php`
(queries and the type registry), `templates/static/mgdb_searchall.bau`,
`css/mgdb-searchall.css`, `js/mgdb-searchall.js`.

### What was wrong with the page it replaces

It ran `all_text_search.text LIKE '%term%'` — a leading wildcard, which no index
can serve — across 8,794,429 rows and 1.6 GB, then materialised every match into
PHP and built an unbounded `IN (...)` list per data type. Searching `b73`
returned **457,015 rows**, and the whole search ran again for every section the
reader opened. The reader saw a loading GIF until it finished.

The table already carried `idx_text_gin` on `to_tsvector('english', text)`. The
page was not using it.

### Three things make it fast

1. **Match through the GIN index**, the same path the header autocomplete uses,
   so the two agree on what matches.
2. **Materialise the match once per request** into a temp table keyed by source
   table. A request needs the same match set for the counts and again for every
   section; re-running the scan for each was the largest single cost on a broad
   term. `b73` went from 1,818 ms to 731 ms.
3. **Page the identifiers before joining** the display tables. Abstracts,
   journal names, and stock centers are then fetched for 25 rows instead of for
   every match — `maize` References fell from 1,428 ms to 115 ms.

| Search | Page it replaces | Shell | Results |
| --- | ---: | ---: | ---: |
| `waxy` | 666 ms / 327 rows | 53 ms | 69 ms |
| `wx1` | 634 ms / 1,385 rows | 53 ms | 89 ms |
| `dwarf` | 662 ms / 707 rows | 53 ms | 73 ms |
| `b73` | 1,791 ms / 457,015 rows | 54 ms | 727 ms |
| `maize` | 1,431 ms / 98,436 rows | 55 ms | 930 ms |

The shell runs no query at all, so the first paint never waits on the database.

### The type registry is the thing to edit

`saTypeRegistry()` in `searchall_lib.php` declares, per data type, which
`all_text_search.table_name` values can produce it, which record table to join,
and which card layout to render. **A text source that is not in some type's
`sources` is invisible to the search.** The map was derived by cross-tabulating
`table_name` against `id_num.type_term` over the whole corpus and is recorded in
AD-062 — `table_name` is the table the *text* came from, not the type of the
record, and the two are not the same.

### Volume is bounded everywhere

Five rows per overview section, 25 per page, 200 pages maximum. And `memo`
(1.76 M rows) and `map_scores` (312 K) are out of the default corpus: they are
commentary, not identity, and with memos in scope `b73` reports 169,742 loci —
one for every locus whose comment mentions the reference line. A checkbox turns
them back on.

### Each data type gets the layout its records need

A reference is a publication card with authors, journal, year, and a three-line
abstract preview from `mgdb.reference_abstract`, plus DOI and PubMed links. A
gene lists the models annotated for it as links. A stock shows its pedigree and
the stock center that distributes it. An allele links to its parent locus. The
alternative — one generic table of name and identifier — makes the reader open
records to tell them apart.

Two fields look like labels and are not: `reference.name` is a whole citation
string that repeats the title and authors (the journal is `reference.in1` into
`mgdb.journal`), and `stock.available_from` and `locus.linkage_group` are
identifiers, into `mgdb.person` and `mgdb.linkage_group` respectively.

### One behaviour change

`LIKE '%term%'` matched inside a word, so `waxy` hit `nonwaxy`. The indexed path
matches on token prefix: `waxy` hits `waxy1` and `waxy endosperm` but not
`nonwaxy`. Prefix matching is what makes the index usable and is what the header
search already did.

### The two categories that are not searches

*MaizeGDB ID* resolves one identifier to one record page, and *Static web
pages* hands the term to Google. Both used to leave the modern route to do it.

The id lookup ran `WHERE idn.id=$term` with the term interpolated, so a term
that was not a number — "GWAS" left in the box while the category was still on
MaizeGDB ID — made Postgres error on an unknown column, and the request
finished with a 200 and **an empty body**. `saResolveId()` in
`searchall_lib.php` now does it: one primary-key read, 3 ms, and no read at all
unless the term is digits. A live id redirects to its record; anything else
falls through to the all-data search with a line saying why, because that is
where the reader was trying to get. The record URLs come from the registry's
own `url` fields, so the id lookup cannot drift from the search — the list it
replaced still pointed at `/data_center/probe` and `/data_center/person`.

The Google option rendered the whole legacy in-progress page and let
`js/search_engine.js` set `document.location` after it loaded: 39 KB and a
flash of pre-redesign chrome on the way off the site. `search_engine.php` sends
the 302 itself.

This makes the id category the one case where the shell touches the database.
Every other category still renders without a query.

### Verification

`ORDER BY` ends in the primary key for every type. Without a total order two
records that tie on name and length swap places between queries, which lets a row
appear on two pages or on none; 6 pages deep on the four largest result sets
returns 150 distinct rows and no duplicates. The temp-table path and the inline
fallback were checked to return identical results across 132 type queries.

### Every number on the page is the number of records behind it

This was not true, and it is the property the rest of this section now exists to
hold. The rail counted records one way — `GROUP BY term.name` over
`id_num.type_term` — and each section listed them another, so the two disagreed
whenever the definitions did:

| Search | The rail said | The section listed | Why |
| --- | ---: | ---: | --- |
| `kn1` Loci | 2 | 1 | kn1 carries gene models, so it is shown as a gene |
| `protein` Loci | 11,784 | 280 | the same, 11,504 times |
| `maize` References | 17,392 | 17,613 | 221 references have `id_num.type_term = 0` |
| `gl` Genes | 60 | 457 exist | the count was the size of a `LIMIT 60` fetch |
| `zm00001eb` Genes | 250 | 44,303 exist | the same, for the identifier branch |
| `2` primers | 60,372 | 62,690 | `mgdb.primer` has several rows per record |
| `"Chao Wu"` References | 18 | 23 cards | one paper has six abstract rows |
| `waxy1` Loci | 0 | 2 exist | the text index cannot see a locus's second name |

The fix is structural: `saBuildTypeTable()` resolves every match to the one data
type it will be displayed as, once, into a second temp table. A match is of a
type when it appears under one of that type's text sources, has a row in that
type's record table, and satisfies that type's own predicate — one definition,
evaluated in one place. The counts are then `GROUP BY type_key` over that table
and the sections read their rows out of it, so **a rail count is the number of
records its section can list, by construction rather than by agreement.**

Two consequences worth knowing:

- **Genes and Loci are one set split by one test.** A locus carrying gene models
  is a gene; a locus carrying none is a locus. They are resolved in a single
  pass — `CASE WHEN EXISTS (gene models)` — so a record cannot land in both or,
  as `protein`'s 11,504 did, in neither.
- **Genes and Loci also match `mgdb.locus`'s name columns directly**, through
  their btree indexes, because `all_text_search` runs a locus's three names
  together into one token (`wx1gss1waxy1`) and the text index can only reach the
  first. See AD-061 for that and for the three data defects the rest of this
  works around.

`tools/tests/searchall_consistency.php` is the proof. For each term it checks,
for every type, that the rail count equals the count the section computes for
itself, that the count comes back the same on the single-type path a deep link
uses, that the first page holds the rows the total promises, and — for sets small
enough to walk — that paging to the end returns exactly that many distinct
records with no repeats. It takes a term list, or `--sample=N` to draw random
real record names from eight tables, or `--comments`.

```bash
scp tools/tests/searchall_consistency.php development-server:/tmp/
ssh development-server 'cd <webroot> && php /tmp/searchall_consistency.php --sample=250'
```

Over 1,500 terms and 4,000 type checks, it reports no failures. It has already
caught two bugs that no amount of reading found: the `mgdb.primer` duplication
above, and an `array_flip()` whose first key gets the value `0`, which made
`saTypeReady()` false for exactly the type a deep link asks for — so every
`?type=` link silently took the slower fallback, and for Loci that fallback
cannot see name matches.

### What it costs

Resolving sixteen types honestly is more work than one grouped count, and the
searches that were wrong were wrong because they were doing less. Measured
end to end, before and after, on the development instance:

| Term | Before | After | |
| --- | ---: | ---: | --- |
| `2` | 21,900 ms → 503 | 0 ms | refused: one character is not a search |
| `zm` | 13,500 ms | 840 ms | refused: over the match ceiling |
| `maize` | 941 ms | 303 ms | |
| `b73` | 751 ms | 571 ms | |
| `b73_x` | 1,782 ms | 309 ms | `_` now splits, as Postgres splits it |
| `zm00001eb` | 216 ms | 112 ms | and the Genes count went 250 → 45,384 |
| `mo17` | 188 ms | 181 ms | |
| `kn1` | 48 ms | 57 ms | |
| `waxy` | 24 ms | 33 ms | |
| `protein` | 386 ms | 578 ms | it now finds the 11,530 genes it was losing |

Three changes paid for most of it:

1. **The curation filter moved into the match table.** `mgdb.id_num` is unique
   on `id`, so `EXISTS (… AND curation_lvl = 0)` is one index probe per matched
   record; doing it once at build time rather than as a join in seventeen
   downstream queries took `b73` from 889 ms to 754 ms. Folding it into the
   `CREATE` instead is slower — it then runs per matching *row* rather than per
   matched record.
2. **`saTsQuery()` splits on `_`.** It did not, and Postgres does: `b73_x:*`
   reached the parser as the phrase query `'b73':* <-> 'x':*`, which has to
   check lexeme positions after the index match. 1,576 ms against 35 ms for the
   conjunction.
3. **A deep link is one request, not two.** `action=type&rail=1` returns the
   rows and the type list together; they were two requests, each paying for its
   own scan of the text index.

And one for the opt-in path: with **“Also search comments and notes”** on, every
type's source list gains `memo`, which is 1.76 M rows belonging to records of
every type — so restricting each type's match to its own sources narrows almost
nothing and costs sixteen passes over a large table. That mode resolves types
from one shared set of matched ids instead, letting the record join decide the
type: `b73` with comments on went from 7.6 s to 5.8 s. It is still the slowest
thing the search can be asked to do, and it is still a checkbox: `b73` with
memos in scope reports 147,719 loci, one for every locus whose curator note
mentions the reference line.

### One character is not a search

`2:*` matches 2,610,080 of the 8.8 M text rows. Resolving that many records to
their types took **22 seconds and finished as a 503** — the 8-second
per-statement timeout never fired, because no single statement was over it. The
API now requires one word of two or more characters, which is the rule the
header suggestions have always applied, and says so instead of timing out.

### The suggestion dropdown gets its "N matches" from the results API

It used to count for itself, over rows of a single `all_text_search`
table_name rather than records of a data type across all of that type's
sources, so its numbers never matched the page they led to: `kn1` offered "2
matches" under Loci for a page that lists one, and `gl` offered "4 matches"
under Genes for a page that lists 1,360.

Counting the page's way inside the suggestion endpoint is not an option — that
resolution costs 13.6 s on `zm` and 0.9 s on `pr`, on an endpoint that fires
while someone is still typing. So the dropdown asks the results API instead.
`mgdb-search.js` renders the suggestions immediately, then 400 ms after the last
keystroke — one request per settled query, not per keystroke — fetches
`searchall_api.php?action=summary` and fills each group's number in, matching
group to type on the registry's `cat`. The counts are therefore the page's
counts, the same values from the same query.

Three properties worth keeping:

- **The list never waits on the numbers.** They arrive after, because finding
  four records to show is the cheap half and counting every data type honestly
  is the expensive half. A count that never arrives leaves no gap — the span is
  empty and stays empty, which is also what a term over the ceiling gets.
- **It is a prefetch.** `searchall_api.php` is cached for a minute, so pressing
  Enter usually lands on a response already made.
- **The dropdown files records the way the page does.** A locus carrying gene
  models appears under Genes there too, read from `mgdb.locus` rather than from
  whatever happened to make that request's `LIMIT 12`.

### A term can be too broad to answer

Everything in this search is roughly linear in the size of the match, so a term
matching most of the corpus takes most of a minute. `zm` — the prefix of every
maize gene identifier — matches **458,536 text rows** and took **13.5 s**: 3.1 s
to match, 6.2 s to resolve types, 3.4 s to count identifiers. No per-statement
timeout fired, because no single statement was over one.

`SA_MATCH_CEILING` is 300,000 matched rows, and the build stops one row past it,
so an over-broad term is refused in 0.8 s with a line saying what to do about
it. The number is set from measurement: the largest match that still returns a
usable page is `b73` with comments included, at 245,764 rows and 5.8 s, and the
largest without comments is `ac` at 84,754 and 2.6 s. Both still work; only the
terms that were already unusable are refused.

## Redesign status

How much of the site is on the design system is measured rather than tracked by
hand. `tools/redesign_status.py` copies itself to the development instance,
walks the whole web root, and works out which URLs the site exposes the same way
`controller.php` and `redirect.php` do — top-level controllers, controller
directories, the single-segment fallback search, real directories in the web
root, and the `if (PAGE == 'x') include('..._modern.php')` guards this redesign
uses. It then fetches each URL over HTTP from the server and reads the response,
so the verdict is what the page actually serves and not what its controller
appears to say.

```bash
tools/redesign_status.py
```

One run writes both outputs:

| Output | What it is |
| --- | --- |
| `REDESIGN_STATUS.md` | The report, committed to this repository |
| `src/data/redesign_status.json` | The same data, rendered by `/redesign_status` |

Deploy the JSON after a run, or the page keeps showing the previous measurement:

```bash
./deploy/deploy.sh src/data/redesign_status.json
```

Three parts of the report are worth reading before picking up the next page.
**Work on these next** ranks the not-yet-modern pages by exposure: everything
reachable from the mega menu first, then by how many places in the codebase link
to it. **Built on the design system but not routed** lists controllers already
written against the design system that no URL reaches — wiring one up is a guard
in its parent controller rather than a rebuild. **Unreachable files** is the
mirror image: files that can no longer be reached at the URL they were written
for, which is what replacing a page in place looks like from the outside.

The scan has to run on the server because the MaizeGDB codebase is not in this
repository — only the files the redesign has replaced. The script is stdlib-only
Python and runs under the server's 3.9. Pages that sign in or out, send mail,
write to the database, or need a record identifier are never fetched; those rows
are classified from the source and say so.

## Alternative design languages

Five complete alternatives to the current design system live at
<https://claude.maizegdb.org/pattern_library/styles/>, as full pattern
libraries rather than mood boards. They are a comparison exercise: **nothing in
them is applied to any page on the site**, and the current library at
`/pattern_library/` is untouched.

| | Style | What it is | Best at | Costs |
| --- | --- | --- | --- | --- |
| 1 | Journal | Serif, hairline rules, one accent | Long-form: nomenclature, methods, help | Density |
| 2 | Console | Monospace, 13px, borders not shadows | Tables, identifiers, coordinates | Reading prose |
| 3 | Grid | Swiss grid, heavy rules, huge type contrast | Staying neutral for a decade; printing | Warmth, scannability |
| 4 | Prairie | Rounded, soft shadows, generous space | First visits, projectors | Rows per screen |
| 5 | Instrument | Dark ground, luminous accents, mono numerals | Charts, browsers, long sessions | Prose, print, familiarity |

All five render **byte-identical markup** from
`src/pages/pattern_library/styles/_shell.php`. Of the 440 lines each page
emits, 28 differ, and every one of those is the style's own name, its number,
which stylesheet it links, or which switcher link is current. That constraint
is the point: a comparison is only worth something if the content is held
constant, and a style that needs different structure has to earn it through CSS
the same way it would on a real page.

```
src/css/pattern-style-base.css     Reset, page skeleton, comparison bar. No colour, type, or radius.
src/css/pattern-style-<n>.css      One design language, entire.
src/pages/pattern_library/styles/  The shell, the five thin entry points, and the chooser.
```

The base sheet holds nothing a reader would call style. Everything else — the
type stack and scale, the palette, density, radii, whether a component is
bounded by a border or a shadow, how a table is ruled — is in the numbered
file, so switching stylesheet switches the whole language.

These pages carry no JavaScript and no chart library; the bar chart is drawn in
CSS so each style can restyle it.

## The page header

A rounded card whose left portion is a solid wheat field carrying the page
title, blending on the right into a photograph. Built from the design handoff
in `docs/`. The idea it turns on is that the tint is **solid** under the text
and fades out over the photo — not a translucent scrim over the whole image —
so the text sits on flat colour at full contrast and the photograph is
untouched where it shows.

```
src/css/mgdb-page-header.css       the component
src/include/page_header_lib.php    mgdb_page_header(), which builds the markup
src/images/headers/                the photographs
```

A page includes the library and the stylesheet and hands the result to its
template:

```php
include_once('./include/page_header_lib.php');
$bauplan->includeCss('/css/mgdb-page-header.css');

$body->get('page-header')->replace(mgdb_page_header(array(
  'title' => 'Stock search',
  'lede'  => 'Find maize genetic stocks by name, accession, or pedigree.',
  'photo' => '/images/headers/cornfield-sample.jpg',
)));
```

Everything else — `fade_start`, `fade_end`, `text_width`, `min_height`,
`title_size`, `title_wrap`, `photo_position`, `tint_rgb`, `logo` — has a
default and is only written to the element when it differs, so the stylesheet
stays the source of truth. The function returns markup rather than printing it,
which is also why it exists: this markup in a `.bau` file would need every
literal parenthesis in the `url()` and the gradient escaped.

**<https://claude.maizegdb.org/pattern_library/header/>** is a workbench for
it. Edit the title, lede, and body; drag the fade start; swap the photograph;
and it prints the exact `mgdb_page_header()` call that produces what is on
screen. The preview is the component itself rendered by the function, not a
mock.

### Three things this component defends against

- **Text on the photograph.** Contrast is only guaranteed where the tint is
  solid. A `text_width` past `fade_start` is clamped back to it, in the PHP and
  in the workbench, and the caller is told. An earlier iteration of the design
  put body text on lit foliage at about 1.5:1.
- **A title that does not fit.** The handoff sets 38px and no wrapping, which is
  right at the width it was drawn against. Narrower than that, a title set not
  to wrap does not get clipped — it runs out over the photograph. The title is
  therefore capped at `3.5cqw`, so it holds 38px on a full-width card and
  scales below. A title long enough to overflow even then is a content problem;
  the workbench measures it and says so.
- **The narrow layout being overridden.** Below 760px the photo becomes a band
  across the top. That behaviour is written on the child elements, never by
  restating tokens on `.mgdb-page-header` — a page sets those inline, and an
  inline custom property beats any stylesheet rule, media query or not. The
  band is a fixed height for the same class of reason: as a percentage of a
  card whose height is driven by its text, it lands back on top of the text
  exactly when there is the most of it.

The cornfield photograph is a **placeholder**: it came from the design session
with its provenance unconfirmed. Replace it with a MaizeGDB or USDA owned
image, or a public-domain one, before this appears on a public page.

## The Collections project pages

Six pages under *Genomes → Collections*: `/NAM_project`, `/PanAnd_project`,
`/european_flints`, `/HiLo_project`, `/amaizing_project`, `/CAAS_FIL_project`.
They were built from one template and had drifted from the data hubs, so they
were brought back onto the hub pattern together.

**The header.** Each hero carried an "Updated" stamp rendering
`date('F j, Y')` — the day the page was served. It always claimed the data had
changed today, on pages whose assemblies were published in 2021–2023. Removed
from all six; put one back only when there is a real release date to print. Its
absence also frees the 11rem the shared `.mgdb-hero h1` reserves for it, which
is what let these longer titles come back onto one line. Two overrides per page
carry the rest:

```css
.<page> .mgdb-hero h1 { padding-right: 0; }
.<page> .mgdb-hero-description { max-width: none; }
```

The 62ch measure on `.mgdb-hero-description` is right for a hero whose text sits
beside something else. Here it wrapped one sentence into three short lines with
the right half of the panel empty.

Hero links are **named, not described** — *JBrowse 2*, not *Explore in
JBrowse 2*; *Project website*, not *PanAnd Website* — which is what gets five
or six of them onto one row. Section tabs follow the Genome hub's register:
*Assemblies*, *Publications*, *Hosted data*, *Metrics*, one word where one word
will do, and they then fit on one line.

**The sections.** Each held its own `h2.mgdb-panel-title` *inside* the panel.
They now use the hub shape — `.mgdb-section-heading` with the name and a
one-line description over a rule, then the content in a `.mgdb-panel`:

```html
<section id="..." aria-labelledby="...-title">
  <div class="mgdb-section-heading">
    <div><h2 id="...-title">Name</h2></div>
    <p>One line on what is in it.</p>
  </div>
  <div class="mgdb-panel"> ... </div>
</section>
```

**The tables.** Every `th` was `white-space: nowrap`, so each column reserved
its full single-line width and the table ran past its container — 93px on NAM,
82px on European flints, with the last column off the edge. Letting the headers
wrap lets each column shrink to its content, and there is then room to hold the
assembly identifiers on one line, which 19 of NAM's 26 were not:

```css
.<page> .<table> thead th,
.<page> .<table> thead th button { white-space: normal; text-align: left; }
.<page> .<assembly-link> { white-space: nowrap; }
```

**NAM's subpopulations were wrong.** B97 was badged *Stiff stalk* in the founder
table, in the filter chip counts, and again in the Subpopulation Structure
cards. B73 is the only stiff-stalk founder among the 26; B97 is non-stiff stalk.
All three places now agree — Stiff Stalk (1), Non-Stiff Stalk (6). The badge
colors were close to scrambled against the convention the NAM papers use: stiff
stalk had blue, which is the non-stiff-stalk color, and non-stiff stalk had
green, which is tropical's. They are now stiff stalk yellow, non-stiff stalk
blue, tropical green, sweet corn orange, popcorn pink, mixed gray, with the
selected filter chip taking its subpopulation's ink.

## The metric card, and its tones

`.mgdb-metric` in `mgdb-modern.css` is the only metric card. Four of the
Collections pages had been built on `.mgdb-metric-card` / `.mgdb-metric-number`
/ `.mgdb-metric-grid-4` instead — **none of which is defined anywhere**. Those
sections had always rendered as unstyled `div`s: no border, no ground, no
padding, and the headline figure at 16px body text rather than the 38px serif
numeral. Converted; the shape is

```html
<article class="mgdb-metric">
  <div class="mgdb-metric-top"><h3>Label</h3><span class="mgdb-metric-badge">Qualifier</span></div>
  <div class="mgdb-metric-stat"><strong class="mgdb-metric-value">42</strong></div>
  <p class="mgdb-metric-description">One line.</p>
</article>
```

The badge is optional. A value longer than about six characters takes
`.mgdb-metric-value-compact` beside `.mgdb-metric-value`, which drops it from
38px to 30px — `~2.26 Gb`, `530,000+`, `NSF #1546719` all need it.

**`mgdb-tone-*` was dead too.** It was on 46 cards across ten templates and
defined nowhere, so no card had ever been tinted. The rules now exist, and the
tone colors **the left spine only**. That restraint is deliberate: the tones are
a decorative rotation so a row of three or four cards does not read as one
block, and `red` is the third step in that rotation rather than a warning.
Recoloring the figure would make *Genome assemblies* and *Stock collections*
look like a problem. It matches the gold spine
`.reference-result-card.is-editorial-pick` already uses, and every card keeps
its heading and description, so nothing rests on hue.

Five tones exist because five are used: `green`, `amber`, `red`, `burgundy`,
`blue`. The Genome hub's own metric row is untoned, and the project pages follow
it.

## The dashboard cache

Every data-centre landing page opens by counting its whole collection so it can
show headline figures and draw its charts. Those aggregates are the same for
every visitor and change only when the database is reloaded, which on production
happens once a month. Measured on the development instance before the cache
existed, that was about eighteen seconds of identical work repeated on every
page view for a month:

| Page | live SQL | cached | |
|---|---|---|---|
| `/data_center/variation` | 10057 ms | 76 ms | −99% |
| `/data_center/map` | 2127 ms | 169 ms | −92% |
| `/data_center/marker` | 2072 ms | 62 ms | −97% |
| `/data_center/stock` | 1211 ms | 74 ms | −94% |
| `/data_center/bac` | 1147 ms | 67 ms | −94% |
| `/data_center/reference` | 804 ms | 62 ms | −92% |
| `/data_center/est` | 446 ms | 67 ms | −85% |
| `/data_center/overgo` | 243 ms | 65 ms | −73% |
| `/data_center/ssr` | 148 ms | 68 ms | −54% |
| reference facets API | 427 ms | 12 ms | −97% |
| **nine pages together** | **18165 ms** | **730 ms** | **−96%** |

Every cached page was verified byte-identical to the same page rendered with the
cache switched off.

`include/dashboard_cache.php` holds the whole mechanism. Wrap the corpus work in
a builder and give it a key:

```php
include_once('./include/dashboard_cache.php');

$page_data = dashboardCache($system, 'marker/page', function () use ($DBConn, $stats_sql) {
    $stats = retrieve_row(make_query($DBConn, $stats_sql));
    return array(
        'total_markers' => (int) $stats['total_markers'],
        'type_options'  => getMarkerTypeOptions($DBConn)
    );
});
```

The builder runs on a miss, and the result is written atomically as JSON. On a
hit it never runs. When the cache is off the builder runs every time and nothing
touches the filesystem, so behaviour is exactly what it was before.

**Only cache collection-wide data.** Anything scoped to a user's query stays
live. `search/marker/marker_search_api.php` shows the shape: with no term, type,
or bin the row count is a property of the whole collection and is cached as
`marker/total`; any narrowed request counts live. Profiling first is worth the
minute it takes -- on that endpoint the count was 534 ms and the twenty-four rows
it returns were 27 ms, so caching the obvious thing would have won nothing. The reference API draws the line explicitly: an unfiltered `facets_only`
request describes the whole collection and is cached, while a request carrying a
query or a filter always runs. Its JSON reports which happened in
`summary.cache` — `hit`, `miss`, `live`, `disabled`, `concurrent`, or
`unwritable` — with `summary.data_built` giving the build time of a hit.

A cache must never be able to break a page, so every failure path — unwritable
directory, corrupt JSON, a lost race, a builder returning nothing — falls back
to serving the builder's result live.

### Settings

These live in `conf/mgdb.conf`, which is not in this repository:

```
dashboard_cache=true
dashboard_cache_path=/home/cache/dashboard
dashboard_cache_ttl=0
dashboard_cache_stamp=
```

- `dashboard_cache` — master switch, following the `use_cache=true` string
  convention already in that file. Anything other than `true` is off, and a
  missing key is off, so an instance that has never heard of this setting keeps
  its present behaviour. **Set it to `false` on the curation instance**, whose
  database takes writes all day and whose figures have to be live.
- `dashboard_cache_path` — defaults to `<search_cache_path>/dashboard`. Must sit
  outside the web root.
- `dashboard_cache_ttl` — seconds before an entry is rebuilt. `0` means never
  expire, which is right for a database reloaded on a known schedule.
- `dashboard_cache_stamp` — free text mixed into every filename. Changing it
  retires the whole cache at once without needing filesystem access, which is
  the simplest thing for a monthly load script to bump.

### After a monthly reload

The cached figures are correct until the database is reloaded and wrong
immediately after. Finish the reload with either a stamp bump or:

```
cd <webroot> && php tools/dashboard_cache.php --purge --warm
```

`--purge` empties the cache; `--warm` then requests each cached page once so the
first real visitor gets a hit rather than paying the rebuild. Warming is
optional — without it the cache fills itself and exactly one visitor per page
pays for it. `--status` lists what is cached and how old it is.

Warming goes to the local Apache with an overridden `Host` header rather than
through `root_url_private`. The site sits behind Cloudflare, which answers a
server-side fetch of an HTML page with a bot challenge, so warming through the
public hostname reports every page as failed while the cache stays empty.

## The homepage

**Design 3 was chosen and is live at `/` as of 2026-08-25.** It won over the
default and `/index2/`, so the homepage now carries the warm header, the larger
borderless quick links, and the Reference assembly card described below. The
version it replaced — the "data dashboard" direction, record-page hero and all —
is archived in `legacy/home-dashboard/` with rollback steps.

The `.mgdb-home-v3` body class and `css/mgdb-home-alt.css` came across as they
were rather than being renamed, so `/` is byte-for-byte what the group approved
at `/index3/` — a URL that no longer serves; see **Design alternatives,
retired** below. Folding those rules into `mgdb-home.css` and dropping the
class is tidying, not a fix.

The homepage opens on **quick links** — the icon buttons from the production
site — with the modernized list a click away. Keeping the familiar grid was a
deliberate call by the group: it is the first thing anyone sees after the
transition, and it should look like the site they know.

Order on the page, after the group's review on 2026-08-24: hero, quick links,
the news and shortcuts rail, then the metric strip last. The metrics led the
page originally and were moved to the end so the grid is the first thing under
the hero. The heading carries no eyebrow and no introductory paragraph — it is
just the view name and the toggle, with an "All resources ›" link to `/sitemap`
underneath whichever panel is showing.

```
[ Quick links | List ]      <- the toggle, remembered per browser
```

Both panels ship in the HTML, so with JavaScript off the page still shows the
quick links. `js/mgdb-home.js` swaps `hidden` between the two `[data-home-panel]`
blocks and stores the choice under `mgdb-home-view` in `localStorage`; blocked
storage is caught and ignored, since the default view is still correct.

The `<h2>` names the view rather than the section — "Quick links" over the grid,
"All resources" over the list — so the JS rewrites it alongside the panels. A
fixed heading would be wrong in one of the two states.

### Design alternatives, retired

`/index2/`, `/index3/` and `/index4/` were the review copies the group compared
while the design was being chosen. **Removed 2026-09-05** on Carson's call; the
chosen design is what `/` serves.

| | Art (desktop) | Caption | Card | Header |
|---|---|---|---|---|
| `/` | 132px, no disc, 116px art | label only | none until hover | warm header |
| *`legacy/home-dashboard/`* | 74px disc, 56px art | label + description | bordered | record hero |

Their files are at
`/var/www/claude/retired/2026-09-05-homepage-alternatives/`, with a README
naming the `mv` that restores them. Nothing linked to the three URLs — no
template, script, stylesheet or controller referenced them, and they were never
in the site map.

**Removing the page directory was not enough to retire the URL.** With
`html/index2/` gone the request fell through `controller.php` to `redirect.php`,
which matched no controller and rendered `templates/error/error-404.bau` — the
"Oops, Sorry!" body, served with a **200** status and a "Welcome to MaizeGDB"
title. `controllers/index2.php`, `index3.php` and `index4.php` are now one-line
`301`s to `/`. Same trap as `/tools/<anything>`: compare the body, never the
status.

**Two files arrived with the alternatives and stayed, because production needs
them.** `css/mgdb-home-alt.css` holds the version 3 and version 4 blocks, and
`/` carries *both* classes on `<main>`; `images/home/assembly_puzzle.png` is
rendered by `mgdb_home.bau`. The version 2 block went with `index2` — only
`mgdb_home2.bau` ever carried `mgdb-home-v2` — and the shared rules at the top
of that file lost their `.mgdb-home-v2` selector. Verified after: the 28
`.mgdb-home-v3` / `.mgdb-home-v4` selectors are byte-identical, and the tiles
still measure 200×144 with 88px art at 1280px.

`include/home_lib.php` holds the release-date query, the precomputed metric
counts and the news rendering. It was extracted from `index.php` on 2026-08-25
so the alternatives could not drift from the real homepage; it stays where it
is, since `index.php` still uses all of it.

`css/mgdb-home-alt.css` loads only on the two variants, after `mgdb-home.css`,
and contains nothing but overrides scoped to `.mgdb-home-v2` / `.mgdb-home-v3`.
The default homepage cannot be affected by it.

**Version 3's header** is the substantive difference. The default homepage wears
`.mgdb-hero-record` — the same component the gene, stock, map, and reference
record pages use, an eyebrow over a title over a `<dl>` of facts. That reads as
database metadata, which is right on a record and cold on a front door. Version 3
replaces it with a warm cream-to-gold ground, a sentence saying what the site
is, the three release facts demoted to one quiet line, and the kernels from the
MaizeGDB mark \(`/images/kernels.png`, which already carries alpha\) sitting on
the gradient.

Its actions are underlined links rather than filled buttons: on a welcome they
are an offer, not a call to action, and two buttons were outweighing the
sentence above them.

**The headline** is the one place on the site that is not the standard green.
`#9c1c1f` is the deep rust already inside the MaizeGDB kernels mark — sampled
from the art rather than invented, so it belongs to the brand — at 6.6:1 on the
cream ground, comfortably past AA. It is sized just under the logo wordmark
above it, and the "Maize genetics and genomics database" kicker was dropped: the
logo already says that, one line higher.

**The tiles are outlined and weighted.** The borderless treatment read, in
review, as though the tiles might float away, so each carries a 1px border and a
small shadow offset down and to the right; pressing one collapses the shadow so
it settles onto the page. They are also smaller than the first pass — the grid
minimum is 10rem, not 11, because 11 still resolved to four columns at this
content width and the tiles stretched to 208px. Ten fits five columns at 164px,
and twenty tiles land as four even rows.

**The Reference assembly card** at the top of version 3's rail is the production
homepage's block rebuilt. There it is three 75px PNGs with their captions baked
into the art — `assembly_home_med.png`, `annotation_home_med.png`,
`assembly_other.png`, reading "B73 ASSEMBLY", "B73 ANNOTATION", "ALL GENOMES" —
which is illegible on a phone and invisible to a screen reader. The puzzle is the
recognizable part, so it leads as the section emblem at 84px and the three
destinations become real text with a line of context each. Same three links as
production: `/assembly`, `/gene_center/gene`, `/genome`.

`assembly_other.png` was the only one of the three worth keeping — 693×720 with
alpha already. `src/images/home/assembly_puzzle.png` is that file trimmed and
squared to 160px. The other two were 75px with baked captions and nothing
salvageable at the size the card needs.

The card sits on white rather than the header's cream: the puzzle art is amber
and washed out against a warm ground. The warmth comes from a gold accent bar
instead, which also marks it as the featured card in the rail. Measured at 1280px, the header is **258px** against 366px
for the first draft — same content, 30% less height, with the quick links
starting at 597px instead of 712px.

**A specificity trap worth knowing** if you edit these: the variant column rules
(`.mgdb-modern .mgdb-home-v3 .mgdb-quicklinks`, three classes) outrank the base
responsive rules in `mgdb-home.css` (`.mgdb-home-page .mgdb-quicklinks`, two
classes) *even inside a narrower media query*. Both breakpoints therefore have
to be restated in the variant sheet; without that, version 3 fell to a single
very wide column at 375px.

The design was chosen and the alternatives are gone (2026-09-05). What was
*not* done is folding the variant rules back into `mgdb-home.css` and dropping
the `mgdb-home-v3` / `mgdb-home-v4` classes: production still depends on both
blocks of `mgdb-home-alt.css` under names that read like scratch artifacts.
That rename is a separate change with its own verification.

### The icons

Source art lives outside the repo in `~/Documents/ClaudeCode/icons-selected` as
2048px squares — 22 MB for the set of twenty, with inconsistent white margins, so at a
fixed tile size some marks would have looked large and others small.
`tools/prep_icons.py` trims each one to its ink, re-squares it with a consistent
margin, and writes 128px PNGs into `src/images/quicklinks/`:

```
python3 tools/prep_icons.py     # needs Pillow
```

**22 MB becomes 286 KB.** 128px is deliberate: the tile shows the art at 56px
on desktop and 34px on mobile, so it covers a 2x display with room to spare. An
earlier 256px pass cost 393 KB of eagerly-loaded icons for no visible gain.

The first eight tiles are `loading="eager" fetchpriority="high"` because quick
links are the default view and sit above the fold; the remaining twelve are
lazy. Icons are `mix-blend-mode: multiply` inside a tinted disc, which drops the
white background the source art carries without needing transparency.

**Per-icon tuning.** Trimming to the bounding box makes every icon the same
*bounding* size, which is not the same as making them look the same size: art
built from thin strokes with white between them reads much smaller than solid
art at identical dimensions. The `TUNE` table at the top of `prep_icons.py`
corrects this per icon:

- `crop` cuts part of the source before trimming. `pan_genes` carries a
  "Pan-genes" caption beneath its diagram that is an illegible smudge at 60px
  and steals a quarter of the height from the part that matters; the bottom 28%
  is cut away.
- `zoom` lets sparse art run closer to the tile edge. `pannad` \(three thin
  plants with wide gaps\) is at 1.28, `qteller` at 1.45.

`qteller` remains the weakest of the set — it is a hairline V whose strokes fall
near a pixel at tile size. The zoom stops it reading as an empty tile, but the
source really needs a heavier stroke.

**Icon URLs are versioned.** `homeIconVersion()` in `include/home_lib.php`
appends `?v=<newest mtime in the directory>` to every quick-link `src` through a
single `$(ql_version)` slot. Without it a retuned icon keeps its URL and both
the browser and Cloudflare go on serving the old one — observed on 2026-08-25,
when the CDN held a superseded icon for 47 minutes after deploy while the origin
had the new one.

### Responsive behaviour

| Width | Quick links | Metrics |
|---|---|---|
| desktop | 5 columns, icon above label, descriptions shown | 4 across |
| ≤ 640px | 2 columns, icon beside label, descriptions hidden | 2 across |
| ≤ 340px | 1 column | 2 across |

Two mobile numbers worth keeping in mind, both found by measuring rather than
by eye:

- The breakpoint for one column is **340px, not 380px**. 360 and 375 are the two
  most common phone widths and both hold two columns comfortably; a 380px
  threshold dropped the most common phone to a single column.
- The shared `.mgdb-metric-grid` rule stacks metrics one per row under 640px,
  which is right where a card carries a paragraph. The four homepage metrics are
  a number and a short line, and stacking them ran to **564px**. Two up halves
  that to 283px. This mattered more when the strip led the page — it pushed the
  quick links 1,466px down — and still keeps the foot of the page compact now
  that it sits last.

## The BLAST front page

`/BLAST`. Tabs: Search, About, References, Related resources. Files:
`controllers/BLAST.php`, `controllers/BLAST/BLAST_form.php`,
`controllers/BLAST/BLAST_form.bau`, `templates/static/mgdb_blast.bau`,
`css/mgdb-blast.css`, `js/mgdb-blast.js`. Originals in `legacy/blast/`.

### Copy and layout revision, 2026-09-05

Carson's pass over the finished page:

- **The hero description is one sentence** — "Search sequence from all genome
  datasets hosted by MaizeGDB." It was three sentences explaining query types,
  target types and program selection, all of which the form's own step headings
  say again a screen further down.
- **Step 1 is two columns.** The GenBank fetch used to sit under a full-width
  textarea, sharing a row with Load an example and Clear. Those two buttons are
  ~260px of a ~1100px row, so the 26rem GenBank block was pushed to the right
  edge with a band of empty space to its left at every width above the
  breakpoint. It is a 20rem column beside the textarea now, with a left rule
  instead of a full-width top border; below 860px the grid becomes one column
  in source order. Measured at 1280: textarea 744px from x=118, GenBank 320px
  from x=886.
- **The run bar sits in flow.** It was `position: sticky; bottom: 0`, so it
  floated over whatever the reader had scrolled to — including About and
  References, where a Run BLAST button has nothing to run. The upward shadow
  that sold it as a floating bar went with the stickiness.
- **"Choose the right tool" is three sentences, each carrying its destination**:
  BLAST for sequence, the Gene Data Hub for an identifier, Foldseek in the
  Protein Structure Hub for structure. The "Search by identifier" button under
  it went — it repeated the Gene Data Hub link that is now in the second line.
- **Two references, not five.** `10.1101/pdb.over108430` and
  `10.1093/nar/gky1046`, both MaizeGDB's own papers and both already in
  `data/cite_journal_articles.json`, so neither needs a fallback. The page had
  also cited the 1990 BLAST algorithm paper, the NAM genomes paper, the pan-gene
  paper and a multi-genome search paper — a bibliography for maize sequence
  rather than for this page.

#### The result-format step is gone

Carson: "I assume with the new output format I no longer need the Choose the
result format section." Correct — it had been decorative since the results
interface was rebuilt. Checked before removing it:

| | |
|---|---|
| `blast_submit.php` | runs `-outfmt 15` **unconditionally**; reads `output_format` only to write it into `<job>.parms` |
| the results page | renders every view from that one JSON — no reference to `output_format` in the API, the lib, the template or its script |
| `BLAST_run.php` | *does* branch on it, in `getOutputFormatParam` — but it is only reached for pre-rebuild reports and `?ui=legacy`, never for a new job |

So the radio decided nothing, and the form went from four steps to three.

**The field itself had to stay, hidden.** `runBLAST` in
`controllers/BLAST/BLAST.js` is not repo-owned and is the results page's engine
too; it reads the checked `.output_format` radio and posts its value. With no
checked radio in the DOM that is `undefined`, and the string "undefined" lands
in `.parms` and in any legacy report reopened from it. One `checked` radio
inside a `.blast-sr-only` wrapper keeps the contract. Verified end to end: a
real search on the example sequence produced job `4cJeH1E5vqaE`, whose `.parms`
records `output_format	enhanced`, and whose results page rendered all three
views with 26 rows.

**Two consequences in repo-owned files**, both of which would have been silent:

- `BLAST_form.php` set `output_format_enhanced_checked` and, on a reopened job,
  `output_format_<format>_checked`. Those placeholders no longer exist in the
  template and **`Nary::get` throws on an identifier the template does not
  declare**, so leaving them would have fatalled the page rather than doing
  nothing. Both are gone.
- `js/mgdb-blast.js` put the format in the run-bar summary line and listened for
  changes on `.output_format`. The line now ends at the parameter preset.

**A Bauplan comment cannot contain a dollar sign followed by an open bracket.**
The first draft of the comment explaining all this quoted the jQuery selector
`$('.output_format:checked')`, and Bauplan reads `$(` as a placeholder wherever
it appears — including inside a `;;` comment. Caught by the paren balance check
before deploying: 58 opens against 60 closes. The comment now says it in words.

**The containers changed; the controls did not.** Every id in step 1 —
`query_sequence`, `blast-seq-count`, `blast-seq-warning`, `genbank_accesion`,
`blast-seq-limits` — is driven by id in `BLAST.js` and `js/mgdb-blast.js`, never
by its wrapper, which is what made the restructure safe. Verified after:
`BLAST_form.bau` opens 57 Bauplan constructs and carries 57 unescaped `)`,
against 60 and 60 at `HEAD` — three fewer of each, one per removed
`output_format_*_checked` placeholder, and still balanced. The 11 bare `(`
are unchanged; they are the JavaScript in `onclick` attributes, whose closing
bracket is escaped.

**Only the front page.** Job submission, execution and results — `BLAST_run.php`,
`BLAST_tasks.php`, `BLAST_visual_alignment.php`, `BLAST.js` and every
`BLAST_results*.bau` — were not touched, and are deliberately absent from the
manifest entry. `BLAST.js` in particular is the form's engine *and* the results
page's, so the form was rebuilt around it rather than with it.

### Two branches, two chromes

`BLAST.php` computed the form-or-job branch *after* loading
`templates/maizegdb-main.bau`, so both rendered in the legacy shell. It computes
the branch first now:

- **no `job_id` and no `submit-form`** → the modern main template, the modern
  header, `mgdb-hub.css` and `templates/static/mgdb_blast.bau`.
- **either present** → `templates/maizegdb-main.bau` and the legacy header,
  byte-for-byte what it did before, straight into `BLAST_run.php`.

Verified both ways: `/BLAST` serves 90 KB with `mgdb-hub-page` on `<main>` and
no `index.css`, `background_static.css` or `ie6.css`; `/BLAST?job_id=…` serves
42 KB with all three and no `mgdb-hub-page`.

### The form, rebuilt on the site's own controls

The first pass nested the 2012 form unchanged. That left the page looking right
and the form working the way it always had, which was the problem: layout
tables, a 46px-wide dataset select beside a 482px assembly box, help text in
popups positioned from `event.clientY` so they landed off-screen once the page
had scrolled, and — the reason the picker was hard to use at all — an
`<input list=…>` over a datalist holding **103 assemblies** for *Zea mays* ssp.
*mays* alone, which shows nothing until you type a prefix of a name of the form
`Zm-<accession>-<SOURCE>-<version>`.

The markup is now the site's own controls, in four numbered steps: **enter your
sequence**, **choose target datasets**, **set the sensitivity**, **choose the
result format**, with a sticky run bar under them.

**`BLAST.js` is still untouched.** It is shared with the results page and is not
a file this repository owns, so the rebuild works entirely through the DOM
contract it already relies on: the same ids, the same
`.query_seq_type` / `.param_set` / `.output_format` / `.selected_BLAST_target`
classes, and real events rather than direct property writes. Nothing was dropped
— the same 35 ids and the same 51 replacement tokens are present, checked
against `legacy/blast/BLAST_form.bau` rather than assumed.

Two pieces of legacy markup are load-bearing and stayed:

| Element | Why it cannot change |
| --- | --- |
| `#selected_targets` is a `<table>` | `addTarget()` appends a `<tr>` to it, and `restoreSettings()` and `setDefaultTargets()` render `<tr>` rows into it server-side. `display: contents` on the table *and* on the `<tbody>` the parser inserts takes it out of table layout so the rows can be chips — and covers jQuery appending a `<tr>` as a direct child rather than into the tbody |
| `#BLAST_target_assembly` is a text input with its datalist | `fillAssemblies()` empties and refills `#BLAST_target_assembly_datalist` and clears the input. The datalist stays and stays the source of truth; `js/mgdb-blast.js` mirrors it into a listbox and takes the `list` attribute off the input so the browser's own popup does not compete. With the script absent the attribute stays and the datalist behaves as before |

#### `<label for>` instead of an onclick that faked it

Every label in the old form was `<label onclick="$('#x').prop('checked','checked')">`.
That sets the property without raising a `change` event, which is why the four
preset labels had to call `setParams()` themselves — the radio's own
`onclick="setParams()"` never fired from a label click. The labels are real
`<label for>` elements now, so a label click clicks the radio, and the radio's
own handler runs. Verified: clicking **High similarity** moves the e-value to
`1e-20` and the identity cutoff to `95`.

#### The assembly picker

The datalist is mirrored into a panel that can be opened empty, says how many
entries it holds, filters on a substring anywhere in the name, highlights the
match, and is walked with the arrow keys. Typing `HiLo` returns the seven
`-REFERENCE-HiLo-1.0` assemblies — a match in the middle of the string, which
the native datalist could not find at all. Choosing an entry sets the input's
value and dispatches `change`, because `fillTargets()` is wired to that event
and assigning `.value` never raises one.

Above it, a row of quick-add buttons for the current reference assembly's own
datasets, from one query in `BLAST_form.php`. Reaching B73 v5's proteins
otherwise meant a species, an assembly typed out of 103, a dataset and a press
of Add; it is now one click, and clicking again removes it. The row they append
is the row `addTarget()` builds, from the id and label already in the button's
data attributes, so nothing downstream can tell the difference.

#### Adding a dataset: one panel instead of a dropdown and a button

The first pass on this form still asked the reader to drive a three-step
sequence for every dataset: pick a species, pick an assembly, pick a dataset
from a second dropdown, press **Add dataset**. Wanting an assembly's protein
set *and* its cDNA set meant repeating the last two steps twice.

The dataset dropdown and its Add button are gone. In their place, the gold
panel that used to be a B73-only shortcut *above* the picker is now the only
way to add a dataset, and it moved *below* the picker: pick a species and
assembly, and the panel updates to name that assembly and offer its available
datasets as "+" chips — click one to add it, click again to remove it. On load,
before anything is picked, the panel already shows **Zm-B73-REFERENCE-NAM-5.0
— the current reference** with its five chips, so a sequence and a press of Run
BLAST is a complete search without opening the picker at all.

`#BLAST_target` — the dataset `<select>` `fillTargets()` in the untouched
`BLAST.js` already empties and refills whenever the assembly changes — did not
go away. It is still exactly that select, just visually hidden
(`aria-hidden`, `tabindex="-1"`, no click ever reaches it), and
`js/mgdb-blast.js` reads its own `<option>`s back out to build the chip panel.
`fillTargets()` does not know its select stopped having a visible dropdown
next to it, and does not need to: from its side nothing changed.

Two things had to be handled for the panel to behave:

- **`fillTargets()` empties the select synchronously, then repopulates it only
  after its POST resolves** — so the select mutates twice per assembly change,
  once empty and once full. Re-rendering on every mutation keeps the panel
  honest without extra bookkeeping; the only thing debounced is the "No BLAST
  datasets for this assembly" message (350ms), so it does not flash during
  that round trip for the ordinary case of an assembly that does have
  datasets. `B73 RefGen_v4` — which has none — settles to that message; every
  other assembly settles to chips before the debounce ever fires.
- **Whether "— the current reference" still applies.** A `data-cur-ref`
  attribute on the panel carries the reference assembly's name from PHP, so
  the label can drop the suffix for anything else and restore it if the reader
  browses back to B73. It is a second token (`quick_assembly_attr`) rather
  than reusing `$(quick_assembly)` in two places in the same template — no
  other `.bau` file in this codebase relies on Bauplan replacing every
  occurrence of one token, so this does not start relying on it either.

`getQuickTargets()` in `BLAST_form.php` still runs exactly once, server-side,
for the reference assembly at page load — the reader's starting point, and the
only case that has to work with the script off. Every assembly picked
afterward is rendered client-side from data `fillTargets()` already fetched;
no additional PHP endpoint exists for it.

#### What else the enhancement layer does

- A live count — `1 sequence · 1,411 of 20,000 bp` — against the page's own
  `MAX_QUERIES` and `MAX_SEQUENCE_LENGTH`, turning red at the limit.
- A warning when the pasted sequence disagrees with the chosen type, using the
  same character classes `BLAST.js` checks on submit, so the warning and the
  rejection never disagree.
- A running summary in the run bar: what is missing, or
  *Nucleotide query against 2 datasets · Default parameters · enhanced output*.
- Two repairs it can reach without editing `BLAST.js`. `getGenbankSequence()`
  writes the fetched FASTA with jQuery's `.text()`, which sets a textarea's
  *content*, not its value — so in a box the user had already typed in, the
  fetched sequence arrived invisibly and the old text was what got submitted. A
  MutationObserver copies content to value; nothing but a script changes a
  textarea's text content, so the write is unambiguous. And `fillTargets()`
  enables the Add button before it knows whether the assembly has any datasets
  — `B73 RefGen_v4` has none — so Add posted an empty id; the button is now
  re-disabled, with a title saying why.

The six advanced parameters moved into a disclosure below the preset control,
`type="number"` with `min`/`max` where the value is numeric, and the two help
popups became inline `<details>` — the popups were positioned with
`event.clientY` against an absolutely-positioned box, so they appeared in the
wrong place on any scrolled page.

#### Alignment

Measured at 1280: all four step cards, the run bar and the recent-jobs panel
span the same 49→1231; all four step bodies start at 74 and all four headings
at 118; **every control in the form is 44px tall — one value, not a range**; the
picker's two controls share a top edge, and the picker, the gold panel and the
selected-datasets panel all span the same 118→1206 as the textarea. Species and
Assembly are 336px and 740px — Assembly is the wider share, since its values
are the long ones and it now also carries the browse toggle. One column below
620px, matching the breakpoint the rest of the form already uses; no horizontal
overflow at 430.

### What a 2012 stylesheet does to a modern page

`BLAST.css` contains, among other things:

```css
label { padding-right: 20px; }
```

No scope at all. On a page of its own that was harmless; nested inside another
page it reached the megamenu, the hero and every label on the site's chrome.
The first pass neutralized it from `mgdb-blast.css`. Now that the form is built
from the site's own controls, **the sheet is not loaded on the form branch at
all** — nothing in it applies any more, and the results branch still gets it.
`.BLAST` stays on the form element, because `BLAST.js` and the server-rendered
target rows carry the class, so `mgdb-blast.css` still neutralizes it.

The same call was made for **Shadowbox**. `BLAST.php` loaded it on both
branches, before jQuery, so on the modern page it threw `jQuery is not defined`
on every view and the inline `Shadowbox.init(…)` then threw
`Shadowbox is not defined`. The form page has no `a.shadow` link at all — it is
the results page that opens CViT images with it — so it is now on the results
branch only. Verified: the form branch serves no `shadowbox.*` and no
`BLAST.css`; `/BLAST?job_id=…` still serves both and no modern asset.

The scoping problem is the one worth remembering — a stylesheet or a script
written for a page of its own will have rules and globals with no scope in it,
and nesting that page inside another one turns every one of them into a
sitewide rule.

### Verified

4/4 distinct section edge colours, no rule under a section title, five
reference cards (BLAST's own 1990 paper supplied through the reference card's
`fallback`, since it is not in the MaizeGDB bibliography), no duplicate `id`,
all four nav labels matching their `<h2>`, no unresolved replacement tokens, and
the tab spy tracking all four sections with every jump clearing the bar. Four
short labels keep the bar at one 57px row from 1280 down to 375, so the ladder
is a flat 65px below the shell's 1170 step.

The form was verified against the backend rather than by eye:

- **The picker.** 103 assemblies load into the datalist; the panel opens with
  all 103, filters to 3 on `CML2` and to 7 on the mid-string `HiLo`; arrow keys
  and Enter select; the selection fires `change`, `fillTargets()` runs and
  returns the assembly's four datasets.
- **The submission.** Replaying exactly what `runBLAST()` collects and posting
  it to `BLAST_tasks.php` with `action=verify_input` — the backend's own
  validation, which creates nothing — returns **SUCCESS**, with all eleven
  fields present and correct, including the now-`type="number"`
  `BLAST_perc_identity` and the omitted-when-empty `BLAST_max_hsps`.
- **The restore path.** POSTing `saved_job_id` with a full parameter set brings
  back protein type, the High preset, 500 max hits, word size 6, e-value
  `1e-20`, 95% identity, 1 max HSP, text output and both target chips with the
  correct labels.

**AD-048 — no BLAST job could run on the development instance at all — is now
implemented.** Three stacked causes, not one: the temp directory's SELinux
type, a missing SELinux boolean (`httpd_sys_script_anon_write`) that a
correct type alone did not cover, and long-running php-fpm workers whose
supplementary groups had gone stale against an SSSD/NSS hiccup and needed the
service restarted to re-resolve. Each fix only *changed* the failure rate
until all three were in place; the full story, including how the second and
third causes were told apart from the first, is in `ADMIN_DEPENDENCIES.md`.
Verified afterward with a real submission through the live form — two targets
on two different assemblies — producing two `DONE` sub-jobs with real
`blastn` output, then 8/8 repeated real submissions with no failures.

**AD-049** records three `BLAST_tasks.php` / `BLAST_lib.php` endpoints that
interpolate raw request parameters into SQL; `BLAST_form.php` now casts its
own target ids to `int` before handing them to `getBLASTrecord()`, which
closes its own call site and nothing else.

### The dataset panel, verified

The picker verification above predates the dataset-panel rework — "returns the
assembly's four datasets" was about the hidden select, which is still exactly
how it behaves; what changed is what reads it. Checked afterward, on the live
form:

- **The default state.** On load, before anything is picked, the panel already
  reads "Zm-B73-REFERENCE-NAM-5.0 — the current reference" with five chips —
  Assembly, Gene model cDNA/CDS/genomic/protein — matching `getQuickTargets()`'s
  output exactly.
- **A different assembly.** Setting the assembly field and firing `change`
  (exactly what the combobox and the native datalist both do) replaces the
  label with the new assembly's name, no suffix, and rebuilds five chips with
  the ids `fillTargets()`'s own `#BLAST_target` select now holds. Clicking two
  of them adds both; clicking one again removes it; the previously-selected
  B73 default is untouched throughout.
- **The zero-dataset case.** `B73 RefGen_v4` — the same one the old dropdown's
  disabled-button guard used to catch — settles the panel to "No BLAST
  datasets for this assembly." after the debounce, with no chips and no flash
  of that message during the round trip for assemblies that do have datasets.
- **Back to the reference assembly.** Re-selecting
  `Zm-B73-REFERENCE-NAM-5.0` restores the "— the current reference" suffix and
  the original five chips.

### The results page header, 2026-09-05

Three fixes to `/BLAST?job_id=…`, all in `css/mgdb-blast-results.css` plus one
class in the template. Measured on job `4cJeH1E5vqaE` at 1280px.

**The gap under "BLAST results" was 58px, and three spacings were stacked in
it.** `.blast-overview > * { margin: 0 }` zeroes its *direct* children, but the
`<h1>` is a grandchild — it sits inside `.mgdb-section-heading` — so its own
24px bottom margin survived, under the wrapper's 16px, under the column's 18px
`gap`. The flex gap should own that spacing, so the other two are now zeroed
and the gap is 18px.

**The real fault was the layout, not the alignment — and it took three passes
to see it.** The overview was a stack of full-width blocks — title, defline,
facts, target bar — with a two-column band underneath holding the reading and
the Best match card. So the card could not start until every full-width block
above it had finished. On a two-target search that put its top edge about 200px
down the page while the prose column beside it ended in a field of white space.
The first two passes both tried to align the card against the *reading*, which
is the wrong thing to align it to.

It is one grid now, two columns from the top: everything that identifies the
search in column 1, the card in column 2 at `grid-row: 1`, the metric tiles and
the action row spanning both underneath. The card's top border is level with
the `<h1>`. Measured on a two-target search at 1280px: `<h1>` ink and card
border both at y=289, main column 814px, card 340px, and the two columns end
19px apart instead of 200.

340px for the card because its widest value is a coordinate range like
`chr2:4,493,490-4,496,967`; past that the column only takes width from the
prose. Below 900px it is one column and the card follows the reading.

The card's own inner spacing still matters, and is what the second pass
settled: `padding: 8px 16px 14px` with `dt:first-child { margin-top: 0 }`, so
its first label sits close to its own top border rather than 15px below it.

**The seven actions were two rows in two treatments.** Three things caused it:

- They measured **1193px against a 1182px bar**, so they wrapped.
- `margin-left: auto` on the utility group split them into two clusters with a
  200px hole between.
- The shared `.mgdb-button` renders on this page with a **transparent
  background and a transparent border**, so six of the seven read as bold text
  rather than as controls — while the seventh, "New search", was a solid blue
  `mgdb-button-primary`. Two different shapes offered for the same kind of act.

Now one row, one treatment: `flex-wrap: nowrap`, 8px gaps, and every control —
`<a>` and `<button>` alike — on `--blast-q0` with a `--blast-q1` border, which
is the same blue family the page scores matches in. Side padding went from 24px
to 14px, which is what brings the row to **957px** and lets it fit. `New search`
lost its primary fill. Below 900px the bar wraps rather than overflowing.

**The table view's filters sat flush against the table.** `.blast-filters`
bottom and `.blast-table-wrap` top both measured y=1325 in the All matches
section, so the search boxes read as part of the table header rather than as
controls above it. 16px on the wrap, not on the filters, because the filter row
is used in more than one place and only this pairing was tight.

### Genomic context labels, and the genome-browser link

**The neighborhood labels overlapped each other and the gene bars.** Two faults
in one drawing:

- **Lanes were packed on the gene bar's footprint, not the label's.** 155 kb of
  neighborhood in 512px makes most gene bars 2px wide, so eight genes packed
  happily into one lane — and then eight ~80px labels were drawn at those eight
  x positions and piled on top of one another. Packing now reserves
  `max(barWidth, labelWidth)`, with the label width estimated at 5.6px per
  character, the measured average advance for the identifiers that actually
  appear here (`Zm00001eb067760`, `GRMZM2G045049`, `prx16`).
- **The label was drawn at `gy + 9`, inside the 10px bar.** A lane is 26px now:
  an 8px bar with its name on the line below it.

Verified geometrically with `getBBox()` rather than by eye: on the eight-gene
`lg1` neighborhood, **0 label-label overlaps and 0 label-bar overlaps**, in a
viewBox that grew from 92px to 144px tall.

### The drawer's own buttons, and a scope that could not reach them

The drawer's three actions — View gene, Genome Browser, Pan-gene — were on two
lines and still rendering as the shared `.mgdb-button`'s transparent background
and transparent border, the same ghost the page's action bar had been fixed for.
`nowrap` and the shared chrome fixed the first two symptoms. **Two scoping
mistakes had to be found first, and both fail silently:**

- **`#blast-drawer` is an `<aside>` that is a SIBLING of `<main>`**, not a
  descendant. Every rule scoped `.mgdb-blast-results …` misses it. The first
  attempt scoped the button chrome that way and changed nothing.
- **The token block was on `.mgdb-blast-results` too**, so inside the drawer
  `var(--blast-q0)` resolved to nothing: `border-color` fell back to the
  element's `color` and the background stayed transparent, which looked like the
  rule had half-applied. The block is on `.mgdb-blast-results, .blast-drawer`
  now, so everything the drawer draws reads the same tokens as the page behind
  it and a value still changes in one place.

Verified by comparing computed styles rather than by eye: the drawer's buttons
and the page bar's buttons return the same background, border, colour, padding
and radius. "See on the Genome Browser" is **"Genome Browser"** — at 14px
padding the three fit 356px of a 524px row. Below 900px the drawer is a
full-width panel where they need 362px in 317px, so they wrap there.

### JBrowse 1, with the hits drawn on it

The pre-redesign results had a **See on the Genome Browser** button that opened
JBrowse 1 with the reader's own HSPs as a custom track. The rewrite had replaced
it with a plain JBrowse 2 coordinate link, which drops the reader into the right
region with nothing of their search on screen. The old behaviour is back.

JBrowse 1 builds a track from URL parameters — `addFeatures` carries the
segments, `addTracks` declares one CanvasFeatures track with the Segments glyph,
`tracks=BLAST` opens it. Three things had to be added to build one:

| | |
|---|---|
| `h_intervals` | The merged HSP spans on the subject. `mgdb_blast_summarize_group` already computed them for `h_aligned` and threw them away; they are on the row now, and are what makes the feature *segmented* rather than one block from `h_start` to `h_end` |
| `mgdb_blast_browser_urls` | `chado.analysisprop`'s `MaizeGDB_browser_URL` per assembly — the same value the gene record page's `getBrowseLink` reads, and the only place that knows which browser serves which assembly. One query per request, cached |
| `browsers` / `browser_base` | The bases, sent with the rows and with the neighborhood, so the client builds the link from a row's own intervals with no extra round trip |

**Only JBrowse 1 assemblies get the button.** The recorded URLs are a mixture:
JBrowse 1 for the NAM, HiLo and PanAnd assemblies, GBrowse for B73 v1 to v4 and
a few drafts. A base that is not `jbrowse.maizegdb.org` is not sent, and the
JBrowse 2 coordinate link stays as the fallback in that case — one button, not
two offering the same thing.

The base already carries its dataset (`?data=CML247`), or carries none at all
for B73 v5, which is JBrowse 1's default. Both forms come straight from the
database, so a new assembly needs no change in the code.

Verified against the example in the review: the `lg1` locus produces
`addFeatures` for `4493490-4494003`, `4494214-4494371` and `4496229-4496967` —
the same three segments, and the URL returns 200.

## The nomenclature standard

`/nomenclature` — not a data hub, but brought onto the same shell so it looks
like the rest of the site. Tabs: Conventions, Guidance, Assemblies and gene
models, Before naming, The standard, Related resources. Files:
`controllers/nomenclature.php` (new), `controllers/community/nomenclature.php`,
`templates/community/mgdb_nomenclature.bau`, `css/mgdb-nomenclature.css`.

### Why it did not match: it was loading two chromes at once

The page was already written against the modern design system, and still did
not look like the site. The reason is routing. `/nomenclature` had no
top-level controller, so `controller.php` fell through to `redirect.php` —
**and `redirect.php` loads `templates/maizegdb-main.bau`, the legacy main, before
it goes looking for a controller.** That template's `include-css` block
registers five stylesheets on the Bauplan object:

    /css/index.css   /css/background_static.css   /css/megamenu.css
    /ie/ie6.css      /tools/shadowbox/…/shadowbox.css

plus `search.js`, `search_engine.js`, jQuery UI, **`ngl.js`** — the 3D structure
viewer, on a page of text — and `shadowbox.js`. The modern controller then
rendered on top of all of it, so the page carried both design systems at once.

`controllers/nomenclature.php` takes the route before that fallback runs, which
is the same fix `/cite` and `/uniformmu` needed. Its stylesheet list is now
identical to a converted hub's, minus nothing and plus only
`/css/nomenclature.css`, which belongs to the curator-maintained standard the
page nests. 112 KB, 68 ms.

**Worth checking on any page that still looks wrong:** compare its stylesheet
list against a converted page. If it carries `index.css` or `ie6.css`, it is
going through `redirect.php` and needs a top-level controller, whatever its own
controller does.

### What the shell changed

Seven eyebrows, the hero tagline and its two buttons, and the `01`–`04`
numerals on the guidance tiles are gone. The bespoke `.nomenclature-jump-nav`
became the shared sticky tab bar, every section got an id and a heading that
matches its tab, and Related resources was added.

No Metrics and no References. There is nothing here to count, and a metric
card with no query behind it is the mistake two archive hubs had already made;
the standard's provenance is a sentence under its own heading, where it
belongs.

### Making the nav sticky broke the page's own navigation

The complete standard is nested unchanged from the curator's template, and it
carries **23 `<a name>` anchors** — the index at the top of the standard, and
every "Read section 12" link on the modern page above it, jump to them. They
had `scroll-margin-top: 1rem`, which was right while the jump nav was an
ordinary block and wrong the moment it became a sticky bar: measured, every one
of them landed **41px behind the bar**. They take the same measured ladder as
the sections now, and clear by 8px.

That is the general trap: making a nav sticky changes the offset every anchor
on the page needs, including anchors in content the page does not own.

### Verified

7/7 distinct section edge colours, no rule under a section title, no duplicate
`id`, all six nav labels matching their `<h2>`, five related links, no
horizontal overflow at 375. Six labels — one of them "Assemblies and gene
models" — wrap to four rows and 201px at 375, so below 767px the bar becomes
one scrolling row; the measured ladder is 65px above 950, 113px from 950 to
768, and 65px below. Sections and nested anchors both clear by exactly 8px at
1280, 880 and 375. `/community/nomenclature` still serves the page through the
community controller; every navigation surface on the site links to
`/nomenclature`.

## The site map

`/sitemap` is the complete directory: every page, tool, data hub, and resource
at MaizeGDB, for someone who cannot find what they need any other way. 130
entries across 11 groups, with search, group filters, per-section collapse, and
a sticky tab bar.

**These files lived only on the server until 2026-08-23** — absent from this
repository and from the manifest, so nothing backed them up and any deploy
touching their directories could have lost them. They are now tracked:

| File | Role |
|---|---|
| `controllers/about/sitemap.php` | Route; supplies the live genome count |
| `templates/about/sitemap.bau` | Hero, search and filter controls, metrics |
| `templates/about/sitemap-featured.bau` | The "New tools" band, generated |
| `templates/about/sitemap-tabs.bau` | The sticky section tab bar, generated |
| `templates/about/sitemap-content.bau` | The directory itself, generated |
| `css/sitemap.css` | Page composition on the shared tokens |
| `js/sitemap.js` | Search, group filter, expand/collapse |

Page order is hero → new tools → tab bar → search panel with the directory
directly under it → metrics. Three generated partials rather than one because
they land in three different places and Bauplan cannot split a single loaded
partial across several.

The hero carries a heading and a one-line description and no buttons, matching
`/data_center/reference`. The tab bar is the shared `.mgdb-section-tabs`
component from that page: thirteen jump links — New tools, Search, then one per
directory group. Labels come from `TAB_LABELS` in the content model and are
shorter than the section headings on purpose, since the bar is sticky and
thirteen full headings would wrap to three rows. The generator fails loudly if
a section has no label, so the bar cannot drift out of sync.

### Editing the directory

Both partials are generated from one content model:

```
# edit tools/sitemap_data.py, then
python3 tools/gen_sitemap.py
deploy/deploy.sh src/templates/about/sitemap-featured.bau
deploy/deploy.sh src/templates/about/sitemap-tabs.bau
deploy/deploy.sh src/templates/about/sitemap-content.bau
```

The generator warns when two entries in the same section point at the same URL.
Across sections is fine — BLAST is both a starting point and a research tool.

No section carries a blurb as of 2026-08-28; the Data hubs one was the last to
go. The field, the generator branch and `.sitemap-section-blurb` in the CSS all
still work, so putting a sentence back is a one-string edit in the content
model.

The generator exists because Bauplan requires every literal `(` and `)` to be
written `\(` and `\)`, and a stray one reports the *last* `)` in the file as
the error — useless on a 1,200-line template. `tools/gen_sitemap.py` escapes
mechanically and checks that opens and closes balance before writing.

The emitted `.bau` is plain, readable HTML, so editing it directly is fine for a
one-line fix. Anything larger is easier in `tools/sitemap_data.py`, and
regenerating overwrites hand edits — make the change in both, or in the data
model only.

### What the markup has to provide

`js/sitemap.js` keys entirely off classes:

```html
<section class="sitemap-section" data-section-kind="tools">
  <h2 class="sitemap-section-heading">
    <button class="sitemap-section-toggle" aria-controls="sm-x-panel" aria-expanded="true">
      <span class="sitemap-section-name">…</span>
      <span class="sitemap-section-count" data-total="44">44</span>
    </button>
  </h2>
  <div id="sm-x-panel" class="sitemap-panel open">
    <ul class="sitemap-grid">
      <li class="sitemap-item">
        <a class="sitemap-item-link" href="…">…</a>
        <p class="sitemap-item-desc">…</p>
      </li>
    </ul>
  </div>
</section>
```

`data-section-kind` is one of `tools`, `curated`, `community`, `archive` and
drives the filter chips. A chip click filters the list immediately; the panel
and the list it filters are one section so the effect is visible without
scrolling.

The new-tools band repeats tools that also appear in the directory below. It is
filtered along with everything else but deliberately left out of every count —
totals come from unique hrefs inside `#sitemap_content`.

Two things about the tab bar are specific to this page. A group filter can hide
the section a tab points at, so those tabs go dashed and inert rather than
scrolling nowhere. And clicking a tab opens its section first, so a reader who
collapsed everything does not land on a bare heading.

**The scrollspy is a throttled scroll listener, not an `IntersectionObserver`,**
unlike `/data_center/reference`. Some embedded and backgrounded browsers never
deliver observer entries — the same failure `js/mgdb-modern.js` already carries
a scroll fallback for — and the automated browser used to verify this page is
one of them: it delivers no `IntersectionObserver` entries, no `scroll` events
and no `requestAnimationFrame` callbacks at all. The throttle is `setTimeout`
rather than `requestAnimationFrame` for the same reason. The trailing sections
sit too close to the foot of the document to ever scroll under the sticky bar,
so `spy()` special-cases the bottom of the page; without it the last tab can
never light up.

The previous version had no such contract: the JS scraped `<dt>` elements and
walked `#sitemap_content > table > tbody > tr` to find promoted tools, and the
"NEW TOOLS" strip was a nested `<table>` of `<td class="newtools">` cells with
tooltip spans, which is what made that section look unformatted. About a hundred
lines of `css/sitemap.css` existed only to re-flow those tables into grids;
all of it is gone.

### Keeping it complete

`src/data/redesign_status.json` is the authoritative list of live URLs — 268 of
them, written by `tools/redesign_status.py`. To find what the directory is
missing, diff its `rows` against the hrefs in `sitemap-content.bau`. That check
is what grew this page from 48 linked URLs to 227 entries.

The 2026-08-28 group review cut it back to 130. Completeness is not the only
goal: an entry earns its place only if the page it points at works without
parameters and is still maintained. Everything dropped in that pass was checked
against the live server first — see "Site map review" below.

Deliberately excluded: record pages needing an id, JSON API endpoints, and the
curator tools under `/curation/` — those are login-gated and return nothing
useful to a signed-out visitor.

Every link was verified to return 200/301/302 before shipping. One link found
during that pass is genuinely dead — `archive.maizegdb.org` resolves through
Cloudflare but its origin does not answer, which also breaks the "MMP
Documentation" link on `/data_center/map`.

The "Your account" section was removed on 2026-08-28. `/login`,
`/create_account`, `/forgot_password`, `/preferences` and `/update_person` are
no longer listed anywhere on this page — the header account controls are the
route in. `/create_account` serves an empty body on dev and on www regardless.

### Site map review, 2026-08-28

Group review cut 227 entries to 135. Every removal was checked against the live
server first — several of the group's calls were right for reasons stronger than
the ones given, and two entries turned out to be duplicates nobody had spotted.

Verified dead — 200 OK with an empty or parameter-stub body:

| URL | What it serves |
|---|---|
| `/about` | "Oops, Sorry! The page you're looking for cannot be found." |
| `/new_genes` | empty body |
| `/curation/geneModelIssues` | empty body |
| `/create_account` | empty body, on dev **and** on www — still linked, flagged |
| `/help`, `/lab-pictures` | empty body |
| `/ems-phenotype`, `/rescuemu-phenotype` | shell only, no content |
| `/mapped_elements`, `/bin_viewer_locus_sequence`, `/single_tissue_comp` | empty body |
| `/compare_maps` | "You must provide two map ids" |
| `/compare_three_maps`, `/complete_map` | "Record Not Found!" |
| `/mappedssrs` | "You did not specify a chromosome." |
| `/bin_viewer_locus_accession` | renders bin 0.00, needs an accession |
| `/community/image_gallery` | "No gallery name provided" |
| `/conferences`, `/maizeprojects`, `/links` | "Click here to go to the old page." |
| `/project_homepages`, `/mnl_submit` | stub, never converted |

Same page under two URLs, merged to one entry:

- `/14InbredsFISH` and `/B73Mo17FISH` → one "FISH karyotypes" entry. Both now
  serve the same modernized page with the two karyotypes as tabs.
- `/maize_history` and `/timelines` → one "Maize history and timelines" entry.
  Byte-identical responses.

Name corrections found while rewriting the genomic-data descriptions:

- `/assembly_manifesto` is titled "MaizeGDB Reference Assembly Information" and
  is about how the representative assembly is chosen. It now carries that name;
  `/assembly` — actually the B73 v1–v5 assembly center — no longer does.
- `/sequencing_project` is the historic BAC-by-BAC project record, which is why
  the review asked to fold it into `/historic` as `/b73_history`. Until that
  merge happens both are listed, named so the overlap is obvious.

Still live, kept, contrary to first impressions: `/gbrowse`, `/foldseek` and
`/fatcat` return almost no HTML because they are `<iframe>` wrappers. The
`/data_center/*` hubs return 403 or "Just a moment…" to `curl` because of the
Cloudflare bot check, not because they are broken.

## The Maize Newsletter archive

`/mnl` is 94 volumes, 1929 to 2020. The newsletter stopped publishing after
volume 94, so the list is final and lives in `controllers/mnl.php` rather than
a data file. That controller takes the route ahead of
`controllers/community/mnl.php`, which is untouched and archived in
`legacy/mnl/`; rollback is deleting the new controller.

Two views over one list — a grid of volume chips and the shared table — both
rendered server-side and filtered together off identical `data-` attributes, so
they cannot disagree about what matched. Note the count callback halves the
visible total: every volume exists twice in the DOM, once per view.

**The design mockup's format badges are not shipped.** It carried
Born-digital / Complete PDF / Scanned facets and said in its own footer that
they were illustrative. All 94 volume URLs return an HTML index page, so there
is no per-volume format distinction observable from here, and inventing one on
an archive page would misdescribe the holdings. The one era split the page can
state is the one the newsletter documents about itself: fully electronic from
volume 89 in 2015. That is the only badge shown.

**The mockup's timeline view is not built either**, at the reviewer's request
and for a reason worth recording: the newsletter ran one volume a year for
ninety years, so a timeline drew a straight line and said nothing the year
label beside each volume did not already say.

Both `mnl.maizegdb.org/94` and the older `mnl.maizegdb.org/mnl/60/` URL shapes
are in use and both are live. They are carried over exactly as published rather
than normalized, because the older form is what external citations point at.
All 94 were verified to return 200 before shipping.

## The news archive

`/whatsnew` is 262 announcements spanning 2002 to 2026, read from
`data/news.xml` on the server. That file is curator-maintained and is **not**
deployed from this repository. `controllers/whatsnew.php` takes the route ahead
of `controllers/about/whatsnew.php`, which is untouched and archived in
`legacy/whatsnew/`; rollback is deleting the new controller.

The whole archive renders server-side and filters in the browser. At this size
that costs ~270 KB and buys three things: the browser's own find-in-page works
across every item, a `#news-250` permalink resolves without a round trip, and
there is no pagination state to get wrong. Reverse chronological, grouped by
year, with a sticky year heading.

**The images were never lost — they were being parsed and thrown away.** The
legacy `news_helper.php` reads an `<imgg>` element into every News object, but
`build_loop_array()` in the old controller passed only `date` and `news` to the
template, so 24 photographs and release graphics sat unused in the data for
years. They are back, at their intrinsic dimensions.

Three things worth knowing about that data:

- **Four of the fifteen image paths have no leading slash.** They resolve today
  only because the page sits at the web root; a route with a path segment would
  break them. `wnImagePath()` normalizes.
- **Lazy images need explicit width/height.** Most of the 260-odd items are far
  below the fold, so the images are lazy — and a lazy image with no dimensions
  reserves no space, reflowing the page under the reader as each one arrives.
  `wnImageSize()` stats each of the fifteen distinct files once per request and
  emits the real numbers.
- **The `<news>` bodies contain curator-authored markup** — links, `<br>`,
  emphasis — and it is emitted as-is, which is what the previous page did. Only
  the file's own editors can change it. Everything *around* the body is escaped.

The RSS subscribe link is gone from the page. The feed generator it pointed at,
`tools/maizegdb_news_feed.php`, is still live at its own URL and was left
alone — removing a published feed endpoint is a separate decision from removing
a link to it.

## The citation card

One published reference, rendered the same way everywhere: the literature
search results, the cite page, and any future page that lists papers. Styles
live in `css/mgdb-modern.css` section 10b, keyed on `.reference-result-card`.
**No page-level copy is needed** — that was the bug this replaced.

```html
<article class="reference-result-card">          <!-- + is-editorial-pick -->
  <div class="reference-result-meta">…</div>     <!-- + is-selectable      -->
  <h3><a href="…">Title</a></h3>
  <p class="reference-result-authors">…</p>
  <p class="reference-result-citation">Journal · volume · pages</p>
  <p class="reference-result-abstract">…</p>
  <div class="reference-result-links">…</div>
</article>
```

Everything but the title is optional; omit an element rather than emitting it
empty. Two variants: `is-editorial-pick` adds a gold spine and a badge that
says so in words, `is-selectable` adds a checkbox gutter for bulk export.

Consolidated on 2026-08-29. Both pages had their own copy of these rules under
`.mgdb-cite-page` and `.mgdb-reference-page`, and the two had drifted: the
search results left the abstract at body size, so a column of results read as
paragraphs rather than as a list. The shared component takes the cite page's
values, which also fixed the search page. 168 lines of duplicated CSS removed.

Two traps found while consolidating, both worth knowing generally:

- `.mgdb-cite-page .mgdb-cite-group h3` was a descendant selector meant for the
  group heading. Once the page-scoped card reset was gone it also matched every
  card title nested under the group, giving them the group heading's underline.
  It is now `> h3`. **A bare-element descendant selector inside a page scope
  will eventually catch a component nested under it.**
- Page stylesheets load after `mgdb-modern.css`, so at equal specificity the
  page wins. That is what lets a page override a shared component — and what
  makes a too-broad page rule silently break one.

Published strings are quoted material: titles, author lists, and journal names
print as the publisher set them. Do not sentence-case a title, expand an author
initial, or Americanize a journal name.

## "Data Hub", not "Data Center"

Renamed site-wide on 2026-08-25: "data center" now reads as a building full of
servers, so the user-facing term is **Data Hub** / **Data Hubs**. 306 occurrences
across 117 files.

**Only the spaced spelling was touched.** `data_center`, `data-center`,
`dataCenter` are routes, file names, PHP identifiers and CSS class names, and the
rename rules require a literal space so they cannot match. The apply script also
asserted per file that the count of each of those tokens was identical before and
after. `/data_center/…` URLs are unchanged, so no link, bookmark, or external
reference breaks.

Three things needed judgement rather than a substitution:

- **`src/data/cite_journal_articles.json` was left alone.** Its one occurrence is
  inside an `abstract` field — text quoted from a published paper. Rewriting a
  citation to match our house style would be falsifying it.
- **`src/data/redesign_status.json` was not hand-edited.** It is written by
  `tools/redesign_status.py` from live page titles; editing it would have made it
  disagree with the site. The generator's own category label changed instead and
  the file was regenerated.
- **"Data Center hub" would have become "Data Hub hub".** `/data_center/` is the
  page that lists them, so that phrase — and "Data Center Hub", "All Data Centers
  Hub" — map to **"Data Hubs"** instead. Those rules run before the general ones.

Four files had to be pulled into the repository to be changed at all, because
they were server-only and nothing was backing them up: `translation.php` (which
holds `dc_name`, the top-level menu label), the pre-redesign
`templates/home/megamenu/data-centers.bau` still served on every unmodernized
page, `controllers/static/archive.php`, and `templates/static/mgdb_archive.bau`.

Verified across 32 pages: none still shows the old term in visible text or in a
`<title>`, and no route broke.

## The megamenu on narrow screens

Below 900px the bar becomes a stacked drawer and each panel renders inline under
its trigger. That block in `css/mgdb-megamenu.css` sets `.mega-group` and
`.mega-feature` to `width: 100%` — which does nothing, because they are **grid
items** and the track sizes them, not the item.

The result, measured on a 375px screen before this was fixed: the Community
panel rendered as **four 66px columns**, the meeting feature box was 66px wide,
and its heading broke across five lines. Every tab was affected — `mega-grid-3`,
`mega-grid-4`, and `mega-grid-tools` all survived intact into the drawer.

The fix collapses every grid variant to one column inside the media query. If a
new panel layout is added, its grid class has to be added there too, or it will
reproduce the same failure.

Three things follow from stacking that the desktop layout did not need:

- The feature box stops being a column beside the lists and becomes a card
  between them, so it gets its own padding and radius.
- Group headings get a hairline rule to separate the sections. It has to be a
  **dark** hairline: the bar is dark green but the panel behind it is white, and
  the first attempt used a white border that was invisible.
- Every destination becomes a full-width row, so each is padded to the 44px tap
  target rather than sitting at its desktop line height.

The Community panel's meeting banner \(`/images/MGC2027_img.png`, 231×84\) is
capped at its own intrinsic width. An early mobile override set
`max-width: 100%`, which upscaled it to 265px on a 375px screen and softened it.

## The menu bar's label size, and where the drawer starts

2026-08-27. The bar's nine top-level items are a single `white-space: nowrap`
row, so their combined width is a hard floor on the viewport. Two ceilings meet
in the middle:

- `.mgdb-site-nav` caps the rail, so **the bar stops widening at 1232px** no
  matter how wide the viewport gets. A 1232px bar leaves 52px of slack at 19px
  labels and 10px at 20px. **19px is the ceiling**, and a `min-width` query
  above it would never have room to matter.
- Below that cap the bar is viewport minus 48px, and each size needs its own
  minimum viewport. Measured on the live bar: **17px → 1142, 18px → 1185,
  19px → 1228.**

The bar had been a flat 18px at every width down to a 900px drawer breakpoint —
so **from 901px to 1185px the items overran the bar and took the document with
them.** At a 1000px viewport the row ended at x=1150 with
`document.scrollWidth` at 1150: the whole page scrolled sideways, on every
modern page. Three stepped bands replace the flat size, and the drawer
breakpoint moves to **1164px** so no viewport is left without a size that fits.

**The breakpoint lives in three files and they must not drift:**

| File | What it keys |
| --- | --- |
| `css/mgdb-megamenu.css` | the stacked drawer layout |
| `css/mgdb-modern.css` | the injected Menu button and the collapse |
| `js/mgdb-chrome.js` | `MOBILE_QUERY`, which drives the toggle and tap-to-open |

`mgdb-modern.css` was the trap: its toggle rule shared a `900px` block with the
masthead rules for the logo and search field. Raising the bar's breakpoint alone
left a 901–1164px band that rendered the stacked drawer **with no button to open
it**. The block is now split — the logo keeps 900px, the nav drawer takes 1164px.

Adding or renaming a top-level item invalidates every number above. Re-measure;
do not assume the next size down still clears.

## Tapping a megamenu trigger that is also a link

Panels open on `:hover` and `:focus-within`. A touch device produces neither
before the tap resolves, and four of the six triggers are `href="#"`, so
`mgdb-chrome.js` intercepted their click and opened the panel instead.

**Genomes and Data Hubs carry real hrefs**, so they fell through that check:
tapping either one simply navigated to `/genome` or `/data_center/`, and
**their panels could not be opened on a phone at all.** Inside the drawer both
labels are now the panel's disclosure control; the landing page stays one tap
away in the panel's own heading action.

Two details make the toggle survive a second tap:

- The tap leaves focus on the trigger, so `:focus-within` would hold the panel
  open forever. `li.mgdb-menu-closed:not(.mgdb-nav-open)` sets `display: none`,
  which wins because the open rules never set `display`. Only the script applies
  that class, so nothing is hidden when the script does not run.
- `open()` deliberately does **not** latch `.mgdb-nav-open` while in the drawer.
  A tap fires `focusin` before `click`; latching there would let the click
  handler read its own open state and close what the tap just opened.

That `display: none` rule sits at file scope in `css/mgdb-megamenu.css` rather
than inside the drawer query, because **Escape was equally broken on the
desktop.** `mgdb-chrome.js` had always set `.mgdb-menu-closed` on Escape, and
`mgdb-modern.css` backs that with `left: -999em` — but that selector carries no
id and loses to `#menu_bar > li:focus-within .mega-dropdown-*`. So Escape
flipped `aria-expanded` to `false` while the panel stayed on screen: a screen
reader was told the menu had closed and it had not. Confirmed fixed at 1440px —
the panel's computed `display` is `none` after the keypress.

## The Genomes panel

2026-08-27, rebuilt from the six changes Carson asked for. Worth knowing:

- **"Representative", not "Reference".** GenBank's usage, and the collection
  holds many reference-quality assemblies. `gcQualityLabel()` in
  `controllers/genome/genome_center_modern.php` already derived the same word
  for the assembly table; the menu now agrees with it.
- **B73 v4 down to BAC-based are chips, not rows.** Five stacked
  `.mega-secondary-link`s would have made the feature box the tallest thing in
  the panel and buried v5 under its own history. `.mega-version-rail` wraps them
  onto one line, with the rail's accessible name carrying the context the
  one-word labels drop.
- **Assembly links use `/genome/genome_assembly/<name>`,** which is what the
  Genome Center's own table links to. The old menu used `/genome/assembly/`,
  which reaches the same record but falls past the title branch in
  `controllers/genome.php`, so every B73 assembly opened with the browser tab
  reading "MaizeGDB Genome Center". Names with spaces are percent-encoded in the
  markup rather than left for the browser to fix.
- **No eyebrow and no footer.** One section destination sits in
  `.mega-heading-actions` beside the h2: **"Genome Data Hub", pointing at
  `/genome`.** That is the modernized Genome Center — *not*
  `/data_center/genome`, which the rest of the modern site labels "Genome Data
  Hub" but which is still the legacy search-by-category page. The menu is the
  first place to use the name for the page that has actually become the hub;
  the older links are a separate cleanup. Whole-genome views is the last row of
  Explore and browse: it still carries the current B73 and NAM paintings, but it
  is the oldest viewer in the list.

### The B73 card: a narrow column, not a third of the panel

2026-08-28. As an equal third of `mega-grid-3` the card was **258 x 227px**
holding about **102px of content** — a badge, a title, one link and five chips.
It stretched to match the lists beside it, so 125px of it was empty.

`.mega-grid-genomes` gives it a fixed **202px** track and hands the ~56px back to
the two lists, which is where the long labels are \("NAM pangenome gene viewer",
205px\). The lists went 258 → 286px at the widest band.

202px is set by the one line that cannot wrap gracefully: "Open B73 genome page"
measures 154px, plus its arrow and the card's 28px of padding.

`.mega-feature-compact` then distributes the content down the full height with
`justify-content: space-between`, so the free space the taller lists create
becomes even **19px gaps** between all four blocks instead of 125px of dead card
at the bottom. `gap` is the floor for when there is no free space to spread —
which is the case in the drawer, where the card is content-height.

Three things follow from the narrower column:

- **The badge reads "Representative", not "Representative genome".** The longer
  string measured 172px — wider than the card now is. The word "genome" is
  carried by the panel it sits in.
- **The link is `.mega-feature-open`, a plain link rather than the filled
  `.mega-feature-link` button.** At 202px the button's padding pushed the label
  onto a second line. A separate class rather than a restyle, so the filled
  buttons in the Tools and Community panels are untouched.
- **The version chips are a three-column grid.** Five will not sit on one line
  in a 202px card, and a 4+1 wrap reads as an accident; a grid makes the 3+2
  deliberate and keeps the chips aligned. The rule above it replaces the
  "Earlier B73 assemblies" caption that used to introduce them — the rail keeps
  that text as its `aria-label`, since "v4" and "BAC" say nothing on their own.
  In the drawer the grid goes to five columns, where the width allows one row.

## The About panel

2026-08-28, rebuilt to the Genomes panel's shape: no eyebrow, no footer, no
description paragraph, and one heading action. That action is **"Explore site
map"** rather than a link to `/about` — `/about` is a page *about the project*,
not a way into the rest of the site, so it makes a poor section destination.

Four links remain after dropping FAQs and the Working Group, so the panel is
**584px** rather than 765px on a two-column `mega-grid-2`. Left at 765px it was
mostly empty space.

584 is not a round number on purpose. The h2 is the full database name, "Maize
Genetics and Genomics Database", which measures **361px** at the h2's 19px/700.
A 560px panel leaves only 357px beside the heading action — four pixels short,
so it wrapped to two lines. 584px leaves ~40px of slack. Narrowing the panel, or
giving the action a longer label than "Explore site map", wraps it again.

### AgBioData as an affiliation, not a feature

The membership had a full `.mega-feature` column: a badge, a heading, body copy
and a filled button — the same treatment B73 gets in the Genomes panel. That
gave a partner organization equal weight with MaizeGDB's own sections.

It is now `.mega-affiliation`: one row across the foot of the grid, carrying
AgBioData's own agriculture mark from `/images/quicklinks/agbiodata.png` \(128px
square, already on the server\). The whole row is a single `<a>`, so it is one
tap target in the drawer rather than a card with a button inside it, and the
mark's `alt` is empty because the adjacent text names it.

`grid-column: 1 / -1` spans whatever track count the panel uses, so the row does
not need respecifying if the grid changes. In the drawer it keeps
`align-items: flex-start`: the description runs to four lines at 375px, and
centring left the mark beside the middle of the paragraph rather than its title.

**"Founding member"** is the site's own wording, used verbatim in the footer of
every unmodernized page — not a claim invented here.

## The Community panel

2026-08-28. Eyebrow removed, and the h2 is now the plain name of the thing —
**"Maize Genetics Community"** — rather than the verb phrase it carried. The
heading action is wrapped in `.mega-heading-actions` so all three reworked
panels stack identically in the drawer.

### Weighted grid tracks

"MGC website" became **"Maize Genetics Cooperation"**, and the measured need
per track is nothing like even:

| Track | Needs | Longest item |
| --- | --- | --- |
| Community | 210px | "Maize Genetics Cooperation" |
| Literature & media | 143px | "Maize News Letter" |
| Share data | 125px | "Contribute data" |
| Meeting feature | 259px | MGC2027_img.png, 231px wide |

`repeat\(4, 1fr\)` gives every track 210px out of the 842px of track space a
920px panel has — exactly what the first column needs and not a pixel more, so
it would sit on the wrap threshold, and the feature would squeeze the banner
below its intrinsic width. `.mega-grid-community` weights them
`1.35fr 1fr 0.92fr 259px`, resolving to **240 / 178 / 163 / 259**: ~30px of
slack on the longest label, and the banner at full size.

\(The label first read "Maize Genetics Cooperation \\(MGC\\)", which needed 258px
and pushed the first track to 288px. Dropping the abbreviation brought the
column back down.\)

It is scoped to Community rather than folded into `.mega-grid-4`, which Data
Hubs also uses: that panel has four evenly matched groups and no feature column,
and even tracks are right for it.

### The banner was being downscaled

`.mega-feature-art` never overrode the shipped `megamenu.css` rule that pads
every `<a>` inside a dropdown. That 9px inset shrank the link's box, and
`width: 100%` then rendered the 231px image at 211px. Zeroed, so it draws at
its intrinsic size.

Note when screenshotting this panel: the banner is `loading="lazy"` inside a
panel parked at `left: -999em`, so it does not fetch until the panel first
opens. A screenshot taken immediately on hover catches an empty box — that is
the lazy load, not a layout bug.

**Literal parentheses in a label need escaping.** "Maize Genetics Cooperation
\(MGC\)" is written `\\(MGC\\)` in the template; an unescaped `\)` ends the
Bauplan block and the error it reports points at the end of the file.

## The Tools panel

2026-08-28. Eyebrow, footer and the featured-tool card all removed, so the panel
is four lists rather than a card plus three. The AI tools that were only
reachable through the old footer link \(`/ai`\) now have a column of their own,
alongside FETA, PanEffect and the MaizeGDB Feature Store. Phylostrata was added
at `https://phylostrata.maizegdb.org`, the URL the sitemap and homepage already
use.

### Status tags

`.mega-tag` marks a tool New or Updated. It is written **inside the label's
`<strong>`**, not as a sibling: `.mega-group > a` is a column flex container, so
a sibling span drops to its own row. Specificity has to beat the description
rule `.mega-group > a span` \(1 id, 2 classes, 2 elements\), which is why the
selector carries `.mega-group` rather than being a bare `.mega-tag`.

### The panel is wider than the other two fullwidth panels

Its four lists need **921px** of track between them — measured widest label per
column, plus the 16px the link padding takes:

| Column | Needs | Longest item |
| --- | --- | --- |
| Search & compare | 203px | "Genome Context Viewer" |
| Genes & function | 228px | "Protein structures" + Updated |
| Genomes & variation | 285px | "NAM pangenome gene viewer" + New |
| AI & machine learning | 205px | "MaizeGDB Feature Store" |

The shared 920px `.mega-dropdown-wide` leaves 842px of track — **79px short
however the tracks are divided**, which is why the pangenome label wrapped \(it
wrapped before this rework too\). Tools alone goes to **1010px** with tighter
gutters, giving 952px, and weights resolving to 210 / 236 / 295 / 212.

**The ceiling is 1037px**, set by the narrowest desktop band: at a 1165px
viewport this panel starts at x=104 and the bar ends at 1141. Do not widen past
that, and re-measure if a label or tag changes.

### Section spacing in the drawer

Stacked, the columns become consecutive sections of one long list. At the
drawer's old `gap: var\(--mgdb-space-2\)` there were **21px** between the end of
one section and the next heading against **1px** between rows inside it — too
close to read as a break. Tools stacks four sections, which is where it showed.
The gap is now `--mgdb-space-4`, giving 29px, and it applies to every panel.

## The Data Hubs panel

2026-08-28. Eyebrow, footer and the four category headings all removed. The
twenty hubs are now **one alphabetical list** in a single container, flowed into
four columns by `column-count` rather than split into groups by hand — so the
A-Z stays correct when an entry is added or removed, and CSS fills down-then-
across, which is the order a reader scans an alphabetical list in.

The headings went because several entries sat awkwardly under any of them:
*Analysis projects* and *Data summaries* are not data types at all. With twenty
entries an A-Z is faster to scan than a taxonomy the reader has to learn first.

### Hover descriptions: one region, not twenty tooltips

Each link carries `data-desc`; the panel carries **one** `[data-mgdb-hint]`
region under the heading that shows the description of whatever the pointer or
keyboard is on. `setupHints()` in `js/mgdb-chrome.js` wires them up.

Per-item `title` tooltips were the obvious alternative and are worse in four
ways: they never appear on touch, they wait about a second, they cannot be
styled, and screen readers treat them inconsistently. A single region also
cannot overflow the panel and costs no per-item vertical space — which matters,
because a visible description line under each of twenty entries would roughly
double the panel's height.

Three details:

- **The heading text block needs `flex: 1 1 auto`.** At the default
  `flex: 0 1 auto` it shrank to the h2's own width — 389px — so descriptions
  wrapped inside it and a two-line reserve was needed, which left an empty line
  under every short one. Taking the row's spare width gives the hint 684px, and
  all twenty descriptions then sit on one line, so the reserve is one line.
- **`min-height` reserves two lines, in every panel.** It was one line here,
  which is all the Data Hubs panel needs at its 684px hint width. The pattern
  then spread to About, Community and Genomes \(2026-09-05\), and the About
  panel is only 584px wide, so three of its five descriptions wrap — and the
  panel grew 20px under the pointer as the reader moved down a column. Two
  lines is the measured maximum across all five panels; see **The hover
  descriptions spread to every panel** below.
- **Reset happens on the panel's `mouseleave`, not each link's.** Per-link reset
  flickers while the pointer crosses the gap between two rows.

The rule is written `p.mega-hint`, not `.mega-hint`: `.mega-panel-heading p`
\(1 id, 2 classes, 1 element\) otherwise pins it to the 12px that rule sets for
heading body copy. That silently swallowed a font-size change once.

The region is `aria-hidden` — it duplicates the link the user is already on, and
announcing it on every hover would be noise. Screen reader and touch users get
the labels plus the **"Data hub descriptions"** heading action, which is the
accessible route to the same information and the reason the hover version is an
enhancement rather than the only way in.

### The hover descriptions spread to every panel

2026-09-05, on Carson's call. **About**, **Community** and **Genomes** lost
their group headings and gained `data-desc` on every link, so four of the five
panels now carry descriptions and only **Tools** still groups its links under
headings — it is the biggest and most heterogeneous of them, 17 links across
four themes.

A heading names the shelf; a description says what the thing is. "Project" and
"Learn" on the About panel were the clearest case, but "Collections" /
"Explore and browse" and "Community" / "Literature & media" / "Share data" were
doing the same approximate work that a per-link description does exactly. The
columns keep their old grouping and reading order — only the labels are gone.

`<div class="mega-group">` rather than `<section>`: a `<section>` with no
heading has no accessible name.

**Every link inside the `.mega-grid` needs a description, including the ones in
the feature cards.** The hint resets on the grid's `mouseleave`, not each
link's, so a described link followed by an undescribed one leaves the previous
description standing while the pointer is somewhere else. The B73 card's five
version-rail links and the Maize Meeting card's two links carry them for that
reason.

#### The panel used to shake, and now cannot

A description that wrapped to a second line grew the open panel by 20px under
the pointer. The hint is the only thing in an open panel whose height changes,
and it sits above the link lists, so everything below it moved.

`p.mega-hint` now reserves `min-height: 40px` — two lines at its 20px
line-height — so a one-line and a two-line description occupy the same box.
Two lines is measured, not guessed. Every description in all five panels, at
the hint width each panel actually gets:

| Panel | Hint width | Longest description |
|---|---|---|
| About | 381px | **two lines** \(3 of 5\) |
| Genomes | 628px | one line |
| Data hubs | 684px | one line |
| Community | 686px | one line |
| Tools | 768px | one line |

Those widths do not vary with the viewport: the dropdowns are fixed-width, and
below 1164px the drawer takes over and `.mega-hint` is `display: none`, so the
reserve costs nothing on a phone. Verified after by writing every description
into its panel's hint and reading the panel height back — one value per panel,
unchanged across all of them: About 266px, Community 389px, Genomes 334px,
Tools 293px, Data hubs 281px.

**Keep a new description inside two lines at 381px**, roughly 110 characters,
or the jump comes back on the About panel.

### One link lost

The removed footer held **Archived Data Hubs** \(`/archive`\), which now has no
link anywhere in the menu. It is still reachable from the site map.

## The feedback form

The Feedback button in the header did nothing on modernized pages. The legacy
shell carried two Atlassian issue collectors — declared as
`window.ATL_JQ_PAGE_PROPS` in `templates/maizegdb-main.bau` — and each one
loads a script from maizegdb.atlassian.net that binds a click handler to a
class and opens Atlassian's dialog in an iframe. The modern header kept the
`.feedback-form` link and lost the handler. ADMIN_DEPENDENCIES.md recorded it
as AD-009.

`/feedback` replaces it with a MaizeGDB form that posts to the collector's own
endpoint **from the server**, so nothing third-party loads on any page and the
form is built from the shared controls: `.mgdb-field`, `.mgdb-label`,
`.mgdb-input`, `.mgdb-textarea`, `.mgdb-form-actions`, `.mgdb-message`.

The route was not empty. `controllers/static/feedback.php` answered it with an
older email-based form — and answered it with a Bauplan error, on the
development instance and on <https://www.maizegdb.org/feedback> alike, because
the template it loads never declares the identifiers the controller sets. Its
POST branch would have mailed the message and then fatalled on an unassigned
`$mgdb`, and it took the recipient address from the request. It is shadowed
rather than overwritten, and archived with the details in `legacy/feedback/`.

### The collector endpoint

Feedback collector `883299e6` is a *custom template* collector. Its form at
`/rest/collectors/1.0/template/form/883299e6` posts to
`/rest/collectors/1.0/template/custom/883299e6` with `pid=10001` — project WEB
— plus `summary`, `description`, and the collector's own `fullname`, `email`,
`recordWebInfo` and `webInfo`.

Measured against the live collector on 2026-09-01: **the POST needs no
`atl_token` and no cookie jar.** Posting with neither returns exactly what
posting with both returns, so a submission is one request rather than a GET for
a token followed by a POST. The reply is JSON wrapped in a `<textarea>`, which
is how the collector answers the iframe that normally posts to it:

```
<html><body><textarea>{"errorMessages":[],"errors":{"summary":"You must specify a summary of the issue."}}</textarea></body></html>
```

`feedbackParseCollectorReply()` unwraps that, and the `errors` object comes back
keyed by the collector's own field names — which are the names this form uses,
so a rejection can be shown against the right control without a lookup table.

The type chooser does not map to a Jira field, because the collector accepts a
summary and a description and nothing else. It is carried as a bracketed tag on
the summary — `[Data] Wrong gene model` — which is a shape a queue can sort on,
and repeated as a line in the description.

### One copy of the markup

`feedbackFormMarkup()` in `include/feedback_lib.php` renders the form, and two
things use it: the page, and `/feedback?embed=form`, which returns the form on
its own for the dialog to fetch. That is why there is no `.bau` partial for it —
the dialog and the page cannot drift apart if there is one function.

On `/feedback` the header button does not open the dialog. A second copy of the
form in the same document would duplicate every field id, so the click scrolls
to the form already on the page.

### It works without JavaScript

The form is a real `<form method="post" action="/feedback">`. With the script it
posts through `fetch` and the answer lands in place; without it the controller
redirects to `/feedback?sent=1&key=WEB-nnn` on success and re-renders with the
field errors and the reader's own values on failure. A feedback form whose job
is to report that something is broken should not itself need everything to work.

### Guards

The collector endpoint is unauthenticated — anyone can post to it directly — so
these do not protect Jira. They keep this form from being the convenient way to
fill the queue, and keep obvious junk out of it:

- a honeypot field, positioned off-screen rather than `display: none`, since
  form fillers skip hidden inputs;
- a minimum time on the form, checked only when the browser filled in the start
  stamp, and ignoring negative elapsed times so a skewed clock cannot block a
  real sender;
- length caps, enforced again server-side after the browser's `maxlength`;
- six messages per address per hour, counted in `<search_cache_path>/feedback`.

A submission that trips the honeypot or the timer is answered as though it
worked and quietly dropped — a script that is told it was blocked retries with
the check removed. Both are logged.

Rate limiting **fails open**: an unwritable directory lets the message through,
the same way `include/dashboard_cache.php` falls back to serving live. Silently
swallowing feedback is worse than letting a determined sender through twice.

### Configuration

All optional, in `conf/mgdb.conf`. Without them the collector, project and paths
above are used.

```
feedback_collector_id=883299e6
feedback_project_pid=10001
feedback_collector_base=https://maizegdb.atlassian.net/rest/collectors/1.0
feedback_rate_path=/home/cache/feedback
feedback_enabled=false          # turns the form off and says so
```

### Verification

From the server, since the dev instance is behind Cloudflare:

```bash
curl -s -o /dev/null -w '%{http_code}\n' --resolve claude.maizegdb.org:80:10.24.27.235 \
  http://claude.maizegdb.org/feedback

# field validation, no Jira call
curl -s --resolve claude.maizegdb.org:80:10.24.27.235 -H 'Accept: application/json' \
  -d 'feedback_summary=&feedback_details=' http://claude.maizegdb.org/feedback

# the collector's own contract, without creating an issue: it rejects a
# submission that has no summary
curl -s -X POST -H 'X-Atlassian-Token: no-check' \
  --data-urlencode 'pid=10001' \
  https://maizegdb.atlassian.net/rest/collectors/1.0/template/custom/883299e6
```

Anything that actually sends opens a real issue in project WEB.

### The second collector: gene model and assembly errors

`dddb1a6c` is what the legacy shell opened from `.trigger_gene_model_issue_form`
on gene and pan-gene records. Same endpoint shape, different everything else,
read from its own form template:

| | site feedback | gene model report |
| --- | --- | --- |
| collector | `883299e6` | `dddb1a6c` |
| project | WEB, pid 10001 | ASMBLY, pid 10003, issue type 10008 |
| fields | summary, description | + `customfield_10050` "Affected gene models and/or loci", `customfield_10051` "Publication", `screenshots` |
| reporter | optional | both marked required by the collector's own labels |

So the form has two shapes, chosen by `kind`: `/feedback?kind=gene_model`, and
`?id=Zm00001eb067740` prefills the affected model. `feedbackKinds()` holds the
difference — collector, project, whether the type chooser appears, whether the
reporter is required, and the words the page and the dialog use. Nothing else
in the library branches on it.

The type tag is not applied to a gene model summary. The site form prefixes
`[Data]` and the like because everything it files lands in one project; this
collector has a project of its own, so the summary is the reporter's own words.

**The attachment is not offered.** The collector accepts one, but forwarding an
upload means proxying multipart with its own size and type handling. A reporter
can attach the image to the issue once it exists.

### Where the report links are

- **Gene record** (`/gene_center/gene/{id}`) — "Report a gene model error" under
  the identity block, prefilled with the gene model. This is where the
  pre-redesign page had it.
- **Gene hub** (`/gene_center/gene`), *Gene model issues* — both links there
  pointed at `/curation/GenomeIssue/edit` and `/curation/GeneModelIssue/edit`,
  which answer with a curator login *and* the note that new annotation accounts
  are not being accepted. No reader of that page could pass it. They now open
  the report.
- **Pan-gene** still points at `/contact`. The legacy page had "Report a
  pan-gene error" on the same collector; whether it comes back is a call for the
  group, since a pan-gene assignment is not an assembly defect.

Any link carrying `.trigger_gene_model_issue_form` opens it, which is the class
the legacy collector bound to, so old markup keeps working. `data-mgdb-feedback`
is the explicit form for anything new, and `data-feedback-id` (or an `id=` in
the href) carries the gene model.

## Why "B73" did not return the B73 v5 genome

2026-08-28. Both the header autocomplete and the full results ranked assemblies
by *name*, not importance:

```sql
ORDER BY CASE WHEN lower(assembly_name)=:exact  THEN 0
              WHEN lower(assembly_name) LIKE :prefix THEN 1 ELSE 2 END,
         assembly_name
```

"B73" matches nine rows. The current reference is named
**Zm-B73-REFERENCE-NAM-5.0**, so it is not an exact match, does not start with
"B73", and sorts last alphabetically in the remaining tier — **ninth of nine**.
The autocomplete's `LIMIT 4` then returned v1, v2, v3 and the 2008 BAC-based
assembly, and the current reference never appeared at all. The full results page
listed it, but bottom of the list.

The fix ranks the representative assembly first, then exact, then name-prefix,
then anything else carrying REFERENCE in its name:

| Rank | Matches |
| --- | --- |
| 0 | `Zm-B73-REFERENCE-NAM-5.0` |
| 1 | exact name match on the query |
| 2 | name starts with the query |
| 3 | name contains REFERENCE |
| 4 | everything else |

### The data cannot drive this

Worth knowing before anyone tries to replace the pinned name with a column:

- **`replaced_by` is empty for all nine**, including v1, v2 and v3, which are
  definitively superseded.
- **`release_date` is free text** — "2009", "9/16/2016" — and is **missing for
  v5**, the one row that matters most.
- **`quality` says "Representative" for v1, v2, v3 and v4, and is blank for
  v5.** `genome_center_modern.php` already works around exactly this by
  deriving the label from the assembly name instead.

So the name is pinned as a literal, which is what the rest of the codebase
already does — `GC_REPRESENTATIVE_ASSEMBLY`, `gene_record_lib.php`,
`expression_search_lib.php`, `uniformmu_search_lib.php` and others all carry the
same string. **It now appears in about ten files and they all have to change
together at the next release.** Folding them into one shared constant is a
worthwhile cleanup.

### Group order: Genomes above Loci and Stocks

Ranking within the group was only half of it — the group itself sat seventh in
the autocomplete's `$priority`, below Loci and Stocks, each of which can run to
thousands of rows \("B73" matches 335 loci and 1,324 stocks\). Genomes now comes
straight after gene models in both the autocomplete and the results page
\(`saTypeOrder\(\)`\), which are kept in step.

**The promoted top hit is deliberately unchanged.** An exact stock name is still
promoted above every group, so "B73" still leads with the B73 germplasm record —
that is the only record actually named "B73". The genome ranking fix and the
group reorder put the v5 assembly immediately below it rather than overriding
exact-match promotion for every query on the site.

The reorder only shows when a query matches an assembly: "anthocyanin" and
"kinase" produce no Genomes group at all and are unaffected, while "Mo17"
now leads with its assemblies the same way "B73" does.

### genome_metadata is one row per annotation, not per assembly

"B73" returns nine rows for eight assemblies: Zm-B73-REFERENCE-GRAMENE-4.0
carries both an NCBI 101 and a Zm00001d.2 annotation, same `analysis_id`,
different `annotation_id`. Both are real records, so both are listed. It is not
a duplicate — but a query matching such an assembly does spend two of the
autocomplete's four slots on it.

## Conventions this codebase relies on

- `.bau` templates escape literal parentheses as `\(` and `\)`. An unescaped
  `)` terminates the template block. This bites most often in inline
  `style="…"` attributes containing `var(--token)` and in `https://` URLs
  (write `https\://`). Prefer a class over an inline style and the problem
  does not arise.
- `translation.php` calls `$mgdb->get('…')->replace(…)` for a fixed set of
  variables, and `Nary::get()` throws on a missing identifier. A replacement
  shell must declare every one of them, even the ones it does not render.
  Declaring them inside an HTML comment is the idiom already used by
  `templates/home/maizegdb_header.bau`.
- Page bodies load from local disk with `load('templates/static/<name>.bau')`,
  resolved against the web root. **Do not use `loadRemote()`.** It fetched the
  template over HTTPS from `root_url_private` — the *public* hostname — so every
  request made a full round trip out through Cloudflare and back for a file
  already on disk: 220 ms per call, measured, against 0.065 ms for the local
  read. All 19 call sites were converted on 2026-08-21; page render times fell
  by 20–80%. `loadRemote()` also rewrites assets declared by a template's own
  `{include-css:}` directive into absolute `http://` URLs, which is a
  mixed-content risk on an HTTPS page.
- Entry points under `pages/<name>/index.php` run with the working directory set
  to their own folder, so they wrap template loads in `chdir('../')` … 
  `chdir($cwd)`. The body load must sit inside that block.
- Cache busting is applied by `Bauplan` itself; pass bare asset paths.
- Never copy `conf/mgdb.conf` or `conf/db.conf` into this repository. They
  contain database credentials and are listed in `.gitignore`.

## Status

On the redesign and verified on the development instance:

| Page | Route |
| --- | --- |
| How to cite MaizeGDB | `/cite` |
| Genome Center | `/genome` |
| AI and machine learning | `/ai` |
| Reference literature search | `/data_center/reference` |
| Maize Genetics Meeting | `/maize_meeting/` |
| Contact | `/contact` |
| Person and organization search | `/person` |
| Pan-gene search | `/pan_gene_center/pan_gene` |
| Insertion Data Center | `/insertion` |
| Homepage | `/` |
| Stock search | `/data_center/stock` |
| Stock record | `/data_center/stock/{id}` |
| Reference record | `/data_center/reference?id={id}` |
| Gene record | `/gene_center/gene/{id}` |
| BAC search | `/data_center/bac` |
| Cytogenetics | `/data_center/cytogenetic` |
| EST search | `/data_center/est` |
| Overgo search | `/data_center/overgo` |
| SSR search | `/data_center/ssr` |
| Maize genetics nomenclature | `/nomenclature` |
| UniformMu insertion resource | `/uniformmu` |
| Analysis projects | `/projects` |
| Protein domain atlas | `/projects/interpro_domain_atlas` |
| Design pattern library | `/pattern_library/` |
| Redesign status | `/redesign_status` |
| Marker and probe search | `/data_center/marker` |
| Phenotype search | `/data_center/phenotype` |
| Image search | `/data_center/image` |
| Protein structure data center | `/data_center/protein_structure` |
| TYPSimSelector | `/TYPSimSelector` |
| All-data search | `/search_engine/searchall` |
| Sitemap | `/sitemap` |
| Archive | `/archive` |
| FAIR practices | `/FAIRpractices` |
| Genomes | `/genomes_modern/` |
| Coming soon | `/coming_soon` |

`REDESIGN_STATUS.md` counts the rest. As of the last run, 40 of the 268 URLs the
site exposes are on the design system, and every one of them was re-checked over
HTTP on the development instance on 2026-08-17.

Foundations complete: the shared design system, the opt-in modern document
shell, the responsive global chrome and mega menu, the blue page ground, and a
single rail shared by the chrome and the page content.

## Working alongside other agents

Claude Code, Codex, and Gemini agents share this one working copy and one `main`
branch. Their edits land in the same files, so a few things are worth holding to.

**Pull before starting and commit when a page is finished.** Uncommitted work is
invisible to the other agents and easy to overwrite. The tree has held eight
finished pages at once; that is a lot of unpushed work to lose.

**These files are shared. Edit them additively.**

| File | Why |
| --- | --- |
| `deploy/manifest.txt` | Append-only. Concurrent appends are safe; a rewrite is not. |
| `src/css/mgdb-modern.css` | The design system every page loads. |
| `src/controllers/data_center.php` | Holds one `include(..._modern.php)` guard per data center. |
| `README.md`, `ADMIN_DEPENDENCIES.md` | Append a section rather than restructuring. |

**Do not sweep another agent's half-finished page into a commit** to get a clean
tree. Leave it uncommitted and say so.

**`tools/redesign_status.py` is the honest check.** It fetches every URL the site
exposes and classifies from the response, so it reports what is actually live
rather than what anyone believes they deployed. Run it before reporting status.

## Starting a new session

Point at the pattern library first — <https://claude.maizegdb.org/pattern_library/>
renders every component in the system. Compose from those rather than inventing
new ones, and reuse the tokens rather than adding colors or spacing values.

A useful opening prompt:

> Read README.md in ~/Documents/ClaudeCode/maizegdb-redesign, then look at
> https://claude.maizegdb.org/pattern_library/ so you are using the existing
> design system. Then modernize <page>, replacing the real route and archiving
> the originals per the policy in the README.
