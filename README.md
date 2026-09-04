# Tutor CRM

PHP/MySQL CRM для репетиторов: ученики, расписание, посещения, оплаты, Telegram, бэкап на Google Drive.

## Локально

- Скопируй `.env.example` в `.env`, задай `DB_*`, `APP_SECRET` и `APP_URL`
- `php -S 127.0.0.1:3000 router.php`

## VPS (nginx)

- Конфиг: `deploy/nginx.conf` — подставь домен, `root`, сокет php-fpm
- В `.env` задай `APP_SECRET` и `APP_URL=https://YOUR_DOMAIN`
- HTTPS: `certbot --nginx -d YOUR_DOMAIN`
- MySQL только на 127.0.0.1

## База данных

- Свежая установка: импорт [schema.sql](schema.sql)
- При первом старте PHP догоняет схему (`SCHEMA_VERSION` в `app_settings`); дальше `ensure_*` не гоняются на каждый запрос

## Переменные окружения

- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
- `TZ=Asia/Baku`
- `APP_SECRET` — шифрование токенов и дампов. Тот же ключ нужен для restore. Если не задан, создаётся `data/app_secret`, но только при запуске из CLI: в веб-запросе будет ошибка, чтобы ключ не оказался эфемерным
- `APP_URL` — публичный URL, обязателен для Google OAuth
- `TRUST_PROXY=1` — доверять `X-Forwarded-*` (только за nginx/Railway; иначе клиент подделает IP)
- Telegram: Bot token в Настройках (админ), Chat ID — у учителя

## Деплой

- Railway: New Project → Deploy from GitHub (Dockerfile в корне). За прокси задай `TRUST_PROXY=1`
- В контейнере `APP_SECRET` задавай переменной окружения: файловый ключ внутри образа пропадёт при редеплое, и токены с дампами станут нечитаемыми
- `data/` (снимки pre-restore) стоит вынести на постоянный том, иначе содержимое исчезнет вместе с контейнером
- Cron должников: `php scripts/notify_debtors.php` каждые 5 минут (`*/5 * * * *`)
- Cron бэкапа: `0 * * * * php /path/to/Tutor/scripts/backup_drive.php`

## Бэкап

- Дамп полный (пароли, 2FA, токены). Файл шифруется AES-256-GCM (`APP_SECRET` / `data/app_secret`) и подписывается `-- SIG`
- Настройки → Интеграции → Client ID / Secret → Подключить Диск (`APP_URL` должен совпадать с Redirect URI)
- Частота — в Настройках (админ). Принудительно: `php scripts/backup_drive.php --force`
- Восстановление (CLI, перезапишет базу; тот же `APP_SECRET`):
  - локальный файл: `php scripts/restore_backup.php backup.sql.gz.enc --yes`
  - список на Диске: `php scripts/restore_backup.php --list`
  - последний с Диска: `php scripts/restore_backup.php --latest --yes`
  - файл с Диска: `php scripts/restore_backup.php --drive NAME.sql.gz.enc --yes`
- Перед импортом пишется снимок `data/pre-restore-*.sql.gz.enc` — им можно откатиться тем же скриптом
- Старые файлы без подписи не встанут. Другой `APP_SECRET` не расшифрует дамп

После деплоя смени пароли `webmaster` и `irada`, если они уже есть в базе.
