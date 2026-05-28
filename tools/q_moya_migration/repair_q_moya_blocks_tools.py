#!/usr/bin/env python3
"""Copy Q_Vernal core-memory blocks, SMCP tools, and llm_config from lettatest → moya."""

from __future__ import annotations

import json
import os
import sys
import urllib.error
import urllib.request

SRC_BASE = os.environ.get("SRC_BASE", "http://127.0.0.1:18283")
TGT_BASE = os.environ.get("TGT_BASE", "http://127.0.0.1:8284")
SRC_KEY = os.environ["SRC_KEY"]
TGT_KEY = os.environ["TGT_KEY"]
SRC_ID = os.environ.get("SRC_AGENT_ID", "agent-4afbed9b-a6c0-403f-8499-4fb75b83c095")
TGT_ID = os.environ["TGT_AGENT_ID"]
SKIP_LABELS = {x.strip() for x in os.environ.get("SKIP_BLOCK_LABELS", "alex_psf_shopify_install").split(",") if x.strip()}


def req(method: str, base: str, key: str, path: str, data=None):
    body = json.dumps(data).encode() if data is not None else None
    headers = {"Authorization": f"Bearer {key}", "Content-Type": "application/json"}
    request = urllib.request.Request(f"{base.rstrip('/')}{path}", data=body, headers=headers, method=method)
    try:
        with urllib.request.urlopen(request, timeout=180) as resp:
            raw = resp.read()
            return json.loads(raw) if raw else None
    except urllib.error.HTTPError as e:
        raise RuntimeError(f"{method} {path} HTTP {e.code}: {(e.read() or b'')[:800]}") from e


def main() -> int:
    src_blocks = req("GET", SRC_BASE, SRC_KEY, f"/v1/agents/{SRC_ID}/core-memory/blocks")
    src_agent = req("GET", SRC_BASE, SRC_KEY, f"/v1/agents/{SRC_ID}")
    src_tools = req("GET", SRC_BASE, SRC_KEY, f"/v1/agents/{SRC_ID}/tools")
    src_tool_names = [t["name"] for t in (src_tools or []) if t.get("name")]

    existing = {
        b["label"]: b["id"]
        for b in req("GET", TGT_BASE, TGT_KEY, f"/v1/agents/{TGT_ID}/core-memory/blocks")
    }
    block_ids: list[str] = []
    for block in src_blocks:
        label = block["label"]
        if label in SKIP_LABELS:
            print(f"skip block: {label}")
            continue
        if label in existing:
            req(
                "PATCH",
                TGT_BASE,
                TGT_KEY,
                f"/v1/blocks/{existing[label]}",
                {"value": block["value"]},
            )
            block_ids.append(existing[label])
            print(f"block updated: {label} ({len(block.get('value') or '')} chars)")
            continue
        created = req(
            "POST",
            TGT_BASE,
            TGT_KEY,
            "/v1/blocks/",
            {
                "label": label,
                "value": block["value"],
                "description": block.get("description") or "",
                "limit": block.get("limit") or 20000,
            },
        )
        block_ids.append(created["id"])
        print(f"block created: {label}")

    by_name = {t["name"]: t["id"] for t in req("GET", TGT_BASE, TGT_KEY, "/v1/tools/")}
    tool_ids = [by_name[n] for n in src_tool_names if n in by_name]
    missing = [n for n in src_tool_names if n not in by_name]

    updated = req(
        "PATCH",
        TGT_BASE,
        TGT_KEY,
        f"/v1/agents/{TGT_ID}",
        {
            "name": "Q_Vernal",
            "block_ids": block_ids,
            "tool_ids": tool_ids,
            "llm_config": src_agent.get("llm_config"),
            "model": src_agent.get("model") or "openai/gpt-4o",
        },
    )

    verify_blocks = req("GET", TGT_BASE, TGT_KEY, f"/v1/agents/{TGT_ID}/core-memory/blocks")
    print("VERIFY blocks:", [(b["label"], len(b.get("value") or "")) for b in verify_blocks])
    print("VERIFY tools:", len(updated.get("tools") or tool_ids), "attached")
    if missing:
        print("MISSING tools on target (install SMCP first):", missing[:10], file=sys.stderr)
        if len(missing) == len(src_tool_names):
            return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
