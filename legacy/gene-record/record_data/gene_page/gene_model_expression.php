<?php
/* file: gene_model_expression.php
 *
 * purpose: display expression information for a gene model. Included by gene_data.php.
 *
 * history:
 *   06/16/20  eksc created from gene_data.php
 */

function showExpression($tmpl, $id, $arrRecord, $DBConn) {
  global $system;

  $tmpl->get('gm_id')->replace($id);

  $expression_data_exist = false;

  // This <div> should be hidden by default in all v3 pages
  $tmpl->get('rna_init_display')->replace("none"); 
    
  // Display qTeller text if gene model is supported
  if (has_qTellerData($id, $arrRecord['assembly_version'])
     && $qTellerURL = get_qTellerURL($arrRecord['assembly_version'])) {
    $tmpl->get('qteller_url')->replace($qTellerURL . $id);
    $tmpl->get('qteller')->unmute();
    $expression_data_exist = true;
  }
  
  // Check for expression data attached to a member of the pan-gene set
  include_once('../include/pan_gene_lib.php');
  $pan_gene_data = queryPanB73Genes($arrRecord['feature_id'], $DBConn);
  
  if ($pan_gene_data && count($pan_gene_data) > 0) {
    $expression_genes = array();
    $processed_expression_genes = array();   // To avoid duplicates
    foreach ($pan_gene_data as $r) {
      $gm = $r['pan_gene_member'];
      $hash = $gm . $r['pan_gene_member_assembly'];
      if ($gm != $arrRecord['gene_id']) {
        if (hasExpressionData($gm, $r['pan_gene_member_assembly'])) {
         if (!isset($processed_expression_genes[$hash])) {
            $processed_expression_genes[$hash] = true;
            $expression_genes[] = array('exp_gene_model' => $gm);
          }
        }
      }//not subject gene model
    }//each gm
    if (count($expression_genes) > 0) {
      $expression_data_exist = true;
      $tmpl->get('exp_gene_model_list')->loop($expression_genes);
      $tmpl->get('pan-gene-expression')->unmute();
    }
  }//Found B73 gene models
  
  if (isset($arrRecord['feature_id'])) {
    processEFPdata($tmpl,  $arrRecord['assembly_version'], $id);
  }
  
  if (!$expression_data_exist) {
    $tmpl->get('no-expression')->unmute();
    return;
  }

  $tmpl->get('gm_name')->replace($id);
  $tmpl->get('expression')->unmute();
}//showExpression


//////////////////////////////////////////////////////////////////////////////////////////
// helper functions
//////////////////////////////////////////////////////////////////////////////////////////

/* show_eFP() doesn't seem to be used anymore. 
   Replaced by showGeneModelExpression defined in gene_center_lib.php
 */
function show_eFP($tmpl, $browser_name, $assembly_version, $id) {
  $eFP_info = getEFPInformation($id);
  
  $key = "$assembly_version $browser_name";
  if (!isset($eFP_info[$key])) {
    return false;
  }
  else {
    $info = $eFP_info[$key];
   //jp - temporary disable the serverside eFP check because of an SSL issue with the eFP browser server 
   // $ret = readURL($info['img_link']);    
    $efp_error = false; //(strstr($ret, "Unable to load content")) ? true : false;
    if (!$efp_error) {
      $ret = readURL($info['link']);
      $efp_not_found = (strstr($ret, "cannot be found")) ? true : false;
    }
    
    if ($efp_error) {
      $tmpl->get($info['template_base'] . '-error')->unmute();
    }
    else if ($efp_not_found) {
      // Not on browser
      $tmpl->get('gm_id')->replace($id);
      $tmpl->get($info['template_base'] . '-no-browser')->unmute();
    }
    else {
      $tmpl->get($info['template_base'] . '-img_src')->replace($info['img_link']);
      $tmpl->get($info['template_base'] . '-link')->replace($info['link']);
      $tmpl->get($info['template_base'] . '-browser')->unmute();
    } 
  }
  
  return true;
}


