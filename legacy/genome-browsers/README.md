# Legacy genome-browser embed pages

Pre-redesign `/jbrowse` and `/gbrowse` — the embedded browser instances. Both
rendered an `<iframe>` of the real browser host (`jbrowse.maizegdb.org` /
`gbrowse.maizegdb.org`) inside the legacy MaizeGDB chrome, and the gbrowse page
carried a block of JavaScript that widened the legacy `#wrapper` to 1600px and
swapped the legacy `content_top`/`menu_bar` chrome images.

Routing was: `controller.php` → (no top-level controller) → `redirect.php` →
`controllers/tools/<page>.php`, which loaded `templates/tools/<page>.bau`.

The redesign replaces the routes with top-level shadow controllers
`controllers/jbrowse.php` and `controllers/gbrowse.php` (the same pattern as
/jobs and /login): `controller.php` checks `controllers/<CONTROLLER>.php` first,
so those take the routes with the modern shell and no legacy chrome. Deleting
them gives the routes straight back to the untouched files archived here.
