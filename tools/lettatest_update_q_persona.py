#!/usr/bin/env python3
"""Push Q_Vernal persona block to lettatest Letta."""

from __future__ import annotations

import json
import os
import sys
import urllib.error
import urllib.request

DEFAULT_BLOCK_ID = "block-5a350bba-388c-4f4d-bebb-07d03bc989e7"
LETTA_BASE = os.getenv("LETTA_BASE", "http://127.0.0.1:18283").rstrip("/")
PERSONA_FILE = os.getenv(
    "Q_PERSONA_FILE",
    os.path.join(os.path.dirname(__file__), "..", "docs", "Q-VERNAL-PERSONA-CANONICAL.txt"),
)


def main() -> None:
    key = os.getenv("LETTA_API_KEY") or os.getenv("AGENT_API_KEY")
    if not key:
        print("LETTA_API_KEY or AGENT_API_KEY required", file=sys.stderr)
        sys.exit(2)
    block_id = os.getenv("Q_PERSONA_BLOCK_ID", DEFAULT_BLOCK_ID)
    path = os.getenv("Q_PERSONA_TEXT_FILE", PERSONA_FILE)
    value = open(path, encoding="utf-8").read().strip()
    body = json.dumps({"value": value}).encode()
    req = urllib.request.Request(
        f"{LETTA_BASE}/v1/blocks/{block_id}",
        data=body,
        method="PATCH",
        headers={
            "Authorization": f"Bearer {key}",
            "Content-Type": "application/json",
        },
    )
    try:
        with urllib.request.urlopen(req, timeout=60) as resp:
            print("PATCH", resp.status, block_id, "chars", len(value))
    except urllib.error.HTTPError as e:
        print(e.read().decode()[:2000], file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
