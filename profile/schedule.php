<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';

// 1. Получаем ВСЕ записи из расписания, сразу объединяя с именами учеников
// Сортируем по дню недели, а затем по времени, чтобы все было по порядку
$all_schedule_flat = $con->query("
    SELECT
        sc.weekday,
        sc.time,
        s.user_id,
        CONCAT(s.lastname, ' ', s.name) AS fio
    FROM schedule sc
    JOIN stud s ON s.user_id = sc.user_id
    ORDER BY sc.weekday, sc.time
")->fetch_all(MYSQLI_ASSOC);

// 2. Группируем полученные данные по дням недели для удобного вывода
$schedule_by_day = [];
foreach ($all_schedule_flat as $item) {
    // Ключом в новом массиве будет номер дня недели (1 для Понедельника и т.д.)
    $schedule_by_day[$item['weekday']][] = $item;
}

// 3. Карта для преобразования номера дня в название
$weekdays_map = [
    1 => 'Понедельник',
    2 => 'Вторник',
    3 => 'Среда',
    4 => 'Четверг',
    5 => 'Пятница',
    6 => 'Суббота',
    7 => 'Воскресенье'
];

// Указываем активный пункт меню для навбара
$active = 'schedule'; 

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Расписание — Tutor CRM</title>
    <link href="/profile/css/style.css" rel="stylesheet">
    <style>
        /* Дополнительные стили для этой страницы */
        .day-block {
            margin-bottom: 24px;
        }
        .day-block h2 {
            margin-bottom: 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid #dee2e6;
        }
    </style>
</head>
<body>

<?php require __DIR__ . '/../common/nav.php'; ?>

<div class="content">
    <div class="card">
        <h1>Расписание на неделю</h1>
        
        <?php foreach ($weekdays_map as $wd_num => $wd_name): ?>
            <div class="day-block">
                <h2><?= $wd_name ?></h2>

                <?php
                // Проверяем, есть ли ученики для этого дня недели в нашем сгруппированном массиве
                $students_for_this_day = $schedule_by_day[$wd_num] ?? [];
                ?>

                <?php if (empty($students_for_this_day)): ?>
                    <p class="muted">В этот день занятий нет.</p>
                <?php else: ?>
                    <table class="table today">
                        <thead>
                            <tr>
                                <th>Имя</th>
                                <th style="width:120px;">Время</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students_for_this_day as $student): ?>
                                <tr>
                                    <td>
                                        <a class="link-strong" href="/profile/student.php?user_id=<?= (int)$student['user_id'] ?>">
                                            <?= h($student['fio']) ?>
                                        </a>
                                    </td>
                                    <td class="time-cell"><?= h(substr($student['time'], 0, 5)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

</body>
</html>
