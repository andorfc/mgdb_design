<?PHP
/*************************************************************************
 file: searchcount_ajax.php: 
 
 purpose: Handles a search for all data types
          Converted from anythingsearch.cgi
          
  General algorithm overview:
    set up variables
    set up query for each type of data
    execute these queries
    analyze overall results (mostly a check for 0 or 1 results overall)
    if 0 display a message stating no results
    if 1 redirect to the record
    else display a results summary

*************************************************************************/
error_reporting(0);
@ini_set('display_errors', 0);
 
  include_once('../../lib/Bauplan.php');
  include_once('../../include/db-api.php');
  include_once('../../include/gp_lib.php');
  include_once('../../include/data_center_functions.php');

  $system = getSystemInfo('mgdb.conf');

    // connect to the database
    $DBConn = connect_to_database();
    
  // Search limit
  $search_limit = getCGIParam('search_limit', 'P', $system['search_limit']);
  $query_limit = ""; 
  if ($search_limit > 0) {
    $query_limit = " limit " . (int) $search_limit;
  }
  
  $raw_term = urldecode(getCGIParam('term', 'G', ''));
  $term     = cleanSearchTerm($raw_term, $DBConn);
  $lc_term  = strtolower($term);
  
  $type_code = getCGIParam('code', 'G', false);
logMessage("Search type: $type_code");

  $query_breakdown = "";

  $use_or = 0;
  
  ///check cache file first
  $tagid =  hash("md5", $term);
  $cachefile = $system["search_cache_path"] . "/search/counts_" . $tagid . ".html";
  if (file_exists($cachefile) && ($iscache != 1)) {
    // open the cache file "cache/search/home.html" for reading
    $fp = fopen($cachefile, 'r');
    // save the contents of output buffer to the file
    $contents = fread($fp, filesize($cachefile));
    // close the file
    fclose($fp);
    
    $contents = "{" . $contents . "}";
    $arr_json = array(json_decode($contents,true));

    echo $arr_json[0][$type_code];
    /////end cache file code
  } 
  
  else {
    // set up query for each type of data

    // set up the suffix for the person query
    if (strlen($term) > 0) {
      $lc_term = strtolower($term);
      $query_end_lc = strtok($lc_term, ' ');
      while ($query_end_lc != false) {
        if ($use_or == 0) {
          $use_or = 1;
          //$query_breakdown = "(LOWER(a.name) LIKE '$query_end%' OR LOWER(a.name_first) LIKE '$query_end%' OR LOWER(a.name_last) LIKE '$query_end%' OR LOWER(c.synonyms) LIKE '%" . $query_end . "%') ";
      /*jp $query_breakdown = "SELECT distinct(A.ID) FROM PERSON A JOIN ID_NUM B ON A.ID = B.ID LEFT OUTER JOIN SYNONYMS C ON B.ID = C.ID WHERE B.CURATION_LVL = 0 AND PostText_Name @@ to_tsquery('$query_end') UNION SELECT distinct(A.ID) FROM PERSON A JOIN ID_NUM B ON A.ID = B.ID LEFT OUTER JOIN SYNONYMS C ON B.ID = C.ID WHERE B.CURATION_LVL = 0 AND PostText_Name_First @@ to_tsquery('$query_end') UNION SELECT distinct(A.ID) FROM PERSON A JOIN ID_NUM B ON A.ID = B.ID LEFT OUTER JOIN SYNONYMS C ON B.ID = C.ID WHERE B.CURATION_LVL = 0 AND PostText_Name_Last @@ to_tsquery('$query_end') UNION SELECT distinct(A.ID) FROM PERSON A JOIN ID_NUM B ON A.ID = B.ID LEFT OUTER JOIN SYNONYMS C ON B.ID = C.ID WHERE B.CURATION_LVL = 0 AND PostText @@ to_tsquery('$query_end')";*/
          $query_breakdown = "WHERE (LOWER(P.NAME) LIKE '%$query_end_lc%' OR LOWER(P.NAME_FIRST) LIKE '$query_end_lc%' OR LOWER(P.NAME_LAST) LIKE '$query_end_lc%' ) ";
      }
        else {
          //$query_breakdown = $query_breakdown . " AND (LOWER(a.name) LIKE '$query_end%' OR LOWER(a.name_first) LIKE '$query_end%' OR LOWER(a.name_last) LIKE '$query_end%' OR LOWER(c.synonyms) LIKE '%" . $query_end . "%') ";
      /* jp $query_breakdown = $query_breakdown . " UNION SELECT distinct(A.ID) FROM PERSON A JOIN ID_NUM B ON A.ID = B.ID LEFT OUTER JOIN SYNONYMS C ON B.ID = C.ID WHERE B.CURATION_LVL = 0 AND PostText_Name @@ to_tsquery('$query_end') UNION SELECT distinct(A.ID) FROM PERSON A JOIN ID_NUM B ON A.ID = B.ID LEFT OUTER JOIN SYNONYMS C ON B.ID = C.ID WHERE B.CURATION_LVL = 0 AND PostText_Name_First @@ '$query_end' UNION SELECT distinct(A.ID) FROM PERSON A JOIN ID_NUM B ON A.ID = B.ID LEFT OUTER JOIN SYNONYMS C ON B.ID = C.ID WHERE B.CURATION_LVL = 0 AND PostText_Name_Last @@ to_tsquery('$query_end') UNION SELECT distinct(A.ID) FROM PERSON A JOIN ID_NUM B ON A.ID = B.ID LEFT OUTER JOIN SYNONYMS C ON B.ID = C.ID WHERE B.CURATION_LVL = 0 AND PostText @@ to_tsquery('$query_end')";*/
          $query_breakdown .= " AND (LOWER(P.NAME) LIKE '%$query_end_lc%' OR LOWER(P.NAME_FIRST) LIKE '$query_end_lc%' OR LOWER(P.NAME_LAST) LIKE '$query_end_lc%' ) ";
        }
        $query_end_lc = strtok(" ");
      }
    }

    /////////////////////////////////////
    //////// Individual searches ////////
    /////////////////////////////////////
    
    // locus query 
    if ($type_code == 0) {
      include_once('../../search/locus/locus_results_functions.php');
      echo doSearch($DBConn, $term, $raw_term, $search_limit, 'count');
    }//locus
  
    // phenotype query
    if ($type_code == 1) {
      include_once('../../search/phenotype/phenotype_results_lib.php');
      echo doSearch($DBConn, $term, $search_limit, 'count');
    }
    
    // gene model
    if ($type_code == 2) {
      include_once('../../search/gene/gene_results_lib.php');
      include_once('../../include/gene_center_lib.php');
      $gm_count = getGMRecords($term, '', $DBConn, 'count');
      $locus_count = getLocusRecords($term, '', $DBConn, 'count');
      // term may be obsolete gene model:
      $withdraw_count = (geneModelWithdrawn($term, $DBConn)) ? 1 : 0; 
      echo ($gm_count + $locus_count + $withdraw_count);
    }//gene model
      
    // reference query
    if ($type_code == 3) {
      include_once('../../search/reference/reference_results_lib.php');
      
      //jp - Need to censor climate change because of new executive orders in 2025...
      $result = ($term == "climate change" || $raw_term == "climate change") ? 0 : doSearch($DBConn, $term, $raw_term, $search_limit, 'count');
      echo $result;
    }
   
    //locus lookup query
    /* 
     * locus lookup is retired, so ingore this block
    if ($type_code == 4) {
        
      
      $term = strtok($term, " \n\t");
    
      ///// Need to add System - root_url
      $fileName1 = $system['root_url_private'] . "/tools/ajax/locus_lookup/getGeneModels.php?locus=" . $term;
      $fileName2 = $system['root_url_private'] . "/tools/ajax/locus_lookup/getPhyMapped.php?locus=" . $term;
      $fileName3 = $system['root_url_private'] . "/tools/ajax/locus_lookup/getPlacedBAC.php?locus=" . $term;
      $fileName4 = $system['root_url_private'] . "/tools/ajax/locus_lookup/getGenMapped.php?locus=" . $term;
      $gbcount = 0;
      $handle1 = fopen($fileName1, 'rb') ;
      $contents1 = '';
      $notbreak = true;
      $fcount = 0;
      while (!feof($handle1) && $notbreak) {
        $contents1 .= fread($handle1, 8192);
        $fcount++;
        if($fcount > 10) {
          $notbreak = false;
        }
      }
      fclose($handle1);
      
      $obj1 = json_decode(trim($contents1));
      
      $start = $obj1->{"start"};
      
      if($start) {
        $gbcount++;
      }
      
      $handle2 = fopen($fileName2, 'rb') ;
      $contents2 = '';
      $notbreak = true;
      $fcount = 0;
      while (!feof($handle2) && $notbreak) {
        $contents2 .= fread($handle2, 8192);
        $fcount++;
        if($fcount > 10) {
          $notbreak = false;
        }
      }
      fclose($handle2);
      
      $obj2 = json_decode(trim($contents2));
      
      $start2 = $obj2->{"start"};
      
      if($start2) {
        $gbcount++;
      }
      
      $handle3 = fopen($fileName3, 'rb') ;
      $contents3 = '';
      $notbreak = true;
      $fcount = 0;
      while (!feof($handle3) && $notbreak) {
        $contents3 .= fread($handle3, 8192);
        $fcount++;
        if($fcount > 10) {
          $notbreak = false;
        }
      }
      fclose($handle3);
      
      $obj3 = json_decode(trim($contents3));
      
      $start3 = $obj3->{"start"};
      
      if($start3) {
        $gbcount++;
      }
      $handle4 = fopen($fileName4, 'rb') ;
      $contents4 = '';
      $notbreak = true;
      $fcount = 0;
      while (!feof($handle4) && $notbreak) {
        $contents4 .= fread($handle4, 8192);
        $fcount++;
        if($fcount > 10) {
          $notbreak = false;
        }
      }
      fclose($handle4);
      
      $obj4 = json_decode(trim($contents4));
      
      $start4 = $obj4->{"start"};
      
      if($start4) {
        $gbcount++;
      }
    
      echo $gbcount;
    }
    */
    
    // stock query
    if ($type_code == 5) {
      include_once('../../search/stock/stock_results_lib.php');
      echo doSearch($DBConn, $term, '', 'count');            
    }
     
    //project
    if ($type_code == 6) {
        
      //jp -- need to censor climate change because of new executive orders in 2025...
      if ($lc_term == "climate change") {
        echo 0;
      }          
      else {
        $query_prj = "
        SELECT p.id 
        FROM pc_project p, id_num i, pc_assoc_funding af 
        WHERE i.id=p.id AND i.curation_lvl=0 AND af.id=p.id 
              AND (LOWER(p.name) LIKE '$lc_term' 
                   OR LOWER(p.description) LIKE '$lc_term' 
                   OR af.keywords LIKE '$lc_term')";
        $statement = make_query($DBConn, $query_prj);
        $arrCount = array();
        $arrCount = get_all_rows($statement);
        $qcount = ($arrCount) ? count($arrCount) : 0;
        echo $qcount;
      }
    }
     
    // person query w/ suffix
    if ($type_code == 7)
    {
     //"SELECT distinct(A.ID) FROM PERSON A JOIN ID_NUM B ON A.ID = B.ID LEFT OUTER JOIN SYNONYMS C ON B.ID = C.ID WHERE B.CURATION_LVL = 0 AND PostText_Name @@ 'andorf' UNION SELECT distinct(A.ID) FROM PERSON A JOIN ID_NUM B ON A.ID = B.ID LEFT OUTER JOIN SYNONYMS C ON B.ID = C.ID WHERE B.CURATION_LVL = 0 AND PostText_Name_First @@ 'andorf' UNION SELECT distinct(A.ID) FROM PERSON A JOIN ID_NUM B ON A.ID = B.ID LEFT OUTER JOIN SYNONYMS C ON B.ID = C.ID WHERE B.CURATION_LVL = 0 AND PostText_Name_Last @@ 'andorf' UNION SELECT distinct(A.ID) FROM PERSON A JOIN ID_NUM B ON A.ID = B.ID LEFT OUTER JOIN SYNONYMS C ON B.ID = C.ID WHERE B.CURATION_LVL = 0 AND PostText @@ 'andorf'"; 
     
     /*RI-1500 - Fixed person search. Previously "Harper, L" returned thousands of irrelevant results */
     $query_person = "
          SELECT P.ID, P.NAME, P.NAME_LAST, P.NAME_FIRST 
          FROM PERSON P, ID_NUM 
          $query_breakdown AND P.ID = ID_NUM.ID and ID_NUM.CURATION_LVL = 0 
          ORDER BY LOWER(P.NAME)";
      $statement = make_query($DBConn,$query_person,100);
      $arrCount = array();
      $arrCount = get_all_rows($statement);
      
      $qcount = ($arrCount) ? count($arrCount) : 0;
    
      echo $qcount ;
    }
     
    // map query
    if ($type_code == 8) {
      include_once('../../search/map/map_results_lib.php');
      echo doSearch($DBConn, $term, '', 'count');
    }
     
    // BAC
    if ($type_code == 9) {
      include_once('../../search/bac/bac_results_lib.php');
      echo doSearch($DBConn, $term, '', 'count');
    }
    
    // Marker
    if ($type_code == 10) {
      include_once('../../search/marker/marker_results_lib.php');
      echo doSearch($DBConn, $term, '', 'marker_results', 'count');
    }
    
    // SSR - RI-1043
    if ($type_code == 11)
    {
      //$query = "select a.id, a.type as TTYPE from probe a join id_num b on a.id = b.id where b.curation_lvl = 0 and (LOWER(a.name) like '".$term."' or LOWER(a.repeat) like '".$term."') AND a.type  = '104436'";
        $query = "
          select id, name from 
            (select id, name, repeat 
                    from (select id, name, repeat 
                          from (select distinct(a.id), a.name, a.repeat 
                                from probe a 
                                  left outer join id_num b on a.id = b.id 
                                  left outer join synonyms c on a.id = c.id 
                                  where a.type = '104436' and b.curation_lvl = 0 
                                        and (LOWER(a.name) like '$term%' 
                                             or LOWER(a.repeat) like '$term%' 
                                             or lower(c.synonyms) like '$term%')
                                ) as sub3 
                          order by name) as sub2
             ) as sub1 ";
    
      // RI-1046 - search limit by ktcho
      $query .= $query_limit;
     
      $statement = make_query($DBConn,$query,100);
      $arrCount = array();
      $arrCount = get_all_rows($statement);
      $qcount = ($arrCount) ? count($arrCount) : 0;
    
      echo $qcount;
    }
    
    // Overgo - RI-1043
    if ($type_code == 12)
    {
      //$query = "select a.id, a.type as TTYPE from probe a join id_num b on a.id = b.id where b.curation_lvl = 0 and (LOWER(a.name) like '".$term."' or LOWER(a.repeat) like '".$term."') AND a.type IN ('747274', '393660')";
      $query = "
        select a.id, a.type as TTYPE 
        from probe a 
        left outer join id_num b on a.id = b.id 
        where (a.type = 393660 or a.type = 747274) 
         and LOWER(a.name) like '" . $term . "%' 
         and b.curation_lvl = 0 
        order by LOWER(a.name) ";
      
      // RI-1046 - search limit by ktcho
      $query .= $query_limit;
     
      $statement = make_query($DBConn,$query,100);
      $arrCount = array();
      $arrCount = get_all_rows($statement);
      $qcount = ($arrCount) ? count($arrCount) : 0;
    
    /*  
      //while(strlen($arrCount2["COUNT(A.ID)"]) > 0)
      for($ii = 0; $ii < $qcount;$ii++)
      {
        if($arrCount[$ii]["ttype"] == 171715)
          $bac_count++;
        else if($arrCount[$ii]["ttype"] == 34)
          $est_count++;
        else if(($arrCount[$ii]["ttype"] == 393660) || ($arrCount[$ii]["ttype"] == 747274))
          $overgo_count++;
        else if($arrCount[$ii]["ttype"] == 104436)
          $ssr_count++;
        else
          $probe_count++;
       // $arrCount2 = retrieve_row($statement2);
      }
     */
      echo $qcount;
    }
    
    // EST - RI-1043
    if ($type_code == 13)
    {
      $query = "select a.id, a.type as TTYPE from probe a join id_num b on a.id = b.id where b.curation_lvl = 0 and (LOWER(a.name) like '".$term."' or LOWER(a.repeat) like '".$term."') AND a.type  = '34'";
     
      //if (!$arrEst) {
        $query = "
          select a.id, a.name 
          from probe a
          left outer join id_num b on a.id = b.id 
          where a.type = 34 and LOWER(a.name) like '" . $term . "%' and b.curation_lvl = 0 
          order by LOWER(a.name) " . $query_limit;
        $stmt_results = make_query($DBConn,$query,100);
        $arrEst = get_all_rows($stmt_results);
        //setSessionVar("est_".$term."_".$search_limit, $arrEst);
      //}
      
      $arrCount = ($arrEst) ? count($arrEst) : 0;
     
      if ($arrCount == 0) {
     
        $query_end = strtok(strtolower($term), " ");
        $query_breakdown = "";
        $use_or = 0;
        
        if (strlen($term) > 0) {
          while ($query_end != false) {
            $uc_query_end = strtoupper($query_end);
            if (preg_match("/\%/", $query_end)) {
              $frag1 = "LIKE '$query_end'";
              $frag2 = "LIKE '$uc_query_end'";
            }
            else {
              $frag1 = "= '$query_end'";
              $frag2 = "= '$uc_query_end'";
            }
            if ($use_or == 0) {
              $use_or = 1;
              $query_breakdown .= "(genbank_acc $frag1 OR genbank_acc $frag2 "
                                 . "OR seq_id $frag1 OR seq_id $frag2 " 
                                 . "OR seq_title $frag1 OR seq_title $frag2) ";
            }
            else {
              $query_breakdown .= " AND (genbank_acc $frag1 OR genbank_acc $frag2 "
                                . "OR seq_id $frag1 OR seq_id $frag2 "
                                . "OR seq_title $frag1 OR seq_title $frag2) ";
            }
            $query_end = strtok(" ");
          }//while
        }
    
        $query = "
          SELECT genbank_acc as name, seq_id as id, seq_title as synonym,seq_type as comment
          FROM z_sequence WHERE $query_breakdown
          ORDER BY genbank_acc " . $query_limit;
        $stmt_results = make_query($DBConn, $query);
        $arrEst = get_all_rows($stmt_results);
      }
     
      $arrCount = ($arrEst) ? count($arrEst) : 0;
    
     
      // RI-1046 - search limit by ktcho
      //$query .= $query_limit;
     
      //$statement = make_query($DBConn,$query,100);
      //$arrCount = array();
      //$arrCount = get_all_rows($statement);
      $qcount = ($arrCount) ? $arrCount : 0;
    
      echo $qcount;
    }
    
    // gene product query
    if ($type_code == 14) {
      include_once('../../search/gene_product/gene_product_results_lib.php');
      echo doSearch($DBConn, $term, 0, 'count');
    }
     
    // qtl experiment query
    if($type_code == 15)
    {
      $query_qtl_exp = "SELECT A.ID FROM QTL_EXP A LEFT OUTER JOIN ID_NUM B ON A.ID = B.ID WHERE B.CURATION_LVL = 0 AND LOWER(a.name) LIKE '" . $term . "%'";
      //$statement_qtl_exp = make_query($DBConn,$query_qtl_exp,1);
      //$arrCountQTLExp = retrieve_row($statement_qtl_exp);
     //  echo $arrCountQTLExp["COUNT(A.ID)"] ;
       
         $statement = make_query($DBConn,$query_qtl_exp,100);
      $arrCount = array();
      $arrCount = get_all_rows($statement);
      $qcount = ($arrCount) ? count($arrCount) : 0;
      echo $qcount;
    }
     
    // trait query
    if ($type_code == 16) {
        /* jp these queries are currently broken
      include_once('../../search/qtltrait/qtltrait_results_lib.php');
      echo doSearch($DBConn, $term, 0, 'count');
      */
      $query_count = "SELECT count(A.ID) FROM term a, id_num b WHERE a.type = 32464 and A.ID = B.ID AND B.CURATION_LVL = 0 AND LOWER(A.NAME) LIKE '%" . $term . "%'";
      $stmt_count = make_query($DBConn,$query_count,1);
      $arrCount2 = retrieve_row($stmt_count);
      echo $arrCount2["count"];
    }
     
    // variation query 
    if ($type_code == 17) {
      include_once('../../search/variation/variation_results_lib.php');
      echo doSearch($DBConn, false, $term, $raw_term, 0, 'count');
    }
     
    // clone library query
    if ($type_code == 18)
    {
      $query_clone_library = "SELECT A.ID FROM clone_library a, id_num b WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND LOWER(a.name) LIKE '%" . $term . "%'";
      $statement = make_query($DBConn,$query_clone_library,100);
      $arrCount = array();
      $arrCount = get_all_rows($statement);
      
      $qcount = ($arrCount) ? count($arrCount) : 0;
    
      echo $qcount ;
    }
     
    // synonyms query
    if($type_code == 19) {
      //jp -- need to censor climate change because of new executive orders...
      if ($term == "climate change") {
         echo 0;   
      }
      else {
      $query_syn = "
        SELECT a.id FROM synonyms a
          INNER JOIN id_num b ON a.id=b.id
        WHERE b.curation_lvl=0 AND a.synonyms ILIKE '$term'";
      $statement = make_query($DBConn,$query_syn,100);
      $arrCount = array();
      $arrCount = get_all_rows($statement);
      $qcount = ($arrCount) ? count($arrCount) : 0;
      echo $qcount;
      }
    }
    
    // resource
    if($type_code == 20)
    {
      $query_res = "SELECT R.ID FROM PC_RESOURCE R, ID_NUM I WHERE I.ID=R.ID AND I.CURATION_LVL=0 AND (LOWER(R.NAME) LIKE '%$term%' OR LOWER(R.DESCRIPTION) LIKE '%$term%')";
      //$statement_res = make_query($DBConn, $query_res, 1);
      //$arrCountterm = retrieve_row($statement_res);
      //echo $arrCountterm["COUNT(R.ID)"] ;
      
      $statement = make_query($DBConn,$query_res ,100);
      $arrCount = array();
      $arrCount = get_all_rows($statement);
      $qcount = ($arrCount) ? count($arrCount) : 0;
      echo $qcount;
    }
    
    // genome query
    if ($type_code == 21) {
      include_once('../../search/genome/genome_results_lib.php');
      echo doSearch($DBConn, $term, 0, 'count');
    }
     

    // term definition
    if($type_code == 24)
    {
      $query_term = "
      SELECT COUNT(A.ID) 
      FROM TERM A, id_num idn
      WHERE LOWER(A.NAME) LIKE '%" . $term . "%'
        AND A.ID = idn.id
        AND idn.CURATION_LVL = 0";
    
      $statement_term = make_query($DBConn,$query_term,1);
      $arrCountterm = retrieve_row($statement_term);
    
      // RI-1046 - search limit by ktcho
      if($arrCountterm["COUNT(A.ID)"] > $search_limit)
        echo $search_limit;
      else
        echo $arrCountterm["COUNT(A.ID)"] ;
    }
    
    //gene to gene model (not used in search-all)
    if ($type_code == 28) {
      $query = "
        SELECT COUNT(a.id) AS aid FROM locus a 
          JOIN id_num b ON a.ID = b.id 
        WHERE species = '12808' 
              AND b.curation_lvl=0 
              AND (LOWER(name) = '$term' OR LOWER(full_name) = '$term')";
      
      $statement = make_query($DBConn, $query);
      $arrNum    = get_all_rows($statement);
    
      $testcase = false;
      
      if (!$arrNum[0]["aid"]) {
        $querysyn1 = "
          SELECT COUNT(a.id) AS aid FROM synonyms a 
            JOIN locus b ON b.id = a.id 
            JOIN id_num c ON a.id=c.id
          WHERE c.curation_lvl = 0 
                AND LOWER(a.synonyms) ='$term' 
                AND species = '12808'";
        $statementsyn1 = make_query($DBConn, $querysyn1);
        $arrsyn1 = get_all_rows($statementsyn1);
      }

/* no longer supported
      $query7 = "
        SELECT COUNT(gene_id) AS aid FROM za_gene_models 
        WHERE LOWER(gene_id) ='$term' OR LOWER(transcript_id) ='$term' 
              OR LOWER(translation_id) ='$term'" ;
      $stmt_ext7 = make_query($DBConn, $query7);
      $arrExtDbs7 = get_all_rows($stmt_ext7);
        
      if ($arrExtDbs7[0]["aid"] > 0 || $arrsyn1[0]["aid"] > 0 
            || $arrNum[0]["aid"] > 0) {
        echo "1";
      }  
      else  {
        echo "0";
      }
*/
    }//gene-to-gene model
    
    // metapath query
    if($type_code == 114)
    {
      $query_metapath = "SELECT count(A.ID) FROM META_PATH A LEFT OUTER JOIN ID_NUM B ON A.ID = B.ID WHERE B.CURATION_LVL = 0 AND LOWER(a.name) LIKE '" . $term . "%'";
      $statement_metapath = make_query($DBConn,$query_metapath,1);
      $arrCountMetapath = retrieve_row($statement_metapath);
      echo $arrCountMetapath["COUNT(A.ID)"] ;
    }
     
    // bp query
    if($type_code == 144)
    {
      $query_bp = "SELECT A.ID FROM TERM A LEFT OUTER JOIN ID_NUM B ON A.ID = B.ID WHERE B.CURATION_LVL = 0 AND A.TYPE = 32466 AND LOWER(A.NAME) LIKE '%" . $term . "%'";
      $statement_bp = make_query($DBConn,$query_bp,1);
      $arrCountBP = retrieve_row($statement_bp);
       echo $arrCountBP["COUNT(A.ID)"] ;
    
    // analyze overall results (mostly a check for 0 or 1 results overall)
    // we don't need to analyze RFLP results as they will be included in other probe sets
    }
    
    // grin query
    if($type_code == 155)
    {
      $query_grin = "SELECT COUNT(*) FROM STOCK_GRIN ";
      $piece = 0;
      $term_piece = strtok($term," ");
      while(strlen($term_piece) > 0)
      {
        if($piece < 1)
          $query_grin = $query_grin . "WHERE ";
        else
          $query_grin = $query_grin . " AND ";
        $piece++;
        $query_grin = $query_grin . "(LOWER(SEARCH_ID) LIKE '%" . strtolower($term_piece) . "%' OR LOWER(AC_P) LIKE '%" . strtolower($term_piece) . "%' OR AC_NO LIKE '%" . $term_piece . "%')";
        $term_piece = strtok(" ");
      }
      $statement_grin = make_query($DBConn,$query_grin,1);
      $arrCountGrin = retrieve_row($statement_grin);
      
      echo $arrCountGrin["COUNT(*)"];
    }//grin
    
  }

?>
