<?php
/* file: controllers/curation/genome_issue_modern.php
 *
 * purpose: put /curation/GenomeIssue on the shared Data Hub shell.
 *
 * This file is only the document. The page's own logic -- the three actions,
 * the form fill, the save, the upload handling and the notification e-mails --
 * stays in controllers/curation/GenomeIssue.php and is required unchanged from
 * here. Splitting it that way is deliberate: the save path writes a report and
 * sends mail, and the point of this change is the page around it, not that.
 *
 * Why it is a separate file at all: controllers/curation.php builds the *legacy*
 * Bauplan -- static.css, jQuery 1.3.2 and jQuery UI 1.5.3 off Google's CDN, the
 * IE6 and IE8 conditional sheets -- before it requires the page controller, so a
 * modern page reached that way renders on top of all of it. The hook in
 * curation.php runs above that and comes here instead. controller.php claims the
 * whole /curation/* namespace, so there is no top-level shadow route to use.
 *
 * The form is not curator-only: GenomeIssue is in $public_pages in
 * controllers/curation.php, and is linked from the home page, the Genome Browser
 * megamenu panel and hub, the Gene Data Hub, the NAM and PanAnd project pages,
 * assembly pages and BAC records.
 *
 * Rollback: delete this file and the PAGE == 'GenomeIssue' block in
 * controllers/curation.php. templates/curation/genome-issue-form.bau and
 * genome-issue-submitted.bau are untouched and archived in
 * legacy/curation-issues/.
 */

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
            ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';

  $bauplan = new Bauplan('Report a genome or gene model issue | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  /* The shared Data Hub shell, before the page sheet. css/curation.css is
     deliberately absent: its bare `body,td,th`, `p`, `a`, `h1`, `fieldset` and
     `legend` rules would reach the megamenu and the hero. */
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-hub.css'));
  $bauplan->includeCss('/css/mgdb-genome-issue.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-genome-issue.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  /* jQuery, because the form's own script uses $() -- the legacy curation shell
     supplied 1.3.2 from Google's CDN and this page no longer loads that shell.
     3.7.1 from the same host the rest of the site uses. */
  $bauplan->includeScript('https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js');
  /* validateIssueForm, Check and resetField, all still referenced from the
     markup and all unchanged. */
  $bauplan->includeScript('/js/GenomeIssue.js');
  $bauplan->includeScript('/js/mgdb-genome-issue.js?v=' . (int) @filemtime($doc_root . '/js/mgdb-genome-issue.js'));
  $bauplan->head('<meta name="description" content="Report a problem with a maize reference genome assembly or a gene model to MaizeGDB: the assembly, the location or gene model, and a description.">');
  /* A form, and a confirmation page that echoes a submitter\'s own details. */
  $bauplan->head('<meta name="robots" content="noindex">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  /* The page itself. $username, $system and ACTION are all set by
     controllers/curation.php above. */
  require('controllers/curation/GenomeIssue.php');

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
  return true;
?>
