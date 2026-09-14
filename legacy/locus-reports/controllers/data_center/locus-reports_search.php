<?PHP
/* file: locus-reports_search.php
 *
 * purpose: display locus reports page
 *
 * history:
 *  01/22/14  jportwood - creating initial locus reports page
 */

  $loc_report_search = $mgdb->get('locus-reports');
  $loc_report_left = $loc_report_search->get('locus-reports-content');

  $DBConn = connect_to_database();

  $report = getCGIParam("report", "GP", false);
  $report_note = '';

  /* Retired 2026-09-06 (Carson): report=genes and report=candidate.

     Both were flat lists of every locus of one type -- 26,115 rows in the
     first, which is why that page weighed 2.6 MB -- and the Locus Data Hub now
     does the same thing with a search that filters, sorts and exports. Its
     `type` parameter takes the term id and runs the search on load, so these
     land on the same set rather than on a general page:

         genes     -> type 101   (Gene)
         candidate -> type 24621 (Gene candidate)

     transgene and family are NOT retired: they carry curator comments, related
     loci and references that the hub search does not show.

     Rollback: delete this block. The report branches below are untouched. */
  if ($report == "genes") {
    header('Location: /data_center/locus?type=101', true, 301);
    exit;
  }
  if ($report == "candidate") {
    header('Location: /data_center/locus?type=24621', true, 301);
    exit;
  }

  $list = ""; 
  
  if (!$list) {
    if($report == "genes") {
      $list_name = "Gene List";
      $description = "You are currently viewing the list of maize genes in the database.";
      $query = "
        SELECT A.ID, A.NAME, A.FULL_NAME
        FROM LOCUS A, ID_NUM B
        WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND A.TYPE = 101 AND A.SPECIES = 12808
        ORDER BY LOWER(NAME)";

      $statement = make_query($DBConn,$query);
      $list = get_all_rows($statement);
      $loc_report_search->get("report_note")->replace($report_note);
  $loc_report_search->get("list_name")->replace($list_name);
      $loc_report_left->get("description")->replace($description);
      $loc_report_left->get("genes_list")->loop($list);
      $loc_report_left->get("genes_sec")->unmute();
    }//genes
    
    else if($report == "candidate") {
      $list_name = "Gene Candidates";
      $description = "You are currently viewing the list of <a href=\"/data_center/term?id=24621\">gene candidates</a> ";
      $query = "
        SELECT A.ID, A.NAME, A.FULL_NAME 
        FROM LOCUS A, ID_NUM B 
        WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND A.TYPE = 24621 AND A.SPECIES = 12808 
        ORDER BY LOWER(NAME)";
      $statement = make_query($DBConn,$query);
      $list = get_all_rows($statement);

      $loc_report_search->get("report_note")->replace($report_note);
  $loc_report_search->get("list_name")->replace($list_name);
      $loc_report_left->get("description")->replace($description);
      $loc_report_left->get("candidate_list")->loop($list);
      $loc_report_left->get("candidate_sec")->unmute();
    }//candidate
    
    else if($report == "transgene") {
      $list_name = "Transgenes";
      $description = "Details on Transgenes";
      /* Dated from the records themselves rather than asserted: see the
         add-date distribution in the redesign notes. */
      $report_note = '<div style="margin: 0 0 18px; padding: 12px 16px; border-left: 4px solid #d99a0b; background: #fdf6e3; font-size: 14px; line-height: 1.5;"><strong>This is a historical list.</strong> 80 of these 89 transgene records were curated between 2003 and 2008, and only eight have been added since, the most recent in 2024. It is kept because the records it describes are still cited, not because it is being added to. For current work, search the <a href="/data_center/locus">Locus Data Hub</a>.</div>';
      $query = "
        SELECT A.ID, A.NAME, A.FULL_NAME 
        FROM LOCUS A, ID_NUM B 
        WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND A.TYPE = 40071 
        ORDER BY LOWER(NAME)";
      $statement = make_query($DBConn,$query);
      $list = get_all_rows($statement);
      for($i=0; $i < count($list); $i++){

        $query_comments = "SELECT memo FROM memo WHERE id = " . $list[$i]['id'];
        $stmt_comments = make_query($DBConn,$query_comments);
        $comments = true;
        $list[$i]['comments'] = "";
        while($arrComments = retrieve_row($stmt_comments))
        {
          if($comments)
            $comments = false;
          $list[$i]['comments'] .= "&nbsp;&nbsp;" . mgdb_safe_html($arrComments['memo']) . "<br>\n";
        }
        if($comments)
          $list[$i]['comments'] = "&nbsp;&nbsp;There are no notes available for this transgene.<br>\n";


        $query_gene_products = "
          SELECT B.NAME, B.ID 
          FROM LOCUS_GENE_PRODUCTS A, GENE_PRODUCT B, ID_NUM C
           WHERE A.ID = " . $list[$i]['id'] . " AND A.GENE_PRODUCT = B.ID 
                 AND B.ID = C.ID AND C.CURATION_LVL = 0";
        $stmt_gene_products = make_query($DBConn,$query_gene_products);
        $gene_products = true;
        $list[$i]['gp'] = "";
        while($arrGP = retrieve_row($stmt_gene_products))
        {
          if($gene_products)
            $gene_products = false;
          $list[$i]['gp'] .= "&nbsp;&nbsp;<a href=\"/data_center/gene_product?id=" . $arrGP['id'] . "\">" . $arrGP['name'] . "</a><br>\n";
        }
        if($gene_products)
         $list[$i]['gp'] = "&nbsp;&nbsp;There are no noted gene products available for this transgene.<br>\n";

        $query_related_loci = "
          SELECT B.NAME, B.ID, D.NAME AS RELATIONSHIP 
          FROM RELATION A, LOCUS B, ID_NUM C, TERM D 
          WHERE A.ID = " . $list[$i]['id'] . " AND A.RELATED_ID = B.ID 
                AND A.RELATION = D.ID AND B.ID = C.ID AND C.CURATION_LVL = 0";
        $stmt_related_loci = make_query($DBConn,$query_related_loci);
        $related_loci = true;
        $list[$i]['loci'] = "";
        while($arrRL = retrieve_row($stmt_related_loci))
        {
          if($related_loci)
            $related_loci = false;
          $list[$i]['loci'] .= "&nbsp;&nbsp;" . $arrRL['relationship'] . " <a href=\"/data_center/locus?id=" . $arrRL['id'] . "\">" . $arrRL['name'] . "</a><br>\n";
        }
        if($related_loci)
          $list[$i]['loci'] = "&nbsp;&nbsp;There are no noted related loci available for this transgene.<br>\n";

        $query_references = "
          SELECT B.NAME, B.ID, B.TITLE, D.NAME AS TYPE 
          FROM ID_REFERENCE A, REFERENCE B, ID_NUM C, TERM D 
          WHERE A.ID = " . $list[$i]['id'] . " AND A.REFERENCE = B.ID 
                AND A.CONTENTS = D.ID AND B.ID = C.ID AND C.CURATION_LVL = 0";
        $stmt_references = make_query($DBConn,$query_references);
        $references = true;
        $list[$i]['ref'] = "";
        while($arrRefs = retrieve_row($stmt_references))
        {
          if($references)
            $references = false;
          $list[$i]['ref'] .= "&nbsp;&nbsp;(" . $arrRefs['type'] . ") <a href=\"/data_center/reference?id=" . $arrRefs['id'] . "\">" . $arrRefs['name'] . "</a><br>\n";
          if(strlen($arrRefs['title']) > 0)
            $list[$i]['ref'] .= "&nbsp;&nbsp;&nbsp;&nbsp;<i>" . trim($arrRefs['title']) . "</i><br>\n";
        }
        if($references)
          $list[$i]['ref'] = "&nbsp;&nbsp;There are no noted references available for this transgene.<br>\n";
      }//all rows
      $loc_report_search->get("report_note")->replace($report_note);
  $loc_report_search->get("list_name")->replace($list_name);
      $loc_report_left->get("description")->replace($description);
      $loc_report_left->get("transgene_list")->loop($list);
      $loc_report_left->get("transgene_sec")->unmute();
    }//transgene
    else if($report == "family") {

      $list_name = "Gene Families";
      $description = "Details on Gene Families";
      $report_note = '<div style="margin: 0 0 18px; padding: 12px 16px; border-left: 4px solid #d99a0b; background: #fdf6e3; font-size: 14px; line-height: 1.5;"><strong>This is a historical list.</strong> 25 of these 28 gene-family records were curated between 2003 and 2005, and only three have been added since, the most recent in 2023. It is kept because the records it describes are still cited, not because it is being added to. For current work, search the <a href="/data_center/locus">Locus Data Hub</a>.</div>';
      $query_gene_family = "select a.name, a.id, a.full_name from locus a, id_num b where a.type = 40414 and a.id = b.id and b.curation_lvl = 0 order by lower(name)";
      $statement = make_query($DBConn,$query_gene_family);
      $count = 0;
      $list = array();
      while($arrEmployee = retrieve_row($statement)) {
        if(strlen($arrEmployee['full_name']) > 0)
          $list[$count]['full_name'] = " <i>" . trim($arrEmployee['full_name']) . "</i>";

        $list[$count]['name'] = $arrEmployee['name'];
        $list[$count]['id'] = $arrEmployee['id'];

        $query_comments = "SELECT memo FROM memo WHERE id = " . $arrEmployee['id'];
        $stmt_comments = make_query($DBConn,$query_comments);
        $comments = true;
        $list[$count]["comments"] =  "";
        while($arrComments = retrieve_row($stmt_comments))
        {
          if($comments)
            $comments = false;
          $list[$count]["comments"] .="&nbsp;&nbsp;" . mgdb_safe_html($arrComments['memo']) . "<br>\n";
        }
        if($comments)
         $list[$count]["comments"] = "&nbsp;&nbsp;There are no notes available for this transgene.<br>\n";


        $query_family_members = "
          SELECT B.NAME, B.FULL_NAME, B.ID 
          FROM RELATION A, LOCUS B, ID_NUM C 
          WHERE A.ID = " . $arrEmployee['id'] . " AND A.RELATED_ID = B.ID 
                AND (A.RELATION = 56335 OR A.RELATION = 69852) AND B.ID = C.ID 
                AND C.CURATION_LVL = 0";
        $stmt_family_members = make_query($DBConn,$query_family_members);

        $is_family = true;
        $list[$count]["fm"] =  "";
        while($arrFM = retrieve_row($stmt_family_members))
        {
          if($is_family)
            $is_family = false;
         $list[$count]["fm"] .= "&nbsp;&nbsp;" . " <a href=\"/data_center/locus?id=" . $arrFM['id'] . "\">" . trim($arrFM['name']);
          if(strlen($arrFM['full_name']) > 0)
           $list[$count]["fm"] .= " <i>" . trim($arrFM['full_name']) . "</i>";
         $list[$count]["fm"] .= "</a><br>\n";
        }
        if($is_family)
         $list[$count]["fm"] ="&nbsp;&nbsp;There are no noted family members available for this transgene.<br>\n";

        $query_related_loci = "
          SELECT B.NAME, B.ID, D.NAME AS RELATIONSHIP 
          FROM RELATION A, LOCUS B, ID_NUM C, TERM D 
          WHERE A.ID = " . $arrEmployee['id'] . " AND A.RELATED_ID = B.ID 
                AND A.RELATION != 56335 AND A.RELATION != 69852 AND A.RELATION = D.ID 
                AND B.ID = C.ID AND C.CURATION_LVL = 0";
        $stmt_related_loci = make_query($DBConn,$query_related_loci);
        $related_loci = true;
        $list[$count]["loci"] =  "";
        while($arrRL = retrieve_row($stmt_related_loci))
        {
          if($related_loci)
            $related_loci = false;
          $list[$count]["loci"] .= "&nbsp;&nbsp;" . $arrRL['relationship'] . " <a href=\"/data_center/locus?id=" . $arrRL['id'] . "\">" . $arrRL['name'] . "</a><br>\n";
        }
        if($related_loci)
         $list[$count]["loci"] = "&nbsp;&nbsp;There are no noted related loci available for this transgene.<br>\n";

        $query_references = "
          SELECT B.NAME, B.ID, B.TITLE, D.NAME AS TYPE 
          FROM ID_REFERENCE A, REFERENCE B, ID_NUM C, TERM D 
          WHERE A.ID = " . $arrEmployee['id'] . " AND A.REFERENCE = B.ID 
                AND A.CONTENTS = D.ID AND B.ID = C.ID AND C.CURATION_LVL = 0";
        $stmt_references = make_query($DBConn,$query_references);
        $references = true;
        $list[$count]["ref"] =  "";
        while($arrRefs = retrieve_row($stmt_references))
        {
          if($references)
            $references = false;
         $list[$count]["ref"] .= "&nbsp;&nbsp;(" . $arrRefs['type'] . ") <a href=\"/data_center/reference?id=" . $arrRefs['id'] . "\">" . $arrRefs['name'] . "</a><br>\n";
         if(strlen($arrRefs['title']) > 0)
           $list[$count]["ref"] .=   "&nbsp;&nbsp;&nbsp;&nbsp;<i>" . trim($arrRefs['title']) . "</i><br>\n";
        }
        if($references)
         $list[$count]["ref"] = "&nbsp;&nbsp;There are no noted references available for this transgene.<br>\n";
       $count++;
      }
      $loc_report_search->get("report_note")->replace($report_note);
  $loc_report_search->get("list_name")->replace($list_name);
      $loc_report_left->get("description")->replace($description);
      $loc_report_left->get("family_list")->loop($list);
      $loc_report_left->get("family_sec")->unmute();
    }//family
  }//!$list
  
  
  function lookuparm($var1) {
    if($var1 == "109667")
      return "centromere";
    else if($var1 == "32021")
      return "L (long arm)";
    else if($var1 == "32022")
      return "S (short arm)";
    else
      return "&nbsp;";
  }

?>
