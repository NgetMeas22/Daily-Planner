<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/i18n.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function normalize_theme_mode(?string $theme): string
{
    return in_array($theme, ['light', 'dark'], true) ? $theme : 'light';
}

function current_request_path(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($uri, PHP_URL_PATH);
    $query = parse_url($uri, PHP_URL_QUERY);
    if (!is_string($path) || $path === '') {
        return 'dashboard.php';
    }
    $file = basename($path);
    if ($file === '') {
        return 'dashboard.php';
    }
    return $query ? $file . '?' . $query : $file;
}

function safe_return_path(?string $value, string $fallback = 'dashboard.php'): string
{
    if (!$value) {
        return $fallback;
    }
    $parts = parse_url($value);
    if ($parts === false) {
        return $fallback;
    }
    $path = $parts['path'] ?? '';
    if ($path === '') {
        return $fallback;
    }
    $file = basename($path);
    if ($file === '' || !preg_match('/^[A-Za-z0-9_.-]+\.php$/', $file)) {
        return $fallback;
    }
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    return $file . $query;
}

function redirect($path)
{
    header("Location: {$path}");
    exit;
}

function is_logged_in()
{
    return isset($_SESSION['user_id']);
}

function require_login()
{
    if (!is_logged_in()) {
        redirect('index.php');
    }
}

if (is_logged_in()) {
    // Fast path: trust the long-lived cookie first so page loads skip the
    // per-request settings query. The DB is only hit when no cookie exists
    // yet (first login on a device); the choice is then persisted as a cookie.
    if (!empty($_COOKIE['theme_mode'])) {
        $_SESSION['theme'] = normalize_theme_mode($_COOKIE['theme_mode']);
    } else {
        $themeMode = 'light';
        $themeStmt = $conn->prepare('SELECT theme_mode FROM settings WHERE user_id = ? LIMIT 1');
        $themeStmt->bind_param('i', $_SESSION['user_id']);
        $themeStmt->execute();
        $themeRow = $themeStmt->get_result()->fetch_assoc();
        $themeStmt->close();

        if ($themeRow && isset($themeRow['theme_mode'])) {
            $themeMode = normalize_theme_mode($themeRow['theme_mode']);
        }

        setcookie('theme_mode', $themeMode, [
            'expires' => time() + 60 * 60 * 24 * 365,
            'path' => '/',
            'samesite' => 'Lax',
        ]);
        $_SESSION['theme'] = $themeMode;
    }
} else {
    $_SESSION['theme'] = normalize_theme_mode($_COOKIE['theme_mode'] ?? ($_SESSION['theme'] ?? 'light'));
}
