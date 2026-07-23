# Sanctum Tasks help

Tasks is not trying to be a cute little checklist app. It is the work ledger: the place where a client ask becomes a task, a build decision becomes a comment, a screenshot becomes proof, and an old board becomes an archive you can still defend six months later.

The product has two faces:

- The browser UI under `/admin/`, for humans doing and reviewing work.
- The JSON API under `/api/`, for agents, scripts, SDKs, and integrations.

Use the browser when you are deciding, discussing, or checking the shape of the work. Use the API when a machine is doing repeatable work and you need the result to be auditable.

## Start here: what Tasks is

The core model is deliberately boring:

- **Organization** — the boundary around people and projects.
- **Project** — the board or workspace where a piece of work lives.
- **To-do list** — a section inside a project.
- **Task** — the actual thing someone owns.
- **Comment** — the running record of decisions, proof, questions, and delivery notes.
- **Document** — long-form project knowledge that should not be trapped inside a single task.
- **Attachment** — a file or image tied to a task and served through Tasks permissions.

That structure matters. A task without a project is a loose receipt in a junk drawer. A project without comments is a dashboard with no memory. A file outside Tasks might exist, but nobody knows whether it was the thing that shipped.

The operating rule is simple: if the work matters, leave a trail where the next person will actually look.

## The help map

This Help area is split into topic pages.

| Page | Use it when |
|------|-------------|
| **Doing the work** | You are on Home, looking at all tasks, writing comments, using mentions, or opening a task. |
| **Projects and archives** | You are creating projects, using lists, archiving a board, downloading a ZIP, or reading schedule/activity. |
| **Docs and files** | You are writing project documents, uploading screenshots, embedding images, or sharing document links. |
| **Access and settings** | You are managing users, project visibility, MFA, API keys, audit, archived-board shortcuts, or Ask Q limits. |
| **Automation** | You are using the API, SDK, SMCP/MCP tools, or Q/agent workflows. |

The little **?** icons in the app jump to the right page and section. If you find yourself explaining the same thing twice, the help section is missing something.

## The daily loop

The normal flow looks like this:

1. Open **Home** to see active projects and current tasks.
2. Open the project that owns the work.
3. Use **Lists** for the working plan.
4. Open the task when the conversation needs detail, files, decisions, or proof.
5. Use comments for status and decisions. Do not bury decisions in chat.
6. Use **Docs** for long explanations, specs, research, onboarding notes, or client-facing writeups.
7. Archive the board when it is no longer current, then download a ZIP when you need a durable handoff.

That last step is new and important. Archived projects are not deleted. They leave the default project list and they stop polluting all-tasks views, but they remain readable, downloadable, and auditable.

## What changed recently

The current build includes several features older help text did not cover:

- **Archived boards** now have an **Archive downloads** tab.
- **Settings → Archived boards** is a master list of archived projects with latest ZIP download shortcuts.
- Archived-board tasks no longer show up on **Home / all tasks**.
- The project **Archive** action moved into **Project settings → Board lifecycle** so it is not an accidental header click.
- **Schedule** aggregates task due dates from visible projects.
- **Doors** store external links on a project.
- **Activity** gives project-level history.
- **Appearance** lives under Settings.
- **Ask Q** rate limits live under admin Settings when Q is enabled.
- Uploaded task assets are served through Tasks permission checks, not guessed public paths.
- Project documents can have public read links when enabled by someone with permission.

## When this guide disagrees with the app

The shipped PHP is the source of truth for behavior. The API reference is `docs/api.md`. This guide is the human map. If the map gets stale, update it in the same change as the feature.
