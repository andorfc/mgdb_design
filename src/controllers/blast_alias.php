<?php
/* file: blast_alias.php   -- DEPLOYS AS controllers/blast.php
 *
 * purpose: case-normalising redirect. /blast -> /BLAST, the canonical BLAST
 *          route, preserving any sub-path and query string.
 *
 * WHY THE LOCAL FILENAME DIFFERS FROM THE DEPLOYED ONE
 * ----------------------------------------------------
 * On the server this file is controllers/blast.php, sitting beside the real
 * controllers/BLAST.php. Those two names differ only by case. The dev server's
 * filesystem is case-sensitive so they coexist there, but macOS (APFS) is
 * case-INSENSITIVE by default: a local src/controllers/blast.php IS
 * src/controllers/BLAST.php, and writing it silently overwrites the real BLAST
 * controller in the working tree. That happened once while this was being
 * written (2026-09-09) -- caught by `git status` showing BLAST.php modified from
 * 10206 bytes down to this file's size, and restored with `git checkout`.
 *
 * So the repo copy is named blast_alias.php and deploy/manifest.txt maps it to
 * controllers/blast.php. Do NOT rename this file to blast.php locally.
 *
 * WHY THE REDIRECT IS NEEDED
 * --------------------------
 * controller.php dispatches BLAST before it works out CONTROLLER at all:
 *
 *     if (strstr($request, '/BLAST')) { include('controllers/BLAST.php'); exit; }
 *
 * strstr() is case-sensitive, so a lowercase /blast missed that guard, found no
 * controllers/blast.php, fell through to redirect.php and -- since the
 * site-wide 404 landed on 2026-09-06 -- answered 404. Before that it answered
 * 200 with a copy of the homepage, which is why nobody had reported it and why
 * production (which has no not_found controller) still looks fine.
 *
 * templates/static/mgdb_genome_center.bau links the lowercase form, so the
 * Genome Center hub had a dead BLAST link. Ten project controllers
 * (NAM_project, whole_genome, european_flints, ...) also carry '/blast' as the
 * fallback for $system['BLAST_URL'], but conf/mgdb.conf sets BLAST_URL=/BLAST,
 * so that fallback is dormant wherever the key is set. This route covers it if
 * one ever is not.
 *
 * Added 2026-09-09 (Carson). Rollback: delete this file and its manifest line;
 * /blast 404s again.
 *
 * Scope: this takes the exactly-lowercase /blast only. A mixed-case /Blast
 * still 404s, because it matches neither the strstr guard nor this filename.
 * Nothing on the site emits that form; closing it would mean making the guard
 * in controller.php case-insensitive, which is a change to the front router.
 */

  $canonical = '/BLAST';
  $alias     = '/blast';

  /* Rewrite only the FIRST path segment, so a query string that happens to
     contain "/blast" is carried through untouched rather than rewritten. */
  $uri   = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : $alias;
  $split = strpos($uri, '?');
  $path  = ($split === false) ? $uri : substr($uri, 0, $split);
  $query = ($split === false) ? ''   : substr($uri, $split);

  if (strcasecmp(substr($path, 0, strlen($alias)), $alias) === 0) {
    /* Keeps /blast/blast_results_api.php and any other sub-path verbatim. */
    $dest = $canonical . substr($path, strlen($alias));
  } else {
    $dest = $canonical;
  }
  $dest .= $query;

  /* Apache rejects a request line containing a bare CR or LF, so this cannot
     currently be reached -- stripped anyway rather than trusting that, since
     the value goes straight into a response header. */
  $dest = str_replace(array("\r", "\n"), '', $dest);

  header('Location: ' . $dest, true, 301);
  exit;
?>
