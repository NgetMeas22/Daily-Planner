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
  html[lang="kh"] body {
    font-family: 'Noto Sans Khmer', 'Inter', sans-serif;
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
            
            <!-- Navigation Links -->
            <ul class="navbar-nav me-auto mb-3 mb-lg-0 gap-lg-1 mt-2 mt-lg-0">
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

            <hr class="d-lg-none text-white-50 my-2">

            <!-- Right Controls (Language & Logout) -->
            <div class="d-flex flex-column flex-lg-row gap-2 align-items-lg-center pt-2 pt-lg-0">
                
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
                <a class="btn btn-outline-success btn-sm fw-medium px-3 text-light d-flex align-items-center justify-content-center gap-1 shadow-sm" href="logout.php">
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
    const overlay = document.getElementById('pageLoadingOverlay');
    if (!navbar || !toggler) return;

    const setExpanded = (expanded) => {
      toggler.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      document.body.style.overflow = expanded ? 'hidden' : '';
    };

    const closeNavbar = () => {
      navbar.classList.remove('show');
      setExpanded(false);
    };

    toggler.addEventListener('click', function () {
      const isOpen = navbar.classList.toggle('show');
      setExpanded(isOpen);
    });

    navbar.querySelectorAll('a, button').forEach((el) => {
      el.addEventListener('click', function () {
        if (window.innerWidth < 992) closeNavbar();
      });
    });

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
