<?php
/* file: project_search_lib.php
 *
 * purpose: functions for project/resource searching
 *
 * history:
 *  02/26/09  eksc  created
 *  06/25/09  eksc  modified for db schema changes
 *  01/21/10  eksc  modified for new combined code architecture
 */

  include_once('../../inc/lib.php');
  include_once('../../inc/lib_db.php');
  include_once('project_search_lists.php');

  $pc_system = (!$pc_system) ? getSystemInfoPC('project_search') : $pc_system;

  include_once($pc_system['MaizeGDB_home'] . '/include/gp_lib.php');

  define("NORMAL", "NORMAL");
  define("NOT_REQUIRED", "NOT_REQUIRED");
  define("REQUIRED", "REQUIRED");


  /////////////////////////////////////////////////////////////////////////////
  // drawStateRow
  /////////////////////////////////////////////////////////////////////////////
  function drawStateRow($ctrlname, $firstoption, &$state, $mode) {
    // NOTE: presumes this will be enclosed by a <tr> element
    echo "State ";
    echo createStateDropDown($ctrlname, $firstoption, $state);
  }//drawStateRow


  /////////////////////////////////////////////////////////////////////////////
  // getCorrectRecordLinkHTML
  /////////////////////////////////////////////////////////////////////////////
  function getCorrectRecordLinkHTML($id) {
    $url = "recommend.php?record=$id";
    return "
              <span class=\"smalltype\">
                (<a class=\"pc\" href=\"$url\">Correct this record</a>)
              </span>";
  }//getCorrectRecordLinkHTML()


  /////////////////////////////////////////////////////////////////////////////
  // getFundingInformationHTML
  /////////////////////////////////////////////////////////////////////////////
  function getFundingInformationHTML($result) {
    $html = '';

    if ($result['funding'] != '') {
      $funding_str = '';
      $funds = explode('|', $result['funding']);
      foreach ($funds as $fund) {
        $fund = trim($fund);
        $name = preg_replace("/(.+)\(.*\)/", "$1", $fund);
        $url = preg_replace("/.+\((.*)\)/", "$1", $fund);
        $funding_str .= ($funding_str == '') ? '' : ', ';
        if (trim($url) == '') {
          $funding_str .= $name;
        }
        else {
          $funding_str .= "<a class=\"pc\" href=\"$url\" target=\"_blank\">$name</a>";
        }
      }
      $html .= "<i>Funding sources:</i> " . $funding_str;
    }

    return $html;
  }//getFundingInformationHTML

  /////////////////////////////////////////////////////////////////////////////
  // getInvestigatorsHTML
  /////////////////////////////////////////////////////////////////////////////
  function getInvestigatorsHTML($names) {
    $html = '';

    $html .= "
          <tr>
            <td></td>
            <td>
              <i>Investigators:</i> ";
    $investigators = explode('|', $names);
    $links = array();
    foreach ($investigators as $investigator) {
      $role = preg_replace('/.*\((.*)\):.*/', "$1", $investigator);
      $id = preg_replace('/.*\:(.*)/', "$1", $investigator);
      $investigator = preg_replace('/\s+\(.*\)\:.*/', '', $investigator);
      $url = "/person/$id";
      if (trim($role) != '') {
        array_push($links, "<a class=\"pc\" href=\"$url\" target=\"_blank\">$investigator</a> ($role)");
      }
      else {
        array_push($links, "<a class=\"pc\" href=\"$url\" target=\"_blank\">$investigator</a>");
      }
    }
    $html .= join(', ', $links);
    $html .= "
            </td>
          </tr>";

    return $html;
  }//getInvestigatorsHTML


  /////////////////////////////////////////////////////////////////////////////
  // getOneProject
  /////////////////////////////////////////////////////////////////////////////
  function getOneProject($conn, $id) {
    $search_results = array();  // will have one row

    $sql = "SELECT DISTINCT P.ID, P.NAME, P.DESCRIPTION, P.FUNDING_PERIOD
            FROM PC_PROJECT P
            WHERE P.ID = ".intval($id);
    $res = makeQuery($conn, $sql);
    if (!$res) {
       return false;
    }

    $row = retrieveRow($res);
    if (!$row) {
      return false;
    }

    $result = array('project_id'     => $row['id'],
                    'project_name'   => $row['name'],
                    'description'    => mgdb_safe_html($row['description']),
                    'funding_period' => $row['funding_period']);

    // Get funding information
    $funding = getProjectFunding($conn, $row['id']);
    $funding_str = join('|', $funding);
    $result['funding'] = $funding_str;

    // Get institution information (may be multiple records)
    $institutions = getProjectInstitutions($conn, $id);
    $inst_str = join(", ", $institutions);  // printable join
    $result['institutions'] = $inst_str;

    // Get investigator information (may be multiple records)
    $investigators = getProjectInvestigators($conn, $id);
    $inv_str = join('|', $investigators);
    $result['investigators'] = $inv_str;

    // Get resource information (may be multiple records)
    $resources = getProjectResources($conn, $id);
    $result['resources'] = $resources;

    // Get related project information (may be multiple records)
    $projects = getRelatedProjects($conn, $id);
    $result['projects'] = $projects;

    // Save final row
    array_push($search_results, $result);

    return $search_results;
  }//getOneProject()


  /////////////////////////////////////////////////////////////////////////////
  // getOneResource
  /////////////////////////////////////////////////////////////////////////////
  function getOneResource($conn, $id) {
    $search_results = array();  // will have one row

    $sql = "SELECT DISTINCT ID, NAME, DESCRIPTION, URL, TUTORIAL
            FROM PC_RESOURCE
            WHERE ID = ".intval($id);
    $res = makeQuery($conn, $sql);
    $row = retrieveRow($res);
    $result = array('resource_id'   => $row['id'],
                    'resource_name' => $row['name'],
                    'description'   => mgdb_safe_html($row['description']),
                    'url'           => $row['url'],
                    'tutorial'      => $row['tutorial']);

    // Get institution information (may be multiple records)
    $institutions = getResourceInstitutions($conn, $id);
    $inst_str = join(", ", $institutions);  // printable join
    $result['institutions'] = $inst_str;

    // Get funding information
    $funding = getResourceFunding($conn, $row['id']);
    $funding_str = join(', ', $funding);
    $result['funding'] = $funding_str;

    // Save final row
    array_push($search_results, $result);

    return $search_results;
  }//getOneResource()


  /////////////////////////////////////////////////////////////////////////////
  // getProjectFieldsHTML
  /////////////////////////////////////////////////////////////////////////////
  function getProjectFieldsHTML($result, $divname, $togname) {
    $html = '';

    // Need to find a URL for this project.
    $url = '';

    // If there are any resources, grab the URL from the first resource
    //    IFF its order level is 1. (order==1 --> project description page)
    if (isset($result['resources'])
          && $result['resources'] != null
          && count($result['resources']) > 0) {
       if ($result['resources'][0]['ordering'] == 1) {
         $url = $result['resources'][0]['url'];
       }
    }//there are resources

    // Create a link string
    $link = '';
    if ($url == '') {
      // This project has only an award abstract
      $funding = explode('|', $result['funding']);
      if (count($funding) > 0) {
        // funding is an array of strings: "NAME (URL)"
        $url = preg_replace('/.*\((.*)\)/', '$1', $funding[0]);
        $link = "(<a class=\"pc\" href=\"$url\" target=\"_blank\">award link</a>)";
      }
    }
    else {
      // take URL from first resource
      $link = "<a class=\"pc\" href=\"$url\" target=\"_blank\">" . truncate($url, 50) . "</a>";
    }

    // NAME & DESCRIPTION
    $html .= "
      <a class=\"pc\" name=\"" . urlencode($result['project_name']) . "\" target=\"_blank\"></a>
      <b>" . $result['project_name'] . "</b>: $link
      <div id=\"$divname\" style=\"display:none\">
        <table border=0>
          <tr>
            <td width=10></td>
            <td>
              " . nl2br($result['description']) . "
            </td>
          </tr>";

    // INSTITUTIONS
    if ($result['institutions'] != '') {
      $html .= "
          <tr>
            <td></td>
            <td>
              <i>Institutions: </i>" . $result['institutions'] . "
            </td>
          </tr>";
    }

    // INVESTIGATORS
    if ($result['investigators'] != '') {
      $html .= getInvestigatorsHTML($result['investigators']);
    }

    // FUNDING PERIOD
    if ($result['funding_period'] != '') {
      $html .= "
          <tr>
            <td></td>
            <td>
              <i>Funding period:</i> " . $result['funding_period'] . "
            </td>
          </tr>";
    }

    // FUNDING INFORMATION
    if (hasFundingInformation($result)) {
      $html .= "
          <tr>
            <td></td>
            <td>";
      $html .= getFundingInformationHTML($result);
      $html .= "
            </td>
          </tr>";
    }

    // CORRECT RECORD LINK
    $url = "recommend.php?record=" . $result['project_id'];
    $html .= "
          <tr>
            <td></td>
            <td>";
    $html .= getCorrectRecordLinkHTML($result['project_id']);
    $html .= "
            </td>
          </tr>";

    return $html;
  }//getProjectFieldsHTML


  /////////////////////////////////////////////////////////////////////////////
  // getProjectFunding
  /////////////////////////////////////////////////////////////////////////////
  function getProjectFunding($conn, $id) {
    $funding = array();
    $sql = "SELECT PAC.URL, PAC.ORDERING, P.NAME, PAC.AUTO_NUM
            FROM PC_ASSOC_FUNDING PAC, PERSON P
            WHERE PAC.ID=".intval($id)." AND P.ID=PAC.PERSON_ID ORDER BY ORDERING";
    $fund_res = makeQuery($conn, $sql);
    while ($fund_row = retrieveRow($fund_res)) {
      $name = $fund_row['name'] . ' (' . $fund_row['url'] . ')';
      array_push($funding, $name);
    }

    return $funding;
  }//getProjectFunding


  /////////////////////////////////////////////////////////////////////////////
  // getProjectHTML
  /////////////////////////////////////////////////////////////////////////////
  function getProjectHTML($result, $divname, $togname) {
    $html = '';

    $html .= getProjectFieldsHTML($result, $divname, $togname);

    // RESOURCES
    if (isset($result['resources'])
          && $result['resources'] != null
          && count($result['resources']) > 0) {
      $resources = $result['resources'];

      // Display associated resources if there are more than 1 resource
      //   *OR* the first resource has ordering != 1
      //   (order==1 --> project description page)
      if (count($resources) > 1 || $resources[0]['ordering'] != '1') {
         $html .= getResourcesHTML($result, $divname, $togname);
      }
    }

    // RELATED PROJECTS
    if (isset($result['projects'])
          && $result['projects'] != null
          && count($result['projects']) > 0) {
       $html .= getRelatedProjectsHTML($result);
    }

    // End table
    $html .= "
        </table>
        <hr style=\"border:0;background-color:#666633;height:1px\">
      </div>";

    return $html;
  }//getProjectHTML


  /////////////////////////////////////////////////////////////////////////////
  // getProjectInstitutions
  /////////////////////////////////////////////////////////////////////////////
  function getProjectInstitutions($conn, $id) {
    $institutions = array();
    $sql = "SELECT P.NAME, PAI.ORDERING, PAI.AUTO_NUM
            FROM PC_ASSOC_INSTITUTION PAI, PERSON P
            WHERE PAI.ID=".intval($id)." AND P.ID=PAI.PERSON_ID ORDER BY ORDERING";
    $inst_res = makeQuery($conn, $sql);
    while ($inst_row = retrieveRow($inst_res)) {
      array_push($institutions, $inst_row['name']);
    }

    return $institutions;
  }//getProjectInstitutions


  /////////////////////////////////////////////////////////////////////////////
  // getProjectInvestigators
  /////////////////////////////////////////////////////////////////////////////
  function getProjectInvestigators($conn, $id) {
    $investigators = array();
    $sql = "SELECT DISTINCT PAI.AUTO_NUM, P.ID, P.NAME, PAI.RELATIONSHIP,
            PAI.ORDERING
            FROM PC_ASSOC_INVESTIGATOR PAI
               INNER JOIN PERSON P ON P.ID=PAI.PERSON_ID
            WHERE PAI.ID=".intval($id)."
            ORDER BY ORDERING, P.NAME";
    $inv_res = makeQuery($conn, $sql);
    while ($inv_row = retrieveRow($inv_res)) {
      $name = $inv_row['name']
              . " (" . $inv_row['relationship'] . "):" . $inv_row['id'];
      array_push($investigators, $name);
    }

    return $investigators;
  }//getProjectInvestigators()


  /////////////////////////////////////////////////////////////////////////////
  // getProjectResources
  /////////////////////////////////////////////////////////////////////////////
  function getProjectResources($conn, $id) {
    $resources = array();
    $sql = "SELECT R.ID, ORDERING FROM PC_ASSOCIATION A
              INNER JOIN PC_RESOURCE R ON R.ID=A.ID2
              INNER JOIN ID_NUM ON R.ID=ID_NUM.ID
            WHERE A.ID1=".intval($id)." AND ID_NUM.CURATION_LVL=0
            ORDER BY ORDERING, NAME";
    $res_res = makeQuery($conn, $sql);
    while ($res_row = retrieveRow($res_res)) {
       $one_resource = getOneResource($conn, $res_row['id']);
       $one_resource[0]['ordering'] = $res_row['ordering'];
       array_push($resources, $one_resource[0]);
    }//each record

    return $resources;
  }//getProjectResources


  /////////////////////////////////////////////////////////////////////////////
  // getProjects
  /////////////////////////////////////////////////////////////////////////////
  function getProjects($conn, $keyword, $institution, $investigator, $country,
                       $state, $category) {
    $search_results = array();

    // Build query
    $sql = "SELECT DISTINCT P.ID AS PROJECT_ID, P.NAME AS PROJECT_NAME,
                   LOWER(P.NAME), P.DESCRIPTION, P.FUNDING_PERIOD
            FROM (SELECT DISTINCT P.ID, P.NAME, P.DESCRIPTION, P.FUNDING_PERIOD
                  FROM PC_PROJECT P
                    INNER JOIN PC_ASSOC_CATEGORY AC ON AC.ID=P.ID
                    INNER JOIN ID_NUM IDN ON IDN.ID=P.ID
                  WHERE IDN.CURATION_LVL=0
                 ) P
              LEFT JOIN PC_ASSOC_FUNDING AF ON AF.ID=P.ID
              LEFT JOIN PC_ASSOC_INSTITUTION AI ON AI.ID=P.ID
              LEFT JOIN PERSON IPER ON IPER.ID=AI.PERSON_ID
              LEFT JOIN PC_ASSOC_CATEGORY AC ON AC.ID=P.ID
              LEFT JOIN PC_CATEGORY CAT ON CAT.ID=AC.CATEGORY_ID
              LEFT JOIN PC_ASSOC_INVESTIGATOR AP ON AP.ID=P.ID
              LEFT JOIN PERSON PER ON PER.ID=AP.PERSON_ID
              LEFT JOIN PC_ASSOCIATION PA
                  ON PA.ID1=P.ID
                     AND PA.RELATIONSHIP IN ('resource-of', 'related-project')
              LEFT JOIN PC_RESOURCE RES ON RES.ID=PA.ID2
              INNER JOIN ID_NUM IDN ON IDN.ID=P.ID";

    // Build WHERE clauses
    $clauses = array();

    if ($investigator != '') {
      array_push($clauses, "PER.ID=$investigator");
    }

    if ($institution != '') {
      array_push($clauses, "IPER.ID=$institution");
    }

    // NOTE: state overrides country
    if ($state != '') {
      array_push($clauses, "IPER.STATE=$state");
    }
    else if ($country != '') {
      array_push($clauses, "IPER.COUNTRY=$country");
    }

    if ($category != '') {
      array_push($clauses, "CAT.ID=$category");
    }

    $use_all_words = preg_match("/^['\"].+/", $keyword);
    if ($keyword && $keyword != '%%') {
      // search for keyword explicitly...
      $like_clause = "(LOWER(P.NAME) LIKE " . strtolower($keyword) . " ";
      $like_clause .= "OR LOWER(P.DESCRIPTION) LIKE " . strtolower($keyword) . " ";
      // ...stripped of punctuation...
      $stripped_keyword = stripPunctuation($keyword);
      $like_clause .= "OR AF.KEYWORDS LIKE '%$stripped_keyword%' ";
      $keywords = removeExcludedWords($keyword);
      // ...and by individual words
      $keyword_clauses = array();
      foreach ($keywords as $k) {
         array_push($keyword_clauses, "AF.KEYWORDS LIKE '%$k%'");
      }
      $operator = ($use_all_words) ? "AND" : "OR";
      $like_clause .= " OR (" . implode(" $operator ", $keyword_clauses) . ")) ";
      array_push($clauses, $like_clause);
    }

    if (count($clauses) > 0) {
      $sql .= " WHERE IDN.CURATION_LVL=0 AND " . join(" AND ", $clauses);
    }
    else {
      $sql .= " WHERE IDN.CURATION_LVL=0";
    }
    $sql .= " ORDER BY LOWER(P.NAME)";
//echo "$sql<br>";
    $res = makeQuery($conn, $sql);
    while ($row = retrieveRow($res)) {
      $result = array('project_id'     => $row['project_id'],
                      'project_name'   => $row['project_name'],
                      'description'    => mgdb_safe_html($row['description']),
                      'funding_period' => $row['funding_period']);

      // Get funding information
      $funding = getProjectFunding($conn, $row['project_id']);
      $funding_str = join('|', $funding);
      $result['funding'] = $funding_str;

      // Get institution information (may be multiple records)
      $institutions = getProjectInstitutions($conn, $row['project_id']);
      $inst_str = join(", ", $institutions);  // printable join
      $result['institutions'] = $inst_str;

      // Get investigator information (may be multiple records)
      $investigators = getProjectInvestigators($conn, $row['project_id']);
      $inv_str = join('|', $investigators);
      $result['investigators'] = $inv_str;

      // Get resource information (may be multiple records)
      $resources = getProjectResources($conn, $row['project_id']);
      $result['resources'] = $resources;

      // Get related project information (may be multiple records)
      $projects = getRelatedProjects($conn, $row['project_id']);
      $result['projects'] = $projects;

      // Save final row
      array_push($search_results, $result);
    }

    return $search_results;
  }//getProjects()


  /////////////////////////////////////////////////////////////////////////////
  // getRelatedProjects
  /////////////////////////////////////////////////////////////////////////////
  function getRelatedProjects($conn, $id) {
    $projects = array();
    $sql = "SELECT P.ID, P.NAME, A.AUTO_NUM
            FROM PC_ASSOCIATION A
              INNER JOIN PC_PROJECT P ON P.ID=A.ID2
              INNER JOIN ID_NUM ON P.ID=ID_NUM.ID
            WHERE A.ID1=".intval($id)." AND ID_NUM.CURATION_LVL=0
            ORDER BY ORDERING, NAME";
    $prj_res = makeQuery($conn, $sql);
    while ($prj_row = retrieveRow($prj_res)) {
      $project = array(
                    'id'   => $prj_row['id'],
                    'name' => $prj_row['name'],
      );
      array_push($projects, $project);
    }

    return $projects;
  }//getRelatedProjects


  /////////////////////////////////////////////////////////////////////////////
  // getRelatedProjectsHTML
  /////////////////////////////////////////////////////////////////////////////
  function getRelatedProjectsHTML($result) {
    $html = '';

    if (isset($result['projects'])
          && $result['projects'] != null
          && count($result['projects']) > 0) {
      $html .= "
          <tr>
            <td colspan=2>
              <b>Related Projects:</b>
            </td>
          </tr>";

      $projects = $result['projects'];
      foreach ($projects as $project) {
        // Link to project record
        $url = "project_search.php?record=" . $project['id'];
        $html .= "
          <tr>
            <td></td>
            <td><a class=\"pc\" href=\"$url\" target=\"_blank\">" . $project['name'] . "</a></td>
          </tr>\n";
      }//for each related project
    }

    return $html;
  }//getRelatedProjectsHTML



  /////////////////////////////////////////////////////////////////////////////
  // getResourceFieldsHTML
  /////////////////////////////////////////////////////////////////////////////
  function getResourceFieldsHTML($result, $divname, $togname) {
    $html = '';

    // Get a url to link to, if possible
    if ($result['url'] != '') {
      $url = $result['url'];
    }
    else {
      $url = '';
    }

    if ($url != '') {
      $html .= "
      <b>" . $result['resource_name'] . "</b>:
      <a class=\"pc\" href=\"$url\" target=\"_blank\">" . truncate($url, 50) . "</a>";
    }
    else {
      $html .= "
      <b>" . $result['resource_name'] . "</b>";
    }
    $html .= "
      <div id=\"$divname\" style=\"display:none\">
        " . nl2br($result['description']) . "<br>";
    if ($result['institutions'] != '') {
      $html .= "
        <i>Institution(s):</i> "
        . preg_replace("/\|/", ", ", $result['institutions']) . "<br>";
    }
    if ($result['tutorial'] != '') {
      $html .= "
        <a class=\"pc\" href=\"" . $result['tutorial'] . "\" target=\"_blank\">Tutorial</a><br>";
    }
    if ($result['funding'] != '') {
      $html .= getFundingInformationHTML($result);
      $html .= "<br>";
    }
    $html .= "
        <a class=\"pc\" href=\"$url\" target=\"_blank\">Link</a><br>";
    $html .= getCorrectRecordLinkHTML($result['resource_id']);
    $html .= "
        <hr style=\"border:0;background-color:#666633;height:1px\">
      </div>";

    return $html;
  }//getResourceFieldsHTML


  /////////////////////////////////////////////////////////////////////////////
  // getResourceFunding
  /////////////////////////////////////////////////////////////////////////////
  function getResourceFunding($conn, $id) {
    $funding = array();
    $sql = "SELECT PAC.URL, PAC.ORDERING, P.NAME, PAC.AUTO_NUM
            FROM PC_ASSOC_FUNDING PAC, PERSON P
            WHERE PAC.ID=".intval($id)." AND P.ID=PAC.PERSON_ID
            ORDER BY ORDERING";
    $fund_res = makeQuery($conn, $sql);
    while ($fund_row = retrieveRow($fund_res)) {
      $name = $fund_row['name'] . ' (' . $fund_row['url'] . ')';
      array_push($funding, $name);
    }

    return $funding;
  }//getResourceFunding


  /////////////////////////////////////////////////////////////////////////////
  // getResourceInstitutions
  /////////////////////////////////////////////////////////////////////////////
  function getResourceInstitutions($conn, $id) {
    $institutions = array();
    $sql = "SELECT IPER.NAME
            FROM PERSON IPER
              INNER JOIN PC_ASSOC_INSTITUTION AI
                ON AI.ID=".intval($id)."
                   AND IPER.ID=AI.PERSON_ID
            ORDER BY ORDERING, IPER.NAME";
    $inst_res = makeQuery($conn, $sql);
    while ($inst_row = retrieveRow($inst_res)) {
      array_push($institutions, $inst_row['name']);
    }

    return $institutions;
  }//getResourceInstitutions


  /////////////////////////////////////////////////////////////////////////////
  // getResources
  /////////////////////////////////////////////////////////////////////////////
  function getResources($conn, $keyword, $institution, $investigator, $country,
                        $state, $category) {
    $search_results = array();
    $sql = "SELECT DISTINCT R.ID AS RESOURCE_ID, R.NAME AS RESOURCE_NAME,
                   LOWER(R.NAME), R.DESCRIPTION, R.URL, R.TUTORIAL
            FROM (SELECT DISTINCT R.ID, R.NAME, R.DESCRIPTION, R.URL, R.TUTORIAL
                  FROM PC_RESOURCE R
                    INNER JOIN PC_ASSOC_CATEGORY AC ON AC.ID=R.ID
                    INNER JOIN ID_NUM IDN ON IDN.ID=R.ID
                  WHERE IDN.CURATION_LVL=0
                 ) R
              LEFT JOIN PC_ASSOC_INSTITUTION AI ON AI.ID=R.ID
              LEFT JOIN PERSON IPER ON IPER.ID=AI.PERSON_ID
              LEFT JOIN PC_ASSOC_CATEGORY AC ON AC.ID=R.ID
              LEFT JOIN PC_CATEGORY CAT ON CAT.ID=AC.CATEGORY_ID
              LEFT JOIN PC_ASSOC_INVESTIGATOR AP ON AP.ID=R.ID
              LEFT JOIN PERSON PER ON PER.ID=AP.PERSON_ID
              INNER JOIN ID_NUM IDN ON IDN.ID=R.ID";

    // Build WHERE clauses
    $clauses = array();
    if ($investigator != '') {
      array_push($clauses, "PER.ID=$investigator");
    }

    if ($institution != '') {
      array_push($clauses, "IPER.ID=$institution");
    }

    // State overrides country:
    if ($state != '') {
      array_push($clauses, "IPER.STATE='$state'");
    }
    else if ($country != '') {
      array_push($clauses, "IPER.COUNTRY='$country'");
    }

    if ($category != '') {
      array_push($clauses, "CAT.ID='$category'");
    }

    if ($keyword && $keyword != '%%') {
      $like_clause = "LOWER(R.NAME) LIKE " . strtolower($keyword) . " ";
      $like_clause .= "OR LOWER(R.DESCRIPTION) LIKE "
                      . strtolower($keyword) .")";
      $like_clause = "LOWER(R.URL) LIKE " . strtolower($keyword) . " ";
      array_push($clauses, $like_clause);
    }
//var_dump($clauses);

    if (count($clauses) > 0) {
      $sql .= " WHERE IDN.CURATION_LVL=0 AND " . join(" AND ", $clauses);
    }
    else {
      $sql .= " WHERE IDN.CURATION_LVL=0";
    }
    $sql .= " ORDER BY LOWER(R.NAME)";

    $res = makeQuery($conn, $sql);
    while ($row = retrieveRow($res)) {
      $result = array('resource_id'   => $row['resource_id'],
                      'resource_name' => $row['resource_name'],
                      'description'   => mgdb_safe_html($row['description']),
                      'tutorial'      => $row['tutorial'],
                      'url'           => $row['url']);

      // Get funding information
      $funding = getResourceFunding($conn, $row['resource_id']);
      $funding_str = join('|', $funding);
      $result['funding'] = $funding_str;

      // Get institution information (may be multiple records)
      $institutions = getResourceInstitutions($conn, $row['resource_id']);
      $inst_str = join("|", $institutions);
      $result['institutions'] = $inst_str;

      // Save final row
      array_push($search_results, $result);
    }
//logVarDumpPC($search_results);

    return $search_results;
  }//getResources()


  /////////////////////////////////////////////////////////////////////////////
  // getResourcesHTML
  /////////////////////////////////////////////////////////////////////////////
  function getResourcesHTML($result, $divname, $togname) {
    $html = '';

    if ($result['resources'] != '') {
      $html .= "
          <tr>
            <td colspan=2>
              <b>Resources:</b>
            </td>
          </tr>";

      $resources = $result['resources'];
      $res_count = 1;
      foreach ($resources as $resource) {
        // Draw this resource
        $sub_divname = $divname . "_$res_count";
        $sub_togname = $togname . "_$res_count";
        $conn = connectToDatabase();
        $html .= getSubResourceFieldsHTML($conn, $resource,
                                          $sub_divname, $sub_togname);
        $res_count++;
      }//for each resource
    }

    return $html;
  }//getResourcesHTML


  /////////////////////////////////////////////////////////////////////////////
  // getResultsHTML
  /////////////////////////////////////////////////////////////////////////////
  function getResultsHTML($search_type, $search_results, $start_count=1) {
    global $pc_system;

    $html = '';
    $html .= "
      <a class=\"pc\" name=\"$search_type\"></a>
      <table width=\"100%\" border=0>";

    if (count($search_results) == 0) {
      // No results
      $html .= "
        <tr><td colspan=3>No $search_type"."s found.</td></tr>";
    }
    else {
      // Display results
      $str = (count($search_results) == 1)
                ? "Found 1 $search_type"
                : "Found " . count($search_results) . " $search_type"."s";
      $section_tog = $search_type . '_tog';
      $section_div = $search_type . '_results';
      $section_img = $section_tog . '_img';
      $tog_call = "toggleDiv('$section_tog', '$section_img', '$section_div', "
                . "'" . $pc_system['root_url'] . "')";
      $html .= "
        <tr name=\"1\">
          <td valign=\"middle\">
            <input type=\"hidden\" id=\"$section_tog\" value=\"-\">
            <a class=\"pc\" href=\"#\" class=\"pc-nooutline\"
               onclick=\"return $tog_call\"><img
               src=\"../../images/row-contract1.gif\" border=0
               id=\"$section_img\"></a>
          </td>
          <td colspan=3>
            <h2>" . ucfirst(strtolower($search_type))."s:</h2>
          </td>
        </tr>
        <tr>
          <td></td>
          <td>
            <b>$str</b>
            (Click the triangle to see details and additional links)
          </td>
        </tr>
        <tr height=4><td colspan=3></td></tr>
        <tr>
          <td></td>
          <td>
            <div name=\"$section_div\" id=\"$section_div\">
            <table>";

      // Display each result
      $count = $start_count;
      foreach ($search_results as $result) {
        $html .= "
        <tr>";

        $divname = "more$count";
        $togname = "tog$count";
        $togimg  = $togname . "_img";
        $tog_call = "toggleDiv('$togname', '$togimg', '$divname', "
                  . "'" . $pc_system['root_url'] . "')";

        // Show expand/contract link
        $html .= "
          <td></td><td></td>
          <td valign=\"top\" name=\"2\">
            <input type=\"hidden\" id=\"$togname\" value=\"+\">
            <a class=\"pc\" href=\"#\" class=\"pc-nooutline\"
               onclick=\"return $tog_call\"><img
               src=\"../../images/row-expand1.gif\" border=0
               id=\"$togimg\"></a>
          </td>
          <td width=\"100%\">";

        if ($search_type == 'project') {
          // Display project fields
          $html .= getProjectHTML($result, $divname, $togname);
        }//print project info

        else {
          // Display resource fields
          $html .= getResourceFieldsHTML($result, $divname, $togname);
        }

        $html .= "
          </td>
        </tr>
        <tr height=8><td colspan=3></td></tr>";

        $count++;
      }//for each result
    }
    $html .= "
            </table>
            </div>
          </td>
        </tr>
      </table>\n";

    return $html;
  }//getResultsHTML


  /////////////////////////////////////////////////////////////////////////////
  // getSubResourceFieldsHTML
  /////////////////////////////////////////////////////////////////////////////
  function getSearchControls($lock, $search_type, $keyword, $institution,
                             $investigator, $country, $state, $category,
                             $boolop='', $category2='') {
    $help_url = "javascript:popupLink('search_projects_help.html', 450, 400, ";

    // Unpack locks (if any)
    $locks = array();
    if ($lock != '') {
      $larray = explode(',', $lock);
      foreach ($larray as $l) {
        $fields = explode(':', $l);
        $locks[$fields[0]] = $fields[1];
      }
    }//locks exist

    $html = "
    <table border=0>
      <tr>
        <td>
          <table>";

    //*** RESOURCE VS PROJECT
    if (isset($locks['search_type'])) {
      $html .= "
          <input type=\"hidden\" name=\"search_type\" value=\"$search_type\">";
    }//lock search_type option
    else {
      $project_selected = ($search_type == 'project') ? 'checked' : '';
      $resource_selected = ($search_type == 'resource') ? 'checked' : '';
      $html .= "
            <tr>
              <td>
                <a class=\"pc\" href=\"$help_url 'projectresource')\"
                   class=\"pc-nooutline\">?</a>
              </td>
              <td colspan=2>
                Search for
                <input type=\"radio\" name=\"search_type\"
                       value='project' $project_selected>Projects
                <input type=\"radio\" name=\"search_type\"
                       value='resource' $resource_selected>Resources
              </td>
            </tr>";
    }//show search_type option

    //*** KEYWORD SEARCH ***
    if (isset($locks['keyword'])) {
      $html .= "
          <input type=\"hidden\" name=\"keyword\"
                 value=\"" . htmlspecialchars($keyword) . "\">";
    }//lock keyword option
    else {
      $html .= "
            <tr>
              <td>
                <a class=\"pc\" href=\"$help_url 'keyword')\" class=\"pc-nooutline\">?</a>
              </td>
              <td>Keyword</td>
              <td>
                <input type=\"text\" size=40 name=\"keyword\"
                       value=\"" . htmlspecialchars($keyword) . "\">
                </td>
            </tr>";
    }//show keyword option

    //*** INVESTIGATOR ***
    if (isset($locks['investigator'])) {
      $html .= "
          <input type=\"hidden\" name=\"investigator\" value=\"$investigator\">";
    }//lock investigator option
    else {
      $html .= "
            <tr>
              <td>
                <a class=\"pc\" href=\"$help_url 'investigator')\" class=\"pc-nooutline\">?</a>
              </td>
              <td>Investigator</td>
              <td>
                " .  createInvestigatorDropDown('investigator', 'Any', '', $investigator) . "
              </td>
            </tr>";
    }//show investigator option

    //*** INSTITUTION ***
    if (isset($locks['institution'])) {
      $html .= "
          <input type=\"hidden\" name=\"institution\" value=\"$institution\">";
    }//lock institution option
    else {
      $html .= "
            <tr>
              <td>
                <a class=\"pc\" href=\"$help_url 'institution')\" class=\"pc-nooutline\">?</a>
              </td>
              <td>Institution</td>
              <td>
                " .  createInstitutionDropDown('institution', 'Any', '', $institution) . "
              </td>
            </tr>";
    }//show institution option

    //*** COUNTRY ***
    if (isset($locks['country'])) {
      $html .= "
        <input type=\"hidden\" name=\"country\" value=\"$country\">";
    }//lock country option
    else {
      $onchange = "setStateRow(this, '$state')";
      $html .= "
        <tr>
          <td>
            <a class=\"pc\" href=\"$help_url 'country')\" class=\"pc-nooutline\">?</a>
          </td>
          <td>Country</td>
          <td>
            " . createCountryDropDown('country', 'Any', $country, $onchange) . "
          </td>
        </tr>";

      //*** STATE ***
      $html .= "
        <tr><td></td><td></td><td><div id=\"stateRow\"></div></td></tr>";
    }//show country/state options

    //*** CATEGORY ***
    if (isset($locks['category'])) {
      $html .= "
        <input type=\"hidden\" name=\"category\"
               value=\"$category\">";
    }//lock category option
    else {
      $html .= "
        <tr>
          <td>
            <a class=\"pc\" href=\"$help_url 'category')\" class=\"pc-nooutline\">?</a>
          </td>
          <td class=\"pc-nowrap\">Category</td>
          <td>
            " . createCategoryDropDown('category', 'Any', '', $category) . "
          </td>
        </tr>";
    }//show category option

    $html .= "
          </table>
        </td>";

    if (isset($locks['category'])) {
      // No category drop-down: don't show hint
      $html .= "
        <td></td>";
    }
    else {
      $pgrop_url = "http://www.plantgdb.org/PGROP/pgropResources.php";
      $pgrop_url .= "?selectedRow=13&start=&app=pgrop";
      $html .= "
        <td align=\"left\" valign=\"bottom\" width=\"100%\">
          Looking for education resources? Try the
          <a class=\"pc\" href=\"$pgrop_url\" target=\"_blank\">Plant Genome Research
            Outreach Portal.</a>
        </td>";
    }

    $html .= "
      </tr>
    </table>";

    return $html;
  }//getSearchControls()


  function getSubResourceFieldsHTML($conn, $resource,
                                    $sub_divname, $sub_togname) {
    global $pc_system;

    $html = '';

    $url      = $resource['url'];
    $name     = $resource['resource_name'];
    $imgname  = $sub_togname . "_img";
    $tog_call = "toggleDiv('$sub_togname', '$imgname', '$sub_divname', "
              . "'" . $pc_system['root_url'] . "')";
    $html .= "
      <tr name=\"3\">
        <td></td>
        <td>
          <input type=\"hidden\" id=\"$sub_togname\" value=\"+\">
          <a class=\"pc\" href=\"#\" class=\"pc-nooutline\"
             onclick=\"return $tog_call\"><img
             src=\"../../images/row-expand1.gif\" border=0
             id=\"$imgname\"></a>
          <b>$name</b>: <a class=\"pc\" href=\"$url\" target=\"_blank\">" . truncate($url, 50) . "</a>
          <div id=\"$sub_divname\" style=\"display:none\">
            <table>";

    // DESCRIPTION
    $html .= "
              <tr>
                <td width=10></td>
                <td>
                  " . $resource['description'] . "
                </td>
              </tr>";

    // INSTITUTIONS
    if (isset($resource['institutions'])) {
      $html .= "
              <tr>
                <td width=10></td>
                <td>
                  <i>" . $resource['institutions'] . "</i><br>
                </td>
              </tr>";
    }

    // TUTORIAL
    if ($resource['tutorial'] != '') {
      $html .= "
              <tr>
                <td></td>
                <td>
                  <a class=\"pc\" href=\"" . $resource['tutorial'] . "\" target=\"_blank\">Tutorial</a>
                </td>
              </tr>";
    }

    // URL
    $html .= "
              <tr>
                <td></td>
                <td>
                  Link: <a class=\"pc\" href=\"$url\" target=\"_blank\">$url</a>
                </td>
              </tr>";

    // CORRECT RECORD LINK
    $html .= "
              <tr>
                <td></td>
                <td>";
    $html .= getCorrectRecordLinkHTML($resource['resource_id']);
    $html .= "
                </td>
              </tr>";

    $html .= "
            </table>
          </div>
        </td>
      </tr>";

    return $html;
  }//getSubResourceFieldsHTML


  /////////////////////////////////////////////////////////////////////////////
  // hasFundingInformation
  /////////////////////////////////////////////////////////////////////////////
  function hasFundingInformation($result) {
    $id = $result['project_id'];

    $conn = connectToDatabase();
    $sql = "SELECT COUNT(PAC.AUTO_NUM) AS NUM
            FROM PC_ASSOC_FUNDING PAC, PERSON P
            WHERE PAC.ID=".intval($id)." AND P.ID=PAC.PERSON_ID";
    $res = makeQuery($conn, $sql);
    $row = retrieveRow($res);

    return ($row['num'] > 0);
  }//hasFundingInformation


  /////////////////////////////////////////////////////////////////////////////
  // loadSearchResults
  /////////////////////////////////////////////////////////////////////////////
  function loadSearchResults($search_type, $keyword, $institution,
                             $investigator, $country, $state, $category) {
    $conn = connectToDatabase();

    $search_type  = validate_inputPC($conn, $search_type);
    $keyword      = $conn->quote(validate_inputPC($conn, "%$keyword%"));
    $institution  = validate_inputPC($conn, $institution);
    $investigator = validate_inputPC($conn, $investigator);
    $country      = validate_inputPC($conn, $country);
    $state        = validate_inputPC($conn, $state);
    $category     = validate_inputPC($conn, $category);
//echo "loadSearchResults(): Search for search_type: $search_type, keyword: $keyword, ";
//echo "investigator: $investigator, institution: $institution, ";
//echo "country: $country, state: $state, category: $category<br>";

    $html = '';

    // search projects only
    if ($search_type == 'project') {
      $results = getProjects($conn, $keyword, $institution, $investigator,
                             $country, $state, $category);
      $html = getResultsHTML($search_type, $results);
    }

    // search resources only
    else if ($search_type == 'resource') {
      $results = getResources($conn, $keyword, $institution, $investigator,
                              $country, $state, $category);
      $html = getResultsHTML($search_type, $results);
    }

    // Search projects and resources
    else {
      $results = getProjects($conn, $keyword, $institution, $investigator,
                              $country, $state, $category);
      $html = getResultsHTML('project', $results);

      // Need this to track # of overall records, for naming <div>s and buttons.
      $num_projects = count($results);

      $html .= "
        <br>
        <hr class=\"divider\">
        <br>";

      // Get matching resources
      $results = array();

      $results = getResources($conn, $keyword, $institution, $investigator,
                              $country, $state, $category);

      $html .= getResultsHTML('resource', $results, ($num_projects+1));
    }

    disconnectFromDatabase($conn);

    echo $html;
  }//loadSearchResults()


  /////////////////////////////////////////////////////////////////////////////
  // writeSearchControls
  /////////////////////////////////////////////////////////////////////////////
  function writeSearchControls($lock, $search_type, $keyword, $institution,
                               $investigator, $country, $state, $category,
                               $boolop='', $category2='') {
    $help_url = "javascript:popupLink('search_projects_help.html', 450, 400, ";

    // Unpack locks (if any)
    $locks = array();
    if ($lock != '') {
      $larray = explode(',', $lock);
      foreach ($larray as $l) {
        $fields = explode(':', $l);
        $locks[$fields[0]] = $fields[1];
      }
    }//locks exist

    echo "
    <table border=0>
      <tr>
        <td>
          <table>";

    //*** RESOURCE VS PROJECT
    if (isset($locks['search_type'])) {
      echo "
          <input type=\"hidden\" name=\"search_type\" value=\"$search_type\">";
    }//lock search_type option
    else {
      $project_selected = ($search_type == 'project') ? 'checked' : '';
      $resource_selected = ($search_type == 'resource') ? 'checked' : '';
      echo "
            <tr>
              <td>
                <a class=\"pc\" href=\"$help_url 'projectresource')\"
                   class=\"pc-nooutline\">?</a>
              </td>
              <td colspan=2>
                Search for
                <input type=\"radio\" name=\"search_type\"
                       value='project' $project_selected>Projects
                <input type=\"radio\" name=\"search_type\"
                       value='resource' $resource_selected>Resources
              </td>
            </tr>";
    }//show search_type option

    //*** KEY WORD SEARCH ***
    if (isset($locks['keyword'])) {
      echo "
          <input type=\"hidden\" name=\"keyword\" value=\"$keyword\">";
    }//lock keyword option
    else {
      echo "
            <tr>
              <td>
                <a class=\"pc\" href=\"$help_url 'keyword')\" class=\"pc-nooutline\">?</a>
              </td>
              <td>Keyword</td>
              <td>
                <input type=\"text\" size=40 name=\"keyword\" value=\"$keyword\">
              </td>
            </tr>";
    }//show keyword option

    //*** INVESTIGATOR ***
    if (isset($locks['investigator'])) {
      echo "
          <input type=\"hidden\" name=\"investigator\" value=\"$investigator\">";
    }//lock investigator option
    else {
      echo "
            <tr>
              <td>
                <a class=\"pc\" href=\"$help_url 'investigator')\" class=\"pc-nooutline\">?</a>
              </td>
              <td>Investigator</td>
              <td>
                " .  createInvestigatorDropDown('investigator', 'Any', '', $investigator) . "
              </td>
            </tr>";
    }//show investigator option

    //*** INSTITUTION ***
    if (isset($locks['institution'])) {
      echo "
          <input type=\"hidden\" name=\"institution\" value=\"$institution\">";
    }//lock institution option
    else {
      echo "
            <tr>
              <td>
                <a class=\"pc\" href=\"$help_url 'institution')\" class=\"pc-nooutline\">?</a>
              </td>
              <td>Institution</td>
              <td>
                " .  createInstitutionDropDown('institution', 'Any', '', $institution) . "
              </td>
            </tr>";
    }//show institution option

    //*** COUNTRY ***
    if (isset($locks['country'])) {
      echo "
        <input type=\"hidden\" name=\"country\" value=\"$country\">";
    }//lock country option
    else {
      $onchange = "setStateRow(this, '$state')";
      echo "
        <tr>
          <td>
            <a class=\"pc\" href=\"$help_url 'country')\" class=\"pc-nooutline\">?</a>
          </td>
          <td>Country</td>
          <td>
            " . createCountryDropDown('country', 'Any', $country, $onchange) . "
          </td>
        </tr>";

      //*** STATE ***
      echo "
        <tr><td></td><td></td><td><div id=\"stateRow\"></div></td></tr>";
    }//show country/state options

    //*** CATEGORY ***
    if (isset($locks['category'])) {
      echo "
        <input type=\"hidden\" name=\"category\"
               value=\"$category\">";
    }//lock category option
    else {
      echo "
        <tr>
          <td>
            <a class=\"pc\" href=\"$help_url 'category')\" class=\"pc-nooutline\">?</a>
          </td>
          <td class=\"pc-nowrap\">Category</td>
          <td>
            " . createCategoryDropDown('category', 'Any', '', $category) . "
          </td>
        </tr>";
    }//show category option

    echo "
          </table>
        </td>";

    if (isset($locks['category'])) {
      // No category drop-down: don't show hint
      echo "
        <td></td>";
    }
    else {
      $pgrop_url = "http://www.plantgdb.org/PGROP/pgropResources.php";
      $pgrop_url .= "?selectedRow=13&start=&app=pgrop";
      echo "
        <td align=\"left\" valign=\"bottom\" width=\"100%\">
          Looking for education resources? Try the
          <a class=\"pc\" href=\"$pgrop_url\" target=\"_blank\">Plant Genome Research Outreach Portal.</a>
        </td>";
    }

    echo "
      </tr>
    </table>";
  }//writeSearchControls()


?>
