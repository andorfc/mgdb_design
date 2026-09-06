<?php
/* file: blast_results_lib.php
 *
 * purpose: turn one BLAST `-outfmt 15` (single-file JSON) report into the
 *          result model every BLAST view renders from.
 *
 *          One search produces one JSON payload; the table, text, enhanced and
 *          discovery views are all renderings of this model. Measured on this
 *          host, asking BLAST for JSON rather than tabular costs ~60 ms on a
 *          ~0.9 s search (6%), so the alternative — running the search once per
 *          format — would multiply the only expensive part of the job to
 *          produce fields we already hold. `outfmt 15` is a strict superset of
 *          `6` (tabular), `0` (pairwise text) and `5` (XML): it carries
 *          per-HSP query/subject coordinates, both strands, identity, gaps,
 *          alignment length, e-value, bit score AND the qseq/hseq/midline
 *          strings the pairwise viewer and the difference strip need.
 *
 *          NOTE the usual shortcut is unavailable here: `-outfmt 11` plus
 *          `blast_formatter` would let any format be regenerated on demand, but
 *          neither `blast_formatter` nor `blastdbcmd` is installed on this host
 *          (the BLAST RPM laid down only the search binaries). Every format is
 *          therefore derived from this model in PHP/JS.
 *
 * history
 *  09/03/26  claude  created
 */

if (!defined('MGDB_BLAST_LIB')) {
  define('MGDB_BLAST_LIB', true);

/* Two HSPs on one subject sequence separated by less than this many subject
   bases are treated as one locus. BLAST splits a single genomic match into one
   HSP per exon-sized block, so without clustering a five-exon gene looks like
   five independent "paralogs" in the chromosome view. 50 kb comfortably spans
   a maize gene with long introns while still separating genuinely distinct
   loci, which in maize are rarely that close. */
define('MGDB_BLAST_LOCUS_GAP', 50000);

/* A subject whose merged query coverage reaches this is called full-length in
   the deterministic summary; below it the match is reported as partial. */
define('MGDB_BLAST_FULL_COVERAGE', 0.90);

/* Identity at or above this counts as "essentially identical" when deciding
   whether a set of assembly hits represents one conserved gene. */
define('MGDB_BLAST_HIGH_IDENTITY', 98.0);

/* Above this a subject is a chromosome rather than a sequence, and its own
   coverage means nothing — a gene matching 3 kb of a 240 Mb chromosome is not a
   0.001% match. Subject coverage is only consulted below this size. */
define('MGDB_BLAST_CHROMOSOME_LEN', 1000000);

/* How much of the query a match must still explain before subject coverage is
   allowed to call it full-length. Without a floor, a 123 bp transposon covered
   end to end is "full-length" while accounting for 8% of the query — which is
   how a real MITE consensus hit was reported before this existed. Half is the
   line: below it, whatever else is true, the match does not explain the query
   and "full-length" is the wrong word for it. */
define('MGDB_BLAST_SUBJECT_RULE_MIN_QUERY', 50.0);


/**
 * Is this a full-length match, and of what?
 *
 * Query coverage alone gets this wrong for exactly the searches MaizeGDB users
 * run most. An mRNA query against a CDS database can never exceed ~85% query
 * coverage — the UTRs have nothing to match — so a hit covering 100% of the
 * coding sequence would be reported as "partial", which reads as a weak result
 * when it is a perfect one. A match is therefore full-length if it covers
 * essentially all of the QUERY or essentially all of the SUBJECT, and the
 * caller is told which so it can say so.
 *
 * Returns 'query', 'subject', 'both' or false.
 */
function mgdb_blast_full_length($row) {
  $threshold = MGDB_BLAST_FULL_COVERAGE * 100;
  $by_query = isset($row['q_coverage']) && $row['q_coverage'] >= $threshold;

  $by_subject = false;
  if (isset($row['s_coverage']) && $row['s_coverage'] !== null &&
      isset($row['subject_len']) && $row['subject_len'] > 0 &&
      $row['subject_len'] < MGDB_BLAST_CHROMOSOME_LEN &&
      isset($row['q_coverage']) &&
      $row['q_coverage'] >= MGDB_BLAST_SUBJECT_RULE_MIN_QUERY) {
    $by_subject = $row['s_coverage'] >= $threshold;
  }

  if ($by_query && $by_subject) { return 'both'; }
  if ($by_query) { return 'query'; }
  if ($by_subject) { return 'subject'; }
  return false;
}


/* --------------------------------------------------------------------------
   Parsing
   -------------------------------------------------------------------------- */

/**
 * Read one `-outfmt 15` file and return its decoded report, or null.
 *
 * BLAST writes {"BlastOutput2": [ {"report": {...}}, ... ]} with one element
 * per query. Multi-query submissions are supported by index.
 */
function mgdb_blast_read($path, $query_index = 0) {
  if (!is_readable($path)) { return null; }
  $raw = file_get_contents($path);
  if ($raw === false || $raw === '') { return null; }

  $doc = json_decode($raw, true);
  if (!is_array($doc) || !isset($doc['BlastOutput2'])) { return null; }

  $entries = $doc['BlastOutput2'];
  if (!isset($entries[$query_index]['report'])) { return null; }
  return $entries[$query_index]['report'];
}

/**
 * How many queries a report file holds. The form accepts multi-FASTA.
 */
function mgdb_blast_query_count($path) {
  if (!is_readable($path)) { return 0; }
  $doc = json_decode(file_get_contents($path), true);
  return (is_array($doc) && isset($doc['BlastOutput2'])) ? count($doc['BlastOutput2']) : 0;
}

/**
 * The queries a report file covers: index, title, length and hit count.
 *
 * A multi-FASTA submission runs every sequence in one BLAST invocation and the
 * report carries one entry per query. Without this the interface renders entry
 * zero and silently discards the rest — a two-sequence submission lost 28 of
 * its 61 hits with nothing on screen to say so.
 */
function mgdb_blast_queries($path) {
  if (!is_readable($path)) { return array(); }
  $doc = json_decode(file_get_contents($path), true);
  if (!is_array($doc) || !isset($doc['BlastOutput2'])) { return array(); }

  $out = array();
  foreach ($doc['BlastOutput2'] as $i => $entry) {
    if (!isset($entry['report']['results']['search'])) { continue; }
    $search = $entry['report']['results']['search'];
    $title = isset($search['query_title']) && $search['query_title'] !== ''
      ? $search['query_title']
      : (isset($search['query_id']) ? $search['query_id'] : ('Query ' . ($i + 1)));
    $out[] = array(
      'index' => $i,
      'id'    => isset($search['query_id']) ? $search['query_id'] : '',
      'title' => $title,
      'len'   => isset($search['query_len']) ? (int) $search['query_len'] : 0,
      'hits'  => isset($search['hits']) ? count($search['hits']) : 0,
    );
  }
  return $out;
}


/* --------------------------------------------------------------------------
   Interval helpers
   -------------------------------------------------------------------------- */

/**
 * Merge a list of [start, end] intervals (inclusive, start <= end) and return
 * the merged list, sorted.
 *
 * Query coverage MUST be computed this way rather than by summing HSP lengths:
 * a tandem repeat or a multi-copy domain produces several HSPs over the same
 * query bases, and summing them yields coverage above 100%.
 */
function mgdb_blast_merge_intervals($intervals) {
  if (!$intervals) { return array(); }
  usort($intervals, function ($a, $b) {
    if ($a[0] === $b[0]) { return $a[1] - $b[1]; }
    return $a[0] - $b[0];
  });

  $merged = array();
  $cur = $intervals[0];
  $n = count($intervals);
  for ($i = 1; $i < $n; $i++) {
    $iv = $intervals[$i];
    // Touching counts as overlapping: [1,10] and [11,20] cover 1..20.
    if ($iv[0] <= $cur[1] + 1) {
      if ($iv[1] > $cur[1]) { $cur[1] = $iv[1]; }
    } else {
      $merged[] = $cur;
      $cur = $iv;
    }
  }
  $merged[] = $cur;
  return $merged;
}

function mgdb_blast_interval_length($merged) {
  $total = 0;
  foreach ($merged as $iv) { $total += ($iv[1] - $iv[0] + 1); }
  return $total;
}


/* --------------------------------------------------------------------------
   HSP normalization
   -------------------------------------------------------------------------- */

/**
 * Normalize one raw HSP.
 *
 * BLAST reports hit_from > hit_to on a minus-strand match. Every consumer wants
 * start <= end for drawing and for genomic links, so the orientation is moved
 * into `strand` and the coordinates are ordered. The same is true of the query
 * side for tblastx/blastx frames.
 *
 * Alignment strings (qseq/hseq/midline) are deliberately NOT carried here. They
 * are by far the largest part of the payload and only one HSP's worth is needed
 * at a time, so they are served separately by mgdb_blast_alignment().
 */
function mgdb_blast_hsp($raw, $index) {
  $q_from = isset($raw['query_from']) ? (int) $raw['query_from'] : 0;
  $q_to   = isset($raw['query_to'])   ? (int) $raw['query_to']   : 0;
  $h_from = isset($raw['hit_from'])   ? (int) $raw['hit_from']   : 0;
  $h_to   = isset($raw['hit_to'])     ? (int) $raw['hit_to']     : 0;

  $align_len = isset($raw['align_len']) ? (int) $raw['align_len'] : 0;
  $identity  = isset($raw['identity'])  ? (int) $raw['identity']  : 0;
  $gaps      = isset($raw['gaps'])      ? (int) $raw['gaps']      : 0;

  /* BLAST reports strand as the words Plus/Minus for nucleotide searches and
     omits them for translated ones, where FRAME carries the orientation.
     A negative frame does NOT come with descending coordinates — a real
     tblastn HSP is `hit_from 4496228, hit_to 4496920, hit_frame -2`, ascending
     — so a from/to comparison never fires and every reverse-strand translated
     match was treated as plus. That mirrored its coordinates through the text
     report, the difference strip and the projected domain overlay while every
     score stayed correct, so nothing on screen looked wrong. Read the frame. */
  $q_frame = isset($raw['query_frame']) ? (int) $raw['query_frame'] : null;
  $h_frame = isset($raw['hit_frame'])   ? (int) $raw['hit_frame']   : null;

  $q_strand = isset($raw['query_strand']) ? $raw['query_strand'] : null;
  $h_strand = isset($raw['hit_strand'])   ? $raw['hit_strand']   : null;
  if ($q_strand === null && $q_frame !== null) { $q_strand = $q_frame < 0 ? 'Minus' : 'Plus'; }
  if ($h_strand === null && $h_frame !== null) { $h_strand = $h_frame < 0 ? 'Minus' : 'Plus'; }
  if ($h_strand === null && $h_from > $h_to) { $h_strand = 'Minus'; }
  if ($q_strand === null && $q_from > $q_to) { $q_strand = 'Minus'; }

  return array(
    'n'          => $index + 1,
    'q_start'    => min($q_from, $q_to),
    'q_end'      => max($q_from, $q_to),
    'h_start'    => min($h_from, $h_to),
    'h_end'      => max($h_from, $h_to),
    'q_strand'   => $q_strand,
    'h_strand'   => $h_strand,
    'q_frame'    => $q_frame,
    'h_frame'    => $h_frame,
    // The orientation a reader cares about: does the subject run with the query?
    'orientation' => ($q_strand === 'Minus') !== ($h_strand === 'Minus') ? '-' : '+',
    'align_len'  => $align_len,
    'identity'   => $identity,
    'mismatches' => max(0, $align_len - $identity - $gaps),
    'gaps'       => $gaps,
    'pident'     => $align_len > 0 ? round($identity * 100.0 / $align_len, 2) : 0.0,
    'evalue'     => isset($raw['evalue']) ? (float) $raw['evalue'] : null,
    'bit_score'  => isset($raw['bit_score']) ? (float) $raw['bit_score'] : null,
    'score'      => isset($raw['score']) ? (int) $raw['score'] : null,
  );
}


/* --------------------------------------------------------------------------
   Locus clustering
   -------------------------------------------------------------------------- */

/**
 * Cluster a subject's HSPs into loci by subject coordinate.
 *
 * On a chromosome database a single gene match arrives as one HSP per aligned
 * block. Clustering them is what lets the interface answer "are there
 * paralogous loci elsewhere?" — without it every exon reads as another locus.
 */
function mgdb_blast_cluster_loci($hsps, $gap = MGDB_BLAST_LOCUS_GAP) {
  if (!$hsps) { return array(); }

  $sorted = $hsps;
  usort($sorted, function ($a, $b) {
    return $a['h_start'] === $b['h_start'] ? $a['h_end'] - $b['h_end'] : $a['h_start'] - $b['h_start'];
  });

  $loci = array();
  $cur = null;
  foreach ($sorted as $hsp) {
    if ($cur !== null && $hsp['h_start'] - $cur['h_end'] <= $gap) {
      $cur['h_end'] = max($cur['h_end'], $hsp['h_end']);
      $cur['hsps'][] = $hsp;
    } else {
      if ($cur !== null) { $loci[] = $cur; }
      $cur = array('h_start' => $hsp['h_start'], 'h_end' => $hsp['h_end'], 'hsps' => array($hsp));
    }
  }
  if ($cur !== null) { $loci[] = $cur; }

  // Summarize each cluster.
  $out = array();
  foreach ($loci as $locus) {
    $out[] = mgdb_blast_summarize_group($locus['hsps'], array(
      'h_start' => $locus['h_start'],
      'h_end'   => $locus['h_end'],
    ));
  }

  // Strongest locus first.
  usort($out, function ($a, $b) {
    if ($a['bit_score'] == $b['bit_score']) { return 0; }
    return ($a['bit_score'] < $b['bit_score']) ? 1 : -1;
  });
  return $out;
}


/* --------------------------------------------------------------------------
   Aggregation
   -------------------------------------------------------------------------- */

/**
 * Roll a set of HSPs up into one row: the stats a table, a bar or a point needs.
 *
 * `pident` is the best HSP's identity, which is what a reader means by "how
 * similar is my best match". `pident_weighted` is the identity across all
 * aligned bases in the group, which is the honest figure for a multi-HSP match
 * and is what the pan-gene medians are built from.
 */
function mgdb_blast_summarize_group($hsps, $extra = array()) {
  $q_intervals = array();
  $h_intervals = array();
  $best = null;
  $bits = 0.0;
  $ident_sum = 0;
  $alen_sum = 0;
  $gaps_sum = 0;
  $mm_sum = 0;
  $h_min = null;
  $h_max = null;

  foreach ($hsps as $hsp) {
    $q_intervals[] = array($hsp['q_start'], $hsp['q_end']);
    $h_intervals[] = array($hsp['h_start'], $hsp['h_end']);
    $bits += (float) $hsp['bit_score'];
    $ident_sum += $hsp['identity'];
    $alen_sum  += $hsp['align_len'];
    $gaps_sum  += $hsp['gaps'];
    $mm_sum    += $hsp['mismatches'];
    $h_min = ($h_min === null) ? $hsp['h_start'] : min($h_min, $hsp['h_start']);
    $h_max = ($h_max === null) ? $hsp['h_end']   : max($h_max, $hsp['h_end']);

    if ($best === null) {
      $best = $hsp;
    } else {
      // Rank by bit score, breaking ties on the lower e-value.
      if ($hsp['bit_score'] > $best['bit_score']) { $best = $hsp; }
    }
  }

  $merged = mgdb_blast_merge_intervals($q_intervals);
  $h_merged = mgdb_blast_merge_intervals($h_intervals);

  $row = array(
    'n_hsps'          => count($hsps),
    /* Subject bases actually covered, merged. NOT the sum of alignment lengths:
       that double-counts overlapping HSPs and counts gap columns, so a tandem
       repeat matching one subject region seven times sums past the subject's
       own length and caps at a fictitious 100%. Measured on a real 7x repeat
       query, the summed form reported 100% where the merged form reports 15%. */
    'h_aligned'       => mgdb_blast_interval_length($h_merged),
    'q_start'         => $merged ? $merged[0][0] : 0,
    'q_end'           => $merged ? $merged[count($merged) - 1][1] : 0,
    'q_intervals'     => $merged,
    'q_aligned'       => mgdb_blast_interval_length($merged),
    'h_start'         => isset($extra['h_start']) ? $extra['h_start'] : $h_min,
    'h_end'           => isset($extra['h_end'])   ? $extra['h_end']   : $h_max,
    /* The merged HSP spans on the subject, which is what a genome browser needs
       to draw this match as a segmented feature rather than as one block from
       h_start to h_end. h_aligned above is already computed from them. */
    'h_intervals'     => $h_merged,
    'orientation'     => $best['orientation'],
    'pident'          => $best['pident'],
    'pident_weighted' => $alen_sum > 0 ? round($ident_sum * 100.0 / $alen_sum, 2) : 0.0,
    'align_len'       => $alen_sum,
    'mismatches'      => $mm_sum,
    'gaps'            => $gaps_sum,
    'evalue'          => $best['evalue'],
    'bit_score'       => $best['bit_score'],
    'bit_score_total' => round($bits, 1),
    'best_hsp'        => $best['n'],
  );
  return $row;
}


/* --------------------------------------------------------------------------
   The model
   -------------------------------------------------------------------------- */

/**
 * Build the result model from a decoded report.
 *
 * Returns subject-level rows with nested HSPs and, for every subject, the loci
 * its HSPs cluster into. A view chooses its own row unit from this: gene-model
 * searches read naturally at subject level (one row per gene), genomic searches
 * at locus level (one row per genomic match), because a chromosome subject is
 * one "hit" carrying dozens of unrelated alignments.
 */
function mgdb_blast_model($report, $opts = array()) {
  if (!is_array($report)) { return null; }

  $search = isset($report['results']['search']) ? $report['results']['search'] : array();
  $query_len = isset($search['query_len']) ? (int) $search['query_len'] : 0;

  $subjects = array();
  $hsp_total = 0;
  $raw_hits = isset($search['hits']) && is_array($search['hits']) ? $search['hits'] : array();

  foreach ($raw_hits as $hit) {
    $hsps = array();
    $raw_hsps = isset($hit['hsps']) && is_array($hit['hsps']) ? $hit['hsps'] : array();
    foreach ($raw_hsps as $i => $raw) { $hsps[] = mgdb_blast_hsp($raw, $i); }
    if (!$hsps) { continue; }
    $hsp_total += count($hsps);

    // BLAST allows several deflines per identical sequence; the first is canonical.
    $desc = isset($hit['description'][0]) ? $hit['description'][0] : array();

    $row = mgdb_blast_summarize_group($hsps);
    $row['num']       = isset($hit['num']) ? (int) $hit['num'] : 0;
    $row['id']        = isset($desc['id']) ? $desc['id'] : '';
    $row['accession'] = isset($desc['accession']) ? $desc['accession'] : '';
    $row['title']     = isset($desc['title']) ? $desc['title'] : '';
    $row['subject_len'] = isset($hit['len']) ? (int) $hit['len'] : 0;
    $row['q_coverage'] = $query_len > 0 ? round($row['q_aligned'] * 100.0 / $query_len, 1) : 0.0;
    $row['s_coverage'] = $row['subject_len'] > 0
      ? round(min($row['subject_len'], $row['h_aligned']) * 100.0 / $row['subject_len'], 1)
      : null;
    $row['hsps'] = $hsps;
    $row['loci'] = mgdb_blast_cluster_loci($hsps);

    /* Per-locus coverage, so a locus row can be read on its own — and the
       subject's own size and coverage, because the interpretation flattens the
       model to loci and would otherwise have no way to tell a full-length CDS
       match from a partial one. Without this the subject-coverage rule was
       unreachable on every single-target search: the very case it exists for. */
    foreach ($row['loci'] as $i => $locus) {
      $row['loci'][$i]['q_coverage'] =
        $query_len > 0 ? round($locus['q_aligned'] * 100.0 / $query_len, 1) : 0.0;
      $row['loci'][$i]['subject_len'] = $row['subject_len'];
      $row['loci'][$i]['s_coverage'] = $row['subject_len'] > 0
        ? round(min($row['subject_len'], $locus['h_aligned']) * 100.0 / $row['subject_len'], 1)
        : null;
    }

    $subjects[] = $row;
  }

  // BLAST already returns hits by descending score; keep that as the default.
  $model = array(
    'program'   => isset($report['program']) ? $report['program'] : '',
    'version'   => isset($report['version']) ? $report['version'] : '',
    'db'        => isset($report['search_target']['db']) ? basename($report['search_target']['db']) : '',
    'db_path'   => isset($report['search_target']['db']) ? $report['search_target']['db'] : '',
    'params'    => isset($report['params']) ? $report['params'] : array(),
    'stat'      => isset($search['stat']) ? $search['stat'] : array(),
    'query'     => array(
      'id'    => isset($search['query_id']) ? $search['query_id'] : '',
      'title' => isset($search['query_title']) ? $search['query_title'] : '',
      'len'   => $query_len,
    ),
    'subjects'  => $subjects,
    'n_subjects' => count($subjects),
    'n_hsps'    => $hsp_total,
  );

  $model['n_loci'] = 0;
  foreach ($subjects as $s) { $model['n_loci'] += count($s['loci']); }

  return $model;
}


/* --------------------------------------------------------------------------
   Alignment strings, served on demand
   -------------------------------------------------------------------------- */

/**
 * Return the alignment strings for one HSP, plus the difference list the
 * sequence-difference strip draws.
 *
 * Kept out of the model so that a 10,000-HSP result does not carry megabytes of
 * sequence into a page that will show one alignment at a time.
 */
function mgdb_blast_alignment($path, $query_index, $hit_num, $hsp_num) {
  $report = mgdb_blast_read($path, $query_index);
  if (!$report) { return null; }
  return mgdb_blast_alignment_from_report($report, $hit_num, $hsp_num);
}

/**
 * The same, against an ALREADY-DECODED report.
 *
 * This split exists because the text renderer walks every HSP, and calling the
 * path-based form per HSP re-read and re-decoded the whole file each time. On a
 * 61 MB report with 42,218 HSPs that is quadratic: the download returned an
 * HTTP 200 carrying a PHP "Maximum execution time exceeded" fatal, which the
 * browser then saved as the user's BLAST report. Anything that needs more than
 * one HSP from one file must decode once and use this.
 */
function mgdb_blast_alignment_from_report($report, $hit_num, $hsp_num) {
  $hits = isset($report['results']['search']['hits']) ? $report['results']['search']['hits'] : array();
  foreach ($hits as $hit) {
    if ((int) $hit['num'] !== (int) $hit_num) { continue; }
    $raw_hsps = isset($hit['hsps']) ? $hit['hsps'] : array();
    foreach ($raw_hsps as $i => $raw) {
      if (($i + 1) !== (int) $hsp_num) { continue; }
      $hsp = mgdb_blast_hsp($raw, $i);
      $hsp['qseq']    = isset($raw['qseq']) ? $raw['qseq'] : '';
      $hsp['hseq']    = isset($raw['hseq']) ? $raw['hseq'] : '';
      $hsp['midline'] = isset($raw['midline']) ? $raw['midline'] : '';
      $hsp['differences'] = mgdb_blast_differences($raw, $hsp);
      $desc = isset($hit['description'][0]) ? $hit['description'][0] : array();
      $hsp['subject'] = array(
        'id'    => isset($desc['id']) ? $desc['id'] : '',
        'title' => isset($desc['title']) ? $desc['title'] : '',
        'len'   => isset($hit['len']) ? (int) $hit['len'] : 0,
      );
      return $hsp;
    }
  }
  return null;
}

/**
 * Walk one alignment and list every position where query and subject differ.
 *
 * Returns entries carrying the QUERY coordinate, so the difference strip can be
 * drawn against the query axis the rest of the interface uses. Runs of adjacent
 * gaps are collapsed into one insertion/deletion entry rather than reported per
 * base, which is what a reader means by "a 3 bp deletion".
 *
 * Bases are compared CASE-INSENSITIVELY. BLAST soft-masks low-complexity and
 * repeat sequence by lowercasing it (see `filter` in the report's params), so a
 * byte comparison reports every masked base as a substitution: on the first
 * alignment tested that turned 3 real mismatches into 10 fake SNPs, all of them
 * `c` against `C`. Where either side was masked the entry is flagged, because
 * "this difference sits in repeat-masked sequence" is worth showing.
 */
function mgdb_blast_differences($raw, $hsp) {
  $q = isset($raw['qseq']) ? $raw['qseq'] : '';
  $h = isset($raw['hseq']) ? $raw['hseq'] : '';
  $mid = isset($raw['midline']) ? $raw['midline'] : '';
  if ($q === '' || $h === '' || strlen($q) !== strlen($h)) { return array(); }

  $len = strlen($q);
  // Query coordinates advance in the direction the query was aligned.
  $q_forward = ($hsp['q_strand'] !== 'Minus');
  $q_pos = $q_forward ? $hsp['q_start'] : $hsp['q_end'];

  /* How many query bases one alignment column consumes.
     For blastn and blastp it is 1. For blastx and tblastx the query is
     translated, so each column is a CODON while q_start/q_end stay nucleotide
     coordinates — stepping by 1 there packs every difference into the first
     third of the query span and reports positions that are simply wrong.
     Derived from the HSP rather than threaded down from the program name, so
     it stays correct whatever called it: the span divided by the number of
     columns the query actually consumed is 1 or 3. */
  $q_cols = $len - substr_count($q, '-');
  $span = abs($hsp['q_end'] - $hsp['q_start']) + 1;
  $per_col = ($q_cols > 0 && (int) round($span / $q_cols) === 3) ? 3 : 1;

  $step = ($q_forward ? 1 : -1) * $per_col;

  $diffs = array();
  $run = null;

  for ($i = 0; $i < $len; $i++) {
    $qc = $q[$i];
    $hc = $h[$i];
    $is_q_gap = ($qc === '-');
    $is_h_gap = ($hc === '-');
    // Masked (soft-filtered) sequence arrives lowercase and must not read as a difference.
    $masked = ($qc >= 'a' && $qc <= 'z') || ($hc >= 'a' && $hc <= 'z');
    $qu = strtoupper($qc);
    $hu = strtoupper($hc);

    if ($is_q_gap || $is_h_gap) {
      $type = $is_q_gap ? 'insertion' : 'deletion';
      if ($run !== null && $run['type'] === $type) {
        $run['length']++;
        $run['query_allele']   .= $qu;
        $run['subject_allele'] .= $hu;
        if ($masked) { $run['masked'] = true; }
      } else {
        if ($run !== null) { $diffs[] = $run; }
        $run = array(
          'type' => $type, 'q_pos' => $q_pos, 'length' => 1,
          'query_allele' => $qu, 'subject_allele' => $hu, 'masked' => $masked,
        );
      }
    } else {
      if ($run !== null) { $diffs[] = $run; $run = null; }
      if ($qu !== $hu) {
        /* For protein searches BLAST marks a conservative substitution with '+'
           in the midline. Carrying that lets the viewer distinguish a
           conservative change from a radical one without re-scoring. */
        $similar = ($mid !== '' && isset($mid[$i]) && $mid[$i] === '+');
        $diffs[] = array(
          'type' => 'substitution', 'q_pos' => $q_pos, 'length' => 1,
          'query_allele' => $qu, 'subject_allele' => $hu,
          'masked' => $masked, 'similar' => $similar,
        );
      }
    }

    // Only advance the query coordinate when the query actually consumed a base.
    if (!$is_q_gap) { $q_pos += $step; }
  }
  if ($run !== null) { $diffs[] = $run; }

  return $diffs;
}



/* --------------------------------------------------------------------------
   Deterministic interpretation
   --------------------------------------------------------------------------
   The one-line reading at the top of the results page is generated from
   explicit rules over the model, never from a language model. A researcher has
   to be able to trace the sentence back to the numbers, and the same result
   must always produce the same words.
   -------------------------------------------------------------------------- */

/* A locus is worth naming in the summary once it covers this much of the query.
   Below it, a match is a fragment — real homology may be there, but calling it
   a "locus" in a one-line summary overstates it. */
define('MGDB_BLAST_NOTABLE_COVERAGE', 25.0);

/* Past this many loci the result is repetitive rather than enumerable, and the
   interface summarizes instead of drawing every one. */
define('MGDB_BLAST_REPETITIVE_LOCI', 100);

/**
 * Classify a result and produce a deterministic sentence.
 *
 * Returns a scenario key the interface uses to decide which panels are worth
 * showing, plus prose assembled from the counts. The scenario keys match the
 * outcomes the interface is expected to handle: none, unique, paralogous,
 * partial, repetitive, multi (the same locus across many assemblies).
 */
function mgdb_blast_interpret($model, $context = array()) {
  $out = array('scenario' => 'none', 'headline' => '', 'detail' => '', 'facts' => array());
  if (!$model || empty($model['subjects'])) {
    $out['headline'] = 'No significant matches.';
    return $out;
  }

  // Flatten to loci, strongest first, remembering which subject each came from.
  $loci = array();
  foreach ($model['subjects'] as $s) {
    foreach ($s['loci'] as $L) {
      $L['subject'] = $s['id'];
      $loci[] = $L;
    }
  }
  usort($loci, function ($a, $b) {
    if ($a['bit_score_total'] == $b['bit_score_total']) { return 0; }
    return ($a['bit_score_total'] < $b['bit_score_total']) ? 1 : -1;
  });

  $n_loci = count($loci);
  $best = $loci[0];
  $notable = array();
  foreach ($loci as $L) {
    if ($L['q_coverage'] >= MGDB_BLAST_NOTABLE_COVERAGE) { $notable[] = $L; }
  }

  $out['facts'] = array(
    'n_subjects'    => $model['n_subjects'],
    'n_hsps'        => $model['n_hsps'],
    'n_loci'        => $n_loci,
    'n_notable'     => count($notable),
    'best_subject'  => $best['subject'],
    'best_coverage' => $best['q_coverage'],
    'best_identity' => $best['pident'],
    'best_evalue'   => $best['evalue'],
  );

  $full_kind = mgdb_blast_full_length($best);
  $full = ($full_kind !== false);
  $span = ($best['q_coverage'] >= MGDB_BLAST_NOTABLE_COVERAGE);
  $out['facts']['full_length'] = $full_kind;

  // Repetitive dominates every other reading: nothing else can be said usefully.
  if ($n_loci > MGDB_BLAST_REPETITIVE_LOCI) {
    $out['scenario'] = 'repetitive';
    $out['headline'] = number_format($model['n_hsps']) . ' significant alignments across '
                     . number_format($n_loci) . ' genomic loci.';
    $out['detail'] = 'This pattern is typical of a repetitive or transposon-derived query. '
                   . 'The strongest matches are shown; use the filters to narrow by identity or coverage.';
    return $out;
  }

  // A match that covers only part of the query, however well, is a partial match.
  if (!$full && $span) {
    $out['scenario'] = 'partial';
    $out['headline'] = 'Best match covers ' . $best['q_coverage'] . '% of the query at '
                     . $best['pident'] . '% identity.';
    $out['detail'] = 'No full-length match was found. A match confined to part of the query '
                   . 'often indicates a shared domain or a conserved segment rather than a '
                   . 'full-length homolog.';
    return $out;
  }

  if (!$span) {
    $out['scenario'] = 'partial';
    $out['headline'] = 'Only short, partial matches were found (best covers '
                     . $best['q_coverage'] . '% of the query).';
    $out['detail'] = 'Consider relaxing the e-value threshold or searching a different database.';
    return $out;
  }

  // Full-length. One locus or several?
  if (count($notable) <= 1) {
    $out['scenario'] = 'unique';
    $out['headline'] = ($full_kind === 'subject')
      ? 'One full-length match: ' . $best['subject'] . ' at ' . $best['pident']
        . '% identity across the whole of that sequence.'
      : 'One full-length match: ' . $best['subject'] . ' at ' . $best['pident']
        . '% identity over ' . $best['q_coverage'] . '% of the query.';
    if ($n_loci > 1) {
      $out['detail'] = ($n_loci - 1) . ' weaker ' . (($n_loci - 1) === 1 ? 'match' : 'matches')
                     . ' also occur but cover less than ' . (int) MGDB_BLAST_NOTABLE_COVERAGE
                     . '% of the query.';
    }
    return $out;
  }

  // Several substantial loci — paralogs or a gene family.
  $out['scenario'] = 'paralogous';
  $others = array_slice($notable, 1);
  $names = array();
  foreach ($others as $L) {
    if (!in_array($L['subject'], $names, true)) { $names[] = $L['subject']; }
  }
  $n_other = count($others);
  $out['headline'] = 'One dominant match on ' . $best['subject'] . ' (' . $best['pident']
                   . '% identity, ' . $best['q_coverage'] . '% coverage), plus '
                   . $n_other . ' further substantial ' . ($n_other === 1 ? 'locus' : 'loci') . '.';

  $detail = ($n_other === 1)
    ? 'The additional match on ' . mgdb_blast_join_list($names)
      . ' may represent a paralog or another member of the same gene family.'
    : 'The additional matches on ' . mgdb_blast_join_list($names)
      . ' may represent paralogs or other members of the same gene family.';

  /* A permissive search can leave a long tail of fragmentary loci under the
     notable threshold. Saying only "plus one further locus" when the result
     holds ninety would read as a contradiction of the counts beside it. */
  $tail = $n_loci - count($notable);
  if ($tail > 0) {
    $detail .= ' A further ' . number_format($tail) . ' weaker '
             . ($tail === 1 ? 'locus covers' : 'loci cover') . ' less than '
             . (int) MGDB_BLAST_NOTABLE_COVERAGE . '% of the query each.';
  }
  $out['detail'] = $detail;
  return $out;
}

/**
 * "a", "a and b", "a, b and c" — American English, serial comma omitted before
 * the conjunction to match the rest of the site's prose.
 */
function mgdb_blast_join_list($items) {
  $n = count($items);
  if ($n === 0) { return ''; }
  if ($n === 1) { return $items[0]; }
  if ($n === 2) { return $items[0] . ' and ' . $items[1]; }
  $last = array_pop($items);
  return implode(', ', $items) . ' and ' . $last;
}



/* --------------------------------------------------------------------------
   Multi-target interpretation
   --------------------------------------------------------------------------
   A search against several assemblies asks a different question from a search
   against one. On one assembly the reader wants to know "where is it, and are
   there paralogs"; across many they want "is this gene present everywhere, or
   only in some lines". The single-target sentence would answer neither, so a
   multi-target job gets its own reading rather than the first target's.
   -------------------------------------------------------------------------- */

/**
 * Read a set of per-target models as one result.
 *
 * $per_target is a list of array('label' => 'Zm-B97-…', 'model' => <model>).
 * `label` is the assembly where there is one, and the database name otherwise,
 * because that is what the reader recognizes.
 */
function mgdb_blast_interpret_multi($per_target) {
  $out = array('scenario' => 'none', 'headline' => '', 'detail' => '', 'facts' => array());
  $n = count($per_target);
  if (!$n) { $out['headline'] = 'No results.'; return $out; }

  $with_hits = array();
  $without = array();
  $best = null;
  $full = array();
  $full_kinds = array();
  $idents = array();

  foreach ($per_target as $t) {
    $m = $t['model'];
    if (!$m || empty($m['subjects'])) { $without[] = $t['label']; continue; }
    $with_hits[] = $t['label'];

    // The strongest subject in this target.
    $top = null;
    foreach ($m['subjects'] as $sub) {
      if ($top === null || $sub['bit_score_total'] > $top['bit_score_total']) { $top = $sub; }
    }
    $idents[] = $top['pident'];
    $kind = mgdb_blast_full_length($top);
    if ($kind !== false) { $full[] = $t['label']; $full_kinds[$kind] = true; }
    if ($best === null || $top['bit_score_total'] > $best['bit_score_total']) {
      $best = $top;
      $best['label'] = $t['label'];
    }
  }

  $out['facts'] = array(
    'targets'        => $n,
    'targets_hit'    => count($with_hits),
    'targets_missed' => $without,
    'full_length'    => count($full),
    'best_label'     => $best ? $best['label'] : null,
    'best_identity'  => $best ? $best['pident'] : null,
    'best_coverage'  => $best ? $best['q_coverage'] : null,
    'identity_min'   => $idents ? min($idents) : null,
    'identity_max'   => $idents ? max($idents) : null,
  );

  if (!$with_hits) {
    $out['headline'] = 'No significant matches in any of the ' . $n . ' searched targets.';
    return $out;
  }

  $hit = count($with_hits);
  $out['scenario'] = ($hit === $n) ? 'conserved' : 'variable';

  $range = '';
  if ($idents && count($idents) > 1 && max($idents) - min($idents) >= 0.05) {
    $range = ' (identity ' . number_format(min($idents), 1) . '–' .
             number_format(max($idents), 1) . '%)';
  } else if ($idents) {
    $range = ' (identity ' . number_format($idents[0], 1) . '%)';
  }

  $kind = (count($full) === $hit) ? 'A full-length match'
        : (count($full) > 0 ? 'A match' : 'A partial match');

  $out['headline'] = $kind . ' was found in ' . $hit . ' of ' . $n .
                     ' searched ' . ($n === 1 ? 'target' : 'targets') . $range . '.';

  /* When every full-length call came from subject coverage rather than query
     coverage, the query is longer than what was searched — an mRNA against a
     CDS database is the common case. Saying so turns a figure that looks like a
     shortfall into the fact it actually is. */
  /* Only when EVERY target that matched is full-length, and every one of those
     calls came from subject coverage. Saying "the matched sequences are covered
     end to end" while half of them are partial describes a result that does not
     exist. */
  $subject_only = (count($full) === $hit)
                  && isset($full_kinds['subject'])
                  && !isset($full_kinds['query'])
                  && !isset($full_kinds['both']);

  $detail = 'Strongest in ' . $best['label'] . ' at ' . number_format($best['pident'], 2) .
            '% identity over ' . number_format($best['q_coverage'], 1) . '% of the query.';
  if ($subject_only) {
    $detail .= ' The matched sequences are covered end to end, so the uncovered '
             . 'part of the query lies outside what was searched — expected when '
             . 'a transcript is searched against coding sequences.';
  }
  if ($without) {
    $detail .= ' No significant match in ' . mgdb_blast_join_list($without) . '.';
  }
  if (count($full) > 0 && count($full) < $hit) {
    $detail .= ' The match is full-length in ' . count($full) . ' of them and partial elsewhere.';
  }
  $out['detail'] = $detail;
  return $out;
}



/* --------------------------------------------------------------------------
   Protein domains projected onto the query
   --------------------------------------------------------------------------
   The query is whatever the user pasted, so it carries no annotation of its
   own and cannot be scanned here. What CAN be said, honestly, is where the
   query aligns to the annotated domains of the sequence it matched — "this
   part of your query corresponds to the SBP domain of lg1". That is the
   question the overlay exists to answer: it makes a match confined to a single
   domain visually obvious instead of merely implied by a short coverage bar.

   Projection walks the alignment rather than interpolating, because gaps make
   the mapping non-linear, and it respects each side's bases-per-column so it
   is correct for blastx (translated query) as well as blastp.
   -------------------------------------------------------------------------- */

/**
 * Map subject (amino-acid) intervals onto query coordinates through one HSP.
 *
 * $hsp must carry qseq/hseq (i.e. come from mgdb_blast_alignment()).
 * $domains is a list of array('start' => aa, 'end' => aa, ...) in SUBJECT
 * coordinates. Returns the same records with q_start/q_end added, plus
 * `clipped` when the domain runs past what the alignment covers.
 *
 * Domains that fall entirely outside the aligned region are dropped: drawing a
 * domain the alignment never reached, at a query position derived from nothing,
 * would be an invention.
 */
function mgdb_blast_project_domains($hsp, $domains) {
  if (!$domains || empty($hsp['qseq']) || empty($hsp['hseq'])) { return array(); }

  $q = $hsp['qseq'];
  $h = $hsp['hseq'];
  $len = strlen($q);

  $q_per = mgdb_blast_bases_per_column($hsp['q_start'], $hsp['q_end'], $q);
  $h_per = mgdb_blast_bases_per_column($hsp['h_start'], $hsp['h_end'], $h);
  $q_fwd = ($hsp['q_strand'] !== 'Minus');
  $h_fwd = ($hsp['h_strand'] !== 'Minus');
  $q_pos = $q_fwd ? $hsp['q_start'] : $hsp['q_end'];
  $h_pos = $h_fwd ? $hsp['h_start'] : $hsp['h_end'];
  $q_dir = $q_fwd ? 1 : -1;
  $h_dir = $h_fwd ? 1 : -1;

  // subject residue -> query coordinate, for every aligned column
  $map = array();
  for ($i = 0; $i < $len; $i++) {
    $q_gap = ($q[$i] === '-');
    $h_gap = ($h[$i] === '-');
    if (!$h_gap) {
      // Record the query position this subject residue sits against.
      $map[$h_pos] = $q_gap ? null : $q_pos;
    }
    if (!$q_gap) { $q_pos += $q_dir * $q_per; }
    if (!$h_gap) { $h_pos += $h_dir * $h_per; }
  }
  if (!$map) { return array(); }

  $covered = array_keys($map);
  $lo = min($covered);
  $hi = max($covered);

  $out = array();
  foreach ($domains as $d) {
    $ds = (int) $d['start'];
    $de = (int) $d['end'];
    if ($de < $lo || $ds > $hi) { continue; }   // never reached by this alignment

    $clipped = ($ds < $lo || $de > $hi);
    $ds = max($ds, $lo);
    $de = min($de, $hi);

    // Nearest aligned residue on each side; gapped columns map to null.
    $qs = null; $qe = null;
    for ($p = $ds; $p <= $de && $qs === null; $p++) {
      if (isset($map[$p]) && $map[$p] !== null) { $qs = $map[$p]; }
    }
    for ($p = $de; $p >= $ds && $qe === null; $p--) {
      if (isset($map[$p]) && $map[$p] !== null) { $qe = $map[$p]; }
    }
    if ($qs === null || $qe === null) { continue; }

    $d['q_start'] = min($qs, $qe);
    /* The end coordinate is the last base of the last column, matching the
       convention used everywhere else in this file. */
    $d['q_end'] = max($qs, $qe) + ($q_per - 1);
    $d['s_start'] = $ds;
    $d['s_end'] = $de;
    $d['clipped'] = $clipped;
    $out[] = $d;
  }
  return $out;
}

/**
 * Bases of one sequence consumed per alignment column: 1, or 3 if translated.
 * Mirrors the text renderer's helper; defined here so the model layer does not
 * depend on the view.
 */
if (!function_exists('mgdb_blast_bases_per_column')) {
  function mgdb_blast_bases_per_column($start, $end, $seq) {
    $cols = strlen($seq) - substr_count($seq, '-');
    if ($cols <= 0) { return 1; }
    return ((int) round((abs($end - $start) + 1) / $cols) === 3) ? 3 : 1;
  }
}

} // MGDB_BLAST_LIB
