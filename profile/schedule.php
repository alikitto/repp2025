<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';

// 1. Получаем ВСЕ записи из расписания, отсортированные по дню и времени
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

// 2. Создаем более сложную структуру: группируем учеников по дню, а затем по времени
// Это позволит нам легко посчитать, сколько учеников в каждом временном слоте
$schedule_grouped = [];
foreach ($all_schedule_flat as $item) {
    $time_key = substr($item['time'], 0, 5); // Используем время 'ЧЧ:ММ' как ключ
    $schedule_grouped[$item['weekday']][$time_key][] = $item;
}

// 3. Карта для преобразования номера дня в название
$weekdays_map = [
    1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда', 4 => 'Четверг',
    5 => 'Пятница', 6 => 'Суббота', 7 => 'Воскресенье'
];

// 4. Массив с классами для цветных кружков ГРУППОВЫХ занятий
$group_dot_colors = ['dot-blue', 'dot-purple', 'dot-yellow'];
$group_dot_colors_count = count($group_dot_colors);

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
        .day-block {
            margin-bottom: 24px;
        }
        .day-block h2 {
            margin-bottom: 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid #dee2e6;
        }
        .schedule-table tbody tr td {
            background-color: #fff;
        }
        /* Стиль для жирной нижней рамки у последней строки в группе */
        .schedule-table tbody tr.group-last-row td {
            border-bottom: 2px solid #adb5bd;
        }
        .time-cell {
            font-weight: 500;
        }
        .student-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .color-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        /* Цвета для кружков */
        .dot-blue { background-color: #0d6efd; }
        .dot-purple { background-color: #6f42c1; }
        .dot-yellow { background-color: #ffc107; }
        .dot-gray { background-color: #6c757d; } /* Новый серый цвет для одиночных */
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
                // Получаем расписание на текущий день из сгруппированного массива
                $day_schedule = $schedule_grouped[$wd_num] ?? [];
                ?>

                <?php if (empty($day_schedule)): ?>
                    <p class="muted">В этот день занятий нет.</p>
                <?php else: ?>
                    <table class="table schedule-table">
                        <thead>
                            <tr>
                                <th>Имя</th>
                                <th style="width:120px;">Время</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $group_color_idx = 0; // Счетчик только для групповых занятий
                            // Перебираем временные слоты (ключ - время, значение - массив учеников)
                            foreach ($day_schedule as $time => $students_in_group):
                                $group_size = count($students_in_group);
                                $color_class = '';

                                if ($group_size > 1) {
                                    // Если учеников больше одного - это группа. Используем циклический цвет.
                                    $color_class = $group_dot_colors[$group_color_idx % $group_dot_colors_count];
                                    $group_color_idx++; // Увеличиваем счетчик только для групп
                                } else {
                                    // Если ученик один - это индивидуальное занятие. Используем серый.
                                    $color_class = 'dot-gray';
                                }

                                // Перебираем учеников внутри этой временной группы
                                foreach ($students_in_group as $key => $student):
                                    // Проверяем, является ли ученик последним в группе, чтобы добавить бордер
                                    $row_class = ($key === $group_size - 1) ? 'group-last-row' : '';
                                ?>
                                    <tr class="<?= $row_class ?>">
                                        <td class="student-cell">
                                            <span class="color-dot <?= $color_class ?>"></span>
                                            <a class="link-strong" href="/profile/student.php?user_id=<?= (int)$student['user_id'] ?>">
                                                <?= h($student['fio']) ?>
                                            </a>
                                        </td>
                                        <td class="time-cell">
                                            <?= h($time) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
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
