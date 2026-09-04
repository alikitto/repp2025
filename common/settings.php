<?php
const SCHEMA_VERSION = 9;

function schema_is_current(mysqli $con): bool {
    $r = $con->query("SHOW TABLES LIKE 'app_settings'");
    if (!$r || $r->num_rows === 0) return false;
    $st = $con->prepare("SELECT v FROM app_settings WHERE k='schema_version' LIMIT 1");
    if (!$st) return false;
    $st->execute();
    $st->bind_result($v);
    $ok = $st->fetch();
    $st->close();
    return $ok && (int)$v === SCHEMA_VERSION;
}

function ensure_settings_table(mysqli $con): void {
    static $done = false;
    if ($done) return;
    $con->query("CREATE TABLE IF NOT EXISTS app_settings (
        k VARCHAR(64) NOT NULL,
        v TEXT NOT NULL,
        PRIMARY KEY (k)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $col = $con->query("SHOW COLUMNS FROM app_settings LIKE 'v'");
    $info = $col ? $col->fetch_assoc() : null;
    if ($info && stripos((string)$info['Type'], 'text') === false) {
        $con->query("ALTER TABLE app_settings MODIFY v TEXT NOT NULL");
    }
    $done = true;
}

function secret_setting_keys(): array {
    return ['google_api_key', 'google_client_secret', 'google_refresh_token', 'tg_bot_token'];
}

function is_secret_setting(string $k): bool {
    return in_array($k, secret_setting_keys(), true);
}

function app_secret_bytes(): string {
    static $key = null;
    if (is_string($key)) return $key;
    $s = trim((string)(getenv('APP_SECRET') ?: ''));
    if ($s !== '') {
        $key = hash('sha256', $s, true);
        return $key;
    }
    $file = dirname(__DIR__) . '/data/app_secret';
    if (is_readable($file)) {
        $b = (string)file_get_contents($file);
        if (strlen($b) === 32) {
            $key = $b;
            return $key;
        }
    }
    // Генерация только из CLI: иначе в контейнере получится эфемерный ключ,
    // который исчезнет при следующем деплое и сделает enc:-значения нечитаемыми.
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('APP_SECRET не задан. Задайте переменную окружения APP_SECRET или создайте data/app_secret через CLI.');
    }
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать каталог data/ для APP_SECRET.');
    }
    $b = random_bytes(32);
    if (file_put_contents($file, $b, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать data/app_secret.');
    }
    @chmod($file, 0600);
    $key = $b;
    return $key;
}

function secret_encrypt(string $plain): string {
    if ($plain === '') return '';
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', app_secret_bytes(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false || $tag === '') {
        throw new RuntimeException('Не удалось зашифровать секрет.');
    }
    return 'enc:' . base64_encode($iv . $tag . $ct);
}

function secret_decrypt(string $stored): string {
    if ($stored === '' || !str_starts_with($stored, 'enc:')) return $stored;
    $raw = base64_decode(substr($stored, 4), true);
    if (!is_string($raw) || strlen($raw) < 29) {
        throw new RuntimeException('Повреждённый секрет. Проверьте APP_SECRET.');
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct = substr($raw, 28);
    $plain = openssl_decrypt($ct, 'aes-256-gcm', app_secret_bytes(), OPENSSL_RAW_DATA, $iv, $tag);
    if (!is_string($plain)) {
        throw new RuntimeException('Не удалось расшифровать секрет. Проверьте APP_SECRET.');
    }
    return $plain;
}

// Для горячих путей: неверный APP_SECRET не должен ронять страницу или логин,
// секрет просто считается ненастроенным.
function secret_decrypt_quiet(string $stored): string {
    try {
        return secret_decrypt($stored);
    } catch (Throwable $e) {
        error_log('secret_decrypt: ' . $e->getMessage());
        return '';
    }
}

// Пустая строка — всё в порядке, иначе текст проблемы для показа админу.
function secret_health(mysqli $con): string {
    try {
        app_secret_bytes();
    } catch (Throwable $e) {
        return $e->getMessage();
    }
    ensure_settings_table($con);
    $keys = secret_setting_keys();
    $in = implode(',', array_fill(0, count($keys), '?'));
    $st = $con->prepare("SELECT v FROM app_settings WHERE k IN ($in) AND v LIKE 'enc:%'");
    if (!$st) return '';
    $st->bind_param(str_repeat('s', count($keys)), ...$keys);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    foreach ($rows as $r) {
        try {
            secret_decrypt((string)$r['v']);
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }
    return '';
}

function app_setting(mysqli $con, string $k, string $default): string {
    ensure_settings_table($con);
    $st = $con->prepare("SELECT v FROM app_settings WHERE k=?");
    $st->bind_param('s', $k);
    $st->execute();
    $st->bind_result($v);
    $ok = $st->fetch();
    $st->close();
    if (!$ok) return $default;
    $raw = (string)$v;
    if (!is_secret_setting($k) || $raw === '') return $raw !== '' ? $raw : $default;
    if (str_starts_with($raw, 'enc:')) return secret_decrypt_quiet($raw);
    save_app_setting($con, $k, $raw);
    return $raw;
}

function session_epoch(mysqli $con): int {
    return (int)app_setting($con, 'session_epoch', '0');
}

function bump_session_epoch(mysqli $con, bool $keep_current = true): int {
    $epoch = time();
    save_app_setting($con, 'session_epoch', (string)$epoch);
    if ($keep_current && session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['session_epoch'] = $epoch;
    }
    return $epoch;
}

function bump_user_session_rev(mysqli $con, int $uid): int {
    if ($uid <= 0) return 0;
    $con->query('UPDATE users SET session_rev=session_rev+1 WHERE id=' . $uid);
    $st = $con->prepare('SELECT session_rev FROM users WHERE id=? LIMIT 1');
    $st->bind_param('i', $uid);
    $st->execute();
    $rev = (int)($st->get_result()->fetch_assoc()['session_rev'] ?? 0);
    $st->close();
    if (session_status() === PHP_SESSION_ACTIVE && (int)($_SESSION['id'] ?? 0) === $uid) {
        $_SESSION['session_rev'] = $rev;
    }
    return $rev;
}

function save_app_setting(mysqli $con, string $k, string $v): void {
    ensure_settings_table($con);
    if (is_secret_setting($k)) {
        $v = $v === '' ? '' : secret_encrypt($v);
    }
    $st = $con->prepare("INSERT INTO app_settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)");
    $st->bind_param('ss', $k, $v);
    $st->execute();
    $st->close();
}

function clamp_setting_int(int $n, int $min = 1, int $max = 99): int {
    return max($min, min($max, $n));
}

function remember_days(mysqli $con): int {
    return clamp_setting_int((int)app_setting($con, 'remember_days', '30'), 1, 365);
}

function backup_time(mysqli $con): string {
    $v = trim(app_setting($con, 'backup_time', '23:00'));
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) return '23:00';
    return $v;
}

function backup_freq(mysqli $con): string {
    $v = app_setting($con, 'backup_freq', 'daily');
    return in_array($v, ['daily', 'weekly', 'monthly'], true) ? $v : 'daily';
}

function backup_weekday(mysqli $con): int {
    return clamp_setting_int((int)app_setting($con, 'backup_weekday', '7'), 1, 7);
}

function backup_monthday(mysqli $con): int {
    return clamp_setting_int((int)app_setting($con, 'backup_monthday', '1'), 1, 28);
}

function report_time(mysqli $con): string {
    $v = trim(app_setting($con, 'report_time', '20:00'));
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) return '20:00';
    return $v;
}

function parse_hhmm(string $v, string $default): string {
    $v = trim($v);
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : $default;
}

function teacher_report_time(mysqli $con, int $tid): string {
    $def = report_time($con);
    return parse_hhmm(teacher_setting($con, $tid, 'report_time', $def), $def);
}

function teacher_report_day(mysqli $con, int $tid): string {
    $v = teacher_setting($con, $tid, 'report_day', 'last');
    if ($v === 'last') return 'last';
    $n = (int)$v;
    return ($n >= 1 && $n <= 28) ? (string)$n : 'last';
}

function teacher_debt_when(mysqli $con, int $tid): string {
    $v = teacher_setting($con, $tid, 'debt_when', 'after_start');
    return in_array($v, ['clock', 'after_arrival'], true) ? $v : 'after_start';
}

function teacher_debt_offset(mysqli $con, int $tid): int {
    return clamp_setting_int((int)teacher_setting($con, $tid, 'debt_offset', '15'), 0, 180);
}

function teacher_debt_offset_after(mysqli $con, int $tid): int {
    return clamp_setting_int((int)teacher_setting($con, $tid, 'debt_offset_after', '15'), 0, 180);
}

function teacher_debt_time(mysqli $con, int $tid): string {
    return parse_hhmm(teacher_setting($con, $tid, 'debt_time', '21:00'), '21:00');
}

/** @return array<string,string> */
function timezone_choices(): array {
    return [
        'Asia/Baku' => 'Баку (UTC+4)',
        'Europe/Moscow' => 'Москва (UTC+3)',
        'Europe/Istanbul' => 'Стамбул (UTC+3)',
        'Asia/Yerevan' => 'Ереван (UTC+4)',
        'Asia/Tbilisi' => 'Тбилиси (UTC+4)',
        'Europe/Kyiv' => 'Киев (UTC+2/+3)',
        'Europe/Minsk' => 'Минск (UTC+3)',
        'Asia/Almaty' => 'Алматы (UTC+5)',
        'Asia/Tashkent' => 'Ташкент (UTC+5)',
        'Europe/Berlin' => 'Берлин (UTC+1/+2)',
        'UTC' => 'UTC',
    ];
}

function app_timezone(mysqli $con): string {
    $v = trim(app_setting($con, 'app_timezone', ''));
    if ($v === '') $v = (string)(getenv('TZ') ?: 'Asia/Baku');
    return in_array($v, timezone_identifiers_list(), true) ? $v : 'Asia/Baku';
}

function apply_app_timezone(mysqli $con): void {
    date_default_timezone_set(app_timezone($con));
    $GLOBALS['_app_tz_applied'] = true;
}

function ensure_teacher_settings_table(mysqli $con): void {
    static $done = false;
    if ($done) return;
    $con->query("CREATE TABLE IF NOT EXISTS teacher_settings (
        teacher_id INT NOT NULL,
        k VARCHAR(64) NOT NULL,
        v TEXT NOT NULL,
        PRIMARY KEY (teacher_id, k)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function teacher_setting(mysqli $con, int $tid, string $k, string $default): string {
    if ($tid <= 0) return $default;
    ensure_teacher_settings_table($con);
    $st = $con->prepare("SELECT v FROM teacher_settings WHERE teacher_id=? AND k=?");
    $st->bind_param('is', $tid, $k);
    $st->execute();
    $st->bind_result($v);
    $ok = $st->fetch();
    $st->close();
    return $ok ? (string)$v : $default;
}

function save_teacher_setting(mysqli $con, int $tid, string $k, string $v): void {
    if ($tid <= 0) return;
    ensure_teacher_settings_table($con);
    $st = $con->prepare("INSERT INTO teacher_settings (teacher_id,k,v) VALUES (?,?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)");
    $st->bind_param('iss', $tid, $k, $v);
    $st->execute();
    $st->close();
}

function setting_teacher_id(?int $teacher_id = null): int {
    if ($teacher_id !== null && $teacher_id > 0) return $teacher_id;
    return function_exists('current_teacher_id') ? current_teacher_id() : 0;
}

function debt_threshold(mysqli $con, ?int $teacher_id = null): int {
    $tid = setting_teacher_id($teacher_id);
    $def = app_setting($con, 'debt_threshold', '8');
    $v = $tid > 0 ? teacher_setting($con, $tid, 'debt_threshold', $def) : $def;
    return clamp_setting_int((int)$v);
}

function pack_lessons(mysqli $con, ?int $teacher_id = null): int {
    $tid = setting_teacher_id($teacher_id);
    $def = app_setting($con, 'pack_lessons', '8');
    $v = $tid > 0 ? teacher_setting($con, $tid, 'pack_lessons', $def) : $def;
    return clamp_setting_int((int)$v);
}

function group_size(mysqli $con, ?int $teacher_id = null): int {
    $tid = setting_teacher_id($teacher_id);
    $def = app_setting($con, 'group_size', '6');
    $v = $tid > 0 ? teacher_setting($con, $tid, 'group_size', $def) : $def;
    return clamp_setting_int((int)$v, 1, 12);
}

/** @return int[] минуты, кратные сетке расписания */
function lesson_duration_choices(): array {
    return [30, 60, 90, 120];
}

function lesson_duration(mysqli $con, ?int $teacher_id = null): int {
    $tid = setting_teacher_id($teacher_id);
    $def = app_setting($con, 'lesson_duration', '90');
    $v = $tid > 0 ? teacher_setting($con, $tid, 'lesson_duration', $def) : $def;
    $n = (int)$v;
    return in_array($n, lesson_duration_choices(), true) ? $n : 90;
}

function chart_period(mysqli $con, ?int $teacher_id = null): string {
    $tid = setting_teacher_id($teacher_id);
    $def = app_setting($con, 'chart_period', 'academic');
    $v = $tid > 0 ? teacher_setting($con, $tid, 'chart_period', $def) : $def;
    return $v === 'rolling' ? 'rolling' : 'academic';
}

/** @return array{0:string,1:string,2:int} from, to exclusive, months */
function finance_chart_span(string $period, string $month_start): array {
    if ($period === 'rolling') {
        $from = date('Y-m-01', strtotime($month_start . ' -11 months'));
        $to = date('Y-m-d', strtotime($month_start . ' +1 month'));
        return [$from, $to, 12];
    }
    $y = (int)substr($month_start, 0, 4);
    $m = (int)substr($month_start, 5, 2);
    $start_y = $m >= 8 ? $y : $y - 1;
    return [sprintf('%04d-09-01', $start_y), sprintf('%04d-08-01', $start_y + 1), 11];
}

function integration_setting(mysqli $con, string $k, string $env = ''): string {
    $v = trim(app_setting($con, $k, ''));
    if ($v !== '') return $v;
    return $env !== '' ? trim((string)(getenv($env) ?: '')) : '';
}

function tg_bot_token(mysqli $con): string {
    $v = integration_setting($con, 'tg_bot_token', 'TG_BOT_TOKEN');
    return str_contains($v, 'ABC-Token') ? '' : $v;
}

function tg_chat_id(mysqli $con, ?int $teacher_id = null): string {
    $tid = setting_teacher_id($teacher_id);
    if ($tid > 0) {
        $st = $con->prepare("SELECT tg_chat_id FROM users WHERE id=? LIMIT 1");
        $st->bind_param('i', $tid);
        $st->execute();
        $st->bind_result($chat);
        $ok = $st->fetch();
        $st->close();
        if ($ok && trim((string)$chat) !== '') return trim((string)$chat);
    }
    return integration_setting($con, 'tg_chat_id', 'TG_CHAT_ID');
}

function save_teacher_chat_id(mysqli $con, int $tid, string $chat): void {
    if ($tid <= 0) return;
    $st = $con->prepare("UPDATE users SET tg_chat_id=? WHERE id=?");
    $st->bind_param('si', $chat, $tid);
    $st->execute();
    $st->close();
}

function google_api_key(mysqli $con): string {
    return integration_setting($con, 'google_api_key', 'GOOGLE_API_KEY');
}

function google_client_id(mysqli $con): string {
    return integration_setting($con, 'google_client_id', 'GOOGLE_CLIENT_ID');
}

function google_client_secret(mysqli $con): string {
    return integration_setting($con, 'google_client_secret', 'GOOGLE_CLIENT_SECRET');
}

function google_refresh_token(mysqli $con): string {
    return integration_setting($con, 'google_refresh_token', 'GOOGLE_REFRESH_TOKEN');
}

function google_drive_folder_id(mysqli $con): string {
    return integration_setting($con, 'google_drive_folder_id', 'GOOGLE_DRIVE_FOLDER_ID');
}

function google_sa_json(): string {
    $raw = trim((string)(getenv('GOOGLE_SERVICE_ACCOUNT_JSON') ?: ''));
    if ($raw === '') return '';
    if ($raw[0] !== '{') {
        $decoded = base64_decode($raw, true);
        if (is_string($decoded) && $decoded !== '' && $decoded[0] === '{') {
            return $decoded;
        }
    }
    return $raw;
}

function lesson_price_from_monthly(float $monthly, int $pack): float {
    $pack = max(1, $pack);
    return $monthly > 0 ? round($monthly / $pack, 2) : 0.0;
}

function price_preset_label(float $monthly): string {
    return $monthly > 0 ? 'при ' . fmt_price($monthly) . ' AZN в мес.' : '';
}

function preset_monthly(array $p, int $pack): float {
    $m = round((float)($p['monthly'] ?? 0), 2);
    if ($m > 0) return $m;
    $price = round((float)($p['price'] ?? 0), 2);
    return $price > 0 ? round($price * max(1, $pack), 2) : 0.0;
}

function normalize_price_preset(array $p, int $pack): ?array {
    $monthly = preset_monthly($p, $pack);
    if ($monthly <= 0) return null;
    return [
        'monthly' => $monthly,
        'price' => lesson_price_from_monthly($monthly, $pack),
        'label' => price_preset_label($monthly),
    ];
}

function price_presets(mysqli $con, ?int $teacher_id = null): array {
    $tid = setting_teacher_id($teacher_id);
    $pack = pack_lessons($con, $tid ?: null);
    $def = array_values(array_filter([
        normalize_price_preset(['monthly' => 150], $pack),
        normalize_price_preset(['monthly' => 120], $pack),
    ]));
    $raw = $tid > 0 ? teacher_setting($con, $tid, 'price_presets', '') : '';
    if ($raw === '') return $def;
    $arr = json_decode($raw, true);
    if (!is_array($arr)) return $def;
    $out = [];
    foreach ($arr as $p) {
        if (!is_array($p)) continue;
        $row = normalize_price_preset($p, $pack);
        if ($row) $out[] = $row;
    }
    return $out ?: $def;
}

function fmt_price(float $n): string {
    return abs($n - round($n)) < 0.001 ? (string)(int)round($n) : number_format($n, 2, '.', '');
}

function default_monthly(mysqli $con, ?int $teacher_id = null): float {
    $tid = setting_teacher_id($teacher_id);
    $presets = price_presets($con, $tid ?: null);
    $v = $tid > 0 ? teacher_setting($con, $tid, 'default_monthly', '') : '';
    if ($v !== '' && (float)$v > 0) return (float)$v;
    $old = $tid > 0 ? teacher_setting($con, $tid, 'default_price', '') : '';
    if ($old !== '' && (float)$old > 0) {
        return round((float)$old * max(1, pack_lessons($con, $tid ?: null)), 2);
    }
    return (float)($presets[0]['monthly'] ?? 0);
}

function default_lesson_price(mysqli $con, ?int $teacher_id = null): float {
    $tid = setting_teacher_id($teacher_id);
    return lesson_price_from_monthly(default_monthly($con, $tid ?: null), pack_lessons($con, $tid ?: null));
}

function price_preset_buttons(mysqli $con): string {
    $html = '<div class="price-presets">';
    foreach (price_presets($con) as $p) {
        $raw = fmt_price((float)$p['price']);
        $h = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
        $html .= '<button type="button" class="price-preset" data-price="'.$h.'"><b>'.$h.' AZN</b>';
        if ($p['label'] !== '') {
            $html .= '<span>'.htmlspecialchars($p['label'], ENT_QUOTES, 'UTF-8').'</span>';
        }
        $html .= '</button>';
    }
    return $html.'</div>';
}

function teacher_tg_on(mysqli $con, int $tid, string $k): bool {
    return $tid > 0 && teacher_setting($con, $tid, $k, '1') !== '0';
}

function load_app_settings(mysqli $con, ?int $teacher_id = null): void {
    $tid = setting_teacher_id($teacher_id);
    $GLOBALS['_debt_threshold'] = debt_threshold($con, $tid ?: null);
    $GLOBALS['_pack_lessons'] = pack_lessons($con, $tid ?: null);
    $GLOBALS['_chart_period'] = chart_period($con, $tid ?: null);
    $GLOBALS['_group_size'] = group_size($con, $tid ?: null);
    $GLOBALS['_lesson_duration'] = lesson_duration($con, $tid ?: null);
    apply_app_timezone($con);
}
