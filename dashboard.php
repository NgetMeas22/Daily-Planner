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

    // Insert Planner
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

// ---- All dashboard stats in ONE round-trip ----
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');
$stmt = $conn->prepare("
    SELECT
      (SELECT COUNT(*) FROM subjects WHERE user_id = ?) AS subjects,
      (SELECT COUNT(*) FROM planner  WHERE user_id = ?) AS planner,
      (SELECT COUNT(*) FROM goals    WHERE user_id = ?) AS goals,
      (SELECT COUNT(*) FROM expenses WHERE user_id = ?) AS expenses,
      (SELECT COUNT(DISTINCT subject_id) FROM planner WHERE user_id = ?) AS subjects_used,
      (SELECT COUNT(*) FROM planner WHERE user_id = ? AND (progress >= 100 OR status = 'Completed')) AS planner_done,
      (SELECT COUNT(*) FROM goals    WHERE user_id = ? AND status = 'Completed') AS goals_done,
      (SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE user_id = ? AND type = 'expense' AND expense_date BETWEEN ? AND ?) AS spent,
      (SELECT COALESCE(monthly_budget, 0) FROM users WHERE id = ?) AS budget
");
$stmt->bind_param('iiiiiiiissi', $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $monthStart, $monthEnd, $userId);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

$counts = [
    'subjects' => (int) ($stats['subjects'] ?? 0),
    'planner'  => (int) ($stats['planner'] ?? 0),
    'goals'    => (int) ($stats['goals'] ?? 0),
    'expenses' => (int) ($stats['expenses'] ?? 0),
];

$subjectsUsed = (int) ($stats['subjects_used'] ?? 0);
$subjectsPct = $counts['subjects'] > 0 ? (int) round(($subjectsUsed / $counts['subjects']) * 100) : 0;

$plannerDone = (int) ($stats['planner_done'] ?? 0);
$plannerPct = $counts['planner'] > 0 ? (int) round(($plannerDone / $counts['planner']) * 100) : 0;

$goalsDone = (int) ($stats['goals_done'] ?? 0);
$goalsPct = $counts['goals'] > 0 ? (int) round(($goalsDone / $counts['goals']) * 100) : 0;

$budget = (float) ($stats['budget'] ?? 0);
$spentThisMonth = (float) ($stats['spent'] ?? 0);

// Account for leftover money carried over from the previous month (matches the
// expenses page's rollover): effective budget = base monthly budget + carry_in.
$baseBudget = $budget;
$nowMonth = date('Y-m');
$bmStmt = $conn->prepare('SELECT base_budget, carry_in FROM budget_months WHERE user_id = ? AND budget_month = ?');
$bmStmt->bind_param('is', $userId, $nowMonth);
$bmStmt->execute();
$bmRow = $bmStmt->get_result()->fetch_assoc();
if ($bmRow) {
    $budget = (float) $bmRow['base_budget'] + (float) $bmRow['carry_in'];
} else {
    // No tracked row yet — fall back to the user's base monthly budget.
    $budget = $baseBudget;
}
if ($budget <= 0) {
    $budget = $baseBudget;
}
$expensesPct = $budget > 0 ? (int) round(($spentThisMonth / $budget) * 100) : 0;

$ringOffset = fn($pct) => (string) round(113 - 113 * $pct / 100, 1);
$pctText = fn($pct) => $pct . '%';
$overBudget = $expensesPct >= 100;

// Fetch recent records (kept small with LIMIT 5 and now using prepared statements).
// Only the lightweight stat-cards + recent lists render server-side; the heavier
// chart aggregations are lazy-loaded from api/dashboard_charts.php via fetch().
$stmt = $conn->prepare("SELECT id, name, description FROM subjects WHERE user_id = ? ORDER BY id DESC LIMIT 5");
$stmt->bind_param('i', $userId);
$stmt->execute();
$subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("SELECT id, goal_name, target_hours, progress, deadline FROM goals WHERE user_id = ? ORDER BY id DESC LIMIT 5");
$stmt->bind_param('i', $userId);
$stmt->execute();
$goals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("SELECT id, title, category, amount, expense_date FROM expenses WHERE user_id = ? ORDER BY id DESC LIMIT 5");
$stmt->bind_param('i', $userId);
$stmt->execute();
$expenses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("
    SELECT p.id, p.study_date, p.start_time, p.end_time, p.topic, s.name AS subject_name
    FROM planner p INNER JOIN subjects s ON s.id = p.subject_id
    WHERE p.user_id = ? ORDER BY p.id DESC LIMIT 5
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$plannerRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$allSubjects = [];
$stmt = $conn->prepare("SELECT id, name FROM subjects WHERE user_id = ? ORDER BY name ASC");
$stmt->bind_param('i', $userId);
$stmt->execute();
$allSubjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Initial range only selects which range button is active and which range the
// browser fetches from the chart endpoint. No server-side aggregation happens
// here for the charts anymore.
$studyRange = $_GET['range'] ?? 'week';
if (!in_array($studyRange, ['day', 'week', 'month'], true)) {
    $studyRange = 'week';
}

require_once __DIR__ . '/includes/layout.php';

$extraHead = '
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"
    onerror="this.onerror=null;var s=document.createElement(\'script\');s.src=\'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js\';document.head.appendChild(s);"></script>
';

layout_header('Dashboard', 'dashboard', $extraHead);
?>

<div class="dashboard-page">
    <!-- Hero Banner -->
    <div class="hero-banner">
        <div>
            <h1 class="hero-greeting">Hello, <?= htmlspecialchars($userName) ?> 👋</h1>
            <p class="hero-sub">Here's your study progress and daily overview at a glance.</p>
        </div>
        <span class="hero-pill-date">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                <line x1="16" y1="2" x2="16" y2="6"></line>
                <line x1="8" y1="2" x2="8" y2="6"></line>
                <line x1="3" y1="10" x2="21" y2="10"></line>
            </svg>
            <?= date('l, j M Y') ?>
        </span>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2 anim-up anim-up-1"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
    <?php endif; ?>
    <?php if ($message): ?>
        <div class="alert alert-success py-2 anim-up anim-up-1"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Stat Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3 anim-up anim-up-1">
            <div class="stat-card stat-subjects">
                <div class="stat-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                </div>
                <div class="stat-info flex-grow-1 min-w-0">
                    <div class="stat-label">Subjects</div>
                    <div class="stat-value"><?= $counts['subjects'] ?></div>
                    <div class="stat-sub"><?= $subjectsUsed ?> used in planner</div>
                </div>
                <div class="stat-progress-ring">
                    <svg viewBox="0 0 42 42">
                        <circle class="bg-circle" cx="21" cy="21" r="18"/>
                        <circle class="val-circle" cx="21" cy="21" r="18" style="stroke-dashoffset: <?= $ringOffset($subjectsPct) ?>;"/>
                    </svg>
                    <span class="stat-progress-text"><?= $pctText($subjectsPct) ?></span>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3 anim-up anim-up-2">
            <div class="stat-card stat-planner">
                <div class="stat-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                </div>
                <div class="stat-info flex-grow-1 min-w-0">
                    <div class="stat-label">Planner</div>
                    <div class="stat-value"><?= $counts['planner'] ?></div>
                    <div class="stat-sub"><?= $plannerDone ?> tasks done</div>
                </div>
                <div class="stat-progress-ring">
                    <svg viewBox="0 0 42 42">
                        <circle class="bg-circle" cx="21" cy="21" r="18"/>
                        <circle class="val-circle" cx="21" cy="21" r="18" style="stroke-dashoffset: <?= $ringOffset($plannerPct) ?>;"/>
                    </svg>
                    <span class="stat-progress-text"><?= $pctText($plannerPct) ?></span>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3 anim-up anim-up-3">
            <div class="stat-card stat-goals">
                <div class="stat-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/></svg>
                </div>
                <div class="stat-info flex-grow-1 min-w-0">
                    <div class="stat-label">Goals</div>
                    <div class="stat-value"><?= $counts['goals'] ?></div>
                    <div class="stat-sub"><?= $goalsDone ?> completed</div>
                </div>
                <div class="stat-progress-ring">
                    <svg viewBox="0 0 42 42">
                        <circle class="bg-circle" cx="21" cy="21" r="18"/>
                        <circle class="val-circle" cx="21" cy="21" r="18" style="stroke-dashoffset: <?= $ringOffset($goalsPct) ?>;"/>
                    </svg>
                    <span class="stat-progress-text"><?= $pctText($goalsPct) ?></span>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3 anim-up anim-up-4">
            <div class="stat-card stat-expenses">
                <div class="stat-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/></svg>
                </div>
                <div class="stat-info flex-grow-1 min-w-0">
                    <div class="stat-label">Expenses</div>
                    <div class="stat-value"><?= $counts['expenses'] ?></div>
                    <div class="stat-sub">Budget used this month</div>
                </div>
                <div class="stat-progress-ring">
                    <svg viewBox="0 0 42 42">
                        <circle class="bg-circle" cx="21" cy="21" r="18"/>
                        <circle class="val-circle" cx="21" cy="21" r="18" style="stroke-dashoffset: <?= $ringOffset(min(100, $expensesPct)) ?>;"/>
                    </svg>
                    <span class="stat-progress-text <?= $overBudget ? 'negative' : '' ?>"><?= $pctText(min(100, $expensesPct)) ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts (rendered after initial load via fetch() to api/dashboard_charts.php) -->
    <div class="row g-3 mb-4">
        <div class="col-lg-7 anim-up anim-up-5">
            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h6 class="mb-1 fw-bold" style="letter-spacing:-.01em;"><span id="studyChartLabel">Study Hours</span></h6>
                        <small style="color:var(--ink-soft);" id="studyChartSummary">&nbsp;</small>
                    </div>
                    <div class="range-switch" aria-label="Study time range" id="studyRangeSwitch">
                        <button type="button" data-range="day" class="<?= $studyRange === 'day' ? 'active' : '' ?>">Day</button>
                        <button type="button" data-range="week" class="<?= $studyRange === 'week' ? 'active' : '' ?>">Week</button>
                        <button type="button" data-range="month" class="<?= $studyRange === 'month' ? 'active' : '' ?>">Month</button>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="d-flex justify-content-between align-items-center mb-2" style="font-size:.72rem;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.06em;font-weight:600;">
                        <span>Total study time</span>
                        <span style="color:var(--ink);font-weight:700;" id="studyTotalHours">…</span>
                    </div>
                    <div class="chart-wrap"><canvas id="weeklyChart"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-lg-5 anim-up anim-up-6">
            <div class="panel">
                <div class="panel-head">
                    <h6 class="mb-0 fw-semibold">Subject Distribution</h6>
                    <span class="count-pill">By sessions</span>
                </div>
                <div class="panel-body" id="subjectDist">
                    <div class="empty-state" id="subjectDistLoading">
                        <div style="font-size:.82rem;color:var(--ink-soft);">Loading breakdown…</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick-Add Forms -->
    <div class="section-header anim-up">
        <h5>Quick Add</h5>
        <div class="section-line"></div>
    </div>
    <div class="row g-3 mb-4">
        <!-- Add Subject -->
        <div class="col-md-6 col-lg-3 anim-up anim-up-1">
            <div class="panel panel-subjects h-100">
                <div class="panel-accent accent-subjects"></div>
                <div class="panel-body">
                    <button class="quick-add-toggle" type="button" data-target="form-subject" aria-expanded="false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                        Add Subject
                    </button>
                    <div class="quick-add-form" id="form-subject">
                        <form method="post" class="vstack gap-2 mt-3">
                            <input type="hidden" name="action" value="add_subject">
                            <input class="form-control" name="name" placeholder="Subject name" required>
                            <input class="form-control" name="description" placeholder="Description (optional)">
                            <button class="btn btn-save btn-subjects w-100">Save Subject</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Goal -->
        <div class="col-md-6 col-lg-3 anim-up anim-up-2">
            <div class="panel panel-goals h-100">
                <div class="panel-accent accent-goals"></div>
                <div class="panel-body">
                    <button class="quick-add-toggle" type="button" data-target="form-goal" aria-expanded="false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                        Add Goal
                    </button>
                    <div class="quick-add-form" id="form-goal">
                        <form method="post" class="vstack gap-2 mt-3">
                            <input type="hidden" name="action" value="add_goal">
                            <input class="form-control" name="goal_name" placeholder="Goal name" required>
                            <input class="form-control" type="number" name="target_hours" placeholder="Target hours" min="1" required>
                            <input class="form-control" type="date" name="deadline">
                            <button class="btn btn-save btn-goals w-100">Save Goal</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Expense -->
        <div class="col-md-6 col-lg-3 anim-up anim-up-3">
            <div class="panel panel-expenses h-100">
                <div class="panel-accent accent-expenses"></div>
                <div class="panel-body">
                    <button class="quick-add-toggle" type="button" data-target="form-expense" aria-expanded="false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                        Add Expense
                    </button>
                    <div class="quick-add-form" id="form-expense">
                        <form method="post" class="vstack gap-2 mt-3">
                            <input type="hidden" name="action" value="add_expense">
                            <input class="form-control" name="title" placeholder="Title" required>
                            <input class="form-control" name="category" placeholder="Category (optional)">
                            <input class="form-control" type="number" step="0.01" name="amount" placeholder="Amount ($)" min="0.01" required>
                            <input class="form-control" type="date" name="expense_date" required>
                            <button class="btn btn-save btn-expenses w-100">Save Expense</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Planner -->
        <div class="col-md-6 col-lg-3 anim-up anim-up-4">
            <div class="panel panel-planner h-100">
                <div class="panel-accent accent-planner"></div>
                <div class="panel-body">
                    <button class="quick-add-toggle" type="button" data-target="form-planner" aria-expanded="false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                        Add Planner
                    </button>
                    <div class="quick-add-form" id="form-planner">
                        <form method="post" class="vstack gap-2 mt-3">
                            <input type="hidden" name="action" value="add_planner">
                            <select class="form-select" name="subject_id" required>
                                <option value="">Select subject</option>
                                <?php foreach ($allSubjects as $sub): ?>
                                    <option value="<?= $sub['id'] ?>"><?= htmlspecialchars($sub['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="row g-1">
                                <div class="col-6"><input type="date" class="form-control" name="study_date" required></div>
                                <div class="col-6">
                                    <select class="form-select" name="day_name" required>
                                        <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $day): ?>
                                            <option value="<?= $day ?>"><?= substr($day, 0, 3) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="row g-1">
                                <div class="col-6"><input type="time" class="form-control" name="start_time" required></div>
                                <div class="col-6"><input type="time" class="form-control" name="end_time" required></div>
                            </div>
                            <input type="text" class="form-control" name="topic" placeholder="Topic" required>
                            <button class="btn btn-save btn-planner w-100">Save Planner</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Activity Feed -->
    <div class="section-header anim-up">
        <h5>Activity Feed</h5>
        <div class="section-line"></div>
    </div>

    <?php
    // Build a unified activity feed from all sources
    $activities = [];

    foreach ($plannerRows as $p) {
        $activities[] = [
            'type' => 'planner',
            'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
            'title' => htmlspecialchars($p['subject_name']) . ' — ' . htmlspecialchars($p['topic']),
            'meta' => date('D, j M', strtotime($p['study_date'])) . ' · ' . date('h:i A', strtotime($p['start_time'])) . ' - ' . date('h:i A', strtotime($p['end_time'])),
            'date' => date('M d, Y', strtotime($p['study_date'])),
            'delete_action' => 'delete_planner',
            'delete_id' => $p['id'],
            'delete_confirm' => 'Delete this planner item?',
        ];
    }

    foreach ($subjects as $s) {
        $activities[] = [
            'type' => 'subject',
            'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
            'title' => htmlspecialchars($s['name']),
            'meta' => !empty($s['description']) ? htmlspecialchars($s['description']) : 'No description',
            'date' => null,
            'delete_action' => 'delete_subject',
            'delete_id' => $s['id'],
            'delete_confirm' => 'Delete this subject?',
        ];
    }

    foreach ($goals as $g) {
        $progress = (int) $g['progress'];
        $statusColor = $progress >= 100 ? '#00B894' : ($progress > 50 ? '#0984E3' : '#FDCB6E');
        $activities[] = [
            'type' => 'goal',
            'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/></svg>',
            'title' => htmlspecialchars($g['goal_name']),
            'meta' => (int)$g['target_hours'] . 'h target · ' . $progress . '% done',
            'progress' => $progress,
            'progress_color' => $statusColor,
            'date' => !empty($g['deadline']) ? ('Due ' . date('M d, Y', strtotime($g['deadline']))) : null,
            'delete_action' => 'delete_goal',
            'delete_id' => $g['id'],
            'delete_confirm' => 'Delete this goal?',
        ];
    }

    foreach ($expenses as $e) {
        $activities[] = [
            'type' => 'expense',
            'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/></svg>',
            'title' => htmlspecialchars($e['title']),
            'meta' => htmlspecialchars($e['category'] ?? 'Uncategorized'),
            'amount' => '$' . number_format((float)$e['amount'], 2),
            'date' => date('M d, Y', strtotime($e['expense_date'])),
            'delete_action' => 'delete_expense',
            'delete_id' => $e['id'],
            'delete_confirm' => 'Delete this expense?',
        ];
    }

    if (!$activities):
    ?>
        <div class="empty-state anim-up anim-up-1">
            <svg width="48" height="48" style="width:48px;height:48px;margin-bottom:12px;opacity:0.35;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <div>No activity yet. Start by adding subjects, planner items, goals, or expenses above.</div>
        </div>
    <?php else: ?>
    <div class="activity-filter-bar anim-up">
        <button type="button" class="filter-chip active" data-filter="all">All <span class="chip-count"><?= count($activities) ?></span></button>
        <button type="button" class="filter-chip" data-filter="planner">Planner <span class="chip-count"><?= count($plannerRows) ?></span></button>
        <button type="button" class="filter-chip" data-filter="subject">Subjects <span class="chip-count"><?= count($subjects) ?></span></button>
        <button type="button" class="filter-chip" data-filter="goal">Goals <span class="chip-count"><?= count($goals) ?></span></button>
        <button type="button" class="filter-chip" data-filter="expense">Expenses <span class="chip-count"><?= count($expenses) ?></span></button>
    </div>
    <div class="activity-grid anim-up anim-up-1" id="activityGrid">
        <?php foreach ($activities as $i => $a): ?>
            <div class="activity-item type-<?= $a['type'] ?>" style="animation-delay: <?= round($i * .04, 2) ?>s;">
                <div class="activity-item-body">
                    <div class="activity-item-header">
                        <div class="activity-item-icon"><?= $a['icon'] ?></div>
                        <div class="min-w-0 flex-grow-1">
                            <div class="activity-item-type"><?= ucfirst($a['type']) ?></div>
                        </div>
                    </div>
                    <div class="activity-item-title" title="<?= $a['title'] ?>"><?= $a['title'] ?></div>
                    <div class="activity-item-meta"><?= $a['meta'] ?></div>
                    <?php if (isset($a['progress'])): ?>
                        <div class="activity-item-progress">
                            <div class="activity-item-progress-bar">
                                <div class="activity-item-progress-fill" style="width: <?= $a['progress'] ?>%; background: <?= $a['progress_color'] ?>;"></div>
                            </div>
                            <span class="activity-item-progress-text"><?= $a['progress'] ?>%</span>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($a['amount'])): ?>
                        <div class="activity-item-amount"><?= $a['amount'] ?></div>
                    <?php endif; ?>
                </div>
                <div class="activity-item-footer">
                    <span class="activity-item-date"><?= $a['date'] ? htmlspecialchars($a['date']) : '—' ?></span>
                    <form method="post" onsubmit="return confirm('<?= $a['delete_confirm'] ?>');" style="margin:0;">
                        <input type="hidden" name="action" value="<?= $a['delete_action'] ?>">
                        <input type="hidden" name="id" value="<?= $a['delete_id'] ?>">
                        <button type="submit" class="btn-activity-del" title="Delete">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                            Del
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="empty-state activity-empty-filtered" id="activityEmptyFiltered" style="display: none;">
        Nothing here yet for this filter.
    </div>
    <?php endif; ?>
</div>

<script>
    const palette = ['#6C5CE7', '#0984E3', '#E17055', '#FF6B6B', '#00B894'];
    const CHART_API = 'api/dashboard_charts.php';

    // Chart data is fetched asynchronously after the page shell renders.
    let dpChart = { study: { labels: [], values: [], totalHours: 0, label: '', summary: '' }, subject: { labels: [], values: [] } };
    let dpChartRange = '<?= $studyRange ?>';

    function chartTheme() {
        const dark = document.body.getAttribute('data-theme') === 'dark';
        return dark ? {
            grid: '#243047',
            tick: '#94a3b8',
            tooltipBg: '#0f172a',
            pointBorder: '#0f172a',
            lineFill: 'rgba(9,132,227,0.18)',
            doughnutBorder: '#111827'
        } : {
            grid: '#EEF0F8',
            tick: '#6B7190',
            tooltipBg: '#1A1D2E',
            pointBorder: '#ffffff',
            lineFill: 'rgba(9,132,227,0.08)',
            doughnutBorder: '#ffffff'
        };
    }

    let chartRetryCount = 0;

    // Render the study-hours line chart and the subject distribution doughnut
    // into their canvases from the latest fetched data. Idempotent-safe and
    // guards against missing canvases after a soft navigation.
    function renderCharts() {
        const weeklyCanvas = document.getElementById('weeklyChart');
        if (!weeklyCanvas) return;

        if (typeof Chart === 'undefined') {
            if (chartRetryCount < 20) {
                chartRetryCount++;
                setTimeout(renderCharts, 150);
            } else {
                const wrap = weeklyCanvas.closest('.panel-body');
                if (wrap && !wrap.querySelector('.chart-load-error')) {
                    wrap.insertAdjacentHTML('beforeend',
                        '<div class="empty-state chart-load-error">Charts could not load. Check your connection and refresh the page.</div>');
                }
            }
            return;
        }

        try {
            const t = chartTheme();

            if (window.__dpWeeklyChart) { window.__dpWeeklyChart.destroy(); window.__dpWeeklyChart = null; }
            if (window.__dpSubjectChart) { window.__dpSubjectChart.destroy(); window.__dpSubjectChart = null; }

            window.__dpWeeklyChart = new Chart(weeklyCanvas, {
                type: 'line',
                data: {
                    labels: dpChart.study.labels,
                    datasets: [{
                        data: dpChart.study.values,
                        borderColor: '#0984E3',
                        backgroundColor: t.lineFill,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 4,
                        pointBackgroundColor: '#0984E3',
                        pointBorderColor: t.pointBorder,
                        pointBorderWidth: 2,
                        borderWidth: 2.5,
                        pointHoverRadius: 6,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 600, easing: 'easeOutQuart' },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: t.tooltipBg,
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            padding: 12,
                            cornerRadius: 10,
                            displayColors: false,
                            titleFont: { weight: '600' }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: t.grid, drawBorder: false },
                            ticks: { color: t.tick, font: { size: 11, weight: '500' }, padding: 8 },
                            border: { display: false }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { color: t.tick, font: { size: 11, weight: '500' }, padding: 8 },
                            border: { display: false }
                        }
                    }
                }
            });

            renderSubjectChart(t);
        } catch (err) {
            console.error('Dashboard chart render failed:', err);
        }
    }

    // Render the doughnut + legend, or an empty state, into #subjectDist.
    function renderSubjectChart(t) {
        const container = document.getElementById('subjectDist');
        if (!container) return;

        const labels = dpChart.subject.labels;
        const values = dpChart.subject.values;

        // Drop the loading / stale empty state.
        container.querySelectorAll('#subjectDistLoading').forEach(function (n) { n.remove(); });
        container.querySelectorAll('canvas, .legend-row').forEach(function (n) { n.remove(); });

        if (!labels.length) {
            container.insertAdjacentHTML('beforeend',
                '<div class="empty-state">' +
                '<svg width="48" height="48" style="width:48px;height:48px;margin-bottom:12px;opacity:0.35;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 12h8M12 8v8"/></svg>' +
                '<div>No planner sessions yet.<br>Add one to see the breakdown.</div>' +
                '</div>');
            return;
        }

        t = t || chartTheme();
        container.insertAdjacentHTML('beforeend',
            '<div class="chart-wrap" style="height:175px;"><canvas id="subjectChart"></canvas></div>');

        var legend = '<div class="d-flex flex-wrap gap-3 justify-content-center mt-3 legend-row" style="font-size:.72rem;">';
        labels.forEach(function (lbl, i) {
            legend += '<span><span class="legend-dot" style="background:' + palette[i % palette.length] + ';"></span>' +
                escapeHtml(lbl) + '</span>';
        });
        legend += '</div>';
        container.insertAdjacentHTML('beforeend', legend);

        const subjectCanvas = document.getElementById('subjectChart');
        if (!subjectCanvas) return;
        window.__dpSubjectChart = new Chart(subjectCanvas, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: palette,
                    borderColor: t.doughnutBorder,
                    borderWidth: 3,
                    hoverBorderWidth: 0,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                animation: { animateRotate: true, duration: 800 },
                plugins: { legend: { display: false } }
            }
        });
    }

    // Update the study-hours panel header + total from the fetched data.
    function updateStudySummary() {
        const labelEl = document.getElementById('studyChartLabel');
        const summaryEl = document.getElementById('studyChartSummary');
        const totalEl = document.getElementById('studyTotalHours');
        if (labelEl) labelEl.textContent = dpChart.study.label + ' Study Hours';
        if (summaryEl) summaryEl.textContent = dpChart.study.summary;
        if (totalEl) totalEl.textContent = (Math.max(0, dpChart.study.totalHours)).toFixed(1) + 'h';
    }

    function setRangeActive(range) {
        document.querySelectorAll('#studyRangeSwitch button').forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-range') === range);
        });
    }

    // Fetch chart data for the given range without reloading the page.
    function loadCharts(range) {
        dpChartRange = range;
        setRangeActive(range);
        fetch(CHART_API + '?range=' + encodeURIComponent(range), {
            headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
            .then(function (res) { return res.ok ? res.json() : Promise.reject(new Error('HTTP ' + res.status)); })
            .then(function (data) {
                dpChart = data;
                updateStudySummary();
                renderCharts();
            })
            .catch(function () {
                const totalEl = document.getElementById('studyTotalHours');
                if (totalEl && totalEl.textContent === '…') totalEl.textContent = '—';
                const container = document.getElementById('subjectDist');
                if (container) {
                    container.querySelectorAll('#subjectDistLoading').forEach(function (n) { n.remove(); });
                    if (!container.querySelector('.empty-state')) {
                        container.insertAdjacentHTML('beforeend',
                            '<div class="empty-state">Charts could not load. Refresh to retry.</div>');
                    }
                }
            });
    }

    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, function (m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
        });
    }

    // Set up page behaviour. Guarded so it runs exactly once per script
    // execution (works both on a full page load and on PJAX soft navigation).
    let dashboardSetupRan = false;
    function initDashboardCharts() {
        if (dashboardSetupRan) return;
        dashboardSetupRan = true;

        const switchEl = document.getElementById('studyRangeSwitch');
        if (switchEl) {
            switchEl.addEventListener('click', function (e) {
                const btn = e.target.closest('button[data-range]');
                if (btn && !btn.classList.contains('active')) loadCharts(btn.getAttribute('data-range'));
            });
        }

        /* ---- Quick-add toggle ---- */
        document.querySelectorAll('.quick-add-toggle').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var targetId = this.getAttribute('data-target');
                var form = document.getElementById(targetId);
                var expanded = this.getAttribute('aria-expanded') === 'true';

                // close others
                document.querySelectorAll('.quick-add-form.show').forEach(function(f) {
                    if (f.id !== targetId) {
                        f.classList.remove('show');
                        var otherBtn = document.querySelector('[data-target="' + f.id + '"]');
                        if (otherBtn) otherBtn.setAttribute('aria-expanded', 'false');
                    }
                });

                this.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                form.classList.toggle('show', !expanded);
                if (!expanded) {
                    var firstInput = form.querySelector('input:not([type=hidden]), select');
                    if (firstInput) setTimeout(function() { firstInput.focus(); }, 100);
                }
            });
        });

        /* ---- Activity Feed filter chips ---- */
        var chips = document.querySelectorAll('.filter-chip');
        var emptyFiltered = document.getElementById('activityEmptyFiltered');
        chips.forEach(function(chip) {
            chip.addEventListener('click', function() {
                chips.forEach(function(c) { c.classList.remove('active'); });
                this.classList.add('active');
                var filter = this.getAttribute('data-filter');
                var items = document.querySelectorAll('.activity-item');
                var visibleCount = 0;
                items.forEach(function(item) {
                    var show = (filter === 'all') || item.classList.contains('type-' + filter);
                    item.style.display = show ? '' : 'none';
                    if (show) visibleCount++;
                });
                if (emptyFiltered) {
                    emptyFiltered.style.display = visibleCount === 0 ? 'block' : 'none';
                }
            });
        });

        loadCharts(dpChartRange);
    }

    document.addEventListener('DOMContentLoaded', initDashboardCharts);
    if (document.readyState === 'interactive' || document.readyState === 'complete') {
        initDashboardCharts();
    }

    // Re-render charts (from cached data) when the theme changes instantly.
    window.addEventListener('dp:themechange', renderCharts);
</script>
<?php layout_footer(); ?>