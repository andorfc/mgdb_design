<?PHP
/* file: chromosome_data.php
 *
 * purpose: display the various sections of a chromosome record; called via Ajax
 *
 * history:
 *  12/28/12  jportwood  created
 */

/* 2026-08-29: every section of this endpoint returned empty rows.
 *
 * retrieve_row() in include/db-api.php used to copy each field to an
 * UPPERCASE key as well -- the comment there calls it a HACK to avoid changing
 * "umpteen-gazillion lines of code" -- and that copy is now commented out.
 * PostgreSQL returns lower case column names, so every $arrGenes["ID"] here
 * read null and the while(strlen(...)) loops ended before their first
 * iteration. The sibling endpoint record_data/bin_viewer_data.php was
 * converted to lower case at some point; this one was not, so /bin_viewer?bin=
 * worked and /bin_viewer?chrom= rendered a heading with an empty list under it
 * on all ten chromosomes.
 *
 * The keys are lower case now, and the loops read from their statement handle
 * directly: strlen() on the false that retrieve_row() returns at end of
 * results is a TypeError under PHP 8.
 */

  include_once('../lib/Bauplan.php');
  include_once("../include/db-api.php");
  include_once("../include/api_tools.php");
  include_once('../include/gp_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $chrom   = getCGIParam("id", 'G', false);
  $type = getCGIParam("type", 'G', false);
logMessage("chromosome_data.php: chrom=$chrom, type=$type");
  
  if (!$type) {
    reportError("No section type given to chromosome_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  /* See the note in bin_viewer_data.php. nomaps=1 drops the image-map
     partial that every section response would otherwise carry. */
  $nomaps = getCGIParam("nomaps", 'G', false);
  $sections_template = ($nomaps && $type != 'top')
                     ? '../templates/tools/chromosome-sections-nomaps.bau'
                     : '../templates/tools/chromosome-sections.bau';
  $tmpl = $bauplan->template()->load($sections_template);
  
  $DBConn = connect_to_database();
  $chrom = validate_input($DBConn, $chrom);

  switch ($type) {
    case 'top':
      show_top($tmpl, $chrom, $DBConn);
      break;
    case 'gb_links':
      showGB($tmpl, $chrom, $DBConn);
      break;
    case 'genes':
      showGenes($tmpl, $chrom, $DBConn);
      break;
    case 'other_loci': //display other loci section with list deprecated (default)
      showOtherLoci($tmpl, $chrom, $DBConn, false);
      break;
    case 'other_loci_list': //display section with full list
      showOtherLoci($tmpl, $chrom, $DBConn, true);
      break;
    case 'hd_maps':
      showHDMaps($tmpl, $chrom, $DBConn);
      break;
    case 'accession':
      showAccession($tmpl, $chrom, $DBConn);
      break;
  }
  $bauplan->publish();
  
  function show_top($tmpl, $chrom, $DBConn)
  {   
    $tmpl->get('chrom')->replace($chrom);
    $tmpl->get('top')->unmute();
  }//showTop
  
  
  function showGB($tmpl, $chrom, $DBConn) {
    $tmpl->get('chrom')->replace($chrom);
    $tmpl->get('genome_browser')->unmute();
    $tmpl->get('gbrowse_url')->replace($system['GBROWSE_URL']);
    $tmpl->get('gbrowse_url_v2')->replace($system['GBROWSE_URL_V2']);
    $tmpl->get('gbrowse_url_v1')->replace($system['GBROWSE_URL_V1']);
    $tmpl->get('gbrowse_url_bac')->replace($system['GBROWSE_URL_BAC']);
  }//showGB


  function showGenes($tmpl, $chrom, $DBConn) {
    
    $linkage_group = getLG($chrom);

    $query_genes = "
      SELECT A.ID, A.NAME, A.FULL_NAME 
      FROM LOCUS A 
        JOIN ID_NUM B ON A.ID = B.ID 
      WHERE A.TYPE = 101 AND B.CURATION_LVL = 0 
            AND A.LINKAGE_GROUP = $linkage_group
      ORDER BY LOWER(A.NAME)";
    $statement_genes = make_query($DBConn,$query_genes);
    //$arrGenes = get_all_rows($statement_genes);
    $count = 0;
    $gene_count = 0;
    $genes_results = array();
    while (($arrGenes = retrieve_row($statement_genes)) !== false)
    { 
      $temp = $gene_count % 2;
      if(($temp == 0) && ($gene_count > 0))
      {
        $genes_results[$count]['id_2'] = $arrGenes['id'];
        $genes_results[$count]['full_name_2'] = trim($arrGenes["full_name"]);
        $genes_results[$count]['name_2'] = trim($arrGenes["name"]);
        $count++;
      }
      else
      {
        $genes_results[$count]['id'] = $arrGenes['id'];
        $genes_results[$count]['full_name'] = trim($arrGenes["full_name"]);
        $genes_results[$count]['name'] = trim($arrGenes["name"]);
      }
      $gene_count++;
    }
    $tmpl->get('gene_count')->replace($gene_count);
    $tmpl->get('chrom')->replace($chrom);
    $tmpl->get('gene_sec')->loop($genes_results);
    $tmpl->get('genes')->unmute();
  }//showGenes


  function showOtherLoci($tmpl, $chrom, $DBConn, $showList)
  {
    $linkage_group = getLG($chrom);
    if ($showList === false)
    {
       $query_non_gene_count = "
         SELECT COUNT(A.ID) 
         FROM LOCUS A 
           JOIN ID_NUM B ON A.ID = B.ID 
         WHERE A.TYPE != 101 AND B.CURATION_LVL = 0 
               AND A.LINKAGE_GROUP = $linkage_group";
       $statement_non_gene_count = make_query($DBConn,$query_non_gene_count,1);
       $arrNonGeneCount = retrieve_row($statement_non_gene_count);
       
       $tmpl->get('loci_count')->replace($arrNonGeneCount['count']);
    }    
    else
    {
      $query_non_genes = "
        SELECT A.ID, A.NAME, A.FULL_NAME 
        FROM LOCUS A, ID_NUM B
        WHERE A.TYPE != 101 AND A.ID = B.ID AND B.CURATION_LVL = 0 
              AND A.LINKAGE_GROUP = $linkage_group 
        ORDER BY LOWER(A.NAME)";
      $statement_non_genes = make_query($DBConn,$query_non_genes,1);

      $gene_count = 0;
      $loci_results = array();
      $count = 0;
      while (($arrNonGenes = retrieve_row($statement_non_genes)) !== false)
      {
        $temp = $gene_count % 2;
        if(($temp == 0) && ($gene_count != 0))
        {
          $loci_results[$count]['id_2'] = $arrNonGenes['id'];
          $loci_results[$count]['full_name_2'] = trim($arrNonGenes["full_name"]);
          $loci_results[$count]['name_2'] = trim($arrNonGenes["name"]);
          $count++;
        }
        else
        {
          $loci_results[$count]['id'] = $arrNonGenes['id'];
          $loci_results[$count]['full_name'] = trim($arrNonGenes["full_name"]);
          $loci_results[$count]['name'] = trim($arrNonGenes["name"]);
        }
        $gene_count++;
      }
      $tmpl->get('loci_pretext')->mute();
      $tmpl->get('loci_sec')->loop($loci_results);
    }
    $tmpl->get('chrom')->replace($chrom);
    $tmpl->get("other_loci")->unmute();
  }//showOtherLoci
  
  function showHDMaps($tmpl, $chrom, $DBConn) 
  {
    $query_maps = "
      SELECT A.ID, A.NAME 
      FROM MAP A 
        JOIN ID_NUM B ON A.ID = B.ID 
      WHERE B.CURATION_LVL = 0 AND A.NAME LIKE '%$chrom' 
            AND A.NAME NOT LIKE 'Oryza sativa%' 
      ORDER BY LOWER(A.NAME)";
    $statement_maps = make_query($DBConn,$query_maps,100);
    
    $map_count = 1;
    $row_count = 0;
    $total_maps = 0;
    $map_results = array();
    while (($arrMaps = retrieve_row($statement_maps)) !== false)
    {
      $temp = $map_count % 3;
      if(($temp == 0) && ($map_count != 0))
      {
        $row_count++;
        $map_count = 1;
      }
      $map_results[$row_count]['mapID_'.$map_count] = $arrMaps["id"];
      $map_results[$row_count]['mapName_'.$map_count] = fix_map_name($arrMaps["name"]);
      $map_count++;
      $total_maps++;
    }
    /* $map_count is a column index that resets every third map, so it was
       never a total. Verified against the query: chromosome 1 has 210 maps,
       and ($row_count * 2) + $map_count reported 211. */
    $tmpl->get('map_count')->replace($total_maps);
    $tmpl->get('map_sec')->loop($map_results);
    $tmpl->get('chrom')->replace($chrom);
    $tmpl->get('hd_maps')->unmute();
  }//showHDMaps
  
  function showAccession($tmpl, $chrom, $DBConn) 
  { 
    $linkage_group = getLG($chrom);
    $query_accessions = "
      select distinct(f.seq_id), f.genbank_acc as key 
      from locus a 
        join id_num b on a.id = b.id join locus_detected_by c on a.id = c.id 
        join id_num d on c.probe_id = d.id join id_seq e on c.probe_id = e.id 
        join z_sequence f on e.seq = f.seq_id 
      where a.linkage_group = $linkage_group and b.curation_lvl = 0 
            and d.curation_lvl = 0";
    $statement_accessions = make_query($DBConn,$query_accessions,1000);

    $map_count = 1;
    $row_count = 0;
    $total_accessions = 0;
    $map_results = array();
    while (($arrAccessions = retrieve_row($statement_accessions)) !== false)
    {
      $temp = $map_count % 6;
      if(($temp == 0) && ($map_count != 0))
      {
        $row_count++;
        $map_count = 1;
      }
      $map_results[$row_count]['seqID_'.$map_count] = $arrAccessions["seq_id"];
      $map_results[$row_count]['genbank_'.$map_count] = trim($arrAccessions["key"]);
      $map_count++;
      $total_accessions++;
    }
    /* Same counting bug as showHDMaps. The join returns no rows at all for
       any chromosome, and the page still read "the 1 sequences". */
    $tmpl->get('acc_count')->replace($total_accessions);
    $tmpl->get('acc_sec')->loop($map_results);
    $tmpl->get('chrom')->replace($chrom);
    $tmpl->get('accession')->unmute();
  }
    
   
  /****************************************************
   ********************HELPER METHODS******************
   ****************************************************/
  
  function getLG($chrom)
  {
    switch($chrom)
    {
      case "1":
       return 13579;
      case "2":
        return 13582;  
      case "3":
        return 13585;
      case "4":
        return 13588;
      case "5":
        return 13591;
      case "6":
        return 13594;
      case "7":
        return 13597;
      case "8":
        return 13600;
      case "9":
        return 13603;
      case "10":
        return 13606;
    }
  }
  
?>