<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$currentLang = $_SESSION['lang'] ?? 'en';

$userId = (int) $_SESSION['user_id'];
$errors = [];
$success = '';

// Load current user from DB
$stmt = $conn->prepare('SELECT id, fullname, username, avatar, avatar_data, created_at FROM users WHERE id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// --- UPDATE PROFILE (fullname) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    $fullname = trim($_POST['fullname'] ?? '');

    if ($fullname === '') {
        $errors[] = 'Full name is required.';
    } else {
        $stmt = $conn->prepare('UPDATE users SET fullname = ? WHERE id = ?');
        $stmt->bind_param('si', $fullname, $userId);
        $stmt->execute();
        $_SESSION['user_name'] = $fullname;
        $user['fullname'] = $fullname;
        $success = 'Profile updated.';
        redirect('profile.php');
    }
}

// --- CHANGE PASSWORD ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'] ?? '';
    $newPass = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $pwStmt = $conn->prepare('SELECT password FROM users WHERE id = ?');
    $pwStmt->bind_param('i', $userId);
    $pwStmt->execute();
    $pwRow = $pwStmt->get_result()->fetch_assoc();

    if (!password_verify($current, $pwRow['password'])) {
        $errors[] = 'Current password is incorrect.';
    } elseif (strlen($newPass) < 6) {
        $errors[] = 'New password must be at least 6 characters.';
    } elseif ($newPass !== $confirm) {
        $errors[] = 'New passwords do not match.';
    } else {
        $hashed = password_hash($newPass, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->bind_param('si', $hashed, $userId);
        $stmt->execute();
        $success = 'Password changed.';
        redirect('profile.php');
    }
}

// --- UPLOAD AVATAR (stored in the database as a base64 data URI) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_photo'])) {
    $file = $_FILES['profile_photo'];

    if ($file['error'] === UPLOAD_ERR_OK && $file['size'] > 0 && $file['size'] <= 2 * 1024 * 1024) {
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (in_array($fileExt, $allowedExtensions)) {
            $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
            $mime = $mimeMap[$fileExt] ?? (mime_content_type($file['tmp_name']) ?: 'image/jpeg');
            $dataUri = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($file['tmp_name']));

            $stmt = $conn->prepare('UPDATE users SET avatar_data = ?, avatar = NULL WHERE id = ?');
            $stmt->bind_param('si', $dataUri, $userId);
            $stmt->execute();

            $_SESSION['user_avatar'] = $dataUri;
            $user['avatar_data'] = $dataUri;
            $success = 'Profile photo updated.';
            redirect('profile.php');
        } else {
            $errors[] = 'Only JPG, PNG, WEBP or GIF images are allowed.';
        }
    } else {
        $errors[] = 'Upload a valid image (max 2MB).';
    }
}

// Reload user after potential changes
$stmt = $conn->prepare('SELECT id, fullname, username, avatar, avatar_data, created_at FROM users WHERE id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$avatar = $user['avatar_data'] ?: $user['avatar'];
$avatarSrc = $avatar ?: null;
$initial = strtoupper(substr($user['fullname'] ?? 'U', 0, 1));
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Khmer:wght@400;500;600;700;800&display=swap');
        body { background: #f8fafc; font-family: 'Inter', sans-serif; }
        html[lang="kh"] body { font-family: 'Noto Sans Khmer', 'Inter', sans-serif; }
        .avatar-lg {
            width: 96px; height: 96px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #fff;
            box-shadow: 0 6px 18px rgba(15,23,42,.12);
            background: #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 800;
            color: #2563eb;
        }
    </style>
</head>
<body>
<?php $activePage = 'profile'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="container py-4 py-md-5" style="max-width: 760px;">
    <div class="d-flex align-items-center gap-3 mb-4">
        <h2 class="h3 fw-bold mb-0">My Profile</h2>
        <span class="badge bg-light text-dark border px-3 py-2 rounded-pill">Member since <?= htmlspecialchars(date('M Y', strtotime($user['created_at'] ?? 'now'))) ?></span>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Avatar + Account info -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <h6 class="card-subtitle text-uppercase text-muted fw-bold mb-3 small">Account</h6>
            <div class="d-flex align-items-center gap-4">
                <div class="avatar-lg text-center">
                    <?php if ($avatarSrc): ?>
                        <img src="<?= htmlspecialchars($avatarSrc) ?>" alt="Profile" class="w-100 h-100 rounded-circle">
                    <?php else: ?>
                        <span><?= htmlspecialchars($initial) ?></span>
                    <?php endif; ?>
                </div>
                <div>
                    <h4 class="fw-bold mb-1"><?= htmlspecialchars($user['fullname']) ?></h4>
                    <p class="text-muted small mb-2">@<?= htmlspecialchars($user['username']) ?></p>

                    <!-- Upload avatar (stored in DB) -->
                    <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
                        <input type="file" class="form-control form-control-sm" style="max-width: 240px;" name="profile_photo" accept="image/*">
                        <button class="btn btn-sm btn-primary">Upload Photo</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Profile -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <h6 class="card-subtitle text-uppercase text-muted fw-bold mb-3 small">Edit Profile</h6>
            <form method="post" class="row g-3">
                <input type="hidden" name="save_profile" value="1">
                <div class="col-12">
                    <label class="form-label small fw-semibold text-secondary">Full Name</label>
                    <input class="form-control rounded-3" name="fullname" value="<?= htmlspecialchars($user['fullname']) ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label small fw-semibold text-secondary">Username</label>
                    <input class="form-control rounded-3" value="<?= htmlspecialchars($user['username']) ?>" disabled>
                    <div class="form-text">Username cannot be changed.</div>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary rounded-3 fw-medium px-4">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Change Password -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <h6 class="card-subtitle text-uppercase text-muted fw-bold mb-3 small">Security</h6>
            <form method="post" class="row g-3">
                <input type="hidden" name="change_password" value="1">
                <div class="col-12 col-md-6">
                    <label class="form-label small fw-semibold text-secondary">Current Password</label>
                    <input type="password" class="form-control rounded-3" name="current_password" placeholder="••••••••" required>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label small fw-semibold text-secondary">New Password</label>
                    <input type="password" class="form-control rounded-3" name="new_password" placeholder="At least 6 characters" required>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label small fw-semibold text-secondary">Confirm New Password</label>
                    <input type="password" class="form-control rounded-3" name="confirm_password" placeholder="Repeat new password" required>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary rounded-3 fw-medium px-4">Change Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
