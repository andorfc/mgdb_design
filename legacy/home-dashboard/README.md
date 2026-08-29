# Homepage before design 3

The homepage as it stood on 2026-08-25, immediately before `/index3/` was
promoted to the real route. Kept so the change is reversible without digging
through `backups/`.

What it was: the "data dashboard" direction — `.mgdb-hero-record` for the
header (the same component the gene, stock, map, and reference record pages
use), quick-link tiles at 74px with a one-line description under each, and a
Common tasks panel in the rail.

## Rolling back

```
cp legacy/home-dashboard/mgdb_home.bau src/templates/static/mgdb_home.bau
cp legacy/home-dashboard/index.php     src/index.php
deploy/deploy.sh src/templates/static/mgdb_home.bau
deploy/deploy.sh src/index.php
```

The stylesheet needs nothing: `css/mgdb-home-alt.css` only adds rules scoped to
`.mgdb-home-v3`, and this template does not carry that class.
