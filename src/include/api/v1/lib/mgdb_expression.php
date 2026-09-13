<?php
/* file: api/v1/lib/mgdb_expression.php
 *
 * purpose: read the expression release files tools/expression_index.py
 *          writes under data/expression/<genome>/, for the expression
 *          dataset route and for the gene record's Expression section.
 *
 *          A release is one SQLite file: the sample catalogue (studies and
 *          samples, with a tissue reading of each label) and one precomputed
 *          profile row per gene and assay -- the values as a JSON array
 *          aligned with the samples, plus the mean, median, maximum, tau and
 *          detection count. A gene is one primary-key read, measured at
 *          0.15-0.23 ms from PHP's own SQLite on a table of this shape, so
 *          the record page embeds the profile rather than fetching it.
 *
 *          Everything is read-only: SQLITE3_OPEN_READONLY, and the files
 *          are denied to the browser by data/expression/.htaccess.
 *
 * history:
 *  09/12/26  claude  created
 */

// Reachable only through controllers/api.php.
if (!defined('MGDB_API')) { http_response_code(404); exit; }

class MgdbExpression {

  private static $dbs = array();
  private static $catalogs = array();

  const TOP = 8;

  /* by_condition is listed in this order, not by mean: the stressed samples first, their controls after. */

  const CONDITION_ORDER = array('abiotic stress', 'biotic stress', 'control', 'stress study');

  public static function path($genome) {
    return MgdbData::dir('expression') . '/' . $genome . '/expression.sqlite';
  }

  public static function available($genome) {
    return $genome !== null && $genome !== ''
        && MgdbData::manifest('expression', $genome) !== null
        && is_file(self::path($genome));
  }

  private static function db($genome) {
    if (!array_key_exists($genome, self::$dbs)) {
      self::$dbs[$genome] = null;
      if (class_exists('SQLite3') && is_file(self::path($genome))) {
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

  /* Studies and samples of a release, once per request. */
  public static function catalog($genome) {
    if (isset(self::$catalogs[$genome])) { return self::$catalogs[$genome]; }
    $db = self::db($genome);
    $out = array('sources' => array(), 'samples' => array(), 'by_assay' => array());
    if ($db === null) { self::$catalogs[$genome] = $out; return $out; }
    $res = $db->query('SELECT id, name, assay, link, description, sample_count, stress FROM sources ORDER BY id');
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
      $row['stress'] = (bool) $row['stress'];
      $out['sources'][$row['id']] = $row;
    }
    $res = $db->query('SELECT id, ord, assay, stub, label, source_id, tissue, condition FROM samples ORDER BY ord');
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
      $src = isset($out['sources'][$row['source_id']]) ? $out['sources'][$row['source_id']] : null;
      $row['source'] = $src ? $src['name'] : null;
      $row['source_link'] = $src ? $src['link'] : null;
      $out['samples'][$row['id']] = $row;
      $out['by_assay'][$row['assay']][] = $row['id'];
    }
    self::$catalogs[$genome] = $out;
    return $out;
  }

  public static function assays($genome) {
    return array_keys(self::catalog($genome)['by_assay']);
  }

  /* The profile rows of one gene: assay => row, with values decoded. */
  private static function rows($genome, $gene) {
    $db = self::db($genome);
    if ($db === null) { return array(); }
    $stmt = $db->prepare('SELECT gene, assay, n, n_present, n_detected, mean, median, max, max_sample, tau, "values" FROM profiles WHERE gene = :g');
    $stmt->bindValue(':g', $gene, SQLITE3_TEXT);
    $res = $stmt->execute();
    $rows = array();
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
      $row['values'] = json_decode($row['values'], true);
      $rows[$row['assay']] = $row;
    }
    return $rows;
  }

  /* Case-insensitive: gene ids are stored as published (Zm00001eb067740),
     and a request may spell them otherwise. One extra read on a miss. */
  public static function exists($genome, $gene) {
    return count(self::rows($genome, $gene)) > 0;
  }

  public static function resolveCase($genome, $gene) {
    $db = self::db($genome);
    if ($db === null) { return null; }
    $stmt = $db->prepare('SELECT gene FROM profiles WHERE gene = :g COLLATE NOCASE LIMIT 1');
    $stmt->bindValue(':g', $gene, SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res->fetchArray(SQLITE3_NUM);
    return $row ? $row[0] : null;
  }

  /* The full answer for one gene, in the shape the route serves and the
     record embeds. $assay is rna, protein or all. Sections can be limited to
     keep a batch small. */
  public static function profile($genome, $gene, $assay = 'all', $sections = array('summary', 'samples', 'sources')) {
    $manifest = MgdbData::manifest('expression', $genome);
    $rows = self::rows($genome, $gene);
    if (count($rows) === 0) { return null; }
    $catalog = self::catalog($genome);
    $assays = array_keys($rows);
    if ($assay !== 'all') { $assays = array_values(array_intersect($assays, array($assay))); }

    $summary = array();
    $samples = array();
    $sourceIds = array();
    foreach ($assays as $a) {
      $row = $rows[$a];
      $ids = isset($catalog['by_assay'][$a]) ? $catalog['by_assay'][$a] : array();
      $values = $row['values'];
      $items = array();
      foreach ($ids as $i => $sid) {
        $s = $catalog['samples'][$sid];
        $v = isset($values[$i]) ? $values[$i] : null;
        $items[] = array('id' => $sid, 'assay' => $a, 'label' => $s['label'], 'source' => $s['source'],
                         'source_id' => $s['source_id'], 'tissue' => $s['tissue'], 'condition' => $s['condition'],
                         'value' => $v);
        $sourceIds[$s['source_id']] = true;
      }
      $summary[$a] = self::summarize($row, $items, $a, $manifest);
      foreach ($items as $it) { $samples[] = $it; }
    }

    $sources = array();
    foreach ($catalog['sources'] as $sid => $src) {
      if (!isset($sourceIds[$sid])) { continue; }
      $sources[] = array('id' => $sid, 'name' => $src['name'], 'assay' => $src['assay'], 'link' => $src['link'],
                         'description' => $src['description'], 'sample_count' => (int) $src['sample_count'],
                         'stress' => $src['stress']);
    }

    $base = MgdbApi::baseUrl();
    $out = array(
      'type' => 'expression_profile',
      'id' => $rows[$assays[0]]['gene'],
      'attributes' => array(
        'gene' => $rows[$assays[0]]['gene'],
        'genome' => $genome,
        'release' => isset($manifest['release']) ? $manifest['release'] : null,
        'assays' => $assays,
        'sample_count' => count($samples),
        'source_count' => count($sources),
        'units_note' => isset($manifest['units_note']) ? $manifest['units_note'] : null,
        'tissue_note' => isset($manifest['tissue_note']) ? $manifest['tissue_note'] : null,
        'condition_note' => isset($manifest['condition_note']) ? $manifest['condition_note'] : null
      ),
      'sections' => array(),
      'links' => array(
        'api' => $base . '/api/v1/data/expression/' . $genome . '/' . rawurlencode($rows[$assays[0]]['gene']),
        'tsv' => $base . '/api/v1/data/expression/' . $genome . '/' . rawurlencode($rows[$assays[0]]['gene']) . '?format=tsv',
        'qteller' => self::qtellerUrl($genome, $rows[$assays[0]]['gene'], $manifest)
      )
    );
    if (in_array('summary', $sections, true)) { $out['sections']['summary'] = $summary; }
    if (in_array('samples', $sections, true)) { $out['sections']['samples'] = $samples; }
    if (in_array('sources', $sections, true)) { $out['sections']['sources'] = $sources; }
    return $out;
  }

  /* The numbers a reader wants first: how many samples, how many with the
     gene detected, the mean and median, where it is highest, how
     tissue-specific it is (tau, Yanai 2005, on log2(value + 1)), the top
     samples, and the same figures per tissue reading and per study. */
  public static function summarize($row, $items, $assay, $manifest) {
    $present = array_values(array_filter($items, function ($it) { return $it['value'] !== null; }));
    usort($present, function ($a, $b) {
      if ($a['value'] == $b['value']) { return 0; }
      return $a['value'] > $b['value'] ? -1 : 1;
    });
    $top = array();
    foreach (array_slice($present, 0, self::TOP) as $it) {
      $top[] = array('sample' => $it['label'], 'source' => $it['source'], 'tissue' => $it['tissue'], 'condition' => $it['condition'], 'value' => $it['value']);
    }
    $maxItem = null;
    foreach ($items as $it) {
      if ($it['id'] === (int) $row['max_sample']) { $maxItem = $it; break; }
    }

    /* Fold the samples three ways: by tissue reading, by study, and by
       stress condition (only stress-study samples carry one). */
    $byTissue = array();
    $bySource = array();
    $byCondition = array();
    $add = function (&$buckets, $key, $it) {
      if (!isset($buckets[$key])) {
        $buckets[$key] = array('name' => $key, 'n' => 0, 'sum' => 0.0, 'max' => null, 'max_sample' => null);
      }
      $buckets[$key]['n']++;
      $buckets[$key]['sum'] += $it['value'];
      if ($buckets[$key]['max'] === null || $it['value'] > $buckets[$key]['max']) {
        $buckets[$key]['max'] = $it['value'];
        $buckets[$key]['max_sample'] = $it['label'];
      }
    };
    foreach ($items as $it) {
      if ($it['value'] === null) { continue; }
      $add($byTissue, $it['tissue'], $it);
      $add($bySource, $it['source'], $it);
      if (!empty($it['condition'])) { $add($byCondition, $it['condition'], $it); }
    }
    $finish = function ($buckets, $order = null) {
      $out = array();
      foreach ($buckets as $b) {
        $out[] = array('name' => $b['name'], 'samples' => $b['n'], 'mean' => (float) sprintf('%.4g', $b['sum'] / $b['n']),
                       'max' => $b['max'], 'max_sample' => $b['max_sample']);
      }
      if ($order === null) {
        usort($out, function ($a, $b) { return $a['mean'] == $b['mean'] ? 0 : ($a['mean'] > $b['mean'] ? -1 : 1); });
      } else {
        usort($out, function ($a, $b) use ($order) {
          $ia = array_search($a['name'], $order); $ib = array_search($b['name'], $order);
          $ia = $ia === false ? count($order) : $ia; $ib = $ib === false ? count($order) : $ib;
          return $ia === $ib ? strcmp($a['name'], $b['name']) : ($ia < $ib ? -1 : 1);
        });
      }
      return $out;
    };

    $n = (int) $row['n'];
    $detected = (int) $row['n_detected'];
    $tau = $row['tau'] === null ? null : (float) $row['tau'];
    $thresholds = isset($manifest['detected_threshold']) ? $manifest['detected_threshold'] : array();
    return array(
      'assay' => $assay,
      'samples' => $n,
      'samples_with_value' => (int) $row['n_present'],
      'detected' => $detected,
      'detected_fraction' => $n > 0 ? round($detected / $n, 3) : null,
      'detected_rule' => isset($thresholds[$assay]) ? $thresholds[$assay] : null,
      'mean' => $row['mean'] === null ? null : (float) $row['mean'],
      'median' => $row['median'] === null ? null : (float) $row['median'],
      'max' => $row['max'] === null ? null : (float) $row['max'],
      'max_sample' => $maxItem === null ? null : array('sample' => $maxItem['label'], 'source' => $maxItem['source'], 'tissue' => $maxItem['tissue']),
      'tau' => $tau,
      'specificity' => self::specificityLabel($tau),
      'top' => $top,
      'by_tissue' => $finish($byTissue),
      'by_condition' => $finish($byCondition, self::CONDITION_ORDER),
      'by_source' => $finish($bySource)
    );
  }

  /* Tau reads 0 for a gene expressed evenly everywhere and 1 for one
     expressed in a single sample. The bands are the usual reading of it. */
  public static function specificityLabel($tau) {
    if ($tau === null) { return null; }
    if ($tau < 0.35) { return 'broadly expressed'; }
    if ($tau < 0.7) { return 'intermediate'; }
    return 'tissue-specific';
  }

  public static function qtellerUrl($genome, $gene, $manifest) {
    $templates = isset($manifest['qteller']) ? $manifest['qteller'] : array();
    $key = null;
    if ($genome === 'Zm-B73-REFERENCE-NAM-5.0') { $key = 'B73v5'; }
    elseif ($genome === 'Zm-B73-REFERENCE-GRAMENE-4.0') { $key = 'B73v4'; }
    elseif (substr($genome, -14) === '-REFERENCE-NAM' || preg_match('/-REFERENCE-NAM-1\.0$/', $genome)) { $key = 'NAM'; }
    if ($key === null || !isset($templates[$key])) { return null; }
    return str_replace('{gene}', rawurlencode($gene), $templates[$key]);
  }

  /* TSV: one row per sample. */
  public static function tsvRows($profile) {
    $rows = array();
    $gene = $profile['attributes']['gene'];
    foreach ((isset($profile['sections']['samples']) ? $profile['sections']['samples'] : array()) as $s) {
      $rows[] = array('gene' => $gene, 'assay' => $s['assay'], 'sample' => $s['label'], 'source' => $s['source'],
                      'tissue' => $s['tissue'], 'condition' => $s['condition'], 'value' => $s['value']);
    }
    return $rows;
  }

  public static function tsvColumns() {
    return array('gene', 'assay', 'sample', 'source', 'tissue', 'condition', 'value');
  }
}
