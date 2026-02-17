"""Unit tests for api_python.db."""
import pytest


def test_assert_identifier_raises_for_invalid():
    from api_python.db import _assert_identifier
    _assert_identifier("valid_name")
    _assert_identifier("table1")
    with pytest.raises(ValueError, match="Unsafe"):
        _assert_identifier("table-name")
    with pytest.raises(ValueError, match="Unsafe"):
        _assert_identifier("123table")


def test_get_connection_and_init_schema(python_api_app):
    from api_python import db
    conn = db.get_connection()
    cur = conn.execute("SELECT 1")
    assert cur.fetchone()[0] == 1
    conn.close()
