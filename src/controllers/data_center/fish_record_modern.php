<?php
/* file: fish_record_modern.php
 *
 * purpose: FISH record page (/data_center/fish?id={locus}&map={map}) on the
 *          modern design system.
 *
 *          Included by controllers/data_center.php when PAGE is 'fish' and a
 *          record id is present. Publishes and returns true.
 *
 * A FISH record is one fluorescence in situ hybridization probe: a sorghum BAC
 * hybridized to a maize pachytene chromosome, and the cytogenetic position its
 * signal was detected at. The whole corpus is **five records**, all from the
 * Cytogenetic Map of Maize project, whose nomenclature and methods are at
 * /projects/cytogenetic_map.
 *
 * Keyed by locus, not by itself
 * -----------------------------
 * mgdb.map_fish has no id column -- its key is auto_num, 1 to 5 -- and the
 * route addresses a record by the *locus* it marks plus the map it is on:
 * ?id=12098&map=892372. That is the legacy contract and every link on the site
 * uses it, so it is kept. The map is optional here: a locus has at most one
 * FISH record, so ?id=12098 alone resolves, where the legacy page answered
 * "Fish record not found for ID: 12098!".
 *
 * The image that never displayed
 * ------------------------------
 * templates/data_center/fish_sections.bau builds the image as
 *
 *     src="$(img_url)/db_images/Map/FISH/$(img_url)"
 *
 * -- the same placeholder for the server prefix and the file name. The
 * controller sets img_url to the image server, then overwrites it with the
 * file name whenever a record has an image, so the src resolves to
 * "sbb-CBM9_03_S13.jpg/db_images/Map/FISH/sbb-CBM9_03_S13.jpg". All five
 * records have an image and none of them has ever been shown. They are at
 * images.maizegdb.org/db_images/Map/FISH/, verified 200.
 *
 * Query cost
 * ----------
 * Two queries: the record with every lookup joined, and the four siblings.
 * The legacy page ran seven single-row lookups for the first of those.
 */

include_once('./include/db-api.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

$requested_locus = trim((string) getCGIParam('id', 'G', ID));
$requested_map = trim((string) getCGIParam('map', 'G', ''));

if (!ctype_digit($requested_locus)) { return false; }

$params = array('locus' => (int) $requested_locus);
$map_clause = '';
if (ctype_digit($requested_map)) {
    $map_clause = ' AND f.map_id = :map';
    $params['map'] = (int) $requested_map;
}

$record = retrieve_row(make_query($DBConn, "
    SELECT f.auto_num, trim(f.name) AS name, f.image,
           f.map_id, trim(m.name) AS map_name,
           f.locus_id, trim(l.name) AS locus_name, trim(l.full_name) AS locus_full,
           f.bac_id, trim(b.name) AS bac_name,
           f.maize_probe, trim(pr.name) AS probe_name,
           trim(t.name) AS select_method,
           f.bac_species, trim(sp.species) AS bac_species_name,
           f.reference, trim(r.name) AS reference_name,
           trim(arm.name) AS arm_name, lc.value AS signal_value,
           trim(lg.name) AS chromosome
    FROM mgdb.map_fish f
      LEFT JOIN mgdb.map m ON m.id = f.map_id
      LEFT JOIN mgdb.linkage_group lg ON lg.id = m.linkage_group
      LEFT JOIN mgdb.locus l ON l.id = f.locus_id
      LEFT JOIN mgdb.term arm ON arm.id = l.arm
      LEFT JOIN mgdb.locus_coordinates lc ON lc.id = f.locus_id AND lc.map = f.map_id
      LEFT JOIN mgdb.probe b ON b.id = f.bac_id
      LEFT JOIN mgdb.probe pr ON pr.id = f.maize_probe
      LEFT JOIN mgdb.term t ON t.id = f.probe_select_method
      LEFT JOIN mgdb.species sp ON sp.id = f.bac_species
      LEFT JOIN mgdb.reference r ON r.id = f.reference
    WHERE f.locus_id = :locus" . $map_clause . "
    LIMIT 1", 1, $params));

/* No FISH record for that locus. The legacy page rendered its own not-found
   body; falling through lets controllers/data_center.php serve the legacy
   page, which is the one place that still knows how to say it. */
if (!$record) { return false; }

header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

logMessage('Starting fish_record_modern.php for locus ' . (int) $requested_locus);

$esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

$name = (string) $record['name'];

/* "9S.65": the chromosome, the arm, and the coordinate's magnitude. The sign
   on locus_coordinates.value is the arm -- negative is short -- and the arm
   term repeats it, so the two are read together rather than either alone. */
$position = '';
if ($record['signal_value'] !== null && $record['arm_name'] !== null) {
    $magnitude = abs((float) $record['signal_value']);
    $position = (string) $record['chromosome'] . $record['arm_name']
              . '.' . str_pad((string) (int) round($magnitude * 100), 2, '0', STR_PAD_LEFT);
}

$bauplan = new Bauplan('MaizeGDB FISH probe: ' . $name);
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
          ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-fish-record.css';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-hub.css');
$bauplan->includeCss('/css/mgdb-record.css');
$bauplan->includeCss('/css/mgdb-fish-record.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->head('<meta name="description" content="'
    . $esc($name . ' is a FISH probe on the ' . $record['map_name'] . ' map'
           . ($position !== '' ? ', with its signal detected at ' . $position : '') . '.')
    . '">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_fish_record.bau');
$content->get('record_name')->replace($esc($name));
$content->get('record_position')->replace($esc($position !== '' ? $position : 'not recorded'));

$facts = '';
if ($position !== '') {
    $facts .= '<div><dt>Signal detected at</dt><dd>' . $esc($position) . '</dd></div>';
}
if ($record['map_name'] !== null && $record['map_name'] !== '') {
    $facts .= '<div><dt>Map</dt><dd><a href="/data_center/map?id=' . (int) $record['map_id'] . '">'
            . $esc($record['map_name']) . '</a></dd></div>';
}
if ($record['locus_name'] !== null && $record['locus_name'] !== '') {
    $facts .= '<div><dt>Locus marked</dt><dd><a href="/data_center/locus?id=' . (int) $record['locus_id'] . '">'
            . $esc($record['locus_name']) . '</a>'
            . ($record['locus_full'] !== null && $record['locus_full'] !== ''
                ? ' <span class="mgdb-muted">' . $esc($record['locus_full']) . '</span>' : '')
            . '</dd></div>';
}
$facts .= '<div><dt>MaizeGDB ID</dt><dd class="mgdb-record-id">' . (int) $record['locus_id'] . '</dd></div>';
$content->get('identity_facts')->replace($facts);

$rows = '';
if ($record['bac_name'] !== null && $record['bac_name'] !== '') {
    $rows .= '<tr><th scope="row">BAC probe</th><td><a href="/data_center/bac?id=' . (int) $record['bac_id'] . '">'
           . $esc($record['bac_name']) . '</a>'
           . ($record['bac_species_name'] !== null && $record['bac_species_name'] !== ''
               ? ' <span class="mgdb-muted"><i>' . $esc($record['bac_species_name']) . '</i></span>' : '')
           . '</td></tr>';
}
if ($record['select_method'] !== null && $record['select_method'] !== '') {
    $chosen = $esc($record['select_method']);
    if ($record['probe_name'] !== null && $record['probe_name'] !== '') {
        $chosen .= ' <a href="/data_center/marker?id=' . (int) $record['maize_probe'] . '">'
                 . $esc($record['probe_name']) . '</a>';
    }
    $rows .= '<tr><th scope="row">Probe chosen by</th><td>' . $chosen . '</td></tr>';
}
if ($record['reference_name'] !== null && $record['reference_name'] !== '') {
    $rows .= '<tr><th scope="row">Reference</th><td><a href="/data_center/reference?id='
           . (int) $record['reference'] . '">' . $esc($record['reference_name']) . '</a></td></tr>';
}
$content->get('detail_rows')->replace($rows);

/* The image is on the image server, under the path the legacy template meant
   to build. All five records have one. */
if ($record['image'] !== null && trim((string) $record['image']) !== '') {
    $file = str_replace(' ', '_', trim((string) $record['image']));
    $img = $content->get('fish_image');
    $img->get('image_url')->replace($esc('https://images.maizegdb.org/db_images/Map/FISH/' . rawurlencode($file)));
    $img->get('image_alt')->replace($esc('FISH signal for ' . $name
        . ($position !== '' ? ' at ' . $position : '')));
    $img->unmute();
}

/* Four siblings at most, so they are listed rather than counted. */
$sth = make_query($DBConn, "
    SELECT f.locus_id, trim(f.name) AS name, f.map_id,
           trim(l.name) AS locus_name
    FROM mgdb.map_fish f
      LEFT JOIN mgdb.locus l ON l.id = f.locus_id
    WHERE f.locus_id <> :locus
    ORDER BY trim(f.name)", 1, array('locus' => (int) $record['locus_id']));
$siblings = '';
while ($row = retrieve_row($sth)) {
    $siblings .= '<li><a href="/data_center/fish?id=' . (int) $row['locus_id']
               . '&amp;map=' . (int) $row['map_id'] . '"><strong>' . $esc($row['name']) . '</strong>'
               . ($row['locus_name'] !== null && $row['locus_name'] !== ''
                   ? ' <span class="mgdb-muted">' . $esc($row['locus_name']) . '</span>' : '')
               . '</a></li>';
}
if ($siblings !== '') {
    $others = $content->get('other_probes');
    $others->get('sibling_items')->replace($siblings);
    $others->unmute();
}

include_once('translation.php');
$bauplan->publish();
return true;
?>
