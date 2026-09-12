<?php
/* file: login_curator.php
 *
 * purpose: log in a user, specific to curation tools.
 *
 *          Differences from login.php:
 *            gathers up POST data and sends it to a form that forwards to the
 *            intended page.
 *
 * history:
 *  07/30/12  eksc  modified from /static/login.php for curation tools.
 */
  
  include_once($system['root_dir'] . '/controllers/static/login_functions.php');

  // Potentially temporary setting to allow people to register then use
  //   curation tools immediately:
  $relaxed_login = false;

  if (!$user_info = doLogIn($relaxed_login)) {
    // Haven't tried logging in yet: show log in form
    $tmpl = $mgdb->get('body')->load('templates/curation/login-needed.bau');
    $tmpl->get('login-handler')->replace('/curation/login_curator');
    if ($relaxed_login) {
      $tmpl->get('relaxed_login')->replace('yes');
    }
    $tmpl->get('curation_params')->loop(getPostVars());
    $tmpl->get('curation_params')->unmute();
    $tmpl->get('forwarding-url')->unmute();
  }
  else {
    if ($user_info['error']) {
      $tmpl = $mgdb->get('body')->load('templates/curation/login-needed.bau');   
      $tmpl->get('login-handler')->replace('/curation/login_curator');
      if ($relaxed_login) {
        $tmpl->get('relaxed_login')->replace('yes');
      }
      $tmpl->get('message')->replace($user_info['error']);
      $tmpl->get('username')->replace($user_info['username']);
      $tmpl->get('curation_params')->loop(getPostVars());
      $tmpl->get('curation_params')->unmute();
      $tmpl->get('forwarding-url')->unmute();
    }
    else if ($user_info['approved'] == 'no') {
      $bauplan->includeScript('/js/api_js.js'); // needed for feedback popup
      $tmpl = $mgdb->get('body')->load('templates/curation/login-needed.bau');   
      $tmpl->get('login-handler')->replace('/curation/login_curator');
      $tmpl->get('not-approved')->unmute();
      $tmpl->get('message')->replace($user_info['error']);
      $tmpl->get('username')->replace($user_info['username']);
      $tmpl->get('curation_params')->loop(getPostVars());
      $tmpl->get('curation_params')->unmute();
      $tmpl->get('forwarding-url')->unmute();
    }
    else {
logMessage("pass through to " . $_POST['nexturl']);
      // Will load intended URL
      $tmpl = $mgdb->get('body')->load('templates/login-passthrough.bau');
      $tmpl->get('nexturl')->replace($_POST['nexturl']);
      $tmpl->get('params')->loop(getPostVars());
    }//successful log in
  }//tried logging in
  
  
  function getPostVars() {
    // Move all POST variables to an array, except for the log in fields
    unset($_POST['username']);
    unset($_POST['password']);
    unset($_POST['length']);
    $params = array();
    foreach (array_keys($_POST) as $key) {
      $pair = array('key' => $key, 'value' => $_POST[$key]);
      array_push($params, $pair);
    }
    
    return $params;
  }
?>