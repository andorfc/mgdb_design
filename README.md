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

## Analysis projects

`/projects` is a third kind of page, alongside the data centres and the tools.
A data centre searches the production database and returns whatever is in it
today; a tool takes input and computes an answer; a project is a *finished
analysis* — a fixed result with its figures, tables, methods and downloads,
which does not change until the analysis is re-run. None of it touches the
database.

```
include/projects_lib.php                the registry: every project, and the topic vocabulary
controllers/projects.php                routes /projects, /projects/<slug>, and the 404
controllers/projects/<slug>.php         one project's page controller
templates/static/mgdb_project_<slug>.bau  its body
data/projects/<slug>/                   its payload, downloads and figures
```

The registry is the single source of truth: the listing cards, the routing, the
filter chips and the breadcrumbs all derive from it, so a project cannot appear
in one and be missing from another. An unrecognized slug is a real `404` rather
than the listing page with a message.

There is deliberately **no `projects/` directory in the web root**. A real
directory at that path would stop `.htaccess` rewriting `/projects/...` to
`controller.php` at all — the same trap documented for `/api` above. Project
data therefore lives under `data/projects/<slug>/`, where Apache serves it
directly. For the same reason a slug must not contain the characters `js`: the
sitewide rewrite skips any URI matching the unanchored pattern `(.js)`.

**Adding a project** is an entry in `mgdb_projects()`, a controller, a body
template, and its data files in the manifest.

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
AD-021 — `table_name` is the table the *text* came from, not the type of the
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

### Verification

`ORDER BY` ends in the primary key for every type. Without a total order two
records that tie on name and length swap places between queries, which lets a row
appear on two pages or on none; 6 pages deep on the four largest result sets
returns 150 distinct rows and no duplicates. The temp-table path and the inline
fallback were checked to return identical results across 132 type queries.

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
at `/index3/`. Folding those rules into `mgdb-home.css` and dropping the class
is tidying, not a fix, and is best done when nobody is mid-review.

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

### Design alternatives for review

Two variants sit alongside the default for the group to compare. Both are
`noindex` and neither is linked from anywhere:

| | Art (desktop) | Caption | Card | Header |
|---|---|---|---|---|
| `/` and `/index3/` | 132px, no disc, 116px art | label only | none until hover | warm header |
| `/index2/` | 104px disc, 84px art | label only | bordered | record hero |
| *`legacy/home-dashboard/`* | 74px disc, 56px art | label + description | bordered | record hero |

`/index3/` still serves the same page as `/` — it is left in place so the group
can keep using the review URL. Delete it, `pages/index2`, and their manifest
entries once nobody needs the comparison.

Everything behind the three pages is shared. `include/home_lib.php` holds the
release-date query, the precomputed metric counts, and the news rendering, so
all three show identical data and only the presentation differs. That library
was extracted from `index.php` on 2026-08-25 for exactly this reason — the
alternative was three copies that would drift.

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

When a design is chosen, fold its template and rules back into `index.php` and
`mgdb_home.bau`, then delete `pages/index2`, `pages/index3`,
`mgdb-home-alt.css`, the two variant templates, and their five manifest entries.

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
- **`min-height` matches the line height**, so the panel does not jog as the
  text changes. Verified by hovering all twenty: the panel stays 234px.
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

### One link lost

The removed footer held **Archived Data Hubs** \(`/archive`\), which now has no
link anywhere in the menu. It is still reachable from the site map.

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
