<?php
require_once __DIR__ . '/intervals.php';

function group_free_seats(int $taken, ?int $max = null): int {
    $max = $max ?? (int)($GLOBALS['_group_size'] ?? 6);
    return max(0, $max - $taken);
}

function session_taken(array $s): int {
    return count($s['students']) + count($s['late']);
}

function normalize_posted_slots(array $slots): array {
    $allowed = array_flip(schedule_time_options());
    $out = [];
    foreach ($slots as $slot) {
        $wd = (int)($slot[0] ?? 0);
        $tm = hm((string)($slot[1] ?? ''));
        $te = hm((string)($slot[2] ?? ''));
        if ($wd < 1 || $wd > 7 || $tm === '' || !isset($allowed[$tm])) continue;
        if ($te !== '' && !isset($allowed[$te])) $te = '';
        $out[] = [$wd, $tm, $te];
    }
    return $out;
}

function schedule_time_options(): array {
    $times = [];
    for ($h = 9; $h <= 22; $h++) {
        foreach ([0, 30] as $m) {
            if ($h === 22 && $m > 0) continue;
            $times[] = sprintf('%02d:%02d', $h, $m);
        }
    }
    return $times;
}

function normalize_slots(array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        $start = hm($r['time']);
        $out[] = [
            'weekday' => (int)($r['weekday'] ?? 0),
            'start' => $start,
            'end' => slot_end($r['time_end'] ?? null, $start),
            'user_id' => (int)$r['user_id'],
            'fio' => $r['fio'] ?? '',
            'name' => $r['name'] ?? '',
            'block_id' => (int)($r['block_id'] ?? 0),
            'partial' => (int)($r['partial'] ?? 0),
        ];
    }
    return $out;
}

function ensure_sched_blocks(mysqli $con): void {
    static $done = false;
    if ($done) return;
    $con->query("CREATE TABLE IF NOT EXISTS sched_blocks (
        id INT NOT NULL AUTO_INCREMENT,
        teacher_id INT NOT NULL,
        weekday TINYINT NOT NULL,
        start TIME NOT NULL,
        end TIME NOT NULL,
        prelude TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY idx_blocks_teacher_wd (teacher_id, weekday, start)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (function_exists('ensure_column')) {
        ensure_column($con, 'schedule', 'block_id', '`block_id` INT DEFAULT NULL');
        ensure_column($con, 'schedule', 'partial', '`partial` TINYINT(1) NOT NULL DEFAULT 0');
    }
    $idx = $con->query("SHOW INDEX FROM schedule WHERE Key_name='idx_schedule_block'");
    if ($idx && $idx->num_rows === 0) {
        $con->query("ALTER TABLE schedule ADD KEY idx_schedule_block (block_id)");
    }
    migrate_schedule_blocks($con);
    $done = true;
}

function find_or_create_block(mysqli $con, int $tid, int $wd, string $start, string $end): int {
    $start = hm($start);
    $end = hm($end) !== '' ? hm($end) : slot_end('', $start);
    $st = $con->prepare("SELECT id FROM sched_blocks WHERE teacher_id=? AND weekday=? AND DATE_FORMAT(start,'%H:%i')=? AND prelude=0 LIMIT 1");
    $st->bind_param('iis', $tid, $wd, $start);
    $st->execute();
    $id = (int)($st->get_result()->fetch_assoc()['id'] ?? 0);
    $st->close();
    if ($id > 0) return $id;
    $ins = $con->prepare("INSERT INTO sched_blocks (teacher_id, weekday, start, end, prelude) VALUES (?,?,?,?,0)");
    $ins->bind_param('iiss', $tid, $wd, $start, $end);
    $ins->execute();
    $id = (int)$ins->insert_id;
    $ins->close();
    return $id;
}

function own_block(mysqli $con, int $block_id, int $tid): ?array {
    $st = $con->prepare("SELECT id, weekday, start, end FROM sched_blocks WHERE id=? AND teacher_id=? AND prelude=0 LIMIT 1");
    $st->bind_param('ii', $block_id, $tid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

function block_member_count(mysqli $con, int $block_id, int $except_uid = 0): int {
    $st = $con->prepare("SELECT COUNT(*) AS n FROM schedule WHERE block_id=? AND user_id<>?");
    $st->bind_param('ii', $block_id, $except_uid);
    $st->execute();
    $n = (int)($st->get_result()->fetch_assoc()['n'] ?? 0);
    $st->close();
    return $n;
}

function migrate_schedule_blocks(mysqli $con): void {
    $col = $con->query("SHOW COLUMNS FROM stud LIKE 'teacher_id'");
    if (!$col || $col->num_rows === 0) return;
    $need = $con->query("SELECT COUNT(*) AS n FROM schedule WHERE block_id IS NULL");
    if (!$need || (int)($need->fetch_assoc()['n'] ?? 0) === 0) return;
    $teachers = $con->query("SELECT DISTINCT teacher_id FROM stud WHERE archived=0 AND teacher_id IS NOT NULL AND teacher_id>0");
    if (!$teachers) return;
    while ($t = $teachers->fetch_assoc()) {
        $tid = (int)$t['teacher_id'];
        for ($wd = 1; $wd <= 7; $wd++) {
            $rows = weekday_slots($con, $wd, 0, $tid);
            $sessions = infer_day_group_slots($rows);
            $blockByStart = [];
            foreach ($sessions as $s) {
                if (!empty($s['prelude'])) continue;
                $blockByStart[$s['start']] = find_or_create_block($con, $tid, $wd, $s['start'], $s['end']);
            }
            $assigned = [];
            foreach ($sessions as $s) {
                if (!empty($s['prelude'])) continue;
                $bid = $blockByStart[$s['start']] ?? 0;
                if ($bid < 1) continue;
                foreach ($s['students'] as $p) {
                    $uid = (int)$p['user_id'];
                    if (isset($assigned[$uid])) continue;
                    $tm = $p['start'];
                    $up = $con->prepare("UPDATE schedule SET block_id=?, partial=0 WHERE user_id=? AND weekday=? AND DATE_FORMAT(time,'%H:%i')=?");
                    $up->bind_param('iiis', $bid, $uid, $wd, $tm);
                    $up->execute();
                    $up->close();
                    $assigned[$uid] = 1;
                }
            }
            $latest = [];
            foreach ($sessions as $s) {
                if (!empty($s['prelude'])) continue;
                $bid = $blockByStart[$s['start']] ?? 0;
                if ($bid < 1) continue;
                foreach ($s['late'] as $p) {
                    $uid = (int)$p['user_id'];
                    if (isset($assigned[$uid])) continue;
                    $latest[$uid] = ['bid' => $bid, 'tm' => $p['start']];
                }
            }
            foreach ($latest as $uid => $info) {
                $bid = $info['bid'];
                $tm = $info['tm'];
                $up = $con->prepare("UPDATE schedule SET block_id=?, partial=1 WHERE user_id=? AND weekday=? AND DATE_FORMAT(time,'%H:%i')=?");
                $up->bind_param('iiis', $bid, $uid, $wd, $tm);
                $up->execute();
                $up->close();
            }
        }
    }
    $left = $con->query("
        SELECT sc.id, sc.user_id, sc.weekday, sc.time, sc.time_end, s.teacher_id
        FROM schedule sc JOIN stud s ON s.user_id=sc.user_id
        WHERE sc.block_id IS NULL
    ");
    if ($left) {
        while ($r = $left->fetch_assoc()) {
            $start = hm($r['time']);
            $end = slot_end($r['time_end'] ?? '', $start);
            $bid = find_or_create_block($con, (int)$r['teacher_id'], (int)$r['weekday'], $start, $end);
            $id = (int)$r['id'];
            $up = $con->prepare("UPDATE schedule SET block_id=?, partial=0 WHERE id=?");
            $up->bind_param('ii', $bid, $id);
            $up->execute();
            $up->close();
        }
    }
}

function person_show_range(array $p): string {
    return ($p['show_start'] ?? $p['start']).'–'.($p['show_end'] ?? $p['end']);
}

function sort_slot_people(array $people): array {
    uasort($people, static function ($a, $b) {
        $c = time_to_min($a['show_start'] ?? $a['start']) <=> time_to_min($b['show_start'] ?? $b['start']);
        if ($c !== 0) return $c;
        return strcasecmp((string)($a['fio'] ?? ''), (string)($b['fio'] ?? ''));
    });
    return $people;
}

function slot_view_parts(array $slot): array {
    $lead = $core = $split = $tail = [];
    foreach ($slot['students'] ?? [] as $id => $p) {
        if (!empty($p['split'])) $split[$id] = $p;
        else $core[$id] = $p;
    }
    foreach ($slot['late'] ?? [] as $id => $p) {
        if (!empty($p['overflow'])) $lead[$id] = $p;
        else $tail[$id] = $p;
    }
    return [sort_slot_people($lead), sort_slot_people($core + $split), sort_slot_people($tail)];
}

function session_typical_end(array $rows, string $start): string {
    $counts = [];
    foreach ($rows as $r) {
        $m = time_to_min($r['end']);
        $counts[$m] = ($counts[$m] ?? 0) + 1;
    }
    $bestN = 0;
    $best = time_to_min($start);
    foreach ($counts as $m => $n) {
        if ($n > $bestN || ($n === $bestN && $m < $best)) {
            $bestN = $n;
            $best = $m;
        }
    }
    return min_to_time($best);
}

function infer_day_group_slots(array $dayRows): array {
    $by_start = [];
    foreach ($dayRows as $r) {
        $by_start[$r['start']][] = $r;
    }
    ksort($by_start);

    $sessions = [];
    foreach ($by_start as $start => $rows) {
        $end = session_typical_end($rows, $start);
        $inside = false;
        foreach ($sessions as $s) {
            if (time_to_min($s['start']) < time_to_min($start) && time_to_min($start) < time_to_min($s['end'])) {
                $inside = true;
                break;
            }
        }
        if ($inside && count($rows) < 2) continue;
        $sessions[$start] = [
            'start' => $start,
            'end' => $end,
            'students' => [],
            'late' => [],
            'prelude' => false,
        ];
    }

    foreach ($sessions as $start => &$s) {
        $starters = $by_start[$start] ?? [];
        if (count($starters) !== 1) continue;
        $r = $starters[0];
        $nextK = null;
        foreach ($sessions as $k => $o) {
            if (time_to_min($k) <= time_to_min($start)) continue;
            $nextK = $k;
            break;
        }
        if ($nextK === null || time_to_min($r['end']) <= time_to_min($nextK)) continue;
        $s['end'] = $nextK;
        $s['prelude'] = true;
    }
    unset($s);

    $keys = array_keys($sessions);
    foreach ($dayRows as $r) {
        $home = isset($sessions[$r['start']]) ? $r['start'] : null;
        if ($home === null) {
            foreach ($keys as $k) {
                $s = $sessions[$k];
                if (time_to_min($s['start']) < time_to_min($r['start']) && time_to_min($r['start']) < time_to_min($s['end'])) {
                    $home = $k;
                    break;
                }
            }
        }
        if ($home === null) continue;

        $targets = [$home];
        if (time_to_min($r['end']) > time_to_min($sessions[$home]['end'])) {
            foreach ($keys as $k) {
                if ($k === $home) continue;
                if (overlaps($r['start'], $r['end'], $sessions[$k]['start'], $sessions[$k]['end'])) $targets[] = $k;
            }
        }
        usort($targets, static fn($a, $b) => time_to_min($a) <=> time_to_min($b));

        $nHits = count($targets);
        for ($n = 0; $n < $nHits; $n++) {
            $k = $targets[$n];
            $from = max(time_to_min($r['start']), time_to_min($sessions[$k]['start']));
            $to = time_to_min($r['end']);
            if ($n + 1 < $nHits) $to = min($to, time_to_min($targets[$n + 1]));
            if ($from >= $to) continue;

            $piece = $r;
            $piece['show_start'] = min_to_time($from);
            $piece['show_end'] = min_to_time($to);
            $piece['split'] = $nHits > 1;
            $piece['overflow'] = $n > 0;
            if ($r['start'] === $k && empty($sessions[$k]['prelude'])) {
                $sessions[$k]['students'][$r['user_id']] = $piece;
            } else {
                $sessions[$k]['late'][$r['user_id']] = $piece;
            }
        }
    }
    return $sessions;
}

function teacher_week_slots(mysqli $con, int $tid): array {
    $out = [];
    for ($wd = 1; $wd <= 7; $wd++) {
        $out[$wd] = teacher_day_slots($con, $tid, $wd);
    }
    return $out;
}

function teacher_day_slots(mysqli $con, int $tid, int $wd): array {
    $bst = $con->prepare("SELECT id, start, end, prelude FROM sched_blocks WHERE teacher_id=? AND weekday=? AND prelude=0 ORDER BY start");
    $bst->bind_param('ii', $tid, $wd);
    $bst->execute();
    $blocks = $bst->get_result()->fetch_all(MYSQLI_ASSOC);
    $bst->close();
    $st = $con->prepare("
        SELECT sc.user_id, sc.weekday, sc.time, sc.time_end, sc.block_id, sc.partial,
               s.name, TRIM(CONCAT(s.lastname,' ',s.name)) AS fio
        FROM schedule sc
        JOIN stud s ON s.user_id = sc.user_id
        WHERE s.teacher_id=? AND sc.weekday=? AND s.archived=0
    ");
    $st->bind_param('ii', $tid, $wd);
    $st->execute();
    $people = normalize_slots($st->get_result()->fetch_all(MYSQLI_ASSOC));
    $st->close();
    return assemble_day_slots($blocks, $people);
}

function assemble_day_slots(array $blocks, array $people): array {
    $byBlock = [];
    foreach ($people as $p) {
        $bid = (int)($p['block_id'] ?? 0);
        if ($bid > 0) $byBlock[$bid][] = $p;
    }
    $sessions = [];
    foreach ($blocks as $b) {
        $bid = (int)$b['id'];
        $start = hm((string)$b['start']);
        $end = hm((string)$b['end']);
        $students = [];
        $late = [];
        $preludes = [];
        foreach ($byBlock[$bid] ?? [] as $r) {
            $person = $r;
            if ((int)$r['partial'] === 1) {
                $from = max(time_to_min($r['start']), time_to_min($start));
                $to = min(time_to_min($r['end']), time_to_min($end));
                if ($from >= $to) {
                    $from = time_to_min($start);
                    $to = time_to_min($end);
                }
                $person['show_start'] = min_to_time($from);
                $person['show_end'] = min_to_time($to);
                $person['split'] = time_to_min($r['start']) < time_to_min($start);
                $person['overflow'] = true;
                $late[$r['user_id']] = $person;
                if (time_to_min($r['start']) < time_to_min($start)) {
                    $pre = $person;
                    $pre['show_start'] = $r['start'];
                    $pre['show_end'] = $start;
                    $pre['overflow'] = false;
                    $pk = $r['start'].'-'.$start;
                    if (!isset($preludes[$pk])) {
                        $preludes[$pk] = [
                            'id' => $bid,
                            'start' => $r['start'],
                            'end' => $start,
                            'students' => [],
                            'late' => [],
                            'prelude' => true,
                        ];
                    }
                    $preludes[$pk]['late'][$r['user_id']] = $pre;
                }
            } else {
                $person['show_start'] = $r['start'];
                $person['show_end'] = $r['end'];
                $students[$r['user_id']] = $person;
            }
        }
        foreach ($preludes as $preSess) $sessions[] = $preSess;
        $sessions[] = [
            'id' => $bid,
            'start' => $start,
            'end' => $end,
            'students' => $students,
            'late' => $late,
            'prelude' => false,
        ];
    }
    foreach ($sessions as &$s) {
        if (!empty($s['prelude'])) continue;
        foreach ($people as $r) {
            $uid = (int)$r['user_id'];
            if ((int)($r['block_id'] ?? 0) === (int)$s['id']) continue;
            if (isset($s['students'][$uid]) || isset($s['late'][$uid])) continue;
            if (!overlaps($r['start'], $r['end'], $s['start'], $s['end'])) continue;
            $from = max(time_to_min($r['start']), time_to_min($s['start']));
            $to = min(time_to_min($r['end']), time_to_min($s['end']));
            if ($from >= $to) continue;
            $piece = $r;
            $piece['show_start'] = min_to_time($from);
            $piece['show_end'] = min_to_time($to);
            $piece['split'] = true;
            $piece['overflow'] = true;
            $s['late'][$uid] = $piece;
        }
    }
    unset($s);
    $homeEnd = [];
    foreach ($blocks as $b) {
        $homeEnd[(int)$b['id']] = hm((string)$b['end']);
    }
    $tails = [];
    foreach ($people as $r) {
        if ((int)($r['partial'] ?? 0) !== 1) continue;
        $bid = (int)($r['block_id'] ?? 0);
        $after = $homeEnd[$bid] ?? '';
        if ($after === '' || time_to_min($r['end']) <= time_to_min($after)) continue;
        $covered = false;
        foreach ($sessions as $s) {
            if (!empty($s['prelude'])) continue;
            if (overlaps($after, $r['end'], $s['start'], $s['end'])) {
                $covered = true;
                break;
            }
        }
        if ($covered) continue;
        $pk = $after.'-'.$r['end'];
        if (!isset($tails[$pk])) {
            $tails[$pk] = [
                'id' => $bid,
                'start' => $after,
                'end' => $r['end'],
                'students' => [],
                'late' => [],
                'prelude' => true,
            ];
        }
        $tail = $r;
        $tail['show_start'] = $after;
        $tail['show_end'] = $r['end'];
        $tail['split'] = true;
        $tail['overflow'] = false;
        $tails[$pk]['late'][$r['user_id']] = $tail;
    }
    foreach ($tails as $t) $sessions[] = $t;
    usort($sessions, static function ($a, $b) {
        $c = time_to_min($a['start']) <=> time_to_min($b['start']);
        if ($c !== 0) return $c;
        return ((int)!empty($b['prelude'])) <=> ((int)!empty($a['prelude']));
    });
    return $sessions;
}

function weekday_slots(mysqli $con, int $wd, int $except_uid = 0, ?int $teacher_id = null): array {
    $st = $con->prepare("
        SELECT sc.user_id, sc.weekday, sc.time, sc.time_end, sc.block_id, sc.partial
        FROM schedule sc
        JOIN stud s ON s.user_id = sc.user_id
        WHERE sc.weekday=? AND sc.user_id<>? AND s.teacher_id=? AND s.archived=0
    ");
    $tid = $teacher_id ?? (function_exists('teacher_id') ? teacher_id() : 0);
    $st->bind_param('iii', $wd, $except_uid, $tid);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return normalize_slots($rows);
}

function group_slots_overflow(mysqli $con, array $slots, int $except_uid = 0): array {
    $max = group_size($con);
    $days = [1=>'понедельник',2=>'вторник',3=>'среду',4=>'четверг',5=>'пятницу',6=>'субботу',7=>'воскресенье'];
    $norm = [];
    foreach ($slots as $slot) {
        $wd = (int)($slot[0] ?? 0);
        $tm = hm((string)($slot[1] ?? ''));
        if ($wd < 1 || $wd > 7 || $tm === '') continue;
        $norm[] = [$wd, $tm, slot_end((string)($slot[2] ?? ''), $tm)];
    }
    for ($i = 0; $i < count($norm); $i++) {
        for ($j = $i + 1; $j < count($norm); $j++) {
            if ($norm[$i][0] !== $norm[$j][0]) continue;
            if (overlaps($norm[$i][1], $norm[$i][2], $norm[$j][1], $norm[$j][2])) {
                $wd = $norm[$i][0];
                return ['error' => 'В '.$days[$wd].' слоты '.$norm[$i][1].'–'.$norm[$i][2].' и '.$norm[$j][1].'–'.$norm[$j][2].' пересекаются.', 'warn' => null];
            }
        }
    }
    $need = [];
    foreach ($slots as $slot) {
        $wd = (int)($slot[0] ?? 0);
        if ($wd >= 1 && $wd <= 7) $need[$wd] = true;
    }
    $tid = function_exists('teacher_id') ? teacher_id() : 0;
    $warns = [];
    foreach ($slots as $slot) {
        $wd = (int)($slot[0] ?? 0);
        $tm = hm((string)($slot[1] ?? ''));
        if ($wd < 1 || $wd > 7 || $tm === '') continue;
        $st = $con->prepare("SELECT id FROM sched_blocks WHERE teacher_id=? AND weekday=? AND DATE_FORMAT(start,'%H:%i')=? AND prelude=0 LIMIT 1");
        $st->bind_param('iis', $tid, $wd, $tm);
        $st->execute();
        $bid = (int)($st->get_result()->fetch_assoc()['id'] ?? 0);
        $st->close();
        if ($bid > 0 && block_member_count($con, $bid, $except_uid) >= $max) {
            $warns[] = 'В '.$days[$wd].' в '.$tm.' уже '.block_member_count($con, $bid, $except_uid).' уч. (лимит '.$max.')';
        }
    }
    return ['error' => null, 'warn' => $warns ? implode('. ', $warns).'. Ученик всё равно сохранён.' : null];
}

function slot_status(int $wd, int $today_wd, int $now_min, string $start, string $end, array $times): string {
    if ($wd !== $today_wd) return '';
    $sm = time_to_min($start);
    if ($now_min >= $sm && $now_min < time_to_min($end)) return 'сейчас';
    $upcoming = array_values(array_filter($times, fn($t) => time_to_min($t) > $now_min));
    if ($upcoming && $upcoming[0] === $start) {
        $diff = $sm - $now_min;
        if ($diff < 60) return 'через '.$diff.' мин';
    }
    return '';
}

function day_counts(array $day): array {
    $ids = [];
    foreach ($day as $slot) {
        foreach ($slot['students'] as $s) $ids[$s['user_id']] = 1;
        foreach ($slot['late'] as $s) $ids[$s['user_id']] = 1;
    }
    return [count($ids), count($day)];
}

function ru_students(int $n): string {
    $mod10 = $n % 10;
    $mod100 = $n % 100;
    if ($mod10 === 1 && $mod100 !== 11) return $n.' ученик';
    if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) return $n.' ученика';
    return $n.' учеников';
}

function ru_sessions(int $n): string {
    $mod10 = $n % 10;
    $mod100 = $n % 100;
    if ($mod10 === 1 && $mod100 !== 11) return $n.' занятие';
    if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) return $n.' занятия';
    return $n.' занятий';
}
