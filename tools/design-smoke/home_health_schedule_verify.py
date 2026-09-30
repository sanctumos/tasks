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
    page.wait_for_load_state("networkidle", timeout=30000)
    if "login.php" in page.url:
        raise RuntimeError(f"login failed: {page.url}")


def capture(page, name: str) -> None:
    page.goto(f"{BASE}/admin/", wait_until="networkidle", timeout=60000)
    page.wait_for_selector("h1", timeout=30000)
    # Require widgets when enabled for the smoke user.
    if page.locator(".st-home-health").count() == 0:
        raise RuntimeError("missing Board health section (.st-home-health)")
    if page.locator("#st-home-schedule-heading").count() == 0:
        raise RuntimeError("missing Schedule peek (#st-home-schedule-heading)")
    page.screenshot(path=str(OUT / f"{name}.png"), full_page=True)


def main() -> int:
    user = os.environ.get("HOME_HEALTH_USER") or os.environ.get("TASKS_DSC_OTTOVERNAL_USERNAME", "")
    password = os.environ.get("HOME_HEALTH_PASSWORD") or os.environ.get("TASKS_DSC_OTTOVERNAL_PASSWORD", "")
    if not user or not password:
        print("FAIL: need HOME_HEALTH_USER/PASSWORD (or TASKS_DSC_OTTOVERNAL_*)")
        return 2

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        try:
            for label, size in (("desktop", {"width": 1280, "height": 800}), ("mobile", {"width": 390, "height": 844})):
                context = browser.new_context(viewport=size)
                page = context.new_page()
                login(page, user, password)
                capture(page, f"home-{label}")
                # Archived boards permanent-delete UI
                page.goto(f"{BASE}/admin/settings.php?tab=archived-boards", wait_until="networkidle", timeout=60000)
                if page.locator("text=Permanently delete").count() == 0:
                    raise RuntimeError("missing Permanently delete on archived-boards")
                page.screenshot(path=str(OUT / f"archived-{label}.png"), full_page=True)
                context.close()
        finally:
            browser.close()

    print(f"OK screenshots under {OUT}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
