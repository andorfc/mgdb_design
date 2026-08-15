<?php
/* file: stock_results_lib.php
 *
 * purpose: functions for stock searching.
 *
 * history:
 *  07/20/20  eksc  created from stock_results.php
 */
 
function downloadResults($DBConn, $term, $job_id, $filename) {
  if (!$job_id) {
    reportError("Download requested with no job id.");
    return;
  }
  if (!$filename) {
    reportError("Download did not specify which query, via filename");
  }

  doSearch($DBConn, $term, 0, 'download', $job_id);  
}//downloadResults


function doSearch($DBConn, $term, $search_limit, $action='display', $job_id='') {
  global $system;
  
  $case = trim(getCGIParam('case', 'GP', ''));
  $raw_term = urldecode(trim(getCGIParam('term', 'GP', 
                               getCGIParam('stock_term', 'S', ''))));
  
  $query_limit = ($action == 'download' || (!$search_limit || $search_limit == 0)) 
                    ? '' : "LIMIT $search_limit";
  
  if ($action == 'display' || $action == 'download') {
    if ($case) {
      $sql = build_stock_query_with_case($raw_term, $term, $query_limit);
    }
    else {
      $sql = build_stock_query($raw_term, $query_limit);
    }
  }
  else if ($action == 'count') {
    if ($case) {
      $sql = build_stock_count_query_with_case($raw_term, $term, $query_limit);
    }
    else {
      $sql = build_stock_count_query($raw_term, $query_limit);
    }
  }
  else {
    reportError("Unknown action submitted to stock_results_lib.php:doSearch() - '$action'");
    return false;
  }
  
  if ($action == 'download') {
    processDownloadRequest($sql, $job_id);
  }//download
  
  else if ($action == 'count') {
    return processCountRequest($DBConn, $sql);
  }//count
  
  else {
    $sth = make_query($DBConn, $sql);
    return get_all_rows($sth);
  }//display
}//doSearch


function build_stock_count_query($term) {
  // TYPE_TERM 26 = "Stock"
  $term = strtolower($term);
  $sql = "
    SELECT COUNT(DISTINCT(idn.id)) 
    FROM id_num idn
      INNER JOIN description d ON d.id=idn.id
      LEFT JOIN synonyms s ON s.id=idn.id
      LEFT JOIN ext_db_key x ON x.id = idn.id
    WHERE idn.type_term = 26 AND idn.curation_lvl IN (0, 101, 102) ";
  
  $term_token = strtok($term, ' ');
  while ($term_token !== false) {
    if ((substr($term_token, -1) == ')') 
          && (substr($term_token, 0, 1) == '(')) {
      $term_token = substr($term_token, 1, (strlen($term_token)-2));
      $sql .= "
          AND (LOWER(d.description) LIKE '% $term_token %' 
               OR LOWER(d.description) LIKE '% $term_token; %' 
               OR LOWER(d.description) LIKE '% $term_token'
               OR LOWER(s.synonyms) LIKE '%$term_token%'
               OR LOWER(x.key) LIKE '%$term_token%'
              )";
    }
    else {
      $sql .= "
          AND (LOWER(d.description) LIKE '%$term_token%'
               OR LOWER(s.synonyms) LIKE '%$term_token%'
               OR LOWER(x.key) LIKE '%$term_token%'
              )";
    }
    
    $term_token = strtok(' ');
  }//while
  
  return $sql;
}//build_stock_count_query() 
  
  
function build_stock_query($term, $query_limit) {
  // TYPE_TERM 26 = "Stock"
  $term = strtolower($term);
  // dumm1 and dumm2 required by postgres for order by clause
  $sql = "
    SELECT DISTINCT(idn.id), idn.curation_lvl, 
           LOWER(d.description)='$term' AS exact, 
           d.description AS name, LOWER(d.description)='$term' AS dumm1,
           LOWER(d.description) LIKE '$term%' AS dumm2,
           ARRAY_AGG(DISTINCT s.synonyms) AS synonyms,
           ARRAY_AGG(DISTINCT t.name || '|' || m.memo) AS comments
    FROM id_num idn
      INNER JOIN description d ON d.id = idn.id
      LEFT JOIN synonyms s ON s.id = idn.id
      LEFT JOIN memo m ON m.id = idn.id
      LEFT OUTER JOIN term t ON t.id=m.type_term
      LEFT JOIN ext_db_key x ON x.id = idn.id
    WHERE idn.type_term = 26 AND idn.curation_lvl IN (0, 101, 102) ";
  
  $term_token = strtok($term, ' ');
  while ($term_token !== false) {
    if ((substr($term_token,-1) == ')') 
          && (substr($term_token,0,1) == '(')) {
      $term_token = substr($term_token, 1, (strlen($term_token)-2));
      $sql .= "
          AND (LOWER(d.description) LIKE '%$term_token%' 
               OR LOWER(d.description) LIKE '%$term_token; %' 
               OR LOWER(d.description) LIKE '%$term_token'
               OR LOWER(s.synonyms) LIKE '%$term_token%' 
               OR LOWER(x.key) LIKE '%$term_token%'
              )";
    }
    else {
      $sql .= "
          AND (LOWER(d.description) LIKE '%$term_token%'
               OR LOWER(s.synonyms) LIKE '%$term_token%'
               OR LOWER(x.key) LIKE '%$term_token%'
              )";
    }
    
    $term_token = strtok(' ');
  }//while
  
  return $sql . " 
    GROUP BY idn.id, idn.curation_lvl, d.description
    ORDER BY LOWER(d.description)='$term' DESC, 
      LOWER(d.description) LIKE '$term%' DESC, d.description 
    $query_limit";
}//build_stock_query()
  

function build_stock_count_query_with_case($term) {
  // TYPE_TERM 26 = "Stock"
  $sql = "
    SELECT COUNT(DISTINCT(idn.id)) 
    FROM id_num idn
      INNER JOIN description d ON d.id = idn.id
      LEFT JOIN synonyms s ON s.id = idn.id
      LEFT JOIN ext_db_key x ON x.id = idn.id
    WHERE idn.type_term = 26 AND idn.curation_lvl = 0 ";
  
  // tokenize term; order within name may vary.
  $term_token = strtok($term, ' ');
  while ($term_token !== false) {
    if ((substr($term_token, -1) == ')') 
        && (substr($term_token, 0, 1) == '(')) {
      $term_token = substr($term_token, 1, (strlen($term_token)-2));
      $sql .= "
          AND (d.description LIKE '% $term_token %' 
               OR d.description '% $term_token; %' 
               OR d.description '% $term_token'
               OR s.synonyms LIKE '%$term_token%'
               OR x.key LIKE '%$term_token%'
              )";
    }
    else {
      $sql .= "
          AND (d.description LIKE '%$query_trait%'
               OR s.synonyms LIKE '%$query_trait%'
               OR x.key LIKE '%$term_token%'
              )";
    }
    $term_token = strtok(' ');
  }//while
  
  return $sql;
}//build_stock_count_query_with_case()


function build_stock_query_with_case($raw_term, $term, $query_limit) {
  // TYPE_TERM 26 = "Stock"
  // dumm1 and dumm2 required by postgres for order by clause
  $sql = "
    SELECT DISTINCT(idn.id), idn.curation_lvl, b.description='$term' AS exact, d.description AS name,
           LOWER(d.description)='$term' AS dumm1, LOWER(d.description) LIKE '$term%' AS dumm2,
           ARRAY_AGG(DISTINCT t.name || '|' || m.memo) AS comments
    FROM id_num idn
      INNER JOIN description d on d.id = idn.id
      LEFT JOIN synonyms s on s.id = idn.id
      LEFT JOIN memo m ON m.id = idn.id
      LEFT OUTER JOIN term t ON t.id=m.type_term
      LEFT JOIN ext_db_key x ON x.id = idn.id
    WHERE idn.type_term = 26 AND idn.curation_lvl IN (0, 101, 102)";
  
  $term_token = strtok($term, ' ');
  while ($term_token !== false) {
    if ((substr($term_token, -1) == ')')
          && (substr($term_token, 0, 1) == '(')) {
      $term_token = substr($term_token, 1, (strlen($term_token)-2));
      $sql .= "
          AND (b.description LIKE '%$term_token%' 
               OR LOWER(d.description) LIKE '%$term_token%' 
               OR LOWER(d.description) LIKE '%$term_token; %'
               OR s.synonyms LIKE '%$term_token%' 
               OR x.key LIKE '%$term_token%'
              )";
    }
    else {
      $sql .= "
          AND (d.description LIKE '%$term_token%'
               OR s.synonyms LIKE '%$term_token%' 
               OR x.key LIKE '%$term_token%'
              )";
    }
    $term_token = strtok(' ');
  }//while
  
  return $sql . " 
    GROUP BY idn.id, idn.curation_lvl, d.description
    ORDER BY d.description='$term' DESC, 
       LOWER(d.description) LIKE '$term%' DESC, d.description 
    $query_limit"; 
}//build_stock_query_with_case()
  

function countGRINrecs($term, $DBConn) {
  $term = strtolower($term);
  $term_tok = strtok($term, " ");
  $term_tok = str_replace('%', '', $term_tok);

  $sql = "
    SELECT COUNT(*) FROM stock_grin
    WHERE (LOWER(search_id) LIKE '%$term_tok%' OR LOWER(ac_p) LIKE '%$term_tok%')";
          
  while ($term_tok = strtok(" ")) {
    $term_tok = str_replace('%', '', $term_tok);
    $sql .= " 
          AND (LOWER(search_id) LIKE '%$term_tok%' OR LOWER(ac_P) LIKE '%$term_tok%')";
  }//each search term token
  
  $sth = make_query($DBConn, $sql);
  if ($row=retrieve_row($sth)) {
    return $row['count'];
  }
  
  return 0;
}//countGRINrecs
  
  
