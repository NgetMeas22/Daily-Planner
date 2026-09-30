<?php
// Lightweight endpoint that persists theme, preset, colors, and contrast choices.
// Used by the navbar's instant theme toggle (fetch) and settings live update.
require_once __DIR__ . '/includes/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('dashboard.php');
}

$userId = (int) $_SESSION['user_id'];

// Fetch current user settings
$stmt = $conn->prepare('SELECT id, theme_mode, theme_preset, accent_color, contrast_style, dark_bg_color, dark_fg_color FROM settings WHERE user_id = ? LIMIT 1');
$stmt->bind_param('i', $userId);
$stmt->execute();
$current = $stmt->get_result()->fetch_assoc();
$stmt->close();

$themeMode     = isset($_POST['theme_mode']) ? normalize_theme_mode($_POST['theme_mode']) : ($current['theme_mode'] ?? 'dark');
$themePreset   = isset($_POST['theme_preset']) ? trim($_POST['theme_preset']) : ($current['theme_preset'] ?? 'default_dark');
$accentColor   = isset($_POST['accent_color']) ? trim($_POST['accent_color']) : ($current['accent_color'] ?? '#007acc');
$contrastStyle = isset($_POST['contrast_style']) ? trim($_POST['contrast_style']) : ($current['contrast_style'] ?? 'default');
$darkBgColor   = isset($_POST['dark_bg_color']) ? trim($_POST['dark_bg_color']) : ($current['dark_bg_color'] ?? '#101010');
$darkFgColor   = isset($_POST['dark_fg_color']) ? trim($_POST['dark_fg_color']) : ($current['dark_fg_color'] ?? '#cccccc');

// Validate hex codes
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accentColor)) {
    $accentColor = '#007acc';
}
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $darkBgColor)) {
    $darkBgColor = '#101010';
}
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $darkFgColor)) {
    $darkFgColor = '#cccccc';
}
if (!in_array($contrastStyle, ['default', 'strong'], true)) {
    $contrastStyle = 'default';
}

$cookieOpts = [
    'expires'  => time() + 60 * 60 * 24 * 365,
    'path'     => '/',
    'samesite' => 'Lax',
];

$_SESSION['theme'] = $themeMode;
setcookie('theme_mode', $themeMode, $cookieOpts);
setcookie('dp_preset', $themePreset, $cookieOpts);
setcookie('dp_accent', $accentColor, $cookieOpts);
setcookie('dp_contrast', $contrastStyle, $cookieOpts);
setcookie('dp_bg', $darkBgColor, $cookieOpts);
setcookie('dp_fg', $darkFgColor, $cookieOpts);

if ($current) {
    $stmt = $conn->prepare('UPDATE settings SET theme_mode = ?, theme_preset = ?, accent_color = ?, contrast_style = ?, dark_bg_color = ?, dark_fg_color = ?, updated_at = NOW() WHERE user_id = ?');
    $stmt->bind_param('ssssssi', $themeMode, $themePreset, $accentColor, $contrastStyle, $darkBgColor, $darkFgColor, $userId);
    $stmt->execute();
    $stmt->close();
} else {
    $stmt = $conn->prepare('INSERT INTO settings (user_id, theme_mode, theme_preset, accent_color, contrast_style, dark_bg_color, dark_fg_color) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('issssss', $userId, $themeMode, $themePreset, $accentColor, $contrastStyle, $darkBgColor, $darkFgColor);
    $stmt->execute();
    $stmt->close();
}

$isFetch = (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'fetch')
    || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
    || isset($_POST['ajax'])
);

if ($isFetch) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'             => true,
        'theme'          => $themeMode,
        'preset'         => $themePreset,
        'accent'         => $accentColor,
        'contrast'       => $contrastStyle,
        'dark_bg_color'  => $darkBgColor,
        'dark_fg_color'  => $darkFgColor,
    ]);
    exit;
}

redirect(safe_return_path($_POST['return_to'] ?? '', current_request_path()));
