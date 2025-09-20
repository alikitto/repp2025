#!/bin/bash

# Останавливаем скрипт при любой ошибке
set -eo pipefail

echo ">>> Starting database backup..."

FILENAME="db_backup_$(date +'%Y-%m-%d_%H-%M-%S').sql.gz"
FILEPATH="/tmp/$FILENAME"

# 1. Делаем дамп базы и сжимаем
mysqldump -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" -P"$DB_PORT" "$DB_NAME" | gzip > "$FILEPATH"

echo ">>> Backup created and compressed at $FILEPATH"

CONTENT=$(base64 < "$FILEPATH")

# 2. Отправляем письмо через SendGrid API
echo ">>> Sending email via SendGrid..."
curl --fail --silent --show-error \
  --request POST \
  --url https://api.sendgrid.com/v3/mail/send \
  --header "Authorization: Bearer $SENDGRID_API_KEY" \
  --header 'Content-Type: application/json' \
  --data @- <<EOF
{
  "personalizations": [{
    "to": [{"email": "$TO_EMAIL"}]
  }],
  "from": {
    "email": "info@alasgarov.az",
    "name": "CRM Backups"
  },
  "subject": "Ежедневный бэкап базы данных $DB_NAME",
  "content": [{
    "type": "text/plain",
    "value": "Во вложении находится автоматический бэкап базы данных '$DB_NAME' от $(date +'%Y-%m-%d %H:%M:%S')."
  }],
  "attachments": [{
    "content": "$CONTENT",
    "filename": "$FILENAME",
    "type": "application/gzip",
    "disposition": "attachment"
  }]
}
EOF

echo ">>> Email sent successfully."

# 3. Удаляем временный файл
rm "$FILEPATH"
echo ">>> Cleanup complete. Backup process finished."
