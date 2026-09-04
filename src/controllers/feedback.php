<?PHP
/* file: feedback.php
 *
 * purpose: main controller for /feedback — the site feedback form, and the
 *          endpoint both it and the header dialog post to.
 *
 * The route did not exist before. `controller.php` checks
 * `controllers/<CONTROLLER>.php` first, so adding this file takes /feedback
 * without touching anything else; deleting it gives the route back.
 *
 * Three things arrive here:
 *
 *   GET  /feedback              the page
 *   GET  /feedback?embed=form   the form on its own, for the header dialog
 *   POST /feedback              a submission, from either
 *
 * `?kind=gene_model` gives the second shape of the form — the gene model and
 * assembly error report, which the legacy shell opened from a link class on
 * gene and pan-gene records. `?id=` prefills the gene model it is about. Both
 * work on the page and on the embed, so the link in a record page still leads
 * somewhere with JavaScript switched off.
 *
 * The POST answers JSON when it is asked to (the dialog and the page script
 * both fetch it) and otherwise redirects back to the page — so the form works
 * with JavaScript switched off, which matters for a form whose whole job is to
 * let someone report that something is broken.
 *
 * Everything about the message itself — validation, the spam guards, and the
 * call to the Jira issue collector — is in include/feedback_lib.php.
 */

  include_once('./include/feedback_lib.php');

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/feedback.php');

  $feedback_kind = feedbackKind(isset($_GET['kind']) ? (string) $_GET['kind'] : 'site');
  $feedback_kinds = feedbackKinds();

  /* Same-host only. The value is shown back to the reader in a text field and
     travels on to the issue queue, so an arbitrary URL from a referrer header
     does not belong in it. */
  function feedbackSuggestedPage($system) {
    if (!empty($_GET['from'])) {
      $candidate = (string) $_GET['from'];
    } else if (!empty($_SERVER['HTTP_REFERER'])) {
      $candidate = (string) $_SERVER['HTTP_REFERER'];
    } else {
      return '';
    }

    $candidate = feedbackCleanLine($candidate, MGDB_FEEDBACK_MAX_PAGE);
    if ($candidate === '') {
      return '';
    }

    /* A bare path is ours by definition; make it absolute so the queue can
       follow it. */
    if (substr($candidate, 0, 1) === '/') {
      return rtrim($system['root_url'], '/') . $candidate;
    }

    $host = parse_url($candidate, PHP_URL_HOST);
    if (!$host) {
      return '';
    }
    $ours = parse_url($system['root_url'], PHP_URL_HOST);
    if (strcasecmp($host, (string) $ours) === 0
        || preg_match('/(^|\.)maizegdb\.org$/i', $host)) {
      return $candidate;
    }
    return '';
  }


  /////
  // POST — a submission from the page form or the header dialog
  /////

  if (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'POST') {

    $wants_json = (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
                   && strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'], 'XMLHttpRequest') === 0)
               || (isset($_SERVER['HTTP_ACCEPT'])
                   && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    $input  = feedbackCollectInput($_POST);
    $feedback_kind = $input['kind'];
    $errors = feedbackValidate($input);
    $note   = '';
    $key    = '';
    $sent   = false;

    $automated = feedbackLooksAutomated($input);

    if ($automated !== '') {
      /* Answered as though it worked. A script that is told it was blocked
         retries with the check removed; one that is thanked does not. Logged so
         a real person who somehow trips it can be found. */
      logMessage('feedback: discarded a submission (' . $automated . ') from ' . feedbackRemoteAddress());
      $sent = true;

    } else if (!empty($errors)) {
      $note = 'Check the highlighted fields and send again.';

    } else if (!feedbackRateAllows($system, feedbackRemoteAddress())) {
      $note = 'That is several messages in a short time. Give it an hour, or write to '
            . 'mgdb-tech@iastate.edu if it is urgent.';

    } else {
      $result = feedbackSubmit($system, $input, array(
        'host'    => isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '',
        'referer' => isset($_SERVER['HTTP_REFERER']) ? feedbackCleanLine($_SERVER['HTTP_REFERER'], 300) : '',
        'agent'   => isset($_SERVER['HTTP_USER_AGENT']) ? feedbackCleanLine($_SERVER['HTTP_USER_AGENT'], 300) : '',
      ));

      if ($result['ok']) {
        $sent = true;
        $key  = $result['key'];
      } else {
        $mapped = feedbackMapCollectorErrors($result['errors']);
        $errors = array_merge($errors, $mapped['fields']);
        $note   = $result['message'];
        if ($note === '' && !empty($mapped['other'])) {
          $note = implode(' ', $mapped['other']);
        }
        if ($note === '' && !empty($errors)) {
          $note = 'Check the highlighted fields and send again.';
        }
      }
    }

    if ($wants_json) {
      header('Content-Type: application/json; charset=utf-8');
      header('Cache-Control: no-store');
      echo json_encode(array(
        'ok'      => $sent,
        'key'     => $key,
        'errors'  => (object) $errors,
        'message' => $note,
      ));
      return;
    }

    /* No JavaScript: redirect on success so a reload cannot send the message
       twice, and fall through to the page with the values still in the form on
       failure. */
    if ($sent) {
      $target = rtrim($system['root_url'], '/') . '/feedback?sent=1'
              . ($input['kind'] !== 'site' ? '&kind=' . urlencode($input['kind']) : '')
              . ($key !== '' ? '&key=' . urlencode($key) : '');
      header('Location: ' . $target);
      return;
    }

    $feedback_values = $input;
    $feedback_errors = $errors;
    $feedback_note   = $note;
  }


  /////
  // GET
  /////

  if (!isset($feedback_values)) {
    $feedback_values = array('page' => feedbackSuggestedPage($system));
    /* A record page links here with the gene model it is about. */
    if (!empty($_GET['id'])) {
      $feedback_values['models'] = feedbackCleanLine((string) $_GET['id'], MGDB_FEEDBACK_MAX_MODELS);
    }
    $feedback_errors = array();
    $feedback_note   = '';
  }

  /* The dialog asks for the form on its own so that the markup has exactly one
     home. Nothing else is emitted — no shell, no stylesheet — because it is
     injected into a page that already has both. */
  if (isset($_GET['embed']) && $_GET['embed'] === 'form') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo feedbackFormMarkup(array(
      'values' => $feedback_values,
      'errors' => $feedback_errors,
      'kind'   => $feedback_kind,
      'dialog' => true,
    ));
    return;
  }

  $sent_key = '';
  $was_sent = isset($_GET['sent']) && $_GET['sent'] !== '';
  if ($was_sent && !empty($_GET['key']) && preg_match('/^[A-Z][A-Z0-9]*-\d+$/', (string) $_GET['key'])) {
    $sent_key = (string) $_GET['key'];
  }

  $feedback_spec = $feedback_kinds[$feedback_kind];

  $bauplan = new Bauplan(($feedback_kind === 'site' ? 'Send feedback' : 'Report a gene model error')
                         . ' | MaizeGDB');
  $bauplan->modern();

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-feedback.css');
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->head('<meta name="description" content="'
                 . htmlspecialchars($feedback_spec['page_intro'], ENT_QUOTES, 'UTF-8') . '">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');

  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $mgdb->get('body')->load('templates/static/mgdb_feedback.bau');

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $mgdb->get('feedback-form')->replace(feedbackFormMarkup(array(
    'values' => $feedback_values,
    'errors' => $feedback_errors,
    'kind'   => $feedback_kind,
  )));

  $mgdb->get('feedback-title')->replace(htmlspecialchars($feedback_spec['title'], ENT_QUOTES, 'UTF-8'));
  $mgdb->get('feedback-intro')->replace(htmlspecialchars($feedback_spec['page_intro'], ENT_QUOTES, 'UTF-8'));
  $mgdb->get('feedback-heading')->replace($feedback_kind === 'site' ? 'Your message' : 'Your report');

  /* One banner above the form: the confirmation after a redirect, or the reason
     a submission did not go through. Built here because both are conditional
     and Bauplan has no branch of its own. */
  $banner = '';
  if ($was_sent) {
    $queue = $feedback_kind === 'site'
           ? 'the MaizeGDB issue queue'
           : 'the assembly and annotation queue';
    $banner = '<div class="mgdb-message mgdb-message-ok" role="status"><div>'
            . '<strong>Thank you — your message reached ' . $queue
            . ($sent_key !== '' ? ' as ' . htmlspecialchars($sent_key, ENT_QUOTES, 'UTF-8') : '')
            . '.</strong> If you left an address, someone will reply to it.'
            . '</div></div>';
  } else if ($feedback_note !== '') {
    $banner = '<div class="mgdb-message mgdb-message-error" role="alert"><div>'
            . '<strong>The message was not sent.</strong> '
            . htmlspecialchars($feedback_note, ENT_QUOTES, 'UTF-8')
            . '</div></div>';
  }
  $mgdb->get('feedback-banner')->replace($banner);

  $bauplan->publish();
?>
