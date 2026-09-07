# Content model for /sitemap.
#
# One place to edit the directory. The generator emits readable, properly
# escaped Bauplan from this; the emitted .bau is what ships.
#
# kind: tools | curated | community | archive   (drives the filter chips)
#
# Revised after the 2026-08-28 group review. Every entry below was checked
# against the live server: pages that returned an empty body or a "you must
# provide a parameter" stub were dropped, and pages that turned out to be the
# same page under two URLs were merged. Notes on those calls are inline.

FEATURED = [
    ("Pangenome graph", "https://pangenome-viewer.maizegdb.org/",
     "Visualize structural and sequence variation across Zea genomes.", "odgi"),
    ("FETA", "https://feta.maizegdb.org",
     "Gene functional enrichment in Zea mays and related species.", "feta"),
    ("Fusarium Protein Toolkit", "https://fusarium.maizegdb.org",
     "Functions and structures of the Fusarium proteome.", "fpt"),
    ("Phylostrata", "https://phylostrata.maizegdb.org",
     "Evolutionary conservation level of a given protein.", "phylo"),
    ("SNPversity 2.0", "https://wgs.maizegdb.org/",
     "Build a VCF for a region of B73 across diverse accessions.", "snpv"),
    ("PanEffect", "/effect/maize_v2/",
     "Variant effects across the maize pan-genome.", "paneffect"),
    ("SNPTools", "https://snptools.maizegdb.org",
     "Variant browsing with per-gene protein structure views.", "snptools"),
    ("Protein structures", "/data_center/protein_structure",
     "Predicted structures for gene model proteins, with Foldseek and FATCAT.", "structures"),
]

# Sticky tab bar. Labels are deliberately shorter than the section headings --
# thirteen full headings would wrap to three rows and eat the viewport, since
# the bar is sticky. gen_sitemap.py asserts every section has one.
TAB_LABELS = {
    "start": "Start here",
    "tools": "Research tools",
    "ai": "AI and ML",
    "genomes": "Genomic data",
    "data_center": "Data hubs",
    "community": "Community",
    "literature": "Publications",
    "docs": "Documentation",
    "download": "Downloads",
    "archive": "Archives",
    "about": "About",
}

# No section carries a blurb any more. The field is still honoured by the
# generator -- a non-empty string emits a <p class="sitemap-section-blurb"> --
# so one can come back without touching gen_sitemap.py or the CSS.
SECTIONS = [
    ("start", "community", "Start here", "", [
        ("Search everything", "/search_engine/searchall", "One search across genes, stocks, phenotypes, references, and more."),
        ("Data hubs", "/data_center", "Every curated collection in one directory."),
        ("Genome Center", "/genome", "Assemblies hosted at MaizeGDB, with browsers and downloads."),
        ("Gene Center", "/gene_center/gene", "Look up a gene model, symbol, or locus."),
        ("BLAST", "/BLAST", "Sequence search against every genome dataset hosted here."),
        ("Downloads", "https://download.maizegdb.org", "Bulk files: assemblies, annotations, insertions, expression."),
    ]),

    # Dropped per review: the three Bin Viewer parameter stubs,
    # Compare three maps, Complete map view, Mapped elements, Mapped SSRs,
    # Single tissue comparison, Whole genome views (listed under Genomic data).
    # Moved to Archives: Incongruency, Locus lookup, Locus pair lookup, Locus
    # summary table, both MapMan entries, SSR reports.
    # Also dropped: Newly characterized genes -- /new_genes serves an empty
    # body on both dev and www.
    # Compare maps is back, 2026-09-07: it was dropped because the bare URL
    # answered "You must provide two map ids", and the modernized page carries
    # a map picker instead. Compare three maps stays out -- it is a 301 to
    # /compare_maps now.
    ("tools", "tools", "All research tools", "", [
        ("BLAST", "/BLAST", "Sequence search against the full set of genome datasets hosted at MaizeGDB."),
        ("Bin Viewer", "/bin_viewer", "Explore data in regions defined by genetic bin boundaries."),
        ("Compare maps", "/compare_maps", "Two or three genetic maps of one chromosome, and the loci placed on all of them."),
        ("FATCAT comparison tool", "/fatcat", "Structural alignments between a maize protein and its top structural hits."),
        ("FETA", "https://feta.maizegdb.org", "A suite of tools for exploring gene functional enrichment in Zea mays and relatives."),
        ("Foldseek search", "/foldseek", "Search a maize protein structure by shape, and find relatives that sequence search misses."),
        ("Fusarium Protein Toolkit", "https://fusarium.maizegdb.org", "Tools and datasets for the functions and structures of the Fusarium proteome."),
        ("GBrowse genome browsers", "/gbrowse", "Older browser hosting B73 v1-v4, NRGene W22, and other historic assemblies."),
        ("Genome Context Viewer", "https://gcv.maizegdb.org", "Explore gene families in genomic context across multiple assemblies."),
        ("GenomeQC", "https://genomeqc.maizegdb.org/", "Assess genome assembly and gene model annotation quality."),
        ("JBrowse genome browsers", "https://jbrowse.maizegdb.org", "The GBrowse replacement, hosting B73 v5 and most assemblies here."),
        ("JBrowse 2 tutorial", "/genome/jbrowse2_tutorial", "How to drive the JBrowse 2 interface."),
        ("Maize Feature Store", "https://mfs.maizegdb.org/", "Central repository of raw and transformed data, suited to machine learning."),
        ("Metabolic Pathways", "/metabolic_pathways", "Maize pathway assignments, and the maintained databases that curate them. Replaces the retired CornCyc instances."),
        ("PanEffect", "/effect/maize_v2/", "Explore variant effects across the maize pan-genome."),
        ("Pangenome graph", "https://pangenome-viewer.maizegdb.org/", "Structural and sequence variation across Zea genomes."),
        ("Pan-genome pathway explorer", "/projects/pathway_explorer", "E2P2 metabolic pathway annotation across the 26 NAM founder genomes, with gap analysis and gene-list enrichment."),
        ("Pathway Association Study Tool (PAST)", "/past", "Assigns SNPs to genes and genes to metabolic pathways."),
        ("PedigreeNet", "/breeders_toolbox", "Pedigree relationships between maize varieties, drawn as a network."),
        ("Phylostrata", "https://phylostrata.maizegdb.org", "Phylostratigraphy: evolutionary conservation level of a protein."),
        ("Protein structures", "/data_center/protein_structure", "Predicted structures for gene model proteins, with Foldseek and FATCAT."),
        ("qTeller", "https://qteller.maizegdb.org", "Comparative RNA-seq expression across multiple data sources."),
        ("reelGene", "https://reelgene.maizegdb.org/", "Look up a gene model for its reelGene functionality score, conservation level, and pan-gene class."),
        ("SNPTools", "https://snptools.maizegdb.org", "Variant browsing and per-gene structure views."),
        ("SNPversity 2.0", "https://wgs.maizegdb.org/", "Build a VCF for a region of B73 across a subset of diverse accessions."),
        ("Trait values for IBM and NAM", "/traits_ibm_nam", "Measured trait values, searchable by stock, trait, reference, or environment."),
        ("TYPSimSelector", "/TYPSimSelector", "Rank USDA Ames inbred lines by genetic similarity to a reference accession."),
    ]),

    ("ai", "tools", "AI and machine learning", "", [
        ("AI and Machine Learning at MaizeGDB", "/ai", "Overview of the AI-derived datasets and tools hosted here."),
        ("PanEffect variant effects", "/effect/maize_v2/", "Language-model variant effect predictions across the pan-genome."),
        ("Protein structure data hub", "/data_center/protein_structure", "AlphaFold and ESMFold predicted structures with search and comparison."),
        ("InterPro domain atlas", "/projects/interpro_domain_atlas", "Domain composition across the proteome."),
        ("Maize Feature Store", "https://mfs.maizegdb.org/", "ML-ready feature tables assembled from many sources."),
        ("FAIR data and AI readiness", "/FAIRpractices", "How MaizeGDB approaches FAIR and machine-readable data."),
    ]),

    # Descriptions here were rewritten from the pages themselves rather than
    # from the link text. /14InbredsFISH and /B73Mo17FISH now serve the same
    # page with two tabs, so they are one entry.
    ("genomes", "curated", "Genomic data", "", [
        ("Genome Center", "/genome", "Search and filter every assembly hosted here by cultivar, species, accession, or status."),
        ("Genome browsers", "/genomebrowser", "Which browser serves which assembly, plus prepared synteny and structural-variant sessions."),
        ("JBrowse 2", "https://jbrowse.maizegdb.org", "Current browser: B73 v5 and most assemblies here, with multi-assembly synteny views."),
        ("GBrowse", "/gbrowse", "Older browser, still serving B73 v1-v4 and other retired assemblies."),
        ("B73 assembly center", "/assembly", "The B73 reference across v1 to v5: sequencing technology, gene model sets, chromosome accessions, and downloads."),
        ("B73 assembly history", "/historic", "What changed between B73 releases, including gene model migrations and coordinate transitions from RefGen_v1 to NAM-5.0."),
        ("B73 sequencing project", "/sequencing_project", "Historic record of the BAC-by-BAC project that produced the first B73 reference."),
        ("Reference assembly information", "/assembly_manifesto", "How MaizeGDB chooses the representative maize assembly, and what a group planning a new assembly should know."),
        ("Whole genome views", "/genome/whole_genome", "Chromosome ideograms, gene density, and repeat distributions for B73 v5 and the 25 NAM founders."),
        ("NAM founder genomes", "/NAM_project", "De novo assemblies, annotations, GRIN accessions, and stock records for the 26 NAM founder inbreds."),
        ("Pan-Andropogoneae genomes", "/PanAnd_project", "Chromosome-scale assemblies across 36 wild Zea, Tripsacum, and Andropogoneae grasses."),
        ("European flint genomes", "/european_flints", "Assemblies and structural variation for four European flint inbreds: DK105, EP1, F7, and PE0075."),
        ("CAAS founder inbred genomes", "/CAAS_FIL_project", "Assemblies for the 12 Chinese and international founder lines behind modern hybrid breeding."),
        # Both of these were missing while their four siblings were listed,
        # found while adding the resource project pages on 2026-09-05.
        ("HiLo elevation genomes", "/HiLo_project", "Assemblies and gene models for Mexican landraces and CIMMYT inbreds sampled from 50 to 2,520 metres."),
        ("AMAIZING genomes", "/amaizing_project", "Assemblies, watered and drought transcriptomes, and panEDTA annotation for seven European breeding lines."),
        ("FISH karyotypes", "/14InbredsFISH", "Multicolor chromosome painting across 14 inbred lines, and high-resolution B73 versus Mo17 somatic karyotypes."),
        # Cross-listed: it is also in Documentation and help with the other two
        # resource project pages. Duplicates across sections are legitimate --
        # this is where someone hunting cytogenetics will look.
        ("Cytogenetic Map of Maize project", "/projects/cytogenetic_map", "Probe nomenclature and FISH methods for placing genetically mapped markers on pachytene chromosomes."),
    ]),

    ("data_center", "curated", "Data hubs", "", []),

    # "Curated data types" was dropped: with descriptions gone it duplicated
    # the Data hubs list entry for entry.

    ("community", "community", "Community and people", "", [
        ("Research projects", "/projects", "Every maize research project with data, results, or documentation here."),
        ("Find researchers", "/person", "Search the MaizeGDB people directory."),
        ("Maize Genetics Conference", "/maize_meeting", "Past, present, and future annual meetings."),
        ("Maize Genetics Cooperation", "https://www.maizegenetics.org", "The organization coordinating maize research cooperation."),
        ("MGEC", "/mgec", "The Maize Genetics Executive Committee record, 2000-2019: origins, procedures, committees, activities, and documents."),
        ("Steering committee", "/working_group#wg-steering", "The ten-person committee that guided the MaizeDB to MaizeGDB transition, recorded on the Working Group page."),
        # Documentation rather than a live body, and the description says so:
        # the group is not currently active and the page is its record. Kept out
        # of the megamenu for the same reason (Carson, 2026-09-05).
        ("MaizeGDB Working Group", "/working_group", "Documentation on the MaizeGDB Working Group, which is not currently active: membership, past members, the transition Steering Committee that preceded it, and the status reports and guidance exchanged with the MaizeGDB team, 2006-2018."),
        ("Awards", "https://www.maizegenetics.org/awards/community-awards", "Maize Genetics Cooperation community awards."),
        ("McClintock Prize", "https://www.maizegenetics.org/awards/mcclintock-prize", "The McClintock Prize for Plant Genetics and Genome Studies, awarded by the Maize Genetics Cooperation."),
        ("Jobs", "/jobs", "Positions shared with MaizeGDB."),
        ("Order stocks from the Stock Center", "/ordering/coop_order", "Request seed from the Maize Genetics Cooperation Stock Center."),
    ]),

    ("literature", "community", "Publications, news, and media", "", [
        ("Reference search", "/data_center/reference", "Search the curated maize literature."),
        ("Maize Newsletter (MNL)", "/mnl", "Every issue since 1929."),
        ("Editorial board picks", "/hot_new_papers", "A recommended paper each month."),
        ("Classic papers", "/maize_history#history-classic-reads", "Influential papers in maize genetics."),
        ("Videos", "/community/videos", "Recorded talks on the history of maize genetics, and six demonstrations of controlled pollination."),
        ("Controlled pollination of maize", "/controlled_pollination", "How to make controlled crosses, with video demonstrations."),
        ("NCGA podcasts", "/podcast", "National Corn Growers Association podcasts, 2012-2013."),
        ("Maize history and timelines", "/maize_history", "A century of maize genetics, and the history of MaizeGDB."),
        ("A history of maize", "https://rilab.ucdavis.edu/maize_history.html", "Domestication summary from the Ross-Ibarra lab."),
        ("News archive", "/whatsnew", "MaizeGDB news, by year, back to 2007."),
    ]),

    ("docs", "community", "Documentation and help", "", [
        # "Project documentation" pointed at /doc, retired 2026-09-05. Its
        # subject moved to "Research projects" under Community and people;
        # what is left here is documentation of the site itself, which is what
        # the section name has always promised.
        # Renamed in the emitted .bau on 2026-09-04 when /handyref itself became
        # "Genetic maps at MaizeGDB"; carried back into the model here so a
        # regeneration stops reverting it.
        ("Genetic maps", "/handyref", "The handy reference to the genetic maps held here: the composite map, the IBM panel, the Neighbors maps and NAM."),
        ("Nomenclature", "/nomenclature", "Maize genetics nomenclature rules."),
        ("Nomenclature service", "https://nomenclature.maizegdb.org", "Check or request standard names for genes, alleles, and stocks."),
        ("Assembly and annotation nomenclature", "/nomenclature_summary", "Naming for assemblies and annotations."),
        ("Contribute data to MaizeGDB", "/contribute_data", "What we accept, in what formats, and how to send it."),
        ("Contribute genomic data", "/contribute_data#genomic", "Specific guidance for assemblies and annotations."),
        ("Submit an assembly to GenBank", "https://download.maizegdb.org/Tutorials/GenBank_protocols/", "Protocols for depositing a maize assembly."),
        ("Submit an assembly to ENA", "https://ena-docs.readthedocs.io/en/latest/submit/assembly.html", "European Nucleotide Archive submission documentation."),
        ("SSR protocols", "/ssr_protocols", "Laboratory protocols for SSR markers."),
        # The three community resource project pages, ported onto the shell on
        # 2026-09-05. They sit beside SSR protocols and Coordinate definitions
        # because that is what they are: the methods and naming references for
        # major maize projects, which is the material /doc used to gather.
        ("Cytogenetic Map of Maize project", "/projects/cytogenetic_map", "Probe nomenclature and FISH methods for the sorghum-BAC probes used to map maize markers cytogenetically."),
        ("Dooner and Du Ds-GFP insertions", "/projects/dooner_du_acds", "The sequence-indexed Ds-GFP collection: how it was made, where to search it, and what to expect from the seed."),
        ("Ds-GFP insertion verification", "/projects/fowler_insertion_validation", "PCR verification, male transmission rates and genotyping primers for 83 Ds-GFP lines."),
        ("Coordinate definitions", "/coordinateDef", "How map coordinates are defined here."),
        ("FAIR practices", "/FAIRpractices", "FAIR and AI-readiness at MaizeGDB."),
        ("Redesign status", "/redesign_status", "Which pages have moved to the current design."),
        # /doc was this file's only route. It is real, current documentation of
        # the back end, so it moves here rather than going with the page.
        ("Database schema", "/docs/MaizeGDBSchema.pdf", "The schema of the database behind MaizeGDB, as a PDF."),
    ]),

    ("download", "curated", "Downloads", "", [
        ("Download server", "https://download.maizegdb.org", "Assemblies, annotations, insertions, expression, and more."),
        ("Downloads page", "/download", "Guided index of what is downloadable, including Globus transfer."),
        ("Maize Feature Store", "https://mfs.maizegdb.org/", "Assembled feature tables for modeling."),
    ]),

    # Dropped per review: FPC physical maps and Gel patterns (data exists, no
    # hub to point at), EMS phenotypes and RescueMu phenotypes (both serve an
    # empty body). Stock catalog moved to Data hubs.
    ("archive", "archive", "Archives and older tools", "", [
        ("Archived data hubs", "/archive", "Data hubs retired from the main set."),
        ("BACs", "/data_center/bac", "BAC clone records."),
        ("ESTs", "/data_center/est", "Expressed sequence tags."),
        ("Overgos", "/data_center/overgo", "Overgo probe records."),
        ("SSR markers", "/data_center/ssr", "SSR marker archive."),
        ("QTL", "/data_center/qtl", "Quantitative trait loci, from the older mapping literature."),
        ("Recombination maps", "/data_center/RNmaps", "Recombination nodule maps."),
        ("MapMan gene atlas files", "https://download.maizegdb.org/Archive/MapMan_GeneAtlas/", "MapMan-formatted expression files from the Sekhon et al. 2011 maize development atlas, single tissue and median."),
        ("SSR reports", "/ssrreports", "Every archived SSR record carrying a repeat motif, and the SSRs derived from mapped genes."),
        ("IBM map scores", "/mapscore_ibmlist", "Scored IBM mapping data."),
        ("IBM 302 list", "/mapscore_ibm302list", "IBM 302 line list."),
    ]),

    # No descriptions in this section, per review. There is still no general
    # about page: /about was an empty shell answering 200 and now 301s to this
    # very section, /sitemap#sm-about (2026-09-05), so it is not listed here.
    ("about", "community", "About MaizeGDB", "", [
        ("Cite us", "/cite", ""),
        ("Contact us", "/contact", ""),
        ("Send feedback", "/feedback", ""),
        ("AgBioData member", "https://www.agbiodata.org", ""),
    ]),

    # "Your account" was removed on request. /login, /create_account,
    # /forgot_password, /preferences and /update_person are no longer listed
    # anywhere on this page; the header account controls are the way in.
]

# Data hubs. The per-hub task chips were removed in review, so these are plain
# (name, url, description) like every other section.
DATA_CENTERS = [
    ("All data hubs", "/data_center", "Every data hub in one directory."),
    ("Markers and probes", "/data_center/marker",
     "Molecular markers, probes, SSRs, RFLPs, and sequence features."),
    ("Stocks", "/data_center/stock", "Stocks tracked by MaizeGDB."),
    ("Stock catalog", "/stock_catalog",
     "Catalog of new additions at the Maize Genetics Cooperation Stock Center."),
    ("References", "/data_center/reference",
     "The curated maize literature, searchable by topic, author, gene, or locus."),
    ("Alleles and polymorphisms", "/data_center/variation",
     "Alleles, polymorphisms, and the mutant collections behind them."),
    ("Maps", "/data_center/map",
     "Genetic, cytogenetic, physical, and bin maps across chromosomes 1-10."),
    ("Linkage groups", "/data_center/lg",
     "What a locus is placed on: the chromosomes, and the plasmids, phage, BACs, and organellar genomes used in maize genetics."),
    ("Phenotypes", "/data_center/phenotype",
     "Phenotype records and the mutant archives they come from."),
    ("Images", "/data_center/image",
     "Curated photographs of phenotypes, materials, and gel patterns."),
    ("Protein structures", "/data_center/protein_structure",
     "Predicted structures for gene model proteins."),
    ("Insertions", "/insertion",
     "Mu, Ac/Ds, and other sequence-indexed transposon insertion collections."),
    ("Pan-genes", "/pan_gene_center/pan_gene",
     "Pan-gene assignments across the NAM assemblies."),
    ("Genome Center", "/genome", "Assemblies hosted at MaizeGDB."),
    ("Gene Center", "/gene_center/gene",
     "Gene records with models, symbols, and supporting evidence."),
    ("Loci", "/data_center/locus", "Classic genetic loci, cloned genes, and their alleles."),
    ("QTL", "/data_center/qtl", "Quantitative trait analyses and the crosses behind them."),
    ("Expression", "/data_center/expression", "Expression datasets."),
    ("Gene products", "/data_center/gene_product", "Products assigned to gene models."),
    ("Metabolic pathways", "/data_center/metabolic_pathway", "Pathway records."),
    ("Cytogenetics", "/data_center/cytogenetic", "Cytogenetic stocks and maps."),
]
