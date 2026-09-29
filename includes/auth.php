<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/i18n.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

if (!function_exists('csrf_valid')) {
    function csrf_valid(?string $token = null): bool
    {
        $token = $token ?? ($_POST['csrf_token'] ?? null);
        return isset($token) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

function normalize_theme_mode(?string $theme): string
{
    return in_array($theme, ['light', 'dark', 'system'], true) ? $theme : 'dark';
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

function is_logged_in(): bool
{
    return isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0;
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('index.php');
    }

    global $conn;
    $userId = (int) $_SESSION['user_id'];

    if ($conn) {
        $stmt = $conn->prepare('SELECT id, fullname, username, avatar, avatar_data FROM users WHERE id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$user) {
                // Stale or deleted user session
                $_SESSION = [];
                if (session_id()) {
                    session_destroy();
                }
                redirect('index.php');
            }

            // Keep session parameters sanitized and fresh
            $_SESSION['user_id'] = (int) $user['id'];
            $cleanName = trim((string) ($user['fullname'] ?: $user['username']));
            $_SESSION['user_name'] = strip_tags($cleanName) ?: 'User';
            if (!empty($user['avatar_data'])) {
                $_SESSION['user_avatar'] = $user['avatar_data'];
            } elseif (!empty($user['avatar'])) {
                $_SESSION['user_avatar'] = $user['avatar'];
            }
        }
    }

    // Sanitize session language
    if (!isset($_SESSION['lang']) || !in_array($_SESSION['lang'], ['en', 'kh'], true)) {
        $_SESSION['lang'] = 'en';
    }
}

if (is_logged_in()) {
    $_SESSION['user_id'] = (int) $_SESSION['user_id'];
    if (!isset($_SESSION['lang']) || !in_array($_SESSION['lang'], ['en', 'kh'], true)) {
        $_SESSION['lang'] = 'en';
    }

    if (!empty($_COOKIE['theme_mode'])) {
        $_SESSION['theme'] = normalize_theme_mode($_COOKIE['theme_mode']);
    } else {
        $themeMode = 'dark';
        if ($conn) {
            $themeStmt = $conn->prepare('SELECT theme_mode FROM settings WHERE user_id = ? LIMIT 1');
            if ($themeStmt) {
                $themeStmt->bind_param('i', $_SESSION['user_id']);
                $themeStmt->execute();
                $themeRow = $themeStmt->get_result()->fetch_assoc();
                $themeStmt->close();

                if ($themeRow && isset($themeRow['theme_mode'])) {
                    $themeMode = normalize_theme_mode($themeRow['theme_mode']);
                }
            }
        }

        setcookie('theme_mode', $themeMode, [
            'expires' => time() + 60 * 60 * 24 * 365,
            'path' => '/',
            'samesite' => 'Lax',
        ]);
        $_SESSION['theme'] = $themeMode;
    }
} else {
    $_SESSION['theme'] = normalize_theme_mode($_COOKIE['theme_mode'] ?? ($_SESSION['theme'] ?? 'dark'));
}
