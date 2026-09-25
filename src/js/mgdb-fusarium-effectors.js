/* ==========================================================================
   /fusarium/effectors — the predicted effector table
   --------------------------------------------------------------------------
   The page arrives with its first 50 rows rendered by
   controllers/fusarium/effectors.php, so it reads without this file. This
   loads the whole table -- data/fusarium/effectors.json, 2,301 rows -- and
   takes over: All species, filtering as the reader types, sorting by any
   column, page sizes, and a TSV of exactly the rows on screen after
   filtering.

   Rows are built exactly as fefRow() builds them in the controller; keep the
   two in step, or the table changes shape the moment this file takes over.

   The address follows the view (species, filter, class, signal, page) with
   history.replaceState, so a reload or a shared link shows the same rows.

   Nothing here touches the DOM before DOMContentLoaded.

   Depends on MGDB from /js/mgdb-modern.js.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  if (!MGDB) { return; }

  var escape = MGDB.escapeHtml;
  var ROUTE = '/fusarium';
  var PANEFFECT = 'https://www.maizegdb.org/effect/fusarium/index.php';
  var ASPECTS = { F: 'function', P: 'process', C: 'component' };

  var els = {};
  var state = {
    ready: false,
    rows: [],            /* every row, with its species attached */
    species: 'graminearum',
    query: '',
    klass: '',
    signal: '',
    sort: { key: 'gene', dir: 1 },
    page: 1,
    size: 50
  };

  function fmtInt(value) { return Number(value).toLocaleString('en-US'); }

  function naturalCompare(a, b) {
    return String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });
  }

  /* ----------------------------------------------------------------------
   * Filtering -- the same tests as fefMatches() in the controller
   * ---------------------------------------------------------------------- */

  function haystack(row) {
    if (!row.hay) {
      var parts = [row.gene, row.acc || '', row.model || '', row.description, row.sp.label];
      row.go.forEach(function (go) { parts.push(go.id, go.name); });
      row.ec.forEach(function (ec) { parts.push(ec); });
      row.hay = parts.join(' ').toLowerCase();
    }
    return row.hay;
  }

  function matches(row) {
    if (state.species !== 'all' && row.sp.key !== state.species) { return false; }
    if (state.query && haystack(row).indexOf(state.query.toLowerCase()) === -1) { return false; }
    var apo = row.apoplastic !== null, cyto = row.cytoplasmic !== null;
    if (state.klass === 'apoplastic' && !(apo && !cyto)) { return false; }
    if (state.klass === 'cytoplasmic' && !(cyto && !apo)) { return false; }
    if (state.klass === 'both' && !(apo && cyto)) { return false; }
    if (state.klass === 'none' && (apo || cyto)) { return false; }
    var any = row.chloroplast || row.mitochondria || row.nucleus;
    if (state.signal === 'any' && !any) { return false; }
    if (state.signal === 'none' && any) { return false; }
    if ((state.signal === 'chloroplast' || state.signal === 'mitochondria' || state.signal === 'nucleus') && !row[state.signal]) { return false; }
    return true;
  }

  function signalRank(row) {
    return (row.chloroplast ? 1 + row.chloroplast.p : 0) + (row.mitochondria ? 1 + row.mitochondria.p : 0) + (row.nucleus ? 1 : 0);
  }

  function sorted(list) {
    var key = state.sort.key, dir = state.sort.dir;
    return list.slice().sort(function (a, b) {
      var c = 0;
      if (key === 'gene') { c = a.sp.index - b.sp.index || naturalCompare(a.gene, b.gene); }
      else if (key === 'species') { c = a.sp.index - b.sp.index; }
      else if (key === 'apoplastic' || key === 'cytoplasmic') {
        /* A missing probability sorts last in either direction. */
        var x = a[key], y = b[key];
        if (x === null && y === null) { c = 0; } else if (x === null) { return 1; } else if (y === null) { return -1; } else { c = x - y; }
      } else if (key === 'signal') { c = signalRank(a) - signalRank(b); }
      else if (key === 'description') { c = naturalCompare(a.description, b.description); }
      return c * dir || (a.sp.index - b.sp.index) || naturalCompare(a.gene, b.gene);
    });
  }

  /* ----------------------------------------------------------------------
   * One row -- as fefRow() in the controller
   * ---------------------------------------------------------------------- */

  function probability(value) {
    if (value === null) { return '<td class="mgdb-numeric fef-prob fef-empty" data-value="">&mdash;</td>'; }
    var width = Math.max(4, Math.min(100, Math.round((value - 0.5) / 0.5 * 100)));
    return '<td class="mgdb-numeric fef-prob" data-value="' + value + '">' + value.toFixed(3)
      + '<span class="fef-meter" aria-hidden="true"><span style="width:' + width + '%"></span></span></td>';
  }

  function trimNumber(value) { return String(Number(value.toFixed(3))); }

  function signals(row) {
    var out = [];
    [['chloroplast', 'Chloroplast'], ['mitochondria', 'Mitochondria']].forEach(function (pair) {
      var s = row[pair[0]];
      if (s) {
        out.push('<span class="fef-signal fef-signal-' + pair[0] + '">' + pair[1] + ' <b>' + trimNumber(s.p) + '</b> <span>'
          + s.from + '&ndash;' + s.to + '</span></span>');
      }
    });
    if (row.nucleus) { out.push('<span class="fef-signal fef-signal-nucleus">Nucleus</span>'); }
    return out.length ? out.join('') : '<span class="fef-empty">&mdash;</span>';
  }

  function func(row) {
    var html = '<span class="fef-desc">' + escape(row.description || 'No description') + '</span>';
    if (row.go.length) {
      html += '<details class="fef-go"><summary>' + row.go.length + ' GO term' + (row.go.length === 1 ? '' : 's')
        + '</summary><ul>' + row.go.map(function (go) {
          return '<li><a href="https://www.ebi.ac.uk/QuickGO/term/' + encodeURIComponent(go.id) + '">' + escape(go.id) + '</a> '
            + escape(go.name) + ' <span class="fef-aspect">' + ASPECTS[go.aspect] + '</span></li>';
        }).join('') + '</ul></details>';
    }
    if (row.ec.length) {
      html += '<span class="fef-ec">' + row.ec.map(function (ec) {
        var number = ec.slice(3);
        return number.indexOf('-') === -1
          ? '<a href="https://enzyme.expasy.org/EC/' + encodeURIComponent(number) + '">EC ' + escape(number) + '</a>'
          : 'EC ' + escape(number);
      }).join(', ') + '</span>';
    }
    return html;
  }

  function rowMarkup(row) {
    var links = [];
    if (row.model) {
      var title = row.model_by_gene ? ' title="' + escape('The model is filed under ' + row.model + ', this gene’s other UniProt entry') + '"' : '';
      links.push('<a href="' + ROUTE + '/structures?id=' + encodeURIComponent(row.model) + '"' + title + '>Structure</a>');
    }
    if (row.foldseek) { links.push('<a href="' + ROUTE + '/foldseek?uniprot=' + encodeURIComponent(row.model) + '">Foldseek</a>'); }
    if (row.sp.paneffect && row.model) { links.push('<a href="' + escape(PANEFFECT + '?id=' + encodeURIComponent(row.model)) + '">PanEffect</a>'); }
    links.push('<a href="https://fungidb.org/fungidb/app/record/gene/' + encodeURIComponent(row.gene) + '">FungiDB</a>');

    var acc = row.acc
      ? '<a class="fef-acc" href="https://www.uniprot.org/uniprotkb/' + encodeURIComponent(row.acc) + '/entry">' + escape(row.acc) + '</a>'
        + (row.inferred ? '<span class="fef-flag" title="Not in the workbook; matched by gene id">by gene id</span>' : '')
      : '<span class="fef-acc fef-empty">No UniProt entry</span>';

    return '<tr>'
      + '<th scope="row" class="fef-col-gene"><span class="fef-gene">' + escape(row.gene) + '</span>' + acc + '</th>'
      + '<td class="fef-col-species"><i>' + escape(row.sp.label) + '</i></td>'
      + probability(row.apoplastic)
      + probability(row.cytoplasmic)
      + '<td class="fef-col-signal">' + signals(row) + '</td>'
      + '<td class="fef-col-function">' + func(row) + '</td>'
      + '<td class="fef-col-links">' + links.join('') + '</td>'
      + '</tr>';
  }

  /* ----------------------------------------------------------------------
   * Drawing
   * ---------------------------------------------------------------------- */

  function currentList() { return sorted(state.rows.filter(matches)); }

  function speciesTotal() {
    return state.species === 'all' ? state.rows.length
      : state.rows.filter(function (row) { return row.sp.key === state.species; }).length;
  }

  function speciesLabel() {
    if (state.species === 'all') { return 'all six species'; }
    var row = state.rows.filter(function (r) { return r.sp.key === state.species; })[0];
    return '<i>' + escape(row ? row.sp.label : state.species) + '</i>';
  }

  function render() {
    var list = currentList();
    var size = state.size || list.length || 1;
    var pages = Math.max(1, Math.ceil(list.length / size));
    if (state.page > pages) { state.page = pages; }
    var slice = list.slice((state.page - 1) * size, (state.page - 1) * size + size);

    els.rows.innerHTML = slice.length ? slice.map(rowMarkup).join('')
      : '<tr><td colspan="7" class="fef-none">No effector matches these filters.</td></tr>';
    els.table.classList.toggle('fef-one-species', state.species !== 'all');

    var filtered = state.query || state.klass || state.signal;
    els.count.innerHTML = fmtInt(list.length) + (filtered ? ' of ' + fmtInt(speciesTotal()) : '')
      + ' effector' + (list.length === 1 ? '' : 's') + ' in ' + speciesLabel()
      + (pages > 1 ? ', page ' + state.page + ' of ' + pages : '');
    renderPager(pages);
    els.tsv.disabled = !list.length;

    Array.prototype.forEach.call(els.chips, function (chip) {
      chip.setAttribute('aria-pressed', chip.getAttribute('data-fef-species') === state.species ? 'true' : 'false');
    });
    Array.prototype.forEach.call(els.headers, function (th) {
      var key = th.getAttribute('data-fef-sort');
      th.setAttribute('aria-sort', key === state.sort.key ? (state.sort.dir > 0 ? 'ascending' : 'descending') : 'none');
    });
    writeUrl();
  }

  function renderPager(pages) {
    if (pages < 2) { els.pager.innerHTML = ''; return; }
    var current = state.page;
    var items = [];
    function button(page, label, disabled, aria) {
      return '<button type="button" class="fef-page" data-fef-page="' + page + '"'
        + (disabled ? ' disabled' : '') + (aria ? ' aria-label="' + aria + '"' : '')
        + (page === current && !aria ? ' aria-current="page"' : '') + '>' + label + '</button>';
    }
    items.push(button(current - 1, '&larr; Previous', current <= 1, 'Previous page'));
    var shown = {};
    [1, 2, current - 1, current, current + 1, pages - 1, pages].forEach(function (p) { if (p >= 1 && p <= pages) { shown[p] = true; } });
    var last = 0;
    Object.keys(shown).map(Number).sort(function (a, b) { return a - b; }).forEach(function (p) {
      if (p - last > 1) { items.push('<span class="fef-gap" aria-hidden="true">&hellip;</span>'); }
      items.push(button(p, String(p), false, null));
      last = p;
    });
    items.push(button(current + 1, 'Next &rarr;', current >= pages, 'Next page'));
    els.pager.innerHTML = items.join('');
  }

  function writeUrl() {
    if (!window.history || !window.history.replaceState || !window.URLSearchParams) { return; }
    var params = new window.URLSearchParams();
    params.set('species', state.species);
    if (state.query) { params.set('q', state.query); }
    if (state.klass) { params.set('class', state.klass); }
    if (state.signal) { params.set('signal', state.signal); }
    if (state.page > 1) { params.set('page', String(state.page)); }
    window.history.replaceState(null, '', ROUTE + '/effectors?' + params.toString() + window.location.hash);
  }

  function exportTsv() {
    var columns = ['species', 'gene', 'uniprot', 'uniprot_from_gene_id', 'model_accession', 'effectorp_apoplastic',
      'effectorp_cytoplasmic', 'localizer_chloroplast', 'localizer_chloroplast_range', 'localizer_mitochondria',
      'localizer_mitochondria_range', 'localizer_nucleus', 'description', 'go_terms', 'enzyme_codes'];
    var clean = function (value) { return value === null || value === undefined ? '' : String(value).replace(/[\t\r\n]+/g, ' '); };
    var lines = [columns.join('\t')];
    currentList().forEach(function (row) {
      lines.push([row.sp.latin, row.gene, row.acc, row.inferred ? 'yes' : '', row.model, row.apoplastic, row.cytoplasmic,
        row.chloroplast ? row.chloroplast.p : '', row.chloroplast ? row.chloroplast.from + '-' + row.chloroplast.to : '',
        row.mitochondria ? row.mitochondria.p : '', row.mitochondria ? row.mitochondria.from + '-' + row.mitochondria.to : '',
        row.nucleus ? 'yes' : '', row.description,
        row.go.map(function (go) { return go.aspect + ':' + go.id + ' ' + go.name; }).join('; '),
        row.ec.join('; ')].map(clean).join('\t'));
    });
    var name = 'fusarium_effectors_' + state.species + (state.query || state.klass || state.signal ? '_filtered' : '') + '.tsv';
    var blob = new window.Blob([lines.join('\n') + '\n'], { type: 'text/tab-separated-values' });
    var url = window.URL.createObjectURL(blob);
    var link = document.createElement('a');
    link.href = url;
    link.download = name;
    document.body.appendChild(link);
    link.click();
    window.setTimeout(function () { window.URL.revokeObjectURL(url); link.parentNode.removeChild(link); }, 0);
  }

  /* ----------------------------------------------------------------------
   * Init
   * ---------------------------------------------------------------------- */

  function readControls() {
    state.query = els.q.value.trim();
    state.klass = els.klass.value;
    state.signal = els.signal.value;
  }

  function bind() {
    var refilter = MGDB.debounce(function () { readControls(); state.page = 1; render(); }, 150);
    els.q.addEventListener('input', refilter);
    els.klass.addEventListener('change', refilter);
    els.signal.addEventListener('change', refilter);
    els.form.addEventListener('submit', function (event) {
      event.preventDefault();
      readControls();
      state.page = 1;
      render();
    });
    els.size.addEventListener('change', function () {
      state.size = parseInt(els.size.value, 10) || 0;
      state.page = 1;
      render();
    });
    els.tsv.addEventListener('click', exportTsv);

    Array.prototype.forEach.call(els.chips, function (chip) {
      chip.hidden = false;
      chip.addEventListener('click', function (event) {
        event.preventDefault();
        state.species = chip.getAttribute('data-fef-species');
        state.page = 1;
        render();
      });
    });

    /* Real buttons inside the headers, as MGDB.sortTable makes them; the
       sort itself is over the whole filtered list, not the rows on screen. */
    Array.prototype.forEach.call(els.headers, function (th) {
      var buttonEl = document.createElement('button');
      buttonEl.type = 'button';
      buttonEl.innerHTML = th.innerHTML;
      th.innerHTML = '';
      th.appendChild(buttonEl);
      buttonEl.addEventListener('click', function () {
        var key = th.getAttribute('data-fef-sort');
        /* Probabilities read best high-first; everything else A to Z. */
        var first = (key === 'apoplastic' || key === 'cytoplasmic' || key === 'signal') ? -1 : 1;
        state.sort = { key: key, dir: state.sort.key === key ? -state.sort.dir : first };
        state.page = 1;
        render();
        MGDB.announce('Sorted by ' + buttonEl.textContent.trim() + ', ' + (state.sort.dir > 0 ? 'ascending' : 'descending'));
      });
    });

    els.pager.addEventListener('click', function (event) {
      var target = event.target.closest && event.target.closest('[data-fef-page]');
      if (!target || target.disabled) { return; }
      event.preventDefault();
      state.page = parseInt(target.getAttribute('data-fef-page'), 10) || 1;
      render();
      if (els.table.scrollIntoView) {
        els.table.scrollIntoView({ block: 'start', behavior: MGDB.prefersReducedMotion() ? 'auto' : 'smooth' });
      }
    });
  }

  function init() {
    var root = document.querySelector('.fpt-effectors-page');
    if (!root) { return; }
    if (MGDB.sectionTabs) { MGDB.sectionTabs(); }

    els.form = document.getElementById('fef-filters');
    els.q = document.getElementById('fef-q');
    els.klass = document.getElementById('fef-class');
    els.signal = document.getElementById('fef-signal');
    els.size = document.getElementById('fef-size');
    els.tsv = document.getElementById('fef-tsv');
    els.count = document.getElementById('fef-count');
    els.table = document.getElementById('fef-grid');
    els.rows = document.getElementById('fef-rows');
    els.pager = document.getElementById('fef-pager');
    els.chips = root.querySelectorAll('[data-fef-species]');
    els.headers = root.querySelectorAll('th[data-fef-sort]');
    if (!els.form || !els.rows || !window.fetch) { return; }

    state.species = root.getAttribute('data-species') || 'graminearum';
    state.query = root.getAttribute('data-query') || '';
    state.klass = root.getAttribute('data-class') || '';
    state.signal = root.getAttribute('data-signal') || '';
    var params = window.URLSearchParams ? new window.URLSearchParams(window.location.search) : null;
    state.page = params ? Math.max(1, parseInt(params.get('page') || '1', 10) || 1) : 1;

    window.fetch(root.getAttribute('data-source'), { credentials: 'same-origin' })
      .then(function (response) {
        if (!response.ok) { throw new Error('status ' + response.status); }
        return response.json();
      })
      .then(function (data) {
        state.rows = [];
        (data.species || []).forEach(function (sp, index) {
          var meta = { key: sp.key, label: sp.label, latin: sp.latin, paneffect: sp.paneffect, index: index };
          sp.rows.forEach(function (row) { row.sp = meta; state.rows.push(row); });
        });
        state.ready = true;
        bind();
        render();
      })
      .catch(function () {
        /* The server-rendered page still works: its chips and pager are
           links. Only the extras are missing, and the download stays off. */
      });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
