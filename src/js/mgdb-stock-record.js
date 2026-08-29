/* ==========================================================================
   Stock record page — page behavior
   --------------------------------------------------------------------------
   Companion to /css/mgdb-stock-record.css and
   templates/static/mgdb_stock_record.bau.

   One request to /api/v1/records/stock/{id} builds the whole page.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  if (!MGDB) { return; }

  var CHIP_LIMIT = 40;     // chips shown before the rest collapse behind a toggle
  var PEDIGREE_PROGENY_LIMIT = 12;
  var REF_PAGE_SIZE = 5;    // references per page

  function byId(id) { return document.getElementById(id); }
  function escape(value) { return MGDB.escapeHtml(value); }
  function show(el, visible) { if (el) { el.hidden = !visible; } }

  var els = {};
  var payload = null;
  var allReferences = [];
  var refCurrentPage = 1;

  /* ------------------------------------------------------------------------
     Small builders
     ------------------------------------------------------------------------ */

  function refLink(ref, extraClass) {
    if (!ref || !ref.name) { return ''; }
    var cls = extraClass ? ' class="' + extraClass + '"' : '';
    if (!ref.html) { return '<span' + cls + '>' + escape(ref.name) + '</span>'; }
    return '<a' + cls + ' href="' + escape(ref.html) + '">' + escape(ref.name) + '</a>';
  }

  function fact(label, value, note) {
    if (!value) { return ''; }
    return '<div><dt>' + escape(label) + '</dt><dd>' + value +
           (note ? '<small>' + escape(note) + '</small>' : '') + '</dd></div>';
  }

  function block(title, description, body) {
    if (!body) { return ''; }
    return '<div class="stock-record-block"><h3>' + escape(title) + '</h3>' +
           (description ? '<p>' + escape(description) + '</p>' : '') + body + '</div>';
  }

  function chipList(items, qualifierKey) {
    if (!items || !items.length) { return ''; }

    function chip(item) {
      var qualifier = qualifierKey && item[qualifierKey];
      var extra = '';
      if (qualifier) {
        extra = '<span class="stock-record-qualifier">' +
                escape(typeof qualifier === 'string' ? qualifier : qualifier.name) + '</span>';
      }
      return '<li>' + (item.html
        ? '<a href="' + escape(item.html) + '">' + escape(item.name) + extra + '</a>'
        : '<span>' + escape(item.name) + extra + '</span>') + '</li>';
    }

    var visible = items.slice(0, CHIP_LIMIT).map(chip).join('');
    var html = '<ul class="stock-record-chips">' + visible + '</ul>';

    if (items.length > CHIP_LIMIT) {
      html += '<details class="stock-record-more"><summary>Show the remaining ' +
              (items.length - CHIP_LIMIT).toLocaleString() + '</summary>' +
              '<ul class="stock-record-chips">' +
              items.slice(CHIP_LIMIT).map(chip).join('') + '</ul></details>';
    }
    return html;
  }

  /* ------------------------------------------------------------------------
     Header
     ------------------------------------------------------------------------ */

  function renderHeader(data, sections) {
    var attributes = data.attributes || {};
    var overview = sections.overview || {};

    if (attributes.synonyms && attributes.synonyms.length) {
      els.synonyms.innerHTML = 'Also known as ' +
        attributes.synonyms.map(function (synonym) {
          return '<em>' + escape(synonym.name) + '</em>';
        }).join(', ');
      show(els.synonyms, true);
    }

    var facts = '';
    if (overview.species) { facts += '<div><dt>Species</dt><dd>' + escape(overview.species.name) + '</dd></div>'; }
    if (overview.provider) { facts += '<div><dt>Available from</dt><dd>' + refLink(overview.provider) + '</dd></div>'; }
    if (overview.origin && overview.origin.year) { facts += '<div><dt>Year</dt><dd>' + overview.origin.year + '</dd></div>'; }
    facts += '<div><dt>MaizeGDB ID</dt><dd class="mgdb-record-id">' + escape(data.id) + '</dd></div>';
    els.facts.innerHTML = facts;

    var actions = [];
    var grin = sections.grin;

    if (grin && grin.details && grin.details.order_url) {
      actions.push('<a class="mgdb-button mgdb-button-primary" href="' +
        escape(grin.details.order_url) + '">Request from the PI Station</a>');
    } else if (overview.provider && overview.provider.is_stock_center) {
      actions.push('<a class="mgdb-button mgdb-button-primary" href="https://maizecoopsc.org/" target="_blank" rel="noopener">' +
        'Order from the Stock Center &nearr;</a>');
    }

    var pedigree = sections.pedigree;
    if (pedigree && pedigree.network && pedigree.network.available && pedigree.network.interactive) {
      actions.push('<a class="mgdb-button mgdb-button-secondary" href="' +
        escape(pedigree.network.interactive) + '">Explore pedigree</a>');
    }
    if (sections.typsim && sections.typsim.available) {
      actions.push('<a class="mgdb-button mgdb-button-secondary" href="' +
        escape(sections.typsim.tool_url) + '" target="_blank" rel="noopener">TYPSimSelector &nearr;</a>');
    }
    if (grin && grin.details && grin.details.grin_url) {
      actions.push('<a class="mgdb-button mgdb-button-quiet" href="' +
        escape(grin.details.grin_url) + '" target="_blank" rel="noopener">View at GRIN &nearr;</a>');
    }

    els.actions.innerHTML = actions.join('');
  }

  /* ------------------------------------------------------------------------
     Sections
     ------------------------------------------------------------------------ */

  function renderOverview(overview) {
    if (!overview) { return false; }

    var facts = '';
    facts += fact('Stock type', overview.type ? escape(overview.type.name) : '', overview.type_definition);
    facts += fact('Species', overview.species ? escape(overview.species.name) : '');
    facts += fact('Classification', overview.classification ? escape(overview.classification) : '');
    facts += fact('Developed by', refLink(overview.developer));
    facts += fact('Available from', refLink(overview.provider));
    facts += fact('Market class', overview.market_class ? escape(overview.market_class.name) : '');
    facts += fact('Focus linkage group', refLink(overview.focus_linkage_group));
    facts += fact('Pedigree', overview.pedigree_text ? escape(overview.pedigree_text) : '');
    facts += fact('Stock Center ID', overview.stock_center_id ? escape(overview.stock_center_id) : '');

    var origin = overview.origin || {};
    var place = [origin.country, origin.state_province].filter(Boolean).join(', ');
    facts += fact('Origin', place ? escape(place) : '');
    facts += fact('Year', origin.year ? String(origin.year) : '');

    var html = facts ? '<dl class="stock-record-grid">' + facts + '</dl>' : '';

    if (overview.assemblies && overview.assemblies.length) {
      html += block('Genome assemblies',
        'Reference assemblies built from this stock.',
        chipList(overview.assemblies));
    }

    if (overview.comments && overview.comments.length) {
      html += block('Curator notes', '',
        '<dl class="stock-record-notes">' + overview.comments.map(function (comment) {
          return '<dt>' + escape(comment.label) + '</dt><dd>' + escape(comment.text) + '</dd>';
        }).join('') + '</dl>');
    }

    if (!html) { return false; }
    els.overviewBody.innerHTML = html;
    return true;
  }

  function renderPedigree(pedigree, stockData, counts) {
    if (!pedigree) { return false; }
    var parents = pedigree.parents || [];
    var progeny = pedigree.progeny || [];
    var network = pedigree.network || {};
    if (!parents.length && !progeny.length && !network.available) { return false; }

    var attributes = stockData && stockData.attributes ? stockData.attributes : {};
    var selectedName = attributes.name || ('Stock ' + (stockData ? stockData.id : ''));
    var selectedHref = '/data_center/stock?id=' + encodeURIComponent(stockData ? stockData.id : '');
    var parentCount = (counts && counts.parents !== null && counts.parents !== undefined)
      ? counts.parents : parents.length;
    var progenyCount = (counts && counts.progeny !== null && counts.progeny !== undefined)
      ? counts.progeny : progeny.length;
    var relationshipCount = parentCount + progenyCount;
    var visibleProgeny = progeny.slice(0, PEDIGREE_PROGENY_LIMIT);

    function contribution(item, missingLabel) {
      if (item.contribution_percent === null || item.contribution_percent === undefined) {
        return missingLabel || '';
      }
      return item.contribution_percent + '%';
    }

    function mapNode(item, direction) {
      var note = contribution(item, 'Contribution not reported');
      return '<a class="stock-pedigree-node stock-pedigree-node-' + direction + '" href="' +
        escape(item.html || '#') + '"><strong>' + escape(item.name) + '</strong>' +
        '<span>' + escape(note) + '</span></a>';
    }

    function emptyGroup(message) {
      return '<p class="stock-pedigree-empty">' + escape(message) + '</p>';
    }

    function relationshipRow(item, direction) {
      var label = direction === 'parent' ? 'Parent' : 'Progeny';
      var percent = contribution(item, 'Not reported');
      var search = (label + ' ' + item.name + ' ' + percent).toLowerCase();
      return '<tr data-pedigree-row data-search="' + escape(search) + '">' +
        '<td><span class="mgdb-pill ' + (direction === 'parent' ? 'mgdb-pill-info' : 'mgdb-pill-ok') +
        '">' + label + '</span></td>' +
        '<th scope="row"><a href="' + escape(item.html || '#') + '">' + escape(item.name) + '</a></th>' +
        '<td class="mgdb-numeric" data-value="' +
        (item.contribution_percent === null || item.contribution_percent === undefined
          ? '' : escape(item.contribution_percent)) + '">' + escape(percent) + '</td>' +
        '</tr>';
    }

    var parentNodes = parents.length
      ? parents.map(function (item) { return mapNode(item, 'parent'); }).join('')
      : emptyGroup('No parents are recorded for this stock.');
    var progenyNodes = visibleProgeny.length
      ? visibleProgeny.map(function (item) { return mapNode(item, 'progeny'); }).join('')
      : emptyGroup('No direct progeny are recorded for this stock.');
    var graphNote = progenyCount > PEDIGREE_PROGENY_LIMIT
      ? '<p class="stock-pedigree-map-note">Showing the first ' + PEDIGREE_PROGENY_LIMIT + ' of ' +
        progenyCount.toLocaleString() + ' direct progeny. The table includes every recorded relationship.</p>'
      : '';
    var rows = parents.map(function (item) { return relationshipRow(item, 'parent'); })
      .concat(progeny.map(function (item) { return relationshipRow(item, 'progeny'); })).join('');

    var html = '<div class="stock-pedigree-summary">' +
      '<dl class="stock-pedigree-metrics">' +
        '<div><dt>Parents</dt><dd>' + parentCount.toLocaleString() + '</dd><span>All shown in the map</span></div>' +
        '<div><dt>Direct progeny</dt><dd>' + progenyCount.toLocaleString() + '</dd><span>First ' +
          Math.min(progenyCount, PEDIGREE_PROGENY_LIMIT).toLocaleString() + ' shown in the map</span></div>' +
        '<div><dt>Known relationships</dt><dd>' + relationshipCount.toLocaleString() +
          '</dd><span>Complete table available</span></div>' +
      '</dl>' +
      '<div class="stock-pedigree-toolbar">' +
        '<div class="stock-pedigree-view-toggle" role="group" aria-label="Pedigree presentation">' +
          '<button type="button" class="is-active" data-pedigree-view="map" aria-pressed="true" ' +
            'aria-controls="stock-pedigree-map-panel">Graph</button>' +
          '<button type="button" data-pedigree-view="table" aria-pressed="false" ' +
            'aria-controls="stock-pedigree-table-panel">Table</button>' +
        '</div>' +
        '<div class="stock-pedigree-actions">' +
          (network.interactive ? '<a class="mgdb-button mgdb-button-primary" href="' +
            escape(network.interactive) + '">Explore full pedigree</a>' : '') +
          '<button class="mgdb-button mgdb-button-secondary" type="button" data-pedigree-download>' +
            'Download relationships</button>' +
        '</div>' +
      '</div>' +
      '<div id="stock-pedigree-map-panel" data-pedigree-panel="map">' +
        '<div class="stock-pedigree-map" aria-label="Direct parents and progeny of ' + escape(selectedName) + '">' +
          '<section class="stock-pedigree-generation stock-pedigree-parents" aria-labelledby="stock-pedigree-parents-title">' +
            '<h3 id="stock-pedigree-parents-title">Parents <span>' + parentCount.toLocaleString() + '</span></h3>' +
            '<div class="stock-pedigree-node-grid">' + parentNodes + '</div>' +
          '</section>' +
          '<div class="stock-pedigree-flow" aria-hidden="true"><span>contributes to</span><b>&darr;</b></div>' +
          '<div class="stock-pedigree-selected">' +
            '<span>Selected stock</span><a href="' + escape(selectedHref) + '">' + escape(selectedName) + '</a>' +
          '</div>' +
          '<div class="stock-pedigree-flow" aria-hidden="true"><span>recorded parent of</span><b>&darr;</b></div>' +
          '<section class="stock-pedigree-generation stock-pedigree-progeny" aria-labelledby="stock-pedigree-progeny-title">' +
            '<h3 id="stock-pedigree-progeny-title">Direct progeny <span>' + progenyCount.toLocaleString() + '</span></h3>' +
            '<div class="stock-pedigree-node-grid">' + progenyNodes + '</div>' + graphNote +
          '</section>' +
        '</div>' +
      '</div>' +
      '<div id="stock-pedigree-table-panel" data-pedigree-panel="table" hidden>' +
        '<div class="stock-pedigree-table-tools">' +
          '<label for="stock-pedigree-search">Search relationships</label>' +
          '<input id="stock-pedigree-search" type="search" placeholder="Filter by stock name or relationship" ' +
            'autocomplete="off">' +
          '<span id="stock-pedigree-result-count" role="status">' + relationshipCount.toLocaleString() +
            (relationshipCount === 1 ? ' relationship' : ' relationships') + '</span>' +
        '</div>' +
        '<div class="mgdb-table-scroll" tabindex="0" role="region" aria-label="Pedigree relationships table">' +
          '<table class="mgdb-table stock-pedigree-table" id="stock-pedigree-table">' +
            '<caption class="mgdb-visually-hidden">All recorded parent and progeny relationships for ' +
              escape(selectedName) + '</caption>' +
            '<thead><tr>' +
              '<th scope="col" data-sort="text"><button type="button">Relationship</button></th>' +
              '<th scope="col" data-sort="text"><button type="button">Stock</button></th>' +
              '<th scope="col" class="mgdb-numeric" data-sort="number"><button type="button">Contribution</button></th>' +
            '</tr></thead><tbody>' + rows + '</tbody>' +
          '</table>' +
        '</div>' +
        '<p class="stock-pedigree-table-note">Contribution is shown only where it is currently recorded in MaizeGDB.</p>' +
      '</div>' +
    '</div>';

    els.pedigreeBody.innerHTML = html;
    initPedigreeEvents(parents, progeny, selectedName);
    return true;
  }

  function initPedigreeEvents(parents, progeny, selectedName) {
    var buttons = els.pedigreeBody.querySelectorAll('[data-pedigree-view]');
    var panels = els.pedigreeBody.querySelectorAll('[data-pedigree-panel]');
    Array.prototype.forEach.call(buttons, function (button) {
      button.addEventListener('click', function () {
        var view = button.getAttribute('data-pedigree-view');
        Array.prototype.forEach.call(buttons, function (candidate) {
          var active = candidate === button;
          candidate.classList.toggle('is-active', active);
          candidate.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        Array.prototype.forEach.call(panels, function (panel) {
          panel.hidden = panel.getAttribute('data-pedigree-panel') !== view;
        });
        MGDB.announce('Showing pedigree as ' + view + '.');
      });
    });

    var table = byId('stock-pedigree-table');
    if (table) { MGDB.sortTable(table); }

    var search = byId('stock-pedigree-search');
    var resultCount = byId('stock-pedigree-result-count');
    if (search && table && table.tBodies.length) {
      search.addEventListener('input', MGDB.debounce(function () {
        var query = search.value.toLowerCase().replace(/\s+/g, ' ').trim();
        var shown = 0;
        Array.prototype.forEach.call(table.tBodies[0].rows, function (row) {
          var match = !query || (row.getAttribute('data-search') || '').indexOf(query) !== -1;
          row.hidden = !match;
          if (match) { shown += 1; }
        });
        if (resultCount) {
          resultCount.textContent = shown.toLocaleString() + (shown === 1 ? ' relationship' : ' relationships');
        }
      }, 120));
    }

    var download = els.pedigreeBody.querySelector('[data-pedigree-download]');
    if (download) {
      download.addEventListener('click', function () {
        function cell(value) { return '"' + String(value == null ? '' : value).replace(/"/g, '""') + '"'; }
        var csvRows = [['Relationship', 'Stock', 'Contribution percent', 'Stock URL']];
        parents.forEach(function (item) {
          csvRows.push(['Parent', item.name, item.contribution_percent, item.html || '']);
        });
        progeny.forEach(function (item) {
          csvRows.push(['Progeny', item.name, item.contribution_percent, item.html || '']);
        });
        var csv = csvRows.map(function (row) { return row.map(cell).join(','); }).join('\r\n') + '\r\n';
        var blob = new window.Blob([csv], { type: 'text/csv;charset=utf-8' });
        var url = window.URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = selectedName.replace(/[^A-Za-z0-9._-]+/g, '-') + '-pedigree.csv';
        link.hidden = true;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(function () { window.URL.revokeObjectURL(url); }, 0);
      });
    }
  }

  function renderRelated(related, counts, stockData) {
    if (!related) { return false; }
    var html = '';

    if (related.genotypic_variations && related.genotypic_variations.length) {
      html += block('Genotypic variations',
        'Alleles and variations this stock carries.',
        chipList(related.genotypic_variations));
    }
    if (related.karyotypic_variations && related.karyotypic_variations.length) {
      html += block('Karyotypic variations', '', chipList(related.karyotypic_variations));
    }
    if (related.phenotypes && related.phenotypes.length) {
      html += block('Phenotypes', 'Traits observed in this stock.',
        chipList(related.phenotypes, 'attributable_to'));
    }
    if (related.relations && related.relations.length) {
      html += block('Part of', 'Populations and panels this stock belongs to.',
        chipList(related.relations, 'relationship'));
    }

    var images = (related.images || []).concat(related.variation_images || []);
    if (images.length) {
      var stockName = (stockData && stockData.attributes && stockData.attributes.name) ? stockData.attributes.name : (stockData ? stockData.id : 'Stock');
      var imageCardsHtml = images.map(function (image, idx) {
        var isVar = (image.subject === 'variation' && image.variation && image.variation.name);
        var title = isVar ? image.variation.name : stockName;
        var recordUrl = isVar ? (image.variation.html || ('/data_center/variation?id=' + encodeURIComponent(image.variation.id))) : ('/data_center/stock/' + encodeURIComponent(stockName));
        var catName = isVar ? 'Variation / Mutant' : 'Stock & Germplasm';
        var caption = image.caption || '';
        var imgUrl = image.url;

        return '<article class="mgdb-image-card" data-index="' + idx + '">' +
          '  <div>' +
          '    <figure class="image-card-figure" data-img-src="' + escape(imgUrl) + '" data-img-title="' + escape(title) + '" data-img-cat="' + escape(catName) + '" data-img-caption="' + escape(caption) + '" data-img-record="' + escape(recordUrl) + '">' +
          '      <img src="' + escape(imgUrl) + '" alt="' + escape(caption || title) + '" loading="lazy" onerror="this.onerror=null;this.src=\'/images/logo.png\';this.style.objectFit=\'contain\';this.style.padding=\'16px\';" />' +
          '    </figure>' +
          '    <div class="image-card-body">' +
          '      <div class="image-card-meta">' +
          '        <span class="image-card-badge" data-cat="' + escape(catName) + '">' + escape(catName) + '</span>' +
          '      </div>' +
          '      <h3><a href="' + escape(recordUrl) + '">' + escape(title) + '</a></h3>' +
          (caption ? '      <p class="image-card-caption">' + escape(caption) + '</p>' : '') +
          '    </div>' +
          '  </div>' +
          '  <div class="image-card-links">' +
          '    <button class="image-card-btn image-preview-btn" type="button" data-img-src="' + escape(imgUrl) + '" data-img-title="' + escape(title) + '" data-img-cat="' + escape(catName) + '" data-img-caption="' + escape(caption) + '" data-img-record="' + escape(recordUrl) + '">Zoom</button>' +
          '    <a class="image-card-btn" href="' + escape(recordUrl) + '">Record &rarr;</a>' +
          '    <button class="image-card-btn image-copy-btn" type="button" data-copy-value="' + escape(imgUrl) + '">Copy URL</button>' +
          '  </div>' +
          '</article>';
      }).join('');

      html += block('Images',
        images.length + (images.length === 1 ? ' image' : ' images') +
        ' of this stock and of the variations it carries.',
        '<div class="stock-record-images">' + imageCardsHtml + '</div>');
    }

    var traits = related.trait_values || {};
    if (traits.count > 0 && traits.html) {
      html += block('Trait values',
        traits.count.toLocaleString() + ' measured trait values are recorded for this stock.',
        '<a class="mgdb-button mgdb-button-secondary" href="' + escape(traits.html) +
        '">Search trait values</a>');
    }

    if (!html) { return false; }
    els.relatedBody.innerHTML = html;
    initImageCardEvents();
    void counts;
    return true;
  }

  /* ------------------------------------------------------------------------
     Lightbox Events
     ------------------------------------------------------------------------ */

  function openLightbox(src, title, cat, caption, recordUrl) {
    var modal = byId('image-lightbox-modal');
    var img = byId('lightbox-img');
    var badge = byId('lightbox-badge');
    var titleEl = byId('lightbox-title');
    var captionEl = byId('lightbox-caption');
    var recordLink = byId('lightbox-record-link');
    var downloadLink = byId('lightbox-download-link');
    var copyBtn = byId('lightbox-copy-url-btn');

    if (!modal || !img) return;

    img.src = src;
    img.alt = caption || title || 'Image preview';
    if (badge) {
      badge.textContent = cat || 'Media';
      badge.setAttribute('data-cat', cat || 'Media');
    }
    if (titleEl) titleEl.textContent = title || 'Image Preview';
    if (captionEl) captionEl.textContent = caption || 'No caption available.';
    if (recordLink) {
      recordLink.href = recordUrl || '#';
      recordLink.hidden = !recordUrl;
    }
    if (downloadLink) downloadLink.href = src;
    if (copyBtn) {
      copyBtn.setAttribute('data-copy-value', src);
      copyBtn.textContent = 'Copy URL';
      copyBtn.classList.remove('mgdb-button-ok');
    }

    if (typeof modal.showModal === 'function') {
      modal.showModal();
    } else {
      modal.setAttribute('open', '');
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

  function initImageCardEvents() {
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

    Array.prototype.forEach.call(document.querySelectorAll('.image-copy-btn'), function (btn) {
      btn.addEventListener('click', function () {
        var val = btn.getAttribute('data-copy-value');
        if (!val) return;
        copyToClipboard(val, btn, 'URL copied!');
      });
    });

    var closeBtn = byId('lightbox-close-btn');
    if (closeBtn) {
      closeBtn.onclick = closeLightbox;
    }

    var modal = byId('image-lightbox-modal');
    if (modal) {
      modal.addEventListener('click', function (e) {
        if (e.target === modal) {
          closeLightbox();
        }
      });
    }

    var lbCopyBtn = byId('lightbox-copy-url-btn');
    if (lbCopyBtn) {
      lbCopyBtn.addEventListener('click', function () {
        var val = lbCopyBtn.getAttribute('data-copy-value');
        if (!val) return;
        copyToClipboard(val, lbCopyBtn, 'URL copied!');
      });
    }
  }

  function renderTypsim(typsim) {
    if (!typsim || !typsim.available) { return false; }

    var matches = typsim.top_matches || [];
    var tableRows = matches.map(function (m) {
      var percent = m.similarity_percent.toFixed(2);
      var selfClass = m.is_self ? ' class="is-self"' : '';
      var selfBadge = m.is_self ? ' <span class="mgdb-pill mgdb-pill-ok">Self</span>' : '';
      var lineLink = '<a href="' + escape(m.html) + '"><strong>' + escape(m.line) + '</strong></a>' +
                     (m.accession ? ' <small class="mgdb-muted">(' + escape(m.accession) + ')</small>' : '') +
                     selfBadge;

      return '<tr' + selfClass + '>' +
        '<td>#' + m.rank + '</td>' +
        '<td>' + lineLink + '</td>' +
        '<td class="stock-typsim-bar-cell">' +
          '<div class="stock-typsim-bar"><i style="width:' + percent + '%"></i></div>' +
        '</td>' +
        '<td><strong>' + percent + '%</strong></td>' +
        '<td><small class="mgdb-muted">' + (m.divergence * 100).toFixed(2) + '%</small></td>' +
        '<td><a href="' + escape(m.html) + '">Stock record &rarr;</a></td>' +
      '</tr>';
    }).join('');

    var tableHtml = '<div class="stock-typsim-table-wrap">' +
      '<table class="stock-typsim-table">' +
        '<thead><tr>' +
          '<th>Rank</th>' +
          '<th>Accession / Line</th>' +
          '<th>IBS Similarity</th>' +
          '<th>Score</th>' +
          '<th>Divergence</th>' +
          '<th>Action</th>' +
        '</tr></thead>' +
        '<tbody>' + tableRows + '</tbody>' +
      '</table>' +
    '</div>';

    var html = '<div class="stock-typsim-card">' +
      '<div class="stock-typsim-header">' +
        '<div>' +
          '<h3>Ames Diversity Panel Genetic Similarity</h3>' +
          '<p>Identity-by-state &#40;IBS&#41; genetic relationships scored across ' +
          typsim.total_compared.toLocaleString() + ' panel accessions. Showing top closest relatives.</p>' +
        '</div>' +
        '<a class="mgdb-button mgdb-button-primary" href="' + escape(typsim.tool_url) + '" target="_blank" rel="noopener">' +
          'Open in TYPSimSelector &nearr;' +
        '</a>' +
      '</div>' +
      tableHtml +
    '</div>';

    els.typsimBody.innerHTML = html;
    return true;
  }

  /* ------------------------------------------------------------------------
     References with Pagination
     ------------------------------------------------------------------------ */

  function renderReferences(references) {
    if (!references || !references.length) { return false; }
    allReferences = references;
    refCurrentPage = 1;
    renderReferencePage();
    return true;
  }

  function renderReferencePage() {
    var total = allReferences.length;
    var totalPages = Math.ceil(total / REF_PAGE_SIZE);
    if (refCurrentPage > totalPages) refCurrentPage = totalPages;
    if (refCurrentPage < 1) refCurrentPage = 1;

    var start = (refCurrentPage - 1) * REF_PAGE_SIZE;
    var end = Math.min(start + REF_PAGE_SIZE, total);
    var pageSlice = allReferences.slice(start, end);

    var html = pageSlice.map(function (ref) {
      var yearBadge = ref.year ? '<span class="reference-year">' + ref.year + '</span>' : '';
      var topicBadge = ref.relevance ? '<span class="mgdb-pill mgdb-pill-ok">' + escape(ref.relevance) + '</span>' : '';
      var typeBadge = ref.pub_type ? '<span class="mgdb-pill mgdb-pill-info">' + escape(ref.pub_type) + '</span>' : '<span class="mgdb-pill mgdb-pill-info">Journal article</span>';
      var idBadge = ref.id ? '<span class="reference-copy-id">ID: ' + ref.id + '</span>' : '';

      var title = ref.title || ref.citation || 'Untitled reference';
      var authors = ref.authors ? '<p class="reference-card-authors">' + escape(ref.authors) + '</p>' : '';
      var citation = ref.citation ? '<p class="reference-card-journal">' + escape(ref.citation) + '</p>' : '';

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
          '<h3 class="reference-card-title"><a href="' + escape(ref.html) + '">' + escape(title) + '</a></h3>' +
          authors +
          citation +
        '</div>' +
        '<div class="reference-card-actions">' +
          '<a class="mgdb-button mgdb-button-secondary" href="' + escape(ref.html) + '">Reference record &rarr;</a>' +
          readBtn +
          '<button class="mgdb-button mgdb-button-quiet" type="button" data-copy-citation="' + escape(fullCitation) + '">Copy citation</button>' +
          (ref.doi ? '<button class="mgdb-button mgdb-button-quiet" type="button" data-copy-doi="' + escape(ref.doi) + '">Copy DOI</button>' : '') +
        '</div>' +
      '</article>';
    }).join('');

    els.referencesBody.innerHTML = html;
    bindReferenceCopyHandlers();

    if (els.referencesStatus) {
      els.referencesStatus.textContent = 'Showing ' + (start + 1) + '–' + end + ' of ' + total.toLocaleString() + ' curated publications, newest first.';
    }

    renderReferencePagination(totalPages);
  }

  function renderReferencePagination(totalPages) {
    if (!els.referencesPagination) return;
    if (totalPages <= 1) {
      show(els.referencesPagination, false);
      return;
    }

    var html = '';
    html += '<button class="stock-page-btn" type="button" data-ref-page="' + (refCurrentPage - 1) + '" ' +
            (refCurrentPage === 1 ? 'disabled' : '') + ' aria-label="Previous page">&larr; Prev</button>';

    var pages = [];
    for (var p = 1; p <= totalPages; p++) {
      if (p === 1 || p === totalPages || (p >= refCurrentPage - 1 && p <= refCurrentPage + 1)) {
        pages.push(p);
      } else if (pages[pages.length - 1] !== '...') {
        pages.push('...');
      }
    }

    pages.forEach(function (page) {
      if (page === '...') {
        html += '<span class="stock-page-ellipsis" aria-hidden="true">&hellip;</span>';
      } else {
        var active = (page === refCurrentPage);
        html += '<button class="stock-page-btn' + (active ? ' is-active' : '') + '" type="button" data-ref-page="' + page + '"' +
                (active ? ' aria-current="page"' : '') + '>' + page + '</button>';
      }
    });

    html += '<button class="stock-page-btn" type="button" data-ref-page="' + (refCurrentPage + 1) + '" ' +
            (refCurrentPage === totalPages ? 'disabled' : '') + ' aria-label="Next page">Next &rarr;</button>';

    els.referencesPagination.innerHTML = html;
    show(els.referencesPagination, true);

    Array.prototype.forEach.call(els.referencesPagination.querySelectorAll('[data-ref-page]'), function (btn) {
      btn.addEventListener('click', function () {
        var targetPage = parseInt(btn.getAttribute('data-ref-page'), 10);
        if (!isNaN(targetPage) && targetPage >= 1 && targetPage <= totalPages && targetPage !== refCurrentPage) {
          refCurrentPage = targetPage;
          renderReferencePage();
          var refSection = byId('stock-record-references');
          if (refSection) {
            refSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        }
      });
    });
  }

  function bindReferenceCopyHandlers() {
    Array.prototype.forEach.call(els.referencesBody.querySelectorAll('[data-copy-citation]'), function (btn) {
      btn.addEventListener('click', function () {
        var text = btn.getAttribute('data-copy-citation');
        copyToClipboard(text, btn, 'Citation copied!');
      });
    });

    Array.prototype.forEach.call(els.referencesBody.querySelectorAll('[data-copy-doi]'), function (btn) {
      btn.addEventListener('click', function () {
        var doi = btn.getAttribute('data-copy-doi');
        copyToClipboard('https://doi.org/' + doi, btn, 'DOI copied!');
      });
    });
  }

  function copyToClipboard(text, btn, feedback) {
    if (!navigator.clipboard) {
      fallbackCopy(text, btn, feedback);
      return;
    }
    navigator.clipboard.writeText(text).then(function () {
      showCopyFeedback(btn, feedback);
    }).catch(function () {
      fallbackCopy(text, btn, feedback);
    });
  }

  function fallbackCopy(text, btn, feedback) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try {
      document.execCommand('copy');
      showCopyFeedback(btn, feedback);
    } catch (e) {}
    document.body.removeChild(ta);
  }

  function showCopyFeedback(btn, feedback) {
    var original = btn.textContent;
    btn.textContent = feedback;
    btn.classList.add('mgdb-button-ok');
    setTimeout(function () {
      btn.textContent = original;
      btn.classList.remove('mgdb-button-ok');
    }, 2000);
  }

  function renderOffsite(offsite) {
    if (!offsite || !offsite.length) { return false; }
    els.offsiteBody.innerHTML = '<dl class="stock-record-grid">' +
      offsite.map(function (entry) {
        return '<div><dt>' + escape(entry.database) + '</dt><dd><a href="' +
               escape(entry.url) + '" target="_blank" rel="noopener">' + escape(entry.accession) + ' &nearr;</a></dd></div>';
      }).join('') + '</dl>';
    return true;
  }

  function renderGrin(grin) {
    if (!grin || !grin.accession) { return false; }

    var details = grin.details;
    if (!details) {
      els.grinBody.innerHTML = '<dl class="stock-record-grid">' +
        '<div><dt>Accession</dt><dd>' + escape(grin.accession) + '</dd></div></dl>' +
        '<p class="stock-record-empty">The GRIN service did not return details for this ' +
        'accession just now. The accession number above is from MaizeGDB and is unaffected.</p>';
      return true;
    }

    var facts = '';
    facts += fact('Accession', escape(details.accession_number || grin.accession));
    facts += fact('Improvement status', details.improvement ? escape(details.improvement) : '');
    facts += fact('Reproductive uniformity', details.reproductive_uniformity
                  ? escape(details.reproductive_uniformity) : '');
    facts += fact('Acquired', details.acquired ? escape(details.acquired) : '');
    facts += fact('Seed source', details.seed_source ? escape(details.seed_source) : '');
    facts += fact('Collection', details.collection ? escape(details.collection) : '');
    facts += fact('Availability', details.is_available === true ? 'Available'
                  : (details.is_available === false ? 'Not currently available' : ''));

    var html = '<dl class="stock-record-grid">' + facts + '</dl>';

    if (details.pedigree) {
      html += block('GRIN pedigree', '',
        '<div class="stock-record-notes"><dd>' + escape(details.pedigree) + '</dd></div>');
    }
    if (details.note) {
      html += block('GRIN description', '',
        '<div class="stock-record-notes"><dd>' + escape(details.note) + '</dd></div>');
    }

    els.grinBody.innerHTML = html;
    return true;
  }

  /* ------------------------------------------------------------------------
     Section tabs, built from what the record actually has
     ------------------------------------------------------------------------ */

  var TAB_COUNTS = {
    'stock-record-pedigree': ['parents', 'progeny'],
    'stock-record-related': ['genotypic_variations', 'karyotypic_variations',
                             'phenotypes', 'relations', 'images', 'variation_images'],
    'stock-record-references': ['references'],
    'stock-record-offsite': ['offsite']
  };

  function buildTabs(rendered, counts) {
    var labels = {
      'stock-record-overview': 'Overview',
      'stock-record-pedigree': 'Pedigree & relationships',
      'stock-record-related': 'Related records',
      'stock-record-typsim': 'Genetic similarity',
      'stock-record-references': 'References',
      'stock-record-offsite': 'Offsite',
      'stock-record-grin': 'GRIN'
    };

    var html = '';
    rendered.forEach(function (id) {
      var total = 0;
      (TAB_COUNTS[id] || []).forEach(function (key) { total += (counts[key] || 0); });
      html += '<a href="#' + id + '">' + labels[id] +
              (total > 0 ? '<span class="stock-record-tab-count">' + total.toLocaleString() + '</span>' : '') +
              '</a>';
    });

    els.tabs.innerHTML = html;
    show(els.tabs, rendered.length > 1);

    var pairs = [];
    Array.prototype.forEach.call(els.tabs.querySelectorAll('a'), function (tab) {
      var section = document.querySelector(tab.getAttribute('href'));
      if (section) { pairs.push({ tab: tab, section: section }); }
    });

    function markCurrent(target) {
      pairs.forEach(function (pair) {
        var current = pair.section === target;
        pair.tab.classList.toggle('is-current', current);
        if (current) { pair.tab.setAttribute('aria-current', 'true'); }
        else { pair.tab.removeAttribute('aria-current'); }
      });
    }

    if (pairs.length) { markCurrent(pairs[0].section); }
    pairs.forEach(function (pair) {
      pair.tab.addEventListener('click', function () { markCurrent(pair.section); });
    });

    if (!window.IntersectionObserver) { return; }
    var observer = new window.IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) { markCurrent(entry.target); }
      });
    }, { rootMargin: '-25% 0px -65% 0px' });
    pairs.forEach(function (pair) { observer.observe(pair.section); });
  }

  /* ------------------------------------------------------------------------
     Assembly
     ------------------------------------------------------------------------ */

  function render(response) {
    payload = response;
    var data = response.data || {};
    var sections = data.sections || {};
    var meta = response.meta || {};
    var counts = meta.counts || {};

    show(els.loading, false);
    show(els.error, false);

    renderHeader(data, sections);

    var rendered = [];
    if (renderOverview(sections.overview)) { rendered.push('stock-record-overview'); }
    if (renderPedigree(sections.pedigree, data, counts)) { rendered.push('stock-record-pedigree'); }
    if (renderRelated(sections.related, counts, data)) { rendered.push('stock-record-related'); }
    if (renderTypsim(sections.typsim)) { rendered.push('stock-record-typsim'); }
    if (renderReferences(sections.references)) { rendered.push('stock-record-references'); }
    if (renderOffsite(sections.offsite)) { rendered.push('stock-record-offsite'); }
    if (renderGrin(sections.grin)) { rendered.push('stock-record-grin'); }

    rendered.forEach(function (id) { show(byId(id), true); });
    buildTabs(rendered, counts);

    var notices = [];
    (meta.truncated || []).forEach(function (list) {
      var key = list.split('.').pop();
      notices.push('Only the first ' + meta.max_items.toLocaleString() + ' of ' +
        (counts[key] || 0).toLocaleString() + ' ' + key.replace(/_/g, ' ') + ' are shown.');
    });
    (meta.warnings || []).forEach(function (warning) { notices.push(warning.detail); });

    if (notices.length) {
      els.notice.innerHTML = '<div><strong>Note</strong><span>' +
        notices.map(escape).join(' ') + '</span></div>';
      show(els.notice, true);
    }

    if (els.apiLink) { els.apiLink.href = '/api/v1/records/stock/' + encodeURIComponent(data.id); }

    MGDB.announce('Record loaded, ' + rendered.length + ' sections.');
  }

  function load() {
    var main = byId('stock-record-top');
    if (!main) { return; }
    var id = main.getAttribute('data-stock-id');
    if (!id) { return; }

    show(els.error, false);
    show(els.loading, true);

    MGDB.request('/api/v1/records/stock/' + encodeURIComponent(id), { key: 'stock-record' })
      .then(function (response) {
        if (!response || !response.data) { throw new Error('unexpected payload'); }
        render(response);
      })
      .catch(function (error) {
        if (error && error.name === 'AbortError') { return; }
        show(els.loading, false);
        show(els.error, true);
      });
  }

  function init() {
    els = {
      synonyms: byId('stock-record-synonyms'),
      facts: byId('stock-record-facts'),
      actions: byId('stock-record-actions'),
      tabs: byId('stock-record-tabs'),
      loading: byId('stock-record-loading'),
      error: byId('stock-record-error'),
      retry: byId('stock-record-retry'),
      notice: byId('stock-record-notice'),
      overviewBody: byId('stock-record-overview-body'),
      pedigreeBody: byId('stock-record-pedigree-body'),
      relatedBody: byId('stock-record-related-body'),
      typsimBody: byId('stock-record-typsim-body'),
      referencesBody: byId('stock-record-references-body'),
      referencesStatus: byId('stock-record-references-status'),
      referencesPagination: byId('stock-record-references-pagination'),
      offsiteBody: byId('stock-record-offsite-body'),
      grinBody: byId('stock-record-grin-body'),
      apiLink: byId('stock-record-api-link')
    };

    if (els.retry) { els.retry.addEventListener('click', load); }
    load();
    void payload;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
