<?PHP
/* file: feedback.php
 *
 * purpose: Sends feedback emails when user using the feedback menu item
 *
 *
 * history:
 *   7/13/2011 andorf - Made it function.
 *   9/24/2012  eksc  - Will also work as stand alone form in a popup.
 *  11/21/2012  eksc  - Does an "anti-turing-test" for human entry
 */
 
  include_once('./include/gp_lib.php');
  include_once('./lib/Bauplan.php');
  include_once('./include/mail.php');
 
  $system = getSystemInfo('mgdb.conf');
 
  $name         = getCGIParam('name', 'P', false);
  $subject      = getCGIParam('subject', 'GP', 'Feedback');
  $message      = getCGIParam('message', 'P', false);
  $email        = getCGIParam('email', 'P', false);
  $caller       = getCGIParam('caller', 'P', false);
  $sendto       = getCGIParam('sendto', 'GP', '');
  $instructions = getCGIParam('instructions', 'GP', '');

  if (!$name && !$message && !$email && !$caller) {
    // No feedback fields set: display feedback form
    $bauplan = new Bauplan('MaizeGDB Feedback');
    $doctype = "
<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Transitional//EN\" 
 \"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd\">";
  $bauplan->preHTML($doctype);
  
  $head = "
<!--[if IE 6]>
  <link rel=\"stylesheet\" href=\"/ie/ie6.css\" type=\"text/css\" media=\"screen\" />
<![endif]-->
<!--[if lt IE 9]>
  <link rel=\"stylesheet\" type=\"text/css\" href=\"/ie/ie.css\" />
<![endif]-->";
    $bauplan->head($head);
    $tmpl = $bauplan->template()->load('templates/static/feedback-popup.bau');

    // hide link (required when feedback is part of the megamenu)
    $subtmpl = $tmpl->get('feedback');
    $subtmpl->get('feedback_link')->mute();

    $tmpl->get('subject')->replace($subject);
    $tmpl->get('instructions')->replace($instructions);
    $tmpl->get('sendto')->replace($sendto);
  }
  
  else {
    // Make sure this came from a human and not a bot
    $turingtest   = getCGIParam('turingtest', 'P', false);
    if (!$turingtest || $turingtest != '18') {
      reportError("bot attack: [$turingtest]");
      $mgdb->get('body')->load('templates/static/feedback-error.bau');
      $err = "You did not answer the question correctly";
      $mgdb->get('error')->replace($err);
      $bauplan->publish();
      exit;
    }
  
    // Handle feedback
    $subject = "[MGDB-FEEDBACK] Feedback about $caller";
    $subject_rt = "Feedback about $caller";
    $message = stripslashes($message);
  
    if ($head=getCGIParam('head', 'P', false))
    {
      $link1 = getCGIParam('uri', 'P', '');
      $link2 = "";
    
      $string = stripslashes($head);
      $count = 0;
      $tok = strtok($string, " ");
      $toka[$count] = $tok;
      while ($tok !== false) {
        $count++;
        $tok = strtok(" ");
        $toka[$count] = $tok;
      }
    
      $start = $toka[6];
      $end = $toka[8];
      
      $chrom = strtok($toka[4], ",");
    
      if (strpos($link1,"bac") > 0) {
        $link2 = $system['GBROWSE_URL_BAC'] . "?name=$chrom:$start..$end";
      } 
      else if (strpos($link1, "gbrowse") > 0) {
        if (strpos($link1,"_v2") > 0) {
          $link2 = $system['GBROWSE_URL_V2'] . "?name=$chrom:$start..$end";
        } 
        else if(strpos($link1,"_v3") > 0) {
          $link2 = $system['GBROWSE_URL_v3'] . "?name=$chrom:$start..$end";
        } 
        else {
          // Assume V1
          $link2 = $system['GBROWSE_URL_V1'] . "?name=$chrom:$start..$end";
        }
      }
    
      $message = "URL: $link1\n\nDirect Link: $link2\n\n$message";
    } 
    else {
      $message = "URL: " . stripslashes($caller) . "\n\n$message";
    }
    
    $curtime = time();
    $message = $message . "\n\nThis message was sent at " . date("h:i A",$curtime) 
             . " on " . date("F d, Y",$curtime) . ".\n";
    $email = $_POST["email"];
    $message .= "\n\nFrom: $name ($email)";
  
    // This is a safe email function, much better than using some sendmail 
    // exec trick.
  
    if (strlen(trim($_POST["message"])) > 4) {
      if ($sendto != '') {
        // A particular send-to e-mail specified
        send_email($sendto, 'admin@maizegdb.org', $subject, $message);
      }
      
      else {
        // Send to usual recipient(s)
        send_email('carson.andorf@gmail.com', 'admin@maizegdb.org', $subject, $message);
        send_email('portwoodii@gmail.com', 'admin@maizegdb.org', $subject, $message);
        $message = "Here is a copy of your feedback message to MaizeGDB.\n\n"
                   . $message;
        send_email($email, 'admin@maizegdb.org', $subject, $message);
      }
    }
  
    $mgdb->get('body')->load('templates/static/feedback_output.bau');
    $mgdb->get('email')->replace($email);
    $mgdb->get('name')->replace($name);
    $mgdb->get('subject')->replace($subject);
    $mgdb->get('message')->replace($message);
  }   
?>