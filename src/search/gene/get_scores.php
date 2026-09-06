<?php
 /* file: get_scores.php
  *
  * purpose: retrieve scores for a list of gene models.
  *
  * history:
  *  04/21/26  eksc  created
  *  09/06/26  claude  adopted into the repository; added the four protein
  *                    structure scores, TSV and CSV output, and parameterized
  *                    the gene model list.
  */
  
  include_once('../../include/db-api.php');
  include_once('../../include/gp_lib.php');
  include_once("../../include/gene_center_lib.php");
  
  // Get system configuration
  $system = getSystemInfo('mgdb.conf');
logMessage("Starting get_scores.php");

  $DBConn = connect_to_database();

  $gm_list        = getCGIParam('bulk_score_gms', 'GP', false);  
logMessage("gene models:\n$gm_list");

  // Standardize input sequences
  $gm_list = preg_replace("/\s+/",    ',', $gm_list);
  $gm_list = preg_replace("/;/",      ',', $gm_list);
  $gm_list = preg_replace("/[\n\r]/", ',', $gm_list);
  
  $gms = explode(',', $gm_list);
  
  /* Three ways out of here, all the same rows. `view` is what the card has
     always done -- plain text in a new tab -- and the two download formats are
     the same table with a filename attached, so a reader who wants the numbers
     in a spreadsheet does not have to copy them out of a browser window. */
  $format = strtolower(trim((string) getCGIParam('format', 'GP', false)));
  if ($format !== 'tsv' && $format !== 'csv') { $format = 'view'; }

  if ($format === 'view') {
    header('Content-type: text/plain');
  }
  else {
    header('Content-Type: text/' . ($format === 'csv' ? 'csv' : 'tab-separated-values') . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="maizegdb-gene-model-scores.' . $format . '"');
  }

  /* One row printer for all three formats. RFC 4180 quoting for CSV: a field
     that contains a comma, a quote or a newline is quoted and its quotes
     doubled. TSV keeps the tab-separated shape this endpoint already returned,
     with any stray tab or newline in a value flattened rather than allowed to
     break the column count. */
  function score_row($values, $format) {
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
  
  $scores = array();
  $sql = "
    SELECT DISTINCT tr.transcript_name, a.name AS score_analysis, 
                    af.rawscore AS score, afp.value AS score_type
    FROM chado.transcript tr
      INNER JOIN chado.analysisfeature af ON af.feature_id=tr.transcript_id
      INNER JOIN chado.analysisfeatureprop afp ON afp.analysisfeature_id=af.analysisfeature_id
      INNER JOIN chado.analysis a ON a.analysis_id=af.analysis_id
      LEFT OUTER JOIN chado.analysisprop ap ON ap.analysis_id=a.analysis_id
        AND ap.type_id=2373 AND ap.value='gene model score'
    WHERE tr.gene_name = ANY(:gms)
    ORDER BY tr.transcript_name, a.name";
  /* Bound as one array rather than interpolated. The list comes from a textarea,
     and `implode("','", $gms)` put it straight into the statement -- a single
     quote in the box was all it took to end the string and start writing SQL. */
  $sth = $DBConn->prepare($sql);
  $sth->execute(array(':gms' => '{' . implode(',', array_map(function ($g) {
    return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), trim($g)) . '"';
  }, $gms)) . '}'));
  while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
    $score_type = $row['score_analysis'] . ':' . $row['score_type'];
    if ($score_type == 'pSAURON:is_protein') {
      $scores[$row['transcript_name']][$score_type] = ($row['score'] == 1) 
                                                    ? 'yes' : 'no';
    }
    else {
      $scores[$row['transcript_name']][$score_type] = $row['score'];
    }
  }//each row

  // AED was an early recorded score, which was attached differently
  $sql = "
    SELECT tr.gene_name, tr.transcript_name, fp.value AS score
    FROM chado.transcript tr
      INNER JOIN chado.featureprop fp ON fp.feature_id=tr.transcript_id
        AND fp.type_id=(SELECT cvterm_id FROM chado.cvterm WHERE name='AED_score')
    WHERE tr.gene_name = ANY(:gms)
    ORDER BY tr.transcript_name";
  $sth = $DBConn->prepare($sql);
  $sth->execute(array(':gms' => '{' . implode(',', array_map(function ($g) {
    return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), trim($g)) . '"';
  }, $gms)) . '}'));
  while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
    $score_type = 'AED';
    $scores[$row['transcript_name']][$score_type] = $row['score'];
  }//each row
logVarDump($scores, "Accumulated scores");

  /* The four protein structure scores were already coming back from the query
     above -- they hang off the same mRNA feature as reelGene and pSAURON, and
     nothing filtered them out -- they were simply never printed. Named here in
     the order a reader reads them: how the model scores as a gene, then what
     its predicted protein looks like.

     Column           analysis:analysisfeatureprop value
     AlphaFold pLDDT  AlphaFold2:ALPHAFOLD2_AVERAGE_pLDDT
     ESMFold pLDDT    ESMFold:ESMFOLD_AVERAGE_pLDDT
     IUPred2          IUPred2A:IUPRED2_PERCENT_GREATER_EQUAL_TO_0.5
     ANCHOR2          IUPred2A:ANCHOR2_PERCENT_GREATER_EQUAL_TO_0.5 */
  $columns = array(
    'transcript'                   => null,
    'AED'                          => 'AED',
    'reelGene:exon score'          => 'reelGene:ExonScore',
    'reelGene:protein score'       => 'reelGene:ProteinScore',
    'reelGene:average'             => 'reelGene:Average',
    'pSAURON:is_protein'           => 'pSAURON:is_protein',
    'pSAURON:in_frame_score'       => 'pSAURON:in_frame_score',
    'AlphaFold2:average pLDDT'     => 'AlphaFold2:ALPHAFOLD2_AVERAGE_pLDDT',
    'ESMFold:average pLDDT'        => 'ESMFold:ESMFOLD_AVERAGE_pLDDT',
    'IUPred2:percent disordered'   => 'IUPred2A:IUPRED2_PERCENT_GREATER_EQUAL_TO_0.5',
    'ANCHOR2:percent binding'      => 'IUPred2A:ANCHOR2_PERCENT_GREATER_EQUAL_TO_0.5',
  );

  echo score_row(array_keys($columns), $format);
  foreach (array_keys($scores) as $tr) {
    $row = array();
    foreach ($columns as $header => $key) {
      $row[] = ($key === null) ? $tr
             : (isset($scores[$tr][$key]) ? $scores[$tr][$key] : '');
    }
    echo score_row($row, $format);
  }//each score
  
  /* The sentinel belongs to the plain-text view -- the page watches for it to
     know the run finished. A .tsv or .csv opened in a spreadsheet would read it
     as three blank rows and a stray cell. */
  if ($format === 'view') { echo "\n\n\nCOMPLETE\n"; }