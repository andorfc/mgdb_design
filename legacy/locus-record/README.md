# Locus record page — pre-redesign originals

Archived per the standing replace-a-page policy in the repository README, for
`/data_center/locus?id={id}` — the site's second-busiest record page (19,774
requests over six days of production logs, 9,444 distinct records, 15,771
distinct client addresses, every user agent a browser).

| File | What it was |
| --- | --- |
| `locus.php`, `locus_functions.php` | The data-centre sub-controller, `check_id()`, and the nav/section arrays. |
| `locus_data.php` | The Ajax dispatcher: nine section types, one HTTP request each. |
| `locus_data_lib.php` | 2,060 lines of section builders and helpers. |
| `locus_*_gene_sections.bau` | The section templates, shared with the gene record page. |
| `locus_notfound.bau` | The soft-404 body. |

Nothing here should be deleted from the server.

## Twenty-six types, one page

`mgdb.locus` holds 26 curated types, from 686,356 Points down to 3 Contiguous
Sequences. **They share one section set.** The legacy page has exactly one type
branch — `if ($arrRecord['type_name'] == 'Gene')` labels the identity fields
"Gene symbol / Gene name" instead of "Name / Full name" — and every other
difference between a Centromere and a QTL is which sections have rows.

**Loci of type `Gene` (26,115) never render here.** `check_id()` redirects them
to `/gene_center/gene/{id}`, and the modern controller preserves that.

## Ported, with the data verified

Every distinct record the legacy page links was compared against the modern
API for one rich example of each of the 25 non-Gene types: **25 of 25 complete,
0 gaps**. Sections: identity and description, functional statements, critical
comments, curator notes, properties, expression induction, gene products, the
Maize Gene Review flag, assembly issues, map positions, nearby loci on four
mapsets, alleles and their phenotypes and images, stocks, the probes that
detect it (SSR / overgo / EST / BAC / other), primers and enzymes, related
BACs, gel patterns, map scores, recombination data, related loci, associated
gene models, external entries (locus, probes, variations, gene products, NCBI
Gene), ontology terms, and references.

## Deliberately not ported

- **Chromosome Coordinates.** `showChrCoords()` opens with
  `// Removed, per request by Ed Coe` and `return;` — the section has rendered
  nothing for years, and its tab was commented out of `get_nav_array()`.
- **Molecular information / Sequences.** Reads `mgdb.z_sequence`, which has
  **0 rows**. The same empty table killed the Sequence sections on the marker,
  overgo, EST and BAC pages.
- **Domain experts.** `read_domain_experts()` is defined and never called.
- **Quick Summary.** Commented out in the source, marked "removed by popular
  demand".
- **Jira issues.** Commented out, and `collect.maizegdb.org` returns 502.
- **User annotations.** A logged-in curator feature. The public record shows
  the ontology terms, which is what an anonymous reader saw before.

## Physical positions

`showPhysicalPositions()` calls `gblade.usda.iastate.edu/etc/logic/get_features.php`
once per assembly. **That service is alive** — it returns real coordinates — so
the feature was ported, not dropped.

It is the record's only outbound dependency, at ~275 ms a call, so it is an
opt-in API section (`?fields=physical`) that the page fetches on its own once
the rest has rendered. The two assemblies are fetched together with
`curl_multi`, which the legacy page did sequentially.
