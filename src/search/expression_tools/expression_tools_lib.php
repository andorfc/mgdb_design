<?php
/* file: search/expression_tools/expression_tools_lib.php
 *
 * purpose: the data layer and the genome-wide analyses behind Expression
 *          Tools (/expression/tools).
 *
 *          Everything here reads the release tools/expression_tools_index.py
 *          writes under data/expression_tools/ -- one directory per genome
 *          with float32 matrices (genes x samples), per-gene and per-sample
 *          statistics, an annotation index and GO annotations, plus the
 *          pan-genome summary -- and nothing touches the database. The
 *          matrices are the same values data/expression/<genome>/
 *          expression.sqlite holds (and /api/v1/data/expression serves), laid
 *          out for a genome-wide pass instead of a one-gene read:
 *
 *            <assay>.raw.f32    the published value, -1 where not measured
 *            <assay>.log.f32    log2(value + 1), -1 where not measured
 *            <assay>.stats.f32  8 floats per gene (see ET_STAT_*)
 *
 *          -1 is "not measured", never zero. Every statistic below is taken
 *          over the samples a gene actually has, and a correlation over the
 *          samples both genes have (pairwise complete). The first port of
 *          these tools dropped any gene missing a value in the selection; on
 *          the B73 v5 release that is most of them, because Walley 2019 did
 *          not quantify 16,154 of the genes.
 *
 *          Measured on dev8 (PHP 8.2, no JIT): one pass over the B73 v5 RNA
 *          matrix, 44,303 genes x 313 samples, is about a second; a NAM
 *          founder (23 samples) about 60 ms.
 *
 * history:
 *  09/24/26  claude  created, from ExpressionTools (github andorfc/rna_seq_tools)
 */

define('ET_STAT_PRESENT', 0);
define('ET_STAT_DETECTED', 1);
define('ET_STAT_SUM', 2);
define('ET_STAT_SUMSQ', 3);
define('ET_STAT_MEAN', 4);
define('ET_STAT_SD', 5);
define('ET_STAT_MAX', 6);
define('ET_STAT_PATTERN', 7);
define('ET_CHUNK_ROWS', 1024);
define('ET_NAM_REFERENCE', 'Zm-B73-REFERENCE-NAM-5.0');
/* A genome expresses a pan-gene when its members reach 1 FPKM in some shared
   sample and carries it silent when they never reach 0.1; in between is low.
   The same two numbers as EXPRESSED_FPKM and SILENT_FPKM in the builder. */
define('ET_EXPRESSED_FPKM', 1.0);
define('ET_SILENT_FPKM', 0.1);

/* A problem the reader can act on: the message is shown as it is. */
class EtError extends RuntimeException {
  public $status;
  public function __construct($message, $status = 400) {
    parent::__construct($message);
    $this->status = $status;
  }
}

/* ------------------------------------------------------------------------
   The release
   ------------------------------------------------------------------------ */

class EtData {

  private static $manifest = null;
  private static $genomes = array();
  private static $pan = null;
  private static $paralogs = null;

  public static function root() {
    return (isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '')
      ? rtrim($_SERVER['DOCUMENT_ROOT'], '/') : realpath(__DIR__ . '/../..');
  }

  public static function dir() {
    return self::root() . '/data/expression_tools';
  }

  public static function manifest() {
    if (self::$manifest === null) {
      $raw = @file_get_contents(self::dir() . '/manifest.json');
      $m = $raw === false ? null : json_decode($raw, true);
      self::$manifest = is_array($m) ? $m : false;
    }
    return self::$manifest === false ? null : self::$manifest;
  }

  /* Changes whenever the release is rebuilt, or the paralogs release the
     homeolog tools read: part of every cache key and validator. */
  public static function stamp() {
    return (int) @filemtime(self::dir() . '/manifest.json') . '_' .
           (int) @filemtime(self::root() . '/data/paralogs/' . ET_NAM_REFERENCE . '/paralogs.sqlite');
  }

  public static function genomeList() {
    $m = self::manifest();
    return $m ? $m['genomes'] : array();
  }

  /* A genome by its key (B73v5, Oh7B), its assembly name, or '' / 'current'
     for B73 v5. Null when unknown. */
  public static function genome($key) {
    $key = trim((string) $key);
    if ($key === '' || strtolower($key) === 'current') { $key = 'B73v5'; }
    foreach (self::genomeList() as $g) {
      if (strcasecmp($g['key'], $key) === 0 || $g['genome'] === $key) {
        if (!isset(self::$genomes[$g['genome']])) {
          self::$genomes[$g['genome']] = new EtGenome($g, self::dir());
        }
        return self::$genomes[$g['genome']];
      }
    }
    return null;
  }

  public static function requireGenome($key) {
    $g = self::genome($key);
    if ($g === null) { throw new EtError('Unknown genome: ' . $key, 404); }
    return $g;
  }

  /* The 26 NAM genomes in the pan-genome's index order (B73 v5 first). */
  public static function namGenomes() {
    $m = self::manifest();
    return ($m && isset($m['pangenome']['nam_genomes'])) ? $m['pangenome']['nam_genomes'] : array();
  }

  public static function sharedSamples() {
    $m = self::manifest();
    return $m ? $m['shared_samples'] : array();
  }

  public static function pan() {
    if (self::$pan === null) {
      self::$pan = self::open(self::dir() . '/pangenome/pangenes.sqlite');
    }
    return self::$pan === false ? null : self::$pan;
  }

  /* The homeolog pairs of the paralogs release (B73 v5). */
  public static function paralogs() {
    if (self::$paralogs === null) {
      self::$paralogs = self::open(self::root() . '/data/paralogs/' . ET_NAM_REFERENCE . '/paralogs.sqlite');
    }
    return self::$paralogs === false ? null : self::$paralogs;
  }

  public static function open($path) {
    if (!class_exists('SQLite3') || !is_file($path)) { return false; }
    try {
      $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
      $db->enableExceptions(true);
      $db->busyTimeout(2000);
      return $db;
    } catch (Exception $e) {
      return false;
    }
  }
}

class EtGenome {

  public $name;
  public $key;
  public $info;
  public $dir;
  private $samples = null;
  private $genes = null;
  private $rowOf = null;
  private $annot = null;
  private $go = null;
  private $stats = array();
  private $handles = array();

  public function __construct($info, $root) {
    $this->info = $info;
    $this->name = $info['genome'];
    $this->key = $info['key'];
    $this->dir = $root . '/' . $info['genome'];
  }

  public function isNam() { return !empty($this->info['nam']); }
  public function hasAssay($a) { return in_array($a, $this->info['assays'], true); }

  /* samples.json: the samples of each assay in column order, per-sample
     statistics, studies. 2.4 MB for B73 v5, read once per request. */
  public function samples() {
    if ($this->samples === null) {
      $raw = @file_get_contents($this->dir . '/samples.json');
      $this->samples = $raw === false ? array() : json_decode($raw, true);
      if (!is_array($this->samples)) { $this->samples = array(); }
    }
    return $this->samples;
  }

  public function assay($a) {
    $s = $this->samples();
    if (!isset($s['assays'][$a])) { throw new EtError('This genome has no ' . $a . ' data.', 404); }
    return $s['assays'][$a];
  }

  public function columns($a) {
    return count($this->assay($a)['samples']);
  }

  public function genes() {
    if ($this->genes === null) {
      $this->genes = @file($this->dir . '/genes.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      if (!is_array($this->genes)) { $this->genes = array(); }
    }
    return $this->genes;
  }

  public function geneAt($r) {
    $g = $this->genes();
    return isset($g[$r]) ? $g[$r] : null;
  }

  /* Exact id -> row, or null. */
  public function row($gene) {
    if ($this->rowOf === null) { $this->rowOf = array_flip($this->genes()); }
    return isset($this->rowOf[$gene]) ? $this->rowOf[$gene] : null;
  }

  public function count() { return count($this->genes()); }

  public function annot() {
    if ($this->annot === null) { $this->annot = EtData::open($this->dir . '/annot.sqlite'); }
    if ($this->annot === false) { throw new EtError('The annotation index for this genome is missing.', 500); }
    return $this->annot;
  }

  public function go() {
    if ($this->go === null) { $this->go = EtData::open($this->dir . '/go.sqlite'); }
    return $this->go === false ? null : $this->go;
  }

  /* Per-gene statistics, 0-indexed: row * 8 + ET_STAT_*. */
  public function stats($a) {
    if (!isset($this->stats[$a])) {
      $raw = @file_get_contents($this->dir . '/' . $a . '.stats.f32');
      $this->stats[$a] = $raw === false ? array() : array_values(unpack('g*', $raw));
    }
    return $this->stats[$a];
  }

  public function stat($a, $r, $field) {
    $s = $this->stats($a);
    $i = $r * 8 + $field;
    return isset($s[$i]) ? $s[$i] : 0.0;
  }

  private function handle($a, $kind) {
    $k = $a . '.' . $kind;
    if (!isset($this->handles[$k])) {
      if (!in_array($kind, array('raw', 'log'), true) || !$this->hasAssay($a)) {
        throw new EtError('No ' . $a . ' matrix for this genome.', 404);
      }
      $fh = @fopen($this->dir . '/' . $a . '.' . $kind . '.f32', 'rb');
      if ($fh === false) { throw new EtError('The expression matrix for this genome is missing.', 500); }
      $this->handles[$k] = $fh;
    }
    return $this->handles[$k];
  }

  /* Some rows, each 0-indexed by column: row => array(values). */
  public function rows($a, $kind, $rows) {
    $cols = $this->columns($a);
    $fh = $this->handle($a, $kind);
    $out = array();
    $n = $this->count();
    foreach ($rows as $r) {
      $r = (int) $r;
      if ($r < 0 || $r >= $n || isset($out[$r])) { continue; }
      fseek($fh, $r * $cols * 4);
      $buf = fread($fh, $cols * 4);
      if ($buf === false || strlen($buf) !== $cols * 4) { continue; }
      $out[$r] = array_values(unpack('g' . $cols, $buf));
    }
    return $out;
  }

  public function row0($a, $kind, $r) {
    $x = $this->rows($a, $kind, array($r));
    return isset($x[$r]) ? $x[$r] : null;
  }

  /* Every row, in chunks: yields row => values keyed 1..columns (the shape
     unpack() returns, kept rather than re-indexed because this is the hot
     loop of every genome-wide analysis). */
  public function scan($a, $kind) {
    $cols = $this->columns($a);
    $fh = $this->handle($a, $kind);
    $width = $cols * 4;
    $n = $this->count();
    $fmt = 'g' . $cols;
    fseek($fh, 0);
    for ($start = 0; $start < $n; $start += ET_CHUNK_ROWS) {
      $take = min(ET_CHUNK_ROWS, $n - $start);
      $buf = fread($fh, $take * $width);
      if ($buf === false) { return; }
      $got = intdiv(strlen($buf), $width);
      for ($i = 0; $i < $got; $i++) {
        yield $start + $i => unpack($fmt, $buf, $i * $width);
      }
    }
  }
}

/* The genomes of the release and the pan-genome summary: what the page
   embeds at load and what ?action=genomes answers, from one definition. */
function etGenomesPayload() {
  $m = EtData::manifest();
  if ($m === null) { return null; }
  $list = array();
  foreach ($m['genomes'] as $g) {
    $list[] = array('genome' => $g['genome'], 'key' => $g['key'], 'short' => $g['short'], 'annotation' => $g['annotation'],
                    'release' => $g['release'], 'nam' => $g['nam'], 'nam_index' => $g['nam_index'], 'assays' => $g['assays'],
                    'samples' => $g['samples'], 'samples_usable' => isset($g['samples_usable']) ? $g['samples_usable'] : $g['samples'],
                    'studies' => $g['studies'], 'genes' => $g['genes'], 'go' => $g['go']);
  }
  $pan = null;
  if (isset($m['pangenome'])) {
    $pan = array('counts' => $m['pangenome']['counts'], 'classes' => $m['pangenome']['classes'],
                 'nam_genomes' => $m['pangenome']['nam_genomes'], 'class_rule' => $m['pangenome']['class_rule'],
                 'expressed_rule' => $m['pangenome']['expressed_rule'], 'conservation_rule' => $m['pangenome']['conservation_rule']);
  }
  return array('genomes' => $list, 'shared_samples' => $m['shared_samples'], 'generated' => $m['generated'], 'pangenome' => $pan);
}

/* ------------------------------------------------------------------------
   Small helpers
   ------------------------------------------------------------------------ */

/* A float32 read back as a double carries noise (7.840000152587891); the
   releases hold four significant digits, so that is what goes out. */
function etRound($v) {
  if ($v === null || $v < 0) { return null; }
  if ($v == 0) { return 0; }
  return (float) sprintf('%.4g', $v);
}

function etLog2p1($v) {
  return log($v + 1, 2);
}

/* "1-40,52,60-62" <-> [1..40, 52, 60, 61, 62] */
function etParseIds($spec, $max = 5000) {
  $out = array();
  foreach (preg_split('/[\s,;|]+/', trim((string) $spec)) as $part) {
    if ($part === '') { continue; }
    if (preg_match('/^(\d+)-(\d+)$/', $part, $m)) {
      /* Ids are small: a bound before the loop, since an end at PHP's
         integer maximum overflowed the counter and never finished. */
      if (strlen($m[1]) > 7 || strlen($m[2]) > 7) { throw new EtError('Sample ids are small whole numbers.'); }
      $a = (int) $m[1]; $b = (int) $m[2];
      if ($b < $a) { list($a, $b) = array($b, $a); }
      if ($b - $a > $max) { throw new EtError('Sample range too long.'); }
      for ($i = $a; $i <= $b; $i++) { $out[$i] = true; }
    } elseif (ctype_digit($part)) {
      if (strlen($part) > 7) { throw new EtError('Sample ids are small whole numbers.'); }
      $out[(int) $part] = true;
    } else {
      throw new EtError('Samples are given as ids and ranges, e.g. 1-40,52.');
    }
    if (count($out) > $max) { throw new EtError('Too many samples.'); }
  }
  $ids = array_keys($out);
  sort($ids);
  return $ids;
}

function etCompressIds($ids) {
  sort($ids);
  $out = array();
  $start = $prev = null;
  foreach ($ids as $i) {
    if ($prev !== null && $i === $prev + 1) { $prev = $i; continue; }
    if ($start !== null) { $out[] = $start === $prev ? (string) $start : $start . '-' . $prev; }
    $start = $prev = $i;
  }
  if ($start !== null) { $out[] = $start === $prev ? (string) $start : $start . '-' . $prev; }
  return implode(',', $out);
}

/* A sample every default selection uses: it was measured, and something in
   it is above zero. A sample that is zero in every gene is a failed load
   (the index builder reports those), not a tissue where nothing is on. */
function etSampleUsable($A, $j) {
  $st = $A['sample_stats'][$j];
  return $st['n'] > 0 && $st['gt0'] > 0;
}

/* The selected columns (0-based) of an assay. '' means the default: every
   usable sample. */
function etColumns($G, $a, $spec) {
  $A = $G->assay($a);
  $cols = array();
  $spec = trim((string) $spec);
  if ($spec === '' || $spec === 'all') {
    foreach ($A['samples'] as $j => $s) {
      if (etSampleUsable($A, $j)) { $cols[] = $j; }
    }
    return array('cols' => $cols, 'default' => true, 'unknown' => array());
  }
  $byId = array();
  foreach ($A['samples'] as $j => $s) { $byId[$s['id']] = $j; }
  $unknown = array();
  foreach (etParseIds($spec) as $id) {
    if (isset($byId[$id])) { $cols[] = $byId[$id]; } else { $unknown[] = $id; }
  }
  sort($cols);
  return array('cols' => array_values(array_unique($cols)), 'default' => false, 'unknown' => $unknown);
}

function etSampleIds($G, $a, $cols) {
  $A = $G->assay($a);
  $out = array();
  foreach ($cols as $c) { $out[] = $A['samples'][$c]['id']; }
  return $out;
}

/* Placeholders for an IN list of $n values. */
function etPlaceholders($n, $prefix = 'p') {
  $ph = array();
  for ($i = 0; $i < $n; $i++) { $ph[] = ':' . $prefix . $i; }
  return implode(',', $ph);
}

function etQueryIn($db, $sql, $values, $type = SQLITE3_TEXT, $extra = array()) {
  $out = array();
  foreach (array_chunk(array_values($values), 400) as $chunk) {
    $stmt = $db->prepare(str_replace('{IN}', etPlaceholders(count($chunk)), $sql));
    if ($stmt === false) { throw new EtError('Query failed.', 500); }
    foreach ($chunk as $i => $v) { $stmt->bindValue(':p' . $i, $v, $type); }
    foreach ($extra as $k => $v) { $stmt->bindValue($k, $v[0], $v[1]); }
    $res = $stmt->execute();
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) { $out[] = $row; }
  }
  return $out;
}

/* Yanai's tissue-specificity index on log2(value + 1), over present values;
   null unless something reaches the detection threshold. */
function etTau($logs, $threshold = 1.0) {
  $xs = array();
  foreach ($logs as $v) { if ($v !== null && $v >= 0) { $xs[] = $v; } }
  $n = count($xs);
  if ($n < 2) { return null; }
  $mx = max($xs);
  if ($mx < etLog2p1($threshold) || $mx <= 0) { return null; }
  $s = 0.0;
  foreach ($xs as $v) { $s += 1 - $v / $mx; }
  return round($s / ($n - 1), 3);
}

function etPearson($x, $y) {
  $n = count($x);
  if ($n < 3) { return null; }
  $sx = $sy = $sxx = $syy = $sxy = 0.0;
  for ($i = 0; $i < $n; $i++) {
    $a = $x[$i]; $b = $y[$i];
    $sx += $a; $sy += $b; $sxx += $a * $a; $syy += $b * $b; $sxy += $a * $b;
  }
  $vx = $sxx - $sx * $sx / $n;
  $vy = $syy - $sy * $sy / $n;
  if ($vx <= 1e-12 || $vy <= 1e-12) { return null; }
  return ($sxy - $sx * $sy / $n) / sqrt($vx * $vy);
}

/* Average ranks, ties sharing the mean rank. */
function etRanks($x) {
  asort($x, SORT_NUMERIC);
  $keys = array_keys($x);
  $vals = array_values($x);
  $n = count($vals);
  $out = array();
  for ($i = 0; $i < $n;) {
    $j = $i;
    while ($j + 1 < $n && $vals[$j + 1] == $vals[$i]) { $j++; }
    $rank = ($i + $j) / 2 + 1;
    for ($t = $i; $t <= $j; $t++) { $out[$keys[$t]] = $rank; }
    $i = $j + 1;
  }
  return $out;
}

/* ------------------------------------------------------------------------
   Genes: annotation, resolution, search
   ------------------------------------------------------------------------ */

define('ET_GENE_COLUMNS', 'row, gene, symbol, full_name, description, biotype, seq, start, "end", strand, transcripts, canonical_transcript, protein_length, pan, b73, b73_symbol');

/* A symbol worth showing, or null. The gene-models release gives 2,567 B73
   v5 genes a "symbol" that is a gene id -- the gene's own (618) or a v3 or
   v4 id (1,949), which is how the locus table names a locus with no symbol.
   Shown beside the id they read as a second gene. They stay searchable:
   the search reads symbol_lc and the aliases table, not this. */
function etSymbol($symbol, $gene) {
  if ($symbol === null || $symbol === '') { return null; }
  if ($gene !== null && strcasecmp($symbol, $gene) === 0) { return null; }
  if (preg_match('/^(Zm\d{5}[a-z]{1,2}\d{6}|GRMZM\d?G\d{6}|[A-Z]{2}\d{6}\.\d_FG\d{3})$/i', $symbol)) { return null; }
  return $symbol;
}

function etGeneRecord($r) {
  $out = array(
    'row' => (int) $r['row'],
    'gene' => $r['gene'],
    'symbol' => etSymbol($r['symbol'], $r['gene']),
    'name' => $r['full_name'],
    'description' => $r['description'],
    'biotype' => $r['biotype'],
    'chr' => $r['seq'],
    'start' => $r['start'] === null ? null : (int) $r['start'],
    'end' => $r['end'] === null ? null : (int) $r['end'],
    'strand' => $r['strand'],
    'transcripts' => $r['transcripts'] === null ? null : (int) $r['transcripts'],
    'pan' => $r['pan'] === null ? null : (int) $r['pan'],
    'b73' => $r['b73'],
    'b73_symbol' => etSymbol($r['b73_symbol'], $r['b73'])
  );
  if ($r['canonical_transcript'] !== null) { $out['canonical_transcript'] = $r['canonical_transcript']; }
  if ($r['protein_length'] !== null) { $out['protein_length'] = (int) $r['protein_length']; }
  return $out;
}

/* row => gene record, for the rows given. */
function etGeneInfo($G, $rows) {
  $rows = array_values(array_unique(array_map('intval', $rows)));
  $out = array();
  if (!$rows) { return $out; }
  foreach (etQueryIn($G->annot(), 'SELECT ' . ET_GENE_COLUMNS . ' FROM genes WHERE row IN ({IN})', $rows, SQLITE3_INTEGER) as $r) {
    $out[(int) $r['row']] = etGeneRecord($r);
  }
  return $out;
}

/* What a reader typed -> rows. Tried in order, each step only for what the
   step before left unresolved:
     1. the genome's own gene id, ignoring case
     2. an alias: transcript, protein, previous id, and on B73 v5 the v4 and
        v3 ids that share a pan-gene 1:1 with a v5 gene
     3. the id with a transcript or protein suffix taken off (_T001, _P002, .1)
     4. a gene symbol (lg1), and on every other genome the symbol or id of
        the B73 v5 counterpart in the same pan-gene
   A symbol shared by several genes resolves to all of them and is listed
   under ambiguous. */
function etResolve($G, $inputs, $max = 5000) {
  $db = $G->annot();
  $want = array();
  foreach ($inputs as $in) {
    $in = trim((string) $in);
    if ($in === '' || strlen($in) > 100) { continue; }
    $want[$in] = strtolower($in);
    if (count($want) > $max) { throw new EtError('Too many identifiers (at most ' . $max . ').'); }
  }
  $found = array();       // input => [rows]
  $via = array();         // input => how
  $pending = $want;

  $step = function ($sql, $keys, $how, $keyCol) use ($db, &$found, &$via, &$pending) {
    if (!$keys) { return; }
    $byKey = array();
    foreach (etQueryIn($db, $sql, array_values(array_unique($keys))) as $r) {
      $byKey[$r[$keyCol]][] = (int) $r['row'];
    }
    foreach ($pending as $in => $lc) {
      $k = isset($keys[$in]) ? $keys[$in] : null;
      if ($k !== null && isset($byKey[$k])) {
        $found[$in] = array_values(array_unique($byKey[$k]));
        $via[$in] = $how;
        unset($pending[$in]);
      }
    }
  };

  $step('SELECT row, gene_lc FROM genes WHERE gene_lc IN ({IN})', $pending, 'id', 'gene_lc');
  $step('SELECT row, alias_lc, kind FROM aliases WHERE alias_lc IN ({IN})', $pending, 'alias', 'alias_lc');
  $stripped = array();
  foreach ($pending as $in => $lc) {
    $s = preg_replace('/(_[tp]\d+|\.\d+|-r[a-z]|-p[a-z])$/', '', $lc);
    if ($s !== $lc) { $stripped[$in] = $s; }
  }
  $step('SELECT row, gene_lc FROM genes WHERE gene_lc IN ({IN})', $stripped, 'id without suffix', 'gene_lc');
  $step('SELECT row, symbol_lc FROM genes WHERE symbol_lc IN ({IN})', $pending, 'symbol', 'symbol_lc');
  if ($G->name !== ET_NAM_REFERENCE) {
    $step('SELECT row, b73_symbol_lc FROM genes WHERE b73_symbol_lc IN ({IN})', $pending, 'B73 v5 symbol', 'b73_symbol_lc');
    $b73 = array();
    foreach ($pending as $in => $lc) {
      if (preg_match('/^zm00001eb\d+/', $lc, $m)) { $b73[$in] = $m[0]; }
    }
    if ($b73) {
      $rows = array();
      /* The column holds ids as published (Zm00001eb...), and the index is on
         that spelling. */
      foreach (etQueryIn($db, 'SELECT row, lower(b73) AS b FROM genes WHERE b73 IN ({IN})',
                         array_map(function ($x) { return 'Zm' . substr($x, 2); }, array_values($b73))) as $r) {
        $rows[$r['b']][] = (int) $r['row'];
      }
      foreach ($b73 as $in => $k) {
        if (isset($rows[$k])) { $found[$in] = $rows[$k]; $via[$in] = 'B73 v5 counterpart'; unset($pending[$in]); }
      }
    }
  }

  $map = array();
  $ambiguous = array();
  $rowsAll = array();
  foreach ($found as $in => $rows) {
    $map[$in] = $rows;
    if (count($rows) > 1) { $ambiguous[] = $in; }
    foreach ($rows as $r) { $rowsAll[$r] = true; }
  }
  return array('map' => $map, 'via' => $via, 'missing' => array_keys($pending), 'ambiguous' => $ambiguous,
               'rows' => array_keys($rowsAll));
}

/* The first row a single input resolves to, or an error naming what was
   tried. */
function etResolveOne($G, $input) {
  $input = trim((string) $input);
  if ($input === '') { throw new EtError('Enter a gene.'); }
  $r = etResolve($G, array($input));
  if (!isset($r['map'][$input])) {
    throw new EtError('No gene in the ' . $G->info['short'] . ' release matches "' . $input . '".', 404);
  }
  return $r['map'][$input][0];
}

/* Typeahead: exact matches first, then id and symbol prefixes, then words in
   names and descriptions. */
function etSearch($G, $q, $limit = 12) {
  $q = trim((string) $q);
  $lc = strtolower($q);
  if (strlen($lc) < 2 || strlen($lc) > 80) { return array(); }
  $db = $G->annot();
  $hits = array();   // row => match label
  $add = function ($sql, $params, $label) use ($db, &$hits, $limit) {
    if (count($hits) >= $limit) { return; }
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v, SQLITE3_TEXT); }
    $res = $stmt->execute();
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
      $row = (int) $r['row'];
      if (!isset($hits[$row])) { $hits[$row] = $label === null ? (isset($r['m']) ? $r['m'] : null) : $label; }
      if (count($hits) >= $limit) { break; }
    }
  };
  $hi = $lc . '~';
  $add('SELECT row FROM genes WHERE gene_lc = :q', array(':q' => $lc), 'gene id');
  $add('SELECT row FROM genes WHERE symbol_lc = :q', array(':q' => $lc), 'symbol');
  $add("SELECT row, kind || ' ' || alias AS m FROM aliases WHERE alias_lc = :q", array(':q' => $lc), null);
  if ($G->name !== ET_NAM_REFERENCE) {
    $add("SELECT row, 'B73 v5 ' || b73_symbol AS m FROM genes WHERE b73_symbol_lc = :q", array(':q' => $lc), null);
  }
  $add('SELECT row FROM genes WHERE gene_lc >= :q AND gene_lc < :hi ORDER BY gene_lc LIMIT 20', array(':q' => $lc, ':hi' => $hi), 'gene id');
  $add('SELECT row FROM genes WHERE symbol_lc >= :q AND symbol_lc < :hi ORDER BY length(symbol_lc), symbol_lc LIMIT 20', array(':q' => $lc, ':hi' => $hi), 'symbol');
  $add("SELECT row, kind || ' ' || alias AS m FROM aliases WHERE alias_lc >= :q AND alias_lc < :hi ORDER BY alias_lc LIMIT 20", array(':q' => $lc, ':hi' => $hi), null);
  if (count($hits) < $limit) {
    $words = array();
    foreach (preg_split('/[^A-Za-z0-9]+/', $q) as $w) {
      if (strlen($w) >= 2) { $words[] = '"' . str_replace('"', '', $w) . '"*'; }
    }
    if ($words) {
      try {
        $add('SELECT rowid AS row FROM genes_fts WHERE genes_fts MATCH :q LIMIT 40', array(':q' => implode(' ', array_slice($words, 0, 6))), 'text');
      } catch (Exception $e) { /* a query FTS cannot parse is simply no text match */ }
    }
  }
  $info = etGeneInfo($G, array_keys($hits));
  $out = array();
  foreach ($hits as $row => $label) {
    if (!isset($info[$row])) { continue; }
    $out[] = $info[$row] + array('match' => $label);
  }
  return $out;
}

/* Values of some genes in one assay, aligned with the assay's columns;
   null where not measured. */
function etValues($G, $a, $rows) {
  $out = array();
  foreach ($G->rows($a, 'raw', $rows) as $r => $vals) {
    $out[$r] = array_map('etRound', $vals);
  }
  return $out;
}

/* ------------------------------------------------------------------------
   The sample catalog, as the client needs it
   ------------------------------------------------------------------------ */

function etCatalog($G) {
  $s = $G->samples();
  $assays = array();
  foreach ($s['assays'] as $a => $A) {
    $samples = array();
    foreach ($A['samples'] as $j => $x) {
      $st = $A['sample_stats'][$j];
      $samples[] = array(
        'id' => $x['id'], 'label' => $x['label'], 'stub' => $x['stub'], 'study' => $x['source_id'],
        'tissue' => $x['tissue'], 'condition' => $x['condition'],
        'n' => $st['n'], 'ge1' => $st['ge1'], 'gt0' => $st['gt0'], 'mean' => $st['mean'], 'median' => $st['median'],
        'q' => $st['q'], 'usable' => etSampleUsable($A, $j)
      );
    }
    $assays[$a] = array('samples' => $samples, 'profiles' => $A['profiles'], 'rows' => $A['rows'],
                        'complete_rows' => $A['complete_rows'], 'duplicates' => $A['duplicates'],
                        'dead' => isset($A['dead']) ? $A['dead'] : array(),
                        'detected_rule' => $A['detected_rule']);
  }
  $studies = array();
  foreach ($s['studies'] as $st) { $studies[] = $st; }
  $sharedIds = array();
  if (!empty($s['shared_columns']) && isset($s['assays']['rna'])) {
    foreach ($s['shared_columns'] as $c) { $sharedIds[] = $s['assays']['rna']['samples'][$c]['id']; }
  }
  $disagreements = isset($G->info['disagreements']) ? $G->info['disagreements'] : array();
  return array(
    'genome' => $G->name, 'key' => $G->key, 'short' => $G->info['short'], 'release' => $G->info['release'],
    'annotation' => $G->info['annotation'], 'genes' => $G->count(), 'nam' => $G->isNam(),
    'assays' => $assays, 'studies' => $studies, 'shared_sample_ids' => $sharedIds,
    'units_note' => $s['units_note'], 'tissue_note' => $s['tissue_note'], 'condition_note' => $s['condition_note'],
    'data_checks' => $disagreements, 'go' => !empty($G->info['go']),
    'sequences' => array_map(function ($name, $x) { return array('name' => $name, 'genes' => $x['genes'], 'length' => $x['max']); },
                             array_keys(etSequences($G)), array_values(etSequences($G)))
  );
}

/* ------------------------------------------------------------------------
   Co-expression

   Pearson (or Spearman) on log2(value + 1) of one gene against every other
   gene of the genome, over the selected samples both genes were measured
   in. A pair needs half the query's selected samples in common (and at
   least five), so a gene measured in a single study cannot top the list on
   a handful of points. Optionally centered within each study first: the
   studies were quantified separately and in different units, so centering
   removes each study's offset and keeps only the variation inside it.
   ------------------------------------------------------------------------ */

function etCoexpression($G, $a, $row, $cols, $opts) {
  $method = $opts['method'] === 'spearman' ? 'spearman' : 'pearson';
  $top = max(1, min(500, (int) $opts['n']));
  $minLog = etLog2p1(max(0.0, (float) $opts['min']));
  $center = !empty($opts['center']);
  $A = $G->assay($a);

  $q = $G->row0($a, 'log', $row);
  if ($q === null) { throw new EtError('The gene has no values in this release.', 404); }
  $Q = array();
  $qy = array();
  $studyOf = array();
  foreach ($cols as $c) {
    if ($q[$c] >= 0) {
      $Q[] = $c + 1;
      $qy[$c + 1] = $q[$c];
      $studyOf[$c + 1] = $A['samples'][$c]['source_id'];
    }
  }
  $k = count($Q);
  if ($k < 5) {
    throw new EtError('The gene has values in ' . $k . ' of the selected samples; co-expression needs at least 5.');
  }
  $minOverlap = max(5, (int) ceil($k / 2));
  if ($method === 'spearman' && !$center) {
    $qr = etRanks($qy);
  }

  $R = array();
  $N = array();
  $skipped = array('overlap' => 0, 'low' => 0, 'flat' => 0);
  foreach ($G->scan($a, 'log') as $r => $v) {
    if ($r === $row) { continue; }
    if ($method === 'pearson' && !$center) {
      $n = 0; $sx = $sy = $sxx = $syy = $sxy = 0.0; $mx = -1.0;
      foreach ($Q as $j) {
        $x = $v[$j];
        if ($x < 0) { continue; }
        $y = $qy[$j];
        $n++; $sx += $x; $sy += $y; $sxx += $x * $x; $syy += $y * $y; $sxy += $x * $y;
        if ($x > $mx) { $mx = $x; }
      }
      if ($n < $minOverlap) { $skipped['overlap']++; continue; }
      if ($mx < $minLog) { $skipped['low']++; continue; }
      $vx = $sxx - $sx * $sx / $n;
      $vy = $syy - $sy * $sy / $n;
      if ($vx <= 1e-9 || $vy <= 1e-9) { $skipped['flat']++; continue; }
      $R[$r] = ($sxy - $sx * $sy / $n) / sqrt($vx * $vy);
      $N[$r] = $n;
      continue;
    }
    // Spearman, or centered within study: the overlap first, then the sums.
    $xs = array(); $ys = array(); $mx = -1.0;
    foreach ($Q as $j) {
      $x = $v[$j];
      if ($x < 0) { continue; }
      $xs[$j] = $x; $ys[$j] = $qy[$j];
      if ($x > $mx) { $mx = $x; }
    }
    $n = count($xs);
    if ($n < $minOverlap) { $skipped['overlap']++; continue; }
    if ($mx < $minLog) { $skipped['low']++; continue; }
    if ($center) {
      $sumx = $sumy = $cnt = array();
      foreach ($xs as $j => $x) {
        $s = $studyOf[$j];
        if (!isset($cnt[$s])) { $cnt[$s] = 0; $sumx[$s] = 0.0; $sumy[$s] = 0.0; }
        $cnt[$s]++; $sumx[$s] += $x; $sumy[$s] += $ys[$j];
      }
      foreach ($xs as $j => $x) {
        $s = $studyOf[$j];
        $xs[$j] = $x - $sumx[$s] / $cnt[$s];
        $ys[$j] = $ys[$j] - $sumy[$s] / $cnt[$s];
      }
    }
    if ($method === 'spearman') {
      $xs = etRanks($xs);
      $ys = ($center || $n !== $k) ? etRanks($ys) : $qr;
    }
    $sx = $sy = $sxx = $syy = $sxy = 0.0;
    foreach ($xs as $j => $x) {
      $y = $ys[$j];
      $sx += $x; $sy += $y; $sxx += $x * $x; $syy += $y * $y; $sxy += $x * $y;
    }
    $vx = $sxx - $sx * $sx / $n;
    $vy = $syy - $sy * $sy / $n;
    if ($vx <= 1e-9 || $vy <= 1e-9) { $skipped['flat']++; continue; }
    $R[$r] = ($sxy - $sx * $sy / $n) / sqrt($vx * $vy);
    $N[$r] = $n;
  }

  $hist = array_fill(0, 40, 0);
  foreach ($R as $x) { $hist[min(39, max(0, (int) floor(($x + 1) * 20)))]++; }
  arsort($R);
  $pos = array_slice($R, 0, $top, true);
  asort($R);
  $neg = array_slice($R, 0, $top, true);
  $info = etGeneInfo($G, array_merge(array($row), array_keys($pos), array_keys($neg)));
  $fmt = function ($set) use ($info, $N) {
    $out = array();
    foreach ($set as $r => $x) {
      $g = isset($info[$r]) ? $info[$r] : array('row' => $r);
      $out[] = array('row' => $r, 'gene' => isset($g['gene']) ? $g['gene'] : null,
                     'symbol' => isset($g['symbol']) ? $g['symbol'] : null,
                     'b73_symbol' => isset($g['b73_symbol']) ? $g['b73_symbol'] : null,
                     'name' => isset($g['name']) ? $g['name'] : null,
                     'r' => round(max(-1, min(1, $x)), 4), 'n' => $N[$r]);
    }
    return $out;
  };
  return array(
    'query' => isset($info[$row]) ? $info[$row] : array('row' => $row),
    'method' => $method, 'centered' => $center,
    'samples_selected' => count($cols), 'samples_used' => $k, 'min_overlap' => $minOverlap,
    'tested' => count($R), 'skipped' => $skipped,
    'positive' => $fmt($pos), 'negative' => $fmt($neg),
    'histogram' => array('from' => -1, 'width' => 0.05, 'counts' => $hist)
  );
}

/* ------------------------------------------------------------------------
   Tissue-specific genes

   Genes whose expression is concentrated in a target set of samples, from
   raw means over the samples each gene was measured in:
     specificity = log2((target mean + 1) / (highest background + 1))
     enrichment  = log2((target mean + 1) / (background mean + 1))
   and "off in target" mirrors both against the lowest background sample.
   ------------------------------------------------------------------------ */

function etSpecific($G, $a, $target, $background, $opts) {
  $metric = $opts['metric'] === 'enrichment' ? 'enrichment' : 'specificity';
  $down = $opts['direction'] === 'down';
  $minExpr = max(0.0, (float) $opts['min']);
  $top = max(1, min(2000, (int) $opts['n']));
  $T = array(); foreach ($target as $c) { $T[] = $c + 1; }
  $inT = array_flip($target);
  $B = array(); foreach ($background as $c) { if (!isset($inT[$c])) { $B[] = $c + 1; } }
  if (!$T) { throw new EtError('Choose at least one target sample.'); }
  if (!$B) { throw new EtError('The background is empty: the selection must include samples outside the target.'); }

  $score = array();
  $keep = array();
  $noValues = 0;
  foreach ($G->scan($a, 'raw') as $r => $v) {
    $ts = 0.0; $tn = 0;
    foreach ($T as $j) { $x = $v[$j]; if ($x >= 0) { $ts += $x; $tn++; } }
    if (!$tn) { $noValues++; continue; }
    $bs = 0.0; $bn = 0; $bmax = -1.0; $bmin = INF;
    foreach ($B as $j) {
      $x = $v[$j];
      if ($x < 0) { continue; }
      $bs += $x; $bn++;
      if ($x > $bmax) { $bmax = $x; }
      if ($x < $bmin) { $bmin = $x; }
    }
    if (!$bn) { $noValues++; continue; }
    $tm = $ts / $tn;
    $bm = $bs / $bn;
    if ($down) {
      if ($bm < $minExpr) { continue; }
      $s = $metric === 'enrichment' ? log(($tm + 1) / ($bm + 1), 2) : log(($tm + 1) / ($bmin + 1), 2);
    } else {
      if ($tm < $minExpr) { continue; }
      $s = $metric === 'enrichment' ? log(($tm + 1) / ($bm + 1), 2) : log(($tm + 1) / ($bmax + 1), 2);
    }
    $score[$r] = $s;
    $keep[$r] = array($tm, $bm, $bmax, $bmin, $tn, $bn);
  }
  if ($down) { asort($score); } else { arsort($score); }
  $passing = 0;
  foreach ($score as $s) { if ($down ? $s <= -1 : $s >= 1) { $passing++; } }
  $sel = array_slice($score, 0, $top, true);
  $info = etGeneInfo($G, array_keys($sel));
  $logs = $G->rows($a, 'log', array_keys($sel));
  $all = array_merge($T, $B);
  $out = array();
  foreach ($sel as $r => $s) {
    list($tm, $bm, $bmax, $bmin, $tn, $bn) = $keep[$r];
    $lv = array();
    foreach ($all as $j) { $lv[] = $logs[$r][$j - 1]; }
    $g = isset($info[$r]) ? $info[$r] : array();
    $out[] = array('row' => $r, 'gene' => isset($g['gene']) ? $g['gene'] : null,
                   'symbol' => isset($g['symbol']) ? $g['symbol'] : null,
                   'b73_symbol' => isset($g['b73_symbol']) ? $g['b73_symbol'] : null,
                   'name' => isset($g['name']) ? $g['name'] : null,
                   'score' => round($s, 3), 'target_mean' => etRound($tm), 'background_mean' => etRound($bm),
                   'background_max' => etRound($bmax), 'background_min' => etRound($bmin),
                   'target_n' => $tn, 'background_n' => $bn, 'tau' => etTau($lv));
  }
  return array('metric' => $metric, 'direction' => $down ? 'down' : 'up', 'min' => $minExpr,
               'target' => count($T), 'background' => count($B), 'tested' => count($score),
               'not_measured' => $noValues, 'passing' => $passing, 'genes' => $out);
}

/* ------------------------------------------------------------------------
   Two groups of samples: every gene's mean in each
   ------------------------------------------------------------------------ */

function etContrast($G, $a, $groupA, $groupB) {
  $A = array(); foreach ($groupA as $c) { $A[] = $c + 1; }
  $B = array(); foreach ($groupB as $c) { $B[] = $c + 1; }
  if (!$A || !$B) { throw new EtError('Both groups need at least one sample.'); }
  if (array_intersect($A, $B)) { throw new EtError('A sample cannot be in both groups.'); }
  $genes = $ma = $mb = array();
  $zero = $missing = 0;
  $names = $G->genes();
  foreach ($G->scan($a, 'raw') as $r => $v) {
    $sa = 0.0; $na = 0; $sb = 0.0; $nb = 0;
    foreach ($A as $j) { $x = $v[$j]; if ($x >= 0) { $sa += $x; $na++; } }
    foreach ($B as $j) { $x = $v[$j]; if ($x >= 0) { $sb += $x; $nb++; } }
    if (!$na || !$nb) { $missing++; continue; }
    $x = $sa / $na; $y = $sb / $nb;
    if ($x <= 0 && $y <= 0) { $zero++; continue; }
    $genes[] = $names[$r];
    $ma[] = etRound($x);
    $mb[] = etRound($y);
  }
  return array('genes' => $genes, 'a' => $ma, 'b' => $mb, 'zero_in_both' => $zero, 'not_measured' => $missing,
               'n_a' => count($A), 'n_b' => count($B));
}

/* ------------------------------------------------------------------------
   The most variable genes over a selection, as a genes x samples matrix of
   log values (the input to the sample map). A gene missing more than a fifth
   of the selection is left out; one missing less has the gaps filled with
   its own mean over the selection, so the matrix is complete.
   ------------------------------------------------------------------------ */

function etVariable($G, $a, $cols, $opts) {
  $top = max(10, min(5000, (int) $opts['n']));
  $minLog = etLog2p1(max(0.0, (float) $opts['min']));
  $center = !empty($opts['center']);
  $k = count($cols);
  if ($k < 3) { throw new EtError('The sample map needs at least 3 samples; the selection has ' . $k . '.'); }
  $C = array(); foreach ($cols as $c) { $C[] = $c + 1; }
  $A = $G->assay($a);
  $study = array();
  foreach ($cols as $c) { $study[] = $A['samples'][$c]['source_id']; }
  $maxMissing = (int) floor($k / 5);
  $var = array();
  foreach ($G->scan($a, 'log') as $r => $v) {
    $s = $ss = 0.0; $n = 0;
    foreach ($C as $j) { $x = $v[$j]; if ($x >= 0) { $s += $x; $ss += $x * $x; $n++; } }
    if ($k - $n > $maxMissing || $n < 3) { continue; }
    $mean = $s / $n;
    if ($mean < $minLog) { continue; }
    if ($center) {
      /* Ranked on the centered values, the same ones the map is drawn from:
         a gene flat within every study but at different levels between them
         is a difference of units, not a variable gene. */
      $sum = $cnt = array();
      foreach ($C as $i => $j) { $x = $v[$j]; if ($x >= 0) { $st = $study[$i]; $sum[$st] = (isset($sum[$st]) ? $sum[$st] : 0) + $x; $cnt[$st] = (isset($cnt[$st]) ? $cnt[$st] : 0) + 1; } }
      $cs = $css = 0.0;
      foreach ($C as $i => $j) { $x = $v[$j]; if ($x >= 0) { $y = $x - $sum[$study[$i]] / $cnt[$study[$i]]; $cs += $y; $css += $y * $y; } }
      $var[$r] = $css / $n - ($cs / $n) * ($cs / $n);
    } else {
      $var[$r] = $ss / $n - $mean * $mean;
    }
  }
  arsort($var);
  $sel = array_slice($var, 0, $top, true);
  $rows = $G->rows($a, 'log', array_keys($sel));
  $info = etGeneInfo($G, array_keys($sel));
  $values = array();
  $genes = array();
  foreach ($sel as $r => $x) {
    $row = array();
    $present = array();
    foreach ($cols as $c) { $y = $rows[$r][$c]; $row[] = $y; if ($y >= 0) { $present[] = $y; } }
    $mean = array_sum($present) / count($present);
    foreach ($row as $i => $y) { if ($y < 0) { $row[$i] = $mean; } }
    if ($center) {
      $sum = $cnt = array();
      foreach ($row as $i => $y) { $s = $study[$i]; $sum[$s] = (isset($sum[$s]) ? $sum[$s] : 0) + $y; $cnt[$s] = (isset($cnt[$s]) ? $cnt[$s] : 0) + 1; }
      foreach ($row as $i => $y) { $s = $study[$i]; $row[$i] = $y - $sum[$s] / $cnt[$s]; }
    }
    $values[] = array_map(function ($y) { return round($y, 3); }, $row);
    $genes[] = isset($info[$r]) ? $info[$r]['gene'] : $G->geneAt($r);
  }
  return array('samples' => etSampleIds($G, $a, $cols), 'genes' => $genes, 'values' => $values,
               'candidates' => count($var), 'centered' => $center);
}

/* ------------------------------------------------------------------------
   Genes in a region
   ------------------------------------------------------------------------ */

function etSequences($G) {
  static $cache = array();
  if (!isset($cache[$G->name])) {
    $res = $G->annot()->query('SELECT seq, count(*) AS n, max("end") AS mx FROM genes WHERE seq IS NOT NULL GROUP BY seq');
    $out = array();
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $out[$r['seq']] = array('genes' => (int) $r['n'], 'max' => (int) $r['mx']); }
    uksort($out, 'strnatcasecmp');
    $cache[$G->name] = $out;
  }
  return $cache[$G->name];
}

function etInterval($G, $seq, $start, $end, $within, $limit) {
  $seqs = etSequences($G);
  $want = null;
  foreach (array($seq, 'chr' . $seq, preg_replace('/^chr/i', '', $seq)) as $cand) {
    foreach ($seqs as $name => $x) { if (strcasecmp($name, $cand) === 0) { $want = $name; break 2; } }
  }
  if ($want === null) { throw new EtError('No sequence named "' . $seq . '" carries genes in this release.', 404); }
  if ($end < $start) { throw new EtError('The end must not come before the start.'); }
  $sql = 'SELECT ' . ET_GENE_COLUMNS . ' FROM genes WHERE seq = :s AND ' .
         ($within ? 'start >= :a AND "end" <= :b' : '"end" >= :a AND start <= :b') .
         ' ORDER BY start LIMIT ' . ((int) $limit + 1);
  $stmt = $G->annot()->prepare($sql);
  $stmt->bindValue(':s', $want, SQLITE3_TEXT);
  $stmt->bindValue(':a', (int) $start, SQLITE3_INTEGER);
  $stmt->bindValue(':b', (int) $end, SQLITE3_INTEGER);
  $res = $stmt->execute();
  $genes = array();
  while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $genes[] = etGeneRecord($r); }
  $truncated = count($genes) > $limit;
  return array('chr' => $want, 'start' => (int) $start, 'end' => (int) $end, 'within' => (bool) $within,
               'genes' => array_slice($genes, 0, $limit), 'truncated' => $truncated, 'limit' => (int) $limit,
               'sequence_length' => $seqs[$want]['max']);
}

/* ------------------------------------------------------------------------
   One gene, for the gene report: everything but the genome-wide passes,
   which the page asks for separately.
   ------------------------------------------------------------------------ */

function etGeneReport($G, $row) {
  $info = etGeneInfo($G, array($row));
  if (!isset($info[$row])) { throw new EtError('Unknown gene.', 404); }
  $out = array('gene' => $info[$row], 'values' => array(), 'stats' => array());
  foreach ($G->info['assays'] as $a) {
    $v = etValues($G, $a, array($row));
    $out['values'][$a] = isset($v[$row]) ? $v[$row] : null;
    $out['stats'][$a] = array(
      'present' => (int) $G->stat($a, $row, ET_STAT_PRESENT),
      'detected' => (int) $G->stat($a, $row, ET_STAT_DETECTED),
      'max' => etRound($G->stat($a, $row, ET_STAT_MAX)),
      'mean_log' => round($G->stat($a, $row, ET_STAT_MEAN), 4)
    );
  }
  $out['go'] = etGeneGo($G, $row);
  $out['homeolog'] = $G->name === ET_NAM_REFERENCE ? etHomeologOf($info[$row]['gene']) : null;
  $out['pangene'] = $info[$row]['pan'] !== null ? etPanSummary($info[$row]['pan']) : null;
  return $out;
}

/* Where one gene ranks among all genes in each sample: its percentile
   against the sample's 101 quantiles from the index (the arithmetic the
   gene report applies to the catalog), and the samples where it ranks
   highest. The gene record asks here rather than load the catalog. */
function etPercentile($v, $q) {
  if ($v === null || $v < 0 || !is_array($q) || count($q) < 101) { return null; }
  if ($v <= $q[0]) { return 0.0; }
  if ($v >= $q[100]) { return 100.0; }
  $lo = 0; $hi = 100;
  while ($hi - $lo > 1) { $m = ($lo + $hi) >> 1; if ($q[$m] <= $v) { $lo = $m; } else { $hi = $m; } }
  $span = $q[$hi] - $q[$lo];
  return $lo + ($span > 0 ? ($v - $q[$lo]) / $span : 0);
}

function etRank($G, $a, $row, $n) {
  $A = $G->assay($a);
  $vals = etValues($G, $a, array($row));
  if (!isset($vals[$row])) { throw new EtError('No values for this gene.', 404); }
  $v = $vals[$row];
  $out = array();
  foreach ($A['samples'] as $j => $s) {
    if (!etSampleUsable($A, $j) || !isset($v[$j]) || $v[$j] === null) { continue; }
    $p = etPercentile($v[$j], $A['sample_stats'][$j]['q']);
    if ($p === null) { continue; }
    $out[] = array('id' => $s['id'], 'label' => $s['label'], 'source' => isset($s['source']) ? $s['source'] : null,
                   'tissue' => isset($s['tissue']) ? $s['tissue'] : null, 'condition' => isset($s['condition']) ? $s['condition'] : null,
                   'value' => $v[$j], 'percentile' => round($p, 1));
  }
  usort($out, function ($x, $y) {
    if ($x['percentile'] == $y['percentile']) { return $y['value'] <=> $x['value']; }
    return $y['percentile'] <=> $x['percentile'];
  });
  $info = etGeneInfo($G, array($row));
  return array('gene' => isset($info[$row]) ? $info[$row] : null, 'assay' => $a, 'samples' => count($out),
               'best' => $out ? $out[0] : null, 'top' => array_slice($out, 0, $n));
}

function etGeneGo($G, $row) {
  $db = $G->go();
  if (!$db) { return null; }
  $stmt = $db->prepare('SELECT t.go, t.name, t.aspect FROM gene_terms g JOIN terms t ON t.id = g.term WHERE g.row = :r ORDER BY t.aspect, t.name');
  $stmt->bindValue(':r', (int) $row, SQLITE3_INTEGER);
  $res = $stmt->execute();
  $out = array();
  while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $out[] = $r; }
  return $out;
}

/* ------------------------------------------------------------------------
   Pan-genes: one pan-gene across the 26 NAM genomes, and the landscape of
   all of them.

   The rules here are the index builder's (build_pangenome in
   tools/expression_tools_index.py), applied live to one pan-gene: a genome's
   value in a shared sample is the sum over its members measured there (copy
   number counts); it expresses the pan-gene when that sum reaches 1 FPKM in
   one of the 23 shared samples, and carries it silent when it has a member
   measured there that never does; the conservation of a genome's profile is
   its Pearson r with the median profile over the expressing genomes, on
   log2(FPKM + 1), over at least eight samples both have.
   ------------------------------------------------------------------------ */

function etPanRow($where, $params) {
  $db = EtData::pan();
  if (!$db) { throw new EtError('The pan-genome index is missing.', 500); }
  $stmt = $db->prepare('SELECT * FROM pangenes WHERE ' . $where . ' LIMIT 1');
  foreach ($params as $k => $v) { $stmt->bindValue($k, $v[0], $v[1]); }
  $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
  return $row ? $row : null;
}

/* A pan-gene from its name (pan-zea.v4.pan02070, pan02070, 2070), any member
   gene model or transcript of any of the 66 annotations, or a B73 v5 symbol. */
function etPanResolve($input) {
  $input = trim((string) $input);
  if ($input === '') { throw new EtError('Enter a gene or a pan-gene.'); }
  $db = EtData::pan();
  if (!$db) { throw new EtError('The pan-genome index is missing.', 500); }
  if (preg_match('/^(?:pan-zea\.v4\.)?pan(\d+)$/i', $input, $m) || ctype_digit($input)) {
    $num = isset($m[1]) ? $m[1] : $input;
    $row = etPanRow('name = :n', array(':n' => array('pan-zea.v4.pan' . str_pad(ltrim($num, '0'), 5, '0', STR_PAD_LEFT), SQLITE3_TEXT)));
    if ($row) { return $row; }
  }
  $gene = preg_replace('/(_T\d+|_P\d+)$/i', '', $input);
  $stmt = $db->prepare('SELECT pan FROM members WHERE gene = :g COLLATE NOCASE LIMIT 1');
  $stmt->bindValue(':g', $gene, SQLITE3_TEXT);
  $hit = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
  if ($hit) { return etPanRow('id = :i', array(':i' => array((int) $hit['pan'], SQLITE3_INTEGER))); }
  $G = EtData::genome('B73v5');
  if ($G) {
    $r = etResolve($G, array($input));
    if (isset($r['map'][$input])) {
      $info = etGeneInfo($G, $r['map'][$input]);
      foreach ($info as $g) {
        if ($g['pan'] !== null) { return etPanRow('id = :i', array(':i' => array((int) $g['pan'], SQLITE3_INTEGER))); }
      }
      throw new EtError($input . ' is not in a pan-gene: about one gene model in five is not placed in one.', 404);
    }
  }
  throw new EtError('No pan-gene matches "' . $input . '".', 404);
}

function etPanRecord($p) {
  $nam = EtData::namGenomes();
  $name = function ($i) use ($nam) { return ($i !== null && isset($nam[$i])) ? $nam[$i]['short'] : null; };
  $masked = function ($mask) use ($nam) {
    $out = array();
    foreach ($nam as $g) { if (((int) $mask >> $g['index']) & 1) { $out[] = $g['short']; } }
    return $out;
  };
  return array(
    'id' => (int) $p['id'], 'name' => $p['name'], 'exemplar' => $p['exemplar'], 'exemplar_gene' => $p['exemplar_gene'],
    'chr' => $p['chr'], 'members' => (int) $p['n_members'], 'annotations' => (int) $p['n_annotations'],
    'present' => (int) $p['n_present'], 'measured' => (int) $p['n_measured'], 'expressed' => (int) $p['n_expressed'],
    'low' => (int) $p['n_low'], 'silent' => (int) $p['n_silent'], 'multi_copy' => (int) $p['n_multi'], 'class' => $p['class'],
    'conservation' => $p['conservation'] === null ? null : (float) $p['conservation'],
    'min_r' => $p['min_r'] === null ? null : (float) $p['min_r'], 'divergent' => $name($p['divergent'] === null ? null : (int) $p['divergent']),
    'level_sd' => $p['level_sd'] === null ? null : (float) $p['level_sd'],
    'max' => $p['max_raw'] === null ? null : (float) $p['max_raw'], 'tau' => $p['tau'] === null ? null : (float) $p['tau'],
    'top_sample' => $p['top_sample'] === null ? null : (int) $p['top_sample'],
    'silent_in' => $masked($p['silent_mask']), 'low_in' => $masked($p['low_mask']), 'expressed_in' => $masked($p['expressed_mask']),
    'absent_in' => array_values(array_diff(array_map(function ($g) { return $g['short']; }, $nam), $masked($p['present_mask']))),
    'b73' => $p['b73'], 'b73_symbol' => etSymbol($p['b73_symbol'], $p['b73'])
  );
}

function etPanSummary($panId) {
  $p = etPanRow('id = :i', array(':i' => array((int) $panId, SQLITE3_INTEGER)));
  return $p ? etPanRecord($p) : null;
}

function etPanGene($input) {
  $p = etPanResolve($input);
  $db = EtData::pan();
  $pid = (int) $p['id'];
  $stmt = $db->prepare('SELECT m.gene, m.ann FROM members m WHERE m.pan = :p ORDER BY m.gene');
  $stmt->bindValue(':p', $pid, SQLITE3_INTEGER);
  $res = $stmt->execute();
  $byAnn = array();
  $unplaced = array();
  while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    if ($r['ann'] === null) { $unplaced[] = $r['gene']; continue; }
    $byAnn[(int) $r['ann']][] = $r['gene'];
  }
  $annotations = array();
  $res = $db->query('SELECT id, assembly, annotation, panel, panel_label, panel_order, short, expression_genome, nam_index FROM annotations ORDER BY panel_order, short COLLATE NOCASE');
  while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    $annotations[] = array('id' => (int) $r['id'], 'assembly' => $r['assembly'], 'short' => $r['short'],
                           'panel' => $r['panel'], 'panel_label' => $r['panel_label'],
                           'nam_index' => $r['nam_index'] === null ? null : (int) $r['nam_index'],
                           'members' => isset($byAnn[(int) $r['id']]) ? $byAnn[(int) $r['id']] : array());
  }

  /* The NAM panel, live: 26 genomes x the shared samples. */
  $shared = EtData::sharedSamples();
  $k = count($shared);
  $genomes = array();
  $logs = array();
  $totals = array();
  foreach (EtData::namGenomes() as $ng) {
    $members = array();
    foreach ($annotations as $an) { if ($an['nam_index'] === $ng['index']) { $members = $an['members']; } }
    $G = EtData::genome($ng['genome']);
    $entry = array('index' => $ng['index'], 'key' => $G ? $G->key : null, 'short' => $ng['short'], 'genome' => $ng['genome'],
                   'members' => array(), 'total' => null, 'present' => count($members) > 0,
                   'measured' => false, 'expressed' => false, 'state' => count($members) > 0 ? 'not measured' : 'absent',
                   'peak' => null, 'r' => null);
    if ($G && $members) {
      $sc = $G->samples()['shared_columns'];
      $total = array_fill(0, $k, null);
      foreach ($members as $gene) {
        $r = $G->row($gene);
        $vals = null;
        if ($r !== null) {
          $row = $G->row0('rna', 'raw', $r);
          $vals = array();
          foreach ($sc as $j => $c) {
            $v = $row[$c];
            $vals[] = etRound($v);
            if ($v >= 0) { $total[$j] = ($total[$j] === null ? 0.0 : $total[$j]) + $v; }
          }
        }
        $entry['members'][] = array('gene' => $gene, 'row' => $r, 'values' => $vals);
      }
      $present = array_filter($total, function ($v) { return $v !== null; });
      if ($present) {
        $peak = max($present);
        $entry['measured'] = true;
        $entry['peak'] = etRound($peak);
        $entry['expressed'] = $peak >= ET_EXPRESSED_FPKM;
        $entry['state'] = $peak >= ET_EXPRESSED_FPKM ? 'expressed' : ($peak < ET_SILENT_FPKM ? 'silent' : 'low');
        if ($entry['expressed']) {
          $logs[$ng['index']] = array_map(function ($v) { return $v === null ? null : etLog2p1($v); }, $total);
        }
      }
      $entry['total'] = array_map(function ($v) { return $v === null ? null : etRound($v); }, $total);
    }
    $genomes[] = $entry;
  }
  $consensus = null;
  if (count($logs) >= 3) {
    $consensus = array();
    for ($j = 0; $j < $k; $j++) {
      $col = array();
      foreach ($logs as $v) { if ($v[$j] !== null) { $col[] = $v[$j]; } }
      sort($col);
      $n = count($col);
      $consensus[] = $n === 0 ? null : ($n % 2 ? $col[intdiv($n, 2)] : ($col[$n / 2 - 1] + $col[$n / 2]) / 2);
    }
    foreach ($genomes as $i => $e) {
      if (!isset($logs[$e['index']])) { continue; }
      $x = $y = array();
      foreach ($logs[$e['index']] as $j => $v) {
        if ($v !== null && $consensus[$j] !== null) { $x[] = $v; $y[] = $consensus[$j]; }
      }
      if (count($x) >= 8) {
        $r = etPearson($x, $y);
        $genomes[$i]['r'] = $r === null ? null : round($r, 4);
      }
    }
  }
  $samples = array();
  $G0 = EtData::genome(ET_NAM_REFERENCE);
  $cat = $G0 ? $G0->samples() : null;
  foreach ($shared as $j => $s) {
    $tissue = null;
    if ($cat && isset($cat['shared_columns'][$j])) {
      $tissue = $cat['assays']['rna']['samples'][$cat['shared_columns'][$j]]['tissue'];
    }
    $samples[] = array('index' => $j, 'source' => $s['source'], 'label' => $s['label'], 'tissue' => $tissue);
  }
  return array('pangene' => etPanRecord($p), 'annotations' => $annotations, 'unplaced' => $unplaced,
               'nam' => array('samples' => $samples, 'genomes' => $genomes,
                              'consensus' => $consensus === null ? null : array_map(function ($v) { return $v === null ? null : round($v, 4); }, $consensus)));
}

/* The landscape: every pan-gene's presence and expression across the NAM
   genomes, filtered, counted into a present x expressed grid, and listed a
   page at a time. */
function etLandscapeFilters($f, &$params, $skipCell = false) {
  $where = array('n_present > 0');
  $nam = EtData::namGenomes();
  $bit = function ($key) use ($nam) {
    foreach ($nam as $g) {
      if (strcasecmp(str_replace(' ', '', $g['short']), str_replace(' ', '', (string) $key)) === 0) { return (int) $g['index']; }
    }
    throw new EtError('Unknown NAM genome: ' . $key);
  };
  if (!empty($f['class'])) {
    $classes = array_intersect(explode(',', strtolower($f['class'])), array('core', 'near-core', 'dispensable', 'private'));
    if ($classes) {
      $ph = array();
      foreach (array_values($classes) as $i => $c) { $ph[] = ':c' . $i; $params[':c' . $i] = array($c, SQLITE3_TEXT); }
      $where[] = 'class IN (' . implode(',', $ph) . ')';
    }
  }
  if (!empty($f['chr']) && preg_match('/^(chr)?(\d{1,2})$/i', $f['chr'], $m)) {
    $where[] = 'chr = :chr';
    $params[':chr'] = array('chr' . (int) $m[2], SQLITE3_TEXT);
  }
  foreach (array('silent_in' => 'silent_mask', 'low_in' => 'low_mask', 'expressed_in' => 'expressed_mask', 'present_in' => 'present_mask') as $key => $col) {
    if (!empty($f[$key])) {
      foreach (explode(',', $f[$key]) as $g) { $where[] = '((' . $col . ' >> ' . $bit($g) . ') & 1) = 1'; }
    }
  }
  if (!empty($f['absent_in'])) {
    foreach (explode(',', $f['absent_in']) as $g) { $where[] = '((present_mask >> ' . $bit($g) . ') & 1) = 0'; }
  }
  if (isset($f['min_silent']) && $f['min_silent'] !== '') { $where[] = 'n_silent >= :ms'; $params[':ms'] = array((int) $f['min_silent'], SQLITE3_INTEGER); }
  if (isset($f['min_expressed']) && $f['min_expressed'] !== '') { $where[] = 'n_expressed >= :me'; $params[':me'] = array((int) $f['min_expressed'], SQLITE3_INTEGER); }
  if (isset($f['min_present']) && $f['min_present'] !== '') { $where[] = 'n_present >= :mp'; $params[':mp'] = array((int) $f['min_present'], SQLITE3_INTEGER); }
  if (isset($f['max_conservation']) && $f['max_conservation'] !== '') { $where[] = 'conservation <= :mc'; $params[':mc'] = array((float) $f['max_conservation'], SQLITE3_FLOAT); }
  if (isset($f['min_conservation']) && $f['min_conservation'] !== '') { $where[] = 'conservation >= :nc'; $params[':nc'] = array((float) $f['min_conservation'], SQLITE3_FLOAT); }
  if (!empty($f['q'])) {
    $where[] = '(b73_symbol LIKE :q ESCAPE \'\\\' OR b73 LIKE :q ESCAPE \'\\\' OR name LIKE :q ESCAPE \'\\\')';
    $params[':q'] = array('%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $f['q']) . '%', SQLITE3_TEXT);
  }
  if (!$skipCell && isset($f['present']) && $f['present'] !== '' && isset($f['expressed']) && $f['expressed'] !== '') {
    $where[] = 'n_present = :cp AND n_expressed = :ce';
    $params[':cp'] = array((int) $f['present'], SQLITE3_INTEGER);
    $params[':ce'] = array((int) $f['expressed'], SQLITE3_INTEGER);
  }
  return implode(' AND ', $where);
}

function etBindAll($stmt, $params) {
  foreach ($params as $k => $v) { $stmt->bindValue($k, $v[0], $v[1]); }
  return $stmt;
}

function etLandscape($f, $sort, $limit, $offset, $all = false, $each = null) {
  $db = EtData::pan();
  if (!$db) { throw new EtError('The pan-genome index is missing.', 500); }
  $nam = EtData::namGenomes();

  /* The grid ignores the chosen cell, so the reader sees where it sits. */
  $p1 = array();
  $w1 = etLandscapeFilters($f, $p1, true);
  $grid = array();
  $res = etBindAll($db->prepare('SELECT n_present, n_expressed, count(*) AS n FROM pangenes WHERE ' . $w1 . ' GROUP BY 1, 2'), $p1)->execute();
  while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $grid[] = array((int) $r['n_present'], (int) $r['n_expressed'], (int) $r['n']); }

  $params = array();
  $where = etLandscapeFilters($f, $params);
  $sums = array();
  foreach ($nam as $g) { $sums[] = 'SUM((silent_mask >> ' . (int) $g['index'] . ') & 1)'; }
  $row = etBindAll($db->prepare('SELECT count(*) AS n, SUM(n_silent > 0) AS with_silent, AVG(conservation) AS mean_r, ' .
                                implode(', ', $sums) . ' FROM pangenes WHERE ' . $where), $params)->execute()->fetchArray(SQLITE3_NUM);
  $total = (int) $row[0];
  $silentBy = array();
  foreach ($nam as $i => $g) { $silentBy[] = array('short' => $g['short'], 'index' => $g['index'], 'silent' => (int) $row[3 + $i]); }
  $cons = array_fill(0, 20, 0);
  $res = etBindAll($db->prepare('SELECT MIN(19, MAX(0, CAST((conservation + 1) * 10 AS INTEGER))) AS b, count(*) AS n FROM pangenes WHERE ' . $where . ' AND conservation IS NOT NULL GROUP BY 1'), $params)->execute();
  while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $cons[(int) $r['b']] = (int) $r['n']; }
  $classes = array();
  $res = etBindAll($db->prepare('SELECT class, count(*) AS n FROM pangenes WHERE ' . $where . ' GROUP BY 1'), $params)->execute();
  while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $classes[$r['class']] = (int) $r['n']; }

  /* 'variation' puts first the pan-genes a reader means by expression
     presence/absence variation: expressed in some genomes and silent in
     others, as evenly as possible. Sorting on the silent count alone opens on
     pan-genes silent in all 26, which are simply not expressed in these
     tissues. */
  $orders = array(
    'variation' => 'MIN(n_expressed, n_silent) DESC, n_silent DESC, n_present DESC, id',
    'silent' => 'n_silent DESC, n_present DESC, id',
    'divergent' => 'conservation IS NULL, conservation ASC, id',
    'conserved' => 'conservation IS NULL, conservation DESC, id',
    'present' => 'n_present DESC, n_expressed DESC, id',
    'level' => 'level_sd IS NULL, level_sd DESC, id',
    'max' => 'max_raw IS NULL, max_raw DESC, id',
    'name' => 'id'
  );
  $order = isset($orders[$sort]) ? $orders[$sort] : $orders['variation'];
  $sql = 'SELECT * FROM pangenes WHERE ' . $where . ' ORDER BY ' . $order .
         ($all ? '' : ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset);
  $res = etBindAll($db->prepare($sql), $params)->execute();
  /* The whole list ($all) is handed row by row to $each, never held. */
  $rows = array();
  while ($r = $res->fetchArray(SQLITE3_ASSOC)) { if ($each) { $each(etPanRecord($r)); } else { $rows[] = etPanRecord($r); } }
  return array('total' => $total, 'with_silent' => (int) $row[1], 'mean_conservation' => $row[2] === null ? null : round((float) $row[2], 4),
               'grid' => $grid, 'silent_by_genome' => $silentBy, 'conservation_histogram' => array('from' => -1, 'width' => 0.1, 'counts' => $cons),
               'classes' => $classes, 'rows' => $rows, 'offset' => (int) $offset, 'limit' => (int) $limit);
}

/* ------------------------------------------------------------------------
   Two NAM genomes, gene by gene through the pan-genes: every pair of
   members of one pan-gene, one in each genome, with each gene's value over
   the chosen shared samples and the correlation of the two profiles over
   all of them.
   ------------------------------------------------------------------------ */

function etPairs($keyA, $keyB, $sharedIdx, $stat) {
  $GA = EtData::requireGenome($keyA);
  $GB = EtData::requireGenome($keyB);
  if ($GA->name === $GB->name) { throw new EtError('Choose two different genomes.'); }
  if (!$GA->isNam() || !$GB->isNam()) { throw new EtError('Genome pairs compare the 26 NAM genomes, which share 23 samples.'); }
  $db = EtData::pan();
  $annOf = array();
  $res = $db->query('SELECT id, expression_genome FROM annotations WHERE expression_genome IS NOT NULL');
  while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $annOf[$r['expression_genome']] = (int) $r['id']; }
  if (!isset($annOf[$GA->name]) || !isset($annOf[$GB->name])) { throw new EtError('No pan-gene annotation for one of the genomes.', 404); }

  $k = count(EtData::sharedSamples());
  $use = array();
  foreach ($sharedIdx as $j) { if ($j >= 0 && $j < $k) { $use[$j] = true; } }
  $use = array_keys($use);
  sort($use);
  if (!$use) { $use = range(0, $k - 1); }

  /* The shared samples each genome was measured in at all: several founders
     were never measured in some (CML277 in neither 16 DAP sample, M162W in
     none of Diepenbrock's), so a value is over the chosen samples its genome
     has and r over those both have, and the page says how many. A genome
     with none of the chosen samples has no level at all: say so, rather
     than pair nothing. */
  $measured = array();
  foreach (array($GA, $GB) as $G) {
    $sc = $G->samples()['shared_columns'];
    $st = $G->assay('rna')['sample_stats'];
    $m = array();
    for ($j = 0; $j < $k; $j++) { if ($st[$sc[$j]]['n'] > 0) { $m[] = $j; } }
    if (!array_intersect($use, $m)) {
      $shared = EtData::sharedSamples();
      throw new EtError($G->info['short'] . ' was not measured in ' .
                        (count($use) === 1 ? $shared[$use[0]]['label'] : 'any of the ' . count($use) . ' chosen samples') . '. Choose other samples.', 404);
    }
    $measured[] = $m;
  }

  /* Each genome's profile over every shared sample, from one pass. The value
     reads the chosen samples and r reads all of them, so a pair's r does not
     move with the level's samples (one sample alone could never give r its
     8). */
  $profile = function ($G) use ($k) {
    $sc = $G->samples()['shared_columns'];
    $cols = array();
    for ($j = 0; $j < $k; $j++) { $cols[] = $sc[$j] + 1; }
    $out = array();
    foreach ($G->scan('rna', 'raw') as $r => $v) {
      $p = array();
      foreach ($cols as $c) { $p[] = $v[$c]; }
      $out[$r] = $p;
    }
    return $out;
  };
  $members = function ($ann) use ($db) {
    $stmt = $db->prepare('SELECT pan, gene FROM members WHERE ann = :a');
    $stmt->bindValue(':a', $ann, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $out = array();
    while ($r = $res->fetchArray(SQLITE3_NUM)) { $out[(int) $r[0]][] = $r[1]; }
    return $out;
  };
  $ma = $members($annOf[$GA->name]);
  $mb = $members($annOf[$GB->name]);
  $pa = $profile($GA);
  $pb = $profile($GB);
  $value = function ($p) use ($stat, $use) {
    $xs = array();
    foreach ($use as $j) { if ($p[$j] >= 0) { $xs[] = $p[$j]; } }
    if (!$xs) { return null; }
    return $stat === 'max' ? max($xs) : array_sum($xs) / count($xs);
  };
  $out = array('a' => array(), 'b' => array(), 'kind' => array(), 'pan' => array(), 'va' => array(), 'vb' => array(), 'r' => array());
  $onlyA = $onlyB = $unmeasured = 0;
  foreach ($ma as $pan => $genesA) {
    if (!isset($mb[$pan])) { $onlyA++; continue; }
    $genesB = $mb[$pan];
    $kind = count($genesA) . ':' . count($genesB);
    foreach ($genesA as $ga) {
      $ra = $GA->row($ga);
      foreach ($genesB as $gb) {
        $rb = $GB->row($gb);
        if ($ra === null || $rb === null) { $unmeasured++; continue; }
        $va = $value($pa[$ra]);
        $vb = $value($pb[$rb]);
        if ($va === null || $vb === null) { $unmeasured++; continue; }
        $x = $y = array();
        foreach ($pa[$ra] as $j => $u) {
          $w = $pb[$rb][$j];
          if ($u >= 0 && $w >= 0) { $x[] = etLog2p1($u); $y[] = etLog2p1($w); }
        }
        $r = count($x) >= 8 ? etPearson($x, $y) : null;
        $out['a'][] = $ga; $out['b'][] = $gb; $out['kind'][] = $kind; $out['pan'][] = $pan;
        $out['va'][] = etRound($va); $out['vb'][] = etRound($vb); $out['r'][] = $r === null ? null : round($r, 3);
      }
    }
  }
  foreach ($mb as $pan => $g) { if (!isset($ma[$pan])) { $onlyB++; } }
  return $out + array('genome_a' => $GA->key, 'genome_b' => $GB->key, 'samples' => $use, 'stat' => $stat === 'max' ? 'max' : 'mean',
                      'measured_a' => $measured[0], 'measured_b' => $measured[1],
                      'pangenes_only_a' => $onlyA, 'pangenes_only_b' => $onlyB, 'pairs_not_measured' => $unmeasured);
}

/* ------------------------------------------------------------------------
   Homeologs: the retained maize1/maize2 pairs of the paralogs release
   (B73 v5), each pair's expression over the selected samples.
   ------------------------------------------------------------------------ */

function etHomeologOf($gene) {
  $db = EtData::paralogs();
  if (!$db) { return null; }
  $stmt = $db->prepare('SELECT m1, m2, m1_ks, m2_ks, m1_omega, m2_omega, sorghum FROM pairs WHERE (m1 = :g OR m2 = :g) AND m1 IS NOT NULL AND m2 IS NOT NULL LIMIT 1');
  $stmt->bindValue(':g', $gene, SQLITE3_TEXT);
  $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
  if (!$r) { return null; }
  $self = $r['m1'] === $gene ? 'maize1' : 'maize2';
  return array('subgenome' => $self, 'partner' => $self === 'maize1' ? $r['m2'] : $r['m1'],
               'partner_subgenome' => $self === 'maize1' ? 'maize2' : 'maize1', 'sorghum' => $r['sorghum'],
               'ks' => array('maize1' => $r['m1_ks'], 'maize2' => $r['m2_ks']),
               'omega' => array('maize1' => $r['m1_omega'], 'maize2' => $r['m2_omega']));
}

function etHomeologs($G, $cols) {
  if ($G->name !== ET_NAM_REFERENCE) { throw new EtError('Homeolog pairs are placed on B73 v5.', 404); }
  $db = EtData::paralogs();
  if (!$db) { throw new EtError('The paralogs release is missing.', 500); }
  $res = $db->query('SELECT m1, m2, m1_ks, m2_ks, m1_omega, m2_omega FROM pairs WHERE m1 IS NOT NULL AND m2 IS NOT NULL ORDER BY id');
  $pairs = array();
  $rows = array();
  while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    $r1 = $G->row($r['m1']);
    $r2 = $G->row($r['m2']);
    $pairs[] = array($r, $r1, $r2);
    if ($r1 !== null) { $rows[] = $r1; }
    if ($r2 !== null) { $rows[] = $r2; }
  }
  $raw = $G->rows('rna', 'raw', $rows);
  $info = etGeneInfo($G, $rows);
  $out = array();
  $classes = array();
  foreach ($pairs as $p) {
    list($r, $r1, $r2) = $p;
    if ($r1 === null || $r2 === null) { continue; }
    $x = $y = array();
    $s1 = $s2 = 0.0; $n = 0; $max1 = $max2 = 0.0;
    foreach ($cols as $c) {
      $a = $raw[$r1][$c]; $b = $raw[$r2][$c];
      if ($a < 0 || $b < 0) { continue; }
      $n++; $s1 += $a; $s2 += $b;
      if ($a > $max1) { $max1 = $a; }
      if ($b > $max2) { $max2 = $b; }
      $x[] = etLog2p1($a); $y[] = etLog2p1($b);
    }
    if ($n < 3) { continue; }
    $m1 = $s1 / $n; $m2 = $s2 / $n;
    $lr = log(($m1 + 1) / ($m2 + 1), 2);
    if ($max1 < 1 && $max2 < 1) { $cls = 'neither detected'; }
    elseif ($max2 < 1) { $cls = 'maize1 only'; }
    elseif ($max1 < 1) { $cls = 'maize2 only'; }
    elseif ($lr >= 1) { $cls = 'maize1 higher'; }
    elseif ($lr <= -1) { $cls = 'maize2 higher'; }
    else { $cls = 'balanced'; }
    $classes[$cls] = (isset($classes[$cls]) ? $classes[$cls] : 0) + 1;
    $rr = etPearson($x, $y);
    $out[] = array('m1' => $r['m1'], 'm2' => $r['m2'],
                   'm1_symbol' => isset($info[$r1]) ? $info[$r1]['symbol'] : null,
                   'm2_symbol' => isset($info[$r2]) ? $info[$r2]['symbol'] : null,
                   'mean1' => etRound($m1), 'mean2' => etRound($m2), 'log2_ratio' => round($lr, 3),
                   'r' => $rr === null ? null : round($rr, 3), 'n' => $n, 'class' => $cls,
                   'ks1' => $r['m1_ks'], 'ks2' => $r['m2_ks'], 'omega1' => $r['m1_omega'], 'omega2' => $r['m2_omega']);
  }
  return array('pairs' => $out, 'classes' => $classes, 'samples' => count($cols), 'pairs_in_release' => count($pairs));
}

/* ------------------------------------------------------------------------
   RNA against protein in one tissue (Walley 2019, measured in the same
   samples): every gene with protein detected there.
   ------------------------------------------------------------------------ */

function etProteinGlobal($G, $proteinSampleId) {
  if (!$G->hasAssay('protein')) { throw new EtError('This genome has no protein data.', 404); }
  $P = $G->assay('protein');
  $R = $G->assay('rna');
  $pc = null;
  foreach ($P['samples'] as $j => $s) { if ((int) $s['id'] === (int) $proteinSampleId) { $pc = $j; } }
  if ($pc === null) { throw new EtError('Unknown protein sample.', 404); }
  $label = $P['samples'][$pc]['label'];
  $study = preg_replace('/\s*\[.*$/', '', (string) $P['samples'][$pc]['source']);
  $rc = null;
  foreach ($R['samples'] as $j => $s) {
    if ($s['label'] === $label && strpos((string) $s['source'], $study) === 0) { $rc = $j; }
  }
  if ($rc === null) { throw new EtError('No RNA sample of the same study carries the label "' . $label . '".', 404); }
  $genes = $rna = $prot = array();
  $names = $G->genes();
  $protRows = array();
  foreach ($G->scan('protein', 'raw') as $r => $v) {
    $x = $v[$pc + 1];
    if ($x > 0) { $protRows[$r] = $x; }
  }
  $rv = $G->rows('rna', 'raw', array_keys($protRows));
  foreach ($protRows as $r => $x) {
    $y = isset($rv[$r]) ? $rv[$r][$rc] : -1;
    $genes[] = $names[$r];
    $prot[] = etRound($x);
    $rna[] = $y < 0 ? null : etRound($y);
  }
  return array('protein_sample' => $P['samples'][$pc]['id'], 'rna_sample' => $R['samples'][$rc]['id'], 'label' => $label,
               'genes' => $genes, 'rna' => $rna, 'protein' => $prot);
}

/* ------------------------------------------------------------------------
   GO enrichment

   One-sided hypergeometric test of each term against the genome's
   GO-annotated genes (or the annotated genes detected in some sample), with
   the list's genes propagated up the is_a and part_of ancestry of the
   GO reference index. Benjamini-Hochberg across the terms tested.
   ------------------------------------------------------------------------ */

function etLogFactorials($n) {
  $lf = array(0.0);
  $s = 0.0;
  for ($i = 1; $i <= $n; $i++) { $s += log($i); $lf[$i] = $s; }
  return $lf;
}

/* P(X >= x) for X ~ Hypergeometric(N, K, n). */
function etHyperTail($x, $N, $K, $n, $lf) {
  $hi = min($n, $K);
  if ($x > $hi) { return 0.0; }
  if ($x <= max(0, $n - ($N - $K))) { return 1.0; }
  $logC = function ($a, $b) use ($lf) { return $lf[$a] - $lf[$b] - $lf[$a - $b]; };
  $p = exp($logC($K, $x) + $logC($N - $K, $n - $x) - $logC($N, $n));
  $sum = $p;
  for ($i = $x; $i < $hi; $i++) {
    $p *= (($K - $i) * ($n - $i)) / (($i + 1) * ($N - $K - $n + $i + 1));
    $sum += $p;
    if ($p < $sum * 1e-15) { break; }
  }
  return min(1.0, $sum);
}

function etEnrich($G, $rows, $opts) {
  $db = $G->go();
  if (!$db) { throw new EtError('There are no GO annotations for ' . $G->info['short'] . '.', 404); }
  $expressedOnly = $opts['background'] === 'expressed';
  $minSize = max(1, (int) $opts['min_size']);
  $maxSize = max($minSize, (int) $opts['max_size']);
  $aspects = array_intersect(str_split(strtoupper((string) $opts['aspects'])), array('P', 'F', 'C'));
  if (!$aspects) { $aspects = array('P', 'F', 'C'); }
  $meta = array();
  $res = $db->query('SELECT key, value FROM meta');
  while ($r = $res->fetchArray(SQLITE3_NUM)) { $meta[$r[0]] = json_decode($r[1], true); }
  $N = (int) ($expressedOnly ? $meta['genes_annotated_expressed'] : $meta['genes_annotated']);

  $rows = array_values(array_unique(array_map('intval', $rows)));
  if ($expressedOnly) {
    $rows = array_values(array_filter($rows, function ($r) use ($G) { return $G->stat('rna', $r, ET_STAT_DETECTED) > 0; }));
  }
  $direct = array();
  foreach (etQueryIn($db, 'SELECT row, term FROM gene_terms WHERE row IN ({IN})', $rows, SQLITE3_INTEGER) as $r) {
    $direct[(int) $r['row']][] = (int) $r['term'];
  }
  $terms = array();
  foreach ($direct as $ts) { foreach ($ts as $t) { $terms[$t] = true; } }
  $anc = array();
  foreach (etQueryIn($db, 'SELECT term, ancestor FROM term_ancestors WHERE term IN ({IN})', array_keys($terms), SQLITE3_INTEGER) as $r) {
    $anc[(int) $r['term']][] = (int) $r['ancestor'];
  }
  $count = array();
  $members = array();
  foreach ($direct as $row => $ts) {
    $seen = array();
    foreach ($ts as $t) {
      foreach ((isset($anc[$t]) ? $anc[$t] : array($t)) as $a) { $seen[$a] = true; }
    }
    foreach ($seen as $a => $true) {
      $count[$a] = (isset($count[$a]) ? $count[$a] : 0) + 1;
      if (!isset($members[$a]) || count($members[$a]) < 200) { $members[$a][] = $row; }
    }
  }
  $n = count($direct);
  $info = array();
  foreach (etQueryIn($db, 'SELECT id, go, name, aspect, depth, n_bg, n_bg_expressed FROM terms WHERE id IN ({IN})', array_keys($count), SQLITE3_INTEGER) as $r) {
    $info[(int) $r['id']] = $r;
  }
  $lf = etLogFactorials(max($N, 1));
  $tested = array();
  foreach ($count as $t => $x) {
    if (!isset($info[$t]) || !in_array($info[$t]['aspect'], $aspects, true)) { continue; }
    $K = (int) ($expressedOnly ? $info[$t]['n_bg_expressed'] : $info[$t]['n_bg']);
    if ($K < $minSize || $K > $maxSize || $x < 2) { continue; }
    $p = etHyperTail($x, $N, $K, $n, $lf);
    $tested[] = array('term' => $t, 'x' => $x, 'K' => $K, 'p' => $p);
  }
  /* Benjamini-Hochberg */
  usort($tested, function ($a, $b) { return $a['p'] == $b['p'] ? 0 : ($a['p'] < $b['p'] ? -1 : 1); });
  $m = count($tested);
  $min = 1.0;
  for ($i = $m - 1; $i >= 0; $i--) {
    $q = min(1.0, $tested[$i]['p'] * $m / ($i + 1));
    if ($q < $min) { $min = $q; }
    $tested[$i]['fdr'] = $min;
  }
  $names = $G->genes();
  $out = array();
  foreach (array_slice($tested, 0, (int) $opts['limit']) as $t) {
    $ti = $info[$t['term']];
    $genes = array();
    foreach (array_slice($members[$t['term']], 0, 60) as $row) { $genes[] = $names[$row]; }
    $out[] = array('go' => $ti['go'], 'name' => $ti['name'], 'aspect' => $ti['aspect'], 'depth' => $ti['depth'] === null ? null : (int) $ti['depth'],
                   'x' => $t['x'], 'K' => $t['K'], 'fold' => ($n > 0 && $t['K'] > 0) ? round(($t['x'] / $n) / ($t['K'] / $N), 3) : null,
                   'p' => $t['p'], 'fdr' => $t['fdr'], 'genes' => $genes);
  }
  return array('list' => count($rows), 'annotated' => $n, 'background' => $N,
               'background_rule' => $expressedOnly ? 'GO-annotated genes detected (>= 1) in at least one sample'
                                                   : 'every GO-annotated gene of the release',
               'tested' => $m, 'terms' => $out, 'go_release' => isset($meta['go_release']) ? $meta['go_release'] : null);
}
