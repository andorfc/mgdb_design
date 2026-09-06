<?PHP
/* file: term_functions.php
 *
 * purpose: helper functions for displaying a term record.
 *
 * history:
 *   03/05/13  jportwood  created 
 */
 
function check_id($id, $DBConn) {
  if (!$id || trim($id) == '') {
    // No id or blank id: fail
    return false;
  }
  
  if (!is_int($id)) {
    //ID could be a trait name linked from JBrowse
    $id = check_gwas_trait($id);
  }  

  // Return hash of identifiers or false if $id not found
  $ret = false;  // fail until succeeding

  $iid = intval($id);

  $query = "
  SELECT NAME 
  FROM term tm, id_num idn
  WHERE tm.ID = $id AND tm.ID = idn.id AND idn.CURATION_LVL = 0";
  $statement = make_query($DBConn,$query);
  
  if ($statement && $arrRecord = retrieve_row($statement)) {
    $ret = array('ID'  => $iid,
                'NAME' => $arrRecord['name']);
  }
  
  return $ret;  
}//check_id


function get_nav_array() {
  return array(
    array('nav_name' => 'Overview',
          'nav_id0' => 'overview',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Annotations',
          'nav_id0' => 'annotations',
          'is_checked' => 'checked'
    ),
  );
}//get_nav_array


function get_section_array() {

  return array(
    array('color1' => 'lite_grey',
          'section_name' => 'Overview',
          'dom_id1' => 'overview',
          'dom_var' => 'overview_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Annotations',
          'dom_id1' => 'annotations',
          'dom_var' => 'annotations_cal'
    ),
  );
}//get_section_array


/**
 * This function links trait names from the GWAS tracks on JBrowse to internal MGDB IDs.
 * Performing an ID lookup here allows us to create links on JBrowse without having to add the IDs 
 * to 52 separate GFF3 files @_@
 */
function check_gwas_trait($id) { 
  switch($id) {
    case 'Anthesis-silking_interval':
        return 2772843;
    case 'Average_internode_length_(above_ear)':
        return 9043110;
    case 'Average_internode_length_(below_ear)':
        return 9043111;
    case 'Average_internode_length_(whole_plant)':
        return 78110;
    case 'Boxcox-transformed_leaf_angle':
        return 9043112;
    case 'Chlorophyll_A':
        return 3229904;
    case 'Chlorophyll_B':
        return 3229905;
    case 'Cob_diameter':
        return 82753;
    case 'Days_to_anthesis':
        return 3100328;
    case 'Days_to_silk':
        return 2772845;
    case 'Ear_height':
        return 61369;
    case 'Ear_row_number':
        return 51580;
    case 'Fructose':
        return 3229906;
    case 'Fumarate':
        return 3229907;
    case 'Glucose':
        return 3229908;
    case 'Glutamate':
        return 3229909;
    case 'Height_above_ear':
        return 134020;
    case 'Height_per_day_(until_flowering)':
        return 9043113;
    case 'Kernel_weight':
        return 78154;
    case 'Leaf_length':
        return 61715;
    case 'Leaf_width':
        return 61714;
    case 'Malate':
        return 3229910;
    case 'Nitrate':
        return 3229911;
    case 'Nodes_above_ear':
        return 9024931;
    case 'Nodes_per_plant':
        return 3100544;
    case 'Nodes_to_ear':
        return 3094810;
    case 'Northern_Leaf_Blight':
        return 9031215;
    case 'PCA_of_metabolites:_PC1':
        return 9043360;
    case 'PCA_of_metabolites:_PC2':
        return 9043361;
    case 'Photoperiod_growing-degree_days_to_anthesis':
        return 9022924;
    case 'Photoperiod_Growing-degree_days_to_silk':
        return 9024964;
    case 'Plant_height':
        return 3097755;
    case 'Protein_(total)':
        return 3229912;
    case 'Ratio_of_ear_height_to_total_height':
        return 3229903;
    case 'Southern_leaf_blight':
        return 9031254;
    case 'Stalk_strength':
        return 9024978;
    case 'Starch':
        return 3229901;
    case 'Sucrose':
        return 3229902;
    case 'Tassel_branch_number':
        return 61719;
    case 'Tassel_length':
        return 61713;
    case 'Total_amino_acids':
        return 3229913;
  }
  
  return $id; //Error invalid GWAS trait
}

?>
