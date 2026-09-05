/* TYPSimSelector — /TYPSimSelector
   --------------------------------------------------------------------------
   Three decisions, one request, one ranked table.

   The accession pickers are static files built by
   tools/typsimselector_index.php: 3,679 entries for the curation dataset and
   2,831 for the breeding dataset. They are fetched once, lazily, when a reader
   chooses a dataset, and filtered in memory from then on — so typing costs
   nothing and the page itself loads without them. The page it replaces shipped
   all four lists as <option> elements in every response, about 13,000 of them.

   Nothing here runs before the document is ready. Bauplan::includeScript emits
   into <head>, so at module scope the body does not exist yet. */

(function () {
  'use strict';

  var esc = window.MGDB ? window.MGDB.escapeHtml : function (v) { return String(v == null ? '' : v); };
  var PAGE_SIZE = 50;
  var MAX_OPTIONS = 60;

  /* Every score in both matrices sits between 0.75 and 1. Drawing a bar from
     zero would fill it nine tenths of the way for every row in the table and
     distinguish nothing, so the bars are scaled across that range instead. */
  var BAR_FLOOR = 0.75;

  var state = {
    dataset: '',
    lines: {},          /* dataset -> array of picker entries */
    loading: {},        /* dataset -> Promise */
    line: null,         /* the chosen picker entry */
    run: '',            /* which genotyping run of it, curation only */
    compare: null,
    scope: 'all',
    sort: 'desc',
    page: 1,
    lastResponse: null
  };

  var el = {};

  function byId(id) { return document.getElementById(id); }
  function number(value) { return Number(value || 0).toLocaleString(); }

  /* PLINK reports six decimals. Four is enough to separate rows that a reader
     would act on, and the exports carry the full value. */
  function score(value) {
    return Number(value).toFixed(4);
  }

  function announce(message) {
    if (el.status) { el.status.textContent = message; }
  }

  /* ------------------------------------------------------------------------
     The picker files
     ------------------------------------------------------------------------ */

  function loadLines(dataset) {
    if (state.lines[dataset]) { return Promise.resolve(state.lines[dataset]); }
    if (state.loading[dataset]) { return state.loading[dataset]; }

    var url = el.main.getAttribute(dataset === 'curation' ? 'data-lines-curation' : 'data-lines-breeding');
    var request = window.fetch(url, { credentials: 'same-origin' })
      .then(function (response) {
        if (!response.ok) { throw new Error('status ' + response.status); }
        return response.json();
      })
      .then(function (payload) {
        var lines = (payload && payload.lines) || [];
        lines.forEach(function (entry) {
          /* One lowercased haystack per entry, built once. Matching on the
             accession as well as the name is the point: a reader holding a
             seed packet has "PI 601558", not "11430". */
          entry.hay = (entry.n + ' ' + (entry.a || '')).toLowerCase();
        });
        state.lines[dataset] = lines;
        delete state.loading[dataset];
        return lines;
      })
      .catch(function (error) {
        delete state.loading[dataset];
        throw error;
      });

    state.loading[dataset] = request;
    return request;
  }

  /* ------------------------------------------------------------------------
     Combobox

     A text input over an in-memory list. Keyboard handling is the full ARIA
     pattern — arrows move, Enter chooses, Escape closes — because a select
     element is what this replaces and a select is fully keyboard operable.
     ------------------------------------------------------------------------ */

  function Combobox(root, onChoose) {
    this.root = root;
    this.input = root.querySelector('.typ-combobox-input');
    this.list = root.querySelector('.typ-combobox-list');
    this.status = root.querySelector('.typ-combobox-status');
    this.onChoose = onChoose;
    this.matches = [];
    this.active = -1;
    this.chosen = null;

    var self = this;

    this.input.addEventListener('input', function () {
      self.chosen = null;
      self.root.classList.remove('is-chosen');
      self.filter(self.input.value);
    });

    this.input.addEventListener('focus', function () {
      if (!self.chosen) { self.filter(self.input.value); }
    });

    this.input.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        if (self.list.hidden) { self.filter(self.input.value); return; }
        self.move(event.key === 'ArrowDown' ? 1 : -1);
      } else if (event.key === 'Enter') {
        if (!self.list.hidden && self.active >= 0) {
          event.preventDefault();
          self.choose(self.matches[self.active]);
        }
      } else if (event.key === 'Escape') {
        self.close();
      }
    });

    this.list.addEventListener('mousedown', function (event) {
      /* mousedown, not click: the input's blur would close the list first. */
      var option = event.target.closest('.typ-combobox-option');
      if (!option) { return; }
      event.preventDefault();
      self.choose(self.matches[Number(option.getAttribute('data-index'))]);
    });

    document.addEventListener('click', function (event) {
      if (!self.root.contains(event.target)) { self.close(); }
    });
  }

  Combobox.prototype.source = function () {
    return state.lines[state.dataset] || [];
  };

  Combobox.prototype.filter = function (query) {
    var needle = String(query || '').trim().toLowerCase();
    var source = this.source();
    var matches = [];

    if (!source.length) {
      this.close();
      return;
    }

    /* Ranked, not merely filtered. Someone typing "B73" wants B73 — not
       B73HT, and not the lines whose identifier happens to contain it further
       along. Three buckets: the line name matched exactly, the entry begins
       with what was typed, the entry contains it anywhere. Inside the prefix
       bucket the shortest line name wins, which is what puts B73 above B73HT
       above B73Htrhm. */
    var exact = [];
    var prefix = [];
    var contains = [];
    for (var i = 0; i < source.length; i++) {
      if (!needle) {
        prefix.push(source[i]);
        if (prefix.length >= MAX_OPTIONS) { break; }
        continue;
      }
      var at = source[i].hay.indexOf(needle);
      if (at < 0) { continue; }
      if (source[i].l.toLowerCase() === needle) { exact.push(source[i]); }
      else if (at === 0) { prefix.push(source[i]); }
      else { contains.push(source[i]); }
    }

    if (needle) {
      prefix.sort(function (a, b) {
        return a.l.length - b.l.length || a.n.localeCompare(b.n);
      });
    }
    matches = exact.concat(prefix, contains).slice(0, MAX_OPTIONS);

    this.matches = matches;
    this.active = matches.length ? 0 : -1;
    this.render(needle);
  };

  Combobox.prototype.render = function (needle) {
    if (!this.matches.length) {
      this.list.innerHTML = '';
      this.close();
      this.setStatus(needle ? 'No line matches that.' : '');
      return;
    }

    var html = '';
    for (var i = 0; i < this.matches.length; i++) {
      var entry = this.matches[i];
      /* The full identifier, not the accession number, because 340 pairs of
         curation entries share a line name and an accession and differ only
         further along the identifier — showing the accession alone would offer
         a reader three rows they cannot tell apart. Every taxa string in the
         picker is unique, and each one already contains its own accession, so
         this both disambiguates and loses nothing. Typing the spaced form,
         "PI 550473", still matches: the haystack carries both. */
      var detail = entry.n;
      html += '<li class="typ-combobox-option" role="option" id="' + this.list.id + '-opt' + i + '"' +
              ' data-index="' + i + '" aria-selected="' + (i === this.active ? 'true' : 'false') + '">' +
              '<strong>' + highlight(entry.l, needle) + '</strong>' +
              '<span>' + esc(detail) + (entry.r && entry.r.length > 1 ? ' &middot; ' + entry.r.length + ' runs' : '') + '</span>' +
              '</li>';
    }
    this.list.innerHTML = html;
    this.list.hidden = false;
    this.input.setAttribute('aria-expanded', 'true');
    this.syncActive();
    this.setStatus(this.matches.length >= MAX_OPTIONS
      ? 'Showing the first ' + MAX_OPTIONS + ' matches. Keep typing to narrow them.'
      : this.matches.length + (this.matches.length === 1 ? ' match' : ' matches'));
  };

  Combobox.prototype.move = function (step) {
    if (!this.matches.length) { return; }
    this.active = (this.active + step + this.matches.length) % this.matches.length;
    this.syncActive();
  };

  Combobox.prototype.syncActive = function () {
    var options = this.list.querySelectorAll('.typ-combobox-option');
    for (var i = 0; i < options.length; i++) {
      options[i].setAttribute('aria-selected', i === this.active ? 'true' : 'false');
    }
    if (this.active >= 0 && options[this.active]) {
      this.input.setAttribute('aria-activedescendant', options[this.active].id);
      var option = options[this.active];
      var top = option.offsetTop;
      var bottom = top + option.offsetHeight;
      if (top < this.list.scrollTop) { this.list.scrollTop = top; }
      else if (bottom > this.list.scrollTop + this.list.clientHeight) {
        this.list.scrollTop = bottom - this.list.clientHeight;
      }
    } else {
      this.input.removeAttribute('aria-activedescendant');
    }
  };

  Combobox.prototype.choose = function (entry) {
    if (!entry) { return; }
    this.chosen = entry;
    this.input.value = entry.n;
    this.root.classList.add('is-chosen');
    this.close();
    this.setStatus(entry.a ? entry.l + ', accession ' + entry.a : entry.l);
    if (this.onChoose) { this.onChoose(entry); }
  };

  Combobox.prototype.close = function () {
    this.list.hidden = true;
    this.input.setAttribute('aria-expanded', 'false');
    this.input.removeAttribute('aria-activedescendant');
  };

  Combobox.prototype.clear = function () {
    this.chosen = null;
    this.input.value = '';
    this.root.classList.remove('is-chosen');
    this.setStatus('');
    this.close();
  };

  Combobox.prototype.setStatus = function (message) {
    if (this.status) { this.status.textContent = message; }
  };

  function highlight(text, needle) {
    var safe = esc(text);
    if (!needle) { return safe; }
    var at = safe.toLowerCase().indexOf(needle);
    if (at < 0) { return safe; }
    return safe.slice(0, at) + '<mark>' + safe.slice(at, at + needle.length) + '</mark>' + safe.slice(at + needle.length);
  }

  /* ------------------------------------------------------------------------
     Wiring
     ------------------------------------------------------------------------ */

  function chooseDataset(dataset) {
    if (state.dataset === dataset) { return; }
    state.dataset = dataset;
    state.line = null;
    state.compare = null;
    state.run = '';
    state.page = 1;

    Array.prototype.forEach.call(el.datasetCards, function (card) {
      var input = card.querySelector('input');
      card.classList.toggle('is-selected', input.value === dataset);
    });

    el.lineBox.clear();
    el.compareBox.clear();
    el.runField.hidden = true;
    el.stepLine.disabled = true;
    el.stepScope.disabled = true;
    el.results.innerHTML = '';

    el.lineBox.input.disabled = true;
    el.lineBox.input.placeholder = 'Loading the accession list…';
    el.lineBox.setStatus('Loading the accession list…');

    loadLines(dataset).then(function (lines) {
      if (state.dataset !== dataset) { return; }
      el.stepLine.disabled = false;
      el.lineBox.input.disabled = false;
      el.lineBox.input.placeholder = dataset === 'curation'
        ? 'e.g. B73, or PI 550473'
        : 'e.g. B73, or Mo17';
      el.lineBox.setStatus(number(lines.length) + ' lines available. Start typing.');
      announce(number(lines.length) + ' lines loaded.');
    }).catch(function () {
      if (state.dataset !== dataset) { return; }
      el.lineBox.input.placeholder = '';
      el.lineBox.setStatus('The accession list could not be loaded. Reload the page to try again.');
    });
  }

  function chooseLine(entry) {
    state.line = entry;
    state.page = 1;
    el.stepScope.disabled = false;

    /* An accession genotyped more than once has one row per run in the matrix,
       and the runs do not agree exactly. Which one is being ranked has to be
       the reader's choice, not the first id in the list. */
    if (entry.r && entry.r.length > 1) {
      var options = '';
      for (var i = 0; i < entry.r.length; i++) {
        options += '<option value="' + esc(entry.r[i]) + '">Run ' + (i + 1) + ' of ' + entry.r.length +
                   ' — entry ' + esc(entry.r[i]) + '</option>';
      }
      el.run.innerHTML = options;
      el.runField.hidden = false;
      state.run = String(entry.r[0]);
    } else {
      el.runField.hidden = true;
      state.run = entry.r && entry.r.length ? String(entry.r[0]) : entry.n;
    }
  }

  function referenceId() {
    if (!state.line) { return ''; }
    if (state.dataset === 'breeding') { return state.line.n; }
    return state.run || (state.line.r && state.line.r.length ? String(state.line.r[0]) : '');
  }

  function compareId() {
    if (state.scope !== 'one' || !state.compare) { return ''; }
    if (state.dataset === 'breeding') { return state.compare.n; }
    return state.compare.r && state.compare.r.length ? String(state.compare.r[0]) : '';
  }

  function buildQuery(extra) {
    var params = new window.URLSearchParams();
    params.set('dataset', state.dataset);
    params.set('line', referenceId());
    var compare = compareId();
    if (compare) { params.set('compare', compare); }
    params.set('sort', state.sort);
    Object.keys(extra || {}).forEach(function (key) { params.set(key, extra[key]); });
    return params;
  }

  function run() {
    if (!state.dataset || !state.line) { return; }
    if (state.scope === 'one' && !state.compare) {
      el.compareBox.setStatus('Choose a second line, or switch back to the whole dataset.');
      el.compareBox.input.focus();
      return;
    }

    var params = buildQuery({ page: state.page, page_size: PAGE_SIZE });
    if (state.page === 1) { params.set('stats', '1'); }

    el.results.innerHTML = '<div class="mgdb-loading"><span class="mgdb-spinner" aria-hidden="true"></span> Ranking the dataset…</div>';
    announce('Ranking the dataset.');

    window.MGDB.request(el.main.getAttribute('data-api') + '?' + params.toString(), { key: 'typsim' })
      .then(function (payload) {
        if (!payload || !payload.ok) { throw new Error('bad payload'); }
        if (state.page === 1 || !state.lastResponse) { state.lastResponse = payload; }
        else { payload.distribution = state.lastResponse.distribution; }
        render(payload);
      })
      .catch(function (error) {
        if (error && error.name === 'AbortError') { return; }
        el.results.innerHTML = '<div class="mgdb-message mgdb-message-error" role="alert">' +
          '<div><strong>The ranking could not be completed.</strong> ' +
          'The similarity data may be temporarily unavailable. Try again in a moment.</div></div>';
        announce('The ranking could not be completed.');
      });
  }

  /* ------------------------------------------------------------------------
     Rendering
     ------------------------------------------------------------------------ */

  function render(payload) {
    var query = payload.query;
    var summary = payload.summary;
    var html = '';

    var referenceLabel = query.line.accession
      ? esc(query.line.line) + ' <span class="mgdb-muted">— ' + esc(query.line.accession) + '</span>'
      : esc(query.line.line);

    html += '<div class="typ-result-header">' +
              '<div>' +
                '<h3 class="typ-result-title">' + referenceLabel + '</h3>' +
                '<p class="typ-result-subtitle">' + subtitle(payload) + '</p>' +
              '</div>' +
              '<div class="typ-downloads">' +
                '<a class="mgdb-button mgdb-button-secondary" href="' + esc(exportUrl('tsv')) + '">Download TSV</a>' +
                '<a class="mgdb-button mgdb-button-secondary" href="' + esc(exportUrl('csv')) + '">Download CSV</a>' +
              '</div>' +
            '</div>';

    if (state.sort === 'asc') {
      html += '<div class="mgdb-message typ-message-warn" role="note"><div>' +
              '<strong>Reading the bottom of the ranking.</strong> Very low similarity scores are distorted by ' +
              'ascertainment bias in the marker set. Which lines are most dissimilar is not something this ' +
              'ranking can answer authoritatively.</div></div>';
    }

    (payload.notes || []).forEach(function (note) {
      html += '<div class="mgdb-message mgdb-message-info" role="note"><div>' + esc(note) + '</div></div>';
    });

    if (payload.distribution) {
      html += distributionMarkup(payload.distribution);
    }

    if (payload.results.length) {
      html += tableMarkup(payload);
      if (summary.page_count > 1) { html += pagerMarkup(summary); }
    } else {
      html += '<div class="mgdb-empty"><p>No similarity score is on file for this comparison.</p></div>';
    }

    el.results.innerHTML = html;

    if (payload.distribution) { drawChart(payload.distribution); }
    wireResults();

    announce(number(summary.total) + ' comparisons, page ' + summary.page + ' of ' + Math.max(1, summary.page_count) + '.');
  }

  function subtitle(payload) {
    var query = payload.query;
    var summary = payload.summary;
    var against = query.compare
      ? 'compared with <strong>' + esc(query.compare.line) + '</strong>'
      : 'ranked against all ' + number(summary.total) + ' entries in the ' + esc(query.dataset) + ' dataset';
    var order = state.sort === 'asc' ? 'least similar first' : 'most similar first';
    return '<code>' + esc(query.line.name) + '</code>, ' + against + ', ' + order + '. ' +
           'Answered in ' + summary.elapsed_ms + ' ms from ' + summary.query_count +
           (summary.query_count === 1 ? ' query.' : ' queries.');
  }

  function exportUrl(format) {
    return el.main.getAttribute('data-api') + '?' + buildQuery({ format: format }).toString();
  }

  function distributionMarkup(distribution) {
    return '<figure class="mgdb-figure">' +
             '<h4>Where this line sits in the panel</h4>' +
             '<p>How the ' + number(distribution.count) + ' similarity scores for this line are distributed. ' +
             'The long right tail is what matters: those are the accessions that share most of their genome ' +
             'with the reference.</p>' +
             '<div class="mgdb-chart typ-chart" id="typ-chart" role="img" ' +
             'aria-label="Histogram of similarity scores between the reference line and every other entry in the dataset">' +
               '<span class="mgdb-chart-fallback">Loading chart…</span>' +
             '</div>' +
             '<dl class="typ-stat-row">' +
               statMarkup('Lowest', distribution.min) +
               statMarkup('Median', distribution.median) +
               statMarkup('Mean', distribution.mean) +
               statMarkup('Highest', distribution.max) +
               '<div class="typ-stat"><dt>Compared with</dt><dd>' + number(distribution.count) + '</dd></div>' +
             '</dl>' +
           '</figure>';
  }

  function statMarkup(label, value) {
    return '<div class="typ-stat"><dt>' + esc(label) + '</dt><dd>' + score(value) + '</dd></div>';
  }

  function tableMarkup(payload) {
    var withAccessions = (payload.query.dataset === 'curation');
    var rows = payload.results;

    var html = '<div class="typ-table-wrap mgdb-table-scroll">' +
               '<table class="mgdb-table">' +
               '<caption>Entries ranked by identity by state against ' + esc(payload.query.line.name) + '</caption>' +
               '<thead><tr>' +
                 '<th scope="col" class="typ-rank-col">Rank</th>' +
                 '<th scope="col">Line</th>' +
                 (withAccessions ? '<th scope="col">Accession</th><th scope="col">Entry</th>' : '') +
                 '<th scope="col" class="typ-score-col">Similarity</th>' +
                 '<th scope="col">Divergence</th>' +
               '</tr></thead><tbody>';

    for (var i = 0; i < rows.length; i++) {
      var row = rows[i];
      var fill = Math.max(0, Math.min(100, ((row.similarity - BAR_FLOOR) / (1 - BAR_FLOOR)) * 100));

      html += '<tr' + (row.is_self ? ' class="is-self"' : '') + '>' +
                '<td class="typ-rank-col">' + number(row.rank) + '</td>' +
                '<td class="typ-name-col">' +
                  '<strong>' + esc(row.line) + (row.is_self ? '<span class="mgdb-pill mgdb-pill-warn typ-self-pill">this entry</span>' : '') + '</strong>' +
                  '<span class="typ-full-name">' + esc(row.name) + '</span>' +
                '</td>';

      if (withAccessions) {
        html += '<td>' + accessionCell(row) + '</td>' +
                '<td class="typ-rank-col">' + esc(row.id) + '</td>';
      }

      html += '<td class="typ-score-col">' +
                '<span class="typ-score">' + score(row.similarity) + '</span>' +
                '<span class="typ-score-bar" aria-hidden="true"><i style="width:' + fill.toFixed(1) + '%"></i></span>' +
              '</td>' +
              '<td>' + score(row.divergence) + '</td>' +
            '</tr>';
    }

    return html + '</tbody></table></div>';
  }

  /* 829 of the 3,679 curation accessions carry the NCRPIS "TEMP" placeholder
     rather than a real accession number. The page they replace linked those to
     GRIN anyway — every one of them to the same unrelated record — and, because
     the accession variables were never reset between rows, a row with no
     inventory match silently displayed the previous row's accession. Absent is
     said here, not guessed. */
  function accessionCell(row) {
    if (!row.accession) {
      return '<span class="typ-no-accession">Not assigned</span>';
    }
    var html = row.grin_id
      ? '<a href="https://npgsweb.ars-grin.gov/gringlobal/accessiondetail.aspx?id=' + encodeURIComponent(row.grin_id) +
        '" class="mgdb-external">' + esc(row.accession) + '</a>'
      : esc(row.accession);
    return html + '<br /><a class="mgdb-small" href="/data_center/stock?term=' +
           encodeURIComponent(row.accession) + '">MaizeGDB stock</a>';
  }

  function pagerMarkup(summary) {
    var first = ((summary.page - 1) * summary.page_size) + 1;
    var last = Math.min(summary.total, summary.page * summary.page_size);
    return '<div class="typ-pager">' +
             '<p class="mgdb-pagination-status">Showing ' + number(first) + '–' + number(last) +
             ' of ' + number(summary.total) + '</p>' +
             '<div class="typ-pager-buttons">' +
               '<button class="mgdb-button mgdb-button-secondary" type="button" data-page="' + (summary.page - 1) + '"' +
                 (summary.page <= 1 ? ' disabled' : '') + '>Previous</button>' +
               '<button class="mgdb-button mgdb-button-secondary" type="button" data-page="' + (summary.page + 1) + '"' +
                 (summary.page >= summary.page_count ? ' disabled' : '') + '>Next</button>' +
             '</div>' +
           '</div>';
  }

  function wireResults() {
    /* mgdb-modern.js makes .mgdb-table-scroll regions keyboard-reachable at
       DOMContentLoaded. This table is injected after that, so it has to be
       done here or the ranking becomes a scrollable region no keyboard user
       can scroll. */
    Array.prototype.forEach.call(el.results.querySelectorAll('.mgdb-table-scroll'), function (region) {
      if (region.scrollWidth <= region.clientWidth) { return; }
      if (!region.hasAttribute('tabindex')) { region.setAttribute('tabindex', '0'); }
      if (!region.hasAttribute('role')) { region.setAttribute('role', 'region'); }
      if (!region.hasAttribute('aria-label')) {
        var caption = region.querySelector('caption');
        region.setAttribute('aria-label', (caption ? caption.textContent.trim() : 'Ranking') + ' (scrollable)');
      }
    });

    Array.prototype.forEach.call(el.results.querySelectorAll('[data-page]'), function (button) {
      button.addEventListener('click', function () {
        state.page = Number(button.getAttribute('data-page'));
        run();
        el.results.scrollIntoView({ behavior: window.MGDB.prefersReducedMotion() ? 'auto' : 'smooth', block: 'start' });
      });
    });
  }

  /* Plotly is 3.6 MB. The chart it draws does not exist until a reader has run
     a comparison, and most of the page — the tool, the method notes, the
     citations — never needs it at all. So it is fetched on first use rather
     than blocking every page view, and the figure states plainly if it never
     arrives. */
  var plotlyPromise = null;

  function loadPlotly() {
    if (window.Plotly) { return Promise.resolve(); }
    if (plotlyPromise) { return plotlyPromise; }

    plotlyPromise = new Promise(function (resolve, reject) {
      var script = document.createElement('script');
      script.src = '/js/lib/plotly/plotly-2.25.2.min.js';
      script.async = true;
      script.onload = resolve;
      script.onerror = reject;
      document.head.appendChild(script);
    });
    return plotlyPromise;
  }

  function drawChart(distribution) {
    var edges = distribution.histogram.edges;
    var counts = distribution.histogram.counts;

    /* The empty low bins carry no information — no line in either dataset
       scores below about 0.76 against anything — so the axis starts at the
       first bin that holds something. */
    var firstUsed = 0;
    while (firstUsed < counts.length - 1 && counts[firstUsed] === 0) { firstUsed++; }

    var centres = [];
    var values = [];
    for (var i = firstUsed; i < counts.length; i++) {
      centres.push((edges[i] + edges[i + 1]) / 2);
      values.push(counts[i]);
    }

    loadPlotly().then(function () { paint(); }).catch(function () {
      var fallback = document.querySelector('#typ-chart .mgdb-chart-fallback');
      if (fallback) {
        fallback.textContent = 'The chart could not be loaded. The five numbers below summarize the same distribution.';
      }
    });

    function paint() {
    window.MGDB.chart({
      target: 'typ-chart',
      traces: [{
        type: 'bar',
        x: centres,
        y: values,
        marker: { color: window.MGDB.CHART_COLORS[1] },
        hovertemplate: 'Similarity %{x:.3f}<br>%{y} entries<extra></extra>'
      }],
      layout: {
        bargap: 0.02,
        xaxis: { title: { text: 'Identity by state' }, tickformat: '.2f' },
        yaxis: { title: { text: 'Entries' } },
        /* Room at the top for the median annotation, which is anchored to the
           top of the plot area and is clipped by the default margin. */
        margin: { l: 60, r: 16, t: 28, b: 48 },
        shapes: [{
          type: 'line', xref: 'x', yref: 'paper',
          x0: distribution.median, x1: distribution.median, y0: 0, y1: 1,
          line: { color: '#501719', width: 2, dash: 'dash' }
        }],
        annotations: [{
          x: distribution.median, y: 1, xref: 'x', yref: 'paper',
          text: 'median ' + score(distribution.median),
          showarrow: false, yanchor: 'bottom', font: { size: 12, color: '#501719' }
        }]
      }
    });
    }
  }

  /* ------------------------------------------------------------------------
     Init
     ------------------------------------------------------------------------ */

  /* ------------------------------------------------------------------------
     Handoff from a stock record

     /data_center/stock/{id} links here as /TYPSimSelector?line=<entry>, where
     <entry> is the genotyping run the stock resolves to in the curation
     matrix. Landing on an empty console after following that link puts the
     reader back at step one, so the parameter picks the dataset, the
     accession and the run, applies the defaults, and runs the ranking.

     The parameter is also accepted as a line name or an accession number, so
     a link someone types or shares by hand resolves the same way.
     ------------------------------------------------------------------------ */

  function findEntry(lines, token) {
    var lower = String(token).toLowerCase();
    var i;

    /* A bare number is a run id. Those live in the r array, not in any name,
       and are what the stock record hands over. */
    if (/^\d+$/.test(token)) {
      var wanted = Number(token);
      for (i = 0; i < lines.length; i++) {
        if (lines[i].r && lines[i].r.indexOf(wanted) !== -1) {
          return { entry: lines[i], run: String(token) };
        }
      }
    }
    for (i = 0; i < lines.length; i++) {
      if (String(lines[i].n).toLowerCase() === lower) { return { entry: lines[i], run: '' }; }
    }
    for (i = 0; i < lines.length; i++) {
      if (String(lines[i].l).toLowerCase() === lower) { return { entry: lines[i], run: '' }; }
    }
    for (i = 0; i < lines.length; i++) {
      if (String(lines[i].a || '').toLowerCase() === lower) { return { entry: lines[i], run: '' }; }
    }
    return null;
  }

  function handoffNotice(message) {
    var box = byId('typ-handoff');
    if (!box) { return; }
    box.innerHTML = '<div>' + message + '</div>';
    box.hidden = false;
  }

  function applyHandoff(dataset, found, token) {
    var input = null;
    Array.prototype.forEach.call(el.datasetCards, function (card) {
      var candidate = card.querySelector('input');
      if (candidate.value === dataset) { input = candidate; }
    });
    if (input) { input.checked = true; }

    chooseDataset(dataset);

    /* loadLines caches its promise per dataset, so this resolves against the
       same fetch chooseDataset just started. Registered second, so it runs
       after the callback that enables the line field. */
    loadLines(dataset).then(function () {
      el.lineBox.choose(found.entry);

      if (found.run && found.entry.r && found.entry.r.length > 1) {
        el.run.value = found.run;
        state.run = found.run;
      }

      state.scope = 'all';
      state.sort = 'desc';
      state.page = 1;
      if (el.scope) { el.scope.value = 'all'; }
      if (el.sort) { el.sort.value = 'desc'; }
      if (el.compareField) { el.compareField.hidden = true; }

      var label = found.entry.a ? found.entry.l + ', accession ' + found.entry.a : found.entry.l;
      handoffNotice('<strong>Opened from a stock record.</strong> Ranking the ' +
        esc(dataset) + ' dataset against <strong>' + esc(label) + '</strong>' +
        (found.run ? ' (entry ' + esc(found.run) + ')' : '') +
        '. Change any step above to run a different comparison.');

      run();
    });
  }

  function readHandoff() {
    if (!window.URLSearchParams) { return false; }
    var params = new window.URLSearchParams(window.location.search);
    var token = (params.get('line') || '').trim();
    if (!token) { return false; }

    var asked = params.get('dataset');
    /* Run ids only exist in the curation matrix, so that is tried first unless
       the link says otherwise. */
    var order = (asked === 'breeding') ? ['breeding', 'curation'] : ['curation', 'breeding'];

    (function attempt(i) {
      if (i >= order.length) {
        handoffNotice('<strong>No match for &ldquo;' + esc(token) + '&rdquo;.</strong> ' +
          'Pick a dataset and reference line below to run a comparison.');
        return;
      }
      var dataset = order[i];
      loadLines(dataset).then(function (lines) {
        var found = findEntry(lines, token);
        if (found) { applyHandoff(dataset, found, token); }
        else { attempt(i + 1); }
      }).catch(function () { attempt(i + 1); });
    })(0);

    return true;
  }

  function init() {
    el.main = byId('typ-main');
    if (!el.main) { return; }

    el.form = byId('typ-form');
    el.status = byId('typ-status');
    el.results = byId('typ-results');
    el.stepLine = byId('typ-step-line');
    el.stepScope = byId('typ-step-scope');
    el.runField = byId('typ-run-field');
    el.run = byId('typ-run');
    el.scope = byId('typ-scope');
    el.sort = byId('typ-sort');
    el.compareField = byId('typ-compare-field');
    el.datasetCards = el.main.querySelectorAll('.typ-dataset-card');

    el.lineBox = new Combobox(el.main.querySelector('[data-combobox="line"]'), chooseLine);
    el.compareBox = new Combobox(el.main.querySelector('[data-combobox="compare"]'), function (entry) {
      state.compare = entry;
      state.page = 1;
      /* A pairwise comparison resolves to one run, and which one is not
         something the reader chose. Say so rather than let the single score
         look like the accession's only score. */
      if (entry.r && entry.r.length > 1) {
        el.compareBox.setStatus(entry.n + ' — genotyped ' + entry.r.length +
          ' times; comparing against the first run, entry ' + entry.r[0] + '.');
      }
    });

    Array.prototype.forEach.call(el.datasetCards, function (card) {
      card.querySelector('input').addEventListener('change', function (event) {
        chooseDataset(event.target.value);
      });
    });

    el.run.addEventListener('change', function () {
      state.run = el.run.value;
      state.page = 1;
    });

    el.scope.addEventListener('change', function () {
      state.scope = el.scope.value;
      state.page = 1;
      el.compareField.hidden = (state.scope !== 'one');
      if (state.scope !== 'one') {
        state.compare = null;
        el.compareBox.clear();
      }
    });

    el.sort.addEventListener('change', function () {
      state.sort = el.sort.value;
      state.page = 1;
      if (state.line) { run(); }
    });

    el.form.addEventListener('submit', function (event) {
      event.preventDefault();
      state.page = 1;
      run();
    });

    readHandoff();

    el.form.addEventListener('reset', function () {
      window.setTimeout(function () {
        state.dataset = '';
        state.line = null;
        state.compare = null;
        state.run = '';
        state.scope = 'all';
        state.sort = 'desc';
        state.page = 1;
        state.lastResponse = null;
        Array.prototype.forEach.call(el.datasetCards, function (card) { card.classList.remove('is-selected'); });
        el.lineBox.clear();
        el.compareBox.clear();
        el.lineBox.input.disabled = true;
        el.lineBox.input.placeholder = 'Choose a dataset first';
        el.stepLine.disabled = true;
        el.stepScope.disabled = true;
        el.runField.hidden = true;
        el.compareField.hidden = true;
        el.results.innerHTML = '';
        announce('Cleared.');
      }, 0);
    });

    initScrollSpy();
  }

  function initScrollSpy() {
    var tabs = document.querySelectorAll('.mgdb-section-tabs a');
    if (!tabs.length || !('IntersectionObserver' in window)) return;

    var sectionIds = [];
    tabs.forEach(function (tab) {
      var href = tab.getAttribute('href');
      if (href && href.charAt(0) === '#') {
        sectionIds.push(href.substring(1));
      }
    });

    var sections = sectionIds.map(function (id) {
      return document.getElementById(id);
    }).filter(Boolean);

    if (!sections.length) return;

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          var id = entry.target.id;
          tabs.forEach(function (tab) {
            if (tab.getAttribute('href') === '#' + id) {
              tab.classList.add('is-current');
              tab.setAttribute('aria-current', 'true');
            } else {
              tab.classList.remove('is-current');
              tab.removeAttribute('aria-current');
            }
          });
        }
      });
    }, {
      rootMargin: '-10% 0px -75% 0px',
      threshold: 0
    });

    sections.forEach(function (sec) {
      observer.observe(sec);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
