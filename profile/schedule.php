<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';

// 1. Получаем ВСЕ записи из расписания, сразу объединяя с именами учеников
// Сортируем по дню недели, а затем по времени — это ключ к правильной группировке
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
    $schedule_by_day[$item['weekday']][] = $item;
}

// 3. Карта для преобразования номера дня в название
$weekdays_map = [
    1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда', 4 => 'Четверг',
    5 => 'Пятница', 6 => 'Суббота', 7 => 'Воскресенье'
];

// 4. Массив с классами для цветных кружков
$dot_colors = ['dot-blue', 'dot-purple', 'dot-yellow'];
$dot_colors_count = count($dot_colors);

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
        /* --- Новые стили для кружков и группировки --- */
        .schedule-table tbody tr td {
            background-color: #fff; /* Убираем чередующийся фон, делаем все строки белыми */
        }
        /* Стиль для жирной нижней рамки у последней строки в группе */
        .schedule-table tbody tr.group-last-row td {
            border-bottom: 2px solid #adb5bd;
        }
        .time-cell {
            font-weight: 500;
        }
        /* Контейнер для кружка и имени, чтобы они были на одной линии */
        .student-cell {
            display: flex;
            align-items: center;
            gap: 12px; /* Расстояние между кружком и именем */
        }
        /* Базовый стиль для цветного кружка */
        .color-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0; /* Предотвращает сжатие кружка */
        }
        /* Цвета для кружков */
        .dot-blue { background-color: #0d6efd; }
        .dot-purple { background-color: #6f42c1; }
        .dot-yellow { background-color: #ffc107; }
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
                $students_for_this_day = $schedule_by_day[$wd_num] ?? [];
                $student_count = count($students_for_this_day);
                ?>

                <?php if (empty($students_for_this_day)): ?>
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
                            $last_time = null;
                            $group_idx = 0; // Индекс для выбора цвета
                            for ($i = 0; $i < $student_count; $i++):
                                $student = $students_for_this_day[$i];
                                $next_student = $students_for_this_day[$i + 1] ?? null;

                                $current_time = substr($student['time'], 0, 5);
                                
                                // Если время сменилось, увеличиваем индекс для выбора нового цвета
                                if ($current_time !== $last_time) {
                                    $group_idx++;
                                }
                                
                                // Выбираем класс цвета по кругу из массива $dot_colors
                                $color_class = $dot_colors[($group_idx - 1) % $dot_colors_count];

                                // Проверяем, является ли эта запись последней в группе, чтобы добавить бордер
                                $row_class = '';
                                if ($next_student === null || $current_time !== substr($next_student['time'], 0, 5)) {
                                    $row_class = 'group-last-row';
                                }
                                
                                $last_time = $current_time;
                            ?>
                                <tr class="<?= $row_class ?>">
                                    <td class="student-cell">
                                        <span class="color-dot <?= $color_class ?>"></span>
                                        <a class="link-strong" href="/profile/student.php?user_id=<?= (int)$student['user_id'] ?>">
                                            <?= h($student['fio']) ?>
                                        </a>
                                    </td>
                                    <td class="time-cell">
                                        <?= h($current_time) ?>
                                    </td>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

</body>
</html>
