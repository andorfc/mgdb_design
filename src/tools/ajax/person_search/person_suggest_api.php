<?PHP
  require("../../../include/db-api.php");
  include_once('../../../include/gp_lib.php');

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
  $query = "
    SELECT P.ID, P.NAME, P.NAME_FIRST, P.NAME_LAST, ORG.NAME AS INSTITUTION,
           P.CITY, P.STATE, P.COUNTRY
    FROM PERSON P
    JOIN ID_NUM I ON P.ID = I.ID AND I.CURATION_LVL = 0
    LEFT JOIN PERSON ORG ON P.INSTITUTION = ORG.ID
    WHERE LOWER(COALESCE(P.NAME, '')) LIKE ?
       OR LOWER(COALESCE(P.NAME_FIRST, '')) LIKE ?
       OR LOWER(COALESCE(P.NAME_LAST, '')) LIKE ?
       OR LOWER(COALESCE(ORG.NAME, '')) LIKE ?
    ORDER BY CASE
      WHEN LOWER(P.NAME) = ? THEN 0
      WHEN LOWER(P.NAME) LIKE ? THEN 1
      WHEN LOWER(COALESCE(P.NAME_LAST, '')) LIKE ? THEN 2
      WHEN LOWER(COALESCE(ORG.NAME, '')) LIKE ? THEN 3
      ELSE 4 END,
      LOWER(P.NAME)
    LIMIT 8";
  $params = array($contains, $prefix, $prefix, $contains, $lower, $prefix, $prefix, $prefix);
  $stmt = make_query($DBConn, $query, 1, $params);
  $results = array();
  while ($row = retrieve_row($stmt)) {
    $name = trim($row['name']);
    $full = trim($row['name_first'] . ' ' . $row['name_last']);
    if (strcasecmp($full, $name) === 0) $full = '';
    $place = array_filter(array(trim((string)$row['city']), trim((string)$row['state']), trim((string)$row['country'])));
    $results[] = array(
      'id' => $row['id'],
      'name' => $name,
      'full_name' => $full,
      'institution' => trim((string)$row['institution']),
      'location' => implode(', ', array_unique($place)),
      'initial' => strtoupper(substr($name, 0, 1))
    );
  }
  echo json_encode(array('results' => $results), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
