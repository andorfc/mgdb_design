<?PHP
include_once("../../include/gp_lib.php");
include_once("conf.php");

session_start();
function get_poster_list($file="posters/poster_listv2.txt") {
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
    $posters[$i]["zoom_link"] = trim($line_parts[10]);
    $posters[$i]["img_file"] = trim($line_parts[11]);
    $i++;
  }
  return $posters;
}

function category_color($category) {
    switch ($category) {
        case "Cytogenetics":
            return "lightgreen";
        case "Education & Outreach":
            return "lightcoral";
        case "Quantitative Genetics & Breeding":
            return "khaki";
        case "Transposons & Epigenetics":
            return "paleturquoise";
        case "Biochemical and Molecular Genetics":
            return "lightskyblue";
        case "Cell and Developmental Biology":
            return "lightsteelblue";
        case "Computational and Large-Scale Biology":
            return "thistle";
        
    }
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
    header('Location: /maize_meeting/v2020/poster_login.php');
    exit;
}

include("mainheader_noimg_posters.php");

$posters = get_poster_list();
$talks = get_poster_list("posters/talk_list.txt");


$table_data = "";
$table_data = "<tr><td colspan='4'>&nbsp;</td></tr>
                   <tr style='background-color: #B693FE;'>
                     <td colspan='4' style='padding: 5px; font-weight: bold; font-size:18px;'>Talks with posters</td>
                   </tr><tr><td colspan='4'>&nbsp;</td></tr>";
 for ($i=0; $i < count($talks); $i++) {
   $color = ($i % 2) == 0 ? "white" : "lightgray";
  // echo "zoom_link = " . $talks[$i]["zoom_link"];
  // echo "<br>img_file = " . $talks[$i]["img_file"];
   $authors = str_replace("|", "; ", $talks[$i]["author_list"]);
   $affiliations = str_replace("|", "<br>", $talks[$i]["affiliation_list"]);
   $zoom_icon = ($talks[$i]["zoom_link"] != "0") ? "<a href='".$talks[$i]["zoom_link"] . "' target='_blank'><img src='images/zoom_icon.png'/></a>" : "";
   $poster_icon = ($talks[$i]["img_file"] != "0") ? "<a href='".$talks[$i]["img_file"] . "' target='_blank'><img src='images/poster_icon.png'/></a>" : "";
   
   
   $table_data .= "<tr style='background-color: $color'>
                     <td height='32px'>$poster_icon $zoom_icon</td>
                     <td>".$talks[$i]["num"]."&nbsp;</td>
                     <td><a href='mailto:".$talks[$i]["email"]."'>".$talks[$i]["fname"]." " .$talks[$i]["lname"] ."</a>&nbsp;</td>
                     <td><a href='#!' onclick=\"toggle_abstract('".$talks[$i]["num"]."_abstract')\"><b>".$talks[$i]["title"]."</b></a></td>
                   </tr>
                   <tr style='background-color: $color;'>
                     <td colspan='4' style='padding: 5px'><div id='".$talks[$i]["num"]. "_abstract' style='display: none;'><small>Full author list: <br>$authors<br>$affiliations<br><br></small>".$talks[$i]["abstract"]."<br><br></div></td>
                   </tr>
                   ";

 }

 
 $cur_category = "";
 for ($i=0; $i < count($posters); $i++) {
   $color = ($i % 2) == 0 ? "white" : "lightgray";
  // echo "zoom_link = " . $posters[$i]["zoom_link"];
  // echo "<br>img_file = " . $posters[$i]["img_file"];
   $authors = str_replace("|", "; ", $posters[$i]["author_list"]);
   $affiliations = str_replace("|", "<br>", $posters[$i]["affiliation_list"]);
   $zoom_icon = ($posters[$i]["zoom_link"] != "0") ? "<a href='".$posters[$i]["zoom_link"] . "' target='_blank'><img src='images/zoom_icon.png'/></a>" : "";
   $poster_icon = ($posters[$i]["img_file"] != "0") ? "<a href='".$posters[$i]["img_file"] . "' target='_blank'><img src='images/poster_icon.png'/></a>" : "";
   
   if ($cur_category != $posters[$i]["category"]) {
       $table_data .= "<tr><td colspan='4'><a name='".$posters[$i]["category"]."'></a>&nbsp;</td></tr>
                   <tr style='background-color: " .category_color($posters[$i]["category"]).";'>
                     <td colspan='4' style='padding: 5px; font-weight: bold; font-size:18px;'>".$posters[$i]["category"]."</td>
                   </tr><tr><td colspan='4'>&nbsp;</td></tr>";
       $cur_category = $posters[$i]["category"];
   }
   
   $table_data .= "<tr style='background-color: $color'>
                     <td height='32px'>$poster_icon $zoom_icon</td>
                     <td>".$posters[$i]["num"]."&nbsp;</td>
                     <td><a href='mailto:".$posters[$i]["email"]."'>".$posters[$i]["fname"]." " .$posters[$i]["lname"] ."</a>&nbsp;</td>
                     <td><a href='#!' onclick=\"toggle_abstract('".$posters[$i]["num"]."_abstract')\"><b>".$posters[$i]["title"]."</b></a></td>
                   </tr>
                   <tr style='background-color: $color;'>
                     <td colspan='4' style='padding: 5px'><div id='".$posters[$i]["num"]. "_abstract' style='display: none;'><small>Full author list: <br>$authors<br>$affiliations<br><br></small>".$posters[$i]["abstract"]."<br><br></div></td>
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
 
<h4>Welcome to the Virtual 2020 Maize Meeting Poster Wall!</h4>

This virtual poster wall will be made available from <b>June 22nd - July 3rd</b> to all registrants of the 2020 Virtual Maize Genetics Meeting. Poster abstracts and images (if present) can be viewed at any time during and outside the scheduled poster session. The zoom links were provided by the poster presenters, and will only be active during the designated timeslot as indicated by the poster schedule:<br><br> 

<b>Thursday June 25th from 2:30pm - 3:30pm PDT</b> - <a href="https://www.timeanddate.com/worldclock/fixedtime.html?msg=2020+Maize+Genetics+Meeting+-Poster+Session+I+June+25&iso=20200625T1430&p1=202&ah=1" target="_blank">check local time here</a><br>
<i>Even posters 2:30pm - 3:00pm</i><br>
<i>Odd posters 3:00pm - 3:30pm</i><br><br>

<b>Friday June 26th from 12:30pm - 1:30pm PDT</b> - <a href="https://www.timeanddate.com/worldclock/fixedtime.html?msg=2020+Maize+Genetics+Meeting+-Poster+Session+II+June+26&iso=20200626T1230&p1=202&ah=1" target="_blank">check local time here</a><br>
<i>Even posters 12:30pm - 1:00pm</i><br>
<i>Odd posters 1:00pm - 1:30pm</i><br><br>

If you are a poster presenter who has submitted a zoom link, then please plan on starting your zoom session at the appropriate time indicated above.  <br><br>

<center><u><a href="v2020Schedule.pdf" target="_blank"><b>Download the full meeting schedule</b></a></u>&nbsp;&nbsp;&nbsp;<u><a href="posters/files/VMM_2020_Registrant_List.pdf" target="_blank"><b>Download the attendee list</b></a></u></center><br><br>

<center><table><tr><td valign="center"><a href="videos.php" target="_blank"><img src="images/video_icon.png"/></a></td><td valign="center"><a href="videos.php" target="_blank"><b>View the recorded talks</b></a>&nbsp;&nbsp;&nbsp;</td></tr></table></center><br><br>



<!--<b>Talks with posters</b>
<table style="border-collapse: collapse">
  <tr>
    <th width="75px"></th><th></th><th align="left"></th><th align="left"></th>
  </tr>
  <?php //echo $talk_table_data; ?>
</table> <br><br>-->

<b>Jump to poster category: <br>
<table><tr>
<td><a href="#Cytogenetics" style="color:seagreen"><b>Cytogenetics</b></a>&nbsp;&nbsp;&nbsp;</td><td><a href="#Education & Outreach" style="color:crimson"><b>Education & Outreach</b></a>&nbsp;&nbsp;&nbsp;<td><a href="#Quantitative Genetics & Breeding" style="color:darkgoldenrod"><b>Quantitative Genetics & Breeding</b></a>&nbsp;&nbsp;&nbsp;<td><a href="#Transposons & Epigenetics" style="color:royalblue"><b>Transposons & Epigenetics</b></a>&nbsp;&nbsp;&nbsp;</tr><tr><td><a href="#Biochemical and Molecular Genetics" style="color:steelblue"><b>Biochemical and Molecular Genetics</b></a>&nbsp;&nbsp;&nbsp;<td><a href="#Cell and Developmental Biology" style="color:darkslategray"><b>Cell and Developmental Biology</b></a>&nbsp;&nbsp;&nbsp;<td><a href="#Computational and Large-Scale Biology" style="color:indigo"><b>Computational and Large-Scale Biology</b></a>&nbsp;&nbsp;&nbsp;</td></tr></table></b> <br><br>

<table style="border-collapse: collapse">
  <tr>
    <th width="75px"></th><th></th><th align="left"></th><th align="left"></th>
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