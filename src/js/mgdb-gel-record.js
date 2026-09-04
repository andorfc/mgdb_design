/* file: js/mgdb-gel-record.js
 *
 * The gel pattern record page. One request to /api/v1/records/gel/{id} fills
 * every section.
 */
(function () {
  'use strict';

  var R = window.MGDBRecord;
  if (!R) { return; }

  var root = null, recordId = null, requested = null, ENDPOINT = null;
  var payload = null;

  var SECTION_LABELS = {
    'gel-record-overview': 'Overview',
    'gel-record-bands': 'Bands',
    'gel-record-polymorphisms': 'Polymorphisms',
    'gel-record-images': 'Images',
    'gel-record-references': 'References',
    'gel-record-resources': 'Related resources'
  };

  function renderOverview(data) {
    var out = R.byId('gel-record-overview-body');
    if (!out) { return false; }
    out.innerHTML = '';
    var o = data.overview;
    if (!o) { return false; }
    var any = false;

    var pairs = [];
    if (o.probe) { pairs.push(['Probe', R.refLink(o.probe) || R.escape(o.probe.name), o.probe_type]); }
    if (o.enzyme) {
      pairs.push(['Enzyme', R.refLink(o.enzyme) || R.escape(o.enzyme.name), o.enzyme_sequence]);
    }
    if (o.stock) { pairs.push(['Stock', R.refLink(o.stock) || R.escape(o.stock.name)]); }
    if (o.fingerprint) {
      pairs.push(['Fingerprint', '<code class="mgdb-sequence">' + R.escape(o.fingerprint) + '</code>']);
    }
    if (o.units) { pairs.push(['Band size units', R.escape(o.units)]); }
    if (o.person) { pairs.push(['Submitted by', R.refLink(o.person) || R.escape(o.person.name)]); }

    var factsHtml = R.facts(pairs);
    if (factsHtml) { out.insertAdjacentHTML('beforeend', factsHtml); any = true; }

    if (R.notes(out, 'Comments', o.comments ? [{ text: o.comments, meta: [] }] : [])) { any = true; }
    return any;
  }

  /* Band size is always recorded; frequency (19,894 of 30,570 rows) and size
     error (6,707) are not, so those columns appear only when this pattern has
     them rather than printing a column of dashes. */
  function renderBands(data) {
    var out = R.byId('gel-record-bands-body');
    if (!out) { return false; }
    out.innerHTML = '';
    var rows = data.bands || [];
    if (!rows.length) { return false; }

    function has(field) {
      return rows.some(function (b) { return b[field] !== null && b[field] !== undefined && b[field] !== ''; });
    }

    var columns = [
      { key: 'band_id', label: 'Band', tile: true,
        get: function (b) { return b.band_id || ''; },
        html: function (b) { return b.band_id ? R.escape(b.band_id) : '—'; } },
      { key: 'size', label: 'Size', numeric: true, sort: 'number',
        get: function (b) { return b.size === null ? '' : String(b.size); } }
    ];
    if (has('morph_id')) {
      columns.push({ key: 'morph_id', label: 'Morph', get: function (b) { return b.morph_id || ''; } });
    }
    if (has('frequency')) {
      columns.push({ key: 'frequency', label: 'Frequency', numeric: true, sort: 'number',
        get: function (b) { return b.frequency === null ? '' : String(b.frequency); } });
    }
    if (has('size_error')) {
      columns.push({ key: 'size_error', label: 'Size error', numeric: true, sort: 'number',
        get: function (b) { return b.size_error === null ? '' : String(b.size_error); } });
    }

    return R.collection(out, {
      title: 'Bands scored',
      items: rows,
      filename: 'gel-bands.tsv',
      columns: columns
    });
  }

  function renderPolymorphisms(data) {
    var out = R.byId('gel-record-polymorphisms-body');
    if (!out) { return false; }
    out.innerHTML = '';
    return R.collection(out, {
      title: 'DNA polymorphisms called from these bands',
      items: data.polymorphisms || [],
      filename: 'gel-polymorphisms.tsv',
      columns: [
        R.recordColumn('Polymorphism', 'variation', function (p) { return p.variation; }),
        { key: 'type', label: 'Type', get: function (p) { return p.type || ''; } },
        { key: 'morph_id', label: 'Morph', get: function (p) { return p.morph_id || ''; } },
        { key: 'stock', label: 'Stock',
          get: function (p) { return p.stock ? p.stock.name : ''; },
          html: function (p) { return p.stock ? (R.refLink(p.stock) || R.escape(p.stock.name)) : '—'; } },
        R.urlColumn(function (p) { return p.variation ? p.variation.html : ''; })
      ]
    });
  }

  function renderImages(data) {
    var out = R.byId('gel-record-images-body');
    if (!out) { return false; }
    out.innerHTML = '';
    var items = data.images || [];
    if (!items.length) { return false; }
    R.images(out, items.map(function (i) {
      return { url: i.url, thumbnail: i.thumbnail, caption: i.caption, title: i.caption || '' };
    }), 'gel-image-dialog', { title: 'Gel images' });
    return true;
  }

  function renderReferences(data) {
    return R.references(R.byId('gel-record-references-body'), data.references || [],
                        'gel-record-references', 'gel-ref');
  }

  function fillSynonyms(attributes) {
    var el = R.byId('gel-record-synonyms');
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
      'gel-record-overview': renderOverview(data),
      'gel-record-bands': renderBands(data),
      'gel-record-polymorphisms': renderPolymorphisms(data),
      'gel-record-images': renderImages(data),
      'gel-record-references': renderReferences(data)
    };

    var order = [];
    Object.keys(SECTION_LABELS).forEach(function (id) {
      if (id === 'gel-record-resources') { order.push(id); return; }
      var el = R.byId(id);
      if (!el) { return; }
      if (rendered[id]) { R.show(el, true); order.push(id); }
      else { R.show(el, false); }
    });

    R.tabs({
      el: R.byId('gel-record-tabs'),
      order: order,
      labels: SECTION_LABELS,
      counts: counts,
      tabCounts: {
        'gel-record-bands': ['bands'],
        'gel-record-polymorphisms': ['polymorphisms'],
        'gel-record-images': ['images'],
        'gel-record-references': ['references']
      }
    });

    R.show(R.byId('gel-record-loading'), false);
  }

  function load() {
    R.show(R.byId('gel-record-error'), false);
    R.show(R.byId('gel-record-loading'), true);
    fetch(ENDPOINT, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
      .then(render)
      .catch(function () {
        R.show(R.byId('gel-record-loading'), false);
        R.show(R.byId('gel-record-error'), true);
      });
  }

  function init() {
    root = R.byId('gel-record-top');
    if (!root) { return; }
    recordId = root.getAttribute('data-record-id');
    requested = root.getAttribute('data-requested-id') || recordId;
    ENDPOINT = '/api/v1/records/gel/' + encodeURIComponent(requested);

    var retry = R.byId('gel-record-retry');
    if (retry) { retry.addEventListener('click', load); }
    R.apiCard('gel-copy-json-btn', 'gel-record-api-link', function () { return payload; });
    load();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
