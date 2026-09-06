<?php
/*
 * file: lib_curation_UI.php
 *
 * purpose: UI functions for POPcorn curation.
 *
 * history:
 *  06/11/09  eksc  created
 *  01/25/10  eksc  modified for combined code base
 */
 
  include_once('../inc/lib.php');
  include_once('curation_defs.php');
  include_once('lib_curation.php');
  
  
  function chooseAnAction() {
    echo "
  <table width=\"100%\" border=0>
    <tr>
      <td>";
    
    // Show the menu
    echo "
      <table>
        <tr>
          <td bgcolor=\"#f3f3f3\" colspan=2>
          <div style=\"border:2px solid black\">
            <table>
              <tr>
                <td colspan=2>
                  <span class=\"emphasize\">Choose&nbsp;an&nbsp;action:</span>
                </td>
              </tr>
              <tr height=\"10px\"><td width=\"10px\"></td><td></td></tr>\n";
    
    // RESOURCES
    echo "              
              <tr>
                <td colspan=2>Resources</td>
              </tr>
              <tr>
                <td></td>
                <td>
                  <a href=\"javascript:goToStage("
                    . CUR_ADD_RESOURCE_1 . ")\">Add&nbsp;a&nbsp;resource</a>
                </td>
              <tr>
                <td></td>
                <td>
                  <a href=\"javascript:goToStage("
                    . CUR_EDIT_RESOURCE_1 . ")\">Edit&nbsp;resource/change&nbsp;curation&nbsp;lvl</a>
                </td>
              </tr>
              <tr height=\"5px\"><td></td></tr>\n";
    // PROJECTS
    echo "
              <tr>
                <td colspan=2>Projects</td>
              </tr>
              <tr>
                <td></td>
                <td>
                  <a href=\"javascript:goToStage("
                    . CUR_ADD_PROJECT_1 . ")\">Add&nbsp;project</a>
                </td>
              </tr>
              <tr>
                <td></td>
                <td>
                  <a href=\"javascript:goToStage("
                    . CUR_EDIT_PROJECT_1 . ")\">Edit&nbsp;project/change&nbsp;curation&nbsp;lvl</a>
                </td>
              </tr>
              <tr height=\"5px\"><td></td></tr>\n";

    // CATEGORIES
    echo "              
              <tr>
                <td colspan=2>Categories</td>
              </tr>
              <tr>
                <td></td>
                <td>
                  <a href=\"javascript:goToStage("
                    . CUR_ADD_CATEGORY_1 . ")\">Add&nbsp;a&nbsp;category</a>
                </td>
              </tr>
              <tr>
                <td></td>
                <td>
                  <a href=\"javascript:goToStage("
                    . CUR_EDIT_CATEGORY_1 . ")\">Edit&nbsp;category/change&nbsp;curation&nbsp;lvl</a>
                </td>
              </tr>
              <tr height=\"5px\"><td></td></tr>\n";

    // SEARCH/BLAST
    echo "
              <tr>
                <td colspan=2>
                  Search/BLAST 
                  <span style=\"color:#990000;font-size:9pt\"><b>caution!</b></span>
                  </td>
              </tr>
              <tr>
                <td></td>
                <td>
                  <a href=\"javascript:goToStage("
                    . CUR_ADD_SEARCH_1 . ")\">Add&nbsp;a&nbsp;search</a>
                </td>
              </tr>
              <tr>
                <td></td>
                <td>
                  <span style=\"color:#990000;font-size:9pt\"></span>
                  <a href=\"javascript:goToStage("
                    . CUR_EDIT_SEARCH_1 . ")\">Edit&nbsp;search</a>
                </td>
              </tr>
              <tr height=\"5px\"><td></td></tr>
              <tr>
                <td></td>
                <td>
                  <a href=\"javascript:goToStage("
                    . CUR_ADD_BLAST_1 . ")\">Add&nbsp;a&nbsp;BLAST target</a>
                </td>
              </tr>
              <tr>
                <td></td>
                <td>
                  <a href=\"javascript:goToStage("
                    . CUR_EDIT_BLAST_1 . ")\">Edit&nbsp;BLAST target</a>
                </td>
              </tr>
              <tr height=\"5px\"><td></td></tr>";
                   
      // ALERTS
      echo "
              <tr>
                <td colspan=2>Other</td>
              </tr>
              <tr>
                <td></td>
                <td>
                  <a href=\"javascript:goToStage("
                    . CUR_CHECK_ALERTS_1 . ")\">Check&nbsp;alerts</a>
                  <br>";
      $alert_count = countActiveAlerts();
      if ($alert_count == 0) {
        echo "(There are no active alerts)";
      }
      else {
        echo "<span class=\"alert\">There are $alert_count active alerts!<span>";
      }
      echo "
                </td>
              </tr>
             </table>\n";
      $url = "http://curation.maizegdb.org/curation/curationtools/curationIndex1.cgi";
      echo "
          </div>
          </td>
        </tr>
        <tr height=\"5px\"><td></td></tr>
        <tr>
          <td>
            <a href=\"$url\"><span 
               class=\"tinytype\"><< Return to MaizeGDB curation pages</span></a>
          </td>
        </tr>
      </table>\n";
      
      // Prompt text
      echo "
      </td>
      <td align=\"center\" width=\"100%\">
        Make a selection from the menu.
      </td>\n";
      
      echo "
      </td>
   </tr>
 </table>\n";
  }//chooseAnAction()
  
  
  function createCurCategoryDropDown($ctrlname, $firstoption, $lastoption, &$selection, $onchange='') {
    $onchange = ($onchange == '') ? '' : "onchange=\"$onchange\"";
    if ($selection == '') { $selection = 0; }
    
    $html = "<select name=\"$ctrlname\" $onchange>\n";
    if ($firstoption != '') {
      $html .= "<option value=''>$firstoption</option>\n";
    }

    $sql = "SELECT DISTINCT CAT.id, CAT.name FROM pc_category CAT
              INNER JOIN id_num IDN ON CAT.id=IDN.id
            WHERE IDN.curation_lvl=0
            ORDER BY TRIM(name)";
    $conn = connectToCurationDB();
    $res = makeQuery_oracle($conn, $sql);
    
    while ($row = retrieveRow($res)) {
      $selected = ($selection == $row['id']) ? 'selected' : '';
      $html .= "<option value=\"" . $row['id'] . "\" $selected>";
      $html .= $row['name'] . "</option>\n";
    }
    
    if ($lastoption != '') {
      $html .= "<option value=\"$lastoption\" class=\"highlightOption\">
                  $lastoption
                </option>\n";
    }
    
    $html .= "</select>\n";

    disconnectFromDatabase($conn);
    return $html;
  }//createCurCategoryDropDown()
  
  
  function createCurFundingDropDown($ctrlname, $firstoption, $lastoption, &$selection, $onchange='') {
    $onchange = ($onchange == '') ? '' : "onchange=\"$onchange\"";
    if ($selection == '') { $selection = 0; }
    
    $html = "<select name=\"$ctrlname\" $onchange>\n";
    if ($firstoption != '') {
      $html .= "<option value=''>$firstoption</option>\n";
    }

    // Institutions are in the PERSON table
/* ORACLE */
    $sql = "SELECT DISTINCT IPER.id, IPER.name FROM person IPER
              INNER JOIN id_num IDN ON IDN.id=IPER.id
              INNER JOIN person_attribute PA ON PA.id=IPER.id
              INNER JOIN term ON TERM.id=PA.attribute
            WHERE IDN.curation_lvl=0 AND TERM.name='Funding Source'
            ORDER BY NLS_LOWER(IPER.name)";
/**/
/* POSTGRES
    $sql = "SELECT DISTINCT IPER.id, IPER.name FROM person IPER
              INNER JOIN id_num IDN ON IDN.id=IPER.id
              INNER JOIN person_attribute PA ON PA.id=IPER.id
              INNER JOIN term ON TERM.id=PA.attribute
            WHERE IDN.curation_lvl=0 AND TERM.name='Funding Source'
            ORDER BY LOWER(IPER.name)";
*/
    $conn = connectToCurationDB();
    $res = makeQuery_oracle($conn, $sql);
    
    while ($row = retrieveRow($res)) {
      $selected = ($selection == $row['id']) ? 'selected' : '';
      $html .= "<option value=\"" . $row['id'] . "\" $selected>";
      $html .= $row['name'] . "</option>\n";
    }
    
    if ($lastoption != '') {
      $html .= "<option value=\"$lastoption\" class=\"highlightOption\">
                  $lastoption
                </option>\n";
    }
    
    $html .= "</select>\n";

    disconnectFromDatabase($conn);
    return $html;
  }//createCurFundingDropDown()
  

  function createCurInstitutionDropDown($ctrlname, $firstoption, $lastoption, &$selection, $onchange='') {
    $onchange = ($onchange == '') ? '' : "onchange=\"$onchange\"";
    if ($selection == '') { $selection = 0; }
    
    $html = "<select name=\"$ctrlname\" $onchange>\n";
    if ($firstoption != '') {
      $html .= "<option value=''>$firstoption</option>\n";
    }

    // Institutions are in the PERSON table
//ORACLE
//    $sql = "SELECT DISTINCT IPER.id, IPER.name FROM person IPER
//              INNER JOIN id_num IDN ON IDN.id=IPER.id
//              INNER JOIN person_attribute PA ON PA.ID=IPER.id
//              INNER JOIN term ON TERM.id=PA.attribute
//            WHERE IDN.curation_lvl=0 AND TERM.name='Institution'
//            ORDER BY NLS_LOWER(IPER.name)";
    $sql = "SELECT DISTINCT IPER.id, IPER.name FROM person IPER
              INNER JOIN id_num IDN ON IDN.id=IPER.id
              INNER JOIN person_attribute PA ON PA.ID=IPER.id
              INNER JOIN term ON TERM.id=PA.attribute
            WHERE IDN.curation_lvl=0 AND TERM.name='Institution'
            ORDER BY LOWER(IPER.name)";
    $conn = connectToCurationDB();
    $res = makeQuery_oracle($conn, $sql);
    
    while ($row = retrieveRow($res)) {
//logVarDumpPC($row);
      $selected = ($selection == $row['id']) ? 'selected' : '';
      $html .= "<option value=\"" . $row['id'] . "\" $selected>";
      $html .= stripslashes($row['name']) . "</option>\n";
    }
    
    if ($lastoption != '') {
      $html .= "<option value=\"$lastoption\" class=\"highlightOption\">
                  $lastoption
                </option>\n";
    }
    
    $html .= "</select>\n";

    disconnectFromDatabase($conn);
    return $html;
  }//createCurInstitutionDropDown()
  

  // Given a list of investigators as a string, display the names.
  function createCurInvestigatorDropDown($ctrlname, $firstoption, $lastoption, &$selection, $onchange='') {
    global $curation_levels;
    
    $onchange = ($onchange == '') ? '' : "onchange=\"$onchange\"";
    if ($selection == '') { $selection = 0; }
    
    $html = "<select name=\"$ctrlname\" $onchange>\n";
    if ($firstoption != '') {
      $html .= "<option value=''>$firstoption</option>\n";
    }
    
    $sql = "SELECT DISTINCT PER.id, PER.name, PER.name_first FROM person PER
              INNER JOIN person_attribute PA ON PA.id = PER.id
              INNER JOIN term ON TERM.id = PA.attribute 
                    AND TERM.name IN ('Cooperator', 
                                     'Researcher for project listed at POPcorn')
              INNER JOIN id_num ON ID_NUM.id=PER.id
            WHERE ID_NUM.curation_lvl = " . $curation_levels['active'][1] . "
            ORDER BY UPPER(PER.name)";
    $conn = connectToDatabase();
    $res = makeQuery_oracle($conn, $sql);
    
    while ($row = retrieveRow($res)) {
      $name = trim($row['name']);
      if (trim($row['name_first']) != '') {
        $name .= ' (' . trim($row['name_first']) . ')';
      }
      $selected = ($selection == $row['id']) ? 'selected' : '';
      $html .= "<option value=\"" . $row['id'] . "\" $selected>";
      $html .= "$name</option>\n";
    }
    
    if ($lastoption != '') {
      $html .= "<option value=\"$lastoption\" class=\"highlightOption\">
                  $lastoption
                </option>\n";
    }
    
    $html .= "</select>\n";

    disconnectFromDatabase($conn);
    return $html;
  }//createCurInvestigatorDropDown()
  
  
  function createCurProjectDropDown($ctrlname, $firstoption, $lastoption, &$selection, $onchange='') {
    global $curation_levels;
    $conn = connectToCurationDB();

    $onchange = ($onchange == '') ? '' : "onchange=\"$onchange\"";
    
    $html = "<select name=\"$ctrlname\" $onchange>\n";
    if ($firstoption != '') {
      $html .= "<option value=''>$firstoption</option>\n";
    }

//ORACLE
//    $sql = "SELECT * FROM pc_project R
//              INNER JOIN id_num ON ID_NUM.id = R.id
//            WHERE ID_NUM.curation_lvl IN ("
//              . $curation_levels['active'][1] . "," 
//              . $curation_levels['inprogress'][1] . ","
//              . $curation_levels['hold'][1] . ")
//            ORDER BY NLS_LOWER(R.name)";
    $sql = "SELECT * FROM pc_project R
              INNER JOIN id_num ON ID_NUM.id = R.id
            WHERE ID_NUM.curation_lvl IN ("
              . $curation_levels['active'][1] . "," 
              . $curation_levels['inprogress'][1] . ","
              . $curation_levels['hold'][1] . ")
            ORDER BY LOWER(R.name)";
    $res = makeQuery_oracle($conn, $sql);
    
    while ($row = retrieveRow($res)) {
//logVarDumpPC($row);
      $selected = ($selection == stripslashes($row['name'])) ? 'selected' : '';
      $html .= "<option value=\"" . stripslashes($row['name']) . "\" $selected>";
      $html .= truncate(stripslashes($row['name']), 50) . "</option>\n";
    }
    
    if ($lastoption != '') {
      $html .= "<option value=\"$lastoption\" class=\"highlightOption\">
                  " . truncate($lastoption, 40) . "
                </option>\n";
    }
    
    $html .= "</select>\n";

    disconnectFromDatabase($conn);
    return $html;
  }//createCurProjectDropDown()
  
  
  function createCurResourceDropDown($ctrlname, $firstoption, $lastoption, &$selection, $onchange='') {
    global $curation_levels;
    $conn = connectToCurationDB();

    $onchange = ($onchange == '') ? '' : "onchange=\"$onchange\"";
    
    $html = "<select name=\"$ctrlname\" $onchange>\n";
    if ($firstoption != '') {
      $html .= "<option value=''>$firstoption</option>\n";
    }

//ORACLE
//    $sql = "SELECT * FROM pc_resource R
//              INNER JOIN id_num ON ID_NUM.id = R.id
//            WHERE ID_NUM.curation_lvl IN ("
//              . $curation_levels['active'][1] . "," 
//              . $curation_levels['inprogress'][1] . ","
//              . $curation_levels['hold'][1] . ")
//            ORDER BY NLS_LOWER(R.name)";
    $sql = "SELECT * FROM pc_resource R
              INNER JOIN id_num ON ID_NUM.id = R.id
            WHERE ID_NUM.curation_lvl IN ("
              . $curation_levels['active'][1] . "," 
              . $curation_levels['inprogress'][1] . ","
              . $curation_levels['hold'][1] . ")
            ORDER BY LOWER(R.name)";
    $res = makeQuery_oracle($conn, $sql);
    
    while ($row = retrieveRow($res)) {
//logVarDumpPC($row);
      $selected = ($selection == stripslashes($row['name'])) ? 'selected' : '';
      $html .= "<option value=\"" . stripslashes($row['name']) . "\" $selected>";
      $html .= truncate(stripslashes($row['name']), 50) . "</option>\n";
    }

//echo "<br>lastoption: [$lastoption]<br>";    
    if (trim($lastoption) != '') {
      $html .= "<option value=\"$lastoption\" class=\"highlightOption\">
                  " . truncate($lastoption, 40) . "
                </option>\n";
    }
    
    $html .= "</select>\n";

    disconnectFromDatabase($conn);
    return $html;
  }//createCurResourceDropDown()
  
  
  function getAlertList($alert_list, $readonly) {
    if ($alert_list == '') {
      echo "
        <i>No alerts set. Please add any required alerts using the 
        box on the right.</i>";
    }
    else {
      $onchange = "enableAlertButtons(this.form)";
      $html = "<table>\n";
      $html .= "<tr>\n";
      $html .= "<td>\n";
      $html .= "<select name=\"alert_select\" size=4 onchange=$onchange>\n";

      $list = array_unique(explode("||", $alert_list));
//logMessagePC("alert list: $alert_list\n");      
//logVarDumpPC($list);
      foreach ($list as $item) {
        $elements = explode("|", $item);
//logVarDumpPC($elements);
        $year = $elements[0];
        $month = $elements[1];
        $day = $elements[2];
        $msg = $elements[3];
        $curator = $elements[4];
        $handled = $elements[5];
        $auto_num = $elements[6];
        
        $opstr = ($handled == 'no') ? 'ON' : 'off';
        $opstr .= " [";
        if (trim($year) != '') {
          $opstr .= $year . "-" . $month . "-" . $day;
        }
        else {
          $opstr .= "always on";
        }
        $opstr .= "] " . truncate($msg, 40);
        $opstr .= " : " . truncate(getCuratorName($curator), 24);
        $html .= "<option value=\"\">$opstr</option>\n";
      }//for each alert

      $html .= "</select>\n";
      $html .= "</td>\n";
      
      $editclick = "editAlert(this.form)";
      $removeclick = "removeAlert(this.form)";
      $disableclick = "disableAlert(this.form)";
      $html .= "<td>\n";
      if (!$readonly) {
        $html .= "<table><tr><td align=\"center\">";
        $html .= "<input type=\"button\" name=\"editAlertBtn\"
                         value=\"edit\" onclick=\"$editclick\" 
                         disabled=true>\n";
        $html .= "</td></tr><tr><td align=\"center\">";
        $html .= "<input type=\"button\" name=\"removeAlertBtn\"
                         value=\"remove\" onclick=\"$removeclick\" 
                         disabled=true>\n";
        $html .= "</td></tr></table>";
      }
      $html .= "</td>\n";
      $html .= "</tr>\n";
      $html .= "</table>\n";
      
      echo $html;
    }
  }//getAlertList()


  function getCategoryList($category_list, $readonly) {
    if ($category_list == '') {
      echo "
        <i>No categories set. Please select or add one or more categories
           from the box on the right.</i>";
    }
    else {
      $onchange = "enableRemoveCategory(this.form)";
      $html = "<table>\n";
      $html .= "<tr>\n";
      $html .= "<td>\n";
      $html .= "<select name=\"category_select\" size=4 onchange=$onchange>\n";
      
      $list = array_unique(explode("||", $category_list));
      foreach ($list as $item) {
        $items = explode("|", $item);
        $html .= "<option value=\"\">" . $items[0] . "</option>\n";
      }
      
      $html .= "</select>\n";
      $html .= "</td>\n";
      
      $onclick = "removeCategory(this.form)";
      $html .= "<td>\n";
      if (!$readonly) {
        $html .= "<input type=\"button\" name=\"removeCategoryBtn\"
                         value=\"remove\" onclick=\"$onclick\" disabled=true>\n";
      }
      $html .= "</td>\n";
      $html .= "</tr>\n";
      $html .= "</table>\n";
      
      echo $html;
    }
  }//getCategoryList()
  
  
  function getFundingList($funding_list, $readonly) {
    if ($funding_list == '') {
      echo "
        <i>No funding sources set. Please select or add one or more funding 
           sources from the box on the right.</i>";
    }
    else {
      $onchange = "enableFundingButtons(this.form)";
      $html = "<table>\n";
      $html .= "<tr>\n";
      $html .= "<td>\n";
      $html .= "<select name=\"funding_select\" size=4
                        onchange=$onchange>\n";

      $list = array_unique(explode("||", $funding_list));
logMessagePC("Got " . count($list) . " records from $funding_list");

      foreach ($list as $item) {
        // 0-award, 1-order, 2-source, 3-auto-num
        $elements = explode("|", $item);
//logMessagePC("lib_curation_db.php: getFundingList(): $item");
//logVarDumpPC($elements);
          $opstr = $elements[2] . " [" . $elements[1] . "]";
          $html .= "<option value=\"\">$opstr</option>\n";
      }

      $html .= "</select>\n";
      $html .= "</td>\n";
      
      $editclick = "editFunding(this.form)";
      $removeclick = "removeFunding(this.form)";
      $openclick = "openFunding(this.form)";
      $html .= "<td>\n";
      if (!$readonly) {
        $html .= "<table><tr><td align=\"center\">";
        $html .= "<input type=\"button\" name=\"editFundingBtn\"
                         value=\"edit\" onclick=\"$editclick\" 
                         disabled=true>\n";
        $html .= "</td></tr><tr><td align=\"center\">";
        $html .= "<input type=\"button\" name=\"removeFundingBtn\"
                         value=\"remove\" onclick=\"$removeclick\" 
                         disabled=true>\n";
        $html .= "</td></tr><tr><td align=\"center\">";
        $html .= "<input type=\"button\" name=\"openFundingBtn\"
                         value=\"open\" onclick=\"$openclick\" 
                         disabled=true>\n";
        $html .= "</td></tr></table>";
      }
      $html .= "</td>\n";
      $html .= "</tr>\n";
      $html .= "</table>\n";
      
      echo $html;
    }
  }//getFundingList()


  function getInstitutionList($institution_list, $readonly) {
    if ($institution_list == '') {
      echo "
        <i>No institutions set. Please select or add one or more institutions
           from the box on the right.</i>";
    }
    else {
      $onchange = "enableInstitutionButtons(this.form)";
      $html = "<table>\n";
      $html .= "<tr>\n";
      $html .= "<td>\n";
      $html .= "<select name=\"institution_select\" size=4
                        onchange=$onchange>\n";
      
      $list = array_unique(explode("||", $institution_list));
logMessagePC("lib_curation_UI.php: getInstitutionList(): institutions as string: $institution_list");
logVarDumpPC($list, "lib_curation_UI.php: getInstitutionList(): institutions: ");
      foreach ($list as $item) {
        $fields = explode("|", $item);
//        $op_str = stripslashes(htmlentities($fields[0])) . " [" . $fields[1] . "]";
//logMessagePC("lib_curation_UI.php: getInstitutionList(): " . $fields[0] ." HTML-encoded: " . htmlentities($fields[0]) . ", stripped: $op_str");
        $op_str = html_entity_decode($fields[0]) . " [" . $fields[1] . "]";
        $html .= "<option value=\"\">$op_str</option>\n";
      }

      $html .= "</select>\n";
      $html .= "</td>\n";
      
      $editclick = "editInstitution(this.form)";
      $removeclick = "removeInstitution(this.form)";
      $html .= "<td>\n";
      if (!$readonly) {
        $html .= "<table><tr><td align=\"center\">";
        $html .= "<input type=\"button\" name=\"editInstitutionBtn\"
                         value=\"edit\" onclick=\"$editclick\" 
                         disabled=true>\n";
        $html .= "</td></tr><tr><td align=\"center\">";
        $html .= "<input type=\"button\" name=\"removeInstitutionBtn\"
                         value=\"remove\" onclick=\"$removeclick\" 
                         disabled=true>\n";
        $html .= "</td></tr></table>";
      }
      $html .= "</td>\n";
      $html .= "</tr>\n";
      $html .= "</table>\n";
      
      echo $html;
    }
  }//getInstitutionList()
  

  function getInvestigatorList($investigator_list, $readonly) {
//logMessagePC("getInvestigatorList(): $investigator_list");
    if ($investigator_list == '') {
      echo "
        <i>No investigators set. Please select or add one or more investigators
           from the box on the right.</i>";
    }
    else {
      $onchange = "enableInvestigatorButtons(this.form)";
      $html = "<table>\n";
      $html .= "<tr>\n";
      $html .= "<td>\n";
      $html .= "<select name=\"investigator_select\" size=4
                        onchange=$onchange>\n";
      
      // Names separated by '||'
      $list = array_unique(explode("||", $investigator_list));
      
      foreach ($list as $item) {
        // Fields separated by '|': name, role, order
        $fields = explode("|", $item);
        
        $str = stripslashes($fields[0]);
        if (trim($fields[1]) != '')  // investigator might not have a role
          $str .= " (" . $fields[1] . ")";
        $str .= " [" . $fields[2] . "]";
        
        $html .= "<option value=\"\">$str</option>\n";
      }

      $html .= "</select>\n";
      $html .= "</td>\n";
      
      $editclick = "editInvestigator(this.form)";
      $removeclick = "removeInvestigator(this.form)";
      $html .= "<td>\n";
      if (!$readonly) {
        $html .= "<table><tr><td align=\"center\">";
        $html .= "<input type=\"button\" name=\"editInvestigatorBtn\"
                         value=\"edit\" onclick=\"$editclick\" 
                         disabled=true>\n";
        $html .= "</td></tr><tr><td align=\"center\">";
        $html .= "<input type=\"button\" name=\"removeInvestigatorBtn\"
                         value=\"remove\" onclick=\"$removeclick\" 
                         disabled=true>\n";
        $html .= "</td></tr></table>";
      }
      $html .= "</td>\n";
      $html .= "</tr>\n";
      $html .= "</table>\n";
      
      echo $html;
    }
  }//getInvestigatorList()

 
  function getProjectAssocList($project_list, $readonly) {
    if ($project_list == '') {
      echo "
        <i>No related projects set. Please select or add one or more projects
           from the box on the right.</i>";
    }
    else {
      $onchange = "enableProjectButtons(this.form)";
      $html = "<table>\n";
      $html .= "<tr>\n";
      $html .= "<td>\n";
      $html .= "<select name=\"project_select\" size=4
                        onchange=$onchange>\n";
      
      $list = array_unique(explode("||", $project_list));
      foreach ($list as $item) {
        $fields = explode("|", $item);
        //0-project, 1-order, 2-auto_num
        $op_str = stripslashes(truncate($fields[0], 50)) 
                  . " [" . $fields[1] . "]";
        $html .= "<option value=\"\">$op_str</option>\n";
      }

      $html .= "</select>\n";
      $html .= "</td>\n";
      
      $editclick = "editProject(this.form)";
      $removeclick = "removeProject(this.form)";
      $html .= "<td>\n";
      if (!$readonly) {
        $html .= "<table><tr><td align=\"center\">";
        $html .= "<input type=\"button\" name=\"editProjectBtn\"
                         value=\"edit\" onclick=\"$editclick\" 
                         disabled=true>\n";
        $html .= "</td></tr><tr><td align=\"center\">";
        $html .= "<input type=\"button\" name=\"removeProjectBtn\"
                         value=\"remove\" onclick=\"$removeclick\" 
                         disabled=true>\n";
        $html .= "</td></tr></table>";
      }
      $html .= "</td>\n";
      $html .= "</tr>\n";
      $html .= "</table>\n";
      
      echo $html;
    }
  }//getProjectList()
  

  function getResourceAssocList($resource_list, $readonly) {
    if ($resource_list == '') {
      echo "
        <i>No resources set. Please select or add one or more resources
           from the box on the right.</i>";
    }
    else {
      $onchange = "enableResourceButtons(this.form)";
      $html = "<table>\n";
      $html .= "<tr>\n";
      $html .= "<td>\n";
      $html .= "<select name=\"resource_select\" size=4
                        onchange=$onchange>\n";
      
      $list = array_unique(explode("||", $resource_list));
      foreach ($list as $item) {
        $fields = explode("|", $item);
        //0-resource, 1-order, 2-auto_num
        $op_str = stripslashes(truncate($fields[0], 50)) 
                  . " [" . $fields[1] . "]";
        $html .= "<option value=\"\">$op_str</option>\n";
      }

      $html .= "</select>\n";
      $html .= "</td>\n";
      
      $editclick = "editResource(this.form)";
      $removeclick = "removeResource(this.form)";
      $html .= "<td>\n";
      if (!$readonly) {
        $html .= "<table><tr><td align=\"center\">";
        $html .= "<input type=\"button\" name=\"editResourceBtn\"
                         value=\"edit\" onclick=\"$editclick\" 
                         disabled=true>\n";
        $html .= "</td></tr><tr><td align=\"center\">";
        $html .= "<input type=\"button\" name=\"removeResourceBtn\"
                         value=\"remove\" onclick=\"$removeclick\" 
                         disabled=true>\n";
        $html .= "</td></tr></table>";
      }
      $html .= "</td>\n";
      $html .= "</tr>\n";
      $html .= "</table>\n";
      
      echo $html;
    }
  }//getResourceAssocList()
  

  function showAddBlastTitle($stage) {
     global $stage_navigation;
     
    // Set nav line
    $navs = array($stage_navigation[CUR_START]);
    if ($stage >= CUR_ADD_BLAST_1)
      array_push($navs, $stage_navigation[CUR_ADD_BLAST_1]);
    if ($stage >= CUR_ADD_BLAST_2)
      array_push($navs, $stage_navigation[CUR_ADD_BLAST_2]);
      
    showTitle($navs, $stage);
  }//showAddBlastTitle()
  
  
  function showAddCategoryTitle($stage) {
     global $stage_navigation;
     
    // Set nav line
    $navs = array($stage_navigation[CUR_START]);
    if ($stage >= CUR_ADD_CATEGORY_1)
      array_push($navs, $stage_navigation[CUR_ADD_CATEGORY_1]);
    if ($stage >= CUR_ADD_CATEGORY_2)
      array_push($navs, $stage_navigation[CUR_ADD_CATEGORY_2]);
      
    showTitle($navs, $stage);
  }//showAddCategoryTitle()
  
  
  function showAddProjectTitle($stage) {
     global $stage_navigation;
     
    // Set nav line
    $navs = array($stage_navigation[CUR_START]);
    if ($stage >= CUR_ADD_PROJECT_1)
      array_push($navs, $stage_navigation[CUR_ADD_PROJECT_1]);
    if ($stage >= CUR_ADD_PROJECT_2)
      array_push($navs, $stage_navigation[CUR_ADD_PROJECT_2]);
      
    showTitle($navs, $stage);
  }//showAddProjectTitle()
  
  
  function showAddResourceTitle($stage) {
     global $stage_navigation;
     
    // Set nav line
    $navs = array($stage_navigation[CUR_START]);
    if ($stage >= CUR_ADD_RESOURCE_1)
      array_push($navs, $stage_navigation[CUR_ADD_RESOURCE_1]);
    if ($stage == CUR_ADD_RESOURCE_2)
      array_push($navs, $stage_navigation[CUR_ADD_RESOURCE_2]);
    if ($stage >= CUR_ADD_RESOURCE_3)
      array_push($navs, $stage_navigation[CUR_ADD_RESOURCE_3]);
    if ($stage >= CUR_ADD_RESOURCE_4)
      array_push($navs, $stage_navigation[CUR_ADD_RESOURCE_4]);
      
    showTitle($navs, $stage);
  }//showAddResourceTitle()
  
  
  function showAddSearchTitle($stage) {
     global $stage_navigation;
     
    // Set nav line
    $navs = array($stage_navigation[CUR_START]);
    if ($stage >= CUR_ADD_SEARCH_1)
      array_push($navs, $stage_navigation[CUR_ADD_SEARCH_1]);
    if ($stage >= CUR_ADD_SEARCH_2)
      array_push($navs, $stage_navigation[CUR_ADD_SEARCH_2]);
      
    showTitle($navs, $stage);
  }//showAddSearchTitle()
  
  
  function showAlert() {
    $auto_num = getCGIParamPC('active_auto_num', 'P', '');
    if ($auto_num == '') {
      reportErrorPC("No auto_num supplied for alert");
      return;
    }
    $rec = getAlert($auto_num);
    //0-date, 1-msg, 2-age, 3-id, 4-type-term, 5-assigned-to 6-handled, 7-auto_num
    $name = getRecordName($rec['id'], $rec['type_term']);
    
    $type = getDataTypeName($rec['type_term']);
    $edit_stage = ($type == 'POPcorn Resource') 
                      ? CUR_EDIT_RESOURCE_2 : CUR_EDIT_PROJECT_2;
    $edit_click = "editRecord(document.curationform, ";
    $edit_click .= $rec['id'] . ", $edit_stage)";
    $cancel_click = "goToStage(" . CUR_CHECK_ALERTS_1 . ")";
    echo "
      <table>";
    // ALERT TRIGGER DATE
    echo "
        <tr>
          <td><b>Alert Trigger Date:</b></td>
          <td>" . $rec['date'] . "</td>
        </tr>";
    // MESSAGE
    echo "
        <tr>
          <td><b>Alert Message:</b></td>
          <td>" . $rec['msg'] . "</td>
        </tr>";
    // ASSIGNED TO
    echo "
        <tr>
          <td><b>Assigned to:</b></td>
          <td>" . getCuratorName($rec['assigned']) . "</td>
        </tr>";
    // OBJECT NAME
    echo "
        <tr>
          <td><b>Record Name:</b></td>
          <td>$name</td>
        </tr>";
    // CONTROLS
    echo "
        <tr height=10><td></td></tr>
        <tr>
          <td colspan=2>";
    if ($rec['handled'] == 'yes') {
      echo "
            <input type=\"button\" value=\"Enable\" 
                   onclick=\"handleAlert(document.curationform, false, "
                                         . CUR_CHECK_ALERTS_1 . ")\">";
    }
    else {
      echo "
            <input type=\"button\" value=\"Disable\" 
                   onclick=\"handleAlert(document.curationform, true, "
                                         . CUR_CHECK_ALERTS_3 . ")\">";
    }
    echo "
            <input type=\"button\" value=\"Edit Record\" 
                   onclick=\"$edit_click\">
            <input type=\"button\" value=\"Cancel\" 
                   onclick=\"$cancel_click\">
          </td>
        </tr>
      </table>\n";
  }//showAlert()

  
  function showAlertList() {
    echo "
      <table border=0>
        <tr>
          <td colspan=5><h3>Active Alerts</h3></td>
        </tr>
        <tr>
          <td></td>
          <td></td>
          <td valign=\"bottom\"><b>Date</b></td>
          <td valign=\"bottom\" align=\"center\"><b>Age (days)</b></td>
          <td valign=\"bottom\" width=\"30%\"><b>Object Name</b></td>
          <td valign=\"bottom\" width=\"50%\"><b>Message</b></td>
          <td valign=\"bottom\" width=\"10%\"><b>Assigned To</b></td>
          <td valign=\"bottom\" align=\"center\"width=\"10%\"><b>E-mail Sent</b></td>
        </tr>";
    $alerts = getActiveAlerts();
    $count = 1;
    foreach ($alerts as $alert) {
      $name = getRecordName($alert['id'], $alert['type_term']);
      $assigned_curator = getCuratorName($alert['assigned']);
      $details_click = "checkAlert(document.curationform, ";
      $details_click .= $alert['auto_num'] . ", " . CUR_CHECK_ALERTS_2 . ")";
      $type = getDataTypeName($alert['type_term']);
      $edit_stage = ($type == 'POPcorn Resource') 
                        ? CUR_EDIT_RESOURCE_2 : CUR_EDIT_PROJECT_2;
      $edit_click = "editRecord(document.curationform, ";
      $edit_click .= $alert['id'] . ", $edit_stage)";
      echo "
        <tr>
          <td valign=\"top\">$count:</td>
          <td valign=\"top\">
            <a href=\"#\" onclick=\"$details_click\">details</a>
          </td>
          <td valign=\"top\" class=>" . $alert['date'] . "</td>
          <td valign=\"top\" align=\"center\">" . $alert['age'] . "</td>
          <td valign=\"top\">
            <a href=\"#\" onclick=\"$edit_click\">$name</a>
          </td>
          <td valign=\"top\">" . truncate($alert['msg'], 60) . "</td>
          <td valign=\"top\">$assigned_curator</td>
          <td valign=\"top\">" . $alert['email_date'] . "</td>
        </tr>
        <tr height=4><td></td></tr>";
      $count++;
    }
    echo "
      </table>\n";
  }//showAlertList()
  
  
  function showAlertTitle($stage) {
     global $stage_navigation;
     
    // Set and display nav line
    $navs = array($stage_navigation[CUR_START]);
    if ($stage >= CUR_CHECK_ALERTS_1)
      array_push($navs, $stage_navigation[CUR_CHECK_ALERTS_1]);
    if ($stage == CUR_CHECK_ALERTS_2)
      array_push($navs, $stage_navigation[CUR_CHECK_ALERTS_2]);
      
    showTitle($navs, $stage);
  }//showAlertTitle()
  
  
  function showBlast($id) {
    global $bfields;
    
    $rec = getBlast($id);
//cho "<pre>";var_dump($rec);echo "</pre>";
    showRecord($bfields, $rec);
  }//showBlast
  
  
  function showBlastLevelTitle($stage) {
     global $stage_navigation;
//echo "showProjectLevelTitle($stage)<br>";
//echo "<pre>";var_dump($stage_navigation);echo "</pre>";
    // Set nav line
    $navs = array($stage_navigation[CUR_START]);
    // CUR_BLAST_LVL_1 not used
    if ($stage >= CUR_BLAST_LVL_2)
      array_push($navs, $stage_navigation[CUR_BLAST_LVL_2]);
    if ($stage == CUR_BLAST_LVL_3)
      array_push($navs, $stage_navigation[CUR_BLAST_LVL_3]);
    if ($stage >= CUR_BLAST_LVL_4)
      array_push($navs, $stage_navigation[CUR_BLAST_LVL_4]);
      
    showTitle($navs, $stage);
  }//showSearchLevelTitle()
  
  
  function showBlastList() {
    $blasts = getAllBlasts();
//echo "<pre>";var_dump($searches);echo "</pre>";

    echo "
      <table>
        <tr>
          <td colspan=4>
            <h3>Existing BLAST targets:</h3>
          </td>
        </tr>";
    
    if (count($blasts) == 0) {
      echo "
        <tr>
          <td><i>No BLAST targets found</i></td>
        </tr>";
    }
    else {
      echo "
        <tr>
          <td><b>ID</b></td>
          <td><b>Curation lvl</b></td>
          <td width=\"25%\"><b>Name</b></td>
          <td width=\"10%\"><b>Host</b></td>
          <td width=\"10%\"><b>Target</b></td>
          <td width=\"10%\"><b>Type</b></td>
          <td><b>Description</b></td>
          <td><b>Mod Date</b></td>
        </tr>
        <tr height=2 bgcolor=\"#a0a0a0\"><td colspan=8></td></tr>";
      foreach ($blasts as $blast) {
        $edit_url = "javascript:editRecord(" 
                    . "document.curationform, " . $blast['id'] . ", "
                    . CUR_EDIT_BLAST_2 . ")";
        $lvlchange_url = "javascript:goToChangeLevel(document.curationform, " 
                         . $blast['id'] . ", " . CUR_BLAST_LVL_2 . ")";
        echo "
          <tr>
            <td>" . $blast['id'] . "</td>
            <td>
              <a href=\"$lvlchange_url\">" . $blast['level'] . "</a>
            </td>
            <td>
              <a href=\"$edit_url\">" . $blast['name'] . "</a>
            </td>
            <td>
              " . $blast['source'] . "
            </td>
            <td>
              " . $blast['db_name'] . "
            </td>
            <td>
              " . $blast['type'] . "
            </td>
            <td>
              " . truncate($blast['display_info'], 30) . "
            </td>
            <td>
              " . $blast['mod_date'] . " 
            </td>
          </tr>";
      }//foreach
    }//else blast targets exist
    
    echo "
      </table>
      <br><br>";
  }//showBlastList()
  
  
  function showCategory($id) {
    global $cfields;
    
    $rec = getCategory($id);
//cho "<pre>";var_dump($rec);echo "</pre>";
    showRecord($cfields, $rec);
  }//showCategory
  
  
  function showCategoryList() {
    $categories = getAllCategories();
//echo "<pre>";var_dump($resources);echo "</pre>";

    echo "
      <table>
        <tr>
          <td colspan=4>
            <h3>Existing categories:</h3>
          </td>
        </tr>";
    
    if (count($categories) == 0) {
      echo "
        <tr>
          <td><i>No categories found</i></td>
        </tr>";
    }
    else {
      echo "
        <tr>
          <td><b>ID</b></td>
          <td><b>Curation lvl</b></td>
          <td width=\"35%\"><b>Name</b></td>
          <td><b>Description</b></td>
          <td><b>Mod Date</b></td>
        </tr>
        <tr height=2 bgcolor=\"#a0a0a0\"><td colspan=7></td></tr>";
      foreach ($categories as $category) {
        $edit_url = "javascript:editRecord(" 
                    . "document.curationform, " . $category['id'] . ", "
                    . CUR_EDIT_CATEGORY_2 . ")";
//        $lvlchange_url = "javascript:changeLevel(" 
//                    . "document.curationform, " . $category['id'] . ", "
//                    . CUR_CATEGORY_LVL_2 . ")";
//        $lvlchange_url = "javascript:goToStage(" . CUR_CATEGORY_LVL_2 . ")";
        $lvlchange_url = "javascript:goToChangeLevel(document.curationform, " 
                         . $category['id'] . ", " . CUR_CATEGORY_LVL_2 . ")";
        echo "
          <tr>
            <td>" . $category['id'] . "</td>
            <td>
              <a href=\"$lvlchange_url\">" . $category['level'] . "</a>
            </td>
            <td>
              <a href=\"$edit_url\">" . $category['category_name'] . "</a>
            </td>
            <td>
              " . truncate($category['description'], 30) . "
            </td>
            <td>
              " . $category['mod_date'] . " 
            </td>
          </tr>";
      }//foreach
    }//else categories exist
    
    echo "
      </table>
      <br><br>";
  }//showCategoryList
  
  
  function showCategoryRecs($category_list) {
    if (trim($category_list) == '') {
      return;
    }
    
    echo "
      <table>
        <tr>";
    $cats = explode("||", $category_list);
    $count = 1;
    foreach ($cats as $cat) {
      $fields = explode("|", $cat);
      echo "
          <td>&nbsp;&nbsp;&bull; " . $fields[0] . "&nbsp;</td>";
      if (($count%4) == 0) {
        echo "
        </tr>
        <tr>";
      }
      $count++;
    }//foreach category
    echo "
        </tr>
      </table>";
  }//showCategoryRecs
  
  
  function showCurationLevelControl($id) {
    global $curation_levels;
    
    $level = ($id > 0) ? getRecordCurationLvl($id) : -1;
    echo "
        <tr>
          <td>
            <b>Curation lvl</b><br>
            <span style=\"font-size:12\">(Can't activate a record here)<span>
          </td>
          <td valign=\"top\">" . showHelp('CurationLevel') . "</td>
          <td valign=\"top\">";
    if ($level == 0) {
      // resource is 'active'
      $url = "javascript:goToChangeLevel(document.curationform, $id, " 
              . CUR_RESOURCE_LVL_2 . ")";
      echo "
            <input type=\"hidden\" name=\"level\" value=\"0\">
            <span class=\"report\">Active</span>
            <i>(To de-activate record, use <a href=\"$url\">this form</a>.)</i>";
    }
    else {
      // resource is not active
      echo "
          
            <select name=\"level\">";
       foreach (array_keys($curation_levels) as $lvl) {
         if ($lvl != 'active') {
           $num = $curation_levels[$lvl][1];
           $name = $curation_levels[$lvl][0];
           $selected = ($level == $curation_levels[$lvl][1]) ? 'selected' : '';
           echo "
              <option value=\"$num\" $selected>$name</option>";
        }
      }//foreach curation level type
    }//resource is not active
    echo "
            </select>
          </td>
        </tr>\n";
  }//showCurationLevelControl()
  

  function showCurrentEdits() {
    $cur_recs = getRecentRecords();
    echo "
      <div style=\"border: 1px solid black;background-color:#f3f3f3\">
      <table border=0 cellpaddig=1 cellspacing=1>
        <tr>
          <td colspan=3>
            <span class=\"emphasize\">Recent activity:</span>
          </td>
        </tr>";
    if (count($cur_recs) == 0) {
      echo "
        <tr>
          <td colspan=3>
            <i>--- none ---</i>
          </td>
        </tr>";
    }
    else {
      $cur_type = '';
      foreach ($cur_recs as $rec) {
        if ($rec['type'] != $cur_type) {
          $cur_type = $rec['type'];
          echo "
        <tr height=8><td></td><td></td></tr>
        <tr>
          <td colspan=3><b>$cur_type" . "s</b></td>
        </tr>";
        }
        $edit_stage = ($cur_type == 'Resource') 
                          ? CUR_EDIT_RESOURCE_2 : CUR_EDIT_PROJECT_2;
        $edit_url = "javascript:editRecord(document.curationform, " 
                     . $rec['id'] . ", $edit_stage)";
        $level_stage = ($cur_type == 'Resource') 
                          ? CUR_RESOURCE_LVL_2 : CUR_PROJECT_LVL_2;
        $level_url = "javascript:goToChangeLevel(document.curationform, "
                     . $rec['id'] . ", $level_stage)";
        echo "
        <tr>
          <td></td>
          <td valign=\"top\">
            <a href=\"$edit_url\"><span class=\"tinytype\">" 
              . $rec['id'] . "</span></a>
          </td>
          <td valign=\"top\">
            <span class=\"tinytype\">" . $rec['name'] . "</span>
          </td>
          <td valign=\"top\">
            <a href=\"$level_url\"><span class=\"tinytype\">" 
              . $rec['curation_lvl'] . "</span></a>
          </td>
        </tr>";
/*
        echo "
        <tr>
          <td></td>
          <td></td>
          <td colspan=2>
            <a href=\"\"><span class=\"tinytype\">Edit</span></a>
            <a href=\"\"><span class=\"tinytype\">Change curation level</span></a>
          </td>
        </tr>";
*/
      }//foreach record
    }// Process current edits
    echo "
      </table>
      </div>\n";
  }//showCurrentEdits
  
  
  function showCategoryLevelTitle($stage) {
     global $stage_navigation;
     
    // Set nav line
    $navs = array($stage_navigation[CUR_START]);
    // CUR_CATEGORY_LVL_1 not used
    if ($stage >= CUR_CATEGORY_LVL_2)
      array_push($navs, $stage_navigation[CUR_CATEGORY_LVL_2]);
    if ($stage == CUR_CATEGORY_LVL_3)
      array_push($navs, $stage_navigation[CUR_CATEGORY_LVL_3]);
    if ($stage >= CUR_CATEGORY_LVL_4)
      array_push($navs, $stage_navigation[CUR_CATEGORY_LVL_4]);
      
    showTitle($navs, $stage);
  }//showCategoryLevelTitle()
  
  
  function showDeleteTitle($stage) {
    global $stage_navigation;
     
    // Set nav line
    $navs = array($stage_navigation[CUR_START]);
    if ($stage >= CUR_MULTI_DELETE_1)
      array_push($navs, $stage_navigation[CUR_MULTI_DELETE_1]);
    if ($stage == CUR_MULTI_DELETE_2)
      array_push($navs, $stage_navigation[CUR_MULTI_DELETE_2]);
    
    showTitle($navs, $stage);
  }//showDeleteTitle()
  
  
  function showEditBlastChanges() {
    global $bfields;
    
    $id = getCGIParamPC('active_id', 'P', -1);
//echo "Get record for [$id]<br>\n";

    // Get existing record from database
    $old_rec = getBlast($id);
//echo "<pre>";var_dump($old_rec);echo "</pre>";

    // Get new record from CGI parameters
    $new_rec = array();
    foreach (array_keys($bfields) as $field) {
      $new_rec[$field] = getCGIParamPC($field, 'P', $old_rec[$field]);
    }
//echo "<pre>";var_dump($new_rec);echo "</pre>";
    
    // Get curation level and set as hidden field
    $new_lvl_num = getCGIParamPC('level', 'P', '');
    echo "
      <input type=\"hidden\" name=\"level\" value=\"$new_lvl_num\">";

    // Keep all the new values around in case user chooses to go back to edit page
    foreach (array_keys($new_rec) as $field) {
      echo "
      <input type=\"hidden\" name=\"" . $field . "\" 
             value=\"" . urlencode($new_rec[$field]) . "\">";
    }
    
    echo "
      <p>You requested the following changes:</p>
      <table cellspacing=8 cellpadding=5>
        <tr valign=\"top\">
          <td></td>
          <td><b>OLD VALUE</b></td>
          <td width=\"10px\"></td>
          <td><b>NEW VALUE</b></td>
        </tr>";
      
    $changes_found = false;
    foreach (array_keys($bfields) as $field) {
//echo "\n<br>handle $field, dbfield: " . $sfields[$field]['dbfield'] . "<br>\n";
      if ($old_rec[$field] != $new_rec[$field]) {
        $changes_found = true;
        
        if ($bfields[$field]['dbfield'] != 'n/a') {
          echo "
        <tr valign=\"top\">
          <td><b>" . $bfields[$field]['label'] . "</b></td>
          <td><i>" . $old_rec[$field] . "</i></td>
          <td>" . $new_rec[$field] . "</td>
        </tr>";
        }//Simple field
        
        else {
          $old_recs = explode("||", trim($old_rec[$field]));
          $new_recs = explode("||", trim($new_rec[$field]));
//echo "OLD $field: " . $old_rec[$field] . "<pre>";var_dump($old_recs);echo "</pre>";
//echo "NEW record: <pre>";var_dump($new_rec);echo "</pre>";
//echo "NEW $field: " . $new_rec[$field] . "<pre>";var_dump($new_recs);echo "</pre>";

          echo "
        <tr valign=\"top\">
          <td><b>" . $bfields[$field]['label'] . "</b></td>
          <td>
            <i>";
          if ($old_rec[$field] == '' || count($old_recs) == 0) {
            echo "[NONE]";
          }
          else {
            foreach ($old_recs as $rec) {
              $fields = explode("|", $rec);
              array_pop($fields); // Don't display auto_num
              echo "&bull; " . join(" : ", $fields) . "<br>";
            }
          }
          echo "
            </ul>
          </td>
          <td></td>
          <td>";
          if ($new_rec[$field] == '' || count($new_recs) == 0) {
            echo "[NONE]";
          }
          else {
            foreach ($new_recs as $rec) {
              $fields = explode("|", $rec);
              array_pop($fields); // Don't display auto_num
              echo "&bull; " . join(" : ", $fields) . "<br>";
            }
          }
          echo "
            </ul>
          </td>
        </tr>\n";
        }//field generated from assoc table
      }//field changed
    }//foreach
    
    // Check if curation lvl changed
    global $curation_levels;
    $new_lvl_num = getCGIParamPC('level', 'P', '');
    $old_lvl_num = getRecordCurationLvl($id);
    if ($new_lvl_num != $old_lvl_num) {
      $changes_found = true;
      foreach ($curation_levels as $level) {
        if ($level[1] == $new_lvl_num) $new_level = $level[0];
        if ($level[1] == $old_lvl_num) $old_level = $level[0];
      }
      echo "
        <tr>
          <td><b>Curation lvl</b></td>
          <td>$old_level</td>
          <td></td>
          <td>$new_level</td>
        </tr>";
    }

    // Handle no changes
    if (!$changes_found) {
      echo "
        <tr>
          <td colspan=4 align=\"center\"><i>--- No Changes ---</i></td>
        </tr>\n";
    }
    
    echo "
        <tr height=\"1px\" bgcolor=\"black\"><td colspan=4></td></tr>
        <tr height=\"10px\"><td colspan=4></td></tr>
        <tr>
          <td colspan=3>
            <input type=\"button\" value=\"Confirm changes\" 
                   onclick=\"goToStage(" . CUR_EDIT_BLAST_4 . ")\">
            <input type=\"button\" value=\"Cancel\" 
                   onclick=\"goToStage(" . CUR_EDIT_BLAST_2 . ")\">
          </td>
        </tr>
      </table>\n";
   }//showEditBlastChanges()
  
  
  function showEditBlastForm($id) {
    global $bfields;
    
    // Initialize record
    if ($id < 0) {
      $rec = array();
      foreach (array_keys($bfields) as $field) {
        $rec[$field] = '';
      }
    }
    else {
      $rec = getBlast($id);
      // ID for currently-edited record
      echo "
      <input type=\"hidden\" name=\"id\" value=\"$id\">";
    }
    
    // if 'active_id' and 'id' are the same, form values override
    // (else we are loading a new record and don't want to replace its fields
    //  with anything left over in the post fields from the previous record)
    if (getCGIParamPC('active_id', 'P', '') == getCGIParamPC('id', 'P', '')) {
      foreach (array_keys($bfields) as $field) {
        $rec[$field] = urldecode(getCGIParamPC($field, 'P', $rec[$field]));
      }
//echo "<pre>";var_dump($rec);echo "</pre>";
    }
    // (else we are loading a new record and don't want to replace its fields
    //  with anything left over in the post fields from the previous record)

    // Start form table
    echo "
      <table>
        <tr>
          <td>ID:</td>
          <td>$id</td>
        </tr>";
      
    // Initial curation level
    showCurationLevelControl($id);
    
    // Show form fields
    showEditFormFields($bfields, $rec);

    // End table
    echo "
      </table>";
   
    // private comments...
    echo "
      <p><i>
        This data is tightly connected to the code. Edit with great caution.
      </i></p>";
    
    // Buttons
    $submit_stage = ($id < 0) ? CUR_ADD_BLAST_2 : CUR_EDIT_BLAST_3;
    $submit_text = ($id < 0) ? 'Add BLAST target' : 'Submit changes';
    $submit_click = "goToStage($submit_stage)";
    $cancel_stage = ($id < 0) ? CUR_START : CUR_EDIT_BLAST_1;
    $cancel_click = "goToStage($cancel_stage)";
    echo"
      <input type=\"button\" value=\"$submit_text\" onclick=\"$submit_click\">
      <input type=\"button\" value=\"Cancel\" onclick=\"$cancel_click\">\n";
    
    // End form
    echo "
     </form>";
    
    // Set initial form state
    echo "
      <script language=\"JavaScript\">
        // Set selected lists
        setCategoryList(document.curationform);
        setResourceList(document.curationform);
        setProjectList(document.curationform);
        setAlertList(document.curationform);
        
        // Show selection choices
        setCategoryRow(document.curationform);
        setResourceRow(document.curationform);
        setProjectRow(document.curationform);
        setAlertRow(document.curationform);
      </script>\n";
  }//showEditBlastForm()
  
  
  function showEditBlastTitle($stage) {
     global $stage_navigation;
     
    // Set nav line
    $navs = array($stage_navigation[CUR_START]);
    if ($stage >= CUR_EDIT_BLAST_1)
      array_push($navs, $stage_navigation[CUR_EDIT_BLAST_1]);
    if ($stage >= CUR_EDIT_SEARCH_2)
      array_push($navs, $stage_navigation[CUR_EDIT_BLAST_2]);
    if ($stage >= CUR_EDIT_SEARCH_3)
      array_push($navs, $stage_navigation[CUR_EDIT_BLAST_3]);
    if ($stage >= CUR_EDIT_SEARCH_4)
      array_push($navs, $stage_navigation[CUR_EDIT_BLAST_4]);
      
    showTitle($navs, $stage);
  }//showEditBlastTitle()
  
  
  function showEditCategoryChanges() {
    global $cfields;
    
    $id = getCGIParamPC('active_id', 'P', -1);

    // Get existing record from database
    $old_rec = getCategory($id);

    // Get new record from CGI parameters
    $new_rec = array();
    foreach (array_keys($cfields) as $field) {
      $new_rec[$field] = getCGIParamPC($field, 'P', $old_rec[$field]);
    }
    
    // Keep all the new values around in case user chooses to go back to edit page
    foreach (array_keys($new_rec) as $field) {
      echo "
      <input type=\"hidden\" name=\"" . $field . "\" 
             value=\"" . $new_rec[$field] . "\">";
    }
    
    echo "
      <p>You requested the following changes:</p>
      <table cellspacing=8 cellpadding=5>
        <tr valign=\"top\">
          <td></td>
          <td><b>OLD VALUE</b></td>
          <td><b>NEW VALUE</b></td>
        </tr>";
      
    foreach (array_keys($cfields) as $field) {
      if ($old_rec[$field] != $new_rec[$field]) {
        if ($cfields[$field]['dbfield'] != 'n/a') {
          echo "
        <tr valign=\"top\">
          <td><b>" . $cfields[$field]['label'] . "</b></td>
          <td><i>" . $old_rec[$field] . "</i></td>
          <td>" . $new_rec[$field] . "</td>
        </tr>";
        }//Simple field
        
        else {
          $old_recs = explode("||", trim($old_rec[$field]));
          $new_recs = explode("||", trim($new_rec[$field]));

          echo "
        <tr valign=\"top\">
          <td><b>" . $cfields[$field]['label'] . "</b></td>
          <td>
            <i>";
          if ($old_rec[$field] == '' || count($old_recs) == 0) {
            echo "[NONE]";
          }
          else {
            foreach ($old_recs as $rec) {
              $fields = explode("|", $rec);
              array_pop($fields); // Don't display auto_num
              echo "&bull; " . join(" : ", $fields) . "<br>";
            }
          }
          echo "
            </ul>
          </td>
          <td>";
          if ($new_rec[$field] == '' || count($new_recs) == 0) {
            echo "[NONE]";
          }
          else {
            foreach ($new_recs as $rec) {
              $fields = explode("|", $rec);
              array_pop($fields); // Don't display auto_num
              echo "&bull; " . join(" : ", $fields) . "<br>";
            }
          }
          echo "
            </ul>
          </td>
        </tr>\n";
        }//field generated from assoc table
      }//field changed
    }//foreach
    
    // TO DO: handle case of no changes.

    echo "
        <tr height=10><td></td></tr>
        <tr>
          <td colspan=3>
            <input type=\"button\" value=\"Confirm changes\" 
                   onclick=\"goToStage(" . CUR_EDIT_CATEGORY_4 . ")\">
            <input type=\"button\" value=\"Cancel\" 
                   onclick=\"goToStage(" . CUR_EDIT_CATEGORY_2 . ")\">";
  
    echo "</table>";  
  }//showEditCategoryChanges()
  
  
  function showEditCategoryForm($id) {
    global $cfields;
    
    // Initialize record
    if ($id < 0) {
      $rec = array();
      foreach (array_keys($cfields) as $field) {
        $rec[$field] = '';
      }
    }
    else {
      $rec = getCategory($id);
    }
    
    // form values override
    foreach (array_keys($cfields) as $field) {
      $rec[$field] = getCGIParamPC($field, 'P', $rec[$field]);
    }

    echo "
      <table>";

    // Build form fields from record definition array
    showEditFormFields($cfields, $rec);
            
    echo "
      </table>";

    // Buttons
    $submit_stage = ($id < 0) ? CUR_ADD_CATEGORY_2 : CUR_EDIT_CATEGORY_3;
    $submit_text = ($id < 0) ? 'Add category' : 'Submit changes';
    $submit_click = "if(verifyCategoryForm(document.curationform))";
    $submit_click .= "{goToStage($submit_stage)}";
    $cancel_stage = ($id < 0) ? CUR_START : CUR_EDIT_CATEGORY_1;
    $cancel_click = "goToStage($cancel_stage)";
    echo"
      <br>
      <input type=\"button\" value=\"$submit_text\" onclick=\"$submit_click\">
      <input type=\"button\" value=\"Cancel\" onclick=\"$cancel_click\">\n";
    
    // End form (started in curation.php)
    echo "
     </form>";
     
  }//showEditCategoryForm()
  
  
  function showEditCategoryTitle($stage) {
     global $stage_navigation;
     
    // Set nav line
    $navs = array($stage_navigation[CUR_START]);
    if ($stage >= CUR_EDIT_CATEGORY_1)
      array_push($navs, $stage_navigation[CUR_EDIT_CATEGORY_1]);
    if ($stage >= CUR_EDIT_CATEGORY_2)
      array_push($navs, $stage_navigation[CUR_EDIT_CATEGORY_2]);
    if ($stage >= CUR_EDIT_CATEGORY_3)
      array_push($navs, $stage_navigation[CUR_EDIT_CATEGORY_3]);
    if ($stage >= CUR_EDIT_CATEGORY_4)
      array_push($navs, $stage_navigation[CUR_EDIT_CATEGORY_4]);
      
    showTitle($navs, $stage);
  }//showEditCategoryTitle()
  
  
  function showEditFormFields($fields, $rec) {
    foreach ($fields as $field) {
       echo "
          <tr>
            <td valign=\"top\"><b>" . $field['label'] . "</b></td>";
       
       // TEXT CONTROL
       if ($field['ctl_type'] == 'text') {
          echo "
            <td valign=\"top\">" . showHelp($field['help']) . "</td>";
          echo "
            <td valign=\"top\">
              <input type=\"text\" name=\"" . $field['name'] . "\" size=60
                     value=\"" . $rec[$field['name']] . "\">
            </td>";
       }
       
       // TEXTAREA CONTROL
       else if ($field['ctl_type'] == 'textarea') {
          echo "
            <td valign=\"top\">" . showHelp($field['help']) . "</td>";
          echo "
            <td>
            <textarea name=\"" . $field['name'] . "\" rows=6 cols=60>"
            . $rec[$field['name']] . "</textarea>
            </td>";
       }
       
       // SELECT CONTROL
       else if (strstr($field['ctl_type'], 'enum')) {
// TODO: pre-set with existing value!
          $opstr = preg_replace("/enum\s*\((.*)\)/", "$1", $field['ctl_type']);
          $ops = explode(', ', $opstr);
          echo "
            <td valign=\"top\">" . showHelp($field['help']) . "</td>";
          echo "
            <td>
            <select name=\"" . $field['name'] . "\">";
          foreach ($ops as $op) {
             $selected = ($rec[$field['name']] == $op) ? 'selected' : '';
             echo "
              <option value=\"$op\" $selected>$op</option>";
          }
          echo "
            </select>
            </td>";
       }
       
       // CATEGORIES
       else if ($field['name'] == 'category_list') {
          echo "
            <td valign=\"top\">
              " . showHelp($field['help']) . "
               <input type=\"hidden\" name=\"category_input_type\" 
                      id=\"category_input_type\" value=\"\">
               <input type=\"hidden\" name=\"category_list\" id=\"category_list\" 
                      value=\"" . $rec['category_list'] . "\">
            </td>";
          echo "
            <td valign=\"top\" id=\"categoryList\"></id>
            <td valign=\"top\" id=\"selectCategory\"
                style=\"background-color:#e0e0e0\"></td>";
       }
       
       // FUNDING
       else if ($field['name'] == 'funding_list') {
          echo "
            <td valign=\"top\">
              " . showHelp($field['help']) . "
              <input type=\"hidden\" name=\"funding_input_type\" 
                     id=\"funding_input_type\" value=\"\">
              <input type=\"hidden\" name=\"funding_list\" 
                     id=\"funding_list\" 
                     value=\"" . urlencode($rec['funding_list']) . "\">
            </td>";
          echo "
            <td valign=\"top\" id=\"fundingList\"></td>
            <td valign=\"top\" id=\"enterFunding\" 
                style=\"background-color:#e0e0e0\"></td>";
       }
       
       // INSTITUTIONS
       else if ($field['name'] == 'institution_list') {
          echo "
            <td valign=\"top\">
              " . showHelp($field['help']) . "
               <input type=\"hidden\" name=\"institution_input_type\" 
                      id=\"institution_input_type\" value=\"\">
               <input type=\"hidden\" name=\"institution_list\" 
                      id=\"institution_list\" 
                      value=\"" . urldecode($rec['institution_list']) . "\">
            </td>";
          echo "
            <td valign=\"top\" id=\"institutionList\"></td>
            <td valign=\"top\" id=\"enterInstitution\"
                style=\"background-color:#e0e0e0\"></td>";
       }
       
       // INVESTIGATORS
       else if ($field['name'] == 'investigator_list') {
          echo "
            <td valign=\"top\">
              " . showHelp($field['help']) . "
              <input type=\"hidden\" name=\"investigator_input_type\" 
                     id=\"investigator_input_type\" value=\"\">
              <input type=\"hidden\" name=\"investigator_list\" 
                     id=\"investigator_list\" 
                     value=\"" . $rec['investigator_list'] . "\">
            </td>
            <td valign=\"top\" id=\"investigatorList\"></id>
            <td valign=\"top\" id=\"enterInvestigator\"
                style=\"background-color:#e0e0e0\"></td>";
       } 
       
       // RESOURCES
       else if ($field['name'] == 'resource_list') {
          echo "
            <td valign=\"top\">
              " . showHelp($field['help']) . "
              <input type=\"hidden\" name=\"resource_input_type\" 
                     id=\"resource_input_type\" value=\"\">
              <input type=\"hidden\" name=\"resource_list\" id=\"resource_list\" 
                     value=\"" . $rec['resource_list'] . "\">
            </td>";
         echo "
            <td valign=\"top\" id=\"resourceList\"></id>
            <td valign=\"top\" id=\"selectResource\"
                style=\"background-color:#e0e0e0\"></td>";
       }
       
       // PROJECTS
       else if ($field['name'] == 'project_list') {
          echo "
            <td valign=\"top\">
              " . showHelp($field['help']) . "
              <input type=\"hidden\" name=\"project_list\" id=\"project_list\" 
                     value=\"" . $rec['project_list'] . "\">
            </td>";
          echo "
            <td valign=\"top\" id=\"projectList\"></id>
            <td valign=\"top\" id=\"selectProject\"
                style=\"background-color:#e0e0e0\"></td>";
       }
       
       // ALERTS
       else if ($field['name'] == 'alert_list') {
          echo "
            <td valign=\"top\">
               <input type=\"hidden\" name=\"alert_input_type\" 
                      id=\"alert_input_type\" value=\"\">
               <input type=\"hidden\" name=\"alert_list\" id=\"alert_list\" 
                      value=\"" . $rec['alert_list'] . "\">
            </td>";
          echo "
            <td valign=\"top\" id=\"alertList\"></id>
            <td valign=\"top\" id=\"enterAlert\"
                style=\"background-color:#e0e0e0\"></td>";
       }
    }//for each field
  }//showEditFormFields
  
  
  function showEditProjectChanges() {
    global $pfields;
    
    // TO DO: check for problems like a duplicated URL in case URL was edited.
    $id = getCGIParamPC('active_id', 'P', -1);

    // Get existing record from database
    $old_rec = getProject($id);

    // Get new record from CGI parameters
    $new_rec = array();
    foreach (array_keys($pfields) as $field) {
      $new_rec[$field] = urldecode(getCGIParamPC($field, 'P', $old_rec[$field]));
    }

    // Get curation level and set as hidden field
    $new_lvl_num = getCGIParamPC('level', 'P', '');
    echo "
      <input type=\"hidden\" name=\"level\" value=\"$new_lvl_num\">";
    
    // Keep all the new values around in case user chooses to go back to edit page
    foreach (array_keys($new_rec) as $field) {
      echo "
      <input type=\"hidden\" name=\"" . $field . "\" 
             value=\"" . urlencode($new_rec[$field]) . "\">";
    }
    
    echo "
      <p><span class=\"report\">You requested the following changes:</span></p>
      <table cellspacing=8 cellpadding=5>
        <tr valign=\"top\">
          <td></td>
          <td><b>OLD VALUE</b></td>
          <td width=\"10px\"></td>
          <td><b>NEW VALUE</b></td>
        </tr>
        <tr height=\"1px\" bgcolor=\"black\"><td colspan=4></td></tr>\n";
      
    $changes_found = false;
    foreach (array_keys($pfields) as $field) {
//echo "\n<br>handle <b>$field</b>, dbfield: " . $pfields[$field]['dbfield'] . "<br>\n";
//echo "<b>old:</b> [" . $old_rec[$field] . "] <b>vs new:</b> [" . $new_rec[$field] . "]<br>\n";
    if ($old_rec[$field] != $new_rec[$field]) {
        $changes_found = true;
        if ($pfields[$field]['dbfield'] != 'n/a') {
          echo "
        <tr valign=\"top\">
          <td><b>" . $pfields[$field]['label'] . "</b></td>
          <td><i>" . $old_rec[$field] . "</i></td>
          <td></td>
          <td>" . $new_rec[$field] . "</td>
        </tr>";
        }//Simple field
        
        else {
          $old_recs = explode("||", trim($old_rec[$field]));
          $new_recs = explode("||", trim($new_rec[$field]));
//echo "OLD $field: " . $old_rec[$field] . "<pre>";var_dump($old_recs);echo "</pre>";
//echo "NEW record: <pre>";var_dump($new_rec);echo "</pre>";
//echo "NEW $field: " . $new_rec[$field] . "<pre>";var_dump($new_recs);echo "</pre>";

          echo "
        <tr valign=\"top\">
          <td><b>" . $pfields[$field]['label'] . "</b></td>
          <td>
            <i>";
          if ($old_rec[$field] == '' || count($old_recs) == 0) {
            echo "[NONE]";
          }
          else {
            foreach ($old_recs as $rec) {
              $fields = explode("|", $rec);
              array_pop($fields); // Don't display auto_num
              echo "&bull; " . join(" : ", $fields) . "<br>";
            }
          }
          echo "
            </ul>
          </td>
          <td></td>
          <td>";
          if ($new_rec[$field] == '' || count($new_recs) == 0) {
            echo "[NONE]";
          }
          else {
            foreach ($new_recs as $rec) {
              $fields = explode("|", $rec);
              array_pop($fields); // Don't display auto_num
              echo "&bull; " . join(" : ", $fields) . "<br>";
            }
          }
          echo "
            </ul>
          </td>
        </tr>\n";
        }//field generated from assoc table
      }//field changed
    }//foreach
    
    // Check if curation lvl changed
    global $curation_levels;
    $new_lvl_num = getCGIParamPC('level', 'P', '');
    $old_lvl_num = getRecordCurationLvl($id);
    if ($new_lvl_num != $old_lvl_num) {
      $changes_found = true;
      foreach ($curation_levels as $level) {
        if ($level[1] == $new_lvl_num) $new_level = $level[0];
        if ($level[1] == $old_lvl_num) $old_level = $level[0];
      }
      echo "
        <tr>
          <td><b>Curation lvl</b></td>
          <td>$old_level</td>
          <td></td>
          <td>$new_level</td>
        </tr>";
    }

    if (!$changes_found) {
      echo "
        <tr>
          <td colspan=4 align=\"center\"><i>--- No Changes ---</i></td>
        </tr>\n";
    }

    echo "
        <tr height=\"1px\" bgcolor=\"black\"><td colspan=4></td></tr>
        <tr height=\"10px\"><td colspan=4></td></tr>
        <tr>
          <td colspan=4>
            <input type=\"button\" value=\"Confirm changes\" 
                   onclick=\"goToStage(" . CUR_EDIT_PROJECT_4 . ")\">
            <input type=\"button\" value=\"Cancel\" 
                   onclick=\"goToStage(" . CUR_EDIT_PROJECT_2 . ")\">";
  
    echo "</table>";  
  }//showEditProjectChanges()
  
  
  function showEditProjectForm($id) {
    global $pfields;
    
    // Initialize record
    if ($id < 0) {
      $rec = array();
      foreach (array_keys($pfields) as $field) {
        $rec[$field] = '';
      }
    }
    else {
      $rec = getProject($id);
      // ID for currently-edited record
      echo "
      <input type=\"hidden\" name=\"id\" value=\"$id\">";
    }
    
    // if 'active_id' and 'id' are the same, form values override
    // (else we are loading a new record and don't want to replace its fields
    //  with anything left over in the post fields from the previous record)
    if (getCGIParamPC('active_id', 'P', '') == getCGIParamPC('id', 'P', '')) {
      foreach (array_keys($pfields) as $field) {
        $rec[$field] = urldecode(getCGIParamPC($field, 'P', $rec[$field]));
      }
    }
    // (else we are loading a new record and don't want to replace its fields
    //  with anything left over in the post fields from the previous record)

    // Start form table
    echo "
      <p class=\"definition\">" . PROJECT_DEF . "</p>
      <table>
        <tr>
          <td>ID:</td>
          <td>$id</td>
        </tr>";
      
    // Initial curation level
    showCurationLevelControl($id);

    // Build form fields from record definition array
    showEditFormFields($pfields, $rec);
        
    // End table
    echo "
      </table>";
    
    echo "
      <p><i>Any amount of information accepted (record need not be complete)</i></p>";
    
    // Buttons
    $submit_stage = ($id < 0) ? CUR_ADD_PROJECT_2 : CUR_EDIT_PROJECT_3;
    $submit_text = ($id < 0) ? 'Add project' : 'Submit changes';
    $submit_click = "if(verifyProjectForm(document.curationform))";
    $submit_click .= "{goToStage($submit_stage)}";
    $cancel_stage = ($id < 0) ? CUR_START : CUR_EDIT_PROJECT_1;
    $cancel_click = "goToStage($cancel_stage)";
    echo"
      <input type=\"button\" value=\"$submit_text\" onclick=\"$submit_click\">
      <input type=\"button\" value=\"Cancel\" onclick=\"$cancel_click\">\n";
    
    // End form
    echo "
     </form>";
     
    // Set initial form state
    echo "
      <script language=\"JavaScript\">
        // Set selected lists
        setFundingList(document.curationform);
        setInstitutionList(document.curationform);
        setInvestigatorList(document.curationform);
        setCategoryList(document.curationform);
        setResourceList(document.curationform);
        setProjectList(document.curationform);
        setAlertList(document.curationform);
        
        // Show selection choices
        setFundingRow(document.curationform);
        setInstitutionRow(document.curationform);
        setInvestigatorRow(document.curationform);
        setCategoryRow(document.curationform);
        setResourceRow(document.curationform);
        setProjectRow(document.curationform);
        setAlertRow(document.curationform);
      </script>\n";
  }//showEditProjectForm()
  
  
  function showEditProjectTitle($stage) {
     global $stage_navigation;
     
    // Set nav line
    $navs = array($stage_navigation[CUR_START]);
    if ($stage >= CUR_EDIT_PROJECT_1)
      array_push($navs, $stage_navigation[CUR_EDIT_PROJECT_1]);
    if ($stage >= CUR_EDIT_PROJECT_2)
      array_push($navs, $stage_navigation[CUR_EDIT_PROJECT_2]);
    if ($stage >= CUR_EDIT_PROJECT_3)
      array_push($navs, $stage_navigation[CUR_EDIT_PROJECT_3]);
    if ($stage >= CUR_EDIT_PROJECT_4)
      array_push($navs, $stage_navigation[CUR_EDIT_PROJECT_4]);
      
    showTitle($navs, $stage);
  }//showEditProjectTitle()
  
  
  function showEditResourceChanges() {
    global $rfields;
    
    // TO DO: check for problems like a duplicated URL in case URL was edited.
    $id = getCGIParamPC('active_id', 'P', -1);

    // Get existing record from database
    $old_rec = getResource($id);

    // Get new record from CGI parameters
    $new_rec = array();
    foreach (array_keys($rfields) as $field) {
      $new_rec[$field] = urldecode(getCGIParamPC($field, 'P', $old_rec[$field]));
    }

    // Keep all the new values around in case user chooses to go back to edit page
    foreach (array_keys($new_rec) as $field) {
      echo "
      <input type=\"hidden\" name=\"" . $field . "\" 
             value=\"" . urlencode($new_rec[$field]) . "\">";
    }
    
    // Get curation level and set as hidden field
    $new_lvl_num = getCGIParamPC('level', 'P', '');
    echo "
      <input type=\"hidden\" name=\"level\" value=\"$new_lvl_num\">";
      
    echo "
      <p><span class=\"report\">You requested the following changes:</span></p>
      <table cellspacing=4 cellpadding=1>
        <tr valign=\"top\">
          <td></td>
          <td><b>OLD VALUE</b></td>
          <td width=10></td>
          <td><b>NEW VALUE</b></td>
        </tr>
        <tr height=\"1px\" bgcolor=\"black\"><td colspan=4></td></tr>\n";
    
    $changes_found = false;
    foreach (array_keys($rfields) as $field) {
//echo "\n<br>handle $field, dbfield: " . $rfields[$field]['dbfield'] . "<br>\n";
      if ($old_rec[$field] != $new_rec[$field]) {
        $changes_found = true;
//echo "changes found: $changes_found<br>";
        if ($rfields[$field]['dbfield'] != 'n/a') {
          echo "
        <tr valign=\"top\">
          <td><b>" . $rfields[$field]['label'] . "</b></td>
          <td><i>" . $old_rec[$field] . "</i></td>
          <td></td>
          <td>" . $new_rec[$field] . "</td>
        </tr>";
        }//Simple field
        
        else {
          $old_recs = explode("||", trim($old_rec[$field]));
          $new_recs = explode("||", trim($new_rec[$field]));
//echo "OLD $field: " . $old_rec[$field] . "<pre>";var_dump($old_recs);echo "</pre>";
//echo "NEW record: <pre>";var_dump($new_rec);echo "</pre>";
//echo "NEW $field: " . $new_rec[$field] . "<pre>";var_dump($new_recs);echo "</pre>";

          echo "
        <tr valign=\"top\">
          <td><b>" . $rfields[$field]['label'] . "</b></td>
          <td>
            <i>";
          if ($old_rec[$field] == '' || count($old_recs) == 0) {
            echo "[NONE]";
          }
          else {
            foreach ($old_recs as $rec) {
              $fields = explode("|", $rec);
              array_pop($fields); // Don't display auto_num
              echo "&bull; " . join(" : ", $fields) . "<br>";
            }
          }
          echo "
            </ul>
          </td>
          <td></td>
          <td>";
          if ($new_rec[$field] == '' || count($new_recs) == 0) {
            echo "[NONE]";
          }
          else {
            foreach ($new_recs as $rec) {
              $fields = explode("|", $rec);
              array_pop($fields); // Don't display auto_num
              echo "&bull; " . join(" : ", $fields) . "<br>";
            }
          }
          echo "
            </ul>
          </td>
        </tr>\n";
        }//field generated from assoc table
      }//field changed
    }//foreach
    
    // Check if level changed
    global $curation_levels;
    $new_lvl_num = getCGIParamPC('level', 'P', '');
    $old_lvl_num = getRecordCurationLvl($id);
    if ($new_lvl_num != $old_lvl_num) {
      $changes_found = true;
      foreach ($curation_levels as $level) {
        if ($level[1] == $new_lvl_num) $new_level = $level[0];
        if ($level[1] == $old_lvl_num) $old_level = $level[0];
      }
      echo "
        <tr>
          <td><b>Curation lvl</b></td>
          <td>$old_level</td>
          <td></td>
          <td>$new_level</td>
        </tr>";
    }

    if (!$changes_found) {
      echo "
        <tr>
          <td colspan=4 align=\"center\"><i>--- No Changes ---</i></td>
        </tr>\n";
    }

    echo "
        <tr height=\"1px\" bgcolor=\"black\"><td colspan=4></td></tr>
        <tr height=\"10px\"><td colspan=4></td></tr>
        <tr>
          <td colspan=4>
            <input type=\"button\" value=\"Confirm changes\" 
                   onclick=\"goToStage(" . CUR_EDIT_RESOURCE_4 . ")\">
            <input type=\"button\" value=\"Cancel\" 
                   onclick=\"goToStage(" . CUR_EDIT_RESOURCE_2 . ")\">";
  
    echo "</table>";  
  }//showEditResourceChanges
  
  
  function showEditResourceForm($id) {
    global $rfields;
    
    // Initialize record
    if ($id < 0) {
      $rec = array();
      foreach (array_keys($rfields) as $field) {
        $rec[$field] = '';
      }
    }
    else {
      $rec = getResource($id);
      if (!$rec || count($rec) == 0) {
         reportErrorPC("The resource $id has been lost from the database.");
         echo "<span style=\"color:green\"><b>There's an error in the database.";
         echo " This resource ($id) has been lost.</b></span>";
         return;
      }
      // ID for currently-edited record
      echo "
      <input type=\"hidden\" name=\"id\" value=\"$id\">";
    }
    
    // if 'active_id' and 'id' are the same, form values override
    // (else we are loading a new record and don't want to replace its fields
    //  with anything left over in the post fields from the previous record)
    if ($id < 0 
          || getCGIParamPC('active_id', 'P', '') == getCGIParamPC('id', 'P', '')) {
      foreach (array_keys($rfields) as $field) {
        $rec[$field] = urldecode(getCGIParamPC($field, 'P', $rec[$field]));
      }
    }

    // Start form table
    echo "
      <table>";

    // Initial curation level
    showCurationLevelControl($id);

    // Build form fields from record definition array
    showEditFormFields($rfields, $rec);

    // End table
    echo "
    </table>";
    
    // private comments...
    echo "
      <p><i>Any amount of information accepted (record need not be complete)</i></p>";
    
    // Buttons
    $submit_stage = ($id < 0) ? CUR_ADD_RESOURCE_4 : CUR_EDIT_RESOURCE_3;
    $submit_text = ($id < 0) ? 'Add resource' : 'Submit changes';
    $submit_click = "if(verifyResourceForm(document.curationform))";
    $submit_click .= "{goToStage($submit_stage)}";
    $cancel_stage = ($id < 0) ? CUR_START : CUR_EDIT_RESOURCE_1;
    $cancel_click = "goToStage($cancel_stage)";
    echo"
      <input type=\"button\" value=\"$submit_text\" onclick=\"$submit_click\">
      <input type=\"button\" value=\"Cancel\" onclick=\"$cancel_click\">\n";
    
    // End form
    echo "
     </form>";
     
    // Set initial form state
    echo "
      <script language=\"JavaScript\">
        setFundingList(document.curationform);
        setInstitutionList(document.curationform);
        setCategoryList(document.curationform);
        setAlertList(document.curationform);
        setInvestigatorList(document.curationform);
        setFundingRow(document.curationform);
        setInstitutionRow(document.curationform);
        setCategoryRow(document.curationform);
        setInvestigatorRow(document.curationform);
        setAlertRow(document.curationform);
      </script>";

  }//showEditResourceForm
  
  
  function showEditResourceTitle($stage) {
     global $stage_navigation;
     
    // Set nav line
    $navs = array($stage_navigation[CUR_START]);
    if ($stage >= CUR_EDIT_RESOURCE_1)
      array_push($navs, $stage_navigation[CUR_EDIT_RESOURCE_1]);
    if ($stage >= CUR_EDIT_RESOURCE_2)
      array_push($navs, $stage_navigation[CUR_EDIT_RESOURCE_2]);
    if ($stage >= CUR_EDIT_RESOURCE_3)
      array_push($navs, $stage_navigation[CUR_EDIT_RESOURCE_3]);
    if ($stage >= CUR_EDIT_RESOURCE_4)
      array_push($navs, $stage_navigation[CUR_EDIT_RESOURCE_4]);
      
    showTitle($navs, $stage);
  }//showEditResourceTitle()
  

  function showEditSearchChanges() {
    global $sfields;
    
    $id = getCGIParamPC('active_id', 'P', -1);

    // Get existing record from database
    $old_rec = getSearch($id);

    // Get new record from CGI parameters
    $new_rec = array();
    foreach (array_keys($sfields) as $field) {
      $new_rec[$field] = getCGIParamPC($field, 'P', $old_rec[$field]);
    }
    
    // Get curation level and set as hidden field
    $new_lvl_num = getCGIParamPC('level', 'P', '');
    echo "
      <input type=\"hidden\" name=\"level\" value=\"$new_lvl_num\">";

    // Keep all the new values around in case user chooses to go back to edit page
    foreach (array_keys($new_rec) as $field) {
      echo "
      <input type=\"hidden\" name=\"" . $field . "\" 
             value=\"" . urlencode($new_rec[$field]) . "\">";
    }
    
    echo "
      <p>You requested the following changes:</p>
      <table cellspacing=8 cellpadding=5>
        <tr valign=\"top\">
          <td></td>
          <td><b>OLD VALUE</b></td>
          <td width=\"10px\"></td>
          <td><b>NEW VALUE</b></td>
        </tr>";
      
    $changes_found = false;
    foreach (array_keys($sfields) as $field) {
//echo "\n<br>handle $field, dbfield: " . $sfields[$field]['dbfield'] . "<br>\n";
      if ($old_rec[$field] != $new_rec[$field]) {
        $changes_found = true;
        
        if ($sfields[$field]['dbfield'] != 'n/a') {
          echo "
        <tr valign=\"top\">
          <td><b>" . $sfields[$field]['label'] . "</b></td>
          <td><i>" . $old_rec[$field] . "</i></td>
          <td></td>
          <td>" . $new_rec[$field] . "</td>
        </tr>";
        }//Simple field
        
        else {
          $old_recs = explode("||", trim($old_rec[$field]));
          $new_recs = explode("||", trim($new_rec[$field]));
//echo "OLD $field: " . $old_rec[$field] . "<pre>";var_dump($old_recs);echo "</pre>";
//echo "NEW record: <pre>";var_dump($new_rec);echo "</pre>";
//echo "NEW $field: " . $new_rec[$field] . "<pre>";var_dump($new_recs);echo "</pre>";

          echo "
        <tr valign=\"top\">
          <td><b>" . $sfields[$field]['label'] . "</b></td>
          <td>
            <i>";
          if ($old_rec[$field] == '' || count($old_recs) == 0) {
            echo "[NONE]";
          }
          else {
            foreach ($old_recs as $rec) {
              $fields = explode("|", $rec);
              array_pop($fields); // Don't display auto_num
              echo "&bull; " . join(" : ", $fields) . "<br>";
            }
          }
          echo "
            </ul>
          </td>
          <td></td>
          <td>";
          if ($new_rec[$field] == '' || count($new_recs) == 0) {
            echo "[NONE]";
          }
          else {
            foreach ($new_recs as $rec) {
              $fields = explode("|", $rec);
              array_pop($fields); // Don't display auto_num
              echo "&bull; " . join(" : ", $fields) . "<br>";
            }
          }
          echo "
            </ul>
          </td>
        </tr>\n";
        }//field generated from assoc table
      }//field changed
    }//foreach
    
    // Check if curation lvl changed
    global $curation_levels;
    $new_lvl_num = getCGIParamPC('level', 'P', '');
    $old_lvl_num = getRecordCurationLvl($id);
    if ($new_lvl_num != $old_lvl_num) {
      $changes_found = true;
      foreach ($curation_levels as $level) {
        if ($level[1] == $new_lvl_num) $new_level = $level[0];
        if ($level[1] == $old_lvl_num) $old_level = $level[0];
      }
      echo "
        <tr>
          <td><b>Curation lvl</b></td>
          <td>$old_level</td>
          <td></td>
          <td>$new_level</td>
        </tr>";
    }

    // Handle no changes
    if (!$changes_found) {
      echo "
        <tr>
          <td colspan=4 align=\"center\"><i>--- No Changes ---</i></td>
        </tr>\n";
    }
    
    echo "
        <tr height=\"1px\" bgcolor=\"black\"><td colspan=4></td></tr>
        <tr height=\"10px\"><td colspan=4></td></tr>
        <tr>
          <td colspan=3>
            <input type=\"button\" value=\"Confirm changes\" 
                   onclick=\"goToStage(" . CUR_EDIT_SEARCH_4 . ")\">
            <input type=\"button\" value=\"Cancel\" 
                   onclick=\"goToStage(" . CUR_EDIT_SEARCH_2 . ")\">
          </td>
        </tr>
      </table>\n";
   }//showEditSearchChanges()
  
  
  function showEditSearchForm($id) {
    global $sfields;
    
    // Initialize record
    if ($id < 0) {
      $rec = array();
      foreach (array_keys($sfields) as $field) {
        $rec[$field] = '';
      }
    }
    else {
      $rec = getSearch($id);
      // ID for currently-edited record
      echo "
      <input type=\"hidden\" name=\"id\" value=\"$id\">";
    }
    
    // if 'active_id' and 'id' are the same, form values override
    // (else we are loading a new record and don't want to replace its fields
    //  with anything left over in the post fields from the previous record)
    if (getCGIParamPC('active_id', 'P', '') == getCGIParamPC('id', 'P', '')) {
      foreach (array_keys($sfields) as $field) {
        $rec[$field] = urldecode(getCGIParamPC($field, 'P', $rec[$field]));
      }
    }
    // (else we are loading a new record and don't want to replace its fields
    //  with anything left over in the post fields from the previous record)

    // Start form table
    echo "
      <table>
        <tr>
          <td>ID:</td>
          <td>$id</td>
        </tr>";
      
    // Initial curation level
    showCurationLevelControl($id);
    
    // Edit fields
    showEditFormFields($sfields, $rec);

    // End table
    echo "
      </table>";
   
    // private comments...
    echo "
      <p><i>
        This data is tightly connected to the code. Edit with great caution.
      </i></p>";
    
    // Buttons
    $submit_stage = ($id < 0) ? CUR_ADD_SEARCH_2 : CUR_EDIT_SEARCH_3;
    $submit_text = ($id < 0) ? 'Add search' : 'Submit changes';
    $submit_click = "goToStage($submit_stage)";
    $cancel_stage = ($id < 0) ? CUR_START : CUR_EDIT_SEARCH_1;
    $cancel_click = "goToStage($cancel_stage)";
    echo"
      <input type=\"button\" value=\"$submit_text\" onclick=\"$submit_click\">
      <input type=\"button\" value=\"Cancel\" onclick=\"$cancel_click\">\n";
    
    // End form
    echo "
     </form>";
    
    // Set initial form state
    echo "
      <script language=\"JavaScript\">
        // Set selected lists
        setCategoryList(document.curationform);
        setResourceList(document.curationform);
        setProjectList(document.curationform);
        setAlertList(document.curationform);
        
        // Show selection choices
        setCategoryRow(document.curationform);
        setResourceRow(document.curationform);
        setProjectRow(document.curationform);
        setAlertRow(document.curationform);
      </script>\n";
  }//showEditSearchForm()
  
  
  function showEditSearchTitle($stage) {
     global $stage_navigation;
     
    // Set nav line
    $navs = array($stage_navigation[CUR_START]);
    if ($stage >= CUR_EDIT_SEARCH_1)
      array_push($navs, $stage_navigation[CUR_EDIT_SEARCH_1]);
    if ($stage >= CUR_EDIT_SEARCH_2)
      array_push($navs, $stage_navigation[CUR_EDIT_SEARCH_2]);
    if ($stage >= CUR_EDIT_SEARCH_3)
      array_push($navs, $stage_navigation[CUR_EDIT_SEARCH_3]);
    if ($stage >= CUR_EDIT_SEARCH_4)
      array_push($navs, $stage_navigation[CUR_EDIT_SEARCH_4]);
      
    showTitle($navs, $stage);
  }//showEditSearchTitle()
  
  
  function showFundingRecs($funding_list) {
    if (trim($funding_list) == '') {
      return;
    }
    
    echo "
      <table>";
    $funds = explode("||", $funding_list);
    foreach ($funds as $fund) {
      $fields = explode("|", $fund);
      echo "
        <tr>
          <td>" . $fields[0] . "</td>
          <td>(" . $fields[1] . ")</td>
        </tr>";
    }//foreach funding source
    echo "
      </table>";
  }//showFundingRecs()
  
  
  function showHelp($name) {
    $url = "javascript:popupLink('POPcornCurationHelp.html', 400, 250, '$name')";
    $html = "
      <span style=\"background-color:#cccb9c\">
        &nbsp;<a href=\"$url\"><b>?</b></a>&nbsp;
      </span>";
    return $html;
  }//showHelp()
  
  
  function showInstitutionRecs($institution_list) {
    if (trim($institution_list) == '') {
      return;
    }
    
    echo "
      <table>
        <tr>";
    $insts = explode("||", $institution_list);
    $count = 1;
    foreach ($insts as $inst) {
      $fields = explode("|", $inst);
      echo "
          <td>&nbsp;&nbsp;&bull; " . $fields[0] . "&nbsp;</td>";
      if (($count%4) == 0) {
        echo "
        </tr>
        <tr>";
      }
      $count++;
    }//foreach institution
    echo "
        </tr>
      </table>";
  }//showInstitutionRecs()
  
  
  function showInvestigatorRecs($investigator_list) {
    if (trim($investigator_list) == '') {
      return;
    }
    
    echo "
      <table>";
    $invs = explode("||", $investigator_list);
    foreach ($invs as $inv) {
      $fields = explode("|", $inv);
      echo "
        <tr>
          <td>" . $fields[0] . "</td>
          <td>(" . $fields[1] . ")</td>
        </tr>";
    }//foreach investigator
    echo "
      </table>";
  }//showInvestigatorRecs()
  
  
  function showLevelChangeControls($id, $next_stage, $edit_stage) {
    global $curation_levels;
    
    $lvl = getRecordCurationLvl($id);

    // Draw drop-down
    echo "
      <br><br>
      <select name=\"level\">";
    foreach (array_keys($curation_levels) as $level) {
      $selected = ($lvl == $curation_levels[$level][1]) ? 'selected' : '';
      echo "
              <option value=\"" . $curation_levels[$level][1] . "\" $selected>" 
                . $curation_levels[$level][0] . "</option>";
    }
    echo "
      </select>";
    
    // Change-level button
    $change_url = "changeLevel(document.curationform, $id, $next_stage)";
    $edit_url = "goToStage($edit_stage)";
    $cancel_url = "goToStage(" . CUR_EDIT_RESOURCE_1 . ")";
    echo "
      <input type=\"button\" value=\"Change Level\" onclick=\"$change_url\">
      <input type=\"button\" value=\"Edit\" onclick=\"$edit_url\">
      <input type=\"button\" value=\"Cancel\" onclick=\"$cancel_url\">\n";

    // End form
    echo "
     </form>";
  }//showLevelChangeControls()
  
  
  function showMuliLevelChangeTitle($stage) {
    global $stage_navigation;
     
    // Setnav line
    $navs = array($stage_navigation[CUR_START], 
                  $stage_navigation[CUR_MULTI_LEVEL_CHANGE]);
    
    showTitle($navs, $stage);
  }//showMuliLevelChangeTitle()
  
  
  function showProject($id) {
    global $pfields;
    
    $rec = getProject($id);
//cho "<pre>";var_dump($rec);echo "</pre>";
    showRecord($pfields, $rec);
  }//showProject
  
  
  function showProjectFilter() {
    global $curation_levels, $project_filters;
    $filters = getParams($project_filters, true); // true: look in $_SESSION too
//echo "<pre>";var_dump($filters);echo "</pre>";

    echo "
      <p class=\"definition\">" . PROJECT_DEF . "</p>
      <table bgcolor=\"#e3e3e3\">
        <tr>
          <td colspan=5 align=\"center\"><h3>Filter Project List</h3></td>
        </tr>";
        
    // ID
    echo "
        <tr>
          <td><b>ID:</b></td>
          <td>
            <input type=\"text\" name=\"filter_id\" size=10
                   value=\"" . $filters['id'] . "\">
          </td>
          <td width=10></td>
          <td></td>
          <td></td>
        </tr>";
        
    //NAME
    echo "
        <tr>
          <td><b>Name:</b></td>
          <td>
            <input type=\"text\" name=\"filter_name\" size=50
                   value=\"" . $filters['project_name'] . "\">
          </td>
          <td width=10></td>";
          
    // CURATION LVL
    echo "
          <td><b>Curation lvl:</b></td>
          <td>
            <select name=\"filter_lvl\">
              <option value=\"-1\">Any visible</option>";
    foreach (array_keys($curation_levels) as $level) {
      $selected = ($filters['level'] == $curation_levels[$level][1]) 
                      ? 'selected' : '';
      echo "
              <option value=\"" . $curation_levels[$level][1] . "\" $selected>" 
                . $curation_levels[$level][0] . "</option>";
    }
    echo "
            </select>
          </td>
        </tr>";
        
    // DESCRIPTION
    echo "
        <tr>
          <td><b>Description:</b></td>
          <td>
            <input type=\"text\" name=\"filter_description\" size=50
                   value=\"" . mgdb_html($filters['description']) . "\">
          </td>
          <td></td>";
          
    // LAST EDITED
    $dates = array(array('', 'Any Time'), array('1', 'Today'), 
                   array('7', 'This Week'),
                   array('30', 'This Month'));
    echo "
          <td><b>Last edited:</b></td>
          <td>
            <select name=\"filter_edited\">";
    foreach($dates as $date) {
      $selected = ($filters['edited'] == $date[0]) ? 'selected' : '';
      echo "
              <option value=\"". $date[0] . "\" $selected>"
                . $date[1] . "</option>";
    }
    echo "
            </select>
          </td>
        </tr>";
        
    // CURATOR NOTES
    echo "
        <tr>
          <td><b>Curator Notes:</b></td>
          <td>
            <input type=\"text\" name=\"filter_notes\" size=50
                   value=\"" . $filters['notes'] . "\">
          </td>
          <td></td>";
          
    // CREATED
    $dates = array(array('', 'Any Time'), array('1', 'Today'), 
                   array('7', 'This Week'),
                   array('30', 'This Month'));
    echo "
          <td><b>Created:</b></td>
          <td>
            <select name=\"filter_created\">";
    foreach($dates as $date) {
      $selected = ($filters['created'] == $date[0]) ? 'selected' : '';
      echo "
              <option value=\"". $date[0] . "\" $selected>"
                . $date[1] . "</option>";
    }
    echo "
            </select>
          </td>
        </tr>";
        
    // SORT
/* ORACLE */
    $sort_opts = array(
                        array('NLS_LOWER(NAME)', 'Name'),
                        array('ID_NUM.ID', 'ID'),
                        array('MOD_DATE', 'Mod date'),
                        array('CURATION_LVL', 'Curation level'),
                        array('NLS_LOWER(DESCRIPTION)', 'Description'),
                        array('NLS_LOWER(NOTES)', 'Notes')
                      );
/**/
/* POSTGRES 
    $sort_opts = array(
                        array('LOWER(NAME)', 'Name'),
                        array('ID_NUM.ID', 'ID'),
                        array('MOD_DATE', 'Mod date'),
                        array('CURATION_LVL', 'Curation level'),
                        array('LOWER(DESCRIPTION)', 'Description'),
                        array('LOWER(NOTES)', 'Notes')
                      );
*/
    $sort = getCGIParamPC('sort', 'P', 
                        getSessionParam('sort', $sort_opts[0][0]));
    $sort_direction = getCGIParamPC('sort_direction', 'P', 
                        getSessionParam('sort_direction', 'ASC'));
//echo "<br>sort: $sort, sort_direction: $sort_direction<br>";
    echo "
      <tr>
        <td></td>
        <td align=\"center\">
          <b>Sort by</b>
          <select name=\"sort\">";
    foreach ($sort_opts as $opt) {
      $selected = ($sort == $opt[0]) ? 'selected' : '';
      echo "
            <option value=\"" . $opt[0] . "\" $selected>" . $opt[1] . "</option>";
    }
    echo "
          </select>";
    $asc_selected = ($sort_direction == 'ASC') ? 'selected' : '';
    $desc_selected = ($sort_direction == 'DESC') ? 'selected' : '';
    echo "
          <select name=\"sort_direction\">
            <option value=\"ASC\" $asc_selected>Ascending</option>
            <option value=\"DESC\" $desc_selected>Descending</option>
          </select>
        </td>";
    
    // CREATED BY ME
    $checked = ($filters['created_me'] == 'on') ? 'checked' : '';
    echo "
          <td></td>
          <td colspan=2>
            <input type=\"checkbox\" name=\"filter_created_me\" $checked>
            <b>Only created by me</b>
          </td>
        </tr>";
        
    // EDITED BY ME
    $checked = ($filters['edited_me'] == 'on') ? 'checked' : '';
    echo "
        <tr>
          <td></td>
          <td></td>
          <td></td>
          <td colspan=2>
            <input type=\"checkbox\" name=\"filter_edited_me\" $checked>
            <b>Only edited by me</b>
          </td>
        </tr>";
        
        
    // DONE
    echo "
        <tr>
          <td></td>
          <td colspan=4>
            <input type=\"button\" value=\"Apply Filter\"
                   onclick=this.form.submit()>
            <input type=\"button\" value=\"Cancel\"
                   onclick=goToStage(" . CUR_START . ")>
          </td>
        </tr>";
    echo "
      </table>
      <br><br>";
  }//showProjectFilter()
  
  
  function showProjectLevelTitle($stage) {
     global $stage_navigation;
//echo "showProjectLevelTitle($stage)<br>";
//echo "<pre>";var_dump($stage_navigation);echo "</pre>";
    // Set nav line
    $navs = array($stage_navigation[CUR_START]);
    // CUR_PROJECT_LVL_1 not used
    if ($stage >= CUR_PROJECT_LVL_2)
      array_push($navs, $stage_navigation[CUR_PROJECT_LVL_2]);
    if ($stage == CUR_PROJECT_LVL_3)
      array_push($navs, $stage_navigation[CUR_PROJECT_LVL_3]);
    if ($stage >= CUR_PROJECT_LVL_4)
      array_push($navs, $stage_navigation[CUR_PROJECT_LVL_4]);
      
    showTitle($navs, $stage);
  }//showProjectLevelTitle()
  
  
  function showProjectList() {
    global $project_filters, $curation_levels;
    $filters = getParams($project_filters, true); // true: look in $_SESSION too

//ORACLE
//    $sort = getCGIParamPC('sort', 'P', 
//                        getSessionParam('sort', 'NLS_LOWER(NAME)'));
    $sort = getCGIParamPC('sort', 'P', 
                        getSessionParam('sort', 'LOWER(NAME)'));
    $sort_direction = getCGIParamPC('sort_direction', 'P', 
                        getSessionParam('sort_direction', 'ASC'));
    
    // Keep these two around:
    $_SESSION['sort'] = $sort;
    $_SESSION['sort_direction'] = $sort_direction;
    
    $projects = getProjectList($filters, $sort, $sort_direction);
//echo "<pre>";var_dump($resources);echo "</pre>";

    echo "
      <table>
        <tr>
          <td colspan=4>
            <h3>Filtered list of projects:</h3>
          </td>
        </tr>";
    
    if (count($projects) == 0) {
      echo "
        <tr>
          <td><i>No projects found</i></td>
        </tr>";
    }
    else {
      echo "
        <tr>
          <td>
            <input type=\"checkbox\" 
                   onclick=\"selectAll(this, 'sel_')\">
          </td>
          <td><b>ID</b></td>
          <td><b>Curation lvl</b>&nbsp;</td>
          <td width=\"35%\"><b>Name</b></td>
          <td><b>Description</b></td>
          <td><b>Notes</b></td>
          <td><b>Mod Date</b></td>
        </tr>
        <tr height=2 bgcolor=\"#a0a0a0\"><td colspan=7></td></tr>";
      foreach ($projects as $project) {
        $edit_url = "javascript:editRecord(" 
                    . "document.curationform, " . $project['id'] . ", "
                    . CUR_EDIT_PROJECT_2 . ")";
        $lvlchange_url = "javascript:goToChangeLevel(document.curationform, " 
                         . $project['id'] . ", " . CUR_PROJECT_LVL_2 . ")";
        
        // Select project
        echo "
        <tr>
          <td>
            <input type=\"checkbox\" name=\"sel_" . $project['id'] . "\">
            <input type=\"hidden\" name=\"level_" . $project['id'] . "\"
                   value=\"" . $project['level'] . "\">
          </td>";
          
        // Project fields
        echo "
          <td>" . $project['id'] . "</td>
          <td>
            <a href=\"$lvlchange_url\">" . $project['level'] . "</a>
          </td>
          <td>
            <a href=\"$edit_url\">" . $project['project_name'] . "</a>
          </td>
          <td>
            " . truncate($project['description'], 30) . "
          </td>
          <td>
            " . truncate($project['notes'], 30) . "
          </td>
          <td>
            " . $project['mod_date'] . " 
          </td>
        </tr>";
      }//foreach
      
/*MaizeGDB doesn't actually ever delete records      
      $url = "deleteRecords(document.curationform, 'sel_', " 
             . CUR_MULTI_DELETE_1 . ")";
      echo "
        <tr height=2 bgcolor=\"#a0a0a0\"><td colspan=8></td></tr>
        <tr>
          <td></td>
          <td colspan=7>
            <b>Delete</b> selected project records.
            <input type=\"button\" value=\"Go\" onclick=\"$url\">
          </td>
        </tr>";
*/

      // Change curation lvl for selected records
      $url = "changeCurationLvls(document.curationform, 'sel_', " 
             . CUR_MULTI_LEVEL_CHANGE . ")";
      echo "
        <tr height=2 bgcolor=\"#a0a0a0\"><td colspan=8></td></tr>
        <tr>
          <td></td>
          <td colspan=7>
            Set curation level to
            <select name=\"level\">";
      foreach (array_keys($curation_levels) as $level) {
        $selected = ($curation_levels[$level][1] == 10) ? 'selected' : '';
        echo "
                <option value=\"" . $curation_levels[$level][1] . "\" $selected>" 
                  . $curation_levels[$level][0] . "</option>";
      }
      echo "
            </select>
            for selected resource records.
            <input type=\"button\" value=\"Go\" onclick=\"$url\">
          </td>
        </tr>";
    }//else projects exist
    
    echo "
      </table>
      <br><br>";
      
  }//showProjectList()


  function showRecord($fields, $rec) {
    echo "
      <table>";
    
    foreach (array_keys($fields) as $key) {
       $field = $fields[$key];
       echo "
         <tr>
           <td><b>" . $field['label'] . "</b></td>
           <td>" . $rec[$key] . "</tr>
         </tr>";
    }
      
    echo "
      </table>";
  }//showRecord
  
  
  function showResource($id) {
    global $rfields;
    
    $rec = getResource($id);
//cho "<pre>";var_dump($rec);echo "</pre>";
    showRecord($rfields, $rec);
  }//showResource
  
  
  function showResourceFilter() {
    global $curation_levels, $resource_filters;
    $filters = getParams($resource_filters, true); // true: look in $_SESSION too
//echo "<pre>";var_dump($filters);echo "</pre>";

    echo "
      <p class=\"definition\">" . RESOURCE_DEF . "</p>
      <table bgcolor=\"#e3e3e3\">
        <tr>
          <td colspan=5 align=\"center\"><h3>Filter Resource List</h3></td>
        </tr>";
    // ID
    echo "
        <tr>
          <td><b>ID:</b></td>
          <td>
            <input type=\"text\" name=\"filter_id\" size=10
                   value=\"" . $filters['id'] . "\">
          </td>
          <td width=10></td>
          <td></td>
          <td></td>
        </tr>";
    //NAME
    echo "
        <tr>
          <td><b>Name:</b></td>
          <td>
            <input type=\"text\" name=\"filter_name\" size=50
                   value=\"" . $filters['resource_name'] . "\">
          </td>
          <td width=10></td>";
    // CURATION LVL
    echo "
          <td><b>Curation lvl:</b></td>
          <td>
            <select name=\"filter_lvl\">
              <option value=\"-1\">Any visible</option>";
    foreach (array_keys($curation_levels) as $level) {
      $selected = ($filters['level'] == $curation_levels[$level][1]) 
                      ? 'selected' : '';
      echo "
              <option value=\"" . $curation_levels[$level][1] . "\" $selected>" 
                . $curation_levels[$level][0] . "</option>";
    }
    echo "
            </select>
          </td>
        </tr>";
    // DESCRIPTION
    echo "
        <tr>
          <td><b>Description:</b></td>
          <td>
            <input type=\"text\" name=\"filter_description\" size=50
                   value=\"" . mgdb_html($filters['description']) . "\">
          </td>
          <td></td>";
    // LAST EDITED
    $dates = array(array('', 'Any Time'), array('1', 'Today'), 
                   array('7', 'This Week'),
                   array('30', 'This Month'));
    echo "
          <td><b>Last edited:</b></td>
          <td>
            <select name=\"filter_edited\">";
    foreach($dates as $date) {
      $selected = ($filters['edited'] == $date[0]) ? 'selected' : '';
      echo "
              <option value=\"". $date[0] . "\" $selected>"
                . $date[1] . "</option>";
    }
    echo "
            </select>
          </td>
        </tr>";
    // TUTORIAL
    echo "
        <tr>
          <td><b>Tutorial URL:</b></td>
          <td>
            <input type=\"text\" name=\"filter_tutorial\" size=50
                   value=\"" . $filters['tutorial'] . "\">
          </td>
          <td></td>";
    // CREATED
    $dates = array(array('', 'Any Time'), array('1', 'Today'), 
                   array('7', 'This Week'),
                   array('30', 'This Month'));
    echo "
          <td><b>Created:</b></td>
          <td>
            <select name=\"filter_created\">";
    foreach($dates as $date) {
      $selected = ($filters['created'] == $date[0]) ? 'selected' : '';
      echo "
              <option value=\"". $date[0] . "\" $selected>"
                . $date[1] . "</option>";
    }
    echo "
            </select>
          </td>
        </tr>";
    // URL
    echo "
        <tr>
          <td><b>URL:</b></td>
          <td>
            <input type=\"text\" name=\"filter_url\" size=50
                   value=\"" . $filters['url'] . "\">
          </td>
          <td></td>";
          
    // CREATED BY ME
    $checked = ($filters['created_me'] == 'on') ? 'checked' : '';
    echo "
          <td colspan=2>
            <input type=\"checkbox\" name=\"filter_created_me\" $checked>
            <b>Only created by me</b>
          </td>
        </tr>";
        
    // CURATOR NOTES
    echo "
        <tr>
          <td><b>Curator Notes:</b></td>
          <td>
            <input type=\"text\" name=\"filter_notes\" size=50
                   value=\"" . $filters['notes'] . "\">
          </td>
          <td></td>";
          
    // EDITED BY ME
    $checked = ($filters['edited_me'] == 'on') ? 'checked' : '';
    echo "
          <td colspan=2>
            <input type=\"checkbox\" name=\"filter_edited_me\" $checked>
            <b>Only edited by me</b>
          </td>
        </tr>";
    
    // SORT
//ORACLE
//    $sort_opts = array(
//                        array('NLS_LOWER(NAME)', 'Name'),
//                        array('ID_NUM.ID', 'ID'),
//                        array('MOD_DATE', 'Mod date'),
//                        array('CURATION_LVL', 'Curation level'),
//                        array('NLS_LOWER(DESCRIPTION)', 'Description'),
//                        array('NLS_LOWER(URL)', 'URL'),
//                        array('NLS_LOWER(NOTES)', 'Notes')
//                      );
    $sort_opts = array(
                        array('LOWER(NAME)', 'Name'),
                        array('ID_NUM.ID', 'ID'),
                        array('MOD_DATE', 'Mod date'),
                        array('CURATION_LVL', 'Curation level'),
                        array('LOWER(DESCRIPTION)', 'Description'),
                        array('LOWER(URL)', 'URL'),
                        array('LOWER(NOTES)', 'Notes')
                      );
    $sort = getCGIParamPC('sort', 'P', 
                          getSessionParam('sort', $sort_opts[0][0]));
    $sort_direction = getCGIParamPC('sort_direction', 'P', 
                                    getSessionParam('sort_direction', 'ASC'));
    echo "
      <tr>
        <td></td>
        <td align=\"center\">
          <b>Sort by</b>
          <select name=\"sort\">";
    foreach ($sort_opts as $opt) {
      $selected = ($sort == $opt[0]) ? 'selected' : '';
      echo "
            <option value=\"" . $opt[0] . "\" $selected>" . $opt[1] . "</option>";
    }
    echo "
          </select>";
    $asc_selected = ($sort_direction == 'ASC') ? 'selected' : '';
    $desc_selected = ($sort_direction == 'DESC') ? 'selected' : '';
    echo "
          <select name=\"sort_direction\">
            <option value=\"ASC\" $asc_selected>Ascending</option>
            <option value=\"DESC\" $desc_selected>Descending</option>
          </select>
        </td>
      </tr>";
    
    // DONE
    echo "
        <tr>
          <td></td>
          <td colspan=4>
            <input type=\"button\" value=\"Apply Filter\"
                   onclick=this.form.submit()>
            <input type=\"button\" value=\"Cancel\"
                   onclick=goToStage(" . CUR_START . ")>
          </td>
        </tr>";
    echo "
      </table>
      <br><br>";
  }//showResourceFilter
  
  
  function showResourceLevelTitle($stage) {
     global $stage_navigation;
     
    // Set nav line
    $navs = array($stage_navigation[CUR_START]);
    // CUR_RESOURCE_LVL_1 not used
    if ($stage >= CUR_RESOURCE_LVL_2)
      array_push($navs, $stage_navigation[CUR_RESOURCE_LVL_2]);
    if ($stage == CUR_RESOURCE_LVL_3)
      array_push($navs, $stage_navigation[CUR_RESOURCE_LVL_3]);
    if ($stage >= CUR_RESOURCE_LVL_4)
      array_push($navs, $stage_navigation[CUR_RESOURCE_LVL_4]);
      
    showTitle($navs, $stage);
  }//showResourceLevelTitle()
  
  
  function showResourceList() {
    global $resource_filters, $curation_levels;
    $filters = getParams($resource_filters, true); // true: look in $_SESSION too
//ORACLE
//    $sort = getCGIParamPC('sort', 'P', 
//                        getSessionParam('sort', 'NLS_LOWER(NAME)'));
    $sort = getCGIParamPC('sort', 'P', 
                        getSessionParam('sort', 'LOWER(NAME)'));
    $sort_direction = getCGIParamPC('sort_direction', 'P', 
                        getSessionParam('sort_direction', 'ASC'));
    
    // Keep these two around:
    $_SESSION['sort'] = $sort;
    $_SESSION['sort_direction'] = $sort_direction;

    $resources = getResourceList($filters, $sort, $sort_direction);

    echo "
      <table>
        <tr>
          <td colspan=4>
            <h3>Filtered list of resources:</h3>
          </td>
        </tr>";
    
    if (count($resources) == 0) {
      echo "
        <tr>
          <td><i>No resources found</i></td>
        </tr>";
    }
    else {
      echo "
        <tr>
          <td>
            <input type=\"checkbox\" 
                   onclick=\"selectAll(this, 'sel_')\">
          </td>
          <td><b>ID</b></td>
          <td><b>Curation lvl</b>&nbsp;</td>
          <td width=\"35%\"><b>Name</b></td>
          <td><b>Description</b></td>
          <td><b>URL</b></td>
          <td><b>Notes</b></td>
          <td><b>Mod Date</b></td>
        </tr>
        <tr height=2 bgcolor=\"#a0a0a0\"><td colspan=8ß></td></tr>";
      foreach ($resources as $resource) {
        $edit_url = "javascript:editRecord(" 
                    . "document.curationform, " . $resource['id'] . ", "
                    . CUR_EDIT_RESOURCE_2 . ")";
        $lvlchange_url = "javascript:goToChangeLevel(document.curationform, " 
                         . $resource['id'] . ", " . CUR_RESOURCE_LVL_2 . ")";
        
        // Select resource
        echo "
        <tr>
          <td>
            <input type=\"checkbox\" name=\"sel_" . $resource['id'] . "\">
            <input type=\"hidden\" name=\"level_" . $resource['id'] . "\"
                   value=\"" . $resource['level'] . "\">
          </td>";
          
        // resource fields
        echo "
          <td>" . $resource['id'] . "</td>
          <td>
            <a href=\"$lvlchange_url\">" . $resource['level'] . "</a>
          </td>
          <td>
            <a href=\"$edit_url\">" . $resource['resource_name'] . "</a>
          </td>";
        echo "
          <td>
            " . truncate($resource['description'], 30) . "
          </td>
          <td>
            " . truncate($resource['url'], 30) . "
          </td>
          <td>
            " . truncate($resource['notes'], 30) . "
          </td>
          <td>
            " . $resource['mod_date'] . " 
          </td>
        </tr>";
      }//foreach
      
/*MaizeGDB doesn't actually ever delete records      
      // Delete selected records
      $url = "deleteRecords(document.curationform, 'sel_', " 
             . CUR_MULTI_DELETE_1 . ")";
      echo "
        <tr height=2 bgcolor=\"#a0a0a0\"><td colspan=8></td></tr>
        <tr>
          <td></td>
          <td colspan=7>
            Delete selected resource records.
            <input type=\"button\" value=\"Go\" onclick=\"$url\">
          </td>
        </tr>";
*/

      // Change curation lvl for selected records
      $url = "changeCurationLvls(document.curationform, 'sel_', " 
             . CUR_MULTI_LEVEL_CHANGE . ")";
      echo "
        <tr height=2 bgcolor=\"#a0a0a0\"><td colspan=8></td></tr>
        <tr>
          <td></td>
          <td colspan=7>
            Set curation level to
            <select name=\"level\">";
      foreach (array_keys($curation_levels) as $level) {
        $selected = ($curation_levels[$level][1] == 10) ? 'selected' : '';
        echo "
                <option value=\"" . $curation_levels[$level][1] . "\" $selected>" 
                  . $curation_levels[$level][0] . "</option>";
      }
      echo "
            </select>
            for selected resource records.
            <input type=\"button\" value=\"Go\" onclick=\"$url\">
          </td>
        </tr>";
    
      // Assign alerts
/*not yet implemented
      $url = "changeOwnership(document.curationform, 'sel_', " 
             . CUR_MULTI_CHANGE_OWNERSHIP . ")";
      $curators = getSuperCurators();
//echo "curators: <pre>";var_dump($curators);echo "</pre>";
      echo "
        <tr>
          <td></td>
          <td colspan=7>
            Change ownership to
            <select name=\"owner\">";
      foreach ($curators as $curator) {
        $selected = '';//($curators[$curator][1] == 10) ? 'selected' : '';
        echo "
                <option value=\"" . $curator[0] . "\" $selected>" 
                  . $curator[1] . " " . $curator[2] . "</option>";
      }
      echo "
            </select>
            for selected resource records.
            <input type=\"button\" value=\"Go\" onclick=\"$url\">
          </td>
        </tr>";
*/      
    }//else resources exist
    
    echo "
      </table>
      <br><br>";
      
  }//showResourceList


  function showResourceRecs($resource_list) {
    if (trim($resource_list) == '') {
      return;
    }
    
    echo "
      <table>";
    $ress = explode("||", $resource_list);
    foreach ($ress as $res) {
      $fields = explode("|", $res);
      echo "
        <tr>
          <td>" . $fields[0] . "</td>
          <td>(" . $fields[1] . ")</td>
        </tr>";
    }//foreach resource
    echo "
      </table>";
  }//showResourceRecs()
  
  
  function showSearch($id) {
    global $sfields;
    
    $rec = getSearch($id);
    showRecord($sfields, $rec);
  }//showSearch
  
  
  function showSearchLevelTitle($stage) {
     global $stage_navigation;

    // Set nav line
    $navs = array($stage_navigation[CUR_START]);
    // CUR_PROJECT_LVL_1 not used
    if ($stage >= CUR_SEARCH_LVL_2)
      array_push($navs, $stage_navigation[CUR_SEARCH_LVL_2]);
    if ($stage == CUR_SEARCH_LVL_3)
      array_push($navs, $stage_navigation[CUR_SEARCH_LVL_3]);
    if ($stage >= CUR_SEARCH_LVL_4)
      array_push($navs, $stage_navigation[CUR_SEARCH_LVL_4]);
      
    showTitle($navs, $stage);
  }//showSearchLevelTitle()
  
  
  function showSearchList() {
    $searches = getAllSearches();

    echo "
      <table>
        <tr>
          <td colspan=4>
            <h3>Existing searches:</h3>
          </td>
        </tr>";
    
    if (count($searches) == 0) {
      echo "
        <tr>
          <td><i>No searches found</i></td>
        </tr>";
    }
    else {
      echo "
        <tr>
          <td><b>ID</b></td>
          <td><b>Curation lvl</b></td>
          <td width=\"35%\"><b>Name</b></td>
          <td width=\"35%\"><b>Type</b></td>
          <td><b>Description</b></td>
          <td><b>Mod Date</b></td>
        </tr>
        <tr height=2 bgcolor=\"#a0a0a0\"><td colspan=7></td></tr>";
      foreach ($searches as $search) {
        $edit_url = "javascript:editRecord(" 
                    . "document.curationform, " . $search['id'] . ", "
                    . CUR_EDIT_SEARCH_2 . ")";
        $lvlchange_url = "javascript:goToChangeLevel(document.curationform, " 
                         . $search['id'] . ", " . CUR_SEARCH_LVL_2 . ")";
        echo "
          <tr>
            <td>" . $search['id'] . "</td>
            <td>
              <a href=\"$lvlchange_url\">" . $search['level'] . "</a>
            </td>
            <td>
              <a href=\"$edit_url\">" . $search['name'] . "</a>
            </td>
            <td>
              <a href=\"$edit_url\">" . $search['type'] . "</a>
            </td>
            <td>
              " . truncate($search['process'], 30) . "
            </td>
            <td>
              " . $search['mod_date'] . " 
            </td>
          </tr>";
      }//foreach
    }//else searches exist
    
    echo "
      </table>
      <br><br>";
  }//showSearchList()
  
  
  function showTitle($navs, $stage) {
    global $stage_navigation;
    
    // Link back to main curation:
    echo "<a href=\"http://curation.maizegdb.org/curation/curationtools/curationIndex1.cgi\">MaizeGDB curation</a> | ";
    
    for ($i=0; $i<count($navs); $i++) {
      if ($i < count($navs)-1) {
        echo "<a href=\"" . $navs[$i]['url'] . "\">";
        echo  $navs[$i]['navtitle'] . "</a> &gt;&gt; ";
      }
      else {
        echo "<b>" . $navs[$i]['navtitle'] . "</b>\n";
      }
    }// each nav line
    
    echo "
      <h2>" . $stage_navigation[$stage]['title'] . "</h2>";
  }//showTitle()
  
  
  //////////////////////////////////////////////////////////////////////////////
  function writeAddAlertBlock($auto_num) {
    echo "
        <input type=\"hidden\" name=\"alert_auto_num\" value=\"$auto_num\">
        <table>";
          
    // Trigger date
    $years = array('2015', '2016', '2017', '2018', '2019', '2020', '2021', '2022');
    $months = array('Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug',
                    'Sep', 'Oct', 'Nov', 'Dec');
    $days = array('01', '02', '03', '04', '05', '06', '07', '08', '09', '10', 
                  '11', '12', '13', '14', '15', '16', '17', '18', '19', '20', 
                  '21', '22', '23', '24', '25', '26', '27', '28', '29', '30', 
                  '31');
    echo "
          <tr>
            <td>" . showHelp('TriggerDate') . "</td>
            <td>Trigger Date:</td>
            <td>
              <select name=\"trigger_year\">
                <option value=\" \">year</option>";
    foreach ($years as $year) {
      echo "
                <option value=\"$year\">$year</option>";
    }
    echo "
              </select>
              <select name=\"trigger_month\">
                <option value=\" \">month</option>";
    foreach ($months as $month) {
      echo "
                <option value=\"$month\">$month</option>";
    }
    echo "
              </select>
              <select name=\"trigger_day\">
                <option value=\" \">day</option>";
    foreach ($days as $day) {
      echo "
                <option value=\"$day\">$day</option>";
    }
    echo "
              </select>
              <span class=\"report\" style=\"font-size:10pt\">
                * no date = always on
              </span>
            </td>
          </tr>";
    echo "
          <tr>
            <td>" . showHelp('AlertMessage') . "</td>
            <td>Alert:</td>
            <td>
              <textarea name=\"alert_msg\" rows=4 cols=45></textarea>
            </td>
          </tr>";
          
    $annotatorID = $_SESSION['annotatorID'];
    $curators = getSuperCurators();
    // Add current curator to head of array.
    array_unshift($curators, array($annotatorID, 'me', ''));
//echo "curators: <pre>";var_dump($curators);echo "</pre>";

    echo "
          <tr>
            <td>" . showHelp('AlertAssign') . "</td>
            <td>Assign to:</td>
            <td>
              <select name=\"assign\">";
    foreach ($curators as $curator) {
      $selected = '';//($curators[$curator][1] == 10) ? 'selected' : '';
      echo "
              <option value=\"" . $curator[0] . "\" $selected>" 
                . $curator[1] . " " . $curator[2] . "</option>";
    }
    echo "
              </select>
            </td>
          </tr>";
      
    echo "
          <tr>
            <td>" . showHelp('AlertHandled') . "</td>
            <td>Handled:</td>
            <td>
              <select name=\"alert_handled\">
                <option value=\"no\">no</option>
                <option value=\"yes\">yes</option>
              </select>
            </td>
          </tr>";
    echo "
          <tr>
            <td></td>
            <td colspan=2>
              <input type=\"button\" name=\"alrtBtn\" value=\"Add\"
                     onclick=\"setAlert(this.form)\">
              <input type=\"button\" name=\"alrtCancel\" value=\"Cancel\"
                     onclick=\"cancelEditAlert(this.form)\"
                     style=\"visibility:hidden\">
            </td>
        </table>\n";
  }//writeAddAlertBlock()


  //////////////////////////////////////////////////////////////////////////////
  function writeSelectCategoryBlock($auto_num) {
    $category = '';
    $onchange = "setCategory(this.form)";
    echo "
        <input type=\"hidden\" name=\"category_auto_num\" 
               value=\"$auto_num\">\n";
    echo createCurCategoryDropDown('category', 
                                   'Select a category',
                                   '', 
                                   $category, $onchange);
  }//writeCategoryBlock()
  
  
  //////////////////////////////////////////////////////////////////////////////
  function writeSelectFundingBlock($funding, $auto_num) {
    echo "
        <table>
          <tr>
            <td>" . showHelp('FundingSource') . "</td>
            <td>\n";
    echo "
              <input type=\"hidden\" name=\"funding_auto_num\" 
                     value=\"$auto_num\">\n";
    echo createCurFundingDropDown('funding', 'Select a funding source', 
                                   '', $funding, '');
    echo "
            </td>
          </tr>
          <tr>
            <td>" . showHelp('FundingURL') . "</td>
            <td>
              Award Abstract URL:
              <input type=\"text\" name=\"award_url\" size=45>
            </td>
          </tr>
          <tr>
            <td>" . showHelp('FundingKeywords') . "</td>
            <td>
              <input type=\"hidden\" name=\"fndKeywordsTmp\" id=\"fndKeywordsTmp\" value=\"\">
              <input type=\"button\" name=\"fndSetKeywords\" value=\"Set Keywords\"
                     onclick=\"showFundingKeywords(this.form)\">
              <span id=\"keywordstatus\" class=\"tinytype italic\">not set</span>
              
              <div id=\"keywordsdiv\" class=\"modal\">
                <div class=\"modal-content\">
                  <span class=\"close\" onclick=\"closeModalDialog('keywordsdiv')\">&times;</span>
                  <form>
                    Insert text below, then submit to have it scanned for keywords<br>
                    <textarea name=\"keywordtext\" id=\"keywordtext\" cols=70 rows=25></textarea>
                    <br>
                    <input type=\"button\" value=\"submit\" onclick=\"setFundingKeywords(this.form, 'keywordsdiv')\">
                    <input type=\"button\" value=\"cancel\" onclick=\"closeModalDialog('keywordsdiv')\">
                  </form>
                </div>
              </div>
            </td>
          </tr>
          <tr>
            <td>" . showHelp('FundingOrder') . "</td>
            <td>
              Order:
              <input type=\"text\" name=\"order\" size=2>
            </td>
          </tr>
          <tr>
            <td></td>
            <td>
              <input type=\"button\" name=\"fndBtn\" value=\"Add\"
                     onclick=\"setFunding(this.form)\">
              <input type=\"button\" name=\"fndCancel\" value=\"Cancel\"
                     onclick=\"cancelEditFunding(this.form)\"
                     style=\"visibility:hidden\">
           </td>
         </tr>
        </table>\n";
  }//writeSelectFundingBlock()


  //////////////////////////////////////////////////////////////////////////////
  function writeSelectInstitutionBlock($institution, $auto_num) {
    echo "
        <table>
          <tr>
            <td>" . showHelp('Institution') . "</td>
            <td>\n";
    echo "
              <input type=\"hidden\" name=\"institution_auto_num\" 
                     value=\"$auto_num\">\n";
    echo createCurInstitutionDropDown('institution', 'Select an institution', 
                                      '', $institution, '');
    echo "
            </td>
          </tr>
          <tr>
            <td>" . showHelp('InstitutionOrder') . "</td>
            <td>
              Order:
              <input type=\"text\" name=\"inst_order\" size=2>
              <input type=\"button\" name=\"instBtn\" value=\"Add\"
                     onclick=\"setInstitution(this.form)\">
              <input type=\"button\" name=\"instCancel\" value=\"Cancel\"
                     onclick=\"cancelEditInstitution(this.form)\"
                     style=\"visibility:hidden\">
            </td>
          </tr>
        </table>\n";
  }//writeSelectInstitutionBlock()
  
  
  //////////////////////////////////////////////////////////////////////////////
  function writeSelectInvestigatorBlock($investigator, $relationship, $auto_num) {
    $onclick = "setInvestigator(this.form)";
    $relationship_prompt = ($relationship == '') ?
                              'Relationship to project' : $relationship;
    echo "
        <table>
          <tr>
            <td>" . showHelp('Investigator') . "</td>
            <td colspan=2>\n";
    echo "
              <input type=\"hidden\" name=\"investigator_auto_num\" 
                     value=\"$auto_num\">\n";
    echo createCurInvestigatorDropDown('investigator', 'Select an investigator', 
                                    '', $investigator, '');
    echo "
            </td>
            <td>
              <input type=\"button\" value=\"view\" 
                     onclick=\"openInvestigatorLink(this.form)\">
            </td>
          </tr>
          <tr>
            <td>" . showHelp('InvestigatorRelationship') . "</td>
            <td>
              <input type=\"text\" name=\"inv_relationship\" size=20 
                     value=\"$relationship_prompt\"
                     onfocus=\"if(this.value=='$relationship_prompt')this.value=''\">
            </td>
          </tr>
          <tr>
            <td>" . showHelp('InvestigatorOrder') . "</td>
            <td>
              Order:
              <input type=\"text\" name=\"inv_order\" size=2>
              <input type=\"button\" name=\"invBtn\" value=\"Add\"
                     onclick=\"setInvestigator(this.form)\">
              <input type=\"button\" name=\"invCancel\" value=\"Cancel\"
                     onclick=\"cancelEditInvestigator(this.form)\"
                     style=\"visibility:hidden\">
            </td>
          </tr>
        </table>\n";
  }//writeSelectInvestigatorBlock()
  

  //////////////////////////////////////////////////////////////////////////////
  function writeSelectResourceBlock($resource, $auto_num) {
    echo "
        <table>
          <tr>
            <td>" . showHelp('Resources') . "</td>
            <td>\n";
    echo "
              <input type=\"hidden\" name=\"resource_auto_num\" 
                     value=\"$auto_num\">\n";
    echo createCurResourceDropDown('resource', 'Select a resource', 
                                      '', $resource, '');
    echo "
            </td>
            <td>
              <input type=\"button\" value=\"open\" 
                     onclick=\"openResourceLink(this.form)\">
            </td>
          </tr>
          <tr>
            <td>" . showHelp('ResourceOrder') . "</td>
            <td>
              Order:
              <input type=\"text\" name=\"res_order\" size=2>
              <input type=\"button\" name=\"resBtn\" value=\"Add\"
                     onclick=\"setResource(this.form)\">
              <input type=\"button\" name=\"resCancel\" value=\"Cancel\"
                     onclick=\"cancelEditResource(this.form)\"
                     style=\"visibility:hidden\">
            </td>
          </tr>
        </table>\n";
  }//writeSelectResourceBlock()
  
  
  //////////////////////////////////////////////////////////////////////////////
  function writeSelectProjectBlock($project, $auto_num) {
    echo "
        <table>
          <tr>
            <td>" . showHelp('Projects') . "</td>
            <td>\n";
    echo "
              <input type=\"hidden\" name=\"project_auto_num\" 
                     value=\"$auto_num\">\n";
    echo createCurProjectDropDown('project', 'Select a project', 
                                      '', $project, '');
    echo "
            </td>
          </tr>
          <tr>
            <td>" . showHelp('ProjectOrder') . "</td>
            <td>
              Order:
              <input type=\"text\" name=\"prj_order\" size=2>
              <input type=\"button\" name=\"prjBtn\" value=\"Add\"
                     onclick=\"setProject(this.form)\">
              <input type=\"button\" name=\"prjCancel\" value=\"Cancel\"
                     onclick=\"cancelEditProject(this.form)\"
                     style=\"visibility:hidden\">
            </td>
          </tr>
        </table>\n";
  }//writeSelectProjectBlock()
  
  

?>
