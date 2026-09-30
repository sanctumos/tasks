#!/usr/bin/env python3
"""Playwright W18: Docs hub + project Docs tab submit search."""
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


def login_admin(page, base: str) -> None:
    page.goto(f"{base}/admin/login.php", wait_until="networkidle")
    page.locator('input[name="username"]').fill(ADMIN_USER)
    page.locator('input[name="password"]').fill(ADMIN_PASS)
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

    tmp = Path(tempfile.mkdtemp(prefix="docssearch-"))
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

        needle = "retainer" + os.urandom(3).hex()
        created = api_post(
            base,
            "/api/create-directory-project.php",
            {"name": f"DocsSearch {needle}", "all_access": True},
        )
        data = created.get("data") or created
        project = data.get("project") or data
        project_id = int(project.get("id") or 0)
        api_post(
            base,
            "/api/create-document.php",
            {
                "project_id": project_id,
                "title": f"Gutor {needle} form",
                "body": f"The only instrument is the {needle} GSA + SOW shape.",
            },
        )

        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            try:
                for label, size in (("desktop", DESKTOP), ("mobile", MOBILE)):
                    page = browser.new_page(viewport={"width": size[0], "height": size[1]})
                    login_admin(page, base)
                    page.goto(f"{base}/admin/docs.php", wait_until="networkidle")
                    page.get_by_role("searchbox", name="Search documents").fill(needle)
                    page.locator("form.filter-bar").get_by_role("button", name="Search").click()
                    page.wait_for_url("**/admin/docs.php?**q=**", timeout=15000)
                    page.wait_for_selector(".st-docresult", timeout=10000)
                    page.wait_for_selector(".st-docresult__snip mark.st-hl", timeout=5000)
                    page.screenshot(path=str(OUT / f"docs_search_hub_{label}.png"), full_page=True)

                    page.goto(
                        f"{base}/admin/project.php?id={project_id}&tab=docs",
                        wait_until="networkidle",
                    )
                    page.get_by_role("searchbox", name="Search project documents").fill(needle)
                    page.locator("form.filter-bar").get_by_role("button", name="Search").click()
                    page.wait_for_selector(".st-docresult", timeout=10000)
                    page.screenshot(
                        path=str(OUT / f"docs_search_project_{label}.png"),
                        full_page=True,
                    )
                    page.close()
            finally:
                browser.close()

        for name in (
            "docs_search_hub_desktop.png",
            "docs_search_hub_mobile.png",
            "docs_search_project_desktop.png",
            "docs_search_project_mobile.png",
        ):
            print(OUT / name)
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
