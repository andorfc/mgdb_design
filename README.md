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

Complete and verified on the development instance:

- Shared design system and pattern library
- Opt-in modern document shell
- Responsive global chrome, no horizontal scrolling from 320px upward
- Keyboard-operable megamenu on modernized pages

Not yet started: the visual redesign of the global header, navigation, and
footer, and the modernization of individual production pages.
