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
| Design pattern library | `/pattern_library/` |
| Redesign status | `/redesign_status` |

`REDESIGN_STATUS.md` counts the rest. As of the last run, 19 of the 268 URLs the
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
