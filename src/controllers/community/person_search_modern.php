<?PHP
/* file: person_search_modern.php
 *
 * purpose: Person and organization search (/person) on the modern design system.
 *
 *          Included by controllers/community.php when the person controller is
 *          reached with no record id. Person *record* pages, and every other
 *          community page, continue through the original controller untouched.
 *
 *          Results and suggestions are fetched by js/mgdb-person.js from the
 *          endpoints under /tools/ajax/person_search/.
 */

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting person_search_modern.php');

  // Bypass edge and browser cache
  header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
  header("Pragma: no-cache");
  header("Expires: 0");

  $query_term = trim(getCGIParam('term', 'GP', ''));
  $initial_letter = strtoupper(trim(getCGIParam('letter', 'GP', '')));

  $bauplan = new Bauplan('Find a Person or Organization | MaizeGDB');
  $bauplan->modern();

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
  $css_file = $doc_root . '/css/mgdb-person.css';
  $js_file  = $doc_root . '/js/mgdb-person.js';
  $v_css = file_exists($css_file) ? filemtime($css_file) : time();
  $v_js  = file_exists($js_file)  ? filemtime($js_file)  : time();

  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-person.css?v=' . $v_css);
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-person.js?v=' . $v_js);
  $bauplan->head('<meta name="description" content="Search MaizeGDB community records for 57,000+ maize researchers and organizations by name, institution, or known aliases.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_person.bau');
  $body->get('data_date')->replace(date('F j, Y'));
  $body->get('queryterm')->replace(htmlspecialchars($query_term));

  $bauplan->publish();
  return;
?>
