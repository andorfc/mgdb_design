/* file: js/mgdb-compare-maps.js
 *
 * /compare_maps. Two jobs: drive the map picker, and page through the shared
 * loci from search/compare_maps/compare_maps_api.php.
 *
 * The legacy page had no script of its own beyond a javascript: link that
 * opened the colour key in a 480x550 pop-up window.
 */
(function () {
  'use strict';

  var API = '/search/compare_maps/compare_maps_api.php';
  var PAGE_SIZE = 100;

  var root = null;
  var state = { maps: [], selected: [] };
  var offset = 0;
  var total = 0;
  var lastQuery = '';
  var debounce = null;

  function byId(id) { return document.getElementById(id); }

  function esc(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function num(value) {
    return Number(value || 0).toLocaleString('en-US');
  }

  function show(el, on) { if (el) { el.hidden = !on; } }

  /* ---- the picker -------------------------------------------------------- */

  function fillSelect(select, maps, selectedId, placeholder) {
    if (!select) { return; }
    var html = '<option value="">' + esc(placeholder) + '</option>';
    maps.forEach(function (m) {
      html += '<option value="' + m.id + '"'
            + (String(m.id) === String(selectedId) ? ' selected' : '') + '>'
            + esc(m.name) + ' — ' + num(m.markers) + ' markers</option>';
    });
    select.innerHTML = html;
    select.disabled = maps.length === 0;
  }

  function loadMapsForChromosome(lgId, preselect) {
    var status = byId('cm-picker-status');
    if (!lgId) {
      [byId('cm-map1'), byId('cm-map2'), byId('cm-map3')].forEach(function (s) {
        if (s) { s.innerHTML = '<option value="">Choose a chromosome first</option>'; s.disabled = true; }
      });
      return;
    }
    if (status) { status.textContent = 'Loading maps…'; }

    fetch(API + '?maps=' + encodeURIComponent(lgId), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (doc) {
        var maps = (doc && doc.maps) || [];
        preselect = preselect || [];
        fillSelect(byId('cm-map1'), maps, preselect[0], 'Choose a map…');
        fillSelect(byId('cm-map2'), maps, preselect[1], 'Choose a map…');
        fillSelect(byId('cm-map3'), maps, preselect[2], 'None');
        if (status) {
          status.textContent = maps.length
            ? num(maps.length) + ' maps on this chromosome carry placed loci.'
            : 'No maps on this chromosome carry placed loci.';
        }
      })
      .catch(function () {
        if (status) { status.textContent = 'The map list could not be loaded.'; }
      });
  }

  function submitPicker(event) {
    if (event) { event.preventDefault(); }
    var m1 = byId('cm-map1'), m2 = byId('cm-map2'), m3 = byId('cm-map3');
    var status = byId('cm-picker-status');
    if (!m1 || !m2 || !m1.value || !m2.value) {
      if (status) { status.textContent = 'Choose two maps to compare.'; }
      return;
    }
    if (m1.value === m2.value) {
      if (status) { status.textContent = 'Choose two different maps.'; }
      return;
    }
    var url = '/compare_maps?map1=' + encodeURIComponent(m1.value)
            + '&map2=' + encodeURIComponent(m2.value);
    if (m3 && m3.value && m3.value !== m1.value && m3.value !== m2.value) {
      url += '&map3=' + encodeURIComponent(m3.value);
    }
    window.location.href = url;
  }

  function initPicker() {
    var chr = byId('cm-chr');
    var form = byId('cm-picker');
    var third = byId('cm-add-third');

    if (chr) {
      chr.addEventListener('change', function () { loadMapsForChromosome(chr.value, []); });
      if (chr.value) { loadMapsForChromosome(chr.value, state.selected); }
    }
    if (form) { form.addEventListener('submit', submitPicker); }
    if (third) {
      third.addEventListener('click', function () {
        var field = byId('cm-third-field');
        show(field, true);
        if (form) { form.classList.add('has-third'); }
        third.hidden = true;
        var m3 = byId('cm-map3');
        if (m3) { m3.focus(); }
      });
    }
    /* A third map already in the URL means the field is in use, so it opens
       with the page rather than waiting to be asked for. */
    if (state.selected.length > 2) {
      show(byId('cm-third-field'), true);
      if (form) { form.classList.add('has-third'); }
      if (third) { third.hidden = true; }
    }
  }

  /* ---- the comparison ---------------------------------------------------- */

  function summaryRows(maps) {
    var body = byId('cm-summary-body');
    if (!body) { return; }
    var head = '<tr><th scope="row">Map</th>';
    var chrRow = '<tr><th scope="row">Chromosome</th>';
    var srcRow = '<tr><th scope="row">Source</th>';
    var mkRow = '<tr><th scope="row">Markers placed</th>';
    maps.forEach(function (m) {
      head += '<td><a href="/data_center/map?id=' + m.id + '"><strong>' + esc(m.name) + '</strong></a></td>';
      chrRow += '<td>' + (m.chromosome ? esc(m.chromosome) : '<span class="mgdb-muted">&mdash;</span>') + '</td>';
      srcRow += '<td>' + (m.source
        ? '<a href="/person?id=' + m.source_id + '">' + esc(m.source) + '</a>'
        : '<span class="mgdb-muted">Not recorded</span>') + '</td>';
      mkRow += '<td>' + num(m.markers) + '</td>';
    });
    body.innerHTML = head + '</tr>' + chrRow + '</tr>' + srcRow + '</tr>' + mkRow + '</tr>';
  }

  function tableHead(maps) {
    var head = byId('cm-thead');
    if (!head) { return; }
    var html = '<tr><th scope="col">Locus</th><th scope="col">Type</th>';
    maps.forEach(function (m) {
      html += '<th scope="col" class="cm-coord-head"><a href="/data_center/map?id=' + m.id + '">'
            + esc(m.name) + '</a></th>';
    });
    head.innerHTML = html + '</tr>';
  }

  function tableRows(rows, maps) {
    var body = byId('cm-tbody');
    if (!body) { return; }
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="' + (2 + maps.length) + '">'
        + 'No locus is placed on all of these maps'
        + (lastQuery ? ' and matches that name' : '') + '.</td></tr>';
      return;
    }
    body.innerHTML = rows.map(function (r) {
      var cells = '<td><a href="/data_center/locus?id=' + r.id + '">' + esc(r.name) + '</a>'
        + (r.full_name ? ' <span class="mgdb-muted">' + esc(r.full_name) + '</span>' : '')
        + '</td>'
        + '<td><span class="cm-type"><span class="cm-chip cm-kind-' + esc(r.kind)
        + '" aria-hidden="true"></span>' + esc(r.kind_label) + '</span></td>';
      r.values.forEach(function (v, i) {
        var map = maps[i];
        cells += '<td class="cm-coord">'
          + (v === null ? '<span class="mgdb-muted">&mdash;</span>'
             : '<a href="/data_center/map?id=' + (map ? map.id : '') + '&reflocus=' + r.id
               + '#map_data">' + esc(v) + '</a>')
          + '</td>';
      });
      return '<tr>' + cells + '</tr>';
    }).join('');
  }

  function exportHref() {
    var link = byId('cm-export');
    if (!link) { return; }
    link.setAttribute('href', API + '?' + queryString({ format: 'tsv' }));
  }

  function queryString(extra) {
    var parts = [];
    state.selected.forEach(function (id, i) { parts.push('map' + (i + 1) + '=' + id); });
    var q = (byId('cm-filter') || {}).value || '';
    var kind = (byId('cm-kind') || {}).value || '';
    if (q) { parts.push('q=' + encodeURIComponent(q)); }
    if (kind) { parts.push('kind=' + encodeURIComponent(kind)); }
    Object.keys(extra || {}).forEach(function (k) {
      parts.push(k + '=' + encodeURIComponent(extra[k]));
    });
    return parts.join('&');
  }

  function fillKinds(kinds) {
    var select = byId('cm-kind');
    if (!select || select.options.length > 1) { return; }
    kinds.forEach(function (pair) {
      var option = document.createElement('option');
      option.value = pair[0];
      option.textContent = pair[1];
      select.appendChild(option);
    });
  }

  function render(doc) {
    total = doc.total || 0;
    var maps = doc.maps || [];
    summaryRows(maps);
    tableHead(maps);
    tableRows(doc.rows || [], maps);
    fillKinds(doc.kinds || []);
    exportHref();

    var count = byId('cm-count');
    if (count) {
      var shown = (doc.rows || []).length;
      count.textContent = total
        ? num(total) + ' loci are placed on all of these maps'
          + (total > shown ? ' — showing ' + num(offset + 1) + '–' + num(offset + shown) : '')
          + '.'
        : 'No locus is placed on all of these maps.';
    }

    show(byId('cm-table-wrap'), true);
    show(byId('cm-loading'), false);

    var pager = byId('cm-pager');
    var pages = Math.ceil(total / PAGE_SIZE);
    if (pager) {
      show(pager, pages > 1);
      var label = byId('cm-page-label');
      if (label) {
        label.textContent = 'Page ' + num(Math.floor(offset / PAGE_SIZE) + 1) + ' of ' + num(pages);
      }
      var prev = byId('cm-prev'), next = byId('cm-next');
      if (prev) { prev.disabled = offset <= 0; }
      if (next) { next.disabled = offset + PAGE_SIZE >= total; }
    }
  }

  function load() {
    show(byId('cm-error'), false);
    show(byId('cm-loading'), true);
    fetch(API + '?' + queryString({ limit: PAGE_SIZE, offset: offset }),
          { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
      .then(function (doc) {
        if (!doc.ok) { throw new Error(doc.error || 'not ok'); }
        render(doc);
      })
      .catch(function () {
        show(byId('cm-loading'), false);
        show(byId('cm-error'), true);
      });
  }

  function initComparison() {
    if (state.selected.length < 2) { return; }

    var filter = byId('cm-filter');
    if (filter) {
      filter.addEventListener('input', function () {
        window.clearTimeout(debounce);
        debounce = window.setTimeout(function () {
          /* Only reload when the term actually changed. An input event fires
             for a focus-and-blur with no edit, and reloading then resets the
             reader's page for nothing. */
          if (filter.value === lastQuery) { return; }
          lastQuery = filter.value;
          offset = 0;
          load();
        }, 250);
      });
    }
    var kind = byId('cm-kind');
    if (kind) {
      kind.addEventListener('change', function () { offset = 0; load(); });
    }
    var prev = byId('cm-prev'), next = byId('cm-next'), retry = byId('cm-retry');
    if (prev) { prev.addEventListener('click', function () {
      offset = Math.max(0, offset - PAGE_SIZE); load();
    }); }
    if (next) { next.addEventListener('click', function () {
      if (offset + PAGE_SIZE < total) { offset += PAGE_SIZE; load(); }
    }); }
    if (retry) { retry.addEventListener('click', load); }

    load();
  }

  function init() {
    root = byId('cm-main');
    if (!root) { return; }
    try {
      state = JSON.parse(root.getAttribute('data-state') || '{}') || {};
    } catch (e) { state = {}; }
    if (!state.maps) { state.maps = []; }
    if (!state.selected) { state.selected = []; }

    initPicker();
    initComparison();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
