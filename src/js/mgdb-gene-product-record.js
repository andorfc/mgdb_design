/* ==========================================================================
   Gene product record page — /data_center/gene_product?id={id}
   --------------------------------------------------------------------------
   Glue over js/mgdb-record.js, which owns every piece of furniture here: the
   collections, the reference list, the metrics and figures, the section tabs
   and the API row. This file maps one call to
   /api/v1/records/gene_product/{id} onto them.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  var R = window.MGDBRecord;
  if (!MGDB || !R) { return; }

  var els = {};
  var payload = null;

  /* ------------------------------------------------------------------------
     Header
     ------------------------------------------------------------------------ */

  function renderHeader(data, overview) {
    var attributes = data.attributes || {};
    var synonyms = attributes.synonyms || [];
    if (synonyms.length) {
      // Synonyms often contain commas themselves, so they are separated by a
      // middle dot rather than joined into one comma list.
      els.synonyms.innerHTML = 'Also known as ' + synonyms.map(function (s) {
        return '<strong>' + R.escape(s.name) + '</strong>' +
               (s.authority ? ' <span class="mgdb-muted">(per ' + R.refLink(s.authority) + ')</span>' : '');
      }).join(' <span class="mgdb-muted" aria-hidden="true">&middot;</span> ') + '.';
      R.show(els.synonyms, true);
    }

    // The server rendered type, species and id; the API adds what it knows.
    var extra = '';
    if (overview && overview.ec_numbers && overview.ec_numbers.length) {
      extra += '<div><dt>EC number' + (overview.ec_numbers.length > 1 ? 's' : '') + '</dt><dd class="mgdb-record-id">' +
        overview.ec_numbers.map(function (e) { return R.escape(e.ec_number); }).join(', ') + '</dd></div>';
    }
    if (overview && overview.loci && overview.loci.length) {
      var loci = overview.loci.slice(0, 4).map(function (l) { return R.refLink(l); }).join(', ');
      if (overview.loci.length > 4) { loci += ' and ' + R.number(overview.loci.length - 4) + ' more'; }
      extra += '<div><dt>Encoded by</dt><dd>' + loci + '</dd></div>';
    }
    if (extra) { els.facts.insertAdjacentHTML('beforeend', extra); }
  }

  /* ------------------------------------------------------------------------
     Overview
     ------------------------------------------------------------------------ */

  function renderOverview(overview, annotations) {
    if (!overview) { return false; }
    var out = els.overviewBody;
    out.innerHTML = '';

    // The description column repeats a curator comment on most records; it is
    // shown here only when Annotations will not show the same text. The column
    // usually holds the opening sentence, so a prefix match is the test.
    var comments = (annotations && annotations.comments) || [];
    var duplicated = overview.description && comments.some(function (c) {
      var a = R.plainText(c.text).toLowerCase(), b = R.plainText(overview.description).toLowerCase();
      return a === b || a.indexOf(b) === 0 || b.indexOf(a) === 0;
    });

    var factsHtml = R.facts([
      ['Product type', overview.type ? R.escape(overview.type.name) : ''],
      ['Species', overview.species ? '<em>' + R.escape(overview.species.name) + '</em>' : ''],
      ['Holoenzyme substructure', overview.holoenzyme_substructure ? R.escape(overview.holoenzyme_substructure) : ''],
      ['Localization', (overview.localizations || []).map(function (l) { return R.escape(l.name); }).join(', ')],
      ['Description', (overview.description && !duplicated) ? R.escape(overview.description) : '']
    ]);
    if (factsHtml) { out.insertAdjacentHTML('beforeend', factsHtml); }
    var rendered = !!factsHtml;

    rendered = R.collection(out, {
      title: 'Encoding loci',
      items: overview.loci,
      filename: 'gene-product-loci.tsv',
      columns: [
        R.recordColumn('Locus', 'name', function (l) { return l; }),
        { key: 'full_name', label: 'Full name' },
        { key: 'gene_model', label: 'B73 v5 gene model',
          get: function (l) { var m = (l.gene_models || []).filter(function (g) { return g.is_reference; })[0]; return m ? m.name : ''; },
          html: function (l) {
            var models = l.gene_models || [];
            var ref = models.filter(function (g) { return g.is_reference; })[0];
            if (!ref) { return models.length ? R.escape(models[0].name) + '<small>' + R.escape(models[0].assembly || models[0].version) + '</small>' : '—'; }
            var others = models.length - 1;
            return R.refLink(ref) + (ref.chromosome ? '<small>' + R.escape(ref.chromosome) + ':' + R.number(ref.start) + '–' + R.number(ref.end) +
                   (others > 0 ? ' · ' + others + ' earlier model' + (others > 1 ? 's' : '') : '') + '</small>' : '');
          } },
        { key: 'bin', label: 'Bin', sort: 'number' },
        { key: 'evidence', label: 'Evidence' },
        R.urlColumn(function (l) { return l.html; })
      ]
    }) || rendered;

    rendered = R.collection(out, {
      title: 'UniProt entries',
      items: overview.uniprot,
      filename: 'gene-product-uniprot.tsv',
      columns: [
        { key: 'accession', label: 'Accession', tile: true,
          html: function (u) { return R.link(u.url, u.accession, true) + (u.obsolete ? ' <span class="mgdb-pill mgdb-pill-warn">Obsolete</span>' : ''); } },
        { key: 'database', label: 'Database', get: function (u) { return u.database ? u.database.name : ''; } },
        { key: 'url', label: 'URL', sort: false, get: function (u) { return u.url || ''; },
          html: function (u) { return u.url ? R.link(u.url, u.url, true) : '—'; } }
      ]
    }) || rendered;

    rendered = R.collection(out, {
      title: 'EC numbers',
      items: overview.ec_numbers,
      filename: 'gene-product-ec-numbers.tsv',
      columns: [
        { key: 'ec_number', label: 'EC number', tile: true,
          html: function (e) { return '<span class="mgdb-record-id">' + R.escape(e.ec_number) + '</span>'; } },
        { key: 'links', label: 'Look up in', sort: false,
          get: function (e) { return Object.keys(e.links || {}).map(function (k) { return e.links[k].name; }).join(', '); },
          tsv: function (e) { return Object.keys(e.links || {}).map(function (k) { return e.links[k].name + ' ' + e.links[k].url; }).join('; '); },
          html: function (e) {
            return '<span class="mgdb-rec-links">' + Object.keys(e.links || {}).map(function (k) { return R.link(e.links[k].url, e.links[k].name, true); }).join('') + '</span>';
          } }
      ]
    }) || rendered;

    rendered = R.collection(out, {
      title: 'Induced expression',
      items: overview.induced_expression,
      filename: 'gene-product-induced-expression.tsv',
      columns: [
        { key: 'condition', label: 'Induced by', tile: true,
          html: function (x) { return R.escape(x.condition) + (x.condition_description ? '<small>' + R.escape(x.condition_description) + '</small>' : ''); } },
        { key: 'evidence', label: 'Evidence',
          html: function (x) { return x.evidence ? R.escape(x.evidence) + (x.evidence_description ? '<small>' + R.escape(x.evidence_description) + '</small>' : '') : '—'; } }
      ]
    }) || rendered;

    rendered = R.collection(out, {
      title: 'Metabolic constituents',
      items: overview.metabolic_constituents,
      filename: 'gene-product-metabolic-constituents.tsv',
      columns: [
        { key: 'name', label: 'Constituent', tile: true },
        { key: 'description', label: 'Description' }
      ]
    }) || rendered;

    rendered = R.collection(out, {
      title: 'Metabolic pathways',
      items: overview.metabolic_pathways,
      filename: 'gene-product-metabolic-pathways.tsv',
      columns: [
        R.recordColumn('Pathway', 'name', function (p) { return p; }),
        { key: 'description', label: 'Description' },
        R.urlColumn(function (p) { return p.html; })
      ]
    }) || rendered;

    rendered = R.collection(out, {
      title: 'Motif features',
      items: overview.motif_features,
      filename: 'gene-product-motif-features.tsv',
      columns: [
        { key: 'feature', label: 'Feature', tile: true,
          html: function (m) { return R.escape(m.feature) + (m.feature_description ? '<small>' + R.escape(m.feature_description) + '</small>' : ''); } },
        { key: 'description', label: 'Description' }
      ]
    }) || rendered;

    if (!rendered) {
      out.innerHTML = '<p class="mgdb-rec-empty">There is no overview data for this gene product.</p>';
    }
    return true;
  }

  /* ------------------------------------------------------------------------
     Annotations
     ------------------------------------------------------------------------ */

  function renderAnnotations(annotations) {
    if (!annotations) { return false; }
    var out = els.annotationsBody;
    out.innerHTML = '';
    var rendered = false;

    rendered = R.notes(out, 'Comments', (annotations.comments || []).map(function (c) {
      return { text: c.text, meta: [c.type, c.source ? 'Source: ' + (c.source.html ? R.refLink(c.source) : R.escape(c.source.name)) : ''] };
    })) || rendered;

    rendered = R.collection(out, {
      title: 'Ontology terms',
      items: annotations.ontology_terms,
      filename: 'gene-product-ontology-terms.tsv',
      columns: [
        { key: 'term', label: 'Term', tile: true, html: function (t) { return t.url ? R.link(t.url, t.term, true) : R.escape(t.term); } },
        { key: 'name', label: 'Name' },
        { key: 'ontology', label: 'Ontology' },
        { key: 'evidence_code', label: 'Evidence' },
        { key: 'reference', label: 'Reference',
          get: function (t) { return t.reference ? t.reference.name : (t.pmid ? 'PMID ' + t.pmid : ''); },
          html: function (t) {
            if (t.reference) { return R.refLink(t.reference); }
            if (t.pmid) { return R.link('https://pubmed.ncbi.nlm.nih.gov/' + t.pmid + '/', 'PMID ' + t.pmid, true); }
            return '—';
          } }
      ]
    }) || rendered;

    rendered = R.notes(out, 'Community annotations', (annotations.user_annotations || []).map(function (a) {
      return { text: a.text, meta: [a.author && a.author.name ? 'From ' + R.refLink(a.author) : '', a.date] };
    })) || rendered;

    return rendered;
  }

  /* ------------------------------------------------------------------------
     Other related records
     ------------------------------------------------------------------------ */

  function renderRelated(related) {
    if (!related) { return false; }
    var out = els.relatedBody;
    out.innerHTML = '';
    var rendered = false;

    rendered = R.collection(out, {
      title: 'Related gene products',
      items: related.gene_products,
      filename: 'gene-product-related-products.tsv',
      columns: [
        R.recordColumn('Gene product', 'name', function (g) { return g; }),
        { key: 'relationship', label: 'Relationship',
          html: function (g) {
            var label = g.relationship ? R.escape(g.relationship) : '—';
            if (g.direction === 'inverse') { label += '<small>recorded on the related product</small>'; }
            else if (g.relationship_description) { label += '<small>' + R.escape(g.relationship_description) + '</small>'; }
            return label;
          } },
        R.urlColumn(function (g) { return g.html; })
      ]
    }) || rendered;


    rendered = R.collection(out, {
      title: 'Probes and markers',
      items: related.probes,
      filename: 'gene-product-probes.tsv',
      columns: [
        R.recordColumn('Probe', 'name', function (p) { return p; }),
        { key: 'probe_type', label: 'Type' },
        { key: 'evidence', label: 'Evidence' },
        R.urlColumn(function (p) { return p.html; })
      ]
    }) || rendered;

    return rendered;
  }

  /* ------------------------------------------------------------------------
     Offsite resources
     ------------------------------------------------------------------------ */

  function renderOffsite(offsite) {
    if (!offsite || !offsite.length) { return false; }
    els.offsiteBody.innerHTML = '';
    return R.collection(els.offsiteBody, {
      title: 'External database entries',
      items: offsite,
      filename: 'gene-product-offsite-resources.tsv',
      columns: [
        { key: 'accession', label: 'Accession', tile: true,
          html: function (x) { return (x.url ? R.link(x.url, x.accession, true) : R.escape(x.accession)) +
                 (x.obsolete ? ' <span class="mgdb-pill mgdb-pill-warn">Obsolete</span>' : ''); } },
        { key: 'database', label: 'Database', get: function (x) { return x.database ? x.database.name : ''; } },
        { key: 'url', label: 'URL', sort: false, get: function (x) { return x.url || ''; },
          html: function (x) { return x.url ? R.link(x.url, x.url, true) : '—'; } }
      ]
    });
  }

  /* ------------------------------------------------------------------------
     Metrics
     ------------------------------------------------------------------------ */

  function renderMetrics(counts, references) {
    var external = (counts.uniprot || 0) + (counts.offsite || 0);
    R.metrics(els.metricsBody, [
      ['Encoding loci', 'Genes', counts.loci, 'Classical loci recorded as encoding this product, with their current gene models.', 'green'],
      ['References', 'Literature', counts.references, 'Curated publications attached to this record.', 'amber'],
      ['Related products', 'Relations', counts.related_products, 'Subunits, isozymes, homologs, and other products related to this one.', 'blue'],
      ['External entries', 'Databases', external, 'UniProt, GenBank, PDB, and other database entries for this product.', 'burgundy']
    ]);

    R.connectionsChart('gp-record-connections-chart', 'gp-record-connections-caption', 'gp-record-connections-figure', [
      ['Encoding loci', counts.loci], ['Gene models', counts.gene_models], ['EC numbers', counts.ec_numbers],
      ['Metabolic pathways', counts.metabolic_pathways], ['Related gene products', counts.related_products],
      ['Probes and markers', counts.probes], ['External entries', external], ['References', counts.references],
      ['Synonyms', counts.synonyms]
    ]);
    R.yearsChart('gp-record-years-chart', 'gp-record-years-caption', 'gp-record-years-figure', references);
    return true;
  }

  /* ------------------------------------------------------------------------
     Assembly
     ------------------------------------------------------------------------ */

  var TAB_COUNTS = {
    'gp-record-overview': ['loci'],
    'gp-record-annotations': ['comments', 'ontology_terms', 'user_annotations'],
    'gp-record-related': ['related_products', 'probes'],
    'gp-record-offsite': ['offsite'],
    'gp-record-references': ['references']
  };

  var LABELS = {
    'gp-record-overview': 'Overview',
    'gp-record-annotations': 'Annotations',
    'gp-record-related': 'Related records',
    'gp-record-offsite': 'Offsite resources',
    'gp-record-references': 'References',
    'gp-record-metrics': 'Metrics',
    'gp-record-resources': 'Related resources',
    'gp-record-api': 'API'
  };

  function render(response) {
    payload = response;
    var data = response.data || {};
    var sections = data.sections || {};
    var meta = response.meta || {};
    var counts = meta.counts || {};

    R.show(els.loading, false);
    R.show(els.error, false);

    renderHeader(data, sections.overview);

    var rendered = [];
    if (renderOverview(sections.overview, sections.annotations)) { rendered.push('gp-record-overview'); }
    if (renderAnnotations(sections.annotations)) { rendered.push('gp-record-annotations'); }
    if (renderRelated(sections.related)) { rendered.push('gp-record-related'); }
    if (renderOffsite(sections.offsite)) { rendered.push('gp-record-offsite'); }
    if (R.references(els.referencesBody, sections.references, els.referencesSection, 'gp-ref')) { rendered.push('gp-record-references'); }
    rendered.forEach(function (id) { R.show(R.byId(id), true); });

    // Revealed before the charts are drawn: Plotly sizes a figure to its
    // container, and a hidden container has no width.
    R.show(R.byId('gp-record-metrics'), true);
    if (renderMetrics(counts, sections.references)) { rendered.push('gp-record-metrics'); }

    R.tabs({
      el: els.tabs,
      order: rendered.concat(['gp-record-resources', 'gp-record-api']),
      labels: LABELS, counts: counts, tabCounts: TAB_COUNTS
    });

    R.notice(els.notice, meta, counts);
    MGDB.announce('Record loaded, ' + rendered.length + ' sections.');
  }

  function load() {
    var main = R.byId('gp-record-top');
    if (!main) { return; }
    var requested = main.getAttribute('data-requested-id') || main.getAttribute('data-gene-product-id');
    if (!requested) { return; }

    R.show(els.error, false);
    R.show(els.loading, true);

    MGDB.request('/api/v1/records/gene_product/' + encodeURIComponent(requested), { key: 'gene-product-record' })
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
      synonyms: R.byId('gp-record-synonyms'),
      facts: R.byId('gp-record-facts'),
      tabs: R.byId('gp-record-tabs'),
      loading: R.byId('gp-record-loading'),
      error: R.byId('gp-record-error'),
      retry: R.byId('gp-record-retry'),
      notice: R.byId('gp-record-notice'),
      overviewBody: R.byId('gp-record-overview-body'),
      annotationsBody: R.byId('gp-record-annotations-body'),
      relatedBody: R.byId('gp-record-related-body'),
      offsiteBody: R.byId('gp-record-offsite-body'),
      referencesBody: R.byId('gp-record-references-body'),
      referencesSection: R.byId('gp-record-references'),
      metricsBody: R.byId('gp-record-metrics-body')
    };
    if (els.retry) { els.retry.addEventListener('click', load); }
    R.apiCard('gp-copy-json-btn', 'gp-record-api-link', function () { return payload; });
    load();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
