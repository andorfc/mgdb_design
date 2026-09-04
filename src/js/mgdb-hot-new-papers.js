/* file: mgdb-hot-new-papers.js
 *
 * purpose: behavior for Hot New Papers (/hot_new_papers).
 *
 *   - the filter form, answered by search/hot_new_papers/hot_new_papers_api.php
 *   - the Editorial Board membership selector
 *   - the two figures
 *   - the section tab scrollspy
 *
 * The first list is rendered server side, so the page is readable and every
 * paper is linked with no script at all. The filters take over from there.
 *
 * Bauplan's includeScript emits into <head>, so the entry point waits for
 * DOMContentLoaded or every query below returns nothing.
 */

(function () {
  'use strict';

  var API = '/search/hot_new_papers/hot_new_papers_api.php';

  /* From 2026 the board publishes quarterly rather than monthly, so from that
     year the period filter offers quarters. The months in the database are
     unchanged -- a quarter is the three months it covers -- so only the
     control and the parameter name differ. */
  var QUARTERLY_FROM = 2026;
  var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'];
  var QUARTERS = [
    ['Q1', 'Q1 — January to March'],
    ['Q2', 'Q2 — April to June'],
    ['Q3', 'Q3 — July to September'],
    ['Q4', 'Q4 — October to December']
  ];

  function byId(id) { return document.getElementById(id); }

  function esc(value) {
    if (value === null || value === undefined) { return ''; }
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function num(value) { return Number(value || 0).toLocaleString(); }

  function readJson(id) {
    var el = byId(id);
    if (!el) { return null; }
    try { return JSON.parse(el.textContent || 'null'); }
    catch (error) { return null; }
  }

  /* ======================================================================
     Filters
     ====================================================================== */

  var lastQuery = '';

  function selectedYear() {
    var year = byId('hnp-year');
    return year ? parseInt(year.value, 10) || 0 : 0;
  }

  function isQuarterly() {
    var year = selectedYear();
    return year >= QUARTERLY_FROM;
  }

  /* Rebuilds the period control for the selected year. The chosen value is
     kept when it still exists, so switching 2026 to 2025 with Q2 selected
     lands on "all months" rather than an option that means nothing. */
  function syncPeriodControl() {
    var select = byId('hnp-period');
    var label = byId('hnp-period-label');
    if (!select || !label) { return; }

    var quarterly = isQuarterly();
    var wanted = quarterly ? 'quarter' : 'month';
    if (select.getAttribute('data-period') === wanted) { return; }

    var previous = select.value;
    label.textContent = quarterly ? 'Quarter' : 'Month';
    select.setAttribute('data-period', wanted);

    var html = '<option value="">' + (quarterly ? 'All quarters' : 'All months') + '</option>';
    if (quarterly) {
      QUARTERS.forEach(function (pair) {
        html += '<option value="' + pair[0] + '">' + esc(pair[1]) + '</option>';
      });
    } else {
      MONTHS.forEach(function (month) {
        html += '<option value="' + month + '">' + month + '</option>';
      });
    }
    select.innerHTML = html;

    // Keep the selection only if the rebuilt list still offers it.
    select.value = previous;
    if (select.selectedIndex < 0) { select.value = ''; }
  }

  function currentParams() {
    var params = new URLSearchParams();
    var term = byId('hnp-term');
    var year = byId('hnp-year');
    var period = byId('hnp-period');
    var recommender = byId('hnp-recommender');
    var sort = byId('hnp-sort');

    if (term && term.value.trim() !== '') { params.set('term', term.value.trim()); }
    if (year && year.value !== '0') { params.set('year', year.value); }
    if (period && period.value !== '') {
      params.set(isQuarterly() ? 'quarter' : 'month', period.value);
    }
    if (recommender && recommender.value !== '0') { params.set('recommender', recommender.value); }
    if (sort && sort.value !== 'newest') { params.set('sort', sort.value); }
    return params;
  }

  /* The filter state goes into the address bar so a reading list can be
     linked to, and so the back button returns to it. */
  function syncUrl(params) {
    if (!window.history || !window.history.replaceState) { return; }
    var query = params.toString();
    window.history.replaceState(null, '', query ? '?' + query : window.location.pathname);
  }

  function describe(summary) {
    var filters = summary.filters || {};
    var parts = [];
    if (filters.term) { parts.push('matching "' + filters.term + '"'); }
    if (filters.quarter) { parts.push('from ' + filters.quarter); }
    if (filters.month) { parts.push('from ' + filters.month); }
    if (filters.year) { parts.push('in ' + filters.year); }

    var text = num(summary.papers) + ' recommendation' + (summary.papers === 1 ? '' : 's');
    if (parts.length) { text += ' ' + parts.join(' '); }
    if (!filters.year && summary.years > 1) {
      text += ' across ' + num(summary.years) + ' years';
    }
    return text + ' (' + num(summary.elapsed_ms) + ' ms).';
  }

  function runSearch() {
    var params = currentParams();
    var results = byId('hnp-results');
    var notes = byId('hnp-notes');
    var empty = byId('hnp-empty');
    var status = byId('hnp-status');
    var exportLink = byId('hnp-export');

    notes.innerHTML = '';
    empty.hidden = true;
    status.textContent = 'Searching…';
    results.innerHTML = '<div class="mgdb-loading"><span class="mgdb-spinner" aria-hidden="true"></span>Searching recommendations&hellip;</div>';

    lastQuery = params.toString();
    syncUrl(params);

    fetch(API + (lastQuery ? '?' + lastQuery : ''), { credentials: 'same-origin' })
      .then(function (response) {
        return response.json().then(function (data) { return { ok: response.ok, data: data }; });
      })
      .then(function (wrap) {
        if (!wrap.ok || !wrap.data || !wrap.data.ok) {
          var message = (wrap.data && (wrap.data.message || wrap.data.detail)) || 'The search could not be completed.';
          results.innerHTML = '';
          notes.innerHTML = '<div class="mgdb-message mgdb-message-error" role="alert">' + esc(message) + '</div>';
          status.textContent = 'Search failed.';
          return;
        }

        var data = wrap.data;
        var summary = data.summary || {};

        if (!summary.papers) {
          results.innerHTML = '';
          empty.hidden = false;
          status.textContent = 'No recommendations matched.';
        } else {
          results.innerHTML = data.html || '';
          status.textContent = describe(summary);
        }

        if (exportLink) {
          exportLink.href = API + '?' + (lastQuery ? lastQuery + '&' : '') + 'format=tsv';
        }
      })
      .catch(function () {
        results.innerHTML = '';
        notes.innerHTML = '<div class="mgdb-message mgdb-message-error" role="alert">The search request failed. Please try again.</div>';
        status.textContent = 'Search failed.';
      });
  }

  function initFilters() {
    var form = byId('hnp-filters');
    if (!form) { return; }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      runSearch();
    });

    // A dropdown is a decision, so it searches at once; the text field waits
    // for the reader to finish typing.
    ['hnp-year', 'hnp-period', 'hnp-recommender', 'hnp-sort'].forEach(function (id) {
      var el = byId(id);
      if (!el) { return; }
      el.addEventListener('change', function () {
        if (id === 'hnp-year') { syncPeriodControl(); }
        runSearch();
      });
    });

    var typing = null;
    var term = byId('hnp-term');
    if (term) {
      term.addEventListener('input', function () {
        window.clearTimeout(typing);
        typing = window.setTimeout(runSearch, 350);
      });
    }

    var reset = byId('hnp-reset');
    if (reset) {
      reset.addEventListener('click', function () {
        window.setTimeout(function () { syncPeriodControl(); runSearch(); }, 10);
      });
    }

    Array.prototype.forEach.call(document.querySelectorAll('.hnp-example[data-term]'), function (button) {
      button.addEventListener('click', function () {
        term.value = button.getAttribute('data-term');
        byId('hnp-year').value = '0';
        syncPeriodControl();
        byId('hnp-period').value = '';
        runSearch();
        term.focus();
      });
    });

    /* Restore the filters from the address bar, and from the previous page's
       ?row=N, which counted backwards from the current year. */
    if (window.URLSearchParams) {
      var incoming = new window.URLSearchParams(window.location.search);
      var applied = false;

      var legacyRow = parseInt(incoming.get('row'), 10);
      if (!incoming.get('year') && legacyRow > 0) {
        incoming.set('year', String(new Date().getFullYear() - legacyRow + 1));
      }

      [['term', 'hnp-term'], ['year', 'hnp-year'],
       ['recommender', 'hnp-recommender'], ['sort', 'hnp-sort']].forEach(function (pair) {
        var value = incoming.get(pair[0]);
        var el = byId(pair[1]);
        if (value !== null && el) { el.value = value; applied = true; }
      });

      // The period control has to match the year before its value is set.
      syncPeriodControl();
      var period = incoming.get('quarter') || incoming.get('month');
      if (period) {
        byId('hnp-period').value = period;
        if (byId('hnp-period').selectedIndex < 0) { byId('hnp-period').value = ''; }
        applied = true;
      }

      if (applied) { runSearch(); }
    }
  }

  /* ======================================================================
     Editorial Board membership
     ====================================================================== */

  function initBoard() {
    var select = byId('hnp-board-year');
    var target = byId('hnp-board-members');
    if (!select || !target) { return; }

    select.addEventListener('change', function () {
      var year = select.value;
      target.innerHTML = '<span class="mgdb-muted">Loading&hellip;</span>';

      fetch('/search/hot_new_papers/hot_new_papers_api.php?mode=board&year=' + encodeURIComponent(year), { credentials: 'same-origin' })
        .then(function (response) { return response.json(); })
        .then(function (data) {
          if (!data || !data.ok) { throw new Error('failed'); }
          if (!data.members || !data.members.length) {
            target.innerHTML = '<p class="hnp-board-empty">No membership is recorded for ' + esc(year) + '.</p>';
            return;
          }
          target.innerHTML = data.members.map(function (member) {
            return member.id
              ? '<a href="/person?id=' + esc(member.id) + '">' + esc(member.name) + '</a>'
              : '<span>' + esc(member.name) + '</span>';
          }).join('');
        })
        .catch(function () {
          target.innerHTML = '<p class="hnp-board-empty">The membership list could not be loaded.</p>';
        });
    });
  }

  /* ======================================================================
     Figures
     ====================================================================== */

  /* .mgdb-chart is 320px tall in the shared stylesheet, and .mgdb-chart-tall
     is not defined anywhere, so Plotly drew every chart at 320px whatever
     layout.height said -- a 17-row heat map squeezed into that painted its
     axis labels and colorbar over the section below. The container is given
     the same height that Plotly is told to use, from one variable, so the two
     cannot disagree. */
  function sizeChart(id, height) {
    var el = byId(id);
    if (el) { el.style.height = height + 'px'; }
    return height;
  }

  function yearChart() {
    var data = readJson('hnp-year-data');
    if (!data || !data.length) { return; }

    var table = byId('hnp-year-table');
    if (table) {
      var body = table.querySelector('tbody');
      if (body) {
        body.innerHTML = data.map(function (row) {
          return '<tr><th scope="row">' + esc(row.year) + '</th>'
               + '<td class="mgdb-numeric" data-value="' + row.papers + '">' + num(row.papers) + '</td></tr>';
        }).join('');
      }
      if (window.MGDB && window.MGDB.sortTable) { window.MGDB.sortTable(table); }
    }

    if (!window.MGDB || !window.MGDB.chart) { return; }

    window.MGDB.chart({
      target: 'hnp-year-chart',
      traces: function () {
        return [{
          type: 'bar',
          x: data.map(function (r) { return String(r.year); }),
          y: data.map(function (r) { return r.papers; }),
          marker: { color: window.MGDB.CHART_COLORS[0] },
          hovertemplate: '%{x}<br>%{y} recommendations<extra></extra>'
        }];
      },
      layout: {
        height: sizeChart('hnp-year-chart', 340),
        margin: { l: 60, r: 24, t: 12, b: 56 },
        xaxis: { title: { text: 'Year' }, type: 'category' },
        yaxis: { title: { text: 'Papers' }, tickformat: ',d' }
      }
    });
  }

  function monthChart() {
    var data = readJson('hnp-month-data');
    if (!data || !data.years || !data.years.length) { return; }
    if (!window.MGDB || !window.MGDB.chart) { return; }

    var months = ['January', 'February', 'March', 'April', 'May', 'June',
                  'July', 'August', 'September', 'October', 'November', 'December'];

    window.MGDB.chart({
      target: 'hnp-month-chart',
      traces: function () {
        return [{
          type: 'heatmap',
          x: months,
          y: data.years.map(String),
          z: data.grid,
          colorscale: [[0, '#f2efe7'], [0.25, '#d7e8dc'], [0.5, '#a9d0b8'], [0.75, '#4f8f68'], [1, '#1d5c3d']],
          hovertemplate: '%{x} %{y}<br>%{z} recommendations<extra></extra>',
          colorbar: { title: { text: 'Papers' }, thickness: 12 },
          xgap: 2,
          ygap: 2
        }];
      },
      layout: {
        height: sizeChart('hnp-month-chart', Math.max(360, data.years.length * 26 + 130)),
        margin: { l: 64, r: 24, t: 12, b: 90 },
        xaxis: { type: 'category', tickangle: -40 },
        yaxis: { type: 'category', autorange: 'reversed' }
      }
    });
  }

  /* ======================================================================
     Section tab scrollspy
     ====================================================================== */

  function initScrollspy() {
    var nav = document.querySelector('.mgdb-hnp-page .mgdb-section-tabs');
    if (!nav) { return; }

    var links = nav.querySelectorAll('a[href^="#"]');
    if (!links.length) { return; }

    var entries = [];
    Array.prototype.forEach.call(links, function (link) {
      var target = document.getElementById(link.getAttribute('href').slice(1));
      if (target) { entries.push({ link: link, target: target }); }
    });
    if (!entries.length) { return; }

    var pinned = null;
    var pinnedAt = 0;

    function select(entry) {
      entries.forEach(function (e) { e.link.classList.toggle('is-current', e === entry); });
      if (nav.scrollWidth > nav.clientWidth + 2) {
        var barBox = nav.getBoundingClientRect();
        var tabBox = entry.link.getBoundingClientRect();
        if (tabBox.left < barBox.left || tabBox.right > barBox.right) {
          nav.scrollLeft += tabBox.left - barBox.left - 16;
        }
      }
    }

    /* The line has to agree with scroll-margin-top, or clicking a tab marks
       the section above the one it jumped to. */
    function currentLine() {
      var margin = parseFloat(window.getComputedStyle(entries[0].target).scrollMarginTop);
      if (!isFinite(margin)) { margin = 0; }
      return Math.max(nav.getBoundingClientRect().height + 8, margin + 4);
    }

    function update() {
      if (pinned) {
        if (Math.abs(window.scrollY - pinnedAt) < 24) { return; }
        pinned = null;
      }

      var line = currentLine();
      var current = entries[0];
      entries.forEach(function (entry) {
        if (entry.target.getBoundingClientRect().top <= line) { current = entry; }
      });

      var doc = document.documentElement;
      if (window.innerHeight + window.scrollY >= doc.scrollHeight - 4) {
        current = entries[entries.length - 1];
      }

      select(current);
    }

    entries.forEach(function (entry) {
      entry.link.addEventListener('click', function () {
        select(entry);
        pinned = entry;
        pinnedAt = window.scrollY;
        window.setTimeout(function () { pinnedAt = window.scrollY; }, 700);
      });
    });

    var scheduled = false;
    function schedule() {
      if (scheduled) { return; }
      scheduled = true;
      window.setTimeout(function () { scheduled = false; update(); }, 100);
    }

    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', schedule);

    if (window.IntersectionObserver) {
      var observer = new window.IntersectionObserver(schedule, { threshold: 0, rootMargin: '0px 0px -60% 0px' });
      entries.forEach(function (entry) { observer.observe(entry.target); });
    }

    /* A search replaces the whole list, which moves every section below it. */
    if (window.MutationObserver) {
      var results = byId('hnp-results');
      if (results) { new window.MutationObserver(schedule).observe(results, { childList: true }); }
    }

    update();
  }

  /* ====================================================================== */

  function init() {
    initFilters();
    initBoard();
    yearChart();
    monthChart();
    initScrollspy();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
