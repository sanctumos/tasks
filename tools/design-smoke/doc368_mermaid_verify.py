#!/usr/bin/env python3
"""Playwright: Doc #368-style mixed pseudocode + mermaid renders correctly."""
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

SAMPLE_BODY = """# Mixed fences regression

Pseudocode:

```
FUNCTION score_sender(signals) -> INTEGER
  IF signals.two_way THEN score <- score + 50
  RETURN score
```

Mermaid:

```mermaid
flowchart TD
  START([Sender address]) --> ORG{org_sender_exclusion_reason?}
  ORG -->|yes| EXCL[excluded=1]
```

Broken mermaid (syntax error) should not break pseudocode above:

```mermaid
this is not valid mermaid syntax!!!
```
"""


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

    tmp = Path(tempfile.mkdtemp(prefix="doc368-"))
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

    projects = api_post(base, "/api/create-directory-project.php", {"name": "Doc368 Smoke"})
    project_id = int((projects.get("project") or projects).get("id") or projects.get("id") or 0)
    doc = api_post(
        base,
        "/api/create-document.php",
        {"project_id": project_id, "title": "Doc368 mixed fences", "body": SAMPLE_BODY},
    )
    doc_id = int((doc.get("document") or doc).get("id") or doc.get("id") or 0)

    try:
        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            for label, size, fname in (
                ("desktop", DESKTOP, "doc368_mermaid_desktop.png"),
                ("mobile", MOBILE, "doc368_mermaid_mobile.png"),
            ):
                page = browser.new_page(viewport={"width": size[0], "height": size[1]})
                login_admin(page, base)
                page.goto(f"{base}/admin/doc.php?id={doc_id}", wait_until="networkidle")
                body = page.locator(".doc-body.markdown-body")
                body.get_by_text("FUNCTION score_sender").wait_for(timeout=15000)
                pre_count = body.locator("pre").count()
                mermaid_count = body.locator(".mermaid").count()
                if pre_count < 1:
                    raise RuntimeError(f"expected pseudocode <pre>, got {pre_count}")
                if mermaid_count < 2:
                    raise RuntimeError(f"expected 2 mermaid blocks, got {mermaid_count}")
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
