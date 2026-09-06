<?PHP
  require("../../../include/db-api.php");
  //require("../html/include/api.php");

  $username = $_COOKIE["username"];
  $password = $_COOKIE["password"];
  $userid = $_COOKIE["userid"];

 $ip = $_SERVER["REMOTE_ADDR"];
 $browser = $_SERVER["HTTP_USER_AGENT"];
 //if((check_ip($ip)) && (check_browser($browser)))
 //if(check_browser($browser))
 if(1)
 {
  $id = $_GET["id"];
  $dump = settype($id, "integer");
  $uri = $SITE_URL . "/cgi-bin/displaypersonrecord.cgi?" . $_SERVER['QUERY_STRING'];
  $legal_record = false;
  $DBConn = OCILogon(DB_USER,DB_PASS,DB_NAME);
  $query = "SELECT rownum n from person WHERE ID = " . $id;
  $statement = @OCIParse($DBConn,$query);
  @OCIExecute($statement,OCI_DEFAULT);
  @OCIFetchInto($statement,&$arrNum, OCI_ASSOC+OCI_RETURN_NULLS);
  if($arrNum["N"] == "1") {
    $query_id_num = "SELECT CURATION_LVL FROM ID_NUM WHERE ID = " . $id;
    $stmt = @OCIParse($DBConn,$query_id_num);
    @OCIExecute($stmt,OCI_DEFAULT);
    @OCIFetchInto($stmt,&$arrLvl, OCI_ASSOC+OCI_RETURN_NULLS);
    if ($arrLvl["CURATION_LVL"] == "0")
      $legal_record = true;
  }
  if($legal_record) {
  
  // $gold_query = "SELECT B.TITLE AS BTITLE, B.ID AS BID  FROM ID_REFERENCE A JOIN REFERENCE B on A.REFERENCE = B.ID JOIN REFERENCE_AUTHORS C ON C.ID = A.ID WHERE B.IN1 = '1232902' AND C.ID ='" . $id . "'";
      
	  $gold_query = "SELECT B.TITLE AS BTITLE, B.ID AS BID FROM REFERENCE_AUTHORS C JOIN ID_REFERENCE A ON A.REFERENCE = C.ID JOIN REFERENCE B on A.REFERENCE = B.ID WHERE B.IN1 = '1232902'  AND C.AUTHOR ='" . $id . "'";
	  $stmt_gold = make_query($DBConn,$gold_query,1);
      $arrgold = retrieve_row($stmt_gold);
	  
	  $goldc_query = "SELECT count(B.ID) as CCOUNT FROM REFERENCE_AUTHORS C JOIN ID_REFERENCE A ON A.REFERENCE = C.ID JOIN REFERENCE B on A.REFERENCE = B.ID WHERE B.IN1 = '1232902'  AND C.AUTHOR ='" . $id . "'";
	  $stmt_goldc = make_query($DBConn,$goldc_query,1);
      $arrgoldc = retrieve_row($stmt_goldc);
	  
	  $gold_query_ed = "SELECT YEAR FROM ED_BOARD WHERE PERSON_ID ='" . $id . "' ORDER BY YEAR";
	  $stmt_gold_ed = make_query($DBConn,$gold_query_ed,1);
      $arrgold_ed = retrieve_row($stmt_gold_ed);
	  
	  //echo "1START : " .  $gold_query . "<BR><BR>";
	  if($arrgold["BTITLE"] || $arrgold_ed["YEAR"])
		{
		
		 ///
  ////////
  //////
  
   
  $query = "SELECT * from person where ID = " . $id;
    $statement = @OCIParse($DBConn,$query);
    @OCIExecute($statement,OCI_DEFAULT);
    @OCIFetchInto($statement,&$arrRecord, OCI_ASSOC+OCI_RETURN_NULLS);
    if((strlen(trim($arrRecord["NAME_FIRST"])) > 0) && (strlen(trim($arrRecord["NAME_LAST"])) > 0)) {
      $headline = $arrRecord["NAME_FIRST"] . " " . $arrRecord["NAME_LAST"]; 
      $namesave = $arrRecord["NAME_FIRST"] . " " . $arrRecord["NAME_LAST"]; 
      $title = $arrRecord["NAME_FIRST"] . " " . $arrRecord["NAME_LAST"];
      if(strlen($arrRecord["SUFFIX"]) > 0) {
        $headline = $headline . ", " . $arrRecord["SUFFIX"];
        $title = $title . ", " . $arrRecord["SUFFIX"];
      }
    }
    else {
      $headline = $arrRecord["NAME"];
	  $namesave = $arrRecord["NAME"];
      $title = $arrRecord["NAME"];
    }


    //$imagelink="http://ftp.maizegdb.org/person_images/images/".'thumbnail_pic'."$id".".jpg";
    $imagelink="images/"."$id".".jpg";
    $url1=getimagesize($imagelink);

	if(!is_array($url1))
	   {
	    //$imagelink="http://ftp.maizegdb.org/person_images/images/".'thumbnail_pic'."$id".".jpeg";
	    $imagelink="images/"."$id".".jpeg";
        $url2=getimagesize($imagelink);
        		if(!is_array($url2))
        		{
        			//$imagelink="http://ftp.maizegdb.org/person_images/images/".'thumbnail_pic'."$id".".png";
        			$imagelink="images/"."$id".".png";
        			$url3=getimagesize($imagelink);
				if(!is_array($url3))
        			{
        				//$imagelink="http://ftp.maizegdb.org/person_images/images/".'thumbnail_pic'."$id".".gif";
        				$imagelink="images/"."$id".".gif";
	        			$url4=getimagesize($imagelink);
	        			if(!is_array($url4))
	        				$imagelink="default_pic.jpg";
        			}
        		}
	   }
		
    

    $headline = "<img src=$imagelink width=91 height=91 border=2 alt=\"default_pic.jpg\" align=middle></img>" ."<img height='20px' src='../icons/gold_star.jpg'></img>" . $headline . "(<a href=\"" . $SITE_URL . "/person.php\">person/organization</a>)</span></p>";
    //echo page_header_new("MaizeGDB: " . $title,"MaizeGDB Person Record: " . $title,$username,$password,$userid);
    echo "<table summary=\"A table to provide control over the primary layout of the page\" width=\"100%\" cellpadding=5 cellspacing=5><tr><td valign=top>\n";
echo "<table  width='100%'><tr><td align='left' valign='top'><p>";

    $probestart = $_GET["probestart"];
    $probeavstart = $_GET["probeavstart"];    
  
    if(strlen($probestart) == 0) {
      $probestart = 1;
    }
    if(!(settype($probestart,'integer'))) {
      $probestart = 1;
    }
    if($probestart < 1) {
      $probestart = 1;
    }

    if(strlen($probeavstart) == 0) {
      $probeavstart = 1;
    }
    if(!(settype($probeavstart,'integer'))) {
      $probeavstart = 1;
    }
    if($probeavstart < 1) {
      $probeavstart = 1;
    }

    $probeavend = $probeavstart + 100;
    $probeend = $probestart + 100;

    $probestring = "";
    $probeavstring = "";
    $proberefstring = "";
    $printstring = "";
    $probeclstring = "";
	
    if($_GET["probe"] == "1")
      $probestring = "&amp;probe=1";
    if($_GET["probeav"] == "1")
      $probeavstring = "&amp;probeav=1";
    if($_GET["proberef"] == "1")
      $proberefstring = "&amp;proberef=1";
    if($_GET["print"] == "1")
      $printstring = "&amp;print=1";
	if($_GET["probecl"] == "1")
      $probeclstring = "&amp;probecl=1";
    $probestartstring = "&amp;probestart=" . $probestart;
    $probeavstartstring = "&amp;probeavstart=" . $probeavstart;
    $probeclstartstring = "&amp;probeclstart=" . $probeclstart;

    
    echo "<p style=\"margin-bottom: 8px;\"><span style=\"font-family: Verdana, Arial, sans-serif; font-size: 120%; font-weight: bold; margin-bottom: 6px;\">";
    F;
    echo $headline;
   
    /*echo "<div id='id2' style='display: inline'><a href=\"javascript:showDetail()\"><font size=\"2\">Upload Picture</font></a></div>";
    echo "<div id='id3' style='display: none'><a href=\"javascript:showDetail()\"><font size=\"2\">Close</font></a></div><br><br>";
   // $link = "change_pic.php?id=".urlencode($id);
    echo "<iframe id=\"id1\" src=$link style='display: none' width=\"100%\" height=\"90px\" border='0'><p>Your browser does not support iframes.</p></iframe>";*/

    //$link_window="http://ftp.maizegdb.org/person_images/upload_crop.php?id=".urlencode($id);
    //$link = "upload_crop.php?id=".urlencode($id);
    $query_email = "SELECT EMAIL_ADDRESS FROM PERSON_EMAIL WHERE ID = " . $arrRecord["ID"];
    $statement_email = @OCIParse($DBConn,$query_email);
    @OCIExecute($statement_email,OCI_DEFAULT);
    @OCIFetchInto($statement_email,&$arrEmail, OCI_ASSOC+OCI_RETURN_NULLS);
    $link="upload_crop.php?id=".urlencode($id)."&email=".urlencode($arrEmail["EMAIL_ADDRESS"]);
    echo "<FORM><INPUT type=\"button\" value=\"Upload New Picture\" onClick=\"window.open('$link','mywindow','width=400,height=400')\"></FORM>";
	
    $query_syn = "SELECT SYNONYMS FROM SYNONYMS WHERE ID = " . $arrRecord["ID"] . " AND SYNONYMS != '" . $arrRecord["NAME"] . "'";
    $statement_syn = @OCIParse($DBConn,$query_syn);
    @OCIExecute($statement_syn,OCI_DEFAULT);
    @OCIFetchInto($statement_syn,&$arrSyn, OCI_ASSOC+OCI_RETURN_NULLS);

    if(strlen($arrSyn["SYNONYMS"]) > 0) {
      echo "<p>Also known by these names: ";
      echo $arrSyn["SYNONYMS"];
      while(@OCIFetchInto($statement_syn,&$arrSyn,OCI_ASSOC+OCI_RETURN_NULLS)){
        echo "; " . $arrSyn["SYNONYMS"]; }
      echo "</p>\n";
    } 

    if((strlen($username) > 0) && (strlen($password) > 0) && (strlen($userid) > 0))
      echo "<p><b><a href=\"create_annotation.cgi?id=" . $id . "\" target=\"new\">Add your own annotation to this record!</a></b></p>\n";

    echo "<p>Is the contact information here incorrect?  <a href=\"" . $SITE_URL . "/cgi-bin/update_person.cgi?id=" . $id . "\">Please help us correct it!</a></p>\n";

    if(strlen($arrRecord["ADDRESS"]) > 0) {
//      echo "<p><b>Address</b>:<br>\n";
      echo nl2br($arrRecord["ADDRESS"]) . "<br>\n";
      echo $arrRecord["CITY"] . " " . $arrRecord["STATE"] . " " . $arrRecord["COUNTRY"] . " " . $arrRecord["POSTAL_CODE"] . "<br>\n";
    } else {
      echo "<b>Address</b>: No address given<br>\n";
    }

    $query_email = "SELECT EMAIL_ADDRESS FROM PERSON_EMAIL WHERE ID = " . $arrRecord["ID"];
    $statement_email = @OCIParse($DBConn,$query_email);
    @OCIExecute($statement_email,OCI_DEFAULT);
    @OCIFetchInto($statement_email,&$arrEmail, OCI_ASSOC+OCI_RETURN_NULLS);

//    echo "<b>Email Address(es)</b>: ";
    if(strlen($arrEmail["EMAIL_ADDRESS"]) > 0) {
      echo "<a href=\"mailto:" . $arrEmail["EMAIL_ADDRESS"] . "\">" . $arrEmail["EMAIL_ADDRESS"] . "</a>";
      while(@OCIFetchInto($statement_email,&$arrEmail,OCI_ASSOC+OCI_RETURN_NULLS)) {
        echo ", <a href=\"mailto:" . $arrEmail["EMAIL_ADDRESS"] . "\">" . $arrEmail["EMAIL_ADDRESS"] . "</a>"; }
      echo "<br>\n";
    }
//    else echo "No email address given.<br>\n"; 

    $query_url = "SELECT URL from WEB_DATA where ID = " . $arrRecord["ID"];
    $statement_url = @OCIParse($DBConn,$query_url);
    @OCIExecute($statement_url,OCI_DEFAULT);
    @OCIFetchInto($statement_url,&$arrUrl,OCI_ASSOC+OCI_RETURN_NULLS);

//    echo "<b>URL(s)</b>: ";
    if(strlen($arrUrl["URL"]) > 0) {
      echo "<a href=\"" . $arrUrl["URL"] . "\">" . $arrUrl["URL"] . "</a>";
      while(@OCIFetchInto($statement_url,&$arrUrl,OCI_ASSOC+OCI_RETURN_NULLS)){
        echo ", <a href=\"" . $arrUrl["URL"] . "\">" . $arrUrl["URL"] . "</a>";
      }
      echo "<br>\n";
    }
//    else echo "No URL given.<br>\n";

    $query_phone = "SELECT PHONE_NUM from PERSON_PHONE_NUM where ID = " . $arrRecord["ID"] . " ORDER BY PHONE_NUM";
    $statement_phone = @OCIParse($DBConn,$query_phone);
    @OCIExecute($statement_phone,OCI_DEFAULT);
    @OCIFetchInto($statement_phone,&$arrPhone,OCI_ASSOC+OCI_RETURN_NULLS);
    
//    echo "<b>Phone number(s)</b>: ";

    if(strlen($arrPhone["PHONE_NUM"]) > 0) {
      echo $arrPhone["PHONE_NUM"];
      while(@OCIFetchInto($statement_phone,&$arrPhone,OCI_ASSOC+OCI_RETURN_NULLS))
        echo "<br>" . $arrPhone["PHONE_NUM"];
    } else {
//      echo "No phone numbers given.";
    }
    echo "</p>\n";

    $query_role = "SELECT NAME from TERM where ID = (SELECT ATTRIBUTE FROM PERSON_ATTRIBUTE WHERE ID = " . $arrRecord["ID"] . ")";
    $statement_role = @OCIParse($DBConn,$query_role);
    @OCIExecute($statement_role,OCI_DEFAULT);
    @OCIFetchInto($statement_role,&$arrRole,OCI_ASSOC+OCI_RETURN_NULLS);
    if(strlen($arrRole["NAME"]) > 0)
      echo "<p><b>Role</b>: " . $arrRole["NAME"] . "</p>";

    echo "<p>";

    $uri = $SITE_URL . "/cgi-bin/displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probestring . $probestartstring . $probeclstring . $probeclstartstring . $probeavstring . $probeavstartstring . $proberefstring . $printstring;

    if($_GET["proberef"] == "1") {
      $proberef_query = "SELECT a.id,a.order1 FROM reference_authors a, reference r, id_num i WHERE a.id = r.id AND r.id = i.id AND i.curation_lvl = 0 AND a.author = " . $arrRecord["ID"] . " ORDER BY r.year desc, r.name";
      $statement_proberef = @OCIParse($DBConn,$proberef_query);
      @OCIExecute($statement_proberef,OCI_DEFAULT);
      @OCIFetchInto($statement_proberef,&$arrProberef,OCI_ASSOC+OCI_RETURN_NULLS);
      if(strlen($arrProberef["ID"]) > 0) {
        echo "<a name=\"proberef\"></a><b><a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probestring . $probestartstring . $probeclstring . $probeclstartstring . $probeavstring . $probeavstartstring . $printstring . "\"><img src=\"" . $SITE_URL . "/images/row-contract.gif\" width=17 height=17 border=0 alt=\"Hide references\"></a> References authored by this person:</b><br>\n";
        $paper_name_query = "SELECT name,title FROM reference WHERE id = " . $arrProberef["ID"] . " ORDER BY YEAR DESC";
        $paper_name_statement =  @OCIParse($DBConn,$paper_name_query);
        @OCIExecute($paper_name_statement,OCI_DEFAULT);
        @OCIFetchInto($paper_name_statement,&$arrPaperName,OCI_ASSOC+OCI_RETURN_NULLS);
        if(strlen($arrPaperName["TITLE"]) > 0) { 
          echo "&nbsp;&nbsp;<a href=\"displayrefrecord.cgi?id=" . $arrProberef["ID"] . "\">" . $arrPaperName["NAME"] . "</a>";
          if($arrProberef["ORDER1"] == "1")
            echo "&nbsp;&nbsp;(primary author)";
          echo "<br>&nbsp;&nbsp;&nbsp;&nbsp;<i>" . strip_tags($arrPaperName["TITLE"]) . "</i>";
        }
        else
        {
          echo "&nbsp;&nbsp;<a href=\"displayrefrecord.cgi?id=" . $arrProberef["ID"] . "\">" . $arrPaperName["NAME"] . "</a>";
          if($arrProberef["ORDER1"] == "1")
            echo "&nbsp;&nbsp;(primary author)";
        }
        echo "<br>\n";
        while(@OCIFetchInto($statement_proberef,&$arrProberef,OCI_ASSOC+OCI_RETURN_NULLS))
        {
          $paper_name_query = "SELECT name,title FROM reference WHERE id = " . $arrProberef["ID"] . " ORDER BY YEAR DESC";
          $paper_name_statement =  @OCIParse($DBConn,$paper_name_query);
          @OCIExecute($paper_name_statement,OCI_DEFAULT);
          @OCIFetchInto($paper_name_statement,&$arrPaperName,OCI_ASSOC+OCI_RETURN_NULLS);
          if(strlen($arrPaperName["TITLE"]) > 0) 
          {
            echo "&nbsp;&nbsp;<a href=\"displayrefrecord.cgi?id=" . $arrProberef["ID"] . "\">" . $arrPaperName["NAME"] . "</a>";
            if($arrProberef["ORDER1"] == "1")
              echo "&nbsp;&nbsp;(primary author)";
            echo "<br>&nbsp;&nbsp;&nbsp;&nbsp;<i>" . strip_tags($arrPaperName["TITLE"]) . "</i>";
          }
          else
          {
            echo "&nbsp;&nbsp;<a href=\"displayrefrecord.cgi?id=" . $arrProberef["ID"] . "\">" . $arrPaperName["NAME"] . "</a>";
            if($arrProberef["ORDER1"] == "1")
              echo "&nbsp;&nbsp;(primary author)";
          }
          echo "<br>\n";
        }
      }
    }
    else {
      $probe_paper_query = "SELECT count(a.id) from reference_authors a join id_num b on a.id = b.id where b.curation_lvl = 0 and a.AUTHOR = " . $arrRecord["ID"];
      $statement_probe_paper = @OCIParse($DBConn,$probe_paper_query);
      @OCIExecute($statement_probe_paper,OCI_DEFAULT);
      @OCIFetchInto($statement_probe_paper,&$arrProbePaper,OCI_ASSOC+OCI_RETURN_NULLS);
      if($arrProbePaper["COUNT(A.ID)"] != "0") {
        echo "<a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . "&amp;proberef=1" . $probestring . $probestartstring . $probeclstring . $probeclstartstring . $probeavstring . $probeavstartstring . $printstring . "#proberef\"><img src=\"" . $SITE_URL . "/images/row-expand.gif\" width=17 height=17 border=0 alt=\"Show authored references\"></a> This person/group has authored <b>" . $arrProbePaper["COUNT(A.ID)"] . "</b> references.  Click the green arrow to the left to view them.<br>\n";
      }
    }

    if($_GET["probe"] == "1") {
      $probe_query = "SELECT name,type,id from PROBE where rowid in (SELECT rowid FROM probe WHERE PREPARED_BY = " . $arrRecord["ID"] . " AND rownum <= " . $probeend . " MINUS SELECT rowid FROM probe WHERE PREPARED_BY = " . $arrRecord["ID"] . " AND rownum < " . $probestart . ") ORDER BY name";
      $statement_probe = @OCIParse($DBConn,$probe_query);
      @OCIExecute($statement_probe,OCI_DEFAULT);
      @OCIFetchInto($statement_probe,&$arrProbe,OCI_ASSOC+OCI_RETURN_NULLS);
      $tmpprobename = $arrProbe["NAME"];
      $tmpprobeid = $arrProbe["ID"];
      $tmpprobetype = $arrProbe["TYPE"];
      if(strlen($arrProbe["NAME"]) > 0) {
        echo "<a name=\"probe\"></a><b><a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probeavstring . $probeavstartstring . $probeclstring . $probeclstartstring . $proberefstring . $printstring . "\"><img src=\"" . $SITE_URL . "/images/row-contract.gif\" width=17 height=17 border=0 alt=\"Hide created probes\"></a> Genetics created by this person:</b><br>";
        if(($probestart > 1) && ($probestart < 101))
          echo "<p>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probestring . "&amp;probestart=1" . $probeavstring . $probeavstartstring . $proberefstring . $printstring . $probeclstring . $probeclstartstring .  "\">Display first set of genetic elements created by this person/organization</a></p>";
        else if($probestart > 100)
          echo "<p>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probestring . "&amp;probestart=" . ($probestart - 100) . $probeavstring . $probeavstartstring . $proberefstring . $printstring . $probeclstring . $probeclstartstring . "\">Display previous set of 100 genetic elements created by this person/organization</a></p>";
        echo "<table summary=\"A table containing all the genetic elements created by this person\" width=\"100%\"><tr><td width=\"25%\" valign=top>\n";
        echo "&nbsp;&nbsp;&nbsp;&nbsp;<a href=\"display";
        if($tmpprobetype == "171715")
          echo "bac";
        else if($tmpprobetype == "34")
          echo "est";
        else if(($tmpprobetype == "393660") || ($tmpprobetype == "747274"))
          echo "overgo";
        else if($tmpprobetype == "104436")
          echo "ssr";
        else
          echo "probe";
        echo "record.cgi?id=" . $tmpprobeid . "\">" . $tmpprobename . "</a> ";
        if($tmpprobetype == "171715")
          echo "(BAC)";
        else if($tmpprobetype == "34")
          echo "(EST)";
        else if(($tmpprobetype == "393660") || ($tmpprobetype == "747274"))
          echo "(Overgo)";
        else if($tmpprobetype == "104436")
          echo "(SSR)";
        else
          echo "(Probe)";
        echo "<br>\n";
        $rowcount = 1;
        $reccnt = 1;
        while($rowcount < 100) {
          if(@OCIFetchInto($statement_probe,&$arrProbe,OCI_ASSOC+OCI_RETURN_NULLS)) {
            if(($rowcount == 25) || ($rowcount == 50) || ($rowcount == 75)) 
            {
              echo "</td><td width=\"25%\" valign=top>&nbsp;&nbsp;&nbsp;&nbsp;<a href=\"display";
              $tmpprobetype = $arrProbe["TYPE"];
              if($tmpprobetype == "171715")
                echo "bac";
              else if($tmpprobetype == "34")
                echo "est";
              else if(($tmpprobetype == "393660") || ($tmpprobetype == "747274"))
                echo "overgo";
              else if($tmpprobetype == "104436")
                echo "ssr";
              else
                echo "probe";
              echo "record.cgi?id=" . $arrProbe["ID"] . "\">" . $arrProbe["NAME"] . "</a> ";
              if($tmpprobetype == "171715")
                echo "(BAC)";
              else if($tmpprobetype == "34")
                echo "(EST)";
              else if(($tmpprobetype == "393660") || ($tmpprobetype == "747274"))
                echo "(Overgo)";
              else if($tmpprobetype == "104436")
                echo "(SSR)";
              else
                echo "(Probe)";

              echo "<br>\n";
            }
            else
            {
              echo "&nbsp;&nbsp;&nbsp;&nbsp;<a href=\"display";
              $tmpprobetype = $arrProbe["TYPE"];
              if($tmpprobetype == "171715")
                echo "bac";
              else if($tmpprobetype == "34")
                echo "est";
              else if($tmpprobetype == "393660")
                echo "overgo";
              else if($tmpprobetype == "104436")
                echo "ssr";
              else
                echo "probe";
              echo "record.cgi?id=" . $arrProbe["ID"] . "\">" . $arrProbe["NAME"] . "</a> ";
              if($tmpprobetype == "171715")
                echo "(BAC)";
              else if($tmpprobetype == "34")
                echo "(EST)";
              else if($tmpprobetype == "393660")
                echo "(Overgo)";
              else if($tmpprobetype == "104436")
                echo "(SSR)";
              else
                echo "(Probe)";

              echo "<br>\n";
            }
            $reccnt = $reccnt + 1;
          }
          $rowcount = $rowcount + 1;
        }
        if($rowcount < 25)
          echo "</td><td width=\"75%\">&nbsp;</td></tr></table>";
        else if($rowcount < 50)
          echo "</td><td width=\"50%\">&nbsp;</td></tr></table>";
        else if($rowcount < 75)
          echo "</td><td width=\"25%\">&nbsp;</td></tr></table>";
        else
          echo "</td></tr></table>";
        echo "<p>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;You are viewing elements <b>" . $probestart . "</b> through <b>" . ($probestart - 1 + $reccnt) . "</b> ";
        $total_probe_count_query = "SELECT count(id) from probe where prepared_by = " . $arrRecord["ID"];
        $total_probe_count_statement = @OCIParse($DBConn,$total_probe_count_query);
        @OCIExecute($total_probe_count_statement,OCI_DEFAULT);
        @OCIFetchInto($total_probe_count_statement,&$arrProbeCount,OCI_ASSOC+OCI_RETURN_NULLS);
        echo "of a total <b>" . $arrProbeCount["COUNT(ID)"] . "</b> probes created by this person/organization.</p>\n";
        $next_page_count = $arrProbeCount["COUNT(ID)"] - ($probestart - 1 + $reccnt);
        if($next_page_count > 100)
          $next_page_count = 100;
        if($arrProbeCount["COUNT(ID)"] > $probeend)
          echo "<p>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probestring . "&amp;probestart=" . ($probestart + 100) . $probeavstring . $probeavstartstring . $probeclstring . $probeclstartstring . $proberefstring . $printstring . "\">Display next " . $next_page_count . " genetic elements created by this person/organization</a></p>";
      }
    }
    else {
      $probe_query = "SELECT count(id) from PROBE where PREPARED_BY = " . $arrRecord["ID"] . " ORDER BY name";
      $statement_probe = @OCIParse($DBConn,$probe_query);
      @OCIExecute($statement_probe,OCI_DEFAULT);
      @OCIFetchInto($statement_probe,&$arrProbe,OCI_ASSOC+OCI_RETURN_NULLS);
      if($arrProbe["COUNT(ID)"] > 0) {
        echo "<a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . "&amp;probe=1" . $probeavstring . $probeavstartstring . $probeclstring . $probeclstartstring . $proberefstring . $printstring . "#probe\"><img src=\"" . $SITE_URL . "/images/row-expand.gif\" width=17 height=17 border=0 alt=\"Show created genetic elements\"></a> This person/group has created <b>" . $arrProbe["COUNT(ID)"] . "</b> genetic elements.  Click the green arrow to the left to view them.<br>\n";
      }
    }

    if($_GET["probeav"] == "1") {
      $probeav_query = "SELECT name,type,id from PROBE where rowid in (SELECT rowid FROM probe WHERE AVAILABLE_FROM = " . $arrRecord["ID"] . " AND rownum <= " . $probeavend . " MINUS SELECT rowid FROM probe WHERE AVAILABLE_FROM = " . $arrRecord["ID"] . " AND rownum < " . $probeavstart . ") ORDER BY name";
      $statement_probeav = @OCIParse($DBConn,$probeav_query);
      @OCIExecute($statement_probeav,OCI_DEFAULT);
      @OCIFetchInto($statement_probeav,&$arrProbeav,OCI_ASSOC+OCI_RETURN_NULLS);
      if(strlen($arrProbeav["NAME"]) > 0) {
        echo "<a name=\"probeav\"></a><b><a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probestring . $probeclstring . $probeclstartstring . $probestartstring . $proberefstring . $printstring . "\"><img src=\"" . $SITE_URL . "/images/row-contract.gif\" width=17 height=17 border=0 alt=\"Hide created genetic elements\"></a> Genetic elements available from this person:</b><br>";
        if(($probeavstart > 1) && ($probeavstart < 101))
          echo "<p>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probestring . $probestartstring . $probeclstring . $probeclstartstring . $probeavstring . "&amp;probeavstart=1" . $proberefstring . $printstring . "\">Display first set of genetic elements available from this person/organization</a></p>";
        else if($probeavstart > 100)
          echo "<p>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probestring . $probestartstring . $probeclstring . $probeclstartstring .  $probeavstring . "&amp;probeavstart=" . ($probeavstart - 100) . $proberefstring . $printstring . "\">Display previous set of 100 genetic elements available from this person/organization</a></p>";
        echo "<table summary=\"A table containing all the genetic elements available from this person\" width=\"100%\"><tr><td width=\"25%\" valign=top>\n";
        $tmpprobetype = $arrProbeav["TYPE"];
        $tmpprobeid = $arrProbeav["ID"];
        $tmpprobename = $arrProbeav["NAME"];
        echo "&nbsp;&nbsp;&nbsp;&nbsp;<a href=\"display";
        if($tmpprobetype == "171715")
          echo "bac";
        else if($tmpprobetype == "34")
          echo "est";
        else if($tmpprobetype == "393660")
          echo "overgo";
        else if($tmpprobetype == "104436")
          echo "ssr";
        else
          echo "probe";
        echo "record.cgi?id=" . $tmpprobeid . "\">" . $tmpprobename . "</a> ";
        if($tmpprobetype == "171715")
          echo "(BAC)";
        else if($tmpprobetype == "34")
          echo "(EST)";
        else if($tmpprobetype == "393660")
          echo "(Overgo)";
        else if($tmpprobetype == "104436")
          echo "(SSR)";
        else
          echo "(Probe)";
        echo "<br>\n";
        $rowcount = 1;
        $reccnt = 1;
        while($rowcount < 100) {
          if(@OCIFetchInto($statement_probeav,&$arrProbeav,OCI_ASSOC+OCI_RETURN_NULLS)) {
            if(($rowcount == 25) || ($rowcount == 50) || ($rowcount == 75))
            {
              echo "</td><td width=\"25%\" valign=top>&nbsp;&nbsp;&nbsp;&nbsp;<a href=\"display";
              $tmpprobetype = $arrProbeav["TYPE"];
              if($tmpprobetype == "171715")
                echo "bac";
              else if($tmpprobetype == "34")
                echo "est";
              else if($tmpprobetype == "393660")
                echo "overgo";
              else if($tmpprobetype == "104436")
                echo "ssr";
              else
                echo "probe";
              echo "record.cgi?id=" . $arrProbeav["ID"] . "\">" . $arrProbeav["NAME"] . "</a> ";
              if($tmpprobetype == "171715")
                echo "(BAC)";
              else if($tmpprobetype == "34")
                echo "(EST)";
              else if($tmpprobetype == "393660")
                echo "(Overgo)";
              else if($tmpprobetype == "104436")
                echo "(SSR)";
              else
                echo "(Probe)";

              echo "<br>\n";
            }
            else
            {
              echo "&nbsp;&nbsp;&nbsp;&nbsp;<a href=\"display";
              $tmpprobetype = $arrProbeav["TYPE"];
              if($tmpprobetype == "171715")
                echo "bac";
              else if($tmpprobetype == "34")
                echo "est";
              else if($tmpprobetype == "393660")
                echo "overgo";
              else if($tmpprobetype == "104436")
                echo "ssr";
              else
                echo "probe";
              echo "record.cgi?id=" . $arrProbeav["ID"] . "\">" . $arrProbeav["NAME"] . "</a> ";
              if($tmpprobetype == "171715")
                echo "(BAC)";
              else if($tmpprobetype == "34")
                echo "(EST)";
              else if($tmpprobetype == "393660")
                echo "(Overgo)";
              else if($tmpprobetype == "104436")
                echo "(SSR)";
              else
                echo "(Probe)";

              echo "<br>\n";
            }
            $reccnt = $reccnt + 1;
          }
          $rowcount = $rowcount + 1;
        }
        if($rowcount < 25)
          echo "</td><td width=\"75%\">&nbsp;</td></tr></table>";
        else if($rowcount < 50)
          echo "</td><td width=\"50%\">&nbsp;</td></tr></table>";
        else if($rowcount < 75)
          echo "</td><td width=\"25%\">&nbsp;</td></tr></table>";
        else
          echo "</td></tr></table>";
        echo "<p>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;You are viewing probes <b>" . $probeavstart . "</b> through <b>" . ($probeavstart - 1 + $reccnt) . "</b> ";
        $total_probeav_count_query = "SELECT count(id) from probe where available_from = " . $arrRecord["ID"];
        $total_probeav_count_statement = @OCIParse($DBConn,$total_probeav_count_query);
        @OCIExecute($total_probeav_count_statement,OCI_DEFAULT);
        @OCIFetchInto($total_probeav_count_statement,&$arrProbeavCount,OCI_ASSOC+OCI_RETURN_NULLS);
        echo "of a total <b>" . $arrProbeavCount["COUNT(ID)"] . "</b> probes available from this person/organization.</p>\n";
        $next_page_count = $arrProbeavCount["COUNT(ID)"] - ($probeavstart - 1 + $reccnt);
        if($next_page_count > 100)
          $next_page_count = 100;
        if($arrProbeavCount["COUNT(ID)"] > $probeavend)
          echo "<p>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probestring . $probestartstring . $probeclstring . $probeclstartstring . $probeavstring . "&amp;probeavstart=" . ($probeavstart + 100) . $proberefstring . $printstring . "\">Display next " . $next_page_count . " probes available from this person/organization</a></p>";
      }
    }
    else {
      $probeav_query = "SELECT count(id) from PROBE where AVAILABLE_FROM = " . $arrRecord["ID"] . " ORDER BY name";
      $statement_probeav = @OCIParse($DBConn,$probeav_query);
      @OCIExecute($statement_probeav,OCI_DEFAULT);
      @OCIFetchInto($statement_probeav,&$arrProbeav,OCI_ASSOC+OCI_RETURN_NULLS);
      if($arrProbeav["COUNT(ID)"] != "0") {
        echo "<a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probestring . $probestartstring . "&amp;probeav=1" . $probeclstring . $probeclstartstring . $proberefstring . $printstring . "#probeav\"><img src=\"" . $SITE_URL . "/images/row-expand.gif\" width=17 height=17 border=0 alt=\"Show available genetic elements\"></a> This person/group has made <b>" . $arrProbeav["COUNT(ID)"] . "</b> genetic elements available.  Click the green arrow to the left to view them.<br>\n";
      }
    }
////////////////////////////  Clone Library Code ///////////////////////////////////////////

 if($_GET["probecl"] == "1") {
      $probecl_query = "SELECT name,id from CLONE_LIBRARY where MADE_BY = '" . $arrRecord["ID"] . "'"; //16906';"SELECT name,type,id from PROBE where rowid in (SELECT rowid FROM probe WHERE AVAILABLE_FROM = " . $arrRecord["ID"] . " AND rownum <= " . $probeavend . " MINUS SELECT rowid FROM probe WHERE AVAILABLE_FROM = " . $arrRecord["ID"] . " AND rownum < " . $probeavstart . ") ORDER BY name";
      $statement_probecl = @OCIParse($DBConn,$probecl_query);
      @OCIExecute($statement_probecl,OCI_DEFAULT);
      @OCIFetchInto($statement_probecl,&$arrprobecl,OCI_ASSOC+OCI_RETURN_NULLS);
      if(strlen($arrprobecl["NAME"]) > 0) {
        echo "<a name=\"probecl\"></a><b><a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probestring . $probestartstring . $probeavstring . $probeavstartstring . $proberefstring . $printstring . "\"><img src=\"" . $SITE_URL . "/images/row-contract.gif\" width=17 height=17 border=0 alt=\"Hide created genetic elements\"></a> Clone Libraries available from this person/organization:</b><br>";
        echo "<table summary=\"A table containing all the clone libraries available from this person\" width=\"100%\"><tr><td width=\"25%\" valign=top>\n";

        $tmpprobeid = $arrprobecl["ID"];
        $tmpprobename = $arrprobecl["NAME"];
        echo "&nbsp;&nbsp;&nbsp;&nbsp;<a href=\"displaycl";
        echo "record.cgi?id=" . $tmpprobeid . "\">" . $tmpprobename . "</a> ";
         echo "(Clone Library)";
        echo "<br>\n";
        $rowcount = 1;
        $reccnt = 1;
		
	
		     while(@OCIFetchInto($statement_probecl,&$arrprobecl,OCI_ASSOC+OCI_RETURN_NULLS)) {
          
              echo "&nbsp;&nbsp;&nbsp;&nbsp;<a href=\"display";
              echo "cl";
              echo "record.cgi?id=" . $arrprobecl["ID"] . "\">" . $arrprobecl["NAME"] . "</a> ";
              echo "(Clone Library)";

              echo "<br>\n";
           
			}
      
          echo "</td></tr></table>";
        echo "<p>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;You are viewing clone libraries <b>" . "1" . "</b> through <b>";
        $total_probecl_count_query = "SELECT count(id) from CLONE_LIBRARY where MADE_BY = " . $arrRecord["ID"];
        $total_probecl_count_statement = @OCIParse($DBConn,$total_probecl_count_query);
        @OCIExecute($total_probecl_count_statement,OCI_DEFAULT);
        @OCIFetchInto($total_probecl_count_statement,&$arrprobeclCount,OCI_ASSOC+OCI_RETURN_NULLS);
        echo $arrprobeclCount["COUNT(ID)"] . "</b> " . "of a total <b>" . $arrprobeclCount["COUNT(ID)"] . "</b> clone libraries available from this person/organization.</p>\n";
       
	   }
    }
    else {
      $probecl_query = "SELECT count(id) from CLONE_LIBRARY where MADE_BY = " . $arrRecord["ID"] . " ORDER BY name";
      $statement_probecl = @OCIParse($DBConn,$probecl_query);
      @OCIExecute($statement_probecl,OCI_DEFAULT);
      @OCIFetchInto($statement_probecl,&$arrprobecl,OCI_ASSOC+OCI_RETURN_NULLS);
      if($arrprobecl["COUNT(ID)"] != "0") {
        echo "<a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probestring . $probestartstring . "&amp;probecl=1" . $proberefstring . $probeclstring . $probeclstartstring .$printstring . "#probecl\"><img src=\"" . $SITE_URL . "/images/row-expand.gif\" width=17 height=17 border=0 alt=\"Show available genetic elements\"></a> This person/group has <b>" . $arrprobecl["COUNT(ID)"] . "</b> clone libraries available.  Click the green arrow to the left to view them.<br>\n";
      }
    }

//////////////////////////// End Clone Lib Code ///////////////////////////////////////////
	
	
    echo "</p>";

    $query_comments = "SELECT MEMO FROM MEMO WHERE ID = " . $id;
    $statement_comments = @OCIParse($DBConn,$query_comments);
    @OCIExecute($statement_comments,OCI_DEFAULT);
    @OCIFetchInto($statement_comments,&$arrComments, OCI_ASSOC+OCI_RETURN_NULLS);

    if(strlen($arrComments["MEMO"]) > 1) {
      echo "<p><b>Comments:</b></p>\n";
      echo "<p>" . $arrComments["MEMO"] . "</p>\n";
      while(@OCIFetchInto($statement_comments,&$arrComments, OCI_ASSOC+OCI_RETURN_NULLS))
        echo "<p>" . $arrComments["MEMO"] . "</p>\n";
    }

    $query_find_user_annotations = "SELECT A.AUTO_NUM, A.MEMO, A.MOD_DATE, B.ID, B.FIRST_NAME, B.LAST_NAME, B.USERNAME, B.PASSWORD FROM ANNOTATION A, ANNOTATION_AUTHOR B WHERE A.ANN_AUTHOR_ID = B.ID AND A.ID = " . $id . " AND B.CURATION_LVL < 2 AND A.CURATION_LVL < 2 ORDER BY A.MOD_DATE";
    $stmt_user_annotations = make_query($DBConn,$query_find_user_annotations,5);
    $arrAnnotations = retrieve_row($stmt_user_annotations);
    if(strlen($arrAnnotations["MEMO"]) > 0)
    {
      echo "<p><b>User Annotations:</b></p>\n";
      echo "<p><b><a href=\"displayannotatorrecord.cgi?id=" . $arrAnnotations["ID"] . "\">" . trim($arrAnnotations["FIRST_NAME"]) . " " . trim($arrAnnotations["LAST_NAME"]) . "</a></b> (<i>" . $arrAnnotations["MOD_DATE"] . "</i>)<br>\n";
      echo "<span style=\"margin-left: 10px;\">" . mgdb_safe_html($arrAnnotations["MEMO"]) . "</span>\n";

      if(($arrAnnotations["ID"] == $userid) && ($arrAnnotations["USERNAME"] == $username) && ($arrAnnotations["PASSWORD"] == $password))
        echo "<br><i><a target=\"new\" href=\"edit_annotation.cgi?id=" . $arrAnnotations["AUTO_NUM"] . "\">Edit this annotation!</a></i>";
      echo "</p>\n";
      $arrAnnotations = retrieve_row($stmt_user_annotations);
      while(strlen($arrAnnotations["MEMO"]) > 0)
      {
        echo "<p><b><a href=\"displayannotatorrecord.cgi?id=" . $arrAnnotations["ID"] . "\">" . trim($arrAnnotations["FIRST_NAME"]) . " " . trim($arrAnnotations["LAST_NAME"]) . "</a></b> (<i>" . $arrAnnotations["MOD_DATE"] . "</i>)<br>\n";
        echo "<span style=\"margin-left: 10px;\">" . mgdb_safe_html($arrAnnotations["MEMO"]) . "</span>\n";
        if(($arrAnnotations["ID"] == $userid) && ($arrAnnotations["USERNAME"] == $username) && ($arrAnnotations["PASSWORD"] == $password))
          echo "<br><i><a target=\"new\" href=\"edit_annotation.cgi?id=" . $arrAnnotations["AUTO_NUM"] . "\">Edit this annotation!</a></i>";
        echo "</p>\n";
        $arrAnnotations = retrieve_row($stmt_user_annotations);
      }
    }

    echo "<p></p>";

    echo "<form action=\"" . $SITE_URL . "/cgi-bin/displaypersonresults.cgi\" method=\"get\">\n";
    echo "<label for=\"mini\">You can try another search through the person/organization data...</label><br>\n";
    $search_term = $arrRecord["NAME_LAST"] . " " . $arrRecord["NAME_FIRST"];
    echo "<input type=\"text\" name=\"term\" id=\"mini\" value=\"" . $search_term . "\" size=\"20\">\n";
    echo "<input type=\"submit\" value=\"submit\" id=\"minisub\">\n";
    echo "<br></form>\n";
	
	if($_GET["print"] == "1")
      echo "<p><a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probestring . $probestartstring . $probeclstring . $probeclstartstring . $probeavstring . $proberefstring . "\">See record in normal MaizeGDB format</a></p>\n";
    else
      echo "<p><a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probestring . $probestartstring . $probeclstring . $probeclstartstring . $probeavstring . $proberefstring . "&amp;print=1\">See record in a printer-friendly format</a></p>\n";

  

    echo "<p><a href=\"" . $SITE_URL . "\">Return to the homepage</a></p>\n";
	
	echo "</td><td align='right' valign='top'>";
		echo "<table style='border:thick double #CFB53B;' cellspacing='5' cellpadding='3'><tr><td valign='top'>";
		echo "<img height='55px'  src='../icons/gold_star.jpg'></img>";
		
		echo "</td>"; 
		 echo "<td>";
		 if($arrgold["BTITLE"])
		 {
		
		
		echo "<h4>Maize Gene Review </h4>";
		echo "" . $namesave . " has authored " .  $arrgoldc["CCOUNT"] . " gene reviews. <span id='review1'>(<a href='javascript:showReview(1)'>See the reviews</a>)</span>";
		echo "<div id='review2' style='display: none;'>";
		while($arrgold["BTITLE"])
		{
		echo "<br><br>";
		 $gold_query2 = "SELECT E.NAME AS ENAME, C.NAME_FIRST AS FNAME, C.NAME_LAST AS LNAME, C.ID AS CID, A.ID AS AID FROM REFERENCE A JOIN REFERENCE_AUTHORS B ON A.ID = B.ID JOIN PERSON C ON C.ID = B.AUTHOR JOIN ID_REFERENCE D ON D.REFERENCE = a.ID JOIN LOCUS E ON E.ID = D.ID  WHERE A.ID = '" . $arrgold["BID"] . "'";
		$stmt_gold2 = make_query($DBConn,$gold_query2,1);
		$arrgold2 = retrieve_row($stmt_gold2);
		
		echo "<a href='displayrefrecord.cgi?id=" . $arrgold["BID"]  . "'><u><b>" . $arrgold["BTITLE"] . "</b></u></a>";
		$gfirst = 1;
		while ($arrgold2["CID"])
		{
	if($gfirst == 1)
		{
		echo "<br>by <a href='displaypersonrecord.cgi?id=" . $arrgold2["CID"]  . "'>" . $arrgold2["FNAME"] . " " . $arrgold2["LNAME"] . "</a>";
		$gfirst = 0;
		} else {
		echo ", <a href='displaypersonrecord.cgi?id=" . $arrgold2["CID"]  . "'>" . $arrgold2["FNAME"] . " " . $arrgold2["LNAME"] . "</a>";
		}
		$arrgold2 = retrieve_row($stmt_gold2);
		}
		$gold_query3 = "SELECT E.NAME AS ENAME FROM REFERENCE A JOIN ID_REFERENCE D ON D.REFERENCE = a.ID JOIN LOCUS E ON E.ID = D.ID WHERE A.ID = '" . $arrgold["BID"] . "'";
		$stmt_gold3 = make_query($DBConn,$gold_query3,1);
		$arrgold3 = retrieve_row($stmt_gold3);
		echo "<br>(<a href='http://www.maizegenereview.org/" . $arrgold3["ENAME"] . ".html'>Read the review</a>)";
		
		$arrgold = retrieve_row($stmt_gold);
		}
		echo "</div>";
		
		}
		
		if($arrgold_ed["YEAR"])
		{
			echo "<h4>Editorial Board </h4>";
			$first = 1;
			while($arrgold_ed["YEAR"])
			{
			if($first == 0 )
			{
				echo "<br>";
			}
			$first = 0;
			echo "" . $namesave . " has served on the editorial board:";
			echo " " . $arrgold_ed["YEAR"];
			echo ".";
			$arrgold_ed = retrieve_row($stmt_gold_ed);
			}
			
			
			
		}
		echo "</td></tr></table>";
		
		
	echo "</td></tr></table>";
  /////
  /////
  ///////
  } else {
  //////
  ///////
  ///////
  
    $query = "SELECT * from person where ID = " . $id;
    $statement = @OCIParse($DBConn,$query);
    @OCIExecute($statement,OCI_DEFAULT);
    @OCIFetchInto($statement,&$arrRecord, OCI_ASSOC+OCI_RETURN_NULLS);
    if((strlen(trim($arrRecord["NAME_FIRST"])) > 0) && (strlen(trim($arrRecord["NAME_LAST"])) > 0)) {
      $headline = $arrRecord["NAME_FIRST"] . " " . $arrRecord["NAME_LAST"]; 
      $title = $arrRecord["NAME_FIRST"] . " " . $arrRecord["NAME_LAST"];
      if(strlen($arrRecord["SUFFIX"]) > 0) {
        $headline = $headline . ", " . $arrRecord["SUFFIX"];
        $title = $title . ", " . $arrRecord["SUFFIX"];
      }
    }
    else {
      $headline = $arrRecord["NAME"];
      $title = $arrRecord["NAME"];
    }
    $headline = $headline . " (<a href=\"" . $SITE_URL . "/person.php\">person/organization</a>)</span></p>";
    //echo page_header_new("MaizeGDB: " . $title,"MaizeGDB Person Record: " . $title,$username,$password,$userid);
    echo "<table summary=\"A table to provide control over the primary layout of the page\" width=\"100%\" cellpadding=5 cellspacing=5><tr><td valign=top>\n";

    $probestart = $_GET["probestart"];
    $probeavstart = $_GET["probeavstart"];    
  
    if(strlen($probestart) == 0) {
      $probestart = 1;
    }
    if(!(settype($probestart,'integer'))) {
      $probestart = 1;
    }
    if($probestart < 1) {
      $probestart = 1;
    }

    if(strlen($probeavstart) == 0) {
      $probeavstart = 1;
    }
    if(!(settype($probeavstart,'integer'))) {
      $probeavstart = 1;
    }
    if($probeavstart < 1) {
      $probeavstart = 1;
    }

    $probeavend = $probeavstart + 100;
    $probeend = $probestart + 100;

    $probestring = "";
    $probeavstring = "";
    $proberefstring = "";
    $printstring = "";
    $probeclstring = "";
	
    if($_GET["probe"] == "1")
      $probestring = "&amp;probe=1";
    if($_GET["probeav"] == "1")
      $probeavstring = "&amp;probeav=1";
    if($_GET["proberef"] == "1")
      $proberefstring = "&amp;proberef=1";
    if($_GET["print"] == "1")
      $printstring = "&amp;print=1";
	if($_GET["probecl"] == "1")
      $probeclstring = "&amp;probecl=1";
    $probestartstring = "&amp;probestart=" . $probestart;
    $probeavstartstring = "&amp;probeavstart=" . $probeavstart;
    $probeclstartstring = "&amp;probeclstart=" . $probeclstart;


    echo "<p style=\"margin-bottom: 8px;\"><span style=\"font-family: Verdana, Arial, sans-serif; font-size: 120%; font-weight: bold; margin-bottom: 6px;\">";
    //echo "<p><img src=\"default_pic.jpg\" width=17 height=17 border=0 alt=\"Hide references\"></p>\n";

    //$imagelink="http://ftp.maizegdb.org/person_images/images/"."$id".".jpg";
    $imagelink="images/"."$id".".jpg";
    $url1=getimagesize($imagelink);

	if(!is_array($url1))
	   {
	    //$imagelink="http://ftp.maizegdb.org/person_images/images/"."$id".".jpeg";
        $imagelink="images/"."$id".".jpeg";
        $url2=getimagesize($imagelink);
        		if(!is_array($url2))
        		{
        			//$imagelink="http://ftp.maizegdb.org/person_images/images/"."$id".".png";
                $imagelink="images/"."$id".".png";
        			$url3=getimagesize($imagelink);
				if(!is_array($url3))
        			{
        				//$imagelink="http://ftp.maizegdb.org/person_images/images/"."$id".".gif";
        				$imagelink="images/"."$id".".gif";
	        			$url4=getimagesize($imagelink);
	        			if(!is_array($url4))
	        				$imagelink="default_pic.jpg";
        			}
        		}
	   }
		
    
    echo "<p><img src=$imagelink width=91 height=91 border=2 alt=\"default_pic.jpg\" align=middle>  $headline</p>";
    /*echo "<div id='id2' style='display: inline'><a href=\"javascript:showDetail()\"><font size=\"2\">Upload Picture</font></a></div>";
    echo "<div id='id3' style='display: none'><a href=\"javascript:showDetail()\"><font size=\"2\">Close</font></a></div><br><br>";
    //$link = "change_pic.php?id=".urlencode($id);
    echo "<iframe id=\"id1\" src=$link style='display: none' width=\"50%\" height=\"90px\"><p>Your browser does not support iframes.</p></iframe>";*/
    //$link_window="http://ftp.maizegdb.org/person_images/upload_crop.php?id=".urlencode($id);
    $query_email = "SELECT EMAIL_ADDRESS FROM PERSON_EMAIL WHERE ID = " . $arrRecord["ID"];
    $statement_email = @OCIParse($DBConn,$query_email);
    @OCIExecute($statement_email,OCI_DEFAULT);
    @OCIFetchInto($statement_email,&$arrEmail, OCI_ASSOC+OCI_RETURN_NULLS);
    //echo "email=".$arrEmail["EMAIL_ADDRESS"];
    $link="upload_crop.php?id=".urlencode($id)."&email=".urlencode($arrEmail["EMAIL_ADDRESS"]);
    echo "<FORM><INPUT type=\"button\" value=\"Upload New Picture\" onClick=\"window.open('$link','mywindow','width=400,height=400')\"></FORM>";
    	

    $query_syn = "SELECT SYNONYMS FROM SYNONYMS WHERE ID = " . $arrRecord["ID"] . " AND SYNONYMS != '" . $arrRecord["NAME"] . "'";
    $statement_syn = @OCIParse($DBConn,$query_syn);
    @OCIExecute($statement_syn,OCI_DEFAULT);
    @OCIFetchInto($statement_syn,&$arrSyn, OCI_ASSOC+OCI_RETURN_NULLS);

    if(strlen($arrSyn["SYNONYMS"]) > 0) {
      echo "<p>Also known by these names: ";
      echo $arrSyn["SYNONYMS"];
      while(@OCIFetchInto($statement_syn,&$arrSyn,OCI_ASSOC+OCI_RETURN_NULLS)){
        echo "; " . $arrSyn["SYNONYMS"]; }
      echo "</p>\n";
    } 

    if((strlen($username) > 0) && (strlen($password) > 0) && (strlen($userid) > 0))
      echo "<p><b><a href=\"create_annotation.cgi?id=" . $id . "\" target=\"new\">Add your own annotation to this record!</a></b></p>\n";

    echo "<p>Is the contact information here incorrect?  <a href=\"" . $SITE_URL . "/cgi-bin/update_person.cgi?id=" . $id . "\">Please help us correct it!</a></p>\n";

    if(strlen($arrRecord["ADDRESS"]) > 0) {
//      echo "<p><b>Address</b>:<br>\n";
      echo nl2br($arrRecord["ADDRESS"]) . "<br>\n";
      echo $arrRecord["CITY"] . " " . $arrRecord["STATE"] . " " . $arrRecord["COUNTRY"] . " " . $arrRecord["POSTAL_CODE"] . "<br>\n";
    } else {
      echo "<b>Address</b>: No address given<br>\n";
    }

    $query_email = "SELECT EMAIL_ADDRESS FROM PERSON_EMAIL WHERE ID = " . $arrRecord["ID"];
    $statement_email = @OCIParse($DBConn,$query_email);
    @OCIExecute($statement_email,OCI_DEFAULT);
    @OCIFetchInto($statement_email,&$arrEmail, OCI_ASSOC+OCI_RETURN_NULLS);

//    echo "<b>Email Address(es)</b>: ";
    if(strlen($arrEmail["EMAIL_ADDRESS"]) > 0) {
      echo "<a href=\"mailto:" . $arrEmail["EMAIL_ADDRESS"] . "\">" . $arrEmail["EMAIL_ADDRESS"] . "</a>";
      while(@OCIFetchInto($statement_email,&$arrEmail,OCI_ASSOC+OCI_RETURN_NULLS)) {
        echo ", <a href=\"mailto:" . $arrEmail["EMAIL_ADDRESS"] . "\">" . $arrEmail["EMAIL_ADDRESS"] . "</a>"; }
      echo "<br>\n";
    }
//    else echo "No email address given.<br>\n"; 

    $query_url = "SELECT URL from WEB_DATA where ID = " . $arrRecord["ID"];
    $statement_url = @OCIParse($DBConn,$query_url);
    @OCIExecute($statement_url,OCI_DEFAULT);
    @OCIFetchInto($statement_url,&$arrUrl,OCI_ASSOC+OCI_RETURN_NULLS);

//    echo "<b>URL(s)</b>: ";
    if(strlen($arrUrl["URL"]) > 0) {
      echo "<a href=\"" . $arrUrl["URL"] . "\">" . $arrUrl["URL"] . "</a>";
      while(@OCIFetchInto($statement_url,&$arrUrl,OCI_ASSOC+OCI_RETURN_NULLS)){
        echo ", <a href=\"" . $arrUrl["URL"] . "\">" . $arrUrl["URL"] . "</a>";
      }
      echo "<br>\n";
    }
//    else echo "No URL given.<br>\n";

    $query_phone = "SELECT PHONE_NUM from PERSON_PHONE_NUM where ID = " . $arrRecord["ID"] . " ORDER BY PHONE_NUM";
    $statement_phone = @OCIParse($DBConn,$query_phone);
    @OCIExecute($statement_phone,OCI_DEFAULT);
    @OCIFetchInto($statement_phone,&$arrPhone,OCI_ASSOC+OCI_RETURN_NULLS);
    
//    echo "<b>Phone number(s)</b>: ";
 
    if(strlen($arrPhone["PHONE_NUM"]) > 0) {
      echo $arrPhone["PHONE_NUM"];
      while(@OCIFetchInto($statement_phone,&$arrPhone,OCI_ASSOC+OCI_RETURN_NULLS))
        echo "<br>" . $arrPhone["PHONE_NUM"];
    } else {
//      echo "No phone numbers given.";
    }
    echo "</p>\n";
	
	  $query_attr1 = "SELECT COUNT(ATTRIBUTE) AS CNTA FROM PERSON_ATTRIBUTE WHERE ID = " . $arrRecord["ID"] . "";
    $statement_attr1 = @OCIParse($DBConn,$query_attr1);
    @OCIExecute($statement_attr1,OCI_DEFAULT);
	@OCIFetchInto($statement_attr1,&$arrattr1,OCI_ASSOC+OCI_RETURN_NULLS);
	
	if($arrattr1["CNTA"] == 1)
	{
	
		 $query_attr = "SELECT ATTRIBUTE FROM PERSON_ATTRIBUTE WHERE ID = " . $arrRecord["ID"] . "";
    $statement_attr = @OCIParse($DBConn,$query_attr);
    @OCIExecute($statement_attr,OCI_DEFAULT);
	@OCIFetchInto($statement_attr,&$arrattr,OCI_ASSOC+OCI_RETURN_NULLS);
	$query_role = "SELECT NAME from TERM where ID = " . $arrattr["ATTRIBUTE"];
    $statement_role = @OCIParse($DBConn,$query_role);
    @OCIExecute($statement_role,OCI_DEFAULT);
    @OCIFetchInto($statement_role,&$arrRole,OCI_ASSOC+OCI_RETURN_NULLS);

	echo "<p><b>Role</b>: " . $arrRole["NAME"] . "</p>";
	
	} else if($arrattr1["CNTA"] > 1)
	{
			  $query_attr = "SELECT ATTRIBUTE FROM PERSON_ATTRIBUTE WHERE ID = " . $arrRecord["ID"] . "";
    $statement_attr = @OCIParse($DBConn,$query_attr);
    @OCIExecute($statement_attr,OCI_DEFAULT);
    $firstflag = true;
	while(@OCIFetchInto($statement_attr,&$arrattr,OCI_ASSOC+OCI_RETURN_NULLS))
	{
		   $query_role = "SELECT NAME from TERM where ID = " . $arrattr["ATTRIBUTE"];
    $statement_role = @OCIParse($DBConn,$query_role);
    @OCIExecute($statement_role,OCI_DEFAULT);
    @OCIFetchInto($statement_role,&$arrRole,OCI_ASSOC+OCI_RETURN_NULLS);
	
		if($firstflag)
		{
			echo "<p><b>Roles(" . $arrattr1["CNTA"] . ")</b>: " . $arrRole["NAME"];
			$firstflag = false;
		} else {
			echo ", " . $arrRole["NAME"];
		}
	}
		echo "</p>";
	}
	

	

 

    echo "<p>";

    $uri = $SITE_URL . "/cgi-bin/displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probestring . $probestartstring . $probeclstring . $probeclstartstring . $probeavstring . $probeavstartstring . $proberefstring . $printstring;

    if($_GET["proberef"] == "1") {
      $proberef_query = "SELECT a.id,a.order1 FROM reference_authors a, reference r, id_num i WHERE a.id = r.id AND r.id = i.id AND i.curation_lvl = 0 AND a.author = " . $arrRecord["ID"] . " ORDER BY r.year, r.name";
      $statement_proberef = @OCIParse($DBConn,$proberef_query);
      @OCIExecute($statement_proberef,OCI_DEFAULT);
      @OCIFetchInto($statement_proberef,&$arrProberef,OCI_ASSOC+OCI_RETURN_NULLS);
      if(strlen($arrProberef["ID"]) > 0) {
        echo "<a name=\"proberef\"></a><b><a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probestring . $probestartstring . $probeclstring . $probeclstartstring . $probeavstring . $probeavstartstring . $printstring . "\"><img src=\"" . $SITE_URL . "/images/row-contract.gif\" width=17 height=17 border=0 alt=\"Hide references\"></a> References authored by this person:</b><br>\n";
        $paper_name_query = "SELECT name,title FROM reference WHERE id = " . $arrProberef["ID"];
        $paper_name_statement =  @OCIParse($DBConn,$paper_name_query);
        @OCIExecute($paper_name_statement,OCI_DEFAULT);
        @OCIFetchInto($paper_name_statement,&$arrPaperName,OCI_ASSOC+OCI_RETURN_NULLS);
        if(strlen($arrPaperName["TITLE"]) > 0) { 
          echo "&nbsp;&nbsp;<a href=\"displayrefrecord.cgi?id=" . $arrProberef["ID"] . "\">" . $arrPaperName["NAME"] . "</a>";
          if($arrProberef["ORDER1"] == "1")
            echo "&nbsp;&nbsp;(primary author)";
          echo "<br>&nbsp;&nbsp;&nbsp;&nbsp;<i>" . strip_tags($arrPaperName["TITLE"]) . "</i>";
        }
        else
        {
          echo "&nbsp;&nbsp;<a href=\"displayrefrecord.cgi?id=" . $arrProberef["ID"] . "\">" . $arrPaperName["NAME"] . "</a>";
          if($arrProberef["ORDER1"] == "1")
            echo "&nbsp;&nbsp;(primary author)";
        }
        echo "<br>\n";
        while(@OCIFetchInto($statement_proberef,&$arrProberef,OCI_ASSOC+OCI_RETURN_NULLS))
        {
          $paper_name_query = "SELECT name,title FROM reference WHERE id = " . $arrProberef["ID"];
          $paper_name_statement =  @OCIParse($DBConn,$paper_name_query);
          @OCIExecute($paper_name_statement,OCI_DEFAULT);
          @OCIFetchInto($paper_name_statement,&$arrPaperName,OCI_ASSOC+OCI_RETURN_NULLS);
          if(strlen($arrPaperName["TITLE"]) > 0) 
          {
            echo "&nbsp;&nbsp;<a href=\"displayrefrecord.cgi?id=" . $arrProberef["ID"] . "\">" . $arrPaperName["NAME"] . "</a>";
            if($arrProberef["ORDER1"] == "1")
              echo "&nbsp;&nbsp;(primary author)";
            echo "<br>&nbsp;&nbsp;&nbsp;&nbsp;<i>" . strip_tags($arrPaperName["TITLE"]) . "</i>";
          }
          else
          {
            echo "&nbsp;&nbsp;<a href=\"displayrefrecord.cgi?id=" . $arrProberef["ID"] . "\">" . $arrPaperName["NAME"] . "</a>";
            if($arrProberef["ORDER1"] == "1")
              echo "&nbsp;&nbsp;(primary author)";
          }
          echo "<br>\n";
        }
      }
    }
    else {
      $probe_paper_query = "SELECT count(a.id) from reference_authors a join id_num b on a.id = b.id where b.curation_lvl = 0 and a.AUTHOR = " . $arrRecord["ID"];
      $statement_probe_paper = @OCIParse($DBConn,$probe_paper_query);
      @OCIExecute($statement_probe_paper,OCI_DEFAULT);
      @OCIFetchInto($statement_probe_paper,&$arrProbePaper,OCI_ASSOC+OCI_RETURN_NULLS);
      if($arrProbePaper["COUNT(A.ID)"] != "0") {
        echo "<a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . "&amp;proberef=1" . $probestring . $probestartstring . $probeclstring . $probeclstartstring . $probeavstring . $probeavstartstring . $printstring . "#proberef\"><img src=\"" . $SITE_URL . "/images/row-expand.gif\" width=17 height=17 border=0 alt=\"Show authored references\"></a> This person/group has authored <b>" . $arrProbePaper["COUNT(A.ID)"] . "</b> references.  Click the green arrow to the left to view them.<br>\n";
      }
    }

    if($_GET["probe"] == "1") {
      $probe_query = "SELECT name,type,id from PROBE where rowid in (SELECT rowid FROM probe WHERE PREPARED_BY = " . $arrRecord["ID"] . " AND rownum <= " . $probeend . " MINUS SELECT rowid FROM probe WHERE PREPARED_BY = " . $arrRecord["ID"] . " AND rownum < " . $probestart . ") ORDER BY name";
      $statement_probe = @OCIParse($DBConn,$probe_query);
      @OCIExecute($statement_probe,OCI_DEFAULT);
      @OCIFetchInto($statement_probe,&$arrProbe,OCI_ASSOC+OCI_RETURN_NULLS);
      $tmpprobename = $arrProbe["NAME"];
      $tmpprobeid = $arrProbe["ID"];
      $tmpprobetype = $arrProbe["TYPE"];
      if(strlen($arrProbe["NAME"]) > 0) {
        echo "<a name=\"probe\"></a><b><a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probeavstring . $probeavstartstring . $probeclstring . $probeclstartstring . $proberefstring . $printstring . "\"><img src=\"" . $SITE_URL . "/images/row-contract.gif\" width=17 height=17 border=0 alt=\"Hide created probes\"></a> Genetics created by this person:</b><br>";
        if(($probestart > 1) && ($probestart < 101))
          echo "<p>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probestring . "&amp;probestart=1" . $probeavstring . $probeavstartstring . $proberefstring . $printstring . $probeclstring . $probeclstartstring .  "\">Display first set of genetic elements created by this person/organization</a></p>";
        else if($probestart > 100)
          echo "<p>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probestring . "&amp;probestart=" . ($probestart - 100) . $probeavstring . $probeavstartstring . $proberefstring . $printstring . $probeclstring . $probeclstartstring . "\">Display previous set of 100 genetic elements created by this person/organization</a></p>";
        echo "<table summary=\"A table containing all the genetic elements created by this person\" width=\"100%\"><tr><td width=\"25%\" valign=top>\n";
        echo "&nbsp;&nbsp;&nbsp;&nbsp;<a href=\"display";
        if($tmpprobetype == "171715")
          echo "bac";
        else if($tmpprobetype == "34")
          echo "est";
        else if(($tmpprobetype == "393660") || ($tmpprobetype == "747274"))
          echo "overgo";
        else if($tmpprobetype == "104436")
          echo "ssr";
        else
          echo "probe";
        echo "record.cgi?id=" . $tmpprobeid . "\">" . $tmpprobename . "</a> ";
        if($tmpprobetype == "171715")
          echo "(BAC)";
        else if($tmpprobetype == "34")
          echo "(EST)";
        else if(($tmpprobetype == "393660") || ($tmpprobetype == "747274"))
          echo "(Overgo)";
        else if($tmpprobetype == "104436")
          echo "(SSR)";
        else
          echo "(Probe)";
        echo "<br>\n";
        $rowcount = 1;
        $reccnt = 1;
        while($rowcount < 100) {
          if(@OCIFetchInto($statement_probe,&$arrProbe,OCI_ASSOC+OCI_RETURN_NULLS)) {
            if(($rowcount == 25) || ($rowcount == 50) || ($rowcount == 75)) 
            {
              echo "</td><td width=\"25%\" valign=top>&nbsp;&nbsp;&nbsp;&nbsp;<a href=\"display";
              $tmpprobetype = $arrProbe["TYPE"];
              if($tmpprobetype == "171715")
                echo "bac";
              else if($tmpprobetype == "34")
                echo "est";
              else if(($tmpprobetype == "393660") || ($tmpprobetype == "747274"))
                echo "overgo";
              else if($tmpprobetype == "104436")
                echo "ssr";
              else
                echo "probe";
              echo "record.cgi?id=" . $arrProbe["ID"] . "\">" . $arrProbe["NAME"] . "</a> ";
              if($tmpprobetype == "171715")
                echo "(BAC)";
              else if($tmpprobetype == "34")
                echo "(EST)";
              else if(($tmpprobetype == "393660") || ($tmpprobetype == "747274"))
                echo "(Overgo)";
              else if($tmpprobetype == "104436")
                echo "(SSR)";
              else
                echo "(Probe)";

              echo "<br>\n";
            }
            else
            {
              echo "&nbsp;&nbsp;&nbsp;&nbsp;<a href=\"display";
              $tmpprobetype = $arrProbe["TYPE"];
              if($tmpprobetype == "171715")
                echo "bac";
              else if($tmpprobetype == "34")
                echo "est";
              else if($tmpprobetype == "393660")
                echo "overgo";
              else if($tmpprobetype == "104436")
                echo "ssr";
              else
                echo "probe";
              echo "record.cgi?id=" . $arrProbe["ID"] . "\">" . $arrProbe["NAME"] . "</a> ";
              if($tmpprobetype == "171715")
                echo "(BAC)";
              else if($tmpprobetype == "34")
                echo "(EST)";
              else if($tmpprobetype == "393660")
                echo "(Overgo)";
              else if($tmpprobetype == "104436")
                echo "(SSR)";
              else
                echo "(Probe)";

              echo "<br>\n";
            }
            $reccnt = $reccnt + 1;
          }
          $rowcount = $rowcount + 1;
        }
        if($rowcount < 25)
          echo "</td><td width=\"75%\">&nbsp;</td></tr></table>";
        else if($rowcount < 50)
          echo "</td><td width=\"50%\">&nbsp;</td></tr></table>";
        else if($rowcount < 75)
          echo "</td><td width=\"25%\">&nbsp;</td></tr></table>";
        else
          echo "</td></tr></table>";
        echo "<p>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;You are viewing elements <b>" . $probestart . "</b> through <b>" . ($probestart - 1 + $reccnt) . "</b> ";
        $total_probe_count_query = "SELECT count(id) from probe where prepared_by = " . $arrRecord["ID"];
        $total_probe_count_statement = @OCIParse($DBConn,$total_probe_count_query);
        @OCIExecute($total_probe_count_statement,OCI_DEFAULT);
        @OCIFetchInto($total_probe_count_statement,&$arrProbeCount,OCI_ASSOC+OCI_RETURN_NULLS);
        echo "of a total <b>" . $arrProbeCount["COUNT(ID)"] . "</b> probes created by this person/organization.</p>\n";
        $next_page_count = $arrProbeCount["COUNT(ID)"] - ($probestart - 1 + $reccnt);
        if($next_page_count > 100)
          $next_page_count = 100;
        if($arrProbeCount["COUNT(ID)"] > $probeend)
          echo "<p>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probestring . "&amp;probestart=" . ($probestart + 100) . $probeavstring . $probeavstartstring . $probeclstring . $probeclstartstring . $proberefstring . $printstring . "\">Display next " . $next_page_count . " genetic elements created by this person/organization</a></p>";
      }
    }
    else {
      $probe_query = "SELECT count(id) from PROBE where PREPARED_BY = " . $arrRecord["ID"] . " ORDER BY name";
      $statement_probe = @OCIParse($DBConn,$probe_query);
      @OCIExecute($statement_probe,OCI_DEFAULT);
      @OCIFetchInto($statement_probe,&$arrProbe,OCI_ASSOC+OCI_RETURN_NULLS);
      if($arrProbe["COUNT(ID)"] > 0) {
        echo "<a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . "&amp;probe=1" . $probeavstring . $probeavstartstring . $probeclstring . $probeclstartstring . $proberefstring . $printstring . "#probe\"><img src=\"" . $SITE_URL . "/images/row-expand.gif\" width=17 height=17 border=0 alt=\"Show created genetic elements\"></a> This person/group has created <b>" . $arrProbe["COUNT(ID)"] . "</b> genetic elements.  Click the green arrow to the left to view them.<br>\n";
      }
    }

    if($_GET["probeav"] == "1") {
      $probeav_query = "SELECT name,type,id from PROBE where rowid in (SELECT rowid FROM probe WHERE AVAILABLE_FROM = " . $arrRecord["ID"] . " AND rownum <= " . $probeavend . " MINUS SELECT rowid FROM probe WHERE AVAILABLE_FROM = " . $arrRecord["ID"] . " AND rownum < " . $probeavstart . ") ORDER BY name";
      $statement_probeav = @OCIParse($DBConn,$probeav_query);
      @OCIExecute($statement_probeav,OCI_DEFAULT);
      @OCIFetchInto($statement_probeav,&$arrProbeav,OCI_ASSOC+OCI_RETURN_NULLS);
      if(strlen($arrProbeav["NAME"]) > 0) {
        echo "<a name=\"probeav\"></a><b><a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probestring . $probeclstring . $probeclstartstring . $probestartstring . $proberefstring . $printstring . "\"><img src=\"" . $SITE_URL . "/images/row-contract.gif\" width=17 height=17 border=0 alt=\"Hide created genetic elements\"></a> Genetic elements available from this person:</b><br>";
        if(($probeavstart > 1) && ($probeavstart < 101))
          echo "<p>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probestring . $probestartstring . $probeclstring . $probeclstartstring . $probeavstring . "&amp;probeavstart=1" . $proberefstring . $printstring . "\">Display first set of genetic elements available from this person/organization</a></p>";
        else if($probeavstart > 100)
          echo "<p>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probestring . $probestartstring . $probeclstring . $probeclstartstring .  $probeavstring . "&amp;probeavstart=" . ($probeavstart - 100) . $proberefstring . $printstring . "\">Display previous set of 100 genetic elements available from this person/organization</a></p>";
        echo "<table summary=\"A table containing all the genetic elements available from this person\" width=\"100%\"><tr><td width=\"25%\" valign=top>\n";
        $tmpprobetype = $arrProbeav["TYPE"];
        $tmpprobeid = $arrProbeav["ID"];
        $tmpprobename = $arrProbeav["NAME"];
        echo "&nbsp;&nbsp;&nbsp;&nbsp;<a href=\"display";
        if($tmpprobetype == "171715")
          echo "bac";
        else if($tmpprobetype == "34")
          echo "est";
        else if($tmpprobetype == "393660")
          echo "overgo";
        else if($tmpprobetype == "104436")
          echo "ssr";
        else
          echo "probe";
        echo "record.cgi?id=" . $tmpprobeid . "\">" . $tmpprobename . "</a> ";
        if($tmpprobetype == "171715")
          echo "(BAC)";
        else if($tmpprobetype == "34")
          echo "(EST)";
        else if($tmpprobetype == "393660")
          echo "(Overgo)";
        else if($tmpprobetype == "104436")
          echo "(SSR)";
        else
          echo "(Probe)";
        echo "<br>\n";
        $rowcount = 1;
        $reccnt = 1;
        while($rowcount < 100) {
          if(@OCIFetchInto($statement_probeav,&$arrProbeav,OCI_ASSOC+OCI_RETURN_NULLS)) {
            if(($rowcount == 25) || ($rowcount == 50) || ($rowcount == 75))
            {
              echo "</td><td width=\"25%\" valign=top>&nbsp;&nbsp;&nbsp;&nbsp;<a href=\"display";
              $tmpprobetype = $arrProbeav["TYPE"];
              if($tmpprobetype == "171715")
                echo "bac";
              else if($tmpprobetype == "34")
                echo "est";
              else if($tmpprobetype == "393660")
                echo "overgo";
              else if($tmpprobetype == "104436")
                echo "ssr";
              else
                echo "probe";
              echo "record.cgi?id=" . $arrProbeav["ID"] . "\">" . $arrProbeav["NAME"] . "</a> ";
              if($tmpprobetype == "171715")
                echo "(BAC)";
              else if($tmpprobetype == "34")
                echo "(EST)";
              else if($tmpprobetype == "393660")
                echo "(Overgo)";
              else if($tmpprobetype == "104436")
                echo "(SSR)";
              else
                echo "(Probe)";

              echo "<br>\n";
            }
            else
            {
              echo "&nbsp;&nbsp;&nbsp;&nbsp;<a href=\"display";
              $tmpprobetype = $arrProbeav["TYPE"];
              if($tmpprobetype == "171715")
                echo "bac";
              else if($tmpprobetype == "34")
                echo "est";
              else if($tmpprobetype == "393660")
                echo "overgo";
              else if($tmpprobetype == "104436")
                echo "ssr";
              else
                echo "probe";
              echo "record.cgi?id=" . $arrProbeav["ID"] . "\">" . $arrProbeav["NAME"] . "</a> ";
              if($tmpprobetype == "171715")
                echo "(BAC)";
              else if($tmpprobetype == "34")
                echo "(EST)";
              else if($tmpprobetype == "393660")
                echo "(Overgo)";
              else if($tmpprobetype == "104436")
                echo "(SSR)";
              else
                echo "(Probe)";

              echo "<br>\n";
            }
            $reccnt = $reccnt + 1;
          }
          $rowcount = $rowcount + 1;
        }
        if($rowcount < 25)
          echo "</td><td width=\"75%\">&nbsp;</td></tr></table>";
        else if($rowcount < 50)
          echo "</td><td width=\"50%\">&nbsp;</td></tr></table>";
        else if($rowcount < 75)
          echo "</td><td width=\"25%\">&nbsp;</td></tr></table>";
        else
          echo "</td></tr></table>";
        echo "<p>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;You are viewing probes <b>" . $probeavstart . "</b> through <b>" . ($probeavstart - 1 + $reccnt) . "</b> ";
        $total_probeav_count_query = "SELECT count(id) from probe where available_from = " . $arrRecord["ID"];
        $total_probeav_count_statement = @OCIParse($DBConn,$total_probeav_count_query);
        @OCIExecute($total_probeav_count_statement,OCI_DEFAULT);
        @OCIFetchInto($total_probeav_count_statement,&$arrProbeavCount,OCI_ASSOC+OCI_RETURN_NULLS);
        echo "of a total <b>" . $arrProbeavCount["COUNT(ID)"] . "</b> probes available from this person/organization.</p>\n";
        $next_page_count = $arrProbeavCount["COUNT(ID)"] - ($probeavstart - 1 + $reccnt);
        if($next_page_count > 100)
          $next_page_count = 100;
        if($arrProbeavCount["COUNT(ID)"] > $probeavend)
          echo "<p>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probestring . $probestartstring . $probeclstring . $probeclstartstring . $probeavstring . "&amp;probeavstart=" . ($probeavstart + 100) . $proberefstring . $printstring . "\">Display next " . $next_page_count . " probes available from this person/organization</a></p>";
      }
    }
    else {
      $probeav_query = "SELECT count(id) from PROBE where AVAILABLE_FROM = " . $arrRecord["ID"] . " ORDER BY name";
      $statement_probeav = @OCIParse($DBConn,$probeav_query);
      @OCIExecute($statement_probeav,OCI_DEFAULT);
      @OCIFetchInto($statement_probeav,&$arrProbeav,OCI_ASSOC+OCI_RETURN_NULLS);
      if($arrProbeav["COUNT(ID)"] != "0") {
        echo "<a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probestring . $probestartstring . "&amp;probeav=1" . $probeclstring . $probeclstartstring . $proberefstring . $printstring . "#probeav\"><img src=\"" . $SITE_URL . "/images/row-expand.gif\" width=17 height=17 border=0 alt=\"Show available genetic elements\"></a> This person/group has made <b>" . $arrProbeav["COUNT(ID)"] . "</b> genetic elements available.  Click the green arrow to the left to view them.<br>\n";
      }
    }
////////////////////////////  Clone Library Code ///////////////////////////////////////////

 if($_GET["probecl"] == "1") {
      $probecl_query = "SELECT name,id from CLONE_LIBRARY where MADE_BY = '" . $arrRecord["ID"] . "'"; //16906';"SELECT name,type,id from PROBE where rowid in (SELECT rowid FROM probe WHERE AVAILABLE_FROM = " . $arrRecord["ID"] . " AND rownum <= " . $probeavend . " MINUS SELECT rowid FROM probe WHERE AVAILABLE_FROM = " . $arrRecord["ID"] . " AND rownum < " . $probeavstart . ") ORDER BY name";
      $statement_probecl = @OCIParse($DBConn,$probecl_query);
      @OCIExecute($statement_probecl,OCI_DEFAULT);
      @OCIFetchInto($statement_probecl,&$arrprobecl,OCI_ASSOC+OCI_RETURN_NULLS);
      if(strlen($arrprobecl["NAME"]) > 0) {
        echo "<a name=\"probecl\"></a><b><a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probestring . $probestartstring . $probeavstring . $probeavstartstring . $proberefstring . $printstring . "\"><img src=\"" . $SITE_URL . "/images/row-contract.gif\" width=17 height=17 border=0 alt=\"Hide created genetic elements\"></a> Clone Libraries available from this person/organization:</b><br>";
        echo "<table summary=\"A table containing all the clone libraries available from this person\" width=\"50%\"><tr><td width=\"15%\" valign=top>\n";

        $tmpprobeid = $arrprobecl["ID"];
        $tmpprobename = $arrprobecl["NAME"];
        echo "&nbsp;&nbsp;&nbsp;&nbsp;<a href=\"displaycl";
        echo "record.cgi?id=" . $tmpprobeid . "\">" . $tmpprobename . "</a> ";
         echo "(Clone Library)";
        echo "<br>\n";
        $rowcount = 1;
        $reccnt = 1;
		
	
		     while(@OCIFetchInto($statement_probecl,&$arrprobecl,OCI_ASSOC+OCI_RETURN_NULLS)) {
          
              echo "&nbsp;&nbsp;&nbsp;&nbsp;<a href=\"display";
              echo "cl";
              echo "record.cgi?id=" . $arrprobecl["ID"] . "\">" . $arrprobecl["NAME"] . "</a> ";
              echo "(Clone Library)";

              echo "<br>\n";
           
			}
      
          echo "</td></tr></table>";
        echo "<p>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;You are viewing clone libraries <b>" . "1" . "</b> through <b>";
        $total_probecl_count_query = "SELECT count(id) from CLONE_LIBRARY where MADE_BY = " . $arrRecord["ID"];
        $total_probecl_count_statement = @OCIParse($DBConn,$total_probecl_count_query);
        @OCIExecute($total_probecl_count_statement,OCI_DEFAULT);
        @OCIFetchInto($total_probecl_count_statement,&$arrprobeclCount,OCI_ASSOC+OCI_RETURN_NULLS);
        echo $arrprobeclCount["COUNT(ID)"] . "</b> " . "of a total <b>" . $arrprobeclCount["COUNT(ID)"] . "</b> clone libraries available from this person/organization.</p>\n";
       
	   }
    }
    else {
      $probecl_query = "SELECT count(id) from CLONE_LIBRARY where MADE_BY = " . $arrRecord["ID"] . " ORDER BY name";
      $statement_probecl = @OCIParse($DBConn,$probecl_query);
      @OCIExecute($statement_probecl,OCI_DEFAULT);
      @OCIFetchInto($statement_probecl,&$arrprobecl,OCI_ASSOC+OCI_RETURN_NULLS);
      if($arrprobecl["COUNT(ID)"] != "0") {
        echo "<a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probestring . $probestartstring . "&amp;probecl=1" . $proberefstring . $probeclstring . $probeclstartstring .$printstring . "#probecl\"><img src=\"" . $SITE_URL . "/images/row-expand.gif\" width=17 height=17 border=0 alt=\"Show available genetic elements\"></a> This person/group has <b>" . $arrprobecl["COUNT(ID)"] . "</b> clone libraries available.  Click the green arrow to the left to view them.<br>\n";
      }
    }

//////////////////////////// End Clone Lib Code ///////////////////////////////////////////
	
	
    echo "</p>";

    $query_comments = "SELECT MEMO FROM MEMO WHERE ID = " . $id;
    $statement_comments = @OCIParse($DBConn,$query_comments);
    @OCIExecute($statement_comments,OCI_DEFAULT);
    @OCIFetchInto($statement_comments,&$arrComments, OCI_ASSOC+OCI_RETURN_NULLS);

    if(strlen($arrComments["MEMO"]) > 1) {
      echo "<p><b>Comments:</b></p>\n";
      echo "<p>" . $arrComments["MEMO"] . "</p>\n";
      while(@OCIFetchInto($statement_comments,&$arrComments, OCI_ASSOC+OCI_RETURN_NULLS))
        echo "<p>" . $arrComments["MEMO"] . "</p>\n";
    }

    $query_find_user_annotations = "SELECT A.AUTO_NUM, A.MEMO, A.MOD_DATE, B.ID, B.FIRST_NAME, B.LAST_NAME, B.USERNAME, B.PASSWORD FROM ANNOTATION A, ANNOTATION_AUTHOR B WHERE A.ANN_AUTHOR_ID = B.ID AND A.ID = " . $id . " AND B.CURATION_LVL < 2 AND A.CURATION_LVL < 2 ORDER BY A.MOD_DATE";
    $stmt_user_annotations = make_query($DBConn,$query_find_user_annotations,5);
    $arrAnnotations = retrieve_row($stmt_user_annotations);
    if(strlen($arrAnnotations["MEMO"]) > 0)
    {
      echo "<p><b>User Annotations:</b></p>\n";
      echo "<p><b><a href=\"displayannotatorrecord.cgi?id=" . $arrAnnotations["ID"] . "\">" . trim($arrAnnotations["FIRST_NAME"]) . " " . trim($arrAnnotations["LAST_NAME"]) . "</a></b> (<i>" . $arrAnnotations["MOD_DATE"] . "</i>)<br>\n";
      echo "<span style=\"margin-left: 10px;\">" . mgdb_safe_html($arrAnnotations["MEMO"]) . "</span>\n";

      if(($arrAnnotations["ID"] == $userid) && ($arrAnnotations["USERNAME"] == $username) && ($arrAnnotations["PASSWORD"] == $password))
        echo "<br><i><a target=\"new\" href=\"edit_annotation.cgi?id=" . $arrAnnotations["AUTO_NUM"] . "\">Edit this annotation!</a></i>";
      echo "</p>\n";
      $arrAnnotations = retrieve_row($stmt_user_annotations);
      while(strlen($arrAnnotations["MEMO"]) > 0)
      {
        echo "<p><b><a href=\"displayannotatorrecord.cgi?id=" . $arrAnnotations["ID"] . "\">" . trim($arrAnnotations["FIRST_NAME"]) . " " . trim($arrAnnotations["LAST_NAME"]) . "</a></b> (<i>" . $arrAnnotations["MOD_DATE"] . "</i>)<br>\n";
        echo "<span style=\"margin-left: 10px;\">" . mgdb_safe_html($arrAnnotations["MEMO"]) . "</span>\n";
        if(($arrAnnotations["ID"] == $userid) && ($arrAnnotations["USERNAME"] == $username) && ($arrAnnotations["PASSWORD"] == $password))
          echo "<br><i><a target=\"new\" href=\"edit_annotation.cgi?id=" . $arrAnnotations["AUTO_NUM"] . "\">Edit this annotation!</a></i>";
        echo "</p>\n";
        $arrAnnotations = retrieve_row($stmt_user_annotations);
      }
    }

    echo "<p></p>";

    echo "<form action=\"" . $SITE_URL . "/cgi-bin/displaypersonresults.cgi\" method=\"get\">\n";
    echo "<label for=\"mini\">You can try another search through the person/organization data...</label><br>\n";
    $search_term = $arrRecord["NAME_LAST"] . " " . $arrRecord["NAME_FIRST"];
    echo "<input type=\"text\" name=\"term\" id=\"mini\" value=\"" . $search_term . "\" size=\"20\">\n";
    echo "<input type=\"submit\" value=\"submit\" id=\"minisub\">\n";
    echo "<br></form>\n";
	if($_GET["print"] == "1")
      echo "<p><a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probestring . $probestartstring . $probeclstring . $probeclstartstring . $probeavstring . $proberefstring . "\">See record in normal MaizeGDB format</a></p>\n";
    else
      echo "<p><a href=\"displaypersonrecord.cgi?id=" . $arrRecord["ID"] . $probestring . $probestartstring . $probeclstring . $probeclstartstring . $probeavstring . $proberefstring . "&amp;print=1\">See record in a printer-friendly format</a></p>\n";

  

    echo "<p><a href=\"" . $SITE_URL . "\">Return to the homepage</a></p>\n";
	
	}
	//////////////////////
	////////////////////
	/////////////////////

    
    echo "</td>\n";
    if(strlen($printstring) > 0) 
      echo "\n";
    else {
      echo "<td width=\"240\" class=\"small\" style=\"border: 1px dashed #3f3;\" bgcolor=\"#ccff99\" valign=top>\n";
      echo "<p style=\"margin-bottom: 8px;\"><span style=\"font-family: Verdana, Arial, sans-serif; font-size: 110%; font-weight: bold; margin-bottom: 6px;\">$title Links</span></p>\n";
      echo "<p>Looking for more information about $title?  Here are some additional related resources.</p>";
      echo "<p><a title=\"Clicking here will search Google for $title\" href=\"http://www.google.com/search?q=$title\">Search <b>Google</b> for $title</a><br>\n";
      echo "<a title=\"Clicking here will search through the newsgroups archived at Google Groups for $title.\" href=\"http://groups.google.com/groups?q=$title\">Search <b>Usenet</b> for $title</a><br>\n";
      if((strlen($arrRecord["NAME_FIRST"]) > 0) && (strlen($arrRecord["NAME_LAST"]) > 0)) 
        echo "<a title=\"Clicking here will do a relevant PubMed search for $title\" href=\"http://www.ncbi.nlm.nih.gov/entrez/query.fcgi?db=pubmed&cmd=search&term=" . $arrRecord["NAME_LAST"] . " " . substr($arrRecord["NAME_FIRST"],0,1) . "\">Search <b>PubMed</b> for $title</a><br>\n";
      echo "</td>\n";
    }
    echo "</tr></table>\n";
  }
  else {
    //echo page_header_new($title,$title,$username,$password,$userid);
    echo "<table width=\"100%\" cellpadding=5 cellspacing=5><tr><td valign=top><p style=\"margin-bottom: 8px;\"><span style=\"font-family: Verdana, Arial, sans-serif; font-size: 120%; font-weight: bold; margin-bottom: 6px;\">";
    echo "No Matching Record</span></p><p>We found no record matching your criteria.</p>";
    echo "<form style=\"padding-top: 4px;\" action=\"" . $SITE_URL . "/cgi-bin/displaypersonresults.cgi\" method=\"get\">\n";
    echo "<label for=\"mini\">You can try another search through the person/organization data...</label><br>\n";
    if(strlen($_GET["term"]) > 0)
      $search_term = stripslashes($_GET["term"]);
    else
      $search_term = "Enter search terms here.";
    echo "<input type=\"text\" name=\"term\" id=\"mini\" value=\"" . $search_term . "\" size=\"20\" onClick=\"document.getElementById('mini').value='';\">\n";
    echo "<input type=\"submit\" value=\"submit\" id=\"minisub\">\n";
    echo "<br><label for=\"minisub\">... or <a href=\"" . $SITE_URL . "/cgi-bin/personbrowser.cgi\">browse through all of the person/organization records</a></label>.</form>\n";
    echo "<p><a href=\"" . $SITE_URL . "/\">Return to the homepage</a></p>\n";
    echo "</td></tr></table>";
  }
  //echo page_footer($title,$uri);
 }
 else {
  sleep(15);
  echo "<html><head><title>Access Denied!</title></head><body><h1>Access Denied!</h1><p>If you would like access to this record, please email <a href=\"mailto:maizegdb_support@iastate.edu\">maizegdb_support@iastate.edu</a> with your request.</p></body></html>";
 }
?>

</script>

<script type="text/javascript">

function showDetail()
{

	var name1 = "id1";
	var name2 = "id2";
	var name3 = "id3";
	ele1 = document.getElementById(name1);
	ele2 = document.getElementById(name2);
	ele3 = document.getElementById(name3);
	if(ele2.style.display == "inline")
	   {
	     ele1.style.display = "inline";
	     ele2.style.display = "none";
	     ele3.style.display = "inline";
		 document.getElementById('frameid').src=document.getElementById('frameid').src;
	     }
	else 
	   {
	     ele1.style.display = "none";
	     ele2.style.display = "inline";
	     ele3.style.display = "none";
	     //document.getElementById(name1).src=document.getElementById(name1).src; 
	   }
}
</script>

<script type="text/javascript">
  
  function showReview(id)
  {
	if(id == 1)
	{
		document.getElementById('review1').innerHTML = "(<a href='javascript:showReview(2)'>Hide the reviews</a>)";
  document.getElementById('review2').style.display="inline";
	} else {
		document.getElementById('review1').innerHTML = "(<a href='javascript:showReview(1)'>See the reviews</a>)";
		document.getElementById('review2').style.display = "none";
	}
    }
</script>

