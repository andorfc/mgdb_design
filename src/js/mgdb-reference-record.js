/* ==========================================================================
   Reference record page — /data_center/reference?id={id}
   --------------------------------------------------------------------------
   Glue over js/mgdb-record.js, the same engine the gene product, variation,
   map, marker, phenotype and pan-gene record pages use. This file maps one
   call to /api/v1/records/reference/{id} onto it.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  var R = window.MGDBRecord;
  if (!MGDB || !R) { return; }

  var els = {};
  var payload = null;

  /* ------------------------------------------------------------------------
     Header
     ------------------------------------------------------------------------ */

  /* Authors as one line, the way a citation reads them. Beyond six the middle
     is elided: the byline is for recognising the paper, and the Authors
     section below lists every one of them. */
  function renderByline(authors) {
    if (!authors || !authors.length) { return; }
    var names = authors.map(function (author) {
      var label = R.escape(author.full_name || author.name);
      return author.html ? R.link(author.html, author.full_name || author.name) : label;
    });
    var shown = names.length > 6
      ? names.slice(0, 3).concat(['<span class="mgdb-muted">&hellip; ' +
          (names.length - 4) + ' more &hellip;</span>'], names.slice(-1))
      : names;
    els.byline.innerHTML = shown.join(', ') + '.';
    R.show(els.byline, true);
  }

  /* ------------------------------------------------------------------------
     Overview
     ------------------------------------------------------------------------ */

  function renderOverview(overview, citation) {
    if (!overview) { return false; }
    var out = els.overviewBody;
    out.innerHTML = '';

    /* Volume 0, issue 0 and a "pages" field holding the DOI are all what this
       table records when a paper was indexed before the publisher assigned
       them. Showing 0 as a volume would be a claim; leaving it out is not. */
    function real(value) {
      var text = value === null || value === undefined ? '' : String(value).trim();
      return (text === '' || text === '0') ? '' : text;
    }
    var locator = real(overview.volume);
    if (locator && real(overview.issue)) { locator += '(' + real(overview.issue) + ')'; }

    var pages = real(overview.pages);
    if (pages && overview.doi && pages.indexOf(overview.doi) !== -1) {
      pages = '';   // the pages field is holding the DOI, which has its own row
    }

    var factsHtml = R.facts([
      ['Publication type', overview.publication_type ? R.escape(overview.publication_type.name) : ''],
      ['Journal', overview.journal
        ? (overview.journal.html ? R.refLink(overview.journal) : R.escape(overview.journal.name)) : ''],
      ['Year', overview.year == null ? '' : String(overview.year)],
      ['Volume', locator ? R.escape(locator) : ''],
      ['Pages', pages ? R.escape(pages) : ''],
      ['DOI', overview.doi ? R.link('https://doi.org/' + overview.doi, overview.doi, true) : ''],
      ['PubMed ID', overview.pubmed_id
        ? R.link('https://pubmed.ncbi.nlm.nih.gov/' + overview.pubmed_id + '/', overview.pubmed_id, true) : ''],
      ['ISSN', overview.issn ? R.escape(overview.issn) : ''],
      ['Publisher', overview.publisher ? R.escape(overview.publisher) : ''],
      ['Institution', overview.institution ? R.escape(overview.institution) : ''],
      ['Maize Newsletter issue', overview.maize_newsletter_issue ? R.escape(overview.maize_newsletter_issue) : '']
    ]);
    if (factsHtml) { out.insertAdjacentHTML('beforeend', factsHtml); }

    /* The two things a reader wants first: the paper itself, then the record
       it is indexed under at PubMed. */
    var actions = [];
    if (citation && citation.doi_url) {
      actions.push('<a class="mgdb-button mgdb-button-primary" href="' + R.escape(citation.doi_url) +
        '" target="_blank" rel="noopener">Read the paper <span aria-hidden="true">&nearr;</span></a>');
    }
    if (citation && citation.pubmed_url) {
      actions.push('<a class="mgdb-button mgdb-button-secondary" href="' + R.escape(citation.pubmed_url) +
        '" target="_blank" rel="noopener">PubMed <span aria-hidden="true">&nearr;</span></a>');
    }
    actions.push('<a class="mgdb-button mgdb-button-quiet" href="#ref-record-citation">Cite this paper</a>');
    out.insertAdjacentHTML('beforeend', '<div class="mgdb-rec-linkrow">' + actions.join('') + '</div>');

    return true;
  }

  /* ------------------------------------------------------------------------
     Editorial Board
     ------------------------------------------------------------------------ */

  var BOARD_TEXT = 'The MaizeGDB Editorial Board is a panel of working maize geneticists who ' +
    'each year nominate papers of particular interest to the community.';

  function renderEditorial(editorial) {
    if (!editorial || !editorial.is_editorial_pick) { return false; }
    var out = els.editorialBody;
    out.innerHTML = '<div class="mgdb-message mgdb-message-ok mgdb-rec-alert" role="note"><div>' +
      '<strong>Nominated by the MaizeGDB Editorial Board</strong>' +
      '<span>' + R.escape(BOARD_TEXT) + '</span></div></div>';

    /* The comment first. It is the only prose on the record that MaizeGDB
       wrote rather than the publisher, and it says why a working geneticist
       thought the paper was worth reading -- which is the whole point of the
       nomination.

       The writer is named only where the memo names them. The nominating
       member below is very often the person who wrote it, but nothing in the
       schema says so, so the page does not say so either. */
    var comments = editorial.comments || [];
    R.notes(out, comments.length === 1 ? 'Editorial Board Member Comment'
                                       : 'Editorial Board Member Comments',
      comments.map(function (comment) {
        return {
          text: comment.text,
          meta: [
            comment.is_codie ? 'CODIE Member Comment' : '',
            comment.written_by
              ? (comment.written_by.html
                  ? R.link(comment.written_by.html, comment.written_by_full_name || comment.written_by.name)
                  : R.escape(comment.written_by_full_name || comment.written_by.name))
              : ''
          ]
        };
      }));
    if (!comments.length) {
      out.insertAdjacentHTML('beforeend',
        '<p class="mgdb-rec-empty">There are currently no comments for this article.</p>');
    }

    R.collection(out, {
      title: 'Nominations',
      items: editorial.recommendations,
      filename: 'reference-editorial-nominations.tsv',
      columns: [
        { key: 'recommended_by', label: 'Recommended by', tile: true,
          get: function (r) { return r.recommended_by_full_name ||
                 (r.recommended_by ? r.recommended_by.name : ''); },
          html: function (r) {
            var name = r.recommended_by_full_name || (r.recommended_by ? r.recommended_by.name : 'A Board member');
            return (r.recommended_by && r.recommended_by.html)
              ? R.link(r.recommended_by.html, name) : R.escape(name);
          } },
        // A nomination can carry a second board member; 43 of them do.
        { key: 'recommended_by_2', label: 'Also recommended by',
          get: function (r) { return r.recommended_by_2_full_name ||
                 (r.recommended_by_2 ? r.recommended_by_2.name : ''); },
          html: function (r) {
            if (!r.recommended_by_2) { return '<span class="mgdb-muted">&mdash;</span>'; }
            var name = r.recommended_by_2_full_name || r.recommended_by_2.name;
            return r.recommended_by_2.html ? R.link(r.recommended_by_2.html, name) : R.escape(name);
          } },
        { key: 'month', label: 'Month' },
        { key: 'year', label: 'Year', sort: 'number', numeric: true,
          get: function (r) { return r.year == null ? '' : String(r.year); } }
      ]
    });

    out.insertAdjacentHTML('beforeend',
      '<div class="mgdb-rec-linkrow"><a class="mgdb-button mgdb-button-secondary" href="' +
      R.escape(editorial.about_html || '/hot_new_papers') +
      '">See all Editorial Board picks</a></div>');
    return true;
  }

  /* ------------------------------------------------------------------------
     Abstract
     ------------------------------------------------------------------------ */

  function renderAbstract(abstract) {
    if (!abstract) { return false; }
    return R.notes(els.abstractBody, 'Abstract', [{ text: abstract }]);
  }

  /* ------------------------------------------------------------------------
     What this paper describes
     ------------------------------------------------------------------------ */

  function geneModelsHtml(item) {
    var models = item.gene_models || [];
    if (!models.length) { return '<span class="mgdb-muted">&mdash;</span>'; }
    return models.map(function (model) {
      return (model.html ? R.link(model.html, model.name) : R.escape(model.name)) +
             (model.is_reference ? ' <span class="mgdb-pill mgdb-pill-ok">Current</span>' : '');
    }).join(', ');
  }

  function renderDescribes(groups) {
    if (!groups || !groups.length) { return false; }
    var out = els.describesBody;
    out.innerHTML = '';
    var rendered = false;

    groups.forEach(function (group) {
      /* A locus carries its gene models, so a reader can go from "this paper
         describes an1" to the annotation they work in without a search. Every
         other record type is a name and what the paper said about it. */
      var columns = group.record_type === 'Locus'
        ? [
            { key: 'name', label: 'Locus', tile: true,
              html: function (i) { return i.html ? R.link(i.html, i.name) : R.escape(i.name); } },
            { key: 'full_name', label: 'Full name' },
            { key: 'relevance', label: 'Relevance' },
            { key: 'gene_models', label: 'Gene models', sort: false,
              get: function (i) {
                return (i.gene_models || []).map(function (m) { return m.name; }).join(', ');
              },
              html: geneModelsHtml }
          ]
        : [
            { key: 'name', label: group.record_type, tile: true,
              html: function (i) { return i.html ? R.link(i.html, i.name) : R.escape(i.name); } },
            { key: 'full_name', label: 'Full name' },
            { key: 'relevance', label: 'Relevance' }
          ];

      var shown = R.collection(out, {
        title: group.record_type + ' records',
        items: group.items,
        filename: 'reference-describes-' + String(group.record_type).toLowerCase() + '.tsv',
        pageSize: 25,
        columns: columns
      });
      rendered = shown || rendered;

      /* A paper can be curated against tens of thousands of records -- one
         here carries 36,006 probes -- so the API caps what it embeds. The
         block's own count is what arrived; this says what it is a slice of. */
      if (shown && group.truncated) {
        var blocks = out.querySelectorAll('.mgdb-rec-block');
        var block = blocks[blocks.length - 1];
        if (block) {
          block.insertAdjacentHTML('beforeend',
            '<p class="mgdb-rec-block-status">The first ' + R.number(group.items.length) +
            ' of ' + R.number(group.count) + ' ' + R.escape(String(group.record_type).toLowerCase()) +
            ' records this paper is curated against. Download the TSV for what is shown, or ' +
            'search the ' + R.escape(String(group.record_type).toLowerCase()) +
            ' collection for the rest.</p>');
        }
      }
    });

    return rendered;
  }

  /* ------------------------------------------------------------------------
     Cite this paper
     ------------------------------------------------------------------------ */

  function citationBlock(key, heading, note, text, monospace) {
    if (!text) { return ''; }
    var body = monospace
      ? '<pre class="mgdb-rec-citation-text" id="ref-citation-' + key + '">' + R.escape(text) + '</pre>'
      : '<p class="mgdb-rec-citation-text" id="ref-citation-' + key + '">' + R.escape(text) + '</p>';
    return '<div class="mgdb-rec-block">' +
      '<div class="mgdb-rec-block-head"><h3>' + R.escape(heading) + '</h3>' +
        '<button class="mgdb-rec-tsv" type="button" data-copy-target="ref-citation-' + key + '">Copy</button>' +
      '</div>' +
      '<p class="mgdb-rec-block-status">' + R.escape(note) + '</p>' + body + '</div>';
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
    els.citationBody.innerHTML = html;

    /* Copy without leaving the page. The clipboard API needs a secure context;
       where it is unavailable the text is selected instead, which still turns
       the task into one keystroke. */
    Array.prototype.forEach.call(
      els.citationBody.querySelectorAll('[data-copy-target]'), function (button) {
        button.addEventListener('click', function () {
          var source = R.byId(button.getAttribute('data-copy-target'));
          if (!source) { return; }

          function done() {
            button.textContent = 'Copied';
            button.setAttribute('data-copied', 'true');
            MGDB.announce('Citation copied to the clipboard.');
            window.setTimeout(function () {
              button.textContent = 'Copy';
              button.removeAttribute('data-copied');
            }, 2000);
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

          if (window.navigator && window.navigator.clipboard && window.isSecureContext) {
            window.navigator.clipboard.writeText(source.textContent).then(done).catch(selectInstead);
          } else {
            selectInstead();
          }
        });
      });

    return true;
  }

  /* ------------------------------------------------------------------------
     Metrics and figures
     ------------------------------------------------------------------------ */

  function renderMetrics(counts, sections) {
    var groups = sections.describes || [];
    var links = sections.links || [];

    R.metrics(els.metricsBody, [
      ['Authors', 'People', counts.authors, 'Authors credited on this paper.', 'green'],
      ['Records described', 'Curation', counts.describes, 'MaizeGDB records a curator connected to this paper.', 'amber'],
      ['Record types', 'Coverage', groups.length, 'Kinds of record those connections span.', 'blue'],
      ['Identifiers', 'Elsewhere', links.length, 'External identifiers and full-text links.', 'burgundy']
    ]);

    var series = [
      ['Authors', counts.authors], ['Records described', counts.describes],
      ['Record types', groups.length], ['Identifiers', links.length],
      ['Editorial nominations', counts.editorial]
    ];
    var height = R.connectionsHeight(series);

    /* How much of the maize literature each author has in MaizeGDB. It answers
       the question a reader actually has about a name they do not recognise --
       is this someone who publishes in maize regularly? -- and it is the one
       number on the page that is about the people rather than the paper. */
    var authors = (sections.authors || []).filter(function (a) { return a.paper_count > 0; });
    if (authors.length > 1 && MGDB.chart) {
      R.show(R.byId('ref-record-authors-figure'), true);
      var fits = Math.max(3, Math.floor((height - 80) / 34));
      var top = authors.slice(0, fits);
      R.sizeChart('ref-record-authors-chart', height);
      R.byId('ref-record-authors-caption').textContent =
        (top.length < authors.length
          ? 'The first ' + top.length + ' of ' + R.number(authors.length) + ' authors. '
          : '') +
        'Counts are what MaizeGDB holds for that author, which is a floor rather than a complete bibliography.';
      var ordered = top.slice().reverse();
      MGDB.chart({
        target: 'ref-record-authors-chart',
        traces: function () {
          return [{
            type: 'bar', orientation: 'h',
            y: ordered.map(function (a) { return a.full_name || a.name; }),
            x: ordered.map(function (a) { return a.paper_count; }),
            marker: { color: '#285d46' },
            // A leading non-breaking space is the only padding Plotly offers
            // for an outside bar label; SVG collapses a plain leading space.
            text: ordered.map(function (a) { return ' ' + R.number(a.paper_count); }),
            textposition: 'outside', textangle: 0, cliponaxis: false,
            hovertemplate: '%{y}<br>%{x:,} papers<extra></extra>'
          }];
        },
        layout: {
          height: height,
          margin: { l: 10, r: 60, t: 8, b: 44 },
          bargap: 0.3,
          xaxis: { title: { text: 'Papers in MaizeGDB' }, rangemode: 'tozero', automargin: true },
          yaxis: { type: 'category', automargin: true }
        }
      });
      R.watchChartWidth('ref-record-authors-chart');
    }

    R.connectionsChart('ref-record-connections-chart', 'ref-record-connections-caption',
                       'ref-record-connections-figure', series, height);
    return true;
  }

  /* ------------------------------------------------------------------------
     Assembly
     ------------------------------------------------------------------------ */

  var TAB_COUNTS = {
    'ref-record-editorial': ['editorial'],
    'ref-record-authors': ['authors'],
    'ref-record-describes': ['describes'],
    'ref-record-links': ['links']
  };

  var LABELS = {
    'ref-record-overview': 'Overview',
    'ref-record-editorial': 'Editorial Board pick',
    'ref-record-abstract': 'Abstract',
    'ref-record-authors': 'Authors',
    'ref-record-describes': 'What this paper describes',
    'ref-record-citation': 'Cite this paper',
    'ref-record-links': 'Identifiers and full text',
    'ref-record-metrics': 'Metrics',
    'ref-record-resources': 'Related resources',
    'ref-record-api': 'API'
  };

  function render(response) {
    payload = response;
    var data = response.data || {};
    var sections = data.sections || {};
    var meta = response.meta || {};
    var counts = meta.counts || {};

    R.show(els.loading, false);
    R.show(els.error, false);

    renderByline(sections.authors);

    var rendered = [];
    if (renderOverview(sections.overview, sections.citation)) { rendered.push('ref-record-overview'); }
    if (renderEditorial(sections.editorial)) { rendered.push('ref-record-editorial'); }
    if (renderAbstract(sections.abstract)) { rendered.push('ref-record-abstract'); }

    if (R.collection(els.authorsBody, {
      title: 'Authors credited on this paper',
      items: sections.authors,
      filename: 'reference-authors.tsv',
      pageSize: 25,
      columns: [
        { key: 'full_name', label: 'Author', tile: true,
          get: function (a) { return a.full_name || a.name; },
          html: function (a) {
            var name = a.full_name || a.name;
            return (a.html ? R.link(a.html, name) : R.escape(name)) +
              (a.is_first ? ' <span class="mgdb-pill mgdb-pill-ok">First author</span>' : '') +
              (a.is_last ? ' <span class="mgdb-pill">Last author</span>' : '');
          } },
        { key: 'name', label: 'As cited' },
        { key: 'position', label: 'Position', sort: 'number', numeric: true,
          get: function (a) { return a.position == null ? '' : String(a.position); } },
        { key: 'other_papers', label: 'Other papers in MaizeGDB', sort: 'number', numeric: true,
          get: function (a) { return a.other_papers == null ? '' : R.number(a.other_papers); },
          html: function (a) {
            if (!a.other_papers) { return '<span class="mgdb-muted">None</span>'; }
            return a.papers_html
              ? R.link(a.papers_html, R.number(a.other_papers))
              : R.number(a.other_papers);
          } }
      ]
    })) { rendered.push('ref-record-authors'); }

    if (renderDescribes(sections.describes)) { rendered.push('ref-record-describes'); }
    if (renderCitation(sections.citation)) { rendered.push('ref-record-citation'); }

    if (R.collection(els.linksBody, {
      title: 'Identifiers and full text',
      items: sections.links,
      filename: 'reference-identifiers.tsv',
      columns: [
        { key: 'database', label: 'Database', tile: true },
        { key: 'accession', label: 'Accession',
          html: function (l) { return l.url
            ? R.link(l.url, l.accession, l.is_external)
            : R.escape(l.accession); } },
        { key: 'destination', label: 'Where it goes' },
        R.urlColumn(function (l) { return l.url; })
      ]
    })) { rendered.push('ref-record-links'); }

    rendered.forEach(function (id) { R.show(R.byId(id), true); });

    // Revealed before the charts are drawn: Plotly sizes a figure to its
    // container, and a hidden container has no width.
    R.show(R.byId('ref-record-metrics'), true);
    if (renderMetrics(counts, sections)) { rendered.push('ref-record-metrics'); }

    R.tabs({
      el: els.tabs,
      order: rendered.concat(['ref-record-resources', 'ref-record-api']),
      labels: LABELS, counts: counts, tabCounts: TAB_COUNTS
    });

    R.notice(els.notice, meta, counts);
    MGDB.announce('Record loaded, ' + rendered.length + ' sections.');
  }

  function load() {
    var main = R.byId('ref-record-top');
    if (!main) { return; }
    var requested = main.getAttribute('data-requested-id') || main.getAttribute('data-reference-id');
    if (!requested) { return; }

    R.show(els.error, false);
    R.show(els.loading, true);

    MGDB.request('/api/v1/records/reference/' + encodeURIComponent(requested), { key: 'reference-record' })
      .then(function (response) {
        if (!response || !response.data) { throw new Error('unexpected payload'); }
        render(response);
      })
      .catch(function (error) {
        if (error && error.name === 'AbortError') { return; }
        R.show(els.loading, false);
        R.show(els.error, true);
      });
  }

  function init() {
    els = {
      byline: R.byId('ref-record-byline'),
      facts: R.byId('ref-record-facts'),
      tabs: R.byId('ref-record-tabs'),
      loading: R.byId('ref-record-loading'),
      error: R.byId('ref-record-error'),
      retry: R.byId('ref-record-retry'),
      notice: R.byId('ref-record-notice'),
      overviewBody: R.byId('ref-record-overview-body'),
      editorialBody: R.byId('ref-record-editorial-body'),
      abstractBody: R.byId('ref-record-abstract-body'),
      authorsBody: R.byId('ref-record-authors-body'),
      describesBody: R.byId('ref-record-describes-body'),
      citationBody: R.byId('ref-record-citation-body'),
      linksBody: R.byId('ref-record-links-body'),
      metricsBody: R.byId('ref-record-metrics-body')
    };
    if (els.retry) { els.retry.addEventListener('click', load); }
    R.apiCard('ref-copy-json-btn', 'ref-record-api-link', function () { return payload; });
    load();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
