<?php
/* file: tools/dashboard_cache.php
 *
 * purpose: inspect, empty, and pre-build the data-centre dashboard cache.
 *
 * The production database is reloaded once a month. The cached figures are
 * correct until that happens and wrong immediately after, so the reload script
 * should finish by running:
 *
 *   cd <webroot> && php tools/dashboard_cache.php --purge --warm
 *
 * --purge drops every entry; --warm then requests each cached page once so the
 * first real visitor gets a hit rather than paying the rebuild. Warming is
 * optional: without it the cache simply fills itself as pages are visited, and
 * exactly one visitor per page pays for it.
 *
 * The alternative to running this at all is to bump dashboard_cache_stamp in
 * conf/mgdb.conf, which retires every entry at once without filesystem access.
 *
 * Usage
 * -----
 *   php tools/dashboard_cache.php --status          what is cached, and how old
 *   php tools/dashboard_cache.php --purge           empty it
 *   php tools/dashboard_cache.php --warm            rebuild by fetching pages
 *   php tools/dashboard_cache.php --purge --warm    the monthly-reload step
 *
 * Must run from the web root: getSystemInfoFile() walks up from getcwd() to
 * find conf/. Warnings about a missing gp_lib.php under the CLI are harmless.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

include_once('include/gp_lib.php');
include_once('include/dashboard_cache.php');

$system = getSystemInfo('mgdb.conf');

$args   = array_slice($argv, 1);
$status = in_array('--status', $args, true);
$purge  = in_array('--purge', $args, true);
$warm   = in_array('--warm', $args, true);

if (!$status && !$purge && !$warm) {
    $status = true;
}

// Pages whose dashboards are cached. Warming just fetches each one, which is
// the same path a visitor takes, so this list needs no knowledge of cache keys.
$WARM_URLS = array(
    '/data_center/variation',
    '/data_center/map',
    '/data_center/marker',
    '/data_center/stock',
    '/data_center/bac',
    '/data_center/reference',
    '/data_center/est',
    '/data_center/overgo',
    '/data_center/ssr',
    '/data_center/protein_structure',
    '/data_center/phenotype',
    '/data_center/gene_product',
    '/data_center/locus',
    '/data_center/qtl',
    '/data_center/assembly',
    '/assembly',
    '/data_center/genomebrowser',
    '/genomebrowser',
    '/download',
    '/downloads',
    '/TYPSimSelector',
    '/NAM_project',
    '/PanAnd_project',
    '/european_flints',
    '/HiLo_project',
    '/amaizing_project',
    '/CAAS_FIL_project',
    '/maize_history',
    '/timelines',
    '/expression',
    '/genetic_variation',
    '/insertion',
    '/search/reference/reference_search_api.php?facets_only=1'
);

echo "dashboard cache -- instance '" . (isset($system['instance']) ? $system['instance'] : '?') . "'\n";
echo "  enabled : " . (dashboardCacheEnabled($system) ? 'yes' : 'no  (dashboard_cache is not "true")') . "\n";
echo "  path    : " . dashboardCacheDir($system) . "\n";
$ttl = dashboardCacheTtl($system);
echo "  ttl     : " . ($ttl > 0 ? $ttl . ' s' : 'none -- entries live until purged') . "\n";
$stamp = isset($system['dashboard_cache_stamp']) ? trim($system['dashboard_cache_stamp']) : '';
echo "  stamp   : " . ($stamp === '' ? '(unset)' : $stamp) . "\n\n";

if (!dashboardCacheEnabled($system)) {
    echo "Caching is off for this instance, so pages compute their figures live.\n";
    echo "Nothing to purge or warm.\n";
    exit(0);
}

if ($purge) {
    $removed = dashboardCachePurge($system);
    echo "purged $removed entr" . ($removed === 1 ? 'y' : 'ies') . "\n\n";
}

if ($warm) {
    /* Warm against the local Apache, not through the public hostname. The site
       sits behind Cloudflare, which answers a server-side fetch of an HTML page
       with a bot challenge -- every page would report FAILED while the cache
       stayed empty. Connecting to this host and overriding the Host header
       reaches the same virtual host directly, with no CDN in between. */
    $vhost  = !empty($system['root_url_private'])
            ? parse_url(rtrim($system['root_url_private'], '/'), PHP_URL_HOST)
            : '';
    $origin = 'http://' . gethostname();

    if ($vhost === '' || $vhost === null) {
        echo "cannot warm: root_url_private is not set in mgdb.conf\n";
    } else {
        $context = stream_context_create(array('http' => array(
            'header'          => "Host: $vhost\r\n",
            'timeout'         => 300,
            'ignore_errors'   => true
        )));

        echo "warming " . count($WARM_URLS) . " pages via $origin (Host: $vhost)\n";
        $failed = 0;
        foreach ($WARM_URLS as $path) {
            $started = microtime(true);
            $body = @file_get_contents($origin . $path, false, $context);
            $ms = (int) round((microtime(true) - $started) * 1000);
            if ($body === false || $body === '') {
                $failed++;
            }
            printf("  %-58s %s  %6d ms\n", $path,
                   ($body === false || $body === '') ? 'FAILED ' : 'ok     ', $ms);
        }
        if ($failed) {
            echo "\n  $failed page(s) failed to warm; they will build on first visit instead.\n";
        }
        echo "\n";
    }
}

if ($status || $purge || $warm) {
    $rows = dashboardCacheStatus($system);
    if (!$rows) {
        echo "cache is empty\n";
        exit(0);
    }
    echo count($rows) . " entr" . (count($rows) === 1 ? 'y' : 'ies') . ":\n";
    printf("  %-34s %10s  %s\n", 'KEY', 'BYTES', 'BUILT');
    foreach ($rows as $row) {
        printf("  %-34s %10d  %s\n", $row['key'], $row['bytes'], date('Y-m-d H:i:s', $row['built']));
    }
}
?>
