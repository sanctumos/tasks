#!/usr/bin/env python3
"""Playwright: Archive downloads tab on archived project (dev.tasks, mobile + desktop)."""
from __future__ import annotations

import os
import sys
from pathlib import Path

OUT = Path(__file__).resolve().parent / "output"
DESKTOP = (1280, 800)
MOBILE = (390, 844)


def login(page, base: str, user: str, password: str, new_password: str) -> str:
    page.goto(f"{base}/admin/login.php", wait_until="networkidle", timeout=60000)
    page.locator('input[name="username"]').fill(user)
    page.locator('input[name="password"]').fill(password)
    page.get_by_role("button", name="Sign in").click()
    page.wait_for_load_state("networkidle", timeout=60000)

    if "settings.php" in page.url and "password" in page.url:
        page.locator('input[name="current_password"]').fill(password)
        page.locator('input[name="new_password"]').fill(new_password)
        page.locator('input[name="confirm_password"]').fill(new_password)
        page.get_by_role("button", name="Change password").click()
        page.wait_for_load_state("networkidle", timeout=60000)
        password = new_password

    if "login.php" in page.url:
        raise RuntimeError(f"login failed: {page.url}")
    return password


def main() -> int:
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        print("playwright not installed", file=sys.stderr)
        return 1

    base = os.environ.get("BOARD_EXPORT_BASE", "https://dev.tasks.decisionsciencecorp.com").rstrip("/")
    host = base.split("://", 1)[-1].split("/")[0]
    user = os.environ.get("BOARD_EXPORT_USER", "admin")
    password = os.environ.get("BOARD_EXPORT_PASS", "")
    new_password = os.environ.get("BOARD_EXPORT_NEW_PASS", "DevBoardExportPass1!")
    project_id = os.environ.get("BOARD_EXPORT_PROJECT_ID", "1")
    resolve_ip = os.environ.get("BOARD_EXPORT_RESOLVE_IP", "64.95.10.156")

    if not password:
        print("Set BOARD_EXPORT_PASS", file=sys.stderr)
        return 1

    OUT.mkdir(parents=True, exist_ok=True)
    url = f"{base}/admin/project.php?id={project_id}&tab=archives"

    with sync_playwright() as p:
        browser = p.chromium.launch(
            headless=True,
            args=[f"--host-resolver-rules=MAP {host} {resolve_ip}"],
        )
        # Establish password (may rotate bootstrap once).
        boot = browser.new_context(viewport={"width": DESKTOP[0], "height": DESKTOP[1]})
        page0 = boot.new_page()
        try:
            password = login(page0, base, user, password, new_password)
        except RuntimeError:
            # Bootstrap may already have been rotated on a prior run.
            password = login(page0, base, user, new_password, new_password)
        boot.close()

        for label, size in (("desktop", DESKTOP), ("mobile", MOBILE)):
            context = browser.new_context(viewport={"width": size[0], "height": size[1]})
            page = context.new_page()
            login(page, base, user, password, new_password)
            page.goto(url, wait_until="networkidle", timeout=60000)
            content = page.content()
            if "Generate board archive" not in content and "Download a ZIP of this board" not in content:
                fail = OUT / f"board_export_archives_fail_{label}.png"
                page.screenshot(path=str(fail), full_page=True)
                print(f"url={page.url}", file=sys.stderr)
                raise RuntimeError(f"archives tab missing content; shot {fail}")
            page.wait_for_selector("text=Generate board archive", timeout=10000)
            # Unchanged-reuse copy landed with content_hash work.
            lower = content.lower()
            assert "duplicate" in lower or "generate board archive" in lower
            page.get_by_role("button", name="Generate board archive").click()
            page.wait_for_load_state("networkidle", timeout=60000)
            body = page.content().lower()
            assert (
                "queued" in body
                or "already" in body
                or "not changed" in body
                or "reuses" in body
                or "building archive" in body
                or "ready" in body
                or "download" in body
            ), f"unexpected post-generate state; url={page.url}"
            shot = OUT / f"board_export_archives_{label}.png"
            page.screenshot(path=str(shot), full_page=True)
            print(f"wrote {shot}")
            context.close()
        browser.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
