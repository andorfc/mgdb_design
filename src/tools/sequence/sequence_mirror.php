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
 *   <assembly>/<file>.fa        the sequences, one line per record
 *   <assembly>/<file>.fa.idx    fixed-width sorted index, binary searched
 *
 * The index is fixed width on purpose: every record is the same number of
 * bytes, so a lookup is a binary search with no line scanning and no parse of
 * anything but the record it lands on. 40,000 proteins index to about 1.3 MB.
 */

  if (php_sapi_name() !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    echo "This is a command line tool.\n";
    exit(1);
  }

  define('SM_DATA_URL', 'https://download.maizegdb.org');
  define('SM_STORE', 'data/sequence');

  /* The sets tools/sequence/get_sequence.php is asked for from the gene record
     pages: protein, CDS and cDNA for the current B73 annotation and for v4.
     Genomic (gene) and whole-assembly files are deliberately not here -- they
     are hundreds of megabytes to gigabytes and are asked for rarely. */
  $SM_DEFAULTS = array(
    array('Zm-B73-REFERENCE-NAM-5.0', 'Zm-B73-REFERENCE-NAM-5.0_Zm00001eb.1.protein.fa.gz'),
    array('Zm-B73-REFERENCE-NAM-5.0', 'Zm-B73-REFERENCE-NAM-5.0_Zm00001eb.1.cds.fa.gz'),
    array('Zm-B73-REFERENCE-NAM-5.0', 'Zm-B73-REFERENCE-NAM-5.0_Zm00001eb.1.cdna.fa.gz'),
    /* The non-coding sets are small and are the first fallback for every
       identifier the main file does not hold, so leaving them out sends a
       non-coding gene to the web on every view. */
    array('Zm-B73-REFERENCE-NAM-5.0', 'Zm-B73-REFERENCE-NAM-5.0_Zm00001eb.1.nc.protein.fa.gz'),
    array('Zm-B73-REFERENCE-NAM-5.0', 'Zm-B73-REFERENCE-NAM-5.0_Zm00001eb.1.nc.cds.fa.gz'),
    array('Zm-B73-REFERENCE-GRAMENE-4.0', 'Zm-B73-REFERENCE-GRAMENE-4.0_Zm00001d.2.protein.fa.gz'),
    array('Zm-B73-REFERENCE-GRAMENE-4.0', 'Zm-B73-REFERENCE-GRAMENE-4.0_Zm00001d.2.cds.fa.gz'),
    array('Zm-B73-REFERENCE-GRAMENE-4.0', 'Zm-B73-REFERENCE-GRAMENE-4.0_Zm00001d.2.cdna.fa.gz'),
    array('B73_RefGen_v3', 'Zea_mays.AGPv3.21.protein.fa.gz'),
    array('B73_RefGen_v3', 'Zea_mays.AGPv3.21.cds.fa.gz'),
    array('B73_RefGen_v3', 'Zea_mays.AGPv3.21.transcripts.fa.gz')
  );

  $argv0 = array_shift($argv);
  $cmd = isset($argv[0]) ? $argv[0] : '--list';

  if ($cmd === '--defaults') {
    $failed = 0;
    foreach ($SM_DEFAULTS as $set) {
      if (!smBuild($set[0], $set[1])) { $failed++; }
    }
    exit($failed === 0 ? 0 : 1);
  }
  else if ($cmd === '--build' && isset($argv[2])) {
    exit(smBuild($argv[1], $argv[2]) ? 0 : 1);
  }
  else if ($cmd === '--check' && isset($argv[2])) {
    exit(smCheck($argv[1], $argv[2]) ? 0 : 1);
  }
  else if ($cmd === '--list') {
    smList();
    exit(0);
  }

  echo "usage: php tools/sequence/sequence_mirror.php [--defaults|--list|--build <assembly> <file.fa.gz>|--check <assembly> <file.fa.gz>]\n";
  exit(2);


/* Download, decompress and index one published FASTA. Everything is written
   beside the target and renamed into place, so a half-built mirror is never
   visible to a request. */
function smBuild($assembly, $file) {
  $url = SM_DATA_URL . '/' . $assembly . '/' . $file;
  $dir = SM_STORE . '/' . $assembly;
  $base = preg_replace('/\.gz$/', '', $file);
  $fa = "$dir/$base";
  $idx = "$fa.idx";

  if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "cannot create $dir\n");
    return false;
  }
  smProtectStore();

  echo str_pad($assembly . '/' . $file, 62), ' ';
  $t0 = microtime(true);

  $tmp_gz = "$fa.download.tmp";
  if (!smDownload($url, $tmp_gz)) {
    echo "FAILED to download\n";
    @unlink($tmp_gz);
    return false;
  }
  $gz_bytes = filesize($tmp_gz);

  /* One pass: read the FASTA, write each record as two lines -- header, then
     the whole sequence -- and record where each one starts. Rewriting the
     wrapping is what makes a lookup one read rather than a scan; the caller
     wraps to 80 columns on the way out anyway. */
  $in = gzopen($tmp_gz, 'rb');
  if (!$in) { echo "FAILED to open the download\n"; @unlink($tmp_gz); return false; }
  $tmp_fa = "$fa.build.tmp";
  $out = fopen($tmp_fa, 'wb');
  if (!$out) { gzclose($in); echo "FAILED to write $tmp_fa\n"; @unlink($tmp_gz); return false; }

  $entries = array();
  $id = null;
  $seq = '';
  $offset = 0;
  $maxid = 0;

  while (($line = gzgets($in)) !== false) {
    if ($line !== '' && $line[0] === '>') {
      if ($id !== null) {
        $offset = smWriteRecord($out, $id, $seq, $entries, $offset, $maxid);
      }
      /* The record name is everything up to the first space, as every FASTA
         reader treats it -- the published files carry a description after it. */
      $head = rtrim(substr($line, 1), "\r\n");
      $sp = strcspn($head, " \t");
      $id = substr($head, 0, $sp);
      $seq = '';
    }
    else {
      $seq .= rtrim($line, "\r\n");
    }
  }
  if ($id !== null) {
    $offset = smWriteRecord($out, $id, $seq, $entries, $offset, $maxid);
  }
  gzclose($in);
  fclose($out);
  @unlink($tmp_gz);

  if (count($entries) === 0) {
    echo "FAILED: no records found\n";
    @unlink($tmp_fa);
    return false;
  }

  ksort($entries, SORT_STRING);
  $width = $maxid + 1 + 12 + 1 + 10 + 1;   // id, space, offset, space, length, newline
  $tmp_idx = "$idx.build.tmp";
  $ih = fopen($tmp_idx, 'wb');
  if (!$ih) { echo "FAILED to write $tmp_idx\n"; @unlink($tmp_fa); return false; }
  /* A fixed-width header so the search knows the record width without
     parsing anything: "MGDBSEQIDX1 <width> <count> <maxid>\n" padded to width. */
  fwrite($ih, str_pad(sprintf('MGDBSEQIDX1 %d %d %d', $width, count($entries), $maxid), $width - 1) . "\n");
  foreach ($entries as $eid => $pos) {
    fwrite($ih, sprintf("%-{$maxid}s %012d %010d\n", $eid, $pos[0], $pos[1]));
  }
  fclose($ih);

  if (!rename($tmp_fa, $fa) || !rename($tmp_idx, $idx)) {
    echo "FAILED to move into place\n";
    return false;
  }
  @chmod($fa, 0664);
  @chmod($idx, 0664);

  printf("%7s records  %6.1f MB gz  %6.1f MB fa  %5.1f s\n",
         number_format(count($entries)), $gz_bytes / 1048576.0,
         filesize($fa) / 1048576.0, microtime(true) - $t0);
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

function smWriteRecord($out, $id, $seq, &$entries, $offset, &$maxid) {
  if ($id === '' || $seq === '') { return $offset; }
  $len = strlen($seq);
  fwrite($out, $seq . "\n");
  $entries[$id] = array($offset, $len);
  if (strlen($id) > $maxid) { $maxid = strlen($id); }
  return $offset + $len + 1;
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
  return $ok !== false && $code === 200 && filesize($dest) > 0;
}

/* Pull ten records at random out of the mirror and compare them with what the
   sequence service says, so a mirror is never trusted just because it parsed. */
function smCheck($assembly, $file) {
  $base = preg_replace('/\.gz$/', '', $file);
  $fa = SM_STORE . "/$assembly/$base";
  if (!is_file("$fa.idx")) { echo "not mirrored: $fa\n"; return false; }

  $h = fopen("$fa.idx", 'rb');
  $header = fgets($h);
  $width = (int) explode(' ', trim($header))[1];
  $count = (int) explode(' ', trim($header))[2];
  $bad = 0;
  for ($i = 0; $i < 10; $i++) {
    $n = random_int(0, $count - 1);
    fseek($h, $width * ($n + 1));
    $row = fgets($h);
    $id = trim(substr($row, 0, strpos($row, ' ')));
    $mine = smLocalRead($fa, $id);
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
function smLocalRead($fa, $id) {
  $ih = @fopen("$fa.idx", 'rb');
  if (!$ih) { return null; }
  $header = fgets($ih);
  $bits = explode(' ', trim($header));
  if (count($bits) < 4 || $bits[0] !== 'MGDBSEQIDX1') { fclose($ih); return null; }
  $width = (int) $bits[1];
  $count = (int) $bits[2];
  $idlen = (int) $bits[3];

  $lo = 0;
  $hi = $count - 1;
  $found = null;
  while ($lo <= $hi) {
    $mid = intdiv($lo + $hi, 2);
    fseek($ih, $width * ($mid + 1));
    $row = fread($ih, $width);
    $rid = rtrim(substr($row, 0, $idlen));
    $cmp = strcmp($rid, $id);
    if ($cmp === 0) { $found = $row; break; }
    if ($cmp < 0) { $lo = $mid + 1; } else { $hi = $mid - 1; }
  }
  fclose($ih);
  if ($found === null) { return null; }

  $offset = (int) substr($found, $idlen + 1, 12);
  $length = (int) substr($found, $idlen + 14, 10);
  $fh = @fopen($fa, 'rb');
  if (!$fh) { return null; }
  fseek($fh, $offset);
  $seq = fread($fh, $length);
  fclose($fh);
  return $seq;
}

function smList() {
  if (!is_dir(SM_STORE)) { echo "nothing mirrored (" . SM_STORE . " does not exist)\n"; return; }
  $total = 0;
  foreach (glob(SM_STORE . '/*', GLOB_ONLYDIR) as $dir) {
    foreach (glob("$dir/*.idx") as $idx) {
      $fa = preg_replace('/\.idx$/', '', $idx);
      $h = fopen($idx, 'rb');
      $bits = explode(' ', trim(fgets($h)));
      fclose($h);
      $total += filesize($fa) + filesize($idx);
      printf("%-70s %8s records  %6.1f MB  built %s\n",
             substr($fa, strlen(SM_STORE) + 1),
             number_format((int) $bits[2]),
             (filesize($fa) + filesize($idx)) / 1048576.0,
             date('Y-m-d H:i', filemtime($fa)));
    }
  }
  printf("%-70s %26.1f MB\n", 'total', $total / 1048576.0);
}
