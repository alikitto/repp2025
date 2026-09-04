<?php
declare(strict_types=1);
require_once __DIR__ . '/util.php';

function csp_nonce(): string {
    if (empty($GLOBALS['_csp_nonce'])) {
        $GLOBALS['_csp_nonce'] = base64_encode(random_bytes(16));
    }
    return $GLOBALS['_csp_nonce'];
}

function csp_nonce_attr(): string {
    return 'nonce="' . htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8') . '"';
}

function app_session_start(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
    if (!headers_sent()) {
        $n = csp_nonce();
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'nonce-{$n}'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'");
        if (is_https()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        header_remove('X-Powered-By');
    }
}
