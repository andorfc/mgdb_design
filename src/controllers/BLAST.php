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
 * which carries controllers/BLAST/BLAST_form.bau -- rebuilt on the site's own
 * controls, but around an untouched BLAST.js.
 *
 * Pre-redesign files are archived in the redesign repository under
 * legacy/blast/. Rollback is copying them back.
 */
 

/**
 * Was this job submitted by the modern runner?
 *
 * A job id names a set of sub-jobs, one per target, written as
 * <job>_<sub>.json by the current runner and <job>_<sub>.bla by the old one,
 * so an old bookmarked job keeps the legacy view rather than erroring against
 * a parser that cannot read its XML.
 *
 * The test is the `.targets` manifest, NOT the presence of a report. The report
 * is created by BLAST itself, asynchronously, and the gap is not small: a job
 * submitted at 23:09:29.254 -- the instant the redirect went out -- had no
 * report until .747 for one target and 31.006 for the other, 0.5 s and 1.75 s
 * later. A browser follows a 303 well inside that window, so a glob for the
 * report found nothing, this returned false, and the job fell through to the
 * legacy view, whose poller then waited for ever on a .bla file the modern
 * runner never writes. blast_submit.php writes the manifest BEFORE it redirects,
 * so it is on disk by the time any request can arrive.
 *
 * The report glob stays as a fallback for jobs submitted before the manifest
 * existed.
 */
function blast_job_is_modern($job_id, $system) {
  if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $job_id)) { return false; }
  $dir = rtrim($system['temp_dir'], '/');
  if (is_readable($dir . '/' . $job_id . '.targets')) { return true; }
  $found = glob($dir . '/' . $job_id . '*.json');
  return !empty($found);
}

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $username = getCookie('username', false);
  $password = getCookie('password', false);
  $userid =   getCookie('userid', false);

  // This is a bit clunky, but need to by-pass bauplan construction in some cases.
//logMessage("Check URL: $request");
  /* The results interface's own JSON endpoint. Checked before _tasks.php
     because both match a bare substring test and this file is repo-owned. */
  if (strstr($request, 'blast_results_api.php')) {
    include('controllers/BLAST/blast_results_api.php');
    exit;
  }
  if (strstr($request, '_tasks.php')) {   // $request set by controller.php
    include('controllers/BLAST/BLAST_tasks.php');
    exit;
  }

  /* A submission launches its searches and redirects to the job's results URL.
     This runs before any template is constructed, because it ends in a
     redirect rather than a page. */
  if (getCGIParam('submit-form', 'P', false)) {
    include('controllers/BLAST/blast_submit.php');
    exit;
  }
  else if (strstr($request, '_visual_alignment.php')) {
    include('controllers/BLAST/BLAST_visual_alignment.php');
  }
  
  /* Three modes now, not two.
       form     no job          -> modern shell + the search form
       results  a job we can    -> modern shell + the discovery interface
                parse as JSON
       legacy   an older .bla   -> the legacy template, byte for byte
     A job whose report is the old XML/tabular .bla still renders the legacy
     view, so nothing already saved or bookmarked changes behavior. */
  $blast_job_id  = getCGIParam('job_id', 'GP', false);
  $blast_is_form = !$blast_job_id;

  $blast_is_results = false;
  if (!$blast_is_form && $blast_job_id) {
    $blast_is_results = blast_job_is_modern($blast_job_id, $system);
    // ?ui=legacy is the escape hatch while the new interface beds in.
    if (getCGIParam('ui', 'GP', false) === 'legacy') { $blast_is_results = false; }
  }

  /* Everything below that asks "is this the modern shell?" means form OR
     results; only the template choice distinguishes them. */
  $blast_modern = $blast_is_form || $blast_is_results;

  $bauplan = new Bauplan('MaizeGDB BLAST');
  if ($blast_modern) {
    $bauplan->modern();
  }
  /* Shadowbox is the legacy lightbox. The results page opens CViT images with
     it; the form page has no `a.shadow` link at all, and on the modern branch
     the script loads before jQuery, so it threw `jQuery is not defined` on
     every view and the init below then threw `Shadowbox is not defined`. Modern
     pages do not load it -- js/mgdb-gene-record.js says the same. */
  if (!$blast_modern) {
    $bauplan->includeCss('/tools/shadowbox/shadowbox-3.0.3/shadowbox.css');
  }
  if ($blast_modern) {
    $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
              ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
    $bauplan->includeCss('/css/static.css');
    $bauplan->includeCss('/css/mgdb-modern.css');
    $bauplan->includeCss('/css/mgdb-megamenu.css');
    /* The shared Data Hub shell -- pale ground, white section cards, colored
       section edges, the reference card -- before the page's own sheets, which
       is the order css/mgdb-hub.css documents. */
    $bauplan->includeCss('/css/mgdb-hub.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-hub.css'));
  }
  /* BLAST.css is the results page's sheet. The form page no longer needs it --
     the form is built from the site's own controls, and nothing in that sheet
     applies to it any more except `label { padding-right: 20px }`, which has no
     scope at all and so reached the megamenu, the hero and every other label on
     the page. */
  if ($blast_is_form) {
    $bauplan->includeCss('/css/mgdb-blast.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-blast.css'));
  }
  else {
    $bauplan->includeCss('/controllers/BLAST/BLAST.css');
  }
  if (!$blast_modern) {
    $bauplan->includeScript('/tools/shadowbox/shadowbox-3.0.3/shadowbox.js');
  }
  if ($blast_modern) {
    $bauplan->includeScript('/js/mgdb-modern.js');
    $bauplan->includeScript('/js/mgdb-chrome.js');
  }
  if ($blast_is_form) {
    $bauplan->includeScript('/js/mgdb-blast.js?v=' . (int) @filemtime($doc_root . '/js/mgdb-blast.js'));
  }
  if ($blast_is_results) {
    $bauplan->includeCss('/css/mgdb-blast-results.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-blast-results.css'));
    /* MGDB.chart() is a Plotly wrapper and does nothing without Plotly itself,
       leaving an empty panel rather than an error. Every other page with a
       figure loads this; the results page needs it for the identity-against-
       coverage scatter. */
    $bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
    $bauplan->includeScript('/js/mgdb-blast-results.js?v=' . (int) @filemtime($doc_root . '/js/mgdb-blast-results.js'));
  }
  /* BLAST.js drives the legacy form and the legacy results poller. The new
     results page has its own engine and does not need it; loading it there
     would start a second poller against the same job. */
  if (!$blast_is_results) {
    $bauplan->includeScript('/controllers/BLAST/BLAST.js');
  }
  
  if (preg_match('/(?i)msie [1-8]/',$_SERVER['HTTP_USER_AGENT'])) {
    // if IE<=8
    $bauplan->preHTML('<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "https://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">');
  }
  else {
    // if IE>8
    $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  }
  if ($blast_modern) {
    $bauplan->head('<meta name="description" content="MaizeGDB is a public informatics service to researchers focused on the crop plant and model organism Zea mays (Corn).">');
  }
  else {
    $bauplan->head('<script type="text/javascript"> Shadowbox.init({handleOversize: "resize", onClose: function() {enable_megamenu()}}); window.onload = function(){ Shadowbox.setup("a.shadow");};</script>
  <meta name="description" content="MaizeGDB is a public informatics service to researchers focused on the crop plant and model organism Zea mays (Corn).">');
  }

  if ($blast_modern) {
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
  
  /* Which body?
       results  the discovery interface, which fetches everything it shows from
                blast_results_api.php — the controller only has to name the job
       legacy   BLAST_run.php, untouched, for old reports and for ?ui=legacy
       form     the search form */
  if ($blast_is_results) {
    $body = $mgdb->get('body')->load('templates/static/mgdb_blast_results.bau');
    $body->get('job_id')->replace($blast_job_id);
    $body->get('reload_url')->replace($system['root_url'] . '/BLAST?job_id=' . rawurlencode($blast_job_id));
    /* No job-level assembly is passed. A job fans out to one search per target
       and each target has its own assembly, so the answer is per sub-job, not
       per job; blast_submit.php records it in <job>.targets and the API reads
       it from there. A single job-level value could only be wrong whenever a
       job searched more than one genome. */
  }
  else if (!$blast_is_form) {
    include('controllers/BLAST/BLAST_run.php');
  }
  else {
    include('controllers/BLAST/BLAST_form.php');
  }
  
  include_once('translation.php');
  $bauplan->publish();
?>