<?PHP
/* file: bin_viewer_locus_accession.php
 *
 * purpose: MAY NOT BE IN USE
 *
 * test URL: /data_center/qtl-loci-summary
 *
 * history:
 *  01/22/14  jportwood - creating initial page
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
    if($sub < 10)
      $return_value = $return_value . "0";
    $flush = settype($sub,"string");
    $return_value = $return_value . $sub;
    return $return_value;
  }
  
  $bin = $_GET["bin"];
  $dump = settype($bin, "integer");

  $sub = $_GET["sub"];
  $dump = settype($sub, "integer");

  $bin_value = return_valid_bin_number($bin,$sub);

/* $bin = getCGIParam('bin', 'G', false);
  $sub = getCGIParam('sub', 'G', false);
  $chrom = getCGIParam('chrom', 'G', false);
  $fullbin = getCGIParam('fullbin', 'G', false);	// RI-854 */
  
  
  $bin_display_name = make_display_name($bin,$sub,".");
    $bin_underbarred = make_display_name($bin,$sub,"_");
	$binviewer = $mgdb->get('body')->load('templates/tools/bin_viewer_locus_sequences_search.bau');
    $DBConn = connect_to_database();

  
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
  
  $sort_msg = "<p>This page identifies the mapped and sequence loci found in bin " . $bin_display_name . " in maize, listing locus name and accession number.<br><a href=\"bin_viewer?bin=" . $bin . "&amp;sub=" . $sub . "\">Return to the full view of Chromosome " . $bin . ", Region " . $sub . " (bin " . $bin_display_name . ")</a></p>";
  $query_loci = "select distinct(f.genbank_acc) as key, f.seq_id, f.seq_type, f.seq_title, g.id, g.name, g.full_name from locus_coordinates a, id_num b, locus_detected_by c, id_num d, id_seq e, z_sequence f, locus g where (a.bin = " . $bin_value . " or a.bin2 = " . $bin_value . " or (a.bin < " . $bin_value . " and a.bin2 > " . $bin_value . ")) and a.id = b.id and b.curation_lvl = 0 and a.id = c.id and c.probe_id = d.id and d.curation_lvl = 0 and d.id = e.id and e.seq = f.seq_id and b.id = g.id order by lower(g.name)";
  
  $statement_loci = make_query($DBConn,$query_loci,250);
  $count = 0;
  $bgcolor = "";
  
  //jp note - breaking the HTML rule because it runs considerably faster this way than when looping the data via bauplan
  $sort = '<a href="/data_center/qtl-loci-summary?sort=';
  $img_ascend = "<img src='/images/collapse.png'>";
  $img_descend = "<img src='/images/expand.png'>";
  $list = ' <table style="width: 100%" >
   <tr>
    <th align="left">  Locus </th>
    <th align="center"> Accession #</th>
   </tr>';
   
   $arrRecord = retrieve_row($statement_loci);
   
  while(strlen($arrRecord["ID"]) > 0)
  {
    $list .= '
      <tr style="background-color: ';
    if ($count % 2 == 0)
      $list .= '#FFFFFF;';
    
    $list .= '">
    <td><a href=\data_center/locus/'.$arrRecord['id'].'\> '.trim($arrRecord['name']);
	if(strlen($arrRecord['full_name']) > 0)
		$list .= '<i> '. trim($arrRecord['full_name']) .' </i>';
    $list .= "</a></td><td><a href=\"data_center/sequence?id=" . $arrRecord['seq_id'] . "\">". $arrRecord['key'] . "</a>: " . $arrRecord['seq_type'] . " - " . trim($arrRecord['seq_title']) . "</td></tr>";
	$arrRecord = retrieve_row($statement_loci);
    
  }//while
  $list .= "</table>";

  setSessionVar("qtl_loci_str".$sortby, $list); 
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
