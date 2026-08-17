# TYPSimSelector — the pre-redesign files

For `/TYPSimSelector`, replaced by `src/controllers/TYPSimSelector.php`.

Everything here is **still on the server, untouched**. The modern controller
`controllers/TYPSimSelector.php` sits at the top level, and `controller.php`
checks `controllers/<CONTROLLER>.php` before falling through to `redirect.php`,
which is what used to find `controllers/tools/TYPSimSelector.php`. So the route
is shadowed, not overwritten, and rollback is deleting one file.

| Archived here | Server path |
| --- | --- |
| `TYPSimSelector.php` | `controllers/tools/TYPSimSelector.php` |
| `TYPSimSelector.bau` | `templates/tools/TYPSimSelector.bau` |
| `TYPSimSelector-content.bau` | `templates/tools/TYPSimSelector-content.bau` |
| `TYPSimSelector-desc.bau` | `templates/tools/TYPSimSelector-desc.bau` |
| `TYPSimSelector_action.php` | `tools/ajax/typsimselector/TYPSimSelector_action.php` |
| `TYPSimSelector.js` | `js/TYPSimSelector.js` |
| `TYPSimSelector_v2.js` | `js/TYPSimSelector_v2.js` |

None of these are `deploy/manifest.txt` targets, so nothing this repository
deploys can overwrite them.

## Two files the page referenced but that do not exist

Both templates open with `#include-js: /js/bin_viewer.js, include-css:
/css/TYPSimSelector.css#`.

- `/css/TYPSimSelector.css` **is not on the server.** The page's stylesheet has
  been a 404 for as long as the current instance has existed, which is why the
  original renders with unstyled `<table border='6px;'>` results.
- `/js/bin_viewer.js` exists but belongs to the bin viewer and defines nothing
  the page calls. The functions the markup actually invokes —
  `datasetForms()`, `showhide()`, `reset_page()`, `runTYP_results()` — are in
  `/js/TYPSimSelector.js`, which `redirect.php` loaded by coincidence: it
  includes `/js/<CONTROLLER>.js` when such a file exists, and the controller
  happened to be named `TYPSimSelector`. Take the route with a top-level
  controller and that automatic include stops happening, which is why the
  modern page names its script explicitly.

`TYPSimSelector_v2.js` defines `runLLGM()`, which nothing calls. It is a
superseded copy of `runTYP_results()` that predates the dataset selector.

## Two defects in the archived code, recorded so they are not reintroduced

**The breeding dropdown was one line short.** `get_taxa_options2()` builds it
from `SELECT DISTINCT iid1 FROM pidata.ames_merged`. That table holds the
strict upper triangle of the matrix — 4,005,865 rows is exactly
`2831 * 2830 / 2` — so the last line in sort order never appears in `iid1` and
was absent from the interface, while still being reachable as a comparison
target from every other line's ranking.

**The accession columns leaked between rows.** In
`TYPSimSelector_action.php`, `$accession_id1`, `$accession_id2` and
`$accession_number` are assigned inside `if (array_key_exists(...))` and never
reset. A row whose inventory id had no match in `pidata.custom_inventory`
therefore printed the previous row's accession number and GRIN link. 829 of the
3,679 curation accessions carry the `TEMP` placeholder rather than a real
accession, so this was not rare.

Its two accession columns are also swapped against their headers: the column
headed *Accession Number* prints the `PI 601558`-style number, and the one
headed *Accession ID* prints the numeric GRIN id — whose link,
`accessiondetail.aspx?$accession_number`, has no parameter name and does not
resolve.
