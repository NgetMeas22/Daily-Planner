<?php
if (!isset($activePage)) {
    $activePage = 'dashboard';
}
$currentLang = $_SESSION['lang'] ?? 'en';

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

<style>
  @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Khmer:wght@400;500;600;700;800&display=swap');

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
                    <a class="nav-link px-3 <?php echo $activePage === 'profile' ? 'active' : ''; ?>" href="profile.php">
                        <?php echo t('profile'); ?>
                    </a>
                </li>
            </ul>

            <div class="navbar-panel-section-label d-lg-none"><?php echo t('account') ?? 'Account'; ?></div>

            <!-- Right Controls (User Profile, Language & Logout) -->
            <div class="navbar-right-controls d-flex flex-column flex-lg-row align-items-center gap-2 pt-2 pt-lg-0">

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
                    <a class="user-profile-name text-decoration-none" href="profile.php" title="<?php echo t('profile'); ?>"><?php echo htmlspecialchars($userName); ?></a>
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
                <a class="nav-logout-btn" href="logout.php">
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
  (function () {
    const navbar = document.getElementById('mainNavbar');
    const toggler = document.querySelector('.custom-navbar .navbar-toggler');
    const backdrop = document.getElementById('navbarBackdrop');
    const closeBtn = document.getElementById('navbarCloseBtn');
    if (!navbar || !toggler) return;

    const setExpanded = (expanded) => {
      toggler.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      document.body.style.overflow = expanded ? 'hidden' : '';
      if (backdrop) {
        backdrop.classList.toggle('show', expanded);
      }
    };

    const closeNavbar = () => {
      navbar.classList.remove('show');
      setExpanded(false);
    };

    toggler.addEventListener('click', function () {
      const isOpen = navbar.classList.toggle('show');
      setExpanded(isOpen);
    });

    if (closeBtn) {
      closeBtn.addEventListener('click', closeNavbar);
    }

    navbar.querySelectorAll('a, button:not(#navbarCloseBtn)').forEach((el) => {
      el.addEventListener('click', function () {
        if (window.innerWidth < 992) closeNavbar();
      });
    });

    if (backdrop) {
      backdrop.addEventListener('click', closeNavbar);
    }

    window.addEventListener('resize', function () {
      if (window.innerWidth >= 992) {
        navbar.classList.remove('show');
        setExpanded(false);
      } else {
        setExpanded(navbar.classList.contains('show'));
      }
    });

    const setInlineLoading = (el) => {
      if (!el || el.dataset.loadingApplied === '1') return;
      el.dataset.loadingApplied = '1';
      el.dataset.originalHtml = el.innerHTML;
      el.classList.add('loading-inline');
      el.innerHTML = '<span class="loading-inline-content"><span class="loading-inline-spinner" aria-hidden="true"></span><span>Loading...</span></span>';
      if (el.tagName === 'BUTTON' || el.getAttribute('role') === 'button') {
        el.disabled = true;
      }
    };

    document.addEventListener('submit', function (event) {
      // Ignore avatar upload submit so inline loading doesn't freeze the avatar preview
      if (event.target && event.target.id === 'avatarForm') return;

      const submitter = event.submitter || event.target.querySelector('button[type="submit"], input[type="submit"]');
      setInlineLoading(submitter);
    }, true);

  })();
</script>