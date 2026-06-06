#!/usr/bin/env python3
"""PATCH Q_Vernal tool_ids on moya — chatter profile + Letta memory tools."""

from __future__ import annotations

import json
import os
import sys
import urllib.error
import urllib.request
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from moya_attach_q_smcp import (  # noqa: E402
    CHATTER_TOOL_NAMES,
    TOOL_PREFIX,
    req,
    tool_list,
)

AGENT_ID = os.getenv("Q_VERNAL_AGENT_ID", "agent-64e52a67-537a-4def-8402-d4bdccc47395")
LETTA_BUILTIN_KEEP = frozenset({"conversation_search", "memory_insert", "memory_replace"})


def load_api_key() -> None:
    if os.getenv("LETTA_API_KEY"):
        return
    for path in (Path("/home/rizzn/sanctum/agents/athena/broca/.env"),):
        if not path.is_file():
            continue
        for line in path.read_text(encoding="utf-8").splitlines():
            if line.startswith("AGENT_API_KEY="):
                os.environ["LETTA_API_KEY"] = line.split("=", 1)[1].strip().strip("\"'")
                return


def org_tools_by_name() -> dict[str, str]:
    payload = req("GET", "/v1/tools/?limit=500")
    tools = tool_list(payload)
    return {t["name"]: t["id"] for t in tools if t.get("name") and t.get("id")}


def main() -> None:
    load_api_key()
    if not os.getenv("LETTA_API_KEY"):
        print("LETTA_API_KEY required", file=sys.stderr)
        sys.exit(2)

    by_name = org_tools_by_name()
    chatter_ids = []
    missing = []
    for name in sorted(CHATTER_TOOL_NAMES):
        tid = by_name.get(name)
        if tid:
            chatter_ids.append(tid)
        else:
            missing.append(name)
    if missing:
        print("missing org tools:", missing, file=sys.stderr)
        sys.exit(1)

    agent = req("GET", f"/v1/agents/{AGENT_ID}")
    embedded = agent.get("tools") or []
    keep_ids = [
        t["id"]
        for t in embedded
        if (t.get("name") or "") in LETTA_BUILTIN_KEEP and t.get("id")
    ]
    new_tool_ids = keep_ids + chatter_ids

    updated = req(
        "PATCH",
        f"/v1/agents/{AGENT_ID}",
        {"tool_ids": new_tool_ids},
    )

    verify = req("GET", f"/v1/agents/{AGENT_ID}")
    names = sorted(
        (t.get("name") or "")
        for t in (verify.get("tools") or [])
        if (t.get("name") or "").startswith(TOOL_PREFIX)
    )
    print("patched tool_ids", len(new_tool_ids), "(builtin", len(keep_ids), "+ chatter", len(chatter_ids), ")")
    print("verify q count", len(names))
    if set(names) != {n for n in CHATTER_TOOL_NAMES}:
        print("verify names", names)
        sys.exit(1)
    print("ok", names)


if __name__ == "__main__":
    main()
