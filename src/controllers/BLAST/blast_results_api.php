<?php
/* file: blast_results_api.php
 *
 * purpose: JSON endpoint behind the BLAST results interface.
 *
 *          One BLAST run per target produces one `-outfmt 15` report; this
 *          endpoint serves slices of it. The four views (table, text, enhanced,
 *          discovery) are renderings of the same report, so switching view
 *          never re-runs a search.
 *
 *          Reached at /BLAST/blast_results_api.php?job=<sub_job_id>&view=<view>,
 *          alongside the legacy BLAST_tasks.php. Unlike that file this one is
 *          repo-owned, sends a real Content-Type, and parameterizes its SQL.
 *
 *          VIEWS
 *            summary    counts, best hit, deterministic interpretation
 *            hits       rows, paged; unit=locus|subject
 *            alignment  one HSP's strings + difference list  (&hit=&hsp=)
 *            enrich     annotation for a named set of rows   (&keys=)
 *            text       the classic pairwise report as text/plain
 *
 *          Progressive rendering is the point of the split: `summary` and the
 *          first page of `hits` come from the report alone and need no database
 *          at all, so the page paints before any annotation query runs.
 *
 * history
 *  09/03/26  claude  created
 */

  /* Paths are relative to the webroot, not to this file: controller.php
     includes this from there, so PHP resolves them against that cwd. The
     legacy BLAST files next door use '../../include/...' and appear to work
     only because controller.php has already loaded those same files, making
     their include_once a silent no-op. */
  include_once('./include/db-api.php');
  include_once('./include/gp_lib.php');
  include_once('./include/blast_results_lib.php');
  include_once('./include/blast_enrich_lib.php');
  include_once('./include/blast_text_view.php');

  $system = getSystemInfo('mgdb.conf');

/* A results page asks for one screenful at a time. 200 keeps the first payload
   small on a repetitive search without making a normal search paginate. */
define('MGDB_BLAST_PAGE', 200);

/* Refuse to parse a report larger than this. A genome-wide repetitive search
   can produce hundreds of megabytes, and json_decode builds the whole document
   in memory. The legacy poller's ceiling (MAX_XML_SIZE, 250 MB) is far past
   what PHP's default memory_limit can decode. */
define('MGDB_BLAST_MAX_REPORT', 64 * 1024 * 1024);


function blast_api_fail($status, $code, $detail) {
  http_response_code($status);
  header('Content-Type: application/problem+json; charset=utf-8');
  echo json_encode(array('status' => $status, 'code' => $code, 'detail' => $detail));
  exit;
}

function blast_api_send($payload, $max_age = 300) {
  $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
  $etag = '"' . md5($body) . '"';
  header('Content-Type: application/json; charset=utf-8');
  header('ETag: ' . $etag);
  /* A finished job's report never changes, so it is safely cacheable. The job id
     is unguessable and the results are the user's own, hence private. */
  header('Cache-Control: private, max-age=' . (int) $max_age);
  if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
  }
  echo $body;
  exit;
}

/**
 * What each sub-job of this job searched.
 *
 * blast_submit.php writes <job>.targets as JSON at submit time. Jobs launched
 * before that existed fall back to globbing the reports and peeking the
 * database name out of each, which loses the assembly but keeps the job
 * readable — an older job must not 404.
 */
function blast_api_manifest($job, $system) {
  $dir = rtrim($system['temp_dir'], '/');

  if (preg_match('/^[A-Za-z0-9]+$/', (string) $job)) {
    $path = $dir . '/' . $job . '.targets';
    if (is_readable($path)) {
      $rows = json_decode(file_get_contents($path), true);
      if (is_array($rows) && $rows) {
        $out = array();
        foreach ($rows as $r) {
          if (empty($r['sub_job'])) { continue; }
          $file = $dir . '/' . $r['sub_job'] . '.json';
          $err  = $dir . '/' . $r['sub_job'] . '.err';
          /* Three states, not two. BLAST opens its -out only after it has
             parsed its arguments and loaded the database -- measured at 0.5 s
             for one target and 1.75 s for another -- so for the first seconds
             of a job the file does not exist AT ALL; then it is zero bytes
             until the search finishes and the whole report is written. Missing
             and empty both mean RUNNING, and treating either as "no results"
             turned a half-finished job into a confident complete answer.
             Readiness is recorded here and decided in the dispatch. */
          $status = $dir . '/' . $r['sub_job'] . '.status';
          $r['path']  = $file;
          $r['size']  = is_readable($file) ? filesize($file) : 0;
          $r['ready'] = ($r['size'] > 0);
          /* Dead, not running. The search records its exit status when it
             stops, so an empty report WITH a status file means the process is
             gone and no amount of polling will change it. A process killed
             outright -- the OOM killer takes BLAST first, since it is the
             largest thing on the box -- writes nothing to stderr, so stderr
             alone cannot tell "died" from "still working". */
          $r['exit'] = is_readable($status)
                     ? (int) trim(file_get_contents($status)) : null;
          $r['failed'] = (!$r['ready'] &&
                          (($r['exit'] !== null) ||
                           (is_readable($err) && filesize($err) > 0)));
          $stderr = (is_readable($err) && filesize($err) > 0)
                  ? trim(file_get_contents($err)) : '';
          if (!$r['failed']) { $r['error'] = null; }
          else if ($stderr !== '') { $r['error'] = $stderr; }
          else if ($r['exit'] !== null && $r['exit'] > 128) {
            /* 128+n is the shell's encoding of "killed by signal n". 137 is
               SIGKILL, which on this host means the OOM killer. */
            $r['error'] = 'The search was stopped by the server before it '
                        . 'finished, most likely because it ran out of memory. '
                        . 'Searching fewer targets at once usually succeeds.';
          }
          else { $r['error'] = 'The search stopped without producing a report.'; }
          $r['type_short'] = blast_api_target_type_short(
            isset($r['target_type']) ? $r['target_type'] : '');
          $r['label'] = !empty($r['assembly']) ? $r['assembly'] : $r['db_name'];
          /* Assembly plus what was searched in it. Two targets on one assembly
             are otherwise the same word twice. */
          $r['label_full'] = $r['label'] . ($r['type_short'] !== '' ? ' ' . $r['type_short'] : '');
          $out[] = $r;
        }
        if ($out) { return $out; }
      }
    }
  }

  // Fallback: whatever reports are on disk.
  $out = array();
  $glob = (strpos((string) $job, '_') !== false)
    ? array($dir . '/' . $job . '.json')
    : glob($dir . '/' . $job . '_*.json');
  foreach ((array) $glob as $file) {
    if (!is_readable($file)) { continue; }
    $size = filesize($file);
    $db = $size > 0 ? blast_api_peek_db($file) : '';
    $sub = basename($file, '.json');
    $err = $dir . '/' . $sub . '.err';
    $out[] = array(
      'sub_job' => $sub,
      'assembly' => '', 'db_name' => $db, 'target_type' => '', 'name' => '',
      'path' => $file, 'size' => $size,
      'ready' => ($size > 0),
      'failed' => ($size === 0 && is_readable($err) && filesize($err) > 0),
      'error' => ($size === 0 && is_readable($err) && filesize($err) > 0)
                   ? trim(file_get_contents($err)) : null,
      'label' => $db !== '' ? $db : $sub,
      'label_full' => $db !== '' ? $db : $sub,
      'type_short' => '',
    );
  }
  usort($out, function ($a, $b) { return strcmp($a['sub_job'], $b['sub_job']); });
  return $out;
}

/**
 * A short name for what kind of sequence a target holds.
 *
 * Several targets commonly share one assembly — A188 alone offers Assembly,
 * Gene model CDS, Gene model genomic and Gene model protein — so an assembly
 * name on its own does not identify which was searched. The stored vocabulary
 * is inconsistent ("Gene model  protein" carries a double space, "All CDS"
 * appears alongside "Gene model CDS"), so it is normalized rather than trusted.
 */
function blast_api_target_type_short($type) {
  $t = trim(preg_replace('/\s+/', ' ', (string) $type));
  if ($t === '') { return ''; }

  // Haplotype suffixes are meaningful and are kept, shortened.
  $hap = '';
  if (preg_match('/-\s*haplotype\s*([ab])$/i', $t, $m)) {
    $hap = ' hap ' . strtolower($m[1]);
    $t = trim(preg_replace('/-\s*haplotype\s*[ab]$/i', '', $t));
  }

  $t = preg_replace('/^Gene model\s*/i', '', $t);
  $t = preg_replace('/^All\s+/i', '', $t);

  $map = array(
    'assembly'        => 'assembly',
    'draft assembly'  => 'draft assembly',
    'cds'             => 'CDS',
    'cdna'            => 'cDNA',
    'protein'         => 'protein',
    'genomic'         => 'genomic',
    'fgs genomic'     => 'FGS genomic',
    'transcript'      => 'transcript',
  );
  $key = strtolower($t);
  return (isset($map[$key]) ? $map[$key] : $t) . $hap;
}

/**
 * The database name from a report, without decoding the whole document.
 * A repetitive search can produce tens of megabytes and the target list must
 * not pay for parsing all of it.
 */
function blast_api_peek_db($path) {
  $fh = fopen($path, 'r');
  if (!$fh) { return ''; }
  $head = fread($fh, 4096);
  fclose($fh);
  if (preg_match('/"db"\s*:\s*"([^"]+)"/', $head, $m)) { return basename($m[1]); }
  return '';
}

/**
 * Which unit reads correctly for a model — see the single-target note. Subject
 * length is the signal: a gene model is kilobases, a chromosome is megabases.
 */
function blast_api_unit($model, $target = null) {
  /* The target's own type is the authority, and blast_submit.php already wrote
     it into <job>.targets. Use it whenever it is there. */
  if ($target && !empty($target['target_type'])) {
    return (stripos($target['target_type'], 'assembly') === 0) ? 'locus' : 'subject';
  }

  /* Fallback for jobs written before the manifest existed. MAXIMUM subject
     length, not the median: B73 v5 is 10 chromosomes plus ~675 scaffolds, so a
     query hitting more scaffolds than chromosomes gave a median of ~74 kb and
     flipped the whole job to subject rows — collapsing chromosome 1 into ONE
     row at "100% coverage, bit score 1,478,990" and hiding 1,648 distinct
     loci. One chromosome in the hit set is enough to need locus rows. */
  $max = 0;
  foreach ($model['subjects'] as $s) {
    if ($s['subject_len'] > $max) { $max = $s['subject_len']; }
  }
  return ($max > MGDB_BLAST_CHROMOSOME_LEN) ? 'locus' : 'subject';
}


/////
// Dispatch
/////

  $job  = getCGIParam('job', 'GP', false);
  $view = getCGIParam('view', 'GP', false);
  if (!$view) { $view = 'summary'; }
  $query_index = (int) getCGIParam('q', 'GP', false);

  if (!preg_match('/^[A-Za-z0-9]+(_[A-Za-z0-9]+)?$/', (string) $job)) {
    blast_api_fail(400, 'bad-job-id', 'Job identifier is not in the expected form.');
  }

  $manifest = blast_api_manifest($job, $system);

  if (!$manifest) {
    /* Nothing on disk at all — the submission has not yet written its reports.
       Still pending, not an error. */
    blast_api_send(array('status' => 'pending', 'job' => $job,
                         'targets' => 0, 'finished' => 0, 'failed' => 0), 0);
  }

  /* A request may name one target. Everything except `summary` and `hits` acts
     on exactly one report, because an alignment or a text report belongs to a
     single search. */
  $requested = getCGIParam('target', 'GP', false);
  $active = $manifest;
  if ($requested) {
    $active = array();
    foreach ($manifest as $t) { if ($t['sub_job'] === $requested) { $active[] = $t; } }
    if (!$active) { blast_api_fail(404, 'no-such-target', 'No such target in this job.'); }
  }

  /* Progress, before any parsing. A job is not an error while it is still
     running, and a job that is PART way through must never be served as if it
     were finished: a four-target submission reporting "3 of 3 targets" is a
     wrong answer, not a partial one. */
  $ready = array();
  $running = array();
  $failed = array();
  foreach ($active as $t) {
    if (!empty($t['ready'])) { $ready[] = $t; }
    else if (!empty($t['failed'])) { $failed[] = $t; }
    else { $running[] = $t; }
  }

  if (!$ready) {
    if ($failed && !$running) {
      blast_api_fail(422, 'job-failed',
        'This BLAST job did not complete. ' . $failed[0]['error']);
    }
    /* Still running. 200 with a progress body, never cached, so the client can
       poll rather than being handed an error on the page it was just
       redirected to. */
    blast_api_send(array(
      'status'   => 'pending',
      'job'      => $job,
      'targets'  => count($active),
      'finished' => 0,
      'failed'   => count($failed),
    ), 0);
  }

  $incomplete = (count($running) > 0);

  /* Only finished targets are parsed; the rest are reported as outstanding. */
  $active = $ready;

  /* json_decode builds the whole document in memory, and an aggregate view
     parses every target at once. Refuse rather than exhaust the worker. */
  $total_bytes = 0;
  foreach ($active as $t) { $total_bytes += $t['size']; }
  if ($total_bytes > MGDB_BLAST_MAX_REPORT) {
    blast_api_fail(413, 'report-too-large',
      'These results total ' . round($total_bytes / 1048576) . ' MB, past the ' .
      (MGDB_BLAST_MAX_REPORT / 1048576) . ' MB this view can parse at once. ' .
      'Open one target at a time with &target=, or repeat the search with a ' .
      'stricter e-value.');
  }


  /////
  // Views acting on a single report
  /////

  if ($view === 'alignment' || $view === 'text') {
    $one = $active[0];
    if ($view === 'alignment') {
      $hit = (int) getCGIParam('hit', 'GP', false);
      $hsp = (int) getCGIParam('hsp', 'GP', false);
      $aln = mgdb_blast_alignment($one['path'], $query_index, $hit, $hsp);
      if (!$aln) { blast_api_fail(404, 'no-such-hsp', 'No such alignment in this report.'); }
      $aln['target'] = $one['sub_job'];
      $aln['assembly'] = $one['assembly'];
      blast_api_send(array('status' => 'ok', 'alignment' => $aln), 3600);
    }

    /* The text report covers every target unless one was named: a download of a
       six-assembly job that silently contained one assembly would be the same
       defect this whole change exists to fix. */
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, max-age=300');
    $many_targets = count($active) > 1;
    /* Unless one query was named, the download covers every query as well as
       every target — a multi-FASTA report whose text file quietly held one of
       two sequences would be the same defect this endpoint exists to avoid. */
    $queries = mgdb_blast_queries($active[0]['path']);
    $q_list = (getCGIParam('q', 'GP', false) !== false && $query_index >= 0 &&
               getCGIParam('q', 'GP', false) !== '')
      ? array($query_index)
      : array_map(function ($q) { return $q['index']; }, $queries);
    if (!$q_list) { $q_list = array(0); }
    $many_queries = count($q_list) > 1;

    $first = true;
    foreach ($q_list as $qi) {
      foreach ($active as $i => $t) {
        $report = mgdb_blast_read($t['path'], $qi);
        if (!$report) { continue; }
        if ($many_targets || $many_queries) {
          if (!$first) { echo "\n\n"; }
          $heading = array();
          if ($many_queries) {
            $heading[] = 'Query ' . ($qi + 1) . ' of ' . count($q_list) . ': ' .
                         (isset($queries[$qi]['title']) ? $queries[$qi]['title'] : '');
          }
          if ($many_targets) {
            $full = !empty($t['label_full']) ? $t['label_full'] : $t['label'];
            $heading[] = 'Target ' . ($i + 1) . ' of ' . count($active) . ': ' .
                         ($full !== '' ? $full : $t['sub_job']);
          }
          echo str_repeat('=', 78) . "\n";
          foreach ($heading as $line) { echo $line . "\n"; }
          echo str_repeat('=', 78) . "\n\n";
        }
        $first = false;
        /* The report is already decoded here — hand it over rather than making
           the renderer read it again per HSP. */
        echo mgdb_blast_render_text(mgdb_blast_model($report), $t['path'], $qi, $report);
      }
    }
    exit;
  }


  /////
  // Aggregate views
  /////

  $models = array();
  foreach ($active as $t) {
    $report = mgdb_blast_read($t['path'], $query_index);
    if (!$report) { continue; }
    $models[] = array('target' => $t, 'model' => mgdb_blast_model($report));
  }
  if (!$models) {
    blast_api_fail(422, 'unreadable-report',
      'No result file for this job could be parsed as BLAST JSON output.');
  }

  $multi = count($models) > 1;

  if ($view === 'summary') {
    $counts = array('subjects' => 0, 'hsps' => 0, 'loci' => 0);
    $best = null;
    $per_target = array();

    foreach ($models as $entry) {
      $m = $entry['model'];
      $t = $entry['target'];
      $counts['subjects'] += $m['n_subjects'];
      $counts['hsps']     += $m['n_hsps'];
      $counts['loci']     += $m['n_loci'];

      $top = null;
      foreach ($m['subjects'] as $s) {
        foreach ($s['loci'] as $L) {
          $L['subject'] = $s['id'];
          $L['subject_len'] = $s['subject_len'];
          $L['target'] = $t['sub_job'];
          $L['assembly'] = $t['assembly'];
          $L['label'] = $t['label'];
          if ($top === null || $L['bit_score_total'] > $top['bit_score_total']) { $top = $L; }
          if ($best === null || $L['bit_score_total'] > $best['bit_score_total']) { $best = $L; }
        }
      }
      $per_target[] = array(
        'sub_job'     => $t['sub_job'],
        'label'       => $t['label'],
        'label_full'  => isset($t['label_full']) ? $t['label_full'] : $t['label'],
        'type_short'  => isset($t['type_short']) ? $t['type_short'] : '',
        'assembly'    => $t['assembly'],
        'db'          => $t['db_name'],
        'target_type' => $t['target_type'],
        'subjects'    => $m['n_subjects'],
        'hsps'        => $m['n_hsps'],
        'loci'        => $m['n_loci'],
        'best'        => $top,
      );
    }

    $first = $models[0]['model'];
    /* Name the sequence type in the reading only when it is load-bearing —
       that is, when two searched targets share an assembly and the assembly
       name alone would not say which one was strongest. */
    $seen = array();
    $ambiguous = false;
    foreach ($models as $e) {
      $l = $e['target']['label'];
      if (isset($seen[$l])) { $ambiguous = true; }
      $seen[$l] = true;
    }
    $interpretation = $multi
      ? mgdb_blast_interpret_multi(array_map(function ($e) use ($ambiguous) {
          return array(
            'label' => $ambiguous && !empty($e['target']['label_full'])
                         ? $e['target']['label_full'] : $e['target']['label'],
            'model' => $e['model'],
          );
        }, $models))
      : mgdb_blast_interpret($first);

    blast_api_send(array(
      'status'  => 'ok',
      'job'     => $job,
      'multi'   => $multi,
      'program' => $first['program'],
      'version' => $first['version'],
      'db'      => $multi ? (count($models) . ' targets') : $first['db'],
      'params'  => $first['params'],
      'query'   => $first['query'],
      'counts'  => $counts,
      'best'    => $best,
      'interpretation' => $interpretation,
      'targets' => $per_target,
      /* Multi-FASTA: every target's report carries the same query list, so the
         first is representative. The page needs this to offer a selector;
         without it a second query is invisible. */
      'query_index' => $query_index,
      'queries' => mgdb_blast_queries($active[0]['path']),
      /* What the reader is looking at, and what they are not. A page that
         cannot say "2 of 6 targets are still running" will be read as complete. */
      'complete' => !$incomplete,
      'running'  => array_map(function ($t) { return $t['label']; }, $running),
      'failed'   => array_map(function ($t) {
                      return array('label' => $t['label'], 'error' => $t['error']);
                    }, $failed),
    /* A partial result must not be cached for an hour. */
    ), $incomplete ? 0 : 3600);
  }

  if ($view === 'hits') {
    $offset = max(0, (int) getCGIParam('offset', 'GP', false));
    $limit  = (int) getCGIParam('limit', 'GP', false);
    if ($limit <= 0 || $limit > 2000) { $limit = MGDB_BLAST_PAGE; }

    $unit = getCGIParam('unit', 'GP', false);
    if ($unit !== 'subject' && $unit !== 'locus') {
      /* One unit for the merged table. Decide it from the targets, not from one
         of them: a job mixing an assembly database with gene models reads as
         loci, since a chromosome row is meaningless either way. */
      $unit = 'subject';
      foreach ($models as $entry) {
        if (blast_api_unit($entry['model'], $entry['target']) === 'locus') {
          $unit = 'locus';
          break;
        }
      }
    }

    $rows = array();
    foreach ($models as $ti => $entry) {
      $m = $entry['model'];
      $t = $entry['target'];
      /* Keys must be unique across targets or selection in one assembly would
         highlight a row in another. */
      $prefix = 't' . $ti;

      foreach ($m['subjects'] as $s) {
        $common = array(
          'target'      => $t['sub_job'],
          'assembly'    => $t['assembly'],
          'target_label' => $t['label'],
          /* Per-target constants stop here. The raw target_type is NOT repeated
             on every row: it is a per-target fact, already sent once in the
             summary's target list, and duplicating it cost 4.7% of a full page
             of rows to say the same string 200 times. */
          'target_type_short' => isset($t['type_short']) ? $t['type_short'] : '',
          'hit'         => $s['num'],
          'subject'     => $s['id'],
          'title'       => $s['title'],
          'subject_len' => $s['subject_len'],
        );

        if ($unit === 'subject') {
          $rows[] = array_merge($common, array(
            'key'         => $prefix . 's' . $s['num'],
            'q_start'     => $s['q_start'],
            'q_end'       => $s['q_end'],
            'q_intervals' => $s['q_intervals'],
            'h_intervals' => $s['h_intervals'],
            'q_coverage'  => $s['q_coverage'],
            'pident'      => $s['pident'],
            'pident_weighted' => $s['pident_weighted'],
            'align_len'   => $s['align_len'],
            'mismatches'  => $s['mismatches'],
            'gaps'        => $s['gaps'],
            'evalue'      => $s['evalue'],
            'bit_score'   => $s['bit_score'],
            'bit_score_total' => $s['bit_score_total'],
            'n_hsps'      => $s['n_hsps'],
            'orientation' => $s['orientation'],
            'best_hsp'    => $s['best_hsp'],
          ));
        } else {
          foreach ($s['loci'] as $li => $L) {
            $rows[] = array_merge($common, array(
              'key'         => $prefix . 's' . $s['num'] . 'l' . $li,
              'h_start'     => $L['h_start'],
              'h_end'       => $L['h_end'],
              'q_start'     => $L['q_start'],
              'q_end'       => $L['q_end'],
              'q_intervals' => $L['q_intervals'],
              'h_intervals' => $L['h_intervals'],
              'q_coverage'  => $L['q_coverage'],
              'pident'      => $L['pident'],
              'pident_weighted' => $L['pident_weighted'],
              'align_len'   => $L['align_len'],
              'mismatches'  => $L['mismatches'],
              'gaps'        => $L['gaps'],
              'evalue'      => $L['evalue'],
              'bit_score'   => $L['bit_score'],
              'bit_score_total' => $L['bit_score_total'],
              'n_hsps'      => $L['n_hsps'],
              'orientation' => $L['orientation'],
              'best_hsp'    => $L['best_hsp'],
            ));
          }
        }
      }
    }

    usort($rows, function ($a, $b) {
      $x = $a['bit_score_total']; $y = $b['bit_score_total'];
      if ($x == $y) { return 0; }
      return ($x < $y) ? 1 : -1;
    });

    /* The browser base for every assembly in this job, so the client can build
       a "See on the Genome Browser" link from a row's own h_intervals without a
       round trip per row. One query, cached for the request; only the JBrowse 1
       entries are sent, because only JBrowse 1 takes a custom track. */
    $browsers = array();
    $browser_map = mgdb_blast_browser_urls(connect_to_database());
    foreach ($models as $m) {
      /* $models entries are array('target' => …, 'model' => …); the assembly is
         on the target, which is the row from the job's .targets manifest. */
      $asm = isset($m['target']['assembly']) ? $m['target']['assembly'] : null;
      if ($asm && isset($browser_map[$asm]) && !isset($browsers[$asm])
          && strpos($browser_map[$asm], 'jbrowse.maizegdb.org') !== false) {
        $browsers[$asm] = $browser_map[$asm];
      }
    }

    blast_api_send(array(
      'status'    => 'ok',
      'multi'     => $multi,
      'unit'      => $unit,
      'total'     => count($rows),
      'offset'    => $offset,
      'limit'     => $limit,
      'query_len' => $models[0]['model']['query']['len'],
      'targets'   => count($models),
      'rows'      => array_slice($rows, $offset, $limit),
      'browsers'  => $browsers,
      'complete'  => !$incomplete,
    ), $incomplete ? 0 : 3600);
  }

  if ($view === 'enrich') {
    $DBConn = connect_to_database();

    $raw = getCGIParam('rows', 'GP', false);
    $wanted = $raw ? json_decode($raw, true) : array();
    if (!is_array($wanted) || !$wanted) {
      blast_api_fail(400, 'no-rows', 'No rows were named for enrichment.');
    }
    if (count($wanted) > 500) { $wanted = array_slice($wanted, 0, 500); }

    /* A multi-assembly job has a different assembly per row, so genomic rows are
       bucketed by assembly and each bucket costs ONE batched query. A fallback
       assembly may still be passed for jobs whose manifest predates .targets. */
    $fallback = getCGIParam('assembly', 'GP', false);
    $genes = array();
    $loci_by_assembly = array();

    foreach ($wanted as $w) {
      if (empty($w['key'])) { continue; }
      if (isset($w['chr'], $w['start'], $w['end'])) {
        $asm = !empty($w['assembly']) ? $w['assembly'] : $fallback;
        if (!$asm) { continue; }
        $loci_by_assembly[$asm][] = array(
          'key' => $w['key'], 'chr' => $w['chr'],
          'start' => (int) $w['start'], 'end' => (int) $w['end'],
        );
      } else if (isset($w['subject'])) {
        /* Carry the row's own assembly: a multi-target job resolves each
           subject against the build it was actually found in. */
        $genes[$w['key']] = array(
          'id'       => $w['subject'],
          'assembly' => !empty($w['assembly']) ? $w['assembly'] : (string) $fallback,
        );
      }
    }

    $annotations = array();

    if ($genes) {
      $resolved = mgdb_blast_enrich_gene_models(array_values($genes), $DBConn);
      foreach ($genes as $key => $item) {
        $id = $item['id'];
        if (isset($resolved[$id])) { $annotations[$key] = $resolved[$id]; }
      }
    }

    foreach ($loci_by_assembly as $asm => $loci) {
      $resolved = mgdb_blast_enrich_loci($loci, $asm, $DBConn);
      foreach ($resolved as $key => $hits) {
        $annotations[$key] = $hits[0];
        if (count($hits) > 1) { $annotations[$key]['also'] = array_slice($hits, 1); }
      }
    }

    $pan_names = array();
    foreach ($annotations as $a) {
      if (!empty($a['pan_gene'])) { $pan_names[] = $a['pan_gene']; }
    }
    $breadth = $pan_names ? mgdb_blast_pangene_breadth($pan_names, $DBConn) : array();

    blast_api_send(array(
      'status'      => 'ok',
      'annotations' => $annotations,
      'pan_genes'   => $breadth,
    ), 3600);
  }

  if ($view === 'domains') {
    /* Pfam domains of the best-matching PROTEIN, projected onto the query axis.
       Only meaningful where the subject is a protein — blastp and blastx. For
       blastn or tblastn the subject is nucleotide and the domain coordinates,
       which are amino-acid positions on a protein, have nothing to attach to;
       returning them anyway would put a domain at a position derived from
       nothing. */
    $program = $models[0]['model']['program'];
    if ($program !== 'blastp' && $program !== 'blastx') {
      blast_api_send(array(
        'status' => 'ok', 'applicable' => false,
        'reason' => 'Domains are shown for protein subjects; this was a ' . $program . ' search.',
        'domains' => array(), 'source' => null,
      ), 3600);
    }

    $DBConn = connect_to_database();

    /* Walk the strongest subjects until one has domains. The best hit usually
       does; falling through a few keeps the overlay useful when it does not,
       and the source is named so the reader knows whose architecture is drawn. */
    $candidates = array();
    foreach ($models as $entry) {
      foreach ($entry['model']['subjects'] as $sub) {
        $candidates[] = array('target' => $entry['target'], 'subject' => $sub);
      }
    }
    usort($candidates, function ($a, $b) {
      $x = $a['subject']['bit_score_total']; $y = $b['subject']['bit_score_total'];
      if ($x == $y) { return 0; }
      return ($x < $y) ? 1 : -1;
    });
    $candidates = array_slice($candidates, 0, 5);

    $ids = array();
    foreach ($candidates as $c) { $ids[] = $c['subject']['id']; }
    $by_id = $ids ? mgdb_blast_enrich_domains($ids, $DBConn) : array();

    $result = array('status' => 'ok', 'applicable' => true,
                    'domains' => array(), 'source' => null);

    foreach ($candidates as $c) {
      $id = $c['subject']['id'];
      if (empty($by_id[$id])) { continue; }

      $hsp = mgdb_blast_alignment($c['target']['path'], $query_index,
                                  $c['subject']['num'], $c['subject']['best_hsp']);
      if (!$hsp) { continue; }

      $projected = mgdb_blast_project_domains($hsp, $by_id[$id]);
      if (!$projected) { continue; }

      $result['domains'] = $projected;
      $result['source'] = array(
        'subject'    => $id,
        'gene_model' => mgdb_blast_gene_id($id),
        'transcript' => mgdb_blast_transcript_id($id),
        'assembly'   => $c['target']['assembly'],
        'pident'     => $c['subject']['pident'],
        'q_coverage' => $c['subject']['q_coverage'],
      );
      break;
    }

    blast_api_send($result, 3600);
  }

  if ($view === 'neighborhood') {
    /* Genomic context for ONE match, fetched when its drawer tab is opened.
       Needs an assembly and genomic coordinates, so it applies to a genomic
       search or to a gene-model hit whose gene has been resolved; the caller
       supplies whichever it has. */
    $assembly = getCGIParam('assembly', 'GP', false);
    $chr      = getCGIParam('chr', 'GP', false);
    $start    = (int) getCGIParam('start', 'GP', false);
    $end      = (int) getCGIParam('end', 'GP', false);

    if (!$assembly || !$chr || !$start || !$end) {
      blast_api_fail(400, 'missing-interval',
        'An assembly, chromosome, start and end are required.');
    }

    $DBConn = connect_to_database();
    $hood = mgdb_blast_neighborhood($assembly, $chr, $start, $end, $DBConn);
    if (!$hood) { blast_api_fail(422, 'bad-interval', 'That interval could not be resolved.'); }

    /* The JBrowse 1 base for this assembly, so the drawer can offer the region
       with the reader's own hits drawn on it. Null for a GBrowse assembly. */
    $browser_map = mgdb_blast_browser_urls($DBConn);
    $hood['browser_base'] =
      (isset($browser_map[$assembly])
        && strpos($browser_map[$assembly], 'jbrowse.maizegdb.org') !== false)
      ? $browser_map[$assembly] : null;

    blast_api_send(array_merge(array('status' => 'ok'), $hood), 3600);
  }

  blast_api_fail(400, 'unknown-view', 'Unknown view: ' . htmlspecialchars((string) $view));
