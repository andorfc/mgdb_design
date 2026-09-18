<?php
/* file: api/v1/lib/mgdb_paralogs.php
 *
 * purpose: read the within-genome paralog release tools/paralogs_index.py
 *          writes under data/paralogs/<genome>/, for the gene record's
 *          "Homeologs and tandem arrays" section.
 *
 *          A release is one SQLite file: the retained maize1/maize2 homeolog
 *          pairs placed on the annotation (each copy's Ka, Ks and omega
 *          against its sorghum syntelog, carried from the source table), the
 *          pairs only one copy of which could be placed, the tandem arrays,
 *          and every gene's position so an array can be drawn among its
 *          neighbours. A gene is two indexed reads.
 *
 *          Read-only, and denied to the browser by data/paralogs/.htaccess
 *          except homeolog_pairs.tsv, the public download of the placed
 *          pairs.
 *
 * history:
 *  09/17/26  claude  created
 */

// Reachable only through controllers/api.php.
if (!defined('MGDB_API')) { http_response_code(404); exit; }

class MgdbParalogs {

  private static $dbs = array();
  private static $manifests = array();

  /* Genes drawn either side of a tandem array, as context. */
  const CONTEXT_GENES = 3;

  public static function path($genome) {
    return MgdbData::dir('paralogs') . '/' . $genome . '/paralogs.sqlite';
  }

  public static function available($genome) {
    return $genome !== null && $genome !== '' && self::db($genome) !== null;
  }

  private static function db($genome) {
    if (!array_key_exists($genome, self::$dbs)) {
      self::$dbs[$genome] = null;
      if (class_exists('SQLite3') && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', (string) $genome) && is_file(self::path($genome))) {
        try {
          $db = new SQLite3(self::path($genome), SQLITE3_OPEN_READONLY);
          $db->busyTimeout(2000);
          self::$dbs[$genome] = $db;
        } catch (Exception $e) {
          self::$dbs[$genome] = null;
        }
      }
    }
    return self::$dbs[$genome];
  }

  public static function manifest($genome) {
    if (array_key_exists($genome, self::$manifests)) { return self::$manifests[$genome]; }
    $db = self::db($genome);
    $m = null;
    if ($db !== null) {
      $raw = $db->querySingle("SELECT value FROM meta WHERE key = 'manifest'");
      $m = is_string($raw) ? json_decode($raw, true) : null;
    }
    self::$manifests[$genome] = $m;
    return $m;
  }

  private static function rows($db, $sql, $params) {
    $stmt = $db->prepare($sql);
    if (!$stmt) { return array(); }
    foreach ($params as $i => $v) {
      $stmt->bindValue($i + 1, $v, is_int($v) ? SQLITE3_INTEGER : SQLITE3_TEXT);
    }
    $res = $stmt->execute();
    $out = array();
    while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) { $out[] = $row; }
    return $out;
  }

  /* An NCBI placeholder is not a name; everything else the gene models call
     a gene is. */
  private static function symbol($s) {
    return ($s === null || $s === '' || preg_match('/^LOC\d+$/', $s)) ? null : $s;
  }

  private static function geneRow($db, $id) {
    if ($id === null || $id === '') { return null; }
    $r = self::rows($db, 'SELECT id, chr, start, end, strand, biotype, symbol FROM genes WHERE id = ?', array($id));
    return $r ? $r[0] : null;
  }

  /* Sobic.006G247700 is SORBI_3006G247700 at Gramene. */
  private static function sorghum($id) {
    $gramene = preg_match('/^Sobic\.(\d{3}G\d+)$/', (string) $id, $m) ? 'SORBI_3' . $m[1] : null;
    return array(
      'id' => $id,
      'gramene_id' => $gramene,
      'url' => $gramene ? 'https://ensembl.gramene.org/Sorghum_bicolor/Gene/Summary?g=' . $gramene : null
    );
  }

  private static function copy($db, $row, $side, $subgenome) {
    $gene = $row[$side];
    $g = self::geneRow($db, $gene);
    return array(
      'gene' => $gene,
      'subgenome' => $subgenome,
      'symbol' => $g ? self::symbol($g['symbol']) : null,
      'chr' => $g ? $g['chr'] : null,
      'start' => $g ? (int) $g['start'] : null,
      'end' => $g ? (int) $g['end'] : null,
      'strand' => $g ? $g['strand'] : null,
      'v4' => $row[$side . '_v4'],
      'v4_location' => array('chr' => 'chr' . $row[$side . '_v4_chr'], 'start' => (int) $row[$side . '_v4_start'], 'end' => (int) $row[$side . '_v4_end']),
      'ka' => $row[$side . '_ka'] === null ? null : (float) $row[$side . '_ka'],
      'ks' => $row[$side . '_ks'] === null ? null : (float) $row[$side . '_ks'],
      'omega' => $row[$side . '_omega'] === null ? null : (float) $row[$side . '_omega'],
      'placed_by' => $row[$side . '_method'],
      'note' => $row[$side . '_note'],
      /* A copy that could not be placed on this annotation is still a gene
         the record page can open: by its v4 identifier. */
      'html' => '/gene_center/gene/' . rawurlencode($gene !== null ? $gene : $row[$side . '_v4'])
    );
  }

  /* The pair a gene is one copy of, or null. */
  public static function homeolog($genome, $gene) {
    $db = self::db($genome);
    if ($db === null || $gene === null) { return null; }
    $r = self::rows($db, 'SELECT * FROM pairs WHERE m1 = ? UNION ALL SELECT * FROM pairs WHERE m2 = ?', array($gene, $gene));
    if (!$r) { return null; }
    $row = $r[0];
    $thisSide = ($row['m1'] === $gene) ? 'm1' : 'm2';
    $otherSide = ($thisSide === 'm1') ? 'm2' : 'm1';
    return array(
      'pair_id' => (int) $row['id'],
      'complete' => (bool) $row['complete'],
      'this' => self::copy($db, $row, $thisSide, $thisSide === 'm1' ? 'maize1' : 'maize2'),
      'partner' => self::copy($db, $row, $otherSide, $otherSide === 'm1' ? 'maize1' : 'maize2'),
      'sorghum' => self::sorghum($row['sorghum'])
    );
  }

  /* The tandem array a gene is in, its members in chromosome order, and a
     few genes either side as context; or null. */
  public static function tandem($genome, $gene) {
    $db = self::db($genome);
    if ($db === null || $gene === null) { return null; }
    $a = self::rows($db, 'SELECT a.id, a.chr, a.start, a.end, a.size FROM tandem t JOIN arrays a ON a.id = t.array_id WHERE t.gene = ?', array($gene));
    if (!$a) { return null; }
    $a = $a[0];
    $members = array();
    $ids = array();
    foreach (self::rows($db, 'SELECT g.id, g.start, g.end, g.strand, g.biotype, g.symbol FROM tandem t JOIN genes g ON g.id = t.gene WHERE t.array_id = ? ORDER BY t.ord',
                        array((int) $a['id'])) as $g) {
      $ids[$g['id']] = true;
      $members[] = array('gene' => $g['id'], 'symbol' => self::symbol($g['symbol']), 'start' => (int) $g['start'], 'end' => (int) $g['end'],
                         'strand' => $g['strand'], 'biotype' => $g['biotype'], 'this' => $g['id'] === $gene, 'member' => true);
    }
    /* Everything between the first and last member that is not a member,
       and CONTEXT_GENES either side. */
    $between = self::rows($db, 'SELECT id, start, end, strand, biotype, symbol FROM genes WHERE chr = ? AND start <= ? AND end >= ? ORDER BY start',
                          array($a['chr'], (int) $a['end'], (int) $a['start']));
    $before = array_reverse(self::rows($db, 'SELECT id, start, end, strand, biotype, symbol FROM genes WHERE chr = ? AND start < ? ORDER BY start DESC LIMIT ' . self::CONTEXT_GENES,
                          array($a['chr'], (int) $a['start'])));
    $after = self::rows($db, 'SELECT id, start, end, strand, biotype, symbol FROM genes WHERE chr = ? AND start > ? ORDER BY start LIMIT ' . self::CONTEXT_GENES,
                          array($a['chr'], (int) $a['end']));
    $context = array();
    foreach (array_merge($before, $between, $after) as $g) {
      if (isset($ids[$g['id']])) { continue; }
      $context[] = array('gene' => $g['id'], 'symbol' => self::symbol($g['symbol']), 'start' => (int) $g['start'], 'end' => (int) $g['end'],
                         'strand' => $g['strand'], 'biotype' => $g['biotype'], 'this' => false, 'member' => false);
    }
    usort($context, function ($x, $y) { return $x['start'] - $y['start']; });
    return array(
      'id' => (int) $a['id'],
      'chr' => $a['chr'],
      'start' => (int) $a['start'],
      'end' => (int) $a['end'],
      'size' => (int) $a['size'],
      'members' => $members,
      'context' => $context
    );
  }
}
