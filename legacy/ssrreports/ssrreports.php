<?PHP
  include_once('./include/db-api.php');
  include_once('./include/gp_lib.php');
  
  $id = getCGIParam('id', 'G', false);
  $text = getCGIParam('text', 'G', false);
  $list = "<table summary=\"A table to provide control over the primary layout of the page\" width=\"100%\" cellpadding=5 cellspacing=5><tr><td valign=top><p style=\"margin-bottom: 8px;\"><span style=\"font-family: Verdana, Arial, sans-serif; font-size: 120%; font-weight: bold; margin-bottom: 6px;\">";


  if($id == "1") {
    if($text == 1)
    {
    header('Content-Description: File Transfer');
      header('Content-type: text/html');
      header('Content-Disposition: attachment; filename=ssr_repeats.txt');
      $DBConn = connect_to_database();
      $query = "
        SELECT A.ID, A.REPEAT FROM PROBE A, ID_NUM B 
        WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND A.REPEAT IS NOT NULL 
              AND A.TYPE = 104436 ORDER BY A.REPEAT";
      $statement = make_query($DBConn,$query,2500);
      while($arrRepeat = retrieve_row($statement)) {
        echo  $arrRepeat['repeat'] ."\n";  
      }
        exit(0);
    }
    else
    {
      $list .= "Complete List of SSR Repeats</span>\t";
    $list .= "<a href=/ssrreports?id=1&text=1>Select for tab delimited text file</a> \n<br></p>";
      $DBConn = connect_to_database();
      $query = "
        SELECT A.ID, A.REPEAT FROM PROBE A, ID_NUM B 
        WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND A.REPEAT IS NOT NULL 
              AND A.TYPE = 104436 ORDER BY A.REPEAT";
      $statement = make_query($DBConn,$query,2500);
      $list .= "<p>";
      while($arrRepeat = retrieve_row($statement)) {
        $list .= "<a href=\"/data_center/ssr?id=" . $arrRepeat['id'] . "\">" . $arrRepeat['repeat'] . "</a><br>\n";
      }
      $list .= "</p>\n";
    }
  }//id==1
  else if($id == "2") {
    if($text == 1)
    {
      header('Content-Description: File Transfer');
      header('Content-type: text/html');
      header('Content-Disposition: attachment; filename=ssr_derived_genes.txt');
      echo "Chr.# \t SSR \t Repeat \t Locus \n";
      $DBConn = connect_to_database();
      $query = "
        SELECT A.ID AS SSR_ID, A.REPEAT AS SSR_REPEAT, A.NAME AS SSR_NAME, 
               D.ID AS LOCUS_ID, D.NAME AS LOCUS_NAME, 
               D.FULL_NAME AS LOCUS_FULL_NAME, D.LINKAGE_GROUP AS CHROM 
        FROM PROBE A, ID_NUM B, LOCUS_DETECTED_BY C, LOCUS D, ID_NUM E 
        WHERE A.TYPE = 104436 AND A.ID = B.ID AND B.CURATION_LVL = 0 
              AND A.ID = C.PROBE_ID AND C.ID = D.ID AND D.TYPE = 101 
              AND D.ID = E.ID AND E.CURATION_LVL = 0 
        ORDER BY D.LINKAGE_GROUP, D.NAME";
      $statement =  make_query($DBConn,$query,2500);
      while($arrRepeat = retrieve_row($statement)) {
        $chrno = ($arrRepeat['chrom'] - 13576) / 3;
        $flush = settype($chrno,"integer");
        if(strlen($arrRepeat['chrom']) < 1)
         echo "N/A</td>\n";
        else
        echo $chrno . "\t";
        echo $arrRepeat['ssr_name'] . "\t";
        echo $arrRepeat['ssr_repeat']. "\t";
        echo $arrRepeat['locus_name']." ";
        if(strlen($arrRepeat['locus_full_name']) > 0)
         echo $arrRepeat['locus_full_name'];
        echo "\n";
      }//each ssr_id
      exit(0);
    }//text==1
    else
    {
      $list .= "SSRs Derived From Genes</span>\t";
      $list .= "<a href=/ssrreports?id=2&text=1>Select for tab delimited text file</a> \n<br></p>";
      $DBConn = connect_to_database();
      $query = "
        SELECT A.ID AS SSR_ID, A.REPEAT AS SSR_REPEAT, A.NAME AS SSR_NAME, 
               D.ID AS LOCUS_ID, D.NAME AS LOCUS_NAME, 
               D.FULL_NAME AS LOCUS_FULL_NAME, D.LINKAGE_GROUP AS CHROM 
        FROM PROBE A, ID_NUM B, LOCUS_DETECTED_BY C, LOCUS D, ID_NUM E 
        WHERE A.TYPE = 104436 AND A.ID = B.ID AND B.CURATION_LVL = 0 
              AND A.ID = C.PROBE_ID AND C.ID = D.ID AND D.TYPE = 101 
              AND D.ID = E.ID AND E.CURATION_LVL = 0 
        ORDER BY D.LINKAGE_GROUP, D.NAME";
      $statement =  make_query($DBConn,$query,2500);
      $list .= "<table cellpadding=1 cellspacing=1><tr><td><u><b>Chr. #</b></u></td><td><u><b>SSR</b></u></td><td><u><b>Repeat</b></u></td><td><u><b>Locus</b></u></td></tr>\n";
      while ($arrRepeat = retrieve_row($statement)) {
        $list .= "<tr><td>";
        $chrno = ($arrRepeat['chrom'] - 13576) / 3;
        $flush = settype($chrno,"integer");
        if(strlen($arrRepeat['chrom']) < 1)
          $list .= "N/A</td>\n";
        else
          $list .= "<a href=\"/data_center/lg?id=" . $arrRepeat['chrom'] . "\">" . $chrno . "</a></td>\n";
        $list .= "<td><a href=\"/data_center/ssr?id=" . $arrRepeat['ssr_id'] . "\">" . trim($arrRepeat['ssr_name']) . "</a></td>\n";
        $list .= "<td><a href=\"/data_center/ssr?id=" . $arrRepeat['ssr_id'] . "\">" . trim($arrRepeat['ssr_repeat']) . "</a></td>\n";
        $list .= "<td><a href=\"/data_center/locus/" . $arrRepeat['locus_id'] . "\">" . trim($arrRepeat['locus_name']);
        if(strlen($arrRepeat['locus_full_name']) > 0)
          $list .= " <i>" . trim($arrRepeat['locus_full_name']) . "</i>";
        $list .= "</a></td></tr>\n";
      }//each ssr_id
      $list .= "</table>";
    }//else
  }//id==2
  else {
    $list .= "<br><b><font size=4>SSR Reports of Interest</font></b></span></p><br>";
    $list .= "<p>Here are a set of reports that may be of interest to you concerning SSRs.</p><br>\n";
    $list .= "<p>";
    $list .= "<a href=\"/ssrreports?id=1\"><b>Complete List of SSR Repeats</b></a>: Provides a complete list of SSR repeats available in the database.<br>\n";
    $list .= "</p>\n";
  }
  $list .= "</td></tr></table>\n";

  $credit = $mgdb->get('body')->load('templates/tools/ssrreports.bau');
  $credit->get('qtl_table_summary_str')->replace($list);

?>
