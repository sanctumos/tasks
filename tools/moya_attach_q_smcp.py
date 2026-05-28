#!/usr/bin/env python3
"""Register q-vernal-smcp on moya Letta and attach q_vernal_tasks__ tools to Q_Vernal."""

from __future__ import annotations

import json
import os
import sys
import urllib.error
import urllib.request
from pathlib import Path

LETTA_BASE = os.getenv("LETTA_BASE", "http://127.0.0.1:8284").rstrip("/")
AGENT_ID = os.getenv("Q_VERNAL_AGENT_ID", "")
SERVER_NAME = os.getenv("Q_SMCP_SERVER_NAME", "q-vernal-smcp")
RUN_SCRIPT = os.getenv(
    "Q_SMCP_RUN_SCRIPT",
    "/home/rizzn/sanctum/agents/q/smcp/run-smcp-stdio-for-letta.sh",
)


def req(method: str, path: str, body: dict | None = None) -> dict:
    key = os.environ["LETTA_API_KEY"]
    if body is not None:
        data = json.dumps(body).encode()
    elif method.upper() in ("POST", "PATCH", "PUT", "DELETE"):
        data = b""
    else:
        data = None
    r = urllib.request.Request(
        LETTA_BASE + path,
        data=data,
        method=method.upper(),
        headers={
            "Authorization": f"Bearer {key}",
            "Content-Type": "application/json",
        },
    )
    try:
        with urllib.request.urlopen(r, timeout=120) as resp:
            raw = resp.read().decode()
            return json.loads(raw) if raw else {}
    except urllib.error.HTTPError as e:
        print(e.read().decode()[:2000], file=sys.stderr)
        raise


def tool_list(payload: dict | list) -> list:
    if isinstance(payload, list):
        return payload
    return payload.get("tools") or payload.get("data") or []


def q_tool_count(server_id: str) -> int:
    tools = tool_list(req("GET", f"/v1/mcp-servers/{server_id}/tools"))
    return sum(1 for t in tools if (t.get("name") or "").startswith("q_vernal_tasks__"))


def main() -> None:
    if not os.getenv("LETTA_API_KEY"):
        for path in (
            Path("/home/rizzn/sanctum/agents/athena/broca/.env"),
            Path("/root/.letta/.env"),
        ):
            if not path.is_file():
                continue
            for line in path.read_text(encoding="utf-8").splitlines():
                if line.startswith("AGENT_API_KEY="):
                    os.environ["LETTA_API_KEY"] = line.split("=", 1)[1].strip().strip("\"'")
                    break
                if line.startswith("export LETTA_SERVER_PASSWORD="):
                    os.environ["LETTA_API_KEY"] = line.split("=", 1)[1].strip().strip("\"'")
                    break
    if not os.getenv("LETTA_API_KEY"):
        print("LETTA_API_KEY required", file=sys.stderr)
        sys.exit(2)
    if not AGENT_ID:
        print("Q_VERNAL_AGENT_ID required", file=sys.stderr)
        sys.exit(2)

    existing = req("GET", "/v1/mcp-servers/")
    servers = existing if isinstance(existing, list) else existing.get("mcp_servers", [])
    server_id = None
    for s in servers:
        if s.get("server_name") == SERVER_NAME:
            server_id = s.get("id")
            break

    if server_id and q_tool_count(server_id) < 45:
        print("recreate mcp server (stale catalog)", server_id)
        try:
            req("DELETE", f"/v1/mcp-servers/{server_id}")
        except urllib.error.HTTPError as e:
            print("delete skipped", e.code, file=sys.stderr)
        server_id = None
    elif server_id:
        print("reuse mcp server", server_id)

    if not server_id:
        created = req(
            "POST",
            "/v1/mcp-servers/",
            {
                "server_name": SERVER_NAME,
                "config": {
                    "mcp_server_type": "stdio",
                    "command": RUN_SCRIPT,
                    "args": [],
                },
            },
        )
        server_id = created.get("id") or created.get("mcp_server", {}).get("id")
        print("created mcp server", server_id)

    if not server_id:
        print("no server_id", file=sys.stderr)
        sys.exit(1)

    for method, path, label in (
        ("POST", f"/v1/mcp-servers/connect/{server_id}", "connect"),
        ("POST", f"/v1/mcp-servers/{server_id}/refresh", "refresh"),
    ):
        try:
            req(method, path)
            print(label, "ok")
        except urllib.error.HTTPError as e:
            print(label, "skipped", e.code)

    tools = req("GET", f"/v1/mcp-servers/{server_id}/tools")
    tool_list_data = tool_list(tools)
    print("mcp tools", len(tool_list_data))

    attached = 0
    for t in tool_list_data:
        name = t.get("name") or ""
        if not name.startswith("q_vernal_tasks__"):
            continue
        tid = t.get("id")
        if not tid:
            continue
        try:
            req("PATCH", f"/v1/agents/{AGENT_ID}/tools/attach/{tid}")
            attached += 1
        except urllib.error.HTTPError as e:
            if e.code in (409, 200):
                attached += 1
            else:
                print("attach fail", name, e.code, file=sys.stderr)
    print("attached", attached, "q_vernal_tasks tools to", AGENT_ID)


if __name__ == "__main__":
    main()
