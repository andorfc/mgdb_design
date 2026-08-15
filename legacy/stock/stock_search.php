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

  $datacenter_left = $mgdb->get('stock_search')->get('stock-left');

  // Simple search filter
  $term = getCGIParam("stock_term", "S", '');
  if ($term && $term != '' && $term != '%%') {
    $datacenter_left->get('term')->replace($term);
    $datacenter_left->get('start-search')->unmute();
  }

  $search_limit = getCGIParam("stock_limit", "S", $system['search_limit']);
  if ($search_limit > 0) {
    $datacenter_left->get("limit")->replace($search_limit);
    $datacenter_left->get("limit_checked")->replace("checked");
  }
  $datacenter_left->get("search_limit_max")->replace(number_format($system['search_limit_max']));

  $pagesize = getCGIParam("stock_pagesize", "S", $system['pagesize']);
  if ($pagesize == 0) {
    $pagesize = $system['pagesize']; // can't be 0
  }
  $select = "ps_select$pagesize";
  $datacenter_left->get($select)->replace('selected');
  $datacenter_left->get('pagesize')->unmute();

  // Advanced search filter 
  $datacenter_left->get("search_limit_max")->replace(number_format($system['search_limit_max']));  
  $datacenter_left->get("adv_search_limit_max")->replace(number_format($system['search_limit_max']));  
  $search_limit = getCGIParam("stock_limit", "S", $system['search_limit']);
  if ($search_limit > 0) {
    $datacenter_left->get("limit")->replace($search_limit);
    $datacenter_left->get("limit_checked")->replace("checked");
  }

  $adv_search_limit = getCGIParam("adv_stock_limit", "S", $system['search_limit']);
  if ($search_limit > 0) {
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
  

//////////////////////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////

function getDeveloperOptions($DBConn) {
  $options = '';

  $query = "
    SELECT DISTINCT(a.id), a.name 
    FROM person a, stock b 
    WHERE a.id = b.developer
    ORDER BY a.name";
  $statement = make_query($DBConn, $query,1);
  while ($row = retrieve_row($statement)) {
    $options .= "<option value=\"".$row['id']."\">".$row['name']."</option>\n";
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
    $options .= "<option value=\"".$row['id']."\">".$row['name']."</option>\n";
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
    $options .= "<option value=\"".$row['id']."\">".$row['name']."</option>\n";
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
    $options .= "<option value=\"".$row['id']."\">".$row['name']."</option>\n";
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
    $options .= "<option value=\"".$row['id']."\">".$row['name']."</option>\n";
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
    $options .= "<option value=\"".$row['id']."\">".$row['name']."</option>\n";
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
    $options .= "<option value=\"".$row['id']."\">".$row['name']."</option>\n";
  }
  
  return $options;
}//getParentOptions
?>
