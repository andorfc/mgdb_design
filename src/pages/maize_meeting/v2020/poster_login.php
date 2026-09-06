<?PHP

include_once("../../include/gp_lib.php");
include_once("conf.php");
$invalid_password = false;


$password = getCGIParam('v2020_password', 'P', false);
$dest = getCGIParam('dest', 'GP', false);
$location = ($dest == "videos") ? "/maize_meeting/v2020/videos.php" : "/maize_meeting/v2020/posters.php";

//jp July 4 2020 -- The poster and video wall is now disabled. Don't try to get a password from cookies
/*
if (!$password) {
  $password = getCookie('v2020_password', false);
}
*/

if (validate_password($password)) {
    //Password is valid, proceed to poster wall
    $flush = setcookie("v2020_password", $password, (time() + 315360000));
    // Stop here, or the login page is rendered into the body of its own redirect.
    header('Location: ' . $location);
    exit;
}
else if ($password) {
    //A supplied password was invalid
    $invalid_password = true;
}

//If user is not redirected to poster wall, then display login mage

include("mainheader_noimg.php");

?>
<table width="900">
<tr>
<td width="72%" valign="top">
<h2>Welcome to the Virtual 2020 Maize Meeting!</h2>
<!--<center><p> <a href="2018Book.pdf">Download Abstract Book</a> &nbsp;&nbsp;-->
</p>

<div style="color: crimson; font-weight: bold;">The poster and video pages have been closed. We'd like to thank everyone who participated in the 2020 Maize Genetics Meeting!</div><br>
</center>


 
<h4>Poster Wall / Video Login:</h4>



<form id="password_form" action="poster_login.php" name="password_form" method="post">
<input type="hidden" name="dest" value="<?php echo $dest; ?>"/>
<table><tr><td>
Enter Password: <input type="text" name="v2020_password" id="v2020_password" disabled/> &nbsp;</td><td class="bmiddle">
<a href="#!" onclick="document.getElementById('password_form').submit()">Log in!</a></td>
</tr>
</table>
</form>
<?php
  if ($invalid_password) {
?>
  <div style="color: crimson; font-weight: bold;">The password you have entered is invalid. If you believe you reached this message in error then contact us through the LiveChat tool or email John Portwood at <a href="mailto:john.portwood@usda.gov">john.portwood@usda.gov</a>.
  <!--<div style="color: crimson; font-weight: bold;">The password you have entered is invalid. If you believe you reached this message in error then contact us through the LiveChat tool or email John Portwood at <a href="mailto:john.portwood@usda.gov">john.portwood@usda.gov</a>.-->
<?php
  }
?>

All registrants of the 2020 virtual maize meeting received an email with a password to log in.



</td >
<?PHP

include("sidebar.php");

?>
<?PHP
include("footer.php");

?>