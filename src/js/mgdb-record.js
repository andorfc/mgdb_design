/* ==========================================================================
   Record page engine — shared by every record page on the Data Hub shell
   --------------------------------------------------------------------------
   Companion to css/mgdb-record.css; the two are a pair and neither works
   without the other. Loaded after js/mgdb-modern.js and before a page's own
   script, which is glue: it maps one API payload onto the pieces here.

   What a page gets:

     collection()   any list, as a sortable table (the default) or a grid of
                    the same rows, with a filter, a page size and a TSV of
                    exactly the columns on screen
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
  var REFERENCE_PAGE_SIZE = 5;

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
              '<svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="1" y="1" width="6" height="6" rx="1" fill="currentColor"/><rect x="9" y="1" width="6" height="6" rx="1" fill="currentColor"/><rect x="1" y="9" width="6" height="6" rx="1" fill="currentColor"/><rect x="9" y="9" width="6" height="6" rx="1" fill="currentColor"/></svg>Grid</button>' +
          '</div>' +
          '<label>Show <select data-role="size" aria-label="Rows per page">' + sizeOptions + '</select></label>' +
          '<button class="mgdb-rec-tsv" type="button" data-role="tsv">Download TSV</button>' +
        '</div>' +
      '</div>' +
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

    function filtered() {
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

      body.innerHTML = total === 0
        ? '<p class="mgdb-rec-empty">Nothing in ' + escape(spec.title.toLowerCase()) + ' matches “' + escape(state.query) + '”.</p>'
        : (state.view === 'grid' ? renderGrid(pageRows) : renderTable(pageRows));

      status.textContent = total === 0 ? '' :
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

    block.querySelector('[data-role="size"]').addEventListener('change', function (event) {
      state.size = event.target.value === 'all' ? 'all' : Number(event.target.value);
      state.page = 1;
      render();
    });

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
      downloadTsv(spec.filename || (spec.title.toLowerCase().replace(/[^a-z0-9]+/g, '-') + '.tsv'), allColumns, filtered());
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
          (row.record ? '<a class="mgdb-rec-image-btn" href="' + escape(row.record) + '">Record &rarr;</a>' : '') +
          '<a class="mgdb-rec-image-btn" href="' + escape(row.url) + '" target="_blank" rel="noopener">Open file <span aria-hidden="true">&nearr;</span></a>' +
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
    if (url) { html += '<a class="mgdb-button mgdb-button-primary" href="' + escape(url) + '" target="_blank" rel="noopener">Full text <span aria-hidden="true">&nearr;</span></a>'; }
    html += '<a class="mgdb-button mgdb-button-quiet" href="' + escape(ref.html) + '">MaizeGDB record <span aria-hidden="true">&rarr;</span></a>';
    html += '<button class="mgdb-ref-copy" type="button" data-copy-target="' + citeId + '">Copy citation</button>';
    if (ref.doi) { html += '<button class="mgdb-ref-copy" type="button" data-copy-value="' + escape(ref.doi) + '">Copy DOI</button>'; }
    html += '</div>';
    html += '<div id="' + citeId + '" class="mgdb-visually-hidden">' + escape(plain) + '</div>';
    html += '</article>';
    return html;
  }

  function references(target, items, section, idPrefix) {
    if (!items || !items.length) { return false; }
    target.innerHTML = '';
    var state = { view: 'cards', page: 1, size: REFERENCE_PAGE_SIZE, query: '' };
    var sizeOptions = [5, 10, 25].map(function (n) {
      return '<option value="' + n + '"' + (n === state.size ? ' selected' : '') + '>' + n + '</option>';
    }).join('') + '<option value="all">All</option>';

    target.innerHTML = '<div class="mgdb-rec-block">' +
      '<div class="mgdb-rec-block-head">' +
        '<h3>Publications<span class="mgdb-rec-block-count">' + number(items.length) + '</span></h3>' +
        '<div class="mgdb-rec-toolbar">' +
          '<label>Filter <input type="search" data-role="filter" placeholder="Title, author, or year" aria-label="Filter references"></label>' +
          '<div class="mgdb-view-toggle" role="group" aria-label="References view">' +
            '<button class="mgdb-view-btn" type="button" data-view="cards" aria-pressed="true"><svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="1" y="1" width="6" height="6" rx="1" fill="currentColor"/><rect x="9" y="1" width="6" height="6" rx="1" fill="currentColor"/><rect x="1" y="9" width="6" height="6" rx="1" fill="currentColor"/><rect x="9" y="9" width="6" height="6" rx="1" fill="currentColor"/></svg>Cards</button>' +
            '<button class="mgdb-view-btn" type="button" data-view="table" aria-pressed="false"><svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="1" y="2" width="14" height="2" rx="1" fill="currentColor"/><rect x="1" y="7" width="14" height="2" rx="1" fill="currentColor"/><rect x="1" y="12" width="14" height="2" rx="1" fill="currentColor"/></svg>Table</button>' +
          '</div>' +
          '<label>Show <select data-role="size" aria-label="References per page">' + sizeOptions + '</select></label>' +
          '<button class="mgdb-rec-tsv" type="button" data-role="tsv">Download TSV</button>' +
        '</div>' +
      '</div>' +
      '<p class="mgdb-rec-block-status" data-role="status" aria-live="polite"></p>' +
      '<div data-role="body"></div>' +
      '<nav class="mgdb-rec-pagination" data-role="pagination" aria-label="Reference pages" hidden></nav></div>';

    var block = target.firstElementChild;
    var body = block.querySelector('[data-role="body"]');
    var status = block.querySelector('[data-role="status"]');
    var pagination = block.querySelector('[data-role="pagination"]');
    var head = block.querySelector('.mgdb-rec-block-head');
    var columns = [
      { key: 'title', label: 'Title', tile: true, get: function (r) { return r.title || r.citation; },
        html: function (r) { return '<a href="' + escape(r.html) + '">' + escape(r.title || r.citation) + '</a>' + (r.authors ? '<small>' + escape(r.authors) + '</small>' : ''); } },
      { key: 'year', label: 'Year', sort: 'number', numeric: true },
      { key: 'citation', label: 'Citation' },
      { key: 'relevance', label: 'Relevance' },
      { key: 'doi', label: 'DOI', html: function (r) { return r.doi ? link('https://doi.org/' + r.doi, r.doi, true) : '—'; } }
    ];

    function filtered() {
      if (!state.query) { return items.slice(); }
      var needle = state.query.toLowerCase();
      return items.filter(function (r) {
        return [r.title, r.authors, r.citation, r.year, r.relevance, r.doi].some(function (v) { return plainText(v).toLowerCase().indexOf(needle) !== -1; });
      });
    }

    function render() {
      var rows = filtered();
      var total = rows.length;
      var size = state.size === 'all' ? Math.max(total, 1) : state.size;
      var pages = Math.max(1, Math.ceil(total / size));
      if (state.page > pages) { state.page = pages; }
      var start = (state.page - 1) * size;
      var pageRows = rows.slice(start, start + size);

      if (state.view === 'table') {
        body.innerHTML = '';
        collection(body, { title: 'References', items: rows, filename: 'references.tsv', columns: columns,
                           pageSize: state.size === 'all' ? 'all' : state.size });
        // The collection carries its own head, filter and pager; hide this one's.
        head.hidden = true;
        status.textContent = '';
        show(pagination, false);
        return;
      }

      head.hidden = false;
      body.innerHTML = total === 0
        ? '<p class="mgdb-rec-empty">No reference matches “' + escape(state.query) + '”.</p>'
        : '<div class="mgdb-ref-list">' + pageRows.map(function (r, i) { return referenceCard(r, start + i, idPrefix); }).join('') + '</div>';
      MGDB.initCopyButtons();
      status.textContent = total === 0 ? '' : 'Showing ' + (start + 1) + '–' + Math.min(start + size, total) + ' of ' +
        number(total) + (total !== items.length ? ' matching' : '') + ' publications, newest first.';

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
      downloadTsv('references.tsv', columns, filtered());
    });

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
    (meta.warnings || []).forEach(function (warning) { notices.push(warning.detail); });
    if (!notices.length) { return; }
    el.innerHTML = '<div><strong>Note</strong><span>' + notices.map(escape).join(' ') + '</span></div>';
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
    tabs: tabs, apiCard: apiCard, notice: notice
  };
})(window, document);
