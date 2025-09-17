<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';

// 1. Получаем ID ученика и проверяем его
$user_id = (int)($_GET['user_id'] ?? 0);
if ($user_id <= 0) {
    http_response_code(400);
    die('Неверный ID ученика.');
}

// 2. Получаем данные ученика из таблицы stud
$st = $con->prepare("SELECT name, klass, money FROM stud WHERE user_id = ?");
$st->bind_param('i', $user_id);
$st->execute();
$student = $st->get_result()->fetch_assoc();
$st->close();

if (!$student) {
    http_response_code(404);
    die('Ученик не найден.');
}

// 3. Получаем расписание ученика из таблицы schedule
$st = $con->prepare("SELECT weekday, time FROM schedule WHERE user_id = ? ORDER BY id LIMIT 3");
$st->bind_param('i', $user_id);
$st->execute();
$schedule = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// --- Вспомогательные массивы для формы ---
$weekdays_map_num = [1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда', 4 => 'Четверг', 5 => 'Пятница', 6 => 'Суббота', 7 => 'Воскресенье'];
$days = array_values($weekdays_map_num);
$classes = range(5, 11);
$times = [];
for ($h = 9; $h <= 20; $h++) {
    foreach ([0, 30] as $m) {
        if ($h === 20 && $m > 0) continue;
        $times[] = sprintf('%02d:%02d', $h, $m);
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Редактировать ученика — Tutor CRM</title>
    <link rel="stylesheet" href="/profile/css/style.css">
</head>
<body>

<?php require __DIR__ . '/../common/nav.php'; ?>

<div class="content">
    <div class="card">
        <h2>Редактировать ученика</h2>
        <p class="muted">Вы изменяете данные для: <strong><?= h($student['name']) ?></strong></p>

        <form action="/profile/update.php" method="post" class="form">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="user_id" value="<?= $user_id ?>">

            <div class="grid-3">
                <div class="form-group">
                    <label for="name">Имя</label>
                    <input class="input" type="text" id="name" name="name" required value="<?= h($student['name']) ?>">
                </div>

                <div class="form-group">
                    <label for="klass">Класс</label>
                    <select id="klass" name="klass" class="input select-big">
                        <option value="">— не выбрано —</option>
                        <?php foreach ($classes as $k): ?>
                            <option value="<?= $k ?>" <?= ($student['klass'] == $k) ? 'selected' : '' ?>><?= $k ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="money">Оплата (AZN)</label>
                    <input class="input" type="number" id="money" name="money" inputmode="decimal" step="0.01" min="0" value="<?= h($student['money']) ?>">
                </div>
            </div>

            <h3 style="margin:16px 0 8px;">Расписание (до 3 слотов)</h3>
            <table class="table today schedule-table">
                <thead><tr><th>День недели</th><th>Время</th></tr></thead>
                <tbody>
                <?php for ($i = 0; $i < 3; $i++):
                    // Получаем текущий слот или null, если его нет
                    $current_slot = $schedule[$i] ?? null; 
                    // Преобразуем числовой день недели (1) в текстовый ('Понедельник') для сравнения
                    $current_day = $current_slot ? ($weekdays_map_num[$current_slot['weekday']] ?? '') : '';
                    $current_time = $current_slot ? $current_slot['time'] : '';
                ?>
                    <tr>
                        <td>
                            <select class="select-big" name="day[]">
                                <option value="">— не выбрано —</option>
                                <?php foreach ($days as $d): ?>
                                    <option value="<?= h($d) ?>" <?= ($d == $current_day) ? 'selected' : '' ?>><?= h($d) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select class="select-big" name="time[]">
                                <option value="">— — : — —</option>
                                <?php foreach ($times as $t): ?>
                                    <option value="<?= $t ?>" <?= ($t == $current_time) ? 'selected' : '' ?>><?= $t ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>

            <button type="submit" class="btn primary" style="margin-top:12px;">
                Сохранить изменения
            </button>
        </form>
    </div>
</div>
</body>
</html>
