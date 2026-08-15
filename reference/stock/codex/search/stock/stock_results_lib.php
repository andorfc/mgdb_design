<?php
/* file: stock_results_lib.php
 *
 * purpose: parameterized functions for stock searching.
 */

function downloadResults($DBConn, $term, $job_id, $filename) {
  if (!$job_id) {
    reportError('Download requested with no job id.');
    return;
  }
  if (!$filename) {
    reportError('Download did not specify which query, via filename');
    return;
  }
  doSearch($DBConn, $term, 0, 'download', $job_id);
}//downloadResults


function doSearch($DBConn, $term, $search_limit, $action='display', $job_id='') {
  $case_sensitive = trim(getCGIParam('case', 'GP', '')) !== '';
  $raw_term = urldecode(trim(getCGIParam('term', 'GP',
                         getCGIParam('stock_term', 'GP',
                         getCGIParam('stock_term', 'S', '')))));
  $tokens = stockSearchTokens($raw_term);
  if (!$tokens) return ($action === 'count') ? 0 : array();

  list($predicate, $params) = stockSearchPredicate($tokens, $case_sensitive);
  $exact = $case_sensitive ? trim($raw_term) : strtolower(trim($raw_term));
  $name_expression = $case_sensitive ? 'd.description' : 'LOWER(d.description)';
  $params[':stock_rank_exact'] = $exact;
  $params[':stock_rank_prefix'] = $exact . '%';

  $filtered_sql = "
    SELECT DISTINCT idn.id, idn.curation_lvl, d.description AS name,
           ($name_expression = :stock_rank_exact) AS exact_rank,
           ($name_expression LIKE :stock_rank_prefix) AS prefix_rank,
           LOWER(d.description) AS sort_name
    FROM mgdb.id_num idn
      INNER JOIN mgdb.description d ON d.id=idn.id
      LEFT JOIN mgdb.synonyms sy ON sy.id=idn.id
      LEFT JOIN mgdb.ext_db_key x ON x.id=idn.id
    WHERE idn.type_term=26
      AND idn.curation_lvl IN (0, 101, 102)
      AND $predicate";

  if ($action === 'count') {
    $stmt = make_query($DBConn, "SELECT COUNT(*) FROM ($filtered_sql) stock_matches", 1, $params);
    $row = retrieve_row($stmt);
    return $row ? (int)$row['count'] : 0;
  }

  $limit_sql = ($action === 'download' || (int)$search_limit <= 0)
             ? '' : 'LIMIT ' . (int)$search_limit;
  $sql = "
    WITH filtered AS MATERIALIZED (
      $filtered_sql
    ), ranked AS (
      SELECT f.*, COUNT(*) OVER () AS total_count
      FROM filtered f
    )
    SELECT r.id, r.curation_lvl, r.exact_rank AS exact, r.name,
           r.exact_rank AS dumm1, r.prefix_rank AS dumm2,
           (SELECT ARRAY_AGG(DISTINCT s.synonyms)
            FROM mgdb.synonyms s WHERE s.id=r.id) AS synonyms,
           (SELECT ARRAY_AGG(DISTINCT t.name || '|' || m.memo)
            FROM mgdb.memo m
              LEFT JOIN mgdb.term t ON t.id=m.type_term
            WHERE m.id=r.id) AS comments,
           r.total_count
    FROM ranked r
    ORDER BY r.exact_rank DESC, r.prefix_rank DESC, LOWER(r.name), r.id
    $limit_sql";

  if ($action === 'download') {
    processDownloadRequest(stockDownloadSql($DBConn, $sql, $params), $job_id);
    return array();
  }
  if ($action !== 'display') {
    reportError("Unknown stock search action: '$action'");
    return false;
  }

  $stmt = make_query($DBConn, $sql, 1, $params);
  return get_all_rows($stmt);
}//doSearch


function stockSearchTokens($raw_term) {
  $parts = preg_split('/\s+/', trim((string)$raw_term));
  $tokens = array();
  foreach ($parts as $part) {
    if (strlen($part) >= 2 && substr($part, 0, 1) === '(' && substr($part, -1) === ')') {
      $part = substr($part, 1, -1);
    }
    $part = trim($part);
    if ($part !== '' && $part !== '%' && $part !== '*') $tokens[] = $part;
  }
  return $tokens;
}//stockSearchTokens


function stockSearchPredicate($tokens, $case_sensitive) {
  $clauses = array();
  $params = array();
  foreach (array_values($tokens) as $index => $token) {
    $value = $case_sensitive ? $token : strtolower($token);
    $description = $case_sensitive ? 'd.description' : 'LOWER(d.description)';
    $synonym = $case_sensitive ? 'sy.synonyms' : 'LOWER(sy.synonyms)';
    $external = $case_sensitive ? 'x.key' : 'LOWER(x.key)';
    $description_param = ':stock_description_' . $index;
    $synonym_param = ':stock_synonym_' . $index;
    $external_param = ':stock_external_' . $index;
    $clauses[] = "(
      $description LIKE $description_param
      OR $synonym LIKE $synonym_param
      OR $external LIKE $external_param
    )";
    foreach (array($description_param, $synonym_param, $external_param) as $param) {
      $params[$param] = '%' . $value . '%';
    }
  }
  return array(implode(' AND ', $clauses), $params);
}//stockSearchPredicate


function stockDownloadSql($DBConn, $sql, $params) {
  uksort($params, function($a, $b) { return strlen($b) - strlen($a); });
  foreach ($params as $placeholder => $value) {
    $sql = str_replace($placeholder, $DBConn->quote($value), $sql);
  }
  return $sql;
}//stockDownloadSql


function countGRINrecs($term, $DBConn) {
  $tokens = stockSearchTokens(str_replace('%', '', strtolower((string)$term)));
  if (!$tokens) return 0;
  $clauses = array();
  $params = array();
  foreach ($tokens as $index => $token) {
    $id_param = ':grin_id_' . $index;
    $accession_param = ':grin_accession_' . $index;
    $clauses[] = "(LOWER(search_id) LIKE $id_param OR LOWER(ac_p) LIKE $accession_param)";
    $params[$id_param] = '%' . $token . '%';
    $params[$accession_param] = '%' . $token . '%';
  }
  $stmt = make_query($DBConn,
    'SELECT COUNT(*) FROM mgdb.stock_grin WHERE ' . implode(' AND ', $clauses),
    1,
    $params
  );
  $row = retrieve_row($stmt);
  return $row ? (int)$row['count'] : 0;
}//countGRINrecs
