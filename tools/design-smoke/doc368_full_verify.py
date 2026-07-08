#!/usr/bin/env python3
"""Playwright: render real Doc #368 body (mixed pseudocode + mermaid) locally."""
from __future__ import annotations

import json
import os
import re
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
DESKTOP = (1400, 900)
MOBILE = (390, 844)
API_KEY = "a" * 64
DOC_JSON = Path("/tmp/doc368.json")


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
    with urllib.request.urlopen(req, timeout=120) as r:
        return json.loads(r.read().decode())


def login_admin(page, base: str) -> None:
    page.goto(f"{base}/admin/login.php", wait_until="networkidle")
    page.locator('input[name="username"]').fill("admin")
    page.locator('input[name="password"]').fill("AdminPass123456!")
    page.get_by_role("button", name="Sign in").click()
    page.wait_for_load_state("networkidle", timeout=30000)
    if "login.php" in page.url:
        raise RuntimeError(f"login failed: {page.url}")


def load_doc_body() -> str:
    if DOC_JSON.is_file():
        doc = json.loads(DOC_JSON.read_text(encoding="utf-8")).get("document") or {}
        body = doc.get("body")
        if isinstance(body, str) and body.strip():
            return body
    raise RuntimeError(f"Missing doc body at {DOC_JSON}")


def main() -> int:
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        print("playwright not installed", file=sys.stderr)
        return 1

    body = load_doc_body()
    mermaid_count = len(re.findall(r"^```mermaid\s*$", body, re.MULTILINE))
    bare_count = len(re.findall(r"^```\s*$", body, re.MULTILINE)) - mermaid_count

    repo = Path(__file__).resolve().parents[2]
    public = repo / "public"
    php = shutil.which("php")
    if not php:
        return 1

    tmp = Path(tempfile.mkdtemp(prefix="doc368full-"))
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

    projects = api_post(base, "/api/create-directory-project.php", {"name": "Doc368 Full"})
    project_id = int((projects.get("project") or projects).get("id") or projects.get("id") or 0)
    doc = api_post(
        base,
        "/api/create-document.php",
        {
            "project_id": project_id,
            "title": "Thalamus Patent — Doc #368 reproduction",
            "body": body,
        },
    )
    doc_id = int((doc.get("document") or doc).get("id") or doc.get("id") or 0)

    try:
        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            for label, size, fname in (
                ("desktop", DESKTOP, "doc368_full_desktop.png"),
                ("mobile", MOBILE, "doc368_full_mobile.png"),
            ):
                page = browser.new_page(viewport={"width": size[0], "height": size[1]})
                login_admin(page, base)
                page.goto(f"{base}/admin/doc.php?id={doc_id}", wait_until="networkidle", timeout=120000)
                page.wait_for_timeout(4000)  # mermaid async render
                doc_body = page.locator(".doc-body.markdown-body")
                doc_body.wait_for(timeout=30000)
                pre_count = doc_body.locator("pre").count()
                mermaid_div_count = doc_body.locator(".mermaid").count()
                bomb_count = doc_body.locator("text=Syntax error in text").count()
                pseudocode_ok = doc_body.locator("pre code", has_text="FUNCTION score_sender").count() >= 1
                print(
                    f"{label}: pre={pre_count} mermaid_divs={mermaid_div_count} "
                    f"bombs={bomb_count} pseudocode_in_pre={pseudocode_ok} "
                    f"(source bare_fences~{bare_count} mermaid_fences={mermaid_count})"
                )
                if not pseudocode_ok:
                    raise RuntimeError(f"{label}: pseudocode not in <pre>")
                if pre_count < 5:
                    raise RuntimeError(f"{label}: expected pseudocode <pre> blocks, got {pre_count}")
                if mermaid_div_count < 5:
                    raise RuntimeError(f"{label}: expected multiple mermaid divs, got {mermaid_div_count}")
                page.screenshot(path=str(OUT / fname), full_page=True)
                # Section 8 anchor crop
                fig = doc_body.locator("text=Nine Figures").first
                if fig.count():
                    fig.scroll_into_view_if_needed()
                    page.wait_for_timeout(500)
                    page.screenshot(path=str(OUT / f"doc368_full_{label}_figures.png"), full_page=False)
                page.close()
            browser.close()
    finally:
        proc.kill()
        proc.wait(timeout=5)

    print("OK — screenshots in tools/design-smoke/output")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
