#!/bin/bash
set -e

if [[ "$RUN_MODE" == "backup" ]]; then
  echo ">>> Running backup job..."
  bash scripts/backup.sh
else
  echo ">>> Container started in normal mode (idle for cron)."
  tail -f /dev/null
fi
