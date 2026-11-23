"""Read only the configured public proxy sources; never accept a caller-supplied URL."""

import base64
import binascii
import json
import logging
import os
import re
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from curl_cffi import requests

LOG = logging.getLogger("proxy-sources")
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
TIMEOUT = 15
MAX_BODY = 5 * 1024 * 1024
PROTOCOLS = ("HTTP", "HTTPS", "SOCKS4", "SOCKS5")


def read_text(response):
    if len(response.content) > MAX_BODY:
        raise ValueError("source response too large")
    return response.text


def fetch_advanced(session):
    response = session.get("https://advanced.name/ru/freeproxy", timeout=TIMEOUT, allow_redirects=False)
    content = read_text(response)
    if response.status_code != 200:
        return {"ok": False, "error": "HTTP " + str(response.status_code)}
    if "<table" not in content.lower():
        return {"ok": False, "error": "source did not return a proxy table"}
    return {"ok": True, "content": content}


def decode_twoip_challenge(content):
    encoded = re.search(r'atob\(\s*["\']([A-Za-z0-9+/=]{1,20000})["\']\s*\)', content)
    if not encoded:
        return None
    try:
        script = base64.b64decode(encoded.group(1), validate=True).decode("utf-8")
    except (binascii.Error, UnicodeError):
        return None
    answer = re.search(r'\bconst\s+answer\s*=\s*["\']([A-Za-z0-9_-]{1,256})["\']', script)
    salt = re.search(r'\bconst\s+salt\s*=\s*["\']([A-Za-z0-9_-]{1,256})["\']', script)
    if not answer or not salt:
        return None
    return {"2ip_js_challenge": answer.group(1), "2ip_js_challenge_salt": salt.group(1)}


def fetch_twoip(session):
    url = "https://2ip.io/ru/proxy/"
    response = session.get(url, timeout=TIMEOUT, allow_redirects=False)
    content = read_text(response)
    if response.status_code == 503:
        cookies = decode_twoip_challenge(content)
        if cookies:
            for name, value in cookies.items():
                session.cookies.set(name, value, domain="2ip.io", path="/")
            response = session.get(url, timeout=TIMEOUT, allow_redirects=False)
            content = read_text(response)
    if response.status_code != 200:
        return {"ok": False, "error": "HTTP " + str(response.status_code)}
    if not re.search(r'\b(?:\d{1,3}\.){3}\d{1,3}:\d{1,5}\b', content):
        return {"ok": False, "error": "source did not return an IP:port list"}
    return {"ok": True, "content": content}


def fetch_geonix(session):
    root = "https://free.geonix.com"
    headers = {"Accept": "application/json", "Content-Type": "application/json", "Referer": root + "/ru/", "Origin": root}
    response = session.get(root + "/api/front/main/captcha/info", headers=headers, timeout=TIMEOUT, allow_redirects=False)
    if response.status_code != 200:
        return {"ok": False, "error": "captcha status HTTP " + str(response.status_code)}
    captcha = json.loads(read_text(response))
    if not isinstance(captcha, dict) or captcha.get("isCaptchaActive") is not False:
        return {"ok": False, "error": "export requires CAPTCHA or captcha status is unknown"}

    groups = {}
    errors = {}
    for protocol in PROTOCOLS:
        body = {"captchaKey": "", "countries": [], "proxyProtocols": [protocol], "proxyTypes": []}
        try:
            response = session.post(root + "/api/front/main/proxy/export", headers=headers, json=body, timeout=TIMEOUT, allow_redirects=False)
            if response.status_code != 200:
                errors[protocol] = "HTTP " + str(response.status_code)
                continue
            values = json.loads(read_text(response))
            if not isinstance(values, list) or any(not isinstance(value, str) for value in values):
                errors[protocol] = "invalid export response"
                continue
            groups[protocol] = values
        except (requests.errors.RequestsError, ValueError, TypeError) as exc:
            errors[protocol] = type(exc).__name__
    return {"ok": bool(groups), "groups": groups, "errors": errors}


def fetch_source(source, attempts=1):
    result = {"ok": False, "error": "source request failed"}
    for _ in range(attempts):
        try:
            with requests.Session(impersonate="chrome", trust_env=False) as session:
                result = source(session)
            if result.get("ok"):
                return result
        except (requests.errors.RequestsError, ValueError, TypeError) as exc:
            result = {"ok": False, "error": "source request failed: " + type(exc).__name__}
    return result


class Handler(BaseHTTPRequestHandler):
    def send_json(self, status, data):
        body = json.dumps(data, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        try:
            self.wfile.write(body)
        except (BrokenPipeError, ConnectionResetError):
            pass

    def do_GET(self):
        if self.path == "/health":
            self.send_json(200, {"ok": True})
            return
        methods = {"/sources/advanced": fetch_advanced, "/sources/2ip": fetch_twoip, "/sources/geonix": fetch_geonix}
        source = methods.get(self.path)
        if source is None:
            self.send_json(404, {"ok": False, "error": "unknown source"})
            return
        result = fetch_source(source, attempts=2 if self.path == "/sources/2ip" else 1)
        LOG.info("source=%s ok=%s", self.path, result.get("ok"))
        self.send_json(200, result)

    def log_message(self, fmt, *args):
        LOG.info("http " + fmt, *args)


if __name__ == "__main__":
    ThreadingHTTPServer((os.getenv("FETCH_BIND", "127.0.0.1"), 8765), Handler).serve_forever()
