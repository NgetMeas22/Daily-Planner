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

    $stmt = $conn->prepare('SELECT id, goal_name, category, priority, target_hours, completed_hours, progress, deadline, status, notes, created_at FROM goals WHERE id = ? AND user_id = ?');
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

    $stmt = $conn->prepare('SELECT id, goal_name, category, priority, target_hours, completed_hours, progress, deadline, status, notes, created_at FROM goals WHERE id = ? AND user_id = ?');
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
    $stmt = $conn->prepare('SELECT id, goal_name, category, priority, target_hours, completed_hours, progress, deadline, status, notes, created_at FROM goals WHERE id = ? AND user_id = ?');
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
    $stmt = $conn->prepare('SELECT id, goal_name, category, priority, target_hours, completed_hours, progress, deadline, status, notes, created_at FROM goals WHERE id = ? AND user_id = ?');
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
    SELECT id, goal_name, category, priority, target_hours, completed_hours, progress, deadline, status, notes, created_at
    FROM goals
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Khmer:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --paper: #F5F7FA;
            --surface: #FFFFFF;
            --ink: #1A1D2E;
            --ink-soft: #6B7190;
            --border: #E8EBF2;
            --accent: #0984E3;
            --accent-light: #0984E318;
            --c-completed: #2F6FED;   /* Done = Blue */
            --c-overdue: #FF6B6B;     /* Deadline passed = Red */
            --c-active: #98A2B8;      /* Not yet done = Gray */
            --shadow-completed: rgba(47,111,237,.28);
            --shadow-overdue: rgba(255,107,107,.32);
            --shadow-active: rgba(152,162,184,.28);
            --radius: 16px;
            --radius-sm: 10px;
            --transition: .22s cubic-bezier(.4,0,.2,1);
        }
        @keyframes fadeSlideUp { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }

        * { box-sizing: border-box; }
        body { background: var(--paper); font-family: 'Inter', sans-serif; color: var(--ink); margin: 0; }
        html[lang="kh"] body { font-family: 'Noto Sans Khmer', 'Inter', sans-serif; }

        /* ── Hero (compact, inset card — matches the Expenses page banner style) ── */
        .goals-hero {
            background: linear-gradient(135deg, #1a2744 0%, #6C5CE7 55%, #e0559f 100%);
            color: #fff;
            padding: 28px 32px;
            border-radius: var(--radius);
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 26px rgba(26,39,68,.22);
        }
        .goals-hero::after {
            content: '';
            position: absolute;
            top: -60%;
            right: -8%;
            width: 320px;
            height: 320px;
            border-radius: 50%;
            background: rgba(255,255,255,.06);
        }
        .goals-hero h1 { font-weight: 800; font-size: 1.6rem; margin-bottom: 4px; }
        .goals-hero p { opacity: .8; font-size: .9rem; margin-bottom: 0; }
        .hero-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255,255,255,.14);
            border: 1px solid rgba(255,255,255,.18);
            border-radius: 999px;
            padding: 6px 14px;
            font-size: .8rem;
            font-weight: 600;
            backdrop-filter: blur(4px);
            color: #fff;
            white-space: nowrap;
        }
        .hero-chip .chip-count {
            background: rgba(255,255,255,.22);
            border-radius: 999px;
            padding: 2px 10px;
            font-size: .76rem;
            font-weight: 700;
        }

        /* ── Add / Edit Form Card (collapsible) ── */
        .add-form-card {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            box-shadow: 0 2px 12px rgba(0,0,0,.05);
            margin-bottom: 16px; /* tightened so the stat cards sit closer beneath it */
            overflow: hidden;
            transition: box-shadow var(--transition), border-color var(--transition);
        }
        .add-form-card:hover { box-shadow: 0 4px 18px rgba(0,0,0,.07); }
        .add-form-toggle {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 24px;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 700;
            font-size: 1rem;
            color: var(--ink);
            transition: background var(--transition);
        }
        .add-form-toggle:hover { background: var(--accent-light); }
        .add-form-toggle .toggle-icon { transition: transform var(--transition); font-size: .85rem; color: var(--ink-soft); }
        .add-form-toggle.active .toggle-icon { transform: rotate(180deg); }
        .add-form-body {
            max-height: 0;
            overflow: hidden;
            transition: max-height .35s cubic-bezier(.4,0,.2,1), padding .35s;
            padding: 0 24px;
        }
        .add-form-body.show { max-height: 600px; padding: 0 24px 20px; }

        /* ── Stat Cards ── */
        .stat-card {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            padding: 20px 22px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,.04);
            transition: box-shadow var(--transition), transform var(--transition), border-color var(--transition);
        }
        .stat-card:hover { box-shadow: 0 6px 22px rgba(0,0,0,.08); transform: translateY(-2px); border-color: #d0d5e2; }
        .stat-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 5px;
            border-radius: var(--radius) 0 0 var(--radius);
        }
        .stat-card.stat-total::before { background: var(--accent); }
        .stat-card.stat-active::before { background: var(--c-active); }
        .stat-card.stat-completed::before { background: var(--c-completed); }
        .stat-card.stat-overdue::before { background: var(--c-overdue); }
        .stat-card .stat-label { font-size: .78rem; text-transform: uppercase; font-weight: 600; letter-spacing: .03em; color: var(--ink-soft); margin-bottom: 6px; }
        .stat-card .stat-value { font-size: 1.7rem; font-weight: 800; line-height: 1; }
        .stat-card .stat-value.text-accent { color: var(--accent); }
        .stat-card .stat-value.text-completed { color: var(--c-completed); }
        .stat-card .stat-value.text-overdue { color: var(--c-overdue); }
        .stat-card .stat-value.text-active { color: var(--c-active); }

        /* ── Goal Card ── */
        .goal-card {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            box-shadow: 0 2px 10px rgba(0,0,0,.04);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: box-shadow var(--transition), transform var(--transition), border-color var(--transition), background var(--transition);
        }
        .goal-card:hover { box-shadow: 0 8px 28px rgba(0,0,0,.1); transform: translateY(-3px); border-color: #d0d5e2; }
        .goal-card .goal-accent {
            height: 4px;
            background: var(--border);
            transition: background var(--transition);
        }
        /* Card background reflects status: red = overdue, blue = done, gray = not yet done */
        .goal-card.completed { background: var(--surface); border-color: var(--border); box-shadow: 0 3px 10px rgba(47,111,237,.14), 0 10px 24px rgba(47,111,237,.18); }
        .goal-card.overdue   { background: var(--surface); border-color: var(--border); box-shadow: 0 3px 10px rgba(255,107,107,.16), 0 10px 24px rgba(255,107,107,.2); }
        .goal-card.active    { background: var(--surface); border-color: var(--border); box-shadow: 0 3px 10px rgba(152,162,184,.14), 0 10px 24px rgba(152,162,184,.16); }
        .goal-card.completed:hover { box-shadow: 0 5px 14px rgba(47,111,237,.18), 0 16px 32px rgba(47,111,237,.24); }
        .goal-card.overdue:hover   { box-shadow: 0 5px 14px rgba(255,107,107,.2), 0 16px 32px rgba(255,107,107,.26); }
        .goal-card.active:hover    { box-shadow: 0 5px 14px rgba(152,162,184,.18), 0 16px 32px rgba(152,162,184,.2); }
        .goal-card.completed .goal-accent { background: var(--c-completed); }
        .goal-card.overdue .goal-accent { background: var(--c-overdue); }
        .goal-card.active .goal-accent { background: var(--c-active); }
        .goal-card .card-body { border: none; }

        /* ── Badges ── */
        .badge-priority-High { background-color: #FF6B6B; color: #fff; }
        .badge-priority-Medium { background-color: #FDCB6E; color: #1A1D2E; }
        .badge-priority-Low { background-color: #00B894; color: #fff; }
        .badge-completed { background-color: var(--c-completed); color: #fff; }
        .badge-overdue { background-color: var(--c-overdue); color: #fff; }
        .badge-locked { background-color: #A0A5BD; color: #fff; }

        /* ── Progress Bars ── */
        .goal-bar-completed { background-color: var(--c-completed) !important; }
        .goal-bar-overdue { background-color: var(--c-overdue) !important; }
        .text-overdue { color: var(--c-overdue) !important; }
        .text-completed { color: var(--c-completed) !important; }

        .locked-flag { cursor: not-allowed; opacity: .55; pointer-events: none; }

        /* ── Card action row: bigger buttons that stay on one line ── */
        .card-actions-row {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: nowrap;
            overflow-x: auto;
            padding-bottom: 2px;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        .card-actions-row::-webkit-scrollbar { display: none; }
        .card-actions-row form { display: flex; align-items: center; gap: 6px; flex-shrink: 0; margin: 0; }
        .card-actions-row .hours-input {
            width: 72px;
            flex-shrink: 0;
            font-size: .82rem !important;
            padding: 7px 8px !important;
            border-radius: 9px !important;
        }

        /* ── Notes Card ── */
        .notes-card {
            background: var(--paper);
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 20px 22px;
        }
        .notes-reading-text {
            font-size: .95rem;
            line-height: 1.55;
            color: var(--ink);
            white-space: pre-wrap;
        }
        .notes-reading-text br { line-height: 1.2; }
        .notes-empty { color: var(--ink-soft); font-style: italic; }

        /* ── Buttons ── */
        .btn-accent {
            background: linear-gradient(135deg, #0984E3, #6C5CE7);
            color: #fff;
            border: none;
            border-radius: var(--radius-sm);
            font-weight: 600;
            padding: 10px 24px;
            transition: box-shadow var(--transition), transform var(--transition);
        }
        .btn-accent:hover { box-shadow: 0 4px 14px rgba(9,132,227,.35); transform: translateY(-1px); color: #fff; }
        .btn-card-action {
            border-radius: 9px;
            font-weight: 600;
            font-size: .8rem;
            padding: 7px 13px;
            transition: all var(--transition);
            white-space: nowrap;
            flex-shrink: 0;
            font-family: 'Inter', sans-serif; /* keep label text from inheriting the bi icon font */
            display: inline-flex;
            align-items: center;
        }
        html[lang="kh"] .btn-card-action { font-family: 'Noto Sans Khmer', 'Inter', sans-serif; }

        /* ── Empty State ── */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        .empty-state svg { width: 120px; height: 120px; margin-bottom: 20px; opacity: .45; }
        .empty-state h5 { font-weight: 700; color: var(--ink); }
        .empty-state p { color: var(--ink-soft); font-size: .93rem; }

        /* ── Dark Theme ── */
        body[data-theme="dark"] { background: #0b1120 !important; color: #e2e8f0 !important; }
        body[data-theme="dark"] .goals-hero { background: linear-gradient(135deg, #0b1120 0%, #3d2c6b 55%, #7a2e58 100%); }
        body[data-theme="dark"] .add-form-card,
        body[data-theme="dark"] .stat-card,
        body[data-theme="dark"] .notes-card,
        body[data-theme="dark"] .card {
            background: #111827 !important;
            color: #e2e8f0 !important;
            border-color: #243047 !important;
        }
        body[data-theme="dark"] .goal-card { background: #111827 !important; color: #e2e8f0 !important; border-color: #243047 !important; }
        body[data-theme="dark"] .goal-card.completed { background: #111827 !important; border-color: #243047 !important; box-shadow: 0 4px 14px rgba(47,111,237,.16), 0 14px 34px rgba(47,111,237,.14) !important; }
        body[data-theme="dark"] .goal-card.overdue { background: #111827 !important; border-color: #243047 !important; box-shadow: 0 4px 14px rgba(255,107,107,.18), 0 14px 34px rgba(255,107,107,.16) !important; }
        body[data-theme="dark"] .goal-card.active { background: #111827 !important; border-color: #243047 !important; box-shadow: 0 4px 14px rgba(152,162,184,.1), 0 14px 34px rgba(152,162,184,.1) !important; }
        body[data-theme="dark"] .goal-card.completed:hover { box-shadow: 0 6px 18px rgba(47,111,237,.22), 0 18px 40px rgba(47,111,237,.2) !important; }
        body[data-theme="dark"] .goal-card.overdue:hover { box-shadow: 0 6px 18px rgba(255,107,107,.24), 0 18px 40px rgba(255,107,107,.22) !important; }
        body[data-theme="dark"] .goal-card.active:hover { box-shadow: 0 6px 18px rgba(152,162,184,.16), 0 18px 40px rgba(152,162,184,.14) !important; }
        body[data-theme="dark"] .stat-card { box-shadow: 0 2px 10px rgba(0,0,0,.2); }
        body[data-theme="dark"] .text-muted,
        body[data-theme="dark"] .small,
        body[data-theme="dark"] .form-label {
            color: #94a3b8 !important;
        }
        body[data-theme="dark"] .badge.bg-secondary {
            background: #1e293b !important;
            color: #e2e8f0 !important;
        }
        body[data-theme="dark"] .form-control {
            background: #0f172a !important;
            color: #e2e8f0 !important;
            border-color: #334155 !important;
        }
        body[data-theme="dark"] .add-form-toggle { color: #e2e8f0; }
        body[data-theme="dark"] .add-form-toggle:hover { background: rgba(9,132,227,.12); }
        body[data-theme="dark"] .notes-card { background: #0f172a !important; }
        body[data-theme="dark"] .notes-reading-text { color: #cbd5e1; }
    </style>
</head>
<body data-theme="<?php echo htmlspecialchars(current_theme()); ?>">
<?php $activePage = 'goals'; include __DIR__ . '/includes/navbar.php'; ?>

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
    $vShadow = $vStatus === 'completed' ? 'var(--shadow-completed)' : ($vStatus === 'overdue' ? 'var(--shadow-overdue)' : 'var(--shadow-active)');
?>

<div class="container py-4" style="max-width:820px;">
    <a href="goals.php" class="btn btn-sm btn-outline-secondary rounded-3 mb-3" style="border-radius:var(--radius-sm);">
        <i class="bi bi-arrow-left me-1"></i>Back to Goals
    </a>

    <div class="card" style="border-radius:var(--radius);border:1.5px solid var(--border);background:var(--surface);overflow:hidden;box-shadow:0 10px 28px <?= $vShadow ?>;animation:fadeSlideUp .4s ease;">
        <div class="goal-accent" style="height:4px;background:linear-gradient(90deg,<?php echo $vStatus==='completed'?'var(--c-completed)':($vStatus==='overdue'?'var(--c-overdue)':'var(--c-active)'); ?>,<?php echo $vStatus==='completed'?'#6C9CFF':($vStatus==='overdue'?'#ff8787':'#c3c9d8'); ?>);"></div>
        <div class="card-body p-4 p-md-5">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div class="d-flex flex-wrap gap-2">
                    <span class="badge bg-secondary opacity-75" style="border-radius:999px;font-size:.78rem;padding:5px 12px;"><?= htmlspecialchars($viewGoal['category']) ?></span>
                    <?php if ($vStatus === 'completed'): ?>
                        <span class="badge badge-completed" style="border-radius:999px;font-size:.78rem;padding:5px 12px;">Completed</span>
                    <?php elseif ($vStatus === 'overdue'): ?>
                        <span class="badge badge-overdue" style="border-radius:999px;font-size:.78rem;padding:5px 12px;">Overdue</span>
                    <?php endif; ?>
                    <?php if ($vLocked): ?>
                        <span class="badge badge-locked" style="border-radius:999px;font-size:.78rem;padding:5px 12px;"><i class="bi bi-lock-fill me-1"></i>Locked</span>
                    <?php endif; ?>
                    <span class="badge badge-priority-<?= htmlspecialchars($viewGoal['priority']) ?>" style="border-radius:999px;font-size:.78rem;padding:5px 12px;"><?= htmlspecialchars($viewGoal['priority']) ?></span>
                </div>
                <span class="text-muted small">Added <?= htmlspecialchars(date('M j, Y', strtotime($viewGoal['created_at']))) ?></span>
            </div>

            <h3 class="fw-bold mb-3" style="font-size:1.45rem;color:var(--ink);"><?= htmlspecialchars($viewGoal['goal_name']) ?></h3>

            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="text-muted small text-uppercase fw-semibold" style="letter-spacing:.04em;">Target</div>
                    <div class="fs-5 fw-bold" style="color:var(--ink);"><?= (int) $viewGoal['target_hours'] ?> hrs</div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-muted small text-uppercase fw-semibold" style="letter-spacing:.04em;">Done</div>
                    <div class="fs-5 fw-bold" style="color:var(--ink);"><?= (int) $viewGoal['completed_hours'] ?> hrs</div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-muted small text-uppercase fw-semibold" style="letter-spacing:.04em;">Deadline</div>
                    <div class="fs-6 fw-bold <?= $vStatus === 'overdue' ? 'text-overdue' : '' ?>">
                        <?= $viewGoal['deadline'] ? htmlspecialchars($viewGoal['deadline']) : '<span class="text-muted">—</span>' ?>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-muted small text-uppercase fw-semibold" style="letter-spacing:.04em;">Status</div>
                    <div class="fs-6 fw-bold <?= $vStatus === 'completed' ? 'text-completed' : ($vStatus === 'overdue' ? 'text-overdue' : '') ?>">
                        <?= $vLocked ? ($vStatus === 'completed' ? 'Done' : 'Locked') : 'Active' ?>
                        <?php if ($vDaysLeft): ?><span class="small fw-normal text-muted">(<?= htmlspecialchars($vDaysLeft) ?>)</span><?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-between small fw-bold mb-1">
                <span style="color:var(--ink);">Progress</span>
                <span style="color:var(--ink);"><?= $vp ?>%</span>
            </div>
            <div class="progress mb-4" style="height:10px;border-radius:999px;background:var(--border);">
                <div class="progress-bar <?= $vStatus === 'completed' ? 'goal-bar-completed' : ($vStatus === 'overdue' ? 'goal-bar-overdue' : ($vp > 50 ? 'bg-info' : 'bg-warning')) ?>" style="width: <?= $vp ?>%;border-radius:999px;transition:width .5s ease;"></div>
            </div>

            <!-- Notes: Read mode -->
            <div class="notes-card mb-3" id="notesViewWrap" style="<?= $hasNotes ? '' : 'display:none;' ?>">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="fw-bold mb-0" style="color:var(--ink);"><i class="bi bi-journal-text me-1"></i>Notes / Words</h6>
                    <button type="button" class="btn btn-card-action" style="border:1px solid var(--border);color:var(--accent);background:var(--accent-light);" onclick="toggleNotesEdit(true)">
                        <i class="bi bi-pencil"></i> Edit
                    </button>
                </div>
                <?php
                    $notesDisplay = trim((string) ($viewGoal['notes'] ?? ''));
                    $notesDisplay = str_replace(["\r\n", "\r"], "\n", $notesDisplay);
                    $notesDisplay = preg_replace('/\n[ \t]*\n+/', "\n", $notesDisplay);
                ?>
                <div class="notes-reading-text"><?= $hasNotes ? nl2br(htmlspecialchars($notesDisplay)) : '' ?></div>
            </div>

            <!-- Notes: Edit mode -->
            <div id="notesEditWrap" style="<?= $hasNotes ? 'display:none;' : '' ?>">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="fw-bold mb-0" style="color:var(--ink);"><i class="bi bi-journal-text me-1"></i>Notes / Words</h6>
                    <?php if ($hasNotes): ?>
                        <button type="button" class="btn btn-card-action" style="border:1px solid var(--border);color:var(--ink-soft);" onclick="toggleNotesEdit(false)">Cancel</button>
                    <?php endif; ?>
                </div>
                <form method="post" class="mb-3">
                    <input type="hidden" name="save_goal_notes" value="1">
                    <input type="hidden" name="goal_id" value="<?= (int) $viewGoal['id'] ?>">
                    <textarea class="form-control mb-2" name="goal_notes" rows="10" style="min-height:240px;border-radius:var(--radius-sm);border-color:var(--border);"
                        placeholder="Write your notes, reminders or words here..."><?= htmlspecialchars($viewGoal['notes'] ?? '') ?></textarea>
                    <div class="d-flex gap-2">
                        <button class="btn btn-accent" style="border-radius:var(--radius-sm);font-size:.82rem;padding:6px 18px;"><i class="bi bi-save me-1"></i>Save Notes</button>
                        <?php if ($hasNotes): ?>
                            <button type="button" class="btn btn-card-action" style="border:1px solid var(--border);color:var(--ink-soft);" onclick="toggleNotesEdit(false)">Cancel</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="card-actions-row pt-3 border-top" style="border-color:var(--border) !important;">
                <?php if (!$vLocked): ?>
                    <a href="goals.php?edit=<?= (int) $viewGoal['id'] ?>" class="btn btn-card-action" style="border:1px solid var(--accent);color:var(--accent);"><i class="bi bi-pencil"></i> Edit</a>
                <?php endif; ?>
                <form method="post" onsubmit="return confirm('Delete this goal?');">
                    <input type="hidden" name="delete_goal" value="1">
                    <input type="hidden" name="goal_id" value="<?= (int) $viewGoal['id'] ?>">
                    <button class="btn btn-card-action" style="border:1px solid var(--c-overdue);color:var(--c-overdue);"><i class="bi bi-trash"></i> Delete</button>
                </form>
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

<div class="container" style="padding-top:22px;">
    <div class="goals-hero">
        <div style="position:relative;z-index:1;" class="d-flex justify-content-between align-items-end flex-wrap gap-3">
            <div>
                <h1 class="mb-1">Goals Tracker</h1>
                <p class="mb-0">Track your goals, log your hours, reach your targets.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <span class="hero-chip"><i class="bi bi-bullseye"></i> Total <span class="chip-count"><?= $goalStats['total'] ?></span></span>
                <span class="hero-chip"><i class="bi bi-play-circle"></i> In Progress <span class="chip-count"><?= $goalStats['in_progress'] ?></span></span>
                <span class="hero-chip"><i class="bi bi-check-circle"></i> Done <span class="chip-count"><?= $goalStats['completed'] ?></span></span>
                <span class="hero-chip"><i class="bi bi-exclamation-triangle"></i> Overdue <span class="chip-count"><?= $goalStats['overdue'] ?></span></span>
            </div>
        </div>
    </div>
</div>

<div class="container" style="margin-top:18px;">

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2" style="border-radius:var(--radius-sm);"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success py-2" style="border-radius:var(--radius-sm);"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Add / Edit Goal Form -->
    <div class="add-form-card">
        <button class="add-form-toggle <?= $editGoal ? 'active' : '' ?>" onclick="toggleAddForm(this)" type="button">
            <span><?= $editGoal ? '<i class="bi bi-pencil-square me-2"></i>Edit Goal' : '<i class="bi bi-plus-circle me-2"></i>Add New Goal' ?></span>
            <i class="bi bi-chevron-down toggle-icon"></i>
        </button>
        <div class="add-form-body <?= $editGoal ? 'show' : '' ?>" id="addFormBody">
            <form method="post" class="row g-3">
                <input type="hidden" name="<?= $editGoal ? 'edit_goal' : 'add_goal' ?>" value="1">
                <?php if ($editGoal): ?>
                    <input type="hidden" name="goal_id" value="<?= (int) $editGoal['id'] ?>">
                <?php endif; ?>

                <div class="col-md-4">
                    <label class="form-label small fw-semibold" style="color:var(--ink-soft);">Goal Title</label>
                    <input class="form-control" name="goal_name" placeholder="e.g. Learn React" required value="<?= $editGoal ? htmlspecialchars($editGoal['goal_name']) : '' ?>" style="border-radius:var(--radius-sm);border-color:var(--border);">
                </div>

                <div class="col-md-4">
                    <label class="form-label small fw-semibold" style="color:var(--ink-soft);">Category</label>
                    <input class="form-control" name="category" placeholder="e.g. Study, Health, Work" value="<?= $editGoal ? htmlspecialchars($editGoal['category']) : '' ?>" style="border-radius:var(--radius-sm);border-color:var(--border);">
                </div>

                <div class="col-md-4">
                    <label class="form-label small fw-semibold" style="color:var(--ink-soft);">Priority</label>
                    <input class="form-control" name="priority" placeholder="e.g. High, Medium, Low" value="<?= $editGoal ? htmlspecialchars($editGoal['priority']) : '' ?>" style="border-radius:var(--radius-sm);border-color:var(--border);">
                </div>

                <div class="col-md-3">
                    <label class="form-label small fw-semibold" style="color:var(--ink-soft);">Target Hours</label>
                    <input class="form-control" type="number" name="target_hours" placeholder="e.g. 60" min="1" required value="<?= $editGoal ? (int) $editGoal['target_hours'] : '' ?>" style="border-radius:var(--radius-sm);border-color:var(--border);">
                </div>

                <div class="col-md-3">
                    <label class="form-label small fw-semibold" style="color:var(--ink-soft);">Deadline</label>
                    <input class="form-control" type="date" name="deadline" value="<?= $editGoal ? htmlspecialchars($editGoal['deadline'] ?? '') : '' ?>" style="border-radius:var(--radius-sm);border-color:var(--border);">
                </div>

                <div class="col-12 d-flex gap-2 align-items-end">
                    <button class="btn btn-accent px-4"><?= $editGoal ? '<i class="bi bi-check-lg me-1"></i>Save Changes' : '<i class="bi bi-plus-lg me-1"></i>Add Goal' ?></button>
                    <?php if ($editGoal): ?>
                        <a class="btn btn-card-action" href="goals.php" style="border:1px solid var(--border);color:var(--ink-soft);">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card stat-total">
                <div class="stat-label">Total Goals</div>
                <div class="stat-value text-accent"><?= $goalStats['total'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card stat-active">
                <div class="stat-label">In Progress</div>
                <div class="stat-value text-active"><?= $goalStats['in_progress'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card stat-completed">
                <div class="stat-label">Completed</div>
                <div class="stat-value text-completed"><?= $goalStats['completed'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card stat-overdue">
                <div class="stat-label">Overdue</div>
                <div class="stat-value text-overdue"><?= $goalStats['overdue'] ?></div>
            </div>
        </div>
    </div>

    <!-- Goals Grid -->
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
            <div class="col-12 col-md-6 col-lg-4" style="animation:fadeSlideUp .4s ease;">
                <div class="goal-card <?= $statusClass ?> h-100">
                    <div class="goal-accent"></div>
                    <div class="card-body vstack" style="padding:22px;">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="badge bg-secondary opacity-75" style="border-radius:999px;font-size:.76rem;padding:4px 10px;"><?= htmlspecialchars($category) ?></span>
                            <span class="d-flex gap-1 align-items-center flex-wrap justify-content-end">
                                <?php if ($statusClass === 'completed'): ?>
                                    <span class="badge badge-completed" style="border-radius:999px;font-size:.76rem;padding:4px 10px;"><i class="bi bi-check2-circle me-1"></i>Done</span>
                                <?php elseif ($statusClass === 'overdue'): ?>
                                    <span class="badge badge-overdue" style="border-radius:999px;font-size:.76rem;padding:4px 10px;">Overdue</span>
                                <?php endif; ?>
                                <?php if ($locked): ?>
                                    <span class="badge badge-locked" style="border-radius:999px;font-size:.76rem;padding:4px 10px;"><i class="bi bi-lock-fill"></i></span>
                                <?php endif; ?>
                                <span class="badge <?= $badgeClass ?>" style="border-radius:999px;font-size:.76rem;padding:4px 10px;"><?= htmlspecialchars($priority) ?></span>
                            </span>
                        </div>

                        <h5 class="fw-bold mb-2" style="font-size:1.05rem;color:var(--ink);"><?= htmlspecialchars($goal['goal_name']) ?></h5>

                        <div class="small mb-3" style="color:var(--ink-soft);">
                            <div>Target: <strong style="color:var(--ink);"><?= (int) $goal['target_hours'] ?> hrs</strong> &middot; Done: <strong style="color:var(--ink);"><?= (int) $goal['completed_hours'] ?> hrs</strong></div>
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
                                <span style="color:var(--ink);">Progress</span>
                                <span style="color:var(--ink);"><?= $progress ?>%</span>
                            </div>
                            <div class="progress mb-3" style="height:8px;border-radius:999px;background:var(--border);">
                                <div class="progress-bar <?= $barColor ?>" style="width: <?= $progress ?>%;border-radius:999px;transition:width .5s ease;"></div>
                            </div>

                          <div class="card-actions-row d-flex align-items-center flex-wrap" style="gap: 8px;">
                                <a href="goals.php?view=<?= (int) $goal['id'] ?>" class="btn btn-card-action d-inline-flex align-items-center" style="border:1px solid var(--border);color:var(--accent);background:var(--accent-light);" title="View details">
                                    <i class="bi bi-eye me-1"></i>View 
                                </a>

                                <?php if (!$locked): ?>
                                    <a href="goals.php?edit=<?= (int) $goal['id'] ?>" class="btn btn-card-action d-inline-flex align-items-center" style="border:1px solid var(--border);color:var(--ink-soft);" title="Edit">
                                        <i class="bi bi-pencil me-1"></i>Edit
                                    </a>
                                    
                                    <form method="post" class="d-inline-flex align-items-center m-0" style="gap: 8px;">
                                        <input type="hidden" name="update_progress" value="1">
                                        <input type="hidden" name="goal_id" value="<?= (int) $goal['id'] ?>">
                                        <input type="number" class="form-control form-control-sm hours-input" name="add_hours" placeholder="+Hrs" min="1" required style="width: 65px;">
                                        <button type="submit" class="btn btn-card-action d-inline-flex align-items-center" style="background:linear-gradient(135deg,#0984E3,#6C5CE7);color:#fff;border:none;">Log</button>
                                    </form>
                                <?php else: ?>
                                    <span class="btn btn-card-action locked-flag d-inline-flex align-items-center" style="border:1px solid var(--border);color:var(--ink-soft);background:var(--paper);" title="Locked">
                                        <i class="bi bi-lock-fill me-1"></i>Locked
                                    </span>
                                <?php endif; ?>

                                <form method="post" onsubmit="return confirm('Delete this goal?')" class="d-inline-flex align-items-center m-0">
                                    <input type="hidden" name="delete_goal" value="1">
                                    <input type="hidden" name="goal_id" value="<?= (int) $goal['id'] ?>">
                                    <button type="submit" class="btn btn-card-action d-inline-flex align-items-center" style="border:1px solid var(--c-overdue);color:var(--c-overdue);" title="Delete">
                                        <i class="bi bi-trash me-1"></i>Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (!$goals): ?>
            <div class="col-12">
                <div class="empty-state">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.2" stroke="currentColor" style="color:var(--ink-soft);">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                    </svg>
                    <h5>No goals yet</h5>
                    <p>Create your first goal and start tracking your progress!</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<script>
function toggleAddForm(btn) {
    var body = document.getElementById('addFormBody');
    btn.classList.toggle('active');
    body.classList.toggle('show');
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>