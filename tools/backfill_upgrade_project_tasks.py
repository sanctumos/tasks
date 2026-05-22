#!/usr/bin/env python3
"""Backfill Sanctum Tasks upgrade project (project_id=10) from git history + docs.

Creates tasks via API, then optionally backdates created_at/updated_at on multihost
when TASKS_BACKFILL_SQLITE=1 and multihost DB path is reachable.
"""
from __future__ import annotations

import json
import os
import subprocess
import sys
import urllib.request
from dataclasses import dataclass
from typing import List, Optional

PROJECT_ID = 10
OTTO_USER = 4
LISTS = {
    "prd": 60,
    "product": 61,
    "infra": 62,
    "program": 63,
    "general": 59,
}


@dataclass
class TaskDef:
    title: str
    list_key: str
    status: str  # done | todo | doing
    date_start: str  # YYYY-MM-DD
    date_end: str
    tags: List[str]
    body: str
    commits: List[str]  # git log lines or subjects for proof


def git_oneline(since: str, until: str, pathspec: str = ".") -> List[str]:
    cmd = [
        "git", "log", f"--since={since}", f"--until={until}",
        "--date=short", "--format=%h %ad %s", "--", pathspec,
    ]
    out = subprocess.check_output(cmd, cwd="/root/projects/sanctum-tasks", text=True)
    return [ln.strip() for ln in out.splitlines() if ln.strip()]


def git_range(end_date: str, *grep_substrings: str) -> List[str]:
    """Commits on or before end_date matching any substring in subject."""
    all_lines = subprocess.check_output(
        ["git", "log", "--date=short", "--format=%h %ad %s", "-200"],
        cwd="/root/projects/sanctum-tasks",
        text=True,
    ).splitlines()
    picked = []
    for line in all_lines:
        if not line.strip():
            continue
        parts = line.split(" ", 2)
        if len(parts) < 3:
            continue
        sha, d, subj = parts[0], parts[1], parts[2]
        if d > end_date:
            continue
        if grep_substrings and not any(g.lower() in subj.lower() for g in grep_substrings):
            continue
        picked.append(line.strip())
    return picked


def commits_block(lines: List[str], max_lines: int = 40) -> str:
    if not lines:
        return "_No matching commits in sanctum-tasks git log._\n"
    shown = lines[:max_lines]
    extra = len(lines) - len(shown)
    block = "\n".join(f"- `{ln}`" for ln in shown)
    if extra > 0:
        block += f"\n- _…and {extra} more._"
    return block + "\n"


TASKS: List[TaskDef] = [
    TaskDef(
        "Planning: Basecamp overlay + domain-first plan v2",
        "prd",
        "done",
        "2026-05-02",
        "2026-05-04",
        ["deliverable", "phase-0"],
        "Research and planning per `docs/BASECAMP3_UX_OVERLAY_PLAN.md`, `docs/BASECAMP3_DOMAIN_PLAN.md`. BC3 UI experiment rolled back to `317d5cc`; domain-first phases supersede overlay milestones §6–7.\n\n",
        git_range("2026-05-04", "Basecamp", "overlay", "domain-first", "person_kind", "Unito"),
    ),
    TaskDef(
        "UX audit memo + Playwright admin walkthrough",
        "prd",
        "done",
        "2026-05-04",
        "2026-05-04",
        ["deliverable", "design"],
        "Documented clunky IA and recommendations in `docs/ux-audit-2026-05-04.md`; harness `tools/design-smoke/`.\n\n",
        git_range("2026-05-04", "UX audit", "walkthrough", "Playwright"),
    ),
    TaskDef(
        "Foundation: v1 API, admin UI, SDK/SMCP, task body",
        "product",
        "done",
        "2026-01-19",
        "2026-01-19",
        ["deliverable", "foundation"],
        "Initial product skeleton: PHP core, `/api/*`, session admin, Python `tasks_sdk`, `smcp_plugin`, task detail + `body` field.\n\n",
        git_range("2026-01-20", "Initial repository", "v1 tasks API", "admin UI", "Python SDK", "body field"),
    ),
    TaskDef(
        "Security hardening + rebrand (AGPL) + expanded test suite",
        "product",
        "done",
        "2026-02-16",
        "2026-02-27",
        ["deliverable", "security"],
        "H-01–H-08, M-01–M-14 fixes; Sanctum Tasks rebrand; rich admin/security flows; PHPUnit/pytest expansion.\n\n",
        git_range("2026-02-28", "Fix H-", "Fix M-", "Rebrand", "comprehensive unit"),
    ),
    TaskDef(
        "Agent ops: WORKFLOWS, HEARTBEAT docs + setup wizard + SMCP CLI parity",
        "product",
        "done",
        "2026-02-27",
        "2026-03-31",
        ["deliverable", "agents"],
        "`docs/WORKFLOWS.md`, `docs/HEARTBEAT.md`, `scripts/setup_heartbeat.sh`, SMCP CLI parity with API-key surface.\n\n",
        git_range("2026-03-31", "HEARTBEAT", "heartbeat", "smcp", "WORKFLOWS"),
    ),
    TaskDef(
        "Phase 1: organizations, person_kind, directory project APIs",
        "product",
        "done",
        "2026-05-04",
        "2026-05-04",
        ["deliverable", "phase-1"],
        "`organizations`, `users.org_id`, `users.person_kind`, directory project list/create APIs + SDK (`sanctum_001` migration notes).\n\n",
        git_range("2026-05-04", "organizations", "person_kind", "directory projects"),
    ),
    TaskDef(
        "Phase 2: project CRUD, members, todo lists, pins, task project_id",
        "product",
        "done",
        "2026-05-04",
        "2026-05-04",
        ["deliverable", "phase-2"],
        "Directory projects as entities: members, `todo_lists`, `user_project_pins`, `tasks.project_id`, client visibility flags. See CHANGELOG [Unreleased] org/project bullets.\n\n",
        git_range("2026-05-04", "Project CRUD", "project CRUD", "todo lists", "project_id"),
    ),
    TaskDef(
        "Admin v2: workspace tabs, settings hub, org admin, scoped lists",
        "product",
        "done",
        "2026-05-04",
        "2026-05-04",
        ["deliverable", "admin-ui"],
        "Tasks v2 design layer, project workspace tabs, consolidated Settings, organizations admin, limited project access.\n\n",
        git_range("2026-05-04", "Admin v2", "Tasks v2", "Settings", "Organizations admin"),
    ),
    TaskDef(
        "PHPUnit stack + API reference expansion + task ACL docs",
        "product",
        "done",
        "2026-05-04",
        "2026-05-05",
        ["deliverable", "testing"],
        "`docs/api.md` expansion, `docs/api-authorization-and-product-notes.md`, PHPUnit unit + HTTP integration (`docs/PHP_TEST_BENCHMARK.md` targets).\n\n",
        git_range("2026-05-05", "PHPUnit", "API reference", "task ACL"),
    ),
    TaskDef(
        "Enforce directory project_id on new tasks + invoicing backfill",
        "product",
        "done",
        "2026-05-05",
        "2026-05-05",
        ["deliverable", "migration"],
        "Require `project_id` on create; CLI/docs updated; prod invoicing rows linked to directory project (see workspace sanctum-tasks-dsc rule).\n\n",
        git_range("2026-05-05", "project_id", "invoicing", "directory project"),
    ),
    TaskDef(
        "Task detail redesign: Discussion thread, markdown, recurrence builder",
        "product",
        "done",
        "2026-05-05",
        "2026-05-06",
        ["deliverable", "admin-ui"],
        "Real discussion composer, Parsedown markdown, inline timestamps, RRULE builder (`tools/design-smoke/task_view_verify.py`).\n\n",
        git_range("2026-05-06", "task detail", "Discussion", "markdown", "recurrence"),
    ),
    TaskDef(
        "Documents feature + document-comment threads",
        "product",
        "done",
        "2026-05-06",
        "2026-05-06",
        ["deliverable", "docs-feature"],
        "`documents` + `document_comments` schema/API/admin (`sanctum_003`); Docs tab on project workspace.\n\n",
        git_range("2026-05-06", "Documents:", "document"),
    ),
    TaskDef(
        "Require list_id + Lists tab (Basecamp-style nested todos)",
        "product",
        "done",
        "2026-05-06",
        "2026-05-07",
        ["deliverable", "phase-4-lists"],
        "`list_id` required on every task; Lists default tab; nested list view (`sanctum_004`).\n\n",
        git_range("2026-05-07", "list_id", "Lists tab"),
    ),
    TaskDef(
        "Inline image uploads + multihost task-assets path + SDK upload_attachment",
        "product",
        "done",
        "2026-05-07",
        "2026-05-08",
        ["deliverable", "attachments"],
        "Per `docs/roadmap-inline-assets.md` locked decisions: `upload-attachment`, `get-asset`, storage under `public/uploads/task-assets/`.\n\n",
        git_range("2026-05-08", "upload", "attachment", "task-assets", "inline image"),
    ),
    TaskDef(
        "Notifications feed + @mentions + in-app user guide",
        "product",
        "done",
        "2026-05-07",
        "2026-05-07",
        ["deliverable", "collaboration"],
        "Per-user notifications; mention autocomplete; `public/docs` user guide for multihost WEB_ROOT.\n\n",
        git_range("2026-05-07", "notifications", "mention", "user guide"),
    ),
    TaskDef(
        "Document library navigation + public share links + copy-link fixes",
        "product",
        "done",
        "2026-05-08",
        "2026-05-10",
        ["deliverable", "docs-feature"],
        "Folder breadcrumbs, optional `/shared-document.php` tokens, `TASKS_APP_BASE_URL` / origin fixes, Playwright copy-link regression.\n\n",
        git_range("2026-05-10", "public share", "copy-link", "document library", "breadcrumbs"),
    ),
    TaskDef(
        "Directory activity timeline (API + admin Activity tab)",
        "product",
        "done",
        "2026-05-11",
        "2026-05-11",
        ["deliverable", "activity"],
        "`GET /api/list-activity.php`, project/home Activity UI (`2a4f14e`). Timeline tab experiment reverted same day.\n\n",
        git_range("2026-05-11", "activity timeline", "Timeline tab"),
    ),
    TaskDef(
        "Split FastAPI stack to sanctumos/py-tasks",
        "product",
        "done",
        "2026-05-05",
        "2026-05-05",
        ["deliverable", "repo"],
        "Removed `api_python/` from this repo; PHP + SDK + SMCP remain canonical deploy surface.\n\n",
        git_range("2026-05-05", "FastAPI", "py-tasks"),
    ),
    TaskDef(
        "Engagement report rubric + Tasks review hygiene docs",
        "prd",
        "done",
        "2026-05-07",
        "2026-05-07",
        ["deliverable", "process"],
        "`docs/tasks-report-rubric.md` + workspace rule alignment (document comments, no self-reply stacking).\n\n",
        git_range("2026-05-07", "rubric", "document-comment", "self-reply"),
    ),
    TaskDef(
        "Prod: tasks.decisionsciencecorp.com go-live (multihost)",
        "infra",
        "done",
        "2026-05-02",
        "2026-05-02",
        ["deliverable", "ada", "infra"],
        "DNS → multihost **64.95.10.156**, vhost + Let's Encrypt, deploy `sanctum-tasks` `public/`, DB outside web root. Source: `otto-mark-summaries/2026-05-02-tasks-dsc-live.md`.\n\n",
        [],
    ),
    TaskDef(
        "Multihost git-sync cron (sync.sh + deploy.sh)",
        "infra",
        "done",
        "2026-05-04",
        "2026-05-04",
        ["deliverable", "ada", "infra"],
        "`/root/sites/tasks.decisionsciencecorp.com.env` + AGENT_CRON pair verified on multihost. Source: `otto-ada-summaries/2026-05-04-tasks-dsc-multihost-cron-request.md`.\n\n",
        [],
    ),
    TaskDef(
        "Home-first admin landing + condensed nav",
        "product",
        "done",
        "2026-05-07",
        "2026-05-08",
        ["deliverable", "admin-ui"],
        "Projects hub then cross-project tasks; Settings submenu; bell-only notifications (UX audit direction §A).\n\n",
        git_range("2026-05-08", "Home-first", "Condense top nav"),
    ),
    # Open / deferred from domain plan + UX audit
    TaskDef(
        "Phase 3: client visibility rules MVP (refine access matrix)",
        "prd",
        "todo",
        "2026-05-21",
        "2026-05-21",
        ["phase-3", "blocked"],
        "Per `docs/BASECAMP3_DOMAIN_PLAN.md` §Phase 3 — enforce project visibility using `project_members` + `person_kind`; seed scenarios TBD.\n\n",
        [],
    ),
    TaskDef(
        "Phase 4: schedule aggregation (due dates / calendar views)",
        "prd",
        "todo",
        "2026-05-21",
        "2026-05-21",
        ["phase-4"],
        "Domain plan §Phase 4.1 — aggregate `due_at` per user/project before Doors/chat.\n\n",
        [],
    ),
    TaskDef(
        "Phase 4: Doors (external URLs on projects)",
        "prd",
        "todo",
        "2026-05-21",
        "2026-05-21",
        ["phase-4"],
        "Domain plan §Phase 4 — external tool links on projects.\n\n",
        [],
    ),
    TaskDef(
        "Phase 5: Home / omnibar / card-list mobile (UX audit §A–C)",
        "prd",
        "todo",
        "2026-05-21",
        "2026-05-21",
        ["phase-5", "design"],
        "Implement UX audit recommendations: triage home, project workspace as collaboration surface, mobile card list.\n\n",
        [],
    ),
    TaskDef(
        "Revisit project Timeline tab (13-week due strip) — reverted 2026-05-11",
        "product",
        "todo",
        "2026-05-11",
        "2026-05-21",
        ["admin-ui"],
        "Shipped in `e154aa0`, reverted in `c0b63b8` after layout helper fix `8c769bb`. Re-scope before re-landing.\n\n",
        git_range("2026-05-11", "Timeline tab"),
    ),
    TaskDef(
        "PHP_TEST_BENCHMARK: 90% unit/integration line coverage + workflow Playwright checklist",
        "product",
        "todo",
        "2026-05-21",
        "2026-05-21",
        ["testing"],
        "Track toward targets in `docs/PHP_TEST_BENCHMARK.md`.\n\n",
        [],
    ),
    TaskDef(
        "Q Vernal: agent bootstrap from canonical memory doc",
        "product",
        "todo",
        "2026-05-21",
        "2026-05-21",
        ["agents", "q-vernal"],
        "Core memory copied to Tasks document **#295** (Athena source). Implement Letta/agent instance + web bridge per doc.\n\n",
        [],
    ),
]


def api(method: str, path: str, payload=None):
    base = os.environ["TASKS_DSC_BASE_URL"].rstrip("/")
    key = os.environ["TASKS_DSC_OTTOVERNAL_API_KEY"]
    data = json.dumps(payload).encode() if payload is not None else None
    req = urllib.request.Request(
        f"{base}{path}",
        data=data,
        method=method,
        headers={"X-API-Key": key, "Content-Type": "application/json"},
    )
    with urllib.request.urlopen(req, timeout=120) as resp:
        return json.loads(resp.read().decode())


def main() -> int:
    if not os.environ.get("TASKS_DSC_BASE_URL") or not os.environ.get("TASKS_DSC_OTTOVERNAL_API_KEY"):
        print("Load ~/.ssh/tasks-dsc-ottovernal.pass first", file=sys.stderr)
        return 2

    payload_tasks = []
    meta = []
    for t in TASKS:
        body = t.body + "## Git / source proof\n\n" + commits_block(t.commits)
        payload_tasks.append({
            "title": t.title,
            "status": t.status,
            "list_id": LISTS[t.list_key],
            "project_id": PROJECT_ID,
            "assigned_to_user_id": OTTO_USER,
            "priority": "normal" if t.status == "done" else "high",
            "tags": t.tags,
            "project": "Sanctum Tasks — platform upgrade",
            "body": body.strip(),
        })
        meta.append((t.date_start, t.date_end, t.status))

    created_ids = []
    for i in range(0, len(payload_tasks), 50):
        chunk = payload_tasks[i : i + 50]
        res = api("POST", "/api/bulk-create-tasks.php", {"tasks": chunk})
        data = res.get("data") or res
        base_index = i
        for item in data.get("results", []):
            if item.get("success") and item.get("id"):
                tid = int(item["id"])
                created_ids.append(tid)
                idx = base_index + int(item.get("index", 0))
                title = payload_tasks[idx]["title"]
                print(f"created {tid}: {title[:60]}")
            else:
                print("FAIL:", item.get("error") or item, file=sys.stderr)

    # Write manifest for SQLite backdate step
    manifest = []
    for idx, tid in enumerate(created_ids):
        if idx >= len(meta):
            break
        ds, de, st = meta[idx]
        manifest.append({
            "id": tid,
            "created_at": f"{ds} 12:00:00",
            "updated_at": f"{de} 18:00:00" if st == "done" else f"{ds} 12:00:00",
        })
    out_path = "/tmp/tasks_upgrade_backfill_manifest.json"
    with open(out_path, "w") as f:
        json.dump(manifest, f, indent=2)
    print(f"manifest={out_path} count={len(manifest)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
