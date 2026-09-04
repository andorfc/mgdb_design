<?php
/* file: include/feedback_lib.php
 *
 * purpose: validate site feedback and hand it to the MaizeGDB Jira issue
 *          collector, plus render the form both places use.
 *
 * Why this exists
 * ---------------
 * The legacy shell carried two Atlassian issue collectors, declared in
 * `templates/maizegdb-main.bau` as `window.ATL_JQ_PAGE_PROPS`. Each one loads
 * a script from maizegdb.atlassian.net that binds a click handler to a class
 * (`.feedback-form`) and opens Atlassian's own dialog in an iframe. Modernized
 * pages kept the link and lost the handler, so the Feedback button in the
 * header has done nothing since the header was rebuilt. ADMIN_DEPENDENCIES.md
 * records it as AD-009.
 *
 * Rather than re-declaring the collector script, this posts to the collector's
 * own endpoint from the server, so the form is ours: MaizeGDB markup, the
 * shared form controls, real labels and error text, and no third-party script
 * or iframe on every page of the site.
 *
 * The endpoint
 * ------------
 * The feedback collector (883299e6) is a *custom template* collector. Its form
 * at
 *
 *   https://maizegdb.atlassian.net/rest/collectors/1.0/template/form/883299e6
 *
 * posts to
 *
 *   https://maizegdb.atlassian.net/rest/collectors/1.0/template/custom/883299e6
 *
 * with `pid=10001` (project WEB), `summary`, `description`, and the collector's
 * own contact fields `fullname`, `email`, `recordWebInfo` and `webInfo`.
 *
 * Measured against the live collector on 2026-09-01: the POST needs **no**
 * `atl_token` and no cookie jar — the endpoint is unauthenticated, and posting
 * with neither returns the same field-level validation as posting with both.
 * So one request per submission, not two. `X-Atlassian-Token: no-check` is sent
 * anyway because it is what Atlassian documents for this call.
 *
 * The reply is JSON wrapped in a textarea, which is how the collector returns a
 * result to the iframe that normally posts to it:
 *
 *   <html><body><textarea>{"errorMessages":[],"errors":{"summary":"..."}}</textarea></body></html>
 *
 * feedbackParseCollectorReply() unwraps that. An `errors` object comes back
 * keyed by the collector's own field names, which are the names this form uses,
 * so they can be shown against the right field without a translation table.
 *
 * Because the endpoint is open to anyone, the guards here are not what stops
 * abuse of Jira — they stop *this form* being the convenient way to do it, and
 * they keep obvious junk out of the queue: a honeypot field, a minimum time on
 * the form, length caps, and a per-address rate limit.
 *
 * The second collector
 * --------------------
 * `dddb1a6c` is the gene model and assembly error report the legacy shell bound
 * to `.trigger_gene_model_issue_form`. Same endpoint shape, different project
 * and a different set of fields, read from its own form template on 2026-09-01:
 *
 *   project ASMBLY, pid 10003, issue type 10008
 *   summary, description
 *   customfield_10050   "Affected gene models and/or loci"
 *   customfield_10051   "Publication"
 *   screenshots         attachment — not offered here, see below
 *   fullname, email     both labelled "(required)" by the collector
 *
 * So the form has two shapes, chosen by `kind`: `site` and `gene_model`. The
 * collector also accepts a file, which this form does not offer — forwarding an
 * upload means proxying multipart with its own size and type handling, and a
 * reporter can attach the image to the issue once it exists.
 *
 * Configuration -- conf/mgdb.conf, all optional
 * ---------------------------------------------
 *   feedback_collector_id=883299e6      collector the site form posts to
 *   feedback_project_pid=10001          the collector's project id
 *   feedback_gene_collector_id=dddb1a6c gene model and assembly reports
 *   feedback_gene_project_pid=10003     that collector's project id
 *   feedback_collector_base=https://maizegdb.atlassian.net/rest/collectors/1.0
 *   feedback_rate_path=/home/cache/feedback
 *                                       where the rate-limit counters live.
 *                                       Defaults to <search_cache_path>/feedback.
 *   feedback_enabled=false              turns the form off and says so.
 *
 * A missing counter directory must never block a message: rate limiting fails
 * open, the same way include/dashboard_cache.php falls back to serving live.
 */

if (!defined('MGDB_FEEDBACK_LIB')) {
  define('MGDB_FEEDBACK_LIB', true);

/* Field caps. Jira's own summary field is 255 characters; 140 keeps a subject
   line readable in a queue and leaves room for the type tag prefixed below. */
define('MGDB_FEEDBACK_MAX_SUMMARY', 140);
define('MGDB_FEEDBACK_MIN_DETAILS', 15);
define('MGDB_FEEDBACK_MAX_DETAILS', 6000);
define('MGDB_FEEDBACK_MAX_NAME', 80);
define('MGDB_FEEDBACK_MAX_EMAIL', 160);
define('MGDB_FEEDBACK_MAX_PAGE', 500);
define('MGDB_FEEDBACK_MAX_MODELS', 300);
define('MGDB_FEEDBACK_MAX_PUB', 300);

/* Seconds a real person needs to fill this in. Scripted posts arrive instantly;
   the check only runs when the browser filled in the start stamp. */
define('MGDB_FEEDBACK_MIN_SECONDS', 4);

/* Per address, per window. Generous for a person, tedious for a script. */
define('MGDB_FEEDBACK_WINDOW', 3600);
define('MGDB_FEEDBACK_MAX_PER_WINDOW', 6);


/////
// What the form offers, and how each choice reaches the issue queue.
//
// The collector takes a summary and a description and nothing else, so the type
// is carried as a bracketed tag on the summary — the shape a triage queue can
// sort on — and repeated as a line in the description.
/////

function feedbackTypes() {
  return array(
    'general'    => array('label' => 'General feedback',
                          'tag'   => 'Feedback',
                          'hint'  => 'Comments on the site, its data, or how something works.'),
    'problem'    => array('label' => 'Something is broken',
                          'tag'   => 'Bug',
                          'hint'  => 'A page, download, or tool that errors, hangs, or shows nothing.'),
    'data'       => array('label' => 'Data correction',
                          'tag'   => 'Data',
                          'hint'  => 'A record, gene model, or annotation that looks wrong.'),
    'suggestion' => array('label' => 'Feature request',
                          'tag'   => 'Request',
                          'hint'  => 'Something MaizeGDB does not do yet that would help your work.'),
  );
}

function feedbackTypeKeys() {
  return array_keys(feedbackTypes());
}


/////
// The two shapes of the form.
//
// `site` is the header's Feedback button. `gene_model` is what the legacy shell
// opened from `.trigger_gene_model_issue_form` on gene and pan-gene records: a
// different collector, a different project, two custom fields, and a reporter
// the collector's own labels mark as required.
/////

function feedbackKinds() {
  return array(
    'site' => array(
      'collector_key' => 'feedback_collector_id',
      'collector'     => '883299e6',
      'pid_key'       => 'feedback_project_pid',
      'pid'           => '10001',
      'title'         => 'Send feedback to MaizeGDB',
      'intro'         => 'Goes to the MaizeGDB issue queue. Nothing you type here leaves the page until you send it.',
      'page_intro'    => 'Tell us about a record that looks wrong, a page that does not work, or something the site should do and does not. Messages open an item in the MaizeGDB issue queue, which the team works through on working days.',
      'types'         => true,
      'contact'       => 'optional',
      'models'        => false,
    ),
    'gene_model' => array(
      'collector_key' => 'feedback_gene_collector_id',
      'collector'     => 'dddb1a6c',
      'pid_key'       => 'feedback_gene_project_pid',
      'pid'           => '10003',
      'title'         => 'Report a gene model or assembly error',
      'intro'         => 'Goes to the assembly and annotation queue, and is shared with the group working on the B73 assembly and its gene models.',
      'page_intro'    => 'Misassembled regions, evidence for closing a gap, gene models that should be merged or split, a structure that disagrees with the evidence. Reports open an item in the assembly and annotation queue, and are shared with the group working on the B73 assembly and its gene models.',
      'types'         => false,
      'contact'       => 'required',
      'models'        => true,
    ),
  );
}

function feedbackKind($kind) {
  $kinds = feedbackKinds();
  return isset($kinds[$kind]) ? $kind : 'site';
}


/////
// Configuration
/////

function feedbackConfig($system, $kind = 'site') {
  $base = !empty($system['feedback_collector_base'])
        ? rtrim($system['feedback_collector_base'], '/')
        : 'https://maizegdb.atlassian.net/rest/collectors/1.0';

  $kinds = feedbackKinds();
  $spec  = $kinds[feedbackKind($kind)];

  return array(
    'base'      => $base,
    'collector' => !empty($system[$spec['collector_key']]) ? $system[$spec['collector_key']] : $spec['collector'],
    'pid'       => !empty($system[$spec['pid_key']])       ? $system[$spec['pid_key']]       : $spec['pid'],
    'enabled'   => !isset($system['feedback_enabled'])
                   || !in_array(strtolower((string) $system['feedback_enabled']), array('false', '0', 'off'), true),
  );
}


/////
// Input handling
//
// One normalizer for both entry points: the fetch() call the dialog makes and
// the plain form post a browser without JavaScript sends. Both arrive as
// application/x-www-form-urlencoded in $_POST, so there is one shape to handle.
/////

function feedbackCollectInput($post) {
  $get = function ($key) use ($post) {
    return isset($post[$key]) ? (string) $post[$key] : '';
  };

  $input = array(
    'kind'     => feedbackKind(feedbackCleanLine($get('feedback_kind'), 40)),
    'type'     => feedbackCleanLine($get('feedback_type'), 40),
    'models'   => feedbackCleanLine($get('feedback_models'), MGDB_FEEDBACK_MAX_MODELS + 20),
    'pub'      => feedbackCleanLine($get('feedback_publication'), MGDB_FEEDBACK_MAX_PUB + 20),
    'summary'  => feedbackCleanLine($get('feedback_summary'), MGDB_FEEDBACK_MAX_SUMMARY + 40),
    'details'  => feedbackCleanText($get('feedback_details'), MGDB_FEEDBACK_MAX_DETAILS + 200),
    'page'     => feedbackCleanLine($get('feedback_page'), MGDB_FEEDBACK_MAX_PAGE),
    'name'     => feedbackCleanLine($get('feedback_name'), MGDB_FEEDBACK_MAX_NAME + 20),
    'email'    => feedbackCleanLine($get('feedback_email'), MGDB_FEEDBACK_MAX_EMAIL + 20),
    'env'      => $get('feedback_env') !== '',
    'webinfo'  => feedbackCleanText($get('feedback_webinfo'), 1500),
    'started'  => $get('feedback_started'),
    /* Honeypot. Named for something a form filler would expect to find and
       hidden from people in CSS, so anything in it came from a script. */
    'website'  => trim($get('feedback_website')),
  );

  if (!in_array($input['type'], feedbackTypeKeys(), true)) {
    $input['type'] = 'general';
  }

  return $input;
}

/* Single line: collapse whitespace, drop control characters, cap length.
   Header injection is the reason the newline strip is not optional — `email`
   and `fullname` reach Jira as form values, but `summary` can end up in mail
   notifications. */
function feedbackCleanLine($value, $max) {
  $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value);
  $value = preg_replace('/\s+/u', ' ', $value);
  $value = trim($value);
  return feedbackTruncate($value, $max);
}

/* Multi-line: keep the paragraphs, drop the control characters that are not
   newlines or tabs, normalize line endings. */
function feedbackCleanText($value, $max) {
  $value = str_replace(array("\r\n", "\r"), "\n", (string) $value);
  $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $value);
  $value = preg_replace('/\n{4,}/', "\n\n\n", $value);
  return feedbackTruncate(trim($value), $max);
}

function feedbackTruncate($value, $max) {
  if (function_exists('mb_substr') && mb_strlen($value, 'UTF-8') > $max) {
    return mb_substr($value, 0, $max, 'UTF-8');
  }
  if (!function_exists('mb_substr') && strlen($value) > $max) {
    return substr($value, 0, $max);
  }
  return $value;
}

function feedbackLength($value) {
  return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}


/////
// Validation
//
// Keyed by the form's own field names so a caller can hang each message under
// the control it belongs to. Only summary and details are required: asking for
// a name and an address before a reader can report a broken page is how a
// feedback form goes unused.
/////

function feedbackValidate($input) {
  $errors = array();
  $kinds = feedbackKinds();
  $spec  = $kinds[feedbackKind(isset($input['kind']) ? $input['kind'] : 'site')];

  if ($input['summary'] === '') {
    $errors['summary'] = 'Enter a short subject so the message can be sorted.';
  } else if (feedbackLength($input['summary']) > MGDB_FEEDBACK_MAX_SUMMARY) {
    $errors['summary'] = 'Keep the subject to ' . MGDB_FEEDBACK_MAX_SUMMARY . ' characters or fewer.';
  }

  if ($input['details'] === '') {
    $errors['details'] = 'Tell us what happened, or what you would like changed.';
  } else if (feedbackLength($input['details']) < MGDB_FEEDBACK_MIN_DETAILS) {
    $errors['details'] = 'Add a little more detail — a sentence or two is enough.';
  } else if (feedbackLength($input['details']) > MGDB_FEEDBACK_MAX_DETAILS) {
    $errors['details'] = 'Keep the message to ' . number_format(MGDB_FEEDBACK_MAX_DETAILS) . ' characters or fewer.';
  }

  /* The gene model collector marks both contact fields required in its own
     labels, and an assembly report is worth nothing if the curator cannot ask a
     follow-up question. The site form asks for neither. */
  $contact_required = $spec['contact'] === 'required';

  if ($input['email'] === '') {
    if ($contact_required) {
      $errors['email'] = 'An address is needed so a curator can follow up on the report.';
    }
  } else if (feedbackLength($input['email']) > MGDB_FEEDBACK_MAX_EMAIL
             || !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = $contact_required
      ? 'Check the address — a curator may need to follow up on the report.'
      : 'Check the address, or leave it blank if you do not need a reply.';
  }

  if ($input['name'] === '') {
    if ($contact_required) {
      $errors['name'] = 'Enter your name so the report can be credited and followed up.';
    }
  } else if (feedbackLength($input['name']) > MGDB_FEEDBACK_MAX_NAME) {
    $errors['name'] = 'Keep the name to ' . MGDB_FEEDBACK_MAX_NAME . ' characters or fewer.';
  }

  if (!empty($spec['models'])) {
    if ($input['models'] === '') {
      $errors['models'] = 'Name at least one gene model, locus, or region the report is about.';
    } else if (feedbackLength($input['models']) > MGDB_FEEDBACK_MAX_MODELS) {
      $errors['models'] = 'Keep this to ' . MGDB_FEEDBACK_MAX_MODELS . ' characters or fewer.';
    }
    if (feedbackLength($input['pub']) > MGDB_FEEDBACK_MAX_PUB) {
      $errors['publication'] = 'Keep this to ' . MGDB_FEEDBACK_MAX_PUB . ' characters or fewer.';
    }
  }

  if ($input['page'] !== '' && !feedbackPageLooksReal($input['page'])) {
    $errors['page'] = 'Enter a web address, or leave this blank.';
  }

  return $errors;
}

/* Deliberately loose: a reader pasting `maizegdb.org/gene_center/gene` without
   a scheme is giving us what we asked for. What this rejects is a value that is
   not an address at all, which is the shape a script fills it with. */
function feedbackPageLooksReal($page) {
  if (preg_match('#^https?://#i', $page)) {
    return filter_var($page, FILTER_VALIDATE_URL) !== false;
  }
  return (bool) preg_match('#^[A-Za-z0-9.\-/_~%?&=+:#\[\]@!$\'()*,;]+$#', $page);
}

/* Signals that this was not a person filling in a form. Returned separately
   from feedbackValidate() because the answer is never shown as a field error —
   a bot that is told which check it failed only learns how to pass it. */
function feedbackLooksAutomated($input) {
  if ($input['website'] !== '') {
    return 'honeypot';
  }
  if ($input['started'] !== '' && ctype_digit((string) $input['started'])) {
    /* The browser writes milliseconds since the epoch when the form is opened.
       Clock skew between a visitor's machine and the server makes the absolute
       value useless, so only an implausibly *small* elapsed time is treated as
       a signal, and a negative one (skew) is ignored. */
    $elapsed = (time() * 1000 - (float) $input['started']) / 1000;
    if ($elapsed >= 0 && $elapsed < MGDB_FEEDBACK_MIN_SECONDS) {
      return 'too-fast';
    }
  }
  return '';
}


/////
// Rate limiting
//
// One small JSON file per address hash. Fails open on every filesystem problem:
// a feedback form that silently swallows messages because a directory is not
// writable is worse than one that lets a determined sender through twice.
/////

function feedbackRatePath($system) {
  if (!empty($system['feedback_rate_path'])) {
    return rtrim($system['feedback_rate_path'], '/');
  }
  if (!empty($system['search_cache_path'])) {
    return rtrim($system['search_cache_path'], '/') . '/feedback';
  }
  return '';
}

function feedbackRateAllows($system, $address) {
  $dir = feedbackRatePath($system);
  if ($dir === '' || $address === '') {
    return true;
  }
  if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    return true;
  }
  if (!is_writable($dir)) {
    return true;
  }

  $file  = $dir . '/' . sha1($address) . '.json';
  $now   = time();
  $stamps = array();

  if (is_file($file)) {
    $raw = @file_get_contents($file);
    if ($raw !== false) {
      $decoded = json_decode($raw, true);
      if (is_array($decoded)) {
        foreach ($decoded as $stamp) {
          if (is_numeric($stamp) && ($now - $stamp) < MGDB_FEEDBACK_WINDOW) {
            $stamps[] = (int) $stamp;
          }
        }
      }
    }
  }

  if (count($stamps) >= MGDB_FEEDBACK_MAX_PER_WINDOW) {
    return false;
  }

  $stamps[] = $now;
  $tmp = $file . '.' . getmypid() . '.tmp';
  if (@file_put_contents($tmp, json_encode($stamps)) !== false) {
    @rename($tmp, $file);
  }

  /* Occasional sweep rather than a cron entry: one visitor in fifty pays for
     it, and the directory holds one small file per sender per hour. */
  if (mt_rand(1, 50) === 1) {
    feedbackRateSweep($dir, $now);
  }

  return true;
}

function feedbackRateSweep($dir, $now) {
  $files = @glob($dir . '/*.json');
  if (!is_array($files)) {
    return;
  }
  foreach ($files as $file) {
    $mtime = @filemtime($file);
    if ($mtime !== false && ($now - $mtime) > (MGDB_FEEDBACK_WINDOW * 2)) {
      @unlink($file);
    }
  }
}

/* The address the request came from, honoring the proxy header only when it is
   present — the dev instance sits behind Cloudflare, so REMOTE_ADDR there is
   the edge, not the sender. */
function feedbackRemoteAddress() {
  foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR') as $key) {
    if (!empty($_SERVER[$key])) {
      $value = explode(',', $_SERVER[$key]);
      $value = trim($value[0]);
      if ($value !== '') {
        return $value;
      }
    }
  }
  return '';
}


/////
// Composing the issue
/////

function feedbackSummaryLine($input) {
  /* The gene model collector files into a project of its own, so its issues
     need no tag to be sortable — the summary is the reporter's own words. */
  if (feedbackKind($input['kind']) !== 'site') {
    return $input['summary'];
  }
  $types = feedbackTypes();
  $tag   = isset($types[$input['type']]) ? $types[$input['type']]['tag'] : 'Feedback';
  return '[' . $tag . '] ' . $input['summary'];
}

function feedbackDescriptionBody($input, $context) {
  $lines = array($input['details'], '', '----');

  if (feedbackKind($input['kind']) === 'site') {
    $types = feedbackTypes();
    $label = isset($types[$input['type']]) ? $types[$input['type']]['label'] : 'General feedback';
    $lines[] = 'Type: ' . $label;
  } else {
    /* Repeated from the custom fields on purpose: a description that stands on
       its own survives being quoted into an email or another tracker. */
    if ($input['models'] !== '') {
      $lines[] = 'Affected gene models or loci: ' . $input['models'];
    }
    if ($input['pub'] !== '') {
      $lines[] = 'Publication: ' . $input['pub'];
    }
  }

  if ($input['page'] !== '') {
    $lines[] = 'Page: ' . $input['page'];
  }
  if ($input['name'] !== '') {
    $lines[] = 'From: ' . $input['name'];
  }
  $lines[] = 'Reply to: ' . ($input['email'] !== '' ? $input['email'] : 'not given');
  $lines[] = 'Sent from: the MaizeGDB feedback form'
           . (!empty($context['host']) ? ' on ' . $context['host'] : '');

  return implode("\n", $lines);
}

/* What the collector's own consent checkbox covers: the environment the message
   was sent from. Only sent when the reader leaves the box ticked, and never
   carrying the sender's address — the queue does not need it and it is not what
   the checkbox asks permission for. */
function feedbackWebInfo($input, $context) {
  if (!$input['env']) {
    return '';
  }

  $lines = array();
  if ($input['page'] !== '') {
    $lines[] = 'Page: ' . $input['page'];
  }
  if (!empty($context['referer'])) {
    $lines[] = 'Came from: ' . $context['referer'];
  }
  if (!empty($context['agent'])) {
    $lines[] = 'Browser: ' . $context['agent'];
  }
  if ($input['webinfo'] !== '') {
    $lines[] = $input['webinfo'];
  }
  $lines[] = 'Received: ' . date('c');

  return implode("\n", $lines);
}


/////
// The call itself
/////

function feedbackSubmit($system, $input, $context = array()) {
  $kind   = feedbackKind($input['kind']);
  $config = feedbackConfig($system, $kind);

  if (!$config['enabled']) {
    return array(
      'ok'      => false,
      'errors'  => array(),
      'message' => 'The feedback form is turned off on this instance. Write to mgdb-tech@iastate.edu instead.',
    );
  }

  $fields = array(
    'pid'         => $config['pid'],
    'summary'     => feedbackSummaryLine($input),
    'description' => feedbackDescriptionBody($input, $context),
  );

  if ($input['name'] !== '') {
    $fields['fullname'] = $input['name'];
  }
  if ($input['email'] !== '') {
    $fields['email'] = $input['email'];
  }

  /* The gene model collector's own custom fields, by the ids its form template
     declares. They are what make a report findable by gene model later. */
  if ($kind === 'gene_model') {
    if ($input['models'] !== '') {
      $fields['customfield_10050'] = $input['models'];
    }
    if ($input['pub'] !== '') {
      $fields['customfield_10051'] = $input['pub'];
    }
  }

  $webInfo = feedbackWebInfo($input, $context);
  if ($webInfo !== '') {
    $fields['recordWebInfo'] = 'on';
    $fields['webInfo']       = $webInfo;
  }

  $url = $config['base'] . '/template/custom/' . rawurlencode($config['collector']);
  $reply = feedbackPostToCollector($url, $fields);

  if (!$reply['ok']) {
    logMessage('feedback: collector unreachable — ' . $reply['message']);
    return array(
      'ok'      => false,
      'errors'  => array(),
      'message' => 'The message could not be delivered to the issue tracker just now. '
                 . 'Try again in a few minutes, or write to mgdb-tech@iastate.edu.',
    );
  }

  $parsed = feedbackParseCollectorReply($reply['body']);

  if (!empty($parsed['errors'])) {
    logMessage('feedback: collector rejected the message — ' . json_encode($parsed['errors']));
    return array('ok' => false, 'errors' => $parsed['errors'], 'message' => '');
  }
  if (!empty($parsed['errorMessages'])) {
    logMessage('feedback: collector returned ' . json_encode($parsed['errorMessages']));
    return array(
      'ok'      => false,
      'errors'  => array(),
      'message' => 'The issue tracker refused the message. Write to mgdb-tech@iastate.edu and we will log it by hand.',
    );
  }

  logMessage('feedback: logged ' . ($parsed['key'] !== '' ? $parsed['key'] : 'an issue')
             . ' (' . $kind . '/' . $input['type'] . ')');

  return array('ok' => true, 'key' => $parsed['key'], 'errors' => array(), 'message' => '');
}

function feedbackPostToCollector($url, $fields) {
  if (!function_exists('curl_init')) {
    return array('ok' => false, 'body' => '', 'message' => 'cURL is not available to PHP');
  }

  $handle = curl_init($url);
  curl_setopt_array($handle, array(
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($fields, '', '&'),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => array(
      'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
      'X-Atlassian-Token: no-check',
      'Accept: text/html, application/json',
    ),
    CURLOPT_USERAGENT      => 'MaizeGDB feedback form',
  ));

  $body   = curl_exec($handle);
  $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
  $error  = curl_error($handle);
  curl_close($handle);

  if ($body === false) {
    return array('ok' => false, 'body' => '', 'message' => $error !== '' ? $error : 'no response');
  }
  if ($status < 200 || $status >= 300) {
    return array('ok' => false, 'body' => (string) $body, 'message' => 'HTTP ' . $status);
  }

  return array('ok' => true, 'body' => (string) $body, 'message' => '');
}

/* The collector answers with its JSON inside a <textarea>, entity-encoded, so
   the iframe that normally posts the form can read it back out. Some errors
   come back as bare JSON, so both shapes are accepted. */
function feedbackParseCollectorReply($body) {
  $json = trim($body);

  if (preg_match('#<textarea[^>]*>(.*?)</textarea>#si', $body, $match)) {
    $json = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
  }

  $decoded = json_decode(trim($json), true);
  $result  = array('key' => '', 'errors' => array(), 'errorMessages' => array());

  if (!is_array($decoded)) {
    /* No JSON at all. The collector answered 2xx, so treat it as delivered
       rather than telling a reader their message failed when it did not. */
    return $result;
  }

  if (!empty($decoded['errors']) && is_array($decoded['errors'])) {
    $result['errors'] = $decoded['errors'];
  }
  if (!empty($decoded['errorMessages']) && is_array($decoded['errorMessages'])) {
    $result['errorMessages'] = $decoded['errorMessages'];
  }

  foreach (array('issueKey', 'key', 'issue_key') as $field) {
    if (!empty($decoded[$field]) && is_string($decoded[$field])) {
      $result['key'] = $decoded[$field];
      break;
    }
  }
  if ($result['key'] === '' && !empty($decoded['issue']['key'])) {
    $result['key'] = $decoded['issue']['key'];
  }

  return $result;
}

/* The collector names its own fields; the form names two of them differently.
   Anything else it complains about is shown as a whole-form message rather than
   attached to a control the reader cannot see. */
function feedbackMapCollectorErrors($errors) {
  $map = array('summary' => 'summary', 'description' => 'details',
               'email' => 'email', 'fullname' => 'name',
               'customfield_10050' => 'models', 'customfield_10051' => 'publication');
  $mapped = array();
  $other  = array();

  foreach ($errors as $field => $message) {
    if (isset($map[$field])) {
      $mapped[$map[$field]] = (string) $message;
    } else {
      $other[] = (string) $message;
    }
  }

  return array('fields' => $mapped, 'other' => $other);
}


/////
// The form
//
// Rendered here rather than in the page template because two places need it:
// /feedback itself, and the dialog the header button opens on every other page,
// which fetches /feedback?embed=form. One copy means the dialog cannot drift
// from the page.
/////

function feedbackFormMarkup($options = array()) {
  $values  = isset($options['values'])  ? $options['values']  : array();
  $errors  = isset($options['errors'])  ? $options['errors']  : array();
  $inDialog = !empty($options['dialog']);
  $kind    = feedbackKind(isset($options['kind']) ? $options['kind'] : 'site');
  $kinds   = feedbackKinds();
  $spec    = $kinds[$kind];

  $value = function ($key, $fallback = '') use ($values) {
    return isset($values[$key]) && $values[$key] !== '' ? $values[$key] : $fallback;
  };
  $esc = function ($text) {
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
  };
  $errorFor = function ($field) use ($errors, $esc) {
    if (empty($errors[$field])) {
      return '';
    }
    return '<p class="mgdb-field-error" id="feedback-' . $field . '-error">'
         . $esc($errors[$field]) . '</p>';
  };
  $describedBy = function ($field, $hintId) use ($errors) {
    $ids = $hintId !== '' ? array($hintId) : array();
    if (!empty($errors[$field])) {
      $ids[] = 'feedback-' . $field . '-error';
    }
    return $ids ? ' aria-describedby="' . implode(' ', $ids) . '"' : '';
  };
  $invalid = function ($field) use ($errors) {
    return empty($errors[$field]) ? '' : ' aria-invalid="true"';
  };

  $selectedType = $value('type', 'general');
  $html = array();

  /* The dialog builds its own heading, and gets the words from here rather
     than carrying a copy of them in the script: one place says what each kind
     of report is called. */
  $html[] = '<form class="mgdb-feedback-form" id="feedback-form" method="post" action="/feedback" novalidate'
          . ' data-feedback-kind="' . $esc($kind) . '"'
          . ' data-feedback-title="' . $esc($spec['title']) . '"'
          . ' data-feedback-intro="' . $esc($spec['intro']) . '"'
          . ($inDialog ? ' data-feedback-dialog-form="1"' : '') . '>';

  $html[] = '<input type="hidden" name="feedback_kind" value="' . $esc($kind) . '">';

  /* Where a failed submission's message goes, and where the dialog writes the
     confirmation. Empty and hidden until something needs saying. */
  $html[] = '<div class="mgdb-feedback-status" id="feedback-status" role="status" aria-live="polite" hidden></div>';

  /* Only the site form asks what kind of message this is. A gene model report
     is one kind of thing, and its collector files into a project of its own. */
  if (!empty($spec['types'])) {
    $html[] = '<fieldset class="mgdb-feedback-types">';
    $html[] = '<legend class="mgdb-label">What is this about?</legend>';
    $html[] = '<div class="mgdb-feedback-type-row">';
    foreach (feedbackTypes() as $key => $type) {
      $id = 'feedback-type-' . $key;
      $html[] = '<label class="mgdb-feedback-type" for="' . $id . '">'
              . '<input type="radio" name="feedback_type" id="' . $id . '" value="' . $esc($key) . '"'
              . ($selectedType === $key ? ' checked' : '') . '>'
              . '<span class="mgdb-feedback-type-label">' . $esc($type['label']) . '</span>'
              . '<span class="mgdb-feedback-type-hint">' . $esc($type['hint']) . '</span>'
              . '</label>';
    }
    $html[] = '</div>';
    $html[] = '</fieldset>';
  }

  /* The collector's own "Affected gene models and/or loci". First on the form,
     because it is the field that decides where the report lands in triage, and
     because arriving from a gene record it is already filled in. */
  if (!empty($spec['models'])) {
    $html[] = '<div class="mgdb-field">'
            . '<label class="mgdb-label" for="feedback-models">Affected gene models or loci '
            . '<span class="mgdb-required" aria-hidden="true">*</span>'
            . '<span class="mgdb-visually-hidden">required</span></label>'
            . '<p class="mgdb-hint" id="feedback-models-hint">One or more, separated by commas. '
            . 'Example: <code>Zm00001eb067740</code>, <code>lg1</code>, or a region such as '
            . '<code>chr1:23,400,000-23,450,000</code></p>'
            . '<input class="mgdb-input" type="text" id="feedback-models" name="feedback_models" '
            . 'maxlength="' . MGDB_FEEDBACK_MAX_MODELS . '" required autocomplete="off" '
            . 'value="' . $esc($value('models')) . '"'
            . $describedBy('models', 'feedback-models-hint') . $invalid('models') . '>'
            . $errorFor('models')
            . '</div>';
  }

  $html[] = '<div class="mgdb-field">'
          . '<label class="mgdb-label" for="feedback-summary">Subject '
          . '<span class="mgdb-required" aria-hidden="true">*</span>'
          . '<span class="mgdb-visually-hidden">required</span></label>'
          . '<input class="mgdb-input" type="text" id="feedback-summary" name="feedback_summary" '
          . 'maxlength="' . MGDB_FEEDBACK_MAX_SUMMARY . '" required autocomplete="off" '
          . 'value="' . $esc($value('summary')) . '"'
          . $describedBy('summary', '') . $invalid('summary') . '>'
          . $errorFor('summary')
          . '</div>';

  $html[] = '<div class="mgdb-field">'
          . '<label class="mgdb-label" for="feedback-details">Message '
          . '<span class="mgdb-required" aria-hidden="true">*</span>'
          . '<span class="mgdb-visually-hidden">required</span></label>'
          . '<p class="mgdb-hint" id="feedback-details-hint">'
          . ($kind === 'site'
             ? 'For a data problem, name the record or gene model. For a broken page, say what you '
               . 'did and what happened.'
             : 'What is wrong with the model, and what the evidence is: read coverage, alignments, '
               . 'a related paper, or a comparison with another assembly.')
          . '</p>'
          . '<textarea class="mgdb-textarea mgdb-feedback-details" id="feedback-details" name="feedback_details" '
          . 'rows="6" maxlength="' . MGDB_FEEDBACK_MAX_DETAILS . '" required'
          . $describedBy('details', 'feedback-details-hint') . $invalid('details') . '>'
          . $esc($value('details')) . '</textarea>'
          . '<p class="mgdb-feedback-count" id="feedback-count" hidden></p>'
          . $errorFor('details')
          . '</div>';

  if (!empty($spec['models'])) {
    $html[] = '<div class="mgdb-field">'
            . '<label class="mgdb-label" for="feedback-publication">Publication</label>'
            . '<p class="mgdb-hint" id="feedback-publication-hint">A paper or preprint supporting the '
            . 'correction, if there is one. A DOI, PMID, or citation.</p>'
            . '<input class="mgdb-input" type="text" id="feedback-publication" name="feedback_publication" '
            . 'maxlength="' . MGDB_FEEDBACK_MAX_PUB . '" autocomplete="off" '
            . 'value="' . $esc($value('pub')) . '"'
            . $describedBy('publication', 'feedback-publication-hint') . $invalid('publication') . '>'
            . $errorFor('publication')
            . '</div>';
  }

  $html[] = '<div class="mgdb-field">'
          . '<label class="mgdb-label" for="feedback-page">Page this concerns</label>'
          . '<input class="mgdb-input" type="text" id="feedback-page" name="feedback_page" '
          . 'maxlength="' . MGDB_FEEDBACK_MAX_PAGE . '" autocomplete="off" '
          . 'value="' . $esc($value('page')) . '"'
          . $describedBy('page', '') . $invalid('page') . '>'
          . $errorFor('page')
          . '</div>';

  $needsContact = $spec['contact'] === 'required';
  $requiredMark = $needsContact
    ? ' <span class="mgdb-required" aria-hidden="true">*</span>'
      . '<span class="mgdb-visually-hidden">required</span>'
    : '';
  $requiredAttr = $needsContact ? ' required' : '';

  $html[] = '<div class="mgdb-feedback-pair">';
  $html[] = '<div class="mgdb-field">'
          . '<label class="mgdb-label" for="feedback-name">Your name' . $requiredMark . '</label>'
          . '<input class="mgdb-input" type="text" id="feedback-name" name="feedback_name" '
          . 'maxlength="' . MGDB_FEEDBACK_MAX_NAME . '" autocomplete="name"' . $requiredAttr . ' '
          . 'value="' . $esc($value('name')) . '"'
          . $describedBy('name', '') . $invalid('name') . '>'
          . $errorFor('name')
          . '</div>';
  /* The hint sits *under* this input, not above it like the others. Above, its
     height pushed the email input below the name input beside it, and the row
     read as broken — worse when the hint wrapped to two lines. */
  $html[] = '<div class="mgdb-field">'
          . '<label class="mgdb-label" for="feedback-email">Email' . $requiredMark . '</label>'
          . '<input class="mgdb-input" type="email" id="feedback-email" name="feedback_email" '
          . 'maxlength="' . MGDB_FEEDBACK_MAX_EMAIL . '" autocomplete="email"' . $requiredAttr . ' '
          . 'value="' . $esc($value('email')) . '"'
          . $describedBy('email', 'feedback-email-hint') . $invalid('email') . '>'
          . '<p class="mgdb-hint mgdb-feedback-help" id="feedback-email-hint">'
          . ($needsContact
             ? 'Used to follow up on the report. It is not published.'
             : 'Only used to reply. Leave blank to stay anonymous.')
          . '</p>'
          . $errorFor('email')
          . '</div>';
  $html[] = '</div>';

  $checked = array_key_exists('env', $values) ? !empty($values['env']) : true;
  $html[] = '<div class="mgdb-feedback-consent">'
          . '<label class="mgdb-feedback-check" for="feedback-env">'
          . '<input type="checkbox" id="feedback-env" name="feedback_env" value="1"'
          . ($checked ? ' checked' : '') . '>'
          . '<span>Include your browser and screen size with the message. '
          . 'It helps us reproduce a display problem.</span>'
          . '</label>'
          . '</div>';

  /* Not display:none — some scripts skip hidden inputs. Off-screen and out of
     the tab order, labelled so a screen reader is told to leave it alone. */
  $html[] = '<div class="mgdb-feedback-trap" aria-hidden="true">'
          . '<label for="feedback-website">Website — leave this field empty</label>'
          . '<input type="text" id="feedback-website" name="feedback_website" tabindex="-1" autocomplete="off">'
          . '</div>';

  $html[] = '<input type="hidden" name="feedback_started" id="feedback-started" value="">';
  $html[] = '<input type="hidden" name="feedback_webinfo" id="feedback-webinfo" value="">';

  $html[] = '<div class="mgdb-form-actions">'
          . '<button class="mgdb-button mgdb-button-primary" type="submit" id="feedback-submit">'
          . ($kind === 'site' ? 'Send feedback' : 'Send report') . '</button>'
          . ($inDialog
             ? '<button class="mgdb-button mgdb-button-quiet" type="button" data-feedback-close>Cancel</button>'
             : '<button class="mgdb-button mgdb-button-quiet" type="reset">Clear</button>')
          . '</div>';

  $html[] = '</form>';

  return implode("\n", $html);
}

}
?>
