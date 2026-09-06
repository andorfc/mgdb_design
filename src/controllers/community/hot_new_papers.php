<?PHP
include_once('include/gp_lib.php');

$ROW = getCGIParam('row', 'G', 0);
$SORT = getCGIParam('sort', 'G', 0);
$CODIE = getCGIParam('codie', 'G', 0);
$CODIE = 0; //jp tmp rm
if ($SORT == 0)
  $SORT = 2;

$this_year = date("Y");

$bauplan->includeCss('/css/hotnew.css');

$hotnewpapers = $mgdb->get('body')->load('templates/community/hot-new-papers.bau');
$hotnewpapers->get('hot-new-papers-table')->get('row-no-selected')->replace($ROW);
$tmpl = $hotnewpapers->get('hot-new-papers-table');

// show $year_limit years of back "hot new papers"
$year_limit = 10;
$back_years = array();
$prev_year = $this_year;
while ($this_year - $prev_year <= $year_limit) {
  array_push($back_years, array('hotnewyear' => "$prev_year Recommended Readings"));
  $prev_year--;
}
$hotnewpapers->get('hot-new-table-row')->loop($back_years);

// Year to display (0 = all years)
$year = ($ROW > 0) ? $this_year - $ROW+1 : 0;

$DBConn = connect_to_database();
         
// Get membership for selected year
if ($year != 0) {
  $membership = array();
  $sql = "
    SELECT p.id AS id, p.name AS name, p.name_first AS fname, p.name_last AS lname
    FROM mgdb.ed_board eb
      LEFT JOIN mgdb.person p ON p.id=eb.person_id
    WHERE eb.year = '$year' 
    ORDER BY eb.auto_num";
  $sth = make_query($DBConn, $sql);
  while ($row=retrieve_row($sth)) {
    $url = '/person/' . $row['id'];
    $name = $row['fname'] . ' '  . $row['lname'];
    array_push($membership, "<a href='$url'>$name</a>");
  }
  $hotnewpapers->get('year')->replace($year);
  if (count($membership) > 0) {
    $ed_board = implode(', ', array_slice($membership, 0, count($membership) -1));
    $ed_board .= ' and ' . array_pop($membership);
    $hotnewpapers->get('ed-board-members')->replace($ed_board);
    $hotnewpapers->get('membership')->unmute();
  }
}
logVarDump($membership, "Membership:\n");

// Get papers for selected year
  $sql = "
  SELECT ebp.rec_month, ebp.rec_year, ebp.person_id, ebp.person_id2, ebp.reference_id, 
         ebp.abstract_link, ebp.html_link, ebp.pdf_link, r.name, r.title, 
         ra.abstract_1 AS abstract, p.name_first, p.name_last
  FROM mgdb.ed_board_papers ebp 
    LEFT JOIN mgdb.reference r ON r.id=ebp.reference_id 
    LEFT JOIN mgdb.reference_abstract ra ON ra.id=r.id
    LEFT JOIN mgdb.person p ON ebp.person_id=p.id 
  WHERE rec_month IS NOT NULL"; 
    
if ($year != 0) {
  $sql .= "
    AND ebp.rec_year=$year";
}

if ($CODIE == 1) {
    $sql .= "
    AND p.id = 3530952";
}
    
if ($SORT == 1) {
  $sql .= "
  ORDER BY ebp.rec_year ASC, CASE WHEN rec_month='January' 
    THEN 1 WHEN rec_month='February' THEN 2 WHEN rec_month='March' 
    THEN 3 WHEN rec_month='April' THEN 4 WHEN rec_month='May' 
    THEN 5 WHEN rec_month='June' THEN 6 WHEN rec_month='July' 
    THEN 7 WHEN rec_month='August' THEN 8 WHEN rec_month='September' 
    THEN 9 WHEN rec_month='October' THEN 10 WHEN rec_month='November' 
    THEN 11 ELSE 12 END";
}
else if ($SORT == 2) {
  $sql .= "
  ORDER BY ebp.rec_year DESC, CASE WHEN rec_month='January' 
    THEN 1 WHEN rec_month='February' THEN 2 WHEN rec_month='March' 
    THEN 3 WHEN rec_month='April' THEN 4 WHEN rec_month='May' 
    THEN 5 WHEN rec_month='June' THEN 6 WHEN rec_month='July' 
    THEN 7 WHEN rec_month='August' THEN 8 WHEN rec_month='September'
    THEN 9 WHEN rec_month='October' THEN 10 WHEN rec_month='November' 
    THEN 11 ELSE 12 END DESC";
}
else if ($SORT == 3) {
  $sql .= "
  ORDER BY b.name_first ASC,b.name_last ASC";
}
else {
  $sql .= " 
  ORDER BY b.name_first DESC,b.name_last DESC";
}

$sth = make_query($DBConn, $sql);
$no_of_rows=0;
$recommendations = array();
while ($rows_selected = retrieve_row($sth)) {
  $no_of_rows++;
  
  // Get ed board comments
  $memo_sql = "
    SELECT memo, name_first, name_last 
    FROM mgdb.memo m
      LEFT OUTER JOIN person p ON p.id=m.source
    WHERE m.id=" . $rows_selected['reference_id'] . " 
          AND type_term IN (1187419, 3530964)
    ORDER BY auto_num";
  $sth_memo = make_query($DBConn, $memo_sql);
  $memo_str = "";
  while ($memo_row = retrieve_row($sth_memo)) {
    $memo_str .= mgdb_safe_html($memo_row['memo']) . '<br>'
               . '<i>' . $memo_row['name_first'] . ' ' . $memo_row['name_last'] . '</i><br><br>';
  }
//logMessage("Comments:\n$memo_str\n");
  
  // Recommender
  $recommender = ($rows_selected['name_last'] == "Egesa_asdkalja84hjfhafhkhaskfjk34wsdxzaqpolkknm") 
               ? $rows_selected['name_first'] . ' ' . $rows_selected['name_last'] . "<br>(CODY)" 
               : $rows_selected['name_first'] . ' ' . $rows_selected['name_last']; //jp -- tmp rm 
  array_push($recommendations, 
    array('rec_month'     => $rows_selected['rec_month'],
          'rec_year'      => $rows_selected['rec_year'], 
          'abstract'      => $rows_selected['abstract'],
          'abstract_link' => $rows_selected['abstract_link'],
          'pdf_link'      => $rows_selected['pdf_link'],
          'html_link'     => $rows_selected['html_link'],
          'name_tile'     => (string)$rows_selected['name'].'<br>'.(string)$rows_selected['title'],
          'memo'          => $memo_str, 
          'author2'       => $rows_selected['person_id2'],
          'rec_mem'       => $recommender,
          'person_id'     => $rows_selected['person_id'], 
          'reference_id'  => $rows_selected['reference_id'], 
          'name'          => $rows_selected['name'], 
          'title'         => $rows_selected['title'],
  ));
}//each paper
      
$hot_new_papers_table_array=array();
$i=0;
$color_c = 0;
while ($i < $no_of_rows) {
  $recommendation = $recommendations[$i];
//logVarDump($recommendation, "One recommendation:\n");
  $month = '0';
  switch($recommendation['rec_month']) {
     case 'January':   $month='1'; break;
     case 'February':  $month='2'; break;
     case 'March':     $month='3'; break;
     case 'April':     $month='4'; break;
     case 'May':       $month='5'; break;
     case 'June':      $month='6'; break;
     case 'July':      $month='7'; break;
     case 'August':    $month='8'; break;
     case 'September': $month='9'; break;
     case 'October':   $month='10'; break;
     case 'November':  $month='11'; break;
     case 'December':  $month='12'; break;
     default : $month='';
  }
  
  //-----get the name of the second recommending member if any-------
  if (($recommendation['author2'] == 0) || ($recommendation['author2'] == null))
    $author2 = '';
  else {
    $query_count2 = "SELECT name_first, name_last FROM mgdb.person b WHERE id=" . $recommendation['author2'];
    $stmt_count2 = make_query($DBConn, $query_count2);
    $rows_selected2 = retrieve_row($stmt_count2);
    $author2 = $rows_selected2['name_first'].' '.$rows_selected2['name_last'];
  }
  
  // Print default message for entries with no memo     
  $ed_comment = "";   
  if (strlen($recommendation['memo']) > 0)
    $ed_comment = "<b>Editorial Board Member Comment(s)</b><br>" 
                     . $recommendation['memo'] . "<br>";
  else
    $ed_comment = "There are currently no comments for this article.";
  
  // link title to reference record
  $reference = "";
  if (strlen($recommendation['name_tile']) > 0 && $recommendation['reference_id'] > 0) {
    $reference = "<a href='/data_center/reference?id=" 
               . $recommendation['reference_id'] . "'>" . $recommendation['name'] 
               . "</a><br>&nbsp;&nbsp;&nbsp;" . $recommendation['title'];   
  }
  else
    $reference = (string)$recommendation['name_tile'];    
  
  // Set paper links (display attr = none/inline)
  $display_ab = "none";
  $display_pdf = "none";
  $display_html = "none";
  if (strlen($recommendation['abstract_link']) > 0)
    $display_ab = "inline"; 
  if (strlen($recommendation['pdf_link']) > 0)
    $display_pdf = "inline"; 
  if (strlen($recommendation['html_link']) > 0)
    $display_html = "inline";                      
  
  // Set abstract text
  $abstract = '';
//logMessage("Abstract:\n" . $recommendation['abstract']);
  if (strlen($recommendation['abstract']) > 0) {
//logMessage("Set abstract");
    $abstract = '<b>Abstract:</b><br>' . $recommendation['abstract'];
  }

  // Set row color
  $row_color = ($i % 2 == 0) ? "#FFFFFF" : "#E8E8E8";
  if (strpos($recommendation['rec_mem'], "CODIE")) {
    $row_color = "#C6E7C1";
  }
  else {
    $color_c++;
  }
  
  if ($author2 == '')    
    array_push($hot_new_papers_table_array,
      array('date'              => $month . '/' . $recommendation['rec_year'],
            'recom-member'      => $recommendation['rec_mem'], 
            'personid'          => $recommendation['person_id'], 
            'citation'          => $reference . '<br><br>' . $ed_comment, 
            'citation-collapsed'=> $reference,
            'abstract'          => $abstract,
            'abstractlink'      => str_replace(' ', '+', $recommendation['abstract_link']), 
            'display_ab'        => $display_ab, 
            'display_pdf'       => $display_pdf, 
            'display_html'      => $display_html, 
            'pdflink'           => str_replace(' ', '+', $recommendation['pdf_link']),
            'htmllink'          => str_replace(' ', '+', $recommendation['html_link']),
            'row_color'         => $row_color
    ));
  else
    array_push($hot_new_papers_table_array,
      array('date'              => $month.'/'.$recommendation['rec_year'],
            'recom-member'      => $recommendation['rec_mem']
                                  .' <a href="/person?id=' . $recommendation['person_id'] . '"><img src="../icon/person_16.png"></a><br>'
                                  . $author2, 
            'personid'          => $recommendation['author2'], 
            'citation'          => $reference . '<br><br>'.$ed_comment,
            'citation-collapsed'=> $reference,
            'abstract'          => $abstract,
            'abstractlink'      => str_replace(' ', '+', $recommendation['abstract_link']),
            'pdflink'           => str_replace(' ', '+', $recommendation['pdf_link']),
            'htmllink'          => str_replace(' ', '+', $recommendation['html_link']),
            'row_color'         => $row_color
    ));
  $i++;
}//each row
//logVarDump($hot_new_papers_table_array);

if ($hot_new_papers_table_array != null) {
  $hotnewpapers->get('hot-new-recom-table-row')->loop($hot_new_papers_table_array);
}
?>
