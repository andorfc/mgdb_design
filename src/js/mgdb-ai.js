/* file: mgdb-ai.js
 *
 * purpose: behavior for the AI & Machine Learning Data Hub (/ai).
 *
 *   - the catalog search, its examples, and its advanced filters
 *   - the results table: sorting, filtering, page size, pagination
 *   - the "resources by data type" figure and the table under it
 *   - copy-citation and copy-DOI on the publications
 *   - the sticky section tab scrollspy
 *
 * The catalog is inlined by controllers/ai.php as two JSON script blocks, so
 * the first keystroke is answered without a network round trip. Every card on
 * the page is rendered server side; nothing here is needed to read the page.
 *
 * Bauplan's includeScript emits into <head>, so this file runs while the
 * document is still parsing. Everything below waits for DOMContentLoaded or
 * every query returns null.
 */

(function () {
  'use strict';

  var CATEGORY_LABEL = {
    tool: 'Tool',
    data: 'Data',
    code: 'Code',
    publication: 'Publication'
  };

  var CATEGORY_PLURAL = {
    tool: 'Tools',
    data: 'Datasets',
    code: 'Repositories',
    publication: 'Publications'
  };

  var CATEGORY_RANK = { tool: 0, data: 1, code: 2, publication: 3 };

  /* Matches the top-border colours the three grids carry in mgdb-ai.css, so a
     bar in the figure and a card in a grid name the same thing the same way. */
  var CATEGORY_COLOR = {
    tool: '#285d46',
    data: '#1a5b7a',
    code: '#501719',
    publication: '#8a5a0f'
  };

  var catalog = { resources: [], topics: {}, genomes: {} };

  var state = {
    term: '',
    category: '',
    topic: '',
    genome: '',
    access: '',
    filter: '',        // the within-results filter, separate from the query
    sort: 'relevance',
    dir: 'asc',
    page: 1,
    pageSize: 25,
    matches: [],
    searched: false
  };

  function byId(id) { return document.getElementById(id); }

  function esc(value) {
    if (value === null || value === undefined) { return ''; }
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function readJson(id) {
    var el = byId(id);
    if (!el) { return null; }
    try { return JSON.parse(el.textContent || 'null'); }
    catch (error) { return null; }
  }

  function topicLabel(key) {
    return catalog.topics && catalog.topics[key] ? catalog.topics[key] : key;
  }

  /* ======================================================================
     Sticky section tabs

     Driven by scroll, IntersectionObserver and resize together: no single
     trigger fires in every case, and the results section appears and
     disappears under the tabs as searches run.
     ====================================================================== */

  function initTabs() {
    var tabs = document.querySelectorAll('.mgdb-section-tabs a');
    if (!tabs.length) { return; }

    var pairs = [];
    Array.prototype.forEach.call(tabs, function (tab) {
      var href = tab.getAttribute('href') || '';
      if (href.charAt(0) !== '#') { return; }
      var section = document.getElementById(href.slice(1));
      if (section) { pairs.push({ tab: tab, section: section }); }
    });
    if (!pairs.length) { return; }

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

    /* The line the spy measures against is the section's own
       scroll-margin-top, read back from CSS rather than repeated here -- so a
       clicked tab and the scrollspy agree by construction even when the tab
       bar wraps to two rows. */
    function triggerLine() {
      var bar = document.querySelector('.mgdb-section-tabs');
      var barHeight = bar ? bar.getBoundingClientRect().height : 0;
      var margin = 0;
      if (pairs[0]) {
        margin = parseFloat(window.getComputedStyle(pairs[0].section).scrollMarginTop) || 0;
      }
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

      // At the foot of the document the last section may never reach the line.
      var atBottom = (window.innerHeight + window.scrollY) >= (document.body.scrollHeight - 2);
      if (atBottom) { current = pairs[pairs.length - 1]; }

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

    // The results section changes height as searches run, which moves
    // everything below it.
    var results = byId('ai-results-section');
    if (results && window.MutationObserver) {
      new window.MutationObserver(update).observe(results, {
        childList: true, subtree: true, attributes: true, attributeFilter: ['hidden']
      });
    }

    update();
  }

  /* ======================================================================
     Search
     ====================================================================== */

  /* All terms must match somewhere. Where each one lands decides the ranking:
     the name outranks the summary, which outranks the keyword blob. */
  function score(resource, terms) {
    var name = resource.name.toLowerCase();
    var summary = (resource.summary || '').toLowerCase();
    var total = 0;

    for (var i = 0; i < terms.length; i++) {
      var term = terms[i];
      if (resource.q.indexOf(term) === -1) { return -1; }

      if (name === term) { total += 200; }
      else if (name.indexOf(term) === 0) { total += 120; }
      else if (name.indexOf(term) !== -1) { total += 80; }
      else if (summary.indexOf(term) !== -1) { total += 30; }
      else { total += 10; }
    }

    return total;
  }

  function matchesFilters(resource) {
    if (state.category && resource.category !== state.category) { return false; }
    if (state.topic && resource.topics.indexOf(state.topic) === -1) { return false; }
    if (state.genome && resource.genomes.indexOf(state.genome) === -1) { return false; }
    if (state.access === 'internal' && resource.external) { return false; }
    if (state.access === 'external' && !resource.external) { return false; }
    return true;
  }

  function compare(a, b) {
    var dir = state.dir === 'desc' ? -1 : 1;
    var av, bv;

    switch (state.sort) {
      case 'name':
        return dir * a.name.localeCompare(b.name);
      case 'category':
        av = CATEGORY_LABEL[a.category] || a.category;
        bv = CATEGORY_LABEL[b.category] || b.category;
        return dir * (av.localeCompare(bv) || a.name.localeCompare(b.name));
      case 'topics':
        av = a.topics.map(topicLabel).join(', ');
        bv = b.topics.map(topicLabel).join(', ');
        return dir * (av.localeCompare(bv) || a.name.localeCompare(b.name));
      case 'access':
        av = a.external ? 'External' : 'Internal';
        bv = b.external ? 'External' : 'Internal';
        return dir * (av.localeCompare(bv) || a.name.localeCompare(b.name));
      default:
        /* Relevance first. Scores tie often in a catalog this small -- two
           names both containing "structure" score the same -- so the tie goes
           to the kind of resource a reader is most likely to have meant: a
           tool they can open, then data they can download, then the code and
           the paper behind it. Name orders what is still equal, so the list is
           stable between renders. */
        return (b._score - a._score)
            || (CATEGORY_RANK[a.category] - CATEGORY_RANK[b.category])
            || a.name.localeCompare(b.name);
    }
  }

  function runSearch(options) {
    var opts = options || {};
    var terms = state.term.toLowerCase().split(/\s+/).filter(Boolean);

    var matches = [];
    catalog.resources.forEach(function (resource) {
      if (!matchesFilters(resource)) { return; }
      var value = terms.length ? score(resource, terms) : 0;
      if (value < 0) { return; }
      resource._score = value;
      matches.push(resource);
    });

    state.matches = matches;
    state.searched = true;
    state.page = 1;

    render();

    if (opts.scroll) {
      var section = byId('ai-results-section');
      if (section) { section.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    }
  }

  /* The within-results filter runs over the same haystack the search uses, so
     "narrow this table" and "search" agree about what a row contains. */
  function visibleMatches() {
    if (!state.filter) { return state.matches; }
    var terms = state.filter.toLowerCase().split(/\s+/).filter(Boolean);
    return state.matches.filter(function (resource) {
      for (var i = 0; i < terms.length; i++) {
        if (resource.q.indexOf(terms[i]) === -1) { return false; }
      }
      return true;
    });
  }

  /* ======================================================================
     Results rendering
     ====================================================================== */

  function render() {
    var section = byId('ai-results-section');
    var body = byId('ai-results-body');
    var empty = byId('ai-results-empty');
    var scroll = document.querySelector('.mgdb-ai-page .ai-results-section .mgdb-table-scroll');
    if (!section || !body) { return; }

    if (!state.searched) {
      section.hidden = true;
      return;
    }
    section.hidden = false;

    var rows = visibleMatches().slice().sort(compare);
    var total = rows.length;
    var size = state.pageSize === 'all' ? Math.max(total, 1) : state.pageSize;
    var pageCount = Math.max(1, Math.ceil(total / size));
    if (state.page > pageCount) { state.page = pageCount; }

    var start = (state.page - 1) * size;
    var slice = rows.slice(start, start + size);

    body.innerHTML = slice.map(rowHtml).join('');

    if (empty) { empty.hidden = total !== 0; }
    if (scroll) { scroll.hidden = total === 0; }

    updateStatus(total, start, slice.length);
    renderPagination(pageCount);
    updateSortIndicators();
  }

  function rowHtml(resource) {
    var arrow = resource.external ? '&nearr;' : '&rarr;';
    var attrs = resource.external ? ' target="_blank" rel="noopener"' : '';
    var topics = resource.topics.length
      ? resource.topics.map(function (key) {
          return '<span class="ai-chip">' + esc(topicLabel(key)) + '</span>';
        }).join('')
      : '<span class="mgdb-muted">&mdash;</span>';

    return '<tr>'
      + '<td>'
      +   '<a class="ai-result-name" href="' + esc(resource.url) + '"' + attrs + '>' + esc(resource.name) + '</a>'
      +   '<span class="ai-result-summary">' + esc(resource.summary) + '</span>'
      + '</td>'
      + '<td><span class="ai-chip ai-chip-' + esc(resource.category) + '">'
      +   esc(CATEGORY_LABEL[resource.category] || resource.category) + '</span></td>'
      + '<td><span class="ai-topic-chips">' + topics + '</span></td>'
      + '<td>' + (resource.external ? 'External' : 'Internal') + '</td>'
      + '<td class="ai-col-open">'
      +   '<a href="' + esc(resource.url) + '"' + attrs + '>' + esc(resource.label)
      +   ' <span aria-hidden="true">' + arrow + '</span></a>'
      + '</td>'
      + '</tr>';
  }

  function updateStatus(total, start, shown) {
    var status = byId('ai-results-status');
    if (!status) { return; }

    if (total === 0) {
      status.textContent = 'No resources matched.';
      return;
    }

    var noun = total === 1 ? 'resource' : 'resources';
    var text;
    if (state.pageSize === 'all' || total <= shown) {
      text = 'Showing all ' + total + ' matching ' + noun + '.';
    } else {
      text = 'Showing ' + (start + 1) + '–' + (start + shown) + ' of ' + total + ' matching ' + noun + '.';
    }

    var filters = [];
    if (state.term) { filters.push('term “' + state.term + '”'); }
    if (state.category) { filters.push((CATEGORY_LABEL[state.category] || state.category).toLowerCase() + ' resources'); }
    if (state.topic) { filters.push(topicLabel(state.topic).toLowerCase()); }
    if (state.genome) { filters.push(catalog.genomes[state.genome] || state.genome); }
    if (state.access) { filters.push(state.access === 'internal' ? 'internal only' : 'external only'); }
    if (state.filter) { filters.push('table filter “' + state.filter + '”'); }
    if (filters.length) { text += ' Matching ' + filters.join(', ') + '.'; }

    status.textContent = text;
  }

  function renderPagination(pageCount) {
    var nav = byId('ai-pagination');
    if (!nav) { return; }

    if (pageCount <= 1) {
      nav.innerHTML = '';
      return;
    }

    var current = state.page;
    var html = '<button class="ai-page-btn" type="button" data-page="' + (current - 1) + '"'
             + (current === 1 ? ' disabled' : '') + '>&larr; Previous</button>';

    var pages = [1];
    if (current > 3) { pages.push('gap'); }
    for (var p = Math.max(2, current - 1); p <= Math.min(pageCount - 1, current + 1); p++) { pages.push(p); }
    if (current < pageCount - 2) { pages.push('gap'); }
    if (pageCount > 1) { pages.push(pageCount); }

    pages.forEach(function (page) {
      if (page === 'gap') {
        html += '<span class="ai-page-ellipsis" aria-hidden="true">&hellip;</span>';
      } else {
        html += '<button class="ai-page-btn' + (page === current ? ' is-active' : '') + '"'
             +  ' type="button" data-page="' + page + '"'
             +  (page === current ? ' aria-current="page"' : '') + '>' + page + '</button>';
      }
    });

    html += '<button class="ai-page-btn" type="button" data-page="' + (current + 1) + '"'
         +  (current === pageCount ? ' disabled' : '') + '>Next &rarr;</button>';

    nav.innerHTML = html;

    Array.prototype.forEach.call(nav.querySelectorAll('button[data-page]'), function (btn) {
      btn.addEventListener('click', function () {
        var page = parseInt(btn.getAttribute('data-page'), 10);
        if (!page || page < 1 || page > pageCount || page === state.page) { return; }
        state.page = page;
        render();
        var section = byId('ai-results-section');
        if (section) { section.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
      });
    });
  }

  function updateSortIndicators() {
    var heads = document.querySelectorAll('#ai-results-table th[data-ai-sort]');
    Array.prototype.forEach.call(heads, function (th) {
      var key = th.getAttribute('data-ai-sort');
      if (state.sort === key) {
        th.setAttribute('aria-sort', state.dir === 'desc' ? 'descending' : 'ascending');
      } else {
        th.setAttribute('aria-sort', 'none');
      }
    });
  }

  /* ======================================================================
     Form wiring
     ====================================================================== */

  function readForm() {
    var query = byId('ai-query');
    state.term = query ? query.value.trim() : '';
    state.category = (byId('ai-filter-category') || {}).value || '';
    state.topic = (byId('ai-filter-topic') || {}).value || '';
    state.genome = (byId('ai-filter-genome') || {}).value || '';
    state.access = (byId('ai-filter-access') || {}).value || '';
  }

  function updateClearButton() {
    var clear = byId('ai-query-clear');
    var query = byId('ai-query');
    if (clear && query) { clear.hidden = query.value.length === 0; }
  }

  function resetAdvanced() {
    ['ai-filter-category', 'ai-filter-topic', 'ai-filter-genome', 'ai-filter-access'].forEach(function (id) {
      var el = byId(id);
      if (el) { el.value = ''; }
    });
  }

  function initSearch() {
    var form = byId('ai-search-form');
    var query = byId('ai-query');

    if (form) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        readForm();
        state.sort = 'relevance';
        state.dir = 'asc';
        runSearch({ scroll: true });
      });
    }

    if (query) {
      query.addEventListener('input', function () {
        updateClearButton();
        // Once results are on the page, keep them in step as the term changes;
        // before the first search, typing must not open the section on its own.
        if (state.searched) {
          readForm();
          runSearch({});
        }
      });
    }

    var clear = byId('ai-query-clear');
    if (clear && query) {
      clear.addEventListener('click', function () {
        query.value = '';
        updateClearButton();
        query.focus();
        if (state.searched) { readForm(); runSearch({}); }
      });
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-ai-example]'), function (btn) {
      btn.addEventListener('click', function () {
        if (query) { query.value = btn.getAttribute('data-ai-example'); }
        updateClearButton();
        readForm();
        state.sort = 'relevance';
        state.dir = 'asc';
        runSearch({ scroll: true });
      });
    });

    ['ai-filter-category', 'ai-filter-topic', 'ai-filter-genome', 'ai-filter-access'].forEach(function (id) {
      var el = byId(id);
      if (!el) { return; }
      el.addEventListener('change', function () {
        readForm();
        runSearch({});
      });
    });

    var reset = byId('ai-adv-reset');
    if (reset) {
      reset.addEventListener('click', function () {
        resetAdvanced();
        readForm();
        if (state.searched) { runSearch({}); }
      });
    }

    var emptyReset = byId('ai-empty-reset');
    if (emptyReset) {
      emptyReset.addEventListener('click', function () {
        if (query) { query.value = ''; }
        var filter = byId('ai-results-filter');
        if (filter) { filter.value = ''; }
        state.filter = '';
        resetAdvanced();
        updateClearButton();
        readForm();
        runSearch({ scroll: true });
        if (query) { query.focus(); }
      });
    }

    var filter = byId('ai-results-filter');
    if (filter) {
      filter.addEventListener('input', function () {
        state.filter = filter.value.trim();
        state.page = 1;
        render();
      });
    }

    var pageSize = byId('ai-page-size');
    if (pageSize) {
      pageSize.addEventListener('change', function () {
        state.pageSize = pageSize.value === 'all' ? 'all' : parseInt(pageSize.value, 10) || 25;
        state.page = 1;
        render();
      });
    }

    Array.prototype.forEach.call(document.querySelectorAll('#ai-results-table th[data-ai-sort] button'), function (btn) {
      btn.addEventListener('click', function () {
        var key = btn.parentNode.getAttribute('data-ai-sort');
        if (state.sort === key) {
          state.dir = state.dir === 'asc' ? 'desc' : 'asc';
        } else {
          state.sort = key;
          state.dir = 'asc';
        }
        state.page = 1;
        render();
      });
    });

    updateClearButton();

    /* A search can be linked to: /ai?q=embeddings, or /ai?topic=structure from
       a bar in the figure below. */
    var params = new URLSearchParams(window.location.search);
    var linked = false;
    if (params.get('q') && query) { query.value = params.get('q'); linked = true; }
    ['category', 'topic', 'genome', 'access'].forEach(function (name) {
      var value = params.get(name);
      var el = byId('ai-filter-' + name);
      if (value && el) { el.value = value; linked = true; }
    });
    if (linked) {
      var adv = byId('ai-adv');
      if (adv && (params.get('category') || params.get('topic') || params.get('genome') || params.get('access'))) {
        adv.open = true;
      }
      updateClearButton();
      readForm();
      runSearch({ scroll: true });
    }
  }

  /* ======================================================================
     Copy citation / DOI
     ====================================================================== */

  function copyText(text, button) {
    var done = function () {
      var original = button.textContent;
      button.textContent = 'Copied';
      window.setTimeout(function () { button.textContent = original; }, 1600);
    };

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(function () {});
      return;
    }

    // Fallback for browsers without the async clipboard API.
    var area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'absolute';
    area.style.left = '-9999px';
    document.body.appendChild(area);
    area.select();
    try { document.execCommand('copy'); done(); } catch (error) { /* nothing to do */ }
    document.body.removeChild(area);
  }

  function initCopy() {
    Array.prototype.forEach.call(document.querySelectorAll('.ai-copy'), function (btn) {
      btn.addEventListener('click', function () {
        var value = btn.getAttribute('data-copy-value');
        if (!value) {
          var target = document.getElementById(btn.getAttribute('data-copy-target') || '');
          value = target ? target.textContent.trim() : '';
        }
        if (value) { copyText(value, btn); }
      });
    });
  }

  /* ======================================================================
     Resources by data type

     .mgdb-chart is a fixed 320px in the shared sheet, so the height has to be
     set on the element and handed to Plotly from the same variable or the bars
     are drawn into a box that is too short for them.
     ====================================================================== */

  function sizeChart(id, height) {
    var el = byId(id);
    if (el) { el.style.height = height + 'px'; }
    return height;
  }

  function initFigure() {
    var rows = readJson('ai-chart-data');
    if (!rows || !rows.length) { return; }

    var table = byId('ai-topic-table');
    if (table) {
      var body = table.querySelector('tbody');
      if (body) {
        // Highest first in the table; the chart runs the other way because
        // Plotly draws the first category at the bottom of a horizontal axis.
        body.innerHTML = rows.slice().reverse().map(function (row) {
          return '<tr><th scope="row">' + esc(row.label) + '</th>'
               + '<td class="mgdb-numeric">' + row.tool + '</td>'
               + '<td class="mgdb-numeric">' + row.data + '</td>'
               + '<td class="mgdb-numeric">' + row.code + '</td>'
               + '<td class="mgdb-numeric">' + row.publication + '</td>'
               + '<td class="mgdb-numeric">' + row.total + '</td></tr>';
        }).join('');
      }
    }

    if (!window.MGDB || !window.MGDB.chart) { return; }

    var labels = rows.map(function (row) { return row.label; });
    var height = sizeChart('ai-topic-chart', Math.max(320, rows.length * 44 + 120));

    var traces = ['tool', 'data', 'code', 'publication'].map(function (key) {
      return {
        type: 'bar',
        orientation: 'h',
        name: CATEGORY_PLURAL[key],
        y: labels,
        x: rows.map(function (row) { return row[key]; }),
        marker: { color: CATEGORY_COLOR[key] },
        hovertemplate: '%{y}<br>' + CATEGORY_PLURAL[key] + ': %{x}<extra></extra>'
      };
    });

    window.MGDB.chart({
      target: 'ai-topic-chart',
      traces: traces,
      layout: {
        height: height,
        barmode: 'stack',
        /* automargin only ever grows a margin, so l is a floor: it buys a
           gutter between the longest tick label and the first bar, which
           automargin on its own leaves flush. */
        margin: { l: 200, r: 24, t: 8, b: 44 },
        xaxis: { title: 'Resources', dtick: 2, zeroline: false },
        yaxis: { automargin: true },
        /* Plotly reverses the legend of a stacked bar by default, so it read
           right to left against the bars it labels. */
        legend: { orientation: 'h', y: -0.18, traceorder: 'normal' }
      }
    });

    /* Selecting a bar searches that data type. Plotly only gains its event
       emitter once it has drawn, so wait for the draw rather than guessing at
       a delay. */
    var el = byId('ai-topic-chart');
    if (!el || !window.MutationObserver) { return; }

    var attached = false;
    var observer = new window.MutationObserver(function () {
      if (attached || typeof el.on !== 'function') { return; }
      attached = true;
      observer.disconnect();
      el.on('plotly_click', function (event) {
        if (!event || !event.points || !event.points.length) { return; }
        var label = event.points[0].y;
        var match = rows.filter(function (row) { return row.label === label; })[0];
        if (!match) { return; }

        var select = byId('ai-filter-topic');
        if (select) { select.value = match.key; }
        var adv = byId('ai-adv');
        if (adv) { adv.open = true; }
        readForm();
        runSearch({ scroll: true });
      });
    });
    observer.observe(el, { childList: true, subtree: true });
  }

  /* ====================================================================== */

  function init() {
    var index = readJson('ai-search-index');
    if (index && index.resources) { catalog = index; }

    initTabs();
    initSearch();
    initCopy();
    initFigure();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
