"""Tasks SMCP tool exposure profiles (Phase 2a)."""

from __future__ import annotations

from typing import Any, Dict, FrozenSet, List, Set

PROFILE_CHATTER: FrozenSet[str] = frozenset(
    {
        "create-task",
        "update-task",
        "get-task",
        "search-tasks",
        "list-tasks",
        "create-comment",
        "list-comments",
        "get-document",
        "list-documents",
        "create-document",
        "update-document",
        "create-document-comment",
        "list-document-comments",
        "list-directory-projects",
        "list-todo-lists",
        "search-users",
    }
)

PROFILE_ADMIN_EXTRA: FrozenSet[str] = frozenset(
    {
        "bulk-create-tasks",
        "bulk-update-tasks",
        "list-attachments",
        "upload-attachment",
        "add-attachment",
        "watch-task",
        "unwatch-task",
        "list-watchers",
        "list-project-members",
        "list-project-pins",
        "set-project-pin",
        "list-tags",
        "list-statuses",
        "get-directory-project",
        "create-todo-list",
    }
)

PROFILE_ADMIN_ONLY: FrozenSet[str] = frozenset(
    {
        "create-user",
        "disable-user",
        "reset-user-password",
        "create-api-key",
        "revoke-api-key",
        "list-api-keys",
        "list-users",
        "list-audit-logs",
        "list-organizations",
        "create-directory-project",
        "update-directory-project",
        "add-project-member",
        "remove-project-member",
        "create-status",
        "delete-task",
        "delete-document",
    }
)

PROFILE_NOISE: FrozenSet[str] = frozenset({"health"})

PROFILES: Dict[str, FrozenSet[str]] = {
    "chatter": PROFILE_CHATTER,
    "admin": PROFILE_CHATTER | PROFILE_ADMIN_EXTRA | PROFILE_ADMIN_ONLY,
    "full": frozenset(),  # empty = all commands
}

TOOL_HELP_ROUTES: List[Dict[str, Any]] = [
    {
        "intent": "create or add a board task",
        "tools": ["create-task"],
        "required": ["list-id or project-id", "title"],
        "anti": "create-document (long-form docs)",
    },
    {
        "intent": "update task status assignee or body",
        "tools": ["update-task", "get-task"],
        "required": ["task-id"],
        "anti": "bulk-update-tasks unless many rows",
    },
    {
        "intent": "search or list tasks on a project",
        "tools": ["list-tasks", "search-tasks"],
        "required": ["project_id filter when known"],
        "anti": "list-users",
    },
    {
        "intent": "comment on a task thread",
        "tools": ["create-comment", "list-comments"],
        "required": ["task-id"],
        "anti": "create-document-comment",
    },
    {
        "intent": "read or write a directory document",
        "tools": ["get-document", "list-documents", "create-document", "update-document"],
        "required": ["project-id for list/create", "id for get/update"],
        "anti": "create-task for long-form specs",
    },
    {
        "intent": "comment on a document",
        "tools": ["create-document-comment", "list-document-comments"],
        "required": ["document-id"],
        "anti": "create-comment",
    },
    {
        "intent": "resolve project or list ids",
        "tools": ["list-directory-projects", "list-todo-lists"],
        "required": ["project-id for list-todo-lists"],
        "anti": "guessing list_id",
    },
    {
        "intent": "resolve assignee username to user id",
        "tools": ["search-users"],
        "required": ["q prefix for username search"],
        "anti": "list-users (admin only); guessing assigned_to_user_id",
    },
    {
        "intent": "admin user or API key management",
        "tools": ["create-user", "list-users", "create-api-key"],
        "required": ["admin profile — not chatter"],
        "anti": "available in chatter profile",
    },
]


def normalize_profile(name: str | None) -> str:
    key = (name or "full").strip().lower()
    if key not in PROFILES:
        raise ValueError(f"Unknown profile {name!r}; use chatter, admin, or full")
    return key


def command_allowed(command_name: str, profile: str) -> bool:
    prof = normalize_profile(profile)
    allowed = PROFILES[prof]
    if not allowed:
        return command_name not in PROFILE_NOISE
    return command_name in allowed


def filter_plugin_description(description: Dict[str, Any], profile: str) -> Dict[str, Any]:
    prof = normalize_profile(profile)
    commands = description.get("commands") or []
    if not PROFILES[prof]:
        filtered = [c for c in commands if (c.get("name") or "") not in PROFILE_NOISE]
    else:
        filtered = [c for c in commands if (c.get("name") or "") in PROFILES[prof]]
    out = dict(description)
    out["profile"] = prof
    out["commands"] = filtered
    out["command_count"] = len(filtered)
    return out


def tool_help(query: str, profile: str = "chatter") -> Dict[str, Any]:
    q = (query or "").strip().lower()
    matches: List[Dict[str, Any]] = []
    for row in TOOL_HELP_ROUTES:
        blob = " ".join(
            [
                row.get("intent", ""),
                " ".join(row.get("tools") or []),
                row.get("anti", ""),
            ]
        ).lower()
        if not q or any(token in blob for token in q.split() if len(token) > 2):
            if profile == "chatter":
                tools = [t for t in row.get("tools") or [] if t in PROFILE_CHATTER]
                if not tools and "admin" in (row.get("anti") or ""):
                    continue
            else:
                tools = list(row.get("tools") or [])
            if tools:
                matches.append({**row, "tools": tools})
    return {
        "status": "success",
        "profile": normalize_profile(profile),
        "query": query,
        "matches": matches[:8],
        "hint": "Use tasks__<command> via MCP; documents need project_id; tasks need list_id.",
    }
