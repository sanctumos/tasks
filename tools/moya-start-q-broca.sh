#!/bin/bash
# Start Q Broca on moya (223) — parent .env then broca/.env.
SESSION_NAME="broca-q"
PROJECT_DIR="/home/rizzn/sanctum/agents/q/broca"
PARENT_DIR="/home/rizzn/sanctum/agents/q"
VENV_DIR="/home/rizzn/sanctum/venv"

CMD="cd $PROJECT_DIR && export \$(grep -v '^#' $PARENT_DIR/.env | grep -v '^$' | xargs) && export \$(grep -v '^#' .env | grep -v '^$' | xargs) && mkdir -p run && exec $VENV_DIR/bin/python main.py >> run/broca-q.log 2>&1"

screen -S "$SESSION_NAME" -X quit 2>/dev/null || true
sleep 1
screen -dmS "$SESSION_NAME" bash -c "$CMD"
echo "started screen $SESSION_NAME"
