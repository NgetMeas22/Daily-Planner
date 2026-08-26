<?php
if (!isset($activePage)) {
    $activePage = 'dashboard';
}
$currentLang = $_SESSION['lang'] ?? 'en';
$themeMode = $_SESSION['theme'] ?? 'light';
$csrfToken = $_SESSION['csrf_token'] ?? '';
$returnTo = current_request_path();

// Theme saving now lives in theme_toggle.php (instant fetch toggle + no-JS fallback).

// Handle Profile Picture Upload Logic (image is stored INSIDE the database as a base64 data URI)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['user_avatar_file'])) {
    $file = $_FILES['user_avatar_file'];

    if ($file['error'] === UPLOAD_ERR_OK && $file['size'] > 0 && $file['size'] <= 2 * 1024 * 1024) {
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (in_array($fileExt, $allowedExtensions) && isset($_SESSION['user_id'])) {
            $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
            $mime = $mimeMap[$fileExt] ?? (mime_content_type($file['tmp_name']) ?: 'image/jpeg');
            $dataUri = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($file['tmp_name']));

            // Update Session
            $_SESSION['user_avatar'] = $dataUri;

            // Store the image in the database
            if (isset($conn)) {
                $stmt = $conn->prepare("UPDATE users SET avatar_data = ?, avatar = NULL WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("si", $dataUri, $_SESSION['user_id']);
                    $stmt->execute();
                }
            }

            // Refresh page to prevent form resubmission
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        }
    }
}

// User session data
$userName    = $_SESSION['user_name'] ?? 'User';
$userAvatar  = $_SESSION['user_avatar'] ?? null;
$userInitial = strtoupper(substr($userName, 0, 1));
?>

<!-- Sync Bootstrap's native dark mode + load the shared theme overrides -->
<script>
  (function () {
    var t = document.body.getAttribute('data-theme') || 'light';
    try { localStorage.setItem('dp_theme', t); } catch (e) {}
    if (t === 'dark') document.documentElement.setAttribute('data-bs-theme', 'dark');
    else document.documentElement.removeAttribute('data-bs-theme');
  })();
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Khmer:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/theme.css?v=2">

<style>

  .custom-navbar {
    background: rgba(63, 140, 255, 0.95);
    backdrop-filter: blur(10px);
    transition: all 0.3s ease;
    font-family: 'Inter', 'Noto Sans Khmer', sans-serif;
  }
  .custom-navbar * {
    box-sizing: border-box;
  }
  .navbar-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.35);
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.25s ease;
    z-index: 1030;
  }
  .navbar-backdrop.show {
    opacity: 1;
    pointer-events: auto;
  }
  .custom-navbar .nav-link {
    position: relative;
    font-weight: 500;
    font-size: 0.95rem;
    transition: color 0.2s ease-in-out;
  }
  @media (min-width: 992px) {
    .custom-navbar .nav-link.active::after {
      content: '';
      position: absolute;
      bottom: 0;
      left: 0.5rem;
      right: 0.5rem;
      height: 2px;
      background-color: #ffffff;
      border-radius: 2px;
    }
  }

  .custom-navbar .navbar-collapse.show {
    visibility: visible !important;
  }

  .navbar-panel-header {
    display: none;
  }

  /* ===== Right-side controls (profile / language / logout) ===== */
  .navbar-right-controls {
    line-height: 1;
  }

  /* User Profile Badge */
  .user-profile-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 3px 12px 3px 3px;
    background: rgba(255, 255, 255, 0.15);
    border: 1px solid rgba(255, 255, 255, 0.25);
    border-radius: 50px;
    color: #ffffff;
    line-height: 1.2;
    max-width: 100%;
  }
  .user-profile-badge:hover {
    background: rgba(255, 255, 255, 0.22);
  }
  .user-profile-name {
    font-size: 0.875rem;
    font-weight: 600;
    max-width: 100px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  /* Avatar Upload */
  .avatar-upload-wrapper {
    position: relative;
    width: 28px;
    height: 28px;
    min-width: 28px;
    cursor: pointer;
    flex-shrink: 0;
    display: block;
  }
  .user-avatar-img,
  .user-avatar-fallback {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    object-fit: cover;
    border: 1px solid #ffffff;
    display: block;
  }
  .user-avatar-fallback {
    background-color: #ffffff;
    color: #2563eb;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.8rem;
    line-height: 1;
  }
  .avatar-overlay {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    opacity: 0;
    transition: opacity 0.2s ease;
  }
  .avatar-overlay svg {
    width: 12px;
    height: 12px;
  }
  .avatar-upload-wrapper:hover .avatar-overlay {
    opacity: 1;
  }

  /* Language Switcher Pill */
  .lang-switcher-pill {
    display: inline-flex;
    background: rgba(255, 255, 255, 0.15);
    border: 1px solid rgba(255, 255, 255, 0.25);
    border-radius: 50px;
    padding: 3px;
    line-height: 1;
  }
  .lang-pill-btn {
    display: inline-block;
    padding: 4px 12px;
    font-size: 0.8rem;
    font-weight: 600;
    line-height: 1.4;
    color: #ffffff;
    text-decoration: none;
    border-radius: 50px;
    transition: all 0.2s ease;
  }
  .lang-pill-btn:hover {
    color: #ffffff;
    background: rgba(255, 255, 255, 0.1);
  }
  .lang-pill-btn.active {
    background: #ffffff;
    color: #2563eb;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
  }

  /* Logout Button */
  .nav-logout-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    padding: 6px 14px;
    font-size: 0.85rem;
    font-weight: 600;
    line-height: 1.2;
    color: #ffffff;
    background: rgba(255, 255, 255, 0.15);
    border: 1px solid rgba(255, 255, 255, 0.25);
    border-radius: 50px;
    text-decoration: none;
    transition: all 0.2s ease;
    white-space: nowrap;
  }
  .nav-logout-btn svg {
    width: 15px;
    height: 15px;
    flex-shrink: 0;
  }
  .nav-logout-btn:hover {
    background: rgba(220, 38, 38, 0.85);
    border-color: rgba(220, 38, 38, 0.85);
    color: #ffffff;
  }

  @media (max-width: 991.98px) {
    .custom-navbar {
      position: sticky;
      top: 0;
      z-index: 1035;
    }
    .custom-navbar .navbar-collapse {
      position: fixed;
      top: 0;
      left: 0;
      width: min(86vw, 320px);
      height: 100vh;
      padding: 0 1.1rem 1.25rem;
      background: linear-gradient(180deg, rgba(92, 157, 255, 0.98), rgba(90, 156, 255, 0.98));
      backdrop-filter: blur(14px);
      box-shadow: 8px 0 30px rgba(15, 23, 42, 0.22);
      transform: translateX(-100%);
      transition: transform 0.28s ease;
      overflow-y: auto;
      display: block !important;
      visibility: visible !important;
      z-index: 1034;
    }
    .custom-navbar .navbar-collapse.show {
      transform: translateX(0);
    }
    .custom-navbar .navbar-nav {
      width: 100%;
    }
    .custom-navbar .nav-item {
      border-bottom: 1px solid rgba(255, 255, 255, 0.14);
    }
    .custom-navbar .nav-item:last-child {
      border-bottom: none;
    }
    .custom-navbar .nav-link {
      padding: 0.85rem 0.25rem;
      font-size: 1rem;
      color: rgba(255, 255, 255, 0.85);
    }
    .custom-navbar .nav-link.active {
      color: #ffffff;
      font-weight: 700;
    }

    .navbar-panel-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: sticky;
      top: 0;
      padding: 1rem 0 0.85rem;
      margin-bottom: 0.5rem;
      background: inherit;
      z-index: 2;
    }
    .navbar-panel-header .navbar-panel-title {
      color: #fff;
      font-weight: 700;
      font-size: 1rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    .navbar-close-btn {
      width: 34px;
      height: 34px;
      border-radius: 999px;
      border: none;
      background: rgba(255, 255, 255, 0.18);
      color: #fff;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      transition: background 0.15s ease, transform 0.15s ease;
      flex: 0 0 auto;
    }
    .navbar-close-btn:hover {
      background: rgba(255, 255, 255, 0.3);
    }
    .navbar-close-btn:active {
      transform: scale(0.92);
    }
    .navbar-panel-section-label {
      color: rgba(255, 255, 255, 0.65);
      font-size: 0.72rem;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      font-weight: 700;
      margin: 1rem 0 0.5rem;
    }

    .navbar-right-controls {
      align-items: stretch !important;
    }
    .user-profile-badge,
    .lang-switcher-pill,
    .nav-logout-btn {
      width: 100%;
      justify-content: center;
    }
    .lang-pill-btn {
      flex: 1;
      text-align: center;
    }
  }

  .loading-inline {
    pointer-events: none !important;
    opacity: 0.82 !important;
  }
  .loading-inline .loading-inline-content {
    display: inline-flex;
    align-items: center;
    gap: 8px;
  }
  .loading-inline .loading-inline-spinner {
    width: 14px;
    height: 14px;
    border-radius: 999px;
    border: 2px solid rgba(255, 255, 255, 0.5);
    border-top-color: currentColor;
    animation: pageLoadingSpin 0.8s linear infinite;
    flex: 0 0 auto;
  }
  @keyframes pageLoadingSpin {
    to { transform: rotate(360deg); }
  }
  /* Dark mode navbar overrides — all other dark styles live in assets/theme.css */
  body[data-theme="dark"] .custom-navbar {
    background: linear-gradient(135deg, rgba(15, 23, 42, 0.98), rgba(30, 41, 59, 0.96));
  }
  body[data-theme="dark"] .custom-navbar .nav-link,
  body[data-theme="dark"] .custom-navbar .navbar-brand,
  body[data-theme="dark"] .custom-navbar .nav-link.active {
    color: #f8fafc;
  }
  body[data-theme="dark"] .custom-navbar .nav-link.active::after {
    background: #f8fafc;
  }
  body[data-theme="dark"] .user-profile-badge,
  body[data-theme="dark"] .lang-switcher-pill,
  body[data-theme="dark"] .nav-logout-btn,
  body[data-theme="dark"] .theme-switch-btn {
    background: rgba(15, 23, 42, 0.45);
    border-color: rgba(148, 163, 184, 0.25);
    color: #f8fafc;
  }
  body[data-theme="dark"] .lang-pill-btn.active {
    background: #f8fafc;
    color: #0f172a;
  }
  body[data-theme="dark"] .custom-navbar .navbar-toggler-icon {
    filter: brightness(1.8);
  }

  @media (min-width: 992px) {
    .custom-navbar .navbar-collapse {
      display: flex !important;
      visibility: visible !important;
    }
  }
</style>

<nav class="navbar navbar-expand-lg navbar-dark custom-navbar sticky-top shadow-sm py-2">
    <div id="navbarBackdrop" class="navbar-backdrop d-lg-none" aria-hidden="true"></div>
    <div class="container-fluid px-lg-4">

        <!-- Brand Logo / Name -->
        <a class="navbar-brand fw-bold d-flex align-items-center gap-2 fs-5" href="dashboard.php">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="currentColor" class="bi bi-calendar-check" viewBox="0 0 16 16">
                <path d="M10.854 7.146a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708 0l-1.5-1.5a.5.5 0 1 1 .708-.708L7.5 9.793l2.646-2.647a.5.5 0 0 1 .708 0z"/>
                <path d="M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5zM1 4v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4H1z"/>
            </svg>
            <span>Daily Planner</span>
        </a>

        <!-- Mobile Toggler -->
        <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar" aria-controls="mainNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Navbar Content -->
        <div class="collapse navbar-collapse" id="mainNavbar">

            <!-- Mobile-only panel header with close (X) button -->
            <div class="navbar-panel-header d-lg-none">
                <span class="navbar-panel-title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" class="bi bi-calendar-check" viewBox="0 0 16 16">
                        <path d="M10.854 7.146a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708 0l-1.5-1.5a.5.5 0 1 1 .708-.708L7.5 9.793l2.646-2.647a.5.5 0 0 1 .708 0z"/>
                        <path d="M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5zM1 4v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4H1z"/>
                    </svg>
                    Menu
                </span>
                <button type="button" class="navbar-close-btn" id="navbarCloseBtn" aria-label="Close menu">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M2.146 2.146a.5.5 0 0 1 .708 0L8 7.293l5.146-5.147a.5.5 0 1 1 .708.708L8.707 8l5.147 5.146a.5.5 0 0 1-.708.708L8 8.707l-5.146 5.147a.5.5 0 0 1-.708-.708L7.293 8 2.146 2.854a.5.5 0 0 1 0-.708z"/>
                    </svg>
                </button>
            </div>

            <!-- Navigation Links -->
            <ul class="navbar-nav me-auto mb-3 mb-lg-0 gap-lg-1">
                <li class="nav-item">
                    <a class="nav-link px-3 <?php echo $activePage === 'dashboard' ? 'active' : ''; ?>" href="dashboard.php">
                        <?php echo t('dashboard'); ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link px-3 <?php echo $activePage === 'planner' ? 'active' : ''; ?>" href="planner.php">
                        <?php echo t('planner'); ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link px-3 <?php echo $activePage === 'subjects' ? 'active' : ''; ?>" href="subjects.php">
                        <?php echo t('subjects'); ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link px-3 <?php echo $activePage === 'goals' ? 'active' : ''; ?>" href="goals.php">
                        <?php echo t('goals'); ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link px-3 <?php echo $activePage === 'expenses' ? 'active' : ''; ?>" href="expenses.php">
                        <?php echo t('expenses'); ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link px-3 <?php echo $activePage === 'notes' ? 'active' : ''; ?>" href="notes.php">
                        <?php echo t('notes'); ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link px-3 <?php echo ($activePage === 'settings' || $activePage === 'profile') ? 'active' : ''; ?>" href="setting.php">
                        <?php echo t('settings'); ?>
                    </a>
                </li>
            </ul>

            <div class="navbar-panel-section-label d-lg-none"><?php echo t('account') ?? 'Account'; ?></div>

            <!-- Right Controls (User Profile, Language & Logout) -->
            <div class="navbar-right-controls d-flex flex-column flex-lg-row align-items-center gap-2 pt-2 pt-lg-0">

                <form method="post" action="theme_toggle.php" class="m-0" data-theme-form>
                    <input type="hidden" name="save_theme" value="1">
                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($returnTo); ?>">
                    <input type="hidden" name="theme_mode" value="<?php echo $themeMode === 'dark' ? 'light' : 'dark'; ?>">
                    <button type="submit" id="dpThemeBtn" class="btn btn-sm theme-switch-btn <?php echo $themeMode === 'dark' ? 'btn-warning' : 'btn-outline-light'; ?> fw-semibold rounded-pill d-inline-flex align-items-center gap-2">
                        <span class="dp-theme-icon"><?php echo $themeMode === 'dark' ? '<i class="bi bi-sun-fill"></i>' : '<i class="bi bi-moon-stars-fill"></i>'; ?></span>
                        <span class="dp-theme-label"><?php echo $themeMode === 'dark' ? t('light_mode') : t('dark_mode'); ?></span>
                    </button>
                </form>

                <!-- User Profile Badge with Photo Upload Form -->
                <div class="user-profile-badge">
                    <form id="avatarForm" action="" method="POST" enctype="multipart/form-data" class="m-0 p-0">
                        <label class="avatar-upload-wrapper m-0" title="Click to change profile picture">
                            <input type="file" name="user_avatar_file" accept="image/*" class="d-none" onchange="document.getElementById('avatarForm').submit();">

                            <?php if (!empty($userAvatar)): ?>
                                <img src="<?php echo htmlspecialchars($userAvatar); ?>" alt="" class="user-avatar-img">
                            <?php else: ?>
                                <div class="user-avatar-fallback"><?php echo htmlspecialchars($userInitial); ?></div>
                            <?php endif; ?>

                            <div class="avatar-overlay">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 16 16">
                                    <path d="M10.5 8.5a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0z"/>
                                    <path d="M2 4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-1.172a2 2 0 0 1-1.414-.586l-.828-.828A2 2 0 0 0 9.172 2H6.828a2 2 0 0 0-1.414.586l-.828.828A2 2 0 0 1 3.172 4H2zm.5 2a.5.5 0 1 1 0-1 .5.5 0 0 1 0 1zm9 2.5a3.5 3.5 0 1 1-7 0 3.5 3.5 0 0 1 7 0z"/>
                                </svg>
                            </div>
                        </label>
                    </form>
                    <a class="user-profile-name text-decoration-none" href="setting.php?tab=account" title="<?php echo t('profile'); ?>"><?php echo htmlspecialchars($userName); ?></a>
                </div>

                <!-- Language Switcher -->
                <div class="lang-switcher-pill" role="group" aria-label="Language switch">
                    <a class="lang-pill-btn <?php echo $currentLang === 'en' ? 'active' : ''; ?>" href="?lang=en">
                        <?php echo t('english'); ?>
                    </a>
                    <a class="lang-pill-btn <?php echo $currentLang === 'kh' ? 'active' : ''; ?>" href="?lang=kh">
                        <?php echo t('khmer'); ?>
                    </a>
                </div>

                <!-- Logout Button -->
                <a class="nav-logout-btn" href="logout.php" onclick="return confirm('Are you sure you want to log out?');">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 16 16">
                        <path fill-rule="evenodd" d="M10 12.5a.5.5 0 0 1-.5.5h-8a.5.5 0 0 1-.5-.5v-9a.5.5 0 0 1 .5-.5h8a.5.5 0 0 1 .5.5v2a.5.5 0 0 0 1 0v-2A1.5 1.5 0 0 0 8.5 2h-8A1.5 1.5 0 0 0 0 3.5v9A1.5 1.5 0 0 0 1.5 14h8a1.5 1.5 0 0 0 1.5-1.5v-2a.5.5 0 0 0-1 0v2z"/>
                        <path fill-rule="evenodd" d="M15.854 8.354a.5.5 0 0 0 0-.708l-3-3a.5.5 0 0 0-.708.708L14.293 7.5H5.5a.5.5 0 0 0 0 1h8.793l-2.147 2.146a.5.5 0 0 0 .708.708l3-3z"/>
                    </svg>
                    <span><?php echo t('logout'); ?></span>
                </a>

            </div>
        </div>
    </div>
</nav>

<script>
  /* ---------- Mobile menu bindings (re-run on every soft navigation) ---------- */
  (function () {
    const navbar = document.getElementById('mainNavbar');
    const toggler = document.querySelector('.custom-navbar .navbar-toggler');
    const backdrop = document.getElementById('navbarBackdrop');
    const closeBtn = document.getElementById('navbarCloseBtn');
    if (!navbar || !toggler) return;

    const setExpanded = (expanded) => {
      toggler.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      if (expanded) document.body.style.overflow = 'hidden';
      else if (!document.querySelector('.modal.show')) document.body.style.overflow = '';
      if (backdrop) backdrop.classList.toggle('show', expanded);
    };

    const closeNavbar = () => {
      navbar.classList.remove('show');
      setExpanded(false);
    };

    toggler.addEventListener('click', function () {
      const isOpen = navbar.classList.toggle('show');
      setExpanded(isOpen);
    });

    if (closeBtn) closeBtn.addEventListener('click', closeNavbar);

    navbar.querySelectorAll('a, button:not(#navbarCloseBtn):not(#dpThemeBtn)').forEach((el) => {
      el.addEventListener('click', function () {
        if (window.innerWidth < 992) closeNavbar();
      });
    });

    if (backdrop) backdrop.addEventListener('click', closeNavbar);

    window.addEventListener('resize', function () {
      if (window.innerWidth >= 992) {
        navbar.classList.remove('show');
        setExpanded(false);
      } else {
        setExpanded(navbar.classList.contains('show'));
      }
    });
  })();

  /* ---------- Inline loading spinner on form submits (delegated, registered once) ---------- */
  (function () {
    if (window.__dpSubmitLoading) return;
    window.__dpSubmitLoading = true;

    const setInlineLoading = (el) => {
      if (!el || el.dataset.loadingApplied === '1') return;
      el.dataset.loadingApplied = '1';
      el.classList.add('loading-inline');
      el.innerHTML = '<span class="loading-inline-content"><span class="loading-inline-spinner" aria-hidden="true"></span><span><?php echo htmlspecialchars(t('loading')); ?></span></span>';
      if (el.tagName === 'BUTTON' || el.getAttribute('role') === 'button') {
        el.disabled = true;
      }
    };

    document.addEventListener('submit', function (event) {
      if (!event.target || event.target.id === 'avatarForm') return;
      if (event.target.hasAttribute && event.target.hasAttribute('data-theme-form')) return; // instant theme toggle handles itself

      const submitter = event.submitter || event.target.querySelector('button[type="submit"], input[type="submit"]');
      setInlineLoading(submitter);
    }, true);
  })();

  /* ---------- App shell: instant theme toggle + PJAX navigation + prefetch ----------
     Registered once on the persistent `document`, so it survives body swaps. */
  (function () {
    if (window.__dpShell) return;
    window.__dpShell = true;

    var progressEl = null;
    function progressBar(show) {
      // The bar lives inside <body>, so re-create it after every body swap
      if (!progressEl || !progressEl.isConnected) {
        progressEl = document.createElement('div');
        progressEl.id = 'dpProgress';
        document.body.appendChild(progressEl);
      }
      if (show) {
        progressEl.style.transition = 'none';
        progressEl.style.width = '0';
        void progressEl.offsetWidth;
        progressEl.style.transition = 'width .25s ease, opacity .3s ease';
        progressEl.classList.add('active');
        progressEl.style.width = '72%';
      } else {
        progressEl.style.width = '100%';
        setTimeout(function () { progressEl && progressEl.classList.remove('active'); }, 220);
      }
    }

    function syncBsTheme(theme) {
      try { localStorage.setItem('dp_theme', theme); } catch (e) {}
      if (theme === 'dark') document.documentElement.setAttribute('data-bs-theme', 'dark');
      else document.documentElement.removeAttribute('data-bs-theme');
      var btn = document.getElementById('dpThemeBtn');
      if (btn) {
        var dark = theme === 'dark';
        btn.classList.toggle('btn-warning', dark);
        btn.classList.toggle('btn-outline-light', !dark);
        var icon = btn.querySelector('.dp-theme-icon');
        var label = btn.querySelector('.dp-theme-label');
        if (icon) icon.innerHTML = dark ? '<i class="bi bi-sun-fill"></i>' : '<i class="bi bi-moon-stars-fill"></i>';
        if (label) label.textContent = dark ? <?php echo json_encode(t('light_mode')); ?> : <?php echo json_encode(t('dark_mode')); ?>;
        var input = btn.closest('form') && btn.closest('form').querySelector('input[name="theme_mode"]');
        if (input) input.value = dark ? 'light' : 'dark';
      }
    }

    /* ----- Instant theme toggle: flip locally now, persist in background ----- */
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-theme-form')) return;
      e.preventDefault();

      var nextModeInput = form.querySelector('input[name="theme_mode"]');
      var next = nextModeInput ? nextModeInput.value : 'light';
      var current = document.body.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
      if (next === current) next = current === 'dark' ? 'light' : 'dark';

      document.body.setAttribute('data-theme', next);
      syncBsTheme(next);
      window.dispatchEvent(new CustomEvent('dp:themechange', { detail: { theme: next } }));

      var fd = new FormData(form);
      fd.set('theme_mode', next);
      fetch(form.action || 'theme_toggle.php', {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
        keepalive: true
      }).catch(function () {});
    }, true);

    /* ----- Prefetch internal pages on hover / touch so clicks feel instant ----- */
    var prefetched = Object.create(null);
    function prefetch(href) {
      if (!href || prefetched[href]) return;
      prefetched[href] = true;
      var l = document.createElement('link');
      l.rel = 'prefetch';
      l.href = href;
      document.head.appendChild(l);
    }
    document.addEventListener('pointerenter', function (e) {
      var a = e.target && e.target.closest ? e.target.closest('a[href$=".php"], a[href*=".php?"]') : null;
      if (a) prefetch(a.href);
    }, { capture: true, passive: true });
    document.addEventListener('touchstart', function (e) {
      var a = e.target && e.target.closest ? e.target.closest('a[href$=".php"], a[href*=".php?"]') : null;
      if (a) prefetch(a.href);
    }, { capture: true, passive: true });
    if ('requestIdleCallback' in window) {
      requestIdleCallback(function () {
        document.querySelectorAll('.custom-navbar .nav-link[href$=".php"]').forEach(function (a) { prefetch(a.href); });
      }, { timeout: 2500 });
    }

    /* ----- Soft navigation: swap <body> without a full page reload ----- */
    var navToken = 0;

    function hashKey(str) {
      var h = 5381;
      for (var i = 0; i < str.length; i++) { h = ((h << 5) + h + str.charCodeAt(i)) | 0; }
      return 'h' + (h >>> 0).toString(36) + '_' + str.length;
    }

    function headKey(node) {
      if (node.tagName === 'LINK' && node.getAttribute('rel') === 'stylesheet') return 'css:' + node.getAttribute('href');
      if (node.tagName === 'SCRIPT' && node.src) return 'js:' + node.src;
      if (node.tagName === 'STYLE') return 'style:' + hashKey(node.textContent.replace(/\s+/g, ' ').trim());
      return null;
    }

    function ensureHead(doc) {
      var loads = [];
      var seen = window.__dpHeadKeys = window.__dpHeadKeys || new Set();
      doc.head.querySelectorAll('link[rel="stylesheet"], script[src], style').forEach(function (node) {
        var key = headKey(node);
        if (!key || seen.has(key)) return;
        seen.add(key);
        var clone = document.createElement(node.tagName === 'LINK' ? 'link' : node.tagName === 'SCRIPT' ? 'script' : 'style');
        if (node.tagName === 'LINK') {
          clone.rel = 'stylesheet';
          clone.href = node.getAttribute('href');
          loads.push(new Promise(function (res) { clone.onload = res; clone.onerror = res; }));
        } else if (node.tagName === 'SCRIPT') {
          Array.prototype.forEach.call(node.attributes, function (attr) { clone.setAttribute(attr.name, attr.value); });
          loads.push(new Promise(function (res) { clone.onload = res; clone.onerror = res; }));
        } else {
          clone.textContent = node.textContent;
        }
        document.head.appendChild(clone);
      });
      return Promise.all(loads);
    }

    function execScript(old) {
      return new Promise(function (resolve) {
        var s = document.createElement('script');
        Array.prototype.forEach.call(old.attributes, function (attr) { s.setAttribute(attr.name, attr.value); });
        if (old.src) {
          s.onload = function () { resolve(); };
          s.onerror = function () { resolve(); };
          s.src = old.src;
          document.body.appendChild(s);
        } else {
          s.textContent = old.textContent;
          document.body.appendChild(s);
          resolve();
        }
      });
    }

    function cleanupAfterSwap() {
      document.body.classList.remove('modal-open');
      document.querySelectorAll('.modal-backdrop').forEach(function (b) { b.remove(); });
      document.querySelectorAll('.tooltip, .popover').forEach(function (t) { t.remove(); });
      if (window.__notesReloadTimer) { clearTimeout(window.__notesReloadTimer); window.__notesReloadTimer = null; }
      window.scrollTo(0, 0);
    }

    function shouldInterceptLink(a) {
      if (!a || a.target === '_blank' || a.hasAttribute('download')) return false;
      if (a.hasAttribute('onclick')) return false; // let confirm() handlers work
      if (a.dataset && a.dataset.bsToggle === 'modal') return false;
      var href = a.getAttribute('href');
      if (!href || href.charAt(0) === '#') return false;
      var url;
      try { url = new URL(a.href, location.href); } catch (e) { return false; }
      if (url.origin !== location.origin) return false;
      if (!/\.php($|\?)/.test(url.pathname) && !/\.php$/.test(url.pathname)) return false;
      return true;
    }

    function navigate(urlStr, push) {
      var token = ++navToken;
      progressBar(true);

      return fetch(urlStr, { headers: { 'X-DP-Nav': '1' }, credentials: 'same-origin' })
        .then(function (res) {
          if (!res.ok) throw new Error('HTTP ' + res.status);
          return res.text();
        })
        .then(function (html) {
          if (token !== navToken) return;
          var doc = new DOMParser().parseFromString(html, 'text/html');

          return ensureHead(doc).then(function () {
            if (token !== navToken) return;

            document.title = doc.title || document.title;

            var frag = document.createDocumentFragment();
            Array.prototype.slice.call(doc.body.childNodes).forEach(function (n) { frag.appendChild(document.importNode(n, true)); });

            var scripts = [];
            Array.prototype.slice.call(frag.querySelectorAll('script')).forEach(function (s) {
              scripts.push(s);
              s.parentNode.removeChild(s);
            });

            var newTheme = doc.body.getAttribute('data-theme');
            if (newTheme) document.body.setAttribute('data-theme', newTheme);
            syncBsTheme(document.body.getAttribute('data-theme') || 'light');

            document.body.replaceChildren(frag);
            cleanupAfterSwap();
            progressBar(false);

            if (push && location.href !== urlStr) history.pushState({ dp: true }, '', urlStr);

            var chain = Promise.resolve();
            scripts.forEach(function (s) {
              chain = chain.then(function () {
                if (token !== navToken) return;
                return execScript(s);
              });
            });
            return chain;
          });
        })
        .catch(function () {
          window.location.href = urlStr; // graceful fallback to a full load
        });
    }

    document.addEventListener('click', function (e) {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      var a = e.target.closest ? e.target.closest('a') : null;
      if (!shouldInterceptLink(a)) return;
      e.preventDefault();
      navigate(a.href, true);
    }, true);

    document.addEventListener('submit', function (e) {
      var f = e.target;
      if (!(f instanceof HTMLFormElement)) return;
      if ((f.method || 'get').toLowerCase() !== 'get') return; // POSTs keep normal PRG flow
      if (f.target === '_blank' || f.hasAttribute('data-no-pjax')) return;
      var url;
      try { url = new URL(f.getAttribute('action') || location.href, location.href); } catch (err) { return; }
      if (url.origin !== location.origin || !/\.php($|\?)/.test(url.pathname)) return;
      e.preventDefault();
      var params = new URLSearchParams(new FormData(f)).toString();
      navigate(url.pathname + (params ? '?' + params : ''), true);
    }, true);

    window.addEventListener('popstate', function () {
      navigate(location.href, false);
    });
  })();
</script>
