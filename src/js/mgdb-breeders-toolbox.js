/* mgdb-breeders-toolbox.js -- /breeders_toolbox, the Pedigree Viewer
 *
 * Two questions, two shapes.
 *
 * "What is this variety related to" is answered as bands: ancestors above, the
 * variety in the middle, descendants below, one band per generation. That is
 * the shape /data_center/stock uses for a single stock, and it is what stops
 * this page producing a hairball -- the fan-out in this graph is one
 * generation *wide* (B73 has 1,312 direct descendants and 23 in the generation
 * after), so a band that caps and filters in place is the thing that makes a
 * hub variety readable. Every row is always in the table and the CSV.
 *
 * "How are these two related" is answered as a chain, which is small by
 * construction.
 */
(function () {
  'use strict';

  var E = window.MGDB && window.MGDB.escape ? window.MGDB.escape : function (s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  };

  function num(n) { return Number(n || 0).toLocaleString(); }

  function stockLink(name) {
    return '/breeders_toolbox?variety=' + encodeURIComponent(name) + '&depth=2';
  }

  function init() {
    var main = document.getElementById('bt-main');
    if (!main) { return; }

    var walkForm = document.getElementById('bt-walk-form');
    var walkOut = document.getElementById('bt-walk-result');
    var pathForm = document.getElementById('bt-path-form');
    var pathOut = document.getElementById('bt-path-result');
    var varietyInput = document.getElementById('bt-variety');
    var depthInput = document.getElementById('bt-depth');
    var options = document.getElementById('bt-variety-options');

    /* ------------------------------------------------------------------ */
    /* Name completion                                                     */
    /* ------------------------------------------------------------------ */

    var suggestTimer = null;
    function suggest() {
      var q = varietyInput.value.trim();
      if (q.length < 2) { return; }
      fetch('/breeders_toolbox?format=json&mode=suggest&q=' + encodeURIComponent(q))
        .then(function (r) { return r.json(); })
        .then(function (d) {
          options.innerHTML = (d.matches || []).map(function (m) {
            return '<option value="' + E(m) + '"></option>';
          }).join('');
        })
        .catch(function () { /* completion is a convenience, not the feature */ });
    }
    if (varietyInput) {
      varietyInput.addEventListener('input', function () {
        window.clearTimeout(suggestTimer);
        suggestTimer = window.setTimeout(suggest, 180);
      });
    }

    /* ------------------------------------------------------------------ */
    /* The generational walk                                               */
    /* ------------------------------------------------------------------ */

    function nodeHtml(it, direction) {
      var where = [it.developer, it.state_province, it.country].filter(Boolean)[0] || '';
      return '<a class="bt-node bt-node-' + direction + '" href="' + E(stockLink(it.name)) +
        '"><strong>' + E(it.name) + '</strong><span>' + E(where) + '</span></a>';
    }

    function searchText(it) {
      return (it.name + ' ' + [it.developer, it.state_province, it.country]
        .filter(Boolean).join(' ')).toLowerCase();
    }

    /* A band renders at most `limit` of its matches. The matches are computed
       from the whole generation, not from what happens to be on screen -- the
       point of the filter is to reach the Iowa lines among B73's 1,312 direct
       descendants, which are nowhere near the first 24. */
    function paintBand(band, keep, filtering) {
      var matches = [];
      for (var i = 0; i < band.items.length; i++) {
        if (keep(band.items[i], band.search[i])) { matches.push(band.items[i]); }
      }
      var shown = matches.slice(0, band.limit);
      band.grid.innerHTML = shown.map(function (it) { return nodeHtml(it, band.direction); }).join('');
      band.count.textContent = num(matches.length);
      band.note.hidden = matches.length <= band.limit;
      if (!band.note.hidden) {
        band.note.textContent = 'Showing ' + num(shown.length) + ' of ' + num(matches.length) +
          (filtering ? ' matching. Open the table for every row.'
                     : '. Filter above, or open the table for every row.');
      }
      band.empty.hidden = matches.length !== 0;
      /* A generation with no match drops out while filtering and comes back
         when the filter clears, so the map never shows an empty band. */
      band.el.hidden = filtering && matches.length === 0;
      return matches.length;
    }

    function bandShell(title, items, direction, limit, gen) {
      return '<div class="bt-band" data-direction="' + direction + '" data-gen="' + gen + '">' +
        '<h3>' + E(title) + ' <span class="bt-band-count">' + num(items.length) + '</span></h3>' +
        '<div class="bt-node-grid"></div>' +
        '<p class="bt-band-note" hidden></p>' +
        '<p class="bt-band-empty" hidden>Nothing in this generation matches the filter.</p></div>';
    }

    /* Developer and state as chips, because "show me the Iowa ones" is a click
       rather than something a breeder should have to type. Counts are of the
       whole result, so they stay put as selections change; the chips are
       capped and the text field reaches whatever the cap leaves out. */
    var FACET_LIMIT = { developer: 10, state_province: 12 };

    /* A few stock rows carry a placeholder where a state or developer belongs
       -- "0", "-", "N/A". They are not values a breeder would ever click, and
       one of them was rendering as a chip labelled 0. */
    var FACET_JUNK = { '0': 1, '-': 1, '--': 1, 'n/a': 1, 'na': 1, 'none': 1, 'unknown': 1 };

    function facet(rows, key) {
      var counts = {};
      rows.forEach(function (r) {
        var v = (r[key] || '').trim();
        if (v && !FACET_JUNK[v.toLowerCase()]) { counts[v] = (counts[v] || 0) + 1; }
      });
      return Object.keys(counts)
        .map(function (v) { return { value: v, n: counts[v] }; })
        .sort(function (a, b) { return b.n - a.n || a.value.localeCompare(b.value); });
    }

    function facetRow(label, key, values) {
      if (values.length < 2) { return ''; }
      var limit = FACET_LIMIT[key] || 10;
      var shown = values.slice(0, limit);
      var chips = '<button type="button" class="bt-chip is-active" data-facet="' + key +
        '" data-value="">All</button>' +
        shown.map(function (v) {
          return '<button type="button" class="bt-chip" data-facet="' + key +
            '" data-value="' + E(v.value) + '">' + E(v.value) +
            ' <span>' + num(v.n) + '</span></button>';
        }).join('');
      var more = values.length > limit
        ? '<span class="bt-chip-more">+' + num(values.length - limit) + ' more, in the filter field</span>'
        : '';
      return '<div class="bt-facet" role="group" aria-label="Filter by ' + E(label) + '">' +
        '<span class="bt-facet-label">' + E(label) + '</span>' + chips + more + '</div>';
    }

    function facetRows(rows) {
      var dev = facetRow('Developer', 'developer', facet(rows, 'developer'));
      var st = facetRow('State', 'state_province', facet(rows, 'state_province'));
      return (dev || st) ? '<div class="bt-facets">' + dev + st + '</div>' : '';
    }

    function renderWalk(data) {
      if (data.error) {
        walkOut.innerHTML = '<div class="mgdb-message mgdb-message-warn" role="note"><div>' +
          E(data.error) + '</div></div>';
        return;
      }
      var rows = data.rows || [];
      var seed = data.seed || {};
      var limit = data.limit || 24;

      var byGen = { ancestor: {}, descendant: {} };
      rows.forEach(function (r) {
        var g = String(r.gen);
        (byGen[r.direction][g] = byGen[r.direction][g] || []).push(r);
      });

      var ancestors = 0, descendants = 0;
      Object.keys(byGen.ancestor).forEach(function (g) { ancestors += byGen.ancestor[g].length; });
      Object.keys(byGen.descendant).forEach(function (g) { descendants += byGen.descendant[g].length; });

      if (!ancestors && !descendants) {
        /* The stock exists but carries no parent or progeny link, so there is
           nothing to draw. Saying that plainly beats an empty canvas. */
        walkOut.innerHTML = '<div class="mgdb-message mgdb-message-info" role="note"><div>' +
          'No pedigree relationship is recorded for <strong>' + E(seed.name) + '</strong>, ' +
          'so there is nothing to trace. Try one of the examples above, or pick a variety ' +
          'from <a href="#bt-common">Common lines</a>.</div></div>';
        return;
      }

      /* Ancestor bands run from the most distant generation down to the seed;
         descendant bands run away from it. */
      var html = '<dl class="bt-summary">' +
        '<div><dt>Ancestors</dt><dd>' + num(ancestors) + '</dd><span>within ' + data.depth +
          ' generation' + (data.depth === 1 ? '' : 's') + '</span></div>' +
        '<div><dt>Descendants</dt><dd>' + num(descendants) + '</dd><span>within ' + data.depth +
          ' generation' + (data.depth === 1 ? '' : 's') + '</span></div>' +
        '<div><dt>Varieties shown</dt><dd>' + num(ancestors + descendants + 1) +
          '</dd><span>including ' + E(seed.name) + '</span></div>' +
      '</dl>' +
      '<div class="bt-toolbar">' +
        '<div class="bt-view-toggle" role="group" aria-label="Pedigree presentation">' +
          '<button type="button" class="is-active" data-view="map" aria-pressed="true">Generations</button>' +
          '<button type="button" data-view="table" aria-pressed="false">Table</button>' +
        '</div>' +
        '<label class="bt-filter-label" for="bt-node-filter">Filter these varieties</label>' +
        '<input type="search" id="bt-node-filter" class="bt-filter" ' +
          'placeholder="Name, developer, state, or country&hellip;" autocomplete="off" />' +
        '<a class="mgdb-button mgdb-button-secondary bt-csv" href="#" download>Download CSV</a>' +
      '</div>' + facetRows(rows);

      var map = '<div class="bt-map" id="bt-map">';
      Object.keys(byGen.ancestor).map(Number).sort(function (a, b) { return b - a; })
        .forEach(function (g) {
          map += bandShell(g === 1 ? 'Parents' : 'Generation ' + g + ' back',
                           byGen.ancestor[String(g)], 'ancestor', limit, g);
          map += '<div class="bt-flow"><b aria-hidden="true">&darr;</b></div>';
        });
      map += '<div class="bt-seed"><span>Selected variety</span><a href="/searchall?query=' +
        encodeURIComponent(seed.name) + '">' + E(seed.name) + '</a>' +
        (seed.pedigree ? '<small>' + E(seed.pedigree) + '</small>' : '') + '</div>';
      Object.keys(byGen.descendant).map(Number).sort(function (a, b) { return a - b; })
        .forEach(function (g) {
          map += '<div class="bt-flow"><b aria-hidden="true">&darr;</b></div>';
          map += bandShell(g === 1 ? 'Direct progeny' : 'Generation ' + g + ' on',
                           byGen.descendant[String(g)], 'descendant', limit, g);
        });
      map += '</div>';

      var tbody = rows.map(function (r) {
        var where = [r.developer, r.state_province, r.country].filter(Boolean).join(' &middot; ');
        return '<tr data-search="' + E((r.name + ' ' + where).toLowerCase()) +
          '" data-developer="' + E(r.developer || '') +
          '" data-state="' + E(r.state_province || '') + '">' +
          '<td><span class="mgdb-pill ' + (r.direction === 'ancestor' ? 'mgdb-pill-info' : 'mgdb-pill-ok') +
            '">' + (r.direction === 'ancestor' ? 'Ancestor' : 'Descendant') + '</span></td>' +
          '<th scope="row"><a href="' + E(stockLink(r.name)) + '">' + E(r.name) + '</a></th>' +
          '<td class="mgdb-numeric" data-value="' + E(r.gen) + '">' + E(r.gen) + '</td>' +
          '<td>' + where + '</td></tr>';
      }).join('');

      var table = '<div class="bt-table-wrap mgdb-table-scroll" id="bt-table" hidden>' +
        '<table class="mgdb-table"><thead><tr>' +
        '<th scope="col">Direction</th><th scope="col">Variety</th>' +
        '<th scope="col" class="mgdb-numeric">Generation</th><th scope="col">Developer, state, country</th>' +
        '</tr></thead><tbody>' + tbody + '</tbody></table>' +
        '<p class="bt-table-empty" hidden>No variety matches that filter.</p></div>';

      walkOut.innerHTML = html + map + table;
      wireResult(seed, rows, byGen, limit);
    }

    function wireResult(seed, rows, byGen, limit) {
      var map = document.getElementById('bt-map');
      var table = document.getElementById('bt-table');
      var filter = document.getElementById('bt-node-filter');

      walkOut.querySelectorAll('[data-view]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var view = btn.getAttribute('data-view');
          walkOut.querySelectorAll('[data-view]').forEach(function (b) {
            var on = b === btn;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
          });
          map.hidden = view !== 'map';
          table.hidden = view !== 'table';
        });
      });

      /* Collect each band with the whole generation behind it, so a filter
         searches all of it rather than the capped slice on screen. */
      var bands = [];
      Array.prototype.forEach.call(map.querySelectorAll('.bt-band'), function (el) {
        var dir = el.getAttribute('data-direction');
        var gen = el.getAttribute('data-gen');
        var items = (byGen[dir] && byGen[dir][gen]) || [];
        bands.push({
          el: el, items: items, direction: dir, limit: limit,
          search: items.map(searchText),
          grid: el.querySelector('.bt-node-grid'),
          count: el.querySelector('.bt-band-count'),
          note: el.querySelector('.bt-band-note'),
          empty: el.querySelector('.bt-band-empty')
        });
      });
      bands.forEach(function (b) { paintBand(b, function () { return true; }, false); });

      /* One predicate over the text field and both chip rows: text OR-matches
         the row's searchable string, each chip AND-narrows it. */
      var selected = { developer: '', state_province: '' };

      function keeper() {
        var q = filter.value.trim().toLowerCase();
        var dev = selected.developer;
        var st = selected.state_province;
        var filtering = q !== '' || dev !== '' || st !== '';
        return {
          filtering: filtering,
          fn: function (item, search) {
            if (q !== '' && search.indexOf(q) === -1) { return false; }
            if (dev !== '' && (item.developer || '') !== dev) { return false; }
            if (st !== '' && (item.state_province || '') !== st) { return false; }
            return true;
          }
        };
      }

      function applyFilter() {
        var k = keeper();
        bands.forEach(function (b) { paintBand(b, k.fn, k.filtering); });
        var shown = 0;
        Array.prototype.forEach.call(table.querySelectorAll('tbody tr'), function (tr) {
          var hit = k.fn({
            developer: tr.getAttribute('data-developer') || '',
            state_province: tr.getAttribute('data-state') || ''
          }, tr.getAttribute('data-search') || '');
          tr.hidden = !hit;
          if (hit) { shown++; }
        });
        table.querySelector('.bt-table-empty').hidden = shown !== 0;
      }

      var timer = null;
      filter.addEventListener('input', function () {
        window.clearTimeout(timer);
        timer = window.setTimeout(applyFilter, 120);
      });

      Array.prototype.forEach.call(walkOut.querySelectorAll('.bt-chip'), function (chip) {
        chip.addEventListener('click', function () {
          var key = chip.getAttribute('data-facet');
          selected[key] = chip.getAttribute('data-value');
          Array.prototype.forEach.call(
            walkOut.querySelectorAll('.bt-chip[data-facet="' + key + '"]'),
            function (c) { c.classList.toggle('is-active', c === chip); });
          applyFilter();
        });
      });

      var csv = walkOut.querySelector('.bt-csv');
      var lines = [['direction', 'generation', 'variety', 'developer', 'state_province', 'country']]
        .concat(rows.map(function (r) {
          return [r.direction, r.gen, r.name, r.developer || '', r.state_province || '', r.country || ''];
        }))
        .map(function (cols) {
          return cols.map(function (c) { return '"' + String(c).replace(/"/g, '""') + '"'; }).join(',');
        }).join('\n');
      csv.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(lines);
      csv.setAttribute('download', 'pedigree-' + String(seed.name).replace(/[^\w.-]+/g, '_') + '.csv');
    }

    function runWalk(name, depth, push) {
      if (!name) { return; }
      walkOut.innerHTML = '<p class="bt-loading">Tracing ' + E(name) + '&hellip;</p>';
      fetch('/breeders_toolbox?format=json&mode=walk&variety=' + encodeURIComponent(name) +
            '&depth=' + encodeURIComponent(depth))
        .then(function (r) { return r.json(); })
        .then(renderWalk)
        .catch(function () {
          walkOut.innerHTML = '<div class="mgdb-message mgdb-message-warn" role="note"><div>' +
            'The pedigree could not be loaded. Try again in a moment.</div></div>';
        });
      if (push && window.history && window.history.replaceState) {
        window.history.replaceState({}, '', '/breeders_toolbox?variety=' +
          encodeURIComponent(name) + '&depth=' + encodeURIComponent(depth));
      }
    }

    walkForm.addEventListener('submit', function (e) {
      e.preventDefault();
      runWalk(varietyInput.value.trim(), depthInput.value, true);
    });
    document.querySelectorAll('.bt-example').forEach(function (b) {
      b.addEventListener('click', function () {
        varietyInput.value = b.getAttribute('data-variety');
        runWalk(varietyInput.value, depthInput.value, true);
        document.getElementById('bt-explore').scrollIntoView({ block: 'start' });
      });
    });

    /* ------------------------------------------------------------------ */
    /* Shortest path                                                       */
    /* ------------------------------------------------------------------ */

    pathForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var from = document.getElementById('bt-from').value.trim();
      var to = document.getElementById('bt-to').value.trim();
      if (!from || !to) { return; }
      pathOut.innerHTML = '<p class="bt-loading">Searching&hellip;</p>';
      fetch('/breeders_toolbox?format=json&mode=path&from=' + encodeURIComponent(from) +
            '&to=' + encodeURIComponent(to))
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.error) {
            pathOut.innerHTML = '<div class="mgdb-message mgdb-message-warn" role="note"><div>' +
              E(d.error) + '</div></div>';
            return;
          }
          var chain = d.steps.map(function (s, i) {
            return '<li><a class="bt-chain-node" href="' + E(stockLink(s.name)) + '">' +
              E(s.name) + '</a>' +
              (s.relation ? '<span class="bt-chain-rel">' + E(s.relation) + '</span>' : '') + '</li>';
          }).join('');
          pathOut.innerHTML = '<p class="bt-chain-summary">' + num(d.hops) + ' step' +
            (d.hops === 1 ? '' : 's') + ' between <strong>' + E(d.steps[0].name) +
            '</strong> and <strong>' + E(d.steps[d.steps.length - 1].name) + '</strong>.</p>' +
            '<ol class="bt-chain">' + chain + '</ol>';
        })
        .catch(function () {
          pathOut.innerHTML = '<div class="mgdb-message mgdb-message-warn" role="note"><div>' +
            'The search could not be completed. Try again in a moment.</div></div>';
        });
    });

    /* A variety in the URL loads its pedigree straight away. */
    var initial = main.getAttribute('data-initial-variety');
    var initialDepth = main.getAttribute('data-initial-depth') || '2';
    if (initial) {
      varietyInput.value = initial;
      depthInput.value = initialDepth;
      runWalk(initial, initialDepth, false);
    }

    if (window.MGDB && typeof window.MGDB.sectionTabs === 'function') {
      window.MGDB.sectionTabs({ watch: '#bt-walk-result' });
    }
    if (window.MGDB && typeof window.MGDB.sortableTable === 'function') {
      window.MGDB.sortableTable(document.getElementById('bt-hub-table'));
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
