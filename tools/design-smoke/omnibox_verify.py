#!/usr/bin/env python3
"""Playwright W17: navbar omnibox — Ctrl+K, live dropdown, Esc, navigate.

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


def seed_searchable(base: str, needle: str) -> int:
    created = api_post(
        base,
        "/api/create-directory-project.php",
        {"name": f"OmniBoard {needle}", "all_access": True},
    )
    data = created.get("data") or created
    project = data.get("project") or data
    project_id = int(project.get("id") or created.get("id") or 0)
    if project_id <= 0:
        raise RuntimeError(f"create project failed: {created}")

    lists = api_get(base, f"/api/list-todo-lists.php?project_id={project_id}")
    lists_data = lists.get("data") or lists
    todo_lists = lists_data.get("todo_lists") or []
    list_id = int(todo_lists[0]["id"]) if todo_lists else 0
    if list_id <= 0:
        raise RuntimeError(f"no todo list: {lists}")

    task = api_post(
        base,
        "/api/create-task.php",
        {
            "title": f"Task {needle} alpha",
            "body": "omnibox seed",
            "status": "todo",
            "project_id": project_id,
            "list_id": list_id,
        },
    )
    task_data = task.get("data") or task
    task_row = task_data.get("task") or task_data
    task_id = int(task_row.get("id") or 0)
    if task_id <= 0:
        raise RuntimeError(f"create task failed: {task}")

    api_post(
        base,
        "/api/create-document.php",
        {
            "project_id": project_id,
            "title": f"Doc {needle}",
            "body": "doc seed",
        },
    )
    return task_id


def run_omnibox_flow(page, base: str, needle: str, task_id: int, shot_name: str) -> None:
    login_admin(page, base)
    page.goto(f"{base}/admin/", wait_until="networkidle")
    # Toggle lives in the collapse menu on mobile — attached is enough here.
    page.wait_for_selector("#st-omnibox-toggle", state="attached", timeout=15000)
    # Collapsed by default
    assert page.locator("#st-searchbar.is-open").count() == 0

    # Esc closes: open bar + dropdown first (Ctrl+K works without opening hamburger)
    page.keyboard.press("Control+k")
    page.wait_for_selector("#st-searchbar.is-open #st-omnibox-input", timeout=10000)
    page.locator("#st-omnibox-input").fill(needle)
    page.wait_for_selector("#st-omnibox-drop:not([hidden])", timeout=10000)
    page.wait_for_selector(".st-omnibox__group", timeout=10000)
    assert page.locator(".st-omnibox__group", has_text="Tasks").count() >= 1
    page.keyboard.press("Escape")
    # Prefer pressing on the input so the omnibox keydown handler runs.
    page.locator("#st-omnibox-input").press("Escape")
    page.wait_for_function(
        "() => document.getElementById('st-omnibox-drop')?.hasAttribute('hidden') === true",
        timeout=8000,
    )
    # Second Esc collapses the search bar
    page.locator("#st-omnibox-input").press("Escape")
    page.wait_for_function(
        "() => !document.getElementById('st-searchbar')?.classList.contains('is-open')",
        timeout=8000,
    )

    # Re-open via Search nav control (desktop) or hamburger + Search (mobile)
    viewport = page.viewport_size or {"width": 1280}
    if viewport.get("width", 1280) < 992:
        page.locator(".navbar-toggler").click()
        page.wait_for_selector("#st-omnibox-toggle", state="visible", timeout=10000)
    page.locator("#st-omnibox-toggle").click()
    page.wait_for_selector("#st-searchbar.is-open #st-omnibox-input", timeout=10000)
    page.locator("#st-omnibox-input").fill("")
    page.locator("#st-omnibox-input").fill(needle)
    page.wait_for_selector(".st-omnibox__item.is-active", timeout=10000)
    first_href = page.locator(".st-omnibox__item").first.get_attribute("href") or ""
    assert f"view.php?id={task_id}" in first_href, first_href
    page.keyboard.press("ArrowDown")
    page.wait_for_timeout(100)
    assert page.locator(".st-omnibox__item.is-active").count() == 1
    # Move back to first (task) and Enter
    page.keyboard.press("ArrowUp")
    page.keyboard.press("Enter")
    page.wait_for_url(f"**/admin/view.php?id={task_id}", timeout=15000)

    # Screenshot with dropdown open on home
    page.goto(f"{base}/admin/", wait_until="networkidle")
    page.keyboard.press("Control+k")
    page.locator("#st-omnibox-input").fill(needle)
    page.wait_for_selector("#st-omnibox-drop:not([hidden]) .st-omnibox__item", timeout=10000)
    page.screenshot(path=str(OUT / shot_name), full_page=False)

    # S1.4 — Enter with no active nav (blur dropdown by submitting form via Enter on empty active
    # when footer "all results" path): navigate via form action
    page.goto(f"{base}/admin/search.php?q={needle}", wait_until="networkidle")
    page.wait_for_selector(".st-search-group", timeout=10000)
    assert page.locator('.st-search-group[data-group="tasks"]').count() >= 1
    assert page.locator('.st-search-group[data-group="documents"]').count() >= 1
    page.screenshot(path=str(OUT / shot_name.replace("omnibox_", "search_results_")), full_page=True)


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
        print("php not found", file=sys.stderr)
        return 1

    tmp = Path(tempfile.mkdtemp(prefix="omnibox-"))
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

        needle = "omniz" + os.urandom(3).hex()
        task_id = seed_searchable(base, needle)

        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            try:
                page = browser.new_page(viewport={"width": DESKTOP[0], "height": DESKTOP[1]})
                run_omnibox_flow(page, base, needle, task_id, "omnibox_desktop.png")
                page.close()

                page = browser.new_page(viewport={"width": MOBILE[0], "height": MOBILE[1]})
                run_omnibox_flow(page, base, needle, task_id, "omnibox_mobile.png")
                page.close()
            finally:
                browser.close()

        print(OUT / "omnibox_desktop.png")
        print(OUT / "omnibox_mobile.png")
        print(OUT / "search_results_desktop.png")
        print(OUT / "search_results_mobile.png")
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
