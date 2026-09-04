<?php
function proxy_trusted(): bool {
    if (getenv('TRUST_PROXY') === '1') return true;
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return $ip === '127.0.0.1' || $ip === '::1';
}

function client_ip(): string {
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (proxy_trusted()) {
        $xff = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        if ($xff === '') {
            $fwd = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            $xff = trim(explode(',', $fwd)[0]);
        }
        if (filter_var($xff, FILTER_VALIDATE_IP)) {
            return $xff;
        }
    }
    return $remote;
}

function is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
    return proxy_trusted() && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function remember_cookie_opts(int $expires): array {
    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function remember_cookie_clear(): void {
    setcookie('remember_me', '', remember_cookie_opts(time() - 3600));
}

function ensure_remember_tokens(mysqli $con): void {
    static $done = false;
    if ($done) return;
    $con->query("CREATE TABLE IF NOT EXISTS remember_tokens (
        selector CHAR(32) NOT NULL,
        hashed VARCHAR(255) NOT NULL,
        expires DATETIME NOT NULL,
        user_id INT NOT NULL,
        PRIMARY KEY (selector),
        KEY idx_remember_user (user_id),
        KEY idx_remember_expires (expires)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function remember_prune(mysqli $con): void {
    $con->query("DELETE FROM remember_tokens WHERE expires < NOW()");
}

function remember_issue(mysqli $con, int $userId): void {
    ensure_remember_tokens($con);
    remember_prune($con);
    $days = function_exists('remember_days') ? remember_days($con) : 30;
    $ttl = 86400 * $days;
    $selector = bin2hex(random_bytes(16));
    $validator = bin2hex(random_bytes(32));
    setcookie(
        'remember_me',
        $selector . ':' . $validator,
        remember_cookie_opts(time() + $ttl)
    );
    $hashed = password_hash($validator, PASSWORD_DEFAULT);
    $expires = date('Y-m-d H:i:s', time() + $ttl);
    $st = $con->prepare(
        "INSERT INTO remember_tokens (selector, hashed, expires, user_id) VALUES (?,?,?,?)"
    );
    $st->bind_param('sssi', $selector, $hashed, $expires, $userId);
    $st->execute();
    $st->close();
}

function remember_revoke_user(mysqli $con, int $userId): void {
    if ($userId <= 0) return;
    ensure_remember_tokens($con);
    $st = $con->prepare("DELETE FROM remember_tokens WHERE user_id=?");
    $st->bind_param('i', $userId);
    $st->execute();
    $st->close();
}

function remember_revoke(mysqli $con, string $selector): void {
    if ($selector === '') return;
    ensure_remember_tokens($con);
    $st = $con->prepare("DELETE FROM remember_tokens WHERE selector=?");
    $st->bind_param('s', $selector);
    $st->execute();
    $st->close();
}

function remember_find(mysqli $con, string $selector): ?array {
    ensure_remember_tokens($con);
    remember_prune($con);
    $st = $con->prepare(
        "SELECT u.id, u.login, u.name, u.role, u.totp_secret, u.totp_enabled, t.hashed AS remember_token_hashed
         FROM remember_tokens t
         JOIN users u ON u.id = t.user_id
         WHERE t.selector = ? AND t.expires > NOW()
         LIMIT 1"
    );
    $st->bind_param('s', $selector);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

function login_attempts_ensure(mysqli $con): void {
    $con->query("CREATE TABLE IF NOT EXISTS login_attempts (
        id BIGINT NOT NULL AUTO_INCREMENT,
        ip VARCHAR(45) NOT NULL,
        login VARCHAR(64) NOT NULL,
        ts INT NOT NULL,
        PRIMARY KEY (id),
        KEY idx_la_ip_ts (ip, ts),
        KEY idx_la_login_ts (login, ts)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function login_rate_limited(string $ip, string $login): bool {
    $con = $GLOBALS['con'] ?? null;
    if (!$con instanceof mysqli) return false;
    login_attempts_ensure($con);
    $since = time() - 15 * 60;
    $low = strtolower($login);
    $st = $con->prepare('SELECT COUNT(*) FROM login_attempts WHERE login=? AND ts>=?');
    $st->bind_param('si', $low, $since);
    $st->execute();
    $st->bind_result($n);
    $st->fetch();
    $st->close();
    if ((int)$n >= 5) return true;
    $st = $con->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip=? AND ts>=?');
    $st->bind_param('si', $ip, $since);
    $st->execute();
    $st->bind_result($ipn);
    $st->fetch();
    $st->close();
    return (int)$ipn >= 20;
}

function login_rate_hit(string $ip, string $login): void {
    $con = $GLOBALS['con'] ?? null;
    if (!$con instanceof mysqli) return;
    login_attempts_ensure($con);
    $low = strtolower($login);
    $ts = time();
    $st = $con->prepare('INSERT INTO login_attempts (ip, login, ts) VALUES (?,?,?)');
    $st->bind_param('ssi', $ip, $low, $ts);
    $st->execute();
    $st->close();
    $old = $ts - 86400;
    $con->query('DELETE FROM login_attempts WHERE ts<' . (int)$old);
}

function login_rate_clear(string $ip, string $login): void {
    $con = $GLOBALS['con'] ?? null;
    if (!$con instanceof mysqli) return;
    $low = strtolower($login);
    $st = $con->prepare('DELETE FROM login_attempts WHERE login=? OR (ip=? AND login=?)');
    $st->bind_param('sss', $low, $ip, $low);
    $st->execute();
    $st->close();
}

function current_debt_threshold(): int {
    return (int)($GLOBALS['_debt_threshold'] ?? 8);
}

function current_pack_lessons(): int {
    return (int)($GLOBALS['_pack_lessons'] ?? 8);
}

function student_pay_mode($raw): string {
    return $raw === 'postpaid' ? 'postpaid' : 'prepaid';
}

function student_is_debtor(int $bal, string $mode): bool {
    if (student_pay_mode($mode) === 'prepaid') return false;
    return $bal <= -current_debt_threshold();
}

function student_alert_kind(int $bal, string $mode): string {
    if (student_is_debtor($bal, $mode)) return 'debt';
    if (student_pay_mode($mode) === 'prepaid' && $bal < 0) return 'warn';
    return '';
}

function pay_alert_html(int $uid, string $kind): string {
    $on = $kind === 'debt' || $kind === 'warn';
    $title = $kind === 'debt' ? 'Долг ≥ ' . current_debt_threshold() : ($kind === 'warn' ? 'Баланс в минусе' : '');
    return '<span class="pay-alert'.($on ? ' is-'.$kind : '').'" data-pay-alert="'.$uid.'"'
        .($on ? '' : ' hidden').' title="'.h($title).'" aria-hidden="'.($on ? 'false' : 'true').'">'
        .'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">'
        .'<circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><circle cx="12" cy="16.2" r=".9" fill="currentColor" stroke="none"/>'
        .'</svg></span>';
}

function student_is_soon(int $bal, string $mode): bool {
    if (student_pay_mode($mode) === 'prepaid') return $bal <= 2;
    $limit = -current_debt_threshold();
    return $bal > $limit && $bal <= $limit + 2;
}

function student_due_pay(int $bal, string $mode): bool {
    if (student_pay_mode($mode) === 'prepaid') return $bal <= 0;
    return $bal <= -current_debt_threshold();
}

function student_pay_mode_label(string $mode): string {
    return student_pay_mode($mode) === 'postpaid' ? 'в конце месяца' : 'предоплата';
}

function student_pay_mode_chip(string $mode): string {
    return student_pay_mode($mode) === 'postpaid' ? 'pay-post' : 'pay-pre';
}

function balance_tone(int $balance, string $mode = 'prepaid'): string {
    if (student_pay_mode($mode) === 'prepaid') {
        if ($balance <= -current_debt_threshold()) return 'bad';
        if ($balance <= 2) return 'warn';
        return 'ok';
    }
    if ($balance <= -current_debt_threshold()) return 'bad';
    if ($balance < 0) return 'warn';
    if ($balance > 0) return 'ok';
    return 'zero';
}

function parse_ymd(?string $date): ?string {
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;
    $t = strtotime($date);
    if ($t === false) return null;
    $out = date('Y-m-d', $t);
    return $out === $date ? $out : null;
}

function valid_ymd(?string $date): string {
    return parse_ymd($date) ?? date('Y-m-d');
}

function require_past_or_today_ymd(?string $date): ?string {
    $d = parse_ymd($date);
    if ($d === null || $d > date('Y-m-d')) return null;
    return $d;
}

function month_start(?string $ym): string {
    if (is_string($ym) && preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) {
        $y = (int)$m[1];
        $mo = (int)$m[2];
        if ($y >= 2000 && $y <= 2100 && $mo >= 1 && $mo <= 12) {
            return sprintf('%04d-%02d-01', $y, $mo);
        }
    }
    return date('Y-m-01');
}

function fmt_money($n): string {
    return number_format((float)$n, 2, '.', ' ');
}

function pack_unit_price(float $fallback, $amount = null, $lessons = null): float {
    $lessons = (int)$lessons;
    $amount = (float)$amount;
    if ($lessons > 0 && $amount > 0) {
        return $amount / $lessons;
    }
    return max(0.0, $fallback);
}

function debt_azn(int $balance_lessons, float $price): float {
    if ($balance_lessons >= 0) return 0.0;
    if ($price <= 0 && isset($GLOBALS['con']) && $GLOBALS['con'] instanceof mysqli && function_exists('default_lesson_price')) {
        $price = default_lesson_price($GLOBALS['con']);
    }
    return abs($balance_lessons) * max(0.0, $price);
}

function student_balance_joins(): string {
    return "
    LEFT JOIN (
        SELECT user_id, SUM(lessons) AS paid_lessons FROM pays GROUP BY user_id
    ) bal_pays ON bal_pays.user_id = s.user_id
    LEFT JOIN (
        SELECT user_id, COUNT(*) AS visits FROM dates WHERE visited=1 GROUP BY user_id
    ) bal_dates ON bal_dates.user_id = s.user_id
    ";
}

function student_balance_expr(): string {
    return '(COALESCE(bal_pays.paid_lessons,0) - COALESCE(bal_dates.visits,0))';
}

function student_debt_joins(): string {
    return student_balance_joins() . "
    LEFT JOIN pays lp ON lp.id = (SELECT MAX(id) FROM pays px WHERE px.user_id = s.user_id)
    ";
}

function app_mysql_lock(mysqli $con, string $name, int $wait = 0): bool {
    $st = $con->prepare('SELECT GET_LOCK(?, ?)');
    $st->bind_param('si', $name, $wait);
    $st->execute();
    $st->bind_result($v);
    $st->fetch();
    $st->close();
    return (int)$v === 1;
}

function session_force_logout(mysqli $con): void {
    if (!empty($_COOKIE['remember_me'])) {
        $parts = explode(':', (string)$_COOKIE['remember_me'], 2);
        remember_revoke($con, $parts[0] ?? '');
    }
    remember_cookie_clear();
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function verify_user_password(mysqli $con, int $uid, string $password): bool {
    if ($uid <= 0 || $password === '') return false;
    $st = $con->prepare('SELECT password_hash FROM users WHERE id=? LIMIT 1');
    $st->bind_param('i', $uid);
    $st->execute();
    $hash = (string)($st->get_result()->fetch_assoc()['password_hash'] ?? '');
    $st->close();
    return $hash !== '' && password_verify($password, $hash);
}

function app_mysql_unlock(mysqli $con, string $name): void {
    $st = $con->prepare('SELECT RELEASE_LOCK(?)');
    $st->bind_param('s', $name);
    $st->execute();
    $st->close();
}

// URL статики с меткой времени файла: правки долетают до браузера без ручной очистки кэша.
if (!function_exists('asset')) {
    function asset(string $path): string {
        $mt = @filemtime(dirname(__DIR__) . $path);
        return htmlspecialchars($mt ? $path . '?v=' . $mt : $path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('h')) {
    function h($s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
