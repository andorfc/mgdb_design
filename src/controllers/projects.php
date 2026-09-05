<?php
/* file: projects.php
 *
 * purpose: main controller for /projects — the maize research projects
 *          directory. Widened from MaizeGDB's own analyses to the whole
 *          directory on 2026-09-05; /doc and /documentation/projects were
 *          the other two listings of the same idea and now redirect here.
 *
 *          this script is loaded by controller.php
 *
 *          /projects              the listing page, built from the registry
 *          /projects/<slug>       one project, rendered by its own controller
 *          /projects/<anything>   404, in the modern shell
 *
 * The route is new and shadows nothing: there was no controllers/projects.php
 * and there is deliberately no projects/ directory in the web root. A real
 * directory at that path would stop .htaccess rewriting /projects/... to
 * controller.php at all, which is the same trap documented for /api.
 *
 * Rollback: delete this file and the /projects route stops resolving.
 */

  include_once('./include/projects_lib.php');

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/projects.php: PAGE: ' . PAGE);

  $slug    = trim((string)PAGE);
  $project = mgdb_project($slug);

  /* A project whose page lives elsewhere on the site is listed here but not
     served here. Send the reader to the real page rather than 404ing a slug the
     registry does recognise, or including a controller that does not exist. */
  if ($project !== null && !empty($project['hosted_elsewhere'])) {
      header('HTTP/1.1 301 Moved Permanently');
      header('Location: ' . $project['url']);
      return;
  }

  /* One project. Its controller owns the whole page from here. */
  if ($project !== null) {
      include($project['controller']);
      return;
  }

  /* An unrecognized slug is a 404, not the listing page with a message. A
     mistyped project URL that silently returned a 200 would be indexed as a
     real page and would look to a script like the project exists. */
  if ($slug !== '') {
      header('HTTP/1.1 404 Not Found');

      $bauplan = new Bauplan('Project not found | MaizeGDB');
      $bauplan->modern();

      $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
      $bauplan->includeCss('/css/static.css');
      $bauplan->includeCss('/css/mgdb-modern.css');
      $bauplan->includeCss('/css/mgdb-megamenu.css');
      $bauplan->includeCss('/css/mgdb-hub.css');
      $bauplan->includeCss('/css/mgdb-projects.css');
      $bauplan->includeScript('/js/mgdb-modern.js');
      $bauplan->includeScript('/js/mgdb-chrome.js');
      $bauplan->head('<meta name="robots" content="noindex">');

      $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
      $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
      $mgdb->get('image-dir')->replace($system['image_url']);
      $mgdb->get('server-url')->replace($system['root_url']);

      $body = $mgdb->get('body')->load('templates/static/mgdb_projects_notfound.bau');
      $body->get('requested-slug')->replace(mgdb_project_esc($slug));

      include_once('translation.php');
      $mgdb->get('blast_url')->replace($system['BLAST_URL']);
      $bauplan->publish();
      return;
  }

  /* ---- the listing page ---------------------------------------------------- */

  $projects   = mgdb_projects();
  $topics     = mgdb_project_topics();
  $categories = mgdb_project_categories();

  /* Chips run on the category axis. They are built from the categories the
     registry actually uses, so a chip can never be offered that matches
     nothing, and a project can never carry a category with no chip.

     Topics were the chip axis until 2026-09-05. With fifteen projects the
     useful first cut is what kind of project it is; the topics are still on
     every card and still in the text the search box matches. */
  $category_counts = array();
  foreach ($projects as $entry) {
      $category = isset($entry['category']) ? $entry['category'] : '';
      if ($category === '') { continue; }
      $category_counts[$category] = isset($category_counts[$category]) ? $category_counts[$category] + 1 : 1;
  }

  $chips = '<button class="mgdb-chip" type="button" data-filter="all" aria-pressed="true">All</button>';
  foreach ($categories as $category => $meta) {
      if (!isset($category_counts[$category])) { continue; }
      $chips .= '<button class="mgdb-chip" type="button" data-filter="' . mgdb_project_esc($category) . '"'
              . ' aria-pressed="false">' . mgdb_project_esc($meta['label'])
              . ' <span class="projects-chip-count">' . (int) $category_counts[$category] . '</span></button>';
  }

  $cards = '';
  foreach ($projects as $slug => $entry) {
      $project  = mgdb_project($slug);
      $category = isset($entry['category']) ? $entry['category'] : '';
      $category_label = isset($categories[$category]) ? $categories[$category]['label'] : '';

      /* Every topic label, the category and the lead join the searchable text,
         so typing "immunity", "genome" or "Dooner" finds a project whose card
         copy may never use the word. */
      $topic_labels = array();
      foreach ($entry['topics'] as $topic) {
          $topic_labels[] = isset($topics[$topic]) ? $topics[$topic] : $topic;
      }
      $search = trim($entry['title'] . ' ' . $entry['card_summary'] . ' '
                   . implode(' ', $topic_labels) . ' ' . $category_label . ' '
                   . (isset($entry['lead']) ? $entry['lead'] : ''));

      $tag_html = '';
      foreach ($entry['topics'] as $topic) {
          $tag_html .= '<span class="mgdb-pill mgdb-pill-info">'
                     . mgdb_project_esc(isset($topics[$topic]) ? $topics[$topic] : $topic)
                     . '</span>';
      }

      /* The kernels from the site mark, on the projects MaizeGDB itself runs.
         Decorative: the meta line already says "led by MaizeGDB" in words, so a
         reader who cannot see the mark loses nothing.

         It goes inside the <h3>, before the link, because the CSS floats it:
         the title wraps around the mark on its first line and runs full width
         under it. Positioned absolutely with the heading padded to clear it,
         which is what this started as, it cost the three marked cards three
         extra lines of title each and left their pill rows 69px lower than
         their neighbours' in the same grid row. */
      $mark_html = !empty($entry['maizegdb'])
          ? '<img class="projects-card-mark" src="/images/kernels.png" alt="" aria-hidden="true"'
            . ' width="368" height="213" loading="lazy" decoding="async" />'
          : '';

      $facts_html = '';
      if (!empty($entry['card_facts'])) {
          $facts_html = '<dl class="projects-card-facts">';
          foreach ($entry['card_facts'] as $fact) {
              $facts_html .= '<div><dt>' . mgdb_project_esc($fact[1]) . '</dt>'
                           . '<dd>' . mgdb_project_esc($fact[0]) . '</dd></div>';
          }
          $facts_html .= '</dl>';
      }

      /* The meta line prints only what the registry actually holds. A project
         run elsewhere has no update date we can stand behind, and a card that
         printed the day it was served would claim the data had just changed --
         the bug already removed from the CAAS project page once.

         It opens with the category so that every card says what kind of thing
         it is while the All chip is selected, and so the six genome projects,
         which carry neither a lead nor a date, still have a line worth reading.

         What it does NOT print is the internal path of a project hosted
         elsewhere on the site. That was worth a phrase when AlphaFill was the
         only such entry among three; with twelve of fifteen hosted elsewhere it
         is twelve repetitions of a URL the card title already links to. An
         offsite destination is different -- it is a promise about where the
         click lands, so it is still named, by host rather than by path. */
      $meta = array();
      if ($category_label !== '') {
          $meta[] = mgdb_project_esc(isset($categories[$category]['label_one'])
              ? $categories[$category]['label_one'] : $category_label);
      }
      if (!empty($entry['lead'])) {
          $meta[] = 'led by ' . mgdb_project_esc($entry['lead']);
      }
      if (!empty($entry['updated']) && ($updated = strtotime($entry['updated']))) {
          $meta[] = 'updated <time datetime="' . mgdb_project_esc($entry['updated']) . '">'
                  . date('j F Y', $updated) . '</time>';
      }
      if (!empty($entry['has_downloads'])) {
          $meta[] = 'data downloads available';
      }
      if (!empty($project['external'])) {
          $host = parse_url($project['url'], PHP_URL_HOST);
          $meta[] = 'hosted at ' . mgdb_project_esc($host ? preg_replace('~^www\.~', '', $host) : 'another site');
      }

      /* An offsite destination says so before it is clicked, and opens in a new
         tab, the same contract every other external link on the site keeps. */
      $link_attrs = !empty($project['external'])
          ? ' target="_blank" rel="noopener"'
          : '';
      $link_cue = !empty($project['external'])
          ? ' <span aria-hidden="true">&nearr;</span>'
          : '';

      $cards .=
          '<article class="mgdb-card projects-card' . (!empty($entry['maizegdb']) ? ' projects-card-ours' : '') . '"'
        . ' data-filter="' . mgdb_project_esc($category) . '"'
        . ' data-search="' . mgdb_project_esc($search) . '">'
        . '<h3>' . $mark_html . '<a href="' . mgdb_project_esc($project['url']) . '"' . $link_attrs . '>'
        . mgdb_project_esc($entry['title']) . $link_cue . '</a></h3>'
        /* The topic pills sit above the summary rather than below the facts
           strip. A card's paragraph is the one part of it that stretches to
           fill the card's height, so anything above the paragraph can be one
           row of pills on one card and two on the next without moving what
           follows, while anything below it carries that difference down into
           every band under it. With the pills up here the facts strip and the
           meta line land at the same height in every card of the row. */
        . '<div class="projects-card-tags">' . $tag_html . '</div>'
        . '<p>' . mgdb_project_esc($entry['card_summary']) . '</p>'
        . $facts_html
        . '<p class="mgdb-small mgdb-muted projects-card-meta">' . implode(' &middot; ', $meta) . '</p>'
        . '</article>';
  }

  $bauplan = new Bauplan('Projects | MaizeGDB');
  $bauplan->modern();

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  /* The shared Data Hub shell -- pale ground, white section cards, coloured
     section edges, the green Related resources panel -- before the page's own
     sheet, which is the order css/mgdb-hub.css documents. `mgdb-hub-page` on
     <main> opts in. This is not a data hub, but the shell is where the site's
     page furniture lives. */
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . (int) @filemtime(
      (isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
        ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html') . '/css/mgdb-hub.css'));
  $bauplan->includeCss('/css/mgdb-projects.css');
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-projects.js');
  $bauplan->head('<meta name="description" content="Maize research projects with data, results, or documentation at MaizeGDB: analyses run here, genome sequencing and assembly projects, and long-running community resources.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_projects.bau');

  $body->get('project-count')->replace(number_format(count($projects)));
  $body->get('topic-chips')->replace($chips);
  $body->get('project-cards')->replace($cards);

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
?>
