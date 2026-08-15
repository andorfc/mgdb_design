# Stock data center — pre-redesign originals

Archived per the standing replace-a-page policy in the repository README, for
`/data_center/stock`.

| File | What it was | Still live on the server? |
| --- | --- | --- |
| `stock_search.php` | Search sub-controller. Populated the option lists for the advanced form. | Yes, but no longer reached: the guard in `controllers/data_center.php` runs first. |
| `stock_search.bau`, `stock-left.bau` | The search page body. | Yes, but no longer loaded. |
| `stock-results.bau`, `stock-adv-results.bau` | Result markup for the legacy search. | Yes, and `stock-results.bau` is **still in use** by the site-wide all-data and shadowbox searches. |
| `stock_results.php`, `stock_results_lib.php` | The legacy simple-search endpoint. Replaced for this page by `search/stock/stock_search_api.php`. | Yes, and **still in use** by the all-data search. |
| `stock_adv_results.php` | The legacy advanced-search endpoint. | Yes, but only this page ever called it. |
| `grin_results.php` | The legacy GRIN endpoint. Its own header marks it obsolete. | Yes, no longer called from this page. |
| `stock.css`, `stock.js` | Page styles and behavior. | Yes, and **still in use** — stock *record* pages load both. |

Nothing in this directory should be deleted from the server. Several of these
files are shadowed for this one route but still serve record pages and the
site-wide search.

## A note on the legacy advanced search

`stock_adv_results.php` as archived here could not have worked for most of its
filters. It joins `mgdb.stock_genotypic_var sgv1 ON sgv1=s.id` (a table
compared to an integer) and then reads `svg1.variation`; it aliases
`stock_phenotypes` as `sp1` and then filters on `sp.name`; and it queries
`mgdb.karotypic_variation`, which does not exist. Any search using genotypic
variation, phenotype, or karyotypic variation would have errored rather than
returned results.

The corrected joins are in `search/stock/stock_search_lib.php`, and follow the
repair already made on the Codex instance.
