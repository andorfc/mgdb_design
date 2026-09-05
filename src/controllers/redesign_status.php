<?PHP
/* file: redesign_status.php
 *
 * purpose: main controller for /redesign_status — where the page redesign has
 *          got to, one row per URL the site exposes.
 *
 * The page renders data/redesign_status.json, which is written by
 * tools/redesign_status.py in the redesign repository. That script walks the
 * whole codebase on this instance, works out which URLs exist the same way
 * controller.php and redirect.php do, fetches each one over HTTP, and
 * classifies the response as modern, partial, or legacy. Nothing here measures
 * anything; this file only presents what the scan found.
 *
 * The route is new and shadows nothing: there was no controllers/redesign_status.php
 * and there is deliberately no redesign_status/ directory in the web root, for
 * the reason documented for /api and /projects — a real directory at that path
 * would stop .htaccess rewriting the URL to controller.php at all.
 *
 * Every row is rendered server-side. The table is complete before any script
 * runs, so a failed asset leaves a long readable page rather than an empty one,
 * and the JSON never has to be fetched by the browser.
 *
 * Rollback: delete this file and the /redesign_status route stops resolving.
 */

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/redesign_status.php');

  $status_file = $system['root_dir'] . '/data/redesign_status.json';
  $status      = file_exists($status_file)
               ? json_decode(file_get_contents($status_file), true)
               : null;

  function rs_esc($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
  }

  /* One status word, one pill. Partial is a warning rather than a success:
     a page carrying design system components inside the old shell is not
     finished, and colouring it green would say it was. */
  function rs_pill($state) {
    $tone = array(
      'modern'  => array('mgdb-pill-ok',   'Modern'),
      'partial' => array('mgdb-pill-warn', 'Partial'),
      'legacy'  => array('mgdb-pill-info', 'Legacy'),
      /* Retired is neither done nor outstanding: the route answers a 301 and
         has no page of its own left to modernize. It takes the page's own
         class rather than one of the four shared pill tones, so it reads as
         neither progress nor work. mgdb-pill-muted does not exist in any
         stylesheet -- see the .mgdb-table-wrapper lesson. */
      'retired' => array('status-pill-retired', 'Retired'),
    );
    $entry = isset($tone[$state]) ? $tone[$state] : array('', $state);
    return '<span class="mgdb-pill ' . $entry[0] . '">' . rs_esc($entry[1]) . '</span>';
  }

  /* A three-part bar rather than a single percentage. The proportion of a
     category still untouched is the thing being read, and one number hides it. */
  function rs_bar($modern, $partial, $legacy, $retired = 0) {
    $total = max(1, $modern + $partial + $legacy + $retired);
    $parts = '';
    foreach (array('modern' => $modern, 'partial' => $partial, 'legacy' => $legacy, 'retired' => $retired) as $state => $count) {
      if ($count <= 0) { continue; }
      $parts .= '<span class="status-bar-part status-bar-' . $state . '"'
              . ' style="width:' . round(100 * $count / $total, 2) . '%"></span>';
    }
    return '<span class="status-bar" aria-hidden="true">' . $parts . '</span>';
  }

  if (!is_array($status) || empty($status['rows'])) {
    /* Without the scan output there is no page. Saying so is better than
       rendering an empty table that reads as "nothing left to do". */
    $bauplan = new Bauplan('Redesign status | MaizeGDB');
    $bauplan->modern();
    $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
    $bauplan->includeCss('/css/static.css');
    $bauplan->includeCss('/css/mgdb-modern.css');
    $bauplan->includeCss('/css/mgdb-megamenu.css');
    $bauplan->includeCss('/css/mgdb-redesign-status.css');
    $bauplan->includeScript('/js/mgdb-modern.js');
    $bauplan->includeScript('/js/mgdb-chrome.js');
    $bauplan->head('<meta name="robots" content="noindex">');

    $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
    $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
    $mgdb->get('image-dir')->replace($system['image_url']);
    $mgdb->get('server-url')->replace($system['root_url']);

    $body = $mgdb->get('body')->load('templates/static/mgdb_redesign_status_missing.bau');

    include_once('translation.php');
    $mgdb->get('blast_url')->replace($system['BLAST_URL']);
    $bauplan->publish();
    return;
  }

  $rows       = $status['rows'];
  $counts     = $status['counts'];
  $total      = $status['total'];
  $categories = $status['category_order'];

  /* ---- headline metrics ---------------------------------------------------- */

  $generated = strtotime($status['generated']);
  $generated_html = $generated
      ? '<time datetime="' . rs_esc($status['generated']) . '">' . date('j F Y', $generated) . '</time>'
      : rs_esc($status['generated']);

  /* ---- progress by category ------------------------------------------------ */

  $category_rows = '';
  foreach ($categories as $category) {
      $bucket  = $status['by_category'][$category];
      $retired = isset($bucket['retired']) ? $bucket['retired'] : 0;
      /* Percent of the pages that still exist. A retired route is finished, so
         leaving it in the denominator would report a category as less complete
         the more of it was cleared away. */
      $live    = $bucket['total'] - $retired;
      $percent = $live > 0 ? round(100 * $bucket['modern'] / $live) : 0;
      $category_rows .=
          '<tr>'
        . '<th scope="row">' . rs_esc($category) . '</th>'
        . '<td class="mgdb-numeric" data-value="' . $bucket['modern'] . '">' . $bucket['modern'] . '</td>'
        . '<td class="mgdb-numeric" data-value="' . $bucket['partial'] . '">' . $bucket['partial'] . '</td>'
        . '<td class="mgdb-numeric" data-value="' . $bucket['legacy'] . '">' . $bucket['legacy'] . '</td>'
        . '<td class="mgdb-numeric" data-value="' . $retired . '">' . $retired . '</td>'
        . '<td class="mgdb-numeric" data-value="' . $bucket['total'] . '">' . $bucket['total'] . '</td>'
        . '<td class="status-progress" data-value="' . $percent . '">'
        . rs_bar($bucket['modern'], $bucket['partial'], $bucket['legacy'], $retired)
        . '<span class="status-progress-value">' . $percent . '%</span>'
        . '</td>'
        . '</tr>';
  }

  /* ---- what to work on next ------------------------------------------------ */

  $by_url = array();
  foreach ($rows as $row) {
      if (!isset($by_url[$row['url']])) { $by_url[$row['url']] = $row; }
  }

  $next_rows = '';
  $rank = 0;
  foreach ($status['next_up'] as $url) {
      if (!isset($by_url[$url])) { continue; }
      $row = $by_url[$url];
      if ($row['status'] === 'modern') { continue; }
      $rank++;
      $next_rows .=
          '<tr>'
        . '<td class="mgdb-numeric" data-value="' . $rank . '">' . $rank . '</td>'
        . '<td><a href="' . rs_esc($row['url']) . '"><code>' . rs_esc($row['url']) . '</code></a></td>'
        . '<td>' . rs_esc($row['category']) . '</td>'
        . '<td>' . rs_pill($row['status']) . '</td>'
        . '<td>' . ($row['in_menu'] ? 'Yes' : '<span class="mgdb-muted">No</span>') . '</td>'
        . '<td class="mgdb-numeric" data-value="' . $row['links_in'] . '">' . number_format($row['links_in']) . '</td>'
        . '<td><code class="status-file">' . rs_esc($row['serves']) . '</code></td>'
        . '</tr>';
  }

  /* ---- the full inventory -------------------------------------------------- */

  /* Every row carries its own searchable text and its two filter values, so the
     script that filters this table never has to read the DOM structure. */
  $inventory_rows = '';
  foreach ($rows as $row) {
      $urls = array_merge(array($row['url']), $row['also']);
      $url_html = '<a href="' . rs_esc($row['url']) . '"><code>' . rs_esc($row['url']) . '</code></a>';
      if (!empty($row['also'])) {
          $extra = array();
          foreach ($row['also'] as $other) {
              $extra[] = '<code>' . rs_esc($other) . '</code>';
          }
          $url_html .= '<span class="status-alias">also ' . implode(' ', $extra) . '</span>';
      }

      $search = trim(implode(' ', $urls) . ' ' . $row['category'] . ' ' . $row['serves']
                     . ' ' . $row['status'] . ' ' . (string)$row['title']);

      $evidence = $row['evidence'] === 'probe'
          ? '<span class="mgdb-small">Live response</span>'
          : '<span class="mgdb-small mgdb-muted">Source</span>';

      $inventory_rows .=
          '<tr data-status="' . rs_esc($row['status']) . '"'
        . ' data-category="' . rs_esc($row['category']) . '"'
        . ' data-search="' . rs_esc($search) . '">'
        . '<td class="status-url">' . $url_html . '</td>'
        . '<td>' . rs_pill($row['status']) . '</td>'
        . '<td>' . rs_esc($row['category']) . '</td>'
        . '<td>' . $evidence . '</td>'
        . '<td class="mgdb-numeric" data-value="' . $row['links_in'] . '">' . number_format($row['links_in']) . '</td>'
        . '<td>' . ($row['in_menu'] ? 'Yes' : '<span class="mgdb-muted">No</span>') . '</td>'
        . '<td><code class="status-file">' . rs_esc($row['serves']) . '</code></td>'
        . '</tr>';
  }

  /* Chips are built from the states actually present, so a chip can never be
     offered that matches nothing. */
  $chips = '<button class="mgdb-chip" type="button" data-filter="all" aria-pressed="true">All</button>';
  foreach (array('modern' => 'Modern', 'partial' => 'Partial', 'legacy' => 'Legacy') as $state => $label) {
      if (empty($counts[$state])) { continue; }
      $chips .= '<button class="mgdb-chip" type="button" data-filter="' . $state . '" aria-pressed="false">'
              . $label . ' <span class="mgdb-small">' . $counts[$state] . '</span></button>';
  }

  $category_options = '<option value="all">Every category</option>';
  foreach ($categories as $category) {
      $bucket = $status['by_category'][$category];
      $category_options .= '<option value="' . rs_esc($category) . '">'
                         . rs_esc($category) . ' (' . $bucket['total'] . ')</option>';
  }

  /* ---- built but not routed ------------------------------------------------ */

  $orphan_rows = '';
  foreach ($status['orphan_modern'] as $orphan) {
      $orphan_rows .=
          '<tr>'
        . '<td><code class="status-file">' . rs_esc($orphan['file']) . '</code></td>'
        . '<td>' . ($orphan['referenced_anywhere'] ? 'Yes' : '<span class="mgdb-muted">No</span>') . '</td>'
        . '</tr>';
  }

  /* ---- MaizeGDB applications on their own subdomain ------------------------ */

  $host_rows = '';
  foreach (array_slice($status['mgdb_hosts'], 0, 25) as $entry) {
      $host_rows .=
          '<tr>'
        . '<th scope="row"><code>' . rs_esc($entry['host']) . '</code></th>'
        . '<td class="mgdb-numeric" data-value="' . $entry['count'] . '">' . number_format($entry['count']) . '</td>'
        . '<td class="status-example">' . (empty($entry['examples']) ? '' : rs_esc($entry['examples'][0])) . '</td>'
        . '</tr>';
  }

  /* ---- publish ------------------------------------------------------------- */

  $bauplan = new Bauplan('Redesign status | MaizeGDB');
  $bauplan->modern();
  $bauplan->bodyClass('mgdb-wide');

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-redesign-status.css');
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-redesign-status.js');

  /* An internal progress report, not a page for readers of the site. */
  $bauplan->head('<meta name="robots" content="noindex, nofollow">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_redesign_status.bau');

  $body->get('generated')->replace($generated_html);
  $body->get('total-urls')->replace(number_format($total));
  /* get() returns the first node carrying an identifier, so the same total
     appearing twice in the template needs two names. */
  $body->get('inventory-total')->replace(number_format($total));
  $body->get('modern-count')->replace(number_format($counts['modern']));
  $body->get('partial-count')->replace(number_format($counts['partial']));
  $body->get('legacy-count')->replace(number_format($counts['legacy']));
  $body->get('retired-count')->replace(number_format(isset($counts['retired']) ? $counts['retired'] : 0));
  $body->get('percent-modern')->replace(number_format($status['percent_modern'], 1));
  $body->get('overall-bar')->replace(rs_bar($counts['modern'], $counts['partial'], $counts['legacy']));
  $body->get('evidence-note')->replace(
      $status['probed']
        ? 'Each URL below was fetched from this server and the response was read.'
        : 'This run read the source only; no URL was fetched.');

  $body->get('category-rows')->replace($category_rows);
  $body->get('next-rows')->replace($next_rows);
  $body->get('next-count')->replace(number_format($rank));
  $body->get('status-chips')->replace($chips);
  $body->get('category-options')->replace($category_options);
  $body->get('inventory-rows')->replace($inventory_rows);
  $body->get('orphan-rows')->replace($orphan_rows);
  $body->get('orphan-count')->replace(number_format(count($status['orphan_modern'])));
  $body->get('host-rows')->replace($host_rows);
  $body->get('host-count')->replace(number_format(count($status['mgdb_hosts'])));
  $body->get('generator')->replace(rs_esc($status['generator']));

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
?>
