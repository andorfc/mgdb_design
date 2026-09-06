<?PHP

require("../../include/db-api.php");

$name= $_GET["name"];

$DBConn = connect_to_database();//OCILogon(DB_USER,DB_PASS,DB_NAME);

	 $gold_query = "SELECT B.TITLE AS BTITLE, B.ID AS BID  FROM ID_REFERENCE A JOIN REFERENCE B on A.REFERENCE = B.ID WHERE B.IN1 = '1232902' AND A.ID =" . $DBConn->quote($name);
      $stmt_gold = make_query($DBConn,$gold_query,1);
      $arrgold = retrieve_row($stmt_gold);

	  if($arrgold["BTITLE"])
		{
		
			$gold_query2 = "SELECT C.NAME_FIRST AS FNAME, C.NAME_LAST AS LNAME, C.ID AS CID, A.ID AS AID FROM REFERENCE A JOIN REFERENCE_AUTHORS B ON A.ID = B.ID JOIN PERSON C ON C.ID = B.AUTHOR WHERE A.ID = '" . $arrgold["BID"] . "'";
			$stmt_gold2 = make_query($DBConn,$gold_query2,1);
			$arrgold2 = retrieve_row($stmt_gold2);
			
			
		echo "<table cellspacing='5' cellpadding='3'>";
		echo "<tr>";
		echo "	<td>";
		echo "		<img src='/images/gene_review.png'></img>";
		echo "	</td>";
		echo "	<td>";
					
					echo "<h4>Maize Gene Review</h4><br><a href='displayrefrecord.cgi?id=" . $arrgold["BID"]  . "'><u><b>" . $arrgold["BTITLE"] . "</b></u></a><br>";
					$gfirst = 1;
					while ($arrgold2["CID"])
					{
						if($gfirst == 1)
						{
						echo "by <a href='displaypersonrecord.cgi?id=" . $arrgold2["CID"]  . "'>" . $arrgold2["FNAME"] . " " . $arrgold2["LNAME"] . "</a>";
							$gfirst = 0;
						} else {
							echo ", <a href='displaypersonrecord.cgi?id=" . $arrgold2["CID"]  . "'>" . $arrgold2["FNAME"] . " " . $arrgold2["LNAME"] . "</a>";
						}
						$arrgold2 = retrieve_row($stmt_gold2);
					}
					echo "<center> <br><a href='http://www.maizegenereview.org/" . $locusname . ".html'> Read the review </a></center>";
					
		echo "	</td>";
		echo "</tr>";
		echo "</table>";
		echo "<br>";
		}
   ?>