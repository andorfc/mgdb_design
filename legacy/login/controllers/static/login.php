<?php
/* file: login.php
 *
 * purpose: log in a user
 *
 * history:
 *  05/14/12  eksc  modified for current bauplan and PostgreSQL.
 *  10/24/19  eksc  added remove log-in
 */

  include_once('login_functions.php');

  if (getCGIParam('code', 'P', '') == 'mgdb') {
    // remote login
    // false, true: no relaxed login, remote login
    $user = doLogIn(false, true);

    if (isset($user['approved']) && $user['approved'] == 'yes') {
       echo json_encode(array('log-in_status'=>'true'));
    }
    else {
      echo json_encode(array('log-in_status'=>'false'));
    }
    exit;
  }
  
  $bauplan->includeCss('css/login.css');
  
  if (!$user_info = doLogIn()) {
    // Haven't tried logging in yet: show log in form
    $tmpl = $mgdb->get('body')->load('templates/static/login.bau');
    $tmpl->get('login-handler')->replace('/login');
  }
  else {
    if (isset($user_info['error']) && $user_info['error'] != '') {
      $tmpl = $mgdb->get('body')->load('templates/static/login.bau');   
      $tmpl->get('login-handler')->replace('/login');
      $tmpl->get('message')->replace($user_info['error']);
    }
    else if ($user_info['approved'] == 'no') {
      $bauplan->includeScript('/js/api_js.js'); // needed for feedback popup
      $tmpl = $mgdb->get('body')->load('templates/curation/login-needed.bau');   
      $tmpl->get('login-handler')->replace('/login');
      $tmpl->get('message')->replace($user_info['error']);
      $tmpl->get('not-approved')->unmute();
    }
    else {
      $mgdb->get('body')->load('templates/static/login_correct.bau');
      
      $date_to_die = date("h:i A, M d, Y", $user_info['time_to_die']);
   
      $l_status = "<p>You are logged in until " . $date_to_die;
      $l_status .= ".  Visit the <b><a href=\"" . $SITE_URL;
      $l_status .= "/\">MaizeGDB homepage</a></b> to begin!</p>";
  
      $login_left_correct = $mgdb->get('login_correct')->get('login-left');
      $login_left_correct->get('login_name')->replace($user_info['username']);
      $login_left_correct->get('login_status')->replace($l_status);
      
      // Set login area in main template
      $mgdb->get('logout')->unmute();
      $mgdb->get('username')->replace($user_info['username']);
    }//successful log in
  }//tried logging in
  
  include('translation.php');
?>