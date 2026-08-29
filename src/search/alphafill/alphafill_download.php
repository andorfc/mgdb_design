<?php
/* file: alphafill_download.php
 *
 * purpose: Bulk downloads for /data_center/alphafill.
 *
 * Four files, named rather than pathed, so nothing a reader types reaches the
 * filesystem. Each is a static artifact written by tools/alphafill_index.py or
 * shipped beside it; this script exists only to give them stable public URLs,
 * a sensible filename, and a content type -- data/alphafill/.htaccess denies
 * .json so the index itself is never fetchable piecemeal.
 *
 * Files are streamed with readfile() rather than read into memory: the
 * transplant table is 64 MB and PHP's memory limit is not.
 */

include_once('../../include/db-api.php');
include_once('alphafill_lib.php');

$AF_DOWNLOADS = array(
    'index' => array(
        'file' => 'index.json',
        'name' => 'maize_alphafill_gene_index.json',
        'type' => 'application/json',
        'desc' => 'one row per gene with a transplant',
    ),
    'by_gene' => array(
        'file' => 'bulk/alphafill.by_gene.json',
        'name' => 'maize_alphafill_by_gene.json',
        'type' => 'application/json',
        'desc' => 'the collapsed gene x ligand table',
    ),
    'transplants' => array(
        'file' => 'bulk/proteome_transplants.tsv.gz',
        'name' => 'maize_alphafill_transplants.tsv.gz',
        'type' => 'application/gzip',
        'desc' => 'every raw transplant',
    ),
    'targets' => array(
        'file' => 'bulk/alphafill_targets.csv',
        'name' => 'maize_alphafill_pocket_no_donor.csv',
        'type' => 'text/csv',
        'desc' => 'confident pocket, no qualifying donor',
    ),
);

$key = strtolower(trim((string) getCGIParam('file', 'G', false)));

if (!isset($AF_DOWNLOADS[$key])) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($key === '' ? 400 : 404);
    $available = array();
    foreach ($AF_DOWNLOADS as $name => $entry) {
        $available[$name] = $entry['desc'];
    }
    echo json_encode(array('ok' => false,
                           'message' => 'Unknown file. Use one of: '
                                      . implode(', ', array_keys($AF_DOWNLOADS)),
                           'available' => $available), JSON_UNESCAPED_SLASHES);
    exit;
}

$entry = $AF_DOWNLOADS[$key];
$path = afDataRoot() . '/' . $entry['file'];

if (!is_file($path) || !is_readable($path)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(503);
    echo json_encode(array('ok' => false,
                           'message' => 'That file has not been published yet.'),
                     JSON_UNESCAPED_SLASHES);
    exit;
}

$size = filesize($path);
$stamp = filemtime($path);
$etag = '"' . sha1($entry['file'] . ':' . $size . ':' . $stamp) . '"';

header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $stamp) . ' GMT');
header('Cache-Control: public, max-age=86400');
if (isset($_SERVER['HTTP_IF_NONE_MATCH'])
    && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . $entry['type']);
header('Content-Length: ' . $size);
header('Content-Disposition: attachment; filename="' . $entry['name'] . '"');
header('X-Content-Type-Options: nosniff');

/* Any output buffering upstream would defeat the point of streaming. */
while (ob_get_level()) { ob_end_clean(); }
readfile($path);
