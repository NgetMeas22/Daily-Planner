<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$currentLang = $_SESSION['lang'] ?? 'en';

$userId = (int) $_SESSION['user_id'];
$userName = $_SESSION['user_name'] ?? 'User';
$errors = [];
$success = '';

$today = date('Y-m-d');

// A goal is "locked" once it's Completed OR its deadline has passed.
// Locked goals can no longer be edited or have hours logged to them.
function goal_is_locked(array $goal, string $today): bool
{
    return $goal['status'] === 'Completed'
        || (!empty($goal['deadline']) && $goal['deadline'] < $today);
}

function goal_status_class(array $goal, string $today): string
{
    if ($goal['status'] === 'Completed') {
        return 'completed';
    }
    if (!empty($goal['deadline']) && $goal['deadline'] < $today) {
        return 'overdue';
    }
    return 'active';
}

// Handle Adding New Goal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_goal'])) {
    $goalName = trim($_POST['goal_name'] ?? '');
    $category = trim($_POST['category'] ?? '') ?: 'General';
    $priority = trim($_POST['priority'] ?? '') ?: 'Medium';
    $targetHours = (int) ($_POST['target_hours'] ?? 0);
    $completedHours = (int) ($_POST['completed_hours'] ?? 0);
    $deadline = $_POST['deadline'] ?? '';
    $deadline = preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline) ? $deadline : null;

    if ($targetHours < 0) $targetHours = 0;
    if ($completedHours < 0) $completedHours = 0;

    $progress = $targetHours > 0 ? (int) min(100, round(($completedHours / $targetHours) * 100)) : 0;
    $status = $progress >= 100 ? 'Completed' : 'In Progress';

    if ($goalName === '' || $targetHours <= 0) {
        $errors[] = 'Goal name and target hours are required.';
    } else {
        $stmt = $conn->prepare('INSERT INTO goals (user_id, goal_name, category, priority, target_hours, completed_hours, progress, deadline, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isssiiiss', $userId, $goalName, $category, $priority, $targetHours, $completedHours, $progress, $deadline, $status);
        $stmt->execute();
        $stmt->close();
        redirect('goals.php');
    }
}

// Handle Editing an Existing Goal (blocked when the goal is locked)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_goal'])) {
    $goalId = (int) ($_POST['goal_id'] ?? 0);

    $stmt = $conn->prepare('SELECT * FROM goals WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $goalId, $userId);
    $stmt->execute();
    $currentGoal = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$currentGoal) {
        $errors[] = 'Goal not found.';
    } elseif (goal_is_locked($currentGoal, $today)) {
        $errors[] = 'This goal is locked (completed or past deadline) and cannot be edited.';
    } else {
        $goalName = trim($_POST['goal_name'] ?? '');
        $category = trim($_POST['category'] ?? '') ?: 'General';
        $priority = trim($_POST['priority'] ?? '') ?: 'Medium';
        $targetHours = (int) ($_POST['target_hours'] ?? 0);
        $deadline = $_POST['deadline'] ?? '';
        $deadline = preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline) ? $deadline : null;

        if ($targetHours < 0) $targetHours = 0;
        $completedHours = (int) $currentGoal['completed_hours'];
        if ($completedHours > $targetHours) $completedHours = $targetHours;

        $progress = $targetHours > 0 ? (int) min(100, round(($completedHours / $targetHours) * 100)) : 0;
        $status = $progress >= 100 ? 'Completed' : 'In Progress';

        if ($goalName === '' || $targetHours <= 0) {
            $errors[] = 'Goal name and target hours are required.';
        } else {
            $stmt = $conn->prepare('UPDATE goals SET goal_name = ?, category = ?, priority = ?, target_hours = ?, deadline = ?, progress = ?, status = ? WHERE id = ? AND user_id = ?');
            $stmt->bind_param('sssisssii', $goalName, $category, $priority, $targetHours, $deadline, $progress, $status, $goalId, $userId);
            $stmt->execute();
            $stmt->close();
            redirect('goals.php');
        }
    }
}

// Handle Saving Goal Notes (from the detail page — allowed even when locked)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_goal_notes'])) {
    $goalId = (int) ($_POST['goal_id'] ?? 0);
    $notes = trim($_POST['goal_notes'] ?? '');
    $stmt = $conn->prepare('UPDATE goals SET notes = ? WHERE id = ? AND user_id = ?');
    $stmt->bind_param('sii', $notes, $goalId, $userId);
    $stmt->execute();
    $stmt->close();
    redirect('goals.php?view=' . $goalId);
}

// Handle Updating Progress (Quick Log Hours) — blocked when the goal is locked
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_progress'])) {
    $goalId = (int) $_POST['goal_id'];
    $addHours = (int) $_POST['add_hours'];

    $stmt = $conn->prepare('SELECT * FROM goals WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $goalId, $userId);
    $stmt->execute();
    $curr = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($curr && !goal_is_locked($curr, $today) && $addHours > 0) {
        $newCompleted = (int) $curr['completed_hours'] + $addHours;
        $newProgress = (int) min(100, round(($newCompleted / (int) $curr['target_hours']) * 100));
        $newStatus = $newProgress >= 100 ? 'Completed' : 'In Progress';

        $updateStmt = $conn->prepare('UPDATE goals SET completed_hours = ?, progress = ?, status = ? WHERE id = ? AND user_id = ?');
        $updateStmt->bind_param('iisii', $newCompleted, $newProgress, $newStatus, $goalId, $userId);
        $updateStmt->execute();
        $updateStmt->close();
        redirect('goals.php');
    } elseif ($curr && goal_is_locked($curr, $today)) {
        $errors[] = 'This goal is locked and cannot accept more hours.';
    }
}

// Handle Deletion via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_goal'])) {
    $id = (int) $_POST['goal_id'];
    $stmt = $conn->prepare('DELETE FROM goals WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $id, $userId);
    $stmt->execute();
    $stmt->close();
    redirect('goals.php');
}

// --- DETAIL PAGE (?view=ID) -------------------------------------------------
$viewGoal = null;
if (isset($_GET['view'])) {
    $vid = (int) $_GET['view'];
    $stmt = $conn->prepare('SELECT * FROM goals WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $vid, $userId);
    $stmt->execute();
    $viewGoal = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$viewGoal) {
        redirect('goals.php');
    }
}

// --- EDIT LOAD (?edit=ID) ----------------------------------------------------
$editGoal = null;
if (isset($_GET['edit'])) {
    $eid = (int) $_GET['edit'];
    $stmt = $conn->prepare('SELECT * FROM goals WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $eid, $userId);
    $stmt->execute();
    $editGoal = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($editGoal && goal_is_locked($editGoal, $today)) {
        $editGoal = null;
        $errors[] = 'This goal is locked (completed or past deadline) and cannot be edited.';
    }
}

// --- FETCH ALL GOALS (overdue first, then in progress, then completed) ------
$stmt = $conn->prepare("
    SELECT * FROM goals
    WHERE user_id = ?
    ORDER BY
        CASE
            WHEN status = 'Completed' THEN 2
            WHEN deadline IS NOT NULL AND deadline < ? THEN 0
            ELSE 1
        END ASC,
        deadline ASC,
        id DESC
");
$stmt->bind_param('is', $userId, $today);
$stmt->execute();
$goals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Goal summary stats (total, in progress, completed, overdue)
$goalStats = ['total' => count($goals), 'in_progress' => 0, 'completed' => 0, 'overdue' => 0];
foreach ($goals as $goal) {
    if ($goal['status'] === 'Completed') {
        $goalStats['completed']++;
    } else {
        $goalStats['in_progress']++;
        if (!empty($goal['deadline']) && $goal['deadline'] < $today) {
            $goalStats['overdue']++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Goals Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Khmer:wght@400;500;600;700;800&display=swap');
        body { background: #f8fafc; font-family: 'Inter', sans-serif; }
        html[lang="kh"] body { font-family: 'Noto Sans Khmer', 'Inter', sans-serif; }
        .soft-card { border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 4px 12px rgba(0,0,0,.03); }
        .stat-mini { border-radius: 12px; border: 1px solid #e2e8f0; background: #fff; padding: 14px 16px; }

        /* Status theming: Completed = blue, Overdue = red, Active = neutral (subtle, thin edges) */
        .goal-card.completed { border-color: #bfdbfe !important; box-shadow: 0 1px 4px rgba(59,130,246,.08); }
        .goal-card.overdue  { border-color: #fecaca !important; box-shadow: 0 1px 4px rgba(220,38,38,.07); }
        .goal-card.completed .goal-accent { background: #3b82f6; }
        .goal-card.overdue .goal-accent  { background: #dc2626; }
        .goal-accent { height: 3px; border-radius: 8px 8px 0 0; background: #e2e8f0; }

        .badge-priority-High { background-color: #ef4444; }
        .badge-priority-Medium { background-color: #f59e0b; }
        .badge-priority-Low { background-color: #10b981; }
        .badge-completed { background-color: #3b82f6; }
        .badge-overdue { background-color: #dc2626; }
        .badge-locked { background-color: #64748b; }

        .goal-bar-completed { background-color: #3b82f6 !important; }
        .goal-bar-overdue { background-color: #dc2626 !important; }
        .text-overdue { color: #dc2626; }
        .text-completed { color: #2563eb; }
        .locked-flag { cursor: not-allowed; opacity: .55; pointer-events: none; }

        /* Notes: reading card + edit mode */
        .notes-card {
            background: #f8f9fb;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 18px 20px;
        }
        .notes-reading-text {
            font-size: 0.97rem;
            line-height: 1.4;
            color: #334155;
            white-space: pre-wrap;
        }
        .notes-reading-text br {
            line-height: 1.2;
        }
        .notes-empty {
            color: #94a3b8;
            font-style: italic;
        }
    </style>
</head>
<body>
<?php $activePage = 'goals'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="container py-5">

<?php if ($viewGoal): ?>
    <?php
        $vp = (int) $viewGoal['progress'];
        $vStatus = goal_status_class($viewGoal, $today);
        $vLocked = goal_is_locked($viewGoal, $today);
        $vDaysLeft = '';
        if (!empty($viewGoal['deadline'])) {
            $diff = (int) ((strtotime($viewGoal['deadline']) - strtotime($today)) / 86400);
            $vDaysLeft = $viewGoal['status'] === 'Completed'
                ? ''
                : ($diff < 0 ? (abs($diff) . ' day' . (abs($diff) === 1 ? '' : 's') . ' overdue') : ($diff . ' day' . ($diff === 1 ? '' : 's') . ' left'));
        }
        $hasNotes = trim((string) ($viewGoal['notes'] ?? '')) !== '';
    ?>
    <a href="goals.php" class="btn btn-sm btn-outline-secondary rounded-3 mb-3">
        <i class="bi bi-arrow-left me-1"></i>Back to Goals
    </a>
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card soft-card goal-card <?= $vStatus ?>">
                <div class="goal-accent"></div>
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                        <div>
                            <span class="badge bg-secondary opacity-75"><?= htmlspecialchars($viewGoal['category']) ?></span>
                            <?php if ($vStatus === 'completed'): ?>
                                <span class="badge badge-completed">Completed</span>
                            <?php elseif ($vStatus === 'overdue'): ?>
                                <span class="badge badge-overdue">Overdue</span>
                            <?php endif; ?>
                            <?php if ($vLocked): ?>
                                <span class="badge badge-locked"><i class="bi bi-lock-fill me-1"></i>Locked</span>
                            <?php endif; ?>
                            <span class="badge badge-priority-<?= htmlspecialchars($viewGoal['priority']) ?>"><?= htmlspecialchars($viewGoal['priority']) ?></span>
                        </div>
                        <span class="text-muted small">Added <?= htmlspecialchars(date('M j, Y', strtotime($viewGoal['created_at']))) ?></span>
                    </div>

                    <h3 class="fw-bold mb-2"><?= htmlspecialchars($viewGoal['goal_name']) ?></h3>

                    <div class="row g-3 mb-3">
                        <div class="col-6 col-md-3">
                            <div class="text-muted small text-uppercase fw-semibold">Target</div>
                            <div class="fs-5 fw-bold"><?= (int) $viewGoal['target_hours'] ?> hrs</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="text-muted small text-uppercase fw-semibold">Done</div>
                            <div class="fs-5 fw-bold"><?= (int) $viewGoal['completed_hours'] ?> hrs</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="text-muted small text-uppercase fw-semibold">Deadline</div>
                            <div class="fs-6 fw-bold <?= $vStatus === 'overdue' ? 'text-overdue' : '' ?>">
                                <?= $viewGoal['deadline'] ? htmlspecialchars($viewGoal['deadline']) : '<span class="text-muted">—</span>' ?>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="text-muted small text-uppercase fw-semibold">Status</div>
                            <div class="fs-6 fw-bold <?= $vStatus === 'completed' ? 'text-completed' : ($vStatus === 'overdue' ? 'text-overdue' : '') ?>">
                                <?= $vLocked ? ($vStatus === 'completed' ? 'Done' : 'Locked') : 'Active' ?>
                                <?php if ($vDaysLeft): ?><span class="small fw-normal text-muted">(<?= htmlspecialchars($vDaysLeft) ?>)</span><?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between small fw-bold mb-1">
                        <span>Progress</span>
                        <span><?= $vp ?>%</span>
                    </div>
                    <div class="progress mb-4" style="height:10px;">
                        <div class="progress-bar <?= $vStatus === 'completed' ? 'goal-bar-completed' : ($vStatus === 'overdue' ? 'goal-bar-overdue' : ($vp > 50 ? 'bg-info' : 'bg-warning')) ?>" style="width: <?= $vp ?>%;"></div>
                    </div>

                    <!-- Notes: Read mode -->
                    <div class="notes-card mb-3" id="notesViewWrap" style="<?= $hasNotes ? '' : 'display:none;' ?>">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="fw-bold mb-0"><i class="bi bi-journal-text me-1"></i>Notes / Words</h6>
                            <button type="button" class="btn btn-sm btn-outline-primary rounded-3" onclick="toggleNotesEdit(true)">
                                <i class="bi bi-pencil me-1"></i>Edit
                            </button>
                        </div>
                        <?php
                            $notesDisplay = trim((string) ($viewGoal['notes'] ?? ''));
                            $notesDisplay = str_replace(["\r\n", "\r"], "\n", $notesDisplay);
                            $notesDisplay = preg_replace('/\n{2,}/', "\n", $notesDisplay);
                        ?>
                        <div class="notes-reading-text"><?= $hasNotes ? nl2br(htmlspecialchars($notesDisplay)) : '' ?></div>
                    </div>

                    <!-- Notes: Edit mode -->
                    <div id="notesEditWrap" style="<?= $hasNotes ? 'display:none;' : '' ?>">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="fw-bold mb-0"><i class="bi bi-journal-text me-1"></i>Notes / Words</h6>
                            <?php if ($hasNotes): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary rounded-3" onclick="toggleNotesEdit(false)">Cancel</button>
                            <?php endif; ?>
                        </div>
                        <form method="post" class="mb-3">
                            <input type="hidden" name="save_goal_notes" value="1">
                            <input type="hidden" name="goal_id" value="<?= (int) $viewGoal['id'] ?>">
                            <textarea class="form-control rounded-3 mb-2" name="goal_notes" rows="10" style="min-height:240px;"
                                placeholder="Write your notes, reminders or words here..."><?= htmlspecialchars($viewGoal['notes'] ?? '') ?></textarea>
                            <div class="d-flex gap-2">
                                <button class="btn btn-sm btn-primary rounded-3"><i class="bi bi-save me-1"></i>Save Notes</button>
                                <?php if ($hasNotes): ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-3" onclick="toggleNotesEdit(false)">Cancel</button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>

                    <div class="d-flex flex-wrap gap-2 pt-3 border-top">
                        <?php if (!$vLocked): ?>
                            <a href="goals.php?edit=<?= (int) $viewGoal['id'] ?>" class="btn btn-sm btn-outline-primary rounded-3"><i class="bi bi-pencil me-1"></i>Edit</a>
                        <?php endif; ?>
                        <form method="post" onsubmit="return confirm('Delete this goal?');" class="m-0">
                            <input type="hidden" name="delete_goal" value="1">
                            <input type="hidden" name="goal_id" value="<?= (int) $viewGoal['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger rounded-3"><i class="bi bi-trash me-1"></i>Delete</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    function toggleNotesEdit(showEdit) {
        document.getElementById('notesViewWrap').style.display = showEdit ? 'none' : 'block';
        document.getElementById('notesEditWrap').style.display = showEdit ? 'block' : 'none';
    }
    </script>

<?php else: ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-1">Goals Tracker</h2>
            <p class="text-muted mb-0">Logged in as <?= htmlspecialchars($userName) ?></p>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Goal Summary Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-mini">
                <div class="text-muted small fw-semibold text-uppercase">Total Goals</div>
                <div class="fs-3 fw-bold"><?= $goalStats['total'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-mini">
                <div class="text-muted small fw-semibold text-uppercase">In Progress</div>
                <div class="fs-3 fw-bold text-info"><?= $goalStats['in_progress'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-mini">
                <div class="text-muted small fw-semibold text-uppercase">Completed</div>
                <div class="fs-3 fw-bold text-completed"><?= $goalStats['completed'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-mini">
                <div class="text-muted small fw-semibold text-uppercase">Overdue</div>
                <div class="fs-3 fw-bold text-overdue"><?= $goalStats['overdue'] ?></div>
            </div>
        </div>
    </div>

    <!-- Add / Edit Goal Form -->
    <div class="card soft-card mb-4">
        <div class="card-body">
            <h5 class="fw-bold mb-3"><?= $editGoal ? '<i class="bi bi-pencil-square me-1"></i>Edit Goal' : '<i class="bi bi-plus-circle me-1"></i>Add New Goal' ?></h5>
            <form method="post" class="row g-3">
                <input type="hidden" name="<?= $editGoal ? 'edit_goal' : 'add_goal' ?>" value="1">
                <?php if ($editGoal): ?>
                    <input type="hidden" name="goal_id" value="<?= (int) $editGoal['id'] ?>">
                <?php endif; ?>

                <div class="col-md-4">
                    <label class="form-label small text-muted">Goal Title</label>
                    <input class="form-control" name="goal_name" placeholder="e.g...." required value="<?= $editGoal ? htmlspecialchars($editGoal['goal_name']) : '' ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label small text-muted">Category</label>
                    <input class="form-control" name="category" placeholder="e.g. Study, Health, Work" value="<?= $editGoal ? htmlspecialchars($editGoal['category']) : '' ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label small text-muted">Priority</label>
                    <input class="form-control" name="priority" placeholder="e.g. High, Medium, Low" value="<?= $editGoal ? htmlspecialchars($editGoal['priority']) : '' ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label small text-muted">Target Hours</label>
                    <input class="form-control" type="number" name="target_hours" placeholder="e.g. 60" min="1" required value="<?= $editGoal ? (int) $editGoal['target_hours'] : '' ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label small text-muted">Deadline</label>
                    <input class="form-control" type="date" name="deadline" value="<?= $editGoal ? htmlspecialchars($editGoal['deadline'] ?? '') : '' ?>">
                </div>

                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary px-4"><?= $editGoal ? 'Save Changes' : 'Add Goal' ?></button>
                    <?php if ($editGoal): ?>
                        <a class="btn btn-outline-secondary" href="goals.php">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Goals Display Grid -->
    <div class="row g-3">
        <?php foreach ($goals as $goal):
            $progress = (int) $goal['progress'];
            $statusClass = goal_status_class($goal, $today);
            $locked = goal_is_locked($goal, $today);
            $priority = $goal['priority'] ?? 'Medium';
            $category = $goal['category'] ?? 'General';

            if ($statusClass === 'completed') {
                $barColor = 'goal-bar-completed';
            } elseif ($statusClass === 'overdue') {
                $barColor = 'goal-bar-overdue';
            } else {
                $barColor = $progress >= 100 ? 'bg-success' : ($progress > 50 ? 'bg-info' : 'bg-warning');
            }

            $daysLeft = '';
            if (!empty($goal['deadline'])) {
                $diff = (int) ((strtotime($goal['deadline']) - strtotime($today)) / 86400);
                $daysLeft = $goal['status'] === 'Completed'
                    ? ''
                    : ($statusClass === 'overdue' ? (abs($diff) . ' day' . (abs($diff) === 1 ? '' : 's') . ' overdue') : ($diff . ' day' . ($diff === 1 ? '' : 's') . ' left'));
            }

            $badgeClass = in_array($priority, ['High', 'Medium', 'Low']) ? "badge-priority-{$priority}" : "bg-primary";
        ?>
            <div class="col-12 col-md-6 col-lg-4">
                <div class="card soft-card goal-card <?= $statusClass ?> h-100">
                    <div class="goal-accent"></div>
                    <div class="card-body vstack">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="badge bg-secondary opacity-75"><?= htmlspecialchars($category) ?></span>
                            <span class="d-flex gap-1 align-items-center flex-wrap justify-content-end">
                                <?php if ($statusClass === 'completed'): ?>
                                    <span class="badge badge-completed"><i class="bi bi-check2-circle me-1"></i>Done</span>
                                <?php elseif ($statusClass === 'overdue'): ?>
                                    <span class="badge badge-overdue">Overdue</span>
                                <?php endif; ?>
                                <?php if ($locked): ?>
                                    <span class="badge badge-locked"><i class="bi bi-lock-fill"></i></span>
                                <?php endif; ?>
                                <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($priority) ?></span>
                            </span>
                        </div>

                        <h5 class="fw-bold mb-2"><?= htmlspecialchars($goal['goal_name']) ?></h5>

                        <div class="text-muted small mb-3">
                            <div>Target: <strong><?= (int) $goal['target_hours'] ?> hrs</strong> | Done: <strong><?= (int) $goal['completed_hours'] ?> hrs</strong></div>
                            <?php if ($goal['deadline']): ?>
                                <div class="<?= $statusClass === 'overdue' ? 'text-overdue fw-semibold' : '' ?>">
                                    Deadline: <?= htmlspecialchars($goal['deadline']) ?>
                                    <?php if ($daysLeft): ?>
                                        <span class="fw-semibold">(<?= htmlspecialchars($daysLeft) ?>)</span>
                                    <?php endif; ?>
                                </div>
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

                            <!-- Action Tools -->
                            <div class="d-flex gap-2 flex-wrap">
                                <a href="goals.php?view=<?= (int) $goal['id'] ?>" class="btn btn-sm btn-outline-secondary" title="View details & notes">
                                    <i class="bi bi-eye"></i> Details
                                </a>

                                <?php if (!$locked): ?>
                                    <a href="goals.php?edit=<?= (int) $goal['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit goal">
                                        <i class="bi bi-pencil"></i> Edit
                                    </a>
                                    <form method="post" class="d-flex gap-1 flex-grow-1">
                                        <input type="hidden" name="update_progress" value="1">
                                        <input type="hidden" name="goal_id" value="<?= (int) $goal['id'] ?>">
                                        <input type="number" class="form-control form-control-sm" name="add_hours" placeholder="+ Hours" min="1" required style="width: 80px;">
                                        <button class="btn btn-sm btn-outline-primary flex-grow-1">Log</button>
                                    </form>
                                <?php else: ?>
                                    <span class="btn btn-sm btn-secondary locked-flag" title="Locked (completed or past deadline)">
                                        <i class="bi bi-lock-fill me-1"></i>Locked
                                    </span>
                                <?php endif; ?>

                                <form method="post" onsubmit="return confirm('Delete this goal?')">
                                    <input type="hidden" name="delete_goal" value="1">
                                    <input type="hidden" name="goal_id" value="<?= (int) $goal['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
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

<?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>