#!/usr/bin/env python3
"""Prepare lettatest Q_Vernal export for POST /v1/agents/import on moya (Letta 0.16.x)."""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path


def sanitize(af: dict, *, drop_labels: set[str]) -> dict:
    agents = af.get("agents") or []
    if not agents:
        raise ValueError("no agents[] in export")
    agent = agents[0]
    agent["name"] = "Q_Vernal"
    agent["agent_type"] = "letta_v1_agent"

    blocks = af.get("blocks") or []
    kept_blocks = []
    kept_ids = set()
    for block in blocks:
        label = block.get("label") or ""
        if label in drop_labels:
            continue
        kept_blocks.append(block)
        if block.get("id"):
            kept_ids.add(block["id"])

    af["blocks"] = kept_blocks

    block_ids = agent.get("block_ids") or []
    agent["block_ids"] = [bid for bid in block_ids if bid in kept_ids]

    for msg in agent.get("messages") or []:
        if msg.get("content") is None and msg.get("role") == "assistant":
            msg["content"] = []
        for part in msg.get("content") or []:
            if isinstance(part, dict) and part.get("type") == "reasoning":
                part.setdefault("is_native", True)
        if msg.get("role") == "tool":
            for part in msg.get("content") or []:
                if isinstance(part, dict) and part.get("type") == "text":
                    text = part.get("text")
                    if isinstance(text, str) and text and not text.lstrip().startswith("{"):
                        part["text"] = json.dumps({"ok": True, "message": text})

    agent.setdefault("tool_ids", [])
    agent.setdefault("source_ids", [])
    agent.setdefault("folder_ids", [])
    return af


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("input")
    ap.add_argument("output")
    ap.add_argument(
        "--drop-block-label",
        action="append",
        default=["alex_psf_shopify_install"],
        help="Ephemeral blocks to omit from import",
    )
    args = ap.parse_args()
    src = Path(args.input)
    data = json.loads(src.read_text(encoding="utf-8"))
    out = sanitize(data, drop_labels=set(args.drop_block_label))
    Path(args.output).write_text(json.dumps(out, indent=2) + "\n", encoding="utf-8")
    n = len((out.get("agents") or [{}])[0].get("messages") or [])
    print(f"wrote {args.output} messages={n} blocks={len(out.get('blocks') or [])}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
