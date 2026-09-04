<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/util.php';
require_once __DIR__ . '/../common/report.php';

apply_app_timezone($con);
$token = tg_bot_token($con);
if ($token === '') {
    fwrite(STDERR, "Telegram is not configured\n");
    exit(0);
}

$now = new DateTimeImmutable('now');
$teachers = $con->query("SELECT id, tg_chat_id FROM users WHERE role='teacher' AND tg_chat_id IS NOT NULL AND tg_chat_id<>''");
$rows_t = $teachers ? $teachers->fetch_all(MYSQLI_ASSOC) : [];

$st_slot = $con->prepare("
    SELECT s.user_id, CONCAT(TRIM(s.lastname),' ',s.name) AS fio, sc.time, COALESCE(s.money,0) AS money,
           s.pay_mode,
           (SELECT amount FROM pays WHERE user_id=s.user_id ORDER BY date DESC, id DESC LIMIT 1) AS last_amount,
           (SELECT lessons FROM pays WHERE user_id=s.user_id ORDER BY date DESC, id DESC LIMIT 1) AS last_lessons,
           (COALESCE((SELECT SUM(lessons) FROM pays WHERE user_id=s.user_id),0)
            - COALESCE((SELECT COUNT(*) FROM dates WHERE user_id=s.user_id AND visited=1),0)) AS balance
    FROM schedule sc
    JOIN stud s ON s.user_id = sc.user_id
    WHERE s.teacher_id=? AND s.archived=0 AND sc.weekday = ? AND sc.time BETWEEN ? AND ?
    HAVING balance <= IF(pay_mode='prepaid', 0, ?)
");
$st_all = $con->prepare("
    SELECT s.user_id, CONCAT(TRIM(s.lastname),' ',s.name) AS fio, COALESCE(s.money,0) AS money,
           s.pay_mode,
           (SELECT amount FROM pays WHERE user_id=s.user_id ORDER BY date DESC, id DESC LIMIT 1) AS last_amount,
           (SELECT lessons FROM pays WHERE user_id=s.user_id ORDER BY date DESC, id DESC LIMIT 1) AS last_lessons,
           (COALESCE((SELECT SUM(lessons) FROM pays WHERE user_id=s.user_id),0)
            - COALESCE((SELECT COUNT(*) FROM dates WHERE user_id=s.user_id AND visited=1),0)) AS balance
    FROM stud s
    WHERE s.teacher_id=? AND s.archived=0
    HAVING balance <= IF(pay_mode='prepaid', 0, ?)
");
$ins = $con->prepare("INSERT IGNORE INTO tg_notifications (user_id, lesson_at, type) VALUES (?, ?, 'debt_reminder')");
$del = $con->prepare("DELETE FROM tg_notifications WHERE user_id=? AND lesson_at=? AND type='debt_reminder'");

function tg_send_debt(string $token, string $chat, string $fio, int $balance, float $money, string $mode = 'prepaid'): bool {
    $text = sprintf(
        "Имя: %s\nОплата: %s\nДолг: %d ур.\nСумма: %s AZN",
        $fio,
        student_pay_mode_label($mode),
        abs($balance),
        number_format(abs($balance) * max(0.0, $money), 2, '.', '')
    );
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query(['chat_id' => $chat, 'text' => $text]),
            'timeout' => 8,
        ],
    ]);
    $raw = @file_get_contents('https://api.telegram.org/bot' . $token . '/sendMessage', false, $ctx);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($j) && !empty($j['ok']);
}

/** @return list<array{wd:int,from:string,to:string,date:string}> */
function debt_slot_windows(DateTimeImmutable $now, int $offset, bool $after = false): array {
    if ($after) {
        $fromDt = $now->modify("-{$offset} minutes");
        $toDt = $fromDt->modify('+10 minutes');
    } else {
        $toDt = $now->modify("+{$offset} minutes");
        $fromDt = $toDt->modify('-10 minutes');
    }
    $from = $fromDt->format('H:i:s');
    $to = $toDt->format('H:i:s');
    $wdTo = (int)$toDt->format('N');
    $wdFrom = (int)$fromDt->format('N');
    if ($wdFrom === $wdTo && $from <= $to) {
        return [['wd' => $wdTo, 'from' => $from, 'to' => $to, 'date' => $toDt->format('Y-m-d')]];
    }
    return [
        ['wd' => $wdFrom, 'from' => $from, 'to' => '23:59:59', 'date' => $fromDt->format('Y-m-d')],
        ['wd' => $wdTo, 'from' => '00:00:00', 'to' => $to, 'date' => $toDt->format('Y-m-d')],
    ];
}

foreach ($rows_t as $t) {
    $tid = (int)$t['id'];
    $chat = trim((string)$t['tg_chat_id']);
    if ($chat === '' || !teacher_tg_on($con, $tid, 'tg_notify_debt')) continue;
    $debt_limit = -debt_threshold($con, $tid);
    $when = teacher_debt_when($con, $tid);

    if ($when === 'clock') {
        if ($now->format('H:i') < teacher_debt_time($con, $tid)) continue;
        $st_all->bind_param('ii', $tid, $debt_limit);
        $st_all->execute();
        $rows = $st_all->get_result()->fetch_all(MYSQLI_ASSOC);
        $lessonAt = $now->format('Y-m-d') . ' 23:59:58';
        foreach ($rows as $r) {
            $uid = (int)$r['user_id'];
            $ins->bind_param('is', $uid, $lessonAt);
            $ins->execute();
            if ($ins->affected_rows < 1) continue;
            if (!tg_send_debt($token, $chat, (string)$r['fio'], (int)$r['balance'], pack_unit_price((float)$r['money'], $r['last_amount'] ?? null, $r['last_lessons'] ?? null), (string)($r['pay_mode'] ?? 'prepaid'))) {
                $del->bind_param('is', $uid, $lessonAt);
                $del->execute();
            }
        }
        continue;
    }

    $after = $when === 'after_arrival';
    $offset = $after ? teacher_debt_offset_after($con, $tid) : teacher_debt_offset($con, $tid);
    foreach (debt_slot_windows($now, $offset, $after) as $win) {
        $wd = $win['wd'];
        $from = $win['from'];
        $to = $win['to'];
        $st_slot->bind_param('iissi', $tid, $wd, $from, $to, $debt_limit);
        $st_slot->execute();
        $rows = $st_slot->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $r) {
            $lessonAt = $win['date'] . ' ' . $r['time'];
            $uid = (int)$r['user_id'];
            $ins->bind_param('is', $uid, $lessonAt);
            $ins->execute();
            if ($ins->affected_rows < 1) continue;
            if (!tg_send_debt($token, $chat, (string)$r['fio'], (int)$r['balance'], pack_unit_price((float)$r['money'], $r['last_amount'] ?? null, $r['last_lessons'] ?? null), (string)($r['pay_mode'] ?? 'prepaid'))) {
                $del->bind_param('is', $uid, $lessonAt);
                $del->execute();
            }
        }
    }
}

$con->query("DELETE FROM tg_notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
maybe_send_monthly_report($con);
