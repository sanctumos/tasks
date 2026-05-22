#!/usr/bin/env python3
"""Playwright: Ask Q bubble on admin home (desktop + mobile)."""
from __future__ import annotations

import os
import shutil
import socket
import subprocess
import sys
import tempfile
import time
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

    tmp = Path(tempfile.mkdtemp(prefix="askq-"))
    db = tmp / "tasks.db"
    env = os.environ.copy()
    env.update({
        "TASKS_DB_PATH": str(db),
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
    # Warm PHP (schema bootstrap can take 30–90s on cold start).
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
        for _ in range(30):
            try:
                with urllib.request.urlopen(f"{base}/api/health.php", timeout=2) as r:
                    if r.status in (200, 401):
                        break
            except urllib.error.HTTPError as e:
                if e.code in (200, 401):
                    break
            except (urllib.error.URLError, TimeoutError, OSError):
                pass
            time.sleep(0.2)
        else:
            print("server timeout", file=sys.stderr)
            return 1

        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            for label, size in (("desktop", DESKTOP), ("mobile", MOBILE)):
                ctx = browser.new_context(viewport={"width": size[0], "height": size[1]})
                page = ctx.new_page()
                page.goto(f"{base}/admin/login.php", wait_until="networkidle")
                page.locator('input[name="username"]').fill("admin")
                page.locator('input[type="password"][name="password"]').fill("AdminPass123456!")
                page.locator('form button[type="submit"]').click()
                page.wait_for_load_state("networkidle", timeout=30000)
                if "login.php" in page.url:
                    print(f"FAIL: still on login ({label})", file=sys.stderr)
                    return 1
                page.wait_for_timeout(800)
                bubble = page.locator("#sanctum-chat-bubble")
                page.wait_for_function(
                    "() => typeof window.SanctumMarkdownLite !== 'undefined'",
                    timeout=10000,
                )
                md = page.evaluate(
                    "() => window.SanctumMarkdownLite.toHtml('**bold** and `code`\\n\\n- one')"
                )
                if "<strong>bold</strong>" not in md or "<code>code</code>" not in md:
                    print(f"FAIL: markdown render {md!r} ({label})", file=sys.stderr)
                    return 1
                bubble.wait_for(state="visible", timeout=10000)
                out = OUT / f"ask_q_{label}.png"
                page.screenshot(path=str(out), full_page=False)
                box_closed = bubble.bounding_box()
                if not box_closed:
                    print(f"FAIL: no bubble box ({label})", file=sys.stderr)
                    return 1
                margin = 80
                if box_closed["y"] + box_closed["height"] < size[1] - margin:
                    print(
                        f"FAIL: bubble not bottom-anchored when closed "
                        f"y={box_closed['y']:.0f} h={size[1]} ({label})",
                        file=sys.stderr,
                    )
                    return 1
                bubble.click()
                page.wait_for_timeout(500)
                win = page.locator("#sanctum-chat-window")
                win.wait_for(state="visible", timeout=5000)
                box_open = bubble.bounding_box()
                if not box_open:
                    print(f"FAIL: no bubble box when open ({label})", file=sys.stderr)
                    return 1
                if abs(box_open["y"] - box_closed["y"]) > 8:
                    print(
                        f"FAIL: bubble moved when panel opened "
                        f"closed_y={box_closed['y']:.0f} open_y={box_open['y']:.0f} ({label})",
                        file=sys.stderr,
                    )
                    return 1
                if box_open["y"] + box_open["height"] < size[1] - margin:
                    print(
                        f"FAIL: bubble not bottom-anchored when open "
                        f"y={box_open['y']:.0f} ({label})",
                        file=sys.stderr,
                    )
                    return 1
                title = page.locator("#sanctum-chat-title")
                title_text = title.inner_text() or ""
                chatter = page.locator("#sanctum-chat-chatter")
                chatter_text = chatter.inner_text() or ""
                page.screenshot(path=str(OUT / f"ask_q_{label}_open.png"), full_page=False)
                if "You: admin" not in chatter_text:
                    print(f"FAIL: chatter label={chatter_text!r} ({label})", file=sys.stderr)
                    return 1
                if "Q" not in title_text and "Vernal" not in title_text:
                    print(f"FAIL: title={title_text!r} on {label}", file=sys.stderr)
                    return 1
                ctx.close()
                print(f"OK {out}")
            browser.close()
        return 0
    finally:
        proc.terminate()
        proc.wait(timeout=5)
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    sys.exit(main())
