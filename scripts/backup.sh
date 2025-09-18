#!/bin/bash

# Скрипт для создания и отправки бэкапа базы данных на Railway

set -e # Прерывать выполнение при любой ошибке

echo ">>> Starting database backup..."

# Формируем имя файла с датой
FILENAME="db_backup_$(date +'%Y-%m-%d_%H-%M-%S').sql.gz"
FILEPATH="/tmp/$FILENAME"

# 1. Создаем дамп и сразу сжимаем его.
#    Данные для подключения (DB_USER, DB_HOST и т.д.) берутся из переменных окружения Railway.
mysqldump -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" -P"$DB_PORT" "$DB_NAME" | gzip > $FILEPATH

echo ">>> Backup created and compressed at $FILEPATH"

# 2. Кодируем файл в base64 для отправки через API
CONTENT=$(base64 < "$FILEPATH")

# 3. Отправляем письмо через SendGrid API с помощью curl
echo ">>> Sending email via SendGrid..."

curl --request POST \
  --url https://api.sendgrid.com/v3/mail/send \
  --header "Authorization: Bearer $SENDGRID_API_KEY" \
  --header 'Content-Type: application/json' \
  --data @- <<EOF
{
  "personalizations": [
    {
      "to": [
        {
          "email": "$TO_EMAIL"
        }
      ]
    }
  ],
  "from": {
    "email": "backup@your-project-name.com",
    "name": "Railway Backup Service"
  },
  "subject": "Ежедневный бэкап базы данных $DB_NAME",
  "content": [
    {
      "type": "text/plain",
      "value": "Во вложении находится автоматический бэкап базы данных '$DB_NAME' от $(date +'%Y-%m-%d %H:%M:%S')."
    }
  ],
  "attachments": [
    {
      "content": "$CONTENT",
      "filename": "$FILENAME",
      "type": "application/gzip",
      "disposition": "attachment"
    }
  ]
}
EOF

echo ""
echo ">>> Email sent successfully."

# 4. Удаляем временный файл
rm $FILEPATH

echo ">>> Cleanup complete. Backup process finished."
