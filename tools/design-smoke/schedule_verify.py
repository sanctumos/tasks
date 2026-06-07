#!/usr/bin/env python3
"""Playwright: Schedule page renders (desktop + mobile)."""
from __future__ import annotations

import os
import shutil
import socket
import sqlite3
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path

OUT = Path(__file__).resolve().parent / "output"
DESKTOP = (1280, 800)
MOBILE = (390, 844)


def free_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.bind(("127.0.0.1", 0))
        return int(s.getsockname()[1])


def login_admin(page, base: str) -> None:
    page.goto(f"{base}/admin/login.php", wait_until="networkidle")
    page.locator('input[name="username"]').fill("admin")
    page.locator('input[name="password"]').fill("AdminPass123456!")
    page.get_by_role("button", name="Sign in").click()
    page.wait_for_load_state("networkidle", timeout=30000)
    if "login.php" in page.url:
        raise RuntimeError(f"login failed: {page.url}")


def main() -> int:
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        print("playwright not installed", file=sys.stderr)
        return 1

    repo = Path(__file__).resolve().parents[2]
    public = repo / "public"
    php = shutil.which("php")
    if not php:
        return 1

    tmp = Path(tempfile.mkdtemp(prefix="sched-"))
    db = tmp / "tasks.db"
    env = os.environ.copy()
    env.update({
        "TASKS_DB_PATH": str(db),
        "TASKS_BOOTSTRAP_ADMIN_USERNAME": "admin",
        "TASKS_BOOTSTRAP_ADMIN_PASSWORD": "AdminPass123456!",
        "TASKS_BOOTSTRAP_API_KEY": "a" * 64,
        "TASKS_PASSWORD_COST": "8",
        "TASKS_SESSION_COOKIE_SECURE": "0",
    })

    port = free_port()
    base = f"http://127.0.0.1:{port}"
    proc = subprocess.Popen(
        [php, "-S", f"127.0.0.1:{port}", "-t", str(public)],
        cwd=str(public),
        env=env,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    OUT.mkdir(parents=True, exist_ok=True)
    ready = False
    for _ in range(90):
        try:
            with urllib.request.urlopen(f"{base}/api/health.php", timeout=3) as r:
                if r.status in (200, 401):
                    ready = True
                    break
        except urllib.error.HTTPError as e:
            if e.code in (200, 401):
                ready = True
                break
        except (urllib.error.URLError, TimeoutError, OSError):
            pass
        time.sleep(0.5)
    if not ready:
        print("server timeout", file=sys.stderr)
        return 1

    with sqlite3.connect(db) as conn:
        conn.execute("UPDATE users SET must_change_password = 0 WHERE lower(username) = lower('admin')")
        conn.commit()

    try:
        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            for label, size in (("desktop", DESKTOP), ("mobile", MOBILE)):
                ctx = browser.new_context(viewport={"width": size[0], "height": size[1]})
                page = ctx.new_page()
                login_admin(page, base)
                page.goto(f"{base}/admin/schedule.php", wait_until="networkidle")
                page.wait_for_selector("h1", timeout=15000)
                h1 = page.locator("h1").first.inner_text()
                if "Schedule" not in h1:
                    print(f"FAIL: expected Schedule h1, got {h1!r} url={page.url}", file=sys.stderr)
                    return 1
                page.screenshot(path=str(OUT / f"schedule_{label}.png"), full_page=True)
                ctx.close()
            browser.close()
        print(f"OK — screenshots in {OUT}")
        return 0
    finally:
        proc.terminate()
        proc.wait(timeout=5)
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    raise SystemExit(main())
