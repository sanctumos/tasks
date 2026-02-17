import json
import sys
from unittest.mock import Mock

import pytest

from smcp_plugin.tasks import cli


@pytest.fixture(autouse=True)
def reset_debug_tracebacks(monkeypatch: pytest.MonkeyPatch):
    monkeypatch.setattr(cli, "DEBUG_TRACEBACKS", False)


def test_create_task_maps_new_arguments(monkeypatch: pytest.MonkeyPatch):
    fake_client = Mock()
    fake_client.create_task.return_value = {"id": 1, "title": "Task A"}
    monkeypatch.setattr(cli, "get_client", lambda api_key: fake_client)

    args = {
        "title": "Task A",
        "status": "todo",
        "assigned-to-user-id": 3,
        "body": "details",
        "due-at": "2026-03-01T10:00:00Z",
        "priority": "high",
        "project": "Ops",
        "tags": "foo, bar",
        "rank": 5,
        "recurrence-rule": "FREQ=WEEKLY",
    }
    result = cli.create_task(args, "k")

    assert result["status"] == "success"
    fake_client.create_task.assert_called_once_with(
        title="Task A",
        status="todo",
        assigned_to_user_id=3,
        body="details",
        due_at="2026-03-01T10:00:00Z",
        priority="high",
        project="Ops",
        tags=["foo", "bar"],
        rank=5,
        recurrence_rule="FREQ=WEEKLY",
    )


def test_update_task_maps_unassign_and_clear_body(monkeypatch: pytest.MonkeyPatch):
    fake_client = Mock()
    fake_client.update_task.return_value = {"id": 10, "title": "Task B"}
    monkeypatch.setattr(cli, "get_client", lambda api_key: fake_client)

    result = cli.update_task(
        {
            "task-id": 10,
            "unassign": True,
            "clear-body": True,
            "priority": "urgent",
            "tags": "x,y",
        },
        "k",
    )

    assert result["status"] == "success"
    fake_client.update_task.assert_called_once_with(
        task_id=10,
        title=None,
        status=None,
        assigned_to_user_id=None,
        body=None,
        due_at=None,
        priority="urgent",
        project=None,
        tags=["x", "y"],
        rank=None,
        recurrence_rule=None,
        unassign=True,
        clear_body=True,
    )


def test_list_tasks_maps_search_and_sort(monkeypatch: pytest.MonkeyPatch):
    fake_client = Mock()
    fake_client.list_tasks.return_value = {
        "tasks": [{"id": 1}],
        "count": 1,
        "total": 1,
        "pagination": {"limit": 10},
    }
    monkeypatch.setattr(cli, "get_client", lambda api_key: fake_client)

    result = cli.list_tasks(
        {
            "status": "todo",
            "assigned-to-user-id": 2,
            "priority": "high",
            "project": "Platform",
            "q": "deploy",
            "sort-by": "updated_at",
            "sort-dir": "DESC",
            "limit": 10,
            "offset": 0,
        },
        "k",
    )

    assert result["status"] == "success"
    assert result["total"] == 1
    fake_client.list_tasks.assert_called_once_with(
        status="todo",
        assigned_to_user_id=2,
        priority="high",
        project="Platform",
        q="deploy",
        sort_by="updated_at",
        sort_dir="DESC",
        limit=10,
        offset=0,
    )


def test_main_describe_outputs_valid_json(monkeypatch: pytest.MonkeyPatch, capsys: pytest.CaptureFixture):
    monkeypatch.setattr(sys, "argv", ["cli.py", "--describe"])
    with pytest.raises(SystemExit) as exc:
        cli.main()
    assert exc.value.code == 0
    stdout = capsys.readouterr().out
    payload = json.loads(stdout)
    assert payload["plugin"]["name"] == "tasks"
    assert payload["plugin"]["version"] == "0.2.1"


def test_main_parses_create_task_extended_options(monkeypatch: pytest.MonkeyPatch):
    captured = {}

    def fake_create_task(args, api_key):
        captured["args"] = args
        captured["api_key"] = api_key
        return {"status": "success", "task": {"id": 1, "title": "ok"}}

    monkeypatch.setattr(cli, "create_task", fake_create_task)
    monkeypatch.setattr(
        sys,
        "argv",
        [
            "cli.py",
            "create-task",
            "--api-key",
            "k",
            "--title",
            "hello",
            "--priority",
            "high",
            "--project",
            "Platform",
            "--tags",
            "a,b",
            "--rank",
            "9",
            "--recurrence-rule",
            "FREQ=WEEKLY",
        ],
    )

    with pytest.raises(SystemExit) as exc:
        cli.main()
    assert exc.value.code == 0
    assert captured["api_key"] == "k"
    assert captured["args"]["priority"] == "high"
    assert captured["args"]["project"] == "Platform"
    assert captured["args"]["tags"] == "a,b"
    assert captured["args"]["rank"] == 9
    assert captured["args"]["recurrence-rule"] == "FREQ=WEEKLY"


def test_get_plugin_description_tracks_parser_arguments():
    payload = cli.get_plugin_description(cli.build_parser())
    commands = {item["name"]: item for item in payload["commands"]}

    create_params = {param["name"] for param in commands["create-task"]["parameters"]}
    update_params = {param["name"] for param in commands["update-task"]["parameters"]}
    list_params = {param["name"] for param in commands["list-tasks"]["parameters"]}

    assert {
        "api-key",
        "title",
        "priority",
        "project",
        "tags",
        "rank",
        "due-at",
        "recurrence-rule",
    }.issubset(create_params)
    assert {"task-id", "clear-body", "unassign", "priority", "project"}.issubset(update_params)
    assert {"q", "sort-by", "sort-dir", "priority", "project", "limit", "offset"}.issubset(list_params)

    api_key_param = next(
        param for param in commands["create-task"]["parameters"] if param["name"] == "api-key"
    )
    assert api_key_param["required"] is True


@pytest.mark.parametrize(
    "argv",
    [
        ["cli.py", "--help"],
        ["cli.py", "create-task", "--help"],
    ],
)
def test_main_help_exits_zero_without_argument_error_json(
    monkeypatch: pytest.MonkeyPatch,
    capsys: pytest.CaptureFixture,
    argv,
):
    monkeypatch.setattr(sys, "argv", argv)
    with pytest.raises(SystemExit) as exc:
        cli.main()
    assert exc.value.code == 0
    captured = capsys.readouterr()
    assert '"error_type": "argument_error"' not in captured.out
    assert '"error_type": "argument_error"' not in captured.err


def test_main_invalid_arguments_emit_argument_error_json(
    monkeypatch: pytest.MonkeyPatch,
    capsys: pytest.CaptureFixture,
):
    monkeypatch.setattr(sys, "argv", ["cli.py", "create-task", "--api-key", "k"])
    with pytest.raises(SystemExit) as exc:
        cli.main()
    assert exc.value.code == 2

    captured = capsys.readouterr()
    payload = json.loads(captured.out)
    assert payload["status"] == "error"
    assert payload["error_type"] == "argument_error"
    assert "traceback" not in payload


def test_list_tasks_error_hides_traceback_by_default(monkeypatch: pytest.MonkeyPatch):
    def fake_get_client(api_key):
        raise cli.APIError("boom")

    monkeypatch.setattr(cli, "get_client", fake_get_client)
    result = cli.list_tasks({}, "k")
    assert result["status"] == "error"
    assert result["error_type"] == "api_error"
    assert "traceback" not in result


def test_list_tasks_error_includes_traceback_in_debug(monkeypatch: pytest.MonkeyPatch):
    def fake_get_client(api_key):
        raise RuntimeError("explode")

    monkeypatch.setattr(cli, "get_client", fake_get_client)
    monkeypatch.setattr(cli, "DEBUG_TRACEBACKS", True)
    result = cli.list_tasks({}, "k")
    assert result["status"] == "error"
    assert result["error_type"] == "unknown_error"
    assert "traceback" in result
    assert "RuntimeError: explode" in result["traceback"]


def test_main_debug_flag_enables_traceback_in_error_output(
    monkeypatch: pytest.MonkeyPatch,
    capsys: pytest.CaptureFixture,
):
    def exploding_create_task(args, api_key):
        raise RuntimeError("boom")

    monkeypatch.setattr(cli, "create_task", exploding_create_task)
    monkeypatch.setattr(
        sys,
        "argv",
        ["cli.py", "--debug", "create-task", "--api-key", "k", "--title", "x"],
    )
    with pytest.raises(SystemExit) as exc:
        cli.main()
    assert exc.value.code == 1

    payload = json.loads(capsys.readouterr().out)
    assert payload["status"] == "error"
    assert payload["error_type"] == "unknown_error"
    assert "traceback" in payload


def test_main_default_error_output_omits_traceback(
    monkeypatch: pytest.MonkeyPatch,
    capsys: pytest.CaptureFixture,
):
    def exploding_create_task(args, api_key):
        raise RuntimeError("boom")

    monkeypatch.setattr(cli, "create_task", exploding_create_task)
    monkeypatch.setattr(
        sys,
        "argv",
        ["cli.py", "create-task", "--api-key", "k", "--title", "x"],
    )
    with pytest.raises(SystemExit) as exc:
        cli.main()
    assert exc.value.code == 1

    payload = json.loads(capsys.readouterr().out)
    assert payload["status"] == "error"
    assert payload["error_type"] == "unknown_error"
    assert "traceback" not in payload
