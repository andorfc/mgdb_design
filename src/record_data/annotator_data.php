<?PHP
/* file: person_data.php
 *
 * purpose: display the various sections of a person record; called via Ajax
 *
 * history:
 *  10/09/12  jportwood  created
 *  11/20/12  eksc       completed
 *
 * -----------------> UNUSED <---------------
 *
 */
  //find new links. Are references and annotators different?
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
  $flush = settype($id, "integer");
  $type = getCGIParam("type", 'G', false);
  // $SITE_URL = "http://sigma.maizegdb.org/";
  $SITE_URL = $system['root_url'];
  /* echo $id . "," . $type;
  exit; */
  logMessage("annotator_data.php: id=$id, type=$type");
  
  if (!$id) {
    reportError("No id given to annotator_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to annotator_data.php.");
    exit;
  }
 //change
  
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
    $tmpl = $bauplan->template()->load('../templates/community/annotator_sections.bau');
	
	// If annotator, check for super curator
    if ($username) {
      $user_info = get_user_info($DBConn, $username);
      $super_curator = ($user_info['curation_lvl'] <= -5);
      $author_id = $user_info['annotation_author_id'];
    }
    
    // Clean up input typed by user
    $id = (int) $id;   // ANNOTATION_AUTHOR.ID and ANNOTATION.ANN_AUTHOR_ID are bigint
    
	//change made
    $query_user = "SELECT FIRST_NAME, LAST_NAME, EMAIL FROM ANNOTATION_AUTHOR WHERE ID = " . $id;
    $stmt_user = make_query($DBConn,$query_user,1);
    $arrUser = retrieve_row($stmt_user);
	
	switch ($type) {
      case 'top':
        show_top($tmpl, $id, $DBConn, $arrUser);
        break;
      /* case 'projects':
        show_projects($tmpl, $id, $DBConn, $arrRecord);
        break; */
    }
  
 }
	
	$bauplan->publish();
	
  function generate_name_query($id,$type)
  {
    $flush = settype($type,"integer");
    $query = "";
    if($type == 18)
      $query = "SELECT NAME FROM JOURNAL WHERE ID = " . $id;
    if($type == 19)
      $query = "SELECT NAME FROM LOCUS WHERE ID = " . $id;
    if($type == 20)
      $query = "SELECT NAME FROM PERSON WHERE ID = " . $id;
    if($type == 21)
      $query = "SELECT NAME FROM TERM WHERE ID = " . $id;
    if($type == 22)
      $query = "SELECT NAME FROM REFERENCE WHERE ID = " . $id;
    if($type == 23)
      $query = "SELECT SPECIES AS NAME FROM SPECIES WHERE ID = " . $id;
    if($type == 24)
      $query = "SELECT NAME FROM MAP_SCORES WHERE ID = " . $id;
    if($type == 25)
      $query = "SELECT NAME FROM META_PATH WHERE ID = " . $id;
    if($type == 26)
      $query = "SELECT NAME FROM STOCK WHERE ID = " . $id;
    if($type == 27)
      $query = "SELECT NAME FROM ENZ_CAT_REACTION WHERE ID = " . $id;
    if($type == 28)
      $query = "SELECT NAME FROM LINKAGE_GROUP WHERE ID = " . $id;
    if($type == 29)
      $query = "SELECT NAME FROM PANEL_OF_STOCKS WHERE ID = " . $id;
    if($type == 30)
      $query = "SELECT NAME FROM ENVIRONMENT WHERE ID = " . $id;
    if($type == 31)
      $query = "SELECT NAME FROM GEL_PATTERN WHERE ID = " . $id;
    if($type == 32)
      $query = "SELECT NAME FROM CLONE_LIBRARY WHERE ID = " . $id;
    if($type == 33)
      $query = "SELECT NAME FROM PHENOTYPE WHERE ID = " . $id;
    if($type == 35)
      $query = "SELECT NAME FROM QTL_EXP WHERE ID = " . $id;
    if($type == 36)
      $query = "SELECT NAME FROM QTL_LINK_ANALYSIS WHERE ID = " . $id;
    if($type == 37)
      $query = "SELECT NAME FROM PRIMER WHERE ID = " . $id;
    if($type == 38)
      $query = "SELECT NAME FROM TRAIT_ANALYSIS WHERE ID = " . $id;
    if($type == 39)
      $query = "SELECT NAME FROM RECOMB WHERE ID = " . $id;
    if($type == 40)
      $query = "SELECT NAME FROM REACTION WHERE ID = " . $id;
    if($type == 41)
      $query = "SELECT NAME FROM DNA_RNA_ISOLATION_PREP WHERE ID = " . $id;
    if($type == 32219)
      $query = "SELECT NAME FROM KARYOTYPIC_VARIATION WHERE ID = " . $id;
    if($type == 45974)
      $query = "SELECT NAME FROM GENE_PRODUCT WHERE ID = " . $id;
    if($type == 60390)
      $query = "SELECT NAME FROM MAP WHERE ID = " . $id;
    if($type == 65737)
      $query = "SELECT NAME FROM VARIATION WHERE ID = " . $id;
    if($type == 105888)
      $query = "SELECT NAME FROM PROBE WHERE ID = " . $id;
    return $query;
  }
  //change $url_prefix to correct link
  function generate_url_prefix($type,$subtype)
  {
    $flush = settype($type,"integer");
    $url_prefix = "";
    if($type == 18)
	  // No new link?
      $url_prefix = "displayjournalrecord.cgi?id=";
    if($type == 19)
	  $url_prefix= "data_center/locus/";
    if($type == 20)
	  $url_prefix= "person?id=";
    if($type == 21)
      $url_prefix = "displaytraitrecord.cgi?id=";
    if($type == 22)
	  $url_prefix = "data_center/reference?id=";
    if($type == 23)
      $url_prefix = "displayspeciesrecord.cgi?id=";
    if($type == 24)
	  $url_prefix = "data_center/map/id=";
    if($type == 25)
      $url_prefix = "displaymprecord.cgi?id=";
    if($type == 26)
	  $url_prefix = "data_center/stock?id=";
    if($type == 27)
      $url_prefix = "displayecrrecord.cgi?id=";
    if($type == 28)
      $url_prefix = "displaylgrecord.cgi?id=";
    if($type == 29)
      $url_prefix = "displayposrecord.cgi?id=";
    if($type == 30)
      $url_prefix = "displayenvrecord.cgi?id=";
    if($type == 31)
	  $url_prefix = "data_center/gel?id=";
    if($type == 32)
      $url_prefix = "displayclrecord.cgi?id=";
    if($type == 33)
	  $url_prefix = "data_center/phenotype?id=";
    if($type == 35)
      $url_prefix = "displayqtlexprecord.cgi?id=";
    if($type == 36)
      $url_prefix = "displayqtlanalysisrecord.cgi?id=";
    if($type == 37)
      $url_prefix = "displayprimerrecord.cgi?id=";
    if($type == 38)
      $url_prefix = "displaytraitanalysisrecord.cgi?id=";
    if($type == 39)
	  //No new link?
      $url_prefix = "displayrecombrecord.cgi?id=";
    if($type == 40)
      $url_prefix = "";
    if($type == 41)
      $url_prefix = "";
    if($type == 32219)
      $url_prefix = "";
    if($type == 45974)
	  $url_prefix = "data_center/gene_product?id=";
    if($type == 60390)
	  $url_prefix = "data_center/map?id=";
    if($type == 65737)
      $url_prefix = "data_center/variation?id=";
    if($type == 105888)
    {
      if($subtype == 171715)
		$url_prefix = "data_center/bac?id=";
      else if($subtype == 34)
        $url_prefix = "displayestrecord.cgi?id=";
      else if($subtype == 393660)
        $url_prefix = "displayovergorecord.cgi?id=";
      else if($subtype == 104436)
        $url_prefix = "displayssrrecord.cgi?id=";
      else
		$url_prefix = "data_center/marker?id=";
    }
    return $url_prefix;
  }

  function generate_data_type($type,$subtype)
  {
	$SITE_URL = $system['root_url'];
    $flush = settype($type,"integer");
    $url_prefix = "";
    if($type == 18)
      $url_prefix = "(journal)";
    if($type == 19)
      $url_prefix = "(<a href=\"" . $SITE_URL . "/data_center/locus\">locus</a>)";
    if($type == 20)
      $url_prefix = "(<a href=\"" . $SITE_URL . "/person\">person/organization</a>)";
    if($type == 21)
      $url_prefix = "(trait)";
    if($type == 22)
      $url_prefix = "(<a href=\"" . $SITE_URL . "/data_center/reference\">reference</a>)";
    if($type == 23)
      $url_prefix = "(species)";
    if($type == 24)
      $url_prefix = "(<a href=\"" . $SITE_URL . "/data_center/map\">map score</a>)";
    if($type == 25)
      $url_prefix = "(<a href=\"" . $SITE_URL . "/data_center/metabolic_pathway\">metabolic pathway</a>)";
    if($type == 26)
      $url_prefix = "(<a href=\"" . $SITE_URL . "/data_center/stock\">stock</a>)";
    if($type == 27)
      $url_prefix = "(enzyme-catalyzed reaction)";
    if($type == 28)
      $url_prefix = "(linkage group)";
    if($type == 29)
      $url_prefix = "(panel of stocks)";
    if($type == 30)
      $url_prefix = "(environment)";
    if($type == 31)
      $url_prefix = "(gel pattern)";
    if($type == 32)
      $url_prefix = "(clone library)";
    if($type == 33)
      $url_prefix = "(<a href=\"" . $SITE_URL . "/data_center/reference\">phenotype</a>)";
    if($type == 35)
      $url_prefix = "(<a href=\"" . $SITE_URL . "/data_center/locus\">QTL experiment</a>)";
    if($type == 36)
      $url_prefix = "(<a href=\"" . $SITE_URL . "/data_center/locus\">QTL linkage analysis</a>)";
    if($type == 37)
      $url_prefix = "(primer)";
    if($type == 38)
      $url_prefix = "(<a href=\"" . $SITE_URL . "/data_center/locus\">trait analysis</a>)";
    if($type == 39)
      $url_prefix = "(recombination data)";
    if($type == 40)
      $url_prefix = "(reaction)";
    if($type == 41)
      $url_prefix = "(preparative technique)";
    if($type == 32219)
      $url_prefix = "(karyotypic variation)";
    if($type == 45974)
      $url_prefix = "(<a href=\"" . $SITE_URL . "/data_center/gene_product\">gene product</a>)";
    if($type == 60390)
      $url_prefix = "(<a href=\"" . $SITE_URL . "/data_center/map\">map</a>)";
    if($type == 65737)
      $url_prefix = "(<a href=\"" . $SITE_URL . "/data_center/variation\">variation</a>)";
    if($type == 105888)
    {
      if($subtype == 171715)
        $url_prefix = "(<a href=\"" . $SITE_URL . "/data_center/bac\">BAC clone</a>)";
      else if($subtype == 34)
        $url_prefix = "(<a href=\"" . $SITE_URL . "/data_center/est\">EST</a>)";
      else if($subtype == 393660)
        $url_prefix = "(<a href=\"" . $SITE_URL . "/data_center/overgo\">overgo</a>)";
      else if($subtype == 104436)
        $url_prefix = "(<a href=\"" . $SITE_URL . "/data_center/ssr\">SSR</a>)";
      else
        $url_prefix = "(<a href=\"" . $SITE_URL . "/data_center/probe\">probe</a>)";
    }
    return $url_prefix;
  }
	

  
  //////////////////////////////////////////////////////////////////////////////
  function show_top($tmpl, $id, $DBConn, $arrUser) { 
    global $username, $super_curator, $author_id;
    if ((strlen(trim($arrUser["FIRST_NAME"])) > 0) 
          && (strlen(trim($arrUser["LAST_NAME"])) > 0)) {
      $namesave = $arrUser["FIRST_NAME"] . " " . $arrUser["LAST_NAME"]; 
      $title = $arrUser["FIRST_NAME"] . " " . $arrUser["LAST_NAME"];
      if(strlen($arrUser["SUFFIX"]) > 0) 
        $title = $title . ", " . $arrUser["SUFFIX"];
    }
    else {
      $namesave = $arrUser["NAME_FIRST"] . " " . $arrUser["NAME_LAST"];
      $title = $arrUser["NAME_FIRST"] . " " . $arrUser["NAME_LAST"];
    }
	//$buttonLink = "http://www.maizegdb.org/cgi-bin/displayannotatorrecord.cgi?id=" . $id;
	$buttonLink = "http://archive.maizegdb.org/cgi-bin/displayannotatorrecord.cgi?id=" . $id;
	$tmpl->get('buttonLink')->replace($buttonLink);
    $tmpl->get('email_address')->replace($arrUser["EMAIL"]);
    $tmpl->get('name')->replace($title);
    // $tmpl->get('synonyms')->replace(read_synonyms($DBConn, $id));

    // show_portrait($tmpl, $id, $DBConn);
    // show_recognitions($tmpl, $id, $DBConn);
    
	$query_find_real_user = "SELECT ID FROM PERSON WHERE NAME_FIRST LIKE '" . $arrUser["FIRST_NAME"] . "' AND NAME_LAST LIKE '" . $arrUser["LAST_NAME"] . "'";
    $stmt_find_real_user = make_query($DBConn,$query_find_real_user,1);
    $arrRealUser = retrieve_row($stmt_find_real_user);
	if(strlen($arrRealUser["ID"]) > 0) {
		$tmpl->get('person_id')->replace($arrRealUser["ID"]);
	}
	
	
    //Display contact info
    // show_contact($tmpl, $id, $DBConn, $arrRecord);
    
    // $roles = read_roles($DBConn, $arrRecord);
    /* if($roles && count($roles > 0))
    {
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
     */
    /////// Look for user annotations ///////
    /* $annotations = getAnnotationHTML($DBConn, $id, '', $username, $author_id, 
                                     $super_curator, 'id');
    if ($annotations) {
      $tmpl->get('annotation-list')->replace($annotations);
      $tmpl->get('annotation-user')->unmute();
    } */
    
    $tmpl->get('top')->unmute();
	$tmpl->get('annotations')->unmute();
	show_annotations($tmpl, $id, $DBConn, $arrUser);
  }//showTop

  
  function show_contact($tmpl, $id, $DBConn, $arrRecord) 
  {
    if(strlen($arrRecord["ADDRESS"]) > 0) 
    {
      $tmpl->get('address')->replace($arrRecord["address"]);
      $tmpl->get('city')->replace($arrRecord["city"]);
      $tmpl->get('state')->replace($arrRecord["state"]);
      $tmpl->get('country')->replace($arrRecord["country"]);
      $tmpl->get('postal_code')->replace($arrRecord["postal_code"]);
      $tmpl->get('id')->replace($id);
    } 
    else 
      $tmpl->get('no_address')->toggle();
    
    $query_email = "SELECT EMAIL_ADDRESS FROM PERSON_EMAIL WHERE ID = " . $arrRecord["ID"];
    $statement_email = make_query($DBConn,$query_email);
    $email = array();
    $count = 0;
    while($arrEmail = retrieve_row($statement_email))
    {
      $email[$count]['email_address'] = $arrEmail['email_address'];
      $email[$count]['sep'] = ' '; 
      $count++;
    }
      
    if (count($email) > 0) {
      $tmpl->get('email')->loop($email);
      $tmpl->get('email')->unmute();
    }

    $query_url = "SELECT URL from WEB_DATA where ID = " . $arrRecord["ID"];
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

    $query_phone = "
      SELECT PHONE_NUM 
      FROM PERSON_PHONE_NUM 
      WHERE ID = " . $arrRecord["ID"] . " 
      ORDER BY PHONE_NUM";
    $statement_phone = make_query($DBConn,$query_phone);
    $arrPhone = get_all_rows($statement_phone);
    
    if ($arrPhone && count($arrPhone) > 0) {
      $tmpl->get("phone")->loop($arrPhone); 
      $tmpl->get('phone')->unmute();
    }
  }//show_contact
  
  /********************************************************Do sequence annotations as well*********************************************************************/
  function show_annotations($tmpl, $id, $DBConn, $arrUser)
  {

	$query_count_normal_annotations = "SELECT COUNT(AUTO_NUM) FROM ANNOTATION WHERE ANN_AUTHOR_ID = " . $id . " AND CURATION_LVL < 2";
    $query_count_sequence_annotations = "SELECT COUNT(AUTO_NUM) FROM ANNOTATION_SEQUENCE WHERE AUTHOR_EMAIL IN (SELECT EMAIL FROM ANNOTATION_AUTHOR WHERE ID = " . $id . ") AND CURATION_LVL < 2";
    $stmt_count_normal_annotations = make_query($DBConn,$query_count_normal_annotations,1);
    $arrNormAnnotationCount = retrieve_row($stmt_count_normal_annotations);
    $stmt_count_sequence_annotations = make_query($DBConn,$query_count_sequence_annotations,1);
    $arrSeqAnnotationCount = retrieve_row($stmt_count_sequence_annotations);
    $num_norm_annotations = $arrNormAnnotationCount["COUNT"];
    $num_seq_annotations = $arrSeqAnnotationCount["COUNT"];
	$flush = settype($num_norm_annotations,"integer");
    $flush = settype($num_seq_annotations,"integer");
	$count = $num_norm_annotations + $num_seq_annotations;
	// echo $count;
	// exit;
	if(!($count > 0))
		$count = "0";
    $tmpl->get('match_count')->replace($count);
	if($id == 204) {
		$query_count = "SELECT count(*) FROM ID_NUM A, ANNOTATION B, ANNOTATION_AUTHOR C WHERE B.ID = A.ID AND C.EMAIL = B.AUTHOR_EMAIL AND B.CURATION_LVL < 2 AND A.CURATION_LVL = 0 AND C.ID = 204";
		$stmt_count = make_query($DBConn, $query_count, 1);
		$num_count = retrieve_row($stmt_count);
		$count = $num_count["COUNT"];
	}
	$query_normal_annotations = "SELECT A.ID, A.TYPE_TERM, B.MEMO FROM ID_NUM A, ANNOTATION B, ANNOTATION_AUTHOR C WHERE B.ID = A.ID AND C.EMAIL = B.AUTHOR_EMAIL AND B.CURATION_LVL < 2 AND A.CURATION_LVL = 0 AND C.ID = " . $id . " ORDER BY A.TYPE_TERM, A.ID";
    $stmt_normal_annotations = make_query($DBConn,$query_normal_annotations,25);
    $arrNormAnnotations = retrieve_row($stmt_normal_annotations);
    $query_record_name = generate_name_query($arrNormAnnotations["ID"],$arrNormAnnotations["TYPE_TERM"]);
    $stmt_record_name = make_query($DBConn,$query_record_name,1);
    $arrRecordName = retrieve_row($stmt_record_name);
	//obtain the memos and place them in an indexable array
	$memo = array();
	$arrId = array();
	for($i = 0; $i < $count; $i++) {
	  array_push($memo, mgdb_safe_html($arrNormAnnotations["MEMO"]));
	  array_push($arrId, $arrNormAnnotations["ID"]);
	  $arrNormAnnotations = retrieve_row($stmt_normal_annotations);
	  
	}
	$memo = array_values($memo);
	$arrId = array_values($arrId);
	/* for($j = 0; $j < $count; $j++) {
		echo $arrId[$j] . " : " . $memo[$j] .  '<br>' . '<br>';
	}
	exit; */
	$query_normal_annotations = "SELECT A.ID, A.TYPE_TERM, B.MEMO FROM ID_NUM A, ANNOTATION B, ANNOTATION_AUTHOR C WHERE B.ID = A.ID AND C.EMAIL = B.AUTHOR_EMAIL AND B.CURATION_LVL < 2 AND A.CURATION_LVL = 0 AND C.ID = " . $id . " ORDER BY A.TYPE_TERM, A.ID";
    $stmt_normal_annotations = make_query($DBConn,$query_normal_annotations,25);
    $arrNormAnnotations = retrieve_row($stmt_normal_annotations);
    $query_record_name = generate_name_query($arrNormAnnotations["ID"],$arrNormAnnotations["TYPE_TERM"]);
    $stmt_record_name = make_query($DBConn,$query_record_name,1);
    $arrRecordName = retrieve_row($stmt_record_name);
    if($arrNormAnnotations["TYPE_TERM"] == "105888")
    {
      $query_subtype = "SELECT TYPE FROM PROBE WHERE ID = " . $arrNormAnnotations["ID"];
      $stmt_subtype = make_query($DBConn,$query_subtype,1);
      $arrSubtype = retrieve_row($stmt_subtype);
      $subtype = $arrSubtype["TYPE"];
    }
	else {
		$subtype = 0;
	}

	$SITE_URL = $system['root_url'];
	$index = 0;
	$annotations = array();
	$type_id = generate_data_type($arrNormAnnotations["TYPE_TERM"],$subtype);
    $url_prefix = generate_url_prefix($arrNormAnnotations["TYPE_TERM"],$subtype);
	if($num_norm_annotations > 0) {
	 $linkToAdd = $SITE_URL . $url_prefix . $arrId[$index];
	array_push($annotations,
				array('link'	=> $linkToAdd,
					  'record_name2'	=> trim($arrRecordName["NAME"]),
					  'type_id'	=> $type_id,
					  'memo'	=> $memo[$index++]));
	
	} 
	
	$arrNormAnnotations = retrieve_row($stmt_normal_annotations);
	while(strlen($arrNormAnnotations["TYPE_TERM"]) > 0)
    {
	  
      $query_record_name = generate_name_query($arrNormAnnotations["ID"],$arrNormAnnotations["TYPE_TERM"]);
      $stmt_record_name = make_query($DBConn,$query_record_name,1);
      $arrRecordName = retrieve_row($stmt_record_name);
      if($arrNormAnnotations["TYPE_TERM"] == "105888")
      {
        $query_subtype = "SELECT TYPE FROM PROBE WHERE ID = " . $arrNormAnnotations["ID"];
        $stmt_subtype = make_query($DBConn,$query_subtype,1);
        $arrSubtype = retrieve_row($stmt_subtype);
        $subtype = $arrSubtype["TYPE"];
      }
      else
        $subtype = 0;
      $type_id = generate_data_type($arrNormAnnotations["TYPE_TERM"],$subtype);
      $url_prefix = generate_url_prefix($arrNormAnnotations["TYPE_TERM"],$subtype);
	  $arrNormAnnotations = retrieve_row($stmt_normal_annotations);
	  $linkToAdd = $SITE_URL . $url_prefix . $arrId[$index];
	  array_push($annotations,
				array('link'	=> $linkToAdd,
					  'record_name2'	=> trim($arrRecordName["NAME"]),
					  'type_id'	=> $type_id,
					  'memo'	=> $memo[$index++]));
      // echo "<p><b><a href=\"" . $url_prefix . $arrNormAnnotations["ID"] . "\">" . trim($arrRecordName["NAME"]) . "</a></b> " . $type_id . ": \"" . $arrNormAnnotations["MEMO"] . "\"</p>\n";
      
    }
	//sequences
	$query_seq_annotations = "SELECT A.SEQ_ID, A.GENBANK_ACC, B.MEMO FROM Z_SEQUENCE A, ANNOTATION_SEQUENCE B WHERE B.SEQ_ID = A.SEQ_ID AND B.CURATION_LVL < 2 AND B.AUTHOR_EMAIL IN (SELECT EMAIL FROM ANNOTATION_AUTHOR WHERE ID = " . $id . ") ORDER BY A.SEQ_ID";
    $stmt_seq_annotations = make_query($DBConn,$query_seq_annotations,25);
    $arrSeqAnnotations = retrieve_row($stmt_seq_annotations);
	if($num_seq_annotations > 0)
    {
      if(strlen($arrSeqAnnotations["GENBANK_ACC"]) > 0)
		array_push($annotations,
			array('link'	=> "data_center/sequence?id=" . $arrSeqAnnotations["SEQ_ID"],
				  'record_name2' => trim($arrSeqAnnotations["GENBANK_ACC"]),
				  'type_id' => "(<a href=\"" . $SITE_URL . "/sequence.php\">sequence</a>)",
				  'memo'    => mgdb_safe_html($arrSeqAnnotations["MEMO"])));
        // echo "<p><b><a href=\"displayseqrecord.cgi?id=" . $arrSeqAnnotations["SEQ_ID"] . "\">" . trim($arrSeqAnnotations["GENBANK_ACC"]) . "</a></b> (<a href=\"" . $SITE_URL . "/sequence.php\">sequence</a>): \"" . $arrSeqAnnotations["MEMO"] . "\"</p>\n";
      else
		array_push($annotations,
			array('link'	=> "data_center/sequence?id=" . $arrSeqAnnotations["SEQ_ID"],
			      'record_name2' => trim($arrSeqAnnotations["SEQ_ID"]),
				  'type_id' => "(<a href=\"" . $SITE_URL . "/sequence.php\">sequence</a>)",
				  'memo'    => mgdb_safe_html($arrSeqAnnotations["MEMO"])));
        // echo "<p><b><a href=\"displayseqrecord.cgi?id=" . $arrSeqAnnotations["SEQ_ID"] . "\">" . trim($arrSeqAnnotations["SEQ_ID"]) . "</a></b> (<a href=\"" . $SITE_URL . "/sequence.php\">sequence</a>): \"" . $arrSeqAnnotations["MEMO"] . "\"</p>\n";
    }
	$arrSeqAnnotations = retrieve_row($stmt_seq_annotations);
    while(strlen($arrSeqAnnotations["TYPE_TERM"]) > 0)
    {
      if(strlen($arrSeqAnnotations["GENBANK_ACC"]) > 0)
	    array_push($annotations,
			array('link'	=> "data_center/sequence?id=" . $arrSeqAnnotations["SEQ_ID"],
			      'record_name2' => trim($arrSeqAnnotations["GENBANK_ACC"]),
				  'type_id' => "(<a href=\"" . $SITE_URL . "/sequence.php\">sequence</a>)",
				  'memo'	=> mgdb_safe_html($arrSeqAnnotations["MEMO"])));
        // echo "<p><b><a href=\"displayseqrecord.cgi?id=" . $arrSeqAnnotations["SEQ_ID"] . "\">" . trim($arrSeqAnnotations["GENBANK_ACC"]) . "</a></b> (<a href=\"" . $SITE_URL . "/sequence.php\">sequence</a>): \"" . $arrSeqAnnotations["MEMO"] . "\"</p>\n";
      else
		array_push($annotations,
			array('link'	=> "data_center/sequence?id=" . $arrSeqAnnotations["SEQ_ID"],
				  'record_name2' => trim($arrSeqAnnotations["SEQ_ID"]),
				  'type_id'	=> "(<a href=\"" . $SITE_URL . "/sequence.php\">sequence</a>)",
				  'memo'	=> mgdb_safe_html($arrSeqAnnotations["MEMO"])));
        // echo "<p><b><a href=\"displayseqrecord.cgi?id=" . $arrSeqAnnotations["SEQ_ID"] . "\">" . trim($arrSeqAnnotations["SEQ_ID"]) . "</a></b> (<a href=\"" . $SITE_URL . "/sequence.php\">sequence</a>): \"" . $arrSeqAnnotations["MEMO"] . "\"</p>\n";
      $arrSeqAnnotations = retrieve_row($stmt_seq_annotations);
    }
       $tmpl->get('annotation-list')->loop($annotations);
      
      $tmpl->get('annotations')->unmute();
    }//found annotations
  //show_annotations()
  

  function show_projects($tmpl, $id, $DBConn, $arrRecord) {
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
  
  
  function show_portrait($tmpl, $id, $DBConn) {
    $sql = "SELECT URL FROM WEB_IMAGE WHERE ID=" . (int) $id;
    $sth = make_query($DBConn, $sql);
    $rows = get_all_rows($sth);
    $count = ($rows) ? count($rows) : 0;
logMessage("Found $count images for $id");
    
    if ($count > 1) {
      reportError("Found more than one 'portrait' for PERSON record $id");
    }
    else if ($count == 1) {
logMessage("Show portrait");
      $tmpl->get('img_url')->replace($rows[0]['url']);
      $tmpl->get('portrait')->unmute();
    }
  }//show_portrait()
  
  
//eksc- may be used later, if multiple images are associated with a person
//      record   11/20/12
  function show_images($tmpl, $id, $DBConn)
  {
    $query_images = "SELECT DISTINCT(URL), CAPTION FROM WEB_IMAGE WHERE ID=" . (int) $id;
    $stmt_images = make_query($DBConn,$query_images,1);
    $arrImages = get_all_rows($stmt_images);
    
    if (count($arrImages) > 0) {
      $tmpl->get('image_loop')->loop($arrImages);
      $tmpl->get('portrait')->unmute();
    }
  }//show_images()
  

  function show_recognitions($tmpl, $id, $DBConn) {
    $sql = "SELECT * FROM ED_BOARD WHERE PERSON_ID=" . (int) $id;
    $sth = make_query($DBConn, $sql);
    $rows = get_all_rows($sth);
    $count = ($rows) ? count($rows) : 0;
    if ($count > 0) {
      $years = array();
      for ($i=0; $i<$count; $i++) {
        array_push($years, $rows[$i]['year']);
      }
      $tmpl->get('years')->replace(implode(', ', $years));
      
      $tmpl->get('ed-board')->unmute();
      $tmpl->get('recognition-star')->unmute();
    }
  }//show_recognitions()
  
  
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
   
  function read_roles($DBConn, $arrRecord)
  {
    $query_attr = "
      SELECT ATTRIBUTE FROM PERSON_ATTRIBUTE 
      WHERE ID = " . $arrRecord["ID"];
    $statement_attr = make_query($DBConn, $query_attr);
    $arrattr = get_all_rows($statement_attr);
    $count = ($arrattr) ? count($arrattr) : 0;
    
    $role_data = array();
    if ($count == 0) {
      return false;
    }//no roles
    
    else {
      if ($count == 1) {
        $heading = 'Role';
      }
      else {
        $heading = "Roles ($count)";
      }

      $roles = array();
      for ($i=0; $i<$count; $i++) {
        $query_role = "SELECT NAME from TERM where ID = " . $arrattr[$i]["attribute"];
        $statement_role = make_query($DBConn, $query_role);
        $term_row = retrieve_row($statement_role);
        array_push($roles, $term_row['name']);
      }//each attribute
      $roles = array_unique($roles);
      
      $role_data = array('heading' => $heading, 
                         'role'    => implode(', ', $roles));
      return $role_data;
    }//one or more roles
  }//read_roles()
  
?>
