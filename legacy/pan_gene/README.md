# Pan-gene search — pre-redesign originals

Archived per the standing replace-a-page policy in the repository README, for
`/pan_gene_center/pan_gene`.

| File | What it was | Still live on the server? |
| --- | --- | --- |
| `pan_gene_center.php` | The controller, before the guard that routes the search page to `pan_gene_search_modern.php`. Restore this file to roll the page back completely. | Yes — overwritten by the version carrying the guard. |
| `pan_gene_search.php` | Search sub-controller. Populated the option lists, the distribution chart, and the annotation table. | Yes, but no longer reached: the guard runs before it. |
| `pan_gene_search.bau`, `pan_gene-left.bau` | The search page body. | Yes, but no longer loaded. |
| `pan_gene-results.bau`, `pan_gene-results-page.bau` | Result markup for the legacy search. | Yes, and **still in use** — `search/pan_gene/pan_gene_results.php` loads them for the site-wide all-data and shadowbox searches. |
| `pan_gene_results.php`, `pan_gene_adv_results.php`, `pan_gene_results_lib.php` | The legacy search endpoints. Replaced for this page by `search/pan_gene/pan_gene_search_api.php`. | Yes, and `pan_gene_results.php` is **still in use** by the all-data search. |
| `pan_gene.css`, `pan_gene.js` | Page styles and behavior. | Yes, and **still in use** — pan-gene *record* pages load both. |

Nothing in this directory should be deleted from the server. Several of these
files are shadowed for this one route but still serve record pages and the
site-wide search.
