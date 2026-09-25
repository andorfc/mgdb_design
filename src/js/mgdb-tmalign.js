/* ==========================================================================
   mgdb-tmalign.js — TM-score of a fixed structural alignment
   --------------------------------------------------------------------------
   A JavaScript port of the part of TM-align that runs under its `-I` option:
   given two Calpha traces and an alignment that must not change, find the
   superposition that maximizes the TM-score and report it.

   Why this exists
   ---------------
   /foldseek shows a TM-score beside every superposition, as the application
   it replaces did. That application ran TM-align itself, compiled to
   WebAssembly, as

       TMalign target.pdb query.pdb -I alignment.fasta -m matrix.txt

   with the target cropped to the aligned range and the query cropped to its
   own, and displayed the "normalized by length of Chain_1" line -- the
   target's aligned length. Under -I, TM-align skips its whole alignment search
   (every branch guarded by !bAlignStick), keeps every aligned pair, and runs
   one final TMscore8_search per normalization. That final step is all this
   file ports, which is why it is small. It is a port rather than a
   reimplementation so the number agrees with the one readers were shown
   before, to the printed five decimals: the Kabsch routine, the fragment
   ladder, the iteration count and the convergence test are TM-align's own.
   Verified against the upstream page's displayed scores; see
   tools/tests/foldseek_tmscore_check.js.

   Source: TMalign.cpp, version 2022/04/12, the file tmalign-wasm compiles.

   TM-align license, reproduced as it requires:

     TM-align: sequence-independent structure alignment of monomer proteins
     by TM-score superposition. Please report issues to
     zhanglab@zhanggroup.org

     References to cite:
     Y Zhang, J Skolnick. Nucl Acids Res 33, 2302-9 (2005)

     DISCLAIMER:
      Permission to use, copy, modify, and distribute the Software for any
      purpose, with or without fee, is hereby granted, provided that the
      notices on the head, the reference information, and this copyright
      notice appear in all copies or substantial portions of the Software.
      It is provided "as is" without express or implied warranty.

   Exposes window.MGDBTMalign. No DOM, no dependencies, so the same file runs
   in a browser and under a command-line JavaScript engine for testing.
   ========================================================================== */

(function (root) {
  'use strict';

  /* ------------------------------------------------------------------------
   * Storage
   *
   * Coordinates are flat Float64Arrays, [x0, y0, z0, x1, ...], and every
   * buffer the search touches is allocated once per call to scoreFixed()
   * rather than once per step. The fragment search runs Kabsch hundreds of
   * thousands of times on a large protein; written with nested arrays and a
   * fresh set of 3x3 matrices per call, a 2,158-residue alignment (dek1 and
   * its rice ortholog) ran past 30 seconds. The arithmetic is unchanged --
   * same operations, same order -- so the scores are too.
   * ------------------------------------------------------------------------ */

  var KT = new Float64Array(3);   /* translation from the last kabsch() */
  var KU = new Float64Array(9);   /* rotation, row-major: u[i][j] = KU[3*i+j] */
  var A = new Float64Array(9);
  var B = new Float64Array(9);
  var R = new Float64Array(9);
  var E = new Float64Array(3);
  var RR = new Float64Array(6);
  var SS = new Float64Array(6);
  var IP = [0, 1, 3, 1, 2, 4, 3, 4, 5];
  var IP2312 = [1, 2, 0, 1];

  /* ------------------------------------------------------------------------
   * Kabsch, as TM-align writes it: the rotation that moves x onto y, left in
   * KT/KU, and the residual returned when mode is 0 or 2. A line-for-line
   * port, tolerances included, so a degenerate fragment fails the way it does
   * in the C++.
   * ------------------------------------------------------------------------ */
  function kabsch(x, y, n, mode) {
    var i, j, m, m1, l, k;
    var e0 = 0, rms1 = 0, d, h, g;
    var cth, sth, sqrth, p, det, sigma;
    var sqrt3 = 1.73205080756888, tol = 0.01;
    var aFailed = 0, bFailed = 0;
    var epsilon = 0.00000001;

    KT[0] = 0; KT[1] = 0; KT[2] = 0;
    for (i = 0; i < 9; i++) { KU[i] = 0; A[i] = 0; R[i] = 0; B[i] = 0; }
    KU[0] = 1; KU[4] = 1; KU[8] = 1;
    A[0] = 1; A[4] = 1; A[8] = 1;

    if (n < 1) { return 0; }

    var s10 = 0, s11 = 0, s12 = 0, s20 = 0, s21 = 0, s22 = 0;
    var sx0 = 0, sx1 = 0, sx2 = 0, sy0 = 0, sy1 = 0, sy2 = 0, sz0 = 0, sz1 = 0, sz2 = 0;
    var c10, c11, c12, c20, c21, c22, base;
    for (i = 0; i < n; i++) {
      base = 3 * i;
      c10 = x[base]; c11 = x[base + 1]; c12 = x[base + 2];
      c20 = y[base]; c21 = y[base + 1]; c22 = y[base + 2];
      s10 += c10; s20 += c20;
      s11 += c11; s21 += c21;
      s12 += c12; s22 += c22;
      sx0 += c10 * c20; sx1 += c10 * c21; sx2 += c10 * c22;
      sy0 += c11 * c20; sy1 += c11 * c21; sy2 += c11 * c22;
      sz0 += c12 * c20; sz1 += c12 * c21; sz2 += c12 * c22;
    }
    var xc0 = s10 / n, xc1 = s11 / n, xc2 = s12 / n;
    var yc0 = s20 / n, yc1 = s21 / n, yc2 = s22 / n;
    if (mode === 2 || mode === 0) {
      var dx0, dx1, dx2, dy0, dy1, dy2;
      for (i = 0; i < n; i++) {
        base = 3 * i;
        dx0 = x[base] - xc0; dy0 = y[base] - yc0;
        e0 += dx0 * dx0 + dy0 * dy0;
        dx1 = x[base + 1] - xc1; dy1 = y[base + 1] - yc1;
        e0 += dx1 * dx1 + dy1 * dy1;
        dx2 = x[base + 2] - xc2; dy2 = y[base + 2] - yc2;
        e0 += dx2 * dx2 + dy2 * dy2;
      }
    }
    /* r[j][0..2] = s{x,y,z}[j] - s1[0..2] * s2[j] / n */
    R[0] = sx0 - s10 * s20 / n; R[1] = sy0 - s11 * s20 / n; R[2] = sz0 - s12 * s20 / n;
    R[3] = sx1 - s10 * s21 / n; R[4] = sy1 - s11 * s21 / n; R[5] = sz1 - s12 * s21 / n;
    R[6] = sx2 - s10 * s22 / n; R[7] = sy2 - s11 * s22 / n; R[8] = sz2 - s12 * s22 / n;

    det = R[0] * (R[4] * R[8] - R[5] * R[7])
        - R[1] * (R[3] * R[8] - R[5] * R[6])
        + R[2] * (R[3] * R[7] - R[4] * R[6]);
    sigma = det;

    m = 0;
    for (j = 0; j < 3; j++) {
      for (i = 0; i <= j; i++) {
        RR[m] = R[i] * R[j] + R[3 + i] * R[3 + j] + R[6 + i] * R[6 + j];
        m++;
      }
    }

    var spur = (RR[0] + RR[2] + RR[5]) / 3.0;
    var cof = (((((RR[2] * RR[5] - RR[4] * RR[4]) + RR[0] * RR[5])
        - RR[3] * RR[3]) + RR[0] * RR[2]) - RR[1] * RR[1]) / 3.0;
    det = det * det;

    E[0] = spur; E[1] = spur; E[2] = spur;

    if (spur > 0) {
      d = spur * spur;
      h = d - cof;
      g = (spur * cof - det) / 2.0 - spur * h;

      if (h > 0) {
        sqrth = Math.sqrt(h);
        d = h * h * h - g * g;
        if (d < 0.0) { d = 0.0; }
        d = Math.atan2(Math.sqrt(d), -g) / 3.0;
        cth = sqrth * Math.cos(d);
        sth = sqrth * sqrt3 * Math.sin(d);
        E[0] = (spur + cth) + cth;
        E[1] = (spur - cth) + sth;
        E[2] = (spur - cth) - sth;

        if (mode !== 0) {
          for (l = 0; l < 3; l = l + 2) {
            d = E[l];
            SS[0] = (d - RR[2]) * (d - RR[5]) - RR[4] * RR[4];
            SS[1] = (d - RR[5]) * RR[1] + RR[3] * RR[4];
            SS[2] = (d - RR[0]) * (d - RR[5]) - RR[3] * RR[3];
            SS[3] = (d - RR[2]) * RR[3] + RR[1] * RR[4];
            SS[4] = (d - RR[0]) * RR[4] + RR[1] * RR[3];
            SS[5] = (d - RR[0]) * (d - RR[2]) - RR[1] * RR[1];

            for (k = 0; k < 6; k++) {
              if (Math.abs(SS[k]) <= epsilon) { SS[k] = 0.0; }
            }

            if (Math.abs(SS[0]) >= Math.abs(SS[2])) {
              j = 0;
              if (Math.abs(SS[0]) < Math.abs(SS[5])) { j = 2; }
            } else if (Math.abs(SS[2]) >= Math.abs(SS[5])) {
              j = 1;
            } else {
              j = 2;
            }

            d = 0.0;
            j = 3 * j;
            for (i = 0; i < 3; i++) {
              k = IP[i + j];
              A[3 * i + l] = SS[k];
              d = d + SS[k] * SS[k];
            }
            if (d > epsilon) { d = 1.0 / Math.sqrt(d); } else { d = 0.0; }
            for (i = 0; i < 3; i++) { A[3 * i + l] = A[3 * i + l] * d; }
          }

          d = A[0] * A[2] + A[3] * A[5] + A[6] * A[8];
          if ((E[0] - E[1]) > (E[1] - E[2])) { m1 = 2; m = 0; } else { m1 = 0; m = 2; }
          p = 0;
          for (i = 0; i < 3; i++) {
            A[3 * i + m1] = A[3 * i + m1] - d * A[3 * i + m];
            p = p + A[3 * i + m1] * A[3 * i + m1];
          }
          if (p <= tol) {
            p = 1.0;
            for (i = 0; i < 3; i++) {
              if (p < Math.abs(A[3 * i + m])) { continue; }
              p = Math.abs(A[3 * i + m]);
              j = i;
            }
            k = IP2312[j];
            l = IP2312[j + 1];
            p = Math.sqrt(A[3 * k + m] * A[3 * k + m] + A[3 * l + m] * A[3 * l + m]);
            if (p > tol) {
              A[3 * j + m1] = 0.0;
              A[3 * k + m1] = -A[3 * l + m] / p;
              A[3 * l + m1] = A[3 * k + m] / p;
            } else {
              aFailed = 1;
            }
          } else {
            p = 1.0 / Math.sqrt(p);
            for (i = 0; i < 3; i++) { A[3 * i + m1] = A[3 * i + m1] * p; }
          }
          if (aFailed !== 1) {
            A[1] = A[5] * A[6] - A[3] * A[8];
            A[4] = A[8] * A[0] - A[6] * A[2];
            A[7] = A[2] * A[3] - A[0] * A[5];
          }
        }
      }

      if (mode !== 0 && aFailed !== 1) {
        for (l = 0; l < 2; l++) {
          d = 0.0;
          for (i = 0; i < 3; i++) {
            B[3 * i + l] = R[3 * i] * A[l] + R[3 * i + 1] * A[3 + l] + R[3 * i + 2] * A[6 + l];
            d = d + B[3 * i + l] * B[3 * i + l];
          }
          if (d > epsilon) { d = 1.0 / Math.sqrt(d); } else { d = 0.0; }
          for (i = 0; i < 3; i++) { B[3 * i + l] = B[3 * i + l] * d; }
        }
        d = B[0] * B[1] + B[3] * B[4] + B[6] * B[7];
        p = 0.0;
        for (i = 0; i < 3; i++) {
          B[3 * i + 1] = B[3 * i + 1] - d * B[3 * i];
          p += B[3 * i + 1] * B[3 * i + 1];
        }
        if (p <= tol) {
          p = 1.0;
          for (i = 0; i < 3; i++) {
            if (p < Math.abs(B[3 * i])) { continue; }
            p = Math.abs(B[3 * i]);
            j = i;
          }
          k = IP2312[j];
          l = IP2312[j + 1];
          p = Math.sqrt(B[3 * k] * B[3 * k] + B[3 * l] * B[3 * l]);
          if (p > tol) {
            B[3 * j + 1] = 0.0;
            B[3 * k + 1] = -B[3 * l] / p;
            B[3 * l + 1] = B[3 * k] / p;
          } else {
            bFailed = 1;
          }
        } else {
          p = 1.0 / Math.sqrt(p);
          for (i = 0; i < 3; i++) { B[3 * i + 1] = B[3 * i + 1] * p; }
        }
        if (bFailed !== 1) {
          B[2] = B[3] * B[7] - B[4] * B[6];
          B[5] = B[6] * B[1] - B[7] * B[0];
          B[8] = B[0] * B[4] - B[1] * B[3];
          for (i = 0; i < 3; i++) {
            for (j = 0; j < 3; j++) {
              KU[3 * i + j] = B[3 * i] * A[3 * j] + B[3 * i + 1] * A[3 * j + 1] + B[3 * i + 2] * A[3 * j + 2];
            }
          }
        }
        KT[0] = ((yc0 - KU[0] * xc0) - KU[1] * xc1) - KU[2] * xc2;
        KT[1] = ((yc1 - KU[3] * xc0) - KU[4] * xc1) - KU[5] * xc2;
        KT[2] = ((yc2 - KU[6] * xc0) - KU[7] * xc1) - KU[8] * xc2;
      }
    } else {
      KT[0] = ((yc0 - KU[0] * xc0) - KU[1] * xc1) - KU[2] * xc2;
      KT[1] = ((yc1 - KU[3] * xc0) - KU[4] * xc1) - KU[5] * xc2;
      KT[2] = ((yc2 - KU[6] * xc0) - KU[7] * xc1) - KU[8] * xc2;
    }

    for (i = 0; i < 3; i++) {
      if (E[i] < 0) { E[i] = 0; }
      E[i] = Math.sqrt(E[i]);
    }
    d = E[2];
    if (sigma < 0.0) { d = -d; }
    d = (d + E[1]) + E[0];

    if (mode === 2 || mode === 0) {
      rms1 = (e0 - d) - d;
      if (rms1 < 0.0) { rms1 = 0.0; }
    }
    return rms1;
  }

  /* x1 = t + u . x, per point: t[i] + (u[i][0]*x0 + u[i][1]*x1 + u[i][2]*x2). */
  function rotateAll(src, dst, len, t, u) {
    var base, p0, p1, p2;
    for (var i = 0; i < len; i++) {
      base = 3 * i;
      p0 = src[base]; p1 = src[base + 1]; p2 = src[base + 2];
      dst[base]     = t[0] + (u[0] * p0 + u[1] * p1 + u[2] * p2);
      dst[base + 1] = t[1] + (u[3] * p0 + u[4] * p1 + u[5] * p2);
      dst[base + 2] = t[2] + (u[6] * p0 + u[7] * p1 + u[8] * p2);
    }
  }

  /* score_fun8: collect the pairs closer than d, relaxing d by 0.5 A at a
     time until at least three qualify, and return the TM-score sum over
     Lnorm. method 8 sums only pairs within score_d8; the final scoring uses
     method 0, every pair. The count of pairs collected is left in nCut. */
  var nCut = 0;

  function scoreFun8(xt, ytm, nAli, d, iAli, method, Lnorm, scoreD8, d0) {
    var dTmp = d * d;
    var d02 = d0 * d0;
    var cut8 = scoreD8 * scoreD8;
    var inc = 0, count, sum, i, di, base, dx, dy, dz;
    for (;;) {
      count = 0;
      sum = 0;
      for (i = 0; i < nAli; i++) {
        base = 3 * i;
        dx = xt[base] - ytm[base];
        dy = xt[base + 1] - ytm[base + 1];
        dz = xt[base + 2] - ytm[base + 2];
        di = dx * dx + dy * dy + dz * dz;
        if (di < dTmp) { iAli[count] = i; count++; }
        if (method === 8) {
          if (di <= cut8) { sum += 1 / (1 + di / d02); }
        } else {
          sum += 1 / (1 + di / d02);
        }
      }
      if (count < 3 && nAli > 3) {
        inc++;
        var dinc = d + inc * 0.5;
        dTmp = dinc * dinc;
      } else {
        break;
      }
    }
    nCut = count;
    return sum / Lnorm;
  }

  /* TMscore8_search: superpose on every fragment of length Lali, Lali/2,
     Lali/4 ... down to 4, extend each by iterating Kabsch over the pairs it
     brings within reach, and keep the best-scoring rotation in bestT/bestU. */
  function tmscore8Search(xtm, ytm, lali, simplifyStep, method, localD0Search, Lnorm, scoreD8, d0, bestT, bestU) {
    var nIt = 20, nInitMax = 6, lIniMin = 4;
    var lIni = [];
    var i, k, m, kk, ka, base, from;
    if (lali < lIniMin) { lIniMin = lali; }

    var nInit = 0;
    for (i = 0; i < nInitMax - 1; i++) {
      nInit++;
      lIni[i] = Math.floor(lali / Math.pow(2.0, i));
      if (lIni[i] <= lIniMin) { lIni[i] = lIniMin; break; }
    }
    if (i === nInitMax - 1) { nInit++; lIni[i] = lIniMin; }

    var r1 = new Float64Array(3 * lali);
    var r2 = new Float64Array(3 * lali);
    var xt = new Float64Array(3 * lali);
    var iAli = new Int32Array(lali);
    var kAli = new Int32Array(lali);
    var scoreMax = -1;
    var score, cut, d;

    function keep(value) {
      if (value > scoreMax) {
        scoreMax = value;
        bestT[0] = KT[0]; bestT[1] = KT[1]; bestT[2] = KT[2];
        for (var q = 0; q < 9; q++) { bestU[q] = KU[q]; }
      }
    }

    for (var iInit = 0; iInit < nInit; iInit++) {
      var lFrag = lIni[iInit];
      var iLMax = lali - lFrag;
      i = 0;
      for (;;) {
        ka = 0;
        for (k = 0; k < lFrag; k++) {
          kk = k + i;
          base = 3 * k;
          from = 3 * kk;
          r1[base] = xtm[from]; r1[base + 1] = xtm[from + 1]; r1[base + 2] = xtm[from + 2];
          r2[base] = ytm[from]; r2[base + 1] = ytm[from + 1]; r2[base + 2] = ytm[from + 2];
          kAli[ka] = kk;
          ka++;
        }
        kabsch(r1, r2, lFrag, 1);
        rotateAll(xtm, xt, lali, KT, KU);

        d = localD0Search - 1;
        score = scoreFun8(xt, ytm, lali, d, iAli, method, Lnorm, scoreD8, d0);
        cut = nCut;
        keep(score);

        d = localD0Search + 1;
        for (var it = 0; it < nIt; it++) {
          ka = 0;
          for (k = 0; k < cut; k++) {
            m = iAli[k];
            base = 3 * k;
            from = 3 * m;
            r1[base] = xtm[from]; r1[base + 1] = xtm[from + 1]; r1[base + 2] = xtm[from + 2];
            r2[base] = ytm[from]; r2[base + 1] = ytm[from + 1]; r2[base + 2] = ytm[from + 2];
            kAli[ka] = m;
            ka++;
          }
          kabsch(r1, r2, cut, 1);
          rotateAll(xtm, xt, lali, KT, KU);
          score = scoreFun8(xt, ytm, lali, d, iAli, method, Lnorm, scoreD8, d0);
          cut = nCut;
          keep(score);

          if (cut === ka) {
            for (k = 0; k < cut; k++) {
              if (iAli[k] !== kAli[k]) { break; }
            }
            if (k === cut) { break; }
          }
        }

        if (i < iLMax) {
          i = i + simplifyStep;
          if (i > iLMax) { i = iLMax; }
        } else {
          break;
        }
      }
    }
    return scoreMax;
  }

  /* parameter_set4search and parameter_set4final, protein branch only. */
  function paramsSearch(xlen, ylen) {
    var Lnorm = Math.min(xlen, ylen);
    var d0 = Lnorm <= 19 ? 0.168 : (1.24 * Math.pow(Lnorm * 1.0 - 15, 1.0 / 3) - 1.8);
    d0 = d0 + 0.8;
    return {
      Lnorm: Lnorm, d0: d0, d0Search: Math.min(8, Math.max(4.5, d0)),
      scoreD8: 1.5 * Math.pow(Lnorm * 1.0, 0.3) + 3.5
    };
  }

  function paramsFinal(len) {
    var d0 = len <= 21 ? 0.5 : (1.24 * Math.pow(len * 1.0 - 15, 1.0 / 3) - 1.8);
    if (d0 < 0.5) { d0 = 0.5; }
    return { Lnorm: len, d0: d0, d0Search: Math.min(8, Math.max(4.5, d0)) };
  }

  function flatten(points, n) {
    var out = new Float64Array(3 * n);
    for (var i = 0; i < n; i++) {
      out[3 * i] = points[i][0];
      out[3 * i + 1] = points[i][1];
      out[3 * i + 2] = points[i][2];
    }
    return out;
  }

  /* The -I final scoring. x is chain 1 (the target, to be moved), y chain 2
     (the query); xPairs[k] and yPairs[k] are the k-th aligned pair in the
     order TM-align walks them, by ascending query position. xlen and ylen are
     the cropped chain lengths, which is what the scores are normalized by.

     Returns
       tmTarget   normalized by xlen -- the "Chain_1" line, the one shown
       tmQuery    normalized by ylen
       t, u       the rotation that moves the target onto the query; it is
                  the one TM-align writes with -m, from the ylen search
       rmsd       over every aligned pair, after optimal superposition
       aligned    the number of aligned pairs */
  function scoreFixed(xPairs, yPairs, xlen, ylen) {
    var n = Math.min(xPairs.length, yPairs.length);
    if (n < 3) { return null; }
    var x = flatten(xPairs, n);
    var y = flatten(yPairs, n);
    var search = paramsSearch(xlen, ylen);

    var rmsd = Math.sqrt(kabsch(x, y, n, 0) / n);

    var tQuery = new Float64Array(3), uQuery = new Float64Array(9);
    var pq = paramsFinal(ylen);
    var tmQuery = tmscore8Search(x, y, n, 1, 0, pq.d0Search, pq.Lnorm, search.scoreD8, pq.d0, tQuery, uQuery);

    var tTarget = new Float64Array(3), uTarget = new Float64Array(9);
    var pt = paramsFinal(xlen);
    var tmTarget = tmscore8Search(x, y, n, 1, 0, pt.d0Search, pt.Lnorm, search.scoreD8, pt.d0, tTarget, uTarget);

    return {
      tmTarget: tmTarget,
      tmQuery: tmQuery,
      d0Target: pt.d0,
      d0Query: pq.d0,
      t: [tQuery[0], tQuery[1], tQuery[2]],
      u: [[uQuery[0], uQuery[1], uQuery[2]], [uQuery[3], uQuery[4], uQuery[5]], [uQuery[6], uQuery[7], uQuery[8]]],
      rmsd: rmsd,
      aligned: n
    };
  }

  /* Pair up the Calpha coordinates of a Foldseek hit.
       qAln, tAln   the aligned strings, gaps as '-'
       qStart,tStart 1-based first aligned residue of each
       qCa, tCa     flat [x,y,z,x,y,z,...] arrays for the WHOLE chains
     Returns the pairs in query order plus the residue numbers, which the
     viewer needs to draw the correspondence. */
  function pairsFromAlignment(qAln, tAln, qStart, tStart, qCa, tCa) {
    var xs = [], ys = [], qRes = [], tRes = [];
    var qi = qStart - 1, ti = tStart - 1;
    var len = Math.min(qAln.length, tAln.length);
    for (var c = 0; c < len; c++) {
      var qGap = qAln.charAt(c) === '-';
      var tGap = tAln.charAt(c) === '-';
      if (!qGap && !tGap) {
        var qb = qi * 3, tb = ti * 3;
        if (qb + 2 < qCa.length && tb + 2 < tCa.length) {
          ys.push([qCa[qb], qCa[qb + 1], qCa[qb + 2]]);
          xs.push([tCa[tb], tCa[tb + 1], tCa[tb + 2]]);
          qRes.push(qi + 1);
          tRes.push(ti + 1);
        }
      }
      if (!qGap) { qi++; }
      if (!tGap) { ti++; }
    }
    return { target: xs, query: ys, queryResidues: qRes, targetResidues: tRes };
  }

  root.MGDBTMalign = {
    scoreFixed: scoreFixed,
    pairsFromAlignment: pairsFromAlignment,
    paramsFinal: paramsFinal
  };
})(typeof window !== 'undefined' ? window : this);
