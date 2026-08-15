<?PHP
/* file: stock_search_modern.php
 *
 * purpose: Stock data center search page (/data_center/stock) on the modern
 *          design system.
 *
 *          Included by controllers/data_center.php when PAGE is 'stock' and no
 *          record id is supplied. Stock *record* pages, and every other data
 *          centre, continue through the original controller untouched.
 *
 *          Summary figures and the filter option lists are read live from the
 *          database. Results themselves are fetched by js/mgdb-stock.js from
 *          search/stock/stock_search_api.php.
 *
 *          Pre-redesign files are archived in the redesign repository under
 *          legacy/stock/.
 */

  include_once('./include/db-api.php');

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting stock_search_modern.php');

  $DBConn = connect_to_database(false);

  $bauplan = new Bauplan('MaizeGDB Stock Search | Maize Genetic Stocks and Germplasm');
  $bauplan->modern();

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-stock.css');
  $bauplan->includeScript('/js/lib/plotly/plotly-2.25.2.min.js');
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-stock.js');
  $bauplan->head('<meta name="description" content="Search curated maize genetic stocks and germplasm by identifier, synonym, variation, phenotype, provider, and parentage, and find where to order them.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $content = $mgdb->get('body')->loadRemote($system['root_url_private'] . '/templates/static/mgdb_stock.bau');

  /////
  // Summary figures and the stock-type breakdown
  //
  // One query: the per-type counts carry the collection-wide totals alongside
  // them, so the whole dashboard costs a single pass over the stock table.
  /////

  $dashboard_sql = "
    WITH available AS MATERIALIZED (
      SELECT s.type, s.available_from
      FROM mgdb.stock s
        INNER JOIN mgdb.id_num i ON i.id = s.id
      WHERE i.type_term = 26 AND i.curation_lvl = 0
    )
    SELECT COALESCE(NULLIF(btrim(t.name), ''), 'Unclassified') AS label,
           COUNT(*) AS total,
           (SELECT COUNT(*) FROM available) AS active,
           (SELECT COUNT(*) FROM available WHERE available_from IS NOT NULL) AS with_provider,
           (SELECT COUNT(DISTINCT type) FROM available) AS types,
           (SELECT COUNT(DISTINCT available_from) FROM available) AS providers
    FROM available a
      LEFT JOIN mgdb.term t ON t.id = a.type
    GROUP BY 1
    ORDER BY COUNT(*) DESC, 1
    LIMIT 10";

  $labels = array();
  $values = array();
  $metrics = array('active' => 0, 'with_provider' => 0, 'types' => 0, 'providers' => 0);
  $charted = 0;

  $sth = make_query($DBConn, $dashboard_sql);
  while ($row = retrieve_row($sth)) {
    if (count($labels) === 0) {
      $metrics['active'] = (int) $row['active'];
      $metrics['with_provider'] = (int) $row['with_provider'];
      $metrics['types'] = (int) $row['types'];
      $metrics['providers'] = (int) $row['providers'];
    }
    // The label list is pipe-separated: a stock type may contain a comma, and
    // the .bau parser has its own opinions about parentheses and backslashes.
    $labels[] = str_replace('|', '/', $row['label']);
    $values[] = (int) $row['total'];
    $charted += (int) $row['total'];
  }

  $grin_row = retrieve_row(make_query($DBConn, 'SELECT COUNT(*) AS total FROM stock_grin'));
  $grin_total = $grin_row ? (int) $grin_row['total'] : 0;

  $content->get('metric_active')->replace(number_format($metrics['active']));
  $content->get('metric_with_provider')->replace(number_format($metrics['with_provider']));
  $content->get('metric_types')->replace(number_format($metrics['types']));
  $content->get('metric_providers')->replace(number_format($metrics['providers']));
  $content->get('metric_grin')->replace(number_format($grin_total));

  $content->get('chart_labels')->replace(htmlspecialchars(implode('|', $labels), ENT_QUOTES, 'UTF-8'));
  $content->get('chart_values')->replace(implode(',', $values));

  $remainder = $metrics['active'] - $charted;
  $caption = 'The ' . count($labels) . ' largest of ' . number_format($metrics['types'])
           . ' curated stock types, covering ' . number_format($charted) . ' of the '
           . number_format($metrics['active']) . ' current records';
  $caption .= ($remainder > 0)
            ? '; the remaining ' . number_format($remainder) . ' are spread across the smaller types.'
            : '.';
  $content->get('chart_caption')->replace(htmlspecialchars($caption, ENT_QUOTES, 'UTF-8'));

  /////
  // Advanced search option lists
  /////

  $content->get('provider_options')->replace(stockOptions($DBConn, "
    SELECT DISTINCT p.id, p.name
    FROM mgdb.person p
      INNER JOIN mgdb.stock s ON s.available_from = p.id
      INNER JOIN mgdb.id_num i ON i.id = s.id AND i.curation_lvl = 0
    ORDER BY p.name"));

  $content->get('developer_options')->replace(stockOptions($DBConn, "
    SELECT DISTINCT p.id, p.name
    FROM mgdb.person p
      INNER JOIN mgdb.stock s ON s.developer = p.id
    ORDER BY p.name"));

  $content->get('type_options')->replace(stockOptions($DBConn, "
    SELECT t.id, t.name FROM mgdb.term t
    WHERE t.id IN (SELECT DISTINCT type FROM mgdb.stock WHERE type IS NOT NULL)
    ORDER BY t.name"));

  $content->get('linkage_options')->replace(stockOptions($DBConn, "
    SELECT DISTINCT lg.id, lg.name
    FROM mgdb.linkage_group lg
      INNER JOIN mgdb.stock s ON s.focus_linkage_group = lg.id
      INNER JOIN mgdb.id_num i ON i.id = lg.id AND i.curation_lvl = 0
    ORDER BY lg.name"));

  $content->get('parent_options')->replace(stockOptions($DBConn, "
    SELECT DISTINCT s.id, s.name
    FROM mgdb.stock s
      INNER JOIN mgdb.stock_coeff_parent p ON p.stock1 = s.id
      INNER JOIN mgdb.id_num pidn ON pidn.id = s.id AND pidn.curation_lvl = 0
      INNER JOIN mgdb.id_num idn ON idn.id = p.id AND idn.curation_lvl = 0
    ORDER BY s.name"));

  $content->get('karyotype_options')->replace(stockOptions($DBConn, "
    SELECT DISTINCT kv.id, kv.name
    FROM mgdb.karyotypic_variation kv
      INNER JOIN mgdb.stock_karyotypic_var skv ON skv.karyotypic_var = kv.id
      INNER JOIN mgdb.id_num i ON i.id = kv.id AND i.curation_lvl = 0
    ORDER BY kv.name"));

  $content->get('phenotype_options')->replace(stockOptions($DBConn, "
    SELECT DISTINCT ph.id, ph.name
    FROM mgdb.phenotype ph
      INNER JOIN mgdb.stock_phenotypes sp ON sp.phenotype = ph.id
      INNER JOIN mgdb.id_num i ON i.id = ph.id AND i.curation_lvl = 0
    ORDER BY ph.name"));

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
  return;

/////
// FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

/* Each query selects an id and a name, in the order the list should appear. */
function stockOptions($DBConn, $query) {
  $options = '';
  $statement = make_query($DBConn, $query, 1);
  while ($row = retrieve_row($statement)) {
    $name = trim((string) $row['name']);
    if ($name === '') {
      continue;
    }
    $options .= '<option value="' . (int) $row['id'] . '">'
              . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . "</option>\n";
  }
  return $options;
}//stockOptions
?>
