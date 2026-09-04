<?php
  include_once('./lib/Bauplan.php');
  include_once('./include/db-api.php');
  include_once('./include/gp_lib.php');
  
  $system = getSystemInfo('mgdb.conf');
  
  $bauplan->title("MaizeGDB BinViewer");
  $bauplan->includeCss('css/data_center.css');
  $bauplan->includeScript('/js/bin_viewer.js');
  
  $bin = getCGIParam('bin', 'G', false);
  $sub = getCGIParam('sub', 'G', false);
  $chrom = getCGIParam('chrom', 'G', false);
  $fullbin = getCGIParam('fullbin', 'G', false);	// RI-854
  
  // Add fullbin parameter to bin viewer page
  if (strlen($fullbin) > 0) {
  	$bin = intval($fullbin);
  	$sub = round(($fullbin - $bin) * 100, 0);
  }
  
  if ($bin >= 1 && $bin <= 10) {
    // load bin viewer sections page based on selected bin
    $mgdb->get('body')->load('templates/tools/bin_view.bau');
    
    $title= "Chromosome $bin, Region $sub ";
    if ($sub < 10)
     $sub = str_pad($sub, 2, "0", STR_PAD_LEFT);
    
    $bin_num = $bin . '.' . $sub;
    $title .= "(Bin $bin_num)";
    
    $mgdb->get('body')->get('title')->replace($title);
    $mgdb->get('body')->get('main_section')->loop(get_section_array($bin_num));
    $mgdb->get('body')->get('nav_section')->loop(get_nav_array($bin_num));
    $mgdb->get('body')->get('navmenu_color')->replace('lite_green');
    $mgdb->get('body')->get('countmenu_color')->replace('lite_blue');

    $tmpl = $mgdb->get('specific-data-view')->load('templates/tools/bin.bau');
    $tmpl->get('bin')->replace($bin);
    $tmpl->get('sub')->replace($sub);
  }
  else if ($chrom > 0 && $chrom < 11) { 
    // load chromosome sections page
    $mgdb->get('body')->load('templates/tools/bin_view.bau');
    
    $title = "Chromosome $chrom";
    $mgdb->get('body')->get('title')->replace($title);
    
    $mgdb->get('body')->get('main_section')->loop(get_chrsection_array($chrom));
    $mgdb->get('body')->get('nav_section')->loop(get_chrnav_array($chrom));
    $mgdb->get('body')->get('navmenu_color')->replace('lite_green');
    $mgdb->get('body')->get('countmenu_color')->replace('lite_blue');

    $tmpl = $mgdb->get('specific-data-view')->load('templates/tools/chrom.bau');
    $tmpl->get('chrom')->replace($chrom);
  }
  else {//load default bin viewer page
    $binviewer = $mgdb->get('body')->load('templates/tools/bin_viewer.bau');
    $binviewer->get('gbrowse_url_v3')->replace($system['GBROWSE_URL_V3']);
  }
  
  
  
////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////

function get_nav_array($bin_num) {
  return array(
    array('nav_name' => 'Genome Browser Links for ' . $bin_num,
          'nav_id0' => 'gb_links'
    ),
    array('nav_name' => 'Genes in Bin ' . $bin_num,
          'nav_id0' => 'genes'
    ),
		array('nav_name' => 'Gene Models in Bin ' . $bin_num,
					'nav_id0' => 'gene_models'
		),
    array('nav_name' => 'Other Loci in Bin ' . $bin_num,
          'nav_id0' => 'other_loci'
    ),
    array('nav_name' => 'High-Density Maps Focusing on Bin ' . $bin_num,
          'nav_id0' => 'hd_maps'
    ),
    array('nav_name' => 'Accession #s in Bin ' . $bin_num,
          'nav_id0' => 'accession'
    ),
    array('nav_name' => 'EST Contigs and SSRs in Bin ' . $bin_num,
          'nav_id0' => 'est_ssr'
    ),
    array('nav_name' => 'BACs in Bin ' . $bin_num,
          'nav_id0' => 'bac'
    ),
  );
}//get_nav_array


function get_section_array($bin_num) {
  return array(
    array('color1' => 'lite_grey',
          'section_name' => 'Genome Browser Links for ' . $bin_num,
          'dom_id1' => 'gb_links',
          'dom_var' => 'gb_links_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Genes in Bin ' . $bin_num,
          'dom_id1' => 'genes',
          'dom_var' => 'genes_cal'
    ),
		array('color1' => 'lite_grey',
					'section_name' => 'Gene Models in Bin ' . $bin_num,
					'dom_id1' => 'gene_models',
					'dom_var' => 'gene_models_cal'
		),
    array('color1' => 'lite_blue',
          'section_name' => 'Other Loci in Bin ' . $bin_num,
          'dom_id1' => 'other_loci',
          'dom_var' => 'other_loci_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'High-Density Maps Focusing on Bin ' . $bin_num,
          'dom_id1' => 'hd_maps',
          'dom_var' => 'hd_maps_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Accession #s in Bin ' . $bin_num,
          'dom_id1' => 'accession',
          'dom_var' => 'accession_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'EST Contigs and SSRs in Bin ' . $bin_num,
          'dom_id1' => 'est_ssr',
          'dom_var' => 'est_ssr_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'BACs in Bin ' . $bin_num,
          'dom_id1' => 'bac',
          'dom_var' => 'bac_cal'
    ),
  );
}//get_section_array


function get_chrnav_array($chrom) {
  return array(
    array('nav_name' => 'Genome Browser Links for Chromosome ' . $chrom,
          'nav_id0' => 'gb_links'
    ),
    array('nav_name' => 'Genes on Chromosome ' . $chrom,
          'nav_id0' => 'genes'
    ),
    array('nav_name' => 'Other Loci on Chromosome ' . $chrom,
          'nav_id0' => 'other_loci'
    ),
    array('nav_name' => 'Maps on Chromosome ' . $chrom,
          'nav_id0' => 'hd_maps'
    ),
    array('nav_name' => 'Accession #s on Chromosome ' . $chrom,
          'nav_id0' => 'accession'
    ),

  );
}//get_nav_array


function get_chrsection_array($chrom) {
  return array(
    array('color1' => 'lite_grey',
          'section_name' => 'Genome Browser Links for Chromosome ' . $chrom,
          'dom_id1' => 'gb_links',
          'dom_var' => 'gb_links_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Genes on Chromosome ' . $chrom,
          'dom_id1' => 'genes',
          'dom_var' => 'genes_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Other Loci on Chromosome ' . $chrom,
          'dom_id1' => 'other_loci',
          'dom_var' => 'other_loci_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Maps of Chromosome ' . $chrom,
          'dom_id1' => 'hd_maps',
          'dom_var' => 'hd_maps_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Accession #s on Chromosome ' . $chrom,
          'dom_id1' => 'accession',
          'dom_var' => 'accession_cal'
    ),
  );
}//get_chrsection_array
 
?>