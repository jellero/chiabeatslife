#!/usr/bin/env python3
"""Importa il frontend pubblico WordPress in snapshot servibili da Slim 4.

Compatibile con Python 3.6+ e privo di dipendenze Python esterne.
CSS e JavaScript vengono salvati byte-per-byte nel loro path pubblico originale.
"""

import argparse
import hashlib
import html
import json
import re
import ssl
import sys
import time
import xml.etree.ElementTree as ET
from collections import deque
from html.parser import HTMLParser
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import unquote, urljoin, urlsplit, urlunsplit
from urllib.request import Request, urlopen

USER_AGENT = (
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/124.0 Safari/537.36 "
    "ChiabeatslifeMigration/2.0"
)
PAGE_LIMIT = 1200
ASSET_LIMIT_BYTES = 95 * 1024 * 1024
STATIC_EXTENSIONS = {
    ".css", ".js", ".mjs", ".map", ".json", ".xml", ".txt",
    ".png", ".jpg", ".jpeg", ".gif", ".webp", ".svg", ".ico", ".avif",
    ".woff", ".woff2", ".ttf", ".otf", ".eot",
    ".pdf", ".zip", ".mp3", ".m4a", ".ogg", ".wav", ".mp4", ".webm",
}
PAGE_EXTENSIONS = {"", ".html", ".htm", ".php"}
EXCLUDED_PAGE_PREFIXES = (
    "/wp-admin", "/wp-login.php", "/wp-json", "/xmlrpc.php",
    "/wp-cron.php", "/wp-comments-post.php",
)
RUNTIME_MARKERS = {
    "wp-admin/admin-ajax.php": "WordPress AJAX endpoint",
    "/wp-json/": "WordPress REST API",
    "xmlrpc.php": "WordPress XML-RPC",
    "wp-comments-post.php": "WordPress comments endpoint",
    "wpcf7": "Contact Form 7 runtime",
    "elementor-pro": "Elementor Pro runtime",
}
CSS_URL_RE = re.compile(r"url\(\s*(['\"]?)([^)'\"]+)\1\s*\)", re.I)
CSS_IMPORT_RE = re.compile(r"@import\s+(?:url\()?\s*(['\"])([^'\"]+)\1\s*\)?", re.I)
ABSOLUTE_URL_RE = re.compile(r"https?://[^\s'\"<>]+", re.I)
TITLE_RE = re.compile(r"<title\b[^>]*>(.*?)</title\s*>", re.I | re.S)
HEAD_RE = re.compile(r"<head\b[^>]*>(.*?)</head\s*>", re.I | re.S)
BODY_RE = re.compile(r"<body\b([^>]*)>(.*?)</body\s*>", re.I | re.S)
HTML_RE = re.compile(r"<html\b([^>]*)>", re.I | re.S)
META_GENERATOR_RE = re.compile(
    r"<meta\b(?=[^>]*\bname\s*=\s*(['\"])generator\1)[^>]*>\s*", re.I | re.S
)
META_CHARSET_RE = re.compile(r"<meta\b[^>]*\bcharset\s*=[^>]*>\s*", re.I | re.S)
LINK_TAG_RE = re.compile(r"<link\b[^>]*>\s*", re.I | re.S)
TITLE_TAG_RE = re.compile(r"<title\b[^>]*>.*?</title\s*>\s*", re.I | re.S)
TAG_RE = re.compile(r"<[^>]+>")


class AssetRecord(object):
    def __init__(self, source_url, public_path, size, sha256, content_type):
        self.source_url = source_url
        self.public_path = public_path
        self.size = size
        self.sha256 = sha256
        self.content_type = content_type

    def to_dict(self):
        return {
            "source_url": self.source_url,
            "public_path": self.public_path,
            "size": self.size,
            "sha256": self.sha256,
            "content_type": self.content_type,
        }


class FailureRecord(object):
    def __init__(self, url, reason):
        self.url = url
        self.reason = reason

    def to_dict(self):
        return {"url": self.url, "reason": self.reason}


class HttpResponse(object):
    def __init__(self, url, status, headers, content):
        self.url = url
        self.status = status
        self.headers = headers
        self.content = content

    def content_type(self):
        return self.headers.get("Content-Type", "")

    def text(self):
        content_type = self.content_type()
        match = re.search(r"charset=([A-Za-z0-9._-]+)", content_type, re.I)
        encodings = []
        if match:
            encodings.append(match.group(1))
        encodings.extend(["utf-8", "latin-1"])
        for encoding in encodings:
            try:
                return self.content.decode(encoding)
            except (UnicodeDecodeError, LookupError):
                pass
        return self.content.decode("utf-8", errors="replace")


class HtmlCollector(HTMLParser):
    def __init__(self, document_url):
        HTMLParser.__init__(self, convert_charrefs=True)
        self.document_url = document_url
        self.page_links = []
        self.asset_candidates = []
        self.form_actions = []
        self.canonical = None
        self.html_attrs = {}
        self.body_attrs = {}
        self._in_style = False
        self.inline_styles = []

    @staticmethod
    def attrs_dict(attrs):
        return {str(k).lower(): ("" if v is None else str(v)) for k, v in attrs}

    @staticmethod
    def rel_set(value):
        return {item.strip().lower() for item in str(value or "").split() if item.strip()}

    def handle_starttag(self, tag, attrs):
        tag = str(tag).lower()
        amap = self.attrs_dict(attrs)
        if tag == "html" and not self.html_attrs:
            self.html_attrs = dict(amap)
        if tag == "body" and not self.body_attrs:
            self.body_attrs = dict(amap)
        if tag == "style":
            self._in_style = True

        if tag == "a":
            href = amap.get("href", "").strip()
            if href:
                self.page_links.append(href)
        elif tag == "link":
            href = amap.get("href", "").strip()
            rel = self.rel_set(amap.get("rel", ""))
            if href and "canonical" in rel and self.canonical is None:
                self.canonical = href
            if href and ({"next", "prev"} & rel):
                self.page_links.append(href)
            if href and rel & {
                "stylesheet", "icon", "preload", "modulepreload",
                "manifest", "apple-touch-icon",
            }:
                self.asset_candidates.append((href, True))
            elif href:
                self.asset_candidates.append((href, False))

        if tag == "form":
            action = amap.get("action", "").strip()
            if action:
                self.form_actions.append(action)

        forced_tags = {"script", "img", "source", "video", "audio"}
        for attr_name in (
            "src", "poster", "data-src", "data-lazy-src", "data-original",
            "data-background", "data-background-image", "data-thumb",
        ):
            value = amap.get(attr_name, "").strip()
            if value:
                self.asset_candidates.append((value, tag in forced_tags))

        for attr_name in ("srcset", "data-srcset", "imagesrcset"):
            value = amap.get(attr_name, "").strip()
            if value:
                for item in value.split(","):
                    candidate = item.strip().split(" ", 1)[0]
                    if candidate:
                        self.asset_candidates.append((candidate, True))

        style = amap.get("style", "")
        if style:
            self.inline_styles.append(style)

        for name, value in amap.items():
            if name.startswith("data-") and value:
                for match in ABSOLUTE_URL_RE.findall(value):
                    self.asset_candidates.append((match.rstrip("\\),]}"), False))

    def handle_endtag(self, tag):
        if str(tag).lower() == "style":
            self._in_style = False

    def handle_data(self, data):
        if self._in_style and data:
            self.inline_styles.append(data)


class AttrParser(HTMLParser):
    def __init__(self):
        HTMLParser.__init__(self, convert_charrefs=True)
        self.attrs = None

    def handle_starttag(self, tag, attrs):
        if self.attrs is None:
            self.attrs = {
                str(k): (True if v is None else str(v))
                for k, v in attrs
            }


class Importer(object):
    def __init__(self, base_url, output, max_pages=PAGE_LIMIT):
        parsed = urlsplit(base_url)
        if parsed.scheme not in {"http", "https"} or not parsed.netloc:
            raise ValueError("base-url non valido")
        self.base_url = urlunsplit((parsed.scheme, parsed.netloc, "/", "", ""))
        self.scheme = parsed.scheme
        self.netloc = parsed.netloc
        self.hostname = (parsed.hostname or "").lower()
        self.output = output.resolve()
        self.public_dir = self.output / "public"
        self.pages_dir = self.output / "storage" / "pages"
        self.max_pages = max_pages
        self.pages = {}
        self.assets = {}
        self.asset_failures = []
        self.page_failures = []
        self.external_asset_hosts = set()
        self.runtime_dependencies = {}
        self._asset_in_progress = set()
        self._queued_pages = set()
        self.ssl_context = ssl.create_default_context()

    def http_get(self, url, timeout=40, max_bytes=None, attempts=4):
        last_error = None
        for attempt in range(attempts):
            req = Request(url, headers={
                "User-Agent": USER_AGENT,
                "Accept-Language": "it-IT,it;q=0.9,en;q=0.6",
            })
            try:
                with urlopen(req, timeout=timeout, context=self.ssl_context) as response:
                    status = int(response.getcode() or 0)
                    final_url = response.geturl()
                    headers = {str(k): str(v) for k, v in response.headers.items()}
                    declared = headers.get("Content-Length")
                    if max_bytes is not None and declared and declared.isdigit():
                        if int(declared) > max_bytes:
                            raise RuntimeError("risposta oltre {} byte".format(max_bytes))
                    content = response.read(None if max_bytes is None else max_bytes + 1)
                    if max_bytes is not None and len(content) > max_bytes:
                        raise RuntimeError("risposta oltre {} byte".format(max_bytes))
                    return HttpResponse(final_url, status, headers, content)
            except HTTPError as exc:
                last_error = "HTTP {}".format(exc.code)
                if exc.code not in (429, 500, 502, 503, 504):
                    break
            except (URLError, OSError, RuntimeError) as exc:
                last_error = str(exc)
            if attempt + 1 < attempts:
                time.sleep(0.7 * (2 ** attempt))
        raise RuntimeError(last_error or "richiesta HTTP fallita")

    def run(self):
        self.prepare_output()
        seeds = self.discover_seed_urls()
        self.crawl_pages(seeds)
        self.fetch_site_level_files()
        self.write_routes()
        self.write_reports()
        self.print_summary()
        if not self.pages:
            raise RuntimeError("Nessuna pagina HTML è stata importata dal sito sorgente")

    def prepare_output(self):
        self.pages_dir.mkdir(parents=True, exist_ok=True)
        for old in self.pages_dir.glob("*.json"):
            old.unlink()

    def discover_seed_urls(self):
        seeds = {self.base_url}
        candidates = {
            urljoin(self.base_url, "wp-sitemap.xml"),
            urljoin(self.base_url, "sitemap_index.xml"),
            urljoin(self.base_url, "sitemap.xml"),
        }
        robots_url = urljoin(self.base_url, "robots.txt")
        try:
            response = self.http_get(robots_url, timeout=25, max_bytes=2 * 1024 * 1024)
            self._save_plain_public_file(robots_url, response.content, response.content_type() or "text/plain")
            for line in response.text().splitlines():
                if line.lower().startswith("sitemap:"):
                    value = line.split(":", 1)[1].strip()
                    if value:
                        candidates.add(value)
        except Exception:
            pass

        visited = set()
        queue = deque(sorted(candidates))
        while queue and len(visited) < 100:
            sitemap_url = queue.popleft()
            if sitemap_url in visited:
                continue
            visited.add(sitemap_url)
            try:
                response = self.http_get(sitemap_url, timeout=30, max_bytes=10 * 1024 * 1024)
                root = ET.fromstring(response.content)
            except Exception:
                continue
            for loc in root.findall(".//{*}loc"):
                if not loc.text:
                    continue
                candidate = loc.text.strip()
                parsed = urlsplit(candidate)
                if (parsed.hostname or "").lower() != self.hostname:
                    continue
                if parsed.path.lower().endswith(".xml"):
                    queue.append(candidate)
                else:
                    page_url = self.normalize_page_url(candidate)
                    if page_url:
                        seeds.add(page_url)
        print("Seed URL individuati: {}".format(len(seeds)))
        return sorted(seeds)

    def crawl_pages(self, seeds):
        queue = deque()
        for seed in seeds:
            normalized = self.normalize_page_url(seed)
            if normalized and normalized not in self._queued_pages:
                self._queued_pages.add(normalized)
                queue.append(normalized)

        processed = set()
        while queue and len(processed) < self.max_pages:
            url = queue.popleft()
            if url in processed:
                continue
            processed.add(url)
            print("[page {:04d}] {}".format(len(processed), url))
            try:
                response = self.http_get(url, timeout=40, max_bytes=20 * 1024 * 1024)
            except Exception as exc:
                self.page_failures.append(FailureRecord(url, str(exc)))
                continue
            if response.status != 200:
                self.page_failures.append(FailureRecord(url, "HTTP {}".format(response.status)))
                continue
            text = response.text()
            ctype = response.content_type().lower()
            if "html" not in ctype and not text.lstrip().lower().startswith(("<!doctype", "<html")):
                continue

            collector = HtmlCollector(response.url)
            try:
                collector.feed(text)
                collector.close()
            except Exception as exc:
                self.page_failures.append(FailureRecord(url, "HTML parser: {}".format(exc)))
                continue

            canonical = urljoin(response.url, collector.canonical) if collector.canonical else response.url
            canonical_page_url = (
                self.normalize_page_url(canonical)
                or self.normalize_page_url(response.url)
                or url
            )
            canonical_path = urlsplit(canonical_page_url).path or "/"

            for href in collector.page_links:
                candidate = self.normalize_page_url(urljoin(response.url, href))
                if candidate and candidate not in self._queued_pages:
                    self._queued_pages.add(candidate)
                    queue.append(candidate)

            for raw, forced in collector.asset_candidates:
                self.consider_asset(raw, response.url, forced)
            for style_text in collector.inline_styles:
                self.discover_css_references(style_text, response.url)

            for match in ABSOLUTE_URL_RE.findall(text):
                if self.hostname in match:
                    self.consider_asset(match.rstrip("\\),]}"), response.url, False)

            self.record_runtime_dependencies(text, collector, canonical_page_url)
            snapshot = self.build_snapshot(text, collector, canonical_page_url)
            existing = self.pages.get(canonical_path)
            if existing is None or url == canonical_page_url:
                self.pages[canonical_path] = snapshot

        if queue:
            self.page_failures.append(
                FailureRecord(self.base_url, "Limite pagine raggiunto: {}".format(self.max_pages))
            )

    def normalize_page_url(self, url):
        try:
            parsed = urlsplit(urljoin(self.base_url, url))
        except ValueError:
            return None
        if parsed.scheme not in {"http", "https"}:
            return None
        if (parsed.hostname or "").lower() != self.hostname:
            return None
        path = parsed.path or "/"
        lower_path = path.lower()
        if any(lower_path.startswith(prefix) for prefix in EXCLUDED_PAGE_PREFIXES):
            return None
        if Path(unquote(path)).suffix.lower() not in PAGE_EXTENSIONS:
            return None
        return urlunsplit((self.scheme, self.netloc, path, "", ""))

    def consider_asset(self, raw_url, document_url, forced=False):
        raw_url = str(raw_url).strip()
        if not raw_url or raw_url.startswith(
            ("data:", "blob:", "javascript:", "mailto:", "tel:", "#")
        ):
            return
        absolute = urljoin(document_url, raw_url)
        parsed = urlsplit(absolute)
        if parsed.scheme not in {"http", "https"} or not parsed.netloc:
            return
        host = (parsed.hostname or "").lower()
        suffix = Path(unquote(parsed.path)).suffix.lower()
        is_asset = (
            forced or suffix in STATIC_EXTENSIONS
            or "/wp-content/" in parsed.path or "/wp-includes/" in parsed.path
        )
        if not is_asset:
            return
        if host != self.hostname:
            self.external_asset_hosts.add(host)
            return
        self.mirror_asset(absolute)

    def mirror_asset(self, url):
        parsed = urlsplit(url)
        key = urlunsplit((parsed.scheme, parsed.netloc, parsed.path, "", ""))
        if key in self.assets:
            return self.assets[key].public_path
        if key in self._asset_in_progress:
            return None
        local_path = self.safe_public_path(parsed.path)
        if local_path is None:
            self.asset_failures.append(FailureRecord(url, "path asset non sicuro"))
            return None
        public_url = "/" + local_path.relative_to(self.public_dir).as_posix()
        self._asset_in_progress.add(key)
        try:
            try:
                response = self.http_get(url, timeout=45, max_bytes=ASSET_LIMIT_BYTES)
            except Exception as exc:
                self.asset_failures.append(FailureRecord(url, str(exc)))
                return None
            if response.status != 200:
                self.asset_failures.append(FailureRecord(url, "HTTP {}".format(response.status)))
                return None
            suffix = Path(unquote(parsed.path)).suffix.lower()
            ctype = response.content_type() or "application/octet-stream"
            if suffix in STATIC_EXTENSIONS and "text/html" in ctype.lower():
                self.asset_failures.append(FailureRecord(url, "risposta HTML inattesa per asset"))
                return None

            local_path.parent.mkdir(parents=True, exist_ok=True)
            local_path.write_bytes(response.content)
            record = AssetRecord(
                key,
                public_url,
                len(response.content),
                hashlib.sha256(response.content).hexdigest(),
                ctype.split(";", 1)[0].strip(),
            )
            self.assets[key] = record
            if suffix == ".css" or "text/css" in ctype.lower():
                css = self.decode_bytes(response.content, ctype)
                self.discover_css_references(css, key)
            return public_url
        finally:
            self._asset_in_progress.discard(key)

    @staticmethod
    def decode_bytes(content, content_type):
        match = re.search(r"charset=([A-Za-z0-9._-]+)", content_type or "", re.I)
        encodings = ([match.group(1)] if match else []) + ["utf-8", "latin-1"]
        for enc in encodings:
            try:
                return content.decode(enc)
            except (UnicodeDecodeError, LookupError):
                pass
        return content.decode("utf-8", errors="replace")

    def discover_css_references(self, css_text, css_url):
        candidates = set()
        for match in CSS_URL_RE.finditer(css_text):
            candidates.add(match.group(2).strip())
        for match in CSS_IMPORT_RE.finditer(css_text):
            candidates.add(match.group(2).strip())
        for candidate in candidates:
            self.consider_asset(candidate, css_url, True)

    def safe_public_path(self, url_path):
        decoded = unquote(url_path)
        parts = [part for part in decoded.split("/") if part not in {"", "."}]
        if not parts or any(part == ".." for part in parts):
            return None
        target = self.public_dir.joinpath(*parts).resolve()
        try:
            target.relative_to(self.public_dir.resolve())
        except ValueError:
            return None
        return target

    @staticmethod
    def parse_attrs(fragment, tag_name):
        parser = AttrParser()
        try:
            parser.feed("<{}{}>".format(tag_name, fragment or ""))
            parser.close()
        except Exception:
            return {}
        return parser.attrs or {}

    @staticmethod
    def clean_head(head_html):
        value = TITLE_TAG_RE.sub("", head_html)
        value = META_CHARSET_RE.sub("", value)
        value = META_GENERATOR_RE.sub("", value)

        def filter_link(match):
            tag = match.group(0)
            lower = tag.lower()
            blocked = (
                'rel="edituri"', "rel='edituri'",
                'rel="wlwmanifest"', "rel='wlwmanifest'",
                'rel="shortlink"', "rel='shortlink'",
                "api.w.org", "wlwmanifest",
                "application/json+oembed", "text/xml+oembed",
                "application/rss+xml", "application/atom+xml",
            )
            return "" if any(item in lower for item in blocked) else tag

        return LINK_TAG_RE.sub(filter_link, value)

    def build_snapshot(self, text, collector, source_url):
        title_match = TITLE_RE.search(text)
        if title_match:
            title = html.unescape(TAG_RE.sub("", title_match.group(1))).strip()
        else:
            title = "chiabeatslife"

        head_match = HEAD_RE.search(text)
        head_html = self.clean_head(head_match.group(1)) if head_match else ""

        body_match = BODY_RE.search(text)
        if body_match:
            body_attrs = self.parse_attrs(body_match.group(1), "body")
            body_html = body_match.group(2)
        else:
            body_attrs = dict(collector.body_attrs)
            body_html = text

        html_match = HTML_RE.search(text)
        if html_match:
            html_attrs = self.parse_attrs(html_match.group(1), "html")
        else:
            html_attrs = dict(collector.html_attrs)
        lang = str(html_attrs.get("lang") or "it-IT")
        page_id = self.page_id(urlsplit(source_url).path or "/")
        snapshot = {
            "id": page_id,
            "source_url": source_url,
            "lang": lang,
            "title": title,
            "html_attributes": html_attrs,
            "body_attributes": body_attrs,
            "head_html": head_html,
            "body_html": body_html,
        }
        destination = self.pages_dir / "{}.json".format(page_id)
        destination.write_text(
            json.dumps(snapshot, ensure_ascii=False, indent=2), encoding="utf-8"
        )
        return snapshot

    def record_runtime_dependencies(self, text, collector, page_url):
        lower = text.lower()
        for marker, label in RUNTIME_MARKERS.items():
            if marker.lower() in lower:
                self.runtime_dependencies.setdefault(label, set()).add(page_url)
        for action in collector.form_actions:
            absolute = urljoin(page_url, action)
            parsed = urlsplit(absolute)
            if (parsed.hostname or "").lower() == self.hostname:
                path = parsed.path or "/"
                if path.startswith(("/wp-", "/xmlrpc")):
                    self.runtime_dependencies.setdefault(
                        "Form endpoint WordPress", set()
                    ).add(page_url)

    def fetch_site_level_files(self):
        for path in ("favicon.ico", "site.webmanifest", "manifest.json"):
            url = urljoin(self.base_url, path)
            try:
                response = self.http_get(url, timeout=20, max_bytes=ASSET_LIMIT_BYTES)
            except Exception:
                continue
            if response.content and "text/html" not in response.content_type().lower():
                self._save_plain_public_file(
                    url, response.content, response.content_type() or "application/octet-stream"
                )

    def _save_plain_public_file(self, url, content, content_type):
        parsed = urlsplit(url)
        local_path = self.safe_public_path(parsed.path)
        if local_path is None:
            return
        local_path.parent.mkdir(parents=True, exist_ok=True)
        local_path.write_bytes(content)
        key = urlunsplit((parsed.scheme, parsed.netloc, parsed.path, "", ""))
        self.assets[key] = AssetRecord(
            key,
            "/" + local_path.relative_to(self.public_dir).as_posix(),
            len(content),
            hashlib.sha256(content).hexdigest(),
            content_type.split(";", 1)[0].strip(),
        )

    def write_routes(self):
        routes = []
        for path, snapshot in sorted(
            self.pages.items(), key=lambda item: (item[0] != "/", item[0])
        ):
            routes.append({
                "name": self.route_name(path),
                "path": path,
                "page": str(snapshot["id"]),
                "source": str(snapshot["source_url"]),
            })
        config_path = self.output / "config" / "routes.php"
        config_path.parent.mkdir(parents=True, exist_ok=True)
        lines = [
            "<?php", "declare(strict_types=1);", "",
            "// Generato automaticamente da tools/import_wordpress.py.", "return [",
        ]
        for route in routes:
            lines.append(
                "    ['name' => {}, 'path' => {}, 'page' => {}],".format(
                    self.php_quote(route["name"]),
                    self.php_quote(route["path"]),
                    self.php_quote(route["page"]),
                )
            )
        lines.extend(["];", ""])
        config_path.write_text("\n".join(lines), encoding="utf-8")
        site_map_path = self.output / "storage" / "site-map.json"
        site_map_path.parent.mkdir(parents=True, exist_ok=True)
        site_map_path.write_text(
            json.dumps(routes, ensure_ascii=False, indent=2), encoding="utf-8"
        )

    def write_reports(self):
        report = {
            "source": self.base_url,
            "generated_at_epoch": int(time.time()),
            "pages": len(self.pages),
            "assets": len(self.assets),
            "css_assets": sum(
                1 for r in self.assets.values()
                if r.public_path.lower().endswith(".css")
            ),
            "js_assets": sum(
                1 for r in self.assets.values()
                if r.public_path.lower().endswith((".js", ".mjs"))
            ),
            "external_asset_hosts": sorted(
                host for host in self.external_asset_hosts if host
            ),
            "runtime_dependencies": {
                key: sorted(value)
                for key, value in sorted(self.runtime_dependencies.items())
            },
            "page_failures": [item.to_dict() for item in self.page_failures],
            "asset_failures": [item.to_dict() for item in self.asset_failures],
            "asset_manifest": [
                record.to_dict()
                for record in sorted(
                    self.assets.values(), key=lambda item: item.public_path
                )
            ],
        }
        report_path = self.output / "storage" / "migration-report.json"
        report_path.parent.mkdir(parents=True, exist_ok=True)
        report_path.write_text(
            json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8"
        )

    def print_summary(self):
        print("\nImport completato")
        print("  Pagine: {}".format(len(self.pages)))
        print("  Asset: {}".format(len(self.assets)))
        print("  Errori pagina: {}".format(len(self.page_failures)))
        print("  Errori asset: {}".format(len(self.asset_failures)))
        if self.runtime_dependencies:
            print("  Riferimenti WordPress residui rilevati:")
            for label, urls in sorted(self.runtime_dependencies.items()):
                print("    - {}: {} pagina/e".format(label, len(urls)))

    @staticmethod
    def page_id(path):
        if path == "/":
            return "home"
        readable = re.sub(
            r"[^a-z0-9]+", "-", unquote(path).strip("/").lower()
        ).strip("-")
        readable = readable[:60] or "page"
        digest = hashlib.sha1(path.encode("utf-8")).hexdigest()[:8]
        return "{}-{}".format(readable, digest)

    @staticmethod
    def route_name(path):
        if path == "/":
            return "home"
        readable = re.sub(
            r"[^a-z0-9]+", ".", unquote(path).strip("/").lower()
        ).strip(".")
        digest = hashlib.sha1(path.encode("utf-8")).hexdigest()[:6]
        return "page.{}.{}".format(readable or "route", digest)

    @staticmethod
    def php_quote(value):
        return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def parse_args():
    parser = argparse.ArgumentParser(
        description="Migra il frontend pubblico WordPress di Chiabeatslife a Slim 4"
    )
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--output", default=".")
    parser.add_argument("--max-pages", type=int, default=PAGE_LIMIT)
    return parser.parse_args()


def main():
    args = parse_args()
    importer = Importer(
        args.base_url, Path(args.output), max_pages=max(1, args.max_pages)
    )
    try:
        importer.run()
    except Exception as exc:
        print("ERRORE MIGRAZIONE: {}".format(exc), file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
