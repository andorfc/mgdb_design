/* tools/tests/foldseek_tmscore_check.js
 *
 * Checks js/mgdb-tmalign.js against the TM-scores the upstream Foldseek page
 * (foldseek.maizegdb.org) displays for the same alignments. Those numbers come
 * from TM-align itself, compiled to WebAssembly and run in the reader's
 * browser, so they are the reference: a port is right when it reproduces them,
 * not when it agrees with a second implementation of the same idea.
 *
 * The expected values were read off the live page for bz1 (Zm00001eb374230,
 * AlphaFold model AF-P16165-F1-model_v3) on 2026-09-25 by toggling each row
 * and reading its "TM-Score:" line. Indices are the row order of the page,
 * which is the order of the embedded render([...]) array.
 *
 * One expected value is NOT the one upstream displays, on purpose. For 13 of
 * bz1's 165 hits the upstream viewer hands TM-align a truncated target --
 * TM-align's own report says "Length of Chain_1: 390 residues" for row 60,
 * whose aligned range is 498 -- because the chain it rebuilds comes back split
 * in two and TM-align reads only the first chain. Row 60 is displayed as
 * 0.72573; the same TM-align binary, extracted from the page and run on the
 * whole structure, returns 0.77119, and that is the value expected here. On
 * the other 152 rows this port and the upstream page agree to every printed
 * digit (checked 2026-09-25, worst difference 0).
 *
 * Runs under macOS's own JavaScript engine -- there is no node on the
 * workstation or the server:
 *
 *   curl -s 'https://foldseek.maizegdb.org/?uniprot=Zm00001eb374230' > /tmp/bz1.html
 *   osascript -l JavaScript tools/tests/foldseek_tmscore_check.js /tmp/bz1.html src/js/mgdb-tmalign.js
 *
 * Prints one line per hit and exits non-zero on any mismatch past 1e-5,
 * which is the precision the upstream page prints.
 */

ObjC.import('Foundation');

var EXPECTED = [
  [0, 'A0A1D6NT88', 0.92672], [1, 'P16167', 0.99578], [3, 'C5Z5X2', 0.95798],
  [5, 'A0A0P0WU30', 0.96911], [8, 'Q9LFJ8', 0.91869], [12, 'I1M2L8', 0.90837],
  [30, 'Q9LMF0', 0.8298], [60, 'A0A1Z5R994', 0.77119], [100, 'Q7XHR3', 0.83084],
  [140, 'O75795', 0.62897], [150, 'Q9H553', 0.49023], [155, 'P53954', 0.51211],
  [158, 'O14081', 0.44073], [160, 'P38426', 0.41231], [164, 'O14190', 0.52727]
];

function readFile(path) {
  var text = $.NSString.stringWithContentsOfFileEncodingError(path, $.NSUTF8StringEncoding, null);
  if (!text) { throw new Error('cannot read ' + path); }
  return text.js;
}

function run(argv) {
  if (argv.length < 2) { return 'usage: foldseek_tmscore_check.js <upstream.html> <mgdb-tmalign.js>'; }
  var html = readFile(argv[0]);
  eval(readFile(argv[1]));
  var TM = this.MGDBTMalign;

  var start = html.indexOf('render([');
  var end = html.lastIndexOf(']);</script>');
  var raw = html.slice(start + 'render('.length, end + 1).replace(/,\s*([\]}])/g, '$1');
  var data = JSON.parse(raw)[0];
  var qca = data.query.qca.split(',').map(Number);

  var lines = [], worst = 0, failures = 0;
  EXPECTED.forEach(function (row) {
    var hit = data.alignments[row[0]];
    var tca = hit.tca.split(',').map(Number);
    var pairs = TM.pairsFromAlignment(hit.qAln, hit.dbAln, hit.qStartPos, hit.dbStartPos, qca, tca);
    var xlen = hit.dbEndPos - hit.dbStartPos + 1;
    var ylen = hit.qEndPos - hit.qStartPos + 1;
    var t0 = Date.now();
    var got = TM.scoreFixed(pairs.target, pairs.query, xlen, ylen);
    var ms = Date.now() - t0;
    var diff = Math.abs(got.tmTarget - row[2]);
    worst = Math.max(worst, diff);
    var ok = hit.target === row[1] && diff < 1e-5;
    if (!ok) { failures++; }
    lines.push((ok ? 'ok   ' : 'FAIL ') + row[0] + ' ' + hit.target + ' expected ' + row[2]
      + ' got ' + got.tmTarget.toFixed(5) + ' (query-normalized ' + got.tmQuery.toFixed(5)
      + ', rmsd ' + got.rmsd.toFixed(2) + ', ' + got.aligned + ' pairs, ' + ms + ' ms)');
  });
  lines.push('worst difference ' + worst.toExponential(2) + '; ' + failures + ' failure(s) of ' + EXPECTED.length);
  if (failures) { lines.push('FAILED'); }
  return lines.join('\n');
}
