#!/usr/bin/env python3
"""Playwright W22: activity feed text filter + type chips (S4.2).

Covers global /admin/activity.php and project.php?tab=activity.
Ephemeral PHP server; screenshots desktop.
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


def seed(base: str) -> tuple[int, str]:
    tag = os.urandom(3).hex()
    needle = f"ActFilt{tag}"
    created = api_post(
        base,
        "/api/create-directory-project.php",
        {"name": f"ActivityFilter {tag}", "all_access": True},
    )
    project_id = int(((created.get("data") or created).get("project") or {}).get("id") or 0)
    lists = api_get(base, f"/api/list-todo-lists.php?project_id={project_id}")
    list_id = int((((lists.get("data") or lists).get("todo_lists") or [{}])[0]).get("id") or 0)
    t1 = api_post(
        base,
        "/api/create-task.php",
        {
            "title": f"{needle} alpha create",
            "status": "todo",
            "project_id": project_id,
            "list_id": list_id,
        },
    )
    task1 = int((((t1.get("data") or t1).get("task") or {}).get("id") or 0))
    t2 = api_post(
        base,
        "/api/create-task.php",
        {
            "title": f"Other zzz{tag}",
            "status": "todo",
            "project_id": project_id,
            "list_id": list_id,
        },
    )
    task2 = int((((t2.get("data") or t2).get("task") or {}).get("id") or 0))
    api_post(
        base,
        "/api/create-comment.php",
        {"task_id": task1, "comment": f"Comment body for {needle}"},
    )
    api_post(
        base,
        "/api/update-task.php",
        {"id": task2, "status": "done"},
    )
    return project_id, needle


def assert_filter(page, feed_sel: str, q_sel: str, chip_sel: str, needle: str) -> None:
    page.wait_for_selector(q_sel, timeout=15000)
    page.locator(q_sel).fill(needle)
    page.wait_for_function(
        """([feed, needle]) => {
            const rows = [...document.querySelectorAll(feed + ' [data-st-filter-item]')];
            const vis = rows.filter(r => !r.hidden && r.style.display !== 'none');
            return vis.length >= 1 && vis.every(r => (r.getAttribute('data-st-filter-text') || '').toLowerCase().includes(needle.toLowerCase()));
        }""",
        arg=[feed_sel, needle],
        timeout=10000,
    )
    page.locator(q_sel).fill("")
    page.wait_for_timeout(200)
    page.locator(f'{chip_sel} [data-st-chip="comment"]').click()
    page.wait_for_function(
        """(feed) => {
            const rows = [...document.querySelectorAll(feed + ' [data-st-filter-item]')];
            const vis = rows.filter(r => !r.hidden && r.style.display !== 'none');
            return vis.length >= 1 && vis.every(r => r.getAttribute('data-st-chip-comment') === '1');
        }""",
        arg=feed_sel,
        timeout=10000,
    )


def main() -> int:
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        print("playwright missing", file=sys.stderr)
        return 1

    repo = Path(__file__).resolve().parents[2]
    public = repo / "public"
    tmp = Path(tempfile.mkdtemp(prefix="st-activity-filter-"))
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

        project_id, needle = seed(base)

        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            page = browser.new_page(viewport={"width": 1280, "height": 800})
            login_admin(page, base)

            page.goto(f"{base}/admin/activity.php", wait_until="load", timeout=60000)
            assert_filter(
                page,
                "#st-activity-feed",
                "#st-activity-filter-q",
                "#st-activity-filter",
                needle,
            )
            page.screenshot(path=str(OUT / "activity_filter_global.png"), full_page=False)

            page.goto(
                f"{base}/admin/project.php?id={project_id}&tab=activity",
                wait_until="load",
                timeout=60000,
            )
            assert_filter(
                page,
                "#st-project-activity-feed",
                "#st-project-activity-filter-q",
                "#st-project-activity-filter",
                needle,
            )
            page.screenshot(path=str(OUT / "activity_filter_project.png"), full_page=False)
            browser.close()

        print(OUT / "activity_filter_global.png")
        print(OUT / "activity_filter_project.png")
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
