<?php
/* file: locus_reports_modern.php
 *
 * purpose: /data_center/locus-reports?report=transgene and ?report=family on
 *          the modern shell.
 *
 * history:
 *  09/13/26  claude  created
 *
 * These two reports are the last of four. `genes` and `candidate` were retired
 * on 2026-09-06 -- they were flat lists of every locus of one type and the
 * Locus Data Hub does that better -- and controllers/data_center/
 * locus-reports_search.php still holds their 301s. These two stayed because
 * they carry curator notes, related loci and references the hub search does not
 * show.
 *
 * Both are historical, and the page says so above everything else. The figures
 * in that sentence are counted from `id_num.add_date` at render time rather
 * than written into the copy, so the claim cannot drift away from the records:
 * 81 of the 89 transgenes were curated between 2003 and 2008, 25 of the 28 gene
 * families between 2003 and 2005.
 *
 * The legacy page is archived in the redesign repository under
 * legacy/locus-reports/. Rollback is deleting the routing block in
 * controllers/data_center.php.
 */

include_once('./include/db-api.php');
include_once('./include/references_lib.php');

$system = getSystemInfo('mgdb.conf');

/* The two reports, and everything that differs between them. */
$LR_REPORTS = array(
    'transgene' => array(
        'type'          => 40071,
        'list_name'     => 'Transgenes',
        'emblem'        => 'Trans<br />genes',
        'title'         => 'Maize transgenes',
        'members_label' => 'Gene products',
        'other'         => 'family',
    ),
    'family' => array(
        'type'          => 40414,
        'list_name'     => 'Gene families',
        'emblem'        => 'Gene<br />families',
        'title'         => 'Maize gene families',
        'members_label' => 'Family members',
        'other'         => 'transgene',
    ),
);

$lr_key = strtolower((string) getCGIParam('report', 'GP', ''));
if (!isset($LR_REPORTS[$lr_key])) { return false; }
$lr = $LR_REPORTS[$lr_key];

$DBConn = connect_to_database();
if (!$DBConn) { return false; }


/* ---------------------------------------------------------------------------
   Helpers
   --------------------------------------------------------------------------- */

function lrEsc($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

/* A list of already-built <li> strings, or the empty-state sentence. The
   legacy page wrote "There are no noted family members available for this
   transgene" under *Gene families* -- the word "transgene" was left behind when
   the transgene branch was copied. Each branch names its own subject now. */
function lrList($items, $empty) {
    if (!$items) { return '<p class="lr-none">' . $empty . '</p>'; }
    return '<ul class="lr-links">' . implode('', $items) . '</ul>';
}

function lrLocusLink($id, $name, $full_name = '', $prefix = '') {
    $html = '<li>';
    if ($prefix !== '') { $html .= '<span class="lr-rel">' . lrEsc($prefix) . '</span> '; }
    $html .= '<a href="/data_center/locus?id=' . (int) $id . '">' . lrEsc($name);
    if (trim((string) $full_name) !== '') {
        $html .= ' <i>' . lrEsc(trim($full_name)) . '</i>';
    }
    return $html . '</a></li>';
}


/* ---------------------------------------------------------------------------
   The records
   --------------------------------------------------------------------------- */

$sth = make_query($DBConn, "
    SELECT a.id, a.name, a.full_name
      FROM mgdb.locus a
      INNER JOIN mgdb.id_num b ON a.id = b.id
     WHERE a.type = ? AND b.curation_lvl = 0
     ORDER BY lower(a.name)", 1, array($lr['type']));
$rows = get_all_rows($sth);

$subject = $lr_key === 'family' ? 'gene family' : 'transgene';
$records = array();

foreach ($rows as $row) {
    $id = (int) $row['id'];

    /* Curator memos are authored HTML -- mgdb_safe_html() is what the legacy
       page allow-listed them with, and it is kept rather than escaped flat. */
    $memos = array();
    foreach (get_all_rows(make_query($DBConn,
            "SELECT memo FROM mgdb.memo WHERE id = ?", 1, array($id))) as $m) {
        $memos[] = '<li>' . mgdb_safe_html($m['memo']) . '</li>';
    }

    /* Gene products for a transgene; family members for a gene family. The two
       relation ids are the legacy page's: 56335 and 69852 are the membership
       relations, and "related loci" is everything else. */
    $members = array();
    if ($lr_key === 'transgene') {
        foreach (get_all_rows(make_query($DBConn, "
                SELECT b.name, b.id
                  FROM mgdb.locus_gene_products a
                  INNER JOIN mgdb.gene_product b ON a.gene_product = b.id
                  INNER JOIN mgdb.id_num c ON b.id = c.id
                 WHERE a.id = ? AND c.curation_lvl = 0", 1, array($id))) as $gp) {
            $members[] = '<li><a href="/data_center/gene_product?id=' . (int) $gp['id'] . '">'
                       . lrEsc($gp['name']) . '</a></li>';
        }
        $related_sql = "
            SELECT b.name, b.id, d.name AS relationship
              FROM mgdb.relation a
              INNER JOIN mgdb.locus b ON a.related_id = b.id
              INNER JOIN mgdb.id_num c ON b.id = c.id
              INNER JOIN mgdb.term d ON a.relation = d.id
             WHERE a.id = ? AND c.curation_lvl = 0";
    } else {
        foreach (get_all_rows(make_query($DBConn, "
                SELECT b.name, b.full_name, b.id
                  FROM mgdb.relation a
                  INNER JOIN mgdb.locus b ON a.related_id = b.id
                  INNER JOIN mgdb.id_num c ON b.id = c.id
                 WHERE a.id = ? AND (a.relation = 56335 OR a.relation = 69852)
                       AND c.curation_lvl = 0", 1, array($id))) as $fm) {
            $members[] = lrLocusLink($fm['id'], $fm['name'], $fm['full_name']);
        }
        $related_sql = "
            SELECT b.name, b.id, d.name AS relationship
              FROM mgdb.relation a
              INNER JOIN mgdb.locus b ON a.related_id = b.id
              INNER JOIN mgdb.id_num c ON b.id = c.id
              INNER JOIN mgdb.term d ON a.relation = d.id
             WHERE a.id = ? AND a.relation != 56335 AND a.relation != 69852
                   AND c.curation_lvl = 0";
    }

    $loci = array();
    foreach (get_all_rows(make_query($DBConn, $related_sql, 1, array($id))) as $rl) {
        $loci[] = lrLocusLink($rl['id'], $rl['name'], '', $rl['relationship']);
    }

    $refs = array();
    foreach (get_all_rows(make_query($DBConn, "
            SELECT b.name, b.id, b.title, d.name AS type
              FROM mgdb.id_reference a
              INNER JOIN mgdb.reference b ON a.reference = b.id
              INNER JOIN mgdb.id_num c ON b.id = c.id
              INNER JOIN mgdb.term d ON a.contents = d.id
             WHERE a.id = ? AND c.curation_lvl = 0", 1, array($id))) as $rf) {
        $ref = '<li><span class="lr-rel">' . lrEsc($rf['type']) . '</span> '
             . '<a href="/data_center/reference?id=' . (int) $rf['id'] . '">' . lrEsc($rf['name']) . '</a>';
        if (trim((string) $rf['title']) !== '') {
            $ref .= '<span class="lr-ref-title">' . lrEsc(trim($rf['title'])) . '</span>';
        }
        $refs[] = $ref . '</li>';
    }

    $members_empty = $lr_key === 'transgene'
        ? 'No gene products are recorded for this transgene.'
        : 'No family members are recorded for this gene family.';

    $records[] = array(
        'id'            => $id,
        'name'          => lrEsc($row['name']),
        'full_name'     => lrEsc(trim((string) $row['full_name'])),
        'search_key'    => lrEsc(strtolower($row['name'] . ' ' . $row['full_name'])),
        'members_label' => $lr['members_label'],
        'comments'      => lrList($memos, 'No notes are recorded for this ' . $subject . '.'),
        'members'       => lrList($members, $members_empty),
        'loci'          => lrList($loci, 'No related loci are recorded for this ' . $subject . '.'),
        'ref'           => lrList($refs, 'No references are recorded for this ' . $subject . '.'),
    );
}


/* ---------------------------------------------------------------------------
   How historical, in the records' own terms

   Counted rather than asserted, so the sentence cannot drift away from the
   data the way a hand-written year would.
   --------------------------------------------------------------------------- */

$dates = get_all_rows(make_query($DBConn, "
    SELECT extract(year from b.add_date)::int AS y, count(*) AS n
      FROM mgdb.locus a
      INNER JOIN mgdb.id_num b ON a.id = b.id
     WHERE a.type = ? AND b.curation_lvl = 0 AND b.add_date IS NOT NULL
     GROUP BY 1 ORDER BY 1", 1, array($lr['type'])));

$total = count($records);
$note  = number_format($total) . ' records are listed.';

/* One fixed lookback rather than a rule that tries to find the original burst
   of curation for itself. A first attempt did the latter and got the transgenes
   wrong: their years run 2003, 2007, 2008, and a heuristic that stopped at a
   four-year gap reported "12 of 89, between 2003 and 2003" when the real answer
   is 81 before the trickle starts. Counting how many were added more than
   fifteen years ago needs no guess about where a burst ends, and says the thing
   the sentence is actually for. */
define('LR_HISTORICAL_YEARS', 15);

if ($dates) {
    $cutoff = ((int) date('Y')) - LR_HISTORICAL_YEARS;
    $old = 0;
    $latest = 0;
    foreach ($dates as $d) {
        $year = (int) $d['y'];
        if ($year < $cutoff) { $old += (int) $d['n']; }
        if ($year > $latest) { $latest = $year; }
    }

    $subject_plural = $lr_key === 'family' ? 'gene-family records' : 'transgene records';
    $note = number_format($total) . ' ' . $subject_plural . ' are listed';
    if ($old > 0) {
        $note .= ', ' . number_format($old) . ' of them curated before ' . $cutoff;
    }
    $note .= '.';
    if ($latest > 0) {
        $note .= ' The most recent addition was in ' . $latest . '.';
    }
}


/* ---------------------------------------------------------------------------
   Render
   --------------------------------------------------------------------------- */

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
          ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';

$bauplan = new Bauplan($lr['title'] . ' | MaizeGDB');
$bauplan->modern();
$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-hub.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-hub.css'));
$bauplan->includeCss('/css/mgdb-locus-reports.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-locus-reports.css'));
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-locus-reports.js?v=' . (int) @filemtime($doc_root . '/js/mgdb-locus-reports.js'));

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_locus_reports.bau');

$other = $LR_REPORTS[$lr['other']];

if ($lr_key === 'transgene') {
    $description = 'Maize loci that are transgenes &#8212; a sequence introduced into maize from '
                 . 'elsewhere, or a maize sequence put back under a different promoter. Each entry '
                 . 'carries the curator notes, the gene products, the loci it relates to and the '
                 . 'papers it was described in.';
    $about = '<p>A transgene record names a construct that has been expressed in maize and written '
           . 'about. It is a locus like any other in the database, typed as a transgene, so it can '
           . 'carry map positions, stocks and variations alongside what this report shows.</p>'
           . '<p>The notes are the curator\'s own, written when the record was made. They describe '
           . 'what the construct contains, what it does in the plant, and where it came from &#8212; '
           . 'and they are the reason this list is still here, because nothing else in the database '
           . 'says those things.</p>';
    $placeholder = 'bar, Basta resistance, AY860968';
} else {
    $description = 'Maize loci that stand for a family of genes rather than a single one. Each entry '
                 . 'carries the curator notes, its member loci, the loci it relates to and the papers '
                 . 'the family was described in.';
    $about = '<p>A gene family record is a placeholder for a group: it names the family, and the '
           . 'members point at it. Some were reserved before any maize member had been published, '
           . 'which is why several carry a note and nothing else.</p>'
           . '<p>Where a family has members, they are listed with their own record links. Where it '
           . 'does not, the note usually says what the family is and what was known at the time.</p>';
    $placeholder = 'abi60, acad, ars1';
}

$content->get('report_key')->replace(lrEsc($lr_key));
$content->get('list_name')->replace($lr['list_name']);
$content->get('emblem_label')->replace($lr['emblem']);
$content->get('page_title')->replace(lrEsc($lr['title']));
$content->get('page_description')->replace($description);
$content->get('og_title')->replace(lrEsc($lr['title'] . ' | MaizeGDB'));
$content->get('og_description')->replace(lrEsc(strip_tags(str_replace('&#8212;', '-', $description))));
$content->get('historical_note')->replace($note);
$content->get('record_count')->replace(number_format($total));
$content->get('filter_placeholder')->replace(lrEsc($placeholder));
$content->get('about_prose')->replace($about);
$content->get('other_report')->replace(lrEsc($lr['other']));
$content->get('other_report_name')->replace($other['list_name']);
$content->get('other_report_blurb')->replace($lr['other'] === 'family'
    ? 'The other historical locus report&#58; loci that stand for a family of genes.'
    : 'The other historical locus report&#58; constructs expressed in maize.');
$content->get('record_list')->loop($records);

include_once('translation.php');

$bauplan->publish();
return true;
?>
