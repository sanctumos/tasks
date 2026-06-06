#!/usr/bin/env python3
"""Playwright: Ask Q conversation survives page reload (doc #297 gap)."""
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
DESKTOP = (1440, 900)
MOBILE = (390, 844)


def free_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.bind(("127.0.0.1", 0))
        return int(s.getsockname()[1])


def login_admin(page, base: str) -> None:
    page.goto(f"{base}/admin/login.php", wait_until="networkidle")
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


def send_user_message(page, text: str) -> None:
    inp = page.locator("#sanctum-chat-input")
    inp.wait_for(state="visible", timeout=5000)
    inp.fill(text)
    page.locator("#sanctum-chat-send").click()
    page.wait_for_function(
        f"() => document.querySelector('#sanctum-chat-messages')?.innerText?.includes({json.dumps(text)})",
        timeout=10000,
    )


def assert_message_persists(page, text: str, label: str) -> None:
    messages = page.locator("#sanctum-chat-messages")
    messages.wait_for(state="visible", timeout=5000)
    body = messages.inner_text() or ""
    if text not in body:
        raise RuntimeError(f"reload lost message on {label}: {body!r}")


def run_viewport(page, base: str, label: str, size: tuple[int, int]) -> None:
    marker = f"Otto reload persist {uuid.uuid4().hex[:8]}"
    page.set_viewport_size({"width": size[0], "height": size[1]})
    login_admin(page, base)
    page.wait_for_timeout(600)
    open_chat(page)
    send_user_message(page, marker)
    page.screenshot(path=str(OUT / f"ask_q_reload_{label}_before.png"), full_page=False)
    page.reload(wait_until="networkidle")
    page.wait_for_timeout(800)
    open_chat(page)
    page.wait_for_timeout(1200)
    assert_message_persists(page, marker, label)
    page.screenshot(path=str(OUT / f"ask_q_reload_{label}_after.png"), full_page=False)
    print(f"OK reload persist {label}")


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

    tmp = Path(tempfile.mkdtemp(prefix="askq-reload-"))
    tasks_db = tmp / "tasks.db"
    bridge_db = tmp / "q_bridge_webchat.db"
    env = os.environ.copy()
    env.update({
        "TASKS_DB_PATH": str(tasks_db),
        "TASKS_Q_BRIDGE_DB_PATH": str(bridge_db),
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
    OUT.mkdir(parents=True, exist_ok=True)
    time.sleep(0.5)

    try:
        urllib.request.urlopen(f"{base}/api/health.php", timeout=120)
    except urllib.error.HTTPError as e:
        if e.code not in (200, 401):
            print(f"warmup failed HTTP {e.code}", file=sys.stderr)
            return 1
    except (urllib.error.URLError, TimeoutError, OSError) as e:
        print(f"warmup failed: {e}", file=sys.stderr)
        return 1

    try:
        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            for label, size in (("desktop", DESKTOP), ("mobile", MOBILE)):
                ctx = browser.new_context(viewport={"width": size[0], "height": size[1]})
                page = ctx.new_page()
                try:
                    run_viewport(page, base, label, size)
                except Exception as exc:
                    print(f"FAIL {label}: {exc}", file=sys.stderr)
                    return 1
                finally:
                    ctx.close()
            browser.close()
        return 0
    finally:
        proc.terminate()
        proc.wait(timeout=5)
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    sys.exit(main())
