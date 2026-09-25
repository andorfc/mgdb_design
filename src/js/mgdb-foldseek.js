/* ==========================================================================
   /foldseek and /fusarium/foldseek — Foldseek structural matches
   --------------------------------------------------------------------------
   One script for two analyses: maize proteins against eight proteomes, and
   the Fusarium Protein Toolkit's F. graminearum and F. verticillioides
   proteins against nine. The page says which with data-set on its <main>;
   every sentence, route and link that differs between them is in SETS below,
   and nothing else in this file knows which analysis it is drawing.

   Reads search/foldseek/foldseek_api.php and draws, for one protein:

     the protein        its identifiers, length and the links out
     its structure      the AlphaFold model, colored by pLDDT or by Pfam domain
     genome context     the JBrowse view the upstream page embedded (maize)
     each proteome      the closest match in each proteome, in one table
     coverage           where on the protein each proteome's matches fall,
                        shaded by the highest identity at each position
     every match        a sortable, filterable, paged table, exportable as TSV
     one match          its alignment and a 3D superposition, scored with the
                        TM-score TM-align gives it

   Why the superposition is drawn the way it is
   --------------------------------------------
   The upstream search returns the Calpha coordinates it aligned, for both
   proteins, and nothing else. Those are what the superposition uses, so the
   picture is the alignment the numbers describe -- residue for residue, with
   no second model fetched and no renumbering to trust. They are drawn as a
   tube through the Calpha atoms because that is all there is; a cartoon would
   need backbone atoms the search never had. The upstream page rebuilt them
   with PULCHRA to draw cartoons, and that rebuild is exactly where its
   TM-scores went wrong (see js/mgdb-tmalign.js).

   The TM-score runs in a Web Worker: it is TM-align's fragment search, about
   100 ms for a 470-residue alignment and seconds for the largest maize
   proteins, and it should never freeze the page while it works.

   Nothing here touches the DOM before DOMContentLoaded. Bauplan emits every
   includeScript() into <head>, so a top-level querySelector runs while <main>
   does not yet exist and returns null.

   Depends on MGDB from /js/mgdb-modern.js, $3Dmol from /js/lib/3dmol/ and
   MGDBTMalign from /js/mgdb-tmalign.js.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  if (!MGDB) { return; }

  var escape = MGDB.escapeHtml;
  var API = '';
  var els = {};

  /* What differs between the two analyses. maize is the default, and its
     entries are the sentences this page printed before there was a second. */
  var SETS = {
    maize: {
      route: '/foldseek',
      host: 'foldseek.maizegdb.org',
      upstream: function (term) { return 'https://foldseek.maizegdb.org/?uniprot=' + encodeURIComponent(term); },
      proteomes: 'eight proteomes',
      covers: 'The analysis covers the 39,299 maize proteins that had an AlphaFold model in July 2022, '
        + 'looked up by B73 RefGen_v5 or v4 gene model, gene symbol or UniProt accession.',
      badTerm: 'That is not a maize gene model, gene symbol or UniProt accession.',
      noMatchTail: ' in any of the eight proteomes, maize included.',
      queryNoun: 'maize protein',
      geneColumn: 'maize_gene_v5',
      pfam: true,
      noAnnotation: 'No UniProt annotation',
      organism: function () { return 'ZEA MAYS'; },
      geneHref: function (hit) { return hit.gene ? '/gene_center/gene/' + encodeURIComponent(hit.gene) : null; },
      /* A maize match was itself searched, so its own results exist. */
      searchable: function (hit) { return hit.species === 'maize'; },
      matchLinks: function (hit) {
        return [['UniProt', uniprotUrl(hit.target)], ['AlphaFold DB', entryUrl(hit.target)]];
      }
    },
    fusarium: {
      route: '/fusarium/foldseek',
      host: 'fusarium.maizegdb.org',
      upstream: function (term) {
        return 'https://fusarium.maizegdb.org/protein_structure/index.php?uniprot=' + encodeURIComponent(term);
      },
      proteomes: 'nine proteomes',
      covers: 'The analysis covers the 15,911 F. graminearum and 17,356 F. verticillioides proteins '
        + 'that had an AlphaFold model in January 2023, looked up by gene id or UniProt accession.',
      badTerm: 'That is not a Fusarium gene id or UniProt accession.',
      noMatchTail: ' in any of the nine proteomes.',
      queryNoun: 'searched protein',
      geneColumn: 'match_gene',
      /* No Fusarium protein has Pfam rows upstream -- every page says "There
         are no PFAM domains for this protein" -- so the Pfam track, toggle and
         table would only ever be empty. */
      pfam: false,
      /* F. graminearum's UniProt names are mostly "Chromosome 1, complete
         genome", which foldseek_lib.php drops; say what is missing. */
      noAnnotation: 'No functional name in UniProt',
      organism: function (protein) {
        return String((protein && protein.species_latin) || 'FUSARIUM').toUpperCase();
      },
      /* Fusarium matches open on the toolkit's own structure page: its models
         are the ones the search used, and AlphaFold DB has deleted the
         F. oxysporum entries along with UniProt. */
      geneHref: function (hit) { return FUSARIUM_SPECIES[hit.species] ? structuresUrl(hit.target) : null; },
      searchable: function (hit) { return hit.species === 'graminearum' || hit.species === 'verticillioides'; },
      matchLinks: function (hit) {
        var links = [['UniProt', uniprotUrl(hit.target)]];
        if (FUSARIUM_SPECIES[hit.species]) { links.push(['Structures', structuresUrl(hit.target)]); }
        else { links.push(['AlphaFold DB', entryUrl(hit.target)]); }
        return links;
      }
    }
  };
  var FUSARIUM_SPECIES = { graminearum: 1, verticillioides: 1, fujikuroi: 1, oxysporum: 1, proliferatum: 1, solani: 1 };
  var SET = SETS.maize;

  function structuresUrl(acc) { return '/fusarium/structures?id=' + encodeURIComponent(acc); }

  /* Every request names its analysis, except maize's, which is the API's
     default -- so the maize page asks exactly what it always asked. */
  var setKey = 'maize';
  function apiUrl(query) {
    return API + '?' + query + (setKey !== 'maize' ? '&set=' + encodeURIComponent(setKey) : '');
  }

  var state = {
    term: '',
    serial: 0,
    data: null,
    speciesByKey: {},
    hits: [],
    filter: { species: 'all', text: '' },
    sort: { key: 'evalue', dir: 'asc' },
    page: 1,
    pageSize: 25,
    openN: null,
    detailSerial: 0,
    sup: null,
    supViewer: null,
    supModels: null,
    supOptions: { scheme: 'chain', full: false, pairs: false, spin: false },
    queryViewer: null,
    queryModel: null,
    queryScheme: 'plddt',
    queryPlddt: null,
    querySameSequence: null,
    querySpin: false,
    worker: null,
    workerBusy: false,
    tmJob: 0
  };

  /* ----------------------------------------------------------------------
   * Palettes
   *
   * The viewers sit on the dark ground the Protein Structure Hub, FATCAT
   * and AlphaFill viewers share, so the chain colors are the lighter end of
   * a blue/amber pair -- distinguishable for the common forms of color
   * blindness, which a red/green pair would not be.
   * ---------------------------------------------------------------------- */
  var VIEWER_BG = '#0d1117';
  var COLOR_QUERY = '#58a6ff';
  var COLOR_QUERY_DIM = '#2d5b8c';
  var COLOR_TARGET = '#f0a030';
  var COLOR_TARGET_DIM = '#80561a';
  var COLOR_MUTED = '#8b949e';
  var COLOR_MUTED_DIM = '#4b5563';

  /* Distance between an aligned pair after superposition. The same bins as
     /fatcat's deviation coloring, so the two tools read alike. */
  var DISTANCE = [
    { max: 1,        color: '#4f9dff', cls: 'fs-d0', label: 'under 1 Å' },
    { max: 2,        color: '#7fdcff', cls: 'fs-d1', label: '1–2 Å' },
    { max: 3.5,      color: '#ffe066', cls: 'fs-d2', label: '2–3.5 Å' },
    { max: 5,        color: '#ffa94d', cls: 'fs-d3', label: '3.5–5 Å' },
    { max: Infinity, color: '#ff6b6b', cls: 'fs-d4', label: 'over 5 Å' }
  ];

  /* The AlphaFold Database's own pLDDT bands and colors. */
  var PLDDT = [
    { min: 90,        color: '#0053d6', label: 'Very high, over 90' },
    { min: 70,        color: '#65cbf3', label: 'Confident, 70–90' },
    { min: 50,        color: '#ffdb13', label: 'Low, 50–70' },
    { min: -Infinity, color: '#ff7d45', label: 'Very low, under 50' }
  ];

  var DOMAIN_COLORS = ['#e69f00', '#56b4e9', '#009e73', '#f0e442', '#cc79a7', '#d55e00', '#0072b2'];
  var DOMAIN_NONE = '#4b5563';

  /* Coverage shading: the highest identity among one proteome's matches at a
     position. A single-hue ramp, darkest for the closest. */
  /* The lightest band is still well off the empty track: a first ramp whose
     bottom step was #e5f5e0 read as "no match" beside the #eef2f8 track, and
     the human and yeast rows -- the matches a structure search is for --
     looked blank. */
  var IDENTITY = [
    { min: 0.8, color: '#00441b', label: '80% or more' },
    { min: 0.5, color: '#238b45', label: '50–80%' },
    { min: 0.3, color: '#41ae76', label: '30–50%' },
    { min: 0.2, color: '#74c69d', label: '20–30%' },
    { min: 0,   color: '#b7e4c7', label: 'under 20%' }
  ];

  /* Amino-acid pairs BLOSUM62 scores above zero, for the alignment's middle
     line: a letter where the residues are identical, + where they are
     similar, as BLAST writes it. */
  var SIMILAR = {};
  'AS RK RQ ND NH NS DE QE QK EK HY IV IL IM LM LV MV FY FW ST WY'.split(' ').forEach(function (pair) {
    SIMILAR[pair] = true;
    SIMILAR[pair.charAt(1) + pair.charAt(0)] = true;
  });

  var AA1 = { ALA: 'A', ARG: 'R', ASN: 'N', ASP: 'D', CYS: 'C', GLN: 'Q', GLU: 'E', GLY: 'G',
              HIS: 'H', ILE: 'I', LEU: 'L', LYS: 'K', MET: 'M', PHE: 'F', PRO: 'P', SER: 'S',
              THR: 'T', TRP: 'W', TYR: 'Y', VAL: 'V', SEC: 'U', PYL: 'O' };
  var AA3 = {};
  Object.keys(AA1).forEach(function (three) { AA3[AA1[three]] = three; });

  /* ----------------------------------------------------------------------
   * Formatting
   * ---------------------------------------------------------------------- */

  function fmtE(value) {
    if (value === null || value === undefined || isNaN(value)) { return '—'; }
    if (value === 0) { return '0'; }
    return value < 0.001 ? value.toExponential(1) : String(Number(value.toPrecision(2)));
  }

  function fmtPct(fraction, digits) {
    if (fraction === null || fraction === undefined || isNaN(fraction)) { return '—'; }
    return (fraction * 100).toFixed(digits === undefined ? 1 : digits) + '%';
  }

  function fmtInt(value) {
    return value === null || value === undefined || isNaN(value) ? '—' : Number(value).toLocaleString('en-US');
  }

  function fmtRange(from, to) { return from + '–' + to; }

  /* Upstream cuts long UniProt names at about 100 characters with "...". */
  function tidy(text) {
    text = String(text || '').trim();
    return text.slice(-3) === '...' ? text.slice(0, -3).trim() + '…' : text;
  }

  function uniprotUrl(acc) { return 'https://www.uniprot.org/uniprotkb/' + encodeURIComponent(acc); }
  function entryUrl(acc) { return 'https://alphafold.ebi.ac.uk/entry/' + encodeURIComponent(acc); }
  function modelUrl(acc) {
    return 'https://alphafold.ebi.ac.uk/files/AF-' + encodeURIComponent(acc) + '-F1-model_'
      + (state.data && state.data.af_version ? state.data.af_version : 'v6') + '.pdb';
  }
  function geneUrl(gene) { return '/gene_center/gene/' + encodeURIComponent(gene); }

  /* A match's gene id, linked wherever this analysis sends one. */
  function geneLink(hit) {
    var href = SET.geneHref(hit);
    return href
      ? '<a class="fs-gene" href="' + escape(href) + '">' + escape(hit.gene) + '</a>'
      : '<span class="fs-gene">' + escape(hit.gene) + '</span>';
  }

  function proteinName(protein) {
    return protein.symbol || protein.v5 || (protein.genes && protein.genes[0]) || protein.uniprot;
  }

  function mk(tag, className) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    return node;
  }

  function hitByN(n) {
    for (var i = 0; i < state.hits.length; i++) {
      if (state.hits[i].n === n) { return state.hits[i]; }
    }
    return null;
  }

  function covBar(from, to, length, label) {
    var left = Math.max(0, (from - 1) / length * 100);
    var width = Math.max(0.6, (to - from + 1) / length * 100);
    return '<span class="fs-covbar" role="img" aria-label="' + escape(label || ('Residues ' + from + ' to ' + to + ' of ' + length)) + '">'
      + '<span style="left:' + left.toFixed(2) + '%;width:' + Math.min(100 - left, width).toFixed(2) + '%"></span></span>';
  }

  function saveFile(name, text, type) {
    var blob = new window.Blob([text], { type: type });
    var url = window.URL.createObjectURL(blob);
    var link = document.createElement('a');
    link.href = url;
    link.download = name;
    document.body.appendChild(link);
    link.click();
    window.setTimeout(function () {
      window.URL.revokeObjectURL(url);
      link.parentNode.removeChild(link);
    }, 0);
  }

  function setStatus(html) {
    if (els.status) { els.status.innerHTML = html || ''; }
  }

  /* ----------------------------------------------------------------------
   * Search
   * ---------------------------------------------------------------------- */

  function runSearch(term, options) {
    term = String(term || '').trim();
    if (!term) { return; }
    options = options || {};
    state.term = term;
    var mine = ++state.serial;

    stopSpins();
    state.openN = null;
    state.detailSerial++;
    setStatus('<div class="mgdb-loading"><span class="mgdb-spinner" aria-hidden="true"></span> '
      + 'Looking up Foldseek matches for <b>' + escape(term) + '</b>&hellip;</div>');

    MGDB.request(apiUrl('action=search&term=' + encodeURIComponent(term)), { key: 'fs-search' })
      .then(function (data) {
        if (mine !== state.serial) { return; }
        if (!data || !data.found) { renderNotFound(term, data || {}); return; }
        setStatus('');
        renderResults(data, options);
        writeUrl();
        MGDB.announce(data.hits.length + ' Foldseek matches for ' + proteinName(data.protein) + ' loaded.');
      })
      .catch(function (error) {
        if (mine !== state.serial || (error && error.name === 'AbortError')) { return; }
        renderError(term, error);
      });
  }

  function renderNotFound(term, data) {
    els.results.hidden = true;
    var tried = (data.tried || []).filter(function (value) { return value !== term; });
    var suggestions = (data.suggestions || []).map(function (row) {
      var value = (row.gene_ids && row.gene_ids.length === 1) ? row.gene_ids[0] : row.label;
      return '<button class="mgdb-chip" type="button" data-fs-example="' + escape(value) + '">'
        + escape(value) + '</button>';
    });
    /* The API can say more than "not found": a Fusarium protein from one of
       the four species that were only ever searched against is named, with
       the page that does have it. */
    var note = data.note
      ? '<span>' + escape(data.note) + '</span>'
        + (data.note_link ? '<div class="fs-note-actions"><a class="mgdb-button mgdb-button-secondary mgdb-button-sm" href="'
            + escape(data.note_link.href) + '">' + escape(data.note_link.label) + '</a></div>' : '')
      : '<span>' + escape(SET.covers)
        + (tried.length ? ' Also tried: ' + tried.map(escape).join(', ') + '.' : '') + '</span>';
    setStatus('<div class="mgdb-message mgdb-message-info fs-notfound"><div>'
      + '<strong>No Foldseek results for “' + escape(term) + '”.</strong> '
      + note
      + (suggestions.length ? '<p class="fs-suggest-row"><span>Related identifiers</span>' + suggestions.join('') + '</p>' : '')
      + '</div></div>');
    bindExamples(els.status);
    /* The address follows the search even when it finds nothing, so a reload
       or a shared link asks the same question rather than the one before. */
    state.data = null;
    state.hits = [];
    writeUrl();
    MGDB.announce('No Foldseek results for ' + term + '.');
  }

  function renderError(term, error) {
    els.results.hidden = true;
    var status = /status (\d+)/.exec(String((error && error.message) || ''));
    var code = status ? parseInt(status[1], 10) : 0;
    var message = code === 400
      ? SET.badTerm
      : 'The Foldseek results service at ' + SET.host + ' could not be reached. The rest of this page is unaffected.';
    setStatus('<div class="mgdb-message mgdb-message-error"><div><strong>' + escape(message) + '</strong>'
      + (code === 400 ? '' : ' <span>You can also open <a href="' + escape(SET.upstream(term))
          + '">the results for ' + escape(term) + ' at ' + escape(SET.host) + '</a>.</span>')
      + '</div></div>');
  }

  function writeUrl() {
    if (!window.history || !window.history.replaceState || !window.URLSearchParams) { return; }
    var params = new window.URLSearchParams();
    if (state.term) { params.set('uniprot', state.term); }
    var open = state.openN !== null ? hitByN(state.openN) : null;
    if (open) { params.set('hit', open.target); }
    var query = params.toString();
    window.history.replaceState(null, '', SET.route + (query ? '?' + query : '') + window.location.hash);
  }

  /* ----------------------------------------------------------------------
   * Results
   * ---------------------------------------------------------------------- */

  function renderResults(data, options) {
    state.data = data;
    state.speciesByKey = {};
    data.species.forEach(function (species, index) {
      species.index = index;
      state.speciesByKey[species.key] = species;
    });
    state.hits = data.hits.map(function (hit) {
      var species = state.speciesByKey[hit.species];
      hit.qcov = (hit.q_end - hit.q_start + 1) / hit.q_len;
      hit.tcov = (hit.t_end - hit.t_start + 1) / hit.t_len;
      hit.speciesIndex = species ? species.index : 99;
      hit.haystack = [hit.target, hit.gene || '', hit.annotation || '', species ? species.label : '']
        .join(' ').toLowerCase();
      return hit;
    });
    state.filter = { species: 'all', text: '' };
    state.sort = { key: 'evalue', dir: 'asc' };
    state.page = 1;
    state.openN = null;
    state.sup = null;

    var protein = data.protein;
    /* Some proteins were searched and kept nothing -- 1 of 25 random v5 gene
       models sampled on 2026-09-25. Eight empty proteome rows, an empty
       figure and a table saying "no matches fit the filter" would each be
       wrong in its own way; one plain sentence is right. */
    var empty = !data.hits.length;
    els.resultsTitle.textContent = 'Matches for ' + proteinName(protein);
    els.resultsBody.innerHTML = proteinMarkup(data) + overviewMarkup(data)
      + (empty ? noMatchesMarkup(data) : speciesMarkup(data) + coverageMarkup(data) + tableMarkup(data));
    els.results.hidden = false;

    bindResults();
    if (!empty) {
      drawCoverage();
      renderTable();
    }
    initQueryViewer();

    if (options && options.hit) {
      var wanted = String(options.hit).toUpperCase();
      for (var i = 0; i < state.hits.length; i++) {
        if (state.hits[i].target === wanted) { openHit(state.hits[i].n, { scroll: true }); break; }
      }
    }
  }

  function proteinMarkup(data) {
    var p = data.protein;
    var name = proteinName(p);
    var evalues = data.hits.map(function (hit) { return hit.evalue; }).filter(function (v) { return v !== null; });
    var proteomes = data.species.filter(function (s) { return s.count > 0; }).length;
    var facts = [
      p.species_label ? ['Species', '<i>' + escape(p.species_label) + '</i>'
        + (p.strain ? ' ' + escape(p.strain) : '')] : null,
      ['UniProt', '<a href="' + escape(uniprotUrl(p.uniprot)) + '">' + escape(p.uniprot) + '</a>'],
      p.v5 ? ['B73 RefGen_v5', '<a href="' + escape(geneUrl(p.v5)) + '">' + escape(p.v5) + '</a>'] : null,
      p.v4 ? ['B73 RefGen_v4', escape(p.v4)] : null,
      p.genes && p.genes.length ? ['Gene ' + (p.genes.length === 1 ? 'id' : 'ids'),
        p.genes.map(function (g) { return '<span class="fs-mono">' + escape(g) + '</span>'; }).join(', ')] : null,
      ['Length', fmtInt(p.length) + ' aa'],
      p.searched_model ? ['Searched model', '<span class="fs-mono">' + escape(p.searched_model) + '</span>'] : null
    ].filter(Boolean).map(function (pair) {
      return '<div><dt>' + pair[0] + '</dt><dd>' + pair[1] + '</dd></div>';
    }).join('');

    var actions = [];
    if (data.actions) {
      /* The Fusarium analysis names its own links; see foldseek_api.php. */
      data.actions.forEach(function (action) {
        actions.push('<a class="mgdb-button mgdb-button-' + (action.primary ? 'secondary' : 'quiet')
          + ' mgdb-button-sm" href="' + escape(action.href) + '">' + escape(action.label) + '</a>');
      });
    } else if (p.v5) {
      actions.push('<a class="mgdb-button mgdb-button-secondary mgdb-button-sm" href="' + escape(geneUrl(p.v5)) + '">Gene record</a>');
      actions.push('<a class="mgdb-button mgdb-button-quiet mgdb-button-sm" href="/data_center/protein_structure?term='
        + encodeURIComponent(p.v5) + '">Protein structures</a>');
      actions.push('<a class="mgdb-button mgdb-button-quiet mgdb-button-sm" href="/fatcat?term='
        + encodeURIComponent(p.v5) + '">FATCAT orthologs</a>');
      actions.push('<a class="mgdb-button mgdb-button-quiet mgdb-button-sm" href="/data_center/alphafill?gene='
        + encodeURIComponent(p.v5) + '">AlphaFill ligands</a>');
    }
    if (!data.actions) {
      actions.push('<a class="mgdb-button mgdb-button-quiet mgdb-button-sm" href="' + escape(entryUrl(p.uniprot)) + '">AlphaFold DB</a>');
    }

    var span = evalues.length
      ? '; E-values from ' + fmtE(Math.min.apply(null, evalues)) + ' to ' + fmtE(Math.max.apply(null, evalues))
      : '';
    var counts = data.hits.length
      ? fmtInt(data.hits.length) + ' matches in ' + proteomes + (proteomes === 1 ? ' proteome' : ' proteomes') + span + '.'
      : 'No structural match in any of the ' + SET.proteomes + '.';
    return '<div class="fs-protein">'
      + '<div class="fs-protein-main">'
      + '<h3 class="fs-protein-title"><span class="fs-protein-symbol">' + escape(name) + '</span>'
      + (p.name ? ' <span class="fs-protein-name">' + escape(p.name) + '</span>' : '') + '</h3>'
      + (p.description ? '<p class="fs-protein-desc">' + escape(p.description) + (p.truncated ? '…' : '') + '</p>' : '')
      + '<dl class="mgdb-record-facts fs-facts">' + facts + '</dl>'
      + '<p class="fs-protein-counts">' + counts + '</p>'
      + '</div>'
      + '<div class="fs-protein-actions">' + actions.join('') + '</div>'
      + '</div>';
  }

  function overviewMarkup(data) {
    var p = data.protein;
    var structure = '<div class="fs-block fs-structure">'
      + '<div class="fs-block-head"><h3 id="fs-structure-title">Structure</h3>'
      + '<div class="fs-segmented" role="group" aria-label="Color the structure by">'
      + '<button type="button" data-fs-qscheme="plddt" aria-pressed="true">Confidence</button>'
      + (SET.pfam ? '<button type="button" data-fs-qscheme="domains" aria-pressed="false">Pfam domains</button>' : '')
      + '</div></div>'
      + '<div class="fs-viewer">'
      + '<div class="fs-viewer-stage" data-fs-slot="query"><p class="fs-viewer-status" data-fs-qstatus>Loading the AlphaFold model&hellip;</p></div>'
      + '<div class="fs-viewer-foot"><div class="fs-legend" data-fs-qlegend></div>'
      + '<div class="fs-viewer-actions"><button type="button" data-fs-qreset>Reset view</button>'
      + '<button type="button" data-fs-qspin aria-pressed="false">Spin</button></div></div>'
      + '</div>'
      + '<p class="fs-caption" data-fs-qcaption>' + escape(data.model_label
          || ('AlphaFold DB model AF-' + p.uniprot + '-F1, version ' + String(data.af_version || '').replace(/^v/, '')))
      + '.</p>'
      + '</div>';

    var genome = '';
    if (data.links && data.links.jbrowse) {
      var open = 'https://jbrowse.maizegdb.org/?loc=' + encodeURIComponent(p.v5)
        + '&tracks=gene_models_official%2Calphafold';
      genome = '<div class="fs-block fs-genome">'
        + '<div class="fs-block-head"><h3>Genome context</h3>'
        + '<a class="mgdb-button mgdb-button-quiet mgdb-button-sm" href="' + escape(open) + '">Open in JBrowse</a></div>'
        + '<iframe class="fs-jbrowse" loading="lazy" src="' + escape(data.links.jbrowse) + '" '
        + 'title="JBrowse: ' + escape(p.v5) + ' with B73 RefGen_v5 gene models and AlphaFold confidence by exon"></iframe>'
        + '<p class="fs-caption">B73 RefGen_v5 gene models, and a track shading each exon by the mean pLDDT of its residues. '
        + 'That track was built from structures predicted on the RefGen_v4 annotation, so it may not match every v5 transcript.</p>'
        + '</div>';
    }
    return '<div class="fs-overview' + (genome ? '' : ' fs-overview-single') + '">' + structure + genome + '</div>';
  }

  function speciesMarkup(data) {
    var name = proteinName(data.protein);
    var rows = data.species.map(function (species) {
      var head = '<th scope="row" class="fs-proteome"><b>' + escape(species.label) + '</b>'
        + '<i>' + escape(species.latin) + '</i></th>';
      if (!species.count || !species.best) {
        return '<tr class="is-empty">' + head + '<td class="mgdb-numeric">0</td>'
          + '<td colspan="4" class="mgdb-muted">No match was kept from this proteome.</td><td></td></tr>';
      }
      var best = species.best;
      return '<tr>' + head
        + '<td class="mgdb-numeric">' + species.count + '</td>'
        + '<td class="fs-best"><a href="' + escape(uniprotUrl(best.target)) + '">' + escape(best.target) + '</a>'
        + (best.gene ? ' ' + geneLink(best) : '')
        + '<span class="fs-annot">' + escape(tidy(best.annotation) || SET.noAnnotation) + '</span></td>'
        + '<td class="mgdb-numeric">' + fmtPct(best.identity) + '</td>'
        + '<td class="mgdb-numeric">' + fmtE(best.evalue) + '</td>'
        + '<td class="fs-col-cov">' + covBar(best.q_start, best.q_end, best.q_len,
            'Covers residues ' + best.q_start + ' to ' + best.q_end + ' of ' + best.q_len)
        + '<span class="fs-range">' + fmtRange(best.q_start, best.q_end) + '</span></td>'
        + '<td class="fs-row-actions">'
        + '<button type="button" class="mgdb-button mgdb-button-secondary mgdb-button-sm" data-fs-open="' + best.n + '">Superpose</button>'
        + '<button type="button" class="mgdb-button mgdb-button-quiet mgdb-button-sm" data-fs-species="' + escape(species.key) + '">'
        + 'List ' + species.count + '</button></td>'
        + '</tr>';
    }).join('');
    return '<div class="fs-block fs-species">'
      + '<div class="fs-block-head"><h3>Closest match in each proteome</h3></div>'
      + '<div class="mgdb-table-scroll"><table class="mgdb-table fs-species-table">'
      + '<caption class="mgdb-visually-hidden">The strongest Foldseek match to ' + escape(name) + ' in each of the ' + SET.proteomes + '</caption>'
      + '<thead><tr><th scope="col">Proteome</th><th scope="col" class="mgdb-numeric">Matches</th>'
      + '<th scope="col">Closest match</th><th scope="col" class="mgdb-numeric">Identity</th>'
      + '<th scope="col" class="mgdb-numeric">E-value</th><th scope="col">Span on ' + escape(name) + '</th>'
      + '<th scope="col"><span class="mgdb-visually-hidden">Actions</span></th></tr></thead>'
      + '<tbody>' + rows + '</tbody></table></div></div>';
  }

  function noMatchesMarkup(data) {
    return '<div class="fs-block fs-nomatch">'
      + '<div class="fs-block-head"><h3>No structural matches</h3></div>'
      + '<p class="fs-nomatch-text">Foldseek kept no match for ' + escape(proteinName(data.protein))
      + SET.noMatchTail + '</p>'
      + domainTableMarkup(data)
      + '</div>';
  }

  function coverageMarkup(data) {
    var name = proteinName(data.protein);
    var legend = IDENTITY.slice().reverse().map(function (bin) {
      return '<span><i style="background:' + bin.color + '"></i>' + escape(bin.label) + '</span>';
    }).join('') + '<span><i class="fs-swatch-empty"></i>no match</span>';

    return '<div class="fs-block fs-coverage">'
      + '<div class="fs-block-head"><h3>Where the matches align on ' + escape(name) + '</h3></div>'
      + '<div class="fs-coverage-figure" data-fs-coverage></div>'
      + '<p class="fs-legend fs-coverage-legend"><span class="fs-legend-title">Highest identity at each position</span>' + legend + '</p>'
      + '<p class="fs-caption">One row per proteome, along the length of ' + escape(name)
      + '. Select a row to list that proteome’s matches.</p>'
      + domainTableMarkup(data)
      + '</div>';
  }

  function domainTableMarkup(data) {
    if (!SET.pfam) { return ''; }
    var name = proteinName(data.protein);
    var domains = '';
    if (data.domains && data.domains.length) {
      domains = '<div class="mgdb-table-scroll fs-domain-scroll"><table class="mgdb-table fs-domain-table">'
        + '<caption class="mgdb-visually-hidden">Pfam domains on ' + escape(name) + '</caption>'
        + '<thead><tr><th scope="col">Pfam domain</th><th scope="col">Type</th><th scope="col">Residues</th>'
        + '<th scope="col" class="mgdb-numeric">Bit score</th><th scope="col" class="mgdb-numeric">E-value</th>'
        + '<th scope="col">Clan</th></tr></thead><tbody>'
        + data.domains.map(function (d, i) {
            return '<tr><th scope="row"><i class="fs-domain-key" style="background:'
              + DOMAIN_COLORS[i % DOMAIN_COLORS.length] + '"></i>'
              + '<a href="https://www.ebi.ac.uk/interpro/entry/pfam/' + encodeURIComponent(d.pfam) + '/">'
              + escape(d.pfam) + '</a> ' + escape(d.name) + '</th>'
              + '<td>' + escape(d.type || '—') + '</td>'
              + '<td>' + fmtRange(d.start, d.end) + '</td>'
              + '<td class="mgdb-numeric">' + (d.bits === null ? '—' : escape(String(d.bits))) + '</td>'
              + '<td class="mgdb-numeric">' + fmtE(d.evalue) + '</td>'
              + '<td>' + (d.clan ? '<a href="https://www.ebi.ac.uk/interpro/set/pfam/' + encodeURIComponent(d.clan) + '/">'
                  + escape(d.clan) + '</a>' : '—') + '</td></tr>';
          }).join('')
        + '</tbody></table></div>';
    } else {
      domains = '<p class="fs-caption">No Pfam domain was reported for this protein.</p>';
    }
    return domains;
  }

  var COLUMNS = [
    { key: 'target',     label: 'Match',           type: 'text' },
    { key: 'species',    label: 'Proteome',        type: 'text' },
    { key: 'annotation', label: 'Annotation',      type: 'text' },
    { key: 'identity',   label: 'Identity',        type: 'number', numeric: true, first: 'desc' },
    { key: 'score',      label: 'Score',           type: 'number', numeric: true, first: 'desc' },
    { key: 'evalue',     label: 'E-value',         type: 'number', numeric: true, first: 'asc' },
    { key: 'qcov',       label: 'Span on query',   type: 'number', first: 'desc' },
    { key: 'tcov',       label: 'Match residues',  type: 'number', numeric: true, first: 'desc' },
    { key: null,         label: '<span class="mgdb-visually-hidden">Superposition</span>' }
  ];

  function tableMarkup(data) {
    var name = proteinName(data.protein);
    var chips = ['<button class="mgdb-chip" type="button" data-fs-chip="all" aria-pressed="true">All '
      + '<span class="fs-chip-count">' + data.hits.length + '</span></button>'];
    data.species.forEach(function (species) {
      if (!species.count) { return; }
      chips.push('<button class="mgdb-chip" type="button" data-fs-chip="' + escape(species.key) + '" aria-pressed="false">'
        + escape(species.label) + ' <span class="fs-chip-count">' + species.count + '</span></button>');
    });
    var head = COLUMNS.map(function (column) {
      if (!column.key) { return '<th scope="col">' + column.label + '</th>'; }
      return '<th scope="col" data-fs-sort="' + column.key + '"' + (column.numeric ? ' class="mgdb-numeric"' : '')
        + ' aria-sort="' + (column.key === 'evalue' ? 'ascending' : 'none') + '">'
        + '<button type="button">' + column.label + '</button></th>';
    }).join('');

    return '<div class="fs-block fs-hits" id="fs-hits">'
      + '<div class="fs-block-head"><h3>All matches</h3></div>'
      + '<div class="mgdb-results-header">'
      + '<p class="mgdb-results-status" data-fs-count aria-live="polite"></p>'
      + '<div class="mgdb-results-controls">'
      + '<label class="fs-inline-field"><span>Filter</span>'
      + '<input type="search" data-fs-filter placeholder="Accession, gene or annotation" autocomplete="off" spellcheck="false" /></label>'
      + '<label class="fs-inline-field"><span>Show</span><select data-fs-pagesize>'
      + '<option value="10">10</option><option value="25" selected>25</option><option value="50">50</option>'
      + '<option value="0">All</option></select></label>'
      + '<button type="button" class="mgdb-button mgdb-button-quiet mgdb-button-sm" data-fs-export>Download TSV</button>'
      + '</div></div>'
      + '<div class="mgdb-filters fs-chips" role="group" aria-label="Show matches from">' + chips.join('') + '</div>'
      + '<div class="mgdb-table-scroll fs-hits-scroll"><table class="mgdb-table fs-hits-table">'
      + '<caption class="mgdb-visually-hidden">Foldseek matches for ' + escape(name) + '</caption>'
      + '<thead><tr>' + head + '</tr></thead><tbody data-fs-rows></tbody></table></div>'
      + '<div class="mgdb-empty fs-filter-empty" data-fs-empty hidden><p><strong>No match in this list fits that filter.</strong></p>'
      + '<button type="button" class="mgdb-button mgdb-button-secondary" data-fs-clear>Clear the filter</button></div>'
      + '<nav class="mgdb-pagination fs-pager" data-fs-pager aria-label="Pages of matches"></nav>'
      + '</div>';
  }

  /* ----------------------------------------------------------------------
   * The match table
   * ---------------------------------------------------------------------- */

  function compareBy(a, b, key) {
    switch (key) {
      case 'target':     return a.target < b.target ? -1 : (a.target > b.target ? 1 : 0);
      case 'species':    return a.speciesIndex - b.speciesIndex;
      case 'annotation': return String(a.annotation || '').localeCompare(String(b.annotation || ''));
      case 'identity':   return a.identity - b.identity;
      case 'score':      return a.score - b.score;
      case 'evalue':     return a.evalue - b.evalue;
      case 'qcov':       return a.qcov - b.qcov;
      case 'tcov':       return a.tcov - b.tcov;
    }
    return 0;
  }

  function currentList() {
    var filter = state.filter;
    var text = filter.text.toLowerCase();
    var list = state.hits.filter(function (hit) {
      if (filter.species !== 'all' && hit.species !== filter.species) { return false; }
      return !text || hit.haystack.indexOf(text) !== -1;
    });
    var key = state.sort.key;
    var dir = state.sort.dir === 'asc' ? 1 : -1;
    list.sort(function (a, b) {
      var c = compareBy(a, b, key) * dir;
      /* Ties fall back to Foldseek's own ranking, whichever way the column
         runs, so equal rows never shuffle between renders. */
      return c || (a.evalue - b.evalue) || (b.score - a.score) || (a.n - b.n);
    });
    return list;
  }

  function rowMarkup(hit) {
    var species = state.speciesByKey[hit.species];
    var open = state.openN === hit.n;
    return '<tr data-n="' + hit.n + '"' + (open ? ' class="is-open"' : '') + '>'
      + '<th scope="row" class="fs-col-match"><a href="' + escape(uniprotUrl(hit.target)) + '">' + escape(hit.target) + '</a>'
      + (hit.gene ? geneLink(hit) : '') + '</th>'
      + '<td class="fs-col-species">' + escape(species ? species.label : '—') + '</td>'
      + '<td class="fs-col-annotation">' + escape(tidy(hit.annotation) || '—') + '</td>'
      + '<td class="mgdb-numeric">' + fmtPct(hit.identity) + '</td>'
      + '<td class="mgdb-numeric">' + fmtInt(hit.score) + '</td>'
      + '<td class="mgdb-numeric fs-col-e">' + fmtE(hit.evalue) + '</td>'
      + '<td class="fs-col-cov">' + covBar(hit.q_start, hit.q_end, hit.q_len,
          'Aligns residues ' + hit.q_start + ' to ' + hit.q_end + ' of ' + hit.q_len)
      + '<span class="fs-range">' + fmtRange(hit.q_start, hit.q_end) + '</span></td>'
      + '<td class="mgdb-numeric fs-col-trange">' + fmtRange(hit.t_start, hit.t_end)
      + '<span class="fs-of">of ' + fmtInt(hit.t_len) + '</span></td>'
      + '<td class="fs-col-action"><button type="button" class="mgdb-button mgdb-button-sm '
      + (open ? 'mgdb-button-primary' : 'mgdb-button-secondary') + '" data-fs-open="' + hit.n + '" '
      + 'aria-expanded="' + (open ? 'true' : 'false') + '" aria-controls="fs-detail">'
      + (open ? 'Close' : 'Superpose') + '</button></td>'
      + '</tr>';
  }

  function renderTable() {
    var body = els.resultsBody.querySelector('[data-fs-rows]');
    if (!body) { return; }
    var list = currentList();
    var size = state.pageSize || list.length || 1;
    var pages = Math.max(1, Math.ceil(list.length / size));
    if (state.page > pages) { state.page = pages; }
    var start = (state.page - 1) * size;
    var slice = list.slice(start, start + size);

    var html = '';
    var openHere = false;
    slice.forEach(function (hit) {
      html += rowMarkup(hit);
      if (hit.n === state.openN) {
        openHere = true;
        html += '<tr class="fs-detail-row"><td colspan="' + COLUMNS.length + '" data-fs-detail-cell></td></tr>';
      }
    });
    body.innerHTML = html;
    if (openHere) {
      body.querySelector('[data-fs-detail-cell]').appendChild(els.detail);
      sizeDetail();
      resizeSup();
    }

    var empty = els.resultsBody.querySelector('[data-fs-empty]');
    if (empty) { empty.hidden = list.length > 0; }

    var count = els.resultsBody.querySelector('[data-fs-count]');
    if (count) {
      var species = state.filter.species !== 'all' ? state.speciesByKey[state.filter.species] : null;
      var scope = species ? ' from ' + species.label : '';
      var total = species ? species.count : state.hits.length;
      var text;
      if (!list.length) {
        text = 'No matches' + scope + ' fit the filter.';
      } else if (state.filter.text) {
        text = list.length + ' of ' + total + ' matches' + scope + ' fit “' + state.filter.text + '”'
          + (pages > 1 ? '; showing ' + (start + 1) + '–' + (start + slice.length) : '') + '.';
      } else {
        text = 'Showing ' + (start + 1) + '–' + (start + slice.length) + ' of ' + list.length + ' matches' + scope + '.';
      }
      count.textContent = text;
    }

    renderPager(pages);

    Array.prototype.forEach.call(els.resultsBody.querySelectorAll('th[data-fs-sort]'), function (th) {
      var key = th.getAttribute('data-fs-sort');
      th.setAttribute('aria-sort', key === state.sort.key ? (state.sort.dir === 'asc' ? 'ascending' : 'descending') : 'none');
    });
  }

  function renderPager(pages) {
    var pager = els.resultsBody.querySelector('[data-fs-pager]');
    if (!pager) { return; }
    if (pages <= 1) { pager.innerHTML = ''; pager.hidden = true; return; }
    pager.hidden = false;
    var items = [];
    var current = state.page;
    function button(page, label, disabled, ariaLabel) {
      if (disabled) { return '<span aria-disabled="true">' + label + '</span>'; }
      return '<a href="#fs-hits" data-fs-page="' + page + '"' + (ariaLabel ? ' aria-label="' + ariaLabel + '"' : '')
        + (page === current && label === String(page) ? ' aria-current="page"' : '') + '>' + label + '</a>';
    }
    items.push(button(current - 1, '← Previous', current <= 1, 'Previous page'));
    for (var page = 1; page <= pages; page++) {
      if (page === 1 || page === pages || Math.abs(page - current) <= 1) {
        items.push(button(page, String(page), false, 'Page ' + page));
      } else if (Math.abs(page - current) === 2) {
        items.push('<span aria-hidden="true">…</span>');
      }
    }
    items.push(button(current + 1, 'Next →', current >= pages, 'Next page'));
    pager.innerHTML = items.join('');
  }

  function setSpecies(key, scroll) {
    state.filter.species = key;
    state.page = 1;
    Array.prototype.forEach.call(els.resultsBody.querySelectorAll('[data-fs-chip]'), function (chip) {
      chip.setAttribute('aria-pressed', chip.getAttribute('data-fs-chip') === key ? 'true' : 'false');
    });
    renderTable();
    if (scroll) {
      var target = els.resultsBody.querySelector('#fs-hits');
      if (target && target.scrollIntoView) {
        target.scrollIntoView({ block: 'start', behavior: MGDB.prefersReducedMotion() ? 'auto' : 'smooth' });
      }
    }
  }

  function exportTsv() {
    var p = state.data.protein;
    var columns = ['query', 'query_uniprot', 'match', 'proteome', 'species', SET.geneColumn, 'annotation',
      'identity', 'alignment_length', 'mismatches', 'gap_openings', 'query_start', 'query_end', 'query_length',
      'match_start', 'match_end', 'match_length', 'evalue', 'score'];
    var clean = function (value) { return value === null || value === undefined ? '' : String(value).replace(/[\t\r\n]+/g, ' '); };
    var lines = [columns.join('\t')];
    currentList().forEach(function (hit) {
      var species = state.speciesByKey[hit.species] || {};
      lines.push([proteinName(p), p.uniprot, hit.target, species.label, species.latin, hit.gene, hit.annotation,
        hit.identity, hit.aln_len, hit.mismatch, hit.gap_open, hit.q_start, hit.q_end, hit.q_len,
        hit.t_start, hit.t_end, hit.t_len, hit.evalue, hit.score].map(clean).join('\t'));
    });
    var suffix = state.filter.species !== 'all' ? '_' + state.filter.species : '';
    saveFile('foldseek_' + p.uniprot + suffix + '.tsv', lines.join('\n') + '\n', 'text/tab-separated-values');
  }

  /* ----------------------------------------------------------------------
   * Coverage figure
   *
   * Drawn at the container's own width rather than scaled from a fixed
   * viewBox, so the labels stay at reading size on a phone.
   * ---------------------------------------------------------------------- */

  function niceStep(length, target) {
    var raw = length / target;
    var steps = [10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000, 2500, 5000];
    for (var i = 0; i < steps.length; i++) { if (steps[i] >= raw) { return steps[i]; } }
    return steps[steps.length - 1];
  }

  function identityBin(value) {
    for (var i = 0; i < IDENTITY.length; i++) { if (value >= IDENTITY[i].min) { return i; } }
    return IDENTITY.length - 1;
  }

  function drawCoverage() {
    var host = els.resultsBody.querySelector('[data-fs-coverage]');
    if (!host || !state.data) { return; }
    var width = Math.floor(host.getBoundingClientRect().width);
    if (width < 10) { return; }
    var data = state.data;
    var length = data.protein.length || (state.hits[0] ? state.hits[0].q_len : 0);
    if (!length) { host.innerHTML = ''; return; }

    var narrow = width < 560;
    var left = narrow ? 96 : 136;
    var right = 34;
    var top = 26;
    var rowH = narrow ? 15 : 17;
    var gap = 7;
    var plotW = Math.max(60, width - left - right);
    var scale = plotW / length;
    var xAt = function (pos) { return left + (pos - 1) * scale; };
    var rows = data.species;
    var domainTop = top;
    var firstRow = SET.pfam ? domainTop + rowH + gap + 4 : top + 4;
    var height = firstRow + rows.length * (rowH + gap) + 4;
    var name = proteinName(data.protein);

    var svg = [];
    svg.push('<svg class="fs-coverage-svg" width="' + width + '" height="' + height + '" viewBox="0 0 ' + width + ' ' + height
      + '" role="img" aria-labelledby="fs-cov-title fs-cov-desc">');
    svg.push('<title id="fs-cov-title">Where the Foldseek matches align on ' + escape(name) + '</title>');
    svg.push('<desc id="fs-cov-desc">' + escape(rows.map(function (s) { return s.label + ' ' + s.count; }).join(', ')) + '</desc>');

    /* Axis */
    var step = niceStep(length, narrow ? 4 : 8);
    svg.push('<line class="fs-cov-axis" x1="' + left + '" x2="' + (left + plotW) + '" y1="' + (top - 8) + '" y2="' + (top - 8) + '"/>');
    var ticks = [1];
    for (var t = step; t < length; t += step) { ticks.push(t); }
    ticks.push(length);
    ticks.forEach(function (pos, index) {
      if (index === ticks.length - 1 && ticks.length > 1 && (pos - ticks[index - 1]) * scale < 28) { ticks[index - 1] = null; }
    });
    ticks.forEach(function (pos) {
      if (pos === null) { return; }
      var x = xAt(pos) + (pos === length ? scale : 0);
      svg.push('<line class="fs-cov-tick" x1="' + x.toFixed(1) + '" x2="' + x.toFixed(1) + '" y1="' + (top - 11) + '" y2="' + (top - 5) + '"/>');
      svg.push('<text class="fs-cov-ticklabel" x="' + x.toFixed(1) + '" y="' + (top - 14) + '" text-anchor="'
        + (pos === 1 ? 'start' : (pos === length ? 'end' : 'middle')) + '">' + pos + '</text>');
    });

    /* Pfam track */
    if (SET.pfam) {
      svg.push('<text class="fs-cov-label" x="' + (left - 8) + '" y="' + (domainTop + rowH - 4) + '" text-anchor="end">Pfam</text>');
      svg.push('<rect class="fs-cov-track" x="' + left + '" y="' + domainTop + '" width="' + plotW + '" height="' + rowH + '" rx="3"/>');
    }
    (SET.pfam ? data.domains || [] : []).forEach(function (d, i) {
      var x = xAt(d.start);
      var w = Math.max(2, (d.end - d.start + 1) * scale);
      svg.push('<rect x="' + x.toFixed(1) + '" y="' + domainTop + '" width="' + w.toFixed(1) + '" height="' + rowH
        + '" rx="3" fill="' + DOMAIN_COLORS[i % DOMAIN_COLORS.length] + '"><title>' + escape(d.pfam + ' ' + d.name + ', residues '
        + d.start + '–' + d.end) + '</title></rect>');
      if (w > d.name.length * 6.6 + 10) {
        svg.push('<text class="fs-cov-domain" x="' + (x + w / 2).toFixed(1) + '" y="' + (domainTop + rowH - 4.5) + '" text-anchor="middle">'
          + escape(d.name) + '</text>');
      }
    });

    /* One row per proteome: runs of positions sharing an identity band. */
    rows.forEach(function (species, index) {
      var y = firstRow + index * (rowH + gap);
      var best = new Float32Array(length + 2);
      for (var i = 0; i < best.length; i++) { best[i] = -1; }
      state.hits.forEach(function (hit) {
        if (hit.species !== species.key) { return; }
        for (var pos = Math.max(1, hit.q_start); pos <= Math.min(length, hit.q_end); pos++) {
          if (hit.identity > best[pos]) { best[pos] = hit.identity; }
        }
      });
      var label = species.count
        ? 'List the ' + species.count + ' ' + species.label + ' matches'
        : species.label + ': no match';
      svg.push('<g class="fs-cov-row' + (species.count ? '' : ' is-empty') + '"'
        + (species.count ? ' data-fs-species="' + escape(species.key) + '" tabindex="0" role="button" aria-label="' + escape(label) + '"' : '')
        + '>');
      svg.push('<text class="fs-cov-label" x="' + (left - 8) + '" y="' + (y + rowH - 4) + '" text-anchor="end">' + escape(species.label) + '</text>');
      svg.push('<rect class="fs-cov-track" x="' + left + '" y="' + y + '" width="' + plotW + '" height="' + rowH + '" rx="3"/>');
      var runStart = 1;
      var runBin = best[1] >= 0 ? identityBin(best[1]) : -1;
      for (var pos = 2; pos <= length + 1; pos++) {
        var bin = pos <= length && best[pos] >= 0 ? identityBin(best[pos]) : -1;
        if (bin !== runBin || pos === length + 1) {
          if (runBin >= 0) {
            svg.push('<rect x="' + xAt(runStart).toFixed(2) + '" y="' + y + '" width="' + Math.max(0.8, (pos - runStart) * scale).toFixed(2)
              + '" height="' + rowH + '" fill="' + IDENTITY[runBin].color + '"/>');
          }
          runStart = pos;
          runBin = bin;
        }
      }
      svg.push('<text class="fs-cov-count" x="' + (left + plotW + 8) + '" y="' + (y + rowH - 4) + '">' + species.count + '</text>');
      svg.push('<rect class="fs-cov-hit" x="0" y="' + (y - 3) + '" width="' + width + '" height="' + (rowH + 6) + '"/>');
      svg.push('</g>');
    });
    svg.push('</svg>');
    host.innerHTML = svg.join('');
  }

  /* ----------------------------------------------------------------------
   * The searched protein's own structure
   * ---------------------------------------------------------------------- */

  function parseModel(text) {
    var seq = [];
    var plddt = {};
    text.split('\n').forEach(function (line) {
      if (line.indexOf('ATOM') !== 0 || line.substr(12, 4).trim() !== 'CA') { return; }
      var resi = parseInt(line.substr(22, 4), 10);
      seq.push(AA1[line.substr(17, 3).trim()] || 'X');
      plddt[resi] = parseFloat(line.substr(60, 6));
    });
    return { sequence: seq.join(''), plddt: plddt };
  }

  function plddtColor(value) {
    for (var i = 0; i < PLDDT.length; i++) { if (value > PLDDT[i].min || i === PLDDT.length - 1) { return PLDDT[i].color; } }
    return PLDDT[PLDDT.length - 1].color;
  }

  function domainIndexAt(resi) {
    var domains = state.data.domains || [];
    for (var i = 0; i < domains.length; i++) {
      if (resi >= domains[i].start && resi <= domains[i].end) { return i; }
    }
    return -1;
  }

  function legendMarkup(rows) {
    return rows.map(function (row) {
      return '<span><i style="background:' + row.color + '"></i>' + escape(row.label) + '</span>';
    }).join('');
  }

  function styleQuery() {
    var viewer = state.queryViewer;
    if (!viewer || !state.queryModel) { return; }
    var scheme = state.queryScheme;
    var legend = els.resultsBody.querySelector('[data-fs-qlegend]');
    viewer.setStyle({}, {});
    if (scheme === 'domains' && state.querySameSequence) {
      viewer.setStyle({}, { cartoon: { colorfunc: function (atom) {
        var index = domainIndexAt(atom.resi);
        return index < 0 ? DOMAIN_NONE : DOMAIN_COLORS[index % DOMAIN_COLORS.length];
      } } });
      if (legend) {
        legend.innerHTML = legendMarkup((state.data.domains || []).map(function (d, i) {
          return { color: DOMAIN_COLORS[i % DOMAIN_COLORS.length], label: d.name + ' ' + d.start + '–' + d.end };
        }).concat([{ color: DOMAIN_NONE, label: 'outside a domain' }]));
      }
    } else {
      viewer.setStyle({}, { cartoon: { colorfunc: function (atom) { return plddtColor(atom.b); } } });
      if (legend) { legend.innerHTML = '<span class="fs-legend-title">pLDDT</span>' + legendMarkup(PLDDT); }
    }
    viewer.render();
  }

  function initQueryViewer() {
    var slot = els.resultsBody.querySelector('[data-fs-slot="query"]');
    if (!slot) { return; }
    slot.insertBefore(els.queryStage, slot.firstChild);
    var status = slot.querySelector('[data-fs-qstatus]');
    if (!window.$3Dmol) {
      if (status) { status.textContent = 'The 3D viewer could not be loaded.'; }
      return;
    }
    if (!state.queryViewer) {
      state.queryViewer = window.$3Dmol.createViewer(els.queryStage, { backgroundColor: VIEWER_BG, antialias: true });
    }
    var viewer = state.queryViewer;
    viewer.clear();
    viewer.resize();
    state.queryModel = null;
    state.queryScheme = 'plddt';
    state.querySameSequence = null;
    var mine = state.serial;
    var protein = state.data.protein;
    var caption = els.resultsBody.querySelector('[data-fs-qcaption]');
    var domainsButton = els.resultsBody.querySelector('[data-fs-qscheme="domains"]');
    if (domainsButton && !(state.data.domains || []).length) {
      domainsButton.disabled = true;
      domainsButton.title = '';
    }

    window.fetch((state.data.links && state.data.links.model) || modelUrl(protein.uniprot), { mode: 'cors' })
      .then(function (response) {
        if (!response.ok) { throw new Error(String(response.status)); }
        return response.text();
      })
      .then(function (text) {
        if (mine !== state.serial) { return; }
        var parsed = parseModel(text);
        state.queryPlddt = parsed.plddt;
        state.querySameSequence = parsed.sequence === protein.sequence;
        state.queryModel = viewer.addModel(text, 'pdb');
        styleQuery();
        viewer.zoomTo();
        viewer.resize();
        viewer.render();
        if (status) { status.hidden = true; }
        if (caption && state.data.model_label) {
          /* The Fusarium model is the searched file itself, so there is no
             second version to compare it with. */
          caption.textContent = state.data.model_label + '.';
        } else if (caption) {
          caption.textContent = 'AlphaFold DB model AF-' + protein.uniprot + '-F1, version '
            + String(state.data.af_version).replace(/^v/, '') + '. '
            + (state.querySameSequence
                ? 'Same sequence as the version 3 model the search used.'
                : 'Its sequence differs from the version 3 model the search used, so the Pfam positions are not drawn on it.');
        }
        if (domainsButton && !state.querySameSequence) { domainsButton.disabled = true; }
      })
      .catch(function () {
        if (mine !== state.serial) { return; }
        if (status) {
          status.hidden = false;
          status.textContent = state.data.model_label
            ? 'The model file could not be read from ' + SET.host + '.'
            : 'The AlphaFold Database did not return this model. It may have been withdrawn with its UniProt entry.';
        }
      });
  }

  /* ----------------------------------------------------------------------
   * One match: alignment and superposition
   * ---------------------------------------------------------------------- */

  function openHit(n, options) {
    options = options || {};
    var hit = hitByN(n);
    if (!hit) { return; }
    if (state.openN === n && !options.force) { closeHit(); return; }
    stopSupSpin();
    state.openN = n;

    var list = currentList();
    var index = -1;
    for (var i = 0; i < list.length; i++) { if (list[i].n === n) { index = i; break; } }
    if (index === -1) {
      /* The match is filtered out -- a link from the proteome table while a
         different proteome is listed, say. Show everything rather than open
         a panel nobody can see. */
      state.filter = { species: 'all', text: '' };
      syncFilterControls();
      list = currentList();
      for (var j = 0; j < list.length; j++) { if (list[j].n === n) { index = j; break; } }
    }
    if (index < 0) { state.openN = null; return; }
    if (state.pageSize) { state.page = Math.floor(index / state.pageSize) + 1; }

    loadDetail(hit);
    renderTable();
    writeUrl();

    if (options.scroll !== false) {
      window.setTimeout(function () {
        if (els.detail.scrollIntoView) {
          els.detail.scrollIntoView({ block: 'start', behavior: MGDB.prefersReducedMotion() ? 'auto' : 'smooth' });
        }
        var heading = els.detail.querySelector('[data-fs-detail-title]');
        if (heading) { heading.focus({ preventScroll: true }); }
      }, 30);
    }
  }

  function closeHit() {
    stopSupSpin();
    state.openN = null;
    state.detailSerial++;
    state.sup = null;
    renderTable();
    writeUrl();
  }

  function syncFilterControls() {
    var input = els.resultsBody.querySelector('[data-fs-filter]');
    if (input) { input.value = state.filter.text; }
    Array.prototype.forEach.call(els.resultsBody.querySelectorAll('[data-fs-chip]'), function (chip) {
      chip.setAttribute('aria-pressed', chip.getAttribute('data-fs-chip') === state.filter.species ? 'true' : 'false');
    });
  }

  function detailMarkup(hit) {
    var species = state.speciesByKey[hit.species];
    var name = proteinName(state.data.protein);
    var links = SET.matchLinks(hit).map(function (link) {
      return '<a class="mgdb-button mgdb-button-quiet mgdb-button-sm" href="' + escape(link[1]) + '">' + escape(link[0]) + '</a>';
    });
    if (hit.gene && SET === SETS.maize) {
      links.unshift('<a class="mgdb-button mgdb-button-quiet mgdb-button-sm" href="' + escape(geneUrl(hit.gene)) + '">Gene record</a>');
    }
    if (SET.searchable(hit)) {
      links.push('<a class="mgdb-button mgdb-button-quiet mgdb-button-sm" href="' + escape(SET.route) + '?uniprot='
        + encodeURIComponent(hit.target) + '">Its own matches</a>');
    }
    return '<div class="fs-detail-head">'
      + '<h4 data-fs-detail-title tabindex="-1">' + escape(name) + ' <span>and</span> ' + escape(hit.target)
      + ' <span>' + escape(species ? species.label : '') + '</span></h4>'
      + '<button type="button" class="mgdb-button mgdb-button-quiet mgdb-button-sm" data-fs-close>Close</button>'
      + '</div>'
      + '<p class="fs-detail-annot">' + escape(tidy(hit.annotation) || SET.noAnnotation) + '</p>'
      + '<div class="fs-detail-grid">'
      + '<div class="fs-viewer fs-sup">'
      + '<div class="fs-viewer-bar">'
      + '<label>Color <select data-fs-scheme><option value="chain">by protein</option>'
      + '<option value="distance">by distance between aligned residues</option></select></label>'
      + '<label class="fs-check"><input type="checkbox" data-fs-full /> whole chains</label>'
      + '<label class="fs-check"><input type="checkbox" data-fs-pairs /> aligned pairs</label>'
      + '</div>'
      + '<div class="fs-viewer-stage" data-fs-slot="sup"><p class="fs-viewer-status" data-fs-supstatus>Loading the alignment&hellip;</p></div>'
      + '<div class="fs-viewer-foot"><div class="fs-legend" data-fs-suplegend></div>'
      + '<div class="fs-viewer-actions"><button type="button" data-fs-supreset>Reset view</button>'
      + '<button type="button" data-fs-supspin aria-pressed="false">Spin</button></div></div>'
      + '</div>'
      + '<dl class="fs-metrics" data-fs-metrics>' + metricsMarkup(hit, null) + '</dl>'
      + '</div>'
      + '<div class="fs-aln-block"><h5>Alignment</h5><pre class="fs-aln" data-fs-aln>Loading&hellip;</pre>'
      + '<p class="fs-caption">Between the rows, a letter marks an identical residue and + a similar one (BLOSUM62). '
      + 'Once the structures are superposed, each aligned residue of the match is shaded by its distance to its partner.</p></div>'
      + '<p class="fs-detail-actions"><button type="button" class="mgdb-button mgdb-button-secondary mgdb-button-sm" data-fs-pdb disabled>'
      + 'Download superposition (PDB)</button>'
      + '<button type="button" class="mgdb-button mgdb-button-quiet mgdb-button-sm" data-fs-png disabled>Save image (PNG)</button>'
      + links.join('') + '</p>';
  }

  function metric(term, value, note) {
    return '<div><dt>' + term + '</dt><dd>' + value + '</dd>' + (note ? '<p>' + note + '</p>' : '') + '</div>';
  }

  function metricsMarkup(hit, sup) {
    var tm = sup && sup.tm;
    var within = null;
    if (sup && sup.distances && sup.distances.length) {
      within = sup.distances.filter(function (d) { return d < 2; }).length / sup.distances.length;
    }
    return metric('TM-score', tm ? tm.tmTarget.toFixed(3) : (sup && sup.tmError ? '—' : '<span class="fs-pending">computing</span>'),
        'normalized by the match’s ' + fmtInt(hit.t_end - hit.t_start + 1) + ' aligned residues')
      + metric('TM-score, ' + SET.queryNoun, tm ? tm.tmQuery.toFixed(3) : '—',
        'normalized by its ' + fmtInt(hit.q_end - hit.q_start + 1) + ' aligned residues')
      + metric('RMSD', tm ? tm.rmsd.toFixed(2) + ' Å' : '—',
        tm ? 'over ' + fmtInt(tm.aligned) + ' aligned pairs' : 'over the aligned pairs')
      + metric('Within 2 Å', within === null ? '—' : fmtPct(within, 0), 'of aligned pairs, superposed')
      + metric('Identity', fmtPct(hit.identity), fmtInt(hit.aln_len) + ' alignment columns')
      + metric('E-value', fmtE(hit.evalue), 'score ' + fmtInt(hit.score) + ' bits');
  }

  function parseCoordinates(text) {
    return String(text || '').split(',').map(Number);
  }

  function loadDetail(hit) {
    var mine = ++state.detailSerial;
    state.sup = null;
    els.detail.innerHTML = detailMarkup(hit);
    var slot = els.detail.querySelector('[data-fs-slot="sup"]');
    slot.insertBefore(els.supStage, slot.firstChild);
    if (state.supViewer) { state.supViewer.clear(); state.supViewer.removeAllShapes(); state.supViewer.render(); }
    state.supOptions = { scheme: 'chain', full: false, pairs: false, spin: false };
    bindDetail();

    MGDB.request(apiUrl('action=hit&acc=' + encodeURIComponent(state.data.accession) + '&n=' + hit.n), { key: 'fs-hit' })
      .then(function (detail) {
        if (mine !== state.detailSerial) { return null; }
        var qCa = parseCoordinates(detail.q_ca);
        var tCa = parseCoordinates(detail.t_ca);
        var pairs = window.MGDBTMalign.pairsFromAlignment(detail.q_aln, detail.t_aln, hit.q_start, hit.t_start, qCa, tCa);
        var sup = {
          hit: hit, detail: detail, qCa: qCa, tCa: tCa, pairs: pairs,
          xlen: hit.t_end - hit.t_start + 1, ylen: hit.q_end - hit.q_start + 1,
          tm: null, distances: null, tRot: null
        };
        state.sup = sup;
        renderAlignment(sup);
        setSupStatus('Computing the TM-score&hellip;');
        return computeTm(sup).then(function (tm) {
          if (mine !== state.detailSerial) { return; }
          sup.tm = tm;
          applySuperposition(sup);
          renderAlignment(sup);
          refreshMetrics();
          drawSuperposition();
          Array.prototype.forEach.call(els.detail.querySelectorAll('[data-fs-pdb], [data-fs-png]'), function (button) {
            button.disabled = false;
          });
        }, function (error) {
          if (mine !== state.detailSerial) { return; }
          sup.tmError = String((error && error.message) || error);
          refreshMetrics();
          setSupStatus('The TM-score could not be computed for this match.');
        });
      })
      .catch(function (error) {
        if (mine !== state.detailSerial || (error && error.name === 'AbortError')) { return; }
        setSupStatus('The alignment for this match could not be loaded.');
        var aln = els.detail.querySelector('[data-fs-aln]');
        if (aln) { aln.textContent = 'Not available.'; }
      });
  }

  function setSupStatus(html) {
    var status = els.detail.querySelector('[data-fs-supstatus]');
    if (!status) { return; }
    status.hidden = !html;
    status.innerHTML = html || '';
  }

  function refreshMetrics() {
    var box = els.detail.querySelector('[data-fs-metrics]');
    if (box && state.sup) { box.innerHTML = metricsMarkup(state.sup.hit, state.sup); }
  }

  /* TM-align's final scoring, off the main thread. A job for a match the
     reader has already left is abandoned by replacing the worker, since a
     large protein's search runs for seconds and would otherwise hold up the
     next one. */
  function tmalignUrl() {
    var script = document.querySelector('script[src*="mgdb-tmalign.js"]');
    return script ? script.src : window.location.origin + '/js/mgdb-tmalign.js';
  }

  function makeWorker() {
    if (!window.Worker || !window.Blob || !window.URL) { return null; }
    try {
      var source = 'importScripts(' + JSON.stringify(tmalignUrl()) + ');'
        + 'self.onmessage=function(e){var d=e.data;try{'
        + 'self.postMessage({id:d.id,ok:true,result:self.MGDBTMalign.scoreFixed(d.x,d.y,d.xlen,d.ylen)});'
        + '}catch(err){self.postMessage({id:d.id,ok:false,error:String(err&&err.message||err)});}};';
      return new window.Worker(window.URL.createObjectURL(new window.Blob([source], { type: 'application/javascript' })));
    } catch (e) {
      return null;
    }
  }

  function computeTm(sup) {
    var x = sup.pairs.target;
    var y = sup.pairs.query;
    if (x.length < 3) { return Promise.reject(new Error('fewer than three aligned pairs')); }
    if (state.workerBusy && state.worker) {
      state.worker.terminate();
      state.worker = null;
    }
    if (!state.worker) { state.worker = makeWorker(); }
    var worker = state.worker;
    if (!worker) {
      return new Promise(function (resolve, reject) {
        window.setTimeout(function () {
          try { resolve(window.MGDBTMalign.scoreFixed(x, y, sup.xlen, sup.ylen)); } catch (e) { reject(e); }
        }, 30);
      });
    }
    var id = ++state.tmJob;
    state.workerBusy = true;
    return new Promise(function (resolve, reject) {
      function done(event) {
        if (!event.data || event.data.id !== id) { return; }
        worker.removeEventListener('message', done);
        worker.removeEventListener('error', failed);
        state.workerBusy = false;
        if (event.data.ok) { resolve(event.data.result); } else { reject(new Error(event.data.error)); }
      }
      function failed(event) {
        worker.removeEventListener('message', done);
        worker.removeEventListener('error', failed);
        state.workerBusy = false;
        state.worker = null;
        /* A worker that cannot start -- importScripts refused, say -- is not
           a reason to go without the score. */
        try { resolve(window.MGDBTMalign.scoreFixed(x, y, sup.xlen, sup.ylen)); }
        catch (e) { reject(new Error((event && event.message) || 'worker failed')); }
      }
      worker.addEventListener('message', done);
      worker.addEventListener('error', failed);
      worker.postMessage({ id: id, x: x, y: y, xlen: sup.xlen, ylen: sup.ylen });
    });
  }

  function transform(t, u, p) {
    return [t[0] + u[0][0] * p[0] + u[0][1] * p[1] + u[0][2] * p[2],
            t[1] + u[1][0] * p[0] + u[1][1] * p[1] + u[1][2] * p[2],
            t[2] + u[2][0] * p[0] + u[2][1] * p[1] + u[2][2] * p[2]];
  }

  /* Move the whole match onto the searched protein with the rotation TM-align
     writes with -m, and measure each aligned pair in that frame. */
  function applySuperposition(sup) {
    var t = sup.tm.t;
    var u = sup.tm.u;
    var rotated = [];
    for (var i = 0; i + 2 < sup.tCa.length; i += 3) {
      rotated.push(transform(t, u, [sup.tCa[i], sup.tCa[i + 1], sup.tCa[i + 2]]));
    }
    sup.tRot = rotated;
    sup.distances = sup.pairs.target.map(function (x, k) {
      var p = transform(t, u, x);
      var q = sup.pairs.query[k];
      return Math.sqrt((p[0] - q[0]) * (p[0] - q[0]) + (p[1] - q[1]) * (p[1] - q[1]) + (p[2] - q[2]) * (p[2] - q[2]));
    });
  }

  function distanceBin(value) {
    for (var i = 0; i < DISTANCE.length; i++) { if (value < DISTANCE[i].max) { return i; } }
    return DISTANCE.length - 1;
  }

  function alignmentWidth() {
    var width = els.detail.getBoundingClientRect().width;
    return width && width < 720 ? 50 : 80;
  }

  function renderAlignment(sup) {
    var box = els.detail.querySelector('[data-fs-aln]');
    if (!box) { return; }
    var qa = sup.detail.q_aln;
    var ta = sup.detail.t_aln;
    var width = alignmentWidth();
    var qLabel = proteinName(state.data.protein);
    var tLabel = sup.hit.target;
    var pad = Math.max(qLabel.length, tLabel.length);
    var numberWidth = String(Math.max(sup.hit.q_end, sup.hit.t_end)).length;
    var qi = sup.hit.q_start;
    var ti = sup.hit.t_start;
    var pair = 0;
    var blocks = [];

    function padRight(text, n) { while (text.length < n) { text += ' '; } return text; }
    function padLeft(text, n) { text = String(text); while (text.length < n) { text = ' ' + text; } return text; }

    for (var start = 0; start < qa.length; start += width) {
      var qs = qa.substr(start, width);
      var ts = ta.substr(start, width);
      var qFirst = null, qLast = null, tFirst = null, tLast = null;
      var mid = '';
      var tHtml = '';
      for (var k = 0; k < qs.length; k++) {
        var qc = qs.charAt(k);
        var tc = ts.charAt(k);
        var qGap = qc === '-';
        var tGap = tc === '-';
        if (!qGap) { if (qFirst === null) { qFirst = qi; } qLast = qi; }
        if (!tGap) { if (tFirst === null) { tFirst = ti; } tLast = ti; }
        if (!qGap && !tGap) {
          mid += qc === tc ? qc : (SIMILAR[qc + tc] ? '+' : ' ');
          if (sup.distances) {
            tHtml += '<span class="' + DISTANCE[distanceBin(sup.distances[pair])].cls + '">' + escape(tc) + '</span>';
          } else {
            tHtml += escape(tc);
          }
          pair++;
        } else {
          mid += ' ';
          tHtml += escape(tc);
        }
        if (!qGap) { qi++; }
        if (!tGap) { ti++; }
      }
      var indent = padRight('', pad + numberWidth + 4);
      blocks.push(
        '<span class="fs-aln-label">' + escape(padRight(qLabel, pad)) + '</span> '
          + padLeft(qFirst === null ? qi - 1 : qFirst, numberWidth) + '  ' + escape(qs) + '  ' + (qLast === null ? '' : qLast) + '\n'
          + indent + '<span class="fs-aln-mid">' + escape(mid) + '</span>\n'
          + '<span class="fs-aln-label">' + escape(padRight(tLabel, pad)) + '</span> '
          + padLeft(tFirst === null ? ti - 1 : tFirst, numberWidth) + '  ' + tHtml + '  ' + (tLast === null ? '' : tLast)
      );
    }
    box.innerHTML = blocks.join('\n\n');
  }

  /* ----------------------------------------------------------------------
   * The superposition viewer
   *
   * A Calpha-only PDB per chain, with CONECT records between consecutive
   * residues so 3Dmol draws a tube: the search's coordinates are Calpha
   * atoms and nothing else, and 3Dmol's cartoon needs a backbone.
   * ---------------------------------------------------------------------- */

  function pdbLine(serial, resi, resn, chain, p) {
    return 'ATOM  ' + String(serial).padStart(5) + '  CA  ' + resn + ' ' + chain + String(resi).padStart(4) + '    '
      + p[0].toFixed(3).padStart(8) + p[1].toFixed(3).padStart(8) + p[2].toFixed(3).padStart(8)
      + '  1.00  0.00           C  ';
  }

  function chainPdb(points, sequence, chain, withConect) {
    var lines = [];
    for (var i = 0; i < points.length; i++) {
      lines.push(pdbLine(i + 1, i + 1, AA3[sequence.charAt(i)] || 'UNK', chain, points[i]));
    }
    lines.push('TER');
    if (withConect) {
      for (var j = 1; j < points.length; j++) {
        lines.push('CONECT' + String(j).padStart(5) + String(j + 1).padStart(5));
      }
    }
    lines.push('END');
    return lines.join('\n');
  }

  function queryPoints(sup) {
    var points = [];
    for (var i = 0; i + 2 < sup.qCa.length; i += 3) { points.push([sup.qCa[i], sup.qCa[i + 1], sup.qCa[i + 2]]); }
    return points;
  }

  function ensureSupViewer() {
    if (state.supViewer || !window.$3Dmol) { return state.supViewer; }
    state.supViewer = window.$3Dmol.createViewer(els.supStage, { backgroundColor: VIEWER_BG, antialias: true });
    return state.supViewer;
  }

  function drawSuperposition() {
    var sup = state.sup;
    if (!sup || !sup.tm) { return; }
    var viewer = ensureSupViewer();
    if (!viewer) { setSupStatus('The 3D viewer could not be loaded.'); return; }
    viewer.clear();
    viewer.removeAllShapes();
    var query = viewer.addModel(chainPdb(queryPoints(sup), sup.detail.q_seq, 'A', true), 'pdb');
    var target = viewer.addModel(chainPdb(sup.tRot, sup.detail.t_seq, 'B', true), 'pdb');
    state.supModels = { query: query, target: target };
    styleSuperposition();
    viewer.zoomTo();
    viewer.resize();
    viewer.render();
    setSupStatus('');
  }

  function tube(radius, color) {
    return { stick: { radius: radius, color: color }, sphere: { radius: radius, color: color } };
  }

  function styleSuperposition() {
    var viewer = state.supViewer;
    var sup = state.sup;
    if (!viewer || !sup || !state.supModels) { return; }
    var options = state.supOptions;
    var query = state.supModels.query;
    var target = state.supModels.target;
    var qAligned = sup.pairs.queryResidues;
    var tAligned = sup.pairs.targetResidues;

    viewer.setStyle({}, {});
    viewer.removeAllShapes();

    if (options.full) {
      viewer.setStyle({ model: query }, tube(0.14, options.scheme === 'distance' ? COLOR_MUTED_DIM : COLOR_QUERY_DIM));
      viewer.setStyle({ model: target }, tube(0.14, options.scheme === 'distance' ? COLOR_MUTED_DIM : COLOR_TARGET_DIM));
    }

    if (options.scheme === 'distance') {
      var byBin = DISTANCE.map(function () { return []; });
      sup.distances.forEach(function (d, k) { byBin[distanceBin(d)].push(qAligned[k]); });
      byBin.forEach(function (residues, bin) {
        if (residues.length) { viewer.setStyle({ model: query, resi: residues }, tube(0.34, DISTANCE[bin].color)); }
      });
      viewer.setStyle({ model: target, resi: tAligned }, tube(0.2, COLOR_MUTED));
    } else {
      viewer.setStyle({ model: query, resi: qAligned }, tube(0.32, COLOR_QUERY));
      viewer.setStyle({ model: target, resi: tAligned }, tube(0.32, COLOR_TARGET));
    }

    if (options.pairs) {
      sup.pairs.query.forEach(function (q, k) {
        var p = transform(sup.tm.t, sup.tm.u, sup.pairs.target[k]);
        viewer.addCylinder({
          start: { x: q[0], y: q[1], z: q[2] }, end: { x: p[0], y: p[1], z: p[2] }, radius: 0.07,
          color: options.scheme === 'distance' ? DISTANCE[distanceBin(sup.distances[k])].color : '#c9d1d9',
          fromCap: 1, toCap: 1
        });
      });
    }

    var legend = els.detail.querySelector('[data-fs-suplegend]');
    if (legend) {
      legend.innerHTML = options.scheme === 'distance'
        ? '<span class="fs-legend-title">Distance to partner</span>' + legendMarkup(DISTANCE)
        : legendMarkup([{ color: COLOR_QUERY, label: proteinName(state.data.protein) }, { color: COLOR_TARGET, label: sup.hit.target }]);
    }
    viewer.render();
  }

  function resizeSup() {
    if (state.supViewer && els.supStage.clientWidth) {
      state.supViewer.resize();
      state.supViewer.render();
    }
  }

  /* As wide as the table's visible area, not as the table: see .fs-detail in
     the stylesheet. */
  function sizeDetail() {
    var scroller = els.detail.closest ? els.detail.closest('.mgdb-table-scroll') : null;
    if (!scroller || !els.detail.isConnected) { return; }
    var width = scroller.clientWidth;
    if (width && els.detail.style.width !== width + 'px') {
      els.detail.style.width = width + 'px';
      resizeSup();
    }
  }

  function stopSupSpin() {
    if (state.supViewer && state.supOptions.spin) { state.supViewer.spin(false); }
    state.supOptions.spin = false;
  }

  function stopSpins() {
    stopSupSpin();
    if (state.queryViewer && state.querySpin) { state.queryViewer.spin(false); }
    state.querySpin = false;
  }

  function downloadPdb() {
    var sup = state.sup;
    if (!sup || !sup.tm) { return; }
    var protein = state.data.protein;
    var species = state.speciesByKey[sup.hit.species] || {};
    var remarks = [
      'REMARK   1 FOLDSEEK MATCH FROM MAIZEGDB, SUPERPOSED ON THE ALIGNED RESIDUES',
      'REMARK   1 CHAIN A: ' + protein.uniprot + ' ' + proteinName(protein) + ' (' + SET.organism(protein) + '), AS SEARCHED',
      'REMARK   1 CHAIN B: ' + sup.hit.target + ' (' + String(species.latin || '').toUpperCase() + ')',
      'REMARK   1 TM-SCORE ' + sup.tm.tmTarget.toFixed(5) + ' BY CHAIN B ALIGNED LENGTH ' + sup.xlen
        + ', ' + sup.tm.tmQuery.toFixed(5) + ' BY CHAIN A ALIGNED LENGTH ' + sup.ylen,
      'REMARK   1 RMSD ' + sup.tm.rmsd.toFixed(2) + ' A OVER ' + sup.tm.aligned + ' ALIGNED PAIRS',
      'REMARK   1 CALPHA ATOMS ONLY: THE COORDINATES FOLDSEEK ALIGNED'
    ];
    var a = chainPdb(queryPoints(sup), sup.detail.q_seq, 'A', false).replace(/\nEND$/, '');
    var b = chainPdb(sup.tRot, sup.detail.t_seq, 'B', false).replace(/\nEND$/, '');
    saveFile('foldseek_' + protein.uniprot + '_' + sup.hit.target + '.pdb',
      remarks.join('\n') + '\n' + a + '\n' + b + '\nEND\n', 'chemical/x-pdb');
  }

  function downloadPng() {
    var sup = state.sup;
    if (!state.supViewer || !sup) { return; }
    var link = document.createElement('a');
    link.href = state.supViewer.pngURI();
    link.download = 'foldseek_' + state.data.protein.uniprot + '_' + sup.hit.target + '.png';
    document.body.appendChild(link);
    link.click();
    link.parentNode.removeChild(link);
  }

  /* ----------------------------------------------------------------------
   * Events
   * ---------------------------------------------------------------------- */

  function bindExamples(scope) {
    Array.prototype.forEach.call((scope || document).querySelectorAll('[data-fs-example]'), function (button) {
      button.addEventListener('click', function () {
        var value = button.getAttribute('data-fs-example');
        els.input.value = value;
        runSearch(value);
      });
    });
  }

  /* Delegated once, on the results container, which outlives every search.
     Binding these per render would stack a second listener on each new
     search and sort a column twice per click. */
  function bindResultsOnce() {
    var body = els.resultsBody;

    body.addEventListener('click', function (event) {
      var target = event.target.closest ? event.target : null;
      if (!target) { return; }
      var open = target.closest('[data-fs-open]');
      if (open) { openHit(parseInt(open.getAttribute('data-fs-open'), 10)); return; }
      var chip = target.closest('[data-fs-chip]');
      if (chip) { setSpecies(chip.getAttribute('data-fs-chip'), false); return; }
      var listSpecies = target.closest('[data-fs-species]');
      if (listSpecies) { setSpecies(listSpecies.getAttribute('data-fs-species'), true); return; }
      var sort = target.closest('th[data-fs-sort] button');
      if (sort) {
        var key = sort.parentNode.getAttribute('data-fs-sort');
        var column = null;
        COLUMNS.forEach(function (c) { if (c.key === key) { column = c; } });
        if (state.sort.key === key) {
          state.sort.dir = state.sort.dir === 'asc' ? 'desc' : 'asc';
        } else {
          state.sort = { key: key, dir: (column && column.first) || 'asc' };
        }
        state.page = 1;
        renderTable();
        MGDB.announce('Sorted by ' + sort.textContent + ', ' + (state.sort.dir === 'asc' ? 'ascending' : 'descending') + '.');
        return;
      }
      var page = target.closest('[data-fs-page]');
      if (page) {
        event.preventDefault();
        state.page = parseInt(page.getAttribute('data-fs-page'), 10) || 1;
        renderTable();
        var hits = body.querySelector('#fs-hits');
        if (hits && hits.scrollIntoView) { hits.scrollIntoView({ block: 'start' }); }
        return;
      }
      if (target.closest('[data-fs-export]')) { exportTsv(); return; }
      if (target.closest('[data-fs-clear]')) {
        state.filter = { species: 'all', text: '' };
        syncFilterControls();
        state.page = 1;
        renderTable();
        return;
      }
      var scheme = target.closest('[data-fs-qscheme]');
      if (scheme && !scheme.disabled) {
        state.queryScheme = scheme.getAttribute('data-fs-qscheme');
        Array.prototype.forEach.call(body.querySelectorAll('[data-fs-qscheme]'), function (b) {
          b.setAttribute('aria-pressed', b === scheme ? 'true' : 'false');
        });
        styleQuery();
        return;
      }
      if (target.closest('[data-fs-qreset]') && state.queryViewer) {
        state.queryViewer.zoomTo();
        state.queryViewer.render();
        return;
      }
      var qspin = target.closest('[data-fs-qspin]');
      if (qspin && state.queryViewer) {
        state.querySpin = !state.querySpin;
        qspin.setAttribute('aria-pressed', state.querySpin ? 'true' : 'false');
        state.queryViewer.spin(state.querySpin ? 'y' : false);
      }
    });

    body.addEventListener('keydown', function (event) {
      if (event.key !== 'Enter' && event.key !== ' ') { return; }
      var row = event.target.closest && event.target.closest('g[data-fs-species]');
      if (row) {
        event.preventDefault();
        setSpecies(row.getAttribute('data-fs-species'), true);
      }
    });
  }

  /* The table's own controls are rebuilt with each result, so they are
     bound with it. */
  function bindResults() {
    var body = els.resultsBody;
    var filter = body.querySelector('[data-fs-filter]');
    if (filter) {
      filter.addEventListener('input', MGDB.debounce(function () {
        state.filter.text = filter.value.trim();
        state.page = 1;
        renderTable();
      }, 150));
    }
    var size = body.querySelector('[data-fs-pagesize]');
    if (size) {
      size.addEventListener('change', function () {
        state.pageSize = parseInt(size.value, 10) || 0;
        state.page = 1;
        renderTable();
      });
      size.value = String(state.pageSize);
    }
  }

  function bindDetail() {
    var detail = els.detail;
    detail.querySelector('[data-fs-close]').addEventListener('click', closeHit);
    detail.querySelector('[data-fs-scheme]').addEventListener('change', function (event) {
      state.supOptions.scheme = event.target.value;
      styleSuperposition();
    });
    detail.querySelector('[data-fs-full]').addEventListener('change', function (event) {
      state.supOptions.full = event.target.checked;
      styleSuperposition();
      if (state.supViewer) { state.supViewer.zoomTo(); state.supViewer.render(); }
    });
    detail.querySelector('[data-fs-pairs]').addEventListener('change', function (event) {
      state.supOptions.pairs = event.target.checked;
      styleSuperposition();
    });
    detail.querySelector('[data-fs-supreset]').addEventListener('click', function () {
      if (state.supViewer) { state.supViewer.zoomTo(); state.supViewer.render(); }
    });
    detail.querySelector('[data-fs-supspin]').addEventListener('click', function (event) {
      if (!state.supViewer) { return; }
      state.supOptions.spin = !state.supOptions.spin;
      event.currentTarget.setAttribute('aria-pressed', state.supOptions.spin ? 'true' : 'false');
      state.supViewer.spin(state.supOptions.spin ? 'y' : false);
    });
    detail.querySelector('[data-fs-pdb]').addEventListener('click', downloadPdb);
    detail.querySelector('[data-fs-png]').addEventListener('click', downloadPng);
  }

  /* MGDB.typeahead with this page's own source: the protein structure index,
     through the API. It calls the source on every keystroke, so the wait and
     the cache live here. */
  var suggestCache = {};
  var suggestTimer = null;

  function suggestSource(text) {
    if (suggestCache[text]) { return suggestCache[text]; }
    return new Promise(function (resolve) {
      window.clearTimeout(suggestTimer);
      suggestTimer = window.setTimeout(function () {
        MGDB.request(apiUrl('action=suggest&term=' + encodeURIComponent(text)), { key: 'fs-suggest' })
          .then(function (data) {
            var items = (data && data.items) || [];
            suggestCache[text] = items;
            resolve(items);
          })
          .catch(function () { resolve([]); });
      }, 110);
    });
  }

  function init() {
    var root = document.querySelector('.mgdb-foldseek-page');
    if (!root) { return; }
    API = root.getAttribute('data-api') || '/search/foldseek/foldseek_api.php';
    setKey = SETS[root.getAttribute('data-set')] ? root.getAttribute('data-set') : 'maize';
    SET = SETS[setKey];

    els.form = document.getElementById('fs-form');
    els.input = document.getElementById('fs-term');
    els.status = document.getElementById('fs-status');
    els.results = document.getElementById('fs-results');
    els.resultsTitle = document.getElementById('fs-results-title');
    els.resultsBody = document.getElementById('fs-results-body');
    if (!els.form || !els.input || !els.results || !els.resultsBody) { return; }

    /* The viewers' stages and the open match's panel live for the whole
       visit and are moved into place, never rebuilt: each 3Dmol viewer holds
       a WebGL context, and browsers cap how many a page may have. */
    els.queryStage = mk('div', 'fs-stage');
    els.supStage = mk('div', 'fs-stage');
    els.detail = mk('div', 'fs-detail');
    els.detail.id = 'fs-detail';

    els.form.addEventListener('submit', function (event) {
      event.preventDefault();
      runSearch(els.input.value);
    });
    bindExamples(els.form);
    bindResultsOnce();

    if (MGDB.typeahead) {
      MGDB.typeahead(els.input, { source: suggestSource, min: 2 });
    }

    if (window.ResizeObserver) {
      var observer = new window.ResizeObserver(MGDB.debounce(function () {
        if (state.queryViewer && els.queryStage.clientWidth) { state.queryViewer.resize(); state.queryViewer.render(); }
        resizeSup();
      }, 120));
      observer.observe(els.queryStage);
      observer.observe(els.supStage);
    }
    var lastWidth = 0;
    window.addEventListener('resize', MGDB.debounce(function () {
      var host = els.resultsBody.querySelector('[data-fs-coverage]');
      var width = host ? Math.floor(host.getBoundingClientRect().width) : 0;
      if (width && width !== lastWidth) { lastWidth = width; drawCoverage(); }
      if (state.queryViewer && els.queryStage.clientWidth) { state.queryViewer.resize(); state.queryViewer.render(); }
      sizeDetail();
      resizeSup();
    }, 150));

    var initial = (root.getAttribute('data-initial') || '').trim();
    if (initial) {
      runSearch(initial, { hit: root.getAttribute('data-initial-hit') || null });
    }
  }

  function boot() {
    init();
    if (MGDB.sectionTabs) { MGDB.sectionTabs({ watch: '#fs-results' }); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(window, document);
