<?php
// =====================================================================
// Sessions, login check and CSRF protection for the operator pages.
// =====================================================================

require_once __DIR__ . '/db.php';

// Session cookie: not readable from JavaScript, only sent to this site
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_name('SMARTBINSESS');
session_start();

// Who is logged in (or null)
function current_user(): ?string
{
    return $_SESSION['username'] ?? null;
}

// Put this at the top of every page that needs a login.
// $json = true for API endpoints (reply 401 JSON instead of redirecting).
function require_login(bool $json = false): void
{
    if (current_user() !== null) {
        return;
    }
    if ($json) {
        json_out(['ok' => false, 'error' => 'not logged in'], 401);
    }
    header('Location: ' . app_url('login.php'));
    exit;
}

// URL of a page inside the app, works from any sub-folder (api/, tools/)
function app_url(string $page): string
{
    // dirname of /smart_bin/lib/auth.php seen from the web = /smart_bin
    $docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']));
    $appDir  = str_replace('\\', '/', realpath(__DIR__ . '/..'));
    $base    = substr($appDir, strlen($docRoot));
    return rtrim($base, '/') . '/' . $page;
}

// ---------------------------------------------------------------------
// CSRF: every form carries a secret token from the session.
// A form posted from another website will not have it and is rejected.
// ---------------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

// Hidden input to put inside every <form method="post">
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

// Call at the start of every POST handler
function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(403);
        die('Security check failed (CSRF). Reload the page and try again.');
    }
}

// One-time message shown after a redirect ("Bin saved", ...)
function flash(?string $msg = null, string $kind = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'kind' => $kind];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}
