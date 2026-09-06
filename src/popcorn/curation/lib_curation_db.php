<?php
/*
 * file: lib_curation_db.php
 *
 * purpose: functions for POPcorn curation.
 *
 * history:
 *  06/09/09  eksc  created
 *  01/25/10  eksc  modifed to fit combined code base
 */
 
  include_once("../inc/lib.php");
  include_once("../inc/lib_db.php");
  include_once('curation_defs.php');

  /**
   * captureChildChanges
   * 
   * Capture and record all changes to child records (e.g. association tables).
   * 
   * @param $conn
   * @param $table
   * @param $id
   * @param $cur_recs
   * @param $new_recs
   * @param $auditTrailID
   * @return none
   */
  function captureChildChanges(&$conn, $table, $id, $cur_recs, $new_recs, $auditTrailID) {
//echo "Update child records for parent id $id<br>";
//echo "CURRENT: <pre>";var_dump($cur_recs);echo "</pre>";
//echo "NEW: <pre>";var_dump($new_recs);echo "</pre>";
    $old_recs = $cur_recs;
    
    // Get modified and new association records
    $changes = array();
    foreach ($new_recs as $new_rec) {
      $is_new = true;
      $old_idx = 0;
      foreach ($old_recs as $old_rec) {
        if ($new_rec[count($new_rec)-1] == $old_rec[count($old_rec)-1]) {
          // Found matching record in old record array
          $is_new = false;
          // Check for modifications
          $is_modified = false;
          for ($j=0; $j<count($new_rec)-1; $j++) {
            if ($new_rec[$j] != $old_rec[$j]) {
              // Modified
              // push type, field #, old value onto change array for this rec
              //   note: dangerously assumes field order is maintained in array
              array_push($changes, 
                         array_merge(array('M', $j, $old_rec[$j]), $new_rec));
              $is_modified = true;
            }//field changed
          }//check each field in record
          if (!$is_modified) {
            // Unchanged
            array_push($changes, array_merge(array('U'), $new_rec));
          }
          
          // Remove this record from old set
          array_splice($old_recs, $old_idx, 1);
          break;
        }//found matching record
        else {
          $old_idx++;
        }
      }//foreach old record
      if ($is_new) {
        // Added
        array_push($changes, array_merge(array('A'), $new_rec));
      }
    }//foreach new record
    
    // records left in old_recs will be deleted
    foreach ($old_recs as $old_rec) {
      // Deleted
      array_push($changes, array_merge(array('D'), $old_rec));
    }
//echo "CHANGED: <pre>";var_dump($changes);echo "</pre>";

    // record changes
    foreach ($changes as $change) {
      $auto_num = getAutoNum($conn, 'AUDIT_FIELDS');
      $change_type = $change[0];
      $old_value = '';
      $new_value = '';
      if ($change_type == 'D') {
        // Record was deleted
        $sql = "INSERT INTO audit_fields
                  (auto_num, audit_trail_id, change_type, field_name, 
                   table_name, record_id)
                VALUES
                  (:auto_num, :auditTrailID, 'D', 'n/a', :table_name, :id)";
      }//deleted
      if ($change_type == 'A') {
        // Record was added
        $sql = "INSERT INTO audit_fields
                  (auto_num, audit_trail_id, change_type, field_name, 
                   table_name, record_id)
                VALUES
                  (:auto_num, :auditTrailID, 'A', 'n/a', :table_name, :id)";
      }//added
      if ($change_type == 'M') {
        // Record was modified
        // Get column name; first three table cols ignored in this instance
        $field_name = getFieldName($conn, $change[1]+3, $table);
        $old_value = $change[2];
        $new_value = $change[$change[1]+3]; // skip 3 appended elements
        $sql = "INSERT INTO audit_fields
                  (auto_num, audit_trail_id, change_type, field_name, 
                   table_name, current_value, old_value, record_id)
                VALUES
                  (:auto_num, :auditTrailID, 'A', 'n/a', :table_name, 
                   :old_value, :new_value, :id)";
      }//modified
      
      if (isset($sql)) {
        $res = doMod_oracle($conn, $sql,
         array(
         ":auto_num" => $auto_num,
         ":auditTrailID" => $auditTrailID,
         ":table_name" => $table,
         ":old_value" => $old_value,
         ":new_value" => $new_value,
         ":id" => $id
         )
        );
        if (!$res) {
          $msg = "captureChildChanges(): ";
          $msg .= "unable to complete insert statement:\n$sql";
          reportErrorPC("$msg\n");
        }
      }
    }//all changes
  }//captureChildChanges()
  
  
  /**
   * captureCurationLevelChange
   * 
   * Record curation level change.
   * 
   * @param $conn
   * @param $id
   * @param $old_level
   * @param $new_level
   * @param $table
   * @param $auditTrailID
   * @return none
   */
  function captureCurationLevelChange($conn, $id, $old_level, $new_level, $table, $auditTrailID) {
    if ($old_level != $new_level) {
      saveAuditFieldRecord($conn, $auditTrailID, 'CURATION_LVL', $table, 
                           $old_level, $new_level, 'M');
    }
  }//captureCurationLevelChange()
  
  
  /**
   * captureParentChanges
   * 
   * Capture and record all changes to a parent record.
   *
   * @param $conn
   * @param $table
   * @param $id
   * @param $new_rec
   * @param $auditTrailID
   * @return none
   */
  function captureParentChanges($conn, $table, $id, $new_rec, $auditTrailID) {
logMessagePC("captureParentChanges() started");
    global $pfields, $rfields, $sfields, $bfields;
  
    // Get existing record
    if ($table == 'PC_PROJECT') {
       $old_rec = getProject($id);
       $fields = $pfields;
    }
    else if ($table == 'PC_RESOURCE') {
logMessagePC("captureParentChanges() go get resource record");
       $old_rec = getResource($id);
       $fields = $rfields;
    }
    else if ($table == 'PC_SEARCH_CTL') {
       $old_rec = getSearch($id);
       $fields = $sfields;
    }
    else if ($table == 'PC_BLAST_CTL') {
       $old_rec = getBlast($id);
       $fields = $bfields;
    }
    else {
       echo "<br><b>captureParentChanges(): $table not suported</b><br>";
       return;
    }
    
logMessagePC("captureParentChanges() check each field");
    // Get fields that changed and insert audit_field record for each
    foreach (array_keys($fields) as $field) {
      if ($old_rec[$field] != $new_rec[$field] 
            && $fields[$field]['dbfield'] != 'n/a'
            && $fields[$field]['dbfield'] != '') {
logMessagePC("captureParentChanges() save audit field record");
        saveAuditFieldRecord($conn, $auditTrailID, $fields[$field]['dbfield'], 
                             $table, $old_rec[$field], $new_rec[$field], 'M');
      }//field changed
    }//foreach
logMessagePC("captureParentChanges() finished");
  }//captureParentChanges()
  
  
  /**
   * changeCategoryLevel
   * 
   * @param $id
   * @param $old_level
   * @param $new_level
   * @return none
   */
  function changeCategoryLevel($id, $old_level, $new_level) {
    $conn = connectToCurationDB();
    
    if ($old_level != $new_level) {
      // Record level change in audit trail
      $annotatorID = intval($_SESSION['annotatorID']);
//logMessagePC("changeCategoryLevel(): get audit trail record for 'POPcorn Category', $id, $annotatorID");
      $auditTrailID = getAuditTrailRecord($conn, 'POPcorn Category', $id, 
                                          $annotatorID);
      captureCurationLevelChange($conn, $id, $old_level, $new_level, 
                                 'PC_CATEGORY', $auditTrailID);
      
      // Update curation level
      $sql = "UPDATE id_num SET curation_lvl=$new_level WHERE ID=$id";
      doMod_oracle($conn, $sql,array());
    }
    
    disconnectFromCurationDB($conn);
  }//changeCategoryLevel()
  
  
  /**
   * changeCurationLvls
   * 
   * Change the curation level for a set of records.
   *
   * @todo - is it possible to ensure all ids are at least numbers?
   * 
   * @param $ids
   * @param $new_level
   * @param $comment
   * @return none
   */
  function changeCurationLvls($ids, $new_level, $comment) {
//logMessagePC("changeCurationLvls(): ids: $ids, new_level: $new_levels, comment: $comment");
    $conn = connectToCurationDB();

    // Check id array
    if (count($ids) == 0) {
      return;
    }

    // Assume all ids are of the same type; get the type
    $type = getRecordType($ids[0], $conn);

    // Could do this all in one SQL statement, but easier to track changes in
    //   AUDIT tables by doing them one by one:
    foreach ($ids as $id) {
      $old_level = getRecordCurationLvl($id, $conn);
      if (is_numeric($id) && $id > -1) {
        changeRecordLevel($id, $old_level, $new_level, $comment, $conn);
      }
    }
    
    disconnectFromCurationDB($conn);
  }//changeCurationLvls()
  
  
  /**
   * changeRecordLevel
   * 
   * Change the curation level for one record.
   * 
   * @param $id
   * @param $old_level
   * @param $new_level
   * @param $comment
   * @return none
   */
  function changeRecordLevel($id, $old_level, $new_level, $comment) {
//echo("changeRecordLevel($id, $old_level, $new_level, $comment)");
//logMessagePC("changeRecordLevel(id=$id, old_level=$old_level, new_level=$new_level, comment=$comment)");
    $conn = connectToCurationDB();
    
    $type = getRecordType($id, $conn);
    $tabletype = ($type == 'POPcorn Resource') ? 'Resource' : 'Project';
    
    if ($comment != '') {
      // Update record notes with comment
      $notes = getRecordNotes($conn, $id, $tabletype);
      $notes .= "\n$comment";
      setRecordNotes($conn, $id, $tabletype, $notes);
    }

    if ($old_level != $new_level) {
      // Record level change in audit trail
      $table = ($type == 'POPcorn Project') ? 'PC_PROJECT' : 'PC_RESOURCE';
      $annotatorID = $_SESSION['annotatorID'];
//logMessagePC("changeRecordLevel(): get audit trail record for $type, $id, $annotatorID");
      $auditTrailID = getAuditTrailRecord($conn, $type, $id, 
                                          $annotatorID);
      captureCurationLevelChange($conn, $id, $old_level, $new_level, 
                                 $table, $auditTrailID);
      
    
      // Update curation level in ID_NUM
      $sql = "UPDATE id_num 
              SET curation_lvl=$new_level, curation_lvl_change=SYSTIMESTAMP
              WHERE id=$id";
      doMod_oracle($conn, $sql, array());
    }
    
    disconnectFromCurationDB($conn);
  }//changeRecordLevel
  
/*don't do this
  function changeOwnership($ids, $type, $new_owner) {
    $conn = connectToCurationDB();
    
    foreach ($ids as $id) {
      if (is_numeric($id) && $id > 0) {
        $old_owner = getRecordOwner($conn, $id);
        if ($old_owner != $new_owner) {
          $sql = "UPDATE ID_NUM 
                    SET MOD_BY=:new_owner";
          doMod_oracle($conn, $sql, array(':new_owner' => $new_owner));
        }//owner has changed
      }//valid id
    }//for each id
    
    disconnectFromCurationDB($conn);
  }//changeOwnership()
*/  
  
  /**
   * connectToCurationDB
   *
   * Permits connecting just the curation pages to an alternative DB for 
   * testing. Generally the connect variables are taken from configure.php
   * in the POPcorn root directory.
   *
   * @return handle - database connection
   */
  function connectToCurationDB() {
  	global $pc_system;

/* ORACLE*/
    include "../../include/db-api.php";
    return connect_to_database();
/**/

/* POSTGRES
    $connect_str  = "host=" . $pc_system['db_host'];
    $connect_str .= " dbname=". $pc_system['db_name'];
    try {
      $conn = new PDO("pgsql:$connect_str", $pc_system['db_user'], $pc_system['db_pass']);
    } catch (PDOException $e) {
      logMessagePC("Unable to connect to $connect_str: " .  $e->getMessage());
    }
    
    if ($conn) {
      logMessagePC("connectToCurationDB() connected to database");
    }
*/
    
    return $conn;
  }//connectToCurationDB


  function disconnectFromCurationDB($conn) {
//logMessagePC("Disconnect from DB");
/* ORACLE */
//  	$ret = oci_close($conn);
/**/
  }//disconnectFromCurationDB

  
  /**
   * countActiveAlerts
   * 
   * @return int - number of active alerts
   */
  function countActiveAlerts() {
    $annotatorID = $_SESSION['annotatorID'];
    $conn = connectToCurationDB();

/* POSTGRES
    $sql = "SELECT COUNT(*) count FROM alert
            WHERE HANDLED='no' 
                  AND annotation_author_id=$annotatorID
                  AND (alert_date < CURRENT_DATE 
                       OR alert_date IS NULL)";
*/
/* ORACLE */
    $sql = "SELECT COUNT(*) COUNT FROM ALERT
            WHERE HANDLED='no' 
                  AND ANNOTATION_AUTHOR_ID=$annotatorID
                  AND (ALERT_DATE < SYSDATE 
                       OR ALERT_DATE IS NULL)";
/**/
    $res = makeQuery_oracle($conn, $sql);
    $row = retrieveRow($res);
    $alert_count = $row['count'];
    
    disconnectFromCurationDB($conn);
    return $alert_count;
  }//countActiveAlerts()
  
  
  /**
   * createIdNum
   * 
   * Grabs the next MAX_ID so to speak from the database, which 
   * is a cross-table sequence.  Used prior to storing new rows presumably.
   * 
   * @param $conn - oracle connection reference
   * @param $data_type - data type referenced from TERM table
   * @return int - new ID, or -1 on fail
   */
  function createIdNum($conn, $data_type) {
    $annotatorID = $_SESSION['annotatorID'];
    $nowDate = date('d-M-Y H:i:s');
    
    // Get resource type id
    $type_id = getDataTypeID($conn, $data_type);
    
    $sql = "INSERT INTO id_num
             (id, type_term, mod_by, mod_date, add_by, add_date, curation_lvl)
            VALUES ( 
             (SELECT ((id) + 1) FROM id_num 
              WHERE id IN (SELECT MAX(id) 
              FROM id_num WHERE (id < 9000000))),
             :typeid, :annoid, 
             TO_DATE(:nowdate, 'DD-MON-YYYY HH24:MI:SS'), 
             :annoid,
             TO_DATE(:nowdate, 'DD-MON-YYYY HH24:MI:SS'), 
             10)";
logMessagePC("createIdNum():\n$sql");

    $vals = array( 
      ":typeid" => $type_id, 
      ":annoid" => $annotatorID, 
      ":nowdate" => $nowDate);
             
    $res = doMod_oracle($conn, $sql, $vals);
    if (!$res) {
      $msg = "createIdNum(): ";
      $msg .= "Unable to complete the following insert statement:\n$sql";
      reportErrorPC("$msg\n");
       return -1;
     }

    // Get ID for record just inserted above
    $sql = "SELECT MAX(id) id FROM id_num WHERE id < 9000000";
    $res = makeQuery_oracle($conn, $sql);
    $row = retrieveRow($res);

    return $row['id'];
  }//createIdNum()
  

  /**
   * emailAlerts
   * 
   * @param $conn
   * @param $alerts - array of alerts
   * @param $data_type - type term name: 'POPcorn Resource' or 'POPcorn Project'
   * @param $id - record alerts are attached to
   * @return none
   */
  function emailAlerts($conn, $alerts, $data_type, $id) {
//logMessagePC("emailAlert($conn, $alerts, $data_type, $id)");
    foreach ($alerts as $alert) {
//logVarDumpPC($alert, 'check this alert:');
      // 0-yr, 1-mon, 2-day, 3-msg, 4-assigned, 5-handled, 6-email date, 7-autonum
      $year       = $alert[0];
      $alert_date = $alert[2] . '-' . $alert[1] . '-' . $year;
      $msg        = $alert[3];
      $curator    = $alert[4];
      $handled    = $alert[5];
      $email_date = $alert[6];
      $auto_num   = $alert[7];
//logMessagePC("lib_curation_db.php: emailAlerts(): $msg\nhandled: $handled, email_date: $email_date, alert_date: $alert_date");
//logMessagePC("alert date: $alert_date, to time: " . strtotime($alert_date) . ", triggered: " . (strtotime($alert_date) < time()));
      // E-mail this alert if:
      // not handled 
      //    AND no notification sent yet 
      //    AND (no date OR date has passed)
      if ($handled == 'no'                                    // not handled
          && ($email_date == null || trim($email_date) == '') // no e-mail sent
          && (trim($year) == ''                          // no date = always on
              || strtotime($alert_date) < time()         // date is passed
             )
          ) {
        $record_name = getRecordName($id, getDataTypeId($conn, $data_type), 
                                     $conn);
        $body = "An alert has been assigned to you regarding ";
        $body .= "the $data_type, '$record_name' with ID $id.\n\n$msg\n";
//logMessagePC("getCuratorEmail($curator)");
        $email = getCuratorEmail($curator);
//logMessagePC("sendMessage('ALERT', 'Curation System', '$email', 'popcornwebmaster@iastate.edu', '$body')");
//        sendMessage('POPCORN ALERT', 'Curation System', 
//                    'popcornwebmaster@iastate.edu', $body, $email);
//        setEmailDate($auto_num, $conn);
      }
    }//for all alerts
  }//emailAlerts()


  /**
   * getActiveAlerts
   * 
   * @return array - all active alert records
   */
  function getActiveAlerts() {
    $results = array();
    $annotatorID = $_SESSION['annotatorID'];
    $conn = connectToCurationDB();

/* POSTGRES
    $sql = "SELECT auto_num, id, type_term, alert_date, alert_msg, 
                   (CURRENT_DATE-aler_date) age, handled, annotation_author_id, 
                   email_date
            FROM alert
            WHERE handled='no' 
                  AND annotation_author_id=$annotatorID
                  AND (alert_date < CURRENT_DATE 
                       OR alert_date IS NULL)";
*/
/* ORACLE */
    $sql = "SELECT AUTO_NUM, ID, TYPE_TERM, ALERT_DATE, ALERT_MSG, 
                   (SYSDATE-ALERT_DATE) AGE, HANDLED, ANNOTATION_AUTHOR_ID, 
                   EMAIL_DATE
            FROM ALERT
            WHERE HANDLED='no' 
                  AND ANNOTATION_AUTHOR_ID=$annotatorID
                  AND (ALERT_DATE < SYSDATE 
                       OR ALERT_DATE IS NULL OR ALERT_DATE='')";
/**/
    $res = makeQuery_oracle($conn, $sql);
    if ($res) {
      while ($row=retrieveRow($res)) {
//echo '<pre>';var_dump($row);echo "</pre>";
        if ($row['alert_date'] == '' || $row['alert_date'] == null) {
          $date = "always on";
        }
        else {
          $date = $row['alert_date'];
        }
        $result = array('date' => $date, 
                        'msg' => stripslashes($row['alert_date']), 
                        'age' => ($date == 'always on') ? 'n/a' : intval($row['age']), 
                        'id' => $row['id'], 
                        'type_term' => $row['type_term'], 
                        'assigned' => $row['annotation_author_id'], 
                        'email_date' => $row['email_date'],
                        'handled' => $row['handled'], 
                        'auto_num' => $row['auto_num']
                       );
        array_push($results, $result);
      }
    }

    disconnectFromCurationDB($conn);
    
    return $results;
  }//getActiveAlerts()
  
  
  /**
   * getAuditTrailRecord
   * 
   * Start a new audit_trail record (to track changes)
   * 
   * @param $conn
   * @param $module
   * @param $id
   * @param $annotatorID
   * @return int - id for just-inserted record
   */
  function getAuditTrailRecord($conn, $module, $id, $annotatorID) {
    // First create a new AUDIT_TRAIL record and get a new $auditTrailID
    $now_date = date('d-M-Y H:i:s');

    $sql = "INSERT INTO audit_trail VALUES (
              (SELECT (MAX(audit_trail_id) + 1) FROM audit_trail),
              :id, :module, :annotatorID,
              TO_DATE('$now_date', 'DD-MON-YYYY HH24:MI:SS'))";
    $res = doMod_oracle($conn, $sql, 
                 array(':id'          => $id,
                       ':module'      => $module,
                       ':annotatorID' => $annotatorID,
                 ));
    if (!$res) {
      $msg = "getAuditTrailRecord(): ";
      $msg .= "Unable to complete the following insert statement:\n$sql";
      reportErrorPC("$msg\n");
      return -1;
    }
  
    // Get the ID of the new Audit_Trail Record
    $sql = "SELECT MAX(audit_trail_id) audit_trail_id FROM audit_trail";
    
    $res = makeQuery_oracle($conn, $sql);
    $row = retrieveRow($res);
    
    return $row["audit_trail_id"];
  }//getAuditTrailRecord()


  /**
   * getAutoNum
   * 
   * @param $conn
   * @param $table
   * @return int - next auto_num for table
   */
  function getAutoNum($conn, $table) {
    $sql = "SELECT MAX(auto_num) auto_num FROM $table";
    $res = makeQuery_oracle($conn, $sql);
    if (!$res) {
      reportErrorPC("getAutoNum(): Unable to get auto_num from $table:\n$sql\n");
      return -1;
    }
    $row = retrieveRow($res);
    return $row['auto_num'] + 1;
  }//getAutoNum()
  
  
  /**
   * getAlert
   * 
   * @param $auto_num
   * @return array - one alert record
   */
  function getAlert($auto_num) {
    $conn = connectToCurationDB();
    $sql = sprintf("SELECT * FROM alert WHERE auto_num='%d'",$auto_num);
    $res = makeQuery_oracle($conn, $sql);
    if (!$res) {
      reportErrorPC("getAlert(): Unable to complete statement:\n$sql");
      return array();
    }
    $row = retrieveRow($res);
    $result = array();
    if ($row['alert_date'] == '' || $row['alert_date'] == null) {
      $date = "always on";
    }
    else {
      $date = $row['ALERT_DATE'];
    }
    $result = array('date'       => $date, 
                    'msg'        => stripslashes($row['alert_msg']), 
                    'id'         => $row['id'],
                    'type_term'  => $row['type_term'], 
                    'assigned'   => $row['annotation_author_id'], 
                    'email_date' => $row['email_date'],
                    'handled'    => $row['handled'], 
                    'auto_num'   => $row['auto_num']
                   );
    
    disconnectFromCurationDB($conn);
    return $result;
  }//getAlert()
  
  
  /**
   * getAlerts
   * 
   * Get all alerts associated with this record. 
   * Result array mirrors controls in add-alert box 
   *   (e.g. the date is split into year|month|day).
   *
   * @param $conn
   * @param $id
   * @return array - all alerts associated with a record
   */
  function getAlerts($conn, $id) {
    $results = array();
    $id=intval($id);
    
    $sql = "SELECT * FROM alert
            WHERE id=$id ORDER BY alert_date";
    
    $res = makeQuery_oracle($conn, $sql);
    if ($res) {
      while ($row=retrieveRow($res)) {
        // Set date string
        if ($row['alert_date'] != '' && $row['alert_date'] != null) {
          $fields = explode('-', $row['alert_date']);
          $fields[2] = '20' . $fields[2];
        }
        else {
          $fields = array(' ', ' ', ' ');
        }
        
        // NOTE: auto_num field MUST be last!
        // day, month, year, msg, assigned-to, handled, auto_num
        $result = array($fields[2], ucfirst(strtolower($fields[1])), $fields[0], 
                        stripslashes($row['alert_msg']), 
                        $row['annotation_author_id'], 
                        $row['handled'],
                        $row['auto_num']
                       );
                       
        // Make sure there are not empty strings
        for ($i=0; $i<count($result); $i++) {
          if ($result[$i] == '') $result[$i] = ' ';
        }
        array_push($results, $result);
      }
    }
    return $results;
  }//getAlerts()
  
  
  /**
   * getAllBlasts
   * 
   * @return array - array of all active category names.
   */
  function getAllBlasts() {
    global $bfields;
    
    $results = array();
    $conn = connectToCurationDB();

/* ORACLE */  
    $sql = "SELECT * 
            FROM pc_blast_ctl S
              INNER JOIN id_num ON id_num.id=S.id
            ORDER BY NLS_LOWER(name)";
/**/
/* POSTGRES
    $sql = "SELECT * 
            FROM pc_blast_ctl S
              INNER JOIN id_num ON id_num.id=S.id
            ORDER BY id_num.curation_lvl, LOWER(name)";
*/
    $res = makeQuery_oracle($conn, $sql);
    while ($row=retrieveRow($res)) {
      $result = array();
      $result['id']                  = $row['id'];
      $result['level']               = levelToString($row['curation_lvl']);
      $result['blast_db_update']     = levelToString($row['blast_db_update']);
      $result['db_name']             = stripslashes($row['db_name']);
      $result['db_path']             = stripslashes($row['db_path']);
      $result['display_info']        = stripslashes($row['display_info']);
      $result['name']                = stripslashes($row['name']);
      $result['short_name']          = stripslashes($row['short_name']);
      $result['results_url']         = stripslashes($row['results_url']);
      $result['source']              = stripslashes($row['source']);
      $result['type']                = stripslashes($row['type']);
      $result['web_service_url']     = stripslashes($row['web_service_url']);
      $result['citation']            = stripslashes($row['citation']);
      $result['web_service_type']    = stripslashes($row['web_service_type']);
      $result['link']                = stripslashes($row['link']);
      $result['notes']               = stripslashes($row['notes']);
      $result['warning']             = stripslashes($row['warning']);
      $result['mod_date']            = $row['mod_date'];
      
      array_push($results, $result);
    }
//echo "<pre>";var_dump($results);echo "</pre>";
    
    disconnectFromCurationDB($conn);
    return $results;
  }//getAllBlasts()
  
  
  /**
   * getAllCategories
   * 
   * @return array - array of all active category names.
   */
  function getAllCategories() {
    global $cfields, $curation_levels;
    
    $results = array();
    $conn = connectToCurationDB();
    
    $cur_lvls = array($curation_levels['active'][1], 
                      $curation_levels['inprogress'][1]);
    
/* ORACLE */
    $sql = "SELECT * 
            FROM pc_category CAT
              INNER JOIN id_num ON id_num.id=CAT.id
            WHERE id_num.curation_lvl IN (" . implode(',', $cur_lvls) . ")
            ORDER BY NLS_LOWER(name)";
/**/
/* POSTGRES
    $sql = "SELECT * 
            FROM pc_category CAT
              INNER JOIN id_num ON id_num.id=CAT.id
            WHERE id_num.curation_lvl IN (" . implode(',', $cur_lvls) . ")
            ORDER BY LOWER(name)";
*/
    $res = makeQuery_oracle($conn, $sql);
    while ($row=retrieveRow($res)) {
      $result = array();
      $result['id']            = $row['id'];
      $result['level']         = levelToString($row['curation_lvl']);
      $result['category_name'] = stripslashes($row['name']);
      $result['description'] = mgdb_safe_html(stripslashes($row['description']));
      $result['mod_date']      = $row['MOD_DATE'];
      array_push($results, $result);
    }
    
    disconnectFromCurationDB($conn);
    return $results;
  }//getAllCategories()
  
  
  /**
   * getAllSearches
   * 
   * @return array - array of all active category names.
   */
  function getAllSearches() {
    global $sfields;
    
    $results = array();
    $conn = connectToCurationDB();
    
/* ORACLE */
    $sql = "SELECT * 
            FROM pc_search_ctl S
              INNER JOIN id_num ON id_num.id=S.id
            ORDER BY NLS_LOWER(NAME)";
/**/
/* POSTGRES
    $sql = "SELECT * 
            FROM pc_search_ctl S
              INNER JOIN id_num ON id_num.id=S.id
            ORDER BY id_num.curation_lvl, LOWER(name)";
*/
    $res = makeQuery_oracle($conn, $sql);
    while ($row=retrieveRow($res)) {
      $result = array();
      $result['id']                  = $row['id'];
      $result['level']               = levelToString($row['curation_lvl']);
      $result['name']                = stripslashes($row['name']);
      $result['short_name']          = stripslashes($row['short_name']);
      $result['type']                = stripslashes($row['type']);
      $result['helper_script']       = stripslashes($row['helper_script']);
      $result['blast_source']        = stripslashes($row['blast_source']);
      $result['blast_database']      = stripslashes($row['blast_database']);
      $result['entrez']              = stripslashes($row['entrez']);
      $result['process']             = stripslashes($row['process']);
      $result['view_hit_record_url'] = stripslashes($row['view_hit_record_url']);
      $result['citation']            = stripslashes($row['citation']);
      $result['warning']             = stripslashes($row['warning']);
      $result['mod_date']            = $row['mod_date'];
      
      array_push($results, $result);
    }
//echo "<pre>";var_dump($results);echo "</pre>";
    
    disconnectFromCurationDB($conn);
    return $results;
  }//getAllSearches()
  
  
  /**
   * getAssociatedResources
   * 
   * @param $conn
   * @param $id
   * @return array - all resources associated with a project
   */
  function getAssociatedResources($conn, $id) {
    $results = array();
    $id=intval($id);
    
    $sql = "SELECT R.name, A.ordering, A.auto_num
            FROM pc_association A, pc_resource R
            WHERE A.id1=$id AND R.id=A.id2 ORDER BY name";
    $res = makeQuery_oracle($conn, $sql);
    if ($res) {
      while ($row=retrieveRow($res)) {
        $result = array(stripslashes($row['name']), $row['ordering'], 
                        $row['auto_num']);
        array_push($results, $result);
      }
    }
    
    return $results;
  }//getAssociatedResources()
  
  
  function getBlast($id) {
   
    $results = array();
    $conn = connectToCurationDB();
    
    $sql = sprintf("SELECT * FROM pc_blast_ctl WHERE id=%d", $id);
    $res = makeQuery_oracle($conn, $sql);
    if (!$res) {
      reportErrorPC("getSearch(): Unable to find PC_BLAST_CTL record for [ID]");
      return array();
    }
    $row = retrieveRow($res);
    $results['id']               = $id;
    $results['blast_db_update']  = stripslashes($row['blast_db_update']);
    $results['db_name']          = stripslashes($row['db_name']);
    $results['db_path']          = stripslashes($row['db_path']);
    $results['display_info']     = stripslashes($row['display_info']);
    $results['name']             = stripslashes($row['name']);
    $results['short_name']       = stripslashes($row['short_name']);
    $results['results_url']      = stripslashes($row['results_url']);
    $results['source']           = stripslashes($row['source']);
    $results['type']             = stripslashes($row['type']);
    $results['web_service_url']  = stripslashes($row['web_service_url']);
    $results['web_service_type'] = stripslashes($row['web_service_type']);
    $results['make_track']       = stripslashes($row['make_track']);
    $results['make_image']       = stripslashes($row['make_image']);
    $results['link']             = stripslashes($row['link']);
    $results['citation']         = stripslashes($row['citation']);
    $results['notes']            = stripslashes($row['notes']);
    $results['warning']          = stripslashes($row['warning']);
    
    $category_list = makeListString(getCategories($conn, $id));
    $results['category_list'] = $category_list; 
    
    $resource_list = makeListString(getAssociatedResources($conn, $id));
    $results['resource_list'] = $resource_list; 

    $project_list = makeListString(getRelatedProjects($conn, $id));
    $results['project_list'] = $project_list; 

    $alert_list = makeListString(getAlerts($conn, $id));
    $results['alert_list'] = $alert_list; 
    
    disconnectFromCurationDB($conn);

    return $results;
  }//getBlast()
  
  
  /**
   * getCategories
   * 
   * @param $conn
   * @param $id
   * @return array - all categories associated with a project or resource record
   */
  function getCategories($conn, $id) {
    $results = array();
    $id=intval($id);
    
    $sql = "SELECT CAT.name, PAC.auto_num
            FROM pc_assoc_category PAC, pc_category CAT
              INNER JOIN id_num IDN ON IDN.id=CAT.id AND IDN.curation_lvl=0
            WHERE PAC.id=$id AND CAT.id=PAC.category_id ORDER BY name";
    $res = makeQuery_oracle($conn, $sql);
    if ($res) {
      while ($row=retrieveRow($res)) {
        $result = array(stripslashes($row['name']), $row['auto_num']);
        array_push($results, $result);
      }
    }

    return $results;
  }//getCategories()
  
  
  /**
   * getCategory
   * 
   * @param $id
   * @return array - contents of category record
   */
  function getCategory($id) {
   
    $results = array();
    $conn = connectToCurationDB();
    
    $sql = sprintf("SELECT * FROM pc_category WHERE ID=%d", $id);
    $res = makeQuery_oracle($conn, $sql);
    if (!$res) {
      reportErrorPC("getCategory(): Unable to find PC_CATEGORY record for [$id]");
      return array();
    }
    $row = retrieveRow($res);

    $results['id'] = $id;
    $results['category_name'] = stripslashes($row['name']);
    $results['description'] = mgdb_safe_html(stripslashes($row['description']));

    disconnectFromCurationDB($conn);

    return $results;
  }//getCategory()


  /**
   * getCategoryId
   * 
   * Data looks like it comes from SQL.  May want to add a SafeCategory regex...
   * 
   * @param $conn
   * @param $name
   * @return int - id number
   */
  function getCategoryId($conn, $name) {
     $sql = "SELECT CAT.id FROM pc_category CAT
               INNER JOIN id_num IDN ON IDN.id=CAT.id AND IDN.curation_lvl=0 
               WHERE NAME='$name'";
     $res = makeQuery_oracle($conn, $sql);
     $row = retrieveRow($res);
     return $row['id'];
  }//getCategoryId()
  
  
  /**
   * getCuratorEmail
   * 
   * Given a curator id, get the e-mail.
   * 
   * @param $conn
   * @return string - curator email
   */
  function getCuratorEmail($id, $use_conn=null) {
    $name = '';
    $conn = ($use_conn != null) ? $use_conn : connectToCurationDB();
    
    $sql = sprintf("SELECT email FROM annotation_author WHERE id=%d", $id);
    $res = makeQuery_oracle($conn, $sql);
    if ($row = retrieveRow($res)) {
      $email = $row['EMAIL'];
    }
    
    if ($use_conn == null) {
      disconnectFromCurationDB($conn);
    }
    
    return $email;
  }//getCuratorEmail()


  /**
   * getCuratorName
   * 
   * Given a curator id, get the name.
   * 
   * @param $id
   * @param $use_conn
   * @return string - curator's name
   */
  function getCuratorName($id, $use_conn=null) {
    $name = '';
    $conn = ($use_conn != null) ? $use_conn : connectToCurationDB();
    
    $sql = sprintf("SELECT first_name, last_name FROM annotation_author WHERE id=%d", $id);
    $res = makeQuery_oracle($conn, $sql);
    if ($row = retrieveRow($res)) {
      $name = $row['first_name'] . ' ' . $row['last_name'];
    }
    
    if ($use_conn == null) {
      disconnectFromCurationDB($conn);
    }
    
    return $name;
  }//getCuratorName()
  
  
  /**
   * getDataTypeID
   * 
   * Cross references the a NAME to an ID from the TERM table.
   * 
   * @param $conn
   * @param $data_type
   * @return int - ID for data type name in TERM table, -1 if not found
   */
  function getDataTypeID($conn, $data_type) {
    $sql = "SELECT id FROM term WHERE name='$data_type'";
    $res = makeQuery_oracle($conn, $sql);
    if (!$res) {
      return -1;
    }
    $row = retrieveRow($res);
    if (!$row || is_null($row)) {
       logMessagePC("Failed to find data type id for $data_type");
       return '';
    }
    return $row['id'];
  }//getDataTypeID()
  
  
  /**
   * getDataTypeName
   * 
   * Cross references the a NAME to an ID from the TERM table.
   * 
   * @param $conn
   * @param $id - a data type ID
   * @return string - data type name in TERM table, '' if not found
   */
  function getDataTypeName($id, $use_conn=null) {
    $conn = ($use_conn != null) ? $use_conn : connectToCurationDB();

    $sql = "SELECT name FROM term WHERE id=$id";
    $res = makeQuery_oracle($conn, $sql);
    if (!$res) {
      return '';
    }
    $row = retrieveRow($res);
    $name = $row['name'];

    if ($use_conn == null) {
      disconnectFromCurationDB($conn);
    }

    return $name;
  }//getDataTypeName()
  
  
  /**
   * getFieldName
   * 
   * Get the column name by index.
   * 
   * @security value sources are internal as of 2009-08-14
   *  
   * @param $conn
   * @param $which - the index
   * @param $table
   * @return string - name of requested table column
   */
  function getFieldName($conn, $which, $table) {
    $sql = "SELECT COLUMN_NAME FROM ALL_TAB_COLS WHERE TABLE_NAME='$table'";
    $res = makeQuery_oracle($conn, $sql);
    if (!$res) {
      reportErrorPC("getFieldName(): Unable to complete query:\n$sql\n");
      return '';
    }
    $rows = retrieveAllRows($res);

    return $rows[$which];
  }//getFieldName()
  
  
  /**
   * getFunding
   * 
   * @param $conn
   * @param $id
   * @return array - all active funding agencies
   */
  function getFunding($conn, $id) {
    $results = array();
    $id=intval($id);
    
    $sql = "SELECT PAC.url, PAC.ordering, PAC.keywords, P.name, PAC.auto_num
            FROM pc_assoc_funding PAC, person P
            WHERE PAC.id=$id AND P.id=PAC.person_id ORDER BY ordering";
    $res = makeQuery_oracle($conn, $sql);
    if ($res) {
      while ($row=retrieveRow($res)) {
        $result = array($row['url'], $row['ordering'], $row['name'], 
                        $row['keywords'], $row['auto_num']);
        array_push($results, $result);
      }
    }

    return $results;
  }//getFunding()
  
  
  /**
   * getInstitutions
   * 
   * @param $conn
   * @param $id
   * @return array - list of all active institutions
   */
  function getInstitutions($conn, $id) {
    $results = array();
    $id=intval($id);
    
    $sql = "SELECT P.name, PAI.ordering, PAI.auto_num
            FROM pc_assoc_institution PAI, person P
            WHERE PAI.id=$id AND P.id=PAI.person_id ORDER BY ordering";
    $res = makeQuery_oracle($conn, $sql);
    if ($res) {
      while ($row=retrieveRow($res)) {
        $result = array(stripslashes($row['name']), 
                        stripslashes($row['ordering']), 
                        $row['auto_num']);
        array_push($results, $result);
      }
    }

    return $results;
  }//getInstitutions()
  
  
  /**
   * getInvestigators
   * 
   * @param $conn
   * @param $id
   * @return array - list of all active investigators 
   */
  function getInvestigators($conn, $id) {
    $results = array();
    $id = intval($id);
    
    $sql = "SELECT P.name, PAI.relationship, PAI.ordering, PAI.auto_num
            FROM pc_assoc_investigator PAI, person P
            WHERE PAI.id=$id AND P.id=PAI.person_id ORDER BY ordering";
    $res = makeQuery_oracle($conn, $sql);
    if ($res) {
      while ($row=retrieveRow($res)) {
        $result = array(stripslashes($row['name']), 
                        stripslashes($row['relationship']), 
                        $row['ordering'], 
                        $row['auto_num']);
        array_push($results, $result);
      }
    }

    return $results;
  }//getInvestigators()
  
  
  /**
   * getPersonId
   * 
   * data sourced from SQL
   * 
   * @param $conn
   * @param $name
   * @return int - ID of record
   */
  function getPersonId($conn, $name) {
   // Used to retrieve records for all of these types of person records:
   $types = "'Cooperator', 'Researcher for project listed at POPcorn', ";
   $types .= "'Institution', 'Funding Source'";
   
   // Clean up name, including removing anything in parentheses
   $name = getSafeName($name);
   $name = preg_replace('/(.*)\(.*\)/', '$1', $name);
   $name = preg_replace('/\s+$/', '', $name); // trim trailing spaces

    $sql = "SELECT COUNT(DISTINCT PER.name) AS num FROM person PER
              INNER JOIN person_attribute PA ON PA.id = PER.id
              INNER JOIN term ON TERM.id = PA.attribute 
                    AND TERM.name IN ($types)
            WHERE PER.name='$name'";
    $res = makeQuery_oracle($conn, $sql);
    $row = retrieveRow($res);
    if ($row['num'] == 0) {
      return -1;
    }
    else if ($row['num'] > 1) {
      // Consider this a non-fatal error
      $err = "More than one PERSON record is associated with the name '$name'";
      $err .= "\n$sql";
      reportErrorPC("$err\n");
    }
    $sql = "SELECT PER.id FROM person PER
              INNER JOIN person_attribute PA ON PA.id = PER.id
              INNER JOIN term ON TERM.id = PA.attribute 
                    AND TERM.name IN ($types)
            WHERE PER.NAME='$name'";
    $res = makeQuery_oracle($conn, $sql);
    $row = retrieveRow($res);
    return $row['id'];
  }//getPersonId()
  
  
  /**
   * getProject
   * 
   * @param $id
   * @return array - project record + associations in one array
   */
  function getProject($id, $use_conn=null) {
    $results = array();
    
    $conn = ($use_conn != null) ? $use_conn : connectToCurationDB();
    
    $sql = sprintf("SELECT * FROM pc_project WHERE id=%d", $id);
    $res = makeQuery_oracle($conn, $sql);
    if (!$res || !($row = retrieveRow($res))) {
      reportErrorPC("getProject(): Unable to find PC_PROJECT record for [$id]");
      return array();
    }

    $results['id'] = $id;
    $results['project_name']   = stripslashes($row['name']);
    $results['description'] = mgdb_safe_html(stripslashes($row['description']));
    $results['funding_period'] = stripslashes($row['funding_period']);
    $results['notes']          = stripslashes($row['notes']);
    
    $funding_list = makeListString(getFunding($conn, $id));
    $results['funding_list'] = $funding_list; 
    
    $institution_list = makeListString(getInstitutions($conn, $id));
    $results['institution_list'] = htmlentities($institution_list);

    $investigator_list = makeListString(getInvestigators($conn, $id));
    $results['investigator_list'] = $investigator_list;

    $category_list = makeListString(getCategories($conn, $id));
    $results['category_list'] = $category_list;

    $resource_list = makeListString(getAssociatedResources($conn, $id));
    $results['resource_list'] = $resource_list; 

    $project_list = makeListString(getRelatedProjects($conn, $id));
    $results['project_list'] = $project_list; 

    $alert_list = makeListString(getAlerts($conn, $id));
    $results['alert_list'] = $alert_list; 

    if ($use_conn == null) {
      disconnectFromCurationDB($conn);
    }

    return $results;
  }//getProject()
  
  
  /**
   * getProjectId
   * 
   * @param $conn
   * @param $name
   * @return int - id number
   */
  function getProjectId($conn, $name) {
    $name=getSafeName($name);
    $sql = "SELECT id FROM pc_project WHERE name='$name'";
    $res = makeQuery_oracle($conn, $sql);
    if (!$res) {
      reportErrorPC("getProjectId() Unable to complete query:\n$sql\n");
      return -1;
    }
    $row = retrieveRow($res);
    if (!$row) {
      return -1;
    }
    return $row['id'];
  }//getProjectId()
  
  
  /**
   * getProjectList
   * 
   * @param $filters
   * @return array of arrays - all projects matching the filters.
   */
  function getProjectList($conn, $filters, $sortby='LOWER(NAME)', $sort_direction='ASC') {
    global $pfields;
    
    $annotatorID = intval($_SESSION['annotatorID']);
    $results = array();
//    $conn = connectToCurationDB();
    
    // Start SQL statement
    $sql = "SELECT * FROM pc_project PP INNER JOIN id_num ON PP.id=ID_NUM.id";
    
    // Handle filters
    $wheres = array(); // holds where clauses
    if ($filters['id'] != '') { // Overrides all other filters
      $sql .= sprintf(" WHERE PP.ID=%d",$filters['id']);
    }
    else {
      foreach (array_keys($filters) as $filter_field) {
        if ($filters[$filter_field] != '') {
          if (isset($pfields[$filter_field])) {
            // assumes varchar
            $clause = 'LOWER(' . $pfields[$filter_field]['dbfield'] . ')';
            $clause .= " LIKE '%" . strtolower($filters[$filter_field]) . "%'";  
            array_push($wheres, $clause);
          }
          else {
            if ($filter_field == 'level') {
              if (trim($filters[$filter_field]) == '-1') {
                // All visible curation levels; < 99 : visible curation lvls
                $clause = "ID_NUM.curation_lvl < 99";  
              }
              else {
                // Specific curation level
                $clause = "ID_NUM.curation_lvl=" . $filters[$filter_field];
              }
              array_push($wheres, $clause);
            }
            else if ($filter_field == 'edited') {
              $clause = "ID_NUM.mod_date>=SYSDATE-$filters[$filter_field]";
              array_push($wheres, $clause);
            }
            else if ($filter_field == 'created') {
              $clause = "ID_NUM.add_date>=SYSDATE-$filters[$filter_field]";
              array_push($wheres, $clause);
            }
            else if ($filter_field == 'created_me') {
              $clause = "ID_NUM.add_by = $annotatorID";
              array_push($wheres, $clause);
            }
            else if ($filter_field == 'edited_me') {
              $clause = "ID_NUM.mod_by = $annotatorID";
              array_push($wheres, $clause);
            }
            else {
              reportErrorPC("Filter $filter_field not handled yet");
            }
          }//Not a simple database field
        }//filter is set
      }//foreach filter
      
      if (count($wheres) > 0) {
        $sql .= " WHERE " . join(" AND ", $wheres);
      }
    }//ID not given as a filter
    
    // Finish SQL statement
    if ($sortby != '') {
      $sql .= " ORDER BY $sortby $sort_direction";
    }

    $res = makeQuery_oracle($conn, $sql);
    while ($row=retrieveRow($res)) {
      $result = array();
      $result['id']            = $row['id'];
      $result['level']         = levelToString($row['curation_lvl']);
//      $result['resource_name'] = stripslashes($row['NAME']);
      $result['project_name']  = stripslashes($row['name']);
      $result['description'] = mgdb_safe_html(stripslashes($row['description']));
      $result['notes']         = stripslashes($row['notes']);
      $result['mod_date']      = $row['mod_date'];
      array_push($results, $result);
    }
    
//    disconnectFromCurationDB($conn);
    return $results;
  }//getProjectList()


  /**
   * getRecentRecords
   * 
   * Get the last few records for the convenience of the curator
   * 
   * @return array of arrays with latest record data
   */
  function getRecentRecords() {
    global $curation_levels;
    
    $conn = connectToCurationDB();
    
    $annotatorID = $_SESSION['annotatorID'];
    $resource_type = getDataTypeID($conn, 'POPcorn Resource');
    $project_type = getDataTypeID($conn, 'POPcorn Project');
    $recs = array();
    
    $sql = "SELECT * FROM id_num 
            WHERE EXTRACT(DAY FROM SYSTIMESTAMP-mod_date) <= 1 
                  AND mod_by=$annotatorID
                  AND type_term IN ($resource_type, $project_type)
            ORDER BY type_term, mod_date";
    
    $res = makeQuery_oracle($conn, $sql);
    while ($row = retrieveRow($res)) {
      $rec = array();
      $rec['id'] = $row['id'];
      $rec['type'] = ($row['type_term'] == $resource_type) 
                          ? 'Resource' : "Project";
      if ($row['type_term'] == $resource_type) {
        $namesql = "SELECT name FROM pc_resource WHERE id=" . $rec['id'];
      }
      else {
        $namesql = "SELECT name FROM pc_project WHERE id=" . $rec['id'];
      }
      $nameres = makeQuery_oracle($conn, $namesql);
      $namerow = retrieveRow($nameres);
      $rec['name'] = truncate($namerow['name'], 20);
      
      foreach ($curation_levels as $level) {
        if ($level[1] == $row['curation_lvl']) {
          $rec['curation_lvl'] = $level[0];
          break;
        }
      }

      array_push($recs, $rec);
    }
    
    disconnectFromCurationDB($conn);

    return $recs;
  }//getRecentRecords
  
  
  /**
   * getRecordCurationLvl
   * 
   * @security - $id is tainted data... in resourceLevelComplete() at least. using sprintf to clean
   * 
   * @param $id
   * @param $use_conn
   * @return int - curation level
   */
  function getRecordCurationLvl($id, $use_conn=null) {
    $conn = ($use_conn != null) ? $use_conn : connectToCurationDB();
    
    $sql = sprintf("SELECT curation_lvl FROM id_num WHERE id=%d", $id);
    $res = makeQuery_oracle($conn, $sql);
    if (!$res || !($row = retrieveRow($res))) {
      return -1;
    }
    $level = $row['curation_lvl'];
    
    if ($use_conn == null) {
      disconnectFromCurationDB($conn);
    }
    
    return $level;
  }//getRecordCurationLvl()
  
  
  /**
   * getRecordName
   * 
   * Retrieves the name of a record when the ID is known.
   * 
   * @param $id
   * @param $type_term
   * @return string - contents of NAME field
   */
  function getRecordName($id, $type_term, $use_conn=null) {
    $conn = ($use_conn != null) ? $use_conn : connectToCurationDB();

    if ($type_term == getDataTypeID($conn, 'POPcorn Resource')) {
      $sql = sprintf("SELECT name FROM pc_resource WHERE id=%d",$id);
    }
    else {
      $sql = sprintf("SELECT name FROM pc_project WHERE id=%d",$id);
    }
    $res = makeQuery_oracle($conn, $sql);
    if (!$res || !($row = retrieveRow($res))) {
      reportErrorPC("getRecordName(): Unable to complete this statement:\n$sql\n");
      disconnectFromCurationDB($conn);
      return '';
    }

     $name = stripslashes($row['name']);
      
    if ($use_conn == null) {
      disconnectFromCurationDB($conn);
    }

    return $name;    
  }//getRecordName()
  
  
  /**
   * getRecordNotes
   * 
   * Returns data from NOTES field
   * 
   * @param $conn
   * @param $id
   * @param $tabletype
   * @return string - notes from table
   */
  function getRecordNotes($conn, $id, $tabletype) {
    $table = ($tabletype == 'Resource') ? 'pc_resource' : 'pc_project';
    $sql = sprintf("SELECT notes FROM $table WHERE id=%d",$id);
    $res = makeQuery_oracle($conn, $sql);
    if (!($row = retrieveRow($res))) {
      reportErrorPC("getRecordNotes(): Unable to find this record.\n$sql\n");
      return '';
    }
    return $row['NOTES'];
  }//getRecordNotes
  
  
  function getRecordOwner($conn, $id) {
    $sql = "SELECT mod_by FROM id_num WHERE id='$id'";
    $res = makeQuery_oracle($conn, $sql);
    if ($row = retrieveRow($res)) {
      return $row['mod_by'];
    }
    else {
      return '';
    }
  }//getRecordOwner()
  
  
  /**
   * getRecordType
   * 
   * Get the type of a record when the ID is known
   * 
   * @param $id
   * @param $use_conn
   * @return string - record type as a string
   */
  function getRecordType($id, $use_conn=null) {
    $conn = ($use_conn == null) ? connectToCurationDB() : $use_conn;
    $sql = sprintf("SELECT TERM.name 
            FROM id_num INNER JOIN term ON TERM.id=ID_NUM.type_term
            WHERE ID_NUM.id=%d",$id);
    $res = makeQuery_oracle($conn, $sql);
    if (!$res) {
      reportErrorPC("getRecordType(): Unable to complete statement:\n$sql\n");
      return '';
    }
    $row = retrieveRow($res);
    $type = stripslashes($row['name']);
    
    if ($use_conn == null) {
      // connection opened here, so close it here:
      disconnectFromCurationDB($conn);
    }
    
    return $type;
  }//getRecordType
  
  
  /**
   * getRelatedProjects
   * 
   * @param $conn
   * @param $id
   * @return array - all resources associated with a project
   */
  function getRelatedProjects($conn, $id) {
    $results = array();
    $id=intval($id);
    
    $sql = "SELECT P.name, A.ordering, A.auto_num
            FROM pc_association A, pc_project P
            WHERE A.id1=$id AND P.id=A.id2 ORDER BY name";
    $res = makeQuery_oracle($conn, $sql);
    if ($res) {
      while ($row=retrieveRow($res)) {
        $result = array(stripslashes($row['name']), $row['ordering'], 
                        $row['auto_num']);
        array_push($results, $result);
      }
    }

    return $results;
  }//getRelatedProjects()
  
  
  /**
   * getResource
   * 
   * @param $id
   * @return array - contents of resource record + associations in one array
   */
  function getResource($id) {
    // Similar to getOneResource() in lib_search.php
    $results = array();
    $conn = connectToCurationDB();
  
    $sql = sprintf("SELECT * FROM pc_resource WHERE id=%d",$id);
    $res = makeQuery_oracle($conn, $sql);
    if (!$res || !($row = retrieveRow($res))) {
      reportErrorPC("getResource(): Unable to find PC_RESOURCE record for [$id]");
      return array();
    }

    $results['id']            = $id;
    $results['resource_name'] = stripslashes($row['name']);
    $results['description'] = mgdb_safe_html(stripslashes($row['description']));
    $results['url']           = $row['url'];
    $results['tutorial']      = $row['tutorial'];
    $results['seq_based']     = $row['seq_based'];
    $results['notes']         = stripslashes($row['notes']);

    $funding_list = makeListString(getFunding($conn, $id));
    $results['funding_list'] = $funding_list; 
    
    $institution_list = makeListString(getInstitutions($conn, $id));
    $results['institution_list'] = htmlentities($institution_list); 

    $investigator_list = makeListString(getInvestigators($conn, $id));
    $results['investigator_list'] = $investigator_list; 

    $category_list = makeListString(getCategories($conn, $id));
    $results['category_list'] = $category_list; 

    $alert_list = makeListString(getAlerts($conn, $id));
    $results['alert_list'] = $alert_list; 

    disconnectFromCurationDB($conn);
//logMessagePC("getResource() finished");

    return $results;
  }//getResource()
  
  
  /**
   * getResourceId
   * 
   * @param $conn
   * @param $name
   * @return int - id number
   */
  function getResourceId($conn, $name) {
   $name=getSafeName($name);
    $sql = "SELECT id FROM pc_resource WHERE name='$name'";
    $res = makeQuery_oracle($conn, $sql);
    if (!$res) {
      reportErrorPC("getResourceId(): Unable to complete query:\n$sql\n");
      return -1;
    }
    $row = retrieveRow($res);
    if (!$row) {
      return -1;
    }
    return $row['id'];
  }//getResourceId()
  
  
  /**
   * getResourceIDForURL
   * 
   * @security - $url is tainted.
   * 
   * @param $url
   * @return int - record ID or -1 if not found
   */
  function getResourceIDForURL($url) {
       
    if(! isSafeUrl($url)){
      reportErrorPC("getResourceIDForURL(): Invalid URL sent to routine:\n$url\n");
      return -1;
    }
    
    $conn = connectToCurationDB();
    
    // Remove optional parts of the URL:
    $url_mod = preg_replace('/http:\/\//', '', $url);
    $url_mod = preg_replace('/www/', '', $url_mod);
    $url_mod = preg_replace('/\/$/', '', $url_mod);
//logMessagePC("Converted $url to $url_mod\n");

    $sql = "SELECT id FROM pc_resource WHERE url LIKE '%$url_mod%'";
    $res = makeQuery_oracle($conn, $sql);
    if (!$res) {
      reportErrorPC("getResourceIDForURL(): Unable to complete SQL query:\n$sql\n");
      return -1;
    }
    $row = retrieveRow($res);
    if (!$row) {
      return -1;
    }
    $id = $row['id'];
    
    disconnectFromCurationDB($conn);
    return $id;
  }//getResourceIDForURL()
  
  
  /**
   * getResourceList
   * 
   * @param $filters
   * @return array of array - all resources matching the filters.
   */
//ORACLE
  function getResourceList($conn, $filters, $sortby='NLS_LOWER(NAME)', $sort_direction='ASC') {
//POSTGRES
//  function getResourceList($conn, $filters, $sortby='LOWER(NAME)', $sort_direction='ASC') {
    global $rfields;

    $annotatorID = $_SESSION['annotatorID'];
    $results = array();
//    $conn = connectToCurationDB();
    
    // Start SQL statement
    $sql = "SELECT * FROM pc_resource PR INNER JOIN id_num ON PR.id=ID_NUM.id";

    // Handle filters
    if ($filters['id'] != '') { 
      // ID Overrides all other filters
      $sql .= sprintf(" WHERE PR.id=%d" ,$filters['id']);
    }
    else {
      $wheres = array();
      foreach (array_keys($filters) as $filter_field) {
        if ($filters[$filter_field] != '') {
          if (isset($rfields[$filter_field])) {
            // assumes varchar
            $clause = 'LOWER(' . $rfields[$filter_field]['dbfield'] . ')';
            $clause .= " LIKE '%" . strtolower($filters[$filter_field]) . "%'";  
            array_push($wheres, $clause);
          }
          else {
            if ($filter_field == 'level') {
              if (trim($filters[$filter_field]) == '-1') {
                // All visible curation levels; < 99 : visible curation lvls
                $clause = "ID_NUM.curation_lvl < 99";  
              }
              else {
                // Specific curation level
                $clause = "ID_NUM.curation_lvl=" . $filters[$filter_field];
              }
              array_push($wheres, $clause);
            }
            else if ($filter_field == 'edited') {
              $clause = "EXTRACT(DAY, SYSTIMESTAMP-ID_NUM.MOD_DATE) <= -$filters[$filter_field]";
              array_push($wheres, $clause);
            }
            else if ($filter_field == 'created') {
              $clause = "EXTRACT(DAY, SYSTIMESTAMP-ID_NUM.ADD_DATE) <=- $filters[$filter_field]";
              array_push($wheres, $clause);
            }
            else if ($filter_field == 'created_me') {
              $clause = "ID_NUM.add_by = $annotatorID";
              array_push($wheres, $clause);
            }
            else if ($filter_field == 'edited_me') {
              $clause = "ID_NUM.mod_by = $annotatorID";
              array_push($wheres, $clause);
            }
            else {
//echo "<br>Filter $filter_field not handled yet<br>\n";
            }
          }//Not a simple database field
        }//filter is set
      }//foreach filter
      
      if (count($wheres) > 0) {
        $sql .= " WHERE " . join(" AND ", $wheres);
      }
    }//ID not given as a filter
    
    // Finish SQL statement
    if ($sortby != '') {
      $sql .= " ORDER BY $sortby $sort_direction";
    }
    $res = makeQuery_oracle($conn, $sql);
    while ($row=retrieveRow($res)) {
      $result = array();
      $result['id']            = $row['id'];
      $result['level']         = levelToString($row['curation_lvl']);
      $result['resource_name'] = stripslashes($row['name']);
      $result['description'] = mgdb_safe_html(stripslashes($row['description']));
      $result['url']           = $row['url'];
      $result['notes']         = stripslashes($row['notes']);
      $result['mod_date']      = $row['mod_date'];
      array_push($results, $result);
    }
    
//    disconnectFromCurationDB($conn);
    return $results;
  }//getResourceList()


  /**
   * getSafeName
   * 
   * @param $name
   * @return unknown_type
   */
  function getSafeName($name){
   return preg_replace('/[\'%]/', '', $name);
  }//getSafeName()


  function getSearch($id) {
    $results = array();
    $conn = connectToCurationDB();
    
    $sql = sprintf("SELECT * FROM pc_search_ctl WHERE id=%d", $id);
    $res = makeQuery_oracle($conn, $sql);
    if (!$res) {
      reportErrorPC("getSearch(): Unable to find PC_SERACH_CTL record for [$id]");
      return array();
    }
    $row = retrieveRow($res);
    $results['id']                  = $id;
    $results['name']                = stripslashes($row['name']);
    $results['short_name']          = stripslashes($row['short_name']);
    $results['search_type']         = stripslashes($row['type']);
    $results['helper_script']       = stripslashes($row['helper_script']);
    $results['blast_source']        = stripslashes($row['blast_source']);
    $results['blast_database']      = stripslashes($row['blast_database']);
    $results['blast_target_type']   = stripslashes($row['blast_target_type']);
    $results['entrez']              = stripslashes($row['entrez']);
    $results['process']             = stripslashes($row['process']);
    $results['view_hit_record_url'] = stripslashes($row['view_hit_record_url']);
    $results['citation']            = stripslashes($row['citation']);
    $results['notes']               = stripslashes($row['notes']);
    $results['warning']             = stripslashes($row['warning']);
    
    $category_list = makeListString(getCategories($conn, $id));
    $results['category_list'] = $category_list; 

    $resource_list = makeListString(getAssociatedResources($conn, $id));
    $results['resource_list'] = $resource_list; 

    $project_list = makeListString(getRelatedProjects($conn, $id));
    $results['project_list'] = $project_list; 

    $alert_list = makeListString(getAlerts($conn, $id));
    $results['alert_list'] = $alert_list; 
    
    disconnectFromCurationDB($conn);

     return $results;
  }//getSearch()
  
  
  function getSuperCurators() {
    $curators = array();
    $conn = connectToCurationDB();
    
    $sql = "SELECT * FROM annotation_author 
            WHERE person_id IS NOT NULL AND curation_lvl <= -5 
            ORDER BY last_name, first_name, date_added DESC";
    $res = makeQuery_oracle($conn, $sql);
    $added = array(); // used to remove duplicates
    while ($row = retrieveRow($res)) {
      $name = $row['first_name'] . ' ' . $row['last_name'];
      if (!isset($added[$name])) {
        $added[$name] = $name;
        $result = array($row['id'], $row['first_name'], $row['last_name']);
        array_push($curators, $result);
      }//not a duplicate name
    }
    
    disconnectFromCurationDB($conn);
    return $curators;
  }//getSuperCurators()
  
  
  /**
   * insertAlerts
   * 
   * @param $conn
   * @param $id
   * @param $alert_list
   * @param $data_type (type term name: 'POPcorn Resource' or 'POPcorn Project'
   * @param $auditTrailID
   * @return none
   */
  function insertAlerts($conn, $id, $alert_list, $data_type, $auditTrailID='') {
    $type_id = getDataTypeID($conn, $data_type);

    if ($id == -1 || $id == '' || $data_type == '' || $type_id == -1) {
      // Bail
      $err = "insertAlerts(): No resource/project id and/or data type for ";
      $err .= "inserting alerts.";
      reportErrorPC("$err\n");
      return;
    }
    
    // Need to preserve un-editable data in existing records, so this is 
    //   more complicated than other association records.
    
    $old_recs = getAlerts($conn, $id);
    $alrts = explode("||", $alert_list);
    
    // Copy e-mail dates from existing to (maybe) modified alerts
//logVarDumpPC($alrts, "Alert list generated from [$alert_list]:\n");
    $new_alerts = array();
    foreach ($alrts as $alrt) {
      // 0-day, 1-month, 2-year, 3-msg, 4-assigned-to, 5-handled, 6-auto_num
      $fields = explode("|", $alrt);
      $auto_num = $fields[count($fields)-1];  // auto_num is last
      
      // If alert already exists, get the email date unless the assigned
      //   curator has changed.
      if ($auto_num > 0) {
        $old_rec = getAlert($auto_num);
//logVarDumpPC($old_rec, "Old alert:\n");
        if (count($old_rec) > 0 && $old_rec['assigned'] == $fields[4]) {
          // assigned curator hasn't changed: keep email date
          $email_date = $old_rec['email_date'];
        }
        else {
          // assigned curator HAS changed: reset email date
          $email_date = null;
        }
      }
      else {
        // new alert: clear email date
        $email_date = null;
      }
      
      // Add date before auto_num (always last element in array)
      $new_rec = array_slice($fields, 0, count($fields)-1);
      array_push($new_rec, $email_date, $auto_num);
      $new_alerts[$auto_num] = $new_rec;
    }//foreach new alert
//logVarDumpPC($new_alerts, "New alerts:\n");

    // Clear out any existing ALERT records
    $sql = "DELETE FROM alert WHERE id=$id AND type_term=$type_id";
    $res = doMod_oracle($conn, $sql, array());
    if (!$res) {
      $err = "insertAlerts(): ";
      $err .= "Unable to complete the following statement:\n$sql";
      reportErrorPC("$err\n");
      // Need not be a fatal error?
    }
    
    if (trim($alert_list) == '') {
      // Nothing to add
      return;
    }

    // Insert records
    foreach ($new_alerts as $alrt) {
      // 0-yr, 1-mon, 2-day, 3-msg, 4-assigned, 5-handled, 6-autonum

      // Get auto_num if necessary
      $auto_num = $alrt[7];
      if ($auto_num < 0) {  // check for temp auto_num
        $auto_num = getAutoNum($conn, 'ALERT');
      }
      
      $alert_date = '';
      if (trim($alrt[0]) != '') {
        // NOTE: the date is split up like this to mirror date drop-downs
        //       in the add-alert box.
        $alert_date = $alrt[0] . '-' . $alrt[1] . '-' . $alrt[2];
        
        $sql = "INSERT INTO alert
                  (auto_num, id, type_term, alert_date, alert_msg, 
                   annotation_author_id, email_date, handled)
                VALUES
                  (:auto_num, :id, :type_id, 
                   TO_DATE(:alert_date, 'YYYY-MON-DD'),
                   :msg, :assigned, :email_date, :handled)";
      }
      else {
        // No trigger date
        $sql = "INSERT INTO alert
                  (auto_num, id, type_term, alert_msg, annotation_author_id, 
                   email_date, handled)
                VALUES
                  (:auto_num, :id, :type_id, :msg, :assigned, :email_date,
                   :handled)";
      }
      $res = doMod_oracle($conn, $sql,
         array(
            ':auto_num'   => $auto_num,  
            ':id'         => $id,
            ':type_id'    => $type_id,
            ':alert_date' => $alert_date,
            ':msg'        => $alrt[3],  
            ':assigned'   => $alrt[4],  
            ':email_date' => $alrt[6],
            ':handled'    => $alrt[5],
         )
      );
      if (!$res) {
        $msg = "insertAlerts(): ";
        $msg .= "Unable to complete the following insert statement:\n$sql";
        reportErrorPC("$msg\n");
      }
    }

    emailAlerts($conn, $new_alerts, $data_type, $id);
  }//insertAlerts()


  /**
   * insertBlast
   * 
   * @param $new_rec
   * @return int - id of newly-inserted record
   */
  function insertBlast($new_rec) {
    global $bfields;
    
    $data_type = 'POPcorn BLAST Target';
    
    $conn = connectToCurationDB();
    
    // Get an id number
    $id = createIdNum($conn, $data_type);

    if ($id == -1) {
      // Bail out
      reportErrorPC("insertBlast(): Unable to get an id for this record.\n");
      return -1;
    }

    // Do the insert
    $sql = "INSERT INTO pc_blast_ctl
              (id, blast_db_update, db_name, db_path, display_info, name, 
               short_name, results_url, source, type, web_service_url, 
               web_service_type, link, citation, notes, warning)
            VALUES
              (:id, 
               TO_DATE('" . $new_rec['blast_db_update'] . "', 'yyyy/mm/dd'), 
               :db_name, :db_path, :display_info, :name, :short_name,
               :results_url, :source, :type, :web_service_url, :web_service_type,
               :link, :citation, :notes, :warning)";
    $res = doMod_oracle($conn, $sql,
        array(':id'                  => $id,
              ':db_name'             => $new_rec['db_name'],
              ':db_path'             => $new_rec['db_path'],
              ':display_info'        => $new_rec['display_info'],
              ':name'                => $new_rec['name'],
              ':short_name'          => $new_rec['short_name'],
              ':results_url'         => $new_rec['results_url'],
              ':source'              => $new_rec['source'],
              ':type'                => $new_rec['type'],
              ':web_service_url'     => $new_rec['web_service_url'],
              ':web_service_type'    => $new_rec['web_service_type'],
              ':link'                => $new_rec['link'],
              ':citation'            => $new_rec['citation'],
              ':notes'               => $new_rec['notes'],
              ':warning'             => $new_rec['warning'],
        )
    );
    
    if (!$res) {
      $msg = "insertBlast(): ";
      $msg .= "Unable to complete the following insert statement:\n$sql";
      reportErrorPC("$msg\n");
    }

    insertCategories($conn, $id, $new_rec['category_list'], $data_type);
    
    insertRelatedResources($conn, $id, $new_rec['resource_list'], $data_type, 
                           'related-resource');
    insertRelatedProjects($conn, $id, $new_rec['project_list'], $data_type, 
                          'related-project');
    insertAlerts($conn, $id, $new_rec['alert_list'], $data_type);
    
    disconnectFromCurationDB($conn);
    return $id;
  }//insertBlast()
  
  
  /**
   * insertCategories
   * 
   * @param $conn
   * @param $id
   * @param $category_list
   * @param $data_type
   * @param $auditTrailID
   * @return none
   */
  function insertCategories(&$conn, $id, $category_list, $data_type, $auditTrailID='') {
    $type_id = getDataTypeID($conn, $data_type);

    if ($id == -1 || $id == '' || $data_type == '' || $type_id == -1) {
      // Bail
      $err = "(): No resource/project id and/or data type ";
      $err .= "for inserting category records.";
      reportErrorPC("$err\n");
      return;
    }
    
    // Clear out any existing PC_ASSOC_CATEGORY records
    $sql = "DELETE FROM pc_assoc_category WHERE id=$id AND type_term=$type_id";
    $res = doMod_oracle($conn, $sql, array( ));
    if (!$res) {
      $msg = "insertCategories(): ";
      $msg .= "Unable to complete the following statement:\n$sql";
      reportErrorPC("$msg\n");
      // Need not be a fatal error?
    }
    
    if (trim($category_list) == '') {
      // Nothing to add
      return;
    }

    // Insert records
    $cats = explode("||", $category_list);
    foreach ($cats as $cat) {
      // 0-name, 1-autonum
      $fields = explode("|", $cat); 

      // Get auto_num if necessary
      if ($fields[1] < 0) {
        $fields[1] = getAutoNum($conn, 'PC_ASSOC_CATEGORY');
      }
      
      // Get PC_CATEGORY id
      $category_id = getCategoryId($conn, $fields[0]);
      
      $sql = "INSERT INTO pc_assoc_category
                (auto_num, id, type_term, category_id)
              VALUES
                (:an,:id,:type_id,:category_id)";
      $res = doMod_oracle($conn, $sql,
         array(
            ':an'          => $fields[1],
            ':id'          => $id,
            ':type_id'     => $type_id,
            ':category_id' => $category_id,
         )
      );
      if (!$res) {
        $msg = "insertCategories(): ";
        $msg .= "Unable to complete the following insert statement:\n$sql";
        reportErrorPC("$msg\n");
      }
    }
  }//insertCategories()


  /**
   * insertCategory
   * 
   * @param $new_rec
   * @return int - id of newly-inserted record
   */
  function insertCategory($new_rec) {
    global $cfields;
    
    $data_type = 'POPcorn Category';
    
    $conn = connectToCurationDB();
    
    // Get an id number
    $id = createIdNum($conn, $data_type);

    if ($id == -1) {
      // Bail out
      reportErrorPC("insertCategory(): Unable to get an id for this record.\n");
      return -1;
    }

    // Do the insert
    $sql = "INSERT INTO pc_category
              (id, name, description)
            VALUES
              (:id,:category_name,:description)";
    $res = doMod_oracle($conn, $sql,
      array(
         ':id'            => $id,
         ':category_name' => $new_rec['category_name'],
         ':description'   => $new_rec['description'],
      )
    );
    
    if (!$res) {
      $msg = "insertCategory(): ";
      $msg .= "Unable to complete the following insert statement:\n$sql";
      reportErrorPC("$msg\n");
    }

    disconnectFromCurationDB($conn);
    return $id;
  }//insertCategory()
  
  
  /**
   * insertFunding
   * 
   * @param $conn
   * @param $id
   * @param $funding_list
   * @param $data_type
   * @param $auditTrailID
   * @return none
   */
  function insertFunding(&$conn, $id, $funding_list, $data_type, $auditTrailID='') {
    $type_id = getDataTypeID($conn, $data_type);

    if ($id == -1 || $id == '' || $data_type == '' || $type_id == -1) {
      // Bail
      $err = "insertFunding(): No resource/project id and/or data type for ";
      $err .= "inserting funding records.";
      reportErrorPC("$err\n");
      return;
    }
    
    if (is_numeric($auditTrailID) && $auditTrailID > 0) {
      // Record intended changes (note: assumes change will be successful)
      $cur_funding = getFunding($conn, $id);
      $new_funding = makeListStringArray($funding_list);
      captureChildChanges($conn, 'PC_ASSOC_FUNDING', $id, $cur_funding, 
                          $new_funding, $auditTrailID);
    }
    
    // Clear out any existing PC_ASSOC_FUNDING records
    $sql = "DELETE FROM pc_assoc_funding WHERE id=:id AND type_term=:type_id";
    $res = doMod_oracle($conn, $sql, array(':id'=>$id,':type_id'=>$type_id));
    if (!$res) {
      $err = "insertFunding(): ";
      $err .= "Unable to complete the following statement:\n$sql";
      reportErrorPC("$err\n");
      // Need not be a fatal error?
    }
    
    if (trim($funding_list) == '') {
      // Nothing to add
      return;
    }

    // Insert records
    $funds = explode("||", $funding_list);
    foreach ($funds as $fund) {
      // 0-url, 1-order, 2-source, 3-autonum, 4-keywords
      $fields = explode("|", $fund); 

      // Get auto_num if necessary
      if ($fields[4] < 0) {
        $fields[4] = getAutoNum($conn, 'PC_ASSOC_FUNDING');
      }
      
      // Get PERSON id for funding record
      $person_id = getPersonId($conn, $fields[2]);
      
      $sql = "INSERT INTO pc_assoc_funding
                (auto_num, id, type_term, person_id, url, ordering, keywords)
              VALUES
                (:f4,:id,:type_id,:person_id,:f0,:f1,:f3)";
      $res = doMod_oracle($conn, $sql,
         array(
            ':f4'        => $fields[4],
            ':id'        => $id,
            ':type_id'   => $type_id,
            ':person_id' => $person_id,
            ':f0'        => $fields[0],
            ':f1'        => $fields[1],
            ':f3'        => $fields[3],
         )
      );
      if (!$res) {
        $msg = "insertFunding(): ";
        $msg .= "Unable to complete the following insert statement:\n$sql";
        reportErrorPC("$msg\n");
      }
    }
  }//insertFunding()


  /**
   * insertInstitutions
   * 
   * @param $conn
   * @param $id
   * @param $institution_list
   * @param $data_type
   * @param $auditTrailID
   * @return none
   */
  function insertInstitutions(&$conn, $id, $institution_list, $data_type, $auditTrailID='') {
    $type_id = getDataTypeID($conn, $data_type);

    if ($id == -1 || $id == '' || $data_type == '' || $type_id == -1) {
      // Bail
      $err = "insertInstitutions(): No resource/project id and/or data type ";
      $err .= "for inserting institution records.";
      reportErrorPC($err);
      return;
    }
    
    // Clear out any existing PC_ASSOC_INSTITUTION records
    $sql = "DELETE FROM pc_assoc_institution WHERE ID=:id AND type_term=:type_id";
    $res = doMod_oracle($conn, $sql,array(':id'=>$id,':type_id'=>$type_id));
    if (!$res) {
      $msg = "insertInstitutions(): ";
      $msg .= "Unable to complete the following statement:\n$sql";
      reportErrorPC("$msg\n");
      // Need not be a fatal error?
    }
    
    if (trim($institution_list) == '') {
      // Nothing to add
      return;
    }

    // Insert records
    $insts = explode("||", $institution_list);
    foreach ($insts as $inst) {
      // 0-name, 1-order, 2-autonum
      $fields = explode("|", $inst); 

      // Get auto_num if necessary
      if ($fields[2] < 0) {
        $fields[2] = getAutoNum($conn, 'PC_ASSOC_INSTITUTION');
      }
      
      // Get PERSON id for institution record
      $person_id = getPersonId($conn, html_entity_decode($fields[0]));

      $sql = "INSERT INTO pc_assoc_institution
                (auto_num, id, type_term, person_id, ordering)
              VALUES
                (:f2,:id,:type_id,:person_id,:f1)";
      $res = doMod_oracle($conn, $sql,
         array(
            ':f2'        => $fields[2],
            ':id'        => $id,
            ':type_id'   => $type_id,
            ':person_id' => $person_id,
            ':f1'        => $fields[1],
         )
      );
      if (!$res) {
        $msg = "insertInstitutions(): ";
        $msg .= "Unable to complete the following insert statement:\n$sql";
        reportErrorPC("$msg\n");
      }
    }
  }//insertInstitutions()


  /**
   * insertInvestigators
   * 
   * @param $conn
   * @param $id
   * @param $investigator_list
   * @param $data_type
   * @param $auditTrailID
   * @return none
   */
  function insertInvestigators($conn, $id, $investigator_list, $data_type, $auditTrailID='') {
    $type_id = getDataTypeID($conn, $data_type);

    if ($id == -1 || $id == '' || $data_type == '' || $type_id == -1) {
      // Bail
      $err = "insertInvestigators(): No resource/project id and/or data type ";
      $err .= "for inserting investigator records.";
      reportErrorPC("$err\m");
      return;
    }
    
    // Clear out any existing PC_ASSOC_INVESTIGATOR records
    $sql = "DELETE FROM pc_assoc_investigator WHERE ID=:id AND type_term=:type_id";
    $res = doMod_oracle($conn, $sql,array(':id'=>$id,':type_id'=>$type_id));
    if (!$res) {
      $msg = "insertInvestigators(): ";
      $msg .= "Unable to complete the following statement:\n$sql";
      reportErrorPC("$msg\n");
      // Need not be a fatal error?
    }
    
    if (trim($investigator_list) == '') {
      // Nothing to add
      return;
    }

    // Insert records
    $invs = explode("||", $investigator_list);
    foreach ($invs as $inv) {
      // 0-name, 1-relationship, 2-order, 3-autonum
      $fields = explode("|", $inv); 
      
      // Get auto_num if necessary
      if ($fields[3] < 0) {
        $fields[3] = getAutoNum($conn, 'PC_ASSOC_INVESTIGATOR');
      }
      
      // Get PERSON id for investigator record
      $person_id = getPersonId($conn, $fields[0]);
      
      $sql = "INSERT INTO pc_assoc_investigator
                (auto_num, id, type_term, person_id, relationship, ordering)
              VALUES
                ($fields[3], $id, $type_id, $person_id, '$fields[1]', $fields[2])";
      $res = doMod_oracle($conn, $sql, array());
      if (!$res) {
        $msg = "insertInvestigators(): ";
        $msg .= "Unable to complete the following insert statement:\n$sql";
        reportErrorPC("$msg\n");
      }
    }
  }//insertInvestigators()


  /**
   * insertProject
   * 
   * @param $new_rec
   * @return int - id of newly-inserted record
   */
  function insertProject($new_rec) {
    global $pfields;
    
    $data_type = 'POPcorn Project';
    
    $conn = connectToCurationDB();
    
    // Get an id number
    $id = createIdNum($conn, $data_type);
    
    if ($id == -1) {
      // Bail out
      reportErrorPC("insertProject(): Unable to get an id for this record.\n");
      return -1;
    }

    // Do the insert
    $sql = "INSERT INTO pc_project
              (id, name, description, funding_period, notes)
            VALUES
              (:id,:project_name,:description,:funding_period,:notes)";
    $res = doMod_oracle($conn, $sql,
      array(
         ':project_name' => $new_rec['project_name'],
         ':description' => $new_rec['description'],
         ':funding_period' => $new_rec['funding_period'],
         ':notes' => $new_rec['notes'],
         ':id' => $id,
      )
    );
    if (!$res) {
      $msg = "insertProject(): ";
      $msg .= "Unable to complete the following insert statement:\n$sql";
      reportErrorPC("$msg\n");
    }

    insertFunding($conn, $id, $new_rec['funding_list'], $data_type);
    insertInstitutions($conn, $id, $new_rec['institution_list'], $data_type);
    insertInvestigators($conn, $id, $new_rec['investigator_list'], $data_type);
    insertCategories($conn, $id, $new_rec['category_list'], $data_type);
    insertAlerts($conn, $id, $new_rec['alert_list'], $data_type);
    insertRelatedResources($conn, $id, $new_rec['resource_list'], 'resource-of', 
                           $data_type);
    insertRelatedProjects($conn, $id, $new_rec['project_list'], 'related-project', 
                          $data_type);
    
    disconnectFromCurationDB($conn);
    return $id;
  }//insertProject()
  

  /**
   * insertRelatedProjects
   * 
   * @param $conn
   * @param $id
   * @param $project_list
   * @param $data_type
   * @param $auditTrailID
   * @return none
   */
  function insertRelatedProjects(&$conn, $id, $project_list, $data_type, $relationship='related-project', $auditTrailID='') {
logVarDumpPC($project_list, "insertRelatedProjects(): projects:\n");
    $prj_type_id = getDataTypeID($conn, $data_type);

    if ($id == -1 || $id == '') {
      // Bail
      $err = "insertRelatedProjects(): ";
      $err .= "No project id for inserting project association records.";
      reportErrorPC("$err\n");
      return;
    }
    
    // Clear out any existing PC_ASSOCIATION records between this project 
    //    and related projects
    $sql = "DELETE FROM pc_association 
            WHERE ID1=$id 
                  AND TYPE_TERM1=$prj_type_id
                  AND TYPE_TERM2=$prj_type_id";
    $res = doMod_oracle($conn, $sql, array());
    if (!$res) {
      $err = "insertRelatedProjects(): ";
      $err .= "Unable to complete the following statement:\n$sql";
      reportErrorPC("$err\n");
      // Need not be a fatal error?
    }
    
    if (trim($project_list) == '') {
      // Nothing to add
      return;
    }

    // Insert records
    $prjs = explode("||", $project_list);
    foreach ($prjs as $prj) {
      // 0-project name, 1-order, 2-auto_num
      $fields = explode("|", $prj); 

      // Get auto_num if necessary
      $auto_num = $fields[2];
      if ($auto_num < 0) {
        $auto_num = getAutoNum($conn, 'PC_ASSOCIATION');
      }
      
      // Get PC_RESOURCE id
      $prj_id = getProjectId($conn, $fields[0]);
      
      $sql = "INSERT INTO pc_association
                (auto_num, id1, type_term1, id2, type_term2, relationship, ordering)
              VALUES
                ($auto_num, $id, $prj_type_id, $prj_id, $prj_type_id, 'related-project', $fields[1])";
      $res = doMod_oracle($conn, $sql, array());
      if (!$res) {
        $err = "insertRelatedProjects(): ";
        $err .= "Unable to complete the following insert statement:\n$sql";
        reportErrorPC("$err\n");
      }
    }//each related project
  }//insertRelatedProjects
  

  /**
   * insertResource
   * 
   * @param $new_rec
   * @return int - id of newly-inserted record
   */
  function insertResource($new_rec) {   
    global $rfields;
    
    $data_type = 'POPcorn Resource';
    
    // Make sure the URL is unique
    if (!isUniqueURL($new_rec['url'])) {
      // Bail out
      reportErrorPC("URL (" . $new_rec['url'] . ") is not unique");
      return -1;
    }
    
    $conn = connectToCurationDB();
    
    // Get an id number
    $id = createIdNum($conn, $data_type);

    if ($id == -1) {
      // Bail out
      reportErrorPC("Unable to get an id for this record.");
      return -1;
    }

    // Do the insert
    $sql = "INSERT INTO pc_resource
              (id, name, description, url, tutorial, seq_based, notes)
            VALUES
              (:id, :resource_name, :description, :url, :tutorial, :seq_based, 
               :notes)" ;
    $res = doMod_oracle($conn, $sql, 
                 array(':id'            => $id,
                       ':resource_name' => $new_rec['resource_name'],
                       ':description'   => $new_rec['description'],
                       ':url'           => $new_rec['url'],
                       ':tutorial'      => $new_rec['tutorial'],
                       ':seq_based'     => $new_rec['seq_based'],
                       ':notes'         => $new_rec['notes']
                 ));
    
    if (!$res) {
      $msg = "insertResource(): ";
      $msg .= "Unable to complete the following insert statement:\n$sql";
      reportErrorPC("$msg\n");
    }

    insertFunding($conn, $id, $new_rec['funding_list'], $data_type);
    insertInstitutions($conn, $id, $new_rec['institution_list'], $data_type);
    insertInvestigators($conn, $id, $new_rec['investigator_list'], $data_type);
    insertCategories($conn, $id, $new_rec['category_list'], $data_type);
    insertAlerts($conn, $id, $new_rec['alert_list'], $data_type);
    
    disconnectFromCurationDB($conn);
    return $id;
  }//insertResource()
  
  
  /**
   * insertRelatedResources
   * 
   * @param $conn
   * @param $id
   * @param $resource_list
   * @param $auditTrailID
   * @return none
   */
  function insertRelatedResources(&$conn, $id, $resource_list, $data_type, $relationship='resource-of', $auditTrailID='') {
    $prj_type_id = getDataTypeID($conn, $data_type);
    $rsrc_type_id = getDataTypeID($conn, 'POPcorn Resource');

    if ($id == -1 || $id == '') {
      // Bail
      $err = "insertRelatedResources(): No project id for inserting resource ";
      $err .= "association records.";
      reportErrorPC("$err\n");
      return;
    }
    
    // Clear out any existing PC_ASSOCIATION records between this project
    //   and related resources.
    $sql = "DELETE FROM pc_association 
            WHERE id1=:id 
                  AND type_term1=:prj_type_id 
                  AND type_term2=:rsrc_type_id";
    $res = doMod_oracle($conn, $sql, 
                 array(':id' => $id,
                       ':prj_type_id' => $prj_type_id,
                       ':rsrc_type_id' => $rsrc_type_id,
                 ));
    if (!$res) {
      $msg = "insertRelatedResources(): ";
      $msg .= "Unable to complete the following statement:\n$sql";
      reportErrorPC("$msg\n");
      // Need not be a fatal error?
    }
    
    if (trim($resource_list) == '') {
      // Nothing to add
      return;
    }

    // Insert records
    $rsrcs = explode("||", $resource_list);
    foreach ($rsrcs as $rsrc) {
      // 0-resource name, 1-order, 2-auto_num
      $fields = explode("|", $rsrc); 

      // Get auto_num if necessary
      $auto_num = $fields[2];
      if ($auto_num < 0) {
        $auto_num = getAutoNum($conn, 'PC_ASSOCIATION');
      }
      
      // Get PC_RESOURCE id
      $rsrc_id = getResourceId($conn, $fields[0]);
      
      $sql = "INSERT INTO pc_association
                (auto_num, id1, type_term1, id2, type_term2, relationship, ordering)
              VALUES
                ($auto_num, $id, $prj_type_id, $rsrc_id, $rsrc_type_id, 'resource-of', $fields[1])";
      $res = doMod_oracle($conn, $sql, array());
      if (!$res) {
        $msg = "insertRelatedResources(): ";
        $msg .= "Unable to complete the following insert statement:\n$sql";
        reportErrorPC("$msg\n");
      }
    }//each resource
  }//insertRelatedResources()


  /**
   * insertSearch
   * 
   * @param $new_rec
   * @return int - id of newly-inserted record
   */
  function insertSearch($new_rec) {
    global $sfields;

    $data_type = 'POPcorn Search';
    
    $conn = connectToCurationDB();
    
    // Get an id number
    $id = createIdNum($conn, $data_type);

    if ($id == -1) {
      // Bail out
      reportErrorPC("insertSearch(): Unable to get an id for this record.\n");
      return -1;
    }

    // Do the insert
    $sql = "INSERT INTO pc_search_ctl
              (id, type, name, short_name, helper_script, blast_source, 
               blast_database, blast_target_type, entrez, process, 
               view_hit_record_url, citation, notes, warning)
            VALUES
              (:id, :type, :name, :short_name, :helper_script, :blast_source, 
               :blast_database, :blast_target_type, :entrez, :process,
               :view_hit_record_url, :citation, :notes, :warning)";
    $res = doMod_oracle($conn, $sql,
        array(':id'                  => $id,
              ':type'                => $new_rec['search_type'],
              ':name'                => $new_rec['name'],
              ':short_name'          => $new_rec['short_name'],
              ':helper_script'       => $new_rec['helper_script'],
              ':blast_source'        => $new_rec['blast_source'],
              ':blast_database'      => $new_rec['blast_database'],
              ':blast_target_type'   => $new_rec['blast_target_type'],
              ':entrez'              => $new_rec['entrez'],
              ':process'             => $new_rec['process'],
              ':view_hit_record_url' => $new_rec['view_hit_record_url'],
              ':citation'            => $new_rec['citation'],
              ':notes'               => $new_rec['notes'],
              ':warning'             => $new_rec['warning'],
        )
    );

    if (!$res) {
      $msg = "insertSearch(): ";
      $msg .= "Unable to complete the following insert statement:\n$sql";
      reportErrorPC("$msg\n");
    }

    insertCategories($conn, $id, $new_rec['category_list'], $data_type);
    insertRelatedResources($conn, $id, $new_rec['resource_list'], $data_type, 
                           'related-resource');
    insertRelatedProjects($conn, $id, $new_rec['project_list'], $data_type, 
                          'related-project');
    insertAlerts($conn, $id, $new_rec['alert_list'], $data_type);
    
    disconnectFromCurationDB($conn);
    return $id;
  }//insertSearch()
  
  
  /**
   * isSafeUrl
   * 
   * Borrowed from Drupal's valid_url
   * http://api.drupal.org/api/function/valid_url/6
   * 
   * @param $url
   * @return unknown_type
   */
  function isSafeUrl($url, $absolute=FALSE) {
   
     if ($absolute) {
       return (bool)preg_match("
         /^                                                      # Start at the beginning of the text
         (?:ftp|https?):\/\/                                     # Look for ftp, http, or https schemes
         (?:                                                     # Userinfo (optional) which is typically
           (?:(?:[\w\.\-\+!$&'\(\)*\+,;=]|%[0-9a-f]{2})+:)*      # a username or a username and password
           (?:[\w\.\-\+%!$&'\(\)*\+,;=]|%[0-9a-f]{2})+@          # combination
         )?
         (?:
           (?:[a-z0-9\-\.]|%[0-9a-f]{2})+                        # A domain name or a IPv4 address
           |(?:\[(?:[0-9a-f]{0,4}:)*(?:[0-9a-f]{0,4})\])         # or a well formed IPv6 address
         )
         (?::[0-9]+)?                                            # Server port number (optional)
         (?:[\/|\?]
           (?:[\w#!:\.\?\+=&@$'~*,;\/\(\)\[\]\-]|%[0-9a-f]{2})   # The path and query (optional)
         *)?
       $/xi", $url);
     }
     else {
       return (bool)preg_match("/^(?:[\w#!:\.\?\+=&@$'~*,;\/\(\)\[\]\-]|%[0-9a-f]{2})+$/i", $url);
     }   
  }//isSafeUrl
  
  
  /**
   * isUniqueName
   * 
   * Find out if the project name is unique
   * 
   * @security - this data is straight off the pipe, see addResourceComplete()
   * so filter the name for valid chars.
   * @todo - Regex needs formalization.
   * 
   * @param $name
   * @param $table
   * @param $is_new
   * @return bool - complicated.  
   * If caller asserts it is_new, return confirmation.
   * Otherwise, return true if only 1 or 0 records exist now.
   * 
   */
  function isUniqueName($name, $table, $is_new=false) {
    $conn = connectToCurationDB();
    $name = getSafeName($name);
    $sql = "SELECT COUNT(*) num_records FROM $table WHERE name='$name'";
    $res = makeQuery_oracle($conn, $sql);
    $row = retrieveRow($res);
    $num_records = $row['num_records'];
    disconnectFromCurationDB($conn);
    
    if ($is_new)
      return ($num_records == 0);
    else
      return ($num_records <= 1);
  }//isUniqueName()
  
  
  /**
   * isUniqueURL
   * 
   * Determines if the URL already exists in the database.
   * 
   * @security - data comes straight off the query string.  Sanitization attempts have been made.
   * 
   * @param $url
   * @return bool - true if no record already exists
   */
  function isUniqueURL($url) {
    $conn = connectToCurationDB();
    
    // Note: 'www' is normally optional in URLs.
    $alt_url = (preg_match("/\/\/www\./", $url)) ?
                  preg_replace("/\/\/www\./", "//", $url) :
                  preg_replace("/\/\//", "//www.", $url);

    // Note: trailing '/' is optional in URLs
    $url2 = (preg_match("/\/$/", $url)) ? 
                preg_replace("/\/$/", "", $url) : $url . "/";
    $alt_url2 = (preg_match("/\/$/", $alt_url)) ? 
                preg_replace("/\/$/", "", $alt_url) : $alt_url . "/";

    $sql = "SELECT COUNT(*) num_records FROM pc_resource 
            WHERE url='$url' OR url='$alt_url' OR url='$url2' OR url='$alt_url2'";
    $res = makeQuery_oracle($conn, $sql);
    $row = retrieveRow($res);
    $num_records = $row['num_records'];
    
    disconnectFromCurationDB($conn);
    return ($num_records == 0);
  }//isUniqueURL()
  
  
  /**
   * @param $str
   * @return unknown_type
   */
  function prepSQLString($str) {
    $new_str = urldecode($str);
    $new_str = stripslashes($str);
    $new_str = preg_replace("/'/", "''", $new_str);
    $new_str = preg_replace("/&/", "'||Chr(38)||'", $new_str);
    return $new_str;
  }//prepSQLString
  
  
  /**
   * saveAuditFieldRecord
   * 
   * Record a field change.
   *
   * @param $conn
   * @param $auditTrailID
   * @param $field
   * @param $table
   * @param $old_value
   * @param $new_value
   * @param $change_type
   * @return bool - success/failure
   */
  function saveAuditFieldRecord($conn, $auditTrailID, $field, $table, $old_value, $new_value, $change_type) {
    $auto_num = getAutoNum($conn, 'AUDIT_FIELDS');
    
    $sql = "SELECT id_num FROM audit_trail WHERE audit_trail_id=$auditTrailID";
    $res = makeQuery_oracle($conn, $sql);
    $row = retrieveRow($res);
    $id_num = $row['id_num'];
    
    $sql = "INSERT INTO audit_fields
              (audit_trail_id, field_name, table_name, record_id, old_value, 
               current_value, change_type, auto_num)
            VALUES
              (:auditTrailID, :field_name, :table_name, :auditTrailID, 
               :old_value, :new_value, :change_type, :auto_num)";
    $res = doMod_oracle($conn, $sql, 
                 array(':auditTrailID' => $auditTrailID,
                       ':field_name'   => $field,
                       ':table_name'   => $table,
                       ':auditTrailID' => $auditTrailID,
                       ':old_value'    => truncate($old_value, 999),
                       ':new_value'    => truncate($new_value, 999),
                       ':change_type'  => $change_type,
                       ':auto_num'     => $auto_num,
                      ));
    if (!$res) {
      $msg = "saveAuditFieldRecord(): ";
      $msg .= "Unable to complete the following insert statement:\n$sql";
      reportErrorPC("$msg\n");
       return false;
    }
    else
      return true;
    
  }//saveAuditFieldRecord()
  
  
  /**
   * setAlertStatus
   * 
   * @param $id
   * @param $auto_num
   * @param $handled
   * @return none
   */
  function setAlertStatus($id, $auto_num, $handled) {
    $conn = connectToCurationDB();

    $annotatorID = $_SESSION['annotatorID'];
    $type = 'POPcorn Alert';
    $results = getAlert($auto_num);
    $old_handled = $results[6];
    
    if ($old_handled != $handled) {
       $auditTrailID = getAuditTrailRecord($conn, $type, $id, $annotatorID);
      if ($old_handled != $handled) {
        saveAuditFieldRecord($conn, $auditTrailID, 'NOTES', 'ALERT', 
                             $old_handled, $handled, 'M');
      }
      
      $auto_num=intval($auto_num);
      $id=intval($id);
      // Make sure 'handled' value is valid
      if ($handled != 'yes' && $handled != 'no') {
        reportErrorPC("setAlertStatus(): Invalid 'handled' value: $handled");
        return;
      }
      
      // Change 'handled' value
      $sql = "UPDATE alert SET handled='$handled' WHERE auto_num=$auto_num";
      $res = doMod_oracle($conn, $sql);
      if (!$res) {
        reportErrorPC("setAlertStatus(): Unable to complete statement:\n$sql");
      }
    }
    
    disconnectFromCurationDB($conn);
  }//setAlertStatus()


  function setEmailDate($auto_num, $use_conn=null) {
    $conn = ($use_conn == null) ? connectToCurationDB() : $use_conn;
    
    $sql = "UPDATE alert 
            SET email_date = SYSTIMESTAMP
            WHERE auto_num = :auto_num";
    doMod_oracle($conn, $sql, array(':auto_num' => $auto_num));
    
    if ($use_conn == null) {
      // connection opened here, so close it here:
      disconnectFromCurationDB($conn);
    }
  }//setEmailDate()
  
  
  /**
   * setRecordNotes
   * 
   * Insert notes into table.
   * 
   * @param $conn
   * @param $id
   * @param $tabletype
   * @param $notes
   * @return unknown_type
   */
  function setRecordNotes($conn, $id, $tabletype, $notes) {
    $table = ($tabletype == 'Resource') ? 'PC_RESOURCE' : 'PC_PROJECT';
    $annotatorID = $_SESSION['annotatorID'];
    $type = getRecordType($id, $conn);
    $old_notes = getRecordNotes($conn, $id, $tabletype);

    if ($old_notes != $notes) {
       $auditTrailID = getAuditTrailRecord($conn, $type, $id, $annotatorID);
      if ($old_notes != $notes) {
        saveAuditFieldRecord($conn, $auditTrailID, 'NOTES', $table, 
                             $old_notes, $notes, 'M');
      }
  
      $sql = "UPDATE $table SET notes=:notes WHERE ID=:id";
      doMod_oracle($conn, $sql, array(":notes" => $notes, ":id" => $id));
    }
  }//setRecordNotes
  
  
  /**
   * updateBlast
   * @param $id
   * @param $new_rec
   * @return unknown_type
   */
  function updateBlast($id, $new_rec) {
    $data_type = 'POPcorn BLAST Target';
    $annotatorID = intval($_SESSION['annotatorID']);
    
    $conn = connectToCurationDB();
    
    if ($id < 1 || $id == '') {
      // Bail out
      reportErrorPC("updateBlast(): ID was lost for this BLAST target record.");
      return -1;
    }
    
    // Get an audit_trail record
    $auditTrailID = getAuditTrailRecord($conn, $data_type, $id, $annotatorID);

    // Record intended changes (note: assumes change will be successful)
    captureParentChanges($conn, 'PC_BLAST_CTL', $id, $new_rec, $auditTrailID);
    
    // Do the insert
    $sql = "UPDATE pc_blast_ctl
            SET
              blast_db_update  = TO_DATE('" . $new_rec['blast_db_update'] . "', 'yyyy/mm/dd'),
              db_name          = '".$new_rec['db_name']."',
              db_path          = '".$new_rec['db_path']."',
              display_info     = '".$new_rec['display_info']."',
              name             = '".$new_rec['name']."',
              short_name       = '".$new_rec['short_name']."',
              results_url      = '".$new_rec['results_url']."',
              source           = '".$new_rec['source']."',
              type             = '".$new_rec['type']."',
              web_service_url  = '".$new_rec['web_service_url']."',
              citation         = '".$new_rec['citation']."',
              web_service_type = '".$new_rec['web_service_type']."',
              make_image       = '".$new_rec['make_image']."',
              make_track       = '".$new_rec['make_track']."',
              link             = '".$new_rec['link']."',
              notes            = '".$new_rec['notes']."',
              warning          = '".$new_rec['warning']."'
            WHERE id = $id";
    $res = doMod_oracle($conn, $sql, array());
    if (!$res) {
      $msg = "updateBlast(): ";
      $msg .= "Unable to complete the following insert statement:\n$sql";
      reportErrorPC("$msg\n");
    }
    
    // Update ID_NUM record
    $now_date = date('d-M-Y H:i:s');

    $sql = "UPDATE id_num SET
              mod_by=$annotatorID,
              mod_date=TO_DATE('$now_date', 'DD-MON-YYYY HH24:MI:SS')
            WHERE id=$id";
    $res = doMod_oracle($conn, $sql, array());
    if (!$res) {
      $msg = "updateBlast(): ";
      $msg .= "Unable to update ID_NUM record with this statement:\n$sql";
      $msg .= "\n using $id, $annotatorID, $now_date";
      reportErrorPC("$msg\n");
      return -1;
    }

    insertCategories($conn, $id, $new_rec['category_list'], $data_type);
    insertRelatedResources($conn, $id, $new_rec['resource_list'], $data_type, 
                           'related-resource', $auditTrailID);
    insertRelatedProjects($conn, $id, $new_rec['project_list'], $data_type, 
                          'related-project', $auditTrailID);
    insertAlerts($conn, $id, $new_rec['alert_list'], $data_type, 
                 $auditTrailID);
    
    disconnectFromCurationDB($conn);
    return $id;
  }//updateBlast()
  
  
  /**
   * updateCategory
   * 
   * @param $id
   * @param $new_rec
   * @return int - id of updated record or -1 if error
   */
  function updateCategory($id, $new_rec) {
    $data_type = 'POPcorn Category';
    $annotatorID = intval($_SESSION['annotatorID']);
    
    $conn = connectToCurationDB();
    
    if ($id < 1 || $id == '') {
      // Bail out
      reportErrorPC("updateCategory(): ID was lost for this category record.");
      return -1;
    }
    
    // Get an audit_trail record
    $auditTrailID = getAuditTrailRecord($conn, $data_type, $id, $annotatorID);

    // Record intended changes (note: assumes change will be successful)
    captureParentChanges($conn, 'PC_CATEGORY', $id, $new_rec, $auditTrailID);
    
    // Do the insert
    $sql = "UPDATE pc_category
            SET
              name = :category_name,
              description = :description
            WHERE id = :id";
    $res = doMod_oracle($conn, $sql,
      array(
         ':id'            => $id,
         ':category_name' => $new_rec['category_name'],
         ':description'   => $new_rec['description'],
      )
    );
    if (!$res) {
      $msg = "updateCategory(): ";
      $msg .= "Unable to complete the following insert statement:\n$sql";
      reportErrorPC("$msg\n");
    }
    
    // Update ID_NUM record
    $now_date = date('d-M-Y H:i:s');
    $sql = "UPDATE id_num SET
              mod_by=$annotatorID,
              mod_date=TO_DATE('$now_date', 'DD-MON-YYYY HH24:MI:SS')
            WHERE ID=$id";
     $res = doMod_oracle($conn, $sql, array());
    if (!$res) {
      $msg = "updateCategory(): ";
      $msg .= "Unable to update ID_NUM record with this statement:\n$sql";
      reportErrorPC("$msg\n");
      return -1;
    }

    disconnectFromCurationDB($conn);
    return $id;
  }//updateCategory()
  
  
  /**
   * updateProject
   * 
   * @param $id
   * @param $new_rec
   * @return int - id of updated record or -1 if error
   */
  function updateProject($id, $new_rec) {
    $data_type = 'POPcorn Project';
    $annotatorID = $_SESSION['annotatorID'];
    
    $conn = connectToCurationDB();
    
    if ($id < 1 || $id == '') {
      // Bail out
      reportErrorPC("updateProject() ID was lost for this project record.\n");
      return -1;
    }
    
    // Get an audit_trail record
    $auditTrailID = getAuditTrailRecord($conn, $data_type, $id, $annotatorID);

    // Record intended changes (note: assumes change will be successful)
    captureParentChanges($conn, 'PC_PROJECT', $id, $new_rec, $auditTrailID);
    
    // Do the insert
    $sql = "UPDATE pc_project
            SET
              name           = '" . prepSQLString($new_rec['project_name']). "',
              description    = '" . prepSQLString($new_rec['description']) . "',
              funding_period = '" . prepSQLString($new_rec['funding_period']) . "',
              notes          = '" . prepSQLString($new_rec['notes']) . "'
            WHERE id = $id";
    $res = doMod_oracle($conn, $sql, array());
    if (!$res) {
      $msg = "updateProject(): ";
      $msg .= "SQL error:\n" . print_r(oci_error(), true);
      $msg .= "\nUnable to complete the following insert statement:\n$sql";
      reportErrorPC("$msg\n");
    }
    
    // Update ID_NUM record
    $now_date = date('d-M-Y H:i:s');
logMessagePC("updateProject(): date is $now_date");
    $sql = "UPDATE id_num SET
              mod_by='$annotatorID',
              mod_date=TO_DATE('$now_date', 'DD-MON-YYYY HH24:MI:SS')
            WHERE id=$id";
    $res = doMod_oracle($conn, $sql, array());
    if (!$res) {
      $msg = "updateProject(): ";
      $msg .= "Unable to update ID_NUM record with this statement:\n$sql";
      reportErrorPC("$msg\n");
      return -1;
    }

    insertFunding($conn, $id, $new_rec['funding_list'], $data_type, 
                  $auditTrailID);
    insertInstitutions($conn, $id, $new_rec['institution_list'], $data_type, 
                       $auditTrailID);
    insertInvestigators($conn, $id, $new_rec['investigator_list'], $data_type, 
                        $auditTrailID);
    insertCategories($conn, $id, $new_rec['category_list'], $data_type, 
                     $auditTrailID);
    insertRelatedResources($conn, $id, $new_rec['resource_list'], $data_type, 
                           'resource-of', $auditTrailID);
    insertRelatedProjects($conn, $id, $new_rec['project_list'], $data_type, 
                          'related-project', $auditTrailID);
    insertAlerts($conn, $id, $new_rec['alert_list'], $data_type, 
                 $auditTrailID);
    
    disconnectFromCurationDB($conn);
    return $id;
  }//updateProject()
  

  /**
   * updateResource
   * 
   * @param $id
   * @param $new_rec
   * @return int - id of updated record, -1 if error
   */
  function updateResource($id, $new_rec) {   
    $data_type = 'POPcorn Resource';
    $annotatorID = $_SESSION['annotatorID'];

    $conn = connectToCurationDB();
    
    if ($id < 1 || $id == '') {
      // Bail out
      reportErrorPC("ID was lost for this resource record.");
      return -1;
    }
    
    // Get an audit_trail record
    $auditTrailID = getAuditTrailRecord($conn, 'POPcorn Resource', $id, 
                                         $annotatorID);
logMessagePC("updateResource() got audit trail record $auditTrailID");
    
    // Record intended changes (note: assumes change will be successful)
    captureParentChanges($conn, 'PC_RESOURCE', $id, $new_rec, $auditTrailID);
logMessagePC("updateResource() captured parent changes");
    
    // Do the insert
    $sql = "UPDATE pc_resource
            SET
              name = :resource_name,
              description = :description,
              url = :url,
              tutorial = :tutorial,
              seq_based = :seq_based,
              note = :notes
            WHERE id = :id";
    $res = doMod_oracle($conn, $sql,
      array(
         ":id" => $id,
         ":resource_name" => $new_rec['resource_name'],
         ":description"   => $new_rec['description'],
         ":url"           => $new_rec['url'],
         ":tutorial"      => $new_rec['tutorial'],
         ":seq_based"     => $new_rec['seq_based'],
         ":notes"         => $new_rec['notes'],
      )
    );
logMessagePC("updateResource() did the update");
    if (!$res) {
      $msg = "updateResource(): ";
      $msg .= "Unable to complete the following update statement on connection";
      reportErrorPC("$msg: $conn:\n$sql\n");
    }
    
    // Update ID_NUM record
    $now_date = date('d-M-Y H:i:s');
    $sql = "UPDATE id_num SET
              mod_by=$annotatorID,
              mod_date=TO_DATE('$now_date', 'DD-MON-YYYY HH24:MI:SS')
            WHERE ID=$id";
    $res = doMod_oracle($conn, $sql,array());
logMessagePC("updateResource() updated mod_date:\n$sql");
    if (!$res) {
      $msg = "updateResource(): ";
      $msg .= "Unable to update ID_NUM record with this statement:\n$sql";
      reportErrorPC("$msg\n");
      return -1;
    }

logMessagePC("updateResource() insert funding");
    insertFunding($conn, $id, $new_rec['funding_list'], $data_type, 
                  $auditTrailID);
    insertInstitutions($conn, $id, $new_rec['institution_list'], $data_type, 
                       $auditTrailID);
    insertInvestigators($conn, $id, $new_rec['investigator_list'], $data_type, 
                        $auditTrailID);
    insertCategories($conn, $id, $new_rec['category_list'], $data_type, 
                     $auditTrailID);
    insertAlerts($conn, $id, $new_rec['alert_list'], $data_type, 
                 $auditTrailID);
    
    disconnectFromCurationDB($conn);
    return $id;
  }//updateResource()
  
  
  /**
   * updateSearch
   * @param $id
   * @param $new_rec
   * @return unknown_type
   */
  function updateSearch($id, $new_rec) {
    $data_type = 'POPcorn Search';
    $annotatorID = intval($_SESSION['annotatorID']);
    
    $conn = connectToCurationDB();
    
    if ($id < 1 || $id == '') {
      // Bail out
      reportErrorPC("updateSearch(): ID was lost for this search record.");
      return -1;
    }
    
    // Get an audit_trail record
    $auditTrailID = getAuditTrailRecord($conn, $data_type, $id, $annotatorID);

    // Record intended changes (note: assumes change will be successful)
    captureParentChanges($conn, 'PC_SEARCH_CTL', $id, $new_rec, $auditTrailID);
    
    // Do the insert
    $sql = "UPDATE pc_search_ctl
            SET
              name              = '".prepSQLString($new_rec['name'])."',
              short_name        = '".prepSQLString($new_rec['short_name'])."',
              type              = '".$new_rec['search_type']."',
              helper_script     = '".$new_rec['helper_script']."',
              blast_source      = '".$new_rec['blast_source']."',
              blast_database    = '".$new_rec['blast_database']."',
              blast_target_type = '".$new_rec['blast_target_type']."',
              entrez            = '".prepSQLString($new_rec['entrez'])."',
              process           = '".prepSQLString($new_rec['process'])."',
              view_hit_record_url = '".$new_rec['view_hit_record_url']."',
              citation          = '".prepSQLString($new_rec['citation'])."',
              notes             = '".prepSQLString($new_rec['notes'])."',
              warning           = '".prepSQLString($new_rec['warning'])."'
              WHERE id = $id";
    $res = doMod_oracle($conn, $sql, array());
    if (!$res) {
      $msg = "updateSearch(): ";
      $msg .= "Unable to complete the following insert statement:\n$sql";
      reportErrorPC("$msg\n");
    }
    
    // Update ID_NUM record
    $now_date = date('d-M-Y H:i:s');
    $sql = "UPDATE id_num SET
              mod_by=$annotatorID,
              mod_date=TO_DATE('$now_date', 'DD-MON-YYYY HH24:MI:SS')
            WHERE id=$id";
    $res = doMod_oracle($conn, $sql, array());
    if (!$res) {
      $msg = "updateSearch(): ";
      $msg .= "Unable to update ID_NUM record with this statement:\n$sql";
      reportErrorPC("$msg\n");
      return -1;
    }

    insertCategories($conn, $id, $new_rec['category_list'], $data_type);
    insertRelatedResources($conn, $id, $new_rec['resource_list'], $data_type, 
                           'related-resource', $auditTrailID);
    insertRelatedProjects($conn, $id, $new_rec['project_list'], $data_type, 
                          'related-project', $auditTrailID);
    insertAlerts($conn, $id, $new_rec['alert_list'], $data_type, 
                 $auditTrailID);
    
    disconnectFromCurationDB($conn);
    return $id;
  }//updateSearch()
  
  
  /**
   * verifyLogin
   * 
   * Verifies that session vars are valid.
   * 
   * @security:  makes assumption that SQL sourced session var is clean
   * 
   * @return bool - is valid login
   */
  function verifyLogin() {
    $valid_login = false;
    $conn = connectToCurationDB();
    // User must be logged in and have a curator level of at least -5
    if (isset($_SESSION['annotatorID']) && $_SESSION['annotatorID'] != '') {
      $sql = "SELECT curation_lvl 
              FROM annotation_author 
              WHERE id = " . $_SESSION['annotatorID'];
      $res = makeQuery_oracle($conn, $sql);
      $row = retrieveRow($res);
      if (!$row || $row['curation_lvl'] <= -5) {
        $valid_login = true;
      }
    }
    
    disconnectFromCurationDB($conn);
    return $valid_login;
  }//verifyLogin()
  
  
  /**
   * deleteRecords
   * 
   * OBSOLETE!
   * 
   * @param $ids array of record IDs
   * @return unknown_type
   */
/*** MaizeGDB policy is to not actually delete any records but set curation
   level to 99 (trash)
   
  function deleteRecords($ids) {
    $conn = connectToCurationDB();
    
    // Check id array
    if (count($ids) == 0) {
      return;
    }
    // Make sure no ids are blank
    for ($i=0; $i<count($ids); $i++) {
      if (trim($ids[$i]) == '')
        $ids[$id] = '---';
    }
    
    // Assume all ids are of the same type; get the type
    $type = getRecordType($ids[0], $conn);
    $idstr = join(',', $ids);
    
    // delete associated categories
    $sql = "DELETE FROM PC_ASSOC_CATEGORY WHERE ID IN ($idstr)";
//echo "<br>$sql<br>";
    $res = doMod_oracle($conn, $sql);
    if (!$res) {
      reportErrorPC("deleteRecords(): Unable to complete statement:\n$sql\n");
    }
    
    // delete associated funding
    $sql = "DELETE FROM PC_ASSOC_FUNDING WHERE ID IN ($idstr)";
//echo "<br>$sql<br>";
    $res = doMod_oracle($conn, $sql);
    if (!$res) {
      reportErrorPC("deleteRecords(): Unable to complete statement:\n$sql\n");
    }

    // delete associated institution
    $sql = "DELETE FROM PC_ASSOC_INSTITUTION WHERE ID IN ($idstr)";
//echo "<br>$sql<br>";
    $res = doMod_oracle($conn, $sql);
    if (!$res) {
      reportErrorPC("deleteRecords(): Unable to complete statement:\n$sql\n");
    }

    // delete associated people
    $sql = "DELETE FROM PC_ASSOC_INVESTIGATOR WHERE ID IN ($idstr)";
//echo "<br>$sql<br>";
    $res = doMod_oracle($conn, $sql);
    if (!$res) {
      reportErrorPC("deleteRecords(): Unable to complete statement:\n$sql\n");
    }
    
    // delete associated resources and projects
    $sql = "DELETE FROM PC_ASSOCIATION WHERE ID1 IN ($idstr)";
//echo "<br>$sql<br>";
    $res = doMod_oracle($conn, $sql);
    if (!$res) {
      reportErrorPC("deleteRecords(): Unable to complete statement:\n$sql\n");
    }
    
    // delete record
    $table = ($type == 'POPcorn Resource') ? 'PC_RESOURCE' : 'PC_PROJECT';
    $sql = "DELETE FROM $table WHERE ID IN ($idstr)";
//echo "<br>$sql<br>";
    $res = doMod_oracle($conn, $sql);
    if (!$res) {
      reportErrorPC("deleteRecords(): Unable to complete statement:\n$sql\n");
    }

    disconnectFromCurationDB($conn);
  }//deleteRecords
*/


?>
