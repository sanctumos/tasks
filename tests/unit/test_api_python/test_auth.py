"""Unit tests for api_python.auth."""
import pytest
from unittest.mock import MagicMock, patch
from fastapi import HTTPException


def test_get_api_key_from_request_x_api_key():
    from api_python.auth import get_api_key_from_request
    req = MagicMock()
    req.headers = {"X-API-Key": "  mykey  "}
    assert get_api_key_from_request(req) == "mykey"


def test_get_api_key_from_request_bearer():
    from api_python.auth import get_api_key_from_request
    req = MagicMock()
    req.headers = {"Authorization": "Bearer token123"}
    assert get_api_key_from_request(req) == "token123"


def test_get_api_key_from_request_missing():
    from api_python.auth import get_api_key_from_request
    req = MagicMock()
    req.headers = {}
    assert get_api_key_from_request(req) is None


def test_is_admin_role():
    from api_python.auth import is_admin_role
    assert is_admin_role("admin") is True
    assert is_admin_role("manager") is True
    assert is_admin_role("member") is False


def test_validate_api_key_and_get_user_none(python_api_app):
    from api_python.auth import validate_api_key_and_get_user
    assert validate_api_key_and_get_user("nonexistent-key-xyz") is None


def test_validate_api_key_and_get_user_returns_id(python_api_app):
    from api_python.auth import validate_api_key_and_get_user
    user = validate_api_key_and_get_user(python_api_app.api_key)
    assert user is not None
    assert "id" in user
    assert user["id"] == user.get("user_id")
    assert user["username"] == python_api_app.admin_username


def test_require_api_user_missing_key_raises():
    from api_python.auth import require_api_user
    req = MagicMock()
    req.headers = {}
    req.state = MagicMock()
    import asyncio
    with pytest.raises(HTTPException) as exc_info:
        asyncio.get_event_loop().run_until_complete(require_api_user(req))
    assert exc_info.value.status_code == 401


def test_check_api_rate_limit_allowed(python_api_app):
    from api_python.auth import check_api_rate_limit
    state = check_api_rate_limit(python_api_app.api_key)
    assert state["allowed"] is True
    assert state["remaining"] >= 0


def test_require_api_user_rate_limit_429(monkeypatch):
    from api_python.auth import require_api_user
    from unittest.mock import MagicMock, AsyncMock
    req = MagicMock()
    req.headers = {"X-API-Key": "some-key"}
    req.state = MagicMock()
    with patch("api_python.auth.check_api_rate_limit") as mock_rate:
        mock_rate.return_value = {"allowed": False, "retry_after": 60}
        with pytest.raises(HTTPException) as exc_info:
            import asyncio
            asyncio.get_event_loop().run_until_complete(require_api_user(req))
        assert exc_info.value.status_code == 429
        assert "Retry-After" in (exc_info.value.headers or {})


def test_require_api_user_invalid_key_raises(python_api_app):
    from api_python.auth import require_api_user
    req = MagicMock()
    req.headers = {"X-API-Key": "invalid-key-not-in-db"}
    req.state = MagicMock()
    import asyncio
    with patch("api_python.auth.check_api_rate_limit", return_value={"allowed": True}):
        with pytest.raises(HTTPException) as exc_info:
            asyncio.get_event_loop().run_until_complete(require_api_user(req))
    assert exc_info.value.status_code == 401
