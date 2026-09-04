<?php
/* file: projects_lib.php
 *
 * purpose: the registry behind /projects — MaizeGDB analysis projects.
 *
 * An analysis project is a finished, self-contained study: a fixed set of
 * results with its figures, tables, methods and downloads. It is deliberately
 * not a data centre (those search the production database, one page per record
 * type) and not a tool (those are interactive applications). Nothing here
 * touches the database.
 *
 * This file is the single source of truth for the section. The listing page,
 * the routing, the page identity, the breadcrumb, and the filter chips are all
 * derived from it, so a project cannot appear in one and be missing from
 * another.
 *
 * Adding a project is three things:
 *   1. an entry in mgdb_projects()
 *   2. controllers/projects/<slug>.php        the page controller
 *   3. templates/static/mgdb_project_<slug>.bau   the page body
 * plus whatever data files the project ships under
 * /data/projects/<slug>/.
 *
 * A project whose page already lives elsewhere on the site needs only the
 * registry entry, with an explicit 'url'. It then appears on the listing in the
 * same format as the rest and links to where it actually is; /projects/<slug>
 * redirects there rather than 404ing.
 *
 * Slug rules. The slug is the URL segment, so it must be lowercase
 * [a-z0-9_] only. It must also not contain the two characters "js": the
 * sitewide .htaccess skips its rewrite for any URI matching the unanchored
 * pattern (.js), so /projects/<slug> would never reach controller.php.
 */

/*
 * Every project, newest first. The order here is the order on the listing page.
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

    );
}

/*
 * The topic vocabulary. Only topics used by at least one project become filter
 * chips, and the chip label comes from here rather than from the slug.
 */
function mgdb_project_topics() {
    return array(
        'comparative-genomics' => 'Comparative genomics',
        'protein-function'     => 'Protein function',
        'immunity'             => 'Immunity and defense',
        'expression'           => 'Expression',
        'variation'            => 'Variation',
        'phenotype'            => 'Phenotype',
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
        return $project;
    }

    $project['hosted_elsewhere'] = false;
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
