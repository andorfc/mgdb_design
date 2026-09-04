/* file: js/mgdb-map-scores-record.js
 *
 * The map score record page. One request to /api/v1/records/map_scores/{id}
 * fills every section; the shared record shell supplies the collections, tabs
 * and API card.
 */
(function () {
  'use strict';

  var R = window.MGDBRecord;
  if (!R) { return; }

  /* Filled by init(): page scripts are emitted in <head>, so nothing exists at
     parse time. */
  var root = null, recordId = null, requested = null, ENDPOINT = null;
  var payload = null;

  var SECTION_LABELS = {
    'ms-record-overview': 'Overview',
    'ms-record-scores': 'Scores',
    'ms-record-maps': 'Maps',
    'ms-record-resources': 'Related resources'
  };

  function renderOverview(data) {
    var out = R.byId('ms-record-overview-body');
    if (!out) { return false; }
    out.innerHTML = '';
    var o = data.overview;
    if (!o) { return false; }
    var any = false;

    if (o.note) {
      out.insertAdjacentHTML('beforeend',
        '<div class="mgdb-rec-block"><p>' + R.escape(o.note) + '</p></div>');
      any = true;
    }

    var pairs = [];
    if (o.probed_site) {
      pairs.push(['Probed site', R.refLink(o.probed_site) || R.escape(o.probed_site.name),
                  o.probed_site_full_name]);
    }
    if (o.probe) {
      pairs.push(['Probe', R.refLink(o.probe) || R.escape(o.probe.name), o.probe_type]);
    }
    if (o.other_marker) {
      pairs.push(['Other marker', R.refLink(o.other_marker) || R.escape(o.other_marker.name),
                  o.other_marker_full_name]);
    }
    if (o.linkage_group) { pairs.push(['Chromosome', R.escape(o.linkage_group.name)]); }
    if (o.bin) { pairs.push(['Genetic bin', R.escape(o.bin)]); }
    if (o.enzyme) { pairs.push(['Enzyme', R.escape(o.enzyme)]); }
    if (o.panel_of_stocks) {
      pairs.push(['Panel of stocks', R.refLink(o.panel_of_stocks) || R.escape(o.panel_of_stocks.name)]);
    }
    if (o.parent1_pattern) {
      pairs.push(['Parent 1 gel pattern', R.refLink(o.parent1_pattern) || R.escape(o.parent1_pattern.name)]);
    }
    if (o.parent2_pattern) {
      pairs.push(['Parent 2 gel pattern', R.refLink(o.parent2_pattern) || R.escape(o.parent2_pattern.name)]);
    }
    if (o.submitted_by) {
      pairs.push(['Submitted by', R.refLink(o.submitted_by) || R.escape(o.submitted_by.name)]);
    }
    if (o.scored_on) { pairs.push(['Scored on', R.escape(o.scored_on)]); }

    var factsHtml = R.facts(pairs);
    if (factsHtml) { out.insertAdjacentHTML('beforeend', factsHtml); any = true; }

    if (R.notes(out, 'Comments', o.comments ? [{ text: o.comments, meta: [] }] : [])) { any = true; }
    return any;
  }

  /* The genotype string, monospaced and grouped in tens so a reader can count
     to a position in it. It is one character per stock in the panel, and the
     legacy page printed it as an undifferentiated run. */
  function renderScores(data) {
    var out = R.byId('ms-record-scores-body');
    if (!out) { return false; }
    out.innerHTML = '';
    var o = data.overview || {};
    if (!o.scores) { return false; }

    var raw = String(o.scores).replace(/\s+/g, '');
    var groups = [];
    for (var i = 0; i < raw.length; i += 10) { groups.push(raw.slice(i, i + 10)); }

    out.insertAdjacentHTML('beforeend',
      '<div class="mgdb-rec-block">' +
      '<div class="mgdb-rec-block-head"><h3>Genotype string' +
      '<span class="mgdb-rec-block-count">' + R.number(o.score_length || raw.length) + '</span></h3></div>' +
      '<pre class="ms-scores"><code>' + groups.map(R.escape).join(' ') + '</code></pre>' +
      '<p class="mgdb-rec-block-status">One character per line in the mapping panel, in panel order, grouped in tens for counting. ' +
      'The panel itself is linked in the overview above.</p></div>');
    return true;
  }

  function renderMaps(data) {
    var out = R.byId('ms-record-maps-body');
    if (!out) { return false; }
    out.innerHTML = '';
    return R.collection(out, {
      title: 'Maps that include this score',
      items: data.maps || [],
      filename: 'map-score-maps.tsv',
      columns: [
        R.recordColumn('Map', 'map', function (m) { return m.map; }),
        { key: 'included_by', label: 'Included by',
          get: function (m) { return m.included_by ? m.included_by.name : ''; },
          html: function (m) { return m.included_by ? (R.refLink(m.included_by) || R.escape(m.included_by.name)) : '—'; } },
        R.urlColumn(function (m) { return m.map ? m.map.html : ''; })
      ]
    });
  }

  function fillSynonyms(attributes) {
    var el = R.byId('ms-record-synonyms');
    if (!el) { return; }
    var list = attributes.synonyms || [];
    if (!list.length) { return; }
    el.innerHTML = '<span class="mgdb-rec-synonyms-label">Also known as</span> ' +
      list.map(function (s) { return R.escape(s.name); }).join(', ');
    R.show(el, true);
  }

  function render(doc) {
    var data = doc.data.sections || {};
    var counts = (doc.meta && doc.meta.counts) || {};
    payload = doc;

    fillSynonyms(doc.data.attributes || {});

    var rendered = {
      'ms-record-overview': renderOverview(data),
      'ms-record-scores': renderScores(data),
      'ms-record-maps': renderMaps(data)
    };

    var order = [];
    Object.keys(SECTION_LABELS).forEach(function (id) {
      if (id === 'ms-record-resources') { order.push(id); return; }
      var el = R.byId(id);
      if (!el) { return; }
      if (rendered[id]) { R.show(el, true); order.push(id); }
      else { R.show(el, false); }
    });

    R.tabs({
      el: R.byId('ms-record-tabs'),
      order: order,
      labels: SECTION_LABELS,
      counts: counts,
      tabCounts: { 'ms-record-maps': ['maps'] }
    });

    R.show(R.byId('ms-record-loading'), false);
  }

  function load() {
    R.show(R.byId('ms-record-error'), false);
    R.show(R.byId('ms-record-loading'), true);
    fetch(ENDPOINT, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
      .then(render)
      .catch(function () {
        R.show(R.byId('ms-record-loading'), false);
        R.show(R.byId('ms-record-error'), true);
      });
  }

  function init() {
    root = R.byId('ms-record-top');
    if (!root) { return; }
    recordId = root.getAttribute('data-record-id');
    requested = root.getAttribute('data-requested-id') || recordId;
    ENDPOINT = '/api/v1/records/map_scores/' + encodeURIComponent(requested);

    var retry = R.byId('ms-record-retry');
    if (retry) { retry.addEventListener('click', load); }
    R.apiCard('ms-copy-json-btn', 'ms-record-api-link', function () { return payload; });
    load();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
