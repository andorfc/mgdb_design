/* ==========================================================================
   Linkage group record page — /data_center/lg/{id}
   --------------------------------------------------------------------------
   Glue over js/mgdb-record.js, the same engine the map, gene product and
   variation record pages use. This file maps one call to
   /api/v1/records/linkage_group/{id} onto it.

   The one thing this record needs that the others do not: a maize chromosome
   carries between 39,771 and 94,465 loci. The API caps the list like every
   other collection, so the section states the full count and hands the reader
   to the Locus hub filtered to this chromosome rather than pretending the
   page of 500 is the answer.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  var R = window.MGDBRecord;
  if (!MGDB || !R) { return; }

  var els = {};
  var payload = null;
  var locusHub = '/data_center/locus';

  function length(value, unit) {
    if (value === null || value === undefined) { return ''; }
    return R.number(Math.round(Number(value) * 100) / 100) + ' ' + unit;
  }

  /* ------------------------------------------------------------------------
     Overview
     ------------------------------------------------------------------------ */

  function renderOverview(overview, counts) {
    if (!overview) { return false; }
    var out = els.overviewBody;
    out.innerHTML = '';

    var factsHtml = R.facts([
      ['Type', overview.type ? R.escape(overview.type) : ''],
      ['Species', overview.species ? '<em>' + R.escape(overview.species) + '</em>' : ''],
      ['Chromosome', overview.chromosome ? R.escape(overview.chromosome) : ''],
      ['Morphology', overview.morphology ? R.escape(overview.morphology) : ''],
      ['Total length', length(overview.length_kb, 'kb')],
      ['Genetic length', length(overview.length_cm, 'cM')],
      ['Loci placed here', counts.loci ? R.number(counts.loci) : ''],
      ['Maps', counts.maps ? R.number(counts.maps) : '']
    ]);
    if (factsHtml) { out.insertAdjacentHTML('beforeend', factsHtml); }

    var notes = (overview.notes || []).map(function (note) {
      return { text: note.text, meta: [note.source] };
    });
    var hasNotes = R.notes(out, 'Notes on this linkage group', notes);

    return !!factsHtml || hasNotes;
  }

  function renderSynonyms(overview) {
    var list = (overview && overview.synonyms) || [];
    if (!els.synonyms || list.length === 0) { return; }
    els.synonyms.innerHTML = '<span class="mgdb-muted">Also known as</span> ' +
      list.map(function (name) { return R.escape(name); }).join(', ');
    R.show(els.synonyms, true);
  }

  /* ------------------------------------------------------------------------
     Metrics
     ------------------------------------------------------------------------ */

  function renderMetrics(counts) {
    R.metrics(els.metricsBody, [
      ['Loci placed here', 'Loci', counts.loci, 'Curated loci assigned to this linkage group.', 'green'],
      ['Maps', 'Maps', counts.maps, 'Chromosome maps built for this linkage group.', 'amber'],
      ['External records', 'Offsite', counts.external, 'Accessions for this record in GenBank and other databases.', 'blue'],
      ['References', 'Literature', counts.references, 'Curated publications attached to this record.', 'burgundy']
    ]);

    R.connectionsChart('lg-record-connections-chart', 'lg-record-connections-caption', 'lg-record-connections-figure', [
      ['Loci placed here', counts.loci], ['Maps', counts.maps],
      ['External records', counts.external], ['References', counts.references],
      ['Synonyms', counts.synonyms], ['Curator notes', counts.notes]
    ]);
    return true;
  }

  /* ------------------------------------------------------------------------
     Assembly
     ------------------------------------------------------------------------ */

  var TAB_COUNTS = {
    'lg-record-overview': ['notes'],
    'lg-record-maps': ['maps'],
    'lg-record-loci': ['loci'],
    'lg-record-external': ['external'],
    'lg-record-references': ['references']
  };

  var LABELS = {
    'lg-record-overview': 'Overview',
    'lg-record-maps': 'Maps',
    'lg-record-loci': 'Loci',
    'lg-record-external': 'External records',
    'lg-record-references': 'References',
    'lg-record-metrics': 'Metrics',
    'lg-record-resources': 'Related resources',
    'lg-record-api': 'API'
  };

  function render(response) {
    payload = response;
    var data = response.data || {};
    var sections = data.sections || {};
    var meta = response.meta || {};
    var counts = meta.counts || {};
    var overview = sections.overview;

    R.show(els.loading, false);
    R.show(els.error, false);

    var rendered = [];
    renderSynonyms(overview);
    if (renderOverview(overview, counts)) { rendered.push('lg-record-overview'); }

    if (R.collection(els.mapsBody, {
      title: 'Maps built for this linkage group',
      items: sections.maps,
      filename: 'linkage-group-maps.tsv',
      pageSize: 25,
      columns: [
        R.recordColumn('Map', 'name', function (m) { return m; }),
        { key: 'coordinate_type', label: 'Coordinates' },
        { key: 'locus_count', label: 'Mapped loci', sort: 'number', numeric: true,
          get: function (m) { return R.number(m.locus_count); } },
        R.urlColumn(function (m) { return m.html; })
      ]
    })) { rendered.push('lg-record-maps'); }

    if (R.collection(els.lociBody, {
      title: 'Loci placed on this linkage group',
      items: sections.loci,
      filename: 'linkage-group-loci.tsv',
      pageSize: 25,
      columns: [
        R.recordColumn('Locus', 'name', function (l) { return l; }),
        { key: 'full_name', label: 'Full name' },
        { key: 'locus_type', label: 'Type' },
        R.urlColumn(function (l) { return l.html; })
      ]
    })) {
      rendered.push('lg-record-loci');
      /* The list is capped and the count is not. Say so where the reader is
         looking, and give them the hub that can actually return all of them
         -- a truncation notice at the top of the page does not tell anybody
         what to do about it. */
      var shown = (sections.loci || []).length;
      if (counts.loci > shown) {
        els.lociBody.insertAdjacentHTML('beforeend',
          '<p class="mgdb-rec-block-status">Showing the first ' + R.number(shown) +
          ' of ' + R.number(counts.loci) + ' loci, in name order. ' +
          '<a href="' + R.escape(locusHub) + '">Open all of them in the Gene and Locus Data Hub</a>, ' +
          'where they can be filtered, sorted and downloaded.</p>');
      }
    }

    if (R.collection(els.externalBody, {
      title: 'This linkage group in other databases',
      items: sections.overview ? sections.overview.external : [],
      filename: 'linkage-group-external.tsv',
      columns: [
        { key: 'database', label: 'Database' },
        { key: 'accession', label: 'Accession',
          html: function (e) {
            return e.html
              ? '<a href="' + R.escape(e.html) + '" target="_blank" rel="noopener">' +
                R.escape(e.accession) + ' <span aria-hidden="true">&nearr;</span></a>'
              : R.escape(e.accession);
          } }
      ]
    })) { rendered.push('lg-record-external'); }

    if (R.references(els.referencesBody, sections.references, els.referencesSection, 'lg-ref')) {
      rendered.push('lg-record-references');
    }

    rendered.forEach(function (id) { R.show(R.byId(id), true); });

    // Revealed before the chart is drawn: Plotly sizes a figure to its
    // container, and a hidden container has no width.
    R.show(R.byId('lg-record-metrics'), true);
    if (renderMetrics(counts)) { rendered.push('lg-record-metrics'); }

    R.tabs({
      el: els.tabs,
      order: rendered.concat(['lg-record-resources', 'lg-record-api']),
      labels: LABELS, counts: counts, tabCounts: TAB_COUNTS
    });

    R.notice(els.notice, meta, counts);
    MGDB.announce('Record loaded, ' + rendered.length + ' sections.');
  }

  function load() {
    var main = R.byId('lg-record-top');
    if (!main) { return; }
    var requested = main.getAttribute('data-requested-id') || main.getAttribute('data-canonical-id');
    if (!requested) { return; }
    locusHub = main.getAttribute('data-locus-hub') || locusHub;

    R.show(els.error, false);
    R.show(els.loading, true);

    MGDB.request('/api/v1/records/linkage_group/' + encodeURIComponent(requested), { key: 'lg-record' })
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
      synonyms: R.byId('lg-record-synonyms'),
      facts: R.byId('lg-record-facts'),
      tabs: R.byId('lg-record-tabs'),
      loading: R.byId('lg-record-loading'),
      error: R.byId('lg-record-error'),
      retry: R.byId('lg-record-retry'),
      notice: R.byId('lg-record-notice'),
      overviewBody: R.byId('lg-record-overview-body'),
      mapsBody: R.byId('lg-record-maps-body'),
      lociBody: R.byId('lg-record-loci-body'),
      externalBody: R.byId('lg-record-external-body'),
      referencesBody: R.byId('lg-record-references-body'),
      referencesSection: R.byId('lg-record-references'),
      metricsBody: R.byId('lg-record-metrics-body')
    };
    if (els.retry) { els.retry.addEventListener('click', load); }
    R.apiCard('lg-copy-json-btn', 'lg-record-api-link', function () { return payload; });
    load();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
