<?PHP
/* file: sequence_data.php
 *
 * purpose: display the various sections of a sequence record; called via Ajax
 *
 * TEST URL: /data_center/sequence/AF123535
 *
 * history:
 *  06/18/12  eksc  created from old website code (cgi-bin/displayseqrecord.cgi)
 *
 *    >>>>>>>>>>>>>> NO LONGER SUPPORTED <<<<<<<<<<<<<<
 */

  include_once('../lib/Bauplan.php');
  include_once("../include/db-api.php");
  include_once("../include/api_tools.php");
  include_once('../include/gp_lib.php');
  include_once('../include/annotation_lib.php');
  include_once('../include/gene_center_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $id   = getCGIParam("id", 'G', false);
  $type = getCGIParam("type", 'G', false);
logMessage("sequence_data.php: id=$id, type=$type");
  
  if (!$id) {
    reportError("No id given to sequence_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to sequence_data.php.");
    exit;
  }

  $username = getCookie('username', false);
  $password = getCookie('password', false);
  $userid   = getCookie('userid',   false);

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/sequence_sections.bau');
  
  $DBConn = connect_to_database();

  // If annotator, check for super curator
  if ($username) {
    $user_info = get_user_info($DBConn, $username);
    $super_curator = ($user_info['curation_lvl'] <= -5);
    $author_id = $user_info['annotation_author_id'];
  }

  // Clean up input typed by user
  $id = validate_input($DBConn, $id);

  switch ($type) {
    case 'top':
      showTop($tmpl, $id, $DBConn);
      break;
    case 'overview':
      showOverview($tmpl, $id, $DBConn);
      break;
    case 'annotations':
      showAnnotations($tmpl, $id, $DBConn);
      break;
    case 'genomebrowser':
      showGenomeBrowsers($tmpl, $id, $DBConn);
      break;
    case 'sequence':
      showSequence($tmpl, $id, $DBConn);
      break;
    case 'related_information':
      showRelatedInformation($tmpl, $id, $DBConn);
      break;
  }
  
  $bauplan->publish();
  
  
  //////////////////////////////////////////////////////////////////////////////
  
  function showTop($tmpl, $id, $DBConn) {
    
    global $system;
    $tmpl->get('archive_url')->replace($system['archive_url']);
    global $username, $userid, $password;
    
    $query = "SELECT * from z_sequence WHERE SEQ_ID = " . $DBConn->quote($id);
    $statement = make_query($DBConn, $query);
    $arrSeq = retrieve_row($statement);
	
	$tmpl->get('id')->replace($id);
  
    $gb_id = ($arrSeq["genbank_acc"] == '') ? 'none' : $arrSeq["genbank_acc"];
      
    $tmpl->get('seq_type')->replace($arrSeq["seq_type"]);
    $tmpl->get('seq_id')->replace($arrSeq["seq_id"]);
    $tmpl->get('genbank_acc')->replace($gb_id);
    $tmpl->get('seq_title')->replace($arrSeq["seq_title"]);
  
    if (($arrSeq["seq_type"] != "EST Contig") 
         && ($arrSeq["seq_type"] != "GSS Contig")) {
      if ((strlen($username) > 0) && (strlen($password) > 0) 
          && (strlen($userid) > 0)) {
        $tmpl->get('user-annotation')->unmute();
      }
    }
    
    $tmpl->get('top')->unmute();
  }//showTop
  
  
  function showOverview($tmpl, $id, $DBConn) {
    $query = "SELECT * from z_sequence WHERE SEQ_ID = " . $DBConn->quote($id);
    $statement = make_query($DBConn, $query);
    $arrSeq = retrieve_row($statement);  
    
    $tmpl->get('entry_date')->replace($arrSeq["entry_date"]);
    $tmpl->get('seq_length')->replace($arrSeq["seq_length"]);
   
    // brief comments
    $query_comments = "
      SELECT MEMO AS BRIEF_COMMENT
      FROM MEMO 
      WHERE TYPE_TERM = 32124 AND ID = " . $DBConn->quote($id) . "
      ORDER BY ORDER1";
    $statement_comments = make_query($DBConn, $query_comments);
    if ($arrComments = get_all_rows($statement_comments)) {
      $tmpl->get('brief-comments-list')->loop($arrComments);
      $tmpl->get('brief-comments')->unmute();
    }

    // other comments
    $query_comments = "
      SELECT MEMO AS COMMENT 
      FROM MEMO 
      WHERE TYPE_TERM != 32124 AND ID = " . $DBConn->quote($id) . " ORDER BY ORDER1";
    $statement_comments = make_query($DBConn, $query_comments);
    if ($arrComments = get_all_rows($statement_comments)) {
      $tmpl->get('other-comments-list')->loop($arrComments);
      $tmpl->get('other-comments')->unmute();
    }

    // Position
    $query_fpc = "
      SELECT ACC, CLONE_NAME, CHR, CHR_START, CHR_END, CONTIG, CONTIG_START, 
             CONTIG_END 
      FROM ZA_FPCCONTIG 
      WHERE ACC = '" . $arrSeq["genbank_acc"] . "'";
    $stmt_fpc = make_query($DBConn, $query_fpc);
    $arrFPC = retrieve_row($stmt_fpc);
 
    $contig_flag = 0;
    $chr_flag = 0;
  
    $position = '';
    if ($arrFPC["chr"]) {
      $chr_flag = 1;
      $position .= "<br>&nbsp;&nbsp;&nbsp;&nbsp;<b>Chromosome:</b> " . $arrFPC["chr"] . "<br>";
    }
    if ($arrFPC["chr_start"]) {
      $position .= "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Chromosome Start:</b> " . number_format($arrFPC["chr_start"], 0, '.', ',') . "<br>";
    }
    if ($arrFPC["chr_end"]) {
      $position .= "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Chromosome Stop:</b> " . number_format($arrFPC["chr_end"], 0, '.', ',') . "<br>";
    }
    if ($arrFPC["contig"]) {
      $contig_flag = 1;
      $position .= "<br>&nbsp;&nbsp;&nbsp;&nbsp;<b>Contig:</b> " . "<a href=\"/data_center/fpc?id=" . $arrFPC["contig"] . "\" > " . $arrFPC["contig"] . "</a>" .  "<br>";
    }
    if ($arrFPC["contig_start"]) {
      $position .= "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Contig Start:</b> " . number_format($arrFPC["contig_start"], 0, '.', ',') . "<br>";
    }
    if ($arrFPC["contig_end"]) {
      $position .= "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Contig Stop:</b> " . number_format($arrFPC["contig_end"], 0, '.', ',') . "<br>";
    }
  
    $query_gc = "
      SELECT CHROMOSOME, CONTIG, BIN, LOCUS_ID, SEQ_START, SEQ_STOP 
      FROM GENOME_COORDINATE, PROBE 
      WHERE PROBE_ID = " . (int) $id . " AND NAME = " . $DBConn->quote($arrSeq["genbank_acc"]);
    $query_fpc = "
      SELECT CHROMOSOME, CONTIG, BIN, LOCUS_ID, SEQ_START, SEQ_STOP 
      FROM GENOME_COORDINATE a, ID_SEQ b 
      WHERE b.KEY =" . $DBConn->quote($arrSeq["genbank_acc"]) . " a.PROBE_ID = " . (int) $id;
     
    $stmt_gc = make_query($DBConn, $query_gc);
    $arrGC = retrieve_row($stmt_gc);
    
    if ($arrGC["chromosome"] && $chr_flag == 0) {
      $position .= "<br>&nbsp;&nbsp;&nbsp;&nbsp;<b>Chromosome:</b> " . $arrGC["chromosome"] . "<br>";
    }
    if ($arrGC["BIN"]) {
      $tok = strtok($arrGC["BIN"], ".");
      $bin1 = $tok;
      $tok = strtok(".");
      $sub1 = $tok;
      $position .= "<br>&nbsp;&nbsp;&nbsp;&nbsp;<b>Bin:</b> " . "<a href=\"/bin_viewer??bin=" . $bin1 . "&sub=" . $sub1 . "\" > " . $arrGC["BIN"] . "</a>" .  "<br>";
    }
  
    if ($arrGC["contig"] && $contig_flag == 0) {
      $position .= "<br>&nbsp;&nbsp;&nbsp;&nbsp;<b>Contig:</b> " . "<a href=\"displayfpccontigrecord.cgi?id=" . $arrGC["contig"] . "\" > " . $arrGC["contig"] . "</a>" .  "<br>";
    }
    if ($arrGC["seq_start"]) {
      $position .= "<br>&nbsp;&nbsp;&nbsp;&nbsp;<b>Sequence Start:</b> " . number_format($arrGC["seq_start"], 0, '.', ',') . "<br>";
    }
    if ($arrGC["seq_stop"]) {
      $position .= "<br>&nbsp;&nbsp;&nbsp;&nbsp;<b>Sequence Stop:</b> " . number_format($arrGC["seq_stop"], 0, '.', ',') . "<br>";
    }
    
    if ($position == '') $position = 'unknown';
    $tmpl->get('position')->replace($position);
  
    $tmpl->get('overview')->unmute();
  }//showOverview


  function showAnnotations($tmpl, $id, $DBConn) {
    global $username, $super_curator, $author_id;

    // Get the record
    $query = "SELECT * from z_sequence WHERE SEQ_ID = " . $DBConn->quote($id);
    $statement = make_query($DBConn, $query);
    $arrSeq = retrieve_row($statement);
    
    /////// Look for user annotations ///////
    $annotations = getAnnotationHTML($DBConn, $id, '', $username, $author_id, 
                                     $super_curator, 'id');
    if ($annotations) {
      $tmpl->get('annotation-list')->replace($annotations);
      $tmpl->get('annotation-user')->unmute();
    }
    else {
      $tmpl->get('no-user')->unmute();
    }
    
    // Always show curation section; will prompt for log-in if need be
    $tmpl->get('curation')->unmute();
  
    $tmpl->get('id')->replace($id);
    $tmpl->get('rec_name')->replace($arrSeq['genbank_acc']);

    $tmpl->get('annotations')->unmute();
  }//showAnnotations


  function showGenomeBrowsers($tmpl, $id, $DBConn) {
    global $system;
    
    // get sequence record
    $query = "SELECT * FROM z_sequence WHERE seq_id = " . $DBConn->quote($id);
    $statement = make_query($DBConn, $query);
    $arrSeq = retrieve_row($statement);

    $tmpl->get('display-v3')->replace('inline');
    showBrowser($tmpl, $arrSeq, 'B73 RefGen_v3');
    
    $tmpl->get('display-v2')->replace('none');
    showBrowser($tmpl, $arrSeq, 'RefGen_v2');
    
    $tmpl->get('display-v1')->replace('none');
    showBrowser($tmpl, $arrSeq, 'RefGen_v1');
    
    $tmpl->get('genome-browsers')->unmute();
  }//showGenomeBrowsers
  
  
  function showSequence($tmpl, $id, $DBConn) {
    $query = "SELECT * from z_sequence WHERE SEQ_ID = " . $DBConn->quote($id);
    $statement = make_query($DBConn, $query);
    $arrSeq = retrieve_row($statement);
    
    $tmpl->get('genbank_acc')->replace($arrSeq['genbank_acc']);
    $tmpl->get('seq_id')->replace($arrSeq['seq_id']);
    
    $tmpl->get('sequence')->unmute();
  }//showSequence
  
  
  function showRelatedInformation($tmpl, $id, $DBConn) {
    $no_related = true;
    
    $query = "SELECT * from z_sequence WHERE SEQ_ID = " . $DBConn->quote($id);
    $statement = make_query($DBConn, $query);
    $arrSeq = retrieve_row($statement);
    //echo "<pre>";var_dump($arrSeq);echo "</pre>";

    $links = '';
    if (($arrSeq["seq_type"] != "Protein") 
         && ($arrSeq["seq_type"] != "EST Contig") 
         && ($arrSeq["seq_type"] != "GSS Contig") 
         && ($arrSeq["seq_type"] != "GSS Plasmid Contig")) {
      $no_related = false;
      
      $links .= "<b>Other Databases</b>: "
              . "<a href=\"http://www.plantgdb.org/search/display/data.php?Seq_ID=" 
              . $arrSeq["seq_id"] . "\">PlantGDB</a> | "
              . "<a href=\"http://getentry.ddbj.nig.ac.jp/getentry/ddbj/" 
              . $arrSeq["genbank_acc"] . "\">DDBJ</a> | "
              . "<a href=\"https://www.ebi.ac.uk/ena/browser/view/" 
              . $arrSeq["genbank_acc"] . "\">EMBL</a> | "
              . "<a href=\"https://www.ncbi.nlm.nih.gov/entrez/query.fcgi?cmd=search&amp;db=nucleotide&amp;dopt=genbank&amp;term=" 
              . $arrSeq["genbank_acc"] . "\">GenBank</a>";
      if ($arrSeq["seq_type"] == "mRNA") {
        $links .= " | <a href=\"http://www.tigr.org/docs/tigr-scripts/tgi/est_report.pl?species=maize&amp;GB=" 
                . $arrSeq["genbank_acc"] . "\">TIGR ZmGI</a>";
      }
      $links .= "<br>\n";
    }//not protein EST, GSS
    
    else if($arrSeq["seq_type"] == "Protein") {
      $no_related = false;
      
      $links .= "<b>Other Databases</b>: "
              . "<a href=\"https://www.ncbi.nlm.nih.gov/entrez/viewer.fcgi?cmd=Retrieve&db=Protein&dopt=Brief&list_uids=" 
              . $arrSeq['seq_id'] . "\">GenBank</a> | "
              . "<a href=\"http://us.expasy.org/cgi-bin/niceprot.pl?" 
              . $arrSeq["genbank_acc"] . "\">SwissProt</a> | "
              . "<a href=\"http://www.gramene.org/db/protein/protein_search?acc=" 
              . $arrSeq["genbank_acc"] . "\">Gramene</a><br>";
    }//Protein

    $tmpl->get('links')->replace($links);
    
    $comments = '';
    if (strlen($arrSeq["seq_comments"]) > 2) { 
      $no_related = false;
      
      $comments .= "<b>Comments:</b> " . $arrSeq["seq_comments"] . "<br>\n";
    }//comments
    
    $tmpl->get('comments')->replace($comments);
    
    $flis_string = flis_check($arrSeq["genbank_acc"]);
    if(strlen($flis_string) > 0)
      echo $flis_string . "\n";

    if ($arrSeq["seq_type"] == "GSS") {
      $query_part_of_tuc = "
        SELECT GSSCONTIG_ID 
        FROM Z_GSSCONTIG_GSS 
        WHERE GSS_ID LIKE '" . $arrSeq["seq_id"] . "'";
      $stmt_part_of_tuc = make_query($DBConn, $query_part_of_tuc);
      $arrPartOfTUC = retrieve_row($stmt_part_of_tuc);
      if (strlen($arrPartOfTUC["GSSCONTIG_ID"]) > 0) {
        $no_related = false;
        
        $tmpl->get('gsscontig_id')->replace($arrPartOfTUC["GSSCONTIG_ID"]);
        $tmpl->get('gss')->unmute();
      }
    }//GSS

    if (($arrSeq["seq_type"] == "EST") || ($arrSeq["seq_type"] == "cDNA")) {
      $no_related = false;
      
      $query_part_of_tuc = "
        SELECT TUC_ID 
        FROM Z_TUC_EST 
        WHERE EST_GI LIKE '" . $arrSeq["seq_id"] . "'";
      $stmt_part_of_tuc = make_query($DBConn, $query_part_of_tuc);
      $arrPartOfTUC = retrieve_row($stmt_part_of_tuc);
      if (strlen($arrPartOfTUC["TUC_ID"]) > 0) {
        $tmpl->get('tuc_id')->replace($arrPartOfTUC["TUC_ID"]);
        $tmpl->get('part-of-tuc')->unmute();
      }
      
      $tmpl->get('genbank_acc')->replace($arrSeq["genbank_acc"]);
      $tmpl->get('est')->unmute();
    }//EST or cDNA

    $mrna = '';
    if (($arrSeq["seq_type"] == "mRNA") 
          || ($arrSeq["seq_type"] == "EST Singlet")) {
      $query_microarray_locs = "
        SELECT * FROM Z_ARRAY WHERE GI LIKE '" . $arrSeq["seq_id"] . "'";
      $stmt_microarray_locs = make_query($DBConn, $query_microarray_locs);
      $arrMicroarrayLocs = get_all_rows($stmt_microarray_locs);

      if ($arrMicroarrayLocs 
            && strlen($arrMicroarrayLocs[0]["ARRAY_NUMBER"]) > 0) {
        $no_related = false;
        
        $tmpl->get('mrna-list')->loop($arrMicroarrayLocs);
        $tmpl->get('mrna')->unmute();
      }
    }//mRNA or EST singlet

    if($arrSeq["seq_type"] == "EST Contig") {
      $no_related = false;
      
      $query_member_ests = "
        SELECT A.ORIENTATION, A.START_POS AS START_POS, A.END_POS AS END_POS, 
               B.SEQ_ID, B.GENBANK_ACC, B.SEQ_TITLE 
        FROM Z_TUC_EST A 
          LEFT OUTER JOIN Z_SEQUENCE B ON A.EST_GI = B.SEQ_ID 
        WHERE A.TUC_ID LIKE '" . $arrSeq["seq_id"] . "' 
        ORDER BY A.START_POS";
      $stmt_member_ests = make_query($DBConn, $query_member_ests);
      $ests = false;
      $arrMemberESTs = get_all_rows($stmt_member_ests);
      if ($arrMemberESTs and count($arrMemberESTs) > 0) {
        $tmpl->get('est-contig-ests-list')->loop($arrMemberESTs);
        $tmpl->get('est-contig-ests')->unmute();
      }
      
      $tmpl->get('seq_id')->replace($arrSeq["seq_id"]);
      $tmpl->get('est-contig')->unmute();
    }//Est contig
    
    if($arrSeq["seq_type"] == "GSS Contig") {
      $no_related = false;
      
      $query_membership = "
        SELECT B.SEQ_TITLE, B.GENBANK_ACC, B.SEQ_ID, A.SIGN AS ORIENTATION, 
               A.G_START, A.G_END 
        FROM Z_GSSCONTIG_GSS A 
          JOIN Z_SEQUENCE B ON A.GSS_ID = B.SEQ_ID 
        WHERE A.GSSCONTIG_ID LIKE '" . $arrSeq["seq_id"] . "' 
        ORDER BY A.G_START";
      $stmt_membership = make_query($DBConn, $query_membership);
      $arrMembership = get_all_rows($stmt_membership);
      
      // Fix accesions:
      foreach ($arrMembership as $row) {
        if (strlen($row["genbank_acc"]) < 1) {
          $row["genbank_acc"] = $row["seq_id"];
        }
      }
      $tmpl->get('gss-contig')->unmute();
    }// GSS contig

    if (($arrSeq["seq_type"] != "EST Contig") 
        && ($arrSeq["seq_type"] != "GSS Contig")) {
      $no_related = false;
      
      $query_related_probes = "
        select distinct(a.id) 
        from id_seq a 
          left outer join id_num b on a.id = b.id 
        where b.type_term = 105888 and a.seq = '" . $arrSeq["seq_id"] . "'";
      $statement_related_probes = make_query($DBConn, $query_related_probes);
      $arrRelprobes = get_all_rows($statement_related_probes);
      $probelist = array();
      if ($arrRelprobes && count($arrRelprobes) > 0) {
        $list_of_related_loci = "";
        
        // Need to add some fields to the rows to fill in template and build a
        //   list of probe ids and loci
        $bau_fields = array();
        for ($i=0; $i<count($arrRelprobes); $i++) {
          array_push($probelist, $arrRelprobes[$i]['id']);
          
          $query_probe_name = "
            SELECT probe.name, probe.type as probe_type, term.name as type_name 
            FROM probe, term 
            WHERE term.id=probe.type AND probe.id = " . $arrRelprobes[$i]["id"];
          $statement_probe_name = make_query($DBConn, $query_probe_name);
          $arrProbeName = retrieve_row($statement_probe_name);

          // Build view URL
//TODO: this will need some work to fix for redesigned data centers
          $url = "/data_center/" . get_probe_type($arrProbeName['probe_type'])
               . "?id=" . $arrRelprobes[$i]["id"];
          
          // Fields needed for bauplan template
          $bau_fields[$i] = array('related-bio-entities-url'=> $url,
                                  'probe_name' => $arrProbeName['name'],
                                  'type_name'  => $arrProbeName['type_name']);
        }//each row
        
        $tmpl->get('related-bio-entities-list')->loop($bau_fields);
        $tmpl->get('related-bio-entities')->unmute();
      }//found related probes
      
      $list_of_related_loci = '';
      $query_related_loci = "
        SELECT B.NAME AS LOCUS_NAME, B.ID AS LOCUS_ID 
        FROM LOCUS B
          JOIN ID_SEQ C ON B.ID = C.ID
          JOIN ID_NUM D on B.ID = D.ID 
        WHERE C.SEQ = '" . $arrSeq["seq_id"] . "' and D.CURATION_LVL = 0";
      $statement_related_loci = make_query($DBConn, $query_related_loci);
      while ($arrRelatedLoci = retrieve_row($statement_related_loci)) {
        $list_of_related_loci .= "<b><a href=\"/data_center/locus/?id=" 
                              . $arrRelatedLoci["LOCUS_ID"] . "\">" 
                              . trim($arrRelatedLoci["LOCUS_NAME"]) 
                              . "</a></b><br>\n";
      }

	  $additional_entities = '';
	  if (count($probelist) > 0) {////This whole section may not work
        
        $query_r2probes = "
          select b.id, b.name, b.type, d.name as relation_name, a.relation 
          from relation a join probe b on a.related_id = b.id 
            join term d on a.relation = d.id 
          where a.id in (" . implode(',', $probelist) . ")";
        $stmt_r2probes = make_query($DBConn, $query_r2probes);
        while ($arrR2probes = retrieve_row($stmt_r2probes)) {
          $additional_entities .= 
                 "<a href=\"/data_center/" . get_probe_type($arrR2probes["TYPE"]) . 
                 "?id=" . $arrR2probes["ID"] . "\">" . trim($arrR2probes["NAME"]) . "</a> (" .
                 get_probe_type($arrR2probes["TYPE"]) . ")";
        
          if($arrR2probes["RELATION"] == "403322")
            $additional_entities .= " is detected by this sequence<br>\n";
          else if(($arrR2probes["RELATION"] == "129779") && ($arrR2probes["TYPE"] == "34"))
            $additional_entities .= " is this sequence<br>\n";
          else if($arrR2probes["RELATION"] == "887676")
            $additional_entities .= " is related to this sequence<br>\n";
          else
            $additional_entities .= " " . strtolower($arrR2probes["RELATION_NAME"]) . " this sequence<br>\n";
        }//each row
      }
      
      if ($additional_entities != '') {
        $no_related = false;
        $tmpl->get('additional-entities-list')->replace($additional_entities);
        $tmpl->get('additional-entities')->unmute();
      }

      if ($list_of_related_loci != '') {
        $no_related = false;
        $tmpl->get('list_of_related_loci')->replace($list_of_related_loci);
        $tmpl->get('related-loci')->unmute();
      }
  
      $query_maps = "
        SELECT DISTINCT(A.ID) as map_id, z.id as locus_id, A.NAME as MAP_NAME, z.NAME as LOCINAME, 
               C.ID AS LOCUS_ID, cast(C.VALUE as float) 
        FROM MAP A 
          LEFT OUTER JOIN ID_NUM B ON A.ID = B.ID 
          LEFT OUTER JOIN LOCUS_COORDINATES C ON A.ID = C.MAP 
          join LOCUS z ON c.ID = z.ID 
        WHERE C.ID IN (SELECT B.ID AS LOCUS_ID 
                       FROM LOCUS B JOIN ID_SEQ C ON B.ID = C.ID 
                       WHERE C.SEQ = '" . $arrSeq["seq_id"] . "')
              AND B.CURATION_LVL = 0";
      $stmt_maps = make_query($DBConn, $query_maps);
      $arrMaps = get_all_rows($stmt_maps);
      
      // Since postgres is weird about the ORDER BY clause when DISTINCT appears
      //   in the SELECT list, try sorting with PHP.
logVarDump($arrMaps, "arrMaps");
      if ($arrMaps && count($arrMaps) > 0) {
        $no_related = false;
        
        array_multisort($arrMaps[1], SORT_ASC, SORT_STRING);
logVarDump($arrMaps, "arrMaps sorted");

        // Need to add a field
        for ($i=0; $i<count($arrMaps); $i++) {
          $arrMaps[$i]['map_name'] = fix_map_name($arrMaps[$i]["map_name"]);
        }
        $tmpl->get('map-list')->loop($arrMaps);
        $tmpl->get('maps')->unmute();
      }//maps
  
      // 65737 = 'Variation'
      $query_related_variations = "
        select distinct(a.id) 
        from id_seq a 
          left outer join id_num b on a.id = b.id 
        where b.type_term = 65737 and b.curation_lvl = 0 
              and a.seq = '" . $arrSeq["seq_id"] . "'";
      $statement_related_variations = make_query($DBConn, $query_related_variations);
      $variations = '';
      while ($arrRelvars = retrieve_row($statement_related_variations)) {
        $query_var_name = "SELECT name FROM variation WHERE id = " . $arrRelvars["ID"];
        $statement_var_name = make_query($DBConn, $query_var_name);
        $arrVarName = retrieve_row($statement_var_name);
        $variations .= "<a href=\"/data_center/variation?id=" . $arrRelvars["ID"] . "\">" 
        . $arrVarName["NAME"] . "</a><br>\n";
      }
      if ($variations != '') {
        $no_related = false;
        $tmpl->get('variation-list')->replace($variations);
        $tmpl->get('related-variations')->unmute();
      }//variations
  
      // 45974 = 'Nucleic acid traits' (Gene Product)
      $query_gene_products = "
        select distinct(a.id) 
        from id_seq a, id_num b 
        where a.id = b.id and b.type_term = 45974 and b.curation_lvl = 0 
              and a.seq = '" . $arrSeq["seq_id"] . "'";
      $statement_gene_products = make_query($DBConn, $query_gene_products);
      $gene_products = '';
      while($arrRelGPs = retrieve_row($statement_gene_products)) {
        $query_gp_name = "SELECT name FROM gene_product WHERE id=" . $arrRelGPs["ID"];
        $statement_gp_name = make_query($DBConn, $query_gp_name);
        $arrGPName = retrieve_row($statement_gp_name);
        $gene_products .= "<a href=\"/data_center/gene_product?id=" . $arrRelGPs["ID"] . "\">" 
        . $arrGPName["NAME"] . "</a><br>\n";
      }
  
      if ($gene_products != '') {
        $no_related = false;
        $tmpl->get('gene-products')->replace($gene_products);
        $tmpl->get('related-gene-products')->unmute();
      }//gene products
  
      // 65737 = 'Variation'
      $query_related_stocks = "
        select a.id as stock_id, a.name as stock_name, a.available_from as avail, 
               c.id as variation_id, c.name as variation_name 
        from stock a, stock_genotypic_var b, variation c, id_num d 
        where a.id = b.id and b.variation = c.id and a.id = d.id 
              and d.curation_lvl = 0 
              and c.id in (select distinct(a.id) 
                           from id_seq a, id_num b 
                           where a.id = b.id and b.type_term = 65737 
                                 and b.curation_lvl = 0 
                                 and a.seq = '" . $arrSeq["seq_id"] . "')";
      $stmt_related_stocks = make_query($DBConn, $query_related_stocks);
      $stocks = '';
      while ($arrRelStocks = retrieve_row($stmt_related_stocks)) {
        if($arrRelStocks["AVAIL"] == "25725") //Maize Genetics Cooperation - Stock Center
          $stocks .= "<b>";
        $stocks .= "<a href=\"/data_center/stock?id=" . $arrRelStocks["STOCK_ID"] . "\">" . $arrRelStocks["STOCK_NAME"] . "</a>";
        if ($arrRelStocks["AVAIL"] == "25725")
          $stocks .= "</b>";
        $stocks .= " (expresses variation <a href=\"/data_center/variation?id=" 
                 . $arrRelStocks["VARIATION_ID"] . "\">" 
                 . trim($arrRelStocks["VARIATION_NAME"]) . "</a>)";
        $stocks .= "<br>\n";
      }
  
      if ($stocks != '') {
        $no_related = false;
        $tmpl->get('stock-list')->replace($stocks);
        $tmpl->get('related-stocks')->unmute();
      }//stocks
  
      $query_gel_patterns = "
        select distinct(c.id) as gel_id, c.name as gel_name
        from id_seq a 
          join probe b on a.id = b.id 
          join gel_pattern c on c.probe = b.id 
        where a.seq like '" . $arrSeq["seq_id"] . "'";
      $stmt_gel = make_query($DBConn, $query_gel_patterns);
      $arrGelP = get_all_rows($stmt_gel);
      if ($arrGelP && count($arrGelP)) {
        $no_related = false;
        $tmpl->get('gel-pattern-list')->loop($arrGelP);
        $tmpl->get('gel-patterns')->unmute();
      }//gel patterns
    }//EST or GSS contig
    
    // Check if no related information was found
    if ($no_related) {
      $tmpl->get('no-related')->unmute();
    }
    
    $tmpl->get('related-information')->unmute();
  }//showRelatedInformation




////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////


function showBrowser($tmpl, $arrSeq, $version) {
  global $system;

  // need abbreviated version name in upper and lower case (sigh)
  $lc_short_version = strtolower(preg_replace("/.*_(.*)/", "$1", $version));
  $uc_short_version = strtoupper($lc_short_version);

  // Start a new template to hold the details
  $bauplan = new Bauplan('');
  $sub_tmpl = $bauplan->template()->load('../templates/data_center/sequence_genomebrowser_details.bau');
  
  if (!$arrSeq) {
    $sub_tmpl->get("not-on-browser")->unmute();
    return;
  }

  // Unfortunately, some information about the gene models remains hard-coded:
  $gm_info = getGeneModelInfo(array('assembly_version'=>$version)); // also gets browser names
  
  // Get GBrowse URLs
  $gbrowse_url = $system["GBROWSE_URL_$uc_short_version"];
  $gbrowse_img_url = $system["GBROWSE_IMG_URL_$uc_short_version"];
  
  // Check if sequence is on the browser via accession
  $found = false;
  $found_name = '';
  $url = "$gbrowse_img_url/?name=" . $arrSeq['genbank_acc'] 
         . ";h_feat=" .  $arrSeq['genbank_acc'] . "@red;width=400;type=BAC";
//logMessage("Test URL for $version: $url");

  if ($fh = fopen($url, "rb")) {
    // The word 'Error' will appear in contents if object not found
    $contents = '';
    while (!feof($fh)) {
      $contents .= fread($fh, 8192);
    }
    fclose($fh);
  }
  else {
    $contents = "Error";
  }

  if (!strpos($contents, "Error")) {
    $found_name = $arrSeq['genbank_acc'];
    $found = true;
  } 
  else {
    // Check if sequence is on the browser via id
    $url = "$gbrowse_img_url/?name=" . $arrSeq['seq_id'] 
           . ";h_feat=" .  $arrSeq['seq_id'] . "@red;width=400;type=BAC";
//logMessage("Test URL for $version: $url");
    if ($fh = fopen($url, "rb")) {
      // The word 'Error' will appear in contents if object not found
      $contents = '';
      while (!feof($fh)) {
        $contents .= fread($fh, 8192);
      }
      fclose($fh);
    }
    else {
      $contents = "Error";
    }
  
    if (!strpos($contents,"Error")) {
      $found_name = $arrSeq['seq_id'];
      $found = true;
    }
  }
    
  if (!$found) {
    // Not on browser
    $sub_tmpl->get('no-browser')->unmute();
  }
  else {
    $sub_tmpl->get('gbrowse_url')->replace($gbrowse_url);
    $sub_tmpl->get('gbrowse_img_url')->replace($gbrowse_img_url);
    $sub_tmpl->get('seq_type')->replace($arrSeq['seq_type']);
    $sub_tmpl->get('seq_id')->replace($found_name);
    $sub_tmpl->get('browser')->unmute();
    $sub_tmpl->get('genome-browser-details')->unmute();
  }

  $sub_tmpl->get('assembly_name')->replace($gm_info['assembly_version']);
  $sub_tmpl->get('genome-browser-details')->unmute();
  $html = $sub_tmpl->getHTML();
  $tmpl->get("contents-$lc_short_version")->replace($html);
}//showBrowser

  
  // Super ICK!
  function flis_check($accession) {
    $output = "";
    if($accession == "AY751079")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41513\">umc76</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 1.03.</p>";
    if($accession == "AY771210")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=97241\">asg45(ptk)</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 1.04.</p>";
    if($accession == "AY771211")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41507\">umc67a</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 1.06.</p>";
    if($accession == "DQ001865")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=97250\">asg62</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 1.07.</p>";
    if($accession == "AY771212")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=59575\">umc161a</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 1.11.</p>";
    if($accession == "AY771213")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41487\">umc53a</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 2.02.</p>";
    if($accession == "AY771214")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41498\">umc6a</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 2.03.</p>";
    if($accession == "DQ001866")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41461\">umc34</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 2.04.</p>";
    if($accession == "AY771215")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41400\">umc131</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 2.05.</p>";
    if($accession == "AY771216")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41496\">umc5a</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 2.07.</p>";
    if($accession == "DQ001867")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41458\">umc32a</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 3.01.</p>";
    if($accession == "AY771217")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=97284\">asg24</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 3.03.</p>";
    if($accession == "DQ001868")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=97287\">asg48</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 3.04.</p>";
    if($accession == "DQ005498")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41362\">umc102</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 3.05.</p>";
    if($accession == "AY771218")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41438\">umc17a</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 3.08.</p>";
    if($accession == "DQ005499")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=57310\">cyp1</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 3.10.</p>";
    if($accession == "DQ007988")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=40975\">npi386(eks)</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 4.04.</p>";
    if($accession == "AY771219")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41420\">umc156a</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 4.06.</p>";
    if($accession == "DQ007989")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41505\">umc66a(lcr)</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 4.07.</p>";
    if($accession == "DQ007990")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=57339\">php20608a</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 4.10.</p>";
    if($accession == "DQ007991")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=56925\">tub4</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 5.03.</p>";
    if($accession == "DQ015673")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=97332\">csu93b</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 5.05.</p>";
    if($accession == "AY771220")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41392\">umc126a</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 5.06.</p>";
    if($accession == "AY771221")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41120\">php10017</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 5.09.</p>";
    if($accession == "AY772450")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41520\">umc85a</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 6.01.</p>";
    if($accession == "DQ015674")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=40977\">npi393</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 6.03.</p>";
    if($accession == "AY772451")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41504\">umc65a</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 6.04.</p>";
    if($accession == "DQ059316")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41466\">umc38a</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 6.06.</p>";
    if($accession == "DQ059317")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=97371\">asg49</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 7.03.</p>";
    if($accession == "AY772452")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=97361\">umc245</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 7.05.</p>";
    if($accession == "DQ059318")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41437\">umc168</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 7.06.</p>";
    if($accession == "DQ059319")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=40864\">npi220a</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 8.01.</p>";
    if($accession == "DQ059320")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41389\">umc124a(chk)</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 8.03.</p>";
    if($accession == "AY772453")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=40994\">npi414a</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 8.08.</p>";
    if($accession == "AY772454")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41373\">umc109</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 9.01.</p>";
    if($accession == "AY772455")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41535\">umc95</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 9.05.</p>";
    if($accession == "DQ059321")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=57434\">php20075a(gast)</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 10.01.</p>";
    if($accession == "DQ059322")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41399\">umc130</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 10.03.</p>";
    if($accession == "AY772456")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41475\">umc44a</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 10.06.</p>";
    if($accession == "DQ123890")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41422\">umc157(chn)</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 1.02.</p>";
    if($accession == "DQ123891")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=64640\">csu3</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 1.05.</p>";
    if($accession == "DQ123892")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41395\">umc128</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 1.08.</p>";
    if($accession == "DQ123893")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=97271\">umc255a</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 2.06.</p>";
    if($accession == "DQ123894")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=97276\">asg20</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 2.08.</p>";
    if($accession == "DQ123895")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41481\">umc49a</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 2.09.</p>";
    if($accession == "DQ123896")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=66779\">csu32</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 3.02.</p>";
    if($accession == "DQ123897")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41502\">umc63a</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 3.09.</p>";
    if($accession == "DQ123898")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41456\">umc31a</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 4.03.</p>";
    if($accession == "DQ123899")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41486\">umc52</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 4.07.</p>";
    if($accession == "DQ123900")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=57341\">umc169</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 4.10.</p>";
    if($accession == "DQ123901")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41444\">umc21</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 6.05.</p>";
    if($accession == "DQ123902")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=41401\">umc132a(chk)</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 6.07.</p>";
    if($accession == "DQ123903")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=40907\">npi268a</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 8.07.</p>";
    if($accession == "DQ123904")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=97409\">asg12</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 9.07.</p>";
    if($accession == "DQ123905")
      $output = "<p><b>FLIS</b> This is the full length insert sequence for <a href=\"/data_center/locus/?id=97418\">umc259a</a>, the <a href=\"/bin_viewer\">core bin marker</a> for bin 10.05.</p>";
    
    return $output;
  }//flis_check
  
  function get_probe_type($type) {
       if ($type == "34")
          return "est";
        else if($type == "171715")
          return "bac";
        else if(($type == "393660") || ($type == "747274"))
          return "overgo";
        else if($type == "104436")
          return "ssr";
        else
          return "marker";
  }

?>