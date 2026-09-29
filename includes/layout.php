<?php
/**
 * DAILY PLANNER — GLOBAL LAYOUT SYSTEM
 * Reusable master layout wrapping Sidebar, Navbar, and Main Content.
 */

if (!defined('DP_LAYOUT_LOADED')) {
    define('DP_LAYOUT_LOADED', true);
}

// Handle Profile Picture Upload BEFORE any output is sent (avoids "headers already sent")
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['user_avatar_file'])) {
    $file = $_FILES['user_avatar_file'];

    if ($file['error'] === UPLOAD_ERR_OK && $file['size'] > 0 && $file['size'] <= 2 * 1024 * 1024) {
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (in_array($fileExt, $allowedExtensions) && isset($_SESSION['user_id'])) {
            global $conn;
            $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
            $mime = $mimeMap[$fileExt] ?? (mime_content_type($file['tmp_name']) ?: 'image/jpeg');
            $dataUri = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($file['tmp_name']));

            $_SESSION['user_avatar'] = $dataUri;

            if (isset($conn)) {
                $stmt = $conn->prepare("UPDATE users SET avatar_data = ?, avatar = NULL WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("si", $dataUri, $_SESSION['user_id']);
                    $stmt->execute();
                }
            }

            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        }
    }
}

if (!function_exists('layout_header')) {
    function layout_header(string $pageTitle = 'Dashboard', string $activePage = 'dashboard', string $extraHead = ''): void
    {
        $currentLang = $_SESSION['lang'] ?? 'en';
        $themeMode   = function_exists('current_theme') ? current_theme() : ($_SESSION['theme'] ?? 'dark');
        ?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang) ?>" data-theme="<?= htmlspecialchars($themeMode) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title><?= htmlspecialchars($pageTitle) ?> — Daily Planner</title>
    
    <!-- Prevent theme flash (FOUC), restore accent, preset and sidebar state -->
    <script>
        (function() {
            var t = '<?= $themeMode ?>';
            try {
                var stored = localStorage.getItem('dp_theme');
                if (stored === 'dark' || stored === 'light' || stored === 'system') t = stored;
                var acc = localStorage.getItem('dp_accent');
                if (acc) document.documentElement.style.setProperty('--dp-primary', acc);
                var preset = localStorage.getItem('dp_preset');
                if (preset === 'midnight') {
                    document.documentElement.style.setProperty('--dp-bg', '#0b1120');
                    document.documentElement.style.setProperty('--dp-surface', '#111827');
                } else if (preset === 'amoled') {
                    document.documentElement.style.setProperty('--dp-bg', '#000000');
                    document.documentElement.style.setProperty('--dp-surface', '#0a0a0a');
                } else if (preset === 'slate') {
                    document.documentElement.style.setProperty('--dp-bg', '#0f172a');
                    document.documentElement.style.setProperty('--dp-surface', '#1e293b');
                }
            } catch(e) {}
            if (t === 'system') {
                t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
            }
            document.documentElement.setAttribute('data-theme', t);
            if (t === 'dark') {
                document.documentElement.setAttribute('data-bs-theme', 'dark');
            } else {
                document.documentElement.removeAttribute('data-bs-theme');
            }

            try {
                var sb = localStorage.getItem('dp_sidebar_state');
                if (sb === 'expanded' && window.innerWidth >= 768) {
                    document.documentElement.classList.add('sidebar-is-expanded');
                }
            } catch(e) {}
        })();
    </script>

    <!-- Bootstrap 5 CSS & Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Khmer:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Shared Theme Stylesheet & Core Design System -->
    <link rel="stylesheet" href="assets/theme.css?v=<?= filemtime(__DIR__ . '/../assets/theme.css') ?>">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    
    <?= $extraHead ?>
</head>
<body data-theme="<?= htmlspecialchars($themeMode) ?>" <?= $themeMode === 'dark' ? 'data-bs-theme="dark"' : '' ?>>
<!-- Global Top Page Loading Progress Bar -->
<div class="dp-page-loader" id="dpPageLoader"></div>
<script>
    (function() {
        var t = document.documentElement.getAttribute('data-theme');
        if (t) {
            document.body.setAttribute('data-theme', t);
            if (t === 'dark') document.body.setAttribute('data-bs-theme', 'dark');
            else document.body.removeAttribute('data-bs-theme');
        }
    })();
</script>

<!-- Mobile Drawer Backdrop Overlay -->
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="app-layout" id="appLayout">
    <!-- Sidebar Navigation -->
    <?php require __DIR__ . '/sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="app-main">
        <!-- Top Navbar -->
        <?php require __DIR__ . '/navbar.php'; ?>

        <!-- Dynamic Content Injected Below -->
        <main class="main-content">
        <?php
    }
}

if (!function_exists('layout_footer')) {
    function layout_footer(string $extraScripts = ''): void
    {
        ?>
        </main>
    </div>
</div>

    <!-- Global Confirmation Modal (replaces native confirm() dialogs) -->
    <div class="modal fade" id="dpConfirmModal" tabindex="-1" aria-labelledby="dpConfirmTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered dp-confirm-dialog">
            <div class="modal-content dp-confirm-card">
                <div class="dp-confirm-body">
                    <div class="dp-confirm-icon" id="dpConfirmIcon" aria-hidden="true">
                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                            <polyline points="16 17 21 12 16 7"></polyline>
                            <line x1="21" y1="12" x2="9" y2="12"></line>
                        </svg>
                    </div>
                    <h5 class="dp-confirm-title" id="dpConfirmTitle"><?= htmlspecialchars(function_exists('t') ? t('logout_confirm_title') : 'Log out of Daily Planner?') ?></h5>
                    <p class="dp-confirm-text" id="dpConfirmText"><?= htmlspecialchars(function_exists('t') ? t('logout_confirm_message') : 'You will be signed out and need to log in again.') ?></p>
                </div>
                <div class="dp-confirm-actions">
                    <button type="button" class="dp-confirm-btn dp-confirm-cancel" id="dpConfirmCancel" data-bs-dismiss="modal"><?= htmlspecialchars(function_exists('t') ? t('stay_signed_in') : 'Stay signed in') ?></button>
                    <button type="button" class="dp-confirm-btn dp-confirm-accept" id="dpConfirmAccept"><?= htmlspecialchars(function_exists('t') ? t('logout') : 'Logout') ?></button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 Bundle JS (for Modals, Dropdowns, Tooltips) -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Core Application Script -->
<script src="assets/js/script.js?v=<?= filemtime(__DIR__ . '/../assets/js/script.js') ?>"></script>
<?= $extraScripts ?>

</body>
</html>
        <?php
    }
}

if (!function_exists('render_layout')) {
    function render_layout(string $content, string $pageTitle = 'Dashboard', string $activePage = 'dashboard', string $extraHead = '', string $extraScripts = ''): void
    {
        layout_header($pageTitle, $activePage, $extraHead);
        echo $content;
        layout_footer($extraScripts);
    }
}

