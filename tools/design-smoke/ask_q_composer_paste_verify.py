#!/usr/bin/env python3
"""Playwright: Ask Q large-paste composer — chip + send (Phase 3)."""
from __future__ import annotations

import json
import os
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import uuid
from pathlib import Path

import urllib.error
import urllib.request

OUT = Path(__file__).resolve().parent / "output"
MOBILE = (390, 844)
DESKTOP = (1280, 800)
LARGE_PASTE = "x" * 900  # above 800-char paste threshold


def free_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.bind(("127.0.0.1", 0))
        return int(s.getsockname()[1])


def login_admin(page, base: str) -> None:
    page.goto(f"{base}/admin/login.php", wait_until="domcontentloaded", timeout=60000)
    if page.locator("#sanctum-chat-bubble").count():
        return
    page.wait_for_selector('input[name="username"]', timeout=60000)
    page.locator('input[name="username"]').fill("admin")
    page.locator('input[type="password"][name="password"]').fill("AdminPass123456!")
    page.locator('form button[type="submit"]').click()
    page.wait_for_load_state("networkidle", timeout=30000)
    if "login.php" in page.url:
        raise RuntimeError("login failed")


def open_chat(page) -> None:
    bubble = page.locator("#sanctum-chat-bubble")
    bubble.wait_for(state="visible", timeout=10000)
    bubble.click()
    page.locator("#sanctum-chat-window").wait_for(state="visible", timeout=5000)


def test_large_paste(page, base: str, label: str) -> None:
    login_admin(page, base)
    open_chat(page)

    caption = f"Paste test {uuid.uuid4().hex[:6]}"
    inp = page.locator("#sanctum-chat-input")
    inp.fill(caption)

    page.evaluate(
        """([text]) => {
            const el = document.getElementById('sanctum-chat-input');
            const dt = new DataTransfer();
            dt.setData('text/plain', text);
            el.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true }));
        }""",
        [LARGE_PASTE],
    )

    chips = page.locator(".sanctum-composer-chip")
    chips.wait_for(state="visible", timeout=5000)
    if chips.count() < 1:
        raise RuntimeError("expected composer chip after large paste")

    page.locator("#sanctum-chat-send").click()
    page.wait_for_function(
        f"() => document.querySelector('#sanctum-chat-messages')?.innerText?.includes({json.dumps(caption)})",
        timeout=15000,
    )
    attach_row = page.locator(".sanctum-composer-bubble-attach")
    if attach_row.count() < 1:
        raise RuntimeError("expected attachment row in sent bubble")

    page.screenshot(path=str(OUT / f"ask_q_composer_paste_{label}.png"), full_page=False)
    print(f"OK large-paste composer ({label})")


def main() -> int:
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        print("playwright not installed", file=sys.stderr)
        return 1

    OUT.mkdir(parents=True, exist_ok=True)
    repo = Path(__file__).resolve().parents[2]
    public = repo / "public"
    php = shutil.which("php")
    if not php:
        return 1

    tmp = Path(tempfile.mkdtemp(prefix="askq-composer-"))
    env = os.environ.copy()
    env.update({
        "TASKS_DB_PATH": str(tmp / "tasks.db"),
        "TASKS_Q_BRIDGE_DB_PATH": str(tmp / "q_bridge_webchat.db"),
        "TASKS_BOOTSTRAP_ADMIN_USERNAME": "admin",
        "TASKS_BOOTSTRAP_ADMIN_PASSWORD": "AdminPass123456!",
        "TASKS_BOOTSTRAP_API_KEY": "a" * 64,
        "TASKS_PASSWORD_COST": "8",
        "TASKS_SESSION_COOKIE_SECURE": "0",
        "TASKS_Q_BRIDGE_ENABLED": "1",
        "TASKS_Q_BRIDGE_KEY_SECRET": "testsecret123456789012345678901234567890",
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
    time.sleep(0.5)

    try:
        urllib.request.urlopen(f"{base}/api/health.php", timeout=120)
    except urllib.error.HTTPError as e:
        if e.code not in (200, 401):
            return 1
    except (urllib.error.URLError, TimeoutError, OSError):
        return 1

    try:
        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            for vp, label in ((MOBILE, "mobile"), (DESKTOP, "desktop")):
                ctx = browser.new_context(viewport={"width": vp[0], "height": vp[1]})
                page = ctx.new_page()
                test_large_paste(page, base, label)
                ctx.close()
            browser.close()
    except Exception as exc:
        print(f"FAIL: {exc}", file=sys.stderr)
        return 1
    finally:
        proc.terminate()
        proc.wait(timeout=5)
        shutil.rmtree(tmp, ignore_errors=True)

    return 0


if __name__ == "__main__":
    sys.exit(main())
