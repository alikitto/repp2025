# Tutor CRM — полная сборка (обновлённая)

**Что внутри**
- Совместимо с исходной структурой (`add/`, `profile/`).
- Безопаснее: подготовленные выражения, `htmlspecialchars`, CSRF-токены для POST.
- Нормализованное расписание в таблице `schedule` (вместо day1/time1 в `stud`).
- «Кто сегодня», «Должники», страница ученика, массовая отметка посещений.
- Страница оплат/балансов: долг/вперёд/ок.
- Telegram-уведомления о должниках за 15 минут до урока (отдельный скрипт).
- Готово к Railway (Dockerfile + переменные окружения).

## База данных
- Выполни `03_schema.sql` (и при желании `04_seed.sql`) против Railway-MySQL (через DBeaver).
- Шесть таблиц: `users, stud, schedule, dates, pays, tg_notifications`.

## Переменные окружения (Railway → Variables)
- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
- `TZ=Asia/Baku`
- (для уведомлений) `TG_BOT_TOKEN`, `TG_CHAT_ID`

## Деплой
- Положи всё в репозиторий GitHub.
- Railway: New Project → Deploy from GitHub (Dockerfile в корне).
- Второй сервис для крон-скрипта: команда `php scripts/notify_debtors.php`, Cron `*/5 * * * *`.
