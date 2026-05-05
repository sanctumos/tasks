# Tasks SMCP Plugin

SMCP plugin for interacting with the Sanctum Tasks API. This plugin provides MCP tools that allow AI agents to create, update, list, and manage tasks.

## Installation

1. **Copy plugin to SMCP plugins directory:**
   ```bash
   cp -r tasks /path/to/smcp/plugins/
   ```

2. **Make CLI executable:**
   ```bash
   chmod +x /path/to/smcp/plugins/tasks/cli.py
   ```

3. **Install dependencies:**
   ```bash
   cd /path/to/smcp/plugins/tasks
   pip install -r requirements.txt
   ```

## Configuration

The plugin is configured with:
- **Base URL**: Hard-coded to `https://tasks.example.com` (set to your Sanctum Tasks server)
- **API Key**: Must be provided as `--api-key` argument for all commands

## Available Commands

### create-task

Create a new task.

**Parameters:**
- `--title` (required): Task title
- `--project-id` (required unless `--list-id`): Directory workspace project id
- `--list-id` (optional): To-do list id; task inherits that list’s project (alternative to `--project-id`)
- `--status` (optional): Task status slug (default: `todo`)
- `--assigned-to-user-id` (optional): User ID to assign task to
- `--priority` (optional): `low|normal|high|urgent`
- `--project` (optional): Legacy display name; use `--project-id` for the real link
- `--tags` (optional): Comma-separated tags
- `--due-at` (optional): Due datetime (ISO-ish)
- `--rank` (optional): Ordering rank
- `--recurrence-rule` (optional): Recurrence rule string

**Example:**
```bash
python cli.py create-task \
  --api-key "YOUR_API_KEY" \
  --title "Fix deployment bug" \
  --status "todo" \
  --project-id 1 \
  --assigned-to-user-id 1
```

### update-task

Update an existing task.

**Parameters:**
- `--task-id` (required): Task ID
- `--title` (optional): New title
- `--status` (optional): New status slug
- `--assigned-to-user-id` (optional): New assigned user ID (set to null to unassign)
- `--unassign` (optional): Explicitly clear assignee
- `--body` (optional): New task description/details
- `--clear-body` (optional): Clear task body
- `--project-id` / `--list-id` (optional): Change which directory project the task belongs to
- `--clear-project-link` (removed): The API no longer allows detaching a task from its workspace project
- `--priority`, `--project`, `--tags`, `--due-at`, `--rank`, `--recurrence-rule` (optional metadata updates)

**Example:**
```bash
python cli.py update-task \
  --api-key "YOUR_API_KEY" \
  --task-id 123 \
  --status "doing" \
  --assigned-to-user-id 2
```

### list-tasks

List tasks with optional filtering and pagination.

**Parameters:**
- `--status` (optional): Filter by status slug
- `--assigned-to-user-id` (optional): Filter by assigned user ID
- `--priority` (optional): Filter by priority
- `--project` (optional): Filter by project
- `--q` (optional): Full-text search in title/body
- `--sort-by` / `--sort-dir` (optional): Sort controls
- `--limit` (optional): Maximum number of tasks to return (max: 500, default: 100)
- `--offset` (optional): Number of tasks to skip (default: 0)

**Example:**
```bash
python cli.py list-tasks --api-key "YOUR_API_KEY" --status "todo" --limit 10 --offset 0
```

### get-task

Get a single task by ID.

**Parameters:**
- `--task-id` (required): Task ID

**Example:**
```bash
python cli.py get-task --api-key "YOUR_API_KEY" --task-id 123
```

### delete-task

Delete a task by ID.

**Parameters:**
- `--task-id` (required): Task ID

**Example:**
```bash
python cli.py delete-task --api-key "YOUR_API_KEY" --task-id 123
```

## Plugin Description

The plugin implements the `--describe` command for structured metadata:

```bash
python cli.py --describe
```

This returns JSON metadata about the plugin and all available commands with their parameter schemas.

## Testing

Test the plugin directly:

```bash
# Test create task
python cli.py create-task \
  --api-key "YOUR_API_KEY" \
  --title "Test Task" \
  --status "todo"

# Test list tasks
python cli.py list-tasks --api-key "YOUR_API_KEY" --limit 5

# Test get task
python cli.py get-task --api-key "YOUR_API_KEY" --task-id 1
```

## Error Handling

The plugin returns structured JSON responses with error information:

```json
{
  "status": "error",
  "error": "Error message",
  "error_type": "validation_error|api_error|not_found|unknown_error"
}
```

## Dependencies

- Python 3.7+
- `tasks_sdk` (included in parent directory)
- `requests` library

## License

This project is licensed under the terms in the repository root [`LICENSE`](../../LICENSE) file.
