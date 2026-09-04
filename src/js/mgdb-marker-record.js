/* ==========================================================================
   Marker record page — /data_center/marker?id={id}
   --------------------------------------------------------------------------
   Glue over js/mgdb-record.js, the same engine the gene product, variation and
   map record pages use. This file maps one call to
   /api/v1/records/marker/{id} onto it.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  var R = window.MGDBRecord;
  if (!MGDB || !R) { return; }

  var els = {};
  var payload = null;

  function position(value) {
    return value === null || value === undefined ? '' : Number(value).toFixed(1);
  }

  /* ------------------------------------------------------------------------
     Header
     ------------------------------------------------------------------------ */

  function renderHeader(data) {
    var synonyms = (data.attributes || {}).synonyms || [];
    if (!synonyms.length) { return; }
    els.synonyms.innerHTML = 'Also known as ' + synonyms.map(function (s) {
      return '<strong>' + R.escape(s.name) + '</strong>' +
             (s.authority ? ' <span class="mgdb-muted">(per ' + R.refLink(s.authority) + ')</span>' : '');
    }).join(' <span class="mgdb-muted" aria-hidden="true">&middot;</span> ') + '.';
    R.show(els.synonyms, true);
  }

  /* ------------------------------------------------------------------------
     Overview
     ------------------------------------------------------------------------ */

  function renderOverview(overview) {
    if (!overview) { return false; }
    var out = els.overviewBody;
    out.innerHTML = '';

    var factsHtml = R.facts([
      ['Marker type', overview.type ? R.escape(overview.type.name) : '', overview.type_description || ''],
      ['Species', overview.species ? '<em>' + R.escape(overview.species.name) + '</em>' : ''],
      ['Insert size', overview.insert_size ? R.escape(overview.insert_size) + ' kb' : ''],
      ['Vector', overview.vector ? (R.refLink(overview.vector) || R.escape(overview.vector.name)) : ''],
      ['Preparation', overview.procedure ? R.escape(overview.procedure.name) : ''],
      ['Quality', overview.quality ? R.escape(overview.quality.name) : ''],
      ['Prepared by', overview.prepared_by ? (R.refLink(overview.prepared_by) || R.escape(overview.prepared_by.name)) : ''],
      ['Available from', overview.available_from ? (R.refLink(overview.available_from) || R.escape(overview.available_from.name)) : ''],
      ['Mnemonic', overview.mnemonic ? R.escape(overview.mnemonic) : ''],
      ['Notable condition', overview.notable_condition ? R.escape(overview.notable_condition) : ''],
      ['Repeat', overview.repeat ? R.escape(overview.repeat) : ''],
      ['Properties', (overview.properties || []).map(function (p) { return R.escape(p.name); }).join(', ')]
    ]);
    if (factsHtml) { out.insertAdjacentHTML('beforeend', factsHtml); }
    var rendered = !!factsHtml;

    rendered = R.collection(out, {
      title: 'Genetic bins',
      items: overview.bins,
      filename: 'marker-bins.tsv',
      columns: [
        { key: 'bin', label: 'Bin', tile: true },
        { key: 'locus', label: 'Locus', get: function (b) { return b.locus ? b.locus.name : ''; },
          html: function (b) { return b.locus ? (R.refLink(b.locus) || R.escape(b.locus.name)) : '—'; } },
        { key: 'map', label: 'Map', get: function (b) { return b.map ? b.map.name : ''; },
          html: function (b) { return b.map ? (R.refLink(b.map) || R.escape(b.map.name)) : '—'; } }
      ]
    }) || rendered;

    rendered = R.collection(out, {
      title: 'Primers',
      items: overview.primers,
      filename: 'marker-primers.tsv',
      columns: [
        R.recordColumn('Primer', 'name', function (p) { return p; }),
        { key: 'end', label: 'End' },
        { key: 'sequence', label: 'Sequence',
          html: function (p) { return p.sequence ? '<span class="mgdb-sequence">' + R.escape(p.sequence) + '</span>' : '—'; } },
        { key: 'melting_temperature', label: 'Tm (°C)', sort: 'number', numeric: true,
          get: function (p) { return p.melting_temperature === null ? '' : String(p.melting_temperature); } }
      ]
    }) || rendered;

    if (!rendered) {
      out.innerHTML = '<p class="mgdb-rec-empty">There is no overview data for this marker.</p>';
    }
    return true;
  }

  /* ------------------------------------------------------------------------
     Related records
     ------------------------------------------------------------------------ */

  function renderRelated(related) {
    if (!related) { return false; }
    var out = els.relatedBody;
    out.innerHTML = '';
    var rendered = false;

    /* Probes related to this one. On a BAC this is the bulk of what the
       legacy page's Related Information section showed -- "detected by overgo
       X" -- and each row links to whichever collection page owns that probe. */
    rendered = R.collection(out, {
      title: 'Related probes',
      items: related.probes,
      filename: 'marker-related-probes.tsv',
      pageSize: 25,
      columns: [
        { key: 'name', label: 'Probe', tile: true,
          html: function (p) { return p.html ? R.link(p.html, p.name) : R.escape(p.name); } },
        { key: 'probe_type', label: 'Probe type' },
        { key: 'relation', label: 'Relationship',
          html: function (p) { return p.relation
            ? R.escape(p.relation)
            : '<span class="mgdb-muted">Not recorded</span>'; } },
        R.urlColumn(function (p) { return p.html; })
      ]
    }) || rendered;

    rendered = R.collection(out, {
      title: 'Gene products detected',
      items: related.gene_products,
      filename: 'marker-gene-products.tsv',
      columns: [
        R.recordColumn('Gene product', 'name', function (g) { return g; }),
        { key: 'evidence', label: 'Evidence' },
        R.urlColumn(function (g) { return g.html; })
      ]
    }) || rendered;

    rendered = R.collection(out, {
      title: 'Sequences',
      items: related.sequences,
      filename: 'marker-sequences.tsv',
      columns: [
        { key: 'accession', label: 'GenBank accession', tile: true,
          html: function (s) { return R.link(s.html, s.accession); } },
        { key: 'sequence_type', label: 'Type' },
        { key: 'length', label: 'Length (bp)', sort: 'number', numeric: true,
          get: function (s) { return s.length == null ? '' : R.number(s.length); } },
        { key: 'title', label: 'Title' }
      ]
    }) || rendered;

    rendered = R.collection(out, {
      title: 'Gel patterns',
      items: related.gel_patterns,
      filename: 'marker-gel-patterns.tsv',
      columns: [
        R.recordColumn('Gel pattern', 'name', function (g) { return g; }),
        { key: 'enzyme', label: 'Enzyme' },
        R.urlColumn(function (g) { return g.html; })
      ]
    }) || rendered;

    rendered = R.collection(out, {
      title: 'Copies held',
      items: related.copies,
      filename: 'marker-copies.tsv',
      columns: [
        { key: 'copies', label: 'Copies', tile: true },
        { key: 'source', label: 'Source', get: function (c) { return c.source ? c.source.name : ''; },
          html: function (c) { return c.source ? (R.refLink(c.source) || R.escape(c.source.name)) : '—'; } },
        { key: 'added', label: 'Added' }
      ]
    }) || rendered;

    return rendered;
  }

  /* ------------------------------------------------------------------------
     Metrics and figures
     ------------------------------------------------------------------------ */

  function renderMetrics(counts, positions, references) {
    /* Which chromosomes this marker lands on. A probe that detects one locus
       sits on one chromosome; one that detects several often does not, and
       that is the thing worth seeing. Nearly every position is on a different
       map -- p-umc10 has 73 positions on 73 maps -- so a count of maps would
       just restate the count of positions. The count of chromosomes does not. */
    var byChromosome = {};
    (positions || []).forEach(function (p) {
      var key = p.linkage_group || 'Not recorded';
      byChromosome[key] = (byChromosome[key] || 0) + 1;
    });
    var keys = Object.keys(byChromosome).sort(function (a, b) {
      var an = parseFloat(a), bn = parseFloat(b);
      if (!isNaN(an) && !isNaN(bn)) { return an - bn; }
      return a.localeCompare(b);
    });

    R.metrics(els.metricsBody, [
      ['Detected loci', 'Loci', counts.loci, 'Loci this marker detects, and the method used.', 'green'],
      ['Map positions', 'Maps', counts.positions, 'Placements of those loci on curated genetic maps.', 'amber'],
      ['Chromosomes', 'Coverage', keys.length, 'Chromosomes those positions fall on.', 'blue'],
      ['References', 'Literature', counts.references, 'Curated publications attached to this record.', 'burgundy']
    ]);

    if (keys.length && MGDB.chart) {
      R.show(R.byId('marker-record-chromosome-figure'), true);
      var height = R.sizeChart('marker-record-chromosome-chart', 320);
      R.byId('marker-record-chromosome-caption').textContent =
        R.number(positions.length) + ' map positions across ' + keys.length +
        (keys.length === 1 ? ' chromosome.' : ' chromosomes.');
      MGDB.chart({
        target: 'marker-record-chromosome-chart',
        traces: function () {
          return [{
            type: 'bar', x: keys, y: keys.map(function (k) { return byChromosome[k]; }),
            marker: { color: '#285d46' },
            hovertemplate: 'Chromosome %{x}<br>%{y} positions<extra></extra>'
          }];
        },
        layout: {
          height: height,
          margin: { l: 48, r: 16, t: 8, b: 44 },
          xaxis: { type: 'category', title: { text: 'Chromosome' }, automargin: true },
          yaxis: { title: { text: 'Map positions' }, rangemode: 'tozero', dtick: 1, automargin: true }
        }
      });
      R.watchChartWidth('marker-record-chromosome-chart');
    }

    R.connectionsChart('marker-record-connections-chart', 'marker-record-connections-caption', 'marker-record-connections-figure', [
      ['Detected loci', counts.loci], ['Map positions', counts.positions], ['Maps', counts.maps],
      ['Genetic bins', counts.bins], ['Primers', counts.primers],
      ['Gene products', counts.gene_products], ['Gel patterns', counts.gel_patterns],
      ['Sequences', counts.sequences], ['External entries', counts.offsite],
      ['References', counts.references], ['Synonyms', counts.synonyms]
    ]);
    void references;
    return true;
  }

  /* ------------------------------------------------------------------------
     Assembly
     ------------------------------------------------------------------------ */

  var TAB_COUNTS = {
    'marker-record-overview': ['bins', 'primers', 'properties'],
    'marker-record-loci': ['loci'],
    'marker-record-positions': ['positions'],
    'marker-record-related': ['related_probes', 'gene_products', 'sequences', 'gel_patterns', 'copies'],
    'marker-record-offsite': ['offsite'],
    'marker-record-annotations': ['comments'],
    'marker-record-references': ['references']
  };

  var LABELS = {
    'marker-record-overview': 'Overview',
    'marker-record-loci': 'Detected loci',
    'marker-record-positions': 'Map positions',
    'marker-record-related': 'Related records',
    'marker-record-offsite': 'Offsite resources',
    'marker-record-annotations': 'Annotations',
    'marker-record-references': 'References',
    'marker-record-metrics': 'Metrics',
    'marker-record-resources': 'Related resources',
    'marker-record-api': 'API'
  };

  function render(response) {
    payload = response;
    var data = response.data || {};
    var sections = data.sections || {};
    var meta = response.meta || {};
    var counts = meta.counts || {};

    R.show(els.loading, false);
    R.show(els.error, false);

    renderHeader(data);

    var rendered = [];
    if (renderOverview(sections.overview)) { rendered.push('marker-record-overview'); }

    if (R.collection(els.lociBody, {
      title: 'Loci this marker detects',
      items: sections.loci,
      filename: 'marker-loci.tsv',
      columns: [
        R.recordColumn('Locus', 'name', function (l) { return l; }),
        { key: 'full_name', label: 'Full name' },
        { key: 'locus_type', label: 'Locus type' },
        { key: 'method', label: 'Detection method' },
        { key: 'authority', label: 'Authority', get: function (l) { return l.authority ? l.authority.name : ''; },
          html: function (l) { return l.authority ? (R.refLink(l.authority) || R.escape(l.authority.name)) : '—'; } },
        R.urlColumn(function (l) { return l.html; })
      ]
    })) { rendered.push('marker-record-loci'); }

    if (R.collection(els.positionsBody, {
      title: 'Where those loci sit on curated maps',
      items: sections.positions,
      filename: 'marker-map-positions.tsv',
      pageSize: 25,
      columns: [
        { key: 'locus', label: 'Locus', tile: true, get: function (p) { return p.locus ? p.locus.name : ''; },
          html: function (p) { return p.locus ? (R.refLink(p.locus) || R.escape(p.locus.name)) : '—'; } },
        { key: 'map', label: 'Map', get: function (p) { return p.map ? p.map.name : ''; },
          html: function (p) { return p.map ? (R.refLink(p.map) || R.escape(p.map.name)) : '—'; } },
        { key: 'linkage_group', label: 'Chromosome' },
        { key: 'position', label: 'Position', sort: 'number', numeric: true,
          get: function (p) { return position(p.position); } },
        { key: 'bin', label: 'Bin' },
        { key: 'is_backbone', label: 'Backbone',
          get: function (p) { return p.is_backbone ? 'Yes' : 'No'; },
          html: function (p) { return p.is_backbone
            ? '<span class="mgdb-pill mgdb-pill-ok">Backbone</span>'
            : '<span class="mgdb-muted">—</span>'; } }
      ]
    })) { rendered.push('marker-record-positions'); }

    if (renderRelated(sections.related)) { rendered.push('marker-record-related'); }

    if (R.collection(els.offsiteBody, {
      title: 'External database entries',
      items: sections.offsite,
      filename: 'marker-offsite-resources.tsv',
      columns: [
        { key: 'accession', label: 'Accession', tile: true,
          html: function (x) { return (x.url ? R.link(x.url, x.accession, true) : R.escape(x.accession)) +
                 (x.obsolete ? ' <span class="mgdb-pill mgdb-pill-warn">Obsolete</span>' : ''); } },
        { key: 'database', label: 'Database', get: function (x) { return x.database ? x.database.name : ''; } },
        { key: 'url', label: 'URL', sort: false, get: function (x) { return x.url || ''; },
          html: function (x) { return x.url ? R.link(x.url, x.url, true) : '—'; } }
      ]
    })) { rendered.push('marker-record-offsite'); }

    if (R.notes(els.annotationsBody, 'Comments', ((sections.annotations || {}).comments || []).map(function (c) {
      return { text: c.text, meta: [c.type, c.source ? 'Source: ' + (c.source.html ? R.refLink(c.source) : R.escape(c.source.name)) : ''] };
    }))) { rendered.push('marker-record-annotations'); }

    if (R.references(els.referencesBody, sections.references, els.referencesSection, 'marker-ref')) {
      rendered.push('marker-record-references');
    }

    rendered.forEach(function (id) { R.show(R.byId(id), true); });

    // Revealed before the charts are drawn: Plotly sizes a figure to its
    // container, and a hidden container has no width.
    R.show(R.byId('marker-record-metrics'), true);
    if (renderMetrics(counts, sections.positions, sections.references)) { rendered.push('marker-record-metrics'); }

    R.tabs({
      el: els.tabs,
      order: rendered.concat(['marker-record-resources', 'marker-record-api']),
      labels: LABELS, counts: counts, tabCounts: TAB_COUNTS
    });

    R.notice(els.notice, meta, counts);
    MGDB.announce('Record loaded, ' + rendered.length + ' sections.');
  }

  function load() {
    var main = R.byId('marker-record-top');
    if (!main) { return; }
    var requested = main.getAttribute('data-requested-id') || main.getAttribute('data-canonical-id');
    if (!requested) { return; }

    R.show(els.error, false);
    R.show(els.loading, true);

    MGDB.request('/api/v1/records/marker/' + encodeURIComponent(requested), { key: 'marker-record' })
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
      synonyms: R.byId('marker-record-synonyms'),
      facts: R.byId('marker-record-facts'),
      tabs: R.byId('marker-record-tabs'),
      loading: R.byId('marker-record-loading'),
      error: R.byId('marker-record-error'),
      retry: R.byId('marker-record-retry'),
      notice: R.byId('marker-record-notice'),
      overviewBody: R.byId('marker-record-overview-body'),
      lociBody: R.byId('marker-record-loci-body'),
      positionsBody: R.byId('marker-record-positions-body'),
      relatedBody: R.byId('marker-record-related-body'),
      offsiteBody: R.byId('marker-record-offsite-body'),
      annotationsBody: R.byId('marker-record-annotations-body'),
      referencesBody: R.byId('marker-record-references-body'),
      referencesSection: R.byId('marker-record-references'),
      metricsBody: R.byId('marker-record-metrics-body')
    };
    if (els.retry) { els.retry.addEventListener('click', load); }
    R.apiCard('marker-copy-json-btn', 'marker-record-api-link', function () { return payload; });
    load();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
