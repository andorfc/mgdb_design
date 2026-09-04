# Metabolic Pathways — pre-redesign originals

Archived per the standing replace-a-page policy in the repository README, for
`/metabolic_pathways` and its three documentation pages.

| File | What it was | Still live on the server? |
| --- | --- | --- |
| `metabolic_pathways.bau` | The landing page. Two launch panels for the CornCyc instances MaizeGDB hosted (RefGen_v4 and RefGen_v3), then Information and Tutorials, Downloads, External Links, Contact us. | Yes, but no longer loaded: `controllers/metabolic_pathways.php` now answers the route before `redirect.php` can look for a template of that name. |
| `metabolic_pathways-compare.bau` | CornCyc vs MaizeCyc pipelines and statistics. | Yes, not loaded. Content preserved in `templates/static/mgdb_metabolic_pathways_compare.bau`. |
| `metabolic_pathways-install.bau` | Pathway Tools + MaizeCyc local installation guide for Mac. | Yes, not loaded. Content preserved in `mgdb_metabolic_pathways_install.bau`. |
| `metabolic_pathways-omics_viewer.bau` | The Omics viewer tutorial, revised from Monaco et al. 2013. | Yes, not loaded. Content preserved in `mgdb_metabolic_pathways_omics.bau`. |

Nothing in this directory should be deleted from the server.

## The prose was kept, the CornCyc instances were not

All three documentation pages carry their original wording. What changed is the
frame — the Data Hub shell instead of nested `<table>` layout — and a notice on
each page saying that the CornCyc instances the text refers to are retired and
where the maintained build is.

`corncyc-b73-v4.maizegdb.org` and `corncyc-b73-v3.maizegdb.org` were both still
answering (HTTP 200, ~36 KB) when they were unlinked on 2026-09-02. **Unlinking
is not decommissioning.** Deciding whether those two subdomains stay up, and for
how long, is a server decision that was not made here; the pages simply stop
sending readers to them.

## What was dropped, and why

- **The RefGen_v3 and RefGen_v4 launch panels.** They were the page's two
  largest elements and both pointed at the retired instances.
- **`http://www.genome.jp/dbget-bin/get_linkdb?-t+2+gn:T01088`**, the KEGG maize
  gene link. It answers **HTTP 400** — with a browser user agent too, so it is
  the CGI that is gone, not a block. Replaced with
  `https://www.genome.jp/kegg-bin/show_organism?menu_type=pathway_maps&org=zma`,
  which serves the maize pathway maps (47 KB).
- **"Contact us!"**, a paragraph pointing at the feedback button that every page
  already carries in its header.
- **The MaizeCyc paper PubMed link** as a bare link. The paper is now a full
  reference card, from the same curated bibliography `/cite` reads.

## What was added

The page had no search and no metrics, because nobody had noticed the data was
already in the database. `mgdb.corncyc_gene_model_pathway` holds **23,957**
gene-model-to-pathway rows across two B73 assemblies — 549 distinct CornCyc
pathways, 14,041 gene models, 1,474 enzymes. That is MaizeGDB's own table, it
did not come from the hosted websites, and retiring them did not touch it.
