<?php
/* file: login_functions.php
 *
 * purpose: functionality related to logging in/out users that is needed by
 *          more than one script.
 *
 * history:
 *   07/31/12  eksc  created from existing code
 */

include_once('./include/db-api.php');
include_once('./include/gp_lib.php');

function doLogIn($relaxed_login=false, $remote_login=false) {

  $user_info = false;
  $time_to_die = 0;
  
  $length = getCGIParam('length', 'P', 0);
  $flush  = settype($length, 'integer');
  
  $username = getCGIParam('username', 'P', false);
  $password = getCGIParam('password', 'P', false);
  $userid   = getCGIParam('userid', 'P', false);
  
  if (!$username && !$remote_login) {
    $username = getCookie('username', false);
    $password = getCookie('password', false);
    $userid   = getCookie('userid', false);
  }

  if ($username) {
    $DBConn = connect_to_database(false);
    $query_user = "
      SELECT id, username, mgdb_pw_hash, curation_lvl
      FROM annotation_author 
      WHERE username = '$username'";
    
    $stmt_user = make_query($DBConn, $query_user, 1);
    $arrUser = retrieve_row($stmt_user);
    $authenticated = password_verify($password, $arrUser['mgdb_pw_hash']);
    if ($arrUser && $authenticated) {
      $correct_user = $arrUser['id'];
      
      $flush = settype($correct_user, 'integer');
      $approved_curator = ($relaxed_login || $arrUser['curation_lvl'] < 1);
      
      if ($correct_user > 0 && $username != '' && $password != '') {
        // user logged in ...
        if (!$approved_curator) {
          // ... but not approved
          $user_info = array(
            'approved' => 'no'
          );
          $flush = setcookie('approved', 'no', (time() + 315360000), '/', 'maizegdb.org');
        }
        else {
          // ...and approved
          if (!$remote_login) {
            $time_to_die = time() + $length;
            if ($length < 1) {
              $flush = setcookie("username", $arrUser['username'], (time() + 315360000), 
                                 "/", "maizegdb.org");
              $flush = setcookie("password", $password, (time() + 315360000), 
                                 "/", "maizegdb.org");
              $flush = setcookie("userid", $arrUser['id'], (time() + 315360000), "/", 
                                 "maizegdb.org");
              $time_to_die = time() + 31536000;
            }
            else {
              $flush = setcookie("username", $arrUser['username'], $time_to_die, "/",
                                 "maizegdb.org");
              $flush = setcookie("password", $password, $time_to_die, "/",
                                 "maizegdb.org");
              $flush = setcookie("userid", $arrUser['id'], $time_to_die, "/",
                                 "maizegdb.org");
            }
          
            $date_to_die = date("h:i A, M d, Y", $time_to_die);
          }
          
          $user_info = array(
                'approved'    => 'yes',
                'username'    => $username,
                'password'    => $password,
                'userid'      => $userid,
                'time_to_die' => $time_to_die,
          );
        }//user logged in correctly and is approved for community curation tools
      }
    }//authenticated
    
    if (!$user_info) {
      $user_info = array('error' => "The username or password is incorrect.");
    }
  }//user tried to log in

  return $user_info;
}//doLogIn
?>