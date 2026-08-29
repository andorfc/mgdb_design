/* ==========================================================================
   MaizeGDB Modern — all-data search results
   --------------------------------------------------------------------------
   Renders /search_engine/searchall from search/searchall/searchall_api.php.

   Two views over the same data:

     overview   the leading data types, five rows each, so someone who typed a
                gene name sees the gene before anything else
     type       one data type, twenty-five rows a page, with a pager

   Each data type gets the layout its records actually need — a reference is a
   publication card with authors and an abstract, a gene lists the models
   annotated for it, a stock shows its pedigree — because a single generic
   table of name-and-id forces the reader to open records to tell them apart.

   Rows are built with createElement and textContent throughout. Record labels
   are curator-entered free text and are never interpolated into markup.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var API = '/search/searchall/searchall_api.php';
  var SVG_NS = 'http://www.w3.org/2000/svg';

  /* Sprite ids for the data-type chips. The header search carries the sprite
     itself; this map only names the targets. Keys match the `cat` the API
     returns.

     Careful: `cat` here and the header's data-cat are NOT the same vocabulary,
     despite sharing two spellings. In this file the API's own registry applies,
     where cat 'gene_model' is the section labelled *Genes* and 'gene_product'
     is *Gene products*. In the header, data-cat 'gene_product' is the option
     labelled *Genes* and 'gene_model' is *Gene models*; searchall_modern.php
     reconciles them by mapping both header gene categories onto the one `gene`
     type. So match the icon to the visible label, not to the key — that is why
     'gene_model' takes the genes icon here while the header's 'gene_model'
     takes the gene-model shape. */
  var ICONS = {
    gene_model: '#mzg-genes',
    locus: '#mzg-loci',
    reference: '#mzg-references',
    stock: '#mzg-stocks-and-germplasm',
    probe: '#mzg-markers-and-probes',
    variation: '#mzg-variations-and-alleles',
    phenotype: '#mzg-phenotypes',
    term: '#mzg-traits-and-terms',
    qtl_exp: '#mzg-qtl-experiments',
    gene_product: '#mzg-gene-products',
    map: '#mzg-maps',
    person: '#mzg-people-and-organizations',
    genome: '#mzg-genomes',
    recomb: '#mzg-recombination-data',
    primer: '#mzg-restriction-enzymes-and-primers',
    species: '#mzg-species',
    journal: '#mzg-journals',
    id: '#mgdb-cat-id'
  };

  var root, resultsEl, typesEl, summaryEl, statusEl, refineForm, queryInput, commentsInput;
  var state = { term: '', type: '', page: 1, comments: false, types: [], total: 0 };
  var pending = null;

  /* ---------------------------------------------------------------------
     Small DOM helpers
     --------------------------------------------------------------------- */

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    if (text !== undefined && text !== null && text !== '') { node.textContent = text; }
    return node;
  }

  function clear(node) {
    while (node.firstChild) { node.removeChild(node.firstChild); }
  }

  function icon(cat) {
    var href = ICONS[cat];
    if (!href) { return null; }
    var chip = el('span', 'mgdb-search-icon sa-icon');
    chip.setAttribute('data-cat', cat);
    chip.setAttribute('aria-hidden', 'true');
    var svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('focusable', 'false');
    var use = document.createElementNS(SVG_NS, 'use');
    use.setAttribute('href', href);
    svg.appendChild(use);
    chip.appendChild(svg);
    return chip;
  }

  function count(n) {
    return Number(n || 0).toLocaleString();
  }

  function plural(n, one, many) {
    return count(n) + ' ' + (Number(n) === 1 ? one : many);
  }

  /* A definition-style meta line: "Chromosome 9 · 3 gene models". Empty values
     are dropped rather than rendered as stray separators. */
  function meta(parent, parts) {
    var kept = parts.filter(function (part) { return part && String(part).trim() !== ''; });
    if (!kept.length) { return; }
    var line = el('p', 'sa-meta');
    kept.forEach(function (part, index) {
      if (index) { line.appendChild(el('span', 'sa-sep', '·')); }
      line.appendChild(el('span', null, String(part)));
    });
    parent.appendChild(line);
  }

  /* ---------------------------------------------------------------------
     Row renderers, one per view
     --------------------------------------------------------------------- */

  function rowShell(row) {
    var item = el('li', 'sa-row');
    var body = el('div', 'sa-row-body');
    item.appendChild(body);
    return { item: item, body: body };
  }

  function titleLink(row, text, className) {
    var link = el('a', className || 'sa-row-title', text);
    link.href = row.url;
    return link;
  }

  var VIEWS = {
    gene: function (row, body) {
      body.appendChild(titleLink(row, row.name));
      if (row.full_name) { body.appendChild(el('p', 'sa-row-sub', row.full_name)); }
      meta(body, [
        row.plant_wide ? 'Plant-wide name ' + row.plant_wide : row.plant_wide_gene_name
          ? 'Plant-wide name ' + row.plant_wide_gene_name : '',
        row.chromosome ? 'Chromosome ' + row.chromosome : '',
        row.model_count ? plural(row.model_count, 'gene model', 'gene models') : '',
        row.assembly || ''
      ]);
      if (row.models && row.models.length) {
        var models = el('p', 'sa-models');
        models.appendChild(el('span', 'sa-models-label', 'Models:'));
        row.models.forEach(function (name) {
          var link = el('a', 'sa-model-link', name);
          link.href = '/gene_center/gene/' + encodeURIComponent(name);
          models.appendChild(link);
        });
        if (row.model_count > row.models.length) {
          models.appendChild(el('span', 'sa-models-more',
            '+' + count(row.model_count - row.models.length) + ' more'));
        }
        body.appendChild(models);
      }
    },

    locus: function (row, body) {
      body.appendChild(titleLink(row, row.name));
      if (row.full_name) { body.appendChild(el('p', 'sa-row-sub', row.full_name)); }
      meta(body, [
        row.plant_wide ? 'Plant-wide name ' + row.plant_wide : '',
        row.chromosome ? 'Chromosome ' + row.chromosome + (row.arm ? row.arm : '') : ''
      ]);
    },

    /* Publication card: what someone deciding whether to open a paper needs —
       title, who wrote it, where, and enough abstract to judge relevance. */
    publication: function (row, body) {
      body.appendChild(titleLink(row, row.title || 'Untitled reference', 'sa-row-title sa-pub-title'));
      if (row.authors) { body.appendChild(el('p', 'sa-pub-authors', row.authors)); }
      meta(body, [row.journal, row.year, row.citation]);
      if (row.abstract) { body.appendChild(el('p', 'sa-pub-abstract', row.abstract)); }

      var links = el('p', 'sa-links');
      var any = false;
      if (row.doi) {
        var doi = el('a', 'sa-link', 'DOI');
        doi.href = 'https://doi.org/' + encodeURIComponent(row.doi);
        doi.rel = 'noopener';
        links.appendChild(doi);
        any = true;
      }
      if (row.pubmed) {
        var pm = el('a', 'sa-link', 'PubMed');
        pm.href = 'https://pubmed.ncbi.nlm.nih.gov/' + encodeURIComponent(row.pubmed) + '/';
        pm.rel = 'noopener';
        links.appendChild(pm);
        any = true;
      }
      if (any) { body.appendChild(links); }
    },

    stock: function (row, body) {
      body.appendChild(titleLink(row, row.name));
      if (row.pedigree) { body.appendChild(el('p', 'sa-row-sub', row.pedigree)); }
      meta(body, [
        row.coop_id ? 'Coop ID ' + row.coop_id : '',
        row.stock_type,
        row.available_from ? 'From ' + row.available_from : '',
        row.country
      ]);
    },

    probe: function (row, body) {
      body.appendChild(titleLink(row, row.name));
      meta(body, [
        row.probe_type,
        row.mnemonic,
        row.bins ? 'Bin ' + row.bins : '',
        row.repeat ? 'Repeat ' + row.repeat : ''
      ]);
    },

    variation: function (row, body) {
      body.appendChild(titleLink(row, row.name));
      if (row.function) { body.appendChild(el('p', 'sa-row-sub', row.function)); }
      var line = el('p', 'sa-meta');
      var wrote = false;
      if (row.locus_name && row.locus_url) {
        line.appendChild(el('span', null, 'Allele of '));
        var link = el('a', 'sa-inline-link', row.locus_name);
        link.href = row.locus_url;
        line.appendChild(link);
        wrote = true;
      }
      [row.descriptor, row.inbred].forEach(function (part) {
        if (!part) { return; }
        if (wrote) { line.appendChild(el('span', 'sa-sep', '·')); }
        line.appendChild(el('span', null, part));
        wrote = true;
      });
      if (wrote) { body.appendChild(line); }
    },

    phenotype: function (row, body) {
      body.appendChild(titleLink(row, row.name));
      if (row.comments) { body.appendChild(el('p', 'sa-row-sub', row.comments)); }
      meta(body, [row.inheritance]);
    },

    term: function (row, body) {
      body.appendChild(titleLink(row, row.name));
      if (row.comments) { body.appendChild(el('p', 'sa-row-sub', row.comments)); }
      meta(body, [row.term_type]);
    },

    qtl: function (row, body) {
      body.appendChild(titleLink(row, row.name));
      if (row.markers) { body.appendChild(el('p', 'sa-row-sub', row.markers)); }
      meta(body, [row.panel]);
    },

    map: function (row, body) {
      body.appendChild(titleLink(row, row.name));
      meta(body, [row.chromosome ? 'Chromosome ' + row.chromosome : '', row.source]);
    },

    person: function (row, body) {
      body.appendChild(titleLink(row, row.name));
      meta(body, [row.institution, row.place]);
    },

    genome: function (row, body) {
      body.appendChild(titleLink(row, row.name));
      if (row.project) { body.appendChild(el('p', 'sa-row-sub', row.project)); }
      meta(body, [row.annotation ? 'Annotation ' + row.annotation : '']);
    },

    simple: function (row, body) {
      body.appendChild(titleLink(row, row.name || ('Record ' + row.id)));
    }
  };

  function renderRows(list, rows, view) {
    var render = VIEWS[view] || VIEWS.simple;
    rows.forEach(function (row) {
      var shell = rowShell(row);
      render(row, shell.body);
      list.appendChild(shell.item);
    });
  }

  /* ---------------------------------------------------------------------
     Sections and the type rail
     --------------------------------------------------------------------- */

  function sectionHeader(section, showAll) {
    var head = el('div', 'sa-section-head');
    var heading = el('h2', 'sa-section-title');
    var chip = icon(section.cat);
    if (chip) { heading.appendChild(chip); }
    heading.appendChild(el('span', null, section.label));
    heading.appendChild(el('span', 'sa-section-count', count(section.count)));
    head.appendChild(heading);

    if (showAll && section.count > section.rows.length) {
      var link = el('a', 'sa-see-all');
      link.href = urlFor(state.term, section.key, 1, state.comments);
      link.textContent = 'See all ' + count(section.count) + ' ' +
        section.label.toLowerCase() + ' →';
      link.addEventListener('click', function (event) {
        event.preventDefault();
        go(section.key, 1);
      });
      head.appendChild(link);
    }
    return head;
  }

  function renderRail(types, activeKey) {
    clear(typesEl);
    var list = el('ul', 'sa-rail-items');

    var all = el('li');
    var allLink = el('a', 'sa-rail-link' + (activeKey ? '' : ' is-active'));
    allLink.href = urlFor(state.term, '', 1, state.comments);
    allLink.appendChild(el('span', 'sa-rail-label', 'All results'));
    allLink.appendChild(el('span', 'sa-rail-count', count(state.total)));
    if (!activeKey) { allLink.setAttribute('aria-current', 'page'); }
    allLink.addEventListener('click', function (event) { event.preventDefault(); go('', 1); });
    all.appendChild(allLink);
    list.appendChild(all);

    types.forEach(function (type) {
      var item = el('li');
      var link = el('a', 'sa-rail-link' + (type.key === activeKey ? ' is-active' : ''));
      link.href = urlFor(state.term, type.key, 1, state.comments);
      var chip = icon(type.cat);
      if (chip) { link.appendChild(chip); }
      link.appendChild(el('span', 'sa-rail-label', type.label));
      link.appendChild(el('span', 'sa-rail-count', count(type.count)));
      if (type.key === activeKey) { link.setAttribute('aria-current', 'page'); }
      link.addEventListener('click', function (event) { event.preventDefault(); go(type.key, 1); });
      item.appendChild(link);
      list.appendChild(item);
    });

    typesEl.appendChild(list);
  }

  function renderEmpty(message, hint) {
    clear(resultsEl);
    var box = el('div', 'mgdb-empty');
    box.appendChild(el('h3', null, message));
    if (hint) { box.appendChild(el('p', null, hint)); }
    resultsEl.appendChild(box);
  }

  function renderOverview(data) {
    clear(resultsEl);
    if (!data.sections.length) {
      renderEmpty('No records match “' + state.term + '”.',
        'Try a shorter term, a gene symbol such as wx1, or turn on “Also search comments and notes”.');
      return;
    }

    data.sections.forEach(function (section) {
      var wrap = el('section', 'sa-section');
      wrap.appendChild(sectionHeader(section, true));
      var list = el('ul', 'sa-list');
      renderRows(list, section.rows, section.view);
      wrap.appendChild(list);
      resultsEl.appendChild(wrap);
    });

    /* Types that did not earn a section still need a way in, and naming them
       is what tells the reader the search reached further than the page shows. */
    var shown = {};
    data.sections.forEach(function (section) { shown[section.key] = true; });
    var rest = data.types.filter(function (type) { return !shown[type.key]; });
    if (rest.length) {
      var more = el('section', 'sa-more');
      more.appendChild(el('h2', 'sa-more-title', 'Also found in'));
      var row = el('div', 'sa-more-list');
      rest.forEach(function (type) {
        var link = el('a', 'mgdb-chip sa-more-chip');
        link.href = urlFor(state.term, type.key, 1, state.comments);
        link.appendChild(el('span', null, type.label));
        link.appendChild(el('span', 'sa-more-count', count(type.count)));
        link.addEventListener('click', function (event) { event.preventDefault(); go(type.key, 1); });
        row.appendChild(link);
      });
      more.appendChild(row);
      resultsEl.appendChild(more);
    }
  }

  function renderPager(data) {
    if (data.page_count <= 1) { return null; }
    var nav = el('nav', 'mgdb-pagination sa-pager');
    nav.setAttribute('aria-label', data.type.label + ' pages');

    function pageButton(page, label, disabled) {
      var button = el('button', 'mgdb-button mgdb-button-secondary', label);
      button.type = 'button';
      if (disabled) { button.disabled = true; }
      else { button.addEventListener('click', function () { go(data.type.key, page); }); }
      return button;
    }

    nav.appendChild(pageButton(data.page - 1, 'Previous', data.page <= 1));
    nav.appendChild(el('span', 'sa-pager-status',
      'Page ' + count(data.page) + ' of ' + count(data.page_count)));
    nav.appendChild(pageButton(data.page + 1, 'Next', data.page >= data.page_count));
    return nav;
  }

  function renderType(data) {
    clear(resultsEl);
    if (!data.rows.length) {
      renderEmpty('No ' + data.type.label.toLowerCase() + ' match “' + state.term + '”.',
        'Pick another data type from the list, or search for something else.');
      return;
    }

    var wrap = el('section', 'sa-section sa-section-full');
    wrap.appendChild(sectionHeader({
      key: data.type.key, label: data.type.label, cat: data.type.cat,
      count: data.total, rows: data.rows
    }, false));
    if (data.type.blurb) { wrap.appendChild(el('p', 'sa-section-blurb', data.type.blurb)); }

    var first = (data.page - 1) * data.page_size + 1;
    var last = Math.min(data.page * data.page_size, data.total);
    wrap.appendChild(el('p', 'sa-showing',
      'Showing ' + count(first) + '–' + count(last) + ' of ' + count(data.total)));

    var list = el('ul', 'sa-list');
    renderRows(list, data.rows, data.type.view);
    wrap.appendChild(list);

    var pager = renderPager(data);
    if (pager) { wrap.appendChild(pager); }

    if (data.capped) {
      wrap.appendChild(el('p', 'mgdb-note',
        'This result set is larger than the pages available. Add a word to the search to narrow it.'));
    }
    resultsEl.appendChild(wrap);
  }

  /* ---------------------------------------------------------------------
     Requests and navigation
     --------------------------------------------------------------------- */

  function urlFor(term, type, page, comments) {
    var url = '/search_engine/searchall?global_search_term=' + encodeURIComponent(term);
    if (type) { url += '&type=' + encodeURIComponent(type); }
    if (page && page > 1) { url += '&page=' + page; }
    if (comments) { url += '&comments=1'; }
    return url;
  }

  function apiUrl(params) {
    var query = ['q=' + encodeURIComponent(state.term)];
    Object.keys(params).forEach(function (key) {
      if (params[key] !== '' && params[key] !== undefined) {
        query.push(key + '=' + encodeURIComponent(params[key]));
      }
    });
    if (state.comments) { query.push('comments=1'); }
    return API + '?' + query.join('&');
  }

  function setStatus(message) {
    if (statusEl) { statusEl.textContent = message; }
  }

  function showSkeleton() {
    clear(resultsEl);
    var box = el('div', 'sa-skeleton');
    box.setAttribute('aria-hidden', 'true');
    for (var i = 0; i < 4; i++) { box.appendChild(el('span')); }
    resultsEl.appendChild(box);
    resultsEl.setAttribute('aria-busy', 'true');
  }

  function fetchJson(url) {
    if (pending && pending.abort) { pending.abort(); }
    var controller = window.AbortController ? new window.AbortController() : null;
    pending = controller;
    var options = { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' };
    if (controller) { options.signal = controller.signal; }
    return window.fetch(url, options).then(function (response) {
      if (!response.ok && response.status !== 503) { throw new Error('Search request failed'); }
      return response.json();
    });
  }

  function load() {
    if (!state.term) {
      renderEmpty('Enter a search term.', 'Search for a gene symbol, a stock, an author, or a phrase.');
      summaryEl.textContent = '';
      return;
    }
    showSkeleton();
    setStatus('Searching for ' + state.term + '.');

    var url = state.type
      ? apiUrl({ action: 'type', type: state.type, page: state.page })
      : apiUrl({ action: 'summary' });

    fetchJson(url).then(function (data) {
      resultsEl.setAttribute('aria-busy', 'false');
      if (!data.ok) {
        renderEmpty('The search could not be completed.',
          data.message || 'Try a more specific term.');
        summaryEl.textContent = '';
        return;
      }

      if (state.type) {
        renderType(data);
        summaryEl.textContent = plural(data.total, 'record', 'records') + ' in ' +
          data.type.label.toLowerCase() + ' · ' + data.elapsed_ms + ' ms';
        /* The rail comes from the overview; on a direct link to a type view
           there is nothing to show in it until that arrives. */
        if (!state.types.length) { loadRail(); }
        else { renderRail(state.types, state.type); }
        setStatus(plural(data.total, 'record', 'records') + ' found.');
      } else {
        state.types = data.types;
        state.total = data.total;
        renderRail(data.types, '');
        renderOverview(data);
        summaryEl.textContent = data.total
          ? plural(data.total, 'record', 'records') + ' across ' +
            plural(data.types.length, 'data type', 'data types') + ' · ' + data.elapsed_ms + ' ms'
          : 'No records found';
        setStatus(plural(data.total, 'record', 'records') + ' found.');
      }
    }).catch(function (error) {
      if (error && error.name === 'AbortError') { return; }
      resultsEl.setAttribute('aria-busy', 'false');
      renderEmpty('The search could not be completed.', 'Reload the page to try again.');
    });
  }

  /* A type view opened from a link has no counts yet; fetch them so the rail
     fills in without blocking the rows the reader came for. */
  function loadRail() {
    window.fetch(apiUrl({ action: 'summary' }), {
      headers: { 'Accept': 'application/json' }, credentials: 'same-origin'
    }).then(function (response) { return response.json(); })
      .then(function (data) {
        if (!data.ok) { return; }
        state.types = data.types;
        state.total = data.total;
        renderRail(data.types, state.type);
      }).catch(function () { /* the rail is navigation, not content */ });
  }

  function go(type, page) {
    state.type = type;
    state.page = page || 1;
    window.history.pushState({ type: state.type, page: state.page },
      '', urlFor(state.term, state.type, state.page, state.comments));
    load();
    var top = document.getElementById('sa-top');
    if (top) { top.scrollIntoView({ behavior: 'auto', block: 'start' }); }
  }

  /* ---------------------------------------------------------------------
     Wiring
     --------------------------------------------------------------------- */

  function init() {
    root = document.querySelector('.sa-page');
    if (!root || !window.fetch) { return; }

    resultsEl = root.querySelector('[data-sa-results]');
    typesEl = root.querySelector('[data-sa-types]');
    summaryEl = root.querySelector('[data-sa-summary]');
    statusEl = root.querySelector('[data-sa-status]');
    refineForm = document.getElementById('sa-refine');
    queryInput = document.getElementById('sa-query');
    commentsInput = document.getElementById('sa-comments');
    if (!resultsEl || !typesEl) { return; }

    state.term = root.getAttribute('data-search-term') || '';
    state.type = root.getAttribute('data-initial-type') || '';
    state.comments = root.getAttribute('data-initial-comments') === '1';

    var params = new window.URLSearchParams(window.location.search);
    var page = parseInt(params.get('page'), 10);
    state.page = page > 0 ? page : 1;

    /* Set only when the MaizeGDB ID category was asked for a term that is not
       a live id. The controller resolves a real id to its record page and
       never gets here; this says why the reader is looking at a search
       instead. Server-escaped, and read back as text. */
    var idNotice = root.getAttribute('data-id-notice') || '';
    if (idNotice) {
      var note = el('div', 'mgdb-note sa-notice');
      note.appendChild(el('p', null, idNotice));
      root.insertBefore(note, root.querySelector('.sa-layout'));
    }

    if (commentsInput) {
      commentsInput.checked = state.comments;
      commentsInput.addEventListener('change', function () {
        state.comments = commentsInput.checked;
        state.types = [];
        go(state.type, 1);
      });
    }

    if (refineForm) {
      refineForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var next = (queryInput.value || '').trim();
        if (!next) { return; }
        state.term = next;
        state.types = [];
        var term = root.querySelector('[data-sa-term]');
        if (term) { term.textContent = next; }
        document.title = 'Search: ' + next + ' | MaizeGDB';
        go('', 1);
      });
    }

    window.addEventListener('popstate', function (event) {
      var restored = event.state || {};
      state.type = restored.type || '';
      state.page = restored.page || 1;
      load();
    });

    load();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
