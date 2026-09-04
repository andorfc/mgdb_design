<?PHP
/* file: gene_search.php
 *
 * purpose: display gene/gene model search page
 *
 *          Executed by gene_center.php with the Bauplan template already
 *          loaded into the variable $tmpl.
 *
 * history:
 *   07/11/12  jportwood - created
 *   08/21/19  eksc  converted to new simple search UI
 */
  include_once('./include/db-api.php');
  include_once('./include/gene_center_lib.php');
  
  $DBConn = connect_to_database();
    
  ////// Simple search filter //////
  
  $term = getCGIParam("gene_term", "S", '');
  $mgdb->get('gene_term')->replace($term);

  $search_limit = getCGIParam("gene_limit", "S", $system['search_limit']);
  $tmpl->get("limit")->replace($search_limit);
  $tmpl->get("limit_checked")->replace("checked");
  $tmpl->get("search_limit_max")->replace(number_format($system['search_limit_max']));
  
  $pagesize = getCGIParam("gene_pagesize", "S", $system['pagesize']);
  if ($pagesize == 0) {
    $pagesize = $system['pagesize']; // can't be 0
  }
  $select = "ps_select$pagesize";
  $mgdb->get($select)->replace('selected');

  if ($term && $term != '' && $term != '%%') {
    $mgdb->get('start-search')->unmute();
  }

  $pagesize = getCGIParam("gene_pagesize", "S", $system['pagesize']);
  if ($pagesize == 0) {
    $pagesize = $system['pagesize']; // can't be 0
  }
  $select = "ps_select$pagesize";
  $mgdb->get($select)->replace('selected');
  $mgdb->get('pagesize')->unmute();

  ////// Advanced search //////
  
  $adv_search_limit = getCGIParam("adv_gene_limit", "S", $system['search_limit']);
  if ($search_limit > 0) {
    $tmpl->get("adv_limit")->replace($adv_search_limit);
    $tmpl->get("adv_limit_checked")->replace("checked");
    $tmpl->get("adv_search_limit_max")->replace(number_format($system['search_limit_max']));
  }
  
  // Fill annotation dropdowns that require gene models in chado
  $gm_sets = getGeneModelSetswRecs($DBConn, true);  // true: get assembly version too
  $gm_set_options = makeGeneSetOptions($gm_sets);
  $tmpl->get('annotation-list')->replace($gm_set_options);
  $gm_set_options = makeGeneSetOptions($gm_sets, false);  // false: don't include annotation
  $tmpl->get('position-assembly-list')->replace($gm_set_options);
  
  // Fill annotation dropdowns that require ALL annotations
  $gms = getGeneModelSets($DBConn, true);  // true: get assembly version too
  $gm_set_options = makeGeneSetOptions($gms);
  $tmpl->get('assembly-list')->replace($gm_set_options);
  
  // Gene model types
  $gmbs = getLocusAssociatedGeneModelTypes($DBConn);
  $gm_type_options = '';
  foreach ($gmbs as $t) {
    $gm_type_options .= "
      <option value=\"".$t['value'].'">' . $t['value'] . '</option>';
  }//each gene model set
  $tmpl->get('gm_type_options')->replace($gm_type_options);
  
  
  
  // Fill gene product dropdown
  $sql = "
    SELECT DISTINCT gp.id AS gp_id, gp.name AS gp_name
    FROM gene_product gp
      INNER JOIN locus_gene_products lgp ON lgp.gene_product=gp.id
      INNER JOIN locus l ON l.id=lgp.id
      INNER JOIN id_num ON id_num.id=gp.id
    WHERE id_num.curation_lvl = 0
          AND gp.name NOT LIKE '?%'
    ORDER BY gp.name";
  $sth = make_query($DBConn, $sql);
  $rows = get_all_rows($sth);
  $gp_options = '';
  foreach ($rows as $row) {
    $gp_options .= "<option value=\"" . $row['gp_id'] . "\">";
    $gp_options .= $row['gp_name'] . "</option>\n";
  }
  $tmpl->get('gene_product-list')->replace($gp_options);

  // Fill in phenotype dropdown
  $sql = "
    SELECT pheno_id, pheno_name
    FROM mgdb.locus_phenotypes
    ORDER BY pheno_name";
  $sth = make_query($DBConn, $sql);
  $rows = get_all_rows($sth);
  $pheno_options = '';
  foreach ($rows as $row) {
    $pheno_options .= "<option value=\"" . $row['pheno_id'] . "\">";
    $pheno_options .= $row['pheno_name'] . "</option>\n";
  }
  $tmpl->get('gene_phenotype-list')->replace($pheno_options);

  // Fill in trait dropdown
  $sql = "
    SELECT id, name 
    FROM mgdb.locus_traits
    ORDER BY name";
  $sth = make_query($DBConn, $sql);
  $rows = get_all_rows($sth);
  $trait_options = '';
  foreach ($rows as $row) {
    $trait_options .= "<option value=\"" . $row['id'] . "\">";
    $trait_options .= $row['name'] . "</option>\n";
  }
  $tmpl->get('gene_trait-list')->replace($trait_options);
  
  ///// BLAST search ////
  
  // Fill in BLAST table target dropdown
  $sql = "
    SELECT bc.name AS blast_name, bc.source AS blast_source, 
           bc.db_name AS blast_db_name 
    FROM pc_blast_ctl bc 
      INNER JOIN id_num ON id_num.id=bc.id 
      INNER JOIN pc_assoc_category ac ON ac.id=bc.id 
      INNER JOIN pc_category cat ON ac.category_id=cat.id
    WHERE cat.name='Gene models' AND id_num.curation_lvl=0
    ORDER BY bc.name";
  $sth = make_query($DBConn, $sql);
  $rows = get_all_rows($sth);
  $blast_target_options = '';
  foreach ($rows as $row) {
    $blast_target_options .= '<option value="' . $row['blast_source'] . '|'
                           . $row['blast_db_name']. '">'
                           . $row['blast_name'] . "</option>\n";
  }
  $tmpl->get('blast-target-options')->replace($blast_target_options);  
  
/* Never worked as planned
  // Top 20 gene models
  showTop20Genes($tmpl);
*/


/////////////////////////////////////////////////////////////////////////////////////////

function makeGeneSetOptions($gm_sets, $with_annotation=true) {
//logVarDump($gm_sets, "Make options with gene models:\n");
  $gm_set_options = array();
  foreach ($gm_sets as $s) {
    // These are not fully supported:
    if ($s['name'] == '4a' || $s['name']== '5b') {
      continue;
    }
    if (!$with_annotation) {
      $gm_text = $s['assembly_version'];
    }
    else if (isset($s['assembly_version'])) {
      $gm_text = ($s['assembly_version'] == '') 
               ? $s['name'] : $s['name'] . ' - ' . $s['assembly_version'];
    }
    else {
      $gm_text = $s['name'];
    }
    
    if ($with_annotation) {
      $selected = ($s['name'] == $system['cur_gm_set']) ? 'selected' : '';
      $gm_set_options[] = "<option $selected value=\"".$s['name']."\">$gm_text</option>";
    }
    else {
      $gm_set_options[] = "<option $selected value=\"".$s['assembly_version']."\">$gm_text</option>";
    }
    
    // group related sets (only true for Zmdddd.<version> identifiers)
    if (preg_match("/(.*?)\./", $s['name'], $matches)) {
      $op_str = $matches[0] . '%';
    }
    else {
      $op_str = $s['name'];
    }
  }//each gene model set

  $gm_set_options = array_unique($gm_set_options);
  if (!$with_annotation) {
    asort($gm_set_options);
  }
  
  return implode("\n", $gm_set_options);
}//makeGeneSetOptions



?>
