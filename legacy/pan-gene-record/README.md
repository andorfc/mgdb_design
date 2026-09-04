# Pan-gene record page — pre-redesign originals

Archived per the standing replace-a-page policy in the repository README, for
`/pan_gene_center/pan_gene/{id}`.

| File | What it was | Still live on the server? |
| --- | --- | --- |
| `pan_gene_data.php` | The Ajax endpoint, 1,665 lines. Fifteen `?section=` modes — `analysis`, `members`, `pangenome`, `alignment`, `domains`, `tree`, `insertions`, `metabolomics`, `expression`, `proteomics`, `traits`, `function`, `sequence`, `downloads`, `3rdparty` — each returning a fragment of HTML, plus `data_details` and `protein_structure` for the expand-in-place rows. Replaced by `/api/v1/records/pan_gene/{id}`. | Yes, no longer called from this page. |
| `pan_gene_locus_data.php` | A second Ajax endpoint for the per-locus tab, which summarised a locus record inside the pan-gene page. The modern page links to the locus record instead and lists the associations in Overview. | Yes, no longer called from this page. |
| `pan_gene.php` | The record sub-controller: two `replace()` calls. | Yes, no longer reached. |
| `pan_gene_functions.php` | Helper functions the record page shared with the search page. | Yes, and still needed by the search page. |

The Bauplan templates under `templates/pan_gene_center/` are not archived here:
they are outside the deployed document root and several are shared with the
pan-gene search page, which still uses them.

## What the fifteen calls became

One request and twenty parameterized queries, answering in about 550 ms.
Measured on the page's own example, `Zm00023ab070050_T001` — a pan-gene of 65
members across 64 assemblies.

| Section | Legacy Ajax call | Modern share of one request |
| --- | --- | --- |
| proteomics | 3.39 s | folded into `proteins` |
| insertions | 1.36 s | 58 ms |
| expression | 0.73 s | 35 ms |
| domains | 0.43 s | 42 ms |
| members | 0.40 s | 35 ms |
| the other ten | 2.9 s combined | the rest of 550 ms |
| **all fifteen** | **9.3 s** | **0.55 s** |

## Where the legacy time went

- **A query per member, several times over.** Two thirds of a large pan-gene's
  members are from annotations with no gene pages, so they have no row in
  `chado.gene_model`; `queryPanGeneMembers()` called `getAnnotationForGeneModel()`
  and `getAnnotationAssemblyName()` for each one, two queries per member.
  `showPanGeneProteomics()` then called `getAnnotationForGeneModel()` again for
  every member, and `showDomains()` called `getProteinDomains()` once per
  member. On this record that is over 200 round trips for data that three
  queries supply.
- **A blocking HEAD request inside three sections.** `alignment`, `tree` and
  `pangenome` each asked another host whether a file existed, one at a time.
  The modern resource asks for all four URLs at once with `curl_multi`, which
  is the one part of the 550 ms that is not the database.
- **Numeric versus bigint.** `perm_tables.id_ontology.reference` and `.source`
  are `numeric` while `mgdb.reference.id` and `mgdb.person.id` are `bigint`.
  Joined bare, Postgres casts the indexed side to `numeric` and the ontology
  query costs 118 ms; casting the `numeric` column instead leaves the indexes
  usable and it costs 21 ms.

## Two defects found while porting

- **The CornCyc pathway lookup silently dropped two features.**
  `getPathwayInfo()` built its `IN` list as
  `implode("','", $gm_names) . implode("','", $tr_names)` — no separator
  between the two lists — so the last gene model and the first transcript were
  glued into one string that matches nothing. Both were absent from every
  CornCyc result. Fixed in the modern resource, which matches members and
  transcripts in one condition.
- **The insertions heading named only the last gene model.** `showInsertions()`
  looped over every member with insertions, appending rows to one table but
  re-`replace()`ing the single heading each time, so a table drawing on several
  gene models was captioned with whichever came last. The modern table carries
  the gene model as a column.

## And one thing to be careful about

`chado.pan_gene`, `chado.pan_gene_loci` and the rest are reached from a URL
segment that the legacy `queryPanGene()` interpolates straight into its SQL, in
five places, with no escaping and no parameter binding. The modern
`include/pan_gene_record_lib.php` binds every value. The legacy path is still
reachable through the rollback route in `controllers/pan_gene_center.php`.
