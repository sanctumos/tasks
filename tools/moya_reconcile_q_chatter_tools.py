#!/usr/bin/env python3
"""Detach all q_vernal_tasks from Q_Vernal, attach chatter profile only (moya)."""

from __future__ import annotations

import json
import os
import sys
import urllib.error
import urllib.request
from pathlib import Path

# Reuse profile constants from moya_attach_q_smcp
sys.path.insert(0, str(Path(__file__).resolve().parent))
from moya_attach_q_smcp import (  # noqa: E402
    AGENT_ID,
    CHATTER_TOOL_NAMES,
    LETTA_BASE,
    SERVER_NAME,
    TOOL_PREFIX,
    detach_tool,
    profile_allows,
    req,
    tool_list,
)


def load_api_key() -> None:
    if os.getenv("LETTA_API_KEY"):
        return
    for path in (
        Path("/home/rizzn/sanctum/agents/athena/broca/.env"),
        Path("/root/.letta/.env"),
    ):
        if not path.is_file():
            continue
        for line in path.read_text(encoding="utf-8").splitlines():
            if line.startswith("AGENT_API_KEY="):
                os.environ["LETTA_API_KEY"] = line.split("=", 1)[1].strip().strip("\"'")
                return


def resolve_server_id() -> str:
    servers = req("GET", "/v1/mcp-servers/")
    for s in servers if isinstance(servers, list) else servers.get("mcp_servers", []):
        if s.get("server_name") == SERVER_NAME:
            return s.get("id") or ""
    return ""


def main() -> None:
    load_api_key()
    if not os.getenv("LETTA_API_KEY"):
        print("LETTA_API_KEY required", file=sys.stderr)
        sys.exit(2)
    agent_id = AGENT_ID or os.getenv("Q_VERNAL_AGENT_ID", "")
    if not agent_id:
        print("Q_VERNAL_AGENT_ID required", file=sys.stderr)
        sys.exit(2)

    profile = "chatter"
    agent_tools = tool_list(req("GET", f"/v1/agents/{agent_id}/tools"))
    detached = 0
    for t in agent_tools:
        name = t.get("name") or ""
        tid = t.get("id")
        if tid and name.startswith(TOOL_PREFIX):
            if detach_tool(agent_id, tid, name):
                detached += 1

    server_id = resolve_server_id()
    if not server_id:
        print("mcp server not found", SERVER_NAME, file=sys.stderr)
        sys.exit(1)

    mcp_tools = tool_list(req("GET", f"/v1/mcp-servers/{server_id}/tools"))
    attached = 0
    for name in sorted(CHATTER_TOOL_NAMES):
        match = next((t for t in mcp_tools if (t.get("name") or "") == name), None)
        if not match or not match.get("id"):
            print("missing on server", name, file=sys.stderr)
            continue
        try:
            req("PATCH", f"/v1/agents/{agent_id}/tools/attach/{match['id']}")
            attached += 1
            print("attached", name)
        except urllib.error.HTTPError as e:
            if e.code in (409, 200):
                attached += 1
            else:
                print("attach fail", name, e.code, file=sys.stderr)

    # Second detach pass for stragglers outside profile
    agent_tools = tool_list(req("GET", f"/v1/agents/{agent_id}/tools"))
    for t in agent_tools:
        name = t.get("name") or ""
        tid = t.get("id")
        if not tid or not name.startswith(TOOL_PREFIX):
            continue
        if not profile_allows(name, profile):
            if detach_tool(agent_id, tid, name):
                detached += 1

    final = tool_list(req("GET", f"/v1/agents/{agent_id}/tools"))
    final_q = sorted(n for n in ((t.get("name") or "") for t in final) if n.startswith(TOOL_PREFIX))
    print("detached_total", detached)
    print("attached_attempted", attached)
    print("agent_q_tool_count", len(final_q))
    if set(final_q) != CHATTER_TOOL_NAMES:
        missing = sorted(CHATTER_TOOL_NAMES - set(final_q))
        extra = sorted(set(final_q) - CHATTER_TOOL_NAMES)
        if missing:
            print("missing", missing, file=sys.stderr)
        if extra:
            print("extra", extra, file=sys.stderr)
        sys.exit(1)
    print("ok chatter profile", len(final_q), "tools")


if __name__ == "__main__":
    main()
