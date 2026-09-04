/* ==========================================================================
   Phenotype record page — /data_center/phenotype?id={id}
   --------------------------------------------------------------------------
   Glue over js/mgdb-record.js, the same engine the gene product, variation,
   map and marker record pages use. This file maps one call to
   /api/v1/records/phenotype/{id} onto it.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  var R = window.MGDBRecord;
  if (!MGDB || !R) { return; }

  var els = {};
  var payload = null;

  function termHtml(term) {
    if (!term) { return ''; }
    return R.refLink(term) || R.escape(term.name);
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
      ['Trait', termHtml(overview.trait), overview.trait_description || ''],
      ['Value', termHtml(overview.value)],
      ['Inheritance', termHtml(overview.inheritance)],
      ['Intensity', termHtml(overview.intensity)],
      ['Visible without equipment', overview.visible_no_equipment ? 'Yes' : '']
    ]);
    if (factsHtml) { out.insertAdjacentHTML('beforeend', factsHtml); }
    var rendered = !!factsHtml;

    if (overview.description) {
      rendered = R.notes(out, 'Description', [{ text: overview.description }]) || rendered;
    }

    /* The legacy page hid each term's definition in an <acronym> title, where
       it was reachable only by hovering. It is a column here. */
    rendered = R.collection(out, {
      title: 'Plant parts affected',
      items: overview.body_parts,
      filename: 'phenotype-plant-parts.tsv',
      columns: [
        { key: 'name', label: 'Plant part', tile: true },
        { key: 'definition', label: 'Definition',
          html: function (t) { return t.definition ? R.escape(t.definition) : '<span class="mgdb-muted">Not recorded</span>'; } }
      ]
    }) || rendered;

    rendered = R.collection(out, {
      title: 'Growth stages affected',
      items: overview.growth_stages,
      filename: 'phenotype-growth-stages.tsv',
      columns: [
        { key: 'name', label: 'Growth stage', tile: true },
        { key: 'definition', label: 'Definition',
          html: function (t) { return t.definition ? R.escape(t.definition) : '<span class="mgdb-muted">Not recorded</span>'; } }
      ]
    }) || rendered;

    rendered = R.collection(out, {
      title: 'Metabolic pathways',
      items: overview.pathways,
      filename: 'phenotype-pathways.tsv',
      columns: [
        R.recordColumn('Pathway', 'name', function (p) { return p; }),
        R.urlColumn(function (p) { return p.html; })
      ]
    }) || rendered;

    if (!rendered) {
      out.innerHTML = '<p class="mgdb-rec-empty">There is no overview data for this phenotype.</p>';
    }
    return true;
  }

  /* ------------------------------------------------------------------------
     Metrics and figures
     ------------------------------------------------------------------------ */

  function renderMetrics(counts, genes) {
    R.metrics(els.metricsBody, [
      ['Variations', 'Alleles', counts.variations, 'Alleles and mutations recorded as showing this phenotype.', 'green'],
      ['Genes', 'Loci', counts.genes, 'Loci those variations belong to.', 'amber'],
      ['Stocks', 'Germplasm', counts.stocks, 'Stocks recorded as carrying this phenotype.', 'blue'],
      ['References', 'Literature', counts.references, 'Curated publications attached to this record.', 'burgundy']
    ]);

    /* The two figures sit side by side, so both are drawn at the height the
       connections chart needs and the gene list is cut to the number of bars
       that fits it. Equal heights, equal bar rhythm.

       Which genes account for the phenotype is worth a figure of its own: one
       like dwarf plant is spread over dozens of loci, and the shape of that
       spread -- a few genes with many alleles, a long tail with one each --
       is the thing a count cannot show. */
    var series = [
      ['Variations', counts.variations], ['Genes', counts.genes], ['Stocks', counts.stocks],
      ['Images', counts.images], ['Plant parts', counts.body_parts],
      ['Growth stages', counts.growth_stages], ['Metabolic pathways', counts.pathways],
      ['External entries', counts.offsite], ['Annotations', counts.comments],
      ['References', counts.references], ['Synonyms', counts.synonyms]
    ];
    var height = R.connectionsHeight(series);
    var fits = Math.max(3, Math.floor((height - 80) / 34));

    var ranked = (genes || []).filter(function (g) { return g.variation_count > 0; });
    var top = ranked.slice(0, fits);
    if (top.length > 1 && MGDB.chart) {
      R.show(R.byId('pheno-record-genes-figure'), true);
      R.sizeChart('pheno-record-genes-chart', height);
      R.byId('pheno-record-genes-caption').textContent =
        (top.length < ranked.length
          ? 'The ' + top.length + ' genes with the most variations, of ' + R.number(ranked.length) + ' in all.'
          : R.number(top.length) + ' genes, by how many of their variations show this phenotype.');
      var ordered = top.slice().reverse();
      MGDB.chart({
        target: 'pheno-record-genes-chart',
        traces: function () {
          return [{
            type: 'bar', orientation: 'h',
            y: ordered.map(function (g) { return g.name; }),
            x: ordered.map(function (g) { return g.variation_count; }),
            marker: { color: '#285d46' },
            // A leading non-breaking space is the only padding Plotly offers
            // for an outside bar label; SVG collapses a plain leading space.
            text: ordered.map(function (g) { return '\u00A0' + g.variation_count; }),
            textposition: 'outside', textangle: 0, cliponaxis: false,
            hovertemplate: '%{y}<br>%{x} variations<extra></extra>'
          }];
        },
        layout: {
          height: height,
          margin: { l: 10, r: 60, t: 8, b: 44 },
          bargap: 0.3,
          xaxis: { title: { text: 'Variations' }, rangemode: 'tozero', automargin: true },
          yaxis: { type: 'category', automargin: true }
        }
      });
      R.watchChartWidth('pheno-record-genes-chart');
    }

    R.connectionsChart('pheno-record-connections-chart', 'pheno-record-connections-caption',
                       'pheno-record-connections-figure', series, height);
    return true;
  }

  /* ------------------------------------------------------------------------
     Assembly
     ------------------------------------------------------------------------ */

  var TAB_COUNTS = {
    'pheno-record-overview': ['body_parts', 'growth_stages', 'pathways'],
    'pheno-record-genes': ['genes'],
    'pheno-record-variations': ['variations'],
    'pheno-record-stocks': ['stocks'],
    'pheno-record-images': ['images'],
    'pheno-record-offsite': ['offsite'],
    'pheno-record-annotations': ['comments'],
    'pheno-record-references': ['references']
  };

  var LABELS = {
    'pheno-record-overview': 'Overview',
    'pheno-record-genes': 'Genes',
    'pheno-record-variations': 'Variations',
    'pheno-record-stocks': 'Stocks',
    'pheno-record-images': 'Images',
    'pheno-record-offsite': 'Offsite resources',
    'pheno-record-annotations': 'Annotations',
    'pheno-record-references': 'References',
    'pheno-record-metrics': 'Metrics',
    'pheno-record-resources': 'Related resources',
    'pheno-record-api': 'API'
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
    if (renderOverview(sections.overview)) { rendered.push('pheno-record-overview'); }

    if (R.collection(els.genesBody, {
      title: 'Genes whose variations show this phenotype',
      items: sections.genes,
      filename: 'phenotype-genes.tsv',
      pageSize: 25,
      columns: [
        R.recordColumn('Gene', 'name', function (g) { return g; }),
        { key: 'full_name', label: 'Full name' },
        { key: 'variation_count', label: 'Variations', sort: 'number', numeric: true,
          get: function (g) { return g.variation_count == null ? '' : String(g.variation_count); } },
        R.urlColumn(function (g) { return g.html; })
      ]
    })) { rendered.push('pheno-record-genes'); }

    if (R.collection(els.variationsBody, {
      title: 'Variations recorded as showing this phenotype',
      items: sections.variations,
      filename: 'phenotype-variations.tsv',
      pageSize: 25,
      columns: [
        R.recordColumn('Variation', 'name', function (v) { return v; }),
        { key: 'variation_type', label: 'Type' },
        { key: 'locus', label: 'Gene', get: function (v) { return v.locus ? v.locus.name : ''; },
          html: function (v) { return v.locus ? (R.refLink(v.locus) || R.escape(v.locus.name)) : '—'; } },
        { key: 'locus_full_name', label: 'Gene name' },
        R.urlColumn(function (v) { return v.html; })
      ]
    })) { rendered.push('pheno-record-variations'); }

    if (R.collection(els.stocksBody, {
      title: 'Stocks carrying this phenotype',
      items: sections.stocks,
      filename: 'phenotype-stocks.tsv',
      pageSize: 25,
      columns: [
        R.recordColumn('Stock', 'name', function (s) { return s; }),
        { key: 'description', label: 'Description' },
        { key: 'provider', label: 'Available from', get: function (s) { return s.provider ? s.provider.name : ''; },
          html: function (s) { return s.provider ? (R.refLink(s.provider) || R.escape(s.provider.name)) : '—'; } },
        R.urlColumn(function (s) { return s.html; })
      ]
    })) { rendered.push('pheno-record-stocks'); }

    /* The pictures belong to the variations that show the phenotype, so each
       card names its variation and links to that record. */
    if (R.images(els.imagesBody, (sections.images || []).map(function (image) {
      return {
        url: image.url,
        caption: image.caption,
        title: image.subject || image.part || 'Image',
        category: image.type || 'Variation / Mutant',
        record: image.record || ''
      };
    }), 'pheno-record-image-dialog', {
      title: 'Images of variations showing this phenotype',
      filename: 'phenotype-images.tsv'
    })) { rendered.push('pheno-record-images'); }

    if (R.collection(els.offsiteBody, {
      title: 'External database entries',
      items: sections.offsite,
      filename: 'phenotype-offsite-resources.tsv',
      columns: [
        { key: 'accession', label: 'Accession', tile: true,
          html: function (x) { return (x.url ? R.link(x.url, x.accession, true) : R.escape(x.accession)) +
                 (x.obsolete ? ' <span class="mgdb-pill mgdb-pill-warn">Obsolete</span>' : ''); } },
        { key: 'database', label: 'Database', get: function (x) { return x.database ? x.database.name : ''; } },
        { key: 'url', label: 'URL', sort: false, get: function (x) { return x.url || ''; },
          html: function (x) { return x.url ? R.link(x.url, x.url, true) : '—'; } }
      ]
    })) { rendered.push('pheno-record-offsite'); }

    if (R.notes(els.annotationsBody, 'Curator notes and annotations', ((sections.annotations || {}).comments || []).map(function (c) {
      return { text: c.text, meta: [c.type, c.source ? 'Source: ' + (c.source.html ? R.refLink(c.source) : R.escape(c.source.name)) : ''] };
    }))) { rendered.push('pheno-record-annotations'); }

    if (R.references(els.referencesBody, sections.references, els.referencesSection, 'pheno-ref')) {
      rendered.push('pheno-record-references');
    }

    rendered.forEach(function (id) { R.show(R.byId(id), true); });

    // Revealed before the charts are drawn: Plotly sizes a figure to its
    // container, and a hidden container has no width.
    R.show(R.byId('pheno-record-metrics'), true);
    if (renderMetrics(counts, sections.genes)) { rendered.push('pheno-record-metrics'); }

    R.tabs({
      el: els.tabs,
      order: rendered.concat(['pheno-record-resources', 'pheno-record-api']),
      labels: LABELS, counts: counts, tabCounts: TAB_COUNTS
    });

    R.notice(els.notice, meta, counts);
    MGDB.announce('Record loaded, ' + rendered.length + ' sections.');
  }

  function load() {
    var main = R.byId('pheno-record-top');
    if (!main) { return; }
    var requested = main.getAttribute('data-requested-id') || main.getAttribute('data-canonical-id');
    if (!requested) { return; }

    R.show(els.error, false);
    R.show(els.loading, true);

    MGDB.request('/api/v1/records/phenotype/' + encodeURIComponent(requested), { key: 'phenotype-record' })
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
      synonyms: R.byId('pheno-record-synonyms'),
      facts: R.byId('pheno-record-facts'),
      tabs: R.byId('pheno-record-tabs'),
      loading: R.byId('pheno-record-loading'),
      error: R.byId('pheno-record-error'),
      retry: R.byId('pheno-record-retry'),
      notice: R.byId('pheno-record-notice'),
      overviewBody: R.byId('pheno-record-overview-body'),
      genesBody: R.byId('pheno-record-genes-body'),
      variationsBody: R.byId('pheno-record-variations-body'),
      stocksBody: R.byId('pheno-record-stocks-body'),
      imagesBody: R.byId('pheno-record-images-body'),
      offsiteBody: R.byId('pheno-record-offsite-body'),
      annotationsBody: R.byId('pheno-record-annotations-body'),
      referencesBody: R.byId('pheno-record-references-body'),
      referencesSection: R.byId('pheno-record-references'),
      metricsBody: R.byId('pheno-record-metrics-body')
    };
    if (els.retry) { els.retry.addEventListener('click', load); }
    R.apiCard('pheno-copy-json-btn', 'pheno-record-api-link', function () { return payload; });
    load();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
