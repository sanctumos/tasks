"""Resolve per-chatter Tasks API key via Q bridge (poll auth only)."""

from __future__ import annotations

import json
import os
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Optional

_ACTIVE_TURN_SCHEMA = "sanctum.broca.active-turn"
_ACTIVE_TURN_VERSION = 1
_DEFAULT_ACTIVE_TURN_PATH = "/opt/broca-q/run/active_turn.json"
_DEFAULT_LEGACY_CHATTER_PATH = "/opt/broca-q/run/current_tasks_user_id.txt"


def _parse_iso8601(value: Any) -> Optional[datetime]:
    if not isinstance(value, str) or not value.strip():
        return None
    text = value.strip()
    if text.endswith("Z"):
        text = text[:-1] + "+00:00"
    try:
        dt = datetime.fromisoformat(text)
    except ValueError:
        return None
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=timezone.utc)
    return dt.astimezone(timezone.utc)


def _tasks_user_id_from_active_turn() -> Optional[int]:
    """Prefer Broca active-turn JSON (Track B). Fail closed on bad/stale context."""
    path = Path(
        os.getenv("BROCA_ACTIVE_TURN_FILE", "").strip() or _DEFAULT_ACTIVE_TURN_PATH
    )
    if not path.is_file():
        return None
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError):
        return None
    if not isinstance(data, dict):
        return None
    if data.get("schema") != _ACTIVE_TURN_SCHEMA or data.get("version") != _ACTIVE_TURN_VERSION:
        return None
    platform = data.get("platform")
    if platform != "q_vernal_webchat":
        return None
    expires_at = _parse_iso8601(data.get("expires_at"))
    if expires_at is None or expires_at <= datetime.now(timezone.utc):
        return None
    uid = data.get("tasks_user_id")
    if type(uid) is not int or isinstance(uid, bool) or uid < 1:
        return None
    return uid


def _tasks_user_id_from_legacy_file() -> Optional[int]:
    """Legacy ingest-time chatter file (transition fallback)."""
    raw = os.getenv("TASKS_Q_CHATTER_USER_ID", "").strip()
    if not raw:
        path = os.getenv("TASKS_Q_CHATTER_FILE", _DEFAULT_LEGACY_CHATTER_PATH)
        if os.path.isfile(path):
            raw = open(path, encoding="utf-8").read().strip()
    if not raw:
        return None
    try:
        uid = int(raw)
        return uid if uid > 0 else None
    except ValueError:
        return None


def chatter_user_id_from_context() -> Optional[int]:
    """
    Trusted chatter id for Q Tasks SMCP.

    Prefer Broca active-turn JSON `tasks_user_id` (turn-scoped). Fall back to the
    legacy env/file during transition.
    """
    from_turn = _tasks_user_id_from_active_turn()
    if from_turn is not None:
        return from_turn
    return _tasks_user_id_from_legacy_file()


def resolve_tasks_api_key(tasks_user_id: int) -> str:
    base = os.getenv("TASKS_Q_BRIDGE_API_URL", "").rstrip("/")
    poll = os.getenv("TASKS_Q_BRIDGE_POLL_API_KEY", "").strip()
    if not base or not poll:
        raise RuntimeError(
            "TASKS_Q_BRIDGE_API_URL and TASKS_Q_BRIDGE_POLL_API_KEY must be set for q_vernal_tasks SMCP"
        )
    url = base + "/api/v1/index.php?action=resolve_user_key"
    body = json.dumps({"tasks_user_id": tasks_user_id}).encode()
    req = urllib.request.Request(
        url,
        data=body,
        method="POST",
        headers={
            "Authorization": f"Bearer {poll}",
            "Content-Type": "application/json",
        },
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            payload = json.loads(resp.read().decode())
    except urllib.error.HTTPError as e:
        detail = e.read().decode()[:500]
        raise RuntimeError(f"resolve_user_key HTTP {e.code}: {detail}") from e
    if not payload.get("success"):
        raise RuntimeError(payload.get("message") or "resolve_user_key failed")
    data = payload.get("data") or {}
    key = data.get("api_key")
    if not key:
        raise RuntimeError("resolve_user_key returned no api_key")
    return str(key)
