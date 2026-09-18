<?php
/* file: api/v1/lib/mgdb_positions.php
 *
 * purpose: read the gene-positions releases tools/gene_positions_index.py
 *          writes under data/gene_positions/<genome>/, for the pan-gene
 *          record's chromosome placement map.
 *
 *          A release is one SQLite file per assembly: genes(gene, seqid,
 *          start, end, strand) and seqs(seqid, length, ord, kind). It exists
 *          because the database has positions for B73 and the 25 NAM founders
 *          only (chado.gene_model), and a full gene-models release is ~151 MB
 *          per genome where this is a few MB.
 *
 *          Everything is read-only: SQLITE3_OPEN_READONLY, and the files are
 *          denied to the browser by data/gene_positions/.htaccess.
 *
 * history:
 *  09/17/26  claude  created
 */

// Reachable only through controllers/api.php.
if (!defined('MGDB_API')) { http_response_code(404); exit; }

class MgdbPositions {

  private static $dbs = array();

  /* The directory name for an assembly: 'B73 RefGen_v3' has a space in it.
     Must agree with safe_key() in tools/gene_positions_index.py. */
  public static function key($assembly) {
    return preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $assembly);
  }

  public static function path($assembly) {
    return MgdbData::dir('gene-positions') . '/' . self::key($assembly) . '/positions.sqlite';
  }

  public static function available($assembly) {
    return $assembly !== null && $assembly !== '' && is_file(self::path($assembly));
  }

  private static function db($assembly) {
    $k = self::key($assembly);
    if (!array_key_exists($k, self::$dbs)) {
      self::$dbs[$k] = null;
      if (class_exists('SQLite3') && is_file(self::path($assembly))) {
        try {
          $db = new SQLite3(self::path($assembly), SQLITE3_OPEN_READONLY);
          $db->busyTimeout(2000);
          self::$dbs[$k] = $db;
        } catch (Exception $e) {
          self::$dbs[$k] = null;
        }
      }
    }
    return self::$dbs[$k];
  }

  /* Many genes of one assembly in one read: gene => [seqid, start, end,
     strand]. Chunked below SQLite's older 999-variable ceiling. */
  public static function batch($assembly, $genes) {
    $db = self::db($assembly);
    $out = array();
    $genes = array_values(array_unique(array_filter($genes, 'strlen')));
    if ($db === null || count($genes) === 0) { return $out; }
    foreach (array_chunk($genes, 400) as $chunk) {
      $ph = array();
      foreach ($chunk as $i => $g) { $ph[] = ':g' . $i; }
      $stmt = $db->prepare('SELECT gene, seqid, start, "end", strand FROM genes WHERE gene IN (' .
                           implode(',', $ph) . ')');
      foreach ($chunk as $i => $g) { $stmt->bindValue(':g' . $i, $g, SQLITE3_TEXT); }
      $res = $stmt->execute();
      while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $out[$row['gene']] = array($row['seqid'], (int) $row['start'], (int) $row['end'], $row['strand']);
      }
    }
    return $out;
  }

  /* The chromosomes of an assembly, in order: [{name, number, length}]. */
  public static function chromosomes($assembly) {
    $db = self::db($assembly);
    $out = array();
    if ($db === null) { return $out; }
    $res = $db->query("SELECT seqid, length FROM seqs WHERE kind = 'chromosome' ORDER BY ord");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
      $out[] = array('name' => $row['seqid'], 'number' => self::chromNumber($row['seqid']),
                     'length' => (int) $row['length']);
    }
    return $out;
  }

  /* 1-10 for a maize chromosome however it is spelled (chr1, Chr01, 1). */
  public static function chromNumber($seqid) {
    return preg_match('/^(?:chr|Chr|CHR)?0*(\d{1,2})$/', (string) $seqid, $m) ? (int) $m[1] : null;
  }
}
