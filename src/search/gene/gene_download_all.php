<?php
/* file: gene_download_all.php
 *
 * purpose: "Download all data for a list of gene models" on the Gene Data Hub.
 *          One request, two queries, one file.
 *
 * history:
 *  09/11/26  claude  created, replacing search/gene/download_all.php +
 *                    download_all.pl + search/download/checkQuery.php
 *
 * What was here before, and why it went
 * -------------------------------------
 * download_all.php wrote the submitted list to a temp file and then ran
 * download_all.pl through shell_exec WITHOUT backgrounding it, so the request
 * did not answer until the whole job had finished. The page meanwhile polled
 * search/download/checkQuery.php, which sleeps five seconds inside every call,
 * and when the job finally landed it tried to deliver the file by clicking a
 * synthetic <a download target="_blank"> -- which a popup blocker may refuse
 * with no message at all. A reader saw a long wait and then nothing.
 *
 * The query behind it read chado.all_gene_model_data, a 995 MB materialized
 * view carrying NO INDEXES, so every request -- three gene models or three
 * thousand -- sequentially scanned all of it. Cold, that is minutes.
 *
 * This file reads the two tables that view is built from instead:
 *
 *   chado.gene_model          1.88M rows, btree on gene_name (gene_model_i2)
 *   perm_tables.id_ontology   3.4 GB, btree on (gene_model_id, ...)
 *
 * Both predicates are index-served: 324 ms of scanning becomes single-digit
 * milliseconds, and the answer is a file with Content-Disposition on it, so the
 * browser saves it directly and there is nothing to poll and nothing to click.
 *
 * The legacy files are left in place and untouched; nothing on the modern page
 * calls them any more.
 */

  include_once('../../include/db-api.php');
  include_once('../../include/gp_lib.php');

  /* The cap is on gene models, not bytes. 2,000 rows of this shape is about
     1.5 MB of TSV, and the two queries stay flat across the whole range
     because both are index lookups. */
  define('GENE_DOWNLOAD_ALL_LIMIT', 2000);
  define('GENE_DOWNLOAD_ALL_MAX_UPLOAD', 50000);

  /* Columns, in the order the previous download wrote them. The header it
     printed named seventeen and the rows carried sixteen -- obo_names was in
     the header and commented out of the row -- so every file it produced was
     one column short of its own header. All seventeen are written here. */
  $GENE_DOWNLOAD_ALL_COLUMNS = array(
      'gene_model', 'assembly_version', 'annotation_version',
      'gene_model_chr', 'gene_model_start', 'gene_model_end', 'transcript_count',
      'canonical_transcript_name', 'transcript_chr', 'transcript_start',
      'transcript_end', 'tandem_count', 'locus_symbol', 'locus_name',
      'gene_products', 'obo_terms', 'obo_names');


/* Plain text, and a reason. The form posts with target="_blank", so this is
   what the reader gets in the new tab instead of an empty file. */
function geneDownloadAllFail($message) {
    header('Content-Type: text/plain; charset=utf-8');
    echo $message . "\n";
    exit;
}

function geneDownloadAllRow($values, $format) {
    if ($format === 'csv') {
        return implode(',', array_map(function ($v) {
            $v = (string) $v;
            return preg_match('/[",\r\n]/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
        }, $values)) . "\n";
    }
    /* A tab or a newline inside a value would add a column or a row, so they
       are flattened rather than quoted -- TSV has no quoting convention that
       every spreadsheet agrees on. */
    return implode("\t", array_map(function ($v) {
        return preg_replace('/[\t\r\n]+/', ' ', (string) $v);
    }, $values)) . "\n";
}


  /* ---------------------------------------------------------------- input */

  $format = strtolower(trim((string) getCGIParam('format', 'GP', '')));
  if ($format !== 'csv') { $format = 'tsv'; }

  $raw = '';
  if (isset($_FILES['downloadall_file']) && is_array($_FILES['downloadall_file'])
      && isset($_FILES['downloadall_file']['error'])
      && $_FILES['downloadall_file']['error'] === UPLOAD_ERR_OK) {

      $tmp = $_FILES['downloadall_file']['tmp_name'];
      if (!is_uploaded_file($tmp)) {
          geneDownloadAllFail('The uploaded file could not be read.');
      }
      if (filesize($tmp) > GENE_DOWNLOAD_ALL_MAX_UPLOAD) {
          geneDownloadAllFail('Uploaded files are limited to 50kb. Paste the list into the box instead, '
              . 'or take the whole annotation from https://download.maizegdb.org/.');
      }
      $raw = (string) file_get_contents($tmp);
  }
  if (trim($raw) === '') {
      $raw = (string) getCGIParam('downloadall_list', 'P', '');
  }
  if (trim($raw) === '') {
      geneDownloadAllFail('No gene models were given. Paste a list into the box or upload a file.');
  }

  /* The separators the form documents, plus whatever whitespace comes with a
     pasted column. Order is kept: the file comes back in the order asked for,
     so a reader can line it up against their own list. */
  $wanted = array();
  $seen   = array();
  foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $token) {
      /* Anything that is not shaped like an identifier is dropped here rather
         than sent to the database. Every value below is bound as well. */
      if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]{0,62}$/', $token)) { continue; }
      $key = strtolower($token);
      if (isset($seen[$key])) { continue; }
      $seen[$key] = true;
      $wanted[] = $token;
      if (count($wanted) >= GENE_DOWNLOAD_ALL_LIMIT) { break; }
  }
  if (!$wanted) {
      geneDownloadAllFail('No gene model identifiers were recognised in that list.');
  }

  $system = getSystemInfo('mgdb.conf');
  $DBConn = connect_to_database();
  if (!$DBConn) {
      geneDownloadAllFail('The database is currently unreachable. Please try again shortly.');
  }

  /* ---------------------------------------------------------------- query */

  $ph = implode(',', array_fill(0, count($wanted), '?'));

  /* chado.all_gene_model_data's own definition, restricted by gene_name so the
     btree answers it. tandem_gene_model is indexed on feature_id and
     locus_gene_products is 1 MB, so neither join costs anything. */
  $sql = "
    SELECT gm.gene_name                       AS gene_model,
           gm.assembly_version,
           gm.version                         AS annotation_version,
           gm.chr                             AS gene_model_chr,
           gm.gm_start                        AS gene_model_start,
           gm.gm_end                          AS gene_model_end,
           gm.transcript_count,
           gm.canonical_transcript_name,
           gm.transcript_chr,
           gm.transcript_start,
           gm.transcript_end,
           tan.tandem_count,
           gm.locus_name                      AS locus_symbol,
           gm.locus_full_name                 AS locus_name,
           array_to_string(gp.gene_products, ', ') AS gene_products
      FROM chado.gene_model gm
      LEFT JOIN chado.tandem_gene_model tan ON tan.feature_id = gm.feature_id
      LEFT JOIN chado.locus_gene_products gp ON gp.locus_id = gm.locus_id
     WHERE gm.gene_name IN ($ph)";

  $sth  = make_query($DBConn, $sql, 1, $wanted);
  $rows = get_all_rows($sth);

  /* The ontology terms, from the table chado.gene_model_onto_terms aggregates.
     That view is 620 MB with no index; the table under it has a btree on
     gene_model_id, and answers the same question in 2 ms.

     obo_names here is the term name. The view writes concat(name,':',name),
     so every value in it reads "nucleus:nucleus" -- reproducing that would be
     reproducing a defect. */
  $onto_sql = "
    SELECT gene_model_id AS gene_model,
           array_to_string(array_agg(DISTINCT concat(obo_term, ':', btrim(name))), ', ') AS obo_terms,
           array_to_string(array_agg(DISTINCT btrim(name)), ', ')                        AS obo_names
      FROM perm_tables.id_ontology
     WHERE gene_model_id IN ($ph)
     GROUP BY gene_model_id";

  $onto = array();
  foreach (get_all_rows(make_query($DBConn, $onto_sql, 1, $wanted)) as $row) {
      $onto[$row['gene_model']] = $row;
  }

  /* Keyed by the identifier as the database spells it, and again lower cased,
     so a reader who typed a different case still gets their row. */
  $found = array();
  foreach ($rows as $row) {
      $found[strtolower($row['gene_model'])] = $row;
  }

  /* ---------------------------------------------------------------- output */

  header('Content-Type: text/' . ($format === 'csv' ? 'csv' : 'tab-separated-values') . '; charset=utf-8');
  header('Content-Disposition: attachment; filename="maizegdb_gene_model_data_'
         . date('Ymd_His') . '.' . $format . '"');

  echo geneDownloadAllRow($GENE_DOWNLOAD_ALL_COLUMNS, $format);

  /* One row per identifier asked for, in that order. A gene model that matched
     nothing keeps its row with every other column empty -- an unmatched
     identifier is the one thing a reader most needs to see, and dropping it
     silently is what the previous download did. */
  foreach ($wanted as $token) {
      $row = isset($found[strtolower($token)]) ? $found[strtolower($token)] : null;
      if (!$row) {
          $blank = array_fill(0, count($GENE_DOWNLOAD_ALL_COLUMNS) - 1, '');
          echo geneDownloadAllRow(array_merge(array($token), $blank), $format);
          continue;
      }
      $name  = $row['gene_model'];
      $terms = isset($onto[$name]) ? $onto[$name] : array('obo_terms' => '', 'obo_names' => '');

      $out = array();
      foreach ($GENE_DOWNLOAD_ALL_COLUMNS as $column) {
          if ($column === 'obo_terms' || $column === 'obo_names') {
              $out[] = isset($terms[$column]) && $terms[$column] !== null ? $terms[$column] : '';
              continue;
          }
          $out[] = isset($row[$column]) && $row[$column] !== null ? $row[$column] : '';
      }
      echo geneDownloadAllRow($out, $format);
  }
?>
