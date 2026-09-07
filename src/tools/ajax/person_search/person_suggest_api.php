<?PHP
/**
 * file: person_suggest_api.php
 * purpose: Fast JSON suggestions for person / organization autocomplete
 */
  require_once("../../../include/gp_lib.php");
  require_once("../../../include/db-api.php");
  require_once("../../../include/person_search_lib.php");

  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: private, max-age=30');

  $term = preg_replace('/\s+/', ' ', trim(getCGIParam('term', 'GP', '')));
  if (strlen($term) < 2) {
    echo json_encode(array('results' => array()));
    exit;
  }

  $DBConn = connect_to_database();
  $lower = strtolower($term);
  $contains = '%' . $lower . '%';
  $prefix = $lower . '%';

  /* Shared with persondisplayresults.php via include/person_search_lib.php, so
     the suggestions under the box and the results the box produces cannot mean
     different things by the same query. See the note there about why
     "Ed Buckler" found nothing. */
  $clauses = mgdbPersonSearchClauses($term, array('synonyms' => false));
  $query = "
    SELECT P.ID, P.NAME, P.NAME_FIRST, P.NAME_LAST, P.TYPE, ORG.NAME AS INSTITUTION,
           P.CITY, P.STATE, P.COUNTRY
    FROM PERSON P
    JOIN ID_NUM I ON P.ID = I.ID AND I.CURATION_LVL = 0
    LEFT JOIN PERSON ORG ON P.INSTITUTION = ORG.ID
    WHERE " . $clauses['where'] . "
    ORDER BY " . $clauses['order'] . "
    LIMIT 10";

  $stmt = make_query($DBConn, $query, 1, $clauses['params']);
  $results = array();

  while ($row = retrieve_row($stmt)) {
    $name = trim($row['name']);
    $full = trim($row['name_first'] . ' ' . $row['name_last']);
    if (strcasecmp($full, $name) === 0) $full = '';
    $place = array_filter(array(trim((string)$row['city']), trim((string)$row['state']), trim((string)$row['country'])));
    
    // Type classification
    $is_org = ($row['type'] != 20 && !empty($row['type']));

    $results[] = array(
      'id' => $row['id'],
      'name' => $name,
      'full_name' => $full,
      'is_org' => $is_org,
      'institution' => trim((string)$row['institution']),
      'location' => implode(', ', array_unique($place)),
      'initial' => strtoupper(substr($name, 0, 1))
    );
  }

  echo json_encode(array('results' => $results), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
