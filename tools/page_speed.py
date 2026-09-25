"""Cold-cache page-load timing over the DevTools Protocol, through Cloudflare.

    python3 tools/page_speed.py [--profile desktop|slow] [--runs N] URL...

Each run opens a fresh tab with the cache disabled and optional throttling --
`desktop` is Lighthouse's desktop numbers (10 Mbps, 40 ms), `slow` its mobile
ones (1.6 Mbps, 150 ms, CPU four times slower) -- and prints one JSON line per
URL: first contentful paint, LCP, DOMContentLoaded, load, requests, bytes on
the wire, the render-blocking stylesheets and scripts, and whether Plotly was
fetched. Written 2026-09-25 for the Plotly and bundling change; the numbers in
the README's "Bundling a page's stylesheets and scripts" came from it.

Needs `websocket-client` and Google Chrome, like tools/overflow_sweep.py. Run
one at a time: two runs share nothing but the network, and that skews both.
Pace it -- a burst of page loads earns HTTP 429 from the edge.
"""
import json, os, subprocess, sys, time, urllib.request, argparse, shutil, tempfile
import websocket

CHROME = "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
import socket
_s = socket.socket(); _s.bind(("127.0.0.1", 0)); PORT = _s.getsockname()[1]; _s.close()

PROFILES = {
    # Lighthouse's desktop numbers: 40 ms RTT, 10 Mbps.
    "desktop": dict(latency=40, down=10_000_000 / 8, up=10_000_000 / 8, cpu=1),
    # Lighthouse's mobile numbers: 150 ms RTT, 1.6 Mbps, CPU 4x slower.
    "slow": dict(latency=150, down=1_638_400 / 8, up=750_000 / 8, cpu=4),
    "none": None,
}

PAGE_JS = r"""
(async () => {
  const nav = performance.getEntriesByType('navigation')[0];
  const paint = {};
  performance.getEntriesByType('paint').forEach(p => paint[p.name] = p.startTime);
  const lcp = await new Promise(res => {
    let last = 0;
    try {
      new PerformanceObserver(l => { l.getEntries().forEach(e => last = e.startTime); })
        .observe({type: 'largest-contentful-paint', buffered: true});
    } catch (e) {}
    setTimeout(() => res(last), 300);
  });
  const res = performance.getEntriesByType('resource');
  const blocking = res.filter(r => r.renderBlockingStatus === 'blocking').map(r => r.name);
  return {
    fcp: Math.round(paint['first-contentful-paint'] || 0),
    lcp: Math.round(lcp),
    dcl: Math.round(nav.domContentLoadedEventEnd),
    load: Math.round(nav.loadEventEnd),
    blocking: blocking.length,
    blockingCss: blocking.filter(n => /\.css/.test(n)).length,
    blockingJs: blocking.filter(n => /\.js/.test(n)).length,
    plotly: res.some(r => /plotly/i.test(r.name)),
    plotlyStart: Math.round((res.find(r => /plotly/i.test(r.name)) || {}).startTime || 0),
    charts: document.querySelectorAll('.js-plotly-plot').length,
    title: document.title.slice(0, 60),
  };
})()
"""


class Tab:
    def __init__(self, ws_url):
        self.ws = websocket.create_connection(ws_url, timeout=120, suppress_origin=True)
        self.n = 0
        self.events = []

    def send(self, method, params=None):
        self.n += 1
        mid = self.n
        self.ws.send(json.dumps({"id": mid, "method": method, "params": params or {}}))
        while True:
            msg = json.loads(self.ws.recv())
            if msg.get("id") == mid:
                if "error" in msg:
                    raise RuntimeError(f"{method}: {msg['error']}")
                return msg.get("result", {})
            self.events.append(msg)

    def pump(self, seconds):
        end = time.time() + seconds
        self.ws.settimeout(0.2)
        while time.time() < end:
            try:
                self.events.append(json.loads(self.ws.recv()))
            except websocket.WebSocketTimeoutException:
                pass
        self.ws.settimeout(120)

    def wait_for(self, method, timeout=90):
        end = time.time() + timeout
        for e in self.events:
            if e.get("method") == method:
                return e
        self.ws.settimeout(0.5)
        try:
            while time.time() < end:
                try:
                    msg = json.loads(self.ws.recv())
                except websocket.WebSocketTimeoutException:
                    continue
                self.events.append(msg)
                if msg.get("method") == method:
                    return msg
        finally:
            self.ws.settimeout(120)
        raise TimeoutError(method)


def http(path, method="GET"):
    req = urllib.request.Request(f"http://127.0.0.1:{PORT}{path}", method=method)
    return json.loads(urllib.request.urlopen(req, timeout=10).read())


def measure(url, profile, settle, scroll):
    target = http("/json/new?about:blank", method="PUT")
    tab = Tab(target["webSocketDebuggerUrl"])
    try:
        tab.send("Network.enable")
        tab.send("Page.enable")
        tab.send("Network.setCacheDisabled", {"cacheDisabled": True})
        tab.send("Emulation.setDeviceMetricsOverride",
                 {"width": 1280, "height": 900, "deviceScaleFactor": 1, "mobile": False})
        p = PROFILES[profile]
        if p:
            tab.send("Network.emulateNetworkConditions", {
                "offline": False, "latency": p["latency"],
                "downloadThroughput": p["down"], "uploadThroughput": p["up"]})
            tab.send("Emulation.setCPUThrottlingRate", {"rate": p["cpu"]})
        tab.events.clear()
        tab.send("Page.navigate", {"url": url})
        tab.wait_for("Page.loadEventFired", timeout=180)
        tab.pump(settle)
        if scroll:
            tab.send("Runtime.evaluate", {"expression": "window.scrollTo(0, document.documentElement.scrollHeight)"})
            tab.pump(settle)
        r = tab.send("Runtime.evaluate", {"expression": PAGE_JS, "awaitPromise": True, "returnByValue": True})
        out = r["result"].get("value") or {}
        reqs = [e for e in tab.events if e.get("method") == "Network.requestWillBeSent"]
        fin = [e for e in tab.events if e.get("method") == "Network.loadingFinished"]
        out["requests"] = len(reqs)
        out["kb"] = round(sum(e["params"].get("encodedDataLength", 0) for e in fin) / 1024)
        statuses = {}
        for e in tab.events:
            if e.get("method") == "Network.responseReceived":
                resp = e["params"]["response"]
                if resp["url"].split("?")[0] == url.split("?")[0]:
                    statuses["doc"] = resp["status"]
        out["status"] = statuses.get("doc")
        return out
    finally:
        try:
            http(f"/json/close/{target['id']}")
        except Exception:
            pass


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--profile", default="desktop", choices=PROFILES)
    ap.add_argument("--runs", type=int, default=3)
    ap.add_argument("--settle", type=float, default=3.0)
    ap.add_argument("--scroll", action="store_true")
    ap.add_argument("--gap", type=float, default=4.0)
    ap.add_argument("urls", nargs="+")
    a = ap.parse_args()

    prof = tempfile.mkdtemp(prefix="perf-chrome-")
    chrome = subprocess.Popen([CHROME, "--headless=new", f"--remote-debugging-port={PORT}",
                               f"--user-data-dir={prof}", "--no-first-run", "--no-default-browser-check",
                               "--disable-extensions", "--remote-allow-origins=*", "about:blank"],
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        for _ in range(50):
            try:
                http("/json/version")
                break
            except Exception:
                time.sleep(0.2)
        for url in a.urls:
            rows = []
            for i in range(a.runs):
                rows.append(measure(url, a.profile, a.settle, a.scroll))
                time.sleep(a.gap)
            print(json.dumps({"url": url, "profile": a.profile, "runs": rows}))
            sys.stdout.flush()
    finally:
        chrome.terminate()
        try:
            chrome.wait(5)
        except Exception:
            chrome.kill()
        shutil.rmtree(prof, ignore_errors=True)


if __name__ == "__main__":
    main()
