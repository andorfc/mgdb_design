<?php
/* file: gbrowse.php  (top-level shadow controller)
 *
 * purpose: /gbrowse -- the embedded (legacy) GBrowse genome browser, on the
 *          design system.
 *
 * Same top-level-shadow pattern as controllers/jbrowse.php: controller.php
 * checks controllers/<CONTROLLER>.php first, so this takes /gbrowse with the
 * modern shell around the browser iframe, and deleting it restores the legacy
 * controllers/tools/gbrowse.php (archived in legacy/genome-browsers/).
 *
 * The legacy page also ran a block of JavaScript that widened the old #wrapper
 * to 1600px and swapped the legacy content_top/menu_bar chrome images. All of
 * that operated on chrome the modern shell no longer has, so it is gone; the
 * browser fills the modern content rail instead.
 *
 * Two things gblade needs are preserved exactly:
 *   - the iframe `name` is the site subdomain -- gblade builds its bookmark
 *     URLs from the window name, so changing it breaks bookmarking;
 *   - the `;`-separated GBrowse parameters are forwarded (minus flip=0, which
 *     the legacy page dropped because it triggered a GBrowse bug).
 * The source, subdomain and parameters are each restricted to a safe character
 * set and the finished URL is HTML-escaped before it reaches the iframe.
 */

  include_once('./include/gp_lib.php');

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/gbrowse.php (modern GBrowse embed)');

  // Assembly source = first path segment (PAGE); default and the w22 alias match
  // the legacy controller.
  $source = (PAGE) ? PAGE : 'maize_v4';
  if ($source === 'w22') { $source = 'maize_w22'; }
  $source = preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) $source);
  if ($source === '') { $source = 'maize_v4'; }

  // The window name gblade reads to build bookmark URLs. Same derivation the
  // legacy page used (first label of the host), then restricted to a hostname
  // label's characters.
  $root = explode('.', $system['root_url']);
  $subdomain = isset($root[0]) ? substr($root[0], 7) : '';   // strip "http://"
  $subdomain = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $subdomain);

  /* Forward the ;-separated GBrowse parameters. flip=0 is dropped (it triggers a
     GBrowse bug; flip=1 is fine). Each segment is restricted to the characters
     GBrowse actually uses -- including the percent-encoded record separators in
     a track list (e.g. l=A%1EB) -- so nothing can break out of the src. */
  $params = '';
  if (!empty($_SERVER['QUERY_STRING'])) {
    $segs = explode(';', $_SERVER['QUERY_STRING']);
    $keep = array();
    foreach ($segs as $seg) {
      if ($seg === '' || $seg === 'flip=0') { continue; }
      $seg = preg_replace('/[^A-Za-z0-9_%.=:,\-]/', '', $seg);
      if ($seg !== '') { $keep[] = $seg; }
    }
    if ($keep) { $params = implode(';', $keep) . ';'; }
  }

  $browser_url = 'https://gbrowse.maizegdb.org/gb2/gbrowse/' . rawurlencode($source)
               . '/?url=' . rawurlencode($subdomain) . ';' . $params;

  $esc = function ($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); };

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
            ? $_SERVER['DOCUMENT_ROOT'] : $system['root_dir'];

  $bauplan = new Bauplan('GBrowse | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-genome-browser-embed.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-genome-browser-embed.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-genome-browser-embed.js?v=' . (int) @filemtime($doc_root . '/js/mgdb-genome-browser-embed.js'));
  $bauplan->head('<meta name="description" content="Browse maize assemblies in GBrowse at MaizeGDB.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/tools/mgdb_gbrowse.bau');
  $body->get('browser-url')->replace($esc($browser_url));
  $body->get('window-name')->replace($esc($subdomain));
  $body->get('source')->replace($esc($source));

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);
  $bauplan->publish();
  exit;
?>
