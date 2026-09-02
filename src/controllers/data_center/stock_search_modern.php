<?php
/* file: stock_search_modern.php
 *
 * purpose: Modernized controller for the Stock Data Hub (/data_center/stock).
 *          Computes real-time corpus statistics, category breakdowns,
 *          populates filter dropdowns, and renders the modern responsive page shell.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./include/references_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting stock_search_modern.php');

$DBConn = connect_to_database(false);

// Bypass Cloudflare and browser edge cache
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$bauplan = new Bauplan('MaizeGDB Stock Center | Maize Genetic Stocks and Germplasm');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-stock.css';
$js_file = $doc_root . '/js/mgdb-stock.js';
$hub_file = $doc_root . '/css/mgdb-hub.css';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js = file_exists($js_file) ? filemtime($js_file) : time();
$v_hub = file_exists($hub_file) ? filemtime($hub_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* The shared Data Hub shell -- pale blue ground, white section cards, coloured
   section edges, the reference card, aligned form rows -- loaded before the
   page's own sheet, which is the order css/mgdb-hub.css documents.
   `mgdb-hub-page` on <main> opts in. */
$bauplan->includeCss('/css/mgdb-hub.css?v=' . $v_hub);
$bauplan->includeCss('/css/mgdb-stock.css?v=' . $v_css);
$bauplan->includeScript('/js/lib/plotly/plotly-2.25.2.min.js');
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-stock.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Search curated maize genetic stocks and germplasm by identifier, synonym, variation, phenotype, provider, and parentage, and find where to order them.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_stock.bau');

// Summary figures and the stock-type breakdown
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

/* The stock dashboard: one grouped rollup over the available-stock corpus that
   also carries the four headline counts, plus the GRIN total. Collection-wide
   and static between monthly reloads. See include/dashboard_cache.php. */
$stock_data = dashboardCache($system, 'stock/dashboard_' . (int) @filemtime(__FILE__), function () use ($DBConn, $dashboard_sql) {
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
    $labels[] = str_replace('|', '/', $row['label']);
    $values[] = (int) $row['total'];
    $charted += (int) $row['total'];
  }

  $grin_row = retrieve_row(make_query($DBConn, 'SELECT COUNT(*) AS total FROM stock_grin'));

  return array(
    'labels'  => $labels,
    'values'  => $values,
    'metrics' => $metrics,
    'charted' => $charted,
    'grin'    => $grin_row ? (int) $grin_row['total'] : 0
  );
});

$labels     = $stock_data['labels'];
$values     = $stock_data['values'];
$metrics    = $stock_data['metrics'];
$charted    = $stock_data['charted'];
$grin_total = $stock_data['grin'];

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

/* The five filter lists are GROUP BY rollups over the stock corpus -- together
   the slowest part of this page after the dashboard rollup, and just as static
   between monthly reloads. See include/dashboard_cache.php. */
$stock_options = dashboardCache($system, 'stock/options_' . (int) @filemtime(__FILE__), function () use ($DBConn) {
  return array(
    'type_options' => stockOptions($DBConn, "
    SELECT t.id, t.name, COUNT(DISTINCT s.id) AS count
    FROM mgdb.term t
    JOIN mgdb.stock s ON s.type = t.id
    JOIN mgdb.id_num i ON i.id = s.id AND i.curation_lvl = 0
    GROUP BY t.id, t.name
    ORDER BY count DESC, t.name ASC"),
    'provider_options' => stockOptions($DBConn, "
    SELECT p.id, p.name, COUNT(DISTINCT s.id) AS count
    FROM mgdb.person p
    JOIN mgdb.stock s ON s.available_from = p.id
    JOIN mgdb.id_num i ON i.id = s.id AND i.curation_lvl = 0
    GROUP BY p.id, p.name
    ORDER BY count DESC, p.name ASC"),
    'linkage_options' => stockOptions($DBConn, "
    SELECT lg.id, lg.name, COUNT(DISTINCT s.id) AS count
    FROM mgdb.linkage_group lg
    JOIN mgdb.stock s ON s.focus_linkage_group = lg.id
    JOIN mgdb.id_num i ON i.id = lg.id AND i.curation_lvl = 0
    GROUP BY lg.id, lg.name
    ORDER BY lg.name ASC"),
    'phenotype_options' => stockOptions($DBConn, "
    SELECT ph.id, ph.name, COUNT(DISTINCT sp.id) AS count
    FROM mgdb.phenotype ph
    JOIN mgdb.stock_phenotypes sp ON sp.phenotype = ph.id
    JOIN mgdb.id_num i ON i.id = ph.id AND i.curation_lvl = 0
    GROUP BY ph.id, ph.name
    ORDER BY count DESC, ph.name ASC
    LIMIT 250"),
    'karyotype_options' => stockOptions($DBConn, "
    SELECT kv.id, kv.name, COUNT(DISTINCT skv.id) AS count
    FROM mgdb.karyotypic_variation kv
    JOIN mgdb.stock_karyotypic_var skv ON skv.karyotypic_var = kv.id
    JOIN mgdb.id_num i ON i.id = kv.id AND i.curation_lvl = 0
    GROUP BY kv.id, kv.name
    ORDER BY count DESC, kv.name ASC")
  );
});

$content->get('type_options')->replace($stock_options['type_options']);
$content->get('provider_options')->replace($stock_options['provider_options']);
$content->get('linkage_options')->replace($stock_options['linkage_options']);
$content->get('phenotype_options')->replace($stock_options['phenotype_options']);
$content->get('karyotype_options')->replace($stock_options['karyotype_options']);

/* References: the collections these records describe and the papers behind
   them, rendered by include/references_lib.php so these cards match every
   other hub. */
$content->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    // The 26 NAM founder assemblies the founder table on this page lists.
    array('doi' => '10.1126/science.abg5289'),
    // A transposon-tagged mutant collection distributed as seed stocks.
    array('doi' => '10.1104/pp.20.00478'),
    // What marker panels show about the breeding lines these stocks come from.
    array('doi' => '10.1007/s00122-019-03486-y'),
    // How this germplasm was curated in the first place.
    array('doi' => '10.1016/j.cpb.2017.11.001'),
    // The database of record.
    array('doi' => '10.1093/nar/gky1046'),
)));

include_once('translation.php');
$bauplan->publish();
return true;

function stockOptions($DBConn, $query) {
  $options = '';
  if (!$DBConn) return $options;
  $statement = make_query($DBConn, $query, 1);
  while ($row = retrieve_row($statement)) {
    $name = trim((string) $row['name']);
    if ($name === '') {
      continue;
    }
    $cnt = isset($row['count']) ? ' (' . number_format((int) $row['count']) . ')' : '';
    $options .= '<option value="' . (int) $row['id'] . '">'
              . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . $cnt . "</option>\n";
  }
  return $options;
}
?>
