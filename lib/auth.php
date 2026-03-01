<?php
declare(strict_types=1);

function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        // Ajusta el path si quieres que aplique solo a /gyrosfe
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/gyrosfe',
            'secure' => true,      // requiere https
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function auth_require_login(): void
{
    auth_start_session();
    if (empty($_SESSION['user_id'])) {
        header('Location: /gyrosfe/ui/login.php');
        exit;
    }
}

function auth_logout(): void
{
    auth_start_session();
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    session_destroy();
}
