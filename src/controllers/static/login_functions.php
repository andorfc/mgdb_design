<?php
/* file: login_functions.php
 *
 * purpose: functionality related to logging in/out users that is needed by
 *          more than one script.
 *
 * history:
 *   07/31/12  eksc  created from existing code
 *   2026-09-06  security hardening (redesign):
 *     - the username lookup is now a parameterised query, closing an SQL
 *       injection reachable from the login form and from the `username` cookie;
 *     - a successful login no longer writes the *plaintext password* into a
 *       cookie. It writes a signed, HttpOnly/Secure/SameSite session token
 *       instead (see mgdbSetAuthCookies / mgdbMintSessionToken in gp_lib.php).
 *       The password is verified against the bcrypt hash and then discarded.
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

  /*
   * No password posted: this is a returning visitor. Authenticate from the
   * signed session token in the `password` cookie rather than from a stored
   * password. A forged `username`/`userid` cookie proves nothing -- identity
   * comes out of the verified token.
   */
  if (!$password && !$remote_login) {
    $session = mgdbSessionUser();
    if (!$session) {
      return false;   // not logged in; caller shows the form
    }
    $username = $session['username'];

    $DBConn = connect_to_database(false);
    $arrUser = lookupAuthorByUsername($DBConn, $username);
    if (!$arrUser) {
      // Account has gone away since the token was minted.
      return false;
    }

    // A curator whose level dropped below "approved" loses access at once,
    // even with a still-valid token.
    if ($arrUser['curation_lvl'] >= 1) {
      return array('approved' => 'no', 'error' => '');
    }

    return array(
      'approved'    => 'yes',
      'username'    => $arrUser['username'],
      'userid'      => $arrUser['id'],
      'time_to_die' => 0,
    );
  }

  if ($username) {
    $DBConn = connect_to_database(false);
    $arrUser = lookupAuthorByUsername($DBConn, $username);

    // password_verify safely returns false when the row (and thus the hash) is
    // missing, so a bad username and a bad password fail the same way.
    $authenticated = $arrUser
                   && password_verify((string) $password, (string) $arrUser['mgdb_pw_hash']);

    if ($authenticated) {
      $correct_user = (int) $arrUser['id'];
      $approved_curator = ($relaxed_login || $arrUser['curation_lvl'] < 1);

      if ($correct_user > 0 && $username != '' && $password != '') {
        if (!$approved_curator) {
          // Logged in correctly but not approved for community curation.
          $user_info = array('approved' => 'no');
        }
        else {
          if (!$remote_login) {
            // A chosen session length caps the token; "1 Year" (length < 1)
            // uses the long-lived default.
            $lifetime = ($length < 1) ? 31536000 : $length;
            mgdbSetAuthCookies($arrUser['id'], $arrUser['username'], $lifetime);
            $time_to_die = time() + $lifetime;
          }

          $user_info = array(
            'approved'    => 'yes',
            'username'    => $arrUser['username'],
            'userid'      => $arrUser['id'],
            'time_to_die' => $time_to_die,
          );
        }//approved for community curation
      }
    }//authenticated

    if (!$user_info) {
      $user_info = array('error' => "The username or password is incorrect.");
    }
  }//user tried to log in

  return $user_info;
}//doLogIn


/*
 * The one account lookup, parameterised. Returns the row or false. Keeping it
 * in one function means the login path and the token-refresh path cannot drift
 * into two different (and differently safe) queries.
 */
function lookupAuthorByUsername($DBConn, $username) {
  $query = "
    SELECT id, username, mgdb_pw_hash, curation_lvl
    FROM annotation_author
    WHERE username = ?";
  $stmt = make_query($DBConn, $query, 1, array($username));
  return retrieve_row($stmt);
}
?>
