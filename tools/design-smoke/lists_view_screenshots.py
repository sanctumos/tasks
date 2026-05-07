#!/usr/bin/env python3
"""
Capture desktop + mobile screenshots of the BC3-style Lists tab.
Run with: python3 tools/design-smoke/lists_view_screenshots.py

Reads credentials from env (load via the workspace .pass file):
  TASKS_DSC_BASE_URL
  TASKS_DSC_OTTOVERNAL_USERNAME
  TASKS_DSC_OTTOVERNAL_PASSWORD

Outputs:
  /tmp/sanctum-tasks-lists-desktop.png
  /tmp/sanctum-tasks-lists-mobile.png
"""
from __future__ import annotations

import os
import sys
from pathlib import Path

from playwright.sync_api import sync_playwright

BASE_URL = os.environ["TASKS_DSC_BASE_URL"].rstrip("/")
USERNAME = os.environ["TASKS_DSC_OTTOVERNAL_USERNAME"]
PASSWORD = os.environ["TASKS_DSC_OTTOVERNAL_PASSWORD"]
PROJECT_ID = int(os.environ.get("TASKS_DESIGN_SMOKE_PROJECT_ID", "1"))

OUT_DIR = Path(os.environ.get("TASKS_DESIGN_SMOKE_OUT_DIR", "/tmp"))


def login(page):
    page.goto(f"{BASE_URL}/admin/login.php", wait_until="networkidle")
    page.fill('input[name="username"]', USERNAME)
    page.fill('input[name="password"]', PASSWORD)
    page.click('button[type="submit"], input[type="submit"]')
    page.wait_for_load_state("networkidle")


def capture(viewport_name, viewport):
    out = OUT_DIR / f"sanctum-tasks-lists-{viewport_name}.png"
    with sync_playwright() as pw:
        browser = pw.chromium.launch()
        ctx = browser.new_context(viewport=viewport, ignore_https_errors=False)
        page = ctx.new_page()
        login(page)
        target = f"{BASE_URL}/admin/project.php?id={PROJECT_ID}&tab=lists"
        page.goto(target, wait_until="networkidle")
        page.wait_for_selector(".tabbar", timeout=15000)
        page.screenshot(path=str(out), full_page=True)
        browser.close()
    print(f"saved {out}")


def main() -> int:
    capture("desktop", {"width": 1280, "height": 900})
    capture("mobile", {"width": 390, "height": 844})
    return 0


if __name__ == "__main__":
    sys.exit(main())
