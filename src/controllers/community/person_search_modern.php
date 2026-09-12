<?PHP
/* file: person_search_modern.php
 *
 * purpose: Person and organization search (/person) on the shared Data Hub shell.
 *
 *          Included by controllers/community.php when the person controller is
 *          reached with no record id. Person *record* pages, and every other
 *          community page, continue through the original controller untouched.
 *
 *          Results and suggestions are fetched by js/mgdb-person.js from the
 *          endpoints under /tools/ajax/person_search/. The page no longer runs a
 *          default search on load -- it opens to a prompt, not a Walbot query.
 */

  include_once('./include/db-api.php');
  include_once('./include/dashboard_cache.php');
  include_once('./include/references_lib.php');

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting person_search_modern.php');

  // Bypass edge and browser cache
  header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
  header("Pragma: no-cache");
  header("Expires: 0");

  $query_term = trim(getCGIParam('term', 'GP', ''));

  $bauplan = new Bauplan('Find a Person or Organization | MaizeGDB');
  $bauplan->modern();

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
  $css_file = $doc_root . '/css/mgdb-person.css';
  $hub_file = $doc_root . '/css/mgdb-hub.css';
  $js_file  = $doc_root . '/js/mgdb-person.js';
  $v_css = file_exists($css_file) ? filemtime($css_file) : time();
  $v_hub = file_exists($hub_file) ? filemtime($hub_file) : time();
  $v_js  = file_exists($js_file)  ? filemtime($js_file)  : time();

  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  // The Data Hub shell, loaded before the page sheet so the page can override it.
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . $v_hub);
  $bauplan->includeCss('/css/mgdb-person.css?v=' . $v_css);
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-person.js?v=' . $v_js);
  $bauplan->head('<meta name="description" content="Search the MaizeGDB community directory of maize researchers and organizations by name, alias, or institution.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_person.bau');
  $body->get('queryterm')->replace(htmlspecialchars($query_term));

  /* Collection-wide metric counts. One scan of the curated person corpus --
     the FILTERs are over plain columns of the same row, so this is a single
     pass, not one per subset. Keyed on the controller mtime so a metric markup
     change is not served stale from a warm cache. */
  $DBConn = connect_to_database(false);
  $metrics = dashboardCache($system, 'person/metrics_' . (int) @filemtime(__FILE__), function () use ($DBConn) {
    $out = array('people' => 0, 'orgs' => 0, 'countries' => 0, 'orcid' => 0);
    if ($DBConn) {
      $sql = "
        SELECT
          count(*) FILTER (WHERE p.type = 20)                       AS people,
          count(*) FILTER (WHERE p.type <> 20)                      AS orgs,
          count(DISTINCT NULLIF(TRIM(p.country), ''))               AS countries,
          count(*) FILTER (WHERE COALESCE(TRIM(p.orcid), '') <> '') AS orcid
        FROM person p
        JOIN id_num i ON p.id = i.id AND i.curation_lvl = 0";
      $row = retrieve_row(make_query($DBConn, $sql, 1));
      if ($row) {
        $out['people']    = (int) $row['people'];
        $out['orgs']      = (int) $row['orgs'];
        $out['countries'] = (int) $row['countries'];
        $out['orcid']     = (int) $row['orcid'];
      }
    }
    return $out;
  });

  $body->get('people')->replace(number_format($metrics['people']));
  $body->get('orgs')->replace(number_format($metrics['orgs']));
  $body->get('countries')->replace(number_format($metrics['countries']));
  $body->get('orcid')->replace(number_format($metrics['orcid']));

  /* References: the MaizeGDB resource and community-database papers that
     describe how this directory's records are curated and maintained. */
  $body->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    array('doi' => '10.1093/genetics/iyag005'),
    array('doi' => '10.1093/nar/gky1046'),
    array('doi' => '10.1093/database/bar022'),
    array('doi' => '10.1093/nar/gkh011'),
  )));

  // Fills the megamenu labels; without it the header renders with empty links.
  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
  return;
?>
