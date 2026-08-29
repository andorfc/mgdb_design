<?php
/* file: include/home_lib.php
 *
 * purpose: the homepage's data helpers, shared by every version of the page.
 *
 * Extracted from index.php on 2026-08-25 when index2 and index3 were added as
 * design alternatives. All three read the same release dates, the same
 * precomputed metric counts, and the same news file -- only the presentation
 * differs -- so the queries live here rather than in three copies that would
 * drift apart.
 *
 * Note on naming: homeUpdateDates is deliberately not getDBUpdateDate, because
 * index_legacy.php still declares that name verbatim and index.php includes it
 * for non-home ?page= values. Two top-level declarations of one name is a fatal
 * redeclare.
 */


//
// Database update dates. Named homeUpdateDates rather than the legacy
// getDBUpdateDate because index_legacy.php still declares that name verbatim,
// and this file includes it for non-home ?page= values — two top-level
// declarations of one name is a fatal redeclare.
//
function homeUpdateDates() {
	$DBConn = connect_to_database();
	$query = "SELECT last_update, next_update from ctl ORDER BY auto_num DESC limit 1";
	$st_up = make_query($DBConn, $query);
	$rows = retrieve_row($st_up);

	return array('last_update' => date("F j, Y", strtotime($rows['last_update'])),
				 'next_update' => date("F j, Y", strtotime($rows['next_update']))
	);
}//homeUpdateDates

/* The precomputed metric counts. The fallbacks are the values measured on
   2026-08-17 and exist so a missing or unreadable file degrades to slightly
   stale numbers rather than to four zeroes on the front page. */
function homeSummary($doc_root) {
    /* assemblies_all_species is the count the homepage shows: MaizeGDB hosts
       assemblies of Zea mays and its relatives, and the maize-only figure read
       as if the others were not here. grin_accessions is the NPGS link count.
       Both come from tools/home_summary.php. */
    $defaults = array('assemblies' => 129, 'assemblies_all_species' => 161,
                      'b73_genes' => 44303, 'stocks' => 80064,
                      'grin_accessions' => 59219, 'references' => 54818);
    $file = $doc_root . '/data/home/home_summary.json';
    if (!file_exists($file)) {
        return $defaults;
    }
    $decoded = json_decode(file_get_contents($file), true);
    if (!is_array($decoded)) {
        return $defaults;
    }
    foreach ($defaults as $key => $value) {
        if (isset($decoded[$key]) && (int) $decoded[$key] > 0) {
            $defaults[$key] = (int) $decoded[$key];
        }
    }
    return $defaults;
}//homeSummary

/* Three most recent news entries, rendered server-side from data/news.xml.

   news.xml has no title field — every entry is a paragraph of curator prose
   with trusted HTML in it. The rail needs a headline, so one is derived: tags
   stripped, first sentence taken, trimmed on a word boundary. That is a
   lossy summary of curator copy, which is why the whole entry stays reachable
   through the archive link under the list rather than being hidden.

   The text is escaped on the way out. The legacy page rendered the embedded
   markup as HTML; a headline does not need it, and not re-emitting it here
   keeps the homepage out of the trusted-markup question raised for /whatsnew
   in ADMIN_DEPENDENCIES. */
function homeNewsHTML($doc_root, $limit) {
    $file = $doc_root . '/data/news.xml';
    if (!file_exists($file)) {
        return '';
    }

    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_file($file);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if ($xml === false || !isset($xml->entry)) {
        return '';
    }

    $html = '';
    $shown = 0;
    foreach ($xml->entry as $entry) {
        if ($shown >= $limit) {
            break;
        }
        $year  = trim((string) $entry->date->year);
        $month = trim((string) $entry->date->month);
        $day   = trim((string) $entry->date->day);
        $body  = (string) $entry->news;

        /* Entries may carry a thumbnail. The element is <imgg>, not <img> --
           that spelling is what the file uses and what /whatsnew reads, so it
           is matched rather than corrected. Only same-origin paths are honoured:
           the value lands in a src attribute, and news.xml is edited by hand. */
        $thumb = isset($entry->imgg) ? trim((string) $entry->imgg) : '';
        if ($thumb !== '' && strpos($thumb, '/') !== 0) {
            $thumb = '';
        }

        $headline = homeNewsHeadline($body);
        if ($headline === '') {
            continue;
        }

        $stamp = trim("$month $day, $year", ' ,');
        $iso = ($year && $month && $day)
             ? date('Y-m-d', strtotime("$month $day $year")) : '';

        $image = $thumb === '' ? '' :
              '<img class="mgdb-home-news-thumb" src="'
            . htmlspecialchars($thumb, ENT_QUOTES, 'UTF-8')
            . '" alt="" loading="lazy" decoding="async" />';

        /* The newest story leads: its picture sits above the headline at full
           card width, the way the production homepage presents it. Older
           entries keep the small thumbnail beside the text. */
        $classes = array();
        if ($image !== '') {
            $classes[] = 'mgdb-home-news-has-thumb';
            $classes[] = ($shown === 0) ? 'mgdb-home-news-lead' : 'mgdb-home-news-past';
        }

        $html .= '<li' . ($classes ? ' class="' . implode(' ', $classes) . '"' : '') . '>'
              . '<time' . ($iso ? ' datetime="' . htmlspecialchars($iso, ENT_QUOTES, 'UTF-8') . '"' : '') . '>'
              . htmlspecialchars($stamp, ENT_QUOTES, 'UTF-8') . '</time>'
              . '<a href="/whatsnew">' . $image
              . '<span>' . htmlspecialchars($headline, ENT_QUOTES, 'UTF-8') . '</span></a>'
              . "</li>\n";
        $shown++;
    }
    return $html;
}//homeNewsHTML

function homeNewsHeadline($body) {
    // Entities first: the XML stores its markup entity-encoded, so tags only
    // become tags after decoding, and only then can they be stripped.
    $text = html_entity_decode($body, ENT_QUOTES, 'UTF-8');
    $text = strip_tags($text);
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    if ($text === '') {
        return '';
    }

    // First sentence, when one ends inside a reasonable headline length.
    if (preg_match('/^(.{20,150}?[.!?])\s/u', $text, $match)) {
        $text = $match[1];
    }

    if (function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') > 96 : strlen($text) > 96) {
        $cut = function_exists('mb_substr') ? mb_substr($text, 0, 96, 'UTF-8') : substr($text, 0, 96);
        $space = strrpos($cut, ' ');
        if ($space !== false && $space > 40) {
            $cut = substr($cut, 0, $space);
        }
        $text = rtrim($cut, " .,;:") . '…';
    }
    return $text;
}//homeNewsHeadline
//
// Cache-busting token for the quick-link icons.
//
// The icons are static paths in the template, so without this a retuned icon
// keeps its URL and both the browser and Cloudflare go on serving the old one
// -- observed on 2026-08-25, when the CDN held a superseded icon for 47 minutes
// after deploy. Newest mtime in the directory, so any icon change moves every
// icon's URL exactly once.
//
function homeIconVersion($doc_root) {
    $dir = $doc_root . '/images/quicklinks';
    $newest = 0;
    foreach ((array) glob($dir . '/*.png') as $file) {
        $m = @filemtime($file);
        if ($m > $newest) {
            $newest = $m;
        }
    }

    return $newest ? '?v=' . $newest : '';
}//homeIconVersion
?>
