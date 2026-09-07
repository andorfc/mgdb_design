<?php
/* file: past.php
 *
 * purpose: main controller for /past -- PAST, the Pathway Association Study
 *          Tool, hosted at MaizeGDB on three R Shiny servers.
 *
 * Why this file is at the top level
 * --------------------------------
 * controller.php checks controllers/<CONTROLLER>.php first and only falls
 * through to redirect.php when there is none. redirect.php loads
 * templates/maizegdb-main.bau -- the *legacy* main -- before it looks for a
 * page, so anything served that way carries index.css, background_static.css,
 * ie6.css and the shadowbox sheet no matter how modern its own markup is. The
 * legacy page was reached exactly that way: redirect.php ->
 * controllers/tools/past.php.
 *
 * There is no second route. /tools/past answers HTTP 200 with the generic
 * 38,935-byte not-found body, because there is no controllers/tools.php
 * dispatcher. controllers/tools/past.php and templates/tools/PAST.bau and
 * past-content.bau are untouched and archived in legacy/past/; deleting this
 * file hands /past straight back to them. That is the whole rollback.
 *
 * What changed from the legacy page
 * ---------------------------------
 * The description of the method is the one the page has carried since 2020.
 * What changed is everything the page asserted about the data and the servers,
 * because the collection moved on and the page did not:
 *
 *   - "Gene Model GFFs \(B73 RefGen_v2, v3, & v4\)" and the same for the pathway
 *     files. There is a **v5** of each in those directories now. Rather than
 *     retype the list, this controller reads the three download directories and
 *     builds the tables from what is actually in them, so the next assembly
 *     appears here on its own.
 *   - The example data was one link called "Kernel color example" with no hint
 *     that one of its three files is 229 MB. The sizes are listed.
 *   - Nothing on the page told a reader that the files have to match the
 *     assembly their GWAS was called against, or that a server's own list of
 *     assemblies can be shorter than what is published \(it is: all three were
 *     offering v2, v3 and v4 when this was written, with v5 published\). The
 *     note under the servers says both, without asserting a list it cannot
 *     keep true -- see past_render_versions_note\(\) for why that is not read
 *     from the app.
 *   - The README link went to the `master` branch, which 302s to `R`. It points
 *     at the branch that exists.
 *   - "MaizeGD PAST server #1" \(and #2, and #3\) -- the B was missing on all
 *     three.
 *   - The citation and the three papers using the method were hand-typed links,
 *     one of them with a mangled citation \("TPG: doi: 11:170069"\). All four now
 *     render through include/references_lib.php with Crossref-verified
 *     metadata. Crossref dates the Warburton paper 2018, not 2017.
 *   - images/past_view.png is gone. It was a stock View-Master illustration
 *     captioned "Get a clearer view with PAST", credited in-image to Vecteezy --
 *     decoration carrying a third-party credit, next to the citation box. The
 *     screenshot of real PAST output is kept, with a caption saying what it
 *     shows.
 *
 * history
 *  09/06/26  claude  created
 */

  /* dashboardCache() is not loaded by controller.php -- every page that caches
     an expensive figure includes it itself. Without this the page answers HTTP
     200 with a PHP fatal error in the body, which no status check catches. */
  include_once('./include/dashboard_cache.php');

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting modern past.php');

define('PAST_DOWNLOAD_BASE', 'https://download.maizegdb.org/GeneFunction_and_Expression/PAST/');

/* The three MaizeGDB Shiny instances, in the order the legacy page listed them.
   They are separate hosts running the same application, and each takes one job
   at a time -- which is why all three are offered rather than one behind a
   load balancer. */
function past_servers() {
  return array(
    array('n' => 1, 'url' => 'https://past1.maizegdb.org'),
    array('n' => 2, 'url' => 'https://past2.maizegdb.org'),
    array('n' => 3, 'url' => 'https://past3.maizegdb.org'),
  );
}

/* The three published directories, and what each is for. The *contents* are
   read from the server; only the framing lives here. */
function past_file_groups() {
  return array(
    array(
      'dir'   => 'Gene_model_files',
      'title' => 'Gene model files',
      'blurb' => 'One GFF per B73 assembly. PAST reads it to decide which gene each SNP belongs to, so it has to match the assembly your GWAS was called against.',
    ),
    array(
      'dir'   => 'Pathway_files',
      'title' => 'Pathway files',
      'blurb' => 'The genes making up each metabolic pathway, per assembly. These are the groups PAST tests for association.',
    ),
    array(
      'dir'   => 'Kernel_Color_Example',
      'title' => 'Worked example',
      'blurb' => 'A kernel-colour GWAS, complete, to run end to end against a fresh install. The linkage disequilibrium file is the large one.',
    ),
  );
}

/**
 * Fetch a URL, or null.
 *
 * curl rather than file_get_contents: download.maizegdb.org is behind
 * Cloudflare, which 403s some default user agents, and a named agent plus an
 * explicit timeout is what keeps a slow remote host from holding a page render
 * open. Six seconds is generous for a 1 KB directory index.
 */
function past_fetch($url) {
  if (!function_exists('curl_init')) { return null; }
  $ch = curl_init($url);
  curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_TIMEOUT        => 6,
    CURLOPT_USERAGENT      => 'MaizeGDB/1.0 (+https://maizegdb.org/past)',
  ));
  $body = curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return ($body !== false && $code === 200 && $body !== '') ? $body : null;
}

/**
 * Parse one Apache autoindex page into files.
 *
 * The listing is `IndexOptions FancyIndexing` inside a <pre>, one line per
 * entry: an icon, the link, the modification time, then Apache's own abbreviated
 * size. The size column is taken as the server prints it rather than HEADing
 * every file for an exact byte count -- eleven extra round trips to gain a
 * precision nobody reads off a download table.
 *
 * Sort links (`?C=N;O=D`) and Parent Directory (an absolute path) are the two
 * things in that <pre> that are not files, and both are excluded by requiring
 * the href to be a bare relative name.
 */
function past_parse_index($html) {
  $out = array();
  if ($html === null) { return $out; }

  $re = '#<a href="(?!\?|/)([^"]+)">[^<]*</a>\s*'      // the file name
      . '(\d{4}-\d{2}-\d{2}[ ]\d{2}:\d{2})\s+'          // last modified
      . '([0-9.]+[KMGT]?|-)#i';                         // Apache's size column

  if (preg_match_all($re, $html, $m, PREG_SET_ORDER)) {
    foreach ($m as $row) {
      $name = rawurldecode($row[1]);
      if (substr($name, -1) === '/') { continue; }      // a subdirectory
      $out[] = array(
        'name'     => $name,
        'modified' => $row[2],
        'size'     => $row[3],
      );
    }
  }
  return $out;
}

/* "3.9M" as Apache prints it, said the way the rest of the site says a size. */
function past_size_label($raw) {
  $raw = trim((string) $raw);
  if ($raw === '' || $raw === '-') { return ''; }
  if (preg_match('/^([0-9.]+)([KMGT])$/i', $raw, $m)) {
    $units = array('K' => 'KB', 'M' => 'MB', 'G' => 'GB', 'T' => 'TB');
    return $m[1] . ' ' . $units[strtoupper($m[2])];
  }
  return $raw . ' B';
}

/* The B73 assembly a file is for, from its own name. Both directories use the
   same Refgen_vN prefix; the example files carry no version and get none. */
function past_assembly($name) {
  return preg_match('/refgen_v(\d+)/i', $name, $m) ? 'B73 RefGen_v' . $m[1] : '';
}

/**
 * Everything this page reads from another host.
 *
 * Three fetches, one per download directory, about 0.6 s together -- far too
 * much to spend on every view, so it is cached.
 *
 * The key carries an ISO week rather than only this file's mtime. The usual
 * dashboardCache key never expires, which is right for a database reloaded on a
 * known schedule -- but this payload describes a *remote* directory that can
 * gain a file at any time, and an entry that never expires would put the page
 * straight back into the staleness it was rebuilt to fix. A week is far shorter
 * than the year those files last went unchanged and far longer than the cost of
 * four requests. Older entries are ~2 KB each and are not read again.
 *
 * Returning null when every fetch failed matters: dashboardCache does not write
 * a null payload, so a download host that is briefly down does not get its
 * outage cached for a week. The page then renders without the tables rather
 * than with a remembered list that may be wrong.
 */
function past_remote_data($system, &$meta = null) {
  $key = 'past/remote_' . date('o-W') . '_' . (int) @filemtime(__FILE__);

  return dashboardCache($system, $key, function () {
    $groups = array();
    $any = false;
    foreach (past_file_groups() as $g) {
      $files = past_parse_index(past_fetch(PAST_DOWNLOAD_BASE . $g['dir'] . '/'));
      if ($files) { $any = true; }
      $groups[$g['dir']] = $files;
    }

    if (!$any) { return null; }

    return array('groups' => $groups);
  }, $meta);
}

function past_esc($v) {
  return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/* A launch card per server. Deliberately no live up/down check: the servers
   take one job each, so a status read at cache-build time would be describing
   a queue that has since changed, and a stale "available" is worse than the
   plain instruction to try the next one. */
function past_render_servers() {
  $h = '';
  foreach (past_servers() as $s) {
    $h .= '<a class="past-server" href="' . past_esc($s['url']) . '" target="_blank" rel="noopener">'
        . '<span class="past-server-n" aria-hidden="true">' . (int) $s['n'] . '</span>'
        . '<span class="past-server-body">'
        . '<strong>MaizeGDB PAST server ' . (int) $s['n'] . ' <span aria-hidden="true">&nearr;</span></strong>'
        . '<span class="past-server-host">' . past_esc(preg_replace('#^https?://#', '', $s['url'])) . '</span>'
        . '</span></a>';
  }
  return $h;
}

/* Which assemblies have a published pair of files, straight from the listing.
 *
 * An earlier version of this read the assembly list out of the running Shiny
 * app on past1 and stated it here. That was wrong twice over. The app is
 * single-process and takes one job at a time, so it is *unreachable whenever
 * anyone is running a job* -- which is most of the point of having three of
 * them -- and a scrape of it therefore fails at random and would have cached
 * an empty note for a week. It was also reading a claim out of another
 * application's markup, which nothing keeps stable.
 *
 * So the note asserts only what this page can actually see: which assemblies
 * MaizeGDB publishes files for, and the instruction to check the server's own
 * form. A file being published is not a promise that the hosted app offers it.
 */
function past_render_versions_note($data) {
  $versions = array();
  foreach (array('Gene_model_files', 'Pathway_files') as $dir) {
    foreach ((isset($data['groups'][$dir]) ? $data['groups'][$dir] : array()) as $f) {
      if (preg_match('/refgen_v(\d+)/i', $f['name'], $m)) { $versions[$m[1]] = true; }
    }
  }
  if (!$versions) { return ''; }

  $keys = array_keys($versions);
  sort($keys, SORT_NUMERIC);
  $labels = array();
  foreach ($keys as $v) { $labels[] = 'v' . $v; }

  $list = count($labels) > 1
        ? implode(', ', array_slice($labels, 0, -1)) . ' and ' . end($labels)
        : $labels[0];

  return '<div class="mgdb-note" role="note">'
       . '<strong>Match the assembly.</strong> A PAST run is only meaningful if its gene model and pathway files '
       . 'are for the assembly your GWAS was called against. MaizeGDB publishes both for B73 RefGen_'
       . past_esc($list) . ' &#8212; listed under <a href="#past-files">Data files</a> below. '
       . 'Each server names the assemblies it offers in its own form, and that list can be shorter than '
       . 'the one published here; a local install can use any pair.'
       . '</div>';
}

/* One table per directory. A group whose listing could not be read is printed
   as a link to the directory saying so, rather than left out silently -- the
   files are still there, and a reader should not be told a directory is empty
   because a fetch timed out. */
function past_render_file_groups($data) {
  $h = '';
  foreach (past_file_groups() as $g) {
    $files = isset($data['groups'][$g['dir']]) ? $data['groups'][$g['dir']] : array();
    $dir_url = PAST_DOWNLOAD_BASE . $g['dir'] . '/';

    $h .= '<section class="past-files-group" aria-labelledby="past-files-' . past_esc($g['dir']) . '">';
    $h .= '<h3 id="past-files-' . past_esc($g['dir']) . '">' . past_esc($g['title']) . '</h3>';
    $h .= '<p class="past-files-blurb">' . past_esc($g['blurb']) . '</p>';

    if (!$files) {
      $h .= '<p class="past-files-fallback">This listing could not be read just now. '
          . '<a href="' . past_esc($dir_url) . '" target="_blank" rel="noopener">Open the directory '
          . '<span aria-hidden="true">&nearr;</span></a></p>';
      $h .= '</section>';
      continue;
    }

    $h .= '<div class="mgdb-table-scroll"><table class="mgdb-table past-files-table">';
    $h .= '<thead><tr><th scope="col">File</th><th scope="col">Assembly</th>'
        . '<th scope="col" class="past-num">Size</th><th scope="col">Updated</th></tr></thead><tbody>';

    foreach ($files as $f) {
      $assembly = past_assembly($f['name']);
      $h .= '<tr>';
      $h .= '<th scope="row"><a class="past-file-link" href="' . past_esc($dir_url . rawurlencode($f['name']))
          . '">' . past_esc($f['name']) . '</a></th>';
      $h .= '<td>' . ($assembly !== '' ? past_esc($assembly) : '<span class="past-dash" aria-label="not assembly specific">&mdash;</span>') . '</td>';
      $h .= '<td class="past-num">' . past_esc(past_size_label($f['size'])) . '</td>';
      $h .= '<td>' . past_esc(substr($f['modified'], 0, 10)) . '</td>';
      $h .= '</tr>';
    }

    $h .= '</tbody></table></div>';
    $h .= '<p class="past-files-dir"><a href="' . past_esc($dir_url) . '" target="_blank" rel="noopener">'
        . 'Open this directory <span aria-hidden="true">&nearr;</span></a></p>';
    $h .= '</section>';
  }
  return $h;
}

/* -------------------------------------------------------------------------- *
 * The document
 * -------------------------------------------------------------------------- */

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
            ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';

  $cache_meta = null;
  $data = past_remote_data($system, $cache_meta);
  if (!is_array($data)) { $data = array('groups' => array()); }

  $bauplan = new Bauplan('PAST, the Pathway Association Study Tool | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  /* The shared Data Hub shell, before the page sheet -- the ground, the white
     section cards, their coloured top edges, the shared table, the reference
     cards and the green Related resources panel. */
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-hub.css'));
  $bauplan->includeCss('/css/mgdb-past.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-past.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  /* Six sections, so the tab bar needs the shared scrollspy or its active state
     never leaves the first tab. The same file binds the Copy buttons. */
  $bauplan->includeScript('/js/mgdb-past.js?v=' . (int) @filemtime($doc_root . '/js/mgdb-past.js'));
  $bauplan->head('<meta name="description" content="PAST, the Pathway Association Study Tool, assigns GWAS SNPs to genes and genes to metabolic pathways. Run it on one of three MaizeGDB Shiny servers, or install it locally with Docker or Bioconductor.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_past.bau');

  $body->get('server_cards')->replace(past_render_servers());
  $body->get('app_versions_note')->replace(past_render_versions_note($data));
  $body->get('file_groups')->replace(past_render_file_groups($data));

  /* When the listing was read, from the cache entry's own build time -- not
     date() at render, which would print today whatever the tables are showing,
     and not date() inside the builder, which freezes and then presents itself
     as a freshness stamp. Omitted rather than guessed when the cache is off or
     the entry was served live. */
  $built = isset($cache_meta['built']) ? (int) $cache_meta['built'] : 0;
  $body->get('files_measured')->replace(
    $built > 0
      ? 'This listing is read from download.maizegdb.org rather than typed here, so a new assembly appears on its own; last read ' . date('F j, Y', $built) . '.'
      : 'This listing is read from download.maizegdb.org rather than typed here, so a new assembly appears on its own.'
  );

/* The tool paper and the three studies the legacy page cited, rendered by
   include/references_lib.php so the cards match every other page. None is in
   data/cite_journal_articles.json -- that bibliography is MaizeGDB's own
   output -- so each carries a Crossref-verified fallback. Tool paper first,
   then the applications newest to oldest. */
  include_once('./include/references_lib.php');
  $body->get('reference_cards')->replace(mgdb_render_references($doc_root, array(

    array('doi' => '10.3390/plants9010058',
          'kind' => 'The tool',
          'fallback' => array(
              'title'   => 'PAST: The Pathway Association Studies Tool to Infer Biological Meaning from GWAS Datasets',
              'authors' => 'Thrash A, Tang JD, DeOrnellis M, Peterson DG, Warburton ML.',
              'journal' => 'Plants',
              'year'    => '2020',
              'volume'  => '9',
              'pages'   => '58',
              'pubmed'  => '31906457',
              'abstract' => 'In recent years, a bioinformatics method for interpreting genome-wide association study (GWAS) data using metabolic pathway analysis has been developed and successfully used to find significant pathways and mechanisms explaining phenotypic traits of interest in plants. However, the many scripts implementing this method were not straightforward to use, had to be customized for each project, required user supervision, and took more than 24 h to process data. PAST (Pathway Association Study Tool), a new implementation, requires no user supervision and runs in under an hour.',
          )),

    array('doi' => '10.1111/tpj.14282',
          'fallback' => array(
              'title'   => 'Leveraging GWAS data to identify metabolic pathways and networks involved in maize lipid biosynthesis',
              'authors' => 'Li H, Thrash A, Tang JD, He L, Yan J, Warburton ML.',
              'journal' => 'The Plant Journal',
              'year'    => '2019',
              'volume'  => '98',
              'pages'   => '853-863',
              'pubmed'  => '30742331',
              'abstract' => 'Maize (Zea mays mays) oil is a rich source of polyunsaturated fatty acids and energy, making it a valuable resource for human food, animal feed, and bio-energy. Although this trait has been studied via conventional genome-wide association study (GWAS), the SNP-trait associations generated by GWAS may miss the underlying associations when traits are based on many genes, each with small effects that can be overshadowed by genetic background and environmental variation.',
          )),

    array('doi' => '10.3835/plantgenome2017.08.0069',
          'fallback' => array(
              'title'   => 'Genome-Wide Association and Metabolic Pathway Analysis of Corn Earworm Resistance in Maize',
              'authors' => 'Warburton ML, Womack ED, Tang JD, Thrash A, Smith JS, Xu W, Murray SC, Williams WP.',
              'journal' => 'The Plant Genome',
              'year'    => '2018',
              'volume'  => '11',
              'pubmed'  => '29505629',
              'abstract' => 'Damage to the growing ears by corn earworm (Helicoverpa zea) is a major economic burden and increases secondary fungal infections and mycotoxin levels. To identify biochemical pathways associated with native resistance mechanisms, a genome-wide association analysis was combined with metabolic pathway analysis in maize.',
          )),

    array('doi' => '10.1186/s12864-015-1874-9',
          'fallback' => array(
              'title'   => 'Using genome-wide associations to identify metabolic pathways involved in maize aflatoxin accumulation resistance',
              'authors' => 'Tang JD, Perkins A, Williams WP, Warburton ML.',
              'journal' => 'BMC Genomics',
              'year'    => '2015',
              'volume'  => '16',
              'pages'   => '673',
              'pubmed'  => '26334534',
          )),
  )));

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
  exit;
?>
