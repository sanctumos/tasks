# Doing the work

This is the page for the day-to-day loop: Home, all tasks, task detail, comments, markdown, mentions, watchers, and filters.

## Home: current work, not storage

Home is the current-work screen. It is not the archive. It is not the database browser. It answers one question: what can I work on right now?

Home has two big pieces:

1. **Your projects** — active projects you can access.
2. **All tasks across projects** — a cross-project board/list for active work.

Archived project tasks do **not** show in all tasks. That is intentional. Archive means "keep the record, remove it from the active operating view." If you need the old board, use **Projects → Show archived**, or **Settings → Archived boards**.

## Search, filters, and all tasks

The all-tasks area lets you narrow work by status, priority, assignee, project, search text, and sorting.

Use it for operational scans:

- "What is assigned to me?"
- "What is still todo?"
- "What is high priority?"
- "Where did we mention this phrase?"
- "What changed recently?"

Use the project page when the question is board-specific:

- "What belongs to this client?"
- "What was in this phase?"
- "What did we decide on this project?"
- "What did this archived board contain?"

The distinction matters. All tasks is a live work cockpit. A project is the full container.

## Task detail: the record of work

A task is not just a checkbox. It is the record of one piece of work.

The task page holds:

- Title and body.
- Status, priority, assignee, due date.
- Project and to-do list.
- Tags and rank.
- Comments.
- Watchers.
- Attachments and inline images.
- Recurrence when that task repeats.

If a decision changes the work, put it in a comment. If a screenshot proves the work, attach it or embed it. If the task is waiting on a person, say who and why. Future-you should not need to reconstruct the story from memory.

## Comments

Comments are the working ledger.

Good comments do three jobs:

- They say what changed.
- They say what is still open.
- They leave proof: a screenshot, a link, a command result, a commit, a downloaded ZIP, or a plain-language note.

Do not use a comment to hide unclear work. If the task title is wrong, fix the title. If the body is stale, update the body or leave a comment that says the thread supersedes it.

## Markdown, mentions, and diagrams

Task bodies, comments, and documents support safe markdown.

Use markdown for the things humans actually need:

- Bullets for scope.
- Numbered lists for sequences.
- Links for source material.
- Code blocks for commands or snippets.
- Tables when comparison beats prose.

Use `@username` when you need a person to see something. The editor suggests users as you type.

Mermaid diagrams render from fenced code blocks marked `mermaid`. Use them when the diagram reduces confusion. Do not draw a flowchart just because a flowchart is possible.

## Watchers

Watching a task means "keep me in the loop." It is not ownership. Ownership is the assignee.

Watch a task when:

- You are reviewing the result.
- Your work depends on it.
- You are a stakeholder but not the doer.
- You need notifications for comments.

Unwatch when the task is no longer in your lane.

## Due dates and recurrence

Due dates feed Schedule. They are a commitment signal, not decorative metadata.

Recurrence is for repeated work. Use it for things that genuinely recur. Do not use recurrence to avoid filing a clear task.

## Board view vs list view

Board view is better for scanning status. List view is better for dense triage.

Use board view when you are asking, "What bucket is this in?" Use list view when you are asking, "What are the next ten things I should touch?"

Neither view changes the underlying task. It only changes how you read the same work.
