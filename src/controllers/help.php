<?php
/* file: help.php
 *
 * purpose: retired 2026-09-09 (Carson). /help now redirects to /contact.
 *
 * The legacy popup help system. js/api_js.js's popUpHelp(section, anchor)
 * opened /help/<section>#<anchor> in a 440x200 window; controller.php routed
 * that to this file, which loaded templates/help/help-main.bau and, inside it,
 * templates/help/<section>-help.bau.
 *
 * Two things ended it. Only one section template was ever written --
 * genome_issue-help.bau -- and its five entries now sit inline under the
 * legends of the modern report form, carried over verbatim when
 * controllers/curation/genome_issue_modern.php was built. And the route had
 * been fatal since the PHP 8 upgrade: line 25 logged the ANCHOR constant, which
 * is only defined when the URL carries an id, so every request without one --
 * the bare /help among them -- died on `Undefined constant "ANCHOR"`.
 *
 * That form was the only caller of popUpHelp() still served, so nothing on the
 * site reaches this route: no href, no redirect into it, and no site map entry.
 * templates/curation/genome-issue-form.bau still calls popUpHelp() five times,
 * but it is the archived legacy form, replaced by mgdb_genome_issue.bau.
 *
 * /contact rather than a help page because MaizeGDB has no help landing to
 * point at, and it is where /faq was sent on 2026-09-04 for the same reason:
 * a reader who wanted an answer gets a way to ask instead.
 *
 * The original controller and templates/help/ are at
 * /var/www/claude/retired/2026-09-09-help/.
 *
 * Rollback: this file is the whole route. Restore the two paths named in that
 * directory's README and delete this file.
 */

  header('Location: /contact', true, 301);
  exit;
?>
