/* ==========================================================================
   Variation record page — /data_center/variation?id={id}
   --------------------------------------------------------------------------
   Glue over js/mgdb-record.js, the same engine the gene product record page
   uses. This file maps one call to /api/v1/records/variation/{id} onto it.
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
    var synonyms = (data.attributes || {}).synonyms || [];
    if (synonyms.length) {
      // Variation synonyms often contain commas, so they are separated by a
      // middle dot rather than joined into one comma list.
      els.synonyms.innerHTML = 'Also known as ' + synonyms.map(function (s) {
        return '<strong>' + R.escape(s.name) + '</strong>' +
               (s.authority ? ' <span class="mgdb-muted">(per ' + R.refLink(s.authority) + ')</span>' : '');
      }).join(' <span class="mgdb-muted" aria-hidden="true">&middot;</span> ') + '.';
      R.show(els.synonyms, true);
    }

    // The server rendered type, locus, dominance and id; the API adds the rest.
    var extra = '';
    if (overview && overview.viability && overview.viability.name) {
      extra += '<div><dt>Viability</dt><dd>' + R.escape(overview.viability.name) + '</dd></div>';
    }
    if (overview && overview.allele_descriptor) {
      extra += '<div><dt>Allele descriptor</dt><dd>' + R.escape(overview.allele_descriptor) + '</dd></div>';
    }
    if (extra) { els.facts.insertAdjacentHTML('beforeend', extra); }
  }

  /* ------------------------------------------------------------------------
     Overview
     ------------------------------------------------------------------------ */

  function termList(items) {
    return (items || []).map(function (t) { return R.escape(t.name); }).join(', ');
  }

  function renderOverview(overview) {
    if (!overview) { return false; }
    var out = els.overviewBody;
    out.innerHTML = '';

    var locus = '';
    if (overview.locus) {
      locus = R.refLink(overview.locus) || R.escape(overview.locus.name);
    }

    var factsHtml = R.facts([
      ['Variation type', overview.type ? R.escape(overview.type.name) : ''],
      ['Species', overview.species ? '<em>' + R.escape(overview.species.name) + '</em>' : ''],
      ['Locus', locus, overview.locus_full_name || ''],
      ['Dominance', overview.dominance ? R.escape(overview.dominance.name) : ''],
      ['Viability', overview.viability ? R.escape(overview.viability.name) : ''],
      ['Allele descriptor', overview.allele_descriptor ? R.escape(overview.allele_descriptor) : ''],
      ['Function', overview.function ? R.escape(overview.function) : ''],
      ['Polymorphism', overview.polymorphism ? R.escape(overview.polymorphism) : ''],
      ['Strand', overview.strand ? R.escape(overview.strand) : ''],
      ['Inbred', overview.inbred ? (R.refLink(overview.inbred) || R.escape(overview.inbred.name)) : ''],
      ['Progenitor stock', overview.progenitor_stock ? (R.refLink(overview.progenitor_stock) || R.escape(overview.progenitor_stock.name)) : ''],
      ['Mutagens', termList(overview.mutagens)],
      ['Mutation types', termList(overview.mutation_types)],
      ['Properties', termList(overview.properties)]
    ]);
    if (factsHtml) { out.insertAdjacentHTML('beforeend', factsHtml); }
    var rendered = !!factsHtml;

    rendered = R.collection(out, {
      title: 'Genome positions',
      items: overview.genome_positions,
      filename: 'variation-genome-positions.tsv',
      columns: [
        { key: 'reference_sequence', label: 'Reference sequence', tile: true },
        { key: 'chromosome', label: 'Chromosome' },
        { key: 'start', label: 'Start', sort: 'number', numeric: true,
          get: function (p) { return p.start == null ? '' : R.number(p.start); } },
        { key: 'end', label: 'End', sort: 'number', numeric: true,
          get: function (p) { return p.end == null ? '' : R.number(p.end); } },
        { key: 'source', label: 'Source' }
      ]
    }) || rendered;

    return rendered;
  }

  /* ------------------------------------------------------------------------
     Related records
     ------------------------------------------------------------------------ */

  function renderRelated(related) {
    if (!related) { return false; }
    var out = els.relatedBody;
    out.innerHTML = '';
    var rendered = false;

    rendered = R.collection(out, {
      title: 'Related variations',
      items: related.variations,
      filename: 'variation-related-variations.tsv',
      columns: [
        R.recordColumn('Variation', 'name', function (v) { return v; }),
        { key: 'relationship', label: 'Relationship' },
        R.urlColumn(function (v) { return v.html; })
      ]
    }) || rendered;

    rendered = R.collection(out, {
      title: 'Recombination, gel patterns, and external databases',
      items: related.other_records,
      filename: 'variation-other-records.tsv',
      columns: [
        { key: 'name', label: 'Name', tile: true,
          html: function (x) { return x.url ? R.link(x.url, x.name) : R.escape(x.name); } },
        { key: 'kind', label: 'Record type' },
        { key: 'detail', label: 'Detail' }
      ]
    }) || rendered;

    rendered = R.collection(out, {
      title: 'Breakpoints',
      items: related.breakpoints,
      filename: 'variation-breakpoints.tsv',
      columns: [
        R.recordColumn('Locus', 'name', function (b) { return b; }),
        { key: 'linkage_group', label: 'Linkage group' },
        { key: 'arm', label: 'Arm' },
        { key: 'cytological_position', label: 'Cytological position' },
        R.urlColumn(function (b) { return b.html; })
      ]
    }) || rendered;

    return rendered;
  }

  /* ------------------------------------------------------------------------
     Metrics
     ------------------------------------------------------------------------ */

  function renderMetrics(counts, references) {
    R.metrics(els.metricsBody, [
      ['Phenotypes', 'Traits', counts.phenotypes, 'Curated phenotypic effects recorded for this variation.', 'green'],
      ['Stocks', 'Germplasm', counts.stocks, 'Stocks recorded as carrying this variation.', 'amber'],
      ['References', 'Literature', counts.references, 'Curated publications attached to this record.', 'blue'],
      ['Related variations', 'Relations', counts.related_variations, 'Alleles and variations recorded as related to this one.', 'burgundy']
    ]);

    R.connectionsChart('var-record-connections-chart', 'var-record-connections-caption', 'var-record-connections-figure', [
      ['Phenotypes', counts.phenotypes], ['Stocks', counts.stocks],
      ['Related variations', counts.related_variations], ['Genome positions', counts.positions],
      ['Breakpoints', counts.breakpoints], ['Recombinations', counts.recombinations],
      ['Gel patterns', counts.gel_patterns], ['External entries', counts.offsite],
      ['Images', counts.images], ['References', counts.references], ['Synonyms', counts.synonyms]
    ]);
    R.yearsChart('var-record-years-chart', 'var-record-years-caption', 'var-record-years-figure', references);
    return true;
  }

  /* ------------------------------------------------------------------------
     Assembly
     ------------------------------------------------------------------------ */

  var TAB_COUNTS = {
    'var-record-overview': ['positions'],
    'var-record-phenotypes': ['phenotypes'],
    'var-record-stocks': ['stocks'],
    'var-record-related': ['related_variations', 'recombinations', 'gel_patterns', 'breakpoints', 'offsite'],
    'var-record-annotations': ['annotations'],
    'var-record-images': ['images'],
    'var-record-references': ['references']
  };

  var LABELS = {
    'var-record-overview': 'Overview',
    'var-record-phenotypes': 'Phenotypes',
    'var-record-stocks': 'Stocks',
    'var-record-related': 'Related records',
    'var-record-annotations': 'Annotations',
    'var-record-images': 'Images',
    'var-record-references': 'References',
    'var-record-metrics': 'Metrics',
    'var-record-resources': 'Related resources',
    'var-record-api': 'API'
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
    if (renderOverview(sections.overview)) { rendered.push('var-record-overview'); }

    if (R.collection(els.phenotypesBody, {
      title: 'Phenotypic effects',
      items: sections.phenotypes,
      filename: 'variation-phenotypes.tsv',
      columns: [
        R.recordColumn('Phenotype', 'name', function (p) { return p; }),
        { key: 'id', label: 'MaizeGDB ID', sort: 'number' },
        R.urlColumn(function (p) { return p.html; })
      ]
    })) { rendered.push('var-record-phenotypes'); }

    if (R.collection(els.stocksBody, {
      title: 'Stocks carrying this variation',
      items: sections.stocks,
      filename: 'variation-stocks.tsv',
      columns: [
        R.recordColumn('Stock', 'name', function (s) { return s; }),
        { key: 'association', label: 'Association' },
        { key: 'provider', label: 'Provider' },
        { key: 'availability', label: 'Availability',
          get: function (s) { return s.available_from_stock_center ? 'Stock Center' : 'Other provider'; } },
        R.urlColumn(function (s) { return s.html; })
      ]
    })) { rendered.push('var-record-stocks'); }

    if (renderRelated(sections.related)) { rendered.push('var-record-related'); }

    if (R.notes(els.annotationsBody, 'Curator notes and annotations', (sections.annotations || []).map(function (a) {
      return { text: a.text, meta: [a.label || a.kind,
                                    a.authority && a.authority.name ? 'From ' + R.refLink(a.authority) : '',
                                    a.modified ? String(a.modified).slice(0, 10) : ''] };
    }))) { rendered.push('var-record-annotations'); }

    /* web_image carries no part or type for a variation, so the subject and
       the category come from the record itself, the way the stock record page
       names its own images. The caption is what tells them apart. */
    var variationName = (data.attributes || {}).name || 'Variation';
    if (R.images(els.imagesBody, (sections.images || []).map(function (image) {
      return {
        url: image.url,
        caption: image.caption,
        title: image.part || variationName,
        category: image.type || 'Variation / Mutant'
      };
    }), 'var-record-image-dialog', {
      title: 'Images of this variation',
      filename: 'variation-images.tsv'
    })) { rendered.push('var-record-images'); }
    if (R.references(els.referencesBody, sections.references, els.referencesSection, 'var-ref')) { rendered.push('var-record-references'); }

    rendered.forEach(function (id) { R.show(R.byId(id), true); });

    // Revealed before the charts are drawn: Plotly sizes a figure to its
    // container, and a hidden container has no width.
    R.show(R.byId('var-record-metrics'), true);
    if (renderMetrics(counts, sections.references)) { rendered.push('var-record-metrics'); }

    R.tabs({
      el: els.tabs,
      order: rendered.concat(['var-record-resources', 'var-record-api']),
      labels: LABELS, counts: counts, tabCounts: TAB_COUNTS
    });

    R.notice(els.notice, meta, counts);
    MGDB.announce('Record loaded, ' + rendered.length + ' sections.');
  }

  function load() {
    var main = R.byId('var-record-top');
    if (!main) { return; }
    var requested = main.getAttribute('data-requested-id') || main.getAttribute('data-canonical-id');
    if (!requested) { return; }

    R.show(els.error, false);
    R.show(els.loading, true);

    MGDB.request('/api/v1/records/variation/' + encodeURIComponent(requested), { key: 'variation-record' })
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
      synonyms: R.byId('var-record-synonyms'),
      facts: R.byId('var-record-facts'),
      tabs: R.byId('var-record-tabs'),
      loading: R.byId('var-record-loading'),
      error: R.byId('var-record-error'),
      retry: R.byId('var-record-retry'),
      notice: R.byId('var-record-notice'),
      overviewBody: R.byId('var-record-overview-body'),
      phenotypesBody: R.byId('var-record-phenotypes-body'),
      stocksBody: R.byId('var-record-stocks-body'),
      relatedBody: R.byId('var-record-related-body'),
      annotationsBody: R.byId('var-record-annotations-body'),
      imagesBody: R.byId('var-record-images-body'),
      referencesBody: R.byId('var-record-references-body'),
      referencesSection: R.byId('var-record-references'),
      metricsBody: R.byId('var-record-metrics-body')
    };
    if (els.retry) { els.retry.addEventListener('click', load); }
    R.apiCard('var-copy-json-btn', 'var-record-api-link', function () { return payload; });
    load();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
