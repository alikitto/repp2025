<?php
declare(strict_types=1);

function ensure_totp_schema(mysqli $con): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (!function_exists('ensure_column')) {
        return;
    }
    ensure_column($con, 'users', 'totp_secret', "totp_secret VARCHAR(255) NULL");
    ensure_column($con, 'users', 'totp_enabled', "totp_enabled TINYINT(1) NOT NULL DEFAULT 0");
    ensure_column($con, 'users', 'totp_last_counter', "totp_last_counter INT NULL");
    $col = $con->query("SHOW COLUMNS FROM users LIKE 'totp_secret'");
    $info = $col ? $col->fetch_assoc() : null;
    if ($info && stripos((string)$info['Type'], 'varchar(255)') === false) {
        $con->query("ALTER TABLE users MODIFY totp_secret VARCHAR(255) NULL");
    }
    $r = $con->query("SELECT id, totp_secret FROM users WHERE totp_secret IS NOT NULL AND totp_secret<>'' AND totp_secret NOT LIKE 'enc:%'");
    if ($r && function_exists('secret_encrypt')) {
        // Без APP_SECRET шифровать нечем. Оставляем значения как есть, чтобы не
        // ронять приложение на старте: totp_secret_plain пропускает не-enc: без
        // расшифровки, поэтому 2FA продолжает работать.
        try {
            $up = $con->prepare('UPDATE users SET totp_secret=? WHERE id=?');
            while ($row = $r->fetch_assoc()) {
                $enc = secret_encrypt((string)$row['totp_secret']);
                $id = (int)$row['id'];
                $up->bind_param('si', $enc, $id);
                $up->execute();
            }
            $up->close();
        } catch (Throwable $e) {
            error_log('totp migration: ' . $e->getMessage());
        }
    }
}

function totp_base32_encode(string $bin): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    $len = strlen($bin);
    for ($i = 0; $i < $len; $i++) {
        $bits .= str_pad(decbin(ord($bin[$i])), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        if (strlen($chunk) < 5) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        }
        $out .= $alphabet[bindec($chunk)];
    }
    return $out;
}

function totp_base32_decode(string $b32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32) ?? '');
    $bits = '';
    $len = strlen($b32);
    for ($i = 0; $i < $len; $i++) {
        $v = strpos($alphabet, $b32[$i]);
        if ($v === false) {
            return '';
        }
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $out .= chr(bindec($chunk));
        }
    }
    return $out;
}

function totp_secret_new(): string {
    return totp_base32_encode(random_bytes(20));
}

function totp_at(string $secret, int $counter): string {
    $key = totp_base32_decode($secret);
    if ($key === '') {
        return '';
    }
    $bin = pack('N*', 0, $counter);
    $hash = hash_hmac('sha1', $bin, $key, true);
    $off = ord($hash[19]) & 0x0f;
    $code = (
        ((ord($hash[$off]) & 0x7f) << 24)
        | (ord($hash[$off + 1]) << 16)
        | (ord($hash[$off + 2]) << 8)
        | ord($hash[$off + 3])
    ) % 1000000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}

function totp_now(string $secret, ?int $time = null): string {
    return totp_at($secret, intdiv($time ?? time(), 30));
}

function totp_verify(string $secret, string $code, int $window = 1, ?int $last_counter = null): bool {
    $code = preg_replace('/\D+/', '', $code) ?? '';
    if (strlen($code) !== 6 || totp_base32_decode($secret) === '') {
        return false;
    }
    $slot = intdiv(time(), 30);
    for ($i = -$window; $i <= $window; $i++) {
        $c = $slot + $i;
        if ($last_counter !== null && $c <= $last_counter) continue;
        if (hash_equals(totp_at($secret, $c), $code)) {
            $GLOBALS['_totp_used_counter'] = $c;
            return true;
        }
    }
    return false;
}

function totp_secret_plain(array $user): string {
    $raw = (string)($user['totp_secret'] ?? '');
    if ($raw === '') return '';
    return function_exists('secret_decrypt_quiet') ? secret_decrypt_quiet($raw) : $raw;
}

function totp_verify_user(mysqli $con, array $user, string $code): bool {
    if (!totp_user_on($user)) return false;
    $uid = (int)($user['id'] ?? 0);
    $last = null;
    if ($uid > 0) {
        $st = $con->prepare('SELECT totp_last_counter FROM users WHERE id=? LIMIT 1');
        if ($st) {
            $st->bind_param('i', $uid);
            $st->execute();
            $v = $st->get_result()->fetch_assoc()['totp_last_counter'] ?? null;
            $st->close();
            if ($v !== null && $v !== '') $last = (int)$v;
        }
    }
    if (!totp_verify(totp_secret_plain($user), $code, 1, $last)) return false;
    if ($uid > 0 && isset($GLOBALS['_totp_used_counter'])) {
        $c = (int)$GLOBALS['_totp_used_counter'];
        $up = $con->prepare('UPDATE users SET totp_last_counter=? WHERE id=?');
        $up->bind_param('ii', $c, $uid);
        $up->execute();
        $up->close();
    }
    return true;
}

function totp_uri(string $account, string $secret): string {
    $issuer = 'Tutor CRM';
    $acc = $account;
    $make = static function (string $a) use ($issuer, $secret): string {
        $label = rawurlencode($issuer) . ':' . rawurlencode($a);
        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
        ], '', '&', PHP_QUERY_RFC3986);
    };
    $uri = $make($acc);
    if (strlen($uri) > 130) {
        $uri = $make(substr($acc, 0, 20));
    }
    return $uri;
}

function totp_user_on(?array $user): bool {
    return $user
        && !empty($user['totp_enabled'])
        && !empty($user['totp_secret']);
}
