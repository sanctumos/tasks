# tasks.technonomicon.net API (v1)

## Auth

All `/api/*` endpoints require:

- Header: `X-API-Key: <token>`

Each API key maps to a **user** in the `users` table.

## Task fields

- `id` (int)
- `title` (string)
- `body` (string|null): Task description/details
- `status` (string): `todo` | `doing` | `done`
- `created_by_user_id` (int)
- `assigned_to_user_id` (int|null)
- `created_at` (string)
- `updated_at` (string)

Server-returned convenience fields:
- `created_by_username` (string)
- `assigned_to_username` (string|null)

## Endpoints

### GET `/api/health.php`

Returns authenticated user info.

```bash
curl -sS -H "X-API-Key: $TASKS_API_KEY" https://tasks.technonomicon.net/api/health.php
```

### GET `/api/list-tasks.php`

Query params:
- `status` (optional): `todo|doing|done`
- `assigned_to_user_id` (optional): integer
- `limit` (optional): integer (max 500; default 100)
- `offset` (optional): integer (default 0)

```bash
curl -sS -H "X-API-Key: $TASKS_API_KEY" "https://tasks.technonomicon.net/api/list-tasks.php?status=todo&limit=50"
```

### GET `/api/get-task.php?id=<id>`

```bash
curl -sS -H "X-API-Key: $TASKS_API_KEY" "https://tasks.technonomicon.net/api/get-task.php?id=123"
```

### POST `/api/create-task.php`

JSON body:
- `title` (required)
- `body` (optional; nullable): Task description/details
- `status` (optional)
- `assigned_to_user_id` (optional; nullable)

Notes:
- `created_by_user_id` is set automatically from the API key's mapped user.

```bash
curl -sS -X POST \
  -H "X-API-Key: $TASKS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"title":"Investigate deployment error","body":"Check logs and deployment status","status":"todo","assigned_to_user_id":1}' \
  https://tasks.technonomicon.net/api/create-task.php
```

### POST `/api/update-task.php`

JSON body:
- `id` (required)
- `title` (optional)
- `body` (optional; nullable; set to `null` or empty string to clear)
- `status` (optional)
- `assigned_to_user_id` (optional; set to `null` to unassign)

```bash
curl -sS -X POST \
  -H "X-API-Key: $TASKS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"id":123,"status":"done"}' \
  https://tasks.technonomicon.net/api/update-task.php
```

### POST `/api/delete-task.php`

JSON body:
- `id` (required)

```bash
curl -sS -X POST \
  -H "X-API-Key: $TASKS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"id":123}' \
  https://tasks.technonomicon.net/api/delete-task.php
```

## Default admin user

On first run, the database initialization creates:

- Username: `admin`
- Password: `go0dp4ssw0rd`

Change this as soon as practical.

