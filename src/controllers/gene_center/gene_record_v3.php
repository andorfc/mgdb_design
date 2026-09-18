<?PHP
/* file: gene_record_v3.php
 *
 * purpose: Gene record header mockup (/gene_center/gene_v3/{id}) -- the
 *          header alone, nothing below it.
 *
 *          Included by controllers/gene_center.php when PAGE is 'gene_v3'
 *          and a record identifier is present. Returns false without
 *          publishing if the identifier does not resolve, so the caller
 *          falls through to its own not-found handling.
 *
 *          Left column, the gene locus: locus name, plant-wide name, whether
 *          it is on the Classical Gene List (nothing when it is not),
 *          species, NCBI Gene with its comment, and the genetic data --
 *          linkage group, cM on the backbone map, bin. Right column, the
 *          most current gene model of that locus: name, model type,
 *          location, canonical transcript and its length, number of
 *          transcripts, and a thin strip of the chromosome with the gene
 *          pinned. Under both, the synonyms with their authority or
 *          reference, as a slim table.
 *
 *          Everything is rendered here from about eight indexed lookups and
 *          one file read; the page loads no script of its own and makes no
 *          API call. See include/gene_header_lib.php.
 */

  include_once('./include/db-api.php');
  include_once('./include/gene_record_lib.php');
  include_once('./include/gene_header_lib.php');

  $system = getSystemInfo('mgdb.conf');
  $DBConn = connect_to_database(false);
  if (!$DBConn) {
    return false;
  }

  $gene_request = rawurldecode((string) getCGIParam('id', 'G', ID));
  $gene_resolved = geneResolveId($DBConn, $gene_request);
  if ($gene_resolved === false) {
    return false;
  }
  $gene_identity = geneIdentity($DBConn, $gene_resolved);
  if (!$gene_identity) {
    return false;
  }
  logMessage('Starting gene_record_v3.php for ' . $gene_identity['name']);

  $esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
  $withdrawn = ($gene_identity['kind'] === 'withdrawn');

  /* The locus, and the model the right column describes. A withdrawn model
     has neither. */
  $locus = $withdrawn ? false : geneHeaderLocus($DBConn, $gene_identity['locus_id']);
  $model = $withdrawn ? false : geneHeaderCurrentModel($DBConn, $gene_identity['locus_id'], $gene_resolved['row']);

  $model_name = $model ? trim((string) $model['gene_name']) : '';
  $model_assembly = $model ? trim((string) $model['assembly_version']) : '';
  $model_canonical = $model ? trim((string) $model['canonical_transcript_name']) : '';
  $transcript = $model ? geneHeaderTranscript($model_assembly, $model_name, $model_canonical) : false;
  $chr_context = $model ? geneChromosomeContext($DBConn, $model['feature_id']) : array('name' => '', 'length' => null, 'karyotype' => array());

  /* Names, as on the other two pages: a classical gene leads with its symbol. */
  $gene_name = $gene_identity['name'];
  $gene_symbol = $locus ? $locus['name'] : $gene_identity['symbol'];
  $gene_full_name = $locus ? $locus['full_name'] : $gene_identity['full_name'];
  $gene_display = ($gene_symbol !== '' && strcasecmp($gene_symbol, $gene_name) !== 0) ? $gene_symbol : $gene_name;
  $gene_title = 'MaizeGDB Gene: ' . $gene_display
              . (($gene_display !== $gene_name && $gene_name !== '') ? ' (' . $gene_name . ')' : '');
  $gene_summary = $gene_display . ($gene_full_name !== '' && strcasecmp($gene_full_name, $gene_display) !== 0 ? ' (' . $gene_full_name . ')' : '')
                . ($locus ? ' is a maize gene' : ' is a maize gene model')
                . ($model_name !== '' ? ', gene model ' . $model_name . ($model_assembly !== '' ? ' in ' . $model_assembly : '') : '') . '.';

  /* No kind pill. The two column headings already say "Gene locus" and "Gene
     model", so a bubble reading "Gene model and classical gene" beside the name
     restated the layout rather than adding to it. */

  /* One fact row: label, value markup, optional small note. Empty values are
     dropped, which is how "display nothing if it is not" is done for the
     classical-gene row and the plant-wide name. */
  $fact = function ($label, $value, $note = '') use ($esc) {
    if ($value === '' || $value === null) { return ''; }
    return '<div><dt>' . $esc($label) . '</dt><dd>' . $value . ($note !== '' ? '<small>' . $esc($note) . '</small>' : '') . '</dd></div>';
  };

  $number = function ($n) { return number_format((int) $n); };

  /* ---- Left column: the gene locus ------------------------------------ */
  $locus_column = '';
  $h1_placed = false;
  if ($locus) {
    $rows = '';
    $rows .= '<div><dt>Locus name</dt><dd><h1 id="gene-record-title">' . $esc($locus['name']) . '</h1>'
           . ($locus['full_name'] !== '' && strcasecmp($locus['full_name'], $locus['name']) !== 0
              ? '<span class="v3-fullname">' . $esc($locus['full_name']) . '</span>' : '')
           . '</dd></div>';
    $h1_placed = true;
    $rows .= $fact('Plant-wide name', $locus['plant_wide_name'] !== '' ? $esc($locus['plant_wide_name']) : '');
    if ($locus['classical']) {
      $rows .= $fact('Classical gene', 'Yes', $locus['classical_key'] !== '' ? 'on the Classical Gene List as ' . $locus['classical_key'] : 'on the Classical Gene List');
    }
    $rows .= $fact('Species', $locus['species'] !== '' ? '<em>' . $esc($locus['species']) . '</em>' : '');
    if ($locus['ncbi_gene'] !== '') {
      $rows .= $fact('NCBI Gene',
        '<a href="' . $esc($locus['ncbi_url']) . '" target="_blank" rel="noopener">' . $esc($locus['ncbi_gene']) . '</a>',
        $locus['ncbi_comment']);
    }
    /* Every synonym on one line, under the identifiers it belongs with.
       geneHeaderLocus() already drops the locus's own name and full name, so
       this is exactly the list the Synonyms table below enumerates -- the same
       nine names for ptk5 -- without repeating their authorities and
       references, which is what the table is for. */
    if (count($locus['synonyms'])) {
      $aka = array();
      foreach ($locus['synonyms'] as $s_row) { $aka[] = $esc($s_row['name']); }
      $rows .= $fact('Also known as',
        '<span class="v3-aka">' . implode(' <span class="v3-aka-sep" aria-hidden="true">&middot;</span> ', $aka) . '</span>');
    }

    $genetic = array();
    if ($locus['linkage_group'] !== '') {
      $genetic[] = '<li><span>Linkage group</span><span>' . $esc($locus['linkage_group'])
                 . ($locus['arm'] !== '' ? ' <small class="is-inline">' . ($locus['arm'] === 'S' ? 'short arm' : ($locus['arm'] === 'L' ? 'long arm' : $esc($locus['arm']))) . '</small>' : '')
                 . '</span></li>';
    }
    if ($locus['cm'] !== null) {
      $genetic[] = '<li><span>cM</span><span>' . $esc(rtrim(rtrim(number_format($locus['cm'], 2, '.', ''), '0'), '.'))
                 . ($locus['cm_map'] !== '' ? ' <small class="is-inline">on ' . $esc($locus['cm_map']) . '</small>' : '') . '</span></li>';
    }
    if ($locus['bin'] !== '') {
      $genetic[] = '<li><span>Bin</span><span>' . $esc($locus['bin']) . '</span></li>';
    }
    /* The genetic data is not a fact row any more: all of it -- linkage group,
       arm, cM, map, bin -- is said inside the Genetic map glyph at the foot of
       this column, where the number has a line to sit on. Built below. */
    $locus_column = '<dl class="v3-facts">' . $rows . '</dl>';

    /* ---- The Genetic map glyph, the twin of the Physical map one -----------
       Same shape as the physical strip in the other column: a caption naming
       where the gene sits, a line to scale with a pin on it, the two ends
       labelled, and a bar per linkage group with this one marked. The only
       difference is the unit -- centiMorgans on a genetic map, megabases on
       the physical one -- and that is the point of showing them side by side.

       All of the old Genetic data row is in the caption: linkage group, arm,
       cM, the map the number is on, and the bin. */
    $cm_context = ($locus['cm'] !== null && $locus['cm_map'] !== '')
      ? geneGeneticMapContext($DBConn, $locus['cm_map'])
      : array('name' => '', 'length' => null, 'start' => 0.0, 'maps' => array());

    if ($cm_context['name'] !== '' && $cm_context['length'] > 0) {
      $cm_val = (float) $locus['cm'];
      $cm_lo  = (float) $cm_context['start'];
      $cm_hi  = (float) $cm_context['length'];
      $span   = ($cm_hi - $cm_lo) > 0 ? ($cm_hi - $cm_lo) : 1;
      $cm_frac = max(0, min(1, ($cm_val - $cm_lo) / $span));
      /* A cM value is written to one decimal, and a whole number without one. */
      $cm = function ($v) { return rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.') . ' cM'; };

      $arm_text = '';
      if ($locus['arm'] !== '') {
        $arm_text = ($locus['arm'] === 'S' ? 'short arm' : ($locus['arm'] === 'L' ? 'long arm' : $locus['arm']));
      }
      $caption = '<strong>' . $esc($cm_context['name']) . '</strong> at <strong>' . $esc($cm($cm_val)) . '</strong> of ' . $esc($cm($cm_hi));
      $detail = array();
      if ($locus['linkage_group'] !== '') {
        $detail[] = 'linkage group ' . $esc($locus['linkage_group']) . ($arm_text !== '' ? ' ' . $esc($arm_text) : '');
      }
      if ($locus['bin'] !== '') { $detail[] = 'bin ' . $esc($locus['bin']); }

      $cm_bars = '';
      if (count($cm_context['maps']) >= 2) {
        $max = 0;
        foreach ($cm_context['maps'] as $m) { $max = max($max, (float) $m[1]); }
        $cm_bars .= '<ul class="v3-karyotype" aria-label="Linkage groups of the genetic map, to scale in centiMorgans">';
        foreach ($cm_context['maps'] as $m) {
          $here = ($m[0] === $cm_context['name']);
          $h = $max > 0 ? (int) round(100 * (float) $m[1] / $max) : 0;
          $cm_bars .= '<li' . ($here ? ' class="is-here"' : '') . ' title="' . $esc($m[0] . ', ' . $cm($m[1])) . '">'
                   . '<span class="v3-chr-bar" style="height:' . $h . '%">'
                   . ($here ? '<span class="v3-chr-pin" style="top:' . number_format($cm_frac * 100, 1, '.', '') . '%"></span>' : '')
                   . '</span><span class="v3-chr-label">' . $esc(preg_replace('/^Genetic /', '', $m[0])) . '</span></li>';
        }
        $cm_bars .= '</ul>';
      }

      $locus_column .= '<div class="v3-genome v3-map">'
        . '<h3 class="v3-map-head">Genetic map</h3>'
        . '<div class="v3-genome-line">'
        .   '<p class="v3-genome-caption">' . $caption
        .   (count($detail) ? ' &middot; ' . implode(' &middot; ', $detail) : '') . '</p>'
        .   '<div class="v3-chrline" role="img" aria-label="' . $esc($cm_context['name'] . ', ' . $cm($cm_hi) . ', the gene at ' . $cm($cm_val)) . '">'
        .     '<span class="v3-chrline-pin" style="left:' . number_format($cm_frac * 100, 2, '.', '') . '%"></span></div>'
        .   '<div class="v3-chrline-ends"><span>' . $esc($cm($cm_lo)) . '</span><span>' . $esc($cm($cm_hi)) . '</span></div>'
        . '</div>'
        . $cm_bars
        . '</div>';
    }
  } else if ($withdrawn) {
    $locus_column = '<p class="v3-empty">This gene model was withdrawn from the annotation'
      . ($gene_identity['replacement'] !== '' ? ' and replaced by <a href="/gene_center/gene_v3/' . rawurlencode($gene_identity['replacement']) . '">' . $esc($gene_identity['replacement']) . '</a>' : '')
      . '.</p>';
  } else {
    $locus_column = '<p class="v3-empty">No classical gene is linked to this gene model.</p>';
  }

  /* ---- Right column: the most current gene model ------------------------ */
  $model_column = '';
  $genome_strip = '';
  if ($model) {
    /* The annotation count that stood beside this heading is gone: the Name
       row below already names the assembly and the annotation, which is the
       fact a reader needs, and "most current of 6 annotations" invited the
       question of where the other five are without answering it. */
    $name_value = '<a class="mgdb-record-id" href="/gene_center/gene/' . rawurlencode($model_name) . '">' . $esc($model_name) . '</a>';
    if (!$h1_placed) {
      $name_value = '<h1 id="gene-record-title" class="mgdb-record-id">' . $esc($model_name) . '</h1>';
      $h1_placed = true;
    } else if (!$model['is_resolved'] && $gene_name !== '' && strcasecmp($gene_name, $model_name) !== 0) {
      $name_value .= '<small>you asked for ' . $esc($gene_name) . ', the ' . $esc($gene_identity['assembly']) . ' annotation</small>';
    }
    $rows = '';
    $rows .= $fact('Name', $name_value,
      $model_assembly !== '' ? $model_assembly . (trim((string) $model['version']) !== '' ? ', annotation ' . trim((string) $model['version']) : '') : '');
    $rows .= $fact('Model type', trim((string) $model['model_type']) !== '' ? $esc(str_replace('_', ' ', trim((string) $model['model_type']))) : '');
    /* Location is not a fact row any more: the coordinates and the span are in
       the Physical map glyph at the foot of this column, beside the line that
       plots them -- the mirror of what the Genetic map glyph does with the cM
       value in the other column. */
    $chr = trim((string) $model['chr']);
    $rows .= $fact('Canonical transcript', $model_canonical !== '' ? '<span class="mgdb-record-id">' . $esc($model_canonical) . '</span>' : '');
    if ($transcript && $transcript['mrna_nt'] > 0) {
      $rows .= $fact('Canonical transcript length', $number($transcript['mrna_nt']) . ' nt',
        ($transcript['cds_nt'] ? 'CDS ' . $number($transcript['cds_nt']) . ' nt' : '')
        . ($transcript['exons'] ? ($transcript['cds_nt'] ? ', ' : '') . (int) $transcript['exons'] . ' exon' . ($transcript['exons'] == 1 ? '' : 's') : '')
        . ($transcript['protein_aa'] ? ', ' . $number($transcript['protein_aa']) . ' aa protein' : '')
        . ($transcript['release'] !== '' ? ' (release ' . $transcript['release'] . ')' : ''));
    } else if ($model['transcript_start'] !== null && $model['transcript_start'] !== '' && $model['transcript_end'] !== null) {
      /* No release for this annotation, so the exon lengths are not on file:
         the genomic span is what the database holds, and it is labelled as
         the span rather than passed off as the transcript's length. */
      $rows .= $fact('Canonical transcript span', $number((int) $model['transcript_end'] - (int) $model['transcript_start'] + 1) . ' bp',
        'genomic span including introns; exon lengths are not in the database for this annotation');
    }
    $count = ($model['transcript_count'] === null || $model['transcript_count'] === '') ? null : (int) $model['transcript_count'];
    $rows .= $fact('Number of transcripts', $count === null ? '' : $esc((string) $count));
    $model_column = '<dl class="v3-facts">' . $rows . '</dl>';

    /* The genome strip. */
    if ($chr_context['name'] !== '' && $chr_context['length'] && $model['gm_start'] !== null && $model['gm_start'] !== '') {
      $len = (int) $chr_context['length'];
      $start = (int) $model['gm_start'];
      $frac = max(0, min(1, $start / $len));
      $mb = function ($bp) { return ($bp >= 1e8 ? number_format($bp / 1e6, 0) : number_format($bp / 1e6, 1)) . ' Mb'; };
      $bars = '';
      if (count($chr_context['karyotype']) >= 2) {
        $max = $len;
        foreach ($chr_context['karyotype'] as $c) { $max = max($max, (int) $c[1]); }
        $bars .= '<ul class="v3-karyotype" aria-label="Chromosomes of this assembly, to scale">';
        foreach ($chr_context['karyotype'] as $c) {
          $here = ($c[0] === $chr_context['name']);
          $h = (int) round(100 * (int) $c[1] / $max);
          $bars .= '<li' . ($here ? ' class="is-here"' : '') . ' title="' . $esc($c[0] . ', ' . number_format((int) $c[1]) . ' bp') . '">'
                 . '<span class="v3-chr-bar" style="height:' . $h . '%">'
                 . ($here ? '<span class="v3-chr-pin" style="top:' . number_format($frac * 100, 1, '.', '') . '%"></span>' : '')
                 . '</span><span class="v3-chr-label">' . $esc(preg_replace('/^chr/i', '', $c[0])) . '</span></li>';
        }
        $bars .= '</ul>';
      }
      /* The coordinates and the span, which used to be a Location fact row
         above. The bin is gone from here: it is a genetic-map coordinate, and
         it is said once, in the Genetic map glyph in the other column. */
      $where = $esc($chr) . ':' . $number($model['gm_start']) . '&ndash;' . $number($model['gm_end'])
             . ' &middot; ' . $number((int) $model['gm_end'] - (int) $model['gm_start'] + 1) . ' bp';

      $genome_strip = '<div class="v3-genome v3-map">'
        . '<h3 class="v3-map-head">Physical map</h3>'
        . '<div class="v3-genome-line">'
        .   '<p class="v3-genome-caption"><strong>' . $esc($chr_context['name']) . '</strong> at <strong>' . $esc($mb($start)) . '</strong> of ' . $esc($mb($len))
        .   ' &middot; ' . $where . '</p>'
        .   '<div class="v3-chrline" role="img" aria-label="' . $esc($chr_context['name'] . ', ' . $mb($len) . ', the gene at ' . $mb($start)) . '">'
        .     '<span class="v3-chrline-pin" style="left:' . number_format($frac * 100, 2, '.', '') . '%"></span></div>'
        .   '<div class="v3-chrline-ends"><span>0</span><span>' . $esc($mb($len)) . '</span></div>'
        . '</div>'
        . $bars
        . '</div>';
    }
    $model_column .= $genome_strip;
  } else if ($withdrawn) {
    $model_column = '<p class="v3-empty">Withdrawn from annotation ' . $esc($gene_identity['annotation']) . '.</p>';
    if (!$h1_placed) { $model_column = '<h1 id="gene-record-title" class="mgdb-record-id">' . $esc($gene_name) . '</h1>' . $model_column; $h1_placed = true; }
  } else {
    $model_column = '<p class="v3-empty">No gene model has been linked to this classical gene in any assembly.</p>';
  }

  /* ---- Synonyms, under both columns --------------------------------------- */
  $synonyms_strip = '';
  if ($locus && count($locus['synonyms'])) {
    $body = '';
    foreach ($locus['synonyms'] as $s) {
      $authority = $s['authority'] !== '' ? $esc($s['authority']) : '<span class="mgdb-muted">&mdash;</span>';
      $reference = $s['reference_id']
        ? '<a href="/data_center/reference?id=' . (int) $s['reference_id'] . '">' . $esc($s['reference_name'] !== '' ? $s['reference_name'] : ('Reference ' . $s['reference_id'])) . '</a>'
        : '<span class="mgdb-muted">&mdash;</span>';
      $body .= '<tr><td>' . $esc($s['name']) . '</td><td>' . $authority . '</td><td>' . $reference . '</td></tr>';
    }
    $synonyms_strip = '<div class="v3-synonyms"><h2 class="v3-synonyms-head">Synonyms<span class="mgdb-rec-block-count">' . count($locus['synonyms']) . '</span></h2>'
      . '<div class="mgdb-table-scroll"><table><thead><tr><th class="v3-syn-name" scope="col">Synonym</th><th class="v3-syn-authority" scope="col">Authority</th><th scope="col">Reference</th></tr></thead>'
      . '<tbody>' . $body . '</tbody></table></div></div>';
  }

  /* ---- Status notice, first in the panel ----------------------------------- */
  $status_notice = '';
  if ($withdrawn) {
    $status_notice = '<div class="mgdb-message mgdb-message-warn" role="note"><div><strong>Withdrawn gene model</strong><span> This model is no longer in its annotation.</span></div></div>';
  } else if ($gene_identity['status'] === 'superseded' && $model && !$model['is_resolved']) {
    $status_notice = '<div class="mgdb-message mgdb-message-warn" role="note"><div><strong>An earlier annotation</strong><span> ' . $esc($gene_name)
      . ' is the ' . $esc($gene_identity['assembly']) . ' annotation of this gene; the model shown is the current one.</span></div></div>';
  }

  /* ---- Publish -------------------------------------------------------------- */
  $bauplan = new Bauplan($gene_title);
  $bauplan->modern();

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
  $v = function ($path) use ($doc_root) {
    return file_exists($doc_root . $path) ? filemtime($doc_root . $path) : time();
  };

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . $v('/css/mgdb-hub.css'));
  $bauplan->includeCss('/css/mgdb-record.css?v=' . $v('/css/mgdb-record.css'));
  $bauplan->includeCss('/css/mgdb-gene-record.css?v=' . $v('/css/mgdb-gene-record.css'));
  $bauplan->includeCss('/css/mgdb-gene-record-v3.css?v=' . $v('/css/mgdb-gene-record-v3.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->head('<meta name="description" content="' . $esc($gene_summary) . '">');
  $bauplan->head('<meta name="robots" content="noindex,follow">');
  $bauplan->head('<link rel="canonical" href="' . $esc($system['root_url']) . '/gene_center/gene/' . $esc(rawurlencode($gene_request)) . '">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $content = $mgdb->get('body')->load('templates/static/mgdb_gene_record_v3.bau');
  $content->get('gene_title')->replace($esc($gene_display));
  $content->get('gene_summary')->replace($esc($gene_summary));
  $content->get('requested_identifier_path')->replace($esc(rawurlencode($gene_request)));
  $content->get('status_notice')->replace($status_notice);
  $content->get('locus_column')->replace($locus_column);
  $content->get('model_column')->replace($model_column);
  $content->get('synonyms_strip')->replace($synonyms_strip);

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);
  $mgdb->get('gbrowse_url')->replace($system['GBROWSE_URL']);

  $bauplan->publish();
  return true;
?>
