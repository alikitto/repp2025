#!/bin/bash
set -eo pipefail
cd "$(dirname "$0")/.."
php scripts/backup_drive.php
