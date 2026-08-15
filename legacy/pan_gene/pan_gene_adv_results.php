 <?php
  /* file: pan_gene_adv_results.php
 * 
 * purpose: search for pan-genes that match search parameters
 *
 * history:
 *   02/09/23  eksc created from gene_adv_results.php
 */
 
  include_once('../../lib/Bauplan.php');
  include_once("../../include/db-api.php");
  include_once("../../include/gp_lib.php");
  include_once("../../include/gene_center_lib.php");
  include_once("../../include/pan_gene_lib.php");
  include_once('./pan_gene_results_lib.php');
logMessage("Starting pan_gene_adv_results.php");
  
  $system = getSystemInfo('mgdb.conf');
  
  // This many records per page
  $pagesize = $system['pagesize'];
  
  // Create a bauplan object
  $bauplan = new Bauplan('Results page');

  // Get a database connection
  $DBConn = connect_to_database();
  
  // What page is this?
  $pagenum = getCGIParam('pagenum', 'GP', 1);

  if ($pagenum > 1) {
    // Not the first page
    $matches = unserialize(urldecode($_SESSION['rows']));
    $match_count = count($matches);

    // Handle just this page
    $template_file = "../../templates/pan_gene_center/pan_gene-results-page.bau";
    $tmpl = $bauplan->template()->load($template_file);
    
    $start = ($pagenum-1) * $pagesize;
    $search_match_page = $matches;
    $search_match_page['matches'] = array_slice($matches, $start, $pagesize);
    $html = processOneAdvPage($matches, $start, $pagesize, $DBConn);
    $tmpl->get('multi-page')->replace($html);

    // Check for more pages, if so, start loading the next page
    $pagecount = floor(($match_count-1)/$pagesize) + 1;
    if ($pagenum < $pagecount) {
      $tmpl->get('nextpage')->replace("" . $pagenum+1);
      $tmpl->get('advanced-search-get_next_page')->unmute();
      $tmpl->get('load-next-page')->unmute();
    }
    
    $bauplan->publish();
    
    // Just bail out at this point
    exit;
  }//handle subsequent page

  // Return no more than this many hits
  $search_limit = getCGIParam('adv_limit_val', 'GP', $system['search_limit']);
  if ($search_limit != 0) {
    setSessionVar('adv_limit', $search_limit);
  }
  $search_limit = ($search_limit > $system['search_limit_max'] || $search_limit == 0) 
                ? $system['search_limit_max'] : $search_limit;
//logMessage("Search limit is $search_limit");
  
  // NOTE that these parameters are passed via JavScript function panGeneAdvSearch(),
  //      not directly from the form.
  $appearbox     = getCGIParam("box_appear", "GP", '');
  $appear        = getCGIParam("appear", "GP", '');
  $analysisbox   = getCGIParam("box_analysis", "GP", '');
  $analysis      = getCGIParam("analysis", "GP", '');
  $genemodelsbox = getCGIParam("box_gene_models", "GP", false);
  $genemodels    = getCGIParam("gene_models", "GP", false);
  $locusbox      = getCGIParam("box_locus", "GP", false);
  $minbox        = getCGIParam("box_min", "GP", false);
  $min           = trim(getCGIParam("min", "GP", false));
  $maxbox        = getCGIParam("box_max", "GP", false);
  $max           = trim(getCGIParam("max", "GP", false));
  $minannotsbox  = getCGIParam("box_min_annots", "GP", false);
  $minannots     = trim(getCGIParam("min_annots", "GP", ''));
  $maxannotsbox  = getCGIParam("box_max_annots", "GP", false);
  $maxannots     = trim(getCGIParam("max_annots", "GP", ''));
  $notappearbox  = getCGIParam("box_not_appear", "GP", '');
  $notappear     = getCGIParam("not_appear", "GP", '');
  $proteinbox    = getCGIParam("box_protein", "GP", false);
  $proteins      = getCGIParam("proteins", "GP", false);
  $traitbox      = getCGIParam("box_trait", "GP", false);
  
  
  // IMPORTANT: to add filters, it may be necessary to changed the materialized
  //            view chado.pan_gene_search
  $search_filters = array('criteria' => array(), 'filters' => array());
  if ($analysisbox == 'true') {
    $search_filters = setAnalysisFilter($analysis, $search_filters);
  }
  if ($appearbox == 'true') {
    $search_filters = setAppearFilter($appear, $search_filters);
  }
  if ($genemodelsbox == 'true') {
    $search_filters = setGeneModelFilter($genemodels, $search_filters);
  }
  if ($locusbox == 'true') {
    $search_filters = setLocusFilter($search_filters);
  }
  if ($minbox == 'true') {
    $search_filters = setMinFilter($min, $search_filters);
  }
  if ($maxbox == 'true') {
    $search_filters = setMaxFilter($max, $search_filters);
  }
  if ($minannotsbox == 'true') {
    $search_filters = setMinAnnotsFilter($minannots, $search_filters);
  }
  if ($maxannotsbox == 'true') {
    $search_filters = setMaxAnnotsFilter($maxannots, $search_filters);
  }
  if ($notappearbox == 'true') {
    $search_filters = setNotAppearFilter($notappear, $search_filters);
  }
  if ($proteinbox == 'true') {
    $search_filters = setProteinFilter($proteins, $search_filters);
  }
  if ($traitbox == 'true') {
    $search_filters = setTraitFilter($search_filters);
  }

  // Download request?
  if (getCGIParam('download', 'GP', false) == 'yes') {
logMessage("Download requested");
    if (!($job_id=getCGIParam('job_id', 'GP', array()))) {
      reportError("Download requested with no job id.");
      exit;
    }
    downloadAdvResults($DBConn, $search_filters, $job_id, getCGIParam('filename', 'GP', ''), getCGIParam('exemplars', 'GP', ''));
    exit;
  }

  // Build query from filters and process first page if any results
  $template_file = '../../templates/pan_gene_center/pan_gene-results.bau';
  $tmpl = $bauplan->template()->load($template_file);
  $tmpl->get('div')->replace('adv_results');
  
//logVarDump($search_filters, "There are " . count($search_filters['filters']) . " in:\n");
  // No filters were set
  if (count($search_filters['filters']) == 0 && !isset($search_filters['locus'])) {
    $tmpl->get('no-filters')->unmute();
    $bauplan->publish();
    exit;
  }
  
  $matches = getAdvMatches($search_filters, $search_limit, $DBConn);
  $match_count = count($matches);
//logMessage("Found $match_count matches");
//logVarDump($matches, "All matches:\n");

  if ($match_count == 0) {
    $tmpl->get('no-results_adv')->unmute();
  }

  else {
    if ($match_count <= $pagesize) {
      // Show one page
      $tmpl->get('multi-results')->unmute();
      $tmpl->get('adv_results')->unmute();
      $tmpl->get('criteria')->replace(ucfirst(implode(' and ', $search_filters['criteria'])).'.');
      $tmpl->get('count')->replace($match_count);
      $tmpl->get('pan_gene-adv_download')->unmute();
      // Fill in the table
      $html = processOneAdvPage($matches, 0, $match_count, $DBConn);
      $tmpl->get('one-page')->replace($html);
    }
    else {
      // There will be multiple pages of results
      $tmpl->get('advanced-search-get_next_page')->unmute();
      
      // Count and prep pages
      $pages = calcPages($match_count, $pagesize, 'pan_gene_results_page');

      $tmpl->get('pages')->loop($pages);
      $tmpl->get('pagecount')->replace(count($pages));
      $tmpl->get('multi-results-paged')->unmute(); 

      $tmpl->get('adv_results')->unmute();
      $tmpl->get('criteria')->replace(ucfirst(implode(' and ', $search_filters['criteria'])).'.'); 
      
      $tmpl->get('multi_adv_results')->unmute();
      $tmpl->get('count')->replace($match_count);
      $tmpl->get('pan_gene-adv_paged_download')->unmute();
      
      // Save serialized match array in session object for all subsequent pages
      $_SESSION['rows'] = urlencode(serialize($matches));
    
      if ($match_count == $search_limit) {
        $tmpl->get('limit')->replace($search_limit);
        $tmpl->get('results_limited_adv')->unmute();
      }
      
      // Fill in table for first page
      $html = processOneAdvPage($matches, 0, $pagesize, $DBConn); 
      $tmpl->get('multi-page')->replace($html);
    }
  }
    
  $bauplan->publish();



/*いいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいい
いいいいいいいいいFUNCTION JUNCTION, WHAT'S YOUR FUNCTION?いいいいいいいいいいいい
いいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいい*/ 

function processOneAdvPage($matches, $start, $pagesize, $DBConn) {
logMessage("Process one page of advanced results");
//logVarDump($matches, "All matches:\n");
  $analyses = array_unique(array_column($matches, 'pan_gene_analysis'));
//logVarDump($analyses, "These analyses are represented:\n");
  
  // Get analysis metatdata
  $metadata = array();
  foreach ($analyses as $analysis) {
//logMessage("Process " . $analysis);
    $metadata = array_merge($metadata, getPanGeneAnalysisMetadata($analysis, $DBConn));
  }
  
  $html = showAdvMatches($metadata, array_slice($matches, $start, $pagesize), $DBConn);
  return $html;
}//processOneAdvPage()


function setAnalysisFilter($analysis, $search_filters) {
  if ($analysis == '') {
    return $search_filters;
  }
  $search_filters['criteria'][] = "in the analysis '$analysis'";
  $search_filters['filters'][] = "pan_gene_analysis = '$analysis'";
  return $search_filters;
}//setAnalysisFilter


function setAppearFilter($appear, $search_filters) {
  if (!$appear || count($appear) == 0) {
    return $search_filters;
  }
/* only-in:
  $search_filters['criteria'][] = "with members from only annotations " . implode(',', $appear);
  $filter = "(assemblies <@ ARRAY['" . implode("','", $appear) . "']::varchar[] "
          . "AND ARRAY['" . implode("','", $appear) . "']::varchar[] @> assemblies)";
  $search_filters['filters'][] = $filter;
*/
  $search_filters['criteria'][] = "with members from annotations " . implode(', ', $appear);
  $filter = "annotations @> ARRAY['" . implode("','", $appear) . "']::varchar[]";
  $search_filters['filters'][] = $filter;
  return $search_filters;
}//setAppearFilter


function setGeneModelFilter($genemodels, $search_filters) {
  if ($genemodels == '') {
    return $search_filters;
  }
  $gms = preg_split("/[,;\t\n]/", $genemodels);
  $gms = array_map('trim', $gms);
  $search_filters['criteria'][] = "contain the gene models " . implode("', '", $gms);
  $search_filters['filters'][] = "gene_model_name LIKE ANY (ARRAY['" . implode("','", $gms) . "%'])";
  return $search_filters;
}//setGeneModelFilter


function setLocusFilter($search_filters) {
  // Don't set a search filter. Just indicate that a locus association is required.
  $search_filters['criteria'][] = "associated with a locus";
  $search_filters['filters'][] = "loci IS NOT NULL";
  return $search_filters;
}//setLocusFilter


function setMaxAnnotsFilter($maxperc, $search_filters) {
  if ($maxperc == '') {
    return $search_filters;
  }
  $search_filters['criteria'][] = "in no more than $maxannot % of annotations";
  $search_filters['filters'][] = "assembly_count <= ($maxperc*max_annots) / 100";
  return $search_filters;
}//setMaxFilter


function setMinAnnotsFilter($minperc, $search_filters) {
  if ($minperc == '') {
    return $search_filters;
  }
  $search_filters['criteria'][] = "in at least $minannot % of the annotations";
  $search_filters['filters'][] = "assembly_count >= ($minperc*max_annots) / 100";
  return $search_filters;
}//setMinFilter


function setMaxFilter($max, $search_filters) {
  if ($max == '') {
    return $search_filters;
  }
  $search_filters['criteria'][] = "with no more than $max members";
  $search_filters['filters'][] = "pan_gene_count <= $max";
  return $search_filters;
}//setMaxFilter


function setMinFilter($min, $search_filters) {
  if ($min == '') {
    return $search_filters;
  }
  $search_filters['criteria'][] = "with at least $min members";
  $search_filters['filters'][] = "pan_gene_count >= $min";
  return $search_filters;
}//setMinFilter


function setNotAppearFilter($notappear, $search_filters) {
  if (!$notappear || count($notappear) == 0) {
    return $search_filters;
  }
  $search_filters['criteria'][] = "without members from annotations " . implode(', ', $notappear);
  
  $clauses = array();
  foreach ($notappear as $a) {
    $clauses[] = "NOT ('$a' = ANY (annotations))";
  }
  $search_filters['filters'][] = implode(' AND ', $clauses);
  
  return $search_filters;
}//setNotAppearFilter


function setProteinFilter($proteinstr, $search_filters) {
  if ($proteinstr == '') {
    // Don't set a search filter. Just indicate that a protein association is required.
    $search_filters['criteria'][] = "associated with a protein";
    $search_filters['filters'][] = "protein IS NOT NULL";
  }
  else {
    $proteins = preg_split("/[\s+|\t|,|\n]/", $proteinstr);
    $search_filters['criteria'][] = "associated with protein(s) " 
                                  . implode(',', $proteins);
    $search_filters['filters'][] = "protein IN ('" . implode("','", $proteins) . "')";
  }
  return $search_filters;
}//setProteinFilter


function setTraitFilter($search_filters) {
  // Don't set a search filter. Just indicate that a locus association is required.
  $search_filters['criteria'][] = "associated with a trait";
  $search_filters['filters'][] = "trait_name IS NOT NULL";
  return $search_filters;
}//setTraitFilter


function showAdvMatches($metadata, $matches, $DBConn) {
//logVarDump($metadata, "Metadata for advanced matches:\n");
  $assembly_counts = array();
  $analyses = array_unique(array_column($metadata['annotation_metadata'], 'analysis'));
//logVarDump($analyses, "Get assembly counts for:\n");
  foreach ($analyses as $analysis) {
//logMessage("Get number of assemblies in $analysis");
    $assembly_counts[$analysis] = getPanGeneAnnotationCount($analysis, $DBConn);
  }
//logVarDump($assembly_counts, "Assembly counts for each analysis:\n");
  
  // Because sections can't be embedded in iterated sections, and because
  //   Bauplan doesn't work with HTML fragments, have to do all the HTML
  //   for a page of search results here. (ick)
  $html = "
    <div id=\"results_box\">
      <table width=\"100%\" cellpadding=0 cellspacing=0>
        <tr>
          <th valign=\"bottom\" align=\"left\" width=\"20%\">Pan-gene exemplar</th>
          <th valign=\"bottom\" align=\"left\" width=\"30%\">Locus</th>
          <th valign=\"bottom\" align=\"left\" width=\"30%\">Protein(s)</th>
          <th valign=\"bottom\" align=\"left\" width=\"30%\">Trait(s)</th>
          <th valign=\"bottom\" align=\"center\" width=\"10%\"># Members&nbsp;</th>
          <th valign=\"bottom\" align=\"center\" width=\"15%\"># Assemblies</th>
          <th valign=\"bottom\" align=\"left\" width=\"25%\">Analysis</th>
        </tr>";
  foreach ($matches as $match) {
    $exmplar = $match['exemplar_gene_model'];
    $loci = str_replace(',', ', ', trim($match['loci'], '{}'));
    $proteins = str_replace('"', '', str_replace(',', ', ', trim($match['proteins'], '{}')));
    $traits = str_replace('"', '', str_replace(',', ', ', trim($match['traits'], '{}')));
    $html .= "
        <tr>
          <td valign=\"top\" width=\"20%\">
            <a href=\"/pan_gene_center/pan_gene/$exmplar\">$exmplar</a>&nbsp;
          </td>
          <td valign=\"top\" width=\"30%\">$loci</td>
          <td valign=\"top\" width=\"30%\">$proteins</td>
          <td valign=\"top\" width=\"30%\">$traits</td>
          <td valign=\"top\" align=\"center\" width=\"10%\">" . $match['pan_gene_count'] . "</td>
          <td valign=\"top\" align=\"center\" width=\"10%\">" 
            . $match['assembly_count'] . '/' . $assembly_counts[$match['pan_gene_analysis']]
            . "</td>
          <td class=\"nowrap\" valign=\"top\" width=\"40%\">" . $match['pan_gene_analysis'] . "</td>
        </tr>";
   }//each match
   $html .= "
      </table>
      <br><br>
     </div>";
     
  return $html;
}//showAdvMatches


?>
