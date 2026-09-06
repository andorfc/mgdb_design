<?PHP
include_once("../../include/gp_lib.php");
include_once("conf.php");

session_start();
function get_poster_list($file="posters/video_list.txt") {
  $fp = fopen($file, 'r');
  $posters = array();
  $i=0;
  //File headers:
  //PRESENTER_FIRST_NAME 	 PRESENTER_LAST_NAME 	 PRESENTER_EMAIL 	 CATEGORY 	POSTER 	 PRESENTER_ROLE 	TITLE 	ABSTRACT 
  while ($line = fgets($fp)) {
    $line_parts = explode("\t", $line);
    $posters[$i]["fname"] = trim($line_parts[0]);
    $posters[$i]["lname"] = trim($line_parts[1]);
    $posters[$i]["email"] = trim($line_parts[2]);
    $posters[$i]["category"] = trim($line_parts[3]);
    $posters[$i]["num"] = trim($line_parts[4]);
    $posters[$i]["role"] = trim($line_parts[5]);
    $posters[$i]["author_list"] = trim($line_parts[6]);
    $posters[$i]["affiliation_list"] = trim($line_parts[7]);
    $posters[$i]["title"] = trim($line_parts[8]);
    $posters[$i]["abstract"] = trim($line_parts[9]);
    $posters[$i]["video_link"] = trim($line_parts[10]);
    $i++;
  }
  return $posters;
}

$password = getCookie('v2020_password', false);

if (!$password) {
  $password = getCGIParam('v2020_password', 'P', false);
}

if (!validate_password($password)) {
    /* Redirect AND stop. Without the exit the script ran straight on and printed
       the whole wall -- presenter names, emails, titles and abstracts, read from
       posters/ -- into the body of the 302, where anything that does not follow
       redirects reads it. Fixed 2026-09-05. */
    header('Location: /maize_meeting/v2020/poster_login.php?dest=videos');
    exit;
}

include("mainheader_noimg_posters.php");

$talks = get_poster_list("posters/video_list.txt");

$table_data = "";
 for ($i=0; $i < count($talks); $i++) {
   $color = ($i % 2) == 1 ? "white" : "lightgray";
  // echo "zoom_link = " . $talks[$i]["zoom_link"];
  // echo "<br>img_file = " . $talks[$i]["img_file"];
   $authors = str_replace("|", "; ", $talks[$i]["author_list"]);
   $affiliations = str_replace("|", "<br>", $talks[$i]["affiliation_list"]);
   $video_icon = ($talks[$i]["video_link"] != "0") ? "<a href='".$talks[$i]["video_link"] . "' target='_blank'><img src='images/video_icon.png'/></a>" : "";
   
   $table_data .= "<tr style='background-color: $color'>
                     <td valign='center' height='32px'><br>$video_icon<br></td>
                     <td><br>".$talks[$i]["num"]."&nbsp;<br></td>
                     <td><br><a href='mailto:".$talks[$i]["email"]."'>".$talks[$i]["fname"]." " .$talks[$i]["lname"] ."</a>&nbsp;<br></td>
                     <td><br><a href='#!' onclick=\"toggle_abstract('".$talks[$i]["num"]."_abstract')\"><b>".$talks[$i]["title"]."</b></a><br></td>
                   </tr>
                   <tr style='background-color: $color;'>
                     <td colspan='4' style='padding: 5px'><div id='".$talks[$i]["num"]. "_abstract' style='display: none;'><small>Full author list: <br>$authors<br>$affiliations<br><br></small>".$talks[$i]["abstract"]."<br><br></div></td>
                   </tr>
                   ";
 }



?>

<script language="javascript">

function toggle_abstract(id) {
  var ele = document.getElementById(id);
  ele.style.display = (ele.style.display == "none") ? "inline" : "none";
}

</script>
<table width="900">
<tr>
<td width="72%" valign="top">
 
<h4>Welcome to the recorded talks of the virtual 2020 maize meeting!</h4>

The recorded talks will be made available from <b>June 22nd - July 3rd</b> to all registrants of the 2020 Virtual Maize Genetics Meeting, and will be made available here once they've been processed!<br><br> 

<center><u><a href="v2020Schedule.pdf" target="_blank"><b>Download the full meeting schedule</b></a></u>&nbsp;&nbsp;&nbsp;<u><a href="posters/files/VMM_2020_Registrant_List.pdf" target="_blank"><b>Download the attendee list</b></a></u></center><br><br>



<!--<b>Talks with posters</b>
<table style="border-collapse: collapse">
  <tr>
    <th width="75px"></th><th></th><th align="left"></th><th align="left"></th>
  </tr>
  <?php //echo $talk_table_data; ?>
</table> <br><br>-->


<table style="border-collapse: collapse">
  <tr>
    <th width="50px"></th><th></th><th align="left" width="125px"></th><th align="left"></th>
  </tr>
  <tr>
    <td valign='center' height='32px'><br><a href='https://drive.google.com/file/d/1x3AVqPXtdVJ-_KXnQl_43Mgne44Ud6gy' target='_blank'><img src='images/video_icon.png'/></a><br></td>
    <td><br>&nbsp;<br></td>
    <td><br><a href='mailto:ruth.wagner@bayer.com'>Ruth Wagner</a>&nbsp;<br></td>
    <td><br><a href='#!' onclick="toggle_abstract('C_abstract')"><b>Community Session</b></a><br>
    </td>
    </tr>
    <tr>
      <td colspan='4' style='padding: 5px'><div id='C_abstract' style='display: none;'>The Community Session includes an MGC Board of Directors update on the status of incorporation into the Maize Genetics Cooperation, current finances; Updates from MGC committees including MGAC, Awards & Nominations Committee, RCN Survey and Workshop Outcomes; Agency Updates from NSF, USDA-ARS, USDA-NIFA, NCGA, and Open Discussion. <br><br></div></td>
    </tr>
  <?php echo $table_data; ?>
</table>

<?PHP
//<center><a href="../abstracts/2015Program.pdf">Download Abstract Book</a></center>
//<br><br>
?>



</td >

<?PHP
include("footer.php");

?>