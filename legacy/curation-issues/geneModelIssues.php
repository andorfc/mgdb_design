<?php
/* file: geneModelIssues.php
 *
 * purpose: list gene models with issues
 *
 * history:
 *   11/25/17  eksc  created
 *   01/26/24  eksc  adapted to cloud Jira
 */
 
  include_once("./include/jira_lib.php");
   
  $status  = getCGIParam('status', 'GP', '');
  $version = getCGIParam('version', 'GP', '');
  $internal = getCGIParam('internal', 'GP', '');
logMessage("geneModelIssues.php: status=$status, version=$version, internal=$internal");

  $status = ($status == "") ? "open" : $status; 

  $tmpl = $mgdb->get('body')->load('templates/curation/gene-model-issues.bau');
  $tmpl->get('issue_status')->replace($status);
  $tmpl->get('version')->replace($version);
  
  // Atm, this ignores status
  $issue_list = getJiraIssues('', 'gene model');  // '' = issues for all database components
//logVarDump($issue_list, "Found these issues:\n");

  for ($i=0; $i<count($issue_list); $i++) {
    unset($issue_list[$i]['summary']);
  }
logVarDump($issue_list, "Issue list");
  
  $tmpl->get('issue-count')->replace(count($issue_list));
  $tmpl->get('issue-list')->loop($issue_list);
?>
