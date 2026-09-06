<?PHP
/* file: person_data.php
 *
 * purpose: display the various sections of a person record; called via Ajax
 *
 * history:
 *  10/09/12  jportwood  created
 *  11/20/12  eksc       completed
 */

  include_once('../lib/Bauplan.php');
  include_once("../include/db-api.php");
  include_once("../include/api_tools.php");
  include_once('../include/gp_lib.php');
  include_once('../include/annotation_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $username = getCookie('username', false);
  $password = getCookie('password', false);
  $userid   = getCookie('userid',   false);

  $id   = getCGIParam("id", 'G', false);
  $type = getCGIParam("type", 'G', false);
    
  if (!$id) {
    reportError("No id given to person_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to person_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $DBConn = connect_to_database();
  
  // Special case lists
  if ($id == 'cooperators') {
    showCooperators($type, $DBConn);
  }
  else if ($id == 'breeders') {
    showBreeders($type, $DBConn);
  }
  else if ($id == 'maizegdb') {
    showMaizeGDB($type, $DBConn);
  }
  
  else {
    $tmpl = $bauplan->template()->load('../templates/community/person_sections.bau');
    
    // If annotator, check for super curator
    if ($username) {
      $user_info = get_user_info($DBConn, $username);
      $super_curator = ($user_info['curation_lvl'] <= -5);
      $author_id = $user_info['annotation_author_id'];
    }
    
    // Clean up input typed by user
    $id = (int) $id;   // was validate_input(), which is a no-op; this id is a numeric
                         // MaizeGDB record id and every query below compares it as one.
    
    $query_record = "
      SELECT p.*, t.name AS record_type 
      FROM person p
        INNER JOIN mgdb.term t ON t.id=p.type
      WHERE p.id = " . (int) $id;
    $stmt_record = make_query($DBConn,$query_record,1);
    $arrRecord = retrieve_row($stmt_record);
  
    switch ($type) {
      case 'top':
        show_top($tmpl, $id, $arrRecord, $DBConn);
        break;
      case 'references':
        show_references($tmpl, $id, $arrRecord, $DBConn);
        break;
      case 'projects':
        show_projects($tmpl, $id, $arrRecord, $DBConn);
        break;
    }
  }
  
  $bauplan->publish();

  
//////////////////////////////////////////////////////////////////////////////

function show_top($tmpl, $id, $arrRecord, $DBConn) { 
  global $username, $super_curator, $author_id;

  if ((strlen(trim($arrRecord['name_first'])) > 0) 
        && (strlen(trim($arrRecord['name_last'])) > 0)) {
    $namesave = $arrRecord['name_first'] . " " . $arrRecord['name_last']; 
    $title = $arrRecord['name_first'] . " " . $arrRecord['name_last'];
    if(strlen($arrRecord['suffix']) > 0) 
      $title = $title . ", " . $arrRecord['suffix'];
  }
  else {
    $namesave = $arrRecord['name'];
    $title = $arrRecord['name'];
  }

  $tmpl->get('name')->replace($title);
  $tmpl->get('record_type')->replace($arrRecord['record_type']);
  $tmpl->get('synonyms')->replace(read_synonyms($DBConn, $id));

  show_portrait($tmpl, $id, $DBConn);
  show_recognitions($tmpl, $id, $DBConn);

  //Display contact info
  show_contact($tmpl, $id, $arrRecord, $DBConn);

  $roles = read_roles($arrRecord, $DBConn);
  if ($roles) {
    $tmpl->get('heading')->replace($roles['heading']);
    $tmpl->get('role')->replace($roles['role']);
    $tmpl->get('roles')->unmute();
  }
  $tmpl->get('id')->replace($id);

  /////// Look for comments ///////
  $comments = getComments($DBConn, $id);
  if ($comments) {
    $tmpl->get('comment-list')->replace($comments);
    $tmpl->get('comments')->unmute();
  }

  /////// Look for user annotations ///////
  $annotations = getAnnotations($DBConn, $id, '', $username, $author_id, 
                                $super_curator, 'id');
  $memos = implode('<br>', array_column($annotations, 'memo'));
  if ($annotations) {
    $tmpl->get('annotation-list')->replace($memos);
    $tmpl->get('annotation-user')->unmute();
  }

  // ORCID
  if ($arrRecord['orcid']) {
    $tmpl->get('orcid')->replace($arrRecord['orcid']);
    $tmpl->get('orcid-link')->unmute();
  }
  
  $tmpl->get('top')->unmute();
}//showTop


function show_references($tmpl, $id, $arrRecord, $DBConn) {
  $ref_query = "
    SELECT ra.id, ra.order1
    FROM reference_authors ra, reference r, id_num i 
    WHERE ra.id = r.id AND r.id = i.id AND i.curation_lvl = 0 
          AND ra.author = " . $arrRecord['id'] . " 
    ORDER BY r.year DESC, r.name";
  $stmt_ref = make_query($DBConn, $ref_query);
  $ref_auth_rows = get_all_rows($stmt_ref);
  $count = ($ref_auth_rows) ? count($ref_auth_rows) : 0;

  if ($count == 0) {
  }//no references
  
  else {
    $tmpl->get('match_count')->replace($count);

    $references = array();
    for ($i=0; $i<$count; $i++) {
      $paper_name_query = "
        SELECT name, title FROM reference WHERE id = " . $ref_auth_rows[$i]["id"];
      $paper_name_statement =  make_query($DBConn, $paper_name_query);
      $arrPaperName = retrieve_row($paper_name_statement);      
      array_push($references,
                 array('ref_id'     => $ref_auth_rows[$i]['id'],
                       'paper_name' => $arrPaperName['name'],
                       'title'      => $arrPaperName['title']));
    }//each ref-auth row
    $tmpl->get('ref-list')->loop($references);
  
    $tmpl->get('references')->unmute();
  }//found references
}//show_references()


function show_projects($tmpl, $id, $arrRecord, $DBConn) {
  $sql = "
    SELECT P.ID, P.NAME AS PROJECT_NAME 
    FROM PC_ASSOC_INVESTIGATOR PAI
      JOIN PC_PROJECT P ON P.ID=PAI.ID
      JOIN ID_NUM ON ID_NUM.ID=PAI.ID
    WHERE PAI.PERSON_ID=$id AND ID_NUM.CURATION_LVL=0";
  $sth = make_query($DBConn, $sql);
  $rows = get_all_rows($sth);
  $count = ($rows) ? count($rows) : 0;

  if ($count == 0) {
  }
  else {
    $tmpl->get('prj_count')->replace($count);
    $tmpl->get('project-list')->loop($rows);
  
    $tmpl->get('projects')->unmute();
  }//associated projects exist
}//show_projects()


// Called via JavaScript, blindly, as if display record sections.
//   The 'top' section is written as the list of cooperators; all other
//   sections are ignored.
function showCooperators($type, $DBConn) {
  global $bauplan;

  if ($type == 'top') {
  logMessage("show cooperator list");
    // id 107406 = TERM record for 'Cooperator'
    $sql = "
      SELECT p.id, p.name  
      FROM person p, person_attribute a, id_num i
      WHERE p.id = a.id AND p.id = i.id AND i.curation_lvl = 0 
            AND a.attribute = 107406 
      ORDER BY LOWER(p.name)";
    $sth = make_query($DBConn, $sql);
    $cooperators = get_all_rows($sth);
  
    $tmpl = $bauplan->template()->load('../templates/community/person-lists.bau');
    $tmpl->get('list-name')->replace('All Maize Cooperators');
    $tmpl->get('list')->loop($cooperators);
  }
}//showCooperators


// Called via JavaScript, blindly, as if display record sections.
//   The 'top' section is written as the list of breeders; all other
//   sections are ignored.
function showBreeders($type, $DBConn) {
  global $bauplan;

  if ($type == 'top') {
  logMessage("show cooperator list");
    // id 107406 = TERM record for 'Breeder'
    $sql = "
      SELECT p.name, p.id 
      FROM person p 
        JOIN person_attribute a ON p.id = a.id 
        JOIN id_num f ON p.id = f.id 
      WHERE f.curation_lvl = 0 AND a.attribute = 952750 
      ORDER BY LOWER(p.name)";

    $sth = make_query($DBConn, $sql);
    $cooperators = get_all_rows($sth);
  
    $tmpl = $bauplan->template()->load('../templates/community/person-lists.bau');
    $tmpl->get('list-name')->replace('Maize Breeders');
    $tmpl->get('list')->loop($cooperators);
  }
}//showBreeders


function showMaizeGDB($type, $DBConn) {
}//showMaizeGDB
  
  
/****************************************************
********************HELPER METHODS******************
***************************************************/
   
function read_roles($arrRecord, $DBConn) {
  $query_attr = "
    SELECT t.name as role, pa.value as year
    FROM term t
    INNER JOIN person_attribute pa on pa.attribute = t.id      
    WHERE pa.ID = " . $arrRecord['id'] . "
    ORDER BY t.name, pa.value";
  $statement_attr = make_query($DBConn, $query_attr);
  $rows = get_all_rows($statement_attr);
  $count = ($rows) ? count($rows) : 0;
  
  if ($count == 0) {
    return false;
  }//no roles
  
  else {
    $heading = ($count == 1) ? "Role" : "Roles ($count)";
    $prev_role = $year_str = $role_str = "";
    $prev_year =  0;
    $year_range = false;
    for($i=0; $i<$count; $i++ ) {
      if ($prev_role == $rows[$i]["role"]) {
         //Track number of years as this role
         if ($rows[$i]["year"] && $rows[$i]["year"] == $prev_year +1) {
              $year_range = true;
         }
         else {
              if ($year_range) {
                  $year_str .= " - " . $prev_year;
              }
              $year_str .= ", " . $rows[$i]["year"];
              $year_range = false;
         }
      }
      else {
          //Found new role, finish counting years from previous one.
          if ($i > 0) {
              if ($year_range) {
                  $year_str .= " - " . $prev_year;
              }
              $role_str .= (strlen($year_str) > 0) ? ", $prev_role (<i>$year_str</i>)" : ", $prev_role";
          }
          $prev_role = $rows[$i]["role"];
          $year_str = $rows[$i]["year"];
          $year_range = false;
      }
      $prev_year = $rows[$i]["year"];
    }
    //Finish the role string after the loop
    if ($year_range) {
         $year_str .= " - " . $prev_year;
    }
    $role_str .= (strlen($year_str) > 0) ? ", $prev_role (<i>$year_str</i>)" : ", $prev_role";
    $role_str = substr($role_str, 2);
    $role_data = array('heading' => $heading, 
                       'role'    => $role_str);
    return $role_data;

  }//one or more roles
}//read_roles()


function show_contact($tmpl, $id, $arrRecord, $DBConn) {

  $query_email = "
    SELECT email_address FROM person_email 
    WHERE id = " . $arrRecord['id'] . " 
    ORDER BY primary_email";
  $statement_email = make_query($DBConn,$query_email);
  $email = array();
  $count = 0;
  while($arrEmail = retrieve_row($statement_email)) {
    $email[$count]['email_address'] = $arrEmail['email_address'];
    $email[$count]['sep'] = ' '; 
    $count++;
  }
  
  if (count($email) > 0) {
    $tmpl->get('email')->loop($email);
    $tmpl->get('email')->unmute();
  }

  $query_url = "SELECT url FROM web_data WHERE id = " . $arrRecord['id'];
  $statement_url = make_query($DBConn,$query_url);
  $url = array();
  $count = 0;
  while($arrURL = retrieve_row($statement_url))
  {
    $url[$count]['url'] = $arrURL['url'];
    $url[$count]['sep'] = ' '; 
    $count++;
  }

  if (count($url) > 0) {
    $tmpl->get("url_info")->loop($url);
    $tmpl->get('url_info')->unmute();
  }

}//show_contact

//eksc- may be used later, if multiple images are associated with a person
//      record   11/20/12
function show_images($tmpl, $id, $DBConn) {
  $query_images = "SELECT DISTINCT(url), caption FROM web_image WHERE id=" . (int) $id;
  $stmt_images = make_query($DBConn,$query_images,1);
  $arrImages = get_all_rows($stmt_images);

  if (count($arrImages) > 0) {
    $tmpl->get('image_loop')->loop($arrImages);
    $tmpl->get('portrait')->unmute();
  }
}//show_images()


function show_portrait($tmpl, $id, $DBConn) {
  $sql = "SELECT url FROM web_image WHERE id=" . (int) $id;
  $sth = make_query($DBConn, $sql);
  $rows = get_all_rows($sth);
  $count = ($rows) ? count($rows) : 0;

  if ($count > 1) {
    reportError("Found more than one 'portrait' for PERSON record $id");
  }
  else if ($count == 1) {
    $tmpl->get('img_url')->replace($rows[0]['url']);
    $tmpl->get('portrait')->unmute();
  }
}//show_portrait()


/**
* JP: As of May 1 2018, badges now appear on person record pages.
*
* Whenever a new type of badge is created, add official term name to the badge_list variable and put the image in the html/icon/badges directory with the following name format:
*    badge_ACRONYM.png
*    where ACRONYM = all of the capitable letters in official term name (ex: 'MaizeGDB Beta Tester' => 'badge_MGDBBT.png') IF the official term name is more than one word
*    For term names that are only one word, use the first two letters as the acronym (ex: 'Cooperator' => 'badge_Co.png')
*/
function show_recognitions($tmpl, $id, $DBConn) {

  //Add term names of new badges to this list
  $badge_list = "
    'Cooperator',
    'Data Provider',
    'Maize Genetics Conference Chair',
    'Maize Genetics Conference Ex-officio member',
    'Maize Genetics Conference Local Host',
    'Maize Genetics Conference Plenary Speaker',
    'Maize Genetics Conference Steering Committee',
    'Maize Genetics Executive Committee',
    'Maize Genetics Executive Committee Chair',
    'Maize Nomenclature Committee',
    'Maize Nomenclature Committee Chair',
    'MaizeGDB Alumni',
    'MaizeGDB Beta Tester',
    'MaizeGDB staff member',
    'MaizeGDB Working Group',
    'MaizeGDB Working Group Chair',
    'National Academy of Science Member',
    'M. Rhoades Early-Career Award',
    'L. Stadler Mid-Career Award',
    'R. Emerson Lifetime Award',
    'The McClintock Prize for Plant Genetics and Genome Studies'
  ";
  $badge_list_order = "
    'M. Rhoades Early-Career Award',
    'L. Stadler Mid-Career Award',
    'R. Emerson Lifetime Award',
    'The McClintock Prize for Plant Genetics and Genome Studies'
    ";
/* Need to make this change for Postgres 12. Also works for older versions.
  $sql = "
      SELECT distinct t.name
      FROM term t
      INNER JOIN person_attribute pa on pa.attribute = t.id
      WHERE pa.id = $id and t.name in ($badge_list)
      ORDER BY t.name in ($badge_list_order) desc
  ";
*/
  $sql = "
    SELECT name FROM (
      SELECT DISTINCT pa.id, t.name FROM term t 
        INNER JOIN person_attribute pa on pa.attribute = t.id 
      WHERE pa.id = $id 
            AND t.name IN ($badge_list) 
    ) s
    ORDER BY 
      CASE 
        WHEN (name='M. Rhoades Early-Career Award') THEN 1
        WHEN (name='L. Stadler Mid-Career Award') THEN 2
        WHEN (name='R. Emerson Lifetime Award') THEN 3
        WHEN (name='The McClintock Prize for Plant Genetics and Genome Studies') THEN 4
        ELSE 5
      END";
   $sth = make_query($DBConn, $sql);
   $rows = get_all_rows($sth);
   $badge_list = array(); 
   for ($i=0; $i<count($rows); $i++)
   {
      $words = explode(" ", $rows[$i]["name"]);
      $acronym = "";
      if (count($words) > 1) {
          foreach ($words as $word) {
              $matches = array();
              preg_match_all("/([A-Z]+)/", $word, $matches);
              $acronym .= implode($matches[0]);
          }
      }
      else {
          //Attributes containing one word should also use the 2nd letter in the acronym for clarification
          $acronym = $words[0][0] . $words[0][1];
      }
      $badge_list[$i]["acronym"] = $acronym;
      $badge_list[$i]["new_row"] = ($i > 0 && $i % 4 == 0) ? "</tr><tr>" : "";
  }

  $sql = "SELECT * FROM ed_board WHERE person_id=" . (int) $id;
  $sth = make_query($DBConn, $sql);
  $rows = get_all_rows($sth);
  $count = ($rows) ? count($rows) : 0;
  if ($count > 0) {
    $years = array();
    for ($i=0; $i<$count; $i++) {
      array_push($years, $rows[$i]['year']);
    }
    $tmpl->get('years')->replace(implode(', ', $years));
  
    $n = count($badge_list);
    $badge_list[$n]["acronym"] = "MEB";
    $badge_list[$n]["new_row"] = "";
    $tmpl->get('ed-board')->unmute();
    $tmpl->get('recognition-star')->unmute();
  }

  //Plenary SQL
  // Postgres 12+ requires order by fields be in select list
  $plenary_sql = "
    SELECT distinct r.id as ref_id, r.title, r.year, r.pages 
    FROM reference r
      INNER JOIN reference_authors ra ON ra.id = r.id
      INNER JOIN person_attribute pa ON pa.id = $id
      INNER JOIN term t ON t.id = pa.attribute
    WHERE 
      LOWER(pages) LIKE 'pl%' AND 
      ra.author = $id AND
      t.name LIKE 'Maize Genetics Conference Plenary Speaker' AND
      pa.value ~ E'^\\d+$' AND r.year = pa.value::INTEGER
    ORDER BY r.year desc, r.pages;
  ";
  $sth = make_query($DBConn, $plenary_sql);
  $plenary_talks = get_all_rows($sth);
  // Get rid of pages, required in query by Postgres, but not used in template
  for ($i=0; $i<count($plenary_talks); $i++) {
    unset($plenary_talks[$i]['pages']);
  }
  if ($plenary_talks) {
      $tmpl->get("mm-plenary")->unmute();
      $tmpl->get("plenary_talks")->loop($plenary_talks);
  }

  //echo "<pre>";var_dump($badge_list);echo "</pre>";
  if (count($badge_list) > 0) {
      $tmpl->get("badges")->loop($badge_list);
      $tmpl->get("badges")->unmute();
  }
}//show_recognitions()


?>
