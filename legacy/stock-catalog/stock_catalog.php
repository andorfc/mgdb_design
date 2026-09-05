<?PHP

 /* file: stock_catalog.php
 *
 * purpose: Show various stock catalogs
 *
 * history:
 *    5/14/2014      developed by Carson Andorf
 *  
 */
 
 
  include_once('./lib/Bauplan.php');
  include_once('./include/db-api.php');
  include_once('./include/gp_lib.php');
  
  $system = getSystemInfo('mgdb.conf');
logVarDump($params, "stock_catalog.php params:\n");
  
  $bauplan->includeCss('css/data_center.css');
  
  $year = getCGIParam('year', 'G', date('Y'));
logMessage("Year: $year");

//  if (array_key_exists(2, $params)) {
//    if ($params[2] > 0) {
//     $year = date('Y');
//    }
//  }  
  
  if (array_key_exists(2, $params)) { 
    if ($params[2] == "RIL") {
      $mgdb->get('body')->load('templates/tools/stock_view_rip.bau');
      $title= "Maize Genetics Cooperation Stock Center Catalog of Intermated B73 x Mo17 Recombinant Inbred Populations";
      $bauplan->title($title);
      $mgdb->get('body')->get('title')->replace($title);
      $mgdb->get('body')->get('main_section')->loop(get_section_array_rip());
      $mgdb->get('body')->get('nav_section')->loop(get_nav_array_rip());
      $mgdb->get('body')->get('navmenu_color')->replace('lite_green');
      $mgdb->get('body')->get('countmenu_color')->replace('lite_blue');
  
      $tmpl = $mgdb->get('specific-data-view')->load('templates/tools/stocks_rip.bau');
     }//RIL
     
     else if ($params[2] == "translocations") {
        $mgdb->get('body')->load('templates/tools/stock_view_tran.bau');
        $title= "Maize Genetics Cooperation Stock Center Catalog of Reciprocal Translocation Stocks (Comprehensive List)";
        $bauplan->title($title);
        $mgdb->get('body')->get('title')->replace($title);
        $mgdb->get('body')->get('main_section')->loop(get_section_array_tran());
        $mgdb->get('body')->get('nav_section')->loop(get_nav_array_tran());
        $mgdb->get('body')->get('navmenu_color')->replace('lite_green');
        $mgdb->get('body')->get('countmenu_color')->replace('lite_blue');
    
        $tmpl = $mgdb->get('specific-data-view')->load('templates/tools/stocks_tran.bau');
     }//translocations
     
     else if ($params[2] == "phenotype") {
        $mgdb->get('body')->load('templates/tools/stock_view_phen.bau');
        $title= "Maize Genetics Cooperation Stock Center Catalog of Stocks Characterized Only by Phenotype";
        $bauplan->title($title);
        $mgdb->get('body')->get('title')->replace($title);
        $mgdb->get('body')->get('main_section')->loop(get_section_array_phen());
        $mgdb->get('body')->get('nav_section')->loop(get_nav_array_phen());
        $mgdb->get('body')->get('navmenu_color')->replace('lite_green');
        $mgdb->get('body')->get('countmenu_color')->replace('lite_blue');
    
        $tmpl = $mgdb->get('specific-data-view')->load('templates/tools/stocks_phen.bau');
     }//phenotype
     
     else if ($params[2] == "chromdb") {
        $mgdb->get('body')->load('templates/tools/stock_view_chrom.bau');
        $title= "Maize Genetics Cooperation Stock Center Catalog of <a href='http://www.chromdb.org/'>ChromDB</a> Stocks";
        $bauplan->title($title);
        $mgdb->get('body')->get('title')->replace($title);
        $mgdb->get('body')->get('main_section')->loop(get_section_array_chrom());
        $mgdb->get('body')->get('nav_section')->loop(get_nav_array_chrom());
        $mgdb->get('body')->get('navmenu_color')->replace('lite_green');
        $mgdb->get('body')->get('countmenu_color')->replace('lite_blue');
    
        $tmpl = $mgdb->get('specific-data-view')->load('templates/tools/stocks_chrom.bau');
     }//chromdb
     
     else if ($year > 0 || $params[2] == "new") {
        $year = date('Y');
        $mgdb->get('body')->load('templates/tools/stock_view.bau');
        $title= "Maize Genetics Cooperation Stock Center Catalog of Stocks - New Additions";
        $bauplan->title($title);
        $mgdb->get('body')->get('title')->replace($title);
        $mgdb->get('body')->get('main_section')->loop(get_section_array_latest());
        $mgdb->get('body')->get('nav_section')->loop(get_nav_array_latest());
        $mgdb->get('body')->get('main_link')->replace('<b><a href="/stock_catalog">Main Stock Catalog</a></b>');
        
        $mgdb->get('body')->get('navmenu_color')->replace('lite_green');
        $mgdb->get('body')->get('countmenu_color')->replace('lite_blue');
        
        $tmpl = $mgdb->get('specific-data-view')->load('templates/tools/stocks.bau');
        $mgdb->get('specific-data-view')->get('bin')->get('year')->replace($year);
    }//new
    
    else {
        $mgdb->get('body')->load('templates/tools/stock_view.bau');
        $mgdb->get('body')->get('main_section')->loop(get_section_array());
        $mgdb->get('body')->get('nav_section')->loop(get_nav_array());
        $title= "Maize Genetics Cooperation Stock Center Catalog of Stocks";
        $bauplan->title($title);
        $mgdb->get('body')->get('title')->replace($title);
        $mgdb->get('body')->get('main_link')->replace("<b><a href='/stock_catalog/new'>New Additions For $year</a></b>");
        
        $mgdb->get('body')->get('navmenu_color')->replace('lite_green');
        $mgdb->get('body')->get('countmenu_color')->replace('lite_blue');
        
        $tmpl = $mgdb->get('specific-data-view')->load('templates/tools/stocks.bau');
        $mgdb->get('specific-data-view')->get('bin')->get('year')->replace($year);    
    }//stocks
  }
  
  else {  
    $mgdb->get('body')->load('templates/tools/stock_view.bau');
  
    if ($year > 0) {
      $title= "Maize Genetics Cooperation Stock Center Catalog of Stocks - New Additions";
      $bauplan->title($title);
      $mgdb->get('body')->get('title')->replace($title);
      $mgdb->get('body')->get('main_section')->loop(get_section_array_latest());
      $mgdb->get('body')->get('nav_section')->loop(get_nav_array_latest());
      $mgdb->get('body')->get('main_link')->replace('<b><a href="/stock_catalog">Main Stock Catalog</a></b>');
    } 
    else {
      $cur_year = date('Y');
      $mgdb->get('body')->get('main_section')->loop(get_section_array());
      $mgdb->get('body')->get('nav_section')->loop(get_nav_array());
      $title= "Maize Genetics Cooperation Stock Center Catalog of Stocks";
      $bauplan->title($title);
      $mgdb->get('body')->get('title')->replace($title);
      $mgdb->get('body')->get('main_link')->replace("<b><a href='/stock_catalog/new'>New Additions For $cur_year</a></b>");
    }
    
    $mgdb->get('body')->get('navmenu_color')->replace('lite_green');
    $mgdb->get('body')->get('countmenu_color')->replace('lite_blue');
  
    $tmpl = $mgdb->get('specific-data-view')->load('templates/tools/stocks.bau');
    $mgdb->get('specific-data-view')->get('bin')->get('year')->replace($year);
  }
  
  
  
  /////////////////////////////////////////////////////////////////////////////
  /////////////////////////////////////////////////////////////////////////////
  

function get_nav_array() {
  return array(
    array('nav_name' => 'Chromosome 1 Markers',
            'nav_id0' => 'section_chr1'
      ),
    array('nav_name' => 'Chromosome 2 Markers',
            'nav_id0' => 'section_chr2'
      ),
    array('nav_name' => 'Chromosome 3 Markers',
            'nav_id0' => 'section_chr3'
      ),
    array('nav_name' => 'Chromosome 4 Markers',
            'nav_id0' => 'section_chr4'
      ),
    array('nav_name' => 'Chromosome 5 Markers',
            'nav_id0' => 'section_chr5'
      ),
    array('nav_name' => 'Chromosome 6 Markers',
            'nav_id0' => 'section_chr6'
      ),
    array('nav_name' => 'Chromosome 7 Markers',
            'nav_id0' => 'section_chr7'
      ),
    array('nav_name' => 'Chromosome 8 Markers',
            'nav_id0' => 'section_chr8'
      ),
    array('nav_name' => 'Chromosome 9 Markers',
            'nav_id0' => 'section_chr9'
      ),
    array('nav_name' => 'Chromosome 10 Markers',
            'nav_id0' => 'section_chr10'
      ),
    array('nav_name' => 'Unplaced Genes',
            'nav_id0' => 'section_unp'
      ),
    array('nav_name' => 'Multiple Genes',
            'nav_id0' => 'section_mul'
      ),
    array('nav_name' => 'Rare Isozyme',
            'nav_id0' => 'section_rar'
      ),
    array('nav_name' => 'B-Chromosome',
            'nav_id0' => 'section_b'
      ),
    array('nav_name' => 'Alien Addition',
            'nav_id0' => 'section_alien'
      ),
    array('nav_name' => 'Trisomic',
            'nav_id0' => 'section_tri'
      ),
    array('nav_name' => 'Tetraploid',
            'nav_id0' => 'section_tet'
      ),
    array('nav_name' => 'Cytoplasmic-Sterile / Restorer',
            'nav_id0' => 'section_csr'
      ),
    array('nav_name' => 'Cytoplasmic Trait',
            'nav_id0' => 'section_cyt'
      ),
    array('nav_name' => 'Toolkit',
            'nav_id0' => 'section_tool'
      ),
    array('nav_name' => 'B-A Translocations (Basic Set)',
            'nav_id0' => 'section_batb'
      ),
    array('nav_name' => 'B-A Translocations (Others)',
            'nav_id0' => 'section_bato'
      ),
    array('nav_name' => 'Inversion',
            'nav_id0' => 'section_inv'
      ),
    array('nav_name' => 'Near Isogenic Lines',
            'nav_id0' => 'section_nil'
      ),
    array('nav_name' => 'Reciprocal Translocations (wx1 and Wx1 marked)',
            'nav_id0' => 'section_wx1'
      ),
  );
}//get_nav_array


function get_section_array() {
  return array(
    array('color1' => 'lite_blue',
          'section_name' => 'Chromosome 1 Markers',
          'dom_id1' => 'chr1',
          'dom_var' => 'chr1_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Chromosome 2 Markers',
          'dom_id1' => 'chr2',
          'dom_var' => 'chr2_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Chromosome 3 Markers',
          'dom_id1' => 'chr3',
          'dom_var' => 'chr3_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Chromosome 4 Markers',
          'dom_id1' => 'chr4',
          'dom_var' => 'chr4_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Chromosome 5 Markers',
          'dom_id1' => 'chr5',
          'dom_var' => 'chr5_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Chromosome 6 Markers',
          'dom_id1' => 'chr6',
          'dom_var' => 'chr6_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Chromosome 7 Markers',
          'dom_id1' => 'chr7',
          'dom_var' => 'chr7_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Chromosome 8 Markers',
          'dom_id1' => 'chr8',
          'dom_var' => 'chr8_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Chromosome 9 Markers',
          'dom_id1' => 'chr9',
          'dom_var' => 'chr9_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Chromosome 10 Markers',
          'dom_id1' => 'chr10',
          'dom_var' => 'chr10_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Unplaced Genes',
          'dom_id1' => 'unp',
          'dom_var' => 'unp_cal'
    ),
  array('color1' => 'lite_grey',
          'section_name' => 'Multiple Genes',
          'dom_id1' => 'mul',
          'dom_var' => 'mul_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Rare Isozyme',
          'dom_id1' => 'rar',
          'dom_var' => 'rar_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'B-Chromosome',
          'dom_id1' => 'b',
          'dom_var' => 'b_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Alien Addition',
          'dom_id1' => 'alien',
          'dom_var' => 'alien_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Trisomic',
          'dom_id1' => 'tri',
          'dom_var' => 'tri_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Tetraploid',
          'dom_id1' => 'tet',
          'dom_var' => 'tet_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Cytoplasmic-Sterile / Restorer',
          'dom_id1' => 'csr',
          'dom_var' => 'csr_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Cytoplasmic Trait',
          'dom_id1' => 'cyt',
          'dom_var' => 'cyt_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Toolkit',
          'dom_id1' => 'tool',
          'dom_var' => 'tool_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'B-A Translocations (Basic Set)',
          'dom_id1' => 'batb',
          'dom_var' => 'batb_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'B-A Translocations (Others)',
          'dom_id1' => 'bato',
          'dom_var' => 'bato_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Inversion',
          'dom_id1' => 'inv',
          'dom_var' => 'inv_cal'
    ),
     array('color1' => 'lite_grey',
          'section_name' => 'Near Isogenic Lines',
          'dom_id1' => 'nil',
          'dom_var' => 'nil_cal'
    ),
   array('color1' => 'lite_blue',
          'section_name' => 'Reciprocal Translocations (wx1 and Wx1 marked)',
          'dom_id1' => 'wx1',
          'dom_var' => 'wx1_cal'
    ),
  );
}//get_section_array


function get_nav_array_latest() {
  return array(
  array('nav_name' => 'Chromosome 1 Markers',
          'nav_id0' => 'chr1'
    ),
  array('nav_name' => 'Chromosome 2 Markers',
          'nav_id0' => 'chr2'
    ),
  array('nav_name' => 'Chromosome 3 Markers',
          'nav_id0' => 'chr3'
    ),
  array('nav_name' => 'Chromosome 4 Markers',
          'nav_id0' => 'chr4'
    ),
  array('nav_name' => 'Chromosome 5 Markers',
          'nav_id0' => 'chr5'
    ),
  array('nav_name' => 'Chromosome 6 Markers',
          'nav_id0' => 'chr6'
    ),
  array('nav_name' => 'Chromosome 7 Markers',
          'nav_id0' => 'chr7'
    ),
  array('nav_name' => 'Chromosome 8 Markers',
          'nav_id0' => 'chr8'
    ),
  array('nav_name' => 'Chromosome 9 Markers',
          'nav_id0' => 'chr9'
    ),
  array('nav_name' => 'Chromosome 10 Markers',
          'nav_id0' => 'chr10'
    ),
  array('nav_name' => 'Unplaced Genes',
          'nav_id0' => 'unp'
    ),
  array('nav_name' => 'Multiple Genes',
          'nav_id0' => 'mul'
    ),
  array('nav_name' => 'Rare Isozyme',
          'nav_id0' => 'rar'
    ),
  array('nav_name' => 'B-Chromosome',
          'nav_id0' => 'b'
    ),
  array('nav_name' => 'Alien Addition',
          'nav_id0' => 'alien'
    ),
  array('nav_name' => 'Trisomic',
          'nav_id0' => 'tri'
    ),
  array('nav_name' => 'Tetraploid',
          'nav_id0' => 'tet'
    ),
  array('nav_name' => 'Cytoplasmic-Sterile / Restorer',
          'nav_id0' => 'csr'
    ),
  array('nav_name' => 'Cytoplasmic Trait',
          'nav_id0' => 'cyt'
    ),
  array('nav_name' => 'Toolkit',
          'nav_id0' => 'tool'
    ),
  array('nav_name' => 'B-A Translocations (Basic Set)',
          'nav_id0' => 'batb'
    ),
  array('nav_name' => 'B-A Translocations (Others)',
          'nav_id0' => 'bato'
    ),
  array('nav_name' => 'Inversion',
          'nav_id0' => 'inv'
    ),
  array('nav_name' => 'Near Isogenic Lines',
          'nav_id0' => 'nil'
    ),
  array('nav_name' => 'Reciprocal Translocations (wx1 and Wx1 marked)',
          'nav_id0' => 'wx1'
    ),
  array('nav_name' => 'Recombinant Inbred',
          'nav_id0' => 'rils'
    ),
  array('nav_name' => 'Reciprocal Translocation Stocks (Comprehensive List)',
          'nav_id0' => 'rts'
    ),
  array('nav_name' => 'Stocks Characterized Only by Phenotype',
          'nav_id0' => 'pheno'
    ),
  );
}//get_nav_array_latest


function get_section_array_latest() {
  return array(
    array('color1' => 'lite_blue',
          'section_name' => 'Chromosome 1 Markers',
          'dom_id1' => 'chr1',
          'dom_var' => 'chr1_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Chromosome 2 Markers',
          'dom_id1' => 'chr2',
          'dom_var' => 'chr2_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Chromosome 3 Markers',
          'dom_id1' => 'chr3',
          'dom_var' => 'chr3_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Chromosome 4 Markers',
          'dom_id1' => 'chr4',
          'dom_var' => 'chr4_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Chromosome 5 Markers',
          'dom_id1' => 'chr5',
          'dom_var' => 'chr5_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Chromosome 6 Markers',
          'dom_id1' => 'chr6',
          'dom_var' => 'chr6_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Chromosome 7 Markers',
          'dom_id1' => 'chr7',
          'dom_var' => 'chr7_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Chromosome 8 Markers',
          'dom_id1' => 'chr8',
          'dom_var' => 'chr8_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Chromosome 9 Markers',
          'dom_id1' => 'chr9',
          'dom_var' => 'chr9_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Chromosome 10 Markers',
          'dom_id1' => 'chr10',
          'dom_var' => 'chr10_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Unplaced Genes',
          'dom_id1' => 'unp',
          'dom_var' => 'unp_cal'
    ),
  array('color1' => 'lite_grey',
          'section_name' => 'Multiple Genes',
          'dom_id1' => 'mul',
          'dom_var' => 'mul_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Rare Isozyme',
          'dom_id1' => 'rar',
          'dom_var' => 'rar_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'B-Chromosome',
          'dom_id1' => 'b',
          'dom_var' => 'b_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Alien Addition',
          'dom_id1' => 'alien',
          'dom_var' => 'alien_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Trisomic',
          'dom_id1' => 'tri',
          'dom_var' => 'tri_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Tetraploid',
          'dom_id1' => 'tet',
          'dom_var' => 'tet_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Cytoplasmic-Sterile / Restorer',
          'dom_id1' => 'csr',
          'dom_var' => 'csr_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Cytoplasmic Trait',
          'dom_id1' => 'cyt',
          'dom_var' => 'cyt_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Toolkit',
          'dom_id1' => 'tool',
          'dom_var' => 'tool_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'B-A Translocations (Basic Set)',
          'dom_id1' => 'batb',
          'dom_var' => 'batb_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'B-A Translocations (Others)',
          'dom_id1' => 'bato',
          'dom_var' => 'bato_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Inversion',
          'dom_id1' => 'inv',
          'dom_var' => 'inv_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Near Isogenic Lines',
          'dom_id1' => 'nil',
          'dom_var' => 'nil_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Reciprocal Translocations (wx1 and Wx1 marked)',
          'dom_id1' => 'wx1',
          'dom_var' => 'wx1_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Recombinant Inbred',
          'dom_id1' => 'rils',
          'dom_var' => 'rils_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Reciprocal Translocation Stocks (Comprehensive List)',
          'dom_id1' => 'rts',
          'dom_var' => 'rts_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Stocks Characterized Only by Phenotype',
          'dom_id1' => 'pheno',
          'dom_var' => 'pheno_cal'
    ),

  );
}//get_section_array_latest


function get_nav_array_rip() {
  return array(
  array('nav_name' => 'Main Set of 94 IBM RILs',
          'nav_id0' => 'section_rip_main'
    ),
  array('nav_name' => 'Inbred Parents of IBM RILs',
          'nav_id0' => 'section_rip_parent'
    ),
  array('nav_name' => 'Other IBM RILs',
          'nav_id0' => 'section_rip_other'
    ),
  
  );
}//get_nav_array_rip


function get_section_array_rip() {
  return array(
    array('color1' => 'lite_blue',
          'section_name' => 'Main Set of 94 IBM RILs',
          'dom_id1' => 'rip_main',
          'dom_var' => 'rip_main_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Inbred Parents of IBM RILs',
          'dom_id1' => 'rip_parent',
          'dom_var' => 'rip_parent_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Other IBM RILs',
          'dom_id1' => 'rip_other',
          'dom_var' => 'rip_other_cal'
    ),

  );
}//get_section_array_rip


function get_nav_array_tran() {
  return array(
  array('nav_name' => 'Reciprocal Translocation Stocks',
          'nav_id0' => 'tran_main'
    ),
  
  );
}//get_nav_array_tran


function get_section_array_tran() {
  return array(
    array('color1' => 'lite_blue',
          'section_name' => 'Reciprocal Translocation Stocks',
          'dom_id1' => 'tran_main',
          'dom_var' => 'tran_main_cal'
    ),
  
  );
}//get_section_array_tran


function get_nav_array_phen() {
  return array(
  array('nav_name' => 'Stocks Characterized Only by Phenotype',
          'nav_id0' => 'phen_main'
    ),

  );
}//get_nav_array_phen


function get_section_array_phen() {
  return array(
    array('color1' => 'lite_blue',
          'section_name' => 'Stocks Characterized Only by Phenotype',
          'dom_id1' => 'phen_main',
          'dom_var' => 'phen_main_cal'
    ),


  );
}//get_section_array_phen


function get_nav_array_chrom() {
  return array(
  array('nav_name' => 'ChromDB Stocks',
          'nav_id0' => 'chrom_main'
    ),
  );
}//get_nav_array_chrom


function get_section_array_chrom() {
  return array(
    array('color1' => 'lite_blue',
          'section_name' => 'ChromDB Stocks',
          'dom_id1' => 'chrom_main',
          'dom_var' => 'chrom_main_cal'
    ),
  );
}//get_section_array_chrom
?>
