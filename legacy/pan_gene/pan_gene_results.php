<?php
/* file: pan_gene_results.php
 *
 * purpose: get and display results of gene model record search.
 *
 * history:
 *   02/08-23  eksc  created from gene_results.php
 */

  include_once('../../lib/Bauplan.php');
  include_once('../../include/db-api.php');
  include_once('../../include/gp_lib.php');
  include_once('../../include/data_center_functions.php');
  include_once('../../include/gene_center_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');
  include_once('./pan_gene_results_lib.php');

  $DBConn = connect_to_database();

  $raw_term = urldecode(getCGIParam('term', 'GP', getCGIParam('pan_gene_term', 'S', '')));
  $term = cleanSearchTerm($raw_term, $DBConn);
  $url_term = urlencode($raw_term);
//logMessage("Search for [$raw_term]");
  
  // Create a bauplan object
  $bauplan = new Bauplan('Results page');
  
  if ($term == '' || $term == '%%') {
    // Don't try searching if no term or only a wildcard
    $template_file = '../../templates/pan_gene_center/pan_gene-results.bau';
    $tmpl = $bauplan->template()->load($template_file);
    $tmpl->get('no-term')->unmute();
    $bauplan->publish();
    exit;
  }

  // Download request?
  if (getCGIParam('download', 'GP', false) == 'yes') {
    if (!($job_id=getCGIParam('job_id', 'GP', false))) {
      reportError("Download requested with no job id.");
      exit;
    }
    downloadResults($DBConn, $term, $job_id, getCGIParam('filename', 'GP', ''));
    exit;
  }

  // Is this search being run from the shadowbox search?
  $main = getCGIParam('main', 'GP', false); 
  $alldata = getCGIParam('alldata', 'GP', false);

  // What page is this?
  $pagenum = getCGIParam('pagenum', 'GP', 1);
  
   // Return no more than this many hits
  $search_limit = getSearchLimit('pan_gene_limit');
  
  // This many records per page
  $pagesize = getPageSize('pan_gene_pagesize');
  
  // Save settings for this data center
  if (!$alldata && $pagenum == 1) { 
    setSessionVar('pan_gene_term', $raw_term);
    setSessionVar('pan_gene_limit', $search_limit);
    setSessionVar('pan_gene_pagesize', $pagesize);
  }
  
  if ($pagenum > 1) {
    // Subsequent page: result data will be passed in
    $search_matches = unserialize(urldecode($_SESSION['rows']));
    $match_count = count($search_matches['matches']);
    
    // Handle just this page
    $template_file = "../../templates/pan_gene_center/pan_gene-results-page.bau";
    $tmpl = $bauplan->template()->load($template_file);

    $start = ($pagenum-1) * $pagesize;
    $search_match_page = $search_matches;
    $search_match_page['matches'] = array_slice($search_matches['matches'], $start, $pagesize);
    processOnePage($tmpl, $raw_term, $term, $search_match_page, $pagenum, false);

    // Check for more pages, if so, start loading the next page
    $pagecount = floor(($match_count-1)/$pagesize) + 1;
    if ($pagenum < $pagecount) {
      $tmpl->get('nextpage')->replace("" . $pagenum+1);
      $tmpl->get('raw_term')->replace($raw_term);
      $tmpl->get('simple-search-get_next_page')->unmute();
      $tmpl->get('load-next-page')->unmute();
    }
  }//subsequent page
    
  else {
    // First page
    $template_file = '../../templates/pan_gene_center/pan_gene-results.bau';
    $tmpl = $bauplan->template()->load($template_file);
    $tmpl->get('raw_term')->replace($raw_term);
    $tmpl->get('term')->replace($raw_term);

    $div = getCGIParam("div_name", "P", "");
    $tmpl->get("div")->replace($div);
    
    ///// Get search results ////
    // search result list may have already been fetched and saved in $SESSION:  
    $sess_var = "pan_gene_$term"."_$search_limit";
    $search_matches = false;//getCGIParam($sess_var, 'S', false); // comment out for testing
    if (!$search_matches) {
      $search_matches = getIDs($term, $pagesize, $search_limit, 0, $DBConn);  // in pan_gene_results_lib.php!
      if (!$alldata) {
        setSessionVar($sess_var, $search_matches);
      }
    }
logVarDump($search_matches, "Search matches:\n");
    
    if (!$search_matches) {
      // pan-gene not found
      if (preg_match("/Zm\d\d\d\d\d[a-z]+\d\d\d\d\d\d/", $raw_term)) {
        if (isObsolete($raw_term, $DBConn)) {
          $tmpl->get('obsolete-annotation')->unmute();
        } 
        else if (isSingletonPanGene($raw_term, $DBConn)) {
          $tmpl->get('singleton')->unmute();
        }
        else {
          $tmpl->get('no-results')->unmute();
        }
      }//term is gene model name
      else {
        $tmpl->get('no-results')->unmute();
      }
    }//no matches
    
    else if (count($search_matches['matches']) == 1 
           && ($search_matches['match_type'] == 'pan-gene' 
               || $search_matches['match_type'] == 'pan-gene locus'
               || $search_matches['match_type'] == 'pan-gene protein')
           && !$alldata && $main != "initial_true") {
      // Go directly to pan-gene page
      echo "javascript:window.top.document.location = '/pan_gene_center/pan_gene/$raw_term'";
      setSessionVar("pan_gene_term", '');
      exit;
    }//one match
      
    else {
      // Found multiple matches
      $match_count = count($search_matches['matches']);
      if ($match_count < $pagesize) {
        $pagesize = $match_count;
      }
      
      $tmpl->get('pan_gene-simple_download')->unmute();
      $tmpl->get('url_term')->replace($url_term);
    
      // Count and prep pages
      $pages = calcPages($match_count, $pagesize, 'pan_gene-results-page');

      if (count($pages) == 1) {
        $tmpl->get('multi-results')->unmute();
        $tmpl->get('raw_term')->replace($raw_term);
        
        // show simple search result count
        $tmpl->get('results')->unmute();
        $tmpl->get('count')->replace($match_count);
        
        // Fill in the table
        processOnePage($tmpl, $raw_term, $term, $search_matches, 1);
      }
      else {
        // There will be multiple pages of results
        $tmpl->get('pages')->loop($pages);
        $tmpl->get('pagecount')->replace(count($pages));
        $tmpl->get('multi-results-paged')->unmute();
        $tmpl->get('raw_term')->replace($raw_term);
        
        // Enable javascript to load next page
        $tmpl->get('simple-search-get_next_page')->unmute();
        
        // show simple search result count
        $tmpl->get('count')->replace($match_count);
        if ($match_count <= $search_limit) {
          $tmpl->get('multi_results')->unmute();
        }
        else {
          $tmpl->get('limit')->replace($search_limit);
          $tmpl->get('results_limited_simple')->unmute();
        }
        
        $tmpl->get('pan_gene-simple_paged_download')->unmute();
        $tmpl->get('url_term')->replace($url_term);

        // Save serialized match array in session object for subsequent pages
        //   via a hidden input element
        $_SESSION['rows'] = urlencode(serialize($search_matches));

        // Fill in table for first page
        $search_match_page = $search_matches;
        $search_match_page['matches'] = array_slice($search_matches['matches'], 0, $pagesize);
        processOnePage($tmpl, $raw_term, $term, $search_match_page, 1, false);
      }//handle first of multiple pages
      
      $tmpl->get('term')->replace($term);
    }//at least one match found
  }//first (and possibly only) page 
  
  if ($main == "true")
    $tmpl->get('open_shadowbox')->unmute();
    
  // Publish final page
  $bauplan->publish();

  
////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////


function processOnePage($tmpl, $raw_term, $term, $search_matches, $page, $onepage=true) {
  if ($search_matches['match_type'] == 'pan-gene') {
    $html = showPanGeneMatches($tmpl, $raw_term, $term, $search_matches);
  }
  else if ($search_matches['match_type'] == 'pan-gene locus') {
    $tmpl->get('locus-note')->unmute();
    $html = showPanGeneLocusMatches($tmpl, $raw_term, $term, $search_matches);
  }
  else if ($search_matches['match_type'] == 'pan-gene protein') {
    $html = showPanGeneProteinMatches($tmpl, $raw_term, $term, $search_matches);
  }
  $section = ($onepage) ? 'one-page' : 'multi-page';
  $tmpl->get($section)->replace($html);
}//processOnePage


function showPanGeneMatches($tmpl, $raw_term, $term, $search_matches) {
  // Because sections can't be embedded in iterated sections, and because
  //   Bauplan doesn't work with HTML fragments, have to do all the HTML
  //   for a page of search results here. (ick)
  $html = "
    <div id=\"results_box\">
      <table width=\"100%\" cellpadding=0 cellspacing=0>
        <tr>
          <th align=\"left\">Search term</th>
          <th align=\"left\">Exemplar</th>
          <th align=\"left\">Locus</th>
          <th align=\"left\">Analysis</th>
        </tr>";
  foreach ($search_matches['matches'] as $match) {
    $loci = explode(',', trim($match['loci'], '{}'));
    $exemplar = $match['exemplar_gene_model'];
    $html .= "
        <tr>
          <td>
            <a href=\"/pan_gene_center/pan_gene/$raw_term\">$raw_term</a>&nbsp;
          </td>
          <td>
            <a href=\"/pan_gene_center/pan_gene/$exemplar\">$exemplar</a>&nbsp;
          </td>
          <td>" . implode(', ', $loci) .  "&nbsp;</td>
          <td>" . $match['pan_gene_analysis'] . "</td>
        </tr>";
   }//each match
   $html .= "
      </table>
      <br><br>
     </div>";
     
  return $html;
}//showPanGeneMatches


function showPanGeneLocusMatches($tmpl, $raw_term, $term, $search_matches) {
  // Because sections can't be embedded in iterated sections, and because
  //   Bauplan doesn't work with HTML fragments, have to do all the HTML
  //   for a page of search results here. (ick)
  $html = "
    <div id=\"results_box\">
      <table width=\"100%\" cellpadding=0 cellspacing=0>
        <tr>
          <th align=\"left\">Exemplar</th>
          <th width=\"4px\"></th>
          <th align=\"left\">Locus association</th> 
          <th align=\"left\">Analysis</th>
        </tr>";
  foreach ($search_matches['matches'] as $match) {
    $loci = explode(',', trim($match['loci'], '{}'));
    
    $html .= "
        <tr>
          <td valign=\"top\">
            <a href=\"/pan_gene_center/pan_gene/" . $match['exemplar_gene_model'] . "\">" 
            .  $match['exemplar_gene_model'] . "</a>&nbsp;
          </td>
          <td></td>
          <td>" . $match['rationale'] . "</td>
          <td valign=\"top\">" . $match['pan_gene_analysis'] . "</td>
        </tr>";
   }//each match
   $html .= "
      </table>
      <br><br>
     </div>";
     
  return $html;
}//showPanGeneMatches


function showPanGeneProteinMatches($tmpl, $raw_term, $term, $search_matches) {
  // Because sections can't be embedded in iterated sections, and because
  //   Bauplan doesn't work with HTML fragments, have to do all the HTML
  //   for a page of search results here. (ick)
  $html = "
    <div id=\"results_box\">
      <table width=\"100%\" cellpadding=0 cellspacing=0>
        <tr>
          <th align=\"left\">Exemplar</th>
          <th align=\"left\">Locus</th>
          <th align=\"left\">Protein</th>
          <th align=\"left\">Analysis</th>
        </tr>";
  foreach ($search_matches['matches'] as $match) {
    $loci = explode(',', trim($match['loci'], '{}'));
    $locus_links = array();
    foreach ($loci as $l) {
      $locus_links[] = "<a href=\"/gene_center/gene/$l\">$l</a>";
    }
    $html .= "
        <tr>
          <td>
            <a href=\"/pan_gene_center/pan_gene/" . $match['exemplar_gene_model'] . "\">" 
            .  $match['exemplar_gene_model'] . "</a>&nbsp;
          </td>
          <td>" . implode(', ', $locus_links) .  "&nbsp;</td>
          <td>" . $match['protein'] .  "&nbsp;</td>
          <td>" . $match['pan_gene_analysis'] . "</td>
        </tr>";
   }//each match
   $html .= "
      </table>
      <br><br>
     </div>";
     
  return $html;
}//showPanGeneProteinMatches
?>
