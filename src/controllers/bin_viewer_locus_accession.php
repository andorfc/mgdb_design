<?php
/* file: bin_viewer_locus_accession.php
 *
 * purpose: /bin_viewer_locus_accession -- the loci mapped to one genetic bin
 *          and the external accessions recorded against them.
 *
 *          controller.php checks ./controllers/<CONTROLLER>.php before falling
 *          through to redirect.php, so this file takes the route from
 *          controllers/tools/bin_viewer_locus_accession.php without touching
 *          it. Rollback is deleting this file. Original archived in
 *          legacy/bin-viewer-accession/.
 *
 * The page never worked
 * ---------------------
 * Its own header said "purpose: MAY NOT BE IN USE", and it was right in
 * spirit: the page returned **zero rows for every bin**. Three separate faults,
 * any one of which was enough:
 *
 *   1. Its query was `WHERE a.type = 25396` -- every QTL locus in the database,
 *      with **no bin condition at all**. The bin only ever reached the heading.
 *   2. It loaded templates/tools/bin_viewer_locus_sequences_search.bau -- the
 *      *sequence* page's template, not its own.
 *   3. The rows it did want came from `mgdb.z_sequence`, which has 0 rows. The
 *      modern Bin Viewer had already dropped its own "Accession #s in Bin"
 *      section for exactly this reason; the comment is still in
 *      controllers/tools/bin_viewer_modern.php.
 *
 * Checked before rebuilding: bins 1.01, 5.04 and 8.06 returned byte-identical
 * pages, and the data table held its header row and nothing else.
 *
 * Where the accessions actually are
 * ---------------------------------
 * `mgdb.ext_db_key`. It holds several kinds of identifier under one roof --
 * gene models, FPC contig numbers, ortholog ids in rice and Arabidopsis -- and
 * only some of them are what this page means by an accession. The sources are
 * named in ACCESSION_SOURCES below rather than guessed at from the shape of
 * the key, and the page says which ones it is showing. Everything else about a
 * locus is one click away on its own record.
 *
 * Query cost
 * ----------
 * Two queries. The largest real bin is 5.03 with 460 loci, so the page is
 * rendered in one pass rather than paged.
 */

include_once('./include/db-api.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting bin_viewer_locus_accession.php');

header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

/* The sources whose keys are an accession in an external sequence or protein
   database. Every other source in ext_db_key is an identifier of a different
   kind and is deliberately not listed here. */
$ACCESSION_SOURCES = array(
    'GenBank', 'EMBL', 'DDBJ', 'NCBI Gene', 'UniProt', 'dbSNP moved to EVA',
);

/* The modern Bin Viewer links here as ?bin=<chromosome>&sub=<n>, which is the
   form the legacy page took. A full label -- ?bin=1.01 -- is accepted too,
   because that is what the heading shows and what someone editing the URL by
   hand would write. */
$bin_raw = trim((string) getCGIParam('bin', 'G', ''));
$sub_raw = trim((string) getCGIParam('sub', 'G', ''));

$chromosome = null;
$sub = null;
if (preg_match('/^([0-9]{1,2})\.([0-9]{1,2})$/', $bin_raw, $m)) {
    $chromosome = (int) $m[1];
    $sub = (int) $m[2];
} elseif (ctype_digit($bin_raw) && ctype_digit($sub_raw)) {
    $chromosome = (int) $bin_raw;
    $sub = (int) $sub_raw;
}

$label = ($chromosome !== null && $sub !== null)
       ? $chromosome . '.' . str_pad((string) $sub, 2, '0', STR_PAD_LEFT)
       : '';

$DBConn = connect_to_database(false);
$rows = array();
$total_accessions = 0;
$bin_exists = false;

if ($label !== '' && $DBConn) {
    /* Bin membership is reduced to DISTINCT before anything is joined to it. A
       locus carries a coordinate on every map it appears on, so joining
       ext_db_key straight onto locus_coordinates multiplies each accession by
       the number of maps -- bhlh140 came back twelve times over one key. */
    $sth = make_query($DBConn, "
        WITH in_bin AS (
          SELECT DISTINCT c.id FROM mgdb.locus_coordinates c WHERE c.bin = :bin
        )
        SELECT l.id, trim(l.name) AS name, trim(l.full_name) AS full_name,
               lt.name AS locus_type,
               COALESCE(json_agg(DISTINCT jsonb_build_object('key', trim(k.key), 'source', p.name))
                        FILTER (WHERE k.key IS NOT NULL), '[]') AS accessions
        FROM in_bin b
          INNER JOIN mgdb.locus l ON l.id = b.id
          INNER JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
          LEFT JOIN mgdb.term lt ON lt.id = l.type
          LEFT JOIN mgdb.ext_db_key k ON k.id = l.id
          LEFT JOIN mgdb.person p ON p.id = k.db_person
                AND p.name IN ('GenBank', 'EMBL', 'DDBJ', 'NCBI Gene', 'UniProt', 'dbSNP moved to EVA')
        GROUP BY l.id, l.name, l.full_name, lt.name
        ORDER BY LOWER(trim(l.name))", 1, array('bin' => $label));

    while ($row = retrieve_row($sth)) {
        $bin_exists = true;
        $decoded = json_decode((string) $row['accessions'], true);
        if (!is_array($decoded)) { $decoded = array(); }
        $accessions = array();
        foreach ($decoded as $item) {
            if (!isset($item['source']) || $item['source'] === null) { continue; }
            if (!in_array($item['source'], $ACCESSION_SOURCES, true)) { continue; }
            $key = isset($item['key']) ? trim((string) $item['key']) : '';
            if ($key === '') { continue; }
            $accessions[] = array('key' => $key, 'source' => (string) $item['source']);
        }
        usort($accessions, function ($a, $b) {
            $c = strcmp($a['source'], $b['source']);
            return $c !== 0 ? $c : strcmp($a['key'], $b['key']);
        });
        $total_accessions += count($accessions);
        $rows[] = array(
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'full_name' => (string) $row['full_name'],
            'type' => $row['locus_type'] === null ? '' : (string) $row['locus_type'],
            'accessions' => $accessions,
        );
    }
}

$esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

$title = $label !== ''
       ? 'Loci and accessions in bin ' . $label . ' | MaizeGDB'
       : 'Loci and accessions by bin | MaizeGDB';

$bauplan = new Bauplan($title);
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
          ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-bin-accession.css';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-hub.css');
$bauplan->includeCss('/css/mgdb-bin-accession.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->head('<meta name="description" content="'
    . $esc($label !== ''
        ? 'The loci mapped to genetic bin ' . $label . ' in maize, and the GenBank, EMBL, DDBJ, NCBI Gene, UniProt and EVA accessions recorded against them.'
        : 'The loci mapped to a maize genetic bin, and the sequence accessions recorded against them.')
    . '">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_bin_accession.bau');

$content->get('bin_label')->replace($esc($label !== '' ? $label : '—'));
$content->get('chromosome')->replace($esc((string) ($chromosome === null ? '' : $chromosome)));
/* The Bin Viewer takes the sub-bin zero-padded -- ?bin=1&sub=01 -- which is
   not the same string as the label's second half for bins below 10. */
$content->get('sub_padded')->replace($sub === null ? ''
    : str_pad((string) $sub, 2, '0', STR_PAD_LEFT));

if ($label === '') {
    $content->get('no_bin')->unmute();
} elseif (!$bin_exists) {
    $notice = $content->get('empty_bin');
    $notice->get('empty_bin_label')->replace($esc($label));
    $notice->unmute();
} else {
    $with = 0;
    $body = '';
    foreach ($rows as $row) {
        $cells = '<th scope="row"><a href="/data_center/locus?id=' . (int) $row['id'] . '">'
               . $esc($row['name']) . '</a>'
               . ($row['full_name'] !== ''
                   ? ' <span class="mgdb-muted">' . $esc($row['full_name']) . '</span>' : '')
               . '</th>';
        $cells .= '<td>' . ($row['type'] !== '' ? $esc($row['type'])
                            : '<span class="mgdb-muted">&mdash;</span>') . '</td>';
        if ($row['accessions']) {
            $with++;
            $list = '';
            foreach ($row['accessions'] as $acc) {
                $list .= '<li><code>' . $esc($acc['key']) . '</code>'
                       . '<span class="bva-source">' . $esc($acc['source']) . '</span></li>';
            }
            $cells .= '<td><ul class="bva-accessions">' . $list . '</ul></td>';
        } else {
            $cells .= '<td><span class="mgdb-muted">None recorded</span></td>';
        }
        $body .= '<tr>' . $cells . '</tr>';
    }
    $table = $content->get('bin_table');
    $table->get('table_rows')->replace($body);
    $table->get('locus_count')->replace(number_format(count($rows)));
    $table->get('accession_count')->replace(number_format($total_accessions));
    $table->get('with_accessions')->replace(number_format($with));
    $table->unmute();
}

include_once('translation.php');
$bauplan->publish();
?>
