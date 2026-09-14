/* ==========================================================================
   Gene record page — /gene_center/gene/{id}
   --------------------------------------------------------------------------
   Glue over js/mgdb-record.js, the same engine every other record page uses.
   This file maps one call to /api/v1/records/gene/{id} onto it.

   Three things are this page's own and are not shell collections:

     the protein domain track     domains drawn against the protein, to scale.
     the pan-gene presence strip  one square per assembly, grouped by Zea
                                  species.
     the eFP viewer               one atlas at a time, large enough to read.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  var R = window.MGDBRecord;
  if (!MGDB || !R) { return; }

  var els = {};
  var payload = null;
  /* What the header hands to Overview: the synonyms and the record's kind,
     which the hero no longer lists. */
  var overviewExtras = { synonyms: [], kind: null };
  var KIND_LABELS = {
    gene_model: 'Gene model', gene_model_and_locus: 'Gene model and classical gene',
    locus: 'Classical gene', withdrawn: 'Withdrawn gene model'
  };

  function num(value) { return (value === null || value === undefined) ? '' : R.number(value); }

  /* Withdraw a section after the tabs have been built -- used only where a
     block can fail after render, which today is an image the server turns out
     not to have. The tab goes with the section; a tab that scrolls to nothing
     is worse than no tab. */
  function hideSection(id) {
    R.show(R.byId(id), false);
    if (!els.tabs) { return; }
    var tab = els.tabs.querySelector('a[href="#' + id + '"]');
    if (tab && tab.parentNode) { tab.parentNode.removeChild(tab); }
  }

  /* The B73 assembly a reader should normally be looking at. A B73 gene has
     seven annotations -- RefGen_v1, v2, v3, two GRAMENE-4.0 releases and
     NAM-5.0 -- and `is_current` is true within each of them, so it cannot tell
     a reader that v3 is superseded. This can. */
  var CURRENT_B73 = 'Zm-B73-REFERENCE-NAM-5.0';

  /* ------------------------------------------------------------------------
     Header
     ------------------------------------------------------------------------ */

  function renderHeader(data, sections) {
    var attributes = data.attributes || {};
    var fn = sections.function || {};
    if (fn.summary) {
      els.functionLine.textContent = fn.summary;
      R.show(els.functionLine, true);
    }
    var locus = sections.locus || {};
    overviewExtras.synonyms = (locus.synonyms || []).map(function (s) {
      return typeof s === 'string' ? s : (s.value || s.name);
    }).filter(Boolean);
    overviewExtras.kind = attributes.kind || null;
    /* The subtitle is server-rendered from the full name; when there is none
       the one-line function summary takes its place, otherwise the summary
       sits beneath it. */
    if (els.subtitle && !els.subtitle.textContent.trim() && fn.summary) {
      els.subtitle.textContent = fn.summary;
      R.show(els.functionLine, false);
    }

    /* A B73 record from an older assembly says so, and points at the current
       one. The list of every annotation of the gene is on the classical gene
       section below; this is the one line a reader needs before reading
       anything else on the page. */
    var assembly = attributes.assembly || '';
    if (assembly && assembly.indexOf('B73') !== -1 && assembly !== CURRENT_B73) {
      var models = (locus.associated_gene_models || []).filter(function (m) {
        return m.assembly === CURRENT_B73;
      });
      var link = models.length
        ? ' The current B73 annotation of this gene is ' +
          R.link('/gene_center/gene/' + encodeURIComponent(models[0].name), models[0].name) + '.'
        : '';
      els.versionNotice.innerHTML = '<div><strong>An earlier B73 assembly</strong>' +
        '<span>This record is the ' + R.escape(assembly) + ' annotation. B73 has been ' +
        'assembled and annotated several times, and each release numbers its genes ' +
        'differently.' + link + '</span></div>';
      R.show(els.versionNotice, true);
    }

    /* The legacy "report a gene model error" link called popUpAnnotation(),
       which opens a Shadowbox iframe that modern pages do not load. It goes
       straight to the curation form instead. */
    if (els.report && attributes.feature_id) {
      els.report.setAttribute('href', '/curation/GeneModelIssue/edit?gene_model_id=' +
        encodeURIComponent(attributes.feature_id) + '&gene_model_version=' +
        encodeURIComponent(attributes.annotation || '') + '&auto_num=');
    }
  }

  /* ------------------------------------------------------------------------
     Overview
     ------------------------------------------------------------------------ */

  function renderOverview(overview) {
    if (!overview) { return false; }
    var out = els.overviewBody;
    out.innerHTML = '';

    var position = '';
    if (overview.chromosome && overview.start !== null && overview.start !== undefined) {
      position = R.escape(overview.chromosome) + ':' + R.number(overview.start) +
                 '\u2013' + R.number(overview.end);
    }

    var kind = overview.kind || overviewExtras.kind;
    var factsHtml = R.facts([
      ['Record', kind ? R.escape(KIND_LABELS[kind] || kind) : ''],
      ['Full name', overview.full_name && overview.full_name !== overview.symbol ? R.escape(overview.full_name) : ''],
      ['Species', overview.species ? '<em>' + R.escape(overview.species) + '</em>' : ''],
      ['Line', overview.line ? R.escape(overview.line) : ''],
      ['Position', position, overview.span_bp ? R.number(overview.span_bp) + ' bp on the genome' : ''],
      ['Strand', overview.strand ? R.escape(overview.strand) : '', overview.strand_note || ''],
      ['Model type', overview.model_type ? R.escape(String(overview.model_type).replace(/_/g, ' ')) : ''],
      ['Transcripts', overview.transcript_count == null ? '' : String(overview.transcript_count),
        overview.canonical_transcript ? 'canonical ' + overview.canonical_transcript : '']
    ]);
    if (factsHtml) { out.insertAdjacentHTML('beforeend', factsHtml); }
    if (overviewExtras.synonyms.length) {
      out.insertAdjacentHTML('beforeend', '<p class="mgdb-rec-synonyms mgdb-rec-aka">Also known as ' +
        overviewExtras.synonyms.map(function (s) { return '<strong>' + R.escape(s) + '</strong>'; })
          .join(' <span class="mgdb-muted" aria-hidden="true">&middot;</span> ') + '.</p>');
    }

    /* The genome browser preview. The legacy Overview carried a 300px JBrowse
       frame of this gene in its neighbourhood; it was lost in the port. Only
       JBrowse can be framed -- B73 v3 and v4 point at GBrowse, which serves a
       snapshot image rather than a frameable view -- so those get the link. */
    var browser = overview.browser;
    if (browser && browser.url) {
      out.insertAdjacentHTML('beforeend',
        '<div class="mgdb-rec-block"><div class="mgdb-rec-block-head">' +
          '<h3>Genome browser</h3>' +
          '<a class="mgdb-rec-tsv" href="' + R.escape(browser.url) +
            '" target="_blank" rel="noopener">Open in ' + R.escape(browser.label) + '</a>' +
        '</div>' +
        '<p class="mgdb-rec-block-status">' + R.escape(browser.location) +
          ', the gene model with 1,500 bp either side.</p>' +
        (browser.embed_url
          ? '<iframe class="gene-record-browser" src="' + R.escape(browser.embed_url) +
            '" title="' + R.escape(browser.label + ' view of ' + (overview.name || 'this gene')) +
            '" loading="lazy"></iframe>'
          : '<p class="mgdb-rec-empty">This assembly is served by GBrowse, which cannot be ' +
            'embedded. Use the link above.</p>') +
        '</div>');
    }

    return !!factsHtml;
  }

  function domainTrack(domains, protein) {
    if (!protein || !protein.length_aa) { return ''; }
    var length = protein.length_aa;
    var canonical = domains.filter(function (d) {
      return d.is_canonical && d.start && d.end;
    });
    if (!canonical.length) { return ''; }

    var bars = canonical.map(function (domain, index) {
      var left = ((domain.start - 1) / length) * 100;
      var width = Math.max(((domain.end - domain.start + 1) / length) * 100, 0.6);
      return '<span class="gene-record-domain gene-record-domain-' + (index % 5) + '" ' +
             'style="left:' + left.toFixed(2) + '%;width:' + width.toFixed(2) + '%" ' +
             'title="' + R.escape(domain.name + ' ' + domain.start + '–' + domain.end) + '">' +
             '<span class="mgdb-visually-hidden">' +
             R.escape(domain.name + ', residues ' + domain.start + ' to ' + domain.end) +
             '</span></span>';
    }).join('');

    var legend = canonical.map(function (domain, index) {
      return '<li><span class="gene-record-swatch gene-record-domain-' + (index % 5) + '"></span>' +
             (domain.url ? '<a href="' + R.escape(domain.url) + '" rel="noopener">' + R.escape(domain.name) + '</a>'
                         : R.escape(domain.name)) +
             ' <span class="gene-record-muted">' + domain.start + '–' + domain.end + '</span></li>';
    }).join('');

    return '<figure class="gene-record-track">' +
           '<div class="gene-record-track-bar" role="img" aria-label="Protein domain positions">' +
           bars + '</div>' +
           '<div class="gene-record-track-scale"><span>1</span><span>' + R.number(length) + ' aa</span></div>' +
           '<ul class="gene-record-track-legend">' + legend + '</ul>' +
           '</figure>';
  }

  /* ------------------------------------------------------------------------
     Structure
     ------------------------------------------------------------------------ */

  function renderStructure(structure) {
    if (!structure) { return false; }
    var out = els.structureBody;
    out.innerHTML = '';
    var protein = structure.protein || {};
    var rendered = false;
    var figureDrawn = false;

    /* The figure: every transcript on the genome, the CDS carried down to the
       residues it encodes, and the protein with its InterPro entries and
       sites -- from the gene-models and domains datasets, embedded in the
       record when this assembly has a release. The tables below stay. */
    if (structure.gene_model && window.MGDB && MGDB.geneStructure) {
      var attrs = (payload && payload.data && payload.data.attributes) || {};
      var figureBlock = document.createElement('div');
      figureBlock.className = 'mgdb-rec-block gene-record-structure-block';
      figureBlock.innerHTML =
        '<div class="mgdb-rec-block-head"><h3>Gene model and protein</h3>' +
        (structure.gene_model.release
          ? '<span class="mgdb-rec-block-count">' + R.escape(structure.gene_model.release) + '</span>' : '') +
        '</div><div class="gene-record-structure-figure"></div>';
      out.appendChild(figureBlock);
      figureDrawn = MGDB.geneStructure(figureBlock.querySelector('.gene-record-structure-figure'), {
        gene: {
          name: attrs.name || structure.gene_model.canonical_transcript, symbol: attrs.symbol,
          chromosome: structure.gene_model.chromosome, strand: structure.gene_model.strand,
          start: structure.gene_model.start, end: structure.gene_model.end
        },
        geneModel: structure.gene_model,
        domains: structure.domains || null,
        model: structure.model || null,
        base: ''
      });
      if (!figureDrawn) { figureBlock.parentNode.removeChild(figureBlock); } else { rendered = true; }
    }

    /* Exon and CDS counts from the release, joined onto the transcript rows
       the database supplies, so the table reads the same as the figure. */
    var blocksByName = {};
    ((structure.gene_model && structure.gene_model.transcripts) || []).forEach(function (t) {
      blocksByName[t.id] = t;
    });
    var transcriptItems = (structure.transcripts || []).map(function (t) {
      var b = blocksByName[t.name];
      return b ? Object.assign({}, t, { exon_count: b.exon_count, cds_length_nt: b.cds_length_nt,
                                        protein_length_aa: b.protein ? b.protein.length_aa : null }) : t;
    });

    var factsHtml = R.facts([
      ['Canonical transcript', protein.transcript ? R.escape(protein.transcript) : ''],
      ['Protein', protein.name ? R.escape(protein.name) : ''],
      ['Protein length', protein.length_aa ? R.number(protein.length_aa) + ' aa' : '',
        protein.length_note || '']
    ]);
    if (factsHtml) { out.insertAdjacentHTML('beforeend', factsHtml); rendered = true; }

    rendered = R.collection(out, {
      title: 'Transcripts',
      items: transcriptItems,
      filename: 'gene-transcripts.tsv',
      pageSize: 25,
      columns: [
        { key: 'name', label: 'Transcript', tile: true },
        { key: 'is_canonical', label: 'Canonical',
          get: function (t) { return t.is_canonical ? 'Yes' : 'No'; },
          html: function (t) { return t.is_canonical
            ? '<span class="mgdb-pill mgdb-pill-ok">Canonical</span>'
            : '<span class="mgdb-muted">&mdash;</span>'; } },
        { key: 'protein', label: 'Protein' },
        { key: 'length_bp', label: 'Length (bp)', sort: 'number', numeric: true,
          get: function (t) { return t.length_bp == null ? '' : R.number(t.length_bp); } },
        { key: 'exon_count', label: 'Exons', sort: 'number', numeric: true,
          get: function (t) { return t.exon_count == null ? '' : String(t.exon_count); } },
        { key: 'cds_length_nt', label: 'CDS (nt)', sort: 'number', numeric: true,
          get: function (t) { return t.cds_length_nt == null ? '' : R.number(t.cds_length_nt); } },
        { key: 'protein_length_aa', label: 'Protein (aa)', sort: 'number', numeric: true,
          get: function (t) { return t.protein_length_aa == null ? '' : R.number(t.protein_length_aa); } }
      ]
    }) || rendered;

    /* Drawn to scale when the protein's length is known. Without it the domains
       cannot be placed against the protein, and the table below is all there
       is to show. */
    var trackHtml = figureDrawn ? '' : domainTrack(structure.protein_domains || [], protein);
    if (trackHtml) {
      out.insertAdjacentHTML('beforeend',
        '<div class="mgdb-rec-block"><div class="mgdb-rec-block-head">' +
        '<h3>Protein domains, to scale</h3></div>' + trackHtml + '</div>');
      rendered = true;
    }

    rendered = R.collection(out, {
      title: 'Protein domains',
      items: structure.protein_domains,
      filename: 'gene-protein-domains.tsv',
      columns: [
        { key: 'name', label: 'Domain', tile: true,
          html: function (d) { return d.url ? R.link(d.url, d.name, true) : R.escape(d.name); } },
        { key: 'accession', label: 'Accession' },
        { key: 'start', label: 'Start', sort: 'number', numeric: true,
          get: function (d) { return d.start == null ? '' : String(d.start); } },
        { key: 'end', label: 'End', sort: 'number', numeric: true,
          get: function (d) { return d.end == null ? '' : String(d.end); } },
        { key: 'analysis', label: 'Analysis' },
        { key: 'entry', label: 'InterPro',
          html: function (d) { return d.entry
            ? R.link('https://www.ebi.ac.uk/interpro/entry/InterPro/' + encodeURIComponent(d.entry) + '/', d.entry, true)
            : '<span class="mgdb-muted">&mdash;</span>'; } },
        { key: 'transcript', label: 'Transcript' }
      ]
    }) || rendered;

    if (structure.exon_structure_note) {
      out.insertAdjacentHTML('beforeend',
        '<p class="mgdb-rec-block-status">' + R.escape(structure.exon_structure_note) + '</p>');
    }
    return rendered;
  }

  /* ------------------------------------------------------------------------
     Function
     ------------------------------------------------------------------------ */

  function renderFunction(fn) {
    if (!fn) { return false; }
    var out = els.functionBody;
    out.innerHTML = '';
    var rendered = false;

    /* The figure: the terms placed in the ontology (plant-slim fingerprint,
       evidence, ancestry), the protein's atlas class in its pan-genome
       context, and the explorer's pathways step by step -- from the GO
       index, the domains release, the atlas and the explorer payload, all
       embedded in the record. The tables below stay. */
    if (window.MGDB && MGDB.geneFunction && (fn.go || fn.classes || fn.pathways)) {
      var attrs = (payload && payload.data && payload.data.attributes) || {};
      var figureBlock = document.createElement('div');
      figureBlock.className = 'mgdb-rec-block gene-record-function-block';
      figureBlock.innerHTML =
        '<div class="mgdb-rec-block-head"><h3>Function at a glance</h3>' +
        (fn.go && fn.go.available && fn.go.release
          ? '<span class="mgdb-rec-block-count">GO ' + R.escape(String(fn.go.release).replace(/^releases\//, '')) + '</span>' : '') +
        '</div><div class="gene-record-function-figure"></div>';
      out.appendChild(figureBlock);
      var figureDrawn = MGDB.geneFunction(figureBlock.querySelector('.gene-record-function-figure'), {
        gene: { name: attrs.name, symbol: attrs.symbol },
        fn: fn,
        base: ''
      });
      if (!figureDrawn) { figureBlock.parentNode.removeChild(figureBlock); } else { rendered = true; }
    }

    rendered = R.collection(out, {
      title: 'Ontology terms',
      items: fn.ontology,
      filename: 'gene-ontology-terms.tsv',
      pageSize: 25,
      columns: [
        { key: 'term', label: 'Term', tile: true,
          html: function (t) { return t.url ? R.link(t.url, t.term, true) : R.escape(t.term); } },
        { key: 'name', label: 'Name' },
        { key: 'ontology', label: 'Ontology' },
        { key: 'evidence_label', label: 'Evidence',
          get: function (t) { return t.evidence_label || t.evidence_code || ''; } },
        { key: 'source', label: 'Source' },
        { key: 'attached_to', label: 'Attached to' }
      ]
    }) || rendered;

    rendered = R.collection(out, {
      title: 'Gene products',
      items: fn.gene_products,
      filename: 'gene-products.tsv',
      columns: [
        { key: 'name', label: 'Gene product', tile: true,
          html: function (g) { return g.html ? R.link(g.html, g.name) : R.escape(g.name); } },
        R.urlColumn(function (g) { return g.html; })
      ]
    }) || rendered;

    rendered = R.collection(out, {
      title: 'Protein accessions',
      items: fn.protein_accessions,
      filename: 'gene-protein-accessions.tsv',
      columns: [
        { key: 'accession', label: 'Accession', tile: true,
          html: function (a) { return a.url ? R.link(a.url, a.accession, true) : R.escape(a.accession); } },
        { key: 'database', label: 'Database' },
        { key: 'description', label: 'Description' }
      ]
    }) || rendered;

    return rendered;
  }


  /* ------------------------------------------------------------------------
     Expression, and the eFP viewer

     The section used to lay eight atlas images out at 180px each. Two things
     were wrong with that. An eFP figure is a labelled anatomical diagram and
     none of it is legible at 180px; and the atlas names it was requesting --
     Maize_Atlas_V5, Maize_Seed_V5 and the rest -- name nothing the BAR serves,
     so every one of those eight images was a 500. The names are corrected in
     the API; here the figures are shown one at a time, as large as the column
     allows.
     ------------------------------------------------------------------------ */

  var efpState = { atlas: 0, mode: 'Absolute', atlases: [] };

  function efpShow() {
    var atlas = efpState.atlases[efpState.atlas];
    if (!atlas) { return; }
    var stage = R.byId('gene-record-efp-stage');
    var img = stage.querySelector('img');
    var link = R.byId('gene-record-efp-open');
    stage.classList.remove('is-missing');
    stage.classList.add('is-loading');
    img.alt = atlas.label + ' expression pattern, ' + efpState.mode.toLowerCase() + ' scale';
    img.src = efpState.mode === 'Relative' ? atlas.image_relative : atlas.image;
    if (link) { link.setAttribute('href', atlas.browser); }
    Array.prototype.forEach.call(
      R.byId('gene-record-efp-atlases').querySelectorAll('[data-atlas]'), function (button) {
        button.setAttribute('aria-pressed',
          String(Number(button.getAttribute('data-atlas')) === efpState.atlas));
      });
    Array.prototype.forEach.call(
      R.byId('gene-record-efp-toolbar').querySelectorAll('[data-mode]'), function (button) {
        button.setAttribute('aria-pressed',
          String(button.getAttribute('data-mode') === efpState.mode));
      });
  }

  function renderEfp(out, efp) {
    if (!efp || !efp.available || !efp.atlases || !efp.atlases.length) { return false; }
    efpState.atlases = efp.atlases;
    efpState.atlas = 0;
    efpState.mode = 'Absolute';

    out.insertAdjacentHTML('beforeend',
      '<div class="mgdb-rec-block"><div class="mgdb-rec-block-head"><h3>eFP Browser' +
      '<span class="mgdb-rec-block-count">' + efp.atlases.length + '</span></h3></div>' +
      '<div class="mgdb-rec-toolbar gene-record-efp-toolbar" id="gene-record-efp-toolbar">' +
        '<div class="mgdb-view-toggle" role="group" aria-label="Colour scale">' +
          '<button class="mgdb-view-btn" type="button" data-mode="Absolute" aria-pressed="true">Absolute</button>' +
          '<button class="mgdb-view-btn" type="button" data-mode="Relative" aria-pressed="false">Relative</button>' +
        '</div>' +
        '<a class="mgdb-rec-tsv" id="gene-record-efp-open" href="' + R.escape(efp.browser) +
          '" target="_blank" rel="noopener">Open at the BAR</a>' +
      '</div>' +
      '<div class="gene-record-efp-atlases" id="gene-record-efp-atlases" role="group" aria-label="Atlas">' +
        efp.atlases.map(function (atlas, index) {
          return '<button class="gene-record-efp-atlas" type="button" data-atlas="' + index +
                 '" aria-pressed="' + (index === 0) + '">' + R.escape(atlas.label) + '</button>';
        }).join('') +
      '</div>' +
      '<div class="gene-record-efp-stage is-loading" id="gene-record-efp-stage"><img alt=""></div>' +
      (efp.note ? '<p class="mgdb-rec-block-status">' + R.escape(efp.note) + '</p>' : '') +
      '<p class="mgdb-rec-block-status">' + R.escape(efp.source) + '. ' +
        R.link(efp.eplant, 'Explore this gene in ePlant', true) + '.</p>' +
      '</div>');

    var stage = R.byId('gene-record-efp-stage');
    var img = stage.querySelector('img');
    img.addEventListener('load', function () { stage.classList.remove('is-loading'); });
    img.addEventListener('error', function () {
      stage.classList.remove('is-loading');
      stage.classList.add('is-missing');
    });
    Array.prototype.forEach.call(
      R.byId('gene-record-efp-atlases').querySelectorAll('[data-atlas]'), function (button) {
        button.addEventListener('click', function () {
          efpState.atlas = Number(button.getAttribute('data-atlas'));
          efpShow();
        });
      });
    Array.prototype.forEach.call(
      R.byId('gene-record-efp-toolbar').querySelectorAll('[data-mode]'), function (button) {
        button.addEventListener('click', function () {
          efpState.mode = button.getAttribute('data-mode');
          efpShow();
        });
      });

    efpShow();
    return true;
  }

  function renderExpression(expression) {
    if (!expression) { return false; }
    var out = els.expressionBody;
    out.innerHTML = '';
    var rendered = false;

    /* The profile itself, from the expression release: tiles, a bar per
       sample, the figures by tissue, the top samples, the studies. qTeller
       stays one click away, inside the figure's footer, so the block below
       only repeats the link when the profile could not be drawn. */
    var profileDrawn = false;
    if (expression.profile && window.MGDB && MGDB.geneExpression) {
      var attrs = (payload && payload.data && payload.data.attributes) || {};
      var block = document.createElement('div');
      block.className = 'mgdb-rec-block gene-record-expression-block';
      block.innerHTML =
        '<div class="mgdb-rec-block-head"><h3>Expression profile</h3>' +
        (expression.profile.attributes && expression.profile.attributes.release
          ? '<span class="mgdb-rec-block-count">' + R.escape(expression.profile.attributes.release) + '</span>' : '') +
        '</div><div class="gene-record-expression-figure"></div>';
      out.appendChild(block);
      profileDrawn = MGDB.geneExpression(block.querySelector('.gene-record-expression-figure'), {
        gene: { name: attrs.name, symbol: attrs.symbol },
        profile: expression.profile,
        qteller: expression.qteller && expression.qteller.available ? expression.qteller.url : null
      });
      if (!profileDrawn) { block.parentNode.removeChild(block); } else { rendered = true; }
    }
    if (profileDrawn) {
      rendered = R.collection(out, {
        title: 'Samples',
        items: (expression.profile.sections && expression.profile.sections.samples) || [],
        filename: 'gene-expression.tsv',
        pageSize: 25,
        columns: [
          { key: 'label', label: 'Sample', tile: true },
          { key: 'source', label: 'Study' },
          { key: 'tissue', label: 'Tissue reading' },
          { key: 'condition', label: 'Condition', get: function (s) { return s.condition || ''; } },
          { key: 'assay', label: 'Assay', get: function (s) { return s.assay === 'rna' ? 'RNA' : s.assay; } },
          { key: 'value', label: 'Value', sort: 'number', numeric: true,
            get: function (s) { return s.value == null ? '' : String(s.value); } }
        ]
      }) || rendered;
    } else if (expression.profile_note) {
      out.insertAdjacentHTML('beforeend', '<p class="mgdb-rec-block-status">' + R.escape(expression.profile_note) + '</p>');
    }

    if (!profileDrawn && expression.qteller && expression.qteller.available) {
      out.insertAdjacentHTML('beforeend',
        '<div class="mgdb-rec-block"><div class="mgdb-rec-block-head"><h3>qTeller</h3></div>' +
        '<div class="mgdb-rec-linkrow"><a class="mgdb-button mgdb-button-primary" href="' +
        R.escape(expression.qteller.url) + '" target="_blank" rel="noopener">' +
        'Open the expression atlas</a></div></div>');
      rendered = true;
    }

    rendered = renderEfp(out, expression.efp) || rendered;

    /* Only reasons that say something. `{available: false}` with no `reason`
       is what a locus-only record gets back, and pushing it unfiltered put a
       "Not available for this gene" note on the page whose one line read
       "undefined". */
    var gaps = [];
    if (expression.rnaseq_histogram && !expression.rnaseq_histogram.available) {
      gaps.push(expression.rnaseq_histogram.reason);
    }
    if (expression.proteomics && !expression.proteomics.available) {
      gaps.push(expression.proteomics.reason);
    }
    gaps = gaps.filter(Boolean);
    if (gaps.length) {
      R.notes(out, 'Not available for this gene', gaps.map(function (t) { return { text: t }; }));
      rendered = true;
    }
    if (expression.note) {
      out.insertAdjacentHTML('beforeend',
        '<p class="mgdb-rec-block-status">' + R.escape(expression.note) + '</p>');
    }
    return rendered;
  }


  /* ------------------------------------------------------------------------
     Variation
     ------------------------------------------------------------------------ */

  /* chr4:43,430,007-43,438,753 from the three fields that carry it. */
  function locationText(row) {
    if (!row || row.start == null) { return ''; }
    return (row.chromosome ? row.chromosome + ':' : '') + R.number(row.start) +
      (row.end != null && row.end !== row.start ? '\u2013' + R.number(row.end) : '');
  }

  /* An array of {id, name, html} refs. Joined by name for the TSV and for
     sorting; linked for the cell. Reading one of these as a string is what put
     "[object Object]" in the Stock column. */
  function refNames(refs) {
    return (refs || []).map(function (r) { return r && r.name; }).filter(Boolean).join(', ');
  }

  function refLinks(refs) {
    var list = (refs || []).filter(function (r) { return r && r.name; });
    if (!list.length) { return '<span class="mgdb-muted">\u2014</span>'; }
    return list.map(function (r) {
      return r.html ? R.link(r.html, r.name) : R.escape(r.name);
    }).join(', ');
  }

  function renderVariation(variation) {
    if (!variation) { return false; }
    var out = els.variationBody;
    out.innerHTML = '';
    var rendered = false;

    /* Four column keys here did not exist in the payload and every cell under
       them was empty or "[object Object]": the resource returns
       `gene_structures`, `chromosome`/`start`/`end` and arrays of refs, not
       `structure`, `position`, `html` and strings. */
    rendered = R.collection(out, {
      title: 'Insertions',
      items: variation.insertions,
      filename: 'gene-insertions.tsv',
      pageSize: 25,
      columns: [
        { key: 'name', label: 'Insertion', tile: true,
          html: function (i) {
            var v = (i.variations || [])[0];
            return v && v.html ? R.link(v.html, i.name) : R.escape(i.name);
          } },
        { key: 'source', label: 'Source' },
        { key: 'gene_structures', label: 'Gene structure',
          get: function (i) { return i.gene_structures || ''; } },
        { key: 'position', label: 'Position',
          get: locationText,
          html: function (i) {
            var text = locationText(i);
            return text
              ? '<span class="mgdb-sequence">' + R.escape(text) + '</span>'
              : '<span class="mgdb-muted">Not recorded</span>';
          } },
        { key: 'stocks', label: 'Stock',
          get: function (i) { return refNames(i.stocks); },
          html: function (i) { return refLinks(i.stocks); } }
      ]
    }) || rendered;

    /* `structure` for `gene_structure`: the column was blank on every row of
       every gene. The Reference column is gone at the group's request; the
       study is still in the API payload and in the TSV below. */
    rendered = R.collection(out, {
      title: 'SNPs and traits',
      items: variation.snp_traits,
      filename: 'gene-snp-traits.tsv',
      pageSize: 25,
      columns: [
        { key: 'snp', label: 'SNP', tile: true },
        { key: 'trait', label: 'Trait' },
        { key: 'gene_structure', label: 'Structure',
          get: function (t) { return t.gene_structure || ''; },
          html: function (t) { return t.gene_structure
            ? '<span title="' + R.escape(t.structure_description || '') + '">' +
              R.escape(t.gene_structure) + '</span>'
            : '<span class="mgdb-muted">\u2014</span>'; } },
        { key: 'position', label: 'Position', sort: 'number', numeric: true,
          get: function (t) { return t.position == null ? '' : R.number(t.position); } }
      ]
    }) || rendered;

    rendered = R.collection(out, {
      title: 'Alleles and variations',
      items: variation.alleles,
      filename: 'gene-alleles.tsv',
      pageSize: 25,
      columns: [
        { key: 'name', label: 'Allele', tile: true,
          html: function (a) { return a.html ? R.link(a.html, a.name) : R.escape(a.name); } },
        { key: 'type', label: 'Type' },
        R.urlColumn(function (a) { return a.html; })
      ]
    }) || rendered;

    return rendered;
  }

  /* ------------------------------------------------------------------------
     Pan-gene
     ------------------------------------------------------------------------ */

  function renderPanGene(pan) {
    if (!pan || !pan.pan_gene || !pan.pan_gene.name) { return false; }
    var out = els.panGeneBody;
    out.innerHTML = '';
    var pg = pan.pan_gene;

    out.insertAdjacentHTML('beforeend', R.facts([
      ['Pan-gene', R.link('/pan_gene_center/pan_gene/' + encodeURIComponent(pg.name), pg.name)],
      ['Members', num(pg.member_count), 'gene models across all assemblies'],
      ['Assemblies', num(pan.assembly_count), 'genomes where this gene was found'],
      ['Analysis', pg.analysis ? R.escape(pg.analysis) : '']
    ]));

    /* The presence strip. This is the thing MaizeGDB has that nobody else
       does: whether a gene is present across cultivated maize only, or across
       the wild Zea species too. */
    if (pan.species && pan.species.length) {
      var strip = pan.species.map(function (group) {
        var cells = group.assemblies.map(function (assembly) {
          return '<li title="' + R.escape(assembly) + '"><span class="mgdb-visually-hidden">' +
                 R.escape(assembly) + '</span></li>';
        }).join('');
        return '<div class="gene-record-species">' +
          '<h4><em>' + R.escape(group.species) + '</em> <span class="gene-record-muted">' +
          group.count + '</span></h4>' +
          '<ul class="gene-record-presence">' + cells + '</ul></div>';
      }).join('');
      out.insertAdjacentHTML('beforeend',
        '<div class="mgdb-rec-block"><div class="mgdb-rec-block-head"><h3>Present in' +
        '<span class="mgdb-rec-block-count">' + num(pan.assembly_count) + '</span></h3></div>' +
        '<p class="mgdb-rec-block-status">One square per assembly in which this gene was found.</p>' +
        strip + '</div>');
    }

    /* The pangenome view. Genomic sequence at the B73 gene model's location
       across the assemblies, from the cactus-Minigraph pipeline. There is no
       database row saying whether a given gene has one, so the figure removes
       itself if the image does not load. */
    if (pan.pangenome_image && pan.pangenome_image.url) {
      var pv = pan.pangenome_image;
      var pvBlock = document.createElement('div');
      pvBlock.className = 'mgdb-rec-block gene-record-pangenome';
      pvBlock.innerHTML =
        '<div class="mgdb-rec-block-head"><h3>Pangenome view</h3>' +
        '<span class="mgdb-rec-block-count">' + R.escape(pv.gene_model) + '</span></div>' +
        '<figure class="gene-record-pangenome-figure">' +
          '<a href="' + R.escape(pv.url) + '" target="_blank" rel="noopener">' +
            '<img src="' + R.escape(pv.url) + '" loading="lazy" alt="Genomic sequence at ' +
            R.escape(pv.gene_model) + ' across multiple maize assemblies, drawn as a ' +
            'pangenome subgraph">' +
          '</a>' +
          '<figcaption>' + R.escape(pv.description) + ' Produced by the ' +
            R.link(pv.pipeline_url, pv.pipeline, true) + ' at MaizeGDB in 2026.' +
          '</figcaption>' +
        '</figure>';
      out.appendChild(pvBlock);
      pvBlock.querySelector('img').addEventListener('error', function () {
        if (pvBlock.parentNode) { pvBlock.parentNode.removeChild(pvBlock); }
      });
    }

    R.collection(out, {
      title: 'Related gene models in maize',
      items: pan.members,
      filename: 'gene-pan-gene-members.tsv',
      pageSize: 25,
      columns: [
        { key: 'name', label: 'Gene model', tile: true,
          html: function (m) { return (m.html ? R.link(m.html, m.name) : R.escape(m.name)) +
                 (m.is_current_record ? ' <span class="mgdb-pill mgdb-pill-ok">This record</span>' : ''); } },
        { key: 'assembly', label: 'Assembly' },
        { key: 'annotation', label: 'Annotation' },
        R.urlColumn(function (m) { return m.html; })
      ]
    });

    return true;
  }

  /* Orthologs, and phylostrata beneath them.

     Every column but Species was reading a key the payload does not have --
     `name`, `source`, `relationship` and `url` against `identifier`,
     `analysis`, `kind` and nothing -- so the table listed one italic species
     name per row and three empty cells. The identifiers were there the whole
     time.

     The rows come from the whole pan-gene, not only from this gene model, which
     is what the legacy page's Pan-genome tab added on top of its Overview tab's
     four species. `via` says which member of the cluster carries the call, and
     a direct call is marked so the two are not confused. */
  function renderOrthologs(orthologs) {
    if (!orthologs) { return false; }
    var out = els.orthologsBody;
    out.innerHTML = '';
    var rendered = false;

    rendered = R.collection(out, {
      title: 'Orthologs in other species',
      items: orthologs.orthologs,
      filename: 'gene-orthologs.tsv',
      pageSize: 25,
      columns: [
        { key: 'identifier', label: 'Gene', tile: true,
          html: function (o) { return o.url
            ? R.link(o.url, o.identifier, true) : R.escape(o.identifier); } },
        { key: 'species', label: 'Species',
          html: function (o) { return o.species
            ? '<em>' + R.escape(o.species) + '</em>'
            : '<span class="mgdb-muted">\u2014</span>'; } },
        { key: 'analysis', label: 'Analysis' },
        { key: 'via', label: 'Called on',
          get: function (o) { return o.is_direct ? 'This gene model' : (o.via || ''); },
          html: function (o) {
            if (o.is_direct) { return '<span class="mgdb-pill mgdb-pill-ok">This gene model</span>'; }
            return o.via_html
              ? R.link(o.via_html, o.via) + ' <span class="mgdb-muted">(pan-gene)</span>'
              : R.escape(o.via || '');
          } }
      ]
    }) || rendered;

    rendered = renderPhylostrata(out, orthologs.phylostrata) || rendered;
    return rendered;
  }

  /* The phylostrata figure. One PNG per B73 v5 protein-coding gene model,
     published by the phylostratR analysis; there is no database row for it, so
     a gene the analysis did not cover is a load failure rather than an empty
     result, and the block removes itself when the image does not arrive. */
  function renderPhylostrata(out, ps) {
    if (!ps || !ps.image) { return false; }
    var block = document.createElement('div');
    block.className = 'mgdb-rec-block gene-record-phylostrata';
    block.innerHTML =
      '<div class="mgdb-rec-block-head"><h3>Phylostrata</h3>' +
      '<span class="mgdb-rec-block-count">1&ndash;' + ps.scale.max + '</span></div>' +
      '<figure class="gene-record-phylostrata-figure">' +
        /* The full 2400x580 PNG, not the 670px `downsized/` JPG. The figure
           fills the column -- about 1,150px on a desktop -- so the thumbnail
           was being scaled UP and read as out of focus, while the same picture
           opened at full size looked fine. At 290 KB against 26 KB it is the
           more expensive of the two, which is what `loading="lazy"` is for:
           nothing is fetched until the section is scrolled to. */
        '<a href="' + R.escape(ps.image) + '" target="_blank" rel="noopener">' +
          '<img src="' + R.escape(ps.image) + '" alt="Phylostratigraphy of ' +
          R.escape(ps.gene_model) + ', showing the level at which each part of the ' +
          'protein is conserved" loading="lazy">' +
        '</a>' +
        '<figcaption>' + R.escape(ps.description) + '</figcaption>' +
      '</figure>' +
      '<div class="mgdb-rec-linkrow">' +
        '<a class="mgdb-button mgdb-button-secondary" href="' + R.escape(ps.details_url) +
          '" target="_blank" rel="noopener">Phylostrata details for ' +
          R.escape(ps.gene_model) + '</a>' +
        '<a class="mgdb-button mgdb-button-quiet" href="' + R.escape(ps.about_url) +
          '" target="_blank" rel="noopener">About the Phylostrata tool</a>' +
      '</div>';
    out.appendChild(block);

    /* The thumbnail is the only evidence the analysis covered this gene. If it
       404s the figure goes, and the two links go with it -- they would lead to
       a page with nothing on it. */
    var img = block.querySelector('img');
    img.addEventListener('error', function () {
      if (block.parentNode) { block.parentNode.removeChild(block); }
      /* A gene with no orthologs and no phylostrata image has nothing left in
         this section. Leaving it visible gives the tab bar an entry that
         scrolls to an empty heading. */
      if (!out.children.length) { hideSection('gene-record-orthologs'); }
    });
    return true;
  }

  /* ------------------------------------------------------------------------
     Classical gene, and the three locus sections the legacy page had
     ------------------------------------------------------------------------ */

  function renderLocus(locus) {
    if (!locus || !locus.id) { return false; }
    var out = els.locusBody;
    out.innerHTML = '';

    out.insertAdjacentHTML('beforeend', R.facts([
      ['Symbol', locus.name ? R.escape(locus.name) : ''],
      ['Full name', locus.full_name ? R.escape(locus.full_name) : ''],
      ['Type', locus.type ? R.escape(locus.type) : ''],
      ['Chromosome bin', locus.bin ? R.escape(locus.bin) : ''],
      ['Locus record', R.link(locus.locus_html, 'Open the locus record')]
    ]));

    /* c.text, not c.value: the field is `text` and reading `value` rendered ten
       empty notes with nothing but their labels. c.reference is a ref object,
       not a string, so it is linked rather than concatenated. */
    R.notes(out, 'Curator notes', (locus.comments || []).map(function (c) {
      return {
        text: c.text,
        meta: [
          c.label,
          c.reference ? 'Source: ' + (R.refLink(c.reference) || R.escape(c.reference.name)) : '',
          c.authority ? 'Authority: ' + R.escape(c.authority) : ''
        ]
      };
    }));

    /* Every annotation of this gene, in every assembly. B73 alone has seven,
       and this is where a reader compares them.

       The title names B73 because that is what the rows are: of the ~22,700
       loci carrying a gene model, all but about fifty carry only B73
       annotations. It is read off the rows rather than assumed, so the fifty
       get an accurate heading instead of a wrong one. */
    var models = locus.associated_gene_models || [];
    var allB73 = models.length && models.every(function (m) {
      return (m.assembly || '').indexOf('B73') !== -1;
    });
    R.collection(out, {
      title: (allB73 ? 'B73 gene models' : 'Gene models') + ' for this classical gene',
      items: models,
      filename: 'gene-associated-models.tsv',
      pageSize: 25,
      columns: [
        { key: 'name', label: 'Gene model', tile: true,
          html: function (m) { return R.link('/gene_center/gene/' + encodeURIComponent(m.name), m.name); } },
        { key: 'assembly', label: 'Assembly' },
        { key: 'annotation', label: 'Annotation' },
        { key: 'is_current', label: 'Current in its annotation',
          get: function (m) { return m.is_current ? 'Yes' : 'No'; },
          html: function (m) { return m.is_current
            ? '<span class="mgdb-pill mgdb-pill-ok">Current</span>'
            : '<span class="mgdb-pill mgdb-pill-warn">Superseded</span>'; } }
      ]
    });

    R.collection(out, {
      title: 'Phenotypes',
      items: locus.phenotypes,
      filename: 'gene-phenotypes.tsv',
      pageSize: 25,
      columns: [
        { key: 'name', label: 'Phenotype', tile: true,
          html: function (p) { return R.link('/data_center/phenotype?id=' + p.id, p.name); } },
        R.urlColumn(function (p) { return '/data_center/phenotype?id=' + p.id; })
      ]
    });

    R.collection(out, {
      title: 'Related loci',
      items: locus.related_loci,
      filename: 'gene-related-loci.tsv',
      columns: [
        { key: 'name', label: 'Locus', tile: true,
          html: function (l) { return R.link('/data_center/locus?id=' + l.id, l.name); } },
        { key: 'qualifier', label: 'Relationship' }
      ]
    });

    return true;
  }

  /* Mutant phenotype images.

     The shared gallery every record page with pictures uses: a card per image
     carrying the photograph, the allele it is filed against, and its caption --
     which on this page is the whole point of the picture and is why the legacy
     "Captions" toggle is gone. The same block offers the rows as a table and a
     TSV; the gallery is its default view. */
  function renderImages(locus) {
    var items = (locus && locus.images) || [];
    if (!items.length) { return false; }
    return R.images(els.imagesBody, items.map(function (image) {
      return {
        url: image.url,
        caption: image.caption || '',
        title: (image.variation && image.variation.name) || 'Image',
        category: image.variation_type || 'Image',
        record: (image.variation && image.variation.html) || ''
      };
    }), 'gene-record-image-dialog', {
      /* Title case, matching the section heading it sits under and the tab
         that names it. */
      title: 'Mutant Phenotype Images',
      filename: 'gene-mutant-phenotype-images.tsv'
    });
  }

  /* Seed stocks carrying an allele of this gene. A Stock Center row can be
     ordered and the rest cannot, which is the one thing the table has to make
     obvious -- the legacy page said it by bolding the name. */
  function renderStocks(locus) {
    return R.collection(els.stocksBody, {
      title: 'Stocks',
      items: (locus && locus.stocks) || [],
      filename: 'gene-stocks.tsv',
      pageSize: 25,
      columns: [
        { key: 'name', label: 'Stock', tile: true,
          html: function (st) { return R.link(st.html, st.name) +
            (st.from_stock_center
              ? ' <span class="mgdb-pill mgdb-pill-ok">Stock Center</span>' : ''); } },
        { key: 'full_name', label: 'Description' },
        { key: 'type', label: 'Type' },
        { key: 'alleles', label: 'Alleles' },
        { key: 'available_from', label: 'Available from' },
        R.urlColumn(function (st) { return st.html; })
      ]
    });
  }

  function renderMap(locus) {
    return R.collection(els.mapBody, {
      title: 'Map coordinates',
      items: (locus && locus.map_positions) || [],
      filename: 'gene-map-positions.tsv',
      pageSize: 25,
      columns: [
        { key: 'map', label: 'Map', tile: true },
        { key: 'position', label: 'Position', sort: 'number', numeric: true,
          get: function (m) { return m.position == null ? '' : String(m.position); } },
        { key: 'bin', label: 'Bin' },
        { key: 'bin2', label: 'Bin 2' },
        { key: 'is_backbone', label: 'Backbone',
          get: function (m) { return m.is_backbone ? 'Yes' : 'No'; },
          html: function (m) { return m.is_backbone
            ? '<span class="mgdb-pill mgdb-pill-ok">Backbone</span>'
            : '<span class="mgdb-muted">\u2014</span>'; } }
      ]
    });
  }

  /* Nearby loci. The legacy page fetched these again from the server every time
     the reader changed the window, through a control that had been commented
     out as broken since 2013. The API returns the widest window once and the
     control filters what is already here. */
  var nearbyAll = [];

  function nearbyRender(window_cm) {
    var visible = nearbyAll.filter(function (n) {
      return n.distance_cm === null || n.distance_cm <= window_cm;
    });
    var body = R.byId('gene-record-nearby-list');
    if (!body) { return; }
    body.innerHTML = '';
    R.collection(body, {
      title: 'Loci within ' + window_cm + ' cM',
      items: visible,
      filename: 'gene-nearby-loci.tsv',
      pageSize: 25,
      columns: [
        { key: 'name', label: 'Locus', tile: true,
          html: function (n) { return (n.html ? R.link(n.html, n.name) : R.escape(n.name)) +
                 (n.is_self ? ' <span class="mgdb-pill mgdb-pill-ok">This gene</span>' : ''); } },
        { key: 'map', label: 'Map' },
        { key: 'position', label: 'Position', sort: 'number', numeric: true,
          get: function (n) { return n.position == null ? '' : String(n.position); } },
        { key: 'distance_cm', label: 'Distance (cM)', sort: 'number', numeric: true,
          get: function (n) { return n.distance_cm == null ? '' : String(n.distance_cm); } }
      ]
    });
  }

  function renderNearby(locus) {
    var list = (locus && locus.nearby_loci) || [];
    if (!list.length) { return false; }
    nearbyAll = list;
    var maxWindow = (locus && locus.nearby_window_cm) || 10;
    var choices = [1, 2, 5, 10].filter(function (c) { return c <= maxWindow; });

    els.nearbyBody.innerHTML =
      '<div class="mgdb-rec-toolbar" id="gene-record-nearby-toolbar">' +
        '<div class="mgdb-view-toggle" role="group" aria-label="Window">' +
          choices.map(function (c) {
            return '<button class="mgdb-view-btn" type="button" data-window="' + c +
                   '" aria-pressed="' + (c === maxWindow) + '">\u00b1' + c + ' cM</button>';
          }).join('') +
        '</div>' +
      '</div>' +
      '<div id="gene-record-nearby-list"></div>';

    Array.prototype.forEach.call(
      els.nearbyBody.querySelectorAll('[data-window]'), function (button) {
        button.addEventListener('click', function () {
          Array.prototype.forEach.call(els.nearbyBody.querySelectorAll('[data-window]'), function (b) {
            b.setAttribute('aria-pressed', String(b === button));
          });
          nearbyRender(Number(button.getAttribute('data-window')));
        });
      });

    nearbyRender(maxWindow);
    return true;
  }

  var GENETIC_KINDS = [
    ['primer', 'Primers and enzymes', 'Primer'],
    ['bac', 'Related BACs', 'BAC'],
    ['gel_pattern', 'Gel patterns', 'Gel pattern'],
    ['map_score', 'Map scores', 'Map score'],
    ['recombination', 'Recombination data', 'Recombination']
  ];

  function renderGenetic(locus) {
    var list = (locus && locus.genetic) || [];
    if (!list.length) { return false; }
    var out = els.geneticBody;
    out.innerHTML = '';
    var rendered = false;

    GENETIC_KINDS.forEach(function (spec) {
      var items = list.filter(function (g) { return g.kind === spec[0]; });
      var columns = [
        { key: 'name', label: spec[2], tile: true,
          html: function (g) { return g.html ? R.link(g.html, g.name) : R.escape(g.name); } }
      ];
      if (spec[0] === 'primer') {
        columns.push({ key: 'detail', label: 'Sequence',
          html: function (g) { return g.detail
            ? '<span class="mgdb-sequence">' + R.escape(g.detail) + '</span>'
            : '<span class="mgdb-muted">Not recorded</span>'; } });
      }
      /* Four of the five kinds carry a name and nothing else, and a one-column
         table is not a table. The MaizeGDB id is the second real fact each of
         them has -- not a constant repeated down the column. */
      columns.push({ key: 'id', label: 'MaizeGDB ID', sort: 'number', numeric: true,
        get: function (g) { return g.id == null ? '' : String(g.id); },
        html: function (g) { return g.id == null ? '\u2014'
          : '<span class="mgdb-sequence">' + g.id + '</span>'; } });
      columns.push(R.urlColumn(function (g) { return g.html; }));
      rendered = R.collection(out, {
        title: spec[1],
        items: items,
        filename: 'gene-' + spec[0] + '.tsv',
        pageSize: 25,
        columns: columns
      }) || rendered;
    });

    return rendered;
  }

  /* ------------------------------------------------------------------------
     Sequences, cross-references, model quality
     ------------------------------------------------------------------------ */

  function renderSequences(seq) {
    if (!seq || !seq.set) { return false; }
    var out = els.sequencesBody;
    out.innerHTML = '';

    out.insertAdjacentHTML('beforeend', R.facts([
      ['Annotation set', R.escape(seq.set)],
      ['Assembly', seq.assembly ? R.escape(seq.assembly) : ''],
      ['Whole gene', seq.genomic
        ? R.link(seq.genomic, 'Genomic FASTA', true) : '', 'the model and its introns']
    ]));

    /* One row per transcript, one column per sequence type. CDS was missing
       from this section: it is the coding sequence without the UTRs, a
       different thing from cDNA, and it is what most people mean when they ask
       for the sequence of a gene. */
    R.collection(out, {
      title: 'Transcript sequences',
      items: seq.transcripts,
      filename: 'gene-sequences.tsv',
      pageSize: 25,
      columns: [
        { key: 'name', label: 'Transcript', tile: true,
          html: function (t) { return R.escape(t.name) +
                 (t.canonical ? ' <span class="mgdb-pill mgdb-pill-ok">Canonical</span>' : ''); } },
        { key: 'cds', label: 'CDS', sort: false,
          get: function (t) { return t.cds || ''; },
          html: function (t) { return t.cds ? R.link(t.cds, 'CDS', true) : '\u2014'; } },
        { key: 'cdna', label: 'cDNA', sort: false,
          get: function (t) { return t.cdna || ''; },
          html: function (t) { return t.cdna ? R.link(t.cdna, 'cDNA', true) : '\u2014'; } },
        { key: 'protein_url', label: 'Protein', sort: false,
          get: function (t) { return t.protein_url || ''; },
          html: function (t) { return t.protein_url
            ? R.link(t.protein_url, t.protein || 'Protein', true) : '\u2014'; } }
      ]
    });

    var downloads = (seq.downloads || []).filter(Boolean);
    if (downloads.length) {
      out.insertAdjacentHTML('beforeend',
        '<div class="mgdb-rec-block"><div class="mgdb-rec-block-head"><h3>Bulk downloads' +
        '<span class="mgdb-rec-block-count">' + downloads.length + '</span></h3></div>' +
        '<div class="mgdb-rec-linkrow">' + downloads.map(function (url) {
          return '<a class="mgdb-button mgdb-button-secondary" href="' + R.escape(url) +
                 '" target="_blank" rel="noopener">Every sequence for this assembly ' +
                 '</a>';
        }).join('') + '</div></div>');
    }

    if (seq.note) {
      out.insertAdjacentHTML('beforeend',
        '<p class="mgdb-rec-block-status">' + R.escape(seq.note) + '</p>');
    }
    return true;
  }

  function renderXrefs(section) {
    return R.collection(els.xrefsBody, {
      title: 'Cross-references',
      items: (section && section.xrefs) || [],
      filename: 'gene-cross-references.tsv',
      pageSize: 25,
      columns: [
        { key: 'accession', label: 'Accession', tile: true,
          html: function (x) { return x.url ? R.link(x.url, x.accession, true) : R.escape(x.accession); } },
        { key: 'database', label: 'Database' },
        { key: 'description', label: 'Description' },
        { key: 'url', label: 'URL', sort: false, get: function (x) { return x.url || ''; },
          html: function (x) { return x.url ? R.link(x.url, x.url, true) : '\u2014'; } }
      ]
    });
  }

  /* A score as a number is only half an answer; the other half is where it
     sits. Each metric gets one track of the same width: the genome-wide
     range from the score index (or the metric's own scale when the index is
     not on file), the 5th-95th percentile band, the median tick, and a
     circle where this gene falls. */
  function fmtScore(v) {
    if (v == null || isNaN(v)) { return '\u2014'; }
    var n = Number(v);
    if (n === 0 || n === 1 || n === 100) { return String(n); }
    if (Math.abs(n) >= 10) { return n.toFixed(1); }
    if (Math.abs(n) >= 1) { return n.toFixed(2); }
    if (Math.abs(n) < 0.001) { return '<0.001'; }
    return n.toPrecision(3).replace(/\.?0+$/, '');
  }
  function scoreStanding(s) {
    var r = s.range;
    if (!r || r.p5 == null) { return null; }
    var v = s.value;
    if (v >= r.p95) { return 'above 95% of scored features'; }
    if (v >= r.p75) { return 'in the top quarter'; }
    if (v >= r.p50) { return 'above the median'; }
    if (v >= r.p25) { return 'below the median'; }
    if (v >= r.p5) { return 'in the bottom quarter'; }
    return 'below 95% of scored features';
  }
  function scoreTone(s) {
    var r = s.range;
    if (!s.better || !r || r.p25 == null) { return 'neutral'; }
    var v = s.value;
    if (s.better === 'high') { return v >= r.p50 ? 'good' : (v < r.p25 ? 'poor' : 'mid'); }
    return v <= r.p50 ? 'good' : (v > r.p75 ? 'poor' : 'mid');
  }
  function scoreRangeHtml(rows) {
    var anyRange = rows.some(function (s) { return s.range && s.range.n; });
    var n = anyRange ? rows.filter(function (s) { return s.range && s.range.n; })[0].range.n : 0;
    return '<div class="gene-score-ranges">' + rows.map(function (s) {
      var lo = null, hi = null, src = null;
      if (s.range && s.range.min != null && s.range.max != null && s.range.max > s.range.min) { lo = s.range.min; hi = s.range.max; src = 'range'; }
      else if (s.scale) { lo = s.scale.min; hi = s.scale.max; src = 'scale'; }
      function pct(v) { return Math.max(0, Math.min(100, 100 * (v - lo) / (hi - lo))); }
      var tone = scoreTone(s);
      var standing = scoreStanding(s);
      var track = '';
      if (lo != null) {
        var r = s.range || {};
        var title = (s.label || s.metric) + ': ' + fmtScore(s.value) + ' on a ' + fmtScore(lo) + ' to ' + fmtScore(hi) + ' ' + (src === 'range' ? 'genome-wide range' : 'scale') +
                    (standing ? ', ' + standing : '');
        track = '<div class="gene-score-track" role="img" aria-label="' + R.escape(title) + '" title="' + R.escape(title) + '">' +
          (src === 'range' && r.p5 != null ? '<span class="gene-score-band" style="left:' + pct(r.p5).toFixed(1) + '%;width:' + (pct(r.p95) - pct(r.p5)).toFixed(1) + '%" title="5th to 95th percentile"></span>' : '') +
          (src === 'range' && r.p50 != null ? '<span class="gene-score-median" style="left:' + pct(r.p50).toFixed(1) + '%" title="median ' + fmtScore(r.p50) + '"></span>' : '') +
          '<span class="gene-score-dot is-' + tone + '" style="left:' + pct(s.value).toFixed(1) + '%"></span>' +
          '</div>' +
          '<div class="gene-score-ends"><span>' + fmtScore(lo) + (src === 'range' ? ' <small>min</small>' : '') + '</span>' +
          (src === 'range' && r.p50 != null ? '<span class="gene-score-ends-mid"><small>median</small> ' + fmtScore(r.p50) + '</span>' : '') +
          '<span>' + fmtScore(hi) + (src === 'range' ? ' <small>max</small>' : '') + '</span></div>';
      } else {
        track = '<p class="mgdb-muted gene-score-noscale">No scale on file for this metric.</p>';
      }
      return '<div class="gene-score-row">' +
        '<div class="gene-score-head">' +
          '<span class="gene-score-label">' + R.escape(s.label || s.metric) + '</span>' +
          '<span class="gene-score-analysis">' + R.escape(s.analysis || '') + (s.version ? ' ' + R.escape(s.version) : '') + '</span>' +
          '<span class="gene-score-value is-' + tone + '">' + fmtScore(s.value) + '</span>' +
        '</div>' +
        track +
        '<p class="gene-score-note">' + R.escape(s.interpretation || '') + (standing ? ' <span class="gene-score-standing">' + R.escape(standing) + '</span>' : '') + '</p>' +
      '</div>';
    }).join('') + '</div>' +
    '<p class="mgdb-rec-block-status">' + (anyRange
      ? 'Ranges, band (5th to 95th percentile) and median are over every feature the analysis scored, ' + R.number(n) + ' and up, across the annotations it was run on. A circle is green when the score sits on the better side of the median, gold between the median and the poorer quartile, wine beyond it; blue metrics describe the protein rather than judge the model.'
      : 'Tracks show each metric\'s own scale; genome-wide ranges are not on this host.') + '</p>';
  }

  function renderProvenance(structure) {
    var scores = (structure && structure.scores) || [];
    return R.collection(els.provenanceBody, {
      title: 'Gene model scores',
      items: scores,
      filename: 'gene-model-scores.tsv',
      pageSize: 'all',
      view: 'range',
      views: [{
        key: 'range', label: 'Range',
        icon: '<svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="1" y="7" width="14" height="2" rx="1" fill="currentColor"/><circle cx="10" cy="8" r="3.5" fill="currentColor"/></svg>',
        render: scoreRangeHtml
      }],
      columns: [
        { key: 'label', label: 'Score', tile: true,
          get: function (s) { return s.label || s.metric; } },
        { key: 'analysis', label: 'Analysis',
          get: function (s) { return (s.analysis || '') + (s.version ? ' ' + s.version : ''); } },
        { key: 'value', label: 'Value', sort: 'number', numeric: true, get: function (s) { return fmtScore(s.value); } },
        { key: 'range', label: 'Genome-wide', sort: false,
          get: function (s) { return s.range && s.range.min != null ? fmtScore(s.range.min) + '\u2013' + fmtScore(s.range.max) + ', median ' + fmtScore(s.range.p50) : (s.scale ? 'scale ' + s.scale.min + '\u2013' + s.scale.max : ''); } },
        { key: 'interpretation', label: 'What it means',
          get: function (s) { var st = scoreStanding(s); return (s.interpretation || '') + (st ? ' (' + st + ')' : ''); } }
      ]
    });
  }

  /* ------------------------------------------------------------------------
     Metrics and figures
     ------------------------------------------------------------------------ */

  function renderMetrics(counts, sections) {
    R.metrics(els.metricsBody, [
      ['Ontology terms', 'Function', counts.ontology, 'GO and other ontology terms attached to this gene.', 'green'],
      ['Insertions', 'Mutants', counts.insertions, 'Insertion alleles recorded in this gene.', 'amber'],
      ['SNP associations', 'Traits', counts.snp_traits, 'SNPs in this gene with a recorded trait association.', 'blue'],
      ['References', 'Literature', counts.references, 'Curated publications associated with this gene.', 'burgundy']
    ]);

    var series = [
      ['Transcripts', counts.transcripts], ['Protein domains', counts.protein_domains],
      ['Ontology terms', counts.ontology], ['Insertions', counts.insertions],
      ['SNP associations', counts.snp_traits], ['Alleles', counts.alleles],
      ['Map positions', counts.map_positions], ['Gene products', counts.gene_products],
      ['Stocks', counts.stocks], ['Images', counts.images],
      ['Cross-references', counts.xrefs], ['Pan-gene members', counts.pan_gene_members],
      ['Gene models of this gene', counts.locus_gene_models],
      ['Curator notes', counts.comments], ['References', counts.references]
    ];
    var height = R.connectionsHeight(series);

    var refs = (sections.references && sections.references.references) || [];
    if (R.yearsChart('gene-record-years-chart', 'gene-record-years-caption',
                     'gene-record-years-figure', refs, height)) {
      R.watchChartWidth('gene-record-years-chart');
    }

    R.connectionsChart('gene-record-connections-chart', 'gene-record-connections-caption',
                       'gene-record-connections-figure', series, height);
    return true;
  }

  /* ------------------------------------------------------------------------
     Assembly
     ------------------------------------------------------------------------ */

  var TAB_COUNTS = {
    'gene-record-structure': ['transcripts', 'protein_domains'],
    'gene-record-function': ['ontology', 'gene_products'],
    'gene-record-variation': ['insertions', 'snp_traits', 'alleles'],
    'gene-record-pan_gene': ['pan_gene_members'],
    'gene-record-locus': ['locus_gene_models', 'comments'],
    'gene-record-images': ['images'],
    'gene-record-stocks': ['stocks'],
    'gene-record-map': ['map_positions'],
    'gene-record-references': ['references'],
    'gene-record-xrefs': ['xrefs']
  };

  var LABELS = {
    'gene-record-overview': 'Overview',
    'gene-record-structure': 'Structure',
    'gene-record-function': 'Function',
    'gene-record-expression': 'Expression',
    'gene-record-variation': 'Variation',
    'gene-record-pan_gene': 'Pan-gene',
    'gene-record-orthologs': 'Orthologs',
    'gene-record-locus': 'Classical gene',
    'gene-record-images': 'Mutant Phenotype Images',
    'gene-record-stocks': 'Stocks',
    'gene-record-map': 'Map coordinates',
    'gene-record-nearby': 'Nearby loci',
    'gene-record-genetic': 'Additional genetic information',
    'gene-record-references': 'References',
    'gene-record-sequences': 'Sequences and downloads',
    'gene-record-xrefs': 'Cross-references',
    'gene-record-provenance': 'Gene model scores',
    'gene-record-metrics': 'Metrics',
    'gene-record-resources': 'Related resources',
    'gene-record-api': 'API'
  };

  function render(response) {
    payload = response;
    var data = response.data || {};
    var sections = data.sections || {};
    var meta = response.meta || {};
    var counts = meta.counts || {};

    R.show(els.loading, false);
    R.show(els.error, false);

    renderHeader(data, sections);

    /* Half the classical genes in MaizeGDB have no gene model at all, and seven
       of this page's sections are about a gene model rather than about the
       gene: there is no structure to draw, no expression to read, no pan-gene,
       no orthologs, no sequence to download and no model scores without one.
       The resource returns those sections as empty shells rather than omitting
       them -- a client should be able to tell "nothing here" from "not asked
       for" -- so the page decides, and a section it does not render never
       reaches the section tabs. */
    var hasGeneModel = !!(data.attributes && data.attributes.name);

    var rendered = [];
    if (renderOverview(sections.overview)) { rendered.push('gene-record-overview'); }
    if (hasGeneModel && renderStructure(sections.structure)) { rendered.push('gene-record-structure'); }
    if (renderFunction(sections.function)) { rendered.push('gene-record-function'); }
    if (hasGeneModel && renderExpression(sections.expression)) { rendered.push('gene-record-expression'); }
    if (renderVariation(sections.variation)) { rendered.push('gene-record-variation'); }
    if (hasGeneModel && renderPanGene(sections.pan_gene)) { rendered.push('gene-record-pan_gene'); }
    if (hasGeneModel && renderOrthologs(sections.orthologs)) { rendered.push('gene-record-orthologs'); }
    if (renderLocus(sections.locus)) { rendered.push('gene-record-locus'); }
    if (renderImages(sections.locus)) { rendered.push('gene-record-images'); }
    if (renderStocks(sections.locus)) { rendered.push('gene-record-stocks'); }
    if (renderMap(sections.locus)) { rendered.push('gene-record-map'); }
    if (renderNearby(sections.locus)) { rendered.push('gene-record-nearby'); }
    if (renderGenetic(sections.locus)) { rendered.push('gene-record-genetic'); }

    if (R.references(els.referencesBody, (sections.references || {}).references,
                     els.referencesSection, 'gene-ref')) {
      rendered.push('gene-record-references');
    }

    if (hasGeneModel && renderSequences(sections.sequences)) { rendered.push('gene-record-sequences'); }
    if (renderXrefs(sections.xrefs)) { rendered.push('gene-record-xrefs'); }
    if (hasGeneModel && renderProvenance(sections.structure)) { rendered.push('gene-record-provenance'); }

    rendered.forEach(function (id) { R.show(R.byId(id), true); });

    // Revealed before the charts are drawn: Plotly sizes a figure to its
    // container, and a hidden container has no width.
    R.show(R.byId('gene-record-metrics'), true);
    if (renderMetrics(counts, sections)) { rendered.push('gene-record-metrics'); }

    R.tabs({
      el: els.tabs,
      order: rendered.concat(['gene-record-resources', 'gene-record-api']),
      labels: LABELS, counts: counts, tabCounts: TAB_COUNTS
    });

    R.notice(els.notice, meta, counts);
    MGDB.announce('Record loaded, ' + rendered.length + ' sections.');
  }

  function load() {
    var main = R.byId('gene-record-top');
    if (!main) { return; }

    /* A withdrawn model has nothing for the API to return -- the resource
       answers 410 -- so the page does not ask. Without this the reader would
       see "the rest of this record could not be loaded", which frames a record
       that is correctly and permanently gone as a transient failure. */
    if (main.getAttribute('data-gene-state') === 'withdrawn') {
      R.show(els.loading, false);
      return;
    }

    var requested = main.getAttribute('data-gene-id') || main.getAttribute('data-requested-id');
    if (!requested) { return; }

    R.show(els.error, false);
    R.show(els.loading, true);

    MGDB.request('/api/v1/records/gene/' + encodeURIComponent(requested), { key: 'gene-record' })
      .then(function (response) {
        if (!response || !response.data) { throw new Error('unexpected payload'); }
        render(response);
      })
      .catch(function (error) {
        if (error && error.name === 'AbortError') { return; }
        R.show(els.loading, false);
        R.show(els.error, true);
      });
  }

  function init() {
    els = {
      functionLine: R.byId('gene-record-function-line'),
      synonyms: R.byId('gene-record-synonyms'),
      versionNotice: R.byId('gene-record-version-notice'),
      report: R.byId('gene-record-report'),
      tabs: R.byId('gene-record-tabs'),
      loading: R.byId('gene-record-loading'),
      error: R.byId('gene-record-error'),
      retry: R.byId('gene-record-retry'),
      notice: R.byId('gene-record-notice'),
      overviewBody: R.byId('gene-record-overview-body'),
      structureBody: R.byId('gene-record-structure-body'),
      functionBody: R.byId('gene-record-function-body'),
      subtitle: R.byId('gene-record-subtitle'),
      expressionBody: R.byId('gene-record-expression-body'),
      variationBody: R.byId('gene-record-variation-body'),
      panGeneBody: R.byId('gene-record-pan_gene-body'),
      orthologsBody: R.byId('gene-record-orthologs-body'),
      locusBody: R.byId('gene-record-locus-body'),
      imagesBody: R.byId('gene-record-images-body'),
      stocksBody: R.byId('gene-record-stocks-body'),
      mapBody: R.byId('gene-record-map-body'),
      nearbyBody: R.byId('gene-record-nearby-body'),
      geneticBody: R.byId('gene-record-genetic-body'),
      referencesBody: R.byId('gene-record-references-body'),
      referencesSection: R.byId('gene-record-references'),
      sequencesBody: R.byId('gene-record-sequences-body'),
      xrefsBody: R.byId('gene-record-xrefs-body'),
      provenanceBody: R.byId('gene-record-provenance-body'),
      metricsBody: R.byId('gene-record-metrics-body')
    };
    if (els.retry) { els.retry.addEventListener('click', load); }
    R.apiCard('gene-copy-json-btn', 'gene-record-api-link', function () { return payload; });
    load();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
