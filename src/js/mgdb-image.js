/* Image Data Center JavaScript
   Handles search input, category filtering, responsive gallery rendering,
   lightbox modal preview, sticky scrollspy tabs, pagination, and export URL synchronization. */

(function () {
  'use strict';

  var API_URL = '/search/image/image_search_api.php';

  var state = {
    term: '',
    category: 'all',
    sort: 'latest',
    page: 1,
    pageSize: 24,
    currentData: null,
    loading: false
  };

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

  function buildTabs() {
    var tabs = document.querySelectorAll('.mgdb-section-tabs a');
    if (!tabs.length) return;

    var pairs = [];
    Array.prototype.forEach.call(tabs, function (tab) {
      var href = tab.getAttribute('href');
      if (href && href.startsWith('#')) {
        var section = document.querySelector(href);
        if (section) {
          pairs.push({ tab: tab, section: section });
        }
      }
    });

    function markCurrent(target) {
      pairs.forEach(function (pair) {
        var current = pair.section === target;
        pair.tab.classList.toggle('is-current', current);
        if (current) {
          pair.tab.setAttribute('aria-current', 'true');
        } else {
          pair.tab.removeAttribute('aria-current');
        }
      });
    }

    var initial = pairs[0];
    if (window.location.hash) {
      pairs.forEach(function (pair) {
        if ('#' + pair.section.id === window.location.hash) {
          initial = pair;
        }
      });
    }
    if (initial) {
      markCurrent(initial.section);
    }

    pairs.forEach(function (pair) {
      pair.tab.addEventListener('click', function () {
        markCurrent(pair.section);
      });
    });

    if (!window.IntersectionObserver) return;

    var observer = new window.IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          markCurrent(entry.target);
        }
      });
    }, { rootMargin: '-20% 0px -60% 0px' });

    pairs.forEach(function (pair) {
      observer.observe(pair.section);
    });
  }

  /* ── URL Parameter Sync ─────────────────────────────────────────────────── */

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
    if (params.has('page')) {
      state.page = parseInt(params.get('page'), 10) || 1;
    }
  }

  function updateUrlParams() {
    var params = new URLSearchParams();
    if (state.term) params.set('q', state.term);
    if (state.category && state.category !== 'all') params.set('category', state.category);
    if (state.sort && state.sort !== 'latest') params.set('sort', state.sort);
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
    var grid = byId('image-results-grid');
    var empty = byId('image-empty');

    if (status) {
      status.textContent = 'Searching image collection…';
    }

    var params = new URLSearchParams();
    if (state.term) params.set('term', state.term);
    if (state.category) params.set('category', state.category);
    params.set('sort', state.sort);
    params.set('page', state.page);
    params.set('page_size', state.pageSize);

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
        renderPagination(data.summary.page, data.summary.page_count);

        if (scrollToResults && grid) {
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

  /* ── Render Gallery Cards ───────────────────────────────────────────────── */

  function renderResults(data) {
    var grid = byId('image-results-grid');
    var empty = byId('image-empty');
    var status = byId('image-results-status');
    var summary = data.summary;

    if (!summary.total || summary.total === 0) {
      if (grid) grid.innerHTML = '';
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
      status.textContent = 'Showing ' + number(start) + '–' + number(end) + ' of ' + number(summary.total)
        + ' images' + queryText + ' · ' + number(summary.elapsed_ms) + ' ms';
    }

    if (!grid) return;

    grid.innerHTML = data.results.map(function (row, idx) {
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
    }).join('');

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

    if (pageCount <= 1) {
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

  /* ── Category Pill Bar & Form Controls ──────────────────────────────────── */

  function setCategory(cat, executeNow) {
    state.category = cat || 'all';
    state.page = 1;

    Array.prototype.forEach.call(document.querySelectorAll('.image-cat-pill'), function (pill) {
      var match = pill.getAttribute('data-cat') === state.category;
      pill.classList.toggle('is-active', match);
      pill.setAttribute('aria-selected', match ? 'true' : 'false');
    });

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

    // Category pills
    Array.prototype.forEach.call(document.querySelectorAll('.image-cat-pill'), function (pill) {
      pill.addEventListener('click', function () {
        setCategory(pill.getAttribute('data-cat'), true);
      });
    });

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
    initForm();
    setCategory(state.category, false);
    updateExportLinks();

    // Execute initial search
    executeSearch(false);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
