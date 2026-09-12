<?PHP
/* file: bin_viewer_locus_sequence.php
 *
 * purpose: MAY NOT BE IN USE
 *
 * test URL: /data_center/qtl-loci-summary
 *
 * history:
 *  01/22/14  jportwood - creating initial page
 *  2026-09-09  Fixed the fatal on a request without a usable bin, and the
 *      reflected parameters behind it.
 *
 *      $binviewer is only assigned inside the bin test below, so anything that
 *      did not carry a bin in 1-10 -- the bare /bin_viewer_locus_sequence
 *      among them -- reached ->get('bin') with null and died on `Call to a
 *      member function get() on null`. The page has no meaning without a bin
 *      and every link into it comes from the bin viewer itself, so a request
 *      that cannot name one now goes back to that tool.
 *
 *      The validation earns its place twice. $bin and $sub are also
 *      interpolated unescaped into $sort_msg -- into its prose and into the
 *      href of its "Return to the full view" link -- and getCGIParam() does no
 *      escaping at all (include/gp_lib.php:181 is trim() and nothing else), so
 *      ?bin=<payload> was reflected straight into the page. Past this guard
 *      both are integers.
 */
 

function return_valid_bin_number($major,$minor)
  {
    $flush = settype($major,"integer");
    $flush = settype($minor,"integer");
    if(($minor > -1) && ($minor < 7) && ($major > 0) && ($major < 11))
    {
      $minor_divided = $minor / 100;
      $major = $major + $minor_divided;
      return $major;
    }
    else if(($minor > -1) && ($minor < 8) && ($major > 0) && ($major < 11) && ($major != 7))
    {
      $minor_divided = $minor / 100;
      $major = $major + $minor_divided;
      return $major;
    }
    else if(($minor > -1) && ($minor < 9) && ($major > 0) && ($major < 10) && ($major != 7))
    {
      $minor_divided = $minor / 100;
      $major = $major + $minor_divided;
      return $major;
    }
    else if(($minor > -1) && ($minor < 10) && ($major > 0) && ($major < 9) && ($major != 7) && ($major != 6))
    {
      $minor_divided = $minor / 100;
      $major = $major + $minor_divided;
      return $major;
    }
    else if(($minor > -1) && ($minor < 11) && ($major > 0) && ($major < 5))
    {
      $minor_divided = $minor / 100;
      $major = $major + $minor_divided;
      return $major;
    }
    else if(($minor > -1) && ($minor < 12) && (($major == 1) || ($major == 4)))
    {
      $minor_divided = $minor / 100;
      $major = $major + $minor_divided;
      return $major;
    }
    else if(($minor == 12) && ($major == 1))
      return 1.12;
    else
      return 0;
  }

  function make_display_name($bin,$sub,$separator)
  {
    $flush = settype($bin,"string");
    $return_value = $bin . $separator;
    /* if($sub < 10)
      $return_value = $return_value . "0"; */
    $flush = settype($sub,"string");
    $return_value = $return_value . $sub;
    return $return_value;
  }

$bin = getCGIParam('bin', 'G', false);
  $sub = getCGIParam('sub', 'G', false);
  $chrom = getCGIParam('chrom', 'G', false);
  $fullbin = getCGIParam('fullbin', 'G', false);	// RI-854
  

 /* Chromosomes are 1-10; the largest region any chromosome has is 1.12, which
    is why sub is allowed up to 12 here. return_valid_bin_number() below still
    decides whether this particular bin/sub pair exists -- a plausible but
    absent one such as 7.09 keeps its long-standing behaviour of rendering an
    empty table rather than being turned away. */
 /* Digits then range, rather than FILTER_VALIDATE_INT: the filter rejects a
    leading zero, and sub is written zero-padded in several places -- the URL
    this file's own $sort_msg documents is bin_viewer?bin=N&sub=NN, and
    bin_viewer_modern.php:236 str_pads it. ?bin=1&sub=00 has always been a
    valid address for bin 1.00 and has to stay one. -1 is the sentinel because
    0 is a real sub. */
 $bin_ok = preg_match('/^\d{1,2}$/', (string) $bin) ? (int) $bin : -1;
 $sub_ok = preg_match('/^\d{1,2}$/', (string) $sub) ? (int) $sub : -1;
 if ($bin_ok < 1 || $bin_ok > 10 || $sub_ok < 0 || $sub_ok > 12) {
   header('Location: /bin_viewer', true, 302);
   exit;
 }
 $bin = $bin_ok;
 $sub = $sub_ok;

 if ($bin >= 1 && $bin <= 10)		// RI-1070
  {//load bin viewer sections page based on selected bin
    //$mgdb->get('body')->load('templates/tools/bin_view.bau');
    $binviewer = $mgdb->get('body')->load('templates/tools/bin_viewer_locus_sequences_search.bau');
    $title= "Chromosome $bin, Region $sub ";
    if ($sub < 10)
     $sub = str_pad($sub, 2, "0", STR_PAD_LEFT);
    
    $bin_num = $bin . '.' . $sub;
    $title .= "(Bin $bin_num)";
    
    //$mgdb->get('body')->get('title')->replace($title);

  }
	
	$binviewer->get('bin')->replace($bin);
	$binviewer->get('sub')->replace($sub);
	$binviewer->get('display')->replace(make_display_name($bin,$sub,"."));
	
  
  $bin_value = return_valid_bin_number($bin,$sub);

$DBConn = connect_to_database();
$sort_msg = "";
$bin_display_name = make_display_name($bin,$sub,".");
$query_loci = "
  SELECT DISTINCT(A.ID), A.NAME, A.FULL_NAME, A.TYPE, A.ARM, C.BIN 
   from locus a 
     left outer join id_num b on b.id = a.id 
     left outer join locus_coordinates c on a.id = c.id 
   where a.type = 25396";
$sortby = getCGIParam("sort", "GP", false);
$sortby_suffix = "";
$list = "";  
//$list = getCGIParam("qtl_loci_str".$sortby, "S", false);
if (!$list) {
  /* if($sortby == "locus_ascend")
  {
    $query_loci = $query_loci . " order by a.name";
    $sort_msg =  "The loci are sorted by their <b>name</b> in <b>ascending order</b>.";
  }
  else if($sortby == "locus_descend")
  {
    $query_loci = $query_loci . " order by a.name desc";
    $sort_msg =  "The loci are sorted by their <b>name</b> in <b>descending order</b>.";
  }
  else if($sortby == "desc_ascend")
  {
    $query_loci = $query_loci . " order by a.full_name";
    $sort_msg =  "The loci are sorted by their <b>full name</b> in <b>ascending order</b>.";
  }
  else if($sortby == "desc_descend")
  {
    $query_loci = $query_loci . " order by a.full_name desc";
    $sort_msg =  "The loci are sorted by their <b>full name</b> in <b>descending order</b>.";
  }
  else if($sortby == "mapped_ascend")
  {
    $query_loci = "SELECT DISTINCT(A.ID), A.VALUE, B.NAME, B.FULL_NAME, B.TYPE, B.ARM, C.BIN FROM (SELECT MAX(B.ID) AS VALUE, A.ID FROM LOCUS A LEFT OUTER JOIN LOCUS_COORDINATES B ON A.ID = B.ID WHERE A.TYPE = 25396 GROUP BY A.ID ORDER BY MAX(B.VALUE)) A LEFT OUTER JOIN LOCUS B ON A.ID = B.ID LEFT OUTER JOIN LOCUS_COORDINATES C ON A.ID = C.ID ORDER BY A.VALUE, B.NAME";
    $sort_msg =  "The loci are sorted by whether they are <b>mapped</b>, with those that <b>are mapped coming first</b>.";
  }
  else if($sortby == "mapped_descend")
  {
    $query_loci = "SELECT DISTINCT(A.ID), A.VALUE, B.NAME, B.FULL_NAME, B.TYPE, B.ARM, C.BIN FROM (SELECT MAX(B.ID) AS VALUE, A.ID FROM LOCUS A LEFT OUTER JOIN LOCUS_COORDINATES B ON A.ID = B.ID WHERE A.TYPE = 25396 GROUP BY A.ID ORDER BY MAX(B.VALUE)) A LEFT OUTER JOIN LOCUS B ON A.ID = B.ID LEFT OUTER JOIN LOCUS_COORDINATES C ON A.ID = C.ID ORDER BY A.VALUE DESC, B.NAME";
    $sort_msg =  "The loci are sorted by whether they are <b>mapped</b>, with those that <b>are not mapped coming first</b>.";
  }
  else if($sortby == "bin_ascend")
  {
    $query_loci = $query_loci . " order by c.bin, a.name";
    $sort_msg =  "The loci are sorted by their <b>bin location</b> in <b>ascending order</b>.";
  }
  else if($sortby == "bin_descend")
  {
    $query_loci = $query_loci . " order by c.bin desc, a.name";
    $sort_msg =  "The loci are sorted by their <b>bin location</b> in <b>descending order</b>.";
  }
  else if($sortby == "arm_ascend")
  {
    $query_loci = $query_loci . " order by a.arm, a.name";
    $sort_msg =  "The loci are sorted by <b>arm</b> in <b>ascending order</b>.";
  }
  else if($sortby == "arm_descend")
  {
    $query_loci = $query_loci . " order by a.arm desc, a.name";
    $sort_msg =  "The loci are sorted by <b>arm</b> in <b>descending order</b>.";
  }
  else if($sortby == "mgdb_ascend")
  {
    $query_loci = $query_loci . " order by a.id";
    $sort_msg =  "The loci are sorted by <b>ID number</b> in <b>ascending order</b>.";
  }
  else if($sortby == "mgdb_descend")
  {
    $query_loci = $query_loci . " order by a.id desc";
    $sort_msg =  "The loci are sorted by <b>ID number</b> in <b>descending order</b>.";
  }
  else
  {
    $query_loci = $query_loci . " order by a.name";
    $sort_msg =  "The loci are sorted by <b>name</b> in <b>ascending order</b>.";
  } */
  
  $sort_msg = "<p>This page identifies map locations of sequences found in bin " . $bin_display_name . " in maize, listing map name, coordinate, accession number, any contigs this accession might be a part of, and locus name.<br><a href=\"bin_viewer?bin=" . $bin . "&amp;sub=" . $sub . "\">Return to the full view of Chromosome " . $bin . ", Region " . $sub . " (bin " . $bin_display_name . ")</a></p>";
  $query_loci = "SELECT B.GENBANK_ACC, B.SEQ_ID, B.SEQ_TITLE, B.SEQ_TYPE, C.VALUE, D.ID AS LOCUS_ID, D.NAME AS LOCUS_NAME, D.FULL_NAME AS LOCUS_FULL_NAME, E.ID AS MAP_ID, E.NAME AS MAP_NAME, F.TUC_ID FROM ID_SEQ A JOIN Z_SEQUENCE B ON A.SEQ = B.SEQ_ID JOIN LOCUS_COORDINATES C ON A.ID = C.ID LEFT OUTER JOIN LOCUS D ON C.ID = D.ID LEFT OUTER JOIN MAP E ON C.MAP = E.ID LEFT OUTER JOIN Z_TUC_EST F ON B.SEQ_ID = F.EST_GI where (c.bin = " . $bin_value . " or c.bin2 = " . $bin_value . " or (c.bin < " . $bin_value . " and c.bin2 > " . $bin_value . ")) ORDER BY LOWER(E.NAME), B.GENBANK_ACC";
  
  $statement_loci = make_query($DBConn,$query_loci);
  $count = 0;
  $bgcolor = "";
  
  //jp note - breaking the HTML rule because it runs considerably faster this way than when looping the data via bauplan
  $sort = '<a href="/data_center/qtl-loci-summary?sort=';
  $img_ascend = "<img src='/images/collapse.png'>";
  $img_descend = "<img src='/images/expand.png'>";
  $list = ' <table style="width: 100%" cellpadding="0" cellspacing="0">
   <tr>
    <th style="text-align: left">  Map </th>
    <th style="text-align: left">  Coordinate </th>
    <th style="text-align: left">  Accession # </th>
    <th style="text-align: left">  Contig </th>
    <th style="text-align: left">  Locus </th>
    <!--<th style="text-align: left">  ' . $sort . 'mgdb_ascend">'.$img_ascend.'</a> MaizeDB / MGDB ID ' .$sort.'mgdb_descend">'.$img_descend.'</a></th>-->
   </tr>';
   
  while($arrLoci = retrieve_row($statement_loci))
  {
    $list .= '
      <tr style="background-color: ';
    if ($count % 2 == 0)
      $list .= '#FFFFFF;';
    //<td><b><a href="displaymapwithaccessions.cgi?id='.$arrLoci["MAP_ID"].'"> '.trim($arrLoci["MAP_NAME"]).' </a></td>
    $list .= '">
    <td><b><a href="data_center/map/?id='.$arrLoci["MAP_ID"].'"> '.trim($arrLoci["MAP_NAME"]).' </a></td>
    <td> '.$arrLoci["VALUE"].' </td>
    <td> ';
    
    /* if ($arrLoci["bin"] > 0){ 
      $list .= ' Yes ';
    }
    else
      $list .= ' No '; */
    $list .= $arrLoci["SEQ_TYPE"] . " <a href=\"data_center/sequence?id=" . $arrLoci["SEQ_ID"] . "\" title=\"" . $arrLoci["SEQ_TITLE"] . "\">" . $arrLoci["GENBANK_ACC"] . "</a>";
    $list .= '</td><td> '; 
    if (strlen($arrLoci["TUC_ID"]) > 0){ 
      //$list .= "<a href=\"data_center/sequence?id=" . $arrLoci["TUC_ID"] . "\">" . $arrLoci["TUC_ID"] . "</a>";
      //jp note - sequence links when the id is a contig currently do not work
      $list .= $arrLoci["TUC_ID"];
    }
    else
      $list .= '&nbsp;';  
    
    $list .= 
     '</td>
    <td> ' . "<a href=\"data_center/locus/" . $arrLoci["LOCUS_ID"] . "\">" . $arrLoci["LOCUS_NAME"];
	if(strlen($arrLoci["LOCUS_FULL_NAME"]) > 0) 
		$list .= " <i>" . $arrLoci["LOCUS_FULL_NAME"] . "</i>";
	$list .= "</a></td>";
	$list .=' </td>
    <!--<td> '.$arrLoci["seq_id"].' </td>-->
   </tr>';
      $count++;
  }//while
  $list .= "</table>";

  //setSessionVar("qtl_loci_str".$sortby, $list); 
}
 
 $binviewer->get('qtl_table_summary_str')->replace($list);
 $binviewer->get('sorted_by')->replace($sort_msg);
  
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
