# Client visibility access matrix (Phase 3 MVP)

Companion to [`BASECAMP3_DOMAIN_PLAN.md`](BASECAMP3_DOMAIN_PLAN.md) §Phase 3 and [`api-authorization-and-product-notes.md`](api-authorization-and-product-notes.md).

**Implementation:** `public/includes/functions.php` — `userCanAccessDirectoryProject`, `userHasUnrestrictedOrgDirectoryAccess`, `listDirectoryProjectsForUser`, `listTasks`, `userCanAccessDocument`, `userCanManageDocument`, `userCanManageDirectoryProject`.

**Seed fixtures:** `tools/e2e/q_acl_fixture_lib.php` (`bootstrapQAclE2eFixtures()`).

---

## Dimensions

| Field | Values | Role |
|-------|--------|------|
| `users.person_kind` | `team_member` \| `client` | Portal lane — separate from `users.role` |
| `users.role` | `admin` \| `manager` \| `member` | Staff permissions inside the org |
| `users.limited_project_access` | `0` \| `1` | When `1`, managers see only member/all_access projects |
| `projects.client_visible` | `0` \| `1` | **Required** for `person_kind=client` to see the project |
| `projects.all_access` | `0` \| `1` | Org-wide read for **team** users; never bypasses `client_visible` for clients |
| `project_members` | `lead` \| `member` \| `client` | Per-project membership (orthogonal to `person_kind`; both apply) |

---

## Project read access (`userCanAccessDirectoryProject`)

Evaluate in order:

1. Project exists, same org, not `trashed`.
2. **`person_kind=client`** → project must have **`client_visible=1`** (hard stop).
3. **Unrestricted staff** (`admin`, or `manager` without `limited_project_access`) → allow.
4. **`all_access=1`** → allow (team only reaches here; clients already filtered).
5. **`project_members`** row for viewer → allow.
6. Otherwise → deny.

### Matrix (seed scenarios)

| Viewer | Project flags | Membership | Read project? |
|--------|---------------|------------|---------------|
| Admin (`team_member`) | internal (`cv=0`) | none | **Yes** (unrestricted) |
| Manager unrestricted | internal | none | **Yes** |
| Manager limited | internal | none | **No** |
| Manager limited | internal | member | **Yes** |
| Manager limited | `all_access=1` | none | **Yes** |
| Team member | internal | member | **Yes** |
| Team member | internal | none | **No** |
| Team member | `all_access=1` | none | **Yes** |
| Client | internal | member | **No** (`client_visible` gate) |
| Client | `client_visible=1` | member | **Yes** |
| Client | `client_visible=1` | none | **No** |
| Client | `client_visible=1`, `all_access=1` | none | **Yes** (org-wide client portal) |

**Fixture mapping** (`bootstrapQAclE2eFixtures`):

| Fixture key | Scenario |
|-------------|----------|
| `projects.member_visible` | Client + member both members; `client_visible=1` |
| `projects.admin_only` | Internal; only admin/unrestricted staff |

Users: `e2e_q_member` (`team_member`), `e2e_q_client` (`client`).

---

## Write / manage access (MVP defaults)

| Action | Client | Team member | Lead | Admin / unrestricted manager |
|--------|--------|-------------|------|------------------------------|
| View tasks/docs in accessible project | Yes | Yes | Yes | Yes |
| Create task | **No** | Yes (if project access) | Yes | Yes |
| Create document | **No** | Yes (if project access) | Yes | Yes |
| Edit/delete task (not creator) | No | No* | Yes (project lead) | Yes |
| Edit/delete document (not creator) | No | No* | Yes (project lead) | Yes |
| Manage project settings / members | **No** | No | Yes (lead) | Yes |
| Add client user to project | — | — | Only if `client_visible=1` | Yes |

\* Members may edit tasks they created; documents they created (see `userCanManageTaskForViewer` / `userCanManageDocument`).

---

## Task listing scope (`listTasks` with directory scope)

Non-unrestricted users only see tasks where:

- `project_id` is in `getAccessibleDirectoryProjectIdsForUser`, **or**
- `project_id` is null and they are creator or assignee.

Watchers are **not** included in list scope SQL — use `get-task` / watcher filter for explicit checks.

---

## HTTP semantics

Forbidden project/task/document reads return **404** (no existence leak). See [`api-authorization-and-product-notes.md`](api-authorization-and-product-notes.md).

---

## Tests

| File | Coverage |
|------|----------|
| `tests/php/Unit/QAclFixturesTest.php` | Fixture idempotency + matrix intent |
| `tests/php/Unit/QAclMemberNegativeTest.php` | Member blocked from internal project |
| `tests/php/Unit/QAclClientVisibleTest.php` | Client scoped listings |
| `tests/php/Unit/ClientVisibilityAccessMatrixTest.php` | Write guards + edge rows |

Run: `./vendor/bin/phpunit tests/php/Unit/QAcl*.php tests/php/Unit/ClientVisibilityAccessMatrixTest.php`
