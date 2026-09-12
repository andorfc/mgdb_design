<?PHP
/*
 * file: logout.php
 *
 * purpose: log out a user
 */
 
  include_once('./include/gp_lib.php');

  $bauplan->includeCss('css/login.css');
 
  $length = getCGIParam('length', 'P', 0);
  $flush  = settype($length, "integer");

  $flush = setcookie("username","",(time() - 315360000),"/","maizegdb.org");
  $flush = setcookie("password","",(time() - 315360000),"/","maizegdb.org");
  $flush = setcookie("userid","",(time() - 315360000),"/","maizegdb.org");
  
  $mgdb->get('body')->load('templates/static/logout.bau');
 
  // Set login area in main template
  $mgdb->get('logout')->toggle();
  $mgdb->get('username')->replace($username);
  
  include('translation.php');
?>