"""
Integration tests for UI skin configuration (org default + user override).
"""

from __future__ import annotations

import json
import re
import sqlite3

import pytest
import requests

pytestmark = pytest.mark.integration


def _api_url(base_url: str, path: str) -> str:
    return f"{base_url.rstrip('/')}{path}"


def _prepare_admin_session(php_server):  # noqa: ANN001
    with sqlite3.connect(php_server.db_path) as sq:
        sq.execute(
            "UPDATE users SET must_change_password = 0, skin_slug = NULL WHERE lower(username) = lower(?)",
            (php_server.admin_username,),
        )
        sq.commit()

    session = requests.Session()
    login = session.post(
        _api_url(php_server.base_url, "/api/session-login.php"),
        json={"username": php_server.admin_username, "password": php_server.admin_password},
        timeout=10,
    )
    assert login.status_code == 200, login.text
    payload = login.json()
    assert payload["success"] is True
    csrf = payload["csrf_token"]
    assert isinstance(csrf, str) and len(csrf) >= 32
    return session, csrf


def test_save_skin_api_requires_session_and_valid_slug(php_server):  # noqa: ANN001
    base = php_server.base_url

    unauth = requests.post(
        _api_url(base, "/admin/save-skin.php"),
        data={"skin_slug": "hey", "csrf_token": "bad"},
        allow_redirects=False,
        timeout=10,
    )
    assert unauth.status_code in (302, 303), unauth.text[:200]

    session, csrf = _prepare_admin_session(php_server)

    invalid = session.post(
        _api_url(base, "/admin/save-skin.php"),
        data={"skin_slug": "basecamp", "csrf_token": csrf},
        timeout=10,
    )
    assert invalid.status_code == 400
    assert invalid.json()["success"] is False

    ok = session.post(
        _api_url(base, "/admin/save-skin.php"),
        data={"skin_slug": "ledger", "csrf_token": csrf},
        timeout=10,
    )
    assert ok.status_code == 200
    assert ok.json()["success"] is True
    assert ok.json()["skin_slug"] == "ledger"


def test_appearance_settings_tab_and_user_override(php_server):  # noqa: ANN001
    session, csrf = _prepare_admin_session(php_server)
    base = php_server.base_url

    page = session.get(_api_url(base, "/admin/settings.php?tab=appearance"), timeout=10)
    assert page.status_code == 200
    assert "Appearance" in page.text
    assert 'name="skin_choice"' in page.text
    assert 'value="__org__"' in page.text
    assert 'value="hey"' in page.text

    save = session.post(
        _api_url(base, "/admin/settings.php?tab=appearance"),
        data={
            "csrf_token": csrf,
            "settings_action": "save_appearance",
            "skin_choice": "obsidian",
        },
        timeout=10,
    )
    assert save.status_code == 200
    assert "Appearance saved" in save.text

    with sqlite3.connect(php_server.db_path) as sq:
        row = sq.execute(
            "SELECT skin_slug FROM users WHERE lower(username) = lower(?)",
            (php_server.admin_username,),
        ).fetchone()
    assert row is not None
    assert row[0] == "obsidian"

    home = session.get(_api_url(base, "/admin/"), timeout=10)
    assert home.status_code == 200
    assert re.search(r'data-skin-comp="obsidian"', home.text)


def test_appearance_clear_override_uses_org_default(php_server):  # noqa: ANN001
    session, csrf = _prepare_admin_session(php_server)
    base = php_server.base_url

    session.post(
        _api_url(base, "/admin/organizations.php"),
        data={
            "csrf_token": csrf,
            "action": "default_skin",
            "org_id": "1",
            "default_skin_slug": "hey",
        },
        timeout=10,
    )

    session.post(
        _api_url(base, "/admin/settings.php?tab=appearance"),
        data={
            "csrf_token": csrf,
            "settings_action": "save_appearance",
            "skin_choice": "ledger",
        },
        timeout=10,
    )

    clear = session.post(
        _api_url(base, "/admin/settings.php?tab=appearance"),
        data={
            "csrf_token": csrf,
            "settings_action": "save_appearance",
            "skin_choice": "__org__",
        },
        timeout=10,
    )
    assert clear.status_code == 200
    assert "Appearance saved" in clear.text

    with sqlite3.connect(php_server.db_path) as sq:
        skin = sq.execute(
            "SELECT skin_slug FROM users WHERE lower(username) = lower(?)",
            (php_server.admin_username,),
        ).fetchone()[0]
        settings = sq.execute("SELECT settings_json FROM organizations WHERE id = 1").fetchone()[0]
    assert skin is None
    decoded = json.loads(settings or "{}")
    assert decoded.get("default_skin_slug") == "hey"

    home = session.get(_api_url(base, "/admin/"), timeout=10)
    assert re.search(r'data-skin-comp="hey"', home.text)


def test_organizations_admin_can_set_default_skin(php_server):  # noqa: ANN001
    session, csrf = _prepare_admin_session(php_server)
    base = php_server.base_url

    page = session.get(_api_url(base, "/admin/organizations.php"), timeout=10)
    assert page.status_code == 200
    assert "Default skin" in page.text

    save = session.post(
        _api_url(base, "/admin/organizations.php"),
        data={
            "csrf_token": csrf,
            "action": "default_skin",
            "org_id": "1",
            "default_skin_slug": "brutalist",
        },
        timeout=10,
    )
    assert save.status_code == 200
    assert "default skin saved" in save.text.lower()

    with sqlite3.connect(php_server.db_path) as sq:
        sq.execute(
            "UPDATE users SET skin_slug = NULL WHERE lower(username) = lower(?)",
            (php_server.admin_username,),
        )
        sq.commit()
        settings = sq.execute("SELECT settings_json FROM organizations WHERE id = 1").fetchone()[0]
    decoded = json.loads(settings or "{}")
    assert decoded.get("default_skin_slug") == "brutalist"

    home = session.get(_api_url(base, "/admin/"), timeout=10)
    assert re.search(r'data-skin-comp="brutalist"', home.text)
