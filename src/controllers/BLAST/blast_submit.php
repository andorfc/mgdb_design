<?php
/* file: blast_submit.php
 *
 * purpose: launch a BLAST job and hand the browser to the results interface.
 *
 *          This replaces BLAST_run.php's submit branch only. The legacy file
 *          stays in place and still serves `?job_id=` for reports written
 *          before this existed, so nothing already bookmarked changes.
 *
 *          THREE THINGS DIFFER FROM THE LEGACY RUNNER
 *
 *          1. One format, not four. The search runs once with `-outfmt 15`
 *             (single-file BLAST JSON) and the table, text, enhanced and
 *             discovery views are rendered from that one report. Measured on
 *             this host, JSON costs ~60 ms against a ~0.9 s search — 6% — so
 *             running the search once per format would nearly quadruple job
 *             time to produce fields the JSON already carries. `outfmt 15` is a
 *             strict superset of 6, 0 and 5, including the qseq/hseq/midline
 *             strings the alignment viewer and difference strip need.
 *
 *             (The usual trick — `-outfmt 11` once, then `blast_formatter` per
 *             format — is unavailable: blast_formatter is not installed here.)
 *
 *          2. The command is escaped. The legacy runner interpolates the whole
 *             command into a single-quoted shell argument, and the tabular
 *             format string contains single quotes of its own, so the argument
 *             terminates early: the launcher receives 15 arguments instead of
 *             2, the requested column list is discarded, and `-num_threads 4`
 *             is silently dropped (AD-064). escapeshellarg() closes that.
 *
 *          3. It redirects. The job's reports are addressed by job id, so the
 *             browser is sent to /BLAST?job_id=... rather than being handed a
 *             page that only works for the submission that created it. That is
 *             what makes a results URL shareable and re-openable.
 *
 * history
 *  09/03/26  claude  created
 */

  include_once('./include/db-api.php');
  include_once('./include/gp_lib.php');
  include_once('./controllers/BLAST/BLAST_lib.php');

  $system = getSystemInfo('mgdb.conf');
  $DBConn = connect_to_database();

/**
 * Which BLAST program pairs this query type with this target type.
 */
function blast_submit_program($target_type, $query_type) {
  if ($query_type == 'nucleotide') {
    return ($target_type == 'nucleotide') ? 'blastn' : 'blastx';
  }
  return ($target_type == 'nucleotide') ? 'tblastn' : 'blastp';
}

/**
 * A word size the chosen program will accept.
 *
 * The protein programs reject the nucleotide default outright, so the form's
 * single word-size control has to be adapted rather than passed through.
 */
function blast_submit_word_size($word_size, $program) {
  $word_size = (int) $word_size;
  if (($program === 'blastx' || $program === 'blastp') && $word_size > 6) { return 3; }
  if ($program === 'tblastn' && $word_size > 8) { return 7; }
  return $word_size > 0 ? $word_size : 11;
}


  /////
  // Collect the submission
  /////

  $job_id = getUniqueID(12);

  $cached = getCGIParam('BLAST_jobs', 'S', false);
  setSessionVar('BLAST_jobs', $cached ? "$job_id,$cached" : $job_id);

  $temp = rtrim($system['temp_dir'], '/');
  $seq = getCGIParam('query_sequence', 'P', false);

  $fas_file = "$temp/$job_id.fa";
  if (@file_put_contents($fas_file, $seq) === false) {
    /* The same failure AD-048 chased for weeks. Say what actually happened
       rather than rendering a generic error page. */
    $err = error_get_last();
    logVarDump($err, "BLAST could not write $fas_file:\n");
    header('Location: /BLAST?error=write-failed');
    exit;
  }

  $query_seq_type  = getCGIParam('query_seq_type', 'P', false);
  $max_evalue      = getCGIParam('BLAST_max_evalue', 'P', false);
  $word_size       = getCGIParam('BLAST_word_size', 'P', false);
  $max_hsps        = getCGIParam('BLAST_max_hsps', 'P', false);
  $max_hits        = getCGIParam('BLAST_max_hits', 'P', false);
  $perc_identity   = getCGIParam('BLAST_perc_identity', 'P', false);
  $targets_raw     = getCGIParam('targets', 'P', false);
  $output_format   = getCGIParam('output_format', 'P', false);

  if (!$max_evalue) { $max_evalue = '1e-10'; }

  $target_ids = array_filter(array_map('trim', explode(',', (string) $targets_raw)));
  if (!$target_ids) {
    header('Location: /BLAST?error=no-target');
    exit;
  }

  /* The parameters file lets a reopened job show the settings that produced it,
     and lets "modify search" prefill the form. */
  $parms = array(
    'saved_job_id' => $job_id,
    'query_seq_type' => $query_seq_type,
    'BLAST_max_evalue' => $max_evalue,
    'BLAST_word_size' => $word_size,
    'BLAST_max_hsps' => $max_hsps,
    'BLAST_max_hits' => $max_hits,
    'BLAST_perc_identity' => $perc_identity,
    'output_format' => $output_format,
    'targets' => $targets_raw,
  );
  $lines = '';
  foreach ($parms as $k => $v) { $lines .= $k . "\t" . preg_replace('/\s/', '', (string) $v) . "\n"; }
  @file_put_contents("$temp/$job_id.parms", $lines);

  resetUID();
  $uid = getUID();

  /////
  // Launch one search per target
  /////

  /* One entry per target, written beside the reports as <job>.targets. The
     results interface needs to know, per sub-job, which assembly and database
     it searched — a multi-assembly job has a different answer for each, and a
     single `.assembly` file cannot carry that. */
  $manifest = array();

  foreach ($target_ids as $blast_id) {
    $info = getBLASTrecord((int) $blast_id, $DBConn);
    if (!$info) { continue; }

    $sub_job_id = $job_id . '_' . getUniqueID(5);
    $program = blast_submit_program($info['sequence_type'], $query_seq_type);
    $ws = blast_submit_word_size($word_size, $program);

    $db = rtrim($system['BLAST_dbs'], '/') . '/' .
          trim($info['db_path'], '/') . '/' . $info['db_name'];
    $db = preg_replace('#/+#', '/', $db);
    $out = "$temp/$sub_job_id.json";

    $manifest[] = array(
      'sub_job'     => $sub_job_id,
      'blast_id'    => (int) $blast_id,
      'assembly'    => isset($info['assembly_name']) ? $info['assembly_name'] : '',
      'db_name'     => $info['db_name'],
      'target_type' => isset($info['target_type']) ? $info['target_type'] : '',
      'name'        => isset($info['name']) ? $info['name'] : '',
      'program'     => $program,
      'seq_type'    => $info['sequence_type'],
    );

    /* Built as a list and escaped as a whole. Every value that comes from the
       request has already been cast or is escaped here; nothing reaches the
       shell unquoted. */
    $args = array(
      $program,
      '-query', $fas_file,
      '-db', $db,
      '-out', $out,
      '-evalue', (string) $max_evalue,
      '-word_size', (string) $ws,
      '-outfmt', '15',
      '-num_threads', '4',
    );
    if ($max_hsps !== false && $max_hsps !== '' && (int) $max_hsps > 0) {
      $args[] = '-max_hsps'; $args[] = (string) (int) $max_hsps;
    }
    if ($max_hits !== false && $max_hits !== '' && (int) $max_hits > 0) {
      $args[] = '-max_target_seqs'; $args[] = (string) (int) $max_hits;
    }
    /* -perc_identity exists only for blastn. The legacy runner emits
       `-perc_identify` (AD-051), which would abort every job it reached. */
    if ($program === 'blastn' && $perc_identity !== false && $perc_identity !== '' &&
        (float) $perc_identity > 0) {
      $args[] = '-perc_identity'; $args[] = (string) (float) $perc_identity;
    }

    $cmd = implode(' ', array_map('escapeshellarg', $args));

    createJobRecord($uid, $sub_job_id, date('d-M-Y G:i'), $info['db_name'],
                    "program=$program, word_size=$ws, outfmt=15", $DBConn);

    /* Detached, so the submission returns immediately and the results page
       polls. stderr is kept: it is the only account of why a job produced
       nothing, and discarding it is what made AD-050 so hard to see.
       The exit status is recorded too, because stderr does NOT cover every
       way a search can die: a process killed by the OOM killer writes nothing
       at all, leaving an empty report and an empty .err -- exactly what a
       still-running search looks like -- so the page polled a dead job for
       ever. A .status file makes finishing explicit rather than inferred:
       absent means running, present and non-zero means dead. */
    $launch = '( ' . $cmd . ' > /dev/null 2> ' . escapeshellarg("$temp/$sub_job_id.err")
            . '; echo $? > ' . escapeshellarg("$temp/$sub_job_id.status") . ' ) &';
    logMessage("BLAST launch:\n$launch");
    exec($launch);
  }

  if (!$manifest) {
    header('Location: /BLAST?error=no-target');
    exit;
  }

  /* What each sub-job searched, so the results interface can turn genomic
     coordinates into genes, label rows by assembly, and group across targets
     without re-querying pc_blast_ctl on every view. */
  @file_put_contents("$temp/$job_id.targets", json_encode($manifest));

  /* Hand over to the results interface. A redirect rather than a render, so the
     URL in the address bar is the one that can be shared and reopened. */
  header('Location: /BLAST?job_id=' . rawurlencode($job_id), true, 303);
  exit;
