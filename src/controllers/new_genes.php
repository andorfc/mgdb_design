<?PHP
/* file: controllers/new_genes.php
 *
 * purpose: /new_genes -- characterized maize genes whose annotation changed,
 *          on the shared Data Hub shell, reading THIS server's database.
 *
 * What this replaced
 * ------------------
 * The legacy page rendered nothing itself. templates/tools/new_genes*.bau
 * `#load-static`'d a pre-built HTML fragment from /home/cache/newgene/, written
 * on the curation server and rsync'd across by cron. On this instance that
 * directory has been empty since it was created in August 2023, so Bauplan threw
 * "No such file" and the route served 178 bytes of error text -- not even the
 * site shell. A missing file killed the page rather than degrading it.
 *
 * It also made the page fresher than the database behind it: the fragment said
 * "today" and "yesterday" while this database's newest row was a month old, so
 * genes it listed had no record here. controllers/gene_center.php still carries
 * a referer sniff that bounces such visitors to cur.maizegdb.org because of it.
 *
 * Now: every gene listed is read from this database and therefore exists here,
 * and the page states its snapshot date instead of implying it is live. A link
 * to the curation server is offered for anyone who wants today's curation
 * (Carson, 2026-09-05).
 *
 * controller.php checks ./controllers/<CONTROLLER>.php before falling through to
 * redirect.php, which builds the legacy shell first, so the route is taken here
 * -- the same arrangement /contact, /mgec and /working_group use.
 *
 * Rollback: delete this file. redirect.php finds controllers/tools/new_genes.php
 * again and the legacy page returns, still broken until the rsync runs.
 * Pre-redesign files are archived in the redesign repo under legacy/new-genes/.
 */

  include_once('./include/gp_lib.php');
  include_once('./include/db-api.php');
  include_once('./include/dashboard_cache.php');
  include_once('./search/new_genes/new_genes_lib.php');

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/new_genes.php');

  $ng_doc_root = rtrim($system['root_dir'], '/');
  if (!is_dir($ng_doc_root . '/css') && isset($_SERVER['DOCUMENT_ROOT'])) {
      $ng_doc_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
  }

  $ng_windows = ng_windows();
  $ng_window  = getCGIParam('window', 'G', 'month');
  if (!isset($ng_windows[$ng_window])) { $ng_window = 'month'; }

  /* ---------------------------------------------------------------------- *
   * Download
   *
   * The export is the WHOLE matched set for the window, not the 1,000 rows the
   * table shows -- an export that reuses the display cap hands back a truncated
   * file under a button that says Download, and gets quietly worse as the
   * corpus grows. It is streamed rather than built in memory, and is not cached:
   * it is one query, and caching a file this size to serve it once is waste.
   * ---------------------------------------------------------------------- */
  if (getCGIParam('format', 'G', '') === 'tsv') {
      $conn = connect_to_database();
      $snapshot = ng_snapshot_date($conn);
      $rows = ng_rows($conn, $ng_window, null);

      $fname = 'maizegdb_new_annotations_' . $ng_window . '_' . date('Y-m-d') . '.tsv';
      header('Content-Type: text/tab-separated-values; charset=utf-8');
      header('Content-Disposition: attachment; filename="' . $fname . '"');

      $out = fopen('php://output', 'w');
      /* A comment header, so a file that outlives its download says what it is
         and how current the data behind it was. */
      fwrite($out, "# MaizeGDB genes with new annotations\n");
      fwrite($out, "# window\t" . $ng_windows[$ng_window]['label'] . "\n");
      fwrite($out, "# database snapshot\t" . $snapshot . "\n");
      fwrite($out, "# downloaded\t" . date('Y-m-d') . "\n");
      fwrite($out, "# rows\t" . count($rows) . "\n");
      fputcsv($out, array('gene', 'full_name', 'gene_models', 'reference_date',
                          'gene_product_date', 'variation_date', 'stock_count',
                          'last_change', 'maizegdb_url'), "\t");
      foreach ($rows as $r) {
          fputcsv($out, array(
              $r['name'],
              $r['full_name'],
              trim((string) $r['models']),
              $r['ref_date'] ? substr($r['ref_date'], 0, 10) : '',
              $r['gp_date']  ? substr($r['gp_date'], 0, 10)  : '',
              $r['var_date'] ? substr($r['var_date'], 0, 10) : '',
              (int) $r['stk_n'],
              $r['last_update'] ? substr($r['last_update'], 0, 10) : '',
              'https://www.maizegdb.org/gene_center/gene?id=' . (int) $r['id'],
          ), "\t");
      }
      fclose($out);
      exit;
  }

  /* The table is capped. "All time" is 18,879 genes, which is a listing nobody
     reads and a page nobody should have to download; the cap is stated on the
     page rather than silently truncating the way the old TSV exports did. */
  $NG_CAP = 1000;

  $conn = connect_to_database();

  /* Cached on the window AND on this file's mtime, because the row markup is
     built here -- a key that watched only the data would serve stale HTML after
     a markup edit. */
  $ng_key = 'new_genes/' . $ng_window . '_' . (int) @filemtime(__FILE__)
          . '_' . (int) @filemtime($ng_doc_root . '/search/new_genes/new_genes_lib.php');

  $ng = dashboardCache($system, $ng_key, function () use ($conn, $ng_window, $NG_CAP) {
      return array(
          'snapshot' => ng_snapshot_date($conn),
          'counts'   => ng_counts($conn),
          'rows'     => ng_rows($conn, $ng_window, $NG_CAP),
      );
  });

  $ng_rows     = isset($ng['rows'])     ? $ng['rows']     : array();
  $ng_counts   = isset($ng['counts'])   ? $ng['counts']   : array();
  $ng_snapshot = isset($ng['snapshot']) ? $ng['snapshot'] : null;

  $ng_snapshot_long = $ng_snapshot && strtotime($ng_snapshot)
                    ? date('j F Y', strtotime($ng_snapshot)) : 'unknown';

  /* ---------------------------------------------------------------------- *
   * Rendering
   * ---------------------------------------------------------------------- */

  /* How long ago, in words, measured from TODAY.
   *
   * The legacy page said "today" / "yesterday" / "9 days ago" and Carson asked
   * for that back. It was measured against the curation server, which is live;
   * this database is a monthly copy, so immediately after a sync the newest rows
   * really do read "Today" and they age through the month until the next one.
   * An earlier attempt measured against the snapshot instead and produced
   * "28 days before", which reads as a comparison to nothing and hid the fact
   * that the data itself was a month old. */
  function ng_ago($ts) {
      $days = (int) floor((time() - $ts) / 86400);
      if ($days < 0)   { return ''; }
      if ($days === 0) { return 'Today'; }
      if ($days === 1) { return 'Yesterday'; }
      if ($days < 7)   { return $days . ' days ago'; }
      if ($days < 14)  { return '1 week ago'; }
      if ($days < 61)  { return ((int) round($days / 7)) . ' weeks ago'; }
      if ($days < 365) {
          $m = (int) round($days / 30.44);
          return $m <= 1 ? '1 month ago' : $m . ' months ago';
      }
      $y = (int) floor($days / 365.25);
      return $y <= 1 ? '1 year ago' : $y . ' years ago';
  }

  /* Every date is a link into the section of the gene's record that holds those
     annotations, so a date is a way in rather than a fact to read and leave. */
  function ng_date_cell($value, $locus_id, $anchor, $label) {
      if (!$value) {
          return '<td class="ng-none"><span class="mgdb-visually-hidden">no record</span>&#8212;</td>';
      }
      $ts = strtotime($value);
      if (!$ts) { return '<td class="ng-none">&#8212;</td>'; }
      $href = '/gene_center/gene?id=' . (int) $locus_id . '#' . $anchor;
      $rel  = ng_ago($ts);
      return '<td data-sort="' . htmlspecialchars($value, ENT_QUOTES) . '">'
           . '<a class="ng-date" href="' . htmlspecialchars($href, ENT_QUOTES) . '">'
           . '<time datetime="' . htmlspecialchars(substr($value, 0, 10), ENT_QUOTES) . '">'
           . htmlspecialchars(date('j M Y', $ts), ENT_QUOTES) . '</time>'
           . ($rel ? '<span class="ng-rel">' . $rel . '</span>' : '')
           . '<span class="mgdb-visually-hidden"> &#8212; ' . htmlspecialchars($label, ENT_QUOTES) . '</span>'
           . '</a></td>';
  }

  $ng_table = '';
  foreach ($ng_rows as $r) {
      $gene_url = '/gene_center/gene?id=' . (int) $r['id'];
      $models   = trim((string) $r['models']);
      $model_html = '';
      if ($models !== '') {
          $parts = preg_split('/\s+/', $models);
          $shown = array_slice($parts, 0, 3);
          /* Each model is its own record. /gene_center/gene?id= takes a model
             name as well as a numeric locus id, so the name is the link. */
          foreach ($shown as $m) {
              $model_html .= '<a class="ng-model" href="/gene_center/gene?id='
                . rawurlencode($m) . '">' . htmlspecialchars($m, ENT_QUOTES) . '</a>';
          }
          if (count($parts) > count($shown)) {
              $model_html .= '<a class="ng-model-more" href="' . htmlspecialchars($gene_url, ENT_QUOTES)
                . '#gene-record-structure">+' . (count($parts) - count($shown))
                . '<span class="mgdb-visually-hidden"> more gene models</span></a>';
          }
      } else {
          $model_html = '<span class="ng-none">&#8212;</span>';
      }

      $ng_table .= '<tr>'
        . '<th scope="row"><a href="' . htmlspecialchars($gene_url, ENT_QUOTES) . '">'
        . htmlspecialchars((string) $r['name'], ENT_QUOTES) . '</a></th>'
        . '<td class="ng-fullname">' . htmlspecialchars((string) $r['full_name'], ENT_QUOTES) . '</td>'
        . '<td class="ng-models">' . $model_html . '</td>'
        . ng_date_cell($r['ref_date'], $r['id'], 'gene-record-references', 'references for this gene')
        . ng_date_cell($r['gp_date'],  $r['id'], 'gene-record-function',   'gene product and function')
        . ng_date_cell($r['var_date'], $r['id'], 'gene-record-variation',  'variations of this gene')
        /* Stocks have no section of their own on the gene record, and the stock
           hub ignores a URL term, so this points at the variation section --
           stocks hang off the gene's variations, which is where a reader can
           actually get to them. */
        . '<td class="ng-num" data-sort="' . (int) $r['stk_n'] . '">'
        . ((int) $r['stk_n'] > 0
            ? '<a class="ng-date" href="' . htmlspecialchars($gene_url, ENT_QUOTES) . '#gene-record-variation">'
              . number_format((int) $r['stk_n'])
              . '<span class="mgdb-visually-hidden"> '
              . ((int) $r['stk_n'] === 1 ? 'stock' : 'stocks')
              . ', via this gene\'s variations</span></a>'
            : '<span class="ng-none">&#8212;</span>')
        . '</td>'
        . ng_date_cell($r['last_update'], $r['id'], 'gene-record-overview', 'this gene record')
        . '</tr>';
  }
  if ($ng_table === '') {
      $ng_table = '<tr><td colspan="8" class="ng-empty">No genes changed in this window.</td></tr>';
  }

  $ng_chips = '';
  foreach ($ng_windows as $key => $meta) {
      $n = isset($ng_counts[$key]) ? $ng_counts[$key] : 0;
      $is = ($key === $ng_window);
      $ng_chips .= '<a class="mgdb-chip' . ($is ? ' is-current' : '') . '" href="/new_genes?window='
        . htmlspecialchars($key, ENT_QUOTES) . '"' . ($is ? ' aria-current="true"' : '') . '>'
        . htmlspecialchars($meta['label'], ENT_QUOTES)
        . ' <span class="ng-chip-n">' . number_format($n) . '</span></a>';
  }

  $ng_shown = count($ng_rows);
  $ng_total = isset($ng_counts[$ng_window]) ? $ng_counts[$ng_window] : $ng_shown;
  $ng_scope = $ng_shown < $ng_total
      ? 'Showing the ' . number_format($ng_shown) . ' most recently changed of ' . number_format($ng_total) . ' genes'
      : 'Showing all ' . number_format($ng_shown) . ' genes';

  /* ---------------------------------------------------------------------- *
   * Publish
   * ---------------------------------------------------------------------- */

  $bauplan = new Bauplan('Genes with new annotations | MaizeGDB');
  $bauplan->modern();

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . (int) @filemtime($ng_doc_root . '/css/mgdb-hub.css'));
  $bauplan->includeCss('/css/mgdb-new-genes.css?v=' . (int) @filemtime($ng_doc_root . '/css/mgdb-new-genes.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-new-genes.js?v=' . (int) @filemtime($ng_doc_root . '/js/mgdb-new-genes.js'));
  $bauplan->head('<meta name="description" content="Maize genes whose annotation changed recently at MaizeGDB, with a separate date for each kind of annotation: references, gene products, variations and seed stocks.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_new_genes.bau');
  $body->get('snapshot')->replace(htmlspecialchars($ng_snapshot_long, ENT_QUOTES));
  $body->get('window-chips')->replace($ng_chips);
  $body->get('scope-line')->replace(htmlspecialchars($ng_scope, ENT_QUOTES));
  $body->get('download-url')->replace(htmlspecialchars('/new_genes?window=' . $ng_window . '&format=tsv', ENT_QUOTES));
  $body->get('gene-rows')->replace($ng_table);
  $body->get('count-month')->replace(number_format(isset($ng_counts['month']) ? $ng_counts['month'] : 0));
  $body->get('count-year')->replace(number_format(isset($ng_counts['year']) ? $ng_counts['year'] : 0));
  $body->get('count-all')->replace(number_format(isset($ng_counts['alltime']) ? $ng_counts['alltime'] : 0));

  include_once('translation.php');
  $bauplan->publish();
?>
