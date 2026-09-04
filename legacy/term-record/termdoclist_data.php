<?PHP
/* file: termdoclist_data.php
 *
 * purmap_scoree: display the various sections of a map scores record; called via Ajax
 *
 * history:
 *  1/16/12  jportwood  created
 */

  include_once('../lib/Bauplan.php');
  include_once("../include/db-api.php");
  include_once("../include/api_tools.php");
  include_once('../include/gp_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $id   = getCGIParam("id", 'G', false);
  $type = getCGIParam("type", 'G', false);
  $term_type = getCGIParam("term_type", 'G', false);

  logMessage("termdoclist_data.php: id=$id, type=$type");
  
  if (!$id) {
    reportError("No id given to termdoclist_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to termdoclist_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/termdoclist_sections.bau');
  $DBConn = connect_to_database();

  // Clean up input typed by user
  $id = validate_input($DBConn, $id); 
  
  switch ($type) {
    case 'top':
      show_top($tmpl, $id, $DBConn);
      break;
    case 'terms':
      show_terms($tmpl, $id, $term_type, $DBConn);
      break;
  }
  $bauplan->publish();
  
  function show_top($tmpl, $id, $DBConn)
  {   
    $query = "SELECT TITLE, NAME from reference where ID = " . $id;
    $statement = make_query($DBConn,$query);
    $arrRef = retrieve_row($statement);
//echo "show_top<br>";
//echo "<pre>";var_dump($arrRef);echo "</pre>";
    $tmpl->get('name')->replace($arrRef["name"]);
    $tmpl->get('title')->replace($arrRef["title"]);
    $tmpl->get('id')->replace($id);
    
    $tmpl->get('top')->unmute();
  }//showTop
  
  function show_terms($tmpl, $id, $term_type, $DBConn) {
    global $system;
    $query = " 
       select a.ID as AID, b.NAME 
       from id_reference a 
		join term b on a.id = b.id 
		JOIN id_num idn ON a.ID = idn.id
       where reference = " . $id ." 
		and type = " . $term_type . " 
		AND idn.curation_lvl = 0
	   ORDER BY ORDER1, NAME";
    $statement = make_query($DBConn,$query);
    $arrReferences = get_all_rows($statement);

  //  echo "show_terms<br>";
//echo "<pre>";var_dump($arrReferences);echo "</pre>";
    
    if ($id == 9021713) { //show terms with images
      $refStr = "<tr>";
      for ($i=0; $i<count($arrReferences);$i++){
        $queryImage = "SELECT URL, CAPTION from WEB_IMAGE WHERE ID = " . $arrReferences[$i]["aid"];
        $statementImage = make_query($DBConn,$queryImage);
        $arrImage = retrieve_row($statementImage);
        $url = $system["image_server_url"] . "/db_images/Term/";
        if ($i % 2 == 0 && $i > 0)
          $refStr .= "</tr><tr>";
        $refStr .= "<td width=\"50%\"><a href='/data_center/term?id=". $arrReferences[$i]["aid"] . "'>". $arrReferences[$i]["name"] . "</a>
                <br>
                <a href='#'>
                  <img style='border-style: none' onclick=\"open_sb('".$url.trim($arrImage["url"])."','".$arrReferences[$i]["name"]."',false);\" src=\"".$url."downsized/".trim($arrImage["url"])."\"'/>
                </a></td>";
      }
      $refStr .="</tr>";
      $tmpl->get('refImg')->replace($refStr);
      $tmpl->get('references')->mute();
    }
    
    if (!$arrReferences) 
      $tmpl->get('no_terms')->toggle(); //no data to display in overview section
    else if ($id != 9021713)
      $tmpl->get('references')->loop($arrReferences);
    $tmpl->get('terms_sec')->unmute();
  }//showTerms
  
  /****************************************************
   ********************HELPER METHODS******************
   ****************************************************/

   function read_image($DBConn, $id)
   {
     $queryImage = "SELECT URL, CAPTION from WEB_IMAGE WHERE ID = " . $id;
     $statementImage = make_query($DBConn,$queryImage);
     $count = 0;
     $img = array();
     while($arrImage = retrieve_row($statementImage)){
       if (strpos($arrImage["url"], "/") !== false){
         $thumbnail = explode("/", $arrImage['url']);
         $img[$count]['downsized'] = $thumbnail[0] . "/downsized/" . $thumbnail[1];
       }
       else {
         $img[$count]['downsized'] = "downsized/" . $arrImage['url'];
       }
       $img[$count]['caption'] = $arrImage['caption'];
       $img[$count]['url'] = $arrImage['url'];
       $count++;
     }
     if($count > 0)
       return $img;
     else
       return false;
   }
?>