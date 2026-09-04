<?PHP
/* file: whatsnew.php
 *
 * purpose: main controller for /whatsnew, the MaizeGDB news archive.
 *
 * Replaces the modernized news archive over the canonical /whatsnew route.
 * controller.php checks controllers/<CONTROLLER>.php first, so this takes the
 * route without touching controllers/about/whatsnew.php or the about
 * templates. Pre-redesign files are archived in the redesign repository under
 * legacy/whatsnew/. Rollback: delete this file.
 *
 * The archive is 260-odd items over two decades, which is small enough to
 * render in full and filter in the browser. That keeps every item findable by
 * the browser's own find-in-page and makes a shared /whatsnew#2019 link work
 * without a round trip.
 *
 * data/news.xml is curator-maintained and its <news> bodies contain markup --
 * links, line breaks, the occasional emphasis. That markup is emitted as-is,
 * which is what the previous page did; only the file's own editors can change
 * it. Everything the parser reads *around* the body -- dates, image paths, ids
 * -- is escaped here, because a bad path should not be able to break out of an
 * attribute.
 */

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/whatsnew.php');

  define('WN_NEWS_FILE', $system['root_dir'] . '/data/news.xml');

  $MONTH_NUMBER = array(
    'january' => '01', 'february' => '02', 'march' => '03', 'april' => '04',
    'may' => '05', 'june' => '06', 'july' => '07', 'august' => '08',
    'september' => '09', 'october' => '10', 'november' => '11', 'december' => '12',
  );

  function wnEsc($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
  }

  /* Four of the fifteen image paths in news.xml are stored without a leading
     slash. They happen to resolve from /whatsnew today because the page sits at
     the web root, and would stop resolving the moment the route gained a path
     segment. Normalized here rather than left to luck. */
  function wnImagePath($raw) {
    $path = trim((string) $raw);
    if ($path === '') { return ''; }
    if (preg_match('~^https?://~i', $path)) { return $path; }
    return '/' . ltrim($path, '/');
  }

  /* Intrinsic dimensions for the news images.

     The images are lazy-loaded -- most of the 260-odd items are far below the
     fold -- and a lazy image with no width/height reserves no space, so the
     page reflows under the reader as each one arrives. Emitting the real
     dimensions holds the box from the start.

     Only fifteen distinct files are referenced across the archive, so the
     results are memoized and each is stat'd at most once per request. */
  function wnImageSize($path) {
    static $cache = array();
    if ($path === '' || preg_match('~^https?://~i', $path)) { return null; }
    if (array_key_exists($path, $cache)) { return $cache[$path]; }

    $file = $GLOBALS['system']['root_dir'] . $path;
    $size = (is_readable($file) ? @getimagesize($file) : false);
    $cache[$path] = ($size && $size[0] > 0) ? array($size[0], $size[1]) : null;
    return $cache[$path];
  }

  function wnReadNews($file) {
    if (!is_readable($file)) { return array(); }
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_file($file);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if ($xml === false) { return array(); }

    global $MONTH_NUMBER;
    $items = array();
    foreach ($xml->entry as $entry) {
      $year  = trim((string) $entry->date->year);
      $month = trim((string) $entry->date->month);
      $day   = trim((string) $entry->date->day);
      $body  = trim((string) $entry->news);
      if ($year === '' || $body === '') { continue; }

      $key = strtolower($month);
      $mm  = isset($MONTH_NUMBER[$key]) ? $MONTH_NUMBER[$key] : '00';

      $items[] = array(
        'year'    => $year,
        'month'   => $month,
        'day'     => $day,
        'mm'      => $mm,
        'iso'     => $mm === '00' ? $year : sprintf('%s-%s-%02d', $year, $mm, (int) $day),
        'label'   => trim($month . ' ' . ($day !== '' ? $day . ', ' : '') . $year),
        'body'    => $body,
        'image'   => wnImagePath(isset($entry->imgg) ? $entry->imgg : ''),
        'id'      => trim((string) $entry->id),
        'sortkey' => sprintf('%s%s%02d', $year, $mm, (int) $day),
      );
    }

    /* Newest first. The file is roughly in that order already but not
       reliably, and the year headings depend on it being exact. */
    usort($items, function ($a, $b) { return strcmp($b['sortkey'], $a['sortkey']); });
    return $items;
  }

  $news = wnReadNews(WN_NEWS_FILE);

  /* Group into years, preserving the sorted order. */
  $by_year = array();
  foreach ($news as $item) { $by_year[$item['year']][] = $item; }

  $with_images = 0;
  foreach ($news as $item) { if ($item['image'] !== '') { $with_images++; } }

  /* ---- render ------------------------------------------------------------ */

  $year_options = '';
  foreach ($by_year as $year => $items) {
    $year_options .= '<option value="' . wnEsc($year) . '">' . wnEsc($year)
                   . ' (' . count($items) . ')</option>';
  }

  $sections = '';
  foreach ($by_year as $year => $items) {
    $rows = '';
    foreach ($items as $item) {
      /* The searchable text is the body with its markup stripped, so a search
         for "browser" does not match an href. */
      $plain = trim(preg_replace('/\s+/', ' ', strip_tags($item['body'])));
      $search = $item['label'] . ' ' . $year . ' ' . $plain;

      $figure = '';
      if ($item['image'] !== '') {
        /* alt="" on purpose: every one of these is a portrait or a release
           graphic that the adjacent item already describes in words, so a
           generated alt would only repeat it. */
        $dims = wnImageSize($item['image']);
        $figure = '<img class="news-item-image" src="' . wnEsc($item['image']) . '" alt=""'
                . ($dims ? ' width="' . $dims[0] . '" height="' . $dims[1] . '"' : '')
                . ' loading="lazy" />';
      }

      $anchor = $item['id'] !== '' ? ' id="news-' . wnEsc($item['id']) . '"' : '';
      $permalink = $item['id'] !== ''
        ? '<a class="news-item-link" href="#news-' . wnEsc($item['id'])
          . '">Link to this item</a>'
        : '';

      $rows .= '<article class="news-item"' . $anchor
             . ' data-year="' . wnEsc($year) . '"'
             . ' data-search="' . wnEsc($search) . '">'
             . '<p class="news-item-date"><time datetime="' . wnEsc($item['iso']) . '">'
             . wnEsc($item['label']) . '</time></p>'
             . '<div class="news-item-main">'
             . $figure
             . '<div class="news-item-body">' . $item['body']
             . ($permalink ? '<p class="news-item-actions">' . $permalink . '</p>' : '')
             . '</div>'
             . '</div>'
             . '</article>';
    }

    $sections .= '<section class="news-year" data-year-section="' . wnEsc($year) . '"'
               . ' aria-labelledby="news-year-' . wnEsc($year) . '">'
               . '<h2 class="news-year-heading" id="news-year-' . wnEsc($year) . '">'
               . wnEsc($year) . '<span class="news-year-count">' . count($items) . '</span></h2>'
               . '<div class="news-year-items">' . $rows . '</div>'
               . '</section>';
  }

  $bauplan = new Bauplan('News archive | MaizeGDB');
  $bauplan->modern();

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-whatsnew.css');
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-whatsnew.js');
  $bauplan->head('<meta name="description" content="Every MaizeGDB announcement since 2002: releases, tools, meetings, jobs, and community news, searchable and filterable by year.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');

  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_whatsnew.bau');
  $body->get('news-total')->replace(number_format(count($news)));
  $body->get('news-years')->replace((string) count($by_year));
  $body->get('news-first-year')->replace($news ? wnEsc(end($news)['year']) : '');
  $body->get('news-image-count')->replace(number_format($with_images));
  $body->get('year-options')->replace($year_options);
  $body->get('news-sections')->replace($sections);

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
?>
