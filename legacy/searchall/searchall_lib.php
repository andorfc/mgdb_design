<?php
/* file: searchall_lib.php
 *
 * purpose: functions to support search requests
 *
 * history
 *  02/04/26  eksc  created
 */
 
 // Cross ref between id_num.type_term and table names.
 //   Note that this does no include archived data types
 $table_xref = array(
   'Clone Library' => 'clone_library',
   'Environment' => 'environment',
   'Gene Product' => 'gene_product',
   'Journal' => 'journal',
   'Karyotypic Variation Type' => 'karyotypic_variation',
   'Linkage Group' => 'linkage_group',
   'Locus' => 'locus',
   'Map' => 'map',
   'Map Scores' => 'map_scores',
   'Memo' => 'memo',
   'Metabolic Pathway' => 'meta_path',
   'Panel of Stock' => 'panel_of_stocks',
   'Person' => 'person',
   'Phenotype' => 'phenotype',
   'Probe' => 'probe',
   'QTL Experiment' => 'qtl_exp',
   'QTL Experiment Linkage Analysis' => 'qtl_link_analysis',
   'Recombination Data' => 'recomb',
   'Reference' => 'reference',
   'Full_reference' => 'reference',
   'Restriction Enzyme Primer' => 'primer',
   'Species' => 'species',
   'Stock' => 'stock',
   'Term' => 'term',
   'Trait Analysis' => 'trait_analysis',
   'Variation' => 'variation',
);

 // Cross ref between table name and id_num.type_term.
 //   Note that this does no include archived data types
 $table_name_xref = array(
   'clone_library' => 'Clone Library',
   'environment' => 'Environment',
   'gene_product' => 'Gene Product',
   'journal' => 'Journal',
   'karyotypic_variation' => 'Karyotypic Variation Type',
   'linkage_group' => 'Linkage Group',
   'locus' => 'Locus',
   'map' => 'Map',
   'map_scores' => 'Map Scores',
   'memo' => 'memo',
   'meta_path' => 'Metabolic Pathway',
   'panel_of_stocks' => 'Panel of Stock',
   'person' => 'Person',
   'phenotype' => 'Phenotype',
   'probe' => 'Probe',
   'qtl_exp' => 'QTL Experiment',
   'qtl_link_analysis' => 'QTL Experiment Linkage Analysis',
   'recomb' => 'Recombination Data',
   'reference' => 'Reference',
   'primer' => 'Restriction Enzyme Primer',
   'species' => 'Species',
   'stock' => 'Stock',
   'term' => 'Term',
   'trait_analysis' => 'Trait Analysis',
   'variation' => 'Variation',
);

$record_urls = array(
  'Clone Library' => '/data_center/clone',
//Unused; only 3 records
//  'Composite' => '',
//Unused; only 9 records
//  'DNA_RNA_Isolatin_Prep' => '',
  'Environment' => '/data_center/environment',
  'Environment Type' => '/data_center/environment',
  'Enz_Cat_Reaction' => '/data_center/ecr',
  'Gel Pattern' => '/data_center/gel',
  'Gene Product' => '/data_center/gene_product',
  'Journal' => '/data_center/journal',
  'Karyotypic Variation Type' => '/data_center/kv',
  'Linkage Group' => '/data_center/lg',
  'Locus' => '/data_center/locus',
  'Map' => '/data_center/map',
  'Map Scores' => '/data_center/map_scores',
  'Metabolic Pathway' => '/data_center/mp',
  'Panel of Stock' => '/data_center/pos',
  'Person' => '/data_center/person',
  'Phenotype' => '/data_center/phenotype',
  'Probe' => '/data_center/probe',
  'QTL Experiment' => '/data_center/qtl',
  'QTL Experiment Linkage Analysis' => '/data_center/qtl_analysis',
  'Recombination Data' => '/data_center/recombination',
  'Reference' => '/data_center/reference',
  'Restriction Enzyme Primer' => '/data_center/primer',
  'Species' => '/data_center/species',
  'Stock' => '/data_center/stock',
  'Term' => '/data_center/term',
  'Trait Analysis' => '/data_center/trait_analysis',
  'Variation' => '/data_center/variation',
);


function downloadSearchResults($results, $table) {
  $heads = array_keys($results[0]);
  $content = implode("\t", $heads) . "\n";
  foreach ($results as $r) {
    $row = array();
    foreach ($heads as $h) {
      $row[$h] = $r[$h];
    }
    $content .= implode("\t", $row) . "\n";
  }
  header("Content-type: application/octet-stream");
  header("Content-Disposition: attachment; filename=\"$table.txt\"");
  echo $content;
  exit;
}//downloadSearchResults


function getTable($table) {
  global $table_xref;
  if ($table_xref[$table]) {
   return $table_xref[$table];
  }
  
  return false;
}//getTable


function getURL($id_num_name) {
  global $record_urls;
  if ($record_urls[$id_num_name]) {
    return $record_urls[$id_num_name];
  }
  
  return false;
}//getURL


function sanitizeSearchTerm($term, $DBConn) {
  //validate_input
  if (strstr($term, "'")) {
    $term = str_replace("'", '', $term);
  }
  if (strstr($term, '"')) {
    $term = str_replace('"', '', $term);
  }
  
  return validate_input($DBConn, $term);
}//sanitizeSearchTerm


function searchAll($term, $DBConn) {
  global $table_xref;
  
  $term = strtolower($term);
  $results = array();
  $sql = "
    SELECT DISTINCT idn.id, t.name AS table FROM (
        SELECT id, text 
        FROM mgdb.all_text_search
        WHERE text LIKE '%$term%'
      ) s
    INNER JOIN id_num idn ON idn.id=s.id AND idn.curation_lvl=0
    INNER JOIN term t ON t.id=idn.type_term
    ORDER BY t.name";
  $sth = make_query($DBConn, $sql);
  if ($sth && $sth->rowCount() > 0) {
    $all_rows = get_all_rows($sth);
    foreach ($all_rows as $r) {
      $datatable = $table_xref[$r['table']];
      if (!$datatable) {
        reportError("ERROR: the data table name for " . $r['table'] . " is unknown.");
        continue;
      }
      if (!$results[$datatable]) {
        $results[$datatable] = array();
      }
      $results[$datatable][] = $r;
    }
  }
  
  // May be looking for a gene model associated with a locus, so don't restrict to
  //   terms that look like gene models.
  if ($more_results = searchGeneModel($term, $DBConn)) {
    $results['gene_model'] = $more_results;
  }
  
  if ($more_results = searchGenome($term, $DBConn)) {
    $results['genome'] = $more_results;
  }
  
  ksort($results);
  
  return $results;
}//searchAll


function searchByTerm($term, $table, $DBConn) {
  global $table_name_xref;
  
  $table_name = $table_name_xref[$table];
  $sql = "
    SELECT DISTINCT idn.id FROM (
      SELECT * FROM mgdb.all_text_search 
      WHERE text ILIKE '%$term%'
    ) s
      INNER JOIN id_num idn ON idn.id=s.id
        AND idn.curation_lvl=0
      INNER JOIN term t ON t.id=idn.type_term
    WHERE t.name='$table_name'";
  $sth = make_query($DBConn, $sql);
  $rows = get_all_rows($sth);
  $ids = implode(',', array_column($rows, 'id'));
  
  return $ids;
}//searchByTerm


function searchCloneLibrary($term, $ids, $DBConn) {
  $sql = "
    SELECT cl.id, cl.name, 
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT s.synonyms), ' ') AS synonyms,
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT m.memo), '<br>') AS comments
    FROM mgdb.clone_library cl
      INNER JOIN mgdb.id_num idn ON idn.id=cl.id
        AND idn.curation_lvl=0
      LEFT OUTER JOIN mgdb.synonyms s ON s.id=cl.id
        AND s.synonyms ILIKE '%$term%'
      LEFT OUTER JOIN mgdb.memo m ON m.id=cl.id
        AND m.memo  ILIKE '%$term%'
    WHERE cl.id IN ($ids)
    GROUP BY cl.id, cl.name
    ORDER BY cl.name";
  $sth = make_query($DBConn, $sql);
  
  return get_all_rows($sth);
}//searchCloneLibrary


function searchEnvironment($term, $ids, $DBConn) {
  $sql = "
    SELECT e.id, e.name, 
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT s.synonyms), ' ') AS synonyms,
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT m.memo), '<br>') AS comments
    FROM mgdb.environment e
      INNER JOIN mgdb.id_num idn ON idn.id=e.id
        AND idn.curation_lvl=0
      LEFT OUTER JOIN mgdb.synonyms s ON s.id=e.id
        AND s.synonyms ILIKE '%$term%'
      LEFT OUTER JOIN mgdb.memo m ON m.id=e.id
        AND m.memo  ILIKE '%$term%'
    WHERE e.id IN ($ids)
    GROUP BY e.id, e.name
    ORDER BY e.name";
  $sth = make_query($DBConn, $sql);
  
  return get_all_rows($sth);
}//searchEnvironment


function searchGeneModel($term, $DBConn) {
//logMessage("Search for gene model [$term]");
  $gm = (isTranscriptIdentifier($term))
      ? getTranscriptGeneModel($term) : $term;  // limit search to gene model name: faster
  $sql = "
    SELECT DISTINCT gene_name, annotation, assembly FROM (
      SELECT gm.gene_name, gm.locus_name, gm.version AS annotation, 
             gm.assembly_version AS assembly
      FROM chado.gene_model gm
        INNER JOIN chado.genome_metadata m ON m.annotation=gm.version
      WHERE (gm.gene_name ILIKE '%$gm%' OR gm.locus_name='$gm')
            AND gm.is_obsolete IS NOT TRUE
      ORDER BY (CASE WHEN (gm.gene_name='$gm' OR gm.locus_name='$gm') THEN 1 ELSE 2 END) DESC,
                gm.gene_name
    ) s";
  $sth=make_query($DBConn, $sql);
  if ($rows=get_all_rows($sth)) {
    return $rows;
  }
  else {
    // If $term is a gene model but not in the db, it may be in a pan_gene
    
    $sql = "
      SELECT additional_gene_model_name AS gene_name, pan_gene_name
      FROM chado.pan_gene 
      WHERE additional_gene_model_name='$gm'";
    $sth=make_query($DBConn, $sql);
    if ($rows=get_all_rows($sth)) {
      return $rows;
    }
  }
  
  return false;
}//searchGeneModel


function searchGeneProduct($term, $ids, $DBConn) {
  $sql = "
    SELECT gp.id, gp.name, 
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT s.synonyms), ' ') AS synonyms,
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT m.memo), '<br>') AS comments
    FROM mgdb.gene_product gp
      INNER JOIN mgdb.id_num idn ON idn.id=gp.id
        AND idn.curation_lvl=0
      LEFT OUTER JOIN mgdb.synonyms s ON s.id=gp.id
        AND s.synonyms ILIKE '%$term%'
      LEFT OUTER JOIN mgdb.memo m ON m.id=gp.id
        AND m.memo  ILIKE '%$term%'
    WHERE gp.id IN ($ids)
    GROUP BY gp.id, gp.name
    ORDER BY gp.name";
  $sth = make_query($DBConn, $sql);
  
  return get_all_rows($sth);
}//searchGeneProduct


function searchGenome($term, $DBConn) {
  $term = strtolower($term);
  $sql = "
    SELECT project, assembly_name AS assembly, annotation
      FROM chado.genome_metadata
      WHERE to_tsvector('english', CONCAT(project, ' ', assembly_name, ' ', annotation)) 
            @@ to_tsquery('english', '$term')
      ORDER BY assembly_name";

  $sth = make_query($DBConn, $sql);
  
  return get_all_rows($sth);
}//templates/home


function searchJournal($term, $ids, $DBConn) {
  $sql = "
    SELECT j.id, j.name, 
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT s.synonyms), ' ') AS synonyms,
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT m.memo), '<br>') AS comments
    FROM mgdb.journal j
      INNER JOIN mgdb.id_num idn ON idn.id=j.id
        AND idn.curation_lvl=0
      LEFT OUTER JOIN mgdb.synonyms s ON s.id=j.id
        AND s.synonyms ILIKE '%$term%'
      LEFT OUTER JOIN mgdb.memo m ON m.id=j.id
        AND m.memo ILIKE '%$term%'
    WHERE j.id IN ($ids)
    GROUP BY j.id, j.name
    ORDER BY j.name";
  $sth = make_query($DBConn, $sql);
  
  return get_all_rows($sth);
}//searchJournal


function searchLinkageGroup($term, $ids, $DBConn) {
  $sql = "
    SELECT lg.id, lg.name, 
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT s.synonyms), ' ') AS synonyms,
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT m.memo), '<br>') AS comments
    FROM mgdb.linkage_group lg
      INNER JOIN mgdb.id_num idn ON idn.id=lg.id
        AND idn.curation_lvl=0
      LEFT OUTER JOIN mgdb.synonyms s ON s.id=lg.id
        AND s.synonyms ILIKE '%$term%'
      LEFT OUTER JOIN mgdb.memo m ON m.id=lg.id
        AND m.memo ILIKE '%$term%'
    WHERE lg.id IN ($ids)
    GROUP BY lg.id, lg.name
    ORDER BY lg.name";
  $sth = make_query($DBConn, $sql);
  
  return get_all_rows($sth);
}//searchLinkageGroup


function searchLocus($term, $ids, $DBConn) {
  $sql = "
    SELECT l.id, l.name, l.full_name, 
    ARRAY_TO_STRING(ARRAY_AGG(DISTINCT s.synonyms), ', ') AS synonyms,
    ARRAY_TO_STRING(ARRAY_AGG(DISTINCT m.memo), '<br>') AS comments
    FROM mgdb.locus l
      INNER JOIN mgdb.id_num idn ON idn.id=l.id
        AND idn.curation_lvl=0
      LEFT OUTER JOIN mgdb.synonyms s ON s.id=l.id
        AND s.synonyms ILIKE '%$term%'
      LEFT OUTER JOIN mgdb.memo m ON m.id=l.id
        AND m.memo LIKE '%$term%'
    WHERE l.id IN ($ids)
    GROUP BY l.id, l.name, l.full_name
    ORDER BY CASE WHEN l.name='$term' THEN 1 ELSE 2 END, l.name";
  $sth = make_query($DBConn, $sql);
  
  return get_all_rows($sth);
}//searchLocus


function searchMap($term, $ids, $DBConn) {
  $sql = "
    SELECT mp.id, mp.name, 
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT s.synonyms), ' ') AS synonyms,
           ARRAY_TO_STRING(ARRAY_CAT(ARRAY_AGG(DISTINCT m.memo), ARRAY_AGG(a.memo)), '<br>') AS comments
    FROM mgdb.map mp
      INNER JOIN mgdb.id_num idn ON idn.id=mp.id
        AND idn.curation_lvl=0
      LEFT OUTER JOIN mgdb.synonyms s ON s.id=mp.id
        AND s.synonyms ILIKE '%$term%'
      LEFT OUTER JOIN mgdb.memo m ON m.id=mp.id
        AND m.memo  ILIKE '%$term%'
      LEFT OUTER JOIN mgdb.annotation a ON a.id=mp.id
        AND a.memo  ILIKE '%$term%'
    WHERE mp.id IN ($ids)
    GROUP BY mp.id, mp.name
    ORDER BY mp.name";
  $sth = make_query($DBConn, $sql);
  
  return get_all_rows($sth);
}//searchMap


function searchMapScores($term, $ids, $DBConn) {
  $sql = "
    SELECT ms.id, ms.name, 
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT s.synonyms), ' ') AS synonyms,
           ARRAY_TO_STRING(ARRAY_CAT(ARRAY_AGG(DISTINCT m.memo), ARRAY_AGG(a.memo)), '<br>') AS comments
    FROM mgdb.map_scores ms
      INNER JOIN mgdb.id_num idn ON idn.id=ms.id
        AND idn.curation_lvl=0
      LEFT OUTER JOIN mgdb.synonyms s ON s.id=ms.id
        AND s.synonyms ILIKE '%$term%'
      LEFT OUTER JOIN mgdb.memo m ON m.id=ms.id
        AND m.memo  ILIKE '%$term%'
      LEFT OUTER JOIN mgdb.annotation a ON a.id=ms.id
        AND a.memo  ILIKE '%$term%'
    WHERE ms.id IN ($ids)
    GROUP BY ms.id, ms.name
    ORDER BY ms.name";
  $sth = make_query($DBConn, $sql);
  
  return get_all_rows($sth);
}//searchMapScores


function searchPanelOfStocks($term, $ids, $DBConn) {
  $sql = "
    SELECT pos.id, pos.name, pos.comments, 
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT m.memo), '<br>') AS comments
    FROM mgdb.panel_of_stocks pos
      INNER JOIN mgdb.id_num idn ON idn.id=pos.id
        AND idn.curation_lvl=0
      LEFT OUTER JOIN mgdb.memo m ON m.id=pos.id
        AND m.memo ILIKE '%$term%'
    WHERE pos.id IN ($ids)
    GROUP BY pos.id, pos.name, pos.comments
    ORDER BY pos.name";
  $sth = make_query($DBConn, $sql);
  
  return get_all_rows($sth);
}//searchPanelOfStocks


function searchPerson($term, $ids, $DBConn) {
  $sql = "
    SELECT p.id, p.name, 
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT pi.person_interest), ', ') AS interests,
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT m.memo), '<br>') AS comments
    FROM mgdb.person p
      INNER JOIN mgdb.id_num idn ON idn.id=p.id
        AND idn.curation_lvl=0
      LEFT OUTER JOIN mgdb.person_interest pi ON pi.id=p.id
        AND pi.person_interest ILIKE '%$term%'
      LEFT OUTER JOIN mgdb.synonyms s ON s.id=p.id
        AND s.synonyms ILIKE '%$term%'
      LEFT OUTER JOIN mgdb.memo m ON m.id=p.id
        AND m.memo ILIKE '%$term%'
    WHERE p.id IN ($ids)
    GROUP BY p.id, p.name
    ORDER BY p.name";
  $sth = make_query($DBConn, $sql);
  
  return get_all_rows($sth);
}//searchPerson


function searchPhenotype($term, $ids, $DBConn) {
  $sql = "
    SELECT ph.id, ph.name, ph.comments AS comment,
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT s.synonyms), ', ') AS synonyms,
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT m.memo), '<br>') AS comments
    FROM mgdb.phenotype ph
      INNER JOIN mgdb.id_num idn ON idn.id=ph.id
        AND idn.curation_lvl=0
      LEFT OUTER JOIN mgdb.synonyms s ON s.id=ph.id
        AND s.synonyms ILIKE '%$term%'
      LEFT OUTER JOIN mgdb.memo m ON m.id=ph.id
        AND m.memo ILIKE '%$term%'
    WHERE ph.id IN ($ids)
    GROUP BY ph.id, ph.name
    ORDER BY ph.name";
  $sth = make_query($DBConn, $sql);
  
  return get_all_rows($sth);
}//searchPhenotype


function searchPrimer($term, $ids, $DBConn) {
  $sql = "
    SELECT p.id, p.name, ARRAY_TO_STRING(ARRAY_AGG(DISTINCT s.synonyms), ' ') AS synonyms,
           ARRAY_TO_STRING(ARRAY_APPEND(ARRAY_AGG(DISTINCT m.memo), p.comments), ' ') AS comments
    FROM mgdb.primer p
      INNER JOIN mgdb.id_num idn ON idn.id=p.id
        AND idn.curation_lvl=0
      LEFT OUTER JOIN mgdb.synonyms s ON s.id=p.id
        AND s.synonyms ILIKE '%$term%'
      LEFT OUTER JOIN mgdb.memo m ON m.id=p.id
        AND m.memo  ILIKE '%$term%'
    WHERE p.id IN ($ids)
    GROUP BY p.id, p.name, p.comments
    ORDER BY p.name";
  $sth = make_query($DBConn, $sql);
  
  return get_all_rows($sth);
}//searchPrimer


function searchProbe($term, $ids, $DBConn) {
  $sql = "
    SELECT p.id, p.name, 
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT s.synonyms), ' ') AS synonyms,
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT m.memo), ' ') AS comments
    FROM mgdb.probe p
      INNER JOIN mgdb.id_num idn ON idn.id=p.id
        AND idn.curation_lvl=0
      LEFT OUTER JOIN mgdb.synonyms s ON s.id=p.id
        AND s.synonyms ILIKE '%$term%'
      LEFT OUTER JOIN mgdb.memo m ON m.id=p.id
        AND m.memo ILIKE '%$term%'
    WHERE p.id IN ($ids)
    GROUP BY p.id, p.name
    ORDER BY p.name";
  $sth = make_query($DBConn, $sql);
  
  return get_all_rows($sth);
}//searchProbe


function searchQTLExperiment($term, $ids, $DBConn) {
  $sql = "
    SELECT qe.id, qe.name, ARRAY_TO_STRING(ARRAY_AGG(s.synonyms), ' ') AS synonyms,
           ARRAY_TO_STRING(ARRAY_AGG(m.memo), ' ') AS comments
    FROM mgdb.qtl_exp qe
      INNER JOIN mgdb.id_num idn ON idn.id=qe.id
        AND idn.curation_lvl=0
      LEFT OUTER JOIN mgdb.synonyms s ON s.id=qe.id
        AND s.synonyms ILIKE '%$term%'
      LEFT OUTER JOIN mgdb.memo m ON m.id=qe.id
        AND m.memo  ILIKE '%$term%'
    WHERE qe.id IN ($ids)
    GROUP BY qe.id, qe.name
    ORDER BY qe.name";
  $sth = make_query($DBConn, $sql);
  
  return get_all_rows($sth);
}//searchQTLExperiment


function searchQTLLinkAnalysis($term, $ids, $DBConn) {
  $sql = "
    SELECT qla.id, qla.name, qla.method,
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT m.memo), '<br>') AS comments
    FROM mgdb.qtl_link_analysis qla
      INNER JOIN mgdb.id_num idn ON idn.id=qla.id
        AND idn.curation_lvl=0
      LEFT OUTER JOIN mgdb.memo m ON m.id=qla.id
        AND m.memo ILIKE '%$term%'
    WHERE qla.id IN ($ids)
    GROUP BY qla.id, qla.name
    ORDER BY qla.name";
  $sth = make_query($DBConn, $sql);
  
  return get_all_rows($sth);
}//searchQTLLinkAnalysis


function searchRecombination($term, $ids, $DBConn) {
  $sql = "
    SELECT r.id, r.name 
    FROM mgdb.recomb r
      INNER JOIN mgdb.id_num idn ON idn.id=r.id
        AND idn.curation_lvl=0
      LEFT OUTER JOIN mgdb.synonyms s ON s.id=r.id
        AND s.synonyms ILIKE '%$term%'
      LEFT OUTER JOIN mgdb.memo m ON m.id=r.id
        AND m.memo ILIKE '%$term%'
    WHERE r.id IN ($ids)
    ORDER BY r.name";
  $sth = make_query($DBConn, $sql);
  
  return get_all_rows($sth);
}//searchRecombination


function searchReference($term, $ids, $DBConn) {
  $sql = "
    SELECT r.id, r.title, r.doi, x.key AS pubmed
    FROM reference r
      INNER JOIN mgdb.id_num idn ON idn.id=r.id
        AND idn.curation_lvl=0
      LEFT OUTER JOIN ext_db_key x ON x.id=r.id
        AND db_person = (
          SELECT id FROM person 
          WHERE name = 'Medline -- PubMed')
    WHERE r.id IN ($ids)
    ORDER BY r.year DESC";
  $sth = make_query($DBConn, $sql);
  
  return get_all_rows($sth);
}//searchReference


function searchSpecies($term, $ids, $DBConn) {
  $sql = "
    SELECT sp.id, sp.species, ARRAY_TO_STRING(ARRAY_AGG(DISTINCT s.synonyms), ' ') AS synonyms,
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT m.memo), ' ') AS comments
    FROM mgdb.species sp
      INNER JOIN mgdb.id_num idn ON idn.id=sp.id
        AND idn.curation_lvl=0
      LEFT OUTER JOIN mgdb.synonyms s ON s.id=sp.id
        AND s.synonyms ILIKE '%$term%'
      LEFT OUTER JOIN mgdb.memo m ON m.id=sp.id
        AND m.memo  ILIKE '%$term%'
    WHERE sp.id IN ($ids)
    GROUP BY sp.id, sp.species
    ORDER BY sp.species";
  $sth = make_query($DBConn, $sql);
  
  return get_all_rows($sth);
}//searchSpecies


function searchStock($term, $ids, $DBConn) {
  $sql = "
    SELECT st.id, st.name, st.pedigree,
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT s.synonyms), ', ') AS synonyms,
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT m.memo), '<br>') AS comments
    FROM mgdb.stock st 
      INNER JOIN mgdb.id_num idn ON idn.id=st.id
        AND idn.curation_lvl=0
      LEFT OUTER JOIN mgdb.synonyms s ON s.id=st.id
        AND s.synonyms ILIKE '%$term%'
      LEFT OUTER JOIN mgdb.memo m ON m.id=st.id
        AND m.memo ILIKE '%$term%'
    WHERE st.id IN ($ids)
    GROUP BY st.id, st.name
    ORDER BY st.name";
  $sth = make_query($DBConn, $sql);
  
  return get_all_rows($sth);
}//searchStock


function searchTerm($term, $ids, $DBConn) {
  $sql = "
    SELECT t.id, t.name, ty.name AS type, t.term_comments, 
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT d.description), '<br>') AS description,
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT s.synonyms), ', ') AS synonyms
    FROM mgdb.term t
      INNER JOIN mgdb.id_num idn ON idn.id=t.id
        AND idn.curation_lvl=0
      INNER JOIN mgdb.term ty ON ty.id=t.type
      LEFT OUTER JOIN mgdb.description d ON d.id=t.id
        AND d.description ILIKE '%$term%'
      LEFT OUTER JOIN mgdb.synonyms s ON s.id=t.id
        AND s.synonyms ILIKE '%$term%'
    WHERE t.id IN ($ids)
    GROUP BY t.id, t.name, ty.name
    ORDER BY t.name";
  $sth = make_query($DBConn, $sql);
  
  return get_all_rows($sth);
}//term


function searchTraitAnalysis($term, $ids, $DBConn) {
  $sql = "
    SELECT ta.id, ta.name,
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT m.memo), '<br>') AS comments
    FROM mgdb.trait_analysis ta
      INNER JOIN mgdb.id_num idn ON idn.id=ta.id
        AND idn.curation_lvl=0
      LEFT OUTER JOIN mgdb.memo m ON m.id=ta.id
        AND m.memo ILIKE '%$term%'
    WHERE ta.id IN ($ids)
    GROUP BY ta.id, ta.name
    ORDER BY ta.name";
  $sth = make_query($DBConn, $sql);
  
  return get_all_rows($sth);
}//searchTraitAnalysis


function searchVariation($term, $ids, $DBConn) {
  $sql = "
    SELECT v.id, v.name,
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT s.synonyms), ', ') AS synonyms,
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT m.memo), '<br>') AS comments
    FROM mgdb.variation v
      INNER JOIN mgdb.id_num idn ON idn.id=v.id
        AND idn.curation_lvl=0
      LEFT OUTER JOIN mgdb.synonyms s ON s.id=v.id
        AND s.synonyms ILIKE '%$term%'
      LEFT OUTER JOIN mgdb.memo m ON m.id=v.id
        AND m.memo ILIKE '%$term%'
    WHERE v.id IN ($ids)
    GROUP BY v.id, v.name
    ORDER BY v.name";
  $sth = make_query($DBConn, $sql);
  
  return get_all_rows($sth);
}//searchVariation


?>