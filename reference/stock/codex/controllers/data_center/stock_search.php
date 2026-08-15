<?PHP
/* file: stock_search.php
 *
 * purpose: display stock data page
 * 
 * Loaded by data_center.php.
 *          Search form submit handled by JavaScript function getSearchData()
 *            in js/search.js.
 *
 * NOTE: template and .css already loaded by data_center.php
 *
 * history:
 *  06/05/12  jportwood  cleaned up and modified for current bauplan
 *  07/21/20  eksc       updated to new paging controls
 */

  $bauplan->includeScript('/js/popcorn.js');
  $bauplan->includeScript('/js/stock.js?v=' . filemtime($system['root_dir'] . '/js/stock.js'));
  $bauplan->head('<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="description" content="Search curated maize genetic stocks, germplasm providers, stock types, phenotypes, and related MaizeGDB resources.">');

  $datacenter_left = $mgdb->get('stock_search')->get('stock-left');

  // Simple search filter
  $term = getCGIParam("stock_term", "GP", getCGIParam("stock_term", "S", ''));
  $datacenter_left->get('term')->replace(htmlspecialchars($term, ENT_QUOTES, 'UTF-8'));
  $datacenter_left->get('term_json')->replace(json_encode($term, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
  $case_sensitive = getCGIParam('stock_case', 'GP', '') || getCGIParam('case', 'GP', '');
  $datacenter_left->get('case_checked')->replace($case_sensitive ? 'checked' : '');
  if ($term && $term != '' && $term != '%%') {
    $datacenter_left->get('start-search')->unmute();
  }

  $search_limit = (int)getCGIParam("stock_limit", "GP", getCGIParam("stock_limit", "S", $system['search_limit']));
  $search_limit = max(1, min((int)$system['search_limit_max'], $search_limit));
  if ($search_limit > 0) {
    $datacenter_left->get("limit")->replace($search_limit);
    $datacenter_left->get("limit_checked")->replace("checked");
  }
  $datacenter_left->get("search_limit_max")->replace(number_format($system['search_limit_max']));

  $pagesize = (int)getCGIParam("stock_pagesize", "GP", getCGIParam("stock_pagesize", "S", $system['pagesize']));
  $pagesize = in_array($pagesize, array(5, 10, 25, 50, 100, 250), true) ? $pagesize : (int)$system['pagesize'];
  $select = "ps_select$pagesize";
  $datacenter_left->get($select)->replace('selected');
  $datacenter_left->get('pagesize')->unmute();

  // Advanced search filter 
  $datacenter_left->get("search_limit_max")->replace(number_format($system['search_limit_max']));  
  $datacenter_left->get("adv_search_limit_max")->replace(number_format($system['search_limit_max']));  
  $search_limit = (int)getCGIParam("stock_limit", "GP", getCGIParam("stock_limit", "S", $system['search_limit']));
  $search_limit = max(1, min((int)$system['search_limit_max'], $search_limit));
  if ($search_limit > 0) {
    $datacenter_left->get("limit")->replace($search_limit);
    $datacenter_left->get("limit_checked")->replace("checked");
  }

  $adv_search_limit = (int)getCGIParam("adv_stock_limit", "GP", getCGIParam("adv_stock_limit", "S", $system['search_limit']));
  $adv_search_limit = max(1, min((int)$system['search_limit_max'], $adv_search_limit));
  if ($adv_search_limit > 0) {
    $datacenter_left->get("adv_limit")->replace($adv_search_limit);
    $datacenter_left->get("adv_limit_checked")->replace("checked");
  }
  
  $DBConn = connect_to_database();

  //Fill Developer options
  $developer_options = getDeveloperOptions($DBConn);
  $datacenter_left->get('developer_options')->replace($developer_options);
  
  //Fill Type options
  $type_options = getTypeOptions($DBConn);
  $datacenter_left->get('type_options')->replace($type_options);
  
  //Fill Linkage options
  $linkage_options = getLinkageOptions($DBConn);
  $datacenter_left->get('linkage_options')->replace($linkage_options);
  
  //Fill Karyotpye options
  $karyotype_options = getKaryotypeOptions($DBConn);
  $datacenter_left->get('karyotype_options')->replace($karyotype_options);
  
  //Fill Phenotype options
  $phenotype_options = getPhenotypeOptions($DBConn);
  $datacenter_left->get('phenotype_options')->replace($phenotype_options);
  
  //Fill Group options
  $group_options = getGroupOptions($DBConn);
  $datacenter_left->get('group_options')->replace($group_options);
  
  //Fill Parent options
  $parent_options = getParentOptions($DBConn);
  $datacenter_left->get('parent_options')->replace($parent_options);

  $dashboard = getStockDashboardData($DBConn);
  $datacenter_left->get('metric_active')->replace(number_format($dashboard['metrics']['active']));
  $datacenter_left->get('metric_types')->replace(number_format($dashboard['metrics']['types']));
  $datacenter_left->get('metric_sources')->replace(number_format($dashboard['metrics']['sources']));
  $datacenter_left->get('stock_type_chart')->replace(htmlspecialchars(json_encode($dashboard['chart']), ENT_QUOTES, 'UTF-8'));
  $datacenter_left->get('stock_type_list')->replace($dashboard['list']);
  

//////////////////////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////

function getDeveloperOptions($DBConn) {
  $options = '';

  $query = "
    SELECT DISTINCT a.id, a.name
    FROM person a
      INNER JOIN stock b ON b.developer = a.id
    ORDER BY a.name";
  $statement = make_query($DBConn, $query,1);
  while ($row = retrieve_row($statement)) {
    $options .= stockOptionHTML($row['id'], $row['name']);
  }
  
  return $options;
}//getDeveloperOptions


function getTypeOptions($DBConn) {
  $options = '';

  $query = "
    Select name, id 
    from term 
    where id in (
      select distinct(type) from stock
    ) 
    order by name";
  $statement = make_query($DBConn,$query,1);
  while ($row = retrieve_row($statement)) {
    $options .= stockOptionHTML($row['id'], $row['name']);
  }

  return $options;
}//getTypeOptions


function getLinkageOptions($DBConn) {
  $options = '';

  $query = "
    select distinct A.NAME, A.id 
    from linkage_group A, stock B, id_num c 
    where A.ID=B.focus_linkage_group AND A.ID=C.ID AND C.CURATION_LVL=0 
    order by A.name";
  $statement = make_query($DBConn,$query,1);
  while ($row = retrieve_row($statement)) {
    $options .= stockOptionHTML($row['id'], $row['name']);
  }

  return $options;
}//getLinkageOptions


function getKaryotypeOptions($DBConn) {
  $options = '';

  $query = "
    select distinct A.NAME, A.ID 
    from karyotypic_variation A, stock_karyotypic_var B, ID_NUM C 
    where A.ID=B.karyotypic_var AND A.ID=C.ID AND C.CURATION_LVL=0 
    order by name";
  $statement = make_query($DBConn,$query,1);
  while ($row = retrieve_row($statement)) {
    $options .= stockOptionHTML($row['id'], $row['name']);
  }
  
  return $options;
}//getKaryotypeOptions


function getPhenotypeOptions($DBConn) {
  $options = '';

  $query = "
    select distinct A.name, A.id 
    from phenotype A, stock_phenotypes B, ID_NUM C 
    where A.id=B.phenotype AND A.ID=C.ID AND C.CURATION_LVL=0 
    order by name";
  $statement = make_query($DBConn,$query,1);
  while ($row = retrieve_row($statement)) {
    $options .= stockOptionHTML($row['id'], $row['name']);
  }

  return $options;
}//getPhenotypeOptions


function getGroupOptions($DBConn) {
  $options = '';

  $query = "
    select distinct A.name, A.id 
    from person A, stock B, ID_NUM C 
    where A.id=B.available_from AND A.ID=C.ID AND C.CURATION_LVL=0
    order by A.name";
  $statement = make_query($DBConn,$query,1);
  while ($row = retrieve_row($statement)) {
    $options .= stockOptionHTML($row['id'], $row['name']);
  }
  
  return $options;
}//getGroupOptions

  
function getParentOptions($DBConn) {
  $options = '';

  $query = "
    SELECT DISTINCT s.name, s.id
    FROM mgdb.stock s
      INNER JOIN mgdb.stock_coeff_parent p ON p.stock1=s.id
      INNER JOIN mgdb.id_num pidn ON pidn.id=s.id
      INNER JOIN mgdb.id_num idn ON idn.id=p.id
    WHERE idn.curation_lvl=0 AND pidn.curation_lvl=0
    ORDER BY s.name";
  $statement = make_query($DBConn,$query,1);
  while ($row = retrieve_row($statement)) {
    $options .= stockOptionHTML($row['id'], $row['name']);
  }
  
  return $options;
}//getParentOptions


function stockOptionHTML($id, $label) {
  return '<option value="' . htmlspecialchars((string)$id, ENT_QUOTES, 'UTF-8') . '">' .
         htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') . "</option>\n";
}//stockOptionHTML


function getStockDashboardData($DBConn) {
  $metrics = array('active' => 0, 'types' => 0, 'sources' => 0);
  $chart = array('labels' => array(), 'values' => array());
  $list = '<p>Category data are currently unavailable.</p>';

  $dashboard_sql = "
    WITH active AS MATERIALIZED (
      SELECT s.type, s.available_from
      FROM mgdb.stock s
        INNER JOIN mgdb.id_num i ON i.id=s.id
      WHERE i.type_term=26 AND i.curation_lvl=0
    )
    SELECT COALESCE(t.name, 'Unclassified') AS label,
           COUNT(*) AS total,
           (SELECT COUNT(*) FROM active) AS active,
           (SELECT COUNT(DISTINCT type) FROM active) AS types,
           (SELECT COUNT(DISTINCT available_from) FROM active) AS sources
    FROM active a
      LEFT JOIN mgdb.term t ON t.id=a.type
    GROUP BY COALESCE(t.name, 'Unclassified')
    ORDER BY COUNT(*) DESC, COALESCE(t.name, 'Unclassified')
    LIMIT 8";
  $dashboard_stmt = make_query($DBConn, $dashboard_sql, 1);
  $items = array();
  if ($dashboard_stmt) {
    while ($row = retrieve_row($dashboard_stmt)) {
      if (!$chart['labels']) {
        foreach ($metrics as $key => $unused) {
          $metrics[$key] = isset($row[$key]) ? (int)$row[$key] : 0;
        }
      }
      $label = trim((string)$row['label']);
      $total = (int)$row['total'];
      $chart['labels'][] = $label;
      $chart['values'][] = $total;
      $items[] = '<li><span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span><strong>' . number_format($total) . '</strong></li>';
    }
  }
  if ($items) {
    $list = '<h4>Category values</h4><ul>' . implode('', $items) . '</ul>';
  }

  return array('metrics' => $metrics, 'chart' => $chart, 'list' => $list);
}//getStockDashboardData
?>
