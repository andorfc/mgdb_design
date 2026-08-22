<?php
/* file: include/dashboard_cache.php
 *
 * purpose: cache the collection-wide figures behind data-centre dashboards.
 *
 * Why
 * ---
 * Every data-centre landing page opens by counting its whole collection --
 * 54,818 references, 769,000 markers, the variation corpus -- so it can show
 * headline figures and draw its charts. Those aggregates are the same for every
 * visitor and they only change when the database is reloaded, which on the
 * production instance happens once a month. Measured on the development
 * instance before this cache existed:
 *
 *   /data_center/variation          10057 ms
 *   /data_center/map                 2084 ms
 *   /data_center/marker              2056 ms
 *   /data_center/stock               1184 ms
 *   /data_center/bac                 1136 ms
 *   /data_center/reference            804 ms
 *   /data_center/est                  430 ms
 *
 * That is roughly eighteen seconds of identical work repeated on every page
 * view for a month. /uniformmu already sidesteps it by reading a JSON file
 * written offline and renders in 43 ms; this is the same idea, except the file
 * builds itself on first miss instead of needing a tool run.
 *
 * Contract
 * --------
 *   $payload = dashboardCache($system, 'reference/summary', function () use ($DBConn) {
 *       return array('total' => ..., 'facets' => ...);   // anything json_encode can take
 *   });
 *
 * On a hit the builder never runs. On a miss it runs once and the result is
 * written atomically. When caching is off the builder runs every time and
 * nothing touches the filesystem, so behaviour is exactly what it was before.
 *
 * A cache must never be able to break a page. Every failure path here --
 * unwritable directory, corrupt JSON, a lost race, a builder that returns
 * nothing -- falls back to serving the builder's own result live.
 *
 * Configuration -- conf/mgdb.conf
 * -------------------------------
 *   dashboard_cache=true            master switch. Set to false on the curation
 *                                   instance, whose database takes writes all
 *                                   day and whose figures must stay live.
 *   dashboard_cache_path=/home/cache/dashboard
 *                                   where entries are written. Defaults to
 *                                   <search_cache_path>/dashboard, and must sit
 *                                   outside the web root.
 *   dashboard_cache_ttl=0           seconds before an entry is rebuilt. 0 means
 *                                   never expire, which is right for a database
 *                                   reloaded on a known schedule.
 *   dashboard_cache_stamp=          free text mixed into every filename. Change
 *                                   it to invalidate the whole cache at once
 *                                   without needing filesystem access -- the
 *                                   simplest thing to bump from the monthly
 *                                   load script.
 *
 * After a monthly reload, do one of: bump dashboard_cache_stamp, or run
 *   php tools/dashboard_cache.php --purge --warm
 *
 * Booleans follow the convention already in this file: the string "true".
 */

//
// Is the cache switched on for this instance?
//
// Anything other than the string "true" is off, and a missing key is off, so an
// instance that has never heard of this setting keeps its present behaviour.
//
function dashboardCacheEnabled($system) {
    return isset($system['dashboard_cache'])
        && strtolower(trim($system['dashboard_cache'])) === 'true';
}

//
// Directory holding cache entries. Falls back to the search cache location that
// this codebase already uses for generated files.
//
function dashboardCacheDir($system) {
    if (!empty($system['dashboard_cache_path'])) {
        return rtrim($system['dashboard_cache_path'], '/');
    }
    if (!empty($system['search_cache_path'])) {
        return rtrim($system['search_cache_path'], '/') . '/dashboard';
    }
    return '/tmp/mgdb-dashboard-cache';
}

//
// Seconds an entry stays fresh. 0 -- the default -- means it never expires on
// its own, which is what a monthly reload wants.
//
function dashboardCacheTtl($system) {
    return isset($system['dashboard_cache_ttl']) ? max(0, (int) $system['dashboard_cache_ttl']) : 0;
}

//
// Absolute path for one entry.
//
// The key is slugged rather than escaped so a caller cannot walk out of the
// cache directory with '../', and the configured stamp is folded in so bumping
// it retires every existing file at once.
//
function dashboardCacheFile($system, $key) {
    $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($key));
    $slug = trim($slug, '_');
    if ($slug === '') {
        $slug = 'entry';
    }
    $stamp = isset($system['dashboard_cache_stamp']) ? trim($system['dashboard_cache_stamp']) : '';
    if ($stamp !== '') {
        $slug .= '_' . substr(md5($stamp), 0, 8);
    }
    return dashboardCacheDir($system) . '/' . $slug . '.json';
}

//
// Make sure the cache directory exists and can be written to.
//
// This has to happen before the lock file is opened, not inside the write path:
// on a cold instance the directory does not exist yet, and fopen() on a lock
// file inside a missing directory fails, which would leave the cache
// permanently unable to build its first entry.
//
function dashboardCacheEnsureDir($system) {
    $dir = dashboardCacheDir($system);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    return is_writable($dir);
}

//
// Read one entry, or null when it is missing, stale, or unreadable.
//
function dashboardCacheRead($system, $key) {
    $file = dashboardCacheFile($system, $key);
    if (!is_readable($file)) {
        return null;
    }

    $ttl = dashboardCacheTtl($system);
    if ($ttl > 0) {
        $age = time() - (int) @filemtime($file);
        if ($age > $ttl) {
            return null;
        }
    }

    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') {
        return null;
    }

    $entry = json_decode($raw, true);
    // A half-written or hand-edited file is treated as a miss, not an error.
    if (!is_array($entry) || !array_key_exists('payload', $entry)) {
        return null;
    }

    return $entry;
}

//
// Write one entry atomically: a partly written file must never be read as a
// complete one, and two builders finishing together must not interleave.
//
// Returns true on success. A false here is not an error the caller should care
// about -- the payload is already in hand either way.
//
function dashboardCacheWrite($system, $key, $payload) {
    if (!dashboardCacheEnsureDir($system)) {
        return false;
    }

    $entry = array(
        'key'     => $key,
        'built'   => time(),
        'built_at'=> date('c'),
        'payload' => $payload
    );
    $encoded = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        return false;
    }

    $file = dashboardCacheFile($system, $key);
    $temp = $file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($temp, $encoded, LOCK_EX) === false) {
        @unlink($temp);
        return false;
    }
    if (!@rename($temp, $file)) {
        @unlink($temp);
        return false;
    }
    @chmod($file, 0664);

    return true;
}

//
// The one function callers use.
//
// $builder is any callable returning a json-encodable value. It runs only on a
// miss, or every time when the cache is switched off.
//
// $meta, when passed by reference, is filled in with how the value was obtained
// -- 'disabled', 'hit', 'miss', 'concurrent', or 'unwritable' -- plus the build
// time of a hit.
// Pages use it to show a data date and to prove in testing that a hit happened.
//
function dashboardCache($system, $key, $builder, &$meta = null) {
    $meta = array('status' => 'disabled', 'built' => null, 'key' => $key);

    if (!is_callable($builder)) {
        return null;
    }

    if (!dashboardCacheEnabled($system)) {
        return call_user_func($builder);
    }

    $entry = dashboardCacheRead($system, $key);
    if ($entry !== null) {
        $meta['status'] = 'hit';
        $meta['built']  = isset($entry['built']) ? (int) $entry['built'] : null;
        return $entry['payload'];
    }

    // A cache that cannot be written to must not become a cache that cannot be
    // read from either: fall straight through to the builder and say so.
    if (!dashboardCacheEnsureDir($system)) {
        $meta['status'] = 'unwritable';
        return call_user_func($builder);
    }

    // Stampede guard. The expensive builders here run for seconds, so if another
    // request is already building this entry we do not queue up behind it and we
    // do not build a second copy to write -- we just answer this request live,
    // exactly as an uncached instance would.
    $lock = @fopen(dashboardCacheFile($system, $key) . '.lock', 'c');
    $held = $lock ? @flock($lock, LOCK_EX | LOCK_NB) : false;

    $payload = call_user_func($builder);

    if ($held) {
        // Re-read first: another process may have finished between our miss and
        // our taking the lock.
        $entry = dashboardCacheRead($system, $key);
        if ($entry !== null) {
            $meta['status'] = 'hit';
            $meta['built']  = isset($entry['built']) ? (int) $entry['built'] : null;
            @flock($lock, LOCK_UN);
            @fclose($lock);
            return $entry['payload'];
        }
        if ($payload !== null) {
            dashboardCacheWrite($system, $key, $payload);
        }
        $meta['status'] = 'miss';
        $meta['built']  = time();
        @flock($lock, LOCK_UN);
    } else {
        $meta['status'] = 'concurrent';
    }

    if ($lock) {
        @fclose($lock);
    }

    return $payload;
}

//
// Drop entries. Without a key, everything. Returns the number of files removed.
//
function dashboardCachePurge($system, $key = null) {
    $dir = dashboardCacheDir($system);
    if (!is_dir($dir)) {
        return 0;
    }

    if ($key !== null) {
        $file = dashboardCacheFile($system, $key);
        return (is_file($file) && @unlink($file)) ? 1 : 0;
    }

    $removed = 0;
    foreach ((array) glob($dir . '/*.json') as $file) {
        if (@unlink($file)) {
            $removed++;
        }
    }
    foreach ((array) glob($dir . '/*.tmp') as $file) {
        @unlink($file);
    }

    return $removed;
}

//
// Everything currently cached, newest first. For the CLI tool and for support
// questions along the lines of "is this page showing me stale numbers".
//
function dashboardCacheStatus($system) {
    $rows = array();
    foreach ((array) glob(dashboardCacheDir($system) . '/*.json') as $file) {
        $entry = json_decode((string) @file_get_contents($file), true);
        $rows[] = array(
            'file'  => $file,
            'key'   => is_array($entry) && isset($entry['key']) ? $entry['key'] : basename($file, '.json'),
            'built' => (int) @filemtime($file),
            'bytes' => (int) @filesize($file)
        );
    }
    usort($rows, function ($a, $b) { return $b['built'] - $a['built']; });

    return $rows;
}
?>
