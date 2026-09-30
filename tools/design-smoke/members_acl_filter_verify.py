#!/usr/bin/env python3
"""Playwright: project members + user-projects ACL name filters (S3.4)."""
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


def main() -> int:
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        return 1

    repo = Path(__file__).resolve().parents[2]
    public = repo / "public"
    tmp = Path(tempfile.mkdtemp(prefix="st-members-acl-"))
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

        tag = os.urandom(3).hex()
        created = api_post(
            base,
            "/api/create-directory-project.php",
            {"name": f"AclFilter {tag}", "all_access": True},
        )
        project_id = int(((created.get("data") or created).get("project") or {}).get("id") or 0)

        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            page = browser.new_page(viewport={"width": 1280, "height": 800})
            login_admin(page, base)
            page.goto(f"{base}/admin/project.php?id={project_id}&tab=members", wait_until="load")
            page.wait_for_selector("#st-members-filter-q", timeout=15000)
            page.locator("#st-members-filter-q").fill("admin")
            page.wait_for_function(
                """() => {
                    const rows = [...document.querySelectorAll('#st-members-table [data-st-filter-item]')];
                    return rows.some(r => !r.hidden && /admin/i.test(r.textContent || ''));
                }""",
                timeout=10000,
            )
            page.screenshot(path=str(OUT / "members_filter_desktop.png"), full_page=False)

            # admin user id is 1 after bootstrap
            page.goto(f"{base}/admin/user-projects.php?id=1", wait_until="load")
            page.wait_for_selector("#st-acl-filter-q", timeout=15000)
            page.locator("#st-acl-filter-q").fill(tag)
            page.wait_for_function(
                f"""() => {{
                    const cols = [...document.querySelectorAll('#st-acl-project-grid [data-st-filter-item]')];
                    const vis = cols.filter(c => !c.hidden);
                    return vis.length >= 1 && vis.every(c => (c.textContent || '').includes({json.dumps(tag)}));
                }}""",
                timeout=10000,
            )
            page.screenshot(path=str(OUT / "acl_filter_desktop.png"), full_page=False)
            browser.close()

        print(OUT / "members_filter_desktop.png")
        print(OUT / "acl_filter_desktop.png")
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
