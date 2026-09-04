<?php
function ensure_activity_table(mysqli $con): void {
    static $done = false;
    if ($done) return;
    $con->query("CREATE TABLE IF NOT EXISTS activity_log (
        id INT NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        type VARCHAR(32) NOT NULL,
        text VARCHAR(255) NOT NULL,
        url VARCHAR(255) DEFAULT NULL,
        teacher_id INT NULL,
        PRIMARY KEY (id),
        KEY idx_activity_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $col = $con->query("SHOW COLUMNS FROM activity_log LIKE 'teacher_id'");
    if ($col && $col->num_rows === 0) {
        $con->query("ALTER TABLE activity_log ADD COLUMN teacher_id INT NULL");
    }
    $done = true;
}

function activity_tz(): void {
    static $set = false;
    if ($set || !empty($GLOBALS['_app_tz_applied'])) return;
    date_default_timezone_set(getenv('TZ') ?: 'Asia/Baku');
    $set = true;
}

function log_activity(mysqli $con, string $type, string $text, ?string $url = null): void {
    ensure_activity_table($con);
    $text = mb_substr(trim($text), 0, 255);
    if ($text === '') return;
    $url = ($url !== null && $url !== '') ? mb_substr($url, 0, 255) : null;
    $tid = function_exists('teacher_id') ? teacher_id() : 0;
    if ($tid > 0) {
        $st = $con->prepare("INSERT INTO activity_log (type, text, url, teacher_id) VALUES (?,?,?,?)");
        $st->bind_param('sssi', $type, $text, $url, $tid);
    } else {
        $st = $con->prepare("INSERT INTO activity_log (type, text, url, teacher_id) VALUES (?,?,?,NULL)");
        $st->bind_param('sss', $type, $text, $url);
    }
    $st->execute();
    $st->close();
}

function activity_fio(mysqli $con, int $uid): string {
    $st = $con->prepare("SELECT TRIM(CONCAT(COALESCE(lastname,''),' ',COALESCE(name,''))) AS fio FROM stud WHERE user_id=?");
    $st->bind_param('i', $uid);
    $st->execute();
    $fio = trim((string)($st->get_result()->fetch_assoc()['fio'] ?? ''));
    $st->close();
    return $fio !== '' ? $fio : 'Ученик #' . $uid;
}

function activity_teacher_clause(): array {
    if (function_exists('is_admin') && is_admin()) {
        return ['1=1', '', 0];
    }
    $tid = function_exists('teacher_id') ? teacher_id() : 0;
    if ($tid > 0) return ['teacher_id=?', 'i', $tid];
    return ['0=1', '', 0];
}

function activity_stmt(mysqli $con, string $sql, string $types, int $tid): mysqli_stmt {
    $st = $con->prepare($sql);
    if ($types !== '') $st->bind_param($types, $tid);
    $st->execute();
    return $st;
}

function activity_recent(mysqli $con, int $limit = 8): array {
    ensure_activity_table($con);
    $limit = max(1, min(50, $limit));
    [$where, $types, $tid] = activity_teacher_clause();
    $st = activity_stmt($con, "SELECT id, created_at, type, text, url FROM activity_log WHERE {$where} ORDER BY id DESC LIMIT " . $limit, $types, $tid);
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

/** @return array{0:list<array>,1:int,2:int,3:int} items, total, page, pages */
function activity_page(mysqli $con, int $page = 1, int $per = 40): array {
    ensure_activity_table($con);
    $per = max(1, min(100, $per));
    [$where, $types, $tid] = activity_teacher_clause();
    $st = activity_stmt($con, "SELECT COUNT(*) AS c FROM activity_log WHERE {$where}", $types, $tid);
    $total = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
    $st->close();
    $pages = max(1, (int)ceil($total / $per));
    $page = max(1, min($pages, $page));
    $offset = ($page - 1) * $per;
    $st = activity_stmt($con, "SELECT id, created_at, type, text, url FROM activity_log WHERE {$where} ORDER BY id DESC LIMIT {$per} OFFSET {$offset}", $types, $tid);
    $items = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return [$items, $total, $page, $pages];
}

function activity_unread(mysqli $con, int $seen): int {
    ensure_activity_table($con);
    [$where, $types, $tid] = activity_teacher_clause();
    $st = $con->prepare("SELECT COUNT(*) FROM activity_log WHERE id > ? AND {$where}");
    if ($types === '') $st->bind_param('i', $seen);
    else $st->bind_param('i' . $types, $seen, $tid);
    $st->execute();
    $st->bind_result($c);
    $st->fetch();
    $st->close();
    return (int)$c;
}

function activity_max_id(mysqli $con): int {
    ensure_activity_table($con);
    [$where, $types, $tid] = activity_teacher_clause();
    $st = activity_stmt($con, "SELECT COALESCE(MAX(id),0) AS m FROM activity_log WHERE {$where}", $types, $tid);
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return (int)($row['m'] ?? 0);
}

function activity_mark_seen(int $id): void {
    if ($id <= 0) return;
    $opts = [
        'expires' => time() + 365 * 86400,
        'path' => '/',
        'secure' => function_exists('is_https') && is_https(),
        'httponly' => false,
        'samesite' => 'Lax',
    ];
    setcookie('activity_seen', (string)$id, $opts);
    $_COOKIE['activity_seen'] = (string)$id;
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['activity_seen'] = $id;
    }
}

function activity_time(string $dt): string {
    activity_tz();
    $ts = strtotime($dt);
    if ($ts === false) return $dt;
    $diff = time() - $ts;
    if ($diff < 45) return 'только что';
    if ($diff < 3600) return (int)floor($diff / 60) . ' мин назад';
    if (date('Y-m-d', $ts) === date('Y-m-d')) return 'сегодня ' . date('H:i', $ts);
    if (date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day'))) return 'вчера ' . date('H:i', $ts);
    if (date('Y', $ts) === date('Y')) return date('d.m, H:i', $ts);
    return date('d.m.Y, H:i', $ts);
}
