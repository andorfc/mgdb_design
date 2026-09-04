<?php
/* file: metabolic_pathway_search_lib.php
 *
 * purpose: the pathway census and search behind /metabolic_pathways.
 *
 * Shape
 * -----
 * The corpus is mgdb.corncyc_gene_model_pathway -- 23,957 rows of
 * gene model -> CornCyc protein -> CornCyc pathway, for two B73 assemblies.
 * MaizeGDB holds this table itself, so it outlives the CornCyc *websites*
 * MaizeGDB used to host, which is why retiring those did not take the search
 * with them.
 *
 * A pathway is the record, not a row: 23,957 rows collapse to 549 pathways.
 * The census is built once (a full scan, ~150 ms) and cached, and pathway
 * search then runs over the cached array with no SQL at all. Only a gene-model
 * query reaches the database, and that is the one column carrying an index
 * (corncyc_gene_model_pathway_idx on gene_model).
 */

if (!defined('MP_MAX_RESULTS')) {
    /* The corpus is 549 pathways, so "all" is a page size, not a risk. */
    define('MP_MAX_RESULTS', 600);
}

/* --------------------------------------------------------------------------
 * Text
 *
 * Pathway and protein names arrive carrying MetaCyc's own presentational
 * markup -- 2,414 pathway names and 2,337 protein names contain a tag -- and
 * 1,269 pathway names are additionally wrapped in literal double quotes
 * ("2,4,6-trinitrotoluene degradation").
 *
 * Printing them raw would put database content into the page unescaped.
 * Escaping them wholesale shows the reader `<i>de novo</i>`. So: strip the
 * wrapping quotes, escape everything, then re-enable exactly the seven
 * inline tags the data actually uses, in either case. Anything else stays
 * escaped, including an attribute on one of those seven.
 * -------------------------------------------------------------------------- */

if (!defined('MP_RICH_TAGS')) {
    define('MP_RICH_TAGS', 'i|em|sub|sup|small|b|strong');
}

function mpUnquote($value) {
    $value = trim((string) $value);
    if (strlen($value) > 1 && $value[0] === '"' && substr($value, -1) === '"') {
        $value = trim(substr($value, 1, -1));
    }
    return $value;
}

/* Safe for innerHTML and for a Bauplan slot. */
function mpRich($value) {
    $escaped = htmlspecialchars(mpUnquote($value), ENT_QUOTES, 'UTF-8');
    /* Only a bare tag is restored: `&lt;i&gt;` yes, `&lt;i onclick=...&gt;` no. */
    return preg_replace('#&lt;(/?)(' . MP_RICH_TAGS . ')&gt;#i', '<$1$2>', $escaped);
}

/* Safe for a TSV cell, a title attribute, and for matching a search term
   against -- searching "de novo" has to hit `<i>de novo</i>`. */
function mpPlain($value) {
    $value = preg_replace('#</?(' . MP_RICH_TAGS . ')>#i', '', mpUnquote($value));
    return trim(html_entity_decode($value, ENT_QUOTES, 'UTF-8'));
}

/* Fold to the form both the index and the query are compared in. */
function mpNormalize($value) {
    $value = mpPlain($value);
    $value = strtolower($value);
    /* Punctuation is noise here: "glycolysis I (from glucose 6-phosphate)"
       should be reachable by "glycolysis I from glucose". */
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
    return trim(preg_replace('/\s+/', ' ', $value));
}


/* --------------------------------------------------------------------------
 * The census
 * -------------------------------------------------------------------------- */

/* One row per pathway, with the assemblies it appears in and the gene models
   and proteins assigned to it.
 
   `corncyc_pathway_id IS NOT NULL` is the filter that matters, and it is NULL
   rather than the empty string: 5,758 gene models carry a CornCyc protein
   assignment with no pathway, and a `<> ''` test would keep them (NULL fails
   both comparisons, so it happens to exclude them too -- but only by accident,
   and the accident is worth not depending on).

   Returns a list ordered by gene-model count, so the busiest pathways lead and
   the figure gets the order it needs without a second pass. */
function mpPathwayCensus($DBConn) {
    $sth = make_query($DBConn, "
        SELECT corncyc_pathway_id AS pid,
               MIN(corncyc_pathway_name) AS pname,
               COUNT(DISTINCT gene_model) AS gene_models,
               COUNT(DISTINCT corncyc_protein) FILTER (WHERE corncyc_protein IS NOT NULL
                                                         AND corncyc_protein <> '') AS proteins,
               COUNT(*) AS rows,
               BOOL_OR(assembly_version = 'B73 RefGen_v3') AS in_v3,
               BOOL_OR(assembly_version = 'B73 RefGen_v4') AS in_v4
        FROM mgdb.corncyc_gene_model_pathway
        WHERE corncyc_pathway_id IS NOT NULL
        GROUP BY corncyc_pathway_id
        ORDER BY COUNT(DISTINCT gene_model) DESC, LOWER(MIN(corncyc_pathway_name))", 1, array());

    $rows = array();
    while ($row = retrieve_row($sth)) {
        $assemblies = array();
        if ($row['in_v3'] === 't' || $row['in_v3'] === true || $row['in_v3'] === 'true') {
            $assemblies[] = 'B73 RefGen_v3';
        }
        if ($row['in_v4'] === 't' || $row['in_v4'] === true || $row['in_v4'] === 'true') {
            $assemblies[] = 'B73 RefGen_v4';
        }
        $name = (string) $row['pname'];
        $rows[] = array(
            'id'          => (string) $row['pid'],
            'name'        => mpPlain($name),
            'name_html'   => mpRich($name),
            'gene_models' => (int) $row['gene_models'],
            'proteins'    => (int) $row['proteins'],
            'assemblies'  => $assemblies,
            'url'         => mpPathwayUrl((string) $row['pid']),
            'metacyc_url' => mpMetacycUrl((string) $row['pid'])
        );
    }
    return $rows;
}

/* Where a CornCyc pathway id now points.
 
   CornCyc ids are MetaCyc ids, so both destinations below are real. PMN is the
   maize build and the better landing place, and it is the one the retirement
   notice sends people to; MetaCyc is offered beside it because pmn.plantcyc.org
   sits behind a bot check that no automated link check can pass, and a reader
   who hits it should have somewhere else to go. */
function mpPathwayUrl($pathway_id) {
    return 'https://pmn.plantcyc.org/CORN/NEW-IMAGE?type=PATHWAY&object=' . rawurlencode($pathway_id);
}

function mpMetacycUrl($pathway_id) {
    return 'https://metacyc.org/pathway?orgid=META&id=' . rawurlencode($pathway_id);
}


/* Corpus-wide figures for the metric cards.
 
   Written as one statement of independent scalar subqueries rather than
   COUNT(*) FILTER over a join, because each is an independent aggregate on the
   same small table and the planner reads it once per subquery either way. */
function mpSummaryStats($DBConn) {
    $row = retrieve_row(make_query($DBConn, "
        SELECT
          (SELECT COUNT(DISTINCT corncyc_pathway_id) FROM mgdb.corncyc_gene_model_pathway
             WHERE corncyc_pathway_id IS NOT NULL) AS pathways,
          (SELECT COUNT(DISTINCT gene_model) FROM mgdb.corncyc_gene_model_pathway
             WHERE corncyc_pathway_id IS NOT NULL) AS gene_models_in_pathway,
          (SELECT COUNT(DISTINCT gene_model) FROM mgdb.corncyc_gene_model_pathway) AS gene_models,
          (SELECT COUNT(DISTINCT corncyc_protein) FROM mgdb.corncyc_gene_model_pathway
             WHERE corncyc_protein IS NOT NULL AND corncyc_protein <> '') AS proteins,
          (SELECT COUNT(*) FROM mgdb.corncyc_gene_model_pathway) AS assignments", 1, array()));

    return array(
        'pathways'               => (int) ($row['pathways'] ?? 0),
        'gene_models'            => (int) ($row['gene_models'] ?? 0),
        'gene_models_in_pathway' => (int) ($row['gene_models_in_pathway'] ?? 0),
        'proteins'               => (int) ($row['proteins'] ?? 0),
        'assignments'            => (int) ($row['assignments'] ?? 0)
    );
}

/* Per-assembly figures, for the figure under the metric cards. */
function mpAssemblyRows($DBConn) {
    $sth = make_query($DBConn, "
        SELECT assembly_version,
               COUNT(DISTINCT corncyc_pathway_id) FILTER (WHERE corncyc_pathway_id IS NOT NULL) AS pathways,
               COUNT(DISTINCT gene_model) AS gene_models,
               COUNT(DISTINCT corncyc_protein) FILTER (WHERE corncyc_protein IS NOT NULL
                                                         AND corncyc_protein <> '') AS proteins
        FROM mgdb.corncyc_gene_model_pathway
        GROUP BY assembly_version
        ORDER BY assembly_version", 1, array());
    $rows = array();
    while ($row = retrieve_row($sth)) {
        $rows[] = array(
            'assembly'    => (string) $row['assembly_version'],
            'pathways'    => (int) $row['pathways'],
            'gene_models' => (int) $row['gene_models'],
            'proteins'    => (int) $row['proteins']
        );
    }
    return $rows;
}


/* --------------------------------------------------------------------------
 * Search
 * -------------------------------------------------------------------------- */

/* Which pathways a gene model belongs to.
 
   The only arm that touches the database. `gene_model` carries the table's
   one index, and the two assemblies name gene models differently -- v4 rows
   are gene ids (Zm00001d053174), v3 rows are protein ids
   (GRMZM2G345493_P01) -- so an exact match is tried first and a prefix match
   second, which is what lets a reader paste `GRMZM2G345493` and still land.
 
   Returns a set of pathway ids. */
function mpPathwayIdsForGeneModel($DBConn, $term) {
    $term = trim((string) $term);
    if ($term === '' || strlen($term) > 60) {
        return array();
    }

    $ids = array();
    $sth = make_query($DBConn, "
        SELECT DISTINCT corncyc_pathway_id AS pid
        FROM mgdb.corncyc_gene_model_pathway
        WHERE corncyc_pathway_id IS NOT NULL
          AND (gene_model = :exact OR UPPER(gene_model) = :upper)", 1,
        array('exact' => $term, 'upper' => strtoupper($term)));
    while ($row = retrieve_row($sth)) {
        $ids[(string) $row['pid']] = true;
    }

    if (count($ids) === 0) {
        /* Anchored, so the index is still usable, and bounded because a very
           short prefix would otherwise match most of the table. */
        if (strlen($term) >= 6) {
            $sth = make_query($DBConn, "
                SELECT DISTINCT corncyc_pathway_id AS pid
                FROM mgdb.corncyc_gene_model_pathway
                WHERE corncyc_pathway_id IS NOT NULL
                  AND gene_model LIKE :prefix
                LIMIT 500", 1, array('prefix' => addcslashes($term, '%_\\') . '%'));
            while ($row = retrieve_row($sth)) {
                $ids[(string) $row['pid']] = true;
            }
        }
    }

    return $ids;
}

/* The search.
 
   $census is the cached list from mpPathwayCensus(). Three arms, best first:
 
     1. the term as a CornCyc pathway id      (PWY-3781, GLYCOLYSIS)
     2. the term as a gene model              (Zm00001d053174, GRMZM2G345493)
     3. the term as words in a pathway name   (glycolysis, de novo)
 
   Arms 1 and 3 are merged rather than short-circuited, because a pathway id is
   sometimes also an English word: GLYCOLYSIS is the id of "glycolysis I (from
   glucose 6-phosphate)", so stopping at the id arm answered "glycolysis" with
   one pathway when the corpus holds five. The id hit leads and the name
   matches follow it, de-duplicated. Arm 2 does short-circuit: a term that
   resolves to a gene model is not also a pathway name, and mixing the two
   would misreport what was matched.
 
   Arm 3 requires every word of the query to appear, so "starch biosynthesis"
   does not return every pathway with "biosynthesis" in it. Within it, a name
   that starts with the query sorts above one that merely contains it, and the
   census order (busiest first) breaks the remaining ties.
 
   Returns array('results' => ..., 'total' => ..., 'matched_by' => ...). */
function mpSearch($DBConn, $filters, $census, $limit = 25, $offset = 0) {
    $term     = isset($filters['term']) ? trim((string) $filters['term']) : '';
    $assembly = isset($filters['assembly']) ? trim((string) $filters['assembly']) : '';

    $matched_by = '';
    $matches = $census;

    if ($term !== '') {
        $needle = mpNormalize($term);
        $upper  = strtoupper($term);
        $found = array();
        $seen  = array();

        /* Arm 1 -- the term as a pathway id. */
        foreach ($census as $row) {
            if (strtoupper($row['id']) === $upper) {
                $found[] = $row;
                $seen[$row['id']] = true;
            }
        }
        if (count($found) > 0) {
            $matched_by = 'pathway_id';
        }

        /* Arm 2 -- the term as a gene model. Only when nothing above matched:
           a gene model accession is never also a pathway id or an English
           pathway name, so there is nothing to merge. */
        if (count($found) === 0) {
            $ids = mpPathwayIdsForGeneModel($DBConn, $term);
            if (count($ids) > 0) {
                foreach ($census as $row) {
                    if (isset($ids[$row['id']])) {
                        $found[] = $row;
                        $seen[$row['id']] = true;
                    }
                }
                $matched_by = 'gene_model';
            }
        }

        /* Arm 3 -- words in a pathway name. Runs unless a gene model claimed
           the term, and appends to whatever arm 1 found. */
        if ($matched_by !== 'gene_model' && $needle !== '') {
            $words = explode(' ', $needle);
            $starts = array();
            $contains = array();
            foreach ($census as $row) {
                if (isset($seen[$row['id']])) { continue; }
                $hay = mpNormalize($row['name']);
                $all = true;
                foreach ($words as $word) {
                    if ($word !== '' && strpos($hay, $word) === false) { $all = false; break; }
                }
                if (!$all) { continue; }
                if (strpos($hay, $needle) === 0) { $starts[] = $row; } else { $contains[] = $row; }
            }
            $added = count($starts) + count($contains);
            $found = array_merge($found, $starts, $contains);
            /* Say which arms actually contributed. Reporting a merged set as
               "matching that pathway ID" would describe one row and count
               five. */
            if ($matched_by === 'pathway_id' && $added > 0) {
                $matched_by = 'pathway_id_and_name';
            } elseif ($matched_by === '' && count($found) > 0) {
                $matched_by = 'pathway_name';
            }
        }

        $matches = $found;
    }

    if ($assembly !== '') {
        $filtered = array();
        foreach ($matches as $row) {
            if (in_array($assembly, $row['assemblies'], true)) {
                $filtered[] = $row;
            }
        }
        $matches = $filtered;
    }

    $total = count($matches);
    if ($limit === null) {
        $page = $matches;
    } else {
        $page = array_slice($matches, max(0, (int) $offset), max(1, (int) $limit));
    }

    return array('results' => $page, 'total' => $total, 'matched_by' => $matched_by);
}
?>
