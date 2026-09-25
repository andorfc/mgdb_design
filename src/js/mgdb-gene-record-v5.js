/* file: mgdb-gene-record-v5.js
 *
 * purpose: the gene record at /gene_center/gene/{id} -- four views over one
 *          /api/v1/records/gene response.
 *
 *          The renderers are the v2 mockup's, unchanged, so that nothing this
 *          page shows is a fresh reading of the payload. What is different is
 *          everything around them:
 *
 *            the header      is rendered by the server before this file runs,
 *                            from include/gene_header_panel.php;
 *            the navigation  is in the markup -- four tabs, four bubble bars,
 *                            a fixed section list per view -- so this file
 *                            opens a view, fills the counts, and marks what
 *                            came back empty, rather than building the bar
 *                            from whatever had data;
 *            empty sections  stay. v2 removed a section with no content from
 *                            its bar; here it is dimmed and says which of
 *                            three things is true -- no data for this record,
 *                            not held for this build, or not applicable to a
 *                            record of this kind.
 *
 *          Two further requests are made only when their view is opened: the
 *          pan-gene record for the member dataset matrix, and each additional
 *          classical gene.
 *
 *          Nothing here reads the DOM at module scope.
 */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  var R = window.MGDBRecord;
  if (!MGDB || !R) { return; }

  var els = {};
  var main = null;
  var payload = null;
  var state = {
    view: 'visual',        // visual | gene_model | pan_gene | genetic
    locus: null,           // active locus id in the genetic view
    loci: [],              // [{id, name, full_name, type, html}]
    locusLoaded: {},       // id -> 'loading' | 'done' | 'failed'
    panLoaded: null,       // null | 'loading' | 'done' | 'failed'
    rendered: {},          // section id -> true when it has content
    views: [],             // the views that have anything in them
    spies: {}              // view key -> the shell scrollspy bound to its bar
  };

  var KIND_LABELS = {
    gene_model: 'Gene model', gene_model_and_locus: 'Gene model and classical gene',
    locus: 'Classical gene', withdrawn: 'Withdrawn gene model'
  };
  var CURRENT_B73 = 'Zm-B73-REFERENCE-NAM-5.0';

  /* The four views, and the sections in each, exactly as the server renders
     them. Kept in step with $views in controllers/gene_center/gene_record_v5.php
     -- the markup is the server's and this only fills it. */
  var ORDER = {
    visual: ['gene-record-structure', 'gene-record-function', 'gene-record-expression',
             'gene-record-variation', 'gene-record-orthologs', 'gene-record-paralogs',
             'gene-record-provenance', 'gene-record-images', 'gene-record-references',
             'gene-record-metrics', 'gene-record-resources', 'gene-record-api'],
    gene_model: ['gm-overview', 'gm-annotations', 'gm-insertions', 'gm-expression',
                 'gm-snps', 'gm-proteomics', 'gm-sequences'],
    pan_gene: ['pg-members', 'pg-datasets', 'pg-orthologs'],
    genetic: ['gn-overview', 'gn-annotations', 'gn-references', 'gn-alleles', 'gn-stocks',
              'gn-map', 'gn-nearby', 'gn-genetic', 'gn-external']
  };

  /* Which meta.counts feed which bubble badge. */
  var TAB_COUNTS = {
    'gene-record-structure': ['transcripts', 'protein_domains'],
    'gene-record-function': ['ontology', 'gene_products'],
    'gene-record-variation': ['insertions', 'snp_traits', 'alleles'],
    'gene-record-orthologs': ['orthologs'],
    'gene-record-paralogs': ['homeologs', 'tandem_duplicates'],
    'gene-record-images': ['images'],
    'gene-record-references': ['references'],
    'gm-annotations': ['ontology_gene_model', 'protein_domains'],
    'gm-insertions': ['insertions'],
    'gm-snps': ['snp_traits'],
    'pg-members': ['pan_gene_members'],
    'pg-orthologs': ['orthologs'],
    'gn-annotations': ['ontology_locus'],
    'gn-references': ['references'],
    'gn-alleles': ['alleles'],
    'gn-stocks': ['stocks'],
    'gn-map': ['map_positions'],
    'gn-external': ['xrefs']
  };

  /* The genetic view's nine sections describe ONE classical gene -- the one the
     header leads with. v2 built a set per locus and a switcher between them;
     here the header already names the others and links to their own pages, so
     the sections are fixed and the primary locus is what fills them. */
  var LOCUS_KEYS = ['overview', 'annotations', 'references', 'alleles', 'stocks', 'map', 'nearby', 'genetic', 'external'];

  /* ======================================================================
     When there is nothing to show

     Three different facts get told apart, because they lead somewhere
     different:

       NO DATA            MaizeGDB has this category and this record has
                          nothing in it. Nothing to do about it.
       NOT IN THIS BUILD  the data exists for maize but not for the assembly
                          or annotation this page is showing -- old annotations
                          have no proteomics, no exon coordinates, no qTeller.
                          Says which build, and links to the one that has it.
       NEEDS A GENE MODEL the section is about a gene model and this record is
       NEEDS A GENE       a classical gene with none linked, or the reverse.

     Never wine. That colour is spoken for by the two header callouts, which
     mean "look at this"; an empty section means the opposite.
     ====================================================================== */

  var EMPTY_KINDS = { none: 'No data', build: 'Not in this build', needs: 'Not applicable' };

  function emptyState(sectionId, kind, sentence, extraHtml) {
    var out = body(sectionId);
    if (!out) { return false; }
    out.innerHTML = '<div class="v5-nodata v5-nodata-' + kind + '">'
      + '<span class="v5-nodata-tag">' + R.escape(EMPTY_KINDS[kind] || EMPTY_KINDS.none) + '</span>'
      + '<p>' + sentence + (extraHtml || '') + '</p></div>';
    return false;
  }

  /* What each section holds, as a noun phrase. The sentences are built from
     this rather than written out one by one, so every empty section reads the
     same way and a new section cannot arrive without one. */
  var SECTION_NOUN = {
    'gene-record-structure': 'transcript or protein-domain structure',
    'gene-record-function': 'ontology terms or gene products',
    'gene-record-expression': 'expression data',
    'gene-record-variation': 'insertions, SNP-trait links or alleles',
    'gene-record-orthologs': 'orthologs in other species',
    'gene-record-paralogs': 'retained homeologs or tandem arrays',
    'gene-record-provenance': 'gene model scores',
    'gene-record-images': 'mutant phenotype images',
    'gene-record-references': 'publications',
    'gene-record-metrics': 'counts to chart',
    'gm-overview': 'gene model facts',
    'gm-annotations': 'ontology terms or model scores',
    'gm-insertions': 'insertions',
    'gm-expression': 'expression data',
    'gm-snps': 'SNP-trait associations',
    'gm-proteomics': 'proteomics evidence',
    'gm-sequences': 'sequence downloads',
    'pg-members': 'related gene models',
    'pg-datasets': 'member datasets',
    'pg-orthologs': 'orthologs',
    'gn-overview': 'curated facts',
    'gn-annotations': 'ontology terms',
    'gn-references': 'publications',
    'gn-alleles': 'alleles, variations or polymorphisms',
    'gn-stocks': 'stocks',
    'gn-map': 'map coordinates',
    'gn-nearby': 'nearby loci',
    'gn-genetic': 'additional genetic information',
    'gn-external': 'external links'
  };

  /* Sections whose data MaizeGDB only generates for the current annotation.
     An old build with nothing in these is not a gap in the record -- the work
     was never done for that build -- and saying so stops a reader concluding
     the gene has no expression when what it has no expression for is
     B73 RefGen_v3. */
  var NO_OLD_BUILD = {
    'gene-record-expression': 1, 'gene-record-provenance': 1, 'gene-record-paralogs': 1,
    'gm-expression': 1, 'gm-proteomics': 1, 'gm-snps': 1, 'gm-sequences': 1
  };

  /* Sections that describe a gene model and cannot exist without one. */
  var GENE_MODEL_ONLY = {
    'gene-record-structure': 1, 'gene-record-expression': 1, 'gene-record-orthologs': 1,
    'gene-record-provenance': 1, 'gene-record-paralogs': 1
  };

  function isCurrentBuild() {
    var o = (payload && payload.data && payload.data.sections && payload.data.sections.overview) || {};
    return ((o.assembly && o.assembly.name) || '') === CURRENT_B73;
  }

  /* "MaizeGDB has no ..." rather than "No ... have been recorded": one shape
     that is right whether the noun is singular or plural, and it names who is
     saying it, which matters when the answer is "not for this build". */
  function sentenceFor(id, kind) {
    var noun = SECTION_NOUN[id] || 'data';
    if (kind === 'build') {
      return 'MaizeGDB does not hold ' + noun + ' for ' + buildName() + '.' + currentBuildLink();
    }
    return 'MaizeGDB has no ' + noun + ' for ' + recordName() + '.';
  }

  function fillEmpty(id) {
    /* A section that knows better than the generic sentence says so here. */
    if (state.emptyNote && state.emptyNote[id]) { return emptyState(id, 'none', state.emptyNote[id]); }
    var kind = (NO_OLD_BUILD[id] && !isCurrentBuild()) ? 'build' : 'none';
    return emptyState(id, kind, sentenceFor(id, kind));
  }

  /* The identifier to name in those sentences: the gene model when there is
     one, else the gene. */
  function recordName() {
    var o = (payload && payload.data && payload.data.sections && payload.data.sections.overview) || {};
    var a = (payload && payload.data && payload.data.attributes) || {};
    return R.escape(o.name || a.name || o.symbol || a.symbol || 'this record');
  }

  function buildName() {
    var o = (payload && payload.data && payload.data.sections && payload.data.sections.overview) || {};
    var asm = (o.assembly && o.assembly.name) || o.line || '';
    return asm ? R.escape(asm) : 'this assembly';
  }

  /* "…; it is available for B73 NAM-5.0" with a link, but only when this page
     is not already showing that build. */
  function currentBuildLink() {
    var o = (payload && payload.data && payload.data.sections && payload.data.sections.overview) || {};
    var asm = (o.assembly && o.assembly.name) || '';
    if (asm === CURRENT_B73) { return ''; }
    return ' The current B73 annotation (' + R.escape(CURRENT_B73) + ') may have it.';
  }

  /* ------------------------------------------------------------------------
     Small helpers
     ------------------------------------------------------------------------ */

  function num(value) { return (value === null || value === undefined) ? '' : R.number(value); }
  function mb(bp) { return (Number(bp) / 1e6).toFixed(Number(bp) >= 1e8 ? 0 : 1) + ' Mb'; }
  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function body(id) { return R.byId(id + '-body'); }

  function blockHtml(title, count, inner, extraHead) {
    return '<div class="mgdb-rec-block"><div class="mgdb-rec-block-head"><h3>' + R.escape(title) +
      (count !== null && count !== undefined && count !== '' ? '<span class="mgdb-rec-block-count">' + R.escape(String(count)) + '</span>' : '') +
      '</h3>' + (extraHead || '') + '</div>' + inner + '</div>';
  }

  function statusLine(text) { return '<p class="mgdb-rec-block-status">' + R.escape(text) + '</p>'; }

  /* A line under a figure pointing at the table behind it in another view. */
  function crosslink(target, text, sectionId, linkText) {
    target.insertAdjacentHTML('beforeend',
      '<p class="v2-crosslink">' + R.escape(text) + ' <button class="v2-jump" type="button" data-jump="' +
      R.escape(sectionId) + '">' + R.escape(linkText) + '</button></p>');
  }

  function locationText(row) {
    if (!row || row.start == null) { return ''; }
    return (row.chromosome ? row.chromosome + ':' : '') + R.number(row.start) +
      (row.end != null && row.end !== row.start ? '–' + R.number(row.end) : '');
  }

  function refNames(refs) {
    return (refs || []).map(function (r) { return r && r.name; }).filter(Boolean).join(', ');
  }

  function refLinks(refs) {
    var list = (refs || []).filter(function (r) { return r && r.name; });
    if (!list.length) { return '<span class="mgdb-muted">—</span>'; }
    return list.map(function (r) { return r.html ? R.link(r.html, r.name) : R.escape(r.name); }).join(', ');
  }

  function mark(rendered, id) { if (rendered) { state.rendered[id] = true; } return rendered; }

  /* ------------------------------------------------------------------------
     Views: which sections show, which bar sticks, where the offset lands
     ------------------------------------------------------------------------ */

  /* ======================================================================
     Navigation

     The v2 mockup built its own view switcher and its own tab bars from
     whatever came back with data. This page's navigation is in the markup
     already -- four tabs, four bubble bars, four sets of sections, rendered by
     the server from one curated list -- so this layer only has to open one of
     them, fill in the counts, and mark the sections that came back empty.

     That difference is deliberate. A bar built from the response changes shape
     between records, so the same section sits in a different place on every
     gene; a bar that is always the same teaches its own layout, and a dimmed
     bubble says "MaizeGDB has this category and this gene has none of it",
     which a missing bubble cannot say.
     ====================================================================== */

  /* Section ids other pages link to that this layout no longer has.

     /new_genes builds about 3,300 links to #gene-record-overview. The visual
     view has no Overview section -- the overview facts are the header and the
     Gene model view's Overview -- so without this those links opened the page
     at the top with nothing selected. They land on gm-overview instead, which
     carries the same facts.

     Sequences sat in the visual view until 2026-09-17 and moved to the Gene
     model view; a link made while it was there still lands on it. */
  var SECTION_ALIASES = { 'gene-record-overview': 'gm-overview', 'gene-record-sequences': 'gm-sequences' };
  function resolveSection(sectionId) {
    return SECTION_ALIASES[sectionId] || sectionId;
  }

  function viewOf(sectionId) {
    sectionId = resolveSection(sectionId);
    for (var key in ORDER) {
      if (ORDER[key].indexOf(sectionId) >= 0) { return key; }
    }
    return null;
  }

  function measureNav() {
    if (!els.nav || !main) { return; }
    var h = els.nav.getBoundingClientRect().height;
    if (h > 0) { main.style.setProperty('--v5-nav-h', Math.min(Math.round(h + 8), 320) + 'px'); }
  }

  function spyFor(key) {
    if (!state.spies[key]) {
      state.spies[key] = (typeof MGDB.sectionTabs === 'function')
        ? MGDB.sectionTabs({ bar: R.byId('v5-bar-' + key) })
        : function () {};
    }
    return state.spies[key];
  }

  function showView(key, moveFocus) {
    if (!key || !ORDER[key]) { return; }
    state.view = key;
    qsa('[data-v5-view]').forEach(function (tab) {
      var on = tab.getAttribute('data-v5-view') === key;
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
      if (on) { tab.removeAttribute('tabindex'); } else { tab.setAttribute('tabindex', '-1'); }
      if (on && moveFocus) { tab.focus(); }
    });
    qsa('[data-v5-bar]').forEach(function (bar) { bar.hidden = bar.getAttribute('data-v5-bar') !== key; });
    qsa('[data-v5-panels]').forEach(function (p) { p.hidden = p.getAttribute('data-v5-panels') !== key; });
    /* The member-dataset matrix is a second request, made the first time this
       view is opened rather than on every page load. */
    if (key === 'pan_gene' && state.panLoaded === null) { loadPanExtra(); }

    measureNav();
    spyFor(key)();
    if (typeof MGDB.announce === 'function') {
      var label = qs('#v5-tab-' + key + ' strong');
      MGDB.announce((label ? label.textContent : key) + ' view.');
    }
  }

  /* A jump from one view into a section of another -- the crosslinks the
     renderers emit -- has to open that view first, and the browser will not
     scroll to something that was hidden when it was asked. */
  function jumpTo(sectionId) {
    var key = viewOf(sectionId);
    if (!key) { return; }
    if (key !== state.view) { showView(key, false); }
    window.setTimeout(function () {
      var target = R.byId(sectionId);
      if (!target) { return; }
      target.scrollIntoView({ behavior: MGDB.prefersReducedMotion && MGDB.prefersReducedMotion() ? 'auto' : 'smooth' });
      var link = qs('[data-v5-bar="' + key + '"] a[href="#' + sectionId + '"]');
      if (link) { link.click(); }
    }, 0);
  }

  /* Counts on the bubbles, and the dim on the ones with nothing behind them.
     Run once after the response is rendered. */
  function markBars(counts) {
    for (var key in ORDER) {
      ORDER[key].forEach(function (id) {
        var link = qs('[data-v5-bar="' + key + '"] a[href="#' + id + '"]');
        var section = R.byId(id);
        if (!link || !section) { return; }

        var keys = TAB_COUNTS[id];
        var total = null;
        if (keys) {
          total = 0;
          keys.forEach(function (k) { total += Number(counts[k] || 0); });
        }
        var badge = link.querySelector('.mgdb-tab-count');
        if (badge && total !== null && total > 0) {
          badge.textContent = R.number(total);
          badge.hidden = false;
        }

        var empty = !state.rendered[id];
        link.classList.toggle('is-empty', empty);
        section.classList.toggle('is-empty', empty);
        if (empty) { link.setAttribute('title', section.getAttribute('data-section-label') + ' has nothing for this record'); }
        else { link.removeAttribute('title'); }
      });
    }
  }

  /* The header is the server's -- name, synonyms, maps, both callouts, all of
     it from include/gene_header_panel.php before this file runs. What is left
     for the response to say is the two things the server cannot: the report
     link needs the feature id, and the earlier-annotation notice needs to know
     which current model to point at. */
  function renderHeader(data, sections) {
    var attributes = data.attributes || {};
    var locus = sections.locus || {};

    var assembly = attributes.assembly || '';
    if (els.versionNotice && assembly && assembly.indexOf('B73') !== -1 && assembly !== CURRENT_B73) {
      var models = (locus.associated_gene_models || []).filter(function (m) { return m.assembly === CURRENT_B73; });
      var link = models.length
        ? ' The current B73 annotation of this gene is ' +
          R.link('/gene_center/gene/' + encodeURIComponent(models[0].name), models[0].name) + '.'
        : '';
      els.versionNotice.innerHTML = '<div><strong>An earlier B73 assembly</strong>' +
        '<span>This record is the ' + R.escape(assembly) + ' annotation. B73 has been assembled and ' +
        'annotated several times, and each release numbers its genes differently.' + link + '</span></div>';
      R.show(els.versionNotice, true);
    }

    if (els.report && attributes.feature_id) {
      els.report.setAttribute('href', '/curation/GeneModelIssue/edit?gene_model_id=' +
        encodeURIComponent(attributes.feature_id) + '&gene_model_version=' +
        encodeURIComponent(attributes.annotation || '') + '&auto_num=');
    }
  }

  /* The karyotype: ten chromosomes to scale, this one filled, the gene
     pinned; then the chromosome alone with the pin placed to the base pair.
     Lengths come from the controller (data attributes), read from the
     chromosome features of the same assembly. */

  /* At a glance: one tile per count that is not zero, each opening the
     section that holds the rows. */

  /* ========================================================================
     VIEW 1: Visual overview
     ======================================================================== */

  function domainTrack(domains, protein) {
    if (!protein || !protein.length_aa) { return ''; }
    var length = protein.length_aa;
    var canonical = domains.filter(function (d) { return d.is_canonical && d.start && d.end; });
    if (!canonical.length) { return ''; }
    var bars = canonical.map(function (domain, index) {
      var left = ((domain.start - 1) / length) * 100;
      var width = Math.max(((domain.end - domain.start + 1) / length) * 100, 0.6);
      return '<span class="gene-record-domain gene-record-domain-' + (index % 5) + '" style="left:' + left.toFixed(2) +
        '%;width:' + width.toFixed(2) + '%" title="' + R.escape(domain.name + ' ' + domain.start + '–' + domain.end) + '">' +
        '<span class="mgdb-visually-hidden">' + R.escape(domain.name + ', residues ' + domain.start + ' to ' + domain.end) + '</span></span>';
    }).join('');
    var legend = canonical.map(function (domain, index) {
      return '<li><span class="gene-record-swatch gene-record-domain-' + (index % 5) + '"></span>' +
        (domain.url ? R.link(domain.url, domain.name, true) : R.escape(domain.name)) +
        ' <span class="gene-record-muted">' + domain.start + '–' + domain.end + '</span></li>';
    }).join('');
    return '<figure class="gene-record-track"><div class="gene-record-track-bar" role="img" aria-label="Protein domain positions">' + bars +
      '</div><div class="gene-record-track-scale"><span>1</span><span>' + R.number(length) + ' aa</span></div>' +
      '<ul class="gene-record-track-legend">' + legend + '</ul></figure>';
  }

  function renderStructure(structure) {
    if (!structure) { return false; }
    var out = body('gene-record-structure');
    out.innerHTML = '';
    var protein = structure.protein || {};
    var rendered = false;
    var figureDrawn = false;

    if (structure.gene_model && MGDB.geneStructure) {
      var attrs = payload.data.attributes || {};
      var figureBlock = document.createElement('div');
      figureBlock.className = 'mgdb-rec-block gene-record-structure-block';
      figureBlock.innerHTML = '<div class="mgdb-rec-block-head"><h3>Gene model and protein</h3>' +
        /* The dataset build ("Zm00001eb.1-20260422") used to sit here. The
           badge beside a block heading is a COUNT -- readers take a number in
           it as "how many" -- so a release string in it reads as a quantity
           and is not one. The annotation is already named in the header and in
           the Gene model view's Overview. */
        '</div><div class="gene-record-structure-figure"></div>';
      out.appendChild(figureBlock);
      figureDrawn = MGDB.geneStructure(qs('.gene-record-structure-figure', figureBlock), {
        gene: { name: attrs.name || structure.gene_model.canonical_transcript, symbol: attrs.symbol,
                chromosome: structure.gene_model.chromosome, strand: structure.gene_model.strand,
                start: structure.gene_model.start, end: structure.gene_model.end },
        geneModel: structure.gene_model, domains: structure.domains || null, model: structure.model || null, base: ''
      });
      if (!figureDrawn) { figureBlock.parentNode.removeChild(figureBlock); } else { rendered = true; }
    }

    var factsHtml = R.facts([
      ['Canonical transcript', protein.transcript ? R.escape(protein.transcript) : ''],
      ['Protein', protein.name ? R.escape(protein.name) : ''],
      ['Protein length', protein.length_aa ? R.number(protein.length_aa) + ' aa' : '', protein.length_note || ''],
      ['Transcripts', (structure.transcripts || []).length ? String(structure.transcripts.length) : ''],
      ['Protein domains', (structure.protein_domains || []).length ? String(structure.protein_domains.length) : '',
        structure.domains && structure.domains.architecture ? structure.domains.architecture : '']
    ]);
    if (factsHtml) { out.insertAdjacentHTML('beforeend', factsHtml); rendered = true; }

    var trackHtml = figureDrawn ? '' : domainTrack(structure.protein_domains || [], protein);
    if (trackHtml) {
      out.insertAdjacentHTML('beforeend', blockHtml('Protein domains, to scale', null, trackHtml));
      rendered = true;
    }
    if (rendered && state.views.indexOf('gene_model') !== -1) {
      crosslink(out, 'Transcripts, exon coordinates and every domain match are listed in the Gene model view.',
        'gm-overview', 'Open the tables');
    }
    if (structure.exon_structure_note) { out.insertAdjacentHTML('beforeend', statusLine(structure.exon_structure_note)); }
    return rendered;
  }

  function renderFunction(fn) {
    if (!fn) { return false; }
    var out = body('gene-record-function');
    out.innerHTML = '';
    var rendered = false;
    var figureDrawn = false;

    if (MGDB.geneFunction && (fn.go || fn.classes || fn.pathways)) {
      var attrs = payload.data.attributes || {};
      var figureBlock = document.createElement('div');
      figureBlock.className = 'mgdb-rec-block gene-record-function-block';
      figureBlock.innerHTML = '<div class="mgdb-rec-block-head"><h3>Function at a glance</h3>' +
        (fn.go && fn.go.available && fn.go.release
          ? '' : '') +
        '</div><div class="gene-record-function-figure"></div>';
      out.appendChild(figureBlock);
      figureDrawn = MGDB.geneFunction(qs('.gene-record-function-figure', figureBlock), {
        gene: { name: attrs.name, symbol: attrs.symbol }, fn: fn, base: ''
      });
      if (!figureDrawn) { figureBlock.parentNode.removeChild(figureBlock); } else { rendered = true; }
    }

    /* A record with nothing to draw -- a classical gene with a product and
       no terms -- still gets its facts here rather than an empty section. */
    if (!figureDrawn) {
      rendered = R.collection(out, {
        title: 'Ontology terms', items: fn.ontology, filename: 'gene-ontology-terms.tsv',
        columns: ontologyColumns()
      }) || rendered;
      rendered = R.collection(out, {
        title: 'Gene products', items: fn.gene_products, filename: 'gene-products.tsv',
        columns: [
          { key: 'name', label: 'Gene product', tile: true,
            html: function (g) { return g.html ? R.link(g.html, g.name) : R.escape(g.name); } },
          { key: 'type', label: 'Type' }
        ]
      }) || rendered;
    } else {
      /* 'gn-annotations', not v2's per-locus 'locus-<id>-annotations': this
         layout has one fixed set of genetic sections, and the old id resolved
         to nothing, so the jump went nowhere. Same fault as the Alleles
         crosslink that the Variation tables replaced. */
      var where = state.views.indexOf('gene_model') !== -1 ? 'gm-annotations' : (state.locus ? 'gn-annotations' : null);
      if (where) {
        crosslink(out, 'Every term with its evidence and source, the gene products and the protein accessions are tabled under Annotations.',
          where, 'Open the tables');
      }
    }

    /* Plant Reactome. Curated reactions and pathways this gene's product takes
       part in -- a different source from the pan-genome pathway explorer above,
       and from CornCyc, so it is its own block rather than folded in.

       Most gene models have neither: 5,364 reaction rows and 4,085 pathway rows
       across the whole corpus. Absent rather than empty when there are none. */
    var pr = (fn && fn.plant_reactome) || {};
    var prRows = (pr.reactions || []).map(function (r) { return { kind: 'Reaction', accession: r.accession, url: r.url }; })
      .concat((pr.pathways || []).map(function (r) { return { kind: 'Pathway', accession: r.accession, url: r.url }; }));
    if (prRows.length) {
      rendered = R.collection(out, {
        title: 'Plant Reactome', items: prRows, filename: 'gene-plant-reactome.tsv',
        columns: [
          { key: 'accession', label: 'Entry', tile: true,
            html: function (r) { return R.link(r.url, r.accession, true); } },
          { key: 'kind', label: 'Kind' }
        ]
      }) || rendered;
    }
    return rendered;
  }

  function ontologyColumns() {
    return [
      { key: 'term', label: 'Term', tile: true, html: function (t) { return t.url ? R.link(t.url, t.term, true) : R.escape(t.term); } },
      { key: 'name', label: 'Name' },
      { key: 'ontology', label: 'Ontology' },
      { key: 'evidence_label', label: 'Evidence', get: function (t) { return t.evidence_label || t.evidence_code || ''; } },
      { key: 'source', label: 'Source' },
      { key: 'scope', label: 'Attached to', get: function (t) { return t.scope === 'locus' ? 'Classical gene' : (t.scope === 'gene_model' ? 'Gene model' : (t.attached_to || t.scope || '')); } }
    ];
  }

  /* eFP viewer, as on the live page: one atlas at a time. */
  var efpState = { atlas: 0, mode: 'Absolute', atlases: [] };

  function efpShow() {
    var atlas = efpState.atlases[efpState.atlas];
    if (!atlas) { return; }
    var stage = R.byId('gene-record-efp-stage');
    var img = qs('img', stage);
    var link = R.byId('gene-record-efp-open');
    stage.classList.remove('is-missing');
    stage.classList.add('is-loading');
    img.alt = atlas.label + ' expression pattern, ' + efpState.mode.toLowerCase() + ' scale';
    img.src = efpState.mode === 'Relative' ? atlas.image_relative : atlas.image;
    if (link) { link.setAttribute('href', atlas.browser); }
    qsa('[data-atlas]', R.byId('gene-record-efp-atlases')).forEach(function (button) {
      button.setAttribute('aria-pressed', String(Number(button.getAttribute('data-atlas')) === efpState.atlas));
    });
    qsa('[data-mode]', R.byId('gene-record-efp-toolbar')).forEach(function (button) {
      button.setAttribute('aria-pressed', String(button.getAttribute('data-mode') === efpState.mode));
    });
  }

  function renderEfp(out, efp) {
    if (!efp || !efp.available || !efp.atlases || !efp.atlases.length) { return false; }
    efpState.atlases = efp.atlases; efpState.atlas = 0; efpState.mode = 'Absolute';
    out.insertAdjacentHTML('beforeend',
      '<div class="mgdb-rec-block"><div class="mgdb-rec-block-head"><h3>eFP Browser<span class="mgdb-rec-block-count">' + efp.atlases.length + '</span></h3></div>' +
      '<div class="mgdb-rec-toolbar gene-record-efp-toolbar" id="gene-record-efp-toolbar">' +
        '<div class="mgdb-view-toggle" role="group" aria-label="Color scale">' +
          '<button class="mgdb-view-btn" type="button" data-mode="Absolute" aria-pressed="true">Absolute</button>' +
          '<button class="mgdb-view-btn" type="button" data-mode="Relative" aria-pressed="false">Relative</button>' +
        '</div>' +
        '<a class="mgdb-button mgdb-button-secondary gene-record-efp-open" id="gene-record-efp-open" href="' + R.escape(efp.browser) + '" target="_blank" rel="noopener">Open at the BAR</a>' +
      '</div>' +
      '<div class="gene-record-efp-atlases" id="gene-record-efp-atlases" role="group" aria-label="Atlas">' +
        efp.atlases.map(function (atlas, index) {
          return '<button class="gene-record-efp-atlas" type="button" data-atlas="' + index + '" aria-pressed="' + (index === 0) + '">' + R.escape(atlas.label) + '</button>';
        }).join('') +
      '</div>' +
      '<div class="gene-record-efp-stage is-loading" id="gene-record-efp-stage"><img alt=""></div>' +
      /* What the figure above actually is. The one-line source credit assumed
         the reader already knew what an eFP browser was; this says it, and
         credits the group that built it. Above the caveat, because the caveat
         only makes sense once you know the pictures are the tissues. */
      '<p class="v2-lead">The <strong>electronic Fluorescent Pictograph browser</strong> (eFP browser) was ' +
      'developed by Nicholas Provart and colleagues at the Bio-Analytic Resource for Plant Biology at the ' +
      'University of Toronto. It projects gene expression data onto a series of pictures (pictographs) ' +
      'representing the plant tissues the data came from, each coloured by the level of expression for the ' +
      'gene of interest. An eFP browser can show a single gene across a variety of tissues, or a single ' +
      'tissue through a series of stress treatments &mdash; heat, drought, insect and pathogen infection.</p>' +
      (efp.note ? statusLine(efp.note) : '') +
      '<p class="mgdb-rec-block-status">' + R.escape(efp.source) + '. ' + R.link(efp.eplant, 'Explore this gene in ePlant', true) + '.</p>' +
      '</div>');
    var stage = R.byId('gene-record-efp-stage');
    var img = qs('img', stage);
    img.addEventListener('load', function () { stage.classList.remove('is-loading'); });
    img.addEventListener('error', function () { stage.classList.remove('is-loading'); stage.classList.add('is-missing'); });
    qsa('[data-atlas]', R.byId('gene-record-efp-atlases')).forEach(function (button) {
      button.addEventListener('click', function () { efpState.atlas = Number(button.getAttribute('data-atlas')); efpShow(); });
    });
    qsa('[data-mode]', R.byId('gene-record-efp-toolbar')).forEach(function (button) {
      button.addEventListener('click', function () { efpState.mode = button.getAttribute('data-mode'); efpShow(); });
    });
    efpShow();
    return true;
  }

  function expressionGaps(expression) {
    var gaps = [];
    if (expression.rnaseq_histogram && !expression.rnaseq_histogram.available) { gaps.push(expression.rnaseq_histogram.reason); }
    if (expression.proteomics && !expression.proteomics.available) { gaps.push(expression.proteomics.reason); }
    return gaps.filter(Boolean);
  }

  function renderExpression(expression) {
    if (!expression) { return false; }
    var out = body('gene-record-expression');
    out.innerHTML = '';
    var rendered = false;
    var profileDrawn = false;

    if (expression.profile && MGDB.geneExpression) {
      var attrs = payload.data.attributes || {};
      var block = document.createElement('div');
      block.className = 'mgdb-rec-block gene-record-expression-block';
      block.innerHTML = '<div class="mgdb-rec-block-head"><h3>Expression profile</h3>' +
        (expression.profile.attributes && expression.profile.attributes.release
          ? '' : '') +
        '</div><div class="gene-record-expression-figure"></div>';
      out.appendChild(block);
      profileDrawn = MGDB.geneExpression(qs('.gene-record-expression-figure', block), {
        gene: { name: attrs.name, symbol: attrs.symbol }, profile: expression.profile,
        qteller: expression.qteller && expression.qteller.available ? expression.qteller.url : null
      });
      if (!profileDrawn) { block.parentNode.removeChild(block); } else { rendered = true; }
    } else if (expression.profile_note) {
      out.insertAdjacentHTML('beforeend', statusLine(expression.profile_note));
    }

    if (!profileDrawn && expression.qteller && expression.qteller.available) {
      out.insertAdjacentHTML('beforeend', blockHtml('qTeller', null,
        '<div class="mgdb-rec-linkrow"><a class="mgdb-button mgdb-button-primary" href="' + R.escape(expression.qteller.url) +
        '" target="_blank" rel="noopener">Open the expression atlas</a></div>'));
      rendered = true;
    }

    rendered = renderEfp(out, expression.efp) || rendered;

    var gaps = expressionGaps(expression);
    if (gaps.length) {
      R.notes(out, 'Not available for this gene', gaps.map(function (t) { return { text: t }; }));
      rendered = true;
    }
    if (rendered && profileDrawn && state.views.indexOf('gene_model') !== -1) {
      crosslink(out, 'Every sample, every study and the atlas links are tabled in the Gene model view.', 'gm-expression', 'Open the tables');
    }
    if (expression.note) { out.insertAdjacentHTML('beforeend', statusLine(expression.note)); }
    return rendered;
  }

  /* The variation track: the canonical transcript to scale, insertions
     flagged above it, SNP-trait positions pinned below. */
  function variationTrack(overview, structure, variation) {
    if (!overview || overview.start == null || overview.end == null) { return ''; }
    var gStart = Number(overview.start), gEnd = Number(overview.end);
    if (gEnd <= gStart) { return ''; }
    var insertions = (variation.insertions || []).filter(function (i) { return i.start != null; });
    var snps = (variation.snp_traits || []).filter(function (s) { return s.position != null; });
    if (!insertions.length && !snps.length) { return ''; }

    var pad = Math.max(500, Math.round((gEnd - gStart) * 0.08));
    var lo = gStart - pad, hi = gEnd + pad;
    var outside = 0;
    function clampPos(p) { if (p < lo) { outside++; return lo; } if (p > hi) { outside++; return hi; } return p; }

    var W = 1000, L = 24, Rm = 24, plotW = W - L - Rm;
    var yIns = 46, yGene = 108, ySnp = 168, yAxis = 214, H = 236;
    function x(bp) { return L + ((bp - lo) / (hi - lo)) * plotW; }

    var canonical = (structure && structure.exon_structure || []).filter(function (t) { return t.canonical; })[0] ||
                    (structure && structure.exon_structure || [])[0];
    var gene = '';
    gene += '<line class="v2-intron" x1="' + x(gStart).toFixed(1) + '" y1="' + yGene + '" x2="' + x(gEnd).toFixed(1) + '" y2="' + yGene + '"/>';
    if (canonical) {
      (canonical.exons || []).forEach(function (e) {
        gene += '<rect class="v2-exon" x="' + x(e.start).toFixed(1) + '" y="' + (yGene - 6) + '" width="' + Math.max(1.5, x(e.end) - x(e.start)).toFixed(1) + '" height="12" rx="1.5"><title>Exon ' + e.rank + ', ' + R.number(e.start) + '–' + R.number(e.end) + '</title></rect>';
      });
      (canonical.cds || []).forEach(function (c) {
        gene += '<rect class="v2-cds" x="' + x(c.start).toFixed(1) + '" y="' + (yGene - 10) + '" width="' + Math.max(1.5, x(c.end) - x(c.start)).toFixed(1) + '" height="20" rx="2"><title>CDS, ' + R.number(c.start) + '–' + R.number(c.end) + '</title></rect>';
      });
    } else {
      gene += '<rect class="v2-exon" x="' + x(gStart).toFixed(1) + '" y="' + (yGene - 6) + '" width="' + (x(gEnd) - x(gStart)).toFixed(1) + '" height="12" rx="1.5"><title>Gene span</title></rect>';
    }
    var strandText = overview.strand === '-' ? '← transcribed right to left (minus strand)' : (overview.strand === '+' ? 'transcribed left to right (plus strand) →' : '');

    var sources = [];
    insertions.forEach(function (i) { if (sources.indexOf(i.source || 'Insertion') === -1) { sources.push(i.source || 'Insertion'); } });
    var insHtml = insertions.map(function (i) {
      var mid = clampPos((Number(i.start) + Number(i.end == null ? i.start : i.end)) / 2);
      var cx = x(mid).toFixed(1);
      var alt = sources.indexOf(i.source || 'Insertion') % 2 === 1;
      var title = i.name + ' · ' + (i.source || 'insertion') + (i.gene_structures ? ' · ' + i.gene_structures : '') + ' · ' + locationText(i);
      return '<g><line class="v2-ins-stem" x1="' + cx + '" y1="' + (yIns + 8) + '" x2="' + cx + '" y2="' + (yGene - 12) + '"/>' +
        '<polygon class="v2-ins' + (alt ? ' is-alt' : '') + '" points="' + (Number(cx) - 7) + ',' + (yIns - 6) + ' ' + (Number(cx) + 7) + ',' + (yIns - 6) + ' ' + cx + ',' + (yIns + 8) + '"><title>' + R.escape(title) + '</title></polygon></g>';
    }).join('');

    var byPos = {};
    snps.forEach(function (s) { var k = String(s.position); (byPos[k] = byPos[k] || []).push(s); });
    var snpHtml = Object.keys(byPos).map(function (k) {
      var list = byPos[k];
      var cx = x(clampPos(Number(k))).toFixed(1);
      var r = 4 + Math.min(5, list.length - 1);
      var traits = [];
      list.forEach(function (s) { if (traits.indexOf(s.trait) === -1) { traits.push(s.trait); } });
      var title = list[0].snp + ' · ' + (list[0].gene_structure || '') + ' · ' + traits.length + ' trait' + (traits.length === 1 ? '' : 's') + ': ' + traits.slice(0, 6).join('; ') + (traits.length > 6 ? '…' : '');
      return '<g><line class="v2-snp-stem" x1="' + cx + '" y1="' + (yGene + 12) + '" x2="' + cx + '" y2="' + (ySnp - r) + '"/>' +
        '<circle class="v2-snp" cx="' + cx + '" cy="' + ySnp + '" r="' + r + '"><title>' + R.escape(title) + '</title></circle></g>';
    }).join('');

    var ticks = '';
    var n = 5;
    for (var t = 0; t <= n; t++) {
      var bp = Math.round(lo + (hi - lo) * t / n);
      var tx = x(bp).toFixed(1);
      ticks += '<line class="v2-axis" x1="' + tx + '" y1="' + yAxis + '" x2="' + tx + '" y2="' + (yAxis + 5) + '"/>' +
        '<text class="v2-axis-text" x="' + tx + '" y="' + (yAxis + 17) + '" text-anchor="' + (t === 0 ? 'start' : (t === n ? 'end' : 'middle')) + '">' + R.number(bp) + '</text>';
    }

    var svg = '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + R.escape(insertions.length + ' insertions and ' + snps.length + ' SNP-trait associations placed along ' + (overview.name || 'the gene')) + '">' +
      '<text class="v2-lane-label" x="' + L + '" y="' + (yIns - 14) + '">Insertions · ' + insertions.length + '</text>' +
      '<text class="v2-lane-label" x="' + L + '" y="' + (yGene - 20) + '">' + R.escape(canonical ? canonical.id : (overview.name || 'Gene')) + '</text>' +
      (strandText ? '<text class="v2-strand" x="' + (W - Rm) + '" y="' + (yGene - 20) + '" text-anchor="end">' + R.escape(strandText) + '</text>' : '') +
      '<text class="v2-lane-label" x="' + L + '" y="' + (ySnp + 26) + '">SNP–trait associations · ' + snps.length + '</text>' +
      '<line class="v2-axis" x1="' + L + '" y1="' + yAxis + '" x2="' + (W - Rm) + '" y2="' + yAxis + '"/>' + ticks +
      gene + insHtml + snpHtml + '</svg>';

    var legend = '<ul class="v2-legend">' +
      '<li><span class="v2-swatch is-cds"></span>Coding sequence</li><li><span class="v2-swatch is-exon"></span>Untranslated exon</li>' +
      sources.map(function (s, i) { return '<li><span class="v2-swatch is-ins"' + (i % 2 ? ' style="background:var(--mgdb-orange)"' : '') + '></span>' + R.escape(s) + '</li>'; }).join('') +
      (snps.length ? '<li><span class="v2-swatch is-snp"></span>SNP with a trait association; a larger pin carries more traits</li>' : '') +
      '</ul>' +
      (outside ? statusLine(outside + ' position' + (outside === 1 ? ' lies' : 's lie') + ' beyond the drawn window and ' + (outside === 1 ? 'is' : 'are') + ' pinned at its edge.') : '') +
      statusLine('Positions are on ' + (overview.chromosome || 'the chromosome') + ' of ' + ((payload.data.attributes || {}).assembly || 'this assembly') + '; hover a mark for its name.');
    return '<div class="v2-vartrack">' + svg + '</div>' + legend;
  }

  function renderVariation(variation, overview, structure) {
    if (!variation) { return false; }
    var out = body('gene-record-variation');
    out.innerHTML = '';
    var insertions = variation.insertions || [];
    var snps = variation.snp_traits || [];
    var alleles = variation.alleles || [];
    if (!insertions.length && !snps.length && !alleles.length) { return false; }

    var sources = {}; insertions.forEach(function (i) { sources[i.source || 'unknown'] = true; });
    var traits = {}; snps.forEach(function (s) { traits[s.trait] = true; });
    var studies = {}; snps.forEach(function (s) { if (s.study && s.study.name) { studies[s.study.name] = true; } });
    var facts = R.facts([
      ['Insertions', insertions.length ? String(insertions.length) : '', Object.keys(sources).length ? 'from ' + Object.keys(sources).join(', ') : ''],
      ['SNP–trait associations', snps.length ? String(snps.length) : '',
        snps.length ? Object.keys(traits).length + ' trait' + (Object.keys(traits).length === 1 ? '' : 's') + (Object.keys(studies).length ? ', ' + Object.keys(studies).length + ' stud' + (Object.keys(studies).length === 1 ? 'y' : 'ies') : '') : ''],
      ['Alleles and variations', alleles.length ? String(alleles.length) : '', alleles.length ? 'curated on the classical gene' : '']
    ]);
    if (facts) { out.insertAdjacentHTML('beforeend', facts); }

    var track = variationTrack(overview, structure, variation);
    if (track) { out.insertAdjacentHTML('beforeend', blockHtml('Insertions and trait-associated SNPs along the gene', null, track)); }

    /* The rows behind the figure, in the section with the figure.

       This used to be three buttons that jumped to tables in other views --
       and the Alleles one jumped to `locus-<id>-alleles`, which is v2's
       per-locus id and does not exist in this layout, so it went nowhere. The
       figure plots insertions and SNPs along the gene and the alleles are what
       it is plotting; sending someone to another view to read them is the
       wrong trade even when the link works.

       Page sizes are small. These are here to be read against the figure, not
       to be the definitive listing -- that is still the Gene model view for
       insertions and SNPs, and Genetic information for alleles, each with its
       own filter, sort and full-size pager. */
    R.collection(out, {
      title: 'Insertions', items: insertions, filename: 'gene-insertions.tsv',
      columns: insertionColumns()
    });
    R.collection(out, {
      title: 'SNPs and traits', items: snps, filename: 'gene-snp-traits.tsv',
      columns: snpColumns()
    });
    R.collection(out, {
      title: 'Alleles and variations', items: alleles, filename: 'gene-alleles.tsv',
      columns: alleleColumns()
    });

    /* PanEffect predicts the effect of every possible amino-acid substitution
       in this protein, which is the same subject as the rows above. It is built
       for B73 v5 and the NAM founders only; the API returns null for anything
       else rather than a link to a gene the tool has never heard of. */
    var vTools = (overview && overview.tools) || {};
    if (vTools.paneffect) {
      out.insertAdjacentHTML('beforeend', blockHtml('Variant effects', null,
        '<p class="v2-lead">PanEffect scores every possible amino-acid substitution in this protein ' +
        'with a language model, across the maize pan-genome.</p>' +
        '<div class="mgdb-rec-linkrow"><a class="mgdb-button mgdb-button-secondary" href="' +
        R.escape(vTools.paneffect) + '" target="_blank" rel="noopener">Open in PanEffect</a></div>'));
    }
    return true;
  }

  function renderOrthologs(orthologs) {
    if (!orthologs) { return false; }
    var out = body('gene-record-orthologs');
    out.innerHTML = '';
    var rendered = false;
    var list = orthologs.orthologs || [];
    if (list.length) {
      /* A collection, not the hand-built tally this used to be. The tally
         grouped by species and then cut each group at six with "and N more",
         so on a gene with many calls the section showed a fraction of them and
         offered no way to see the rest. A collection is the same rows with the
         species as a sortable column, a filter, a pager and a TSV -- and the
         Table/Cards toggle the rest of the page uses, opening on Table. */
      R.collection(out, {
        title: 'Orthologs by species', items: list, filename: 'gene-orthologs-by-species.tsv',
        view: 'table', columns: orthologColumns()
      });
      out.insertAdjacentHTML('beforeend',
        statusLine('Calls made on this gene model and on the other members of its pan-gene, from ' +
          (list[0].analysis || 'the ortholog analysis') + '.'));
      rendered = true;
      if (state.views.indexOf('pan_gene') !== -1) {
        crosslink(out, 'The full ortholog table, with the member each call was made on, is in the Pan-gene view.', 'pg-orthologs', 'Open the table');
      }
    }
    rendered = renderPhylostrata(out, orthologs.phylostrata) || rendered;

    /* The phylogenetic tree, beside the other two ways this section answers
       "what is this gene related to": the ortholog calls and the phylostrata.
       Gramene builds it for the B73 annotations and the NAM assemblies; the API
       returns null otherwise. */
    var oTools = ((payload.data.sections.overview) || {}).tools || {};
    if (oTools.tree_browser) {
      out.insertAdjacentHTML('beforeend', blockHtml('Phylogenetic tree', null,
        '<p class="v2-lead">The Gramene Maize Tree Browser places this gene model in a gene-family ' +
        'tree across the maize pan-genome and its relatives.</p>' +
        '<div class="mgdb-rec-linkrow"><a class="mgdb-button mgdb-button-secondary" href="' +
        R.escape(oTools.tree_browser) + '" target="_blank" rel="noopener">Open the Maize Tree Browser</a></div>'));
      rendered = true;
    }
    return rendered;
  }

  function renderPhylostrata(out, ps) {
    if (!ps || !ps.image) { return false; }
    var block = document.createElement('div');
    block.className = 'mgdb-rec-block gene-record-phylostrata';
    /* No "1-14" badge: that is the scale of the figure, not a count of
       anything, and the figure's own caption explains the scale. */
    block.innerHTML = '<div class="mgdb-rec-block-head"><h3>Phylostrata</h3></div>' +
      '<figure class="gene-record-phylostrata-figure"><a href="' + R.escape(ps.image) + '" target="_blank" rel="noopener">' +
      '<img src="' + R.escape(ps.image) + '" alt="Phylostratigraphy of ' + R.escape(ps.gene_model) + ', showing the level at which each part of the protein is conserved" loading="lazy"></a>' +
      '<figcaption>' + R.escape(ps.description) + '</figcaption></figure>' +
      '<div class="mgdb-rec-linkrow"><a class="mgdb-button mgdb-button-secondary" href="' + R.escape(ps.details_url) + '" target="_blank" rel="noopener">Phylostrata details for ' + R.escape(ps.gene_model) + '</a>' +
      '<a class="mgdb-button mgdb-button-quiet" href="' + R.escape(ps.about_url) + '" target="_blank" rel="noopener">About the Phylostrata tool</a></div>';
    out.appendChild(block);
    qs('img', block).addEventListener('error', function () {
      if (block.parentNode) { block.parentNode.removeChild(block); }
      if (!out.children.length) {
        /* The bar is the server's and stays put; the section is marked empty
           instead of being taken out of it. */
        state.rendered['gene-record-orthologs'] = false;
        emptyState('gene-record-orthologs', 'none',
          'No orthologs have been recorded for ' + recordName() + '.');
        markBars((payload && payload.meta && payload.meta.counts) || {});
      }
    });
    return true;
  }

  function renderImages(locus) {
    var items = (locus && locus.images) || [];
    if (!items.length) { return false; }
    return R.images(body('gene-record-images'), items.map(function (image) {
      return { url: image.url, caption: image.caption || '', title: (image.variation && image.variation.name) || 'Image',
               category: image.variation_type || 'Image', record: (image.variation && image.variation.html) || '' };
    }), 'gene-record-image-dialog', { title: 'Mutant Phenotype Images', filename: 'gene-mutant-phenotype-images.tsv' });
  }

  function sequenceColumns() {
    return [
      { key: 'name', label: 'Transcript', tile: true,
        html: function (t) { return R.escape(t.name) + (t.canonical ? ' <span class="mgdb-pill mgdb-pill-ok">Canonical</span>' : ''); } },
      { key: 'cds', label: 'CDS', sort: false, get: function (t) { return t.cds || ''; }, html: function (t) { return t.cds ? R.link(t.cds, 'CDS', true) : '—'; } },
      { key: 'cdna', label: 'cDNA', sort: false, get: function (t) { return t.cdna || ''; }, html: function (t) { return t.cdna ? R.link(t.cdna, 'cDNA', true) : '—'; } },
      { key: 'protein_url', label: 'Protein', sort: false, get: function (t) { return t.protein_url || ''; },
        html: function (t) { return t.protein_url ? R.link(t.protein_url, t.protein || 'Protein', true) : '—'; } }
    ];
  }

  function renderSequences(seq, out) {
    if (!seq || !seq.set) { return false; }
    out.innerHTML = '';
    out.insertAdjacentHTML('beforeend', R.facts([
      ['Annotation set', R.escape(seq.set)],
      ['Assembly', seq.assembly ? R.escape(seq.assembly) : ''],
      ['Whole gene', seq.genomic ? R.link(seq.genomic, 'Genomic FASTA', true) : '', 'the model and its introns']
    ]));
    R.collection(out, { title: 'Transcript sequences', items: seq.transcripts, filename: 'gene-sequences.tsv', columns: sequenceColumns() });
    var downloads = (seq.downloads || []).filter(Boolean);
    if (downloads.length) {
      out.insertAdjacentHTML('beforeend', blockHtml('Bulk downloads', downloads.length,
        '<div class="mgdb-rec-linkrow">' + downloads.map(function (url) {
          return '<a class="mgdb-button mgdb-button-secondary" href="' + R.escape(url) + '" target="_blank" rel="noopener">Every sequence for this assembly</a>';
        }).join('') + '</div>'));
    }
    if (seq.note) { out.insertAdjacentHTML('beforeend', statusLine(seq.note)); }
    return true;
  }

  /* Gene model scores: the range view, as on the live page. */
  function fmtScore(v) {
    if (v == null || isNaN(v)) { return '—'; }
    var n = Number(v);
    if (n === 0 || n === 1 || n === 100) { return String(n); }
    if (Math.abs(n) >= 10) { return n.toFixed(1); }
    if (Math.abs(n) >= 1) { return n.toFixed(2); }
    if (Math.abs(n) < 0.001) { return '<0.001'; }
    return n.toPrecision(3).replace(/\.?0+$/, '');
  }
  function scoreStanding(s) {
    var r = s.range; if (!r || r.p5 == null) { return null; }
    var v = s.value;
    if (v >= r.p95) { return 'above 95% of scored features'; }
    if (v >= r.p75) { return 'in the top quarter'; }
    if (v >= r.p50) { return 'above the median'; }
    if (v >= r.p25) { return 'below the median'; }
    if (v >= r.p5) { return 'in the bottom quarter'; }
    return 'below 95% of scored features';
  }
  function scoreTone(s) {
    var r = s.range; if (!s.better || !r || r.p25 == null) { return 'neutral'; }
    var v = s.value;
    if (s.better === 'high') { return v >= r.p50 ? 'good' : (v < r.p25 ? 'poor' : 'mid'); }
    return v <= r.p50 ? 'good' : (v > r.p75 ? 'poor' : 'mid');
  }
  /* One transcript's scores: the block shows one at a time (scoreGroups), so
     the transcript is named once, above the cards, rather than on each. */
  function scoreRangeHtml(rows) {
    var withRange = rows.filter(function (s) { return s.range && s.range.n; });
    var n = withRange.length ? withRange[0].range.n : 0;
    return '<div class="gene-score-ranges">' + rows.map(function (s) {
      var lo = null, hi = null, src = null;
      if (s.range && s.range.min != null && s.range.max != null && s.range.max > s.range.min) { lo = s.range.min; hi = s.range.max; src = 'range'; }
      else if (s.scale) { lo = s.scale.min; hi = s.scale.max; src = 'scale'; }
      function pct(v) { return Math.max(0, Math.min(100, 100 * (v - lo) / (hi - lo))); }
      var tone = scoreTone(s), standing = scoreStanding(s), track = '';
      if (lo != null) {
        var r = s.range || {};
        var title = (s.label || s.metric) + ': ' + fmtScore(s.value) + ' on a ' + fmtScore(lo) + ' to ' + fmtScore(hi) + ' ' + (src === 'range' ? 'genome-wide range' : 'scale') + (standing ? ', ' + standing : '');
        track = '<div class="gene-score-track" role="img" aria-label="' + R.escape(title) + '" title="' + R.escape(title) + '">' +
          (src === 'range' && r.p5 != null ? '<span class="gene-score-band" style="left:' + pct(r.p5).toFixed(1) + '%;width:' + (pct(r.p95) - pct(r.p5)).toFixed(1) + '%" title="5th to 95th percentile"></span>' : '') +
          (src === 'range' && r.p50 != null ? '<span class="gene-score-median" style="left:' + pct(r.p50).toFixed(1) + '%" title="median ' + fmtScore(r.p50) + '"></span>' : '') +
          '<span class="gene-score-dot is-' + tone + '" style="left:' + pct(s.value).toFixed(1) + '%"></span></div>' +
          '<div class="gene-score-ends"><span>' + fmtScore(lo) + (src === 'range' ? ' <small>min</small>' : '') + '</span>' +
          (src === 'range' && r.p50 != null ? '<span class="gene-score-ends-mid"><small>median</small> ' + fmtScore(r.p50) + '</span>' : '') +
          '<span>' + fmtScore(hi) + (src === 'range' ? ' <small>max</small>' : '') + '</span></div>';
      } else {
        track = '<p class="mgdb-muted gene-score-noscale">No scale on file for this metric.</p>';
      }
      return '<div class="gene-score-row"><div class="gene-score-head"><span class="gene-score-label">' + R.escape(s.label || s.metric) + '</span>' +
        '<span class="gene-score-analysis">' + R.escape(s.analysis || '') + (s.version ? ' ' + R.escape(s.version) : '') + '</span>' +
        '<span class="gene-score-value is-' + tone + '">' + fmtScore(s.value) + '</span></div>' + track +
        '<p class="gene-score-note">' + R.escape(s.interpretation || '') + (standing ? ' <span class="gene-score-standing">' + R.escape(standing) + '</span>' : '') + '</p></div>';
    }).join('') + '</div>' +
    '<p class="mgdb-rec-block-status">' + (withRange.length
      ? 'Ranges, band (5th to 95th percentile) and median are over every feature the analysis scored, ' + R.number(n) + ' and up, across the annotations it was run on. A circle is green when the score sits on the better side of the median, gold between the median and the poorer quartile, wine beyond it; blue metrics describe the protein rather than judge the model.'
      : 'Tracks show each metric\'s own scale; genome-wide ranges are not on this host.') + '</p>';
  }
  function scoreColumns() {
    return [
      { key: 'label', label: 'Score', tile: true, get: function (s) { return s.label || s.metric; } },
      /* Which isoform the score is of -- in the TSV only. On screen the block
         shows one transcript at a time and names it above the rows, so the
         column would repeat one value down every row; the download carries
         every transcript, and there a column of AED values means nothing
         without it. */
      { key: 'feature', label: 'Transcript', tsvOnly: true, get: function (s) { return s.feature || ''; } },
      { key: 'analysis', label: 'Analysis', get: function (s) { return (s.analysis || '') + (s.version ? ' ' + s.version : ''); } },
      { key: 'value', label: 'Value', sort: 'number', numeric: true, get: function (s) { return fmtScore(s.value); } },
      { key: 'range', label: 'Genome-wide', sort: false,
        get: function (s) { return s.range && s.range.min != null ? fmtScore(s.range.min) + '–' + fmtScore(s.range.max) + ', median ' + fmtScore(s.range.p50) : (s.scale ? 'scale ' + s.scale.min + '–' + s.scale.max : ''); } },
      { key: 'interpretation', label: 'What it means', get: function (s) { var st = scoreStanding(s); return (s.interpretation || '') + (st ? ' (' + st + ')' : ''); } }
    ];
  }
  /* The scores come one transcript at a time, in place of pages of ten: every
     score of the open transcript in every view, the transcripts in name order
     (T001, T002, ...), opening on the canonical one -- which is not always
     T001: sh1's is T004, adh1's T002. Buttons carry the short name when every
     transcript shares the gene's prefix; the status line gives the full one. */
  function scoreGroups(scores) {
    var canonical = canonicalTranscript();
    var ids = [];
    scores.forEach(function (s) { if (s.feature && ids.indexOf(s.feature) === -1) { ids.push(s.feature); } });
    ids.sort(function (a, b) { return a.localeCompare(b, undefined, { numeric: true }); });
    var cut = ids.length ? ids[0].lastIndexOf('_') + 1 : 0;
    var prefix = cut > 0 ? ids[0].slice(0, cut) : '';
    var short = prefix !== '' && ids.every(function (id) { return id.indexOf(prefix) === 0 && id.length > cut; });
    return {
      label: 'Transcript',
      noun: ['score', 'scores'],
      key: function (s) { return s.feature; },
      current: canonical,
      sets: ids.map(function (id) {
        return { id: id, label: short ? id.slice(cut) : id, name: id,
                 note: id === canonical ? 'the canonical transcript' : '', marked: id === canonical };
      })
    };
  }
  function renderProvenance(structure) {
    var scores = (structure && structure.scores) || [];
    return R.collection(body('gene-record-provenance'), {
      title: 'Gene model scores', items: scores, filename: 'gene-model-scores.tsv', view: 'range',
      groups: scoreGroups(scores),
      views: [{ key: 'range', label: 'Range',
        icon: '<svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="1" y="7" width="14" height="2" rx="1" fill="currentColor"/><circle cx="10" cy="8" r="3.5" fill="currentColor"/></svg>',
        render: scoreRangeHtml }],
      columns: scoreColumns()
    });
  }

  function canonicalTranscript() {
    var st = payload && payload.data && payload.data.sections && payload.data.sections.structure;
    if (!st) { return null; }
    if (st.gene_model && st.gene_model.canonical_transcript) { return st.gene_model.canonical_transcript; }
    /* No gene-models release behind this annotation (B73 v4 and older), so no
       gene_model block -- but the transcript list still flags the canonical
       one, and without it the scores would open on T001 by default. */
    var flagged = (st.transcripts || []).filter(function (t) { return t.canonical; })[0];
    return flagged ? flagged.name : null;
  }

  /* Homeologs and tandem arrays: MGDB.geneParalogs draws it. A gene with a
     release but neither says which list it is not in, rather than the
     generic sentence; an assembly without a release (sections.paralogs is
     null) falls through to "not held for this build". */
  function renderParalogs(par, expression) {
    if (!par || !MGDB.geneParalogs) { return false; }
    if (!par.homeolog && !par.tandem) {
      state.emptyNote = state.emptyNote || {};
      state.emptyNote['gene-record-paralogs'] = recordName() + ' is not one of the ' + R.number(par.source.pairs_placed) +
        ' retained maize1/maize2 homeolog pairs placed on ' + R.escape(par.genome_label) + ' v5, and is not in any of its ' +
        R.number(par.source.tandem_arrays) + ' tandem arrays.';
      return false;
    }
    var attrs = payload.data.attributes || {};
    return MGDB.geneParalogs(body('gene-record-paralogs'), par, {
      gene: attrs.name, symbol: attrs.symbol, profile: expression && expression.profile
    });
  }

  function renderMetrics(counts) {
    R.metrics(R.byId('gene-record-metrics-body'), [
      ['Ontology terms', 'Function', counts.ontology, 'GO and other ontology terms attached to this gene.', 'green'],
      ['Insertions', 'Mutants', counts.insertions, 'Insertion alleles recorded in this gene.', 'amber'],
      ['SNP associations', 'Traits', counts.snp_traits, 'SNPs in this gene with a recorded trait association.', 'blue'],
      ['References', 'Literature', counts.references, 'Curated publications associated with this gene.', 'burgundy']
    ]);
    var series = [
      ['Transcripts', counts.transcripts], ['Protein domains', counts.protein_domains],
      ['Ontology terms', counts.ontology], ['Insertions', counts.insertions],
      ['SNP associations', counts.snp_traits], ['Alleles', counts.alleles],
      ['Map positions', counts.map_positions], ['Gene products', counts.gene_products],
      ['Stocks', counts.stocks], ['Images', counts.images],
      ['Cross-references', counts.xrefs], ['Pan-gene members', counts.pan_gene_members],
      ['Gene models of this gene', counts.locus_gene_models],
      ['Curator notes', counts.comments], ['References', counts.references]
    ];
    R.connectionsChart('gene-record-connections-chart', 'gene-record-connections-caption',
                       'gene-record-connections-figure', series, R.connectionsHeight(series));
    return true;
  }

  /* ========================================================================
     VIEW 2: Gene model, tables and lists only
     ======================================================================== */

  function transcriptColumns() {
    return [
      { key: 'name', label: 'Transcript', tile: true },
      { key: 'is_canonical', label: 'Canonical', get: function (t) { return t.canonical ? 'Yes' : 'No'; },
        html: function (t) { return t.canonical ? '<span class="mgdb-pill mgdb-pill-ok">Canonical</span>' : '<span class="mgdb-muted">&mdash;</span>'; } },
      { key: 'protein', label: 'Protein' },
      { key: 'model_type', label: 'Type', get: function (t) { return String(t.model_type || '').replace(/_/g, ' '); } },
      { key: 'position', label: 'Position', get: locationText, html: function (t) { var s = locationText(t); return s ? '<span class="mgdb-sequence">' + R.escape(s) + '</span>' : '—'; } },
      { key: 'span_bp', label: 'Span (bp)', sort: 'number', numeric: true, get: function (t) { return t.span_bp == null ? '' : R.number(t.span_bp); } },
      { key: 'exon_count', label: 'Exons', sort: 'number', numeric: true, get: function (t) { return t.exon_count == null ? '' : String(t.exon_count); } },
      { key: 'cds_length_nt', label: 'CDS (nt)', sort: 'number', numeric: true, get: function (t) { return t.cds_length_nt == null ? '' : R.number(t.cds_length_nt); } },
      { key: 'protein_length_aa', label: 'Protein (aa)', sort: 'number', numeric: true, get: function (t) { return t.protein_length_aa == null ? '' : R.number(t.protein_length_aa); } }
    ];
  }

  function transcriptRows(structure) {
    var blocks = {};
    ((structure.gene_model && structure.gene_model.transcripts) || []).forEach(function (t) { blocks[t.id] = t; });
    return (structure.transcripts || []).map(function (t) {
      var b = blocks[t.name];
      return b ? Object.assign({}, t, { exon_count: b.exon_count, cds_length_nt: b.cds_length_nt,
        protein_length_aa: b.protein ? b.protein.length_aa : null }) : t;
    });
  }

  function exonRows(structure) {
    var rows = [];
    ((structure.gene_model && structure.gene_model.transcripts) || structure.exon_structure || []).forEach(function (t) {
      (t.exons || []).forEach(function (e) {
        var coding = 0;
        (t.cds || []).forEach(function (c) {
          var lo = Math.max(e.start, c.start), hi = Math.min(e.end, c.end);
          if (hi >= lo) { coding += hi - lo + 1; }
        });
        rows.push({ transcript: t.id, canonical: !!t.canonical, rank: e.rank, start: e.start, end: e.end,
                    length: e.end - e.start + 1, coding: coding,
                    kind: coding === 0 ? 'Untranslated' : (coding === e.end - e.start + 1 ? 'Coding' : 'Part coding') });
      });
    });
    return rows;
  }

  function gmOverview(overview, structure, sections, counts) {
    var out = body('gm-overview');
    out.innerHTML = '';
    var attrs = payload.data.attributes || {};
    var asm = overview.assembly || {};
    var pan = (sections.pan_gene || {}).pan_gene;
    var protein = (structure && structure.protein) || {};
    var facts = R.facts([
      ['Gene model', overview.name ? '<span class="mgdb-record-id">' + R.escape(overview.name) + '</span>' : ''],
      ['Annotation set', overview.annotation && overview.annotation.name ? R.escape(overview.annotation.name) : (attrs.annotation ? R.escape(attrs.annotation) : '')],
      ['Assembly', asm.name ? (asm.html ? R.link(asm.html, asm.name) : R.escape(asm.name)) : '',
        [asm.accession, asm.provider, asm.date, asm.coverage ? asm.coverage + ' coverage' : ''].filter(Boolean).join(' · ')],
      ['Model type', overview.model_type ? R.escape(String(overview.model_type).replace(/_/g, ' ')) : ''],
      ['Location', overview.chromosome && overview.start != null ? R.escape(overview.chromosome) + ':' + R.number(overview.start) + '–' + R.number(overview.end) : '',
        overview.span_bp ? R.number(overview.span_bp) + ' bp' : ''],
      ['Strand', overview.strand ? (overview.strand === '-' ? 'Minus (−)' : 'Plus (+)') : '', overview.strand_note || ''],
      ['Transcripts', overview.transcript_count == null ? '' : String(overview.transcript_count), overview.canonical_transcript ? 'canonical ' + overview.canonical_transcript : ''],
      ['Canonical protein', protein.name ? R.escape(protein.name) : (overview.canonical_protein ? R.escape(overview.canonical_protein) : ''),
        protein.length_aa ? R.number(protein.length_aa) + ' aa' : ''],
      ['Current in its annotation', overview.is_current == null ? '' : (overview.is_current ? 'Yes' : 'No')],
      ['Reference gene model', overview.is_reference_gene_model == null ? '' : (overview.is_reference_gene_model ? 'Yes' : 'No')],
      ['Species', overview.species ? '<em>' + R.escape(overview.species) + '</em>' : '', overview.line ? 'line ' + overview.line : ''],
      ['Pan-gene', pan && pan.name ? R.link('/pan_gene_center/pan_gene/' + encodeURIComponent(pan.name), pan.name) : '<span class="mgdb-muted">Not placed in a pan-gene</span>',
        pan && pan.analysis ? pan.analysis : '']
    ]);
    if (facts) { out.insertAdjacentHTML('beforeend', facts); }

    R.collection(out, {
      title: 'Classical genes on this gene model', items: state.loci, filename: 'gene-classical-genes.tsv',
      columns: [
        { key: 'name', label: 'Symbol', tile: true, /* 'gn-overview', not 'locus-<id>-overview'. The genetic sections are
             fixed in this layout and describe the locus the header leads with,
             so every one of these jumps goes to the same place -- which is
             right: there is one set, and it is where a classical gene is
             described. The per-locus ids were v2's and resolve to nothing. */
          html: function (l) { return '<button class="v2-jump" type="button" data-jump="gn-overview">' + R.escape(l.name) + '</button>'; } },
        { key: 'full_name', label: 'Full name' },
        { key: 'type', label: 'Type' },
        { key: 'id', label: 'MaizeGDB ID', sort: 'number', numeric: true, get: function (l) { return String(l.id); } },
        R.urlColumn(function (l) { return l.html; })
      ]
    });

    if (structure) {
      R.collection(out, { title: 'Transcripts', items: transcriptRows(structure), filename: 'gene-transcripts.tsv', columns: transcriptColumns() });
      R.collection(out, {
        title: 'Exon coordinates', items: exonRows(structure), filename: 'gene-exons.tsv',
        columns: [
          { key: 'transcript', label: 'Transcript', tile: true, html: function (e) { return R.escape(e.transcript) + (e.canonical ? ' <span class="mgdb-pill mgdb-pill-ok">Canonical</span>' : ''); } },
          { key: 'rank', label: 'Exon', sort: 'number', numeric: true, get: function (e) { return String(e.rank); } },
          { key: 'start', label: 'Start', sort: 'number', numeric: true, get: function (e) { return R.number(e.start); } },
          { key: 'end', label: 'End', sort: 'number', numeric: true, get: function (e) { return R.number(e.end); } },
          { key: 'length', label: 'Length (bp)', sort: 'number', numeric: true, get: function (e) { return R.number(e.length); } },
          { key: 'coding', label: 'Coding (bp)', sort: 'number', numeric: true, get: function (e) { return R.number(e.coding); } },
          { key: 'kind', label: 'Kind' }
        ]
      });
    }

    var browser = overview.browser;
    var links = [];
    if (browser && browser.url) { links.push('<a class="mgdb-button mgdb-button-secondary" href="' + R.escape(browser.url) + '" target="_blank" rel="noopener">Open in ' + R.escape(browser.label) + '</a>'); }
    if (structure && structure.gene_model && structure.gene_model.links) {
      var gl = structure.gene_model.links;
      if (gl.gff3) { links.push('<a class="mgdb-button mgdb-button-quiet" href="' + R.escape(gl.gff3) + '" target="_blank" rel="noopener">GFF3</a>'); }
      if (gl.bed) { links.push('<a class="mgdb-button mgdb-button-quiet" href="' + R.escape(gl.bed) + '" target="_blank" rel="noopener">BED</a>'); }
    }
    /* MaizeMine takes the gene model by name and is only built for three
       assemblies, so the API returns null rather than a link that would land on
       "no results" -- see gene_api_tools(). */
    var tools = overview.tools || {};
    if (tools.maizemine) {
      links.push('<a class="mgdb-button mgdb-button-quiet" href="' + R.escape(tools.maizemine) +
        '" target="_blank" rel="noopener">MaizeMine</a>');
    }
    if (links.length) { out.insertAdjacentHTML('beforeend', blockHtml('Genome browser and model files', null, '<div class="mgdb-rec-linkrow">' + links.join('') + '</div>')); }

    /* The embedded browser was in v2's visual Overview, which this layout does
       not have; the button above it is not a substitute for seeing the region,
       so the frame comes here with it. GBrowse assemblies cannot be framed and
       say so rather than showing an empty box. */
    if (browser && browser.url) {
      out.insertAdjacentHTML('beforeend', blockHtml('Genome browser', null,
        '<p class="mgdb-rec-block-status">' + R.escape(browser.location) + ', the gene model with 1,500 bp either side.</p>' +
        (browser.embed_url
          ? '<iframe class="gene-record-browser" src="' + R.escape(browser.embed_url) + '" title="' +
            R.escape(browser.label + ' view of ' + (overview.name || 'this gene')) + '" loading="lazy"></iframe>'
          : '<p class="mgdb-rec-empty">This assembly is served by GBrowse, which cannot be embedded. Use the link above.</p>'),
        '<a class="mgdb-rec-tsv" href="' + R.escape(browser.url) + '" target="_blank" rel="noopener">Open in ' + R.escape(browser.label) + '</a>'));
    }
    return true;
  }

  function gmAnnotations(fn, structure) {
    var out = body('gm-annotations');
    out.innerHTML = '';
    var rendered = false;
    fn = fn || {};
    structure = structure || {};

    rendered = R.collection(out, { title: 'Ontology terms', items: fn.ontology, filename: 'gene-ontology-terms.tsv', columns: ontologyColumns() }) || rendered;

    rendered = R.collection(out, {
      title: 'Protein domains', items: structure.protein_domains, filename: 'gene-protein-domains.tsv',
      columns: [
        { key: 'name', label: 'Domain', tile: true, html: function (d) { return d.url ? R.link(d.url, d.name, true) : R.escape(d.name); } },
        { key: 'accession', label: 'Accession' },
        { key: 'start', label: 'Start', sort: 'number', numeric: true, get: function (d) { return d.start == null ? '' : String(d.start); } },
        { key: 'end', label: 'End', sort: 'number', numeric: true, get: function (d) { return d.end == null ? '' : String(d.end); } },
        { key: 'analysis', label: 'Analysis' },
        { key: 'evalue', label: 'E-value', sort: 'number', numeric: true, get: function (d) { return d.evalue == null ? '' : String(d.evalue); } },
        { key: 'entry', label: 'InterPro', html: function (d) { return d.entry ? R.link('https://www.ebi.ac.uk/interpro/entry/InterPro/' + encodeURIComponent(d.entry) + '/', d.entry, true) : '<span class="mgdb-muted">&mdash;</span>'; } },
        { key: 'transcript', label: 'Transcript' }
      ]
    }) || rendered;

    var dom = structure.domains || {};
    if (dom.sites && dom.sites.length) {
      rendered = R.collection(out, {
        title: 'Residue-level sites on ' + (dom.id || 'the canonical protein'), items: dom.sites, filename: 'gene-protein-sites.tsv',
        columns: [
          { key: 'description', label: 'Site', tile: true },
          { key: 'residue', label: 'Residue' },
          { key: 'start', label: 'Position', sort: 'number', numeric: true, get: function (s) { return s.start === s.end ? String(s.start) : s.start + '–' + s.end; } },
          { key: 'analysis', label: 'Analysis' },
          { key: 'accession', label: 'Signature' },
          { key: 'signature_start', label: 'Signature span', get: function (s) { return s.signature_start + '–' + s.signature_end; } }
        ]
      }) || rendered;
    }

    rendered = R.collection(out, {
      title: 'Protein accessions', items: fn.protein_accessions, filename: 'gene-protein-accessions.tsv',
      columns: [
        { key: 'accession', label: 'Accession', tile: true, html: function (a) { return a.url ? R.link(a.url, a.accession, true) : R.escape(a.accession); } },
        { key: 'database', label: 'Database' },
        { key: 'analysis', label: 'Analysis' },
        { key: 'description', label: 'Description' }
      ]
    }) || rendered;

    rendered = R.collection(out, {
      title: 'Gene products', items: fn.gene_products, filename: 'gene-products.tsv',
      columns: [
        { key: 'name', label: 'Gene product', tile: true, html: function (g) { return g.html ? R.link(g.html, g.name) : R.escape(g.name); } },
        { key: 'type', label: 'Type' },
        { key: 'evidence', label: 'Evidence' },
        { key: 'comments', label: 'Comments' }
      ]
    }) || rendered;

    rendered = R.collection(out, {
      title: 'Gene model scores', items: structure.scores, filename: 'gene-model-scores.tsv',
      groups: scoreGroups(structure.scores || []), columns: scoreColumns()
    }) || rendered;

    /* Gramene's Ensembl views, grouped the way the production record page
       groups them under Annotations & Scores: the gene, its orthologues and
       paralogues, the gene tree and the variant table. B73 annotations only --
       Gramene's Zea_mays is B73, and a NAM founder id lands on an empty
       Summary page rather than a gene. */
    var gr = ((payload.data.sections.overview) || {}).tools;
    gr = gr && gr.gramene;
    if (gr) {
      out.insertAdjacentHTML('beforeend', blockHtml('Gramene', null,
        '<p class="v2-lead">This gene model in Gramene\u2019s Ensembl browser.</p>' +
        '<div class="mgdb-rec-linkrow">' +
        '<a class="mgdb-button mgdb-button-secondary" href="' + R.escape(gr.gene) + '" target="_blank" rel="noopener">Gene</a>' +
        '<a class="mgdb-button mgdb-button-quiet" href="' + R.escape(gr.orthologs) + '" target="_blank" rel="noopener">Orthologues</a>' +
        '<a class="mgdb-button mgdb-button-quiet" href="' + R.escape(gr.paralogs) + '" target="_blank" rel="noopener">Paralogues</a>' +
        '<a class="mgdb-button mgdb-button-quiet" href="' + R.escape(gr.tree) + '" target="_blank" rel="noopener">Gene tree</a>' +
        '<a class="mgdb-button mgdb-button-quiet" href="' + R.escape(gr.variation) + '" target="_blank" rel="noopener">Variant table</a>' +
        '</div>'));
      rendered = true;
    }
    return rendered;
  }

  function alleleColumns() {
    return [
      { key: 'name', label: 'Allele', tile: true, html: function (a) { return a.html ? R.link(a.html, a.name) : R.link('/data_center/variation?id=' + a.id, a.name); } },
      { key: 'type', label: 'Type' },
      { key: 'id', label: 'MaizeGDB ID', sort: 'number', numeric: true, get: function (a) { return a.id == null ? '' : String(a.id); } }
    ];
  }

  function insertionColumns() {
    return [
      { key: 'name', label: 'Insertion', tile: true, html: function (i) { var v = (i.variations || [])[0]; return v && v.html ? R.link(v.html, i.name) : R.escape(i.name); } },
      { key: 'source', label: 'Source' },
      { key: 'gene_structures', label: 'Gene structure', get: function (i) { return i.gene_structures || ''; } },
      { key: 'position', label: 'Position', get: locationText, html: function (i) { var t = locationText(i); return t ? '<span class="mgdb-sequence">' + R.escape(t) + '</span>' : '<span class="mgdb-muted">Not recorded</span>'; } },
      { key: 'transcripts', label: 'Transcript' },
      { key: 'stocks', label: 'Stock', get: function (i) { return refNames(i.stocks); }, html: function (i) { return refLinks(i.stocks); } }
    ];
  }

  function gmInsertions(variation) {
    var out = body('gm-insertions');
    out.innerHTML = '';
    var ok = R.collection(out, { title: 'Insertions', items: (variation || {}).insertions, filename: 'gene-insertions.tsv', columns: insertionColumns() });
    if (ok) {
      out.insertAdjacentHTML('beforeend', '<div class="mgdb-rec-linkrow"><a class="mgdb-button mgdb-button-quiet" href="/insertion">Insertion Data Hub</a>' +
        '<a class="mgdb-button mgdb-button-quiet" href="/uniformmu">UniformMu resource</a></div>');
    }
    return ok;
  }

  function gmExpression(expression) {
    var out = body('gm-expression');
    out.innerHTML = '';
    if (!expression) { return false; }
    var rendered = false;
    var profile = expression.profile;
    var summary = profile && profile.sections && profile.sections.summary;
    var rna = summary && summary.rna;
    if (rna) {
      var factsHtml = R.facts([
        ['Release', profile.attributes && profile.attributes.release ? R.escape(profile.attributes.release) : ''],
        ['RNA samples', rna.samples != null ? R.number(rna.samples) : '', rna.samples_with_value != null ? R.number(rna.samples_with_value) + ' with a value' : ''],
        ['Detected in', rna.detected != null ? R.number(rna.detected) + ' samples' : '', rna.detected_rule ? rna.detected_rule : ''],
        ['Mean', rna.mean != null ? fmtScore(rna.mean) : '', rna.median != null ? 'median ' + fmtScore(rna.median) : ''],
        ['Highest', rna.max != null ? fmtScore(rna.max) : '', rna.max_sample ? rna.max_sample.sample + ' (' + rna.max_sample.source + ')' : ''],
        ['Tissue specificity', rna.tau != null ? 'tau ' + fmtScore(rna.tau) : '', rna.specificity || ''],
        ['Studies', profile.attributes && profile.attributes.source_count != null ? R.number(profile.attributes.source_count) : '']
      ]);
      if (factsHtml) { out.insertAdjacentHTML('beforeend', factsHtml); rendered = true; }
    }
    if (profile && profile.sections) {
      rendered = R.collection(out, {
        title: 'Samples', items: profile.sections.samples || [], filename: 'gene-expression.tsv',
        columns: [
          { key: 'label', label: 'Sample', tile: true },
          { key: 'source', label: 'Study' },
          { key: 'tissue', label: 'Tissue reading' },
          { key: 'condition', label: 'Condition', get: function (s) { return s.condition || ''; } },
          { key: 'assay', label: 'Assay', get: function (s) { return s.assay === 'rna' ? 'RNA' : s.assay; } },
          { key: 'value', label: 'Value', sort: 'number', numeric: true, get: function (s) { return s.value == null ? '' : String(s.value); } }
        ]
      }) || rendered;
      rendered = R.collection(out, {
        title: 'Studies', items: profile.sections.sources || [], filename: 'gene-expression-studies.tsv',
        columns: [
          { key: 'name', label: 'Study', tile: true, html: function (s) { return s.link ? R.link(s.link, s.name, true) : R.escape(s.name); } },
          { key: 'assay', label: 'Assay', get: function (s) { return s.assay === 'rna' ? 'RNA' : s.assay; } },
          { key: 'sample_count', label: 'Samples', sort: 'number', numeric: true, get: function (s) { return s.sample_count == null ? '' : String(s.sample_count); } },
          { key: 'stress', label: 'Stress study', get: function (s) { return s.stress ? 'Yes' : 'No'; } },
          { key: 'description', label: 'Notes' }
        ]
      }) || rendered;
    } else if (expression.profile_note) {
      out.insertAdjacentHTML('beforeend', statusLine(expression.profile_note));
      rendered = true;
    }
    if (expression.efp && expression.efp.available && expression.efp.atlases && expression.efp.atlases.length) {
      rendered = R.collection(out, {
        title: 'eFP Browser atlases', items: expression.efp.atlases, filename: 'gene-efp-atlases.tsv',
        columns: [
          { key: 'label', label: 'Atlas', tile: true },
          { key: 'key', label: 'Data source' },
          { key: 'image', label: 'Absolute', sort: false, get: function (a) { return a.image; }, html: function (a) { return R.link(a.image, 'Image', true); } },
          { key: 'image_relative', label: 'Relative', sort: false, get: function (a) { return a.image_relative; }, html: function (a) { return R.link(a.image_relative, 'Image', true); } },
          { key: 'browser', label: 'Browser', sort: false, get: function (a) { return a.browser; }, html: function (a) { return R.link(a.browser, 'Open at the BAR', true); } }
        ]
      }) || rendered;
    }
    var links = [];
    if (expression.qteller && expression.qteller.available) { links.push('<a class="mgdb-button mgdb-button-secondary" href="' + R.escape(expression.qteller.url) + '" target="_blank" rel="noopener">qTeller expression atlas</a>'); }
    if (expression.efp && expression.efp.eplant) { links.push('<a class="mgdb-button mgdb-button-quiet" href="' + R.escape(expression.efp.eplant) + '" target="_blank" rel="noopener">ePlant</a>'); }
    if (links.length) { out.insertAdjacentHTML('beforeend', blockHtml('Expression tools', null, '<div class="mgdb-rec-linkrow">' + links.join('') + '</div>')); rendered = true; }
    var gaps = expressionGaps(expression);
    if (gaps.length) { R.notes(out, 'Not available for this gene', gaps.map(function (t) { return { text: t }; })); rendered = true; }
    return rendered;
  }

  function snpColumns() {
    return [
      { key: 'snp', label: 'SNP', tile: true },
      { key: 'trait', label: 'Trait', html: function (t) { return '<span title="' + R.escape(t.trait_description || '') + '">' + R.escape(t.trait) + '</span>'; } },
      { key: 'gene_structure', label: 'Structure', get: function (t) { return t.gene_structure || ''; },
        html: function (t) { return t.gene_structure ? '<span title="' + R.escape(t.structure_description || '') + '">' + R.escape(t.gene_structure) + '</span>' : '<span class="mgdb-muted">—</span>'; } },
      { key: 'position', label: 'Position', sort: 'number', numeric: true, get: function (t) { return t.position == null ? '' : R.number(t.position); } },
      { key: 'transcript', label: 'Transcript' },
      { key: 'study', label: 'Study', tsvOnly: true, get: function (t) { return t.study && t.study.name ? t.study.name : ''; } }
    ];
  }

  function gmSnps(variation) {
    var out = body('gm-snps');
    out.innerHTML = '';
    return R.collection(out, { title: 'SNPs and traits', items: (variation || {}).snp_traits, filename: 'gene-snp-traits.tsv', columns: snpColumns() });
  }

  function gmProteomics(expression) {
    var out = body('gm-proteomics');
    out.innerHTML = '';
    var p = expression && expression.proteomics;
    if (!p) { return false; }
    if (p.available) {
      out.insertAdjacentHTML('beforeend', '<div class="mgdb-rec-linkrow"><a class="mgdb-button mgdb-button-secondary" href="' + R.escape(p.url || '#') + '">Open the proteomics data</a></div>');
      return true;
    }
    if (!p.reason) { return false; }
    R.notes(out, 'Proteomics', [{ text: p.reason, meta: ['Protein abundance and phosphorylation from Walley et al. 2016 were mapped to B73 RefGen_v3 gene models; the pan-gene view lists any related model that carries them.'] }]);
    return true;
  }

  function gmXrefs(xrefs, target) {
    var out = target || body('gn-external');
    if (!out) { return false; }
    out.innerHTML = '';
    return R.collection(out, { title: 'Cross-references', items: xrefs, filename: 'gene-cross-references.tsv', columns: xrefColumns() });
  }

  function xrefColumns() {
    return [
      { key: 'key', label: 'Accession', tile: true, html: function (x) { return x.url ? R.link(x.url, x.key || x.accession, true) : R.escape(x.key || x.accession); }, get: function (x) { return x.key || x.accession || ''; } },
      { key: 'database', label: 'Database' },
      { key: 'comment', label: 'Comment', get: function (x) { return x.comment || x.description || ''; } },
      { key: 'url', label: 'URL', sort: false, get: function (x) { return x.url || ''; }, html: function (x) { return x.url ? R.link(x.url, x.url, true) : '—'; } }
    ];
  }

  /* ========================================================================
     VIEW 3: Pan-gene
     ======================================================================== */

  /* pgOverview() was here. Its blocks now live in pgMembers() -- the identity,
     the Present in strip, the Pangenome view and the analysis block -- so the
     function was dead code carrying a second element with id
     "pg-analysis-block", which renderPgAnalysis() looks up by id.

     The one block not carried over is the BRIDGEcereal structural-variation
     panel, which is on the production record page and has no home in this
     layout yet. Its markup is still in js/mgdb-gene-record-v2.js. */


  /* Related gene models opens with what the pan-gene IS, and with the picture
     of it.

     Both were in the Pan-gene view's own Overview section, which this layout
     does not have. The identity is four facts and a link to the pan-gene
     record; without it the members table is a list of gene models with nothing
     saying what groups them. The Pangenome view is the only image MaizeGDB
     holds of the region across assemblies and is on the current record page,
     so it could not simply go. */
  function pgMembers(pan) {
    var out = body('pg-members');
    out.innerHTML = '';
    var pg = pan.pan_gene || {};

    out.insertAdjacentHTML('beforeend', '<p class="v2-lead">A <strong>pan-gene</strong> is the set of gene models from ' +
      'multiple genomes that appear to represent the same gene: grouped by sequence similarity and syntenic position ' +
      'across the annotations in the analysis.</p>');
    /* Named by its exemplar gene model, not by pan-zea.v4.pan02070. The
       internal id is the primary key of the analysis and means nothing to a
       reader; the exemplar is a gene model they can look up. The link still
       goes to the pan-gene record, which is addressed by the internal id -- so
       the id is in the href and out of the sentence.

       The exemplar is stored as a transcript (Zm00023ab070050_T001, and the
       suffix is not always _T001), so the gene model is that with the
       transcript suffix removed, and the transcript stays as the note. */
    var exemplarTx = pg.exemplar ? String(pg.exemplar) : '';
    var exemplarGene = exemplarTx.replace(/_T\d+$/, '');
    var panLabel = exemplarGene || pg.name || '';

    out.insertAdjacentHTML('beforeend', R.facts([
      ['Pan-gene', panLabel && pg.name
        ? R.link('/pan_gene_center/pan_gene/' + encodeURIComponent(pg.name), panLabel)
        : R.escape(panLabel),
        exemplarTx ? 'exemplar of the group, transcript ' + exemplarTx : 'opens the pan-gene record'],
      ['Members', num(pg.member_count), 'gene models across all assemblies'],
      ['Assemblies', num(pan.assembly_count), 'genomes where this gene was found'],
      ['Zea species', pan.species && pan.species.length ? String(pan.species.length) : '',
        pan.species ? pan.species.map(function (sp) { return sp.species; }).join(', ') : ''],
      ['Chromosome', pg.chromosome ? R.escape(pg.chromosome) : ''],
      ['Analysis', pg.analysis ? R.escape(pg.analysis) : '']
    ]));

    /* One square per assembly the gene was found in, grouped by species: 64 of
       them read as a shape where a list of 64 assembly names does not. */
    if (pan.species && pan.species.length) {
      var strip = pan.species.map(function (group) {
        return '<div class="gene-record-species"><h4><em>' + R.escape(group.species) + '</em> <span class="gene-record-muted">' + group.count + '</span></h4>' +
          '<ul class="gene-record-presence">' + group.assemblies.map(function (a) {
            return '<li title="' + R.escape(a) + '"><span class="mgdb-visually-hidden">' + R.escape(a) + '</span></li>';
          }).join('') + '</ul></div>';
      }).join('');
      out.insertAdjacentHTML('beforeend', blockHtml('Present in', num(pan.assembly_count),
        statusLine('One square per assembly in which this gene was found, grouped by species. Hover a square for the assembly name.') + strip));
    }

    /* The image is served from images.maizegdb.org and is not generated for
       every gene model, so a missing file removes its own block rather than
       leaving a broken frame in the middle of the section. */
    if (pan.pangenome_image && pan.pangenome_image.url) {
      var pv = pan.pangenome_image;
      var pvBlock = document.createElement('div');
      pvBlock.className = 'mgdb-rec-block gene-record-pangenome';
      /* The gene model id was in the count badge here. It is not a count, and
         on a page that is entirely about that gene model it was not news
         either. The figure's own caption still names what it is of. */
      pvBlock.innerHTML = '<div class="mgdb-rec-block-head"><h3>Pangenome view</h3></div>' +
        '<figure class="gene-record-pangenome-figure"><a href="' + R.escape(pv.url) + '" target="_blank" rel="noopener">' +
        '<img src="' + R.escape(pv.url) + '" loading="lazy" alt="Genomic sequence at ' + R.escape(pv.gene_model) + ' across multiple maize assemblies, drawn as a pangenome subgraph"></a>' +
        '<figcaption>' + R.escape(pv.description) + ' Produced by the ' + R.link(pv.pipeline_url, pv.pipeline, true) + ' at MaizeGDB in 2026.</figcaption></figure>';
      out.appendChild(pvBlock);
      qs('img', pvBlock).addEventListener('error', function () { if (pvBlock.parentNode) { pvBlock.parentNode.removeChild(pvBlock); } });
    }

    var ok = R.collection(out, {
      title: 'Gene models in this pan-gene', items: pan.members, filename: 'gene-pan-gene-members.tsv',
      columns: [
        { key: 'name', label: 'Gene model', tile: true,
          html: function (m) { return (m.html ? R.link(m.html, m.name) : R.escape(m.name)) + (m.is_current_record ? ' <span class="mgdb-pill mgdb-pill-ok">This record</span>' : ''); } },
        { key: 'transcript', label: 'Transcript' },
        { key: 'annotation', label: 'Annotation set' },
        { key: 'chromosome', label: 'Chromosome', get: function (m) { return m.chromosome || 'n/a'; } },
        { key: 'position', label: 'Position', get: function (m) { return m.start != null ? R.number(m.start) + '–' + R.number(m.end) : ''; },
          html: function (m) { return m.start != null ? '<span class="mgdb-sequence">' + R.number(m.start) + '–' + R.number(m.end) + '</span>' : '<span class="mgdb-muted">Not in the database</span>'; } },
        { key: 'assembly', label: 'Assembly', html: function (m) { return m.assembly ? R.link('/genome/genome_assembly/' + encodeURIComponent(m.assembly), m.assembly) : '—'; } }
      ]
    });
    if (ok) { out.insertAdjacentHTML('beforeend', statusLine('A chromosome of n/a means the gene model itself is not in the MaizeGDB database, so its position cannot be found.')); }

    /* Provenance, at the foot: which run produced this grouping. Filled by
       renderPgAnalysis() when loadPanExtra() answers -- the description lives
       on the pan-gene record, not on this one -- so the block is emitted now
       with its own loading line. */
    out.insertAdjacentHTML('beforeend', '<div class="mgdb-rec-block" id="pg-analysis-block">'
      + '<div class="mgdb-rec-block-head"><h3>About the analysis</h3></div>'
      + '<p class="mgdb-rec-block-status" data-role="analysis-status">The analysis description loads with the member datasets.</p>'
      + '<div data-role="analysis"></div></div>');

    return ok;
  }

  /* One column set for both places orthologs are listed: here and the
     Orthologs by species block in the visual view. */
  function orthologColumns() {
    return [
        { key: 'identifier', label: 'Gene', tile: true, html: function (o) { return o.url ? R.link(o.url, o.identifier, true) : R.escape(o.identifier); } },
        { key: 'species', label: 'Species', html: function (o) { return o.species ? '<em>' + R.escape(o.species) + '</em>' : '<span class="mgdb-muted">—</span>'; } },
        { key: 'kind', label: 'Relationship', get: function (o) { return String(o.kind || '').replace(/_/g, ' '); } },
        { key: 'analysis', label: 'Analysis' },
        { key: 'via', label: 'Called on', get: function (o) { return o.is_direct ? 'This gene model' : (o.via || ''); },
          html: function (o) { if (o.is_direct) { return '<span class="mgdb-pill mgdb-pill-ok">This gene model</span>'; }
            return o.via_html ? R.link(o.via_html, o.via) + ' <span class="mgdb-muted">(pan-gene)</span>' : R.escape(o.via || ''); } }
    ];
  }

  function pgOrthologs(orthologs) {
    var out = body('pg-orthologs');
    out.innerHTML = '';
    return R.collection(out, {
      title: 'Orthologs in other species', items: (orthologs || {}).orthologs,
      filename: 'gene-orthologs.tsv', columns: orthologColumns()
    });
  }

  /* The second request: the pan-gene record's own sections, for the member
     dataset matrix, the analysis description and the comparative viewers.
     Made only when the Pan-gene view is opened. */
  function loadPanExtra() {
    var pan = payload.data.sections.pan_gene;
    if (!pan || !pan.pan_gene || !pan.pan_gene.name) { return; }
    state.panLoaded = 'loading';
    var ds = body('pg-datasets');
    ds.innerHTML = '<div class="mgdb-loading"><span class="mgdb-spinner" aria-hidden="true"></span><span>Reading the data attached to the other members&hellip;</span></div>';
    state.rendered['pg-datasets'] = true;
    markBars((payload && payload.meta && payload.meta.counts) || {});
    MGDB.request('/api/v1/records/pan_gene/' + encodeURIComponent(pan.pan_gene.name) +
      '?fields=analysis,expression,insertions,traits,proteins,domains,function,viewers,downloads', { key: 'pan-extra' })
      .then(function (response) {
        state.panLoaded = 'done';
        var s = (response.data || {}).sections || {};
        renderPgAnalysis(s.analysis);
        state.rendered['pg-datasets'] = pgDatasets(pan, s);
        state.rendered['pg-viewers'] = pgViewers(s.viewers, s.downloads);
        markBars((payload && payload.meta && payload.meta.counts) || {});
        if (state.view === 'pan_gene') { showView('pan_gene', { keepUrl: true }); }
      })
      .catch(function (error) {
        if (error && error.name === 'AbortError') { return; }
        state.panLoaded = 'failed';
        ds.innerHTML = '<div class="mgdb-message mgdb-message-warn" role="note"><div><strong>The pan-gene record could not be read.</strong><span> The members and orthologs above come from this gene’s own record; the data attached to the other members needs the pan-gene record, which did not answer.</span></div></div>';
        var st = qs('[data-role="analysis-status"]', R.byId('pg-analysis-block'));
        if (st) { st.textContent = 'The analysis description could not be read.'; }
      });
  }

  function renderPgAnalysis(analysis) {
    var block = R.byId('pg-analysis-block');
    if (!block) { return; }
    var st = qs('[data-role="analysis-status"]', block);
    var host = qs('[data-role="analysis"]', block);
    if (!analysis) { if (st) { st.textContent = 'No analysis description is on file.'; } return; }
    if (st) { st.remove(); }
    host.innerHTML = '<dl class="mgdb-rec-facts v2-facts-wide">' +
      R.fact('Description', analysis.description ? R.escape(analysis.description) : '') + '</dl>' +
      R.facts([
        ['Program', analysis.program ? R.escape(analysis.program) + (analysis.program_version ? ' ' + R.escape(analysis.program_version) : '') : '', analysis.source_uri ? analysis.source_uri : ''],
        ['Run', analysis.executed ? R.escape(analysis.executed) : '', analysis.source ? 'by ' + analysis.source : ''],
        ['Annotations included', analysis.annotations ? String(analysis.annotations.length) : ''],
        ['Download', analysis.download_url ? R.link(analysis.download_url, 'Full pan-gene analysis', true) : '']
      ]);
  }

  function pgDatasets(pan, s) {
    var out = body('pg-datasets');
    out.innerHTML = '';
    var members = pan.members || [];
    if (!members.length) { return false; }
    var expr = {}, ins = {}, traits = {}, prot = {}, structs = {}, domains = {}, fnTerms = {};
    (s.expression || []).forEach(function (e) { expr[e.gene_model] = e.url; });
    (s.insertions || []).forEach(function (i) { ins[i.gene_model] = (ins[i.gene_model] || 0) + 1; });
    (s.traits || []).forEach(function (t) { traits[t.gene_model] = (traits[t.gene_model] || 0) + 1; });
    (((s.proteins || {}).proteomics) || []).forEach(function (p) { prot[p.gene_model] = p.reference || 'yes'; });
    (((s.proteins || {}).structures) || []).forEach(function (p) { structs[p.gene_model] = p.html; });
    (((s.domains || {}).members) || []).forEach(function (d) { domains[d.gene_model] = d.domain_string || ''; });
    (s.function || []).forEach(function (f) {
      String(f.gene_models || '').split(/,\s*/).forEach(function (g) { if (g) { fnTerms[g] = (fnTerms[g] || 0) + 1; } });
    });

    function count(map) { return Object.keys(map).filter(function (k) { return members.some(function (m) { return m.name === k; }); }).length; }
    var facts = R.facts([
      ['Expression', count(expr) ? count(expr) + ' members' : '', 'with a qTeller profile'],
      ['Insertions', (s.insertions || []).length ? String((s.insertions || []).length) : '', count(ins) ? 'on ' + count(ins) + ' members' : ''],
      ['SNP–trait associations', (s.traits || []).length ? String((s.traits || []).length) : '', count(traits) ? 'on ' + count(traits) + ' members' : ''],
      ['Proteomics', count(prot) ? count(prot) + ' members' : '', 'Walley et al. 2016, B73 RefGen_v3'],
      ['Protein structures', count(structs) ? count(structs) + ' members' : ''],
      ['Ontology terms', (s.function || []).length ? String((s.function || []).length) : '', 'distinct terms across the members']
    ]);
    if (facts) { out.insertAdjacentHTML('beforeend', facts); }

    function tick(v, title) {
      return v ? '<span class="v2-mark" title="' + R.escape(title || '') + '">' + (typeof v === 'number' ? v : '✓') + '</span>' : '<span class="v2-mark is-none" aria-label="none">·</span>';
    }
    R.collection(out, {
      title: 'Which members carry which data', items: members, filename: 'gene-pan-gene-member-datasets.tsv',
      columns: [
        { key: 'name', label: 'Gene model', tile: true,
          html: function (m) { return (m.html ? R.link(m.html, m.name) : R.escape(m.name)) + (m.is_current_record ? ' <span class="mgdb-pill mgdb-pill-ok">This record</span>' : ''); } },
        { key: 'assembly', label: 'Assembly' },
        { key: 'expression', label: 'Expression', get: function (m) { return expr[m.name] ? 'yes' : ''; },
          html: function (m) { return expr[m.name] ? '<a class="v2-mark" href="' + R.escape(expr[m.name]) + '" target="_blank" rel="noopener" title="Open in qTeller">✓</a>' : tick(false); } },
        { key: 'insertions', label: 'Insertions', sort: 'number', get: function (m) { return ins[m.name] ? String(ins[m.name]) : ''; }, html: function (m) { return tick(ins[m.name], (ins[m.name] || 0) + ' insertions'); } },
        { key: 'traits', label: 'SNP–traits', sort: 'number', get: function (m) { return traits[m.name] ? String(traits[m.name]) : ''; }, html: function (m) { return tick(traits[m.name], (traits[m.name] || 0) + ' associations'); } },
        { key: 'proteomics', label: 'Proteomics', get: function (m) { return prot[m.name] ? 'yes' : ''; }, html: function (m) { return tick(!!prot[m.name], prot[m.name]); } },
        { key: 'structure', label: 'Structure', get: function (m) { return structs[m.name] ? 'yes' : ''; },
          html: function (m) { return structs[m.name] ? '<a class="v2-mark" href="' + R.escape(structs[m.name]) + '" title="Protein structure record">✓</a>' : tick(false); } },
        { key: 'terms', label: 'GO terms', sort: 'number', get: function (m) { return fnTerms[m.name] ? String(fnTerms[m.name]) : ''; }, html: function (m) { return tick(fnTerms[m.name], (fnTerms[m.name] || 0) + ' terms'); } },
        { key: 'domains', label: 'Domains', get: function (m) { return domains[m.name] || ''; } }
      ]
    });
    out.insertAdjacentHTML('beforeend', statusLine('Read from the pan-gene record. A tick or a count means the member carries that data; the gene model link opens its own record.'));
    return true;
  }

  /* No Viewers and downloads section in this layout. Kept guarded rather than
     deleted: loadPanExtra() asks the pan-gene record for the fields it needs,
     and an unguarded body() of an id that is not in the markup returns null and
     takes the whole pan-gene load down with it. */
  function pgViewers(viewers, downloads) {
    var out = body('pg-viewers');
    if (!out) { return false; }
    out.innerHTML = '';
    var links = [];
    if (viewers && viewers.gcv_url) { links.push('<a class="mgdb-button mgdb-button-secondary" href="' + R.escape(viewers.gcv_url) + '" target="_blank" rel="noopener">Genome Context Viewer</a>'); }
    if (viewers && viewers.ncbi) {
      if (viewers.ncbi.gdv_url) { links.push('<a class="mgdb-button mgdb-button-quiet" href="' + R.escape(viewers.ncbi.gdv_url) + '" target="_blank" rel="noopener">NCBI Genome Data Viewer</a>'); }
      if (viewers.ncbi.cgv_url) { links.push('<a class="mgdb-button mgdb-button-quiet" href="' + R.escape(viewers.ncbi.cgv_url) + '" target="_blank" rel="noopener">NCBI Comparative Genome Viewer</a>'); }
    }
    var rendered = false;
    if (links.length) {
      out.insertAdjacentHTML('beforeend', blockHtml('Comparative viewers', null, '<div class="mgdb-rec-linkrow">' + links.join('') + '</div>' +
        (viewers && viewers.ncbi && viewers.ncbi.gene_accession ? statusLine('NCBI Gene ' + viewers.ncbi.gene_accession + ' on ' + viewers.ncbi.reference_assembly + ' (' + viewers.ncbi.reference_accession + ').') : '')));
      rendered = true;
    }
    /* The per-pan-gene sequence files are assembled on request by the
       pan-gene record page (record_data/pan_gene_seq.php, driven by its own
       script), so they are not plain URLs to list here; the record is one
       click away and the bulk directory is a plain link. */
    if (downloads && (downloads.pan_gene_name || downloads.bulk_url)) {
      var files = (downloads.files || []).map(function (f) { return f.label; });
      out.insertAdjacentHTML('beforeend', blockHtml('Pan-gene downloads', files.length || null,
        (files.length ? '<ul class="mgdb-list">' + files.map(function (l) { return '<li>' + R.escape(l) + '</li>'; }).join('') + '</ul>' : '') +
        '<div class="mgdb-rec-linkrow">' +
          (downloads.pan_gene_name ? '<a class="mgdb-button mgdb-button-secondary" href="/pan_gene_center/pan_gene/' + encodeURIComponent(downloads.pan_gene_name) + '#pg-record-downloads">Download from the pan-gene record</a>' : '') +
          (downloads.bulk_url ? '<a class="mgdb-button mgdb-button-quiet" href="' + R.escape(downloads.bulk_url) + '" target="_blank" rel="noopener">Bulk pan-gene directory</a>' : '') +
        '</div>' +
        statusLine('The files for one pan-gene are built on request and can be slow; the bulk directory holds the whole analysis.')));
      rendered = true;
    }
    return rendered;
  }

  /* ========================================================================
     VIEW 4: Genetic information, one section set per classical gene
     ======================================================================== */

  /* One set of genetic sections, in the markup, for the locus the header
     leads with. The id ignores the locus -- v2 needed it because it built a set
     per gene; here the other loci are named in the header callout and link to
     their own pages. */
  function locusSectionId(id, key) { return 'gn-' + key; }

  /* data = { locus, references, xrefs, ontology, counts } */
  function renderLocusView(id, data) {
    var locus = data.locus || {};
    var sid = function (key) { return locusSectionId(id, key); };
    var out;

    out = body(sid('overview')); out.innerHTML = '';
    var products = (data.gene_products || []);
    out.insertAdjacentHTML('beforeend', R.facts([
      ['Symbol', locus.name ? R.escape(locus.name) : ''],
      ['Full name', locus.full_name ? R.escape(locus.full_name) : ''],
      ['Type', locus.type ? R.escape(locus.type) : ''],
      ['Chromosome bin', locus.bin ? R.escape(locus.bin) : ''],
      ['MaizeGDB ID', locus.id ? '<span class="mgdb-record-id">' + locus.id + '</span>' : ''],
      ['Locus record', locus.locus_html ? R.link(locus.locus_html, 'Open the locus record') : ''],
      ['Gene products', products.length ? products.map(function (g) { return g.html ? R.link(g.html, g.name) : R.escape(g.name); }).join(', ') : '']
    ]));
    R.collection(out, {
      title: 'Synonyms', items: locus.synonyms, filename: 'gene-synonyms.tsv',
      columns: [
        { key: 'name', label: 'Name', tile: true },
        { key: 'authority', label: 'Authority' },
        { key: 'reference', label: 'Reference', get: function (s) { return s.reference && s.reference.name ? s.reference.name : ''; },
          html: function (s) { return s.reference && s.reference.name ? R.refLink(s.reference) : '<span class="mgdb-muted">—</span>'; } }
      ]
    });
    R.notes(out, 'Curator notes', (locus.comments || []).map(function (c) {
      return { text: c.text, meta: [c.label, c.reference ? 'Source: ' + (R.refLink(c.reference) || R.escape(c.reference.name)) : '', c.authority ? 'Authority: ' + R.escape(c.authority) : ''] };
    }));
    var models = locus.associated_gene_models || [];
    var allB73 = models.length && models.every(function (m) { return (m.assembly || '').indexOf('B73') !== -1; });
    R.collection(out, {
      title: (allB73 ? 'B73 gene models' : 'Gene models') + ' for this classical gene', items: models, filename: 'gene-associated-models.tsv',
      columns: [
        { key: 'name', label: 'Gene model', tile: true, html: function (m) { return R.link('/gene_center/gene/' + encodeURIComponent(m.name), m.name) + (m.is_current_record ? ' <span class="mgdb-pill mgdb-pill-ok">This record</span>' : ''); } },
        { key: 'assembly', label: 'Assembly' },
        { key: 'annotation', label: 'Annotation' },
        { key: 'position', label: 'Position', get: locationText, html: function (m) { var t = locationText(m); return t ? '<span class="mgdb-sequence">' + R.escape(t) + '</span>' : '—'; } },
        { key: 'is_current', label: 'Current in its annotation', get: function (m) { return m.is_current ? 'Yes' : 'No'; },
          html: function (m) { return m.is_current ? '<span class="mgdb-pill mgdb-pill-ok">Current</span>' : '<span class="mgdb-pill mgdb-pill-warn">Superseded</span>'; } }
      ]
    });
    R.collection(out, {
      title: 'Phenotypes', items: locus.phenotypes, filename: 'gene-phenotypes.tsv',
      columns: [
        { key: 'name', label: 'Phenotype', tile: true, html: function (p) { return R.link('/data_center/phenotype?id=' + p.id, p.name); } },
        { key: 'qualifier', label: 'Qualifier', get: function (p) { return p.qualifier || ''; } },
        R.urlColumn(function (p) { return '/data_center/phenotype?id=' + p.id; })
      ]
    });
    R.collection(out, {
      title: 'Related loci', items: locus.related_loci, filename: 'gene-related-loci.tsv',
      columns: [
        { key: 'name', label: 'Locus', tile: true, html: function (l) { return R.link('/data_center/locus?id=' + l.id, l.name); } },
        { key: 'qualifier', label: 'Relationship' }
      ]
    });
    state.rendered[sid('overview')] = !!locus.id;

    out = body(sid('annotations')); out.innerHTML = '';
    state.rendered[sid('annotations')] = R.collection(out, {
      title: 'Ontology terms on ' + (locus.name || 'this gene'), items: data.ontology, filename: 'gene-locus-ontology-terms.tsv', columns: ontologyColumns()
    });

    out = body(sid('references')); out.innerHTML = '';
    /* The same list as the visual view's References, year figure included. */
    state.rendered[sid('references')] = R.references(out, data.references, R.byId(sid('references')), 'locus-' + id + '-ref', { timeline: true });

    out = body(sid('alleles')); out.innerHTML = '';
    var allelesOk = R.collection(out, {
      title: 'Alleles and variations', items: locus.alleles || data.alleles, filename: 'gene-alleles.tsv',
      columns: alleleColumns()
    });
    /* The gallery, not a collection.

       These are the same images the visual view shows, and they were being
       handed to R.collection() -- whose Cards view is the generic
       definition-list card, built for rows of fields. Given an image row it
       renders the caption and the URL as text with a 72px thumbnail wedged
       into one cell, which is what "the cards look broken" was: not a broken
       card, the wrong card.

       R.images() is the component built for this, and it is what the visual
       view's Mutant Phenotype Images uses -- figure, caption, badge, and the
       shared lightbox. Both sections now share the dialog in the template. */
    var imagesOk = R.images(out, (locus.images || []).map(function (image) {
      return { url: image.url, caption: image.caption || '',
               title: (image.variation && image.variation.name) || 'Image',
               category: image.variation_type || 'Image',
               record: (image.variation && image.variation.html) || '' };
    }), 'gene-record-image-dialog', { title: 'Images on these alleles', filename: 'gene-allele-images.tsv' });
    state.rendered[sid('alleles')] = allelesOk || imagesOk;

    out = body(sid('stocks')); out.innerHTML = '';
    state.rendered[sid('stocks')] = R.collection(out, {
      title: 'Stocks', items: locus.stocks, filename: 'gene-stocks.tsv',
      columns: [
        { key: 'name', label: 'Stock', tile: true, html: function (st) { return R.link(st.html, st.name) + (st.from_stock_center ? ' <span class="mgdb-pill mgdb-pill-ok">Stock Center</span>' : ''); } },
        { key: 'full_name', label: 'Description' },
        { key: 'type', label: 'Type' },
        { key: 'alleles', label: 'Alleles' },
        { key: 'available_from', label: 'Available from' },
        R.urlColumn(function (st) { return st.html; })
      ]
    });

    out = body(sid('map')); out.innerHTML = '';
    state.rendered[sid('map')] = R.collection(out, {
      title: 'Map coordinates', items: locus.map_positions, filename: 'gene-map-positions.tsv',
      columns: [
        /* /data_center/map/{name} resolves by name -- the payload carries no
           map id, and the route looks the name up rather than echoing it (a
           name that does not exist answers "Map: not found"). encodeURIComponent
           because every one of these has spaces: "IBM2 2008 Neighbors Frame 2". */
        { key: 'map', label: 'Map', tile: true,
          html: function (m) { return m.map ? R.link('/data_center/map/' + encodeURIComponent(m.map), m.map) : '<span class="mgdb-muted">&mdash;</span>'; } },
        { key: 'position', label: 'Position', sort: 'number', numeric: true, get: function (m) { return m.position == null ? '' : String(m.position); } },
        { key: 'bin', label: 'Bin' },
        { key: 'bin2', label: 'Bin 2' },
        { key: 'is_backbone', label: 'Backbone', get: function (m) { return m.is_backbone ? 'Yes' : 'No'; },
          html: function (m) { return m.is_backbone ? '<span class="mgdb-pill mgdb-pill-ok">Backbone</span>' : '<span class="mgdb-muted">—</span>'; } }
      ]
    });

    state.rendered[sid('nearby')] = renderNearby(body(sid('nearby')), locus);
    state.rendered[sid('genetic')] = renderGenetic(body(sid('genetic')), locus);

    out = body(sid('external')); out.innerHTML = '';
    state.rendered[sid('external')] = R.collection(out, { title: 'Cross-references', items: data.xrefs, filename: 'gene-cross-references.tsv', columns: xrefColumns() });

    var counts = Object.assign({}, data.counts || {}, { nearby: (locus.nearby_loci || []).length, genetic: (locus.genetic || []).length });
  }

  function renderNearby(out, locus) {
    var list = (locus && locus.nearby_loci) || [];
    out.innerHTML = '';
    if (!list.length) { return false; }
    var maxWindow = locus.nearby_window_cm || 10;
    var choices = [1, 2, 5, 10].filter(function (c) { return c <= maxWindow; });
    out.innerHTML = '<div class="mgdb-rec-toolbar"><div class="mgdb-view-toggle" role="group" aria-label="Window">' +
      choices.map(function (c) { return '<button class="mgdb-view-btn" type="button" data-window="' + c + '" aria-pressed="' + (c === maxWindow) + '">±' + c + ' cM</button>'; }).join('') +
      '</div></div><div data-role="nearby-list"></div>';
    var host = qs('[data-role="nearby-list"]', out);
    function draw(windowCm) {
      host.innerHTML = '';
      R.collection(host, {
        title: 'Loci within ' + windowCm + ' cM', items: list.filter(function (n) { return n.distance_cm === null || n.distance_cm <= windowCm; }),
        filename: 'gene-nearby-loci.tsv',
        columns: [
          { key: 'name', label: 'Locus', tile: true, html: function (n) { return (n.html ? R.link(n.html, n.name) : R.escape(n.name)) + (n.is_self ? ' <span class="mgdb-pill mgdb-pill-ok">This gene</span>' : ''); } },
          { key: 'map', label: 'Map' },
          { key: 'position', label: 'Position', sort: 'number', numeric: true, get: function (n) { return n.position == null ? '' : String(n.position); } },
          { key: 'distance_cm', label: 'Distance (cM)', sort: 'number', numeric: true, get: function (n) { return n.distance_cm == null ? '' : String(n.distance_cm); } }
        ]
      });
    }
    qsa('[data-window]', out).forEach(function (button) {
      button.addEventListener('click', function () {
        qsa('[data-window]', out).forEach(function (b) { b.setAttribute('aria-pressed', String(b === button)); });
        draw(Number(button.getAttribute('data-window')));
      });
    });
    draw(maxWindow);
    return true;
  }

  var GENETIC_KINDS = [
    ['primer', 'Primers and enzymes', 'Primer'], ['bac', 'Related BACs', 'BAC'],
    ['gel_pattern', 'Gel patterns', 'Gel pattern'], ['map_score', 'Map scores', 'Map score'],
    ['recombination', 'Recombination data', 'Recombination']
  ];

  function renderGenetic(out, locus) {
    var list = (locus && locus.genetic) || [];
    out.innerHTML = '';
    if (!list.length) { return false; }
    var rendered = false;
    GENETIC_KINDS.forEach(function (spec) {
      var items = list.filter(function (g) { return g.kind === spec[0]; });
      var columns = [{ key: 'name', label: spec[2], tile: true, html: function (g) { return g.html ? R.link(g.html, g.name) : R.escape(g.name); } }];
      if (spec[0] === 'primer') {
        columns.push({ key: 'detail', label: 'Sequence', html: function (g) { return g.detail ? '<span class="mgdb-sequence">' + R.escape(g.detail) + '</span>' : '<span class="mgdb-muted">Not recorded</span>'; } });
      }
      columns.push({ key: 'id', label: 'MaizeGDB ID', sort: 'number', numeric: true, get: function (g) { return g.id == null ? '' : String(g.id); },
        html: function (g) { return g.id == null ? '—' : '<span class="mgdb-sequence">' + g.id + '</span>'; } });
      columns.push(R.urlColumn(function (g) { return g.html; }));
      rendered = R.collection(out, { title: spec[1], items: items, filename: 'gene-' + spec[0] + '.tsv', columns: columns }) || rendered;
    });
    return rendered;
  }

  /* An additional classical gene on the model: its own request, made when
     its chip is first chosen. */

  /* ------------------------------------------------------------------------
     Assembly
     ------------------------------------------------------------------------ */

  function render(response) {
    payload = response;
    var data = response.data || {};
    var sections = data.sections || {};
    var meta = response.meta || {};
    var counts = meta.counts || {};
    counts.orthologs = ((sections.orthologs || {}).orthologs || []).length;

    R.show(els.loading, false);
    R.show(els.error, false);

    var hasGeneModel = !!(data.attributes && data.attributes.name);
    var primaryLocus = sections.locus && sections.locus.id ? sections.locus : null;
    var loci = ((sections.overview || {}).loci || []).slice();
    if (!loci.length && primaryLocus) {
      loci = [{ id: primaryLocus.id, name: primaryLocus.name, full_name: primaryLocus.full_name, type: primaryLocus.type, html: primaryLocus.locus_html }];
    }
    if (primaryLocus) {
      loci.sort(function (a, b) { return (a.id === primaryLocus.id ? 0 : 1) - (b.id === primaryLocus.id ? 0 : 1); });
    }
    state.loci = loci;
    state.locus = primaryLocus ? primaryLocus.id : (loci.length ? loci[0].id : null);

    renderHeader(data, sections);

    /* ---- Genetic information ------------------------------------------
       Rendered first, so the other views' crosslinks can see which of its
       sections have content. The nine sections are in the markup and describe
       the locus the header leads with; the others are named in the header.

       References and External links are record-level, not locus-level, so they
       are filled even when there is no classical gene -- otherwise the twelve
       cross-references on a model with no locus would have nowhere to go, and
       half of all B73 models have no locus. */
    if (primaryLocus) {
      var lfn = sections.function || {};
      renderLocusView(primaryLocus.id, {
        locus: primaryLocus,
        references: (sections.references || {}).references || [],
        xrefs: (sections.xrefs || {}).xrefs || [],
        ontology: (lfn.ontology || []).filter(function (t) { return t.scope === 'locus' || !t.scope || !hasGeneModel; }),
        gene_products: lfn.gene_products || [],
        counts: counts
      });
      state.locusLoaded[primaryLocus.id] = 'done';
      ORDER.genetic.forEach(function (id) { if (!state.rendered[id]) { fillEmpty(id); } });
    } else {
      LOCUS_KEYS.forEach(function (key) {
        var sid = 'gn-' + key;
        if (key === 'references') {
          mark(R.references(body(sid), (sections.references || {}).references, R.byId(sid), 'gene-ref-gn', { timeline: true }), sid);
        } else if (key === 'external') {
          mark(gmXrefs((sections.xrefs || {}).xrefs, body(sid)), sid);
        }
        if (!state.rendered[sid]) {
          emptyState(sid, 'needs', 'This is about a classical gene, and no gene or locus is linked to '
            + recordName() + '. Genetic information is curated against the gene, not the gene model.');
        }
      });
    }

    var panHas = hasGeneModel && sections.pan_gene && sections.pan_gene.pan_gene && sections.pan_gene.pan_gene.name;
    state.views = ['visual', 'gene_model', 'pan_gene', 'genetic'];

    /* ---- Gene model ---------------------------------------------------- */
    if (hasGeneModel) {
      mark(gmOverview(sections.overview || {}, sections.structure, sections, counts), 'gm-overview');
      mark(gmAnnotations(sections.function, sections.structure), 'gm-annotations');
      mark(gmInsertions(sections.variation), 'gm-insertions');
      mark(gmExpression(sections.expression), 'gm-expression');
      mark(gmSnps(sections.variation), 'gm-snps');
      mark(gmProteomics(sections.expression), 'gm-proteomics');
      mark(renderSequences(sections.sequences, body('gm-sequences')), 'gm-sequences');
    }
    ORDER.gene_model.forEach(function (id) {
      if (state.rendered[id]) { return; }
      if (!hasGeneModel) {
        emptyState(id, 'needs', 'This is about a gene model, and no gene model is linked to '
          + recordName() + ' in any assembly.');
      } else {
        fillEmpty(id);
      }
    });

    /* ---- Pan-gene ------------------------------------------------------- */
    if (panHas) {
      mark(pgMembers(sections.pan_gene), 'pg-members');
      mark(pgOrthologs(sections.orthologs), 'pg-orthologs');
    }
    ORDER.pan_gene.forEach(function (id) {
      if (state.rendered[id]) { return; }
      /* The dataset matrix has not been asked for yet; loadPanExtra() fills it
         or says why when the view is opened. */
      if (id === 'pg-datasets' && panHas) {
        /* Not empty -- not asked for. The matrix is a second request that
           loadPanExtra() makes when this view is first opened. */
        body(id).innerHTML = '<p class="mgdb-rec-block-status">The dataset matrix is loaded when this view is opened.</p>';
        return;
      }
      if (!hasGeneModel) {
        emptyState(id, 'needs', 'Pan-gene membership is a property of a gene model, and none is linked to ' + recordName() + '.');
      } else if (!panHas) {
        emptyState(id, 'none', recordName() + ' has not been placed in a pan-gene, so it has no members, datasets or pan-gene orthologs.');
      } else {
        fillEmpty(id);
      }
    });

    /* ---- Visual overview, last, so its crosslinks see the rest ---------- */
    if (hasGeneModel) { mark(renderStructure(sections.structure), 'gene-record-structure'); }
    mark(renderFunction(sections.function), 'gene-record-function');
    if (hasGeneModel) { mark(renderExpression(sections.expression), 'gene-record-expression'); }
    mark(renderVariation(sections.variation, sections.overview, sections.structure), 'gene-record-variation');
    if (hasGeneModel) { mark(renderOrthologs(sections.orthologs), 'gene-record-orthologs'); }
    if (hasGeneModel) { mark(renderParalogs(sections.paralogs, sections.expression), 'gene-record-paralogs'); }
    mark(renderImages(primaryLocus), 'gene-record-images');
    mark(R.references(body('gene-record-references'), (sections.references || {}).references,
                      R.byId('gene-record-references'), 'gene-ref', { timeline: true }), 'gene-record-references');
    if (hasGeneModel) { mark(renderProvenance(sections.structure), 'gene-record-provenance'); }

    /* Plotly sizes to its container, and a hidden container has no width. */
    R.byId('gene-record-metrics').hidden = false;
    mark(renderMetrics(counts), 'gene-record-metrics');
    state.rendered['gene-record-resources'] = true;
    state.rendered['gene-record-api'] = true;

    ORDER.visual.forEach(function (id) {
      if (state.rendered[id]) { return; }
      if (!hasGeneModel && GENE_MODEL_ONLY[id]) {
        emptyState(id, 'needs', 'This is about a gene model, and no gene model is linked to '
          + recordName() + ' in any assembly.');
      } else {
        fillEmpty(id);
      }
    });

    markBars(counts);

    /* Where to start: a section named in the hash wins, then ?view=. */
    var initial = 'visual';
    var params = new URL(window.location.href).searchParams;
    var hashId = resolveSection((window.location.hash || '').slice(1));
    var fromHash = hashId ? viewOf(hashId) : null;
    if (fromHash) { initial = fromHash; }
    else if (params.get('view') && ORDER[params.get('view')]) { initial = params.get('view'); }
    showView(initial, false);
    if (fromHash) {
      window.setTimeout(function () {
        var target = R.byId(hashId);
        if (target && target.scrollIntoView) { target.scrollIntoView(); }
      }, 0);
    }

    R.notice(els.notice, meta, counts);
    MGDB.announce('Record loaded.');
  }

  function load() {
    if (main.getAttribute('data-gene-state') === 'withdrawn') { R.show(els.loading, false); return; }
    var requested = main.getAttribute('data-gene-id') || main.getAttribute('data-requested-id');
    if (!requested) { return; }
    R.show(els.error, false);
    R.show(els.loading, true);
    MGDB.request('/api/v1/records/gene/' + encodeURIComponent(requested), { key: 'gene-record' })
      .then(function (response) {
        if (!response || !response.data) { throw new Error('unexpected payload'); }
        render(response);
      })
      .catch(function (error) {
        if (error && error.name === 'AbortError') { return; }
        if (window.console && console.error) { console.error('[v5] render failed:', error && error.stack ? error.stack : error); }
        R.show(els.loading, false);
        R.show(els.error, true);
      });
  }

  function init() {
    main = R.byId('gene-record-top');
    if (!main) { return; }
    els = {
      functionLine: R.byId('gene-record-function-line'),
      synonyms: R.byId('gene-record-synonyms'),
      subtitle: R.byId('gene-record-subtitle'),
      versionNotice: R.byId('gene-record-version-notice'),
      report: R.byId('gene-record-report'),
      loading: R.byId('gene-record-loading'),
      error: R.byId('gene-record-error'),
      retry: R.byId('gene-record-retry'),
      notice: R.byId('gene-record-notice'),
      nav: R.byId('v5-nav')
    };
    if (els.retry) { els.retry.addEventListener('click', load); }
    R.apiCard('gene-copy-json-btn', 'gene-record-api-link', function () { return payload; });

    /* One listener for every jump button the renderers emit, wherever it is. */
    main.addEventListener('click', function (event) {
      var target = event.target.closest ? event.target.closest('[data-jump]') : null;
      if (target) { event.preventDefault(); jumpTo(target.getAttribute('data-jump')); }
    });

    /* The four view tabs. The bars and their scrollspies are the shell's, and
       each one is bound the first time its view is opened. */
    qsa('[data-v5-view]').forEach(function (tab) {
      tab.addEventListener('click', function () { showView(tab.getAttribute('data-v5-view'), false); });
      tab.addEventListener('keydown', function (event) {
        var keys = qsa('[data-v5-view]').map(function (t) { return t.getAttribute('data-v5-view'); });
        var i = keys.indexOf(tab.getAttribute('data-v5-view'));
        var next = -1;
        if (event.key === 'ArrowRight') { next = (i + 1) % keys.length; }
        else if (event.key === 'ArrowLeft') { next = (i - 1 + keys.length) % keys.length; }
        else if (event.key === 'Home') { next = 0; }
        else if (event.key === 'End') { next = keys.length - 1; }
        if (next < 0) { return; }
        event.preventDefault();
        showView(keys[next], true);
      });
    });

    window.addEventListener('resize', MGDB.debounce(measureNav, 100));
    if (window.ResizeObserver && els.nav) { new window.ResizeObserver(measureNav).observe(els.nav); }
    measureNav();
    load();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
