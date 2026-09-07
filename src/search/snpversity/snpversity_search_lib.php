<?php
/* file: search/snpversity/snpversity_search_lib.php
 *
 * purpose: everything /snpversity needs from the SNPversity 1.0 engine, as
 *          data rather than as markup.
 *
 * Where the work actually happens
 * -------------------------------
 * SNPversity's query engine is not on this host and is not this repository's
 * code. It is a TASSEL installation behind
 *
 *     https://snpversity.maizegdb.org/Diversity/
 *
 * which reads four HDF5 genotype files and writes its answers into
 * tassel/output/<n>_O<query>.json. The legacy MaizeGDB page was a 1050px
 * <iframe> around that application's own two pages; nothing on this side ever
 * touched the data.
 *
 * That has not changed. The engine is untouched: this file is a *client* for
 * it. Every one of its endpoints is still the endpoint being called, with the
 * same field names and the same values. What is new is that the answers come
 * back to PHP, get turned into JSON, and are rendered by MaizeGDB's own
 * markup — so the tool can sit on the modern shell, be read on a phone, and be
 * linked into the rest of the site.
 *
 * Why a proxy rather than talking to the engine from the browser
 * -------------------------------------------------------------
 * snpversity.maizegdb.org sends no CORS headers, so a page on
 * claude.maizegdb.org cannot read a single one of these responses directly.
 * The choice is a proxy or an iframe, and an iframe is what we are replacing.
 *
 * The proxy earns its place twice over:
 *
 *   - It caches. Measured against the engine on 2026-09-06:
 *       get_gene_models.php   1290 ms   ← per keystroke of an autocomplete
 *       get_table_body.php     140 ms
 *       send.php (GET)          41 ms
 *       a page's JSON            4 ms
 *     The 1.3 s one is the type-ahead. Cached, a repeat is a file read.
 *   - It keeps the engine's hostname out of the page. Every URL the engine
 *     emits is http://, and the modern site is https:// — pasting those
 *     straight into the document would be mixed content, blocked by the
 *     browser, and the results table would simply never populate.
 *
 * What is cached, and for how long
 * --------------------------------
 * Only immutable things. A query id names a set of files the engine wrote
 * once and deletes after six weeks; a gene model's coordinates come from a
 * frozen 2012/2014 annotation. Neither can change under us. Nothing that
 * *creates* a query is cached, and nothing here caches per-user state.
 *
 * The cache reuses conf/mgdb.conf's dashboard_cache settings — the same
 * switch, the same directory, the same "a cache must never break a page"
 * rule — rather than introducing a second cache to configure. See
 * include/dashboard_cache.php. It does not use dashboardCache() itself
 * because that has one global TTL for the whole site (0, never expire) and
 * these entries must expire: the engine's own retention is six weeks, and a
 * cached copy that outlived the files it describes would offer a reader a
 * result page whose every download link 404s.
 *
 * history
 *  09/06/26  claude  created
 */

/* The engine. https, not the http:// the engine writes into its own markup. */
define('SNPV_ENGINE', 'https://snpversity.maizegdb.org/Diversity');

/* Cache lifetimes, seconds. Results outlive nothing; the engine keeps a query
   for six weeks, so a shorter life here is only ever a wasted refetch. */
define('SNPV_TTL_QUERY',  60 * 60 * 24 * 3);   /* a query's shape and its rows */
define('SNPV_TTL_LOOKUP', 60 * 60 * 24 * 30);  /* gene models: frozen annotation */

/* A query's own run can take minutes on a whole chromosome; the engine's form
   has always warned about it. Everything else answers in under two seconds. */
define('SNPV_TIMEOUT_RUN',    900);
define('SNPV_TIMEOUT_LOOKUP',  30);

/* ------------------------------------------------------------------ *
 * The engine's four datasets
 *
 * Counts are the engine's own, from Diversity/html/datasets_modal.html.
 * They are not derived from anything on this host and must not be presented
 * as if MaizeGDB measured them.
 * ------------------------------------------------------------------ */
function snpvDatasets() {
    return array(
        'ZeaGBSv27publicImputed20150114' => array(
            'label'     => 'AllZeaGBS v2.7, imputed',
            'assembly'  => 'v2',
            'assembly_label' => 'B73 RefGen_v2',
            'stocks'    => 17280,
            'snps'      => 955690,
            'size_gb'   => 5.1,
            'source'    => 'https://www.panzea.org/genotypes',
            'note'      => 'Genotyping by sequencing, imputed. The default, and the one the examples use.',
        ),
        'AllZeaGBSv27public20140528' => array(
            'label'     => 'AllZeaGBS v2.7, raw',
            'assembly'  => 'v2',
            'assembly_label' => 'B73 RefGen_v2',
            'stocks'    => 18013,
            'snps'      => 955690,
            'size_gb'   => 11.6,
            'source'    => 'https://www.panzea.org/genotypes',
            'note'      => 'The same SNPs before imputation, so missing calls stay missing rather than being filled in.',
        ),
        'ZeaHM321_LinkImpute' => array(
            'label'     => 'HapMap v3, imputed',
            'assembly'  => 'v3',
            'assembly_label' => 'B73 RefGen_v3',
            'stocks'    => 1210,
            'snps'      => 83153144,
            'size_gb'   => 34.2,
            'source'    => 'https://www.panzea.org/genotypes',
            'note'      => 'Whole-genome resequencing, imputed with LinkImpute. 87 times the SNP density of GBS, over far fewer lines.',
        ),
        'ZeaHM321_raw' => array(
            'label'     => 'HapMap v3, raw',
            'assembly'  => 'v3',
            'assembly_label' => 'B73 RefGen_v3',
            'stocks'    => 1210,
            'snps'      => 83153144,
            'size_gb'   => 24.7,
            'source'    => 'https://www.panzea.org/genotypes',
            'note'      => 'HapMap v3 before imputation.',
        ),
    );
}

/* The engine's own per-chromosome bounds, lifted from js/home.js. They are the
   extent of the *data*, not of the assembly: the first SNP on RefGen_v2 chr 5
   is at 281 bp and on chr 7 at 27 bp, which is why "end" has a minimum at all.
   Chromosome 0 is the v2 unmapped scaffold bucket and does not exist in v3. */
function snpvChromosomeBounds($assembly) {
    if ($assembly === 'v3') {
        return array(
            1  => array(10004, 301410279),  2  => array(10056, 237798960),
            3  => array(32942, 232184005),  4  => array(29023, 241982791),
            5  => array(313, 217804155),    6  => array(120018, 169339845),
            7  => array(65, 176174508),     8  => array(17958, 175289467),
            9  => array(66658, 156862189), 10  => array(228730, 149584883),
        );
    }
    return array(
        0  => array(5518, 6932521),     1  => array(6370, 301331039),
        2  => array(9700, 237042811),   3  => array(32942, 232096209),
        4  => array(28990, 241426350),  5  => array(281, 217748593),
        6  => array(119364, 169132442), 7  => array(27, 176759698),
        8  => array(17932, 175735562),  9  => array(66658, 156591838),
        10 => array(2918, 150146791),
    );
}

/* The engine's project palette, from Diversity/css/taxa_colors.css. Kept here
   because the results table colors a stock column by the project it came
   from, and that mapping is the engine's, not ours. */
function snpvProjects() {
    return array(
        'NAM'                 => array('cls' => 'NAM',          'color' => '#7293CB'),
        'IBM'                 => array('cls' => 'IBM',          'color' => '#808585'),
        'ApeKI 384-plex'      => array('cls' => 'ApeKI',        'color' => '#E1974C'),
        'Imputation Test'     => array('cls' => 'Imputation',   'color' => '#84BA5B'),
        '2010 Ames Lines'     => array('cls' => 'Ames2010',     'color' => '#D35E60'),
        'R&D'                 => array('cls' => 'RandD',        'color' => '#7FB2C6'),
        'Maize-BREAD'         => array('cls' => 'BREAD',        'color' => '#9067A7'),
        'AMES Inbreds'        => array('cls' => 'Inbreds',      'color' => '#61A961'),
        'Ames282'             => array('cls' => 'Ames282',      'color' => '#AB6857'),
        'Old Maize Diversity' => array('cls' => 'oldDiversity', 'color' => '#CCC210'),
        'HapMapV3'            => array('cls' => 'HapMapV3',     'color' => '#5B7C99'),
    );
}

/* The engine sends "R&D" to get_taxa_allzeagbs.php as "RandD" — js/home.js
   rewrites it before the POST, because the ampersand does not survive the
   round trip. Anything that talks to that endpoint has to do the same. */
function snpvProjectWireName($project) {
    return ($project === 'R&D') ? 'RandD' : $project;
}

/* ------------------------------------------------------------------ *
 * HTTP to the engine
 * ------------------------------------------------------------------ */

/*
 * One request. $post is null for GET, an array for a form POST, or a
 * pre-built multipart array (values may be CURLFile) for a file upload.
 *
 * Returns array(ok, status, body, error, ms). It never throws and never emits:
 * a caller decides what a failure means, because "the engine is down" and
 * "that query id has expired" want different words on the page.
 */
function snpvHttp($url, $post = null, $timeout = SNPV_TIMEOUT_LOOKUP, $multipart = false) {
    $started = microtime(true);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_USERAGENT, 'MaizeGDB/snpversity (+https://www.maizegdb.org/)');
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        /* http_build_query for an ordinary form; the raw array only when a
           CURLFile is in it, since curl reads that as multipart. */
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $post : http_build_query($post));
    }
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = curl_error($ch);
    curl_close($ch);

    return array(
        'ok'     => ($body !== false && $status >= 200 && $status < 300),
        'status' => $status,
        'body'   => ($body === false) ? '' : $body,
        'error'  => $error,
        'ms'     => (int) round((microtime(true) - $started) * 1000),
    );
}

/*
 * Two requests at once. The results page needs a page's genotype JSON *and*
 * its gene annotation, which are two different endpoints on the same host;
 * run in series that is 4 ms + 140 ms, in parallel it is 140 ms.
 *
 * $requests is a map of name => array('url' => …, 'post' => … or null).
 */
function snpvHttpParallel($requests, $timeout = SNPV_TIMEOUT_LOOKUP) {
    if (!count($requests)) { return array(); }

    $multi   = curl_multi_init();
    $handles = array();

    foreach ($requests as $name => $spec) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $spec['url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_USERAGENT, 'MaizeGDB/snpversity (+https://www.maizegdb.org/)');
        if (isset($spec['post']) && $spec['post'] !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($spec['post']));
        }
        curl_multi_add_handle($multi, $ch);
        $handles[$name] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($multi, $running);
        if ($running > 0) { curl_multi_select($multi, 1.0); }
    } while ($running > 0);

    $out = array();
    foreach ($handles as $name => $ch) {
        $body   = curl_multi_getcontent($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $out[$name] = array(
            'ok'     => ($body !== false && $body !== null && $status >= 200 && $status < 300),
            'status' => $status,
            'body'   => ($body === null || $body === false) ? '' : $body,
            'error'  => curl_error($ch),
        );
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);
    return $out;
}

/* ------------------------------------------------------------------ *
 * The cache
 *
 * Deliberately not dashboardCache(): that has one site-wide TTL, fixed at 0
 * (never expire), which is right for figures rebuilt on a monthly database
 * load and wrong for a result the engine deletes after six weeks.
 * ------------------------------------------------------------------ */

function snpvCacheEnabled($system) {
    return isset($system['dashboard_cache'])
        && strtolower(trim($system['dashboard_cache'])) === 'true';
}

function snpvCacheDir($system) {
    if (!empty($system['dashboard_cache_path'])) {
        $base = rtrim($system['dashboard_cache_path'], '/');
    } elseif (!empty($system['search_cache_path'])) {
        $base = rtrim($system['search_cache_path'], '/') . '/dashboard';
    } else {
        return '';
    }
    return $base . '/snpversity';
}

function snpvCacheFile($system, $key) {
    $dir = snpvCacheDir($system);
    if ($dir === '') { return ''; }
    $stamp = isset($system['dashboard_cache_stamp']) ? trim($system['dashboard_cache_stamp']) : '';
    return $dir . '/' . sha1($stamp . '|' . $key) . '.json';
}

function snpvCacheGet($system, $key, $ttl) {
    if (!snpvCacheEnabled($system)) { return null; }
    $file = snpvCacheFile($system, $key);
    if ($file === '' || !is_file($file)) { return null; }
    if ($ttl > 0 && (time() - (int) @filemtime($file)) > $ttl) { return null; }
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') { return null; }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function snpvCachePut($system, $key, $payload) {
    if (!snpvCacheEnabled($system) || !is_array($payload)) { return false; }
    $dir = snpvCacheDir($system);
    if ($dir === '') { return false; }
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) { return false; }
    $file = snpvCacheFile($system, $key);
    $tmp  = $file . '.' . getmypid() . '.tmp';
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) { return false; }
    /* Atomic: a half-written entry read by a concurrent request would be a
       JSON parse failure on a page that has nothing wrong with it. */
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) { return false; }
    if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
    return true;
}

/* ------------------------------------------------------------------ *
 * Parsing the engine's answers
 * ------------------------------------------------------------------ */

/*
 * get_taxa_allzeagbs.php and get_taxa_hapmapv3.php answer with a run of
 * <option> elements, optionally wrapped in <optgroup label="project">.
 *
 * Two shapes of option, and the difference matters:
 *   <option value="Imputation Test">All Imputation Test</option>   the whole project
 *   <option value="B73:250040827">B73</option>                     one stock
 *   <option value="Ames19311:250040803">A641 = Ames19311</option>  label ≠ value
 *
 * so the label cannot be used as the value, and the value cannot be used as
 * the label.
 *
 * The "whole project" entry is the one whose value *is* the optgroup's label.
 * It is not "the one with no colon in it": get_taxa_hapmapv3.php answers with
 * a flat list of bare names and no ids at all, and reading a missing colon as
 * "this means the whole project" silently reduced all 1,210 HapMap v3 lines to
 * nothing on the first run of the index tool.
 */
function snpvParseTaxaOptions($html) {
    $out = array();

    /* Split rather than match the group body.
     *
     * The obvious pattern — <optgroup …>(.*?)(?=</optgroup>|$) — blows PCRE's
     * backtrack limit on these responses and returns *false*, which is not 0
     * and is easy to read as "no groups". The NAM list is 640 KB and the
     * engine never closes its <optgroup>, so the lazy body has to expand to
     * the end of the subject one character at a time. When that happened the
     * project came back empty for every option, which in turn made the
     * "whole project" entry indistinguishable from a stock and put a row
     * called NAM in the stock list.
     *
     * Splitting on the opening tag has no backtracking in it at all. It also
     * handles get_taxa_hapmapv3.php, which answers with a flat list and no
     * optgroup: that is simply one chunk with no project. */
    $chunks = preg_split('#(?=<optgroup)#i', $html);
    if ($chunks === false) { $chunks = array($html); }

    foreach ($chunks as $chunk) {
        if ($chunk === '') { continue; }
        $project = '';
        if (preg_match('#^<optgroup[^>]*label="([^"]*)"#i', $chunk, $m)) {
            $project = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
        if (!preg_match_all('#<option\s+value="([^"]*)"\s*>(.*?)</option>#si', $chunk, $opts, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($opts as $opt) {
            $value = html_entity_decode($opt[1], ENT_QUOTES, 'UTF-8');
            $label = trim(html_entity_decode(strip_tags($opt[2]), ENT_QUOTES, 'UTF-8'));
            if ($value === '') { continue; }
            $isAll = ($project !== '' && $value === $project);
            $pos = strrpos($value, ':');
            $out[] = array(
                'value'   => $value,
                'label'   => $label,
                'name'    => ($isAll || $pos === false) ? $value : substr($value, 0, $pos),
                'taxon'   => ($isAll || $pos === false) ? '' : substr($value, $pos + 1),
                'project' => $project,
                'all'     => $isAll,
            );
        }
    }
    return $out;
}

/*
 * send.php's page, as data.
 *
 * Everything below is read from the engine's own markup rather than guessed,
 * because the engine is the only thing that knows how many pages a query
 * produced and in which order the stock columns are written. Getting the
 * column order wrong would put one line's genotype under another line's name,
 * which is a wrong answer that looks exactly like a right one.
 */
function snpvParseResultsPage($html) {
    $out = array(
        'stocks'     => array(),
        'pages'      => array(),
        'assembly'   => '',
        'assembly_label' => '',
        'taxainfo'   => '',
        'download'   => '',
        'error'      => '',
    );

    if (preg_match('#id="version"[^>]*>([^<]*)<#i', $html, $m)) {
        $out['assembly'] = trim($m[1]);
    }
    if (preg_match('#id="descriptionFooter"[^>]*>\s*<strong>\s*Assembly:\s*([^<]*)</strong>#i', $html, $m)) {
        $out['assembly_label'] = trim($m[1]);
    }

    /* The stock columns, in the order the engine wrote them. The class carries
       the project, which is what colors the column.
     *
     * Two shapes, and matching only the first one silently produced a result
     * with no stock columns at all — a 27-stock query reported "0 stocks" and
     * drew a grid six columns wide:
     *
     *   <th class="Imputation"><div><span>B73</span></div></th>
     *   <th class="rotate Imputation"><div><span title="B73">B73</span></div></th>
     *
     * The engine adds `rotate` and a title attribute once a result is wide
     * enough that it turns the labels on their side. So the span may carry
     * attributes, and the class is a list rather than a name. */
    if (preg_match('#<thead>(.*?)</thead>#si', $html, $head)) {
        if (preg_match_all('#<th class="([^"]*)"><div><span[^>]*>(.*?)</span>#si', $head[1], $ths, PREG_SET_ORDER)) {
            $known = snpvProjects();
            foreach ($ths as $th) {
                $cls = '';
                foreach (preg_split('/\s+/', trim($th[1])) as $candidate) {
                    foreach ($known as $meta) {
                        if ($meta['cls'] === $candidate) { $cls = $candidate; break 2; }
                    }
                }
                if ($cls === '') { $cls = trim($th[1]); }
                $out['stocks'][] = array(
                    'name'  => trim(html_entity_decode(strip_tags($th[2]), ENT_QUOTES, 'UTF-8')),
                    'class' => $cls,
                );
            }
        }
    }

    /* The pages. A single-page result has no <select id="pages"> at all, so
       the first-page div is the fallback rather than an extra case. */
    if (preg_match('#<select[^>]*id="pages".*?</select>#si', $html, $sel)) {
        if (preg_match_all('#<option value="([^"]*)"[^>]*>(.*?)</option>#si', $sel[0], $opts, PREG_SET_ORDER)) {
            foreach ($opts as $i => $opt) {
                $out['pages'][] = array(
                    'n'     => $i + 1,
                    'url'   => snpvNormalizeEngineUrl(html_entity_decode($opt[1], ENT_QUOTES, 'UTF-8')),
                    'label' => trim(html_entity_decode(strip_tags($opt[2]), ENT_QUOTES, 'UTF-8')),
                );
            }
        }
    }
    if (!count($out['pages']) && preg_match('#id="first-page"[^>]*>([^<]*)<#i', $html, $m)) {
        $url = trim($m[1]);
        if ($url !== '') {
            $out['pages'][] = array('n' => 1, 'url' => snpvNormalizeEngineUrl($url), 'label' => 'All sites');
        }
    }

    if (preg_match('#href="([^"]*\.taxainfo)"#i', $html, $m)) {
        $out['taxainfo'] = snpvNormalizeEngineUrl(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
    }

    /* The hapmap / vcf branch answers with a bare "View File" link instead of
       a table. See snpvCheckExport() for why that link cannot be handed to a
       reader as it stands.
     *
     * The href must actually be a URL. For a query id the engine has never
     * heard of it emits `<a href=deadbeef...,1,>View File</a>` — the id and
     * two commas — and matching that made "this query does not exist" look
     * like "here is your file", so the results page answered 200 with an
     * empty grid instead of saying the result had expired. */
    if (!count($out['pages'])
        && preg_match('#<a href="?(https?://[^ ">]+)"?>View File</a>#i', $html, $m)) {
        $out['download'] = snpvNormalizeEngineUrl($m[1]);
    }

    return $out;
}

/*
 * The engine writes absolute URLs on three different hostnames — the public
 * one over http, and david1.usda.iastate.edu, which is an internal name with
 * no public DNS record at all. Everything is rewritten onto the public host
 * over https before it is fetched or shown.
 */
function snpvEngineInsecureUrl($url) {
    return preg_replace('#^https://#i', 'http://', $url);
}

function snpvNormalizeEngineUrl($url) {
    $url = trim($url);
    if ($url === '') { return ''; }
    $url = preg_replace('#^https?://[^/]*/Diversity#i', SNPV_ENGINE, $url);
    if (strpos($url, 'http') !== 0) {
        $url = SNPV_ENGINE . '/' . ltrim($url, '/');
    }
    return $url;
}

/*
 * The genotype grid for one page.
 *
 * Two sources, merged:
 *
 *   tassel/output/<n>_O<query>.json   the calls. Authoritative, and the only
 *                                     thing the legacy viewer used for the
 *                                     first four columns.
 *   get_table_body.php                the gene model and feature type for each
 *                                     site. That annotation exists nowhere on
 *                                     this host — MaizeGDB's own
 *                                     mgdb.za_gene_structure_v2 is empty, and
 *                                     mgdb.za_gene_models' RefGen_v2 spans
 *                                     disagree with the engine's (271340805
 *                                     vs 271346869 for GRMZM2G017087_T01), so
 *                                     substituting it would quietly answer a
 *                                     different question.
 *
 * The two are matched by row order, which is the same order in both because
 * get_table_body.php iterates the very JSON file it is handed.
 */
function snpvBuildPage($system, $query, $pageUrl, $assembly) {
    $key = 'page|' . $query . '|' . $pageUrl . '|' . $assembly;
    $hit = snpvCacheGet($system, $key, SNPV_TTL_QUERY);
    if ($hit !== null) {
        $hit['cache'] = 'hit';
        return $hit;
    }

    $answers = snpvHttpParallel(array(
        'calls' => array('url' => $pageUrl, 'post' => null),
        /* http, not https, and this is not an oversight. get_table_body.php
           does not read the file from disk — it fetches the URL it is handed,
           on a PHP 5.3 build with no SSL stream wrapper, so an https:// URL
           returns 200 with an empty body and every gene and type column comes
           back blank. Everything else on this side stays https. */
        'genes' => array('url' => SNPV_ENGINE . '/get_table_body.php',
                         'post' => array('file' => json_encode(snpvEngineInsecureUrl($pageUrl)),
                                         'assembly' => json_encode($assembly))),
    ), 60);

    if (empty($answers['calls']['ok'])) {
        return array('ok' => false, 'error' => 'expired', 'cache' => 'live');
    }

    $rows = json_decode($answers['calls']['body'], true);
    if (!is_array($rows)) {
        return array('ok' => false, 'error' => 'unreadable', 'cache' => 'live');
    }

    $annot = empty($answers['genes']['ok'])
           ? array()
           : snpvParseAnnotation($answers['genes']['body']);

    $out = array('ok' => true, 'rows' => array(), 'annotated' => count($annot) > 0, 'cache' => 'miss');
    foreach ($rows as $i => $row) {
        $calls = isset($row['results']) && is_array($row['results']) ? $row['results'] : array();
        $pair  = snpvSplitAlleles(isset($row['allele']) ? $row['allele'] : '');
        $out['rows'][] = array(
            'site'    => isset($row['rs#']) ? $row['rs#'] : '',
            'alleles' => isset($row['allele']) ? $row['allele'] : '',
            'maj'     => $pair[0],
            'min'     => $pair[1],
            'chr'     => isset($row['chrom_name']) ? $row['chrom_name'] : '',
            'pos'     => isset($row['chrom_pos']) ? (string) $row['chrom_pos'] : '',
            'genes'   => isset($annot[$i]['genes']) ? $annot[$i]['genes'] : array(),
            'types'   => isset($annot[$i]['types']) ? $annot[$i]['types'] : array(),
            'calls'   => array_values($calls),
        );
    }

    /* Only a complete page is worth keeping. A page cached without its
       annotation would show empty Gene model and Type columns for three days
       on a result that has them, which reads as "no gene here" rather than as
       a failed request. An empty result set is complete by definition. */
    if ($out['annotated'] || !count($out['rows'])) {
        snpvCachePut($system, $key, $out);
    }
    return $out;
}

/*
 * The engine's `allele` field is a *pair*, not a base.
 *
 * It writes "G" where only one allele was seen among the stocks in this
 * result and "G/T" where two were — major first, minor second — so it also
 * changes with the stock selection: the same site is "T" in a three-stock
 * result and "T/C" in a twenty-seven-stock one.
 *
 * That matters because the whole point of the grid's color scale is major
 * against minor, and comparing a call to the *string* "G/T" makes every call
 * at a polymorphic site read as minor. It is silent, and it looks right,
 * because monomorphic sites — the majority — still color correctly.
 * Confirmed against the engine's own table: at S1_158926101, allele "G/T", it
 * classes G as `j` and T as `n`.
 *
 * Returns array(major, minor); minor is '' at a monomorphic site.
 */
function snpvSplitAlleles($allele) {
    $allele = trim((string) $allele);
    if ($allele === '' || strtoupper($allele) === 'NA') { return array('', ''); }
    $parts = explode('/', $allele);
    return array(trim($parts[0]), isset($parts[1]) ? trim($parts[1]) : '');
}

/*
 * get_table_body.php's rows, reduced to the two columns only it can supply.
 *
 * Its output is not well-formed: each <tr> is preceded by a bare, unwrapped
 * GBrowse URL echoed straight into the stream. In the legacy viewer those text
 * nodes are hoisted out of the table by the HTML parser and pile up above it;
 * here they are simply not matched.
 *
 * The gene cell holds a link whose text is either a gene model name or the
 * literal word "View", which the engine's own help explains means *no* gene
 * model at that site. "View" is therefore dropped rather than shown: it is a
 * label for a link, not a value for a column.
 */
function snpvParseAnnotation($html) {
    $out = array();
    if (!preg_match_all('#<tr>(.*?)</tr>#si', $html, $trs, PREG_SET_ORDER)) {
        return $out;
    }
    foreach ($trs as $tr) {
        if (!preg_match_all('#<td[^>]*>(.*?)</td>#si', $tr[1], $tds, PREG_SET_ORDER)) {
            $out[] = array('genes' => array(), 'types' => array());
            continue;
        }
        $genes = array();
        $types = array();
        if (isset($tds[4][1])) {
            foreach (preg_split('#<br\s*/?>#i', $tds[4][1]) as $piece) {
                $text = trim(html_entity_decode(strip_tags($piece), ENT_QUOTES, 'UTF-8'));
                if ($text !== '' && strcasecmp($text, 'View') !== 0) { $genes[] = $text; }
            }
        }
        if (isset($tds[5][1])) {
            foreach (preg_split('#<br\s*/?>#i', $tds[5][1]) as $piece) {
                $text = trim(html_entity_decode(strip_tags($piece), ENT_QUOTES, 'UTF-8'));
                if ($text !== '') { $types[] = $text; }
            }
        }
        $out[] = array('genes' => $genes, 'types' => $types);
    }
    return $out;
}

/*
 * One page of calls, without the gene annotation.
 *
 * The HapMap and VCF exports carry no gene model or feature type column, so
 * for them the second upstream request is 140 ms per page bought for nothing.
 * On a whole-chromosome query that is the difference between an export that
 * finishes and one that does not: the page JSON alone is 4 ms.
 *
 * Not cached. snpvBuildPage() caches the *complete* page; caching a second,
 * partial copy of the same data under a second key would double the cache for
 * no gain, and an export reads each page exactly once.
 */
function snpvPageCalls($pageUrl) {
    $res = snpvHttp($pageUrl, null, 60);
    if (!$res['ok']) { return null; }
    $rows = json_decode($res['body'], true);
    if (!is_array($rows)) { return null; }

    $out = array();
    foreach ($rows as $row) {
        $pair = snpvSplitAlleles(isset($row['allele']) ? $row['allele'] : '');
        $out[] = array(
            'site'    => isset($row['rs#']) ? $row['rs#'] : '',
            'alleles' => isset($row['allele']) ? $row['allele'] : '',
            'maj'     => $pair[0],
            'min'     => $pair[1],
            'chr'     => isset($row['chrom_name']) ? $row['chrom_name'] : '',
            'pos'     => isset($row['chrom_pos']) ? (string) $row['chrom_pos'] : '',
            'calls'   => isset($row['results']) && is_array($row['results']) ? array_values($row['results']) : array(),
        );
    }
    return $out;
}

/*
 * The IUPAC codes the engine emits, expanded to the two bases each stands for.
 * Shared by the VCF writer, which has to turn a call into a pair of allele
 * indices, and by anything else that needs to know what a call contains.
 */
function snpvIupac($code) {
    static $map = array(
        'A' => array('A', 'A'), 'C' => array('C', 'C'),
        'G' => array('G', 'G'), 'T' => array('T', 'T'),
        'R' => array('A', 'G'), 'Y' => array('C', 'T'),
        'S' => array('G', 'C'), 'W' => array('A', 'T'),
        'K' => array('G', 'T'), 'M' => array('A', 'C'),
    );
    $code = strtoupper(trim((string) $code));
    return isset($map[$code]) ? $map[$code] : null;
}

/*
 * A query's shape: its stock columns, its pages, its assembly.
 *
 * One GET of send.php answers all of it in ~40 ms, and the answer cannot
 * change — so it is cached, and a second visit to a shared result URL costs a
 * file read. Probing for page files one at a time would be one request per
 * page and would still not give the position range each page covers.
 */
function snpvQueryMeta($system, $query) {
    $key = 'meta|' . $query;
    $hit = snpvCacheGet($system, $key, SNPV_TTL_QUERY);
    if ($hit !== null) {
        $hit['cache'] = 'hit';
        return $hit;
    }

    $res = snpvHttp(SNPV_ENGINE . '/send.php?query=' . rawurlencode($query) . '&parent=true', null, SNPV_TIMEOUT_LOOKUP);
    if (!$res['ok']) {
        return array('ok' => false, 'error' => 'unreachable', 'cache' => 'live');
    }

    $meta = snpvParseResultsPage($res['body']);
    snpvAttachStockLinks($meta);
    snpvAttachExtent($meta);
    $meta['ok']    = count($meta['pages']) > 0 || $meta['download'] !== '';
    $meta['query'] = $query;
    $meta['cache'] = 'miss';

    /* Only a real answer is worth keeping. Caching "expired" would make an
       expiry permanent for three days even after the engine was fixed. */
    if ($meta['ok']) { snpvCachePut($system, $key, $meta); }
    return $meta;
}

/*
 * The span of positions the result actually covers.
 *
 * Not read off the page labels. The engine names each page by the range in it
 * — "158926888 - 159175567" — but names the *last* one "159214898 - End", so
 * a result's upper bound is the one number those labels never carry. Parsing
 * them gives "from 158,918,417" and a dash where the end should be.
 *
 * The page files themselves have it. The first and last are fetched together
 * — 4 ms each, and in parallel — and the extent is the first row of one and
 * the last row of the other. A single-page result fetches one file. The
 * answer is stored in the cached meta, so this happens once per query id.
 */
function snpvAttachExtent(&$meta) {
    $meta['extent'] = null;
    $meta['chr'] = '';
    if (empty($meta['pages'])) { return; }

    $first = $meta['pages'][0]['url'];
    $last  = $meta['pages'][count($meta['pages']) - 1]['url'];

    $want = array('first' => array('url' => $first, 'post' => null));
    if ($last !== $first) { $want['last'] = array('url' => $last, 'post' => null); }
    $got = snpvHttpParallel($want, 30);

    $lo = null;
    $hi = null;
    if (!empty($got['first']['ok'])) {
        $rows = json_decode($got['first']['body'], true);
        if (is_array($rows) && count($rows) && isset($rows[0]['chrom_pos'])) {
            $lo = (float) $rows[0]['chrom_pos'];
            if (isset($rows[0]['chrom_name'])) { $meta['chr'] = (string) $rows[0]['chrom_name']; }
            if ($last === $first) { $hi = (float) $rows[count($rows) - 1]['chrom_pos']; }
        }
    }
    if ($hi === null && !empty($got['last']['ok'])) {
        $rows = json_decode($got['last']['body'], true);
        if (is_array($rows) && count($rows)) {
            $hi = (float) $rows[count($rows) - 1]['chrom_pos'];
        }
    }
    if ($lo !== null && $hi !== null && $hi >= $lo) {
        $meta['extent'] = array($lo, $hi);
    }
}

/*
 * Give each stock column its MaizeGDB stock record, where it has one.
 *
 * The engine's taxa carry an id — "B73:250040827" — but it is the engine's
 * own, not a MaizeGDB one: B73 is 47638 in mgdb.stock. So the join has to be
 * on the name, and a name is not a key. This asks for exactly the names in
 * this result and links only the ones that resolve to a single row; a name
 * held by two stocks is left unlinked rather than pointed at whichever one
 * sorted first.
 *
 * One indexed lookup. mgdb.stock has a plain btree on `name`, so an exact
 * IN list is an index scan — measured at 1.0 ms for ten names against 87,397
 * rows, against the 304 ms a full read of the table costs. Case is not
 * folded for that reason: lower(name) cannot use that index, and the engine's
 * names are already the site's own spellings.
 *
 * Called once per query id, from inside the cached meta, so a result page
 * revisited a hundred times runs it once.
 */
function snpvAttachStockLinks(&$meta) {
    if (empty($meta['stocks']) || !function_exists('connect_to_database')) { return; }

    $names = array();
    foreach ($meta['stocks'] as $s) {
        if ($s['name'] !== '') { $names[$s['name']] = true; }
    }
    $names = array_keys($names);
    if (!count($names)) { return; }

    $DBConn = connect_to_database(false);
    if (!$DBConn) { return; }

    $marks = implode(',', array_fill(0, count($names), '?'));
    $sql = 'SELECT name, MIN(id) AS id, COUNT(*) AS n FROM mgdb.stock '
         . 'WHERE name IN (' . $marks . ') GROUP BY name';
    $rows = get_all_rows(make_query($DBConn, $sql, 1, $names));

    $byName = array();
    foreach ($rows as $row) {
        if ((int) $row['n'] === 1) { $byName[$row['name']] = (int) $row['id']; }
    }
    foreach ($meta['stocks'] as $i => $s) {
        $meta['stocks'][$i]['stock_id'] = isset($byName[$s['name']]) ? $byName[$s['name']] : null;
    }
}

/*
 * Gene model type-ahead. A substring match, capped by the engine at 50 —
 * which is why this cannot be answered by asking for a shorter prefix once
 * and filtering here: at 50 the answer is truncated and the entry being typed
 * towards may not be in it.
 */
function snpvGeneModels($system, $assembly, $input) {
    $input = trim($input);
    if (strlen($input) < 3) { return array(); }
    $key = 'models|' . $assembly . '|' . strtolower($input);
    $hit = snpvCacheGet($system, $key, SNPV_TTL_LOOKUP);
    if ($hit !== null) { return isset($hit['models']) ? $hit['models'] : array(); }

    $res = snpvHttp(SNPV_ENGINE . '/get_gene_models.php',
                    array('action' => 'getGeneModels', 'assembly' => $assembly, 'input' => $input),
                    SNPV_TIMEOUT_LOOKUP);
    if (!$res['ok']) { return array(); }

    $models = array();
    $parsed = json_decode($res['body'], true);
    if (is_array($parsed)) {
        foreach ($parsed as $row) {
            if (isset($row['model']) && $row['model'] !== '') { $models[] = $row['model']; }
        }
    }
    snpvCachePut($system, $key, array('models' => $models));
    return $models;
}

/*
 * A gene model's extent. Coordinates come from the engine because they have to
 * agree with the coordinates its HDF5 files are indexed on; MaizeGDB's own
 * RefGen_v2 table gives a different span for the same transcript.
 */
function snpvGeneRange($system, $assembly, $model) {
    $model = trim($model);
    if ($model === '') { return null; }
    $key = 'range|' . $assembly . '|' . strtolower($model);
    $hit = snpvCacheGet($system, $key, SNPV_TTL_LOOKUP);
    if ($hit !== null) { return isset($hit['range']) ? $hit['range'] : null; }

    $res = snpvHttp(SNPV_ENGINE . '/get_gene_models.php',
                    array('action' => 'getRange', 'assembly' => $assembly, 'model' => $model),
                    SNPV_TIMEOUT_LOOKUP);
    if (!$res['ok']) { return null; }

    $parsed = json_decode($res['body'], true);
    $range  = null;
    if (is_array($parsed) && isset($parsed[0]['chr'])) {
        $range = array(
            'chr' => (string) $parsed[0]['chr'],
            'min' => (int) $parsed[0]['min'],
            'max' => (int) $parsed[0]['max'],
        );
    }
    snpvCachePut($system, $key, array('range' => $range));
    return $range;
}

/*
 * A hapmap or vcf run answers with a link, and that link cannot be trusted.
 * Measured 2026-09-06: the engine writes
 *
 *   http://david1.usda.iastate.edu/Diversity/tassel/output/O<query>.vcf
 *
 * david1.usda.iastate.edu has no public DNS record, and rewriting the host
 * onto snpversity.maizegdb.org gives a 404 — the file is not under the
 * directory Apache serves, although that same directory serves the .json
 * pages of the same query. So the export is checked before it is offered,
 * and a reader is told plainly rather than handed a dead link.
 */
function snpvCheckExport($url) {
    $url = snpvNormalizeEngineUrl($url);
    if ($url === '') { return array('ok' => false, 'url' => '', 'status' => 0); }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size   = (float) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    curl_close($ch);
    return array('ok' => ($status === 200), 'url' => $url, 'status' => $status, 'bytes' => (int) $size);
}

/* ------------------------------------------------------------------ *
 * Small helpers shared by the API and the controllers
 * ------------------------------------------------------------------ */

/* A query id names files on someone else's disk. Anything but hex could only
   be an attempt to make the engine open a path it was not asked to. */
function snpvValidQueryId($id) {
    return is_string($id) && preg_match('/^[0-9a-zA-Z]{8,64}$/', $id) === 1;
}

function snpvParam($name, $default = '') {
    if (isset($_POST[$name])) { $v = $_POST[$name]; }
    elseif (isset($_GET[$name])) { $v = $_GET[$name]; }
    else { return $default; }
    return is_string($v) ? trim($v) : $default;
}

/* The engine's IUPAC and match classes, as data, so the results grid and the
   legend cannot disagree about what a color means. j = matches the major
   allele, n = the minor one; both are assigned per site, not per base. */
function snpvNucleotideLegend() {
    return array(
        array('code' => 'Major',   'cls' => 'j',    'meaning' => 'The more common of the two alleles seen at this site'),
        array('code' => 'Minor',   'cls' => 'n',    'meaning' => 'The other allele seen at this site'),
        array('code' => 'R',       'cls' => 'R',    'meaning' => 'A or G'),
        array('code' => 'Y',       'cls' => 'Y',    'meaning' => 'C or T'),
        array('code' => 'S',       'cls' => 'S',    'meaning' => 'G or C'),
        array('code' => 'W',       'cls' => 'W',    'meaning' => 'A or T'),
        array('code' => 'K',       'cls' => 'K',    'meaning' => 'G or T'),
        array('code' => 'M',       'cls' => 'M',    'meaning' => 'A or C'),
        array('code' => '+',       'cls' => 'ins',  'meaning' => 'Insertion'),
        array('code' => '- or .',  'cls' => 'del',  'meaning' => 'Deletion'),
        array('code' => '0',       'cls' => 'zero', 'meaning' => 'Insertion or deletion'),
        array('code' => 'N',       'cls' => 'N',    'meaning' => 'Not called'),
    );
}
