<?php
if (!isset($activePage)) {
    $activePage = 'dashboard';
}
$currentLang = $_SESSION['lang'] ?? 'en';
?>

<style>
  @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Khmer:wght@400;500;600;700;800&display=swap');
  /* Custom enhancements */
  .custom-navbar {
    background: rgba(63, 140, 255, 0.95);
    backdrop-filter: blur(10px);
    transition: all 0.3s ease;
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
    transition: color 0.2s ease-in-out;
  }
  /* Subtle bottom border accent for active links on desktop */
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
  .lang-btn.active {
    background-color: #ffffff !important;
    color: #3988ff !important;
    font-weight: 600;
  }

  /* Tailwind's .collapse can hide the Bootstrap navbar content. */
  .custom-navbar .navbar-collapse.show {
    visibility: visible !important;
  }

  /* Mobile panel header (brand + close button) */
  .navbar-panel-header {
    display: none;
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

    /* Sticky header inside the sliding panel */
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
            </ul>

            <div class="navbar-panel-section-label d-lg-none"><?php echo t('language') ?? 'Language'; ?></div>
            <hr class="d-none text-white-50 my-2">

            <!-- Right Controls (Language & Logout) -->
            <div class="d-flex flex-column flex-lg-row gap-2 align-items-lg-center pt-1 pt-lg-0">
                
                <!-- Language Selector Group -->
                <div class="btn-group w-100 w-lg-auto" role="group" aria-label="Language switch">
                    <a class="btn btn-outline-light btn-sm lang-btn px-3 <?php echo $currentLang === 'en' ? 'active' : ''; ?>" href="?lang=en">
                        <?php echo t('english'); ?>
                    </a>
                    <a class="btn btn-outline-light btn-sm lang-btn px-3 <?php echo $currentLang === 'kh' ? 'active' : ''; ?>" href="?lang=kh">
                        <?php echo t('khmer'); ?>
                    </a>
                </div>

                <!-- Logout Button -->
                <a class="btn btn-outline-success btn-sm fw-medium px-3 text-light d-flex align-items-center justify-content-center gap-1 shadow-sm mt-2 mt-lg-0" href="logout.php">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-box-arrow-right" viewBox="0 0 16 16">
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
      const submitter = event.submitter || event.target.querySelector('button[type="submit"], input[type="submit"]');
      setInlineLoading(submitter);
    }, true);
  })();
</script>