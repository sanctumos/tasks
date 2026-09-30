#!/usr/bin/env python3
"""Playwright W20: project Tasks tab board-local search (stFilter + URL state).

Ephemeral PHP server; screenshots desktop 1280 + mobile 390.
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
    page.goto(f"{base}/admin/login.php", wait_until="load", timeout=60000)
    page.locator('input[name="username"]').fill(ADMIN_USER)
    page.locator('input[name="password"]').fill(ADMIN_PASS)
    page.get_by_role("button", name="Sign in").click()
    page.wait_for_load_state("load", timeout=30000)
    if "login.php" in page.url:
        raise RuntimeError(f"login failed: {page.url}")


def seed_board(base: str, needle: str) -> int:
    created = api_post(
        base,
        "/api/create-directory-project.php",
        {"name": f"BoardSearch {needle}", "all_access": True},
    )
    data = created.get("data") or created
    project = data.get("project") or data
    project_id = int(project.get("id") or 0)
    lists = api_get(base, f"/api/list-todo-lists.php?project_id={project_id}")
    lists_data = lists.get("data") or lists
    todo_lists = lists_data.get("todo_lists") or []
    list_id = int(todo_lists[0]["id"]) if todo_lists else 0
    api_post(
        base,
        "/api/create-task.php",
        {
            "title": f"Match {needle} alpha",
            "status": "todo",
            "priority": "high",
            "project_id": project_id,
            "list_id": list_id,
        },
    )
    api_post(
        base,
        "/api/create-task.php",
        {
            "title": f"Other zzz{needle[-4:]}",
            "status": "todo",
            "priority": "normal",
            "project_id": project_id,
            "list_id": list_id,
        },
    )
    return project_id


def run_board_search(page, base: str, project_id: int, needle: str, shot: str) -> None:
    login_admin(page, base)
    page.goto(f"{base}/admin/project.php?id={project_id}&tab=tasks", wait_until="load", timeout=60000)
    page.wait_for_selector("#st-board-filter", timeout=15000)
    use_client = page.locator("#st-board-filter").get_attribute("data-st-use-client")
    if use_client != "1":
        raise RuntimeError("expected client filter on small board")

    page.evaluate(
        """() => { window.__stBoardNav = 0; window.addEventListener('beforeunload', () => { window.__stBoardNav++; }); }"""
    )
    page.locator("#st-board-filter-q").fill(needle)
    page.wait_for_function(
        f"""() => {{
            const u = new URL(window.location.href);
            return (u.searchParams.get('q') || '').includes({json.dumps(needle)});
        }}""",
        timeout=10000,
    )
    page.wait_for_function(
        """() => {
            const el = document.getElementById('st-board-filter-count');
            return el && /\\b1\\b/.test(el.textContent || '');
        }""",
        timeout=10000,
    )
    if page.evaluate("() => window.__stBoardNav || 0") != 0:
        raise RuntimeError("full navigation during live board filter")

    html = page.content()
    if f"Match {needle}" not in html:
        raise RuntimeError("match missing")
    # Other task should be hidden
    other_visible = page.locator("#st-board-results .task-card:not([hidden])", has_text="Other zzz").count()
    if other_visible > 0:
        raise RuntimeError("non-matching card still visible")

    page.locator('[data-st-chip="high"]').click()
    page.wait_for_function(
        """() => {
            const u = new URL(window.location.href);
            return u.searchParams.getAll('chip').includes('high');
        }""",
        timeout=10000,
    )

    page.screenshot(path=str(OUT / shot), full_page=False)

    page.reload(wait_until="load", timeout=60000)
    page.wait_for_selector("#st-board-filter-q", timeout=15000)
    qs = parse_qs(urlparse(page.url).query)
    if needle not in (qs.get("q") or [""])[0]:
        raise RuntimeError(f"q not persisted: {page.url}")

    page.locator("a.st-board-filter-clear").click()
    page.wait_for_function(
        """() => {
            const u = new URL(window.location.href);
            return !u.searchParams.get('q');
        }""",
        timeout=10000,
    )


def main() -> int:
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        print("playwright not installed", file=sys.stderr)
        return 1

    repo = Path(__file__).resolve().parents[2]
    public = repo / "public"
    php = shutil.which("php") or "php"
    tmp = Path(tempfile.mkdtemp(prefix="st-board-search-"))
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

        con = sqlite3.connect(db)
        con.execute(
            "UPDATE users SET must_change_password = 0 WHERE username = ?",
            (ADMIN_USER,),
        )
        con.commit()
        con.close()

        needle = "bdsr" + os.urandom(3).hex()
        project_id = seed_board(base, needle)

        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            try:
                page = browser.new_page(viewport={"width": DESKTOP[0], "height": DESKTOP[1]})
                run_board_search(page, base, project_id, needle, "project_board_search_desktop.png")
                page.close()
                page = browser.new_page(viewport={"width": MOBILE[0], "height": MOBILE[1]})
                run_board_search(page, base, project_id, needle, "project_board_search_mobile.png")
                page.close()
            finally:
                browser.close()

        print(OUT / "project_board_search_desktop.png")
        print(OUT / "project_board_search_mobile.png")
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
