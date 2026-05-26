#!/usr/bin/env python3
"""Detach stray tools from Q_Vernal, refresh MCP catalog, attach all q_vernal_tasks__ tools."""

from __future__ import annotations

import json
import os
import sys
import urllib.error
import urllib.request

LETTA_BASE = os.getenv("LETTA_BASE", "http://127.0.0.1:18283").rstrip("/")
AGENT_ID = os.getenv("Q_VERNAL_AGENT_ID", "agent-4afbed9b-a6c0-403f-8499-4fb75b83c095")
SERVER_NAME = os.getenv("Q_SMCP_SERVER_NAME", "q-vernal-smcp")
KEEP_PREFIX = "q_vernal_tasks__"


def req(method: str, path: str, body: dict | None = None) -> dict | list:
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
    ctx = __import__("ssl").create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = __import__("ssl").CERT_NONE
    try:
        with urllib.request.urlopen(r, timeout=120, context=ctx) as resp:
            raw = resp.read().decode()
            return json.loads(raw) if raw else {}
    except urllib.error.HTTPError as e:
        body_txt = e.read().decode()[:2000]
        print(f"HTTP {e.code} {method} {path}: {body_txt}", file=sys.stderr)
        raise


def tool_list(payload: dict | list) -> list:
    if isinstance(payload, list):
        return payload
    return payload.get("tools") or payload.get("data") or []


def main() -> None:
    if not os.getenv("LETTA_API_KEY"):
        print("LETTA_API_KEY required", file=sys.stderr)
        sys.exit(2)

    # Detach non-q_vernal tools from agent
    agent_tools = tool_list(req("GET", f"/v1/agents/{AGENT_ID}/tools"))
    detached = 0
    for t in agent_tools:
        name = t.get("name") or ""
        tid = t.get("id")
        if not tid or name.startswith(KEEP_PREFIX):
            continue
        for path in (
            f"/v1/agents/{AGENT_ID}/tools/detach/{tid}",
            f"/v1/agents/{AGENT_ID}/tools/{tid}/detach",
        ):
            try:
                req("PATCH", path)
                detached += 1
                print("detached", name)
                break
            except urllib.error.HTTPError:
                continue
        else:
            print("warn: could not detach", name, file=sys.stderr)

    servers = req("GET", "/v1/mcp-servers/")
    server_list = servers if isinstance(servers, list) else servers.get("mcp_servers", [])
    server_id = None
    for s in server_list:
        if s.get("server_name") == SERVER_NAME:
            server_id = s.get("id")
            break
    if not server_id:
        print("mcp server not found:", SERVER_NAME, file=sys.stderr)
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

    mcp_tools = tool_list(req("GET", f"/v1/mcp-servers/{server_id}/tools"))
    doc_tools = [t.get("name") for t in mcp_tools if "document" in (t.get("name") or "")]
    print("mcp tool count", len(mcp_tools))
    print("document tools on server", doc_tools)

    attached = 0
    for t in mcp_tools:
        name = t.get("name") or ""
        if not name.startswith(KEEP_PREFIX):
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
                print("attach fail", name, file=sys.stderr)

    final = tool_list(req("GET", f"/v1/agents/{AGENT_ID}/tools"))
    final_names = sorted(t.get("name") or "" for t in final)
    print("detached_stray", detached)
    print("attached_q_vernal", attached)
    print("agent_tool_count", len(final_names))
    print("agent_document_tools", [n for n in final_names if "document" in n])


if __name__ == "__main__":
    main()
