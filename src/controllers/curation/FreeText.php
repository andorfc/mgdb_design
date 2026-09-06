<?php
/* file: FreeText.php
 * 
 * purpose: curation page for attaching free-text annotation to database objects.
 *
 *          included by curation.php
 *
 * history:
 *  08/01/12  eksc  created from old MaizeGDB script create_annotation.cgi
 */
 
  include_once('./include/gp_lib.php');
  include_once('./include/curation_lib.php');
  include_once('./include/mail.php');
  
  // Get system configuration
  $system = getSystemInfo('mgdb.conf');
  $db     = getSystemInfo('db.conf');

  // ACTION is defined in curation.php
logMessage("Free text ACTION=" . ACTION);

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
  
logMessage("FreeText.php completed.");


///////////////////////////////////////////////////////////////////////////////
///////////////////////////////////////////////////////////////////////////////
///////////////////////////////////////////////////////////////////////////////

function showEditForm($mgdb) {
  global $username;

  $orig_url           = getCGIParam('orig_url', 'GP', '');
  $rec_name           = getCGIParam('rec_name', 'GP', '');
  $mgdb_id            = getCGIParam('mgdb_id', 'GP', false);
  $table_name         = getCGIParam('table_name', 'GP', false);
  $gene_model_id      = getCGIParam('gene_model_id', 'GP', false);
  $auto_num           = getCGIParam('auto_num', 'GP', false);

  // Don't know why only POSTed gene model versions need decoding, 
  //   but this seems to work where always decoding doesn't:
  $gene_model_version = getCGIParam('gene_model_version', 'G', 
                          urldecode(getCGIParam('gene_model_version', 'P', false)));
  
  // Load edit form
  $tmpl = $mgdb->get('body')->load('templates/curation/freetext_form.bau');

  if (!$mgdb_id && !$gene_model_id) {
    $error_msg = "Record to annotate is not identified";
    reportError($error_msg);
    $tmpl->get('error-msg')->replace($error_msg);
    $tmpl->get('error')->unmute();
    return;
  }
  
  if ((!$mgdb_id && !$table_name) 
          && (!$gene_model_id &&!$gene_model_version)
          && !$auto_num) {
    $error_msg  = "No auto_num or data object to annotate is not properly ";
    $error_msg .= "identified. Need an auto_num, id + table name or gene model ";
    $error_msg .= "id + gene model version.";
    $tmpl->get('error-msg')->replace($error_msg);
    $tmpl->get('error')->unmute();
    return;
  }
  
  $DBConn = connect_to_database(false);  // false: don't cache
  
  if (!$auto_num) {
    // New annotation
    $tmpl->get('new-annot')->unmute();
    $memo          = '';
    $curation_lvl  = '';
    $ann_author_id = '';
  }
  
  else {
    // Editing this annotation record
    $tmpl->get('edit-annot')->unmute();
    
    $sql = "SELECT * FROM ANNOTATION WHERE AUTO_NUM=$auto_num";
    $sth = make_query($DBConn, $sql);
    $row = retrieve_row($sth);
    
    $memo          = htmlspecialchars($row['memo'], ENT_QUOTES, 'UTF-8');
    $curation_lvl  = $row['curation_lvl'];
    $ann_author_id = $row['ann_author_id'];
  }

  // Get ID for person entering/editing this record, check for "super curator"
  //   ($username set in curation.php)
  $user_info = get_user_info($DBConn, $username);
  $super_curator = ($user_info['curation_lvl'] <= -5);
  
  // Some information varies if annotating MaizeGDB data object or gene model
  if ($mgdb_id && $mgdb_id != 'NULL') {
    $tmpl->get('mgdb_id')->replace($mgdb_id);
    if ($table_name)
      $tmpl->get('table_name')->replace($table_name);
    $tmpl->get('rec_name')->replace($rec_name);
    $tmpl->get('id-table')->unmute();
  }
  else {
    if ($gene_model_id) {
      $tmpl->get('gene_model_id')->replace($gene_model_id);
    }
    if ($gene_model_version) {
      $tmpl->get('gene_model_version')->replace($gene_model_version);
      $tmpl->get('gene_model_version_encoded')->replace(urlencode($gene_model_version));
    }
    $tmpl->get('gene-model-id-version')->unmute();
  }
  
  // Data about this annotation
  if ($auto_num)
    $tmpl->get('auto_num')->replace($auto_num);
  if ($memo)
    $tmpl->get('memo')->replace($memo);
  if ($ann_author_id)
    $tmpl->get('annotation_author_id')->replace($ann_author_id);
  else
    // Information about this user
    $tmpl->get('annotation_author_id')->replace($user_info['annotation_author_id']);
  
  
  // Curation level
  $check0  = ($curation_lvl == 0)  ? 'selected="selected"' : '';
  $check2  = ($curation_lvl == 2)  ? 'selected="selected"' : '';
  $check10 = ($curation_lvl == 10) ? 'selected="selected"' : '';
  $check99 = ($curation_lvl == 99) ? 'selected="selected"' : '';
  $tmpl->get('check0')->replace($check0);
  $tmpl->get('check2')->replace($check2);
  $tmpl->get('check10')->replace($check10);
  $tmpl->get('check99')->replace($check99);
  if ($super_curator) {
    $tmpl->get('super-curator-validation-lvl')->unmute();
  }
  else {
    $tmpl->get('validation-lvl')->unmute();
  }

  // If there are other annotations, show option to view all
  $all_annotations 
       = getRecordAnnotations($DBConn, $username, $mgdb_id, $table_name, 
                              $gene_model_id, $gene_model_version);
  if ($all_annotations && count($all_annotations) > 0) {
    $tmpl->get('view-all')->unmute();
  }
  
  $tmpl->get('edit-form')->unmute();
}//showEditForm()


function saveRecord($mgdb) {
  $auto_num           = getCGIParam('auto_num', 'P', false);
  
  $mgdb_id            = addslashes(getCGIParam('mgdb_id', 'P', ''));
  $table_name         = addslashes(getCGIParam('table_name', 'P', ''));
  
  $gene_model_id      = addslashes(getCGIParam('gene_model_id', 'P', ''));
  $gene_model_version = urldecode(getCGIParam('gene_model_version', 'P', ''));
  
  $ann_author_id      = addslashes(getCGIParam('ann_author_id', 'P', ''));

  $memo               = addslashes(getCGIParam('memo', 'P', ''));
  $curation_lvl       = addslashes(getCGIParam('curation_lvl', 'P', ''));
  
  $DBConn = connect_to_database(false);

  // freetext-saved template
  $tmpl = $mgdb->get('body')->load('templates/curation/freetext_save.bau');
  $tmpl->get('mgdb_id')->replace($mgdb_id);
  $tmpl->get('table_name')->replace($table_name);
  $tmpl->get('gene_model_id')->replace($gene_model_id);
  $tmpl->get('gene_model_version_encoded')->replace(urlencode($gene_model_version));

  // Numeric fields allowed to be empty will be provided with a NULL string  
  //   instead of being left empty
  if ($mgdb_id == '') {
    $mgdb_id    = 'NULL';
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
    $auto_num = get_AUTO_NUM($DBConn, 'annotation');
    if ($auto_num == -1) {
      echo "Failed to get new AUTO_NUM\n";
      exit;
    }
  }//get auto_num for new record
  
  // Make sure these are safe and clean
  $memo               = validate_input($DBConn, $memo);
  $gene_model_id      = validate_input($DBConn, $gene_model_id);
  $gene_model_version = validate_input($DBConn, $gene_model_version);

  // Get author e-mail
  $sql = "SELECT email FROM annotation_author WHERE id=$ann_author_id";
  $author_sth = make_query($DBConn, $sql);
  $author_row = retrieve_row($author_sth);
  $author_email = $author_row['email'];

  // This array will be used to create the INSERT statment
  $elem_array = array(
    'auto_num'           => validate_input($DBConn, $auto_num),
    'id'                 => validate_input($DBConn, $mgdb_id),
    'gene_model_id'      => "'$gene_model_id'",
    'gene_model_version' => "'$gene_model_version'",
    'author_email'       => "'$author_email'",
    'ann_author_id'      => validate_input($DBConn, $ann_author_id),
    'memo'               => "'$memo'",
    'curation_lvl'       => validate_input($DBConn, $curation_lvl),
    'mod_date'           => 'NOW()',
  );
  
  if (!$edit) {
    $elem_array['add_date'] = 'NOW()';
  }
  
  // Generate SQL commit string for a row edit
  if ($edit) {
    $comm_ret = edit_record($DBConn, $elem_array, $tmpl);
  }
  // Generate sql string for new row
  else {
    $comm_ret = new_record($DBConn, $elem_array, $tmpl);
  }

// Can't do this test with this object...  
//  if ($comm_ret) {
    $rec_label = ($gene_model_id != '') 
               ? "$gene_model_id - $gene_model_version"
               : "$mgdb_id ($table_name)";
    $tmpl->get('rec_label')->replace($rec_label);
      
    if ($human_readable_rec = get_HR_Annotation_Record($DBConn, $auto_num)) {
      if ($mgdb_id && $mgdb_id != '') {
        $tmpl->get('mgdb_id')->replace($mgdb_id);
        $tmpl->get('table_name')->replace($table_name);
      }
      else {
        $tmpl->get('gene_model_id')->replace($gene_model_id);
        $tmpl->get('gene_model_version')->replace(urlencode($gene_model_version));
      }
      $tmpl->get('author_name')->replace($human_readable_rec['author']);
      $tmpl->get('author_email')->replace($human_readable_rec['author_email']);
      $tmpl->get('comment')->replace($human_readable_rec['comment']);
    }
    else {
      logMessage("FreeText update failed for ($mgdb_id, $table_name) or ($gene_model_id, $gene_model_version)");
      $error  = "Database change failed. ";
      $error .= "Please contact the webmaster using the feedback link below.";
      $tmpl->get('error-msg')->replace($error);
      $tmpl->get('failed')->unmute();
      return;
    }
//  }
// Have to assume it worked...
//  else {
//    logMessage("FreeText insert failed for ($mgdb_id, $table_name) or ($gene_model_id, $gene_model_version)");
//    $error  = "Failed to update database. ";
//    $error .= $tmpl->get('error-msg')->value(); // in case a message was set earlier
//    $error .= " Please contact the webmaster using the feedback link below.";
//    $tmpl->get('error-msg')->replace($error);
//    $tmpl->get('failed')->unmute();
//    return;
//  }

  logMessage("Saved FreeText insert/update for ($mgdb_id, $table_name) or ($gene_model_id, $gene_model_version)");
  $tmpl->get('succeeded')->unmute();
}//saveRecord()


function loadView($mgdb) {
  global $username;
  
  $mgdb_id            = getCGIParam("mgdb_id", 'GP', false);
  $table_name         = getCGIParam("table_name", 'GP', false);
  $gene_model_id      = getCGIParam("gene_model_id", 'GP', false);
  $gene_model_version = urldecode(getCGIParam("gene_model_version", 'GP', false));
//logMessage("for view, gene model version: $gene_model_version");

  // Load view records template
  $tmpl = $mgdb->get('body')->load('templates/curation/freetext_view.bau');
  $tmpl->get('gene_model_id')->replace($gene_model_id);
  $tmpl->get('gene_model_version_encoded')->replace(urlencode($gene_model_version));
  $tmpl->get('mgdb_id')->replace($mgdb_id);
  $tmpl->get('table_name')->replace($table_name);

  // Build a label identifying this record
  $rec_label = ($gene_model_id != '') 
             ? "$gene_model_id - $gene_model_version"
             : "$mgdb_id ($table_name)";
  $tmpl->get('rec_label')->replace($rec_label);
  
  $DBConn = connect_to_database(false);

  $all_rows = getRecordAnnotations($DBConn, $username, $mgdb_id, $table_name, 
                                   $gene_model_id, $gene_model_version);
logMessage("Found " . count($all_rows) . " annotations\n");
  if (!$all_rows) {
    $tmpl->get('count')->replace('no');
  }
  else {
    $count = count($all_rows);
    $tmpl->get('count')->replace($count);
    $tmpl->get('freetext-view-rows')->loop($all_rows);
    $tmpl->get('freetext-view-rows')->unmute();
  }
}//loadView()


function edit_record($DBConn, $elem_array, $tmpl) {
  global $system, $db;
  
  $audit_ret = false;
  
  // Only set audit trail record for a MaizeGDB data object
  if ($elem_array['id'] != 'NULL') {
    $id = $elem_array['id'];
    $audit_ret = getAuditTrailRecord($DBConn, $id, $elem_array['ann_author_id']);
  }
  
  $sql = "
    SELECT * 
    FROM annotation 
    WHERE auto_num = '" . $elem_array['auto_num'] . "'";
  $sth = make_query($DBConn, $sql);
  $orig_array = retrieve_row($sth);

  $sets = array();
  foreach ($elem_array as $key => $value) {
    $sets[] = "
      $key=$value";
    $orig_value = $orig_array[$key];
    $compare_res = compare_records($orig_value, $value);
    // Record field change in database table AUDIT_FIELDS 
    // Multiple fields can change with each update, so they are done this way
    // to keep track of them individually 
    if (!$compare_res && $audit_ret) {
      set_audit_field($DBConn, $audit_ret, $key, $orig_value, $value, 'annotation');
    }// field change   
  }// foreach elem_array

  $sql_update = "
    UPDATE annotation 
    SET "
      . implode(',', $sets) . "
    WHERE auto_num = " . $elem_array['auto_num'];
  $comm_ret = make_query($DBConn, $sql_update);

  // send alert since annotations can be set in multiple db instances.
  $subject = '[ANNOTATION] annotation edited';
  $message = "Annotation " . $elem_array['auto_num'];
  $message .= " has been changed by annotation_author ";
  $message .= $elem_array['ann_author_id'];
  $message .= " on database instance " . $db['DB_HOST'];
  $message .= " The annotation is:\n\t " . $elem_array['memo'];
  $emails = explode(",", $system['annotation_email']);
  foreach ($emails as $email) {
    send_email($email, 'admin@maizegdb.org', $subject, $message);
    logMessage("Sent new record alert email to ".$email.":\n$message");
  }
  
  return $comm_ret;
}//edit_record()


function new_record($DBConn, $elem_array, $tmpl) {
  global $system, $db;
  
  $error = false;
  
  // Some sanity checks:
  if (!array_key_exists('ann_author_id', $elem_array)
      && !array_key_exists('ann_author_id', $elem_array)) {
    $error = 'You are not properly logged in. Please log out and log in again.';
  }
  if ($error) {
    $tmpl->get('error-msg')->replace($error);
    $tmpl->get('failed')->unmute();
    return false;
  }

  // Start arrays for insert statement
  $keys = array();
  $values = array();
  
  // Build insert statement
  foreach ($elem_array as $key => $value) {
    // special check for curation_lvl, which may be 0
    if (($value && $value != '') || $key == 'curation_lvl') {
      array_push($keys, $key);
      array_push($values, $value);
    }
  }

  $sql_insert = "
    INSERT INTO annotation 
      (" . (implode(',', $keys)) . ") 
    VALUES 
      (" . (implode(',', $values)) . ")";
  $comm_ret = make_query($DBConn, $sql_insert);
/*it doesn't appear to be possible to check the return value reliably!
  if ($comm_ret === false) {
    reportError("Failed to insert free text annotation.");
  }
  else {
    logMessage("Inserted free text annotation with return value: $comm_ret.");
  }
*/  


  // Assume it worked ...
  
  // send alert since annotations can be set in multiple db instances.
  $auto_num = $elem_array['auto_num'];
  $subject = '[annotation] annotation added';
  $message = "Annotation $auto_num has been added by annotation_author ";
  $message .= $elem_array['ann_author_id'];
  $message .= " on database instance " . $db['DB_HOST'] . ".";
  $message .= " The annotation is:\n\t " . $elem_array['memo'];
  $emails = explode(",", $system['annotation_email']);
  foreach ($emails as $email) {
    send_email($email, 'admin@maizegdb.org', $subject, $message);
    logMessage("Sent new record alert email to ".$email.":\n$message");
  }


  return $comm_ret;
}//new_record()


function getRecordAnnotations($DBConn, $username, $mgdb_id, $table_name, 
                                   $gene_model_id, $gene_model_version) {
    // WHERE clauses go here:
    $clauses = array();
    
    // If not super curator, only this author's annotations are visible.
    $user_info = get_user_info($DBConn, $username);
    if ($user_info['curation_lvl'] > -5) {
      $author_id = $user_info['annotation_author_id'];
      array_push($clauses, "ann_author_id = '$author_id'");
    }
    
    // If id/table or gene_model_id/gene_model_version given, show only 
    //    annotations for this record
    if ($gene_model_id != '' && $gene_model_version != '') {
      array_push($clauses, "gene_model_id='$gene_model_id'");
      array_push($clauses, "gene_model_version='$gene_model_version'");
    }
    else {
      array_push($clauses, "a.id=$mgdb_id");
    }
  
    $where = (count($clauses) > 0) ? 'WHERE ' . (implode(' AND ', $clauses)) : '';
    $sql = "
      SELECT DISTINCT auto_num, author_email, memo, aa.first_name AS author,
             a.curation_lvl,
             DATE_TRUNC('minute', add_date) AS create_date,
             DATE_TRUNC('minute', mod_date) AS mod_date
      FROM annotation a
             JOIN annotation_author aa ON aa.id=a.ann_author_id
      $where";
    $sth = make_query($DBConn, $sql);
    return get_all_rows($sth);
}//getRecordAnnotations()


// Get a human readable annotation record, with fk's converted to strings
function get_HR_Annotation_Record($DBConn, $auto_num) {
  $sql = "
    SELECT a.*, aa.first_name, aa.last_name 
    FROM annotation a, annotation_author aa 
    WHERE auto_num=$auto_num AND a.ann_author_id=aa.id";
  $sth = make_query($DBConn, $sql);
  if ($row = retrieve_row($sth)) {
logMessage("Found annotation record");
    // Turn curation_lvl # into name
    switch ($row['curation_lvl']) {
      case 0:
        $curation_lvl = 'Approved';
        break;
      case 2:
        $curation_lvl = 'Possible error';
        break;
      case 10:
        $curation_lvl = 'Waiting approval';
        break;
      case 99:
        $curation_lvl = 'Trash';
    }//switch
    
    return array(
             'author'       => $row['first_name'] . ' ' . $row['last_name'],
             'author_email' => $row['author_email'],
             'comment'      => mgdb_safe_html($row['memo']),
             'curation_lvl' => $curation_lvl,
    );
  }//found record
  else {
logMessage("DID NOT find annotation record");
    return false;
  }
}//get_HR_Annotation_Record
