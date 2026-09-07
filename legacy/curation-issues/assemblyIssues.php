<?php
/* file: assemblyIssues.php
 *
 * purpose: list assembly issues
 *
 * history:
 *   11/26/17  eksc  created
 *   01/28/24  eksc  adapted to cloud Jira
 */

  include_once("./include/jira_lib.php");
   
  $status  = getCGIParam('status', 'GP', '');
  $version = getCGIParam('version', 'GP', '');
  $internal = getCGIParam('internal', 'GP', '');
logMessage("assemblyIssues.php: status=$status, version=$version, internal=$internal");

  $status = ($status == "") ? "open" : $status; 

  $tmpl = $mgdb->get('body')->load('templates/curation/assembly-issues.bau');
  $tmpl->get('issue_status')->replace($status);
  $tmpl->get('version')->replace($version);
  
  // Atm, this ignores status
  $issue_list = getJiraIssues('', 'assembly');  // '' = issues for all database components
//logVarDump($issue_list, "Found these issues:\n");

  for ($i=0; $i<count($issue_list); $i++) {
    unset($issue_list[$i]['summary']);
    unset($issue_list[$i]['components']);
  }
//logVarDump($issue_list, "Issue list");
  
  $tmpl->get('issue-count')->replace(count($issue_list));
  $tmpl->get('issue-list')->loop($issue_list);

?>
