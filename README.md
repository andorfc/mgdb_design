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
- Page bodies are fetched over HTTP by `loadRemote()`, so a template must be
  reachable under `/templates/static/` on the same host.
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
