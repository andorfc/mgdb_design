<?php
/* file: projects_lib.php
 *
 * purpose: the registry behind /projects — every maize research project whose
 *          data, results or documentation live at MaizeGDB.
 *
 * history:
 *  2026-08-15  Created as the registry for MaizeGDB analysis projects only.
 *  2026-09-05  Widened to the whole projects directory (Carson: "one /projects
 *              directory"). /doc and /documentation/projects were two more
 *              listings of the same idea, both stale, and between them they
 *              pointed at two dead hosts and a PHP fatal error. Both now
 *              redirect here, and the projects they listed that still have a
 *              working page are entries below.
 *
 * Three kinds of project, which is what 'category' names:
 *
 *   analysis   A finished, self-contained study run at MaizeGDB: a fixed set
 *              of results with its figures, tables, methods and downloads.
 *              Deliberately not a data hub (those search the production
 *              database) and not a tool (those compute an answer from input).
 *   genome     A sequencing and assembly effort whose genomes the site serves.
 *   resource   A long-running community resource — a mutant collection, a map,
 *              a protocol set — hosted or documented here.
 *
 * 'maizegdb' => true marks a project MaizeGDB itself runs. Those cards carry
 * the kernels from the site mark in the upper right. It is an explicit flag
 * rather than a test on 'lead' so a project co-led with someone else can still
 * carry it.
 *
 * This file is the single source of truth for the section. The listing page,
 * the routing, the page identity, the breadcrumb, and the filter chips are all
 * derived from it, so a project cannot appear in one and be missing from
 * another.
 *
 * Adding a project hosted here is three things:
 *   1. an entry in mgdb_projects()
 *   2. controllers/projects/<slug>.php        the page controller
 *   3. templates/static/mgdb_project_<slug>.bau   the page body
 * plus whatever data files the project ships under
 * /data/projects/<slug>/.
 *
 * A project whose page already lives elsewhere needs only the registry entry,
 * with an explicit 'url'. It then appears on the listing in the same format as
 * the rest and links to where it actually is; /projects/<slug> redirects there
 * rather than 404ing. A 'url' beginning http is offsite, and the card says so.
 *
 * Optional fields, all of which the card omits rather than faking when absent:
 *   'lead'        who runs it. Only set where a source page states it — an
 *                 invented consortium name is the failure this section has
 *                 already had once, on a citation.
 *   'updated'     when the result last changed. Only meaningful for work
 *                 published here; a project hosted elsewhere has no date we
 *                 can stand behind, and a card that prints today's date claims
 *                 the data just changed.
 *   'card_facts'  up to three value/label pairs. Every number must come from
 *                 the project's own page or from a query, never from memory.
 *
 * Slug rules. The slug is the URL segment, so it must be lowercase
 * [a-z0-9_] only. It must also not contain the two characters "js": the
 * sitewide .htaccess skips its rewrite for any URI matching the unanchored
 * pattern (.js), so /projects/<slug> would never reach controller.php.
 */

/*
 * Every project. The order here is the order on the listing page: MaizeGDB's
 * own analyses first, then the genome projects, then the community resources,
 * and newest first within each.
 */
function mgdb_projects() {
    return array(

        /* Hosted at /data_center/alphafill rather than under /projects/, because
           it is a searchable page over its own corpus as well as a finished
           analysis. It is listed here because it is the same kind of work as
           the projects around it -- one pipeline, one fixed result set, with
           its figures, its methods and its downloads on the page. An explicit
           `url` is what marks an entry as living elsewhere; see mgdb_project(). */
        'alphafill' => array(
            'title'       => 'Predicted ligands, cofactors and metal sites in maize proteins',
            'short_title' => 'AlphaFill ligand transplants',
            'card_summary' => 'AlphaFill run over the maize AlphaFold models: cofactors, substrates and metal ions transplanted from homologous experimental structures onto predicted maize proteins, each with its donor PDB entry, its sequence identity and its local fit, so a predicted binding site can be judged rather than taken on trust.',
            'description' => 'AlphaFill 2.3.0 transplants against PDB-REDO, giving 624,456 ligand placements across 16,933 B73 RefGen_v5 genes, with donor evidence and confidence for each and the wholly unfilled pockets listed separately.',
            'category'    => 'analysis',
            'maizegdb'    => true,
            'topics'      => array('protein-function', 'methods'),
            'status'      => 'current',
            'published'   => '2026-08-29',
            'updated'     => '2026-08-29',
            'lead'        => 'MaizeGDB',
            'url'         => '/data_center/alphafill',
            'card_facts'  => array(
                array('624K',   'transplants'),
                array('16,933', 'genes'),
                array('1,969',  'ligand types'),
            ),
            'has_downloads' => true,
        ),

        'pathway_explorer' => array(
            'title'       => 'Metabolic pathways across the 26 NAM founder genomes',
            'short_title' => 'Pan-genome pathway explorer',
            'eyebrow'     => 'Comparative genomics',
            'card_summary' => 'E2P2 metabolic pathway annotation run identically on all 26 NAM founder genomes, compared against CornCyc 8.0: which pathways every genome carries, which reaction steps no genome fills, and which gene models sit on each step. Searchable, with a gene-list enrichment test.',
            'description' => 'E2P2 pathway annotation for the 26 NAM founder genomes beside CornCyc 8.0, with 694 pathways, 2,696 reaction steps and 259,709 gene-to-step assignments; browse, compare genomes, list reaction gaps, look up genes, and test a gene list for pathway enrichment.',
            'category'    => 'analysis',
            'maizegdb'    => true,
            'topics'      => array('comparative-genomics', 'protein-function', 'methods'),
            'status'      => 'current',
            'published'   => '2026-09-02',
            'updated'     => '2026-09-02',
            'lead'        => 'MaizeGDB',
            'card_facts'  => array(
                array('27',  'annotation tracks'),
                array('694', 'pathways'),
                /* Gene-to-step assignments, which is the unit the page states.
                   The pipeline's own 475,716 counts protein-model rows, and a
                   card that says 476K sends the reader to a page whose Methods
                   section exists to keep the two apart. */
                array('260K', 'gene assignments'),
            ),
            'has_downloads' => true,
        ),

        'interpro_domain_atlas' => array(
            'title'       => 'Protein domain landscape across maize, its relatives, and outgroups',
            'short_title' => 'Protein domain atlas',
            'eyebrow'     => 'Comparative genomics',
            'card_summary' => 'One uniform InterProScan re-scan of 46 Andropogoneae genomes and 6 outgroup species, turned into a browsable atlas of protein-domain copy number: which functional classes vary across genomes, which are stable, and where the variation is annotation method rather than biology.',
            'description' => 'A uniform InterProScan 5.78 re-scan of 46 Andropogoneae genomes and 6 outgroup species, with a 36-class functional ontology and an exclusive immunity classifier, so that cross-genome domain copy-number comparison is defensible.',
            'category'    => 'analysis',
            'maizegdb'    => true,
            'topics'      => array('comparative-genomics', 'protein-function', 'immunity'),
            'status'      => 'current',
            'published'   => '2026-08-15',
            'updated'     => '2026-08-15',
            'lead'        => 'MaizeGDB',
            /* Shown on the card so the listing says what the project is made
               of before the reader commits to opening it. */
            'card_facts'  => array(
                array('52',  'genomes scanned'),
                array('36',  'functional classes'),
                array('4.6M', 'genes annotated'),
            ),
            'has_downloads' => true,
        ),

        /* ── Genome sequencing and assembly projects ────────────────────────
           Every one of these has a modern page of its own at a root-level URL,
           so each is an entry with an explicit 'url' and nothing else here.
           They carry no 'updated': the assemblies are the consortium's, not
           ours, and a card stamping the day it was served would claim the data
           had just changed. That exact bug was removed from
           mgdb-caas-fil-project.css once already. */

        'nam' => array(
            'title'       => 'Nested Association Mapping (NAM) parent genomes',
            'short_title' => 'NAM founder genomes',
            'card_summary' => 'De novo PacBio assemblies and gene model annotations for the 26 NAM founder inbreds, the reference set behind most maize pan-genome work, with the RIL populations and marker data that map traits onto them.',
            'description' => 'High-quality de novo reference assemblies and annotations for 26 diverse founder inbreds representing a broad cross-section of modern maize genetic variation.',
            'category'    => 'genome',
            'topics'      => array('genome-assembly', 'comparative-genomics', 'variation'),
            'status'      => 'current',
            'url'         => '/NAM_project',
            'card_facts'  => array(
                array('26',      'founder genomes'),
                array('~5,000',  'mapping RILs'),
                array('680K+',   'SNP markers'),
            ),
            'has_downloads' => true,
        ),

        'panand' => array(
            'title'       => 'Pan-Andropogoneae sequencing and assembly project',
            'short_title' => 'Pan-Andropogoneae genomes',
            'card_summary' => 'De novo assemblies and annotations across the Andropogoneae — wild grasses, teosintes and outgroups — for studying the variation and evolution behind adaptive convergence and constraint in grass genomes.',
            'description' => 'De novo genome assemblies and annotations across 36+ wild grasses, teosintes, and outgroups spanning the Andropogoneae.',
            'category'    => 'genome',
            'topics'      => array('genome-assembly', 'comparative-genomics'),
            'status'      => 'current',
            'url'         => '/PanAnd_project',
            'card_facts'  => array(
                array('36+',   'genomes'),
                array('57',    'species'),
                array('700+',  'WGS surveys'),
            ),
            'has_downloads' => true,
        ),

        'caas_fil' => array(
            'title'       => 'CAAS 12 founder inbred lines genomes project (FIL)',
            'short_title' => 'CAAS founder inbred genomes',
            'card_summary' => 'Chromosome-scale assemblies and annotations for twelve Chinese and international founder inbreds, with the heterotic group architecture that organizes modern hybrid breeding.',
            'description' => 'De novo chromosome-scale assemblies, annotations, and heterotic group architecture across 12 foundational Chinese and international maize founder lines.',
            'category'    => 'genome',
            'topics'      => array('genome-assembly', 'variation'),
            'status'      => 'current',
            'url'         => '/CAAS_FIL_project',
            'card_facts'  => array(
                array('12',        'assemblies'),
                array('~2.26 Gb',  'genome size'),
                array('6',         'heterotic groups'),
            ),
            'has_downloads' => true,
        ),

        'amaizing' => array(
            'title'       => 'The AMAIZING European maize sequencing project',
            'short_title' => 'AMAIZING genomes',
            'card_summary' => 'Chromosome-scale assemblies, multi-tissue transcriptomes under watered and drought regimes, and panEDTA transposable element annotation for seven inbred lines foundational to European breeding.',
            'description' => 'De novo chromosome-scale assemblies, multi-tissue transcriptomics under two water regimes, and panEDTA transposable element annotations for seven European breeding lines.',
            'category'    => 'genome',
            'topics'      => array('genome-assembly', 'expression'),
            'status'      => 'current',
            'url'         => '/amaizing_project',
            'card_facts'  => array(
                array('7',     'assemblies'),
                array('15',    'RNA-seq tissues'),
                array('40K+',  'gene models'),
            ),
            'has_downloads' => true,
        ),

        'european_flints' => array(
            'title'       => 'European flint reference genomes and heterosis framework',
            'short_title' => 'European flint genomes',
            'card_summary' => 'Chromosome-scale assemblies, annotations and structural variation maps for four cornerstone European flint inbreds (DK105, EP1, F7, PE0075), aimed at cold tolerance and dent-by-flint heterosis.',
            'description' => 'De novo chromosome-scale assemblies, annotations, and structural variation maps for four cornerstone European flint inbred lines.',
            'category'    => 'genome',
            'topics'      => array('genome-assembly', 'variation'),
            'status'      => 'current',
            'url'         => '/european_flints',
            'card_facts'  => array(
                array('4',        'assemblies'),
                array('~2.3 Gb',  'genome size'),
                array('40K+',     'gene models'),
            ),
            'has_downloads' => true,
        ),

        'hilo' => array(
            'title'       => 'High and low elevation maize adaptation genomes (HiLo)',
            'short_title' => 'HiLo elevation genomes',
            'card_summary' => 'Chromosome-scale assemblies and gene models for traditional Mexican landraces and CIMMYT inbreds sampled from 50 to 2,520 metres above sea level, for work on altitudinal adaptation.',
            'description' => 'De novo chromosome-scale assemblies, gene models, and altitudinal adaptation genomics for traditional Mexican landraces and CIMMYT inbred lines.',
            'category'    => 'genome',
            'topics'      => array('genome-assembly', 'variation'),
            'status'      => 'current',
            'url'         => '/HiLo_project',
            'card_facts'  => array(
                array('7',        'assemblies'),
                array('2,470 m',  'elevation span'),
                array('40K+',     'gene models'),
            ),
            'has_downloads' => true,
        ),

        'b73_sequencing' => array(
            'title'       => 'The B73 genome sequencing project',
            'short_title' => 'B73 sequencing project',
            'card_summary' => 'The BAC-by-BAC effort that produced the first maize reference: the minimal tiling path it was built from, the B73 stock behind the BAC libraries, the input and output data, and the timeline of the work.',
            'description' => 'The historic record of the BAC-by-BAC sequencing project that produced the first B73 reference assembly, published in 2009.',
            'category'    => 'genome',
            'topics'      => array('genome-assembly', 'methods'),
            'status'      => 'historic',
            'lead'        => 'Maize Genome Sequencing Consortium',
            'url'         => '/sequencing_project',
            'card_facts'  => array(
                array('~19,000',  'tiling-path BACs'),
                array('302',      'IBM lines'),
                array('2009',     'first assembly'),
            ),
        ),

        /* ── Community resource projects ────────────────────────────────────
           The four that /doc and /documentation/projects were the only route
           to. Three of them were still in the legacy chrome when this section
           widened; they were ported onto the shell on 2026-09-05 and now have
           real pages under /projects/, with their old URLs redirecting. */

        'uniformmu' => array(
            'title'       => 'UniformMu transposon resource',
            'short_title' => 'UniformMu',
            'card_summary' => 'Sequence-indexed Mu transposon insertions in a uniform W22 background, each tied to a seed stock orderable from the Stock Center, with flanking sequences aligned to the current assemblies.',
            'description' => 'Sequence-indexed germinal Mu insertions in the W22 background, with the seed stocks that carry them and alignments to current maize assemblies.',
            'category'    => 'resource',
            'topics'      => array('mutagenesis', 'stocks'),
            'status'      => 'current',
            'lead'        => 'McCarty and Koch, University of Florida',
            'url'         => '/uniformmu',
            /* From data/uniformmu/uniformmu_summary.json, the same file the
               resource page reads, so the card and the page cannot disagree. */
            'card_facts'  => array(
                array('77,990',  'insertions'),
                array('10,047',  'seed stocks'),
                array('4',       'assemblies aligned'),
            ),
            'has_downloads' => true,
        ),

        'dooner_du_acds' => array(
            'title'       => 'Dooner and Du sequence-indexed Ds-GFP insertions',
            'short_title' => 'Dooner-Du Ds-GFP insertions',
            'card_summary' => 'A sequence-indexed collection of single transposed Ds-GFP insertions spread through the maize genome, with flanking sequence, genome position and tagged genes for each, and seed available from the Stock Center.',
            'description' => 'Sequence-indexed stocks of single transposed Ds elements tagged with GFP, generated under NSF award 1339238.',
            'category'    => 'resource',
            'topics'      => array('mutagenesis', 'stocks'),
            'status'      => 'current',
            'lead'        => 'Dooner and Du',
            'card_facts'  => array(
                array('14,184',  'in the collection'),
                array('7,510',   'searchable here'),
                array('13,145',  'seed stocks'),
            ),
        ),

        'fowler_insertion_validation' => array(
            'title'       => 'Maize Gametophyte Project validated Ds-GFP insertions',
            'short_title' => 'Fowler insertion verification',
            'card_summary' => 'PCR verification of Ds-GFP insertion lines drawn from the Dooner and Du collection, with per-line transmission rates and the primer sequences used, done as part of the Maize Gametophyte Project.',
            'description' => 'PCR verification and transmission testing of 83 putative Ds-GFP insertion lines from the Dooner and Du collection.',
            'category'    => 'resource',
            'topics'      => array('mutagenesis', 'methods'),
            'status'      => 'current',
            'lead'        => 'Maize Gametophyte Project',
            'card_facts'  => array(
                array('83',  'lines tested'),
                array('64',  'PCR verified'),
                array('10',  'transmission defects'),
            ),
            'has_downloads' => true,
        ),

        'cytogenetic_map' => array(
            'title'       => 'Cytogenetic Map of Maize project',
            'short_title' => 'Cytogenetic Map of Maize',
            'card_summary' => 'The nomenclature and methods behind the sorghum-BAC FISH probes that place genetically mapped maize markers on pachytene chromosomes: how a probe name is read, and how each probe was selected and detected.',
            'description' => 'Nomenclature and methods for the Cytogenetic Map of Maize project (NSF-DBI-0321639), which used sorghum BACs as FISH probes on pachytene spreads.',
            'category'    => 'resource',
            'topics'      => array('cytogenetics', 'methods'),
            'status'      => 'historic',
            'lead'        => 'Bass lab, Florida State University',
            'card_facts'  => array(
                array('7', 'chromosomes mapped'),
            ),
        ),

        'panzea' => array(
            'title'       => 'Maize Genetic Variation Project (Panzea)',
            'short_title' => 'Panzea',
            'card_summary' => 'The relationship between genotype and phenotype in maize, with a focus on rare genetic variation. MaizeGDB holds no page for this project; its data and tools are on the project\'s own site.',
            'description' => 'The Maize Genetic Variation Project, investigating the relationship between phenotype and genotype with a focus on rare genetic variations.',
            'category'    => 'resource',
            'topics'      => array('variation'),
            'status'      => 'current',
            'url'         => 'https://www.panzea.org/',
        ),

    );
}

/*
 * The category vocabulary, in listing order. This is the axis the filter chips
 * run on: three kinds of project, each answering a different question, and a
 * reader who wants "what has MaizeGDB itself produced" gets it in one click.
 *
 * 'label' is the chip, which names a set. 'label_one' opens the meta line on a
 * card, which names one project.
 *
 * A category with no projects in it emits no chip, so a chip can never be
 * offered that matches nothing.
 */
function mgdb_project_categories() {
    return array(
        'analysis' => array(
            'label'     => 'MaizeGDB analyses',
            'label_one' => 'MaizeGDB analysis',
            'blurb'     => 'Analyses run at MaizeGDB and published as one self-contained page.',
        ),
        'genome' => array(
            'label'     => 'Genome projects',
            'label_one' => 'Genome project',
            'blurb'     => 'Sequencing and assembly efforts whose genomes the site serves.',
        ),
        'resource' => array(
            'label'     => 'Community resources',
            'label_one' => 'Community resource',
            'blurb'     => 'Mutant collections, maps and protocol sets hosted or documented here.',
        ),
    );
}

/*
 * The topic vocabulary. Topics are the pills on a card and part of the text the
 * search box matches, so typing "immunity" finds a project whose card copy
 * never uses the word. They stopped being the chip axis on 2026-09-05, when
 * the section widened from three projects to fifteen and the useful first cut
 * became what kind of project it is rather than what it is about.
 */
function mgdb_project_topics() {
    return array(
        'genome-assembly'      => 'Genome assemblies',
        'comparative-genomics' => 'Comparative genomics',
        'protein-function'     => 'Protein function',
        'immunity'             => 'Immunity and defense',
        'expression'           => 'Expression',
        'variation'            => 'Variation',
        'phenotype'            => 'Phenotype',
        'mutagenesis'          => 'Insertions and mutagenesis',
        'stocks'               => 'Seed stocks',
        'cytogenetics'         => 'Cytogenetics',
        'methods'              => 'Methods and benchmarking',
    );
}

/*
 * One project by slug, with its slug and derived URLs folded in, or null.
 *
 * The slug is validated against the registry rather than sanitized, so a
 * request for /projects/../../etc can never reach a filesystem path.
 */
function mgdb_project($slug) {
    $projects = mgdb_projects();
    $slug = (string)$slug;
    if ($slug === '' || !isset($projects[$slug])) {
        return null;
    }

    $project = $projects[$slug];
    $project['slug'] = $slug;

    /* A registry entry may carry its own `url`, which means the page lives
       somewhere else on the site and this section only lists it. Such an entry
       has no controller and no data directory here, and /projects/<slug> must
       not pretend otherwise -- controllers/projects.php redirects it to the
       real page rather than including a controller that does not exist. */
    if (!empty($project['url'])) {
        $project['hosted_elsewhere'] = true;
        /* An offsite url is a different promise from an internal one: the card
           opens it in a new tab, names the host rather than the path, and the
           reader is told they are leaving before they click. */
        $project['external'] = (bool) preg_match('~^https?://~i', $project['url']);
        return $project;
    }

    $project['hosted_elsewhere'] = false;
    $project['external']   = false;
    $project['url']        = '/projects/' . $slug;
    $project['data_url']   = '/data/projects/' . $slug;
    $project['controller'] = 'controllers/projects/' . $slug . '.php';
    $project['template']   = '/templates/static/mgdb_project_' . $slug . '.bau';

    return $project;
}

/* Shared escaping helper. Every value rendered by this section goes through it. */
function mgdb_project_esc($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/*
 * A byte count as a short human-readable size.
 *
 * Sizes are read from disk at render time rather than recorded in the registry
 * so a re-run of a pipeline cannot leave the page quoting the old file size.
 */
function mgdb_project_filesize($absolute_path) {
    if (!is_file($absolute_path)) {
        return null;
    }
    $bytes = filesize($absolute_path);
    if ($bytes === false) {
        return null;
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0) . ' KB';
    }
    return $bytes . ' bytes';
}

/*
 * A cache-busting URL for a file the project ships.
 *
 * Data files are served straight off disk by Apache, so they are not covered by
 * Bauplan's asset versioning, which only rewrites the CSS and script tags it
 * emits.
 */
function mgdb_project_asset_url($project, $relative_path) {
    $url  = $project['data_url'] . '/' . ltrim($relative_path, '/');
    $file = $_SERVER['DOCUMENT_ROOT'] . $url;
    if (is_file($file)) {
        $mtime = @filemtime($file);
        if ($mtime) {
            return $url . '?v=' . $mtime;
        }
    }
    return $url;
}
?>
