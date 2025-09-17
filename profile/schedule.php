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
        /* Новые стили для визуальной группировки */
        .schedule-table tbody tr.group-odd td {
            background-color: #f8f9fa; /* Легкий фон для нечетных групп */
        }
        .schedule-table tbody tr.group-even td {
            background-color: #fff; /* Белый фон для четных групп */
        }
        /* Добавляем рамку сверху, когда начинается новая группа времени */
        .schedule-table tbody tr:not(:first-child) td.new-group-start {
            border-top: 2px solid #dee2e6;
        }
        .time-cell {
            font-weight: 500;
        }
    </style>
</head>
<body>

<?php require __DIR__ . '/../common/nav.php'; ?>

<div class.content">
    <div class="card">
        <h1>Расписание на неделю</h1>
        
        <?php foreach ($weekdays_map as $wd_num => $wd_name): ?>
            <div class="day-block">
                <h2><?= $wd_name ?></h2>

                <?php $students_for_this_day = $schedule_by_day[$wd_num] ?? []; ?>

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
                            $last_time = null;      // Переменная для хранения времени предыдущей записи
                            $group_color_idx = 0;   // Счетчик для чередования цветов фона
                            ?>
                            <?php foreach ($students_for_this_day as $student): ?>
                                <?php
                                $current_time = substr($student['time'], 0, 5);
                                $is_new_group = false; // Флаг, который определяет начало новой группы

                                // Если время текущей записи не совпадает с предыдущей,
                                // значит, это начало новой группы
                                if ($current_time !== $last_time) {
                                    $group_color_idx++; // Меняем индекс цвета
                                    $is_new_group = true;
                                    $last_time = $current_time;
                                }

                                // Определяем класс для цвета фона
                                $group_class = ($group_color_idx % 2 != 0) ? 'group-odd' : 'group-even';
                                ?>
                                <tr class="<?= $group_class ?>">
                                    <td class="<?= $is_new_group ? 'new-group-start' : '' ?>">
                                        <a class="link-strong" href="/profile/student.php?user_id=<?= (int)$student['user_id'] ?>">
                                            <?= h($student['fio']) ?>
                                        </a>
                                    </td>
                                    <td class="time-cell <?= $is_new_group ? 'new-group-start' : '' ?>">
                                        <?php
                                        // Показываем время только для первой записи в группе
                                        if ($is_new_group) {
                                            echo h($current_time);
                                        }
                                        ?>
                                    </td>
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
