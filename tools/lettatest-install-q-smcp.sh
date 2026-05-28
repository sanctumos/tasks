#!/usr/bin/env bash
# Install SMCP + q_vernal_tasks on lettatest (run from workspace via ssh).
set -euo pipefail
HOST="${LETTA_TEST_HOST:-root@64.95.12.16}"
PASS_FILE="${LETTA_TEST_PASS:-$HOME/.ssh/lettatest-root.pass}"
SMCP_ROOT="/opt/sanctum-q/smcp"
TASKS_ROOT="/opt/sanctum-q/sanctum-tasks"
BROCA_Q="/opt/broca-q"

sshpass -f "$PASS_FILE" ssh -o StrictHostKeyChecking=no "$HOST" "mkdir -p $SMCP_ROOT $TASKS_ROOT $BROCA_Q/run"

sshpass -f "$PASS_FILE" rsync -az --delete \
  --exclude '.git' --exclude '__pycache__' --exclude '.venv' \
  /root/projects/smcp/ "$HOST:$SMCP_ROOT/"

sshpass -f "$PASS_FILE" rsync -az \
  --exclude '.git' --exclude 'public' --exclude 'tests' --exclude 'db' \
  /root/projects/sanctum-tasks/ "$HOST:$TASKS_ROOT/"

sshpass -f "$PASS_FILE" ssh -o StrictHostKeyChecking=no "$HOST" "bash -s" <<REMOTE
set -e
SMCP_ROOT="$SMCP_ROOT"
TASKS_ROOT="$TASKS_ROOT"
mkdir -p "\$SMCP_ROOT/plugins"
rm -rf "\$SMCP_ROOT/plugins/q_vernal_tasks" "\$SMCP_ROOT/plugins/tasks"
cp -a "\$TASKS_ROOT/smcp_plugin/q_vernal_tasks" "\$SMCP_ROOT/plugins/"
cp -a "\$TASKS_ROOT/smcp_plugin/tasks" "\$SMCP_ROOT/plugins/"
chmod +x "\$SMCP_ROOT/plugins/q_vernal_tasks/cli.py" "\$SMCP_ROOT/plugins/tasks/cli.py"
# Legacy deploys copied the plugin to \$TASKS_ROOT/tasks/ — shadows smcp_plugin/tasks on import.
if [ -d "\$TASKS_ROOT/tasks" ] && [ -f "\$TASKS_ROOT/tasks/cli.py" ]; then
  rm -rf "\$TASKS_ROOT/tasks"
fi

# Q must use q_vernal_tasks__* only (per-chatter API key injection). Bare tasks__* and
# demo plugins confuse the model and bypass key resolution.
for p in tasks demo_math demo_text; do
  if [ -d "\$SMCP_ROOT/plugins/\$p" ]; then
    rm -rf "\$SMCP_ROOT/plugins/\${p}.disabled"
    mv "\$SMCP_ROOT/plugins/\$p" "\$SMCP_ROOT/plugins/\${p}.disabled"
  fi
done

if [ ! -d "\$SMCP_ROOT/.venv" ]; then
  python3 -m venv "\$SMCP_ROOT/.venv"
  "\$SMCP_ROOT/.venv/bin/pip" install -q -r "\$SMCP_ROOT/requirements.txt"
fi
"\$SMCP_ROOT/.venv/bin/pip" install -q -r "\$SMCP_ROOT/plugins/tasks/requirements.txt" 2>/dev/null || true

POLL=\$(grep '^TASKS_Q_BRIDGE_POLL_API_KEY=' "$BROCA_Q/.env" 2>/dev/null | cut -d= -f2- | tr -d '\"' || true)
if [ -z "\$POLL" ]; then
  POLL=\$(cat /var/www/tasks.decisionsciencecorp.com/db/q_bridge_poll_api_key.txt 2>/dev/null || true)
fi
cat > "\$SMCP_ROOT/env.smcp" <<ENV
MCP_PLUGINS_DIR=\$SMCP_ROOT/plugins
PYTHONPATH=\$TASKS_ROOT/smcp_plugin:\$TASKS_ROOT
TASKS_API_BASE_URL=https://tasks.decisionsciencecorp.com
TASKS_Q_BRIDGE_API_URL=https://tasks.decisionsciencecorp.com/q-bridge/
TASKS_Q_BRIDGE_POLL_API_KEY=\${POLL}
TASKS_Q_CHATTER_FILE=/opt/broca-q/run/current_tasks_user_id.txt
ENV
chmod 600 "\$SMCP_ROOT/env.smcp"

cat > "\$SMCP_ROOT/run-smcp-stdio-for-letta.sh" <<'SCRIPT'
#!/bin/bash
set -e
SMCP_HOME="/opt/sanctum-q/smcp"
cd "\$SMCP_HOME"
VENV_PY="\$SMCP_HOME/.venv/bin/python"
[ -x "\$VENV_PY" ] || VENV_PY=python3
[ -f "\$SMCP_HOME/env.smcp" ] && set -a && . "\$SMCP_HOME/env.smcp" && set +a
export PYTHONPATH="\${SMCP_HOME}/../sanctum-tasks/smcp_plugin:\${SMCP_HOME}/../sanctum-tasks:\${PYTHONPATH:-}"
exec "\$VENV_PY" smcp_stdio.py
SCRIPT
chmod +x "\$SMCP_ROOT/run-smcp-stdio-for-letta.sh"
REMOTE

# Revert broca core on lettatest; sync plugin-only fixes
sshpass -f "$PASS_FILE" rsync -az \
  /root/projects/broca/runtime/core/queue.py \
  "$HOST:$BROCA_Q/runtime/core/"
sshpass -f "$PASS_FILE" ssh "$HOST" "rm -f $BROCA_Q/runtime/core/tasks_tool_context.py"
sshpass -f "$PASS_FILE" rsync -az \
  /root/projects/broca/plugins/q_vernal_webchat/ \
  "$HOST:$BROCA_Q/plugins/q_vernal_webchat/"

echo "OK lettatest SMCP + broca plugin sync"
