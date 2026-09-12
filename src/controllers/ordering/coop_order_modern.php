<?php
/* file: coop_order_modern.php
 *
 * purpose: /ordering/coop_order -- the Maize Genetics Cooperation Stock Center
 *          request form, on the design system.
 *
 * Loaded by controllers/ordering.php for every /ordering/coop_order *page*
 * render -- the form itself and the `completed` confirmation. Anything carrying
 * an `action` parameter is left to the legacy controllers/ordering/coop_order.php
 * exactly as it was: those are the AJAX endpoints the form posts back to
 * (add-stock, check-stock, get-list, remove-stock, get-comment, clear-order,
 * force-add-stock, submit). The basket store and the order email are entirely
 * that file's, and are not re-implemented here.
 *
 * So this controller renders a page and nothing else. It runs no SQL: the list
 * is fetched by the page script from the same get-list endpoint the legacy page
 * used, and validation of a typed stock name still goes through check-stock.
 *
 * Pre-add. A stock record's "Order this stock" link is
 * /ordering/coop_order/<urlencoded descriptive name>, and the legacy page added
 * that stock to the order before rendering. The router hands the segment through
 * as ID; urldecode() turns it back into the descriptive name (spaces and all),
 * exactly as the legacy addStock() did. That name is handed to the page in a
 * data attribute and the script adds it through the same add-stock endpoint on
 * load, so the end state is identical.
 *
 * Rollback: delete the guard block in controllers/ordering.php and the legacy
 * templates/ordering/coop_order.bau serves the route again. Nothing under
 * controllers/ordering/coop_order.php was modified.
 */

  include_once('./include/gp_lib.php');

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/ordering/coop_order_modern.php');

  $completed = (ID && ID === 'completed');

  /* The stock to pre-add, if the route named one. `completed` is a route, not a
     stock. `desc` is the query-string form the legacy page also accepted. */
  $preadd = '';
  if (!$completed) {
    $raw = ID ? ID : getCGIParam('desc', 'G', '');
    if ($raw !== '' && $raw !== null) {
      $preadd = urldecode((string) $raw);
    }
  }

/* -------------------------------------------------------------------------- *
 * The document
 * -------------------------------------------------------------------------- */

  $bauplan = new Bauplan('Order stocks | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');

  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-coop-order.css');
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  if (!$completed) {
    $bauplan->includeScript('/js/mgdb-coop-order.js');
  }
  $bauplan->head('<meta name="description" content="Request maize genetic stocks from the Maize Genetics Cooperation Stock Center. Build a list of stocks and submit it as one request.">');

  /* A request in progress is per-visitor and changes on every add, and the
     confirmation should not be indexed either. Never let an edge cache it. */
  $bauplan->head('<meta name="robots" content="noindex">');
  header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  if ($completed) {
    $mgdb->get('body')->load('templates/ordering/mgdb_coop_order_completed.bau');
  }
  else {
    $body = $mgdb->get('body')->load('templates/ordering/mgdb_coop_order.bau');
    $body->get('preadd')->replace(htmlspecialchars($preadd, ENT_QUOTES, 'UTF-8'));
  }

  include_once('translation.php');
  $bauplan->publish();
?>
