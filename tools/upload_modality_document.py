#!/usr/bin/env python3
"""Upload MODALITY doc to Tasks Document Library (project 6) and upgrade project (10)."""
from __future__ import annotations

import json
import os
import urllib.parse
import urllib.request
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[1]
DOC_PATH = REPO_ROOT / "docs" / "MODALITY-EMBEDDED-AGENT-CHAT-IN-SANCTUM-APP.md"
TITLE = "Software modality — embedded agent chat in a Sanctum app (omnibus)"
DIR_P6 = "sanctum-modality"
DIR_P10 = ""


def api(method: str, path: str, payload: dict | None = None) -> dict:
    base = os.environ["TASKS_DSC_BASE_URL"].rstrip("/")
    key = os.environ["TASKS_DSC_OTTOVERNAL_API_KEY"]
    headers = {"X-API-Key": key, "Content-Type": "application/json"}
    data = json.dumps(payload).encode() if payload is not None else None
    req = urllib.request.Request(
        base + path, data=data, method=method, headers=headers
    )
    with urllib.request.urlopen(req, timeout=120) as resp:
        return json.loads(resp.read().decode())


def find_existing(project_id: int, directory_path: str, title: str) -> int | None:
    q = f"/api/list-documents.php?project_id={project_id}"
    if directory_path:
        q += f"&directory_path={urllib.parse.quote(directory_path)}"
    out = api("GET", q)
    docs = out.get("documents") or out.get("data", {}).get("documents") or []
    for d in docs:
        if (d.get("title") or "").strip() == title:
            return int(d["id"])
    return None


def upsert(project_id: int, directory_path: str, body: str) -> int:
    existing = find_existing(project_id, directory_path, TITLE)
    payload = {
        "project_id": project_id,
        "title": TITLE,
        "body": body,
        "directory_path": directory_path,
    }
    if existing:
        payload["id"] = existing
        r = api("POST", "/api/update-document.php", payload)
        doc_id = existing
    else:
        r = api("POST", "/api/create-document.php", payload)
        doc = r.get("document") or r.get("data", {}).get("document") or {}
        doc_id = int(doc.get("id") or r.get("id") or 0)
    if not r.get("success", True):
        raise SystemExit(f"API failed: {r}")
    return doc_id


def main() -> None:
    body = DOC_PATH.read_text(encoding="utf-8")
    print(f"body chars: {len(body)}")
    id6 = upsert(6, DIR_P6, body)
    print(f"project 6 document id={id6} directory={DIR_P6!r}")
    stub = (
        "# Pointer — embedded agent chat modality (omnibus)\n\n"
        "The full **software modality template** (start-to-finish Q Vernal leg, "
        "replication recipe for other Sanctum apps) lives in the **Document Library**:\n\n"
        f"- **Project 6** · directory `sanctum-modality/` · document **#{id6}**\n"
        "- **Git:** `sanctum-tasks/docs/MODALITY-EMBEDDED-AGENT-CHAT-IN-SANCTUM-APP.md`\n\n"
        "See also: persona [#295](https://tasks.decisionsciencecorp.com/admin/document.php?id=295), "
        "plan [#296](https://tasks.decisionsciencecorp.com/admin/document.php?id=296).\n"
    )
    id10 = upsert(10, DIR_P10, stub)
    print(f"project 10 stub id={id10}")


if __name__ == "__main__":
    main()
