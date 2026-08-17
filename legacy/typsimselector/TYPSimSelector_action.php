<?php
//This code takes the data from the database and pulls it into queries bases upon the selections indicated.
//It then takes the query and presents the data in a sorted table based upon similiarity score.

//include_once statements give locations of necessary support files.
include_once('../../../lib/Bauplan.php');
include_once("../../../include/db-api.php");
include_once("../../../include/gp_lib.php");


// Get system configuration
$system = getSystemInfo('mgdb.conf');

// Define terms to be used. Provide direction on where and how to get them.
	$taxa   = getCGIParam('taxa', 'G', false);
	$compare_taxa2   = getCGIParam('taxa2', 'G', false);
	$sort_order = getCGIParam('sort_order', 'G', false);
	$dataset = getCGIParam('dataset', 'G', false);

    $db = getSystemInfo('db.conf');
    //jp - As of 2/19/2015 TYPSIMSelector data is in the core2 db
    $DBConn = connect_to_database(false);

if($dataset == 'curation') {
	//Selects the taxa name for snp_entry_id = $taxa
		$germplasm_query1 = "SELECT snp_entry_id, taxa FROM pidata.snp_entry WHERE snp_entry_id=$taxa";
		$germplasm_stmt1 = make_query($DBConn, $germplasm_query1,1);
		$arrGermplasm1 = retrieve_row($germplasm_stmt1);

	//Provide feedback to users allowing them to see that the query ran as they wanted.
		echo "Your reference for this sort was" . "&nbsp" . "<b>" . $arrGermplasm1["taxa"] . "</b>" . "<br>";


	// Conditional for query based on whether we are comparing all lines or only two
		$germplasm_stmt3 = "";
	if ($compare_taxa2 != "ALL") {

		$germplasm_query2 = "SELECT snp_entry_id, taxa FROM pidata.snp_entry WHERE snp_entry_id=$compare_taxa2";
		$germplasm_stmt2 = make_query($DBConn, $germplasm_query2,1);
		$arrGermplasm2 = retrieve_row($germplasm_stmt2);
		echo "compared to" . "&nbsp" . "<b>" . $arrGermplasm2["taxa"] . "</b>." . "<br>";

		$germplasm_query3 = "SELECT DISTINCT germplasm1_id, inventory_id, taxa, similarity FROM pidata.snp_entry_map join pidata.snp_entry ON snp_entry_id = germplasm1_id WHERE germplasm2_id = $taxa AND germplasm1_id = $compare_taxa2";
		$germplasm_stmt3 = make_query($DBConn, $germplasm_query3 ,1);
	}
	else {

		$germplasm_query3 = "SELECT DISTINCT germplasm1_id, inventory_id, taxa, similarity FROM pidata.snp_entry_map join pidata.snp_entry ON snp_entry_id = germplasm1_id WHERE germplasm2_id = $taxa"; //all entries
		$germplasm_stmt3 = make_query($DBConn,$germplasm_query3,1);
		echo "compared to <b>ALL LINES</b>.<br>";

	}

	//Create an array of results to incorporate only the data columns that are necessary.
		$arrscore_results = array();
		$count = 0;
		while($arrGermplasm3 = retrieve_row($germplasm_stmt3))
		{
			$arrscore_results[$count] = $arrGermplasm3["similarity"];
			$arrInventory_id[$count] = $arrGermplasm3["inventory_id"];
			$arrTaxa_results[$count] = $arrGermplasm3["taxa"];
			$arrGermplasm1_id[$count] = $arrGermplasm3["germplasm1_id"];
			$count++;
		}

	// Select accession numbers
		$accession_query = "SELECT DISTINCT inventory_id, inventory_number_part1, inventory_number_part2, accession_id FROM pidata.custom_inventory";
		$accession_stmt = make_query($DBConn,$accession_query,1);

		while($arrAccession = retrieve_row($accession_stmt)){
			$inventory_number = $arrAccession["inventory_id"];
			$arrInventory_Number1[$inventory_number] = $arrAccession["inventory_number_part1"];
			$arrInventory_Number2[$inventory_number] = $arrAccession["inventory_number_part2"];
			$arrAccession_id[$inventory_number] = $arrAccession["accession_id"];
		}

	//Give feedback to users.
		if ($count == 1) {
			echo "<br>" . "There was $count record returned.";
		}
		else {
			echo "<br>" . "There were $count records returned.";
		}

	//Use and IF/ELSE statement to sort data to users preference.
		if ($sort_order == "ASC"){
			asort($arrscore_results);
		}
		else {
			arsort($arrscore_results);
		}
		$value = 0;

	//Provide results in table and incorporate sorted results into it.
		echo "<br>";
		echo "<table border='6px;'>"; //add a command to direct to a table style in CSS
			echo "<tr>";
				echo "<th>Run Number<span style='cursor:help' title=''><img src='../images/question.png' height='10' width='10'/></span></th>";
				echo "<th>Inbred Line <span style='cursor:help' title='A variety or cultivar created by continued inbreeding usually by selfing a number of generations (more rarely sib-pollination for more generation) until the majority of genes become homozygous.'><img src='../images/question.png' height='10' width='10'/></span></th>";
				echo "<th>Accession Number <span style='cursor:help' title='Most often the number given by the National Plant Germplasm System. (Named after the Inbred Line if a number has not been assigned)'><img src='../images/question.png' height='10' width='10'/></span></th>";
				echo "<th>Accession ID <span style='cursor:help' title=''><img src='../images/question.png' height='10' width='10'/></span></th>";
				echo "<th>Similarity Score<span style='cursor:help' title='Similarity = 1-dissimilarity score. The dissimilarity is the percentage divergence between SNPs.'><img src='../images/question.png' height='10' width='10'/></span></th>";
			echo "</tr>";

			foreach ($arrscore_results as  $key => $value)
			{
				$inventoryID = $arrInventory_id[$key];
				$first_part_id = "";
				$accession = "";

				if (array_key_exists($inventoryID, $arrAccession_id)) {
					$accession_number = $arrAccession_id[$inventoryID];
					$accession_id1 = $arrInventory_Number1[$inventoryID];
					$accession_id2 = $arrInventory_Number2[$inventoryID];
				}
				$taxa_parts = preg_split('/_/', $arrTaxa_results[$key],0);

				$accession_id = $accession_id1 . " " . $accession_id2;

				$hyperlink_accession_id = "<a href='https://npgsweb.ars-grin.gov/gringlobal/accessiondetail.aspx?accid=$accession_id1+$accession_id2' target='_blank'>$accession_id</a>";
				$hyperlink_accession_number = "<a href='https://npgsweb.ars-grin.gov/gringlobal/accessiondetail.aspx?$accession_number' target='_blank'>$accession_number</a>";


				echo "<tr>";
					echo '<td>' . $arrGermplasm1_id[$key] . '</td>';
					echo '<td>' . $taxa_parts[0] . '</td>';
					echo '<td>' . $hyperlink_accession_id . '</td>';
					echo '<td>' . $hyperlink_accession_number . '</td>';
					echo "<td>" . $value . "</td>";
				echo "</tr>";

			}
		echo "</table>";

}
else {

	// If-Else to query against all lines or only one.
	if ($compare_taxa2 != 'ALL') {

		//$curator_query1 = "SELECT iid1, iid2, dst FROM pidata.ames_merged WHERE iid1 = '$taxa' AND iid2 = '$compare_taxa2'";
		$curator_query1 = "SELECT iid1, iid2, dst FROM pidata.ames_merged WHERE (iid1 = '$taxa' AND iid2 = '$compare_taxa2') OR (iid1 = '$compare_taxa2' AND iid2 = '$taxa')";

	}
	else {

		$curator_query1 = "SELECT iid1, iid2, dst FROM pidata.ames_merged WHERE iid1 = '$taxa' OR iid2 = '$taxa'";

	}

	$curator_stmt1 = make_query($DBConn, $curator_query1,1);
	$arrscore_results = array();
	$count = 0;

	// If a taxa is compared to itself set the score equal to one or go through query array
	if ($taxa == $compare_taxa2) {

		if($arrCurator1["iid1"] == $taxa)
		{
			$arrCurator_iid1[0] = $taxa;
			$arrCurator_iid2[0] = $compare_taxa2;
			$arrscore_results[0] = 1;
			$count++;
		} else if($arrCurator1["iid2"] == $taxa)
		{
			$arrCurator_iid2[0] = $taxa;
			$arrCurator_iid1[0] = $compare_taxa2;
			$arrscore_results[0] = 1;
			$count++;
		}
	}
	else {
		while($arrCurator1 = retrieve_row($curator_stmt1))
		{
			if($arrCurator1["iid1"] == $taxa)
			{
				$arrCurator_iid1[$count] = $arrCurator1["iid1"];
				$arrCurator_iid2[$count] = $arrCurator1["iid2"];
				$arrscore_results[$count] = $arrCurator1["dst"];
				$count++;
			} else if($arrCurator1["iid2"] == $taxa)
			{
				$arrCurator_iid2[$count] = $arrCurator1["iid1"];
				$arrCurator_iid1[$count] = $arrCurator1["iid2"];
				$arrscore_results[$count] = $arrCurator1["dst"];
				$count++;
			}
		}
	}

	// If no results are returned then the following is returned
	if ($count == 0) {
		$arrCurator_iid1[0] = $taxa;
		$arrCurator_iid2[0] = $compare_taxa2;
		$arrscore_results[0] = "No score has been calculated between these lines.";
	}

	if ($sort_order == "ASC"){
		asort($arrscore_results);
	}
	else{
			arsort($arrscore_results);
	}

	if ($compare_taxa2 != 'ALL') {
		echo "Your reference for this sort was <b>" . $taxa . "</b><br> compared to <b>" . $compare_taxa2 . "</b>.<br>";
	}
	else {
		echo "Your reference for this sort was <b>" . $taxa . "</b><br> compared to <b>ALL LINES</b>.<br>";
	}

	if ($count == 1) {
		echo "<br>" . "There was $count record returned.";
	}
	else {
		echo "<br>" . "There were $count records returned.";
	}

	$value = 0;
	echo "<table border='6px;'>"; //add a command to direct to a table style in CSS
		echo "<th>Inbred Line <span style='cursor:help' title='A variety or cultivar created by continued inbreeding usually by selfing a number of generations (more rarely sib-pollination for more generation) until the majority of genes become homozygous.'><img src='../images/question.png' height='10' width='10'/></span></th>";
		echo "<th>Similarity Score <span style='cursor:help' title=''><img src='../images/question.png' height='10' width='10'/></span></th>";

		foreach ($arrscore_results as $key => $value)
		{
			echo "<tr>";
				echo '<td>' . $arrCurator_iid2[$key] . '</td>';
				echo '<td>' . $value . '</td>';
			echo "</tr>";
		}
	echo "</table>";
}


?>
