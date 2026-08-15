<?PHP
/* file: stock_functions.php
 *
 * purpose: helper functions for displaying a stock record.
 *
 * history:
 *   07/02/12  jportwood - created from old website code
 *   10/26/15  bbraun - added functions from stock_search.php so breeders_toolbox can use them as well
 */

function getDeveloperOptions($DBConn) {
  $options = '';

  $query = "
    SELECT DISTINCT(a.id), a.name
    FROM person a, stock b
    WHERE a.id = b.developer
    ORDER BY a.name";
  $statement = make_query($DBConn, $query);
  while ($row = retrieve_row($statement)) {
    $options .= "<option value=\"".$row['id']."\">".$row['name']."</option>\n";
  }

  return $options;
}//getDeveloperOptions


function getTypeOptions($DBConn) {
  $options = '';

  $query = "
    SELECT name, id
    FROM term
    WHERE id in (
      SELECT DISTINCT(type) FROM stock
    )
    ORDER BY name";
  $statement = make_query($DBConn, $query);
  while ($row = retrieve_row($statement)) {
    $options .= "<option value=\"".$row['id']."\">".$row['name']."</option>\n";
  }

  return $options;
}//getTypeOptions


function getLinkageOptions($DBConn) {
  $options = '';

  $query = "
    SELECT DISTINCT a.name, a.id
    FROM linkage_group a, stock b, id_num c
    WHERE a.id=b.focus_linkage_group AND a.id=c.id AND c.curation_lvl=0
    ORDER BY a.name";
  $statement = make_query($DBConn, $query);
  while ($row = retrieve_row($statement)) {
    $options .= "<option value=\"".$row['id']."\">".$row['name']."</option>\n";
  }

  return $options;
}//getLinkageOptions


function getKaryotypeOptions($DBConn) {
  $options = '';

  $query = "
    SELECT DISTINCT a.name, a.id
    FROM karyotypic_variation a, stock_karyotypic_var b, id_num c
    WHERE a.id=b.karyotypic_var AND a.id=c.id AND c.curation_lvl=0
    ORDER BY name";
  $statement = make_query($DBConn,$query,1);
  while ($row = retrieve_row($statement)) {
    $options .= "<option value=\"".$row['id']."\">".$row['name']."</option>\n";
  }

  return $options;
}//getKaryotypeOptions


function getPhenotypeOptions($DBConn) {
  $options = '';

  $query = "
    SELECT DISTINCT a.name, a.id
    FROM phenotype a, stock_phenotypes , id_num c
    WHERE a.id=b.phenotype AND a.id=c.id AND c.curation_lvl=0
    ORDER BY name";
  $statement = make_query($DBConn,$query,1);
  while ($row = retrieve_row($statement)) {
    $options .= "<option value=\"".$row['id']."\">".$row['name']."</option>\n";
  }

  return $options;
}//getPhenotypeOptions


function getGroupOptions($DBConn) {
  $options = '';

  $query = "
    SELECT DISTINCT a.name, a.id
    FROM PERSON a, stock b, id_num c
    WHERE a.id=b.available_from AND a.id=c.id AND c.curation_lvl=0
    ORDER BY a.name";
  $statement = make_query($DBConn,$query,1);
  while ($row = retrieve_row($statement)) {
    $options .= "<option value=\"".$row['id']."\">".$row['name']."</option>\n";
  }

  return $options;
}//getGroupOptions


function getParentOptions($DBConn) {
  $options = '';

  $query = "
    SELECT DISTINCT a.name, a.id
    FROM stock a, stock_coeff_parent b, id_num c
    WHERE a.id=b.stock1 AND a.id=c.id AND c.curation_lvl=0
    ORDER BY name";
  $statement = make_query($DBConn,$query,1);
  while ($row = retrieve_row($statement)) {
    $options .= "<option value=\"".$row['id']."\">".$row['name']."</option>\n";
  }

  return $options;
}//getParentOptions


function check_id($id, $DBConn) {
  if (!$id || trim($id) == '') {
    // No id or blank id: fail
    return false;
  }

  // Return hash of identifiers or false if $id not found
  $ret = false;  // fail until succeeding

  $iid = (is_numeric($id)) ? intval($id) : 0;
  $name = (!is_numeric($id)) ? urldecode($id) : '';

  $query = "
    SELECT s.id, s.name, idn.curation_lvl 
    FROM mgdb.stock s 
      JOIN id_num idn ON s.id = idn.id 
      LEFT OUTER JOIN ext_db_key x ON x.id=s.id
        AND x.db_person = (SELECT id FROM person WHERE name='GRIN')
    WHERE idn.curation_lvl IN (0, 101) 
          AND (s.id=$iid OR s.name='$name' OR x.key='$name')";
  $statement = make_query($DBConn,$query);
  $arrRecord = retrieve_row($statement);

  if ($arrRecord)
    $ret = array('ID'   => $arrRecord['id'],
                 'NAME' => $arrRecord['name'],
                 'CURATION_LVL' => $arrRecord['curation_lvl']);

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
    array('nav_name' => 'Related Records',
          'nav_id0' => 'related_records',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'GRIN Information',
          'nav_id0' => 'grin_information',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Offsite Resources',
          'nav_id0' => 'offsite_resources',
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
    array('color1' => 'lite_grey',
          'section_name' => 'Related Records',
          'dom_id1' => 'related_records',
          'dom_var' => 'related_records_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'GRIN Information',
          'dom_id1' => 'grin_information',
          'dom_var' => 'grin_information_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Offsite Resources',
          'dom_id1' => 'offsite_resources',
          'dom_var' => 'offsite_resources'
    ),
  );
}//get_section_array

?>
