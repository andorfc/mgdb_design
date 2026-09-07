<?php
/* file: include/person_search_lib.php
 *
 * purpose: build the WHERE and ORDER BY for a person/organization search, once,
 *          for both endpoints that run one.
 *
 * Why this exists
 * ---------------
 * MaizeGDB stores a person as a citation, not as a name:
 *
 *     name        "Buckler, ES, IV"
 *     name_first  "Edward"
 *     name_last   "Buckler"
 *
 * Both endpoints matched a query the obvious way -- `name LIKE '%<term>%'`, plus
 * a prefix match on name_first and on name_last -- and every one of those fails
 * for a name typed the way a person says it. "Ed Buckler" is not a substring of
 * "Buckler, ES, IV"; it is not a prefix of "Edward"; it is not a prefix of
 * "Buckler". So /person?term=Ed%20Buckler found nothing, and so did Barbara
 * McClintock, Virginia Walbot and Sarah Hake -- four of the seven example chips
 * the search page offers. The three that worked were the organizations, whose
 * `name` really does contain what you type.
 *
 * What is added
 * -------------
 * When a query has two or more words it is also read as a personal name, in
 * both orders, against the two columns that hold the parts:
 *
 *     "Ed Buckler"        given "ed",     surname "buckler"
 *     "Buckler Edward"    given "edward", surname "buckler"
 *
 * The given name is matched as a **prefix**, which is what makes "Ed" find
 * "Edward" and "E" find either. The surname is matched exactly, or as the tail
 * of a compound surname, so "Ana Cruz" still reaches "de la Cruz". Only the
 * first and last words are used: "Edward S Buckler" is given "edward", surname
 * "buckler", rather than trying to guess which middle words are names.
 *
 * Everything the old clauses matched, they still match. This only adds rows.
 *
 * Contract
 * --------
 *   include_once('./include/person_search_lib.php');
 *   $c = mgdbPersonSearchClauses($term, array('synonyms' => true));
 *   $sql = "SELECT ... WHERE {$c['where']} ORDER BY {$c['order']} LIMIT 75";
 *   $stmt = make_query($DBConn, $sql, 1, $c['params']);
 *
 * `params` is already in placeholder order: the WHERE's, then the ORDER BY's.
 * `synonyms` says whether the caller joined SYNONYMS S; the suggestion endpoint
 * does not.
 */

/**
 * The two name parts of a query, or null when it is not two words.
 *
 * Split on whitespace and commas together, so "Buckler, Edward" arrives here as
 * the same two words as "Edward Buckler" and gets both readings. A trailing
 * period is dropped so "E. Buckler" is the initial "e", not "e.".
 */
function mgdbPersonNameParts($term) {
    $words = preg_split('/[\s,]+/u', strtolower(trim((string) $term)), -1, PREG_SPLIT_NO_EMPTY);
    $words = array_values(array_filter(array_map(function ($w) {
        return rtrim($w, '.');
    }, $words), 'strlen'));

    if (count($words) < 2) {
        return null;
    }
    return array('head' => $words[0], 'tail' => $words[count($words) - 1]);
}

/**
 * One reading of a two-word name: this surname, that given-name prefix.
 *
 * The surname is `= surname` OR `LIKE '% surname'` -- exact, or the last word of
 * a compound one. It is deliberately not `LIKE '%surname%'`: that would make
 * "Ed Ha" match every name containing "ha" and turn a precise lookup into a
 * substring sweep over 58,000 rows.
 */
function mgdbPersonNameOrderSql() {
    return "((LOWER(COALESCE(P.NAME_LAST, '')) = ? OR LOWER(COALESCE(P.NAME_LAST, '')) LIKE ?)"
         . " AND LOWER(COALESCE(P.NAME_FIRST, '')) LIKE ?)";
}

function mgdbPersonNameOrderParams($surname, $given) {
    return array($surname, '% ' . $surname, $given . '%');
}

/**
 * The WHERE and ORDER BY for a search term.
 */
function mgdbPersonSearchClauses($term, $options = array()) {
    $with_synonyms = !empty($options['synonyms']);

    $lower    = strtolower(trim((string) $term));
    $contains = '%' . $lower . '%';
    $prefix   = $lower . '%';

    $parts = mgdbPersonNameParts($lower);

    /* ---- WHERE ---------------------------------------------------------- */
    $where  = "LOWER(COALESCE(P.NAME, '')) LIKE ?\n";
    $where .= "         OR LOWER(COALESCE(P.NAME_FIRST, '')) LIKE ?\n";
    $where .= "         OR LOWER(COALESCE(P.NAME_LAST, '')) LIKE ?\n";
    $where .= "         OR LOWER(COALESCE(ORG.NAME, '')) LIKE ?";
    $params = array($contains, $prefix, $prefix, $contains);

    if ($with_synonyms) {
        $where .= "\n         OR LOWER(COALESCE(S.SYNONYMS, '')) LIKE ?";
        $params[] = $contains;
    }

    if ($parts) {
        // "Ed Buckler": given first, surname last -- the order people type.
        $where .= "\n         OR " . mgdbPersonNameOrderSql();
        $params = array_merge($params, mgdbPersonNameOrderParams($parts['tail'], $parts['head']));
        // "Buckler Edward" and "Buckler, Edward": surname first.
        $where .= "\n         OR " . mgdbPersonNameOrderSql();
        $params = array_merge($params, mgdbPersonNameOrderParams($parts['head'], $parts['tail']));
    }

    /* ---- ORDER BY ------------------------------------------------------- */
    /* A whole-name match first, then the two name readings -- someone who typed
       a first and last name meant that person, not everyone whose institution
       contains one of the words -- then the old tiers in their old order. */
    $order  = "CASE\n";
    $order .= "        WHEN LOWER(P.NAME) = ? THEN 0\n";
    $params[] = $lower;

    if ($parts) {
        $order .= "        WHEN " . mgdbPersonNameOrderSql() . " THEN 1\n";
        $params = array_merge($params, mgdbPersonNameOrderParams($parts['tail'], $parts['head']));
        $order .= "        WHEN " . mgdbPersonNameOrderSql() . " THEN 2\n";
        $params = array_merge($params, mgdbPersonNameOrderParams($parts['head'], $parts['tail']));
    }

    $order .= "        WHEN LOWER(COALESCE(P.NAME_LAST, '')) = ? THEN 3\n";
    $order .= "        WHEN LOWER(P.NAME) LIKE ? THEN 4\n";
    $order .= "        WHEN LOWER(COALESCE(P.NAME_LAST, '')) LIKE ? THEN 5\n";
    $order .= "        WHEN LOWER(COALESCE(P.NAME_FIRST, '')) LIKE ? THEN 6\n";
    $order .= "        WHEN LOWER(COALESCE(ORG.NAME, '')) LIKE ? THEN 7\n";
    $order .= "        ELSE 8 END,\n";
    $order .= "        LOWER(P.NAME)";
    $params = array_merge($params, array($lower, $prefix, $prefix, $prefix, $prefix));

    return array('where' => $where, 'order' => $order, 'params' => $params);
}
