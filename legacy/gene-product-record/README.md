# Gene product record page — pre-redesign originals

Archived per the standing replace-a-page policy in the repository README, for
`/data_center/gene_product?id={id}`.

| File | What it was | Still live on the server? |
| --- | --- | --- |
| `gene_product_data.php` | The Ajax endpoint. Five `?type=` modes (`top`, `overview`, `annotations`, `related_data`, `offsite_resources`), each returning a fragment of HTML. Replaced by `/api/v1/records/gene_product/{id}`. | Yes, no longer called from this page. |
| `gene_product_sections.bau` | The Bauplan template `gene_product_data.php` filled in. | Yes, no longer loaded. |
| `gene_product.bau` | The record page body: the script that fired the five Ajax calls and the section checkboxes. | Yes, no longer loaded. |
| `gene_product_functions.php` | `check_id()`, `get_nav_array()`, `get_section_array()` — the section list `data_view.bau` looped over. | Yes, and **still needed**: `data_center.php` requires those three functions for any data centre record page, and the guard only takes the gene product route when the identifier resolves. |
| `gene_product.php` | The record sub-controller: two `replace()` calls. | Yes, no longer reached. |
| `gene_product.js` | The reference show/hide toggle. | Yes, no longer loaded by this page. |

Nothing here should be deleted from the server. `gene_product_functions.php`
is shared infrastructure: an identifier the modern controller cannot resolve
falls through to the original code, which still calls `check_id()` on its way
to the not-found template.

## What the five calls became

Between them the five modes ran one query per row for most of what they
showed: a term lookup for every induced-expression condition and evidence, a
person lookup and a URL-prefix lookup for every external database key, and a
term lookup plus a reference lookup for every citation. The request's `id` was
interpolated straight into SQL in all of them.

The replacement is one request and twenty-one parameterized queries, every one
an indexed probe on the resolved id, answering in about 80 ms.

## Two things the legacy code got wrong, not ported

- **Ontology terms were looked up under the wrong table name.** `showAnnotations()`
  called `getOBOTerms(..., $id, 'locus')` — copied from the locus page — so the
  query could never match a gene product. The API asks for
  `table_name = 'gene_product'`. There are currently no validated terms under
  that name either, so the section is empty for every record, but it is now
  empty for the right reason.
- **Relations were shown in one direction only.** `relation` stores a row once,
  from one product to the other, so "Subunit of" appeared on one record and
  nothing on its partner. The API returns both directions and labels the
  inverse rows.

## The annotations section

`?type=annotations` also rendered a viewer's own pending community annotations
when they were logged in. The API is public and cacheable, so it carries only
approved annotations (`curation_lvl = 0`), which every viewer could already
see. The curation forms in the template were already disabled in the source
with the comment "broken, and no one used it when it worked".
