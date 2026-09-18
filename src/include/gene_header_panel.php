<?PHP
/* file: gene_header_panel.php
 *
 * purpose: builds the two-sided green gene-record header panel -- the gene and
 *          its genetic map on the left, the gene model and its physical map on
 *          the right -- shared by the live record page /gene_center/gene (built
 *          as the gene_v5 mockup) and the /gene_center/gene_v4 mockup.
 *
 *          This began as the body of gene_record_v4.php. It was lifted out
 *          when v5 was added, because v5's brief is "the v4 header, with a
 *          navigation bar under it": two copies would have meant every later
 *          header change landing twice, and the two drifting the first time it
 *          landed once. The presentation below is v4's, unchanged.
 *
 *          Reads through include/gene_header_lib.php only -- about eight
 *          indexed lookups, no API call and no page script.
 */

include_once('./include/gene_header_lib.php');

/* Builds both halves of the header for one resolved gene.
 *
 *   $gene_identity  as returned by geneIdentity()
 *   $gene_resolved  as returned by geneResolveId()
 *   $link_base      the mockup's own record path, e.g. '/gene_center/gene_v5/'.
 *                   Sibling loci and a withdrawn model's replacement link back
 *                   into the page the reader is already on.
 *
 * Returns an array of ready-to-place HTML and the names the page titles itself
 * with, or false if the database is unreachable.
 */
function geneHeaderPanel($DBConn, $gene_identity, $gene_resolved, $link_base) {

  $esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
  $withdrawn = ($gene_identity['kind'] === 'withdrawn');

  /* The locus, and the model the right column describes. A withdrawn model
     has neither. */
  $locus = $withdrawn ? false : geneHeaderLocus($DBConn, $gene_identity['locus_id']);
  /* true: describe the annotation the reader asked for, not the newest one.
     Someone who arrives on GRMZM2G036297 wants that model's coordinates and
     transcript, with the newer annotations offered beside it -- not silently
     replaced by Zm00001eb067740. */
  $model = $withdrawn ? false : geneHeaderCurrentModel($DBConn, $gene_identity['locus_id'], $gene_resolved['row'], true);

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
  /* "(withdrawn)" in the title, as the previous record page had it: the
     browser tab and a search result are where a reader first sees the page,
     and a withdrawn model should not look like a current one there. */
  $gene_title = 'MaizeGDB Gene: ' . $gene_display
              . (($gene_display !== $gene_name && $gene_name !== '') ? ' (' . $gene_name . ')' : '')
              . ($withdrawn ? ' (withdrawn)' : '');
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

  /* ---- Left half: the gene, and its genetic map ------------------------- */
  $locus_side = '';
  if ($locus) {
    $rows = '';
    /* No Classical gene pair. geneHeaderLocus() still reads the flag and its
       Classical Gene List key -- other pages use them -- but the header does
       not show them. */
    $rows .= $fact('Species', $locus['species'] !== '' ? '<em>' . $esc($locus['species']) . '</em>' : '');
    $rows .= $fact('Plant-wide name', $locus['plant_wide_name'] !== '' ? $esc($locus['plant_wide_name']) : '');
    if ($locus['ncbi_gene'] !== '') {
      $rows .= $fact('NCBI Gene',
        '<a href="' . $esc($locus['ncbi_url']) . '" target="_blank" rel="noopener">' . $esc($locus['ncbi_gene']) . '</a>',
        $locus['ncbi_comment']);
    }

    /* The name block: the gene's symbol at h1 size and its full name under
       it, then -- where there is one -- the note that this model stands for
       other genes too, then every synonym on one line. */
    $locus_side .= '<span class="v4-badge">Gene/locus</span>'
      . '<h1 id="gene-record-title" class="v4-name">' . $esc($locus['name']) . '</h1>';
    if ($locus['full_name'] !== '' && strcasecmp($locus['full_name'], $locus['name']) !== 0) {
      $locus_side .= '<p class="mgdb-rec-subtitle v4-fullname">' . $esc($locus['full_name']) . '</p>';
    }

    /* The other loci on this gene model.

       Directly under the name, above Species. This qualifies which gene the
       whole left half is describing, so it belongs with the identity and not
       after it: down among the facts, below three lines of synonyms, it was
       the fifth thing on the side and read as a footnote to the panel rather
       than a caveat on its subject.

       A <details> rather than a script: these mockups load no JS of their own,
       and a disclosure works with scripting off and is keyboard-operable for
       free. Closed by default -- one locus is the headline, the others are
       behind the count in the summary, so the reader knows what is there
       without opening it.

       Named rather than switched. The tempting design is a control that swaps
       which locus the left half describes, but on the case that motivated this
       -- Zm00001eb334630, six loci -- all six sit within 0.3 cM on the same
       linkage group, so the map would not visibly move and the switch would
       cost a page state for nothing. What actually differs is identity, so the
       identity is what is listed. */
    $siblings = geneHeaderSiblingLoci($DBConn, $model_name, $locus['id']);
    if (count($siblings)) {
      $items = '';
      foreach ($siblings as $sib) {
        $items .= '<li><a href="' . $link_base . rawurlencode($sib['name']) . '">' . $esc($sib['name']) . '</a>'
                . ($sib['full_name'] !== '' && strcasecmp($sib['full_name'], $sib['name']) !== 0
                   ? ' <span class="v4-loci-full">' . $esc($sib['full_name']) . '</span>' : '')
                . ($sib['ncbi_gene'] !== '' ? ' <span class="v4-loci-ncbi">NCBI ' . $esc($sib['ncbi_gene']) . '</span>' : '')
                . '</li>';
      }
      $n = count($siblings);
      $locus_side .= '<details class="v4-loci">'
        . '<summary>' . $n . ' more gene ' . ($n === 1 ? 'locus is' : 'loci are') . ' linked to this gene model</summary>'
        . '<p class="v4-loci-note">' . $esc($model_name) . ' represents ' . ($n + 1) . ' classical loci. '
        . 'They are shown here one at a time; ' . $esc($locus['name']) . ' is the one this page leads with.</p>'
        . '<ul>' . $items . '</ul>'
        . '</details>';
    }

    if (count($locus['synonyms'])) {
      /* Plain names, not bold. Fifteen bolded synonyms in a row out-weighed
         every real value on the panel.

         No .mgdb-rec-synonyms here either: that class is what pulled the line
         into Verdana, visibly a different face from the Species and NCBI Gene
         pairs beside it. This line is styled from the fact list instead, so the
         three read as one block. The separator keeps .mgdb-muted, which is the
         colour that made the dots visible. */
      $aka = array();
      foreach ($locus['synonyms'] as $s_row) { $aka[] = $esc($s_row['name']); }

      /* Where each name came from.

         Every synonym is credited to an authority (a person, or a data source
         held as one -- "NCBI", "Canonical Name", "Grassius") or to the paper
         that used it, never to both, and often to neither. The old record page
         printed "name (per Authority)" inline, which at ptk5's nine names is
         another three lines of header for a fact almost nobody is after.

         So: the compact line stays the default, and a disclosure swaps it for
         the credited list. A swap rather than an addition -- the stylesheet
         hides the line while the list is open -- because the names would
         otherwise be on screen twice. <details> rather than a control, for the
         same reasons as the sibling-loci list above: no page script, works
         with scripting off, keyboard-operable for free.

         Nothing to disclose when no name has a source, which is the whole list
         for a good many loci. */
      $sourced = 0;
      foreach ($locus['synonyms'] as $s_row) {
        if ($s_row['authority'] !== '' || $s_row['reference_id'] !== null) { $sourced++; }
      }

      $aka_block = '<p class="v4-aka"><span class="v4-aka-label">Also known as</span> '
        . implode(' <span class="mgdb-muted" aria-hidden="true">&middot;</span> ', $aka) . '</p>';

      if ($sourced > 0) {
        $items = '';
        foreach ($locus['synonyms'] as $s_row) {
          if ($s_row['reference_id'] !== null) {
            /* The stored citation is the whole paper. Author and year is what
               says which paper; the rest is kept on the link's title so it is
               one hover away rather than four lines of list. */
            $short = geneHeaderCitationShort($s_row['reference_name']);
            $label = ($short !== '') ? $short : $s_row['reference_name'];
            $source = 'per <a href="/data_center/reference?id=' . (int) $s_row['reference_id'] . '"'
                    . ' title="' . $esc($s_row['reference_name']) . '">' . $esc($label) . '</a>';
          } else if ($s_row['authority'] !== '' && $s_row['authority_id'] !== null) {
            $source = 'per <a href="/person?id=' . (int) $s_row['authority_id'] . '">'
                    . $esc($s_row['authority']) . '</a>';
          } else if ($s_row['authority'] !== '') {
            $source = 'per ' . $esc($s_row['authority']);
          } else {
            $source = '<span class="v4-aka-nosource">no source recorded</span>';
          }
          $items .= '<li><span class="v4-aka-name">' . $esc($s_row['name']) . '</span>'
                  . '<span class="v4-aka-per">' . $source . '</span></li>';
        }
        $aka_block .= '<details class="v4-aka-src">'
          . '<summary>Where ' . (count($locus['synonyms']) === 1 ? 'this name comes' : 'these ' . count($locus['synonyms']) . ' names come') . ' from</summary>'
          . '<ul class="v4-aka-list">' . $items . '</ul>'
          . '</details>';
      }

      $locus_side .= '<div class="v4-aka-block">' . $aka_block . '</div>';
    }
    if ($rows !== '') {
      $locus_side .= '<dl class="mgdb-record-facts mgdb-rec-headfacts v4-facts">' . $rows . '</dl>';
    }


    /* The genetic map. Its caption carries what used to be a Genetic data
       row: linkage group, arm, cM, the map the number is on, and the bin. */
    $cm_context = ($locus['cm'] !== null && $locus['cm_map'] !== '')
      ? geneGeneticMapContext($DBConn, $locus['cm_map'])
      : array('name' => '', 'length' => null, 'start' => 0.0, 'maps' => array());
    if ($cm_context['name'] !== '' && $cm_context['length'] > 0) {
      $cm_val = (float) $locus['cm'];
      $cm_lo = (float) $cm_context['start'];
      $cm_hi = (float) $cm_context['length'];
      $span = ($cm_hi - $cm_lo) > 0 ? ($cm_hi - $cm_lo) : 1;
      $cm_frac = max(0, min(1, ($cm_val - $cm_lo) / $span));
      $cm = function ($v) { return rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.') . ' cM'; };
      $arm_text = '';
      if ($locus['arm'] !== '') {
        $arm_text = ($locus['arm'] === 'S' ? 'short arm' : ($locus['arm'] === 'L' ? 'long arm' : $locus['arm']));
      }
      $detail = array();
      if ($locus['linkage_group'] !== '') {
        $detail[] = 'linkage group ' . $esc($locus['linkage_group']) . ($arm_text !== '' ? ' ' . $esc($arm_text) : '');
      }
      if ($locus['bin'] !== '') { $detail[] = 'bin ' . $esc($locus['bin']); }

      $cm_bars = '';
      if (count($cm_context['maps']) >= 2) {
        $max = 0;
        foreach ($cm_context['maps'] as $m) { $max = max($max, (float) $m[1]); }
        $cm_bars .= '<ul class="v4-bars" aria-label="Linkage groups of the genetic map, to scale in centiMorgans">';
        foreach ($cm_context['maps'] as $m) {
          $here = ($m[0] === $cm_context['name']);
          $h = $max > 0 ? (int) round(100 * (float) $m[1] / $max) : 0;
          $cm_bars .= '<li' . ($here ? ' class="is-here"' : '') . ' title="' . $esc($m[0] . ', ' . $cm($m[1])) . '">'
                   . '<span class="v4-bar" style="height:' . $h . '%">'
                   . ($here ? '<span class="v4-bar-pin" style="top:' . number_format($cm_frac * 100, 1, '.', '') . '%"></span>' : '')
                   . '</span><span class="v4-bar-label">' . $esc(preg_replace('/^Genetic /', '', $m[0])) . '</span></li>';
        }
        $cm_bars .= '</ul>';
      }

      $locus_side .= '<div class="v4-map">'
        . '<h2 class="v4-map-head">Genetic map</h2>'
        /* The caption is a child of the glyph, not of .v4-map-line, so it can
           span the whole section rather than the ~68% track the line sits in --
           at that width it wrapped onto a second row. */
        . '<p class="v4-map-caption"><strong>' . $esc($cm_context['name']) . '</strong> at <strong>' . $esc($cm($cm_val)) . '</strong> of ' . $esc($cm($cm_hi))
        .   (count($detail) ? ' &middot; ' . implode(' &middot; ', $detail) : '') . '</p>'
        . '<div class="v4-map-line">'
        .   '<div class="v4-line" role="img" aria-label="' . $esc($cm_context['name'] . ', ' . $cm($cm_hi) . ', the gene at ' . $cm($cm_val)) . '">'
        .     '<span class="v4-line-pin" style="left:' . number_format($cm_frac * 100, 2, '.', '') . '%"></span></div>'
        .   '<div class="v4-line-ends"><span>' . $esc($cm($cm_lo)) . '</span><span>' . $esc($cm($cm_hi)) . '</span></div>'
        . '</div>'
        . $cm_bars
        . '</div>';
    }
  } else if ($withdrawn) {
    $locus_side = '<h1 id="gene-record-title" class="v4-name">' . $esc($gene_name) . '</h1>'
      . '<p class="v4-empty">This gene model was withdrawn from the annotation'
      . ($gene_identity['replacement'] !== '' ? ' and replaced by <a href="' . $link_base . rawurlencode($gene_identity['replacement']) . '">' . $esc($gene_identity['replacement']) . '</a>' : '')
      . '.</p>';
  } else {
    /* A gene model with no gene or locus behind it. Not an anomaly: 21,662 of
       the 44,497 current B73 v5 models are in this state, 48.7%.

       So it is an empty state and not a warning -- centred in the half, in the
       muted colour, with the corner badge kept so it is obvious WHICH half is
       empty. A pale red or wine box here would be the loudest thing on half
       the gene pages on the site, and on the records that also carry the
       sibling-loci callout the two would compete.

       No <h1> here either. This used to print the gene model's name on the
       left as the page heading, which put the same identifier at the same size
       on both sides of the divider and made one record look like two. The
       heading moves to the model name on the right, where the identity
       actually is. */
    $locus_side = '<span class="v4-badge">Gene/locus</span>'
      . '<div class="v4-none"><p>No gene or locus is linked to this gene model.</p></div>';
  }

  /* ---- Right half: the gene model, and its physical map ------------------ */
  $model_side = '';
  if ($model) {
    /* The model name is set at the same size as the gene name on the left --
       the two halves are peers, and a heading on one side with a small
       identifier on the other would not read that way. It is deliberately not
       a link: this is a header mockup, and every other identifier on it is
       plain text too. */
    /* The page's one <h1> sits on whichever half carries the record's
       identity: the gene on the left when there is one, the model on the right
       when there is not. Everywhere else this name is a <p>, unlinked, at the
       same size as the gene name opposite. */
    $model_side .= '<span class="v4-badge">Gene model</span>'
      . ($locus
         ? '<p class="v4-name v4-name-model">' . $esc($model_name) . '</p>'
         : '<h1 id="gene-record-title" class="v4-name v4-name-model">' . $esc($model_name) . '</h1>');
    if ($model_assembly !== '') {
      $model_side .= '<p class="mgdb-rec-subtitle v4-fullname">' . $esc($model_assembly)
        . (trim((string) $model['version']) !== '' ? ', annotation ' . $esc(trim((string) $model['version'])) : '') . '</p>';
    }
    if (!$model['is_resolved'] && $gene_name !== '' && strcasecmp($gene_name, $model_name) !== 0) {
      /* Only reachable now when the identifier named something that is not one
         of this locus's models at all. When it named one, that one is what the
         column describes and there is nothing to apologise for. */
      $model_side .= '<p class="v4-aka">You asked for <strong>' . $esc($gene_name) . '</strong>, the '
        . $esc($gene_identity['assembly']) . ' annotation of this gene.</p>';
    }

    /* The other annotations of this gene.

       The same callout as the sibling loci opposite, in the same place --
       directly under the name, above the facts -- because it qualifies the
       same thing on this side that they qualify on that one: which of several
       records the column is describing.

       Wording says "linked to this gene" rather than naming B73. These are not
       all B73: this locus carries Zm-CML333-REFERENCE-NAM-1.0 alongside the
       four B73 annotations, and the Assembly column is where that is said. */
    $others = array();
    foreach ((array) $model['annotations'] as $anno) {
      if (!$anno['is_shown']) { $others[] = $anno; }
    }
    if (count($others)) {
      $reference = false;
      foreach ((array) $model['annotations'] as $anno) {
        if ($anno['is_reference'] || ($anno['is_current'] && $reference === false)) {
          if ($anno['is_reference']) { $reference = $anno; break; }
          if ($reference === false) { $reference = $anno; }
        }
      }

      $rows_html = '';
      foreach ($others as $anno) {
        $mark = $anno['is_reference'] ? '<span class="v4-anno-mark">current</span>' : '';
        $rows_html .= '<tr><td><a href="' . $link_base . rawurlencode($anno['name']) . '">' . $esc($anno['name']) . '</a>' . $mark . '</td>'
                   . '<td>' . $esc(implode(', ', $anno['assemblies'])) . '</td></tr>';
      }

      /* The one thing a reader who arrived on a retired identifier actually
         needs: which annotation is the current one. Said only when they are
         not already looking at it. */
      $note = '';
      if ($reference !== false && !$reference['is_shown']) {
        $note = '<p class="v4-anno-note"><strong>' . $esc($reference['name']) . '</strong> is the current annotation'
              . (count($reference['assemblies']) ? ', in ' . $esc($reference['assemblies'][0]) : '') . '.</p>';
      }

      $n = count($others);
      $model_side .= '<details class="v4-anno">'
        . '<summary>' . $n . ' other gene model annotation' . ($n === 1 ? ' is' : 's are') . ' linked to this gene</summary>'
        . $note
        . '<table class="v4-anno-table"><thead><tr><th scope="col">Gene model</th><th scope="col">Assembly</th></tr></thead>'
        . '<tbody>' . $rows_html . '</tbody></table>'
        . '</details>';
    }

    $rows = '';
    $rows .= $fact('Model type', trim((string) $model['model_type']) !== '' ? $esc(str_replace('_', ' ', trim((string) $model['model_type']))) : '');
    $rows .= $fact('Canonical transcript', $model_canonical !== '' ? $esc($model_canonical) : '');
    /* The transcript count is emitted here, before the measurements, so the
       wrapping flex row packs it onto the Canonical transcript line -- 349px
       used of 549 leaves room for it. Emitted after the measurements it had
       nowhere to go but a line of its own, because that pair takes the full
       row. */
    $count = ($model['transcript_count'] === null || $model['transcript_count'] === '') ? null : (int) $model['transcript_count'];
    $rows .= $fact('Transcripts', $count === null ? '' : $esc((string) $count));
    if ($transcript && $transcript['mrna_nt'] > 0) {
      /* All four measurements on one line, each labelled in parentheses,
         rather than a value with a <small> note under it. The note made this
         pair two lines tall, which pushed the transcript count beside a blank
         second line instead of beside the measurements. */
      $measures = array($number($transcript['mrna_nt']) . ' nt (mRNA)');
      if ($transcript['cds_nt']) { $measures[] = $number($transcript['cds_nt']) . ' nt (CDS)'; }
      if ($transcript['protein_aa']) { $measures[] = $number($transcript['protein_aa']) . ' aa (protein)'; }
      if ($transcript['exons']) { $measures[] = (int) $transcript['exons'] . ' exon' . ($transcript['exons'] == 1 ? '' : 's'); }
      /* "Transcript", not "Transcript length": the value now carries the
         protein size and the exon count as well as the two lengths, so the
         longer label was no longer accurate -- and it cost 40px that the one
         line needs. With it the measurements set in 456px of a 440px track and
         wrapped; without it they fit. */
      $rows .= $fact('Transcript', implode(', ', $measures));
    } else if ($model['transcript_start'] !== null && $model['transcript_start'] !== '' && $model['transcript_end'] !== null) {
      $rows .= $fact('Transcript span', $number((int) $model['transcript_end'] - (int) $model['transcript_start'] + 1) . ' bp',
        'genomic span including introns; exon lengths are not in the database for this annotation');
    }
    if ($rows !== '') {
      $model_side .= '<dl class="mgdb-record-facts mgdb-rec-headfacts v4-facts">' . $rows . '</dl>';
    }

    /* The physical map: the same drawing as the genetic map opposite, in
       megabases. Its caption carries the coordinates and the span, which is
       where the Location row went. No bin here -- a bin is a genetic-map
       coordinate and it is said once, on the left. */
    $chr = trim((string) $model['chr']);
    if ($chr_context['name'] !== '' && $chr_context['length'] && $model['gm_start'] !== null && $model['gm_start'] !== '') {
      $len = (int) $chr_context['length'];
      $start = (int) $model['gm_start'];
      $frac = max(0, min(1, $start / $len));
      $mb = function ($bp) { return ($bp >= 1e8 ? number_format($bp / 1e6, 0) : number_format($bp / 1e6, 1)) . ' Mb'; };
      /* No chromosome prefix on the coordinates: the caption opens with the
         chromosome, so "chr1 at 281 Mb of 308 Mb · chr1:280,590,357-..." said
         it twice. The bare range reads as the same chromosome. */
      $where = $number($model['gm_start']) . '&ndash;' . $number($model['gm_end'])
             . ' &middot; ' . $number((int) $model['gm_end'] - (int) $model['gm_start'] + 1) . ' bp';

      $bars = '';
      if (count($chr_context['karyotype']) >= 2) {
        $max = $len;
        foreach ($chr_context['karyotype'] as $c) { $max = max($max, (int) $c[1]); }
        $bars .= '<ul class="v4-bars" aria-label="Chromosomes of this assembly, to scale">';
        foreach ($chr_context['karyotype'] as $c) {
          $here = ($c[0] === $chr_context['name']);
          $h = (int) round(100 * (int) $c[1] / $max);
          $bars .= '<li' . ($here ? ' class="is-here"' : '') . ' title="' . $esc($c[0] . ', ' . number_format((int) $c[1]) . ' bp') . '">'
                 . '<span class="v4-bar" style="height:' . $h . '%">'
                 . ($here ? '<span class="v4-bar-pin" style="top:' . number_format($frac * 100, 1, '.', '') . '%"></span>' : '')
                 . '</span><span class="v4-bar-label">' . $esc(preg_replace('/^chr/i', '', $c[0])) . '</span></li>';
        }
        $bars .= '</ul>';
      }

      $model_side .= '<div class="v4-map">'
        . '<h2 class="v4-map-head">Physical map</h2>'
        . '<p class="v4-map-caption"><strong>' . $esc($chr_context['name']) . '</strong> at <strong>' . $esc($mb($start)) . '</strong> of ' . $esc($mb($len))
        .   ' &middot; ' . $where . '</p>'
        . '<div class="v4-map-line">'
        .   '<div class="v4-line" role="img" aria-label="' . $esc($chr_context['name'] . ', ' . $mb($len) . ', the gene at ' . $mb($start)) . '">'
        .     '<span class="v4-line-pin" style="left:' . number_format($frac * 100, 2, '.', '') . '%"></span></div>'
        .   '<div class="v4-line-ends"><span>0</span><span>' . $esc($mb($len)) . '</span></div>'
        . '</div>'
        . $bars
        . '</div>';
    }
  } else if ($withdrawn) {
    $model_side = '<p class="v4-name v4-name-model">' . $esc($gene_name) . '</p>'
      . '<p class="v4-empty">Withdrawn from annotation ' . $esc($gene_identity['annotation']) . '.</p>';
  } else {
    /* A gene with no model in any assembly -- 2,596 of the 26,115 curated loci
       of type Gene, about 10%. Uncommon, but common enough that it is a fact
       about the record rather than a fault, so it gets the same quiet centred
       empty state as the other side. The badge is what was missing before: a
       lone sentence in half a green panel did not say which half it was. */
    $model_side = '<span class="v4-badge">Gene model</span>'
      . '<div class="v4-none"><p>No gene model has been linked to this gene or locus in any assembly.</p></div>';
  }

  /* ---- Status notice ------------------------------------------------------- */
  $status_notice = '';
  if ($withdrawn) {
    $status_notice = '<div class="mgdb-message mgdb-message-warn" role="note"><div><strong>Withdrawn gene model</strong><span> This model is no longer in its annotation.</span></div></div>';
  } else if ($gene_identity['status'] === 'superseded' && $model && !$model['is_resolved']) {
    $status_notice = '<div class="mgdb-message mgdb-message-warn" role="note"><div><strong>An earlier annotation</strong><span> ' . $esc($gene_name)
      . ' is the ' . $esc($gene_identity['assembly']) . ' annotation of this gene; the model shown is the current one.</span></div></div>';
  }

  return array(
    'locus_side' => $locus_side,
    'model_side' => $model_side,
    'status_notice' => $status_notice,
    'gene_display' => $gene_display,
    'gene_name' => $gene_name,
    'gene_title' => $gene_title,
    'gene_summary' => $gene_summary,
    'model_name' => $model_name,
    'locus' => $locus,
    'model' => $model
  );
}
?>
