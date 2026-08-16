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
reference/                     Unmodified copies of the files being replaced
tools/redesign_status.py       Measures how much of the site is modernized
REDESIGN_STATUS.md             Its report. Generated; do not edit by hand
docs/BASELINE_AUDIT.md         Architecture and findings from the initial audit
ADMIN_DEPENDENCIES.md          Changes that need an administrator
backups/<timestamp>/           Automatic pre-deploy snapshot of every server file
deploy/manifest.txt            local path -> webroot destination mapping
```

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
| BAC search | `/data_center/bac` | `controllers/data_center/bac_search_modern.php` | `legacy/bac/` |
| Cytogenetics | `/data_center/cytogenetic` | `controllers/data_center/cytogenetic_search_modern.php` | `legacy/cytogenetic/` |
| EST search | `/data_center/est` | `controllers/data_center/est_search_modern.php` | `legacy/est/` |
| Overgo search | `/data_center/overgo` | `controllers/data_center/overgo_search_modern.php` | `legacy/overgo/` |
| SSR search | `/data_center/ssr` | `controllers/data_center/ssr_search_modern.php` | `legacy/ssr/` |

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

## Modernizing a page

**1. Opt the controller in to the modern shell.**

```php
$bauplan = new Bauplan('Page Title | MaizeGDB');
$bauplan->modern();   // DOCTYPE, viewport, <html lang>, body class

$bauplan->includeCss('/css/mgdb-modern.css?v=' . filemtime($system['root_dir'] . '/css/mgdb-modern.css'));
$bauplan->includeScript('/js/mgdb-modern.js?v=' . filemtime($system['root_dir'] . '/js/mgdb-modern.js'));
$bauplan->includeScript('/js/mgdb-chrome.js?v=' . filemtime($system['root_dir'] . '/js/mgdb-chrome.js'));
```

`modern()` is opt-in by design. Legacy pages were authored against quirks-mode
box sizing and a fixed 1280px wrapper; switching them to standards mode or a
device-width viewport would change their layout. Pages that do not call it
render byte-identically to before.

Add `$bauplan->bodyClass('mgdb-wide')` for data-dense pages that need the full
1280px wrapper instead of the 1080px content column.

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
- Cache busting uses `filemtime()` appended as `?v=`; keep that pattern.
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
| Stock search | `/data_center/stock` |
| Stock record | `/data_center/stock/{id}` |
| Reference record | `/data_center/reference?id={id}` |
| BAC search | `/data_center/bac` |
| Cytogenetics | `/data_center/cytogenetic` |
| EST search | `/data_center/est` |
| Overgo search | `/data_center/overgo` |
| SSR search | `/data_center/ssr` |
| Analysis projects | `/projects` |
| Protein domain atlas | `/projects/interpro_domain_atlas` |
| Design pattern library | `/pattern_library/` |
| Redesign status | `/redesign_status` |

`REDESIGN_STATUS.md` counts the rest. As of the last run, 25 of the 268 URLs the
site exposes are on the design system.

Foundations complete: the shared design system, the opt-in modern document
shell, the responsive global chrome and mega menu, the blue page ground, and a
single rail shared by the chrome and the page content.

## Starting a new session

Point at the pattern library first — <https://claude.maizegdb.org/pattern_library/>
renders every component in the system. Compose from those rather than inventing
new ones, and reuse the tokens rather than adding colors or spacing values.

A useful opening prompt:

> Read README.md in ~/Documents/ClaudeCode/maizegdb-redesign, then look at
> https://claude.maizegdb.org/pattern_library/ so you are using the existing
> design system. Then modernize <page>, replacing the real route and archiving
> the originals per the policy in the README.
