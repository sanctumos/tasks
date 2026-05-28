#!/usr/bin/env bash
# Install SMCP + q_vernal_tasks on moya (223). Run from workspace via SSH or on-box as rizzn.
set -euo pipefail

MOYA="${MOYA_SSH:-rizzn@sanctum.zero1.network}"
MOYA_PORT="${MOYA_SSH_PORT:-7837}"
PASS_FILE="${MOYA_PASS_FILE:-$HOME/.ssh/athena-moya.pass}"
SMCP_ROOT="/home/rizzn/sanctum/agents/q/smcp"
TASKS_ROOT="/home/rizzn/sanctum/repos/sanctum-tasks"
BROCA_Q="/home/rizzn/sanctum/agents/q/broca"
RSYNC_RSH="sshpass -f ${PASS_FILE} ssh -o StrictHostKeyChecking=no -p ${MOYA_PORT}"

remote() {
  sshpass -f "$PASS_FILE" ssh -o StrictHostKeyChecking=no -p "$MOYA_PORT" "$MOYA" "$@"
}

remote "mkdir -p $SMCP_ROOT/upstream $TASKS_ROOT $BROCA_Q/run"

rsync -az --delete -e "$RSYNC_RSH" \
  --exclude '.git' --exclude '__pycache__' --exclude '.venv' \
  /root/projects/smcp/ "$MOYA:$SMCP_ROOT/upstream/"

rsync -az -e "$RSYNC_RSH" \
  --exclude '.git' --exclude 'public' --exclude 'tests' --exclude 'db' \
  /root/projects/sanctum-tasks/ "$MOYA:$TASKS_ROOT/"

rsync -az -e "$RSYNC_RSH" \
  /root/projects/broca/plugins/q_vernal_webchat/ \
  "$MOYA:$BROCA_Q/plugins/q_vernal_webchat/"

remote "bash -s" <<REMOTE
set -e
SMCP_ROOT="$SMCP_ROOT"
TASKS_ROOT="$TASKS_ROOT"
UP="\$SMCP_ROOT/upstream"
mkdir -p "\$SMCP_ROOT/plugins"
rm -rf "\$SMCP_ROOT/plugins/q_vernal_tasks" "\$SMCP_ROOT/plugins/tasks"
cp -a "\$TASKS_ROOT/smcp_plugin/q_vernal_tasks" "\$SMCP_ROOT/plugins/"
cp -a "\$TASKS_ROOT/smcp_plugin/tasks" "\$SMCP_ROOT/plugins/"
chmod +x "\$SMCP_ROOT/plugins/q_vernal_tasks/cli.py" "\$SMCP_ROOT/plugins/tasks/cli.py"
for p in tasks demo_math demo_text; do
  [ -d "\$SMCP_ROOT/plugins/\$p" ] && mv "\$SMCP_ROOT/plugins/\$p" "\$SMCP_ROOT/plugins/\${p}.disabled" || true
done
if [ ! -d "\$SMCP_ROOT/.venv" ]; then
  python3 -m venv "\$SMCP_ROOT/.venv"
  "\$SMCP_ROOT/.venv/bin/pip" install -q -r "\$UP/requirements.txt"
fi
"\$SMCP_ROOT/.venv/bin/pip" install -q -r "\$SMCP_ROOT/plugins/tasks/requirements.txt" 2>/dev/null || true
POLL=\$(grep '^TASKS_Q_BRIDGE_POLL_API_KEY=' "$BROCA_Q/.env" 2>/dev/null | cut -d= -f2- | tr -d '\"' || true)
cat > "\$SMCP_ROOT/env.smcp" <<ENV
MCP_PLUGINS_DIR=\$SMCP_ROOT/plugins
PYTHONPATH=\$TASKS_ROOT/smcp_plugin:\$TASKS_ROOT
TASKS_API_BASE_URL=https://tasks.decisionsciencecorp.com
TASKS_Q_BRIDGE_API_URL=https://tasks.decisionsciencecorp.com/q-bridge/
TASKS_Q_BRIDGE_POLL_API_KEY=\${POLL}
TASKS_Q_CHATTER_FILE=$BROCA_Q/run/current_tasks_user_id.txt
ENV
chmod 600 "\$SMCP_ROOT/env.smcp"
cat > "\$SMCP_ROOT/run-smcp-stdio-for-letta.sh" <<'SCRIPT'
#!/bin/bash
set -e
SMCP_HOME="/home/rizzn/sanctum/agents/q/smcp"
cd "$SMCP_HOME/upstream"
VENV_PY="$SMCP_HOME/.venv/bin/python"
[ -x "$VENV_PY" ] || VENV_PY=python3
[ -f "$SMCP_HOME/env.smcp" ] && set -a && . "$SMCP_HOME/env.smcp" && set +a
export PYTHONPATH="/home/rizzn/sanctum/repos/sanctum-tasks/smcp_plugin:/home/rizzn/sanctum/repos/sanctum-tasks:${PYTHONPATH:-}"
exec "$VENV_PY" smcp_stdio.py
SCRIPT
chmod +x "\$SMCP_ROOT/run-smcp-stdio-for-letta.sh"
REMOTE

echo "OK moya Q SMCP + plugin sync"
