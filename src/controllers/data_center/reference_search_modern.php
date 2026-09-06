<?PHP
/* file: reference_search_modern.php
 *
 * purpose: Reference literature search (/data_center/reference) on the modern
 *          design system.
 *
 *          Included by controllers/data_center.php when PAGE is 'reference' and
 *          no record id is supplied. Reference record pages, and every other
 *          data centre, continue through the original controller untouched.
 *
 *          Corpus figures and the journal and publication-type option lists are
 *          read live from the database. Results themselves are fetched by
 *          js/mgdb-reference.js from search/reference/reference_search_api.php.
 *
 *          Pre-redesign files are archived in the redesign repository under
 *          legacy/reference/.
 */

  include_once('./include/db-api.php');
  include_once('./include/dashboard_cache.php');

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting reference_search_modern.php');

  $DBConn = connect_to_database(false);

  $bauplan = new Bauplan('MaizeGDB Literature Search | References and Papers');
  $bauplan->modern();

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
    ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
  $hub_file = $doc_root . '/css/mgdb-hub.css';
  $css_file = $doc_root . '/css/mgdb-reference.css';
  $js_file  = $doc_root . '/js/mgdb-reference.js';
  $v_hub = file_exists($hub_file) ? filemtime($hub_file) : time();
  $v_css = file_exists($css_file) ? filemtime($css_file) : time();
  $v_js  = file_exists($js_file)  ? filemtime($js_file)  : time();

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  /* The shared Data Hub shell -- pale blue ground, white section cards, coloured
     section edges, the reference card, aligned form rows -- loaded before the
     page's own sheet, which is the order css/mgdb-hub.css documents.
     `mgdb-hub-page` on <main> opts in. */
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . $v_hub);
  $bauplan->includeCss('/css/mgdb-reference.css?v=' . $v_css);
  $bauplan->includeScript('/js/lib/plotly/plotly-2.25.2.min.js');
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-reference.js?v=' . $v_js);
  $bauplan->head('<meta name="description" content="Search more than 50,000 curated maize references by topic, title, author, abstract, gene model, or locus, and explore publication patterns over time.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $content = $mgdb->get('body')->load('templates/static/mgdb_reference.bau');

  /* Corpus figures and the two option lists are the same for every visitor and
     change only when the database is reloaded, so they are cached as one entry.
     Measured cost of building it: 656 ms, against 804 ms for the whole page.
     See include/dashboard_cache.php. */
  $page_data = dashboardCache($system, 'reference/page_' . (int) @filemtime(__FILE__), function () use ($DBConn) {
      $stats_sql = "
        SELECT COUNT(*) AS reference_count,
               COUNT(*) FILTER (WHERE r.doi IS NOT NULL AND btrim(r.doi) <> '') AS doi_count,
               COUNT(*) FILTER (WHERE EXISTS (
                 SELECT 1 FROM ext_db_key x WHERE x.id=r.id AND x.db_person=134209
               )) AS pubmed_count,
               MIN(r.year) FILTER (WHERE r.year BETWEEN 1800 AND 2100) AS min_year,
               MAX(r.year) FILTER (WHERE r.year BETWEEN 1800 AND 2100) AS max_year
        FROM reference r JOIN id_num i ON i.id=r.id
        WHERE i.curation_lvl=0";
      $stats = retrieve_row(make_query($DBConn, $stats_sql));

      return array(
          'journal_options'  => getJournalOptions($DBConn),
          'pub_type_options' => getPubTypeOptions($DBConn),
          'reference_count'  => (int) $stats['reference_count'],
          'doi_count'        => (int) $stats['doi_count'],
          'pubmed_count'     => (int) $stats['pubmed_count'],
          'min_year'         => $stats['min_year'],
          'max_year'         => $stats['max_year']
      );
  });

  $content->get('journal_options')->replace($page_data['journal_options']);
  $content->get('pub_type_options')->replace($page_data['pub_type_options']);
  $content->get('reference_count')->replace(number_format($page_data['reference_count']));
  $content->get('doi_count')->replace(number_format($page_data['doi_count']));
  $content->get('pubmed_count')->replace(number_format($page_data['pubmed_count']));
  $content->get('year_range')->replace($page_data['min_year'] . '&ndash;' . $page_data['max_year']);

  /* The Metrics section's scope line, rendered server side so it is right
     before any script runs. js/mgdb-reference.js rewrites it on every search;
     what it must never say is nothing. */
  $content->get('scope_line')->replace(
    'These figures cover the whole collection &mdash; all '
    . number_format($page_data['reference_count'])
    . ' curated references. Search above and they narrow to your matches.');

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
  return;

/////
// FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

function getJournalOptions($DBConn) {
  $options = '';

  $query = "
    SELECT j.name, j.id
    FROM journal j
    WHERE EXISTS (
      SELECT 1 FROM reference r JOIN id_num i ON i.id=r.id
      WHERE r.in1=j.id AND i.curation_lvl=0
    )
    ORDER BY lower(j.name)";
  $statement = make_query($DBConn,$query,1);
  while ($row = retrieve_row($statement)) {
    $options .= '<option value="' . (int) $row['id'] . '">'
              . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') . "</option>\n";
  }
  
  return $options;
}//getJournalOptions

function getPubTypeOptions($DBConn) {
  $options = '';

  $query = "
    SELECT t.name, t.id
    FROM term t
    WHERE EXISTS (
      SELECT 1 FROM reference r JOIN id_num i ON i.id=r.id
      WHERE r.type=t.id AND i.curation_lvl=0
    )
    ORDER BY lower(t.name)";
  $statement = make_query($DBConn,$query,1);
  while ($row = retrieve_row($statement)) {
    $options .= '<option value="' . (int) $row['id'] . '">'
              . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') . "</option>\n";
  }
  
  return $options;
}//getPubTypeOptions
?>
