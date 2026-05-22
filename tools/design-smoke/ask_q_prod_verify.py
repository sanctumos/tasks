#!/usr/bin/env python3
"""Playwright: Ask Q on production Tasks admin (desktop + mobile)."""
from __future__ import annotations

import os
import sys
from pathlib import Path

OUT = Path(__file__).resolve().parent / "output"
BASE = os.getenv("TASKS_VERIFY_BASE", "https://tasks.decisionsciencecorp.com").rstrip("/")
USER = os.getenv("TASKS_VERIFY_USER", "ottovernal")
PASSWORD = os.getenv("TASKS_VERIFY_PASSWORD", "")
DESKTOP = (1440, 900)
MOBILE = (390, 844)


def main() -> int:
    if not PASSWORD:
        print("TASKS_VERIFY_PASSWORD required", file=sys.stderr)
        return 1
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        print("playwright not installed", file=sys.stderr)
        return 1

    OUT.mkdir(parents=True, exist_ok=True)
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        for label, size in (("prod_desktop", DESKTOP), ("prod_mobile", MOBILE)):
            ctx = browser.new_context(viewport={"width": size[0], "height": size[1]})
            page = ctx.new_page()
            page.goto(f"{BASE}/admin/login.php", wait_until="domcontentloaded", timeout=60000)
            page.locator('input[name="username"]').fill(USER)
            page.locator('input[type="password"][name="password"]').fill(PASSWORD)
            page.locator('form button[type="submit"]').click()
            page.wait_for_load_state("networkidle", timeout=60000)
            if "login.php" in page.url:
                page.screenshot(path=str(OUT / f"ask_q_{label}_login_fail.png"))
                print(f"FAIL login {label}", file=sys.stderr)
                return 1
            page.wait_for_timeout(1000)
            bubble = page.locator("#sanctum-chat-bubble")
            try:
                bubble.wait_for(state="visible", timeout=15000)
            except Exception:
                page.screenshot(path=str(OUT / f"ask_q_{label}_no_bubble.png"), full_page=True)
                print(f"FAIL no bubble {label}", file=sys.stderr)
                return 1
            page.screenshot(path=str(OUT / f"ask_q_{label}.png"))
            bubble.click()
            page.wait_for_timeout(600)
            page.locator("#sanctum-chat-window").wait_for(state="visible", timeout=8000)
            title = page.locator("#sanctum-chat-title").inner_text() or ""
            page.screenshot(path=str(OUT / f"ask_q_{label}_open.png"))
            if "Q" not in title_text and "Vernal" not in title_text:
                print(f"FAIL title={title_text!r}", file=sys.stderr)
                return 1
            # Send a test message
            inp = page.locator("#sanctum-chat-input, .sanctum-chat-input, textarea").first
            inp.fill("Hi Q — Playwright smoke test. Reply with one short sentence.")
            page.locator("#sanctum-chat-send, button.sanctum-chat-send").first.click()
            page.wait_for_timeout(15000)
            page.screenshot(path=str(OUT / f"ask_q_{label}_after_send.png"))
            body = page.locator("#sanctum-chat-messages, .sanctum-chat-messages").first.inner_text()
            if "Playwright smoke" not in body and "smoke" not in body.lower():
                print(f"WARN: user message not visible in thread {label}", file=sys.stderr)
            ctx.close()
            print(f"OK {label} title={title_text!r}")
        browser.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
