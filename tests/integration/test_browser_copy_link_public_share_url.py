"""
Playwright regression: authenticated doc sidebar Copy public URL must match absolute href.

Catches `window.location.origin` being prepended onto URLs that already start with https://..
"""

from __future__ import annotations

import re
import sqlite3
import urllib.parse as urlparse

import pytest
import requests

pytestmark = [pytest.mark.integration, pytest.mark.browser]


def test_sidebar_copy_public_url_matches_absolute_href(php_server):  # noqa: ANN001
    base = php_server.base_url.rstrip("/")
    hdr = {"X-API-Key": php_server.api_key, "Content-Type": "application/json"}

    r = requests.post(
        f"{base}/api/create-directory-project.php",
        headers=hdr,
        json={"name": "browser-copy-proj", "all_access": True},
        timeout=10,
    )
    assert r.status_code == 201, r.text
    body = r.json()
    project_id = int(((body.get("data") or {}).get("project") or body.get("project"))["id"])

    r = requests.post(
        f"{base}/api/create-document.php",
        headers=hdr,
        json={
            "project_id": project_id,
            "title": "Public copy-link probe",
            "body": "## Hello\n\n",
            "public_link_enabled": True,
        },
        timeout=10,
    )
    assert r.status_code == 201, r.text
    doc = r.json().get("document") or {}
    doc_id = int(doc["id"])
    share = (doc.get("public_share_url") or "").strip()
    assert share.startswith("http"), share
    assert "shared-document.php" in share
    token = urlparse.parse_qs(urlparse.urlparse(share).query).get("token", [""])[0]
    assert len(token) == 64

    prev_must_change = 1
    with sqlite3.connect(php_server.db_path) as sq:
        row = sq.execute(
            "SELECT must_change_password FROM users WHERE lower(username) = lower(?)",
            (php_server.admin_username,),
        ).fetchone()
        if row is not None:
            prev_must_change = int(row[0])
        sq.execute(
            "UPDATE users SET must_change_password = 0 WHERE lower(username) = lower(?)",
            (php_server.admin_username,),
        )
        sq.commit()

    try:
        _run_playwright_copy_link_probe(php_server, base, doc_id, share)
    finally:
        with sqlite3.connect(php_server.db_path) as sq:
            sq.execute(
                "UPDATE users SET must_change_password = ? WHERE lower(username) = lower(?)",
                (prev_must_change, php_server.admin_username),
            )
            sq.commit()


def _run_playwright_copy_link_probe(php_server, base: str, doc_id: int, share: str) -> None:  # noqa: ANN001
    playwright = pytest.importorskip("playwright.sync_api")
    sync_playwright = playwright.sync_playwright

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        ctx = browser.new_context()
        page = ctx.new_page()

        doc_url = f"{base}/admin/doc.php?id={doc_id}"
        page.goto(doc_url, wait_until="commit", timeout=45_000)
        if "login.php" in page.url:
            page.fill('input[name="username"]', php_server.admin_username)
            page.fill('input[name="password"]', php_server.admin_password)
            page.click('button[type="submit"]')

        admin_pat = re.compile(re.escape(base) + r"/admin/")
        page.wait_for_url(admin_pat, timeout=35_000)
        page.goto(doc_url, wait_until="commit", timeout=45_000)
        page.wait_for_load_state("domcontentloaded")
        loc = page.get_by_role("button", name=re.compile(r"copy public url", re.I))

        raw_attr = (loc.first.get_attribute("data-copy-url") or "").strip()
        assert raw_attr == share, {"sidebar": raw_attr, "api_share": share}

        verdict = page.evaluate(
            """
            (expected) => {
              const buttons = [...document.querySelectorAll('button.js-copy-link')]
                .filter((b) => (/copy public url/i.test(b.textContent || '')));
              const el = buttons[0];
              if (!el) return { ok: false, why: 'no copy-public button', raw: '', computed: '' };
              const raw = (el.getAttribute('data-copy-url') || '').trim();
              const computed = /^https?:\\/\\//i.test(raw)
                ? raw
                : window.location.origin + (raw.startsWith('/') ? raw : '/' + raw);
              const doubled = /https?:\\/\\/[^\\s"'<>]+https?:/i.test(computed);
              return {
                ok: !doubled && raw === computed && computed === expected,
                doubled,
                raw,
                computed,
                origin: window.location.origin,
              };
            }
            """,
            share,
        )
        assert verdict.get("ok") is True, verdict

        ctx.close()
        browser.close()
