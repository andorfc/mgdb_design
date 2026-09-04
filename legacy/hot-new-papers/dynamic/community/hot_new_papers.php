<?PHP
$hotnewpapers = $mgdb->get('body')->load('templates/hot-new-papers.bau');
		$hotnewpapers->get('hot-new-table-row')->loop(array(
			array('hotnewyear' => '2011 Recommended Readings'),
			array('hotnewyear' => '2010 Recommended Readings'),
			array('hotnewyear' => '2009 Recommended Readings'),
			array('hotnewyear' => '2008 Recommended Readings'),
			array('hotnewyear' => '2007 Recommended Readings'),
			array('hotnewyear' => '2006 Recommended Readings'),
			array('hotnewyear' => '2005 Recommended Readings'),
			array('hotnewyear' => '2004 Recommended Readings'),
			array('hotnewyear' => '2003 Recommended Readings'),
			array('hotnewyear' => '2002 Recommended Readings'),
			array('hotnewyear' => '2001 Recommended Readings'),
			array('hotnewyear' => '2000 Recommended Readings'),
			array('hotnewyear' => 'Entire Recommended Readings')
			));
		$DBConn = connect_to_database();
		/*----get the date and the full text links--------*/
        	$query_count = "SELECT rec_month, rec_year, person_id, person_id2, reference_id, abstract_link, html_link, pdf_link,a.name as name,a.title as title FROM ED_BOARD_PAPERS z left join REFERENCE a on z.reference_id = a.ID WHERE z.rec_year=2011";
		$stmt_count = make_query($DBConn,$query_count,1);
   		$no_of_rows=0;
        $output = array();
   		while($rows_selected = retrieve_row($stmt_count))
   			{
   	    			$no_of_rows++;
   				array_push($output, array('rec_month' => $rows_selected['REC_MONTH'],'rec_year' =>$rows_selected['REC_YEAR'],'abstract_link' =>$rows_selected['ABSTRACT_LINK'],
   				                          'pdf_link' =>$rows_selected['PDF_LINK'],'html_link' =>$rows_selected['HTML_LINK'],'name_tile' =>(string)$rows_selected['NAME'].'<br>'.(string)$rows_selected['TITLE']));
    			}
        /*----get the names of the recommending member(s)---*/
        $query_count1 = "SELECT person_id,person_id2,b.name_first,b.name_last FROM ED_BOARD_PAPERS z left join PERSON b on z.person_id=b.id WHERE z.rec_year= 2011";
		$stmt_count1 = make_query($DBConn,$query_count1,1);
        $output1 = array();
   		while($rows_selected1 = retrieve_row($stmt_count1))
   			{
   				array_push($output1, array('rec_mem' =>(string)$rows_selected1['NAME_FIRST'].' '.(string)$rows_selected1['NAME_LAST']));
    			}
    		/*------------get memo----------------*/
    		$hot_new_papers_table_array=array();
    		$i=0;
    		while($i<$no_of_rows)
    			{
    	     		$temp=$output[$i];
    	     		$temp1=$output1[$i];
    	     		$value1='0';
    	     		switch((string)$temp['rec_month'])
    	     		{
    	     			case 'January': $value1='1';
    	     			break;
    	     			case 'Febraury': $value1='2';
    	     			break;
    	     			case 'March': $value1='3';
    	     			break;
    	     			case 'April': $value1='4';
    	     			break;
    	     			case 'May': $value1='5';
    	     			break;
    	     			case 'June': $value1='6';
    	     			break;
    	     			case 'July': $value1='7';
    	     			break;
    	     			case 'August': $value1='8';
    	     			break;
    	     			case 'September': $value1='9';
    	     			break;
    	     			case 'October': $value1='10';
    	     			break;
    	     			case 'November': $value1='11';
    	     			break;
    	     			case 'December': $value1='12';
    	     			break;
    	     			
    	     		}
    	     		array_push($hot_new_papers_table_array,array($value1.'/'.(string)$temp['rec_year']=>'abc','recom-member'=>(string)$temp1['rec_mem'],'citation'=>(string)$temp['name_tile'],'abstractlink'=>(string)$temp['abstract_link'],'pdflink'=>(string)$temp['pdf_link'],'htmllink'=>(string)$temp['html_link']));
    	     		$i++;
    			}
   	
   		$hotnewpapers->get('hot-new-recom-table-row')->loop($hot_new_papers_table_array);
   		
?>
