"""Unit tests for api_python.logic.helpers."""
import pytest


def test_truncate_string():
    from api_python.logic import helpers
    assert helpers.truncate_string("short", 10) == "short"
    assert helpers.truncate_string("longword", 4) == "long"


def test_normalize_slug():
    from api_python.logic import helpers
    assert helpers.normalize_slug("  Todo  ") == "todo"
    assert helpers.normalize_slug("in progress") == "in-progress"
    assert helpers.normalize_slug("a" * 60)[:50] == "a" * 50


def test_normalize_nullable_text():
    from api_python.logic import helpers
    assert helpers.normalize_nullable_text(None, 10) is None
    assert helpers.normalize_nullable_text("  ", 10) is None
    assert helpers.normalize_nullable_text("  x  ", 10) == "x"


def test_normalize_priority():
    from api_python.logic import helpers
    assert helpers.normalize_priority("high") == "high"
    assert helpers.normalize_priority("  NORMAL  ") == "normal"
    assert helpers.normalize_priority("medium") is None
    assert helpers.normalize_priority("") is None


def test_parse_datetime_or_null():
    from api_python.logic import helpers
    assert helpers.parse_datetime_or_null(None) is None
    assert helpers.parse_datetime_or_null("") is None
    assert helpers.parse_datetime_or_null("2026-01-15T12:00:00Z") is not None
    assert helpers.parse_datetime_or_null("2026-01-15 12:00:00") is not None
    assert helpers.parse_datetime_or_null("invalid") is None


def test_normalize_tags():
    from api_python.logic import helpers
    assert helpers.normalize_tags(None) == []
    assert helpers.normalize_tags("") == []
    assert helpers.normalize_tags("a, b, c") == ["a", "b", "c"]
    assert helpers.normalize_tags(["x", "y"]) == ["x", "y"]
    assert len(helpers.normalize_tags(["t"] * 25)) <= 20


def test_decode_tags_json():
    from api_python.logic import helpers
    assert helpers.decode_tags_json(None) == []
    assert helpers.decode_tags_json('["a","b"]') == ["a", "b"]
    assert helpers.decode_tags_json("invalid") == []


def test_encode_tags_json():
    from api_python.logic import helpers
    assert helpers.encode_tags_json([]) is None
    assert helpers.encode_tags_json(["a"]) == '["a"]'


def test_normalize_username():
    from api_python.logic import helpers
    assert helpers.normalize_username("  Admin  ") == "admin"


def test_validate_username():
    from api_python.logic import helpers
    assert helpers.validate_username("") is not None
    assert helpers.validate_username("ab") is not None  # too short
    assert helpers.validate_username("validuser") is None
    assert helpers.validate_username("invalid!user") is not None


def test_validate_password():
    from api_python.logic import helpers
    assert helpers.validate_password("short") is not None
    assert helpers.validate_password("nouppercase1") is not None
    assert helpers.validate_password("NOLOWERCASE1") is not None
    assert helpers.validate_password("NoNumbers") is not None
    assert helpers.validate_password("ValidPass123") is None


def test_normalize_role():
    from api_python.logic import helpers
    assert helpers.normalize_role("admin") == "admin"
    assert helpers.normalize_role("  MANAGER  ") == "manager"
    assert helpers.normalize_role("invalid") is None


def test_generate_temporary_password():
    from api_python.logic import helpers
    p = helpers.generate_temporary_password(16)
    assert len(p) >= 16
    assert any(c.isupper() for c in p)
    assert any(c.islower() for c in p)
    assert any(c.isdigit() for c in p)


def test_now_utc():
    from api_python.logic import helpers
    s = helpers.now_utc()
    assert " " in s and "-" in s and ":" in s
