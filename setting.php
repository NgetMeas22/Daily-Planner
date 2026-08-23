<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$currentLang = $_SESSION['lang'] ?? 'en';

$userId = (int) $_SESSION['user_id'];
$errors = [];

function csrf_valid(): bool {
    return isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

$csrfToken = $_SESSION['csrf_token'];

// Active tab (account | preferences | danger)
$activeTab = $_GET['tab'] ?? 'account';
if (!in_array($activeTab, ['account', 'preferences', 'danger'], true)) {
    $activeTab = 'account';
}

// Flash message (set before a PRG redirect)
$flashSuccess = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);

function settings_flash(string $message, string $type = 'success'): void {
    $_SESSION[$type === 'success' ? 'flash_success' : 'flash_error'] = $message;
}

// Load current user
$stmt = $conn->prepare('SELECT id, fullname, username, avatar, avatar_data, created_at FROM users WHERE id = ?');
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
        settings_flash('Preferences saved.');
        redirect('setting.php?tab=preferences');
    }
}

// --- UPDATE PROFILE NAME (merged from profile.php) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    if (!csrf_valid()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $fullname = trim($_POST['fullname'] ?? '');
        if ($fullname === '') {
            $errors[] = 'Full name is required.';
        } else {
            $stmt = $conn->prepare('UPDATE users SET fullname = ? WHERE id = ?');
            $stmt->bind_param('si', $fullname, $userId);
            $stmt->execute();
            $_SESSION['user_name'] = $fullname;
            settings_flash('Profile updated.');
            redirect('setting.php?tab=account');
        }
    }
}

// --- CHANGE PASSWORD (merged from profile.php) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    if (!csrf_valid()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $current = $_POST['current_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $pwStmt = $conn->prepare('SELECT password FROM users WHERE id = ?');
        $pwStmt->bind_param('i', $userId);
        $pwStmt->execute();
        $pwRow = $pwStmt->get_result()->fetch_assoc();

        if (!$pwRow || !password_verify($current, $pwRow['password'])) {
            $errors[] = 'Current password is incorrect.';
        } elseif (strlen($newPass) < 6) {
            $errors[] = 'New password must be at least 6 characters.';
        } elseif ($newPass !== $confirm) {
            $errors[] = 'New passwords do not match.';
        } else {
            $hashed = password_hash($newPass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
            $stmt->bind_param('si', $hashed, $userId);
            $stmt->execute();
            settings_flash('Password changed.');
            redirect('setting.php?tab=account');
        }
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

// Keep errors visible across the redirect-back flows
if ($errors) {
    $_SESSION['flash_error'] = implode(' ', $errors);
    $tabForErrors = isset($_POST['save_settings']) ? 'preferences'
        : (isset($_POST['delete_account']) ? 'danger' : 'account');
    redirect('setting.php?tab=' . $tabForErrors);
}
if ($flashError) {
    $errors[] = $flashError;
}

$avatar = $user['avatar_data'] ?: $user['avatar'];
$initial = strtoupper(substr($user['fullname'] ?? 'U', 0, 1));
$memberSince = date('M Y', strtotime($user['created_at'] ?? 'now'));

function settings_tab_link(string $tab, string $label, string $icon, string $active): void
{
    ?>
    <a class="btn btn-sm rounded-pill px-3 fw-semibold settings-tab <?= $active === $tab ? 'active bg-primary text-white' : 'btn-outline-secondary border-0' ?>"
       href="setting.php?tab=<?= htmlspecialchars($tab) ?>">
        <i class="bi <?= $icon ?> me-1"></i><?= htmlspecialchars($label) ?>
    </a>
    <?php
}
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
        .settings-tab { transition: all .15s ease; }
        .avatar-xl {
            width: 110px; height: 110px;
            border-radius: 50%;
            object-fit: cover;
            background: #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.4rem;
            font-weight: 800;
            color: #2563eb;
            position: relative;
        }
        .avatar-edit-overlay {
            position: absolute;
            inset: auto 0 0 0;
            height: 34px;
            background: rgba(15, 23, 42, .55);
            color: #fff;
            border-radius: 0 0 999px 999px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background .15s ease;
        }
        .avatar-edit-wrap:hover .avatar-edit-overlay { background: rgba(37, 99, 235, .75); }
    </style>
</head>
<body data-theme="<?php echo htmlspecialchars(current_theme()); ?>">
<?php $activePage = 'settings'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="container py-4 py-md-5" style="max-width: 780px;">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h2 class="h3 fw-bold mb-1"><?php echo htmlspecialchars(t('settings')); ?></h2>
            <p class="text-muted mb-0 small">Manage your profile, preferences and account.</p>
        </div>
        <span class="badge bg-light text-dark border px-3 py-2 rounded-pill">
            <i class="bi bi-calendar-check me-1"></i><?= htmlspecialchars(t('member_since')) ?> <?= htmlspecialchars($memberSince) ?>
        </span>
    </div>

    <!-- Tabs -->
    <div class="d-flex gap-1 flex-wrap mb-4 pb-2 border-bottom">
        <?php
        settings_tab_link('account', t('profile'), 'bi-person', $activeTab);
        settings_tab_link('preferences', t('preferences'), 'bi-sliders', $activeTab);
        settings_tab_link('danger', t('danger_zone'), 'bi-exclamation-triangle', $activeTab);
        ?>
    </div>

    <?php if ($flashSuccess): ?>
        <div class="alert alert-success py-2"><?= htmlspecialchars($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="alert alert-danger py-2"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
    <?php endif; ?>

    <?php if ($activeTab === 'account'): ?>

        <!-- ======== ACCOUNT TAB (merged Profile page) ======== -->
        <!-- Avatar + identity -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4 d-flex align-items-center gap-4 flex-wrap">
                <form id="dpAvatarForm" method="post" enctype="multipart/form-data" class="m-0">
                    <label class="avatar-xl avatar-edit-wrap d-block position-relative">
                        <?php if ($avatar): ?>
                            <img src="<?= htmlspecialchars($avatar) ?>" alt="Profile" class="w-100 h-100 rounded-circle" style="object-fit:cover;">
                        <?php else: ?>
                            <span><?= htmlspecialchars($initial) ?></span>
                        <?php endif; ?>
                        <span class="avatar-edit-overlay"><i class="bi bi-camera-fill"></i></span>
                        <input type="file" name="user_avatar_file" accept="image/*" class="d-none"
                               onchange="document.getElementById('dpAvatarForm').submit();">
                    </label>
                </form>
                <div class="flex-grow-1">
                    <h4 class="fw-bold mb-1"><?= htmlspecialchars($user['fullname']) ?></h4>
                    <p class="text-muted small mb-1">@<?= htmlspecialchars($user['username']) ?></p>
                    <p class="small text-secondary mb-0"><i class="bi bi-image me-1"></i>Click the photo to upload a new one (max 2 MB).</p>
                </div>
            </div>
        </div>

        <!-- Edit name -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4">
                <h6 class="section-title text-uppercase text-muted mb-3 small"><?php echo htmlspecialchars(t('edit_profile')); ?></h6>
                <form method="post" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="save_profile" value="1">
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-semibold text-secondary"><?php echo htmlspecialchars(t('full_name')); ?></label>
                        <input class="form-control rounded-3" name="fullname" value="<?= htmlspecialchars($user['fullname']) ?>" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-semibold text-secondary"><?php echo htmlspecialchars(t('username')); ?></label>
                        <input class="form-control rounded-3" value="<?= htmlspecialchars($user['username']) ?>" disabled>
                        <div class="form-text">Username cannot be changed.</div>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary rounded-3 fw-medium px-4"><?php echo htmlspecialchars(t('save')); ?></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Change password -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4">
                <h6 class="section-title text-uppercase text-muted mb-3 small"><?= htmlspecialchars(t('security')) ?> · <?= htmlspecialchars(t('password')) ?></h6>
                <form method="post" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="change_password" value="1">
                    <div class="col-12 col-md-4">
                        <label class="form-label small fw-semibold text-secondary">Current Password</label>
                        <input type="password" class="form-control rounded-3" name="current_password" placeholder="••••••••" required>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label small fw-semibold text-secondary">New Password</label>
                        <input type="password" class="form-control rounded-3" name="new_password" placeholder="At least 6 characters" required minlength="6">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label small fw-semibold text-secondary">Confirm New Password</label>
                        <input type="password" class="form-control rounded-3" name="confirm_password" placeholder="Repeat new password" required>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary rounded-3 fw-medium px-4"><i class="bi bi-shield-lock me-1"></i>Change Password</button>
                    </div>
                </form>
            </div>
        </div>

    <?php elseif ($activeTab === 'preferences'): ?>

        <!-- ======== PREFERENCES TAB ======== -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4">
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

    <?php else: ?>

        <!-- ======== DANGER ZONE TAB ======== -->
        <div class="card border-0 shadow-sm rounded-4 mb-4" style="border-top: 3px solid #dc3545 !important;">
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

    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
