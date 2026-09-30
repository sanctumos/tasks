#!/usr/bin/env python3
"""Playwright W23: notifications + users admin live filters (S4.3)."""
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


def login_admin(page, base: str) -> None:
    page.goto(f"{base}/admin/login.php", wait_until="load", timeout=60000)
    page.locator('input[name="username"]').fill(ADMIN_USER)
    page.locator('input[name="password"]').fill(ADMIN_PASS)
    page.get_by_role("button", name="Sign in").click()
    page.wait_for_load_state("load", timeout=30000)
    if "login.php" in page.url:
        raise RuntimeError(f"login failed: {page.url}")


def seed(base: str, db: Path) -> tuple[str, str]:
    tag = os.urandom(3).hex()
    needle = f"NotifNeedle{tag}"
    api_post(
        base,
        "/api/create-user.php",
        {
            "username": f"member_{tag}",
            "password": "MemberPass123456!",
            "role": "member",
            "must_change_password": False,
        },
    )
    api_post(
        base,
        "/api/create-user.php",
        {
            "username": f"zzzother_{tag}",
            "password": "MemberPass123456!",
            "role": "member",
            "must_change_password": False,
        },
    )
    con = sqlite3.connect(db)
    admin_id = con.execute(
        "SELECT id FROM users WHERE username = ?", (ADMIN_USER,)
    ).fetchone()[0]
    actor_id = con.execute(
        "SELECT id FROM users WHERE username = ?", (f"member_{tag}",)
    ).fetchone()[0]
    payload1 = json.dumps(
        {
            "title": f"{needle} assigned task",
            "label": "You were assigned",
            "href": "/admin/",
            "snippet": f"about {needle}",
            "actor_user_id": actor_id,
            "actor_username": f"member_{tag}",
        }
    )
    payload2 = json.dumps(
        {
            "title": "Unrelated other task",
            "label": "Someone commented",
            "href": "/admin/",
            "snippet": "nothing special here",
            "actor_user_id": actor_id,
            "actor_username": f"member_{tag}",
        }
    )
    con.execute(
        """INSERT INTO user_notifications
           (user_id, actor_user_id, kind, task_id, document_id, task_comment_id, document_comment_id, payload_json, dedupe_key)
           VALUES (?, ?, 'task_assigned', NULL, NULL, NULL, NULL, ?, ?)""",
        (admin_id, actor_id, payload1, f"seed-{tag}-1"),
    )
    con.execute(
        """INSERT INTO user_notifications
           (user_id, actor_user_id, kind, task_id, document_id, task_comment_id, document_comment_id, payload_json, dedupe_key)
           VALUES (?, ?, 'task_comment', NULL, NULL, NULL, NULL, ?, ?)""",
        (admin_id, actor_id, payload2, f"seed-{tag}-2"),
    )
    con.commit()
    con.close()
    return needle, tag


def main() -> int:
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        print("playwright missing", file=sys.stderr)
        return 1

    repo = Path(__file__).resolve().parents[2]
    public = repo / "public"
    tmp = Path(tempfile.mkdtemp(prefix="st-notifs-users-"))
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

        needle, tag = seed(base, db)

        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            page = browser.new_page(viewport={"width": 1280, "height": 800})
            login_admin(page, base)

            page.goto(f"{base}/admin/notifications.php", wait_until="load", timeout=60000)
            page.wait_for_selector("#st-notifs-filter-q", timeout=15000)
            page.locator("#st-notifs-filter-q").fill(needle)
            page.wait_for_function(
                """(needle) => {
                    const rows = [...document.querySelectorAll('#st-notifs-list [data-st-filter-item]')];
                    const vis = rows.filter(r => !r.hidden && r.style.display !== 'none');
                    return vis.length === 1 && (vis[0].getAttribute('data-st-filter-text') || '').includes(needle);
                }""",
                arg=needle,
                timeout=10000,
            )
            page.screenshot(path=str(OUT / "notifs_filter_desktop.png"), full_page=False)

            page.goto(f"{base}/admin/users.php", wait_until="load", timeout=60000)
            page.wait_for_selector("#st-users-filter-q", timeout=15000)
            page.locator("#st-users-filter-q").fill(f"member_{tag}")
            page.wait_for_function(
                f"""() => {{
                    const rows = [...document.querySelectorAll('#st-users-table [data-st-filter-item]')];
                    const vis = rows.filter(r => !r.hidden && r.style.display !== 'none');
                    return vis.length === 1 && (vis[0].getAttribute('data-st-filter-text') || '').includes('member_{tag}');
                }}""",
                timeout=10000,
            )
            page.screenshot(path=str(OUT / "users_filter_desktop.png"), full_page=False)
            browser.close()

        print(OUT / "notifs_filter_desktop.png")
        print(OUT / "users_filter_desktop.png")
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
