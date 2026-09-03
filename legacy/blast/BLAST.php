<?PHP
/* file: BLAST.php
 *
 * purpose: main controller for BLAST
 *
 *
 * history:
 *  04/10/25  eksc  created
 */
 
  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $username = getCookie('username', false);
  $password = getCookie('password', false);
  $userid =   getCookie('userid', false);

  // This is a bit clunky, but need to by-pass bauplan construction in some cases.
//logMessage("Check URL: $request");
  if (strstr($request, '_tasks.php')) {   // $request set by controller.php
    include('controllers/BLAST/BLAST_tasks.php');
    exit;
  }
  else if (strstr($request, '_visual_alignment.php')) {
    include('controllers/BLAST/BLAST_visual_alignment.php');
  }
  
  $bauplan = new Bauplan('MaizeGDB BLAST');
  $bauplan->includeCss('/tools/shadowbox/shadowbox-3.0.3/shadowbox.css');
  $bauplan->includeCss('/controllers/BLAST/BLAST.css');
  $bauplan->includeScript('/tools/shadowbox/shadowbox-3.0.3/shadowbox.js');
  $bauplan->includeScript('/controllers/BLAST/BLAST.js');
  
  if (preg_match('/(?i)msie [1-8]/',$_SERVER['HTTP_USER_AGENT'])) {
    // if IE<=8
    $bauplan->preHTML('<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "https://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">');
  }
  else {
    // if IE>8
    $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  }
  $bauplan->head('<script type="text/javascript"> Shadowbox.init({handleOversize: "resize", onClose: function() {enable_megamenu()}}); window.onload = function(){ Shadowbox.setup("a.shadow");};</script>
  <meta name="description" content="MaizeGDB is a public informatics service to researchers focused on the crop plant and model organism Zea mays (Corn).">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main.bau');
  $header = $mgdb->get('megamenu')->load('templates/home/maizegdb_header.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);
  
  // Toggle log in/out section based on login status
  if ($username && $password && $userid) {
    $mgdb->get('logout')->toggle();
    $mgdb->get('username')->replace($username);
  }
  
  // Check if any in-coming data. If none, show form, else pass control to BLAST_run.php
  //  job_id ---> restore previous results; submit-form ---> submit a job
  if (getCGIParam('job_id', 'GP', false) || getCGIParam('submit-form', 'P', false)) {
    include('controllers/BLAST/BLAST_run.php');
  }
  else {
    include('controllers/BLAST/BLAST_form.php');
  }
  
  include_once('translation.php');
  $bauplan->publish();
?>