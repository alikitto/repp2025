<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';

// Устанавливаем часовой пояс для корректной даты
date_default_timezone_set('Asia/Baku');
$wd = (int)date('N');

// 1. Кто сегодня приходит (данные уже отсортированы по времени)
$st = $con->prepare("
    SELECT s.user_id, CONCAT(s.lastname,' ',s.name) AS fio, sc.time
    FROM schedule sc
    JOIN stud s ON s.user_id = sc.user_id
    WHERE sc.weekday=?
    ORDER BY sc.time
");
$st->bind_param('i', $wd);
$st->execute();
$today = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// 2. Должники (запрос без изменений)
$deb = $con->query("
    SELECT s.user_id, CONCAT(s.lastname,' ',s.name) AS fio,
           (COALESCE((SELECT SUM(lessons) FROM pays WHERE user_id = s.user_id), 0) - COALESCE((SELECT COUNT(*) FROM dates WHERE user_id = s.user_id AND visited = 1), 0)) AS balance_lessons
    FROM stud s
    HAVING balance_lessons <= -8
    ORDER BY balance_lessons ASC
")->fetch_all(MYSQLI_ASSOC);

// 3. Получаем общее количество учеников
$student_count_result = $con->query("SELECT COUNT(*) AS total FROM stud");
$student_count = $student_count_result->fetch_assoc()['total'];

// 4. Массив с классами для цветных кружков
$dot_colors = ['dot-blue', 'dot-purple', 'dot-yellow'];
$dot_colors_count = count($dot_colors);

$active = 'home';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Главная — Tutor CRM</title>
    <link href="/profile/css/style.css" rel="stylesheet">
    <style>
        /* Стили для группировки, скопированные со страницы расписания */
        .schedule-table tbody tr.group-last-row td {
            border-bottom: 2px solid #adb5bd;
        }
        .time-cell { font-weight: 500; }
        .student-cell { display: flex; align-items: center; gap: 12px; }
        .color-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
        .dot-blue { background-color: #0d6efd; }
        .dot-purple { background-color: #6f42c1; }
        .dot-yellow { background-color: #ffc107; }
        .dot-gray { background-color: #6c757d; }
        
        /* --- ИЗМЕНЕНИЯ ЗДЕСЬ --- */
        .dashboard-header {
            position: relative; /* Нужно для позиционирования псевдо-элемента */
            color: white; /* Делаем основной цвет текста белым */
            padding: 24px;
            margin-bottom: 16px;
            border-radius: 12px;
            overflow: hidden; /* Скрываем части изображения, выходящие за рамки */

            /* Стили для фонового изображения */
            background-image: url('https://video.karal.az/ff.jpg');
            background-size: cover; /* Масштабируем изображение, чтобы оно заполнило блок */
            background-position: center; /* Центрируем изображение */
        }
        
        .dashboard-header::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: rgba(0, 0, 0, 0.5); /* Полупрозрачная черная подложка */
            z-index: 1;
        }
        
        .dashboard-header > * {
            position: relative; /* Размещаем текст поверх подложки */
            z-index: 2;
        }

        .dashboard-header p { 
            margin: 0 0 4px 0; 
            text-shadow: 1px 1px 3px rgba(0,0,0,0.7); /* Тень для лучшей читаемости */
        }
        .dashboard-header a {
            color: #fff; /* Делаем ссылку белой */
            text-decoration: underline;
        }
    </style>
</head>
<body>

<?php require __DIR__ . '/../common/nav.php'; ?>

<div class="content">
    <div class="dashboard-header">
        <p><strong>Сегодня, <?= date('d.m.Y') ?></strong></p>
        <p>Всего учеников: <a href="/profile/list.php"><?= $student_count ?></a></p>
        <p style="margin-top: 8px;">Хорошей работы! ✨</p>
    </div>

    <div class="card">
        <h2>Кто приходит сегодня</h2>
        <?php if (!$today): ?>
            <p>Сегодня занятий нет.</p>
        <?php else: ?>
            <table class="table schedule-table">
                <thead>
                    <tr>
                        <th>Имя</th>
                        <th style="width: 120px;">Время</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $last_time = null;
                    $group_idx = 0;
                    $today_count = count($today);
                    for ($i = 0; $i < $today_count; $i++):
                        $student = $today[$i];
                        $next_student = $today[$i + 1] ?? null;
                        $current_time = substr($student['time'], 0, 5);

                        // Проверяем, сколько учеников придет в это же время
                        $group_size = 0;
                        foreach($today as $s) {
                            if (substr($s['time'], 0, 5) == $current_time) $group_size++;
                        }
                        
                        $color_class = ($group_size > 1) ? $dot_colors[$group_idx % $dot_colors_count] : 'dot-gray';
                        
                        if ($current_time !== $last_time && $group_size > 1) {
                             $group_idx++;
                        }
                        
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
                                    <?= htmlspecialchars($student['fio']) ?>
                                </a>
                            </td>
                            <td class="time-cell"><?= htmlspecialchars($current_time) ?></td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Должники</h2>
        <?php if (!$deb): ?>
            <p>Должников нет 🎉</p>
        <?php else: ?>
            <table class="table debtors">
                <thead>
                    <tr><th>Ученик</th><th style="width:160px;">Долг (уроков)</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($deb as $r): ?>
                        <tr>
                            <td>
                                <a class="link-strong" href="/profile/student.php?user_id=<?= (int)$r['user_id'] ?>">
                                    <?= htmlspecialchars($r['fio']) ?>
                                </a>
                            </td>
                            <td class="debt"><?= abs((int)$r['balance_lessons']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
