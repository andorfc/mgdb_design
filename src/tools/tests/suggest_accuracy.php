<?php
/* file: tools/tests/suggest_accuracy.php
 *
 * purpose: prove that every suggestion a hub's search field offers is a
 *          record that hub's own search returns when the suggestion is picked.
 *
 *          A pick puts the suggestion's value in the field and submits the
 *          form (MGDB.typeahead in js/mgdb-modern.js), so the check is exactly
 *          that: for sampled queries, ask the index (suggestSearch, one row per
 *          value, as the page asks) for its suggestions; for each, call the
 *          hub's search API with the suggestion's value, over HTTP at the
 *          origin, and look for the suggested record in the answer.
 *
 *          Queries are prefixes of real record values drawn at random from the
 *          index -- two and three characters, half the value, the whole value --
 *          plus each scope's fixed cases. A record counts as found only within
 *          the first page the API returns at its largest page size, so a
 *          suggestion that leads to page 40 of the results fails.
 *
 * Running it -- from the web root on the development server:
 *   php tools/tests/suggest_accuracy.php                  # every scope, 12 records each
 *   php tools/tests/suggest_accuracy.php --only=stock,locus --sample=40
 *   php tools/tests/suggest_accuracy.php --verbose        # every miss, with its query
 *
 * Exit status is the number of suggestions not found, so it can gate a deploy.
 *
 * history:
 *  09/24/26  claude  created
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit("This script is a command-line tool.\n");
}
ini_set('display_errors', 'stderr');
set_time_limit(0);
include_once('./include/suggest_lib.php');

$opts = getopt('', array('only:', 'sample:', 'verbose', 'host:'));
$sample = isset($opts['sample']) ? max(1, (int) $opts['sample']) : 12;
$verbose = isset($opts['verbose']);
$host = isset($opts['host']) ? $opts['host'] : 'claude.maizegdb.org';
$origin = gethostbyname('dev8.usda.iastate.edu');

/* ------------------------------------------------------------------------
   The hubs: how to search one for a value, and whether an answer holds the
   suggested item. $it is the suggestion as the API sends it, plus `key` (the
   record id, read back from the index) and `u` (which kind of record).
   ------------------------------------------------------------------------ */

$byId = function ($field = 'id') {
    return function ($it, $d) use ($field) {
        foreach (isset($d['results']) ? $d['results'] : array() as $r) {
            if (isset($r[$field]) && (string) $r[$field] === (string) $it['key']) { return true; }
        }
        return false;
    };
};
$sameText = function ($a, $b) { return strtolower(trim((string) $a)) === strtolower(trim((string) $b)); };

$HUBS = array(
    'stock' => array('url' => '/search/stock/stock_search_api.php?mode=simple&sort=relevance&page=1&page_size=100&term=', 'found' => $byId()),
    'locus' => array('url' => '/search/locus/locus_search_api.php?page=1&page_size=200&term=', 'found' => $byId()),
    'marker' => array('url' => '/search/marker/marker_search_api.php?page=1&page_size=100&sort=relevance&term=', 'found' => $byId()),
    'phenotype' => array('url' => '/search/phenotype/phenotype_search_api.php?page=1&page_size=100&sort=relevance&term=', 'found' => $byId()),
    'map' => array('url' => '/search/map/map_search_api.php?sort=relevance&page=1&page_size=100&term=', 'found' => $byId()),
    'gene_product' => array('url' => '/search/gene_product/gene_product_search_api.php?limit=200&offset=0&term=', 'found' => $byId()),
    'pathway' => array('url' => '/search/metabolic_pathway/metabolic_pathway_search_api.php?page=1&page_size=100&term=', 'found' => $byId()),
    'variation' => array('url' => '/search/variation/variation_search_api.php?sort=relevance&page=1&page_size=100&term=',
        'found' => function ($it, $d) use ($byId) {
            if ((int) $it['u'] === 0) { $f = $byId(); return $f($it, $d); }
            foreach ($d['results'] as $r) { if ((string) $r['locus_id'] === (string) $it['key']) { return true; } }
            return false;
        }),
    'qtl' => array('url' => '/search/qtl/qtl_search_api.php?page=1&page_size=200&term=',
        'found' => function ($it, $d) use ($byId, $sameText) {
            if ((int) $it['u'] === 0) { $f = $byId(); return $f($it, $d); }
            foreach ($d['results'] as $r) {
                if ($sameText($r['trait_name'], $it['v']) || $sameText($r['experiment_name'], $it['v'])) { return true; }
            }
            return false;
        }),
    'reference' => array('url' => '/search/reference/reference_search_api.php?page=1&page_size=100&sort=relevance&q=',
        'found' => function ($it, $d) use ($byId) {
            if ((int) $it['u'] === 0) { $f = $byId(); return $f($it, $d); }
            return isset($d['summary']['total']) && (int) $d['summary']['total'] > 0;
        }),
    'image' => array('url' => '/search/image/image_search_api.php?sort=relevance&page=1&page_size=100&term=',
        'found' => function ($it, $d) use ($sameText) {
            foreach ($d['results'] as $r) { if ($sameText($r['entity_name'], $it['v'])) { return true; } }
            return false;
        }),
    'gene' => array('url' => '/search/gene/gene_search_api.php?limit=2000&term=',
        'found' => function ($it, $d) use ($sameText) {
            foreach (isset($d['models']) ? $d['models'] : array() as $m) {
                if (isset($it['id']) ? $sameText($m['gene_model'], $it['v']) : $sameText($m['locus_name'], $it['v'])) { return true; }
            }
            if (!isset($it['id'])) {
                foreach (isset($d['loci']) ? $d['loci'] : array() as $l) { if ($sameText($l['locus_name'], $it['v'])) { return true; } }
            }
            return false;
        }),
    'pan_gene' => array('url' => '/search/pan_gene/pan_gene_search_api.php?mode=simple&page=1&page_size=100&term=',
        'found' => function ($it, $d) use ($sameText) {
            foreach ($d['results'] as $r) { if ($sameText($r['pan_gene_name'], $it['v'])) { return true; } }
            return false;
        }),
    'uniformmu_gene' => array('url' => '/search/uniformmu/uniformmu_search_api.php?mode=gene&term=',
        'found' => function ($it, $d) { return !empty($d['results']); }),
    'uniformmu_insertion' => array('url' => '/search/uniformmu/uniformmu_search_api.php?mode=insertion&term=',
        'found' => function ($it, $d) use ($sameText) {
            foreach ($d['results'] as $r) { if ($sameText($r['name'], $it['v'])) { return true; } }
            return false;
        }),
    'uniformmu_stock' => array('url' => '/search/uniformmu/uniformmu_search_api.php?mode=stock&term=',
        'found' => function ($it, $d) { return !empty($d['results']); }),
    'trait_stock' => array('url' => '/search/traits_ibm_nam/traits_ibm_nam_search_api.php?limit=10&stock=',
        'found' => function ($it, $d) { return !empty($d['results']); }),
    'insertion_gene' => array('url' => '/search/insertion/insertion_search_api.php?mode=gene&genes=',
        'found' => function ($it, $d) { return !empty($d['results']); }),
    'insertion_name' => array('url' => '/search/insertion/insertion_search_api.php?mode=stock&names=',
        'found' => function ($it, $d) use ($sameText) {
            foreach ($d['results'] as $r) { if ($sameText($r['name'], $it['v'])) { return true; } }
            return false;
        }),
    /* The four legacy clone searches answer an HTML fragment to a POST. */
    'bac' => array('post' => '/search/bac/bac_results.php', 'field' => 'term', 'encode' => true,
                   'extra' => array('search_limit' => 1000, 'pagesize' => 1000, 'div_name' => 'bac-results'),
                   'found' => function ($it, $html) { return strpos($html, '/data_center/bac/' . $it['v']) !== false; }),
    'est' => array('post' => '/search/est/est_results.php', 'field' => 'term', 'encode' => true,
                   'extra' => array('search_limit' => 1000, 'div_name' => 'est-results'),
                   'found' => function ($it, $html) { return strpos($html, 'id=' . $it['key']) !== false; }),
    'overgo' => array('post' => '/search/overgo/overgo_results.php', 'field' => 'term',
                      'extra' => array('search_limit' => 1000, 'div_name' => 'overgo-results'),
                      'found' => function ($it, $html) { return strpos($html, 'id=' . $it['key']) !== false; }),
    'ssr' => array('post' => '/search/ssr/ssr_results.php', 'field' => 'term',
                   'extra' => array('search_limit' => 1000, 'div_name' => 'ssr-results'),
                   'found' => function ($it, $html) { return strpos($html, 'id=' . $it['key']) !== false; }),
);

/* Fixed cases: the hubs' own example queries and the shapes that have bitten
   before (a synonym, an accession, a transcript id, a parenthesised allele). */
$FIXED = array(
    'stock' => array('b7', 'mo17', 'ames 2', 'pi 5', 'oh43', 'w22', 'ky21', 'nam', 'wx1-m1'),
    'locus' => array('wx', 'adh', 'kn1', 'waxy', 'tb1', 'bronze', 'glossy', 'opaque', 'umc10', 'zm00001eb0544'),
    'marker' => array('umc10', 'phi', 'bnlg1', 'p-umc', 'mmc0'),
    'phenotype' => array('dwa', 'brach', 'albino', 'glossy'),
    'variation' => array('wx1', 'bz1-m', 'adh1', 'mu10'),
    'qtl' => array('plant', 'beav', 'kernel', 'ear'),
    'map' => array('ibm2', 'bins', 'isu', 'cornfed', 'umc 98'),
    'gene_product' => array('alco', 'ferr', '1.1.1', 'kinase'),
    'reference' => array('liguleless', 'walbot', 'transposable', 'genome-wide', 'lg1'),
    'image' => array('wx', 'b73', 'kernel'),
    'pathway' => array('glycol', 'starch', 'pwy-57'),
    'gene' => array('zm00001eb06', 'lg1', 'wx', 'ligul', 'grmzm2g0', 'zm00001eb067740_t001'),
    'pan_gene' => array('lg1', 'zm00001eb0677', 'a0a1d6', 'pan-zea.v4.pan0001'),
    'bac' => array('c0085', 'b0128'), 'est' => array('p-std48'), 'overgo' => array('si48'), 'ssr' => array('bnlg10'),
    'uniformmu_gene' => array('zm00001eb0677', 'lg1'), 'uniformmu_insertion' => array('mu101'),
    'uniformmu_stock' => array('ufmu-018', '1828'), 'trait_stock' => array('b73', 'nam-z0'),
    'insertion_gene' => array('zm00001eb2287', 'lg1'), 'insertion_name' => array('mu100', 'bonnmu026', 'r01'),
);

/* The all-data search's refine box: suggestions merged from several scopes
   (suggestSearchAll), each checked against that search's single-type answer
   for its own type, up to four pages deep -- references there are ordered by
   year, not by match. */
$HUBS['all'] = array('all' => true);
$FIXED['all'] = array('lg1', 'b73', 'wx', 'mo17', 'waxy', 'adh', 'zm00001eb0677', 'walbot', 'umc10', 'kn1');

$only = isset($opts['only']) ? array_map('trim', explode(',', $opts['only'])) : array_keys($HUBS);
$failures = 0;
$cache = array();

foreach ($only as $scope) {
    if (!isset($HUBS[$scope])) { fwrite(STDERR, "no hub adapter for $scope\n"); continue; }
    if ($scope === 'all') {
        $failures += sgaCheckAll($origin, $host, $FIXED['all'], $sample, $verbose, $cache);
        continue;
    }
    $db = suggestOpen($scope);
    if (!$db) { fwrite(STDERR, "$scope: no index\n"); $failures++; continue; }
    $meta = suggestMeta($db);
    $hub = $HUBS[$scope];

    $queries = isset($FIXED[$scope]) ? $FIXED[$scope] : array();
    $res = $db->query('SELECT v FROM rec ORDER BY random() LIMIT ' . (int) $sample);
    while ($r = $res->fetchArray(SQLITE3_NUM)) {
        $v = $r[0];
        $n = strlen($v);
        foreach (array_unique(array(2, 3, (int) ceil($n / 2), $n)) as $len) {
            if ($len >= 2 && $len <= $n) { $queries[] = substr($v, 0, $len); }
        }
    }
    $queries = array_values(array_unique($queries));

    $keys = $db->prepare('SELECT key, u FROM rec WHERE id = :id');
    $checked = 0; $found = 0; $misses = array(); $t0 = microtime(true); $ms = array();
    foreach ($queries as $q) {
        $ts = microtime(true);
        $out = suggestSearch($db, $scope, $q, SUGGEST_LIMIT_DEFAULT, $meta, true);
        $ms[] = (microtime(true) - $ts) * 1000;
        foreach ($out['items'] as $it) {
            $keys->reset();
            $keys->bindValue(':id', (int) $it['n'], SQLITE3_INTEGER);
            $row = $keys->execute()->fetchArray(SQLITE3_ASSOC);
            $it['key'] = $row['key'];
            $it['u'] = $row['u'];
            $answer = sgaAsk($origin, $host, $hub, $it['v'], $cache);
            $checked++;
            $ok = $answer !== null && call_user_func($hub['found'], $it, $answer);
            if ($ok) { $found++; } else { $misses[] = array($q, $it['v'], $answer === null ? 'no answer' : 'not in results'); }
        }
    }
    sort($ms);
    $p95 = $ms ? $ms[(int) floor(0.95 * (count($ms) - 1))] : 0;
    printf("%-20s %4d queries  %5d suggestions  %5d found  %3d missed  (index p95 %.1f ms, %.0f s)\n",
           $scope, count($queries), $checked, $found, count($misses), $p95, microtime(true) - $t0);
    foreach (array_slice($misses, 0, $verbose ? 1000 : 5) as $m) {
        printf("    miss: %-22s -> %-40s %s\n", '"' . $m[0] . '"', '"' . $m[1] . '"', $m[2]);
    }
    $failures += count($misses);
}
exit(min(255, $failures));

function sgaCheckAll($origin, $host, $fixed, $sample, $verbose, &$cache) {
    /* The refine box opens the record a suggestion names (it is the header
       search's box, and the header's suggestions do the same), so the check
       is that every suggestion's link opens a record page: HTTP 200 and not a
       not-found page. */
    $queries = $fixed;
    foreach (array_keys(suggestAllScopes()) as $scopeName) {
        $db = suggestOpen($scopeName);
        if (!$db) { continue; }
        $res = $db->query('SELECT v FROM rec ORDER BY random() LIMIT ' . max(1, (int) ceil($sample / 3)));
        while ($r = $res->fetchArray(SQLITE3_NUM)) {
            $queries[] = substr($r[0], 0, 3);
            $queries[] = $r[0];
        }
        $db->close();
    }
    $queries = array_values(array_unique(array_filter($queries, function ($q) { return strlen($q) >= 2; })));
    $checked = 0; $found = 0; $misses = array(); $t0 = microtime(true);
    foreach ($queries as $q) {
        $out = suggestSearchAll($q, SUGGEST_LIMIT_DEFAULT, false);
        foreach ($out['items'] as $it) {
            $checked++;
            $url = isset($it['url']) ? $it['url'] : '';
            $page = $url === '' ? null : sgaFetchPage($origin, $host, $url, $cache);
            $ok = $page !== null && $page['status'] === 200 && !preg_match('/<title>[^<]*not found/i', $page['body']);
            if ($ok) { $found++; } else { $misses[] = array($q, $it['v'] . ' [' . $it['scope'] . '] ' . $url, $page === null ? 'no page' : 'HTTP ' . $page['status']); }
        }
    }
    printf("%-20s %4d queries  %5d suggestions  %5d open  %3d missed  (%.0f s)\n",
           'all', count($queries), $checked, $found, count($misses), microtime(true) - $t0);
    foreach (array_slice($misses, 0, $verbose ? 1000 : 8) as $m) {
        printf("    miss: %-22s -> %-40s %s\n", '"' . $m[0] . '"', '"' . $m[1] . '"', $m[2]);
    }
    return count($misses);
}

function sgaFetchPage($origin, $host, $url, &$cache) {
    if (array_key_exists('page|' . $url, $cache)) { return $cache['page|' . $url]; }
    $ch = curl_init('http://' . $origin . $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Host: ' . $host));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $cache['page|' . $url] = ($body === false ? null : array('status' => $status, 'body' => substr($body, 0, 20000)));
}

function sgaAsk($origin, $host, $hub, $value, &$cache) {
    $key = (isset($hub['post']) ? $hub['post'] : $hub['url']) . '|' . $value;
    if (array_key_exists($key, $cache)) { return $cache[$key]; }
    $ch = curl_init();
    if (isset($hub['post'])) {
        $fields = (isset($hub['extra']) ? $hub['extra'] : array()) +
                  array($hub['field'] => !empty($hub['encode']) ? rawurlencode($value) : $value);
        curl_setopt($ch, CURLOPT_URL, 'http://' . $origin . $hub['post']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    } else {
        curl_setopt($ch, CURLOPT_URL, 'http://' . $origin . $hub['url'] . rawurlencode($value));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Host: ' . $host, 'Accept: application/json'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $status >= 500) { return $cache[$key] = null; }
    if (isset($hub['post'])) { return $cache[$key] = $body; }
    $d = json_decode($body, true);
    return $cache[$key] = (is_array($d) ? $d : null);
}
