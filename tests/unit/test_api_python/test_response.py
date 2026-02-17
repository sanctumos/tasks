"""Unit tests for api_python.response."""
import pytest
from unittest.mock import MagicMock


def _api_error():
    from api_python.response import api_error
    return api_error


def _api_success():
    from api_python.response import api_success
    return api_success


def _json_error():
    from api_python.response import json_error
    return json_error


def _json_success():
    from api_python.response import json_success
    return json_success


def _pagination_meta():
    from api_python.response import pagination_meta
    return pagination_meta


def test_api_error():
    payload, status = _api_error()("test.code", "Test message", 400)
    assert status == 400
    assert payload["success"] is False
    assert payload["error"] == "Test message"
    assert payload["error_object"]["code"] == "test.code"
    assert payload["error_object"]["message"] == "Test message"
    assert payload["error_object"]["details"] == {}


def test_api_error_with_details():
    payload, status = _api_error()("v.code", "Msg", 422, details={"field": "x"})
    assert payload["error_object"]["details"] == {"field": "x"}


def test_api_error_with_extra():
    payload, status = _api_error()("c", "m", 400, extra={"extra_key": 1})
    assert payload["extra_key"] == 1


def test_api_success():
    payload, status = _api_success()({"a": 1}, None, 200)
    assert status == 200
    assert payload["success"] is True
    assert payload["data"] == {"a": 1}
    assert payload["a"] == 1
    assert "meta" not in payload


def test_api_success_with_meta():
    payload, status = _api_success()({"x": 2}, {"total": 10}, 200)
    assert payload["meta"] == {"total": 10}


def test_json_error():
    resp = _json_error()("e.code", "Error", 404)
    assert resp.status_code == 404
    body = resp.body.decode()
    assert "success" in body and "false" in body.lower()


def test_json_success_attaches_rate_limit_headers():
    """json_success uses _rate_limit_headers when request.state.rate_limit is set."""
    req = MagicMock()
    req.state.rate_limit = {"limit": 100, "remaining": 99, "reset_epoch": 12345}
    req.base_url = MagicMock(__str__=lambda: "http://test/")
    resp = _json_success()(req, {"ok": True})
    assert resp.status_code == 200
    assert resp.headers.get("X-RateLimit-Limit") == "100"
    assert resp.headers.get("X-RateLimit-Remaining") == "99"


def test_pagination_meta_next_and_prev():
    req = MagicMock()
    req.base_url = type("URL", (), {"__str__": lambda _: "http://test/"})()
    meta = _pagination_meta()(req, "/api/list-tasks.php", {"status": "todo"}, 10, 10, 50)
    assert meta["limit"] == 10
    assert meta["offset"] == 10
    assert meta["total"] == 50
    assert meta["next_offset"] == 20
    assert meta["prev_offset"] == 0
    assert "next_url" in meta and "prev_url" in meta


def test_pagination_meta_no_next():
    req = MagicMock()
    req.base_url = type("URL", (), {"__str__": lambda _: "http://test/"})()
    meta = _pagination_meta()(req, "/api/list-tasks.php", {}, 10, 0, 5)
    assert meta["next_offset"] is None
    assert meta["next_url"] is None


def test_pagination_meta_no_prev():
    req = MagicMock()
    req.base_url = type("URL", (), {"__str__": lambda _: "http://test/"})()
    meta = _pagination_meta()(req, "/api/list-tasks.php", {}, 10, 0, 50)
    assert meta["prev_offset"] is None
    assert meta["prev_url"] is None
