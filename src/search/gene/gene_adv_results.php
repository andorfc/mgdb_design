 <?php
  /* file: gene_adv_results.php
 * 
 * purpose: search for gene/locus that match search parameters
 *
 * history:
 *   05/19/14  eksc created from locus_adv_results.php
 */
 
  include_once('../../lib/Bauplan.php');
  include_once("../../include/db-api.php");
  include_once("../../include/gp_lib.php");
  include_once("../../include/data_center_functions.php");
  include_once('./gene_search_functions.php');
  
  $system = getSystemInfo('mgdb.conf');
  
  $assembly_version = $system['cur_ref_gen'];

  // Create a bauplan object
  $bauplan = new Bauplan('Results page');
  $template_file = '../../templates/gene_center/gene_adv_results.bau';
  $template = $bauplan->template()->load($template_file);
  
  $DBConn = connect_to_database();
  
  // This many records per page
  $pagesize = $system['pagesize'];
  
  // Return no more than this many hits
  $search_limit = getCGIParam('adv_limit_val', 'GP', $system['search_limit']);
  if ($search_limit != 0) {
    setSessionVar('adv_gene_limit', $search_limit);
  }
  $search_limit = ($search_limit > $system['search_limit_max'] || $search_limit == 0) ? 
                     $system['search_limit_max'] : $search_limit;
  
   // What page is this?
  $pagenum = getCGIParam('pagenum', 'GP', 1);

  if ($pagenum > 1) {
//logMessage("Handle advanced search results page $pagenum");
    // Not the first page; result data will be passed in
    $rows = getCGIParam('rows_adv', 'GP', '');
    $locusList = unserialize(urldecode($rows));
    $arrCount = count($locusList);

    // Handle just this page
    $bauplan = new Bauplan('Results page');
    $template_file = "../../templates/gene_center/gene_adv_results-page.bau";
    $tmpl = $bauplan->template()->load($template_file);
    
    $start = ($pagenum-1) * $pagesize + 1;
    $end = ($start+$pagesize > $arrCount) 
                  ? $arrCount : $start+$pagesize-1;
    
    $page_rows = processOneAdvPage($DBConn, $locusList, $start, $end);
//logVarDump($page_rows, "Rows for this page:");
    $tmpl->get('adv_gene-row')->loop($page_rows);

    // Check for more pages, if so, start loading the next page
    $pagecount = floor(($arrCount-1)/$pagesize) + 1;
    if ($pagenum < $pagecount) {
      $tmpl->get('nextpage')->replace("" . $pagenum+1);
      $tmpl->get('load-next-page_adv')->unmute();
    }
    
    $bauplan->publish();
    
    // Just bail out at this point
    exit;
  }//handle subsequent page

  //jp -- used to distinguish searches on pages that run multiple ones
  $div_name = getCGIParam("div_name", "GP", false);
  $template->get('div')->replace($div_name);
  
  // NOTE that these parameters are passed via JavScript function geneAdvSearch(),
  //      not directly from the form.
  $versionbox    = getCGIParam("box_version", "GP", false);
  $version       = getCGIParam("version", "GP", false);
  
  $typebox       = getCGIParam("box_type", "GP", false);
  $type          = getCGIParam("type", "GP", false);
  
  $chrbox        = getCGIParam("box_chr", "GP", false);
  $chromosome    = getCGIParam("chromosome", "GP", false);
  
  $rangebox      = getCGIParam("box_range", "GP", false);
  $rangestart    = getCGIParam("range_start", "GP", false);
  $rangeend      = getCGIParam("range_end", "GP", false);

  $locusassocbox = getCGIParam("box_locus_assoc", "GP", false);

  $gpbox         = getCGIParam("box_gp", "GP", false);
  $gene_product  = getCGIParam("gene_product", "GP", false);
  
  $phenobox      = getCGIParam("box_pheno", "GP", false);
  $pheno         = getCGIParam("pheno", "GP", false);
  
  $traitbox      = getCGIParam("box_trait", "GP", false);
  $trait         = getCGIParam("trait", "GP", false);
  
  $tandembox     = getCGIParam("box_tandem", "GP", false);
  
  $proteinbox     = getCGIParam("box_protein", "GP", false);
  $protein        = getCGIParam("protein", "GP", false);

  $gmassocbox    = getCGIParam("box_gm_assoc", "GP", false);
  
//$msg = "\ngene_adv_results.php: versionbox=$versionbox & version=$version, ";
//$msg .= "typebox=$typebox & type=$type, ";
//$msg .= "chrbox=$chrbox & chromosome=$chromosome, ";
//$msg .= "rangebox=$rangebox & rangestart=$rangestart, rangeend=$rangeend, ";
//$msg .= "locusassocbox=$locusassocbox, ";
//$msg .= "gpbox=$gpbox & gene_product=$gene_product, ";
//$msg .= "phenobox=$phenobox & pheno=$pheno, ";
//$msg .= "traitbox=$traitbox & trait=$trait, ";
//$msg .= "gmassocbox=$gmassocbox, ";
//$msg .= "proteinbox=$proteinbox & protein=$protein";
//logMessage("get_adv_results.php: $msg");

  $search_filters = array();
  
  // Only return genes from active annotation
  $search_filters['chado_clauses'][] = "analysis_is_current='yes'"; 
  
  // Add filters to the basic SQL statement
  if ($versionbox == 'true') {
    $search_filters = setVersionFilter($version, $search_filters);
  }
  if ($typebox == 'true') {
    $search_filters = setTypeFilter($type, $search_filters);
  }
  if ($chrbox == 'true') {
    $search_filters = setChrFilter($chromosome, $search_filters);
  }
  if ($rangebox == 'true') {
    $search_filters = setRangeFilter($rangestart, $rangeend, $search_filters);
  }
  if ($locusassocbox == 'true') {
    $search_filters = setLocusFilter($search_filters);
  }
  if ($gpbox == 'true') {
    $search_filters = setGPFilter($DBConn, $gene_product, $search_filters);
  }
  if ($phenobox == 'true') {
    $search_filters = setPhenoFilter($DBConn, $pheno, $search_filters);
  }
  if ($traitbox == 'true') {
    $search_filters = setTraitFilter($DBConn, $trait, $search_filters);
  }
  if ($tandembox == 'true') {
    $search_filters = setTandemFilter($DBConn, $search_filters);
  }
  if ($proteinbox == 'true') {
    $search_filters = setProteinFilter($DBConn, $protein, $search_filters);
  }
  if ($gmassocbox == 'true') {
    $search_filters = setGmFilter($search_filters);
  }

  // No checkboxes were selected -- don't run searches and exit
  if (!isset($search_filters["criteria"]) || count($search_filters["criteria"]) == 0) {
    $template->get('no-results_adv')->unmute();
    $bauplan->publish();
    exit;
  }

  $where = (isset($search_filters['chado_clauses'])) 
         ? "WHERE " . implode(' AND ', $search_filters['chado_clauses'])
         : '';
  $intersects = (isset($search_filters['intersects']))
         ? "INTERSECT " . implode(' INTERSECT ', $search_filters['intersects'])
         : '';
  $sql = "
    SELECT gene_name, genbank_name, locus_name, locus_id, locus_full_name, 
           chr, model_type, version
    FROM chado.gene_model
    $where
    $intersects
    ORDER BY locus_name, gene_name DESC";
    
  $sql_unlimited = $sql;

  
  $sql .= " LIMIT $search_limit";
  $sth = make_query($DBConn, $sql);
  $rows = get_all_rows($sth);
  $arrCount = count($rows);

  if ($arrCount == $search_limit) {
    //Find out how many there would be without a limit, and offer to download all unlimited results in a file
    $stmt_count = make_query($DBConn, $sql_unlimited);
    $unlimited_rows = get_all_rows($stmt_count);
    $unlimited_count = count($unlimited_rows);
    $template->get('unlimited_count')->replace($unlimited_count);
    $template->get('unlimited_rows')->replace(urlencode(serialize($unlimited_rows)));
  }

  // build result list from rows returned from query
  $resultList = array();
  for($i=0; $i<$arrCount; $i++) {
    $resultList[$i]['locus_name']      = trim($rows[$i]['locus_name']);
    $resultList[$i]['locus_id']        = $rows[$i]['locus_id'];
    $resultList[$i]['locus_full_name'] = trim($rows[$i]['locus_full_name']);
    $resultList[$i]['gene_name']       = trim($rows[$i]['gene_name']);
    //$resultList[$i]['line']            = trim($rows[$i]['line']);
    $resultList[$i]['model_type']      = trim($rows[$i]['model_type']);
    $resultList[$i]['chr']             = trim($rows[$i]['chr']);
    $resultList[$i]['version']         = trim($rows[$i]['version']);
      
    if ($i % 2 == 0)
      $bgcolor = "#F5F5F5";
    else
      $bgcolor = "";
    $resultList[$i]['bgcolor'] = $bgcolor;
  }//each record found
  
  if ($arrCount < $pagesize) 
    $pagesize = $arrCount;

  $pages = calcPages($arrCount, $pagesize, 'gene_adv_results_page');
  $template->get('total')->replace($arrCount);

  $main = getCGIParam('main', 'P', false);
  if ($arrCount == 1 && $main != "true") {
    // Found only one record: go to it directly
    $id = (isset($resultList[0]['gene_name']) && $resultList[0]['gene_name'] != '')
        ? $resultList[0]['gene_name']
        : $resultList[0]['locus_name'];
    echo "javascript:document.location = '/gene_center/gene/$id'";
    exit;
  }
  else {
    if ($arrCount == 0) {
      $template->get('no-results_adv')->unmute();
    }
    else if (count($pages) > 1) {
      // there will be multiple pages of results
      $template->get('pages')->loop($pages);
      $template->get('adv_results-paged')->unmute();

      $template->get('criteria')->replace(ucfirst(implode(' and ', $search_filters['criteria'])).'.'); 
      $template->get('count')->replace($arrCount);
      $template->get('rows')->replace(urlencode(serialize($resultList)));
    
      if ($arrCount == $search_limit) {
        $template->get('limit')->replace($search_limit);
        $template->get('results_limited')->toggle();
      }
      
      // Fill in table for first page
      $page_rows = processOneAdvPage($DBConn, $resultList, 1, $pagesize); 
      $template->get('adv_gene-page-row')->loop($page_rows);
    }
    
    else {
      $template->get('adv_results')->unmute();
      $template->get('criteria')->replace(ucfirst(implode(' and ', $search_filters['criteria'])).'.');
      $template->get('count')->replace($arrCount);
      
      // Fill in the table
      $page_rows = processOneAdvPage($DBConn, $resultList, 1, $arrCount);
      $template->get('adv_gene-row')->loop($page_rows);
      
    }//multiple records found
  }//0 or many records found
  
  $bauplan->publish();



/*いいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいい
いいいいいいいいいFUNCTION JUNCTION, WHAT'S YOUR FUNCTION?いいいいいいいいいいいい
いいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいい*/ 

function getMap($mapname, $mapsource, $DBConn, $adv_results) {
  if (($mapsource > 0) && (strlen($mapname) > 0)) {
     $adv_results['basic_query'] .= " 
       INTERSECT SELECT A.ID 
       FROM LOCUS_COORDINATES A, MAP B 
       WHERE A.MAP = B.ID 
             AND  LOWER(B.NAME) LIKE '" . strtolower($mapname) . "%' 
             AND B.SOURCE = $mapsource";
     $adv_results['criteria'] .= "You wanted genes mapped on <b>a map with a name similar to '" 
                              . mgdb_html($mapname) . "'</b>.<br>\n";
     $query_lookup_source = "SELECT NAME FROM PERSON WHERE ID = " . $mapsource;
     $stmt_lookup_source = make_query($DBConn,$query_lookup_source,1);
     $arrSource = retrieve_row($stmt_lookup_source);
     $adv_results['criteria'] .= "You wanted genes mapped on <b>a map with source 
                              <a href=\"/person?id=" . mgdb_html($mapsource) . "\">" 
                              . mgdb_html(trim($arrSource["NAME"])) . "</a></b>.<br>";
  }
  else if($mapsource > 0) {
    $adv_results['basic_query'] .= " 
       INTERSECT SELECT A.ID 
       FROM LOCUS_COORDINATES A, MAP B 
       WHERE A.MAP = B.ID AND B.SOURCE = $mapsource";
    $query_lookup_source = "SELECT NAME FROM PERSON WHERE ID = $mapsource";
    $stmt_lookup_source = make_query($DBConn,$query_lookup_source,1);
    $arrSource = retrieve_row($stmt_lookup_source);
    $adv_results['criteria'] .= "You wanted genes mapped on <b>a map with source 
                            <a href=\"/person?id=" . mgdb_html($mapsource) . "\">" 
                            . mgdb_html(trim($arrSource["NAME"])) . "</a></b>.<br>";
  }
  else if(strlen($mapname) > 0) {
     $adv_results['basic_query'] .= " 
        INTERSECT SELECT A.ID 
        FROM LOCUS_COORDINATES A, MAP B 
        WHERE A.MAP = B.ID AND LOWER(B.NAME) 
          LIKE '" . strtolower($mapname) . "%'";
     $adv_results['criteria'] .= "You wanted genes mapped on <b>
                              a map with a name similar to '" . mgdb_html($mapname) . "'</b>.<br>\n";
  }
  else {
    $adv_results['basic_query'] .= " INTERSECT SELECT DISTINCT(ID) FROM LOCUS_COORDINATES";
    $adv_results['criteria'] .= "You wanted genes mapped on <b>any map</b>.<br>";
  }
  return $adv_results;
}//getMap


function getPheno($pheno, $DBConn, $adv_results) {
   if ($pheno > 0) {
      $adv_results['basic_query'] .= " 
        intersect select distinct(a.id) 
        from locus a join variation b on a.id = b.variationof join var_pheno_effects c on b.id = c.id
        where c.pheno_effect =  " . (int) $pheno;
      $query_lookup_pheno = "SELECT NAME FROM PHENOTYPE WHERE ID = " . (int) $pheno;
      $stmt_lookup_pheno = make_query($DBConn,$query_lookup_pheno,1);
      $arrPhenoName = retrieve_row($stmt_lookup_pheno);
      $adv_results['criteria'] .= "You wanted genes that have mutants with the <b>phenotype " 
                               . mgdb_html(trim($arrPhenoName["NAME"])) . "</b>.<br>";
   }
   else {
     $adv_results['basic_query'] .= " 
       INTERSECT SELECT DISTINCT(A.VARIATIONOF) 
       FROM VARIATION A JOIN VAR_PHENO_EFFECTS B ON A.ID = B.ID";
     $adv_results['criteria'] .= "You wanted genes that have variations with a known <b>phenotype</b>.<br>";
   }
   return $adv_results;
}//getPheno


function processOneAdvPage($DBConn, $resultList, $start, $end) {
//logMessage("process records $start-$end");
  for ($i=$start-1; $i<$end; $i++) { 
    $resultList[$i]['path'] = (isset($resultList[$i]['gene_name'])) 
                            ? '/gene_center/gene' 
                            : '/data_center/locus';
  }
  
  return array_slice($resultList, $start-1, ($end-$start)+1);
}//processOneAdvPage()


function setChrFilter($chromosome, &$search_filters) {
  $chromosome = strtolower($chromosome);
  $search_filters['criteria'][] = (($chromosome == 'all')
                             ? "on all chromosomes"
                             : ($chromosome == 'Unplaced'))
                               ? "not placed on a chromosome"
                               : "on chromosome '$chromosome'";
  if ($chromosome == 'unplaced') {
    $search_filters['chado_clauses'][] = "LOWER(chr) NOT LIKE 'chr%'";
  }
  else if ($chromosome != 'all') {
    $search_filters['chado_clauses'][] = "LOWER(chr)='$chromosome'";
  }
  
  return $search_filters;
}//setChrFilter


function setGmFilter($search_filters) {
  $search_filters['criteria'][] = "associated with a gene model";
  $search_filters['chado_clauses'][] = "gene_name IS NOT NULL";
  
  return $search_filters;
}//setGmFilter


function setGPFilter($DBConn, $gp_id, &$search_filters) {
  global $gene_product;
  
  if ($gp_id != 'all') {
    // Get gene product name
    $sql = "SELECT name FROM gene_product WHERE id=$gp_id";
    $sth = make_query($DBConn, $sql);
    $row = retrieve_row($sth);
    $gp_name = $row['name'];
  }//get gp name
  
  $search_filters['criteria'][] = ($gene_product == 'all')
                             ? "with a known gene product"
                             : "with the gene product <b>$gp_name</b>";
  if ($gp_id != 'all') {
    $search_filters['intersects'][] = "
      SELECT gene_name, genbank_name, locus_name, locus_id, locus_full_name, 
             chr, model_type, version 
      FROM chado.gene_model gm
        INNER JOIN locus_gene_products lgp ON lgp.id=gm.locus_id
      WHERE lgp.gene_product=$gp_id";
  }
  else {
    $search_filters['intersects'][] = "
      SELECT gene_name, genbank_name, locus_name, locus_id, locus_full_name, 
             chr, model_type, version 
      FROM chado.gene_model gm
        INNER JOIN locus_gene_products lgp ON lgp.id=gm.locus_id";
  }
  
  return $search_filters;
}//setGPFilter


function setLocusFilter($search_filters) {
  $search_filters['criteria'][] = "<b>associated with a gene locus</b>";
  $search_filters['chado_clauses'][] = "locus_name IS NOT NULL";
  
  return $search_filters;
}//setLocusFilter


function setPhenoFilter($DBConn, $pheno, $search_filters) {
  if ($pheno) {
    // Get phenotype name
    $sql = "SELECT name FROM phenotype WHERE id=$pheno";
    $sth = make_query($DBConn, $sql);
    $row = retrieve_row($sth);
    $pheno_name = $row['name'];
  }//get pheno name
  
  $search_filters['criteria'][] = (!$pheno)
                                ? "<b>with a known phenotype</b>"
                                : "with phenotype <b>$pheno_name</b>";
  $search_filters['chado_clauses'][] = (!$pheno)
    ? "locus_id IN (SELECT DISTINCT l.id
                    FROM phenotype ph
                      INNER JOIN var_pheno_effects phe ON phe.pheno_effect=ph.id
                      INNER JOIN variation v ON v.id=phe.id
                      INNER JOIN locus l ON l.id=v.variationof)"
    : "locus_id IN (SELECT DISTINCT l.id
                    FROM phenotype ph
                      INNER JOIN var_pheno_effects phe ON phe.pheno_effect=ph.id
                      INNER JOIN variation v ON v.id=phe.id
                      INNER JOIN locus l ON l.id=v.variationof
                    WHERE ph.id=$pheno)";
  
  return $search_filters;
}//setPhenoFilter


function setProteinFilter($DBConn, $protein, &$search_filters) {
  $search_filters['criteria'][] = (!$protein)
                                ? "<b>associated with a protein or enzyme</b>"
                                : ">associated with protein or enzyme <b>$protein</b>";
  $search_filters['chado_clauses'][] = (!$protein)
    ? ""  // Looking for gene models with ANY associated protein is way too slow
    : "feature_id IN (
          SELECT feature_id FROM chado.feature_dbxref
          WHERE dbxref_id = (SELECT dbxref_id FROM chado.dbxref WHERE accession='$protein')
          UNION
          SELECT af.feature_id FROM chado.analysisfeature_dbxref afx
            INNER JOIN chado.analysisfeature af ON af.analysisfeature_id=afx.analysisfeature_id
          WHERE dbxref_id = (SELECT dbxref_id FROM chado.dbxref WHERE accession='$protein')
         )
       OR canonical_transcript_id IN (
          SELECT feature_id FROM chado.feature_dbxref
          WHERE dbxref_id = (SELECT dbxref_id FROM chado.dbxref WHERE accession='$protein')
          UNION
          SELECT af.feature_id FROM chado.analysisfeature_dbxref afx
            INNER JOIN chado.analysisfeature af ON af.analysisfeature_id=afx.analysisfeature_id
          WHERE dbxref_id = (SELECT dbxref_id FROM chado.dbxref WHERE accession='$protein')
         )
       ";

  return $search_filters;
}//setProteinFilter


function setRangeFilter($rangestart, $rangeend, $search_filters) {
  $rangestart = ($rangestart == 0) ? 1 : $rangestart;
  $rangeend   = ($rangeend == 0) ? 1 : $rangeend;
  
  if ($rangestart && $rangestart > 0 && $rangeend && $rangeend > 0) {
    $search_filters['criteria'][] = "between <b>$rangestart and $rangeend</b>";
    $search_filters['chado_clauses'][] = "gm_start>=$rangestart AND gm_end<=$rangeend";
  }
  
  return $search_filters;
}//setRangeFilter


function setTandemFilter($DBConn, $search_filters) {
  $search_filters['criteria'][] = 'member of a tandem array';
  $search_filters['chado_clauses'][] = 
    "feature_id IN (SELECT feature_id FROM chado.tandem_gene_model)";
  return $search_filters;
}//setTandemFilter


function setTraitFilter($DBConn, $trait, $search_filters) {
  if ($trait) {
    // Get trait name
    $sql = "SELECT name FROM term WHERE id=$trait";
    $sth = make_query($DBConn, $sql);
    $row = retrieve_row($sth);
    $trait_name = $row['name'];
  }//get trait name

  $search_filters['criteria'][] = (!$trait)
                             ? "<b>associated with a trait</b>"
                             : "associated with trait <b>$trait_name</b>";
  $search_filters['chado_clauses'][] = (!$trait)
    ? "locus_id IN (SELECT DISTINCT l.id
                    FROM phenotype ph
                      INNER JOIN var_pheno_effects phe ON phe.pheno_effect=ph.id
                      INNER JOIN variation v ON v.id=phe.id
                      INNER JOIN locus l ON l.id=v.variationof
                      INNER JOIN term t ON t.id=ph.trait)"
    : "locus_id IN (SELECT DISTINCT l.id
                    FROM phenotype ph
                      INNER JOIN var_pheno_effects phe ON phe.pheno_effect=ph.id
                      INNER JOIN variation v ON v.id=phe.id
                      INNER JOIN locus l ON l.id=v.variationof
                      INNER JOIN term t ON t.id=ph.trait
                    WHERE t.id=$trait)";
  
  return $search_filters;
}//setTraitFilter


function setTypeFilter($type, &$search_filters) {
  $search_filters['criteria'][] = ($type == 'all')
                             ? "<b>of all types</b>"
                             : "of type <b>$type</b>";
  if ($type != 'all') {
    $search_filters['chado_clauses'][] = "model_type='$type'";
  }
  
  return $search_filters;
}//setTypeFilter


function setVersionFilter($version, &$search_filters) {
  $search_filters['criteria'][] = ($version == 'all')
                             ? "<a>from all gene model sets</b>"
                             : "from the <b>$version</b> gene model set";
  if ($version != 'all') {
    $search_filters['chado_clauses'][] = "version='$version'";
  }
  
  return $search_filters;
}//setVersionFilter


?>
