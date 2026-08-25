<?PHP
/**
 * file: persondisplayresults.php
 * purpose: Modern table-based search results for Person / Organization search (/person)
 */
  require_once("../../../include/gp_lib.php");
  require_once("../../../include/db-api.php");

  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: no-cache, no-store, must-revalidate');

  $DBConn = connect_to_database();

  $term = trim(getCGIParam('term', 'GP', ''));
  $letter = strtoupper(trim(getCGIParam('letter', 'GP', '')));

  // Validation
  if (empty($term) && empty($letter)) {
    echo '<div class="person-empty-state">';
    echo '  <h3>Enter a name or keyword</h3>';
    echo '  <p>Search for a researcher by first/last name, known aliases, or institution name above.</p>';
    echo '</div>';
    exit;
  }

  if (!empty($term) && strlen($term) < 2) {
    echo '<div class="person-empty-state">';
    echo '  <h3>Search query too short</h3>';
    echo '  <p>Please enter at least <strong>2 characters</strong> (e.g. <em>Li</em>, <em>Wu</em>, <em>Yu</em>, <em>Buckler</em>, <em>Walbot</em>).</p>';
    echo '</div>';
    exit;
  }

  $lower = strtolower($term);
  $contains = '%' . $lower . '%';
  $prefix = $lower . '%';

  if (!empty($letter)) {
    // Single letter A-Z browse
    $letter_clean = substr($letter, 0, 1);
    $query = "
      SELECT P.ID, P.NAME, P.NAME_FIRST, P.NAME_LAST, P.TYPE, ORG.NAME AS INSTITUTION, ORG.ID AS ORG_ID,
             P.CITY, P.STATE, P.COUNTRY, P.ORCID
      FROM PERSON P
      JOIN ID_NUM I ON P.ID = I.ID AND I.CURATION_LVL = 0
      LEFT JOIN PERSON ORG ON P.INSTITUTION = ORG.ID
      WHERE UPPER(P.NAME) LIKE ? OR UPPER(P.NAME_LAST) LIKE ?
      ORDER BY UPPER(P.NAME)
      LIMIT 100";
    $stmt = make_query($DBConn, $query, 1, array($letter_clean . '%', $letter_clean . '%'));
    $search_label = "Last name beginning with '$letter_clean'";
  } else {
    // Text search query
    $query = "
      SELECT P.ID, P.NAME, P.NAME_FIRST, P.NAME_LAST, P.TYPE, ORG.NAME AS INSTITUTION, ORG.ID AS ORG_ID,
             P.CITY, P.STATE, P.COUNTRY, P.ORCID, S.SYNONYMS
      FROM PERSON P
      JOIN ID_NUM I ON P.ID = I.ID AND I.CURATION_LVL = 0
      LEFT JOIN PERSON ORG ON P.INSTITUTION = ORG.ID
      LEFT JOIN SYNONYMS S ON P.ID = S.ID
      WHERE LOWER(COALESCE(P.NAME, '')) LIKE ?
         OR LOWER(COALESCE(P.NAME_FIRST, '')) LIKE ?
         OR LOWER(COALESCE(P.NAME_LAST, '')) LIKE ?
         OR LOWER(COALESCE(ORG.NAME, '')) LIKE ?
         OR LOWER(COALESCE(S.SYNONYMS, '')) LIKE ?
      ORDER BY CASE
        WHEN LOWER(P.NAME) = ? THEN 0
        WHEN LOWER(COALESCE(P.NAME_LAST, '')) = ? THEN 1
        WHEN LOWER(P.NAME) LIKE ? THEN 2
        WHEN LOWER(COALESCE(P.NAME_LAST, '')) LIKE ? THEN 3
        WHEN LOWER(COALESCE(P.NAME_FIRST, '')) LIKE ? THEN 4
        WHEN LOWER(COALESCE(ORG.NAME, '')) LIKE ? THEN 5
        ELSE 6 END,
        LOWER(P.NAME)
      LIMIT 75";
    $params = array($contains, $prefix, $prefix, $contains, $contains, $lower, $lower, $prefix, $prefix, $prefix, $prefix);
    $stmt = make_query($DBConn, $query, 1, $params);
    $search_label = "term '" . htmlspecialchars($term) . "'";
  }

  $rows = array();
  $seen_ids = array();
  while ($row = retrieve_row($stmt)) {
    if (isset($seen_ids[$row['id']])) continue;
    $seen_ids[$row['id']] = true;
    $rows[] = $row;
  }

  $count = count($rows);

  if ($count === 0) {
    echo '<div class="person-empty-state">';
    echo '  <h3>No matching records found</h3>';
    echo '  <p>No community records matched <strong>' . htmlspecialchars(!empty($letter) ? "Letter $letter" : $term) . '</strong>.</p>';
    echo '  <ul class="person-tips-list">';
    echo '    <li>Check the spelling of the name or institution.</li>';
    echo '    <li>Try searching by last name only or partial surname (e.g. <em>Walbot</em>, <em>Buckler</em>, <em>Li</em>).</li>';
    echo '    <li>Use the A–Z alphabetical directory buttons above to browse.</li>';
    echo '  </ul>';
    echo '</div>';
    exit;
  }

  // Render Table
  echo '<div class="person-table-results-container">';
  echo '  <div class="person-results-status-bar">';
  echo '    <span class="person-results-badge">Found <strong>' . $count . '</strong> matching record' . ($count === 1 ? '' : 's') . ' for ' . $search_label . '</span>';
  echo '    <span class="person-results-hint">Click a name or button to view full researcher profile and publications</span>';
  echo '  </div>';

  echo '  <div class="mgdb-table-scroll person-table-scroll">';
  echo '    <table class="mgdb-table person-table">';
  echo '      <thead>';
  echo '        <tr>';
  echo '          <th scope="col" style="min-width: 200px;">Name / Profile</th>';
  echo '          <th scope="col" style="width: 140px;">Type</th>';
  echo '          <th scope="col" style="min-width: 220px;">Institution / Affiliation</th>';
  echo '          <th scope="col" style="min-width: 180px;">Location</th>';
  echo '          <th scope="col" class="text-right" style="width: 130px;">Action</th>';
  echo '        </tr>';
  echo '      </thead>';
  echo '      <tbody>';

  foreach ($rows as $r) {
    $id = htmlspecialchars($r['id']);
    $name = htmlspecialchars(trim($r['name']));
    $first = trim((string)$r['name_first']);
    $last = trim((string)$r['name_last']);
    $full = htmlspecialchars(trim("$first $last"));
    if (strcasecmp($full, $name) === 0) $full = '';

    $is_org = ($r['type'] != 20 && !empty($r['type']));
    $type_badge = $is_org 
      ? '<span class="person-type-badge person-badge-org">Organization</span>'
      : '<span class="person-type-badge person-badge-researcher">Researcher / Author</span>';

    $inst_name = htmlspecialchars(trim((string)$r['institution']));
    $inst_id = !empty($r['org_id']) ? htmlspecialchars($r['org_id']) : '';
    $inst_html = $inst_name;
    if ($inst_id && $inst_name) {
      $inst_html = '<a href="/person?id=' . $inst_id . '" class="person-org-link">' . $inst_name . '</a>';
    } else if (empty($inst_name)) {
      $inst_html = '<span class="person-na-text">—</span>';
    }

    $place_parts = array_filter(array(trim((string)$r['city']), trim((string)$r['state']), trim((string)$r['country'])));
    $place = implode(', ', array_unique($place_parts));
    $place_html = $place ? htmlspecialchars($place) : '<span class="person-na-text">—</span>';

    $alias = !empty($r['synonyms']) ? htmlspecialchars(trim($r['synonyms'])) : '';

    echo '        <tr>';
    echo '          <td>';
    echo '            <a href="/person?id=' . $id . '" class="person-name-link"><strong>' . $name . '</strong></a>';
    if ($full) {
      echo '            <span class="person-subname">' . $full . '</span>';
    }
    if ($alias && stripos($name, $term) === false && stripos($full, $term) === false) {
      echo '            <span class="person-alias-note">Alias match: <em>' . $alias . '</em></span>';
    }
    echo '          </td>';
    echo '          <td>' . $type_badge . '</td>';
    echo '          <td>' . $inst_html . '</td>';
    echo '          <td>' . $place_html . '</td>';
    echo '          <td class="text-right">';
    echo '            <a href="/person?id=' . $id . '" class="person-view-btn">View Profile &rarr;</a>';
    echo '          </td>';
    echo '        </tr>';
  }

  echo '      </tbody>';
  echo '    </table>';
  echo '  </div>';
  echo '</div>';
?>
