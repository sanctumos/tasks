import json
import sys

import pytest

from smcp_plugin.tasks import tool_profiles


def test_chatter_profile_has_fifteen_commands():
    assert len(tool_profiles.PROFILE_CHATTER) == 15


def test_filter_plugin_description_chatter():
    desc = {
        "plugin": {"name": "tasks"},
        "commands": [{"name": n} for n in ("create-task", "create-user", "health")],
    }
    out = tool_profiles.filter_plugin_description(desc, "chatter")
    names = {c["name"] for c in out["commands"]}
    assert out["profile"] == "chatter"
    assert names == {"create-task"}


def test_tool_help_document_intent():
    out = tool_profiles.tool_help("document transcript", "chatter")
    assert out["status"] == "success"
    tools = {t for row in out["matches"] for t in row["tools"]}
    assert "get-document" in tools
    assert "create-user" not in tools


def test_unknown_profile_raises():
    with pytest.raises(ValueError):
        tool_profiles.normalize_profile("bogus")
