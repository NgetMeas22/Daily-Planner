<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$currentLang = $_SESSION['lang'] ?? 'en';

$userId = (int) $_SESSION['user_id'];
$userName = $_SESSION['user_name'] ?? 'User';
$errors = [];
$message = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Deletions
    if (str_starts_with($action, 'delete_')) {
        $id = (int) ($_POST['id'] ?? 0);
        $tableMap = [
            'delete_subject' => ['subjects', 'id = ?'],
            'delete_goal'    => ['goals', 'id = ? AND user_id = ?'],
            'delete_planner' => ['planner', 'id = ? AND user_id = ?'],
            'delete_expense' => ['expenses', 'id = ? AND user_id = ?'],
        ];

        if (isset($tableMap[$action])) {
            [$table, $where] = $tableMap[$action];
            $stmt = $conn->prepare("DELETE FROM {$table} WHERE {$where}");
            if ($table === 'subjects') {
                $stmt->bind_param('i', $id);
            } else {
                $stmt->bind_param('ii', $id, $userId);
            }
            $stmt->execute();
            redirect('dashboard.php');
        }
    }

    // Insert Subject
    if ($action === 'add_subject') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if (!$name) $errors[] = 'Subject name is required.';
        else {
            $stmt = $conn->prepare('INSERT INTO subjects (user_id, name, description) VALUES (?, ?, ?)');
            $stmt->bind_param('iss', $userId, $name, $description);
            $stmt->execute();
            $message = 'Subject added.';
        }
    }

    // Insert Goal
    if ($action === 'add_goal') {
        $goalName = trim($_POST['goal_name'] ?? '');
        $targetHours = (int) ($_POST['target_hours'] ?? 0);
        $deadline = $_POST['deadline'] ?: null;
        if (!$goalName || $targetHours <= 0) $errors[] = 'Goal name and target hours are required.';
        else {
            $stmt = $conn->prepare('INSERT INTO goals (user_id, goal_name, target_hours, deadline) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('isis', $userId, $goalName, $targetHours, $deadline);
            $stmt->execute();
            $message = 'Goal added.';
        }
    }

    // Insert Expense
    if ($action === 'add_expense') {
        $title = trim($_POST['title'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $amount = (float) ($_POST['amount'] ?? 0);
        $expenseDate = $_POST['expense_date'] ?? '';
        $note = trim($_POST['note'] ?? '');
        if (!$title || $amount <= 0 || !$expenseDate) $errors[] = 'Title, amount, and date are required.';
        else {
            $stmt = $conn->prepare('INSERT INTO expenses (user_id, title, category, amount, expense_date, note) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('issdss', $userId, $title, $category, $amount, $expenseDate, $note);
            $stmt->execute();
            $message = 'Expense added.';
        }
    }

    // Insert Planner (FIXED: Added missing $dayName parameter)
    if ($action === 'add_planner') {
        $subjectId = (int) ($_POST['subject_id'] ?? 0);
        $studyDate = $_POST['study_date'] ?? '';
        $dayName = $_POST['day_name'] ?? '';
        $startTime = $_POST['start_time'] ?? '';
        $endTime = $_POST['end_time'] ?? '';
        $topic = trim($_POST['topic'] ?? '');
        $goal = trim($_POST['goal'] ?? '');

        if (!$subjectId || !$studyDate || !$dayName || !$startTime || !$endTime || !$topic) {
            $errors[] = 'Please fill in all required planner fields.';
        } else {
            $stmt = $conn->prepare('INSERT INTO planner (user_id, subject_id, study_date, day_name, start_time, end_time, topic, goal) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('iissssss', $userId, $subjectId, $studyDate, $dayName, $startTime, $endTime, $topic, $goal);
            $stmt->execute();
            $message = 'Planner item added.';
        }
    }
}

// Fetch counts
$counts = [
    'subjects' => (int) ($conn->query("SELECT COUNT(*) AS c FROM subjects WHERE user_id = {$userId}")->fetch_assoc()['c'] ?? 0),
    'planner'  => (int) ($conn->query("SELECT COUNT(*) AS c FROM planner WHERE user_id = {$userId}")->fetch_assoc()['c'] ?? 0),
    'goals'    => (int) ($conn->query("SELECT COUNT(*) AS c FROM goals WHERE user_id = {$userId}")->fetch_assoc()['c'] ?? 0),
    'expenses' => (int) ($conn->query("SELECT COUNT(*) AS c FROM expenses WHERE user_id = {$userId}")->fetch_assoc()['c'] ?? 0),
];

// Fetch recent records
$subjects = $conn->query("SELECT id, name, description FROM subjects WHERE user_id = {$userId} ORDER BY id DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);
$goals = $conn->query("SELECT id, goal_name, target_hours, progress FROM goals WHERE user_id = {$userId} ORDER BY id DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);
$expenses = $conn->query("SELECT id, title, category, amount, expense_date FROM expenses WHERE user_id = {$userId} ORDER BY id DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);
$plannerRows = $conn->query("
    SELECT p.id, p.study_date, p.start_time, p.end_time, p.topic, s.name AS subject_name
    FROM planner p INNER JOIN subjects s ON s.id = p.subject_id
    WHERE p.user_id = {$userId} ORDER BY p.id DESC LIMIT 5
")->fetch_all(MYSQLI_ASSOC);
$allSubjects = $conn->query("SELECT id, name FROM subjects WHERE user_id = {$userId} ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Khmer:wght@400;500;600;700;800&display=swap');
        body { background: #f8fafc; font-family: 'Inter', sans-serif; }
        html[lang="kh"] body { font-family: 'Noto Sans Khmer', 'Inter', sans-serif; }
        .soft-card { border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,.03); }
    </style>
</head>
<body>
<?php $activePage = 'dashboard'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="container py-4">
    <div class="mb-4">
        <h1 class="h3 fw-bold">Hello, <?= htmlspecialchars($userName) ?></h1>
        <p class="text-muted small">Manage your study schedule, goals, and expenses.</p>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
    <?php endif; ?>
    <?php if ($message): ?>
        <div class="alert alert-success py-2"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Summary Counters -->
    <div class="row g-3 mb-4 text-center">
        <?php foreach ($counts as $title => $count): ?>
            <div class="col-6 col-md-3">
                <div class="card soft-card py-2">
                    <div class="text-muted small text-capitalize"><?= $title ?></div>
                    <div class="fs-4 fw-bold"><?= $count ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Forms Section -->
    <div class="row g-3 mb-4">
        <!-- Add Subject -->
        <div class="col-md-6 col-lg-3">
            <div class="card soft-card h-100 p-3">
                <h6 class="fw-bold mb-3">Add Subject</h6>
                <form method="post" class="vstack gap-2">
                    <input type="hidden" name="action" value="add_subject">
                    <input class="form-control form-control-sm" name="name" placeholder="Subject Name" required>
                    <input class="form-control form-control-sm" name="description" placeholder="Description">
                    <button class="btn btn-sm btn-primary w-100 mt-auto">Save</button>
                </form>
            </div>
        </div>

        <!-- Add Goal -->
        <div class="col-md-6 col-lg-3">
            <div class="card soft-card h-100 p-3">
                <h6 class="fw-bold mb-3">Add Goal</h6>
                <form method="post" class="vstack gap-2">
                    <input type="hidden" name="action" value="add_goal">
                    <input class="form-control form-control-sm" name="goal_name" placeholder="Goal Name" required>
                    <input class="form-control form-control-sm" type="number" name="target_hours" placeholder="Target Hours" required>
                    <input class="form-control form-control-sm" type="date" name="deadline">
                    <button class="btn btn-sm btn-primary w-100 mt-auto">Save</button>
                </form>
            </div>
        </div>

        <!-- Add Expense -->
        <div class="col-md-6 col-lg-3">
            <div class="card soft-card h-100 p-3">
                <h6 class="fw-bold mb-3">Add Expense</h6>
                <form method="post" class="vstack gap-2">
                    <input type="hidden" name="action" value="add_expense">
                    <input class="form-control form-control-sm" name="title" placeholder="Title" required>
                    <input class="form-control form-control-sm" name="category" placeholder="Category">
                    <input class="form-control form-control-sm" type="number" step="0.01" name="amount" placeholder="Amount ($)" required>
                    <input class="form-control form-control-sm" type="date" name="expense_date" required>
                    <button class="btn btn-sm btn-primary w-100 mt-auto">Save</button>
                </form>
            </div>
        </div>

        <!-- Add Planner -->
        <div class="col-md-6 col-lg-3">
            <div class="card soft-card h-100 p-3">
                <h6 class="fw-bold mb-3">Add Planner Item</h6>
                <form method="post" class="vstack gap-2">
                    <input type="hidden" name="action" value="add_planner">
                    <select class="form-select form-select-sm" name="subject_id" required>
                        <option value="">Select Subject</option>
                        <?php foreach ($allSubjects as $sub): ?>
                            <option value="<?= $sub['id'] ?>"><?= htmlspecialchars($sub['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="row g-1">
                        <div class="col-6"><input type="date" class="form-control form-control-sm" name="study_date" required></div>
                        <div class="col-6">
                            <select class="form-select form-select-sm" name="day_name" required>
                                <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $day): ?>
                                    <option value="<?= $day ?>"><?= substr($day, 0, 3) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row g-1">
                        <div class="col-6"><input type="time" class="form-control form-control-sm" name="start_time" required></div>
                        <div class="col-6"><input type="time" class="form-control form-control-sm" name="end_time" required></div>
                    </div>
                    <input type="text" class="form-control form-control-sm" name="topic" placeholder="Topic" required>
                    <button class="btn btn-sm btn-primary w-100 mt-auto">Save</button>
                </form>
            </div>
        </div>
    </div>

   <!-- Overview Lists Section -->
    <div class="row g-3">
         <!-- Planner List -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-3 overflow-hidden bg-white">
                <div class="card-header bg-white border-bottom border-light py-3 px-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-semibold text-secondary text-uppercase extra-small tracking-wider">Planner Items</h6>
                    <span class="badge bg-light text-secondary border fw-normal px-2 py-1"><?= count($plannerRows) ?> total</span>
                </div>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($plannerRows as $p): ?>
                        <li class="list-group-item border-light px-3 py-2.5 d-flex justify-content-between align-items-center">
                            <div class="me-2 text-truncate">
                                <span class="fw-medium text-dark d-block text-truncate"><?= htmlspecialchars($p['subject_name']) ?> &bull; <span class="fw-normal text-muted"><?= htmlspecialchars($p['topic']) ?></span></span>
                                <span class="text-muted extra-small d-block"><?= htmlspecialchars($p['study_date'] . ' [' . $p['start_time'] . ' - ' . $p['end_time'] . ']') ?></span>
                            </div>
                            <form method="post" onsubmit="return confirm('Delete?');" class="flex-shrink-0">
                                <input type="hidden" name="action" value="delete_planner">
                                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger border-1 px-2 py-0.5 rounded-2 extra-small fw-medium d-inline-flex align-items-center gap-1 shadow-none" title="Delete">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="currentColor" viewBox="0 0 16 16">
                                    <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/>
                                    <path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/>
                                </svg>
                                <span>Delete</span>
                            </button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                    <?php if (!$plannerRows): ?>
                        <li class="list-group-item text-center text-muted py-4 extra-small">No planner items added.</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        
        <!-- Subjects List -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-3 overflow-hidden bg-white">
                <div class="card-header bg-white border-bottom border-light py-3 px-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-semibold text-secondary text-uppercase extra-small tracking-wider">Subjects</h6>
                    <span class="badge bg-light text-secondary border fw-normal px-2 py-1"><?= count($subjects) ?> total</span>
                </div>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($subjects as $s): ?>
                        <li class="list-group-item border-light px-3 py-2.5 d-flex justify-content-between align-items-center">
                            <div class="me-2 text-truncate">
                                <span class="fw-medium text-dark d-block text-truncate"><?= htmlspecialchars($s['name']) ?></span>
                                <?php if (!empty($s['description'])): ?>
                                    <span class="text-muted extra-small d-block text-truncate"><?= htmlspecialchars($s['description']) ?></span>
                                <?php endif; ?>
                            </div>
                            <form method="post" onsubmit="return confirm('Delete?');" class="flex-shrink-0">
                                <input type="hidden" name="action" value="delete_subject">
                                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                               <button type="submit" class="btn btn-sm btn-outline-danger border-1 px-2 py-0.5 rounded-2 extra-small fw-medium d-inline-flex align-items-center gap-1 shadow-none" title="Delete">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="currentColor" viewBox="0 0 16 16">
                                    <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/>
                                    <path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/>
                                </svg>
                                <span>Delete</span>
                            </button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                    <?php if (!$subjects): ?>
                        <li class="list-group-item text-center text-muted py-4 extra-small">No subjects added.</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

       

       
         <!-- Goals List -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-3 overflow-hidden bg-white">
                <div class="card-header bg-white border-bottom border-light py-3 px-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-semibold text-secondary text-uppercase extra-small tracking-wider">Goals</h6>
                    <span class="badge bg-light text-secondary border fw-normal px-2 py-1"><?= count($goals) ?> total</span>
                </div>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($goals as $g): ?>
                        <li class="list-group-item border-light px-3 py-2.5 d-flex justify-content-between align-items-center">
                            <div class="me-2 text-truncate">
                                <span class="fw-medium text-dark d-block text-truncate"><?= htmlspecialchars($g['goal_name']) ?></span>
                                <span class="text-muted extra-small d-block">
                                    Target: <span class="fw-medium text-secondary"><?= (int)$g['target_hours'] ?>h</span> &bull; Progress: <span class="fw-medium text-secondary"><?= (int)$g['progress'] ?>%</span>
                                </span>
                            </div>
                            <form method="post" onsubmit="return confirm('Delete?');" class="flex-shrink-0">
                                <input type="hidden" name="action" value="delete_goal">
                                <input type="hidden" name="id" value="<?= $g['id'] ?>">
                               <button type="submit" class="btn btn-sm btn-outline-danger border-1 px-2 py-0.5 rounded-2 extra-small fw-medium d-inline-flex align-items-center gap-1 shadow-none" title="Delete">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="currentColor" viewBox="0 0 16 16">
                                    <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/>
                                    <path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/>
                                </svg>
                                <span>Delete</span>
                            </button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                    <?php if (!$goals): ?>
                        <li class="list-group-item text-center text-muted py-4 extra-small">No goals added.</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <!-- Expenses List -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-3 overflow-hidden bg-white">
                <div class="card-header bg-white border-bottom border-light py-3 px-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-semibold text-secondary text-uppercase extra-small tracking-wider">Expenses</h6>
                    <span class="badge bg-light text-secondary border fw-normal px-2 py-1"><?= count($expenses) ?> total</span>
                </div>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($expenses as $e): ?>
                        <li class="list-group-item border-light px-3 py-2.5 d-flex justify-content-between align-items-center">
                            <div class="me-2 text-truncate">
                                <span class="fw-medium text-dark d-block text-truncate"><?= htmlspecialchars($e['title']) ?></span>
                                <span class="text-muted extra-small d-block"><?= htmlspecialchars($e['category'] ?? 'Uncategorized') ?> &bull; <?= htmlspecialchars($e['expense_date'] ?? '') ?></span>
                            </div>
                            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                <span class="text-danger fw-semibold small">-$<?= number_format((float)$e['amount'], 2) ?></span>
                                <form method="post" onsubmit="return confirm('Delete?');">
                                    <input type="hidden" name="action" value="delete_expense">
                                    <input type="hidden" name="id" value="<?= $e['id'] ?>">
                                   <button type="submit" class="btn btn-sm btn-outline-danger border-1 px-2 py-0.5 rounded-2 extra-small fw-medium d-inline-flex align-items-center gap-1 shadow-none" title="Delete">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="currentColor" viewBox="0 0 16 16">
                                        <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/>
                                        <path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/>
                                    </svg>
                                    <span>Delete</span>
                                </button>
                                </form>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    <?php if (!$expenses): ?>
                        <li class="list-group-item text-center text-muted py-4 extra-small">No expenses added.</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

    </div>
</div>
</body>
</html>
