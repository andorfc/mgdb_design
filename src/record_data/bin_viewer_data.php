<?PHP
/* file: bin_viewer_data.php
 *
 * purpose: display the various sections of a bin; called via Ajax
 *
 * history:
 *  12/12/12  jportwood  created
 */

  include_once('../lib/Bauplan.php');
  include_once("../include/db-api.php");
  include_once("../include/api_tools.php");
  include_once('../include/gp_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $bin   = getCGIParam("bin", 'G', false);
  $sub   = getCGIParam("sub", 'G', false);
  $type = getCGIParam("type", 'G', false);

  logMessage("bin_viewer_data.php: bin=$bin, sub=$sub");
  
  if (!$type) {
    reportError("No section type given to bin_viewer_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  /* The sections template loads bin_viewer-maps.bau, two HTML image maps of
     220 <area> tags and 21 KB. Only the 'top' section draws them, but this
     endpoint loads the template whole for every section, so all eight sections
     of a bin page carried them -- 172 KB a page for markup nothing read.
     nomaps=1 selects a copy of the template with that one load removed; the
     previous Bin Viewer does not pass it and is unaffected. */
  $nomaps = getCGIParam("nomaps", 'G', false);
  $sections_template = ($nomaps && $type != 'top')
                     ? '../templates/tools/bin_viewer-sections-nomaps.bau'
                     : '../templates/tools/bin_viewer-sections.bau';
  $tmpl = $bauplan->template()->load($sections_template);
  
  $DBConn = connect_to_database();

  // Clean up input typed by user
  $bin = validate_input($DBConn, $bin);
  $sub = validate_input($DBConn, $sub);   

  switch ($type) {
    case 'top':
      show_top($tmpl, $DBConn);
      break;
    case 'gb_links':
      showGB($tmpl, $DBConn);
      break;
    case 'genes':
      showGenes($tmpl, $DBConn);
      break;
    case 'gene_models':
      showGeneModels($tmpl, $DBConn);
      break;
    case 'other_loci':
      showOtherLoci($tmpl, $DBConn);
      break;
    case 'hd_maps':
      showHDMaps($tmpl, $DBConn);
      break;
    case 'accession':
      showAccession($tmpl, $DBConn);
      break;
    case 'est_ssr':
      showESTSSR($tmpl, $DBConn);
      break;
    case 'bac':
      showBAC($tmpl, $DBConn);
      break;
  }

  // Past GBrowse instances
  /* Bauplan's Nary::get() throws on a missing identifier, and several of these
     are declared inside bin_viewer-maps.bau. Under nomaps=1 that partial is not
     loaded, so only the ones the section itself declares are set. */
  $gbrowse_vars = array(
    'gbrowse_url_v3'      => 'GBROWSE_URL_V3',
    'gbrowse_img_url_v3'  => 'GBROWSE_IMG_URL_V3',
    'gbrowse_url_v2'      => 'GBROWSE_URL_V2',
    'gbrowse_img_url_v2'  => 'GBROWSE_IMG_URL_V2',
    'gbrowse_url_v1'      => 'GBROWSE_URL_V1',
    'gbrowse_img_url_v1'  => 'GBROWSE_IMG_URL_V1',
    'gbrowse_url_bac'     => 'GBROWSE_URL_BAC',
    'gbrowse_img_url_bac' => 'GBROWSE_IMG_URL_BAC',
  );
  foreach ($gbrowse_vars as $name => $key) {
    if (!$nomaps || $tmpl->has($name)) {
      $tmpl->get($name)->replace($system[$key]);
    }
  }
  
  $bauplan->publish();
  
  
////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////

  function show_top($tmpl, $DBConn) {   
    $bin = getCGIParam("bin", 'G', false);
    $sub = getCGIParam("sub", 'G', false);
    
    if ($sub < 10)
      $sub = str_pad($sub, 2, "0", STR_PAD_LEFT);
    $bin_num = $bin . '.' . $sub;
    
    $bin_barred = str_replace('.', '_', $bin_num);
    
    $tmpl->get('bin')->replace($bin);
    $tmpl->get('sub')->replace($sub);
    $tmpl->get('bin_num')->replace($bin_num);
    $tmpl->get('bin_underbarred')->replace($bin_barred);

    $tmpl->get('top')->unmute();

  }//showTop
  
  
  function showGB($tmpl, $DBConn) {
   
    $bin = getCGIParam("bin", 'G', false);
    $sub = getCGIParam("sub", 'G', false);
    
    if ($sub < 10)
      $sub = str_pad($sub, 2, "0", STR_PAD_LEFT);
    
    $bin_num = $bin . '.' . $sub;
    $highlight1 = "h_feat=" .  $bin_num;
    
    $tmpl->get('highlight')->replace($highlight1);
    $tmpl->get('bin_num')->replace($bin_num);
    $tmpl->get('genome_browser')->unmute();
    
  }//showGB


  function showGenes($tmpl, $DBConn) {

    $bin = getCGIParam("bin", 'G', false);
    $sub = getCGIParam("sub", 'G', false);
    
    if ($sub < 10)
      $sub = str_pad($sub, 2, "0", STR_PAD_LEFT);
    $bin_num = $bin . '.' . $sub;
  
    $bin_map = retrieve_bin_map_id($bin);
    $bin_value = return_valid_bin_number($bin,$sub);

    $query_genes = "
      SELECT A.ID, A.NAME, A.FULL_NAME 
      FROM LOCUS A 
        LEFT OUTER JOIN ID_NUM B ON A.ID = B.ID 
        LEFT OUTER JOIN LOCUS_COORDINATES C ON A.ID = C.ID 
      WHERE A.TYPE = 101 AND B.CURATION_LVL = 0 
            AND C.MAP = $bin_map 
            AND (C.BIN = $bin_value OR C.BIN2 = $bin_value 
                 OR (C.BIN < $bin_value AND C.BIN2 > $bin_value)) 
      ORDER BY LOWER(A.NAME)";
    $statement_genes = make_query($DBConn,$query_genes,250);
    $count = 0;
    $gene_count = 0;
    $genes_results = array();
    
    while($arrGenes = retrieve_row($statement_genes)) { 
      $temp = $gene_count % 2;
      if(($temp == 0) && ($gene_count > 0))
      {
        $genes_results[$count]['id_2'] = $arrGenes['id'];
        $genes_results[$count]['full_name_2'] = trim($arrGenes['full_name']);
        $genes_results[$count]['name_2'] = trim($arrGenes['name']);
        $count++;
      }
      else
      {
        $genes_results[$count]['id'] = $arrGenes['id'];
        $genes_results[$count]['full_name'] = trim($arrGenes['full_name']);
        $genes_results[$count]['name'] = trim($arrGenes['name']);
      }
      $gene_count++;
    }
    $tmpl->get('gene_count')->replace($gene_count);
    $tmpl->get('gene_sec')->loop($genes_results);
    $tmpl->get('genes')->unmute();
  }//showGenes
  

function showGeneModels($tmpl, $DBConn) {
  
  $bin = getCGIParam("bin", 'G', false);
  $sub = getCGIParam("sub", 'G', false);
  
  if ($sub < 10)
    $sub = str_pad($sub, 2, "0", STR_PAD_LEFT);
  $bin_num = $bin . '.' . $sub;
  
  $bin_map = retrieve_bin_map_id($bin);
  $bin_value = return_valid_bin_number($bin,$sub);
  
  $query_genes = "
    select distinct gm.gene_name, gm.model_type, l.name, gm.gm_start
    from chado.gene_model gm
    left join ext_db_key edb on gm.gene_name = edb.key
    left join locus l on edb.id = l.id
    where gm.chr = " . $DBConn->quote('Chr' . $bin) . "
      and gm.assembly_version = 'B73 RefGen_v3'
      and gm.gm_start >= (select chr_start from bin_coordinates where bin like '1.01')
      and gm.gm_end <= (select chr_end from bin_coordinates where bin like '1.01')
    order by gm.gm_start;";
  $statement_genes = make_query($DBConn,$query_genes,250);
  $count = 0;
  $gene_m_count = 0;
  $genes_m_results = array();
  while($arrGenes = retrieve_row($statement_genes))
  {
    if (strlen($arrGenes['name'])){
      $arrGenes['name'] = '(' . $arrGenes['name'] . ')';
    }
    $temp = $gene_m_count % 2;
    if(($temp == 0) && ($gene_m_count > 0))
    {
      $genes_m_results[$count]['gm_2'] = $arrGenes['gene_name'];
      $genes_m_results[$count]['type_2'] = $arrGenes['model_type'];
      $genes_m_results[$count]['locus_2'] = $arrGenes['name'];
      $count++;
    }
    else
    {
      $genes_m_results[$count]['gm'] = $arrGenes['gene_name'];
      $genes_m_results[$count]['type'] = $arrGenes['model_type'];
      $genes_m_results[$count]['locus'] = $arrGenes['name'];
    }
    $gene_m_count++;
  }
  $tmpl->get('gene_m_count')->replace($gene_m_count);
  $tmpl->get('gene_model_sec')->loop($genes_m_results);
  $tmpl->get('gene_models')->unmute();
}//showGenesModels


  function showOtherLoci($tmpl, $DBConn)
  {
    $bin = getCGIParam("bin", 'G', false);
    $sub = getCGIParam("sub", 'G', false);
    
    if ($sub < 10)
      $sub = str_pad($sub, 2, "0", STR_PAD_LEFT);
    $bin_num = $bin . '.' . $sub;
  
    $bin_map = retrieve_bin_map_id($bin);
    $bin_value = return_valid_bin_number($bin,$sub);

    // 101 = 'Gene'
    $query_non_genes = "
      SELECT A.ID, A.NAME, A.FULL_NAME, D.NAME AS TYPE 
      FROM LOCUS A 
        LEFT OUTER JOIN ID_NUM B ON A.ID = B.ID 
        LEFT OUTER JOIN LOCUS_COORDINATES C ON A.ID = C.ID 
        LEFT OUTER JOIN TERM D ON A.TYPE = D.ID 
      WHERE A.TYPE != 101 AND B.CURATION_LVL = 0 AND C.MAP = $bin_map 
            AND (C.BIN = $bin_value OR C.BIN2 = $bin_value 
                 OR (C.BIN < $bin_value AND C.BIN2 > $bin_value)) 
      ORDER BY LOWER(D.NAME), LOWER(A.NAME)";
    $statement_non_genes = make_query($DBConn,$query_non_genes,1);

    $gene_count = 0;
    $loci_results = array();
    $count = 0;
    while($arrNonGenes = retrieve_row($statement_non_genes))
    {
      $temp = $gene_count % 2;
      if(($temp == 0) && ($gene_count != 0))
      {
        $loci_results[$count]['id_2'] = $arrNonGenes['id'];
        $loci_results[$count]['full_name_2'] = trim($arrNonGenes['full_name']);
        $loci_results[$count]['name_2'] = trim($arrNonGenes['name']);
        $loci_results[$count]['type_2'] = trim($arrNonGenes['type']);
        
        $loci_results[$count]['open_2'] = "(";
        $loci_results[$count]['close_2'] = ")";
        $count++;
      }
      else
      {
        $loci_results[$count]['id'] = $arrNonGenes['id'];
        $loci_results[$count]['full_name'] = trim($arrNonGenes['full_name']);
        $loci_results[$count]['name'] = trim($arrNonGenes['name']);
        $loci_results[$count]['type'] = trim($arrNonGenes['type']);
        
        $loci_results[$count]['open'] = "(";
        $loci_results[$count]['close'] = ")";
      }
      $gene_count++;
    }
    $tmpl->get('loci_count')->replace($gene_count);
    $tmpl->get('loci_sec')->loop($loci_results);
    
    $tmpl->get("other_loci")->unmute();
  }//showOtherLoci
  
  
  function showHDMaps($tmpl, $DBConn) 
  {
    $bin = getCGIParam("bin", 'G', false);
    $sub = getCGIParam("sub", 'G', false);
    
    if ($sub < 10)
      $sub = str_pad($sub, 2, "0", STR_PAD_LEFT);
    $bin_num = $bin . '.' . $sub;
  
    $bin_map = retrieve_bin_map_id($bin);
    $bin_value = return_valid_bin_number($bin,$sub);

    $count = 0;
    
    // UMC98 map
    $umc98_map = retrieve_umc98_map_id($bin);
    $tmpl->get('umc98_map')->replace($umc98_map);
    $tmpl->get('bin')->replace($bin);
    
    $query_umc98 = "
      SELECT A.ID, A.NAME, A.FULL_NAME, C.VALUE 
      FROM LOCUS A 
        LEFT OUTER JOIN ID_NUM B ON A.ID = B.ID 
        LEFT OUTER JOIN LOCUS_COORDINATES C ON A.ID = C.ID 
      WHERE B.CURATION_LVL = 0 AND C.MAP = $umc98_map 
            AND (C.BIN = $bin_value OR C.BIN2 = $bin_value 
                 OR (C.BIN < $bin_value AND C.BIN2 > $bin_value)) 
      ORDER BY C.VALUE, LOWER(A.NAME)";
    $statement_umc98 = make_query($DBConn, $query_umc98);
    $umc98_results = array();
    while($arrUMC98 = retrieve_row($statement_umc98)) {
      $umc98_results[$count]['umc98_value'] = trim($arrUMC98['value']);
      $umc98_results[$count]['umc98_id'] = $arrUMC98['id'];
      $umc98_results[$count]['umc98_name'] = trim($arrUMC98['name']);
      $umc98_results[$count]['umc98_fullname'] = trim($arrUMC98['full_name']);

      $count++;      
    }
    $tmpl->get('umc98_loop')->loop($umc98_results);

    // BNL 96 map
    $bnl96_map = retrieve_bnl96_map_id($bin);
    $tmpl->get('bnl96_map')->replace($bnl96_map);

    $query_bnl96 = "
      SELECT A.ID, A.NAME, A.FULL_NAME, C.VALUE 
      FROM LOCUS A 
        LEFT OUTER JOIN ID_NUM B ON A.ID = B.ID 
        LEFT OUTER JOIN LOCUS_COORDINATES C ON A.ID = C.ID 
      WHERE B.CURATION_LVL = 0 AND C.MAP = $bnl96_map 
            AND (C.BIN = $bin_value OR C.BIN2 = $bin_value 
                 OR (C.BIN < $bin_value AND C.BIN2 > $bin_value)) 
      ORDER BY C.VALUE, LOWER(A.NAME)";
    $statement_bnl96 = make_query($DBConn, $query_bnl96);
    $bnl96_results = array();
    while($arrBNL96 = retrieve_row($statement_bnl96))
    {
      $bnl96_results[$count]['bnl96_value'] = trim($arrBNL96['value']);
      $bnl96_results[$count]['bnl96_id'] = $arrBNL96['id'];
      $bnl96_results[$count]['bnl96_name'] = trim($arrBNL96['name']);
      $bnl96_results[$count]['bnl96_fullname'] = trim($arrBNL96['full_name']);
      
      $count++;      
    }
    $tmpl->get('bnl96_loop')->loop($bnl96_results);

    // IBM map
    $ibm_map = retrieve_ibm_map_id($bin);
    $tmpl->get('ibm_map')->replace($ibm_map);

    $query_ibm = "
      SELECT A.ID, A.NAME, A.FULL_NAME, C.VALUE 
      FROM LOCUS A 
        LEFT OUTER JOIN ID_NUM B ON A.ID = B.ID 
        LEFT OUTER JOIN LOCUS_COORDINATES C ON A.ID = C.ID 
      WHERE B.CURATION_LVL = 0 AND C.MAP = $ibm_map 
            AND (C.BIN = $bin_value OR C.BIN2 = $bin_value 
                 OR (C.BIN < $bin_value AND C.BIN2 > $bin_value)) 
      ORDER BY C.VALUE, LOWER(A.NAME)";
    $statement_ibm = make_query($DBConn, $query_ibm);
    $ibm_results = array();
    while($arrIBM = retrieve_row($statement_ibm))
    {
      $ibm_results[$count]['ibm_value'] = trim($arrIBM['value']);
      $ibm_results[$count]['ibm_id'] = $arrIBM['id'];
      $ibm_results[$count]['ibm_name'] = trim($arrIBM['name']);
      $ibm_results[$count]['ibm_fullname'] = trim($arrIBM['full_name']);
       
      $count++;
    }
    $tmpl->get('ibm_loop')->loop($ibm_results);
    
    // Genetic map
    $genetic_map = retrieve_genetic_map_id($bin);
    $tmpl->get('genetic_map')->replace($genetic_map);

    $query_genetic = "
      SELECT A.ID, A.NAME, A.FULL_NAME, C.VALUE, C.G 
      FROM LOCUS A 
        LEFT OUTER JOIN ID_NUM B ON A.ID = B.ID 
        LEFT OUTER JOIN LOCUS_COORDINATES C ON A.ID = C.ID 
      WHERE B.CURATION_LVL = 0 AND C.MAP = $genetic_map 
            AND (C.BIN = $bin_value OR C.BIN2 = $bin_value 
                 OR (C.BIN < $bin_value AND C.BIN2 > $bin_value)) 
      ORDER BY C.VALUE, LOWER(A.NAME)";
    $genetic_results = array();
    $statement_genetic = make_query($DBConn, $query_genetic);
    while($arrGenetic=retrieve_row($statement_genetic))
    {
      $genetic_results[$count]['genetic_value'] = (isset($arrGenetic['value'])) ? trim($arrGenetic['value']) : '';
      $genetic_results[$count]['genetic_id'] = (isset($arrGenetic['id'])) ? $arrGenetic['id'] : '';
      $genetic_results[$count]['genetic_name'] = (isset($arrGenetic['name'])) ? trim($arrGenetic['name']) : '';
      $genetic_results[$count]['genetic_fullname'] = (isset($arrGenetic['full_name'])) ? trim($arrGenetic['full_name']) : '';
       
      if (strlen($arrGenetic['g']) > 0)
        $genetic_results[$count]['genetic_value'] .= " +/- " . number_format($arrGenetic['g'], 0);

      $count++;
    }
    $tmpl->get('genetic_loop')->loop($genetic_results);
    
    $tmpl->get('hd_maps')->unmute();
  }//showHDMaps
  
  
  function showAccession($tmpl, $DBConn) 
  { 
    $bin = getCGIParam("bin", 'G', false);
    $sub = getCGIParam("sub", 'G', false);
    
    if ($sub < 10)
      $sub = str_pad($sub, 2, "0", STR_PAD_LEFT);
    $bin_num = $bin . '.' . $sub;
  
    $bin_map = retrieve_bin_map_id($bin);
    $bin_value = return_valid_bin_number($bin,$sub);
    
    $query_accessions = "
      SELECT DISTINCT(B.SEQ_ID), B.GENBANK_ACC 
      FROM ID_SEQ A 
        JOIN Z_SEQUENCE B ON A.SEQ = B.SEQ_ID 
        JOIN LOCUS_COORDINATES C ON A.ID = C.ID 
    JOIN id_num idn ON A.ID = idn.id
      WHERE (c.bin = $bin_value OR c.bin2 = $bin_value 
             OR (c.bin < $bin_value AND c.bin2 > $bin_value))
       AND idn.curation_lvl = 0";
    $statement_accessions = make_query($DBConn, $query_accessions);

    $map_count = 1;
    $row_count = 0;
    $map_results = array();
    while($arrAccessions = retrieve_row($statement_accessions))
    {
      $temp = $map_count % 6;
      if(($temp == 0) && ($map_count != 0))
      {
        $row_count++;
        $map_count = 1;
      }
      $map_results[$row_count]['seqID_'.$map_count] = $arrAccessions['seq_id'];
      $map_results[$row_count]['genbank_'.$map_count] = trim($arrAccessions['genbank_acc']);
      $map_count++;
    }
    $count = ($row_count * 5) + $map_count;
    $tmpl->get('map_count')->replace($count);
    $tmpl->get('bin_num')->replace($bin_num);
    $tmpl->get('acc_sec')->loop($map_results);
    $tmpl->get('accession')->unmute();
  }
    
    
  function showESTSSR($tmpl, $DBConn) 
  {
    $bin = getCGIParam("bin", 'G', false);
    $sub = getCGIParam("sub", 'G', false);
    
    if ($sub < 10)
      $sub = str_pad($sub, 2, "0", STR_PAD_LEFT);
    $bin_num = $bin . '.' . $sub;
  
    $bin_map = retrieve_bin_map_id($bin);
    $bin_value = return_valid_bin_number($bin,$sub);
  
    //ESTs
    $query_contigs = "
      SELECT DISTINCT(F.TUC_ID) 
      FROM ID_SEQ A 
        JOIN Z_SEQUENCE B ON A.SEQ = B.SEQ_ID 
        JOIN LOCUS_COORDINATES C ON A.ID = C.ID 
        LEFT OUTER JOIN Z_TUC_EST F ON B.SEQ_ID = F.EST_GI 
    JOIN id_num idn ON A.ID = idn.id
      WHERE (c.bin = $bin_value OR c.bin2 = $bin_value 
            OR (c.bin < $bin_value AND c.bin2 > $bin_value))
      AND idn.curation_lvl = 0";
    $statement_contigs = make_query($DBConn,$query_contigs,1000);


    $map_count = 1;
    $row_count = 1;
    $est_results = array();
    while($arrContigs = retrieve_row($statement_contigs))
    { 
      if (isset($arrContigs['tuc_id']) && $arrContigs['tuc_id'] > 0)
      {
        $temp = $map_count % 4;
        if(($temp == 0) && ($map_count != 0))
        {
        $row_count++;
        $map_count = 1;
        }
      
       $est_results[$row_count]['tucID_'.$map_count] = $arrContigs['tuc_id'];
       $map_count++;
      }
    }
    $tmpl->get('est_sec')->loop($est_results);
    $tmpl->get('est_count')->replace(($row_count * 3) + $map_count);
    
    //SSRs (104436 = 'SSR PCR')
    $query_ssrs = "
      select distinct(e.id), e.name, e.repeat 
      from locus_coordinates a 
        left outer join id_num b on a.id = b.id 
        left outer join locus_detected_by c on a.id = c.id 
        left outer join id_num d on c.probe_id = d.id 
        left outer join probe e on d.id = e.id 
      where (a.bin = $bin_value or a.bin2 = $bin_value 
             or (a.bin < $bin_value and a.bin2 > $bin_value)) 
            and b.curation_lvl = 0 and d.curation_lvl= 0 and e.type = 104436 
      order by e.repeat, e.name";
    $statement_ssrs = make_query($DBConn,$query_ssrs,1000);

    $ssr_count = 1;
    $row_count = 0;
    $ssr_results = array();
    while($arrSSRs = retrieve_row($statement_ssrs))
    {
      $temp = $ssr_count % 4;
      if(($temp == 0) && ($ssr_count != 0))
      {
        $row_count++;
        $ssr_count = 1;
      }
      $ssr_results[$row_count]['ssrID_'.$ssr_count] = $arrSSRs['id'];
      $ssr_results[$row_count]['repeat_'.$ssr_count] = trim($arrSSRs['repeat']);
      $ssr_results[$row_count]['ssrname_'.$ssr_count] = trim($arrSSRs['name']);
       
      $ssr_count++;
    }
    $tmpl->get('ssr_sec')->loop($ssr_results);
    $tmpl->get('ssr_count')->replace((3 * $row_count) + $ssr_count);
    
    $tmpl->get('bin_num')->replace($bin_num);
    $tmpl->get('est_ssr')->unmute();
  }//showESTSSR
  
  
  function showBAC($tmpl, $DBConn) 
  {
    $bin = getCGIParam("bin", 'G', false);
    $sub = getCGIParam("sub", 'G', false);
    
    if ($sub < 10)
      $sub = str_pad($sub, 2, "0", STR_PAD_LEFT);
    $bin_num = $bin . '.' . $sub;
  
    $bin_map = retrieve_bin_map_id($bin);
    $bin_value = return_valid_bin_number($bin,$sub);
    
    // 171715 = 'BAC clone'
    $query_bacs = "
      select distinct(e.id), e.name, f.name as method, g.id as locus_id, 
             g.name as locus_name 
      from locus_coordinates a 
        left outer join id_num b on a.id = b.id 
        left outer join locus_detected_by c on b.id = c.id 
        left outer join id_num d on c.probe_id = d.id 
        left outer join probe x on d.id = x.id 
        left outer join relation y on x.id = y.id 
        left outer join probe e on y.related_id = e.id 
        left outer join term f on c.method = f.id 
        left outer join locus g on a.id = g.id 
      where (a.bin = $bin_value or a.bin2 = $bin_value 
             or (a.bin < $bin_value and a.bin2 > $bin_value)) 
            and b.curation_lvl = 0 and d.curation_lvl= 0 
            and e.type = 171715 
      order by g.name, e.name";
    $statement_bacs = make_query($DBConn,$query_bacs,1000);

    $bac_count = 1;
    $row_count = 0;
    while($arrBACs = retrieve_row($statement_bacs))
    {
      $temp = $bac_count % 3;
      if(($temp == 0) && ($bac_count != 0))
      {
        $row_count++;
        $bac_count = 1;
      }
      $bac_results[$row_count]['bacID_'.$bac_count] = $arrBACs['id'];
      $bac_results[$row_count]['bacname_'.$bac_count] = trim($arrBACs['name']);
      $bac_results[$row_count]['blocID_'.$bac_count] = trim($arrBACs['locus_id']);
      $bac_results[$row_count]['blocname_'.$bac_count] = trim($arrBACs['locus_name']);
      
      //non-data text
      $bac_results[$row_count]['open_'.$bac_count] = "(@ ";
      $bac_results[$row_count]['close_'.$bac_count] = ")";
      $bac_results[$row_count]['by_'.$bac_count] = "by ";
      
      if(strlen(trim($arrBACs['method'])))
        $bac_results[$row_count]['method_'.$bac_count] = $arrBACs['method'];
      else
        $bac_results[$row_count]['method_'.$bac_count] = "unspecified method";
      
      $check_for_seqs = "
    select count(edk.id) 
    from ext_db_key edk, id_num idn
    where db_person = 983096 
      and edk.id = " . $arrBACs['id'] ."
      AND edk.id = idn.id
      AND idn.curation_lvl = 0";
      $seqs_statement = make_query($DBConn,$check_for_seqs,1);
      $arrSeqStmt = retrieve_row($seqs_statement);
      
      if ($arrSeqStmt['count'] |= "0")
        $bac_results[$row_count]['seq_'.$bac_count] = "*"; 

      $bac_count++;
    }
    $tmpl->get('bac_sec')->loop($bac_results);
    $tmpl->get('bac_count')->replace((2 * $row_count) + $bac_count);
    $tmpl->get('bin_num')->replace($bin_num);
    
    // Get bin physical coordinates
    $sql = "
      SELECT chr, chr_start, chr_end 
      FROM bin_coordinates 
      WHERE bin='$bin_value'";
    $sth = make_query($DBConn, $sql);
    if ($row = retrieve_row($sth)) {
      $pos = $row['chr'] . ':' . $row['chr_start'] . '-' . $row['chr_end'];
      $tmpl->get('bin-pos')->replace($pos);
      $tmpl->get('gramene_bac_view')->unmute();
    }

    $tmpl->get('bac')->unmute();
  }//show_sequence_match
  
  
  /****************************************************
   ********************HELPER METHODS (TODO: CLEAN UP)*
   ****************************************************/
   
   function return_valid_bin_number($major,$minor)
  {
    $flush = settype($major,"integer");
    $flush = settype($minor,"integer");
    if(($minor > -1) && ($minor < 7) && ($major > 0) && ($major < 11))
    {
      $minor_divided = $minor / 100;
      $major = $major + $minor_divided;
      return $major;
    }
    else if(($minor > -1) && ($minor < 8) && ($major > 0) && ($major < 11) && ($major != 7))
    {
      $minor_divided = $minor / 100;
      $major = $major + $minor_divided;
      return $major;
    }
    else if(($minor > -1) && ($minor < 9) && ($major > 0) && ($major < 10) && ($major != 7))
    {
      $minor_divided = $minor / 100;
      $major = $major + $minor_divided;
      return $major;
    }
    else if(($minor > -1) && ($minor < 10) && ($major > 0) && ($major < 9) && ($major != 7) && ($major != 6))
    {
      $minor_divided = $minor / 100;
      $major = $major + $minor_divided;
      return $major;
    }
    else if(($minor > -1) && ($minor < 11) && ($major > 0) && ($major < 5))
    {
      $minor_divided = $minor / 100;
      $major = $major + $minor_divided;
      return $major;
    }
    else if(($minor > -1) && ($minor < 12) && (($major == 1) || ($major == 4)))
    {
      $minor_divided = $minor / 100;
      $major = $major + $minor_divided;
      return $major;
    }
    else if(($minor == 12) && ($major == 1))
      return 1.12;
    else
      return 0;
  }

  function retrieve_bin_map_id($major)
  {
    $flush = settype($major,"integer");
    if($major == 1)
      return 64489;
    else if($major == 2)
      return 64501;
    else if($major == 3)
      return 64505;
    else if($major == 4)
      return 64506;
    else if($major == 5)
      return 64507;
    else if($major == 6)
      return 64508;
    else if($major == 7)
      return 64509;
    else if($major == 8)
      return 64510;
    else if($major == 9)
      return 64511;
    else if($major == 10)
      return 64512;
    else
      return 0;
  }

  function retrieve_umc98_map_id($major)
  {
    $flush = settype($major,"integer");
    if($major == 1)
      return 143431;
    else if($major == 2)
      return 143432;
    else if($major == 3)
      return 143433;
    else if($major == 4)
      return 143434;
    else if($major == 5)
      return 143435;
    else if($major == 6)
      return 143436;
    else if($major == 7)
      return 143437;
    else if($major == 8)
      return 143438;
    else if($major == 9)
      return 143439;
    else if($major == 10)
      return 143440;
    else
      return 0;
  }

  function retrieve_bnl96_map_id($major)
  {
    $flush = settype($major,"integer");
    if($major == 1)
      return 128612;
    else if($major == 2)
      return 128613;
    else if($major == 3)
      return 128614;
    else if($major == 4)
      return 128615;
    else if($major == 5)
      return 128616;
    else if($major == 6)
      return 128617;
    else if($major == 7)
      return 128618;
    else if($major == 8)
      return 128619;
    else if($major == 9)
      return 128620;
    else if($major == 10)
      return 128621;
    else
      return 0;
  }

  function retrieve_ibm_map_id($major)
  {
    $flush = settype($major,"integer");
    if($major == 1)
      return 934700;
    else if($major == 2)
      return 934701;
    else if($major == 3)
      return 934702;
    else if($major == 4)
      return 934703;
    else if($major == 5)
      return 934704;
    else if($major == 6)
      return 934705;
    else if($major == 7)
      return 934706;
    else if($major == 8)
      return 934707;
    else if($major == 9)
      return 934708;
    else if($major == 10)
      return 934709;
    else
      return 0;
  }

  function retrieve_genetic_map_id($major)
  {
    $flush = settype($major,"integer");
    if($major == 1)
      return 940880;
    else if($major == 2)
      return 940881;
    else if($major == 3)
      return 940882;
    else if($major == 4)
      return 940883;
    else if($major == 5)
      return 940884;
    else if($major == 6)
      return 940885;
    else if($major == 7)
      return 940886;
    else if($major == 8)
      return 940887;
    else if($major == 9)
      return 940888;
    else if($major == 10)
      return 940889;
    else
      return 0;
  }
  
  
?>
