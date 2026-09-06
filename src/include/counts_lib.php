<?php
/* file: counts_lib.php
 *
 * purpose: counts requested records
 *
 * history:
 *  03/06/13  eksc  created from ajax script getCounts.php
 */

/*eksc- it appears that this file is never being call via Ajax, and
        is only used as a lib. Because the switch statement may be 
        inappropriatelyexecuted because of 'id' or 'type' CGI params sent to 
        parent page,remove the switch statement.  (07/10/13)
        
  $id   = getCGIParam('id', 'GP', false);
  $type = getCGIParam('type', 'GP', false);
  
  $DBConn = connect_to_database();

  if ($id && $type) {
    // Called from Ajax; echo return from requested function
    switch ($type) {
      case "BACs":
        echo countBACs($id);
        break;
      case "ESTs":
        echo countESTs($id);
        break;
      case "Gel patterns":
        echo countGelPatterns($id);
        break;
      case "Gene products":
        echo countGeneProducts($id);
        break;
      case "Maps":
        echo countMaps($id);
        break;
      case "Map scores":
        echo countMapScores($id);
        break;
      case "Overgos":
        echo countOvergos($id);
        break;
      case "Phenotypes":
        echo countPhenotypes($id);
        break;
      case "Primers":
        echo countPrimers($id);
        break;
      case "Probes":
        echo countProbes($id);
        break;
      case "Recombination":
        echo countRecombinations($id);
        break;
      case "Related BACs":
        echo countRelatedBACs($id);
        break;
      case "Related loci":
        echo countRelatedLoci($id);
        break;
      case "SSRs":
        echo countSSRs($id);
        break;
      case "Variations":
        echo countVariations($id);
        break;
    }//switch
  }//$id and $type set
*/  
  
function countBACs($id) {
  global $DBConn;
  
  $query = "
    SELECT A.PROBE_ID 
    FROM LOCUS_DETECTED_BY A 
      JOIN ID_NUM B ON A.PROBE_ID = B.ID 
      JOIN probe c on c.id = A.PROBE_ID
    WHERE B.CURATION_LVL = 0 AND A.ID = " . (int) $id . " AND c.TYPE = 171715";
  $statement = make_query($DBConn, $query);
  $rows = get_all_rows($statement);
  $count = ($rows) ? count($rows) : 0;
  
  return $count;
}//countBACs()


function countESTs($id) {
  global $DBConn;
  
  $query = "
    SELECT A.PROBE_ID 
    FROM LOCUS_DETECTED_BY A 
      JOIN ID_NUM B ON A.PROBE_ID = B.ID 
      JOIN probe c on c.id = A.PROBE_ID   
    WHERE B.CURATION_LVL = 0 AND A.ID = " . (int) $id . " AND c.TYPE = 34";
  $statement = make_query($DBConn, $query);
  $rows = get_all_rows($statement);
  $count = ($rows) ? count($rows) : 0;
  
  return $count;
}//countESTs()


function countGelPatterns($id) {
  global $DBConn;
  
	$query = "
	  SELECT A.ID
	  FROM GEL_PATTERN A 
	    LEFT OUTER JOIN ID_NUM B ON A.ID = B.ID 
	    LEFT OUTER JOIN LOCUS_DETECTED_BY C ON A.PROBE = C.PROBE_ID 
	  WHERE C.ID = " . (int) $id;
  $statement = make_query($DBConn, $query);
  $rows = get_all_rows($statement);
  $count = ($rows) ? count($rows) : 0;
  
  return $count;
}//countGelPatterns()


function countGeneProducts($id) {
  global $DBConn;
  
  $query = "
    SELECT B.NAME 
    FROM LOCUS_GENE_PRODUCTS A, GENE_PRODUCT B, ID_NUM C 
    WHERE A.ID = $id AND A.GENE_PRODUCT = B.ID AND B.ID = C.ID 
          AND C.CURATION_LVL = 0";
  $statement = make_query($DBConn, $query);
  $rows = get_all_rows($statement);
  $count = ($rows) ? count($rows) : 0;
  
  return $count;
}//countGeneProducts()


function countMaps($id) {
  global $DBConn;
  
  $query = "
    SELECT A.BIN 
    FROM LOCUS_COORDINATES A, ID_NUM B, MAP C 
    WHERE A.ID = " . (int) $id . " AND A.MAP = B.ID AND B.CURATION_LVL = 0 AND A.MAP = C.ID"; 
  $statement = make_query($DBConn, $query);
  $rows = get_all_rows($statement);
  $count = ($rows) ? count($rows) : 0;
  
  return $count;
}//countMaps()


function countMapScores($id) {
  global $DBConn;
  
  $query = "
    SELECT A.ID
    FROM MAP_SCORES A, ID_NUM B 
    WHERE A.PROBED_SITE = " . (int) $id . " AND A.ID = B.ID AND B.CURATION_LVL = 0";
  $statement = make_query($DBConn, $query);
  $rows = get_all_rows($statement);
  $count = ($rows) ? count($rows) : 0;
  
  return $count;
}//countMapScores()


function countOffSiteResources($id) {
  global $DBConn;
  
  $query = "
    SELECT A.KEY 
    FROM EXT_DB_KEY A, PERSON B, PERSON_URL_PREFIX C, ID_NUM D 
    WHERE A.ID = $id AND A.DB_PERSON = B.ID AND B.ID = C.ID AND B.ID = D.ID 
          AND D.CURATION_LVL = 0 AND A.DB_PERSON <> 9021469";
  $statement = make_query($DBConn, $query);
  $rows = get_all_rows($statement);
  $count = ($rows) ? count($rows) : 0;
  
  return $count;
}//countOffSiteResources()


function countOvergos($id) {
  global $DBConn;
  
  $query = "
    SELECT A.PROBE_ID 
    FROM LOCUS_DETECTED_BY A 
      JOIN ID_NUM B ON A.PROBE_ID = B.ID 
      JOIN probe c ON c.id = A.PROBE_ID   
    WHERE B.CURATION_LVL = 0 AND A.ID = " . (int) $id . " AND c.TYPE = 393660";
  $statement = make_query($DBConn, $query);
  $rows = get_all_rows($statement);
  $count = ($rows) ? count($rows) : 0;
logMessage("count: [$count]");
  
  return $count;
}//countOvergos()


function countPhenotypes($id) {
  global $DBConn;
  
  $query = "
    SELECT DISTINCT(c.id)
    FROM variation a 
      LEFT OUTER JOIN var_pheno_effects b ON a.id = b.id 
      LEFT OUTER JOIN phenotype c ON b.pheno_effect = c.id 
      LEFT OUTER JOIN id_num d ON a.id = d.id 
      LEFT OUTER JOIN id_num e ON c.id = e.id 
    WHERE a.variationof = " . (int) $id . " AND d.curation_lvl = 0 AND e.curation_lvl = 0";
  $statement = make_query($DBConn, $query);
  $rows = get_all_rows($statement);
  $count = ($rows) ? count($rows) : 0;
  
  return $count;
}//countPhenotypes()


function countPrimers($id) {
  global $DBConn;
  
  $query = "
    SELECT D.SEQUENCE
    FROM LOCUS_DETECTED_BY A, PROBE B, PROBE_SOURCE_DNA C, PRIMER D 
    WHERE A.ID = $id AND A.PROBE_ID = B.ID AND B.ID = C.ID 
          AND C.ENZYME_PRIMER = D.ID";
  $statement = make_query($DBConn, $query);
  $rows = get_all_rows($statement);
  $count = ($rows) ? count($rows) : 0;
  
  return $count;
}//countPrimers()


function countProbes($id) {
  global $DBConn;
  
  $query = "
    SELECT A.PROBE_ID 
    FROM LOCUS_DETECTED_BY A 
      JOIN ID_NUM B ON A.PROBE_ID = B.ID 
      JOIN probe c on c.id = A.PROBE_ID   
    WHERE B.CURATION_LVL = 0 AND A.ID = $id 
          AND (c.TYPE != 171715 AND c.TYPE != 393660 AND c.TYPE != 104436 
               AND c.TYPE != 34)";
  $statement = make_query($DBConn, $query);
  $rows = get_all_rows($statement);
  $count = ($rows) ? count($rows) : 0;
  
  return $count;
}//countProbes()


function countRecombinations($id) {
  global $DBConn;
  
	$query = "
	  SELECT ID 
	  FROM ((SELECT B.ID, B.NAME 
	         FROM RECOMB_LOCI_2 A, RECOMB B, ID_NUM C 
	         WHERE A.LOCUS = $id AND A.ID = B.ID AND B.ID = C.ID 
	               AND C.CURATION_LVL = 0 
	         UNION 
	         SELECT B.ID, B.NAME 
	         FROM RECOMB_LOCI A, RECOMB B, ID_NUM C 
	         WHERE A.LOCUS = $id AND A.ID = B.ID AND B.ID = C.ID 
	               AND C.CURATION_LVL = 0) 
	        UNION (SELECT B.ID, B.NAME 
	               FROM RECOMB_FREQ A, RECOMB B, ID_NUM C 
	               WHERE A.BEFORE = $id AND A.ID = B.ID AND B.ID = C.ID 
	                     AND C.CURATION_LVL = 0 
	               UNION 
	               SELECT B.ID, B.NAME 
	               FROM RECOMB_FREQ A, RECOMB B, ID_NUM C 
	               WHERE A.AFTER = $id AND A.ID = B.ID AND B.ID = C.ID 
	                     AND C.CURATION_LVL = 0)) as sub1";
  $statement = make_query($DBConn, $query);
  $rows = get_all_rows($statement);
  $count = ($rows) ? count($rows) : 0;
  
  return $count;
}//countRecombinations()


function countRelatedBACs($id) {
  global $DBConn;
  
  $query = "
      SELECT DISTINCT(a.id) 
      FROM probe a 
        JOIN relation r ON r.related_id = a.id 
        JOIN id_num i ON a.id = i.id 
        WHERE a.type = 171715 
              AND r.id IN (SELECT probe_id FROM locus_detected_by WHERE id=$id) 
              AND i.curation_lvl = 0";
	$statement = make_query($DBConn, $query);
  $rows = get_all_rows($statement);
  $count = ($rows) ? count($rows) : 0;
  
  return $count;
}//countRelatedBACs()


function countRelatedLoci($id) {
  global $DBConn;
  
	$query = "
	  SELECT C.ID
	  FROM LOCUS C, RELATION A, ID_NUM B 
	  WHERE A.RELATED_ID = B.ID AND B.ID = C.ID AND B.CURATION_LVL = 0 
	        AND A.ID = " . (int) $id;
  $statement = make_query($DBConn,$query);
  $rows = get_all_rows($statement);
  $count = ($rows) ? count($rows) : 0;
  
  return $count;
}//countRelatedLoci()


function countSSRs($id) {
  global $DBConn;
  
  $query = "
    SELECT A.PROBE_ID 
    FROM LOCUS_DETECTED_BY A 
      JOIN ID_NUM B ON A.PROBE_ID = B.ID 
      JOIN probe c on c.id = A.PROBE_ID   
    WHERE B.CURATION_LVL = 0 AND A.ID = " . (int) $id . " AND c.TYPE = 104436";
  $statement = make_query($DBConn, $query);
  $rows = get_all_rows($statement);
  $count = ($rows) ? count($rows) : 0;
  
  return $count;
}//countSSRs()


function countVariations($id) {
  global $DBConn;
  
  $query = "
    SELECT A.ID
    FROM VARIATION A, ID_NUM B, TERM C
    WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND A.TYPE = C.ID 
          AND A.VARIATIONOF = " . (int) $id;
  $statement = make_query($DBConn, $query);
  $rows = get_all_rows($statement);
  $count = ($rows) ? count($rows) : 0;
  
  return $count;
}//countVariations()
?>
