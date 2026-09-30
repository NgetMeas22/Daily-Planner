<?php
if (!isset($activePage)) {
    $activePage = 'dashboard';
}

$pageTitle = $pageTitle ?? ucfirst($activePage);
$currentLang = $_SESSION['lang'] ?? 'en';
$themeMode = function_exists('current_theme') ? current_theme() : ($_SESSION['theme'] ?? 'light');

// User session data
$userName    = $_SESSION['user_name'] ?? 'User';
$userAvatar  = $_SESSION['user_avatar'] ?? null;
$userInitial = strtoupper(substr($userName, 0, 1));
?>
<!-- Top Navigation Header (Sticky, Frosted Glass, Minimalist) -->
<header class="app-navbar" id="appNavbar">
    <div class="navbar-left">
        <!-- Mobile Drawer Hamburger Toggle Button -->
        <button type="button" class="sidebar-mobile-toggle d-md-none" id="mobileSidebarToggle" aria-label="Open Navigation Menu">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="3" y1="6" x2="21" y2="6"></line>
                <line x1="3" y1="12" x2="21" y2="12"></line>
                <line x1="3" y1="18" x2="21" y2="18"></line>
            </svg>
        </button>
    </div>

    <!-- Right Controls: Single Consolidated Dropdown Button (Pic 2 Request) -->
    <div class="navbar-right">
        <div class="nav-dropdown-wrap" id="userDropdownWrap">
            <!-- Single Profile Trigger Button -->
            <button type="button" class="nav-dropdown-trigger" id="userMenuBtn" aria-expanded="false" aria-haspopup="true">
                <?php if (!empty($userAvatar)): ?>
                    <img src="<?= htmlspecialchars($userAvatar) ?>" alt="<?= htmlspecialchars($userName) ?>" class="navbar-avatar-img">
                <?php else: ?>
                    <div class="navbar-avatar-fallback"><?= htmlspecialchars($userInitial) ?></div>
                <?php endif; ?>
                <span class="navbar-user-name"><?= htmlspecialchars($userName) ?></span>
                <svg class="dropdown-arrow-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6 9 12 15 18 9"></polyline>
                </svg>
            </button>

            <!-- Consolidated Dropdown Menu -->
            <div class="nav-dropdown-panel" id="userMenuDropdown">
                <!-- User Profile Header -->
                <div class="dropdown-header-block">
                    <div class="d-flex align-items-center gap-2">
                        <?php if (!empty($userAvatar)): ?>
                            <img src="<?= htmlspecialchars($userAvatar) ?>" alt="<?= htmlspecialchars($userName) ?>" class="navbar-avatar-img lg">
                        <?php else: ?>
                            <div class="navbar-avatar-fallback lg"><?= htmlspecialchars($userInitial) ?></div>
                        <?php endif; ?>
                        <div class="user-meta-text">
                            <div class="user-meta-name"><?= htmlspecialchars($userName) ?></div>
                        </div>
                    </div>
                </div>

                <div class="dropdown-divider-line"></div>

                <!-- Theme Mode Option (Clear Light/Dark Switch) -->
                <div class="dropdown-action-row">
                    <div class="dropdown-row-label">
                        <svg class="theme-label-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="5"></circle>
                            <path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"></path>
                        </svg>
                        <span>Theme</span>
                    </div>
                    <div class="theme-switch-pill" role="group" aria-label="Theme mode switch">
                        <button type="button" class="theme-mode-btn <?= $themeMode === 'light' ? 'active' : '' ?>" data-theme-val="light" id="themeLightBtn" title="Set Light Mode">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
                            <span>Light</span>
                        </button>
                        <button type="button" class="theme-mode-btn <?= $themeMode === 'dark' ? 'active' : '' ?>" data-theme-val="dark" id="themeDarkBtn" title="Set Dark Mode">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                            <span>Dark</span>
                        </button>
                    </div>
                </div>

                <!-- Language Option -->
                <div class="dropdown-action-row">
                    <div class="dropdown-row-label">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="2" y1="12" x2="22" y2="12"></line>
                            <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
                        </svg>
                        <span>Language</span>
                    </div>
                    <div class="lang-pill" role="group" aria-label="Language switch">
                        <a class="lang-btn <?= $currentLang === 'en' ? 'active' : '' ?>" href="?lang=en">EN</a>
                        <a class="lang-btn <?= $currentLang === 'kh' ? 'active' : '' ?>" href="?lang=kh">KH</a>
                    </div>
                </div>

                <div class="dropdown-divider-line"></div>

                <!-- Navigation Links -->
                <a href="setting.php" class="dropdown-menu-link">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3"></circle>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                    </svg>
                    <span><?= function_exists('t') ? t('settings') : 'Settings' ?></span>
                </a>

                <a href="logout.php" class="dropdown-menu-link text-danger" data-dp-confirm="<?= htmlspecialchars(t('logout_confirm_title')) ?>|<?= htmlspecialchars(t('logout_confirm_message')) ?>|<?= htmlspecialchars(t('logout')) ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                        <polyline points="16 17 21 12 16 7"></polyline>
                        <line x1="21" y1="12" x2="9" y2="12"></line>
                    </svg>
                    <span><?= function_exists('t') ? t('logout') : 'Logout' ?></span>
                </a>
            </div>
        </div>
    </div>
</header>
