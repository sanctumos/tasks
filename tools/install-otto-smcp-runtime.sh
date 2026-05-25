#!/usr/bin/env bash
# Install Otto local SMCP stdio runtime (sanctumos/smcp + sanctum-tasks tasks plugin).
# Clone source stays in projects/smcp; venv + plugins live outside sanctum-tasks.
set -euo pipefail

SMCP_SRC="${OTTO_SMCP_SRC:-$HOME/projects/smcp}"
TASKS_ROOT="${OTTO_SANCTUM_TASKS_ROOT:-$HOME/projects/sanctum-tasks}"
RUNTIME="${OTTO_SMCP_RUNTIME:-$HOME/.otto-local/smcp-runtime}"
PASS_FILE="${OTTO_TASKS_PASS_FILE:-$HOME/.ssh/tasks-dsc-ottovernal.pass}"

echo "SMCP source:  $SMCP_SRC"
echo "Tasks repo:   $TASKS_ROOT"
echo "Runtime:      $RUNTIME"

if [ ! -d "$SMCP_SRC/.git" ]; then
  echo "Cloning sanctumos/smcp into $SMCP_SRC ..."
  mkdir -p "$(dirname "$SMCP_SRC")"
  if [ -f "$HOME/.ssh/github-token.pass" ]; then
    set -a && . "$HOME/.ssh/github-token.pass" && set +a
    git clone "https://${GITHUB_TOKEN}@github.com/sanctumos/smcp.git" "$SMCP_SRC"
  else
    git clone https://github.com/sanctumos/smcp.git "$SMCP_SRC"
  fi
else
  echo "Updating smcp ..."
  git -C "$SMCP_SRC" pull --ff-only || true
fi

if [ ! -f "$TASKS_ROOT/smcp_plugin/tasks/cli.py" ]; then
  echo "Missing Tasks SMCP plugin at $TASKS_ROOT/smcp_plugin/tasks" >&2
  exit 1
fi

mkdir -p "$RUNTIME/plugins" "$RUNTIME/logs"
ln -sfn "$TASKS_ROOT/smcp_plugin/tasks" "$RUNTIME/plugins/tasks"
chmod +x "$TASKS_ROOT/smcp_plugin/tasks/cli.py"

if [ ! -d "$RUNTIME/.venv" ]; then
  python3 -m venv "$RUNTIME/.venv"
fi
"$RUNTIME/.venv/bin/pip" install -q -U pip
"$RUNTIME/.venv/bin/pip" install -q -r "$SMCP_SRC/requirements.txt"
if [ -f "$TASKS_ROOT/smcp_plugin/tasks/requirements.txt" ]; then
  "$RUNTIME/.venv/bin/pip" install -q -r "$TASKS_ROOT/smcp_plugin/tasks/requirements.txt"
fi

# Non-secret runtime paths (key loaded at launch from pass file, not stored here).
cat > "$RUNTIME/env.paths" <<ENV
# Otto SMCP runtime paths — sourced by run-otto-smcp-stdio.sh
OTTO_SMCP_RUNTIME=$RUNTIME
OTTO_SMCP_SRC=$SMCP_SRC
OTTO_SANCTUM_TASKS_ROOT=$TASKS_ROOT
OTTO_TASKS_PASS_FILE=$PASS_FILE
ENV
chmod 600 "$RUNTIME/env.paths"

cp -f "$TASKS_ROOT/tools/run-otto-smcp-stdio.sh" "$RUNTIME/run-otto-smcp-stdio.sh"
chmod 700 "$RUNTIME/run-otto-smcp-stdio.sh"

# Smoke: describe plugin (requires pass file for optional api-key in schema)
if [ -f "$PASS_FILE" ]; then
  set -a && . "$PASS_FILE" && set +a
  export MCP_PLUGINS_DIR="$RUNTIME/plugins"
  export PYTHONPATH="$TASKS_ROOT:$TASKS_ROOT/smcp_plugin"
  export TASKS_API_BASE_URL="${TASKS_DSC_BASE_URL%/}"
  export TASKS_SMCP_API_KEY="${TASKS_DSC_OTTOVERNAL_API_KEY}"
  if "$RUNTIME/.venv/bin/python" "$TASKS_ROOT/smcp_plugin/tasks/cli.py" --describe | head -c 200 >/dev/null; then
    echo "OK tasks plugin --describe"
  fi
  echo "OK stdio launcher: $RUNTIME/run-otto-smcp-stdio.sh"
fi

echo ""
echo "Installed. Cursor MCP command:"
echo "  $RUNTIME/run-otto-smcp-stdio.sh"
echo "See: $TASKS_ROOT/docs/otto-smcp-cursor.md"
