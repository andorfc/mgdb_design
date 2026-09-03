<?PHP
/* file: BLAST.php
 *
 * purpose: main controller for BLAST
 *
 *
 * history:
 *  04/10/25  eksc  created
 *
 * Front page redesign
 * -------------------
 * Only the *form* branch changed. A request carrying `job_id` or `submit-form`
 * goes to BLAST_run.php through the legacy main template exactly as before, so
 * job submission, execution and results are untouched. Without one, the page
 * renders on the modern main template and templates/static/mgdb_blast.bau,
 * which nests controllers/BLAST/BLAST_form.bau unchanged.
 *
 * Pre-redesign files are archived in the redesign repository under
 * legacy/blast/. Rollback is copying them back.
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
  
  // Is this the form, or a submitted/restored job? The two render differently.
  $blast_is_form = !(getCGIParam('job_id', 'GP', false) || getCGIParam('submit-form', 'P', false));

  $bauplan = new Bauplan('MaizeGDB BLAST');
  if ($blast_is_form) {
    $bauplan->modern();
  }
  $bauplan->includeCss('/tools/shadowbox/shadowbox-3.0.3/shadowbox.css');
  if ($blast_is_form) {
    $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
              ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
    $bauplan->includeCss('/css/static.css');
    $bauplan->includeCss('/css/mgdb-modern.css');
    $bauplan->includeCss('/css/mgdb-megamenu.css');
    /* The shared Data Hub shell -- pale ground, white section cards, coloured
       section edges, the reference card -- before the page's own sheets, which
       is the order css/mgdb-hub.css documents. */
    $bauplan->includeCss('/css/mgdb-hub.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-hub.css'));
  }
  /* BLAST.css still styles the form itself; mgdb-blast.css sits on top of it and
     only restrains the parts that fought the page around them. */
  $bauplan->includeCss('/controllers/BLAST/BLAST.css');
  if ($blast_is_form) {
    $bauplan->includeCss('/css/mgdb-blast.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-blast.css'));
  }
  $bauplan->includeScript('/tools/shadowbox/shadowbox-3.0.3/shadowbox.js');
  if ($blast_is_form) {
    $bauplan->includeScript('/js/mgdb-modern.js');
    $bauplan->includeScript('/js/mgdb-chrome.js');
    $bauplan->includeScript('/js/mgdb-blast.js?v=' . (int) @filemtime($doc_root . '/js/mgdb-blast.js'));
  }
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

  if ($blast_is_form) {
    $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
    $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
    $mgdb->get('image-dir')->replace($system['image_url']);
    $mgdb->get('server-url')->replace($system['root_url']);
  }
  else {
    $mgdb = $bauplan->template()->load('templates/maizegdb-main.bau');
    $header = $mgdb->get('megamenu')->load('templates/home/maizegdb_header.bau');
    $mgdb->get('image-dir')->replace($system['image_url']);
    $mgdb->get('server-url')->replace($system['root_url']);

    // Toggle log in/out section based on login status
    if ($username && $password && $userid) {
      $mgdb->get('logout')->toggle();
      $mgdb->get('username')->replace($username);
    }
  }
  
  // Check if any in-coming data. If none, show form, else pass control to BLAST_run.php
  //  job_id ---> restore previous results; submit-form ---> submit a job
  if (!$blast_is_form) {
    include('controllers/BLAST/BLAST_run.php');
  }
  else {
    include('controllers/BLAST/BLAST_form.php');
  }
  
  include_once('translation.php');
  $bauplan->publish();
?>