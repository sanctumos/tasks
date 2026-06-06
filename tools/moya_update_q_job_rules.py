#!/usr/bin/env python3
"""Push Q_Vernal job_rules block from docs/Q-VERNAL-JOB-RULES.md to moya Letta (HTTP API only)."""

from __future__ import annotations

import json
import os
import sys
import urllib.error
import urllib.request
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from moya_attach_q_smcp import req  # noqa: E402

REPO = Path(__file__).resolve().parents[1]
DOC = Path(os.getenv("Q_JOB_RULES_MD", str(REPO / "docs" / "Q-VERNAL-JOB-RULES.md")))
AGENT_ID = os.getenv("Q_VERNAL_AGENT_ID", "agent-64e52a67-537a-4def-8402-d4bdccc47395")
BLOCK_LABEL = os.getenv("Q_JOB_RULES_BLOCK_LABEL", "job_rules")


def extract_job_rules_text(md: str) -> str:
    marker = "```text\n"
    if marker not in md:
        raise SystemExit("Could not find ```text opener in Q-VERNAL-JOB-RULES.md")
    body = md.split(marker, 1)[1]
    end = body.rfind("\n```")
    if end < 0:
        raise SystemExit("Could not find closing ``` for job_rules block")
    return body[:end].strip()


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


def find_job_rules_block_id() -> str:
    override = os.getenv("Q_JOB_RULES_BLOCK_ID", "").strip()
    if override:
        return override
    agent = req("GET", f"/v1/agents/{AGENT_ID}")
    for block in agent.get("blocks") or []:
        label = (block.get("label") or block.get("name") or "").strip()
        if label == BLOCK_LABEL and block.get("id"):
            return str(block["id"])
    raise SystemExit(f"No block labeled {BLOCK_LABEL!r} on agent {AGENT_ID}")


def main() -> None:
    load_api_key()
    if not os.getenv("LETTA_API_KEY"):
        print("LETTA_API_KEY required (or run on moya with broca .env)", file=sys.stderr)
        sys.exit(2)

    block_id = find_job_rules_block_id()
    value = extract_job_rules_text(DOC.read_text(encoding="utf-8"))
    body = json.dumps({"value": value}).encode()
    letta_base = os.getenv("LETTA_BASE", "http://127.0.0.1:8284").rstrip("/")
    req_obj = urllib.request.Request(
        f"{letta_base}/v1/blocks/{block_id}",
        data=body,
        method="PATCH",
        headers={
            "Authorization": f"Bearer {os.environ['LETTA_API_KEY']}",
            "Content-Type": "application/json",
        },
    )
    try:
        with urllib.request.urlopen(req_obj, timeout=120) as resp:
            print("PATCH", resp.status, block_id, "chars", len(value))
    except urllib.error.HTTPError as e:
        print(e.read().decode()[:2000], file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
