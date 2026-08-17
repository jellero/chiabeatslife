#!/usr/bin/env python3
"""Importa il frontend pubblico WordPress in uno snapshot servibile da Slim 4.

Compatibile con Python 3.6+.
Il crawler conserva CSS e JavaScript byte-per-byte nel loro path pubblico originale,
ricostruisce una shell HTML indipendente da WordPress e genera un inventario di route.
"""

import argparse
import hashlib
import json
import re
import sys
import time
import xml.etree.ElementTree as ET
from collections import deque
from pathlib import Path
from urllib.parse import unquote, urljoin, urlsplit, urlunsplit

import requests
from bs4 import BeautifulSoup
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry

USER_AGENT = (
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/124.0 Safari/537.36 "
    "ChiabeatslifeMigration/1.1"
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
    "/wp-admin",
    "/wp-login.php",
    "/wp-json",
    "/xmlrpc.php",
    "/wp-cron.php",
    "/wp-comments-post.php",
)
RUNTIME_MARKERS = {
    "wp-admin/admin-ajax.php": "WordPress AJAX endpoint",
    "/wp-json/": "WordPress REST API",
    "xmlrpc.php": "WordPress XML-RPC",
    "wp-comments-post.php": "WordPress comments endpoint",
    "wpcf7": "Contact Form 7 runtime",
    "elementor-pro": "Elementor Pro runtime",
}
CSS_URL_RE = re.compile(r"url\(\s*(['\"]?)([^)'\"]+)\1\s*\)", re.IGNORECASE)
CSS_IMPORT_RE = re.compile(r"@import\s+(?:url\()?\s*(['\"])([^'\"]+)\1\s*\)?", re.IGNORECASE)
ABSOLUTE_URL_RE = re.compile(r"https?://[^\s'\"<>]+", re.IGNORECASE)


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

        retry = Retry(
            total=3,
            connect=3,
            read=3,
            backoff_factor=0.6,
            status_forcelist=(429, 500, 502, 503, 504),
        )
        self.session = requests.Session()
        self.session.headers.update({
            "User-Agent": USER_AGENT,
            "Accept-Language": "it-IT,it;q=0.9,en;q=0.6",
        })
        self.session.mount("https://", HTTPAdapter(max_retries=retry))
        self.session.mount("http://", HTTPAdapter(max_retries=retry))

        self.pages = {}
        self.assets = {}
        self.asset_failures = []
        self.page_failures = []
        self.external_asset_hosts = set()
        self.runtime_dependencies = {}
        self._asset_in_progress = set()
        self._queued_pages = set()

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
        sitemap_candidates = {
            urljoin(self.base_url, "wp-sitemap.xml"),
            urljoin(self.base_url, "sitemap_index.xml"),
            urljoin(self.base_url, "sitemap.xml"),
        }

        robots_url = urljoin(self.base_url, "robots.txt")
        try:
            response = self.session.get(robots_url, timeout=25)
            if response.ok:
                self._save_plain_public_file(
                    robots_url,
                    response.content,
                    response.headers.get("Content-Type", "text/plain"),
                )
                for line in response.text.splitlines():
                    if line.lower().startswith("sitemap:"):
                        candidate = line.split(":", 1)[1].strip()
                        if candidate:
                            sitemap_candidates.add(candidate)
        except requests.RequestException as exc:
            self.asset_failures.append(FailureRecord(robots_url, "robots: {}".format(exc)))

        visited_sitemaps = set()
        queue = deque(sorted(sitemap_candidates))
        while queue and len(visited_sitemaps) < 100:
            sitemap_url = queue.popleft()
            if sitemap_url in visited_sitemaps:
                continue
            visited_sitemaps.add(sitemap_url)
            try:
                response = self.session.get(sitemap_url, timeout=30)
            except requests.RequestException:
                continue
            if not response.ok or not response.content:
                continue
            try:
                root = ET.fromstring(response.content)
            except ET.ParseError:
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
                    continue
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
                response = self.session.get(url, timeout=40, allow_redirects=True)
            except requests.RequestException as exc:
                self.page_failures.append(FailureRecord(url, str(exc)))
                continue

            if response.status_code != 200:
                self.page_failures.append(FailureRecord(url, "HTTP {}".format(response.status_code)))
                continue
            content_type = response.headers.get("Content-Type", "")
            if (
                "html" not in content_type.lower()
                and not response.text.lstrip().lower().startswith(("<!doctype", "<html"))
            ):
                continue

            final_url = response.url
            soup = BeautifulSoup(response.text, "html.parser")
            canonical_url = self.extract_canonical_url(soup, final_url)
            canonical_page_url = (
                self.normalize_page_url(canonical_url)
                or self.normalize_page_url(final_url)
                or url
            )
            canonical_path = urlsplit(canonical_page_url).path or "/"

            self.discover_page_links(soup, final_url, queue)
            self.discover_and_mirror_assets(soup, final_url)
            self.record_runtime_dependencies(soup, canonical_page_url)

            snapshot = self.build_snapshot(soup, canonical_page_url)
            existing = self.pages.get(canonical_path)
            if existing is None or url == canonical_page_url:
                self.pages[canonical_path] = snapshot

        if queue:
            self.page_failures.append(
                FailureRecord(self.base_url, "Limite pagine raggiunto: {}".format(self.max_pages))
            )

    def discover_page_links(self, soup, document_url, queue):
        for tag in soup.find_all(["a", "link"]):
            href = tag.get("href")
            if not isinstance(href, str) or not href.strip():
                continue
            if tag.name == "link":
                rel = {str(item).lower() for item in (tag.get("rel") or [])}
                if not ({"next", "prev"} & rel):
                    continue
            candidate = self.normalize_page_url(urljoin(document_url, href.strip()))
            if candidate and candidate not in self._queued_pages:
                self._queued_pages.add(candidate)
                queue.append(candidate)

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
        suffix = Path(unquote(path)).suffix.lower()
        if suffix not in PAGE_EXTENSIONS:
            return None
        return urlunsplit((self.scheme, self.netloc, path, "", ""))

    def extract_canonical_url(self, soup, fallback):
        canonical = soup.find(
            "link",
            rel=lambda value: value and "canonical" in [
                str(v).lower()
                for v in (value if isinstance(value, list) else [value])
            ],
        )
        href = canonical.get("href") if canonical else None
        if isinstance(href, str) and href.strip():
            return urljoin(fallback, href.strip())
        return fallback

    def discover_and_mirror_assets(self, soup, document_url):
        for tag in soup.find_all(True):
            tag_name = tag.name.lower()

            for attr_name in (
                "src", "poster", "data-src", "data-lazy-src", "data-original",
                "data-background", "data-background-image", "data-thumb",
            ):
                value = tag.get(attr_name)
                if isinstance(value, str):
                    self.consider_asset(
                        value,
                        document_url,
                        forced=tag_name in {"script", "img", "source", "video", "audio"},
                    )

            href = tag.get("href")
            if isinstance(href, str):
                rel = {str(item).lower() for item in (tag.get("rel") or [])}
                forced_link_asset = (
                    tag_name == "link"
                    and bool(rel & {
                        "stylesheet", "icon", "preload", "modulepreload",
                        "manifest", "apple-touch-icon",
                    })
                )
                self.consider_asset(href, document_url, forced=forced_link_asset)

            for attr_name in ("srcset", "data-srcset", "imagesrcset"):
                value = tag.get(attr_name)
                if isinstance(value, str):
                    for item in value.split(","):
                        candidate = item.strip().split(" ", 1)[0]
                        if candidate:
                            self.consider_asset(candidate, document_url, forced=True)

            style = tag.get("style")
            if isinstance(style, str):
                self.discover_css_references(style, document_url)

            for attr_name, attr_value in list(tag.attrs.items()):
                if not str(attr_name).startswith("data-"):
                    continue
                if isinstance(attr_value, list):
                    text = " ".join(str(v) for v in attr_value)
                else:
                    text = str(attr_value)
                if self.hostname in text:
                    for match in ABSOLUTE_URL_RE.findall(text):
                        self.consider_asset(
                            match.rstrip("\\),]}"),
                            document_url,
                            forced=False,
                        )

        for style_tag in soup.find_all("style"):
            if style_tag.string:
                self.discover_css_references(style_tag.string, document_url)

    def consider_asset(self, raw_url, document_url, forced=False):
        raw_url = raw_url.strip()
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
            forced
            or suffix in STATIC_EXTENSIONS
            or "/wp-content/" in parsed.path
            or "/wp-includes/" in parsed.path
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
                response = self.session.get(url, timeout=45, allow_redirects=True)
            except requests.RequestException as exc:
                self.asset_failures.append(FailureRecord(url, str(exc)))
                return None
            if response.status_code != 200:
                self.asset_failures.append(
                    FailureRecord(url, "HTTP {}".format(response.status_code))
                )
                return None

            length_header = response.headers.get("Content-Length")
            if (
                length_header
                and length_header.isdigit()
                and int(length_header) > ASSET_LIMIT_BYTES
            ):
                self.asset_failures.append(
                    FailureRecord(
                        url,
                        "asset oltre {} byte".format(ASSET_LIMIT_BYTES),
                    )
                )
                return None

            content = response.content
            if len(content) > ASSET_LIMIT_BYTES:
                self.asset_failures.append(
                    FailureRecord(
                        url,
                        "asset oltre {} byte".format(ASSET_LIMIT_BYTES),
                    )
                )
                return None
            content_type = response.headers.get("Content-Type", "application/octet-stream")

            expected_suffix = Path(unquote(parsed.path)).suffix.lower()
            if (
                expected_suffix in STATIC_EXTENSIONS
                and "text/html" in content_type.lower()
            ):
                self.asset_failures.append(
                    FailureRecord(url, "risposta HTML inattesa per asset")
                )
                return None

            local_path.parent.mkdir(parents=True, exist_ok=True)
            local_path.write_bytes(content)
            record = AssetRecord(
                source_url=key,
                public_path=public_url,
                size=len(content),
                sha256=hashlib.sha256(content).hexdigest(),
                content_type=content_type.split(";", 1)[0].strip(),
            )
            self.assets[key] = record

            if expected_suffix == ".css" or "text/css" in content_type.lower():
                encoding = response.encoding or "utf-8"
                try:
                    css_text = content.decode(encoding, errors="replace")
                except LookupError:
                    css_text = content.decode("utf-8", errors="replace")
                self.discover_css_references(css_text, key)

            return public_url
        finally:
            self._asset_in_progress.discard(key)

    def discover_css_references(self, css_text, css_url):
        candidates = set()
        for match in CSS_URL_RE.finditer(css_text):
            candidates.add(match.group(2).strip())
        for match in CSS_IMPORT_RE.finditer(css_text):
            candidates.add(match.group(2).strip())
        for candidate in candidates:
            self.consider_asset(candidate, css_url, forced=True)

    def safe_public_path(self, url_path):
        decoded = unquote(url_path)
        parts = [part for part in decoded.split("/") if part not in {"", "."}]
        if not parts or any(part == ".." for part in parts):
            return None
        target = (self.public_dir.joinpath(*parts)).resolve()
        try:
            target.relative_to(self.public_dir.resolve())
        except ValueError:
            return None
        return target

    def build_snapshot(self, soup, source_url):
        self.strip_wordpress_runtime_metadata(soup)

        title_tag = soup.find("title")
        title = title_tag.get_text(" ", strip=True) if title_tag else "chiabeatslife"
        if title_tag:
            title_tag.decompose()

        head = soup.head
        if head:
            for charset in head.find_all("meta", attrs={"charset": True}):
                charset.decompose()
            head_html = "".join(str(node) for node in head.contents)
        else:
            head_html = ""

        body = soup.body
        if body:
            body_html = "".join(str(node) for node in body.contents)
            body_attributes = self.normalize_attributes(body.attrs)
        else:
            body_html = str(soup)
            body_attributes = {}

        html_tag = soup.html
        html_attributes = self.normalize_attributes(html_tag.attrs) if html_tag else {}
        lang = str(html_attributes.get("lang") or "it-IT")

        page_id = self.page_id(urlsplit(source_url).path or "/")
        snapshot = {
            "id": page_id,
            "source_url": source_url,
            "lang": lang,
            "title": title,
            "html_attributes": html_attributes,
            "body_attributes": body_attributes,
            "head_html": head_html,
            "body_html": body_html,
        }

        destination = self.pages_dir / "{}.json".format(page_id)
        destination.write_text(
            json.dumps(snapshot, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )
        return snapshot

    @staticmethod
    def normalize_attributes(attrs):
        normalized = {}
        for name, value in attrs.items():
            if value is None:
                normalized[str(name)] = True
            elif isinstance(value, list):
                normalized[str(name)] = " ".join(str(item) for item in value)
            else:
                normalized[str(name)] = str(value)
        return normalized

    @staticmethod
    def strip_wordpress_runtime_metadata(soup):
        for meta in soup.find_all("meta"):
            if str(meta.get("name", "")).lower() == "generator":
                meta.decompose()

        for link in list(soup.find_all("link")):
            rel = {str(item).lower() for item in (link.get("rel") or [])}
            href = str(link.get("href") or "")
            link_type = str(link.get("type") or "").lower()
            if rel & {"edituri", "wlwmanifest", "shortlink"}:
                link.decompose()
                continue
            if "api.w.org" in href or "wlwmanifest" in href:
                link.decompose()
                continue
            if (
                "application/json+oembed" in link_type
                or "text/xml+oembed" in link_type
            ):
                link.decompose()
                continue
            if (
                "application/rss+xml" in link_type
                or "application/atom+xml" in link_type
            ):
                link.decompose()

    def record_runtime_dependencies(self, soup, page_url):
        text = str(soup)
        for marker, label in RUNTIME_MARKERS.items():
            if marker.lower() in text.lower():
                self.runtime_dependencies.setdefault(label, set()).add(page_url)

        for form in soup.find_all("form"):
            action = form.get("action")
            if not isinstance(action, str) or not action.strip():
                continue
            absolute = urljoin(page_url, action.strip())
            parsed = urlsplit(absolute)
            if (parsed.hostname or "").lower() == self.hostname:
                path = parsed.path or "/"
                if path.startswith(("/wp-", "/xmlrpc")):
                    self.runtime_dependencies.setdefault(
                        "Form endpoint WordPress",
                        set(),
                    ).add(page_url)

    def fetch_site_level_files(self):
        for path in ("favicon.ico", "site.webmanifest", "manifest.json"):
            url = urljoin(self.base_url, path)
            try:
                response = self.session.get(url, timeout=20)
            except requests.RequestException:
                continue
            if (
                response.ok
                and response.content
                and "text/html" not in response.headers.get(
                    "Content-Type",
                    "",
                ).lower()
            ):
                self._save_plain_public_file(
                    url,
                    response.content,
                    response.headers.get(
                        "Content-Type",
                        "application/octet-stream",
                    ),
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
            source_url=key,
            public_path="/" + local_path.relative_to(self.public_dir).as_posix(),
            size=len(content),
            sha256=hashlib.sha256(content).hexdigest(),
            content_type=content_type.split(";", 1)[0].strip(),
        )

    def write_routes(self):
        routes = []
        for path, snapshot in sorted(
            self.pages.items(),
            key=lambda item: (item[0] != "/", item[0]),
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
            "<?php",
            "declare(strict_types=1);",
            "",
            "// Generato automaticamente da tools/import_wordpress.py.",
            "return [",
        ]
        for route in routes:
            lines.append(
                "    ["
                "'name' => {}, ".format(self.php_quote(route["name"]))
                + "'path' => {}, ".format(self.php_quote(route["path"]))
                + "'page' => {}],".format(self.php_quote(route["page"]))
            )
        lines.extend(["];", ""])
        config_path.write_text("\n".join(lines), encoding="utf-8")

        site_map_path = self.output / "storage" / "site-map.json"
        site_map_path.parent.mkdir(parents=True, exist_ok=True)
        site_map_path.write_text(
            json.dumps(routes, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )

    def write_reports(self):
        report = {
            "source": self.base_url,
            "generated_at_epoch": int(time.time()),
            "pages": len(self.pages),
            "assets": len(self.assets),
            "css_assets": sum(
                1
                for record in self.assets.values()
                if record.public_path.lower().endswith(".css")
            ),
            "js_assets": sum(
                1
                for record in self.assets.values()
                if record.public_path.lower().endswith((".js", ".mjs"))
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
                    self.assets.values(),
                    key=lambda item: item.public_path,
                )
            ],
        }
        report_path = self.output / "storage" / "migration-report.json"
        report_path.parent.mkdir(parents=True, exist_ok=True)
        report_path.write_text(
            json.dumps(report, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )

    def print_summary(self):
        print("\nImport completato")
        print("  Pagine: {}".format(len(self.pages)))
        print("  Asset: {}".format(len(self.assets)))
        print("  Errori pagina: {}".format(len(self.page_failures)))
        print("  Errori asset: {}".format(len(self.asset_failures)))
        if self.runtime_dependencies:
            print("  Dipendenze WordPress residue rilevate:")
            for label, urls in sorted(self.runtime_dependencies.items()):
                print("    - {}: {} pagina/e".format(label, len(urls)))

    @staticmethod
    def page_id(path):
        if path == "/":
            return "home"
        readable = re.sub(
            r"[^a-z0-9]+",
            "-",
            unquote(path).strip("/").lower(),
        ).strip("-")
        readable = readable[:60] or "page"
        digest = hashlib.sha1(path.encode("utf-8")).hexdigest()[:8]
        return "{}-{}".format(readable, digest)

    @staticmethod
    def route_name(path):
        if path == "/":
            return "home"
        readable = re.sub(
            r"[^a-z0-9]+",
            ".",
            unquote(path).strip("/").lower(),
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
    parser.add_argument(
        "--base-url",
        required=True,
        help="URL radice del sito WordPress sorgente",
    )
    parser.add_argument(
        "--output",
        default=".",
        help="Root del repository di destinazione",
    )
    parser.add_argument(
        "--max-pages",
        type=int,
        default=PAGE_LIMIT,
        help="Limite di sicurezza del crawler",
    )
    return parser.parse_args()


def main():
    args = parse_args()
    importer = Importer(
        args.base_url,
        Path(args.output),
        max_pages=max(1, args.max_pages),
    )
    try:
        importer.run()
    except Exception as exc:
        print("ERRORE MIGRAZIONE: {}".format(exc), file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
