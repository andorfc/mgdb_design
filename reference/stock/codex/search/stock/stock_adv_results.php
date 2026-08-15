<?php
/* file: stock_adv_results.php
 *
 * purpose: parameterized advanced search for stock records.
 */

include_once('../../lib/Bauplan.php');
include_once('../../include/db-api.php');
include_once('../../include/gp_lib.php');
include_once('../../include/data_center_functions.php');

$system = getSystemInfo('mgdb.conf');
$search_limit = (int)getCGIParam('adv_limit_val', 'GP', $system['search_limit']);
if ($search_limit > 0) setSessionVar('adv_stock_limit', $search_limit);
$search_limit = ($search_limit <= 0 || $search_limit > (int)$system['search_limit_max'])
              ? (int)$system['search_limit_max'] : $search_limit;
$pagesize = max(1, (int)$system['pagesize']);
$pagenum = max(1, (int)getCGIParam('pagenum', 'GP', 1));
$cytogenetics = getCGIParam('cytogenetics', 'GP', false);
$type = (int)getCGIParam('type', 'GP', 0);

$bauplan = new Bauplan('Results page');
$template = $bauplan->template()->load('../../templates/data_center/stock-adv-results.bau');
$DBConn = connect_to_database();

if ($pagenum > 1) {
  $rows = getCGIParam('rows_adv', 'GP', '');
  $stockList = @unserialize(urldecode($rows), array('allowed_classes' => false));
  if (!is_array($stockList)) $stockList = array();
  $arrCount = count($stockList);
  $page_template = new Bauplan('Results page');
  $tmpl = $page_template->template()->load('../../templates/data_center/stock-adv-results-page.bau');
  $tmpl->get('type123')->replace($type);
  $start = (($pagenum - 1) * $pagesize) + 1;
  $end = min($arrCount, $start + $pagesize - 1);
  $tmpl->get('stock-adv-row')->loop(stockAdvancedPage($stockList, $start, $end));
  $pagecount = $arrCount ? (int)ceil($arrCount / $pagesize) : 0;
  if ($pagenum < $pagecount) {
    $tmpl->get('nextpage')->replace($pagenum + 1);
    $tmpl->get('cytogenetics')->replace($cytogenetics ? $cytogenetics : 'false');
    $tmpl->get('load-next-page_adv')->unmute();
  }
  $page_template->publish();
  exit;
}

$filters = array(
  'mgsc' => stockAdvancedEnabled('box_mgsc'),
  'developer' => stockAdvancedEnabled('box_dev'),
  'name' => stockAdvancedEnabled('box_name'),
  'type' => stockAdvancedEnabled('box_type'),
  'linkage' => stockAdvancedEnabled('box_lg'),
  'genvar1' => stockAdvancedEnabled('box_genvar1'),
  'genvar2' => stockAdvancedEnabled('box_genvar2'),
  'genvar3' => stockAdvancedEnabled('box_genvar3'),
  'karyotype' => stockAdvancedEnabled('box_kv'),
  'phenotype' => stockAdvancedEnabled('box_pheno'),
  'available' => stockAdvancedEnabled('box_avail'),
  'parent' => stockAdvancedEnabled('box_parent'),
  'expvp' => stockAdvancedEnabled('box_expvp'),
  'bank' => stockAdvancedEnabled('box_bank')
);

$where = array('idn.curation_lvl IN (0, 101)');
$params = array();
$criteria = array();

if ($filters['mgsc']) {
  $where[] = 's.available_from=25725';
  $criteria[] = 'Available from the Maize Genetics Cooperation Stock Center.';
}
if ($filters['bank']) {
  $where[] = 's.available_from IN (60219, 62075, 69173)';
  $criteria[] = 'Available from a represented germplasm bank.';
}
if ($filters['expvp']) {
  $where[] = 'EXISTS (SELECT 1 FROM mgdb.ext_db_key pvp WHERE pvp.id=s.id AND pvp.db_person=40310)';
  $criteria[] = 'Recorded as an ex-Plant Variety Protection stock.';
}

if ($filters['developer']) {
  $developer = (int)getCGIParam('dev', 'GP', 0);
  if ($developer > 0) {
    $where[] = 's.developer=:developer';
    $params[':developer'] = $developer;
    $criteria[] = 'Developed by ' . stockAdvancedLookup($DBConn, 'person', $developer) . '.';
  }
  else {
    $where[] = 's.developer IS NOT NULL';
    $criteria[] = 'Has a recorded developer.';
  }
}

if ($filters['available']) {
  $available = (int)getCGIParam('avail', 'GP', 0);
  if ($available > 0) {
    $where[] = 's.available_from=:available_from';
    $params[':available_from'] = $available;
    $criteria[] = 'Available from ' . stockAdvancedLookup($DBConn, 'person', $available) . '.';
  }
  else {
    $where[] = 's.available_from IS NOT NULL';
    $where[] = 'idn.curation_lvl=0';
    $criteria[] = 'Currently available from a recorded provider.';
  }
}

$stock_name = stockAdvancedText(getCGIParam('stock_name', 'GP', ''));
if ($filters['name'] && $stock_name !== '') {
  if (substr($stock_name, -1) === ' ') {
    $where[] = 'LOWER(s.name)=:stock_name';
    $params[':stock_name'] = strtolower(trim($stock_name));
    $criteria[] = 'Identifier exactly matches ' . stockAdvancedEscape(trim($stock_name)) . '.';
  }
  else {
    $where[] = 'LOWER(s.name) LIKE :stock_name';
    $params[':stock_name'] = '%' . strtolower($stock_name) . '%';
    $criteria[] = 'Identifier contains ' . stockAdvancedEscape($stock_name) . '.';
  }
}
else if ($filters['name']) {
  $filters['name'] = false;
}

if ($filters['type']) {
  if ($type > 0) {
    $where[] = 's.type=:stock_type';
    $params[':stock_type'] = $type;
    $criteria[] = 'Stock type is ' . stockAdvancedLookup($DBConn, 'term', $type) . '.';
  }
  else {
    $where[] = 's.type IS NOT NULL';
    $criteria[] = 'Has a recorded stock type.';
  }
}

if ($filters['linkage']) {
  $linkage_group = (int)getCGIParam('lg', 'GP', 0);
  if ($linkage_group > 0) {
    $where[] = 's.focus_linkage_group=:linkage_group';
    $params[':linkage_group'] = $linkage_group;
    $criteria[] = 'Focus linkage group is ' . stockAdvancedLookup($DBConn, 'linkage_group', $linkage_group) . '.';
  }
  else {
    $where[] = 's.focus_linkage_group IS NOT NULL';
    $criteria[] = 'Has a recorded focus linkage group.';
  }
}

if ($filters['parent']) {
  $parent = (int)getCGIParam('parent', 'GP', 0);
  if ($parent > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM mgdb.stock_coeff_parent cp WHERE cp.id=s.id AND cp.stock1=:parent_stock)';
    $params[':parent_stock'] = $parent;
    $criteria[] = 'Parent stock is ' . stockAdvancedLookup($DBConn, 'stock', $parent) . '.';
  }
  else {
    $where[] = 'EXISTS (SELECT 1 FROM mgdb.stock_coeff_parent cp WHERE cp.id=s.id AND cp.stock1 IS NOT NULL)';
    $criteria[] = 'Has a recorded parent stock.';
  }
}

foreach (array('genvar1', 'genvar2', 'genvar3') as $index => $key) {
  if (!$filters[$key]) continue;
  $variation = stockAdvancedText(getCGIParam($key, 'GP', ''));
  $param = ':genotypic_variation_' . $index;
  $where[] = "EXISTS (
    SELECT 1 FROM mgdb.stock_genotypic_var sgv
      INNER JOIN mgdb.variation v ON v.id=sgv.variation
    WHERE sgv.id=s.id AND v.name LIKE $param
  )";
  $params[$param] = $variation . '%';
  $criteria[] = $variation === ''
              ? 'Has a recorded genotypic variation.'
              : 'Genotypic variation starts with ' . stockAdvancedEscape($variation) . '.';
}

if ($filters['karyotype']) {
  $karyotype = (int)getCGIParam('kv', 'GP', 0);
  if ($karyotype > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM mgdb.stock_karyotypic_var skv WHERE skv.id=s.id AND skv.karyotypic_var=:karyotype)';
    $params[':karyotype'] = $karyotype;
    $criteria[] = 'Karyotypic variation is ' . stockAdvancedLookup($DBConn, 'karyotypic_variation', $karyotype) . '.';
  }
  else {
    $where[] = 'EXISTS (SELECT 1 FROM mgdb.stock_karyotypic_var skv WHERE skv.id=s.id AND skv.karyotypic_var IS NOT NULL)';
    $criteria[] = 'Has a recorded karyotypic variation.';
  }
}

if ($filters['phenotype']) {
  $phenotype = (int)getCGIParam('pheno', 'GP', 0);
  $attribution = stockAdvancedText(getCGIParam('phenovar', 'GP', ''));
  $phenotype_conditions = array('sp.id=s.id');
  if ($phenotype > 0) {
    $phenotype_conditions[] = 'sp.phenotype=:phenotype';
    $params[':phenotype'] = $phenotype;
    $criteria[] = 'Phenotype is ' . stockAdvancedLookup($DBConn, 'phenotype', $phenotype) . '.';
  }
  else {
    $phenotype_conditions[] = 'sp.phenotype IS NOT NULL';
    $criteria[] = 'Has a recorded phenotype.';
  }
  if ($attribution !== '') {
    $phenotype_conditions[] = "EXISTS (
      SELECT 1 FROM mgdb.variation pav
      WHERE pav.id=sp.attributable_to AND LOWER(pav.name) LIKE :phenotype_attribution
    )";
    $params[':phenotype_attribution'] = '%' . strtolower($attribution) . '%';
    $criteria[] = 'Phenotype attribution contains ' . stockAdvancedEscape($attribution) . '.';
  }
  $where[] = 'EXISTS (SELECT 1 FROM mgdb.stock_phenotypes sp WHERE ' . implode(' AND ', $phenotype_conditions) . ')';
}

if (!$criteria) {
  $template->get('no-term')->unmute();
  $bauplan->publish();
  exit;
}

$query = "
  WITH matching AS MATERIALIZED (
    SELECT s.id, s.name, t.name AS type, lg.name AS linkage_group,
           s.focus_linkage_group AS linkage_group_id,
           p.name AS available_from, p.id AS available_from_id,
           idn.curation_lvl,
           (SELECT JSON_AGG(description ORDER BY description)
            FROM (SELECT DISTINCT d.description FROM mgdb.description d WHERE d.id=s.id) descriptions) AS descriptions
    FROM mgdb.stock s
      INNER JOIN mgdb.id_num idn ON idn.id=s.id
      LEFT JOIN mgdb.term t ON t.id=s.type
      LEFT JOIN mgdb.linkage_group lg ON lg.id=s.focus_linkage_group
      LEFT JOIN mgdb.person p ON p.id=s.available_from
    WHERE " . implode(' AND ', $where) . "
  )
  SELECT matching.*, COUNT(*) OVER () AS total_count
  FROM matching
  ORDER BY LOWER(name), id
  LIMIT " . (int)$search_limit;

$stmt = make_query($DBConn, $query, 1, $params);
$rows = get_all_rows($stmt);
$arrCount = $rows ? count($rows) : 0;
$arrCountAll = ($arrCount && isset($rows[0]['total_count'])) ? (int)$rows[0]['total_count'] : $arrCount;
$stockList = array();

foreach ($rows as $index => $row) {
  $descriptions = json_decode($row['descriptions'], true);
  if (!is_array($descriptions)) $descriptions = array();
  $description_html = array();
  foreach ($descriptions as $description) $description_html[] = stockAdvancedEscape($description);
  $stockList[] = array(
    'name' => stockAdvancedEscape(trim($row['name'])),
    'id' => (int)$row['id'],
    'syn' => implode('<br>', $description_html),
    'type' => stockAdvancedEscape(trim((string)$row['type'])),
    'avail_id' => (int)$row['available_from_id'],
    'lg_id' => (int)$row['linkage_group_id'],
    'lg_name' => stockAdvancedEscape(trim((string)$row['linkage_group'])),
    'avail' => stockAdvancedEscape(trim((string)$row['available_from'])),
    'status' => ((int)$row['curation_lvl'] === 101)
              ? "<span class='stock-status stock-status-unavailable'>Unavailable</span>" : '',
    'bgcolor' => ($index % 2 === 0) ? '#F5F5F5' : ''
  );
}

$effective_pagesize = max(1, min($pagesize, max(1, $arrCount)));
$pages = calcPages($arrCount, $effective_pagesize, 'stock_adv_results_page' . $type . '_');
$template->get('type123')->replace($type);
$template->get('total')->replace($arrCount);
$criteria_html = '<ul class="stock-criteria-list"><li>' . implode('</li><li>', $criteria) . '</li></ul>';
$main = getCGIParam('main', 'P', false);

if ($arrCount === 1 && $main !== 'true') {
  echo "javascript:document.location = '/data_center/stock?id=" . (int)$rows[0]['id'] . "'";
  exit;
}
if ($arrCount === 0) {
  $template->get('criteria')->replace($criteria_html);
  $template->get('no-results_adv')->unmute();
}
else if (count($pages) > 1) {
  $template->get('pages')->loop($pages);
  $template->get('adv_results-paged')->unmute();
  $template->get('criteria')->replace($criteria_html);
  $template->get('count')->replace($arrCount);
  $template->get('rows')->replace(urlencode(serialize($stockList)));
  $template->get('div')->replace(getCGIParam('div_name', 'GP', 'adv_results'));
  $template->get('cytogenetics')->replace($cytogenetics ? $cytogenetics : 'false');
  if ($arrCountAll > $arrCount) {
    $template->get('countAll')->replace(number_format($arrCountAll));
    $template->get('limit')->replace($search_limit);
    if ($search_limit === (int)$system['search_limit_max']) $template->get('max_limit')->unmute();
    $template->get('results_limited')->toggle();
  }
  $template->get('adv_stock-page-row')->loop(stockAdvancedPage($stockList, 1, $effective_pagesize));
}
else {
  $template->get('adv_results')->unmute();
  $template->get('criteria')->replace($criteria_html);
  $template->get('count')->replace($arrCount);
  $template->get('adv_stock-row')->loop($stockList);
}

$bauplan->publish();


function stockAdvancedEnabled($name) {
  return getCGIParam($name, 'GP', '') === 'true';
}

function stockAdvancedText($value) {
  return substr((string)$value, 0, 120);
}

function stockAdvancedEscape($value) {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function stockAdvancedLookup($DBConn, $table, $id) {
  $allowed = array('person', 'term', 'linkage_group', 'stock', 'karyotypic_variation', 'phenotype');
  if (!in_array($table, $allowed, true)) return 'the selected value';
  $stmt = make_query($DBConn, "SELECT name FROM mgdb.$table WHERE id=:lookup_id", 1, array(':lookup_id' => (int)$id));
  $row = retrieve_row($stmt);
  return $row ? stockAdvancedEscape(trim($row['name'])) : 'the selected value';
}

function stockAdvancedPage($stockList, $start, $end) {
  if ($end < $start) return array();
  return array_slice($stockList, $start - 1, ($end - $start) + 1);
}
