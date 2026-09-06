 <?PHP
  require("../../include/db-api.php");
  include_once("../../include/gp_lib.php");

  $system = getSystemInfo('mgdb.conf');

  $username = $_COOKIE["username"];
  $password = $_COOKIE["password"];
  $userid = $_COOKIE["userid"];

  $id = $_GET["id"];
  $dump = settype($id, "integer");
  $uri = $SITE_URL . "/cgi-bin/displaymprecord.cgi?" . $_SERVER['QUERY_STRING'];

  $username = $_COOKIE["username"];
  $password = $_COOKIE["password"];
  $userid = $_COOKIE["userid"];

  $DBConn = connect_to_database();
  $query = "SELECT rownum n from meta_path WHERE ID = " . (int) $id;
  $statement = make_query($DBConn,$query,1);
  $arrNum = retrieve_row($statement);
  $testcase = false;

  if($arrNum["N"] == 1) {
    $query2 = "SELECT curation_lvl from ID_NUM where ID = " . (int) $id;
    $stmt2 = make_query($DBConn,$query2,1);
    $arrPub = retrieve_row($stmt2);
    if($arrPub["CURATION_LVL"] == "0")
      $testcase = true;    
  }

  if($testcase) {
    $query = "SELECT * from meta_path where ID = " . (int) $id;
    $statement = make_query($DBConn,$query,1);
    $arrRecord = retrieve_row($statement);
    $title = "Metabolic Pathway " . $arrRecord["NAME"];
    //echo page_header_new($title,$title,$username,$password,$userid);
    echo "<table width=\"100%\" cellpadding=5 cellspacing=5><tr><td valign=top><p style=\"margin-bottom: 8px;\"><span style=\"font-family: Verdana, Arial, sans-serif; font-size: 120%; font-weight: bold; margin-bottom: 6px;\">";
    echo $arrRecord["NAME"] . " (<a href=\"" . $SITE_URL . "/metabolic_pathway.php\">metabolic pathway</a>)</span></p>\n";
    $query = "SELECT SYNONYMS from SYNONYMS where ID = " . (int) $id . " AND SYNONYMS NOT LIKE '" . str_replace("'","''",trim($arrRecord["NAME"])) . "'";
    $stmtsyn = make_query($DBConn,$query,1);
    $arrSyn = retrieve_row($stmtsyn);
    if(strlen($arrSyn["SYNONYMS"]) > 0) {
      echo "<p>This pathway is also known by the following names: ";
      echo "<b>" . $arrSyn["SYNONYMS"] . "</b>";
      $arrSyn = retrieve_row($stmtsyn);
      while(strlen($arrSyn["SYNONYMS"]) > 0) {
        echo ", <b>" . $arrSyn["SYNONYMS"] . "</b>";
        $arrSyn = retrieve_row($stmtsyn);
      }
      echo ".</p>";
    }

    if((strlen($username) > 0) && (strlen($password) > 0) && (strlen($userid) > 0))
      echo "<p><b><a href=\"create_annotation.cgi?id=" . $id . "\" target=\"new\">Add your own annotation to this record!</a></b></p>\n";

    echo "<p>";
    if(strlen($arrRecord["METABOLIC_PROCESS"]) > 0)
    {
      $query_process = "SELECT NAME, TERM_COMMENTS from TERM where ID = " . $arrRecord["METABOLIC_PROCESS"];
      $statement_process = make_query($DBConn,$query_process,1);
      $arrProcess = retrieve_row($statement_process);
      if(strlen($arrProcess["TERM_COMMENTS"]) > 0)
        echo "<b>Metabolic Process:</b> <acronym title=\"" . trim($arrProcess["TERM_COMMENTS"]) . "\">" . trim($arrProcess["NAME"]) . "</acronym><br>\n";
      else
        echo "<b>Metabolic Process:</b> " . $arrProcess["NAME"] . "<br>\n";
    }

    echo "</p>";

    if(strlen($arrRecord["SUMMARY_REACTION"]) > 0)
    {
      $query_summary_reaction = "SELECT A.NAME, A.ID FROM REACTION A, ID_NUM B WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND A.ID = " . $arrRecord["SUMMARY_REACTION"];
      $statement_summary_reaction = make_query($DBConn,$query_summary_reaction,1);
      $arrSummaryReaction = retrieve_row($statement_summary_reaction);
      if(strlen($arrSummaryReaction["ID"]) > 0)
        echo "<p><b>Summary Reaction:</b><br><a href=\"displayreactionrecord.cgi?id=" . $arrSummaryReaction["ID"] . "\">" . trim($arrSummaryReaction["NAME"]) . "</a></p>\n";
    }

    $query_reaction_steps = "SELECT A.NAME, A.ID, B.STEP_NUM from ENZ_CAT_REACTION A, META_PATH_STEPS B, ID_NUM C WHERE B.STEP_ENZYME = A.ID AND A.ID = C.ID AND C.CURATION_LVL = 0 AND B.ID = " . (int) $id . " ORDER BY B.STEP_NUM";
    $statement_reaction_steps = make_query($DBConn,$query_reaction_steps,5);
    $arrSteps = retrieve_row($statement_reaction_steps);
    $count_step = 0;
    while(strlen($arrSteps["NAME"]) > 0)
    {
      if($count_step == 0)
      { $count_step = 1;
        echo "<p><b>Reaction Steps:</b><br>\n";
      }
      echo $arrSteps["STEP_NUM"] . ". <a href=\"displayecrrecord.cgi?id=" . $arrSteps["ID"] . "\">" . $arrSteps["NAME"] . "</a><br>\n";
      $arrSteps = retrieve_row($statement_reaction_steps);
    }
    if($count_step == 1)
      echo "</p>\n";

    $query_gene_products = "SELECT B.ID, B.NAME FROM GENE_PROD_METABOLIC_PATHWAY A, GENE_PRODUCT B, ID_NUM C WHERE A.METABOLIC_PATHWAY = " . (int) $id . " AND A.ID = B.ID AND B.ID = C.ID AND C.CURATION_LVL = 0";
    $stmt_gp = make_query($DBConn,$query_gene_products,5);
    $arrGP = retrieve_row($stmt_gp);
    $gp = false;
    while(strlen($arrGP["NAME"]) > 0)
    {
      if(!($gp))
      {
        $gp = true;
        echo "<p><b>Related Gene Products:</b><br>\n";
      }
      echo "<a href=\"displaygprecord.cgi?id=" . $arrGP["ID"] . "\">" . trim($arrGP["NAME"]) . "</a><br>\n";
      $arrGP = retrieve_row($stmt_gp);
    }
    if($gp)
      echo "</p>";

    $query_phenotypes = "SELECT B.ID, B.NAME FROM PHENOTYPE_METABOLIC_PATHWAY A, PHENOTYPE B, ID_NUM C WHERE A.METABOLIC_PATHWAY = " . (int) $id . " AND A.ID = B.ID AND B.ID = C.ID AND C.CURATION_LVL = 0";
    $stmt_pheno = make_query($DBConn,$query_phenotypes,10);
    $arrPheno = retrieve_row($stmt_pheno);
    $pheno = false;
    while(strlen($arrPheno["NAME"]) > 0)
    {
      if(!($pheno))
      {
        $pheno = true;
        echo "<p><b>Related Phenotypes:</b><br>\n";
      }
      echo "<a href=\"displayphenorecord.cgi?id=" . $arrPheno["ID"] . "\">" . trim($arrPheno["NAME"]) . "</a><br>\n";
      $arrPheno = retrieve_row($stmt_pheno);
    }
    if($pheno)
      echo "</p>";

    $query_description = "SELECT DISTINCT(DESCRIPTION) FROM DESCRIPTION WHERE ID = " . (int) $id;
    $stmt_description = make_query($DBConn,$query_description,1);
    $arrDescription = retrieve_row($stmt_description);
    $count = 0;
    while(strlen($arrDescription["DESCRIPTION"]) > 0)
    {
      if($count == 0)
      {
        $count = 1;
        echo "<p><b>Description:</b>\n ";
      }
      else
        echo "; ";
      echo $arrDescription["DESCRIPTION"];
      $arrDescription = retrieve_row($stmt_description);
    }
    if ($count > 0)
      echo "</p>\n";

    $query = "SELECT MEMO from MEMO where ID = " . (int) $id;
    $stmt_memo = make_query($DBConn,$query,4);
    $arrComments = retrieve_row($stmt_memo);
    if(count($arrComments) > 0) {
      echo "<b><span title=\"Comments are additional notes relevant to this metabolic pathway record that might be of interest to you.\">Comments:</span></b><br>\n" . mgdb_safe_html($arrComments["MEMO"]) . "<br>";
      $arrComments = retrieve_row($stmt_memo);
      while(strlen($arrComments["MEMO"]) > 0) {
        echo trim($arrComments["MEMO"]) . "<br>\n";
        $arrComments = retrieve_row($stmt_memo);
      }
    }

    $query_related_articles = "SELECT A.CONTENTS, A.REFERENCE FROM ID_REFERENCE A, ID_NUM B WHERE A.REFERENCE = B.ID AND B.CURATION_LVL = 0 AND A.ID = " . (int) $id . " ORDER BY A.CONTENTS";
    $stmt_related_articles = make_query($DBConn,$query_related_articles,5);
    $arrRelatedArticles = retrieve_row($stmt_related_articles);
    $count = 0;
    while(strlen($arrRelatedArticles["REFERENCE"]) > 0)
    {
      $query_contents = "SELECT NAME FROM TERM WHERE ID = " . $arrRelatedArticles["CONTENTS"];
      $query_reference = "SELECT ID, NAME, TITLE FROM REFERENCE WHERE ID = " . $arrRelatedArticles["REFERENCE"];
      $stmt_contents = make_query($DBConn,$query_contents,1);
      $stmt_reference = make_query($DBConn,$query_reference,1);
      if($count == 0)
      {
        $count = 1;
        echo "<p><b>Related Papers:</b><br>\n";
      }
      $arrContents = retrieve_row($stmt_contents);
      $arrReference = retrieve_row($stmt_reference);
      if(strlen($arrContents["NAME"]) == 0)
        $arrContents["NAME"] = "general";
      echo "(<i>" . $arrContents["NAME"] . "</i>) <a href=\"displayrefrecord.cgi?id=" . $arrReference["ID"] . "\" title=\"" . addslashes($arrReference["TITLE"]) . "\">" . $arrReference["NAME"] . "</a><br>";
      $arrRelatedArticles = retrieve_row($stmt_related_articles);
    }

    if($count == 1)
      echo "</p>\n";

    $query_find_user_annotations = "SELECT A.AUTO_NUM, A.MEMO, A.MOD_DATE, B.ID, B.FIRST_NAME, B.LAST_NAME, B.USERNAME, B.PASSWORD FROM ANNOTATION A, ANNOTATION_AUTHOR B WHERE A.ANN_AUTHOR_ID = B.ID AND A.ID = " . (int) $id . " AND B.CURATION_LVL = 0 AND A.CURATION_LVL < 2 ORDER BY A.MOD_DATE";
    $stmt_user_annotations = make_query($DBConn,$query_find_user_annotations,5);
    $arrAnnotations = retrieve_row($stmt_user_annotations);
    if(strlen($arrAnnotations["MEMO"]) > 0)
    {
      echo "<p><b>User Annotations:</b></p>\n";
      echo "<p><b><a href=\"displayannotatorrecord.cgi?id=" . (int) $arrAnnotations["ID"] . "\">" . mgdb_html(trim($arrAnnotations["FIRST_NAME"])) . " " . mgdb_html(trim($arrAnnotations["LAST_NAME"])) . "</a></b> (<i>" . mgdb_html($arrAnnotations["MOD_DATE"]) . "</i>)<br>\n";
      echo "<span style=\"margin-left: 10px;\">" . mgdb_safe_html($arrAnnotations["MEMO"]) . "</span>\n";
      if(($arrAnnotations["ID"] == $userid) && ($arrAnnotations["USERNAME"] == $username) && ($arrAnnotations["PASSWORD"] == $password))
        echo "<br><i><a target=\"new\" href=\"edit_annotation.cgi?id=" . (int) $arrAnnotations["AUTO_NUM"] . "\">Edit this annotation!</a></i>";
      echo "</p>\n";
      $arrAnnotations = retrieve_row($stmt_user_annotations);
      while(strlen($arrAnnotations["MEMO"]) > 0)
      {
        echo "<p><b><a href=\"displayannotatorrecord.cgi?id=" . (int) $arrAnnotations["ID"] . "\">" . mgdb_html(trim($arrAnnotations["FIRST_NAME"])) . " " . mgdb_html(trim($arrAnnotations["LAST_NAME"])) . "</a></b> (<i>" . mgdb_html($arrAnnotations["MOD_DATE"]) . "</i>)<br>\n";
        echo "<span style=\"margin-left: 10px;\">" . mgdb_safe_html($arrAnnotations["MEMO"]) . "</span>\n";
        if(($arrAnnotations["ID"] == $userid) && ($arrAnnotations["USERNAME"] == $username) && ($arrAnnotations["PASSWORD"] == $password))
          echo "<br><i><a target=\"new\" href=\"edit_annotation.cgi?id=" . (int) $arrAnnotations["AUTO_NUM"] . "\">Edit this annotation!</a></i>";
        echo "</p>\n";
        $arrAnnotations = retrieve_row($stmt_user_annotations);
      }
    }

   /* echo "<form style=\"margin-top: 16px;\" action=\"" . $SITE_URL . "/cgi-bin/displaympresults.cgi\" method=\"get\">\n";
    echo "<label for=\"term11\">You can try another pathway search...</label><br><input type=\"text\" name=\"term\" id=\"term11\" value=\"" . $_GET["term"] . "\" size=\"20\">\n";
    echo "<input type=\"submit\" value=\"submit\">\n";
    echo "</form>\n";*/

    if($_GET["print"] == "1")
      echo "<p><a href=\"displaymprecord.cgi?id=" . $arrRecord["ID"] . "\">See record in normal MaizeGDB format</a></p>\n";
    else
      echo "<p><a href=\"displaymprecord.cgi?id=" . $arrRecord["ID"] . "&amp;print=1\">See record in a printer-friendly format</a></p>\n";

   // echo "<p><a href=\"" . $SITE_URL . "/\">Return to the homepage</a></p>\n";

    if($_GET["print"] != "1") {
      echo "</td>";
      echo "<td width=\"240\" class=\"small\" style=\"border: 1px dashed #3f3;\" bgcolor=\"#ccff99\" valign=top>\n";
      echo "<p style=\"margin-bottom: 8px;\"><span style=\"font-family: Verdana, Arial, sans-serif; font-size: 110%; font-weight: bold; margin-bottom: 6px;\">" . trim($arrRecord["NAME"]) . " Research Tools</span></p>";
      echo "<p>Here are some internet resources to aid your investigation into " . trim($arrRecord["NAME"]) . ".</p><p></p><p>";
      echo "<a title=\"Clicking here will search Google\" href=\"http://www.google.com/search?q=" . trim($arrRecord["NAME"]) . "\">Search Google for " . $arrRecord["NAME"] . "</a><br>";
      echo "<a title=\"Clicking here will search through the newsgroups archived at Google Groups.\" href=\"http://groups.google.com/groups?q=" . $arrRecord["NAME"] . "\">Search Usenet for " . trim($arrRecord["NAME"]) . "</a><br>";
      echo "</p>";
    }
    echo "</td></tr></table>";
  }
  else {
    $title = "Record not found - " . $id;
    //echo page_header_new($title,$title,$username,$password,$userid);
    echo "<p style=\"margin-bottom: 8px;\"><span style=\"font-family: Verdana, Arial, sans-serif; font-size: 120%; font-weight: bold; margin-bottom: 6px;\">Record Not Found!</span></p>\n";
    echo "<table width=\"100%\" cellpadding=5 cellspacing=5><tr><td valign=top>\n";
    echo "<p>The record you requested was not found in the database.  Please revise your query and try again.</p>";
    /*echo "<form action=\"" . $SITE_URL . "/cgi-bin/displaympresults.cgi\" method=\"get\">\n";
    echo "<input type=\"text\" name=\"term\" value=\"" . $_GET["term"] . "\" size=\"20\">\n";
    echo "<input type=\"submit\" value=\"submit\">\n";
    echo "</form>\n";*/
    //echo "<p><a href=\"" . $SITE_URL . "/\">Return to the homepage</a></p>\n";
    echo "</td></tr></table>";
  }
  //echo page_footer($title,$uri);
?>

