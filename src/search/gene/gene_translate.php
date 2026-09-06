<?php
/* file: gene_translate.php
 *
 * purpose: translate a list of gene IDs from one gene set to another.
 *
 * history:
 *  11/29/16  eksc  created
 *  09/06/26  claude  added TSV and CSV output alongside the plain-text view.
 */

  include_once("../../include/db-api.php");
  include_once("../../include/gp_lib.php");

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');
  
  // gene_search_functions.php depends on $system, so load it here
  include_once("../../include/gene_center_lib.php");
  include_once("gene_search_functions.php");

  $DBConn = connect_to_database();
  
  $gm_list    = getCGIParam('gm_list', 'GP', false);
  $trans_from = validate_input($DBConn, getCGIParam('trans_from', 'GP', false));
  $trans_to   = validate_input($DBConn, getCGIParam('trans_to', 'GP', false));
//logMessage("Translate from $trans_from to $trans_to");

  /* Same rows, three ways out. `view` is the plain text this has always
     returned; the two download formats attach a filename so the table can go
     straight into a spreadsheet. */
  $format = strtolower(trim((string) getCGIParam('format', 'GP', false)));
  if ($format !== 'tsv' && $format !== 'csv') { $format = 'view'; }

  if ($format === 'view') {
    header('Content-type: text/plain');
  }
  else {
    header('Content-Type: text/' . ($format === 'csv' ? 'csv' : 'tab-separated-values') . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="maizegdb-translated-gene-models.' . $format . '"');
  }

  /* RFC 4180 quoting for CSV, and for TSV any stray tab or newline in a value
     flattened so it cannot break the column count. A translation cell holds a
     comma-separated list, so CSV without quoting would split one column into
     several. */
  function translate_row($values, $format) {
    if ($format === 'csv') {
      return implode(',', array_map(function ($v) {
        $v = (string) $v;
        return preg_match('/[",\r\n]/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
      }, $values)) . "\n";
    }
    return implode("\t", array_map(function ($v) {
      return preg_replace('/[\t\r\n]+/', ' ', (string) $v);
    }, $values)) . "\n";
  }

  // Standardize input sequences
  $gm_list = preg_replace("/\s+/",    ',', $gm_list);
  $gm_list = preg_replace("/,+/",     ',', $gm_list);
  $gm_list = preg_replace("/;/",      ',', $gm_list);
  $gm_list = preg_replace("/[\n\r]/", ',', $gm_list);
  
  $gms = explode(',', $gm_list);
  
  // May have to translate from GenBank identifiers
  $gms = translateFromGenBankIDs($DBConn, $gms);
  
  switch ($trans_to) {
    case 'all':
      $translations = translateToAllAnnotations($DBConn, $gms);
      break;
    case 'B73':
      $translations = translateToAllB73Annotations($DBConn, $gms);
      break;
    case 'NAM':
      $translations = translateToAllNAMAnnotations($DBConn, $gms);
      break;
    case 'genbank_gene':
      $translations = translateToEntrez($DBConn, $gms);
      break;
    case 'genbank_id':
      $translations = translateToGenBankIDs($DBConn, $gms);
      break;
    default:
      $translations = translateToGeneSet($DBConn, $gms, $trans_to);
  }//switch
  
  printTranslations($trans_to, $translations, $format);



//////////////////////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////

function createEmptyArray($gms, $set_names) {
  $translations = array();
  
  // Add empty rows for gene models that lack translations
  foreach ($gms as $gm) {
    if (!isset($translations[$gm])) {
      $translations[$gm] = array();
    }
    foreach ($set_names as $s) {
      if (!isset($translations[$gm][$s])) {
        $translations[$gm][$s] = array();
      }
    }
  }
  
  return $translations;
}//createEmptyArray


function getTranslationTarget($trans_to) {
  switch ($trans_to) {
    case 'all':
      return 'all gene model sets';
    case 'NAM':
      return 'all NAM founders';
    case 'B73':
      return 'all B73 gene model sets';
    default:
      return $trans_to;
  }//switch
}//getTranslationTarget


function mergeTranslations($set1, $set2) {
  if (!$set2 || count($set2) == 0) {
    return $set1;
  }
  
  $translations = $set1;
  
  $found_gms = array();
  $headers1 = false;
  $headers2 = false;
  foreach (array_keys($set2) as $gm) {
    if (!$headers1) {
      $headers1 = array_keys($set1[$gm]);
      $headers2 = array_keys($set2[$gm]);
    }
    
    if (!$translations[$gm]) {
      $translations[$gm] = array();
    }
    
    // Make sure each header in the first set is accounted for
    foreach ($headers1 as $h) {
      $translations[$gm][$h] = (isset($set1[$gm][$h])) ? $set1[$gm][$h] : '';
    }
    
    // Make sure each header in the second set is accounted for
    foreach ($headers2 as $h) {
      $translations[$gm][$h] = (isset($set2[$gm][$h])) ? $set2[$gm][$h] : '';
    }
  }//each gene model

  return $translations;
}//mergeTranslations


function printTranslations($trans_to, $translations, $format) {
  $target = getTranslationTarget($trans_to);
  
  if (!$translations) {
    echo "Unable to find any translations for $target\n";
    return;
  }
  
  $heads = false;
  foreach (array_keys($translations) as $gm) {
    if (!$heads) {
      $heads = array_keys($translations[$gm]);
      echo translate_row(array_merge(array('input'), $heads), $format);
    }
    
    // Each record may need addition processing before printing
    foreach (array_keys($translations[$gm]) as $set) {
      if (is_array($translations[$gm][$set])) {
        // make comma-separated list of unique gene model names
        $translations[$gm][$set] = array_unique($translations[$gm][$set]);
        sort($translations[$gm][$set], SORT_NATURAL);
        $translations[$gm][$set] = implode(',', $translations[$gm][$set]);
      }
    }
    echo translate_row(array_merge(array($gm), array_values($translations[$gm])), $format);
  }
}//printTranslations


function translateFromGenBankIDs($DBConn, $ids) {
  $id_list = mgdb_quote_list($DBConn, $ids);
    
  $sql = "
    SELECT gene_name
    FROM chado.gene_model
    WHERE genbank_name IN ($id_list) 
          OR old_genbank_name IN ($id_list)
          OR transcript_acc IN ($id_list)";
  $sth = make_query($DBConn, $sql);
  if ((!$rows = get_all_rows($sth))) {
    // Assume input ids were gene model names
    return $ids;
  }
  
  $gms = array();
  foreach ($rows as $r) {
    $gms[] = $r['gene_name'];
  }
  
  return $gms;
}//translateFromGenBankIDs


function translateToEntrez($DBConn, $gms) {
  $gm_list = mgdb_quote_list($DBConn, $gms);
  
  $sql = "
    SELECT f.name, x.accession
    FROM chado.feature f
      LEFT JOIN chado.feature_dbxref fd ON fd.feature_id=f.feature_id
      LEFT JOIN chado.dbxref x ON x.dbxref_id=fd.dbxref_id
      LEFT JOIN chado.db ON db.db_id=x.db_id
    WHERE db.name='GenBank:Entrez Gene'
      AND f.name IN ($gm_list)";
  $sth = make_query($DBConn, $sql);
  if ((!$rows = get_all_rows($sth))) {
    return false;
  }
  else {
    $translations = array();
    if ($rows) {
      foreach ($rows as $row) {
        $translations[$row['name']]['GenBank Gene'] = $row['accession'];
      }
    }
  
//    $translations = fillArray($gms, $translations);
    
    return $translations;
  }
}//translateToEntrez


function translateToAllAnnotations($DBConn, $gms) {
  $sets = getGeneModelSets($DBConn);
  $set_names = array();
  foreach ($sets as $s) {
    $set_names[] = $s['name'];
  }

  return translateToGeneSets($DBConn, $gms, $set_names, true);
}//translateToAllAnnotations


function translateToAllB73Annotations($DBConn, $gms) {
  $set_names = array('4b', '5b', '5b+', 'Zm00001d.2', 'Zm00001eb.1');

  return translateToGeneSets($DBConn, $gms, $set_names, false);
}//translateToAllB73Annotations


function translateToAllNAMAnnotations($DBConn, $gms) {
  $set_names = array('Zm00001eb.1', 'Zm00018ab.1', 'Zm00019ab.1', 'Zm00020ab.1', 
                     'Zm00021ab.1', 'Zm00022ab.1', 'Zm00023ab.1', 'Zm00024ab.1', 
                     'Zm00025ab.1', 'Zm00026ab.1', 'Zm00027ab.1', 'Zm00028ab.1', 
                     'Zm00029ab.1', 'Zm00030ab.1', 'Zm00031ab.1', 'Zm00032ab.1', 
                     'Zm00033ab.1', 'Zm00034ab.1', 'Zm00035ab.1', 'Zm00036ab.1', 
                     'Zm00037ab.1', 'Zm00038ab.1', 'Zm00039ab.1', 'Zm00040ab.1', 
                     'Zm00041ab.1', 'Zm00042ab.1');

  return translateToGeneSets($DBConn, $gms, $set_names, true);
}//translateToAllB73Annotations


function translateToGenBankIDs($DBConn, $gms) {
  $gm_list = mgdb_quote_list($DBConn, $gms);
  
  $sql = "
    SELECT transcript_acc, genbank_name FROM chado.gene_model
    WHERE gene_name IN ($gm_list)";
  $sth = make_query($DBConn, $sql);
  return get_all_rows($sth);
}//translateToGenBankIDs


function translateToGeneSet($DBConn, $gms, $trans_to) {
  $gm_list = mgdb_quote_list($DBConn, $gms);
//logVarDump($gms, "Translate this gene models to $trans_to");
  
  $translations = array();
  
  $sql = "
    SELECT f.feature_id, f.name, ARRAY_AGG(DISTINCT gm.gene_name) AS translation_list
    FROM chado.feature f
      INNER JOIN chado.gene_set_member gsm ON gsm.gene_model_id=f.feature_id
      INNER JOIN (
        -- inner join with all members that are not in the search list
        SELECT gs.gene_set_id, g.gene_model_id
        FROM chado.gene_set gs
          INNER JOIN chado.gene_set_member g ON g.gene_set_id=gs.gene_set_id
          INNER JOIN chado.gene_model gm ON gm.feature_id=g.gene_model_id
        ) m ON m.gene_set_id=gsm.gene_set_id AND m.gene_model_id!=f.feature_id
      INNER JOIN chado.gene_model gm ON gm.feature_id=m.gene_model_id
    WHERE f.name in ($gm_list)
          AND gm.version=" . $DBConn->quote($trans_to) . "
    GROUP BY f.feature_id, f.name";
//logMessage("\n$sql\n");
  $sth = make_query($DBConn, $sql);
  while (($row=retrieve_row($sth))) {
    $translation_list = $row['translation_list'];
    $translation_list = substr($translation_list, 1, strlen($translation_list)-2);
    $translations[$row['name']] = explode(',', $translation_list);
  }

  //-- check for hand-curated associations via shared locus
  //-- associations via shared locus name
  
  return $translations;
}//translateToGeneSet


function translateToGeneSets($DBConn, $gms, $set_names, $use_pan_gene=true) {
  $translations = createEmptyArray($gms, $set_names);
  $gm_list = mgdb_quote_list($DBConn, $gms);
  $sets_list = mgdb_quote_list($DBConn, $set_names);
  
  $sql = "
    SELECT f.feature_id, f.name, gm.version, ARRAY_AGG(DISTINCT gm.gene_name) AS translation_list
    FROM chado.feature f
      INNER JOIN chado.gene_set_member gsm ON gsm.gene_model_id=f.feature_id
      INNER JOIN (
        SELECT gs.gene_set_id, g.gene_model_id
        FROM chado.gene_set gs
          INNER JOIN chado.gene_set_member g ON g.gene_set_id=gs.gene_set_id
          INNER JOIN chado.gene_model gm ON gm.feature_id=g.gene_model_id
        ) m ON m.gene_set_id=gsm.gene_set_id AND m.gene_model_id!=f.feature_id
      INNER JOIN chado.gene_model gm ON gm.feature_id=m.gene_model_id
    WHERE f.name in ($gm_list)
          AND gm.version IN ($sets_list)
    GROUP BY f.feature_id, f.name, gm.version";
logMessage("\n$sql\n");
  $sth = make_query($DBConn, $sql);
  while (($row=retrieve_row($sth))) {
    $translation_list = $row['translation_list'];
    $translation_list = substr($translation_list, 1, strlen($translation_list)-2);
    $translations[$row['name']][$row['version']] = explode(',', $translation_list);
  }
  
  return $translations;
}//translateToGeneSets


?>
