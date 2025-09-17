<?php
session_start();
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';

$is_unlocked = false;
$error_message = '';

// --- Логика проверки ключа ---
if (isset($_SESSION['fkey_unlocked']) && $_SESSION['fkey_unlocked'] === true) {
    $is_unlocked = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fkey'])) {
    $submitted_key = $_POST['fkey'];
    // Предполагаем, что ключ проверяется для администратора с id = 1
    $st = $con->prepare("SELECT fkey FROM users WHERE id = 1 LIMIT 1");
    $st->execute();
    $result = $st->get_result()->fetch_assoc();
    $st->close();

    if ($result && $submitted_key === $result['fkey']) {
        $_SESSION['fkey_unlocked'] = true;
        $is_unlocked = true;
    } else {
        $error_message = 'Неверный ключ доступа';
    }
}

// --- Если доступ открыт, получаем финансовые данные ---
if ($is_unlocked) {
    // Доход за этот месяц
    $income_this_month = $con->query("SELECT COALESCE(SUM(amount), 0) AS total FROM pays WHERE MONTH(date) = MONTH(CURDATE()) AND YEAR(date) = YEAR(CURDATE())")->fetch_assoc()['total'];

    // Доход за прошлый месяц
    $income_last_month = $con->query("SELECT COALESCE(SUM(amount), 0) AS total FROM pays WHERE MONTH(date) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR(date) = YEAR(CURDATE() - INTERVAL 1 MONTH)")->fetch_assoc()['total'];

    // Всего получено
    $income_total = $con->query("SELECT COALESCE(SUM(amount), 0) AS total FROM pays")->fetch_assoc()['total'];

    // Расчет долгов (более сложный запрос)
    $debt_query = $con->query("
        SELECT
            SUM(CASE WHEN lesson_balance < 0 THEN debt_amount ELSE 0 END) as total_debt,
            SUM(CASE WHEN lesson_balance <= -8 THEN debt_amount ELSE 0 END) as severe_debt
        FROM (
            SELECT
                s.user_id,
                (COALESCE((SELECT SUM(p.lessons) FROM pays p WHERE p.user_id = s.user_id), 0) - (SELECT COUNT(*) FROM dates d WHERE d.user_id = s.user_id AND d.visited = 1)) AS lesson_balance,
                GREATEST(0, ((SELECT COUNT(*) FROM dates d WHERE d.user_id = s.user_id AND d.visited = 1) * s.money) - COALESCE((SELECT SUM(p.amount) FROM pays p WHERE p.user_id = s.user_id), 0)) AS debt_amount
            FROM stud s
        ) AS student_balances
    ")->fetch_assoc();

    $total_debt = $debt_query['total_debt'] ?? 0;
    $severe_debt = $debt_query['severe_debt'] ?? 0;
}

$active = 'finance'; // Для подсветки в меню навигации
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmt($num) { return number_format((float)$num, 2, '.', ' '); }
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Финансы — Tutor CRM</title>
    <link href="/profile/css/style.css" rel="stylesheet">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr); /* 3 колонки */
            gap: 16px;
        }
        .stat-card {
            background-color: #fff;
            padding: 20px;
            border-radius: 12px;
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .stat-card .icon {
            flex-shrink: 0;
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
        }
        .stat-card .icon.blue { background-color: #0d6efd; }
        .stat-card .icon.green { background-color: #198754; }
        .stat-card .icon.purple { background-color: #6f42c1; }
        .stat-card .icon svg { width: 24px; height: 24px; }
        .stat-card .value {
            font-size: 24px;
            font-weight: 600;
            line-height: 1.2;
        }
        .stat-card .label {
            font-size: 14px;
            color: var(--muted);
        }

        .debt-list { list-style: none; padding: 0; margin: 0; }
        .debt-list li {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
        }
        .debt-list li:last-child { border-bottom: none; }
        .debt-list .icon { color: #dc3545; }
        .debt-list .value { margin-left: auto; font-weight: 500; }

        .lock-container {
            max-width: 400px;
            margin: 50px auto;
            text-align: center;
        }
        .lock-container .input { text-align: center; font-size: 16px; }
        .error { color: #dc3545; margin-top: 8px; }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr; /* 1 колонка на мобильных */
            }
        }
    </style>
</head>
<body>

<?php require __DIR__ . '/../common/nav.php'; ?>

<div class="content">
    <?php if ($is_unlocked): ?>
        <h1>Финансовая сводка</h1>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="icon green">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"></path><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"></path><path d="M18 12a2 2 0 0 0 0 4h4v-4h-4z"></path></svg>
                </div>
                <div>
                    <div class="value"><?= fmt($income_this_month) ?> AZN</div>
                    <div class="label">Доход за этот месяц</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="icon blue">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                </div>
                <div>
                    <div class="value"><?= fmt($income_last_month) ?> AZN</div>
                    <div class="label">Доход за прошлый месяц</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="icon purple">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>
                </div>
                <div>
                    <div class="value"><?= fmt($income_total) ?> AZN</div>
                    <div class="label">Всего получено</div>
                </div>
            </div>
        </div>

        <div class="card" style="margin-top: 24px;">
            <h2>Задолженности</h2>
            <ul class="debt-list">
                <li>
                    <span class="icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                    </span>
                    <span>Долгов всего:</span>
                    <span class="value"><?= fmt($total_debt) ?> AZN</span>
                </li>
                <li>
                    <span class="icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.46 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                    </span>
                    <span>Долги у "злостных" должников (>8 уроков):</span>
                    <span class="value"><?= fmt($severe_debt) ?> AZN</span>
                </li>
            </ul>
        </div>

    <?php else: ?>
        <div class="card lock-container">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 16px; color: var(--muted);"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
            <h2>Доступ к финансам</h2>
            <p class="muted">Для просмотра этой страницы введите ваш ключ безопасности.</p>
            <form method="POST" action="/profile/finance.php" style="margin-top: 16px;">
                <input type="password" name="fkey" class="input" placeholder="••••••••••" required autofocus>
                <?php if ($error_message): ?>
                    <p class="error"><?= $error_message ?></p>
                <?php endif; ?>
                <button type="submit" class="btn primary" style="margin-top: 12px; width: 100%;">Войти</button>
            </form>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
