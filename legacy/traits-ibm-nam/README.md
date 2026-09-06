# /traits_ibm_nam, before the rewrite

The files that served trait values on the development instance on 2026-09-05,
taken from the server before `controllers/traits_ibm_nam.php` intercepted the
route. None were modified.

    controllers/tools/traits_ibm_nam.php          the page, and its four facet queries
    templates/tools/traits_ibm_nam_search.bau     the legacy frame
    templates/tools/traits_ibm_nam.bau            the form
    templates/tools/traits_ibm_nam-adv-results.bau       results
    templates/tools/traits_ibm_nam-adv-results-page.bau  a results page
    search/traits_ibm_nam/traits_ibm_nam_adv_results.php the search endpoint
    js/traits_ibm_nam.js                          the form's own script

Three things about these worth keeping in view:

**The search endpoint concatenates the request into SQL.** `getStock()` builds
`" AND (s.name like '".$name."' ...)"` from the raw POST value, and `getPOName`,
`getTraitName`, `getReference` and `getEnvironment` do the same. A single quote
in the stock field puts `SQLSTATE[42601]: syntax error at or near "NAM"` into
`logs/mgdb.log`. This endpoint is still reachable — it answers the old page and
is the rollback path — so the fault is still live. Reported to Carson
2026-09-05.

**The Plant Ontology criterion could never match.** Its dropdown is filled by a
query joining `ext_db_key` on `key LIKE 'PO%'`, and no PO key joins
`trait_means_values`: 0 rows of the 713,081 that do. The live page renders that
select with exactly one option, and the results column beside it was blank on
every row.

**`traits_ibm_nam-adv-results.bau` carries a malformed directive.**
`@(comments {display: off` is missing its closing brace, so the literal word
`off` and the HTML comment it was meant to hide are both printed at the top of
every response. The comment says in-page search was disabled in 2020;
it was not — the endpoint answers, and the old page shows its results.

## The endpoint was retired on 2026-09-05

`search/traits_ibm_nam/traits_ibm_nam_adv_results.php` is **no longer in the
webroot** (Carson). It was removed after establishing that nothing on this
instance still called it:

- `/traits_ibm_nam` is the modern page and uses the new JSON endpoint.
- The trait record page's "Measured values" section summarises and links out;
  it never called this.
- The modern stock record page has no trait-values section at all.
- The two legacy templates that embedded one -- `stock_sections.bau` and
  `trait_sections.bau` -- carry it as `@(... {display: off}` with the note
  "unused as of 05/14/20". It had been switched off for five years.
- Nothing loads `js/trait.js`. `js/stock.js` is loaded by four pages and none
  of them invoke its traits functions.

Confirmed after removal: `stock=NAM'` adds **zero** SQLSTATE lines where it
previously added one, and /traits_ibm_nam, both record pages,
/data_center/cytogenetic and /breeders_toolbox all still answer without a fatal.

A byte-identical copy is preserved on the server at
`/var/www/claude/removed-from-webroot-20260905-traits/` (md5
475135060e76f1338b99fdf28cecae2a) as well as here.

**Rollback is two steps now.** Restore the endpoint from this directory *and*
delete `controllers/traits_ibm_nam.php`. Deleting the interceptor alone gives
back a page whose search is a 404.
