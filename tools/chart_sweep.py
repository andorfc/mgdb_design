"""Chart sweep: load each page at the dev ORIGIN in headless Chrome, scroll it
top to bottom in steps so every lazily drawn figure is asked for, and write
one JSON line per page: Plotly figures drawn, chart containers, fallbacks that
say a chart failed, JavaScript errors and MGDB console warnings, bundles
served, overflow, which Plotly build the page fetched, the trace types it drew,
and any trace Plotly drew as another type than it asked for (`substituted` --
what a type missing from the cartesian build looks like).

    python3 tools/chart_sweep.py OUT.jsonl < urls.txt

Run it before and after a change and compare page by page; that is how the
Plotly change of 2026-09-25 was checked (167 pages, 99 figures on 59 pages
both times). For speed, split the list and run four at once -- each run starts
its own Chrome on a free port.

`plotlyTagInHead` is read AFTER the scroll, when MGDB.loadPlotly() has put its
own script tag in <head>, so it is true on every page that drew a figure. To
ask whether a page's served HTML includes Plotly, grep the HTML itself.
"""
import json, os, subprocess, sys, time, urllib.request, tempfile, shutil, socket
import websocket

CHROME = "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
ORIGIN = "10.24.27.235"
HOST = "claude.maizegdb.org"
_s = socket.socket(); _s.bind(("127.0.0.1", 0)); PORT = _s.getsockname()[1]; _s.close()

SCROLL_JS = r"""
(async () => {
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const H = () => document.documentElement.scrollHeight;
  for (let y = 0; y < H(); y += Math.round(innerHeight * 0.8)) {
    window.scrollTo(0, y);
    window.dispatchEvent(new Event('scroll'));
    await sleep(250);
  }
  window.scrollTo(0, H());
  window.dispatchEvent(new Event('scroll'));
  return H();
})()
"""

REPORT_JS = r"""
(() => {
  const html = document.documentElement.outerHTML;
  const containers = document.querySelectorAll('.mgdb-chart, [id$="-chart"], .reference-chart, .et-plot');
  const drawn = document.querySelectorAll('.js-plotly-plot');
  const failed = Array.from(document.querySelectorAll('.mgdb-chart-fallback'))
    .map(e => e.textContent.trim()).filter(t => /could not|did not load|No data/i.test(t));
  const loading = Array.from(document.querySelectorAll('.mgdb-chart-fallback'))
    .map(e => e.textContent.trim()).filter(t => /^Loading/i.test(t));
  const res = performance.getEntriesByType('resource');
  return {
    title: document.title.slice(0, 50),
    containers: containers.length,
    drawn: drawn.length,
    failed: failed.slice(0, 3),
    stillLoading: loading.length,
    plotlyTagInHead: !!document.querySelector('head script[src*="plotly"]'),
    plotlyFetched: res.some(r => /plotly/i.test(r.name)),
    bundles: res.filter(r => /\/temp\/bundles\//.test(r.name)).map(r => r.name.replace(/^.*\/temp\/bundles\//, '').replace(/\?.*/, '')),
    plotlyBuild: res.filter(r => /plotly[^/]*\.js/.test(r.name)).map(r => r.name.replace(/^.*\//, '').replace(/\?.*/, '')),
    traceTypes: Array.from(new Set(Array.from(drawn).flatMap(el => (el.data || []).map(t => t.type || 'scatter')))).sort(),
    substituted: Array.from(drawn).flatMap(el => (el._fullData || []).filter(f => el.data[f.index] && el.data[f.index].type && el.data[f.index].type !== f.type).map(f => (el.id || '?') + ':' + el.data[f.index].type + '->' + f.type)),
    cssFiles: res.filter(r => r.initiatorType === 'link' && /\.css/.test(r.name)).length,
    jsFiles: res.filter(r => r.initiatorType === 'script' && /\.js/.test(r.name)).length,
    width: document.documentElement.scrollWidth - document.documentElement.clientWidth,
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

    def wait_for(self, method, timeout=60):
        end = time.time() + timeout
        self.ws.settimeout(0.5)
        try:
            while time.time() < end:
                for e in self.events:
                    if e.get("method") == method:
                        return e
                try:
                    self.events.append(json.loads(self.ws.recv()))
                except websocket.WebSocketTimeoutException:
                    continue
        finally:
            self.ws.settimeout(120)
        raise TimeoutError(method)


def http(path, method="GET"):
    req = urllib.request.Request(f"http://127.0.0.1:{PORT}{path}", method=method)
    return json.loads(urllib.request.urlopen(req, timeout=10).read())


def check(path):
    target = http("/json/new?about:blank", method="PUT")
    tab = Tab(target["webSocketDebuggerUrl"])
    try:
        tab.send("Page.enable")
        tab.send("Runtime.enable")
        tab.send("Log.enable")
        tab.send("Emulation.setDeviceMetricsOverride", {"width": 1280, "height": 900, "deviceScaleFactor": 1, "mobile": False})
        tab.events.clear()
        tab.send("Page.navigate", {"url": f"http://{HOST}{path}"})
        try:
            tab.wait_for("Page.loadEventFired", timeout=60)
        except TimeoutError:
            pass
        tab.pump(2.5)
        tab.send("Runtime.evaluate", {"expression": SCROLL_JS, "awaitPromise": True, "returnByValue": True})
        tab.pump(4.0)
        r = tab.send("Runtime.evaluate", {"expression": REPORT_JS, "returnByValue": True})
        out = r["result"].get("value") or {}
        errs = []
        for e in tab.events:
            if e.get("method") == "Runtime.exceptionThrown":
                d = e["params"]["exceptionDetails"]
                errs.append((d.get("exception", {}).get("description") or d.get("text", ""))[:160])
            elif e.get("method") == "Log.entryAdded" and e["params"]["entry"]["level"] == "error":
                errs.append(("log: " + e["params"]["entry"].get("text", ""))[:160])
            elif e.get("method") == "Runtime.consoleAPICalled" and e["params"].get("type") in ("warning", "error"):
                text = " ".join(str(a.get("value", a.get("description", ""))) for a in e["params"].get("args", []))
                if "MGDB" in text or e["params"]["type"] == "error":
                    errs.append(("console." + e["params"]["type"] + ": " + text)[:200])
        out["errors"] = errs[:5]
        out["path"] = path
        return out
    finally:
        try:
            http(f"/json/close/{target['id']}")
        except Exception:
            pass


def main():
    out_path = sys.argv[1]
    paths = [l.strip() for l in sys.stdin if l.strip()]
    prof = tempfile.mkdtemp(prefix="verify-chrome-")
    chrome = subprocess.Popen([CHROME, "--headless=new", f"--remote-debugging-port={PORT}",
                               f"--user-data-dir={prof}", "--no-first-run", "--no-default-browser-check",
                               "--disable-extensions", "--remote-allow-origins=*",
                               f"--host-resolver-rules=MAP {HOST} {ORIGIN}", "about:blank"],
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        for _ in range(50):
            try:
                http("/json/version")
                break
            except Exception:
                time.sleep(0.2)
        with open(out_path, "w") as fh:
            for p in paths:
                try:
                    row = check(p)
                except Exception as e:
                    row = {"path": p, "fatal": str(e)[:200]}
                fh.write(json.dumps(row) + "\n")
                fh.flush()
    finally:
        chrome.terminate()
        try:
            chrome.wait(5)
        except Exception:
            chrome.kill()
        shutil.rmtree(prof, ignore_errors=True)


if __name__ == "__main__":
    main()
