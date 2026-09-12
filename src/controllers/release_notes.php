<?php
/* file: release_notes.php
 *
 * purpose: retired 2026-09-06 (Carson). /release_notes now redirects to
 *          /whatsnew, the News archive.
 *
 * The page rendered a directory of JIRA exports, one file per release, through
 * data/release_notes/release_notes.xsl. Two things retired it:
 *
 *   It stopped. There are 76 files in data/release_notes/ and the newest is
 *   release_notes_2021-09-02.xml -- five years of a "Release Notes (Updated
 *   ...)" heading that has said September 2021 the whole time.
 *
 *   What it showed was a ticket list. The stylesheet prints the last comment of
 *   each closed issue under "The following improvements or bug fixes were made
 *   in this release", so a typical entry reads "Various other minor bug fixes
 *   and improvements were made."
 *
 * /whatsnew is the successor and is maintained: 262 announcements across 24
 * years, current to 2026, searchable and filterable by year, and it covers
 * releases alongside new tools, meetings and community news.
 *
 * NOTHING LINKED TO IT. Checked every template and controller on the server and
 * in the repository: the only references were two entries in
 * data/redesign_status.json, which tracks pages rather than linking them. So
 * there was no unlinking to do, and nobody arrives here from the site.
 *
 * NO ALTERNATE ROUTE. /about/release_notes and /community/release_notes answer
 * 200, but they render the generic shell rather than this page -- there is no
 * controllers/static.php to dispatch controllers/static/release_notes.php, so
 * the top-level URL was the only one that worked. This file takes it, which
 * takes the page off the site.
 *
 * Nothing is deleted: controllers/static/release_notes.php, its template, its
 * script and all 76 XML files stay on disk.
 *
 * Rollback: this file is the whole route. Delete it and /release_notes serves
 * the page again.
 */

  header('Location: /whatsnew', true, 301);
  exit;
?>
