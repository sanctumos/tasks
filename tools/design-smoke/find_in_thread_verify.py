#!/usr/bin/env python3
"""Playwright W21: task detail find-in-thread + attachment name filter (S4.1).

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
import urllib.request
from pathlib import Path

OUT = Path(__file__).resolve().parent / "output"
DESKTOP = (1280, 800)
MOBILE = (390, 844)
API_KEY = "a" * 64
ADMIN_USER = "admin"
ADMIN_PASS = "AdminPass123456!"
NEEDLE = "screenshot"


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


def seed_task(base: str) -> int:
    tag = os.urandom(3).hex()
    created = api_post(
        base,
        "/api/create-directory-project.php",
        {"name": f"FindThread {tag}", "all_access": True},
    )
    data = created.get("data") or created
    project = data.get("project") or data
    project_id = int(project.get("id") or 0)
    lists = api_get(base, f"/api/list-todo-lists.php?project_id={project_id}")
    lists_data = lists.get("data") or lists
    todo_lists = lists_data.get("todo_lists") or []
    list_id = int(todo_lists[0]["id"]) if todo_lists else 0
    task = api_post(
        base,
        "/api/create-task.php",
        {
            "title": f"Find-in-thread seed {tag}",
            "status": "doing",
            "priority": "normal",
            "project_id": project_id,
            "list_id": list_id,
        },
    )
    task_data = (task.get("data") or task).get("task") or {}
    task_id = int(task_data.get("id") or 0)
    for body in [
        "Shipped the pin buttons. Screenshots attached for desktop + mobile.",
        "Unrelated note about the schedule widget only.",
        "The mobile screenshot shows the chip wrapping — fix that before we ship.",
        "Fixed wrap, re-attached screenshots.",
    ]:
        api_post(base, "/api/create-comment.php", {"task_id": task_id, "comment": body})
    api_post(
        base,
        "/api/add-attachment.php",
        {
            "task_id": task_id,
            "file_name": "desktop-screenshot.png",
            "file_url": "https://example.com/desktop-screenshot.png",
            "mime_type": "image/png",
            "size_bytes": 12000,
        },
    )
    api_post(
        base,
        "/api/add-attachment.php",
        {
            "task_id": task_id,
            "file_name": "notes-export.txt",
            "file_url": "https://example.com/notes-export.txt",
            "mime_type": "text/plain",
            "size_bytes": 400,
        },
    )
    api_post(
        base,
        "/api/add-attachment.php",
        {
            "task_id": task_id,
            "file_name": "mobile-screenshot.png",
            "file_url": "https://example.com/mobile-screenshot.png",
            "mime_type": "image/png",
            "size_bytes": 9000,
        },
    )
    return task_id


def exercise_find(page, base: str, task_id: int, label: str) -> None:
    page.goto(f"{base}/admin/view.php?id={task_id}", wait_until="load", timeout=60000)
    page.wait_for_selector("#st-find-thread-q", timeout=15000)
    page.locator("#st-find-thread-q").fill(NEEDLE)
    page.wait_for_function(
        """() => {
            const marks = document.querySelectorAll('#st-comment-thread mark.st-hl');
            const count = document.getElementById('st-find-thread-count');
            return marks.length >= 3 && count && /\\d+\\/\\d+/.test(count.textContent || '');
        }""",
        timeout=10000,
    )
    count_text = page.locator("#st-find-thread-count").inner_text().strip()
    if not count_text or "/" not in count_text:
        raise RuntimeError(f"bad counter: {count_text!r}")
    _, total_s = count_text.split("/", 1)
    if int(total_s) < 3:
        raise RuntimeError(f"expected >=3 matches, got {count_text}")
    if page.locator(".comment-item.st-comment-hl").count() < 1:
        raise RuntimeError("active comment outline missing")
    page.locator("#st-find-thread-next").click()
    page.wait_for_function(
        """(prev) => {
            const t = (document.getElementById('st-find-thread-count') || {}).textContent || '';
            return t.trim() !== prev && /\\d+\\/\\d+/.test(t);
        }""",
        arg=count_text,
        timeout=5000,
    )
    page.locator("#st-find-thread-prev").click()
    page.wait_for_function(
        """(prev) => {
            const t = (document.getElementById('st-find-thread-count') || {}).textContent || '';
            return t.trim() === prev;
        }""",
        arg=count_text,
        timeout=5000,
    )

    page.locator("#st-find-attach-q").fill(NEEDLE)
    page.wait_for_function(
        """() => {
            const rows = [...document.querySelectorAll('#st-attachment-list [data-st-filter-item]')];
            const vis = rows.filter(r => !r.hidden && r.style.display !== 'none');
            return vis.length === 2 && vis.every(r => /screenshot/i.test(r.getAttribute('data-st-filter-text') || ''));
        }""",
        timeout=10000,
    )
    page.locator("#discussion").scroll_into_view_if_needed()
    page.screenshot(path=str(OUT / f"find_in_thread_{label}.png"), full_page=False)


def main() -> int:
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        print("playwright missing", file=sys.stderr)
        return 1

    repo = Path(__file__).resolve().parents[2]
    public = repo / "public"
    tmp = Path(tempfile.mkdtemp(prefix="st-find-thread-"))
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
        ["php", "-S", f"127.0.0.1:{port}", "-t", str(public)],
        cwd=str(public),
        env=env,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    OUT.mkdir(parents=True, exist_ok=True)
    try:
        for _ in range(120):
            try:
                with urllib.request.urlopen(f"{base}/api/health.php", timeout=3) as r:
                    if r.status in (200, 401):
                        break
            except Exception:
                pass
            time.sleep(0.25)

        con = sqlite3.connect(db)
        con.execute(
            "UPDATE users SET must_change_password = 0 WHERE username = ?",
            (ADMIN_USER,),
        )
        con.commit()
        con.close()

        task_id = seed_task(base)

        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            page = browser.new_page(viewport={"width": DESKTOP[0], "height": DESKTOP[1]})
            login_admin(page, base)
            exercise_find(page, base, task_id, "desktop")

            page.set_viewport_size({"width": MOBILE[0], "height": MOBILE[1]})
            exercise_find(page, base, task_id, "mobile")
            browser.close()

        print(OUT / "find_in_thread_desktop.png")
        print(OUT / "find_in_thread_mobile.png")
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
