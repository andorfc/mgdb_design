<?php
/* file: forgot_password.php
 *
 * purpose: send password to user
 *
 * history:
 *  05/14/12  eksc  created for v2 site from annotation_password_reminder.cgi
 */
 
  include_once('./include/gp_lib.php');
  include_once('./include/mail.php');

  $username = getCookie('username', false);
  $password = getCookie('password', false);
  $userid   = getCookie('userid', false);
 
  $term = getCGIParam('term', 'P', false);

  $bauplan->includeCss('css/login.css');
  
  $mgdb->get('body')->load('templates/static/forgot-password.bau');
  
  include('translation.php');
  
  if ($term) {
    $DBConn = connect_to_database(false);
    /* Bound, not concatenated. This query used to paste the posted term
       straight between quotes, so `term=x'` produced SQLSTATE[42601] and
       anything else the attacker wrote ran -- against the table holding
       usernames, e-mail addresses and password reminders. make_query()'s
       fourth argument is PDO's bound-parameter list. Fixed 2026-09-05.

       The `echo $query;` that stood here printed the statement to the browser
       on every submission, which handed a reader the schema and, with the
       injection above, the output of whatever they appended. Removed. */
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
      $correct_user = $arrUser["id"];
      $flush = settype($correct_user, "integer");
      if ($correct_user > 0) {
        $message = $arrUser["first_name"] . " " . $arrUser["last_name"];
        $message .= ",\n\n";
        $message .= "Here is the password hint for your account. ";
        $message .= "As a reminder, the username for this account is ";
        $message .= $arrUser["username"] . "\n\n";
        $message .= "Password hint:\n" . $arrUser["password_reminder"];
        $message .= "\n\nIf you have additional problems accessing your ";
        $message .= "account, don't hesitate to contact the MaizeGDB team by ";
        $message .= "replying to this message.\n\n";
        $message .= "Sincerely yours,\nThe MaizeGDB Team";
// eksc- use send_email()
//        mail($arrUser["EMAIL"], 
//             "Your MaizeGDB Annotation Account Password Reminder", 
//             $message,
//             "From: maizegdb_support@iastate.edu\r\n"
//             ."Reply-To: darwin.campbell@ars.usda.gov\r\n"
//             ."X-Mailer: MaizeGDB");
        send_email($arrUser['email'], 
                   'maizegdb_support@iastate.edu',
                   'Your MaizeGDB Annotation Account Password Reminder',
                   $message);
        
        $mgdb->get('enter-info')->mute();
        $mgdb->get('failure')->mute();
        
        $mgdb->get('account')->replace($arrUser['username']);
        $mgdb->get('email')->replace($arrUser['email']);
        $mgdb->get('success')->unmute();
      }
      else {
        $mgdb->get('failure')->unmute();
      }
    }#found the record
    else {
      $mgdb->get('failure')->unmute();
    }
  }//term entered
  else {
    $mgdb->get('enter-info')->unmute();
  }//first in
?>