<?php
/* file: include/reference_ids_lib.php
 *
 * purpose: the DOI and the PubMed ID of a reference, as SQL -- one definition
 *          for every page that prints one.
 *
 * Used by the reference hub (search/reference/reference_search_lib.php, whose
 * referenceDoiSql() is this) and by every record page's references section
 * (include/api/v1/records/*.php). Before 2026-09-18 the record pages had a
 * definition of their own, copied into thirteen files: the column, then the
 * citation text. It never looked in mgdb.ext_db_key, so wx1 showed a DOI on 52
 * of its 274 references where the database holds one for 97.
 *
 * Where a DOI is kept, in the order they are read (54,900 curated references,
 * measured 2026-09-18; 8,506 end up with a DOI)
 * -----------------------------------------------------------------------
 *   1. mgdb.reference.doi -- 478. Every one of them is also in (2), and the
 *      two never disagree.
 *   2. mgdb.ext_db_key under db_person 2738676, "Digital Object Identifier
 *      (DOI), -" -- 8,482, of which 8,004 are nowhere else.
 *   3. The citation string, mgdb.reference.name -- 24 more. Read last,
 *      because it is cut short: "Theor Appl Genet doi: 10.1007/s00122-014-2"
 *      for 10.1007/s00122-014-2419-3. Every truncated or contradictory one of
 *      those (20) is on a reference that also has the DOI in (2), which wins;
 *      the 24 it alone supplies all carry the DOI whole.
 *
 * Both stores are free text and hold junk -- "none", "dup", "doi", "123",
 * "doi: 10.x/y", "https://doi.org/10.x/y" -- so the DOI is extracted by
 * pattern, a 10.NNNN prefix and a suffix, with trailing punctuation removed.
 * Anything without that shape is not a DOI and comes back NULL.
 *
 * Some rows fail that pattern only because of how they were pasted: slashes
 * URL-encoded as %2F (three references), and zero-width spaces stored as
 * "&#8203;" or as a literal U+00BF (on records other than references today).
 * Those are decoded or removed before matching. Twelve more are typos
 * ("0.1186/...", "!0.3389/...") and are left alone: repairing one would be a
 * guess.
 *
 * A reference can hold several rows under the DOI source (4 do). The first row
 * that *is* a DOI is taken, not the first row and then a test of it, so a junk
 * row filed ahead of the real one cannot hide it. None does today; it is a
 * guard, not a fix.
 */

function mgdbReferenceDoiSql($alias = 'r') {
    return "NULLIF(regexp_replace(COALESCE(
              substring(btrim($alias.doi) from '10[.][0-9]{4,9}/[^[:space:]]+'),
              (SELECT substring(xk.clean from '10[.][0-9]{4,9}/[^[:space:]]+')
                 FROM mgdb.ext_db_key xd
                   CROSS JOIN LATERAL (
                     SELECT regexp_replace(regexp_replace(replace(xd.key, '&#8203;', ''),
                                                          '%2[Ff]', '/', 'g'),
                                           '[\\u00BF\\u200B-\\u200D\\uFEFF]', '', 'g') AS clean) xk
                 WHERE xd.id = $alias.id AND xd.db_person = 2738676
                   AND xk.clean ~ '10[.][0-9]{4,9}/'
                 ORDER BY xd.auto_num LIMIT 1),
              substring($alias.name from '10[.][0-9]{4,9}/[^[:space:]]+')
            ), '[.,;}]+$', ''), '')";
}

/* mgdb.ext_db_key under db_person 134209, "Medline -- PubMed": 9,054 rows, one
   per reference. All but one are a bare number; the exception is a GenBank
   accession filed there by mistake, which would make a PubMed link to nothing,
   so only a number is taken. */
function mgdbReferencePubmedSql($alias = 'r') {
    return "(SELECT btrim(xp.key) FROM mgdb.ext_db_key xp
              WHERE xp.id = $alias.id AND xp.db_person = 134209
                AND xp.key ~ '^[[:space:]]*[0-9]+[[:space:]]*$'
              ORDER BY xp.auto_num LIMIT 1)";
}
?>
