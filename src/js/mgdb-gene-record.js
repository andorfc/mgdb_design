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

  function num(value) { return (value === null || value === undefined) ? '' : R.number(value); }

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
    var synonyms = locus.synonyms || [];
    if (synonyms.length) {
      els.synonyms.innerHTML = 'Also known as ' + synonyms.map(function (s) {
        return '<strong>' + R.escape(typeof s === 'string' ? s : (s.value || s.name)) + '</strong>';
      }).join(' <span class="mgdb-muted" aria-hidden="true">&middot;</span> ') + '.';
      R.show(els.synonyms, true);
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

    var factsHtml = R.facts([
      ['Species', overview.species ? '<em>' + R.escape(overview.species) + '</em>' : ''],
      ['Line', overview.line ? R.escape(overview.line) : ''],
      ['Position', position, overview.span_bp ? R.number(overview.span_bp) + ' bp on the genome' : ''],
      ['Strand', overview.strand ? R.escape(overview.strand) : '', overview.strand_note || ''],
      ['Model type', overview.model_type ? R.escape(String(overview.model_type).replace(/_/g, ' ')) : ''],
      ['Transcripts', overview.transcript_count == null ? '' : String(overview.transcript_count)]
    ]);
    if (factsHtml) { out.insertAdjacentHTML('beforeend', factsHtml); }

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

    var factsHtml = R.facts([
      ['Canonical transcript', protein.transcript ? R.escape(protein.transcript) : ''],
      ['Protein', protein.name ? R.escape(protein.name) : ''],
      ['Protein length', protein.length_aa ? R.number(protein.length_aa) + ' aa' : '',
        protein.length_note || '']
    ]);
    if (factsHtml) { out.insertAdjacentHTML('beforeend', factsHtml); rendered = true; }

    rendered = R.collection(out, {
      title: 'Transcripts',
      items: structure.transcripts,
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
          get: function (t) { return t.length_bp == null ? '' : R.number(t.length_bp); } }
      ]
    }) || rendered;

    /* Drawn to scale when the protein's length is known. Without it the domains
       cannot be placed against the protein, and the table below is all there
       is to show. */
    var trackHtml = domainTrack(structure.protein_domains || [], protein);
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
      '<div class="gene-record-efp-stage is-loading" id="gene-record-efp-stage"><img src="" alt=""></div>' +
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

    if (expression.qteller && expression.qteller.available) {
      out.insertAdjacentHTML('beforeend',
        '<div class="mgdb-rec-block"><div class="mgdb-rec-block-head"><h3>qTeller</h3></div>' +
        '<div class="mgdb-rec-linkrow"><a class="mgdb-button mgdb-button-primary" href="' +
        R.escape(expression.qteller.url) + '" target="_blank" rel="noopener">' +
        'Open the expression atlas <span aria-hidden="true">&nearr;</span></a></div></div>');
      rendered = true;
    }

    rendered = renderEfp(out, expression.efp) || rendered;

    var gaps = [];
    if (expression.rnaseq_histogram && !expression.rnaseq_histogram.available) {
      gaps.push(expression.rnaseq_histogram.reason);
    }
    if (expression.proteomics && !expression.proteomics.available) {
      gaps.push(expression.proteomics.reason);
    }
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

  function renderVariation(variation) {
    if (!variation) { return false; }
    var out = els.variationBody;
    out.innerHTML = '';
    var rendered = false;

    rendered = R.collection(out, {
      title: 'Insertions',
      items: variation.insertions,
      filename: 'gene-insertions.tsv',
      pageSize: 25,
      columns: [
        { key: 'name', label: 'Insertion', tile: true,
          html: function (i) { return i.html ? R.link(i.html, i.name) : R.escape(i.name); } },
        { key: 'source', label: 'Source' },
        { key: 'structure', label: 'Gene structure' },
        { key: 'position', label: 'Position',
          html: function (i) { return i.position
            ? '<span class="mgdb-sequence">' + R.escape(i.position) + '</span>'
            : '<span class="mgdb-muted">Not recorded</span>'; } },
        { key: 'stocks', label: 'Stock' }
      ]
    }) || rendered;

    rendered = R.collection(out, {
      title: 'SNPs and traits',
      items: variation.snp_traits,
      filename: 'gene-snp-traits.tsv',
      pageSize: 25,
      columns: [
        { key: 'snp', label: 'SNP', tile: true },
        { key: 'trait', label: 'Trait' },
        { key: 'structure', label: 'Structure' },
        { key: 'position', label: 'Position', sort: 'number', numeric: true,
          get: function (t) { return t.position == null ? '' : R.number(t.position); } },
        { key: 'reference', label: 'Reference',
          get: function (t) { return t.reference ? t.reference.name : ''; },
          html: function (t) { return t.reference ? (R.refLink(t.reference) || R.escape(t.reference.name)) : '\u2014'; } }
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

  function renderOrthologs(orthologs) {
    var list = (orthologs && orthologs.orthologs) || [];
    return R.collection(els.orthologsBody, {
      title: 'Orthologs in other species',
      items: list,
      filename: 'gene-orthologs.tsv',
      pageSize: 25,
      columns: [
        { key: 'name', label: 'Gene', tile: true,
          html: function (o) { return o.url ? R.link(o.url, o.name, true) : R.escape(o.name); } },
        { key: 'species', label: 'Species',
          html: function (o) { return o.species ? '<em>' + R.escape(o.species) + '</em>' : '\u2014'; } },
        { key: 'source', label: 'Source' },
        { key: 'relationship', label: 'Relationship' }
      ]
    });
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
       and this is where a reader compares them. */
    R.collection(out, {
      title: 'Gene models for this classical gene',
      items: locus.associated_gene_models,
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
                 '<span aria-hidden="true">&nearr;</span></a>';
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

  function renderProvenance(structure) {
    var scores = (structure && structure.scores) || [];
    return R.collection(els.provenanceBody, {
      title: 'Model quality scores',
      items: scores,
      filename: 'gene-model-scores.tsv',
      columns: [
        { key: 'label', label: 'Score', tile: true,
          get: function (s) { return s.label || s.metric; } },
        { key: 'value', label: 'Value' },
        { key: 'interpretation', label: 'What it means' }
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
    'gene-record-map': 'Map coordinates',
    'gene-record-nearby': 'Nearby loci',
    'gene-record-genetic': 'Additional genetic information',
    'gene-record-references': 'References',
    'gene-record-sequences': 'Sequences and downloads',
    'gene-record-xrefs': 'Cross-references',
    'gene-record-provenance': 'Model quality',
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

    var rendered = [];
    if (renderOverview(sections.overview)) { rendered.push('gene-record-overview'); }
    if (renderStructure(sections.structure)) { rendered.push('gene-record-structure'); }
    if (renderFunction(sections.function)) { rendered.push('gene-record-function'); }
    if (renderExpression(sections.expression)) { rendered.push('gene-record-expression'); }
    if (renderVariation(sections.variation)) { rendered.push('gene-record-variation'); }
    if (renderPanGene(sections.pan_gene)) { rendered.push('gene-record-pan_gene'); }
    if (renderOrthologs(sections.orthologs)) { rendered.push('gene-record-orthologs'); }
    if (renderLocus(sections.locus)) { rendered.push('gene-record-locus'); }
    if (renderMap(sections.locus)) { rendered.push('gene-record-map'); }
    if (renderNearby(sections.locus)) { rendered.push('gene-record-nearby'); }
    if (renderGenetic(sections.locus)) { rendered.push('gene-record-genetic'); }

    if (R.references(els.referencesBody, (sections.references || {}).references,
                     els.referencesSection, 'gene-ref')) {
      rendered.push('gene-record-references');
    }

    if (renderSequences(sections.sequences)) { rendered.push('gene-record-sequences'); }
    if (renderXrefs(sections.xrefs)) { rendered.push('gene-record-xrefs'); }
    if (renderProvenance(sections.structure)) { rendered.push('gene-record-provenance'); }

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
      functionLine: R.byId('gene-record-function'),
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
      expressionBody: R.byId('gene-record-expression-body'),
      variationBody: R.byId('gene-record-variation-body'),
      panGeneBody: R.byId('gene-record-pan_gene-body'),
      orthologsBody: R.byId('gene-record-orthologs-body'),
      locusBody: R.byId('gene-record-locus-body'),
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
