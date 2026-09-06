<?PHP
/* function: api_tools.php
 *
 * purpose:
 *
 * history:
 *   09/13/12  eksc  converted for redesign
 *   07/31/13 jp - added search_id function (same functionality as search_id.cgi)
 */
 
  /***display_comments() should be replaced with get_comments below!***/
  function display_comments($DBConn, $id) {
    $query_comments = "
      SELECT DISTINCT(MEMO),ORDER1 FROM MEMO WHERE ID = " . (int) $id . " ORDER BY ORDER1";
    $stmt_comments = make_query($DBConn, $query_comments);
    $arrComments = retrieve_row($stmt_comments);
    if (strlen($arrComments["MEMO"]) > 0) {
      echo "<br>\n &nbsp;&nbsp;&nbsp;" . $arrComments["MEMO"];
      $arrComments = retrieve_row($stmt_comments);
      while (strlen($arrComments["MEMO"]) > 0) {
        echo "<br>\n&nbsp;&nbsp;&nbsp;" . $arrComments["MEMO"];
        $arrComments = retrieve_row($stmt_comments);
      }
    }
  }//display_comments
  
  
  function get_comments($DBConn, $id) {
    $comments = '';
    $query_comments = "
      SELECT DISTINCT(MEMO),ORDER1 FROM MEMO WHERE ID = " . (int) $id . " ORDER BY ORDER1";
    $stmt_comments = make_query($DBConn, $query_comments);
    while ($arrComments = retrieve_row($stmt_comments)) {
      $comments .= "<br>\n&nbsp;&nbsp;&nbsp;" . mgdb_safe_html($arrComments["MEMO"]);
    }
    
    return $comments;
  }//get_comments
  
  
  function fix_map_name($map_name) {
    $map_name = trim($map_name);
    $string_length = strlen($map_name);
    $string_prefix = substr($map_name,0,($string_length-2));
    $string_char_to_check = substr($map_name,-2,1);
    $string_suffix = substr($map_name,-1,1);
    if ($string_char_to_check == "0")
      $result_string = $string_prefix . $string_suffix;
    else
      $result_string = $string_prefix . $string_char_to_check . $string_suffix;
    return $result_string;
  }//fix_map_name
  

  function lookuparm($var1) {
    if($var1 == "109667")
      return "centromere";
    else if($var1 == "32021")
      return "L (long arm)";
    else if($var1 == "32022")
      return "S (short arm)";
    else if($var1 == "0")
      return "no arm";
  }

  function flis($id)
  {
    $flis = "";
    if(($id == 41513) || ($id == 44534))
      $flis = "AY751079";
    if(($id == 97241) || ($id == 86532))
      $flis = "AY771210";
    if(($id == 41507) || ($id == 44573))
      $flis = "AY771211";
    if(($id == 97250) || ($id == 86551))
      $flis = "DQ001865";
    if(($id == 59575) || ($id == 44599))
      $flis = "AY771212";
    if(($id == 41487) || ($id == 44516))
      $flis = "AY771214";
    if(($id == 41498) || ($id == 44532))
      $flis = "AY771213";
    if(($id == 41461) || ($id == 44512))
      $flis = "DQ001866";
    if(($id == 41400) || ($id == 44574))
      $flis = "AY771215";
    if(($id == 41496) || ($id == 44595))
      $flis = "AY771216";
    if(($id == 41458) || ($id == 44508))
      $flis = "DQ001867";
    if(($id == 97284) || ($id == 86509))
      $flis = "AY771217";
    if(($id == 97287) || ($id == 86535))
      $flis = "DQ001868";
    if(($id == 41362) || ($id == 44552))
      $flis = "DQ005498";
    if(($id == 41438) || ($id == 44587))
      $flis = "AY771218";
    if(($id == 57310) || ($id == 58190))
      $flis = "DQ005499";
    if(($id == 40975) || ($id == 44340))
      $flis = "DQ007988";
    if(($id == 41420) || ($id == 44683))
      $flis = "AY771219";
    if(($id == 41505) || ($id == 44558))
      $flis = "DQ007989";
    if(($id == 57339) || ($id == 57339))
      $flis = "DQ007990";
    if(($id == 56925) || ($id == 98225))
      $flis = "DQ007991";
    if(($id == 97332) || ($id == 58349))
      $flis = "DQ015673";
    if(($id == 41392) || ($id == 44571))
      $flis = "AY771220";
    if(($id == 41120) || ($id == 44725))
      $flis = "AY771221";
    if(($id == 41520) || ($id == 44491))
      $flis = "AY772450";
    if(($id == 40977) || ($id == 44383))
      $flis = "DQ015674";
    if(($id == 41504) || ($id == 44578))
      $flis = "AY772451";
    if(($id == 41466) || ($id == 44493))
      $flis = "DQ059316";
    if(($id == 97371) || ($id == 86536))
      $flis = "DQ059317";
    if(($id == 97361) || ($id == 86222))
      $flis = "AY772452";
    if(($id == 41437) || ($id == 44555))
      $flis = "DQ059318";
    if(($id == 40864) || ($id == 44275))
      $flis = "DQ059319";
    if(($id == 41389) || ($id == 44569))
      $flis = "DQ059320";
    if(($id == 40994) || ($id == 44349))
      $flis = "AY772453";
    if(($id == 41373) || ($id == 44543))
      $flis = "AY772454";
    if(($id == 41535) || ($id == 44589))
      $flis = "AY772455";
    if(($id == 57434) || ($id == 44729))
      $flis = "DQ059321";
    if(($id == 41399) || ($id == 44593))
      $flis = "DQ059322";
    if(($id == 41475) || ($id == 44554))
      $flis = "AY772456";
    if(($id == 41422) || ($id == 44592))
      $flis = "DQ123890";
    if(($id == 64640) || ($id == 58021))
      $flis = "DQ123891";
    if(($id == 41395) || ($id == 44575))
      $flis = "DQ123892";
    if(($id == 97271) || ($id == 86233))
      $flis = "DQ123893";
    if(($id == 97276) || ($id == 86505))
      $flis = "DQ123894";
    if(($id == 41481) || ($id == 44486))
      $flis = "DQ123895";
    if(($id == 66779) || ($id == 58166))
      $flis = "DQ123896";
    if(($id == 41502) || ($id == 44528))
      $flis = "DQ123897";
    if(($id == 41456) || ($id == 44507))
      $flis = "DQ123898";
    if(($id == 41486) || ($id == 44505))
      $flis = "DQ123899";
    if(($id == 57341) || ($id == 58396))
      $flis = "DQ123900";
    if(($id == 41444) || ($id == 44499))
      $flis = "DQ123901";
    if(($id == 41401) || ($id == 44590))
      $flis = "DQ123902";
    if(($id == 40907) || ($id == 44318))
      $flis = "DQ123903";
    if(($id == 97409) || ($id == 86494))
      $flis = "DQ123904";
    if(($id == 97418) || ($id == 86237))
      $flis = "DQ123905";

    return $flis;
  }

  function coordfix($arg1) {
    if(strlen($arg1) == 0)
      return $arg1;
    else if(strlen($arg1) == 1)
      return $arg1 . ".00";
    else {
      return $arg1;
    }
  }

  function lookuplinkagegroup($var1) {
    if ($var1=="13579") return "1";
    else if ($var1=="13582") return "2";
    else if ($var1=="13585") return "3";
    else if ($var1=="13588") return "4";
    else if ($var1=="13591") return "5";
    else if ($var1=="13594") return "6";
    else if ($var1=="13597") return "7";
    else if ($var1=="13600") return "8";
    else if ($var1=="13603") return "9";
    else if ($var1=="13606") return "10";
    else if ($var1=="11064") return "B chromosome";
    else if ($var1=="250717") return "cytoplasmic";
    else if ($var1=="30036") return "mitochondrion";
    else if ($var1=="63982") return "mito mtNA188";
    else if ($var1=="50266") return "mito mtNB37";
    else if ($var1=="50543") return "mito mtT";
    else if ($var1=="493270") return "O. sativa 2";
    else if ($var1=="493272") return "O. sativa 3";
    else if ($var1=="493274") return "O. sativa 4";
    else if ($var1=="493276") return "O. sativa 5";
    else if ($var1=="493278") return "O. sativa 6";
    else if ($var1=="493280") return "O. sativa 7";
    else if ($var1=="493280") return "O. sativa 7";
    else if ($var1=="493282") return "O. sativa 8";
    else if ($var1=="493284") return "O. sativa 9";
    else if ($var1=="493286") return "O. sativa 10";
    else if ($var1=="493288") return "O. sativa 11";
    else if ($var1=="493290") return "O. sativa 12";
    else if ($var1=="493292") return "O. sativa 1";
    else if ($var1=="69238") return "plasmid S1, mitochondrial";
    else if ($var1=="69622") return "plasmid S2, mitochondrial";
    else if ($var1=="24818") return "plastid";
    else if ($var1=="78800") return "S2.3 linear plasmid";
    else if ($var1=="60649") return "Sb#M";
    else if ($var1=="60657") return "Sb#N";
    else if ($var1=="0") return "no linkage group";
    else return $var1;
  }


  function lookupspecies($var1) {
    $DBConn = connect_to_database();
    $query = "select species from species where id=" . (int) $var1;
    $sth = make_query($DBConn, $query);
    $row = retrieve_row($sth);
    
    return $row['species'];
  }//lookupspecies
  
  
  function person($DBConn, $id, $dom_id) {
    $output = "";
    $person_query = "
      SELECT NAME_FIRST, NAME_LAST, NAME, ID FROM PERSON WHERE ID = " . (int) $id;
    $stmt_person = make_query($DBConn,$person_query,1);
    $arrPerson = retrieve_row($stmt_person);
    if (strlen($arrPerson["ID"]) > 0) {
      $output .= " <a class=\"\" style=\"\" id=\"" 
               . $dom_id . "\" href=\"/tools/cluetip/person_summary.php?id=" 
               . $id . "\" rel=\"/tools/cluetip/person_summary.php?id=" . $id 
               . "\" title=\"Person Summary\">";
      if ((strlen($arrPerson["NAME_FIRST"]) > 0) 
            && (strlen($arrPerson["NAME_LAST"]) > 0)) {
        $output .=  trim($arrPerson["NAME_FIRST"]) . " " . trim($arrPerson["NAME_LAST"]) . "</a>";
      }
      else {
        $output .=  trim($arrPerson["NAME"]) . "</a>";
      }
    }
    
    return $output;
  }
  
?>