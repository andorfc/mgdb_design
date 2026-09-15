<?php
/* file: tools/sequence/sequence_mirror.php
 *
 * purpose: mirror a published FASTA from download.maizegdb.org onto local disk
 *          with an index, so tools/sequence/get_sequence.php can answer from
 *          the filesystem instead of asking fasta.maizegdb.org.
 *
 * WHY. get_sequence.php hands fasta.maizegdb.org a download.maizegdb.org URL
 * and that service range-reads it. For an identifier Cloudflare has cached the
 * answer comes back in about 50 ms; for a cold one it takes 1 to 1.5 seconds
 * and, measured on 2026-09-15, fails outright with a 502 perhaps a quarter of
 * the time in bursts of a few seconds. Meanwhile the same 10 MB file downloads
 * from download.maizegdb.org in 0.6 s. Nothing about the data is slow -- the
 * round trip is. A file mirrored here is read with one seek in about a
 * millisecond and cannot fail.
 *
 * USAGE
 *   php tools/sequence/sequence_mirror.php --build <assembly> <file.fa.gz>
 *   php tools/sequence/sequence_mirror.php --defaults        # the sets the record pages link to
 *   php tools/sequence/sequence_mirror.php --list            # what is mirrored, and how old
 *   php tools/sequence/sequence_mirror.php --check <assembly> <file.fa.gz>
 *
 * Run it from the web root. It is a build tool: nothing in a web request ever
 * downloads or indexes, so a missing mirror costs nothing but the old path.
 *
 * LAYOUT, under data/sequence/, mirroring the download tree:
 *   <assembly>/<file>.faz       the sequences, in deflated 64 KB blocks
 *   <assembly>/<file>.faz.idx   fixed-width sorted index, binary searched
 *
 * The index is fixed width on purpose: every row is the same number of bytes,
 * so a lookup is a binary search with no line scanning and no parse of
 * anything but the row it lands on.
 *
 * The sequences are stored in independently deflated blocks of about 64 KB, so
 * a read is one seek, one 20 KB read and one inflate -- 0.22 ms, measured --
 * rather than a seek into a plain file. Blocks because per-record compression
 * is nearly pointless on this data: 1.68x a record at a time against 3.16x in
 * 64 KB blocks, since neighbouring maize proteins share a great deal. Plain
 * FASTA would be 24 GB for every assembly MaizeGDB publishes and there are 18
 * free; blocked, the whole corpus is about 8 GB.
 */

  if (php_sapi_name() !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    echo "This is a command line tool.\n";
    exit(1);
  }

  define('SM_DATA_URL', 'https://download.maizegdb.org');
  define('SM_STORE', 'data/sequence');
  define('SM_BLOCK', 65536);      // uncompressed bytes per deflate block
  /* Level 3, not the default 6. On protein the two are the same (3.16x) but on
     DNA level 6 spends a very long time on long repeats for little: measured on
     one NAM CDS file, 5.12x at 8.5 MB/s against 4.62x at 39 MB/s. Four and a
     half times the build for a tenth of the size is the wrong trade when the
     whole corpus has to be rebuilt after every annotation release. */
  define('SM_LEVEL', 3);

  /* The sequence types a gene record links to, in the order they are worth
     having. "canonical" is the subset file: it is only ever a fallback, and it
     is here because Il14H has no plain cds.fa.gz published at all. Genomic
     (gene) and whole-assembly FASTA are deliberately absent -- hundreds of
     megabytes to gigabytes each, and asked for rarely. */
  $SM_TYPES = array('protein', 'cds', 'cdna', 'nc.protein', 'nc.cds', 'canonical.cds');

  /* Shapes get_sequence.php's fallback ladder can ask for but which are not
     worth mirroring. They are probed with a HEAD and the 404s written to
     absent.json, so the server skips them instead of spending a retry ladder
     on a service that reports a missing file as a 502. nc.cdna and
     canonical.protein are published nowhere; canonical.cdna is published
     everywhere and is a duplicate of cdna, so it is left on the service. */
  $SM_PROBE_TYPES = array('nc.cdna', 'canonical.protein', 'canonical.cdna', 'canonical.cds');

  /* assembly => annotation, grouped so a rebuild can be scoped.

     B73 RefGen_v3's files are not named after the assembly, so it is listed as
     explicit filenames instead. Zm-B73_AB10-REFERENCE-NAM-1.0 is deliberately
     absent: its gene-model files carry an "evd." infix nothing else uses, and
     its models are not in chado.transcript, so no record page can reach them. */
  $SM_SETS = array(
    'core' => array(
      'Zm-B73-REFERENCE-NAM-5.0'     => 'Zm00001eb.1',
      'Zm-B73-REFERENCE-GRAMENE-4.0' => 'Zm00001d.2'
    ),
    'nam' => array(
      'Zm-B97-REFERENCE-NAM-1.0'   => 'Zm00018ab.1',
      'Zm-CML52-REFERENCE-NAM-1.0' => 'Zm00019ab.1',
      'Zm-CML69-REFERENCE-NAM-1.0' => 'Zm00020ab.1',
      'Zm-CML103-REFERENCE-NAM-1.0' => 'Zm00021ab.1',
      'Zm-CML228-REFERENCE-NAM-1.0' => 'Zm00022ab.1',
      'Zm-CML247-REFERENCE-NAM-1.0' => 'Zm00023ab.1',
      'Zm-CML277-REFERENCE-NAM-1.0' => 'Zm00024ab.1',
      'Zm-CML322-REFERENCE-NAM-1.0' => 'Zm00025ab.1',
      'Zm-CML333-REFERENCE-NAM-1.0' => 'Zm00026ab.1',
      'Zm-HP301-REFERENCE-NAM-1.0' => 'Zm00027ab.1',
      'Zm-Il14H-REFERENCE-NAM-1.0' => 'Zm00028ab.1',
      'Zm-Ki3-REFERENCE-NAM-1.0'   => 'Zm00029ab.1',
      'Zm-Ki11-REFERENCE-NAM-1.0'  => 'Zm00030ab.1',
      'Zm-Ky21-REFERENCE-NAM-1.0'  => 'Zm00031ab.1',
      'Zm-M37W-REFERENCE-NAM-1.0'  => 'Zm00032ab.1',
      'Zm-M162W-REFERENCE-NAM-1.0' => 'Zm00033ab.1',
      'Zm-Mo18W-REFERENCE-NAM-1.0' => 'Zm00034ab.1',
      'Zm-Ms71-REFERENCE-NAM-1.0'  => 'Zm00035ab.1',
      'Zm-NC350-REFERENCE-NAM-1.0' => 'Zm00036ab.1',
      'Zm-NC358-REFERENCE-NAM-1.0' => 'Zm00037ab.1',
      'Zm-Oh7B-REFERENCE-NAM-1.0'  => 'Zm00038ab.1',
      'Zm-Oh43-REFERENCE-NAM-1.0'  => 'Zm00039ab.1',
      'Zm-P39-REFERENCE-NAM-1.0'   => 'Zm00040ab.1',
      'Zm-Tx303-REFERENCE-NAM-1.0' => 'Zm00041ab.1',
      'Zm-Tzi8-REFERENCE-NAM-1.0'  => 'Zm00042ab.1'
    )
  );

  /* Files whose names do not follow <assembly>_<annotation>, so --discover
     cannot find them: B73 v1, v2 and v3, and v4's provisional gene models.
     The names are the ones getLegacyGeneModelFile() in get_sequence.php asks
     for, so mirroring them under the same path is all it takes.

     v1 and v2 matter more than they look. Neither has a .fai or .gzi
     published, so fasta.maizegdb.org cannot open either and has never returned
     a single v1 or v2 sequence (AD-077). This tool does not need an index --
     it downloads the file and builds its own -- so mirroring them makes those
     two assemblies work for the first time, without waiting for anybody. */
  $SM_FILES = array(
    'core' => array(
      array('B73_RefGen_v3', 'Zea_mays.AGPv3.21.protein.fa.gz'),
      array('B73_RefGen_v3', 'Zea_mays.AGPv3.21.cds.fa.gz'),
      array('B73_RefGen_v3', 'Zea_mays.AGPv3.21.transcripts.fa.gz'),
      array('B73_RefGen_v3', 'Zea_mays.AGPv3.21.genes.all.fa.gz'),
      array('B73_RefGen_v2', 'ZmB73_5a.59_working.translations.fasta.gz'),
      array('B73_RefGen_v2', 'ZmB73_5a.59_working.cds.fasta.gz'),
      array('B73_RefGen_v2', 'ZmB73_5a.59_working.cdna.fasta.gz'),
      array('B73_RefGen_v2', 'ZmB73_5a.59_working.genes.fasta.gz'),
      array('B73_RefGen_v1', 'ZmB73_4a.53_working_translations.fasta.gz'),
      array('B73_RefGen_v1', 'ZmB73_4a.53_working_cdna.fasta.gz'),
      array('B73_RefGen_v1', 'ZmB73_4a.53_working_genes.fasta.gz'),
      /* v1 publishes no working_cds, only filtered_cds, which is a different
         gene set -- left alone, and recorded in absent.json. */
      array('B73_RefGen_v1', 'ZmB73_4a.53_working_cds.fasta.gz'),
      array('Zm-B73-REFERENCE-GRAMENE-4.0', 'Zm-B73-REFERENCE-GRAMENE-4.0_Zm00001d.provisional.protein.fa.gz'),
      array('Zm-B73-REFERENCE-GRAMENE-4.0', 'Zm-B73-REFERENCE-GRAMENE-4.0_Zm00001d.provisional.transcripts.fa.gz'),
      array('Zm-B73-REFERENCE-GRAMENE-4.0', 'Zm-B73-REFERENCE-GRAMENE-4.0_Zm00001d.2.transcripts.fa.gz'),
      array('Zm-B73-REFERENCE-NAM-5.0', 'Zm-B73-REFERENCE-NAM-5.0_Zm00001eb.1.gene.fa.gz')
    )
  );

  $argv0 = array_shift($argv);
  $cmd = isset($argv[0]) ? $argv[0] : '--list';

  if ($cmd === '--defaults' || $cmd === '--core' || $cmd === '--nam'
      || $cmd === '--all' || $cmd === '--rest') {
    /* --all and --rest work from the discovered set when there is one, so a
       genome added to chado.genome_metadata is picked up by re-running
       --discover rather than by editing this file. */
    $discovered = smLoadSets();
    if ($discovered !== null && ($cmd === '--all' || $cmd === '--rest')) {
      /* One entry per assembly, so nothing is downloaded twice: --all is the
         discovered set plus the few files whose names do not follow the
         <assembly>_<annotation> pattern, and --rest drops what --core and
         --nam already cover. */
      $SM_SETS['discovered'] = array();
      foreach ($discovered as $assembly => $info) {
        if ($cmd === '--rest'
            && (isset($SM_SETS['core'][$assembly]) || isset($SM_SETS['nam'][$assembly]))) {
          continue;
        }
        $SM_SETS['discovered'][$assembly] = $info['annotation'];
      }
      $groups = array('discovered');
      if ($cmd === '--all') { $SM_FILES['discovered'] = $SM_FILES['core']; }
    }
    else {
      $groups = ($cmd === '--nam') ? array('nam')
              : (($cmd === '--all' || $cmd === '--rest') ? array('core', 'nam') : array('core'));
    }
    $built = 0;
    $skipped = 0;
    $failed = 0;
    foreach ($groups as $group) {
      foreach ((isset($SM_SETS[$group]) ? $SM_SETS[$group] : array()) as $assembly => $annotation) {
        $have = array();
        foreach ($SM_TYPES as $type) {
          /* The canonical-only file duplicates the full one, so mirror it only
             where the full one is not published -- Il14H and nowhere else. */
          if (strpos($type, 'canonical.') === 0
              && !empty($have[substr($type, strlen('canonical.'))])) {
            continue;
          }
          $r = smBuild($assembly, "{$assembly}_$annotation.$type.fa.gz", true);
          if ($r === true) { $built++; $have[$type] = true; }
          else if ($r === null) { $skipped++; }
          else { $failed++; }
        }
        foreach ($SM_PROBE_TYPES as $type) {
          if (!empty($have[$type])) { continue; }
          smProbe($assembly, "{$assembly}_$annotation.$type.fa.gz");
        }
        if (!empty($SM_SETS['nam'][$assembly]) || !empty($SM_SETS['core'][$assembly])) { continue; }
      }
      foreach ((isset($SM_FILES[$group]) ? $SM_FILES[$group] : array()) as $set) {
        $r = smBuild($set[0], $set[1], true);
        if ($r === true) { $built++; } else if ($r === null) { $skipped++; } else { $failed++; }
      }
    }
    printf("\n%d built, %d not published, %d failed\n", $built, $skipped, $failed);
    exit($failed === 0 ? 0 : 1);
  }
  else if ($cmd === '--genomic') {
    /* The whole-gene (genomic) FASTA, separately, because it is the largest
       file per assembly and the least asked for: the record pages use it only
       for the "Whole gene" link and for dbtype=nuc on a gene model. */
    $discovered = smLoadSets();
    if ($discovered === null) { fwrite(STDERR, "run --discover first\n"); exit(1); }
    $built = 0; $skipped = 0; $failed = 0;
    foreach ($discovered as $assembly => $info) {
      $r = smBuild($assembly, "{$assembly}_{$info['annotation']}.gene.fa.gz", true);
      if ($r === true) { $built++; } else if ($r === null) { $skipped++; } else { $failed++; }
    }
    printf("\n%d built, %d not published, %d failed\n", $built, $skipped, $failed);
    exit($failed === 0 ? 0 : 1);
  }
  else if ($cmd === '--discover') {
    exit(smDiscover($SM_TYPES, $SM_PROBE_TYPES) ? 0 : 1);
  }
  else if ($cmd === '--probe') {
    /* Refresh absent.json without downloading anything: every shape the
       fallback ladder can ask for, HEADed. Seconds, not minutes. */
    $n = 0;
    foreach ($SM_SETS as $group => $sets) {
      foreach ($sets as $assembly => $annotation) {
        foreach (array_merge($SM_TYPES, $SM_PROBE_TYPES) as $type) {
          smProbe($assembly, "{$assembly}_$annotation.$type.fa.gz");
          $n++;
        }
      }
    }
    $list = is_file(SM_STORE . '/absent.json')
          ? json_decode((string) file_get_contents(SM_STORE . '/absent.json'), true) : array();
    printf("%d files probed, %d not published\n", $n, is_array($list) ? count($list) : 0);
    exit(0);
  }
  else if ($cmd === '--build' && isset($argv[2])) {
    exit(smBuild($argv[1], $argv[2]) === true ? 0 : 1);
  }
  else if ($cmd === '--check' && isset($argv[2])) {
    exit(smCheck($argv[1], $argv[2]) ? 0 : 1);
  }
  else if ($cmd === '--list') {
    smList();
    exit(0);
  }

  echo "usage: php tools/sequence/sequence_mirror.php [--core|--nam|--all|--list|--build <assembly> <file.fa.gz>|--check <assembly> <file.fa.gz>]\n"
     . "  --core  B73 v5, v4 and v3          --nam  the 25 NAM founder lines\n"
     . "  --all   both                       --defaults  same as --core\n"
     . "  --rest  everything discovered that --core and --nam do not cover\n"
     . "  --genomic  the whole-gene FASTA for every discovered assembly\n"
     . "  --discover  re-read chado.genome_metadata and the download listings\n"
     . "  --probe refresh absent.json with HEAD requests, no downloads\n";
  exit(2);


/* Download, decompress and index one published FASTA. Everything is written
   beside the target and renamed into place, so a half-built mirror is never
   visible to a request. */
/* true built, null not published, false failed. $skip_missing is for the
   group builds, where a set that simply has no file of that type is normal --
   four NAM lines publish no nc.* and Il14H publishes no plain cds. */
function smBuild($assembly, $file, $skip_missing = false) {
  $url = SM_DATA_URL . '/' . $assembly . '/' . $file;
  $dir = SM_STORE . '/' . $assembly;
  $base = preg_replace('/\.(fa|fasta)\.gz$/', '', $file);
  $faz = "$dir/$base.faz";
  $idx = "$faz.idx";

  if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "cannot create $dir\n");
    return false;
  }
  smProtectStore();

  echo str_pad($assembly . '/' . $file, 62), ' ';
  $t0 = microtime(true);

  $tmp_gz = "$faz.download.tmp";
  $code = smDownload($url, $tmp_gz);
  if ($code !== 200) {
    @unlink($tmp_gz);
    if ($code === 404) {
      /* Write it down. get_sequence.php reads this list and skips the
         candidate outright, because the service answers a request for a file
         that does not exist with a 502 -- indistinguishable from an outage --
         and would otherwise spend a retry ladder on it every single time. */
      smNotePublished($assembly . '/' . $file, false);
      if ($skip_missing) { echo "not published\n"; return null; }
    }
    echo "FAILED to download (HTTP $code)\n";
    return false;
  }
  $gz_bytes = filesize($tmp_gz);

  /* One pass: read the FASTA, append each record's sequence to the current
     block, and note where it landed. A block is flushed once it passes
     SM_BLOCK, so one very long sequence simply becomes a block of its own. */
  $in = gzopen($tmp_gz, 'rb');
  if (!$in) { echo "FAILED to open the download\n"; @unlink($tmp_gz); return false; }
  $tmp_faz = "$faz.build.tmp";
  $out = fopen($tmp_faz, 'wb');
  if (!$out) { gzclose($in); echo "FAILED to write $tmp_faz\n"; @unlink($tmp_gz); return false; }

  $entries = array();
  $id = null;
  $seq = '';
  $maxid = 0;
  $st = smNewState();

  /* Read in megabyte chunks rather than with gzgets(). The published files
     wrap at 60 or 80 columns, so a line at a time is hundreds of millions of
     PHP function calls across the corpus and was most of the build time --
     about 7 s of the 10 s this file used to take. */
  $tail = '';
  while (($chunk = gzread($in, 1048576)) !== false && $chunk !== '') {
    $chunk = $tail . $chunk;
    $cut = strrpos($chunk, "\n");
    if ($cut === false) { $tail = $chunk; continue; }
    $tail = substr($chunk, $cut + 1);
    foreach (explode("\n", substr($chunk, 0, $cut)) as $line) {
      if ($line !== '' && $line[0] === '>') {
        if ($id !== null) {
          smAddRecord($out, $id, $seq, $entries, $st, $maxid);
        }
        /* The record name is everything up to the first space, as every FASTA
           reader treats it -- the published files carry a description after
           it. */
        $head = rtrim(substr($line, 1), "\r");
        $sp = strcspn($head, " \t");
        $id = substr($head, 0, $sp);
        $seq = '';
      }
      else {
        $seq .= rtrim($line, "\r");
      }
    }
  }
  if ($tail !== '') {
    if ($tail[0] === '>') {
      if ($id !== null) { smAddRecord($out, $id, $seq, $entries, $st, $maxid); }
      $head = rtrim(substr($tail, 1), "\r");
      $id = substr($head, 0, strcspn($head, " \t"));
      $seq = '';
    }
    else { $seq .= rtrim($tail, "\r"); }
  }
  if ($id !== null) {
    smAddRecord($out, $id, $seq, $entries, $st, $maxid);
  }
  smFlushBlock($out, $entries, $st);
  gzclose($in);
  fclose($out);
  @unlink($tmp_gz);

  if (count($entries) === 0) {
    echo "FAILED: no records found\n";
    @unlink($tmp_faz);
    return false;
  }

  ksort($entries, SORT_STRING);
  // id, block offset, block length, offset in block, record length
  $width = $maxid + 1 + 12 + 1 + 8 + 1 + 6 + 1 + 8 + 1;
  $tmp_idx = "$idx.build.tmp";
  $ih = fopen($tmp_idx, 'wb');
  if (!$ih) { echo "FAILED to write $tmp_idx\n"; @unlink($tmp_faz); return false; }
  fwrite($ih, str_pad(sprintf('MGDBSEQIDX2 %d %d %d %d', $width, count($entries), $maxid, SM_BLOCK), $width - 1) . "\n");
  foreach ($entries as $eid => $e) {
    fwrite($ih, sprintf("%-{$maxid}s %012d %08d %06d %08d\n", $eid, $e[0], $e[1], $e[2], $e[3]));
  }
  fclose($ih);

  if (!rename($tmp_faz, $faz) || !rename($tmp_idx, $idx)) {
    echo "FAILED to move into place\n";
    return false;
  }
  @chmod($faz, 0664);
  @chmod($idx, 0664);
  smNotePublished($assembly . '/' . $file, true);

  printf("%9s records  %6.1f MB gz  %6.1f MB faz  %5.1f s\n",
         number_format(count($entries)), $gz_bytes / 1048576.0,
         (filesize($faz) + filesize($idx)) / 1048576.0, microtime(true) - $t0);
  return true;
}//smBuild

/* The store sits under the document root, so without this the mirror offers
   a 326 MB file to anyone who asks for it by name. get_sequence.php reads
   these through the filesystem and does not care. */
function smProtectStore() {
  $ht = SM_STORE . '/.htaccess';
  if (is_file($ht)) { return; }
  if (!is_dir(SM_STORE)) { return; }
  @file_put_contents($ht,
    "# Mirrored FASTA for tools/sequence/get_sequence.php, read from disk by\n"
  . "# that script. The same data is published at download.maizegdb.org.\n"
  . "<FilesMatch \"\\.(fa|idx|tmp)$\">\n"
  . "  Require all denied\n"
  . "</FilesMatch>\n");
}

/* What every assembly in chado.genome_metadata publishes, read from the
   directory listings rather than guessed -- one request per assembly instead
   of one per file -- and written to data/sequence/sets.json. Re-run it after a
   new genome is loaded; --all then picks the genome up with no edit here.

   The listing is also where the 404s come from: a type an assembly does not
   publish goes into absent.json, so get_sequence.php skips that candidate
   instead of spending a retry ladder on a service that reports a missing file
   as a 502. */
function smDiscover($types, $probe_types) {
  // getSystemInfo() reads $_SERVER['HTTP_HOST'] and there is none on a console.
  if (!isset($_SERVER['HTTP_HOST'])) { $_SERVER['HTTP_HOST'] = 'localhost'; }
  include_once('include/db-api.php');
  include_once('include/gp_lib.php');
  $DBConn = connect_to_database(false);
  $sth = make_query($DBConn,
    'SELECT annotation, assembly_name FROM chado.genome_metadata ORDER BY assembly_name');
  $want = array();
  while ($row = retrieve_row($sth)) {
    $want[trim($row['assembly_name'])][] = trim($row['annotation']);
  }
  if (count($want) === 0) { fwrite(STDERR, "no rows in chado.genome_metadata\n"); return false; }

  $listings = smListings(array_keys($want));

  $sets = array();
  $no_dir = 0;
  $no_fasta = 0;
  foreach ($want as $assembly => $annotations) {
    if (!isset($listings[$assembly])) { $no_dir++; continue; }
    $files = $listings[$assembly];
    $best = null;
    foreach ($annotations as $annotation) {
      $have = array();
      foreach (array_merge($types, $probe_types) as $type) {
        $name = "{$assembly}_{$annotation}.{$type}.fa.gz";
        if (isset($files[$name])) { $have[] = $type; }
        else { smNotePublished("$assembly/$name", false); }
      }
      /* Several annotations of one assembly can be published; take the one
         with the most sequence types, which is the current release. */
      if (count($have) > 0 && ($best === null || count($have) > count($best['types']))) {
        $best = array('annotation' => $annotation, 'types' => $have);
      }
    }
    if ($best === null) { $no_fasta++; continue; }
    $sets[$assembly] = $best;
  }

  ksort($sets);
  if (!is_dir(SM_STORE) && !mkdir(SM_STORE, 0775, true) && !is_dir(SM_STORE)) {
    fwrite(STDERR, "cannot create " . SM_STORE . "\n");
    return false;
  }
  smProtectStore();
  file_put_contents(SM_STORE . '/sets.json.tmp', json_encode($sets, JSON_PRETTY_PRINT));
  rename(SM_STORE . '/sets.json.tmp', SM_STORE . '/sets.json');
  printf("%d assemblies in chado.genome_metadata; %d publish gene-model FASTA, "
       . "%d have no download directory, %d have a directory but no FASTA\n",
         count($want), count($sets), $no_dir, $no_fasta);
  return true;
}//smDiscover

/* One directory listing per assembly, in parallel. Returns assembly =>
   filename => true for the .fa.gz files it holds. */
function smListings($assemblies) {
  $mh = curl_multi_init();
  curl_multi_setopt($mh, CURLMOPT_MAX_HOST_CONNECTIONS, 6);
  $handles = array();
  foreach ($assemblies as $a) {
    $ch = curl_init();
    curl_setopt_array($ch, array(
      CURLOPT_URL => SM_DATA_URL . '/' . rawurlencode($a) . '/',
      CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90,
      CURLOPT_ENCODING => '', CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_USERAGENT => 'MaizeGDB/sequence_mirror.php'
    ));
    curl_multi_add_handle($mh, $ch);
    $handles[$a] = $ch;
  }
  $running = null;
  do {
    $status = curl_multi_exec($mh, $running);
    if ($running) { if (curl_multi_select($mh, 1.0) === -1) { usleep(1000); } }
  } while ($running > 0 && $status == CURLM_OK);

  $out = array();
  foreach ($handles as $a => $ch) {
    $body = curl_multi_getcontent($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
    if ($code !== 200) { continue; }
    if (preg_match_all('/<a href="([^"]+\.(?:fa|fasta)\.gz)"/', (string) $body, $m)) {
      $out[$a] = array_fill_keys($m[1], true);
    }
    else {
      $out[$a] = array();
    }
  }
  curl_multi_close($mh);
  return $out;
}

function smLoadSets() {
  $file = SM_STORE . '/sets.json';
  if (!is_file($file)) { return null; }
  $sets = json_decode((string) file_get_contents($file), true);
  return is_array($sets) && count($sets) > 0 ? $sets : null;
}

/* Ask whether a file is published, without downloading it, and write the
   answer down. A HEAD costs about 20 ms. */
function smProbe($assembly, $file) {
  $ch = curl_init();
  curl_setopt_array($ch, array(
    CURLOPT_URL => SM_DATA_URL . '/' . $assembly . '/' . $file,
    CURLOPT_NOBODY => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_USERAGENT => 'MaizeGDB/sequence_mirror.php'
  ));
  curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  if ($code === 200 || $code === 404) {
    smNotePublished($assembly . '/' . $file, $code === 200);
  }
}

/* The record of which published files exist, at data/sequence/absent.json.
   Only files this tool has actually asked for are in it, so its absence from
   the list means nothing and its presence is a checked fact. */
function smNotePublished($path, $exists) {
  $file = SM_STORE . '/absent.json';
  $list = is_file($file) ? json_decode((string) file_get_contents($file), true) : array();
  if (!is_array($list)) { $list = array(); }
  if ($exists) {
    if (!isset($list[$path])) { return; }
    unset($list[$path]);
  }
  else {
    if (isset($list[$path])) { return; }
    $list[$path] = date('c');
  }
  ksort($list);
  @file_put_contents($file . '.tmp', json_encode($list, JSON_PRETTY_PRINT));
  @rename($file . '.tmp', $file);
}

/* Block building. $st is the state: the block buffer, where the next block
   will land in the file, and the ids whose index rows are waiting for that
   block's offset and compressed length. */
function smNewState() {
  return array('block' => '', 'offset' => 0, 'pending' => array(), 'blocks' => 0);
}

function smAddRecord($out, $id, $seq, &$entries, &$st, &$maxid) {
  if ($id === '' || $seq === '') { return; }
  $entries[$id] = array(0, 0, strlen($st['block']), strlen($seq));
  $st['pending'][] = $id;
  $st['block'] .= $seq;
  if (strlen($id) > $maxid) { $maxid = strlen($id); }
  if (strlen($st['block']) >= SM_BLOCK) { smFlushBlock($out, $entries, $st); }
}

/* Deflate the block, write it, and fill in the offset and length on every
   index row that was waiting for it. Raw deflate rather than gzip: there is no
   need for a per-block header when the index says how long the block is. */
function smFlushBlock($out, &$entries, &$st) {
  if ($st['block'] === '') { return; }
  $c = gzdeflate($st['block'], SM_LEVEL);
  foreach ($st['pending'] as $id) {
    $entries[$id][0] = $st['offset'];
    $entries[$id][1] = strlen($c);
  }
  fwrite($out, $c);
  $st['offset'] += strlen($c);
  $st['blocks']++;
  $st['block'] = '';
  $st['pending'] = array();
}

function smDownload($url, $dest) {
  $out = fopen($dest, 'wb');
  if (!$out) { return false; }
  $ch = curl_init();
  curl_setopt_array($ch, array(
    CURLOPT_URL => $url,
    CURLOPT_FILE => $out,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 1800,
    CURLOPT_FAILONERROR => true,
    CURLOPT_USERAGENT => 'MaizeGDB/sequence_mirror.php'
  ));
  $ok = curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  fclose($out);
  if ($ok === false || $code !== 200 || filesize($dest) === 0) { return $code === 0 ? -1 : $code; }
  return 200;
}

/* Pull ten records at random out of the mirror and compare them with what the
   sequence service says, so a mirror is never trusted just because it parsed. */
function smCheck($assembly, $file) {
  $base = preg_replace('/\.(fa|fasta)\.gz$/', '', $file);
  $faz = SM_STORE . "/$assembly/$base.faz";
  if (!is_file("$faz.idx")) { echo "not mirrored: $faz\n"; return false; }

  $h = fopen("$faz.idx", 'rb');
  $bits = explode(' ', trim((string) fgets($h)));
  $width = (int) $bits[1];
  $count = (int) $bits[2];
  $idlen = (int) $bits[3];
  $bad = 0;
  for ($i = 0; $i < 10; $i++) {
    $n = random_int(0, $count - 1);
    fseek($h, $width * ($n + 1));
    $id = rtrim(substr((string) fread($h, $width), 0, $idlen));
    $mine = smLocalRead($faz, $id);
    $url = 'https://fasta.maizegdb.org/fasta/fetch/' . $id . '/' . SM_DATA_URL . "/$assembly/$file";
    $ch = curl_init();
    curl_setopt_array($ch, array(CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true,
                                 CURLOPT_TIMEOUT => 30, CURLOPT_ENCODING => ''));
    $body = curl_exec($ch);
    curl_close($ch);
    $theirs = json_decode((string) $body);
    $theirs = isset($theirs->{'sequence'}) ? $theirs->{'sequence'} : null;
    if ($theirs === null) { printf("  %-24s service did not answer, skipped\n", $id); continue; }
    if ($theirs !== $mine) { $bad++; printf("  %-24s MISMATCH local=%d service=%d\n", $id, strlen((string) $mine), strlen($theirs)); }
    else { printf("  %-24s matches (%d)\n", $id, strlen($theirs)); }
  }
  fclose($h);
  echo $bad === 0 ? "check passed\n" : "check FAILED on $bad of 10\n";
  return $bad === 0;
}

/* The same read get_sequence.php does, kept here so --check exercises it. */
function smLocalRead($faz, $id) {
  $ih = @fopen("$faz.idx", 'rb');
  if (!$ih) { return null; }
  $bits = explode(' ', trim((string) fgets($ih)));
  if (count($bits) < 5 || $bits[0] !== 'MGDBSEQIDX2') { fclose($ih); return null; }
  $width = (int) $bits[1];
  $count = (int) $bits[2];
  $idlen = (int) $bits[3];

  $lo = 0;
  $hi = $count - 1;
  $row = null;
  while ($lo <= $hi) {
    $mid = intdiv($lo + $hi, 2);
    fseek($ih, $width * ($mid + 1));
    $candidate = fread($ih, $width);
    if ($candidate === false || $candidate === '') { break; }
    $cmp = strcmp(rtrim(substr($candidate, 0, $idlen)), $id);
    if ($cmp === 0) { $row = $candidate; break; }
    if ($cmp < 0) { $lo = $mid + 1; } else { $hi = $mid - 1; }
  }
  fclose($ih);
  if ($row === null) { return null; }

  $boff = (int) substr($row, $idlen + 1, 12);
  $blen = (int) substr($row, $idlen + 14, 8);
  $roff = (int) substr($row, $idlen + 23, 6);
  $rlen = (int) substr($row, $idlen + 30, 8);
  $fh = @fopen($faz, 'rb');
  if (!$fh) { return null; }
  fseek($fh, $boff);
  $block = @gzinflate((string) fread($fh, $blen));
  fclose($fh);
  return ($block === false) ? null : substr($block, $roff, $rlen);
}

function smList() {
  if (!is_dir(SM_STORE)) { echo "nothing mirrored (" . SM_STORE . " does not exist)\n"; return; }
  $total = 0;
  $files = 0;
  $records = 0;
  foreach (glob(SM_STORE . '/*', GLOB_ONLYDIR) as $dir) {
    $bytes = 0;
    $n = 0;
    $when = 0;
    foreach (glob("$dir/*.idx") as $idx) {
      $faz = preg_replace('/\.idx$/', '', $idx);
      $h = fopen($idx, 'rb');
      $bits = explode(' ', trim((string) fgets($h)));
      fclose($h);
      $bytes += filesize($faz) + filesize($idx);
      $n += (int) $bits[2];
      $when = max($when, filemtime($faz));
      $files++;
    }
    if ($n === 0) { continue; }
    $total += $bytes;
    $records += $n;
    printf("%-44s %2d files %10s records %7.1f MB  %s\n",
           basename($dir), count(glob("$dir/*.idx")), number_format($n),
           $bytes / 1048576.0, date('Y-m-d H:i', $when));
  }
  printf("%-44s %2d files %10s records %7.1f MB\n", 'TOTAL', $files, number_format($records), $total / 1048576.0);
}
