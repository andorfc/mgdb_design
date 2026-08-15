/* ==========================================================================
   Stock record page — page behavior
   --------------------------------------------------------------------------
   Companion to /css/mgdb-stock-record.css and
   templates/static/mgdb_stock_record.bau.

   One request to /api/v1/records/stock/{id} builds the whole page. The record
   page this replaces made six, sharded across ajax1..N.maizegdb.org
   subdomains to get around the browser's per-host connection limit — a
   workaround that one request makes unnecessary.

   The identity is already on the page, server-rendered, so a failure here
   degrades to a record that still says what it is and links to its data.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  if (!MGDB) { return; }

  var CHIP_LIMIT = 40;   // chips shown before the rest collapse behind a toggle

  function byId(id) { return document.getElementById(id); }
  function escape(value) { return MGDB.escapeHtml(value); }
  function show(el, visible) { if (el) { el.hidden = !visible; } }

  var els = {};
  var payload = null;

  /* ------------------------------------------------------------------------
     Small builders
     ------------------------------------------------------------------------ */

  /* A reference from the API is {type, id, name, html}. Anything without a
     page is still named, just not linked. */
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

  /* A list of record links. Past CHIP_LIMIT the remainder collapses: B73
     carries over 1300 genotypic variations, and a wall of them buries
     everything below it. The hidden ones stay in the DOM so find-in-page and
     assistive technology can still reach them. */
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

    // Actions are the things a reader came to do: get the seed, see the
    // pedigree, follow the accession outward.
    var actions = [];
    var grin = sections.grin;

    if (grin && grin.details && grin.details.order_url) {
      actions.push('<a class="mgdb-button mgdb-button-primary" href="' +
        escape(grin.details.order_url) + '">Request from the PI Station</a>');
    } else if (overview.provider && overview.provider.is_stock_center) {
      actions.push('<a class="mgdb-button mgdb-button-primary" href="https://maizecoopsc.org/">' +
        'Order from the Stock Center</a>');
    }

    var pedigree = sections.pedigree;
    if (pedigree && pedigree.network && pedigree.network.available && pedigree.network.interactive) {
      actions.push('<a class="mgdb-button mgdb-button-secondary" href="' +
        escape(pedigree.network.interactive) + '">Pedigree viewer</a>');
    }
    if (grin && grin.details && grin.details.grin_url) {
      actions.push('<a class="mgdb-button mgdb-button-quiet" href="' +
        escape(grin.details.grin_url) + '">View at GRIN</a>');
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

  function renderPedigree(pedigree) {
    if (!pedigree) { return false; }

    function withContribution(items) {
      return items.map(function (item) {
        var copy = { name: item.name, html: item.html };
        if (item.contribution_percent !== null && item.contribution_percent !== undefined) {
          copy.note = item.contribution_percent + '%';
        }
        return copy;
      });
    }

    var html = '';
    if (pedigree.parents && pedigree.parents.length) {
      html += block('Parents', '', chipList(withContribution(pedigree.parents), 'note'));
    }
    if (pedigree.progeny && pedigree.progeny.length) {
      html += block('Progeny', 'Stocks recorded as having this one as a parent.',
        chipList(withContribution(pedigree.progeny), 'note'));
    }

    var network = pedigree.network || {};
    if (network.available && network.interactive) {
      var figure = network.thumbnail
        ? '<a href="' + escape(network.interactive) + '"><img src="' + escape(network.thumbnail) +
          '" alt="Pedigree network for this stock" loading="lazy"></a>'
        : '<a class="mgdb-button mgdb-button-secondary" href="' + escape(network.interactive) +
          '">Open the pedigree viewer</a>';
      html += block('Pedigree network',
        'Ancestors and descendants as a navigable graph.', figure);
    }

    if (!html) { return false; }
    els.pedigreeBody.innerHTML = html;
    return true;
  }

  function renderRelated(related, counts) {
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
      html += block('Images',
        images.length + (images.length === 1 ? ' image' : ' images') +
        ' of this stock and of the variations it carries.',
        '<ul class="stock-record-images">' + images.map(function (image) {
          var caption = '';
          if (image.variation) { caption += '<strong>' + escape(image.variation.name) + '</strong>'; }
          if (image.caption) { caption += escape(image.caption); }
          return '<li><figure><img src="' + escape(image.url) + '" alt="' +
                 escape(image.caption || 'Stock image') + '" loading="lazy">' +
                 (caption ? '<figcaption>' + caption + '</figcaption>' : '') +
                 '</figure></li>';
        }).join('') + '</ul>');
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
    void counts;
    return true;
  }

  function renderReferences(references) {
    if (!references || !references.length) { return false; }
    els.referencesBody.innerHTML = '<ul class="stock-record-references">' +
      references.map(function (reference) {
        var title = reference.title || reference.citation || 'Untitled reference';
        return '<li><h3><a href="' + escape(reference.html) + '">' + escape(title) + '</a></h3>' +
          (reference.citation ? '<p>' + escape(reference.citation) +
            (reference.relevance ? ' &middot; ' + escape(reference.relevance) : '') + '</p>' : '') +
          '</li>';
      }).join('') + '</ul>';
    return true;
  }

  function renderOffsite(offsite) {
    if (!offsite || !offsite.length) { return false; }
    els.offsiteBody.innerHTML = '<dl class="stock-record-grid">' +
      offsite.map(function (entry) {
        return '<div><dt>' + escape(entry.database) + '</dt><dd><a href="' +
               escape(entry.url) + '">' + escape(entry.accession) + '</a></dd></div>';
      }).join('') + '</dl>';
    return true;
  }

  function renderGrin(grin) {
    if (!grin || !grin.accession) { return false; }

    var details = grin.details;
    if (!details) {
      // The accession is ours and is always known; the detail behind it is
      // not. Say which is which rather than showing an empty section.
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
      'stock-record-pedigree': 'Pedigree',
      'stock-record-related': 'Related records',
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
    if (renderPedigree(sections.pedigree)) { rendered.push('stock-record-pedigree'); }
    if (renderRelated(sections.related, counts)) { rendered.push('stock-record-related'); }
    if (renderReferences(sections.references)) { rendered.push('stock-record-references'); }
    if (renderOffsite(sections.offsite)) { rendered.push('stock-record-offsite'); }
    if (renderGrin(sections.grin)) { rendered.push('stock-record-grin'); }

    rendered.forEach(function (id) { show(byId(id), true); });
    buildTabs(rendered, counts);

    // Anything the API had to hold back is said out loud rather than left to
    // look like the record simply has less in it than it does.
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
      referencesBody: byId('stock-record-references-body'),
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
