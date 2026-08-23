<?php
// Lightweight endpoint that persists the light/dark theme choice.
// Used by the navbar's instant theme toggle (fetch) and as the
// no-JS fallback form target (then it redirects back).
require_once __DIR__ . '/includes/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['theme_mode'])) {
    redirect('dashboard.php');
}

$themeMode = normalize_theme_mode($_POST['theme_mode'] ?? 'light');
$_SESSION['theme'] = $themeMode;
setcookie('theme_mode', $themeMode, [
    'expires' => time() + 60 * 60 * 24 * 365,
    'path' => '/',
    'samesite' => 'Lax',
]);

$userId = (int) $_SESSION['user_id'];
$stmt = $conn->prepare('SELECT id FROM settings WHERE user_id = ? LIMIT 1');
$stmt->bind_param('i', $userId);
$stmt->execute();
$exists = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($exists) {
    $stmt = $conn->prepare('UPDATE settings SET theme_mode = ?, updated_at = NOW() WHERE user_id = ?');
    $stmt->bind_param('si', $themeMode, $userId);
    $stmt->execute();
    $stmt->close();
} else {
    $stmt = $conn->prepare('INSERT INTO settings (user_id, theme_mode) VALUES (?, ?)');
    $stmt->bind_param('is', $userId, $themeMode);
    $stmt->execute();
    $stmt->close();
}

$isFetch = (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'fetch')
    || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
);

if ($isFetch) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'theme' => $themeMode]);
    exit;
}

redirect(safe_return_path($_POST['return_to'] ?? '', current_request_path()));
