#!/usr/bin/env python3
"""
Design verification for the Documents feature: spin up the PHP app,
seed a project + a doc with markdown body + a few comments, then
capture screenshots of:

  * /admin/docs.php          (top-level docs list)
  * /admin/doc.php?id=N      (single doc view + discussion)

Both at desktop (1440x900) and mobile (390x844).

Run from repo root with the .venv-ci venv (has Playwright):

    .venv-ci/bin/python tools/design-smoke/docs_verify.py

Output: tools/design-smoke/output/docs_*.png
"""
from __future__ import annotations

import os
import shutil
import socket
import sqlite3
import subprocess
import sys
import tempfile
import time
from pathlib import Path

import requests

OUT_DIR = Path(__file__).resolve().parent / "output"
DESKTOP = {"width": 1440, "height": 900}
MOBILE = {"width": 390, "height": 844}


def free_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.bind(("127.0.0.1", 0))
        return int(s.getsockname()[1])


def main() -> int:
    repo_root = Path(__file__).resolve().parents[2]
    public = repo_root / "public"
    php = shutil.which("php")
    if not php:
        print("PHP not found", file=sys.stderr)
        return 1

    tmp = Path(tempfile.mkdtemp(prefix="tasks-docs-design-"))
    db_dir = tmp / "db"
    db_dir.mkdir()
    db_path = db_dir / "tasks.db"

    api_key = "a" * 64
    admin_user = "admin"
    admin_pass = "AdminPass123!"

    env = os.environ.copy()
    env.update({
        "TASKS_DB_PATH": str(db_path),
        "TASKS_BOOTSTRAP_ADMIN_USERNAME": admin_user,
        "TASKS_BOOTSTRAP_ADMIN_PASSWORD": admin_pass,
        "TASKS_BOOTSTRAP_API_KEY": api_key,
        "TASKS_PASSWORD_COST": "8",
        "TASKS_SESSION_COOKIE_SECURE": "0",
        "TASKS_API_RATE_LIMIT_REQUESTS": "10000",
    })

    port = free_port()
    base = f"http://127.0.0.1:{port}"
    proc = subprocess.Popen(
        [php, "-S", f"127.0.0.1:{port}", "-t", str(public)],
        cwd=str(repo_root), env=env,
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )

    try:
        for _ in range(60):
            try:
                r = requests.get(f"{base}/api/health.php", timeout=1.0)
                if r.status_code in (200, 401):
                    break
            except Exception:
                pass
            time.sleep(0.2)
        else:
            print("PHP server did not come up", file=sys.stderr)
            return 1

        h = {"X-API-Key": api_key, "Content-Type": "application/json"}

        # Create two projects so the docs list shows project diversity.
        r = requests.post(f"{base}/api/create-directory-project.php", headers=h,
                          json={"name": "Engineering", "all_access": True})
        r.raise_for_status()
        eng_id = int(r.json()["data"]["project"]["id"])

        r = requests.post(f"{base}/api/create-directory-project.php", headers=h,
                          json={"name": "Operations", "all_access": True})
        r.raise_for_status()
        ops_id = int(r.json()["data"]["project"]["id"])

        # Seed a couple docs in the Operations project.
        ops_doc_body = (
            "# Sanctum Tasks deploy runbook\n\n"
            "How to ship `tasks` to production safely.\n\n"
            "## Pre-flight checklist\n\n"
            "1. PHPUnit + pytest green on CI\n"
            "2. CHANGELOG entry under `[Unreleased]`\n"
            "3. Review with [Mark](https://example.com/mark)\n\n"
            "## Deploy\n\n"
            "```bash\n"
            "ssh multihost 'cd /var/www/tasks && git pull'\n"
            "```\n\n"
            "> **Heads up:** `multihost` is Ada's lane — file a Broca ticket\n"
            "> rather than SSHing manually.\n\n"
            "## Rollback\n\n"
            "- Revert the merge commit\n"
            "- Re-deploy via `sync.sh tasks.decisionsciencecorp.com`\n"
            "- Confirm `/api/health.php` returns 200\n"
        )
        r = requests.post(f"{base}/api/create-document.php", headers=h,
                          json={"project_id": ops_id, "title": "Sanctum Tasks deploy runbook", "body": ops_doc_body})
        r.raise_for_status()
        runbook_id = int(r.json()["document"]["id"])

        for body in [
            "First draft is in. Anything missing from pre-flight?",
            "Maybe add `composer test:php` explicitly — easy to forget.",
            "Good call. I'll fold that in.\n\nDone in **rev 2**.",
            "> Heads up: `multihost` is Ada's lane\n\n+1 — leaving the SSH path out keeps Otto honest.",
        ]:
            requests.post(f"{base}/api/create-document-comment.php", headers=h,
                          json={"document_id": runbook_id, "comment": body}).raise_for_status()

        r = requests.post(f"{base}/api/create-document.php", headers=h,
                          json={
                              "project_id": ops_id,
                              "title": "Incident postmortem template",
                              "body": "## Summary\n\n## Timeline\n\n## Root cause\n\n## Action items\n",
                          })
        r.raise_for_status()

        r = requests.post(f"{base}/api/create-document.php", headers=h,
                          json={
                              "project_id": eng_id,
                              "title": "Tasks discussion redesign — decision record",
                              "body": (
                                  "# Discussion redesign — decision record\n\n"
                                  "**Status:** accepted (2026-05)\n\n"
                                  "## Context\n\n"
                                  "Comments were piled into the task **body** because the page had no real composer.\n\n"
                                  "## Decision\n\n"
                                  "Split *Activity* into a real Discussion thread + composer; render markdown via Parsedown.\n\n"
                                  "## Consequences\n\n"
                                  "- Conversations now thread cleanly\n"
                                  "- Docs needed: long-form refs link from discussions\n"
                              ),
                          })
        r.raise_for_status()

        r = requests.post(
            f"{base}/api/create-document.php",
            headers=h,
            json={
                "project_id": ops_id,
                "title": "Handbook overview",
                "body": "## Ops handbook\n\nIndex lives one level up.",
                "directory_path": "handbook",
            },
        )
        r.raise_for_status()

        requests.post(
            f"{base}/api/create-document.php",
            headers=h,
            json={
                "project_id": ops_id,
                "title": "Escalations",
                "body": "## When to page\n\n",
                "directory_path": "handbook/playbooks",
            },
        ).raise_for_status()

        # Clear must_change_password so each viewport can log in cleanly.
        with sqlite3.connect(str(db_path)) as conn:
            conn.execute("UPDATE users SET must_change_password = 0 WHERE username = ?", (admin_user,))

        OUT_DIR.mkdir(parents=True, exist_ok=True)
        from playwright.sync_api import sync_playwright

        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            for label, viewport in [("desktop", DESKTOP), ("mobile", MOBILE)]:
                ctx = browser.new_context(viewport=viewport)
                page = ctx.new_page()
                page.goto(f"{base}/admin/login.php", wait_until="networkidle", timeout=60_000)
                page.fill('input[name="username"]', admin_user)
                page.fill('input[name="password"]', admin_pass)
                with page.expect_navigation(wait_until="networkidle", timeout=30_000):
                    page.press('input[name="password"]', "Enter")

                # Docs list.
                page.goto(f"{base}/admin/docs.php", wait_until="networkidle", timeout=30_000)
                time.sleep(0.4)
                p1 = OUT_DIR / f"docs_list_{label}.png"
                page.screenshot(path=str(p1), full_page=True)
                print(f"[{label}] {p1}")

                # Single doc view.
                page.goto(f"{base}/admin/doc.php?id={runbook_id}", wait_until="networkidle", timeout=30_000)
                time.sleep(0.4)
                p2 = OUT_DIR / f"doc_view_{label}.png"
                page.screenshot(path=str(p2), full_page=True)
                print(f"[{label}] {p2}")

                # Project › Docs tab (folder breadcrumbs + subfolder pills).
                page.goto(f"{base}/admin/project.php?id={ops_id}&tab=docs", wait_until="networkidle", timeout=30_000)
                time.sleep(0.4)
                p3 = OUT_DIR / f"project_docs_folders_{label}.png"
                page.screenshot(path=str(p3), full_page=True)
                print(f"[{label}] {p3}")

                ctx.close()
            browser.close()
    finally:
        if proc.poll() is None:
            proc.terminate()
            try:
                proc.wait(timeout=5)
            except subprocess.TimeoutExpired:
                proc.kill()
        shutil.rmtree(tmp, ignore_errors=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
