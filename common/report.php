<?php

function tg_esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tg_send_message(mysqli $con, string $text, string $parse = '', string $chat = '', ?int $teacher_id = null): bool {
    $token = tg_bot_token($con);
    if ($chat === '') $chat = tg_chat_id($con, $teacher_id);
    if ($token === '' || $chat === '') return false;
    $payload = ['chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => 'true'];
    if ($parse !== '') $payload['parse_mode'] = $parse;
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($payload),
            'timeout' => 8,
        ],
    ]);
    $raw = @file_get_contents('https://api.telegram.org/bot' . $token . '/sendMessage', false, $ctx);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($j) && !empty($j['ok']);
}

/** Username бота, либо '' если токен пуст или Telegram его не принял. */
function tg_get_me(mysqli $con): string {
    $token = tg_bot_token($con);
    if ($token === '') return '';
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 8]]);
    $raw = @file_get_contents('https://api.telegram.org/bot' . $token . '/getMe', false, $ctx);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    return (is_array($j) && !empty($j['ok'])) ? (string)($j['result']['username'] ?? '') : '';
}

function tg_send_chunks(mysqli $con, string $text, string $chat = '', ?int $teacher_id = null): bool {
    $max = 4000;
    if (mb_strlen($text) <= $max) return tg_send_message($con, $text, 'HTML', $chat, $teacher_id);
    $buf = '';
    foreach (explode("\n", $text) as $line) {
        $next = $buf === '' ? $line : $buf . "\n" . $line;
        if (mb_strlen($next) > $max) {
            if ($buf !== '' && !tg_send_message($con, $buf, 'HTML', $chat, $teacher_id)) return false;
            $buf = $line;
        } else {
            $buf = $next;
        }
    }
    return $buf === '' || tg_send_message($con, $buf, 'HTML', $chat, $teacher_id);
}

/** @return array{0:string,1:string} from inclusive, to exclusive */
function monthly_report_period(?DateTimeImmutable $now = null): array {
    $now = $now ?? new DateTimeImmutable('now');
    return [$now->format('Y-m-01'), $now->modify('+1 day')->format('Y-m-d')];
}

/** @return array{profit:float,students:int,missed:int,top_missed:list<array{fio:string,missed:int}>,debtors:list<array{fio:string,balance:int,debt:float}>,debt_azn:float,from:string,to:string} */
function monthly_report_data(mysqli $con, string $from, string $to, ?int $teacher_id = null): array {
    $tid = setting_teacher_id($teacher_id);
    $st = $con->prepare("
        SELECT COALESCE(SUM(p.amount),0) AS total
        FROM pays p JOIN stud s ON s.user_id=p.user_id
        WHERE s.teacher_id=? AND p.date >= ? AND p.date < ?
    ");
    $st->bind_param('iss', $tid, $from, $to);
    $st->execute();
    $profit = (float)$st->get_result()->fetch_assoc()['total'];
    $st->close();

    $st = $con->prepare("SELECT COUNT(*) FROM stud WHERE teacher_id=? AND archived=0");
    $st->bind_param('i', $tid);
    $st->execute();
    $students = (int)$st->get_result()->fetch_row()[0];
    $st->close();

    $st = $con->prepare("
        SELECT COUNT(*) FROM dates d JOIN stud s ON s.user_id=d.user_id
        WHERE s.teacher_id=? AND d.visited=0 AND d.dates >= ? AND d.dates < ?
    ");
    $st->bind_param('iss', $tid, $from, $to);
    $st->execute();
    $missed = (int)$st->get_result()->fetch_row()[0];
    $st->close();

    $st = $con->prepare("
        SELECT TRIM(CONCAT(s.lastname,' ',s.name)) AS fio, COUNT(*) AS missed
        FROM dates d
        JOIN stud s ON s.user_id = d.user_id
        WHERE s.teacher_id=? AND d.visited=0 AND d.dates >= ? AND d.dates < ?
        GROUP BY d.user_id, s.lastname, s.name
        ORDER BY missed DESC, fio
        LIMIT 3
    ");
    $st->bind_param('iss', $tid, $from, $to);
    $st->execute();
    $top_missed = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $st = $con->prepare("
        SELECT TRIM(CONCAT(s.lastname,' ',s.name)) AS fio,
               COALESCE(s.money,0) AS price,
               s.pay_mode,
               COALESCE(p.paid_lessons,0) AS paid_lessons,
               COALESCE(v.visits,0) AS visits,
               lp.amount AS last_amount, lp.lessons AS last_lessons
        FROM stud s
        LEFT JOIN (
            SELECT user_id, SUM(lessons) AS paid_lessons FROM pays GROUP BY user_id
        ) p ON p.user_id = s.user_id
        LEFT JOIN (
            SELECT user_id, COUNT(*) AS visits FROM dates WHERE visited=1 GROUP BY user_id
        ) v ON v.user_id = s.user_id
        LEFT JOIN pays lp ON lp.id = (SELECT MAX(id) FROM pays px WHERE px.user_id=s.user_id)
        WHERE s.teacher_id=? AND s.archived=0
    ");
    $st->bind_param('i', $tid);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $debtors = [];
    $debt_azn = 0.0;
    $GLOBALS['_debt_threshold'] = debt_threshold($con, $tid);
    foreach ($rows as $r) {
        $bal = (int)$r['paid_lessons'] - (int)$r['visits'];
        if (!student_is_debtor($bal, student_pay_mode($r['pay_mode'] ?? 'prepaid'))) continue;
        $debt = debt_azn($bal, pack_unit_price((float)$r['price'], $r['last_amount'] ?? null, $r['last_lessons'] ?? null));
        $debtors[] = ['fio' => $r['fio'], 'balance' => $bal, 'debt' => $debt, 'pay_mode' => student_pay_mode($r['pay_mode'] ?? 'prepaid')];
        $debt_azn += $debt;
    }
    usort($debtors, fn($a, $b) => $a['balance'] <=> $b['balance']);

    return [
        'profit' => $profit,
        'students' => $students,
        'missed' => $missed,
        'top_missed' => $top_missed,
        'debtors' => $debtors,
        'debt_azn' => $debt_azn,
        'from' => $from,
        'to' => $to,
    ];
}

function monthly_report_text(array $d): string {
    $months = [1=>'январь',2=>'февраль',3=>'март',4=>'апрель',5=>'май',6=>'июнь',7=>'июль',8=>'август',9=>'сентябрь',10=>'октябрь',11=>'ноябрь',12=>'декабрь'];
    $gen = [1=>'января',2=>'февраля',3=>'марта',4=>'апреля',5=>'мая',6=>'июня',7=>'июля',8=>'августа',9=>'сентября',10=>'октября',11=>'ноября',12=>'декабря'];
    $from = new DateTimeImmutable($d['from']);
    $last = (new DateTimeImmutable($d['to']))->modify('-1 day');
    $mo = (int)$from->format('n');
    $title_m = $months[$mo] ?? $from->format('m');
    $title_m = mb_strtoupper(mb_substr($title_m, 0, 1)) . mb_substr($title_m, 1);
    $range = $from->format('j') . '–' . $last->format('j') . ' ' . ($gen[$mo] ?? '');
    $sep = '────────────';

    $lines = [
        '📊 <b>Ежемесячный отчёт</b>',
        $title_m . ' ' . $from->format('Y'),
        '<i>' . tg_esc($range) . '</i>',
        $sep,
        '💰 <b>Прибыль</b>  ·  <b>' . tg_esc(fmt_money($d['profit'])) . '</b> AZN',
        '👥 <b>Учеников</b>  ·  <b>' . (int)$d['students'] . '</b>',
        '🚫 <b>Пропусков</b>  ·  <b>' . (int)$d['missed'] . '</b>',
    ];

    $lines[] = $sep;
    if ($d['top_missed']) {
        $lines[] = '👤 <b>Больше всего пропускали</b>';
        foreach ($d['top_missed'] as $i => $r) {
            $lines[] = ($i + 1) . '. ' . tg_esc((string)$r['fio']) . '  —  ' . (int)$r['missed'];
        }
    } else {
        $lines[] = '👤 <b>Пропусков нет</b>';
    }

    $n = count($d['debtors']);
    $lines[] = $sep;
    if ($n === 0) {
        $lines[] = '💳 <b>Долгов нет</b>';
    } else {
        $lines[] = '💳 <b>Долги</b>  ·  ' . $n . ' чел.  ·  <b>' . tg_esc(fmt_money($d['debt_azn'])) . '</b> AZN';
        foreach ($d['debtors'] as $r) {
            $lines[] = '• ' . tg_esc((string)$r['fio']) . '  ·  ' . tg_esc(student_pay_mode_label($r['pay_mode'] ?? 'prepaid')) . '  —  ' . abs((int)$r['balance']) . ' ур.  ·  ' . tg_esc(fmt_money($r['debt'])) . ' AZN';
        }
    }

    return implode("\n", $lines);
}

/** @return array{ok:bool,message:string} */
function send_monthly_report(mysqli $con, string $from, string $to, ?int $teacher_id = null): array {
    $tid = setting_teacher_id($teacher_id);
    $chat = tg_chat_id($con, $tid);
    if ($chat === '') {
        return ['ok' => false, 'message' => 'Укажите ваш Chat ID.'];
    }
    if (tg_bot_token($con) === '') {
        return ['ok' => false, 'message' => 'Bot token ещё не настроен администратором.'];
    }
    $text = monthly_report_text(monthly_report_data($con, $from, $to, $tid));
    if (!tg_send_chunks($con, $text, $chat, $tid)) {
        return ['ok' => false, 'message' => 'Не удалось отправить. Проверьте token, chat id и что вы написали боту /start.'];
    }
    return ['ok' => true, 'message' => 'Отчёт отправлен в Telegram.'];
}

function maybe_send_monthly_report(mysqli $con): void {
    if (!app_mysql_lock($con, 'tutor_monthly_report')) return;
    try {
    apply_app_timezone($con);
    $now = new DateTimeImmutable('now');
    $dom = (int)$now->format('j');
    $last = (int)$now->format('t');
    $res = $con->query("SELECT id FROM users WHERE role='teacher'");
    $teachers = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    foreach ($teachers as $t) {
        $tid = (int)$t['id'];
        if (!teacher_tg_on($con, $tid, 'tg_notify_report')) continue;
        $day = teacher_report_day($con, $tid);
        $want = $day === 'last' ? $last : min((int)$day, $last);
        if ($dom !== $want) continue;
        if ($now->format('H:i') < teacher_report_time($con, $tid)) continue;
        if ($day === 'last') {
            [$from, $to] = monthly_report_period($now);
        } else {
            $from = $now->modify('first day of last month')->format('Y-m-01');
            $to = $now->format('Y-m-01');
        }
        $period_ym = substr($from, 0, 7);
        if (teacher_setting($con, $tid, 'monthly_report_sent_ym', '') === $period_ym) continue;
        $r = send_monthly_report($con, $from, $to, $tid);
        if ($r['ok']) save_teacher_setting($con, $tid, 'monthly_report_sent_ym', $period_ym);
    }
    } finally {
        app_mysql_unlock($con, 'tutor_monthly_report');
    }
}
