<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$userName = $_SESSION['user_name'] ?? 'User';
$errors = [];

// Handle Adding New Goal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_goal'])) {
    $goalName = trim($_POST['goal_name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $priority = trim($_POST['priority'] ?? '');
    $targetHours = (int) ($_POST['target_hours'] ?? 0);
    $completedHours = (int) ($_POST['completed_hours'] ?? 0);
    $deadline = $_POST['deadline'] ?: null;

    // Fallback defaults if left empty
    if ($category === '') $category = 'General';
    if ($priority === '') $priority = 'Medium';

    $progress = $targetHours > 0 ? (int) min(100, round(($completedHours / $targetHours) * 100)) : 0;
    $status = $progress >= 100 ? 'Completed' : 'In Progress';

    if ($goalName === '' || $targetHours <= 0) {
        $errors[] = 'Goal name and target hours are required.';
    } else {
        $stmt = $conn->prepare('INSERT INTO goals (user_id, goal_name, category, priority, target_hours, completed_hours, progress, deadline, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isssiiiss', $userId, $goalName, $category, $priority, $targetHours, $completedHours, $progress, $deadline, $status);
        $stmt->execute();
        redirect('goals.php');
    }
}

// Handle Updating Progress (Quick Log Hours)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_progress'])) {
    $goalId = (int) $_POST['goal_id'];
    $addHours = (int) $_POST['add_hours'];

    if ($addHours > 0) {
        $stmt = $conn->prepare('SELECT target_hours, completed_hours FROM goals WHERE id = ? AND user_id = ?');
        $stmt->bind_param('ii', $goalId, $userId);
        $stmt->execute();
        $curr = $stmt->get_result()->fetch_assoc();

        if ($curr) {
            $newCompleted = $curr['completed_hours'] + $addHours;
            $newProgress = (int) min(100, round(($newCompleted / $curr['target_hours']) * 100));
            $newStatus = $newProgress >= 100 ? 'Completed' : 'In Progress';

            $updateStmt = $conn->prepare('UPDATE goals SET completed_hours = ?, progress = ?, status = ? WHERE id = ? AND user_id = ?');
            $updateStmt->bind_param('iisii', $newCompleted, $newProgress, $newStatus, $goalId, $userId);
            $updateStmt->execute();
        }
    }
    redirect('goals.php');
}

// Handle Deletion via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_goal'])) {
    $id = (int) $_POST['goal_id'];
    $stmt = $conn->prepare('DELETE FROM goals WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $id, $userId);
    $stmt->execute();
    redirect('goals.php');
}

$goals = $conn->query("SELECT * FROM goals WHERE user_id = {$userId} ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Goals Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8fafc; }
        .soft-card { border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,.03); }
        .badge-priority-High { background-color: #ef4444; }
        .badge-priority-Medium { background-color: #f59e0b; }
        .badge-priority-Low { background-color: #10b981; }
    </style>
</head>
<body>
<?php $activePage = 'goals'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-1">Goals Tracker</h2>
            <p class="text-muted mb-0">Logged in as <?= htmlspecialchars($userName) ?></p>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
    <?php endif; ?>

    <!-- Add Goal Form -->
    <div class="card soft-card mb-4">
        <div class="card-body">
            <h5 class="fw-bold mb-3">Add New Goal</h5>
            <form method="post" class="row g-3">
                <input type="hidden" name="add_goal" value="1">
                
                <div class="col-md-4">
                    <label class="form-label small text-muted">Goal Title</label>
                    <input class="form-control" name="goal_name" placeholder="e.g..." required>
                </div>
                
                <!-- Custom Category Input -->
                <div class="col-md-4">
                    <label class="form-label small text-muted">Category</label>
                    <input class="form-control" name="category" placeholder="e.g...">
                </div>

                <!-- Custom Priority Input -->
                <div class="col-md-4">
                    <label class="form-label small text-muted">Priority</label>
                    <input class="form-control" name="priority" placeholder="e.g. High, Urgent, Normal...">
                </div>

                <div class="col-md-3">
                    <label class="form-label small text-muted">Target Hours</label>
                    <input class="form-control" type="number" name="target_hours" placeholder="e.g. 60" required>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label small text-muted">Already Done (Hours)</label>
                    <input class="form-control" type="number" name="completed_hours" placeholder="0" value="0">
                </div>

                <div class="col-md-4">
                    <label class="form-label small text-muted">Deadline</label>
                    <input class="form-control" type="date" name="deadline">
                </div>

                <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-primary w-100">Add Goal</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Goals Display Grid -->
    <div class="row g-3">
        <?php foreach ($goals as $goal): 
            $progress = (int)$goal['progress'];
            $barColor = $progress >= 100 ? 'bg-success' : ($progress > 50 ? 'bg-info' : 'bg-warning');
            $priority = $goal['priority'] ?? 'Medium';
            $category = $goal['category'] ?? 'General';
            
            // Check if priority matches predefined badge styling, fallback to primary badge
            $badgeClass = in_array($priority, ['High', 'Medium', 'Low']) ? "badge-priority-{$priority}" : "bg-primary";
        ?>
            <div class="col-12 col-md-6 col-lg-4">
                <div class="card soft-card h-100">
                    <div class="card-body vstack">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="badge bg-secondary opacity-75"><?= htmlspecialchars($category) ?></span>
                            <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($priority) ?></span>
                        </div>

                        <h5 class="fw-bold mb-2"><?= htmlspecialchars($goal['goal_name']) ?></h5>
                        
                        <div class="text-muted small mb-3">
                            <div>Target: <strong><?= (int)$goal['target_hours'] ?> hrs</strong> | Done: <strong><?= (int)$goal['completed_hours'] ?> hrs</strong></div>
                            <?php if ($goal['deadline']): ?>
                                <div>Deadline: <?= htmlspecialchars($goal['deadline']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="mt-auto">
                            <div class="d-flex justify-content-between small fw-bold mb-1">
                                <span>Progress</span>
                                <span><?= $progress ?>%</span>
                            </div>
                            <div class="progress mb-3" style="height:8px;">
                                <div class="progress-bar <?= $barColor ?>" style="width: <?= $progress ?>%;"></div>
                            </div>

                            <!-- Action Tools: Log Hours & Delete -->
                            <div class="d-flex gap-2">
                                <form method="post" class="d-flex gap-1 flex-grow-1">
                                    <input type="hidden" name="update_progress" value="1">
                                    <input type="hidden" name="goal_id" value="<?= $goal['id'] ?>">
                                    <input type="number" class="form-control form-control-sm" name="add_hours" placeholder="+ Hours" min="1" required style="width: 80px;">
                                    <button class="btn btn-sm btn-outline-primary flex-grow-1">Log</button>
                                </form>

                                <form method="post" onsubmit="return confirm('Delete this goal?')">
                                    <input type="hidden" name="delete_goal" value="1">
                                    <input type="hidden" name="goal_id" value="<?= $goal['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (!$goals): ?>
            <div class="col-12 text-center text-muted py-4">No goals added yet. Start by creating one above!</div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>