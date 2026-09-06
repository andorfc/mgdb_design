/* mgdb-traits-ibm-nam.js -- /traits_ibm_nam
 *
 * The search calls a JSON endpoint and builds the results table here, rather
 * than dropping a returned HTML document into an innerHTML the way the page
 * this replaces did. Sorting comes from mgdb-modern.js via `data-sortable`.
 *
 * Bauplan::includeScript emits into <head>, so nothing below may touch the
 * document until it has been parsed.
 */
(function () {
  'use strict';

  var API = '/search/traits_ibm_nam/traits_ibm_nam_search_api.php';
  var PAGE_SIZE = 100;

  function byId(id) { return document.getElementById(id); }

  function esc(value) {
    if (value === null || value === undefined || value === '') { return ''; }
    return String(value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  /* A trait value is a decimal from the database and arrives as "0.0730000000".
     Trailing zeros are precision the source did not claim, so they come off --
     but the raw string is kept on the cell as data-value, which is what the
     shared column sort reads, so sorting stays numeric. */
  function formatValue(raw) {
    if (raw === null || raw === undefined || raw === '') { return ''; }
    var n = parseFloat(raw);
    if (!isFinite(n)) { return String(raw); }
    return String(parseFloat(n.toFixed(6)));
  }

  function currentFilters() {
    return {
      stock: (byId('traits-stock').value || '').trim(),
      trait: byId('traits-trait').value || '',
      reference: byId('traits-reference').value || '',
      environment: byId('traits-environment').value || ''
    };
  }

  function hasAnyFilter(f) {
    return !!(f.stock || f.trait || f.reference || f.environment);
  }

  function queryString(filters, extra) {
    var params = new URLSearchParams();
    Object.keys(filters).forEach(function (key) {
      if (filters[key]) { params.set(key, filters[key]); }
    });
    Object.keys(extra || {}).forEach(function (key) { params.set(key, extra[key]); });
    return params.toString();
  }

  /* The Plant Ontology column is not rendered. Its filter could never match --
     no PO key joins this table -- so the column was blank on every row of the
     page this replaces. If a value ever appears, it is shown as a row note
     rather than an always-empty column. */
  function rowHtml(row) {
    var cells = [
      '<th scope="row" class="traits-trait">' + esc(row.trait) + '</th>',
      '<td>' + esc(row.stock) + '</td>',
      '<td class="mgdb-numeric" data-value="' + esc(row.value) + '">' + esc(formatValue(row.value)) + '</td>',
      '<td>' + esc(row.units) + '</td>',
      '<td>' + esc(row.statistic) + (row.condition ? ' <span class="mgdb-pill">' + esc(row.condition) + '</span>' : '') + '</td>',
      '<td>' + (row.environment ? esc(row.environment) : '<span class="traits-empty-value">Not reported</span>') + '</td>',
      '<td class="traits-reference">' + esc(row.reference)
        + (row.po_term ? ' <span class="mgdb-pill">' + esc(row.po_term) + '</span>' : '') + '</td>'
    ];
    return '<tr>' + cells.join('') + '</tr>';
  }

  function setStatus(message) {
    var status = byId('traits-status');
    if (status) { status.textContent = message; }
  }

  function runSearch() {
    var filters = currentFilters();
    var results = byId('traits-results');
    var none = byId('traits-none');
    var tbody = byId('traits-tbody');
    var count = byId('traits-count');
    var more = byId('traits-more');

    if (!hasAnyFilter(filters)) {
      setStatus('Choose at least one criterion before searching.');
      byId('traits-stock').focus();
      return;
    }

    results.hidden = false;
    none.hidden = true;
    count.textContent = 'Searching…';
    tbody.innerHTML = '';
    more.hidden = true;
    setStatus('Searching trait values.');

    window.fetch(API + '?' + queryString(filters, { limit: PAGE_SIZE }), {
      headers: { 'Accept': 'application/json' }
    }).then(function (response) {
      return response.json();
    }).then(function (data) {
      if (!data || !data.ok) {
        results.hidden = true;
        none.hidden = false;
        setStatus((data && data.error) || 'The search could not be completed.');
        return;
      }

      var rows = data.results || [];
      if (!rows.length) {
        results.hidden = true;
        none.hidden = false;
        setStatus('No trait values match those criteria.');
        return;
      }

      tbody.innerHTML = rows.map(rowHtml).join('');
      count.textContent = data.has_more
        ? 'Showing the first ' + rows.length.toLocaleString() + ' matching values'
        : rows.length.toLocaleString() + (rows.length === 1 ? ' matching value' : ' matching values');

      /* The endpoint fetches one row past the limit rather than counting the
         whole matched set twice, so the page can say there are more without
         claiming a total it did not measure. */
      more.hidden = !data.has_more;
      if (data.has_more) {
        more.textContent = 'More values match than are shown. Download the TSV for the whole set, '
                         + 'or narrow the search with another criterion.';
      }

      var notes = data.notes || [];
      if (notes.length) { more.hidden = false; more.textContent = notes.join(' ') + ' ' + (more.textContent || ''); }

      byId('traits-export').setAttribute('href', API + '?' + queryString(filters, { format: 'tsv' }));
      setStatus(count.textContent);
    }).catch(function () {
      results.hidden = true;
      none.hidden = false;
      setStatus('The search could not be completed.');
    });
  }

  function initForm() {
    var form = byId('traits-form');
    if (!form) { return; }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      runSearch();
    });

    form.addEventListener('reset', function () {
      window.setTimeout(function () {
        byId('traits-results').hidden = true;
        byId('traits-none').hidden = true;
        byId('traits-tbody').innerHTML = '';
        setStatus('');
        updateSubmitState();
      }, 0);
    });

    Array.prototype.forEach.call(form.querySelectorAll('[data-traits-example]'), function (chip) {
      chip.addEventListener('click', function () {
        var preset;
        try { preset = JSON.parse(chip.getAttribute('data-traits-example')); }
        catch (error) { return; }
        /* Clearing the fields directly rather than calling form.reset(): the
           reset handler above defers its work to a setTimeout, so a reset here
           would fire *after* the search below and hide the results it just
           produced. */
        clearFields();
        if (preset.stock) { byId('traits-stock').value = preset.stock; }
        if (preset.trait) { byId('traits-trait').value = preset.trait; }
        if (preset.reference) { byId('traits-reference').value = preset.reference; }
        if (preset.environment) { byId('traits-environment').value = preset.environment; }
        updateSubmitState();
        runSearch();
      });
    });

    /* The submit is disabled while nothing is chosen, and the hint beside it
       says why -- an unfiltered search is 425,616 rows, and the endpoint
       refuses it too. */
    ['traits-stock', 'traits-trait', 'traits-reference', 'traits-environment'].forEach(function (id) {
      var el = byId(id);
      if (!el) { return; }
      el.addEventListener('input', updateSubmitState);
      el.addEventListener('change', updateSubmitState);
    });
    updateSubmitState();
  }

  function clearFields() {
    byId('traits-stock').value = '';
    byId('traits-trait').value = '';
    byId('traits-reference').value = '';
    byId('traits-environment').value = '';
  }

  function updateSubmitState() {
    var any = hasAnyFilter(currentFilters());
    var submit = byId('traits-submit');
    var hint = byId('traits-empty-hint');
    if (submit) { submit.disabled = !any; }
    if (hint) { hint.hidden = any; }
  }

  /* ── Section tabs ──────────────────────────────────────────────────────── */

  function buildTabs() {
    var tabs = document.querySelectorAll('.mgdb-section-tabs a');
    if (!tabs.length) { return function () {}; }

    var pairs = [];
    Array.prototype.forEach.call(tabs, function (tab) {
      var href = tab.getAttribute('href') || '';
      if (href.charAt(0) !== '#') { return; }
      var section = document.getElementById(href.slice(1));
      if (section) { pairs.push({ tab: tab, section: section }); }
    });
    if (!pairs.length) { return function () {}; }

    var heldUntilScroll = null;
    var heldAtY = 0;

    function mark(section) {
      pairs.forEach(function (pair) {
        var current = pair.section === section;
        pair.tab.classList.toggle('is-current', current);
        if (current) { pair.tab.setAttribute('aria-current', 'true'); }
        else { pair.tab.removeAttribute('aria-current'); }
      });
    }

    function triggerLine() {
      var bar = document.querySelector('.mgdb-section-tabs');
      var barHeight = bar ? bar.getBoundingClientRect().height : 0;
      var margin = parseFloat(window.getComputedStyle(pairs[0].section).scrollMarginTop) || 0;
      return Math.max(barHeight + 8, margin + 4);
    }

    function update() {
      if (heldUntilScroll) {
        if (Math.abs(window.scrollY - heldAtY) < 4) { return; }
        heldUntilScroll = null;
      }
      var line = triggerLine();
      var current = pairs[0];
      pairs.forEach(function (pair) {
        if (pair.section.hasAttribute('hidden')) { return; }
        if (pair.section.getBoundingClientRect().top <= line) { current = pair; }
      });
      if ((window.innerHeight + window.scrollY) >= (document.documentElement.scrollHeight - 2)) {
        current = pairs[pairs.length - 1];
      }
      if (current) { mark(current.section); }
    }

    pairs.forEach(function (pair) {
      pair.tab.addEventListener('click', function () {
        mark(pair.section);
        heldUntilScroll = pair.section;
        heldAtY = window.scrollY;
      });
    });

    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);

    if (window.IntersectionObserver) {
      var observer = new window.IntersectionObserver(function () { update(); },
        { rootMargin: '-20% 0px -60% 0px' });
      pairs.forEach(function (pair) { observer.observe(pair.section); });
    }

    if (document.readyState !== 'complete') {
      window.addEventListener('load', function () { window.setTimeout(update, 0); });
    }

    update();
    return update;
  }

  /* A link can arrive prefiltered -- /traits_ibm_nam?trait=<name> is what the
     trait record page's Measured values section points at, so a reader lands on
     that trait's per-line values rather than on an empty form they have to fill
     in again. The same works for stock, reference and environment. */
  function applyUrlFilters() {
    var params = new URLSearchParams(window.location.search);
    var map = { stock: 'traits-stock', trait: 'traits-trait',
                reference: 'traits-reference', environment: 'traits-environment' };
    var any = false;

    Object.keys(map).forEach(function (key) {
      var value = params.get(key);
      if (!value) { return; }
      var el = byId(map[key]);
      if (!el) { return; }
      if (el.tagName === 'SELECT') {
        /* A select can only take a value it actually offers; a trait name that
           is not in the list would otherwise leave the control blank and run an
           unfiltered-looking search. */
        var match = Array.prototype.some.call(el.options, function (option) {
          return option.value === value;
        });
        if (!match) { return; }
      }
      el.value = value;
      any = true;
    });

    if (any) {
      updateSubmitState();
      runSearch();
      byId('traits-search').scrollIntoView({ block: 'start', behavior: 'auto' });
    }
    return any;
  }

  function init() {
    initForm();
    applyUrlFilters();
    var sectionsMoved = buildTabs();
    /* Results appear below the search and move every section under it. */
    var results = byId('traits-results');
    if (results && window.MutationObserver) {
      new window.MutationObserver(function () { sectionsMoved(); })
        .observe(results, { attributes: true, attributeFilter: ['hidden'] });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
