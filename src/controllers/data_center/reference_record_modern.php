<?PHP
/* file: reference_record_modern.php
 *
 * purpose: Reference record page (/data_center/reference?id={id}) on the
 *          modern design system.
 *
 *          Included by controllers/data_center.php when PAGE is 'reference'
 *          and a record id is present. Returns false without publishing if the
 *          identifier does not resolve, so the caller falls through to the
 *          original code and its 404 handling.
 *
 *          The page renders its own identity — title, journal, year, and the
 *          Editorial Board badge — because the document title, the social
 *          preview, and a crawler all need it before any script runs.
 *          Everything else arrives in one call to
 *          /api/v1/records/reference/{id}, made by js/mgdb-reference-record.js.
 *          The page it replaces made five.
 *
 *          Pre-redesign files are archived in the redesign repository under
 *          legacy/reference-record/.
 */

  include_once('./include/db-api.php');
  include_once('./include/reference_record_lib.php');

  $system = getSystemInfo('mgdb.conf');
  $DBConn = connect_to_database(false);

  $reference_id = referenceResolveId($DBConn, rawurldecode((string) getCGIParam('id', 'G', ID)));
  if ($reference_id === false) {
    return false;   // let the original controller answer
  }

  $identity = referenceIdentity($DBConn, $reference_id);
  if (!$identity) {
    return false;
  }

  logMessage('Starting reference_record_modern.php for ' . $reference_id);

  $title = $identity['title'] !== '' ? $identity['title'] : $identity['citation'];
  if ($title === '') {
    $title = 'Reference ' . $reference_id;
  }

  // The browser tab shows the start of a title that can run to 200 characters.
  $short = (strlen($title) > 70) ? rtrim(substr($title, 0, 70)) . '…' : $title;

  $where = array();
  if ($identity['journal'] !== '') { $where[] = $identity['journal']; }
  if ($identity['year'] !== null)  { $where[] = (string) $identity['year']; }
  $summary = rtrim($title, '.') . '.'
           . (count($where) > 0 ? ' ' . implode(', ', $where) . '.' : '')
           . ' Authors, abstract, the maize records this paper describes, and citation formats.';

  $bauplan = new Bauplan('MaizeGDB Reference: ' . $short);
  $bauplan->modern();

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-reference-record.css');
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-reference-record.js');
  $bauplan->head('<meta name="description" content="'
    . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $content = $mgdb->get('body')->load('templates/static/mgdb_reference_record.bau');

  $content->get('reference_id')->replace((int) $reference_id);
  $content->get('reference_title')->replace(htmlspecialchars($title, ENT_QUOTES, 'UTF-8'));
  $content->get('reference_summary')->replace(htmlspecialchars($summary, ENT_QUOTES, 'UTF-8'));

  $kicker = array();
  if ($identity['type'] !== '')    { $kicker[] = $identity['type']; }
  if ($identity['journal'] !== '') { $kicker[] = $identity['journal']; }
  if ($identity['year'] !== null)  { $kicker[] = (string) $identity['year']; }
  $content->get('reference_kicker')->replace(htmlspecialchars(
    count($kicker) > 0 ? implode(' · ', $kicker) : 'Reference', ENT_QUOTES, 'UTF-8'));

  // The Editorial Board badge is server-rendered rather than waiting on the
  // API call: it is the strongest signal on the page about whether the paper
  // is worth a working geneticist's time, and it should not arrive late.
  $content->get('editorial_badge')->replace($identity['is_editorial_pick']
    ? '<a class="reference-record-badge" href="/hot_new_papers">'
      . '<span aria-hidden="true">&#9733;</span> Editorial Board pick</a>'
    : '');

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
  return true;
?>
