<?php
/* file: annotation_lib.php
 *
 * purpose: common tasks for user annotation
 *
 * history:
 *  08/22/12  eksc  created
 *  07/07/15  bbraun  added JIRA issues functionality
 */

include_once($_SERVER['DOCUMENT_ROOT'] . "/record_data/gene_data_lib.php"); // WEB-189: The function isGeneModel() is declared in this file and needs to be included to avoid a php error for some records in the annotation section


function filterOntTerms($arrOntTerms, $DBConn) {
  // Remove terms which are parents to other terms in the list
  
  // Get all parental terms
  $parents = array();
  foreach ($arrOntTerms as $t) {
    $name = pg_escape_string($t['name']);
    // NOTE: using "IN" for term select clause in case the term appears in 
    //       multiple ontologies. It may not be possible to know in advance
    //       which ontology is targeted. (Because GO is split into 3 separate
    //       ontologies, which are not identified in ID_ONTOLOGY table.) 
    $sql = "
      SELECT DISTINCT o.name FROM chado.cvtermpath p
        INNER JOIN chado.cvterm o ON o.cvterm_id=p.object_id
      WHERE p.pathdistance > 0
            AND subject_id IN (SELECT cvterm_id FROM chado.cvterm WHERE name='$name')
            AND p.type_id IN (SELECT cvterm_id FROM chado.cvterm WHERE name='IS_A')";
    $sth = make_query($DBConn, $sql);
    $rows = get_all_rows($sth);
    foreach ($rows as $r) {
      $parents[$r['name']] = $t['name'];
    }
  }//each term
  
  // Remove all terms that appear as parents
  $filtered_list = array();
  $seen = array();
  foreach ($arrOntTerms as $t) {
    if (!isset($parents[$t['name']]) && !isset($seen[$t['name']])) {
      // This field might have been carried over from initial SQL
      if (isset($t['row'])) {
        unset($t['row']);  // to make bauplan happy
      }
      
      $filtered_list[] = $t;
      $seen[$t['name']] = 1;
    }
  }//each term
  
  return $filtered_list;
}//filterOntTerms


function getAnnotations($DBConn, $id, $gene_model_version, $username, $author_id, $super_curator, $id_type) {

  // Look for user-submitted, free text annotations
  if ($id_type == 'genemodel') {
    $sql = "
       SELECT DISTINCT a.auto_num, a.ann_author_id, a.memo, a.mod_date, a.gene_model_id,
           a.gene_model_version, aa.first_name, aa.last_name
      FROM annotation a
        INNER JOIN annotation_author aa ON aa.id=a.ann_author_id
      WHERE gene_model_id=" . $DBConn->quote($id) . " ";
  }
  else {
    $sql = "
      SELECT DISTINCT a.auto_num, a.memo, a.mod_date, a.id AS mgdb_id,
           aa.first_name, aa.last_name, aa.person_id
      FROM annotation a
        INNER JOIN annotation_author aa ON aa.id=a.ann_author_id
      WHERE a.id='$id'
            AND aa.id=a.ann_author_id ";
  }
  if ($super_curator) {
    // super curators can see all (visible) annotations
    $sql .= "AND a.curation_lvl<99";
  }
  else if ($author_id) {
    // annotators can see their own annotations
    $sql .= "AND (a.curation_lvl=0 ";
    $sql .= "OR (a.curation_lvl < 99 AND a.ann_author_id=" . (int) $author_id . "))";
  }
  else {
    // everyone else sees only approved annotations
    $sql .= "AND a.curation_lvl=0";
  }
  $stmt = make_query($DBConn, $sql);
  $arrAnnotations = get_all_rows($stmt);

  return $arrAnnotations;
}//getAnnotations


// DEPRECATED!
function getAnnotationHTML($DBConn, $id, $gene_model_version, $username, $author_id, $super_curator, $id_type) {
  reportError("getAnnotationHTML() is a deprecated function");

  $annotations = '';

  // Look for user-submitted, free text annotations
  if ($id_type == 'genemodel') {
    $sql = "
      SELECT A.*, AA.FIRST_NAME, AA.LAST_NAME
      FROM ANNOTATION A, ANNOTATION_AUTHOR AA
      WHERE GENE_MODEL_ID='$id'
            AND GENE_MODEL_VERSION='$gene_model_version'
            AND AA.ID=A.ANN_AUTHOR_ID ";
  }
  else {
    $sql = "
      SELECT A.*, AA.FIRST_NAME, AA.LAST_NAME
      FROM ANNOTATION A, ANNOTATION_AUTHOR AA
      WHERE A.ID='$id'
            AND AA.ID=A.ANN_AUTHOR_ID ";
  }
  if ($super_curator) {
    // super curators can see all (visible) annotation
    $sql .= "AND A.CURATION_LVL<99";
  }
  else if ($author_id) {
    // annotators can see their own annotations
    $sql .= "AND (A.CURATION_LVL=0 ";
    $sql .= "OR (A.CURATION_LVL < 99 AND A.ANN_AUTHOR_ID=" . (int) $author_id . "))";
  }
  else {
    // everyone else sees only approved annotations
    $sql .= "AND A.CURATION_LVL=0";
  }
  $stmt_user_annotations = make_query($DBConn, $sql);
  $arrAnnotations = get_all_rows($stmt_user_annotations);

  // Here's a case where HTML needs to be created in PHP (maybe)
  if ($arrAnnotations && count($arrAnnotations) > 0) {
    for ($i=0; $i<count($arrAnnotations); $i++) {
      $annotations .= "<b><a href=\"/data_center/annotator/"
                    . $arrAnnotations[$i]["id"] . "\">"
                    . trim($arrAnnotations[$i]["first_name"]) . " "
                    . trim($arrAnnotations[$i]["last_name"])
                    . "</a></b> (<i>"
                    . $arrAnnotations[$i]["mod_date"] . "</i>)<br>\n";
      $annotations .= "<span style=\"margin-left: 10px;\">"
                    . $arrAnnotations[$i]["memo"] . "</span>\n";

      $user_info = get_user_info($DBConn, $username);
      if ($super_curator
            || ($arrAnnotations[$i]["ann_author_id"] == $user_info['annotation_author_id'])) {
        $an = $arrAnnotations[$i]["auto_num"];
        $js = "document.getElementById('auto_num').value=$an;";
        $js .= "popUpAnnotation(curationform);";
        $js .= ";curationform.submit();";
        $annotations .= "<br><i>"
                      . "<a href=\"#\"
                            onclick=\"$js\">Edit this annotation</a></i>\n";
      }
      $annotations .= "<br>\n";
    }//each record
  }//found annotations

  return $annotations;
}//getAnnotationHTML


function getComments($DBConn, $id, $type_term=null, $dont_show=null) {
  $comments = '';

  $query = "
    SELECT m.memo, t.name AS type_term, r.id AS ref_id, r.name AS reference_authority,
           p.id AS per_id, p.name AS person_authority 
    FROM memo m
      LEFT OUTER JOIN term t ON t.id=m.type_term
      LEFT OUTER JOIN person p ON p.id = m.source
      LEFT OUTER JOIN reference r ON r.id = m.source
    WHERE m.id = " . (int) $id;
  if ($type_term != null) {
    $query .= " AND t.name = '$type_term'";
  }
  $stmt = make_query($DBConn, $query);
  while ($arrComments = retrieve_row($stmt)) {
    $comment = '';
    if (isset($arrComments['type_term']) && $arrComments['type_term'] != '') {
      if ($dont_show && in_array($arrComments['type_term'], $dont_show)) {
        continue;
      }
      if (!$type_term && isset($arrComments['type_term']) 
           && $arrComments['type_term'] != '' 
           && $arrComments['type_term'] != 'Not specified') {
        $comment .= '<b>' . $arrComments['type_term'] . '</b>: ';
      }
    }
    $comment .=  mgdb_safe_html($arrComments['memo']);
    if (isset($arrComments['ref_id']) && isset($arrComments['reference_authority'])
          && $arrComments['reference_authority'] != '') {
      $comment .= ' (per <a href="/data_center/reference/' . $arrComments['ref_id'] . '">'
                . $arrComments['reference_authority'] . '</a>)';
    }
    else if (isset($arrComments['per_id']) && isset($arrComments['person_authority'])
          && $arrComments['person_authority'] != '') {
      $comment .= ' (per <a href="/person/' . $arrComments['per_id'] . '">'
                . $arrComments['person_authority'] . '</a>)';
    }
    $comments .= "$comment<br>\n";
  }//each memo record

  // 2nd set of comments 
  //   (same memo may be attached to multiple data objects via id_memo)
  $query = "
    SELECT memo, t.name AS type_term, m.order1, r.name AS reference_authority,
           p.name AS person_authority 
    FROM memo m 
      INNER JOIN id_memo im ON m.id = im.memo_id
      LEFT OUTER JOIN term t ON t.id=m.type_term
      LEFT OUTER JOIN person p ON p.id = m.source
      LEFT OUTER JOIN reference r ON r.id = m.source
    WHERE im.id = " . (int) $id;
  if ($type_term != null) {
    $query .= " AND t.name = '$type_term'";
  }
  $query .= " ORDER BY m.order1";
  $stmt = make_query($DBConn, $query);
  while ($arrComments = retrieve_row($stmt)) {
    $comment = '';
    if (!$type_term 
          && isset($arrComments['type_term']) && $arrComments['type_term'] != '') {
      $comment .= '<b>' . $arrComments['type_term'] . '</b>: ';
    }
    $comment .=  mgdb_safe_html($arrComments['memo']);
    if (isset($arrComments['ref_id']) && isset($arrComments['reference_authority'])
          && $arrComments['reference_authority'] != '') {
      $comment .= ' (per <a href=/"data_center/reference/' . $arrComments['ref_id'] . '">'
                . $arrComments['reference_authority'] . '</a>)';
    }
    else if (isset($arrComments['per_id']) && isset($arrComments['person_authority'])
          && $arrComments['person_authority'] != '') {
      $comment .= ' (per <a href=/"data_center/reference/' . $arrComments['per_id'] . '">'
                . $arrComments['person_authority'] . '</a>)';
    }
    $comments .= "$comment<br>\n";
  }//each memo record

  return $comments;
}//getComments


function getCommentswRefs($DBConn, $id) {
    $sql = "
      SELECT DISTINCT m.memo AS fs_memo, r.id AS ref_id, r.name AS ref_name, 
             t.name AS stmt_type
      FROM memo m 
        INNER JOIN reference r ON r.id=m.source 
        INNER JOIN term t ON t.id=m.type_term 
      WHERE m.id=" . (int) $id;
    $sth = make_query($DBConn, $sql);
    
    return get_all_rows($sth);
}//getCommentswRefs


function getOBOTerms($DBConn, $username, $super_curator, $id, $table_name, $gene_model_id=false, $gene_model_version=false) {
  if ($id && $table_name == '') {
    reportError("Must indicate table name when using an id for an ID_ONTOLOGY query");
    return false;
  }
  if (($gene_model_id || $gene_model_version) 
    && (!$gene_model_id || !$gene_model_version)) {
    reportError("Must indicate both gene model name and gene model version for an ID_ONTOLOGY query");
    return false;
  }
  
  // Look for associated ontology terms
  $select = "
    SELECT DISTINCT o.gene_model_id, o.name, o.qualifier, o.with_from, o.obo_term,
           dt.name AS ontology_domain, p.name AS source, o.evidence_code, o.pmid, 
           o.reference AS reference_id, r.name AS reference_name, o.comments";           
  if ($super_curator) {
    // Super curators get to see some extra stuff
    $select .= ", auto_num, c.name AS curator, validation_lvl";
  }
  
  if ($id) {
    $where = "WHERE o.id='$id' AND o.table_name='$table_name' ";
  }  
  elseif ($gene_model_id) {
    $where = "WHERE o.gene_model_id = '$gene_model_id' AND o.gene_model_version='$gene_model_version' ";
  }
  else {
    // Shouldn't be possible to get here...
    reportError("No data object or gene model specified in ID_ONTOLOGY query");
    return false;
  }
  
  $where .= "AND o.validation_lvl = 0";

  $sql = "
    $select
    FROM perm_tables.id_ontology o
      INNER JOIN mgdb.person c ON c.id=o.source
      LEFT OUTER JOIN mgdb.term dt ON dt.id=o.ontology_domain
      LEFT OUTER JOIN mgdb.annotation_author v ON v.id=o.validation
      LEFT OUTER JOIN mgdb.reference r ON r.id=o.reference
      LEFT OUTER JOIN mgdb.person p ON p.id=o.source
    $where
    ORDER BY obo_term";
    
  $sth = make_query($DBConn, $sql);
  $arrOntTerms = get_all_rows($sth);
//logVarDump($arrOntTerms, "Found these terms:\n");

  // Remove duplicates and parent terms
  $arrOntTerms = filterOntTerms($arrOntTerms, $DBConn);
//logVarDump($arrOntTerms, "Filtered:\n");
  
  // Look for obo terms in the ext_b_key table too. This needs to be temporary!
  //   Must be fixed when curation tools 2.0 are online -eksc (10/14/20)
  if ($id && $table_name) {
    $sql = "
      SELECT DISTINCT k.key AS obo_term, e.name AS evidence_code, r.id AS reference_id, 
             r.name AS reference_name
      FROM ext_db_key k
        INNER JOIN person p ON p.id=k.db_person
        LEFT OUTER JOIN reference r ON r.id=k.reference
        LEFT OUTER JOIN term e ON e.id=k.evidence
      WHERE k.id = $id
        AND db_person IN (
              SELECT id FROM person
              WHERE name IN ('Crop Ontology', 'Phenotype qualities (properties) PATO'
                             'Plant Ontology Consortium', 'Trait Ontology', 
                             'GRIN Descriptors')
            )
      ORDER BY k.key";
    $sth = make_query($DBConn, $sql);
    $rows = get_all_rows($sth);
    $arrOntTerms = array_merge($arrOntTerms, $rows);
  }
  
  $arrOntTermsType = array();
  foreach ($arrOntTerms as $a) {
    array_push($arrOntTermsType, 
               array_merge($a, array('type' => getOntoTermType($a['obo_term'])))
    );
  }

  return $arrOntTermsType;
}//getOBOTerms


function getOntoTermType($term) {
  if (strstr($term, 'PO')) {
    return 'Plant Ontology';
  }
  else if (strstr($term, 'TO')) {
    return 'Plant Trait Ontology';
  }
  else if (strstr($term, 'CO_')) {
    return 'Crop Ontology';
  }
  else if (strstr($term, 'GO')) {
    return 'Gene Ontology';
  }
  else if (preg_match("/^\d+$/", $term)) {
    return 'GRIN descriptor';
  }
  
  return '';
}//getOntoTermType


function getPersonName($id, $DBConn) {
  $sql = "SELECT name FROM mgdb.person WHERE id='$id'";
  $sth = make_query($DBConn, $sql);
  if ($row=retrieve_row($sth)) {
    return $row['name'];
  }
  
  return false;
}//getPersonName


function getSynonyms($DBConn, $id) {
  $synonyms = array();
  
  $sql = "
    SELECT synonyms, p.id AS person_authority_id, p.name AS person_authority, 
           r.id AS reference_authority_id, r.name AS reference_authority
    FROM mgdb.synonyms s
      LEFT OUTER JOIN mgdb.person p ON p.id=s.authority
      LEFT OUTER JOIN mgdb.reference r ON r.id=s.authority
    WHERE s.id=$id
    ORDER BY CASE 
        WHEN s.authority=(SELECT id FROM mgdb.person WHERE name='Canonical Name') THEN ''
        ELSE s.synonyms
      END";
  $sth =  make_query($DBConn, $sql);
  while ($row = retrieve_row($sth)) {
    $synonym = $row['synonyms'];
    if ($row['person_authority'] != '') {
      $authority = '(per <a href="/person/' . $row['person_authority_id'] . '">' 
                 . $row['person_authority'] . '</a>)';
    }
    else if ($row['reference_authority']) {
      $authority = ' (per <a href="/data_center/reference/' . $row['reference_authority_id'] . '">' 
                 . $row['reference_authority'] . '</a>)';
    }
    else {
      $authority = '';
    }
    array_push($synonyms, ['synonym' => $synonym, 'authority' => $authority]);
  }
  
  return $synonyms;
}//getSynonyms


function getValidationLvlText($lvl) {
  switch ($lvl) {
    case 0:  return 'approved';
    case 1:  return 'auto with data update';
    case 2:  return 'may be an error';
    case 10: return 'submitted by community curator';
    case 99: return 'deleted';
  }
}//getValidationLvlText


/*
 * Return all ASMBLY issues for a given locus name.
 * This function returns data in a form suitable for passing directly to the
 * Bauplan template templates/partials/issues.bau
 *
 * See: https://github.com/chobie/jira-api-restclient
 *
 * OBSOLETE - DON'T USE
function getIssuesForLocus($url, $user, $password, $gene_model_id=false, $locus_name=false, $withComments=true) {
  include_once($_SERVER['DOCUMENT_ROOT'] . "/lib/jira-api/JiraAPI.php"); // for querying issues

  if (!$gene_model_id && !$locus_name) {
    // nothing to search for
    reportError("No locus or gene model passed to getIssuesForLocus()");
    return false;
  }
  
  $api = new chobie\Jira\Api(
    $url,
    new chobie\Jira\Api\Authentication\Basic($user, $password)
  );
  if (!$api) {
    reportError("Unable to connect to Jira");
    return false;
  }
  
  $walker = new chobie\Jira\Issues\Walker($api);
  $locus_clause = (!$locus_name)
                ? ''
                : 
         '(Accession1 ~ "' . $locus_name 
            . '" OR "Component/s" ~ "' . $locus_name 
            . '" OR Accession2 ~ "' . $locus_name 
            . '")';
  $gm_clause = (!$gene_model_id)
             ? ''
             : 
         '(Accession1 ~ "' . $gene_model_id 
            . '" OR "Component/s" ~ "' . $gene_model_id 
            . '" OR Accession2 ~ "' . $gene_model_id 
            . '")';
  if ($locus_clause && $gm_clause) {
    $and_clause = "($locus_clause OR $gm_clause)";
  }
  else {
    $and_clause = ($locus_clause)
                  ? $locus_clause
                  : $gm_clause;
  }
  $jql = 'project = ASMBLY '
        . 'AND Labels NOT IN (internal) '
        . 'AND (Resolution != Duplicate OR Resolution IS EMPTY)'
        . "AND $and_clause";
  $walker->push($jql);
  $issue_data = array();
  foreach ($walker as $issue) {
    $id = $issue->getId();
  
    // Because 'Affects Version/s' is in a dropdown, its data format is different
    $affects_versions = $issue->get('Affects Version/s');

    if ($withComments) {
      $comments = "
        <li>
          <b>Comments:</b>
          <div style='margin-left: 20px;'>
            <ul>";
      $issueComments = $api->api('GET', "/rest/api/2/issue/$id/comment")->getResult();
      foreach ($issueComments['comments'] as $comment) {
        $comments .= "
              <li>" . $comment['body'] . "</li>";
      }
      $comments .= "
            </ul>
          </div>
        </li>";
    }

    $status_arr = $issue->getStatus();
    $status = ($status_arr['name'] == 'Closed' || $status_arr['name'] == 'Resolved') 
            ? '<b style="color:#000090">'.$status_arr['name'].'</b>' 
            : '<b style="color:red">'.$status_arr['name'].'</b>';
            
    $created = new DateTime($issue->get('Created'));
    $updated = new DateTime($issue->get('Updated'));
    $date_format = 'M d, Y';
    
    $this_issue = array(
      'issue_text'   => $issue->get('display_text'),
      'issue_status' => $status,
      'issue_version' => $affects_versions['value'],
      'issue_resolution' => $issue->get('resolution_text'),
      'issue_stall' => $issue->get('StallStatement'),
      'issue_reopen' => $issue->get('Reopen Statement'),
      'issue_created' => $created->format($date_format),
      'issue_updated' => $updated->format($date_format),
    );
    
    if ($comments > 0) {
      $this_issue['issue_comments'] = $comments;
    }
    
    array_push($issue_data, $this_issue);
  }

  return (count($issue_data) > 0) ? $issue_data : false;
} // getIssuesForLocus
*/

function makeEvidenceField($evidence) {
  // see: http://geneontology.org/page/guide-go-evidence-codes
  
  switch ($evidence) {
    case 'COMP':
    case 'EV-COMP':
      //BioCyc evidence code
      $description = 'Inferred by computational analysis';
      $explanation = 'Inferred from computation. The evidence for an assertion comes 
                      from a computational analysis. The assertion itself might have been 
                      made by a person or by a computer, that is, EV-COMP does not specify 
                      whether manual interpretation of the computation occurred.';
      break;
    case 'EV-COMP-AINF':
    case 'AINF-SEQ-ORTHOLOGY':
      //BioCyc evidence code
      $description = 'Automated inference of function by sequence orthlogy';
      $explanation = 'A computer inferred function based on the function of an
                      orthologous protein in another organism.';
      break;
    case 'EV-COMP-HINF-ISM':
    case 'HINF-SEQ-ORTHOLOGY':
      //BioCyc evidence code
      $description = 'Human inference of function by sequence orthology';
      $explanation = "A person inferred or reviewed a computer inference of function
                      based on the function of an orthlogous protein in another
                      organism";
      break;
    case 'IC': 
      $description = 'Inferred By Curator';
      $explanation = 'Used when an annotation is not supported by any evidence, 
                      but can be reasonably inferred by a curator from other GO 
                      annotations for which evidence is available.';
      break;
    case 'IDA':
      $description = "Inferred from Direct Assay";
      $explanation = "Examples: Enzyme assays, In vitro reconstitution, 
                      Immunofluorescence, Cell fractionation, 
                      Physical interaction/binding assay";
      break;
    case 'IEA':
      $description = "Inferred from Electronic Annotation";
      $explanation = "Annotations based on \"hits\" in sequence similarity searches, 
                      if not curator-reviewed. Annotations transferred from database 
                      records, if not curator-reviewed.";
      break;
    case 'IEP':
      $description = "Inferred from Expression Pattern";
      $explanation = "Examples: Transcript levels [e.g. Northerns, microarray data],
                      Protein levels [e.g. Western blots]";
      break;
    case 'IGC':
      $description = "Inferred from Genomic Context";
      $explanation = "Examples: operon structure, syntenic regions, pathway analysis, 
                      genome scale analysis of processes";
      break;
    case 'IGI':
      $description = "Inferred from Genetic Interaction";
      $explanation = "Examples: Traditional genetic interactions such as suppressors, 
                      synthetic lethals, etc., Functional complementation, 
                      Rescue experiments";
      break;
    case 'IMP':
      $description = "Inferred from Mutant Phenotype";
      $explanation = "Examples: Any gene mutation/knockout, Overexpression/ectopic 
                      expression of wild-type or mutant genes, Anti-sense experiments,
                      Specific protein inhibitors, IPI	Inferred from Physical Interaction	,
                      2-hybrid interactions, Co-purification, Co-immunoprecipitation,
                      Ion/protein binding experiments";
      break;
    case 'IPI':
      $description = "Inferred from Physical Interaction";
      $explanation = "Inferred from Physical Interaction";
      break;
    case 'ISA':
      $description = "Inferred from Sequence Alignment";
      $explanation = "Used when the primary piece of evidence is a pairwise or multiple 
                      sequence alignment";
      break;
    case 'ISM':
      $description = "Inferred from Sequence Model";
      $explanation = "Used when any kind of sequence modeling method (e.g. Hidden Markov 
                      Models) is the primary piece of evidence";
      break;
    case 'ISO':
      $description = "Inferred from Sequence Orthology";
      $explanation = "Used when the assertion of orthology between the gene product and 
                      an experimentally characterized gene product in another organism is 
                      the main basis of the annotation";
      break;
    case 'ISS':
      $description = "Inferred from Sequence or structural Similarity";
      $explanation = "Examples: Sequence similarity [homolog of/most closely related to],
                      Recognized domains, Structural similarity, Southern blotting";
      break;
    case 'NAS':
      $description = "Non-traceable Author Statement";
      $explanation = "Database entries that do not cite a paper [e.g. SwissProt records, 
                      YPD protein reports]; Statements in papers [abstract, introduction, 
                      or discussion] that a curator cannot trace to another publication";
      break;
    case 'ND';
      $description = "No Biological Data Available";
      $explanation = "Used for annotations to unknown molecular function, biological 
                      process, or cellular component.";
      break;
    case 'RCA';
      $description = "Reviewed Computational Analysis";
      $explanation = "Used for predictions based on large-scale experiments, integration 
                      of large-scale datasets, text-based computation";
      break;
    case 'TAS':
      $description = "Traceable Author Statement";
      $explanation = "Anything in a review article where the original experiments are 
                      traceable through that article, or in a textbook or dictionary 
                      [e.g. \"everybody\" knows that enolase is a glycolytic enzyme]";
      break;
    default:
      $description = '';
  }//switch

  $html = "<span class=\"tooltip\">$evidence<span class=\"tooltiptext tooltip-right\">$description</span></span>";
  
  return $html;
}//makeEvidenceField


function makeOntoTable($tmpl, $arrOntTerms) {
  // These columns always exist
  $cols  = array('gene_model_id', 'obo_term', 'name');  
  $heads = array('feature', 'term', 'name');
  
  // These may not have values (don't show the column)
  $check_col = array_unique(array_column($arrOntTerms, 'ontology_domain'));
  if (count($check_col) > 1 || $check_col[0] != '') {
    $cols[] = 'ontology_domain';
    $heads[] = 'domain';
  }
  $check_col = array_unique(array_column($arrOntTerms, 'qualifier'));
  if (count($check_col) > 1 || $check_col[0] != '') {
    $cols[] = 'qualifier';
    $heads[] = 'qualifier';
  }
  $check_col = array_unique(array_column($arrOntTerms, 'with_from'));
  if (count($check_col) > 1 || $check_col[0] != '') {
    $cols[] = 'with_from';
    $heads[] = 'with/from';
  }
  $check_col = array_unique(array_column($arrOntTerms, 'evidence_code'));
  if (count($check_col) > 1 || $check_col[0] != '') {
    $cols[] = 'evidence_code';
    $heads[] = 'evidence';
  }
  $check_col = array_unique(array_column($arrOntTerms, 'source'));
  if (count($check_col) > 1 || $check_col[0] != '') {
    $cols[] = 'source';
    $heads[] = 'source';
  }
  $check_col = array_unique(array_column($arrOntTerms, 'publication'));
  if (count($check_col) > 1 || $check_col[0] != '') {
    $cols[] = 'publication';
    $heads[] = 'publication';
  }
  $check_col = array_unique(array_column($arrOntTerms, 'comments'));
  if (count($check_col) > 1 || $check_col[0] != '') {
    $cols[] = 'comments';
    $heads[] = 'comment';
  }

  // Bauplan is not flexible enough to handle this situation, so bulid the
  //   HTML here (ick)
  $html = "
    <div style=\"border: 1px solid black\">
    <table>
      <tr>";
  foreach ($heads as $head) {
    $html .= "
        <th align=\"left\">$head&nbsp;</th>";
  }
  $html .= "
      </tr>";
  foreach ($arrOntTerms as $rec) {
    $html .= "
      <tr>";
    foreach ($cols as $col) {
      $html .= "
        <td valign=\"top\">" . $rec[$col] . "&nbsp;</td>";
    }
    $html .= "
      </tr>";
  }
  $html .= "
    </table>
    </div><br>";

  $tmpl->get('ontology_terms')->replace($html);
  $tmpl->get('onto-terms')->unmute();
}//makeOntoTable


function makePublicationLinks($pmid, $doi, $reference_id, $DBConn) {
  $html = '';
  
  // Preferentially use DOI over PMID
  if ($doi && $doi != '') {
    $url = getdbURL('DOI', $DBConn) . $doi;
    $html .= "<a href=\"$url\">Publication<a> ";
  }
  else if ($pmid && $pmid != '') {
    $url = getdbURL('PMID', $DBConn) . $pmid;
    $html .= "<a href=\"$url\">Publication<a> ";
  }
  
  if ($reference_id && $reference_id != '') {
    $url = "/reference/$reference_id";
    $html .= "<a href=\"$url\">MaizeGDB Reference</a>";
  }
  
  if ($html != '') {
    return "[$html]";
  }
  
  return '';
}


function makeQualityField($quality) {
  switch ($quality) {
    case 'similar':
      $description = "gene model sequences have some sequence similarity.";
      break;
    case 'match':
      $description = "gene model sequences have high sequence similarity.";
      break;
    case 'syntelog':
      $description = "gene models have high sequence similarity and appear in similar (syntenic) genomic context.";
      break;
    default:
      $description = '';
  }
      
  $html = "<span class=\"tooltip\">$quality<span class=\"tooltiptext tooltip-right\">$description</span></span>";
  
  return $html;
}//makeQualityField


function makeWithFromField($with_from, $DBConn) {
  if (!$with_from) { return ''; }
  
  $test_str = $with_from;
  if (preg_match("/UniProtKB:(.*)/", $test_str, $matches)) {
    $url = getdbURL('UniProtKB', $DBConn);
    $acc = $matches[1];
    return "<a href='$url$acc'>" . $matches[1] . "</a>";
  }
  else if (isGeneModel($with_from, $DBConn)) {
    return "<a href='/gene_center/gene/$with_from>$with_from</a>";
  }
  else if ($locus_id=getLocusId($with_from, $DBConn)) {
    return "<a href=/gene_center/gene/$with_from>$with_from</a>";
  }
  else {
    // Don't know what this is, so just return the text
    return $with_from;
  }
  
  return '';
}//makeWithFromField


function prepareOBOtable($arrOntTerms, $DBConn) {
  for ($i=0; $i<count($arrOntTerms); $i++) {
    // GRIN descriptors are a special case
    if (preg_match("/^\d+$/", $arrOntTerms[$i]['obo_term'])) {
      $arrOntTerms[$i]['purl'] = 'https://npgsweb.ars-grin.gov/gringlobal/descriptordetail?id='
                               . $arrOntTerms[$i]['obo_term'];
    }
    // Crop ontology terms are another special case
    else if (preg_match("/^CO_/", $arrOntTerms[$i]['obo_term'])) {
      // Doesn't appear to be a direct link to CO term records
      $arrOntTerms[$i]['purl'] = 'https://www.cropontology.org/ontology/CO_322/Maize';
    }
    else {
      $arrOntTerms[$i]['purl'] = 'http://purl.obolibrary.org/obo/'
                               . str_replace(":", "_", $arrOntTerms[$i]['obo_term']);
    }
    
    if (isset($arrOntTerms[$i]['reference_id'])) {
      $arrOntTerms[$i]['publication'] = "/data_center/reference?id="
                                      . $arrOntTerms[$i]['reference_id'];
    }
    else if (isset($arrOntTerms[$i]['pmid'])) {
      $arrOntTerms[$i]['publication']   = "https://www.ncbi.nlm.nih.gov/pubmed/"
                                        . $arrOntTerms[$i]['pmid'];
      $arrOntTerms[$i]['reference_name'] = 'PMID:' . $arrOntTerms[$i]['pmid'];
    }
    else if (isset($arrOntTerms[$i]['authority'])) {
      $arrOntTerms[$i]['publication'] = "/person?id="
                                      . $arrOntTerms[$i]['authority'];
      $arrOntTerms[$i]['reference_name'] = getPersonName($arrOntTerms[$i]['authority'], $DBConn);
    }
    else {
      $arrOntTerms[$i]['publication'] = '';
    }
    if (isset($arrOntTerms[$i]['evidence_code'])) {
      $arrOntTerms[$i]['evidence_code'] = makeEvidenceField($arrOntTerms[$i]['evidence_code']);
    }
    else {
      $arrOntTerms[$i]['evidence_code'] = '';
    }
    if (isset($arrOntTerms[$i]['with_from'])) {
      $arrOntTerms[$i]['with_from'] = makeWithFromField($arrOntTerms[$i]['with_from'], $DBConn);
    }
    else {
      $arrOntTerms[$i]['with_from'] = '';
    }

    // to keep bauplan happy
    unset($arrOntTerms[$i]['reference_id']);
    unset($arrOntTerms[$i]['pmid']);
    unset($arrOntTerms[$i]['authority']);
    unset($arrOntTerms[$i]['id']);
    unset($arrOntTerms[$i]['row']);  // added by DISTINCT clause
  }
  
  return $arrOntTerms;
}//prepareOBOtable


// $rec_id+$table or $gene_model_id+$gene_model_version required, but not both
function showOntoTerms($tmpl, $username, $super_curator, $rec_id, $table, $gene_model_id, $gene_model_version, $DBConn) {
  global $super_curator, $username, $author_id;
  
  // Look for associated ontology terms
  $arrOntTerms 
    = getOBOTerms($DBConn, $username, $super_curator, $rec_id, $table, $gene_model_id, $gene_model_version);

  // Add PURLs, references, and mouseovers
  if ($arrOntTerms) {
    $arrOntTerms = prepareOBOtable($arrOntTerms, $DBConn);
  }
  
  // Display ontology terms
  if (!$arrOntTerms || count($arrOntTerms) == 0) {
    $tmpl->get('no-ont-terms')->unmute();
  }
  else {
    makeOntoTable($tmpl, $arrOntTerms);
  }
}//showOntoTerms


?>
