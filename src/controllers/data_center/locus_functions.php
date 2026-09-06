<?PHP
/* file: locus_functions.php
 *
 * purpose: collections of functions required by data_center.php for locus
 *          data type.
 *
 * history:
 *      ?? created by Carson (and Bahvani?)
 *   05/31/12  eksc  modified for postgres
 */

include_once('./include/gene_center_lib.php');

function check_id($id, $DBConn) {
  $lid = trim(strtolower($id));
  $lid2 = trim(strtolower($id));
  $iid = intval($id);

  if (!$id || trim($id) == '') {
    // No id or blank id: fail
    return false;
  }
  
  // Return hash of identifiers or false if $id not found
  $ret = false;  // fail until succeeding

/* remove species limitation on search (species 12808 = Zea mays ssp. mays)
  $query = "
    SELECT a.id 
    FROM locus a 
      JOIN id_num b ON a.ID = b.id 
    WHERE SPECIES = '12808'   
          AND (b.CURATION_LVL =0) 
          AND (a.ID = $iid OR LOWER(NAME) = '$lid' OR LOWER(NAME) = '$lid2')";
*/
  $query = "
    SELECT l.id FROM locus l 
      INNER JOIN id_num idn ON l.ID = idn.id 
    WHERE idn.curation_lvl=0 
          AND (l.id = $iid OR LOWER(l.name) = '$lid' OR LOWER(l.name) = '$lid2')";
  $statement = make_query($DBConn, $query);
  $row = retrieve_row($statement);
  if (!$row) {
     // check synonyms
     $querysyn1 = "
       SELECT s.id FROM synonyms s 
         INNER JOIN id_num idn ON s.id = idn.id 
         INNER JOIN locus l ON l.id=s.id
       WHERE idn.curation_lvl = 0 AND s.synonyms ='$id'
             AND (l.id = $iid OR LOWER(l.name) = '$lid' OR LOWER(l.name) = '$lid2')";
    $statementsyn1 = make_query($DBConn, $querysyn1);
    if ($row = retrieve_row($statementsyn1)) {
      $iid = intval($row["id"]);
    }
  }
  
  if ($row) {
/* remove species limitation on search (species 12808 = Zea mays ssp. mays)
    $query = "
      SELECT id, name 
      FROM locus 
      WHERE (ID = $iid OR LOWER(NAME) = '$lid' OR LOWER(NAME) = '$lid2')
            AND SPECIES = '12808' ";
*/

    $query = "
      SELECT l.id, l.name, t.name AS type
      FROM locus l
        LEFT OUTER JOIN term t ON t.id=l.type
      WHERE (l.id = $iid OR LOWER(l.name) = '$lid' OR LOWER(l.name) = '$lid2')";
    $statement = make_query($DBConn, $query);
    $locusrow = retrieve_row($statement);
    
    if (isset($locusrow['id']) && $locusrow['id'] != '') {
      $id = $locusrow['id'];
      $name = $locusrow['name'];
      $type = $locusrow['type'];
    
      $query_public = "SELECT curation_lvl FROM id_num WHERE id = $iid";
      $statement_public = make_query($DBConn, $query_public);
      $arrPub = retrieve_row($statement_public);
      if ($arrPub["curation_lvl"] == "0") {
        $ret = array('ID' => $id, 'NAME' => $name, 'TYPE' => $type);
      }
    }
  }//Found locus record
  
  if ($ret) {
    // Loci of type 'gene' should be displayed on the gene record page
    //// See if there is a corresponding gene model
    //if ($gm_match = getLocusAssociatedGeneModel($id, $DBConn)) {
    if ($ret['TYPE'] == 'Gene') {
      // this locus is associated with a gene model; display in gene center
      logMessage("Locus has a gene model; redirecting to gene center");
      header("Location: /gene_center/gene/$id");
    }
  }//locus was found
  
  return $ret;
}


function get_nav_array() {
  return array(
    /*array('nav_name' => 'Quick Summary',
          'nav_id0' => 'quick_summary',
          'is_checked' => 'checked'
    ),*/
    array('nav_name' => 'Overview',
          'nav_id0' => 'overview',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Annotations',
          'nav_id0' => 'annotations',
          'is_checked' => 'checked'
    ),
//Removed by request from Ed Coe
//    array('nav_name' => 'Chromosome Coordinates',
//          'nav_id0' => 'chrcoords',
//          'is_checked' => 'checked'
//    ),
    array('nav_name' => 'Map Coordinates',
          'nav_id0' => 'map',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Nearby Loci',
          'nav_id0' => 'nearby',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Allele/variation/polymorphism',
          'nav_id0' => 'alleles',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Molecular information',
          'nav_id0' => 'molecular',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Genetic information',
          'nav_id0' => 'genetic',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'References',
          'nav_id0' => 'references',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'External Links',
          'nav_id0' => 'external',
          'is_checked' => 'checked'
    ),
   );
}

function get_section_array() {
  return array(
  /*  array('color1' => 'lite_green',
          'section_name' => 'Quick Summary',
          'dom_id1' => 'quick_summary',
          'dom_var' => 'quick_summary_cal'
    ),*/
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
//Removed by request from Ed Coe
//    array('color1' => 'lite_grey',
//          'section_name' => 'Chromosome Coordinates',
//          'dom_id1' => 'chrcoords',
//          'dom_var' => 'chrcoords_cal'
//    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Map Coordinates',
          'dom_id1' => 'map',
          'dom_var' => 'map_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Nearby Loci',
          'dom_id1' => 'nearby',
          'dom_var' => 'nearby_cal'
    ), 
    array('color1' => 'lite_blue',
          'section_name' => 'Allele/variation/polymorphism',
          'dom_id1' => 'alleles',
          'dom_var' => 'alleles_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Molecular information',
          'dom_id1' => 'molecular',
          'dom_var' => 'molecular_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Genetic information',
          'dom_id1' => 'genetic',
          'dom_var' => 'genetic_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'References',
          'dom_id1' => 'references',
          'dom_var' => 'references_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'External Links',
          'dom_id1' => 'external',
          'dom_var' => 'external_cal'
    ), 
  );
}  

?>
