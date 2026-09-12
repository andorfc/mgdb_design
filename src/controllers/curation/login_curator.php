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
 *  2026-09-06  security hardening (redesign):
 *    - the forwarding URL and every reflected POST value are now escaped before
 *      they go back into the page. This form echoed `nexturl` and arbitrary POST
 *      keys/values into HTML unescaped -- a reflected-XSS and open-redirect
 *      surface reachable by posting bad credentials with extra parameters;
 *    - `nexturl` is constrained to a same-site absolute path, so the auto-submit
 *      cannot be pointed at another origin;
 *    - undefined-key reads on the doLogIn() result are guarded.
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
    if (!empty($user_info['error'])) {
      $tmpl = $mgdb->get('body')->load('templates/curation/login-needed.bau');
      $tmpl->get('login-handler')->replace('/curation/login_curator');
      if ($relaxed_login) {
        $tmpl->get('relaxed_login')->replace('yes');
      }
      $tmpl->get('message')->replace(loginCuratorEsc($user_info['error']));
      $tmpl->get('username')->replace(loginCuratorEsc(isset($user_info['username']) ? $user_info['username'] : ''));
      $tmpl->get('curation_params')->loop(getPostVars());
      $tmpl->get('curation_params')->unmute();
      $tmpl->get('forwarding-url')->unmute();
    }
    else if (isset($user_info['approved']) && $user_info['approved'] == 'no') {
      $bauplan->includeScript('/js/api_js.js'); // needed for feedback popup
      $tmpl = $mgdb->get('body')->load('templates/curation/login-needed.bau');
      $tmpl->get('login-handler')->replace('/curation/login_curator');
      $tmpl->get('not-approved')->unmute();
      $tmpl->get('message')->replace(loginCuratorEsc(isset($user_info['error']) ? $user_info['error'] : ''));
      $tmpl->get('username')->replace(loginCuratorEsc(isset($user_info['username']) ? $user_info['username'] : ''));
      $tmpl->get('curation_params')->loop(getPostVars());
      $tmpl->get('curation_params')->unmute();
      $tmpl->get('forwarding-url')->unmute();
    }
    else {
      // Will load intended URL. The target is constrained to a same-site path so
      // the auto-submitting form cannot be aimed at another origin.
      $nexturl = loginCuratorSafeNextUrl(getCGIParam('nexturl', 'P', '/'));
      logMessage("pass through to " . $nexturl);
      $tmpl = $mgdb->get('body')->load('templates/login-passthrough.bau');
      $tmpl->get('nexturl')->replace(loginCuratorEsc($nexturl));
      $tmpl->get('params')->loop(getPostVars());
    }//successful log in
  }//tried logging in


  function loginCuratorEsc($text) {
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
  }

  /* A same-site absolute path only: one leading slash, no scheme, no protocol-
     relative "//host", and no CR/LF that could split the value. Anything else
     falls back to the site root. */
  function loginCuratorSafeNextUrl($url) {
    $url = str_replace(array("\r", "\n", "\0"), '', (string) $url);
    if ($url === '' || $url[0] !== '/' || (isset($url[1]) && $url[1] === '/')) {
      return '/';
    }
    return $url;
  }

  function getPostVars() {
    // Move all POST variables to an array, except for the log in fields. Both
    // the key and the value are escaped: they are reflected straight into HTML
    // attributes, and a form field name is HTML-safe once escaped (the browser
    // decodes it back on submit).
    unset($_POST['username']);
    unset($_POST['password']);
    unset($_POST['length']);
    unset($_POST['nexturl']);
    $params = array();
    foreach (array_keys($_POST) as $key) {
      if (is_array($_POST[$key])) {
        continue; // the forwarding form carries scalar fields only
      }
      $params[] = array(
        'key'   => loginCuratorEsc($key),
        'value' => loginCuratorEsc($_POST[$key]),
      );
    }

    return $params;
  }
?>
