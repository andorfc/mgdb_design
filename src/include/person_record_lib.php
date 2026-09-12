<?php
/* file: person_record_lib.php
 *
 * purpose: queries and server-side HTML builders for the modern person record
 *          page (/person?id={id}). All queries are parameterised; all values
 *          are escaped or run through mgdb_safe_html() before they reach the
 *          page.
 *
 * The legacy page fetched its three sections over Ajax from
 * record_data/person_data.php and, for the publication list, ran one query per
 * paper (N+1). This library renders server-side and reads the publications in a
 * single joined query.
 */

if (!function_exists('personRecEsc')) {
  function personRecEsc($text) {
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
  }
}

/* Resolve a requested identifier to a curated person id, or false. Person ids
   are numeric MaizeGDB record ids; the special list ids (cooperators, breeders,
   maizegdb) are deliberately not handled here so they fall through to the
   legacy list pages. */
function personResolveId($DBConn, $identifier) {
  $identifier = trim((string) $identifier);
  if ($identifier === '' || !ctype_digit($identifier)) {
    return false;
  }
  $sql = "SELECT p.id
          FROM person p
          JOIN id_num i ON p.id = i.id AND i.curation_lvl = 0
          WHERE p.id = ?";
  $row = retrieve_row(make_query($DBConn, $sql, 1, array((int) $identifier)));
  return $row ? (int) $row['id'] : false;
}

/* Identity: the display name, record type, and the row the other builders need. */
function personIdentity($DBConn, $id) {
  $sql = "SELECT p.id, p.name, p.name_first, p.name_last, p.suffix,
                 t.name AS record_type,
                 p.institution, org.name AS institution_name,
                 p.address, p.city, p.state, p.country, p.postal_code, p.orcid
          FROM person p
          LEFT JOIN term t   ON t.id = p.type
          LEFT JOIN person org ON org.id = p.institution
          WHERE p.id = ?";
  $row = retrieve_row(make_query($DBConn, $sql, 1, array((int) $id)));
  if (!$row) {
    return false;
  }

  $first = trim((string) $row['name_first']);
  $last  = trim((string) $row['name_last']);
  if ($first !== '' && $last !== '') {
    $display = $first . ' ' . $last;
    if (trim((string) $row['suffix']) !== '') {
      $display .= ', ' . trim((string) $row['suffix']);
    }
  } else {
    $display = (string) $row['name'];
  }
  $row['display_name'] = $display !== '' ? $display : ('Person ' . $id);
  return $row;
}

/* The synonym line under the name. Skips whatever already appears as the record
   name or the displayed name, and de-duplicates case-insensitively -- the
   synonyms table often carries both "Walbot, Virginia" and "Virginia Walbot".
   Allele-style markup is allowed, so each survivor is sanitised, not escaped. */
function personSynonymsHtml($DBConn, $id, $skip_name, $display_name = '') {
  $rows = get_all_rows(make_query($DBConn,
    "SELECT synonyms FROM synonyms WHERE id = ? ORDER BY synonyms", 1, array((int) $id)));
  if (!$rows) {
    return '';
  }
  $skip = array();
  foreach (array($skip_name, $display_name) as $n) {
    $n = strtolower(trim((string) $n));
    if ($n !== '') { $skip[$n] = true; }
  }
  $seen = array();
  $out = array();
  foreach ($rows as $r) {
    $syn = trim((string) $r['synonyms']);
    if ($syn === '') { continue; }
    $key = strtolower(preg_replace('/\s+/', ' ', $syn));
    if (isset($skip[$key]) || isset($seen[$key])) { continue; }
    $seen[$key] = true;
    $out[] = mgdb_safe_html($syn);
  }
  return implode('<br>', $out);
}

/* Overview facts: the definition list in the hero. */
function personIdentityFacts($identity) {
  $facts = array();
  $facts[] = array('Type', personRecEsc($identity['record_type'] !== '' ? $identity['record_type'] : 'Person'));

  $inst = trim((string) $identity['institution_name']);
  if ($inst !== '') {
    $facts[] = array('Affiliation', personRecEsc($inst));
  }

  $loc = array();
  foreach (array('city', 'state', 'country') as $k) {
    $v = trim((string) $identity[$k]);
    if ($v !== '') { $loc[] = $v; }
  }
  if ($loc) {
    $facts[] = array('Location', personRecEsc(implode(', ', $loc)));
  }

  $orcid = trim((string) $identity['orcid']);
  if ($orcid !== '') {
    $facts[] = array('ORCID iD',
      '<a href="https://orcid.org/' . personRecEsc($orcid) . '" target="_blank" rel="noopener">'
      . personRecEsc($orcid) . ' <span aria-hidden="true">&nearr;</span></a>');
  }

  $facts[] = array('MaizeGDB ID', '<span class="mgdb-record-id">' . personRecEsc($identity['id']) . '</span>');

  $html = '';
  foreach ($facts as $f) {
    $html .= '<div><dt>' . $f[0] . '</dt><dd>' . $f[1] . '</dd></div>';
  }
  return $html;
}

/* Roles and recognitions: every person_attribute term, with its years folded
   into ranges. One list, most-recent-looking first is not meaningful here, so
   it is alphabetical by role -- the same order the legacy page used. */
function personRolesHtml($DBConn, $id) {
  $sql = "SELECT t.name AS role, pa.value AS year
          FROM person_attribute pa
          JOIN term t ON t.id = pa.attribute
          WHERE pa.id = ?
          ORDER BY t.name, pa.value";
  $rows = get_all_rows(make_query($DBConn, $sql, 1, array((int) $id)));
  if (!$rows) {
    return '';
  }

  $byRole = array();
  foreach ($rows as $r) {
    $role = (string) $r['role'];
    if (!isset($byRole[$role])) { $byRole[$role] = array(); }
    $y = trim((string) $r['year']);
    if ($y !== '' && ctype_digit($y)) { $byRole[$role][] = (int) $y; }
  }

  $items = '';
  foreach ($byRole as $role => $years) {
    $years = array_values(array_unique($years));
    sort($years);
    $yearHtml = $years ? ' <span class="person-rec-years">' . personRecEsc(personRecYearRanges($years)) . '</span>' : '';
    $items .= '<li>' . personRecEsc($role) . $yearHtml . '</li>';
  }
  return '<ul class="person-rec-roles">' . $items . '</ul>';
}

/* [2000,2001,2002,2007,2008] -> "2000-2002, 2007-2008". */
function personRecYearRanges($years) {
  if (!$years) { return ''; }
  $out = array();
  $start = $prev = $years[0];
  for ($i = 1; $i < count($years); $i++) {
    if ($years[$i] === $prev + 1) {
      $prev = $years[$i];
      continue;
    }
    $out[] = ($start === $prev) ? (string) $start : ($start . '–' . $prev);
    $start = $prev = $years[$i];
  }
  $out[] = ($start === $prev) ? (string) $start : ($start . '-' . $prev);
  return implode(', ', $out);
}

/* Maize Community Support badges.
 *
 * The same rule the legacy record page used: a fixed set of attribute terms
 * count as badges, each mapped to an image at /icon/badges/badge_<ACRONYM>.png.
 * The acronym is the capital letters of a multi-word name concatenated
 * (Maize Genetics Executive Committee -> MGEC), or the first two characters of
 * a one-word name (Cooperator -> Co). Awards sort first. Editorial-board service
 * adds the MEB badge. A badge whose image file is missing is skipped rather than
 * shown broken. Returns an array of array('acronym','name').
 */
function personBadgeTerms() {
  return array(
    'Cooperator', 'Data Provider',
    'Maize Genetics Conference Chair', 'Maize Genetics Conference Ex-officio member',
    'Maize Genetics Conference Local Host', 'Maize Genetics Conference Plenary Speaker',
    'Maize Genetics Conference Steering Committee',
    'Maize Genetics Executive Committee', 'Maize Genetics Executive Committee Chair',
    'Maize Nomenclature Committee', 'Maize Nomenclature Committee Chair',
    'MaizeGDB Alumni', 'MaizeGDB Beta Tester', 'MaizeGDB staff member',
    'MaizeGDB Working Group', 'MaizeGDB Working Group Chair',
    'National Academy of Science Member',
    'M. Rhoades Early-Career Award', 'L. Stadler Mid-Career Award',
    'R. Emerson Lifetime Award', 'The McClintock Prize for Plant Genetics and Genome Studies',
  );
}

function personBadgeAcronym($name) {
  $words = preg_split('/\s+/', trim((string) $name));
  if (count($words) > 1) {
    $ac = '';
    foreach ($words as $w) {
      if (preg_match_all('/([A-Z]+)/', $w, $m)) {
        $ac .= implode('', $m[0]);
      }
    }
    return $ac;
  }
  return substr($words[0], 0, 2);
}

function personBadges($DBConn, $doc_root, $id) {
  $terms = personBadgeTerms();
  $placeholders = implode(',', array_fill(0, count($terms), '?'));
  $awards = array(
    'M. Rhoades Early-Career Award' => 1,
    'L. Stadler Mid-Career Award'   => 2,
    'R. Emerson Lifetime Award'     => 3,
    'The McClintock Prize for Plant Genetics and Genome Studies' => 4,
  );

  $sql = "SELECT DISTINCT t.name
          FROM term t
          JOIN person_attribute pa ON pa.attribute = t.id
          WHERE pa.id = ? AND t.name IN ($placeholders)";
  $params = array_merge(array((int) $id), $terms);
  $rows = get_all_rows(make_query($DBConn, $sql, 1, $params));

  $names = array();
  foreach ($rows as $r) { $names[] = (string) $r['name']; }

  // Editorial Board service is its own badge (MEB), image badge_MEB.png.
  $ed = retrieve_row(make_query($DBConn,
    "SELECT count(*) AS n FROM ed_board WHERE person_id = ?", 1, array((int) $id)));
  $has_ed = $ed && (int) $ed['n'] > 0;

  // Awards first (in award order), then the rest alphabetically.
  usort($names, function ($a, $b) use ($awards) {
    $pa = isset($awards[$a]) ? $awards[$a] : 99;
    $pb = isset($awards[$b]) ? $awards[$b] : 99;
    if ($pa !== $pb) { return $pa - $pb; }
    return strcasecmp($a, $b);
  });

  $badges = array();
  foreach ($names as $name) {
    $acronym = personBadgeAcronym($name);
    if ($acronym === '') { continue; }
    if (!is_file($doc_root . '/icon/badges/badge_' . $acronym . '.png')) { continue; }
    $badges[] = array('acronym' => $acronym, 'name' => $name);
  }
  if ($has_ed && is_file($doc_root . '/icon/badges/badge_MEB.png')) {
    $badges[] = array('acronym' => 'MEB', 'name' => 'MaizeGDB Editorial Board');
  }

  return $badges;
}

function personBadgesHtml($badges) {
  if (!$badges) { return ''; }
  $html = '<div class="person-badge-grid">';
  foreach ($badges as $b) {
    $src  = '/icon/badges/badge_' . rawurlencode($b['acronym']) . '.png';
    $name = personRecEsc($b['name']);
    $html .= '<figure class="person-badge">'
           . '<img src="' . personRecEsc($src) . '" alt="' . $name . '" width="110" height="110" loading="lazy">'
           . '<figcaption>' . $name . '</figcaption>'
           . '</figure>';
  }
  $html .= '</div>';
  return $html;
}

/* Contact: affiliation, postal address, e-mail(s), and web link(s). */
function personContactHtml($DBConn, $id, $identity) {
  $blocks = array();

  $addressLines = array();
  $inst = trim((string) $identity['institution_name']);
  if ($inst !== '') { $addressLines[] = personRecEsc($inst); }
  $addr = trim((string) $identity['address']);
  if ($addr !== '') {
    foreach (preg_split('/[\r\n]+/', $addr) as $line) {
      $line = trim($line);
      if ($line !== '') { $addressLines[] = personRecEsc($line); }
    }
  }
  $cityline = array();
  foreach (array('city', 'state', 'postal_code') as $k) {
    $v = trim((string) $identity[$k]);
    if ($v !== '') { $cityline[] = $v; }
  }
  $tail = trim(implode(', ', array_slice($cityline, 0, 2)));
  if (isset($cityline[2])) { $tail = trim($tail . ' ' . $cityline[2]); }
  if ($tail !== '') { $addressLines[] = personRecEsc($tail); }
  $country = trim((string) $identity['country']);
  if ($country !== '') { $addressLines[] = personRecEsc($country); }

  if ($addressLines) {
    $blocks[] = '<div class="person-contact-block"><h3>Address</h3><address class="person-address">'
              . implode('<br>', $addressLines) . '</address></div>';
  }

  $emails = get_all_rows(make_query($DBConn,
    "SELECT email_address FROM person_email WHERE id = ? ORDER BY primary_email", 1, array((int) $id)));
  if ($emails) {
    $links = array();
    foreach ($emails as $e) {
      $addr = trim((string) $e['email_address']);
      if ($addr === '') { continue; }
      $links[] = '<a href="mailto:' . personRecEsc($addr) . '">' . personRecEsc($addr) . '</a>';
    }
    if ($links) {
      $blocks[] = '<div class="person-contact-block"><h3>Email</h3><p>' . implode('<br>', $links) . '</p></div>';
    }
  }

  $urls = get_all_rows(make_query($DBConn,
    "SELECT url FROM web_data WHERE id = ?", 1, array((int) $id)));
  if ($urls) {
    $links = array();
    foreach ($urls as $u) {
      $url = trim((string) $u['url']);
      if ($url === '') { continue; }
      $href = (preg_match('#^https?://#i', $url)) ? $url : ('http://' . $url);
      $links[] = '<a href="' . personRecEsc($href) . '" target="_blank" rel="noopener">' . personRecEsc($url) . '</a>';
    }
    if ($links) {
      $blocks[] = '<div class="person-contact-block"><h3>Web</h3><p>' . implode('<br>', $links) . '</p></div>';
    }
  }

  return $blocks ? '<div class="person-contact-grid">' . implode('', $blocks) . '</div>' : '';
}

/* Publications: every curated reference this person authored, one joined query,
   newest first. Returns array(count, html). */
function personPublications($DBConn, $id) {
  $sql = "SELECT r.id, r.name, r.title, r.year
          FROM reference_authors ra
          JOIN reference r ON ra.id = r.id
          JOIN id_num i ON r.id = i.id AND i.curation_lvl = 0
          WHERE ra.author = ?
          ORDER BY r.year DESC NULLS LAST, r.name";
  $rows = get_all_rows(make_query($DBConn, $sql, 1, array((int) $id)));
  $count = $rows ? count($rows) : 0;
  if ($count === 0) {
    return array(0, '');
  }

  $body = '';
  foreach ($rows as $r) {
    $label = trim((string) $r['title']);
    if ($label === '') { $label = trim((string) $r['name']); }
    if ($label === '') { $label = 'Reference ' . $r['id']; }
    $year = trim((string) $r['year']);
    $yearHtml = $year !== '' ? '<span class="person-pub-year">' . personRecEsc($year) . '</span>' : '';
    $body .= '<li>' . $yearHtml
           . '<a href="/data_center/reference?id=' . personRecEsc($r['id']) . '">' . personRecEsc($label) . '</a>'
           . '</li>';
  }
  return array($count, '<ol class="person-pub-list">' . $body . '</ol>');
}

/* Projects the person is an investigator on. Returns array(count, html). */
function personProjects($DBConn, $id) {
  $sql = "SELECT pp.id, pp.name
          FROM pc_assoc_investigator pai
          JOIN pc_project pp ON pp.id = pai.id
          JOIN id_num i ON i.id = pai.id AND i.curation_lvl = 0
          WHERE pai.person_id = ?
          ORDER BY pp.name";
  $rows = get_all_rows(make_query($DBConn, $sql, 1, array((int) $id)));
  $count = $rows ? count($rows) : 0;
  if ($count === 0) {
    return array(0, '');
  }

  // Every legacy project link resolved to the person-scoped project search;
  // keep that target so the list still leads somewhere.
  $href = '/popcorn/search/project_search/project_search.php?record=' . (int) $id;
  $body = '';
  foreach ($rows as $r) {
    $name = trim((string) $r['name']);
    if ($name === '') { $name = 'Project ' . $r['id']; }
    $body .= '<li><a href="' . personRecEsc($href) . '">' . personRecEsc($name) . '</a></li>';
  }
  return array($count, '<ul class="person-project-list">' . $body . '</ul>');
}
?>
