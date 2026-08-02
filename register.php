<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = trim($_POST['fullname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($fullname === '') $errors[] = 'Full name is required.';
    if ($username === '') $errors[] = 'Username is required.';
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
    if ($password !== $confirmPassword) $errors[] = 'Passwords do not match.';

    if (!$errors) {
        $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $errors[] = 'Username is already registered.';
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('INSERT INTO users (fullname, username, password) VALUES (?, ?, ?)');
            $stmt->bind_param('sss', $fullname, $username, $hashedPassword);

            if ($stmt->execute()) {
                redirect('index.php?registered=1');
            } else {
                $errors[] = 'Registration failed. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light min-vh-100 d-flex align-items-center py-5">
    <div class="position-fixed top-0 end-0 p-3 d-flex gap-2">
        <a class="btn btn-sm btn-outline-primary" href="?lang=en">English</a>
        <a class="btn btn-sm btn-outline-primary" href="?lang=kh">ខ្មែរ</a>
    </div>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-12 col-md-8 col-lg-5">
                <div class="card border-0 shadow-sm rounded-4 p-4">
                    <div class="card-body p-2">
                        <h1 class="h3 text-primary text-center fw-bold mb-2">Create an Account</h1>
                        <p class="text-muted text-center mb-4 small">Please fill in your details to register mnus smos</p>

                        <?php if ($errors): ?>
                            <div class="alert alert-danger py-2 px-3 mb-3 small" role="alert">
                                <?php echo htmlspecialchars(implode(' ', $errors)); ?>
                            </div>
                        <?php endif; ?>

                        <form method="post" action="">
                            <div class="mb-3">
                                <label class="form-label text-secondary small fw-medium">Full Name</label>
                                <input type="text" class="form-control rounded-3" name="fullname" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label text-secondary small fw-medium">Username</label>
                                <input type="text" class="form-control rounded-3" name="username" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label text-secondary small fw-medium">Password</label>
                                <input type="password" class="form-control rounded-3" name="password" required>
                            </div>

                            <div class="mb-4">
                                <label class="form-label text-secondary small fw-medium">Confirm Password</label>
                                <input type="password" class="form-control rounded-3" name="confirm_password" required>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 fw-bold py-2 rounded-3">Register Here</button>
                        </form>

                        <div class="text-center small text-muted mt-3">
                            Already have an account? <a class="text-primary text-decoration-none fw-bold" href="index.php">Login</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
