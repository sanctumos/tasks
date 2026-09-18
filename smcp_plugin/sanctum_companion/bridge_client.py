"""POST companion ui_event to Q bridge (poll bearer). Never log Authorization."""

from __future__ import annotations

import json
import os
import urllib.error
import urllib.request
from typing import Any, Dict, Optional


class BridgeClientError(Exception):
    def __init__(self, message: str, *, error_type: str = "bridge") -> None:
        super().__init__(message)
        self.error_type = error_type


def bridge_base_url() -> str:
    for var in ("SANCTUM_COMPANION_BRIDGE_URL", "TASKS_Q_BRIDGE_API_URL"):
        raw = os.getenv(var, "").strip().rstrip("/")
        if raw:
            return raw
    return ""


def poll_api_key() -> str:
    for var in ("SANCTUM_COMPANION_POLL_API_KEY", "TASKS_Q_BRIDGE_POLL_API_KEY"):
        raw = os.getenv(var, "").strip()
        if raw:
            return raw
    return ""


def _redact_http_detail(detail: str) -> str:
    """Strip bearer tokens if a gateway echoed them into an error body."""
    lower = detail.lower()
    if "authorization" in lower or "bearer " in lower:
        return "[redacted]"
    return detail[:500]


def post_ui_event(
    session_id: str,
    event: Dict[str, Any],
    *,
    base_url: Optional[str] = None,
    api_key: Optional[str] = None,
    timeout: float = 30.0,
) -> Dict[str, Any]:
    """
    POST ?action=ui_event with poll Bearer auth.

    Returns the bridge success `data` object. Never logs the Authorization header.
    """
    base = (base_url if base_url is not None else bridge_base_url()).rstrip("/")
    poll = (api_key if api_key is not None else poll_api_key()).strip()
    if not base or not poll:
        raise BridgeClientError(
            "SANCTUM_COMPANION_BRIDGE_URL (or TASKS_Q_BRIDGE_API_URL) and "
            "SANCTUM_COMPANION_POLL_API_KEY (or TASKS_Q_BRIDGE_POLL_API_KEY) must be set"
        )
    if not session_id or not isinstance(event, dict):
        raise BridgeClientError("session_id and event are required")

    url = base + "/api/v1/index.php?action=ui_event"
    body = json.dumps({"session_id": session_id, "event": event}).encode("utf-8")
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
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            payload = json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        detail = _redact_http_detail(e.read().decode("utf-8", errors="replace"))
        raise BridgeClientError(f"ui_event HTTP {e.code}: {detail}") from e
    except urllib.error.URLError as e:
        # Do not include request headers; reason only.
        raise BridgeClientError(f"ui_event unreachable: {e.reason}") from e
    except json.JSONDecodeError as e:
        raise BridgeClientError("ui_event returned invalid JSON") from e

    if not isinstance(payload, dict) or not payload.get("success"):
        msg = "ui_event failed"
        if isinstance(payload, dict):
            msg = str(payload.get("message") or msg)
        raise BridgeClientError(msg)

    data = payload.get("data")
    return data if isinstance(data, dict) else {}
