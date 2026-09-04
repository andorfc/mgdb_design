# Phenotype record page — pre-redesign originals

Archived per the standing replace-a-page policy in the repository README, for
`/data_center/phenotype?id={id}`.

| File | What it was | Still live on the server? |
| --- | --- | --- |
| `phenotype_data.php` | The Ajax endpoint. Five `?type=` modes — `top`, `overview`, `annotations`, `variations`, `stocks` — each returning a fragment of HTML, with references and images folded into them. Replaced by `/api/v1/records/phenotype/{id}`. | Yes, no longer called from this page. |
| `phenotype.php` | The record sub-controller: two `replace()` calls naming the record type. | Yes, no longer reached. |
| `phenotype_functions.php` | `check_id()`, `get_nav_array()`, `get_section_array()`. | Yes, and **still needed**: `data_center.php` requires those three functions for any data centre record page. |

The Bauplan template the endpoint filled in, `templates/data_center/phenotype_sections.bau`,
is not archived here: it is outside the deployed document root and is shared
with the phenotype search results, which still use it.

## What the five calls became

One request and fourteen parameterized queries, answering in about 120 ms for
`dwarf plant` — the largest phenotype in the database, with 309 variations, 112
stocks and 203 images.

## Three things the legacy page did that the modern one does not

- **A locus query per variation.** `show_variations()` ran
  `SELECT … FROM locus … WHERE a.id = {variationof}` inside the loop over
  variations. On `dwarf plant` that is 309 extra round trips for data one join
  supplies.
- **Genes as a string, with no counts and a dead route.** `read_genes()`
  concatenated `<br>`-joined links to `/gene_center/gene/{id}`, which no longer
  resolves, and gave no indication that `d3` accounts for 27 of the variations
  while eleven other loci account for one each. The modern page sorts by that
  count and charts it.
- **Stocks packed into three unlabelled columns.** `show_stocks()` built keys
  `stock_name1`..`stock_name3` per row to fake a three-column layout, bolded the
  ones held by the Stock Center, and dropped the provider name. The modern page
  is a sortable table with the provider as a column.

## Counts differ from the legacy page, deliberately

The record header used to report 311 variations and 203 stocks. Both figures
counted rows the site withholds: filtering on `id_num.curation_lvl = 0`, the
same filter every section already applied, gives 309 and 112.
