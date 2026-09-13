<?php
/**
 * MgdbGo -- the Gene Ontology reference index, read for the gene record's
 * Function section.
 *
 * data/go/go.sqlite is built by tools/go_index.py from go-basic.obo and
 * InterPro2GO: every term with its aspect, definition, plant-slim membership
 * and depth; is_a / part_of parents; the full ancestor closure; and the
 * InterPro-entry-to-GO mapping. Nothing here is per genome.
 *
 * annotate() is what the record calls: given the GO ids a gene carries it
 * returns each term with its plant-slim ancestors, and the small graph of
 * those terms, their slim ancestors and the three roots, with the edges
 * reduced to the shortest ones (no edge that another node already implies).
 * Three to four IN queries against the closure, whatever the gene.
 *
 * history:
 *  09/12/26  claude  created
 */

class MgdbGo {
  const ROOTS = array('biological_process' => 'GO:0008150',
                      'molecular_function' => 'GO:0003674',
                      'cellular_component' => 'GO:0005575');
  const NAMESPACE_ORDER = array('biological_process', 'molecular_function', 'cellular_component');

  private static $db = false;
  private static $manifest = false;

  public static function path() {
    $dir = MgdbData::dir('go');
    return $dir === null ? null : $dir . '/go.sqlite';
  }

  public static function available() {
    $p = self::path();
    return $p !== null && class_exists('SQLite3') && is_file($p);
  }

  public static function manifest() {
    if (self::$manifest === false) {
      $dir = MgdbData::dir('go');
      $p = $dir === null ? null : $dir . '/index.json';
      self::$manifest = ($p !== null && is_file($p)) ? json_decode(file_get_contents($p), true) : null;
    }
    return self::$manifest;
  }

  public static function release() {
    $m = self::manifest();
    return $m && isset($m['release']) ? $m['release'] : null;
  }

  private static function db() {
    if (self::$db === false) {
      self::$db = null;
      if (self::available()) {
        try {
          $db = new SQLite3(self::path(), SQLITE3_OPEN_READONLY);
          $db->busyTimeout(2000);
          self::$db = $db;
        } catch (Exception $e) {
          self::$db = null;
        }
      }
    }
    return self::$db;
  }

  /* Rows of one prepared query with a text IN list, chunked under SQLite's
     variable limit. */
  private static function inQuery($sqlPrefix, $ids, $sqlSuffix = '') {
    $out = array();
    $db = self::db();
    if ($db === null || !$ids) { return $out; }
    foreach (array_chunk(array_values($ids), 400) as $chunk) {
      $sql = $sqlPrefix . ' (' . implode(',', array_fill(0, count($chunk), '?')) . ')' . $sqlSuffix;
      $st = $db->prepare($sql);
      if (!$st) { continue; }
      foreach ($chunk as $i => $v) { $st->bindValue($i + 1, (string) $v, SQLITE3_TEXT); }
      $res = $st->execute();
      while ($row = $res->fetchArray(SQLITE3_ASSOC)) { $out[] = $row; }
    }
    return $out;
  }

  public static function validId($id) {
    return is_string($id) && preg_match('/^GO:\d{7}$/', $id) === 1;
  }

  /* id -> {id, name, namespace, definition, obsolete, replaced_by, slim, depth} */
  public static function terms(array $ids) {
    $ids = array_values(array_unique(array_filter($ids, array('MgdbGo', 'validId'))));
    $out = array();
    foreach (self::inQuery('SELECT id, name, namespace, definition, obsolete, replaced_by, slim, depth FROM term WHERE id IN', $ids) as $r) {
      $r['obsolete'] = (bool) $r['obsolete'];
      $r['slim'] = (bool) $r['slim'];
      $r['depth'] = $r['depth'] === null ? null : (int) $r['depth'];
      $out[$r['id']] = $r;
    }
    return $out;
  }

  /* id -> [ancestor ids] over is_a and part_of (the term itself excluded). */
  public static function ancestors(array $ids) {
    $ids = array_values(array_unique(array_filter($ids, array('MgdbGo', 'validId'))));
    $out = array();
    foreach ($ids as $id) { $out[$id] = array(); }
    foreach (self::inQuery('SELECT term, ancestor FROM closure WHERE term IN', $ids) as $r) {
      $out[$r['term']][] = $r['ancestor'];
    }
    return $out;
  }

  /* IPR accession -> [GO ids] from InterPro2GO. */
  public static function iprToGo(array $iprs) {
    $iprs = array_values(array_unique(array_filter($iprs, function ($a) { return is_string($a) && preg_match('/^IPR\d{6}$/', $a); })));
    $out = array();
    foreach (self::inQuery('SELECT ipr, go FROM ipr2go WHERE ipr IN', $iprs, ' ORDER BY ipr, go') as $r) {
      $out[$r['ipr']][] = $r['go'];
    }
    return $out;
  }

  /* alt id -> the surviving primary id (a merged term). */
  public static function altOf(array $ids) {
    $ids = array_values(array_unique(array_filter($ids, array('MgdbGo', 'validId'))));
    $out = array();
    foreach (self::inQuery('SELECT alt_id, term FROM alt WHERE alt_id IN', $ids) as $r) {
      $out[$r['alt_id']] = $r['term'];
    }
    return $out;
  }

  /* Normalise what a caller typed: GO:0003677, go:0003677, GO_0003677,
     GO0003677 or 3677 all mean GO:0003677. Null when it is nothing like one. */
  public static function normaliseId($raw) {
    $t = strtoupper(trim((string) $raw));
    if (preg_match('/^(?:GO[:_]?)?(\d{1,7})$/', $t, $m)) { return 'GO:' . str_pad($m[1], 7, '0', STR_PAD_LEFT); }
    return null;
  }

  /* One term by id, following a merged (alt) id to its survivor. Returns
     the term row plus 'requested' and 'merged_into' (the id asked for, when
     it was an alt id), or null. */
  public static function term($id) {
    $id = self::normaliseId($id);
    if ($id === null) { return null; }
    $t = self::terms(array($id));
    if (isset($t[$id])) { $row = $t[$id]; $row['merged_into'] = null; $row['requested'] = $id; return $row; }
    $alt = self::altOf(array($id));
    if (!isset($alt[$id])) { return null; }
    $t = self::terms(array($alt[$id]));
    if (!isset($t[$alt[$id]])) { return null; }
    $row = $t[$alt[$id]];
    $row['merged_into'] = $alt[$id];
    $row['requested'] = $id;
    return $row;
  }

  /* Direct parents with the relation, and direct children. */
  public static function parentsOf($id) {
    $out = array();
    foreach (self::inQuery('SELECT p.parent AS id, p.rel, t.name, t.namespace, t.depth, t.slim FROM parent p JOIN term t ON t.id = p.parent WHERE p.child IN', array($id), ' ORDER BY t.depth, t.name') as $r) {
      $out[] = array('id' => $r['id'], 'name' => $r['name'], 'relation' => $r['rel'], 'namespace' => $r['namespace'],
                     'depth' => $r['depth'] === null ? null : (int) $r['depth'], 'slim' => (bool) $r['slim']);
    }
    return $out;
  }

  public static function childrenOf($id, $limit = 500) {
    $out = array();
    foreach (self::inQuery('SELECT p.child AS id, p.rel, t.name, t.namespace, t.depth, t.slim FROM parent p JOIN term t ON t.id = p.child WHERE p.parent IN', array($id), ' ORDER BY t.name LIMIT ' . (int) $limit) as $r) {
      $out[] = array('id' => $r['id'], 'name' => $r['name'], 'relation' => $r['rel'], 'namespace' => $r['namespace'],
                     'depth' => $r['depth'] === null ? null : (int) $r['depth'], 'slim' => (bool) $r['slim']);
    }
    return $out;
  }

  public static function childCount($id) {
    $rows = self::inQuery('SELECT count(*) AS n FROM parent WHERE parent IN', array($id));
    return $rows ? (int) $rows[0]['n'] : 0;
  }

  public static function descendantCount($id) {
    $rows = self::inQuery('SELECT count(*) AS n FROM closure WHERE ancestor IN', array($id));
    return $rows ? (int) $rows[0]['n'] : 0;
  }

  /* Every ancestor of a term with its name and depth, roots first. */
  public static function lineage($id) {
    $anc = self::ancestors(array($id));
    $ids = isset($anc[$id]) ? $anc[$id] : array();
    $terms = self::terms($ids);
    $out = array();
    foreach ($ids as $a) {
      if (!isset($terms[$a])) { continue; }
      $t = $terms[$a];
      $out[] = array('id' => $a, 'name' => $t['name'], 'namespace' => $t['namespace'], 'depth' => $t['depth'],
                     'slim' => $t['slim'], 'root' => in_array($a, self::ROOTS, true));
    }
    usort($out, function ($x, $y) {
      $dx = $x['depth'] === null ? 99 : $x['depth']; $dy = $y['depth'] === null ? 99 : $y['depth'];
      return $dx === $dy ? strcmp($x['name'], $y['name']) : ($dx < $dy ? -1 : 1);
    });
    return $out;
  }

  /* The InterPro entries InterPro2GO maps to a term. */
  public static function iprFor($go) {
    $out = array();
    foreach (self::inQuery('SELECT ipr, ipr_name FROM ipr2go WHERE go IN', array($go), ' ORDER BY ipr') as $r) {
      $out[] = array('accession' => $r['ipr'], 'name' => $r['ipr_name'],
                     'url' => 'https://www.ebi.ac.uk/interpro/entry/InterPro/' . $r['ipr'] . '/');
    }
    return $out;
  }

  /* Name search: an id answers exactly; otherwise live terms whose name
     contains the phrase, those starting with it first, then by depth. */
  public static function search($q, $namespace, $limit) {
    $q = trim((string) $q);
    $out = array();
    $db = self::db();
    if ($db === null || $q === '') { return $out; }
    $asId = self::normaliseId($q);
    if ($asId !== null) {
      $t = self::term($asId);
      if ($t) { $out[] = array('id' => $t['id'], 'name' => $t['name'], 'namespace' => $t['namespace'], 'depth' => $t['depth'], 'slim' => $t['slim'], 'obsolete' => $t['obsolete'], 'match' => 'id'); }
      return $out;
    }
    $sql = 'SELECT id, name, namespace, depth, slim, obsolete FROM term WHERE name LIKE ? ESCAPE \'\\\' AND obsolete = 0'
         . ($namespace !== null ? ' AND namespace = ?' : '')
         . ' ORDER BY CASE WHEN name LIKE ? ESCAPE \'\\\' THEN 0 ELSE 1 END, depth, length(name), name LIMIT ' . (int) $limit;
    $st = $db->prepare($sql);
    if (!$st) { return $out; }
    $needle = str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $q);
    $i = 1;
    $st->bindValue($i++, '%' . $needle . '%', SQLITE3_TEXT);
    if ($namespace !== null) { $st->bindValue($i++, $namespace, SQLITE3_TEXT); }
    $st->bindValue($i++, $needle . '%', SQLITE3_TEXT);
    $res = $st->execute();
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
      $out[] = array('id' => $row['id'], 'name' => $row['name'], 'namespace' => $row['namespace'],
                     'depth' => $row['depth'] === null ? null : (int) $row['depth'], 'slim' => (bool) $row['slim'],
                     'obsolete' => (bool) $row['obsolete'], 'match' => stripos($row['name'], $q) === 0 ? 'prefix' : 'contains');
    }
    return $out;
  }

  /* The plant slim, in a stable order: by aspect, then depth, then name.
     The same list for every gene, so the fingerprint reads the same way
     from one record to the next. */
  public static function slimTerms() {
    $m = self::manifest();
    $list = $m && isset($m['slim_terms']) ? $m['slim_terms'] : array();
    $list = array_values(array_filter($list, function ($t) { return !in_array($t['id'], self::ROOTS, true); }));
    $rank = array_flip(self::NAMESPACE_ORDER);
    usort($list, function ($a, $b) use ($rank) {
      $ra = isset($rank[$a['namespace']]) ? $rank[$a['namespace']] : 9;
      $rb = isset($rank[$b['namespace']]) ? $rank[$b['namespace']] : 9;
      if ($ra !== $rb) { return $ra < $rb ? -1 : 1; }
      $da = $a['depth'] === null ? 99 : $a['depth'];
      $db = $b['depth'] === null ? 99 : $b['depth'];
      if ($da !== $db) { return $da < $db ? -1 : 1; }
      return strcmp($a['name'], $b['name']);
    });
    return $list;
  }

  /* Everything the record needs for a set of ids: each term (obsolete ones
     followed to their replacement for ancestry), its plant-slim ancestors,
     and the reduced ancestry graph over annotated terms + slim ancestors +
     roots. */
  public static function annotate(array $ids) {
    $ids = array_values(array_unique(array_filter($ids, array('MgdbGo', 'validId'))));
    $terms = self::terms($ids);

    /* An id the release no longer lists may be a merged one: its survivor
       answers for it. Obsolete terms carry no ancestry of their own;
       follow replaced_by. */
    $alts = self::altOf(array_values(array_diff($ids, array_keys($terms))));
    $terms2 = $terms + self::terms(array_values($alts));
    $primaryOf = array();
    $live = array();
    foreach ($ids as $id) {
      $pid = isset($terms[$id]) ? $id : (isset($alts[$id]) ? $alts[$id] : null);
      if ($pid === null || !isset($terms2[$pid])) { continue; }
      $primaryOf[$id] = $pid;
      $t = $terms2[$pid];
      $live[$id] = ($t['obsolete'] && !empty($t['replaced_by']) && self::validId($t['replaced_by'])) ? $t['replaced_by'] : $pid;
    }
    $liveIds = array_values(array_unique(array_values($live)));
    $liveTerms = $terms2 + self::terms(array_diff($liveIds, array_keys($terms2)));
    $anc = self::ancestors($liveIds);

    $ancIds = array();
    foreach ($anc as $list) { foreach ($list as $a) { $ancIds[$a] = true; } }
    $ancTerms = self::terms(array_keys($ancIds));

    $out = array();
    $slimNodes = array();
    foreach ($ids as $id) {
      if (!isset($primaryOf[$id])) {
        $out[$id] = array('id' => $id, 'known' => false, 'name' => null, 'namespace' => null, 'definition' => null,
                          'depth' => null, 'obsolete' => false, 'replaced_by' => null, 'merged_into' => null, 'slim' => false, 'slim_ancestors' => array());
        continue;
      }
      $t = $terms2[$primaryOf[$id]];
      $lid = $live[$id];
      /* The roots are tagged goslim_plant too; they say nothing about the
         gene, so they stay out of the slim ancestry (the graph keeps them). */
      $slim = array();
      if (isset($liveTerms[$lid]) && $liveTerms[$lid]['slim'] && !in_array($lid, self::ROOTS, true)) {
        $slim[] = array('id' => $lid, 'name' => $liveTerms[$lid]['name'], 'depth' => $liveTerms[$lid]['depth']);
        $slimNodes[$lid] = true;
      }
      foreach (isset($anc[$lid]) ? $anc[$lid] : array() as $a) {
        if (isset($ancTerms[$a]) && $ancTerms[$a]['slim'] && !in_array($a, self::ROOTS, true)) {
          $slim[] = array('id' => $a, 'name' => $ancTerms[$a]['name'], 'depth' => $ancTerms[$a]['depth']);
          $slimNodes[$a] = true;
        }
      }
      usort($slim, function ($a, $b) { return $a['depth'] == $b['depth'] ? strcmp($a['name'], $b['name']) : ($a['depth'] < $b['depth'] ? -1 : 1); });
      $out[$id] = array(
        'id' => $id, 'known' => true, 'name' => $t['name'], 'namespace' => $t['namespace'], 'definition' => $t['definition'],
        'depth' => $t['depth'], 'obsolete' => $t['obsolete'], 'replaced_by' => $t['replaced_by'], 'slim' => $t['slim'],
        'resolved_as' => $lid !== $id ? $lid : null,
        'merged_into' => $primaryOf[$id] !== $id ? $primaryOf[$id] : null,
        'slim_ancestors' => $slim
      );
    }

    /* The graph: annotated (live) terms, their slim ancestors, the roots. */
    $nodeIds = array();
    foreach ($liveIds as $lid) { $nodeIds[$lid] = true; }
    foreach ($slimNodes as $s => $_) { $nodeIds[$s] = true; }
    foreach (self::ROOTS as $root) {
      foreach ($liveIds as $lid) {
        $ns = isset($liveTerms[$lid]) ? $liveTerms[$lid]['namespace'] : null;
        if ($ns !== null && self::ROOTS[$ns] === $root) { $nodeIds[$root] = true; break; }
      }
    }
    $nodeList = array_keys($nodeIds);
    $nodeTerms = $liveTerms + $ancTerms + self::terms(array_diff($nodeList, array_keys($liveTerms), array_keys($ancTerms)));
    $ancS = self::ancestors($nodeList);
    $annotated = array_flip($liveIds);
    $nodes = array();
    foreach ($nodeList as $n) {
      if (!isset($nodeTerms[$n])) { continue; }
      $t = $nodeTerms[$n];
      $kind = in_array($n, self::ROOTS, true) ? 'root' : (isset($annotated[$n]) ? 'term' : 'slim');
      $nodes[] = array('id' => $n, 'name' => $t['name'], 'namespace' => $t['namespace'], 'depth' => $t['depth'],
                       'kind' => $kind, 'annotated' => isset($annotated[$n]), 'slim' => $t['slim']);
    }
    $edges = array();
    foreach ($nodeList as $n) {
      $cand = array();
      foreach (isset($ancS[$n]) ? $ancS[$n] : array() as $a) { if (isset($nodeIds[$a])) { $cand[$a] = true; } }
      foreach (array_keys($cand) as $a) {
        $implied = false;
        foreach (array_keys($cand) as $m) {
          if ($m === $a) { continue; }
          if (isset($ancS[$m]) && in_array($a, $ancS[$m], true)) { $implied = true; break; }
        }
        if (!$implied) { $edges[] = array($n, $a); }
      }
    }
    return array('terms' => $out, 'graph' => array('nodes' => $nodes, 'edges' => $edges), 'release' => self::release());
  }
}
