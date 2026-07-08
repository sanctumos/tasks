#!/usr/bin/env python3
"""Screenshot Doc #368 §3 pseudocode section after local render."""
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
DOC_JSON = Path("/tmp/doc368.json")


def free_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.bind(("127.0.0.1", 0))
        return int(s.getsockname()[1])


def api_post(base: str, path: str, payload: dict) -> dict:
    import json as _json
    req = urllib.request.Request(
        f"{base}{path}",
        data=_json.dumps(payload).encode(),
        headers={"Content-Type": "application/json", "X-API-Key": API_KEY},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=120) as r:
        return _json.loads(r.read().decode())


def main() -> int:
    from playwright.sync_api import sync_playwright

    body = json.loads(DOC_JSON.read_text())["document"]["body"]
    repo = Path(__file__).resolve().parents[2]
    public = repo / "public"
    php = shutil.which("php")
    tmp = Path(tempfile.mkdtemp(prefix="doc368crop-"))
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
        cwd=str(public), env=env,
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )
    for _ in range(90):
        try:
            with urllib.request.urlopen(f"{base}/api/health.php", timeout=3):
                break
        except Exception:
            time.sleep(0.2)
    con = sqlite3.connect(db)
    con.execute("UPDATE users SET must_change_password = 0 WHERE username = 'admin'")
    con.commit()
    con.close()
    p = api_post(base, "/api/create-directory-project.php", {"name": "Crop"})
    pid = int((p.get("project") or p).get("id") or p.get("id"))
    d = api_post(base, "/api/create-document.php", {"project_id": pid, "title": "Crop", "body": body})
    did = int((d.get("document") or d).get("id") or d.get("id"))
    try:
        with sync_playwright() as pw:
            browser = pw.chromium.launch(headless=True)
            page = browser.new_page(viewport={"width": 1280, "height": 900})
            page.goto(f"{base}/admin/login.php", wait_until="networkidle")
            page.locator('input[name="username"]').fill("admin")
            page.locator('input[name="password"]').fill("AdminPass123456!")
            page.get_by_role("button", name="Sign in").click()
            page.goto(f"{base}/admin/doc.php?id={did}", wait_until="networkidle", timeout=120000)
            anchor = page.locator(".doc-body").get_by_text("Item 1: Composite Importance Formula").first
            anchor.scroll_into_view_if_needed()
            page.wait_for_timeout(500)
            pre = page.locator(".doc-body pre").filter(has_text="FUNCTION score_sender").first
            pre.screenshot(path=str(OUT / "doc368_pseudocode_crop.png"))
            # mermaid figure 2 area
            fig2 = page.locator(".doc-body").get_by_text("Figure 2").first
            fig2.scroll_into_view_if_needed()
            page.wait_for_timeout(3000)
            page.locator(".doc-body").screenshot(path=str(OUT / "doc368_figure2_area.png"))
            browser.close()
    finally:
        proc.kill()
    print("OK")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
