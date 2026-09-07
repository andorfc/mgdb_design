/**
 * file: js/mgdb-snpversity.js
 *
 * purpose: /snpversity — the query console.
 *
 * What it is responsible for
 * --------------------------
 *   - the stock picker, filtering 15,532 names in the browser from one static
 *     file rather than POSTing to the engine on every change
 *   - the gene model type-ahead, which does go to the engine, debounced and
 *     server-cached, because those coordinates have to be the engine's own
 *   - the region controls: which chromosomes exist on this assembly, where its
 *     first genotyped site is, and what the range adds up to
 *   - running the query, and handing the reader to its result
 *
 * Progressive enhancement has a limit here and it is worth being honest about
 * it. The form's target is an engine on another host that answers with its own
 * HTML page, so a plain <form action> submission cannot both reach it and come
 * back to a MaizeGDB page. With this file absent the console does not run a
 * query. Everything else on the page — the datasets, the legends, the stock
 * files, the references — is server-rendered and complete.
 *
 * Load order: Bauplan emits scripts into <head>, so this runs while the
 * document is still parsing. Every entry point is behind the readyState guard
 * at the bottom.
 */

(function () {
  'use strict';

  var MAX_SUGGESTIONS = 60;
  var WIDE_REGION = 10000000;   /* the engine's own "this will be slow" line */

  var root, api, bounds, datasets;
  var catalog = { gbs: null, hmp: null, loading: null };
  var selected = [];            /* {value, name, label, project, projectIndex, stockId, bulk} */
  var selectedIndex = {};
  var selectedKey = '';         /* which catalog the current selection came from */
  var activeProjects = {};
  var comboIndex = -1;
  var comboItems = [];
  var geneItems = [];
  var geneIndex = -1;

  function $(id) { return document.getElementById(id); }
  function esc(s) { return (window.MGDB && MGDB.escapeHtml) ? MGDB.escapeHtml(s) : String(s); }
  function num(n) { return Number(n).toLocaleString('en-US'); }

  /* ----------------------------------------------------------------------
     Talking to the API

     MGDB.request is GET-only and throws away the response body on a non-2xx,
     which is exactly the body carrying the reason. This keeps it.
     ---------------------------------------------------------------------- */
  function apiGet(params) {
    var qs = Object.keys(params).map(function (k) {
      return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
    }).join('&');
    return fetch(api + '?' + qs, { credentials: 'same-origin' }).then(readJson);
  }

  function apiPost(formData) {
    return fetch(api, { method: 'POST', body: formData, credentials: 'same-origin' }).then(readJson);
  }

  function readJson(response) {
    return response.json().then(function (data) {
      if (!data || data.ok !== true) {
        var err = new Error((data && data.message) || ('Request failed with status ' + response.status));
        err.code = data && data.code;
        throw err;
      }
      return data;
    }, function () {
      throw new Error('The server answered with something that was not JSON.');
    });
  }

  /* ----------------------------------------------------------------------
     The stock catalog

     Fetched once, on the first interaction that needs it. 125 KB gzipped for
     the GBS file; nothing is fetched at all for a reader who only came to
     read the datasets section.
     ---------------------------------------------------------------------- */
  function assembly() {
    var chosen = document.querySelector('input[name="dataSet"]:checked');
    if (!chosen) { return 'v2'; }
    return chosen.getAttribute('data-assembly') || 'v2';
  }

  function catalogKey() { return assembly() === 'v3' ? 'hmp' : 'gbs'; }

  function loadCatalog() {
    var key = catalogKey();
    if (catalog[key]) { return Promise.resolve(catalog[key]); }

    var url = root.getAttribute(key === 'hmp' ? 'data-stocks-hmp' : 'data-stocks-gbs');
    var status = $('snpv-stock-status');
    status.textContent = 'Loading the stock catalog…';

    return fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        /* [projectIndex, name, engineTaxonId, labelOrNull, mgdbStockId?] */
        data.rows = data.stocks.map(function (s) {
          var name = s[1];
          return {
            value: s[2] ? (name + ':' + s[2]) : name,
            name: name,
            label: s[3] || name,
            search: (s[3] ? (s[3] + ' ' + name) : name).toLowerCase(),
            project: data.projects[s[0]] ? data.projects[s[0]].label : '',
            projectIndex: s[0],
            color: data.projects[s[0]] ? data.projects[s[0]].color : '#8a8f98',
            stockId: s[4] || null
          };
        });
        catalog[key] = data;
        renderProjectChips();
        setStockStatus();
        return data;
      })
      .catch(function () {
        status.textContent = 'The stock catalog could not be loaded. Reload the page to try again.';
        throw new Error('catalog');
      });
  }

  function setStockStatus(extra) {
    var data = catalog[catalogKey()];
    var status = $('snpv-stock-status');
    if (!data) { status.textContent = 'Type part of a name to search the stock catalog.'; return; }
    status.textContent = extra || (num(data.rows.length) + ' stocks in this dataset. Type part of a name, or press Browse.');
  }

  /* ----------------------------------------------------------------------
     Project chips
     ---------------------------------------------------------------------- */
  function renderProjectChips() {
    var wrap = $('snpv-project-chips');
    var row = $('snpv-project-row');
    var data = catalog[catalogKey()];
    if (!data) { wrap.innerHTML = ''; return; }

    /* HapMap v3 is one roster with no projects in it, so a row of one chip
       filtering to everything would be a control that does nothing. */
    if (data.projects.length < 2) { row.hidden = true; return; }
    row.hidden = false;

    wrap.innerHTML = data.projects.map(function (p, i) {
      return '<button type="button" class="snpv-project-chip" data-project="' + i + '"'
           + ' aria-pressed="' + (activeProjects[i] ? 'true' : 'false') + '">'
           + '<span class="snpv-swatch" style="background:' + esc(p.color) + '"></span>'
           + esc(p.label) + ' <span class="snpv-chip-count">' + num(p.count) + '</span></button>';
    }).join('');
  }

  function toggleProject(index) {
    if (activeProjects[index]) { delete activeProjects[index]; } else { activeProjects[index] = true; }
    renderProjectChips();
    openCombo($('snpv-stock-input').value);
  }

  function activeProjectList() {
    return Object.keys(activeProjects).map(Number);
  }

  /* ----------------------------------------------------------------------
     The stock combobox
     ---------------------------------------------------------------------- */
  function matches(term) {
    var data = catalog[catalogKey()];
    if (!data) { return []; }
    var projects = activeProjectList();
    var needle = term.trim().toLowerCase();
    var out = [];

    /* With a project filter and no search term, offer the project itself
       first. That is a real value to the engine — a bare project name means
       "every stock in it" — and it is the only way to ask for 5,039 stocks
       without adding 5,039 chips. */
    if (!needle && projects.length) {
      projects.forEach(function (i) {
        var p = data.projects[i];
        if (!p || !p.all) { return; }
        out.push({ bulk: true, value: p.all, name: p.label, label: 'Every stock in ' + p.label,
                   project: p.label, projectIndex: i, color: p.color, count: p.count });
      });
    }

    for (var i = 0; i < data.rows.length && out.length < MAX_SUGGESTIONS; i++) {
      var row = data.rows[i];
      if (projects.length && projects.indexOf(row.projectIndex) === -1) { continue; }
      if (needle && row.search.indexOf(needle) === -1) { continue; }
      if (selectedIndex[row.value]) { continue; }
      out.push(row);
    }
    return out;
  }

  function highlight(text, needle) {
    if (!needle) { return esc(text); }
    var at = text.toLowerCase().indexOf(needle.toLowerCase());
    if (at === -1) { return esc(text); }
    return esc(text.slice(0, at)) + '<mark>' + esc(text.slice(at, at + needle.length)) + '</mark>'
         + esc(text.slice(at + needle.length));
  }

  function openCombo(term) {
    var list = $('snpv-stock-listbox');
    var input = $('snpv-stock-input');
    comboItems = matches(term || '');
    comboIndex = -1;

    if (!comboItems.length) {
      list.innerHTML = '<li class="is-disabled">No stock matches that.</li>';
    } else {
      var needle = (term || '').trim();
      list.innerHTML = comboItems.map(function (item, i) {
        var right = item.bulk ? num(item.count) + ' stocks' : item.project;
        return '<li role="option" id="snpv-opt-' + i + '" data-index="' + i + '" aria-selected="false">'
             + '<span class="snpv-swatch" style="background:' + esc(item.color) + '"></span>'
             + '<span class="snpv-option-name">' + highlight(item.bulk ? item.label : item.name, needle) + '</span>'
             + (!item.bulk && item.label !== item.name
                  ? '<span class="snpv-option-alias">' + highlight(item.label, needle) + '</span>' : '')
             + '<span class="snpv-option-project">' + esc(right) + '</span></li>';
      }).join('');
    }
    list.hidden = false;
    input.setAttribute('aria-expanded', 'true');
    $('snpv-stock-browse').setAttribute('aria-expanded', 'true');
  }

  function closeCombo() {
    var list = $('snpv-stock-listbox');
    list.hidden = true;
    comboIndex = -1;
    $('snpv-stock-input').setAttribute('aria-expanded', 'false');
    $('snpv-stock-browse').setAttribute('aria-expanded', 'false');
  }

  function moveCombo(delta) {
    var list = $('snpv-stock-listbox');
    if (list.hidden || !comboItems.length) { return; }
    var nodes = list.querySelectorAll('li[data-index]');
    if (!nodes.length) { return; }
    comboIndex = (comboIndex + delta + nodes.length) % nodes.length;
    for (var i = 0; i < nodes.length; i++) {
      nodes[i].setAttribute('aria-selected', i === comboIndex ? 'true' : 'false');
    }
    nodes[comboIndex].scrollIntoView({ block: 'nearest' });
    $('snpv-stock-input').setAttribute('aria-activedescendant', 'snpv-opt-' + comboIndex);
  }

  function addStock(item) {
    if (!item || selectedIndex[item.value]) { return; }
    selectedIndex[item.value] = true;
    selectedKey = catalogKey();
    selected.push(item);
    renderSelected();
    updateSummary();
  }

  function removeStock(value) {
    delete selectedIndex[value];
    selected = selected.filter(function (s) { return s.value !== value; });
    renderSelected();
    updateSummary();
  }

  function renderSelected() {
    var list = $('snpv-selected-list');
    $('snpv-selected-count').textContent = num(selected.length);
    $('snpv-selected-empty').hidden = selected.length > 0;

    list.innerHTML = selected.map(function (s) {
      return '<li style="border-left-color:' + esc(s.color) + '">'
           + '<span class="' + (s.bulk ? 'snpv-bulk' : '') + '">' + esc(s.bulk ? s.label : s.name) + '</span>'
           + '<button type="button" class="snpv-remove" data-value="' + esc(s.value) + '"'
           + ' aria-label="Remove ' + esc(s.bulk ? s.label : s.name) + '">&times;</button></li>';
    }).join('');
  }

  /* ----------------------------------------------------------------------
     Region controls
     ---------------------------------------------------------------------- */
  function rebuildChromosomes() {
    var select = $('snpv-chromosome');
    var set = bounds[assembly()] || {};
    var keep = select.value;
    select.innerHTML = Object.keys(set).map(function (c) {
      return '<option value="' + esc(c) + '">' + (c === '0' ? '0 — unmapped scaffolds' : esc(c)) + '</option>';
    }).join('');
    if (keep && set[keep]) { select.value = keep; }
    else { select.value = set['1'] ? '1' : Object.keys(set)[0]; }
    applyChromosomeBounds();
  }

  function applyChromosomeBounds() {
    var set = bounds[assembly()] || {};
    var chr = $('snpv-chromosome').value;
    var pair = set[chr];
    if (!pair) { return; }
    $('snpv-start').min = 1;
    $('snpv-start').max = pair[1];
    $('snpv-end').min = pair[0];
    $('snpv-end').max = pair[1];
    updateRegionReadout();
  }

  function regionSpan() {
    var start = parseInt($('snpv-start').value, 10);
    var end = parseInt($('snpv-end').value, 10);
    if (isNaN(start) || isNaN(end) || end < start) { return null; }
    return end - start + 1;
  }

  function updateRegionReadout() {
    var readout = $('snpv-region-readout');
    var warn = $('snpv-region-warning');
    var chr = $('snpv-chromosome').value;
    var set = bounds[assembly()] || {};
    var pair = set[chr] || [1, 0];

    if ($('snpv-positions').value === 'all') {
      readout.textContent = 'Chromosome ' + chr + ', every genotyped site from '
                          + num(pair[0]) + ' to ' + num(pair[1]) + ' bp.';
      showWarning(warn, 'A whole chromosome is the slowest query SNPversity can be asked for. '
                      + 'Estimate the time first, and expect minutes rather than seconds.');
      updateSummary();
      return;
    }

    var span = regionSpan();
    if (span === null) {
      readout.textContent = 'Give a start and an end position on chromosome ' + chr
                          + '. Its genotyped sites run from ' + num(pair[0]) + ' to ' + num(pair[1]) + ' bp.';
      warn.hidden = true;
      updateSummary();
      return;
    }

    readout.textContent = 'chr' + chr + ': ' + num($('snpv-start').value) + ' – '
                        + num($('snpv-end').value) + ' · ' + num(span) + ' bp';

    if (span > WIDE_REGION) {
      showWarning(warn, 'That is ' + num(span) + ' bp. Past about 10 Mbp a query takes minutes '
                      + 'rather than seconds, and gets slower with every stock added.');
    } else if (parseInt($('snpv-end').value, 10) < pair[0]) {
      showWarning(warn, 'The first genotyped site on chromosome ' + chr + ' is at ' + num(pair[0])
                      + ' bp, so this range holds none.');
    } else {
      warn.hidden = true;
    }
    updateSummary();
  }

  function showWarning(node, text) {
    node.innerHTML = '<p>' + esc(text) + '</p>';
    node.hidden = false;
  }

  /* ----------------------------------------------------------------------
     Gene model type-ahead
     ---------------------------------------------------------------------- */
  var lookupModels = null;   /* built in init(), so MGDB.debounce is loaded */

  function fetchModels(term) {
    var status = $('snpv-gene-status');
    if (term.trim().length < 3) {
      $('snpv-gene-listbox').hidden = true;
      status.textContent = 'Type at least three characters.';
      return;
    }
    status.textContent = 'Looking up gene models…';
    apiGet({ action: 'models', assembly: assembly(), input: term })
      .then(function (data) {
        geneItems = data.models || [];
        var list = $('snpv-gene-listbox');
        if (!geneItems.length) {
          list.innerHTML = '<li class="is-disabled">No gene model matches that.</li>';
          status.textContent = '';
        } else {
          list.innerHTML = geneItems.map(function (m, i) {
            return '<li role="option" id="snpv-gene-opt-' + i + '" data-index="' + i + '" aria-selected="false">'
                 + '<span class="snpv-option-name">' + esc(m) + '</span></li>';
          }).join('');
          status.textContent = data.capped
            ? 'Showing the first 50 matches. Type more of the name to narrow them.'
            : geneItems.length + ' match' + (geneItems.length === 1 ? '' : 'es') + '.';
        }
        list.hidden = false;
        geneIndex = -1;
        $('snpv-gene-input').setAttribute('aria-expanded', 'true');
      })
      .catch(function () { status.textContent = 'The gene model lookup did not answer.'; });
  }

  function chooseModel(model) {
    var input = $('snpv-gene-input');
    var status = $('snpv-gene-status');
    input.value = model;
    $('snpv-gene-listbox').hidden = true;
    input.setAttribute('aria-expanded', 'false');
    status.textContent = 'Looking up its position…';

    apiGet({ action: 'range', assembly: assembly(), model: model })
      .then(function (data) {
        if (!data.range) {
          status.textContent = 'No position is recorded for ' + model + ' on this assembly.';
          return;
        }
        var offset = parseInt($('snpv-offset').value, 10);
        if (isNaN(offset) || offset < 0) { offset = 0; }
        var lo = Math.max(1, data.range.min - offset);
        var hi = data.range.max + offset;
        $('snpv-chromosome').value = String(data.range.chr);
        applyChromosomeBounds();
        $('snpv-positions').value = 'range';
        togglePositionFields();
        $('snpv-start').value = lo;
        $('snpv-end').value = hi;
        status.textContent = model + ' is chr' + data.range.chr + ':' + num(data.range.min)
                           + '–' + num(data.range.max) + '. The region below has '
                           + num(offset) + ' bp on each side.';
        updateRegionReadout();
      })
      .catch(function () { status.textContent = 'The position lookup did not answer.'; });
  }

  /* ----------------------------------------------------------------------
     Output and the run bar
     ---------------------------------------------------------------------- */
  function togglePositionFields() {
    var all = $('snpv-positions').value === 'all';
    $('snpv-start-field').hidden = all;
    $('snpv-end-field').hidden = all;
    updateRegionReadout();
  }

  function updateSummary() {
    var chosen = document.querySelector('input[name="dataSet"]:checked');
    var parts = [];
    var missing = [];

    if (!chosen) { missing.push('a dataset'); }
    else { parts.push(datasets[chosen.value] ? datasets[chosen.value].label : chosen.value); }

    var stockCount = selected.reduce(function (n, s) { return n + (s.bulk ? s.count : 1); }, 0);
    var hasFile = $('snpv-stockfile').files && $('snpv-stockfile').files.length > 0;
    if (!selected.length && !hasFile) { missing.push('at least one stock'); }
    else if (selected.length) { parts.push(num(stockCount) + ' stock' + (stockCount === 1 ? '' : 's')); }
    else { parts.push('stocks from a file'); }

    if ($('snpv-positions').value === 'all') {
      parts.push('all of chromosome ' + $('snpv-chromosome').value);
    } else {
      var span = regionSpan();
      if (span === null) { missing.push('a start and end position'); }
      else { parts.push(num(span) + ' bp of chromosome ' + $('snpv-chromosome').value); }
    }

    var summary = $('snpv-runbar-summary');
    if (missing.length) {
      summary.textContent = 'Still needed: ' + missing.join(', ') + '.';
      $('snpv-run').disabled = true;
      $('snpv-estimate').disabled = true;
    } else {
      summary.textContent = parts.join(' · ');
      $('snpv-run').disabled = false;
      $('snpv-estimate').disabled = false;
    }
  }

  /* Everything the engine needs, in its own field names. */
  function buildFormData() {
    var chosen = document.querySelector('input[name="dataSet"]:checked');
    var data = new FormData();
    data.append('dataSet', chosen ? chosen.value : '');
    data.append('assembly', assembly());
    data.append('chromosome', $('snpv-chromosome').value);
    data.append('positions', $('snpv-positions').value);
    data.append('resultsMax', $('snpv-perpage').value || '50');
    data.append('select-region-type',
      document.querySelector('input[name="regionMode"]:checked').value);

    if ($('snpv-positions').value === 'range') {
      data.append('startPosition', $('snpv-start').value);
      data.append('endPosition', $('snpv-end').value);
    }

    selected.forEach(function (s, i) { data.append('taxa[' + i + ']', s.value); });

    /* The engine's project field is a single scalar even on its own form,
       because that <select multiple> has no [] in its name and PHP keeps the
       last value. Sending the first selected stock's project matches what a
       browser would have sent, and it only matters to the file-upload path. */
    if (selected.length) { data.append('project', selected[0].project || ''); }

    var file = $('snpv-stockfile').files && $('snpv-stockfile').files[0];
    if (file) { data.append('stockFile', file); }

    return data;
  }

  function setBusy(on, text) {
    $('snpv-progress').hidden = !on;
    if (text) { $('snpv-progress-text').textContent = text; }
    $('snpv-run').disabled = on;
    $('snpv-estimate').disabled = on;
  }

  function showError(message) {
    var box = $('snpv-error');
    if (!message) { box.hidden = true; return; }
    box.innerHTML = '<p>' + esc(message) + '</p>';
    box.hidden = false;
  }

  function runEstimate() {
    showError('');
    setBusy(true, 'Asking SNPversity how long this will take…');
    apiPost(withAction(buildFormData(), 'estimate'))
      .then(function (data) {
        setBusy(false);
        var minutes = data.minutes;
        var text = 'About ' + (minutes <= 1 ? 'a minute' : minutes + ' minutes')
                 + ' for ' + num(data.stocks) + ' stock' + (data.stocks === 1 ? '' : 's')
                 + ' over ' + num(data.range) + ' bp.';
        showWarning($('snpv-region-warning'), text + (minutes > 9
          ? ' Queries this long do sometimes time out; narrowing the region or the stock list is the fix.'
          : ''));
      })
      .catch(function (error) { setBusy(false); showError(error.message); });
  }

  /* Running a query, and surviving the gateway that will not wait for it.
   *
   * Apache proxies to php-fpm with a 60-second timeout on this host — measured:
   * a deliberate 75-second sleep through the stack returns 504 at 60.05 s. The
   * engine's own time estimator routinely answers "4 minutes" for a wide
   * region, so the submit response is *expected* to be lost on exactly the
   * queries that matter most. The legacy page had the same ceiling and nothing
   * to say about it: it blocked the screen with "This may take a few minutes"
   * and, when the gateway gave up, left that overlay on screen forever.
   *
   * So the query id is minted here rather than by the server. The id is what
   * names the engine's output files, so once the browser knows it, the result
   * can be picked up whatever happens to the request that started it: the page
   * polls action=status for that id and goes to the result when the file
   * appears. The submit response, when it does arrive, just gets there first.
   */
  function newQueryId() {
    if (window.crypto && window.crypto.getRandomValues) {
      var bytes = new Uint8Array(16);
      window.crypto.getRandomValues(bytes);
      return [].map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    }
    var out = '';
    while (out.length < 32) { out += Math.floor(Math.random() * 16).toString(16); }
    return out;
  }

  var pollTimer = null;
  var pollStarted = 0;
  var settled = false;

  function stopPolling() {
    window.clearTimeout(pollTimer);
    pollTimer = null;
  }

  function goToResult(url) {
    if (settled) { return; }
    settled = true;
    stopPolling();
    window.location.href = url;
  }

  function pollFor(id) {
    var POLL_LIMIT = 20 * 60 * 1000;   /* past this the engine has not answered */
    pollTimer = window.setTimeout(function () {
      apiGet({ action: 'status', query: id })
        .then(function (data) {
          if (data.ready) { goToResult(data.result_url); return; }
          if (settled) { return; }
          /* A query that finished and found nothing writes no output file, so
             waiting for one would wait forever. The run record is what tells
             the difference, and it is the only thing that can when the submit
             response was lost to the 60-second gateway. */
          if (data.state === 'empty' || data.state === 'failed') {
            settled = true;
            stopPolling();
            setBusy(false);
            showError(data.message || 'The query did not produce a result.');
            return;
          }
          var mins = Math.round((Date.now() - pollStarted) / 60000);
          setBusy(true, 'Still running' + (mins >= 1 ? ' — ' + mins + ' minute' + (mins === 1 ? '' : 's')
                                                     + ' so far' : '')
                      + '. You can leave this page open, or come back to the result later at '
                      + window.location.origin + '/snpversity/send/?query=' + id);
          if (Date.now() - pollStarted > POLL_LIMIT) {
            settled = true;
            setBusy(false);
            showError('The query has been running for twenty minutes without finishing. It may still '
                    + 'complete — the result will appear at /snpversity/send/?query=' + id
                    + ' if it does. A narrower region or fewer stocks will run faster.');
            return;
          }
          pollFor(id);
        })
        .catch(function () { if (!settled) { pollFor(id); } });
    }, 5000);
  }

  function runQuery(event) {
    event.preventDefault();
    showError('');
    settled = false;
    pollStarted = Date.now();
    setBusy(true, 'Running the query. A wide region over many stocks can take several minutes; '
                + 'this page moves to the results when it is done.');

    var data = buildFormData();
    var id = newQueryId();
    data.append('query', id);

    /* Polling starts alongside the request, not after it fails: a 504 arrives
       at 60 s, and by then the answer may already be on disk. */
    pollFor(id);

    apiPost(withAction(data, 'submit'))
      .then(function (result) { goToResult(result.result_url); })
      .catch(function (error) {
        /* A lost submit response is not a failed query. Only a refusal the
           server actually explained stops the wait. */
        if (settled) { return; }
        if (error.code === 'invalid' || error.code === 'method' || error.code === 'empty') {
          settled = true;
          stopPolling();
          setBusy(false);
          showError(error.message);
        }
      });
  }

  function withAction(data, action) { data.append('action', action); return data; }

  /* ----------------------------------------------------------------------
     Examples

     The legacy form's Quick Select buttons chose a project and then a random
     region: onChromosomeChange() picked start and end with Math.random(), so
     pressing the same button twice ran two different queries. These are fixed
     regions that were run before shipping.
     ---------------------------------------------------------------------- */
  var EXAMPLES = {
    small: {
      dataset: 'ZeaGBSv27publicImputed20150114',
      project: 'Imputation Test',
      names: ['B73', 'Mo17', 'CML247'],
      chr: '1', start: '158918000', end: '159225000'
    },
    founders: {
      dataset: 'ZeaGBSv27publicImputed20150114',
      project: 'Imputation Test',
      names: ['B73', 'B97', 'CML103', 'CML228', 'CML247', 'CML277', 'CML322', 'CML333',
              'CML52', 'CML69', 'HP301', 'Il14H', 'Ki11', 'Ki3', 'Ky21', 'M162W', 'M37W',
              'Mo17', 'Mo18W', 'MS71', 'NC350', 'NC358', 'Oh43', 'OH7B', 'P39', 'Tx303', 'Tzi8'],
      chr: '1', start: '158918000', end: '159225000'
    }
  };

  function loadExample(key) {
    var ex = EXAMPLES[key];
    if (!ex) { return; }
    var radio = document.querySelector('input[name="dataSet"][value="' + ex.dataset + '"]');
    if (radio) { radio.checked = true; markDatasetCards(); }

    loadCatalog().then(function (data) {
      selected = [];
      selectedIndex = {};
      selectedKey = catalogKey();
      var wanted = {};
      ex.names.forEach(function (n) { wanted[n.toLowerCase()] = true; });
      data.rows.forEach(function (row) {
        if (row.project === ex.project && wanted[row.name.toLowerCase()] && !selectedIndex[row.value]) {
          selectedIndex[row.value] = true;
          selected.push(row);
        }
      });
      renderSelected();
      rebuildChromosomes();
      $('snpv-chromosome').value = ex.chr;
      applyChromosomeBounds();
      $('snpv-positions').value = 'range';
      togglePositionFields();
      $('snpv-start').value = ex.start;
      $('snpv-end').value = ex.end;
      updateRegionReadout();
      $('snpv-stock-input').focus();
    }).catch(function () { /* the catalog's own message is already on screen */ });
  }

  /* :has() covers most browsers; this covers the rest, and is also what keeps
     the card marked when a preset sets the radio in script. */
  function markDatasetCards() {
    var cards = document.querySelectorAll('.snpv-dataset-card');
    for (var i = 0; i < cards.length; i++) {
      var input = cards[i].querySelector('input');
      cards[i].classList.toggle('is-selected', !!(input && input.checked));
    }
  }

  /* ----------------------------------------------------------------------
     Wiring
     ---------------------------------------------------------------------- */
  function init() {
    root = document.getElementById('snpv-main');
    if (!root || !document.getElementById('snpv-form')) { return; }

    api = root.getAttribute('data-api');
    try {
      bounds = JSON.parse(root.getAttribute('data-bounds'));
      datasets = JSON.parse(root.getAttribute('data-datasets'));
    } catch (e) { bounds = { v2: {}, v3: {} }; datasets = {}; }

    if (window.MGDB && MGDB.sectionTabs) { MGDB.sectionTabs(); }
    lookupModels = (window.MGDB && MGDB.debounce)
      ? MGDB.debounce(fetchModels, 350)
      : fetchModels;

    /* Examples, added here rather than in the template: they only work with
       this file loaded, and a button that does nothing is worse than none. */
    var runbar = $('snpv-runbar');
    var examples = document.createElement('div');
    examples.className = 'snpv-examples';
    examples.innerHTML = '<span class="mgdb-label">Start from an example</span> '
      + '<button type="button" class="mgdb-button mgdb-button-quiet" data-example="small">'
      + 'Three inbreds, 307 kb of chromosome 1</button> '
      + '<button type="button" class="mgdb-button mgdb-button-quiet" data-example="founders">'
      + 'The 26 NAM founders, same region</button>';
    runbar.parentNode.insertBefore(examples, $('snpv-step-dataset'));
    examples.addEventListener('click', function (e) {
      var button = e.target.closest('button[data-example]');
      if (button) { loadExample(button.getAttribute('data-example')); }
    });

    /* Dataset */
    document.querySelectorAll('input[name="dataSet"]').forEach(function (input) {
      input.addEventListener('change', function () {
        markDatasetCards();
        /* The stock roster and the chromosome set both belong to the
           assembly, so a dataset change that crosses assemblies invalidates
           the selection rather than silently carrying names the new dataset
           does not have. */
        if (selected.length && selectedKey && selectedKey !== catalogKey()) {
          selected = [];
          selectedIndex = {};
          selectedKey = '';
          renderSelected();
        }
        renderProjectChips();
        setStockStatus();
        rebuildChromosomes();
        updateSummary();
        if (catalog[catalogKey()]) { return; }
        if (document.activeElement === $('snpv-stock-input')) { loadCatalog(); }
      });
    });
    markDatasetCards();

    /* Projects */
    $('snpv-project-chips').addEventListener('click', function (e) {
      var chip = e.target.closest('.snpv-project-chip');
      if (chip) { toggleProject(parseInt(chip.getAttribute('data-project'), 10)); }
    });

    /* Stock combobox */
    var stockInput = $('snpv-stock-input');
    stockInput.addEventListener('focus', function () { loadCatalog().catch(function () {}); });
    stockInput.addEventListener('input', function () { loadCatalog().then(function () { openCombo(stockInput.value); }).catch(function () {}); });
    stockInput.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); moveCombo(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); moveCombo(-1); }
      else if (e.key === 'Enter') {
        e.preventDefault();
        var pick = comboIndex >= 0 ? comboItems[comboIndex] : comboItems[0];
        if (pick) { addStock(pick); stockInput.value = ''; openCombo(''); }
      } else if (e.key === 'Escape') { closeCombo(); }
    });
    $('snpv-stock-browse').addEventListener('click', function () {
      if (!$('snpv-stock-listbox').hidden) { closeCombo(); return; }
      loadCatalog().then(function () { openCombo(stockInput.value); stockInput.focus(); }).catch(function () {});
    });
    $('snpv-stock-listbox').addEventListener('mousedown', function (e) {
      var li = e.target.closest('li[data-index]');
      if (!li) { return; }
      e.preventDefault();
      addStock(comboItems[parseInt(li.getAttribute('data-index'), 10)]);
      stockInput.value = '';
      openCombo('');
    });
    document.addEventListener('click', function (e) {
      if (!e.target.closest('[data-combobox="stock"]')) { closeCombo(); }
      if (!e.target.closest('[data-combobox="gene"]')) {
        $('snpv-gene-listbox').hidden = true;
        $('snpv-gene-input').setAttribute('aria-expanded', 'false');
      }
    });

    $('snpv-selected-list').addEventListener('click', function (e) {
      var button = e.target.closest('.snpv-remove');
      if (button) { removeStock(button.getAttribute('data-value')); }
    });
    $('snpv-clear-stocks').addEventListener('click', function () {
      selected = []; selectedIndex = {}; selectedKey = ''; renderSelected(); updateSummary();
    });
    $('snpv-stockfile').addEventListener('change', updateSummary);

    /* Region */
    document.querySelectorAll('input[name="regionMode"]').forEach(function (input) {
      input.addEventListener('change', function () {
        $('snpv-gene-block').hidden = (input.value !== 'model');
        if (input.value === 'model') { $('snpv-gene-input').focus(); }
      });
    });
    var geneInput = $('snpv-gene-input');
    geneInput.addEventListener('input', function () { lookupModels(geneInput.value); });
    geneInput.addEventListener('keydown', function (e) {
      var list = $('snpv-gene-listbox');
      var nodes = list.querySelectorAll('li[data-index]');
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        if (!nodes.length) { return; }
        e.preventDefault();
        geneIndex = (geneIndex + (e.key === 'ArrowDown' ? 1 : -1) + nodes.length) % nodes.length;
        for (var i = 0; i < nodes.length; i++) {
          nodes[i].setAttribute('aria-selected', i === geneIndex ? 'true' : 'false');
        }
        nodes[geneIndex].scrollIntoView({ block: 'nearest' });
      } else if (e.key === 'Enter') {
        e.preventDefault();
        var pick = geneIndex >= 0 ? geneItems[geneIndex] : geneItems[0];
        if (pick) { chooseModel(pick); }
      } else if (e.key === 'Escape') { list.hidden = true; }
    });
    $('snpv-gene-listbox').addEventListener('mousedown', function (e) {
      var li = e.target.closest('li[data-index]');
      if (!li) { return; }
      e.preventDefault();
      chooseModel(geneItems[parseInt(li.getAttribute('data-index'), 10)]);
    });
    $('snpv-offset').addEventListener('change', function () {
      if (geneInput.value.trim()) { chooseModel(geneInput.value.trim()); }
    });

    $('snpv-chromosome').addEventListener('change', applyChromosomeBounds);
    $('snpv-positions').addEventListener('change', togglePositionFields);
    $('snpv-start').addEventListener('input', updateRegionReadout);
    $('snpv-end').addEventListener('input', updateRegionReadout);

    /* Results */
    $('snpv-perpage').addEventListener('input', updateSummary);

    /* Run */
    $('snpv-estimate').addEventListener('click', runEstimate);
    $('snpv-form').addEventListener('submit', runQuery);

    rebuildChromosomes();
    togglePositionFields();
    renderSelected();
    setStockStatus();
    updateSummary();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
