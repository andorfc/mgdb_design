/* ==========================================================================
   MaizeGDB Modern -- the basket (mockup)
   --------------------------------------------------------------------------
   A site-wide collection of records, kept in this browser. This file is the
   whole feature for one page: it puts a Basket button in the header, an add
   control in each half of the gene record hero, an "Add shown rows" control
   on the tables it knows how to read, and the drawer itself.

   It is loaded only by /gene_center/gene_basket/{id} while it is a mockup.
   The basket is stored under a mock-specific localStorage key and is seeded
   with examples on first load, so the drawer has something to show; "Reset
   examples" puts them back. Nothing here talks to the server except one read
   of the record API for the identity of the page being shown.

   What works today is wired to real URLs (Expression Tools, the record pages,
   TSV and copy). What is planned but not wired -- the order form hand-off,
   BibTeX, the cross-type queries -- is marked data-planned, drawn dashed,
   and says so when pressed.

   Loaded through Bauplan::includeScript(), so it runs from <head> while the
   document is still parsing; everything waits for DOMContentLoaded.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var STORE_KEY = 'mgdb-basket-mock';
  var SEED_KEY  = 'mgdb-basket-mock-seeded';
  var MOCK_ROUTE = /^\/gene_center\/gene_basket\//.test(window.location.pathname);
  /* Gene links stay inside the mockup while it is one, so a reader who opens
     lg2 from the basket lands on a page that still has the basket. */
  var GENE_BASE = MOCK_ROUTE ? '/gene_center/gene_basket/' : '/gene_center/gene/';

  /* ------------------------------------------------------------------ types
     One entry per record type the basket holds, in tab order. `sub` is the
     second line under the identifier; `tsv` the columns of the download;
     `actions` the places this type's items can go, marked wired or planned. */
  var TYPES = {
    gene_model: {
      label: 'Gene models', noun: ['gene model', 'gene models'], mono: true,
      href: function (it) { return GENE_BASE + encodeURIComponent(it.id); },
      sub: function (it) {
        var parts = [];
        if (it.symbol) { parts.push(it.symbol); }
        if (it.full_name && it.full_name !== it.symbol) { parts.push(it.full_name); }
        parts.push(genomeShort(it.assembly));
        return escapeHtml(parts.join(' · '));
      },
      tsv: [['gene_model', 'id'], ['symbol', 'symbol'], ['full_name', 'full_name'], ['assembly', 'assembly'], ['annotation', 'annotation'], ['url', 'url']],
      copy: function (items) { return items.map(function (it) { return it.id; }).join('\n'); },
      copyLabel: 'Copy ids',
      actions: function (items) {
        /* Expression Tools is per genome. Send the B73 v5 models, which is
           what it holds for every tool, and say which ones stay behind. */
        var b73 = items.filter(function (it) { return /NAM-5\.0$/.test(it.assembly || ''); });
        var rest = items.filter(function (it) { return !/NAM-5\.0$/.test(it.assembly || ''); });
        var ids = b73.map(function (it) { return it.id; }).join(',');
        var hint = '';
        if (rest.length && b73.length) {
          hint = 'Expression Tools holds B73 v5 models: ' + plural(b73.length, 'model') + ' go, '
            + rest.map(function (it) { return it.id + ' (' + genomeShort(it.assembly) + ')'; }).join(', ') + ' stay here.';
        } else if (!b73.length) {
          hint = 'None of these is a B73 v5 model, so Expression Tools has nothing to show for them.';
        }
        return {
          hint: hint,
          buttons: [
            { label: 'Expression Tools', href: '/expression/tools#list?genes=' + encodeURIComponent(ids), disabled: !b73.length },
            { label: 'GO enrichment', href: '/expression/tools#enrich?genes=' + encodeURIComponent(ids), disabled: !b73.length },
            { label: 'BLAST', planned: 'BLAST needs the sequences; the sequence endpoint proposed on 2026-09-10 would supply them.' },
            { label: 'Open in JBrowse', planned: 'A track of these models, the way the BLAST results page builds one.' }
          ]
        };
      }
    },
    locus: {
      label: 'Loci', noun: ['locus', 'loci'], mono: false,
      href: function (it) { return '/data_center/locus?id=' + encodeURIComponent(it.id); },
      sub: function (it) {
        var parts = [];
        if (it.full_name && it.full_name !== it.label) { parts.push(it.full_name); }
        if (it.kind) { parts.push(it.kind); }
        if (it.bin) { parts.push('bin ' + it.bin); }
        return escapeHtml(parts.join(' · '));
      },
      tsv: [['locus', 'label'], ['full_name', 'full_name'], ['type', 'kind'], ['bin', 'bin'], ['maizegdb_id', 'id'], ['url', 'url']],
      copy: function (items) { return items.map(function (it) { return it.label; }).join('\n'); },
      copyLabel: 'Copy names',
      actions: function (items) {
        return {
          hint: '',
          buttons: [
            { label: 'Stocks carrying alleles', planned: 'One query over the tables the locus record already reads: every stock carrying an allele of these ' + plural(items.length, 'locus', 'loci') + '.' },
            { label: 'Gene models for these loci', planned: 'The record resolver already maps a locus to its current model; this would fill the Gene models tab.' }
          ]
        };
      }
    },
    variation: {
      /* Alleles and insertions are both variation records; the Type column
         says which. An insertion row also names the stocks that carry it,
         so those ride along and can be added to the Stocks tab. */
      label: 'Variations', noun: ['variation', 'variations'], mono: false,
      href: function (it) { return '/data_center/variation?id=' + encodeURIComponent(it.id); },
      sub: function (it) {
        var parts = [];
        if (it.kind) { parts.push(it.kind); }
        if (it.source) { parts.push(it.source); }
        if (it.position) { parts.push(it.position); }
        if (it.stocks && it.stocks.length) { parts.push(it.stocks.length === 1 ? 'stock ' + it.stocks[0].name : plural(it.stocks.length, 'stock')); }
        return escapeHtml(parts.join(' · '));
      },
      tsv: [['variation', 'label'], ['type', 'kind'], ['source', 'source'], ['position', 'position'], ['stocks', 'stock_names'], ['maizegdb_id', 'id'], ['url', 'url']],
      copy: function (items) { return items.map(function (it) { return it.label; }).join('\n'); },
      copyLabel: 'Copy names',
      actions: function (items) {
        var stocks = {};
        items.forEach(function (it) { (it.stocks || []).forEach(function (s) { stocks[s.id] = s; }); });
        var ids = Object.keys(stocks);
        var without = items.filter(function (it) { return !(it.stocks && it.stocks.length); }).length;
        var hint = '';
        if (ids.length) {
          hint = plural(ids.length, 'stock') + ' ' + (ids.length === 1 ? 'is' : 'are') + ' named on the insertion rows here'
            + (without ? '; ' + plural(without, 'variation') + ' name' + (without === 1 ? 's' : '') + ' none' : '') + '.';
        }
        return {
          hint: hint,
          buttons: [
            { label: 'Add their stocks' + (ids.length ? ' (' + ids.length + ')' : ''), disabled: !ids.length,
              run: function () {
                var list = ids.map(function (k) { return { type: 'stock', id: Number(k), label: stocks[k].name, full_name: '', kind: '', distributor: null, distributor_label: '' }; });
                var n = addMany(list);
                toast(n ? 'Added ' + plural(n, 'stock') + ' to the basket' : 'Those stocks are already in the basket');
                if (n) { state.tab = 'stock'; draw(); }
              } },
            { label: 'Stocks carrying these alleles', planned: 'One query over the stock-allele table the locus record reads. The mockup only knows the stocks an insertion row named.' },
            { label: 'Open in JBrowse', planned: 'The positions are on the rows; a track of these sites is the hand-off the BLAST results page already makes.' }
          ]
        };
      }
    },
    snp_trait: {
      /* A SNP-trait association has no record page of its own: the row is a
         fact about the gene model it sits in, from a GWAS study. So the item
         is keyed by SNP + trait and links back to that model's SNPs and
         traits table. */
      label: 'SNPs and traits', noun: ['SNP–trait association', 'SNP–trait associations'], mono: true,
      href: function (it) { return GENE_BASE + encodeURIComponent(it.transcript || '') + '#gm-snps'; },
      sub: function (it) {
        var parts = [];
        if (it.full_name) { parts.push(it.full_name); }
        if (it.structure) { parts.push(it.structure); }
        if (it.position) { parts.push('position ' + it.position); }
        if (it.transcript) { parts.push(it.transcript); }
        return escapeHtml(parts.join(' · '));
      },
      tsv: [['snp', 'label'], ['trait', 'full_name'], ['gene_structure', 'structure'], ['position', 'position'], ['transcript', 'transcript'], ['url', 'url']],
      copy: function (items) { return items.map(function (it) { return it.label; }).join('\n'); },
      copyLabel: 'Copy SNP names',
      actions: function () {
        return {
          hint: '',
          buttons: [
            { label: 'Open in SNPversity', planned: 'SNPversity takes a gene or a region; this would open it on the gene with these sites marked.' },
            { label: 'Open in JBrowse', planned: 'A track of these positions, the hand-off the BLAST results page already makes.' }
          ]
        };
      }
    },
    stock: {
      label: 'Stocks', noun: ['stock', 'stocks'], mono: false,
      href: function (it) { return '/data_center/stock/' + encodeURIComponent(it.id); },
      sub: function (it) {
        var s = escapeHtml(it.full_name && it.full_name !== it.label ? it.full_name : (it.kind || ''));
        if (it.distributor === 'stock_center') { s += ' <span class="mgdb-pill mgdb-pill-ok">Stock Center</span>'; }
        else if (it.distributor === 'grin') { s += ' <span class="mgdb-pill mgdb-pill-info">GRIN' + (it.grin ? ' ' + escapeHtml(it.grin) : '') + '</span>'; }
        return s;
      },
      tsv: [['stock', 'label'], ['description', 'full_name'], ['type', 'kind'], ['distributor', 'distributor_label'], ['grin_accession', 'grin'], ['maizegdb_id', 'id'], ['url', 'url']],
      copy: function (items) { return items.map(function (it) { return it.label; }).join('\n'); },
      copyLabel: 'Copy names',
      actions: function (items) {
        var coop = items.filter(function (it) { return it.distributor === 'stock_center'; });
        var grin = items.filter(function (it) { return it.distributor === 'grin'; });
        var none = items.length - coop.length - grin.length;
        var buttons = [];
        if (coop.length) {
          buttons.push({ label: 'Order from the Stock Center (' + coop.length + ')',
            planned: 'The order form adds one stock per request; this button would add ' + plural(coop.length, 'stock') + ' and open the form.' });
        }
        if (grin.length) {
          buttons.push({ label: 'Order through GRIN (' + grin.length + ')',
            planned: 'GRIN takes an accession list on its own site; this would open it with ' + plural(grin.length, 'accession') + '.' });
        }
        var hint = none ? plural(none, 'stock') + ' here ' + (none === 1 ? 'has' : 'have') + ' no distributor on record and cannot be ordered.' : '';
        return { hint: hint, buttons: buttons };
      }
    },
    phenotype: {
      label: 'Phenotypes', noun: ['phenotype', 'phenotypes'], mono: false,
      href: function (it) { return '/data_center/phenotype?id=' + encodeURIComponent(it.id); },
      sub: function (it) { return escapeHtml(it.full_name || ''); },
      tsv: [['phenotype', 'label'], ['note', 'full_name'], ['maizegdb_id', 'id'], ['url', 'url']],
      copy: function (items) { return items.map(function (it) { return it.label; }).join('\n'); },
      copyLabel: 'Copy names',
      actions: function (items) {
        return {
          hint: '',
          buttons: [
            { label: 'Loci with these phenotypes', planned: 'The phenotype record lists its loci; this would fill the Loci tab from ' + plural(items.length, 'phenotype') + '.' }
          ]
        };
      }
    },
    reference: {
      label: 'References', noun: ['reference', 'references'], mono: false,
      href: function (it) { return '/data_center/reference?id=' + encodeURIComponent(it.id); },
      sub: function (it) { return escapeHtml(it.full_name || ''); },
      tsv: [['citation', 'label'], ['title', 'full_name'], ['year', 'year'], ['doi', 'doi'], ['pubmed', 'pubmed'], ['maizegdb_id', 'id'], ['url', 'url']],
      copy: function (items) {
        return items.map(function (it) { return it.citation || it.label; }).join('\n');
      },
      copyLabel: 'Copy citations',
      actions: function () {
        return {
          hint: '',
          buttons: [
            { label: 'BibTeX', planned: 'The reference record already carries every field BibTeX needs.' },
            { label: 'RIS', planned: 'Same fields, the format EndNote and Zotero import.' }
          ]
        };
      }
    }
  };
  var TYPE_ORDER = ['gene_model', 'locus', 'variation', 'snp_trait', 'stock', 'phenotype', 'reference'];

  /* ------------------------------------------------------------------ seed
     Real records, read from the lg1 record and its neighbours on 2026-09-24,
     so the examples link somewhere. Added times are staggered so the note
     under the title has something to say. */
  function seedItems() {
    var now = Date.now();
    var m = 60 * 1000, h = 60 * m, d = 24 * h;
    return [
      { type: 'gene_model', id: 'Zm00001eb147220', label: 'Zm00001eb147220', symbol: 'lg2', full_name: 'liguleless2', assembly: 'Zm-B73-REFERENCE-NAM-5.0', annotation: 'Zm00001eb.1', added: now - 2 * d },
      { type: 'gene_model', id: 'Zm00001eb055920', label: 'Zm00001eb055920', symbol: 'kn1', full_name: 'knotted1', assembly: 'Zm-B73-REFERENCE-NAM-5.0', annotation: 'Zm00001eb.1', added: now - 2 * d + 3 * m },
      { type: 'gene_model', id: 'Zm00001eb054440', label: 'Zm00001eb054440', symbol: 'tb1', full_name: 'teosinte branched1', assembly: 'Zm-B73-REFERENCE-NAM-5.0', annotation: 'Zm00001eb.1', added: now - 1 * d },
      { type: 'gene_model', id: 'Zm00026ab067840', label: 'Zm00026ab067840', symbol: 'lg1', full_name: 'liguleless1', assembly: 'Zm-CML333-REFERENCE-NAM-1.0', annotation: 'Zm00026ab.1', added: now - 3 * h },
      { type: 'locus', id: 12387, label: 'lg2', full_name: 'liguleless2', kind: 'Gene', added: now - 2 * d },
      { type: 'locus', id: 12252, label: 'gl2', full_name: 'glossy2', kind: 'Gene', added: now - 5 * h },
      { type: 'stock', id: 14011, label: '201F', full_name: '201F ws3 lg1 gl2', kind: 'Chromosome Marker', distributor: 'stock_center', distributor_label: 'Maize Genetics Cooperation - Stock Center', added: now - 1 * d },
      { type: 'stock', id: 166679, label: '202A', full_name: '202A lg1-PI200299', kind: 'Chromosome Marker', distributor: 'stock_center', distributor_label: 'Maize Genetics Cooperation - Stock Center', added: now - 1 * d + 40 * 1000 },
      { type: 'stock', id: 166681, label: '202B', full_name: '202B lg1-PI262493', kind: 'Chromosome Marker', distributor: 'stock_center', distributor_label: 'Maize Genetics Cooperation - Stock Center', added: now - 1 * d + 55 * 1000 },
      /*GRIN_SEED*/
      { type: 'phenotype', id: 64314, label: 'liguleless', full_name: '', added: now - 4 * h },
      { type: 'phenotype', id: 69814, label: 'erect leaf', full_name: '', added: now - 4 * h + 20 * 1000 },
      { type: 'reference', id: 136336, label: 'Moreno et al. 1997', full_name: 'liguleless1 encodes a nuclear-localized protein required for induction of ligules and auricles during maize leaf organogenesis', citation: 'Moreno, M et al. 1997. Genes Dev 11:616-628', year: 1997, doi: '', pubmed: '9119226', added: now - 3 * d },
      { type: 'reference', id: 134624, label: 'Harper and Freeling 1996', full_name: 'Interactions of liguleless1 and liguleless2 function during ligule induction in maize', citation: 'Harper, L and Freeling, M. 1996. Genetics 144:1871-1882', year: 1996, doi: '', pubmed: '8978070', added: now - 3 * d + 2 * m },
      { type: 'reference', id: 22847, label: 'Becraft et al. 1990', full_name: 'The liguleless-1 gene acts tissue specifically in maize leaf development', citation: 'Becraft, P et al. 1990. Dev Biol 141:220-232', year: 1990, doi: '', pubmed: '2391003', added: now - 3 * d + 4 * m }
    ];
  }

  /* ----------------------------------------------------------------- store */
  var storage = {
    get: function (k, d) {
      try { var v = window.localStorage.getItem(k); return v == null ? d : JSON.parse(v); } catch (e) { return d; }
    },
    set: function (k, v) {
      try { window.localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* private window or blocked storage: the basket lives for this page only */ }
    }
  };

  var state = { items: [], tab: null, open: false };
  var listeners = [];
  function emit() { listeners.forEach(function (fn) { fn(); }); }

  function load() {
    var stored = storage.get(STORE_KEY, null);
    if (stored && stored.items) {
      state.items = stored.items;
    } else if (!storage.get(SEED_KEY, false)) {
      state.items = seedItems();
      storage.set(SEED_KEY, true);
      save();
    } else {
      state.items = [];
    }
  }
  function save() { storage.set(STORE_KEY, { v: 1, items: state.items }); emit(); }

  function keyOf(type, id) { return type + ':' + String(id); }
  function has(type, id) {
    var k = keyOf(type, id);
    return state.items.some(function (it) { return keyOf(it.type, it.id) === k; });
  }
  function add(item) {
    if (!TYPES[item.type] || has(item.type, item.id)) { return 0; }
    item.added = item.added || Date.now();
    state.items.push(item);
    save();
    return 1;
  }
  function addMany(items) {
    var n = 0;
    items.forEach(function (it) {
      if (TYPES[it.type] && !has(it.type, it.id)) { it.added = it.added || Date.now(); state.items.push(it); n++; }
    });
    if (n) { save(); }
    return n;
  }
  function remove(type, id) {
    var k = keyOf(type, id);
    state.items = state.items.filter(function (it) { return keyOf(it.type, it.id) !== k; });
    save();
  }
  function clearType(type) { state.items = state.items.filter(function (it) { return it.type !== type; }); save(); }
  function clearAll() { state.items = []; save(); }
  function ofType(type) { return state.items.filter(function (it) { return it.type === type; }); }
  function typesPresent() { return TYPE_ORDER.filter(function (t) { return ofType(t).length; }); }

  /* --------------------------------------------------------------- helpers */
  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function el(html) { var t = document.createElement('template'); t.innerHTML = html.trim(); return t.content.firstChild; }
  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function plural(n, one, many) { return n + ' ' + (n === 1 ? one : (many || one + 's')); }
  function genomeShort(assembly) {
    if (!assembly) { return ''; }
    var m = /^Zm-([A-Za-z0-9]+)-REFERENCE-(NAM-5\.0|NAM-1\.0|GRAMENE-4\.0)/.exec(assembly);
    if (!m) { return assembly; }
    if (m[1] === 'B73') { return m[2] === 'NAM-5.0' ? 'B73 v5' : (m[2] === 'GRAMENE-4.0' ? 'B73 v4' : 'B73'); }
    return m[1];
  }
  function ago(ts) {
    var s = Math.max(0, Math.round((Date.now() - ts) / 1000));
    if (s < 60) { return 'just now'; }
    var m = Math.round(s / 60);
    if (m < 60) { return plural(m, 'minute') + ' ago'; }
    var h = Math.round(m / 60);
    if (h < 24) { return plural(h, 'hour') + ' ago'; }
    var d = Math.round(h / 24);
    return plural(d, 'day') + ' ago';
  }
  function absoluteUrl(path) { return window.location.origin + path; }

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) { return navigator.clipboard.writeText(text); }
    return new Promise(function (resolve, reject) {
      var ta = document.createElement('textarea');
      ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.top = '-1000px';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); resolve(); } catch (e) { reject(e); }
      document.body.removeChild(ta);
    });
  }
  function downloadText(name, text, mime) {
    var blob = new Blob([text], { type: mime || 'text/plain;charset=utf-8' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = name;
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
  }
  /* Tabs and newlines inside a value are replaced rather than quoted: TSV
     has no agreed quoting rule (the record shell does the same). */
  function tsvOf(type, items) {
    var t = TYPES[type];
    var rows = [t.tsv.map(function (c) { return c[0]; }).join('\t')];
    items.forEach(function (it) {
      rows.push(t.tsv.map(function (c) {
        var v = c[1] === 'url' ? absoluteUrl(t.href(it)) : it[c[1]];
        return String(v == null ? '' : v).replace(/[\t\r\n]+/g, ' ');
      }).join('\t'));
    });
    return rows.join('\n') + '\n';
  }

  /* ----------------------------------------------------------------- toast */
  var toasts = null;
  /* A toast that reports an add is also the way to the basket: pressing it
     opens the drawer on the tab of what was just added. It stays a little
     longer than a plain notice so there is time to take it up. Notices with
     nothing to open (copied, removed, planned) stay plain. */
  function toast(msg, opts) {
    opts = opts || {};
    if (!toasts) { toasts = el('<div class="mgdb-basket-toasts" aria-live="polite"></div>'); document.body.appendChild(toasts); }
    var actionable = !!opts.open && !state.open;
    var t;
    if (actionable) {
      t = el('<button type="button" class="mgdb-basket-toast is-action"><span></span><span class="mgdb-basket-toast-cta">View basket</span></button>');
      t.firstChild.textContent = msg;
    } else {
      t = el('<div class="mgdb-basket-toast"></div>');
      t.textContent = msg;
    }
    function dismiss() {
      t.classList.remove('is-in');
      setTimeout(function () { if (t.parentNode) { t.parentNode.removeChild(t); } }, 250);
    }
    if (actionable) {
      t.addEventListener('click', function () {
        if (typeof opts.open === 'string' && TYPES[opts.open]) { state.tab = opts.open; }
        dismiss();
        toggleDrawer(true);
      });
    }
    toasts.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('is-in'); });
    setTimeout(dismiss, actionable ? 5000 : 3200);
  }

  /* ---------------------------------------------------------- header button */
  var BASKET_ICON =
    '<svg class="mgdb-basket-button-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
      '<path d="M3.5 10h17l-1.6 8.2a2 2 0 0 1-2 1.6H7.1a2 2 0 0 1-2-1.6L3.5 10z"></path>' +
      '<path d="M8 10l3-5.5M16 10l-3-5.5"></path>' +
      '<path d="M9.5 14v2.5M12 14v2.5M14.5 14v2.5"></path>' +
    '</svg>';
  var headerButton = null;
  function buildHeaderButton() {
    var right = qs('.mgdb-site-header-right');
    if (!right) { return; }
    var bulk = qs('.mgdb-bulk-download', right);
    var row = el('<div class="mgdb-basket-headrow"></div>');
    headerButton = el(
      '<button type="button" class="mgdb-basket-button" aria-controls="mgdb-basket" aria-expanded="false">' +
        BASKET_ICON +
        '<span>Basket</span><span class="mgdb-basket-count" data-zero>0</span>' +
      '</button>');
    headerButton.addEventListener('click', function () { toggleDrawer(); });
    if (bulk) { right.insertBefore(row, bulk); row.appendChild(bulk); } else { right.insertBefore(row, right.firstChild); }
    row.appendChild(headerButton);
    listeners.push(paintHeaderCount);
    paintHeaderCount();
  }
  function paintHeaderCount() {
    if (!headerButton) { return; }
    var n = state.items.length;
    var c = qs('.mgdb-basket-count', headerButton);
    c.textContent = String(n);
    if (n) { c.removeAttribute('data-zero'); } else { c.setAttribute('data-zero', ''); }
    headerButton.setAttribute('aria-label', 'Basket, ' + plural(n, 'item'));
  }

  /* ------------------------------------------------------ floating button
     The header button leaves the screen a few hundred pixels down a record,
     and a basket that can only be opened from the top of a 15,000px page is
     no basket. A small pill at the bottom right stands in for it, and only
     then: it appears once the header button has scrolled out of view, only
     while the basket holds something, and never while the drawer is open.
     Scroll and resize are the triggers -- IntersectionObserver would be
     tidier, but it does not fire in the in-app preview pane, and a scroll
     listener costs one rect read per frame. */
  var fab = null;
  function buildFab() {
    fab = el('<button type="button" class="mgdb-basket-fab" aria-controls="mgdb-basket" hidden>' +
      BASKET_ICON + '<span class="mgdb-basket-fab-label">Basket</span><span class="mgdb-basket-count">0</span></button>');
    fab.addEventListener('click', function () { toggleDrawer(true); });
    document.body.appendChild(fab);
    listeners.push(paintFab);
    var ticking = false;
    function onScroll() {
      if (ticking) { return; }
      ticking = true;
      requestAnimationFrame(function () { ticking = false; paintFab(); });
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
    paintFab();
  }
  function paintFab() {
    if (!fab) { return; }
    var n = state.items.length;
    var headerGone = headerButton ? headerButton.getBoundingClientRect().bottom < 0 : true;
    fab.hidden = !(n > 0 && headerGone && !state.open);
    qs('.mgdb-basket-count', fab).textContent = String(n);
    fab.setAttribute('aria-label', 'Open the basket, ' + plural(n, 'item'));
  }

  /* ------------------------------------------------------------- hero adds
     The identity of the page comes from the record API rather than from the
     hero's text, so the basket stores the same facts the record does. One
     request, the overview fields only. The controls are built at once and
     enabled when the answer arrives. */
  var identity = { model: null, locus: null };
  function buildHeroControls() {
    var main = qs('main[data-gene-id]');
    var hero = qs('.v4-hero');
    if (!main || !hero) { return; }
    var apiId = main.getAttribute('data-gene-id') || main.getAttribute('data-requested-id');
    if (!apiId) { return; }

    var sides = { gene: qs('.v4-side-gene', hero), model: qs('.v4-side-model', hero) };
    var buttons = {};
    Object.keys(sides).forEach(function (k) {
      var side = sides[k];
      if (!side) { return; }
      var badge = qs('.v4-badge', side);
      var name = qs('.v4-name', side);
      /* An empty half keeps its badge but has no name; nothing to add. */
      if (!badge || !name) { return; }
      var corner = el('<div class="mgdb-basket-corner"></div>');
      var btn = el('<button type="button" class="mgdb-basket-add" aria-pressed="false" disabled><span class="mgdb-basket-add-plus" aria-hidden="true">+</span><span>Basket</span></button>');
      btn.setAttribute('aria-label', 'Add to basket');
      side.insertBefore(corner, badge);
      corner.appendChild(btn);
      corner.appendChild(badge);
      buttons[k] = btn;
    });
    if (!buttons.gene && !buttons.model) { return; }

    function itemForSide(k) {
      if (k === 'model' && identity.model) {
        var m = identity.model;
        return { type: 'gene_model', id: m.name, label: m.name, symbol: m.symbol || '', full_name: m.full_name || '', assembly: m.assembly || '', annotation: m.annotation || '' };
      }
      if (k === 'gene' && identity.locus) {
        var l = identity.locus;
        return { type: 'locus', id: l.id, label: l.name, full_name: l.full_name || '', kind: l.type || '', bin: l.bin || '' };
      }
      return null;
    }
    function paint() {
      Object.keys(buttons).forEach(function (k) {
        var it = itemForSide(k);
        var btn = buttons[k];
        if (!it) { btn.disabled = true; return; }
        btn.disabled = false;
        var inBasket = has(it.type, it.id);
        btn.setAttribute('aria-pressed', inBasket ? 'true' : 'false');
        btn.setAttribute('aria-label', (inBasket ? 'Remove ' : 'Add ') + it.label + (inBasket ? ' from the basket' : ' to the basket'));
        btn.title = inBasket ? 'In the basket. Press to remove.' : 'Add this ' + TYPES[it.type].noun[0] + ' to the basket';
      });
    }
    Object.keys(buttons).forEach(function (k) {
      buttons[k].addEventListener('click', function () {
        var it = itemForSide(k);
        if (!it) { return; }
        if (has(it.type, it.id)) {
          remove(it.type, it.id);
          toast('Removed ' + it.label + ' from the basket');
        } else {
          add(it);
          toast('Added ' + it.label + ' to the basket', { open: it.type });
          if (!state.open) { pulseHeader(); }
        }
      });
    });
    listeners.push(paint);

    var url = '/api/v1/records/gene/' + encodeURIComponent(apiId) + '?fields=overview';
    fetch(url, { headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (json) {
        if (!json || !json.data) { return; }
        var a = json.data.attributes || {};
        var ov = (json.data.sections && json.data.sections.overview) || {};
        if (a.name && a.kind !== 'locus') {
          identity.model = { name: a.name, symbol: a.symbol, full_name: a.full_name, assembly: a.assembly, annotation: a.annotation };
        }
        /* The locus half: the first entry of overview.loci when the payload
           carries one, else the attributes' locus id with the hero's name. */
        var loci = ov.loci || [];
        var locus = loci.length ? loci[0] : null;
        if (locus && locus.id) {
          identity.locus = { id: locus.id, name: locus.name || a.symbol, full_name: locus.full_name || a.full_name, type: locus.type || 'Gene', bin: locus.bin || '' };
        } else if (a.locus_id) {
          var h1 = qs('.v4-side-gene .v4-name');
          identity.locus = { id: a.locus_id, name: (h1 ? h1.textContent.trim() : a.symbol) || a.symbol, full_name: a.full_name, type: 'Gene', bin: '' };
        }
        paint();
      })
      .catch(function () { /* the controls stay disabled; the page is unchanged */ });
    paint();
  }
  function pulseHeader() {
    if (!headerButton || window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }
    headerButton.animate([{ transform: 'scale(1)' }, { transform: 'scale(1.06)' }, { transform: 'scale(1)' }], { duration: 260, easing: 'ease-out' });
  }

  /* ------------------------------------------------------- table controls
     Two controls in the toolbar of every table the basket can read, and a
     checkbox column in the table itself:

       Add selected (n)   the rows whose checkbox is ticked, on any page
       Add shown rows     every row on screen, filter and page size applied

     The record shell renders each list as .mgdb-rec-block with an h3 title
     and a toolbar, and re-renders the rows on sort, filter, page and view
     switch -- so the checkboxes are put back after every render, and the
     selection lives in the block's controller keyed by record, not in the
     DOM. Titles are matched because the shell carries no type marker: fine
     for a mockup on one page; the real thing would tag the block and its
     rows. Publications renders cards as well as a table, so a card gets a
     checkbox too. */

  /* "Moreno, M et al." + 1997 -> "Moreno et al. 1997";
     "Harper, L and Freeling, M" -> "Harper and Freeling 1996";
     "Johnston, RM; Sylvester, AW; Scanlon, MJ" -> "Johnston et al. 2017". */
  function shortCitation(authors, year) {
    var a = String(authors || '').trim();
    var surname = function (part) { return part.split(',')[0].trim(); };
    var out = '';
    if (!a) { out = ''; }
    else if (/\bet al\b/i.test(a) || a.split(';').length > 2) { out = surname(a.split(/;| and /)[0]) + ' et al.'; }
    else if (a.split(';').length === 2) { var p2 = a.split(';'); out = surname(p2[0]) + ' and ' + surname(p2[1]); }
    else if (/ and /.test(a)) { var p = a.split(' and '); out = surname(p[0]) + ' and ' + surname(p[1]); }
    else { out = surname(a); }
    return (out + ' ' + (year || '')).trim();
  }
  function refItem(id, title, authors, year, doi, pubmed, citation) {
    var label = shortCitation(authors, year) || ('Reference ' + id);
    return { type: 'reference', id: Number(id), label: label, full_name: title || '',
             citation: citation || ((authors ? authors + ' ' : '') + (year ? year + '. ' : '') + (title || '')).trim(),
             year: year || '', doi: doi || '', pubmed: pubmed || '' };
  }
  function pubmedIdFrom(a) {
    var m = a && /pubmed\.ncbi\.nlm\.nih\.gov\/(\d+)/.exec(a.getAttribute('href') || '');
    return m ? m[1] : '';
  }
  function cellsOf(tr) { return qsa('th, td', tr).filter(function (c) { return !c.classList.contains('mgdb-basket-selcol'); }).map(function (c) { return c.textContent.trim(); }); }

  var BLOCK_READERS = {
    'Stocks': { type: 'stock', row: function (tr) {
      var a = qs('th a, td a', tr);
      var m = a && /\/data_center\/stock\/(\d+)/.exec(a.getAttribute('href') || '');
      if (!m) { return null; }
      var cells = cellsOf(tr);
      var coop = !!qs('.mgdb-pill-ok', tr);
      return { type: 'stock', id: Number(m[1]), label: a.textContent.trim(), full_name: cells[1] || '', kind: cells[2] || '',
               distributor: coop ? 'stock_center' : null, distributor_label: cells[4] || '' };
    } },
    'Phenotypes': { type: 'phenotype', row: function (tr) {
      var a = qs('th a, td a', tr);
      var m = a && /\/data_center\/phenotype\?id=(\d+)/.exec(a.getAttribute('href') || '');
      if (!m) { return null; }
      var cells = cellsOf(tr);
      return { type: 'phenotype', id: Number(m[1]), label: a.textContent.trim(), full_name: cells[1] || '' };
    } },
    'Alleles and variations': { type: 'variation', row: function (tr) {
      var a = qs('th a, td a', tr);
      var m = a && /\/data_center\/variation\?id=(\d+)/.exec(a.getAttribute('href') || '');
      if (!m) { return null; }
      var cells = cellsOf(tr);
      return { type: 'variation', id: Number(m[1]), label: a.textContent.trim(), kind: cells[1] || 'Allele',
               source: '', position: '', stocks: [], stock_names: '' };
    } },
    'Insertions': { type: 'variation', row: function (tr) {
      /* The insertion's own link is in the row head; the stock links are in
         the last cell, so only the head is read for the identity. An
         insertion with no variation record has no page and is skipped. */
      var a = qs('th a', tr);
      var m = a && /\/data_center\/variation\?id=(\d+)/.exec(a.getAttribute('href') || '');
      if (!m) { return null; }
      var cells = cellsOf(tr);
      var stocks = qsa('a[href*="/data_center/stock/"]', tr).map(function (s) {
        var sm = /\/data_center\/stock\/(\d+)/.exec(s.getAttribute('href') || '');
        return sm ? { id: Number(sm[1]), name: s.textContent.trim() } : null;
      }).filter(Boolean);
      var pos = qs('.mgdb-sequence', tr);
      return { type: 'variation', id: Number(m[1]), label: a.textContent.trim(), kind: 'Insertion',
               source: cells[1] || '', position: pos ? pos.textContent.trim() : '',
               stocks: stocks, stock_names: stocks.map(function (s) { return s.name; }).join('; ') };
    } },
    'SNPs and traits': { type: 'snp_trait', row: function (tr) {
      /* No link and no id on these rows: the SNP name and the trait together
         are the identity. A missing structure renders as an em dash. */
      var cells = cellsOf(tr);
      var snp = cells[0] || '', trait = cells[1] || '';
      if (!snp || !trait) { return null; }
      var structure = cells[2] || '';
      if (structure === '—' || structure === '-') { structure = ''; }
      return { type: 'snp_trait', id: snp + '|' + trait, label: snp, full_name: trait,
               structure: structure, position: cells[3] || '', transcript: cells[4] || '' };
    } },
    'Publications': { type: 'reference',
      row: function (tr) {
        var a = qs('th a', tr) || qs('td a', tr);
        var m = a && /\/data_center\/reference\?id=(\d+)/.exec(a.getAttribute('href') || '');
        if (!m) { return null; }
        var small = qs('th small', tr);
        var yearCell = qs('.mgdb-ref-col-year', tr);
        var doiBtn = qs('button[data-copy-value]', tr);
        return refItem(m[1], a.textContent.trim(), small ? small.textContent.trim() : '',
          yearCell ? yearCell.textContent.trim() : '', doiBtn ? doiBtn.getAttribute('data-copy-value') : '',
          pubmedIdFrom(qs('a[href*="pubmed.ncbi.nlm.nih.gov"]', tr)), '');
      },
      card: function (card) {
        var a = qs('.mgdb-ref-title a', card);
        var m = a && /\/data_center\/reference\?id=(\d+)/.exec(a.getAttribute('href') || '');
        if (!m) { return null; }
        var authors = qs('.mgdb-ref-authors', card), citation = qs('.mgdb-ref-citation', card), meta = qs('.mgdb-ref-meta', card), doi = qs('.mgdb-ref-doi', card);
        var y = meta && /\b(1[89]\d\d|20\d\d)\b/.exec(meta.textContent);
        return refItem(m[1], a.textContent.trim(), authors ? authors.textContent.trim() : '', y ? y[1] : '',
          doi ? doi.textContent.replace(/^DOI:\s*/, '').trim() : '', pubmedIdFrom(qs('a[href*="pubmed.ncbi.nlm.nih.gov"]', card)),
          citation ? citation.textContent.trim() : '');
      }
    }
  };

  function enhanceBlock(block) {
    if (block.getAttribute('data-basket-ready')) { return; }
    var h3 = qs('.mgdb-rec-block-head h3', block);
    var toolbar = qs('.mgdb-rec-toolbar', block);
    var body = qs('[data-role="body"]', block);
    if (!h3 || !toolbar || !body) { return; }
    var title = h3.childNodes[0] ? h3.childNodes[0].textContent.trim() : '';
    var reader = BLOCK_READERS[title];
    if (!reader) { return; }
    block.setAttribute('data-basket-ready', '1');

    var selected = {};   /* key -> item; survives re-renders and page changes */
    var noun = TYPES[reader.type].noun;

    var addSel = el('<button type="button" class="mgdb-basket-add-rows mgdb-basket-add-selected" disabled></button>');
    addSel.title = 'Add the ticked rows to the basket';
    var addAll = el('<button type="button" class="mgdb-basket-add-rows"><span aria-hidden="true">+</span> Add shown rows</button>');
    addAll.title = 'Add every row on screen to the basket';
    toolbar.appendChild(addSel);
    toolbar.appendChild(addAll);

    function count() { return Object.keys(selected).length; }
    function paintButtons() {
      var n = count();
      addSel.innerHTML = '<span aria-hidden="true">+</span> Add selected' + (n ? ' <span class="mgdb-basket-selcount">' + n + '</span>' : '');
      addSel.disabled = !n;
    }
    function setSelected(it, on) {
      var k = keyOf(it.type, it.id);
      if (on) { selected[k] = it; } else { delete selected[k]; }
    }
    function isSelected(it) { return !!selected[keyOf(it.type, it.id)]; }

    /* Every checkbox in the block agrees with `selected`; the header box
       of each table reads all / some / none of its rows. */
    function sync() {
      qsa('tr[data-basket-row]', body).forEach(function (tr) {
        var cb = qs('.mgdb-basket-selcol input', tr);
        if (cb) { cb.checked = isSelected(tr._basketItem); }
      });
      qsa('article[data-basket-row]', body).forEach(function (card) {
        var cb = qs('.mgdb-basket-cardsel input', card);
        if (cb) { cb.checked = isSelected(card._basketItem); }
      });
      qsa('table[data-basket-decorated]', body).forEach(function (table) {
        var head = qs('thead .mgdb-basket-selcol input', table);
        if (!head) { return; }
        var rows = qsa('tbody tr[data-basket-row]', table);
        var on = rows.filter(function (tr) { return isSelected(tr._basketItem); }).length;
        head.checked = rows.length > 0 && on === rows.length;
        head.indeterminate = on > 0 && on < rows.length;
      });
      paintButtons();
    }

    function decorate() {
      qsa('table.mgdb-rec-table', body).forEach(function (table) {
        if (table.getAttribute('data-basket-decorated')) { return; }
        table.setAttribute('data-basket-decorated', '1');
        var headRow = qs('thead tr', table);
        if (headRow) {
          var th = el('<th scope="col" class="mgdb-basket-selcol"><input type="checkbox" aria-label="Select all shown rows"></th>');
          qs('input', th).addEventListener('change', function (e) {
            qsa('tbody tr[data-basket-row]', table).forEach(function (tr) { setSelected(tr._basketItem, e.target.checked); });
            sync();
          });
          headRow.insertBefore(th, headRow.firstChild);
        }
        qsa('tbody tr', table).forEach(function (tr) {
          var it = reader.row(tr);
          if (!it) { return; }
          tr._basketItem = it;
          tr.setAttribute('data-basket-row', '1');
          var td = el('<td class="mgdb-basket-selcol"><input type="checkbox"></td>');
          var cb = qs('input', td);
          cb.setAttribute('aria-label', 'Select ' + it.label);
          cb.addEventListener('change', function () { setSelected(it, cb.checked); sync(); });
          tr.insertBefore(td, tr.firstChild);
        });
      });
      if (reader.card) {
        qsa('article.mgdb-ref', body).forEach(function (card) {
          if (card.getAttribute('data-basket-row')) { return; }
          var it = reader.card(card);
          if (!it) { return; }
          card._basketItem = it;
          card.setAttribute('data-basket-row', '1');
          var lab = el('<label class="mgdb-basket-cardsel"><input type="checkbox"><span>Select</span></label>');
          var cb = qs('input', lab);
          cb.setAttribute('aria-label', 'Select ' + it.label);
          cb.addEventListener('change', function () { setSelected(it, cb.checked); sync(); });
          (qs('.mgdb-ref-actions', card) || card).appendChild(lab);
        });
      }
      sync();
    }

    addSel.addEventListener('click', function () {
      var items = Object.keys(selected).map(function (k) { return selected[k]; });
      var n = addMany(items);
      toast(n ? 'Added ' + plural(n, noun[0], noun[1]) + ' to the basket'
              : (items.length === 1 ? 'Already in the basket' : 'All ' + items.length + ' were already in the basket'),
            n ? { open: reader.type } : null);
      selected = {};
      sync();
      if (n && !state.open) { pulseHeader(); }
    });
    addAll.addEventListener('click', function () {
      var shown = qsa('[data-basket-row]', body).map(function (n) { return n._basketItem; }).filter(Boolean);
      if (!shown.length) { toast('Nothing on screen to add'); return; }
      var n = addMany(shown);
      toast(n ? 'Added ' + plural(n, noun[0], noun[1]) + ' to the basket' : 'Already in the basket', n ? { open: reader.type } : null);
      if (n && !state.open) { pulseHeader(); }
    });

    /* The shell replaces the table or the cards on every render; put the
       checkboxes back when it does. Decorating adds nodes too, which fires
       the observer once more and finds nothing left to do. */
    var scheduled = false;
    function schedule() {
      if (scheduled) { return; }
      scheduled = true;
      requestAnimationFrame(function () { scheduled = false; decorate(); });
    }
    if (window.MutationObserver) { new MutationObserver(schedule).observe(body, { childList: true, subtree: true }); }
    decorate();
  }
  function watchTables() {
    var main = qs('main');
    if (!main) { return; }
    qsa('.mgdb-rec-block', main).forEach(enhanceBlock);
    if (!window.MutationObserver) { return; }
    new MutationObserver(function (records) {
      records.forEach(function (r) {
        Array.prototype.forEach.call(r.addedNodes, function (n) {
          if (n.nodeType !== 1) { return; }
          if (n.classList && n.classList.contains('mgdb-rec-block')) { enhanceBlock(n); }
          qsa('.mgdb-rec-block', n).forEach(enhanceBlock);
        });
      });
    }).observe(main, { childList: true, subtree: true });
  }

  /* ---------------------------------------------------------------- drawer */
  var drawer = null;
  var lastTrigger = null;
  function buildDrawer() {
    drawer = el(
      '<aside class="mgdb-basket-drawer" id="mgdb-basket" role="dialog" aria-labelledby="mgdb-basket-title" hidden>' +
        '<div class="mgdb-basket-head">' +
          '<h2 id="mgdb-basket-title">Basket <span class="mgdb-basket-count" data-role="total">0</span></h2>' +
          '<button type="button" class="mgdb-basket-close" aria-label="Close the basket">&times;</button>' +
        '</div>' +
        '<p class="mgdb-basket-note" data-role="note"></p>' +
        '<ul class="mgdb-basket-tabs" role="tablist" aria-label="Record types in the basket" data-role="tabs"></ul>' +
        '<div class="mgdb-basket-panel" data-role="panel"></div>' +
        '<div class="mgdb-basket-foot">' +
          '<button type="button" class="mgdb-button mgdb-button-quiet" data-role="clear">Empty the basket</button>' +
          '<button type="button" class="mgdb-button mgdb-button-quiet" data-role="reset" title="Put the example records back (this is a mockup)">Reset examples</button>' +
          '<button type="button" class="mgdb-button mgdb-button-primary" data-role="close">Close</button>' +
        '</div>' +
      '</aside>');
    document.body.appendChild(drawer);
    qs('.mgdb-basket-close', drawer).addEventListener('click', function () { toggleDrawer(false); });
    qs('[data-role="close"]', drawer).addEventListener('click', function () { toggleDrawer(false); });
    qs('[data-role="clear"]', drawer).addEventListener('click', function () {
      var n = state.items.length;
      clearAll();
      toast(n ? 'Emptied the basket' : 'The basket was already empty');
    });
    qs('[data-role="reset"]', drawer).addEventListener('click', function () {
      state.items = seedItems(); save();
      toast('Example records put back');
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && state.open) { toggleDrawer(false); }
    });
    listeners.push(draw);
    draw();
  }

  function draw() {
    if (!drawer) { return; }
    var total = state.items.length;
    qs('[data-role="total"]', drawer).textContent = String(total);
    var present = typesPresent();
    if (state.tab && present.indexOf(state.tab) === -1) { state.tab = null; }
    if (!state.tab && present.length) { state.tab = present[0]; }

    /* The note. */
    var note = qs('[data-role="note"]', drawer);
    if (!total) {
      note.textContent = 'Kept in this browser only. Add records with the Basket control on any record, or Add shown rows on a table.';
    } else {
      var last = state.items.reduce(function (m, it) { return Math.max(m, it.added || 0); }, 0);
      note.textContent = 'Kept in this browser only. ' + plural(total, 'item') + ', last added ' + ago(last) + '.';
    }

    /* Tabs. */
    var tabs = qs('[data-role="tabs"]', drawer);
    tabs.innerHTML = '';
    present.forEach(function (t) {
      var li = el('<li role="presentation"></li>');
      var b = el('<button type="button" role="tab" class="mgdb-basket-tab" id="mgdb-basket-tab-' + t + '" aria-controls="mgdb-basket-panel"></button>');
      b.innerHTML = escapeHtml(TYPES[t].label) + '<span class="mgdb-basket-tab-count">' + ofType(t).length + '</span>';
      b.setAttribute('aria-selected', state.tab === t ? 'true' : 'false');
      b.addEventListener('click', function () { state.tab = t; draw(); });
      li.appendChild(b);
      tabs.appendChild(li);
    });
    tabs.hidden = !present.length;

    /* Panel. */
    var panel = qs('[data-role="panel"]', drawer);
    panel.innerHTML = '';
    panel.id = 'mgdb-basket-panel';
    panel.setAttribute('role', 'tabpanel');
    if (!state.tab) {
      panel.appendChild(el('<p class="mgdb-basket-empty">Empty.</p>'));
      return;
    }
    panel.setAttribute('aria-labelledby', 'mgdb-basket-tab-' + state.tab);
    var type = TYPES[state.tab];
    var items = ofType(state.tab).slice().sort(function (a, b) { return (b.added || 0) - (a.added || 0); });
    var acts = type.actions(items);

    if (acts.buttons.length) {
      var actions = el('<div class="mgdb-basket-actions"><p class="mgdb-basket-actions-label">Send to</p></div>');
      acts.buttons.forEach(function (spec) {
        var b;
        if (spec.href && !spec.planned) {
          b = el('<a class="mgdb-button mgdb-button-secondary" target="_blank" rel="noopener"></a>');
          b.href = spec.href;
          if (spec.disabled) { b.setAttribute('aria-disabled', 'true'); b.removeAttribute('href'); }
        } else if (spec.run) {
          /* Wired, and acts inside the basket rather than opening a page. */
          b = el('<button type="button" class="mgdb-button mgdb-button-secondary"></button>');
          b.disabled = !!spec.disabled;
          b.addEventListener('click', function () { spec.run(); });
        } else {
          b = el('<button type="button" class="mgdb-button mgdb-button-secondary" data-planned></button>');
          b.title = 'Planned. Not wired in this mockup.';
          b.addEventListener('click', function () { toast('Planned, not wired in this mockup. ' + spec.planned); });
        }
        b.textContent = spec.label;
        actions.appendChild(b);
      });
      panel.appendChild(actions);
    }
    if (acts.hint) { panel.appendChild(el('<p class="mgdb-basket-hint">' + escapeHtml(acts.hint) + '</p>')); }

    var ul = el('<ul class="mgdb-basket-list"></ul>');
    items.forEach(function (it) {
      var li = el('<li class="mgdb-basket-item"></li>');
      li.title = 'Added ' + new Date(it.added || Date.now()).toLocaleString();
      var main = el('<div class="mgdb-basket-item-main"></div>');
      var a = el('<a class="mgdb-basket-item-id' + (type.mono ? ' is-mono' : '') + '"></a>');
      a.href = type.href(it);
      a.textContent = it.label;
      main.appendChild(a);
      var sub = type.sub(it);
      if (sub) { main.appendChild(el('<span class="mgdb-basket-item-sub">' + sub + '</span>')); }
      var rm = el('<button type="button" class="mgdb-basket-remove" aria-label="Remove ' + escapeHtml(it.label) + '">&times;</button>');
      rm.addEventListener('click', function () { remove(it.type, it.id); });
      li.appendChild(main);
      li.appendChild(rm);
      ul.appendChild(li);
    });
    panel.appendChild(ul);

    var foot = el('<div class="mgdb-basket-tabfoot"></div>');
    var dl = el('<button type="button" class="mgdb-button mgdb-button-quiet">Download TSV</button>');
    dl.addEventListener('click', function () {
      downloadText('maizegdb_basket_' + state.tab + '.tsv', tsvOf(state.tab, items), 'text/tab-separated-values;charset=utf-8');
    });
    var cp = el('<button type="button" class="mgdb-button mgdb-button-quiet"></button>');
    cp.textContent = type.copyLabel;
    cp.addEventListener('click', function () {
      copyText(type.copy(items)).then(function () { toast('Copied ' + plural(items.length, type.noun[0], type.noun[1])); },
        function () { toast('Could not copy in this browser'); });
    });
    var ct = el('<button type="button" class="mgdb-button mgdb-button-quiet"></button>');
    ct.textContent = 'Empty this tab';
    ct.addEventListener('click', function () { clearType(state.tab); toast('Removed the ' + type.label.toLowerCase()); });
    foot.appendChild(dl); foot.appendChild(cp); foot.appendChild(ct);
    panel.appendChild(foot);
  }

  function toggleDrawer(open) {
    if (!drawer) { return; }
    var show = open == null ? !state.open : open;
    state.open = show;
    drawer.hidden = !show;
    if (headerButton) { headerButton.setAttribute('aria-expanded', show ? 'true' : 'false'); }
    if (show) {
      lastTrigger = document.activeElement;
      draw();
      var first = qs('.mgdb-basket-close', drawer);
      if (first) { first.focus(); }
    } else if (lastTrigger && lastTrigger.focus) {
      lastTrigger.focus();
    }
    paintFab();
  }

  /* ------------------------------------------------------------------ boot */
  function init() {
    load();
    buildHeaderButton();
    buildDrawer();
    buildFab();
    buildHeroControls();
    watchTables();
    /* A tick of the note's relative time while the drawer is open. */
    setInterval(function () { if (state.open) { draw(); } }, 60 * 1000);
    /* Another tab of this browser changing the basket shows here. */
    window.addEventListener('storage', function (e) {
      if (e.key === STORE_KEY) { load(); emit(); }
    });
    window.MGDB_BASKET = { add: add, addMany: addMany, remove: remove, has: has, items: function () { return state.items.slice(); }, open: toggleDrawer };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
