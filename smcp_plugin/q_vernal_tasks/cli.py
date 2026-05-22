#!/usr/bin/env python3
"""
Q Vernal Tasks SMCP — full Tasks SDK surface with per-chatter API keys.

Never accepts --api-key from the model. Resolves the hidden key server-side via
the Q bridge (poll Bearer + tasks_user_id from Broca plugin context file).
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from typing import Any, Dict

_PLUGIN_ROOT = Path(__file__).resolve().parent
_PLUGINS_DIR = _PLUGIN_ROOT.parent
_REPO_ROOT = _PLUGINS_DIR.parent.parent
sys.path.insert(0, str(_REPO_ROOT))
sys.path.insert(0, str(_PLUGINS_DIR))
sys.path.insert(0, str(_PLUGIN_ROOT))

from resolve_key import chatter_user_id_from_context, resolve_tasks_api_key

from tasks.cli import (  # noqa: E402
    DEBUG_TRACEBACKS,
    build_parser,
    command_handlers,
    get_plugin_description,
)


def _strip_api_key_argument(parser: argparse.ArgumentParser) -> None:
    """Remove --api-key from root and all subcommands (resolved server-side)."""
    for action in list(parser._actions):
        if hasattr(action, "option_strings") and "--api-key" in action.option_strings:
            parser._remove_action(action)
    for action in parser._actions:
        name_map = getattr(action, "_name_parser_map", None)
        if name_map:
            for sub in name_map.values():
                _strip_api_key_argument(sub)


def build_q_vernal_parser() -> argparse.ArgumentParser:
    parser = build_parser()
    _strip_api_key_argument(parser)
    return parser


def main() -> None:
    global DEBUG_TRACEBACKS
    parser = build_q_vernal_parser()
    try:
        args = parser.parse_args()
    except SystemExit as e:
        if e.code == 0:
            raise
        err = {
            "status": "error",
            "error": "Invalid arguments. Check command syntax.",
            "error_type": "argument_error",
        }
        print(json.dumps(err, indent=2))
        sys.exit(e.code if isinstance(e.code, int) else 2)

    DEBUG_TRACEBACKS = bool(getattr(args, "debug", False))

    if args.describe:
        desc = get_plugin_description(parser)
        desc["plugin_name"] = "q_vernal_tasks"
        desc["description"] = (
            "Sanctum Tasks API tools for Q. Vernal — uses the logged-in chatter's "
            "hidden API key (resolved server-side; never pass api-key)."
        )
        desc["notes"] = (
            "Chatter context comes from the active webchat session (Broca plugin). "
            "Do not impersonate other user IDs."
        )
        for cmd in desc.get("commands", []):
            cmd["parameters"] = [
                p
                for p in cmd.get("parameters", [])
                if p.get("name") not in ("api-key", "api_key")
            ]
        print(json.dumps(desc, indent=2))
        sys.exit(0)

    if not args.command:
        parser.print_help()
        sys.exit(1)

    uid = chatter_user_id_from_context()
    if not uid:
        err = {
            "status": "error",
            "error": "No active Tasks chatter context (webchat session).",
            "error_type": "auth",
        }
        print(json.dumps(err, indent=2))
        sys.exit(1)

    try:
        api_key = resolve_tasks_api_key(uid)
    except Exception as e:
        err = {"status": "error", "error": str(e), "error_type": "auth"}
        print(json.dumps(err, indent=2))
        sys.exit(1)

    args_dict: Dict[str, Any] = {}
    for key, value in vars(args).items():
        if key in ("command", "describe", "api_key", "debug"):
            continue
        if value is None:
            continue
        args_dict[key.replace("_", "-")] = value

    handler = command_handlers().get(args.command)
    if not handler:
        err = {
            "status": "error",
            "error": f"Unknown command: {args.command}",
            "error_type": "argument_error",
        }
        print(json.dumps(err, indent=2))
        sys.exit(1)

    result = handler(args_dict, api_key)
    print(json.dumps(result, indent=2))
    sys.exit(0 if result.get("status") == "success" else 1)


if __name__ == "__main__":
    main()
