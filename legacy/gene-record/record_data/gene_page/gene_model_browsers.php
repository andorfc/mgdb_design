<?php
/* file: gene_model_browsers.php
 *
 * purpose: display information about genome browsers for a gene model. Included in 
*           gene_data.php
 *
 * history:
 *   06/16/20  eksc  created from gene_data.php
 *
 *
 *  >>>> THIS MAY NO LONGER BE USED? <<<<
 *
 */

function showGenomeBrowsers($bauplan, $id, $arrRecord, $DBConn) {
  global $system;

  // Get GBrowse URLs
  $browse_url       = getBrowseLink($arrRecord['assembly_version'], $DBConn);
  $gbrowse_img_url  = getGBrowseImgLink($arrRecord['assembly_version'], $DBConn);
  $other_urls       = getOtherBrowsers($arrRecord['assembly_version'], $DBConn);

  if (!stristr($arrRecord['assembly_version'], 'refgen')) {
    $tmpl = $bauplan->template()->load('../templates/gene_center/gene_genomebrowser_sections.bau');
    $transcript_info = getTranscriptData($arrRecord['gene_id'], $arrRecord['version'], true, $DBConn);
    $transcript_info['browse_url']      = $browse_url;
    $transcript_info['gbrowse_img_url'] = $gbrowse_img_url;
    $transcript_info['other_urls']      = $other_urls;
    showBrowser($tmpl, $transcript_info, $arrRecord, $DBConn);
  }
  // legacy hardcoding...
  else {
    $tmpl = $bauplan->template()->load('../templates/gene_center/gene_genomebrowser_sections-legacy.bau');
    
    $v3_transcript_info = getTranscriptData($arrRecord['geme_id'], '5b+', true, $DBConn);  // true = canonical
    $v3_transcript_info['browse_url']     = $browse_url;
    $v3_transcript_info['gbrowse_img_url'] = $gbrowse_img_url;
    $v3_transcript_info['other_urls']      = $other_urls;
    $tmpl->get('display-v3')->replace('inline');
    showBrowser($tmpl, $v3_transcript_info, $arrRecord, $DBConn);

// NOTE THAT V1 AND V2 ARE NO LONGER SUPPORTED
    $v2_arrRecord = getGeneModel($id, $DBConn, $system['gm_set_v2']);
    $v2_transcript_info = getTranscriptData($arrRecord['gene_id'], '5b', true, $DBConn);  // true = canonical
    
    $v2_transcript_info['browse_url']      = getBrowseLink('B73 RefGen_v2', $DBConn);
    $v2_transcript_info['gbrowse_img_url'] = getGBrowseImgLink('B73 RefGen_v2', $DBConn);
    $v2_transcript_info['other_urls']      = $other_urls;
    $tmpl->get('display-v2')->replace('none');
    showBrowser($tmpl, $v2_transcript_info, $v2_arrRecord, $DBConn);

    $v1_arrRecord = getGeneModel($id, $DBConn, $system['gm_set_v1']);
    $v1_transcript_info = getTranscriptData($arrRecord['gene_id'], '4a', true, $DBConn);  // true = canonical
                                              
    $v1_transcript_info['browse_url']      = getBrowseLink('B73 RefGen_v1', $DBConn);
    $v1_transcript_info['gbrowse_img_url'] = getGBrowseImgLink('B73 RefGen_v1', $DBConn);
    $v1_transcript_info['other_urls']      = $other_urls;
    $tmpl->get('display-v1')->replace('none');
    showBrowser($tmpl, $v1_transcript_info, $v1_arrRecord, $DBConn);
  }
  
  $tmpl->get('genome-browsers')->unmute();
}//showGenomeBrowsers



//////////////////////////////////////////////////////////////////////////////////////////
// helper functions
//////////////////////////////////////////////////////////////////////////////////////////

function getOtherBrowsers($assembly_name, $DBConn) {
  $sql = "
    SELECT ap.value FROM chado.sequence_metadata sm 
      INNER JOIN chado.analysis a ON a.analysis_id=sm.analysis_id
      INNER JOIN chado.analysisprop ap ON ap.analysis_id=a.analysis_id
        AND ap.type_id=(SELECT cvterm_id FROM chado.cvterm 
                        WHERE name='other_browser_URLs')
    WHERE a.name='$assembly_name'";
  $sth = make_query($DBConn, $sql);
  if ($row=retrieve_row($sth)) {
    return $row['value'];
  }
  
  else {
    // Get whatever can be found from the config file, hardcode some...
    $metadata = array();
    switch ($assembly_name) {
      case 'B73 RefGen_v3':
        $other_browsers = implode(',',array(
          'http://genomaize.org/cgi-bin/hgTracks?position=',
          'http://www.plantgdb.org/ZmGDB/cgi-bin/findRecord.pl?id=',
// PLEXdb website vanished.
//          'http://www.plexdb.org/modules/PD_browse/experiment_browser.php?section=Expression&probeset_view=expressiongraph&array=nimbMaize&probeset=',
        ));
        break;
      case 'RefGen_v2':
        $other_browsers = false;
        break;
      case 'RefGen_v1':
        $other_browsers = false;
        break;
      default:
        $other_browsers = false;
        break;      
    }//switch
    
    return $other_browsers;
  }
}//getOtherBrowsers


function showBrowser($tmpl, $transcript_info, $arrRecord, $DBConn) {
  global $system;

  // Start a new template to hold the details
  $bauplan = new Bauplan('');
  $sub_tmpl = $bauplan->template()->load('../templates/gene_center/gene_genomebrowser_details.bau');

  if (!$arrRecord) {
    $sub_tmpl->get("not-on-browser")->unmute();
    return;
  }

  // Get information about this gene model set
  $gm_info = getGeneModelInfo($arrRecord, $DBConn);

  // Get GBrowse URLs and coordinates
  $browse_url     = $transcript_info['browse_url'];
  $browse_img_url = $transcript_info['gbrowse_img_url'];;
  $gm_chr          = ($arrRecord['chr']) 
                   ? $arrRecord['chr'] : $arrRecord['transcript_chr'];
  $gm_min          = ($arrRecord['gm_start']) 
                   ? $arrRecord['gm_start'] : $transcript_info[0]['gm_min'];
  $gm_max          = ($arrRecord['gm_end']) 
                   ? $arrRecord['gm_end'] : $transcript_info[0]['gm_max'];
  $transcript      = (isset($transcript_info[0]['transcript']))
                   ? $transcript_info[0]['transcript'] : '';
  $gene_name       = $arrRecord['gene_id'];
  
  //Zoom out a bit for the jbrowse URLs
  $gm_padding = 1500;
  $gm_min_padded = ($gm_min > $gm_padding) ? $gm_min - $gm_padding : 1;
  $gm_max_padded = $gm_max + $gm_padding;

  if (!$browse_url) {
    // No browser for this assembly
    $sub_tmpl->get('annotation')->replace($arrRecord['version']);
    $sub_tmpl->get('assembly_version')->replace($arrRecord['assembly_version']);
    $sub_tmpl->get('no-browser')->unmute();
  }

  else if (stristr($browse_url, 'gbrowse') !== false) {
    // Check GBrowse image
    $browse_img = getGBrowseImage($browse_img_url, $gene_name, $transcript);
    if (!$browse_img || preg_match("/Error/", $browse_img)) {
      // Gene model not found on browser
      $sub_tmpl->get('gm_id')->replace($gene_name);
      $sub_tmpl->get('assembly_name')->replace($gm_info['assembly_version']);
      $sub_tmpl->get('not-on-browser')->unmute();
    }
    else {
      // Display GBrowse image and link
      $link = "$browse_url/?name=$gene_name;h_feat=$gene_name";
      $sub_tmpl->get('tr_id')->replace($transcript);
      $sub_tmpl->get('gbrowse_type')->replace($gm_info['gbrowse_type']);
      $sub_tmpl->get('gbrowse_img_url')->replace($browse_img_url);
      $sub_tmpl->get('link')->replace($link);
      $sub_tmpl->get('browser')->unmute();
    }
  }//gbrowse URL
  
  else {
    // Make link for JBrowse
    if (strstr($browse_url, 'data=') === false) {
      $link = "$browse_url?loc=$gm_chr:$gm_min_padded..$gm_max_padded&tracks=gene_models_official,gene_models_v4_json,gene_models_v3_json";
    }
    else {
      $link = "$browse_url&loc=$gm_chr:$gm_min_padded..$gm_max_padded&tracks=gene_models_official,gwas_snps";
    }

    $sub_tmpl->get('link')->replace($link);
    $sub_tmpl->get('gm_chr')->replace($gm_chr);
    $sub_tmpl->get('ngm_start')->replace($gm_min);
    $sub_tmpl->get('ngm_end')->replace($gm_max);
    $sub_tmpl->get('browser-link-only')->unmute();
  }//JBrowse URL
  
  if ($browse_url) {
    $sub_tmpl->get('gene_set_source')->replace($gm_info['gene_set_source']);
    $sub_tmpl->get('gm_id')->replace($gene_name);
    $sub_tmpl->get('tr_id')->replace($transcript);
    $sub_tmpl->get('gm_chr')->replace($gm_chr);
    $sub_tmpl->get('ngm_start')->replace($gm_min);
    $sub_tmpl->get('ngm_end')->replace($gm_max);
    if (isset($transcript_info[0])) {
      $sub_tmpl->get('genbank_id')->replace($transcript_info[0]['transcript_acc']);
      $sub_tmpl->get('gm_min')->replace($transcript_info[0]['transcript_start']);
      $sub_tmpl->get('gm_max')->replace($transcript_info[0]['transcript_end']);
    }
    
    if ($transcript_info['other_urls']) {
      $other_urls = explode(',', $transcript_info['other_urls']);
      foreach ($other_urls as $url) {
        if (stristr($url, 'maizemine')) {  
          $sub_tmpl->get('maizemine-browser-url')->replace($url);
          $sub_tmpl->get('maizemine-browser')->unmute();
        }
        if (stristr($url, 'genomaize')) {
          $sub_tmpl->get('genomaize-browser-url')->replace($url);
          $sub_tmpl->get('genomaize-browser')->unmute();
        }
        if (stristr($url, 'plantgdb')) {
          $sub_tmpl->get('plantgdb-browser-url')->replace($url);
          $sub_tmpl->get('plantgdb-browser')->unmute();
        }
        if (stristr($url, 'gramene')) {
          $sub_tmpl->get('gramene-browser-url')->replace($url);
          $sub_tmpl->get('gramene-browser')->unmute();
        }
/* PLEXdb website gone -2019
        if (stristr($url, 'plexdb')) {
          $sub_tmpl->get('plexdb-browser-url')->replace($url);
          $sub_tmpl->get('plexdb-browser')->unmute();
        }
*/
      }//each other URL
      $sub_tmpl->get('other-browsers')->unmute();
    }//there are additional URLs
  }

  // Set the details content for this assembly version
  $sub_tmpl->get('genome-browser-details')->unmute();
  $html = $sub_tmpl->getHTML();
  
  // More special-casing for pre-v4 assemblies
  if (preg_match("/RefGen/", $arrRecord['assembly_version'])) {
    $contents_section = 'contents-' 
                      . strtolower(
                          preg_replace("/.*_(.*)/", "$1", 
                                       $arrRecord['assembly_version'])
    );
  }
  
  else {
    $contents_section = 'genomebrowser-contents';
  }
  
  // load details into browser section template
  $tmpl->get($contents_section)->replace($html);
}//showBrowser


?>
