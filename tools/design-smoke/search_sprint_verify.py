#!/usr/bin/env python3
"""
Screenshot search-at-every-level comps (mobile + desktop).
Ephemeral php -S child — always killed in finally.
  python3 tools/design-smoke/search_sprint_verify.py
"""
from __future__ import annotations

import socket
import subprocess
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
COMPS = Path(__file__).resolve().parent / "search-sprint"
OUT = Path(__file__).resolve().parent / "output" / "search-sprint"
VIEWPORTS = [
    ("mobile", {"width": 390, "height": 844}),
    ("desktop", {"width": 1280, "height": 900}),
]
SECTIONS = ["r1", "r2", "r3", "r4", "r5", "r6", "r7"]


def free_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.bind(("127.0.0.1", 0))
        return int(s.getsockname()[1])


def main() -> int:
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        print("Install: pip install playwright && playwright install chromium", file=sys.stderr)
        return 1

    port = free_port()
    proc = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{port}", "-t", str(COMPS), str(COMPS / "router.php")],
        cwd=str(COMPS),
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    OUT.mkdir(parents=True, exist_ok=True)
    base = f"http://127.0.0.1:{port}/"
    try:
        time.sleep(0.4)
        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            for name, size in VIEWPORTS:
                context = browser.new_context(viewport=size)
                page = context.new_page()
                page.goto(base, wait_until="networkidle", timeout=60000)
                full = OUT / f"full_{name}_{size['width']}x{size['height']}.png"
                page.screenshot(path=str(full), full_page=True)
                print(full)
                for sid in SECTIONS:
                    el = page.locator(f"#{sid}")
                    shot = OUT / f"{sid}_{name}_{size['width']}x{size['height']}.png"
                    el.screenshot(path=str(shot))
                    print(shot)
                context.close()
            browser.close()
    finally:
        proc.terminate()
        try:
            proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            proc.kill()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
