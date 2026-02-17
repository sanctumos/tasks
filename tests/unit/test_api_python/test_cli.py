"""Unit tests for api_python.cli (peek)."""
import pytest
from unittest.mock import patch, MagicMock


def test_cli_list_uses_api_key_from_env(monkeypatch):
    monkeypatch.setenv("TASKS_API_KEY", "test-key")
    monkeypatch.setenv("TASKS_API_BASE_URL", "http://testserver")
    from api_python.cli.peek import get_api_key, get_base_url, cmd_list
    assert get_api_key() == "test-key"
    assert get_base_url() == "http://testserver"
    with patch("api_python.cli.peek.api_request") as mock_req:
        mock_req.return_value = {"tasks": [], "pagination": {}}
        cmd_list("test-key")
        mock_req.assert_called_once()
        assert mock_req.call_args[0][2] == "test-key"
        assert mock_req.call_args[1]["params"].get("limit") == 50


def test_cli_view_calls_get_task_and_comments(monkeypatch):
    monkeypatch.setenv("TASKS_API_KEY", "k")
    with patch("api_python.cli.peek.api_request") as mock_req:
        mock_req.side_effect = [
            {"task": {"id": 1, "title": "T", "status": "todo", "body": "", "created_at": "", "updated_at": ""}},
            {"comments": []},
            {"attachments": []},
            {"watchers": []},
        ]
        from api_python.cli.peek import cmd_view
        cmd_view("k", 1)
        assert mock_req.call_count >= 2


def test_cli_main_exits_without_api_key(monkeypatch):
    monkeypatch.delenv("TASKS_API_KEY", raising=False)
    import sys
    from io import StringIO
    with patch("sys.stdout", StringIO()), patch("sys.stderr", StringIO()):
        with pytest.raises(SystemExit):
            from api_python.cli.peek import main
            with patch("sys.argv", ["peek", "list"]):
                main()


def test_cli_main_list_run(monkeypatch):
    monkeypatch.setenv("TASKS_API_KEY", "key")
    with patch("api_python.cli.peek.api_request", return_value={"tasks": [], "pagination": {}}):
        from api_python.cli.peek import main
        with patch("sys.argv", ["peek", "list"]):
            main()


def test_cli_main_module_invocation(monkeypatch):
    """Cover __main__.py by running module as __main__."""
    import runpy
    monkeypatch.setenv("TASKS_API_KEY", "key")
    with patch("api_python.cli.peek.api_request", return_value={"tasks": [], "pagination": {}}):
        with patch("sys.argv", ["peek", "list"]):
            runpy.run_module("api_python.cli", run_name="__main__")


def test_cli_main_view_run(monkeypatch):
    monkeypatch.setenv("TASKS_API_KEY", "key")
    with patch("api_python.cli.peek.api_request") as mock_req:
        mock_req.side_effect = [
            {"task": {"id": 1, "title": "T", "status": "todo", "body": "", "created_at": "", "updated_at": "", "priority": "normal", "project": None, "created_by_username": None, "assigned_to_username": None}},
            {"comments": []},
            {"attachments": []},
            {"watchers": []},
        ]
        from api_python.cli.peek import main
        with patch("sys.argv", ["peek", "view", "1"]):
            main()
