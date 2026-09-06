<?php
/* file: stock_modern.php
 *
 * purpose: /ordering/stock -- the stock order basket, on the design system.
 *
 * Loaded by controllers/ordering.php for every /ordering/stock route except
 * `submit`. That one branch still runs controllers/ordering/stock.php exactly as
 * it did: it redirects to a live order form at the Stock Center, and it is not
 * something to re-implement for a restyle.
 *
 * The basket itself is a cookie, `stock_order`, one entry per line in the shape
 * `<id>+<name>`. This file reads and re-writes that cookie the same way the
 * legacy page did, so an order started before the change still submits.
 *
 * Two things the legacy page did that are not carried over:
 *
 *   - It printed the cookie into the page with `str_replace("\n", "<br>", ...)`
 *     and no escaping, so whatever the cookie held was rendered as markup.
 *     Every value here goes through mgdb_html().
 *   - Arriving at /ordering/stock with no stock named produced "Please select a
 *     stock first", even when the basket already had entries -- the message
 *     was about the URL rather than the order. An empty basket now says so
 *     where the list would be, and a basket with entries just shows them.
 *
 * Rollback: delete this file and the guard in controllers/ordering.php, and the
 * legacy templates/ordering/stock.bau serves the route again. Nothing under
 * templates/ordering/ was modified.
 */

  include_once('./include/gp_lib.php');

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/ordering/stock_modern.php');

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
            ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';

/* -------------------------------------------------------------------------- *
 * The basket
 * -------------------------------------------------------------------------- */

  $requested = ID ? ID : getCGIParam('desc', 'G', '');
  $cleared   = (ID == 'empty');
  $added     = '';

  $basket = isset($_COOKIE['stock_order']) ? (string) $_COOKIE['stock_order'] : '';

  if ($cleared) {
    // Same cookie write the legacy page made, so a cleared order stays cleared.
    setcookie('stock_order', ' ', time() + 86400);
    $basket = '';
  }
  else if ($requested !== '' && $requested !== null) {
    $added  = rawurldecode((string) $requested);
    $basket = $basket . "\n" . $requested;
    setcookie('stock_order', $basket, time() + 86400);
  }

  /* One entry per line, `<id>+<name>`. Anything without a `+` is kept and shown
     whole rather than dropped: it is still something the visitor asked for, and
     the submit branch reads the cookie itself. */
  $items = array();
  foreach (preg_split('/\r\n|\r|\n/', $basket) as $line) {
    $line = trim($line);
    if ($line === '') {
      continue;
    }
    $plus = strpos($line, '+');
    $items[] = array(
      'id'    => $plus === false ? '' : substr($line, 0, $plus),
      'label' => $plus === false ? $line : substr($line, $plus + 1),
    );
  }

/* -------------------------------------------------------------------------- *
 * The document
 * -------------------------------------------------------------------------- */

  $bauplan = new Bauplan('Stock order | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');

  $css_file = $doc_root . '/css/mgdb-stock-order.css';
  $v_css = file_exists($css_file) ? filemtime($css_file) : time();

  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-stock-order.css?v=' . $v_css);
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->head('<meta name="description" content="The stocks you have collected for a seed request to the Maize Genetics Cooperation Stock Center.">');
  // A basket is per-visitor and changes on every add; never let an edge cache it.
  $bauplan->head('<meta name="robots" content="noindex">');
  header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/ordering/mgdb_stock_order.bau');

/* -------------------------------------------------------------------------- *
 * What happened, if anything did
 * -------------------------------------------------------------------------- */

  $message = '';
  if ($cleared) {
    $message = '<div class="mgdb-message mgdb-message-info" role="status">'
             . '<span><strong>Order cleared.</strong> Nothing is waiting to be submitted.</span></div>';
  }
  else if ($added !== '') {
    $plus  = strpos($added, '+');
    $shown = $plus === false ? $added : substr($added, $plus + 1);
    $message = '<div class="mgdb-message mgdb-message-ok" role="status">'
             . '<span><strong>Added to your order:</strong> ' . mgdb_html($shown) . '</span></div>';
  }
  $body->get('status_message')->replace($message);

/* -------------------------------------------------------------------------- *
 * The list
 * -------------------------------------------------------------------------- */

  $count = count($items);
  $body->get('order_count')->replace(
      $count === 0 ? '' : ($count . ($count === 1 ? ' stock' : ' stocks')));

  if ($count === 0) {
    $order_body = '<div class="mgdb-empty">'
                . '<h3>Your order is empty</h3>'
                . '<p>Stocks are added from a stock record. Find one through the stock search, then use Order this stock.</p>'
                . '<a class="mgdb-button mgdb-button-secondary" href="/data_center/stock">Search stocks</a>'
                . '</div>';
  }
  else {
    $rows = '';
    foreach ($items as $item) {
      $id    = $item['id'];
      $label = mgdb_html(rawurldecode($item['label']));

      /* A numeric id is a stock record, so the name links to it. Anything else
         is printed as it stands rather than guessed at. */
      $name = ctype_digit((string) $id)
            ? '<a href="/data_center/stock/' . rawurlencode($id) . '">' . $label . '</a>'
            : $label;

      $rows .= '<tr><td>' . $name . '</td><td class="stock-order-id">'
             . ($id === '' ? '<span class="mgdb-muted">&mdash;</span>' : mgdb_html($id))
             . '</td></tr>';
    }

    $order_body =
        '<div class="mgdb-table-scroll">'
      . '<table class="mgdb-table"><caption class="mgdb-visually-hidden">Stocks in your order</caption>'
      . '<thead><tr><th scope="col">Stock</th><th scope="col">Stock ID</th></tr></thead>'
      . '<tbody>' . $rows . '</tbody></table></div>'

      . '<p class="mgdb-note"><strong>Add everything before you submit.</strong> '
      . 'The order is handed over in one piece, so keep adding stocks until the list above is complete.</p>'

      . '<div class="mgdb-form-actions stock-order-actions">'
      . '<a class="mgdb-button mgdb-button-primary" href="/ordering/stock/submit">Submit this order to the Stock Center</a>'
      . '<a class="mgdb-button mgdb-button-quiet" href="/ordering/stock/empty">Clear this order</a>'
      . '</div>';
  }

  $body->get('order_body')->replace($order_body);

  include_once('translation.php');
  $bauplan->publish();
?>
