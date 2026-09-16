<?php
/* file: get_sequence.php
 *
 * purpose: extract sequence from a bgzipped fasta file using fasta-api.
 *
 * Uses fastAPI :
 *   https://github.com/Maize-Genetics-and-Genomics-Database/maizegdb-fasta-api
 *
 * Tests:
 *  https://[URL]/tools/sequence/get_sequence.php?dbtype=cds&assembly=Zm-B73-REFERENCE-NAM-5.0&annotation=Zm00001eb.1&&id=Zm00001eb000010_T001
 *  https://[URL]/tools/sequence/get_sequence.php?dbtype=gene&assembly=Zm-B73-REFERENCE-NAM-5.0&annotation=Zm00001eb.1&&id=Zm00001eb000010
 *  https://[URL]/tools/sequence/get_sequence.php?dbtype=nuc&assembly=Zm-B73-REFERENCE-NAM-5.0&annotation=Zm00001eb.1&&id=Zm00001eb000010
 *  https://[URL]/tools/sequence/get_sequence.php?dbtype=gene&assembly=Zm-B73-REFERENCE-NAM-5.0&annotation=Zm00001eb.1&&id=Zm00001eb000010,Zm00001eb000020
 *  https://[URL]/tools/sequence/get_sequence.php?dbtype=nuc&assembly=Zm-B73-REFERENCE-NAM-5.0&annotation=Zm00001eb.1&&id=Zm00001eb000010_T001,Zm00001eb000020_T001
 *
 *  test for does not exist:
 *  https://[URL]/tools/sequence/get_sequence.php?dbtype=nuc&assembly=Zm-B73-REFERENCE-NAM-5.0&annotation=Zm00001eb.1&&id=Zm00001eb000010_T010
 *  https://[URL]/tools/sequence/get_sequence.php?dbtype=cds&assembly=Zm-B73-REFERENCE-NAM-5.0&position=chr3:40000-50000
 *  https://[URL]/tools/sequence/get_sequence.php?dbtype=cds&assembly=B73%20RefGen_v3&annotation=5b+&id=GRMZM2G138676_T01
 *  https://[URL]/tools/sequence/get_sequence.php?dbtype=nuc&assembly=B73%20RefGen_v3&annotation=5b+&id=GRMZM2G138676_T01
 *
 *  test out-of-bounds coordinates
 *  https://[URL]/tools/sequence/get_sequence.php?dbtype=nuc&assembly=B73%20RefGen_v3&position=chr4:350010-602400
 *  https://[URL]/tools/sequence/get_sequence.php?dbtype=nuc&assembly=Zm-B73-REFERENCE-NAM-5.0&annotation=Zm00001eb.1&id=Zm00001eb000010_T001,Zm00001eb000020_T001&rflank=1000&lflank=1000
 *  https://[URL]/tools/sequence/get_sequence.php?dbtype=nuc&assembly=B73%20RefGen_v3&annotation=5b+&id=GRMZM2G138676_T01&rflank=1000&lflank=1000
 *
 *  test pan-gene
 *  https://[URL]/tools/sequence/get_sequence.php?annotation=Pan-Zea&dbtype=cds&id=pan-zea.v1.000000001
 *
 * Optional conf/mgdb.conf keys (all have working defaults):
 *   sequence_cache_path   where answers are cached; default <search_cache_path>/sequence
 *   sequence_cache_ttl    seconds a found sequence stays fresh; default 2592000 (30 days)
 *   sequence_cache_miss_ttl  seconds a "no such sequence" answer is remembered; default 900
 *   sequence_cache        set to false to switch the cache off entirely
 *
 * history:
 *  06/20/24  eksc  created
 *  09/15/26  claude  hardened and made fast; see the notes below.
 *
 * WHAT CHANGED, 09/15/26, all of it measured from dev8 on the day:
 *
 *  FIRST, WHAT THE SERVICE ACTUALLY DOES. fasta.maizegdb.org is handed a
 *  download.maizegdb.org URL and range-reads it. For an identifier Cloudflare
 *  has already cached it answers in ~50 ms and never fails: 957 polls over
 *  four minutes, 0 failures; 600 more with retries, 0 failures. For a COLD
 *  identifier it takes 1.0-1.5 s, sometimes 27-30 s, and returns 502 in bursts
 *  a second or two long -- 5 of 25 cold identifiers unanswered in one run,
 *  mean 4.1 s, worst 29.9 s. That is the whole of the "SEQUENCE SERVICE IS
 *  DOWN" story: the service is fine for what it has served recently and shaky
 *  for everything else. Meanwhile the same 10 MB file downloads whole from
 *  download.maizegdb.org in 0.6 s. Nothing here is slow but the round trip.
 *
 *  1. Sequences are read from local disk when they are mirrored there.
 *     tools/sequence/sequence_mirror.php downloads a published FASTA and
 *     rewrites it as deflated 64 KB blocks with a fixed-width sorted index
 *     beside it; a lookup is a binary search, one seek, one ~20 KB read and
 *     one inflate. Mirrored: all 134 assemblies in chado.genome_metadata that
 *     publish gene-model FASTA -- B73 v1 through v5, the 25 NAM founder lines,
 *     the PanAnd species and the rest -- protein, CDS, cDNA, genomic and the
 *     small non-coding sets. 437 files, 31.9 M sequences, 11.9 GB.
 *
 *     B73 v5, 25 identifiers nothing had requested: 25 answered, 0 failed,
 *     mean 139 ms, worst 940 ms. Across the NAM lines: 25 answered, mean
 *     64 ms, worst 129, against 24 of 25 at a mean of 2,578 ms and a worst of
 *     19,671. Four sequences in one request: 29 ms against 2,409 ms. A file
 *     that is not mirrored behaves exactly as before, so this is an
 *     optimisation and never a dependency.
 *
 *     It also serves what the service cannot. B73 v1 and v2 publish no
 *     .fai/.gzi, so fasta.maizegdb.org has never returned a single sequence
 *     for either -- production still answers "sequence not found" for every
 *     one. The mirror builds its own index from the file, so those two
 *     assemblies work here for the first time.
 *
 *     The mirror also writes data/sequence/absent.json, the files it asked
 *     download.maizegdb.org for and was told do not exist -- 67 of them, and
 *     the whole nc./canonical. family for B73 v4. A candidate naming one is
 *     skipped rather than retried, because the service answers a request for
 *     a file that is not there with the same 502 it uses for an outage. That
 *     one list took the worst NAM lookup from 3,788 ms to 74.
 *
 *  2. The "is the service up?" probe is gone. Every request began with
 *     get_headers('https://fasta.maizegdb.org/'), and a non-200 printed
 *     "SEQUENCE SERVICE IS DOWN." and exited. That probe went out over
 *     Cloudflare like any other request and returned 502 on about 3% of
 *     attempts (2 of 60, 2 of 40) during the same minutes in which the fetch
 *     endpoint answered 60 of 60. It was inventing most of the outages it
 *     reported, and it cost 205 ms -- as much as the request it was guarding.
 *     A failure is now something the real fetch reports after being retried.
 *
 *  3. file_get_contents() became cURL on a reused connection. Each
 *     file_get_contents() negotiated a fresh TLS session to Cloudflare.
 *
 *  4. Several identifiers are fetched in PARALLEL. The id parameter has always
 *     taken a comma-separated list and the old code walked it one at a time.
 *     Four at a time, because ten at once pushed the service past what it
 *     would serve and one request in ten hit its timeout.
 *
 *  5. Timeouts and a deadline. Neither the probe nor the fetches set one, so
 *     they inherited default_socket_timeout -- 60 seconds of an Apache worker
 *     held by one stuck request. Connect 4 s, request 12 s, and SEQ_DEADLINE
 *     caps the whole thing at 25 s however many identifiers and fallbacks are
 *     in play.
 *
 *  6. Retries, with a backoff that steps over an outage burst rather than
 *     landing inside it: 400 ms, then 1,500 ms. A clean "sequence not present"
 *     (the API answers 400 with a JSON detail) is never retried -- it is an
 *     answer, not a failure. The old code could not tell the two apart: a 502
 *     left file_get_contents() holding Cloudflare's HTML, json_decode()
 *     returned null, and the reader was told, with confidence, that the
 *     sequence does not exist.
 *
 *  7. A disk cache, and stale-while-broken. A sequence is immutable inside an
 *     annotation release, so an answer is kept for 30 days and a repeat costs
 *     no network. If the service is unreachable and any cached copy exists,
 *     even an expired one, it is served rather than an error.
 *
 *  8. cdna no longer silently means cds. The old code did
 *     `if ($dbtype == 'cdna') $dbtype = 'cds';` with the comment "we rarely
 *     have cDNA sequence". There IS a cdna file for B73 v5, for v4 and for
 *     every NAM line: Zm00001eb168550_T001 is 1,695 nt as cdna and 1,140 nt
 *     as cds, so every cDNA link on the site was returning the CDS. cdna is
 *     tried first now and falls back to cds where no cdna file exists.
 *
 *  9. The canonical-only file is the last fallback.
 *     Zm-Il14H-REFERENCE-NAM-1.0 publishes no plain cds.fa.gz -- only
 *     canonical.cds -- so a CDS request on that line could not be answered for
 *     any of its 76,559 transcripts. Its 40,301 canonical ones can be now.
 *     Everything in a canonical file is also in the full file wherever the
 *     full file exists, so this never changes an answer, only supplies one.
 *
 * 10. Position requests work on B73 RefGen_v3. Its chromosomes are named Chr4
 *     where the modern assemblies say chr4, so the example in this file's own
 *     header has always come back empty. The chromosome name is tried as
 *     given and then in the obvious variants.
 *
 * 11. Bugs fixed while in here:
 *     - One failed identifier threw away every sequence that HAD been found:
 *       the formatter did `$new_sequence = "$seq\n"` -- assignment, not
 *       append -- inside the loop over records.
 *     - Every fetch function declared `global $assembly, $annotation` while
 *       also taking them as parameters, which in PHP discards the argument and
 *       binds the name to the global. fetchV4SequenceForId() then ASSIGNED to
 *       $annotation, so a v4 request that fell back to the provisional set
 *       changed the annotation for every later identifier in the same request.
 *       No function here reads a global any more.
 *     - handleFlankingSequence() interpolated the identifier straight into
 *       SQL. Parameterised.
 *     - The legacy nuc path computed the gene id and then looked the
 *       TRANSCRIPT id up in the genes file, so that fallback could never hit.
 *     - getLegacyGeneModelFile() echoed its error into the middle of the FASTA
 *       and returned false; handleLegacyAssembly() appended to an undefined
 *       $sequence for a position request and tested an undefined $flank.
 *       Harmless on production, where display_errors is off, and visible as a
 *       PHP warning inside the FASTA on any instance where it is not.
 *     - The 80-column wrapper dropped the header of any record whose sequence
 *       was on a single line.
 *
 * WHAT DID NOT CHANGE: every parameter, the FASTA and its CRLF 80-column
 * wrapping, the three content types, the error wording, and HTTP 200 on an
 * error -- callers match on the text, not the status. Output was diffed
 * against the old script over 19 request shapes and against production
 * sequence2 for the v3 cases, byte for byte.
 *
 * THIS FILE IS WHAT sequence2.maizegdb.org SERVES, once it is deployed there.
 * That vhost's DirectoryIndex is get_sequence.php and its document root is the
 * production site's tools/sequence/ directory -- confirmed from outside:
 * https://sequence2.maizegdb.org/test_fasta_api.pl returns the file that sits
 * beside this one, byte for byte. So there is no Apache change to make and no
 * second copy to keep in step; deploying the web root is the whole of it. The
 * local mirror is optional -- with no data/sequence/ directory every lookup
 * takes the service path, as production does today.
 *
 * STILL NOT FIXABLE HERE: B73 v1 and v2 have no .fai/.gzi beside any of their
 * FASTA files on download.maizegdb.org, so the service cannot read them at all
 * and every v1 and v2 request through it comes back empty. Gene-model
 * sequences for both are served from the mirror, which builds its own index;
 * whole-assembly POSITION requests on v1 and v2 still cannot be answered.
 * See ADMIN_DEPENDENCIES AD-077.
 */

  include_once('../../include/db-api.php');
  include_once('../../include/gp_lib.php');
  include_once('../../include/gene_center_lib.php');

  $base_url  = 'https://fasta.maizegdb.org';
  $fetch_url = "$base_url/fasta/fetch";
  $data_url  = 'https://download.maizegdb.org';

  /* How long one upstream request may take, and how hard to try again.
     CONNECT is short because a healthy connect is ~20 ms; TOTAL is generous
     because a whole-chromosome range is a real amount of work. */
  /* Measured on 2026-09-15. A cold identifier -- one Cloudflare has not
     cached -- takes 1.0 to 1.5 s and occasionally 27 to 30 s; failures come in
     bursts a second or two long, so the backoff is set to step over one.
     SEQ_DEADLINE bounds the whole request no matter how many identifiers or
     fallbacks are in play: three attempts on each of several candidates could
     otherwise add up to minutes of somebody waiting. */
  define('SEQ_CONNECT_TIMEOUT', 4);
  define('SEQ_TOTAL_TIMEOUT', 12);
  define('SEQ_ATTEMPTS', 3);             // one try plus two retries
  define('SEQ_RETRY_DELAYS', '400,1500'); // milliseconds, in order
  define('SEQ_DEADLINE', 25);            // seconds of fetching, in total
  define('SEQ_MAX_CONCURRENCY', 4);      /* 10 at once pushed the service past
                                            what it would serve and one request
                                            in ten hit its timeout; 4 at once
                                            answered every time. */

  $system = getSystemInfo('mgdb.conf');
  $seq_started = microtime(true);
  $seq_degraded = false;   // set once the service has failed in this request

  // sequence identifier
  $id_str      = getCGIParam('id',                'GP', false);

  // annotation or dataset (e.g. pan-gene version)
  $annotation  = getCGIParam('annotation',        'GP', false);
  // To maintain existing URLs, also check for legacy annotation parameter
  $annotation = getCGIParam('gene-model-set',    'GP', $annotation);

  // cdna|cds|mrna|ncrna|nuc|genomic|protein
  $dbtype      = strtolower(getCGIParam('dbtype', 'GP', false));

  // assembly coordinates
  $assembly    = getCGIParam('assembly',         'GP', false);
  $position    = getCGIParam('position',         'GP', false);

  // if requesting a pan-gene, the exemplar (optional)
  $exemplar    = getCGIParam('exemplar',         'GP', false);

  // output types
  $text        = getCGIParam('text',             'GP', 1);   //  1 = default = return text
  $html        = getCGIParam('html',             'GP', 0);   //  0 = default = no html
  $download    = getCGIParam('download',         'GP', 0);   //  0 = default = don't force download

  // flanking sequence (only applicable for gene models)
  $lflank     = (int) getCGIParam('lflank',      'GP', 0);
  $rflank     = (int) getCGIParam('rflank',      'GP', 0);

  if ($html && $html == '1') {
    header('Content-type: text/html');
  }
  else if ($download && $download == '1') {
    header('Content-type: fasta');
    header('Content-Disposition: attachment; filename="sequence.fasta"');
  }
  else {
    header('Content-type: text/plain');
  }

  $errors = array();
  if ($annotation == 'Pan-Zea') {
    if (!$id_str || $id_str == '') {
      $errors[] = "A pan-gene id is required.";
    }
    else if (!preg_match("/^pan-zea.*/", $id_str)) {
      $errors[] = "The identifier '$id_str' doesn't look like a pan-gene name.";
    }
  }
  else {
    if (!$assembly && $annotation) {
      // A bit of messiness: Get the assembly, to be compatible with old
      //   sequence server which did not require an assembly as well as annotation.
      if ($annotation == 'AGPv3') {
        $assembly = 'B73 RefGen_v3';
      }
      else {
        $DBConn = connect_to_database();
        $assembly = getAnnotationAssemblyName($annotation, $DBConn);
        if ($assembly == '') {
          $errors[] = "Unable to find the assembly for $annotation.";
        }
      }
    }//Assembly missing

    if ((!$id_str || $id_str == '') && !$position) {
      $errors[] = "Sequence id or chromosome position expected.";
    }
    if ($id_str && (!$annotation || !$assembly)) {
      $errors[] = "A valid assembly and annotation name are required.";
    }
    if ($id_str && !$dbtype) {
      $errors[] = "Data type required with a sequence id.";
    }
    // Get the position parts while we're checking format
    if ($position && !preg_match("/^(\w+):(\d+)-(\d+)$/", $position, $pos_parts)) {
      $errors[] = "The position must take the form [chr]:[start]-[end]";
    }
  }

  /* Everything below puts the id and the position straight into an upstream
     URL path, so anything that could leave that path is refused here rather
     than encoded -- the fasta-api's routes carry ':' and '-' unescaped and
     rawurlencode() would break a range request. */
  if ($id_str) {
    foreach (explode(',', $id_str) as $one) {
      if (trim($one) !== '' && !seqValidIdentifier(trim($one))) {
        $errors[] = "The identifier '" . htmlspecialchars($one, ENT_QUOTES, 'UTF-8')
                  . "' contains characters that are not part of a sequence name.";
      }
    }
  }
  if ($position && !preg_match('/^[A-Za-z0-9_.-]+:\d+-\d+$/', $position)) {
    // Already reported above for the general case; this catches odd chr names.
    if (!in_array("The position must take the form [chr]:[start]-[end]", $errors)) {
      $errors[] = "The position must take the form [chr]:[start]-[end]";
    }
  }

  if (count($errors) > 0) {
     header('Cache-Control: no-store');
     echo "Unable to process request:\n" . implode("\n", $errors) . "\n";
     exit;
  }

  /////
  // Build the work, then do it all at once.
  //
  // Each record is one output FASTA entry and carries an ORDERED list of
  // candidate lookups -- the file to try first, then the fallbacks. Round one
  // asks every record's first candidate in parallel; round two asks the second
  // candidate of only those that came back "not present", and so on. That
  // preserves every fallback the old code had while turning what was one
  // request per id per fallback into one request per ROUND.
  /////

  $records = array();

  if ($id_str) {
    foreach (explode(',', $id_str) as $id) {
      $id = trim($id);
      if ($id === '') { continue; }

      // Special-case for v1-v3. Bleech
      if (strstr($assembly, 'RefGen')) {
        $records[] = seqLegacyRecord($assembly, $annotation, $id, $dbtype, $lflank, $rflank);
      }

      else if ($lflank > 0 || $rflank > 0) {
        $records[] = seqFlankingRecord($assembly, $annotation, $id, $lflank, $rflank);
      }

      // Check if this is a pan-gene or gene family
      else if ($annotation == 'Pan-Zea') {  // note: gene family not yet implemented
        $records[] = seqPanGeneRecord($id, $dbtype, $exemplar);
      }

      // Likely a gene model
      else {
        $records[] = seqGeneModelRecord($assembly, $annotation, $id, $dbtype);
      }
    }//each id
  }//by id

  else if ($position) {
    // Assumes position/range is within the genome assembly, not a gene model
    if (strstr($assembly, 'RefGen')) {
      $assembly_mod = str_replace(' ', '_', $assembly);
      $file = getLegacyAssemblyFile(seqLegacyVersion($assembly_mod));
      $records[] = ($file === null)
        ? seqErrorRecord("Unknown assembly version for '$assembly'.")
        : seqRecord($position, seqPositionCandidates($position, "$assembly_mod/$file"));
    }
    else {
      $records[] = seqRecord($position, seqPositionCandidates($position, "$assembly/$assembly.fa.gz"),
                             "ERROR: Unable to get sequence for $position.");
    }
  }//by position

  seqResolve($records);

  /* One error no longer discards the sequences that were found: records are
     printed in the order they were asked for, each either as FASTA or as its
     own ERROR line. */
  $out = '';
  $found = 0;
  $unavailable = false;
  foreach ($records as $rec) {
    if ($rec['status'] === 'found') {
      $found++;
      $out .= seqFasta($rec['label'], $rec['sequence']);
    }
    else {
      if ($rec['status'] === 'unavailable') { $unavailable = true; }
      $out .= $rec['error'] . "\n";
    }
  }

  if ($found > 0 && !$unavailable) {
    /* A sequence does not change inside an annotation release, so let the
       browser and Cloudflare keep it. Nothing was cacheable before. */
    header('Cache-Control: public, max-age=86400');
  }
  else {
    header('Cache-Control: no-store');
  }

  echo ($out === '') ? 'No sequence found.' : $out;



//////////////////////////////////////////////////////////////////////////////////////////
//   Records: what to fetch, and what to call it
//////////////////////////////////////////////////////////////////////////////////////////

/* A candidate is one (identifier, file) pair to ask the fasta-api for.
   $path is everything after https://download.maizegdb.org/. */
function seqCandidate($id, $path, $label = null) {
  return array('id' => $id, 'path' => $path, 'label' => $label === null ? $id : $label);
}

function seqRecord($label, $candidates, $not_found = null) {
  return array(
    'label' => $label,
    'candidates' => $candidates,
    'status' => 'pending',
    'sequence' => '',
    'error' => $not_found === null
             ? "ERROR: sequence not found for '$label'."
             : $not_found
  );
}

function seqErrorRecord($message) {
  return array('label' => '', 'candidates' => array(), 'status' => 'missing',
               'sequence' => '', 'error' => "ERROR: $message");
}

/* Only characters that appear in real sequence names. Refusing is better than
   encoding: the upstream routes read ':' and '-' literally. */
function seqValidIdentifier($id) {
  return (bool) preg_match('/^[A-Za-z0-9._:+-]{1,200}$/', $id);
}

/* A gene model, transcript or protein on a modern assembly.

   The candidate ladder, in order:
     - the annotation's own file for the requested type;
     - the nc. (non-coding) file of the same type, which is where the
       non-coding models live;
     - for B73 v4 only, the same two against the other annotation, because
       the provisional gene models are published as a separate set. */
function seqGeneModelRecord($assembly, $annotation, $id, $dbtype) {
  $lookup_id = $id;
  $types = array($dbtype);

  if ($dbtype == 'cdna') {
    /* cdna used to be rewritten to cds outright. There is a cdna file for
       every current assembly, and it is a different sequence -- with the
       UTRs -- so ask for it and keep cds as the fallback for the older sets
       that really have none. */
    $types = array('cdna', 'cds');
  }
  else if ($dbtype == 'nuc') {
    // Generic nucleotide request: the whole gene for a gene model, the coding
    // sequence for a transcript.
    if (isGeneModelIdentifier($id)) {
      /* The id already IS a gene model. The old code ran it through
         getGeneModelNameFromTranscript() anyway, whose no-_T branch is
         preg_replace('/T/', '', $id) -- it strips every capital T from the
         name. Harmless on Zm00001eb..., GRMZM... and AC..._FG..., none of
         which contain one, but it is a trap waiting for an annotation that
         does. */
      $types = array('gene');
    }
    else {
      // Stupid hack for v4:
      $types = ($assembly == 'Zm-B73-REFERENCE-GRAMENE-4.0')
             ? array('transcripts') : array('cds');
    }
  }

  $annotations = array($annotation);
  if ($assembly == 'Zm-B73-REFERENCE-GRAMENE-4.0') {
    // Because of the provisional gene models. Sigh.
    $annotations[] = ($annotation == 'Zm00001d.provisional')
                   ? 'Zm00001d.2' : 'Zm00001d.provisional';
  }

  $candidates = array();
  foreach ($annotations as $ann) {
    foreach ($types as $type) {
      $candidates[] = seqCandidate($lookup_id, "$assembly/{$assembly}_$ann.$type.fa.gz");
      $candidates[] = seqCandidate($lookup_id, "$assembly/{$assembly}_$ann.nc.$type.fa.gz");
      /* Last resort: the canonical-only file. Il14H has no
         Zm-Il14H-REFERENCE-NAM-1.0_Zm00028ab.1.cds.fa.gz published at all --
         only canonical.cds -- so without this a CDS request on that line
         cannot be answered for any transcript. Everything in here is also in
         the main file wherever the main file exists, so it never changes an
         answer, only supplies one that was missing. */
      $candidates[] = seqCandidate($lookup_id, "$assembly/{$assembly}_$ann.canonical.$type.fa.gz");
    }
  }

  return seqRecord($lookup_id, $candidates,
    "ERROR: sequence not found for '$lookup_id' in assembly '$assembly', annotation '$annotation'.");
}//seqGeneModelRecord

/* B73 RefGen v1, v2 and v3, whose files are named nothing like the modern
   ones. Unchanged except that the nuc fallback now looks the GENE up by the
   gene id rather than by the transcript id it was handed. */
function seqLegacyRecord($assembly, $annotation, $id, $dbtype, $lflank, $rflank) {
  $assembly_mod = str_replace(' ', '_', $assembly);  // name used for directory....
  $v = seqLegacyVersion($assembly_mod);
  if ($v === null) {
    return seqErrorRecord("Unknown assembly version: $assembly.");
  }

  if ($lflank > 0 || $rflank > 0) {
    return seqFlankingRecord($assembly_mod, $annotation, $id, $lflank, $rflank);
  }

  $filename = getLegacyGeneModelFile($dbtype, $v);
  if ($filename === null) {
    return seqErrorRecord("Unknown assembly version: $v, or data type: $dbtype");
  }

  $filenames = explode(',', $filename);
  if (count($filenames) == 1) {
    return seqRecord($id, array(seqCandidate($id, "$assembly_mod/$filenames[0]")),
      "ERROR: sequence not found for '$id' in assembly '$assembly'.");
  }

  // NOTE: this assumes there are only 2 dbs to check
  $gene_filename = (strstr($filenames[0], 'gene')) ? $filenames[0] : $filenames[1];
  $cds_filename  = (strstr($filenames[0], 'cds'))  ? $filenames[0] : $filenames[1];

  if (isGeneModelIdentifier($id)) {
    $candidates = array(seqCandidate($id, "$assembly_mod/$gene_filename"));
  }
  else {
    // Try the transcript first, then the gene it belongs to.
    $gene_id = getGeneModelNameFromTranscript($id);
    $candidates = array(seqCandidate($id, "$assembly_mod/$cds_filename"));
    if ($gene_id !== '' && $gene_id !== $id) {
      $candidates[] = seqCandidate($gene_id, "$assembly_mod/$gene_filename");
    }
  }

  return seqRecord($id, $candidates,
    "ERROR: sequence not found for '$id' in assembly '$assembly'.");
}//seqLegacyRecord

function seqLegacyVersion($assembly_mod) {
  return preg_match("/_v(\d)/", $assembly_mod, $parts) ? $parts[1] : null;
}

/* A gene model plus flanking genomic sequence. One query for the feature's
   position, then a range request against the assembly FASTA. */
function seqFlankingRecord($assembly_mod, $annotation, $id, $lflank, $rflank) {
  $analysis = str_replace('_', ' ', $assembly_mod);
  $DBConn = connect_to_database();

  /* The id used to be concatenated into this statement. */
  $sql = "
    SELECT chr.name AS chr, fl.fmin AS start, fl.fmax AS end
    FROM chado.feature f
      INNER JOIN chado.featureloc fl ON fl.feature_id=f.feature_id
      INNER JOIN chado.feature chr ON chr.feature_id=fl.srcfeature_id
      INNER JOIN chado.analysisfeature af ON af.feature_id=f.feature_id
      INNER JOIN chado.analysis a ON a.analysis_id=af.analysis_id
    WHERE f.name=:name AND (a.name=:analysis OR a.name=:assembly_mod)";
  $sth = make_query($DBConn, $sql, 1,
    array('name' => $id, 'analysis' => $analysis, 'assembly_mod' => $assembly_mod));
  if (!($row = retrieve_row($sth))) {
    return seqErrorRecord("Unable to find position for $id");
  }

  $start = max(1, ((int) $row['start']) - $lflank);
  $position = $row['chr'] . ':' . $start . '-' . (((int) $row['end']) + $rflank);
  $rec = seqRecord("$id $position",
    array(seqCandidate($position, "$assembly_mod/$assembly_mod.fa.gz", "$id $position")),
    "ERROR: Unable to get sequence for $position.");
  return $rec;
}//seqFlankingRecord

/* A position, and the ways the same chromosome is spelled across assemblies.

   The modern assemblies call it chr4; B73 RefGen_v3 calls it Chr4 and v1/v2
   have their own habits, so a documented position request like
   "?assembly=B73 RefGen_v3&position=chr4:350010-350100" -- the example in this
   file's own header -- has always come back empty. Ask for the spelling given
   first, then the obvious variants; a hit is cached, so the extra round trip
   happens once per assembly rather than once per request. The label always
   shows the position as the caller wrote it. */
function seqPositionCandidates($position, $path) {
  $parts = explode(':', $position, 2);
  $seq = $parts[0];
  $range = isset($parts[1]) ? $parts[1] : '';

  $names = array($seq);
  $bare = preg_match('/^chr(.+)$/i', $seq, $m) ? $m[1] : $seq;
  foreach (array($bare, 'chr' . $bare, 'Chr' . $bare, strtolower($seq), ucfirst(strtolower($seq))) as $n) {
    if ($n !== '' && !in_array($n, $names, true)) { $names[] = $n; }
  }

  $candidates = array();
  foreach ($names as $n) {
    $candidates[] = seqCandidate($range === '' ? $n : "$n:$range", $path, $position);
  }
  return $candidates;
}//seqPositionCandidates

/* A pan-gene. With no exemplar the sequence is returned with no header at
   all, which is what the pan-gene pages expect. */
function seqPanGeneRecord($id, $dbtype, $exemplar) {
  // Way too much hard-coding...
  if ($dbtype == 'nuc' || $dbtype == 'cds') {
    $dbtype = 'CDS';
  }
  $version = preg_replace('/pan-zea\.(v\d+)\..*/', "$1", $id);
  $label = ($exemplar && trim($exemplar) != '') ? $exemplar : '';
  $rec = seqRecord($label,
    array(seqCandidate($id, "Pan-genes/Pan-Zea/pan-zea.$version.$dbtype.fa.gz", $label)),
    "ERROR: sequence not found for '$id' in pan-genes, analysis pan-zea.$version.");
  return $rec;
}//seqPanGeneRecord



//////////////////////////////////////////////////////////////////////////////////////////
//   The transport: cache, parallel fetch, retry
//////////////////////////////////////////////////////////////////////////////////////////

/* Walk every record's candidate list, a round at a time, asking the whole
   round in parallel. A record is done when a candidate returns a sequence, or
   when it runs out of candidates, or when the service could not be reached at
   all (which is reported as such rather than as a missing sequence). */
function seqResolve(&$records) {
  global $fetch_url, $data_url, $seq_started, $seq_degraded;

  $round = 0;
  while (true) {
    $batch = array();
    $lookups = array();
    foreach ($records as $i => $rec) {
      if ($rec['status'] !== 'pending') { continue; }
      if (!isset($rec['candidates'][$round])) {
        /* Out of candidates. Which answer this is depends on whether anything
           along the way failed for service reasons: "not found" is a claim
           about the data and must not be made on the strength of a 502. */
        if (!empty($rec['unavailable'])) {
          $records[$i]['status'] = 'unavailable';
          $records[$i]['error'] = 'SEQUENCE SERVICE IS DOWN.';
        }
        else {
          $records[$i]['status'] = 'missing';
        }
        continue;
      }
      $c = $rec['candidates'][$round];
      if (seqKnownAbsent($c['path'])) {
        /* The mirror tool asked download.maizegdb.org for this file and got a
           404. Skipping it saves a whole retry ladder against a service that
           answers "no such file" with the same 502 it uses for "I am unwell". */
        continue;
      }
      $batch[$i] = "$fetch_url/" . $c['id'] . "/$data_url/" . $c['path'];
      $lookups[$i] = $c;
    }
    /* An empty batch does not mean the work is done: every candidate this
       round may have been skipped as not published. Only stop when nothing is
       pending any more. */
    if (count($batch) === 0) {
      $pending = false;
      foreach ($records as $rec) { if ($rec['status'] === 'pending') { $pending = true; break; } }
      if (!$pending) { break; }
      $round++;
      continue;
    }

    /* Past the deadline nothing more goes out: say so rather than keeping the
       reader waiting through another ladder of fallbacks. */
    if (microtime(true) - $seq_started > SEQ_DEADLINE) {
      foreach (array_keys($batch) as $i) {
        $records[$i]['status'] = 'unavailable';
        $records[$i]['error'] = 'SEQUENCE SERVICE IS DOWN.';
      }
      break;
    }

    $answers = seqFetchAll($batch, $lookups);

    foreach ($answers as $i => $answer) {
      $c = $records[$i]['candidates'][$round];
      if ($answer['status'] === 'found') {
        $records[$i]['status'] = 'found';
        $records[$i]['sequence'] = $answer['sequence'];
        $records[$i]['label'] = $c['label'];
      }
      else if ($answer['status'] === 'unavailable') {
        /* Keep walking the ladder. The obvious thing is to stop -- if the
           service is down the fallbacks will fail the same way -- but the
           service answers a request for a file that does not EXIST with a 502
           as well, so "down" and "no such file" look identical from here. That
           is not hypothetical: Il14H publishes no plain cds.fa.gz, and stopping
           at the first candidate meant its canonical.cds, sitting mirrored on
           local disk, was never reached. Remember the failure instead and use
           it only if nothing further answers.

           $seq_degraded stops the retries for the rest of the request, so a
           genuine outage costs one attempt per remaining candidate rather than
           three. */
        $records[$i]['unavailable'] = true;
        $seq_degraded = true;
      }
      // 'missing' just falls through to the next candidate.
    }
    $round++;
  }
}//seqResolve

/* Fetch a whole round. Cached answers are taken first and never go out on the
   wire; the rest go out together. Returns key => array(status, sequence). */
function seqFetchAll($urls, $lookups) {
  $out = array();
  $todo = array();

  foreach ($urls as $key => $url) {
    /* Local mirror first. It is the same bytes, it cannot 502, and it answers
       in about a millisecond -- see tools/sequence/sequence_mirror.php. */
    $local = seqLocalRead($lookups[$key]['path'], $lookups[$key]['id']);
    if ($local !== false) {
      $out[$key] = ($local === null)
        ? array('status' => 'missing', 'sequence' => '')
        : array('status' => 'found', 'sequence' => $local);
      continue;
    }
    $hit = seqCacheGet($url);
    if ($hit !== null) {
      $out[$key] = $hit;
    }
    else {
      $todo[$key] = $url;
    }
  }
  if (count($todo) === 0) { return $out; }

  $responses = seqHttpAll($todo);

  foreach ($responses as $key => $r) {
    $url = $todo[$key];
    if ($r['code'] == 200) {
      $body = json_decode($r['body']);
      if (isset($body->{'sequence'}) && $body->{'sequence'} !== '') {
        $answer = array('status' => 'found', 'sequence' => $body->{'sequence'});
        seqCachePut($url, $answer);
        $out[$key] = $answer;
        continue;
      }
      /* 200 with no sequence field is the API telling us the file is fine and
         the identifier is not in it. */
      $answer = array('status' => 'missing', 'sequence' => '');
      seqCachePut($url, $answer);
      $out[$key] = $answer;
      continue;
    }

    if ($r['code'] == 400 || $r['code'] == 404 || $r['code'] == 422) {
      // A real answer: no such sequence, or no such file. Worth remembering,
      // but only briefly -- a file can be published later.
      $answer = array('status' => 'missing', 'sequence' => '');
      seqCachePut($url, $answer);
      $out[$key] = $answer;
      continue;
    }

    /* The service failed. If we ever had this sequence, serve it rather than
       an error -- a sequence does not change, so a stale copy is the right
       answer and an error is not. */
    $stale = seqCacheGet($url, true);
    if ($stale !== null && $stale['status'] === 'found') {
      $out[$key] = $stale;
      continue;
    }
    logMessage('get_sequence: upstream failed (' . $r['code'] . ' ' . $r['err'] . ') for ' . $url);
    $out[$key] = array('status' => 'unavailable', 'sequence' => '');
  }

  return $out;
}//seqFetchAll

/* Issue a set of GETs together and retry the ones that failed in a way worth
   retrying. One multi handle for the whole request, so the TLS session to
   Cloudflare is negotiated once and reused by every round. */
function seqHttpAll($urls) {
  global $seq_started, $seq_degraded;
  static $mh = null;
  if ($mh === null) {
    $mh = curl_multi_init();
    /* libcurl queues the rest itself, so a 40-identifier request still goes
       out four at a time rather than forty. */
    curl_multi_setopt($mh, CURLMOPT_MAX_HOST_CONNECTIONS, SEQ_MAX_CONCURRENCY);
    curl_multi_setopt($mh, CURLMOPT_MAX_TOTAL_CONNECTIONS, SEQ_MAX_CONCURRENCY);
  }

  $delays = array_map('intval', explode(',', SEQ_RETRY_DELAYS));
  $pending = $urls;
  $out = array();

  $attempts = $seq_degraded ? 1 : SEQ_ATTEMPTS;
  for ($attempt = 0; $attempt < $attempts && count($pending) > 0; $attempt++) {
    if (microtime(true) - $seq_started > SEQ_DEADLINE) { break; }
    if ($attempt > 0) {
      usleep(1000 * (isset($delays[$attempt - 1]) ? $delays[$attempt - 1] : 400));
    }

    $handles = array();
    foreach ($pending as $key => $url) {
      $ch = curl_init();
      curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => SEQ_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => SEQ_TOTAL_TIMEOUT,
        CURLOPT_ENCODING => '',            // accept gzip; the JSON compresses well
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'MaizeGDB/get_sequence.php',
        CURLOPT_HTTPHEADER => array('Accept: application/json')
      ));
      curl_multi_add_handle($mh, $ch);
      $handles[$key] = $ch;
    }

    $running = null;
    do {
      $status = curl_multi_exec($mh, $running);
      if ($running) {
        /* select() returns -1 immediately when libcurl has no descriptor to
           wait on, which turns this into a busy loop that burns a core. */
        if (curl_multi_select($mh, 1.0) === -1) { usleep(1000); }
      }
    } while ($running > 0 && $status == CURLM_OK);

    $retry = array();
    foreach ($handles as $key => $ch) {
      $body = curl_multi_getcontent($ch);
      $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
      $err  = curl_error($ch);
      curl_multi_remove_handle($mh, $ch);
      curl_close($ch);

      /* 0 is a transport failure (connect, TLS, timeout); 5xx and 429 are
         Cloudflare or the origin having a moment. Both clear on a retry far
         more often than not. A 4xx is an answer and is never retried. */
      if ($code === 0 || $code >= 500 || $code == 429) {
        $retry[$key] = $pending[$key];
        $out[$key] = array('code' => $code, 'body' => '', 'err' => $err);
      }
      else {
        $out[$key] = array('code' => $code, 'body' => (string) $body, 'err' => $err);
        unset($retry[$key]);
      }
    }
    $pending = $retry;
  }

  return $out;
}//seqHttpAll



//////////////////////////////////////////////////////////////////////////////////////////
//   The local mirror
//////////////////////////////////////////////////////////////////////////////////////////

/* Files tools/sequence/sequence_mirror.php asked download.maizegdb.org for and
   was told do not exist -- Il14H publishes no plain cds.fa.gz, four NAM lines
   publish no nc.* -- so a candidate naming one can be skipped rather than
   retried. Rebuilt whenever the mirror is. */
function seqKnownAbsent($path) {
  static $list = null;
  if ($list === null) {
    $file = dirname(__FILE__) . '/../../data/sequence/absent.json';
    $raw = is_file($file) ? @file_get_contents($file) : false;
    $list = ($raw === false) ? array() : json_decode($raw, true);
    if (!is_array($list)) { $list = array(); }
  }
  return isset($list[$path]);
}

/* A published FASTA that has been mirrored onto this machine by
   tools/sequence/sequence_mirror.php: the sequences in deflated 64 KB blocks
   and a fixed-width sorted index beside them. A lookup is a binary search of
   the index -- about 17 reads of a few dozen bytes -- one seek, one read of
   roughly 20 KB and one inflate, which takes 0.22 ms.

   Three answers, and the difference matters:
     a string  the sequence;
     null      this file IS mirrored and does not contain that identifier,
               which is a real answer and stops the caller going to the web;
     false     this file is not mirrored, so ask the service as before.

   $path is the download.maizegdb.org path of the .fa.gz, so the mirror is a
   mirror: data/sequence/<assembly>/<file>.faz. */
function seqLocalRead($path, $id) {
  static $handles = array();

  $faz = seqLocalPath($path);
  if ($faz === null) { return false; }

  if (!isset($handles[$faz])) {
    $ih = @fopen("$faz.idx", 'rb');
    if (!$ih) { $handles[$faz] = null; return false; }
    $bits = explode(' ', trim((string) fgets($ih)));
    if (count($bits) < 5 || $bits[0] !== 'MGDBSEQIDX2') { fclose($ih); $handles[$faz] = null; return false; }
    $fh = @fopen($faz, 'rb');
    if (!$fh) { fclose($ih); $handles[$faz] = null; return false; }
    $handles[$faz] = array('idx' => $ih, 'faz' => $fh, 'width' => (int) $bits[1],
                           'count' => (int) $bits[2], 'idlen' => (int) $bits[3],
                           'cached_at' => -1, 'cached' => '');
  }
  $m =& $handles[$faz];
  if ($m === null) { return false; }

  $lo = 0;
  $hi = $m['count'] - 1;
  $row = null;
  while ($lo <= $hi) {
    $mid = intdiv($lo + $hi, 2);
    fseek($m['idx'], $m['width'] * ($mid + 1));
    $candidate = fread($m['idx'], $m['width']);
    if ($candidate === false || $candidate === '') { break; }
    $cmp = strcmp(rtrim(substr($candidate, 0, $m['idlen'])), $id);
    if ($cmp === 0) { $row = $candidate; break; }
    if ($cmp < 0) { $lo = $mid + 1; } else { $hi = $mid - 1; }
  }
  if ($row === null) { return null; }

  $n = $m['idlen'];
  $boff = (int) substr($row, $n + 1, 12);
  $blen = (int) substr($row, $n + 14, 8);
  $roff = (int) substr($row, $n + 23, 6);
  $rlen = (int) substr($row, $n + 30, 8);

  /* One block held back. The transcripts of a gene are adjacent in the file,
     so a four-identifier request usually inflates once rather than four
     times. */
  if ($m['cached_at'] !== $boff) {
    fseek($m['faz'], $boff);
    $block = @gzinflate((string) fread($m['faz'], $blen));
    if ($block === false) { return null; }
    $m['cached_at'] = $boff;
    $m['cached'] = $block;
  }
  $seq = substr($m['cached'], $roff, $rlen);
  return ($seq === false || $seq === '') ? null : $seq;
}//seqLocalRead

/* Where a mirrored file would be. Refuses anything that could climb out of
   the store, because $path is assembled from request parameters. */
function seqLocalPath($path) {
  static $root = null;
  if ($root === null) {
    $root = realpath(dirname(__FILE__) . '/../../data/sequence');
    if ($root === false) { $root = ''; }
  }
  if ($root === '') { return null; }
  if (strpos($path, '..') !== false) { return null; }
  $faz = $root . '/' . preg_replace('/\.(fa|fasta)\.gz$/', '.faz', $path);
  return is_file("$faz.idx") ? $faz : null;
}



//////////////////////////////////////////////////////////////////////////////////////////
//   The cache
//////////////////////////////////////////////////////////////////////////////////////////

/* Every filesystem problem fails open: a sequence server that stops answering
   because a cache directory is not writable is worse than one that is slow. */
function seqCacheDir() {
  global $system;
  static $dir = false;
  if ($dir !== false) { return $dir; }

  $dir = null;
  if (isset($system['sequence_cache'])
      && strtolower(trim($system['sequence_cache'])) === 'false') {
    return $dir;
  }

  /* In preference order, and it falls through rather than giving up: on dev8
     /home/cache is labelled user_home_dir_t, so apache cannot create a new
     subdirectory of it -- /home/cache/dashboard and /home/cache/search were
     labelled httpd_sys_rw_content_t by an administrator, one at a time. Until
     /home/cache/sequence is given the same label (AD-077) this lands in the
     php-fpm private tmp, which every worker of the pool shares and which is
     cleared when the service restarts. A cache that empties on a restart is
     worth having; no cache at all is not. */
  $paths = array();
  if (!empty($system['sequence_cache_path'])) {
    $paths[] = rtrim($system['sequence_cache_path'], '/');
  }
  if (!empty($system['search_cache_path'])) {
    $paths[] = rtrim($system['search_cache_path'], '/') . '/sequence';
  }
  $paths[] = sys_get_temp_dir() . '/mgdb-sequence-cache';

  foreach ($paths as $path) {
    if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) { continue; }
    if (!is_writable($path)) { continue; }
    $dir = $path;
    return $dir;
  }
  return $dir;
}

function seqCacheFile($url) {
  $dir = seqCacheDir();
  if ($dir === null) { return null; }
  $hash = sha1($url);
  $sub = $dir . '/' . substr($hash, 0, 2);
  if (!is_dir($sub) && !@mkdir($sub, 0775, true) && !is_dir($sub)) { return null; }
  return "$sub/$hash";
}

function seqCacheTtl($kind) {
  global $system;
  if ($kind === 'missing') {
    return isset($system['sequence_cache_miss_ttl'])
         ? max(0, (int) $system['sequence_cache_miss_ttl']) : 900;
  }
  return isset($system['sequence_cache_ttl'])
       ? max(0, (int) $system['sequence_cache_ttl']) : 2592000;
}

/* $any_age is the stale-while-broken read: used only when the service has
   already failed, where an old sequence beats an error. */
function seqCacheGet($url, $any_age = false) {
  $file = seqCacheFile($url);
  if ($file === null || !is_file($file)) { return null; }

  $raw = @file_get_contents($file);
  if ($raw === false || $raw === '') { return null; }
  $entry = json_decode($raw, true);
  if (!is_array($entry) || !isset($entry['status'])) { return null; }

  if (!$any_age) {
    $ttl = seqCacheTtl($entry['status']);
    if ($ttl > 0 && (time() - (int) @filemtime($file)) > $ttl) { return null; }
  }
  return array('status' => $entry['status'],
               'sequence' => isset($entry['sequence']) ? $entry['sequence'] : '');
}

function seqCachePut($url, $answer) {
  $file = seqCacheFile($url);
  if ($file === null) { return; }
  /* Written to a neighbour and renamed, so a reader never sees half a file. */
  $tmp = $file . '.' . getmypid() . '.tmp';
  if (@file_put_contents($tmp, json_encode($answer)) === false) { return; }
  if (!@rename($tmp, $file)) { @unlink($tmp); }
}



//////////////////////////////////////////////////////////////////////////////////////////
//   Output
//////////////////////////////////////////////////////////////////////////////////////////

/* 80-column FASTA with CRLF, as this server has always produced. A record
   with no label is emitted bare, which is what a pan-gene request without an
   exemplar has always returned. */
function seqFasta($label, $sequence) {
  $wrapped = chunk_split($sequence, 80, "\r\n");
  return ($label === '' || $label === null) ? $wrapped : '>' . $label . "\r\n" . $wrapped;
}



//////////////////////////////////////////////////////////////////////////////////////////
//   Legacy assembly file names
//////////////////////////////////////////////////////////////////////////////////////////

function getLegacyAssemblyFile($v) {
  if ($v == '1') {
    return 'ZmB73_AGPv1.fa.gz';
  }//v1
  else if ($v == '2') {
    return 'B73_RefGen_v2.fa.gz';
  }//v2
  else if ($v == '3') {
    return 'B73_RefGen_v3.fa.gz';
  }//v3

  return null;
}//getLegacyAssemblyFile


/* Returns one filename, or two comma-separated for a generic nucleotide
   request, or null when the combination has no file. It used to echo its
   error into the middle of the FASTA. */
function getLegacyGeneModelFile($dbtype, $v) {
  if ($v == '1') {
    if ($dbtype == 'nuc') {
      return 'ZmB73_4a.53_working_genes.fasta.gz,ZmB73_4a.53_working_cds.fasta.gz';
    }
    else if ($dbtype == 'cds') {
      return 'ZmB73_4a.53_working_cds.fasta.gz';
    }
    else if ($dbtype == 'cdna') {
      return 'ZmB73_4a.53_working_cdna.fasta.gz';
    }
    else if ($dbtype == 'gene') {
      return 'ZmB73_4a.53_working_genes.fasta.gz';
    }
    else if ($dbtype == 'protein') {
      return 'ZmB73_4a.53_working_translations.fasta.gz';
    }
  }//v1
  else if ($v == '2') {
    if ($dbtype == 'nuc') {
      return 'ZmB73_5a.59_working.genes.fasta.gz,ZmB73_5a.59_working.cds.fasta.gz';
    }
    else if ($dbtype == 'cds') {
      return 'ZmB73_5a.59_working.cds.fasta.gz';
    }
    else if ($dbtype == 'cdna') {
      return 'ZmB73_5a.59_working.cdna.fasta.gz';
    }
    else if ($dbtype == 'gene') {
      return 'ZmB73_5a.59_working.genes.fasta.gz';
    }
    else if ($dbtype == 'protein') {
      return 'ZmB73_5a.59_working.translations.fasta.gz';
    }
  }//v2
  else if ($v == '3') {
// 3/16/26 note: changed these to AGP.v21 as v22 appears to be missing
//               low confidence gene models.
    if ($dbtype == 'nuc') {
      return 'Zea_mays.AGPv3.21.genes.all.fa.gz,Zea_mays.AGPv3.21.cds.fa.gz';
    }
    else if ($dbtype == 'cds') {
      // No cDNA file for v3
      return 'Zea_mays.AGPv3.21.cds.fa.gz';
    }
    else if ($dbtype == 'cdna') {
      return 'Zea_mays.AGPv3.21.transcripts.fa.gz';
    }
    else if ($dbtype == 'gene') {
      return 'Zea_mays.AGPv3.21.genes.all.fa.gz';
    }
    else if ($dbtype == 'protein') {
      return 'Zea_mays.AGPv3.21.protein.fa.gz';
    }
  }//v3

  return null;
}//getLegacyGeneModelFile
