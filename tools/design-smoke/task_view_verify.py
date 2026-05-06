#!/usr/bin/env python3
"""
Design verification: spin up the PHP app on a temp DB, seed a task with
comments + watcher + attachment, then capture screenshots of the
redesigned task page at desktop + mobile widths.

Run from repo root with the .venv-ci venv (has Playwright):

    .venv-ci/bin/python tools/design-smoke/task_view_verify.py

Output: tools/design-smoke/output/task_view_*.png
"""
from __future__ import annotations

import os
import shutil
import socket
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

    tmp = Path(tempfile.mkdtemp(prefix="tasks-design-"))
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
        # Wait for server.
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
        # Create directory project
        r = requests.post(f"{base}/api/create-directory-project.php", headers=h,
                          json={"name": "Design Verification", "all_access": True})
        r.raise_for_status()
        project_id = int(r.json()["data"]["project"]["id"])

        # Create task with a markdown body and a real RRULE so screenshots
        # exercise both new features.
        body_md = (
            "## Goals\n\n"
            "Replaces the cramped *Activity* card with a real **Discussion** thread.\n\n"
            "- Comment composer in-page (no more API-only)\n"
            "- Watch / unwatch button next to the title\n"
            "- Project dropdown bound to `project_id` (no more orphans)\n\n"
            "### Acceptance\n\n"
            "1. Comments render markdown\n"
            "2. Times are shown inline (absolute + relative)\n"
            "3. Recurrence has a real builder, not a raw RRULE\n\n"
            "> Reference: https://example.com/sanctum-tasks/redesign\n\n"
            "```php\n"
            "echo st_markdown($task['body']);\n"
            "```\n"
        )
        r = requests.post(f"{base}/api/create-task.php", headers=h,
                          json={
                              "title": "Roll out new task page layout",
                              "body": body_md,
                              "status": "doing",
                              "priority": "high",
                              "project_id": project_id,
                              "tags": ["ui", "polish", "discussion"],
                              "recurrence_rule": "FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE,FR;COUNT=12",
                          })
        r.raise_for_status()
        task_id = int(r.json()["data"]["task"]["id"])

        # Multiple comments — markdown, links, code, quotes — to exercise
        # the renderer and the timestamp display.
        for body in [
            "Pulling apart the existing **Activity** card so comments aren't sharing space with watchers.",
            "Quick question: do we want `@mentions` in this pass, or hold for a follow-up?",
            "Holding on `@mentions`; just want a real composer + chronological history first.\n\n"
            "Plan:\n\n"
            "1. Discussion card with composer\n"
            "2. Watching button in header\n"
            "3. Project dropdown bound to `project_id`",
            "Composer is wired — `POST /admin/comment.php` on submit. Attachments still API-only.\n"
            "See [the redesign doc](https://example.com/redesign).",
            "> Looking sharp at desktop. Going to verify mobile next.",
        ]:
            r = requests.post(f"{base}/api/create-comment.php", headers=h,
                              json={"task_id": task_id, "comment": body})
            r.raise_for_status()

        # Add a watcher
        r = requests.post(f"{base}/api/watch-task.php", headers=h,
                          json={"task_id": task_id})
        r.raise_for_status()

        # Add an attachment metadata row
        r = requests.post(f"{base}/api/add-attachment.php", headers=h,
                          json={
                              "task_id": task_id,
                              "file_name": "before-screenshot.png",
                              "file_url": "https://example.com/before.png",
                              "mime_type": "image/png",
                              "size_bytes": 24576,
                          })
        r.raise_for_status()

        # Clear must_change_password directly in the DB so each viewport
        # gets a clean session without mutating the admin password
        # mid-loop.
        import sqlite3
        with sqlite3.connect(str(db_path)) as conn:
            conn.execute("UPDATE users SET must_change_password = 0 WHERE username = ?", (admin_user,))

        # Now drive the admin UI with Playwright.
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

                page.goto(f"{base}/admin/view.php?id={task_id}", wait_until="networkidle", timeout=60_000)
                time.sleep(0.4)
                out = OUT_DIR / f"task_view_{label}.png"
                page.screenshot(path=str(out), full_page=True)
                print(f"[{label}] {out}")

                # Open the recurrence modal and snap it too.
                page.click(".recurrence-trigger")
                page.wait_for_selector("#recurrenceModal.show", timeout=5_000)
                time.sleep(0.4)
                modal_out = OUT_DIR / f"recurrence_modal_{label}.png"
                page.screenshot(path=str(modal_out), full_page=False)
                print(f"[{label}] {modal_out}")
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
