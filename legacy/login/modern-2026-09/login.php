<?php
/* file: login.php  (top-level shadow controller)
 *
 * purpose: /login -- community-curator login, on the design system.
 *
 * `/login` used to fall through controller.php to redirect.php, which loaded the
 * legacy main template and its chrome before running
 * controllers/static/login.php. controller.php checks controllers/<CONTROLLER>.php
 * first, so adding this top-level file takes the route with a clean modern shell
 * and no legacy stylesheets leaking in. Deleting it gives the route straight back
 * to the legacy controller, which is untouched. The originals are archived in
 * legacy/login/.
 *
 * Security (2026-09-06):
 *   - the account lookup is a parameterised query (see login_functions.php),
 *     closing the SQL injection the legacy form and `username` cookie carried;
 *   - a successful login writes a signed HttpOnly/Secure/SameSite session token,
 *     not the plaintext password, into the auth cookie (see gp_lib.php);
 *   - the password is only ever verified against the account's bcrypt hash.
 *
 * Everything the login *mechanism* does lives in
 * controllers/static/login_functions.php and include/gp_lib.php; this file is
 * presentation and flow only.
 */

  include_once('./include/gp_lib.php');
  include_once($system['root_dir'] . '/controllers/static/login_functions.php');

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/login.php (modern curator login)');

  /* ---------------------------------------------------------------------- *
   * Remote login endpoint (unchanged contract): POST code=mgdb with a
   * username/password returns a small JSON object. Verifies credentials but
   * sets no cookie. Answered before any HTML shell is built.
   * ---------------------------------------------------------------------- */
  if (getCGIParam('code', 'P', '') == 'mgdb') {
    $user = doLogIn(false, true);   // no relaxed login, remote login
    header('Content-Type: application/json');
    $ok = isset($user['approved']) && $user['approved'] == 'yes';
    echo json_encode(array('log-in_status' => $ok ? 'true' : 'false'));
    exit;
  }

  /* ---------------------------------------------------------------------- *
   * Resolve login state.
   * ---------------------------------------------------------------------- */
  $user_info = doLogIn();

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
            ? $_SERVER['DOCUMENT_ROOT'] : $system['root_dir'];

  $esc = function ($text) {
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
  };

  /* ---------------------------------------------------------------------- *
   * Modern shell.
   * ---------------------------------------------------------------------- */
  $bauplan = new Bauplan('Curator login | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');

  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-login.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-login.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->head('<meta name="description" content="Log in to add and edit community annotations on MaizeGDB records. For approved community curators.">');
  // A login page should never be cached by a shared proxy.
  $bauplan->head('<meta name="robots" content="noindex">');
  header('Cache-Control: no-store');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_login.bau');

  if ($user_info && empty($user_info['error']) && isset($user_info['approved'])
      && $user_info['approved'] == 'yes') {
    /* Logged in. */
    $body->get('logged-in')->unmute();
    $body->get('login_name')->replace($esc($user_info['username']));

    if (!empty($user_info['time_to_die'])) {
      $when = date('h:i A, M d, Y', (int) $user_info['time_to_die']);
      $status = '<p>Your session is valid until ' . $esc($when) . '.</p>';
    } else {
      $status = '<p>Visit the MaizeGDB homepage to begin.</p>';
    }
    $body->get('login_status')->replace($status);
  }
  else {
    /* Show the form. */
    $body->get('logged-out')->unmute();
    $body->get('login-handler')->replace('/login');

    if ($user_info && !empty($user_info['error'])) {
      $body->get('message')->unmute();
      $body->get('message-text')->replace($esc($user_info['error']));
      $body->get('username')->replace($esc(getCGIParam('username', 'P', '')));
    }
    else if ($user_info && isset($user_info['approved']) && $user_info['approved'] == 'no') {
      $body->get('not-approved')->unmute();
      $body->get('username')->replace($esc(getCGIParam('username', 'P', '')));
    }
  }

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);
  $bauplan->publish();
  exit;
?>
