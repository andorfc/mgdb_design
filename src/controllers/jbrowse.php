<?php
/* file: jbrowse.php  (top-level shadow controller)
 *
 * purpose: /jbrowse -- the embedded JBrowse 1 genome browser, on the design
 *          system.
 *
 * `/jbrowse` used to fall through controller.php to redirect.php, which loaded
 * the legacy main template and chrome before running controllers/tools/jbrowse.php.
 * controller.php checks controllers/<CONTROLLER>.php first, so adding this
 * top-level file takes the route with a clean modern shell -- modern header,
 * megamenu and footer -- around the browser iframe. Deleting it gives the route
 * back to the untouched legacy controller, archived in legacy/genome-browsers/.
 *
 * The assembly source is the first path segment: /jbrowse/v5 -> "v5" (default
 * "v5"). Any extra query string is forwarded to the browser host. The source is
 * restricted to a safe character set and the finished URL is HTML-escaped before
 * it reaches the iframe src, so neither can break out of the attribute.
 */

  include_once('./include/gp_lib.php');

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/jbrowse.php (modern JBrowse embed)');

  /* Source = first path segment (PAGE), constrained to the identifier charset
     the browser hosts actually use. */
  $source = (PAGE) ? PAGE : 'v5';
  $source = preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) $source);
  if ($source === '') { $source = 'v5'; }

  /* Forward any extra query parameters, minus the ones we set ourselves. Each
     key and value is url-encoded so the forwarded URL is well formed. */
  $forward = '';
  if (!empty($_SERVER['QUERY_STRING'])) {
    $pairs = explode('&', $_SERVER['QUERY_STRING']);
    $clean = array();
    foreach ($pairs as $pair) {
      if ($pair === '') { continue; }
      $kv = explode('=', $pair, 2);
      $k = urldecode($kv[0]);
      if ($k === '' || $k === 'data') { continue; }
      $v = isset($kv[1]) ? urldecode($kv[1]) : '';
      $clean[] = rawurlencode($k) . '=' . rawurlencode($v);
    }
    if ($clean) { $forward = '&' . implode('&', $clean); }
  }

  $browser_url = 'https://jbrowse.maizegdb.org/?data=' . rawurlencode($source) . $forward;

  $esc = function ($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); };

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
            ? $_SERVER['DOCUMENT_ROOT'] : $system['root_dir'];

  $bauplan = new Bauplan('JBrowse | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-genome-browser-embed.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-genome-browser-embed.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-genome-browser-embed.js?v=' . (int) @filemtime($doc_root . '/js/mgdb-genome-browser-embed.js'));
  $bauplan->head('<meta name="description" content="Browse the maize genome in JBrowse at MaizeGDB.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/tools/mgdb_jbrowse.bau');
  $body->get('browser-url')->replace($esc($browser_url));
  $body->get('source')->replace($esc($source));

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);
  $bauplan->publish();
  exit;
?>
