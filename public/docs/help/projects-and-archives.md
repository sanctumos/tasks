# Projects and archives

Projects are the container for real work. If the task matters, it belongs to a project. If the project is no longer active, it should be archived instead of left in the way.

## Projects: the container for real work

Open **Projects** from the top nav to see workspace projects.

By default, this page shows active projects. Use **Show archived** when you need an old board.

A project has a few important flags:

- **Active** — shows in normal project lists and feeds active task views.
- **Archived** — stays readable, leaves default project lists, leaves all-tasks views, and can be exported as a ZIP.
- **Trashed** — soft-deleted from normal use.
- **All-access** — visible to everyone in the organization who otherwise has org access.
- **Client-visible** — visible to client-kind users when their access rules allow it.

Projects are not only labels. They own lists, tasks, docs, members, doors, activity, and archive downloads.

## Lists, tasks, schedule, doors, docs

Opening a project lands you on **Lists** because most project work starts there.

Project tabs:

| Tab | What it is for |
|-----|----------------|
| **Lists** | The main work plan. Tasks grouped under project to-do lists. |
| **Tasks** | A project-scoped task view when you need task density instead of list grouping. |
| **Schedule** | Due dates from tasks in this project context. |
| **Doors** | External links tied to the project: client portals, specs, repos, dashboards, or anything that should be one click away. |
| **Activity** | The project history stream. Useful when you need to know what changed. |
| **Docs** | Long-form project documents. Specs, onboarding notes, research, decisions. |
| **Members** | People who belong to the project and their project role. |
| **Archive downloads** | Visible after archive. Generate and download a ZIP snapshot. |
| **Settings** | Manage project settings and lifecycle if you have permission. |

Every task belongs to a to-do list. That is not bureaucracy; it is how the board stays readable.

## Schedule, activity, and doors

**Schedule** is built from task due dates. It is not a separate calendar. If the date matters, put the date on the task.

**Activity** is the project trail. It tells you what changed without forcing you to open every task.

**Doors** are named links. Use them for destinations people actually need: a repo, a vendor portal, a client folder, a dashboard, a live site, a design, a contract. A door is better than a link buried in the third comment of an old task.

## Archiving a board

Archive is a deliberate lifecycle action.

The button is not in the project header because that is too easy to hit by accident. Go to:

**Project → Settings → Board lifecycle → Archive this board…**

Archiving does three things:

- Removes the project from the default active project list.
- Removes the project’s tasks from Home / all tasks.
- Keeps the project readable, including tasks, docs, comments, activity, members, and downloads.

Archive is not delete. It is "this is no longer current, but the record still matters."

## Archived boards and ZIP downloads

Archived boards have an **Archive downloads** tab.

The path is:

**Projects → Show archived → open the board → Archive downloads**

From there:

1. Click **Generate board archive**.
2. Wait for the job to turn **ready**.
3. Click **Download**.

The ZIP is a snapshot. It includes flat HTML pages for the board, tasks, and documents, plus local attachment bytes under `assets/` when Tasks has the file. Embedded `get-asset.php?id=...` references are rewritten to files inside the ZIP when possible.

Remote attachments are best-effort. If a third-party URL is dead, the ZIP records a note instead of pretending the file exists.

## Settings → Archived boards

There is also a master archived-board list:

**Settings → Archived boards**

Use this when you do not remember which archived project holds the file you need.

That page lists archived boards you can access. Each row gives you:

- **Open** — goes to that board’s Archive downloads tab.
- **Download** — downloads the latest ready ZIP when one exists.
- ZIP status — ready, building, or no ZIP yet.

This is the shelf. The project Archive downloads tab is the workbench.

## Restoring a board

If a board becomes active again:

**Archived project → Settings → Board lifecycle → Restore to active**

Restoring brings the project back into active project lists and all-tasks views.

Do this when the work is genuinely live again. Do not restore a board only to grab an old file. Use Archive downloads for that.
