<?php
/* file: tools/expression_tools_export.php
 *
 * purpose: pull the two database tables Expression Tools needs out of
 *          Postgres, once, into gzipped TSV files that
 *          tools/expression_tools_index.py then reads. The page itself never
 *          queries the database; everything it serves comes from the files the
 *          index builder writes.
 *
 *            pan_genes.tsv.gz        pan_gene, member, exemplar, chr
 *                                    chado.pan_gene: every member of every
 *                                    Pan-Zea pan-gene, all 66 annotations
 *                                    (2,280,526 rows on 2026-09-24, 5 s)
 *            go_annotations.tsv.gz   annotation, gene, term
 *                                    perm_tables.id_ontology, GO terms only,
 *                                    distinct per gene (4,673,171 rows, 13 s)
 *
 *          Both are read through a server-side cursor in 50,000-row batches:
 *          PDO's pgsql driver buffers a whole result set otherwise, and the
 *          pan-gene table alone is 141 MB of text.
 *
 * Running it
 * ----------
 *   cd /var/www/claude/html
 *   php tools/expression_tools_export.php --dest /var/www/claude/expression_tools_src
 *
 * It has to run from the web root: getSystemInfoFile() walks up from getcwd()
 * to find conf/, and the credentials stay there. The destination is outside
 * the document root on purpose -- these are raw exports, not a served payload.
 * Warnings about HTTP_HOST are harmless under the CLI.
 *
 * history:
 *  09/24/26  claude  created
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit("This script is a command-line tool.\n");
}

ini_set('display_errors', 'stderr');
include_once('./include/gp_lib.php');
include_once('./include/db-api.php');

$opts = getopt('', array('dest:', 'only:'));
$dest = isset($opts['dest']) ? rtrim($opts['dest'], '/') : '';
if ($dest === '' || !is_dir($dest) || !is_writable($dest)) {
    fwrite(STDERR, "usage: php tools/expression_tools_export.php --dest <writable directory outside the web root>\n");
    exit(2);
}
$only = isset($opts['only']) ? $opts['only'] : null;

$DBConn = connect_to_database(false);
if (!$DBConn) {
    fwrite(STDERR, "could not connect to the database\n");
    exit(1);
}
$DBConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* One cursor, written straight to a gzip stream. The file is written under a
   temporary name and renamed, so a failed run never leaves a truncated export
   where the builder would read it. */
function et_export($DBConn, $sql, $columns, $path) {
    $t0 = microtime(true);
    $tmp = $path . '.part';
    $gz = gzopen($tmp, 'wb6');
    if ($gz === false) { throw new RuntimeException('cannot write ' . $tmp); }
    gzwrite($gz, implode("\t", $columns) . "\n");
    $DBConn->beginTransaction();
    $DBConn->exec('DECLARE et_cur NO SCROLL CURSOR FOR ' . $sql);
    $rows = 0;
    while (true) {
        $batch = $DBConn->query('FETCH 50000 FROM et_cur')->fetchAll(PDO::FETCH_NUM);
        if (count($batch) === 0) { break; }
        $buf = '';
        foreach ($batch as $r) {
            foreach ($r as $i => $v) {
                /* Tabs and newlines inside a value would shift every column
                   after them; none are expected in identifiers, so collapse
                   rather than escape. */
                $r[$i] = $v === null ? '' : str_replace(array("\t", "\n", "\r"), ' ', trim((string) $v));
            }
            $buf .= implode("\t", $r) . "\n";
            $rows++;
        }
        gzwrite($gz, $buf);
    }
    $DBConn->exec('CLOSE et_cur');
    $DBConn->commit();
    gzclose($gz);
    rename($tmp, $path);
    fwrite(STDERR, sprintf("%s: %s rows in %.1f s\n", basename($path), number_format($rows), microtime(true) - $t0));
    return $rows;
}

$counts = array();

if ($only === null || $only === 'pan_genes') {
    /* A member is the gene model, or -- where the pan-gene analysis kept a
       gene the annotation load did not -- the "additional" gene model name.
       The same COALESCE the pan-gene record's members CTE uses, so the tool
       and the record count the same members. */
    $counts['pan_genes'] = et_export($DBConn, "
        SELECT DISTINCT pan_gene_name,
               COALESCE(NULLIF(TRIM(gene_model_name), ''), TRIM(additional_gene_model_name)) AS member,
               exemplar_gene_model, chr
        FROM chado.pan_gene
        WHERE COALESCE(NULLIF(TRIM(gene_model_name), ''), TRIM(additional_gene_model_name)) IS NOT NULL",
        array('pan_gene', 'member', 'exemplar', 'chr'), $dest . '/pan_genes.tsv.gz');
}

if ($only === null || $only === 'go') {
    /* Gene-model annotations only (gene_model_version set). Locus-level terms
       are keyed on a locus id and belong to a curated gene, not to the gene
       models an expression release is keyed on. Distinct per (annotation,
       gene, term): the table carries one row per evidence line. */
    $counts['go'] = et_export($DBConn, "
        SELECT DISTINCT gene_model_version, gene_model_id, obo_term
        FROM perm_tables.id_ontology
        WHERE obo_term LIKE 'GO:%'
          AND gene_model_version IS NOT NULL AND gene_model_version <> ''
          AND gene_model_id IS NOT NULL AND gene_model_id <> ''",
        array('annotation', 'gene', 'term'), $dest . '/go_annotations.tsv.gz');
}

file_put_contents($dest . '/export.json', json_encode(array(
    'generated' => gmdate('Y-m-d\TH:i:s\Z'),
    'generated_by' => 'tools/expression_tools_export.php',
    'rows' => $counts
), JSON_PRETTY_PRINT) . "\n");
