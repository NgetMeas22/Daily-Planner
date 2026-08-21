<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$currentLang = $_SESSION['lang'] ?? 'en';

$userName = $_SESSION['user_name'] ?? 'User';
$userId = (int) $_SESSION['user_id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_subject'])) {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($name === '') {
        $errors[] = 'Subject name is required.';
    } else {
        $stmt = $conn->prepare('INSERT INTO subjects (user_id, name, description) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $userId, $name, $description);
        $stmt->execute();
    }
}

if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $stmt = $conn->prepare('DELETE FROM subjects WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $id, $userId);
    $stmt->execute();
    redirect('subjects.php');
}

$stmt = $conn->prepare('SELECT id, name, description, created_at FROM subjects WHERE user_id = ? ORDER BY id DESC');
$stmt->bind_param('i', $userId);
$stmt->execute();
$subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subjects</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Khmer:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        html[lang="kh"] body { font-family: 'Noto Sans Khmer', 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-light" data-theme="<?php echo htmlspecialchars(current_theme()); ?>">
<?php $activePage = 'subjects'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="container py-4 py-md-5">
    
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="h3 fw-bold mb-1">Subjects</h2>
            <p class="text-muted small mb-0">Logged in as <strong class="text-dark"><?php echo htmlspecialchars($userName); ?></strong></p>
        </div>
    </div>

    <!-- Error Alert -->
    <?php if ($errors): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3 shadow-sm" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo htmlspecialchars(implode(' ', $errors)); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Add Subject Form Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <h6 class="card-subtitle text-uppercase text-muted fw-bold mb-3 small">Add New Subject</h6>
            <form method="post">
                <input type="hidden" name="add_subject" value="1">
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label small fw-semibold text-secondary">Subject Name</label>
                        <input class="form-control rounded-3" 
                               name="name" 
                               placeholder="e.g. Mathematics" 
                               required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-semibold text-secondary">Description</label>
                        <input class="form-control rounded-3" 
                               name="description" 
                               placeholder="Optional brief description">
                    </div>
                    <div class="col-12 col-md-2 d-grid align-self-end">
                        <button class="btn btn-primary rounded-3 fw-medium py-2" type="submit">
                            <i class="bi bi-plus-lg me-1"></i> Add
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Subjects Container -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        
        <!-- Header -->
        <div class="card-header bg-white py-3 px-4 border-bottom d-flex justify-between align-items-center">
            <div>
                <h5 class="card-title fw-bold mb-0 text-dark">Subject List</h5>
                <small class="text-muted">All subjects registered in your system</small>
            </div>
            <span class="badge bg-light text-dark border px-3 py-2 rounded-pill ms-auto">
                <?php echo count($subjects); ?> Total
            </span>
        </div>

        <!-- Mobile View: Card List (Visible below 'md' breakpoint) -->
        <div class="d-md-none divide-y">
            <?php foreach ($subjects as $subject): ?>
                <div class="p-3 border-bottom">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-2 px-2.5 py-1.5 fw-semibold">
                                <?php echo htmlspecialchars($subject['name']); ?>
                            </span>
                        </div>
                        <a class="btn btn-sm btn-outline-danger border-0 text-danger p-1" 
                           href="subjects.php?delete=<?php echo (int)$subject['id']; ?>" 
                           onclick="return confirm('Delete this subject?')">
                            <i class="bi bi-trash"></i> Delete
                        </a>
                    </div>
                    <p class="small text-secondary mb-2">
                        <?php echo htmlspecialchars($subject['description'] ?? 'No description provided'); ?>
                    </p>
                    <div class="text-muted small" style="font-size: 0.75rem;">
                        Created: <?php echo htmlspecialchars(date('M d, Y', strtotime($subject['created_at']))); ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (!$subjects): ?>
                <div class="text-center py-4 text-muted small">
                    No subjects added yet.
                </div>
            <?php endif; ?>
        </div>

        <!-- Desktop View: Table (Visible on 'md' screens and above) -->
        <div class="table-responsive d-none d-md-block">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light text-uppercase small text-muted">
                    <tr>
                        <th class="py-3 px-4" style="width: 25%;">Name</th>
                        <th class="py-3 px-4">Description</th>
                        <th class="py-3 px-4" style="width: 20%;">Created</th>
                        <th class="py-3 px-4 text-end" style="width: 15%;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subjects as $subject): ?>
                        <tr>
                            <!-- Name -->
                            <td class="py-3 px-4">
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1.5 rounded-2 fw-semibold">
                                    <?php echo htmlspecialchars($subject['name']); ?>
                                </span>
                            </td>

                            <!-- Description -->
                            <td class="py-3 px-4 text-secondary">
                                <?php echo htmlspecialchars($subject['description'] ?? '—'); ?>
                            </td>

                            <!-- Created Date -->
                            <td class="py-3 px-4 text-muted small">
                                <?php echo htmlspecialchars(date('M d, Y', strtotime($subject['created_at']))); ?>
                            </td>

                            <!-- Action -->
                            <td class="py-3 px-4 text-end">
                                <a class="btn btn-sm btn-outline-danger border-0 px-2 py-1" 
                                   href="subjects.php?delete=<?php echo (int)$subject['id']; ?>" 
                                   onclick="return confirm('Delete this subject?')">
                                    <i class="bi bi-trash me-1"></i> Delete
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$subjects): ?>
                        <tr>
                            <td colspan="4" class="text-center py-5 text-muted">
                                No subjects added yet.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>
</div>
</body>
</html>
