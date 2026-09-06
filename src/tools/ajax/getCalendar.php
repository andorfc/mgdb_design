<?php
/* file: getCalendar.php
 *
 * purpose: get date of indicated data object; called via Ajax
 *
 * NOTE: THIS APPEARS TO BE UNUSED.
 *
 */

  include_once("../../include/db-api.php");
  include_once("../../include/gp_lib.php");
  
// Get system configuration
$system = getSystemInfo('mgdb.conf');

  $id = $_GET["id"];
  $type = $_GET["type"];

  /* Every column this file filters on -- id_num.id, locus.id and
     variation.variationof -- is bigint, checked in information_schema. The
     three queries below interpolated the raw request value straight into SQL:
     ?id=zzz reached Postgres as `WHERE ID = zzz` and came back
     `column "zzz" does not exist`, which is an unauthenticated injection on an
     endpoint that answers 200. Fixed 2026-09-06.

     The 2026-09-05 audit passed over this file, recording it as dead code
     because its one commented-out query sits in a `no longer supported` block.
     Three live sites sit outside that block. */
  $iid = (int) $id;
logMessage("getCalendar.php - id: $id, type: $type");
  $DBConn = connect_to_database();//OCILogon(DB_USER,DB_PASS,DB_NAME);

  if ($type == "allele") {
    $query = "
      SELECT TO_CHAR(max(mod_date),'mm/dd/yyyy') as VDATE 
      from id_num a 
        join variation b on a.id = b.id 
      where B.VARIATIONOF = " . $iid;
    $stmt = make_query($DBConn,$query);
    $arr_date = retrieve_row($stmt);
  } 
  else if ($type == "reference") {
  $query = "
    select TO_CHAR(max(mod_date),'mm/dd/yyyy') as VDATE 
    from locus a 
      join ID_REFERENCE b on a.ID = b.ID 
      left join ID_NUM z on z.ID = b.REFERENCE 
    WHERE a.ID = " . $iid ;
    $stmt = make_query($DBConn,$query);
    $arr_date = retrieve_row($stmt);
  } 
  else if ($type == "overview") {
    $queryd1 = "
      select TO_CHAR(max(mod_date),'mm/dd/yyyy') as VDATE 
      from ID_NUM WHERE ID = " . $iid ;
    $statementd1 = make_query($DBConn,$queryd1);
    $arrSeqd1 = retrieve_row($statementd1);

    $arrSeqd5 = array();
/* no longer supported
    $queryd5 = "
      select TO_CHAR(max(date_entered),'mm/dd/yyyy') as VDATE 
      from audit_fields a 
        join za_gene_models b on a.current_value = b.gene_id 
        join audit_trail c on a.audit_trail_id = c.audit_trail_id 
      where field_name = 'KEY' and table_name = 'EXT_DB_KEY' 
            and record_id = '" . $id . "'";
    $statementd5 = make_query($DBConn,$queryd5);
    $arrSeqd5 = retrieve_row($statementd5);
*/
    
    $todaytime = strtotime("now");
    
    if (isset($arrSeqd1["DATE01"])) {
      $diff1 = intval(abs($todaytime - strtotime($arrSeqd1["VDATE"])) / 86400);  // calculate number of days
    } 
    else {
      $diff1 = 10000;
    }
    
    if (isset($arrSeqd5["DATE05"])) {
      $diff5 = intval(abs($todaytime - strtotime($arrSeqd5["VDATE"])) / 86400);  // calculate number of days
    }
    else {
      $diff5 = 10000;
    }

    $earliest = min($diff1, $diff5);
    
    if (isset($arrSeqd1["VDATE"]) && $earliest == $diff1) {
      $arr_date["VDATE"] = $arrSeqd1["VDATE"];
    } 
    
    else if (isset($arrSeqd5["VDATE"])) {
      $arr_date["VDATE"] = $arrSeqd5["VDATE"];
    }
  }
logVarDump($arr_date, "Data object age:\n");

  if ($arr_date && count($arr_date) > 0 && isset($arr_date['vdate']) 
        && $arr_date['vdate'] != '') {
    echo "<img src='/tools/calendar/calendar_date.php?date=" . $arr_date["VDATE"] . "'>";
  }
  else {
    echo '';
  }
?>