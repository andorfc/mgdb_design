#!/usr/local/bin/php
<?php
/*
 * file: lib_curation_utils.php
 *
 * purpose: Utilities library for POPcorn curation.
 *
 * history:
 *  09/23/09  eksc  created
 *  01/25/10  eksc  modified for combined code base
 */
 
  include_once("../inc/lib.php");
  include_once("../inc/lib_db.php");
  include_once("curation_defs.php");


  function utilGetStandaloneResources() {
    $results = array();

    $conn = connectToCurationDB();
    
    // All resources NOT associated with a project which is not trashed
    $sql = "SELECT PR.ID, PR.NAME, PR.DESCRIPTION, PR.URL, I.CURATION_LVL
            FROM PC_RESOURCE PR, ID_NUM I
            WHERE PR.ID=I.ID AND I.CURATION_LVL < 99
            EXCEPT
            SELECT PR.ID, PR.NAME, PR.DESCRIPTION, PR.URL, I.CURATION_LVL
            FROM PC_RESOURCE PR, ID_NUM I, PC_ASSOCIATION PA 
            WHERE PR.ID=I.ID AND I.CURATION_LVL < 99 AND PR.ID=PA.ID2";
    $res = makeQuery_oracle($conn, $sql);
    while ($row = retrieveRow($res)) {
      // Weed out resources with no categories (these are orphans)
      $check_sql = "SELECT COUNT(*) AS NUM FROM PC_ASSOC_CATEGORY 
              WHERE ID=" . $row['id'];
      $check_res = makeQuery_oracle($conn, $check_sql);
      $check_row = retrieveRow($check_res);
      if ($check_row['NUM'] > 0) {
        array_push($results, buildResultArray($row));
      }
    }
    
    disconnectFromDatabase($conn);
    return $results;
  }//utilGetStandaloneResources()


  function utilGetOrphanedResources () {
    $results = array();

    $conn = connectToCurationDB();
    
    // All resources with neither project nor category associations
    $sql = "SELECT PR.ID, PR.NAME, PR.DESCRIPTION, PR.URL, I.CURATION_LVL
            FROM PC_RESOURCE PR, ID_NUM I
            WHERE PR.ID=I.ID AND I.CURATION_LVL < 99
            EXCEPT
            SELECT PR.ID, PR.NAME, PR.DESCRIPTION, PR.URL, I.CURATION_LVL
            FROM PC_RESOURCE PR, ID_NUM I, PC_ASSOCIATION PA 
            WHERE PR.ID=I.ID AND I.CURATION_LVL < 99 AND PR.ID=PA.ID2";
    $res = makeQuery_oracle($conn, $sql);
    while ($row = retrieveRow($res)) {
      // Keep only resources with no categories (these are orphans)
      $check_sql = "SELECT COUNT(*) AS NUM FROM PC_ASSOC_CATEGORY 
              WHERE ID=" . $row['id'];
      $check_res = makeQuery_oracle($conn, $check_sql);
      $check_row = retrieveRow($check_res);
      if ($check_row['NUM'] == 0) {
        array_push($results, buildResultArray($row));
      }
    }
    
    disconnectFromDatabase($conn);
    return $results;
  }//utilGetOrphanedResources()
  
  
  function utilGetDependentResources () {
    $results = array();

    $conn = connectToCurationDB();
    
    // All resources associated with projects but not categories
    $sql = "SELECT PR.ID, PR.NAME, PR.DESCRIPTION, PR.URL, I.CURATION_LVL
            FROM PC_RESOURCE PR, ID_NUM I, PC_ASSOCIATION PA 
            WHERE PR.ID=I.ID AND I.CURATION_LVL < 99 AND PR.ID=PA.ID2
            EXCEPT
            SELECT DISTINCT PR.ID, PR.NAME, PR.DESCRIPTION, PR.URL, I.CURATION_LVL
            FROM PC_RESOURCE PR, ID_NUM I, PC_ASSOC_CATEGORY PC 
            WHERE PR.ID=I.ID AND I.CURATION_LVL < 99 AND PR.ID=PC.ID";
    $res = makeQuery_oracle($conn, $sql);
    while ($row = retrieveRow($res)) {
      // Keep only resources with no categories (these are orphans)
      $check_sql = "SELECT COUNT(*) AS NUM FROM PC_ASSOC_CATEGORY 
              WHERE ID=" . $row['id'];
      $check_res = makeQuery_oracle($conn, $check_sql);
      $check_row = retrieveRow($check_res);
      if ($check_row['NUM'] == 0) {
        array_push($results, buildResultArray($row));
      }
    }
    
    disconnectFromDatabase($conn);
    return $results;
  }//utilGetDependentResources()
  
  
  function buildResultArray($row) {
    $result = array();
    $result['ID']           = $row['id'];
    $result['NAME']         = truncate($row['name'], 45);
    $result['DESCRIPTION']  = truncate($row['deescription'], 45);
    $result['URL']          = $row['url'];
    $result['CURATION_LVL'] = levelToString($row['curation_lvl']);
    
    return $result;
  }//buildResultArray()
  
  
  function utilDisplayResources($results) {
    // To alternate row colors
    $colors = array('#ffffff', '#f0f0f0');
    $which_color = 0;

    echo "
      <table>
        <tr bgcolor=\"#000000\" height=2><td colspan=4></tr>
        <tr>
          <td>
            <b>ID</b> <span class=\"definition\">(edit)</span>
          </td>
          <td>
            <b>Name</b> <span class=\"definition\">(go to URL)</span>
          </td>
          <td><b>Description</b></td>
          <td><b>Curation Level</b></td>
        </tr>
        <tr bgcolor=\"#000000\" height=2><td colspan=4></tr>";
        
    foreach ($results as $result) {
      $color = $colors[$which_color++ % 2];
      $edit_url = "javascript:";
      $edit_url .= 'editRecord(document.curationform, ' 
                               . $result['id'] . ', ' 
                               . CUR_EDIT_RESOURCE_2 . ')';
      echo "
        <tr bgcolor=\"$color\">
          <td>
            <a href=\"$edit_url\">" . $result['id'] . "</a>
          </td>
          <td>
            <a href=\"" . $result['url'] . "\" target=\"site\">" 
              . $result['name'] . "</a>
          </td>
          <td>" . $result['description'] . "</td>
          <td>" . $result['curation_lvl'] . "</td>
        </tr>";
    }
    
    echo "
      </table>\n";
  }//utilDisplayResources()
  
  
  function utilDisplayResourceCategories() {
    $conn = connectToCurationDB();
    
    // Get all resources which are not trashed
    $sql = "SELECT DISTINCT PR.ID, PR.NAME, PR.DESCRIPTION, PR.URL, I.CURATION_LVL
            FROM PC_RESOURCE PR, ID_NUM I 
            WHERE PR.ID=I.ID AND I.CURATION_LVL < 99";
    $res = makeQuery_oracle($conn, $sql);
    utilDisplayRecordCategories($conn, $res, true);  // true: is a resource
    
    disconnectFromDatabase($conn);
  }//utilDisplayResourceCategories()


  function utilDisplayProjectCategories() {
    $conn = connectToCurationDB();
    
    // Get all resources which are not trashed
    $sql = "SELECT DISTINCT PP.ID, PP.NAME, PP.DESCRIPTION, I.CURATION_LVL
            FROM PC_PROJECT PP, ID_NUM I 
            WHERE PP.ID=I.ID AND I.CURATION_LVL < 99";
    $res = makeQuery_oracle($conn, $sql);
    utilDisplayRecordCategories($conn, $res, false); // false: not a resource
    
    disconnectFromDatabase($conn);
  }//utilDisplayResourceCategories()


  function utilDisplayRecordCategories($conn, $res, $is_res) {
    // To alternate row colors
    $colors = array('#ffffff', '#f0f0f0');
    $which_color = 0;
    
    $id_inst = "<span class=\"definition\">(edit)</span>";
    $name_inst = ($is_res) 
                    ? "<span class=\"definition\">(go to URL)</span>" : '';
    echo "
      <table>
        <tr bgcolor=\"#000000\" height=2><td colspan=5></tr>
        <tr>
          <td><b>ID</b> $id_inst</td>
          <td><b>Name</b> $name_inst</td>
          <td><b>Description</b></td>
          <td><b>Curation level</b></td>
          <td><b>Categories</b></td>
        </tr>
        <tr bgcolor=\"#000000\" height=2><td colspan=5></tr>";
        
    while ($row = retrieveRow($res)) {
      // Get this resource's categories
      $categories = array();
      $cat_sql = "SELECT DISTINCT C.NAME
                  FROM PC_ASSOC_CATEGORY PC, PC_CATEGORY C
                  WHERE PC.ID=" . $row['id'] . " AND PC.CATEGORY_ID=C.ID";
      $cat_res = makeQuery_oracle($conn, $cat_sql);
      while ($cat_row = retrieveRow($cat_res)) {
        array_push($categories, $cat_row['NAME']);
      }
      $category_str = join(', ', $categories);
      
      if ($category_str == '') {
        $category_str = "<i>NONE</i>";
      }

      $edit_url = "javascript:";
      $edit_url .= 'editRecord(document.curationform, ' 
                               . $result['id'] . ', ' 
                               . CUR_EDIT_RESOURCE_2 . ')';
      $name_link = ($is_res) 
                      ? "<a href=\"" . $row['URL'] . "\" target=\_blank\">"
                        . truncate($row['NAME'], 45) . "</a>"
                      : truncate($row['NAME'], 45);

      $color = $colors[$which_color++ % 2];
      echo "
          <tr bgcolor=\"$color\">
            <td valign=\"top\"><a href=\"$edit_url\">" . $row['id'] . "</a></td>
            <td valign=\"top\">$name_link</td>
            <td valign=\"top\">" . mgdb_safe_html(truncate($row['DESCRIPTION'], 45)) . "</td>
            <td valign=\"top\">" . levelToString($row['CURATION_LVL']) . "</td>
            <td valign=\"top\">$category_str</td>
          </tr>";
    }//foreach resource
    
    echo "
      </table>";
  }//utilDisplayRecordCategories()

?>
