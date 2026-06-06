#!/usr/bin/env python3
"""
Optional: mirror SMCP chatter/admin tool names to a Letta agent tool_ids (HTTP API only).

Used when Letta env is present alongside SMCP profile bootstrapping.
"""

from __future__ import annotations

import argparse
import os
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from moya_attach_q_smcp import CHATTER_TOOL_NAMES, req, tool_list  # noqa: E402
from moya_patch_q_chatter_tool_ids import LETTA_BUILTIN_KEEP, load_api_key  # noqa: E402

PROFILE_TOOLS = {
    "chatter": CHATTER_TOOL_NAMES,
    "admin": None,  # all q_vernal_tasks__*
    "full": None,
}


def tools_for_profile(profile: str, by_name: dict[str, str]) -> list[str]:
    profile = profile.strip().lower()
    if profile == "chatter":
        names = sorted(PROFILE_TOOLS["chatter"])
    else:
        names = sorted(n for n in by_name if n.startswith("q_vernal_tasks__"))
    return [by_name[n] for n in names if n in by_name]


def main() -> None:
    parser = argparse.ArgumentParser(description="Sync Letta agent tools to SMCP_ATTACH_PROFILE")
    parser.add_argument("--agent-id", default=os.getenv("Q_VERNAL_AGENT_ID", ""))
    parser.add_argument("--profile", default=os.getenv("SMCP_ATTACH_PROFILE", "chatter"))
    args = parser.parse_args()
    if not args.agent_id:
        print("--agent-id or Q_VERNAL_AGENT_ID required", file=sys.stderr)
        sys.exit(2)

    load_api_key()
    if not os.getenv("LETTA_API_KEY"):
        print("LETTA_API_KEY required", file=sys.stderr)
        sys.exit(2)

    payload = req("GET", "/v1/tools/?limit=500")
    by_name = {t["name"]: t["id"] for t in tool_list(payload) if t.get("name") and t.get("id")}
    ids = tools_for_profile(args.profile, by_name)

    agent = req("GET", f"/v1/agents/{args.agent_id}")
    keep = [t["id"] for t in (agent.get("tools") or []) if (t.get("name") or "") in LETTA_BUILTIN_KEEP]
    req("PATCH", f"/v1/agents/{args.agent_id}", {"tool_ids": keep + ids})
    print(f"PATCH {args.agent_id} profile={args.profile} tools={len(ids)} + builtins={len(keep)}")


if __name__ == "__main__":
    main()
