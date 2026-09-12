/* Genome Data Hub (/genome).

   The assembly table, the in-progress table and the taxon counts are rendered
   server-side from the database, so the page is complete before this runs.
   This adds the charts, the section tabs, and a collection view over each
   list that is already on the page: a table by default and a grid of the same
   rows, a filter, sortable columns, a page size, and a TSV download. */

(function () {
  'use strict';

  var PAGE_SIZES = [10, 25, 50];
  var DEFAULT_PAGE_SIZE = 25;

  function byId(id) { return document.getElementById(id); }
  function escape(value) { return window.MGDB ? window.MGDB.escapeHtml(value == null ? '' : String(value)) : String(value == null ? '' : value); }
  function number(value) { return Number(value || 0).toLocaleString(); }
  function plainText(value) {
    if (value === null || value === undefined) { return ''; }
    return String(value).replace(/\s+/g, ' ').trim();
  }
  function debounce(fn, wait) {
    if (window.MGDB && window.MGDB.debounce) { return window.MGDB.debounce(fn, wait); }
    var timer = null;
    return function () { var args = arguments; window.clearTimeout(timer); timer = window.setTimeout(function () { fn.apply(null, args); }, wait); };
  }

  function text(tag, value, className) {
    var node = document.createElement(tag);
    node.textContent = value;
    if (className) { node.className = className; }
    return node;
  }

  /* Tabs and newlines are the field and record separators, so any inside a
     value are replaced rather than quoted: TSV has no agreed quoting rule. */
  function downloadTsv(filename, columns, rows) {
    var lines = [columns.map(function (c) { return c.label; }).join('\t')];
    rows.forEach(function (item) {
      lines.push(columns.map(function (c) { return plainText(c.tsv ? c.tsv(item) : c.get(item)).replace(/[\t\r\n]+/g, ' '); }).join('\t'));
    });
    var blob = new Blob([lines.join('\n') + '\n'], { type: 'text/tab-separated-values;charset=utf-8' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
  }

  function paginationHtml(page, pages, cls) {
    if (pages <= 1) { return ''; }
    var html = '<button class="' + cls + '" type="button" data-page="' + (page - 1) + '"' + (page === 1 ? ' disabled' : '') + ' aria-label="Previous page">&larr; Prev</button>';
    var last = null;
    for (var p = 1; p <= pages; p++) {
      if (p === 1 || p === pages || (p >= page - 1 && p <= page + 1)) {
        html += '<button class="' + cls + (p === page ? ' is-active' : '') + '" type="button" data-page="' + p + '"' + (p === page ? ' aria-current="page"' : '') + '>' + p + '</button>';
        last = p;
      } else if (last !== '…') {
        html += '<span class="genome-page-ellipsis" aria-hidden="true">&hellip;</span>';
        last = '…';
      }
    }
    html += '<button class="' + cls + '" type="button" data-page="' + (page + 1) + '"' + (page === pages ? ' disabled' : '') + ' aria-label="Next page">Next &rarr;</button>';
    return html;
  }

  /* ------------------------------------------------------------------------
     A collection over a server-rendered table

     The rows are read once from the table the server put on the page -- each
     cell's markup and its text, plus the row's data attributes -- and from
     then on the table, the grid, the filter, the sort and the pager are all
     rendered from that list. Without script the full table simply stays.

     options:
       predicate(item)   extra filter, from the page's own controls
       onCount(shown, total)
     ------------------------------------------------------------------------ */

  function collectionFromTable(host, options) {
    options = options || {};
    var table = host.querySelector('table');
    if (!table || !table.tBodies[0]) { return null; }

    var headers = Array.prototype.map.call(table.tHead.rows[0].cells, function (th) {
      return { label: plainText(th.textContent), sort: th.getAttribute('data-sort') || 'text', numeric: th.classList.contains('mgdb-numeric') };
    });
    var items = Array.prototype.map.call(table.tBodies[0].rows, function (tr, index) {
      var cells = Array.prototype.map.call(tr.cells, function (td) {
        return { html: td.innerHTML, text: plainText(td.textContent), muted: !!td.querySelector('.mgdb-muted') && plainText(td.textContent) === 'Not reported' };
      });
      return { index: index, cells: cells, data: tr.dataset, search: (tr.getAttribute('data-search') || cells.map(function (c) { return c.text; }).join(' ')).toLowerCase() };
    });
    // A placeholder row ("No assemblies are currently listed") is not a record.
    if (items.length === 1 && items[0].cells.length < headers.length) { return null; }

    var columns = headers.map(function (h, i) {
      return {
        key: 'c' + i, label: h.label, sort: h.sort, numeric: h.numeric,
        get: function (item) { return item.cells[i] && !item.cells[i].muted ? item.cells[i].text : ''; },
        html: function (item) { return item.cells[i] ? item.cells[i].html : ''; }
      };
    });

    var title = host.getAttribute('data-title') || 'Records';
    var caption = table.caption ? table.caption.innerHTML : '';
    var state = { view: 'table', size: DEFAULT_PAGE_SIZE, page: 1, query: '', sortKey: null, sortDir: 'ascending' };

    var sizeOptions = PAGE_SIZES.map(function (n) {
      return '<option value="' + n + '"' + (n === state.size ? ' selected' : '') + '>' + n + '</option>';
    }).join('') + '<option value="all">All</option>';

    host.innerHTML =
      '<div class="genome-block-head">' +
        '<h3>' + escape(title) + '<span class="genome-block-count">' + number(items.length) + '</span></h3>' +
        '<div class="genome-toolbar">' +
          (options.noFilter ? '' : '<label>Filter <input type="search" data-role="filter" placeholder="Within this list" aria-label="Filter ' + escape(title) + '"></label>') +
          '<div class="mgdb-view-toggle" role="group" aria-label="' + escape(title) + ' view">' +
            '<button class="mgdb-view-btn" type="button" data-view="table" aria-pressed="true"><svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="1" y="2" width="14" height="2" rx="1" fill="currentColor"/><rect x="1" y="7" width="14" height="2" rx="1" fill="currentColor"/><rect x="1" y="12" width="14" height="2" rx="1" fill="currentColor"/></svg>Table</button>' +
            '<button class="mgdb-view-btn" type="button" data-view="grid" aria-pressed="false"><svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="1" y="1" width="6" height="6" rx="1" fill="currentColor"/><rect x="9" y="1" width="6" height="6" rx="1" fill="currentColor"/><rect x="1" y="9" width="6" height="6" rx="1" fill="currentColor"/><rect x="9" y="9" width="6" height="6" rx="1" fill="currentColor"/></svg>Grid</button>' +
          '</div>' +
          '<label>Show <select data-role="size" aria-label="Rows per page">' + sizeOptions + '</select></label>' +
          '<button class="genome-tsv" type="button" data-role="tsv">Download TSV</button>' +
        '</div>' +
      '</div>' +
      '<p class="genome-block-status" data-role="status" aria-live="polite"></p>' +
      '<div data-role="body"></div>' +
      '<nav class="genome-pagination" data-role="pagination" aria-label="' + escape(title) + ' pages" hidden></nav>';

    var body = host.querySelector('[data-role="body"]');
    var status = host.querySelector('[data-role="status"]');
    var pagination = host.querySelector('[data-role="pagination"]');
    var filterInput = host.querySelector('[data-role="filter"]');

    function filtered() {
      var rows = items.slice();
      if (options.predicate) { rows = rows.filter(options.predicate); }
      if (state.query) {
        var needle = state.query.toLowerCase();
        rows = rows.filter(function (item) { return item.search.indexOf(needle) !== -1; });
      }
      if (state.sortKey) {
        var col = columns.filter(function (c) { return c.key === state.sortKey; })[0];
        var dir = state.sortDir === 'ascending' ? 1 : -1;
        rows.sort(function (a, b) {
          var av = col.get(a), bv = col.get(b);
          if (av === '' && bv === '') { return 0; }
          if (av === '') { return 1; }
          if (bv === '') { return -1; }
          if (col.sort === 'number') {
            var an = parseFloat(av.replace(/,/g, '')), bn = parseFloat(bv.replace(/,/g, ''));
            if (!isNaN(an) && !isNaN(bn)) { return (an - bn) * dir; }
          }
          return av.localeCompare(bv, undefined, { numeric: true, sensitivity: 'base' }) * dir;
        });
      }
      return rows;
    }

    function renderTable(rows) {
      var head = columns.map(function (col) {
        var aria = state.sortKey === col.key ? state.sortDir : 'none';
        return '<th scope="col"' + (col.numeric ? ' class="mgdb-numeric"' : '') + ' data-sort="' + col.sort + '" aria-sort="' + aria + '">' +
          '<button type="button" data-sort-key="' + col.key + '">' + escape(col.label) + '</button></th>';
      }).join('');
      var bodyRows = rows.map(function (item) {
        return '<tr>' + columns.map(function (col, i) {
          return i === 0 ? '<th scope="row">' + col.html(item) + '</th>' : '<td' + (col.numeric ? ' class="mgdb-numeric"' : '') + '>' + col.html(item) + '</td>';
        }).join('') + '</tr>';
      }).join('');
      return '<div class="mgdb-table-scroll" tabindex="0" role="region" aria-label="' + escape(title) + ' table">' +
        '<table class="mgdb-table">' + (caption ? '<caption>' + caption + '</caption>' : '') +
        '<thead><tr>' + head + '</tr></thead><tbody>' + bodyRows + '</tbody></table></div>';
    }

    function renderGrid(rows) {
      return '<div class="mgdb-card-grid genome-grid">' + rows.map(function (item) {
        var facts = columns.slice(1).map(function (col) {
          return '<div><dt>' + escape(col.label) + '</dt><dd>' + col.html(item) + '</dd></div>';
        }).join('');
        return '<article class="mgdb-card"><p class="genome-tile-title">' + columns[0].html(item) + '</p><dl>' + facts + '</dl></article>';
      }).join('') + '</div>';
    }

    function render() {
      var rows = filtered();
      var total = rows.length;
      var size = state.size === 'all' ? Math.max(total, 1) : Number(state.size);
      var pages = Math.max(1, Math.ceil(total / size));
      if (state.page > pages) { state.page = pages; }
      var start = (state.page - 1) * size;
      var pageRows = rows.slice(start, start + size);

      body.innerHTML = total === 0 ? '' : (state.view === 'grid' ? renderGrid(pageRows) : renderTable(pageRows));
      status.textContent = total === 0 ? '' :
        'Showing ' + (start + 1) + '–' + Math.min(start + size, total) + ' of ' + number(total) +
        (total !== items.length ? ' matching ' : ' ') + (host.getAttribute('data-noun') || 'rows');

      Array.prototype.forEach.call(body.querySelectorAll('button[data-sort-key]'), function (btn) {
        btn.addEventListener('click', function () {
          var next = btn.getAttribute('data-sort-key');
          if (state.sortKey === next) { state.sortDir = state.sortDir === 'ascending' ? 'descending' : 'ascending'; }
          else { state.sortKey = next; state.sortDir = 'ascending'; }
          render();
          if (window.MGDB) { window.MGDB.announce(title + ' sorted by ' + btn.textContent + ', ' + state.sortDir + '.'); }
        });
      });

      pagination.innerHTML = paginationHtml(state.page, pages, 'genome-page-btn');
      pagination.hidden = pages <= 1;
      Array.prototype.forEach.call(pagination.querySelectorAll('[data-page]'), function (btn) {
        btn.addEventListener('click', function () {
          var next = parseInt(btn.getAttribute('data-page'), 10);
          if (isNaN(next) || next < 1 || next > pages || next === state.page) { return; }
          state.page = next;
          render();
          host.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
      });

      if (options.onCount) { options.onCount(total, items.length); }
    }

    if (filterInput) {
      filterInput.addEventListener('input', debounce(function () { state.query = filterInput.value.trim(); state.page = 1; render(); }, 150));
    }
    host.querySelector('[data-role="size"]').addEventListener('change', function (event) {
      state.size = event.target.value === 'all' ? 'all' : Number(event.target.value);
      state.page = 1;
      render();
    });
    Array.prototype.forEach.call(host.querySelectorAll('.mgdb-view-btn'), function (btn) {
      btn.addEventListener('click', function () {
        state.view = btn.getAttribute('data-view');
        Array.prototype.forEach.call(host.querySelectorAll('.mgdb-view-btn'), function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
        render();
      });
    });
    host.querySelector('[data-role="tsv"]').addEventListener('click', function () {
      downloadTsv(host.getAttribute('data-filename') || 'records.tsv', columns, filtered());
    });

    render();
    return {
      refresh: function () { state.page = 1; render(); },
      setQuery: function (value) { state.query = plainText(value); state.page = 1; render(); }
    };
  }

  /* ------------------------------------------------------------------------
     Search: the query field, the example buttons, and the advanced filters
     all drive the assembly collection. Results are presented in dual table/cards
     view with live in-result filtering, pagination, and TSV export.
     ------------------------------------------------------------------------ */

  function buildSearch() {
    var collectionHost = byId('genome-assembly-collection');
    var tableBody = byId('genome-table-body');
    if (!collectionHost || !tableBody) { return; }

    var input = byId('genome-query');
    var clearBtn = byId('genome-query-clear');
    var form = byId('genome-search-form');
    var qualitySel = byId('genome-filter-quality');
    var groupSel = byId('genome-filter-group');
    var advSortSel = byId('genome-adv-sort');
    var advCount = byId('genome-advanced-count');
    var resetBtn = byId('genome-reset');
    var chips = document.querySelectorAll('.genome-species-filters .mgdb-chip[data-filter]');

    var resultsSection = byId('genome-results-section');
    var resultsStatus = byId('genome-results-status');
    var resultsFilter = byId('genome-results-filter');
    var filterCount = byId('genome-filter-count');
    var pageSizeSel = byId('genome-page-size');
    var exportTsvBtn = byId('genome-export-tsv');
    var viewCardsBtn = byId('genome-view-cards');
    var viewTableBtn = byId('genome-view-table');
    var tableView = byId('genome-table-view');
    var cardsView = byId('genome-cards-view');
    var pagination = byId('genome-pagination');
    var empty = byId('genome-empty');
    var emptyReset = byId('genome-empty-reset');

    // Parse initial rows from table DOM
    var allRows = Array.prototype.map.call(tableBody.querySelectorAll('tr'), function (tr, index) {
      var cells = tr.children;
      var aEl = cells[0] ? cells[0].querySelector('a') : null;
      var assembly = aEl ? plainText(aEl.textContent) : plainText(cells[0] ? cells[0].textContent : '');
      var assemblyHref = aEl ? aEl.getAttribute('href') : '';
      var assemblyHtml = cells[0] ? cells[0].innerHTML : '';
      var cultivar = cells[1] ? plainText(cells[1].textContent) : '';
      var speciesHtml = cells[2] ? cells[2].innerHTML : '';
      var species = cells[2] ? plainText(cells[2].textContent) : '';
      var qualityHtml = cells[3] ? cells[3].innerHTML : '';
      var quality = cells[3] ? plainText(cells[3].textContent) : '';
      var accessionHtml = cells[4] ? cells[4].innerHTML : '';
      var accession = cells[4] ? plainText(cells[4].textContent) : '';
      /* The Status column was dropped 2026-09-10 -- it read "Current" on every
         one of the 160 rows. See controllers/genome/genome_center_modern.php. */
      var groupAttr = tr.getAttribute('data-group') || '';
      var qualityAttr = tr.getAttribute('data-quality') || '';
      var search = (tr.getAttribute('data-search') || (assembly + ' ' + cultivar + ' ' + species + ' ' + accession + ' ' + quality)).toLowerCase();

      var qualityRank = 4;
      if (qualityAttr === 'Representative') { qualityRank = 1; }
      else if (qualityAttr === 'Reference') { qualityRank = 2; }
      else if (qualityAttr === 'Draft') { qualityRank = 3; }

      return {
        index: index,
        assembly: assembly,
        assemblyHtml: assemblyHtml,
        assemblyHref: assemblyHref,
        cultivar: cultivar,
        species: species,
        speciesHtml: speciesHtml,
        quality: quality,
        qualityHtml: qualityHtml,
        qualityRank: qualityRank,
        accession: accession,
        accessionHtml: accessionHtml,
        groupAttr: groupAttr,
        qualityAttr: qualityAttr,
        search: search
      };
    });

    var state = {
      q: '',
      group: 'all',
      quality: 'all',
      advSort: 'default',
      resultsFilter: '',
      pageSize: 25,
      page: 1,
      view: 'table',
      sortKey: null,
      sortDir: 'ascending'
    };

    var params = new URLSearchParams(window.location.search);
    if (params.get('q')) { state.q = params.get('q'); if (input) { input.value = state.q; } }
    if (params.get('taxon')) { state.group = params.get('taxon'); if (groupSel) { groupSel.value = state.group; } }
    if (params.get('quality') && qualitySel) { state.quality = params.get('quality'); qualitySel.value = state.quality; }
    if (params.get('sort') && advSortSel) { state.advSort = params.get('sort'); advSortSel.value = state.advSort; }
    if (params.get('view') && (params.get('view') === 'cards' || params.get('view') === 'table')) { state.view = params.get('view'); }

    function syncUrl() {
      var next = new URLSearchParams(window.location.search);
      if (state.q) { next.set('q', state.q); } else { next.delete('q'); }
      if (state.group !== 'all') { next.set('taxon', state.group); } else { next.delete('taxon'); }
      next.delete('status');   // the Status filter was removed 2026-09-10
      if (state.quality !== 'all') { next.set('quality', state.quality); } else { next.delete('quality'); }
      if (state.advSort !== 'default') { next.set('sort', state.advSort); } else { next.delete('sort'); }
      if (state.view !== 'table') { next.set('view', state.view); } else { next.delete('view'); }
      var qStr = next.toString();
      window.history.replaceState(null, '', window.location.pathname + (qStr ? '?' + qStr : '') + window.location.hash);
    }

    function syncControls() {
      if (clearBtn && input) { clearBtn.hidden = !input.value.trim(); }
      Array.prototype.forEach.call(chips, function (chip) {
        chip.setAttribute('aria-pressed', chip.getAttribute('data-filter') === state.group ? 'true' : 'false');
      });
      if (groupSel) { groupSel.value = state.group; }
      if (qualitySel) { qualitySel.value = state.quality; }
      if (advSortSel) { advSortSel.value = state.advSort; }

      var activeAdv = (state.group !== 'all' ? 1 : 0) +
                      (state.quality !== 'all' ? 1 : 0) +
                      (state.advSort !== 'default' ? 1 : 0);
      if (advCount) {
        advCount.textContent = activeAdv ? activeAdv + ' active' : '';
        advCount.hidden = !activeAdv;
      }
    }

    function getFiltered() {
      var rows = allRows.slice();

      if (state.q) {
        var qTokens = state.q.toLowerCase().split(/\s+/).filter(Boolean);
        rows = rows.filter(function (item) {
          return qTokens.every(function (tok) { return item.search.indexOf(tok) !== -1; });
        });
      }

      if (state.group !== 'all') {
        rows = rows.filter(function (item) { return item.groupAttr === state.group; });
      }
      if (state.quality !== 'all') {
        rows = rows.filter(function (item) { return item.qualityAttr === state.quality; });
      }

      if (state.resultsFilter) {
        var rfTokens = state.resultsFilter.toLowerCase().split(/\s+/).filter(Boolean);
        rows = rows.filter(function (item) {
          return rfTokens.every(function (tok) { return item.search.indexOf(tok) !== -1; });
        });
      }

      if (state.sortKey) {
        var dir = state.sortDir === 'ascending' ? 1 : -1;
        var key = state.sortKey;
        rows.sort(function (a, b) {
          if (key === 'quality') {
            return ((a.qualityRank - b.qualityRank) || a.assembly.localeCompare(b.assembly)) * dir;
          }
          var av = a[key] || '', bv = b[key] || '';
          return av.localeCompare(bv, undefined, { numeric: true, sensitivity: 'base' }) * dir;
        });
      } else {
        switch (state.advSort) {
          case 'assembly-desc':
            rows.sort(function (a, b) { return b.assembly.localeCompare(a.assembly); });
            break;
          case 'cultivar-asc':
            rows.sort(function (a, b) { return a.cultivar.localeCompare(b.cultivar) || a.assembly.localeCompare(b.assembly); });
            break;
          case 'cultivar-desc':
            rows.sort(function (a, b) { return b.cultivar.localeCompare(a.cultivar) || a.assembly.localeCompare(b.assembly); });
            break;
          case 'species-asc':
            rows.sort(function (a, b) { return a.species.localeCompare(b.species) || a.assembly.localeCompare(b.assembly); });
            break;
          case 'quality-asc':
            rows.sort(function (a, b) { return (a.qualityRank - b.qualityRank) || a.assembly.localeCompare(b.assembly); });
            break;
          case 'default':
          default:
            rows.sort(function (a, b) { return a.index - b.index; });
            break;
        }
      }

      return rows;
    }

    function renderTableRows(pageRows) {
      tableBody.innerHTML = pageRows.map(function (item) {
        return '<tr data-group="' + escape(item.groupAttr) + '" data-quality="' + escape(item.qualityAttr) + '">' +
          '<th scope="row">' + item.assemblyHtml + '</th>' +
          '<td>' + escape(item.cultivar) + '</td>' +
          '<td>' + item.speciesHtml + '</td>' +
          '<td>' + item.qualityHtml + '</td>' +
          '<td>' + item.accessionHtml + '</td>' +
          '</tr>';
      }).join('');
    }

    function renderCards(pageRows) {
      if (!cardsView) { return; }
      cardsView.innerHTML = pageRows.map(function (item) {
        var badges = [];
        if (item.qualityAttr !== 'none' && item.qualityHtml) { badges.push(item.qualityHtml); }
        return '<article class="genome-result-card">' +
          '<div>' +
            '<div class="genome-card-header">' +
              '<h3 class="genome-card-title"><a href="' + escape(item.assemblyHref) + '">' + escape(item.assembly) + '</a></h3>' +
              '<div class="genome-card-badges">' + badges.join(' ') + '</div>' +
            '</div>' +
            '<dl class="genome-card-meta-list">' +
              '<div><dt>Cultivar</dt><dd>' + escape(item.cultivar || 'Not reported') + '</dd></div>' +
              '<div><dt>Species</dt><dd>' + (item.speciesHtml || 'Not reported') + '</dd></div>' +
              '<div><dt>Accession</dt><dd>' + (item.accessionHtml || 'Not reported') + '</dd></div>' +
              '<div><dt>Quality</dt><dd>' + (item.qualityHtml || 'Not reported') + '</dd></div>' +
            '</dl>' +
          '</div>' +
          '<div class="genome-card-actions">' +
            '<button class="genome-copy-btn" type="button" data-copy="' + escape(item.assembly) + '">Copy assembly</button>' +
          '</div>' +
        '</article>';
      }).join('');

      Array.prototype.forEach.call(cardsView.querySelectorAll('.genome-copy-btn'), function (btn) {
        btn.addEventListener('click', function () {
          var val = btn.getAttribute('data-copy');
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(val).then(function () {
              var orig = btn.textContent;
              btn.textContent = 'Copied!';
              setTimeout(function () { btn.textContent = orig; }, 1500);
            });
          }
        });
      });
    }

    function updateSortHeaders() {
      var table = byId('genome-table');
      if (!table || !table.tHead) { return; }
      Array.prototype.forEach.call(table.tHead.querySelectorAll('th'), function (th) {
        var btn = th.querySelector('button[data-sort-key]');
        if (!btn) { return; }
        var key = btn.getAttribute('data-sort-key');
        if (state.sortKey === key) {
          th.setAttribute('aria-sort', state.sortDir);
        } else {
          th.setAttribute('aria-sort', 'none');
        }
      });
    }

    function render() {
      syncControls();
      syncUrl();

      var rows = getFiltered();
      var total = rows.length;

      if (filterCount) {
        if (state.resultsFilter) {
          filterCount.textContent = total + ' match' + (total === 1 ? '' : 'es');
        } else {
          filterCount.textContent = '';
        }
      }

      if (total === 0) {
        if (collectionHost) { collectionHost.hidden = true; }
        if (empty) { empty.hidden = false; }
        if (resultsStatus) { resultsStatus.textContent = 'No matching assemblies found.'; }
        if (pagination) { pagination.hidden = true; }
        return;
      }

      if (collectionHost) { collectionHost.hidden = false; }
      if (empty) { empty.hidden = true; }

      var size = state.pageSize === 'all' ? Math.max(total, 1) : Number(state.pageSize);
      var pages = Math.max(1, Math.ceil(total / size));
      if (state.page > pages) { state.page = pages; }
      var start = (state.page - 1) * size;
      var pageRows = rows.slice(start, start + size);

      var isFiltered = (total !== allRows.length);
      if (resultsStatus) {
        var rangeStr = 'Showing ' + (start + 1) + '–' + Math.min(start + size, total) + ' of ' + number(total);
        if (isFiltered) {
          resultsStatus.innerHTML = rangeStr + ' matching assemblies <span class="mgdb-muted">(filtered from ' + number(allRows.length) + ' total)</span>';
        } else {
          resultsStatus.textContent = rangeStr + ' assemblies';
        }
      }

      // Views
      if (state.view === 'cards') {
        if (tableView) { tableView.hidden = true; }
        if (cardsView) { cardsView.hidden = false; }
        if (viewCardsBtn) { viewCardsBtn.setAttribute('aria-pressed', 'true'); viewCardsBtn.classList.add('is-active'); }
        if (viewTableBtn) { viewTableBtn.setAttribute('aria-pressed', 'false'); viewTableBtn.classList.remove('is-active'); }
        renderCards(pageRows);
      } else {
        if (tableView) { tableView.hidden = false; }
        if (cardsView) { cardsView.hidden = true; }
        if (viewTableBtn) { viewTableBtn.setAttribute('aria-pressed', 'true'); viewTableBtn.classList.add('is-active'); }
        if (viewCardsBtn) { viewCardsBtn.setAttribute('aria-pressed', 'false'); viewCardsBtn.classList.remove('is-active'); }
        renderTableRows(pageRows);
        updateSortHeaders();
      }

      // Pagination
      if (pagination) {
        pagination.innerHTML = paginationHtml(state.page, pages, 'genome-page-btn');
        pagination.hidden = pages <= 1;
        Array.prototype.forEach.call(pagination.querySelectorAll('[data-page]'), function (btn) {
          btn.addEventListener('click', function () {
            var next = parseInt(btn.getAttribute('data-page'), 10);
            if (isNaN(next) || next < 1 || next > pages || next === state.page) { return; }
            state.page = next;
            render();
            if (resultsSection) {
              resultsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
          });
        });
      }
    }

    // Wiring event listeners

    // Hero search input
    if (input) {
      input.addEventListener('input', debounce(function () {
        state.q = input.value.trim();
        state.page = 1;
        render();
      }, 150));
    }

    // Clear button
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        if (input) {
          input.value = '';
          input.focus();
        }
        state.q = '';
        state.page = 1;
        render();
      });
    }

    // Form submit
    if (form) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (input) { state.q = input.value.trim(); }
        state.page = 1;
        render();
        if (resultsSection) {
          resultsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      });
    }

    // Examples
    Array.prototype.forEach.call(document.querySelectorAll('[data-genome-example]'), function (btn) {
      btn.addEventListener('click', function () {
        var ex = btn.getAttribute('data-genome-example') || '';
        if (input) { input.value = ex; }
        state.q = ex;
        state.page = 1;
        render();
        if (resultsSection) {
          resultsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      });
    });

    // Advanced search controls
    if (qualitySel) {
      qualitySel.addEventListener('change', function () {
        state.quality = qualitySel.value;
        state.page = 1;
        render();
      });
    }

    if (groupSel) {
      groupSel.addEventListener('change', function () {
        state.group = groupSel.value;
        state.page = 1;
        render();
      });
    }

    if (advSortSel) {
      advSortSel.addEventListener('change', function () {
        state.advSort = advSortSel.value;
        state.sortKey = null; // Clear manual table column sort
        state.page = 1;
        render();
      });
    }

    // Quick filter chips
    Array.prototype.forEach.call(chips, function (chip) {
      chip.addEventListener('click', function () {
        state.group = chip.getAttribute('data-filter') || 'all';
        state.page = 1;
        render();
      });
    });

    // Reset button
    function resetAll() {
      if (input) { input.value = ''; }
      if (resultsFilter) { resultsFilter.value = ''; }
      state.q = '';
      state.group = 'all';
      state.quality = 'all';
      state.advSort = 'default';
      state.resultsFilter = '';
      state.sortKey = null;
      state.sortDir = 'ascending';
      state.page = 1;
      render();
      if (input) { input.focus(); }
    }

    if (resetBtn) { resetBtn.addEventListener('click', resetAll); }
    if (emptyReset) { emptyReset.addEventListener('click', resetAll); }

    // Results toolbar live filter
    if (resultsFilter) {
      resultsFilter.addEventListener('input', debounce(function () {
        state.resultsFilter = resultsFilter.value.trim();
        state.page = 1;
        render();
      }, 150));
    }

    // Results shown
    if (pageSizeSel) {
      pageSizeSel.addEventListener('change', function () {
        state.pageSize = pageSizeSel.value;
        state.page = 1;
        render();
      });
    }

    // View switcher
    if (viewCardsBtn) {
      viewCardsBtn.addEventListener('click', function () {
        state.view = 'cards';
        render();
      });
    }

    if (viewTableBtn) {
      viewTableBtn.addEventListener('click', function () {
        state.view = 'table';
        render();
      });
    }

    // Table header sort
    var table = byId('genome-table');
    if (table && table.tHead) {
      Array.prototype.forEach.call(table.tHead.querySelectorAll('button[data-sort-key]'), function (btn) {
        btn.addEventListener('click', function () {
          var key = btn.getAttribute('data-sort-key');
          if (state.sortKey === key) {
            state.sortDir = state.sortDir === 'ascending' ? 'descending' : 'ascending';
          } else {
            state.sortKey = key;
            state.sortDir = 'ascending';
          }
          state.page = 1;
          render();
        });
      });
    }

    // TSV export
    if (exportTsvBtn) {
      exportTsvBtn.addEventListener('click', function () {
        var rows = getFiltered();
        var columns = [
          { label: 'Assembly', get: function (r) { return r.assembly; } },
          { label: 'Cultivar', get: function (r) { return r.cultivar; } },
          { label: 'Species', get: function (r) { return r.species; } },
          { label: 'Quality', get: function (r) { return r.quality; } },
          { label: 'Accession', get: function (r) { return r.accession; } }
        ];
        downloadTsv('maizegdb_genome_assemblies.tsv', columns, rows);
      });
    }

    render();
  }

  function buildProgress() {
    var host = byId('genome-progress-collection');
    if (host) { collectionFromTable(host); }
  }

  /* ------------------------------------------------------------------------
     Charts
     ------------------------------------------------------------------------ */

  function growthData() {
    var node = byId('genome-growth-data');
    if (!node) { return null; }
    try {
      var parsed = JSON.parse(node.textContent || 'null');
      return (parsed && parsed.points && parsed.points.length) ? parsed : null;
    } catch (error) {
      return null;
    }
  }

  function sizeChart(id, height) {
    var el = byId(id);
    if (el) { el.style.height = height + 'px'; }
    return height;
  }

  /* The history chart, drawn the way the Codex genome2 page draws its
     "A history of genomes at MaizeGDB": a hand-built SVG rather than Plotly,
     so it can carry the gold landmark pins on stems and the crosshair tip. The
     series, the landmark labels and their label heights all come from the
     controller as JSON; nothing about the data is decided here. */
  function buildGrowth() {
    var data = growthData();
    var svg = byId('genome-growth-chart');
    if (!data || !svg) { return; }
    var growth = data.points;
    var milestones = data.milestones || {};
    var levels = data.levels || {};

    var tbody = document.querySelector('#genome-growth-table tbody');
    if (tbody) {
      growth.forEach(function (point, index) {
        var row = document.createElement('tr');
        var year = text('th', String(point[0]));
        year.setAttribute('scope', 'row');
        row.appendChild(year);
        row.appendChild(text('td', point[1].toLocaleString(), 'mgdb-numeric'));
        var change = index ? point[1] - growth[index - 1][1] : null;
        row.appendChild(text('td', change === null ? '—' : (change >= 0 ? '+' : '') + change, 'mgdb-numeric'));
        row.appendChild(text('td', milestones[point[0]] || '—'));
        tbody.appendChild(row);
      });
    }

    var width = 960, height = 420, margin = { left: 48, right: 27, top: 26, bottom: 38 };
    var plotWidth = width - margin.left - margin.right;
    var plotHeight = height - margin.top - margin.bottom;
    var firstYear = growth[0][0], lastYear = growth[growth.length - 1][0];
    var maxValue = Math.max(175, Math.ceil(Math.max.apply(null, growth.map(function (row) { return row[1]; })) / 25) * 25);
    var x = function (year) { return margin.left + ((year - firstYear) / (lastYear - firstYear)) * plotWidth; };
    var y = function (value) { return margin.top + (1 - value / maxValue) * plotHeight; };
    var parts = ['<defs><linearGradient id="genome-history-fill" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#5fa028" stop-opacity=".28"/><stop offset="1" stop-color="#5fa028" stop-opacity=".02"/></linearGradient></defs>'];

    for (var value = 0; value <= maxValue; value += 25) {
      parts.push('<line class="genome-history-grid" x1="' + margin.left + '" y1="' + y(value) + '" x2="' + (width - margin.right) + '" y2="' + y(value) + '"/>');
      parts.push('<text class="genome-history-axis" x="' + (margin.left - 9) + '" y="' + (y(value) + 4) + '" text-anchor="end">' + value + '</text>');
    }
    growth.forEach(function (row) {
      parts.push('<text class="genome-history-year" x="' + x(row[0]) + '" y="' + (height - 15) + '" text-anchor="middle">' + String(row[0]).slice(2) + '</text>');
    });
    parts.push('<text class="genome-history-axis" x="' + margin.left + '" y="' + (height - 2) + '">' + firstYear + '</text>');

    var points = growth.map(function (row) { return [x(row[0]), y(row[1])]; });
    var line = 'M' + points.map(function (point) { return point[0].toFixed(1) + ',' + point[1].toFixed(1); }).join(' L');
    parts.push('<path class="genome-history-area" d="' + line + ' L' + points[points.length - 1][0].toFixed(1) + ',' + y(0) + ' L' + points[0][0].toFixed(1) + ',' + y(0) + ' Z"/>');
    parts.push('<path class="genome-history-line" d="' + line + '"/>');

    Object.keys(milestones).map(Number).sort().forEach(function (year) {
      var row = growth.filter(function (entry) { return entry[0] === year; })[0];
      if (!row) { return; }
      var level = levels[year] !== undefined ? levels[year] : row[1] + 24;
      var pointX = x(year), pointY = y(row[1]), labelY = y(Math.min(level, maxValue - 2));
      var anchor = year >= lastYear - 2 ? 'end' : (year <= firstYear + 2 ? 'start' : 'middle');
      var labelX = year >= lastYear - 2 ? pointX + 4 : (year <= firstYear + 2 ? pointX - 4 : pointX);
      parts.push('<line class="genome-history-stem" x1="' + pointX + '" y1="' + (pointY - 6) + '" x2="' + pointX + '" y2="' + (labelY + 4) + '"/>');
      parts.push('<circle class="genome-history-pin" cx="' + pointX + '" cy="' + pointY + '" r="4.7"/>');
      parts.push('<text class="genome-history-label" x="' + labelX + '" y="' + labelY + '" text-anchor="' + anchor + '">' + window.MGDB.escapeHtml(milestones[year]) + '</text>');
    });
    points.forEach(function (point) { parts.push('<circle class="genome-history-point" cx="' + point[0].toFixed(1) + '" cy="' + point[1].toFixed(1) + '" r="3.5"/>'); });
    var last = growth[growth.length - 1];
    parts.push('<text class="genome-history-end" x="' + (x(last[0]) - 7) + '" y="' + (y(last[1]) - 10) + '" text-anchor="end">' + last[1] + '</text>');
    parts.push('<line class="genome-history-crosshair" id="genome-history-crosshair" x1="0" y1="' + margin.top + '" x2="0" y2="' + (margin.top + plotHeight) + '"/>');
    parts.push('<circle class="genome-history-hover" id="genome-history-hover" r="5"/>');
    parts.push('<rect id="genome-history-hitbox" x="' + margin.left + '" y="' + margin.top + '" width="' + plotWidth + '" height="' + plotHeight + '" fill="transparent"/>');
    svg.innerHTML = parts.join('');

    var hitbox = byId('genome-history-hitbox');
    var crosshair = byId('genome-history-crosshair');
    var hoverPoint = byId('genome-history-hover');
    var tip = byId('genome-history-tip');
    function showPoint(event) {
      var bounds = svg.getBoundingClientRect();
      var svgX = ((event.clientX - bounds.left) / bounds.width) * width;
      var best = growth[0], distance = Infinity;
      growth.forEach(function (row) {
        var current = Math.abs(x(row[0]) - svgX);
        if (current < distance) { distance = current; best = row; }
      });
      var pointX = x(best[0]), pointY = y(best[1]);
      crosshair.setAttribute('x1', pointX); crosshair.setAttribute('x2', pointX); crosshair.style.opacity = 1;
      hoverPoint.setAttribute('cx', pointX); hoverPoint.setAttribute('cy', pointY); hoverPoint.style.opacity = 1;
      var index = growth.indexOf(best);
      var change = index ? best[1] - growth[index - 1][1] : null;
      tip.innerHTML = '<b>' + best[0] + '</b> · ' + best[1] + ' assemblies' + (change === null ? '' : ' · Δ ' + (change >= 0 ? '+' : '') + change);
      tip.style.left = ((pointX / width) * bounds.width) + 'px';
      tip.style.top = ((pointY / height) * bounds.height) + 'px';
      tip.classList.add('is-visible');
    }
    hitbox.addEventListener('pointermove', showPoint);
    hitbox.addEventListener('pointerdown', showPoint);
    hitbox.addEventListener('pointerleave', function () {
      crosshair.style.opacity = 0; hoverPoint.style.opacity = 0; tip.classList.remove('is-visible');
    });

    var toggle = byId('genome-history-toggle');
    var table = byId('genome-history-table-wrap');
    if (toggle && table) {
      toggle.addEventListener('click', function () {
        table.hidden = !table.hidden;
        toggle.setAttribute('aria-expanded', table.hidden ? 'false' : 'true');
        toggle.textContent = table.hidden ? 'Show data table' : 'Hide data table';
      });
    }
  }

  function buildTaxa() {
    var node = byId('genome-group-data');
    if (!node || !window.MGDB) { return; }

    var groups;
    try { groups = JSON.parse(node.textContent || '[]'); } catch (error) { return; }
    if (!groups.length) { return; }

    var ordered = groups.slice().sort(function (a, b) { return a.count - b.count; });
    var height = sizeChart('genome-taxa-chart', Math.max(320, ordered.length * 44 + 90));

    window.MGDB.chart({
      target: 'genome-taxa-chart',
      traces: function () {
        return [{
          type: 'bar', orientation: 'h',
          x: ordered.map(function (g) { return g.count; }),
          y: ordered.map(function (g) { return g.label; }),
          text: ordered.map(function (g) { return ' ' + Number(g.count).toLocaleString(); }),
          textposition: 'outside', textangle: 0, cliponaxis: false,
          marker: { color: window.MGDB.CHART_COLORS[1], line: { color: '#123', width: 1 } },
          hovertemplate: '%{y}: %{x} assemblies<extra></extra>'
        }];
      },
      layout: {
        height: height,
        xaxis: { title: { text: 'Assemblies' }, rangemode: 'tozero', automargin: true },
        yaxis: { automargin: true },
        showlegend: false,
        margin: { l: 10, r: 56, t: 12, b: 56 }
      }
    });
  }

  /* ------------------------------------------------------------------------
     Section tabs

     Driven by scroll, an IntersectionObserver and resize together, and the
     line it measures against is the section's own scroll-margin, so clicking
     a tab cannot mark the section above the one it jumped to.
     ------------------------------------------------------------------------ */

  function buildTabs() {
    var bar = byId('genome-tabs');
    if (!bar) { return; }
    var pairs = [];
    Array.prototype.forEach.call(bar.querySelectorAll('a'), function (tab) {
      var section = document.querySelector(tab.getAttribute('href'));
      if (section) { pairs.push({ tab: tab, section: section }); }
    });
    if (!pairs.length) { return; }

    var held = null;
    var heldScroll = 0;

    function markCurrent(target) {
      pairs.forEach(function (pair) {
        var current = pair.section === target;
        pair.tab.classList.toggle('is-current', current);
        if (current) { pair.tab.setAttribute('aria-current', 'true'); } else { pair.tab.removeAttribute('aria-current'); }
      });
    }

    function lineOffset() {
      var margin = parseFloat(getComputedStyle(pairs[0].section).scrollMarginTop) || 0;
      return Math.max(bar.offsetHeight + 8, margin + 4);
    }

    function spy() {
      if (held && Math.abs(window.scrollY - heldScroll) < 4) { return; }
      held = null;
      var line = window.scrollY + lineOffset();
      var current = pairs[0].section;
      pairs.forEach(function (pair) { if (pair.section.offsetTop <= line) { current = pair.section; } });
      if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 2) { current = pairs[pairs.length - 1].section; }
      markCurrent(current);
    }

    pairs.forEach(function (pair) {
      pair.tab.addEventListener('click', function () {
        held = pair.section;
        markCurrent(pair.section);
        setTimeout(function () { heldScroll = window.scrollY; }, 400);
      });
    });

    window.addEventListener('scroll', debounce(spy, 50), { passive: true });
    window.addEventListener('resize', debounce(spy, 100));
    if (window.IntersectionObserver) {
      var observer = new window.IntersectionObserver(function () { spy(); }, { rootMargin: '-20% 0px -60% 0px' });
      pairs.forEach(function (pair) { observer.observe(pair.section); });
    }
    if (window.location.hash) {
      pairs.forEach(function (pair) { if ('#' + pair.section.id === window.location.hash) { markCurrent(pair.section); held = pair.section; heldScroll = window.scrollY; } });
    } else {
      spy();
    }
  }

  function init() {
    buildSearch();
    buildProgress();
    buildGrowth();
    buildTaxa();
    buildTabs();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

/* Prototype assembly statistics.

   The JSON file mirrors the future API boundary: this code only depends on a
   documented response shape, not on how the rows are stored. The payload is
   fetched only when the section approaches the viewport, keeping the normal
   hub load fast for visitors who do not open the demonstration. The table and
   the grid are rendered from the same filtered, sorted, paged rows. */

(function () {
  'use strict';

  function startPrototype() {

  var section = document.getElementById('genome-statistics');
  if (!section) { return; }

  var state = {
    loaded: false,
    loading: false,
    rows: [],
    columns: [],
    dictionary: {},
    groups: [],
    defaultColumns: [],
    numeric: {},
    visible: {},
    sortColumn: 'assembly_id',
    sortDirection: 1,
    openRow: null,
    filters: { chromosome: false, annotation: false, flag: false },
    view: [],
    viewColumns: [],
    display: 'table',
    pageSize: 25,
    page: 1
  };

  function byId(id) { return document.getElementById(id); }

  function make(tag, className, value) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    if (value !== undefined && value !== null) { node.textContent = String(value); }
    return node;
  }

  function clear(node) {
    while (node && node.firstChild) { node.removeChild(node.firstChild); }
  }

  function setVisible(columns) {
    state.visible = {};
    columns.forEach(function (column) { state.visible[column] = true; });
    state.visible.assembly_id = true;
  }

  function number(value, digits) {
    return Number(value).toLocaleString(undefined, { minimumFractionDigits: digits, maximumFractionDigits: digits });
  }

  function formatted(column, value) {
    if (value === null || value === undefined || value === '') { return '—'; }
    if (value === true) { return 'yes'; }
    if (value === false) { return 'no'; }

    var meta = state.dictionary[column] || {};
    var unit = meta.unit || '';
    if (typeof value === 'number') {
      if (unit === 'bp') {
        if (value >= 1000000000) { return number(value / 1000000000, 2) + ' Gb'; }
        if (value >= 1000000) { return number(value / 1000000, 2) + ' Mb'; }
        if (value >= 1000) { return number(value / 1000, 1) + ' kb'; }
        return number(value, 0) + ' bp';
      }
      if (unit === 'percent') { return number(value, 2) + '%'; }
      if (unit === 'Gb') { return number(value, 3); }
      if (unit === 'count' || unit === 'integer') { return number(value, 0); }
      return Math.abs(value) >= 1000 ? value.toLocaleString() : String(value);
    }
    return String(value);
  }

  /* ------------------------------------------------------------------
     Presentation of the statistics table, so it reads like the assembly
     table above it (2026-09-10).

     Three things were inconsistent between the two tables:

       1. Headers. This table printed the raw dictionary key --
          `species_as_stated`, `n_scaffolds`, `gc_pct_of_acgt` -- beside an
          assembly table whose headers read "Assembly", "Species", "Quality".
          statsColumnLabel() turns a key into a human label: an override when
          the generic rule reads badly, otherwise split on underscores with a
          token map so N50, GC, ACGT, BUSCO and the unit suffixes survive.

       2. Vocabulary. `status` held REFERENCE / REFERENCE_NS / DRAFT, so B73 v5
          read "Reference" here and "Representative" above. statsQuality() maps
          it onto the assembly table's words, and the header is labelled
          "Quality" to match that column's name. REFERENCE_NS folds into
          Reference, which is what the assembly table already shows for PH207,
          its only holder.

       3. Species. `species_as_stated` held "Zea mays" with the subspecies in
          its own column, against "Zea mays ssp. mays" above. statsSpecies()
          joins the two -- both halves are as-stated, so the combined string is
          still what the README said.

     The underlying row data is untouched; this is display only, and the
     dictionary definition still reaches the reader through the cell tooltip.
     ------------------------------------------------------------------ */

  /* The B73 reference series. These five are the assemblies the assembly table
     calls Representative: four carry it in genome_information.quality, and v5
     gets it from the exact-name rule in genome_center_modern.php. The ids here
     are the statistics file's own (B73_RefGen_v1, underscored), which differ
     from the database's ("B73 RefGen_v1", spaced). */
  var STATS_REPRESENTATIVE = {
    'B73_RefGen_v1': true, 'B73_RefGen_v2': true, 'B73_RefGen_v3': true,
    'Zm-B73-REFERENCE-GRAMENE-4.0': true, 'Zm-B73-REFERENCE-NAM-5.0': true
  };

  function statsQuality(row) {
    if (STATS_REPRESENTATIVE[row.assembly_id]) { return 'Representative'; }
    var value = row.status;
    if (value === null || value === undefined || value === '') { return null; }
    value = String(value);
    if (value.indexOf('REFERENCE') === 0) { return 'Reference'; }
    if (value === 'DRAFT') { return 'Draft'; }
    return value;
  }

  function statsSpecies(row) {
    var name = row.species_as_stated;
    if (name === null || name === undefined || name === '') { return null; }
    return row.subspecies ? name + ' ssp. ' + row.subspecies : String(name);
  }

  /* The one value accessor the table, the grid, the detail list, the filters
     and the CSV all go through, so none of them can drift from the others. */
  function statsValue(row, column) {
    if (column === 'status') { return statsQuality(row); }
    if (column === 'species_as_stated') { return statsSpecies(row); }
    return row[column];
  }

  var STATS_LABELS = {
    assembly_id: 'Assembly',
    species_as_stated: 'Species',
    species_code: 'Species code',
    genotype_label: 'Cultivar',
    genotype_from_id: 'Cultivar (from ID)',
    status: 'Quality',
    n_annotation_versions: 'Annotation versions',
    annotation_ids: 'Annotation IDs',
    primary_annotation_id: 'Primary annotation ID',
    assembly_size_Gb: 'Assembly size (Gb)',
    gc_pct_of_acgt: 'GC % of ACGT',
    /* These three are the nucleotide N, not a count -- the generic "n_" rule
       would render them "Number of bases" and invert the meaning. */
    n_bases: 'N bases',
    n_pct_of_total: 'N % of total',
    gap_min_n: 'Gap minimum N run',
    other_iupac_bases: 'Other IUPAC bases',
    pct_chr_anchored: 'Chromosome-anchored %',
    chr_anchored_bp: 'Chromosome-anchored bp',
    is_chromosome_scale: 'Chromosome scale',
    n_unplaced: 'Unplaced sequences',
    n_scaffolds_ge_1Mb: 'Scaffolds \u2265 1 Mb',
    n_scaffolds_ge_10Mb: 'Scaffolds \u2265 10 Mb',
    n_genes_coding: 'Coding genes',
    n_genes_noncoding: 'Noncoding genes',
    n_transcripts_coding: 'Coding transcripts',
    n_genes_on_chromosomes: 'Genes on chromosomes',
    pct_genes_on_chromosomes: 'Genes on chromosomes %',
    pct_genome_exonic: 'Genome exonic %',
    pct_genome_cds: 'Genome CDS %',
    mono_exonic_transcript_pct: 'Mono-exonic transcript %',
    mean_protein_length_aa: 'Mean protein length (aa)',
    compleasm_n_markers: 'Compleasm markers',
    compleasm_single_n: 'Compleasm single (count)',
    compleasm_duplicated_n: 'Compleasm duplicated (count)',
    compleasm_missing_n: 'Compleasm missing (count)',
    busco_protein_n_markers: 'BUSCO protein markers',
    bioproject: 'BioProject',
    biosample: 'BioSample',
    has_te_gff: 'Has TE GFF',
    has_interproscan: 'Has InterProScan',
    md5_status: 'MD5 status',
    fai_validation: 'faidx validation',
    dir_prefix: 'Directory prefix',
    curation_flag: 'Curation flag',
    genome_file_url: 'Genome file'
  };

  /* Tokens a plain capitalise would ruin. */
  var STATS_TOKENS = {
    id: 'ID', ids: 'IDs', gc: 'GC', at: 'AT', acgt: 'ACGT', iupac: 'IUPAC',
    busco: 'BUSCO', compleasm: 'Compleasm', bp: 'bp', kb: 'kb', mb: 'Mb',
    gb: 'Gb', pct: '%', url: 'URL', n50: 'N50', l50: 'L50', n75: 'N75',
    n90: 'N90', l90: 'L90', aun: 'auN', cds: 'CDS', mrna: 'mRNA', utr: 'UTR',
    te: 'TE', ltr: 'LTR', rrna: 'rRNA', trna: 'tRNA', ncrna: 'ncRNA'
  };

  function statsColumnLabel(column) {
    if (STATS_LABELS[column]) { return STATS_LABELS[column]; }
    var parts = String(column).split('_');
    var words = [];
    parts.forEach(function (part, index) {
      var key = part.toLowerCase();
      if (STATS_TOKENS[key]) { words.push(STATS_TOKENS[key]); return; }
      // A leading "n_" is a count of the thing that follows.
      if (index === 0 && key === 'n') { words.push('Number of'); return; }
      words.push(index === 0 ? part.charAt(0).toUpperCase() + part.slice(1) : part);
    });
    var label = words.join(' ');
    return label.replace(/^(\w)/, function (m) { return m.toUpperCase(); });
  }

  function appendNull(cell) {
    cell.appendChild(make('span', 'genome-stats-null', '—'));
  }

  function safeDownloadUrl(value) {
    return typeof value === 'string' && value.indexOf('https://download.maizegdb.org/') === 0;
  }

  function completenessCell(cell, row, prefix) {
    var single = row[prefix + 'single_pct'];
    var duplicated = row[prefix + 'duplicated_pct'];
    var fragmented = row[prefix + 'fragmented_pct'];
    var missing = row[prefix + 'missing_pct'];
    if (single === null || single === undefined) { appendNull(cell); return; }

    var values = [[single, 'is-single'], [duplicated, 'is-duplicated'], [fragmented, 'is-fragmented'], [missing, 'is-missing']];
    var bar = make('span', 'genome-stats-completeness');
    bar.setAttribute('aria-hidden', 'true');
    values.forEach(function (item) {
      var segment = make('span', item[1]);
      segment.style.width = String(item[0] || 0) + '%';
      bar.appendChild(segment);
    });
    cell.appendChild(bar);
    cell.appendChild(document.createTextNode(' ' + number((single || 0) + (duplicated || 0), 1) + '%'));
    cell.title = 'Single ' + single + '%, duplicated ' + duplicated + '%, fragmented ' + fragmented + '%, missing ' + missing + '%';
  }

  /* Fills `cell` (a td or a dd) with the value of one column. */
  function fillCell(cell, row, column) {
    var value = statsValue(row, column);
    if (column === 'compleasm_complete_pct') {
      completenessCell(cell, row, 'compleasm_');
    } else if (column === 'busco_protein_complete_pct') {
      completenessCell(cell, row, 'busco_protein_');
    } else if (column === 'genome_file_url') {
      if (safeDownloadUrl(value)) {
        var link = make('a', '', 'FASTA');
        link.href = value;
        link.target = '_blank';
        link.rel = 'noopener';
        cell.appendChild(link);
      } else { appendNull(cell); }
    } else if (column === 'curation_flag') {
      if (value) {
        var flag = make('span', 'genome-stats-flag', '⚠ Review');
        flag.title = String(value);
        cell.appendChild(flag);
      } else { appendNull(cell); }
    } else if (value === null || value === undefined || value === '') {
      appendNull(cell);
    } else {
      cell.appendChild(make('span', column === 'species_as_stated' ? 'genome-stats-species' : '', formatted(column, value)));
    }
    return cell;
  }

  function dataCell(row, column) {
    return fillCell(make('td', state.numeric[column] ? 'genome-stats-num' : ''), row, column);
  }

  function visibleColumns() {
    return state.columns.filter(function (column) { return state.visible[column]; });
  }

  function filteredRows() {
    var query = (byId('genome-stats-query').value || '').toLowerCase();
    var species = byId('genome-stats-species').value;
    var status = byId('genome-stats-status-filter').value;
    var producer = byId('genome-stats-producer').value;

    return state.rows.filter(function (row) {
      if (species && statsSpecies(row) !== species) { return false; }
      if (status && statsQuality(row) !== status) { return false; }
      if (producer && row.producer !== producer) { return false; }
      if (state.filters.chromosome && !(row.is_chromosome_scale === true || row.pct_chr_anchored >= 90)) { return false; }
      if (state.filters.annotation && !(row.n_genes > 0)) { return false; }
      if (state.filters.flag && !row.curation_flag) { return false; }
      if (query) {
        var haystack = [row.assembly_id, row.genotype_label, statsSpecies(row), row.producer, row.primary_annotation_id, row.annotation_ids]
          .filter(function (value) { return value !== null && value !== undefined; }).join(' ').toLowerCase();
        if (haystack.indexOf(query) === -1) { return false; }
      }
      return true;
    });
  }

  function sortedRows(rows) {
    var column = state.sortColumn;
    var direction = state.sortDirection;
    return rows.slice().sort(function (a, b) {
      var left = a[column];
      var right = b[column];
      var leftNull = left === null || left === undefined || left === '';
      var rightNull = right === null || right === undefined || right === '';
      if (leftNull && rightNull) { return 0; }
      if (leftNull) { return 1; }
      if (rightNull) { return -1; }
      if (typeof left === 'number' && typeof right === 'number') { return (left - right) * direction; }
      return String(left).localeCompare(String(right)) * direction;
    });
  }

  function detailId(row) {
    return 'genome-stats-detail-' + String(row.assembly_id).replace(/[^A-Za-z0-9_-]/g, '-');
  }

  function detailContent(row) {
    var cell = document.createDocumentFragment();
    var record = make('a', '', 'Open the MaizeGDB genome record');
    record.href = '/genome/genome_assembly/' + encodeURIComponent(row.assembly_id);
    cell.appendChild(record);
    var list = make('dl', 'genome-stats-detail-list');
    state.columns.forEach(function (column) {
      var value = statsValue(row, column);
      if (column === 'assembly_id' || value === null || value === undefined || value === '') { return; }
      list.appendChild(make('dt', '', statsColumnLabel(column)));
      var definition = state.dictionary[column] && state.dictionary[column].definition;
      var valueBox = make('dd');
      valueBox.textContent = formatted(column, value);
      if (definition) { valueBox.title = definition; }
      list.appendChild(valueBox);
    });
    cell.appendChild(list);
    return cell;
  }

  function detailRow(row, columnCount) {
    var detail = make('tr', 'genome-stats-detail-row');
    detail.id = detailId(row);
    var cell = make('td');
    cell.colSpan = columnCount;
    cell.appendChild(make('h3', '', row.assembly_id));
    cell.appendChild(detailContent(row));
    detail.appendChild(cell);
    return detail;
  }

  function identityButton(row) {
    var button = make('button', 'genome-stats-assembly-button', row.assembly_id);
    button.type = 'button';
    button.setAttribute('aria-expanded', state.openRow === row.assembly_id ? 'true' : 'false');
    button.setAttribute('aria-controls', detailId(row));
    button.addEventListener('click', function () {
      state.openRow = state.openRow === row.assembly_id ? null : row.assembly_id;
      render();
    });
    return button;
  }

  function renderHead(columns) {
    var head = byId('genome-stats-head');
    clear(head);
    columns.forEach(function (column) {
      var meta = state.dictionary[column] || {};
      var th = make('th', column === 'assembly_id' ? 'genome-stats-identity' : '');
      th.scope = 'col';
      th.title = meta.definition || '';
      th.setAttribute('aria-sort', state.sortColumn === column ? (state.sortDirection > 0 ? 'ascending' : 'descending') : 'none');
      th.appendChild(make('span', 'genome-stats-column-group', meta.group || 'Other'));
      var button = make('button', '', statsColumnLabel(column));
      button.type = 'button';
      button.addEventListener('click', function () {
        if (state.sortColumn === column) { state.sortDirection *= -1; }
        else { state.sortColumn = column; state.sortDirection = 1; }
        render();
      });
      th.appendChild(button);
      head.appendChild(th);
    });
  }

  function renderBody(columns, rows) {
    var body = byId('genome-stats-body');
    var fragment = document.createDocumentFragment();
    clear(body);

    rows.forEach(function (row) {
      var tr = make('tr');
      columns.forEach(function (column) {
        if (column === 'assembly_id') {
          var identity = make('th', 'genome-stats-identity');
          identity.scope = 'row';
          identity.appendChild(identityButton(row));
          tr.appendChild(identity);
        } else {
          tr.appendChild(dataCell(row, column));
        }
      });
      fragment.appendChild(tr);
      if (state.openRow === row.assembly_id) { fragment.appendChild(detailRow(row, columns.length)); }
    });
    body.appendChild(fragment);
  }

  function renderGrid(columns, rows) {
    var grid = byId('genome-stats-grid');
    clear(grid);
    var fragment = document.createDocumentFragment();
    rows.forEach(function (row) {
      var card = make('article', 'mgdb-card');
      var title = make('p', 'genome-tile-title');
      title.appendChild(identityButton(row));
      card.appendChild(title);
      var list = make('dl');
      columns.forEach(function (column) {
        if (column === 'assembly_id') { return; }
        var item = make('div');
        var meta = state.dictionary[column] || {};
        var dt = make('dt', '', statsColumnLabel(column));
        if (meta.definition) { dt.title = meta.definition; }
        item.appendChild(dt);
        item.appendChild(fillCell(make('dd'), row, column));
        list.appendChild(item);
      });
      card.appendChild(list);
      if (state.openRow === row.assembly_id) {
        var detail = make('div', 'genome-stats-detail-list-wrap');
        detail.id = detailId(row);
        detail.appendChild(detailContent(row));
        card.appendChild(detail);
      }
      fragment.appendChild(card);
    });
    grid.appendChild(fragment);
  }

  function updateGroupButtons() {
    Array.prototype.forEach.call(document.querySelectorAll('.genome-stats-group'), function (button) {
      var group = button.getAttribute('data-group');
      var anyVisible = state.columns.some(function (column) { return state.dictionary[column].group === group && state.visible[column]; });
      button.setAttribute('aria-pressed', anyVisible ? 'true' : 'false');
    });
  }

  function paginationHtml(page, pages) {
    if (pages <= 1) { return ''; }
    var html = '<button class="genome-page-btn" type="button" data-page="' + (page - 1) + '"' + (page === 1 ? ' disabled' : '') + ' aria-label="Previous page">&larr; Prev</button>';
    var last = null;
    for (var p = 1; p <= pages; p++) {
      if (p === 1 || p === pages || (p >= page - 1 && p <= page + 1)) {
        html += '<button class="genome-page-btn' + (p === page ? ' is-active' : '') + '" type="button" data-page="' + p + '"' + (p === page ? ' aria-current="page"' : '') + '>' + p + '</button>';
        last = p;
      } else if (last !== '…') {
        html += '<span class="genome-page-ellipsis" aria-hidden="true">&hellip;</span>';
        last = '…';
      }
    }
    html += '<button class="genome-page-btn" type="button" data-page="' + (page + 1) + '"' + (page === pages ? ' disabled' : '') + ' aria-label="Next page">Next &rarr;</button>';
    return html;
  }

  function render() {
    var columns = visibleColumns();
    var rows = sortedRows(filteredRows());
    state.view = rows;
    state.viewColumns = columns;

    var size = state.pageSize === 'all' ? Math.max(rows.length, 1) : state.pageSize;
    var pages = Math.max(1, Math.ceil(rows.length / size));
    if (state.page > pages) { state.page = pages; }
    var start = (state.page - 1) * size;
    var pageRows = rows.slice(start, start + size);

    var tableWrap = byId('genome-stats-table-wrap');
    var grid = byId('genome-stats-grid');
    if (state.display === 'grid') {
      renderGrid(columns, pageRows);
      tableWrap.hidden = true;
      grid.hidden = rows.length === 0;
    } else {
      renderHead(columns);
      renderBody(columns, pageRows);
      grid.hidden = true;
      tableWrap.hidden = rows.length === 0;
    }
    updateGroupButtons();

    var countLabel = rows.length.toLocaleString() + ' of ' + state.rows.length.toLocaleString() + ' assemblies · ' + columns.length + ' columns' +
      (pages > 1 ? ' · showing ' + (start + 1) + '–' + Math.min(start + size, rows.length) : '');
    byId('genome-stats-count').textContent = countLabel;
    byId('genome-stats-fullscreen-count').textContent = countLabel;
    byId('genome-stats-empty').hidden = rows.length !== 0;

    var pagination = byId('genome-stats-pagination');
    pagination.innerHTML = paginationHtml(state.page, pages);
    pagination.hidden = pages <= 1;
    Array.prototype.forEach.call(pagination.querySelectorAll('[data-page]'), function (btn) {
      btn.addEventListener('click', function () {
        var next = parseInt(btn.getAttribute('data-page'), 10);
        if (isNaN(next) || next < 1 || next > pages || next === state.page) { return; }
        state.page = next;
        state.openRow = null;
        render();
        byId('genome-stats-table-stage').scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });
  }

  function optionList(select, column, label) {
    var seen = {};
    var values = [];
    state.rows.forEach(function (row) {
      var value = statsValue(row, column);
      if (value === null || value === undefined || value === '' || seen[value]) { return; }
      seen[value] = true;
      values.push(value);
    });
    values.sort(function (a, b) { return String(a).localeCompare(String(b)); });
    clear(select);
    var all = make('option', '', label + ' — all');
    all.value = '';
    select.appendChild(all);
    values.forEach(function (value) {
      var option = make('option', '', value);
      option.value = value;
      select.appendChild(option);
    });
  }

  function buildGroups() {
    var host = byId('genome-stats-groups');
    clear(host);
    state.groups.forEach(function (group) {
      /* Identity is not offered (2026-09-10). assembly_id lives in it and is
         force-set visible on the line after this handler's loop, so `anyVisible`
         was permanently true for this group: clicking it hid its columns and
         clicking it again hid them a second time. The "show all" branch was
         unreachable, and the button read as a toggle that had stopped working.
         Its columns keep their default visibility and are still individually
         controllable everywhere else. */
      if (group === 'Identity') { return; }
      var button = make('button', 'genome-stats-group', group);
      button.type = 'button';
      button.setAttribute('data-group', group);
      button.setAttribute('aria-pressed', 'false');
      button.addEventListener('click', function () {
        var columns = state.columns.filter(function (column) { return state.dictionary[column].group === group; });
        var anyVisible = columns.some(function (column) { return state.visible[column]; });
        columns.forEach(function (column) {
          if (anyVisible) { delete state.visible[column]; } else { state.visible[column] = true; }
        });
        state.visible.assembly_id = true;
        state.openRow = null;
        render();
      });
      host.appendChild(button);
    });
  }

  function toggleFilter(buttonId, key) {
    var button = byId(buttonId);
    button.addEventListener('click', function () {
      state.filters[key] = !state.filters[key];
      button.setAttribute('aria-pressed', state.filters[key] ? 'true' : 'false');
      state.openRow = null;
      state.page = 1;
      render();
    });
  }

  function reset() {
    byId('genome-stats-query').value = '';
    byId('genome-stats-species').value = '';
    byId('genome-stats-status-filter').value = '';
    byId('genome-stats-producer').value = '';
    state.filters = { chromosome: false, annotation: false, flag: false };
    ['genome-stats-chromosome', 'genome-stats-annotation', 'genome-stats-flag'].forEach(function (id) {
      byId(id).setAttribute('aria-pressed', 'false');
    });
    state.sortColumn = 'assembly_id';
    state.sortDirection = 1;
    state.openRow = null;
    state.page = 1;
    setVisible(state.defaultColumns);
    render();
  }

  function csvCell(value) {
    if (value === null || value === undefined) { return ''; }
    var string = String(value);
    return /[",\n]/.test(string) ? '"' + string.replace(/"/g, '""') + '"' : string;
  }

  function downloadCsv() {
    var columns = state.viewColumns;
    /* Export what the reader is looking at: the same headers and the same
       Representative/Reference/Draft and combined-species values. */
    var lines = [columns.map(statsColumnLabel).map(csvCell).join(',')];
    state.view.forEach(function (row) {
      lines.push(columns.map(function (column) { return csvCell(statsValue(row, column)); }).join(','));
    });
    var url = window.URL.createObjectURL(new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8' }));
    var link = document.createElement('a');
    link.href = url;
    link.download = 'maizegdb_assembly_statistics_demo.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.setTimeout(function () { window.URL.revokeObjectURL(url); }, 1000);
  }

  function buildFullscreen() {
    var stage = byId('genome-stats-table-stage');
    var enter = byId('genome-stats-fullscreen');
    var exit = byId('genome-stats-fullscreen-exit');
    var wasActive = false;

    function nativeElement() { return document.fullscreenElement || document.webkitFullscreenElement || null; }
    function isActive() { return nativeElement() === stage || stage.classList.contains('is-fullscreen-fallback'); }

    function sync() {
      var active = isActive();
      enter.setAttribute('aria-pressed', active ? 'true' : 'false');
      document.body.classList.toggle('genome-stats-fullscreen-open', active);
      if (active && !wasActive) { exit.focus(); }
      if (!active && wasActive) { enter.focus(); }
      wasActive = active;
    }

    function fallback() { stage.classList.add('is-fullscreen-fallback'); sync(); }

    function open() {
      if (stage.requestFullscreen) {
        try {
          var request = stage.requestFullscreen();
          if (request && request.catch) { request.catch(fallback); }
        } catch (error) { fallback(); }
      } else if (stage.webkitRequestFullscreen) {
        try { stage.webkitRequestFullscreen(); } catch (error) { fallback(); }
      } else {
        fallback();
      }
    }

    function close() {
      if (stage.classList.contains('is-fullscreen-fallback')) {
        stage.classList.remove('is-fullscreen-fallback');
        sync();
      } else if (document.exitFullscreen) {
        document.exitFullscreen();
      } else if (document.webkitExitFullscreen) {
        document.webkitExitFullscreen();
      }
    }

    enter.addEventListener('click', open);
    exit.addEventListener('click', close);
    document.addEventListener('fullscreenchange', sync);
    document.addEventListener('webkitfullscreenchange', sync);
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && stage.classList.contains('is-fullscreen-fallback')) { close(); }
    });
  }

  function updateMeta(meta) {
    var host = byId('genome-stats-meta');
    var generated = meta.generated ? new Date(meta.generated) : null;
    var generatedLabel = generated && !isNaN(generated.getTime())
      ? generated.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' })
      : 'the supplied prototype';
    clear(host);
    host.appendChild(document.createTextNode(
      Number(meta.total || state.rows.length).toLocaleString() + ' assemblies and '
      + Number(meta.annotation_versions || 0).toLocaleString() + ' annotation versions, generated '
      + generatedLabel + ' from files published at '
    ));
    var source = make('a', '', 'download.maizegdb.org');
    source.href = 'https://download.maizegdb.org/';
    source.target = '_blank';
    source.rel = 'noopener';
    host.appendChild(source);
    host.appendChild(document.createTextNode('. A dash means not measured, never zero.'));
  }

  function initialize(data) {
    if (!data || !Array.isArray(data.data) || !data.dictionary || !data.dictionary.columns) {
      throw new Error('Unexpected prototype data format');
    }
    state.rows = data.data;
    state.dictionary = data.dictionary.columns;
    state.groups = data.dictionary.groups || [];
    state.defaultColumns = data.default_columns || ['assembly_id'];
    state.columns = Object.keys(state.dictionary);
    state.numeric = {};
    (data.numeric_columns || []).forEach(function (column) { state.numeric[column] = true; });
    setVisible(state.defaultColumns);

    optionList(byId('genome-stats-species'), 'species_as_stated', 'Species');
    optionList(byId('genome-stats-status-filter'), 'status', 'Quality');
    optionList(byId('genome-stats-producer'), 'producer', 'Producer');
    buildGroups();
    updateMeta(data.meta || {});

    var timer = null;
    byId('genome-stats-query').addEventListener('input', function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(function () { state.openRow = null; state.page = 1; render(); }, 120);
    });
    ['genome-stats-species', 'genome-stats-status-filter', 'genome-stats-producer'].forEach(function (id) {
      byId(id).addEventListener('change', function () { state.openRow = null; state.page = 1; render(); });
    });
    toggleFilter('genome-stats-chromosome', 'chromosome');
    toggleFilter('genome-stats-annotation', 'annotation');
    toggleFilter('genome-stats-flag', 'flag');
    byId('genome-stats-reset').addEventListener('click', reset);
    byId('genome-stats-csv').addEventListener('click', downloadCsv);
    byId('genome-stats-page-size').addEventListener('change', function (event) {
      state.pageSize = event.target.value === 'all' ? 'all' : Number(event.target.value);
      state.page = 1;
      render();
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-stats-view]'), function (btn) {
      btn.addEventListener('click', function () {
        state.display = btn.getAttribute('data-stats-view');
        Array.prototype.forEach.call(document.querySelectorAll('[data-stats-view]'), function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
        render();
      });
    });
    buildFullscreen();

    state.loaded = true;
    byId('genome-stats-load-status').hidden = true;
    byId('genome-stats-app').hidden = false;
    render();
  }

  function fail(message) {
    var status = byId('genome-stats-load-status');
    status.classList.add('is-error');
    status.textContent = message;
    state.loading = false;
  }

  function load() {
    if (state.loaded || state.loading) { return; }
    state.loading = true;
    var loader = document.createElement('script');
    loader.async = true;
    loader.src = section.getAttribute('data-source');
    loader.onload = function () {
      try {
        initialize(window.MGDB_GENOME_STATS_DEMO);
        window.MGDB_GENOME_STATS_DEMO = null;
      } catch (error) {
        fail('The prototype statistics were returned in an unexpected format.');
      }
    };
    loader.onerror = function () {
      fail('The prototype statistics could not be loaded. The database-backed assembly search remains available above.');
    };
    document.head.appendChild(loader);
  }

  var sectionTab = document.querySelector('.mgdb-section-tabs a[href="#genome-statistics"]');
  if (sectionTab) { sectionTab.addEventListener('click', load); }

  if (window.location.hash === '#genome-statistics') {
    load();
  } else if (window.IntersectionObserver) {
    var observer = new window.IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) { observer.disconnect(); load(); }
      });
    }, { rootMargin: '600px 0px' });
    observer.observe(section);
  } else {
    window.setTimeout(load, 0);
  }

  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startPrototype);
  } else {
    startPrototype();
  }
})();
