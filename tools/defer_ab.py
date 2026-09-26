"""A/B test of `defer` on a page's scripts, without deploying anything.

    python3 tools/defer_ab.py [--profile desktop|slow] [--runs N] URL

The page's HTML is intercepted in headless Chrome (CDP Fetch, response stage)
and, for the `defer` variant, every <script src> in <head> gains `defer`. The
`sync` variant goes through the same interception untouched, so both pay the
same overhead; runs alternate so drift in the network hits both. One JSON line
per run on stdout, medians on stderr: first paint, LCP, DOMContentLoaded, when
the API request started, when the gene record's "Loading the full record"
banner was hidden (contentReady), and cumulative layout shift. The gene
record's numbers in the README ("Deferring a page's scripts") came from it.

The same interception tests any change to a response before it is deployed --
swap add_defer() for another rewrite, or change the pattern to a script. One
trap: Page.navigate does not answer until the paused response is released, so
it is sent without waiting (send_nowait); waiting for it deadlocks.
Needs `websocket-client` and Google Chrome, like tools/page_speed.py.
"""
import argparse, base64, json, re, shutil, socket, statistics as st, subprocess, sys, tempfile, time, urllib.request
import websocket

CHROME = "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
_s = socket.socket(); _s.bind(("127.0.0.1", 0)); PORT = _s.getsockname()[1]; _s.close()

PROFILES = {
    "desktop": dict(latency=40, down=10_000_000 / 8, up=10_000_000 / 8, cpu=1),
    "slow": dict(latency=150, down=1_638_400 / 8, up=750_000 / 8, cpu=4),
}

# Runs before any page script: layout shifts, and the moment the gene
# record's "Loading the full record" banner is hidden (its content is drawn).
EARLY = r"""
window.__ab = { cls: 0, shifts: [], contentReady: 0 };
try {
  new PerformanceObserver(function (list) {
    list.getEntries().forEach(function (e) {
      if (!e.hadRecentInput) { window.__ab.cls += e.value; window.__ab.shifts.push([Math.round(e.startTime), +e.value.toFixed(4)]); }
    });
  }).observe({ type: 'layout-shift', buffered: true });
} catch (e) {}
(function watch() {
  var el = document.getElementById('gene-record-loading');
  if (!el) { if (document.readyState !== 'complete') { setTimeout(watch, 5); } return; }
  var seen = false;
  function check() {
    var hidden = el.hidden || getComputedStyle(el).display === 'none';
    if (!seen) { seen = !hidden; }
    if (seen && hidden && !window.__ab.contentReady) { window.__ab.contentReady = performance.now(); }
  }
  new MutationObserver(check).observe(el, { attributes: true });
  check();
})();
"""

REPORT = r"""
(async () => {
  const nav = performance.getEntriesByType('navigation')[0];
  const paint = {};
  performance.getEntriesByType('paint').forEach(p => paint[p.name] = p.startTime);
  const lcp = await new Promise(res => {
    let last = 0;
    try { new PerformanceObserver(l => l.getEntries().forEach(e => last = e.startTime)).observe({type: 'largest-contentful-paint', buffered: true}); } catch (e) {}
    setTimeout(() => res(last), 300);
  });
  const res = performance.getEntriesByType('resource');
  const api = res.find(r => /\/api\/v1\/records\/gene\//.test(r.name));
  const scripts = res.filter(r => /\/temp\/bundles\/.*\.js/.test(r.name));
  return {
    fcp: Math.round(paint['first-contentful-paint'] || 0),
    lcp: Math.round(lcp),
    dcl: Math.round(nav.domContentLoadedEventEnd),
    load: Math.round(nav.loadEventEnd),
    apiStart: api ? Math.round(api.startTime) : null,
    apiEnd: api ? Math.round(api.responseEnd) : null,
    contentReady: Math.round(window.__ab.contentReady || 0),
    cls: +window.__ab.cls.toFixed(4),
    shifts: window.__ab.shifts.slice(0, 6),
    deferred: document.querySelectorAll('head script[defer]').length,
    blockingJs: res.filter(r => r.renderBlockingStatus === 'blocking' && /\.js/.test(r.name)).length,
    jsDone: scripts.length ? Math.round(Math.max(...scripts.map(s => s.responseEnd))) : null,
  };
})()
"""


def add_defer(html):
    head_end = html.find('</head>')
    head, rest = html[:head_end], html[head_end:]
    head, n = re.subn(r"<script type='text/javascript' src='([^']+)'></script>",
                      r"<script type='text/javascript' src='\1' defer></script>", head)
    return head + rest, n


class Tab:
    def __init__(self, ws_url, variant):
        self.ws = websocket.create_connection(ws_url, timeout=120, suppress_origin=True)
        self.n = 0
        self.queue = []
        self.variant = variant
        self.rewrites = None

    def _recv(self, timeout=None):
        self.ws.settimeout(timeout if timeout is not None else 120)
        return json.loads(self.ws.recv())

    def send(self, method, params=None):
        self.n += 1
        mid = self.n
        self.ws.send(json.dumps({"id": mid, "method": method, "params": params or {}}))
        while True:
            msg = self._recv()
            if msg.get("id") == mid:
                if "error" in msg:
                    raise RuntimeError(f"{method}: {msg['error']}")
                return msg.get("result", {})
            self.queue.append(msg)

    def send_nowait(self, method, params=None):
        # Page.navigate answers only once the paused document is released,
        # so waiting for its reply here would deadlock the interception.
        self.n += 1
        self.ws.send(json.dumps({"id": self.n, "method": method, "params": params or {}}))

    def handle(self, msg):
        if msg.get("method") != "Fetch.requestPaused":
            return msg
        p = msg["params"]
        body = self.send("Fetch.getResponseBody", {"requestId": p["requestId"]})
        raw = base64.b64decode(body["body"]) if body.get("base64Encoded") else body["body"].encode()
        html = raw.decode("utf-8", "replace")
        if self.variant == "defer":
            html, self.rewrites = add_defer(html)
        else:
            self.rewrites = 0
        headers = [h for h in p.get("responseHeaders", [])
                   if h["name"].lower() not in ("content-length", "content-encoding")]
        self.send("Fetch.fulfillRequest", {
            "requestId": p["requestId"], "responseCode": p.get("responseStatusCode", 200),
            "responseHeaders": headers, "body": base64.b64encode(html.encode()).decode()})
        return msg

    def pump_until(self, method, timeout=120):
        end = time.time() + timeout
        while time.time() < end:
            msg = self.queue.pop(0) if self.queue else None
            if msg is None:
                try:
                    msg = self._recv(0.5)
                except websocket.WebSocketTimeoutException:
                    continue
            self.handle(msg)
            if method and msg.get("method") == method:
                return msg
        if method:
            raise TimeoutError(method)

    def settle(self, seconds):
        end = time.time() + seconds
        while time.time() < end:
            msg = self.queue.pop(0) if self.queue else None
            if msg is None:
                try:
                    msg = self._recv(0.2)
                except websocket.WebSocketTimeoutException:
                    continue
            self.handle(msg)


def http(path, method="GET"):
    req = urllib.request.Request(f"http://127.0.0.1:{PORT}{path}", method=method)
    return json.loads(urllib.request.urlopen(req, timeout=10).read())


def run(url, variant, profile, settle):
    target = http("/json/new?about:blank", method="PUT")
    tab = Tab(target["webSocketDebuggerUrl"], variant)
    try:
        tab.send("Network.enable")
        tab.send("Page.enable")
        tab.send("Network.setCacheDisabled", {"cacheDisabled": True})
        tab.send("Emulation.setDeviceMetricsOverride", {"width": 1280, "height": 900, "deviceScaleFactor": 1, "mobile": False})
        p = PROFILES[profile]
        tab.send("Network.emulateNetworkConditions", {"offline": False, "latency": p["latency"],
                                                      "downloadThroughput": p["down"], "uploadThroughput": p["up"]})
        tab.send("Emulation.setCPUThrottlingRate", {"rate": p["cpu"]})
        tab.send("Page.addScriptToEvaluateOnNewDocument", {"source": EARLY})
        tab.send("Fetch.enable", {"patterns": [{"urlPattern": "*", "resourceType": "Document", "requestStage": "Response"}]})
        tab.send_nowait("Page.navigate", {"url": url})
        tab.pump_until("Page.loadEventFired", timeout=180)
        tab.settle(settle)
        r = tab.send("Runtime.evaluate", {"expression": REPORT, "awaitPromise": True, "returnByValue": True})
        out = r["result"].get("value") or {}
        out["variant"] = variant
        out["rewrites"] = tab.rewrites
        return out
    finally:
        try:
            http(f"/json/close/{target['id']}")
        except Exception:
            pass


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--profile", default="desktop", choices=PROFILES)
    ap.add_argument("--runs", type=int, default=5)
    ap.add_argument("--settle", type=float, default=4.0)
    ap.add_argument("--gap", type=float, default=3.0)
    ap.add_argument("url")
    a = ap.parse_args()
    prof = tempfile.mkdtemp(prefix="deferab-chrome-")
    chrome = subprocess.Popen([CHROME, "--headless=new", f"--remote-debugging-port={PORT}", f"--user-data-dir={prof}",
                               "--no-first-run", "--no-default-browser-check", "--disable-extensions",
                               "--remote-allow-origins=*", "about:blank"],
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        for _ in range(50):
            try:
                http("/json/version"); break
            except Exception:
                time.sleep(0.2)
        rows = []
        for i in range(a.runs):
            for variant in (("sync", "defer") if i % 2 == 0 else ("defer", "sync")):
                rows.append(run(a.url, variant, a.profile, a.settle))
                print(json.dumps(rows[-1]), flush=True)
                time.sleep(a.gap)
        print("## summary", a.profile, a.url, file=sys.stderr)
        for variant in ("sync", "defer"):
            vs = [r for r in rows if r["variant"] == variant]
            med = lambda k: st.median([r.get(k) or 0 for r in vs])
            print(f"{variant:5}  fcp={med('fcp'):6.0f} lcp={med('lcp'):6.0f} dcl={med('dcl'):6.0f} apiStart={med('apiStart'):6.0f} "
                  f"contentReady={med('contentReady'):6.0f} load={med('load'):6.0f} cls={st.median([r['cls'] for r in vs]):.4f} "
                  f"blockingJs={vs[0]['blockingJs']} deferred={vs[0]['deferred']} rewrites={vs[0]['rewrites']}", file=sys.stderr)
    finally:
        chrome.terminate()
        try:
            chrome.wait(5)
        except Exception:
            chrome.kill()
        shutil.rmtree(prof, ignore_errors=True)


if __name__ == "__main__":
    main()
