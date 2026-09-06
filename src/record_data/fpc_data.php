<?PHP
/* file: fpc_data.php
 *
 * purpose: display the various sections of a FPC Contig record; called via Ajax
 *
 * test URL: 
 *
 * history:
 *  1/16/12  jportwood  created
 *
 * ------------> NO LONGER IN USE <-------------
 *
 */

  include_once('../lib/Bauplan.php');
  include_once("../include/db-api.php");
  include_once("../include/api_tools.php");
  include_once('../include/gp_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $id   = getCGIParam("id", 'G', false);
  $type = getCGIParam("type", 'G', false);

  
  logMessage("fpc_data.php: id=$id, type=$type");
  
  if (!$id) {
    reportError("No id given to fpc_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to fpc_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/fpc_sections.bau');
  
  $DBConn = connect_to_database();

  // Clean up input typed by user
  $id = validate_input($DBConn, $id); 

  // RI-1039, call before the functions of each section - ktcho
  $tmpl->get('gbrowse_url_bac')->replace($system['GBROWSE_URL_BAC']);
  $tmpl->get('gbrowse_img_url_bac')->replace($system['GBROWSE_IMG_URL_BAC']);

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
     case 'bac_colored_key':
      $tmpl->get('bac_color_key')->unmute(); //only raw html in this section
      break;
    case 'sequenced_bacs':
      show_sequenced_bacs($tmpl, $id, $DBConn);
      break;
    case 'probes_loci_maps':
      show_probes_loci_maps($tmpl, $id, $DBConn);
      break;
    case 'recombination':
      show_recombination($tmpl, $id, $DBConn);
      break;
  }

  $bauplan->publish();
  
  
  
  function show_top($tmpl, $id, $DBConn)
  {   
    $tmpl->get('name')->replace($id);
    $tmpl->get('top')->unmute();
  }//showTop
  
  
  function show_overview($tmpl, $id, $DBConn) {
    $query = "
     SELECT ACC, CLONE_NAME, CHR, CHR_START, CHR_END, CONTIG_START, 
            CONTIG_END, CONTIG, SEQ, c.ID as clone_id 
     FROM ZA_FPCCONTIG a  
       JOIN ID_SEQ b ON b.KEY = a.ACC  
       JOIN PROBE c ON c.NAME = a.CLONE_NAME 
     WHERE LOWER(CONTIG) = " . $DBConn->quote($id) . " 
     ORDER BY CHR_START";
    $stmt = make_query($DBConn,$query,1);
    $arrNum = retrieve_row($stmt);
    
    $queryPos = "
     SELECT MAX(CHR_END), MIN(CHR_START) 
     from ZA_FPCCONTIG 
     WHERE LOWER(CONTIG) = " . $DBConn->quote($id) ;
    $stmtPos = make_query($DBConn,$queryPos,1);
    $arrNumPos = retrieve_row($stmtPos);
    
    $no_overview = true;
    $tmpl->get('id')->replace($id); 

/*maizesequence.org is long gone    
    // 1083647 = person record for 'Maize B73 Genome Sequencing Project Contig 2005'
    $prefix_query = "SELECT URL_PREFIX FROM PERSON_URL_PREFIX WHERE ID = 1083647";
    $stmt_prefix = make_query($DBConn,$prefix_query,1);
    $arrPrefix = retrieve_row($stmt_prefix);
    $arrPrefix['url_prefix'] = trim($arrPrefix['url_prefix']);
    
    if ($arrPrefix['url_prefix'] != '') {
    $tmpl->get('maize_seq_url')->replace($arrPrefix['url_prefix'] . $id);
*/    
    if (strlen($arrNum["chr"]) > 0)
    {
      $tmpl->get('num_chr')->replace($arrNum["chr"]);
      $tmpl->get('chrom')->unmute();
    }
    if (strlen($arrNumPos["MIN"]) > 0)
    {
      $tmpl->get('chrom_start')->replace(number_format($arrNumPos["MIN"], 0, '.', ','));
      $tmpl->get('chrom_start_sec')->unmute();
    }
    if (strlen($arrNumPos["MAX"]) > 0)
    {
      $tmpl->get('chrom_end')->replace(number_format($arrNumPos["MAX"], 0, '.', ','));
      $tmpl->get('chrom_end_sec')->unmute();
    }
    
    $tmpl->get('overview')->unmute();
  }//showOverview


  function showAnnotations($tmpl, $id, $DBConn) {
    $annotations = '';
    
    if(substr(trim($id),0,3) == "ctg")
    {
        $id = substr(trim($id),3);
    }
    
    $query_find_user_annotations = "
      SELECT A.AUTO_NUM, A.MEMO, A.MOD_DATE, B.ID, B.FIRST_NAME, B.LAST_NAME, 
             B.USERNAME, B.PASSWORD 
      FROM ANNOTATION A, ANNOTATION_AUTHOR B 
      WHERE A.ANN_AUTHOR_ID = B.ID AND A.ID = " . (int) $id . " AND B.CURATION_LVL = 0 
            AND A.CURATION_LVL < 2 
      ORDER BY A.MOD_DATE";
    $stmt_user_annotations = make_query($DBConn, $query_find_user_annotations);
    $arrAnnotations = get_all_rows($stmt_user_annotations);
    if (!$arrAnnotations || count($arrAnnotation) == 0) {
      $annotations = '<b>&nbsp;&nbsp;No annotations found for this FPC Contig</b>';
    }
    else {
      for ($i=0; $i<count($arrAnnotations); $i++) {
        $annotations .= "<b><a href=\"displayannotatorrecord.cgi?id=" 
                      . $arrAnnotations["ID"] . "\">" 
                      . trim($arrAnnotations["FIRST_NAME"]) . " " 
                      . trim($arrAnnotations["LAST_NAME"]) 
                      . "</a></b> (<i>" 
                      . $arrAnnotations["MOD_DATE"] . "</i>)<br>\n";
        $annotations .= "<span style=\"margin-left: 10px;\">" 
                      . $arrAnnotations["MEMO"] . "</span>\n";
                      
        if (($arrAnnotations["ID"] == $userid) 
                && ($arrAnnotations["USERNAME"] == $username) 
                && ($arrAnnotations["PASSWORD"] == $password)) {
          $annotations .= "<br><i>"
                        . "<a target=\"new\" href=\"edit_seq_annotation.cgi?id=" 
                        . $arrAnnotations["AUTO_NUM"] 
                        . "\">Edit this annotation!</a></i>\n";
        }
        $annotations .= "<br>\n";
      }//each record
    }//found annotations
    
    $tmpl->get('annotation-list')->replace($annotations);
    $tmpl->get('annotations')->unmute();
  }//showAnnotations


  function show_sequenced_bacs($tmpl, $id, $DBConn) 
  {
    $query = "
     SELECT ACC, CLONE_NAME, CHR, CHR_START, CHR_END, CONTIG_START, CONTIG_END, CONTIG, SEQ, c.ID as clone_id 
     from ZA_FPCCONTIG a  
     join ID_SEQ b ON b.KEY = a.ACC  
     join PROBE c ON c.NAME = a.CLONE_NAME 
     WHERE LOWER(CONTIG) = " . $DBConn->quote($id) . " 
     ORDER BY CHR_START";
    $stmt = make_query($DBConn,$query);
    $arrNum = retrieve_row($stmt);
    
    $prev_seq = "";
    $sequenced_results = array();
    $count = 0;
    while(strlen($arrNum["CONTIG"]) > 0)
    {
      if ($arrNum["seq"] != $prev_seq)
      {
        $prev_seq = $arrNum["seq"];
        $sequenced_results[$count]['clone_id'] = $arrNum["clone_id"];
        $sequenced_results[$count]['clone_name'] = $arrNum["clone_name"];
        $sequenced_results[$count]['seq'] = $arrNum["seq"];
        $sequenced_results[$count]['acc'] = $arrNum["acc"];
        
        $sequenced_results[$count]['contig_start'] = "<b>Contig Start:</b> " 
                                                   . number_format($arrNum["CONTIG_START"], 0, '.', ',')
                                                   . "<br>";
                                                   
        $sequenced_results[$count]['contig_end'] = "<b>Contig End:</b> " 
                                                   . number_format($arrNum["CONTIG_END"], 0, '.', ',')
                                                   . "<br>";
                                                   
        $sequenced_results[$count]['chr'] = "<b>Chromosome:</b> " 
                                                   . number_format($arrNum["CHR"], 0, '.', ',')
                                                   . "<br>";
                                                   
        $sequenced_results[$count]['chr_start'] = "<b>Chromosome Start:</b> " 
                                                   . number_format($arrNum["CHR_START"], 0, '.', ',')
                                                   . "<br>";
                                                   
        $sequenced_results[$count]['chr_end'] = "<b>Chromosome End:</b> " 
                                                   . number_format($arrNum["CHR_END"], 0, '.', ',')
                                                   . "<br>";
        
        
        $count++;
        
      }
      $arrNum = retrieve_row($stmt);
    }
    
    if ($sequenced_results && count($sequenced_results) > 0)
      $tmpl->get('sequenced_sec')->loop($sequenced_results);
    else
      $tmpl->get('no_sequenced')->toggle();
      
    $tmpl->get('sequenced_bacs')->unmute();
  }
  
  function show_probes_loci_maps($tmpl, $id, $DBConn)
  {
    $loci_list = "";
    $other_bacs = read_other_bacs($DBConn, $id);
    if ($other_bacs && count($other_bacs) > 0)
    {
      $tmpl->get('show_bacs')->loop($other_bacs['show']); //show only the first 10 BACs initially
      $tmpl->get('hide_bacs')->loop($other_bacs['hide']); 
      $tmpl->get('other_bacs_sec')->unmute();
    }
    
    $detected_probes = read_detected_probes($DBConn, $id);
    if ($detected_probes && count($detected_probes) > 0)
    {
      $tmpl->get('detected_probes')->loop($detected_probes); 
      $tmpl->get('detected_probes_sec')->unmute();
    }
    $tmpl->get('probes_loci_maps')->unmute();
    $detected_loci = read_detected_loci($DBConn, $id);
    if ($detected_loci && count($detected_loci) > 0)
    {
      $loci_list = $detected_loci[0]['list'];
      unset($detected_loci[0]['list']);
      
      $tmpl->get('detected_loci')->loop($detected_loci); 
      $tmpl->get('detected_loci_sec')->unmute();
    }
    
    $map_coordinates = read_map_coords($DBConn, $id, $loci_list);
    if ($map_coordinates && count($map_coordinates) > 0)
    {
      $tmpl->get('map_coords_sec')->unmute();
      $tmpl->get('map_table_data')->replace($map_coordinates); 
    }
  }//show_probes_loci_maps
  
  
  function show_recombination($tmpl, $id, $DBConn)
  {
    $show_recomb = false;
    $saved_vals = array(); //save values for re-use in below queries
    $recomb_loci = read_recomb_loci($DBConn, $id); //loci with larger bounded coord range
    if ($recomb_loci && count($recomb_loci) > 0)
    {
      $saved_vals['mapval'] = $recomb_loci[0]['map_val'];
      $saved_vals['maxval'] = $recomb_loci[0]['max_val'];
      $saved_vals['minval'] = $recomb_loci[0]['min_val'];
      
      $tmpl->get('map_val')->replace($saved_vals['mapval']);
      $tmpl->get('max_val')->replace($saved_vals['maxval']);
      $tmpl->get('min_val')->replace($saved_vals['minval']);
      $tmpl->get('lid')->replace($recomb_loci[0]['lid']);
      
      unset($recomb_loci[0]['map_val']); //Destroy values that we don't need for the loop
      unset($recomb_loci[0]['max_val']);
      unset($recomb_loci[0]['min_val']);
      
      $tmpl->get('loci_table_data')->replace($recomb_loci[0]['table_string']);
      //$tmpl->get('recomb_loci')->loop($recomb_loci); 
      $tmpl->get('recomb_loci_sec')->unmute();
      $show_recomb = true;
    }
    
    $recomb_loci_2 = read_recomb_loci_2($DBConn, $id); //loci with smaller bounded coord range
    if ($recomb_loci_2 && count($recomb_loci_2) > 0)
    {
      $tmpl->get('map_val2')->replace($recomb_loci_2[0]['map_val2']);
      $tmpl->get('max_val_2')->replace($recomb_loci_2[0]['max_val_2']);
      $tmpl->get('min_val_2')->replace($recomb_loci_2[0]['min_val_2']);
      $tmpl->get('map_lid2')->replace($recomb_loci_2[0]['map_lid2']);
      $tmpl->get('gen_id')->replace($recomb_loci_2[0]['map_val2']);  
     
      unset($recomb_loci_2[0]['map_val2']); //Destroy values that we don't need for the loop
      unset($recomb_loci_2[0]['max_val_2']);
      unset($recomb_loci_2[0]['min_val_2']);
      unset($recomb_loci_2[0]['gen_id']);
      unset($recomb_loci_2[0]['map_lid2']);
     
      $tmpl->get('recomb_loci_2')->loop($recomb_loci_2);
      $tmpl->get('recomb_loci_sec')->unmute();
      $show_recomb = true;
    }
    
    $recomb_phenotypes = read_recomb_pheno($DBConn, $id, $saved_vals);
    if ($recomb_phenotypes && count($recomb_phenotypes) > 0)
    {
      $tmpl->get('recomb_pheno')->loop($recomb_phenotypes); 
      $tmpl->get('map_val')->replace($saved_vals['mapval']);
      $tmpl->get('recomb_pheno_sec')->unmute();
      $show_recomb = true;
    }
    
    $recomb_probes = read_recomb_probe($DBConn, $id, $saved_vals);
    if ($recomb_probes && count($recomb_probes) > 0)
    {
      //$tmpl->get('recomb_probe')->loop($recomb_probes); 
      $tmpl->get('probe_data_table')->replace($recomb_probes);
      $tmpl->get('map_val')->replace($saved_vals['mapval']);
      $tmpl->get('recomb_probe_sec')->unmute();
      $show_recomb = true;
    }
    
    if ($show_recomb === false)
      $tmpl->get('no_recombination')->unmute();
     
    $tmpl->get('recombination')->unmute();
  }
  
  
  /****************************************************
   ********************HELPER METHODS******************
   ****************************************************/
  
  function read_other_bacs($DBConn, $id)
  {
    $idrb = "";
    if(substr(trim($id),0,3) == "ctg")
    {
      $idrb = substr(trim($id),3);
    }
    
    $query_rb = "
     select a.id as BID, a.name as BNAME 
     from probe a, id_num idn
     where a.type = 171715 and a.id IN 
      (
        SELECT b.ID 
        FROM EXT_DB_KEY b 
        WHERE b.KEY = " . $DBConn->quote($idrb) . " AND b.DB_PERSON = 758495 
      )
	  AND a.ID = idn.id
	  AND idn.curation_lvl = 0";
    $stmt_rb = make_query($DBConn,$query_rb);
    $arrRB= retrieve_row($stmt_rb);

    $count = 0;
    $show_bacs = array();
    $hide_bacs = array();
    while(strlen($arrRB["bid"]))
    {
      if ($count < 10) //show only the first 10 BACs initially
      {
        $show_bacs[$count]['show_id'] = $arrRB['bid'];
        $show_bacs[$count]['show_name'] = $arrRB['bname'];
      }
      else
      {
        $hide_bacs[$count]['hide_id'] = $arrRB['bid'];
        $hide_bacs[$count]['hide_name'] = $arrRB['bname'];
      }
      $count++;
      $arrRB = retrieve_row($stmt_rb);
    }
    $all_bacs = array();
    $all_bacs['show'] = $show_bacs;
    $all_bacs['hide'] = $hide_bacs;    
    
    return $all_bacs;
  }
  
  
  function read_detected_probes($DBConn, $id)
  {
    $query = "
     select r.relation, r.related_id, r.method, p.name as PNAME, p.id as PID 
     from probe p, relation r, id_num i, za_fpccontig z 
     WHERE LOWER(Z.CONTIG) = " . $DBConn->quote($id) . " and z.CLONE_NAME = p.NAME 
       and r.ID = p.ID and r.related_id = i.id and i.curation_lvl = 0";
    $stmt_rel_probes = make_query($DBConn,$query);
    $arrRelatedProbes = retrieve_row($stmt_rel_probes);
    $count = 0;
    $probes_results = array();
    while(strlen($arrRelatedProbes["RELATED_ID"]) > 0) 
    {
      $probes_results[$count]['pid'] = $arrRelatedProbes["PID"];
      $probes_results[$count]['pname'] = trim($arrRelatedProbes["PNAME"]);
         
      if($arrRelatedProbes["RELATION"] == "129778")
        $probes_results[$count]['relation'] = " contains ";
      else if($arrRelatedProbes["RELATION"] == "129779")
        $probes_results[$count]['relation'] = " is contained by ";
      else if($arrRelatedProbes["RELATION"] == "640505")
        $probes_results[$count]['relation'] = " is linked to ";
      else if($arrRelatedProbes["RELATION"] == "403541")
        $probes_results[$count]['relation'] = " is detected by ";
      else
        $probes_results[$count]['relation'] = " detects ";
      
      $query2 = "select name, type from probe where id = " . $arrRelatedProbes["RELATED_ID"];
      $stmt2 = make_query($DBConn,$query2,1);
      $arrName = retrieve_row($stmt2);
      if($arrName["TYPE"] == "171715")
      {
        $probes_results[$count]['p2_type'] = "BAC";
        $probes_results[$count]['p2_url'] = "bac";
      }          
      else if($arrName["TYPE"] == "34")
      {
        $probes_results[$count]['p2_type'] = "EST";
        $probes_results[$count]['p2_url'] = "est";
      }
      else if(($arrName["TYPE"] == "393660") || ($arrName["TYPE"] == "747274"))
      {
        $probes_results[$count]['p2_type'] = "Overgo";
        $probes_results[$count]['p2_url'] = "overgo";
      }
      else if($arrName["TYPE"] == "104436")
      {
        $probes_results[$count]['p2_type'] = "SSR";
        $probes_results[$count]['p2_url'] = "ssr";
      }
      else
      {
        $probes_results[$count]['p2_type'] = "Probe";
        $probes_results[$count]['p2_url'] = "marker";
      }
      $probes_results[$count]['p2_id'] = $arrRelatedProbes["RELATED_ID"];
      $probes_results[$count]['p2_name'] = trim($arrName["NAME"]);
      $arrRelatedProbes = retrieve_row($stmt_rel_probes);  
      $count++;          
    }
    
    return $probes_results;
  }
  
  
  function read_detected_loci($DBConn, $id)
  {
    $query_loci = "
     SELECT L.ID, M.NAME 
     FROM  probe p, za_fpccontig z, LOCUS_DETECTED_BY L, ID_NUM I, TERM M 
     WHERE I.ID = L.ID AND L.METHOD = M.ID AND I.CURATION_LVL = 0 
       AND L.PROBE_ID = p.ID AND z.CLONE_NAME = p.NAME and LOWER(Z.CONTIG) = " . $DBConn->quote($id) ;
    $stmt_loci = make_query($DBConn,$query_loci,5);
    $arrLoci = retrieve_row($stmt_loci);
    $locus_results = array();
    $count = 0;
      
    while(strlen($arrLoci["ID"]) > 0)
    {
      if ($count == 0) //Add loci to a list to be used by map coords section
       $locus_results[0]['list'] = $arrLoci['id']; 
      else
       $locus_results[0]['list'] .= ", " . $arrLoci['id'];
       
      $query_locus = "SELECT name,full_name,type FROM LOCUS WHERE ID = " . $arrLoci["ID"];
      $stmt_locus = make_query($DBConn,$query_locus);
      $arrLocus = retrieve_row($stmt_locus);
      
      $locus_results[$count]['loc_id'] = $arrLoci["ID"];
      $locus_results[$count]['loc_name'] = $arrLocus["name"];
      $locus_results[$count]['full_name'] = $arrLocus["full_name"];
    
      $query_type = "SELECT name FROM TERM WHERE ID = " . $arrLocus["TYPE"];
      $stmt_type = make_query($DBConn,$query_type,1);
      $arrType = retrieve_row($stmt_type);
    
      $locus_results[$count]['type_name'] = $arrType["name"];
    
      $arrLoci = retrieve_row($stmt_loci);
      $count++;
    } 
    return $locus_results;
  }
  
  function read_map_coords($DBConn, $id, $locus_list)
  {
    $idtemp = $id;
    if(substr(trim($id),0,3) == "ctg")
    {
      $idtemp = substr(trim($id),3);
    }
  
    $contig_query2 = "
    SELECT MAX(d.VALUE) as MAXVAL, MIN(d.VALUE) as MINVAL  
    FROM LOCUS e, LOCUS_COORDINATES d , MAP g 
    WHERE  e.ID = d.ID AND 
     (d.MAP = 1140201  OR d.MAP = 1140202  OR d.MAP = 1140203  OR d.MAP = 1140204  
      OR d.MAP = 1140205  OR d.MAP = 1140206 OR d.MAP = 1140207  OR d.MAP = 1140208  
      OR d.MAP = 1140209 OR d.MAP = 1140210) 
      AND g.id = d.MAP AND e.ID IN 
      (
       SELECT a.ID as PROBE_ID 
       FROM EXT_DB_KEY a 
       WHERE a.KEY = " . $DBConn->quote($idtemp) . " AND a.DB_PERSON = 758495 
      )";// ORDER BY d.MAP, d.VALUE";
    $stmt_contig2 = make_query($DBConn,$contig_query2);
    $arrContig2 = retrieve_row($stmt_contig2);
      
    $contig_query3 = "
     SELECT d.MAP as MAPPVAL 
     FROM LOCUS e, LOCUS_COORDINATES d , MAP g 
     WHERE  e.ID = d.ID AND
      (
       d.MAP = 1140201  OR d.MAP = 1140202  OR d.MAP = 1140203  OR d.MAP = 1140204 
       OR d.MAP = 1140205  OR d.MAP = 1140206 OR d.MAP = 1140207  OR d.MAP = 1140208  
       OR d.MAP = 1140209 OR d.MAP = 1140210
      ) 
      AND g.id = d.MAP AND e.ID IN 
      (
        SELECT a.ID as PROBE_ID 
        FROM EXT_DB_KEY a 
        WHERE a.KEY = " . $DBConn->quote($idtemp) . " AND a.DB_PERSON = 758495 
      )"; 
    //ORDER BY d.MAP, d.VALUE";
    $stmt_contig3 = make_query($DBConn,$contig_query3);
    $arrContig3 = retrieve_row($stmt_contig3);

    $maxval = $arrContig2["MAXVAL"];
    $minval = $arrContig2["MINVAL"];
    $mappval = $arrContig3["MAPPVAL"];
      
    $query_map = "
     SELECT D.VALUE AS BIN , A.G AS BIN2, A.MAP, A.VALUE, A.BACK_BONE, 
       C.NAME AS MAP_NAME, D.NAME AS LOCUS_NAME, D.ID AS LOCUS_ID 
     FROM LOCUS_COORDINATES A, ID_NUM B, MAP C, LOCUS D 
     WHERE 
      (
        A.ID IN (" . $locus_list . ") OR A.ID IN 
        (
          SELECT a.ID as PROBE_ID 
          FROM EXT_DB_KEY a 
          WHERE a.KEY = " . $DBConn->quote($idtemp) . " AND a.DB_PERSON = 758495
        ) 
        OR
        (
          A.ID in 
          (
            SELECT e.ID 
            FROM LOCUS e, LOCUS_COORDINATES d , MAP g 
            WHERE  e.ID = d.ID AND (d.MAP = " . $mappval . " ) AND g.id = d.MAP 
            AND 
            (
             e.ID IN 
             (
               SELECT a.ID as PROBE_ID 
               FROM EXT_DB_KEY a 
               WHERE a.KEY = " . $DBConn->quote($idtemp) . "  AND a.DB_PERSON = 758495 
              )
              OR e.ID IN 
              (
               SELECT q.ID 
               FROM LOCUS_COORDINATES q  
               WHERE (q.MAP = " . $mappval . " ) AND q.VALUE > " . $minval . " 
                 AND q.VALUE < " . $maxval  . " 
              )
             )
          ) 
         )  
       ) 
       AND A.MAP = B.ID AND B.CURATION_LVL = 0 AND A.MAP = C.ID AND A.ID = D.ID 
      ORDER BY LOWER(D.NAME), LOWER(C.NAME)";
    $stmt_map = make_query($DBConn,$query_map);
    $arrMaps = retrieve_row($stmt_map);
    
    $map_table_string = ""; 
     /*JP note - some FPC records return 20,000+ results that we need to display 
                 and for loading time's sake it's much more efficient to dump all the
                 html into a string and add it into the cache instead of looping through them all 
                 and then again in the bauplan loop. 
      */
    while(strlen($arrMaps["locus_name"]) > 0)
    {
       $map_table_string .= '
       <tr>
         <td>
          <a href="/data_center/locus?id=$'.$arrMaps["locus_id"].'">'. trim($arrMaps["locus_name"]).'</a>';
          
          if($arrMaps["back_bone"] == 1)
           $map_table_string .= "*";
         
         $map_table_string .= '  
         </td>
         <td>
          <a href="/data_center/map?id='.$arrMaps["map"].'&amp;reflocus='.$arrMaps["locus_id"].'#reflocus">'.fix_map_name($arrMaps["map_name"]).'</a>
         </td>
         <td style="font-family: Courier New,Courier,serif; text-align: right;">' 
           . trim($arrMaps["value"]) .
         '</td>';
      
      if(strlen($arrMaps["BIN"]) > 0)
      {
        $tok = strtok($arrMaps["BIN"], ".");
        $bin1 = $tok;
        $tok = strtok(".");
        $sub1 = substr($tok, 0, 2);
        
        $map_table_string .= '
        <td style="text-align: right;">
          <a href="/bin_viewer?bin='.$bin1.'&sub='.$sub1.'">
           ' . number_format(coordfix($arrMaps["BIN"]),2) . '
          </a>';
          
        if((strlen($arrMaps["BIN2"]) > 0) && ($arrMaps["BIN"] != $arrMaps["BIN2"]))
        {
          $tok = strtok($arrMaps["BIN2"], ".");
          $bin1 = $tok;
          $tok = strtok(".");
          $sub1 = substr($tok, 0, 2);
          
          $map_table_string .= ' - 
          <a href="/bin_viewer?bin='.$bin1.'&sub='.$sub1.'">
           ' . number_format(coordfix($arrMaps["BIN2"]),2) . '
          </a>
         </td>';
          
        }
        $map_table_string .= '</tr>';
      }
      $arrMaps = retrieve_row($stmt_map);
    }//while

    return $map_table_string; 
  }
  
  
  function read_recomb_loci($DBConn, $id)
  {
    if(substr(trim($id),0,3) == "ctg")
    {
      $id = substr(trim($id),3);
    }
  
    $contig_query2 = "
      SELECT MAX(d.VALUE) as MAXVAL, MIN(d.VALUE) as MINVAL  
      FROM LOCUS e, LOCUS_COORDINATES d , MAP g, id_num idn
      WHERE  e.ID = d.ID AND 
       (
        d.MAP = 1140201  OR d.MAP = 1140202  OR d.MAP = 1140203  OR d.MAP = 1140204  
        OR d.MAP = 1140205  OR d.MAP = 1140206 OR d.MAP = 1140207  OR d.MAP = 1140208  
        OR d.MAP = 1140209 OR d.MAP = 1140210
       ) 
       AND g.id = d.MAP AND e.ID IN 
       (
        SELECT a.ID as PROBE_ID 
        FROM EXT_DB_KEY a 
        WHERE a.KEY = " . $DBConn->quote($id) . " AND a.DB_PERSON = 758495 
       )
	   AND e.ID = idn.ID
	   AND idn.curation_lvl = 0"; 
    //ORDER BY d.MAP, d.VALUE";
    $stmt_contig2 = make_query($DBConn,$contig_query2,2);
    $arrContig2 = retrieve_row($stmt_contig2);
      
    $contig_query3 = "
      SELECT d.MAP as MAPPVAL 
      FROM LOCUS e, LOCUS_COORDINATES d , MAP g, id_num idn
      WHERE  e.ID = d.ID AND
       (
        d.MAP = 1140201  OR d.MAP = 1140202  OR d.MAP = 1140203  OR d.MAP = 1140204 
        OR d.MAP = 1140205  OR d.MAP = 1140206 OR d.MAP = 1140207  OR d.MAP = 1140208  
        OR d.MAP = 1140209 OR d.MAP = 1140210
       ) 
       AND g.id = d.MAP AND e.ID IN 
       (
         SELECT a.ID as PROBE_ID 
         FROM EXT_DB_KEY a 
         WHERE a.KEY = " . $DBConn->quote($id) . " AND a.DB_PERSON = 758495 
       )
	   AND e.ID = idn.ID
	   AND idn.curation_lvl = 0"; 
        //ORDER BY d.MAP, d.VALUE";
    $stmt_contig3 = make_query($DBConn,$contig_query3,2);
    $arrContig3 = retrieve_row($stmt_contig3);

    $maxval = $arrContig2["MAXVAL"];
    $minval = $arrContig2["MINVAL"];
    $mappval = $arrContig3["MAPPVAL"];
      
    $contig_query = "
      SELECT distinct(e.NAME) as LNAME, e.FULL_NAME as FLNAME, g.NAME as MAP_NAME,  e.ID as LID,
        d.BACK_BONE as BACK_BONE,  d.MAP as MAPVAL, e.value as BBIN, e.g as BBIN2,  d.BIN as BIN,
        d.BIN2 as BIN2, e.NAME as MYNAME, d.MAP as MYTERM, d.VALUE as MVAL 
      FROM LOCUS e, LOCUS_COORDINATES d , MAP g, id_num idn
      WHERE  e.ID = d.ID AND 
       ( 
        d.MAP = 1140201  OR d.MAP = 1140202  OR d.MAP = 1140203  OR d.MAP = 1140204  OR d.MAP = 1140205 
        OR d.MAP = 1140206 OR d.MAP = 1140207  OR d.MAP = 1140208  OR d.MAP = 1140209 OR d.MAP = 1140210
       )
       AND g.id = d.MAP AND 
        (
         e.ID IN 
          (
           SELECT a.ID as PROBE_ID 
           FROM EXT_DB_KEY a 
           WHERE a.KEY = " . $DBConn->quote($id) . " AND a.DB_PERSON = 758495
           )
           OR e.ID IN 
            (
             SELECT q.ID  
             FROM LOCUS_COORDINATES q  
             WHERE (q.MAP = " . $mappval . " ) AND q.VALUE >" . $minval . " AND q.VALUE <" . $maxval  . " 
             )
          )
	   AND e.ID = idn.id
	   AND idn.curation_lvl = 0";
          //ORDER BY d.MAP, d.VALUE";

    $stmt_contig = make_query($DBConn,$contig_query);
    $arrContig = retrieve_row($stmt_contig);
      
    $count = 0;
    $recomb_loci = array();
    $recomb_loci[0]['min_val'] = number_format($minval, 2);
    $recomb_loci[0]['max_val'] = number_format($maxval, 2);
    $recomb_loci[0]['map_val'] = $mappval; //$arrContig["mapval"];
    $recomb_loci[0]['lid'] = $arrContig["lid"];
    $recomb_loci[0]['table_string'] = "";
    
    while(strlen($arrContig["lname"]) > 0)
    {
      $recomb_loci[0]['table_string'] .= '
        <tr>
         <td>
          <a href="/data_center/locus?id='.$arrContig["lid"].'">'.trim($arrContig["lname"]).'</a>';
          
      if($arrContig["back_bone"] == 1)
        $recomb_loci[0]['table_string'] .= '*';
         
      $recomb_loci[0]['table_string'] .= '
         </td>
         <td>
          '.trim($arrContig["flname"]).'
         </td>
         <td style="font-family: Courier New,Courier,serif; text-align: right;">';
       
      if ($arrContig["mval"] || $arrContig['mval'] == 0)
        $recomb_loci[0]['table_string'] .= number_format($arrContig["MVAL"],2);
        
      $recomb_loci[0]['table_string'] .= '
      </td>
      <td style="text-align: right;">';
        
      if(strlen($arrContig["BIN"]) > 0)
      {
        $tok = strtok($arrContig["BIN"], ".");
        $bin1 = $tok;
        $tok = strtok(".");
        $sub1 = substr($tok, 0, 2);
          
        $recomb_loci[0]['table_string'] .= '
          <a href="/bin_viewer?bin='.$bin1.'&sub='.$sub1.'">' .
             number_format(coordfix($arrContig["BIN"]),2) . '
          </a>';  
          
        if((strlen($arrContig["BIN2"]) > 0) && ($arrContig["BIN"] != $arrContig["BIN2"]))
        {
          $tok = strtok($arrContig["BIN2"], ".");
          $bin1 = $tok;
          $tok = strtok(".");
          $sub1 = substr($tok, 0, 2);
          
          $recomb_loci[0]['table_string'] .= '
            - <a href="/bin_viewer?bin='.$bin1.'&sub='.$sub1.'">' .
               number_format(coordfix($arrContig["BIN2"]),2) . '
             </a>';
        }
      }//if
      else if(strlen($arrContig["BBIN"]) > 0)
      {
        $tok = strtok($arrContig["BBIN"], ".");
        $bin1 = $tok;
        $tok = strtok(".");
        $sub1 = substr($tok, 0, 2);
          
        $recomb_loci[0]['table_string'] .= '
             <a href="/bin_viewer?bin='.$bin1.'&sub='.$sub1.'">' .
               number_format(coordfix($arrContig["BBIN"]),2) . '
             </a>';
        
        if((strlen($arrContig["BBIN2"]) > 0) && ($arrContig["BBIN"] != $arrContig["BBIN2"]))
        {
          $tok = strtok($arrContig["BBIN2"], ".");
          $bin1 = $tok;
          $tok = strtok(".");
          $sub1 = substr($tok, 0, 2);
          
          $recomb_loci[0]['table_string'] .= '
            - <a href="/bin_viewer?bin='.$bin1.'&sub='.$sub1.'">' .
               number_format(coordfix($arrContig["BBIN2"]),2) . '
             </a>';
          
        }
      }//else
      
      $recomb_loci[0]['table_string'] .= '
        </td>
       </tr>';
       
      $count++;
      $arrContig = retrieve_row($stmt_contig);
    }//while lname
    
    return $recomb_loci;
  }//read_recomb_loci
  
  
  function read_recomb_loci_2($DBConn, $id)
  {
    if(substr(trim($id),0,3) == "ctg")
      $id = substr(trim($id),3);
    
      $contig_query2 = "
       SELECT MAX(d.VALUE) as MAXVAL, MIN(d.VALUE) as MINVAL  
       FROM LOCUS e, LOCUS_COORDINATES d , MAP g, id_num idn
       WHERE  e.ID = d.ID AND 
       (d.MAP = 1203637  OR d.MAP = 1203638  OR d.MAP = 1203639  OR d.MAP = 1203640  
        OR d.MAP = 120341  OR d.MAP = 1203642 OR d.MAP = 1203643  OR d.MAP = 1203644  
        OR d.MAP = 1203645 OR d.MAP = 1203645)
       AND g.id = d.MAP AND e.ID IN 
        (SELECT a.ID as PROBE_ID FROM EXT_DB_KEY a WHERE a.KEY = " . $DBConn->quote($id) . " AND a.DB_PERSON = 758495 )
	   AND e.ID = idn.id
	   AND idn.curation_lvl = 0";
       //ORDER BY d.MAP, d.VALUE";

    $stmt_contig2 = make_query($DBConn,$contig_query2,2);
    $arrContig2 = retrieve_row($stmt_contig2);
    
    $contig_query3 = "
     SELECT d.MAP as MAPPVAL 
     FROM LOCUS e, LOCUS_COORDINATES d , MAP g, id_num idn
     WHERE  e.ID = d.ID AND 
      (d.MAP = 1203637  OR d.MAP = 1203638  OR d.MAP = 1203639  OR d.MAP = 1203640  
       OR d.MAP = 120341  OR d.MAP = 1203642 OR d.MAP = 1203643  OR d.MAP = 1203644  
       OR d.MAP = 1203645 OR d.MAP = 1203645) 
      AND g.id = d.MAP AND e.ID IN 
       (SELECT a.ID as PROBE_ID FROM EXT_DB_KEY a WHERE a.KEY = " . $DBConn->quote($id) . " AND a.DB_PERSON = 758495 )
	  AND e.ID = idn.id
	  AND idn.curation_lvl = 0";
     //ORDER BY d.MAP, d.VALUE";
    $stmt_contig3 = make_query($DBConn,$contig_query3,2);
    $arrContig3 = retrieve_row($stmt_contig3);

    $maxval = $arrContig2["MAXVAL"];
    $minval = $arrContig2["MINVAL"];
    $mappval = $arrContig3["MAPPVAL"];
      
    $contig_query = "
     SELECT distinct(e.NAME) as LNAME, e.FULL_NAME as FLNAME, g.NAME as MAP_NAME,  
      e.ID as LID, d.BACK_BONE as BACK_BONE,  d.MAP as MAPVAL, e.value as BBIN, e.g as BBIN2,
      d.BIN as BIN, d.BIN2 as BIN2, e.NAME as MYNAME, d.MAP as MYTERM, d.VALUE as MVAL 
     FROM LOCUS e, LOCUS_COORDINATES d , MAP g, id_num idn
     WHERE  e.ID = d.ID AND 
     (d.MAP = 1140201  OR d.MAP = 1140202  OR d.MAP = 1140203  OR d.MAP = 1140204  
     OR d.MAP = 1140205  OR d.MAP = 1140206 OR d.MAP = 1140207  OR d.MAP = 1140208  
     OR d.MAP = 1140209 OR d.MAP = 1140210) 
     AND g.id = d.MAP AND 
     (e.ID IN 
      (SELECT a.ID as PROBE_ID 
       FROM EXT_DB_KEY a 
       WHERE a.KEY = " . $DBConn->quote($id) . " AND a.DB_PERSON = 758495) 
      OR e.ID IN 
      (SELECT q.ID  
       FROM LOCUS_COORDINATES q  
       WHERE (q.MAP = " . $mappval . " ) AND q.VALUE >" . $minval . " AND q.VALUE <" . $maxval  . " 
       )
      )
	 AND e.ID = idn.id
	 AND idn.curation_lvl = 0"; 
      //ORDER BY d.MAP, d.VALUE";
    $stmt_contig = make_query($DBConn,$contig_query);
    $arrContig = retrieve_row($stmt_contig);
      
    $count = 0;
    $recomb_loci = array();
    $recomb_loci[0]['min_val_2'] = number_format($minval, 2);
    $recomb_loci[0]['max_val_2'] = number_format($maxval, 2);
    $recomb_loci[0]['map_val2'] = $mappval; //$arrContig["mapval"];
    $recomb_loci[0]['map_lid2'] = $arrContig["lid"];
    $recomb_loci[0]['gen_id'] = $mappval; //$arrContig3["mapval"];
      
    while(strlen($arrContig["lname"]) > 0)
    {
      $contig_gen = "
       select a.value as VAL 
       from LOCUS_COORDINATES a, id_num idn
       WHERE a.ID = " . $arrContig["LID"] . " 
			AND MAP = " . $mappval ."
			AND a.ID = idn.id
			AND idn.curation_lvl = 0";
      $stmt_gen = make_query($DBConn,$contig_gen,2);
      $arrGEN = retrieve_row($stmt_gen);
      
      $recomb_loci[$count]['lid2'] = $arrContig["lid"];
      $recomb_loci[$count]['lname'] = trim($arrContig["lname"]);
      $recomb_loci[$count]['flname'] = trim($arrContig["flname"]);
      
      if ($arrContig["mval"] || $arrContig['mval'] == 0)
       $recomb_loci[$count]['mval'] = number_format($arrContig["MVAL"],2);
      
      if($arrContig["back_bone"] == 1)
       $recomb_loci[$count]['back_bone'] = "*";
      
      if (($arrGEN["val"]) || (("1" . $arrGEN["VAL"]) == "10"))
        $recomb_loci[$count]['gen_val'] = number_format($arrGEN["VAL"],2);

      if(strlen($arrContig["BIN"]) > 0)
      {
        $tok = strtok($arrContig["BIN"], ".");
        $bin1 = $tok;
        $tok = strtok(".");
        $sub1 = substr($tok, 0, 2);
          
        $recomb_loci[$count]['bin1'] = $bin1;
        $recomb_loci[$count]['sub1'] = $sub1;
        $recomb_loci[$count]['bin_num1'] = number_format(coordfix($arrContig["BIN"]),2);
        
        if((strlen($arrContig["BIN2"]) > 0) && ($arrContig["BIN"] != $arrContig["BIN2"]))
        {
          $tok = strtok($arrContig["BIN2"], ".");
          $bin1 = $tok;
          $tok = strtok(".");
          $sub1 = substr($tok, 0, 2);
          
          $recomb_loci[$count]['bin2'] = $bin1;
          $recomb_loci[$count]['sub2'] = $sub1;
          $recomb_loci[$count]['bin_num2'] = number_format(coordfix($arrContig["BIN2"]),2);
          $recomb_loci[$count]['bin_sep'] = "-";
          
        }
      }
      else if(strlen($arrContig["BBIN"]) > 0)
      {
        $tok = strtok($arrContig["BBIN"], ".");
        $bin1 = $tok;
        $tok = strtok(".");
        $sub1 = substr($tok, 0, 2);
          
        $recomb_loci[$count]['bin1'] = $bin1;
        $recomb_loci[$count]['sub1'] = $sub1;
        $recomb_loci[$count]['bin_num1'] = number_format(coordfix($arrContig["BBIN"]),2);
        
        if((strlen($arrContig["BBIN2"]) > 0) && ($arrContig["BBIN"] != $arrContig["BBIN2"]))
        {
          $tok = strtok($arrContig["BBIN2"], ".");
          $bin1 = $tok;
          $tok = strtok(".");
          $sub1 = substr($tok, 0, 2);
          
          $recomb_loci[$count]['bin2'] = $bin1;
          $recomb_loci[$count]['sub2'] = $sub1;
          $recomb_loci[$count]['bin_num2'] = number_format(coordfix($arrContig["BBIN2"]),2);
          $recomb_loci[$count]['bin_sep'] = "-";
          
        }
      }
      $count++;
      $arrContig = retrieve_row($stmt_contig);
     }
     return $recomb_loci;
  }
  
  
  function read_recomb_pheno($DBConn, $id, $saved_vals)
  {
    if(substr(trim($id),0,3) == "ctg")
      $id = substr(trim($id),3);
      
    $query_pheno = "
       SELECT distinct(d.ID) AS PHENO, e.MAP AS MAPID, a.value as BBIN, a.g as BBIN2, e.VALUE AS LVAL,
         d.NAME AS PNAME,  a.ID AS LID , a.NAME AS LNAME, a.TYPE as TTYPE 
       from LOCUS a 
       join VARIATION b ON a.ID = b.VARIATIONOF 
       join VAR_PHENO_EFFECTS c on b.ID = c.ID 
       join PHENOTYPE d ON c.PHENO_EFFECT = d.ID 
       JOIN LOCUS_COORDINATES e ON e.ID = a.ID 
       JOIN ID_NUM f ON f.ID = d.ID 
       WHERE (
        a.ID IN (
         SELECT a.ID as PROBE_ID 
         FROM EXT_DB_KEY a 
         WHERE a.KEY = " . $DBConn->quote($id) . " AND a.DB_PERSON = 758495 ) OR a.ID IN 
         (
          SELECT q.ID  
          FROM LOCUS_COORDINATES q  
          WHERE (q.MAP = " . $saved_vals['mapval'] . " ) AND q.VALUE > " . $saved_vals['minval'] . " 
            AND q.VALUE < " . $saved_vals['maxval'] . ")) AND d.VNE = 'Y' AND f.CURATION_LVL =0
            AND e.MAP = " . $saved_vals['mapval'] . " ORDER BY a.VALUE, e.VALUE";
    $stmt_pheno = make_query($DBConn,$query_pheno,2);
    $arrPheno = retrieve_row($stmt_pheno);
    
    $count = 0;
    $recomb_pheno = array();
    
    
    while(strlen($arrPheno["pheno"]) > 0)
    {
      $recomb_pheno[$count]['pheno_id'] = $arrPheno["pheno"];
      $recomb_pheno[$count]['pheno_name'] = trim($arrPheno["pname"]);
      $recomb_pheno[$count]['lid'] = $arrPheno["lid"];
      $recomb_pheno[$count]['lname'] = trim($arrPheno["lname"]);
      
      if ($arrPheno["lval"] || $arrPheno['lval'] == 0)
       $recomb_pheno[$count]['lval'] = number_format($arrPheno["LVAL"],2);
    
      if(strlen($arrPheno["BBIN"]) > 0)
      {
        $tok = strtok($arrPheno["BBIN"], ".");
        $bin1 = $tok;
        $tok = strtok(".");
        $sub1 = substr($tok, 0, 2);
        
          
        $recomb_pheno[$count]['bin1'] = $bin1;
        $recomb_pheno[$count]['sub1'] = $sub1;
        $recomb_pheno[$count]['bin_num1'] = number_format(coordfix($arrPheno["BBIN"]),2);
        
        if((strlen($arrPheno["BBIN2"]) > 0) && ($arrPheno["BBIN"] != $arrPheno["BBIN2"]))
        {
          $tok = strtok($arrPheno["BBIN2"], ".");
          $bin1 = $tok;
          $tok = strtok(".");
          $sub1 = substr($tok, 0, 2);
          
          
          $recomb_pheno[$count]['bin2'] = $bin1;
          $recomb_pheno[$count]['sub2'] = $sub1;
          $recomb_pheno[$count]['bin_num2'] = number_format(coordfix($arrPheno["BBIN2"]),2);
          $recomb_pheno[$count]['bin_sep'] = "-";
          
        }
      }
      $count++;
      $arrPheno = retrieve_row($stmt_pheno);
    }
    return $recomb_pheno;
  }
  
  
  function read_recomb_probe($DBConn, $id, $saved_vals)
  {
    if(substr(trim($id),0,3) == "ctg")
      $id = substr(trim($id),3);
        
    $query_probe = "
     SELECT distinct(c.ID) AS PID, g.SEQ_START as CSTART, g.SEQ_STOP as CEND, e.MAP AS MAPID, 
       a.value as BBIN, a.g as BBIN2, e.VALUE AS LVAL, c.NAME AS PNAME, d.NAME as PTYPE, 
       a.ID AS LID , a.NAME AS LNAME, a.TYPE as TTYPE, c.TYPE as PIDTYPE 
     from LOCUS a 
     join LOCUS_DETECTED_BY b ON a.ID = b.ID 
     join PROBE c ON b.PROBE_ID = c.ID 
     JOIN TERM d on c.TYPE = d.ID 
     JOIN LOCUS_COORDINATES e ON e.ID = a.ID 
     JOIN ID_NUM f ON a.ID = f.ID 
     LEFT JOIN GENOME_COORDINATE g ON g.LOCUS_ID = a.ID
     WHERE (a.ID IN (
       SELECT a.ID as PROBE_ID 
       FROM EXT_DB_KEY a 
       WHERE a.KEY = " . $DBConn->quote($id) . " AND a.DB_PERSON = 758495 
       ) 
       OR a.ID IN (
         SELECT q.ID  
         FROM LOCUS_COORDINATES q  
         WHERE (q.MAP = " . $saved_vals['mapval'] . " ) AND q.VALUE > " . $saved_vals['minval'] . "  
         AND q.VALUE < " . $saved_vals['maxval'] . " 
       )
     )  
     AND f.CURATION_LVL =0 AND e.MAP = " . $saved_vals['mapval'] . " AND 
    (
       c.type = 111599 OR c.type = 162780 OR c.type = 104436 OR c.type = 51525 
       OR c.type = 275335 OR c.type = 487230 OR c.type = 887674 OR c.type = 25402 
       OR c.type = 99386 OR c.type = 933665 OR c.type = 487229 OR c.type = 885502
       OR c.type = 1080056 OR c.type = 1080057 OR c.type = 1083616
     ) 
     ORDER BY e.VALUE" ;

    $stmt_probe = make_query($DBConn,$query_probe,2);
    $arrProbe = retrieve_row($stmt_probe);
    
    $count = 0;
    $recomb_probe = array();
    $probe_string = "";
      
    while(strlen($arrProbe["pid"]) > 0)
    {
      $probe_string .= '
       <tr>
         <td style="width: 20%;">
          <a href="/data_center/';
      
      if($arrProbe["PIDTYPE"] == "487230" || $arrProbe["PIDTYPE"] == "887674" || 
         $arrProbe["PIDTYPE"] == "25402" || $arrProbe["PIDTYPE"] == "99386" || 
         $arrProbe["PIDTYPE"] == "933665" || $arrProbe["PIDTYPE"] == "487229" || 
         $arrProbe["PIDTYPE"] == "885502" || $arrProbe["PIDTYPE"] == "1080056" || 
         $arrProbe["PIDTYPE"] == "1080057" || $arrProbe["PIDTYPE"] == "1083616")
         $probe_string .= "marker"; 
      else if ($arrProbe["PIDTYPE"] == "111599" || $arrProbe["PIDTYPE"] == "162780" || $arrProbe["PIDTYPE"] == "104436" || $arrProbe["PIDTYPE"] == "51525" || $arrProbe["PIDTYPE"] == "275335" )
         $probe_string .= "ssr";
         
      $probe_string .=
       '?id=$(pid)">'.trim($arrProbe["pname"]).'</a>
         </td>
         <td style="width: 15%;">' .
           trim($arrProbe["ptype"]) . '
         </td>
         <td style="width: 20%;">
          <a href="/data_center/locus?id='.$arrProbe["lid"].'">'.trim($arrProbe["lname"]).'</a>
         </td>
         <td style="font-family: Courier New,Courier,serif; text-align: center; width: 10%;">';
          
        if ($arrProbe["lval"] || $arrProbe['lval'] == 0)
          $probe_string .= number_format($arrProbe["lval"],2);
       
      $probe_string .= '
        </td>
        <td style="text-align: center; width: 15%;">';
      
      if(strlen($arrProbe["BBIN"]) > 0)
      {
        $tok = strtok($arrProbe["BBIN"], ".");
        $bin1 = $tok;
        $tok = strtok(".");
        $sub1 = substr($tok, 0, 2);
          
        $probe_string .='
        <a href="/bin_viewer?bin='.$bin1.'&sub='.$sub1.'">'.
           number_format(coordfix($arrProbe["BBIN"]),2) . '
        </a>';
        
        if((strlen($arrProbe["BBIN2"]) > 0) && ($arrProbe["BBIN"] != $arrProbe["BBIN2"]))
        {
          $tok = strtok($arrProbe["BBIN2"], ".");
          $bin1 = $tok;
          $tok = strtok(".");
          $sub1 = substr($tok, 0, 2);
          
          $probe_string .='
           - <a href="/bin_viewer?bin='.$bin1.'&sub='.$sub1.'">'.
             number_format(coordfix($arrProbe["BBIN2"]),2) . '
           </a>';
          
        }
      }
      $probe_string .= '
        <td width="10%" style="text-align: right;">'.
          $arrProbe["cstart"].'
        </td>
         <td width="10%" style="text-align: right;">'.
          $arrProbe["cend"].'
         </td>
        </tr>';
      $count++;
      $arrProbe = retrieve_row($stmt_probe);
    }

    return $probe_string; 
  }
   
?>
