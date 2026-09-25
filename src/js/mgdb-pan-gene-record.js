/* ==========================================================================
   Pan-gene record page — /pan_gene_center/pan_gene/{id}
   --------------------------------------------------------------------------
   Glue over js/mgdb-record.js, the same engine the gene product, variation,
   map, marker and phenotype record pages use. This file maps one call to
   /api/v1/records/pan_gene/{id} onto it.

   The figures -- presence strip, domain ribbons, tree, alignment -- are the
   record's own, in js/mgdb-pan-gene-figures.js, linked through
   MGDB.panGeneSelection. What is still the legacy page's is the GCV, in an
   iframe, and the sequence downloads in js/pan_gene.js. The IcyTree and
   BioJS MSAViewer libraries were retired from this page on 2026-09-17.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  var R = window.MGDBRecord;
  if (!MGDB || !R) { return; }

  var els = {};
  var payload = null;
  var presenceStrip = null;   /* handle from MGDB.panGenePresence, for linked selection */

  function num(value) { return value === null || value === undefined ? '' : String(value); }

  /* A gene model reaches its own record page only when MaizeGDB holds one; two
     thirds of the members of a large pan-gene are from annotations that have
     no gene pages, and those are named without a link. */
  function memberLink(name, html) {
    return html ? R.link(html, name) : R.escape(name);
  }

  /* ------------------------------------------------------------------------
     Header
     ------------------------------------------------------------------------ */

  function renderHeader(data, requested) {
    var attributes = data.attributes || {};
    var parts = [];
    /* "Reached from" is for an identifier the page is NOT already showing --
       a member gene model, a locus, an accession. The heading is now the
       exemplar gene model, so a reader who arrived by the exemplar in either
       of its forms is told nothing by being shown it again beside
       "represented by the exemplar", which says the same string twice. */
    var showsAlready = requested === attributes.pan_gene_name ||
                       requested === attributes.exemplar ||
                       requested === attributes.exemplar_gene_model;
    if (requested && !showsAlready) {
      parts.push('Reached from <strong>' + R.escape(requested) + '</strong>');
    }
    /* The exemplar is no longer repeated here. It used to be the only place
       the record named an identifier a reader could use, because the heading
       was the internal pan-gene id; now the heading IS the exemplar and the
       Exemplar fact below gives its transcript form, so saying it a third
       time filled the hero with one string written three ways. What is left
       is the one thing neither of those carries: which identifier the reader
       actually arrived by. */
    if (!parts.length) { R.show(els.synonyms, false); return; }
    els.synonyms.innerHTML = parts.join(', ') + '.';
    R.show(els.synonyms, true);
  }

  /* ------------------------------------------------------------------------
     Overview
     ------------------------------------------------------------------------ */

  /* The legacy alert's caveat, less its first sentence -- the alert's summary
     line now says that, and names what was found. */
  var ALERT_TEXT = 'Pan-gene analyses continue to evolve and improve, but remain subject to gene ' +
    'model annotation quality and the complexities of genome structure. For example, split, ' +
    'merged, or otherwise misannotated gene models may be incorrectly included or excluded. Note ' +
    'also that the MaizeGDB pan-gene analysis is optimized to limit false negatives rather than ' +
    'false positives.';

  var OVERLAP_TEXT = 'Some members of this pan-gene overlap other gene models. In a group of ' +
    'overlapping gene models, often the longest gene model is selected for membership because ' +
    'it has the highest similarity score, even if a different overlapping gene model may be a ' +
    'closer match.';

  /* The members table follows whatever figure published the selection -- a
     presence cell, a tree tip, a clade. The table sits directly beneath the
     strip on purpose, so the answer appears where the reader already is.

     This deliberately does not scroll. `scrollIntoView` with `block: 'nearest'`
     looks harmless but still moved the page 771 px here, because the members
     block is taller than the viewport and `nearest` then aligns an edge of it
     -- which is the jump the adjacency was meant to remove. */
  function bindMembersToSelection() {
    if (!MGDB.panGeneSelection) { return; }
    MGDB.panGeneSelection.subscribe(function (sel) {
      var container = R.byId('pg-overview-members');
      var filter = container ? container.querySelector('[data-role="filter"]') : null;
      if (!filter) { return; }
      /* A selection with no filter hint -- a whole clade -- would need the
         table to match a set rather than a string, which its filter cannot
         do, so the table is left alone and the figures carry the highlight. */
      if (sel && !sel.filter) { return; }
      var query = sel ? sel.filter : '';
      if (filter.value === query) { return; }
      filter.value = query;
      filter.dispatchEvent(new Event('input', { bubbles: true }));
      MGDB.announce(query ? ('Members table filtered to ' + query + '.') : 'Members table filter cleared.');
    });
  }

  /* ------------------------------------------------------------------------
     The pan-gene alert

     One disclosure at the top of the Overview, closed, whose summary says
     what was found in the words the reader needs -- "There may be some
     errors in this pan-gene, including overlapping gene models and
     discrepancies with hand-curated data" -- naming only what this record
     has. About one pan-gene in five raises one (12,409 have overlapping
     members, 9,737 have a curated gene model left out), so it has to be
     visible without shouting: the site's warning colours, an orange spine,
     a count per kind. Opening it gives the evidence for each kind, and the
     curators' alignment is drawn only then.

     A complex locus -- an NLR cluster like rp1 -- raises it for good
     reasons, and the pan-gene can still be meaningful; the caveat says so.
     ------------------------------------------------------------------------ */

  function listPhrase(items) {
    if (items.length < 2) { return items.join(''); }
    if (items.length === 2) { return items[0] + ' and ' + items[1]; }
    return items.slice(0, -1).join(', ') + ', and ' + items[items.length - 1];
  }

  function renderAlert(overview, out) {
    var a = overview.alerts || {};
    var overlaps = a.overlaps || [];
    var mismatches = a.chromosome_mismatches || [];
    var curated = a.hand_curated || [];
    var kinds = [], chips = [];
    var missing = curated.reduce(function (n, g) { return n + g.missing; }, 0);
    if (overlaps.length) {
      kinds.push('overlapping gene models');
      chips.push(R.number(overlaps.length) + ' overlapping gene model' + (overlaps.length === 1 ? '' : 's'));
    }
    if (curated.length) {
      kinds.push('discrepancies with hand-curated data');
      var loci = {};
      curated.forEach(function (g) { loci[g.locus] = true; });
      var nl = Object.keys(loci).length;
      chips.push(R.number(missing) + ' curated gene model' + (missing === 1 ? '' : 's') + ' left out, ' +
                 nl + (nl === 1 ? ' locus' : ' loci'));
    }
    if (mismatches.length) {
      kinds.push(mismatches.length === 1 ? 'a locus on another chromosome' : 'loci on another chromosome');
      chips.push(mismatches.length + (mismatches.length === 1 ? ' locus' : ' loci') + ' on another chromosome');
    }
    if (!kinds.length) { return false; }

    var det = document.createElement('details');
    det.className = 'mgdb-pg-alert';
    det.id = 'pg-record-alert';
    det.innerHTML =
      '<summary>' +
        '<svg class="mgdb-pg-alert-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 21h20L12 3z"/><path d="M12 10v5M12 18v.5"/></svg>' +
        '<span class="mgdb-pg-alert-text"><strong>Pan-gene alert</strong> ' +
          'There may be some errors in this pan-gene, including ' + R.escape(listPhrase(kinds)) + '.' +
          '<span class="mgdb-pg-alert-counts">' + chips.map(function (c) { return '<span>' + R.escape(c) + '</span>'; }).join('') + '</span>' +
        '</span>' +
        '<span class="mgdb-pg-alert-toggle" aria-hidden="true"></span>' +
      '</summary>' +
      '<div class="mgdb-pg-alert-body"><p class="mgdb-pg-alert-caveat">' + R.escape(ALERT_TEXT) + '</p></div>';
    out.appendChild(det);
    var body = det.querySelector('.mgdb-pg-alert-body');

    /* A table's own title is the part's heading; the explanation goes
       directly under it. */
    function explain(host, html) {
      var blocks = host.querySelectorAll('.mgdb-rec-block-head');
      var head = blocks[blocks.length - 1];
      if (head) { head.insertAdjacentHTML('afterend', '<p class="mgdb-pg-alert-lead">' + html + '</p>'); }
    }
    function part(title, html) {
      var el = document.createElement('div');
      el.className = 'mgdb-pg-alert-part mgdb-rec-block';
      el.innerHTML = '<div class="mgdb-rec-block-head"><h3>' + R.escape(title) + '</h3></div>' +
        (html ? '<p class="mgdb-pg-alert-lead">' + html + '</p>' : '');
      body.appendChild(el);
      return el;
    }

    if (curated.length) {
      var hc = document.createElement('div');
      hc.className = 'mgdb-pg-alert-part';
      body.appendChild(hc);
      var curators = {};
      curated.forEach(function (g) { curators[g.curator] = true; });
      var hcLead = (curators.MaizeGDB && curators.Grassius ? 'MaizeGDB curators and Grassius' : (curators.Grassius ? 'Grassius' : 'MaizeGDB curators')) +
        ' associated these gene models with the loci of this pan-gene. ' + R.number(missing) + ' of them ' +
        (missing === 1 ? 'is not a member' : 'are not members') + ' of it; the Membership column says where each one is instead.' +
        (a.hand_curated_region ? ' <a href="' + R.escape(a.hand_curated_region.browser_url) + '" target="_blank" rel="noopener">' +
          'View the B73 v5 gene models in the genome browser</a> (' + R.escape(a.hand_curated_region.chr) + ', ' +
          (a.hand_curated_region.start / 1e6).toFixed(2) + '\u2013' + (a.hand_curated_region.end / 1e6).toFixed(2) + ' Mb).' : '');
      var rows = [];
      curated.forEach(function (g) {
        g.gene_models.forEach(function (m) {
          rows.push({ locus: g.locus, curator: g.curator, gene_model: m.gene_model, html: m.html, comment: m.comment,
                      member: m.member, elsewhere: m.elsewhere || [] });
        });
      });
      /* Left-out models first within each locus, so page one of the table is
         the problem rather than the part that worked. */
      rows.sort(function (x, y) {
        return x.locus.localeCompare(y.locus) || (x.member === y.member ? 0 : (x.member ? 1 : -1)) || x.gene_model.localeCompare(y.gene_model);
      });
      function status(r) {
        if (r.member) { return 'In this pan-gene'; }
        if (!r.elsewhere.length) { return 'Not in any pan-gene'; }
        return 'In another pan-gene';
      }
      R.collection(hc, {
        title: 'Discrepancies with hand-curated gene models',
        items: rows,
        filename: 'pan-gene-curation-discrepancies.tsv',
        columns: [
          { key: 'locus', label: 'Locus', tile: true, html: function (r) { return R.link('/gene_center/gene/' + encodeURIComponent(r.locus), r.locus); } },
          { key: 'gene_model', label: 'Gene model', html: function (r) { return R.link(r.html, r.gene_model); } },
          { key: 'status', label: 'Membership', get: status, html: function (r) {
              if (r.member) { return '<span class="mgdb-pill mgdb-pill-ok">In this pan-gene</span>'; }
              if (!r.elsewhere.length) { return '<span class="mgdb-pill mgdb-pill-warn">Not in any pan-gene</span>'; }
              return '<span class="mgdb-pill mgdb-pill-warn">In another pan-gene</span> ' + r.elsewhere.map(function (e) {
                return R.link(e.html, e.exemplar.replace(/_T\d+$/, ''));
              }).join(', ');
            } },
          { key: 'curator', label: 'Curated by', get: function (r) { return r.curator === 'MaizeGDB' ? 'MaizeGDB' : r.curator; } },
          { key: 'comment', label: 'Curator note', get: function (r) { return r.comment || ''; } }
        ]
      });
      explain(hc, hcLead);

      var candidates = a.hand_curated_alignments || [];
      if (candidates.length && MGDB.panGeneMsa) {
        var alnPart = part('Alignment of the hand-curated gene models', '');
        var alnLead = alnPart.querySelector('.mgdb-pg-alert-lead') || document.createElement('p');
        alnLead.className = 'mgdb-pg-alert-lead';
        alnPart.appendChild(alnLead);
        var alnPick = document.createElement('div');
        alnPick.className = 'mgdb-pg-alert-pick';
        alnPart.appendChild(alnPick);
        var alnHost = document.createElement('div');
        alnPart.appendChild(alnHost);
        var alnStarted = false;

        /* One file per curated locus, hand_curated/<locus>.fa, holding the
           gene models curated for it and the exemplars of the pan-genes they
           fell into. Not every locus has one, so which exist is asked when
           the alert is first opened: a closed disclosure has no width to
           draw into, and most readers never open it. */
        var drawAlignment = function (c) {
          alnLead.innerHTML = 'The CDS alignment MaizeGDB built for ' + R.escape(c.locus) + ': the gene models curated ' +
            'for it, with the exemplars of the pan-genes they belong to.';
          alnHost.innerHTML = '';
          /* These rows are the curators' gene models and other pan-genes'
             exemplars, not this pan-gene's members, so the member lookup the
             main alignment uses would call every one of them "named
             differently". Name them by gene model instead. */
          MGDB.panGeneMsa(alnHost, {
            cdsUrl: c.url, panGene: overview.pan_gene_name,
            resolve: function (name) { return { gene: String(name).replace(/_T\d+$/, '') }; }
          });
        };
        det.addEventListener('toggle', function () {
          if (!det.open || alnStarted) { return; }
          alnStarted = true;
          alnLead.textContent = 'Looking for the curators’ alignments…';
          Promise.all(candidates.map(function (c) {
            return fetch(c.url, { method: 'HEAD', credentials: 'omit' })
              .then(function (r) { return r.ok ? c : null; })
              .catch(function () { return null; });
          })).then(function (found) {
            found = found.filter(Boolean);
            if (!found.length) { alnPart.parentNode.removeChild(alnPart); return; }
            if (found.length > 1) {
              alnPick.innerHTML = '<label>Locus <select aria-label="Alignment for which locus">' + found.map(function (c, i) {
                return '<option value="' + i + '">' + R.escape(c.locus) + '</option>';
              }).join('') + '</select></label> <span>' + found.length + ' of the ' + candidates.length +
                ' loci with a discrepancy have one.</span>';
              alnPick.querySelector('select').addEventListener('change', function (e) {
                drawAlignment(found[Number(e.target.value)]);
              });
            }
            drawAlignment(found[0]);
          });
        });
      }
    }

    if (overlaps.length) {
      var ov = document.createElement('div');
      ov.className = 'mgdb-pg-alert-part';
      body.appendChild(ov);
      R.collection(ov, {
        title: 'Overlapping gene models',
        items: overlaps,
        filename: 'pan-gene-overlaps.tsv',
        columns: [
          { key: 'gene_model', label: 'Gene model', tile: true,
            html: function (o) { return R.link(o.browser_url, o.gene_model, true); } },
          { key: 'overlaps', label: 'Overlaps' }
        ]
      });
      explain(ov, R.escape(OVERLAP_TEXT));
    }

    if (mismatches.length) {
      part('Loci on another chromosome',
        (mismatches.length === 1 ? 'This locus is' : 'These loci are') + ' mapped to a different chromosome than this pan-gene (' +
        R.escape(overview.chr || '') + '): ' + mismatches.map(function (l) {
          return R.link('/gene_center/gene/' + encodeURIComponent(l), l);
        }).join(', ') + '.');
    }
    return true;
  }

  function renderOverview(overview, presence) {
    if (!overview) { return false; }
    var out = els.overviewBody;
    out.innerHTML = '';

    /* Order: anything wrong with this pan-gene, then the figure, then the
       members. There are no fact cards: Analysis, Chromosome, Exemplar,
       Members and Assemblies are all in the header already, one screen up. */
    renderAlert(overview, out);

    presenceStrip = null;
    if (presence && MGDB.panGenePresence) {
      presenceStrip = MGDB.panGenePresence(out, {
        presence: presence,
        chr: overview.chr,
        filename: 'pan-gene-presence.tsv'
      });
    }

    /* The members table renders in here, immediately below the strip, so that
       clicking a cell answers in place. render() fills it; there is no
       separate Members section any more. */
    out.insertAdjacentHTML('beforeend', '<div id="pg-overview-members"></div>');

    R.collection(out, {
      title: 'Associated loci',
      items: overview.loci,
      filename: 'pan-gene-loci.tsv',
      columns: [
        { key: 'name', label: 'Locus', tile: true,
          html: function (l) { return (l.html ? R.link(l.html, l.name) : R.escape(l.name)) +
                 (l.chromosome_mismatch ? ' <span class="mgdb-pill mgdb-pill-warn">Other chromosome</span>' : ''); } },
        { key: 'linkage_group', label: 'Linkage group' },
        { key: 'source', label: 'Association source' },
        { key: 'comment', label: 'Evidence' }
      ]
    });

    /* "About pan-genes" moved to the header (templates/static/
       mgdb_pan_gene_record.bau), under the name. */

    return true;
  }

  /* ------------------------------------------------------------------------
     Analysis
     ------------------------------------------------------------------------ */

  function renderAnalysis(analysis) {
    if (!analysis) { return false; }
    var out = els.analysisBody;
    out.innerHTML = '';

    var factsHtml = R.facts([
      ['Analysis', analysis.name ? R.escape(analysis.name) : ''],
      ['Description', analysis.description ? R.escape(analysis.description) : ''],
      ['Software', analysis.program
        ? (analysis.source_uri
            ? R.link(analysis.source_uri, analysis.program +
                (analysis.program_version ? ' ' + analysis.program_version : ''), true)
            : R.escape(analysis.program) +
              (analysis.program_version ? ' ' + R.escape(analysis.program_version) : ''))
        : ''],
      ['Source', analysis.source ? R.escape(analysis.source) : ''],
      ['Date', analysis.executed ? R.escape(analysis.executed) : ''],
      ['Annotations included', analysis.annotations ? R.number(analysis.annotations.length) : '']
    ]);
    if (factsHtml) { out.insertAdjacentHTML('beforeend', factsHtml); }

    R.collection(out, {
      title: 'Annotations and assemblies included in the analysis',
      items: analysis.annotations,
      filename: 'pan-gene-analysis-annotations.tsv',
      pageSize: 25,
      columns: [
        { key: 'assembly', label: 'Assembly', tile: true,
          html: function (a) { return R.link('/genome/assembly/' + encodeURIComponent(a.assembly), a.assembly); } },
        { key: 'annotation', label: 'Annotation' },
        { key: 'gene_models', label: 'Gene models', sort: 'number', numeric: true,
          get: function (a) { return a.gene_models == null ? '' : R.number(a.gene_models); } },
        { key: 'min_length', label: 'Min length', sort: 'number', numeric: true,
          get: function (a) { return a.min_length == null ? '' : R.number(a.min_length); } },
        { key: 'max_length', label: 'Max length', sort: 'number', numeric: true,
          get: function (a) { return a.max_length == null ? '' : R.number(a.max_length); } },
        { key: 'average_length', label: 'Ave length', sort: 'number', numeric: true,
          get: function (a) { return a.average_length == null ? '' : R.number(a.average_length); } },
        { key: 'percent_placed', label: '% placed in pan-genes', sort: 'number', numeric: true,
          get: function (a) { return a.percent_placed == null ? '' : a.percent_placed + '%'; } }
      ]
    });

    return true;
  }

  /* ------------------------------------------------------------------------
     Sequence, alignment, tree, pangenome graph, genome context
     ------------------------------------------------------------------------ */

  function renderSequence(sequence, sections) {
    if (!sequence) { return false; }
    var out = els.sequenceBody;
    out.innerHTML = '';

    var factsHtml = R.facts([
      ['Exemplar', sequence.exemplar ? '<span class="mgdb-sequence">' + R.escape(sequence.exemplar) + '</span>' : ''],
      ['Members aligned', sequence.alignment_member_count == null ? '' : R.number(sequence.alignment_member_count)]
    ]);
    if (factsHtml) { out.insertAdjacentHTML('beforeend', factsHtml); }

    var rows = [];
    if (sequence.download_url) {
      rows.push({ label: 'CDS sequence for the pan-gene exemplar', type: 'cds' });
      rows.push({ label: 'Protein sequence for the pan-gene exemplar', type: 'protein' });
    }
    if (rows.length) {
      out.insertAdjacentHTML('beforeend',
        '<div class="mgdb-rec-block"><div class="mgdb-rec-block-head"><h3>Exemplar sequence' +
        '<span class="mgdb-rec-block-count">' + rows.length + '</span></h3></div>' +
        '<div class="mgdb-rec-linkrow">' + rows.map(function (row) {
          return '<button class="mgdb-button mgdb-button-secondary" type="button" data-seq="' +
                 R.escape(row.type) + '">' + R.escape(row.label) + '</button>';
        }).join('') + '</div></div>');
      Array.prototype.forEach.call(out.querySelectorAll('[data-seq]'), function (button) {
        button.addEventListener('click', function () {
          if (typeof window.downloadPanGeneSequence !== 'function') { return; }
          window.downloadPanGeneSequence(button.getAttribute('data-seq'),
            sequence.pan_gene_name, sequence.download_url);
        });
      });
    }

    /* The record's own alignment viewer, drawn from the same aligned FASTA the
       legacy BioJS MSAViewer read. That library (199 KB) is no longer loaded:
       this one is linked to the other figures, draws only the cells in view,
       and carries the conservation profile and the exemplar's domains. */
    if ((sequence.protein_alignment_url || sequence.cds_alignment_url) && MGDB.panGeneMsa) {
      var sec = sections || {};
      MGDB.panGeneMsa(out, {
        proteinUrl: sequence.protein_alignment_url,
        cdsUrl: sequence.cds_alignment_url,
        exemplar: sequence.exemplar,
        resolve: memberIndex(sec.members),
        treeUrl: sec.tree ? sec.tree.url : null,
        domains: sec.domains,
        panGene: sequence.pan_gene_name
      });
    } else if (!sequence.cds_alignment_url && !sequence.protein_alignment_url) {
      out.insertAdjacentHTML('beforeend',
        '<p class="mgdb-rec-empty">No alignment files exist for this pan-gene.</p>');
    }

    return true;
  }


  /* Everything the tree needs to say about a tip, keyed by transcript. The
     tree file names tips by transcript and every other figure works in gene
     models, so this is also what translates between them -- by lookup, not by
     stripping a _T\d+ suffix, which is a guess the member list makes
     unnecessary. */
  function memberIndex(members) {
    var byTranscript = {};
    (members || []).forEach(function (m) {
      if (!m.transcript) { return; }
      byTranscript[m.transcript] = {
        gene: m.name,
        assembly: m.assembly,
        species: m.species || null,
        chr: m.chr,
        html: m.html || null
      };
    });
    return function (transcript) {
      return Object.prototype.hasOwnProperty.call(byTranscript, transcript)
        ? byTranscript[transcript] : null;
    };
  }

  function renderTree(tree, members, overview) {
    if (!tree) { return false; }
    var out = els.treeBody;
    out.innerHTML = '';
    if (!tree.url) {
      out.innerHTML = '<p class="mgdb-rec-empty">No tree exists for this pan-gene.</p>';
      return true;
    }
    if (MGDB.panGeneTree) {
      MGDB.panGeneTree(out, {
        url: tree.url,
        exemplar: tree.exemplar,
        panChr: overview ? overview.chr : null,
        resolve: memberIndex(members),
        filename: 'pan-gene-tree.tsv'
      });
      return true;
    }
    /* d3-hierarchy did not load, so there is no viewer at all now that IcyTree
       has gone. Say so and hand over the file. */
    out.insertAdjacentHTML('beforeend',
      '<p class="mgdb-rec-empty">The tree viewer could not be loaded. ' +
      R.link(tree.url, 'Open the Newick file', true) + '.</p>');
    return true;
  }

  /* The graph is a wide, detailed figure -- tracks across 26 assemblies -- and
     an image card scaled it into a thumbnail that had to be opened in a dialog
     before any of it could be read. It is shown full width instead, with the
     full-size file a click away for anyone who wants to zoom further. */
  function renderPangenome(images) {
    var out = els.pangenomeBody;
    out.innerHTML = '';
    if (!images || !images.length) { return false; }
    var html = '<div class="mgdb-rec-block mgdb-pg-graph">' +
      '<div class="mgdb-rec-block-head is-headless">' +
        '<div class="mgdb-pg-graph-tools">' +
          '<a class="mgdb-rec-tsv" href="' + R.escape(images[0].url) + '" target="_blank" rel="noopener">' +
            'Open full size</a>' +
        '</div>' +
      '</div>' +
      '<p class="mgdb-fig-desc">Cactus-Minigraph pangenome subgraph across the NAM founders ' +
        'and B73v5, shown full width.</p>' +
      '<p class="mgdb-rec-block-status">This image shows genomic sequence at the B73 gene model ' +
      'location across the NAM founder and B73v5 assemblies. The image was generated by the ' +
      'cactus-Minigraph pangenome pipeline by MaizeGDB in 2026. Where present, the merged CDSs ' +
      '(dark yellow) and UTRs (aqua) in the subgraph space are also shown. Note that the UTR ' +
      'track may be missing.</p>';
    images.forEach(function (image) {
      html += '<figure class="mgdb-pg-graph-figure">' +
        '<img src="' + R.escape(image.url) + '" alt="Pangenome graph at ' +
          R.escape(image.gene_model) + '" loading="lazy">' +
        '<figcaption>Pangenome graph at <span class="mgdb-sequence">' +
          R.escape(image.gene_model) + '</span></figcaption>' +
      '</figure>';
    });
    html += '</div>';
    out.insertAdjacentHTML('beforeend', html);
    return true;
  }

  function renderContext(viewers, positions) {
    var out = els.contextBody;
    out.innerHTML = '';
    var map = null;
    if (!viewers || !viewers.gcv_url) {
      map = MGDB.panGenePlacement ? MGDB.panGenePlacement(out, { positions: positions,
        filename: 'pan-gene-placement.tsv' }) : null;
      return !!map;
    }
    out.innerHTML =
      '<div class="mgdb-rec-block"><div class="mgdb-rec-block-head"><h3>Genomic Context Viewer for the ' +
      R.escape(viewers.gcv_analysis || 'pan-gene analysis') + '</h3></div>' +
      '<p class="mgdb-rec-block-status">The Genomic Context Viewer enables exploration of synteny in ' +
      'regions of interest at the gene model level. The underlying data is a pan-gene analysis that ' +
      'groups gene models from multiple annotations into pan-genes, a set of genes that appear to be ' +
      'the same. Each track in the display represents an annotation. Rather than showing the gene ' +
      'models in each track, the color-coded pan-genes are displayed.</p>' +
      '<div class="mgdb-rec-linkrow">' +
      '<a class="mgdb-button mgdb-button-primary" href="' + R.escape(viewers.gcv_url) +
      '" target="_blank" rel="noopener">Open in the Genomic Context Viewer</a>' +
      '</div>' +
      '<iframe class="mgdb-pg-gcv" src="' + R.escape(viewers.gcv_url) +
      '" title="Genomic Context Viewer" loading="lazy"></iframe>' +
      '<p class="mgdb-rec-block-status">References: ' +
      R.link('https://github.com/legumeinfo/gcv', 'GitHub', true) + ', ' +
      R.link('https://doi.org/10.1093/bioinformatics/btx757', 'Cleary and Farmer, 2017', true) + ', ' +
      R.link('https://doi.org/10.1093/nar/gkad391', 'Cleary and Farmer, 2023', true) + '.</p></div>';
    /* The placement map above the GCV: where each member is, before the
       synteny around it. */
    if (MGDB.panGenePlacement) {
      MGDB.panGenePlacement(out, { positions: positions, filename: 'pan-gene-placement.tsv' });
    }
    return true;
  }

  function renderDownloads(downloads) {
    if (!downloads) { return false; }
    var out = els.downloadsBody;
    out.innerHTML = '';
    if (!downloads.bulk_url) {
      out.innerHTML = '<p class="mgdb-rec-empty">No download files are available for this analysis.</p>';
      return true;
    }
    out.insertAdjacentHTML('beforeend', R.facts([
      ['Bulk download directory', R.link(downloads.bulk_url, downloads.bulk_url, true)]
    ]));
    out.insertAdjacentHTML('beforeend',
      '<div class="mgdb-rec-block"><div class="mgdb-rec-block-head"><h3>This pan-gene' +
      '<span class="mgdb-rec-block-count">' + downloads.files.length + '</span></h3></div>' +
      '<p class="mgdb-rec-block-status">The downloads below can be slow.</p>' +
      '<div class="mgdb-rec-linkrow">' + downloads.files.map(function (file) {
        return '<button class="mgdb-button mgdb-button-secondary" type="button" data-dl="' +
               R.escape(file.type) + '">' + R.escape(file.label) + '</button>';
      }).join('') + '</div>' +
      '<img src="/images/cornloading_trans.gif" id="pan_gene_downloading" height="52" alt="" style="display:none">' +
      '</div>');
    Array.prototype.forEach.call(out.querySelectorAll('[data-dl]'), function (button) {
      button.addEventListener('click', function () {
        if (typeof window.downloadPanGeneSequence !== 'function') { return; }
        window.downloadPanGeneSequence(button.getAttribute('data-dl'),
          downloads.pan_gene_name, downloads.bulk_url);
      });
    });
    return true;
  }

  /* The CGV takes its whole request in the path, and the shape is the legacy
     goToGCV()'s, unchanged.

     cmp_ids is 'accession|internal id|:chr1:chr2:...:chr10'. Splitting the
     third field on ':' leaves an empty first element because of the leading
     colon, which is what makes chromosome 2 land on index 2 -- the list is
     effectively one-based. Only the comparison accession takes the '.1'
     version suffix; the B73 reference already carries one in the database. */
  function cgvUrl(row, ncbi) {
    var parts = String(row.cmp_ids).split('|');
    if (parts.length < 3) { return ''; }
    var chrs = parts[2].split(':');
    var index = parseInt(ncbi.chromosome_number, 10);
    var compareChr = chrs[index] || '';
    return 'https://www.ncbi.nlm.nih.gov/genome/cgv/browse/' +
      parts[0] + '.1/' + ncbi.reference_accession + '/' + parts[1] + '/' +
      '4577#' + compareChr + '/' + ncbi.chromosome_accession + ':' +
      ncbi.start + '-' + ncbi.end + '/size=10000';
  }

  function renderViewers(viewers) {
    if (!viewers || !viewers.ncbi) { return false; }
    var ncbi = viewers.ncbi;
    var out = els.viewersBody;
    out.innerHTML = '';

    out.insertAdjacentHTML('beforeend', R.facts([
      ['NCBI Gene', R.link('https://www.ncbi.nlm.nih.gov/gene/?term=' + encodeURIComponent(ncbi.gene_accession), ncbi.gene_accession, true)],
      ['Locus', R.escape(ncbi.locus)],
      ['Reference assembly', R.escape(ncbi.reference_assembly)],
      ['Region', R.escape(ncbi.chromosome + ':' + R.number(ncbi.start) + '..' + R.number(ncbi.end))]
    ]));

    out.insertAdjacentHTML('beforeend',
      '<div class="mgdb-rec-block"><div class="mgdb-rec-block-head"><h3>Genome Data Viewer at NCBI</h3></div>' +
      '<p class="mgdb-rec-block-status">Explore structural variation between the NCBI annotation gene model ' +
      R.escape(ncbi.gene_accession) + ' corresponding to ' + R.escape(ncbi.locus) +
      ' and your choice of NAM assemblies. Select NAM and RNAseq tracks using the ' +
      '&ldquo;tracks&rdquo; option on the browser&rsquo;s menu bar.</p>' +
      '<div class="mgdb-rec-linkrow"><a class="mgdb-button mgdb-button-primary" href="' +
      R.escape(ncbi.gdv_url) + '" target="_blank" rel="noopener">Open the NCBI Genome Data Viewer ' +
      '</a></div></div>');

    R.collection(out, {
      title: 'NCBI Comparative Genome Viewer: compare B73 against',
      items: ncbi.compare,
      filename: 'pan-gene-cgv-assemblies.tsv',
      pageSize: 25,
      columns: [
        { key: 'assembly', label: 'Assembly', tile: true },
        { key: 'view', label: 'NCBI Comparative Genome Viewer', sort: false,
          get: function (row) { return cgvUrl(row, ncbi); },
          /* NCBI's viewer, not MaizeGDB's: the label says whose it is. */
          html: function (row) {
            return R.link(cgvUrl(row, ncbi), 'Open the NCBI Comparative Genome Viewer', true);
          } }
      ]
    });

    return true;
  }

  /* ------------------------------------------------------------------------
     Metrics and figures
     ------------------------------------------------------------------------ */

  function renderMetrics(counts, analysis, memberCount) {
    R.metrics(els.metricsBody, [
      ['Members', 'Gene models', counts.members, 'Gene models the analysis grouped into this pan-gene.', 'green'],
      ['Assemblies', 'Coverage', counts.assemblies, 'Distinct assemblies those members come from.', 'amber'],
      ['Ontology terms', 'Function', counts.function, 'GO and other ontology terms attached to the members.', 'blue'],
      ['SNP associations', 'Traits', counts.traits, 'SNPs in the members with a recorded trait association.', 'burgundy']
    ]);

    var series = [
      ['Members', counts.members], ['Assemblies', counts.assemblies],
      ['Ontology terms', counts.function], ['Members with domains', counts.domains],
      ['SNP associations', counts.traits], ['Insertions', counts.insertions],
      ['Expression links', counts.expression], ['Loci', counts.loci],
      ['Proteins', counts.proteins], ['Protein structures', counts.protein_structures],
      ['Overlapping models', counts.overlaps], ['Pathways', counts.pathways]
    ];
    var height = R.connectionsHeight(series);

    /* Where this pan-gene sits in the analysis. A pan-gene of 65 members in an
       analysis of 66 annotations is a core gene present almost everywhere, and
       the distribution is the only thing that says so. */
    var distribution = (analysis && analysis.distribution) || [];
    if (distribution.length > 1 && MGDB.chart) {
      R.show(R.byId('pg-record-size-figure'), true);
      R.sizeChart('pg-record-size-chart', height);
      var sizes = distribution.map(function (d) { return d.size; });
      var values = distribution.map(function (d) { return d.pan_genes; });
      var here = distribution.filter(function (d) { return d.size === memberCount; })[0];
      R.byId('pg-record-size-caption').textContent =
        (here
          ? 'This pan-gene has ' + R.number(memberCount) + ' members, a size shared by ' +
            R.number(here.pan_genes) + ' pan-genes in the analysis.'
          : 'Pan-gene sizes across the analysis, truncated at ' +
            R.number(analysis.distribution_cutoff) + ' members.');
      MGDB.chart({
        target: 'pg-record-size-chart',
        traces: function () {
          return [{
            type: 'bar', x: sizes, y: values,
            marker: {
              color: sizes.map(function (size) {
                return size === memberCount ? '#e95e22' : '#285d46';
              })
            },
            hovertemplate: '%{x} members<br>%{y:,} pan-genes<extra></extra>'
          }];
        },
        layout: {
          height: height,
          margin: { l: 16, r: 16, t: 8, b: 44 },
          bargap: 0.1,
          xaxis: { title: { text: 'Members in a pan-gene' }, rangemode: 'tozero', automargin: true },
          yaxis: { title: { text: 'Pan-genes' }, rangemode: 'tozero', automargin: true }
        }
      });
      R.watchChartWidth('pg-record-size-chart');
    }

    R.connectionsChart('pg-record-connections-chart', 'pg-record-connections-caption',
                       'pg-record-connections-figure', series, height);
    return true;
  }

  /* ------------------------------------------------------------------------
     Assembly
     ------------------------------------------------------------------------ */

  var TAB_COUNTS = {
    'pg-record-overview': ['members'],
    'pg-record-associated': ['function', 'insertions', 'traits', 'pathways'],
    'pg-record-domains': ['domains'],
    'pg-record-expression': ['expression'],
    'pg-record-proteins': ['proteins', 'protein_structures'],
    'pg-record-pangenome': ['pangenome_images'],
    'pg-record-analysis': ['annotations']
  };

  var LABELS = {
    'pg-record-overview': 'Overview',
    'pg-record-associated': 'Associated data',
    'pg-record-domains': 'Protein domains',
    'pg-record-expression': 'Expression',
    'pg-record-proteins': 'Proteins and structures',
    'pg-record-sequence': 'Sequence and alignment',
    'pg-record-tree': 'Phylogenetic tree',
    'pg-record-pangenome': 'Pangenome graph',
    'pg-record-context': 'Genome context',
    'pg-record-analysis': 'Analysis',
    'pg-record-downloads': 'Downloads',
    'pg-record-viewers': 'Comparative viewers',
    'pg-record-metrics': 'Metrics',
    'pg-record-resources': 'Related resources',
    'pg-record-api': 'API'
  };

  /* ------------------------------------------------------------------------
     Associated data

     What the pan-gene's members carry -- ontology terms, insertions,
     metabolic pathways, SNP-trait associations -- as one table of gene
     models, each count linking into that gene model's own record at the
     matching section. These were four sections that each repeated, for the
     pan-gene, a list the gene record already shows gene model by gene model.
     The four full lists stay underneath, folded, for reading across members.
     ------------------------------------------------------------------------ */

  /* The GO evidence codes, by the Gene Ontology Consortium's names
     (https://geneontology.org/docs/guide-go-evidence-codes/). A code not in
     this list -- the NAM Consortium's COMP, say -- is shown as it is. */
  var GO_EVIDENCE = {
    EXP: 'Inferred from Experiment', IDA: 'Inferred from Direct Assay', IPI: 'Inferred from Physical Interaction',
    IMP: 'Inferred from Mutant Phenotype', IGI: 'Inferred from Genetic Interaction', IEP: 'Inferred from Expression Pattern',
    HTP: 'Inferred from High Throughput Experiment', HDA: 'Inferred from High Throughput Direct Assay',
    HMP: 'Inferred from High Throughput Mutant Phenotype', HGI: 'Inferred from High Throughput Genetic Interaction',
    HEP: 'Inferred from High Throughput Expression Pattern', IBA: 'Inferred from Biological aspect of Ancestor',
    IBD: 'Inferred from Biological aspect of Descendant', IKR: 'Inferred from Key Residues',
    IRD: 'Inferred from Rapid Divergence', ISS: 'Inferred from Sequence or structural Similarity',
    ISO: 'Inferred from Sequence Orthology', ISA: 'Inferred from Sequence Alignment', ISM: 'Inferred from Sequence Model',
    IGC: 'Inferred from Genomic Context', RCA: 'Inferred from Reviewed Computational Analysis',
    TAS: 'Traceable Author Statement', NAS: 'Non-traceable Author Statement', IC: 'Inferred by Curator',
    ND: 'No biological Data available', IEA: 'Inferred from Electronic Annotation'
  };
  var GO_EVIDENCE_GUIDE = 'https://geneontology.org/docs/guide-go-evidence-codes/';

  /* Where on a gene record each kind lives (js/mgdb-gene-record-v5.js):
     Plant Reactome pathways are part of its Function section. */
  var RECORD_ANCHOR = {
    go: 'gene-record-function', insertions: 'gm-insertions', pathways: 'gene-record-function',
    snps: 'gm-snps', protein: 'gene-record-structure'
  };

  function renderAssociated(sections) {
    var out = els.associatedBody;
    if (!out) { return false; }
    out.innerHTML = '';

    var byName = {}, byTranscript = {};
    (sections.members || []).forEach(function (m) {
      byName[m.name] = m;
      if (m.transcript) { byTranscript[m.transcript] = m.name; }
    });
    function geneOf(id) {
      id = String(id || '').trim();
      if (!id) { return null; }
      if (byName[id]) { return id; }
      if (byTranscript[id]) { return byTranscript[id]; }
      return id.replace(/_[TP]\d+$/, '');
    }

    var terms = sections.function || [];
    var insertions = sections.insertions || [];
    var traits = sections.traits || [];
    var pw = sections.pathways || {};
    var reactome = pw.reactome || [], corncyc = pw.corncyc || [];

    var agg = {};
    function bump(id, key) {
      var gm = geneOf(id);
      if (!gm) { return; }
      var a = agg[gm] || (agg[gm] = { go: 0, insertions: 0, pathways: 0, snps: 0 });
      a[key]++;
    }
    terms.forEach(function (t) { String(t.gene_models || '').split(/[\s,;]+/).forEach(function (g) { bump(g, 'go'); }); });
    insertions.forEach(function (i) { bump(i.gene_model, 'insertions'); });
    traits.forEach(function (t) { bump(t.gene_model, 'snps'); });
    reactome.forEach(function (p) { bump(p.gene_model, 'pathways'); });
    corncyc.forEach(function (p) { bump(p.feature, 'pathways'); });

    var rows = Object.keys(agg).map(function (gm) {
      var a = agg[gm], m = byName[gm] || {};
      return { gene_model: gm, html: m.html || null, assembly: m.assembly || null, is_exemplar: !!m.is_exemplar,
               go: a.go, insertions: a.insertions, pathways: a.pathways, snps: a.snps,
               total: a.go + a.insertions + a.pathways + a.snps };
    }).sort(function (x, y) { return (y.total - x.total) || x.gene_model.localeCompare(y.gene_model); });

    if (!rows.length) {
      out.innerHTML = '<p class="mgdb-rec-empty">No ontology terms, insertions, metabolic pathways or SNP-trait ' +
        'associations are recorded for the members of this pan-gene.</p>';
      return true;
    }

    function countCell(key, label) {
      return {
        key: key, label: label, sort: 'number', numeric: true,
        get: function (r) { return r[key] ? String(r[key]) : ''; },
        html: function (r) {
          if (!r[key]) { return '<span class="mgdb-muted">&mdash;</span>'; }
          return r.html ? R.link(r.html + '#' + RECORD_ANCHOR[key], R.number(r[key])) : R.number(r[key]);
        }
      };
    }
    var withoutPage = rows.filter(function (r) { return !r.html; }).length;
    R.collection(out, {
      title: 'Member gene models with associated data',
      items: rows,
      filename: 'pan-gene-associated-data.tsv',
      columns: [
        { key: 'gene_model', label: 'Gene model', tile: true,
          html: function (r) { return memberLink(r.gene_model, r.html) +
                 (r.is_exemplar ? ' <span class="mgdb-pill mgdb-pill-ok">Exemplar</span>' : ''); } },
        { key: 'assembly', label: 'Assembly', get: function (r) { return r.assembly || ''; } },
        countCell('go', 'Terms'),
        countCell('insertions', 'Insertions'),
        countCell('pathways', 'Pathways'),
        countCell('snps', 'SNP-traits'),
        { key: 'protein', label: 'Protein', sort: false, get: function (r) { return r.html ? r.html + '#' + RECORD_ANCHOR.protein : ''; },
          html: function (r) { return r.html ? R.link(r.html + '#' + RECORD_ANCHOR.protein, 'Structure') : '<span class="mgdb-muted">&mdash;</span>'; } }
      ]
    });
    var head = out.querySelector('.mgdb-rec-block-head');
    if (head) {
      head.insertAdjacentHTML('afterend', '<p class="mgdb-pg-associated-lead">Terms are GO and Plant Ontology ' +
        'annotations. Each number opens that gene model’s record at the matching section; Structure opens ' +
        'its gene and protein structure.' +
        (withoutPage ? ' ' + R.number(withoutPage) + ' of these gene model' + (withoutPage === 1 ? ' has' : 's have') +
          ' no MaizeGDB record page, so ' + (withoutPage === 1 ? 'its numbers are' : 'their numbers are') + ' not links.' : '') +
        ' The full lists across all members are below.</p>');
    }

    /* The four lists, folded. Each is the table the old section held. */
    function list(title, count, build) {
      if (!count) { return; }
      var det = document.createElement('details');
      det.className = 'mgdb-pg-list';
      det.innerHTML = '<summary><span>' + R.escape(title) + '</span><span class="mgdb-rec-block-count">' + R.number(count) + '</span></summary>';
      var body = document.createElement('div');
      det.appendChild(body);
      out.appendChild(det);
      build(body);
    }

    list('Ontology terms on the members', terms.length, function (host) {
      R.collection(host, {
        title: 'Ontology terms on the members',
        items: terms,
        filename: 'pan-gene-ontology-terms.tsv',
        columns: [
          { key: 'term', label: 'Term', tile: true },
          { key: 'name', label: 'Name' },
          { key: 'evidence_code', label: 'Evidence',
            html: function (t) {
              var code = t.evidence_code || '';
              if (!code) { return '<span class="mgdb-muted">&mdash;</span>'; }
              var full = GO_EVIDENCE[String(code).toUpperCase()];
              return full ? '<abbr title="' + R.escape(full) + '">' + R.escape(code) + '</abbr>' : R.escape(code);
            } },
          { key: 'source', label: 'Source' },
          { key: 'reference', label: 'Reference', get: function (t) { return t.reference ? t.reference.name : ''; },
            html: function (t) { return t.reference ? (R.refLink(t.reference) || R.escape(t.reference.name)) : '—'; } },
          { key: 'comments', label: 'Comments' },
          { key: 'gene_models', label: 'Gene models', tsvOnly: true }
        ]
      });
      var h = host.querySelector('.mgdb-rec-block-head');
      if (h) {
        h.insertAdjacentHTML('afterend', '<p class="mgdb-pg-associated-lead">Evidence codes say how each term was ' +
          'assigned; point at a code for its name. Full names and descriptions: ' +
          R.link(GO_EVIDENCE_GUIDE, 'the Gene Ontology Consortium’s guide to GO evidence codes', true) + '.</p>');
      }
    });

    list('Insertions in pan-gene members', insertions.length, function (host) {
      R.collection(host, {
        title: 'Insertions in pan-gene members',
        items: insertions,
        filename: 'pan-gene-insertions.tsv',
        columns: [
          { key: 'name', label: 'Insertion', tile: true,
            html: function (i) { return i.variation ? (R.refLink(i.variation) || R.escape(i.name)) : R.escape(i.name); } },
          { key: 'gene_model', label: 'Gene model' },
          { key: 'position', label: 'Position',
            html: function (i) { return i.position
              ? '<span class="mgdb-sequence">' + R.escape(i.position) + '</span>'
              : '<span class="mgdb-muted">Not recorded</span>'; } },
          { key: 'structure', label: 'Gene structure' },
          { key: 'stocks', label: 'Stock' },
          { key: 'source', label: 'Source' },
          { key: 'structure_definition', label: 'Structure definition', tsvOnly: true }
        ]
      });
    });

    list('Metabolic pathways', reactome.length + corncyc.length, function (host) {
      R.collection(host, {
        title: 'Plant Reactome pathways',
        items: reactome,
        filename: 'pan-gene-reactome-pathways.tsv',
        columns: [
          { key: 'accession', label: 'Pathway', tile: true,
            html: function (p) { return p.url ? R.link(p.url, p.accession, true) : R.escape(p.accession); } },
          { key: 'description', label: 'Description' },
          { key: 'gene_model', label: 'Gene model' }
        ]
      });
      R.collection(host, {
        title: 'CornCyc pathways',
        items: corncyc,
        filename: 'pan-gene-corncyc-pathways.tsv',
        columns: [
          { key: 'accession', label: 'Pathway', tile: true,
            html: function (p) { return p.url ? R.link(p.url, p.accession, true) : R.escape(p.accession); } },
          { key: 'description', label: 'Description' },
          { key: 'feature', label: 'Gene model or transcript' }
        ]
      });
    });

    list('SNPs in pan-gene members and the traits they associate with', traits.length, function (host) {
      R.collection(host, {
        title: 'SNPs in pan-gene members and the traits they associate with',
        items: traits,
        filename: 'pan-gene-snp-traits.tsv',
        columns: [
          { key: 'snp', label: 'SNP', tile: true },
          { key: 'gene_model', label: 'Gene model' },
          { key: 'chromosome', label: 'Chromosome' },
          { key: 'position', label: 'Position', sort: 'number', numeric: true,
            get: function (t) { return t.position == null ? '' : R.number(t.position); } },
          { key: 'structure', label: 'Structure' },
          { key: 'trait', label: 'Trait' },
          { key: 'reference', label: 'Reference', get: function (t) { return t.reference ? t.reference.name : ''; },
            html: function (t) { return t.reference ? (R.refLink(t.reference) || R.escape(t.reference.name)) : '—'; } }
        ]
      });
    });

    return true;
  }

  function render(response, requested) {
    payload = response;
    var data = response.data || {};
    var sections = data.sections || {};
    var meta = response.meta || {};
    var counts = meta.counts || {};

    R.show(els.loading, false);
    R.show(els.error, false);

    renderHeader(data, requested);

    var rendered = [];
    if (renderOverview(sections.overview, sections.presence)) { rendered.push('pg-record-overview'); }

    /* Members render inside Overview, under the presence strip, rather than as
       a section of their own -- so selecting a cell of the strip filters a
       table the reader can already see. The Overview tab therefore carries the
       member count and there is no Members tab. */
    R.collection(R.byId('pg-overview-members'), {
      title: 'Gene models in this pan-gene',
      items: sections.members,
      filename: 'pan-gene-members.tsv',
      pageSize: 25,
      columns: [
        { key: 'name', label: 'Gene model', tile: true,
          html: function (m) { return memberLink(m.name, m.html) +
                 (m.is_exemplar ? ' <span class="mgdb-pill mgdb-pill-ok">Exemplar</span>' : ''); } },
        { key: 'assembly', label: 'Assembly',
          html: function (m) { return m.assembly
            ? R.link('/genome/assembly/' + encodeURIComponent(m.assembly), m.assembly)
            : '<span class="mgdb-muted">Not recorded</span>'; } },
        { key: 'annotation', label: 'Annotation' },
        { key: 'chr', label: 'Chromosome' },
        { key: 'locus', label: 'Locus', get: function (m) { return m.locus ? m.locus.name : ''; },
          html: function (m) { return m.locus ? (R.refLink(m.locus) || R.escape(m.locus.name)) : '—'; } },
        { key: 'browser_url', label: 'Browser', sort: false,
          get: function (m) { return m.browser_url || ''; },
          html: function (m) { return m.browser_url ? R.link(m.browser_url, 'Genome browser', true) : '—'; } }
      ]
    });
    /* The table exists now, so it can start following the shared selection. */
    bindMembersToSelection();

    if (renderAssociated(sections)) { rendered.push('pg-record-associated'); }

    var domains = sections.domains || {};
    /* The ribbons first: the architectures are the shape of the section and
       the table under them is the detail. */
    if (MGDB.panGeneArchitectures) {
      MGDB.panGeneArchitectures(els.domainsBody, {
        domains: domains,
        limit: 8,
        filename: 'pan-gene-architectures.tsv'
      });
    }
    var domainsRendered = R.collection(els.domainsBody, {
      title: 'Domains in order across the gene models',
      items: domains.members,
      filename: 'pan-gene-domains.tsv',
      pageSize: 25,
      columns: [
        { key: 'transcript', label: 'Transcript', tile: true },
        { key: 'assembly', label: 'Assembly' },
        { key: 'domain_count', label: 'Domains', sort: 'number', numeric: true,
          get: function (d) { return num(d.domain_count); } },
        { key: 'domain_string', label: 'Domain order',
          html: function (d) { return d.domain_string
            ? '<span class="mgdb-sequence">' + R.escape(d.domain_string) + '</span>'
            : '<span class="mgdb-muted">None</span>'; } }
      ]
    });
    if (domainsRendered) {
      /* On the table, not `afterbegin` on the section: the ribbons are drawn
         above it now and this note is about the Domain order column. */
      var domainsTable = els.domainsBody.querySelector('.mgdb-rec-block:not(.mgdb-pg-arch)');
      if (domainsTable) {
        domainsTable.insertAdjacentHTML('beforeend',
          '<p class="mgdb-rec-block-status">Domains are calculated by HMMscan. If a domain is repeated ' +
          'in order, the repeat number is shown in square brackets.</p>');
      }
      R.collection(els.domainsBody, {
        title: 'Domain definitions',
        items: domains.definitions,
        filename: 'pan-gene-domain-definitions.tsv',
        columns: [
          { key: 'accession', label: 'Accession', tile: true,
            html: function (d) { return R.link('https://www.ebi.ac.uk/interpro/entry/pfam/' +
                   encodeURIComponent(d.accession) + '/', d.accession, true); } },
          { key: 'name', label: 'Domain' },
          { key: 'definition', label: 'Definition' }
        ]
      });
      rendered.push('pg-record-domains');
    }

    var expressionLinks = R.collection(els.expressionBody, {
      title: 'Expression for pan-gene members in qTeller',
      items: sections.expression,
      filename: 'pan-gene-expression.tsv',
      pageSize: 25,
      columns: [
        { key: 'gene_model', label: 'Gene model', tile: true },
        { key: 'assembly', label: 'Assembly',
          html: function (e) { return R.link('/genome/assembly/' + encodeURIComponent(e.assembly), e.assembly); } },
        { key: 'url', label: 'qTeller', sort: false, get: function (e) { return e.url; },
          html: function (e) { return R.link(e.url, 'Expression profile', true); } }
      ]
    });
    /* The heatmap above the qTeller links, drawn from the record's own
       expression_matrix. Either one is enough to show the section. */
    var heat = MGDB.panGeneHeatmap ? MGDB.panGeneHeatmap(els.expressionBody, {
      matrix: sections.expression_matrix,
      treeUrl: sections.tree ? sections.tree.url : null,
      filename: 'pan-gene-nam-expression.tsv',
      toolsUrl: data.id ? '/expression/tools#pangene?id=' + encodeURIComponent(data.id) : null
    }) : null;
    if (expressionLinks || heat) { rendered.push('pg-record-expression'); }

    var proteins = sections.proteins || {};
    var proteinsRendered = false;
    proteinsRendered = R.collection(els.proteinsBody, {
      title: 'Proteins',
      items: proteins.proteins,
      filename: 'pan-gene-proteins.tsv',
      columns: [
        { key: 'transcript', label: 'Transcript', tile: true },
        { key: 'accession', label: 'Accession',
          html: function (p) { return p.url ? R.link(p.url, p.accession, true) : R.escape(p.accession); } },
        { key: 'database', label: 'Database' },
        { key: 'description', label: 'Description' }
      ]
    }) || proteinsRendered;
    proteinsRendered = R.collection(els.proteinsBody, {
      title: 'Members with proteomics data',
      items: proteins.proteomics,
      filename: 'pan-gene-proteomics.tsv',
      columns: [
        { key: 'gene_model', label: 'Gene model', tile: true,
          html: function (p) { return memberLink(p.gene_model, p.html); } },
        { key: 'assembly', label: 'Assembly' },
        { key: 'reference', label: 'Reference' }
      ]
    }) || proteinsRendered;
    proteinsRendered = R.collection(els.proteinsBody, {
      title: 'Protein structures',
      items: proteins.structures,
      filename: 'pan-gene-protein-structures.tsv',
      columns: [
        { key: 'transcript', label: 'Transcript', tile: true,
          html: function (s) { return R.link(s.html, s.transcript); } },
        { key: 'gene_model', label: 'Gene model' },
        { key: 'assembly', label: 'Assembly' },
        { key: 'annotation', label: 'Annotation' }
      ]
    }) || proteinsRendered;
    if (proteinsRendered) { rendered.push('pg-record-proteins'); }

    if (renderSequence(sections.sequence, sections)) {
      rendered.push('pg-record-sequence');
    }
    if (renderTree(sections.tree, sections.members, sections.overview)) { rendered.push('pg-record-tree'); }
    if (renderPangenome(sections.pangenome)) { rendered.push('pg-record-pangenome'); }
    if (renderContext(sections.viewers, sections.positions)) { rendered.push('pg-record-context'); }
    if (renderAnalysis(sections.analysis)) { rendered.push('pg-record-analysis'); }
    if (renderDownloads(sections.downloads)) { rendered.push('pg-record-downloads'); }
    if (renderViewers(sections.viewers)) { rendered.push('pg-record-viewers'); }

    rendered.forEach(function (id) { R.show(R.byId(id), true); });

    // Revealed before the charts are drawn: Plotly sizes a figure to its
    // container, and a hidden container has no width.
    R.show(R.byId('pg-record-metrics'), true);
    if (renderMetrics(counts, sections.analysis, (data.attributes || {}).member_count)) {
      rendered.push('pg-record-metrics');
    }

    R.tabs({
      el: els.tabs,
      order: rendered.concat(['pg-record-resources', 'pg-record-api']),
      labels: LABELS, counts: counts, tabCounts: TAB_COUNTS
    });

    /* Function, Insertions, SNPs and traits and Metabolic pathways were four
       sections until 2026-09-18; a bookmark to one of them lands on the
       section that holds them now. */
    if (/^#pg-record-(function|insertions|traits|pathways)$/.test(window.location.hash) && R.byId('pg-record-associated')) {
      history.replaceState(null, '', '#pg-record-associated');
      R.byId('pg-record-associated').scrollIntoView();
    }

    R.notice(els.notice, meta, counts);
    MGDB.announce('Record loaded, ' + rendered.length + ' sections.');
  }

  function load() {
    var main = R.byId('pg-record-top');
    if (!main) { return; }
    var requested = main.getAttribute('data-requested-id') || main.getAttribute('data-pan-gene');
    if (!requested) { return; }

    R.show(els.error, false);
    R.show(els.loading, true);

    MGDB.request('/api/v1/records/pan_gene/' + encodeURIComponent(requested), { key: 'pan-gene-record' })
      .then(function (response) {
        if (!response || !response.data) { throw new Error('unexpected payload'); }
        render(response, requested);
      })
      .catch(function (error) {
        if (error && error.name === 'AbortError') { return; }
        R.show(els.loading, false);
        R.show(els.error, true);
      });
  }

  function init() {
    els = {
      synonyms: R.byId('pg-record-synonyms'),
      tabs: R.byId('pg-record-tabs'),
      loading: R.byId('pg-record-loading'),
      error: R.byId('pg-record-error'),
      retry: R.byId('pg-record-retry'),
      notice: R.byId('pg-record-notice'),
      overviewBody: R.byId('pg-record-overview-body'),
      associatedBody: R.byId('pg-record-associated-body'),
      domainsBody: R.byId('pg-record-domains-body'),
      expressionBody: R.byId('pg-record-expression-body'),
      proteinsBody: R.byId('pg-record-proteins-body'),
      sequenceBody: R.byId('pg-record-sequence-body'),
      treeBody: R.byId('pg-record-tree-body'),
      pangenomeBody: R.byId('pg-record-pangenome-body'),
      contextBody: R.byId('pg-record-context-body'),
      analysisBody: R.byId('pg-record-analysis-body'),
      downloadsBody: R.byId('pg-record-downloads-body'),
      viewersBody: R.byId('pg-record-viewers-body'),
      metricsBody: R.byId('pg-record-metrics-body')
    };
    if (els.retry) { els.retry.addEventListener('click', load); }
    R.apiCard('pg-copy-json-btn', 'pg-record-api-link', function () { return payload; });
    load();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
