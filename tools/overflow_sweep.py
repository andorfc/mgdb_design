"""Load every modern MaizeGDB page in headless Chrome and report horizontal
document overflow, naming the element that causes it.

Written 2026-09-09 after three overflow bugs (/new_genes, /data_center/overgo,
/jobs) turned up in three consecutive audit items. A static grep cannot find
these -- `minmax(260px, 1fr)` is safe while a bare `1fr` may not be, and it
depends entirely on whether the grid happens to hold a long unbreakable token --
so the detector is a runtime one.

Usage
-----
    python3 tools/overflow_sweep.py                  # every modern page, 375px
    python3 tools/overflow_sweep.py /jobs /new_genes # just these
    SWEEP_WIDTH=768 python3 tools/overflow_sweep.py  # another breakpoint
    SWEEP_SETTLE=6  python3 tools/overflow_sweep.py  # slower Ajax pages

Needs `websocket-client` (pip install websocket-client) and Google Chrome. It
drives Chrome over the DevTools Protocol and points it at the dev ORIGIN with
--host-resolver-rules, so it bypasses Cloudflare and does not look like a scrape
at the edge.

Reads overflow_sweep_urls.json beside this script -- a plain list of paths. Record routes need
a REAL id: a bare /data_center/<type> answers the 404 page, which measures clean
and hides the page you meant to test. Nine routes did exactly that on the first
run here.

Two detector notes, both learned the hard way:

  * An element's rect is not enough. A block element keeps its container's width
    while its TEXT spills out (white-space: nowrap, or an unbreakable token), so
    text runs are measured separately with a Range.
  * Content inside a scroll container is NOT overflow. getBoundingClientRect()
    reports every wide row of a scrolling table as sticking out; an element only
    counts if no ancestor that itself fits inside the viewport clips it.

Self-test before trusting a clean run: inject a wide div and a long unbreakable
token and confirm both are caught. A sweep that reports nothing proves nothing
until you have seen it report something.
"""
import json, os, subprocess, sys, time, urllib.request
import websocket

SP      = os.path.dirname(os.path.abspath(__file__))
ORIGIN  = "10.24.27.235"
HOST    = "claude.maizegdb.org"
PORT    = 9222
WIDTH   = int(os.environ.get("SWEEP_WIDTH", "375"))
SETTLE  = float(os.environ.get("SWEEP_SETTLE", "2.5"))   # let record-page Ajax land
NAV_TMO = 45

DETECTOR = r"""
(() => {
  const d = document.documentElement, vw = d.clientWidth;
  const overflow = d.scrollWidth - vw;
  const info = { vw, docScrollWidth: d.scrollWidth, overflow,
                 title: document.title.slice(0,80),
                 bodyClass: (document.body.className||'').slice(0,120),
                 offenders: [] };
  if (overflow <= 0) return info;

  const sel = el => {
    if (!el) return null;
    let s = el.tagName.toLowerCase();
    if (el.id) s += '#' + el.id;
    if (typeof el.className === 'string' && el.className.trim())
      s += '.' + el.className.trim().split(/\s+/).slice(0,3).join('.');
    return s;
  };
  const path = el => { const out=[]; let e=el; for(let i=0;i<5&&e&&e!==document.body;i++){out.unshift(sel(e));e=e.parentElement;} return out.join(' < '); };

  // An element contributes to DOCUMENT overflow only if no ancestor that itself
  // fits inside the viewport clips it horizontally.
  const raw = [];
  for (const el of document.querySelectorAll('body *')) {
    const r = el.getBoundingClientRect();
    if (r.width === 0 && r.height === 0) continue;
    if (r.right <= vw + 1) continue;
    let p = el.parentElement, clipped = false;
    while (p && p !== d) {
      const cs = getComputedStyle(p);
      if (cs.overflowX !== 'visible' && p.getBoundingClientRect().right <= vw + 1) { clipped = true; break; }
      p = p.parentElement;
    }
    if (!clipped) raw.push(el);
  }
  const set = new Set(raw);
  let roots = raw.filter(el => !set.has(el.parentElement));

  // A block element's rect stays at its container's width while its TEXT spills
  // out of it (white-space: nowrap, or an unbreakable token). Element rects miss
  // that entirely, so measure text runs with a Range as well.
  const textOffenders = [];
  {
    const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    const rng = document.createRange();
    let n;
    while ((n = w.nextNode())) {
      if (!n.textContent.trim()) continue;
      let rr;
      try { rng.selectNodeContents(n); rr = rng.getBoundingClientRect(); } catch (e) { continue; }
      if (!rr || rr.width === 0 || rr.right <= vw + 1) continue;
      const host = n.parentElement;
      if (!host) continue;
      let p2 = host, clipped = false;
      while (p2 && p2 !== d) {
        const cs2 = getComputedStyle(p2);
        if (cs2.overflowX !== 'visible' && p2.getBoundingClientRect().right <= vw + 1) { clipped = true; break; }
        p2 = p2.parentElement;
      }
      if (!clipped && !set.has(host)) textOffenders.push(host);
    }
  }
  for (const t of textOffenders) if (!set.has(t)) { set.add(t); roots.push(t); }

  // Never report an overflow with nothing named: fall back to the widest boxes.
  info.rawCount = raw.length;
  info.textOffenderCount = textOffenders.length;
  if (roots.length === 0) {
    info.fallback = true;
    roots = [...document.querySelectorAll('body *')]
      .filter(el => { const r = el.getBoundingClientRect(); return r.right > vw + 1 && (r.width || r.height); })
      .sort((a, b) => b.getBoundingClientRect().right - a.getBoundingClientRect().right)
      .slice(0, 3);
  }
  roots.sort((a, b) => b.getBoundingClientRect().right - a.getBoundingClientRect().right);

  const probe = document.createElement('span');
  for (const el of roots.slice(0, 6)) {
    const r  = el.getBoundingClientRect();
    const cs = getComputedStyle(el);
    const par = el.parentElement, pcs = par ? getComputedStyle(par) : null;
    // widest unbreakable token inside this offender
    let worst = null;
    const w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    let n, seen = new Set();
    while ((n = w.nextNode())) {
      for (const tok of (n.textContent.match(/\S{18,}/g) || [])) {
        if (seen.has(tok)) continue; seen.add(tok);
        const tcs = getComputedStyle(n.parentElement);
        probe.style.cssText = 'position:absolute;visibility:hidden;white-space:pre;font:' + tcs.font;
        probe.textContent = tok;
        document.body.appendChild(probe);
        const px = probe.getBoundingClientRect().width;
        document.body.removeChild(probe);
        if (!worst || px > worst.px)
          worst = { px: Math.round(px), chars: tok.length,
                    text: tok.length > 70 ? tok.slice(0,70)+'…' : tok,
                    inTag: n.parentElement.tagName.toLowerCase(),
                    overflowWrap: tcs.overflowWrap, wordBreak: tcs.wordBreak };
      }
    }
    info.offenders.push({
      sel: sel(el), path: path(el),
      right: Math.round(r.right), width: Math.round(r.width), pastViewport: Math.round(r.right - vw),
      display: cs.display, position: cs.position, minWidth: cs.minWidth,
      overflowX: cs.overflowX, overflowWrap: cs.overflowWrap, whiteSpace: cs.whiteSpace,
      parentSel: sel(par),
      parentDisplay: pcs ? pcs.display : null,
      parentGridCols: pcs ? pcs.gridTemplateColumns : null,
      parentOverflowX: pcs ? pcs.overflowX : null,
      widestToken: worst
    });
  }
  return info;
})()
"""

class CDP:
    def __init__(self, ws): self.ws = ws; self.i = 0
    def send(self, method, params=None, sid=None, timeout=NAV_TMO):
        self.i += 1
        msg = {"id": self.i, "method": method, "params": params or {}}
        if sid: msg["sessionId"] = sid
        self.ws.send(json.dumps(msg))
        self.ws.settimeout(timeout)
        while True:
            m = json.loads(self.ws.recv())
            if m.get("id") == self.i: return m
    def wait_event(self, name, sid, timeout):
        end = time.time() + timeout
        while time.time() < end:
            self.ws.settimeout(max(0.5, end - time.time()))
            try: m = json.loads(self.ws.recv())
            except Exception: return False
            if m.get("method") == name and (not sid or m.get("sessionId") == sid): return True
        return False

def main():
    urls = json.load(open(os.path.join(SP, "overflow_sweep_urls.json")))
    only = sys.argv[1:] 
    if only: urls = only

    chrome = ("/Applications/Google Chrome.app/Contents/MacOS/Google Chrome")
    proc = subprocess.Popen([chrome, "--headless=new", f"--remote-debugging-port={PORT}",
        f"--host-resolver-rules=MAP {HOST} {ORIGIN}", "--ignore-certificate-errors",
        "--no-first-run", "--no-default-browser-check", "--disable-gpu",
        "--remote-allow-origins=*",
        f"--user-data-dir={SP}/chrome-profile", "about:blank"],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        wsurl = None
        for _ in range(60):
            try:
                wsurl = json.load(urllib.request.urlopen(f"http://127.0.0.1:{PORT}/json/version"))["webSocketDebuggerUrl"]; break
            except Exception: time.sleep(0.5)
        if not wsurl: print("chrome did not start"); return 1

        cdp = CDP(websocket.create_connection(wsurl, timeout=NAV_TMO, max_size=None))
        tid = cdp.send("Target.createTarget", {"url": "about:blank"})["result"]["targetId"]
        sid = cdp.send("Target.attachToTarget", {"targetId": tid, "flatten": True})["result"]["sessionId"]
        cdp.send("Page.enable", {}, sid)
        cdp.send("Emulation.setDeviceMetricsOverride",
                 {"width": WIDTH, "height": 812, "deviceScaleFactor": 2, "mobile": True}, sid)

        results = []
        for i, u in enumerate(urls, 1):
            rec = {"url": u}
            try:
                cdp.send("Page.navigate", {"url": f"http://{HOST}{u}"}, sid)
                cdp.wait_event("Page.loadEventFired", sid, NAV_TMO)
                time.sleep(SETTLE)
                r = cdp.send("Runtime.evaluate",
                             {"expression": DETECTOR, "returnByValue": True, "awaitPromise": False},
                             sid, timeout=NAV_TMO)
                rec.update(r["result"]["result"]["value"])
            except Exception as e:
                rec["error"] = f"{type(e).__name__}: {e}"
            results.append(rec)
            flag = "OVERFLOW %4s" % rec.get("overflow") if rec.get("overflow", 0) > 0 else ("ERR" if "error" in rec else "ok")
            print("[%3d/%d] %-11s %s" % (i, len(urls), flag, u), flush=True)
            json.dump(results, open(os.path.join(SP, "sweep_results.json"), "w"), indent=1)
        bad = [r for r in results if r.get("overflow", 0) > 0]
        print("\n=== %d of %d pages overflow at %dpx ===" % (len(bad), len(results), WIDTH))
        return 0
    finally:
        proc.terminate()

sys.exit(main())
