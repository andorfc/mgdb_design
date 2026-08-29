/**
 * Map Record Page — Client Controller (/data_center/map/{id})
 * Built on the MaizeGDB modern design system (Pattern Library & Stock Record patterns).
 */

(function (window, document) {
  'use strict';

  var REF_PAGE_SIZE = 5;
  var LOCI_PAGE_SIZE = 50;

  var state = {
    mapId: null,
    record: null,
    lociFilter: '',
    backboneOnly: false,
    lociPage: 1,
    filteredLoci: [],
    allReferences: [],
    refCurrentPage: 1
  };

  var el = {};

  function byId(id) {
    return document.getElementById(id);
  }

  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function show(element, isVisible) {
    if (element) element.hidden = !isVisible;
  }

  function copyToClipboard(text, btnEl, successMsg) {
    navigator.clipboard.writeText(text).then(function () {
      if (btnEl) {
        var orig = btnEl.textContent;
        btnEl.textContent = successMsg || 'Copied!';
        btnEl.classList.add('is-copied');
        setTimeout(function () {
          btnEl.textContent = orig;
          btnEl.classList.remove('is-copied');
        }, 2000);
      }
    }).catch(function (err) {
      console.error('Clipboard copy failed', err);
    });
  }

  function fact(label, value, note) {
    if (!value && value !== 0) return '';
    return '<div><dt>' + escapeHtml(label) + '</dt><dd>' + value +
           (note ? '<small>' + escapeHtml(note) + '</small>' : '') + '</dd></div>';
  }

  function block(title, description, body) {
    if (!body) return '';
    return '<div class="map-record-block"><h3>' + escapeHtml(title) + '</h3>' +
           (description ? '<p>' + escapeHtml(description) + '</p>' : '') + body + '</div>';
  }

  /* ── Initialization ─────────────────────────────────────────────────────── */

  function init() {
    var top = document.querySelector('[data-map-id]');
    if (!top) return;

    state.mapId = top.getAttribute('data-map-id');
    if (!state.mapId) return;

    el = {
      loading: byId('map-record-loading'),
      error: byId('map-record-error'),
      notice: byId('map-record-notice'),
      facts: byId('map-record-facts'),
      actions: byId('map-record-actions'),
      tabs: byId('map-record-tabs'),
      overviewSection: byId('map-record-overview'),
      overviewBody: byId('map-record-overview-body'),
      lociSection: byId('map-record-loci'),
      lociBody: byId('map-record-loci-body'),
      seriesSection: byId('map-record-series'),
      seriesBody: byId('map-record-series-body'),
      altSection: byId('map-record-alt'),
      altBody: byId('map-record-alt-body'),
      qtlsSection: byId('map-record-qtls'),
      qtlsBody: byId('map-record-qtls-body'),
      referencesSection: byId('map-record-references'),
      referencesBody: byId('map-record-references-body'),
      referencesStatus: byId('map-record-ref-status'),
      referencesPagination: byId('map-record-ref-pagination'),
      copyJsonBtn: byId('map-copy-json-btn'),
      retryBtn: byId('map-record-retry')
    };

    if (el.retryBtn) {
      el.retryBtn.addEventListener('click', function () {
        loadRecord();
      });
    }

    if (el.copyJsonBtn) {
      el.copyJsonBtn.addEventListener('click', function () {
        if (state.record) {
          copyToClipboard(JSON.stringify(state.record, null, 2), el.copyJsonBtn, 'JSON copied!');
        }
      });
    }

    loadRecord();
  }

  function loadRecord() {
    show(el.loading, true);
    show(el.error, false);

    fetch('/api/v1/records/map/' + encodeURIComponent(state.mapId))
      .then(function (res) {
        if (!res.ok) throw new Error('API returned HTTP ' + res.status);
        return res.json();
      })
      .then(function (data) {
        var mapData = (data && data.data) ? data.data : data;
        if (!mapData || !mapData.sections) {
          throw new Error('Malformed API payload: sections missing');
        }
        show(el.loading, false);
        state.record = mapData;
        renderRecord(mapData, (data.meta && data.meta.counts) || {});
      })
      .catch(function (err) {
        show(el.loading, false);
        show(el.error, true);
        console.error('Failed to load map record', err);
      });
  }

  /* ── Render Record ──────────────────────────────────────────────────────── */

  function renderRecord(mapData, counts) {
    var attr = mapData.attributes || {};
    var sections = mapData.sections || {};
    var overview = sections.overview || {};
    var coords = sections.coordinates || [];
    var related = sections.related_maps || {};
    var refs = sections.references || [];
    var qtls = sections.qtl_experiments || [];

    // Hero facts and actions
    renderHero(attr, overview, counts, coords);

    // Overview section (Pattern Library .map-record-grid)
    renderOverview(overview, attr);

    // Mapped loci section
    if (coords && coords.length > 0) {
      renderLociSection(coords, overview, attr);
      show(el.lociSection, true);
    }

    // Sister maps series
    if (related.sister_maps && related.sister_maps.length > 0) {
      renderSisterMaps(related.sister_maps, related.series_name);
      show(el.seriesSection, true);
    }

    // Same chromosome alternatives
    if (related.same_chromosome_maps && related.same_chromosome_maps.length > 0) {
      renderSameChromosomeMaps(related.same_chromosome_maps, overview.linkage_group || attr.linkage_group);
      show(el.altSection, true);
    }

    // QTL experiments
    if (qtls && qtls.length > 0) {
      renderQTLs(qtls);
      show(el.qtlsSection, true);
    }

    // References
    if (refs && refs.length > 0) {
      renderReferences(refs);
      show(el.referencesSection, true);
    }

    // Dynamic sticky section tabs and scrollspy
    buildDynamicTabs(sections, coords, related, refs, qtls);
  }

  /* ── Hero Facts & Actions ───────────────────────────────────────────────── */

  function renderHero(attr, overview, counts, coords) {
    if (el.facts) {
      var minC = (overview.min_coord !== undefined && overview.min_coord !== null) ? overview.min_coord : null;
      var maxC = (overview.max_coord !== undefined && overview.max_coord !== null) ? overview.max_coord : null;
      var units = overview.coordinate_type || attr.coordinate_type || 'cM';

      var spanStr = (minC !== null && maxC !== null)
        ? minC.toFixed(1) + ' &ndash; ' + maxC.toFixed(1) + ' ' + escapeHtml(units)
        : escapeHtml(units);

      var totalLoci = overview.locus_count || attr.locus_count || (counts && counts.coordinates) || coords.length;
      var authorName = overview.author ? overview.author.name : '';

      var factsHtml = '';
      factsHtml += fact('Map ID', escapeHtml(overview.id || state.mapId));
      factsHtml += fact('Chromosome', overview.linkage_group ? ('Chr ' + escapeHtml(overview.linkage_group)) : (attr.linkage_group ? ('Chr ' + escapeHtml(attr.linkage_group)) : ''));
      factsHtml += fact('Units & Span', spanStr);
      factsHtml += fact('Mapped Loci', totalLoci ? totalLoci.toLocaleString() : '');
      if (authorName) factsHtml += fact('Author / Source', escapeHtml(authorName));

      el.facts.innerHTML = factsHtml;
    }

    if (el.actions) {
      var actionsHtml =
        '<a class="mgdb-button mgdb-button-primary" href="/compare_maps?map1=' + encodeURIComponent(state.mapId) + '">Compare this map</a>' +
        '<a class="mgdb-button mgdb-button-secondary" href="/displaycompletemaprecord.cgi?id=' + encodeURIComponent(state.mapId) + '">Full map set</a>';

      el.actions.innerHTML = actionsHtml;
    }
  }

  /* ── Overview Section (Pattern Library Grid & Blocks) ───────────────────── */

  function renderOverview(overview, attr) {
    if (!el.overviewBody) return;

    var minC = (overview.min_coord !== undefined && overview.min_coord !== null) ? overview.min_coord : null;
    var maxC = (overview.max_coord !== undefined && overview.max_coord !== null) ? overview.max_coord : null;
    var units = overview.coordinate_type || attr.coordinate_type || 'cM';

    var spanFormatted = (minC !== null && maxC !== null)
      ? minC.toFixed(2) + ' &ndash; ' + maxC.toFixed(2) + ' ' + escapeHtml(units)
      : escapeHtml(units);

    var lengthNote = (minC !== null && maxC !== null)
      ? 'Total map length: ' + (maxC - minC).toFixed(2) + ' ' + escapeHtml(units)
      : '';

    var totalLoci = overview.locus_count || attr.locus_count || 0;
    var authorName = overview.author ? overview.author.name : 'MaizeGDB Curation';

    var factsHtml = '';
    factsHtml += fact('Map Name', escapeHtml(overview.name || attr.name || ''));
    factsHtml += fact('Chromosome / Linkage Group', 'Chromosome ' + escapeHtml(overview.linkage_group || attr.linkage_group || '—'));
    factsHtml += fact('Coordinate Units', escapeHtml(units));
    factsHtml += fact('Mapped Span', spanFormatted, lengthNote);
    factsHtml += fact('Mapped Loci', totalLoci ? (totalLoci.toLocaleString() + ' positioned markers') : '0 positioned markers');
    factsHtml += fact('Source / Contributor', escapeHtml(authorName));

    var html = factsHtml ? '<dl class="map-record-grid">' + factsHtml + '</dl>' : '';

    if (overview.memos && overview.memos.length) {
      var memoBody = overview.memos.map(function (m) {
        return '<p>' + m + '</p>';
      }).join('');
      html += block('Curator notes &amp; methodology', '',
        '<div class="map-record-notes">' + memoBody + '</div>');
    }

    el.overviewBody.innerHTML = html;
    show(el.overviewSection, true);
  }

  /* ── Mapped Loci & Coordinates Table ────────────────────────────────────── */

  function renderLociSection(coords, overview, attr) {
    if (!el.lociBody) return;

    state.filteredLoci = coords;

    var toolbarHtml =
      '<div class="map-loci-toolbar">' +
      '  <div class="map-loci-filters">' +
      '    <input class="map-loci-search" id="map-loci-search-input" type="search" placeholder="Filter by locus name, symbol, or bin…" />' +
      '    <label class="map-checkbox-label">' +
      '      <input type="checkbox" id="map-loci-backbone-toggle" />' +
      '      <span>Backbone markers only</span>' +
      '    </label>' +
      '  </div>' +
      '  <div>' +
      '    <button class="mgdb-button mgdb-button-quiet" id="map-loci-export-tsv" type="button">Download TSV &darr;</button>' +
      '  </div>' +
      '</div>' +
      '<div id="map-loci-table-container"></div>' +
      '<nav class="map-pagination" id="map-loci-pagination" aria-label="Loci table pages"></nav>';

    el.lociBody.innerHTML = toolbarHtml;

    var searchInput = byId('map-loci-search-input');
    var backboneToggle = byId('map-loci-backbone-toggle');
    var exportBtn = byId('map-loci-export-tsv');

    if (searchInput) {
      searchInput.addEventListener('input', function () {
        state.lociFilter = this.value.trim().toLowerCase();
        state.lociPage = 1;
        applyLociFilter(coords);
      });
    }

    if (backboneToggle) {
      backboneToggle.addEventListener('change', function () {
        state.backboneOnly = this.checked;
        state.lociPage = 1;
        applyLociFilter(coords);
      });
    }

    if (exportBtn) {
      exportBtn.addEventListener('click', function () {
        exportLociTsv(coords, overview, attr);
      });
    }

    applyLociFilter(coords);
  }

  function applyLociFilter(allCoords) {
    state.filteredLoci = allCoords.filter(function (locus) {
      if (state.backboneOnly && !locus.is_backbone) return false;
      if (state.lociFilter) {
        var matchName = locus.name && locus.name.toLowerCase().indexOf(state.lociFilter) !== -1;
        var matchFullName = locus.full_name && locus.full_name.toLowerCase().indexOf(state.lociFilter) !== -1;
        var matchBin = locus.bin && String(locus.bin).toLowerCase().indexOf(state.lociFilter) !== -1;
        var matchType = locus.locus_type && locus.locus_type.toLowerCase().indexOf(state.lociFilter) !== -1;
        if (!matchName && !matchFullName && !matchBin && !matchType) return false;
      }
      return true;
    });

    renderLociPage();
  }

  function renderLociPage() {
    var container = byId('map-loci-table-container');
    var paginationEl = byId('map-loci-pagination');
    if (!container) return;

    var total = state.filteredLoci.length;
    var totalPages = Math.ceil(total / LOCI_PAGE_SIZE);
    if (state.lociPage > totalPages) state.lociPage = Math.max(1, totalPages);

    if (total === 0) {
      container.innerHTML = '<div class="mgdb-message mgdb-message-info"><div><span>No locus coordinates matched your filter.</span></div></div>';
      if (paginationEl) paginationEl.innerHTML = '';
      return;
    }

    var start = (state.lociPage - 1) * LOCI_PAGE_SIZE;
    var end = Math.min(start + LOCI_PAGE_SIZE, total);
    var pageItems = state.filteredLoci.slice(start, end);

    var html = '<div class="map-table-wrapper">' +
      '<table class="map-loci-table">' +
      '<thead><tr>' +
      '  <th>Locus Symbol</th>' +
      '  <th>Coordinate</th>' +
      '  <th>Bin</th>' +
      '  <th>Backbone</th>' +
      '  <th>Locus Type</th>' +
      '  <th>Full Name / Description</th>' +
      '</tr></thead><tbody>';

    pageItems.forEach(function (locus) {
      var coordStr = (locus.coordinate !== null && locus.coordinate !== undefined) ? locus.coordinate.toFixed(2) : '—';
      var binStr = locus.bin ? escapeHtml(locus.bin) : '<span style="color:var(--mgdb-muted);">—</span>';
      var backboneStr = locus.is_backbone
        ? '<span class="map-backbone-pill">Backbone</span>'
        : '<span style="color:var(--mgdb-muted);">—</span>';

      html += '<tr>' +
        '  <td class="map-locus-cell"><strong><a href="' + escapeHtml(locus.html) + '">' + escapeHtml(locus.name) + '</a></strong></td>' +
        '  <td><strong>' + coordStr + '</strong></td>' +
        '  <td>' + binStr + '</td>' +
        '  <td>' + backboneStr + '</td>' +
        '  <td>' + (locus.locus_type ? escapeHtml(locus.locus_type) : '—') + '</td>' +
        '  <td>' + (locus.full_name ? escapeHtml(locus.full_name) : '<span style="color:var(--mgdb-muted);">—</span>') + '</td>' +
        '</tr>';
    });

    html += '</tbody></table></div>';
    container.innerHTML = html;

    if (paginationEl) {
      renderTablePagination(paginationEl, totalPages, state.lociPage, function (newPage) {
        state.lociPage = newPage;
        renderLociPage();
      });
      show(paginationEl, totalPages > 1);
    }
  }

  function exportLociTsv(coords, overview, attr) {
    var tsv = 'Locus Name\tCoordinate\tBin\tIs Backbone\tLocus Type\tFull Name\tURL\n';
    coords.forEach(function (l) {
      tsv += (l.name || '') + '\t' +
             (l.coordinate !== null ? l.coordinate : '') + '\t' +
             (l.bin || '') + '\t' +
             (l.is_backbone ? 'YES' : 'NO') + '\t' +
             (l.locus_type || '') + '\t' +
             (l.full_name || '') + '\t' +
             ('https://maizegdb.org' + (l.html || '')) + '\n';
    });

    var mapName = overview.name || attr.name || ('map_' + state.mapId);
    var blob = new Blob([tsv], { type: 'text/tab-separated-values;charset=utf-8' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = mapName.replace(/\s+/g, '_') + '_loci.tsv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }

  /* ── Sister Series & Alternative Maps ───────────────────────────────────── */

  function renderSisterMaps(sisterMaps, seriesName) {
    if (!el.seriesBody) return;

    var html = '<div class="map-sister-grid">';
    sisterMaps.forEach(function (m) {
      html += '<article class="map-sister-card">' +
        '  <div>' +
        '    <span class="map-chr-pill">Chr ' + escapeHtml(m.linkage_group) + '</span>' +
        '    <h3 style="margin:var(--mgdb-space-1) 0 2px;font-size:var(--mgdb-text-base);"><a href="' + escapeHtml(m.html) + '">' + escapeHtml(m.name) + '</a></h3>' +
        '  </div>' +
        '  <div style="display:flex;justify-content:space-between;align-items:center;margin-top:var(--mgdb-space-2);padding-top:var(--mgdb-space-2);border-top:1px dashed var(--mgdb-line-soft);">' +
        '    <span class="map-loci-badge">' + (m.locus_count ? m.locus_count.toLocaleString() : '0') + ' loci</span>' +
        '    <a class="mgdb-button mgdb-button-quiet" href="' + escapeHtml(m.html) + '">View map &rarr;</a>' +
        '  </div>' +
        '</article>';
    });
    html += '</div>';

    el.seriesBody.innerHTML = html;
  }

  function renderSameChromosomeMaps(altMaps, linkage) {
    if (!el.altBody) return;

    var html = '<div class="map-alt-grid">';
    altMaps.forEach(function (m) {
      html += '<article class="map-alt-card">' +
        '  <div>' +
        '    <strong><a href="' + escapeHtml(m.html) + '">' + escapeHtml(m.name) + '</a></strong>' +
        '    <p style="margin:2px 0 0;color:var(--mgdb-muted);font-size:var(--mgdb-text-xs);">' + (m.locus_count ? m.locus_count.toLocaleString() : '0') + ' mapped loci</p>' +
        '  </div>' +
        '  <div class="map-alt-actions">' +
        '    <a class="mgdb-button mgdb-button-secondary" href="' + escapeHtml(m.html) + '">View map &rarr;</a>' +
        '    <a class="mgdb-button mgdb-button-quiet" href="' + escapeHtml(m.compare_html) + '">Compare &nearr;</a>' +
        '  </div>' +
        '</article>';
    });
    html += '</div>';

    el.altBody.innerHTML = html;
  }

  /* ── QTL Experiments ────────────────────────────────────────────────────── */

  function renderQTLs(qtls) {
    if (!el.qtlsBody) return;

    var html = '<div class="map-table-wrapper">' +
      '<table class="map-loci-table">' +
      '<thead><tr>' +
      '  <th>QTL Experiment</th>' +
      '  <th>Trait</th>' +
      '  <th>Actions</th>' +
      '</tr></thead><tbody>';

    qtls.forEach(function (q) {
      html += '<tr>' +
        '  <td><strong><a href="' + escapeHtml(q.html) + '">' + escapeHtml(q.name) + '</a></strong></td>' +
        '  <td>' + escapeHtml(q.trait || '—') + '</td>' +
        '  <td><a href="' + escapeHtml(q.html) + '">View experiment &rarr;</a></td>' +
        '</tr>';
    });

    html += '</tbody></table></div>';
    el.qtlsBody.innerHTML = html;
  }

  /* ── Curated References (Matching Reference & Stock Center) ─────────────── */

  function renderReferences(references) {
    if (!references || !references.length) return;
    state.allReferences = references;
    state.refCurrentPage = 1;
    renderReferencePage();
  }

  function renderReferencePage() {
    var total = state.allReferences.length;
    var totalPages = Math.ceil(total / REF_PAGE_SIZE);
    if (state.refCurrentPage > totalPages) state.refCurrentPage = totalPages;
    if (state.refCurrentPage < 1) state.refCurrentPage = 1;

    var start = (state.refCurrentPage - 1) * REF_PAGE_SIZE;
    var end = Math.min(start + REF_PAGE_SIZE, total);
    var pageSlice = state.allReferences.slice(start, end);

    var html = pageSlice.map(function (ref) {
      var yearBadge = ref.year ? '<span class="reference-year">' + escapeHtml(ref.year) + '</span>' : '';
      var topicBadge = ref.relevance ? '<span class="mgdb-pill mgdb-pill-ok">' + escapeHtml(ref.relevance) + '</span>' : '';
      var typeBadge = ref.pub_type ? '<span class="mgdb-pill mgdb-pill-info">' + escapeHtml(ref.pub_type) + '</span>' : '<span class="mgdb-pill mgdb-pill-info">Journal article</span>';
      var idBadge = ref.id ? '<span class="reference-copy-id">ID: ' + ref.id + '</span>' : '';

      var title = ref.title || ref.citation || 'Untitled publication';
      var authors = ref.authors ? '<p class="reference-card-authors">' + escapeHtml(ref.authors) + '</p>' : '';
      var citation = ref.citation ? '<p class="reference-card-journal">' + escapeHtml(ref.citation) + '</p>' : '';

      var readBtn = ref.doi
        ? '<a class="mgdb-button mgdb-button-quiet" href="https://doi.org/' + encodeURIComponent(ref.doi) + '" target="_blank" rel="noopener">Read paper &nearr;</a>'
        : '';

      var fullCitation = (ref.authors ? ref.authors + '. ' : '') +
                         (ref.year ? '(' + ref.year + '). ' : '') +
                         title + '. ' +
                         (ref.citation ? ref.citation + '. ' : '') +
                         (ref.doi ? 'doi:' + ref.doi : '');

      return '<article class="reference-result-card">' +
        '<div>' +
          '<div class="reference-result-meta">' +
            yearBadge + topicBadge + typeBadge + idBadge +
          '</div>' +
          '<h3 class="reference-card-title"><a href="' + escapeHtml(ref.html) + '">' + escapeHtml(title) + '</a></h3>' +
          authors +
          citation +
        '</div>' +
        '<div class="reference-card-actions">' +
          '<a class="mgdb-button mgdb-button-secondary" href="' + escapeHtml(ref.html) + '">Reference record &rarr;</a>' +
          readBtn +
          '<button class="mgdb-button mgdb-button-quiet" type="button" data-copy-citation="' + escapeHtml(fullCitation) + '">Copy citation</button>' +
          (ref.doi ? '<button class="mgdb-button mgdb-button-quiet" type="button" data-copy-doi="' + escapeHtml(ref.doi) + '">Copy DOI</button>' : '') +
        '</div>' +
      '</article>';
    }).join('');

    el.referencesBody.innerHTML = html;
    bindReferenceCopyHandlers();

    if (el.referencesStatus) {
      el.referencesStatus.textContent = 'Showing ' + (start + 1) + '–' + end + ' of ' + total.toLocaleString() + ' curated publications.';
    }

    if (el.referencesPagination) {
      renderTablePagination(el.referencesPagination, totalPages, state.refCurrentPage, function (newPage) {
        state.refCurrentPage = newPage;
        renderReferencePage();
      });
      show(el.referencesPagination, totalPages > 1);
    }
  }

  function bindReferenceCopyHandlers() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-copy-citation]'), function (btn) {
      btn.addEventListener('click', function () {
        var text = btn.getAttribute('data-copy-citation');
        copyToClipboard(text, btn, 'Citation copied!');
      });
    });

    Array.prototype.forEach.call(document.querySelectorAll('[data-copy-doi]'), function (btn) {
      btn.addEventListener('click', function () {
        var doi = btn.getAttribute('data-copy-doi');
        copyToClipboard('https://doi.org/' + doi, btn, 'DOI copied!');
      });
    });
  }

  /* ── Generic Table Pagination ───────────────────────────────────────────── */

  function renderTablePagination(paginationEl, totalPages, curPage, onPageChange) {
    if (totalPages <= 1) {
      paginationEl.innerHTML = '';
      return;
    }

    var html = '';
    html += '<button class="map-page-btn" type="button" data-page="' + (curPage - 1) + '" ' + (curPage === 1 ? 'disabled' : '') + '>&larr; Prev</button>';

    var pages = [];
    pages.push(1);
    if (curPage > 3) pages.push('...');
    for (var p = Math.max(2, curPage - 1); p <= Math.min(totalPages - 1, curPage + 1); p++) {
      pages.push(p);
    }
    if (curPage < totalPages - 2) pages.push('...');
    if (totalPages > 1) pages.push(totalPages);

    pages.forEach(function (p) {
      if (p === '...') {
        html += '<span class="map-page-ellipsis">&hellip;</span>';
      } else {
        html += '<button class="map-page-btn ' + (p === curPage ? 'is-active' : '') + '" type="button" data-page="' + p + '">' + p + '</button>';
      }
    });

    html += '<button class="map-page-btn" type="button" data-page="' + (curPage + 1) + '" ' + (curPage === totalPages ? 'disabled' : '') + '>Next &rarr;</button>';

    paginationEl.innerHTML = html;

    paginationEl.querySelectorAll('button[data-page]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var page = parseInt(this.getAttribute('data-page'), 10);
        if (page && page !== curPage && page >= 1 && page <= totalPages) {
          onPageChange(page);
        }
      });
    });
  }

  /* ── Dynamic Section Tabs & Scrollspy ────────────────────────────────────── */

  function buildDynamicTabs(sections, coords, related, refs, qtls) {
    if (!el.tabs) return;

    var tabs = [];
    tabs.push({ id: 'map-record-overview', label: 'Overview' });

    if (coords && coords.length > 0) {
      tabs.push({ id: 'map-record-loci', label: 'Mapped loci', count: coords.length });
    }
    if (related.sister_maps && related.sister_maps.length > 0) {
      tabs.push({ id: 'map-record-series', label: 'Sister chromosome maps', count: related.sister_maps.length });
    }
    if (related.same_chromosome_maps && related.same_chromosome_maps.length > 0) {
      tabs.push({ id: 'map-record-alt', label: 'Same chromosome maps', count: related.same_chromosome_maps.length });
    }
    if (qtls && qtls.length > 0) {
      tabs.push({ id: 'map-record-qtls', label: 'QTL experiments', count: qtls.length });
    }
    if (refs && refs.length > 0) {
      tabs.push({ id: 'map-record-references', label: 'Curated references', count: refs.length });
    }
    tabs.push({ id: 'map-record-related', label: 'Related resources' });
    tabs.push({ id: 'map-record-provenance', label: 'Provenance' });

    var html = tabs.map(function (t, i) {
      var countBadge = t.count !== undefined
        ? ' <span class="map-record-tab-count">' + t.count.toLocaleString() + '</span>'
        : '';
      var activeClass = i === 0 ? ' class="is-current" aria-current="true"' : '';
      return '<a href="#' + t.id + '"' + activeClass + '>' + escapeHtml(t.label) + countBadge + '</a>';
    }).join('');

    el.tabs.innerHTML = html;
    show(el.tabs, true);

    bindScrollspy();
  }

  function bindScrollspy() {
    var tabs = document.querySelectorAll('.mgdb-section-tabs a');
    if (!tabs.length) return;

    var pairs = [];
    Array.prototype.forEach.call(tabs, function (tab) {
      var href = tab.getAttribute('href');
      if (href && href.indexOf('#') === 0) {
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

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
