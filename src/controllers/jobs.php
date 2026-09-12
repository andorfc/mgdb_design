<?php
/* file: jobs.php  (top-level shadow controller)
 *
 * purpose: /jobs -- the maize community job board, on the design system.
 *
 * `/jobs` used to fall through controller.php to redirect.php, which loaded the
 * legacy main template and its chrome before running
 * controllers/community/jobs.php. controller.php checks controllers/<CONTROLLER>.php
 * first, so adding this top-level file takes the route with a clean shell and no
 * legacy stylesheets leaking in. Deleting it gives the route straight back to the
 * legacy controller, which is untouched. The originals are archived in
 * legacy/jobs/.
 *
 * The listings are curated in data/jobs/jobs.php -- moved there verbatim from the
 * legacy controller's $jobs array -- so staff still edit one file to post a job.
 * Field values are curator-authored HTML and rendered as trusted markup, exactly
 * as before.
 *
 * The "Post a job" form still POSTs back to /jobs and is handled by the same
 * logic as the legacy page: it validates, keeps the honeypot field, e-mails the
 * curators, and writes the submission to data/jobs/ for review. The one change is
 * that values echoed back into the form on a validation error are now escaped;
 * the legacy page printed them raw.
 */

  include_once('./include/mail.php');
  include_once('./include/gp_lib.php');

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/jobs.php (modern job board)');

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
            ? $_SERVER['DOCUMENT_ROOT'] : $system['root_dir'];

  $jobs = include($doc_root . '/data/jobs/jobs.php');
  if (!is_array($jobs)) { $jobs = array(); }

/* -------------------------------------------------------------------------- *
 * Submission
 * -------------------------------------------------------------------------- */

  $submit = array(
    'active' => false,   // the form was posted
    'errors' => array(),
    'sent'   => false,
    'values' => array(
      'ContactName' => '', 'ContactEmail' => '', 'JobTitle' => '',
      'Location' => '', 'DegreeRequired' => '', 'AreaOfStudy' => '',
      'ApplyBy_month' => '', 'ApplyBy_day' => '', 'ApplyBy_year' => '',
      'Responsibilities' => '', 'OtherRequirements' => '',
    ),
  );

  if (getCGIParam('SubmitJob', 'P', false)) {
    jobs_handle_submit($system, $doc_root, $submit);
  }

  function jobs_handle_submit($system, $doc_root, &$submit) {
    $submit['active'] = true;
    foreach (array_keys($submit['values']) as $k) {
      $submit['values'][$k] = getCGIParam($k, 'P', '');
    }

    $errors = array();
    if (!$submit['values']['ContactName'])      { $errors[] = "Give a contact name."; }
    if (!$submit['values']['ContactEmail'])     { $errors[] = "Give a contact e-mail address."; }
    if (!$submit['values']['JobTitle'])         { $errors[] = "Give the position title."; }
    if (!$submit['values']['Location'])         { $errors[] = "Give the job location."; }
    if (!$submit['values']['Responsibilities']) { $errors[] = "Describe the responsibilities."; }

    /* Honeypot: a field hidden from people but filled by many bots. A trip is
       answered as though it worked and quietly dropped -- the same approach as
       the feedback form. */
    $honeypot = getCGIParam('OtherNotes', 'P', false);

    if ($errors) {
      $submit['errors'] = $errors;
      return;
    }

    if ($honeypot) {
      logMessage('jobs: discarded a submission (honeypot) from '
                 . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?'));
      $submit['sent'] = true;   // thanked, not blocked
      return;
    }

    /* Build and send the notice -- same recipients, subject, and file-drop as
       the legacy page. Large postings are written to data/jobs/ and the e-mail
       just points there, because long bodies were failing to send. */
    $v = $submit['values'];
    $applyBy = trim($v['ApplyBy_month'] . ' ' . $v['ApplyBy_day'] . ' ' . $v['ApplyBy_year']);

    $message  = "Job title: {$v['JobTitle']}\n\n";
    $message .= "Apply by: {$applyBy}\n\n";
    $message .= "Location: {$v['Location']}\n\n";
    $message .= "Degree Required: {$v['DegreeRequired']}\n\n";
    $message .= "Area of Study: {$v['AreaOfStudy']}\n\n";
    $message .= "Description: {$v['Responsibilities']}\n\n";
    $message .= "Other Requirements: {$v['OtherRequirements']}\n\n";
    $message .= "Contact {$v['ContactName']} at {$v['ContactEmail']} for more information.\n\n";

    $dir = $doc_root . '/data/jobs';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $fh = @fopen($dir . '/newjob_' . rand(), 'w');
    if ($fh) { fwrite($fh, $message); fclose($fh); }

    // Long bodies broke the mail notification, so send a pointer instead.
    $mail_body = (strlen($message) > 300)
               ? 'New job posting, check /data/jobs on the production server'
               : $message;

    $sendtos = array(
      'portwood@iastate.edu',
      'john.portwood@usda.gov',
      'carson.andorf@usda.gov',
      'portwoodii@gmail.com',
      'mgdbtech1@gmail.com',
    );
    // Sending as the contact address routed some notices to junk; send from mgdb.
    $sendfrom = 'mgdb@iastate.edu';
    $subject  = '[MAIZEGDB-JOB] New Job Posting';
    foreach ($sendtos as $to) {
      send_email($to, $sendfrom, $subject, $mail_body);
    }

    $submit['sent'] = true;
  }

/* -------------------------------------------------------------------------- *
 * Rendering the listings
 * -------------------------------------------------------------------------- */

  /* Field routing. Header meta is compact and inline; the rest are titled blocks,
     with the qualifications folded into a <details> so a long posting stays
     scannable and the how-to-apply lines stay in view. Anything a job does not
     carry is simply skipped, so the varying field sets across postings render
     without special-casing. */
  function jobs_render_cards($jobs) {
    if (!$jobs) {
      return '<div class="mgdb-empty"><h3>No openings right now</h3>'
           . '<p>There are no job postings on the board at the moment. '
           . 'Check back, or post an opening below.</p></div>';
    }

    $details_fields = array(
      'Area of Study', 'Department', 'Required Qualifications',
      'Qualifications', 'Preferred Qualifications', 'Job Announcement Number',
    );

    $out = '<div class="jobs-list">';
    $n = 0;
    foreach ($jobs as $job) {
      $n++;
      $title = isset($job['Job Title']) ? $job['Job Title'] : 'Position';

      // Header meta
      $meta = array();
      if (!empty($job['Location']))        { $meta[] = '<span class="jobs-meta-primary">' . mgdb_html($job['Location']) . '</span>'; }
      if (!empty($job['Date Posted']))     { $meta[] = '<span>Posted ' . mgdb_html($job['Date Posted']) . '</span>'; }
      if (!empty($job['Apply By']))        { $meta[] = '<span class="jobs-meta-apply">Apply by ' . mgdb_html($job['Apply By']) . '</span>'; }
      if (!empty($job['Degree Required'])) { $meta[] = '<span>' . mgdb_html($job['Degree Required']) . '</span>'; }
      $meta_html = $meta
        ? '<ul class="jobs-meta"><li>' . implode('</li><li>', $meta) . '</li></ul>'
        : '';

      $body = '';

      // Description (trusted curator HTML)
      if (!empty($job['Description'])) {
        $body .= '<div class="jobs-field jobs-description">' . $job['Description'] . '</div>';
      }

      // Foldable qualifications and secondary fields
      $more = '';
      foreach ($details_fields as $label) {
        if (!empty($job[$label])) {
          $more .= '<div class="jobs-field"><h4>' . mgdb_html($label) . '</h4><div>' . $job[$label] . '</div></div>';
        }
      }
      if ($more !== '') {
        $body .= '<details class="jobs-more"><summary>Qualifications and requirements</summary>'
               . '<div class="jobs-more-body">' . $more . '</div></details>';
      }

      // How to apply -- kept in view
      if (!empty($job['Application Instructions'])) {
        $body .= '<div class="jobs-apply"><h4>How to apply</h4><div>' . $job['Application Instructions'] . '</div></div>';
      }
      if (!empty($job['Full Announcement'])) {
        $url = htmlspecialchars($job['Full Announcement'], ENT_QUOTES, 'UTF-8');
        $body .= '<p class="jobs-field jobs-announcement"><a class="mgdb-external" href="' . $url . '">Full announcement</a></p>';
      }
      if (!empty($job['Contact'])) {
        $body .= '<p class="jobs-contact">' . $job['Contact'] . '</p>';
      }

      $out .= '<article class="mgdb-card jobs-card" id="job-' . $n . '">'
            . '<div class="jobs-card-head"><h3>' . mgdb_html($title) . '</h3>' . $meta_html . '</div>'
            . '<div class="jobs-card-body">' . $body . '</div>'
            . '</article>';
    }
    $out .= '</div>';
    return $out;
  }

/* -------------------------------------------------------------------------- *
 * Rendering the submission form
 * -------------------------------------------------------------------------- */

  function jobs_render_form($system, $submit) {
    $v = $submit['values'];
    $e = function ($k) use ($v) { return htmlspecialchars($v[$k], ENT_QUOTES, 'UTF-8'); };

    // Success replaces the form.
    if ($submit['sent']) {
      return '<div class="mgdb-message mgdb-message-ok jobs-submitted" role="status">'
           . '<div><strong>Thanks for your submission.</strong> Our curators will review it and add it to '
           . 'the board. If it is not posted by the next business day, contact John Portwood at '
           . '<a href="mailto:john.portwood@usda.gov">john.portwood@usda.gov</a>.</div></div>';
    }

    $open = $submit['active'] && $submit['errors'] ? ' open' : '';

    $errbox = '';
    if ($submit['errors']) {
      $items = '';
      foreach ($submit['errors'] as $msg) { $items .= '<li>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</li>'; }
      $errbox = '<div class="mgdb-message mgdb-message-error" role="alert">'
              . '<div><strong>Please fix the following:</strong><ul class="jobs-error-list">' . $items . '</ul></div></div>';
    }

    $cur = (int) date('Y');
    $next = $cur + 1;
    $months = array('January','February','March','April','May','June','July',
                    'August','September','October','November','December');
    $month_opts = '<option value="">Month</option>';
    foreach ($months as $m) {
      $sel = ($v['ApplyBy_month'] === $m) ? ' selected' : '';
      $month_opts .= '<option value="' . $m . '"' . $sel . '>' . $m . '</option>';
    }
    $day_opts = '<option value="">Day</option>';
    for ($d = 1; $d <= 31; $d++) {
      $sel = ((string) $v['ApplyBy_day'] === (string) $d) ? ' selected' : '';
      $day_opts .= '<option value="' . $d . '"' . $sel . '>' . $d . '</option>';
    }
    $year_opts = '<option value="">Year</option>';
    foreach (array($cur, $next) as $y) {
      $sel = ((string) $v['ApplyBy_year'] === (string) $y) ? ' selected' : '';
      $year_opts .= '<option value="' . $y . '"' . $sel . '>' . $y . '</option>';
    }
    $degrees = array('' => 'No minimum', 'Bachelors' => 'Bachelor\'s',
                     'Masters' => 'Master\'s', 'Doctorate' => 'Doctorate');
    $degree_opts = '';
    foreach ($degrees as $val => $lab) {
      $sel = ($v['DegreeRequired'] === $val) ? ' selected' : '';
      $degree_opts .= '<option value="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>' . $lab . '</option>';
    }

    $req = '<span class="mgdb-required" aria-hidden="true">*</span>';

    return
      '<details class="jobs-post"' . $open . '>'
    . '<summary class="jobs-post-summary">Post a job opening</summary>'
    . '<div class="jobs-post-body">'
    . '<p class="jobs-post-lede">Fill out this form and our curation team will review your submission. '
    . 'Valid postings are added to the board, usually within one business day. Fields marked ' . $req . ' are required.</p>'
    . $errbox
    . '<form method="post" action="/jobs" class="jobs-form">'

    . '<div class="jobs-form-grid">'
    . jobs_field('ContactName', 'Contact name', $req, '<input class="mgdb-input" type="text" id="ContactName" name="ContactName" autocomplete="name" value="' . $e('ContactName') . '">')
    . jobs_field('ContactEmail', 'Contact e-mail', $req, '<input class="mgdb-input" type="email" id="ContactEmail" name="ContactEmail" autocomplete="email" value="' . $e('ContactEmail') . '">')
    . jobs_field('JobTitle', 'Position title', $req, '<input class="mgdb-input" type="text" id="JobTitle" name="JobTitle" value="' . $e('JobTitle') . '">')
    . jobs_field('Location', 'Location', $req, '<input class="mgdb-input" type="text" id="Location" name="Location" value="' . $e('Location') . '">', 'City, state or province, country')
    . jobs_field('DegreeRequired', 'Minimum degree', '', '<select class="mgdb-select" id="DegreeRequired" name="DegreeRequired">' . $degree_opts . '</select>')
    . jobs_field('AreaOfStudy', 'Area of study', '', '<input class="mgdb-input" type="text" id="AreaOfStudy" name="AreaOfStudy" placeholder="e.g. developmental biology" value="' . $e('AreaOfStudy') . '">')
    . '<div class="mgdb-field jobs-field-wide">'
    . '<span class="mgdb-label" id="applyby-label">Apply by</span>'
    . '<div class="jobs-date-row" role="group" aria-labelledby="applyby-label">'
    . '<select class="mgdb-select" name="ApplyBy_month" aria-label="Apply-by month">' . $month_opts . '</select>'
    . '<select class="mgdb-select" name="ApplyBy_day" aria-label="Apply-by day">' . $day_opts . '</select>'
    . '<select class="mgdb-select" name="ApplyBy_year" aria-label="Apply-by year">' . $year_opts . '</select>'
    . '</div></div>'
    . '<div class="mgdb-field jobs-field-wide">'
    . '<label class="mgdb-label" for="Responsibilities">Responsibilities ' . $req . '</label>'
    . '<textarea class="mgdb-textarea" id="Responsibilities" name="Responsibilities" rows="6">' . $e('Responsibilities') . '</textarea></div>'
    . '<div class="mgdb-field jobs-field-wide">'
    . '<label class="mgdb-label" for="OtherRequirements">Other requirements <span class="jobs-optional">optional</span></label>'
    . '<textarea class="mgdb-textarea" id="OtherRequirements" name="OtherRequirements" rows="5">' . $e('OtherRequirements') . '</textarea></div>'
    . '</div>'

    // Honeypot: off-screen, not display:none, so form-fillers still reach it.
    . '<div class="jobs-hp" aria-hidden="true"><label>Other notes<textarea name="OtherNotes" rows="2" tabindex="-1" autocomplete="off"></textarea></label></div>'

    . '<div class="mgdb-form-actions">'
    . '<button class="mgdb-button mgdb-button-primary" type="submit" name="SubmitJob" value="1">Submit job posting</button>'
    . '</div>'
    . '</form>'
    . '</div></details>';
  }

  function jobs_field($id, $label, $req, $control, $hint = '') {
    $desc = $hint ? ' aria-describedby="' . $id . '-hint"' : '';
    // Inject aria-describedby onto the control if there is a hint.
    if ($hint) { $control = preg_replace('/^(<(?:input|select|textarea)\b)/', '$1' . $desc, $control, 1); }
    $hint_html = $hint ? '<p class="mgdb-hint" id="' . $id . '-hint">' . htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') . '</p>' : '';
    return '<div class="mgdb-field">'
         . '<label class="mgdb-label" for="' . $id . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ' ' . $req . '</label>'
         . $hint_html . $control . '</div>';
  }

/* -------------------------------------------------------------------------- *
 * The document
 * -------------------------------------------------------------------------- */

  $bauplan = new Bauplan('Jobs | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');

  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-jobs.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-jobs.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->head('<meta name="description" content="Current job postings of interest to the maize genetics and genomics community, and a form to submit an opening to the MaizeGDB job board.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/community/mgdb_jobs.bau');

  $count = count($jobs);
  $body->get('job_count')->replace($count === 0 ? '' : ($count . ($count === 1 ? ' opening' : ' openings')));
  $body->get('openings')->replace(jobs_render_cards($jobs));
  $body->get('post_form')->replace(jobs_render_form($system, $submit));

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);
  $bauplan->publish();
  exit;
?>
