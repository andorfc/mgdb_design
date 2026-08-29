# `/fatcat` — the pre-redesign page

Archived 2026-08-29, replaced by `src/controllers/fatcat.php`. The originals are
still on the server; `controller.php` checks `controllers/<CONTROLLER>.php`
before falling through to `redirect.php`, so the new top-level controller takes
the route without touching `controllers/tools/fatcat.php`. Deleting the new file
restores this page immediately.

## What was here

`fatcat.php` read an identifier from `PAGE` or `?uniprot=`, and
`fatcat-content.bau` rendered a 1,050px `<iframe>` pointed at
`https://fatcat.maizegdb.org?uniprot=<id>`. That was the entire page. No
MaizeGDB markup, no `<h1>`, no meta description, and the title was "Welcome to
MaizeGDB".

## Four things wrong with it, worth not repeating

**It rewrote the shell with inline styles.** Twenty lines of jQuery set
`width:1700px` on `#wrapper`, `1424px` on `#logo`, `1400px` on
`#centercolumn_record` and `#content`, `1420px` on `#whitecurve`, `#content_top`,
`#content_bottom` and `#footer`, swapped four menu classes, and replaced two
images — to make one page wider than the site. Half of it ran on
`window.onload` because the footer did not exist yet at parse time.

**Every AlphaFold link on it is dead.** They point at `AF-<acc>-F1-model_v3.pdb`.
EMBL-EBI is on v6 and v1 through v5 all return 404, so the upstream page's own
structure viewer loads a 404 and every "Download Structure (PDB)" link goes
nowhere. Checked 2026-08-29 across eight accessions: only `model_v6` resolves,
and one accession (`Q6ZF65`) has been withdrawn from UniProt entirely and has no
model at any version.

**The comparison it exists to support was left to the reader.** DIAMOND,
Foldseek and FATCAT each pick a top hit per species. Agreement between a
sequence method and two independent structural ones is the whole signal — and
it was rendered as twelve separate panels with the accession codes buried in
prose, so noticing it meant diffing twelve strings by eye.

**The RMSD was computed, shipped, and never shown.** Every superposition file
carries `REMARK result after optimizing N blocks M residues R rmsd`. The page
displayed the FATCAT score and p-value but not the one number that says how
well the two backbones actually fit.

## One thing that was right

The upstream analysis itself. It is a fixed 2022 run over four plant proteomes
and it is not reproducible from anything in this repository — the hit table
lives only inside the application at `fatcat.maizegdb.org`. The replacement
therefore adapts that service rather than reimplementing it; see
`src/search/fatcat/fatcat_lib.php` for what is parsed and how brittle each part
is, and AD-029 for what would remove the dependency.
