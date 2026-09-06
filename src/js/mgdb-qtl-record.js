/* file: js/mgdb-qtl-record.js
 *
 * The QTL experiment record page. One request to /api/v1/records/qtl/{id}
 * fills every section; the shared record shell supplies the collections, tabs
 * and API card.
 */
(function () {
  'use strict';

  var R = window.MGDBRecord;
  if (!R) { return; }

  /* Filled by init(): page scripts are emitted in <head>, so nothing exists at
     parse time. */
  var root = null, recordId = null, requested = null, analysisId = null, ENDPOINT = null;
  var payload = null;

  var SECTION_LABELS = {
    'qtl-record-overview': 'Overview',
    'qtl-record-evaluations': 'Traits evaluated',
    'qtl-record-loci': 'QTL detected',
    'qtl-record-maps': 'Maps',
    'qtl-record-references': 'References',
    'qtl-record-resources': 'Related resources'
  };

  function refText(ref) { return ref ? ref.name : ''; }
  function refHtml(ref) {
    return ref ? (R.refLink(ref) || R.escape(ref.name)) : '<span class="mgdb-muted">&mdash;</span>';
  }

  function renderOverview(data) {
    var out = R.byId('qtl-record-overview-body');
    if (!out) { return false; }
    out.innerHTML = '';
    var o = data.overview;
    if (!o) { return false; }
    var any = false;

    var pairs = [];
    if (o.mapping_panel) {
      pairs.push(['Mapping panel', refHtml(o.mapping_panel)]);
    }
    if (o.progeny_genotyped) { pairs.push(['Progeny genotyped', R.escape(o.progeny_genotyped)]); }
    if (o.progeny_trait_evaluated) {
      pairs.push(['Progeny trait-evaluated', R.escape(o.progeny_trait_evaluated)]);
    }
    if (o.marker_summary) { pairs.push(['Markers', R.escape(o.marker_summary)]); }

    if (o.contributors && o.contributors.length) {
      pairs.push(['Contributors', o.contributors.map(function (c) {
        var who = refHtml(c.person);
        return c.role ? who + ' <span class="mgdb-muted">(' + R.escape(c.role) + ')</span>' : who;
      }).join(', ')]);
    }

    var factsHtml = R.facts(pairs);
    if (factsHtml) { out.insertAdjacentHTML('beforeend', factsHtml); any = true; }

    /* The study's own caveats -- usually what else was measured and found
       nothing, which is exactly what a reader of a QTL count needs to know. */
    var comments = (o.comments || []).map(function (t) { return { text: t, meta: [] }; });
    if (R.notes(out, 'Additional comments', comments)) { any = true; }

    return any;
  }

  function renderEvaluations(data) {
    var out = R.byId('qtl-record-evaluations-body');
    if (!out) { return false; }
    out.innerHTML = '';
    return R.collection(out, {
      title: 'Traits evaluated in this experiment',
      items: data.evaluations || [],
      filename: 'qtl-traits-evaluated.tsv',
      columns: [
        { key: 'trait', label: 'Trait',
          get: function (e) { return refText(e.trait); },
          html: function (e) { return refHtml(e.trait); } },
        { key: 'analysis', label: 'Trait analysis',
          get: function (e) { return refText(e.analysis); },
          html: function (e) {
            var name = e.analysis ? R.escape(e.analysis.name) : '&mdash;';
            /* The analysis is a row of this table, not a page of its own, so
               it is marked rather than linked; the id anchors the row so a hub
               link can scroll straight to it. */
            return e.analysis
              ? '<strong id="analysis-' + R.escape(String(e.analysis.id)) + '">' + name + '</strong>'
              : '<span class="mgdb-muted">&mdash;</span>';
          } },
        { key: 'method', label: 'Evaluation method',
          get: function (e) { return e.method || ''; },
          html: function (e) {
            return e.method ? R.escape(e.method) : '<span class="mgdb-muted">Not recorded</span>';
          } },
        { key: 'design', label: 'Experimental design',
          get: function (e) { return e.experimental_design || ''; },
          html: function (e) {
            return e.experimental_design ? R.escape(e.experimental_design)
                                         : '<span class="mgdb-muted">&mdash;</span>';
          } },
        { key: 'environment', label: 'Environment',
          get: function (e) { return refText(e.environment); },
          html: function (e) { return refHtml(e.environment); } },
        { key: 'linkage', label: 'Linkage analysis',
          get: function (e) {
            return (e.linkage_analyses || []).map(refText).join('; ');
          },
          html: function (e) {
            var list = e.linkage_analyses || [];
            if (!list.length) { return '<span class="mgdb-muted">None recorded</span>'; }
            return list.map(refHtml).join(', ');
          } }
      ]
    });
  }

  function renderLoci(data) {
    var out = R.byId('qtl-record-loci-body');
    if (!out) { return false; }
    out.innerHTML = '';
    return R.collection(out, {
      title: 'QTL detected by this experiment',
      items: data.loci || [],
      filename: 'qtl-detected.tsv',
      columns: [
        R.recordColumn('QTL', 'locus', function (l) { return l.locus; }),
        { key: 'full_name', label: 'Name',
          get: function (l) { return l.full_name || ''; },
          html: function (l) {
            return l.full_name ? R.escape(l.full_name) : '<span class="mgdb-muted">&mdash;</span>';
          } },
        { key: 'bin', label: 'Bin',
          get: function (l) { return l.bin || ''; },
          html: function (l) {
            return l.bin ? R.escape(l.bin) : '<span class="mgdb-muted">&mdash;</span>';
          } },
        { key: 'r_squared', label: 'R²',
          get: function (l) { return l.r_squared === null ? '' : String(l.r_squared); },
          html: function (l) {
            return l.r_squared === null ? '<span class="mgdb-muted">&mdash;</span>'
                                        : R.escape(String(l.r_squared));
          } },
        { key: 'effect', label: 'Add/dom effects',
          get: function (l) { return l.effect || ''; },
          html: function (l) {
            return l.effect ? R.escape(l.effect) : '<span class="mgdb-muted">&mdash;</span>';
          } },
        { key: 'high_scoring_variation', label: 'High-scoring variation',
          get: function (l) { return refText(l.high_scoring_variation); },
          html: function (l) { return refHtml(l.high_scoring_variation); } },
        { key: 'linkage_analysis', label: 'Detected by',
          get: function (l) { return refText(l.linkage_analysis); },
          html: function (l) { return refHtml(l.linkage_analysis); } },
        R.urlColumn(function (l) { return l.locus ? l.locus.html : ''; })
      ]
    });
  }

  function renderMaps(data) {
    var out = R.byId('qtl-record-maps-body');
    if (!out) { return false; }
    out.innerHTML = '';
    return R.collection(out, {
      title: 'Maps this experiment was placed on',
      items: data.maps || [],
      filename: 'qtl-maps.tsv',
      columns: [
        R.recordColumn('Map', 'map', function (m) { return m.map; }),
        R.urlColumn(function (m) { return m.map ? m.map.html : ''; })
      ]
    });
  }

  function renderReferences(data) {
    var out = R.byId('qtl-record-references-body');
    if (!out) { return false; }
    out.innerHTML = '';
    return R.collection(out, {
      title: 'References describing this experiment',
      items: data.references || [],
      filename: 'qtl-references.tsv',
      columns: [
        { key: 'reference', label: 'Reference',
          get: function (r) { return refText(r.reference); },
          html: function (r) { return refHtml(r.reference); } },
        { key: 'year', label: 'Year',
          get: function (r) { return r.year === null ? '' : String(r.year); },
          html: function (r) {
            return r.year === null ? '<span class="mgdb-muted">&mdash;</span>' : R.escape(String(r.year));
          } },
        R.urlColumn(function (r) { return r.reference ? r.reference.html : ''; })
      ]
    });
  }

  function fillSynonyms(attributes) {
    var el = R.byId('qtl-record-synonyms');
    if (!el) { return; }
    var list = attributes.synonyms || [];
    if (!list.length) { return; }
    el.innerHTML = '<span class="mgdb-rec-synonyms-label">Also known as</span> ' +
      list.map(function (s) { return R.escape(s.name); }).join(', ');
    R.show(el, true);
  }

  /* A reader who followed a hub result asked for one trait, not the whole
     study. Once the table exists, mark that row and bring it into view. */
  function highlightAnalysis() {
    if (!analysisId) { return; }
    var cell = document.getElementById('analysis-' + analysisId);
    if (!cell) { return; }
    var row = cell.closest ? cell.closest('tr') : null;
    if (!row) { return; }
    row.classList.add('qtl-row-current');
    var section = R.byId('qtl-record-evaluations');
    if (section && !section.hidden) {
      row.scrollIntoView({ block: 'center', behavior: 'auto' });
    }
  }

  function render(doc) {
    var data = doc.data.sections || {};
    var counts = (doc.meta && doc.meta.counts) || {};
    payload = doc;

    fillSynonyms(doc.data.attributes || {});

    var rendered = {
      'qtl-record-overview': renderOverview(data),
      'qtl-record-evaluations': renderEvaluations(data),
      'qtl-record-loci': renderLoci(data),
      'qtl-record-maps': renderMaps(data),
      'qtl-record-references': renderReferences(data)
    };

    var order = [];
    Object.keys(SECTION_LABELS).forEach(function (id) {
      if (id === 'qtl-record-resources') { order.push(id); return; }
      var el = R.byId(id);
      if (!el) { return; }
      if (rendered[id]) { R.show(el, true); order.push(id); }
      else { R.show(el, false); }
    });

    R.tabs({
      el: R.byId('qtl-record-tabs'),
      order: order,
      labels: SECTION_LABELS,
      counts: counts,
      tabCounts: {
        'qtl-record-evaluations': ['evaluations'],
        'qtl-record-loci': ['loci'],
        'qtl-record-maps': ['maps'],
        'qtl-record-references': ['references']
      }
    });

    R.show(R.byId('qtl-record-loading'), false);
    highlightAnalysis();
  }

  function load() {
    R.show(R.byId('qtl-record-error'), false);
    R.show(R.byId('qtl-record-loading'), true);
    fetch(ENDPOINT, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
      .then(render)
      .catch(function () {
        R.show(R.byId('qtl-record-loading'), false);
        R.show(R.byId('qtl-record-error'), true);
      });
  }

  function init() {
    root = R.byId('qtl-record-top');
    if (!root) { return; }
    recordId = root.getAttribute('data-record-id');
    requested = root.getAttribute('data-requested-id') || recordId;
    analysisId = root.getAttribute('data-analysis-id') || '';
    ENDPOINT = '/api/v1/records/qtl/' + encodeURIComponent(requested);

    var retry = R.byId('qtl-record-retry');
    if (retry) { retry.addEventListener('click', load); }
    R.apiCard('qtl-copy-json-btn', 'qtl-record-api-link', function () { return payload; });
    load();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
