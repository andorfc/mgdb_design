# Data semantics — read before writing code

Three distinctions in this dataset will silently produce wrong numbers if collapsed. Each one
caused a real error during development.

---

## 1. Two measures: INCLUSIVE vs EXCLUSIVE

### Inclusive — the 36-class functional ontology
Files: `data/gene_lists/class_gene_lists_*.tsv.gz`, `data/counts/class_gene_counts_*.tsv`
Endpoints: `/classes`, `/counts`, `/genes`

A gene appears under **every class** for which it carries a matching InterPro domain. A
wall-associated receptor kinase carries both an LRR and a protein-kinase domain, so it is counted
under `Immunity: receptor kinase` *and* `Immunity: LRR (receptor/adaptor)`.

This is deliberate: someone browsing for "receptor kinase" and someone browsing for "LRR" should
both find that gene. The consequence is that **class counts do not sum to the gene total** and
summing across classes is meaningless.

### Exclusive — architecture-precedence immunity calls
Files: `data/gene_lists/immunity_calls_*.tsv.gz`, `data/counts/immunity_counts_*.tsv`
Endpoint: `/immunity`

Each gene is assigned to **exactly one** class by domain-architecture precedence:

```
NLR > NLR_partial > RLK > RLP > PR > IMMUNE_SIGNALING > IMMUNE_OTHER
```

These counts are strictly **lower** than the corresponding inclusive `Immunity: *` classes, and
they *do* sum meaningfully.

### The test that catches a collapse

| Query | Correct answer |
|---|---|
| Inclusive `Immunity: NLR (NBS-LRR)` for Zm-B73-REFERENCE-NAM-5.0 | **144** |
| Exclusive `NLR` for the same genome | **122** |

If your implementation returns the same number for both, you have merged the two measures.
The dashboard labels every panel with which measure it shows; preserve that in the UI.

---

## 2. Two annotation arms — never pool

| Arm | Gene models | Genomes |
|---|---|---|
| `reference` | Curated MaizeGDB / Ensembl Plants annotations | **45** Andropogoneae + 6 outgroups |
| `helixer` | Helixer 0.3.6 *ab initio* predictions | **46** Andropogoneae |

`Zl-RIL003-REFERENCE-PanAnd-1.0` has no curated proteome, so it exists **only** in the Helixer arm.
That is why the arm counts differ by one — not a data error.

Helixer predicts roughly 15% more genes than curated sets in maize, at 76% locus concordance with
curated B73 models. This is expected for an *ab initio* caller. Therefore **a count difference
between arms is a statement about annotation method, not about genome content.** Never average,
sum, or silently default across arms; always show which arm a number came from.

---

## 3. Ploidy normalization

`monoploid_genomes` in the genome metadata is the divisor for `normalize=monoploid`.

- **Wheat (`Triticum_aestivum`) = 3.** Allohexaploid AABBDD. Its raw NLR-class count of 2390
  divides to 796.7 per monoploid genome, which puts it in line with barley and the
  other cereals. Without this, wheat looks like a massive outlier in every plot.
- **Every other genome = 1**, including soybean. Soybean has undergone two ancient whole-genome
  duplications (~13 and ~59 Mya), but its retained duplicates are **real present-day gene copies**,
  not an artifact of assembly ploidy. Dividing them would understate its true gene content.

**Always keep the raw value one click from the normalized one.** A normalized number shown alone
is not reproducible by a user who downloads the underlying table.

---

## 4. Genome scope: 46, 48, 50, 52 — which is which

| Number | Meaning |
|---|---|
| **48** | Source assemblies downloaded from MaizeGDB |
| **46** | Distinct Andropogoneae genomes = 48 − 2 Tripsacum alternate haplotypes (`Td-*-2.0b`) |
| **52** | Served genome list = 46 Andropogoneae + 6 outgroup species |
| **45 / 46** | Per-arm Andropogoneae counts (reference / helixer) |

The two `Td-*-2.0b` entries are the **alternate haplotype assemblies of the same individuals** as
the `2.0a` entries — not subgenomes. Pooling them would double-count alleles. They were annotated
and scanned, so they appear in `data/gene_lists/`, but they are excluded from the primary matrix,
from `web/domain_center_data.json`, and from every figure.

This is why `immunity_calls_reference.tsv.gz` has **79,177** rows (47 assemblies) while the
dashboard reports **75,299** (45 primary-matrix genomes). Both are right. Choose one scope for your
API, state it in the response, and hold to it.

---

## 5. Counting unit

Counts are **gene** counts, never domain-occurrence counts. A gene carrying three tandem LRR
domains contributes **1** to `Immunity: LRR (receptor/adaptor)`, not 3.

- Reference arm: longest protein per gene (isoform ratio 1.78 collapsed to 1).
- Helixer arm: Helixer emits one transcript per gene, so no isoform selection was needed.

---

## 6. Gene identifiers are genome-specific

Gene IDs differ in format across arms and sources:

| Arm / source | Example |
|---|---|
| Reference (MaizeGDB) | `Zm00001eb000610` |
| Helixer | `Av-Kellogg1287_8-REFERENC_chr4_000066` |
| Outgroup (Ensembl) | `AT1G05830` |

They are **not orthology-mapped**. A set intersection of gene IDs across two genomes returns
nothing. Cross-genome comparison must go through counts, or through an orthogroup table that this
package does not include.

One quirk to know about: Helixer truncates its `--species` argument in emitted IDs, so the
`Td-KS_B6_1` 2.0a and 2.0b haplotypes produce identical ID prefixes. This is harmless because all
joins key on `(assembly, gene)` and output is stored per assembly — but a loader that keys on the
bare gene ID would silently merge them. See `provenance/id_truncation_note.json`.
