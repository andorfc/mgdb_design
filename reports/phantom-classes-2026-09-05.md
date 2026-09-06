# Phantom CSS classes — full sweep

Generated 2026-09-05. Every class used in `src/templates/`, `src/controllers/`,
`src/search/`, `src/include/` and `src/js/` checked against every class defined in
all 169 stylesheets **served from the dev box** (not just the repo, since some CSS
is server-only).

- classes used: 3200, in 17957 places
- classes defined: 3919
- **used but defined nowhere: 187 classes, 416 occurrences**

A class that matches nothing fails silently and looks deliberate in review. But
"undefined" is not the same as "broken" — three tiers below.

## The sweep under-reports, and here is the proof

"Defined somewhere" is not "defined on the page that uses it", and this check only
tests the first. `.mgdb-metric-card` **passed** the sweep — yet the metric cards on
`/14InbredsFISH` are completely unstyled. It is defined in exactly one stylesheet,
`css/mgdb-variation-record.css`, which is **server-only** (absent from the repo and
from `deploy/manifest.txt`) and is loaded only by the variation record page. Every
other page using that class gets nothing.

So the counts below are a floor. The stronger check, per page, is: classes used in
that page's template against classes defined in the sheets that page actually
loads.

## Two confirmed visible defects

Both are the same root cause: **a parallel component vocabulary that was never
built.** These pages invented names beside components that already exist and work.

| invented (matches nothing) | the real one |
|---|---|
| `mgdb-metric-card` | `mgdb-metric` |
| `mgdb-metric-number` | `mgdb-metric-value` |
| `mgdb-metric-desc` | the `<p>` inside `.mgdb-metric` |
| `mgdb-metric-grid-4` | `mgdb-metric-grid` |
| `mgdb-metric-card-green` / `-gold` / `-blue` / `-orange` | `mgdb-tone-green` and friends |
| `mgdb-btn` / `-primary` / `-secondary` | `mgdb-button` / `-primary` / `-secondary` |
| `mgdb-section-tab` | `mgdb-section-tabs` |

**`/14InbredsFISH`** (and `mgdb_historic_assemblies.bau`, `mgdb_jbrowse2_tutorial.bau`,
`mgdb_whole_genome.bau`, `mgdb_person.bau` — five in all): measured live — metric
number renders at `16px` weight `400`, i.e. body text where a metric value belongs;
all four cards share one border colour despite four different
`-green/-gold/-blue/-orange` modifiers; and the card background is transparent
instead of a white surface.

> **Correction.** A first pass also reported the grid collapsing to "a single 107px
> column". That was not real: the browser pane had a **0-width viewport**, so every
> width on the page read as garbage (`main` 32px, the grid itself 0). Re-measured
> with an explicit viewport, the grid is a correct `279.5px x 4`. Set a viewport
> before trusting any width from that pane — the same trap recorded for overflow
> audits.

**`/person`**: seven `.mgdb-btn` anchors compute to `background: transparent`,
`padding: 0`, `border-radius: 0`, `min-height: auto` — buttons rendering as bare
text links. Six `.mgdb-section-tab` beside them (the real class is plural).

The fix is a rename to the existing vocabulary, not new CSS.

## Tier 1 — element has no other styled class

These carry the phantom and nothing else, so nothing styles them *unless* the tag
has a useful browser default. Verify each on the live page before acting: `.mgdb-link`
is in this tier and turned out harmless, because an `<a>` already inherits the site
link colour and underline.

| class | uses | example attribute | files |
|---|---:|---|---|
| `mgdb-link` | 28 | `mgdb-link` | mgdb_assembly.bau |
| `mgdb-metric-number` | 20 | `mgdb-metric-number` | mgdb_fish_karyotypes.bau, mgdb_historic_assemblies.bau, mgdb_jbr |
| `mgdb-metric-desc` | 20 | `mgdb-metric-desc` | mgdb_fish_karyotypes.bau, mgdb_historic_assemblies.bau, mgdb_jbr |
| `mgdb-btn` | 6 | `mgdb-btn mgdb-btn-primary` | mgdb_person.bau |
| `mgdb-badge` | 6 | `mgdb-badge` | mgdb_assembly.bau, mgdb_expression.bau |
| `mgdb-tool-icon` | 6 | `mgdb-tool-icon` | mgdb_genomes.bau |
| `mgdb-rec-count` | 6 | `mgdb-rec-count` | gel_record_modern.php, locus_record_modern.php, map_scores_recor |
| `mgdb-btn-secondary` | 5 | `mgdb-btn mgdb-btn-secondary` | mgdb_person.bau |
| `mgdb-section-tab` | 5 | `mgdb-section-tab is-active` | mgdb_person.bau |
| `mgdb-rec-synonyms-label` | 5 | `mgdb-rec-synonyms-label` | mgdb-gel-record.js, mgdb-locus-record.js, mgdb-map-scores-record |
| `mgdb-card-top` | 4 | `mgdb-card-top` | mgdb_pattern_library.bau |
| `mgdb-report-link` | 2 | `mgdb-report-link` | mgdb_gene_record.bau, mgdb_pan_gene_record.bau |
| `mgdb-hero-grid` | 1 | `mgdb-hero-grid` | mgdb_person.bau |
| `mgdb-hero-topline` | 1 | `mgdb-hero-topline` | mgdb_person.bau |
| `mgdb-hero-badge` | 1 | `mgdb-hero-badge` | mgdb_person.bau |
| `mgdb-hero-updated` | 1 | `mgdb-hero-updated` | mgdb_person.bau |
| `mgdb-hero-desc` | 1 | `mgdb-hero-desc` | mgdb_person.bau |
| `mgdb-btn-primary` | 1 | `mgdb-btn mgdb-btn-primary` | mgdb_person.bau |
| `mgdb-metric-label` | 1 | `mgdb-metric-label` | mgdb-blast-results.js |

## Tier 2 — modifier on a styled base

The base class works; the *variant* does nothing, so every instance looks identical
where the markup intended a difference.

| class | uses | example attribute |
|---|---:|---|
| `mgdb-rec-api` | 19 | `mgdb-rec-api mgdb-hub-tone-blue` |
| `mgdb-table-zebra` | 13 | `mgdb-table mgdb-table-zebra` |
| `mgdb-tool-card` | 6 | `mgdb-card mgdb-tool-card` |
| `mgdb-metric-grid-4` | 5 | `mgdb-metric-grid mgdb-metric-grid-4` |
| `mgdb-metric-card-green` | 5 | `mgdb-metric-card mgdb-metric-card-green` |
| `mgdb-metric-card-gold` | 5 | `mgdb-metric-card mgdb-metric-card-gold` |
| `mgdb-metric-card-blue` | 5 | `mgdb-metric-card mgdb-metric-card-blue` |
| `mgdb-metric-card-orange` | 5 | `mgdb-metric-card mgdb-metric-card-orange` |
| `mgdb-chart-tall` | 3 | `mgdb-chart mgdb-chart-tall` |

## Tier 3 — page-scope hooks, harmless

`<main>` scope classes sitting beside `mgdb-page` / `mgdb-hub-page` /
`mgdb-record-page`, which do the styling. No rule needs them; they cost nothing.

`mgdb-bac-record-page`, `mgdb-coming-soon-page`, `mgdb-coorddef-page`, `mgdb-est-record-page`, `mgdb-gel-record-page`, `mgdb-marker-record-page`, `mgdb-overgo-record-page`, `mgdb-pattern-library`, `mgdb-phenotype-record-page`, `mgdb-project-page`, `mgdb-recombination-record-page`, `mgdb-reference-record-page`, `mgdb-ssr-record-page`

## Non-`mgdb-` classes (142)

Page-local names. Same check, lower stakes — each belongs to one page.

| file | undefined classes |
|---|---:|
| `mgdb_person.bau` | 7 |
| `mgdb_coordinate_definition.bau` | 7 |
| `mgdb_locus.bau` | 5 |
| `mgdb_caas_fil_project.bau` | 5 |
| `mgdb_amaizing_project.bau` | 4 |
| `mgdb_genome_center.bau` | 4 |
| `mgdb_map.bau` | 4 |
| `mgdb-blast-results.js` | 4 |
| `mgdb-image.js` | 4 |
| `mgdb_european_flints.bau` | 3 |
| `mgdb_qtl.bau` | 3 |
| `mgdb_hilo_project.bau` | 3 |
| `mgdb_fatcat.bau` | 3 |
| `mgdb_alphafill.bau` | 3 |
| `mgdb-map.js` | 3 |
| `mgdb_protein_structure.bau` | 3 |
| `mgdb_data_center_hub.bau` | 3 |
| `mgdb_breeders_toolbox.bau` | 3 |
| `mgdb_reference.bau` | 3 |
| `mgdb_fair_practices.bau` | 3 |
