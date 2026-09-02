<?php
/* file: include/gene_hub_lib.php
 *
 * purpose: the figures, option lists and chart series behind the Gene Data Hub
 *          landing page (/gene_center/gene).
 *
 * Everything here is collection-wide and identical for every reader, so the
 * controller runs it through dashboardCache(). Uncached, on the development
 * instance:
 *
 *   annotation set counts   10435 ms   (1.88M row aggregate)
 *   chromosome breakdown      323 ms
 *   transcript distribution   315 ms
 *   annotation dropdown       419 ms   getGeneModelSetswRecs()
 *   gene product dropdown      44 ms
 *   BLAST target dropdown       5 ms
 *   phenotype, trait             4 ms
 *   ------------------------------
 *   total                   ~11.5 s
 *
 * Cached, the page issues no query of its own at all. The figures change when
 * the database is reloaded, which is the event dashboard_cache_stamp exists to
 * signal.
 */

/* Assembly annotations, with the count of gene models in each.

   The count is of distinct gene_name, not of rows: the view carries a row per
   gene model per current annotation and a handful of gene models appear twice
   inside one annotation. Sorted largest first, which is the order the chart
   reads in. */
function geneHubAnnotationCounts($DBConn) {
    $sql = "
        SELECT version, line,
               count(DISTINCT gene_name) AS gene_models
        FROM chado.gene_model
        WHERE analysis_is_current = 'yes'
          AND version IS NOT NULL AND version <> ''
        GROUP BY version, line
        ORDER BY gene_models DESC, version";

    $rows = get_all_rows(make_query($DBConn, $sql));

    $out = array();
    foreach ($rows as $row) {
        $out[] = array(
            'annotation'  => trim((string) $row['version']),
            'line'        => trim((string) $row['line']),
            'gene_models' => (int) $row['gene_models']
        );
    }
    return $out;
}

/* Gene models of the reference annotation by chromosome and model type.

   Scaffolds are pooled into one bar. There are 180 of them in Zm00001eb.1,
   carrying 8 to 18 gene models each; charted separately they would be 180 bars
   too short to read. */
function geneHubChromosomeCounts($DBConn, $annotation) {
    $sql = "
        SELECT chr, model_type, count(DISTINCT gene_name) AS gene_models
        FROM chado.gene_model
        WHERE version = ?
          AND chr IS NOT NULL AND chr <> ''
        GROUP BY chr, model_type";

    $rows = get_all_rows(make_query($DBConn, $sql, 1, array($annotation)));

    $bins  = array();
    $types = array();

    foreach ($rows as $row) {
        $chr  = strtolower(trim((string) $row['chr']));
        $type = trim((string) $row['model_type']);
        if ($type === '') { $type = 'unclassified'; }

        if (preg_match('/^chr(\d+)$/', $chr, $m)) {
            $label = 'Chr ' . (int) $m[1];
            $sort  = (int) $m[1];
        } else if ($chr === 'chrmt' || strpos($chr, 'mito') !== false) {
            $label = 'Mitochondrion';
            $sort  = 90;
        } else if ($chr === 'chrpt' || strpos($chr, 'chloro') !== false || strpos($chr, 'plastid') !== false) {
            $label = 'Plastid';
            $sort  = 91;
        } else {
            $label = 'Unplaced scaffolds';
            $sort  = 99;
        }

        if (!isset($bins[$label])) {
            $bins[$label] = array('label' => $label, 'sort' => $sort, 'types' => array());
        }
        if (!isset($bins[$label]['types'][$type])) {
            $bins[$label]['types'][$type] = 0;
        }
        $bins[$label]['types'][$type] += (int) $row['gene_models'];
        $types[$type] = true;
    }

    uasort($bins, function ($a, $b) { return $a['sort'] - $b['sort']; });

    $types = array_keys($types);
    sort($types);

    return array('bins' => array_values($bins), 'types' => $types);
}

/* Transcripts per gene model in the reference annotation.

   transcript_count is null for some gene models, so the coverage is returned
   with the series and stated on the figure rather than left to be inferred
   from a total that does not add up. */
function geneHubTranscriptCounts($DBConn, $annotation, $cap = 10) {
    $sql = "
        SELECT LEAST(transcript_count, ?) AS bucket,
               count(DISTINCT gene_name)  AS gene_models
        FROM chado.gene_model
        WHERE version = ? AND transcript_count IS NOT NULL AND transcript_count > 0
        GROUP BY 1
        ORDER BY 1";

    $rows = get_all_rows(make_query($DBConn, $sql, 1, array($cap, $annotation)));

    $series  = array();
    $counted = 0;
    foreach ($rows as $row) {
        $n = (int) $row['bucket'];
        $c = (int) $row['gene_models'];
        $series[] = array('transcripts' => $n, 'gene_models' => $c, 'capped' => ($n >= $cap));
        $counted += $c;
    }

    /* What the gene models with no transcript count are. On Zm00001eb.1 that
       is every non_coding model and nothing else, which is a better thing to
       tell a reader than the bare number missing. */
    $breakdown = get_all_rows(make_query($DBConn,
        "SELECT model_type,
                count(DISTINCT gene_name) FILTER (WHERE transcript_count IS NULL OR transcript_count = 0) AS no_count,
                count(DISTINCT gene_name) AS total
         FROM chado.gene_model WHERE version = ?
         GROUP BY model_type",
        1, array($annotation)));

    $totalModels = 0;
    $missingTypes = array();
    foreach ($breakdown as $row) {
        $totalModels += (int) $row['total'];
        if ((int) $row['no_count'] > 0) {
            $type = trim((string) $row['model_type']);
            $missingTypes[] = array(
                'model_type' => $type === '' ? 'unclassified' : $type,
                'count'      => (int) $row['no_count'],
                'is_all'     => ((int) $row['no_count'] === (int) $row['total'])
            );
        }
    }

    return array(
        'series'        => $series,
        'cap'           => $cap,
        'counted'       => $counted,
        'total'         => $totalModels,
        'no_value'      => max(0, $totalModels - $counted),
        'no_value_types' => $missingTypes
    );
}

/* Headline figures. Derived from the annotation counts already in hand so the
   1.88M row aggregate runs once, not four times. */
function geneHubTotals($annotations, $referenceAnnotation) {
    $totalModels = 0;
    $referenceModels = 0;
    $lines = array();

    foreach ($annotations as $a) {
        $totalModels += $a['gene_models'];
        if ($a['line'] !== '') { $lines[$a['line']] = true; }
        if ($a['annotation'] === $referenceAnnotation) {
            $referenceModels = $a['gene_models'];
        }
    }

    return array(
        'gene_models'      => $totalModels,
        'annotations'      => count($annotations),
        'lines'            => count($lines),
        'reference_models' => $referenceModels
    );
}

/* Gene loci that carry at least one gene model in a current annotation. The
   number the page uses to say how much of the collection is curated. */
function geneHubCuratedLocusCount($DBConn) {
    $rows = get_all_rows(make_query($DBConn,
        "SELECT count(DISTINCT locus_id) AS n
         FROM chado.gene_model
         WHERE analysis_is_current = 'yes' AND locus_id IS NOT NULL"));
    return $rows ? (int) $rows[0]['n'] : 0;
}


/* ---------------------------------------------------------------------------
   Option lists for the forms
   --------------------------------------------------------------------------- */

function geneHubOption($value, $label, $selected = false) {
    return '<option value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"'
         . ($selected ? ' selected' : '') . '>'
         . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</option>\n";
}

/* Annotation sets, in the same shape the previous page built them:
   makeGeneSetOptions() dropped 4a and 5b, labelled each set with its assembly
   and marked the configured current set as selected. */
function geneHubAnnotationOptions($sets, $currentSet, $withAnnotation = true) {
    $seen = array();
    $html = '';

    foreach ($sets as $set) {
        $name = isset($set['name']) ? trim((string) $set['name']) : '';
        if ($name === '4a' || $name === '5b') { continue; }

        $assembly = isset($set['assembly_version']) ? trim((string) $set['assembly_version']) : '';

        if ($withAnnotation) {
            $value = $name;
            $label = ($assembly === '') ? $name : $name . ' - ' . $assembly;
        } else {
            $value = $assembly;
            $label = $assembly;
        }

        if ($value === '' || isset($seen[$value])) { continue; }
        $seen[$value] = true;

        $html .= geneHubOption($value, $label, $withAnnotation && $name === $currentSet);
    }

    if (!$withAnnotation) {
        // The assembly list was sorted by label on the previous page.
        $lines = array_filter(explode("\n", $html), 'strlen');
        sort($lines);
        $html = implode("\n", $lines) . "\n";
    }

    return $html;
}

function geneHubModelTypeOptions($DBConn) {
    $rows = getLocusAssociatedGeneModelTypes($DBConn);
    $html = '';
    foreach ($rows as $row) {
        $html .= geneHubOption($row['value'], $row['value']);
    }
    return $html;
}

function geneHubGeneProductOptions($DBConn) {
    $sql = "
        SELECT DISTINCT gp.id AS gp_id, gp.name AS gp_name
        FROM gene_product gp
          INNER JOIN locus_gene_products lgp ON lgp.gene_product = gp.id
          INNER JOIN locus l ON l.id = lgp.id
          INNER JOIN id_num ON id_num.id = gp.id
        WHERE id_num.curation_lvl = 0
          AND gp.name NOT LIKE '?%'
        ORDER BY gp.name";
    $rows = get_all_rows(make_query($DBConn, $sql));

    $html = '';
    foreach ($rows as $row) {
        $html .= geneHubOption($row['gp_id'], $row['gp_name']);
    }
    return $html;
}

function geneHubPhenotypeOptions($DBConn) {
    $rows = get_all_rows(make_query($DBConn,
        "SELECT pheno_id, pheno_name FROM mgdb.locus_phenotypes ORDER BY pheno_name"));
    $html = '';
    foreach ($rows as $row) {
        $html .= geneHubOption($row['pheno_id'], $row['pheno_name']);
    }
    return $html;
}

function geneHubTraitOptions($DBConn) {
    $rows = get_all_rows(make_query($DBConn,
        "SELECT id, name FROM mgdb.locus_traits ORDER BY name"));
    $html = '';
    foreach ($rows as $row) {
        $html .= geneHubOption($row['id'], $row['name']);
    }
    return $html;
}

/* BLAST targets in the Gene models category, value formatted source|db_name as
   search/gene/gene_seq_search.php expects. */
function geneHubBlastTargetOptions($DBConn) {
    $sql = "
        SELECT bc.name AS blast_name, bc.source AS blast_source, bc.db_name AS blast_db_name
        FROM pc_blast_ctl bc
          INNER JOIN id_num ON id_num.id = bc.id
          INNER JOIN pc_assoc_category ac ON ac.id = bc.id
          INNER JOIN pc_category cat ON ac.category_id = cat.id
        WHERE cat.name = 'Gene models' AND id_num.curation_lvl = 0
        ORDER BY bc.name";
    $rows = get_all_rows(make_query($DBConn, $sql));

    $html = '';
    foreach ($rows as $row) {
        $html .= geneHubOption($row['blast_source'] . '|' . $row['blast_db_name'], $row['blast_name']);
    }
    return $html;
}

/* Every figure and option list the page needs, in one payload. Called inside
   dashboardCache(). */
function geneHubPageData($DBConn, $system) {
    $reference = 'Zm00001eb.1';

    $annotations = geneHubAnnotationCounts($DBConn);
    $totals      = geneHubTotals($annotations, $reference);

    $sets     = getGeneModelSetswRecs($DBConn, true);
    $allSets  = getGeneModelSets($DBConn, true);
    $current  = isset($system['cur_gm_set']) ? $system['cur_gm_set'] : '';

    return array(
        'reference'          => $reference,
        'totals'             => $totals,
        'curated_loci'       => geneHubCuratedLocusCount($DBConn),
        'annotations'        => $annotations,
        'chromosomes'        => geneHubChromosomeCounts($DBConn, $reference),
        'transcripts'        => geneHubTranscriptCounts($DBConn, $reference),
        'annotation_options' => geneHubAnnotationOptions($sets, $current, true),
        'assembly_options'   => geneHubAnnotationOptions($allSets, $current, true),
        'position_options'   => geneHubAnnotationOptions($sets, $current, false),
        'model_type_options' => geneHubModelTypeOptions($DBConn),
        'product_options'    => geneHubGeneProductOptions($DBConn),
        'phenotype_options'  => geneHubPhenotypeOptions($DBConn),
        'trait_options'      => geneHubTraitOptions($DBConn),
        'blast_options'      => geneHubBlastTargetOptions($DBConn),
        'built'              => date('F j, Y')
    );
}
