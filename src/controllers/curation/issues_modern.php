<?php
/* file: controllers/curation/issues_modern.php
 *
 * purpose: /curation/assemblyIssues and /curation/geneModelIssues -- the open
 *          assembly and gene model issues the community has reported -- on the
 *          shared Data Hub shell.
 *
 * One controller for both, because they are the same page with a different Jira
 * issue type and one extra column. Two copies is how the legacy pair drifted:
 * geneModelIssues.php still had an uncommented logVarDump() of the whole issue
 * list on every request and assemblyIssues.php did not.
 *
 * These are NOT curator-only pages. Both are in $public_pages in
 * controllers/curation.php, and both are linked from pages any reader reaches:
 * geneModelIssues from the home page and the Gene Data Hub, assemblyIssues from
 * the Gene Data Hub. The /curation/ prefix is where the code lives, not who the
 * page is for.
 *
 * What was wrong
 * --------------
 * **Both pages were dead.** getJiraIssues() answered an empty result set with a
 * bare `return`, which is NULL, and both controllers then called
 * count($issue_list). In PHP 8 that is a TypeError, so the page returned a
 * 440-byte fatal error instead of a page -- every time Jira had no open issue of
 * that type, which is the normal state and is the state today. The four
 * record-page callers of the same function guard with `if ($issues)` and were
 * unaffected, which is why this went unnoticed. Fixed in include/jira_lib.php:
 * an empty result is an empty array, a hard failure is still false, and this
 * controller tells the reader which of the two happened.
 *
 * The legacy pages also read `status` and `version` off the query string and
 * printed `status` into the heading -- "All $(issue_status) Assembly Issues" --
 * while the query they ran ignored both and always asked Jira for open issues.
 * So /curation/assemblyIssues?status=anything+at+all set the heading. That is a
 * reflected-input surface for no feature: the parameters do nothing, so they are
 * not read here, and the heading says "open" because the query does.
 *
 * Cost: one Jira request per view through include/jira_lib.php, which shells out
 * to curl. Not cached -- an issue list that is a few minutes stale is worse than
 * useless to someone checking whether their report was received, and the request
 * is a few hundred milliseconds.
 */

  include_once('./include/jira_lib.php');

  /* Which of the two pages this is. PAGE is defined by controller.php. */
  $ci_kind = (PAGE === 'assemblyIssues') ? 'assembly' : 'gene model';

  $ci_conf = ($ci_kind === 'assembly')
    ? array(
        'title'    => 'Assembly issues',
        'noun'     => 'assembly issue',
        'nouns'    => 'assembly issues',
        'blurb'    => 'Problems the maize community has reported in a reference genome assembly &mdash; a region that looks wrong, a sequence that is missing, or variation that is not well represented &mdash; and that MaizeGDB has open.',
        'columns'  => false,
        'report'   => '/curation/GenomeIssue',
      )
    : array(
        'title'    => 'Gene model issues',
        'noun'     => 'gene model issue',
        'nouns'    => 'gene model issues',
        'blurb'    => 'Problems the maize community has reported in a gene model &mdash; a wrong structure, a missing model, a merge or split that should not be &mdash; and that MaizeGDB has open.',
        'columns'  => true,
        'report'   => '/curation/GenomeIssue?gene_model_id=',
      );

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
            ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';

function ci_esc($v) {
  return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/**
 * The issue table, or the reason there is not one.
 *
 * Three outcomes, and the page has to say which: Jira did not answer, Jira
 * answered with nothing open, or here they are. The legacy pages could only
 * express the third, and crashed on the second.
 *
 * Every value is escaped. `description` and `components` are free text a
 * reporter typed into a Jira form; nothing between that form and this page
 * sanitises them.
 */
function ci_render_issues($issues, $conf) {
  if ($issues === false) {
    return '<div class="mgdb-message mgdb-message-warning" role="status">'
         . '<strong>The issue tracker did not answer.</strong> '
         . 'This list is read live from MaizeGDB&#39;s issue tracker, and that request failed. '
         . 'Nothing is wrong with the issues themselves &#8212; try again shortly.'
         . '</div>';
  }

  if (!$issues) {
    return '<div class="ci-empty" role="status">'
         . '<p class="ci-empty-mark" aria-hidden="true">0</p>'
         . '<div><strong>No open ' . $conf['nouns'] . '.</strong>'
         . '<p>Everything reported has been resolved. If you have found something, '
         . '<a href="' . ci_esc($conf['report']) . '">report it</a> and it will appear here.</p></div>'
         . '</div>';
  }

  $head = '<tr>';
  if ($conf['columns']) { $head .= '<th scope="col">Gene model</th>'; }
  $head .= '<th scope="col">Assembly</th><th scope="col">Description</th></tr>';

  $body = '';
  foreach ($issues as $issue) {
    $body .= '<tr>';
    if ($conf['columns']) {
      $c = trim((string) (isset($issue['components']) ? $issue['components'] : ''));
      $body .= '<th scope="row" class="ci-model">'
             . ($c !== '' ? ci_esc($c) : '<span class="ci-none">not stated</span>')
             . '</th>';
    }
    $v = trim((string) (isset($issue['version']) ? $issue['version'] : ''));
    $body .= '<td class="ci-version">'
           . ($v !== '' ? ci_esc($v) : '<span class="ci-none">not stated</span>')
           . '</td>';
    $d = trim((string) (isset($issue['description']) ? $issue['description'] : ''));
    $body .= '<td>'
           . ($d !== '' ? nl2br(ci_esc($d)) : '<span class="ci-none">no description</span>')
           . '</td>';
    $body .= '</tr>';
  }

  return '<div class="mgdb-table-scroll"><table class="mgdb-table ci-table">'
       . '<thead>' . $head . '</thead><tbody>' . $body . '</tbody></table></div>';
}

/* -------------------------------------------------------------------------- *
 * The document
 * -------------------------------------------------------------------------- */

  $ci_issues = getJiraIssues('', $ci_kind);
  $ci_count  = is_array($ci_issues) ? count($ci_issues) : 0;

  $bauplan = new Bauplan($ci_conf['title'] . ' | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  /* The shared Data Hub shell, before the page sheet -- the ground, the white
     section cards, their coloured top edges, the shared table and message, and
     the green Related resources panel. */
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-hub.css'));
  $bauplan->includeCss('/css/mgdb-curation-issues.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-curation-issues.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-curation-issues.js?v=' . (int) @filemtime($doc_root . '/js/mgdb-curation-issues.js'));
  $bauplan->head('<meta name="description" content="Open ' . $ci_conf['nouns']
      . ' reported by the maize community to MaizeGDB, read live from the issue tracker.">');
  /* A list that changes as issues are opened and closed, and which a reporter
     checks to see whether their report arrived. */
  $bauplan->head('<meta name="robots" content="noindex">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/curation/mgdb_curation_issues.bau');

  $body->get('page_title')->replace(ci_esc($ci_conf['title']));
  $body->get('page_blurb')->replace($ci_conf['blurb']);
  $body->get('issue_table')->replace(ci_render_issues($ci_issues, $ci_conf));
  $body->get('issue_count')->replace(
    $ci_issues === false ? '&mdash;' : number_format($ci_count));
  $body->get('issue_noun')->replace($ci_count === 1 ? $ci_conf['noun'] : $ci_conf['nouns']);
  $body->get('other_url')->replace(
    $ci_kind === 'assembly' ? '/curation/geneModelIssues' : '/curation/assemblyIssues');
  $body->get('other_label')->replace(
    $ci_kind === 'assembly' ? 'Gene model issues' : 'Assembly issues');
  $body->get('other_blurb')->replace(
    $ci_kind === 'assembly'
      ? 'The same list for problems reported in a gene model rather than in the assembly'
      : 'The same list for problems reported in a reference assembly rather than in a gene model');

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
  return true;
?>
