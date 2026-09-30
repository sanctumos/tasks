#!/usr/bin/env python3
"""Playwright W19: Home live filter — debounced fragment swap, URL state, clear.

Starts an ephemeral PHP server (child process; killed in finally).
Screenshots: desktop 1280 + mobile 390 under tools/design-smoke/output/.
"""
from __future__ import annotations

import json
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
from urllib.parse import parse_qs, urlparse

OUT = Path(__file__).resolve().parent / "output"
DESKTOP = (1280, 800)
MOBILE = (390, 844)
API_KEY = "a" * 64
ADMIN_USER = "admin"
ADMIN_PASS = "AdminPass123456!"


def free_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.bind(("127.0.0.1", 0))
        return int(s.getsockname()[1])


def api_post(base: str, path: str, payload: dict) -> dict:
    req = urllib.request.Request(
        f"{base}{path}",
        data=json.dumps(payload).encode(),
        headers={"Content-Type": "application/json", "X-API-Key": API_KEY},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read().decode())


def api_get(base: str, path: str) -> dict:
    req = urllib.request.Request(
        f"{base}{path}",
        headers={"X-API-Key": API_KEY},
        method="GET",
    )
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read().decode())


def login_admin(page, base: str) -> None:
    page.goto(f"{base}/admin/login.php", wait_until="networkidle")
    page.locator('input[name="username"]').fill(ADMIN_USER)
    page.locator('input[name="password"]').fill(ADMIN_PASS)
    page.get_by_role("button", name="Sign in").click()
    page.wait_for_load_state("networkidle", timeout=30000)
    if "login.php" in page.url:
        raise RuntimeError(f"login failed: {page.url}")


def enable_home_board(db_path: Path) -> None:
    widgets = json.dumps({"cross_project_board": True})
    con = sqlite3.connect(db_path)
    con.execute(
        "UPDATE users SET must_change_password = 0, home_widgets_json = ? WHERE username = ?",
        (widgets, ADMIN_USER),
    )
    con.commit()
    con.close()


def seed_tasks(base: str, needle: str) -> None:
    created = api_post(
        base,
        "/api/create-directory-project.php",
        {"name": f"LiveBoard {needle}", "all_access": True},
    )
    data = created.get("data") or created
    project = data.get("project") or data
    project_id = int(project.get("id") or 0)
    lists = api_get(base, f"/api/list-todo-lists.php?project_id={project_id}")
    lists_data = lists.get("data") or lists
    todo_lists = lists_data.get("todo_lists") or []
    list_id = int(todo_lists[0]["id"]) if todo_lists else 0
    for title in (f"Match {needle} alpha", f"Other zzz{needle[-4:]}"):
        api_post(
            base,
            "/api/create-task.php",
            {
                "title": title,
                "body": "live filter seed",
                "status": "todo",
                "project_id": project_id,
                "list_id": list_id,
            },
        )


def run_live_filter_flow(page, base: str, needle: str, shot_name: str) -> None:
    login_admin(page, base)
    page.goto(f"{base}/admin/", wait_until="networkidle")
    page.wait_for_selector("#st-home-filter-form", timeout=15000)
    page.wait_for_selector("#st-home-results", timeout=15000)

    nav_token = page.evaluate("() => window.__stNavToken = Math.random().toString(36)")
    page.evaluate(
        """() => {
            window.__stHomeNavCount = 0;
            window.addEventListener('beforeunload', () => { window.__stHomeNavCount++; });
        }"""
    )

    q = page.locator('#st-home-filter-form input[name="q"]')
    q.fill(needle)
    page.wait_for_function(
        f"""() => {{
            const u = new URL(window.location.href);
            return (u.searchParams.get('q') || '').includes({json.dumps(needle)});
        }}""",
        timeout=10000,
    )
    page.wait_for_selector(f"#st-home-results >> text=Match {needle}", timeout=10000)
    page.wait_for_function(
        """() => {
            const el = document.querySelector('#st-home-results');
            return el && el.getAttribute('data-total-count') === '1';
        }""",
        timeout=10000,
    )

    # No full navigation
    nav_count = page.evaluate("() => window.__stHomeNavCount || 0")
    if nav_count != 0:
        raise RuntimeError(f"unexpected full navigation during live filter: {nav_count}")
    token_after = page.evaluate("() => window.__stNavToken")
    if token_after != nav_token:
        raise RuntimeError("page context was replaced (full reload)")

    qs = parse_qs(urlparse(page.url).query)
    if needle not in (qs.get("q") or [""])[0]:
        raise RuntimeError(f"URL missing q: {page.url}")

    count_text = page.locator(".st-home-live-count").inner_text()
    if "1" not in count_text:
        raise RuntimeError(f"count line unexpected: {count_text!r}")

    # Other task should not be in results
    if page.locator(f"#st-home-results >> text=Other zzz").count() > 0:
        raise RuntimeError("non-matching task still visible")

    page.screenshot(path=str(OUT / shot_name), full_page=False)

    page.locator("a.st-home-filter-clear").first.click()
    page.wait_for_function(
        """() => {
            const u = new URL(window.location.href);
            return !u.searchParams.get('q');
        }""",
        timeout=10000,
    )
    page.wait_for_function(
        """() => {
            const el = document.querySelector('#st-home-results');
            const n = parseInt(el && el.getAttribute('data-total-count') || '0', 10);
            return n >= 2;
        }""",
        timeout=10000,
    )
    page.wait_for_selector(f"#st-home-results >> text=Match {needle}", timeout=10000)


def run_cold_light_path(page, base: str, needle: str) -> None:
    """Board widget off: cold GET ?q= uses light path, not heavy hydration."""
    login_admin(page, base)
    page.goto(f"{base}/admin/?q={needle}", wait_until="load", timeout=60000)
    page.wait_for_selector("#st-home-results", timeout=15000)
    path = page.locator(".st-home-master").get_attribute("data-st-home-path")
    if path != "light":
        raise RuntimeError(f"expected light path, got {path!r}")
    html = page.content()
    if f"Match {needle}" not in html:
        raise RuntimeError("matching task missing from light-path HTML")
    total = page.locator("#st-home-results").get_attribute("data-total-count")
    if total != "1":
        raise RuntimeError(f"expected 1 match, data-total-count={total!r}")
    if "st-home-board-hydration" in html:
        raise RuntimeError("heavy board hydration present on light path")
    if page.locator("#newTaskModal").count() > 0:
        raise RuntimeError("new task modal should not mount on light path")


def main() -> int:
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        print("playwright not installed", file=sys.stderr)
        return 1

    repo = Path(__file__).resolve().parents[2]
    public = repo / "public"
    php = shutil.which("php") or "php"
    tmp = Path(tempfile.mkdtemp(prefix="st-home-live-"))
    db = tmp / "tasks.db"
    env = os.environ.copy()
    env.update(
        {
            "TASKS_DB_PATH": str(db),
            "TASKS_BOOTSTRAP_ADMIN_USERNAME": ADMIN_USER,
            "TASKS_BOOTSTRAP_ADMIN_PASSWORD": ADMIN_PASS,
            "TASKS_BOOTSTRAP_API_KEY": API_KEY,
            "TASKS_PASSWORD_COST": "8",
            "TASKS_SESSION_COOKIE_SECURE": "0",
        }
    )

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
    try:
        ready = False
        for _ in range(120):
            try:
                with urllib.request.urlopen(f"{base}/api/health.php", timeout=3) as r:
                    if r.status in (200, 401):
                        ready = True
                        break
            except urllib.error.HTTPError as e:
                if e.code in (200, 401):
                    ready = True
                    break
            except Exception:
                pass
            time.sleep(0.25)
        if not ready:
            print("server not ready", file=sys.stderr)
            return 1

        enable_home_board(db)
        needle = "livef" + os.urandom(3).hex()
        seed_tasks(base, needle)

        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            try:
                page = browser.new_page(viewport={"width": DESKTOP[0], "height": DESKTOP[1]})
                run_live_filter_flow(page, base, needle, "home_live_filter_desktop.png")
                page.close()

                page = browser.new_page(viewport={"width": MOBILE[0], "height": MOBILE[1]})
                run_live_filter_flow(page, base, needle, "home_live_filter_mobile.png")
                page.close()

                # S2.3 — cold filtered URL with board widget off
                con = sqlite3.connect(db)
                con.execute(
                    "UPDATE users SET home_widgets_json = ? WHERE username = ?",
                    (json.dumps({"cross_project_board": False}), ADMIN_USER),
                )
                con.commit()
                con.close()
                page = browser.new_page(viewport={"width": DESKTOP[0], "height": DESKTOP[1]})
                run_cold_light_path(page, base, needle)
                page.screenshot(path=str(OUT / "home_live_filter_light_cold.png"), full_page=False)
                page.close()
            finally:
                browser.close()

        print(OUT / "home_live_filter_desktop.png")
        print(OUT / "home_live_filter_mobile.png")
        print(OUT / "home_live_filter_light_cold.png")
        return 0
    finally:
        proc.terminate()
        try:
            proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            proc.kill()
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    raise SystemExit(main())
