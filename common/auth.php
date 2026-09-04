<?php
declare(strict_types=1);

function current_role(): string {
    return (string)($_SESSION['role'] ?? '');
}

function is_admin(): bool {
    return current_role() === 'admin';
}

function is_teacher(): bool {
    return current_role() === 'teacher';
}

function teacher_id(): int {
    return is_teacher() ? (int)($_SESSION['id'] ?? 0) : 0;
}

function current_teacher_id(): int {
    return teacher_id();
}

function session_login_user(array $user): void {
    $_SESSION['login'] = (string)$user['login'];
    $_SESSION['id'] = (int)$user['id'];
    $_SESSION['name'] = (string)($user['name'] ?: $user['login']);
    $_SESSION['role'] = (($user['role'] ?? '') === 'admin') ? 'admin' : 'teacher';
    unset($_SESSION['csrf']);
    if (isset($GLOBALS['con']) && $GLOBALS['con'] instanceof mysqli) {
        $_SESSION['session_epoch'] = session_epoch($GLOBALS['con']);
        $rev = isset($user['session_rev']) ? (int)$user['session_rev'] : 0;
        if (!array_key_exists('session_rev', $user)) {
            $st = $GLOBALS['con']->prepare('SELECT session_rev FROM users WHERE id=? LIMIT 1');
            if ($st) {
                $id = (int)$user['id'];
                $st->bind_param('i', $id);
                $st->execute();
                $rev = (int)($st->get_result()->fetch_assoc()['session_rev'] ?? 0);
                $st->close();
            }
        }
        $_SESSION['session_rev'] = $rev;
    }
}

function require_admin(): void {
    if (!is_admin()) {
        header('Location: /profile/schedule.php');
        exit;
    }
}

function require_teacher(): void {
    if (!is_teacher()) {
        header('Location: /profile/settings.php');
        exit;
    }
}

function enforce_role_path(string $path): void {
    if (is_admin()) {
        $ok = [
            '/profile/settings.php',
            '/profile/profile.php',
            '/profile/users.php',
            '/profile/teacher.php',
            '/profile/google_oauth.php',
            '/profile/activity.php',
            '/logout.php',
        ];
        if (!in_array($path, $ok, true)) {
            header('Location: /profile/settings.php');
            exit;
        }
        return;
    }
    if (is_teacher() && ($path === '/profile/users.php' || $path === '/profile/google_oauth.php')) {
        header('Location: /profile/schedule.php');
        exit;
    }
}

function stud_owned(mysqli $con, int $sid): bool {
    $tid = teacher_id();
    if ($tid <= 0 || $sid <= 0) return false;
    $st = $con->prepare("SELECT 1 FROM stud WHERE user_id=? AND teacher_id=? LIMIT 1");
    $st->bind_param('ii', $sid, $tid);
    $st->execute();
    $ok = (bool)$st->get_result()->fetch_row();
    $st->close();
    return $ok;
}

function render_not_found(string $msg = 'Страница не найдена'): void {
    http_response_code(404);
    $h = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Не найдено</title><link rel="stylesheet" href="'.asset('/profile/css/style.css').'"></head><body><div class="content"><div class="card"><h2>Не найдено</h2><p>'.$h.'</p><p><a href="/profile/schedule.php">На главную</a></p></div></div></body></html>';
    exit;
}

function require_owned_student(mysqli $con, int $sid): void {
    if (!stud_owned($con, $sid)) {
        render_not_found('Ученик не найден');
    }
}

function is_reserved_login(mysqli $con, string $login, int $except_uid = 0): bool {
    $low = strtolower(trim($login));
    if ($low === 'webmaster' || $low === 'admin') {
        if ($except_uid > 0) {
            $st = $con->prepare("SELECT login FROM users WHERE id=? LIMIT 1");
            $st->bind_param('i', $except_uid);
            $st->execute();
            $cur = strtolower((string)($st->get_result()->fetch_assoc()['login'] ?? ''));
            $st->close();
            if ($cur === $low) return false;
        }
        return true;
    }
    $st = $con->prepare("SELECT id FROM users WHERE role='admin' AND LOWER(login)=? AND id<>? LIMIT 1");
    $st->bind_param('si', $low, $except_uid);
    $st->execute();
    $hit = (bool)$st->get_result()->fetch_assoc();
    $st->close();
    return $hit;
}

function ensure_column(mysqli $con, string $table, string $col, string $ddl): void {
    $ex = $con->query("SHOW TABLES LIKE '" . $con->real_escape_string($table) . "'");
    if (!$ex || $ex->num_rows === 0) return;
    $r = $con->query("SHOW COLUMNS FROM `{$table}` LIKE '" . $con->real_escape_string($col) . "'");
    if ($r && $r->num_rows === 0) {
        $con->query("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
    }
}

function drop_column_if_exists(mysqli $con, string $table, string $col): void {
    $r = $con->query("SHOW COLUMNS FROM `{$table}` LIKE '" . $con->real_escape_string($col) . "'");
    if ($r && $r->num_rows > 0) {
        $con->query("ALTER TABLE `{$table}` DROP COLUMN `{$col}`");
    }
}

function ensure_index(mysqli $con, string $table, string $name, string $cols): void {
    $ex = $con->query("SHOW TABLES LIKE '" . $con->real_escape_string($table) . "'");
    if (!$ex || $ex->num_rows === 0) return;
    $idx = $con->query("SHOW INDEX FROM `{$table}` WHERE Key_name='" . $con->real_escape_string($name) . "'");
    if ($idx && $idx->num_rows === 0) {
        $con->query("ALTER TABLE `{$table}` ADD KEY `{$name}` ({$cols})");
    }
}

function ensure_roles_schema(mysqli $con): void {
    static $done = false;
    if ($done) return;
    $done = true;

    ensure_column($con, 'users', 'role', "role ENUM('admin','teacher') NOT NULL DEFAULT 'teacher' AFTER name");
    ensure_column($con, 'users', 'tg_chat_id', "tg_chat_id VARCHAR(32) NULL AFTER role");
    drop_column_if_exists($con, 'users', 'password');

    $con->query("CREATE TABLE IF NOT EXISTS teacher_settings (
        teacher_id INT NOT NULL,
        k VARCHAR(64) NOT NULL,
        v TEXT NOT NULL,
        PRIMARY KEY (teacher_id, k)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $con->query("UPDATE users SET role='teacher' WHERE role IS NULL OR role=''");

    $tid = 0;
    $teachers_n = 0;
    $r = $con->query("SELECT id FROM users WHERE role='teacher' ORDER BY id");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $teachers_n++;
            if ($tid === 0) $tid = (int)($row['id'] ?? 0);
        }
    }

    ensure_column($con, 'stud', 'teacher_id', "teacher_id INT NULL");
    ensure_column($con, 'stud', 'archived', "archived TINYINT(1) NOT NULL DEFAULT 0");
    ensure_column($con, 'stud', 'pay_mode', "pay_mode ENUM('prepaid','postpaid') NOT NULL DEFAULT 'prepaid'");
    foreach (['parentname', 'parent', 'note', 'school', 'phone'] as $col) {
        drop_column_if_exists($con, 'stud', $col);
    }
    if ($teachers_n === 1 && $tid > 0) {
        $con->query("UPDATE stud SET teacher_id={$tid} WHERE teacher_id IS NULL OR teacher_id=0");
        $info = $con->query("SHOW COLUMNS FROM stud LIKE 'teacher_id'");
        $col = $info ? $info->fetch_assoc() : null;
        if ($col && strtoupper((string)$col['Null']) === 'YES') {
            $con->query("ALTER TABLE stud MODIFY teacher_id INT NOT NULL");
        }
    }
    $idx = $con->query("SHOW INDEX FROM stud WHERE Key_name='idx_stud_teacher'");
    if ($idx && $idx->num_rows === 0) {
        $con->query("ALTER TABLE stud ADD KEY idx_stud_teacher (teacher_id)");
    }

    $tg = '';
    $g = $con->query("SELECT v FROM app_settings WHERE k='tg_chat_id' LIMIT 1");
    if ($g && ($gr = $g->fetch_assoc())) $tg = trim((string)$gr['v']);
    if ($tid > 0 && $tg !== '') {
        $up = $con->prepare("UPDATE users SET tg_chat_id=? WHERE id=? AND (tg_chat_id IS NULL OR tg_chat_id='')");
        $up->bind_param('si', $tg, $tid);
        $up->execute();
        $up->close();
    }

    if ($tid > 0) {
        foreach (['debt_threshold' => '8', 'pack_lessons' => '8', 'group_size' => '6', 'chart_period' => 'academic'] as $k => $def) {
            $chk = $con->prepare("SELECT 1 FROM teacher_settings WHERE teacher_id=? AND k=? LIMIT 1");
            $chk->bind_param('is', $tid, $k);
            $chk->execute();
            $exists = (bool)$chk->get_result()->fetch_row();
            $chk->close();
            if ($exists) continue;
            $src = $con->prepare("SELECT v FROM app_settings WHERE k=? LIMIT 1");
            $src->bind_param('s', $k);
            $src->execute();
            $v = (string)($src->get_result()->fetch_assoc()['v'] ?? $def);
            $src->close();
            if ($v === '') $v = $def;
            $ins = $con->prepare("INSERT INTO teacher_settings (teacher_id,k,v) VALUES (?,?,?)");
            $ins->bind_param('iss', $tid, $k, $v);
            $ins->execute();
            $ins->close();
        }
    }

    $ex = $con->query("SHOW TABLES LIKE 'notifications'");
    if ($ex && $ex->num_rows > 0) {
        try { $con->query('DROP TABLE IF EXISTS notifications'); } catch (Throwable $e) {}
    }
    try { $con->query('ALTER TABLE schedule DROP FOREIGN KEY fk_schedule_group'); } catch (Throwable $e) {}
    drop_column_if_exists($con, 'schedule', 'group_id');
    foreach (['group_slots', 'group_names', 'study_groups'] as $dead) {
        try { $con->query("DROP TABLE IF EXISTS `{$dead}`"); } catch (Throwable $e) {}
    }
    foreach (['familiya', 'prof', 'remember_token_selector', 'remember_token_hashed', 'remember_token_expires'] as $col) {
        drop_column_if_exists($con, 'users', $col);
    }

    ensure_column($con, 'activity_log', 'teacher_id', "teacher_id INT NULL");
    if ($tid > 0) {
        $con->query("UPDATE activity_log SET teacher_id={$tid} WHERE teacher_id IS NULL");
    }
    ensure_column($con, 'users', 'fkey_rev', "fkey_rev INT NOT NULL DEFAULT 0");
    ensure_column($con, 'users', 'session_rev', "session_rev INT NOT NULL DEFAULT 0");
    $ln = $con->query("SHOW COLUMNS FROM stud LIKE 'lastname'");
    $lnc = $ln ? $ln->fetch_assoc() : null;
    if ($lnc && $lnc['Default'] === null) {
        $con->query("ALTER TABLE stud MODIFY lastname VARCHAR(100) NOT NULL DEFAULT ''");
    }
    $vis = $con->query("SHOW COLUMNS FROM dates LIKE 'visited'");
    $vc = $vis ? $vis->fetch_assoc() : null;
    if ($vc && (string)($vc['Default'] ?? '') !== '0') {
        $con->query("ALTER TABLE dates MODIFY visited TINYINT(1) NOT NULL DEFAULT 0");
    }
    $uq = $con->query("SHOW INDEX FROM schedule WHERE Key_name='uniq_schedule_slot'");
    if ($uq && $uq->num_rows === 0) {
        $dups = $con->query("SELECT user_id, weekday, time FROM schedule GROUP BY user_id, weekday, time HAVING COUNT(*)>1");
        if ($dups && $dups->num_rows > 0) {
            $con->query("DELETE s1 FROM schedule s1 INNER JOIN schedule s2 ON s1.user_id=s2.user_id AND s1.weekday=s2.weekday AND s1.time=s2.time AND s1.id>s2.id");
        }
        $con->query("ALTER TABLE schedule ADD UNIQUE KEY uniq_schedule_slot (user_id, weekday, time)");
    }
    if (function_exists('ensure_dates_time_schema')) {
        ensure_dates_time_schema($con);
    }
    ensure_index($con, 'dates', 'idx_dates_date', 'dates');
    ensure_index($con, 'stud', 'idx_stud_teacher_archived', 'teacher_id, archived');
    ensure_index($con, 'activity_log', 'idx_activity_teacher_id', 'teacher_id, id');
    ensure_index($con, 'schedule', 'idx_schedule_wd_time', 'weekday, time');
    login_attempts_ensure($con);
    ensure_schema_fks($con);
}

function ensure_schema_fks(mysqli $con): void {
    $db = '';
    $r = $con->query('SELECT DATABASE()');
    if ($r && ($row = $r->fetch_row())) $db = (string)$row[0];
    if ($db === '') return;
    $has = static function (mysqli $con, string $db, string $name): bool {
        $st = $con->prepare("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=? AND CONSTRAINT_NAME=? LIMIT 1");
        $st->bind_param('ss', $db, $name);
        $st->execute();
        $ok = (bool)$st->get_result()->fetch_row();
        $st->close();
        return $ok;
    };
    $fks = [
        'fk_stud_teacher' => "ALTER TABLE stud ADD CONSTRAINT fk_stud_teacher FOREIGN KEY (teacher_id) REFERENCES users(id)",
        'fk_teacher_settings_user' => "ALTER TABLE teacher_settings ADD CONSTRAINT fk_teacher_settings_user FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE",
        'fk_remember_user' => "ALTER TABLE remember_tokens ADD CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE",
        'fk_activity_teacher' => "ALTER TABLE activity_log ADD CONSTRAINT fk_activity_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL",
    ];
    foreach ($fks as $name => $sql) {
        if ($has($con, $db, $name)) continue;
        try { $con->query($sql); } catch (Throwable $e) {}
    }
}
