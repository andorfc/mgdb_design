<?php
/* file: include/references_lib.php
 *
 * purpose: render a cited paper the same way on every page.
 *
 * The shape is /NAM_project's, which is where the group settled it: journal and
 * year as a pill, the DOI in mono beside it, the title in burgundy, then the
 * authors, the citation, the abstract in a green-edged well, and the links as
 * buttons. The styling lives in css/mgdb-hub.css under `.mgdb-ref*`; this file
 * is the matching markup, so a page cannot get one without the other.
 *
 * The content comes from data/cite_journal_articles.json -- the curated
 * bibliography behind /cite, with verified titles, authors, journals, volumes,
 * DOIs, PubMed IDs and abstracts. A page names the DOIs it wants and gets the
 * curated record; there is no second copy of a citation to drift.
 *
 * Contract
 * --------
 *   include_once('./include/references_lib.php');
 *
 *   echo mgdb_render_references($doc_root, array(
 *       array('doi' => '10.1093/g3journal/jkae281'),
 *       array('doi' => '10.1101/2022.11.10.516002', 'kind' => 'Preprint',
 *             'fallback' => array(  // for anything not in the bibliography
 *                 'title' => '...', 'authors' => '...', 'journal' => 'bioRxiv',
 *                 'year' => 2022)),
 *   ));
 *
 * A DOI that is neither in the bibliography nor given a fallback title is
 * skipped rather than rendered empty.
 */

//
// The bibliography, indexed by lower-cased DOI. Read once per request.
//
function mgdb_reference_index($doc_root) {
    static $index = null;
    if ($index !== null) {
        return $index;
    }

    $index = array();
    $file = rtrim($doc_root, '/') . '/data/cite_journal_articles.json';
    if (!is_readable($file)) {
        return $index;
    }

    $rows = json_decode((string) file_get_contents($file), true);
    if (!is_array($rows)) {
        return $index;
    }

    foreach ($rows as $row) {
        if (!empty($row['doi'])) {
            $index[strtolower(trim($row['doi']))] = $row;
        }
    }

    return $index;
}

function mgdb_ref_esc($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

//
// A compact journal name for the pill.
//
// The bibliography stores NLM-style names, which carry a place of publication
// or an expanded subtitle: "Bioinformatics (Oxford, England)", "G3 (Bethesda,
// Md.)", "Database : the journal of biological databases and curation". Those
// are the record, and the citation line below prints them in full; the pill is
// a label and only has room for the name itself. Nothing is dropped that the
// card does not also show verbatim.
//
function mgdb_reference_short_journal($journal) {
    $short = preg_replace('/\s*\([^)]*\)\s*$/', '', trim((string) $journal));
    $short = preg_replace('/\s*:\s.*$/', '', $short);
    return $short !== '' ? $short : trim((string) $journal);
}

//
// "Genetics 2026; 232:iyag005." -- volume and pages only when the record has
// them, so a preprint does not print stray punctuation.
//
function mgdb_reference_citation($row) {
    $out = '<em>' . mgdb_ref_esc($row['journal']) . '</em>';
    if (!empty($row['year'])) {
        $out .= ' ' . mgdb_ref_esc($row['year']);
    }
    $tail = '';
    if (!empty($row['volume'])) {
        $tail .= mgdb_ref_esc($row['volume']);
    }
    if (!empty($row['pages'])) {
        $tail .= ($tail !== '' ? ':' : '') . mgdb_ref_esc($row['pages']);
    }
    if ($tail !== '') {
        $out .= '; ' . $tail;
    }
    return $out . '.';
}

//
// One reference card.
//
function mgdb_render_reference($row, $seq) {
    if (empty($row['title'])) {
        return '';
    }

    $doi = isset($row['doi']) ? trim($row['doi']) : '';
    $url = !empty($row['url'])
         ? $row['url']
         : ($doi !== '' ? 'https://doi.org/' . $doi : '');

    $cite_id = 'mgdb-ref-cite-' . $seq;

    // The plain-text form the Copy citation button hands over.
    $plain = trim($row['authors'] . ' (' . $row['year'] . ') ' . $row['title'] . '. '
           . $row['journal'] . '.' . ($doi !== '' ? ' doi:' . $doi : ''));

    $html  = '<article class="mgdb-ref">';

    $html .= '<div class="mgdb-ref-meta">';
    $html .= '<span class="mgdb-ref-badge">' . mgdb_ref_esc(mgdb_reference_short_journal($row['journal']));
    if (!empty($row['year'])) {
        $html .= ' &bull; ' . mgdb_ref_esc($row['year']);
    }
    $html .= '</span>';
    if (!empty($row['kind'])) {
        $html .= '<span class="mgdb-ref-badge">' . mgdb_ref_esc($row['kind']) . '</span>';
    }
    if ($doi !== '') {
        $html .= '<span class="mgdb-ref-doi">DOI&#58; ' . mgdb_ref_esc($doi) . '</span>';
    }
    $html .= '</div>';

    $html .= '<h3 class="mgdb-ref-title">';
    $html .= $url !== ''
           ? '<a href="' . mgdb_ref_esc($url) . '" target="_blank" rel="noopener">' . mgdb_ref_esc($row['title'])
             . ' <span aria-hidden="true">&nearr;</span></a>'
           : mgdb_ref_esc($row['title']);
    $html .= '</h3>';

    if (!empty($row['authors'])) {
        $html .= '<p class="mgdb-ref-authors">' . mgdb_ref_esc($row['authors']) . '</p>';
    }
    if (!empty($row['journal'])) {
        $html .= '<p class="mgdb-ref-citation">' . mgdb_reference_citation($row) . '</p>';
    }

    /* Some bibliography records carry only a database URL where the abstract
       should be -- 49 characters in one case. A stub reads as a broken panel,
       so the well is only opened for text long enough to be an abstract. */
    $abstract = isset($row['abstract']) ? trim($row['abstract']) : '';
    if (mb_strlen($abstract, 'UTF-8') > 120) {
        $html .= '<div class="mgdb-ref-abstract"><h4>Abstract</h4><p>'
               . mgdb_ref_esc($abstract) . '</p></div>';
    }

    $html .= '<div class="mgdb-ref-actions">';
    if ($url !== '') {
        $html .= '<a class="mgdb-button mgdb-button-primary" href="' . mgdb_ref_esc($url)
               . '" target="_blank" rel="noopener">Full text <span aria-hidden="true">&nearr;</span></a>';
    }
    if (!empty($row['pubmed'])) {
        $html .= '<a class="mgdb-button mgdb-button-secondary" href="https://pubmed.ncbi.nlm.nih.gov/'
               . mgdb_ref_esc($row['pubmed']) . '/" target="_blank" rel="noopener">PubMed '
               . mgdb_ref_esc($row['pubmed']) . ' <span aria-hidden="true">&nearr;</span></a>';
    }
    if (!empty($row['record'])) {
        $html .= '<a class="mgdb-button mgdb-button-quiet" href="' . mgdb_ref_esc($row['record'])
               . '">MaizeGDB record <span aria-hidden="true">&rarr;</span></a>';
    }
    $html .= '<button class="mgdb-ref-copy" type="button" data-copy-target="' . $cite_id . '">Copy citation</button>';
    if ($doi !== '') {
        $html .= '<button class="mgdb-ref-copy" type="button" data-copy-value="' . mgdb_ref_esc($doi) . '">Copy DOI</button>';
    }
    $html .= '</div>';

    $html .= '<div id="' . $cite_id . '" class="mgdb-visually-hidden">' . mgdb_ref_esc($plain) . '</div>';
    $html .= '</article>';

    return $html;
}

//
// The list. $items is an ordered array of specs; see the contract at the top.
//
function mgdb_render_references($doc_root, $items) {
    $index = mgdb_reference_index($doc_root);
    $html  = '';
    $seq   = 0;

    foreach ((array) $items as $item) {
        $doi = isset($item['doi']) ? strtolower(trim($item['doi'])) : '';
        $row = isset($index[$doi]) ? $index[$doi] : array();

        // A page's own values win over the bibliography, so a card can be
        // corrected in place without editing the shared file.
        $fallback = isset($item['fallback']) ? $item['fallback'] : array();
        foreach ($fallback as $key => $value) {
            if (empty($row[$key])) {
                $row[$key] = $value;
            }
        }
        foreach (array('kind', 'record', 'url') as $key) {
            if (!empty($item[$key])) {
                $row[$key] = $item[$key];
            }
        }
        if (empty($row['doi']) && $doi !== '') {
            $row['doi'] = $item['doi'];
        }

        $card = mgdb_render_reference($row, ++$seq);
        if ($card === '') {
            reportError('references_lib: no record and no fallback for DOI ' . $doi);
            continue;
        }
        $html .= $card;
    }

    return $html;
}
?>
