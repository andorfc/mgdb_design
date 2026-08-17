<?php
/* file: coming_soon.php
 *
 * purpose: main controller for /coming_soon — MaizeGDB Development Roadmap & Coming Soon
 *
 * Replaces the legacy static page over the canonical /coming_soon route using
 * the modern design pattern library.
 */

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/coming_soon.php');

  /* ---- Load JSON Data ---------------------------------------------------- */
  $data_file = $system['root_dir'] . '/data/coming_soon.json';
  if (!is_file($data_file)) {
      $data_file = $_SERVER['DOCUMENT_ROOT'] . '/data/coming_soon.json';
  }

  $data = array('items' => array());
  if (is_file($data_file)) {
      $json_raw = file_get_contents($data_file);
      $decoded  = json_decode($json_raw, true);
      if (is_array($decoded)) {
          $data = $decoded;
      }
  }

  $items = isset($data['items']) ? $data['items'] : array();

  /* Helper escaping functions */
  function cs_esc($str) {
      return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
  }

  function cs_pill($status_type, $label) {
      $class = 'mgdb-pill-info';
      if ($status_type === 'completed') {
          $class = 'mgdb-pill-ok';
      } elseif ($status_type === 'in_progress') {
          $class = 'mgdb-pill-warn';
      } elseif ($status_type === 'beta') {
          $class = 'mgdb-pill-info';
      }
      return '<span class="mgdb-pill ' . $class . '">' . cs_esc($label) . '</span>';
  }

  /* ---- Calculate Statistics & Filter Metadata --------------------------- */
  $total = count($items);
  $counts = array(
      'beta'        => 0,
      'in_progress' => 0,
      'completed'   => 0,
      'planned'     => 0
  );
  $categories = array();

  foreach ($items as $item) {
      $st = isset($item['status_type']) ? $item['status_type'] : 'planned';
      if (!isset($counts[$st])) {
          $counts[$st] = 0;
      }
      $counts[$st]++;

      $cat = isset($item['category']) ? $item['category'] : 'General';
      if (!in_array($cat, $categories, true)) {
          $categories[] = $cat;
      }
  }
  sort($categories);

  /* ---- Render Category Select Options ------------------------------------ */
  $category_options = '<option value="all">Every category</option>';
  foreach ($categories as $cat) {
      $category_options .= '<option value="' . cs_esc($cat) . '">' . cs_esc($cat) . '</option>';
  }

  /* ---- Render Status Filter Chips ---------------------------------------- */
  $chips = '<button class="mgdb-chip" type="button" data-filter="all" aria-pressed="true">All</button>';
  $status_map = array(
      'beta'        => 'Beta release',
      'in_progress' => 'In progress',
      'completed'   => 'Completed',
      'planned'     => 'Planned'
  );
  foreach ($status_map as $st_key => $st_label) {
      if (empty($counts[$st_key])) { continue; }
      $chips .= '<button class="mgdb-chip" type="button" data-filter="' . cs_esc($st_key) . '" aria-pressed="false">'
              . cs_esc($st_label) . ' <span class="mgdb-small">' . $counts[$st_key] . '</span></button>';
  }

  /* ---- Render Table Rows ------------------------------------------------ */
  $table_rows = '';
  foreach ($items as $item) {
      $title       = isset($item['title']) ? $item['title'] : '';
      $cat         = isset($item['category']) ? $item['category'] : '';
      $desc        = isset($item['description']) ? $item['description'] : '';
      $status      = isset($item['status']) ? $item['status'] : '';
      $status_type = isset($item['status_type']) ? $item['status_type'] : 'planned';
      $target_date = isset($item['target_date']) ? $item['target_date'] : '';
      $link        = isset($item['link']) ? $item['link'] : null;

      $search_text = trim($title . ' ' . $cat . ' ' . $desc . ' ' . $status . ' ' . $target_date);

      $link_html = '<span class="mgdb-muted">Pending release</span>';
      if (!empty($link)) {
          $link_html = '<a href="' . cs_esc($link) . '" class="cs-link-icon" target="_blank" rel="noopener">'
                     . '<span>Access Tool</span>'
                     . '<svg aria-hidden="true" viewBox="0 0 20 20"><path d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z"/><path d="M5 5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3a1 1 0 10-2 0v3H5V7h3a1 1 0 000-2H5z"/></svg>'
                     . '</a>';
      }

      $table_rows .= '<tr data-status="' . cs_esc($status_type) . '"'
                   . ' data-category="' . cs_esc($cat) . '"'
                   . ' data-search="' . cs_esc($search_text) . '">'
                   . '<th scope="row" class="cs-url">' . cs_esc($title) . '</th>'
                   . '<td>' . cs_esc($cat) . '</td>'
                   . '<td>' . cs_esc($desc) . '</td>'
                   . '<td>' . cs_pill($status_type, $status) . '</td>'
                   . '<td>' . cs_esc($target_date) . '</td>'
                   . '<td>' . $link_html . '</td>'
                   . '</tr>';
  }

  /* ---- Bauplan Page Setup ----------------------------------------------- */
  $bauplan = new Bauplan('Coming Soon & Development Roadmap | MaizeGDB');
  $bauplan->modern();
  $bauplan->bodyClass('mgdb-wide');

  $v = time(); // Cache-busting timestamp
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css?v=' . $v);
  $bauplan->includeCss('/css/mgdb-modern.css?v=' . $v);
  $bauplan->includeCss('/css/mgdb-megamenu.css?v=' . $v);
  $bauplan->includeCss('/css/mgdb-coming-soon.css?v=' . $v);
  $bauplan->includeScript('/js/mgdb-modern.js?v=' . $v);
  $bauplan->includeScript('/js/mgdb-chrome.js?v=' . $v);
  $bauplan->includeScript('/js/mgdb-coming-soon.js?v=' . $v);
  $bauplan->head('<meta name="description" content="Track upcoming features, tools, genomic datasets, and page modernizations coming to MaizeGDB.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->loadRemote($system['root_url_private'] . '/templates/static/mgdb_coming_soon.bau');

  $body->get('total-items')->replace(number_format($total));
  $body->get('beta-count')->replace(number_format($counts['beta']));
  $body->get('in-progress-count')->replace(number_format($counts['in_progress']));
  $body->get('completed-count')->replace(number_format($counts['completed']));

  $body->get('category-options')->replace($category_options);
  $body->get('status-chips')->replace($chips);
  $body->get('table-rows')->replace($table_rows);

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
?>
