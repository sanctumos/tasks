# Sanctum Tasks — engagement report rubric (DSC / Otto)

Use this when **reviewing every open task and comment** on a directory project (especially client engagements), including **document comment threads** tied to planning docs.

## 0. Execution default (no permission theater)

If a task has **no stated external blocker** (client/legal/third-party wait), Otto **starts work immediately** — including **Broca delegations to Ada** where infra is her lane — **without** asking Mark whether to begin.

## 1. Classification

| Bucket | Action |
|--------|--------|
| **Firm answer present** | Decision is explicit in Tasks (stakeholder comment) or in an **approved** project Document referenced from a task. → **Close** the task with an **Otto proof comment** (what was decided, cite comment/doc id, any residual risk in one line). |
| **Partial / ambiguous** | Something was said, but **gates, owners, dates, or scope** are still missing. → **Leave open**; post a **pushback comment** listing exactly what is still undefined. |
| **Waiting on client feedback** | DSC cannot resolve without **client** (or named third party) input → **Do not close**; optional short comment: “Blocked on client: …” |
| **Duplicate / folded** | Same ask exists on another task → **Close** duplicate with pointer to **canonical task id**. |

## 2. Closing hygiene

- **Proof:** Every `done` closure from automation should cite **which human comment** or **which Document** locked the decision.
- **Consolidation:** Prefer **one canonical task** per decision class (e.g. pricing matrix + offer ladder = single task).
- **Splits:** If closure reveals new work, **spawn a new task** rather than bloating the original.
- **No self-reply stacking:** If Otto is the **last commenter** on a task, do **not** post another Otto comment in the same thread unless (a) there has been a **long quiet period** with no resolution, or (b) genuinely new evidence/decision changed state.

## 3. What does *not* close a task

- Otto or model “recommendations” without Mark/client sign-off in Tasks.
- Discord between **Documents** unless **precedence** is defined (default: **approved task comment on this project > project Documents > pasted threads** when Mark has said the project is source of truth).

## 4. Report output (optional periodic)

For long engagements, a short bullet **status report** comment on the main epic or program task: counts of **closed this pass**, **blocked on client**, **needs Mark only**, **needs internal build**.

## 5. Mandatory preflight (cannot skip)

Before posting a task report or changing any task status, complete this checklist in order:

1. Read all open task threads in scope.
2. Read comments on all relevant project Documents in scope.
3. Reconcile conflicts (task-comment precedence unless Mark states otherwise).
4. Only then post the report and/or close tasks.

If step 2 (document comments) is not completed, the pass is **incomplete** and must not be presented as final.

## 6. Document comment coverage (required)

- During each pass, review **document comments** (not only task comments) for project docs that influence scope, pricing, assets, or governance.
- If a document comment introduces a decision, mirror that decision into the **canonical task thread** (or create one) before closing work.
- Do not leave “hidden decisions” living only on document threads; task closures must still cite a task comment and/or document id.

---

*Adopted from ProSpikeFlow / Gutor pass (2026-05).*
