<?php
/* file: jira_lib.php
 *
 * purpose: functions for communicating with cloud Jira.
 *
 * Note: no success using the php curl_* functions. Tried:
 *   $curl_base = 'https://maizegdb.atlassian.net/rest/api/2/';
 *   $curl = curl_init();
 *   $options = array(
 *     CURLOPT_URL => "$url/issue/createmeta",
 *     CURLOPT_HEADER => false,
 *     CURLOPT_HTTPHEADER => Array("Content-Type: application/json"),
 *     CURLOPT_PROXY_SSL_VERIFYPEER => false,
 *     CURLOPT_HTTPGET => true,
 *     CURLOPT_RETURNTRANSFER => true,
 *     CURLOPT_PROXYUSERPWD => "$user:$key",
 *   );
 *   curl_setopt_array($curl, $options);
 *   $resp = curl_exec($curl);
 *     --> nada
 *
 * history
 *  01/25/24  eksc  created
 */

$system = getSystemInfo();

$jira_base = $system['issue_url'] . '/rest/api/3';

// Need to remove 's from around the api key
$api_key = preg_replace("/^'/", '', $system['issue_api_key']);
$api_key = preg_replace("'$'", '', $api_key);
$user = $system['issue_user'] . ":$api_key";

$cmd_base = "curl --insecure --header 'Accept: application/json' --user '$user' ";
//logMessage("jira_base: $jira_base\nuser: $user\ncmd_base: $cmd_base\n");

function getCustomJiraFields() {
  global $cmd_base, $jira_base;
  
  $custom_fields = array();
  
  $cmd = "$cmd_base --request GET --url $jira_base/field";
/* Never log $cmd itself: $cmd_base carries `--user '<address>:<api key>'`, so
   every call was writing the Jira API key into logs/mgdb.log in clear text. */
logMessage("Jira request: GET $jira_base/field");
  //echo $cmd;
  exec($cmd, $output, $ret);
  if ($ret != 0) {
    reportError("Request for field data from Jira failed with $ret");
    //return undef;
    return false;
  }
  $json = json_decode($output[0]);
  if ($json) {
	  $field_names = array('display_text', 'database_components', 'PMID_DOI');
	  foreach ($field_names as $field) {
		$idx = array_search($field, array_column($json, 'name'));
		if ($idx > -1) {
		  $custom_fields[$field] = $json[$idx]->id;
		}
	  }
  }
//logVarDump($custom_fields);
  
  return $custom_fields;
}//getCustomFields


function getJiraIssues($component, $issuetype='', $custom_fields=null) {
  global $cmd_base, $jira_base;
  /* jp tmp rm
  if ($component == '') {
    return false;
  }*/
  if (!$custom_fields) {
    $custom_fields = getCustomJiraFields();
  }

  $jql = 'project = ASMBLY AND status = Open';
  $jql .= ' AND (labels is empty OR labels != internal)';
  if ($component != '') {
    $jql .= " AND database_components ~ $component";
  }
  if ($issuetype == 'gene model') {
    $jql .= ' AND issuetype = "Gene/gene model issue"';
  }
  else if ($issuetype == 'assembly') {
    $jql .= ' AND issuetype = "Assembly issue"';
  }
logMessage("JQL: $jql");
   
  $cmd = "$cmd_base --request GET --url '$jira_base/search/jql?jql=" 
       . urlencode($jql) . "'";
/* The URL only. See the note in getCustomJiraFields() -- $cmd holds the API key. */
logMessage("Jira request: GET $jira_base/search/jql");
  exec($cmd, $output, $ret);
logVarDump($output, "Results:\n");
  if ($ret != 0) {
    reportError("Request for data from Jira failed with $ret");
    return false;
  }
  if (str_starts_with($output[0], 'error code')) {
    reportError("Request for data from Jira failed. " . $output[0]);
    return false;
  }
  
  // If we get here, all is well
  $json = json_decode($output[0]);
//logVarDump($json->issues, "Issues:\n");
  /* An empty result is not an error: it means nothing is currently open, which
     is the normal state. This used to be a bare `return`, so it handed back
     NULL, and count(NULL) is a TypeError in PHP 8 -- which is exactly what
     /curation/assemblyIssues and /curation/geneModelIssues were dying of, on
     every request, for as long as Jira had no open issue of their type. The
     four record-page callers guard with `if ($issues)`, and an empty array is
     falsy there too, so their behaviour is unchanged. A hard failure still
     returns false, so a caller that needs to tell "nothing open" from "Jira did
     not answer" can compare against it. */
  if (isset($json->issues) && count($json->issues) == 0) {
    return array();
  }
  
  if (!$json) {
	  return false;
  }
  
  /* Every one of these is a custom field that an issue is allowed not to have.
     The version read in particular was `customfield_10049[0]->value` with no
     check, so one issue filed without a version would have taken down every
     page that lists issues -- and the pages that consume this cannot see the
     difference between a missing field and a bad one. Each is now read only if
     it is there, and the key is always present so callers can rely on it. */
  $issues = array();
  foreach ($json->issues as $issue) {
    $fields = isset($issue->fields) ? $issue->fields : null;
    $version = '';
    if ($fields && isset($fields->customfield_10049) && is_array($fields->customfield_10049)
        && isset($fields->customfield_10049[0]->value)) {
      $version = $fields->customfield_10049[0]->value;
    }
    $issues[] = array(
      'summary'     => ($fields && isset($fields->summary))              ? $fields->summary              : '',
      'components'  => ($fields && isset($fields->customfield_10050))    ? $fields->customfield_10050    : '',
      'description' => ($fields && isset($fields->customfield_10048))    ? $fields->customfield_10048    : '',
      'version'     => $version
    );
  }

  return $issues;
}//getJiraIssues


?>