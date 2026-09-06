<?PHP
/* file: species_data.php
 *
 * purpose: display the various sections of a species record; called via Ajax
 *
 * TEST URLs: 
 *   /data_center/species/12808
 *   /data_center/species/13824
 *
 * history:
 *  1/22/12  jportwood  created
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

  
  logMessage("species_data.php: id=$id, type=$type");
  
  if (!$id) {
    reportError("No id given to species_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to species_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/species_sections.bau');
  
  $DBConn = connect_to_database();

  // Clean up input typed by user
  $id = validate_input($DBConn, $id); 

  switch ($type) {
    case 'top':
      show_top($tmpl, $id, $DBConn);
      break;
    case 'overview':
      show_overview($tmpl, $id, $DBConn);
      break;
    case 'annotations':
      showAnnotations($tmpl, $id, $DBConn);
      break;
  }

  $bauplan->publish();
  
  
  function show_top($tmpl, $id, $DBConn)
  {   
    $query_record = "SELECT id, species FROM species WHERE id = " . (int) $id;
    $stmt_record = make_query($DBConn,$query_record);
    $arrRecord = retrieve_row($stmt_record);

     $tmpl->get('species')->replace($arrRecord["species"]);
    
     $syn = read_synonyms($DBConn, $id);
     if (strlen($syn) > 0)
    {
      $tmpl->get('synonyms')->replace($syn);
      $tmpl->get('syn')->unmute();
    }
    
    show_references($id, $DBConn, $tmpl);
    
    $tmpl->get('top')->unmute();
  }//showTop
  
  function show_overview($tmpl, $id, $DBConn) {

    global $system;
    $tmpl->get("img_url")->replace($system["image_server_url"]);
  
    $query_record = "
     SELECT id, species 
     FROM species WHERE id = " . (int) $id;
    $stmt_record = make_query($DBConn,$query_record);
    $arrRecord = retrieve_row($stmt_record);
    
    $no_overview = true;
    
    $nuclear_details = read_nuclear($DBConn, $arrRecord);
    if($nuclear_details && count($nuclear_details) > 0)
    {
      $tmpl->get('nuclear_sec')->loop($nuclear_details);
      $tmpl->get('nuclear_details')->unmute();
      $no_overview = false;      
    }
    
    $linkage_groups = read_lg($DBConn, $arrRecord);
    if($linkage_groups && count($linkage_groups) > 0)
    {
      $tmpl->get('linkage_sec')->loop($linkage_groups);
      $tmpl->get('linkage_groups')->unmute();
      $no_overview = false;      
    }

    $comments = read_comment($DBConn, $id);
    if(strlen($comments) > 0)
    {
      $tmpl->get('comments')->replace($comments);
      $tmpl->get('comment_sec')->unmute();
      $no_overview = false;
    }
    
    if(show_offsite_resources($tmpl, $id, $DBConn) === false &&
       show_images($tmpl, $id, $DBConn) === false &&
       $no_overview === true)
    {
      $tmpl->get('no_overview')->unmute();
    }
    
    $tmpl->get('overview')->unmute();
  }//showOverview


  function showAnnotations($tmpl, $id, $DBConn) {
    global $username, $super_curator, $author_id;
    
    // Get the record
    $query_record = "SELECT species FROM species WHERE id = " . (int) $id;
    $stmt_record = make_query($DBConn,$query_record);
    $arrRecord = retrieve_row($stmt_record);
    
    /////// Look for user annotations ///////
    $arrAnnotations = getAnnotations($DBConn, $id, '', $username, $author_id, 
                                     $super_curator, 'id');
    if (!$arrAnnotations || count($arrAnnotations) == 0) {
      $tmpl->get('no-user')->unmute();
    }
    else if ($super_curator) {
      $tmpl->get('annotation-user-list-ex')->loop($arrAnnotations);
      $tmpl->get('annotation-user-curator')->unmute();
    }
    else {
      $tmpl->get('annotation-user-list')->loop($arrAnnotations);
      $tmpl->get('annotation-user')->unmute();
    }

/* broken, and no one used it when it worked
   // Always show curation section; will prompt for log-in if need be
   $tmpl->get('curation')->unmute();
*/
  
    $tmpl->get('id')->replace($id);
    $tmpl->get('rec_name')->replace($arrRecord['species']);

    $tmpl->get('annotations')->unmute();
  }//showAnnotations
  
  /****************************************************
   ********************HELPER METHODS******************
   ****************************************************/
   
  function show_offsite_resources($tmpl, $id, $DBConn)
  {
    $query_keys = "
     SELECT A.DB_PERSON, A.KEY 
     FROM EXT_DB_KEY A, ID_NUM B 
     WHERE A.ID = " . (int) $id . " AND A.DB_PERSON = B.ID AND B.CURATION_LVL = 0";
    $stmt_keys = make_query($DBConn,$query_keys);
    $count = 0;
    $offsite_result = array();
    while ($arrKeys = retrieve_row($stmt_keys))
    {
      $query_person = "SELECT name, id FROM person WHERE id = " 
                    . $arrKeys['db_person'];
      $stmt_person = make_query($DBConn,$query_person);
      $arrPerson = retrieve_row($stmt_person);

      $query_url_prefix = "SELECT url_prefix FROM person_url_prefix WHERE id = " 
                        . $arrKeys['db_person'];
      $stmt_url_prefix = make_query($DBConn,$query_url_prefix);
      $arrUrlPrefix = retrieve_row($stmt_url_prefix);
      
      $offsite_result[$count]['url_prefix'] = $arrUrlPrefix['url_prefix'];
      $offsite_result[$count]['key'] = trim($arrKeys['key']);
      $offsite_result[$count]['pers_name'] = $arrPerson['name'];

      $count++;
    }
    if (count($offsite_result) > 0)
    {
      $tmpl->get('offsite_sec')->loop($offsite_result);
      $tmpl->get('offsite_resources')->unmute();
      return true;
    }
    else
      return false;
  }
  
  function read_nuclear($DBConn, $arrRecord)
  {
    $query_nuclear = "
     SELECT sn.ID, sn.HAPLOID_NUMBER, sn.DNA_CONTENT 
     FROM SPECIES_NUCLEAR sn, id_num idn
     WHERE sn.ID = " . $arrRecord['id'] . "
		AND sn.ID = idn.id
		AND idn.curation_lvl = 0";
    $stmt_nuclear = make_query($DBConn,$query_nuclear,5);

    $nuclear_results = array();
    $count = 0;
    while($arrNuclear = retrieve_row($stmt_nuclear))
    {
      if(strlen($arrNuclear['haploid_number']) > 0)
        $nuclear_results[$count]['haploid_num'] = trim($arrNuclear['haploid_number']);
      else
        $nuclear_results[$count]['haploid_num'] = "&nbsp;";
      
      if(strlen($arrNuclear['dna_content']) > 0)
         $nuclear_results[$count]['dna_content'] =  trim($arrNuclear['dna_content']);
      else
         $nuclear_results[$count]['dna_content'] =  "&nbsp;";
      
      $count++;
    }
    return $nuclear_results;
  }
  
  function read_lg($DBConn, $arrRecord)
  {
    $query_linkage_groups = "
     SELECT A.ID, A.NAME 
     FROM LINKAGE_GROUP A, ID_NUM B 
     WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND A.SPECIES = " . $arrRecord['id'] . "
     ORDER BY A.NAME";
    $stmt_linkage_groups = make_query($DBConn,$query_linkage_groups,20);

    $linkage_results = array();
    $count = 0;
    while($arrLinkageGroups = retrieve_row($stmt_linkage_groups))
    {
      $linkage_results[$count]['id'] = $arrLinkageGroups["id"];
      $linkage_results[$count]['name'] = trim($arrLinkageGroups["name"]);
      $count++;
    }
    return $linkage_results;
  }
  
   function show_references($id, $DBConn, $tmpl)
   {
      $query_related_articles = "
        SELECT A.CONTENTS, A.REFERENCE 
        FROM ID_REFERENCE A, ID_NUM B 
        WHERE A.REFERENCE = B.ID AND B.CURATION_LVL = 0 AND A.ID = " . (int) $id . "
        ORDER BY A.CONTENTS";
      $stmt_related_articles = make_query($DBConn,$query_related_articles,10);
      
      $print = false;
      $count = 0;
      $reference = array();
      while($arrRelatedArticles = retrieve_row($stmt_related_articles))
      {
       if (strlen($arrRelatedArticles['contents']) > 0)
       {
          $query_contents = "
            SELECT name FROM term WHERE id = " . $arrRelatedArticles['contents'];
          $stmt_contents = make_query($DBConn,$query_contents,1);
          $arrContents = retrieve_row($stmt_contents);
          if(strlen($arrContents['name']) == 0)
            $arrContents['name'] = "general";
          $reference[$count]["cont_name"] = $arrContents['name']; 
       }
       else 
         $reference[$count]["cont_name"] = "general"; 
        
        if (strlen($arrRelatedArticles['reference']) > 0)
        {
          $query_reference = "
            SELECT id, name, title FROM reference 
            WHERE id = " . $arrRelatedArticles['reference'];
          $stmt_reference = make_query($DBConn,$query_reference,1);
          $arrReference = retrieve_row($stmt_reference);
          
          $reference[$count]["ref_id"] = $arrReference['id'];
          $reference[$count]["ref_title"] = addslashes($arrReference['title']);
          $reference[$count]["ref_name"] = trim($arrReference['name']);
        }
        
        $count++;
      }//each row
      
      $tmpl->get("fill_ref")->loop($reference);
    
    $matching_article_count = $count;
    //TODO: Print functionality for references used?
    if(strlen($print) > 0)
    {
      $bool = settype($matching_article_count, "integer");
      if($matching_article_count > 0)
      {
          $tmpl->get("hide_print")->unmute();
      }
    }
    else
    {
      $bool = settype($matching_article_count, "integer");
      if($matching_article_count > 0)
      {
        $tmpl->get('display')->replace('block');
      }
      else
        $tmpl->get('display')->replace('none');
    }
      $tmpl->get("match_count")->replace($matching_article_count);
    $tmpl->get("references")->unmute();
   }
  
  /**
   * Show the image carousel
   */
  function show_images($template, $id, $DBConn)
  {
    $query_images = "SELECT DISTINCT ON (URL, CAPTION) URL, CAPTION FROM WEB_IMAGE WHERE ID = " . (int) $id;
    $stmt_images = make_query($DBConn,$query_images,1);
    $arrImages = get_all_rows($stmt_images);
    
    $num_images = ($arrImages) ? count($arrImages) : 0;
    $img_count = 0;
    $bgcolor = "#F5F5F5";
    $img_results = array();
    
    while (strlen($arrImages[$img_count]['caption']) > 0) 
    {
      if ($img_count % 2 == 0)
        $img_results[$img_count]['bgcolor'] = "#F5F5F5";
      else
        $img_results[$img_count]['bgcolor'] = "";
          
       
      $img_results[$img_count]['img_count'] = $img_count + 1;
      $img_results[$img_count]['caption'] = mgdb_safe_html($arrImages[$img_count]['caption']);
      $img_results[$img_count]['url'] = $arrImages[$img_count]['url'];

      $img_count++;
      if ($img_count == $num_images)
        break;
    }
    
    if ($num_images > 0)
    {
      $template->get('species_img_tbl')->loop($img_results);
      $template->get('id')->replace($id);
      $template->get('img_carousel')->unmute();
      return true;
    }
    else
      return false;
  }
?>
