<?php
/* file: search/new_genes/new_genes_lib.php
 *
 * purpose: the query behind /new_genes -- characterized maize genes whose
 *          annotation changed, with a separate date per annotation type.
 *
 * Reads THIS server's database only. The page it feeds replaced a design in
 * which the curation server rendered HTML and rsync'd it to
 * /home/cache/newgene/; that directory has been empty on this instance since
 * 2023, so the page threw a Bauplan error and served 178 bytes. Nothing here
 * depends on another host.
 *
 * Each annotation type is its own aggregate joined back to the gene set, not an
 * OR'd pass or a correlated subquery -- the whole statement runs in about a
 * second over 18,879 characterized genes.
 *
 * The join paths are not the obvious ones, and three of them were wrong on the
 * first attempt. Verified against the live database 2026-09-05:
 *   Reference    id_reference.id = locus, .reference -> id_num.mod_date
 *   Gene Product locus_gene_products.id = locus, .gene_product -> id_num. This
 *                is the table include/gene_center_lib.php and the v1 API both
 *                use, i.e. the site's own definition. Two wrong tables were
 *                tried first and each looked plausible: mgdb.relation reaches
 *                3 genes in a year, mgdb.gene_prod_links reaches 1,010, and
 *                this one reaches 9,685 of the 18,879 characterized genes.
 *                When a link table's coverage disagrees with the page you are
 *                replacing, find the query the record page runs.
 *   Variation    variation.variationof = locus
 *   Stock        stock_genotypic_var -> variation -> variationof
 *   Gene model   chado.gene_model.locus_id, current and not obsolete (NOT
 *                mgdb.id_gene_model, whose ids reach only 442 characterized
 *                genes against this table's 11,745)
 */

/* A gene is "characterized" if a curator has given it a full name -- 18,879 of
   781,395 loci. That is the set the legacy page listed. */
function ng_base_sql() {
    return "
WITH gene AS (
  SELECT l.id, l.name, l.full_name, i.mod_date AS locus_mod
  FROM mgdb.locus l
  JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
  WHERE l.full_name IS NOT NULL AND l.full_name <> ''
),
ref AS (
  SELECT ir.id AS lid, MAX(ri.mod_date) AS d, COUNT(*) AS n
  FROM mgdb.id_reference ir
  JOIN gene g ON g.id = ir.id
  JOIN mgdb.id_num ri ON ri.id = ir.reference AND ri.curation_lvl = 0
  GROUP BY ir.id
),
gp AS (
  SELECT lgp.id AS lid, MAX(gi.mod_date) AS d, COUNT(DISTINCT lgp.gene_product) AS n
  FROM mgdb.locus_gene_products lgp
  JOIN gene g ON g.id = lgp.id
  JOIN mgdb.id_num gi ON gi.id = lgp.gene_product AND gi.curation_lvl = 0
  GROUP BY lgp.id
),
var AS (
  SELECT v.variationof AS lid, MAX(vi.mod_date) AS d, COUNT(*) AS n
  FROM mgdb.variation v
  JOIN gene g ON g.id = v.variationof
  JOIN mgdb.id_num vi ON vi.id = v.id AND vi.curation_lvl = 0
  GROUP BY v.variationof
),
stk AS (
  SELECT v.variationof AS lid, MAX(si.mod_date) AS d, COUNT(DISTINCT sgv.id) AS n
  FROM mgdb.stock_genotypic_var sgv
  JOIN mgdb.variation v ON v.id = sgv.variation
  JOIN gene g ON g.id = v.variationof
  JOIN mgdb.id_num si ON si.id = sgv.id AND si.curation_lvl = 0
  GROUP BY v.variationof
),
gm AS (
  SELECT gm.locus_id AS lid,
         COUNT(DISTINCT gm.gene_name) AS n,
         string_agg(DISTINCT gm.gene_name, ' ' ORDER BY gm.gene_name) AS models
  FROM chado.gene_model gm
  JOIN gene g ON g.id = gm.locus_id
  WHERE gm.analysis_is_current = 'yes' AND gm.is_obsolete = false
  GROUP BY gm.locus_id
)
SELECT g.id, g.name, g.full_name, g.locus_mod,
       ref.d AS ref_date, ref.n AS ref_n,
       gp.d  AS gp_date,  gp.n  AS gp_n,
       var.d AS var_date, var.n AS var_n,
       stk.d AS stk_date, stk.n AS stk_n,
       gm.n  AS gm_n,     gm.models,
       GREATEST(COALESCE(g.locus_mod,'epoch'::timestamp),
                COALESCE(ref.d,'epoch'::timestamp),
                COALESCE(gp.d,'epoch'::timestamp),
                COALESCE(var.d,'epoch'::timestamp),
                COALESCE(stk.d,'epoch'::timestamp)) AS last_update
FROM gene g
LEFT JOIN ref ON ref.lid = g.id
LEFT JOIN gp  ON gp.lid  = g.id
LEFT JOIN var ON var.lid = g.id
LEFT JOIN stk ON stk.lid = g.id
LEFT JOIN gm  ON gm.lid  = g.id";
}

/* The windows the legacy page offered, kept. 'alltime' still needs a floor or
   the list is every characterized gene ever touched. */
function ng_windows() {
    return array(
        'month'   => array('label' => 'Past month',    'days' => 31),
        '6month'  => array('label' => 'Past 6 months', 'days' => 183),
        'year'    => array('label' => 'Past year',     'days' => 365),
        'alltime' => array('label' => 'All time',      'days' => null),
    );
}

/* The snapshot date. This database is a monthly copy of the curation server, so
   the page states how current it is rather than saying "today" over month-old
   rows the way the rsync'd page did. */
function ng_snapshot_date($conn) {
    $r = $conn->query("SELECT MAX(mod_date)::date AS d FROM mgdb.id_num")->fetch(PDO::FETCH_ASSOC);
    return $r ? $r['d'] : null;
}

/* Windows are measured back from the SNAPSHOT, not from now().
 *
 * This matters more than it looks. The production database is a monthly copy of
 * the curation server, so on 2026-09-05 its newest row was 2026-08-03 -- 33 days
 * old. Anchored on now(), "past month" asked for changes since 2026-08-05 and
 * returned ZERO genes, which would have made the page's own default view empty
 * while the data behind it was fine. Anchored on the snapshot, "past month"
 * means the month of curation this copy actually contains, and the page states
 * the snapshot date beside it. */
function ng_window_floor($conn, $window) {
    $windows = ng_windows();
    $days = isset($windows[$window]) ? $windows[$window]['days'] : 365;
    if ($days === null) { return null; }
    $st = $conn->prepare("SELECT (MAX(mod_date) - (:d || ' days')::interval) AS floor FROM mgdb.id_num");
    $st->execute(array(':d' => (int) $days));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? $r['floor'] : null;
}

/* $limit === null means every matching row. The download uses that: an export
   that silently reuses the page's display cap hands back a truncated file under
   a button that says "Download". */
function ng_rows($conn, $window = 'year', $limit = 500) {
    $floor = ng_window_floor($conn, $window);
    $sql = "SELECT * FROM (" . ng_base_sql() . ") q";
    $args = array();
    if ($floor !== null) { $sql .= " WHERE q.last_update > :floor"; $args[':floor'] = $floor; }
    else                 { $sql .= " WHERE q.last_update > 'epoch'::timestamp"; }
    $sql .= " ORDER BY q.last_update DESC, q.name ASC";
    $sql .= ($limit === null) ? " LIMIT ALL" : (" LIMIT " . (int) $limit);
    $st = $conn->prepare($sql);
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function ng_counts($conn) {
    $out = array();
    foreach (array_keys(ng_windows()) as $w) {
        $floor = ng_window_floor($conn, $w);
        $sql = "SELECT COUNT(*) n FROM (" . ng_base_sql() . ") q WHERE q.last_update > "
             . ($floor === null ? "'epoch'::timestamp" : ":floor");
        $st = $conn->prepare($sql);
        $st->execute($floor === null ? array() : array(':floor' => $floor));
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $out[$w] = (int) $r['n'];
    }
    return $out;
}
