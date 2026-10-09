"""Run as root on the VPS while bag dev is running. No real model calls."""
import json
import hashlib
import os
from pathlib import Path
import shutil
import tempfile
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import urllib.request
import urllib.error

COMMENT_FILE = 'comment-' + hashlib.sha256(b'TSLA').hexdigest()[:16] + '.json'

BASE = Path('/home/bhub.tm.biz.tr/private/agent')
WORKER = BASE / 'rwa-compare.py'
CACHE = BASE / 'rwa-cache'
ENDPOINT = 'http://127.0.0.1:9000/rwa/compare'
ORIGINAL_URL = "URL = 'http://127.0.0.1:9000/rwa/comment'"


class FailureHandler(BaseHTTPRequestHandler):
    calls = 0

    def do_POST(self):
        self.rfile.read(int(self.headers.get('Content-Length', '0')))
        type(self).calls += 1
        body = b'{"error":"controlled_failure_test"}'
        self.send_response(503)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *args):
        pass


def fetch_report():
    request = urllib.request.Request(ENDPOINT, data=b'{"ticker":"TSLA"}',
                                     headers={'Content-Type': 'application/json'}, method='POST')
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    with opener.open(request, timeout=40) as response:
        status = response.status
        raw = response.read(131073)
    if len(raw) > 131072:
        raise RuntimeError('Report too large')
    value = json.loads(raw)
    if status != 200 or not isinstance(value, dict) or value.get('ok') is not True:
        raise RuntimeError('Comparison endpoint did not return a successful report')
    return value


def main():
    if os.geteuid() != 0:
        raise RuntimeError('Run this test as root')
    source = WORKER.read_bytes()
    if source.count(ORIGINAL_URL.encode()) != 1 or not CACHE.is_dir():
        raise RuntimeError('Expected worker URL or cache directory not found')
    # Check Studio without touching the model or cache.
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    with opener.open(ENDPOINT.rsplit('/rwa/compare', 1)[0] + '/ping', timeout=5) as response:
        if response.status != 200:
            raise RuntimeError('Studio is not ready')
    backup = Path(tempfile.mkdtemp(prefix='fallback-test-', dir=BASE))
    (backup / 'rwa-compare.py').write_bytes(source)
    print('Recovery backup: ' + str(backup), flush=True)
    server = ThreadingHTTPServer(('127.0.0.1', 0), FailureHandler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    moved = []
    patched = False
    try:
        for name in (COMMENT_FILE, 'failure.json'):
            path = CACHE / name
            if path.exists():
                os.replace(path, backup / name)
                moved.append(name)
        temporary_url = "URL = 'http://127.0.0.1:" + str(server.server_port) + "/rwa/comment'"
        # Write in place to retain worker owner/mode for the site-user process.
        WORKER.write_bytes(source.replace(ORIGINAL_URL.encode(), temporary_url.encode()))
        patched = True
        thread.start()
        first = fetch_report()
        second = fetch_report()
        for label, value in (('FIRST', first), ('SECOND', second)):
            keys = ('ok', 'model_status', 'studio_http_requests', 'studio_http_status',
                    'cache_hit', 'db_time_ms', 'total_time_ms',
                    'binance_http_requests', 'binance_request_weight')
            print(label + ': ' + json.dumps({k: value.get(k) for k in keys}))
            if '| Market price | Reference price | RWA token price |' not in value.get('result', ''):
                raise RuntimeError('Deterministic price table missing')
            if value.get('model_comment_accepted') is not False:
                raise RuntimeError('Unexpected model commentary')
        if (first.get('model_status') != 'unavailable'
                or first.get('studio_http_requests') != 1
                or first.get('studio_http_status') != 503
                or second.get('model_status') != 'cooldown'
                or second.get('studio_http_requests') != 0
                or FailureHandler.calls != 1):
            raise RuntimeError('Failure fallback or cooldown check failed')
        print('PASS: HTTP 200 reports survive a simulated model HTTP 503; cooldown prevents another request.')
        print('Real model calls: 0. Binance API calls: 0.')
    finally:
        WORKER.write_bytes(source)
        # Only remove state produced by this isolated test, restoring original inodes/owners.
        for name in (COMMENT_FILE, 'failure.json'):
            path = CACHE / name
            if (patched or name in moved) and path.exists():
                path.unlink()
            if name in moved:
                os.replace(backup / name, path)
        if thread.is_alive():
            server.shutdown()
        server.server_close()
        shutil.rmtree(backup)
        print('RESTORED: original worker and cache files. Studio restart is not required.')


if __name__ == '__main__':
    try:
        main()
    except Exception as exc:
        print('FAIL: ' + str(exc))
        raise SystemExit(1)
