/* ==========================================================================
   /uniformmu — insertion lookup, coverage charts, and figure zoom
   --------------------------------------------------------------------------
   Three independent jobs, deliberately not coupled:

     the lookup   asks search/uniformmu/uniformmu_search_api.php and renders
                  what comes back
     the charts   read data/uniformmu/uniformmu_summary.json, the same file the
                  controller already rendered into tables server-side
     figure zoom  opens a methods figure full size, over the page

   If this file fails to load, or Plotly is missing, or the payload fetch
   fails, the page keeps every number it shows: each chart sits beside a table
   carrying the same values, rendered server-side. Figures stay ordinary links
   to the image. The lookup is the only thing that genuinely needs scripting,
   and the page says so in a <noscript>.

   Nothing here touches the DOM before DOMContentLoaded. Bauplan emits every
   includeScript() into <head>, so a top-level document.querySelector runs
   while <main> does not yet exist and returns null — which is exactly how the
   first version of this file silently did nothing at all.

   Depends on MGDB from /js/mgdb-modern.js.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  if (!MGDB) { return; }

  var API = '/search/uniformmu/uniformmu_search_api.php';
  var MODES = ['gene', 'insertion', 'stock', 'region'];
  var MAX_SPAN = 20000000;

  var escape = MGDB.escapeHtml;

  /* Resolved in init(), never before. */
  var page = null;
  var results = null;
  var idle = null;
  var chips = [];
  var panels = {};
  var currentMode = 'gene';

  function byId(id) { return document.getElementById(id); }

  function num(value) {
    if (value === null || value === undefined || value === '') { return ''; }
    return Number(value).toLocaleString();
  }

  /* ------------------------------------------------------------------------
     Mode switching
     ------------------------------------------------------------------------ */

  function showMode(mode, focusField) {
    if (MODES.indexOf(mode) === -1) { mode = 'gene'; }
    currentMode = mode;

    chips.forEach(function (chip) {
      chip.setAttribute('aria-pressed', chip.getAttribute('data-mode') === mode ? 'true' : 'false');
    });
    MODES.forEach(function (name) {
      if (panels[name]) { panels[name].hidden = (name !== mode); }
    });

    if (focusField && panels[mode]) {
      var field = panels[mode].querySelector('input, select');
      if (field) { field.focus(); }
    }
  }

  /* ------------------------------------------------------------------------
     URL state

     The mode and the search are mirrored into the query string, so a result
     can be linked to and the back button behaves. Read on load, which is also
     what makes /uniformmu?mode=gene&term=lg1 work as an entry point from
     elsewhere on the site.
     ------------------------------------------------------------------------ */

  function readUrl() {
    if (!window.URLSearchParams) { return null; }
    var params = new window.URLSearchParams(window.location.search);
    if (!params.has('mode') && !params.has('term')) { return null; }
    var state = { mode: params.get('mode') || 'gene', term: params.get('term') || '' };
    ['assembly', 'chr', 'start', 'end'].forEach(function (key) {
      if (params.has(key)) { state[key] = params.get(key); }
    });
    return state;
  }

  function writeUrl(query) {
    if (!window.history || !window.history.replaceState || !window.URLSearchParams) { return; }
    var params = new window.URLSearchParams();
    params.set('mode', currentMode);
    Object.keys(query).forEach(function (key) {
      if (query[key] !== '' && query[key] !== null && query[key] !== undefined) {
        params.set(key, query[key]);
      }
    });
    window.history.replaceState(null, '',
      window.location.pathname + '?' + params.toString() + '#um-find');
  }

  /* ------------------------------------------------------------------------
     Rendering
     ------------------------------------------------------------------------ */

  function message(kind, title, detail) {
    return '<div class="mgdb-message mgdb-message-' + kind + '" role="' +
      (kind === 'error' ? 'alert' : 'status') + '"><div><strong>' + escape(title) + '</strong> ' +
      escape(detail || '') + '</div></div>';
  }

  function link(href, text, extra) {
    return '<a href="' + escape(href) + '"' + (extra || '') + '>' + escape(text) + '</a>';
  }

  function refList(refs, emptyText) {
    if (!refs || !refs.length) { return '<span class="um-none">' + escape(emptyText) + '</span>'; }
    return '<span class="um-links">' + refs.map(function (ref) {
      var html = link(ref.url, ref.name);
      if (ref.order_url) {
        html += ' ' + link(ref.order_url, 'order', ' class="mgdb-small"');
      } else if (ref.available === false) {
        html += ' <span class="mgdb-pill mgdb-pill-warn">not available</span>';
      }
      return html;
    }).join(' ') + '</span>';
  }

  /* One row per insertion. Its position is listed once per assembly rather
     than once per gene-transcript-structure combination — mu1013469 has
     fifteen of those and four of these. */
  function placeCell(alignments) {
    if (!alignments || !alignments.length) {
      return '<span class="um-none">no coordinates on file</span>';
    }
    return alignments.map(function (place) {
      var where = place.chromosome
        ? escape(place.chromosome) + ':' + num(place.start)
        : 'position not recorded';
      var gene = place.gene ? ' in ' + link(place.gene_url, place.gene) : '';
      return '<span class="um-place"><span class="um-place-assembly">' +
        escape(place.assembly_label || 'unknown') + '</span> <span class="um-id">' +
        where + '</span>' + gene + '</span>';
    }).join('');
  }

  function structureCell(alignments) {
    if (!alignments || !alignments.length) { return '<span class="um-none">&mdash;</span>'; }
    var seen = {};
    var parts = [];
    alignments.forEach(function (place) {
      (place.structures || '').split(', ').forEach(function (structure) {
        var name = structure.trim();
        if (name && !seen[name]) { seen[name] = true; parts.push(name); }
      });
    });
    return parts.length ? escape(parts.join(', ')) : '<span class="um-none">&mdash;</span>';
  }

  function subjectCard(subject, summary) {
    if (!subject) { return ''; }

    var heading = escape(subject.name || '');
    var lines = [];
    var actions = [];

    if (subject.kind === 'gene') {
      if (subject.symbol) {
        lines.push('Gene symbol <strong>' + escape(subject.symbol) + '</strong>' +
          (subject.full_name ? ' &mdash; ' + escape(subject.full_name) : ''));
      }
      if (subject.chromosome) {
        lines.push('<span class="um-id">' + escape(subject.chromosome) + ':' +
          num(subject.start) + '&ndash;' + num(subject.end) + '</span> on ' +
          escape(subject.assembly || ''));
      }
      if (subject.searched_names && subject.searched_names.length > 1) {
        lines.push('Also searched as ' + subject.searched_names.slice(1).map(escape).join(', ') +
          ', so insertions aligned to earlier annotations of this gene are included.');
      }
      if (subject.url) { actions.push(link(subject.url, 'Gene page', ' class="mgdb-button mgdb-button-secondary"')); }
    } else if (subject.kind === 'stock') {
      lines.push(subject.provider ? 'Held by ' + escape(subject.provider) : '');
      if (subject.comments) { lines.push('Stock note: <strong>' + escape(subject.comments) + '</strong>'); }
      if (subject.status !== 'available') {
        lines.push('<span class="mgdb-pill mgdb-pill-warn">Not currently available</span>');
      }
      if (subject.url) { actions.push(link(subject.url, 'Stock record', ' class="mgdb-button mgdb-button-secondary"')); }
      if (subject.order_url) { actions.push(link(subject.order_url, 'Order this stock', ' class="mgdb-button mgdb-button-primary"')); }
    } else if (subject.kind === 'insertion') {
      if (subject.status === 'withdrawn') {
        lines.push('<span class="mgdb-pill mgdb-pill-warn">Withdrawn record</span>');
      }
      if (subject.url) { actions.push(link(subject.url, 'Insertion record', ' class="mgdb-button mgdb-button-secondary"')); }
    } else if (subject.kind === 'region') {
      lines.push(escape(subject.assembly_label || subject.assembly || ''));
    }

    var count = summary.total === 1 ? '1 insertion' : num(summary.total) + ' insertions';
    var stocked = summary.with_stock === summary.total
      ? 'every one has seed on file'
      : num(summary.with_stock) + ' with seed on file';
    lines.push('<strong>' + count + '</strong>, ' + stocked + '.');

    return '<div class="um-subject"><div><h3>' + heading + '</h3>' +
      lines.filter(Boolean).map(function (line) { return '<p>' + line + '</p>'; }).join('') +
      '</div>' +
      (actions.length ? '<div class="um-subject-actions">' + actions.join('') + '</div>' : '') +
      '</div>';
  }

  function resultsTable(rows) {
    var body = rows.map(function (row) {
      return '<tr>' +
        '<th scope="row">' + link(row.url, row.name || ('#' + row.id), ' class="um-id"') +
        (row.status === 'withdrawn' ? ' <span class="mgdb-pill mgdb-pill-warn">withdrawn</span>' : '') +
        '</th>' +
        '<td>' + placeCell(row.alignments) + '</td>' +
        '<td>' + structureCell(row.alignments) + '</td>' +
        '<td>' + refList(row.variations, 'no variation record') + '</td>' +
        '<td>' + refList(row.stocks, 'no seed on file') + '</td>' +
        '</tr>';
    }).join('');

    return '<div class="mgdb-table-scroll"><table class="mgdb-table" data-sortable>' +
      '<caption>Matching UniformMu insertions' +
      '<span class="mgdb-muted">Positions are listed once per assembly. ' +
      'An insertion touching several transcripts is still one row.</span></caption>' +
      '<thead><tr>' +
      '<th scope="col" data-sort="text"><button type="button">Insertion</button></th>' +
      '<th scope="col">Position</th>' +
      '<th scope="col" data-sort="text"><button type="button">Disrupts</button></th>' +
      '<th scope="col">Variation</th>' +
      '<th scope="col">Seed stock</th>' +
      '</tr></thead><tbody>' + body + '</tbody></table></div>';
  }

  function render(payload) {
    var html = '';

    (payload.notes || []).forEach(function (note) {
      var kind = (note.code === 'truncated' || note.code === 'stock_unmapped' ||
                  note.code === 'no_coordinates' || note.code === 'gene_withdrawn')
                 ? 'info' : 'error';
      var title = note.code === 'truncated' ? 'Showing part of the result.' : 'Note.';
      html += message(kind, title, note.detail);
    });

    html += subjectCard(payload.subject, payload.summary);

    if (payload.results && payload.results.length) {
      html += resultsTable(payload.results);
      html += '<p class="mgdb-small mgdb-muted">Answered in ' +
        escape(String(payload.summary.elapsed_ms)) + '&thinsp;ms from ' +
        escape(String(payload.summary.queries)) + ' database ' +
        (payload.summary.queries === 1 ? 'query' : 'queries') + '.</p>';
    } else if (payload.subject) {
      html += '<div class="mgdb-empty"><h3>No UniformMu insertions here</h3>' +
        '<p>This record exists, but no UniformMu insertion is associated with it. ' +
        'Other insertion collections may still have one &mdash; a gene page lists them all.</p></div>';
    } else {
      html += '<div class="mgdb-empty"><h3>Nothing matched</h3>' +
        '<p>Check the identifier and try again, or pick one of the examples above.</p></div>';
    }

    results.innerHTML = html;

    /* The table is built after mgdb-modern.js has already wired the page, so
       its sorting has to be attached here. */
    var table = results.querySelector('table[data-sortable]');
    if (table) { MGDB.sortTable(table); }

    MGDB.announce(payload.results && payload.results.length
      ? num(payload.summary.total) + ' insertions found'
      : 'No insertions found');
  }

  /* ------------------------------------------------------------------------
     Running a lookup
     ------------------------------------------------------------------------ */

  function collect() {
    if (currentMode === 'region') {
      return {
        assembly: (byId('um-assembly') || {}).value || '',
        chr: ((byId('um-chr') || {}).value || '').trim(),
        start: ((byId('um-start') || {}).value || '').replace(/[^0-9]/g, ''),
        end: ((byId('um-end') || {}).value || '').replace(/[^0-9]/g, '')
      };
    }
    var field = byId('um-' + currentMode);
    return { term: field ? field.value.trim() : '' };
  }

  function run() {
    var query = collect();

    if (currentMode === 'region') {
      if (!query.chr || query.start === '' || query.end === '') {
        results.innerHTML = message('error', 'Incomplete region.',
          'Enter a chromosome and both coordinates.');
        return;
      }
      /* The same 20 Mb ceiling the endpoint enforces, checked here so the
         reader gets the reason rather than a failed request. MGDB.request
         rejects on a 4xx without exposing the body, so a server-side
         rejection can only ever produce a generic message. */
      if (Math.abs(Number(query.end) - Number(query.start)) > MAX_SPAN) {
        results.innerHTML = message('error', 'That window is too wide.',
          'Ask for at most 20 Mb at a time. This is the one lookup with no index behind it.');
        return;
      }
    } else if (!query.term) {
      results.innerHTML = message('error', 'Nothing to look up.',
        'Enter an identifier, or pick one of the examples.');
      return;
    }

    if (idle) { idle.hidden = true; }
    results.setAttribute('aria-busy', 'true');
    results.innerHTML = '<div class="mgdb-loading"><span class="mgdb-spinner" aria-hidden="true"></span>' +
      '<span>Searching insertions&hellip;</span></div>';

    var params = ['mode=' + encodeURIComponent(currentMode)];
    Object.keys(query).forEach(function (key) {
      params.push(encodeURIComponent(key) + '=' + encodeURIComponent(query[key]));
    });

    writeUrl(query);

    MGDB.request(API + '?' + params.join('&'), { key: 'uniformmu-lookup' })
      .then(function (payload) {
        results.setAttribute('aria-busy', 'false');
        if (!payload || payload.ok !== true) {
          results.innerHTML = message('error',
            (payload && payload.message) || 'The lookup failed.',
            (payload && payload.detail) || '');
          return;
        }
        render(payload);
      })
      .catch(function (error) {
        // An aborted request is a newer search superseding this one, not a
        // failure; replacing the results with an error would be wrong.
        if (error && error.name === 'AbortError') { return; }
        results.setAttribute('aria-busy', 'false');
        results.innerHTML = message('error', 'The lookup could not be completed.',
          'Check the identifier, or try again in a moment.');
      });
  }

  /* ------------------------------------------------------------------------
     Charts

     Each reads the same payload the server already rendered into a table.
     Values are never recomputed here: a chart and its table disagreeing is a
     bug that is almost impossible to see.
     ------------------------------------------------------------------------ */

  function drawCharts(data) {
    if (!data) { return; }
    var buckets = (data.per_gene && data.per_gene.buckets) || [];
    var assemblies = (data.assemblies || []).filter(function (a) { return a.name; });
    var reference = assemblies.filter(function (a) {
      return a.name === 'Zm-B73-REFERENCE-NAM-5.0';
    })[0] || assemblies[0];

    if (buckets.length) {
      MGDB.chart({
        target: 'um-chart-per-gene',
        traces: [{
          type: 'bar',
          x: buckets.map(function (b) { return b.insertions >= 10 ? '10+' : String(b.insertions); }),
          y: buckets.map(function (b) { return b.genes; }),
          marker: { color: MGDB.CHART_COLORS[1] },
          hovertemplate: '%{y:,} genes with %{x} insertions<extra></extra>'
        }],
        layout: {
          xaxis: { title: { text: 'UniformMu insertions in the gene' }, type: 'category' },
          yaxis: { title: { text: 'Gene models' } }
        }
      });
    }

    /* Structures as a share rather than a count, because the assemblies differ
       in how many alignments they carry and the interesting comparison is
       where the insertions land, not how many there are. */
    if (assemblies.length) {
      var structureNames = [];
      assemblies.forEach(function (assembly) {
        (assembly.structures || []).forEach(function (structure) {
          if (structureNames.indexOf(structure.structure) === -1) {
            structureNames.push(structure.structure);
          }
        });
      });

      MGDB.chart({
        target: 'um-chart-structure',
        traces: structureNames.map(function (name, index) {
          return {
            type: 'bar',
            name: name,
            x: assemblies.map(function (a) { return a.label; }),
            y: assemblies.map(function (assembly) {
              var total = 0;
              var hit = 0;
              (assembly.structures || []).forEach(function (structure) {
                total += structure.alignments;
                if (structure.structure === name) { hit = structure.alignments; }
              });
              return total ? (hit / total) * 100 : 0;
            }),
            marker: { color: MGDB.CHART_COLORS[index % MGDB.CHART_COLORS.length] },
            hovertemplate: '%{x}: %{y:.1f}% of alignments in ' + name + '<extra></extra>'
          };
        }),
        layout: {
          barmode: 'group',
          xaxis: { title: { text: 'Assembly' } },
          yaxis: { title: { text: 'Share of alignments (%)' }, ticksuffix: '%' }
        }
      });
    }

    if (reference && reference.chromosomes && reference.chromosomes.length) {
      /* Sorted by chromosome number, not by count. A chromosome axis that
         reorders itself by value stops being a genome and starts being a
         ranking, and the shape a reader is checking for — a smooth decline
         with length — disappears. */
      var chromosomes = reference.chromosomes.slice().sort(function (a, b) {
        var an = parseInt(String(a.name).replace(/[^0-9]/g, ''), 10);
        var bn = parseInt(String(b.name).replace(/[^0-9]/g, ''), 10);
        return (isNaN(an) ? 99 : an) - (isNaN(bn) ? 99 : bn);
      });

      MGDB.chart({
        target: 'um-chart-chromosome',
        traces: [{
          type: 'bar',
          x: chromosomes.map(function (c) { return c.name; }),
          y: chromosomes.map(function (c) { return c.insertions; }),
          marker: { color: MGDB.CHART_COLORS[3] },
          hovertemplate: '%{x}: %{y:,} insertions<extra></extra>'
        }],
        layout: {
          xaxis: { title: { text: 'Chromosome' }, type: 'category' },
          yaxis: { title: { text: 'Insertions placed' } }
        }
      });
    }
  }

  function chartsUnavailable(reason) {
    Array.prototype.forEach.call(page.querySelectorAll('.mgdb-chart-fallback'), function (node) {
      node.textContent = reason;
    });
  }

  function loadPayload() {
    var url = page.getAttribute('data-payload');
    if (!url) {
      chartsUnavailable('No data file is configured for these charts. The values they show are in the table below.');
      return;
    }
    MGDB.request(url, { key: 'uniformmu-payload' })
      .then(function (data) {
        try {
          drawCharts(data);
        } catch (error) {
          // The tables beside each chart already carry every value, so a
          // failure here costs the figures and nothing else. Swallowing it
          // silently would leave "Loading chart…" on screen forever, which
          // reads as a page still working rather than one that gave up.
          chartsUnavailable('This chart could not be drawn. The values it shows are in the table below.');
        }
      })
      .catch(function () {
        chartsUnavailable('This chart could not be loaded. The values it shows are in the table below.');
      });
  }

  /* ------------------------------------------------------------------------
     Figure zoom

     The methods figures are 2011 scans reproduced at their original pixel
     size, which is small on a modern display. Each is wrapped in a link to
     the image file, so it works without this; the overlay just keeps the
     reader on the page.
     ------------------------------------------------------------------------ */

  var zoom = null;
  var zoomReturnFocus = null;

  function buildZoom() {
    var overlay = document.createElement('div');
    overlay.className = 'um-zoom-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', 'Enlarged figure');
    overlay.hidden = true;
    overlay.innerHTML =
      '<div class="um-zoom-frame">' +
      '<button class="um-zoom-close" type="button">Close<span aria-hidden="true"> ✕</span></button>' +
      '<img class="um-zoom-image" alt="" />' +
      '<p class="um-zoom-caption"></p>' +
      '</div>';
    document.body.appendChild(overlay);

    overlay.addEventListener('click', function (event) {
      // Clicking the backdrop closes; clicking the image itself does not.
      if (event.target === overlay) { closeZoom(); }
    });
    overlay.querySelector('.um-zoom-close').addEventListener('click', closeZoom);

    return {
      overlay: overlay,
      image: overlay.querySelector('.um-zoom-image'),
      caption: overlay.querySelector('.um-zoom-caption'),
      close: overlay.querySelector('.um-zoom-close')
    };
  }

  function openZoom(src, alt, caption, trigger) {
    if (!zoom) { zoom = buildZoom(); }
    zoom.image.setAttribute('src', src);
    zoom.image.setAttribute('alt', alt || '');
    zoom.caption.textContent = caption || '';
    zoom.overlay.hidden = false;
    document.body.classList.add('um-zoom-open');
    zoomReturnFocus = trigger || null;
    zoom.close.focus();
  }

  function closeZoom() {
    if (!zoom || zoom.overlay.hidden) { return; }
    zoom.overlay.hidden = true;
    document.body.classList.remove('um-zoom-open');
    // Focus goes back where it came from, or it lands at the top of the
    // document and the reader loses their place in a long methods section.
    if (zoomReturnFocus && zoomReturnFocus.focus) { zoomReturnFocus.focus(); }
    zoomReturnFocus = null;
  }

  function wireZoom() {
    Array.prototype.forEach.call(page.querySelectorAll('.um-zoom'), function (trigger) {
      trigger.addEventListener('click', function (event) {
        // A modified click is the reader asking for a new tab. Leave it alone.
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ||
            event.button === 1) {
          return;
        }
        var image = trigger.querySelector('img');
        if (!image) { return; }
        event.preventDefault();
        var figure = trigger.closest ? trigger.closest('figure') : null;
        var caption = figure ? figure.querySelector('figcaption') : null;
        openZoom(trigger.getAttribute('href') || image.getAttribute('src'),
          image.getAttribute('alt'), caption ? caption.textContent : '', trigger);
      });
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' || event.key === 'Esc') { closeZoom(); }
    });
  }

  /* ------------------------------------------------------------------------
     Start
     ------------------------------------------------------------------------ */

  function init() {
    page = document.querySelector('.mgdb-uniformmu-page');
    if (!page) { return; }

    results = byId('um-results');
    idle = byId('um-idle');
    chips = Array.prototype.slice.call(page.querySelectorAll('.um-modes [data-mode]'));
    MODES.forEach(function (mode) { panels[mode] = byId('um-panel-' + mode); });

    if (results) {
      chips.forEach(function (chip) {
        chip.addEventListener('click', function () {
          showMode(chip.getAttribute('data-mode'), true);
        });
      });

      MODES.forEach(function (mode) {
        if (!panels[mode]) { return; }
        panels[mode].addEventListener('submit', function (event) {
          event.preventDefault();
          run();
        });
      });

      /* An example fills its field and runs the search. Filling without
         running leaves the reader looking at a populated box wondering
         whether anything happened. */
      Array.prototype.forEach.call(page.querySelectorAll('.um-example'), function (button) {
        button.addEventListener('click', function () {
          var field = byId(button.getAttribute('data-fill'));
          if (!field) { return; }
          field.value = button.getAttribute('data-value') || '';
          run();
        });
      });

      showMode('gene', false);

      var state = readUrl();
      if (state) {
        showMode(state.mode, false);
        if (currentMode === 'region') {
          ['assembly', 'chr', 'start', 'end'].forEach(function (key) {
            var field = byId('um-' + key);
            if (field && state[key]) { field.value = state[key]; }
          });
          run();
        } else {
          var field = byId('um-' + currentMode);
          if (field && state.term) {
            field.value = state.term;
            run();
          }
        }
      }
    }

    wireZoom();
    loadPayload();

    /* The sticky section tabs. `.mgdb-section-tabs` is styled by the hub shell
       but driven per page, and this page shipped without a spy at all: its old
       `.um-section-nav` linked to the sections and never marked the one you
       were in, which its own stylesheet comment admitted. MGDB.sectionTabs is
       that behaviour, shared, so this is the only line the page needs. */
    if (window.MGDB && MGDB.sectionTabs) { MGDB.sectionTabs(); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
