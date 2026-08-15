# Stock record page — pre-redesign originals

Archived per the standing replace-a-page policy in the repository README, for
`/data_center/stock/{id}`.

| File | What it was | Still live on the server? |
| --- | --- | --- |
| `stock_data.php` | The Ajax endpoint. Six `?type=` modes, each returning a fragment of HTML. Replaced by `/api/v1/records/stock/{id}`. | Yes, no longer called from this page. |
| `stock_sections.bau` | The Bauplan template `stock_data.php` filled in. | Yes, no longer loaded. |
| `stock.bau`, `stock-right.bau` | The record page body and its right column. | Yes, no longer loaded. |
| `stock_functions.php` | `check_id()`, `get_nav_array()`, `get_section_array()` — the section list `data_view.bau` looped over. | Yes, and **still needed**: `data_center.php` requires those three functions for any data centre record page, and the guard only takes the stock route. |
| `stock.php` | The record sub-controller. | Yes, no longer reached. |
| `stock.js` | Section toggles and the trait-value table. | Yes, and **still in use** by other pages that include it. |
| `data_view.bau` | The shared record shell for *every* data centre. Untouched. | Yes, and **still in use** by every other record type. |

Nothing here should be deleted from the server. `data_view.bau` and
`stock_functions.php` in particular are shared infrastructure.

## What the six calls became

`stock_data.php` was called once each for `top`, `overview`, `annotations`,
`related_records`, `grin_information`, and `offsite_resources`, sharded across
`ajax1..N.maizegdb.org` subdomains to get past the browser's per-host
connection limit. Between them they ran about twenty-five queries, including a
per-row image lookup for every genotypic variation — a stock with 175 of them
made 175 extra round trips to the database.

The replacement is one request, eleven queries.

## The annotations section

`stock_data.php?type=annotations` rendered user annotations, which are
per-viewer and depend on the login cookie. The API is public and cacheable, so
it does not carry them and the modern page does not show them. The legacy code
that produced them is preserved here; the section was already dead in practice,
its curation half disabled in the source with the comment "broken, and no one
used it when it worked".
