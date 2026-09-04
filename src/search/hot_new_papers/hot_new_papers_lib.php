<?php
/* file: hot_new_papers_lib.php
 *
 * purpose: query builder and formatting for /hot_new_papers, the Editorial
 *          Board's recommended reading list.
 *
 * The collection is small -- 865 recommendations across 17 years, 2009 to
 * 2026 -- so everything here is one query over a few hundred rows and there is
 * nothing to cache. A search across every title, citation, abstract and
 * editorial comment runs in about 40 ms.
 *
 * What the previous page did per row
 * ----------------------------------
 * It ran the paper list once, then for every row issued a second query for the
 * editorial comments and, when a paper had a second recommending member, a
 * third for that person's name. On a year with 70 papers that is 141 queries
 * to render one page. Both are joined or batched here.
 *
 * Two things in the previous page were broken rather than slow:
 *
 *   - Sort options 3 and 4 ordered by `b.name_first`, and no table in the
 *     query is aliased `b`. Choosing "sort by recommending member" produced a
 *     SQL error. Sorting is by the joined person columns here.
 *   - Every value went into the statement by concatenation. They are bound.
 *
 * A column that looks like a visibility flag but is not
 * ----------------------------------------------------
 * ed_board_papers.curation_lvl is 10 for 729 rows and 0 for 152. It is not a
 * curation state: every row created before 2022-08-31 is 10 and every row
 * after is 0, so it records when the loading script changed, nothing more.
 * Filtering on it would hide every recommendation before 2022. The previous
 * page ignored it and so does this one.
 */

/* Most recommendations carry both an abstract and a board comment, so one
   entry is about 4 KB of text. Rendering all 865 at once came to 3.4 MB, which
   is not a page anyone reads -- it is the whole archive poured into one
   scroll. The list is capped and the reader is told, with the filters as the
   way to reach the rest. */
define('HNP_MAX_RESULTS', 250);

define('HNP_PUBMED_DB_PERSON', 134209);   /* mgdb.person "Medline -- PubMed" */
define('HNP_COMMENT_TERMS', '1187419, 3530964');  /* Editorial Board Member Comment, CODIE Member Comment */

function hnpValue($key, $default = '') {
    if (isset($_GET[$key]))  { return trim((string) $_GET[$key]); }
    if (isset($_POST[$key])) { return trim((string) $_POST[$key]); }
    return $default;
}

function hnpMonths() {
    return array('January', 'February', 'March', 'April', 'May', 'June',
                 'July', 'August', 'September', 'October', 'November', 'December');
}

function hnpMonthNumber($name) {
    $index = array_search(trim((string) $name), hnpMonths(), true);
    return $index === false ? 0 : $index + 1;
}

/* SQL fragment ordering rec_month by calendar position. rec_month is stored as
   the English month name, so it cannot be sorted as text. */
function hnpMonthOrderSql($column = 'ebp.rec_month') {
    $cases = '';
    foreach (hnpMonths() as $i => $month) {
        $cases .= " WHEN '" . $month . "' THEN " . ($i + 1);
    }
    return 'CASE ' . $column . $cases . ' ELSE 13 END';
}

/* From 2026 the Editorial Board publishes quarterly rather than monthly, so
   from that year the page filters by quarter. The months are unchanged in the
   database -- a quarter is just the three months it covers -- so both filters
   run against the same column. */
define('HNP_QUARTERLY_FROM', 2026);

function hnpQuarters() {
    return array(
        'Q1' => array('January', 'February', 'March'),
        'Q2' => array('April', 'May', 'June'),
        'Q3' => array('July', 'August', 'September'),
        'Q4' => array('October', 'November', 'December')
    );
}

function hnpQuarterMonths($quarter) {
    $quarters = hnpQuarters();
    $key = strtoupper(trim((string) $quarter));
    return isset($quarters[$key]) ? $quarters[$key] : array();
}

/* The quarter a month falls in, for labelling a card in a quarterly year. */
function hnpQuarterOf($month) {
    $number = hnpMonthNumber($month);
    if ($number === 0) { return ''; }
    return 'Q' . (int) ceil($number / 3);
}

function hnpIsQuarterlyYear($year) {
    return (int) $year >= HNP_QUARTERLY_FROM;
}

function hnpStr($value) {
    return trim((string) $value);
}

/* Only http and https links are emitted. The stored values are free text and a
   few are bare words rather than URLs. */
function hnpUrl($value) {
    $url = hnpStr($value);
    if ($url === '' || !preg_match('#^https?://#i', $url)) { return ''; }
    return str_replace(' ', '%20', $url);
}

/* A DOI may be stored bare, prefixed "doi:", or as a doi.org URL. Reduced to
   the bare identifier so the page can build one canonical link. */
function hnpDoi($value) {
    $doi = preg_replace('#^https?://(?:dx\.)?doi\.org/#i', '', hnpStr($value));
    $doi = preg_replace('/^doi\s*[:;]\s*/i', '', $doi);
    return rtrim($doi, " \t\n\r\0\x0B.,;");
}

function hnpPersonName($first, $last) {
    return trim(hnpStr($first) . ' ' . hnpStr($last));
}


/* ---------------------------------------------------------------------------
   Counts and option lists
   --------------------------------------------------------------------------- */

/* Recommendations per year. Also the year selector and the trend figure. */
function hnpYearCounts($DBConn) {
    $rows = get_all_rows(make_query($DBConn, "
        SELECT rec_year, count(*) AS papers
        FROM mgdb.ed_board_papers
        WHERE rec_month IS NOT NULL AND rec_year IS NOT NULL
        GROUP BY rec_year
        ORDER BY rec_year DESC"));

    $counts = array();
    foreach ($rows as $row) {
        $counts[(int) $row['rec_year']] = (int) $row['papers'];
    }
    return $counts;
}

/* Years between the first and the last with no recommendations recorded. The
   collection has a gap at 2018, and a chart that simply omits the year reads
   as though nothing happened rather than as though nothing was loaded. */
function hnpMissingYears($firstYear, $lastYear, $counts) {
    $missing = array();
    for ($year = (int) $firstYear; $year <= (int) $lastYear; $year++) {
        if (!isset($counts[$year])) { $missing[] = $year; }
    }
    return $missing;
}

/* Recommendations per year and month, for the figure that shows the change in
   publishing cadence. */
function hnpYearMonthCounts($DBConn) {
    $rows = get_all_rows(make_query($DBConn, "
        SELECT rec_year, rec_month, count(*) AS papers
        FROM mgdb.ed_board_papers
        WHERE rec_month IS NOT NULL AND rec_year IS NOT NULL
        GROUP BY rec_year, rec_month"));

    $grid = array();
    foreach ($rows as $row) {
        $year = (int) $row['rec_year'];
        $month = hnpMonthNumber($row['rec_month']);
        if ($month === 0) { continue; }
        if (!isset($grid[$year])) { $grid[$year] = array_fill(1, 12, 0); }
        $grid[$year][$month] += (int) $row['papers'];
    }
    ksort($grid);
    return $grid;
}

/* Everyone who has recommended a paper, in either slot, with how many. */
function hnpRecommenders($DBConn) {
    $rows = get_all_rows(make_query($DBConn, "
        SELECT p.id, p.name_first, p.name_last, count(*) AS papers
        FROM (
            SELECT person_id AS pid FROM mgdb.ed_board_papers
             WHERE rec_month IS NOT NULL AND person_id > 0
            UNION ALL
            SELECT person_id2 AS pid FROM mgdb.ed_board_papers
             WHERE rec_month IS NOT NULL AND person_id2 > 0
        ) picks
        JOIN mgdb.person p ON p.id = picks.pid
        GROUP BY p.id, p.name_first, p.name_last
        ORDER BY lower(p.name_last), lower(p.name_first)"));

    $out = array();
    foreach ($rows as $row) {
        $name = hnpPersonName($row['name_first'], $row['name_last']);
        if ($name === '') { continue; }
        $out[] = array('id' => (int) $row['id'], 'name' => $name, 'papers' => (int) $row['papers']);
    }
    return $out;
}

/* Editorial Board membership for one year. */
function hnpBoardMembers($DBConn, $year) {
    $rows = get_all_rows(make_query($DBConn, "
        SELECT p.id, p.name_first, p.name_last
        FROM mgdb.ed_board eb
          JOIN mgdb.person p ON p.id = eb.person_id
        WHERE eb.year = ?
        ORDER BY eb.auto_num", 1, array((int) $year)));

    $out = array();
    foreach ($rows as $row) {
        $name = hnpPersonName($row['name_first'], $row['name_last']);
        if ($name === '') { continue; }
        $out[] = array('id' => (int) $row['id'], 'name' => $name);
    }
    return $out;
}


/* ---------------------------------------------------------------------------
   The recommendations
   --------------------------------------------------------------------------- */

/* $filters: term, year, month, recommender (person id), sort
 *
 * Returns every matching recommendation. There are 865 in total, so there is
 * no pagination -- the largest possible answer is the whole collection.
 */
function hnpSearch($DBConn, $filters) {
    $where = array('ebp.rec_month IS NOT NULL');
    $params = array();

    $year = isset($filters['year']) ? (int) $filters['year'] : 0;
    if ($year > 0) {
        $where[] = 'ebp.rec_year = ?';
        $params[] = $year;
    }

    $month = isset($filters['month']) ? hnpStr($filters['month']) : '';
    if ($month !== '' && hnpMonthNumber($month) > 0) {
        $where[] = 'ebp.rec_month = ?';
        $params[] = $month;
    }

    $quarterMonths = hnpQuarterMonths(isset($filters['quarter']) ? $filters['quarter'] : '');
    if ($quarterMonths) {
        $where[] = 'ebp.rec_month IN (' . implode(',', array_fill(0, count($quarterMonths), '?')) . ')';
        foreach ($quarterMonths as $name) { $params[] = $name; }
    }

    $recommender = isset($filters['recommender']) ? (int) $filters['recommender'] : 0;
    if ($recommender > 0) {
        $where[] = '(ebp.person_id = ? OR ebp.person_id2 = ?)';
        $params[] = $recommender;
        $params[] = $recommender;
    }

    /* The search reaches the editorial comments as well as the paper, because
       the comment is often where the reason a paper matters is written. */
    $term = isset($filters['term']) ? hnpStr($filters['term']) : '';
    if ($term !== '') {
        $like = '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\%', '\_'), $term) . '%';
        $where[] = "(r.title ILIKE ? OR r.name ILIKE ? OR ra.abstract_1 ILIKE ?
                     OR (coalesce(p1.name_first,'') || ' ' || coalesce(p1.name_last,'')) ILIKE ?
                     OR (coalesce(p2.name_first,'') || ' ' || coalesce(p2.name_last,'')) ILIKE ?
                     OR EXISTS (SELECT 1 FROM mgdb.memo m
                                 WHERE m.id = ebp.reference_id
                                   AND m.type_term IN (" . HNP_COMMENT_TERMS . ")
                                   AND m.memo ILIKE ?))";
        for ($i = 0; $i < 6; $i++) { $params[] = $like; }
    }

    $monthOrder = hnpMonthOrderSql();
    switch (isset($filters['sort']) ? $filters['sort'] : '') {
        case 'oldest':
            $order = "ebp.rec_year ASC, $monthOrder ASC, lower(coalesce(r.title, r.name))";
            break;
        case 'title':
            $order = "lower(coalesce(r.title, r.name)), ebp.rec_year DESC";
            break;
        case 'recommender':
            /* The previous page offered this and could not run it: it ordered
               by an alias that was not in the query. */
            $order = "lower(coalesce(p1.name_last, '')), lower(coalesce(p1.name_first, '')), ebp.rec_year DESC";
            break;
        default:
            $order = "ebp.rec_year DESC, $monthOrder DESC, lower(coalesce(r.title, r.name))";
    }

    $sql = "
        SELECT ebp.auto_num, ebp.rec_month, ebp.rec_year, ebp.reference_id,
               ebp.person_id, ebp.person_id2,
               ebp.abstract_link, ebp.html_link, ebp.pdf_link,
               r.name AS citation, r.title, r.doi,
               ra.abstract_1 AS abstract,
               x.key AS pubmed,
               p1.name_first AS rec1_first, p1.name_last AS rec1_last,
               p2.name_first AS rec2_first, p2.name_last AS rec2_last
        FROM mgdb.ed_board_papers ebp
          LEFT JOIN mgdb.reference r ON r.id = ebp.reference_id
          LEFT JOIN mgdb.reference_abstract ra ON ra.id = r.id
          LEFT JOIN mgdb.person p1 ON p1.id = ebp.person_id
          LEFT JOIN mgdb.person p2 ON p2.id = ebp.person_id2
          LEFT JOIN mgdb.ext_db_key x ON x.id = ebp.reference_id
               AND x.db_person = " . HNP_PUBMED_DB_PERSON . "
        WHERE " . implode(' AND ', $where) . "
        ORDER BY " . $order;

    $rows = get_all_rows(make_query($DBConn, $sql, 1, $params));
    if (!$rows) { return array('papers' => array(), 'total' => 0, 'truncated' => false); }

    $total = count($rows);
    $truncated = empty($filters['no_limit']) && $total > HNP_MAX_RESULTS;
    if ($truncated) { $rows = array_slice($rows, 0, HNP_MAX_RESULTS); }

    return array(
        'papers'    => hnpAttachComments($DBConn, $rows),
        'total'     => $total,
        'truncated' => $truncated
    );
}

/* One query for every comment on the whole result set, rather than one per
   paper. Comments are keyed by reference, and several papers can share one. */
function hnpAttachComments($DBConn, $rows) {
    $ids = array();
    foreach ($rows as $row) {
        $id = (int) $row['reference_id'];
        if ($id > 0) { $ids[$id] = $id; }
    }

    $comments = array();
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "
            SELECT m.id, m.memo, p.name_first, p.name_last, m.type_term
            FROM mgdb.memo m
              LEFT JOIN mgdb.person p ON p.id = m.source
            WHERE m.id IN ($placeholders)
              AND m.type_term IN (" . HNP_COMMENT_TERMS . ")
            ORDER BY m.id, m.auto_num";
        $memoRows = get_all_rows(make_query($DBConn, $sql, 1, array_values($ids)));

        foreach ($memoRows as $memo) {
            $id = (int) $memo['id'];
            if (!isset($comments[$id])) { $comments[$id] = array(); }
            $comments[$id][] = array(
                'comment' => hnpStr($memo['memo']),
                'author'  => hnpPersonName($memo['name_first'], $memo['name_last'])
            );
        }
    }

    $out = array();
    foreach ($rows as $row) {
        $out[] = hnpShapePaper($row, $comments);
    }
    return $out;
}

function hnpShapePaper($row, $comments) {
    $referenceId = (int) $row['reference_id'];
    $title = hnpStr($row['title']);
    $citation = hnpStr($row['citation']);
    if ($title === '') { $title = $citation; }

    $recommenders = array();
    $first = hnpPersonName($row['rec1_first'], $row['rec1_last']);
    if ($first !== '') {
        $recommenders[] = array('id' => (int) $row['person_id'], 'name' => $first);
    }
    $second = hnpPersonName($row['rec2_first'], $row['rec2_last']);
    if ($second !== '') {
        $recommenders[] = array('id' => (int) $row['person_id2'], 'name' => $second);
    }

    $doi = hnpDoi($row['doi']);
    $pubmed = preg_replace('/\D+/', '', hnpStr($row['pubmed']));

    return array(
        'id'            => (int) $row['auto_num'],
        'reference_id'  => $referenceId,
        'month'         => hnpStr($row['rec_month']),
        'month_number'  => hnpMonthNumber($row['rec_month']),
        'quarter'       => hnpQuarterOf($row['rec_month']),
        'year'          => (int) $row['rec_year'],
        'title'         => $title,
        'citation'      => $citation,
        'abstract'      => hnpStr($row['abstract']),
        'doi'           => $doi,
        'pubmed'        => $pubmed,
        'abstract_link' => hnpUrl($row['abstract_link']),
        'html_link'     => hnpUrl($row['html_link']),
        'pdf_link'      => hnpUrl($row['pdf_link']),
        'recommenders'  => $recommenders,
        'comments'      => isset($comments[$referenceId]) ? $comments[$referenceId] : array(),
        'url'           => $referenceId > 0 ? '/data_center/reference?id=' . $referenceId : ''
    );
}


/* ---------------------------------------------------------------------------
   Rendering

   The list is rendered server side for the first view and by the page script
   after that, from the same shape, so the two cannot drift.
   --------------------------------------------------------------------------- */

function hnpEsc($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function hnpLinkList($paper) {
    $links = array();
    if ($paper['abstract_link'] !== '') {
        $links[] = '<a href="' . hnpEsc($paper['abstract_link']) . '" rel="noopener">Abstract</a>';
    }
    if ($paper['html_link'] !== '') {
        $links[] = '<a href="' . hnpEsc($paper['html_link']) . '" rel="noopener">Full text</a>';
    }
    if ($paper['pdf_link'] !== '') {
        $links[] = '<a href="' . hnpEsc($paper['pdf_link']) . '" rel="noopener">PDF</a>';
    }
    if ($paper['doi'] !== '') {
        $links[] = '<a href="https://doi.org/' . hnpEsc($paper['doi']) . '" rel="noopener">DOI</a>';
    }
    if ($paper['pubmed'] !== '') {
        $links[] = '<a href="https://pubmed.ncbi.nlm.nih.gov/' . hnpEsc($paper['pubmed']) . '/" rel="noopener">PubMed</a>';
    }
    return $links;
}

function hnpRenderPaper($paper) {
    $html = '<article class="hnp-paper">';

    /* In a quarterly year the quarter is what the board published against, so
       it leads and the month follows it. */
    $quarterly = hnpIsQuarterlyYear($paper['year']) && $paper['quarter'] !== '';
    $html .= '<div class="hnp-paper-date">'
           . ($quarterly ? '<span class="hnp-quarter">' . hnpEsc($paper['quarter']) . '</span>' : '')
           . '<span class="hnp-month">' . hnpEsc($paper['month']) . '</span>'
           . '<span class="hnp-year">' . hnpEsc($paper['year']) . '</span></div>';

    $html .= '<div class="hnp-paper-body">';

    $html .= '<h3 class="hnp-paper-title">'
           . ($paper['url'] !== ''
              ? '<a href="' . hnpEsc($paper['url']) . '">' . hnpEsc($paper['title']) . '</a>'
              : hnpEsc($paper['title']))
           . '</h3>';

    if ($paper['citation'] !== '' && $paper['citation'] !== $paper['title']) {
        $html .= '<p class="hnp-citation">' . hnpEsc($paper['citation']) . '</p>';
    }

    if ($paper['recommenders']) {
        $names = array();
        foreach ($paper['recommenders'] as $person) {
            $names[] = $person['id'] > 0
                ? '<a href="/person?id=' . (int) $person['id'] . '">' . hnpEsc($person['name']) . '</a>'
                : hnpEsc($person['name']);
        }
        $html .= '<p class="hnp-recommender"><span class="mgdb-muted">Recommended by</span> '
               . implode(' and ', $names) . '</p>';
    }

    $links = hnpLinkList($paper);
    if ($links) {
        $html .= '<p class="hnp-links">' . implode('<span aria-hidden="true"> &middot; </span>', $links) . '</p>';
    }

    foreach ($paper['comments'] as $comment) {
        $html .= '<blockquote class="hnp-comment"><p>' . nl2br(hnpEsc($comment['comment'])) . '</p>'
               . ($comment['author'] !== ''
                  ? '<footer><cite>' . hnpEsc($comment['author']) . '</cite></footer>'
                  : '')
               . '</blockquote>';
    }

    if ($paper['abstract'] !== '') {
        $html .= '<details class="hnp-abstract"><summary>Abstract</summary>'
               . '<p>' . nl2br(hnpEsc($paper['abstract'])) . '</p></details>';
    }

    $html .= '</div></article>';
    return $html;
}

function hnpRenderList($papers) {
    if (!$papers) { return ''; }
    $html = '';
    foreach ($papers as $paper) { $html .= hnpRenderPaper($paper); }
    return $html;
}
