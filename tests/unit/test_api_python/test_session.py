"""Unit tests for api_python.session."""
import pytest
from unittest.mock import MagicMock, patch


def test_create_session_and_validate(python_api_app):
    from api_python import session as session_module
    token, csrf = session_module.create_session(1)
    assert token and csrf
    result = session_module.validate_session(token)
    assert result is not None
    user, session_csrf = result
    assert user["id"] == 1
    assert session_csrf == csrf


def test_validate_session_none():
    from api_python import session as session_module
    assert session_module.validate_session(None) is None
    assert session_module.validate_session("") is None
    assert session_module.validate_session("  ") is None


def test_validate_session_invalid_token():
    from api_python import session as session_module
    assert session_module.validate_session("invalid-token-xyz") is None


def test_destroy_session(python_api_app):
    from api_python import session as session_module
    token, _ = session_module.create_session(1)
    assert session_module.destroy_session(token) is True
    assert session_module.validate_session(token) is None
    assert session_module.destroy_session(token) is False


def test_destroy_session_none():
    from api_python import session as session_module
    assert session_module.destroy_session(None) is False


def test_verify_csrf():
    from api_python import session as session_module
    assert session_module.verify_csrf("a", "a") is True
    assert session_module.verify_csrf("a", "b") is False
    assert session_module.verify_csrf(None, "a") is False
    assert session_module.verify_csrf("a", None) is False


def test_get_session_token_from_request():
    from api_python import session as session_module
    req = MagicMock()
    req.cookies = {session_module.SESSION_COOKIE_NAME: "sometoken"}
    assert session_module.get_session_token_from_request(req) == "sometoken"


def test_validate_session_expired_returns_none(python_api_app):
    from api_python import session as session_module
    from api_python import db
    token, _ = session_module.create_session(1)
    conn = db.get_connection()
    conn.execute("UPDATE api_sessions SET expires_at = '2000-01-01 00:00:00' WHERE token = ?", (token,))
    conn.commit()
    conn.close()
    assert session_module.validate_session(token) is None


def test_get_session_token_from_request_no_cookies():
    from api_python import session as session_module
    req = MagicMock(spec=[])  # no cookies attr
    assert session_module.get_session_token_from_request(req) is None
