/* ==========================================================================
   Record page engine — shared by every record page on the Data Hub shell
   --------------------------------------------------------------------------
   Companion to css/mgdb-record.css; the two are a pair and neither works
   without the other. Loaded after js/mgdb-modern.js and before a page's own
   script, which is glue: it maps one API payload onto the pieces here.

   What a page gets:

     collection()   any list, as a sortable table (the default) or a grid of
                    the same rows, with a filter, a page size (or one set of
                    rows at a time) and a TSV of exactly the columns on screen
     notes()        curator prose on the warm surface
     images()       the gallery of image cards over a lightbox, with a
                    table of the same rows behind the view toggle
     references()   the shell's reference card, paged, with a table view
     metrics()      the four cards, and the two figures under them
     tabs()         the section bar, its counts, and a scrollspy that agrees
                    with the sections' own scroll-margin
     apiCard()      the Copy JSON button on the closing API row

   Nothing here knows what a gene product or a variation is.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  if (!MGDB) { return; }

  var PAGE_SIZES = [10, 25, 50];
  var DEFAULT_PAGE_SIZE = 10;
  /* 10, the same as every other table. It was 5, from when this block opened
     on cards and five cards was a screenful; opening on the table, five rows
     under a filter, a pager and a year histogram is more chrome than content.
     The image gallery keeps its own 16: that is a 4-across grid and 10 would
     leave a ragged half-row. */
  var REFERENCE_PAGE_SIZE = DEFAULT_PAGE_SIZE;
  /* A collection shown one set at a time lists its sets as buttons up to this
     many, and in a select past it. 96% of gene models have one to four
     transcripts, but a B73 v4 model can have hundreds -- Zm00001d038675 has
     366, every one scored -- and as buttons that is twenty rows of them. */
  var GROUP_BUTTON_LIMIT = 30;

  function byId(id) { return document.getElementById(id); }
  function escape(value) { return MGDB.escapeHtml(value == null ? '' : String(value)); }
  function show(el, visible) { if (el) { el.hidden = !visible; } }
  function number(value) { return Number(value || 0).toLocaleString(); }
  function plainText(value) {
    if (value === null || value === undefined) { return ''; }
    return String(value).replace(/\s+/g, ' ').trim();
  }
  function isExternal(href) { return /^https?:\/\//i.test(href || ''); }
  function absoluteUrl(href) {
    if (!href) { return ''; }
    if (isExternal(href)) { return href; }
    return window.location.origin + (href.charAt(0) === '/' ? '' : '/') + href;
  }

  /* A link to another record, or to an external page. The stylesheet puts the
     arrow on anything with target="_blank". */
  function link(href, label, external) {
    if (!label && label !== 0) { return ''; }
    if (!href) { return escape(label); }
    var ext = external === undefined ? isExternal(href) : external;
    return '<a href="' + escape(href) + '"' + (ext ? ' target="_blank" rel="noopener"' : '') + '>' + escape(label) + '</a>';
  }

  function refLink(ref) {
    if (!ref || (!ref.name && ref.name !== 0)) { return ''; }
    return link(ref.html, ref.name);
  }

  function fact(label, value, note) {
    if (!value && value !== 0) { return ''; }
    return '<div><dt>' + escape(label) + '</dt><dd>' + value + (note ? '<small>' + escape(note) + '</small>' : '') + '</dd></div>';
  }

  function facts(pairs) {
    var html = pairs.map(function (pair) { return fact(pair[0], pair[1], pair[2]); }).join('');
    return html === '' ? '' : '<div class="mgdb-rec-block"><dl class="mgdb-rec-facts">' + html + '</dl></div>';
  }

  /* ------------------------------------------------------------------------
     Collections
     ------------------------------------------------------------------------ */

  var collectionSeq = 0;

  /* collection(target, spec) appends one list block to `target`.

       title      block heading
       items      the rows
       columns    [{ key, label, sort: 'text'|'number', numeric, tile,
                     get(item) -> plain text, html(item) -> markup,
                     tsv(item) -> plain text, tsvOnly }]
       filename   for the TSV download
       pageSize   initial page size (default 10)
       view       'table' (default) or 'grid'
       empty      message to render when there are no rows
       cardHtml   builds the grid card itself, in place of the default
                  definition list -- what the image gallery uses
       gridClass  extra class on the grid container
       onRender   called with the body element after every render, for a view
                  that has to bind its own controls
       views      extra views beside Table and Grid: [{ key, label, icon (an
                  svg string), render(rows) -> markup }]. The toggle shows
                  them in order; `view` may name one of them as the start.
       groups     show the rows one set at a time instead of in pages -- a
                  gene's scores one transcript at a time:
                  { label, noun: [one, many], key(item) -> set id,
                    sets: [{ id, label, name, note, marked }], current }.
                  The picker lists the sets in the order given and opens on
                  `current`. Every row of the open set is shown, so there is
                  no page size and no pager; the filter and the sort work
                  inside the set, and the TSV still carries every set, as it
                  carried every page. A set with no rows gets no button.

     Returns true when it rendered rows, so a caller can decide whether its
     section has anything in it. */
  function collection(target, spec) {
    var items = spec.items || [];
    if (!items.length) {
      if (spec.empty) {
        target.insertAdjacentHTML('beforeend', '<div class="mgdb-rec-block"><div class="mgdb-rec-block-head"><h3>' +
          escape(spec.title) + '</h3></div><p class="mgdb-rec-empty">' + escape(spec.empty) + '</p></div>');
      }
      return false;
    }

    var key = 'mrc' + (++collectionSeq);
    var state = {
      view: spec.view || 'table',
      size: spec.pageSize || DEFAULT_PAGE_SIZE,
      page: 1,
      query: '',
      sortKey: null,
      sortDir: 'ascending'
    };
    var allColumns = spec.columns;
    var columns = allColumns.filter(function (c) { return !c.tsvOnly; });
    var titleColumn = columns.filter(function (c) { return c.tile; })[0] || columns[0];

    /* One set at a time (spec.groups). The sets are the listed ones that have
       rows, in the order listed; a row whose set is not listed gets a set of
       its own at the end rather than going quietly unreachable, since the
       count in the heading includes it. */
    var groups = null;
    function groupOf(item) {
      var id = groups.key(item);
      return id == null ? '' : String(id);
    }
    if (spec.groups && spec.groups.key) {
      groups = {
        label: spec.groups.label || 'Set',
        noun: spec.groups.noun || ['row', 'rows'],
        key: spec.groups.key,
        sets: []
      };
      var seen = {}, unlisted = [];
      items.forEach(function (item) {
        var id = groupOf(item);
        if (!seen[id]) { seen[id] = true; unlisted.push(id); }
      });
      (spec.groups.sets || []).forEach(function (s) {
        var id = String(s.id);
        if (seen[id] === true) {
          seen[id] = 'listed';
          groups.sets.push({ id: id, label: s.label, name: s.name, note: s.note, marked: !!s.marked });
        }
      });
      unlisted.forEach(function (id) { if (seen[id] === true) { groups.sets.push({ id: id }); } });
      var opening = groups.sets.filter(function (s) { return s.id === String(spec.groups.current); })[0] || groups.sets[0];
      state.group = opening.id;
      state.size = 'all';
    }
    function setName(s) { return s.name || s.id || '—'; }
    function setLabel(s) { return s.label || setName(s); }
    function currentSet() {
      return groups.sets.filter(function (s) { return s.id === state.group; })[0];
    }

    /* Buttons, like the pager they replace; past GROUP_BUTTON_LIMIT, a select
       with Prev and Next. Above the rows rather than below them, because the
       choice decides what the rows are, and because sets differ in length --
       under them, the button just clicked would move out from under the
       pointer whenever the next set had a row more or less. */
    function groupsHtml() {
      if (!groups) { return ''; }
      var noun = escape(groups.label.toLowerCase());
      if (groups.sets.length <= GROUP_BUTTON_LIMIT) {
        return '<div class="mgdb-rec-toolbar mgdb-rec-groups" role="group" aria-labelledby="' + key + '-group">' +
          '<span class="mgdb-rec-groups-label" id="' + key + '-group">' + escape(groups.label) + '</span>' +
          '<div class="mgdb-rec-group-btns">' + groups.sets.map(function (s, i) {
            var on = s.id === state.group;
            return '<button class="mgdb-rec-page-btn mgdb-rec-group-btn' + (on ? ' is-active' : '') + '" type="button"' +
              ' data-group="' + i + '" aria-pressed="' + on + '" title="' + escape(setName(s) + (s.note ? ', ' + s.note : '')) + '">' +
              escape(setLabel(s)) +
              (s.marked ? '<span class="mgdb-rec-group-mark" aria-hidden="true">★</span>' : '') +
              (s.note ? '<span class="mgdb-visually-hidden">, ' + escape(s.note) + '</span>' : '') +
            '</button>';
          }).join('') + '</div>' +
        '</div>';
      }
      return '<div class="mgdb-rec-toolbar mgdb-rec-groups">' +
        '<label>' + escape(groups.label) + ' <select data-role="group">' + groups.sets.map(function (s, i) {
          return '<option value="' + i + '"' + (s.id === state.group ? ' selected' : '') + '>' +
            escape(setLabel(s) + (s.note ? ' — ' + s.note : '')) + '</option>';
        }).join('') + '</select></label>' +
        '<button class="mgdb-rec-page-btn" type="button" data-step="-1" aria-label="Previous ' + noun + '">&larr; Prev</button>' +
        '<button class="mgdb-rec-page-btn" type="button" data-step="1" aria-label="Next ' + noun + '">Next &rarr;</button>' +
      '</div>';
    }

    /* A block may ask for a size that is not one of the standard three -- the
       image gallery pages at sixteen, a four-by-four grid. Without adding it
       to the list the select would show 10 while the block paged at 16. */
    var sizes = PAGE_SIZES.slice();
    if (typeof state.size === 'number' && sizes.indexOf(state.size) === -1) {
      sizes.push(state.size);
      sizes.sort(function (a, b) { return a - b; });
    }
    var sizeOptions = sizes.map(function (n) {
      return '<option value="' + n + '"' + (n === state.size ? ' selected' : '') + '>' + n + '</option>';
    }).join('') + '<option value="all"' + (state.size === 'all' ? ' selected' : '') + '>All</option>';

    target.insertAdjacentHTML('beforeend', '<div class="mgdb-rec-block" data-collection="' + key + '">' +
      '<div class="mgdb-rec-block-head">' +
        '<h3>' + escape(spec.title) + '<span class="mgdb-rec-block-count">' + number(items.length) + '</span></h3>' +
        '<div class="mgdb-rec-toolbar">' +
          '<label>Filter <input type="search" data-role="filter" placeholder="Within ' + escape(spec.title.toLowerCase()) + '" aria-label="Filter ' + escape(spec.title) + '"></label>' +
          '<div class="mgdb-view-toggle" role="group" aria-label="' + escape(spec.title) + ' view">' +
            '<button class="mgdb-view-btn" type="button" data-view="table" aria-pressed="' + (state.view === 'table') + '">' +
              '<svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="1" y="2" width="14" height="2" rx="1" fill="currentColor"/><rect x="1" y="7" width="14" height="2" rx="1" fill="currentColor"/><rect x="1" y="12" width="14" height="2" rx="1" fill="currentColor"/></svg>Table</button>' +
            '<button class="mgdb-view-btn" type="button" data-view="grid" aria-pressed="' + (state.view === 'grid') + '">' +
              '<svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="1" y="1" width="6" height="6" rx="1" fill="currentColor"/><rect x="9" y="1" width="6" height="6" rx="1" fill="currentColor"/><rect x="1" y="9" width="6" height="6" rx="1" fill="currentColor"/><rect x="9" y="9" width="6" height="6" rx="1" fill="currentColor"/></svg>Cards</button>' +
            (spec.views || []).map(function (v) {
              return '<button class="mgdb-view-btn" type="button" data-view="' + escape(v.key) + '" aria-pressed="' + (state.view === v.key) + '">' +
                (v.icon || '') + escape(v.label) + '</button>';
            }).join('') +
          '</div>' +
          (groups ? '' : '<label>Show <select data-role="size" aria-label="Rows per page">' + sizeOptions + '</select></label>') +
          '<button class="mgdb-rec-tsv" type="button" data-role="tsv">Download TSV</button>' +
        '</div>' +
      '</div>' +
      groupsHtml() +
      '<p class="mgdb-rec-block-status" data-role="status" aria-live="polite"></p>' +
      '<div data-role="body"></div>' +
      '<nav class="mgdb-rec-pagination" data-role="pagination" aria-label="' + escape(spec.title) + ' pages" hidden></nav>' +
    '</div>');

    var block = target.querySelector('[data-collection="' + key + '"]');
    var body = block.querySelector('[data-role="body"]');
    var status = block.querySelector('[data-role="status"]');
    var pagination = block.querySelector('[data-role="pagination"]');

    function textOf(col, item) { return plainText(col.get ? col.get(item) : item[col.key]); }
    function cellOf(col, item) {
      if (col.html) { return col.html(item); }
      var text = textOf(col, item);
      return text === '' ? '<span class="mgdb-muted">—</span>' : escape(text);
    }

    /* Every row the filter lets through, sorted. This is what the TSV takes. */
    function matching() {
      var rows = items.slice();
      if (state.query) {
        var needle = state.query.toLowerCase();
        rows = rows.filter(function (item) {
          return columns.some(function (col) { return textOf(col, item).toLowerCase().indexOf(needle) !== -1; });
        });
      }
      if (state.sortKey) {
        var col = columns.filter(function (c) { return c.key === state.sortKey; })[0];
        if (col) {
          var dir = state.sortDir === 'ascending' ? 1 : -1;
          var numeric = col.sort === 'number';
          rows.sort(function (a, b) {
            var av = textOf(col, a), bv = textOf(col, b);
            // Missing values always sort last, regardless of direction.
            if (av === '' && bv === '') { return 0; }
            if (av === '') { return 1; }
            if (bv === '') { return -1; }
            if (numeric) {
              var an = parseFloat(av.replace(/,/g, '')), bn = parseFloat(bv.replace(/,/g, ''));
              if (!isNaN(an) && !isNaN(bn)) { return (an - bn) * dir; }
            }
            return av.localeCompare(bv, undefined, { numeric: true, sensitivity: 'base' }) * dir;
          });
        }
      }
      return rows;
    }

    /* What the views draw: the matching rows, and of those only the open
       set's when the block is shown one set at a time. */
    function filtered() {
      var rows = matching();
      return groups ? rows.filter(function (item) { return groupOf(item) === state.group; }) : rows;
    }

    /* "Showing all 15 scores for Zm00001eb374090_T004, the canonical
       transcript." The full name, because the button carries the short one. */
    function groupStatus(shown) {
      if (!shown) { return ''; }
      var set = currentSet();
      var inSet = items.filter(function (item) { return groupOf(item) === set.id; }).length;
      var noun = inSet === 1 ? groups.noun[0] : groups.noun[1];
      var count = shown === inSet
        ? (inSet === 1 ? '1 ' + noun : 'all ' + number(inSet) + ' ' + noun)
        : number(shown) + ' of ' + number(inSet) + ' ' + noun;
      return 'Showing ' + count + ' for ' + setName(set) + (set.note ? ', ' + set.note : '') + '.';
    }

    function renderTable(rows) {
      var head = columns.map(function (col) {
        var sortable = col.sort !== false;
        var aria = state.sortKey === col.key ? state.sortDir : 'none';
        return '<th scope="col"' + (col.numeric ? ' class="mgdb-numeric"' : '') +
          (sortable ? ' data-sort="' + (col.sort || 'text') + '" aria-sort="' + aria + '"' : '') + '>' +
          (sortable ? '<button type="button" data-sort-key="' + escape(col.key) + '">' + escape(col.label) + '</button>' : escape(col.label)) +
          '</th>';
      }).join('');
      var bodyRows = rows.map(function (item) {
        return '<tr>' + columns.map(function (col, i) {
          var cell = cellOf(col, item);
          return i === 0 ? '<th scope="row">' + cell + '</th>' : '<td' + (col.numeric ? ' class="mgdb-numeric"' : '') + '>' + cell + '</td>';
        }).join('') + '</tr>';
      }).join('');
      return '<div class="mgdb-table-scroll" tabindex="0" role="region" aria-label="' + escape(spec.title) + ' table">' +
        '<table class="mgdb-table mgdb-rec-table"><thead><tr>' + head + '</tr></thead><tbody>' + bodyRows + '</tbody></table></div>';
    }

    function renderGrid(rows) {
      var cls = 'mgdb-card-grid mgdb-rec-grid' + (spec.gridClass ? ' ' + spec.gridClass : '');
      if (spec.cardHtml) {
        return '<div class="' + cls + '">' + rows.map(spec.cardHtml).join('') + '</div>';
      }
      return '<div class="' + cls + '">' + rows.map(function (item) {
        var title = cellOf(titleColumn, item);
        var rest = columns.filter(function (col) { return col !== titleColumn; }).map(function (col) {
          return '<div><dt>' + escape(col.label) + '</dt><dd>' + cellOf(col, item) + '</dd></div>';
        }).join('');
        return '<article class="mgdb-card"><p class="mgdb-rec-tile-title">' + title + '</p><dl>' + rest + '</dl></article>';
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

      var extraView = (spec.views || []).filter(function (v) { return v.key === state.view; })[0];
      body.innerHTML = total === 0
        ? '<p class="mgdb-rec-empty">Nothing in ' + escape(spec.title.toLowerCase()) +
          (groups ? ' for ' + escape(setName(currentSet())) : '') + ' matches “' + escape(state.query) + '”.</p>'
        : (extraView ? extraView.render(pageRows) : (state.view === 'grid' ? renderGrid(pageRows) : renderTable(pageRows)));

      status.textContent = groups ? groupStatus(total) : total === 0 ? '' :
        (total === items.length
          ? (total > size ? 'Showing ' + (start + 1) + '–' + Math.min(start + size, total) + ' of ' + number(total) : '')
          : number(total) + ' of ' + number(items.length) + ' shown' +
            (total > size ? ', ' + (start + 1) + '–' + Math.min(start + size, total) + ' on this page' : ''));

      Array.prototype.forEach.call(body.querySelectorAll('button[data-sort-key]'), function (btn) {
        btn.addEventListener('click', function () {
          var next = btn.getAttribute('data-sort-key');
          if (state.sortKey === next) {
            state.sortDir = state.sortDir === 'ascending' ? 'descending' : 'ascending';
          } else {
            state.sortKey = next;
            state.sortDir = 'ascending';
          }
          render();
          MGDB.announce(spec.title + ' sorted by ' + btn.textContent + ', ' + state.sortDir + '.');
        });
      });

      if (spec.onRender) { spec.onRender(body, state); }
      renderPagination(pages);
    }

    function renderPagination(pages) {
      if (pages <= 1) { show(pagination, false); pagination.innerHTML = ''; return; }
      var html = '<button class="mgdb-rec-page-btn" type="button" data-page="' + (state.page - 1) + '"' +
        (state.page === 1 ? ' disabled' : '') + ' aria-label="Previous page">&larr; Prev</button>';
      var shown = [];
      for (var p = 1; p <= pages; p++) {
        if (p === 1 || p === pages || (p >= state.page - 1 && p <= state.page + 1)) {
          shown.push(p);
        } else if (shown[shown.length - 1] !== '…') {
          shown.push('…');
        }
      }
      shown.forEach(function (p) {
        if (p === '…') {
          html += '<span class="mgdb-rec-page-ellipsis" aria-hidden="true">&hellip;</span>';
        } else {
          var active = p === state.page;
          html += '<button class="mgdb-rec-page-btn' + (active ? ' is-active' : '') + '" type="button" data-page="' + p + '"' +
            (active ? ' aria-current="page"' : '') + '>' + p + '</button>';
        }
      });
      html += '<button class="mgdb-rec-page-btn" type="button" data-page="' + (state.page + 1) + '"' +
        (state.page === pages ? ' disabled' : '') + ' aria-label="Next page">Next &rarr;</button>';
      pagination.innerHTML = html;
      show(pagination, true);
      Array.prototype.forEach.call(pagination.querySelectorAll('[data-page]'), function (btn) {
        btn.addEventListener('click', function () {
          var next = parseInt(btn.getAttribute('data-page'), 10);
          if (isNaN(next) || next < 1 || next > pages || next === state.page) { return; }
          state.page = next;
          render();
          block.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
      });
    }

    block.querySelector('[data-role="filter"]').addEventListener('input', MGDB.debounce(function (event) {
      state.query = event.target.value.trim();
      state.page = 1;
      render();
    }, 150));

    var sizeSelect = block.querySelector('[data-role="size"]');
    if (sizeSelect) {
      sizeSelect.addEventListener('change', function (event) {
        state.size = event.target.value === 'all' ? 'all' : Number(event.target.value);
        state.page = 1;
        render();
      });
    }

    /* Opening a set redraws the rows and moves the pressed state; focus stays
       on the control that was used, and the status line, which is aria-live,
       says what is now shown. */
    var groupRow = groups ? block.querySelector('.mgdb-rec-groups') : null;
    function syncSteps() {
      var index = groups.sets.indexOf(currentSet());
      Array.prototype.forEach.call(groupRow.querySelectorAll('[data-step]'), function (btn) {
        var to = index + Number(btn.getAttribute('data-step'));
        btn.disabled = to < 0 || to >= groups.sets.length;
      });
    }
    function openSet(index) {
      var s = groups.sets[index];
      if (!s || s.id === state.group) { return; }
      state.group = s.id;
      Array.prototype.forEach.call(groupRow.querySelectorAll('[data-group]'), function (btn) {
        var on = Number(btn.getAttribute('data-group')) === index;
        btn.classList.toggle('is-active', on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      var select = groupRow.querySelector('select[data-role="group"]');
      if (select) { select.value = String(index); }
      syncSteps();
      render();
    }
    if (groupRow) {
      Array.prototype.forEach.call(groupRow.querySelectorAll('[data-group]'), function (btn) {
        btn.addEventListener('click', function () { openSet(Number(btn.getAttribute('data-group'))); });
      });
      var groupSelect = groupRow.querySelector('select[data-role="group"]');
      if (groupSelect) {
        groupSelect.addEventListener('change', function () { openSet(Number(groupSelect.value)); });
      }
      Array.prototype.forEach.call(groupRow.querySelectorAll('[data-step]'), function (btn) {
        btn.addEventListener('click', function () {
          openSet(groups.sets.indexOf(currentSet()) + Number(btn.getAttribute('data-step')));
        });
      });
      syncSteps();
    }

    Array.prototype.forEach.call(block.querySelectorAll('.mgdb-view-btn'), function (btn) {
      btn.addEventListener('click', function () {
        state.view = btn.getAttribute('data-view');
        Array.prototype.forEach.call(block.querySelectorAll('.mgdb-view-btn'), function (b) {
          b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
        });
        render();
      });
    });

    block.querySelector('[data-role="tsv"]').addEventListener('click', function () {
      downloadTsv(spec.filename || (spec.title.toLowerCase().replace(/[^a-z0-9]+/g, '-') + '.tsv'), allColumns, matching());
    });

    render();
    return true;
  }

  /* Tabs and newlines are the field and record separators, so any inside a
     value are replaced rather than quoted: TSV has no agreed quoting rule. */
  function downloadTsv(filename, columns, rows) {
    var lines = [columns.map(function (c) { return c.label; }).join('\t')];
    rows.forEach(function (item) {
      lines.push(columns.map(function (c) {
        var text = c.tsv ? c.tsv(item) : (c.get ? c.get(item) : item[c.key]);
        return plainText(text).replace(/[\t\r\n]+/g, ' ');
      }).join('\t'));
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

  /* A column that links to a record and downloads as the record's name. */
  function recordColumn(label, key, getRef) {
    return {
      key: key, label: label, tile: true,
      get: function (item) { var r = getRef(item); return r ? r.name : ''; },
      html: function (item) { var r = getRef(item); return r ? (refLink(r) || escape(r.name)) : '—'; }
    };
  }

  /* Absolute, because a relative path is useless once the TSV leaves the
     browser. The link is already the first column on screen, so this one goes
     only into the download. */
  function urlColumn(getHref) {
    return { key: 'url', label: 'MaizeGDB URL', sort: false, tsvOnly: true,
             get: function (item) { return absoluteUrl(getHref(item)); } };
  }

  /* ------------------------------------------------------------------------
     Curator prose
     ------------------------------------------------------------------------ */

  /* items: [{ text, meta: ['Comment', 'Source: …'] }] */
  function notes(target, title, items) {
    if (!items || !items.length) { return false; }
    target.insertAdjacentHTML('beforeend',
      '<div class="mgdb-rec-block"><div class="mgdb-rec-block-head"><h3>' + escape(title) +
      '<span class="mgdb-rec-block-count">' + number(items.length) + '</span></h3></div>' +
      '<div class="mgdb-rec-notes">' + items.map(function (item) {
        var meta = (item.meta || []).filter(Boolean);
        return '<div class="mgdb-rec-note"><p>' + escape(item.text) + '</p>' +
               (meta.length ? '<small>' + meta.join(' · ') + '</small>' : '') + '</div>';
      }).join('') + '</div></div>');
    return true;
  }

  /* ------------------------------------------------------------------------
     Images

     The gallery the stock record page settled on: a card per image with the
     picture, a category chip, a linked title, the caption, and a row of
     actions, over a lightbox. It is a collection like any other, so the same
     block also offers a table of the same rows, a filter, a page size and a
     TSV -- the gallery is simply its grid view, and the default.

     items: [{ url, caption, part, type, title, category, record }]. Only url
     is required; a page maps its own payload onto the rest.
     ------------------------------------------------------------------------ */

  function images(target, items, dialogId, spec) {
    if (!items || !items.length) { return false; }
    spec = spec || {};
    target.innerHTML = '';

    function title(item, index) { return item.title || item.caption || ('Image ' + (index + 1)); }
    function category(item) { return item.category || item.part || item.type || 'Image'; }

    var rows = items.map(function (item, index) {
      return {
        url: item.url,
        /* The card shows the FULL image, not the `downsized/` variant.
        
           Those are capped at 100px on the width -- measured across
           GelPattern, Variation and Phenotype: 100x137, 100x46, 100x67 -- while
           the card's image box is 258x193 CSS pixels, which needs 516x386
           device pixels on a 2x display. Using them upscales roughly 5x and
           every preview reads as out of focus. The image server has no
           mid-size variant and does not resize on request, so the full image
           is the only sharp source available.
        
           `thumbnail` is still carried in the API payload for a client that
           wants a 100px icon; it is just not what this card is sized for.
           `loading="lazy"` keeps offscreen cards from fetching. */
        thumb: item.url,
        title: title(item, index),
        category: category(item),
        caption: item.caption || '',
        record: item.record || ''
      };
    });

    function card(row) {
      var name = row.record ? link(row.record, row.title) : escape(row.title);
      return '<article class="mgdb-rec-image-card">' +
        '<div>' +
          '<figure class="mgdb-rec-image-figure" data-image="' + escape(row.url) + '">' +
            /* Two fallbacks, in order: whatever the card asked for, then the
               full image, then the site mark for a record naming a file the
               image server no longer has. The middle step matters because not
               every image has a generated variant -- every sampled
               db_images/Term/.../downsized/ path is a 404 while the full image
               is fine. */
            '<img src="' + escape(row.thumb) + '" alt="' + escape(row.caption || row.title) + '" loading="lazy" ' +
              'data-full="' + escape(row.url) + '" ' +
              'onerror="if(this.dataset.full&&this.src!==this.dataset.full){this.src=this.dataset.full;return;}' +
              'this.onerror=null;this.src=\'/images/logo.png\';this.style.objectFit=\'contain\';this.style.padding=\'16px\';">' +
          '</figure>' +
          '<div class="mgdb-rec-image-body">' +
            '<div class="mgdb-rec-image-meta"><span class="mgdb-rec-image-badge" data-cat="' + escape(row.category) + '">' + escape(row.category) + '</span></div>' +
            '<h3>' + name + '</h3>' +
            (row.caption ? '<p class="mgdb-rec-image-caption">' + escape(row.caption) + '</p>' : '') +
          '</div>' +
        '</div>' +
        '<div class="mgdb-rec-image-links">' +
          '<button class="mgdb-rec-image-btn" type="button" data-image="' + escape(row.url) + '">Zoom</button>' +
          (row.record ? '<a class="mgdb-rec-image-btn" href="' + escape(row.record) + '">Record</a>' : '') +
          '<a class="mgdb-rec-image-btn" href="' + escape(row.url) + '" target="_blank" rel="noopener">Open file</a>' +
          '<button class="mgdb-rec-image-btn mgdb-ref-copy" type="button" data-copy-value="' + escape(row.url) + '">Copy URL</button>' +
        '</div>' +
      '</article>';
    }

    var byUrl = {};
    rows.forEach(function (row) { byUrl[row.url] = row; });

    function openLightbox(url) {
      var dialog = byId(dialogId);
      var row = byUrl[url];
      if (!dialog || !row) { return; }
      var img = dialog.querySelector('img');
      img.src = row.url;
      img.alt = row.caption || row.title;
      var badge = dialog.querySelector('.mgdb-rec-image-badge');
      if (badge) { badge.textContent = row.category; badge.setAttribute('data-cat', row.category); }
      var heading = dialog.querySelector('h3');
      if (heading) { heading.textContent = row.title; }
      var caption = dialog.querySelector('.mgdb-rec-lightbox-caption');
      if (caption) { caption.textContent = row.caption; caption.hidden = !row.caption; }
      var record = dialog.querySelector('[data-role="record"]');
      if (record) { record.hidden = !row.record; if (row.record) { record.href = row.record; } }
      var file = dialog.querySelector('[data-role="file"]');
      if (file) { file.href = row.url; }
      var copy = dialog.querySelector('[data-role="copy"]');
      if (copy) { copy.setAttribute('data-copy-value', row.url); }
      MGDB.initCopyButtons();
      if (dialog.showModal) { dialog.showModal(); }
    }

    var dialog = byId(dialogId);
    if (dialog && !dialog.hasAttribute('data-bound')) {
      dialog.setAttribute('data-bound', '');
      var close = dialog.querySelector('.mgdb-rec-lightbox-close');
      if (close) { close.addEventListener('click', function () { dialog.close(); }); }
      dialog.addEventListener('click', function (event) { if (event.target === dialog) { dialog.close(); } });
    }

    return collection(target, {
      title: spec.title || 'Images',
      items: rows,
      filename: spec.filename || 'images.tsv',
      view: 'grid',
      pageSize: spec.pageSize || 16,
      gridClass: 'mgdb-rec-image-grid',
      cardHtml: card,
      columns: [
        { key: 'title', label: 'Subject', tile: true,
          html: function (r) { return r.record ? link(r.record, r.title) : escape(r.title); } },
        { key: 'category', label: 'Category' },
        { key: 'caption', label: 'Caption' },
        { key: 'file', label: 'Image file', sort: false, get: function (r) { return absoluteUrl(r.url); },
          html: function (r) { return link(r.url, 'Open', true); } },
        { key: 'record_url', label: 'MaizeGDB URL', sort: false, tsvOnly: true,
          get: function (r) { return r.record ? absoluteUrl(r.record) : ''; } }
      ],
      onRender: function (body) {
        Array.prototype.forEach.call(body.querySelectorAll('[data-image]'), function (el) {
          el.addEventListener('click', function () { openLightbox(el.getAttribute('data-image')); });
        });
        MGDB.initCopyButtons();
      }
    });
  }

  /* ------------------------------------------------------------------------
     References

     The shell's reference card is the default view, the same markup
     include/references_lib.php emits, built here from the API's rows. A table
     of the same rows is the alternative. Both page five at a time.
     ------------------------------------------------------------------------ */

  /* The API sends the bare PMID; see include/reference_ids_lib.php. */
  function pubmedUrl(pmid) {
    return 'https://pubmed.ncbi.nlm.nih.gov/' + encodeURIComponent(String(pmid)) + '/';
  }

  function referenceCard(ref, seq, idPrefix) {
    var title = ref.title || ref.citation || 'Untitled reference';
    var url = ref.doi ? 'https://doi.org/' + ref.doi : '';
    var plain = (ref.authors ? ref.authors + ' ' : '') + (ref.year ? '(' + ref.year + ') ' : '') + title + '. ' +
                (ref.citation ? ref.citation + '.' : '') + (ref.doi ? ' doi:' + ref.doi : '');
    var citeId = idPrefix + '-cite-' + seq;

    var meta = '<span class="mgdb-ref-badge">' + escape(ref.pub_type || 'Journal article') + (ref.year ? ' &bull; ' + escape(ref.year) : '') + '</span>';
    if (ref.relevance) { meta += '<span class="mgdb-ref-badge">' + escape(ref.relevance) + '</span>'; }
    if (ref.doi) { meta += '<span class="mgdb-ref-doi">DOI: ' + escape(ref.doi) + '</span>'; }

    var html = '<article class="mgdb-ref">';
    html += '<div class="mgdb-ref-meta">' + meta + '</div>';
    html += '<h3 class="mgdb-ref-title"><a href="' + escape(ref.html) + '">' + escape(title) + '</a></h3>';
    if (ref.authors) { html += '<p class="mgdb-ref-authors">' + escape(ref.authors) + '</p>'; }
    if (ref.citation) { html += '<p class="mgdb-ref-citation">' + escape(ref.citation) + '</p>'; }
    if (ref.abstract && ref.abstract.length > 120) {
      html += '<div class="mgdb-ref-abstract"><h4>Abstract</h4><p>' + escape(ref.abstract) + (ref.abstract.length >= 695 ? '…' : '') + '</p></div>';
    }
    html += '<div class="mgdb-ref-actions">';
    if (url) { html += '<a class="mgdb-button mgdb-button-primary" href="' + escape(url) + '" target="_blank" rel="noopener">Full text</a>'; }
    if (ref.pubmed) { html += '<a class="mgdb-button mgdb-button-quiet" href="' + escape(pubmedUrl(ref.pubmed)) + '" target="_blank" rel="noopener">PubMed</a>'; }
    html += '<a class="mgdb-button mgdb-button-quiet" href="' + escape(ref.html) + '">MaizeGDB record</a>';
    html += '<button class="mgdb-ref-copy" type="button" data-copy-target="' + citeId + '">Copy citation</button>';
    if (ref.doi) { html += '<button class="mgdb-ref-copy" type="button" data-copy-value="' + escape(ref.doi) + '">Copy DOI</button>'; }
    html += '</div>';
    html += '<div id="' + citeId + '" class="mgdb-visually-hidden">' + escape(plain) + '</div>';
    html += '</article>';
    return html;
  }

  /* ------------------------------------------------------------------------
     Publications by year

     One column per year from the first to the last, gaps included, at the
     head of the reference list, and a filter on it: a bar keeps its year,
     a decade label keeps its decade, the same click again (or the status
     line's button) lets go. The pointer is never the only way in: every bar
     carries its year and count as its accessible name, the peak year is
     labelled outright, the caption names the range, and the table view
     lists every year.
     ------------------------------------------------------------------------ */
  function referenceTimeline(host, items, setRange) {
    var counts = {};
    items.forEach(function (r) {
      var y = parseInt(r.year, 10);
      if (!isNaN(y) && y > 1000) { counts[y] = (counts[y] || 0) + 1; }
    });
    var years = Object.keys(counts).map(Number);
    if (years.length < 2) { return null; }
    var first = Math.min.apply(null, years), last = Math.max.apply(null, years);
    var span = last - first + 1;
    var max = 0, peak = first, dated = 0;
    years.sort(function (a, b) { return a - b; }).forEach(function (y) {
      dated += counts[y];
      if (counts[y] > max) { max = counts[y]; peak = y; }
    });
    var step = span <= 12 ? 1 : 10;

    var bars = '', axis = '';
    for (var y = first; y <= last; y++) {
      var n = counts[y] || 0;
      var col = y - first + 1;
      var tip = y + ' · ' + n + ' publication' + (n === 1 ? '' : 's');
      var edge = col <= 4 ? ' is-left' : (col > span - 4 ? ' is-right' : '');
      bars += '<button class="mgdb-ref-year' + (y === peak ? ' is-peak' : '') + edge + '" type="button" data-year="' + y + '"' +
        ' style="--h:' + (max ? Math.round(1000 * n / max) / 10 : 0) + '%" aria-pressed="false"' +
        ' aria-label="' + tip + (n ? ', filter to this year' : '') + '" data-tip="' + tip + '"' + (n ? '' : ' disabled') + '>' +
        '<i></i>' + (y === peak ? '<span class="mgdb-ref-year-label">' + n + '</span>' : '') + '</button>';
      if (y % step === 0) {
        var to = Math.min(last, y + step - 1);
        var inRange = 0;
        for (var k = y; k <= to; k++) { inRange += counts[k] || 0; }
        axis += '<button class="mgdb-ref-decade" type="button" style="grid-column:' + col + '" data-from="' + y + '" data-to="' + to + '" aria-pressed="false"' +
          ' aria-label="' + (step === 1 ? y : y + ' to ' + to) + ', ' + inRange + ' publication' + (inRange === 1 ? '' : 's') + (inRange ? ', filter to ' + (step === 1 ? 'this year' : 'this decade') : '') + '"' +
          (inRange ? '' : ' disabled') + '>' + y + '</button>';
      }
    }
    host.innerHTML =
      '<div class="mgdb-ref-years" role="group" aria-label="Publications by year" style="grid-template-columns:repeat(' + span + ',minmax(0,1fr))">' + bars + '</div>' +
      '<div class="mgdb-ref-axis" style="grid-template-columns:repeat(' + span + ',minmax(0,1fr))">' + axis + '</div>' +
      '<p class="mgdb-ref-timeline-caption">' + number(dated) + ' dated publication' + (dated === 1 ? '' : 's') + ', ' + first + ' to ' + last +
        ' · most in <strong>' + peak + '</strong> (' + max + ')' +
        (items.length > dated ? ' · ' + number(items.length - dated) + ' without a year' : '') +
        ' · click a ' + (step === 1 ? 'year' : 'year or a decade') + ' to filter the list</p>';

    var yearsEl = host.querySelector('.mgdb-ref-years');
    var current = null;
    function same(a, b) { return !!a && !!b && a[0] === b[0] && a[1] === b[1]; }
    host.addEventListener('click', function (event) {
      var bar = event.target.closest('[data-year]');
      var dec = event.target.closest('[data-from]');
      var range = bar ? [Number(bar.getAttribute('data-year')), Number(bar.getAttribute('data-year'))]
                : dec ? [Number(dec.getAttribute('data-from')), Number(dec.getAttribute('data-to'))] : null;
      if (!range) { return; }
      setRange(same(range, current) ? null : range);
    });
    function sync(range) {
      current = range;
      yearsEl.classList.toggle('is-filtered', !!range);
      Array.prototype.forEach.call(host.querySelectorAll('[data-year]'), function (b) {
        var y = Number(b.getAttribute('data-year'));
        b.setAttribute('aria-pressed', range && y >= range[0] && y <= range[1] ? 'true' : 'false');
      });
      Array.prototype.forEach.call(host.querySelectorAll('[data-from]'), function (b) {
        b.setAttribute('aria-pressed', same(range, [Number(b.getAttribute('data-from')), Number(b.getAttribute('data-to'))]) ? 'true' : 'false');
      });
    }
    return { sync: sync };
  }

  /* opts.timeline: draw the publications-by-year figure above the list and
     let it filter the list. Off unless a page asks. */
  function references(target, items, section, idPrefix, opts) {
    if (!items || !items.length) { return false; }
    opts = opts || {};
    /* The element or its id. Six pages (gel, locus, primer, qtl,
       recombination, term) pass the id, and the pager's
       `section.scrollIntoView` then threw on a string: the next page drew but
       the list never scrolled back to its top. */
    if (typeof section === 'string') { section = document.getElementById(section); }
    target.innerHTML = '';
    var state = { view: 'table', page: 1, size: REFERENCE_PAGE_SIZE, query: '', years: null, sortKey: null, sortDir: 'ascending' };
    var sizeOptions = [5, 10, 25].map(function (n) {
      return '<option value="' + n + '"' + (n === state.size ? ' selected' : '') + '>' + n + '</option>';
    }).join('') + '<option value="all">All</option>';

    target.innerHTML = '<div class="mgdb-rec-block">' +
      '<div class="mgdb-rec-block-head">' +
        '<h3>Publications<span class="mgdb-rec-block-count">' + number(items.length) + '</span></h3>' +
        '<div class="mgdb-rec-toolbar">' +
          '<label>Filter <input type="search" data-role="filter" placeholder="Title, author, or year" aria-label="Filter references"></label>' +
          /* Table first and pressed, the same order and the same default as
             every collection on the page. References opened on Cards because
             the card is the richer view, but a reader arriving at a list of a
             hundred publications is scanning, and the toggle reading
             "Cards | Table" with Table lit was the odd one out of every view
             control on the record. */
          '<div class="mgdb-view-toggle" role="group" aria-label="References view">' +
            '<button class="mgdb-view-btn" type="button" data-view="table" aria-pressed="true"><svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="1" y="2" width="14" height="2" rx="1" fill="currentColor"/><rect x="1" y="7" width="14" height="2" rx="1" fill="currentColor"/><rect x="1" y="12" width="14" height="2" rx="1" fill="currentColor"/></svg>Table</button>' +
            '<button class="mgdb-view-btn" type="button" data-view="cards" aria-pressed="false"><svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="1" y="1" width="6" height="6" rx="1" fill="currentColor"/><rect x="9" y="1" width="6" height="6" rx="1" fill="currentColor"/><rect x="1" y="9" width="6" height="6" rx="1" fill="currentColor"/><rect x="9" y="9" width="6" height="6" rx="1" fill="currentColor"/></svg>Cards</button>' +
          '</div>' +
          '<label>Show <select data-role="size" aria-label="References per page">' + sizeOptions + '</select></label>' +
          '<button class="mgdb-rec-tsv" type="button" data-role="tsv">Download TSV</button>' +
        '</div>' +
      '</div>' +
      (opts.timeline ? '<div class="mgdb-ref-timeline" data-role="timeline" hidden></div>' : '') +
      '<p class="mgdb-rec-block-status" data-role="status" aria-live="polite"></p>' +
      '<div data-role="body"></div>' +
      '<nav class="mgdb-rec-pagination" data-role="pagination" aria-label="Reference pages" hidden></nav></div>';

    var block = target.firstElementChild;
    var body = block.querySelector('[data-role="body"]');
    var status = block.querySelector('[data-role="status"]');
    var pagination = block.querySelector('[data-role="pagination"]');
    var head = block.querySelector('.mgdb-rec-block-head');
    /* What the table shows.

       Abstract rather than citation: the citation repeats the title, the
       authors and the year, all of which are already in the first two columns,
       so the widest column on the table was the one saying the least. The
       abstract is clamped to four lines -- enough to tell whether the paper is
       the one you want, not so much that a page of five rows becomes a page of
       one. 94 of this record's 111 references carry one.

       Actions rather than DOI: a bare DOI string is not something anyone reads,
       it is something they follow or copy, so the column offers those two,
       and the PubMed record where there is one. */
    var columns = [
      { key: 'title', label: 'Title', tile: true, get: function (r) { return r.title || r.citation; },
        html: function (r) { return '<a href="' + escape(r.html) + '">' + escape(r.title || r.citation) + '</a>' + (r.authors ? '<small>' + escape(r.authors) + '</small>' : ''); } },
      { key: 'year', label: 'Year', sort: 'number', numeric: true },
      { key: 'abstract', label: 'Abstract', sort: false,
        html: function (r) {
          /* NOT .mgdb-ref-abstract: that class is the card view's abstract
             block and mgdb-modern.css gives it 12px/16px of padding and a green
             rule. With border-box sizing that padding came out of the four
             lines this is meant to show, which is why the preview rendered
             three and a clipped fourth.

             title carries the whole of what we hold, so hovering the preview
             reads the rest without leaving the table. The API returns the
             first 700 characters of an abstract, so that is what "the whole of
             it" means here. */
          if (!r.abstract) { return '<span class="mgdb-muted">No abstract on file</span>'; }
          /* A control, not a tooltip.

             This was a `title` attribute, which was the wrong mechanism twice
             over: it takes about a second of holding still before anything
             appears, gives no sign in the meantime that anything is coming, and
             on a touch screen never appears at all. With `cursor: help` over it
             the page was promising something the reader could not tell had
             failed to arrive.

             So the rest of the abstract opens in place. The button stays hidden
             until the pass after render finds that this particular abstract
             really does overflow four lines -- a short one has nothing more to
             show and should not offer to show it. */
          var absId = idPrefix + '-abs-' + r.id;
          return '<div class="mgdb-ref-preview-wrap">' +
            '<div class="mgdb-ref-preview" id="' + escape(absId) + '">' + escape(r.abstract) +
              (r.abstract.length >= 695 ? '…' : '') + '</div>' +
            '<button class="mgdb-ref-more" type="button" aria-expanded="false" aria-controls="' +
              escape(absId) + '" hidden>Show more</button>' +
            '</div>';
        } },
      { key: 'relevance', label: 'Relevance' },
      { key: 'actions', label: 'Actions', sort: false,
        html: function (r) {
          /* One button per line, so the column is one button wide and the
             title gets the width back. PubMed is its own way to the paper:
             on wx1, 61 of the 274 references have a PubMed ID and no DOI.
             With neither there is nothing to follow, and a dead button would
             be the usual case on older loci, so it says so instead. */
          var doi = r.doi ? String(r.doi).replace(/\.$/, '') : '';
          var out = [];
          if (doi) {
            out.push('<a class="mgdb-button mgdb-button-quiet mgdb-ref-cta" href="https://doi.org/' + escape(doi) +
              '" target="_blank" rel="noopener">View paper</a>');
          }
          if (r.pubmed) {
            out.push('<a class="mgdb-button mgdb-button-quiet mgdb-ref-cta" href="' + escape(pubmedUrl(r.pubmed)) +
              '" target="_blank" rel="noopener">PubMed</a>');
          }
          if (doi) {
            out.push('<button class="mgdb-button mgdb-button-quiet mgdb-ref-cta mgdb-ref-copy" type="button" data-copy-value="' +
              escape(doi) + '">Copy DOI</button>');
          }
          if (!out.length) { return '<span class="mgdb-muted">No DOI or PubMed ID on file</span>'; }
          return '<div class="mgdb-ref-cellactions">' + out.join('') + '</div>';
        } }
    ];

    /* The download keeps what the table gave up, and gains what the cards had:
       the citation and the DOI are still the fields a reader wants in a
       spreadsheet, and the authors and the abstract were never in the TSV at
       all. Actions is not a value and is not in it. */
    var tsvColumns = [
      { key: 'title', label: 'Title', get: function (r) { return r.title || r.citation; } },
      { key: 'authors', label: 'Authors' },
      { key: 'year', label: 'Year' },
      { key: 'citation', label: 'Citation' },
      { key: 'relevance', label: 'Relevance' },
      { key: 'doi', label: 'DOI' },
      { key: 'pubmed', label: 'PubMed ID' },
      { key: 'abstract', label: 'Abstract' }
    ];

    function filtered() {
      var rows = items.slice();
      if (state.years) {
        rows = rows.filter(function (r) { var y = parseInt(r.year, 10); return y >= state.years[0] && y <= state.years[1]; });
      }
      if (state.query) {
        var needle = state.query.toLowerCase();
        rows = rows.filter(function (r) {
          return [r.title, r.authors, r.citation, r.year, r.relevance, r.doi, r.pubmed].some(function (v) { return plainText(v).toLowerCase().indexOf(needle) !== -1; });
        });
      }
      if (state.sortKey) {
        var col = columns.filter(function (c) { return c.key === state.sortKey; })[0];
        if (col) {
          var dir = state.sortDir === 'ascending' ? 1 : -1;
          var numeric = col.sort === 'number';
          rows.sort(function (a, b) {
            var av = plainText(col.get ? col.get(a) : a[col.key]);
            var bv = plainText(col.get ? col.get(b) : b[col.key]);
            if (av === '' && bv === '') { return 0; }
            if (av === '') { return 1; }
            if (bv === '') { return -1; }
            if (numeric) {
              var an = parseFloat(av.replace(/,/g, '')), bn = parseFloat(bv.replace(/,/g, ''));
              if (!isNaN(an) && !isNaN(bn)) { return (an - bn) * dir; }
            }
            return av.localeCompare(bv, undefined, { numeric: true, sensitivity: 'base' }) * dir;
          });
        }
      }
      return rows;
    }

    /* The table this block draws for itself.

       It used to hand the rows to collection() and hide its own head. That
       traded one set of controls for another: collection() brings its own
       Table/Grid toggle, so choosing Table replaced the Cards/Table control
       with a Table/Grid one, and because collection() renders inside the body
       -- below the year histogram -- the controls moved under the chart as
       well. Same rows, same columns, drawn here instead, so the filter, the
       page size, the pager, the TSV button, the histogram and the Cards/Table
       toggle are the same controls in the same place in both views. */
    function referencesTable(pageRows) {
      var headHtml = columns.map(function (col) {
        /* Abstract and Actions opt out: sorting a table by the first letter of
           its abstracts is not a thing anyone wants, and Actions has no value
           to sort on at all. */
        if (col.sort === false) {
          return '<th scope="col" class="mgdb-ref-col-' + escape(col.key) + '">' + escape(col.label) + '</th>';
        }
        var aria = state.sortKey === col.key ? state.sortDir : 'none';
        return '<th scope="col"' + (col.numeric ? ' class="mgdb-numeric"' : '') +
          ' data-sort="' + (col.sort || 'text') + '" aria-sort="' + aria + '">' +
          '<button type="button" data-sort-key="' + escape(col.key) + '">' + escape(col.label) + '</button></th>';
      }).join('');
      var bodyHtml = pageRows.map(function (item) {
        return '<tr>' + columns.map(function (col, i) {
          var text = plainText(col.get ? col.get(item) : item[col.key]);
          var cell = col.html ? col.html(item) : (text === '' ? '<span class="mgdb-muted">&mdash;</span>' : escape(text));
          var cls = (col.numeric ? ' mgdb-numeric' : '') + ' mgdb-ref-col-' + col.key;
          return i === 0 ? '<th scope="row" class="mgdb-ref-col-' + col.key + '">' + cell + '</th>'
                         : '<td class="' + cls.trim() + '">' + cell + '</td>';
        }).join('') + '</tr>';
      }).join('');
      return '<div class="mgdb-table-scroll" tabindex="0" role="region" aria-label="References table">' +
        '<table class="mgdb-table mgdb-rec-table"><thead><tr>' + headHtml + '</tr></thead><tbody>' + bodyHtml + '</tbody></table></div>';
    }
    function scopeText() {
      if (!state.years) { return ''; }
      return state.years[0] === state.years[1] ? ' from ' + state.years[0] : ' from ' + state.years[0] + ' to ' + state.years[1];
    }
    function emptyText() {
      if (state.query) { return 'No reference' + scopeText() + ' matches “' + escape(state.query) + '”.'; }
      return 'No reference' + scopeText() + '.';
    }

    function render() {
      var rows = filtered();
      var total = rows.length;
      var size = state.size === 'all' ? Math.max(total, 1) : state.size;
      var pages = Math.max(1, Math.ceil(total / size));
      if (state.page > pages) { state.page = pages; }
      var start = (state.page - 1) * size;
      var pageRows = rows.slice(start, start + size);
      if (timeline) { timeline.sync(state.years); }

      /* The head stays put in both views. It carries the only view toggle this
         block has, and a control that disappears when you use it is not a
         control. */
      head.hidden = false;
      body.innerHTML = total === 0
        ? '<p class="mgdb-rec-empty">' + emptyText() + '</p>'
        : (state.view === 'table'
            ? referencesTable(pageRows)
            : '<div class="mgdb-ref-list">' + pageRows.map(function (r, i) { return referenceCard(r, start + i, idPrefix); }).join('') + '</div>');
      MGDB.initCopyButtons();

      Array.prototype.forEach.call(body.querySelectorAll('.mgdb-ref-preview-wrap'), function (wrap) {
        var pre = wrap.querySelector('.mgdb-ref-preview');
        var more = wrap.querySelector('.mgdb-ref-more');
        if (!pre || !more) { return; }
        more.addEventListener('click', function () {
          var open = pre.classList.toggle('is-open');
          more.setAttribute('aria-expanded', String(open));
          more.textContent = open ? 'Show less' : 'Show more';
        });
      });
      measurePreviews();

      Array.prototype.forEach.call(body.querySelectorAll('button[data-sort-key]'), function (btn) {
        btn.addEventListener('click', function () {
          var next = btn.getAttribute('data-sort-key');
          if (state.sortKey === next) {
            state.sortDir = state.sortDir === 'ascending' ? 'descending' : 'ascending';
          } else {
            state.sortKey = next;
            state.sortDir = 'ascending';
          }
          render();
          MGDB.announce('References sorted by ' + btn.textContent + ', ' + state.sortDir + '.');
        });
      });
      /* "newest first" is the order the payload arrives in, so it stops being
         true the moment a column header is used. */
      var orderText = state.sortKey
        ? ', by ' + (columns.filter(function (c) { return c.key === state.sortKey; })[0] || {}).label.toLowerCase() +
          (state.sortDir === 'ascending' ? ', low to high' : ', high to low')
        : ', newest first';
      status.innerHTML = (total === 0 ? '' : escape('Showing ' + (start + 1) + '–' + Math.min(start + size, total) + ' of ' +
        number(total) + (total !== items.length ? ' matching' : '') + ' publications' + scopeText() + orderText + '.')) +
        (state.years ? ' <button class="mgdb-ref-clear" type="button" data-role="clear-years">Show every year</button>' : '');

      if (pages <= 1) { show(pagination, false); pagination.innerHTML = ''; return; }
      var html = '<button class="mgdb-rec-page-btn" type="button" data-page="' + (state.page - 1) + '"' + (state.page === 1 ? ' disabled' : '') + ' aria-label="Previous page">&larr; Prev</button>';
      var shown = [];
      for (var p = 1; p <= pages; p++) {
        if (p === 1 || p === pages || (p >= state.page - 1 && p <= state.page + 1)) { shown.push(p); }
        else if (shown[shown.length - 1] !== '…') { shown.push('…'); }
      }
      shown.forEach(function (p) {
        if (p === '…') { html += '<span class="mgdb-rec-page-ellipsis" aria-hidden="true">&hellip;</span>'; }
        else {
          html += '<button class="mgdb-rec-page-btn' + (p === state.page ? ' is-active' : '') + '" type="button" data-page="' + p + '"' +
            (p === state.page ? ' aria-current="page"' : '') + '>' + p + '</button>';
        }
      });
      html += '<button class="mgdb-rec-page-btn" type="button" data-page="' + (state.page + 1) + '"' + (state.page === pages ? ' disabled' : '') + ' aria-label="Next page">Next &rarr;</button>';
      pagination.innerHTML = html;
      show(pagination, true);
      Array.prototype.forEach.call(pagination.querySelectorAll('[data-page]'), function (btn) {
        btn.addEventListener('click', function () {
          var next = parseInt(btn.getAttribute('data-page'), 10);
          if (isNaN(next) || next < 1 || next > pages || next === state.page) { return; }
          state.page = next;
          render();
          if (section) { section.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
        });
      });
    }

    block.querySelector('[data-role="filter"]').addEventListener('input', MGDB.debounce(function (event) {
      state.query = event.target.value.trim(); state.page = 1; render();
    }, 150));
    block.querySelector('[data-role="size"]').addEventListener('change', function (event) {
      state.size = event.target.value === 'all' ? 'all' : Number(event.target.value); state.page = 1; render();
    });
    Array.prototype.forEach.call(block.querySelectorAll('.mgdb-view-btn'), function (btn) {
      btn.addEventListener('click', function () {
        state.view = btn.getAttribute('data-view');
        Array.prototype.forEach.call(block.querySelectorAll('.mgdb-view-btn'), function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
        render();
      });
    });
    block.querySelector('[data-role="tsv"]').addEventListener('click', function () {
      downloadTsv('references.tsv', tsvColumns, filtered());
    });
    status.addEventListener('click', function (event) {
      if (event.target.closest('[data-role="clear-years"]')) { state.years = null; state.page = 1; render(); }
    });
    var timeline = null;
    if (opts.timeline) {
      var timelineHost = block.querySelector('[data-role="timeline"]');
      timeline = referenceTimeline(timelineHost, items, function (range) { state.years = range; state.page = 1; render(); });
      show(timelineHost, !!timeline);
    }

    /* Whether an abstract overflows its four lines can only be known once it
       has been laid out, so Show more is unhidden by measuring rather than
       guessed at in the markup. It runs after every render, because filtering,
       sorting and paging all bring different rows -- and again whenever the
       list changes width. A list rendered inside a hidden view (the gene
       record's Genetic information) measures 0 against 0 and would never show
       the button; the width going from 0 to real is the moment it can. A
       resize can also move an abstract across its fourth line either way. */
    function measurePreviews() {
      Array.prototype.forEach.call(body.querySelectorAll('.mgdb-ref-preview-wrap'), function (wrap) {
        var pre = wrap.querySelector('.mgdb-ref-preview');
        var more = wrap.querySelector('.mgdb-ref-more');
        if (!pre || !more || pre.classList.contains('is-open') || !pre.clientHeight) { return; }
        more.hidden = pre.scrollHeight <= pre.clientHeight + 1;
      });
    }
    if (window.ResizeObserver) {
      var measuredWidth = 0;
      new window.ResizeObserver(function () {
        var width = body.clientWidth;
        if (!width || width === measuredWidth) { return; }
        measuredWidth = width;
        measurePreviews();
      }).observe(body);
    }

    render();
    return true;
  }

  /* ------------------------------------------------------------------------
     Metrics and figures
     ------------------------------------------------------------------------ */

  function metricCard(title, badge, value, description, tone) {
    return '<article class="mgdb-metric mgdb-tone-' + tone + '">' +
      '<div class="mgdb-metric-top"><h3>' + escape(title) + '</h3><span class="mgdb-metric-badge">' + escape(badge) + '</span></div>' +
      '<div class="mgdb-metric-stat"><strong class="mgdb-metric-value">' + number(value) + '</strong></div>' +
      '<p class="mgdb-metric-description">' + escape(description) + '</p></article>';
  }

  function metrics(target, cards) {
    target.innerHTML = cards.map(function (card) {
      return metricCard(card[0], card[1], card[2], card[3], card[4]);
    }).join('');
  }

  function sizeChart(id, height) {
    var el = byId(id);
    if (el) { el.style.height = height + 'px'; }
    return height;
  }

  /* A chart drawn while its container has no width falls back to Plotly's
     700px default, which then escapes the box and stretches the document.
     MGDB.chart redraws on window resize; this catches the container changing
     width on its own -- a section revealed, a details panel opened. */
  function watchChartWidth(id) {
    var el = byId(id);
    if (!el || !window.ResizeObserver) { return; }
    var lastWidth = 0;
    new window.ResizeObserver(function () {
      var width = el.clientWidth;
      if (!width || width === lastWidth) { return; }
      lastWidth = width;
      if (window.Plotly && window.Plotly.Plots && el.querySelector('.main-svg')) {
        window.Plotly.Plots.resize(el);
      }
    }).observe(el);
  }

  /* The height connectionsChart would choose for a series, so a page can size
     a neighbouring figure to match before either is drawn. */
  function connectionsHeight(series) {
    var rows = (series || []).filter(function (s) { return Number(s[1]) > 0; }).length;
    return Math.max(300, rows * 34 + 80);
  }

  /* How much of the database touches this record, as one horizontal bar per
     kind. series is [[label, count], ...]; zeroes are dropped. */
  /* height is optional: a page that draws a second figure beside this one
     passes connectionsHeight() so the two boxes come out the same shape. */
  function connectionsChart(chartId, captionId, figureId, series, height) {
    var rows = series.filter(function (s) { return Number(s[1]) > 0; });
    if (!rows.length || !MGDB.chart) { show(byId(figureId), false); return false; }

    var labels = rows.map(function (s) { return s[0]; }).reverse();
    var values = rows.map(function (s) { return Number(s[1]); }).reverse();
    height = sizeChart(chartId, height || connectionsHeight(series));
    var caption = byId(captionId);
    if (caption) {
      caption.textContent = 'How much of the database touches this record: ' +
        rows.map(function (s) { return number(s[1]) + ' ' + s[0].toLowerCase(); }).join(', ') + '.';
    }

    MGDB.chart({
      target: chartId,
      traces: function () {
        return [{
          type: 'bar', orientation: 'h', x: values, y: labels,
          // A leading non-breaking space is the only padding Plotly offers for
          // an outside bar label; SVG collapses a plain leading space.
          text: values.map(function (v) { return ' ' + number(v); }),
          textposition: 'outside', textangle: 0, cliponaxis: false,
          marker: { color: '#285d46' },
          hovertemplate: '%{y}<br>%{x:,}<extra></extra>'
        }];
      },
      layout: {
        height: height,
        margin: { l: 10, r: 60, t: 8, b: 44 },
        bargap: 0.3,
        xaxis: { title: { text: 'Records' }, automargin: true, rangemode: 'tozero' },
        yaxis: { type: 'category', automargin: true }
      }
    });
    watchChartWidth(chartId);
    return true;
  }

  /* height is optional, as on connectionsChart: a page drawing the two side by
     side passes connectionsHeight() so the boxes come out the same shape. */
  function yearsChart(chartId, captionId, figureId, refs, height) {
    var years = {};
    (refs || []).forEach(function (r) { if (r.year) { years[r.year] = (years[r.year] || 0) + 1; } });
    var keys = Object.keys(years).sort();
    if (keys.length < 2 || !MGDB.chart) { return false; }

    show(byId(figureId), true);
    height = sizeChart(chartId, height || 320);
    var caption = byId(captionId);
    if (caption) {
      caption.textContent = number(refs.length) + ' publications from ' + keys[0] + ' to ' + keys[keys.length - 1] + '.';
    }
    MGDB.chart({
      target: chartId,
      traces: function () {
        return [{
          type: 'bar', x: keys, y: keys.map(function (y) { return years[y]; }),
          marker: { color: '#8a5a0f' },
          hovertemplate: '%{x}<br>%{y} publication(s)<extra></extra>'
        }];
      },
      layout: {
        height: height,
        margin: { l: 40, r: 16, t: 8, b: 44 },
        xaxis: { type: 'category', title: { text: 'Year' }, automargin: true },
        yaxis: { title: { text: 'Publications' }, dtick: 1, rangemode: 'tozero', automargin: true }
      }
    });
    watchChartWidth(chartId);
    return true;
  }

  /* ------------------------------------------------------------------------
     Section tabs

     Driven by scroll, an IntersectionObserver and resize together, and the
     line the spy measures is the section's own scroll-margin, so clicking a
     tab cannot mark the section above the one it jumped to.
     ------------------------------------------------------------------------ */

  /* ------------------------------------------------------------------------
     The URL fragment, re-applied

     Every section on a record page carries `hidden` in the served HTML and is
     revealed only once the API response has been rendered. The browser's own
     jump to #section therefore happens while there is nothing to jump to, and a
     reader following a deep link lands at the top of the record instead. That is
     not a rare path: /new_genes alone builds 3,305 of these links -- 979 to
     overview, 973 to references, 840 to function, 461 to variation, 52 to
     structure -- and none of them arrived where they were aimed.

     Called once per render, from tabs(), after the sections exist.
     ------------------------------------------------------------------------ */

  var hashApplied = false;

  /* Whether the reader has actually done something, tracked from the moment this
     file runs. Scroll POSITION cannot answer that question: the browser does its
     own fragment scrolling as the target element appears, so by the time a
     record has rendered, scrollY is already large without anyone having touched
     anything. A `scrollY > 8` guard therefore reads the browser's own jump as
     "the reader is busy" and declines to fix the very thing it was added for. */
  var userMoved = false;
  var INPUT_EVENTS = ['wheel', 'touchstart', 'keydown', 'mousedown'];
  INPUT_EVENTS.forEach(function (evt) {
    window.addEventListener(evt, function () { userMoved = true; }, { passive: true, capture: true });
  });

  function focusHash(after) {
    if (hashApplied) { return; }

    var id = (window.location.hash || '').slice(1);
    if (!id) { hashApplied = true; return; }

    /* If the reader has started reading, the record has loaded around them and
       moving the page under them is worse than ignoring the fragment. */
    if (userMoved) { hashApplied = true; return; }

    var target;
    try { target = document.getElementById(id); } catch (e) { target = null; }
    if (!target) { hashApplied = true; return; }

    /* A section with no data keeps its `hidden` attribute, and scrolling to a
       display:none element lands at the top of the document -- which looks
       exactly like the bug this fixes. Leave the reader where they are. */
    if (target.hidden || !target.getBoundingClientRect().height) {
      hashApplied = true;
      return;
    }

    hashApplied = true;

    /* Hold the target in place while the record finishes settling.
     *
     * A single jump at render time is not enough: the page keeps growing and
     * shrinking after its sections are revealed -- charts size themselves,
     * collections collapse from their loading height, tables paginate -- and a
     * jump taken then ended up 6,190px from the section. Waiting for the
     * document height to hold still is not enough either, because on the gene
     * record it does not hold still inside any deadline worth waiting for; the
     * jump fired on the timeout, mid-reflow, and stranded the reader 23,051px
     * away.
     *
     * So the position is re-asserted on a short interval until it holds, and
     * abandoned the moment the reader does anything. The reader's INPUT is the
     * test, never their scroll position: when content above the viewport
     * resizes, the browser moves scrollY by itself, and a loop that reads that
     * as "the reader took over" quits while the reader is still sitting at the
     * top of a record they asked to be shown the middle of.
     *
     * scrollIntoView honours the section's scroll-margin-top, so the heading
     * clears the sticky tab bar by the same measure a clicked tab uses.
     * Explicitly instant: animating a jump of this size under a
     * `scroll-behavior: smooth` rule is a long ride through content nobody
     * asked to see. */
    var deadline = new Date().getTime() + 15000;
    var settled = 0;
    var timer = null;

    function stop() {
      if (timer) { window.clearInterval(timer); timer = null; }
    }

    function hold() {
      var margin = parseFloat(getComputedStyle(target).scrollMarginTop) || 0;
      if (Math.abs(target.getBoundingClientRect().top - margin) <= 2) {
        /* Let go only after two full seconds of the target holding still. A
           record page pauses between collections loading, so a shorter quiet
           period ends the hold in a gap and the next reflow carries the reader
           away -- measured, 8,327px away with a five-check test. */
        if (++settled >= 10) { stop(); }
        return;
      }
      settled = 0;
      try { target.scrollIntoView({ block: 'start', behavior: 'auto' }); }
      catch (e) { target.scrollIntoView(true); }
      if (typeof after === 'function') { after(); }
    }

    hold();
    timer = window.setInterval(function () {
      if (userMoved || new Date().getTime() > deadline) { stop(); return; }
      hold();
    }, 200);
  }

  /* The offset that clears the sticky bar is measured, not declared -- see
     syncTabOffset() in js/mgdb-modern.js, which owns the measurement for every
     modern page. It is re-run here because a record page's bar does not exist
     when that file's init() runs: tabs() builds it from the sections that came
     back with data, so the same template is six tabs for gene_product/ferritin
     and eighteen for a gene, and the height and the wrap point move with the
     record rather than with the viewport. */

  function tabs(spec) {
    var bar = spec.el;
    if (!bar) { return; }
    var counts = spec.counts || {};
    var tabCounts = spec.tabCounts || {};

    bar.innerHTML = spec.order.map(function (id) {
      var total = 0;
      (tabCounts[id] || []).forEach(function (k) { total += (counts[k] || 0); });
      return '<a href="#' + id + '">' + escape(spec.labels[id]) +
             (total > 0 ? '<span class="mgdb-rec-tab-count">' + number(total) + '</span>' : '') + '</a>';
    }).join('');
    show(bar, spec.order.length > 1);

    /* Before the pairs are walked and before focusHash(), both of which read
       scroll-margin-top back off a section. */
    if (MGDB.watchTabOffset) { MGDB.watchTabOffset(bar); }

    var pairs = [];
    Array.prototype.forEach.call(bar.querySelectorAll('a'), function (tab) {
      var section = document.querySelector(tab.getAttribute('href'));
      if (section) { pairs.push({ tab: tab, section: section }); }
    });
    if (!pairs.length) { focusHash(); return; }

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
      pairs.forEach(function (pair) {
        if (pair.section.hidden) { return; }
        if (pair.section.offsetTop <= line) { current = pair.section; }
      });
      if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 2) {
        current = pairs[pairs.length - 1].section;
      }
      markCurrent(current);
    }

    pairs.forEach(function (pair) {
      pair.tab.addEventListener('click', function () {
        held = pair.section;
        markCurrent(pair.section);
        setTimeout(function () { heldScroll = window.scrollY; }, 400);
      });
    });

    window.addEventListener('scroll', MGDB.debounce(spy, 50), { passive: true });
    window.addEventListener('resize', MGDB.debounce(spy, 100));
    if (window.IntersectionObserver) {
      var observer = new window.IntersectionObserver(function () { spy(); }, { rootMargin: '-20% 0px -60% 0px' });
      pairs.forEach(function (pair) { observer.observe(pair.section); });
    }
    spy();
    focusHash(spy);
  }

  /* ------------------------------------------------------------------------
     The API row, and the notice above the record
     ------------------------------------------------------------------------ */

  function apiCard(buttonId, linkId, getPayload) {
    var btn = byId(buttonId);
    if (!btn) { return; }
    btn.addEventListener('click', function () {
      var payload = getPayload();
      var linkEl = byId(linkId);
      var text = payload ? JSON.stringify(payload, null, 2) : absoluteUrl(linkEl ? linkEl.getAttribute('href') : '');
      var original = btn.textContent;
      function done() {
        btn.textContent = payload ? 'JSON copied' : 'Endpoint copied';
        setTimeout(function () { btn.textContent = original; }, 1800);
      }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done).catch(function () { fallbackCopy(text, done); });
      } else {
        fallbackCopy(text, done);
      }
    });
  }

  function fallbackCopy(text, done) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); done(); } catch (e) {}
    document.body.removeChild(ta);
  }

  /* Anything the API held back is stated on the page rather than left to look
     like the record simply contains less than it does. `meta.truncated` is a
     list on some resources and a map of key to boolean on others. */
  function notice(el, meta, counts) {
    if (!el) { return; }
    var notices = [];
    var truncated = meta.truncated;
    var keys = [];
    if (Array.isArray(truncated)) {
      keys = truncated.map(function (name) { return String(name).split('.').pop(); });
    } else if (truncated && typeof truncated === 'object') {
      keys = Object.keys(truncated).filter(function (k) { return truncated[k]; });
    }
    keys.forEach(function (key) {
      notices.push('Only the first ' + number(meta.max_items || 500) + ' ' + key.replace(/_/g, ' ') +
                   ' are shown; the record has ' + number((counts || {})[key] || 0) + '.');
    });
    /* Not every warning is for the reader. `count_mismatch` compares
       meta.counts against the section bodies, which is how a resource notices
       its own broken query — /gene_center/gene/wx1 printed "variation.alleles
       returned 307 rows but meta.counts.alleles is 310." above the fold, in API
       vocabulary, naming fields the reader cannot see. It stays in the JSON,
       where it is addressed to us; tools/tests/api_warning_sweep.php is what
       watches for it. Everything else — a service that did not answer, a fact
       that is genuinely unavailable — is about the record and is shown. */
    (meta.warnings || []).forEach(function (warning) {
      if (warning.code === 'count_mismatch') { return; }
      notices.push(warning.detail);
    });
    if (!notices.length) { return; }
    /* "Note:" with the space outside the <strong>. The label and the text are
       both inline here -- unlike the warning and error boxes, where they are
       separate lines -- so without punctuation the two ran together as
       "NoteOnly the first 500 alleles are shown". */
    el.innerHTML = '<div><strong>Note:</strong> <span>' + notices.map(escape).join(' ') + '</span></div>';
    show(el, true);
  }

  window.MGDBRecord = {
    byId: byId, escape: escape, show: show, number: number, plainText: plainText,
    isExternal: isExternal, absoluteUrl: absoluteUrl, link: link, refLink: refLink,
    fact: fact, facts: facts,
    collection: collection, downloadTsv: downloadTsv,
    recordColumn: recordColumn, urlColumn: urlColumn,
    notes: notes, images: images, references: references,
    metricCard: metricCard, metrics: metrics,
    sizeChart: sizeChart, watchChartWidth: watchChartWidth,
    connectionsChart: connectionsChart, connectionsHeight: connectionsHeight,
    yearsChart: yearsChart,
    tabs: tabs, apiCard: apiCard, notice: notice, focusHash: focusHash
  };

  /* Record type bubble: the sticky wrapper in the template does the pinning
     (css/mgdb-record.css). This marks which of its two resting places it is
     in -- the header's corner, or pinned once its wrapper has reached the top
     of the window -- and, pinned, where it covers nothing: level with the last
     tab when the bar's last row has room beside it, else just under the bar.
     The bar is rebuilt by tabs() and wraps with the record, so this is
     measured, not assumed. A few rect reads per scroll event, and nothing at
     all on a page without the bubble. */
  function typeBubble() {
    var dock = document.querySelector('.mgdb-rec-type-dock');
    var bubble = dock && dock.querySelector('.mgdb-rec-type');
    if (!bubble) { return; }
    var bar = document.querySelector('.mgdb-rec-tabs');
    function update() {
      var docked = window.scrollY > 0 && dock.getBoundingClientRect().top <= 0.5;
      dock.classList.toggle('is-docked', docked);
      if (!docked) { return; }
      var top = 12;
      var last = bar && !bar.hidden && bar.lastElementChild;
      if (last) {
        var barBox = bar.getBoundingClientRect();
        var lastBox = last.getBoundingClientRect();
        var mine = bubble.getBoundingClientRect();
        top = mine.left - lastBox.right >= 12
          ? lastBox.top + (lastBox.height - mine.height) / 2
          : barBox.bottom + 8;
      }
      dock.style.setProperty('--mgdb-rec-type-docked-top', Math.round(top - dock.getBoundingClientRect().top) + 'px');
    }
    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
    update();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', typeBubble);
  } else {
    typeBubble();
  }
})(window, document);
