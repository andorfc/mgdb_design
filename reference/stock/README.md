# Stock data center — Codex reference

The `codex/` tree is the stock-page redesign as it stands on the Codex
instance, fetched from that webroot on 2026-08-15. It is kept because it is
where the legacy advanced search was first repaired.

The claude-instance originals this redesign shadows are archived separately, in
`legacy/stock/`.

## What was taken from it

- **The corrected advanced-search joins.** The claude copy of
  `stock_adv_results.php` cannot execute most of its filters: it joins
  `stock_genotypic_var sgv1 ON sgv1=s.id` and reads `svg1.variation`, aliases
  `stock_phenotypes` as `sp1` and filters on `sp.name`, and queries
  `mgdb.karotypic_variation`. The Codex version replaced all of those with
  `EXISTS` subqueries against the real tables, and parameterized them. Those
  semantics carry over into `src/search/stock/stock_search_lib.php`.
- **The information architecture** of the page: summary figures and a stock-type
  breakdown ahead of the search, the advanced form grouped into availability,
  identity, and genotype/karyotype/phenotype, and collections and NAM founders
  after the results.

## What was not

Its presentation. The Codex page carries its own palette, type scale, and
component set (`stock-hero`, `stock-button`, `stock-metric`, `stock-kicker`).
The redesign rebuilds all of that on the shared tokens and components in
`css/mgdb-modern.css`, per the standing convention in the repository README.

Its result rendering also stayed on the Bauplan HTML-fragment endpoints. The
redesign moves results to `search/stock/stock_search_api.php`, matching the
reference and pan-gene searches.
