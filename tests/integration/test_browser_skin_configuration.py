"""
Playwright e2e: skin appearance settings and layout attribute wiring.
"""

from __future__ import annotations

import re
import sqlite3

import pytest
import requests

pytestmark = [pytest.mark.integration, pytest.mark.browser]


def _prepare_admin_session(php_server):  # noqa: ANN001
    with sqlite3.connect(php_server.db_path) as sq:
        sq.execute(
            "UPDATE users SET must_change_password = 0, skin_slug = NULL WHERE lower(username) = lower(?)",
            (php_server.admin_username,),
        )
        sq.commit()

    session = requests.Session()
    login = session.post(
        f"{php_server.base_url.rstrip('/')}/api/session-login.php",
        json={"username": php_server.admin_username, "password": php_server.admin_password},
        timeout=10,
    )
    assert login.status_code == 200, login.text
    return session


def test_browser_appearance_settings_changes_layout_skin(php_server):  # noqa: ANN001
    playwright = pytest.importorskip("playwright.sync_api")
    sync_playwright = playwright.sync_playwright

    session = _prepare_admin_session(php_server)
    cookies = []
    for c in session.cookies:
        cookies.append(
            {
                "name": c.name,
                "value": c.value,
                "domain": "127.0.0.1",
                "path": c.path or "/",
            }
        )

    base = php_server.base_url.rstrip("/")

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context()
        if cookies:
            context.add_cookies(cookies)
        page = context.new_page()

        page.goto(f"{base}/admin/settings.php?tab=appearance", wait_until="domcontentloaded", timeout=45_000)
        assert "Appearance" in page.content()
        assert page.locator('input[name="skin_choice"][value="ledger"]').count() >= 1

        page.locator('input[name="skin_choice"][value="ledger"]').check()
        page.get_by_role("button", name="Save appearance").click()
        page.wait_for_timeout(400)
        assert "Appearance saved" in page.content()

        page.goto(f"{base}/admin/", wait_until="domcontentloaded", timeout=45_000)
        skin = page.locator("html").get_attribute("data-skin-comp")
        assert skin == "ledger"
        assert page.locator('link[href*="/assets/skins/ledger.css"]').count() >= 1

        browser.close()


def test_browser_organizations_default_skin_column_present(php_server):  # noqa: ANN001
    playwright = pytest.importorskip("playwright.sync_api")
    sync_playwright = playwright.sync_playwright

    session = _prepare_admin_session(php_server)
    cookies = [
        {"name": c.name, "value": c.value, "domain": "127.0.0.1", "path": c.path or "/"}
        for c in session.cookies
    ]
    base = php_server.base_url.rstrip("/")

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context()
        if cookies:
            context.add_cookies(cookies)
        page = context.new_page()

        page.goto(f"{base}/admin/organizations.php", wait_until="domcontentloaded", timeout=45_000)
        assert page.locator("th", has_text=re.compile("Default skin", re.I)).count() >= 1
        assert page.locator('select[name="default_skin_slug"]').count() >= 1
        assert page.locator('option[value="hey"]').count() >= 1

        browser.close()
