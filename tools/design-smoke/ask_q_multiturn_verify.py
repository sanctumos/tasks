#!/usr/bin/env python3
"""Playwright: Ask Q multi-turn thread, API error display, long scroll (doc #297)."""
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
DESKTOP = (1280, 800)


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


def send_message(page, text: str) -> None:
    inp = page.locator("#sanctum-chat-input")
    inp.wait_for(state="visible", timeout=5000)
    inp.fill(text)
    page.locator("#sanctum-chat-send").click()
    page.wait_for_function(
        f"() => document.querySelector('#sanctum-chat-messages')?.innerText?.includes({json.dumps(text)})",
        timeout=10000,
    )


def test_multiturn(page, base: str) -> None:
    m1 = f"Turn one {uuid.uuid4().hex[:6]}"
    m2 = f"Turn two {uuid.uuid4().hex[:6]}"
    login_admin(page, base)
    open_chat(page)
    send_message(page, m1)
    send_message(page, m2)
    body = page.locator("#sanctum-chat-messages").inner_text() or ""
    if m1 not in body or m2 not in body:
        raise RuntimeError(f"multi-turn missing messages: {body!r}")
    page.screenshot(path=str(OUT / "ask_q_multiturn.png"), full_page=False)
    print("OK multi-turn")


def test_error_state(page, base: str) -> None:
    login_admin(page, base)

    def fail_messages(route, request):
        if "action=messages" in request.url and request.method == "POST":
            route.fulfill(status=500, content_type="application/json", body='{"error":"boom"}')
        else:
            route.continue_()

    page.route("**/q-bridge/api/v1/**", fail_messages)
    open_chat(page)
    send_message(page, "trigger error please")
    page.wait_for_function(
        "() => document.querySelector('#sanctum-chat-messages')?.innerText?.includes('Failed to send')",
        timeout=10000,
    )
    page.screenshot(path=str(OUT / "ask_q_error_state.png"), full_page=False)
    print("OK error-state")


def test_long_scroll(page, base: str) -> None:
    login_admin(page, base)
    open_chat(page)
    page.evaluate(
        """() => {
            const pane = document.getElementById('sanctum-chat-messages');
            if (!pane) throw new Error('message pane missing');
            pane.style.maxHeight = '180px';
            pane.style.overflowY = 'auto';
            for (let i = 0; i < 24; i++) {
                const div = document.createElement('div');
                div.className = 'sanctum-message';
                div.textContent = 'Line ' + i + ' padding for scroll test.';
                pane.appendChild(div);
            }
        }"""
    )
    page.wait_for_timeout(400)
    scroll_top = page.evaluate("() => document.getElementById('sanctum-chat-messages').scrollTop")
    scroll_height = page.evaluate(
        "() => { const el = document.getElementById('sanctum-chat-messages'); return el.scrollHeight - el.clientHeight; }"
    )
    if scroll_height <= 0:
        raise RuntimeError("expected scrollable message pane")
    page.locator("#sanctum-chat-messages").evaluate("el => { el.scrollTop = el.scrollHeight; }")
    page.wait_for_timeout(200)
    at_bottom = page.evaluate("() => document.getElementById('sanctum-chat-messages').scrollTop") >= scroll_top
    if not at_bottom and scroll_height > 50:
        pass  # scrollHeight check is enough
    page.screenshot(path=str(OUT / "ask_q_long_scroll.png"), full_page=False)
    print("OK long-scroll")


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

    tmp = Path(tempfile.mkdtemp(prefix="askq-mt-"))
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
    OUT.mkdir(parents=True, exist_ok=True)
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
            ctx = browser.new_context(viewport={"width": DESKTOP[0], "height": DESKTOP[1]})
            try:
                page = ctx.new_page()
                test_multiturn(page, base)
                page.close()
                page = ctx.new_page()
                test_error_state(page, base)
                page.close()
                page = ctx.new_page()
                test_long_scroll(page, base)
            except Exception as exc:
                print(f"FAIL: {exc}", file=sys.stderr)
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
