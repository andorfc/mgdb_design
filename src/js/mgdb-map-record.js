/* ==========================================================================
   Map record page — /data_center/map/{id}
   --------------------------------------------------------------------------
   Glue over js/mgdb-record.js, the same engine the gene product and variation
   record pages use. This file maps one call to /api/v1/records/map/{id} onto
   it, and adds the one figure that belongs to a map: marker density.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  var R = window.MGDBRecord;
  if (!MGDB || !R) { return; }

  var els = {};
  var payload = null;
  var unit = 'cM';

  function coordinate(value) {
    return value === null || value === undefined ? '' : Number(value).toFixed(1);
  }

  /* ------------------------------------------------------------------------
     Overview
     ------------------------------------------------------------------------ */

  function renderOverview(overview) {
    if (!overview) { return false; }
    var out = els.overviewBody;
    out.innerHTML = '';

    var span = '';
    if (overview.min_coord !== null && overview.max_coord !== null) {
      span = coordinate(overview.min_coord) + ' – ' + coordinate(overview.max_coord) + ' ' + R.escape(unit);
    }

    var factsHtml = R.facts([
      ['Chromosome', overview.linkage_group && overview.linkage_group !== '—' ? R.escape(overview.linkage_group) : ''],
      ['Coordinate type', overview.coordinate_type ? R.escape(overview.coordinate_type) : ''],
      ['Mapped loci', overview.locus_count ? R.number(overview.locus_count) : ''],
      ['Span', span],
      ['Source', overview.author ? (R.refLink(overview.author) || R.escape(overview.author.name)) : '']
    ]);
    if (factsHtml) { out.insertAdjacentHTML('beforeend', factsHtml); }

    var notes = (overview.memos || []).filter(Boolean).map(function (text) {
      return { text: text, meta: ['Curator note'] };
    });
    var hasNotes = R.notes(out, 'Notes on this map', notes);

    return !!factsHtml || hasNotes;
  }

  /* ------------------------------------------------------------------------
     Metrics and figures
     ------------------------------------------------------------------------ */

  function renderMetrics(counts, overview, references) {
    R.metrics(els.metricsBody, [
      ['Mapped loci', 'Coordinates', counts.coordinates, 'Loci placed on this map with a coordinate.', 'green'],
      ['Maps in this series', 'Series', counts.sister_maps, 'The other chromosomes of the same map series.', 'amber'],
      ['Maps on this chromosome', 'Chromosome', counts.same_chromosome_maps, 'Other maps of the same chromosome, for comparison.', 'blue'],
      ['References', 'Literature', counts.references, 'Curated publications attached to this record.', 'burgundy']
    ]);

    /* Marker density comes from the API, bucketed over every locus on the map
       rather than the page of 500 the client is sent -- a histogram built from
       the capped list would describe the cap, not the map. */
    var distribution = (overview && overview.distribution) || [];
    if (distribution.length > 1 && MGDB.chart) {
      R.show(R.byId('map-record-density-figure'), true);
      var height = R.sizeChart('map-record-density-chart', 320);
      var busiest = distribution.slice().sort(function (a, b) { return b.loci - a.loci; })[0];
      R.byId('map-record-density-caption').textContent =
        R.number(counts.coordinates) + ' loci across ' + distribution.length +
        ' intervals of about ' + coordinate((overview.max_coord - overview.min_coord) / distribution.length) +
        ' ' + unit + '. The densest interval starts at ' + coordinate(busiest.start) +
        ' ' + unit + ' and holds ' + R.number(busiest.loci) + '.';

      MGDB.chart({
        target: 'map-record-density-chart',
        traces: function () {
          return [{
            type: 'bar',
            x: distribution.map(function (d) { return d.start; }),
            y: distribution.map(function (d) { return d.loci; }),
            marker: { color: '#285d46' },
            hovertemplate: 'From %{x} ' + unit + '<br>%{y:,} loci<extra></extra>'
          }];
        },
        layout: {
          height: height,
          margin: { l: 56, r: 16, t: 8, b: 48 },
          bargap: 0.08,
          xaxis: { title: { text: 'Position (' + unit + ')' }, automargin: true },
          yaxis: { title: { text: 'Loci' }, rangemode: 'tozero', automargin: true }
        }
      });
      R.watchChartWidth('map-record-density-chart');
    }

    R.connectionsChart('map-record-connections-chart', 'map-record-connections-caption', 'map-record-connections-figure', [
      ['Mapped loci', counts.coordinates], ['Maps in this series', counts.sister_maps],
      ['Maps on this chromosome', counts.same_chromosome_maps],
      ['QTL experiments', counts.qtl_experiments], ['References', counts.references],
      ['Curator notes', counts.memos]
    ]);
    void references;
    return true;
  }

  /* ------------------------------------------------------------------------
     Assembly
     ------------------------------------------------------------------------ */

  var TAB_COUNTS = {
    'map-record-overview': ['memos'],
    'map-record-loci': ['coordinates'],
    'map-record-series': ['sister_maps'],
    'map-record-alt': ['same_chromosome_maps'],
    'map-record-qtls': ['qtl_experiments'],
    'map-record-references': ['references']
  };

  var LABELS = {
    'map-record-overview': 'Overview',
    'map-record-loci': 'Mapped loci',
    'map-record-series': 'Maps in this series',
    'map-record-alt': 'Other maps on this chromosome',
    'map-record-qtls': 'QTL experiments',
    'map-record-references': 'References',
    'map-record-metrics': 'Metrics',
    'map-record-resources': 'Related resources',
    'map-record-api': 'API'
  };

  function render(response) {
    payload = response;
    var data = response.data || {};
    var sections = data.sections || {};
    var meta = response.meta || {};
    var counts = meta.counts || {};
    var overview = sections.overview;
    var related = sections.related_maps || {};
    unit = (data.attributes && data.attributes.coordinate_type) || 'cM';

    R.show(els.loading, false);
    R.show(els.error, false);

    var rendered = [];
    if (renderOverview(overview)) { rendered.push('map-record-overview'); }

    if (R.collection(els.lociBody, {
      title: 'Loci placed on this map',
      items: sections.coordinates,
      filename: 'map-loci.tsv',
      pageSize: 25,
      columns: [
        R.recordColumn('Locus', 'name', function (l) { return l; }),
        { key: 'full_name', label: 'Full name' },
        { key: 'coordinate', label: 'Position (' + unit + ')', sort: 'number', numeric: true,
          get: function (l) { return coordinate(l.coordinate); } },
        { key: 'bin', label: 'Bin' },
        { key: 'locus_type', label: 'Type' },
        { key: 'is_backbone', label: 'Backbone',
          get: function (l) { return l.is_backbone ? 'Yes' : 'No'; },
          html: function (l) { return l.is_backbone
            ? '<span class="mgdb-pill mgdb-pill-ok">Backbone</span>'
            : '<span class="mgdb-muted">—</span>'; } },
        R.urlColumn(function (l) { return l.html; })
      ]
    })) { rendered.push('map-record-loci'); }

    if (R.collection(els.seriesBody, {
      title: related.series_name ? 'Other chromosomes of ' + related.series_name : 'Maps in this series',
      items: related.sister_maps,
      filename: 'map-series.tsv',
      columns: [
        R.recordColumn('Map', 'name', function (m) { return m; }),
        { key: 'linkage_group', label: 'Chromosome' },
        { key: 'locus_count', label: 'Mapped loci', sort: 'number', numeric: true,
          get: function (m) { return R.number(m.locus_count); } },
        R.urlColumn(function (m) { return m.html; })
      ]
    })) { rendered.push('map-record-series'); }

    if (R.collection(els.altBody, {
      title: 'Other maps of this chromosome',
      items: related.same_chromosome_maps,
      filename: 'map-same-chromosome.tsv',
      columns: [
        R.recordColumn('Map', 'name', function (m) { return m; }),
        { key: 'locus_count', label: 'Mapped loci', sort: 'number', numeric: true,
          get: function (m) { return R.number(m.locus_count); } },
        { key: 'compare', label: 'Compare', sort: false,
          get: function (m) { return R.absoluteUrl(m.compare_html); },
          html: function (m) { return m.compare_html
            ? '<a href="' + R.escape(m.compare_html) + '">Compare with this map <span aria-hidden="true">&rarr;</span></a>'
            : '—'; } },
        R.urlColumn(function (m) { return m.html; })
      ]
    })) { rendered.push('map-record-alt'); }

    if (R.collection(els.qtlBody, {
      title: 'QTL experiments mapped here',
      items: sections.qtl_experiments,
      filename: 'map-qtl-experiments.tsv',
      columns: [
        R.recordColumn('Experiment', 'name', function (q) { return q; }),
        { key: 'trait', label: 'Traits measured' },
        R.urlColumn(function (q) { return q.html; })
      ]
    })) { rendered.push('map-record-qtls'); }

    if (R.references(els.referencesBody, sections.references, els.referencesSection, 'map-ref')) {
      rendered.push('map-record-references');
    }

    rendered.forEach(function (id) { R.show(R.byId(id), true); });

    // Revealed before the charts are drawn: Plotly sizes a figure to its
    // container, and a hidden container has no width.
    R.show(R.byId('map-record-metrics'), true);
    if (renderMetrics(counts, overview, sections.references)) { rendered.push('map-record-metrics'); }

    R.tabs({
      el: els.tabs,
      order: rendered.concat(['map-record-resources', 'map-record-api']),
      labels: LABELS, counts: counts, tabCounts: TAB_COUNTS
    });

    R.notice(els.notice, meta, counts);
    MGDB.announce('Record loaded, ' + rendered.length + ' sections.');
  }

  function load() {
    var main = R.byId('map-record-top');
    if (!main) { return; }
    var requested = main.getAttribute('data-requested-id') || main.getAttribute('data-canonical-id');
    if (!requested) { return; }

    R.show(els.error, false);
    R.show(els.loading, true);

    MGDB.request('/api/v1/records/map/' + encodeURIComponent(requested), { key: 'map-record' })
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
      synonyms: R.byId('map-record-synonyms'),
      facts: R.byId('map-record-facts'),
      tabs: R.byId('map-record-tabs'),
      loading: R.byId('map-record-loading'),
      error: R.byId('map-record-error'),
      retry: R.byId('map-record-retry'),
      notice: R.byId('map-record-notice'),
      overviewBody: R.byId('map-record-overview-body'),
      lociBody: R.byId('map-record-loci-body'),
      seriesBody: R.byId('map-record-series-body'),
      altBody: R.byId('map-record-alt-body'),
      qtlBody: R.byId('map-record-qtls-body'),
      referencesBody: R.byId('map-record-references-body'),
      referencesSection: R.byId('map-record-references'),
      metricsBody: R.byId('map-record-metrics-body')
    };
    if (els.retry) { els.retry.addEventListener('click', load); }
    R.apiCard('map-copy-json-btn', 'map-record-api-link', function () { return payload; });
    load();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
