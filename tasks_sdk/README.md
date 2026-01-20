# Tasks SDK

Python SDK for tasks.technonomicon.net API.

## Installation

```bash
pip install -e .
```

Or install dependencies manually:

```bash
pip install requests>=2.31.0
```

## Usage

```python
from tasks_sdk import TasksClient

# Initialize client
client = TasksClient(
    api_key="your_api_key_here",
    base_url="https://tasks.technonomicon.net"
)

# Create a task
task = client.create_task(
    title="Fix deployment bug",
    status="todo",
    assigned_to_user_id=1
)

# List tasks
result = client.list_tasks(status="todo", limit=10)
for task in result['tasks']:
    print(task['title'])

# Update a task
client.update_task(
    task_id=123,
    status="doing",
    assigned_to_user_id=2
)

# Get a task
task = client.get_task(123)

# Delete a task
client.delete_task(123)
```

## API Methods

- `health()` - Check API health and get user info
- `create_task(title, status=None, assigned_to_user_id=None)` - Create a new task
- `update_task(task_id, title=None, status=None, assigned_to_user_id=None)` - Update a task
- `get_task(task_id)` - Get a single task
- `list_tasks(status=None, assigned_to_user_id=None, limit=None, offset=0)` - List tasks
- `delete_task(task_id)` - Delete a task

## Error Handling

The SDK raises custom exceptions:

- `AuthenticationError` - Invalid or missing API key
- `NotFoundError` - Task not found
- `ValidationError` - Invalid request data
- `APIError` - Other API errors

## License

(Add license information here)
