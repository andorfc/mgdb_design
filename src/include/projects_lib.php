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
    $project['slug']       = $slug;
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
