#!/usr/bin/env python3
"""Smoke: dev.tasks skin comp bar — login + home board, mobile + desktop."""
from __future__ import annotations

import os
import sys
from pathlib import Path

from playwright.sync_api import sync_playwright

BASE = "https://dev.tasks.decisionsciencecorp.com"
OUT = Path(__file__).resolve().parent / "output" / "dev-skin-lab-v2"
OUT.mkdir(parents=True, exist_ok=True)
SKINS = ("hey", "ledger", "brutalist", "obsidian")


def login(page, user: str, password: str) -> None:
    page.goto(f"{BASE}/admin/login.php", wait_until="networkidle", timeout=60000)
    page.fill('input[name="username"]', user)
    page.fill('input[name="password"]', password)
    page.locator('button[type="submit"]').click()
    page.wait_for_url("**/admin/**", timeout=30000)


def capture_skin(page, skin: str, prefix: str) -> None:
    page.locator(f'[data-skin-set="{skin}"]').click()
    page.wait_for_timeout(500)
    slug = page.evaluate("document.documentElement.getAttribute('data-skin-comp')")
    if slug != skin:
        raise RuntimeError(f"toggle {skin} got {slug}")
    page.screenshot(path=str(OUT / f"{prefix}-{skin}.png"), full_page=False)


def main() -> int:
    user = os.environ.get("TASKS_DSC_OTTOVERNAL_USERNAME", "")
    password = os.environ.get("TASKS_DSC_OTTOVERNAL_PASSWORD", "")
    if not user or not password:
        print("WARN: set TASKS_DSC_OTTOVERNAL_* for logged-in captures; login-only mode")

    failures: list[str] = []
    with sync_playwright() as p:
        browser = p.chromium.launch()
        for name, size in (
            ("mobile", {"width": 390, "height": 844}),
            ("desktop", {"width": 1280, "height": 800}),
        ):
            page = browser.new_page(viewport=size)
            try:
                resp = page.goto(f"{BASE}/admin/login.php", wait_until="networkidle", timeout=60000)
                if not resp or resp.status != 200:
                    failures.append(f"{name} login HTTP {getattr(resp, 'status', 0)}")
                if page.locator("#st-skin-comp-bar").count() == 0:
                    failures.append(f"{name}: missing comp bar")
                page.screenshot(path=str(OUT / f"login-{name}.png"), full_page=True)
                for skin in SKINS:
                    capture_skin(page, skin, f"login-{name}")

                if user and password:
                    login(page, user, password)
                    page.goto(f"{BASE}/admin/", wait_until="networkidle", timeout=60000)
                    page.wait_for_timeout(400)
                    page.screenshot(path=str(OUT / f"home-{name}-default.png"), full_page=False)
                    for skin in SKINS:
                        capture_skin(page, skin, f"home-{name}")
            except Exception as exc:  # noqa: BLE001
                failures.append(f"{name}: {exc}")
            finally:
                page.close()
        browser.close()

    if failures:
        print("FAIL:", "; ".join(failures))
        return 1
    print(f"OK — screenshots in {OUT}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
