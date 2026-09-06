<?php
/* file: OBO.php
 * 
 * purpose: curation page for attaching OBO terms to database objects.
 *
 *          included by curation.php
 *
 * history:
 *  07/25/12  eksc  created from code by Scott Birkett
 */
 
  include_once('./include/gp_lib.php');
  include_once('./include/curation_lib.php');
  
  // ACTION is defined in curation.php
logMessage("in OBO.php ACTION=" . ACTION);
  switch (ACTION) {
    case 'edit':
      showEditForm($mgdb);
      break;
    case 'save':
      saveRecord($mgdb);
      break;
    case 'view':
      loadView($mgdb);
      break;
    default:
      $msg = "Unknown action: [" . ACTION . "]";
      reportError($msg);
  }
  
  
  
////////////////////////////////////////////////////////////////////////////////
  function showEditForm($mgdb) {
    global $username;

    $orig_url           = getCGIParam("orig_url", 'GP', '');
    $rec_name           = getCGIParam("rec_name", 'GP', '');
    $mgdb_id            = getCGIParam("mgdb_id", 'GP', false);
    $table_name         = getCGIParam("table_name", 'GP', false);
    $gene_model_id      = getCGIParam("gene_model_id", 'GP', false);
    $gene_model_version = getCGIParam("gene_model_version", 'GP', false);
    $auto_num           = getCGIParam("auto_num", 'GP', false);
    $default_ont        = getCGIParam("default_ont", 'GP', 'GO');
logMessage("rec_name:$rec_name, mgdb_id=$mgdb_id, table_name=$table_name, gene_model_id=$gene_model_id, gene_model_version=$gene_model_version, auto_num=$auto_num, default_ont=$default_ont");

    // Load edit form
    $tmpl = $mgdb->get('body')->load('templates/curation/obo_form.bau');
    
    if ((!$mgdb_id && !$table_name) && (!$gene_model_id &&!$gene_model_version)
            && !$auto_num) {
      $error_msg  = "No auto_num or data object to annotate is not properly ";
      $error_msg .= "identified. Need an auto_num, id + table name or gene model ";
      $error_msg .= "id + gene model version.";
      $tmpl->get('error-msg')->replace($error_msg);
      $tmpl->get('error')->unmute();
      return;
    }
    
    $DBConn = connect_to_database();
    
    if (!$auto_num) {
      // New annotation
      $tmpl->get('new-annot')->unmute();
      
      $ontology_type = $default_ont;
    }
    
    else {
      // Editing this annotation record
      $tmpl->get('edit-annot')->unmute();
      $tmpl->get('auto_num')->replace($auto_num);
      
      $sql = "SELECT * FROM perm_tables.ID_ONTOLOGY WHERE AUTO_NUM = " . (int) $auto_num;
      $sth = make_query($DBConn, $sql);
      $row = retrieve_row($sth);
  
      $obo_term           = $row['obo_term'];
      $obo_name           = $row['name'];
      $ontology_type_id   = $row['ontology_tree'];
//      $ontology_domain    = $row['ONTOLOGY_DOMAIN'];
      $evidence_code      = $row['evidence_code'];
      $pmid               = $row['pmid'];
      $reference          = $row['reference'];
      $authority          = $row['authority'];
      $mgdb_id            = $row['id'];
      $table_name         = $row['table_name'];
      $gene_model_id      = $row['gene_model'];
      $gene_model_version = $row['gene_model_version'];
      $validation         = $row['validation'];
      $validation_lvl     = $row['validation_lvl'];
      $comments           = $row['comments'];
      
      $sql = "SELECT * FROM TERM WHERE ID=$ontology_type_id";
      $ot_sth = make_query($DBConn, $sql);
      $ot_row = retrieve_row($ot_sth);
      $ontology_type = $ot_row['NAME'];
      
      include_once("./record_data/curation/OBO_web_services.php");
      $obo_info        = get_obo_info($obo_term, $ontology_type);
      $obo_name        = (isset($obo_info['name'])) 
                            ? $obo_info['name'] : '';
      $obo_description = (isset($obo_info['description'])) 
                            ? $obo_info['description'] : '';
      $obo_domain      = (isset($obo_info['domain'])) 
                            ? $obo_info['domain'] : '';
    }//editing term
    
    // Get ID for person entering/editing this record, check for "super curator"
    //   ($username set in curation.php)
    $user_info = get_user_info($DBConn, $username);
    $super_curator = ($user_info['curation_lvl'] <= -5);
    
    // Data about this annotation
    $tmpl->get('annotation_author_id')->replace($user_info['annotation_author_id']);
    if ($super_curator) {
      $tmpl->get('validation')->replace($user_info['annotation_author_id']);
    }
    
    // Set OBO fields
    if (isset($obo_term))
      $tmpl->get('obo_term')->replace($obo_term);
    if (isset($obo_name)) 
      $tmpl->get('obo_name')->replace($obo_name);
    if (isset($obo_description))
      $tmpl->get('obo_description')->replace($obo_description);
    if (isset($obo_domain))
      $tmpl->get('ontology_domain_name')->replace($obo_domain);
    
    // Get supported OBO types
//HACK! Until/unless fields associated with terms for different ontologies is 
//      worked out, will have to hand-fill this list because not all are 
//      appropriate for a simple one-term-one-record association.
    $sql = "
      SELECT ot.id, ot.name 
      FROM term ot, term od
      WHERE od.name = 'Ontology Type' AND ot.type = od.id";
    $sth = make_query($DBConn, $sql);
    $ontology_types = get_all_rows($sth);
    // Set selected option, if any
    for ($i=0; $i<count($ontology_types); $i++) {
      if ($ontology_types[$i]['name'] == $ontology_type) {
        $ontology_types[$i]['selected'] = 'selected="selected"';
      }
    }
    $tmpl->get('ontology_type-options')->loop($ontology_types);

    if (isset($ontology_domain)) {
      $tmpl->get('ontology_domain')->replace($ontology_domain);
    }
    
    // Get all possible evidence codes
    $sql = "
      SELECT DISTINCT k.id, k.name, k.term_comments
      FROM term t, term k, person p, synonyms s
      WHERE t.name LIKE 'Evidence code: GO, PO' AND k.type = t.id
            AND p.name LIKE 'Gene Ontology Consortium' AND s.authority = p.id
            AND s.id = k.id
      ORDER BY name";
    $sth = make_query($DBConn, $sql);
    $evidence_codes = get_all_rows($sth);
    $codes = array();
    $descriptions = array();
    // Split record rows into 2 arrays and set selected code
    for ($i=0; $i<count($evidence_codes); $i++) {
      $new_rec = array(/*'ID'   => $evidence_codes[$i]['id'], */
                       'name' => $evidence_codes[$i]['name']);
      if (isset($evidence_code) 
          && $evidence_codes[$i]['name'] == $evidence_code) {
        $new_rec['selected'] = 'selected=selected';
      }
      array_push($codes, $new_rec);
      $new_rec = array('desc' => $evidence_codes[$i]["term_comments"],
                       'name' => $evidence_codes[$i]['name']);
      array_push($descriptions, $new_rec);
    }
    $tmpl->get('evidence_code-options')->loop($codes);
    $tmpl->get('evidence_code-descriptions')->loop($descriptions);
    
    // PubMed ID
    if (isset($pmid))
      $tmpl->get('pmid')->replace($pmid);
    
    if (isset($reference)) 
      $tmpl->get('reference')->replace($reference);
    
    // Validation level
    if (isset($validation_lvl)) {
      $check0  = ($validation_lvl == 0)  ? 'selected="selected"' : '';
      $check1  = ($validation_lvl == 1)  ? 'selected="selected"' : '';
      $check2  = ($validation_lvl == 2)  ? 'selected="selected"' : '';
      $check10 = ($validation_lvl == 10) ? 'selected="selected"' : '';
      $check99 = ($validation_lvl == 99) ? 'selected="selected"' : '';
    }
    else {
      $check0  = '';
      $check1  = '';
      $check2  = '';
      $check10 = '';
      $check99 = '';
    }
    $tmpl->get('check0')->replace($check0);
    $tmpl->get('check1')->replace($check1);
    $tmpl->get('check2')->replace($check2);
    $tmpl->get('check10')->replace($check10);
    $tmpl->get('check99')->replace($check99);

    if ($super_curator) {
      $tmpl->get('super-curator-validation-lvl')->unmute();
    }
    else {
      $tmpl->get('validation-lvl')->unmute();
    }

    // Create authority drop-down from PERSON table

    // First, check for cached HTML
    $sql = "
      SELECT per.id AS per_id, per.name AS per_name
      FROM person per
        INNER JOIN person_attribute pa ON pa.id=per.id
        INNER JOIN term ON term.id = ap.attribute
                           AND term.name IN ('Cooperator')
        INNER JOIN id_num ON id_num.id=per.id
      WHERE id_num.curation_lvl = 0
      ORDER BY UPPER(per.name)";
    $sth = make_query($DBConn, $sql);
    $all_persons = get_all_rows($sth);
    
    $authority_options = '';

    for($i=0; $i<count($all_persons); $i++) {
      if (isset($authority) && $all_persons[$i]['per_id'] == $authority) {
        $selected = 'selected';
      }
      else {
        $selected = '';
      }
  
      $authority_options .= "<option value=\"" . $all_persons[$i]['per_id'] . "\">";
      $authority_options .= $all_persons[$i]['per_name'] . "</option>";
    }

    $tmpl->get('authority-options')->replace($authority_options);

    // Comments
    if (isset($comments)) {
      $tmpl->get('comments')->replace($comments);
    }

    // If annotations exist, show option to view
    $all_obo_annotations 
         = getRecordAnnotations($DBConn, $username, $mgdb_id, $table_name, 
                                $gene_model_id, $gene_model_version);
    if ($all_obo_annotations && count($all_obo_annotations) > 0) {
      $tmpl->get('view-all')->unmute();
    }

    // Some information varies if annotating MaizeGDB data object or gene model
    if ($mgdb_id && $mgdb_id != '' && $mgdb_id != 'NULL') {
      $tmpl->get('mgdb_id')->replace($mgdb_id);
      if ($table_name)
        $tmpl->get('table_name')->replace($table_name);
      $tmpl->get('rec_name')->replace($rec_name);
      $tmpl->get('id-table')->unmute();
    }
    else {
      if ($gene_model_id) 
        $tmpl->get('gene_model_id')->replace($gene_model_id);
      if ($gene_model_version) 
        $tmpl->get('gene_model_version')->replace($gene_model_version);
      $tmpl->get('gene-model-id-version')->unmute();
      $tmpl->get('gene-model-section')->unmute();
    }
  }//loadEditForm()
  
  
  function saveRecord($mgdb) {
    global $system;
    
    $DBConn = connect_to_database();

    $auto_num           = validate_input($DBConn, getCGIParam('auto_num', 'P', false));

    $obo_term           = validate_input($DBConn, getCGIParam('obo_term', 'P', ''));
    $obo_name           = validate_input($DBConn, getCGIParam('obo_name', 'P', ''));
    $ontology_type      = validate_input($DBConn, getCGIParam('ontology_type', 'P', ''));
    $ontology_domain    = validate_input($DBConn, getCGIParam('ontology_domain', 'P', ''));
    $evidence_code      = validate_input($DBConn, getCGIParam('evidence_code', 'P', ''));
    
    $mgdb_id            = validate_input($DBConn, getCGIParam('mgdb_id', 'P', ''));
    $table_name         = validate_input($DBConn, strtolower(getCGIParam('table_name', 'P', '')));
    
    $gene_model_id      = validate_input($DBConn, getCGIParam('gene_model_id', 'P', ''));
    $gene_model_version = validate_input($DBConn, getCGIParam('gene_model_version', 'P', ''));
    
    $rec_name           = validate_input($DBConn, getCGIParam('rec_name', 'P', ''));
    
    $pmid               = validate_input($DBConn, getCGIParam('pmid', 'P', ''));
    $reference          = validate_input($DBConn, getCGIParam('reference', 'P', ''));
    $authority          = validate_input($DBConn, getCGIParam('authority', 'P', ''));
    
    $comments           = validate_input($DBConn, getCGIParam('comments', 'P', ''));
    
    $source             = validate_input($DBConn, getCGIParam('source', 'P', ''));
    $validation         = validate_input($DBConn, getCGIParam('validation', 'P', ''));
    $validation_lvl     = validate_input($DBConn, getCGIParam('validation_lvl', 'P', ''));
logMessage("mgdb_id=$mgdb_id, table_name=$table_name, gene_model_id=$gene_model_id, gene_model_version=$gene_model_version, auto_num=$auto_num");

    $tmpl = $mgdb->get('body')->load('templates/curation/obo_save.bau');
    
    // These values not allowed to be empty
    $error = '';
    if ($obo_term == '') {
      $error .= "The provided annotation ID was empty<br>";
    }
    else if ($obo_term == '' 
                || strcmp($obo_term,"GO:") == 0 || strcmp($obo_term,"PO:") == 0) {
      $error .= "The provided annotation ID was not valid<br>";
    }
    else if ($ontology_type == '') {
      $error .= "The provided ontology type was empty<br>";
    }
    else if ($evidence_code == '') {
      $error .= "The provided evidence code was empty<br>";
    }
    else if($source == '') {
      $error .= "The provided annotator id was empty<br>";
    }
    
    if ($error != '') {
      $tmpl->get('error-msg')->replace($error);
      $tmpl->get('failed')->unmute();
      return;  // bail
    }
    
    // Numeric fields allowed to be empty will be provided with a NULL string  
    //   instead of being left empty
    if ($pmid == '') {
      $pmid = "NULL";
    }
    if ($reference == '') {
      $reference = "NULL";
    }
    if ($authority == '') {
      $authority = "NULL";
    }
    if ($mgdb_id == '') {
      $mgdb_id = "NULL";
    }
    if ($validation == '') {
      $validation = "NULL";
    }
    if (!isset($memo_id) || $memo_id == '') {
      $memo_id = "NULL";
    }
    
    // Get term name if not provided; use web services.
    if ($obo_name == '') {
      include_once('./record_data/curation/OBO_web_services.php');
      $info = get_obo_info($obo_term, $ontology_type);
      if (isset($info['name'])) {
        $obo_name = $info['name'];
      }
      else {
        reportError("Inserted record for $obo_term ($auto_num) without its name");
      }
    }
    
    // Default validation level is 10 (user submited, not yet approved)
    if ($validation_lvl == '') {
      $validation_lvl = 10;
    }
    
    $edit = false;
    if ($auto_num && $auto_num > 0) {
      // Editing record
      $edit = true;
    }
    else {
      // New record
      $edit = false;
      // create auto_num
      $auto_num = get_AUTO_NUM($DBConn, "perm_tables.ID_ONTOLOGY");
      if ($auto_num == -1) {
        echo "Failed to get new AUTO_NUM\n";
        exit;
      }
    }//get auto_num for new record

    // This array will be used to create the INSERT statment
    $elem_array = array(
      'auto_num'           => $auto_num,
      'obo_term'           => "'$obo_term'",
      'name'               => "'$obo_name'",
      'ontology_type'      => $ontology_type,
      'evidence_code'      => "'$evidence_code'",
      'id'                 => $mgdb_id,
      'table_name'         => "'$table_name'",
      'gene_model_id'      => "'$gene_model_id'",
      'gene_model_version' => "'$gene_model_version'",
      'pmid'               => $pmid,
      'reference'          => $reference,
      'authority'          => $authority,
      'source'             => $source,
      'comments'           => "'$comments'",
      'validation'         => $validation,
      'validation_lvl'     => $validation_lvl,
      'memo_id'            => $memo_id,
      'mod_date'           => 'NOW()',
    );
    
    if (!$edit) {
      $elem_array['create_date'] = 'NOW()';
    }

    // Generate SQL commit string for a row edit
    if ($edit) {
      $comm_ret = edit_record($DBConn, $elem_array);
    }
    // Generate sql string for new row
    else {
      $comm_ret = new_record($DBConn, $elem_array);
    }

    if ($comm_ret) {
      $rec_label = ($gene_model_id != '') 
                 ? "$gene_model_id - $gene_model_version"
                 : "$rec_name (rec #$mgdb_id which is a $table_name)";
      $tmpl->get('rec_label')->replace($rec_label);
      
      if ($human_readable_rec = get_HR_OBO_Record($DBConn, $auto_num)) {
        $tmpl->get('obo_term')->replace($human_readable_rec['obo_term']);
        $tmpl->get('name')->replace($human_readable_rec['name']);
        $tmpl->get('obo_type')->replace($human_readable_rec['ontology_type']);
        $tmpl->get('evidence_code')->replace($human_readable_rec['evidence_code']);
        $tmpl->get('pmid')->replace($human_readable_rec['pmid']);
        $tmpl->get('reference')->replace($human_readable_rec['reference']);
        $tmpl->get('authority')->replace($human_readable_rec['authority']);
        $tmpl->get('comments')->replace($human_readable_rec['comments']);
        $tmpl->get('validation_lvl')->replace($human_readable_rec['validation_lvl']);
        
        if (isset($mgdb_id) && isset($table_name) 
            && $mgdb_id != '' && $table_name != '') {
//logMessage("attach term to $mgdb_id in $table_name");
          $tmpl->get('mgdb_id')->replace($mgdb_id);
          $tmpl->get('table_name')->replace($table_name);
          $tmpl->get('rec_type')->replace($table_name);
        }
        else {
          $tmpl->get('gene_model_id')->replace($gene_model_id);
          $tmpl->get('gene_model_version')->replace($gene_model_version);
          $rec_type = (preg_match("/_P\d\d/", $gene_model_id)) 
                    ? "gene model product" : "gene model";
//logMessage("attach term to $gene_model_id/$gene_model_version of type $rec_type");
          $tmpl->get('rec_type')->replace($rec_type);
        }
        
        $tmpl->get('rec_name')->replace($rec_name);
        $tmpl->get('auto_num')->replace($auto_num);
        
        $tmpl->get('succeeded')->unmute();
      }
      else {
        $error = "Database change failed. ";
        $error .= "Please contact the webmaster using the feedback link below.";
        $tmpl->get('error-msg')->replace($error);
        $tmpl->get('failed')->unmute();
      }
    }
    else {
      $error = "Failed to update database. ";
      $error .= "Please contact the webmaster using the feedback link below.";
      $tmpl->get('error-msg')->replace($error);
      $tmpl->get('failed')->unmute();
    }
  }//saveRecord
  
  
  function loadView($mgdb) {
    global $username;

    $mgdb_id            = getCGIParam("mgdb_id", 'GP', false);
    $table_name         = getCGIParam("table_name", 'GP', false);
    $gene_model_id      = getCGIParam("gene_model_id", 'GP', false);
    $gene_model_version = getCGIParam("gene_model_version", 'GP', false);
    $rec_name           = getCGIParam("rec_name", 'GP', false);

    // Load view records template
    $tmpl = $mgdb->get('body')->load('templates/curation/obo_view.bau');

    // Build a label identifying this record
    $rec_label = ($gene_model_id != '') 
               ? "$gene_model_id - $gene_model_version"
               : "$rec_name (rec #$mgdb_id  which is a $table_name)";
    $tmpl->get('rec_label')->replace($rec_label);
    
    $DBConn = connect_to_database();

    $all_rows = getRecordAnnotations($DBConn, $username, $mgdb_id, $table_name, 
                                     $gene_model_id, $gene_model_version);
    if (!$all_rows) {
      $tmpl->get('count')->replace('no');
    }
    else {
      // Need record name in all row forms
      for ($i=0; $i<count($all_rows); $i++) {
        $all_rows[$i]['record_name'] = $rec_name;
      }
//logVarDump($all_rows, "all OBO rows\n");
      $count = count($all_rows);
      $tmpl->get('count')->replace($count);
      $tmpl->get('obo-view-rows')->loop($all_rows);
      $tmpl->get('obo-view-rows')->unmute();
    }
    
    $tmpl->get('gene_model_id')->replace($gene_model_id);
    $tmpl->get('gene_model_version')->replace($gene_model_version);
    $tmpl->get('mgdb_id')->replace($mgdb_id);
    $tmpl->get('table_name')->replace($table_name);
    $tmpl->get('rec_name')->replace($rec_name);
  }//loadView()
  
  
  function getRecordAnnotations($DBConn, $username, $mgdb_id, $table_name, 
                                $gene_model_id, $gene_model_version) {
logMessage("Get annotations for id=$mgdb_id/table=$table_name OR gm=$gene_model_id/version=$gene_model_version");
    if (!$mgdb_id && !$table_name && !$gene_model_id && !$gene_model_version) {
      return false;
    }
    
    // WHERE clauses go here:
    $clauses = array();
    
    // If not super curator, only this author's annotations are visible.
    $user_info = get_user_info($DBConn, $username);
    if ($user_info['curation_lvl'] > -5) {
      $author_id = $user_info['annotation_author_id'];
      array_push($clauses, "source = '$author_id'");
    }
    
    // If id/table or gene_model_id/gene_model_version given, show only 
    //    annotations for this record
    if ($mgdb_id != '' && $table_name != '') {
      array_push($clauses, "o.id=$mgdb_id");
      array_push($clauses, "table_name='$table_name'");
    }
    else if ($gene_model_id != '' && $gene_model_version != '') {
      array_push($clauses, "gene_model_name='$gene_model_id'");
      array_push($clauses, "gen_model_version='$gene_model_version'");
    }
  
    $where = (count($clauses) > 0) ? 'WHERE ' . (implode(' AND ', $clauses)) : '';
    $sql = "
      SELECT auto_num, obo_term, name, source, s.first_name AS source, 
             v.first_name AS validator, validation_lvl, 
             DATE_TRUNC('minute', create_date) AS create_date, 
             DATE_TRUNC('minute', mod_date) AS mode_date
      FROM perm_tables.id_ontology o
             JOIN annotation_author s ON s.id=o.source
             LEFT JOIN annotation_author v ON v.id = 0.validation
      $where" ;
logMessage("annotation sql\n$sql");
    $sth = make_query($DBConn, $sql);
    return get_all_rows($sth);
  }//getRecordAnnotations
  
  
  function edit_record($DBConn, $elem_array) {
    // Only set audit trail record for a MaizeGDB data object
    $set_audit_trail = false;
    if ($elem_array['id'] && $elem_array['id'] != 'NULL') {
      $set_audit_trail = true;
      $id = $elem_array['id'];
      $audit_ret = getAuditTrailRecord($DBConn, $id, $elem_array['source']);
    }
    
    $sql = "
      SELECT * 
      FROM perm_tables.ID_ONTOLOGY 
      WHERE AUTO_NUM = '" . $elem_array['AUTO_NUM'] . "'";
    $sth = make_query($DBConn, $sql);
    $orig_array = retrieve_row($sth);
  
    $sql_update = "UPDATE perm_tables.id_ontology SET ";
    foreach ($elem_array as $key => $value) {
      $sql_update .= "$key=$value,";
      $orig_value = $orig_array[$key];
      $no_changes = ($set_audit_trail) 
                        ? compare_records($orig_value, $value) : true;
   
      // Record field change in database table AUDIT_FIELDS 
      // Multiple fields can change with each update, so they are done this way
      // to keep track of them individually 
      if (!$no_changes) {
        set_audit_field($DBConn, $audit_ret, $key, $orig_value, $value, 'id_ontology');
      }// field change   
    }// foreach elem_array
  
    $sql_update = preg_replace('/(.*),/','$1', $sql_update);
    $sql_update .= " WHERE auto_num = " . $elem_array['auto_num'];
    $comm_ret = make_query($DBConn, $sql_update);
  
       // send alert since annotations can be set in multiple db instances.
    $subject = '[ANNOTATION] ontology term updated';
    $message = "The Ontology Term " . $elem_array['obo_term']. " (auto_num: " . $elem_array["auto_num"] . ")";
    $message .= " has been updated for ". $elem_array['gene_model_id'] . " by annotation_author ";
    $message .= $elem_array['ANN_AUTHOR_ID'];
    $message .= " on database instance " . $db['DB_HOST'];
    $emails = explode(",", $system['annotation_email']);
    foreach ($emails as $email) {
      send_email($email, 'admin@maizegdb.org', $subject, $message);
      logMessage("Sent new record alert email to ".$email.":\n$message");
    }
  
    return $comm_ret;
  }//edit_record


  function new_record($DBConn, $elem_array) {
    // Build insert statement
    $keys = array();
    $values = array();
    foreach ($elem_array as $key => $value) {
      array_push($keys, $key);
      array_push($values, $value);
    }
    $sql_insert = "
      INSERT INTO perm_tables.id_ontology 
        (" . (implode(',', $keys)) . ") 
      VALUES 
        (" . (implode(',', $values)) . ")";
    $comm_ret = make_query($DBConn, $sql_insert);
  
    // Audit trail only if attached to a MGDB object
    if ($comm_ret && $elem_array['ID'] != '' & $elem_array['id'] != 'NULL') {
      $audit_ret = getAuditTrailRecord($DBConn, $elem_array['id'], 
                                       $elem_array['source'], 'new record');
    }
    
    // send alert since annotations can be set in multiple db instances.
    $subject = '[ANNOTATION] ontology term added';
    $message = "The Ontology Term " . $elem_array['obo_term']. " (auto_num: " . $elem_array["auto_num"] . ")";
    $message .= " has been added to ". $elem_array['gene_model_id'] . " by annotation_author ";
    $message .= $elem_array['ANN_AUTHOR_ID'];
    $message .= " on database instance " . $db['DB_HOST'];
    $emails = explode(",", $system['annotation_email']);
    foreach ($emails as $email) {
      send_email($email, 'admin@maizegdb.org', $subject, $message);
      logMessage("Sent new record alert email to ".$email.":\n$message");
    }
    
    return $comm_ret;
  }//new_record
  
  
  // Get a human readable ontology record, with fk's converted to strings
  function get_HR_OBO_Record($DBConn, $auto_num) {
    $ontology_type   = '';
    $ontology_domain = '';
    $evidence_code   = '';
    $authority       = '';
    
    $sql = "SELECT * FROM perm_tables.id_ontology WHERE auto_num=" . (int) $auto_num;
    $sth = make_query($DBConn, $sql);
    if ($row = retrieve_row($sth)) {
      $sql = "SELECT * FROM term WHERE id=" . $row['ontology_type'];
      $ot_sth = make_query($DBConn, $sql);
      if ($ot_row = retrieve_row($ot_sth)) {
        $ontology_type = $ot_row['name'];
      }
      
      if ($row['authority']) {
        $sql = "SELECT * FROM person WHERE id=" . $row['authority'];
        $au_sth = make_query($DBConn, $sql);
        if ($au_row = retrieve_row($au_sth)) {
          $authority = $au_row['name_first'] . ' ' . $au_row['name_last'];
        }
      }
      
      // Turn validation # into name
      switch ($row['validation_lvl']) {
        case 0:
          $validation_lvl = 'Approved';
          break;
        case 1:
          $validation_lvl = 'Automatic update';
          break;
        case 2:
          $validation_lvl = 'Possible error';
          break;
        case 10:
          $validation_lvl = 'Waiting approval';
          break;
        case 99:
          $validation_lvl = 'Trash';
      }
      
      return array(
               'obo_term'        => $row['obo_term'],
               'name'            => $row['name'],
               'ontology_type'   => $ontology_type,
               'ontology_domain' => $ontology_domain,
               'evidence_code'   => $row['evidence_code'],
               'pmid'            => $row['pmid'],
               'reference'       => $row['reference'],
               'authority'       => $authority,
               'comments'        => $row['comments'],
               'validation_lvl'  => $validation_lvl,
      );
    }
    else {
      return false;
    }
  }//get_HR_OBO_Record
?>

