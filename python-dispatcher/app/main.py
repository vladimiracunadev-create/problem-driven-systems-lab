"""
Python Lab Dispatcher — un solo contenedor, un solo puerto para los 12 casos.

Cada caso corre como subproceso interno en un puerto local (9001-9012).
El dispatcher escucha en :8200 y enruta por prefijo de path:

    GET /01/health          → case 01 server (interno :9001)
    GET /02/query?...       → case 02 server (interno :9002)
    ...
    GET /12/share-knowledge → case 12 server (interno :9012)
    GET /                   → índice de todos los casos

Los puertos internos nunca se exponen al host — solo :8200 es visible.
"""

from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import urlparse, parse_qs
import json
import os
import subprocess
import sys
import time
import urllib.error
import urllib.request

# ---------------------------------------------------------------------------
# Case registry — internal port per case (never exposed to host)
# ---------------------------------------------------------------------------

CASES = {
    "01": {"port": 9001, "name": "API lenta bajo carga",               "server": "/cases/01/server.py"},
    "02": {"port": 9002, "name": "N+1 y cuellos de botella DB",        "server": "/cases/02/server.py"},
    "03": {"port": 9003, "name": "Observabilidad deficiente",          "server": "/cases/03/server.py"},
    "04": {"port": 9004, "name": "Timeout chain y retry storms",       "server": "/cases/04/server.py"},
    "05": {"port": 9005, "name": "Presion de memoria y fugas",         "server": "/cases/05/server.py"},
    "06": {"port": 9006, "name": "Pipeline roto y delivery fragil",    "server": "/cases/06/server.py"},
    "07": {"port": 9007, "name": "Modernizacion incremental monolito", "server": "/cases/07/server.py"},
    "08": {"port": 9008, "name": "Extraccion critica de modulo",       "server": "/cases/08/server.py"},
    "09": {"port": 9009, "name": "Integracion externa inestable",      "server": "/cases/09/server.py"},
    "10": {"port": 9010, "name": "Arquitectura cara para algo simple", "server": "/cases/10/server.py"},
    "11": {"port": 9011, "name": "Reportes que bloquean la operacion", "server": "/cases/11/server.py"},
    "12": {"port": 9012, "name": "Punto unico de conocimiento",        "server": "/cases/12/server.py"},
}

DISPATCH_PORT = int(os.environ.get("PORT", "8200"))
APP_STACK     = os.environ.get("APP_STACK", "Python 3.12")


# ---------------------------------------------------------------------------
# Startup: spawn each case as an internal subprocess
# ---------------------------------------------------------------------------

def start_case_servers():
    for case_id, info in CASES.items():
        env = {
            **os.environ,
            "PORT":      str(info["port"]),
            "APP_STACK": APP_STACK,
        }
        proc = subprocess.Popen(
            [sys.executable, info["server"]],
            env=env,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        print(f"  case {case_id} → interno :{info['port']} (pid {proc.pid})")


def wait_for_cases(timeout: float = 15.0):
    """Wait until all case servers respond to /health."""
    deadline = time.monotonic() + timeout
    remaining = set(CASES.keys())
    while remaining and time.monotonic() < deadline:
        for case_id in list(remaining):
            port = CASES[case_id]["port"]
            try:
                urllib.request.urlopen(
                    f"http://127.0.0.1:{port}/health", timeout=1
                ).read()
                remaining.discard(case_id)
            except Exception:
                pass
        if remaining:
            time.sleep(0.3)
    if remaining:
        print(f"  WARNING: cases not ready yet: {sorted(remaining)}")


# ---------------------------------------------------------------------------
# Proxy helper
# ---------------------------------------------------------------------------

def proxy_to_case(case_id: str, sub_path: str, raw_query: str):
    """Forward request to the internal case server, return (status, headers, body)."""
    port = CASES[case_id]["port"]
    url  = f"http://127.0.0.1:{port}{sub_path}"
    if raw_query:
        url += "?" + raw_query
    try:
        with urllib.request.urlopen(url, timeout=30) as resp:
            return resp.status, dict(resp.headers), resp.read()
    except urllib.error.HTTPError as exc:
        body = exc.read()
        return exc.code, dict(exc.headers), body
    except Exception as exc:
        body = json.dumps({
            "error":   "dispatcher_proxy_error",
            "case":    case_id,
            "message": str(exc),
        }).encode("utf-8")
        return 502, {"Content-Type": "application/json; charset=utf-8"}, body


# ---------------------------------------------------------------------------
# Request handler
# ---------------------------------------------------------------------------

class DispatchHandler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        return  # silence default access log

    def _send(self, status: int, content_type: str, body: bytes):
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        parsed    = urlparse(self.path)
        parts     = parsed.path.lstrip("/").split("/", 1)
        case_id   = parts[0].zfill(2) if parts[0] else ""
        sub_path  = "/" + parts[1] if len(parts) > 1 else "/"
        raw_query = parsed.query

        # Root index — list all cases
        if not case_id or case_id == "00":
            payload = {
                "lab":   "Problem-Driven Systems Lab",
                "stack": APP_STACK,
                "info":  "Dispatcher Python — un contenedor, un puerto, 12 casos.",
                "usage": "GET /{caso}/{ruta}  →  e.g. /01/health, /05/batch-legacy",
                "cases": {
                    cid: {
                        "name":     info["name"],
                        "health":   f"/{cid}/health",
                        "internal_port": info["port"],
                    }
                    for cid, info in CASES.items()
                },
            }
            body = json.dumps(payload, ensure_ascii=False, indent=2).encode("utf-8")
            self._send(200, "application/json; charset=utf-8", body)
            return

        # Unknown case
        if case_id not in CASES:
            payload = {
                "error":       "case_not_found",
                "requested":   case_id,
                "valid_cases": sorted(CASES.keys()),
            }
            body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
            self._send(404, "application/json; charset=utf-8", body)
            return

        # Proxy to the internal case server
        status, headers, body = proxy_to_case(case_id, sub_path, raw_query)
        ct = headers.get("Content-Type", "application/json; charset=utf-8")
        self._send(status, ct, body)


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

if __name__ == "__main__":
    print(f"[dispatcher] Iniciando casos internos...")
    start_case_servers()

    print(f"[dispatcher] Esperando que los casos levanten...")
    wait_for_cases(timeout=20.0)

    print(f"[dispatcher] Listo. Escuchando en :{DISPATCH_PORT}")
    print(f"[dispatcher] Rutas: /01/ ... /12/  →  casos internos :9001 ... :9012")

    server = HTTPServer(("0.0.0.0", DISPATCH_PORT), DispatchHandler)
    server.serve_forever()
