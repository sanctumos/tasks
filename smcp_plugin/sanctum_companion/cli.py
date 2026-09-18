#!/usr/bin/env python3
"""
Sanctum Companion SMCP — canvas initiation (Track B).

Tool: sanctum_companion__open_canvas

Model-visible params only: optional title (max 120 plain), optional surface (primary).
Never accepts user id, session id, URL, HTML, bearer, or event id from the model.
Session comes from Broca active-turn context; event_id is generated server-side.
"""

from __future__ import annotations

import argparse
import json
import re
import secrets
import sys
from pathlib import Path
from typing import Any, Dict, List, Optional

_PLUGIN_ROOT = Path(__file__).resolve().parent
sys.path.insert(0, str(_PLUGIN_ROOT))

from bridge_client import BridgeClientError, post_ui_event  # noqa: E402
from context import ActiveTurnError, read_active_turn  # noqa: E402

PLUGIN_VERSION = "0.1.0"
UI_EVENT_SCHEMA = "sanctum.companion.ui-event"
TITLE_FORBIDDEN = re.compile(r"[<>&]")


def _error(error: str, error_type: str) -> Dict[str, Any]:
    return {"status": "error", "error": error, "error_type": error_type}


def _success(**data: Any) -> Dict[str, Any]:
    out: Dict[str, Any] = {"status": "success"}
    out.update(data)
    return out


def new_event_id() -> str:
    return "evt_" + secrets.token_hex(16)


def normalize_title(raw: Optional[str]) -> Optional[str]:
    if raw is None:
        return None
    title = str(raw).strip()
    if not title:
        return None
    if len(title) > 120:
        raise ValueError("title must be at most 120 characters")
    if TITLE_FORBIDDEN.search(title):
        raise ValueError("title must be plain text (no HTML markup)")
    return title


def build_canvas_open_event(
    *,
    title: Optional[str] = None,
    surface: str = "primary",
    event_id: Optional[str] = None,
) -> Dict[str, Any]:
    if surface != "primary":
        raise ValueError("surface must be primary")
    norm_title = normalize_title(title)
    payload: Dict[str, Any] = {"surface": "primary"}
    if norm_title is not None:
        payload["title"] = norm_title
    return {
        "schema": UI_EVENT_SCHEMA,
        "version": 1,
        "event_id": event_id or new_event_id(),
        "type": "canvas.open",
        "payload": payload,
    }


def open_canvas(*, title: Optional[str] = None, surface: str = "primary") -> Dict[str, Any]:
    try:
        turn = read_active_turn()
    except ActiveTurnError as e:
        return _error(str(e), getattr(e, "error_type", "context"))

    try:
        event = build_canvas_open_event(title=title, surface=surface)
    except ValueError as e:
        return _error(str(e), "argument_error")

    try:
        data = post_ui_event(turn.session_id, event)
    except BridgeClientError as e:
        return _error(str(e), getattr(e, "error_type", "bridge"))

    return _success(
        event_id=event["event_id"],
        session_id=turn.session_id,
        bridge=data,
    )


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Sanctum Companion SMCP — canvas open (Track B)",
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument(
        "--describe",
        action="store_true",
        help="JSON plugin + command schema for MCP",
    )
    parser.add_argument("--debug", action="store_true", help="Reserved; unused")
    sub = parser.add_subparsers(dest="command", help="Command")

    p = sub.add_parser(
        "open_canvas",
        help="Open empty companion canvas for the active Broca webchat turn",
    )
    p.add_argument(
        "--title",
        required=False,
        help="Optional plain-text canvas title (max 120 characters)",
    )
    p.add_argument(
        "--surface",
        required=False,
        default="primary",
        choices=("primary",),
        help="Canvas surface (only primary in Track B v1)",
    )
    return parser


def get_plugin_description(parser: argparse.ArgumentParser) -> Dict[str, Any]:
    commands: List[Dict[str, Any]] = [
        {
            "name": "open_canvas",
            "description": "Open empty companion canvas for the active Broca webchat turn",
            "parameters": [
                {
                    "name": "title",
                    "type": "string",
                    "required": False,
                    "description": "Optional plain-text canvas title (max 120 characters)",
                },
                {
                    "name": "surface",
                    "type": "string",
                    "required": False,
                    "default": "primary",
                    "enum": ["primary"],
                    "description": "Canvas surface (only primary in Track B v1)",
                },
            ],
        }
    ]
    return {
        "contract_version": "1.0",
        "plugin_name": _PLUGIN_ROOT.name,
        "plugin": {
            "name": _PLUGIN_ROOT.name,
            "version": PLUGIN_VERSION,
            "description": (
                "Sanctum Companion canvas tools — open an empty Track B canvas "
                "for the active Broca webchat turn (session from active-turn context)."
            ),
        },
        "description": (
            "Open companion canvas via machine-authenticated ui_event. "
            "Do not pass session, user, bearer, or event id."
        ),
        "notes": (
            "Requires a valid Broca active-turn file (schema sanctum.broca.active-turn v1). "
            "Fails closed when context is missing, expired, or wrong platform."
        ),
        "commands": commands,
    }


def main() -> None:
    parser = build_parser()
    try:
        args = parser.parse_args()
    except SystemExit as e:
        if e.code == 0:
            raise
        err = _error("Invalid arguments. Check command syntax.", "argument_error")
        print(json.dumps(err, indent=2))
        sys.exit(e.code if isinstance(e.code, int) else 2)

    if args.describe:
        print(json.dumps(get_plugin_description(parser), indent=2))
        sys.exit(0)

    if not args.command:
        parser.print_help()
        sys.exit(1)

    if args.command == "open_canvas":
        result = open_canvas(title=args.title, surface=args.surface or "primary")
    else:
        result = _error(f"Unknown command: {args.command}", "argument_error")

    print(json.dumps(result, indent=2))
    sys.exit(0 if result.get("status") == "success" else 1)


if __name__ == "__main__":
    main()
