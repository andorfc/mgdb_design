/* file: js/mgdb-associated-genes.js
 *
 * /associated_genes. Pages one of the three association lists from
 * search/associated_genes/associated_genes_api.php, and switches between them.
 *
 * The page this replaces had no script. Its table mode put all 38,758 rows in
 * the document instead -- 22.8 MB of it.
 */
(function () {
  'use strict';

  var API = '/search/associated_genes/associated_genes_api.php';
  var PAGE_SIZE = 100;

  var type = 'all';
  var offset = 0;
  var total = 0;
  var columns = [];
  var lastQuery = '';
  var debounce = null;

  function byId(id) { return document.getElementById(id); }
  function show(el, on) { if (el) { el.hidden = !on; } }
  function num(v) { return Number(v || 0).toLocaleString('en-US'); }

  function esc(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function queryString(extra) {
    var parts = ['type=' + encodeURIComponent(type)];
    var q = (byId('ag-q') || {}).value || '';
    if (q) { parts.push('q=' + encodeURIComponent(q)); }
    Object.keys(extra || {}).forEach(function (k) {
      parts.push(k + '=' + encodeURIComponent(extra[k]));
    });
    return parts.join('&');
  }

  /* An identifier cell links to the Gene hub, which resolves any of these.
     A blank one is not a link: the legacy table wrapped every cell in an
     anchor whether or not there was an identifier to put in it, which is
     where its 11,144 links to /gene_center/gene/ came from. */
  function idCell(value) {
    if (!value) { return '<td class="ag-id"><span class="mgdb-muted">&mdash;</span></td>'; }
    return '<td class="ag-id"><a href="/gene_center/gene/' + encodeURIComponent(value) + '">'
         + esc(value) + '</a></td>';
  }

  function renderHead() {
    var head = byId('ag-thead');
    if (!head) { return; }
    head.innerHTML = '<tr>' + columns.map(function (col) {
      return '<th scope="col">' + esc(col[1]) + '</th>';
    }).join('') + '</tr>';
  }

  function renderRows(rows) {
    var body = byId('ag-tbody');
    if (!body) { return; }
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="' + columns.length + '">'
        + (lastQuery ? 'Nothing in this list matches that identifier.'
                     : 'This list is empty.') + '</td></tr>';
      return;
    }
    body.innerHTML = rows.map(function (row) {
      return '<tr>' + columns.map(function (col) {
        var key = col[0];
        var value = row[key] || '';
        if (key === 'v5' || key === 'v4' || key === 'v3' || key === 'gene') {
          return idCell(value);
        }
        return '<td>' + (value ? esc(value)
                               : '<span class="mgdb-muted">&mdash;</span>') + '</td>';
      }).join('') + '</tr>';
    }).join('');
  }

  function markCurrentSet() {
    document.querySelectorAll('.ag-set').forEach(function (card) {
      var button = card.querySelector('.ag-browse');
      var isCurrent = button && button.getAttribute('data-type') === type;
      card.classList.toggle('is-current', !!isCurrent);
    });
    var select = byId('ag-type');
    if (select && select.value !== type) { select.value = type; }
    var link = byId('ag-export');
    if (link) { link.setAttribute('href', API + '?' + queryString({ format: 'tsv' })); }
  }

  function render(doc) {
    total = doc.total || 0;
    columns = doc.columns || [];
    renderHead();
    renderRows(doc.rows || []);

    var count = byId('ag-count');
    if (count) {
      var shown = (doc.rows || []).length;
      count.textContent = total
        ? num(total) + ' rows in ' + ((doc.dataset && doc.dataset.label) || 'this list')
          + (lastQuery ? ' matching “' + lastQuery + '”' : '')
          + (total > shown ? ' — showing ' + num(offset + 1) + '–' + num(offset + shown) : '')
          + '.'
        : 'Nothing to show.';
    }

    show(byId('ag-table-wrap'), true);
    show(byId('ag-loading'), false);

    var pages = Math.ceil(total / PAGE_SIZE);
    var pager = byId('ag-pager');
    if (pager) {
      show(pager, pages > 1);
      var label = byId('ag-page-label');
      if (label) {
        label.textContent = 'Page ' + num(Math.floor(offset / PAGE_SIZE) + 1) + ' of ' + num(pages);
      }
      var prev = byId('ag-prev'), next = byId('ag-next');
      if (prev) { prev.disabled = offset <= 0; }
      if (next) { next.disabled = offset + PAGE_SIZE >= total; }
    }
    markCurrentSet();
  }

  function load() {
    show(byId('ag-error'), false);
    show(byId('ag-loading'), true);
    fetch(API + '?' + queryString({ limit: PAGE_SIZE, offset: offset }),
          { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
      .then(function (doc) {
        if (!doc.ok) { throw new Error(doc.error || 'not ok'); }
        render(doc);
      })
      .catch(function () {
        show(byId('ag-loading'), false);
        show(byId('ag-error'), true);
      });
  }

  function switchTo(next) {
    if (next === type) { return; }
    type = next;
    offset = 0;
    markCurrentSet();
    load();
  }

  function init() {
    var root = byId('ag-main');
    if (!root) { return; }
    type = root.getAttribute('data-type') || 'all';

    document.querySelectorAll('.ag-browse').forEach(function (button) {
      button.addEventListener('click', function () {
        switchTo(button.getAttribute('data-type'));
        var browse = byId('ag-browse');
        if (browse) { browse.scrollIntoView({ block: 'start', behavior: 'smooth' }); }
      });
    });

    var select = byId('ag-type');
    if (select) {
      select.value = type;
      select.addEventListener('change', function () { switchTo(select.value); });
    }

    var form = byId('ag-form');
    if (form) { form.addEventListener('submit', function (e) { e.preventDefault(); }); }

    var q = byId('ag-q');
    if (q) {
      q.addEventListener('input', function () {
        window.clearTimeout(debounce);
        debounce = window.setTimeout(function () {
          if (q.value === lastQuery) { return; }
          lastQuery = q.value;
          offset = 0;
          load();
        }, 250);
      });
    }

    var prev = byId('ag-prev'), next = byId('ag-next'), retry = byId('ag-retry');
    if (prev) { prev.addEventListener('click', function () {
      offset = Math.max(0, offset - PAGE_SIZE); load();
    }); }
    if (next) { next.addEventListener('click', function () {
      if (offset + PAGE_SIZE < total) { offset += PAGE_SIZE; load(); }
    }); }
    if (retry) { retry.addEventListener('click', load); }

    load();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
