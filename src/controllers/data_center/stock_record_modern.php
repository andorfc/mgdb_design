<?PHP
/* file: stock_record_modern.php
 *
 * purpose: Stock record page (/data_center/stock/{id}) on the modern design
 *          system.
 *
 *          Included by controllers/data_center.php when PAGE is 'stock' and a
 *          record id is present. Returns false without publishing if the
 *          identifier does not resolve, so the caller falls through to the
 *          original code and its 404 handling.
 *
 *          The page renders its own identity — name, type, status — because
 *          the document title, the social preview, and a crawler all need it
 *          before any script runs. Everything else arrives in a single call to
 *          /api/v1/records/stock/{id}, made by js/mgdb-stock-record.js. The
 *          page it replaces made six.
 *
 *          Pre-redesign files are archived in the redesign repository under
 *          legacy/stock-record/.
 */

  include_once('./include/db-api.php');
  include_once('./include/stock_record_lib.php');

  $system = getSystemInfo('mgdb.conf');
  $DBConn = connect_to_database(false);

  /* controller.php splits REQUEST_URI on '/' without decoding, so an
     identifier with an escaped space — a GRIN accession such as PI%20595550 —
     arrives still encoded. Decoding happens here, at the boundary, exactly
     once; stockResolveId() takes a decoded value. */
  $stock_id = stockResolveId($DBConn, rawurldecode((string) getCGIParam('id', 'G', ID)));
  if ($stock_id === false) {
    return false;   // let the original controller answer
  }

  $identity = stockIdentity($DBConn, $stock_id);
  if (!$identity) {
    return false;
  }

  logMessage('Starting stock_record_modern.php for ' . $stock_id);

  $stock_name = $identity['name'] !== '' ? $identity['name'] : ('Stock ' . $stock_id);
  $descriptor = $identity['type'] !== '' ? $identity['type'] : 'genetic stock';

  $summary = $stock_name . ' is a maize ' . $descriptor;
  if ($identity['provider'] !== '') {
    $summary .= ' available from ' . $identity['provider'];
  }
  $summary .= '. Pedigree, variations, phenotypes, images, references, and ordering information.';

  $bauplan = new Bauplan('MaizeGDB Stock: ' . $stock_name);
  $bauplan->modern();

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-stock-record.css');
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-stock-record.js');
  $bauplan->head('<meta name="description" content="'
    . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $content = $mgdb->get('body')->loadRemote($system['root_url_private'] . '/templates/static/mgdb_stock_record.bau');

  $content->get('stock_id')->replace((int) $stock_id);
  $content->get('stock_name')->replace(htmlspecialchars($stock_name, ENT_QUOTES, 'UTF-8'));
  $content->get('stock_summary')->replace(htmlspecialchars($summary, ENT_QUOTES, 'UTF-8'));
  $content->get('stock_type')->replace($identity['type'] !== ''
    ? htmlspecialchars($identity['type'], ENT_QUOTES, 'UTF-8') : 'Genetic stock');

  // The status badge is server-rendered rather than left to the API call: a
  // withdrawn stock must say so in the first paint, not a moment later.
  $badges = array(
    'unavailable' => array('mgdb-pill-warn', 'No longer available'),
    'discontinued' => array('mgdb-pill-error', 'Discontinued')
  );
  $content->get('status_badge')->replace(isset($badges[$identity['status']])
    ? '<span class="mgdb-pill ' . $badges[$identity['status']][0] . '">'
      . $badges[$identity['status']][1] . '</span>'
    : '');

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
  return true;
?>
