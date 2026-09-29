<?php
if (!isset($activePage)) {
    $activePage = 'dashboard';
}

$userName    = $_SESSION['user_name'] ?? 'User';
$userAvatar  = $_SESSION['user_avatar'] ?? null;
$userInitial = strtoupper(substr($userName, 0, 1));
?>
<!-- Sidebar Navigation Rail (Supports Sleek Icon Rail & Expand/Collapse on Brand Icon Click) -->
<aside class="app-sidebar icon-rail" id="appSidebar" aria-label="Main Navigation">
    <!-- Top Daily Planner Brand Icon (Click to Open/Close Sidebar) -->
    <div class="sidebar-top">
        <button type="button" class="sidebar-brand-toggle" id="sidebarBrandToggle" title="Click to Open or Close Sidebar" aria-label="Toggle Sidebar Navigation" data-tooltip="Daily Planner (Click to Expand)">
            <div class="brand-planner-icon-box">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                    <path d="m9 16 2 2 4-4"></path>
                </svg>
            </div>
            <div class="rail-brand-meta">
                <span class="rail-brand-name">Daily Planner</span>
            </div>
        </button>
    </div>

    <!-- Navigation Icon & Label Buttons -->
    <nav class="sidebar-nav-rail">
        <!-- Dashboard -->
        <a href="dashboard.php" class="rail-nav-link <?= $activePage === 'dashboard' ? 'active' : '' ?>" data-tooltip="<?= function_exists('t') ? t('dashboard') : 'Dashboard' ?>" aria-label="Dashboard">
            <span class="rail-icon-wrap">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="7" height="9" rx="1.5"></rect>
                    <rect x="14" y="3" width="7" height="5" rx="1.5"></rect>
                    <rect x="14" y="12" width="7" height="9" rx="1.5"></rect>
                    <rect x="3" y="16" width="7" height="5" rx="1.5"></rect>
                </svg>
            </span>
            <span class="rail-label"><?= function_exists('t') ? t('dashboard') : 'Dashboard' ?></span>
        </a>

        <!-- Planner -->
        <a href="planner.php" class="rail-nav-link <?= $activePage === 'planner' ? 'active' : '' ?>" data-tooltip="<?= function_exists('t') ? t('planner') : 'Planner' ?>" aria-label="Planner">
            <span class="rail-icon-wrap">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="18" rx="2"></rect>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                    <path d="m9 16 2 2 4-4"></path>
                </svg>
            </span>
            <span class="rail-label"><?= function_exists('t') ? t('planner') : 'Planner' ?></span>
        </a>

        <!-- Notes -->
        <a href="notes.php" class="rail-nav-link <?= $activePage === 'notes' ? 'active' : '' ?>" data-tooltip="<?= function_exists('t') ? t('notes') : 'Notes' ?>" aria-label="Notes">
            <span class="rail-icon-wrap">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 20h9"></path>
                    <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                </svg>
            </span>
            <span class="rail-label"><?= function_exists('t') ? t('notes') : 'Notes' ?></span>
        </a>

        <!-- Subjects -->
        <a href="subjects.php" class="rail-nav-link <?= $activePage === 'subjects' ? 'active' : '' ?>" data-tooltip="<?= function_exists('t') ? t('subjects') : 'Subjects' ?>" aria-label="Subjects">
            <span class="rail-icon-wrap">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                    <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                </svg>
            </span>
            <span class="rail-label"><?= function_exists('t') ? t('subjects') : 'Subjects' ?></span>
        </a>

        <!-- Goals -->
        <a href="goals.php" class="rail-nav-link <?= $activePage === 'goals' ? 'active' : '' ?>" data-tooltip="<?= function_exists('t') ? t('goals') : 'Goals' ?>" aria-label="Goals">
            <span class="rail-icon-wrap">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <circle cx="12" cy="12" r="6"></circle>
                    <circle cx="12" cy="12" r="2"></circle>
                </svg>
            </span>
            <span class="rail-label"><?= function_exists('t') ? t('goals') : 'Goals' ?></span>
        </a>

        <!-- Expenses & Budget -->
        <a href="expenses.php" class="rail-nav-link <?= $activePage === 'expenses' ? 'active' : '' ?>" data-tooltip="<?= function_exists('t') ? t('expenses') : 'Expenses' ?>" aria-label="Expenses">
            <span class="rail-icon-wrap">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="4" width="20" height="16" rx="2"></rect>
                    <line x1="2" y1="10" x2="22" y2="10"></line>
                    <line x1="6" y1="15" x2="10" y2="15"></line>
                </svg>
            </span>
            <span class="rail-label"><?= function_exists('t') ? t('expenses') : 'Expenses' ?></span>
        </a>
    </nav>

    <!-- Bottom Actions: Settings, Profile & Logout -->
    <div class="sidebar-bottom">
        <!-- Settings -->
        <a href="setting.php" class="rail-nav-link <?= ($activePage === 'settings' || $activePage === 'profile') ? 'active' : '' ?>" data-tooltip="<?= function_exists('t') ? t('settings') : 'Settings' ?>" aria-label="Settings">
            <span class="rail-icon-wrap">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="3"></circle>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                </svg>
            </span>
            <span class="rail-label"><?= function_exists('t') ? t('settings') : 'Settings' ?></span>
        </a>

        <!-- User Profile Card -->
        <a href="setting.php?tab=account" class="rail-nav-link rail-user-link" data-tooltip="<?= htmlspecialchars($userName) ?> (Profile)" aria-label="<?= htmlspecialchars($userName) ?>">
            <span class="rail-icon-wrap">
                <?php if (!empty($userAvatar)): ?>
                    <img src="<?= htmlspecialchars($userAvatar) ?>" alt="<?= htmlspecialchars($userName) ?>" class="rail-avatar-img">
                <?php else: ?>
                    <div class="rail-avatar-fallback"><?= htmlspecialchars($userInitial) ?></div>
                <?php endif; ?>
            </span>
            <div class="rail-user-meta">
                <span class="rail-user-name" title="<?= htmlspecialchars($userName) ?>"><?= htmlspecialchars($userName) ?></span>
            </div>
        </a>

        <!-- Logout Button (triggers confirmation modal) -->
        <a href="logout.php" class="rail-nav-link rail-logout-btn" data-dp-confirm="<?= htmlspecialchars(t('logout_confirm_title')) ?>|<?= htmlspecialchars(t('logout_confirm_message')) ?>|<?= htmlspecialchars(t('logout')) ?>" data-tooltip="<?= function_exists('t') ? t('logout') : 'Logout' ?>" aria-label="Log Out">
            <span class="rail-icon-wrap">
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                    <polyline points="16 17 21 12 16 7"></polyline>
                    <line x1="21" y1="12" x2="9" y2="12"></line>
                </svg>
            </span>
            <span class="rail-label"><?= function_exists('t') ? t('logout') : 'Logout' ?></span>
        </a>
    </div>
</aside>
