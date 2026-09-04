<?php
function wd_to_num($s): int {
    $m = ['Понедельник'=>1,'Вторник'=>2,'Среда'=>3,'Четверг'=>4,'Пятница'=>5,'Суббота'=>6,'Воскресенье'=>7];
    return $m[$s] ?? (int)$s;
}

function hm(?string $t): string {
    return $t ? substr($t, 0, 5) : '';
}

function time_to_min(string $t): int {
    $p = explode(':', hm($t) . ':00');
    return ((int)$p[0] * 60) + (int)($p[1] ?? 0);
}

function min_to_time(int $min): string {
    $min = max(0, min(24 * 60, $min));
    return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
}

function slot_end(?string $end, string $start, ?int $defaultMin = null): string {
    $e = hm($end);
    if ($e !== '' && time_to_min($e) > time_to_min($start)) {
        return $e;
    }
    if ($defaultMin === null) {
        $defaultMin = (int)($GLOBALS['_lesson_duration'] ?? 90);
    }
    return min_to_time(time_to_min($start) + $defaultMin);
}

function overlaps(string $a0, string $a1, string $b0, string $b1): bool {
    return time_to_min($a0) < time_to_min($b1) && time_to_min($b0) < time_to_min($a1);
}

function overlap_range(string $a0, string $a1, string $b0, string $b1): ?array {
    $from = max(time_to_min($a0), time_to_min($b0));
    $to = min(time_to_min($a1), time_to_min($b1));
    if ($from >= $to) return null;
    return [min_to_time($from), min_to_time($to)];
}

function ensure_dates_time_schema(mysqli $con): void {
    static $done = false;
    if ($done) return;
    $r = $con->query("SHOW COLUMNS FROM dates LIKE 'time'");
    if ($r && $r->num_rows === 0) {
        $con->query("ALTER TABLE dates ADD COLUMN `time` TIME NOT NULL DEFAULT '00:00:00' AFTER `dates`");
        $con->query("UPDATE dates d
            INNER JOIN (
                SELECT user_id, weekday, MIN(time) AS t FROM schedule GROUP BY user_id, weekday
            ) s ON s.user_id=d.user_id AND s.weekday=WEEKDAY(d.dates)+1
            SET d.time=s.t");
    }
    $old = $con->query("SHOW INDEX FROM dates WHERE Key_name='uniq_user_date'");
    if ($old && $old->num_rows > 0) {
        $con->query("ALTER TABLE dates DROP INDEX uniq_user_date");
    }
    $neu = $con->query("SHOW INDEX FROM dates WHERE Key_name='uniq_user_date_time'");
    if ($neu && $neu->num_rows === 0) {
        $con->query("ALTER TABLE dates ADD UNIQUE KEY uniq_user_date_time (user_id, dates, time)");
    }
    $done = true;
}

function dates_visited_lookup(array $rows): array {
    $marks = [];
    $byUser = [];
    foreach ($rows as $m) {
        $uid = (int)$m['user_id'];
        $hm = (string)$m['time'];
        $marks[$uid . '|' . $hm] = (int)$m['visited'];
        $byUser[$uid][] = $m;
    }
    foreach ($byUser as $uid => $list) {
        if (count($list) === 1) {
            $marks[$uid . '|*'] = (int)$list[0]['visited'];
        }
    }
    return $marks;
}

function dates_mark_visited(array $marks, int $uid, string $hm, bool $fallback = false): ?int {
    $key = $uid . '|' . $hm;
    if (array_key_exists($key, $marks)) return (int)$marks[$key];
    $fb = $uid . '|*';
    if ($fallback && array_key_exists($fb, $marks)) return (int)$marks[$fb];
    return null;
}

// $slotCount — сколько слотов у ученика в этот день недели. Перенос отметки
// допустим только при единственном слоте, иначе она затрёт соседний слот.
function dates_realign_one(mysqli $con, int $uid, string $date, string $time, int $slotCount = 1): void {
    if ($slotCount !== 1) return;
    $st = $con->prepare("SELECT dates_id, time FROM dates WHERE user_id=? AND dates=?");
    $st->bind_param('is', $uid, $date);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    if (count($rows) !== 1) return;
    if (hm((string)$rows[0]['time']) === hm($time)) return;
    $id = (int)$rows[0]['dates_id'];
    $up = $con->prepare("UPDATE dates SET time=? WHERE dates_id=?");
    $up->bind_param('si', $time, $id);
    $up->execute();
    $up->close();
}

function ensure_schedule_time_end(mysqli $con): void {
    static $done = false;
    if ($done) return;
    $r = $con->query("SHOW COLUMNS FROM schedule LIKE 'time_end'");
    if ($r && $r->num_rows === 0) {
        $con->query("ALTER TABLE schedule ADD COLUMN time_end TIME NULL AFTER time");
    }
    $con->query("UPDATE schedule SET time_end = ADDTIME(time, '01:30:00') WHERE time_end IS NULL");
    $done = true;
}
