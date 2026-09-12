<?php
/* file: forgot_password.php  (top-level shadow controller)
 *
 * purpose: /forgot_password -- the curator account password-hint reminder, on
 *          the design system.
 *
 * `/forgot_password` used to fall through controller.php to redirect.php, which
 * loads the legacy main template and its chrome before running
 * controllers/static/forgot_password.php. controller.php checks
 * controllers/<CONTROLLER>.php first, so this file takes the route with a clean
 * modern shell. Deleting it hands the route straight back to the legacy
 * controller, which is untouched.
 *
 * The lookup and the mail are the hardened ones from that controller, carried
 * over unchanged in substance: the query is parameterised (it used to paste the
 * posted term between quotes, against the table holding usernames, e-mail
 * addresses and password hints), and the `echo $query;` that printed the
 * statement to the browser on every submission is long gone.
 *
 * Two behaviour changes, both deliberate:
 *
 *   1. The page said "the password will be mailed to you". It never mailed a
 *      password -- it mails PASSWORD_REMINDER, the hint, and the message itself
 *      says "Here is the password hint for your account." The page now says what
 *      it does.
 *
 *   2. The answer is the same whether or not an account matched. The old page
 *      replied "No Matching Account" on a miss, which let anyone test an e-mail
 *      address against the curator roster one submission at a time. The mail is
 *      still sent only when there is an account to send it to.
 *
 * history
 *  09/07/26  claude  created
 */

  include_once('./include/gp_lib.php');
  include_once('./include/db-api.php');
  include_once('./include/mail.php');

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/forgot_password.php');

  $term = trim((string) getCGIParam('term', 'P', ''));
  $submitted = ($term !== '');

  if ($submitted) {
    $DBConn = connect_to_database(false);

    /* Bound, not concatenated. make_query()'s fourth argument is PDO's bound
       parameter list. */
    $query = "
      SELECT ID, USERNAME, EMAIL, FIRST_NAME, LAST_NAME,
             PASSWORD_REMINDER
      FROM ANNOTATION_AUTHOR
      WHERE LOWER(USERNAME) LIKE ?
            OR LOWER(EMAIL) LIKE ?";
    $term_pattern = strtolower($term);
    $stmt = make_query($DBConn, $query, 1, array($term_pattern, $term_pattern));

    if ($stmt) {
      $arrUser = retrieve_row($stmt);
      if ($arrUser && (int) $arrUser['id'] > 0) {
        $message  = $arrUser['first_name'] . ' ' . $arrUser['last_name'] . ",\n\n";
        $message .= "Here is the password hint for your account. ";
        $message .= "As a reminder, the username for this account is ";
        $message .= $arrUser['username'] . "\n\n";
        $message .= "Password hint:\n" . $arrUser['password_reminder'];
        $message .= "\n\nIf you have additional problems accessing your ";
        $message .= "account, don't hesitate to contact the MaizeGDB team by ";
        $message .= "replying to this message.\n\n";
        $message .= "Sincerely yours,\nThe MaizeGDB Team";

        send_email($arrUser['email'],
                   'maizegdb_support@iastate.edu',
                   'Your MaizeGDB Annotation Account Password Reminder',
                   $message);
      }
      /* No else. A miss is not reported -- see the note at the top of this
         file -- and it is not logged with the term either, because that would
         put attempted addresses in a file. */
    }
  }

  /* ---------------------------------------------------------------------- */

  $bauplan = new Bauplan('Password reminder | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . (int) @filemtime($system['root_dir'] . '/css/mgdb-hub.css'));
  $bauplan->includeCss('/css/mgdb-forgot-password.css?v=' . (int) @filemtime($system['root_dir'] . '/css/mgdb-forgot-password.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  /* A password-reminder form has no business in a search index. */
  $bauplan->head('<meta name="robots" content="noindex">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_forgot_password.bau');

  if ($submitted) {
    $body->get('sent')->unmute();
    $body->get('form')->mute();
  }

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
?>
