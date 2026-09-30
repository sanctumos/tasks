#!/usr/bin/env python3
"""Playwright: Home board health + schedule peek on Dev (mobile + desktop)."""
from __future__ import annotations

import os
import sys
from pathlib import Path

from playwright.sync_api import sync_playwright

BASE = os.environ.get("HOME_HEALTH_BASE", "https://dev.tasks.decisionsciencecorp.com").rstrip("/")
OUT = Path(__file__).resolve().parent / "output" / "home-health-schedule"
OUT.mkdir(parents=True, exist_ok=True)


def login(page, user: str, password: str) -> None:
    page.goto(f"{BASE}/admin/login.php", wait_until="networkidle", timeout=60000)
    page.fill('input[name="username"]', user)
    page.fill('input[name="password"]', password)
    page.locator('button[type="submit"]').click()
    page.wait_for_url("**/admin/**", timeout=30000)


def capture(page, name: str) -> None:
    page.goto(f"{BASE}/admin/", wait_until="networkidle", timeout=60000)
    page.wait_for_selector("h1", timeout=30000)
    # Board health may be empty for some users but section should render when default on.
    page.screenshot(path=str(OUT / f"{name}.png"), full_page=True)


def main() -> int:
    user = os.environ.get("TASKS_DSC_OTTOVERNAL_USERNAME", "")
    password = os.environ.get("TASKS_DSC_OTTOVERNAL_PASSWORD", "")
    if not user or not password:
        print("FAIL: need TASKS_DSC_OTTOVERNAL_USERNAME/PASSWORD")
        return 2

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        try:
            for label, size in (("desktop", {"width": 1280, "height": 800}), ("mobile", {"width": 390, "height": 844})):
                context = browser.new_context(viewport=size)
                page = context.new_page()
                login(page, user, password)
                capture(page, f"home-{label}")
                context.close()
        finally:
            browser.close()

    print(f"OK screenshots under {OUT}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
