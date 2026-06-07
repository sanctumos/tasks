#!/usr/bin/env python3
"""Playwright: Project Doors tab renders (desktop + mobile)."""
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
    page.locator('input[name="username"]').fill("admin")
    page.locator('input[name="password"]').fill("AdminPass123456!")
    page.get_by_role("button", name="Sign in").click()
    page.wait_for_load_state("networkidle", timeout=30000)
    if "login.php" in page.url:
        raise RuntimeError(f"login failed: {page.url}")


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

    tmp = Path(tempfile.mkdtemp(prefix="doors-"))
    db = tmp / "tasks.db"
    env = os.environ.copy()
    env.update({
        "TASKS_DB_PATH": str(db),
        "TASKS_BOOTSTRAP_ADMIN_USERNAME": "admin",
        "TASKS_BOOTSTRAP_ADMIN_PASSWORD": "AdminPass123456!",
        "TASKS_BOOTSTRAP_API_KEY": API_KEY,
        "TASKS_PASSWORD_COST": "8",
        "TASKS_SESSION_COOKIE_SECURE": "0",
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
    ready = False
    for _ in range(90):
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
        time.sleep(0.2)
    if not ready:
        proc.kill()
        print("server not ready", file=sys.stderr)
        return 1

    con = sqlite3.connect(db)
    con.execute("UPDATE users SET must_change_password = 0 WHERE username = 'admin'")
    con.commit()
    con.close()

    projects = api_get(base, "/api/list-directory-projects.php?limit=5")
    rows = projects.get("projects") or projects.get("directory_projects") or []
    if not rows:
        created = api_post(base, "/api/create-directory-project.php", {"name": "Doors Smoke"})
        project_id = int((created.get("project") or created).get("id") or created.get("id") or 0)
    else:
        project_id = int(rows[0]["id"])

    api_post(
        base,
        "/api/create-project-door.php",
        {
            "project_id": project_id,
            "title": "Figma board",
            "url": "https://www.figma.com/file/smoke-test",
            "description": "Design reference",
        },
    )

    try:
        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            for label, size, fname in (
                ("desktop", DESKTOP, "doors_desktop.png"),
                ("mobile", MOBILE, "doors_mobile.png"),
            ):
                page = browser.new_page(viewport={"width": size[0], "height": size[1]})
                login_admin(page, base)
                page.goto(f"{base}/admin/project.php?id={project_id}&tab=doors", wait_until="networkidle")
                page.get_by_text("Figma board").wait_for(timeout=15000)
                page.screenshot(path=str(OUT / fname), full_page=True)
                page.close()
            browser.close()
    finally:
        proc.kill()
        proc.wait(timeout=5)

    print("OK — screenshots in tools/design-smoke/output")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
