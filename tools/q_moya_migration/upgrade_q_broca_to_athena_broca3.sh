#!/bin/bash
# Align Q Broca with Athena's live broca-3 on moya; preserve q_vernal_webchat + sanctum.db.
set -euo pipefail

Q_BROCA="${Q_BROCA:-$HOME/sanctum/agents/q/broca}"
ATHENA_BROCA="${ATHENA_BROCA:-$HOME/sanctum/agents/athena/broca}"
STAMP="${STAMP:-$(date +%Y%m%d-%H%M%S)}"
BACKUP_ROOT="${BACKUP_ROOT:-$HOME/sanctum/migration-q-moya-20260528/broca-upgrade-$STAMP}"
VENV="${VENV:-$HOME/sanctum/venv}"

mkdir -p "$BACKUP_ROOT"

echo "== Backup ($BACKUP_ROOT)"
for f in sanctum.db .env settings.json; do
  [[ -f "$Q_BROCA/$f" ]] && cp -a "$Q_BROCA/$f" "$BACKUP_ROOT/$f"
done
[[ -f "$Q_BROCA/../.env" ]] && cp -a "$Q_BROCA/../.env" "$BACKUP_ROOT/parent.env"
[[ -d "$Q_BROCA/plugins/q_vernal_webchat" ]] && cp -a "$Q_BROCA/plugins/q_vernal_webchat" "$BACKUP_ROOT/plugins-q_vernal_webchat"
python3 -c "
import sqlite3, os
src=os.path.join('$Q_BROCA','sanctum.db')
if os.path.isfile(src):
    s=sqlite3.connect('file:'+src+'?mode=ro', uri=True)
    d=sqlite3.connect(os.path.join('$BACKUP_ROOT','sanctum.db.checkpoint'))
    s.backup(d); s.close(); d.close(); print('sqlite checkpoint ok')
"

echo "== Rsync Athena broca -> Q (preserve webchat plugin + DB)"
rsync -a --delete \
  --exclude '.env' \
  --exclude 'sanctum.db' \
  --exclude 'sanctum.db.*' \
  --exclude 'settings.json' \
  --exclude 'broca2.pid' \
  --exclude 'plugins/q_vernal_webchat' \
  --exclude 'run/' \
  --exclude '.git' \
  "$ATHENA_BROCA/" "$Q_BROCA/"

if [[ -d "$BACKUP_ROOT/plugins-q_vernal_webchat" ]]; then
  mkdir -p "$Q_BROCA/plugins"
  cp -a "$BACKUP_ROOT/plugins-q_vernal_webchat" "$Q_BROCA/plugins/q_vernal_webchat"
fi
mkdir -p "$Q_BROCA/run"

echo "== pip install"
"$VENV/bin/python3" -m pip install -q -r "$Q_BROCA/requirements.txt"

echo "== verify imports"
cd "$Q_BROCA"
set -a
[[ -f ../.env ]] && source ../.env
[[ -f .env ]] && source .env
set +a
"$VENV/bin/python3" -c "
from runtime.core.letta_client import get_letta_client
import importlib
importlib.import_module('plugins.q_vernal_webchat.plugin')
print('q_broca_ok')
"
echo "Done backup=$BACKUP_ROOT"
