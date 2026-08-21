<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$currentLang = $_SESSION['lang'] ?? 'en';

$userId = (int) $_SESSION['user_id'];
$errors = [];
$success = '';

function csrf_valid(): bool {
    return isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

$csrfToken = $_SESSION['csrf_token'];

// Load current user
$stmt = $conn->prepare('SELECT id, fullname, username, avatar, avatar_data FROM users WHERE id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Load (or create) the user's preferences
$stmt = $conn->prepare('SELECT deep_work, daily_reminders, focus_duration, theme_mode FROM settings WHERE user_id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$settings = $stmt->get_result()->fetch_assoc();
if (!$settings) {
    $stmt = $conn->prepare('INSERT INTO settings (user_id) VALUES (?)');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $settings = ['deep_work' => 0, 'daily_reminders' => 1, 'focus_duration' => 45, 'theme_mode' => 'light'];
}

// --- SAVE PREFERENCES ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    if (!csrf_valid()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $deepWork = isset($_POST['deep_work_mode']) ? 1 : 0;
        $reminders = isset($_POST['daily_reminders']) ? 1 : 0;
        $focus = (int) ($_POST['default_focus_duration'] ?? 45);
        $themeMode = normalize_theme_mode($_POST['theme_mode'] ?? ($settings['theme_mode'] ?? 'light'));
        if (!in_array($focus, [25, 45, 60, 90], true)) {
            $focus = 45;
        }

        $stmt = $conn->prepare('UPDATE settings SET deep_work = ?, daily_reminders = ?, focus_duration = ?, theme_mode = ?, updated_at = NOW() WHERE user_id = ?');
        $stmt->bind_param('iissi', $deepWork, $reminders, $focus, $themeMode, $userId);
        $stmt->execute();
        $_SESSION['theme'] = $themeMode;
        setcookie('theme_mode', $themeMode, [
            'expires' => time() + 60 * 60 * 24 * 365,
            'path' => '/',
            'samesite' => 'Lax',
        ]);
        redirect('setting.php');
    }
}

// --- DELETE ACCOUNT (requires the account password, then removes all data via FK cascade) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_account'])) {
    if (!csrf_valid()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (empty($_POST['delete_password'])) {
        $errors[] = 'Please enter your password to delete your account.';
    } else {
        $stmt = $conn->prepare('SELECT password FROM users WHERE id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row || !password_verify($_POST['delete_password'], $row['password'])) {
            $errors[] = 'Incorrect password. Your account was not deleted.';
        } else {
            $stmt = $conn->prepare('DELETE FROM users WHERE id = ?');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            session_unset();
            session_destroy();
            redirect('index.php');
        }
    }
}

$avatar = $user['avatar_data'] ?: $user['avatar'];
$initial = strtoupper(substr($user['fullname'] ?? 'U', 0, 1));
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('settings')); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Khmer:wght@400;500;600;700;800&display=swap');
        body { background: #f8fafc; font-family: 'Inter', sans-serif; }
        html[lang="kh"] body { font-family: 'Noto Sans Khmer', 'Inter', sans-serif; }
        .avatar-lg {
            width: 84px; height: 84px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #fff;
            box-shadow: 0 6px 18px rgba(15,23,42,.12);
            background: #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            font-weight: 800;
            color: #2563eb;
        }
        .section-title { font-size: 1rem; font-weight: 700; color: #0f172a; }
        .theme-option .btn-check:checked + .btn {
            background: #2563eb;
            color: #fff;
            border-color: #2563eb;
        }
    </style>
</head>
<body data-theme="<?php echo htmlspecialchars(current_theme()); ?>">
<?php $activePage = 'settings'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="container py-4 py-md-5" style="max-width: 780px;">
    <div class="d-flex align-items-center gap-3 mb-4">
        <h2 class="h3 fw-bold mb-0"><?php echo htmlspecialchars(t('settings')); ?></h2>
        <p class="text-muted mb-0 small">Manage your preferences and account.</p>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Account Overview -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <h6 class="section-title text-uppercase text-muted mb-3 small"><?php echo htmlspecialchars(t('account')); ?></h6>
            <div class="d-flex align-items-center gap-4 flex-wrap">
                <div class="avatar-lg">
                    <?php if ($avatar): ?>
                        <img src="<?= htmlspecialchars($avatar) ?>" alt="Profile" class="w-100 h-100 rounded-circle">
                    <?php else: ?>
                        <span><?= htmlspecialchars($initial) ?></span>
                    <?php endif; ?>
                </div>
                <div class="flex-grow-1">
                    <h4 class="fw-bold mb-1"><?= htmlspecialchars($user['fullname']) ?></h4>
                    <p class="text-muted small mb-2">@<?= htmlspecialchars($user['username']) ?></p>
                    <a class="btn btn-sm btn-outline-primary" href="profile.php"><i class="bi bi-person-gear me-1"></i><?php echo htmlspecialchars(t('edit_profile')); ?> (name, photo, password)</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Preferences -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <h6 class="section-title text-uppercase text-muted mb-3 small"><?php echo htmlspecialchars(t('preferences')); ?></h6>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="save_settings" value="1">

                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <h6 class="mb-1 fw-semibold text-dark"><?php echo htmlspecialchars(t('appearance')); ?></h6>
                            <p class="text-muted small mb-0">Choose how the interface looks across the app.</p>
                        </div>
                        <span class="badge text-bg-secondary"><?php echo htmlspecialchars(ucfirst($settings['theme_mode'] ?? 'light')); ?></span>
                    </div>
                    <div class="btn-group w-100 theme-option" role="group" aria-label="Theme selection">
                        <input type="radio" class="btn-check" name="theme_mode" id="themeLight" value="light" <?= ($settings['theme_mode'] ?? 'light') === 'light' ? 'checked' : '' ?>>
                        <label class="btn btn-outline-secondary" for="themeLight"><i class="bi bi-sun me-1"></i><?php echo htmlspecialchars(t('light_mode')); ?></label>

                        <input type="radio" class="btn-check" name="theme_mode" id="themeDark" value="dark" <?= ($settings['theme_mode'] ?? 'light') === 'dark' ? 'checked' : '' ?>>
                        <label class="btn btn-outline-secondary" for="themeDark"><i class="bi bi-moon-stars me-1"></i><?php echo htmlspecialchars(t('dark_mode')); ?></label>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h6 class="mb-1 fw-semibold text-dark"><?php echo htmlspecialchars(t('deep_work_mode')); ?></h6>
                        <p class="text-muted small mb-0">Suppress non-essential notifications during focus sessions.</p>
                    </div>
                    <div class="form-check form-switch fs-4">
                        <input class="form-check-input" type="checkbox" name="deep_work_mode" id="deepWork" <?= $settings['deep_work'] ? 'checked' : '' ?>>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h6 class="mb-1 fw-semibold text-dark"><?php echo htmlspecialchars(t('daily_study_reminders')); ?></h6>
                        <p class="text-muted small mb-0">Receive an email digest of today's schedule.</p>
                    </div>
                    <div class="form-check form-switch fs-4">
                        <input class="form-check-input" type="checkbox" name="daily_reminders" id="dailyReminders" <?= $settings['daily_reminders'] ? 'checked' : '' ?>>
                    </div>
                </div>

                <div class="mb-3" style="max-width: 320px;">
                    <label class="form-label text-secondary small fw-medium" for="focusDuration"><?php echo htmlspecialchars(t('default_focus_duration')); ?></label>
                    <select class="form-select rounded-3" name="default_focus_duration" id="focusDuration">
                        <option value="25" <?= $settings['focus_duration'] == 25 ? 'selected' : '' ?>>25 Minutes (Pomodoro)</option>
                        <option value="45" <?= $settings['focus_duration'] == 45 ? 'selected' : '' ?>>45 Minutes</option>
                        <option value="60" <?= $settings['focus_duration'] == 60 ? 'selected' : '' ?>>60 Minutes</option>
                        <option value="90" <?= $settings['focus_duration'] == 90 ? 'selected' : '' ?>>90 Minutes (Deep Session)</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary fw-bold px-4 rounded-3"><?php echo htmlspecialchars(t('save_preferences')); ?></button>
            </form>
        </div>
    </div>

    <!-- Danger Zone -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <h6 class="section-title text-uppercase text-danger mb-3 small"><?php echo htmlspecialchars(t('danger_zone')); ?></h6>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h6 class="mb-1 fw-semibold text-dark"><?php echo htmlspecialchars(t('delete_account')); ?></h6>
                    <p class="text-muted small mb-0">Enter your password to permanently delete your account and all of your data (planner, subjects, goals, expenses, notes). This cannot be undone.</p>
                </div>
                <form method="post" class="w-100" onsubmit="return confirm('Are you absolutely sure? This permanently deletes your account and ALL data. This cannot be undone!');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="delete_account" value="1">
                    <div class="d-flex flex-column flex-sm-row align-items-sm-center gap-2">
                        <input type="password" name="delete_password" class="form-control rounded-3" placeholder="<?php echo htmlspecialchars(t('enter_password')); ?>" required style="max-width: 260px;">
                        <button type="submit" class="btn btn-outline-danger btn-sm fw-semibold"><i class="bi bi-trash me-1"></i>Delete Account</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
