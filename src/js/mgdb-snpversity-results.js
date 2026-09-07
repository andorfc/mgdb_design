/**
 * file: js/mgdb-snpversity-results.js
 *
 * purpose: /snpversity/send — draw the genotype grid.
 *
 * The page's identity is already server-rendered: the stock columns, the
 * counts, the region, the downloads and the share URL are all in the HTML
 * before this file runs. What this adds is the grid itself, one page at a
 * time, from
 *
 *     snpversity_search_api.php?action=page&query=<id>&page=<n>
 *
 * which merges the engine's genotype JSON with its gene annotation and caches
 * the merge. The legacy viewer did the equivalent by dropping an HTML fragment
 * into a <tbody> — a fragment that prefixes every row with a bare GBrowse URL,
 * so the parser hoisted those text nodes out of the table and stacked them
 * above it.
 *
 * With this file absent the reader still gets everything except the grid, and
 * the <noscript> in the template points at the TSV of the whole result.
 *
 * Two things it does that the legacy viewer could not
 * --------------------------------------------------
 *   - A call's class is computed here from the row's own major allele, so
 *     "matches the major allele" is decided per site rather than per base.
 *     That is what the engine does too; doing it here means the grid needs
 *     nothing from the annotation request to be correct.
 *   - The two filters — sites called in no stock, and sites where every stock
 *     agrees — work on the page's data without another request.
 *
 * Load order: Bauplan emits scripts into <head>, so this runs while the
 * document is still parsing. init() is behind the readyState guard.
 */

(function () {
  'use strict';

  /* The IUPAC codes the engine emits, and the class each takes. Anything not
     here is compared with the row's major allele and colored as a match or
     not. */
  var AMBIGUOUS = { R: 'R', Y: 'Y', S: 'S', W: 'W', K: 'K', M: 'M' };
  var SPECIAL = { N: 'N', '+': 'ins', '-': 'del', '.': 'del', '0': 'zero' };

  var api, query, pageCount = 0, current = 1;
  var meta = null, rows = [];

  function $(id) { return document.getElementById(id); }
  function esc(s) { return (window.MGDB && MGDB.escapeHtml) ? MGDB.escapeHtml(s) : String(s); }
  function num(n) { return Number(n).toLocaleString('en-US'); }

  function apiGet(params) {
    var qs = Object.keys(params).map(function (k) {
      return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
    }).join('&');
    return fetch(api + '?' + qs, { credentials: 'same-origin' }).then(function (response) {
      return response.json().then(function (data) {
        if (!data || data.ok !== true) {
          throw new Error((data && data.message) || ('Request failed with status ' + response.status));
        }
        return data;
      });
    });
  }

  function showError(message) {
    var box = $('snpv-error');
    if (!message) { box.hidden = true; return; }
    box.innerHTML = '<p>' + esc(message) + '</p>';
    box.hidden = false;
    $('snpv-grid-status').textContent = '';
  }

  /* ----------------------------------------------------------------------
     Cell classes
     ---------------------------------------------------------------------- */
  function callClass(call, major) {
    if (call === undefined || call === null || call === '') { return 'N'; }
    var c = String(call).toUpperCase();
    if (SPECIAL[c]) { return SPECIAL[c]; }
    if (AMBIGUOUS[c]) { return AMBIGUOUS[c]; }
    /* The engine writes "NA" for a site whose major allele it could not
       determine; a call there is neither major nor minor. */
    if (!major || major === 'NA') { return 'N'; }
    return (c === String(major).toUpperCase()) ? 'j' : 'n';
  }

  /* ----------------------------------------------------------------------
     The grid
     ---------------------------------------------------------------------- */
  function renderHead() {
    var head = $('snpv-grid-head');
    var cells = ''
      + '<th scope="col" class="snpv-col-site">Site</th>'
      + '<th scope="col">Allele</th>'
      + '<th scope="col">Chr</th>'
      + '<th scope="col" class="snpv-num">Position</th>'
      + '<th scope="col">Gene model</th>'
      + '<th scope="col">Type</th>';

    cells += meta.stocks.map(function (s) {
      var color = projectColor(s['class']);
      var name = esc(s.name);
      if (s.stock_id) { name = '<a href="/data_center/stock/' + encodeURIComponent(s.stock_id) + '">' + name + '</a>'; }
      return '<th scope="col" class="snpv-stock-head" title="' + esc(s.name) + '">'
           + '<span class="snpv-stock-tag" style="border-bottom-color:' + esc(color) + '">'
           + name + '</span></th>';
    }).join('');

    head.innerHTML = '<tr>' + cells + '</tr>';
  }

  /* The engine's project palette, as the class names it puts on its own
     header cells. Kept in step with css/mgdb-snpversity.css and with
     snpvProjects() in the PHP library. */
  var PROJECT_COLORS = {
    NAM: '#7293CB', IBM: '#808585', ApeKI: '#E1974C', Imputation: '#84BA5B',
    Ames2010: '#D35E60', RandD: '#7FB2C6', BREAD: '#9067A7', Inbreds: '#61A961',
    Ames282: '#AB6857', oldDiversity: '#CCC210', HapMapV3: '#5B7C99'
  };

  function projectColor(cls) {
    return PROJECT_COLORS[String(cls || '').trim()] || '#8a8f98';
  }

  function visibleRows() {
    var hideUncalled = $('snpv-hide-uncalled').checked;
    var onlyVariable = $('snpv-only-variable').checked;
    if (!hideUncalled && !onlyVariable) { return rows; }

    return rows.filter(function (row) {
      var seen = {};
      var called = 0;
      for (var i = 0; i < row.calls.length; i++) {
        var c = String(row.calls[i] || '').toUpperCase();
        if (c === '' || c === 'N') { continue; }
        called++;
        seen[c] = true;
      }
      if (hideUncalled && called === 0) { return false; }
      if (onlyVariable && Object.keys(seen).length < 2) { return false; }
      return true;
    });
  }

  function renderBody() {
    var body = $('snpv-grid-body');
    var shown = visibleRows();

    if (!shown.length) {
      body.innerHTML = '<tr><td colspan="' + (6 + meta.stocks.length) + '" class="mgdb-empty">'
                     + (rows.length ? 'No site on this page passes the filters above.'
                                    : 'This page holds no sites.') + '</td></tr>';
    } else {
      body.innerHTML = shown.map(function (row) {
        var cells = ''
          + '<td class="snpv-col-site">' + esc(row.site) + '</td>'
          + '<td>' + esc(row.major) + '</td>'
          + '<td>' + esc(row.chr) + '</td>'
          + '<td class="snpv-num">' + num(row.pos) + '</td>'
          + '<td class="snpv-gene">' + geneLinks(row.genes) + '</td>'
          + '<td class="snpv-type">' + (row.types.length ? esc(row.types.join(', ')) : 'IGR') + '</td>';

        for (var i = 0; i < meta.stocks.length; i++) {
          var call = row.calls[i];
          var text = (call === undefined || call === null || call === '') ? 'N' : call;
          cells += '<td class="snpv-cell snpv-call-' + callClass(call, row.major) + '">' + esc(text) + '</td>';
        }
        return '<tr>' + cells + '</tr>';
      }).join('');
    }

    var label = pageLabel(current);
    var filtered = (shown.length !== rows.length)
      ? (' · ' + num(shown.length) + ' shown after filtering')
      : '';
    $('snpv-grid-status').textContent =
      'Page ' + current + ' of ' + pageCount + (label ? ' · ' + label : '')
      + ' · ' + num(rows.length) + ' site' + (rows.length === 1 ? '' : 's') + filtered
      + ' · ' + num(meta.stocks.length) + ' stock' + (meta.stocks.length === 1 ? '' : 's');
  }

  /* A gene model here is the engine's annotation, on RefGen_v2 or v3. The
     MaizeGDB gene page is keyed on the model name and resolves those older
     identifiers, so the link is worth making; a site with no model shows a
     dash rather than the word "View" the engine's own table puts there, which
     is a link label and not a value. */
  function geneLinks(genes) {
    if (!genes || !genes.length) { return '<span class="mgdb-muted">&mdash;</span>'; }
    return genes.map(function (g) {
      return '<a href="/gene_center/gene/' + encodeURIComponent(g) + '">' + esc(g) + '</a>';
    }).join(', ');
  }

  /* ----------------------------------------------------------------------
     Paging
     ---------------------------------------------------------------------- */
  /* The engine labels a page "158918417 - 159178604" and the last one
     "159214898 - End". Neither is grouped, and "End" is a word inside what
     otherwise reads as a number pair — so appending " bp" to the raw string
     gives "159178605 - End bp". Both halves are formatted here, and the last
     page says so in words. A single-page result has no engine label at all;
     its stand-in is not a range and is left alone. */
  function pageLabel(n) {
    var raw = meta.pages[n - 1] ? meta.pages[n - 1].label : '';
    var parts = raw.split(/\s+-\s+/);
    if (parts.length !== 2 || !/^\d+$/.test(parts[0])) { return raw; }
    var lo = num(parts[0]);
    var hi = /^\d+$/.test(parts[1]) ? num(parts[1]) + ' bp' : 'the end of the region';
    return lo + ' – ' + hi;
  }

  function renderPager() {
    var select = $('snpv-page-select');
    select.innerHTML = meta.pages.map(function (p, i) {
      return '<option value="' + (i + 1) + '">Page ' + (i + 1) + ' — ' + esc(pageLabel(i + 1)) + '</option>';
    }).join('');
    select.value = String(current);
    $('snpv-prev').disabled = (current <= 1);
    $('snpv-next').disabled = (current >= pageCount);
    $('snpv-grid-controls').hidden = false;
  }

  function loadPage(n) {
    current = Math.max(1, Math.min(pageCount || 1, n));
    $('snpv-grid-status').textContent = 'Loading page ' + current + '…';
    renderPager();

    /* The page number lives in the URL so a browser Back returns to the page
       the reader was on, and a link to page 4 opens on page 4. replaceState
       for the first render so the entry the reader arrived on is not
       duplicated. */
    try {
      var params = new URLSearchParams(window.location.search);
      params.set('p', current);
      window.history.replaceState({ page: current }, '',
        window.location.pathname + '?' + params.toString());
    } catch (e) { /* URLSearchParams is not everywhere; the grid works without it */ }

    return apiGet({ action: 'page', query: query, page: current })
      .then(function (data) {
        rows = data.rows || [];
        if (!data.annotated && rows.length) {
          /* The calls came back but the annotation request did not. Saying so
             is better than a column of dashes that reads as "no gene here". */
          showError('The gene model annotation for this page did not load, so the Gene model and '
                  + 'Type columns are empty. The calls themselves are complete.');
        } else {
          showError('');
        }
        renderBody();
      })
      .catch(function (error) { showError(error.message); });
  }

  /* ----------------------------------------------------------------------
     Wiring
     ---------------------------------------------------------------------- */
  function init() {
    var root = document.getElementById('snpv-main');
    if (!root || !document.getElementById('snpv-grid-table')) { return; }

    if (window.MGDB && MGDB.sectionTabs) { MGDB.sectionTabs(); }
    wireCopy();

    api = root.getAttribute('data-api');
    query = root.getAttribute('data-query');

    if (root.getAttribute('data-found') !== 'yes') {
      showError('No results are stored under this query id. SNPversity keeps a result for six weeks; '
              + 'after that the query has to be run again.');
      $('snpv-grid-wrap').hidden = true;
      return;
    }

    var start = 1;
    try {
      var p = parseInt(new URLSearchParams(window.location.search).get('p'), 10);
      if (p > 0) { start = p; }
    } catch (e) { /* default to page 1 */ }

    apiGet({ action: 'meta', query: query })
      .then(function (data) {
        meta = data;
        pageCount = meta.pages.length;
        renderHead();
        return loadPage(start);
      })
      .catch(function (error) {
        showError(error.message);
        $('snpv-grid-wrap').hidden = true;
      });

    $('snpv-page-select').addEventListener('change', function () {
      loadPage(parseInt(this.value, 10));
    });
    $('snpv-prev').addEventListener('click', function () { loadPage(current - 1); });
    $('snpv-next').addEventListener('click', function () { loadPage(current + 1); });
    $('snpv-hide-uncalled').addEventListener('change', renderBody);
    $('snpv-only-variable').addEventListener('change', renderBody);
  }

  function wireCopy() {
    var button = $('snpv-share-copy');
    var field = $('snpv-share-url');
    if (!button || !field) { return; }
    var label = button.textContent;
    button.addEventListener('click', function () {
      field.select();
      var done = function (ok) {
        button.textContent = ok ? 'Copied' : 'Press Ctrl+C';
        window.setTimeout(function () { button.textContent = label; }, 2000);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(field.value).then(function () { done(true); },
                                                        function () { done(false); });
        return;
      }
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      done(ok);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
