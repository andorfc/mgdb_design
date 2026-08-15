/* ==========================================================================
   Reference record page — page behavior
   --------------------------------------------------------------------------
   Companion to /css/mgdb-reference-record.css and
   templates/static/mgdb_reference_record.bau.

   One request to /api/v1/records/reference/{id} builds the whole page. The
   page it replaces made five, each returning a fragment of HTML.

   The title, journal, year, and the Editorial Board badge are already on the
   page, server-rendered, so a failure here degrades to a citation a reader can
   still use.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  if (!MGDB) { return; }

  var CHIP_LIMIT = 60;

  function byId(id) { return document.getElementById(id); }
  function escape(value) { return MGDB.escapeHtml(value); }
  function show(el, visible) { if (el) { el.hidden = !visible; } }

  var els = {};

  /* ------------------------------------------------------------------------
     Header
     ------------------------------------------------------------------------ */

  function renderByline(authors) {
    if (!authors || !authors.length) { return; }

    // Long author lists are elided the way a journal would: first three, then
    // "and N others", then the last author, who is usually the senior one.
    var parts;
    if (authors.length <= 6) {
      parts = authors.map(authorLink);
    } else {
      parts = authors.slice(0, 3).map(authorLink);
      parts.push('and ' + (authors.length - 4) + ' others');
      parts.push(authorLink(authors[authors.length - 1]));
    }
    els.byline.innerHTML = parts.join(', ');
  }

  function authorLink(author) {
    var label = escape(author.full_name || author.name);
    return author.html
      ? '<a href="' + escape(author.html) + '">' + label + '</a>'
      : label;
  }

  function renderHeader(data, sections) {
    var attributes = data.attributes || {};
    var overview = sections.overview || {};

    renderByline(sections.authors);

    var facts = '';
    if (attributes.journal) { facts += '<div><dt>Journal</dt><dd>' + escape(attributes.journal) + '</dd></div>'; }
    if (attributes.year) { facts += '<div><dt>Year</dt><dd>' + attributes.year + '</dd></div>'; }
    if (overview.volume && overview.volume !== '0') {
      var locator = overview.volume + (overview.issue && overview.issue !== '0' ? '(' + overview.issue + ')' : '');
      facts += '<div><dt>Volume</dt><dd>' + escape(locator) + '</dd></div>';
    }
    facts += '<div><dt>MaizeGDB ID</dt><dd class="mgdb-record-id">' + escape(data.id) + '</dd></div>';
    els.facts.innerHTML = facts;

    // The actions a reader wants first: the paper itself, then the record it
    // is indexed under, then the means to cite it.
    var actions = [];
    var citation = sections.citation || {};

    if (citation.doi_url) {
      actions.push('<a class="mgdb-button mgdb-button-primary" href="' + escape(citation.doi_url) +
        '">Read the paper</a>');
    }
    if (citation.pubmed_url) {
      actions.push('<a class="mgdb-button mgdb-button-secondary" href="' + escape(citation.pubmed_url) +
        '">PubMed</a>');
    }
    if (citation.formatted || citation.bibtex) {
      actions.push('<a class="mgdb-button mgdb-button-quiet" href="#reference-record-citation">Cite</a>');
    }
    els.actions.innerHTML = actions.join('');
  }

  /* ------------------------------------------------------------------------
     Sections
     ------------------------------------------------------------------------ */

  function renderEditorial(editorial) {
    if (!editorial || !editorial.is_editorial_pick) { return false; }

    var who = (editorial.recommendations || []).map(function (item) {
      var name = item.recommended_by_full_name ||
                 (item.recommended_by ? item.recommended_by.name : null);
      var linked = (item.recommended_by && item.recommended_by.html && name)
        ? '<a href="' + escape(item.recommended_by.html) + '">' + escape(name) + '</a>'
        : escape(name || 'a Board member');
      return linked + (item.year ? ' in ' + item.year : '');
    });

    els.editorialBody.innerHTML =
      '<div class="reference-record-editorial">' +
        '<p><strong>Nominated by the MaizeGDB Editorial Board</strong>' +
        (who.length ? ' &mdash; put forward by ' + who.join(', ') + '.' : '.') +
        ' Board members are working maize geneticists who each year select papers ' +
        'they consider of particular interest to the community.</p>' +
        '<a class="mgdb-button mgdb-button-secondary" href="' +
        escape(editorial.about_html || '/hot_new_papers') + '">See all Editorial Board picks</a>' +
      '</div>';
    return true;
  }

  function renderAbstract(abstract) {
    if (!abstract) { return false; }
    els.abstractBody.innerHTML = '<div class="reference-record-abstract">' +
      escape(abstract) + '</div>';
    return true;
  }

  function initials(author) {
    var source = author.full_name || author.name || '';
    var letters = source.replace(/[^A-Za-z\s,]/g, ' ').split(/[\s,]+/)
      .filter(Boolean).map(function (word) { return word.charAt(0).toUpperCase(); });
    if (!letters.length) { return '?'; }
    return letters.length === 1 ? letters[0] : letters[0] + letters[letters.length - 1];
  }

  function renderAuthors(authors) {
    if (!authors || !authors.length) { return false; }

    els.authorsBody.innerHTML = '<ul class="reference-record-authors">' +
      authors.map(function (author) {
        var role = '';
        if (author.is_first) { role = 'First author'; }
        else if (author.is_last) { role = 'Last author'; }

        // The count is what MaizeGDB holds for that author. Saying how many
        // *other* papers there are is the useful form: it answers "is this
        // someone who publishes in maize regularly?" without the reader
        // having to subtract the one they are looking at.
        var papers = '';
        if (author.paper_count) {
          var others = author.other_papers;
          papers = '<span class="reference-record-papers">' +
            (others > 0
              ? '<strong>' + others.toLocaleString() + '</strong> other paper' +
                (others === 1 ? '' : 's') + ' in MaizeGDB'
              : 'Only this paper in MaizeGDB') +
            (others > 0 && author.papers_html
              ? ' &middot; <a href="' + escape(author.papers_html) + '">see them</a>'
              : '') +
            '</span>';
        }

        return '<li class="reference-record-author">' +
          '<span class="reference-record-initials" aria-hidden="true">' + escape(initials(author)) + '</span>' +
          '<div class="reference-record-author-body">' +
            '<h3>' + (author.html
              ? '<a href="' + escape(author.html) + '">' + escape(author.full_name || author.name) + '</a>'
              : escape(author.full_name || author.name)) + '</h3>' +
            (author.full_name && author.name && author.full_name !== author.name
              ? '<span class="reference-record-author-name">' + escape(author.name) + '</span>' : '') +
            (role ? '<span class="reference-record-role">' + role + '</span>' : '') +
            papers +
          '</div></li>';
      }).join('') + '</ul>';
    return true;
  }

  /* A described locus carries its gene models, so a reader can go from "this
     paper describes lg2" to the annotation they work in without a search. */
  function locusCard(item) {
    var html = '<li class="reference-record-locus">' +
      '<h4>' + (item.html
        ? '<a href="' + escape(item.html) + '">' + escape(item.name) + '</a>'
        : escape(item.name)) + '</h4>' +
      (item.full_name ? '<span class="reference-record-locus-full">' + escape(item.full_name) + '</span>' : '') +
      (item.relevance ? '<span class="reference-record-relevance">' + escape(item.relevance) + '</span>' : '');

    var models = item.gene_models || [];
    if (models.length) {
      html += '<div class="reference-record-models"><span>Gene models</span><ul>' +
        models.map(function (model) {
          return '<li><a href="' + escape(model.html) + '">' +
            '<span class="reference-record-model-id">' + escape(model.name) + '</span>' +
            (model.assembly ? '<span class="reference-record-model-assembly">' +
              escape(model.assembly) + '</span>' : '') +
            (model.is_reference ? '<span class="reference-record-model-current">Current</span>' : '') +
            '</a></li>';
        }).join('') + '</ul></div>';
    }

    return html + '</li>';
  }

  function chip(item) {
    var label = escape(item.name) +
      (item.relevance ? ' <span class="mgdb-muted">&middot; ' + escape(item.relevance) + '</span>' : '');
    return '<li>' + (item.html
      ? '<a href="' + escape(item.html) + '">' + label + '</a>'
      : '<span class="reference-record-chip">' + label + '</span>') + '</li>';
  }

  function renderDescribes(groups) {
    if (!groups || !groups.length) { return false; }

    els.describesBody.innerHTML = groups.map(function (group) {
      var heading = '<h3>' + escape(group.record_type) +
        '<span>' + group.count.toLocaleString() +
        (group.count === 1 ? ' record' : ' records') +
        (group.truncated ? ', first ' + group.items.length + ' shown' : '') + '</span></h3>';

      var body;
      if (group.record_type === 'Locus') {
        body = '<ul class="reference-record-loci">' + group.items.map(locusCard).join('') + '</ul>';
      } else {
        var visible = group.items.slice(0, CHIP_LIMIT);
        body = '<ul class="reference-record-chips">' + visible.map(chip).join('') + '</ul>';
        if (group.items.length > CHIP_LIMIT) {
          body += '<details class="stock-record-more"><summary>Show the remaining ' +
            (group.items.length - CHIP_LIMIT).toLocaleString() + '</summary>' +
            '<ul class="reference-record-chips">' +
            group.items.slice(CHIP_LIMIT).map(chip).join('') + '</ul></details>';
        }
      }

      return '<div class="reference-record-group">' + heading + body + '</div>';
    }).join('');
    return true;
  }

  function citationBlock(key, heading, note, text, monospace) {
    if (!text) { return ''; }
    var body = monospace
      ? '<pre id="reference-citation-' + key + '">' + escape(text) + '</pre>'
      : '<p class="reference-record-text" id="reference-citation-' + key + '">' + escape(text) + '</p>';

    return '<div class="reference-record-citation">' +
      '<div class="reference-record-citation-head">' +
        '<h3>' + escape(heading) + (note ? '<small>' + escape(note) + '</small>' : '') + '</h3>' +
        '<button class="reference-record-copy" type="button" data-copy-target="reference-citation-' + key + '">' +
        'Copy</button>' +
      '</div>' + body + '</div>';
  }

  function renderCitation(citation) {
    if (!citation) { return false; }

    var html =
      citationBlock('formatted', 'Citation',
        'Authors, year, title, and where it appeared.', citation.formatted, false) +
      citationBlock('maizegdb', 'MaizeGDB citation string',
        'The form used across MaizeGDB search results.', citation.maizegdb, false) +
      citationBlock('bibtex', 'BibTeX',
        'For LaTeX, Zotero, JabRef, and most reference managers.', citation.bibtex, true) +
      citationBlock('ris', 'RIS',
        'For EndNote, Mendeley, and Papers.', citation.ris, true);

    if (!html) { return false; }
    els.citationBody.innerHTML = '<div class="reference-record-citations">' + html + '</div>';

    // Copy without leaving the page. The clipboard API needs a secure context;
    // where it is unavailable the text is selected instead, which still turns
    // the task into one keystroke.
    Array.prototype.forEach.call(
      els.citationBody.querySelectorAll('[data-copy-target]'), function (button) {
        button.addEventListener('click', function () {
          var source = byId(button.getAttribute('data-copy-target'));
          if (!source) { return; }
          var text = source.textContent;

          function done() {
            button.textContent = 'Copied';
            button.setAttribute('data-copied', 'true');
            MGDB.announce('Citation copied to the clipboard.');
            window.setTimeout(function () {
              button.textContent = 'Copy';
              button.removeAttribute('data-copied');
            }, 2000);
          }

          if (window.navigator && window.navigator.clipboard && window.isSecureContext) {
            window.navigator.clipboard.writeText(text).then(done).catch(selectInstead);
          } else {
            selectInstead();
          }

          function selectInstead() {
            var range = document.createRange();
            range.selectNodeContents(source);
            var selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            button.textContent = 'Selected — press Ctrl+C';
            window.setTimeout(function () { button.textContent = 'Copy'; }, 3000);
          }
        });
      });

    return true;
  }

  var LINK_MARKS = {
    doi: 'DOI',
    pubmed: 'PMID',
    mnl: 'MNL',
    ancillary: 'FILE',
    gene_review: 'MGR'
  };

  function renderLinks(links) {
    if (!links || !links.length) { return false; }

    els.linksBody.innerHTML = '<ul class="reference-record-links">' +
      links.filter(function (link) { return link.url; }).map(function (link) {
        var mark = LINK_MARKS[link.kind] || 'LINK';
        return '<li><a class="reference-record-link" href="' + escape(link.url) + '"' +
          (link.is_external ? ' rel="noopener"' : '') + '>' +
          '<span class="reference-record-mark reference-record-mark-' + escape(link.kind) +
            '" aria-hidden="true">' + escape(mark) + '</span>' +
          '<span><strong>' + escape(link.database) +
            (link.is_external ? '<span class="mgdb-external" aria-hidden="true"></span>' : '') +
            '</strong>' +
          '<span class="reference-record-link-accession">' + escape(link.accession) + '</span>' +
          '<span class="reference-record-link-where">' + escape(link.destination) + '</span>' +
          '</span></a></li>';
      }).join('') + '</ul>';
    return true;
  }

  /* ------------------------------------------------------------------------
     Tabs
     ------------------------------------------------------------------------ */

  var TAB_COUNTS = {
    'reference-record-authors': ['authors'],
    'reference-record-describes': ['describes'],
    'reference-record-links': ['links']
  };

  function buildTabs(rendered, counts) {
    var labels = {
      'reference-record-editorial': 'Editorial pick',
      'reference-record-abstract': 'Abstract',
      'reference-record-authors': 'Authors',
      'reference-record-describes': 'Describes',
      'reference-record-citation': 'Cite',
      'reference-record-links': 'Links'
    };

    els.tabs.innerHTML = rendered.map(function (id) {
      var total = 0;
      (TAB_COUNTS[id] || []).forEach(function (key) { total += (counts[key] || 0); });
      return '<a href="#' + id + '">' + labels[id] +
        (total > 0 ? '<span class="reference-record-tab-count">' + total.toLocaleString() + '</span>' : '') +
        '</a>';
    }).join('');
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
    var data = response.data || {};
    var sections = data.sections || {};
    var meta = response.meta || {};
    var counts = meta.counts || {};

    show(els.loading, false);
    show(els.error, false);

    renderHeader(data, sections);

    // Order matters here: a scientist reads the abstract, judges the authors,
    // then wants what MaizeGDB adds on top — the curated links. Citation and
    // identifiers are tasks, and tasks come after reading.
    var rendered = [];
    if (renderEditorial(sections.editorial)) { rendered.push('reference-record-editorial'); }
    if (renderAbstract(sections.abstract)) { rendered.push('reference-record-abstract'); }
    if (renderAuthors(sections.authors)) { rendered.push('reference-record-authors'); }
    if (renderDescribes(sections.describes)) { rendered.push('reference-record-describes'); }
    if (renderCitation(sections.citation)) { rendered.push('reference-record-citation'); }
    if (renderLinks(sections.links)) { rendered.push('reference-record-links'); }

    rendered.forEach(function (id) { show(byId(id), true); });
    buildTabs(rendered, counts);

    var notices = [];
    (meta.truncated || []).forEach(function (list) {
      notices.push('Only the first ' + meta.max_items.toLocaleString() + ' of ' + list + ' are shown.');
    });
    (meta.warnings || []).forEach(function (warning) { notices.push(warning.detail); });

    if (notices.length) {
      els.notice.innerHTML = '<div><strong>Note</strong><span>' + notices.map(escape).join(' ') + '</span></div>';
      show(els.notice, true);
    }

    if (els.apiLink) {
      els.apiLink.href = '/api/v1/records/reference/' + encodeURIComponent(data.id);
    }

    MGDB.announce('Record loaded, ' + rendered.length + ' sections.');
  }

  function load() {
    var main = byId('reference-record-top');
    if (!main) { return; }
    var id = main.getAttribute('data-reference-id');
    if (!id) { return; }

    show(els.error, false);
    show(els.loading, true);

    MGDB.request('/api/v1/records/reference/' + encodeURIComponent(id), { key: 'reference-record' })
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
      byline: byId('reference-record-byline'),
      facts: byId('reference-record-facts'),
      actions: byId('reference-record-actions'),
      tabs: byId('reference-record-tabs'),
      loading: byId('reference-record-loading'),
      error: byId('reference-record-error'),
      retry: byId('reference-record-retry'),
      notice: byId('reference-record-notice'),
      editorialBody: byId('reference-record-editorial-body'),
      abstractBody: byId('reference-record-abstract-body'),
      authorsBody: byId('reference-record-authors-body'),
      describesBody: byId('reference-record-describes-body'),
      citationBody: byId('reference-record-citation-body'),
      linksBody: byId('reference-record-links-body'),
      apiLink: byId('reference-record-api-link')
    };

    if (els.retry) { els.retry.addEventListener('click', load); }
    load();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
