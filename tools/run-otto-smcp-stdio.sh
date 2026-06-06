#!/usr/bin/env bash
# Otto → DSC Tasks via local SMCP stdio (sanctumos/smcp + smcp_plugin/tasks).
# API key from pass file each launch — never commit secrets into this script.
set -euo pipefail

RUNTIME="${OTTO_SMCP_RUNTIME:-$HOME/.otto-local/smcp-runtime}"
SMCP_SRC="${OTTO_SMCP_SRC:-$HOME/projects/smcp}"
TASKS_ROOT="${OTTO_SANCTUM_TASKS_ROOT:-$HOME/projects/sanctum-tasks}"
PASS_FILE="${OTTO_TASKS_PASS_FILE:-$HOME/.ssh/tasks-dsc-ottovernal.pass}"

if [ -f "$RUNTIME/env.paths" ]; then
  # shellcheck disable=SC1090
  set -a && . "$RUNTIME/env.paths" && set +a
fi

if [ ! -f "$PASS_FILE" ]; then
  echo "Missing Tasks pass file: $PASS_FILE" >&2
  exit 2
fi
# shellcheck disable=SC1090
set -a && . "$PASS_FILE" && set +a

if [ -z "${TASKS_DSC_OTTOVERNAL_API_KEY:-}" ]; then
  echo "TASKS_DSC_OTTOVERNAL_API_KEY not set in $PASS_FILE" >&2
  exit 2
fi

VENV_PY="$RUNTIME/.venv/bin/python"
if [ ! -x "$VENV_PY" ]; then
  echo "SMCP runtime not installed. Run: $TASKS_ROOT/tools/install-otto-smcp-runtime.sh" >&2
  exit 2
fi

export MCP_PLUGINS_DIR="${MCP_PLUGINS_DIR:-$RUNTIME/plugins}"
export PYTHONPATH="${TASKS_ROOT}/smcp_plugin:${TASKS_ROOT}${PYTHONPATH:+:$PYTHONPATH}"
export TASKS_API_BASE_URL="${TASKS_DSC_BASE_URL%/}"
export TASKS_SMCP_API_KEY="${TASKS_DSC_OTTOVERNAL_API_KEY}"
export SMCP_ATTACH_PROFILE="${SMCP_ATTACH_PROFILE:-chatter}"

cd "$SMCP_SRC"
exec "$VENV_PY" smcp_stdio.py
