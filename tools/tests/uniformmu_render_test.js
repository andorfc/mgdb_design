/* Exercises js/mgdb-uniformmu.js under JavaScriptCore with a small DOM shim and
   real API payloads captured from the development instance. The browser cannot
   reach the dev host (Cloudflare bot challenge), so this is how the render path
   is checked. */

var LOG = [];
function ok(name, cond, extra) {
  LOG.push((cond ? 'PASS  ' : 'FAIL  ') + name + (cond ? '' : '   ' + (extra || '')));
  if (!cond) { FAILED = true; }
}
var FAILED = false;

/* ---- DOM shim ---------------------------------------------------------- */

function Element(id, className) {
  this.id = id || '';
  this.className = className || '';
  this.innerHTML = '';
  this.textContent = '';
  this.hidden = false;
  this.value = '';
  this.attrs = {};
  this.children = [];
  this.listeners = {};
}
Element.prototype.setAttribute = function (k, v) { this.attrs[k] = String(v); };
Element.prototype.getAttribute = function (k) { return (k in this.attrs) ? this.attrs[k] : null; };
Element.prototype.hasAttribute = function (k) { return k in this.attrs; };
Element.prototype.addEventListener = function (type, fn) {
  (this.listeners[type] = this.listeners[type] || []).push(fn);
};
Element.prototype.dispatch = function (type, event) {
  (this.listeners[type] || []).forEach(function (fn) { fn.call(this, event || { preventDefault: function () {} }); }, this);
};
Element.prototype.querySelector = function (selector) {
  if (selector === 'table[data-sortable]') { return TABLE_STUB; }
  if (selector === 'input, select') { return new Element(); }
  // The overlay's own parts, created lazily and cached so the file under test
  // sees the same node each time it asks.
  this.parts = this.parts || {};
  if (['.um-zoom-image', '.um-zoom-caption', '.um-zoom-close', 'img', 'figcaption'].indexOf(selector) !== -1) {
    if (!this.parts[selector]) { this.parts[selector] = new Element(selector); }
    return this.parts[selector];
  }
  return null;
};
Element.prototype.closest = function () { return this.parentFigure || null; };
Element.prototype.querySelectorAll = function () { return []; };
Element.prototype.appendChild = function (child) { this.children.push(child); };
Element.prototype.focus = function () {};

var TABLE_STUB = new Element('table');
var registry = {};
function reg(id, className) { registry[id] = new Element(id, className); return registry[id]; }

var PAGE = new Element('um-main', 'mgdb-page mgdb-uniformmu-page');
PAGE.setAttribute('data-payload', '/data/uniformmu/uniformmu_summary.json');

['um-panel-gene', 'um-panel-insertion', 'um-panel-stock', 'um-panel-region',
 'um-results', 'um-idle', 'um-gene', 'um-insertion', 'um-stock',
 'um-assembly', 'um-chr', 'um-start', 'um-end',
 'um-chart-per-gene', 'um-chart-structure', 'um-chart-chromosome'].forEach(function (id) { reg(id); });

var MODE_CHIPS = ['gene', 'insertion', 'stock', 'region'].map(function (mode) {
  var chip = new Element('chip-' + mode, 'mgdb-chip');
  chip.setAttribute('data-mode', mode);
  return chip;
});

/* The real load order, not a convenient one.

   Bauplan emits every includeScript() into <head>, so the file under test runs
   while the document is still parsing: readyState is "loading" and <main> does
   not exist yet. The first version of this file read the DOM at module scope,
   got null, and silently did nothing at all — no charts, no lookup. Modelling
   that here is the only reason this shim is more complicated than it looks. */
var DOM_READY = false;
var domReadyHandlers = [];
var keydownHandlers = [];

var BODY = new Element('body');
BODY.classList = {
  names: {},
  add: function (n) { this.names[n] = true; },
  remove: function (n) { delete this.names[n]; },
  has: function (n) { return !!this.names[n]; }
};

var document = {
  readyState: 'loading',
  body: BODY,
  addEventListener: function (type, fn) {
    if (type === 'DOMContentLoaded') { domReadyHandlers.push(fn); }
    if (type === 'keydown') { keydownHandlers.push(fn); }
  },
  getElementById: function (id) { return DOM_READY ? (registry[id] || null) : null; },
  createElement: function () { return new Element(); },
  querySelector: function (selector) {
    if (!DOM_READY) { return null; }
    return selector === '.mgdb-uniformmu-page' ? PAGE : null;
  },
  querySelectorAll: function () { return []; }
};

function fireDomReady() {
  DOM_READY = true;
  document.readyState = 'complete';
  domReadyHandlers.forEach(function (fn) { fn(); });
}

var FIGURE = new Element('figure');
FIGURE.parts = { figcaption: (function () { var c = new Element(); c.textContent = 'Figure 5. Library construction.'; return c; })() };

var ZOOM_TRIGGER = new Element('zoom-1', 'um-zoom');
ZOOM_TRIGGER.setAttribute('href', '/images/uniformmu/figure5.png');
ZOOM_TRIGGER.parts = { img: (function () { var i = new Element(); i.setAttribute('alt', 'Figure 5 alt text'); i.setAttribute('src', '/images/uniformmu/figure5.png'); return i; })() };
ZOOM_TRIGGER.parentFigure = FIGURE;

var CHART_FALLBACKS = [new Element('fb1'), new Element('fb2'), new Element('fb3')];
CHART_FALLBACKS.forEach(function (node) { node.textContent = 'Loading chart\u2026'; });

PAGE.querySelectorAll = function (selector) {
  if (selector === '.um-modes [data-mode]') { return MODE_CHIPS; }
  if (selector === '.um-example') { return []; }
  if (selector === '.mgdb-chart-fallback') { return CHART_FALLBACKS; }
  if (selector === '.um-zoom') { return [ZOOM_TRIGGER]; }
  return [];
};

/* ---- window and MGDB shims ---------------------------------------------- */

var REQUESTS = [];
var RESPONSES = {};
var ANNOUNCED = [];
var CHARTS = [];

var window = {
  location: { search: '', pathname: '/uniformmu', hash: '' },
  history: { replaceState: function () {} },
  URLSearchParams: null,   // exercises the no-URLSearchParams path first
  document: document,
  setTimeout: function (fn) { fn(); return 0; },
  clearTimeout: function () {},
  MGDB: {
    escapeHtml: function (v) {
      return String(v == null ? '' : v)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    },
    announce: function (m) { ANNOUNCED.push(m); },
    sortTable: function (t) { CHARTS.push('sorted'); void t; },
    chart: function (config) { CHARTS.push(config); },
    CHART_COLORS: ['#D55E00', '#0072B2', '#E69F00', '#009E73'],
    request: function (url) {
      REQUESTS.push(url);
      var key = Object.keys(RESPONSES).filter(function (k) { return url.indexOf(k) !== -1; })[0];
      if (!key) { return { then: function () { return { catch: function () {} }; } }; }
      var payload = RESPONSES[key];
      return {
        then: function (fn) {
          var out = fn(payload);
          return { catch: function () { return out; } };
        }
      };
    }
  }
};

/* ---- load the file under test ------------------------------------------- */

/* The summary payload has to be available before init() runs, because that is
   when loadPayload() asks for it. */
RESPONSES['uniformmu_summary.json'] = JSON.parse(readFile(SUMMARY_PAYLOAD));

var source = readFile(SOURCE_PATH);
var run = new Function('window', 'document', source);
run(window, document);

/* Nothing may have happened yet: the document is still "loading". */
ok('nothing runs before the DOM is ready', REQUESTS.length === 0 && CHARTS.length === 0,
   'requests=' + REQUESTS.length + ' charts=' + CHARTS.length);

fireDomReady();

ok('the payload is requested once the DOM is ready',
   REQUESTS.some(function (u) { return u.indexOf('uniformmu_summary.json') !== -1; }),
   REQUESTS.join(' '));

/* ---- the checks ---------------------------------------------------------- */

var results = registry['um-results'];

/* 1. a gene result with insertions on three assemblies */
RESPONSES['mode=gene'] = JSON.parse(readFile(GENE_PAYLOAD));
registry['um-gene'].value = 'lg1';
registry['um-panel-gene'].dispatch('submit');

ok('gene lookup called the endpoint', REQUESTS.some(function (u) { return u.indexOf('mode=gene') !== -1; }), REQUESTS.join(' '));
ok('gene result renders the insertion name', results.innerHTML.indexOf('mu1038042') !== -1);
ok('gene result renders the locus record link', results.innerHTML.indexOf('/data_center/locus?id=2373557') !== -1);
ok('gene result renders the stock link', results.innerHTML.indexOf('/data_center/stock/2388919') !== -1);
ok('gene result renders the order link', results.innerHTML.indexOf('/ordering/coop_order/UFMu-04038') !== -1);
ok('gene result renders the variation link', results.innerHTML.indexOf('/data_center/variation?id=2384603') !== -1);
ok('gene result lists all three assemblies',
   results.innerHTML.indexOf('B73 v5') !== -1 && results.innerHTML.indexOf('B73 v4') !== -1 &&
   results.innerHTML.indexOf('B73 v3') !== -1);
ok('gene result names the symbol', results.innerHTML.indexOf('liguleless1') !== -1);
ok('gene result says which other names were searched', results.innerHTML.indexOf('Also searched as') !== -1);
ok('gene result announces the count', ANNOUNCED.join('|').indexOf('insertions found') !== -1);
ok('gene result reports its query cost', results.innerHTML.indexOf('database queries') !== -1);

/* 2. an insertion with no stock and no coordinates: both must still render */
RESPONSES['mode=insertion'] = {
  ok: true, mode: 'insertion',
  subject: { kind: 'insertion', name: 'mu9999999', url: '/data_center/locus?id=1', status: 'withdrawn' },
  summary: { total: 1, truncated: false, with_stock: 0, without_alignment: 1, elapsed_ms: 4, queries: 3 },
  notes: [{ code: 'no_coordinates', detail: 'These insertions have records and seed stocks but no genome alignment on file.' }],
  results: [{ id: 1, name: 'mu9999999', url: '/data_center/locus?id=1', status: 'withdrawn',
              alignments: [], assembly_count: 0, variations: [], stocks: [] }]
};
MODE_CHIPS[1].dispatch('click');
registry['um-insertion'].value = 'mu9999999';
registry['um-panel-insertion'].dispatch('submit');

ok('unaligned insertion still renders a row', results.innerHTML.indexOf('mu9999999') !== -1);
ok('unaligned insertion says it has no coordinates', results.innerHTML.indexOf('no coordinates on file') !== -1);
ok('stockless insertion says so rather than showing nothing', results.innerHTML.indexOf('no seed on file') !== -1);
ok('withdrawn insertion is badged', results.innerHTML.indexOf('withdrawn') !== -1);
ok('the note is surfaced', results.innerHTML.indexOf('no genome alignment on file') !== -1);

/* 3. a stock that resolves but carries no mapped insertion */
RESPONSES['mode=stock'] = {
  ok: true, mode: 'stock',
  subject: { kind: 'stock', name: 'UFMu-09999', url: '/data_center/stock/9', status: 'available',
             provider: 'Maize Genetics Cooperation - Stock Center', comments: null,
             order_url: '/ordering/coop_order/UFMu-09999' },
  summary: { total: 0, truncated: false, with_stock: 0, without_alignment: 0, elapsed_ms: 6, queries: 2 },
  notes: [{ code: 'stock_unmapped', detail: 'This stock has no sequence-indexed Mu insertion recorded at MaizeGDB. The seed still exists and can be ordered.' }],
  results: []
};
MODE_CHIPS[2].dispatch('click');
registry['um-stock'].value = 'UFMu-09999';
registry['um-panel-stock'].dispatch('submit');

ok('empty stock still offers the order link', results.innerHTML.indexOf('/ordering/coop_order/UFMu-09999') !== -1);
ok('empty stock explains itself', results.innerHTML.indexOf('The seed still exists') !== -1);
ok('empty stock does not claim a match', results.innerHTML.indexOf('No UniformMu insertions here') !== -1);

/* 4. a term that resolves to nothing at all */
RESPONSES['mode=gene'] = {
  ok: true, mode: 'gene', subject: null,
  summary: { total: 0, truncated: false, with_stock: 0, without_alignment: 0, elapsed_ms: 3, queries: 2 },
  notes: [{ code: 'gene_not_found', detail: 'No gene at MaizeGDB matches "zzz".' }],
  results: []
};
MODE_CHIPS[0].dispatch('click');
registry['um-gene'].value = 'zzz';
registry['um-panel-gene'].dispatch('submit');
ok('unknown gene says nothing matched', results.innerHTML.indexOf('Nothing matched') !== -1);
ok('unknown gene surfaces the reason', results.innerHTML.indexOf('No gene at MaizeGDB matches') !== -1);

/* 5. an empty field must not reach the network */
var before = REQUESTS.length;
registry['um-gene'].value = '   ';
registry['um-panel-gene'].dispatch('submit');
ok('an empty term is refused client-side', REQUESTS.length === before, 'a request was sent');
ok('an empty term explains itself', results.innerHTML.indexOf('Nothing to look up') !== -1);

/* 6. the region window ceiling is enforced before the request */
MODE_CHIPS[3].dispatch('click');
registry['um-chr'].value = 'chr1';
registry['um-start'].value = '1';
registry['um-end'].value = '90000000';
before = REQUESTS.length;
registry['um-panel-region'].dispatch('submit');
ok('an over-wide window is refused client-side', REQUESTS.length === before);
ok('an over-wide window states the limit', results.innerHTML.indexOf('20 Mb') !== -1);

/* 7. a region within the limit does go out, with its coordinates */
RESPONSES['mode=region'] = {
  ok: true, mode: 'region',
  subject: { kind: 'region', name: 'chr1:1-1,000', assembly: 'Zm-B73-REFERENCE-NAM-5.0',
             assembly_label: 'B73 v5', chromosome: 'chr1', start: 1, end: 1000, url: null },
  summary: { total: 0, truncated: false, with_stock: 0, without_alignment: 0, elapsed_ms: 90, queries: 3 },
  notes: [], results: []
};
registry['um-end'].value = '1000';
registry['um-panel-region'].dispatch('submit');
/* Defaulted to '' rather than read directly: when the file under test wires
   nothing up — which is exactly the failure this harness exists to catch — the
   request list is empty, and a check that throws is a check that hides every
   other result behind a stack trace. */
var last = REQUESTS.length ? REQUESTS[REQUESTS.length - 1] : '';
ok('region request carries the assembly, chromosome and both coordinates',
   last.indexOf('mode=region') !== -1 && last.indexOf('chr=chr1') !== -1 &&
   last.indexOf('start=1') !== -1 && last.indexOf('end=1000') !== -1, last || '(no request sent)');

/* 8. the charts read the payload the server already rendered into tables */
ok('three charts were requested', CHARTS.filter(function (c) { return c && c.target; }).length === 3,
   'got ' + CHARTS.filter(function (c) { return c && c.target; }).length);
var perGene = CHARTS.filter(function (c) { return c && c.target === 'um-chart-per-gene'; })[0];
ok('the per-gene chart caps its last bucket at 10+',
   perGene && perGene.traces[0].x[perGene.traces[0].x.length - 1] === '10+');
var chrChart = CHARTS.filter(function (c) { return c && c.target === 'um-chart-chromosome'; })[0];
ok('the chromosome chart is ordered by chromosome, not by count',
   chrChart && chrChart.traces[0].x[0] === 'chr1' && chrChart.traces[0].x[9] === 'chr10',
   chrChart ? chrChart.traces[0].x.join(',') : 'no chart');
ok('the chromosome chart shows the ten chromosomes and no scaffolds',
   chrChart && chrChart.traces[0].x.length === 10);
var structureChart = CHARTS.filter(function (c) { return c && c.target === 'um-chart-structure'; })[0];
ok('the structure chart is a share, so every bar is between 0 and 100',
   structureChart && structureChart.traces.every(function (trace) {
     return trace.y.every(function (v) { return v >= 0 && v <= 100; });
   }));
ok('the structure chart carries one trace per structure class',
   structureChart && structureChart.traces.length >= 4,
   structureChart ? String(structureChart.traces.length) : 'none');

/* 9. figure zoom */
ZOOM_TRIGGER.dispatch('click', { preventDefault: function () { this.defaulted = true; }, button: 0 });
var overlay = BODY.children[BODY.children.length - 1];
ok('clicking a figure builds an overlay', !!overlay && overlay.className === 'um-zoom-overlay');
ok('the overlay is a modal dialog to assistive technology',
   overlay && overlay.getAttribute('role') === 'dialog' && overlay.getAttribute('aria-modal') === 'true');
ok('the overlay is shown', overlay && overlay.hidden === false);
ok('the overlay shows the full-size image',
   overlay && overlay.parts['.um-zoom-image'].getAttribute('src') === '/images/uniformmu/figure5.png');
ok('the enlarged image keeps its alt text',
   overlay && overlay.parts['.um-zoom-image'].getAttribute('alt') === 'Figure 5 alt text');
ok('the overlay carries the figure caption',
   overlay && overlay.parts['.um-zoom-caption'].textContent.indexOf('Library construction') !== -1);
ok('the page behind is locked from scrolling', BODY.classList.has('um-zoom-open'));

keydownHandlers.forEach(function (fn) { fn({ key: 'Escape' }); });
ok('Escape closes the overlay', overlay && overlay.hidden === true);
ok('the scroll lock is released', !BODY.classList.has('um-zoom-open'));

/* A modified click is the reader asking for a new tab; leave it to the browser. */
var beforeChildren = BODY.children.length;
ZOOM_TRIGGER.dispatch('click', { preventDefault: function () { this.defaulted = true; }, metaKey: true, button: 0 });
ok('a command-click is left alone', overlay && overlay.hidden === true && BODY.children.length === beforeChildren);

print(LOG.join('\n'));
print(FAILED ? '\nFAILURES PRESENT' : '\nall checks passed');
