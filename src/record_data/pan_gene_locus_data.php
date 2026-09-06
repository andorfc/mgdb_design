<?php
/* file: pan_gene_locus_data.php
 *
 * purpose: display the various sections of a pan-gene record.
 *          Replaces gene_data, gene_locus_data, gene_pangenome_data, and supporting code.
 *
 *          Called via Ajax. Ajax calls go through getData() in api_js.js.
 *          Javascript calls set in function set_checkBoxes() in gene.bau.
 *
 * history:
 *   02/20/23  eksc  created
 */

  include_once('../lib/Bauplan.php');
  include_once('../include/db-api.php');
  include_once('../include/api_tools.php');
  include_once('../include/gp_lib.php');
  include_once('../include/annotation_lib.php');
  include_once('../include/gene_center_lib.php');
  include_once('../tools/issuetracking/assembly_issues.php');

  include_once('gene_data_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $username = getCookie('username', false);
  $password = getCookie('password', false);
  $userid   = getCookie('userid',   false);

  // NOTE: $id will always be the official identifier
  $locus   = getCGIParam("locus", 'P', false);
  $section = getCGIParam("section", 'P', false);
  $suspect = getCGIParam("suspect", 'P', 'false');
  $pan_gene_name = getCGIParam("pan_gene_name", 'P', false);
//logVarDump($_POST, "POST vars:\n");
logMessage("Build pan-gene locus page for $locus");

  if (!$section) {
    reportError("No section name given to pan_gene_data.php.");
    exit;
  }

  $bauplan = new Bauplan('');

  $DBConn = connect_to_database();

  // If annotator, check for super curator: NO REAL PURPOSE FOR THIS, BUT LEAVE IN.
  if ($username) {
    $user_info = get_user_info($DBConn, $username);
    $super_curator = ($user_info['curation_lvl'] <= -5);
    $author_id = $user_info['annotation_author_id'];
  }

  // Clean up input, in case typed incorrectly by user
  $locus = validate_input($DBConn, $locus);

  // Just show the overview. No expandable sections
  $locus_data = getOverviewLocusData($locus, $pan_gene_name, $DBConn);
  $tmpl = $bauplan->template()->load('../templates/pan_gene_center/pan_gene_locus_record-overview.bau');
  showOverview($tmpl, $locus_data, $suspect, $DBConn);

  $bauplan->publish();


/////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////

function getOverviewLocusData($locus, $pan_gene_name, $DBConn) {
  $sql = "
    SELECT DISTINCT l.id, l.name AS symbol, l.full_name, t.name AS type, 
           lg.name AS linkage_group, sp.species AS species,
           ARRAY_AGG(DISTINCT CONCAT(rl.id, ':', rl.name, ':', rt.name)) AS related_loci, 
           ARRAY_AGG(DISTINCT CONCAT(ph.id, ':', ph.name)) AS phenotypes, 
           ARRAY_AGG(DISTINCT CONCAT(gp.id, ':', gp.name, ':', gpt.name)) AS gene_products, 
           ARRAY_AGG(DISTINCT CONCAT(v.id, ':', v.name)) AS variations, 
           ARRAY_AGG(DISTINCT CONCAT(m.source, ':', mt.name, ':', m.memo)) AS memo, 
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT s.synonyms), ',') AS synonyms
    FROM mgdb.locus l
      INNER JOIN mgdb.term t ON t.id=l.type
      INNER JOIN mgdb.linkage_group lg ON lg.id=l.linkage_group
      LEFT OUTER JOIN mgdb.species sp ON sp.id=l.species
      LEFT OUTER JOIN mgdb.relation r ON r.id=l.id
      LEFT OUTER JOIN mgdb.term rt ON rt.id=r.relation
      LEFT OUTER JOIN mgdb.locus rl ON rl.id=r.related_id
      LEFT OUTER JOIN mgdb.locus_gene_products lgp ON lgp.id=l.id
      LEFT OUTER JOIN mgdb.gene_product gp ON gp.id=lgp.gene_product
      LEFT OUTER JOIN mgdb.term gpt ON gpt.id=gp.type
      LEFT OUTER JOIN mgdb.locus_function lf ON lf.id=l.id
      LEFT OUTER JOIN mgdb.phenotype ph ON ph.id=lf.phenotype
      LEFT OUTER JOIN mgdb.variation v ON v.variationof=l.id
      LEFT OUTER JOIN mgdb.memo m ON m.id=l.id
      LEFT OUTER JOIN mgdb.term mt ON mt.id=m.type_term
      LEFT OUTER JOIN mgdb.synonyms s ON s.id=l.id
    WHERE l.name=" . $DBConn->quote($locus) . "
    GROUP BY l.id, l.full_name, t.name, lg.name, sp.species";
    $sth = make_query($DBConn, $sql);
    $data = retrieve_row($sth);
//logVarDump($data, "Locus data record:\n");
    
    if ($data) {
      // Look for support for linking this locus to the pan-gene
      $support = array();
      $sql = "
        SELECT DISTINCT gene_model_name, ext_db_comment, source, reference, pan_gene_name
        FROM chado.pan_gene_locus_assoc
        WHERE locus_name = '" . $data['symbol'] . "'";
      $sth = make_query($DBConn, $sql);
      while ($row = retrieve_row($sth)) {
        $s = '';
        $gm_link = '<a href="/gene_center/gene/' . $row['gene_model_name'] . '">';
        $gm_link .= $row['gene_model_name'] . '</a>';
        if ($row['ext_db_comment'] && $row['ext_db_comment'] != '') {
          $s = $gm_link . ': ' . $row['ext_db_comment'];
        }
        else {
          $s = $gm_link . ': ' . $row['source'];
        }
        if ($row['reference'] && $row['reference'] != '') {
          $s .= ' (<a href="/data_center/reference/' . $row['reference_id'] . '">'
              .  $row['reference_name'] . '</a>)';
        }
        if ($row['pan_gene_name'] != $pan_gene_name) {
          $pan_link = '/pan_gene_center/pan_gene/' . $row['pan_gene_name'];
          $s .= ". <b>Warning: this gene model is in a different <a href=\"$pan_link\"><b>pan-gene</b></a></b>";
        }
        if ($s != '') {
          array_push($support, $s);
        }
      }//each row
      
      $data['support'] = (count($support) > 0) ? implode('<br>', $support) : '';
    }
    
    return $data;
}//getOverviewLocusData


function showFunction($tmpl, $locus_data, $DBConn) {
//   $tmpl->get('pan_gene_locus-function')->unmute();
}//showDetails


function showMap($tmpl, $locus_data, $DBConn) {
//   $tmpl->get('pan_gene_locus-map')->unmute();
}//showExpression


function showOverview($tmpl, $locus_data, $suspect, $DBConn) {
  $tmpl->get('locus-full_name')->replace($locus_data['full_name']);
  $tmpl->get('locus-symbol')->replace($locus_data['symbol']);
  $tmpl->get('locus-synonyms')->replace($locus_data['synonyms']);
  $tmpl->get('locus-linkage_group')->replace($locus_data['linkage_group']);
  if ($locus_data['support'] != '') {
    $tmpl->get('supporting_evidence')->replace($locus_data['support']);
    $tmpl->get('locus-gene_model-support')->unmute();
  }

  showUniProt($tmpl, $locus_data, $DBConn);
  showNCBIGenes($tmpl, $locus_data, $DBConn);
  showDescription($tmpl, $locus_data, $DBConn);
  showComments($tmpl, $locus_data, $DBConn);
  showIssues($tmpl, $locus_data, $DBConn);
  showGeneProducts($tmpl, $locus_data, $DBConn);
  showPhenotypes($tmpl, $locus_data, $DBConn);
  showRelatedLoci($tmpl, $locus_data, $DBConn);
  
  if ($suspect == 'true') {
    $tmpl->get('suspect-pan_gene')->unmute();
  }
  
  $tmpl->get('pan_gene_locus-overview')->unmute();
}//showOverview



//////////////////////////////////////////////////////////////////////////////////////////
//                                  SUPPORTING FUNCTIONS                                //
//////////////////////////////////////////////////////////////////////////////////////////

function showComments($tmpl, $locus_data, $DBConn) {
  // Get all comments EXCEPT 'Critical' and 'Brief Description'
  $comments = getComments($DBConn, $locus_data['id'], '', 
                          array('Critical', 'Brief Description'));
  if ($comments) {
    $tmpl->get('comment-list_gene')->replace($comments);
    $tmpl->get('comments_gene')->unmute();
  }
}//showComments


function showDescription($tmpl, $locus_data, $DBConn) {
  $no_description = true;
  
  // Description
  $description = getComments($DBConn, $locus_data['id'], 'Brief Description');
  if ($description != '') {
    $tmpl->get('brief-description')->replace($description);
    $tmpl->get('description-list')->unmute();
    $no_description = false;
  }

  // Functional statements from literature
  $stmts = getCommentswRefs($DBConn, $locus_data['id']);
  if (count($stmts) > 0) {
    $tmpl->get('functional-statements')->loop($stmts);
    $tmpl->get('functional-statements')->unmute();
    $no_description = false;
  }
  
  // Critical comments
  $description = getComments($DBConn, $locus_data['id'], 'Critical');
  if ($description != '') {
    $tmpl->get('critical')->replace($description);
    $tmpl->get('critical-statements')->unmute();
    $no_description = false;
  }
 
  if ($no_description) {
    $tmpl->get('no-locus-description')->unmute();
  }
  else {
    $tmpl->get('locus-description')->unmute();
  }
}//showComments


function showGeneProducts($tmpl, $locus_data, $DBConn) {
  if ($gene_products = getGeneProducts($DBConn, $locus_data['id'])) {
    $tmpl->get('gene-products-list')->loop($gene_products);
    $tmpl->get('gene-products-list')->unmute();
  }
  else {
    $tmpl->get('gene-products-none')->unmute();
  }
}//showGeneProducts


function showIssues($tmpl, $locus_data, $DBConn) {
logVarDump($locus_data, "show_issues(): locus data:\n");
  global $system;
  
/* Jira no longer functional
logMessage("Get jira issues.");
  $issues = getJiraIssues($locus_data['symbol']);
  if ($issues) {
    $tmpl->get('open-issue')->unmute();
    $tmpl->get('jira_issues')->loop($issues);
    $tmpl->get('jira_issues_section')->unmute();
  }
*/
  $locus_name = $locus_data['symbol'];
  $issues = getAssemblyIssues($locus_name, $DBConn);
logVarDump($issues, "Got these issues:\n");
  if (isset($issues) && $issues !== false && count($issues) > 0) {
    for ($i=0; $i<count($issues); $i++) {
      if (strstr($issues[$i]['issue_status'], 'open')) {
        $issues[$i]['flag'] = '<img src="/icon/red_flag_16.png">';
      }
      else {
        $issues[$i]['flag'] = '<img src="/icon/blue_flag_16.png">';
      }
      unset($issues[$i]['issue_status']);
      $issues[$i]['display_text'] = str_replace('&lt;br&gt;', '', $issues[$i]['display_text']);
    }//each issue
    
    $tmpl->get('issue-list')->loop($issues);
    $tmpl->get('assembly_issues_section')->unmute();
  }

}//showIssues


function showNCBIGenes($tmpl, $locus_data, $DBConn) {
  $id = $locus_data['id'];
  $sql = "
    SELECT x.key, x.ext_db_comment, p.id, p.name, pup.url_prefix
    FROM mgdb.ext_db_key x
      INNER JOIN mgdb.person p ON p.id=x.db_person
      INNER JOIN mgdb.person_url_prefix pup ON pup.id=p.id
      INNER JOIN mgdb.id_num i ON i.id=p.id
    WHERE x.id=$id AND p.name='NCBI Gene' AND i.curation_lvl=0";
logMessage("$sql");
  $sth = make_query($DBConn, $sql);
  $ncbi_genes = array();
  while ($row = retrieve_row($sth)) {
    array_push($ncbi_genes, array(
      'url_prefix'   => $row['url_prefix'],
      'key'          => $row['key'],
      'gene_name'    => ($row['ext_db_comment']) 
                         ? '('.$row['ext_db_comment'].')' : '',
    ));
  }
    
  if (count($ncbi_genes) > 0) {
    $tmpl->get('ncbi_genes')->loop($ncbi_genes);
    $tmpl->get('ncbi_genes_sec')->unmute();
  }
}//showNCBIGenes


function showPhenotypes($tmpl, $locus_data, $DBConn) {
  $id = $locus_data['id'];
  $sql = "
    SELECT * FROM (
      SELECT DISTINCT p.id, p.name 
      FROM mgdb.variation v 
        INNER JOIN mgdb.var_pheno_effects pe ON pe.id = v.id 
        INNER JOIN mgdb.phenotype p ON p.id = pe.pheno_effect 
        INNER JOIN mgdb.id_num vid ON vid.id = v.id 
        INNER JOIN mgdb.id_num pid ON pid.id = p.id 
      WHERE v.variationof = $id AND vid.curation_lvl = 0 
    ) s
    ORDER BY name";
  $sth = make_query($DBConn, $sql);
  $pheno_results = array();
  while ($row = retrieve_row($sth)) {
    $pheno_results[] = array(
      'pheno_name' => trim($row['name']),
      'pheno_id'   => $row['id']);
  }

  if (count($pheno_results) > 0){
    $tmpl->get('phenotype_list')->loop($pheno_results);
    $tmpl->get('phenotypes')->unmute();
  }
}//showPhenotypes


function showRelatedLoci($tmpl, $locus_data, $DBConn) {
  $id = $locus_data['id'];
  $sql = "
    SELECT l.id AS locus_id, l.name AS locus_name,
           l.full_name AS locus_full_name, t.name AS relation
    FROM mgdb.locus l
      INNER JOIN mgdb.id_num i ON i.id=l.id
      INNER JOIN mgdb.relation r ON r.related_id=i.id
      INNER JOIN mgdb.term t ON r.relation=t.id
    WHERE i.curation_lvl = 0 AND r.id = $id
    ORDER BY LOWER(l.name)";
  $sth = make_query($DBConn, $sql);
  $related_loci = get_all_rows($sth);
  if ($related_loci && count($related_loci) > 0) {
    $tmpl->get('related-loci-list')->loop($related_loci);
    $tmpl->get('related-loci')->unmute();
  }
}//show_related_loci


function showUniProt($tmpl, $locus_data, $DBConn) {
  if ($uniprot = getUniProtAccession($DBConn, $locus_data['id'])) {
    $tmpl->get('uniprot_accs')->loop($uniprot);
    $tmpl->get('uniprot')->unmute();
  }
}//showUniProt



?>