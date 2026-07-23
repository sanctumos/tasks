# Access and settings

Access is where task systems usually rot. Either everyone sees everything forever, or nobody knows why they cannot open the thing they need. Tasks takes the middle road: organization, project visibility, membership, role, and person kind all matter.

## Users, organizations, and access

The admin-only **Users** page manages accounts.

Use it for:

- Creating users.
- Disabling and re-enabling users.
- Assigning organization access.
- Setting person kind.
- Resetting passwords.
- Requiring password change on next login.
- Limiting a non-admin user to assigned projects.

Organizations set the broad boundary. Projects set the working boundary. A user can be in the organization and still not need every project.

## Roles and person kind

Roles and person kind are related, but not the same.

Role answers: what power does this account have?

- **Admin** — system-level control.
- **Manager** — elevated operational control where enabled by the deployment.
- **Member** — normal human collaborator.
- **API** — service-style account for automation.

Person kind answers: what kind of participant is this?

- **Team member** — internal collaborator.
- **Client** — external stakeholder with narrower visibility.

Client-visible projects matter because client users should not see internal-only boards by accident.

## Project membership

Project **Members** controls who belongs to a specific project and what project role they have.

Use project membership when:

- A project is not all-access.
- A client needs access to a client-visible board.
- A team member should see one project but not the whole directory.
- A limited manager needs lead authority on a specific board.

Project access is what makes project-scoped work readable. If a user cannot open a project, the problem is usually not the task. It is membership, all-access, client-visible, organization, or role.

## Why Settings matters

Open **Settings** from the top nav.

Settings is not one thing. It is the control panel behind the account and the workspace.

| Tab | What it controls |
|-----|------------------|
| **Password** | Change your own password. Forced password changes land here. |
| **Appearance** | UI skin / presentation preferences where enabled. |
| **MFA** | Time-based one-time password setup for your account. |
| **Archived boards** | Master list of archived boards you can access, with latest ZIP download shortcuts. |
| **API keys** | Admin-only key management for integrations and automation. |
| **Audit log** | Admin-only event trail for security-relevant actions. |
| **Ask Q** | Admin-only rate limits for the Ask Q widget when Q is enabled. |

## Settings: the controls behind the work

The easiest mistake is treating Settings as "account stuff." It is more than that.

Settings now includes a master archived-board shelf. That matters because archive downloads are project-scoped, but people do not always remember which old project they need. The shelf gives them a way back.

## Archived boards in Settings

Path:

**Settings → Archived boards**

This page lists archived boards you can access.

Each row gives you:

- Board name and description.
- Last project update time.
- Latest ZIP status.
- **Open** — goes to the board’s Archive downloads tab.
- **Download** — downloads the latest ready ZIP if one exists.

Use this page when the question is "Where is that old board?" Use the project’s **Archive downloads** tab when the question is "Generate a new ZIP for this board."

## Passwords and MFA

Passwords follow the configured policy. If an admin resets your password and marks it as must-change, you land on **Settings → Password** after login.

MFA uses a TOTP authenticator. Turn it on when the account has meaningful access. If an account can create API keys, manage users, or see client data, MFA is not optional in spirit even if the UI lets you defer it.

## API keys

API keys are for machines, not humans clicking around the browser.

Use API keys for:

- Scripts.
- SDK clients.
- SMCP/MCP tools.
- Agent workflows.
- Integrations.

Do not put API keys in task bodies, comments, public docs, screenshots, or help pages. Reference the integration or key name, not the secret.

## Audit log

Audit is the "what happened?" page for security and admin actions.

Use it when:

- A user was created, disabled, or changed.
- A key was issued or revoked.
- A sensitive setting changed.
- You need to confirm whether the app recorded the action.

Audit is not a replacement for task comments. Audit tells you the system event. Comments tell humans why the event happened.

## Ask Q settings

When Ask Q is enabled, admins get **Settings → Ask Q**.

That page controls rate limits for the webchat widget:

- Messages sent per user per hour.
- Response polling.
- History fetches.
- Session lookups.
- Overall user and IP caps.

These limits protect the app from noisy widget behavior without changing the normal task API. Broca inbox/outbox limits are a separate operator concern.

## Appearance

Appearance controls the admin UI skin where enabled.

Do not confuse a skin problem with a product problem. If a button disappears because the skin made it unreadable, that is a UI bug. If a user cannot see a project because access denies it, that is an access configuration issue.
