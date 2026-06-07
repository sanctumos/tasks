#!/usr/bin/env bash
# Prod Ask Q smoke: bridge seed + optional Playwright (run from workspace).
set -euo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
SMOKE_SSH="${Q_PROD_SMOKE_SSH:-}"
PLAYWRIGHT="${Q_PROD_SMOKE_PLAYWRIGHT:-1}"

echo "== Q prod smoke =="

if [[ -n "$SMOKE_SSH" ]]; then
  echo "Seeding bridge message on $SMOKE_SSH ..."
  sshpass -f ~/.ssh/multihost.pass ssh -o StrictHostKeyChecking=no root@multihost \
    "cd /root/repos/tasks.decisionsciencecorp.com && php tools/e2e_q_bridge_seed_message.php"
else
  echo "Skip remote seed (set Q_PROD_SMOKE_SSH=multihost to run on box)"
fi

if [[ "$PLAYWRIGHT" == "1" ]] && [[ -f "$REPO/tools/design-smoke/ask_q_prod_verify.py" ]]; then
  if [[ -f "$HOME/.ssh/tasks-dsc-ottovernal.pass" ]]; then
    set -a && . "$HOME/.ssh/tasks-dsc-ottovernal.pass" && set +a
  fi
  echo "Playwright prod verify ..."
  python3 "$REPO/tools/design-smoke/ask_q_prod_verify.py"
else
  echo "Skip Playwright (Q_PROD_SMOKE_PLAYWRIGHT=0 or script missing)"
fi

echo "OK q_prod_smoke"
