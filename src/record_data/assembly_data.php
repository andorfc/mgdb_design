<?php
/* file: assembly_data.php
 *
 * purpose: display all available information about the given genome assembly.
 *
 * history:
 *  07/25/19  eksc  created
 */
 

function load_genome_page($tmpl, $assembly_name) {
  $DBConn = connect_to_database(false);

  // $assembly_name may instead be its identifier. 
  $names = getAssemblyName($assembly_name, $DBConn);
  
  $tmpl->get('record_name')->replace($names['assembly_name']);
  
  if ($details_page=getDetailsPage($assembly_name, $DBConn)) {
    // Has a project details tab
    $tmpl->get('assembly-detail-tab-metadata')->unmute();
    $tmpl->get('assembly-detail-tab-browser')->unmute();
    $tmpl->get('main_section_project_details')->load("templates/genome/$details_page.bau");
  }
  else if (strstr($assembly_name, 'TUM')) {
    // Has a project details tab
    $tmpl->get('assembly-detail-tab-metadata')->unmute();
    $tmpl->get('assembly-detail-tab-browser')->unmute();
    $tmpl->get('main_section_project_details')->load('templates/genome/european_flints.bau');
  }
  
  // Load template for metadata
  $subtmpl = $tmpl->get('main_section_metadata')->load("templates/genome/assembly_metadata.bau");
  loadAssemblyMetadata($subtmpl, $names, $DBConn);
  
  // Show browser if one exists for this assembly
  showBrowser($tmpl, $assembly_name, $DBConn);
}//load_genome_page



////////////////////////////////////////////////////////////////////////////////
// SUPPORTING FUNCTIONS
////////////////////////////////////////////////////////////////////////////////  

function cleanString($str) {
  $str = str_replace('""', '"', $str);
  return $str;
}//cleanString


function DisplayAnnotationInformation($tmpl, $row, $DBConn) {
  if ($annotation_recs = getAnnotationRecords($row['assembly_name'], $DBConn)) {
    $annotation_values = array();
    foreach ($annotation_recs as $annotation_rec) {
      // If never released, don't show anything (fixes issues when data is
      //   is loaded before publication ... and things change. Sigh.
      if (isset($annotation_rec['is_current']) 
            && $annotation_rec['is_current'] == 'never released') {
        continue;
      }
      $annotation_id = $annotation_rec['annot_id'];
      
      $annotation_name = makeRow('Annotation Identifier', $annotation_rec['annot']);
      
      $withdrawn = false;
      $is_current = false;
      if (isset($annotation_rec['withdrawn']) 
           && $annotation_rec['withdrawn'] == 'yes') {
        // Mark withdrawn
        $name  = "<span class='error'>Withdrawn</span>";
        $value = "<span class='error'>yes</span>";
        $withdrawn = makeRow($name, $value);
      }
      else {
        if (isset($annotation_rec['is_current'])) {
          if ($annotation_rec['is_current'] == 'yes') {
            $is_current = makeRow('Is current', 'yes');
          }
          else {
            $is_current = makeRow('Is current', $annotation_rec['is_current']);
          }
        }
        else {
          $is_current = makeRow('Is current', 'no');
        }
      }
      
      $prop_rows = getProperties('analysisprop', 'analysis_id', $annotation_id, $DBConn);
      foreach ($prop_rows as $prop_row) {
        $property = $prop_row['property'];
        $value    = $prop_row['value'];
        switch ($property) {
          case 'annotation_provider':
            $annotation_provider = makeRow('Annotation Provider', $value);
            break;
          case 'annotation_date':
            $annotation_date = makeRow('Annotation Date', $value);
            break;
          case 'annotation_software':
            $annotation_software = makeRow('Annotation Software', $value);
            break;
          case 'annotation_description':
            $annotation_description = makeRow('Annotation Description', $value);
            break;
          case 'annotation_method':
            $annotation_method = makeRow('Annotation Method', $value);
            break;
          case 'annotation_accession':
            break;
          case 'annotation_download':
            if (trim($value) != '') {
              $links = '';
              $urls = explode(',', $value);
              foreach ($urls as $url) {
                $links .= "<a href=\"$url\">$url</a><br>";
              }
              $annotation_download = makeRow('Data download', $links);
            }
            break;
        }//switch
      }//each property 
      
      array_push($annotation_values, array(
                   'annotation_name'        => (isset($annotation_name)) 
                                                  ? $annotation_name        : '',
                   'annotation_provider'    => (isset($annotation_provider))
                                                  ? $annotation_provider    : '',
                   'annotation_date'        => (isset($annotation_date))
                                                  ? $annotation_date        : '',
                   'is_current'             => (isset($is_current)) 
                                                  ? $is_current             : '',
                   'withdrawn'              => (isset($withdrawn)) 
                                                  ? $withdrawn              : '',
                   'annotation_software'    => (isset($annotation_software))
                                                  ? $annotation_software    : '',
                   'annotation_description' => (isset($annotation_description))
                                                  ? $annotation_description : '',
                   'annotation_method'      => (isset($annotation_method))
                                                  ? $annotation_method      : '',
                   'annotation_accession'   => (isset($annotation_accession))
                                                  ? $annotation_accession   : '',
                   'annotation_download'    => (!$withdrawn && isset($annotation_download))
                                                  ? $annotation_download    : '',
      ));
    }//each annotation

    $tmpl->get('structural-annotation-list')->loop($annotation_values);
    $tmpl->get('structural-annotation')->unmute();
  }
}//DisplayAnnotationInformation


function DisplayAssemblyInformation($tmpl, $row, $DBConn) {
  $html = makeRow('Assembly name', $row['assembly_name']);
  $tmpl->get('assembly_name')->replace($html);
  
  if (preg_match('/^ERS\d+/', $row['wgs_accession'])) {
    // SRA accession
    $prefix = getURLPrefix('GenBank:SRA', $DBConn);
  }
  else {
    $prefix = getURLPrefix('GenBank:nucleotide', $DBConn);
  }
  $link = "<a href='$prefix".$row['wgs_accession']."'>".$row['wgs_accession']."</a>";
  $html = makeRow('WGS accession', $link);
  $tmpl->get('WGS_accession')->replace($html);
  
  $html = makeRow('Sequencing description', $row['sequencing_description']);
  $tmpl->get('sequencing_description')->replace($html);
  
  $html = makeRow('Assembly description', $row['assembly_description']);
  $tmpl->get('assembly_description')->replace($html);
  
  $prop_rows = getProperties('analysisprop', 'analysis_id', $row['analysis_id'], $DBConn);
  foreach ($prop_rows as $prop_row) {
    $property = $prop_row['property'];
    $value    = $prop_row['value'];
    switch ($property) {
      // Also-known-as
      case 'analysis_synonyms':
        $tmpl->get('aka')->replace($value);
        $tmpl->get('genome-aka')->unmute();
        break;
        
      // Simple properties
      case 'assembly_date':
      case 'assembly_provider':
      case 'assembly_methods':
      case 'release_date':
      case 'finishing_strategy':
        $html = makeRow($property, $value);
        $tmpl->get($prop_row['property'])->replace($html);
        break;
        
      // Special case properties
      case 'Assembly_accession':
        $link = '<a href="https://www.ncbi.nlm.nih.gov/assembly/' . $prop_row['value'] . '">'
              . $prop_row['value'] 
              . '</a>';
        $html = makeRow('Assembly accession', $link);
        $tmpl->get('Assembly_accession')->replace($html);
        break;
        
      case 'contributors':
        $html = makeRow($prop_row['property'], $prop_row['value']);
        $tmpl->get($prop_row['property'])->replace($html);
        break;
        
      case 'genome_alignment':
        $html = makeRow('Genome used for alignment', $value);
        $tmpl->get('genome_alignment')->replace($html);
        $tmpl->get('genome_alignment_row')->unmute();
        break;
        
      // Browser link
      case 'MaizeGDB_browser_URL':
        $link = "<a href=\"" . $prop_row['value'] . "\">Genome browser at MaizeGDB</a>";
        $html = makeRow('Browse Genome', $link);
        $tmpl->get('MaizeGDB_browser_URL')->replace($html);
        break;

      // Download links
      case 'Download_URLs':
        if (trim($prop_row['value']) != '') {
          $links = '';
          $urls = explode(',', $prop_row['value']);
          foreach ($urls as $url) {
            $links .= "<a href=\"$url\">$url</a><br>";
          }
          $html = makeRow('Data download', $links);
          $tmpl->get('download_URLs')->replace($html);
        }
        break;
        
      // Optional properties
      case 'comment':
        $html = makeRow($property, $value);
        $tmpl->get($property)->replace($html);
        break;
        
      case 'seq_service_provider':
      case 'seq_hardware':
      case 'seq_chemistry':
      case 'seq_chemistry_version':
        $html = makeRow($property, $value);
        $tmpl->get($property)->replace($html);
        $tmpl->get($property.'_row')->unmute();
        break;
      
      // Assembly statistics
      case 'total_psuedomolecule_length':
      case 'total_scaff_length':
      case 'longest_scaff':
      case 'shortest_scaff':
      case 'N50_scaff_length':
      case 'N90_scaff_length':
      case 'total_contig_length':
      case 'longest_contig':
      case 'shortest_contig':
      case 'N50_contig_length':
      case 'N90_contig_length':
        $value = str_replace('bp', '', $value);
        $num = number_format(str_replace(',', '', $value));
        $html = makeRow($property, $num.'&nbsp;bp');
        $tmpl->get($property)->replace($html);
        $tmpl->get($property.'_row')->unmute();
        $tmpl->get($property.'_gloss')->unmute();
        break;
      case 'scaff_num':
      case 'N50_scaff_count':
      case 'N90_scaff_count':
      case 'N50_contig_count':
      case 'N90_contig_count':
        $html = makeRow($property, number_format(str_replace(',', '', $value)));
        $tmpl->get($property)->replace($html);
        $tmpl->get($property.'_row')->unmute();
        $tmpl->get($property.'_gloss')->unmute();
        break;
      case 'perc_seq_scaffold':
      case 'perc_seq_unscaffold':
        $value = str_replace('%', '', $value);
        $html = makeRow($property, number_format(str_replace(',', '', $value), 2));
        $tmpl->get($property)->replace($html);
        $tmpl->get($property.'_row')->unmute();
        $tmpl->get($property.'_gloss')->unmute();
        break;
    }//switch
  }//each property
}//DisplayAssemblyInformation


function DisplayProjectInformation($tmpl, $row, $DBConn) {
  $description = nl2br(cleanString($row['project']));
  $tmpl->get('project_name')->replace($description);

  // Check if this assembly was withdrawn and possibly replaced by another
  //   assembly.
  if (isset($row['replaced_by'])) {
    $html = "
      <span style='color:red;font-weight:bold'>This assembly was withdrawn.</span>
      <br><br>
    ";
    $tmpl->get('withdrawn-statement')->replace($html);
  }

  // Use this later:
  $award_prop = '';
  
  $prop_rows = getProperties('projectprop', 'project_id', $row['project_id'], $DBConn);
  foreach ($prop_rows as $prop_row) {
    switch ($prop_row['property']) {
      case 'funding':
        $award_prop = $prop_row['value'];
        break;
        
      case 'project_PI':
        $html = makeRow('Project PI', $prop_row['value']);
        $tmpl->get($prop_row['property'])->replace($html);
        break;
        
      case 'project_start_date':
        $html = makeRow('Project start data', $prop_row['value']);
        $tmpl->get($prop_row['property'])->replace($html);
        break;
        
//      case 'project_description':

      case 'change_history':
        $html = makeRow('Changes to previous version', $prop_row['value']);
        $tmpl->get($prop_row['property'])->replace($html);
        break;

      case 'replaced_by':
        $url = "/genome/assembly/" . $prop_row['value'];
        $link = "<a href='$url'>" . $prop_row['value'] . "</a>";
        $html = makeRow('<span style="color:red">Replaced by version</span>', $link);
        $tmpl->get($prop_row['property'])->replace($html);
        break;

      case 'consortium':
        if ($url_rec=getProperty('projectprop', 'project_id', $row['project_id'], 
                             'consortium_url', 'maizegdb', $DBConn)) {
          $url = $url_rec['value'];
          $link = "<a href=\"$url\">" . $prop_row['value'] . "</a>";
          $html = makeRow($prop_row['property'], $link);
        }
        else {
          $html = makeRow($prop_row['property'], $prop_row['value']);
        }
        $tmpl->get('consortium')->replace($html);
        break;

      case 'MaizeGDB_reference':
        $url = "/data_center/reference/" . $prop_row['value'];
        $html = "<br><a href=\"$url\">At MaizeGDB<a/>&nbsp;&nbsp;";
        $tmpl->get('mgdb_project_reference')->replace($html);
        $tmpl->get('project_reference')->unmute();
        break;

      case 'reference_title':
        $tmpl->get('reference_title')->replace($prop_row['value'] . '.');
        $tmpl->get('project_reference')->unmute();
        break;
        
      case 'publication_authors':
        $tmpl->get('publication_authors')->replace(nl2br($prop_row['value']));
        break;
        
      case 'publication_status':
        $html = makeRow('Publication status', $prop_row['value']);
        $tmpl->get('publication_status')->replace($html);
        break;
        
      case 'Toronto_agreement':
        if (strtolower($prop_row['value']) == 'yes') {
          $html = TorontoAgreementRow();
          $tmpl->get('toronto_agreement')->replace($html);
        }
        break;
    }//switch
  }//each property

  // Award information
logMessage("Grants property: [$award_prop]");
  if ($award_prop != '' || isset($row['award_name'])) {
    $html = makeRow('Funding', setAwardHTML($row, $award_prop));
    $tmpl->get('award')->replace($html);
  }
    
  $dbxref_rows = getDbxrefs('project_dbxref', 'project_id', $row['project_id'], $DBConn);
  foreach ($dbxref_rows as $dbxref_row) {
    $url = $dbxref_row['urlprefix'] . $dbxref_row['accession'];
    switch ($dbxref_row['db']) {
      case 'PMID':
      case 'DOI':
        $html = "<br><a href=\"$url\">" . $dbxref_row['db'] . "<a/>&nbsp;&nbsp;";
        $tmpl->get('project_'.$dbxref_row['db'])->replace($html);
        $tmpl->get('project_reference')->unmute();
        break;
      case 'GenBank:BioProject':
        $tag = "<a href=\"$url\">" . $dbxref_row['accession'] . "<a/>&nbsp;&nbsp;";
        $html = makeRow('GenBank BioProject', $tag);
        $tmpl->get('bioproject')->replace($html);
        break;
    }//switch
  }//each dbxref
}//DisplayProjectInformation


function DisplaySampleInformation($tmpl, $row, $DBConn) {
  // Stock properies
  $chado_stock_id = (isset($row['chado_stock_id'])) ? $row['chado_stock_id'] : '';
  $prop_rows = getProperties('stockprop', 'stock_id', $chado_stock_id, $DBConn);
  foreach ($prop_rows as $prop_row) {
    $value    = $prop_row['value'];
    $property = $prop_row['property'];
    switch ($property) {
      case 'source_mat_id':
        $html = makeRow('Stock details', $value);
        $tmpl->get('source_mat_id')->replace($html);
        break;
      case 'source_mat_derived_id':
        $html = makeRow('Stock derived from original source', $value);
        $tmpl->get('source_mat_derived_id')->replace($html);
        break;
       case 'MaizeGDB_stock_ID':
        $link = "<a href=\"/data_center/stock/$value\">$value<a/>";
        $html = makeRow('Stock record', $link);
        $tmpl->get('MaizeGDB_stock_ID')->replace($html);
        $tmpl->get('MaizeGDB_stock_ID_row')->unmute();
        break;
     case 'age':
      case 'life_stage':
        $html = makeRow($property, $value);
        $tmpl->get('source_mat_derived_id')->replace($html);
        $tmpl->get($prop_row['property'].'_row')->unmute();
        break;
    }//switch
  }//each stock property

  // Show stock name, with mgdb link, if possible
  if (isset($mgdb_stock_id)) {
    $tag = "<a href='/reference/$mgdb_stock_id'>" . $row['stock_name'] . "</a>";
  }
  else {
    $tag = $row['stock_name'];
  }
  $html = makeRow('Stock name', $tag);
  $tmpl->get('stock_name')->replace($html);
  
  // Sample values
  if (isset($row['species'])) {
    $row['species'] = str_replace(' ()', '', $row['species']); // sometimes common name is empty
    $html = makeRow('Species', '<i>'.$row['species'].'</i>');
    $tmpl->get('species')->replace($html);
  }
  if (isset($row['sample_name'])) {
    $html = makeRow('Sample name', $row['sample_name']);
    $tmpl->get('sample_name')->replace($html);
  }
  
  // Sample (biomaterial) properties
  $prop_rows = getProperties('biomaterialprop', 'biomaterial_id', $row['biomaterial_id'], $DBConn);
  foreach ($prop_rows as $prop_row) {
    switch ($prop_row['property']) {
      case 'description':
        $html = makeRow('Sample description', $prop_row['value']);
        $tmpl->get('sample_description')->replace($html);
        break;
      case 'biomaterial_provider':
        $html = makeRow('Stock provided by', $prop_row['value']);
        $tmpl->get('biomaterial_provider')->replace($html);
        break;
      case 'sample_type':
      case 'collection_date':
      case 'collected_by':
      case 'sample_description':
        $html = makeRow($prop_row['property'], $prop_row['value']);
        $tmpl->get($prop_row['property'])->replace($html);
        break;
      case 'geo_location':
        $html = makeRow('Location', $prop_row['value']);
        $tmpl->get($prop_row['property'])->replace($html);
        break;
      case 'plant_structure':
      case 'age':
      case 'developmental_stage':
      case 'env_biome':
        $html = makeRow($prop_row['property'], $prop_row['value']);
        $tmpl->get($prop_row['property'])->replace($html);
        $tmpl->get($prop_row['property'].'_row')->unmute();
        break;
    }//switch
  }//each property
  
  // Sample (biomaterial) dbxrefs
  $dbxref_rows = getDbxrefs('biomaterial_dbxref', 'biomaterial_id', $row['biomaterial_id'], $DBConn);
  foreach ($dbxref_rows as $dbxref_row) {
    if ($dbxref_row['accession'] != '') {
//echo "Show accession " . $dbxref_row['accession'] . ", in database " . $dbxref_row['db'] . "<br>";
      switch ($dbxref_row['db']) {
        case 'GenBank:BioSample':
          $url = $dbxref_row['urlprefix'];
          $biosamples = explode(',', $dbxref_row['accession']);
          $tags = array();
          foreach ($biosamples as $s) {
            $s = trim($s);
            $tags[] = "<a href=\"$url$s\">$s<a/>";
          }
          $html = makeRow('GenBank BioSample', implode(", ", $tags));
          $tmpl->get('biosample')->replace($html);
          break;
        case 'PMID':
        case 'DOI':
          $url = $dbxref_row['urlprefix'] . $dbxref_row['accession'];
          $html = "<a href=\"$url\">" . $dbxref_row['db'] . "<a/>&nbsp;&nbsp;";
          $tmpl->get('sample_'.$dbxref_row['db'])->replace($html);
          $tmpl->get('sample_reference')->unmute();
          break;
      }//switch
    }
  }//each dbxref
}//DisplaySampleInformation


function getAnnotationRecords($assembly_name, $DBConn) {
  $recs = array();

/*
  $sql = "
    SELECT a.analysis_id AS annot_id, a.name AS annot, asmbly.name AS assembly 
    FROM chado.analysis a 
      INNER JOIN chado.analysisprop ap ON ap.analysis_id=a.analysis_id
        AND ap.type_id=(SELECT cvterm_id FROM chado.cvterm WHERE name='analysis_type')
      INNER JOIN chado.analysis_relationship ar ON ar.subject_id=a.analysis_id
      INNER JOIN chado.analysis asmbly ON asmbly.analysis_id=ar.object_id
    WHERE ap.value = 'gene model set' AND asmbly.name=" . $DBConn->quote($assembly_name);
*/
  $sql = "
    SELECT a.analysis_id AS annot_id, a.name AS annot, asmbly.name AS assembly, 
       ic.value AS is_current, w.value AS withdrawn
    FROM chado.analysis a 
      INNER JOIN chado.analysisprop ap ON ap.analysis_id=a.analysis_id
        AND ap.type_id=(SELECT cvterm_id FROM chado.cvterm WHERE name='analysis_type')
      INNER JOIN chado.analysis_relationship ar ON ar.subject_id=a.analysis_id
      INNER JOIN chado.analysis asmbly ON asmbly.analysis_id=ar.object_id
      LEFT OUTER JOIN chado.analysisprop ic ON ic.analysis_id=a.analysis_id
        AND ic.type_id=(SELECT cvterm_id FROM chado.cvterm WHERE name='is_current')
      LEFT OUTER JOIN chado.analysisprop w ON w.analysis_id=a.analysis_id
        AND w.type_id=(SELECT cvterm_id FROM chado.cvterm WHERE name='withdrawn')
    WHERE ap.value = 'gene model set' AND asmbly.name=" . $DBConn->quote($assembly_name) . "
    ORDER BY a.name DESC";

  $sth = make_query($DBConn, $sql);
  while ($row=retrieve_row($sth)) {
    array_push($recs, $row);
  }
  
  if (count($recs) > 0) {
    return $recs;
  }
  else {
    return false;
  } 
}//getAnnotationRecords


function getAssemblyName($identifier, $DBConn) {
  $sql = "
    SELECT assembly, assembly_identifier FROM chado.genome_information
    WHERE assembly=" . $DBConn->quote($identifier) . " OR assembly_identifier=" . $DBConn->quote($identifier);
  $sth = make_query($DBConn, $sql);
  if ($row=retrieve_row($sth)) {
    return array('assembly_name' => $row['assembly'], 'assembly_identifier' => $row['assembly_identifier']);
  }
  
  return array('assembly_name' => $identifier, 'assembly_identifier' => '');
}//getAssemblyName


function getDbxrefs($tablename, $id_field, $id, $DBConn) {
  $sql = "
    SELECT d.accession, db.name AS db, db.urlprefix 
    FROM chado.$tablename ed
      INNER JOIN chado.dbxref d ON d.dbxref_id=ed.dbxref_id
      INNER JOIN chado.db ON db.db_id=d.db_id
    WHERE ed.$id_field=$id";
//echo "$sql<br>";
  $dbxref_sth = make_query($DBConn, $sql);
  return get_all_rows($dbxref_sth);
}//getDbxrefs


function getDetailsPage($assembly_name, $DBConn) {
  $sql = "
    SELECT value FROM chado.analysisprop ap
      INNER JOIN chado.analysis a ON a.analysis_id=ap.analysis_id
    WHERE ap.type_id=(SELECT cvterm_id FROM chado.cvterm 
                      WHERE name='details_page'
                            AND cv_id=(SELECT cv_id FROM chado.cv WHERE name='maizegdb'))
          AND a.name=" . $DBConn->quote($assembly_name);
  $sth = make_query($DBConn, $sql);
  if ($row=retrieve_row($sth)) {
    if ($row['value'] != '') {
      return $row['value'];
    }
  }
  
  return false;
}//getDetailsPage


function getProperty($tablename, $id_field, $id, $property, $cv, $DBConn) {
  $sql = "
      SELECT p.*, t.name AS property FROM chado.$tablename p
        INNER JOIN chado.cvterm t ON t.cvterm_id=p.type_id
      WHERE p.$id_field=$id 
            AND p.type_id=(SELECT cvterm_id FROM chado.cvterm 
                           WHERE name=" . $DBConn->quote($property) . "
                                 AND cv_id=(SELECT cv_id FROM chado.cv 
                                            WHERE name=" . $DBConn->quote($cv) . "))";
//echo "$sql<br>";
  $sth = make_query($DBConn, $sql);
  return retrieve_row($sth);
}//getProperty


function getProperties($tablename, $id_field, $id, $DBConn) {
  $sql = "
      SELECT p.*, t.name AS property FROM chado.$tablename p
        INNER JOIN chado.cvterm t ON t.cvterm_id=p.type_id
      WHERE p.$id_field=$id";
//echo "$sql<br>";
  $sth = make_query($DBConn, $sql);
  return get_all_rows($sth);
}//getProperties


function getURLPrefix($dbname, $DBConn) {
  $sql = "SELECT urlprefix FROM chado.db WHERE name=" . $DBConn->quote($dbname);
  $sth = make_query($DBConn, $sql);
  if ($row=retrieve_row($sth)) {
    return $row['urlprefix'];
  }
  
  return false;
}//getURLPrefix


function loadAssemblyMetadata($subtmpl, $names, $DBConn) {
  $subtmpl->get('assembly-name')->replace($names['assembly_name']);
  $subtmpl->get('assembly-identifier')->replace($names['assembly_identifier']);

  $sql = "
    SELECT DISTINCT gm.*, b.name AS sample_name, b.description AS sample_description,
           CONCAT(o.genus, ' ', o.species, ' ', o.infraspecific_name) AS species, 
           ap.value AS replaced_with 
    FROM chado.genome_metadata gm
      INNER JOIN chado.biomaterial b ON b.biomaterial_id=gm.biomaterial_id
      INNER JOIN chado.organism o ON o.organism_id=b.taxon_id
      LEFT OUTER JOIN chado.analysisprop ap ON ap.analysis_id=gm.analysis_id
        AND ap.type_id = (SELECT cvterm_id FROM chado.cvterm WHERE name='replaced_with')
    WHERE assembly_name='" . $names['assembly_name'] . "'";
  $sth = make_query($DBConn, $sql);
  if ($row = retrieve_row($sth)) {
    $subtmpl->get('found')->unmute();
  }
  else {
    $subtmpl->get('not_found')->unmute();
    return;
  }
  
  if (isset($row['replaced_with']) && $row['replaced_with'] != '') {
    $subtmpl->get('replaced-with-assembly')->replace($row['replaced_with']);
     $subtmpl->get('replaced-with')->unmute();
  }

  DisplayProjectInformation($subtmpl, $row, $DBConn);
  DisplaySampleInformation($subtmpl, $row, $DBConn);
  DisplayAssemblyInformation($subtmpl, $row, $DBConn);
  DisplayAnnotationInformation($subtmpl, $row, $DBConn);
}//loadAssemblyMetadata


function makeRow($header, $value) {
  // Header is likely to be metadata ontology term; make it look like a header
  $header = ucfirst(str_replace('_', ' ', $header));
  
  // May have to do some cleanup on the $value
  $value = cleanString($value);
  
  $html = "
    <tr>
      <td>&nbsp;&nbsp;</td>
      <td align=\"right\" valign=\"top\"><b class=\"nowrap\">$header</b>&nbsp;&nbsp;</td>
      <td width='100%'>$value</td>
    </tr>";
  
  return $html;
}//makeRow


function setAwardHTML($row, $award_prop) {
  $award = '';
  if ($award_prop != '' && isset($row['award_name'])) {
    $award = $row['award_name'] . " $award_prop";
  }
  else if ($award_prop != '') {
    $award = $award_prop;
  }
  else if (isset($row['award_name'])) {
    $award = $row['award_name'];
  }
  
  if (isset($row['award_url'])) {
    return "<a href='" . $row['award_url'] . "'>$award</a>";
  }
  else {
    return $award;
  }
  
  return $award;
}//setAwardHTML


function showBrowser($tmpl, $assembly_name, $DBConn) {
  $sql = "
    SELECT browser FROM chado.genome_metadata 
    WHERE assembly_name=" . $DBConn->quote($assembly_name);
  $sth = make_query($DBConn, $sql);
  if ($row = retrieve_row($sth)) {
    if (isset($row['browser']) && $row['browser'] != '') {
      if (preg_match("/gbrowse/", $row['browser'])) {
        $browser_link = preg_replace("/.*(\/gbrowse.*)/", "$1", $row['browser']);
        $browser_link = "https://gbrowse.maizegdb.org/gb2$browser_link";
      }
      else {
        $browser_link = $row['browser'];
      }
      $tmpl->get('browser-link')->replace($browser_link);
      $tmpl->get('main_section_browser')->unmute();
      return;
    }
  }
  
  $tmpl->get('no-browser')->unmute();
}//showBrowser


function TorontoAgreementRow() {
  $text = "
    This sequence has been released under the 
    <a href=\"https://dx.doi.org/10.1038/461168a\">Toronto Agreement</a>. 
    No whole-genome or whole-annotation research
    may be submitted for publication until the official publication for this genome 
    assembly and/or annotation has been published.";
  $html = "<td>&nbsp;&nbsp;</td><td colspan=2><b>$text</b><br><br></td>";
  
  return $html;
}//TorontoAgreementRow

?>
