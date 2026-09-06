<?PHP
/* file: map_scores_data.php
 *
 * Display the various sections of a map scores record; called via Ajax
 *
 *  Test URL: data_center/map_scores?id=42279
 *                                      134062
 *                                      258448
 *
 * history:
 *  1/16/12  jportwood  created
 */

  include_once('../lib/Bauplan.php');
  include_once("../include/db-api.php");
  include_once("../include/api_tools.php");
  include_once('../include/gp_lib.php');
  include_once('../include/annotation_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $id   = getCGIParam("id", 'G', false);
  $type = getCGIParam("type", 'G', false);

  $username = getCookie('username', false);
  $password = getCookie('password', false);
  $userid   = getCookie('userid',   false);
    
  if (!$id) {
    reportError("No id given to map_scores_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to map_scores_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/map_scores_sections.bau');
  
  $DBConn = connect_to_database();

  // Clean up input typed by user
  $id = (int) $id;   // was validate_input(), which is a no-op; this id is a numeric
                       // MaizeGDB record id and every query below compares it as one.

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
    case 'maps':
      show_maps($tmpl, $id, $DBConn);
      break;
  }

  $bauplan->publish();
  
  
  function show_top($tmpl, $id, $DBConn) {   
    global $system;
    $tmpl->get('archive_url')->replace($system['archive_url']);
    
    $query = "SELECT * from MAP_SCORES where ID = " . (int) $id;
    $statement = make_query($DBConn,$query);
    $arrRecord = retrieve_row($statement);

    $tmpl->get('name')->replace($arrRecord['name']);
    $tmpl->get('id')->replace($id);
    
    $tmpl->get('top')->unmute();
  }//showTop
  
  function show_overview($tmpl, $id, $DBConn) {

    $query = "SELECT * from MAP_SCORES where ID = " . (int) $id;
    $statement = make_query($DBConn,$query);
    $arrRecord = retrieve_row($statement);

    if(isset($arrRecord['scores_123']))
    {
      $tmpl->get('scores_123')->replace($arrRecord["scores_123"]);
      $tmpl->get('scores_sec')->unmute();
    }
    
    if(isset($arrRecord["bin"]))
    {
      $tmpl->get('bin')->replace($arrRecord["bin"]);
      $tmpl->get('bin_sec')->unmute();
    }
    
    if(isset($arrRecord["map_score_comments"]))
    {
      $tmpl->get('note')->replace($arrRecord["map_score_comments"]);
      $tmpl->get('note_sec')->unmute();
    }
    
    if(isset($arrRecord["map_score_date"]))
    {
      $date = date_create($arrRecord["map_score_date"]);
      $tmpl->get('date')->replace(date_format($date, 'F jS, Y'));
      $tmpl->get('date_sec')->unmute();
    }
    
    if($linkage_group = read_linkage_group($DBConn, $arrRecord['linkage_group']))
    {
      $tmpl->get('lg_id')->replace($linkage_group['id']);
      $tmpl->get('lg_name')->replace($linkage_group['name']);
      $tmpl->get('lg_sec')->unmute();
    }
    
    if($other_marker = read_other_marker($DBConn, $arrRecord['other_marker']))
    {
      $tmpl->get('om_id')->replace($other_marker['id']);
      $tmpl->get('om_name')->replace($other_marker['name']);
      if (isset($other_marker['full_name']))
        $tmpl->get('om_full_name')->replace($other_marker['full_name']);
      $tmpl->get('om_sec')->unmute();
    }
    
    if($pos = read_pos($DBConn, $arrRecord['panel_of_stocks']))
    {
      $tmpl->get('pos_id')->replace($pos['id']);
      $tmpl->get('pos_name')->replace($pos['name']);
      $tmpl->get('pos_sec')->unmute();
    }
    
    if($gp = read_gp($DBConn, $arrRecord['parent1_pattern']))
    {
      $tmpl->get('gp1_id')->replace($gp['id']);
      $tmpl->get('gp1_name')->replace($gp['name']);
      $tmpl->get('gp1_sec')->unmute();
    }
    
    if($gp = read_gp($DBConn, $arrRecord['parent2_pattern']))
    {
      $tmpl->get('gp2_id')->replace($gp['id']);
      $tmpl->get('gp2_name')->replace($gp['name']);
      $tmpl->get('gp2_sec')->unmute();
    }
    
    if($probe = read_probe($DBConn, $arrRecord['probe']))
    {
      $tmpl->get('probe_id')->replace($probe['id']);
      $tmpl->get('probe_name')->replace($probe['name']);
      $tmpl->get('probe_type')->replace($probe['type']);
      $tmpl->get('probe_sec')->unmute();
    }
    
     if($probed_site = read_probed_site($DBConn, $arrRecord['probed_site']))
    {
      $tmpl->get('ps_id')->replace($probed_site['id']);
      $tmpl->get('ps_name')->replace($probed_site['name']);
      if (isset($probed_site['full_name']))
        $tmpl->get('ps_full_name')->replace($probed_site['full_name']);
      $tmpl->get('ps_sec')->unmute();
    }
    
    if($sb = read_submitted_by($DBConn, $arrRecord['submitted_by']))
    {
      $tmpl->get('sb_id')->replace($sb['id']);
      $tmpl->get('sb_name')->replace($sb['name']);
      $tmpl->get('sb_sec')->unmute();
    }
    
    $maps = read_maps($DBConn, $id);
    if ($maps && count($maps) > 0)
    {
      $tmpl->get('map_includes')->loop($maps);
      $tmpl->get('map_sec')->unmute();
    }

    $comments = getComments($DBConn, $id);
    if ($comments != '') {
      $tmpl->get('addl_comments')->replace($comments);
      $tmpl->get('additional_comments')->unmute();
    }
    
    if (!isset($arrRecord['bin']) && !isset($arrRecord['map_score_comments']) && 
        !isset($arrRecord['map_score_date']) && !$linkage_group && !$other_marker && 
        !$pos && !$gp && !$probe && !$probed_site && !$sb && !$maps && !$comments && 
        !isset($arrRecord['scores_123'])) 
      $tmpl->get('no_overview')->unmute(); //no data to display in overview section
   
    $tmpl->get('overview')->unmute();
  }//showOverview


  function showAnnotations($tmpl, $id, $DBConn) {
    global $username, $super_curator, $author_id;
    
    // Get the record
    $query_record = "SELECT * from MAP_SCORES where ID = " . (int) $id;
    $stmt_record = make_query($DBConn,$query_record,1);
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
    $tmpl->get('rec_name')->replace($arrRecord['name']);

     $tmpl->get('annotations')->unmute();
  }//showAnnotations
  
  
  
  /****************************************************
   ********************HELPER METHODS******************
   ****************************************************/
   
   function read_map_score_synonyms($DBConn, $id)
   {
     $querysyn = "
      SELECT A.SYNONYMS 
      from SYNONYMS A
      where A.ID = " . (int) $id;
     $stmtsyn = make_query($DBConn,$querysyn);
     $syn_results = array();
     $count = 0;
     while($arrSyn = retrieve_row($stmtsyn))
     {
        $syn_results[$count]['synonyms'] = $arrSyn['synonyms'];
        $count++;
     }
    return $syn_results;
   }
   
  function read_linkage_group($DBConn, $lg)
  {
    if(strlen($lg) > 0)
    { 
      $query_linkage_group = "
       SELECT A.ID, A.NAME 
       FROM LINKAGE_GROUP A, ID_NUM B 
       WHERE A.ID = $lg AND A.ID = B.ID AND B.CURATION_LVL = 0";
      $stmt_linkage_group = make_query($DBConn,$query_linkage_group);
      $arrLG = retrieve_row($stmt_linkage_group);
      return $arrLG;
    }
    else 
     return false;
  }
  
  function read_other_marker($DBConn, $om)
  {
    if(strlen($om) > 0)
    { 
      $query_other_marker = "
       SELECT A.ID, A.NAME, A.FULL_NAME 
       FROM LOCUS A, ID_NUM B 
       WHERE A.ID = $om AND A.ID = B.ID AND B.CURATION_LVL = 0";
      $stmt_other_marker = make_query($DBConn,$query_other_marker);
      $arrOM = retrieve_row($stmt_linkage_group);
      return $arrOM;
    }
    else 
     return false;
  }
  
  function read_pos($DBConn, $pos)
  {
    if(strlen($pos) > 0)
    { 
      $query_panel_of_stocks = "
       SELECT A.ID, A.NAME 
       FROM PANEL_OF_STOCKS A, ID_NUM B 
       WHERE A.ID = $pos AND A.ID = B.ID AND B.CURATION_LVL = 0";
      $stmt_panel_of_stocks = make_query($DBConn,$query_panel_of_stocks);
      
      if ($arrPOS = retrieve_row($stmt_panel_of_stocks))
        return $arrPOS;
      else
        return false;
    }
    else 
     return false;
  }
  
  function read_gp($DBConn, $gp)
  {
    if(strlen($gp) > 0)
    { 
      $query_parent1_pattern = "
       SELECT A.ID, A.NAME 
       FROM GEL_PATTERN A, ID_NUM B 
       WHERE A.ID = $gp AND A.ID = B.ID AND B.CURATION_LVL = 0";
      $stmt_parent1_pattern = make_query($DBConn,$query_parent1_pattern);
      
      if ($arrGP = retrieve_row($stmt_parent1_pattern))
        return $arrGP;
      else
        return false;
    }
    else 
     return false;
  }
  
  function read_probe($DBConn, $probe)
  {
    if(strlen($probe) > 0)
    { 
      $query_probe = "
        SELECT A.ID, A.NAME, A.TYPE 
        FROM PROBE A, ID_NUM B
        WHERE A.ID = $probe AND A.ID = B.ID AND B.CURATION_LVL = 0";
      $statement_probe = make_query($DBConn,$query_probe);
      if ($arrProbe = retrieve_row($statement_probe))
      {
        switch ($arrProbe['type']){
          case "34":
            $arrProbe['type'] = "est";
            break;
          case "104436":
            $arrProbe['type'] = "ssr" ;
            break;
          case "171715":
            $arrProbe['type'] = "bac";
            break;
          case "393660":
            $arrProbe['type'] = "overgo";
            break;
          default:
            $arrProbe['type'] = "marker";
        }
        return $arrProbe;
      }
      else
        return false;
    }
    else 
     return false;
  }
  
  function read_probed_site($DBConn, $probed_site)
  {
    if(strlen($probed_site) > 0)
    { 
      $query_probed_site = "
        SELECT A.ID, A.NAME, A.FULL_NAME 
        FROM LOCUS A, ID_NUM B
        WHERE A.ID = $probed_site AND A.ID = B.ID AND B.CURATION_LVL = 0";
      $stmt_probed_site = make_query($DBConn,$query_probed_site);
      
      if ($arrProbe = retrieve_row($stmt_probed_site))
        return $arrProbe;
      else
        return false;
    }
    else 
     return false;
  }
  
  function read_submitted_by($DBConn, $submitted_by)
  {
    if(strlen($submitted_by) > 0)
    { 
      $query_submitted_by = "
       SELECT A.ID, A.NAME 
       FROM PERSON A, ID_NUM B 
       WHERE A.ID = $submitted_by AND A.ID = B.ID AND B.CURATION_LVL = 0";
      $stmt_submitted_by = make_query($DBConn,$query_submitted_by);
      
      if ($sub = retrieve_row($stmt_submitted_by))
        return $sub;
      else
        return false;
    }
    else 
     return false;
  }
   
  function read_maps($DBConn, $id)
  {
    $query_includes = "
     SELECT B.ID AS MAP_ID, B.NAME AS MAP_NAME, D.ID AS PERSON_ID, D.NAME AS PERSON_NAME 
     from MAP_SCORES_INCLUDE_MAPS A, MAP B, ID_NUM C, PERSON D, ID_NUM E
     where A.ID = $id AND A.MAP = B.ID AND B.ID = C.ID AND C.CURATION_LVL = 0 
      AND A.BY1 = D.ID AND D.ID = E.ID AND E.CURATION_LVL = 0";
    $stmt_includes = make_query($DBConn,$query_includes);
    $maps = get_all_rows($stmt_includes);
    return $maps;
  }
?>
