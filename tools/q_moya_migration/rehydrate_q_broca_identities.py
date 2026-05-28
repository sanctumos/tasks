#!/usr/bin/env python3
"""Re-create Letta identities for q_vernal_webchat platform profiles after agent migration."""

from __future__ import annotations

import json
import os
import sqlite3
import sys
import uuid
import urllib.error
import urllib.request
from pathlib import Path


def api(method: str, base: str, key: str, path: str, body: dict | None = None) -> dict:
    url = f"{base.rstrip('/')}{path}"
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(
        url,
        data=data,
        method=method,
        headers={
            "Authorization": f"Bearer {key}",
            "Content-Type": "application/json",
            "Accept": "application/json",
        },
    )
    try:
        with urllib.request.urlopen(req, timeout=120) as resp:
            raw = resp.read()
            return json.loads(raw) if raw else {}
    except urllib.error.HTTPError as e:
        raise RuntimeError(f"{method} {path} HTTP {e.code}: {(e.read() or b'')[:500]}") from e


def main() -> int:
    base = os.environ.get("AGENT_ENDPOINT", "http://127.0.0.1:8284")
    key = os.environ.get("AGENT_API_KEY", "").strip()
    db_path = Path(os.environ.get("SANCTUM_DB", "sanctum.db"))
    platform = os.environ.get("WEB_CHAT_PLATFORM_NAME", "q_vernal_webchat")
    if not key:
        print("Set AGENT_API_KEY", file=sys.stderr)
        return 2
    if not db_path.is_file():
        print(f"Missing DB: {db_path}", file=sys.stderr)
        return 1

    con = sqlite3.connect(db_path)
    cur = con.cursor()
    cur.execute(
        """
        SELECT lu.id, pp.platform_user_id, pp.username, pp.display_name
        FROM letta_users lu
        JOIN platform_profiles pp ON pp.letta_user_id = lu.id
        WHERE pp.platform = ?
        ORDER BY lu.id
        """,
        (platform,),
    )
    rows = cur.fetchall()
    print(f"Rehydrating {len(rows)} {platform} letta_users …")

    ok = 0
    for user_id, platform_user_id, username, display_name in rows:
        name = display_name or username or f"User {platform_user_id}"
        parts = name.split()
        first = parts[0]
        last = " ".join(parts[1:]) if len(parts) > 1 else None
        unique = str(uuid.uuid4())[:8]
        identity = api(
            "POST",
            base,
            key,
            "/v1/identities/",
            {
                "identifier_key": f"broca_user_{unique}",
                "name": name,
                "identity_type": "user",
            },
        )
        block_lines = [f"About Me ({name})"]
        block_lines.append(f"Tasks platform_user_id: {platform_user_id}")
        if username:
            block_lines.append(f"Tasks username: {username}")
        block = api(
            "POST",
            base,
            key,
            "/v1/blocks/",
            {
                "label": "human",
                "value": json.dumps(
                    {
                        "type": "human_core",
                        "data": {
                            "name": name,
                            "created_at": __import__("datetime").datetime.utcnow().isoformat(),
                            "content": "\n".join(block_lines),
                        },
                    }
                ),
            },
        )
        identity_id = identity.get("id")
        block_id = block.get("id")
        if not identity_id or not block_id:
            raise RuntimeError(f"unexpected API response identity={identity} block={block}")
        cur.execute(
            "UPDATE letta_users SET letta_identity_id = ?, letta_block_id = ? WHERE id = ?",
            (identity_id, block_id, user_id),
        )
        ok += 1
        if ok % 10 == 0:
            print(f"  {ok}/{len(rows)}")
            con.commit()

    con.commit()
    con.close()
    print(f"Done. updated={ok}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
