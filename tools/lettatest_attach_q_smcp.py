#!/usr/bin/env python3
"""Register q-vernal-smcp on lettatest Letta and attach tools to Q_Vernal."""

from __future__ import annotations

import json
import os
import sys
import urllib.error
import urllib.request

LETTA_BASE = os.getenv("LETTA_BASE", "http://127.0.0.1:18283").rstrip("/")
AGENT_ID = os.getenv("Q_VERNAL_AGENT_ID", "agent-4afbed9b-a6c0-403f-8499-4fb75b83c095")
SERVER_NAME = os.getenv("Q_SMCP_SERVER_NAME", "q-vernal-smcp")
RUN_SCRIPT = os.getenv(
    "Q_SMCP_RUN_SCRIPT", "/opt/sanctum-q/smcp/run-smcp-stdio-for-letta.sh"
)


def req(method: str, path: str, body: dict | None = None) -> dict:
    key = os.environ["LETTA_API_KEY"]
    if body is not None:
        data = json.dumps(body).encode()
    elif method.upper() in ("POST", "PATCH", "PUT"):
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
    ctx = __import__("ssl").create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = __import__("ssl").CERT_NONE
    try:
        with urllib.request.urlopen(r, timeout=120, context=ctx) as resp:
            raw = resp.read().decode()
            return json.loads(raw) if raw else {}
    except urllib.error.HTTPError as e:
        print(e.read().decode()[:2000], file=sys.stderr)
        raise


def main() -> None:
    if not os.getenv("LETTA_API_KEY"):
        print("LETTA_API_KEY required", file=sys.stderr)
        sys.exit(2)

    existing = req("GET", "/v1/mcp-servers/")
    servers = existing if isinstance(existing, list) else existing.get("mcp_servers", [])
    server_id = None
    for s in servers:
        if s.get("server_name") == SERVER_NAME:
            server_id = s.get("id")
            print("reuse mcp server", server_id)
            break

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
    tool_list = tools if isinstance(tools, list) else tools.get("tools", tools.get("data", []))
    print("mcp tools", len(tool_list))

    attached = 0
    for t in tool_list:
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
                body = e.read().decode()[:200]
                print("attach fail", name, e.code, body, file=sys.stderr)
    print("attached", attached, "q_vernal_tasks tools to", AGENT_ID)


if __name__ == "__main__":
    main()
