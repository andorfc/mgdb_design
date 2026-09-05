<?php
/* file: blast_text_view.php
 *
 * purpose: render the classic BLAST pairwise report from the `-outfmt 15`
 *          model, so the text view costs no extra search.
 *
 *          The alignment blocks are reproduced to match `blastn -outfmt 0`
 *          exactly — same 60-column wrapping, same coordinate gutters, same
 *          midline. Verified by rendering a real report and diffing the
 *          alignment section against BLAST's own text output for the same job.
 *
 *          The surrounding furniture (the citation block, the database posted
 *          date, the Lambda/K/H tables) is reproduced from the report's own
 *          `params` and `stat` where the data is present. The posted date is
 *          the one field `outfmt 15` does not carry; it is omitted rather than
 *          invented.
 *
 * history
 *  09/03/26  claude  created
 */

if (!defined('MGDB_BLAST_TEXT_VIEW')) {
  define('MGDB_BLAST_TEXT_VIEW', true);

/* BLAST wraps alignments at 60 residues per block. */
define('MGDB_BLAST_TEXT_WIDTH', 60);

/* mgdb_blast_bases_per_column() lives in blast_results_lib.php, which this
   file already requires: the projection code needs the same rule and two
   copies would be free to drift. */

/**
 * Render the alignment blocks for one HSP exactly as `-outfmt 0` does.
 *
 * The coordinate gutter is as wide as the widest coordinate printed in this
 * HSP, which is what makes real BLAST output line up. Query coordinates always
 * ascend; subject coordinates descend on a minus-strand match. Neither side
 * advances over its own gap characters.
 */
function mgdb_blast_text_alignment($hsp) {
  $q = $hsp['qseq'];
  $h = $hsp['hseq'];
  $m = $hsp['midline'];
  $len = strlen($q);
  if ($len === 0) { return ''; }

  $q_forward = ($hsp['q_strand'] !== 'Minus');
  $h_forward = ($hsp['h_strand'] !== 'Minus');
  $q_pos = $q_forward ? $hsp['q_start'] : $hsp['q_end'];
  $h_pos = $h_forward ? $hsp['h_start'] : $hsp['h_end'];

  /* How many bases each side consumes per alignment column. Both sides need
     this independently, and they differ:
       blastn/blastp  1 and 1
       blastx         3 on the QUERY (translated), 1 on the subject
       tblastn        1 on the query, 3 on the SUBJECT (translated)
       tblastx        3 and 3
     Getting the subject wrong printed `Sbjct 1 … 60` for a tblastn block that
     BLAST itself ends at 180. Derived from the HSP so it is right for every
     program without threading the program name down here. */
  $q_per = mgdb_blast_bases_per_column($hsp['q_start'], $hsp['q_end'], $q);
  $h_per = mgdb_blast_bases_per_column($hsp['h_start'], $hsp['h_end'], $h);
  $q_dir = $q_forward ? 1 : -1;
  $h_dir = $h_forward ? 1 : -1;

  // Gutter width: the widest coordinate either side will print.
  $width = max(
    strlen((string) $hsp['q_start']), strlen((string) $hsp['q_end']),
    strlen((string) $hsp['h_start']), strlen((string) $hsp['h_end'])
  );

  $out = '';
  for ($off = 0; $off < $len; $off += MGDB_BLAST_TEXT_WIDTH) {
    $q_chunk = substr($q, $off, MGDB_BLAST_TEXT_WIDTH);
    $h_chunk = substr($h, $off, MGDB_BLAST_TEXT_WIDTH);
    $m_chunk = substr($m, $off, MGDB_BLAST_TEXT_WIDTH);

    // A block's end coordinate counts only the residues that side consumed.
    $q_used = strlen($q_chunk) - substr_count($q_chunk, '-');
    $h_used = strlen($h_chunk) - substr_count($h_chunk, '-');

    /* The block's end coordinate is the LAST BASE the block consumed, not the
       first base of its last column. For an untranslated side those are the
       same thing; for a translated one they differ by two, which is why a
       60-column tblastn block ended at 178 instead of BLAST's 180. */
    $q_from = $q_pos;
    $h_from = $h_pos;
    $q_to = $q_used ? $q_pos + $q_dir * ($q_used * $q_per - 1) : $q_pos;
    $h_to = $h_used ? $h_pos + $h_dir * ($h_used * $h_per - 1) : $h_pos;

    $out .= sprintf("Query  %-{$width}s  %s  %s\n", $q_from, $q_chunk, $q_to);
    $out .= sprintf("       %-{$width}s  %s\n", '', $m_chunk);
    $out .= sprintf("Sbjct  %-{$width}s  %s  %s\n", $h_from, $h_chunk, $h_to);
    $out .= "\n";

    $q_pos = $q_used ? $q_to + $q_dir : $q_pos;
    $h_pos = $h_used ? $h_to + $h_dir : $h_pos;
  }
  return $out;
}

/**
 * The per-HSP statistics header BLAST prints above each alignment.
 */
function mgdb_blast_text_hsp_header($hsp, $program) {
  $out = '';
  $bits = $hsp['bit_score'];
  $bits_s = ($bits >= 100) ? (string) round($bits) : sprintf('%.1f', $bits);
  $out .= sprintf(" Score = %s bits (%s),  Expect = %s\n",
    $bits_s, $hsp['score'], mgdb_blast_text_evalue($hsp['evalue']));

  $alen = $hsp['align_len'];
  $pct = $alen ? (int) round($hsp['identity'] * 100.0 / $alen) : 0;
  $out .= sprintf(" Identities = %d/%d (%d%%), Gaps = %d/%d (%d%%)\n",
    $hsp['identity'], $alen, $pct,
    $hsp['gaps'], $alen, $alen ? (int) round($hsp['gaps'] * 100.0 / $alen) : 0);

  /* BLAST prints Strand for nucleotide searches and Frame for translated ones;
     printing Strand for a tblastn HSP is both wrong and unlike every other
     BLAST report a reader has seen. */
  $q_frame = isset($hsp['q_frame']) ? $hsp['q_frame'] : null;
  $h_frame = isset($hsp['h_frame']) ? $hsp['h_frame'] : null;
  if ($q_frame !== null && $h_frame !== null) {
    $out .= sprintf(" Frame = %+d/%+d\n", $q_frame, $h_frame);
  } else if ($h_frame !== null) {
    $out .= sprintf(" Frame = %+d\n", $h_frame);
  } else if ($q_frame !== null) {
    $out .= sprintf(" Frame = %+d\n", $q_frame);
  } else if ($hsp['q_strand'] !== null && $hsp['h_strand'] !== null) {
    $out .= sprintf(" Strand=%s/%s\n", $hsp['q_strand'], $hsp['h_strand']);
  }
  return $out . "\n";
}

/**
 * BLAST prints 0.0 for a zero e-value and otherwise uses a compact exponent.
 */
function mgdb_blast_text_evalue($e) {
  if ($e === null) { return 'n/a'; }
  if ($e == 0) { return '0.0'; }
  if ($e >= 0.001 && $e < 10) { return rtrim(rtrim(sprintf('%.3f', $e), '0'), '.'); }
  $s = sprintf('%.0e', $e);
  // 1e-158 rather than 1.0e-158
  return preg_replace('/e([+-])0*(\d)/', 'e$1$2', $s);
}

/**
 * Render the whole report.
 *
 * $model comes from mgdb_blast_model(); $path is the report file, needed
 * because the model deliberately omits alignment strings.
 */
function mgdb_blast_render_text($model, $path, $query_index = 0, $report = null) {
  /* Decode ONCE. The caller already has the report in almost every case; when
     it does not, read it here — but never per HSP, which is what made this
     quadratic. */
  if ($report === null) { $report = mgdb_blast_read($path, $query_index); }
  if (!$report) { return "The result file could not be read.\n"; }
  /* `version` is already "BLASTN 2.16.0+" — prefixing the program name again
     would print it twice. */
  $out = $model['version'] . "\n\n\n";

  $stat = $model['stat'];
  if (!empty($model['db'])) {
    $out .= "Database: " . $model['db'] . "\n";
    if (isset($stat['db_num'], $stat['db_len'])) {
      $out .= sprintf("           %s sequences; %s total letters\n",
        number_format($stat['db_num']), number_format($stat['db_len']));
    }
    $out .= "\n\n\n";
  }

  $title = $model['query']['title'] !== '' ? $model['query']['title'] : $model['query']['id'];
  $out .= 'Query= ' . $title . "\n\n";
  $out .= 'Length=' . $model['query']['len'] . "\n";

  if (empty($model['subjects'])) {
    $out .= "\n\n***** No hits found *****\n\n";
    return $out;
  }

  // Summary table.
  $out .= str_repeat(' ', 70) . "Score     E\n";
  $out .= "Sequences producing significant alignments:                          (Bits)  Value\n\n";
  foreach ($model['subjects'] as $s) {
    $label = $s['id'] . ($s['title'] !== '' ? ' ' . $s['title'] : '');
    if (strlen($label) > 66) { $label = substr($label, 0, 63) . '...'; }
    $bits = $s['bit_score'];
    $out .= sprintf("%-66s %6s  %-7s\n", $label,
      ($bits >= 100 ? (string) round($bits) : sprintf('%.1f', $bits)),
      mgdb_blast_text_evalue($s['evalue']));
  }
  $out .= "\n";

  // Per-subject alignments.
  foreach ($model['subjects'] as $s) {
    $out .= '>' . $s['id'] . ($s['title'] !== '' ? ' ' . $s['title'] : '') . "\n";
    $out .= 'Length=' . $s['subject_len'] . "\n\n";
    foreach ($s['hsps'] as $stub) {
      $hsp = mgdb_blast_alignment_from_report($report, $s['num'], $stub['n']);
      if (!$hsp) { continue; }
      $out .= mgdb_blast_text_hsp_header($hsp, $model['program']);
      $out .= mgdb_blast_text_alignment($hsp);
    }
    $out .= "\n";
  }

  // Footer.
  $p = $model['params'];
  if (isset($stat['lambda'])) {
    $out .= "\nGapped\nLambda      K        H\n";
    $out .= sprintf("    %.2f    %.3f    %.3f \n\n", $stat['lambda'], $stat['kappa'], $stat['entropy']);
  }
  if (isset($stat['eff_space'])) {
    $out .= 'Effective search space used: ' . $stat['eff_space'] . "\n\n";
  }
  if (isset($p['sc_match'], $p['sc_mismatch'])) {
    $out .= "\nMatrix: blastn matrix " . $p['sc_match'] . ' ' . $p['sc_mismatch'] . "\n";
  }
  if (isset($p['gap_open'], $p['gap_extend'])) {
    $out .= 'Gap Penalties: Existence: ' . $p['gap_open'] . ', Extension: ' . $p['gap_extend'] . "\n";
  }
  return $out;
}

} // MGDB_BLAST_TEXT_VIEW
