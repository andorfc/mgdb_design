<?php
/* file: include/bin_viewer_lib.php
 *
 * purpose: the idiogram, the per-bin counts and the core bin marker table
 *          behind /bin_viewer.
 *
 * The idiogram
 * ------------
 * The previous page drew the ten chromosomes as a 200x240 JPEG with two HTML
 * image maps laid over it -- 220 <area> rectangles, one set linking to bin
 * pages and one to the genome browser. An image map cannot scale, cannot take
 * keyboard focus, and carries no information about what is in a bin.
 *
 * The rectangles are the useful part: each one is a bin, with its exact extent
 * along the chromosome. tools/gen_bin_data.py parses them out of
 * templates/tools/bin_viewer-maps.bau into data/bin_viewer/bin_geometry.json,
 * and this file redraws them as one inline SVG. Same layout, same 100 bins,
 * but each bin is a real link, the whole thing scales, and each bin can be
 * shaded by how many mapped loci it holds.
 *
 * Counting loci per bin
 * ---------------------
 * mgdb.locus_coordinates.bin is numeric(10,2) holding the bin as a decimal --
 * 1.09 is chromosome 1, bin 09 -- and each chromosome has its own map id. The
 * two do not always agree: four rows sit on a map for one chromosome while
 * carrying a bin number from another, and two more name bins that do not exist
 * (3.90, 9.09). Deriving the chromosome from the map id alone silently merges
 * those into real bins -- it made bin 3.00 and bin 9.05 read as two separate
 * bins with the same name. The chromosome is taken from the bin value and
 * checked against the map, and anything that fails is counted separately and
 * reported rather than dropped.
 *
 * One grouped query covers all 100 bins in about 60 ms, so the whole thing
 * goes through dashboardCache and a page view issues no query at all.
 */

/* Bin map identifiers, one per chromosome. Carried over verbatim from
   retrieve_bin_map_id() in record_data/bin_viewer_data.php, which is still the
   source of truth for the bin and chromosome section pages. */
function binViewerMapIds() {
    return array(
        1 => 64489, 2 => 64501, 3 => 64505, 4 => 64506, 5 => 64507,
        6 => 64508, 7 => 64509, 8 => 64510, 9 => 64511, 10 => 64512
    );
}

function binViewerDataDir($system) {
    $root = isset($system['root_dir']) && $system['root_dir'] ? $system['root_dir'] : '.';
    return rtrim($root, '/') . '/data/bin_viewer';
}

function binViewerLoadJson($system, $name) {
    $path = binViewerDataDir($system) . '/' . $name;
    if (!file_exists($path)) { return null; }
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

/* Normalizes a chromosome and sub-bin into the "1.09" form the whole page
   uses. The previous controller did this inline in three places. */
function binViewerLabel($chromosome, $sub) {
    return ((int) $chromosome) . '.' . str_pad((string) ((int) $sub), 2, '0', STR_PAD_LEFT);
}


/* ---------------------------------------------------------------------------
   Mapped loci per bin
   --------------------------------------------------------------------------- */

/* Returns:
     counts     label => number of curated loci mapped to that bin
     unplaced   loci on a chromosome's bin map with no bin assignment (bin 999)
     mismatched rows whose bin names a different chromosome than their map
     unknown    rows naming a bin that is not one of the 100
     max        largest per-bin count, for the shading scale
*/
function binViewerLocusCounts($DBConn, $validLabels) {
    $maps = binViewerMapIds();
    $byMap = array_flip($maps);
    $ids = implode(',', array_map('intval', $maps));

    $sql = "
        SELECT c.map, c.bin::text AS bin, count(DISTINCT c.id) AS loci
        FROM locus_coordinates c
          INNER JOIN id_num n ON n.id = c.id AND n.curation_lvl = 0
        WHERE c.map IN ($ids) AND c.bin IS NOT NULL
        GROUP BY c.map, c.bin";

    $rows = get_all_rows(make_query($DBConn, $sql));

    $counts = array();
    $unplaced = 0;
    $mismatched = array();
    $unknown = array();
    $max = 0;

    foreach ($rows as $row) {
        $mapChromosome = isset($byMap[(int) $row['map']]) ? $byMap[(int) $row['map']] : null;
        $bin  = (float) $row['bin'];
        $loci = (int) $row['loci'];

        // 999.00 is the sentinel for a locus on the chromosome with no bin.
        if ($bin >= 900) { $unplaced += $loci; continue; }

        $chromosome = (int) floor($bin);
        $sub = (int) round(($bin - $chromosome) * 100);
        $label = binViewerLabel($chromosome, $sub);

        if ($chromosome !== $mapChromosome) {
            $mismatched[] = array('map_chromosome' => $mapChromosome, 'bin' => $label, 'loci' => $loci);
            continue;
        }
        if (!isset($validLabels[$label])) {
            $unknown[] = array('bin' => $label, 'loci' => $loci);
            continue;
        }

        if (!isset($counts[$label])) { $counts[$label] = 0; }
        $counts[$label] += $loci;
        if ($counts[$label] > $max) { $max = $counts[$label]; }
    }

    return array(
        'counts'     => $counts,
        'unplaced'   => $unplaced,
        'mismatched' => $mismatched,
        'unknown'    => $unknown,
        'max'        => $max,
        'total'      => array_sum($counts)
    );
}


/* ---------------------------------------------------------------------------
   The idiogram
   --------------------------------------------------------------------------- */

/* Five shading steps rather than a continuous ramp: the eye cannot read a
   continuous scale off 100 small rectangles, and a stepped legend can be
   labelled with real numbers. Colours are the shared green ramp. */
function binViewerShadeClass($count, $max) {
    if ($max <= 0 || $count <= 0) { return 'is-empty'; }
    $share = $count / $max;
    if ($share <= 0.2) { return 'is-q1'; }
    if ($share <= 0.4) { return 'is-q2'; }
    if ($share <= 0.6) { return 'is-q3'; }
    if ($share <= 0.8) { return 'is-q4'; }
    return 'is-q5';
}

/* Renders the ten chromosomes as one inline SVG.
 *
 * $options:
 *   href       callable($chromosome, $sub, $label) returning the bin's link
 *   counts     label => locus count, for shading and the tooltip
 *   max        largest count, for the shading scale
 *   current    label of the bin to mark as the one being viewed
 *   id_prefix  so two idiograms on one page do not share element ids
 *
 * Rendered server side rather than by script: this is the page's primary
 * navigation, and it should be there whether or not the script runs.
 */
function binViewerSvg($geometry, $options = array()) {
    $href    = isset($options['href']) ? $options['href'] : null;
    $counts  = isset($options['counts']) ? $options['counts'] : array();
    $max     = isset($options['max']) ? (int) $options['max'] : 0;
    $current = isset($options['current']) ? $options['current'] : '';
    $prefix  = isset($options['id_prefix']) ? $options['id_prefix'] : 'bin';

    $width  = isset($geometry['width']) ? (int) $geometry['width'] : 200;
    $height = isset($geometry['height']) ? (int) $geometry['height'] : 248;

    $svg  = '<svg class="bin-idiogram" viewBox="0 0 ' . $width . ' ' . $height . '" '
          . 'role="group" aria-label="The ten maize chromosomes divided into 100 cytological bins">';

    foreach ($geometry['chromosomes'] as $chromosome) {
        $chr = (int) $chromosome['chromosome'];
        $x1  = (int) $chromosome['x1'];
        $x2  = (int) $chromosome['x2'];
        $w   = $x2 - $x1;

        $svg .= '<g class="bin-chr" data-chromosome="' . $chr . '">';

        foreach ($chromosome['bins'] as $bin) {
            $label = $bin['label'];
            $y1 = (int) $bin['y1'];
            $y2 = (int) $bin['y2'];
            $h  = max(1, $y2 - $y1);

            $count = isset($counts[$label]) ? (int) $counts[$label] : 0;
            $shade = binViewerShadeClass($count, $max);
            $isCurrent = ($current !== '' && $current === $label);

            $title = 'Bin ' . $label
                   . ($count > 0 ? ' — ' . number_format($count) . ' mapped loci' : ' — no mapped loci');

            $url = $href ? call_user_func($href, $chr, (int) $bin['sub'], $label) : null;

            $rect = '<rect x="' . $x1 . '" y="' . $y1 . '" width="' . $w . '" height="' . $h . '" '
                  . 'class="bin-cell ' . $shade . ($isCurrent ? ' is-current' : '') . '" '
                  . 'data-bin="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '" '
                  . 'data-loci="' . $count . '"></rect>';

            if ($url) {
                $svg .= '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" '
                      . 'class="bin-link" aria-label="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">'
                      . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
                      . $rect . '</a>';
            } else {
                $svg .= '<g><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
                      . $rect . '</g>';
            }
        }

        // Chromosome number, in the label band the image map reserved for it.
        $labelY = isset($chromosome['label_y']) && $chromosome['label_y']
                ? (int) $chromosome['label_y'] + 11 : $height - 6;
        $svg .= '<a href="/bin_viewer?chrom=' . $chr . '" class="bin-chr-label">'
              . '<title>Chromosome ' . $chr . '</title>'
              . '<text x="' . ($x1 + $w / 2) . '" y="' . $labelY . '" text-anchor="middle">' . $chr . '</text>'
              . '</a>';

        $svg .= '</g>';
    }

    $svg .= '</svg>';
    return $svg;
}


/* ---------------------------------------------------------------------------
   Core bin marker table
   --------------------------------------------------------------------------- */

function binViewerEsc($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function binViewerCell($text, $url) {
    $text = trim((string) $text);
    if ($text === '') { return '<span class="mgdb-muted">&mdash;</span>'; }
    if (!$url) { return binViewerEsc($text); }
    return '<a href="' . binViewerEsc($url) . '">' . binViewerEsc($text) . '</a>';
}

/* One table per chromosome, all rendered, with only the selected one visible.
   The previous page did the same with ten <tbody> blocks and a display:none
   toggle; keeping them all in the document means the browser's find-in-page
   still reaches every marker. */
function binViewerMarkerTables($markers, $counts = array()) {
    $html = '';

    foreach ($markers as $chromosome) {
        $chr = (int) $chromosome['chromosome'];
        $hidden = $chr === 1 ? '' : ' hidden';

        $html .= '<div class="bin-marker-panel" id="bin-markers-chr' . $chr . '"'
               . ' role="tabpanel" aria-labelledby="bin-tab-chr' . $chr . '"' . $hidden . '>';

        $html .= '<div class="mgdb-table-scroll"><table class="mgdb-table bin-marker-table">'
               . '<caption>Core bin markers for chromosome ' . $chr . '</caption>'
               . '<thead><tr>'
               . '<th scope="col">Bin</th>'
               . '<th scope="col">Core Bin Marker</th>'
               . '<th scope="col">Molecular Marker/Probe</th>'
               . '<th scope="col">Type</th>'
               . '<th scope="col" class="mgdb-numeric">Insert</th>'
               . '<th scope="col">Enzyme&#40;s&#41;</th>'
               . '<th scope="col">Full Length Insert Sequence</th>'
               . '<th scope="col" class="mgdb-numeric">B73 RefGen_v3 Start Position</th>'
               . '<th scope="col" class="mgdb-numeric">Mapped loci</th>'
               . '</tr></thead><tbody>';

        foreach ($chromosome['rows'] as $row) {
            $classes = array();
            if (!empty($row['alternate'])) { $classes[] = 'is-alternate'; }
            if (!empty($row['note']))      { $classes[] = 'is-unplaced'; }
            $class = $classes ? ' class="' . implode(' ', $classes) . '"' : '';

            $label = trim((string) $row['bin']);
            $count = isset($counts[$label]) ? (int) $counts[$label] : null;

            $html .= '<tr' . $class . '>'
                   . '<th scope="row"><a href="/bin_viewer?fullbin=' . binViewerEsc($label) . '">'
                     . binViewerEsc($label) . '</a>'
                     . (!empty($row['alternate']) ? ' <span class="mgdb-pill mgdb-pill-warn">alternate</span>' : '')
                     . '</th>'
                   . '<td>' . binViewerCell($row['marker'], $row['marker_url']) . '</td>'
                   . '<td>' . binViewerCell($row['probe'], $row['probe_url']) . '</td>'
                   . '<td>' . binViewerCell($row['type'], null) . '</td>'
                   . '<td class="mgdb-numeric">' . binViewerCell($row['insert'], null) . '</td>'
                   . '<td>' . binViewerCell($row['enzyme'], null) . '</td>'
                   . '<td>' . binViewerCell($row['sequence'], $row['sequence_url']) . '</td>';

            if (!empty($row['note'])) {
                $html .= '<td class="bin-note">' . binViewerEsc($row['note']) . '</td>';
            } else {
                $position = trim((string) $row['position']);
                $html .= '<td class="mgdb-numeric" data-value="' . binViewerEsc($position) . '">'
                       . ($position === '' ? '<span class="mgdb-muted">&mdash;</span>'
                                           : binViewerEsc(number_format((float) $position)))
                       . '</td>';
            }

            $html .= '<td class="mgdb-numeric">'
                   . ($count === null ? '<span class="mgdb-muted">&mdash;</span>' : number_format($count))
                   . '</td>'
                   . '</tr>';
        }

        $html .= '</tbody></table></div>';

        if (!empty($chromosome['image'])) {
            $html .= '<figure class="bin-marker-figure">'
                   . '<img src="' . binViewerEsc($chromosome['image']) . '" loading="lazy" decoding="async"'
                   . ' alt="Core bin marker positions along chromosome ' . $chr
                   . ' on the B73 RefGen_v3 sequence" />'
                   . '</figure>';
        }

        if (!empty($chromosome['download'])) {
            $html .= '<p class="bin-marker-download"><a href="' . binViewerEsc($chromosome['download']) . '">'
                   . 'Download all the core bin marker sequences for Chromosome ' . $chr . '</a></p>';
        }

        $html .= '</div>';
    }

    return $html;
}

/* The chromosome selector above the marker tables. */
function binViewerMarkerTabs($markers) {
    $html = '';
    foreach ($markers as $chromosome) {
        $chr = (int) $chromosome['chromosome'];
        $current = $chr === 1;
        $html .= '<button type="button" class="bin-chr-tab' . ($current ? ' is-current' : '') . '"'
               . ' id="bin-tab-chr' . $chr . '" role="tab"'
               . ' aria-selected="' . ($current ? 'true' : 'false') . '"'
               . ' aria-controls="bin-markers-chr' . $chr . '"'
               . ' data-chromosome="' . $chr . '">' . $chr . '</button>';
    }
    return $html;
}
