<?php
/* file: pan_gene_results_lib.php
 *
 * purpose: library of functions for gene simple search. These libraries should
 * be included in the gene search and all-data search for code reusability. 
 *
 * history:
 *   07/28/16  jportwood created
 *
 */

  include_once('../../lib/Bauplan.php');
  include_once('../../include/db-api.php');
  include_once('../../include/gp_lib.php');
  include_once('../../include/data_center_functions.php');


function downloadResults($DBConn, $term, $job_id, $filename) {
logMessage("Download results for job $job_id");
  if (!$job_id) {
    reportError("Download requested with no job id.");
    return;
  }
  if (!$filename) {
    reportError("Download did not specify which query, via filename");
  }
  
  // Download the requested query, as determined by file name
  getPanGeneRecords($DBConn, $term, $query_limit, 'download', $job_id);
}//downloadResults


function downloadAdvResults($DBConn, $search_filters, $job_id, $filename, $exemplars) {
logMessage("Download advanced search results for job $job_id");
  if (!$job_id) {
    reportError("Download requested with no job id.");
    return;
  }
  if (!$filename) {
    reportError("Download did not specify which query, via filename");
  }
  
  // Download the requested query, as determined by file name
  getAdvMatches($search_filters, 0, $DBConn, 'download', $job_id, ($exemplars=='yes'));
}//downloadResults


function getAdvMatches($search_filters, $search_limit, $DBConn, $action='display', $job_id='', $exemplars_only=false) {
//logVarDump($search_filters, "getAdvMatches(): Search data:\n");
  // Not all filters are implemented via the where clause
  $select = ($exemplars_only) 
          ? "SELECT DISTINCT exemplar_gene_model"
          : "SELECT DISTINCT pgs.pan_gene_name, pan_gene_analysis, pan_gene_count, 
           exemplar_gene_model, assembly_count, loci, max_annots, 
           ARRAY_AGG(DISTINCT protein) AS proteins, ARRAY_AGG(DISTINCT trait_name) AS traits";
  $whereclause = (count($search_filters['filters']) > 0) 
               ? "WHERE " . implode(' AND ', $search_filters['filters'])
               : '';
  $groupby     = ($exemplars_only)
               ? ''
               : "GROUP BY gene_model_name, pgs.pan_gene_name, pan_gene_analysis,pan_gene_count, 
             exemplar_gene_model, assembly_count, loci, max_annots";
  $orderby     = (isset($search_filters['orderby']) && !$exemplars_only)
               ? 'ORDER BY ' . $search_filters['orderby'] : '';
  $limitclause = ($search_limit > 0) ? "LIMIT $search_limit" : '';


  $sql = "
    $select
    FROM chado.pan_gene_search pgs
      INNER JOIN chado.pan_gene_assemblies pga ON pga.pan_gene_name=pgs.pan_gene_name
    $whereclause
    $groupby
    $orderby
    $limitclause";
logMessage("\n$sql\n");
        
  if ($action == 'download') {
    processDownloadRequest($sql, $job_id);
  }//download
  else {
    $sth = make_query($DBConn, $sql);
    return get_all_rows($sth);
  }
  
  return $matches;  
}//getAdvMatches


function getIDs($term, $pagesize, $search_limit, $offset, $DBConn) {
  if (!$term || trim($term) == '' || $term == '%%'  || $term == '%') {
    // No id or blank id: fail
    return false;
  }

  $query_limit = ($search_limit > 0) ? " LIMIT $search_limit OFFSET $offset" : '';
    
  // Look for pan-genes
  $search_matches = getPanGeneRecords($DBConn, $term, $query_limit);
//logVarDump($search_matches, "Found these matches:\n");

  return $search_matches;
}//getIDs


function getPanGeneProteinMatches($term, $pagesize, $search_limit, $offset, $DBConn) {
  $protein_matches = array();
  $sql = "
    SELECT DISTINCT pg.pan_gene_name, pg.pan_gene_analysis, 
                    pg.pan_gene_count, pg.exemplar_gene_model, 
                    ARRAY_LENGTH(pga.assemblies, 1) AS assembly_count, pgl.loci,
                    ma.max_annots, x.accession AS protein
    FROM chado.pan_gene pg
      INNER JOIN chado.feature_dbxref fx ON fx.feature_id=pg.gene_model_id
      INNER JOIN chado.dbxref x ON x.dbxref_id=fx.dbxref_id
      INNER JOIN chado.db ON db.db_id=x.db_id
        AND db.name IN ('UniProt', 'EC')
      INNER JOIN chado.analysis a ON a.analysis_id=pg.pan_gene_analysis_id
      INNER JOIN (
          SELECT pgs.analysis_id, COUNT(DISTINCT pgs.annotation_id) AS max_annots
          FROM chado.pan_gene_analysis_stats pgs
          GROUP BY pgs.analysis_id
        ) ma ON ma.analysis_id=a.analysis_id
      INNER JOIN chado.pan_gene_assemblies pga ON pga.pan_gene_name=pg.pan_gene_name
      LEFT OUTER JOIN chado.pan_gene_loci pgl ON pgl.pan_gene_name=pg.pan_gene_name  
    WHERE x.accession='$term'
  ";
  
  if ($action == 'download') {
    processDownloadRequest($sql, $job_id);
  }//download
  else {
    $sth = make_query($DBConn, $sql);
    while ($row = retrieve_row($sth)) {
      array_push($protein_matches, $row);
    }
  }
  
  return $protein_matches;
}//getPanGeneProteinMatches


function getPanGeneRecords($DBConn, $term, $query_limit, $action='display', $job_id='') {
  $raw_term = str_replace('%', '', $term);
  
  if (!preg_match("/[[:upper:]][[:lower:]]\d{5}[[:lower:]].*/", $term)) {
    // Term appears to be a locus
    $locus_matches = array();
    $sql = "
      SELECT * FROM (
        SELECT DISTINCT pg.pan_gene_name, pg.pan_gene_analysis, 
                        pg.pan_gene_count, ex.exemplar_gene_model, 
                        ARRAY_LENGTH(pga.assemblies, 1) AS assembly_count, pgl.loci,
                        ma.max_annots
        FROM chado.pan_gene pg
          INNER JOIN chado.pan_gene_exemplar ex ON ex.pan_gene_name=pg.pan_gene_name
          INNER JOIN chado.feature f ON f.feature_id=pg.gene_model_id 
          INNER JOIN chado.pan_gene_assemblies pga ON pga.pan_gene_name=pg.pan_gene_name
          -- pan-gene analysis
          INNER JOIN chado.analysis a ON a.analysis_id=pg.pan_gene_analysis_id
          INNER JOIN (
            SELECT pgs.analysis_id, COUNT(DISTINCT pgs.annotation_id) AS max_annots
            FROM chado.pan_gene_analysis_stats pgs
            GROUP BY pgs.analysis_id
          ) ma ON ma.analysis_id=a.analysis_id
          INNER JOIN chado.pan_gene_loci pgl ON pgl.pan_gene_name=pg.pan_gene_name  
          WHERE '$raw_term' LIKE ANY(pgl.loci)
      ) s";

    if ($action == 'download') {
      processDownloadRequest($sql, $job_id);
    }//download
    else {
      $sth = make_query($DBConn, $sql);
      while ($row = retrieve_row($sth)) {
        if (!$row['loci'] || $row['loci'] == '{}') {
          $rationalestr = '';
        }
        else {
          $loci = explode(',', trim($row['loci'], '{}'));
          // Get reason for locus association(s)
          $rationale = array();
          $sql = "
            SELECT * FROM chado.pan_gene_locus_assoc
            WHERE locus_name IN ('" . implode("','", $loci) . "')
            ORDER BY locus_name, gene_model_name";
          $rsth = make_query($DBConn, $sql);
          while ($rrow = retrieve_row($rsth)) {
            $rationalestr = '';
            $locusstr = '<a href="/gene_center/gene/' . $rrow['locus_name'] . '">' 
                      . $rrow['locus_name'] . '</a>';
            $genemodelstr = '<a href="/gene_center/gene/' . $rrow['gene_model_name'] . '">' 
                      . $rrow['gene_model_name'] . '</a>';
            if ($rrow['ext_db_comment'] != '') {
              $rationalestr = "$locusstr via $genemodelstr per " 
                            . $rrow['ext_db_comment'];
            }
            else {
              $rationalestr = "$locusstr via $genemodelstr per " 
                            . $rrow['source'];
            }
            if (!$rationale[$rrow['exemplar_gene_model']]) {
              $rationale[$rrow['exemplar_gene_model']] = array();
            }
            $rationale[$rrow['exemplar_gene_model']][] = $rationalestr;
          }//each locus association rationale row
          $row['rationale'] = implode('<br>', $rationale[$row['exemplar_gene_model']]);
        }
        
        array_push($locus_matches, $row);
      }
    
      if (count($locus_matches) == 0) {
        // May be a protein
        $protein_matches = getPanGeneProteinMatches($raw_term, $pagesize, $search_limit, $offset, $DBConn);
        if (count($protein_matches) == 0) {
          return false;
        }
        else {
          return array('match_type' => 'pan-gene protein', 'matches' => $protein_matches);
        }
      }//protein search
      else {
        return array('match_type' => 'pan-gene locus', 'matches' => $locus_matches);
      }
    }
  }//locus search
  
  else {
    // Check pan_gene and tandem/pan_gene
    $pan_gene_matches = array();
    $sql = "
      SELECT DISTINCT pg.pan_gene_name, pg.pan_gene_analysis, pg.exemplar_gene_model,
             pgl.loci
      FROM chado.pan_gene pg
        INNER JOIN chado.pan_gene_loci pgl ON pgl.pan_gene_id=pg.gene_set_id
        INNER JOIN chado.feature f ON f.feature_id=pg.gene_model_id
      WHERE f.name='$raw_term'";

    if ($action == 'download') {
      processDownloadRequest($sql, $job_id);
    }//download
    else {
      $sth = make_query($DBConn, $sql);
      if ($sth->rowCount() == 0) {
        // No pan-gene matches
        return false;
      }
      else {
        while ($row = retrieve_row($sth)) {
          array_push($pan_gene_matches, $row);
        }
        return array('match_type' => 'pan-gene', 'matches' => $pan_gene_matches);
      }
    }//return search results
  }//gene model search
  
  return false;
}//getPanGeneRecords


?>
