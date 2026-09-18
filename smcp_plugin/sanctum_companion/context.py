"""Read and validate Broca turn-scoped active-turn context (fail closed)."""

from __future__ import annotations

import json
import os
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Optional, Set

ACTIVE_TURN_SCHEMA = "sanctum.broca.active-turn"
ACTIVE_TURN_VERSION = 1
DEFAULT_ALLOWED_PLATFORMS: Set[str] = {"q_vernal_webchat"}
DEFAULT_ACTIVE_TURN_PATH = "/opt/broca-q/run/active_turn.json"


class ActiveTurnError(Exception):
    """Active-turn missing, malformed, expired, or wrong platform."""

    def __init__(self, message: str, *, error_type: str = "context") -> None:
        super().__init__(message)
        self.error_type = error_type


@dataclass(frozen=True)
class ActiveTurn:
    turn_id: str
    platform: str
    broca_message_id: int
    session_id: str
    issued_at: datetime
    expires_at: datetime
    tasks_user_id: Optional[int] = None


def active_turn_path() -> Path:
    raw = os.getenv("BROCA_ACTIVE_TURN_FILE", "").strip()
    return Path(raw or DEFAULT_ACTIVE_TURN_PATH)


def allowed_platforms() -> Set[str]:
    raw = os.getenv("SANCTUM_COMPANION_ALLOWED_PLATFORMS", "").strip()
    if not raw:
        return set(DEFAULT_ALLOWED_PLATFORMS)
    return {p.strip() for p in raw.split(",") if p.strip()}


def _parse_iso8601(value: Any, field: str) -> datetime:
    if not isinstance(value, str) or not value.strip():
        raise ActiveTurnError(f"active-turn {field} must be an ISO8601 string")
    text = value.strip()
    if text.endswith("Z"):
        text = text[:-1] + "+00:00"
    try:
        dt = datetime.fromisoformat(text)
    except ValueError as e:
        raise ActiveTurnError(f"active-turn {field} is not valid ISO8601") from e
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=timezone.utc)
    return dt.astimezone(timezone.utc)


def validate_active_turn_dict(
    data: Any,
    *,
    now: Optional[datetime] = None,
    platforms: Optional[Set[str]] = None,
) -> ActiveTurn:
    """Validate a parsed active-turn object. Raises ActiveTurnError on any failure."""
    if not isinstance(data, dict):
        raise ActiveTurnError("active-turn must be a JSON object")

    if data.get("schema") != ACTIVE_TURN_SCHEMA:
        raise ActiveTurnError("active-turn schema mismatch")
    if data.get("version") != ACTIVE_TURN_VERSION:
        raise ActiveTurnError("active-turn version mismatch")

    turn_id = data.get("turn_id")
    if not isinstance(turn_id, str) or not turn_id.strip():
        raise ActiveTurnError("active-turn turn_id missing")

    platform = data.get("platform")
    if not isinstance(platform, str) or not platform.strip():
        raise ActiveTurnError("active-turn platform missing")
    allowed = platforms if platforms is not None else allowed_platforms()
    if platform.strip() not in allowed:
        raise ActiveTurnError("active-turn platform not allowed")

    mid = data.get("broca_message_id")
    if type(mid) is not int or isinstance(mid, bool) or mid < 1:
        raise ActiveTurnError("active-turn broca_message_id must be a positive int")

    session_id = data.get("session_id")
    if not isinstance(session_id, str) or not session_id.strip():
        raise ActiveTurnError("active-turn session_id missing")

    issued_at = _parse_iso8601(data.get("issued_at"), "issued_at")
    expires_at = _parse_iso8601(data.get("expires_at"), "expires_at")
    if expires_at <= issued_at:
        raise ActiveTurnError("active-turn expires_at must be after issued_at")

    now_utc = (now or datetime.now(timezone.utc)).astimezone(timezone.utc)
    if expires_at <= now_utc:
        raise ActiveTurnError("active-turn expired", error_type="context_expired")

    tasks_user_id: Optional[int] = None
    if "tasks_user_id" in data and data["tasks_user_id"] is not None:
        uid = data["tasks_user_id"]
        if type(uid) is not int or isinstance(uid, bool) or uid < 1:
            raise ActiveTurnError("active-turn tasks_user_id must be a positive int")
        tasks_user_id = uid

    return ActiveTurn(
        turn_id=turn_id.strip(),
        platform=platform.strip(),
        broca_message_id=mid,
        session_id=session_id.strip(),
        issued_at=issued_at,
        expires_at=expires_at,
        tasks_user_id=tasks_user_id,
    )


def read_active_turn(
    *,
    path: Optional[Path] = None,
    now: Optional[datetime] = None,
    platforms: Optional[Set[str]] = None,
) -> ActiveTurn:
    """Load active-turn JSON from disk and validate. Fail closed."""
    target = path or active_turn_path()
    if not target.is_file():
        raise ActiveTurnError("no active-turn context file")
    try:
        raw = target.read_text(encoding="utf-8")
        data = json.loads(raw)
    except (OSError, UnicodeError, json.JSONDecodeError) as e:
        raise ActiveTurnError("active-turn unreadable or invalid JSON") from e
    return validate_active_turn_dict(data, now=now, platforms=platforms)


def tasks_user_id_from_active_turn(
    *,
    path: Optional[Path] = None,
    now: Optional[datetime] = None,
) -> Optional[int]:
    """Return tasks_user_id from a valid active-turn, or None if absent/invalid."""
    try:
        turn = read_active_turn(path=path, now=now)
    except ActiveTurnError:
        return None
    return turn.tasks_user_id
