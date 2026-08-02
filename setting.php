<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$currentLang = $_SESSION['lang'] ?? 'en';

// Fallback session data
$userFirstName = $_SESSION['user_first_name'] ?? 'Alex';
$userLastName = $_SESSION['user_last_name'] ?? 'Student';
$userEmail = $_SESSION['user_email'] ?? 'alex@university.edu';
$userName = $userFirstName . ' ' . $userLastName;
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings | StudyPlanner Essential</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Khmer:wght@400;500;600;700;800&display=swap');
        body {
            background-color: #f8fafc;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }
        html[lang="kh"] body {
            font-family: 'Noto Sans Khmer', 'Inter', system-ui, -apple-system, sans-serif;
        }
        .sidebar {
            width: 240px;
            min-height: 100vh;
            background-color: #f8fafc;
        }
        .nav-link {
            color: #475569;
            font-weight: 500;
            padding: 10px 16px;
            border-radius: 8px;
        }
        .nav-link:hover {
            color: #0d6efd;
            background-color: #f1f5f9;
        }
        .nav-link.active {
            color: #0d6efd;
            background-color: #e0edff;
            border-right: 3px solid #0d6efd;
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
        }
        .profile-img-container {
            width: 84px;
            height: 84px;
            border-radius: 12px;
            overflow: hidden;
            background-color: #e2e8f0;
        }
        .profile-img-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 1rem;
        }
        .form-check-input:checked {
            background-color: #0d6efd;
            border-color: #0d6efd;
        }
        .settings-container {
            max-width: 800px;
        }
    </style>
</head>
<body>

<div class="d-flex">
    <!-- Sidebar -->
    <aside class="sidebar p-3 border-end d-none d-md-flex flex-column justify-content-between">
        <div>
            <div class="mb-4 ps-2">
                <h5 class="fw-bold mb-0 text-dark">StudyPlanner</h5>
                <small class="text-muted fw-semibold" style="font-size: 11px;">Deep Work Mode</small>
            </div>

            <nav class="nav flex-column gap-1">
                <a class="nav-link d-flex align-items-center gap-2" href="dashboard.php">
                    <i class="bi bi-grid"></i> Dashboard
                </a>
                <a class="nav-link d-flex align-items-center gap-2" href="planner.php">
                    <i class="bi bi-calendar-event"></i> Planner
                </a>
                <a class="nav-link d-flex align-items-center gap-2" href="subjects.php">
                    <i class="bi bi-journal-text"></i> Subjects
                </a>
                <a class="nav-link active d-flex align-items-center gap-2" href="settings.php">
                    <i class="bi bi-gear-fill"></i> Settings
                </a>
            </nav>
        </div>

        <!-- Sidebar User Profile Bottom -->
        <div class="dropdown border-top pt-3">
            <button class="btn btn-link text-decoration-none text-dark p-0 d-flex align-items-center gap-2 w-100" type="button" data-bs-toggle="dropdown">
                <div class="bg-secondary-subtle rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                    <i class="bi bi-person text-secondary small"></i>
                </div>
                <div class="text-start lh-1 overflow-hidden">
                    <div class="small fw-semibold text-truncate"><?php echo htmlspecialchars($userName); ?></div>
                    <small class="text-muted" style="font-size: 10px;"><?php echo htmlspecialchars($userEmail); ?></small>
                </div>
            </button>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
            </ul>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-grow-1 bg-white min-vh-100">
        
        <!-- Top Navbar Header -->
        <header class="d-flex justify-content-end align-items-center px-4 py-3 border-bottom">
            <button class="btn btn-link text-muted p-0"><i class="bi bi-bell fs-5"></i></button>
        </header>

        <div class="p-4 p-md-5">
            <div class="settings-container">
                
                <!-- Page Title -->
                <div class="mb-4">
                    <h2 class="fw-bold text-dark mb-1">Settings</h2>
                    <p class="text-muted mb-0">Manage your account details and application preferences.</p>
                </div>

                <form method="post" action="" enctype="multipart/form-data">
                    
                    <!-- Profile Section -->
                    <section class="mb-5">
                        <h3 class="section-title">Profile</h3>
                        <hr class="mt-2 mb-4">

                        <div class="row align-items-start g-4">
                            <!-- Avatar Column -->
                            <div class="col-auto text-center">
                                <div class="profile-img-container mb-2 shadow-sm">
                                    <img src="https://images.unsplash.com/photo-1534528741775-53994a69daeb?q=80&w=256&auto=format&fit=crop" alt="Profile Photo">
                                </div>
                                <label for="photo-upload" class="btn btn-link p-0 small text-decoration-none fw-semibold" style="font-size: 13px; cursor: pointer;">
                                    Change Photo
                                </label>
                                <input type="file" id="photo-upload" class="d-none" name="profile_photo">
                            </div>

                            <!-- Form Fields Column -->
                            <div class="col">
                                <div class="row g-3 mb-3">
                                    <div class="col-12 col-sm-6">
                                        <label class="form-label text-secondary small fw-medium">First Name</label>
                                        <input type="text" class="form-control rounded-3" name="first_name" value="<?php echo htmlspecialchars($userFirstName); ?>">
                                    </div>
                                    <div class="col-12 col-sm-6">
                                        <label class="form-label text-secondary small fw-medium">Last Name</label>
                                        <input type="text" class="form-control rounded-3" name="last_name" value="<?php echo htmlspecialchars($userLastName); ?>">
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label text-secondary small fw-medium">Email Address</label>
                                    <input type="email" class="form-control rounded-3" name="email" value="<?php echo htmlspecialchars($userEmail); ?>">
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Preferences Section -->
                    <section class="mb-5">
                        <h3 class="section-title">Preferences</h3>
                        <hr class="mt-2 mb-4">

                        <!-- Deep Work Switch -->
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <div>
                                <h6 class="mb-1 fw-semibold text-dark">Deep Work Mode</h6>
                                <p class="text-muted small mb-0">Suppress non-essential notifications during focus sessions.</p>
                            </div>
                            <div class="form-check form-switch fs-4">
                                <input class="form-check-input" type="checkbox" name="deep_work_mode" checked>
                            </div>
                        </div>

                        <!-- Daily Reminders Switch -->
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <div>
                                <h6 class="mb-1 fw-semibold text-dark">Daily Study Reminders</h6>
                                <p class="text-muted small mb-0">Receive an email digest of today's schedule.</p>
                            </div>
                            <div class="form-check form-switch fs-4">
                                <input class="form-check-input" type="checkbox" name="daily_reminders">
                            </div>
                        </div>

                        <!-- Default Focus Duration Dropdown -->
                        <div class="mb-3" style="max-width: 320px;">
                            <label class="form-label text-secondary small fw-medium">Default Focus Duration</label>
                            <select class="form-select rounded-3" name="default_focus_duration">
                                <option value="25">25 Minutes (Pomodoro)</option>
                                <option value="45" selected>45 Minutes</option>
                                <option value="60">60 Minutes</option>
                                <option value="90">90 Minutes (Deep Session)</option>
                            </select>
                        </div>
                    </section>

                    <!-- Additional Section: Security -->
                    <section class="mb-5">
                        <h3 class="section-title">Security</h3>
                        <hr class="mt-2 mb-4">

                        <div class="row g-3">
                            <div class="col-12 col-sm-6">
                                <label class="form-label text-secondary small fw-medium">New Password</label>
                                <input type="password" class="form-control rounded-3" name="new_password" placeholder="••••••••">
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label text-secondary small fw-medium">Confirm New Password</label>
                                <input type="password" class="form-control rounded-3" name="confirm_password" placeholder="••••••••">
                            </div>
                        </div>
                    </section>

                    <!-- Additional Section: Danger Zone -->
                    <section class="mb-5 p-4 rounded-3 bg-danger-subtle border border-danger-subtle">
                        <h6 class="fw-bold text-danger mb-1"><i class="bi bi-exclamation-triangle-fill me-2"></i>Danger Zone</h6>
                        <p class="text-muted small mb-3">Once you delete your account, there is no going back. Please be certain.</p>
                        <button type="button" class="btn btn-outline-danger btn-sm fw-semibold">Delete Account</button>
                    </section>

                    <!-- Form Action Buttons -->
                    <div class="d-flex justify-content-end align-items-center gap-3 pt-3 border-top">
                        <button type="button" class="btn btn-link text-muted text-decoration-none fw-semibold">Cancel</button>
                        <button type="submit" class="btn btn-primary fw-bold px-4 py-2 rounded-3">Save Changes</button>
                    </div>

                </form>

            </div>
        </div>
    </main>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
