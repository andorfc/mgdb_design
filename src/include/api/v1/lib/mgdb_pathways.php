<?php
/**
 * MgdbPathways -- what the pan-genome pathway explorer knows about one gene,
 * read from its static payload under data/projects/pathway_explorer/ for
 * the gene record's Function section.
 *
 * The explorer shards its gene-to-step assignments by sha1 of the lowercase
 * gene id (genes/<3 hex>.json), keeps every pathway's steps in
 * pathway/<id>.json, and its catalogue in index.json. forGene() reads the
 * one shard, then the index and one file per pathway the gene sits in, and
 * returns each pathway with its ordered reaction steps: which step this
 * gene fills, which steps this genome has a gene for at all, and how many
 * of the NAM founders have the pathway. Reads are capped so a promiscuous
 * enzyme cannot pull in dozens of files.
 *
 * history:
 *  09/12/26  claude  created
 */

class MgdbPathways {
  const MAX_PATHWAY_FILES = 12;
  const MAX_GENES_PER_STEP = 8;

  private static $index = false;
  private static $reads = 0;

  public static function root() {
    $root = (isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '') ? $_SERVER['DOCUMENT_ROOT'] : getcwd();
    return rtrim($root, '/') . '/data/projects/pathway_explorer';
  }

  public static function available() {
    return is_file(self::root() . '/index.json');
  }

  private static function readJson($path) {
    if (!is_file($path)) { return null; }
    self::$reads++;
    $raw = file_get_contents($path);
    $doc = $raw === false ? null : json_decode($raw, true);
    return is_array($doc) ? $doc : null;
  }

  public static function fileReads() { return self::$reads; }

  public static function index() {
    if (self::$index === false) {
      self::$index = self::readJson(self::root() . '/index.json');
    }
    return self::$index;
  }

  public static function geneEntry($gene) {
    $key = strtolower(trim((string) $gene));
    if ($key === '' || !preg_match('/^[a-z0-9_.-]+$/', $key)) { return null; }
    $shard = substr(sha1($key), 0, 3);
    $doc = self::readJson(self::root() . '/genes/' . $shard . '.json');
    return ($doc !== null && isset($doc[$key])) ? $doc[$key] : null;
  }

  /* The explorer's shortest breadcrumb of a class path: the last two levels. */
  private static function classTail($cls) {
    if (!is_string($cls) || $cls === '') { return null; }
    $parts = array_map('trim', explode('>', $cls));
    return implode(' › ', array_slice($parts, -2));
  }

  public static function forGene($gene) {
    if (!self::available()) { return null; }
    $entry = self::geneEntry($gene);
    $base = array('available' => true, 'gene' => $gene, 'genome' => null, 'pathways' => array(),
                  'counts' => array('pathways' => 0, 'core' => 0, 'reactions' => 0, 'files_read' => 0, 'truncated' => false),
                  'explorer' => '/projects/pathway_explorer',
                  'source' => 'Pan-genome pathway explorer: E2P2 pathway annotation of the 26 NAM founder genomes beside CornCyc 8.0.');
    if ($entry === null || empty($entry['a'])) { return $base; }
    $idx = self::index();
    if ($idx === null || empty($idx['pathways']) || empty($idx['genomes'])) { return $base; }

    $track = isset($entry['k']) ? $entry['k'] : null;
    $genomes = $idx['genomes'];
    $pos = null;
    $label = $track;
    foreach ($genomes as $i => $g) {
      if ($g['id'] === $track) { $pos = $i; $label = isset($g['label']) ? $g['label'] : $track; break; }
    }
    $corncyc = isset($idx['corncyc_track']) ? $idx['corncyc_track'] : 'CORNCYC8';
    $founders = array();
    foreach ($genomes as $i => $g) { if ($g['id'] !== $corncyc) { $founders[] = array('i' => $i, 'id' => $g['id']); } }

    /* Group the assignments by pathway; keep the reactions and the strongest
       evidence label the explorer recorded for each. */
    $byPathway = array();
    $reactions = array();
    foreach ($entry['a'] as $a) {
      if (!is_array($a) || count($a) < 2) { continue; }
      $pi = (int) $a[0];
      $rxn = (string) $a[1];
      $ev = isset($a[2]) ? $a[2] : null;
      $reactions[$rxn] = true;
      if (!isset($byPathway[$pi])) { $byPathway[$pi] = array(); }
      if (!isset($byPathway[$pi][$rxn]) || ($byPathway[$pi][$rxn] === null && $ev !== null)) { $byPathway[$pi][$rxn] = $ev; }
    }

    $items = array();
    foreach ($byPathway as $pi => $rxns) {
      if (!isset($idx['pathways'][$pi])) { continue; }
      $p = $idx['pathways'][$pi];
      $items[] = array('p' => $p, 'rxns' => $rxns);
    }
    usort($items, function ($a, $b) {
      $ra = $a['p']['pan'] === 'core' ? 0 : 1;
      $rb = $b['p']['pan'] === 'core' ? 0 : 1;
      if ($ra !== $rb) { return $ra < $rb ? -1 : 1; }
      return strcasecmp($a['p']['np'], $b['p']['np']);
    });

    $out = array();
    $files = 0;
    $core = 0;
    foreach ($items as $it) {
      $p = $it['p'];
      if ($p['pan'] === 'core') { $core++; }
      $mine = array();
      foreach ($it['rxns'] as $rxn => $ev) { $mine[] = array('reaction' => $rxn, 'evidence' => $ev); }
      $item = array(
        'id' => $p['id'],
        'name' => isset($p['np']) ? $p['np'] : $p['n'],
        'name_html' => $p['n'],
        'class' => isset($p['cls']) ? $p['cls'] : null,
        'class_tail' => self::classTail(isset($p['cls']) ? $p['cls'] : null),
        'pan' => $p['pan'],
        'variability' => isset($p['var']) ? $p['var'] : null,
        'reactions' => isset($p['nr']) ? (int) $p['nr'] : null,
        'mean_completeness' => isset($p['mc']) ? $p['mc'] : null,
        'in_e2p2' => !empty($p['e2p2']),
        'in_corncyc' => !empty($p['cc']),
        'this_gene' => $mine,
        'url' => '/projects/pathway_explorer#pathway=' . rawurlencode($p['id']),
        'steps' => null, 'genome' => null, 'presence' => null, 'present_in' => null, 'founders' => count($founders),
        'links' => array()
      );
      if ($files < self::MAX_PATHWAY_FILES) {
        $doc = self::readJson(self::root() . '/pathway/' . $p['id'] . '.json');
        $files++;
        if ($doc !== null) {
          $steps = array();
          foreach (isset($doc['steps']) ? $doc['steps'] : array() as $s) {
            if (!empty($s['sub'])) { continue; }
            $counts = isset($s['counts']) && is_array($s['counts']) ? $s['counts'] : array();
            $here = ($pos !== null && isset($counts[$pos])) ? (int) $counts[$pos] : 0;
            $filledFounders = 0;
            foreach ($founders as $f) { if (isset($counts[$f['i']]) && $counts[$f['i']] > 0) { $filledFounders++; } }
            $genesHere = array();
            if ($track !== null && isset($s['genes'][$track])) {
              foreach (array_slice($s['genes'][$track], 0, self::MAX_GENES_PER_STEP) as $g) {
                $genesHere[] = array('gene' => $g['g'], 'symbol' => isset($g['s']) ? $g['s'] : null, 'name' => isset($g['nm']) ? $g['nm'] : null);
              }
            }
            $steps[] = array(
              'reaction' => $s['r'],
              'ec' => isset($s['ec']) ? $s['ec'] : null,
              'enzyme' => isset($s['en']) ? $s['en'] : null,
              'common_name' => isset($s['cn']) ? $s['cn'] : null,
              'equation' => isset($s['eq']) ? $s['eq'] : null,
              'order' => isset($s['ord']) ? (int) $s['ord'] : null,
              'this_gene' => isset($it['rxns'][$s['r']]),
              'genes_here' => $here,
              'filled' => $here > 0,
              'founders_filled' => $filledFounders,
              'occurrence' => isset($s['occ']) ? $s['occ'] : null,
              'gap_class' => isset($s['gc']) ? $s['gc'] : null,
              'genes' => $genesHere,
              'genes_total_here' => ($track !== null && isset($s['genes'][$track])) ? count($s['genes'][$track]) : 0
            );
          }
          usort($steps, function ($a, $b) { return $a['order'] == $b['order'] ? strcmp($a['reaction'], $b['reaction']) : ($a['order'] < $b['order'] ? -1 : 1); });
          $item['steps'] = $steps;
          if ($track !== null && isset($doc['stats'][$track]) && is_array($doc['stats'][$track])) {
            $st = $doc['stats'][$track];
            $item['genome'] = array('steps_filled' => isset($st[0]) ? (int) $st[0] : null, 'genes' => isset($st[1]) ? (int) $st[1] : null,
                                    'completeness' => isset($st[2]) ? $st[2] : null);
          }
          $present = isset($doc['genomes']) && is_array($doc['genomes']) ? array_flip($doc['genomes']) : array();
          $presence = array();
          $n = 0;
          foreach ($founders as $f) {
            $on = isset($present[$f['id']]);
            if ($on) { $n++; }
            $presence[] = array('genome' => $f['id'], 'present' => $on);
          }
          $item['presence'] = $presence;
          $item['present_in'] = $n;
          $item['links'] = array(
            'metacyc' => !empty($doc['metacyc']) ? 'https://metacyc.org/pathway?orgid=META&id=' . rawurlencode($doc['metacyc']) : null,
            'plantcyc' => !empty($doc['plantcyc']) ? 'https://pmn.plantcyc.org/PLANT/NEW-IMAGE?type=PATHWAY&object=' . rawurlencode($doc['plantcyc']) : null
          );
        }
      }
      $out[] = $item;
    }
    $base['genome'] = $track;
    $base['genome_label'] = $label;
    $base['pathways'] = $out;
    $base['counts'] = array('pathways' => count($out), 'core' => $core, 'reactions' => count($reactions),
                            'files_read' => $files, 'truncated' => count($out) > $files);
    return $base;
  }
}
