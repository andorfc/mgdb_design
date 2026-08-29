<?php
/* file: projects.php
 *
 * purpose: main controller for /projects — MaizeGDB analysis projects.
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

  $projects = mgdb_projects();
  $topics   = mgdb_project_topics();

  /* Chips are built from the topics the registry actually uses, so a chip can
     never be offered that matches nothing, and a project can never carry a
     topic with no chip. */
  $topic_counts = array();
  foreach ($projects as $entry) {
      foreach ($entry['topics'] as $topic) {
          $topic_counts[$topic] = isset($topic_counts[$topic]) ? $topic_counts[$topic] + 1 : 1;
      }
  }

  $chips = '<button class="mgdb-chip" type="button" data-filter="all" aria-pressed="true">All</button>';
  foreach ($topics as $topic => $label) {
      if (!isset($topic_counts[$topic])) { continue; }
      $chips .= '<button class="mgdb-chip" type="button" data-filter="' . mgdb_project_esc($topic) . '"'
              . ' aria-pressed="false">' . mgdb_project_esc($label) . '</button>';
  }

  $cards = '';
  foreach ($projects as $slug => $entry) {
      $project = mgdb_project($slug);

      /* Every topic label joins the searchable text, so typing "immunity"
         finds a project whose card copy never uses the word. */
      $topic_labels = array();
      foreach ($entry['topics'] as $topic) {
          $topic_labels[] = isset($topics[$topic]) ? $topics[$topic] : $topic;
      }
      $search = trim($entry['title'] . ' ' . $entry['card_summary'] . ' ' . implode(' ', $topic_labels));

      $tag_html = '';
      foreach ($entry['topics'] as $topic) {
          $tag_html .= '<span class="mgdb-pill mgdb-pill-info">'
                     . mgdb_project_esc(isset($topics[$topic]) ? $topics[$topic] : $topic)
                     . '</span>';
      }

      $facts_html = '';
      if (!empty($entry['card_facts'])) {
          $facts_html = '<dl class="projects-card-facts">';
          foreach ($entry['card_facts'] as $fact) {
              $facts_html .= '<div><dt>' . mgdb_project_esc($fact[1]) . '</dt>'
                           . '<dd>' . mgdb_project_esc($fact[0]) . '</dd></div>';
          }
          $facts_html .= '</dl>';
      }

      $updated = strtotime($entry['updated']);
      $updated_html = $updated
          ? '<time datetime="' . mgdb_project_esc($entry['updated']) . '">' . date('j F Y', $updated) . '</time>'
          : '<span class="mgdb-muted">Not reported</span>';

      $cards .=
          '<article class="mgdb-card projects-card"'
        . ' data-filter="' . mgdb_project_esc(implode(' ', $entry['topics'])) . '"'
        . ' data-search="' . mgdb_project_esc($search) . '">'
        . '<span class="mgdb-eyebrow">' . mgdb_project_esc($entry['eyebrow']) . '</span>'
        . '<h3><a href="' . mgdb_project_esc($project['url']) . '">' . mgdb_project_esc($entry['title']) . '</a></h3>'
        . '<p>' . mgdb_project_esc($entry['card_summary']) . '</p>'
        . $facts_html
        . '<div class="projects-card-tags">' . $tag_html . '</div>'
        . '<p class="mgdb-small mgdb-muted projects-card-meta">Updated ' . $updated_html
        . (!empty($entry['has_downloads']) ? ' &middot; data downloads available' : '')
        . '</p>'
        . '</article>';
  }

  $bauplan = new Bauplan('Analysis Projects | MaizeGDB');
  $bauplan->modern();

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-projects.css');
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-projects.js');
  $bauplan->head('<meta name="description" content="Self-contained maize data analyses at MaizeGDB: figures, tables, methods, and downloadable results for each project.">');

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
