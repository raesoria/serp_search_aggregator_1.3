#!/usr/bin/env python3
"""
Public search result collector -> CSV/TXT exporter

What this does:
- Runs exact-match public web searches for one or more queries
- Collects visible result links from DuckDuckGo's HTML results
- Follows multiple result pages
- Deduplicates results
- Exports:
    1) a master CSV
    2) a plain TXT list of URLs
    3) a grouped-by-domain CSV
    4) a per-query CSV

What this does NOT do:
- It does not log into sites
- It does not bypass paywalls or CAPTCHAs
- It does not scrape private or protected platforms
- It does not submit reports automatically

Default queries:
- "Orange Tabby Cats" Cute

Dependencies:
    pip install requests beautifulsoup4

Usage:
    python public_search_result_collector.py
"""

from __future__ import annotations

import csv
import re
import time
from collections import defaultdict
from dataclasses import dataclass
from pathlib import Path
from urllib.parse import parse_qs, quote_plus, urlparse, urljoin

import requests
from bs4 import BeautifulSoup


ROOT = Path(__file__).resolve().parent
OUTPUT_DIR = ROOT / "public_search_output"


DEFAULT_QUERIES = [
    '"Orange Tabby Cats" cute', 
    '"-Green Cats" -ugly',
]


USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/123.0.0.0 Safari/537.36"
)


@dataclass(frozen=True)
class SearchResult:
    query: str
    domain: str
    url: str
    title: str


def clean_ddg_redirect(href: str) -> str:
    if not href:
        return ""

    if href.startswith("//"):
        href = "https:" + href
    elif href.startswith("/"):
        href = urljoin("https://html.duckduckgo.com", href)

    parsed = urlparse(href)
    if parsed.netloc.endswith("duckduckgo.com") and parsed.path.startswith("/l/"):
        qs = parse_qs(parsed.query)
        uddg = qs.get("uddg", [""])[0]
        return uddg or href

    return href


def normalize_domain(url: str) -> str:
    parsed = urlparse(url)
    domain = parsed.netloc.lower().strip()
    if domain.startswith("www."):
        domain = domain[4:]
    return domain


def fetch_html(url: str, session: requests.Session) -> str:
    resp = session.get(url, timeout=30)
    resp.raise_for_status()
    return resp.text


def parse_ddg_results(html: str, query: str) -> tuple[list[SearchResult], str | None]:
    soup = BeautifulSoup(html, "html.parser")
    results: list[SearchResult] = []

    link_nodes = soup.select("a.result__a") or soup.select("a[href]")
    seen_urls: set[str] = set()

    for a in link_nodes:
        href = clean_ddg_redirect(a.get("href", "").strip())
        title = " ".join(a.get_text(" ", strip=True).split())

        if not href.startswith("http"):
            continue

        domain = normalize_domain(href)
        if not domain:
            continue

        if "duckduckgo.com" in domain:
            continue

        if href in seen_urls:
            continue
        seen_urls.add(href)

        results.append(
            SearchResult(
                query=query,
                domain=domain,
                url=href,
                title=title,
            )
        )

    next_url = None
    next_link = soup.find("a", string=re.compile(r"next", re.I))
    if next_link and next_link.get("href"):
        next_url = next_link["href"]
        if next_url.startswith("/"):
            next_url = urljoin("https://html.duckduckgo.com", next_url)

    return results, next_url


def search_query(query: str, max_pages: int, delay_seconds: float, session: requests.Session) -> list[SearchResult]:
    encoded = quote_plus(query)
    url = f"https://html.duckduckgo.com/html/?q={encoded}"

    all_results: list[SearchResult] = []

    for page_num in range(1, max_pages + 1):
        print(f"Searching page {page_num} for {query} ...")
        html = fetch_html(url, session)
        page_results, next_url = parse_ddg_results(html, query)

        added_this_page = 0
        for result in page_results:
            all_results.append(result)
            added_this_page += 1

        print(f"  Collected {added_this_page} result(s) on page {page_num}.")

        if not next_url:
            break

        url = next_url
        if page_num < max_pages:
            time.sleep(delay_seconds)

    return all_results


def prompt_queries() -> list[str]:
    print("\nDefault queries:")
    for q in DEFAULT_QUERIES:
        print(f" - {q}")

    use_defaults = input("\nUse these defaults? [Y/n]: ").strip().lower()
    if use_defaults in ("", "y", "yes"):
        return DEFAULT_QUERIES.copy()

    queries: list[str] = []
    print("\nEnter one query per line. Press Enter on a blank line when done.")
    while True:
        q = input("Query: ").strip()
        if not q:
            break
        queries.append(q)

    if not queries:
        print("No custom queries entered. Falling back to defaults.")
        return DEFAULT_QUERIES.copy()

    return queries


def write_master_csv(results: list[SearchResult], path: Path) -> None:
    with path.open("w", newline="", encoding="utf-8") as f:
        writer = csv.writer(f)
        writer.writerow(["query", "domain", "url", "title"])
        for r in results:
            writer.writerow([r.query, r.domain, r.url, r.title])


def write_url_txt(results: list[SearchResult], path: Path) -> None:
    with path.open("w", encoding="utf-8") as f:
        for r in results:
            f.write(r.url + "\n")


def write_grouped_by_domain_csv(results: list[SearchResult], path: Path) -> None:
    grouped: dict[tuple[str, str], list[SearchResult]] = defaultdict(list)
    for r in results:
        grouped[(r.query, r.domain)].append(r)

    with path.open("w", newline="", encoding="utf-8") as f:
        writer = csv.writer(f)
        writer.writerow(["query", "domain", "url_count", "urls"])
        for (query, domain), items in sorted(grouped.items(), key=lambda x: (x[0][0], x[0][1])):
            writer.writerow([query, domain, len(items), "\n".join(i.url for i in items)])


def write_per_query_csv(results: list[SearchResult], output_dir: Path) -> None:
    per_query_dir = output_dir / "per_query_csv"
    per_query_dir.mkdir(parents=True, exist_ok=True)

    grouped: dict[str, list[SearchResult]] = defaultdict(list)
    for r in results:
        grouped[r.query].append(r)

    for query, items in grouped.items():
        safe_name = re.sub(r"[^a-zA-Z0-9._-]+", "_", query).strip("_") or "query"
        path = per_query_dir / f"{safe_name}.csv"
        with path.open("w", newline="", encoding="utf-8") as f:
            writer = csv.writer(f)
            writer.writerow(["query", "domain", "url", "title"])
            for r in items:
                writer.writerow([r.query, r.domain, r.url, r.title])


def main() -> int:
    print("Public search result collector -> CSV/TXT exporter")
    queries = prompt_queries()

    try:
        max_pages = int(input("How many result pages per query? [5]: ").strip() or "5")
    except ValueError:
        max_pages = 5

    try:
        delay_seconds = float(input("Delay between pages in seconds? [1.5]: ").strip() or "1.5")
    except ValueError:
        delay_seconds = 1.5

    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)

    session = requests.Session()
    session.headers.update({"User-Agent": USER_AGENT})

    all_results: list[SearchResult] = []
    for query in queries:
        results = search_query(query=query, max_pages=max_pages, delay_seconds=delay_seconds, session=session)
        all_results.extend(results)

    write_master_csv(deduped, OUTPUT_DIR / "search_results_master.csv")
    write_url_txt(deduped, OUTPUT_DIR / "search_results_urls.txt")
    write_grouped_by_domain_csv(deduped, OUTPUT_DIR / "search_results_grouped_by_domain.csv")
    write_per_query_csv(deduped, OUTPUT_DIR)

    print("\nDone.")
    print(f"Master CSV: {OUTPUT_DIR / 'search_results_master.csv'}")
    print(f"URL TXT:    {OUTPUT_DIR / 'search_results_urls.txt'}")
    print(f"Grouped CSV:{OUTPUT_DIR / 'search_results_grouped_by_domain.csv'}")
    print(f"Per-query folder: {OUTPUT_DIR / 'per_query_csv'}")
    print(f"Total unique results: {len(deduped)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())