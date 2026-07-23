# Automation

Automation is a first-class surface in Tasks. The browser is for people. The API is for machines. Good work uses both and does not pretend one is the other.

## API, SDK, SMCP, and Q

The browser UI lives under `/admin/`.

The JSON API lives under `/api/`.

The API is the contract for:

- Internal scripts.
- Python SDK clients.
- SMCP/MCP tools.
- Q and other agents.
- External integrations.

If a workflow can be done through the API, use the API. Do not scrape admin pages to automate normal product behavior.

## Authentication

API calls use keys in headers:

- `X-API-Key: ...`
- `Authorization: Bearer ...`

Do not put keys in query strings. Do not paste keys into tasks, docs, comments, screenshots, or help pages.

The browser uses session cookies. A session cookie is not a service credential. An API key is not a browser login.

## API reference

The detailed API contract lives in the repository:

- `docs/api.md`
- `docs/api-authorization-and-product-notes.md`

This Help page is the operating map. The API docs are the route-by-route contract.

## SDK clients

The Python SDK lives under `tasks_sdk/`.

Use the SDK when you want code that reads like product work instead of raw HTTP plumbing. Use raw HTTP when you are testing endpoints, debugging a contract, or working from a language without a local client.

The SDK does not exempt you from product rules. A task still needs a project/list. A client still needs access. An archived board is still archived.

## SMCP and MCP tools

SMCP/MCP tools expose Tasks operations to agents and automation.

Use them when:

- An agent needs to create or update tasks.
- A workflow needs to search tasks and documents.
- A scripted tool needs a stable interface instead of hand-built curl calls.
- You want agent behavior to stay inside published product APIs.

Do not use SMCP as a back door around access rules. The tool should behave like the product, not like someone opened the database with a crowbar.

## Q

Q is the in-app helper agent surface when enabled.

Q can help with task navigation and workflow support. Admins configure widget rate limits in **Settings → Ask Q**. Those limits protect normal app use from overly noisy chat sessions.

Q is not the ledger. If Q helps you decide something, put the decision on the task or document where it belongs.

## Health and troubleshooting

Use `/api/health.php` as documented. It is not a magic unauthenticated ping for every context.

When automation fails, check in this order:

1. Is the base URL correct?
2. Is the API key being sent in a header?
3. Is the user tied to that key active?
4. Can that user see the project?
5. Does the task have a valid `project_id` and `list_id`?
6. Is the project archived, active, or trashed?
7. Did the endpoint return structured JSON explaining the problem?

Most automation bugs are not mysterious. They are a missing list, a stale key, a user without access, or a script trying to treat archived work as active work.

## When to use the browser instead

Use the browser when the work is judgment-heavy:

- Reviewing a client-facing note.
- Reading a discussion thread.
- Checking screenshots.
- Deciding whether to archive a board.
- Changing project membership.
- Looking at what a human will actually see.

Use automation when the work is repeatable:

- Filing many tasks from a known plan.
- Updating statuses after a build.
- Pulling task lists into another system.
- Uploading proof assets.
- Creating API-driven reports.

The line is not "manual bad, automation good." The line is whether the action needs judgment at the moment it happens.
