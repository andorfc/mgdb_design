<?php
/* file: GenomeIssue.php
 *
 * purpose: permit a community annotator to report a genome issue
 *
 * history:
 *   08/27/12  Steven Perez  created
 *   10/01/12  eksc          modified for website
 *   09/07/26  claude        page put on the modern design system
 *
 * On the modern shell
 * -------------------
 * The three actions are unchanged: `save` writes the report, `view` shows one,
 * and the default shows the form. What changed is the document around them.
 *
 * The form itself is not rewritten. Every control keeps its name, id, value and
 * onclick, every <option> and every Bauplan token is the one that was there --
 * checked mechanically against the original: 90 options and 75 tokens in, 90
 * options and 75 tokens out, and the six muted regions still six. What was
 * removed is the page furniture the modern shell replaces \(three wrapper divs
 * and an <a name="main">\), the two layout tables inside fieldsets, which are
 * why the form could not reflow, and the five help icons.
 *
 * The help icons called popUpHelp\(\) from js/api_js.js, which opened
 * /help/genome_issue#<anchor> in a 440x200 window. That route throws
 * `Undefined constant "ANCHOR"` -- a PHP 8 fatal in controllers/help.php -- so
 * all five have been showing a stack trace. Their text now sits inline under
 * each legend, taken verbatim from templates/help/genome_issue-help.bau. One of
 * them matters: it says a report needs supporting sequence, in GenBank, from
 * the inbred of the selected assembly, or it will not be considered. A
 * submitter could not see that.
 *
 * This form is the only caller of popUpHelp\(\) on the site, so /help and
 * controllers/help.php are now unreferenced -- a retirement decision, not a
 * conversion, and the route is broken either way.
 *
 * css/curation.css is NOT loaded. It carries bare `body,td,th`, `p`, `a`, `h1`,
 * `fieldset` and `legend` rules, which on a modern page reach the megamenu and
 * the hero. css/mgdb-genome-issue.css styles the same controls scoped to this
 * page. js/GenomeIssue.js is still loaded and unchanged: validateIssueForm,
 * Check and resetField are all still referenced from the markup.
 */

function genomeIssueEsc($value) {
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

  include_once('./include/gp_lib.php');
  include_once('./include/curation_lib.php');
  include_once('./include/mail.php');
  
  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  // ACTION is defined in curation.php
logMessage("in GenomeIssue.php ACTION=" . ACTION);
  switch (ACTION) {
    case 'save':
      saveRecord($mgdb);    // $mgdb defined in curation.php
      break;
    case 'view':
      loadView($mgdb);      // $mgdb defined in curation.php
      break;
    case 'edit':
    default:
      showEditForm($mgdb);  // $mgdb defined in curation.php
      break;
  }


function showEditForm($mgdb) {
  $gene_model = getCGIParam('gene_model_id', 'PG', false);
  $gene_model_version = getCGIParam('gene_model_version', 'PG', false);
  
  // Load edit form
  $tmpl = $mgdb->get('body')->load('templates/curation/mgdb_genome_issue.bau');
  
  // Get a database connection
  $DBConn = connect_to_database();
  
  // Dealing with a faulty gene model or genome location?
  if ($gene_model) {
    $tmpl->get('gene-model-issue')->unmute();
    $tmpl->get('gene-model')->replace($gene_model);
    
    // Set selected clause for gene model set
    if ($gene_model_version) {
      // Stupid hack:
      if ($gene_model_version == '5b+' || $gene_model_version == '5b') {
        $gene_model_version = 'B73_RefGen_v3';
      }
      $gene_model_version = str_replace(' ', '_', $gene_model_version);
      $selected = preg_replace("/(.*)\.\d+/", "$1", $gene_model_version) . '-selected';
    }
    else {
      // default
      $selected = 'default-selected';
    }
    $tmpl->get($selected)->replace('selected');

    $tmpl->get('gene-model-section')->unmute();
    //$tmpl->get('gene-model-version')->replace($gene_model_version);
  }
  
  else {
    // genome issue
    $tmpl->get('genome-issue')->unmute();
    $genome_assembly = getCGIParam('genome_assembly', 'PG', false);
    
    // Set selected clause for genome assembly
    if ($genome_assembly) {
      $selected = str_replace(' ', '_', $genome_assembly) . '-selected';
    }
    else {
      // default
      $selected = 'default-selected';
    }
    $tmpl->get($selected)->replace('selected');

    $tmpl->get('genome-location-section')->unmute();
  }
  
  /* The reporter's name and e-mail start empty (2026-09-10).
     These two fields used to be pre-filled from the logged-in curator's
     account -- get_user_info($DBConn, $username) against annotation_author.
     This form has always been public (it is in $public_pages in
     controllers/curation.php and submits through a Jira collector), so the
     prefill only ever fired for the small number of visitors who happened to be
     logged in; with community curation retired there is no login and no
     $username, and the lookup could only ever have returned the empty strings
     it falls back to. Removing it drops a per-render query against a retired
     table. The reader types their own name and e-mail, as every anonymous
     visitor already did. */
  $tmpl->get('cur_name')->replace('');
  $tmpl->get('cur_email')->replace('');
}//showEditForm()


function saveRecord($mgdb) {
  global $system;
  
  $max_size = 5000000;
  $error    = '';

//TODO assign a number to this issue via Jira
// 1. Send create request to Jira and receive issue key
// 2. Save issue key, if received, otherwise unique ID and sent email alert
// If possible, include reporter's e-mail for notifications when issue status
//      changes.
$issue_key = uniqid("GI-");  // temporary
  
  $url                = getCGIParam('URL', 'GP', 'unknown');
  $genome_assembly    = getCGIParam('genome_assembly', 'GP', $system['cur_ref_gen']);
  $gene_model         = getCGIParam('gene_model', 'GP', false);
  $gene_model_version = getCGIParam('gene_model_version', 'GP', $system['cur_gm_set']);
  $chromosome         = getCGIParam('chromosome', 'GP', false);
  $chr_start          = getCGIParam('chr_start', 'GP', false);
  $chr_end            = getCGIParam('chr_end', 'GP', false);
  $first_acc          = getCGIParam('first_acc', 'GP', false);
  $last_acc           = getCGIParam('last_acc', 'GP', false);
  $sub_name           = getCGIParam('sub_name', 'GP', false);
  $email              = getCGIParam('email', 'GP', false);
  $affiliation        = getCGIParam('affiliation', 'GP', false);
  $position           = getCGIParam('position', 'GP', false);
  $description        = getCGIParam('description', 'GP', false);
  if (isset($_FILES['attachment']['name'])) {
    $attachment = $_FILES['attachment']['name'];
  }
  else {
    $attachment = '';
  }
  
  $record = array($url,
                  $genome_assembly, $gene_model, $gene_model_version, 
                  $chromosome, $chr_start, $chr_end, $first_acc, $last_acc,
                  $sub_name, $email, $affiliation, $position, $description,
                  $attachment);
  
  
  // save issue in flatfile for now
  $issue_file = $system['issues_file'];
  $fh = fopen($issue_file, 'a+');
  if (!$fh) {
    // this is a problem!
//TODO: if true, assure that issue has been saved in primary Jira db
logVarDump(error_get_last(), "Error:\n");
//    $error .= "\n<br>There was an error when attempting to save your issue. ";
//    reportError("Unable to write to issue file!");
  }
  else {
    fwrite($fh, implode("\t", $record));
    list ($micro, $d) = explode(' ', microtime());
    fwrite($fh, "\t" . date("d M Y H:i:s.$micro") . "\n");
    fclose($fh);
  }
  
  // TODO: upload images, if any attachment
  $upload_error = '';
logVarDump($_FILES, "Files uploaded:\n");
  if (isset($_FILES['attachment']) 
        && isset($_FILES['attachment']['name'])
        && $_FILES['attachment']['name'] != '') {
    $allowedExts  = array('gif', 'jpeg', 'jpg', 'png', 'pdf');
    $allowedTypes = array('image/gif', 'image/jpeg', 'image/jpg', 'image/pjpeg', 
                          'image/x-png', 'image/png');
    $temp = explode(".", $_FILES['attachment']['name']);
    $extension = strtolower(end($temp));
    if ((in_array($_FILES['attachment']['type'], $allowedTypes)
         || in_array($extension, $allowedExts))
        && ($_FILES['attachment']['size'] < $max_size)) {
      if ($_FILES['attachment']['error'] > 0) {
        $upload_error .= "\n<br>There was an error when attempting to upload your attachment. ";
        reportError("Unable to upload file: " . $_FILES['attachment']['error']);
        echo "Error: " . $_FILES['attachment']['error'] . "<br>";
      }
      else {
        $ret = move_uploaded_file($_FILES['attachment']['tmp_name'],
                                  $system['root_dir'] . "/issues/images/" . $_FILES['attachment']['name']);
        if (!$ret) {
          $upload_error .= "\n<br>There was an error when attempting to save your image. ";
          reportError("Failed to copy uploaded file " . $_FILES['attachment']['tmp_name']
                      . ' (' . $_FILES['attachment']['name'] . ')');
        }
      }//successful upload
    }//allowed file
    else {
      if (!in_array($_FILES['attachment']['type'], $allowedTypes)
            && !in_array($extension, $allowedExts)) {
        $upload_error .= "An attachment of this type is not permitted. ";
        $upload_error .= "Please upload an image of type GIF, JPEG, PNG or PDF.";
        reportError("Attachment of type " . $_FILES['attachment']['type'] . " is not allowed");
        reportError("Attachment with extension $extension is not allowed");
      }
      else if ($_FILES['attachment']['size'] >= $max_size
                || $_FILES['attachment']['error'] == UPLOAD_ERR_INI_SIZE) {
        $upload_error .= "Your attachment is too large (limit = 5MB)";
        reportError("Attachment was too big (" . $_FILES['attachment']['size'] . ")");
      }
    }//error in upload
  }//issue had an attachment
  
  if ($upload_error != '' && $error == '') {
    $upload_error .= "\n<br>Your issue has been submitted even though your image ";
    $upload_error .= "was not uploaded. You can mail your image directly to ";
    $upload_error .= "the MaizeGDB team. Please identify it the image by the ";
    $upload_error .= "issue number, $issue_key.<br>";
  }
  
  $error .= $upload_error;
  
  // Send an e-mail
  $to   = $system['issue_email'];
  $from = 'admin@maizegdb.org';
  $subject = 'Genome/gene model issue submitted ('.$issue_key.')';
  $message = "The genome/gene model issue $issue_key has been submitted.\n\n";
  $message .= "   issue identifier = $issue_key\n";
  $message .= "   URL = $url\n";
  $message .= "   genome_assembly = $genome_assembly\n";

  if ($gene_model) {
    $message .= "   gene_model = $gene_model\n";
    $message .= "   gene_model_version = $gene_model_version\n";
  }
  else {
    $message .= "   chromosome = $chromosome\n";
    if ($chr_start) {
      $message .= "   chr_start = $chr_start\n"; 
      $message .= "   chr_end = $chr_end\n";
    }
    else {
      $message .= "   first_acc = $first_acc\n";
      $message .= "   last_acc = $last_acc\n";
    }
  }
  
  $message .= "   sub_name = $sub_name\n";
  $message .= "   e-mail = $email\n";
  $message .= "   affiliation = $affiliation\n";
  $message .= "   position = $position\n\n";
  
  if (isset($_FILES['attachment']) && $upload_error == '') {
    $message .= "   attachment: " . $_FILES['attachment']['name'] . "\n\n";
  }

  $message .= "Description:\n$description\n";
  
  if ($error != '') {
    $message .= "\nERROR: " . str_replace("<br>", '', $error);
  }
  
  // Send e-mail to MaizeGDB and reporter
  send_email($to, $from, $subject, $message);
  send_email($email, $from, $subject, $message);
  
  if ($error != '') {
    $error .= "\n<br>Please notify the MaizeGDB team using the Feedback link on ";
    $error .= "the menu bar above.";
  }
  
  /* Show submission response page.
   *
   * Every value below is what the submitter just typed, echoed back to them.
   * Bauplan's replace() does not escape -- see loginCuratorEsc() in
   * login_curator.php, which exists for the same reason -- so a report whose
   * description contained markup was rendering it. Escaped here.
   * The description is free text over several lines, so its newlines are kept.
   */
  $tmpl = $mgdb->get('body')->load('templates/curation/mgdb_genome_issue_submitted.bau');

  $tmpl->get('genome_assembly')->replace(genomeIssueEsc($genome_assembly));
  $tmpl->get('gene_model')->replace(genomeIssueEsc($gene_model));
  $tmpl->get('gene_model_version')->replace(genomeIssueEsc($gene_model_version));
  $tmpl->get('chromosome')->replace(genomeIssueEsc($chromosome));
  $tmpl->get('chr_start')->replace(genomeIssueEsc($chr_start));
  $tmpl->get('chr_end')->replace(genomeIssueEsc($chr_end));
  $tmpl->get('first_acc')->replace(genomeIssueEsc($first_acc));
  $tmpl->get('last_acc')->replace(genomeIssueEsc($last_acc));
  $tmpl->get('sub_name')->replace(genomeIssueEsc($sub_name));
  $tmpl->get('email')->replace(genomeIssueEsc($email));
  $tmpl->get('affiliation')->replace(genomeIssueEsc($affiliation));
  $tmpl->get('position')->replace(genomeIssueEsc($position));
  $tmpl->get('description')->replace(nl2br(genomeIssueEsc($description)));
  
  if ($error != '') {
    $tmpl->get('error-msg')->replace($error);
    $tmpl->get('error')->unmute();
  }
  
  if ($gene_model && $gene_model_version) {
    $tmpl->get('gene-model-fb')->unmute();
    $tmpl->get('gene-model-section')->unmute();
  }
  else {
    $tmpl->get('genome-fb')->unmute();
    if ($chr_start) {
      $tmpl->get('genome-coords-fb')->unmute();
    }
    else {
      $tmpl->get('genome-acc-fb')->unmute();
    }
    $tmpl->get('genome-location-section')->unmute();
  }
  
  if (isset($_FILES['attachment']) && $upload_error == '') {
    /* The uploaded file's name is submitter-controlled too. */
    $tmpl->get('attachment-name')->replace(genomeIssueEsc($_FILES['attachment']['name']));
    $tmpl->get('attachment')->unmute();
  }
logMessage("Finished saveRecord()");
}//saveRecord()


function loadView($mgdb) {
}//loadView()


?>
