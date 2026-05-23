#!/usr/bin/env python3
"""Push Q_Vernal job_rules block from docs/Q-VERNAL-JOB-RULES.md to lettatest Letta."""

from __future__ import annotations

import json
import os
import re
import sys
import urllib.error
import urllib.request
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
DOC = Path(os.getenv("Q_JOB_RULES_MD", str(REPO / "docs" / "Q-VERNAL-JOB-RULES.md")))
DEFAULT_BLOCK_ID = "block-2f30b44c-ad4c-46ba-b57e-268baa28a5f5"
LETTA_BASE = os.getenv("LETTA_BASE", "http://127.0.0.1:18283").rstrip("/")


def extract_job_rules_text(md: str) -> str:
    """Extract the single ```text … ``` block (may contain inner ``` fences in examples)."""
    marker = "```text\n"
    if marker not in md:
        raise SystemExit("Could not find ```text opener in Q-VERNAL-JOB-RULES.md")
    body = md.split(marker, 1)[1]
    # Closing fence is the last ``` line in the file (outer wrapper).
    end = body.rfind("\n```")
    if end < 0:
        raise SystemExit("Could not find closing ``` for job_rules block")
    return body[:end].strip()


def main() -> None:
    key = os.getenv("LETTA_API_KEY") or os.getenv("AGENT_API_KEY")
    if not key:
        print("LETTA_API_KEY or AGENT_API_KEY required", file=sys.stderr)
        sys.exit(2)
    block_id = os.getenv("Q_JOB_RULES_BLOCK_ID", DEFAULT_BLOCK_ID)
    value = extract_job_rules_text(DOC.read_text(encoding="utf-8"))
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
