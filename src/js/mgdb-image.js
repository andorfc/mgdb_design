/* Image Data Hub JavaScript
   Handles search input, category filtering, responsive gallery rendering,
   lightbox modal preview, sticky scrollspy tabs, pagination, and export URL synchronization. */

(function () {
  'use strict';

  var API_URL = '/search/image/image_search_api.php';

  var state = {
    term: '',
    category: 'all',
    sort: 'latest',
    view: 'card',
    page: 1,
    pageSize: 25,
    /* The endpoint caps a page at 100, so "All results" means "as many as it
       returns at once", which the status line says rather than truncating
       quietly. */
    filter: '',
    searched: false,
    currentData: null,
    loading: false
  };

  var MAX_PAGE = 100;

  function byId(id) { return document.getElementById(id); }

  function esc(str) {
    if (!str && str !== 0) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function number(n) {
    return Number(n || 0).toLocaleString();
  }

  /* ── Section Tabs & Scrollspy ───────────────────────────────────────────── */

  /* Sticky section tabs, driven by scroll, IntersectionObserver and resize
     together: no single trigger fires everywhere, and the results section
     appears and disappears under the bar as searches run. */
  function buildTabs() {
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

    /* The line the spy measures against is the section's own scroll-margin-top,
       read back from CSS rather than repeated here, so a clicked tab and the
       scrollspy agree by construction. */
    function triggerLine() {
      var bar = document.querySelector('.mgdb-section-tabs');
      var barHeight = bar ? bar.getBoundingClientRect().height : 0;
      var margin = parseFloat(window.getComputedStyle(pairs[0].section).scrollMarginTop) || 0;
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
      if ((window.innerHeight + window.scrollY) >= (document.documentElement.scrollHeight - 2)) {
        current = pairs[pairs.length - 1];
      }
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

    var results = byId('image-gallery-section');
    if (results && window.MutationObserver) {
      new window.MutationObserver(update).observe(results, {
        childList: true, subtree: true, attributes: true, attributeFilter: ['hidden']
      });
    }

    update();
  }

  function readUrlParams() {
    var main = byId('image-top');
    if (main && main.getAttribute('data-initial-category')) {
      state.category = main.getAttribute('data-initial-category') || 'all';
    }

    var params = new URLSearchParams(window.location.search);
    if (params.has('q') || params.has('term')) {
      state.term = params.get('q') || params.get('term') || '';
    }
    if (params.has('category') || params.has('cat')) {
      state.category = params.get('category') || params.get('cat') || 'all';
    }
    if (params.has('sort')) {
      state.sort = params.get('sort') || 'latest';
    }
    if (params.has('view')) {
      state.view = params.get('view') === 'table' ? 'table' : 'card';
    }
    if (params.has('page')) {
      state.page = parseInt(params.get('page'), 10) || 1;
    }
  }

  function updateUrlParams() {
    var params = new URLSearchParams();
    if (state.term) params.set('q', state.term);
    if (state.category && state.category !== 'all') params.set('category', state.category);
    if (state.sort && state.sort !== 'latest') params.set('sort', state.sort);
    if (state.view && state.view !== 'card') params.set('view', state.view);
    if (state.page > 1) params.set('page', state.page);

    var queryString = params.toString();
    var newUrl = window.location.pathname + (queryString ? '?' + queryString : '');
    window.history.replaceState({}, '', newUrl);
  }

  /* ── Export Links Sync ──────────────────────────────────────────────────── */

  function updateExportLinks() {
    var params = new URLSearchParams();
    if (state.term) params.set('term', state.term);
    if (state.category) params.set('category', state.category);

    var tsvLink = byId('image-export-tsv');
    var csvLink = byId('image-export-csv');

    if (tsvLink) {
      var tsvParams = new URLSearchParams(params.toString());
      tsvParams.set('format', 'tsv');
      tsvLink.href = API_URL + '?' + tsvParams.toString();
    }

    if (csvLink) {
      var csvParams = new URLSearchParams(params.toString());
      csvParams.set('format', 'csv');
      csvLink.href = API_URL + '?' + csvParams.toString();
    }
  }

  /* ── Search Fetcher ─────────────────────────────────────────────────────── */

  function executeSearch(scrollToResults) {
    if (state.loading) return;
    state.loading = true;

    var status = byId('image-results-status');
    var container = byId('image-results') || byId('image-results-grid');
    var empty = byId('image-empty');

    if (status) {
      status.textContent = 'Searching image collection…';
    }

    var section = byId('image-gallery-section');
    if (section) { section.hidden = false; }
    state.searched = true;

    var size = state.pageSize === 'all' ? MAX_PAGE : state.pageSize;

    var params = new URLSearchParams();
    if (state.term) params.set('term', state.term);
    if (state.category) params.set('category', state.category);
    params.set('sort', state.sort);
    params.set('page', state.pageSize === 'all' ? 1 : state.page);
    params.set('page_size', size);

    updateUrlParams();
    updateExportLinks();

    fetch(API_URL + '?' + params.toString())
      .then(function (res) { return res.json(); })
      .then(function (data) {
        state.loading = false;
        state.currentData = data;

        if (!data || !data.ok) {
          if (status) status.textContent = 'Search failed. Please try again.';
          return;
        }

        renderResults(data);
        applyResultsFilter();
        renderPagination(data.summary.page, data.summary.page_count);

        if (scrollToResults && container) {
          var target = byId('image-gallery-section');
          if (target && typeof target.scrollIntoView === 'function') {
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        }
      })
      .catch(function (err) {
        state.loading = false;
        if (status) status.textContent = 'An error occurred while fetching images.';
      });
  }

  /* ── Render Gallery Cards & Table ────────────────────────────────────────── */

  function renderResults(data) {
    var container = byId('image-results') || byId('image-results-grid');
    var empty = byId('image-empty');
    var status = byId('image-results-status');
    var summary = data.summary;

    if (!summary.total || summary.total === 0) {
      if (container) container.innerHTML = '';
      if (empty) empty.hidden = false;
      if (status) {
        var qText = data.query.term ? ' for “' + esc(data.query.term) + '”' : '';
        status.textContent = 'No images matched your query' + qText + '.';
      }
      return;
    }

    if (empty) empty.hidden = true;

    var start = (summary.page - 1) * summary.page_size + 1;
    var end = Math.min(summary.total, summary.page * summary.page_size);
    var queryText = data.query.term ? ' for “' + esc(data.query.term) + '”' : '';

    if (status) {
      status.textContent = state.pageSize === 'all'
        ? 'Showing ' + number(Math.min(summary.total, MAX_PAGE)) + ' of ' + number(summary.total)
          + ' images' + queryText + ', which is as many as the search returns at once.'
          + ' (' + number(summary.elapsed_ms) + ' ms)'
        : 'Showing ' + number(start) + '–' + number(end) + ' of ' + number(summary.total)
          + ' images' + queryText + '. (' + number(summary.elapsed_ms) + ' ms)';
    }

    if (!container) return;
    container.className = 'image-results-container image-view-' + state.view;

    if (state.view === 'card') {
      container.innerHTML = '<div class="image-results-grid">' + data.results.map(function (row, idx) {
        var name = row.entity_name || ('Image #' + row.auto_num);
        var recordUrl = row.record_url || ('/data_center/variation?id=' + encodeURIComponent(row.id));
        var imgUrl = row.image_url;
        var caption = row.caption || '';
        var catName = row.category_name || row.type_name || 'Media';

        return '<article class="mgdb-image-card" data-index="' + idx + '">'
          + '  <div>'
          + '    <figure class="image-card-figure" data-img-src="' + esc(imgUrl) + '" data-img-title="' + esc(name) + '" data-img-cat="' + esc(catName) + '" data-img-caption="' + esc(caption) + '" data-img-record="' + esc(recordUrl) + '">'
          + '      <img src="' + esc(imgUrl) + '" alt="' + esc(caption || name) + '" loading="lazy" onerror="this.onerror=null;this.src=\'/images/logo.png\';this.style.objectFit=\'contain\';this.style.padding=\'16px\';" />'
          + '    </figure>'
          + '    <div class="image-card-body">'
          + '      <div class="image-card-meta">'
          + '        <span class="image-card-badge" data-cat="' + esc(row.type_name || catName) + '">' + esc(catName) + '</span>'
          + '      </div>'
          + '      <h3><a href="' + recordUrl + '">' + esc(name) + '</a></h3>'
          + (caption ? '<p class="image-card-caption">' + esc(caption) + '</p>' : '')
          + '    </div>'
          + '  </div>'
          + '  <div class="image-card-links">'
          + '    <button class="image-card-btn image-preview-btn" type="button" data-img-src="' + esc(imgUrl) + '" data-img-title="' + esc(name) + '" data-img-cat="' + esc(catName) + '" data-img-caption="' + esc(caption) + '" data-img-record="' + esc(recordUrl) + '">Zoom</button>'
          + '    <a class="image-card-btn" href="' + recordUrl + '">Record &rarr;</a>'
          + '    <button class="image-card-btn image-copy-btn" type="button" data-copy-value="' + esc(imgUrl) + '">Copy URL</button>'
          + '  </div>'
          + '</article>';
      }).join('') + '</div>';
    } else {
      var rows = data.results.map(function (row, idx) {
        var name = row.entity_name || ('Image #' + row.auto_num);
        var recordUrl = row.record_url || ('/data_center/variation?id=' + encodeURIComponent(row.id));
        var imgUrl = row.image_url;
        var caption = row.caption || '';
        var catName = row.category_name || row.type_name || 'Media';

        return '<tr>'
          + '  <td>'
          + '    <button class="image-table-thumb image-preview-btn" type="button" data-img-src="' + esc(imgUrl) + '" data-img-title="' + esc(name) + '" data-img-cat="' + esc(catName) + '" data-img-caption="' + esc(caption) + '" data-img-record="' + esc(recordUrl) + '" title="Click to zoom">'
          + '      <img src="' + esc(imgUrl) + '" alt="' + esc(caption || name) + '" loading="lazy" onerror="this.onerror=null;this.src=\'/images/logo.png\';" />'
          + '    </button>'
          + '  </td>'
          + '  <td>'
          + '    <a class="image-table-title" href="' + recordUrl + '">' + esc(name) + '</a>'
          + '  </td>'
          + '  <td>'
          + '    <span class="image-card-badge" data-cat="' + esc(row.type_name || catName) + '">' + esc(catName) + '</span>'
          + '  </td>'
          + '  <td>'
          + '    <div class="image-table-caption">' + esc(caption || '—') + '</div>'
          + '  </td>'
          + '  <td>'
          + '    <div class="image-table-actions">'
          + '      <button class="image-table-btn image-preview-btn" type="button" data-img-src="' + esc(imgUrl) + '" data-img-title="' + esc(name) + '" data-img-cat="' + esc(catName) + '" data-img-caption="' + esc(caption) + '" data-img-record="' + esc(recordUrl) + '">Zoom</button>'
          + '      <a class="image-table-btn" href="' + recordUrl + '">Record</a>'
          + '      <button class="image-table-btn image-copy-btn" type="button" data-copy-value="' + esc(imgUrl) + '">Copy</button>'
          + '    </div>'
          + '  </td>'
          + '</tr>';
      }).join('');

      container.innerHTML = '<div class="mgdb-table-scroll">'
        + '<table class="mgdb-table image-table">'
        + '  <thead>'
        + '    <tr>'
        + '      <th scope="col" style="width: 72px;">Preview</th>'
        + '      <th scope="col">Entity / Title</th>'
        + '      <th scope="col">Category</th>'
        + '      <th scope="col">Caption</th>'
        + '      <th scope="col" style="text-align: right; width: 140px;">Actions</th>'
        + '    </tr>'
        + '  </thead>'
        + '  <tbody>' + rows + '</tbody>'
        + '</table>'
        + '</div>';
    }

    initGalleryCardEvents();
  }

  /* ── Lightbox & Preview Events ──────────────────────────────────────────── */

  function openLightbox(src, title, cat, caption, recordUrl) {
    var modal = byId('image-lightbox-modal');
    if (!modal) return;

    var img = byId('lightbox-img');
    var titleEl = byId('lightbox-title');
    var badgeEl = byId('lightbox-badge');
    var captionEl = byId('lightbox-caption');
    var recordLink = byId('lightbox-record-link');
    var downloadLink = byId('lightbox-download-link');

    if (img) img.src = src;
    if (titleEl) titleEl.textContent = title;
    if (badgeEl) badgeEl.textContent = cat;
    if (captionEl) captionEl.textContent = caption || 'No caption available for this image specimen.';
    if (recordLink) recordLink.href = recordUrl;
    if (downloadLink) downloadLink.href = src;

    if (typeof modal.showModal === 'function') {
      modal.showModal();
    } else {
      modal.setAttribute('open', 'true');
    }
  }

  function closeLightbox() {
    var modal = byId('image-lightbox-modal');
    if (!modal) return;
    if (typeof modal.close === 'function') {
      modal.close();
    } else {
      modal.removeAttribute('open');
    }
  }

  function initGalleryCardEvents() {
    // Figure and zoom button clicks open lightbox
    Array.prototype.forEach.call(document.querySelectorAll('.image-card-figure, .image-preview-btn'), function (el) {
      el.addEventListener('click', function () {
        var src = el.getAttribute('data-img-src');
        var title = el.getAttribute('data-img-title');
        var cat = el.getAttribute('data-img-cat');
        var caption = el.getAttribute('data-img-caption');
        var recordUrl = el.getAttribute('data-img-record');
        openLightbox(src, title, cat, caption, recordUrl);
      });
    });

    // Copy buttons
    Array.prototype.forEach.call(document.querySelectorAll('.image-copy-btn'), function (btn) {
      btn.addEventListener('click', function () {
        var val = btn.getAttribute('data-copy-value');
        if (!val) return;
        var original = btn.textContent;
        function finish(ok) {
          btn.textContent = ok ? 'Copied!' : 'Press Cmd+C';
          window.setTimeout(function () { btn.textContent = original; }, 1600);
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(val).then(function () { finish(true); }).catch(function () { finish(false); });
        } else {
          finish(false);
        }
      });
    });
  }

  function initLightboxModal() {
    var modal = byId('image-lightbox-modal');
    var closeBtn = byId('lightbox-close-btn');
    var copyBtn = byId('lightbox-copy-url-btn');

    if (closeBtn) {
      closeBtn.addEventListener('click', closeLightbox);
    }

    if (modal) {
      modal.addEventListener('click', function (e) {
        if (e.target === modal) {
          closeLightbox();
        }
      });
    }

    if (copyBtn) {
      copyBtn.addEventListener('click', function () {
        var img = byId('lightbox-img');
        if (!img || !img.src) return;
        var original = copyBtn.textContent;
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(img.src).then(function () {
            copyBtn.textContent = 'Copied!';
            window.setTimeout(function () { copyBtn.textContent = original; }, 1600);
          });
        }
      });
    }
  }

  /* ── Pagination ─────────────────────────────────────────────────────────── */

  function renderPagination(page, pageCount) {
    var nav = byId('image-pagination');
    if (!nav) return;

    if (state.pageSize === 'all' || pageCount <= 1) {
      nav.innerHTML = '';
      return;
    }

    var pages = [];
    var start = Math.max(1, page - 2);
    var end = Math.min(pageCount, page + 2);

    pages.push('<button type="button" data-page="' + Math.max(1, page - 1) + '"' + (page === 1 ? ' disabled' : '') + '>Previous</button>');
    if (start > 1) pages.push('<button type="button" data-page="1">1</button>');
    if (start > 2) pages.push('<button type="button" disabled>&hellip;</button>');

    for (var i = start; i <= end; i += 1) {
      pages.push('<button type="button" data-page="' + i + '" class="' + (i === page ? 'is-active' : '') + '" aria-current="' + (i === page ? 'page' : 'false') + '">' + i + '</button>');
    }

    if (end < pageCount - 1) pages.push('<button type="button" disabled>&hellip;</button>');
    if (end < pageCount) pages.push('<button type="button" data-page="' + pageCount + '">' + pageCount + '</button>');
    pages.push('<button type="button" data-page="' + Math.min(pageCount, page + 1) + '"' + (page === pageCount ? ' disabled' : '') + '>Next</button>');

    nav.innerHTML = pages.join('');

    Array.prototype.forEach.call(nav.querySelectorAll('[data-page]'), function (btn) {
      btn.addEventListener('click', function () {
        var targetPage = parseInt(btn.getAttribute('data-page'), 10);
        if (targetPage && targetPage !== state.page) {
          state.page = targetPage;
          executeSearch(true);
        }
      });
    });
  }

  /* Narrows the page already rendered, in both the card and the table view.
     The gallery pages server side, so this filters what is on screen rather
     than the whole result set, and the status line says so. */
  function applyResultsFilter() {
    var container = byId('image-results');
    if (!container) { return; }

    /* The card view renders `.mgdb-image-card`; the table view renders rows.
       One selector covers both so the filter works in either. */
    var items = container.querySelectorAll('.mgdb-image-card, tbody tr');
    var terms = state.filter.toLowerCase().split(/\s+/).filter(Boolean);
    var shown = 0;

    Array.prototype.forEach.call(items, function (item) {
      var hay = (item.textContent || '').toLowerCase();
      var match = true;
      for (var i = 0; i < terms.length; i++) {
        if (hay.indexOf(terms[i]) === -1) { match = false; break; }
      }
      item.hidden = !match;
      if (match) { shown++; }
    });

    if (!terms.length) { return; }

    var status = byId('image-results-status');
    var total = state.currentData && state.currentData.summary ? state.currentData.summary.total : 0;
    if (status) {
      status.textContent = shown === 0
        ? 'Nothing on this page matches the filter “' + state.filter + '”. '
          + number(total) + ' images matched the search.'
        : 'Showing ' + number(shown) + ' of the ' + number(items.length)
          + ' images on this page matching “' + state.filter + '”, out of '
          + number(total) + ' matched by the search.';
    }
  }

  /* ── View Switcher (Cards vs Table) ─────────────────────────────────────── */

  function setView(view) {
    state.view = view || 'card';
    Array.prototype.forEach.call(document.querySelectorAll('.image-view-btn'), function (btn) {
      var active = btn.getAttribute('data-view') === state.view;
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    updateUrlParams();
    if (state.currentData) {
      renderResults(state.currentData);
    }
  }

  function initViewToggle() {
    Array.prototype.forEach.call(document.querySelectorAll('.image-view-btn'), function (btn) {
      btn.addEventListener('click', function () {
        var targetView = btn.getAttribute('data-view');
        if (targetView && targetView !== state.view) {
          setView(targetView);
        }
      });
    });
  }

  /* ── Category Pill Bar & Form Controls ──────────────────────────────────── */

  function setCategory(cat, executeNow) {
    state.category = cat || 'all';
    state.page = 1;

    /* The category pill bar was removed from the search panel: the Categories
       section below is the browse affordance, and the advanced panel is where
       the filter lives, the same as every other hub. Keeping the select in step
       is what makes the category cards and the figure legible as searches. */
    var select = byId('image-filter-category');
    if (select) { select.value = state.category; }
    if (state.category !== 'all') {
      var adv = byId('image-adv');
      if (adv) { adv.open = true; }
    }

    if (executeNow) {
      executeSearch(true);
    }
  }

  function initForm() {
    var form = byId('image-search-form');
    var input = byId('image-query');
    var clearBtn = byId('image-query-clear');
    var sortSelect = byId('image-sort');

    if (input) {
      input.value = state.term;
      if (clearBtn) clearBtn.hidden = !state.term;

      input.addEventListener('input', function () {
        if (clearBtn) clearBtn.hidden = !input.value;
      });
    }

    if (sortSelect && state.sort) {
      sortSelect.value = state.sort;
    }

    if (clearBtn && input) {
      clearBtn.addEventListener('click', function () {
        input.value = '';
        clearBtn.hidden = true;
        state.term = '';
        state.page = 1;
        executeSearch(false);
      });
    }

    if (form && input) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        state.term = input.value.trim();
        state.sort = sortSelect ? sortSelect.value : 'latest';
        state.page = 1;
        executeSearch(true);
      });
    }

    if (sortSelect) {
      sortSelect.addEventListener('change', function () {
        state.sort = sortSelect.value;
        state.page = 1;
        executeSearch(true);
      });
    }

    // Category select in the advanced panel
    var catSelect = byId('image-filter-category');
    if (catSelect) {
      catSelect.addEventListener('change', function () {
        setCategory(catSelect.value, state.searched);
      });
    }

    // Results-per-page
    var sizeSelect = byId('image-page-size');
    if (sizeSelect) {
      sizeSelect.addEventListener('change', function () {
        state.pageSize = sizeSelect.value === 'all' ? 'all' : parseInt(sizeSelect.value, 10) || 25;
        state.page = 1;
        if (state.searched) { executeSearch(false); }
      });
    }

    // Filter within the rendered page
    var resultsFilter = byId('image-results-filter');
    if (resultsFilter) {
      resultsFilter.addEventListener('input', function () {
        state.filter = resultsFilter.value.trim();
        if (state.filter === '' && state.currentData) {
          renderResults(state.currentData);
        }
        applyResultsFilter();
      });
    }

    var advReset = byId('image-adv-reset');
    if (advReset) {
      advReset.addEventListener('click', function () {
        if (catSelect) { catSelect.value = 'all'; }
        if (sortSelect) { sortSelect.value = 'latest'; }
        state.sort = 'latest';
        setCategory('all', state.searched);
      });
    }

    // Category jump buttons in category grid
    Array.prototype.forEach.call(document.querySelectorAll('[data-switch-cat]'), function (btn) {
      btn.addEventListener('click', function () {
        setCategory(btn.getAttribute('data-switch-cat'), true);
      });
    });

    // Examples
    Array.prototype.forEach.call(document.querySelectorAll('[data-img-example]'), function (btn) {
      btn.addEventListener('click', function () {
        var ex = btn.getAttribute('data-img-example');
        if (input) {
          input.value = ex;
          if (clearBtn) clearBtn.hidden = false;
        }
        state.term = ex;
        state.page = 1;
        executeSearch(true);
      });
    });

    var emptyReset = byId('image-empty-reset');
    if (emptyReset) {
      emptyReset.addEventListener('click', function () {
        if (input) { input.value = ''; if (clearBtn) clearBtn.hidden = true; }
        if (sortSelect) sortSelect.value = 'latest';
        state.term = '';
        state.sort = 'latest';
        setCategory('all', true);
      });
    }
  }

  /* ── Bootstrap ──────────────────────────────────────────────────────────── */

  function init() {
    readUrlParams();
    buildTabs();
    initLightboxModal();
    initViewToggle();
    initForm();
    setCategory(state.category, false);
    setView(state.view);
    updateExportLinks();
    initFigure();

    /* The gallery used to load 24 images on every page view -- a 2 s request
       nobody had asked for. It runs now only when the reader has asked for
       something: a term or a category in the URL, or a search on the page. */
    if (state.term || (state.category && state.category !== 'all')) {
      executeSearch(false);
    }
  }

  /* ── Images by category ─────────────────────────────────────────────────── */

  /* .mgdb-chart is a fixed 320px in the design system, so the height has to be
     set on the element and handed to Plotly from the same variable. */
  function sizeChart(id, height) {
    var el = byId(id);
    if (el) { el.style.height = height + 'px'; }
    return height;
  }

  function readAttrJson(el, name) {
    if (!el) { return null; }
    try { return JSON.parse(el.getAttribute(name) || 'null'); }
    catch (error) { return null; }
  }

  function initFigure() {
    var el = byId('image-category-chart');
    if (!el || !window.MGDB || !window.MGDB.chart) { return; }

    var labels = readAttrJson(el, 'data-labels');
    var values = readAttrJson(el, 'data-values');
    var cats = readAttrJson(el, 'data-cats') || [];
    if (!labels || !values || !labels.length) { return; }

    var height = sizeChart('image-category-chart', Math.max(320, labels.length * 40 + 110));

    window.MGDB.chart({
      target: 'image-category-chart',
      traces: [{
        type: 'bar',
        orientation: 'h',
        x: values,
        y: labels,
        text: values.map(function (value) { return '\u00A0' + Number(value).toLocaleString(); }),
        textposition: 'outside',
        textangle: 0,
        cliponaxis: false,
        marker: { color: '#285d46' },
        hovertemplate: '%{y}<br>%{x:,} images<extra></extra>'
      }],
      layout: {
        height: height,
        margin: { l: 10, r: 88, t: 8, b: 48 },
        bargap: 0.3,
        xaxis: { title: { text: 'Curated images' }, automargin: true },
        yaxis: { type: 'category', automargin: true }
      }
    });

    /* Selecting a bar searches that category. Plotly only gains its event
       emitter once it has drawn, so wait for the draw. */
    if (!window.MutationObserver) { return; }
    var attached = false;
    var observer = new window.MutationObserver(function () {
      if (attached || typeof el.on !== 'function') { return; }
      attached = true;
      observer.disconnect();
      el.on('plotly_click', function (event) {
        if (!event || !event.points || !event.points.length) { return; }
        var index = labels.indexOf(event.points[0].y);
        if (index === -1 || !cats[index]) { return; }
        setCategory(cats[index], true);
      });
    });
    observer.observe(el, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
