# Publishing the maize AlphaFill results on MaizeGDB: a design recommendation

**Date:** 2026-08-27 · **Data:** 68,262 B73 models, 624,456 transplants, 16,933 genes with ≥1 hit
**Every size figure below is measured on the real data, not estimated.**

---

## 1. The headline recommendation

**Show everything, default to the confident subset, and never let a filter hide the distinction
between "no evidence" and "no ligand".**

Filtering the *release* down to confident predictions would be a mistake, and not for the reason
people usually give. The payload is small enough that size is not a constraint (§3: 2 MB gzipped for
all 16,933 genes), so hiding rows buys nothing. What it costs is real: a curator who cannot see the
weak assignment cannot correct it, and a gene showing "no results" is indistinguishable from a gene
that was never run.

So: publish all 624,456 transplants, but make the **default view** the strong subset, and put the
evidence class on every row.

---

## 2. The metric to expose — collapse first, then tier

### 2.1 Collapse redundancy before anything else
624,456 raw transplants reduce to **133,489 gene × ligand pairs — a 4.7× collapse.** Most of that
redundancy is the same compound transplanted from several homologous donors into the same site. A
user does not want ATP listed 40 times; they want "ATP, best donor 6IUG at 90% identity, 40
supporting donors."

Median distinct ligands per gene after collapse: **5** (p90 = 19). That is a browsable list. The raw
per-gene count (median 15, max 1,075) is not.

### 2.2 A single five-value evidence class, not four raw metrics
Biologists will not internalise local RMSD, TCS, donor identity and pLDDT. Derive one field:

| `evidence` | Definition | gene×ligand pairs | Genes where this is the best available |
|---|---|---|---|
| **strong** | Tier 1: high on both local RMSD *and* TCS, biological ligand, model pLDDT ≥ 70 | 32,247 | **7,897** |
| **moderate** | high on both metrics, biological ligand, pLDDT < 70 | 7,806 | 1,884 |
| **ion** | single-atom metal — may be adventitious in the donor crystal | 39,601 | 5,586 |
| **weak** | biological ligand, lower confidence | 38,295 | 1,417 |
| **additive** | crystallization artifact (nitrate, dioxane, PEG, glycerol…) | 15,540 | 149 |

Keep the four raw metrics visible on the detail card for anyone who wants them — but the badge, the
colour, the sort order and the default filter should all key off `evidence`.

**Default view: `strong` + `moderate`.** Ions and additives ship collapsed behind a toggle labelled
for what they are, not hidden.

### 2.3 The two caveats that must be in the UI, not the docs
- **Ions are 43.8% of all transplants** and Na⁺/Ca²⁺/Mg²⁺ are frequently adventitious in the donor
  crystal. A high transplant count is *not* evidence of biological importance. Ranking genes by raw
  count would put ion-decorated surfaces at the top.
- **9.9% are crystallization additives.** These are properties of the donor's crystallization buffer,
  not of maize. Flag them visually.

### 2.4 Three distinct empty states — this is the one thing most resources get wrong
| State | Genes | What the page must say |
|---|---|---|
| Transplant present | 16,933 | show it |
| Ran, no qualifying donor | 21,427 | "No PDB homolog with a ligand met the 25% identity threshold — **this is not evidence the protein binds nothing**" |
| Not run / no model | 0 (all 68,262 ran) | "Not analysed" |

"No qualifying donor" ≠ "no ligand". Collapsing those two is how an annotation resource
teaches people something false. Since all 68,262 models ran, you can state the third state is empty —
which is itself a useful claim.

### 2.5 P2Rank as an independent second signal
6,599 of the 11,110 Tier-1 proteins have an independently predicted pocket at the same protein. A
"pocket-supported" badge is the strongest evidence class available without experiment, and it is
already computed. Conversely the **1,954 well-modelled genes with a confident pocket but no donor**
are a genuinely interesting "known unknown" list — worth its own browsable view, since it is a
target list for the community rather than a gap to apologise for.

---

## 3. Storage — three tiers, sized on real files

The critical measurement: **ligands are only 4.6% of atoms** in a filled AlphaFill mmCIF (283 of
6,122 in a representative file). Serving whole filled structures is therefore ~20× more bytes than
the science needs.

| Tier | Content | Size (gzipped) | Serve as |
|---|---|---|---|
| **1. Index** | `[gene, chr, pLDDT, n_ligands, n_strong]` × 16,933 | **0.10 MB** | one static file, load on page init |
| **2. Per-gene summary** | collapsed gene × ligand list with evidence + metrics | **2.04 MB** | one static file, or shard by chromosome |
| **3a. Per-protein detail** | slim JSON: ligand, donor, identity, local RMSD, TCS, pocket residue list | **38 MB total** (~1 KB/protein) | one file per protein, static |
| **3b. Ligand coordinates** | HETATM records only, as a parseable mmCIF | **228 MB total** (~7 KB/protein) | one file per protein, static |
| *(not recommended)* | full filled mmCIFs | 4.9 GB | — |

**Tiers 1 and 2 are small enough to be static assets — no database required.** At 2 MB gzipped the
entire gene-level dataset loads in one request. Introducing Postgres here would be infrastructure
without a purpose; add it only if you later need cross-gene queries like "all genes binding FAD with
identity > 0.5", which is a legitimate future reason but not a launch requirement.

### The protein structure itself: don't store it
For the 8,983-equivalent case in maize — every B73 model already exists in your own AlphaFold set,
and the *protein* half of every filled CIF is byte-identical to it. Serve the protein from the model
you already publish and overlay tier-3b ligands at runtime. **That is the 4.9 GB → 228 MB saving,**
and it is exact rather than lossy: I verified the split round-trips at 47/47 ligand copies with
identical centroids.

### Provenance fields to store per row
`alphafill_version` (2.3.0), `pdb_redo_databank_date` (2024-03-08), `run_date`, and the donor PDB ID
hyperlinked to RCSB. When PDB-REDO updates, recall changes — a stamped release lets you say which
version a claim came from.

---

## 4. Showing gene models with predictions

You already have the right pattern in-house: the SNPVersity / SNPImpact `domains/` layout
(`domain_lookup.js`, `domains.by_chr.json`, `domains.by_gene.json`, `domains.by_protein.json`).
**Mirror it exactly** rather than inventing a new shape — same file names, same three-way split, same
binary-search helper. A `domainsAt(intervals, chrom, pos)` lookup works unchanged for ligand sites.

Two adjustments specific to ligands:

1. **Pocket residues are already in the AlphaFill JSON** — the clash-distance records name every
   polymer residue within contact distance (115 distinct residues in the file I checked). No
   separate computation and no extra store; just extract `poly_atom.seq_id`.
2. **Project pocket residues to genomic coordinates the same way you projected Pfam envelopes** —
   residue *p* → coding nt `[(p-1)*3, (p-1)*3+2]`, walked across strand-ordered CDS segments. Then a
   ligand-binding site becomes a genome-browser track exactly like a domain, and a SNP falling in a
   pocket is queryable. **This is the highest-value feature for a maize audience**: "this variant sits
   in the FAD pocket" is a far more actionable statement than a list of predicted cofactors, and it
   connects the resource to the variant data you already serve.

Track design: one row per pocket block, coloured by `evidence`, labelled with the ligand CCD.

---

## 5. Search and loading into the viewer

### Search
Tier-1 index (0.10 MB) supports client-side search with no backend:
- **gene ID** — exact and prefix (`Zm00001eb1273…`)
- **ligand CCD or name** — "FAD", "flavin", "ATP"; needs a CCD → name map, which is in the container
- **evidence class** and **pocket-supported** as facets
- **chromosome / position range** for browser integration

Add a CCD → gene inverted index for ligand-first search. Measured: 1,969 distinct codes, so this file
is tiny. "Show me every maize gene predicted to bind NAD" is the query an enzymologist actually has,
and it is the one a gene-centric layout cannot answer without it.

### Loading into the 3D viewer
The single-file viewer already built for this project is the demo, not the production pattern —
57 MB with structures inlined works for 179 curated proteins and will not scale to 33,002. For
production, invert it: ship the viewer once, fetch data per gene.

```
GET  alphafill.index.json          →  0.10 MB, once
GET  alphafill.by_gene.json        →  2.04 MB, once  (or per-chromosome shard)
click a gene:
GET  models/<GENE>.pdb             →  the AlphaFold model you already host
GET  af/meta/<PROTEIN>.json        →  ~1 KB   (ligands, donors, metrics, pocket residues)
GET  af/lig/<PROTEIN>.cif          →  ~7 KB   (ligand coordinates only)
```

Two 1–7 KB requests per gene click. 3Dmol.js loads the model and the ligand file as separate models,
so pLDDT colouring on the protein and per-ligand colouring both work, and you can toggle individual
transplants without re-fetching.

**One implementation trap, found the hard way:** AlphaFill writes multi-character chain IDs (`AA`,
`AB`, …) into `_atom_site.label_alt_id` once a protein exceeds ~25 transplants — a field the mmCIF
spec limits to one character. Parsers reject the file outright (`Not a single character: AA`).
Sanitize that column when generating tier-3b files. This affects exactly the transplant-rich
proteins, so it is correlated with the interesting cases; it silently removed 36 of 51 cases from my
benchmark before I caught it.

---

## 6. What I would ship first

1. **Index + per-gene JSON + gene page** with the five-value evidence badge, the three empty states
   spelled out, and ion/additive flags visible. Static files, no database. (2.15 MB total)
2. **3D viewer per gene** on the model-plus-ligands split (§5).
3. **Genome-browser pocket track** with CDS projection — the differentiating feature.
4. **Ligand-first search** via the inverted index.
5. **The 1,954 pocket-but-no-donor genes** as a published target list.

Deferred until there is a demonstrated need: a relational backend, per-transplant permalinks, and
the full 4.9 GB mmCIF archive (offer it as a bulk download, not as the serving path).

---

## 7. Honest caveats for the site's own methods page

- A transplant is a **homology-transferred hypothesis**, not an observation and not a docking result.
- Recall is bounded by PDB coverage of maize protein families, not by maize biology: median donor
  identity is 0.321 and 37.5% of transplants fall below 0.30.
- Yield is a steep function of model quality — 10.5% of genes at pLDDT < 50 versus 74.3% at ≥ 85. Show
  per-gene pLDDT so users can calibrate.
- Experimental validation is thin: on 12 clean benchmark cases against solved maize structures, pocket
  location was correct in 9/12 (95% CI 0.47–0.91), median LEV RMSD 1.59 Å. Quote the n.
- 84% of candidate benchmark cases had to be discarded for donor circularity — some maize PDB entries
  *are* the donor. Relevant if anyone benchmarks against this resource.
