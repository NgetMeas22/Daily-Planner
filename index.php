<?php
require_once __DIR__ . '/includes/auth.php';

$currentLang = $_SESSION['lang'] ?? 'en';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$errors = [];
$mode = 'login';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '') $errors[] = 'Username is required.';
    if ($password === '') $errors[] = 'Password is required.';

    if (!$errors) {
        $stmt = $conn->prepare('SELECT id, fullname, password FROM users WHERE username = ? LIMIT 1');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['fullname'];
                redirect('dashboard.php');
            } else {
                $errors[] = 'Invalid username or password.';
            }
        } else {
            $errors[] = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Planner Auth</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Khmer:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; }
        html[lang="kh"] body { font-family: 'Noto Sans Khmer', 'Inter', sans-serif; }
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
    </style>
</head>
<body class="bg-light min-vh-100 d-flex align-items-center py-5">
    <div class="position-fixed top-0 end-0 p-3 d-flex gap-2">
        <a class="btn btn-sm btn-outline-primary" href="?lang=en">English</a>
        <a class="btn btn-sm btn-outline-primary" href="?lang=kh">ខ្មែរ</a>
    </div>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-12 col-md-7 col-lg-5">
                <div class="card border-0 shadow-sm rounded-4 p-4 h-100" id="login">
                    <div class="card-body p-2 d-flex flex-column justify-content-between">
                        <div>
                            <h1 class="h3 text-primary text-center fw-bold mb-2">Welcome Back Mnus Smos</h1>
                            <p class="text-muted text-center mb-4 small">Please enter your credentials to log in</p>

                            <?php if ($errors && $mode === 'login'): ?>
                                <div class="alert alert-danger py-2 px-3 mb-3 small" role="alert">
                                    <?php echo htmlspecialchars(implode(' ', $errors)); ?>
                                </div>
                            <?php endif; ?>

                            <form method="post" action="">
                                <input type="hidden" name="form_type" value="login">

                                <div class="mb-3">
                                    <label class="form-label text-secondary small fw-medium">Username</label>
                                    <input type="text" class="form-control rounded-3" name="username" required>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label text-secondary small fw-medium">Password</label>
                                    <input type="password" class="form-control rounded-3" name="password" required>
                                </div>

                                <button type="submit" class="btn btn-primary w-100 fw-bold py-2 rounded-3 mb-3">Login</button>
                            </form>
                        </div>

                        <div class="text-center small text-muted">
                            Don't have an account? <a class="text-primary text-decoration-none fw-bold" href="register.php">Register</a>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script>
        (function () {
            const setInlineLoading = (el) => {
                if (!el || el.dataset.loadingApplied === '1') return;
                el.dataset.loadingApplied = '1';
                el.dataset.originalHtml = el.innerHTML;
                el.classList.add('loading-inline');
                el.innerHTML = '<span class="loading-inline-content"><span class="loading-inline-spinner" aria-hidden="true"></span><span>Loading...</span></span>';
                if (el.tagName === 'BUTTON') {
                    el.disabled = true;
                }
            };

            document.addEventListener('submit', function (event) {
                const submitter = event.submitter || event.target.querySelector('button[type="submit"], input[type="submit"]');
                setInlineLoading(submitter);
            }, true);
        })();
    </script>

    <!-- Optional Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
