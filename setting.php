<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$currentLang = $_SESSION['lang'] ?? 'en';

$userId = (int) $_SESSION['user_id'];
$errors = [];

if (!function_exists('csrf_valid')) {
    function csrf_valid(): bool {
        return isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
    }
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
require_once __DIR__ . '/includes/layout.php';

$pageExtraHead = <<<'EOD'
<style>
        body { background: var(--dp-bg) !important; color: var(--dp-text) !important; font-family: 'Inter', sans-serif; }
        html[lang="kh"] body { font-family: 'Noto Sans Khmer', 'Inter', sans-serif; }
        .settings-tab { transition: all .15s ease; color: var(--dp-text-muted); }
        .settings-tab.active { background: var(--dp-primary) !important; color: #ffffff !important; }
        .card {
            background: var(--dp-surface) !important;
            border: 1px solid var(--dp-border) !important;
            color: var(--dp-text) !important;
        }
        .setting-appearance-card {
            background: var(--dp-surface-2, #181d28) !important;
            border: 1px solid var(--dp-border) !important;
        }
        .form-control, .form-select {
            background: var(--dp-surface-2, #181d28) !important;
            color: var(--dp-text) !important;
            border-color: var(--dp-border) !important;
        }
        .form-control:focus, .form-select:focus {
            background: var(--dp-surface-2, #181d28) !important;
            color: var(--dp-text) !important;
            border-color: var(--dp-primary) !important;
        }
        .avatar-xl {
            width: 110px; height: 110px;
            border-radius: 50%;
            object-fit: cover;
            background: var(--dp-elevated);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.4rem;
            font-weight: 800;
            color: var(--dp-primary);
            position: relative;
        }
        .avatar-edit-overlay {
            position: absolute;
            inset: auto 0 0 0;
            height: 34px;
            background: rgba(15, 23, 42, .65);
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
EOD;

layout_header('Settings', 'settings', $pageExtraHead);
?>

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

                    <!-- Appearance Settings (Pic 2 Design Match) -->
                    <div class="setting-appearance-card mb-4">
                        <!-- Theme Mode -->
                        <div class="setting-row mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="setting-label fw-bold">Theme Mode</label>
                                <span class="setting-mode-badge" id="currentThemeBadge"><?= ucfirst($settings['theme_mode'] ?? 'light') ?> Mode</span>
                            </div>
                            <div class="setting-segmented-switch" role="group" aria-label="Theme Mode Selection">
                                <input type="radio" class="btn-check" name="theme_mode" id="themeSystem" value="system" <?= ($settings['theme_mode'] ?? 'light') === 'system' ? 'checked' : '' ?>>
                                <label class="setting-segment-btn" for="themeSystem" onclick="setSettingTheme('system')">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                                    <span>System</span>
                                </label>

                                <input type="radio" class="btn-check" name="theme_mode" id="themeLight" value="light" <?= ($settings['theme_mode'] ?? 'light') === 'light' ? 'checked' : '' ?>>
                                <label class="setting-segment-btn" for="themeLight" onclick="setSettingTheme('light')">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
                                    <span>Light</span>
                                </label>

                                <input type="radio" class="btn-check" name="theme_mode" id="themeDark" value="dark" <?= ($settings['theme_mode'] ?? 'light') === 'dark' ? 'checked' : '' ?>>
                                <label class="setting-segment-btn" for="themeDark" onclick="setSettingTheme('dark')">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                                    <span>Dark</span>
                                </label>
                            </div>
                        </div>

                        <!-- Contrast Style -->
                        <div class="setting-row mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="setting-label fw-bold">Contrast Style</label>
                                <span class="setting-sub-info">Matte Dark (Pic 5)</span>
                            </div>
                            <div class="setting-contrast-switch">
                                <button type="button" class="contrast-btn active" id="contrastDefaultBtn" onclick="setContrast('default')">Default</button>
                                <button type="button" class="contrast-btn" id="contrastStrongBtn" onclick="setContrast('strong')">Strong</button>
                            </div>
                        </div>

                        <!-- Theme Preset -->
                        <div class="setting-row mb-4">
                            <label class="setting-label fw-bold mb-2">Theme Preset</label>
                            <div class="setting-select-wrap">
                                <select class="form-select setting-preset-select" id="themePresetSelect" onchange="changeThemePreset(this.value)">
                                    <option value="matte" selected>Matte Dark (Pic 5)</option>
                                    <option value="midnight">Midnight Navy</option>
                                    <option value="amoled">AMOLED Pitch Black</option>
                                    <option value="slate">Slate Indigo</option>
                                </select>
                            </div>
                        </div>

                        <div class="setting-divider my-4" style="height:1px;background:var(--dp-border);"></div>

                        <!-- Color Palette (Pic 2 Match) -->
                        <div class="setting-row mb-2">
                            <div class="setting-palette-head mb-3 d-flex align-items-center">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="var(--dp-primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-2"><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.9 0 1.6-.7 1.6-1.6 0-.4-.2-.8-.4-1.1-.3-.3-.4-.7-.4-1.1 0-.9.7-1.6 1.6-1.6H16c3.3 0 6-2.7 6-6 0-5.5-4.5-10-10-10z"/><circle cx="7.5" cy="11.5" r="1.5"/><circle cx="12" cy="7.5" r="1.5"/><circle cx="16.5" cy="11.5" r="1.5"/></svg>
                                <span class="fw-bold">Color Palette</span>
                            </div>

                            <div class="palette-field-group mb-2">
                                <div class="palette-input-row">
                                    <span class="palette-input-label">Background</span>
                                    <div class="palette-color-display">
                                        <div class="palette-color-box" id="paletteBgBox" style="background:var(--dp-bg);"></div>
                                        <span class="palette-hex-code" id="paletteBgHex">#0D0F12</span>
                                    </div>
                                </div>
                            </div>

                            <div class="palette-field-group mb-2">
                                <div class="palette-input-row">
                                    <span class="palette-input-label">Text Content</span>
                                    <div class="palette-color-display">
                                        <div class="palette-color-box" id="paletteTextBox" style="background:var(--dp-text);"></div>
                                        <span class="palette-hex-code" id="paletteTextHex">#F8FAFC</span>
                                    </div>
                                </div>
                            </div>

                            <div class="palette-field-group mb-3">
                                <div class="palette-input-row">
                                    <span class="palette-input-label">Accent Focus</span>
                                    <div class="palette-color-display">
                                        <div class="palette-color-box" id="paletteAccentBox" style="background:var(--dp-primary);"></div>
                                        <span class="palette-hex-code" id="paletteAccentHex">#6366F1</span>
                                    </div>
                                </div>
                            </div>

                            <div class="palette-swatches-row">
                                <span class="palette-swatches-label">Presets:</span>
                                <div class="palette-dots">
                                    <button type="button" class="swatch-dot" style="background:#3b82f6;" title="Blue" onclick="applyAccentColor('#3b82f6')"></button>
                                    <button type="button" class="swatch-dot active" style="background:#6366f1;" title="Indigo (Pic 5)" onclick="applyAccentColor('#6366f1')"></button>
                                    <button type="button" class="swatch-dot" style="background:#0ea5e9;" title="Sky" onclick="applyAccentColor('#0ea5e9')"></button>
                                    <button type="button" class="swatch-dot" style="background:#10b981;" title="Emerald" onclick="applyAccentColor('#10b981')"></button>
                                    <button type="button" class="swatch-dot" style="background:#8b5cf6;" title="Purple" onclick="applyAccentColor('#8b5cf6')"></button>
                                    <button type="button" class="swatch-dot" style="background:#f59e0b;" title="Amber" onclick="applyAccentColor('#f59e0b')"></button>
                                </div>
                            </div>
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

                    <button type="submit" class="btn btn-primary fw-bold px-4 rounded-3" data-loading-text="Saving Preferences..."><?php echo htmlspecialchars(t('save_preferences')); ?></button>
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
                        <h6 class="mb-1 fw-semibold text-danger"><?php echo htmlspecialchars(t('delete_account')); ?></h6>
                        <p class="text-muted small mb-0">Enter your password to permanently delete your account and all of your data (planner, subjects, goals, expenses, notes). This cannot be undone.</p>
                    </div>
                    <form method="post" class="w-100" onsubmit="return confirm('Are you absolutely sure? This permanently deletes your account and ALL data. This cannot be undone!');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="delete_account" value="1">
                        <div class="d-flex flex-column flex-sm-row align-items-sm-center gap-2">
                            <input type="password" name="delete_password" class="form-control rounded-3" placeholder="<?php echo htmlspecialchars(t('enter_password')); ?>" required style="max-width: 260px;">
                            <button type="submit" class="btn btn-outline-danger btn-sm fw-semibold" data-loading-text="Deleting..."><i class="bi bi-trash me-1"></i>Delete Account</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    <?php endif; ?>
</div>

<script>
function setSettingTheme(mode) {
    var effective = mode;
    if (mode === 'system') {
        effective = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
    }
    document.documentElement.setAttribute('data-theme', effective);
    if (document.body) document.body.setAttribute('data-theme', effective);
    if (effective === 'dark') {
        document.documentElement.setAttribute('data-bs-theme', 'dark');
        if (document.body) document.body.setAttribute('data-bs-theme', 'dark');
    } else {
        document.documentElement.removeAttribute('data-bs-theme');
        if (document.body) document.body.removeAttribute('data-bs-theme');
    }
    try {
        localStorage.setItem('dp_theme', mode);
    } catch(e) {}
    document.cookie = 'theme_mode=' + encodeURIComponent(mode) + '; path=/; max-age=31536000; SameSite=Lax';

    ['themeSystem', 'themeLight', 'themeDark'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.checked = (el.value === mode);
    });

    var badge = document.getElementById('currentThemeBadge');
    if (badge) badge.textContent = mode.charAt(0).toUpperCase() + mode.slice(1) + ' Mode';
    updatePaletteDisplay(effective);

    var fd = new FormData();
    fd.append('theme_mode', mode);
    fetch('theme_toggle.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } }).catch(function(){});
}

function updatePaletteDisplay(effectiveTheme) {
    var bgBox = document.getElementById('paletteBgBox');
    var bgHex = document.getElementById('paletteBgHex');
    var textBox = document.getElementById('paletteTextBox');
    var textHex = document.getElementById('paletteTextHex');
    var isDark = effectiveTheme === 'dark';
    if (!isDark) {
        if (bgBox) bgBox.style.background = '#f8fafc';
        if (bgHex) bgHex.textContent = '#F8FAFC';
        if (textBox) textBox.style.background = '#0f172a';
        if (textHex) textHex.textContent = '#0F172A';
    } else {
        if (bgBox) bgBox.style.background = '#0d0f12';
        if (bgHex) bgHex.textContent = '#0D0F12';
        if (textBox) textBox.style.background = '#f8fafc';
        if (textHex) textHex.textContent = '#F8FAFC';
    }
}

function setContrast(type) {
    var defBtn = document.getElementById('contrastDefaultBtn');
    var strBtn = document.getElementById('contrastStrongBtn');
    if (defBtn) defBtn.classList.toggle('active', type === 'default');
    if (strBtn) strBtn.classList.toggle('active', type === 'strong');
    try {
        localStorage.setItem('dp_contrast', type);
    } catch(e) {}
    document.cookie = 'dp_contrast=' + encodeURIComponent(type) + '; path=/; max-age=31536000; SameSite=Lax';
    if (type === 'strong') {
        document.documentElement.style.setProperty('--dp-border', 'rgba(255, 255, 255, 0.28)');
    } else {
        document.documentElement.style.removeProperty('--dp-border');
    }
}

function changeThemePreset(preset) {
    try {
        localStorage.setItem('dp_preset', preset);
    } catch(e) {}
    document.cookie = 'dp_preset=' + encodeURIComponent(preset) + '; path=/; max-age=31536000; SameSite=Lax';
    if (preset === 'midnight') {
        applyAccentColor('#3b82f6');
        document.documentElement.style.setProperty('--dp-bg', '#0b1120');
        document.documentElement.style.setProperty('--dp-surface', '#111827');
    } else if (preset === 'amoled') {
        applyAccentColor('#6366f1');
        document.documentElement.style.setProperty('--dp-bg', '#000000');
        document.documentElement.style.setProperty('--dp-surface', '#0a0a0a');
    } else if (preset === 'slate') {
        applyAccentColor('#8b5cf6');
        document.documentElement.style.setProperty('--dp-bg', '#0f172a');
        document.documentElement.style.setProperty('--dp-surface', '#1e293b');
    } else {
        applyAccentColor('#6366f1');
        document.documentElement.style.removeProperty('--dp-bg');
        document.documentElement.style.removeProperty('--dp-surface');
    }
    updatePaletteDisplay(document.documentElement.getAttribute('data-theme') || 'dark');
}

function applyAccentColor(hex) {
    var accBox = document.getElementById('paletteAccentBox');
    var accHex = document.getElementById('paletteAccentHex');
    if (accBox) accBox.style.background = hex;
    if (accHex) accHex.textContent = hex.toUpperCase();
    document.documentElement.style.setProperty('--dp-primary', hex);
    try {
        localStorage.setItem('dp_accent', hex);
    } catch(e) {}
    document.cookie = 'dp_accent=' + encodeURIComponent(hex) + '; path=/; max-age=31536000; SameSite=Lax';
    document.querySelectorAll('.swatch-dot').forEach(function(d) {
        var bg = d.style.background || '';
        d.classList.toggle('active', bg.toLowerCase().indexOf(hex.toLowerCase()) !== -1);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    var storedTheme = localStorage.getItem('dp_theme') || document.documentElement.getAttribute('data-theme') || 'dark';
    var effective = storedTheme === 'system' ? ((window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light') : storedTheme;
    updatePaletteDisplay(effective);
    var badge = document.getElementById('currentThemeBadge');
    if (badge) badge.textContent = storedTheme.charAt(0).toUpperCase() + storedTheme.slice(1) + ' Mode';

    var storedPreset = localStorage.getItem('dp_preset');
    if (storedPreset) {
        var presetSel = document.getElementById('themePresetSelect');
        if (presetSel) presetSel.value = storedPreset;
    }
    var storedContrast = localStorage.getItem('dp_contrast');
    if (storedContrast) {
        setContrast(storedContrast);
    }
    var storedAccent = localStorage.getItem('dp_accent');
    if (storedAccent) {
        applyAccentColor(storedAccent);
    }
});
</script>

<?php layout_footer(); ?>
