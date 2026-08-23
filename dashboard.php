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

// ---- All dashboard stats in ONE round-trip (subselects instead of 10 separate queries) ----
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

// ---- Dynamic stat-card percentages (dashboards should reflect real data) ----
$subjectsUsed = (int) ($stats['subjects_used'] ?? 0);
$subjectsPct = $counts['subjects'] > 0 ? (int) round(($subjectsUsed / $counts['subjects']) * 100) : 0;

$plannerDone = (int) ($stats['planner_done'] ?? 0);
$plannerPct = $counts['planner'] > 0 ? (int) round(($plannerDone / $counts['planner']) * 100) : 0;

$goalsDone = (int) ($stats['goals_done'] ?? 0);
$goalsPct = $counts['goals'] > 0 ? (int) round(($goalsDone / $counts['goals']) * 100) : 0;

$budget = (float) ($stats['budget'] ?? 0);
$spentThisMonth = (float) ($stats['spent'] ?? 0);
$expensesPct = $budget > 0 ? (int) round(($spentThisMonth / $budget) * 100) : 0;

$ringOffset = fn($pct) => (string) round(113 - 113 * $pct / 100, 1);
$pctText = fn($pct) => $pct . '%';
$overBudget = $expensesPct >= 100;

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

// ---- Study hours chart (dynamic range) ----
$studyRange = $_GET['range'] ?? 'week';
if (!in_array($studyRange, ['day', 'week', 'month'], true)) {
    $studyRange = 'week';
}

$studyChart = [
    'label' => 'This week',
    'summary' => 'Current week',
    'labels' => [],
    'values' => [],
    'totalHours' => 0,
];

if ($studyRange === 'day') {
    $today = date('Y-m-d');
    $dayRaw = $conn->query("
        SELECT start_time, end_time, TIMESTAMPDIFF(MINUTE, start_time, end_time) / 60 AS hours
        FROM planner
        WHERE user_id = {$userId} AND study_date = '{$today}'
        ORDER BY start_time ASC
    ")->fetch_all(MYSQLI_ASSOC);

    foreach ($dayRaw as $row) {
        $label = substr($row['start_time'], 0, 5) . ' - ' . substr($row['end_time'], 0, 5);
        $studyChart['labels'][] = $label;
        $studyChart['values'][] = round((float) $row['hours'], 1);
        $studyChart['totalHours'] += (float) $row['hours'];
    }

    $studyChart['label'] = 'This day';
    $studyChart['summary'] = date('D, j M');
} elseif ($studyRange === 'month') {
    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-t');
    $monthRaw = $conn->query("
        SELECT study_date, SUM(TIMESTAMPDIFF(MINUTE, start_time, end_time)) / 60 AS hours
        FROM planner
        WHERE user_id = {$userId} AND study_date BETWEEN '{$monthStart}' AND '{$monthEnd}'
        GROUP BY study_date
        ORDER BY study_date ASC
    ")->fetch_all(MYSQLI_ASSOC);

    $firstDay = (int) date('j', strtotime($monthStart));
    $lastDay = (int) date('j', strtotime($monthEnd));
    $monthHours = [];
    foreach ($monthRaw as $row) {
        $monthHours[$row['study_date']] = round((float) $row['hours'], 1);
        $studyChart['totalHours'] += (float) $row['hours'];
    }
    for ($day = $firstDay; $day <= $lastDay; $day++) {
        $date = date('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
        $studyChart['labels'][] = (string) $day;
        $studyChart['values'][] = $monthHours[$date] ?? 0;
    }

    $studyChart['label'] = 'This month';
    $studyChart['summary'] = date('F Y');
} else {
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $weekEnd   = date('Y-m-d', strtotime('sunday this week'));
    $weeklyRaw = $conn->query("
        SELECT day_name, SUM(TIME_TO_SEC(TIMEDIFF(end_time, start_time))) / 3600 AS hours
        FROM planner
        WHERE user_id = {$userId} AND study_date BETWEEN '{$weekStart}' AND '{$weekEnd}'
        GROUP BY day_name
    ")->fetch_all(MYSQLI_ASSOC);

    $dayOrder = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $weeklyHours = array_fill_keys($dayOrder, 0.0);
    foreach ($weeklyRaw as $row) {
        if (isset($weeklyHours[$row['day_name']])) {
            $weeklyHours[$row['day_name']] = round((float) $row['hours'], 1);
            $studyChart['totalHours'] += (float) $row['hours'];
        }
    }
    $studyChart['labels'] = array_map(fn($d) => substr($d, 0, 3), $dayOrder);
    $studyChart['values'] = array_values($weeklyHours);
}

// ---- Subject distribution (by planner sessions) ----
$subjectDistRaw = $conn->query("
    SELECT s.name, COUNT(*) AS c
    FROM planner p INNER JOIN subjects s ON s.id = p.subject_id
    WHERE p.user_id = {$userId}
    GROUP BY s.name ORDER BY c DESC LIMIT 5
")->fetch_all(MYSQLI_ASSOC);
$subjectLabels = array_map(fn($r) => $r['name'], $subjectDistRaw);
$subjectValues = array_map(fn($r) => (int) $r['c'], $subjectDistRaw);

?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Khmer:wght@400;500;600;700;800&display=swap');

        :root {
            --paper:   #F5F6FB;
            --surface: #FFFFFF;
            --ink:     #1D2140;
            --ink-soft:#6B7093;
            --border:  #E7E9F3;

            --c-subjects: #0F9B8E; /* teal   */
            --c-planner:  #5457E5; /* indigo */
            --c-goals:    #E8A93A; /* amber  */
            --c-expenses: #E15B5B; /* coral  */

            --c-subjects-bg: #0F9B8E14;
            --c-planner-bg:  #5457E514;
            --c-goals-bg:    #E8A93A18;
            --c-expenses-bg: #E15B5B14;
        }

        html, body { background: var(--paper); }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--ink);
        }
        html[lang="kh"] body { font-family: 'Noto Sans Khmer', 'Plus Jakarta Sans', sans-serif; }

        .font-display { font-family: 'Plus Jakarta Sans', sans-serif; letter-spacing: -.03em; }

        .extra-small { font-size: .72rem; letter-spacing: .04em; }
        .tracking-wider { letter-spacing: .08em; }

        /* ---- Hero header ---- */
        .hero-panel {
            background: var(--ink);
            border-radius: 18px;
            padding: 28px 32px;
            color: #fff;
            position: relative;
            overflow: hidden;
        }
        .hero-panel::after {
            content: "";
            position: absolute;
            top: -60px; right: -60px;
            width: 220px; height: 220px;
            border-radius: 50%;
            background: radial-gradient(circle, var(--c-planner) 0%, transparent 70%);
            opacity: .35;
        }
        .hero-panel h1 { letter-spacing: -.02em; }
        .hero-date {
            background: rgba(255,255,255,.1);
            border: 1px solid rgba(255,255,255,.18);
            border-radius: 999px;
            padding: 6px 14px;
            font-size: .8rem;
            white-space: nowrap;
        }

        /* ---- Stat cards ---- */
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 16px 18px;
            display: flex;
            align-items: center;
            gap: 14px;
            height: 100%;
        }
        .stat-icon {
            width: 42px; height: 42px;
            border-radius: 11px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .stat-card .stat-value { font-size: 1.65rem; line-height: 1; }
        .stat-card .stat-label { color: var(--ink-soft); }

        .stat-subjects .stat-icon { background: var(--c-subjects-bg); color: var(--c-subjects); }
        .stat-planner  .stat-icon { background: var(--c-planner-bg);  color: var(--c-planner); }
        .stat-goals    .stat-icon { background: var(--c-goals-bg);    color: var(--c-goals); }
        .stat-expenses .stat-icon { background: var(--c-expenses-bg); color: var(--c-expenses); }

        /* ---- Generic panel ---- */
        .panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            height: 100%;
        }
        .panel-head {
            padding: 14px 18px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .panel-accent { height: 4px; width: 100%; }
        .panel-body { padding: 18px; }

        .accent-subjects { background: var(--c-subjects); }
        .accent-planner  { background: var(--c-planner); }
        .accent-goals    { background: var(--c-goals); }
        .accent-expenses { background: var(--c-expenses); }

        .count-pill {
            background: var(--paper);
            border: 1px solid var(--border);
            color: var(--ink-soft);
            border-radius: 999px;
            padding: 3px 10px;
            font-size: .72rem;
        }

        /* ---- Forms ---- */
        .form-control, .form-select {
            border-color: var(--border);
            font-size: .85rem;
            border-radius: 9px;
            padding: .5rem .7rem;
        }
        .form-control:focus, .form-select:focus {
            box-shadow: 0 0 0 3px var(--focus-color, #5457E522);
            border-color: var(--focus-border, #5457E5);
        }
        .panel-subjects .form-control:focus, .panel-subjects .form-select:focus { --focus-color: var(--c-subjects-bg); --focus-border: var(--c-subjects); }
        .panel-planner  .form-control:focus, .panel-planner  .form-select:focus { --focus-color: var(--c-planner-bg);  --focus-border: var(--c-planner); }
        .panel-goals    .form-control:focus, .panel-goals    .form-select:focus { --focus-color: var(--c-goals-bg);    --focus-border: var(--c-goals); }
        .panel-expenses .form-control:focus, .panel-expenses .form-select:focus { --focus-color: var(--c-expenses-bg); --focus-border: var(--c-expenses); }

        .btn-save {
            border: none;
            border-radius: 9px;
            font-size: .85rem;
            font-weight: 600;
            padding: .5rem;
            color: #fff;
        }
        .btn-subjects { background: var(--c-subjects); }
        .btn-planner  { background: var(--c-planner); }
        .btn-goals    { background: var(--c-goals); }
        .btn-expenses { background: var(--c-expenses); }
        .btn-save:hover { filter: brightness(0.92); color: #fff; }

        /* ---- List rows ---- */
        .row-item {
            padding: 11px 18px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .row-item:last-child { border-bottom: none; }
        .row-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 6px;
            flex-shrink: 0;
        }
        .row-title { font-weight: 600; font-size: .85rem; color: var(--ink); }
        .row-sub { color: var(--ink-soft); font-size: .74rem; }

        .btn-del {
            border: 1px solid var(--border);
            background: transparent;
            color: #B5495B;
            border-radius: 8px;
            font-size: .7rem;
            font-weight: 600;
            padding: 3px 8px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            flex-shrink: 0;
        }
        .btn-del:hover { background: #E15B5B14; border-color: #E15B5B55; color: #B5495B; }

        .empty-state { text-align: center; color: var(--ink-soft); padding: 34px 12px; font-size: .78rem; }

        /* ---- Chart panels ---- */
        .chart-wrap { position: relative; height: 210px; }
        .legend-dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; margin-right: 6px; }

        .range-switch {
            display: inline-flex;
            gap: 6px;
            background: var(--paper);
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 4px;
            flex-wrap: wrap;
        }
        .range-switch a {
            text-decoration: none;
            color: var(--ink-soft);
            font-size: .74rem;
            font-weight: 700;
            padding: 6px 11px;
            border-radius: 999px;
            transition: .2s ease;
        }
        .range-switch a.active {
            background: var(--surface);
            color: var(--ink);
            box-shadow: 0 1px 6px rgba(29,33,64,.08);
        }
        /* Card Container Base */
.stat-card-modern {
    background: #ffffff;
    border-radius: 20px;
    padding: 1.25rem;
    position: relative;
    box-shadow: 0 10px 30px rgba(118, 110, 230, 0.12), 
                0 4px 10px rgba(0, 0, 0, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.8);
    overflow: hidden;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.stat-card-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 15px 35px rgba(118, 110, 230, 0.18);
}

/* Side Accent Ribbon */
.stat-card-modern::after {
    content: '';
    position: absolute;
    right: 0;
    top: 25%;
    height: 45%;
    width: 6px;
    border-top-left-radius: 6px;
    border-bottom-left-radius: 6px;
}

/* Individual Card Accent Colors */
.stat-subjects::after { background-color: #4F46E5; }
.stat-planner::after  { background-color: #38BDF8; }
.stat-goals::after    { background-color: #6366F1; }
.stat-expenses::after { background-color: #10B981; }

/* Circular Progress Ring Styling */
.stat-progress-ring {
    width: 48px;
    height: 48px;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
}

.stat-progress-ring svg {
    transform: rotate(-90deg);
    width: 100%;
    height: 100%;
}

.stat-progress-ring circle {
    fill: none;
    stroke-width: 4.5;
    stroke-linecap: round;
}

.stat-progress-ring .bg-circle {
    stroke: #E2E8F0;
}

.stat-progress-ring .val-circle {
    stroke-dasharray: 113; /* Circumference = 2 * PI * 18 */
    transition: stroke-dashoffset 0.3s ease;
}

/* Ring Progress Color Themes */
.stat-subjects .val-circle { stroke: #4F46E5; }
.stat-planner .val-circle  { stroke: #38BDF8; }
.stat-goals .val-circle    { stroke: #818CF8; }
.stat-expenses .val-circle { stroke: #10B981; }

.stat-progress-text {
    position: absolute;
    font-size: 0.68rem;
    font-weight: 700;
    color: #1E293B;
}

.stat-progress-text.negative {
    color: #EF4444;
}

/* Label & Typography Customization */
.stat-label-modern {
    color: #64748B;
    font-size: 0.85rem;
    font-weight: 500;
}

.stat-value-modern {
    font-size: 1.5rem;
    font-weight: 800;
    color: #0F172A;
    line-height: 1.2;
}

.stat-subtext-modern {
    font-size: 0.72rem;
    color: #94A3B8;
}
    </style>
</head>
<body data-theme="<?php echo htmlspecialchars(current_theme()); ?>">
<?php $activePage = 'dashboard'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="container py-4">

    <!-- Hero -->
    <div class="hero-panel mb-4 d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
            <h1 class="h3 fw-bold font-display mb-1">Hello, <?= htmlspecialchars($userName) ?></h1>
            <p class="mb-0 small" style="color: rgba(255,255,255,.7);">Manage your study schedule, goals, and expenses.</p>
        </div>
        <span class="hero-date font-display"><?= date('D, j M Y') ?></span>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
    <?php endif; ?>
    <?php if ($message): ?>
        <div class="alert alert-success py-2"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Summary Counters -->
<div class="row g-3 mb-4">
    <!-- Subjects Card -->
    <div class="col-6 col-md-3">
        <div class="stat-card-modern stat-subjects d-flex flex-column justify-content-between h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="stat-icon text-dark">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                </span>
                <div class="stat-progress-ring">
                    <svg viewBox="0 0 42 42">
                        <circle class="bg-circle" cx="21" cy="21" r="18"/>
                        <circle class="val-circle" cx="21" cy="21" r="18" style="stroke-dashoffset: <?= $ringOffset($subjectsPct) ?>;"/>
                    </svg>
                    <span class="stat-progress-text"><?= $pctText($subjectsPct) ?></span>
                </div>
            </div>
            <div>
                <div class="stat-label-modern mb-1">Subjects</div>
                <div class="stat-value-modern"><?= $counts['subjects'] ?></div>
                <div class="stat-subtext-modern mt-1"><?= $subjectsUsed ?> used in planner</div>
            </div>
        </div>
    </div>

    <!-- Planner Card -->
    <div class="col-6 col-md-3">
        <div class="stat-card-modern stat-planner d-flex flex-column justify-content-between h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="stat-icon text-dark">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                </span>
                <div class="stat-progress-ring">
                    <svg viewBox="0 0 42 42">
                        <circle class="bg-circle" cx="21" cy="21" r="18"/>
                        <circle class="val-circle" cx="21" cy="21" r="18" style="stroke-dashoffset: <?= $ringOffset($plannerPct) ?>;"/>
                    </svg>
                    <span class="stat-progress-text"><?= $pctText($plannerPct) ?></span>
                </div>
            </div>
            <div>
                <div class="stat-label-modern mb-1">Planner</div>
                <div class="stat-value-modern"><?= $counts['planner'] ?></div>
                <div class="stat-subtext-modern mt-1"><?= $plannerDone ?> tasks done</div>
            </div>
        </div>
    </div>

    <!-- Goals Card -->
    <div class="col-6 col-md-3">
        <div class="stat-card-modern stat-goals d-flex flex-column justify-content-between h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="stat-icon text-dark">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/></svg>
                </span>
                <div class="stat-progress-ring">
                    <svg viewBox="0 0 42 42">
                        <circle class="bg-circle" cx="21" cy="21" r="18"/>
                        <circle class="val-circle" cx="21" cy="21" r="18" style="stroke-dashoffset: <?= $ringOffset($goalsPct) ?>;"/>
                    </svg>
                    <span class="stat-progress-text"><?= $pctText($goalsPct) ?></span>
                </div>
            </div>
            <div>
                <div class="stat-label-modern mb-1">Goals</div>
                <div class="stat-value-modern"><?= $counts['goals'] ?></div>
                <div class="stat-subtext-modern mt-1"><?= $goalsDone ?> completed</div>
            </div>
        </div>
    </div>

    <!-- Expenses Card -->
    <div class="col-6 col-md-3">
        <div class="stat-card-modern stat-expenses d-flex flex-column justify-content-between h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="stat-icon text-dark">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/></svg>
                </span>
                <div class="stat-progress-ring">
                    <svg viewBox="0 0 42 42">
                        <circle class="bg-circle" cx="21" cy="21" r="18"/>
                        <circle class="val-circle" cx="21" cy="21" r="18" style="stroke-dashoffset: <?= $ringOffset(min(100, $expensesPct)) ?>;"/>
                    </svg>
                    <span class="stat-progress-text <?= $overBudget ? 'negative' : '' ?>"><?= $pctText(min(100, $expensesPct)) ?></span>
                </div>
            </div>
            <div>
                <div class="stat-label-modern mb-1">Expenses</div>
                <div class="stat-value-modern"><?= $counts['expenses'] ?></div>
                <div class="stat-subtext-modern mt-1">Budget used this month</div>
            </div>
        </div>
    </div>
</div>

    <!-- Charts -->
    <div class="row g-3 mb-4">
        <div class="col-lg-7">
            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h6 class="mb-1 fw-bold font-display mb-0"><?= htmlspecialchars($studyChart['label']) ?> Study Hours</h6>
                        <small class="text-secondary"><?= htmlspecialchars($studyChart['summary']) ?></small>
                    </div>
                    <div class="range-switch" aria-label="Study time range">
                        <a href="?range=day" class="<?= $studyRange === 'day' ? 'active' : '' ?>">This day</a>
                        <a href="?range=week" class="<?= $studyRange === 'week' ? 'active' : '' ?>">This week</a>
                        <a href="?range=month" class="<?= $studyRange === 'month' ? 'active' : '' ?>">This month</a>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="d-flex justify-content-between align-items-center mb-2 extra-small text-uppercase tracking-wider text-secondary">
                        <span>Total study time</span>
                        <span><?= number_format($studyChart['totalHours'], 1) ?>h</span>
                    </div>
                    <div class="chart-wrap"><canvas id="weeklyChart"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="panel">
                <div class="panel-head">
                    <h6 class="mb-0 fw-semibold font-display">Subject Distribution</h6>
                    <span class="count-pill">By sessions</span>
                </div>
                <div class="panel-body">
                    <?php if ($subjectLabels): ?>
                        <div class="chart-wrap" style="height:170px;"><canvas id="subjectChart"></canvas></div>
                        <div class="d-flex flex-wrap gap-3 justify-content-center mt-3 extra-small">
                            <?php
                            $palette = ['#5457E5', '#0F9B8E', '#E8A93A', '#E15B5B', '#8B5CF6'];
                            foreach ($subjectLabels as $i => $lbl): ?>
                                <span><span class="legend-dot" style="background:<?= $palette[$i % count($palette)] ?>;"></span><?= htmlspecialchars($lbl) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">No planner sessions yet — add one to see the breakdown.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Forms Section -->
    <div class="row g-3 mb-4">
        <!-- Add Subject -->
        <div class="col-md-6 col-lg-3">
            <div class="panel panel-subjects h-100">
                <div class="panel-accent accent-subjects"></div>
                <div class="panel-body h-100 d-flex flex-column">
                    <h6 class="fw-semibold font-display mb-3">Add Subject</h6>
                    <form method="post" class="vstack gap-2 flex-grow-1">
                        <input type="hidden" name="action" value="add_subject">
                        <input class="form-control" name="name" placeholder="Subject name" required>
                        <input class="form-control" name="description" placeholder="Description">
                        <button class="btn btn-save btn-subjects w-100 mt-auto">Save</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Add Goal -->
        <div class="col-md-6 col-lg-3">
            <div class="panel panel-goals h-100">
                <div class="panel-accent accent-goals"></div>
                <div class="panel-body h-100 d-flex flex-column">
                    <h6 class="fw-semibold font-display mb-3">Add Goal</h6>
                    <form method="post" class="vstack gap-2 flex-grow-1">
                        <input type="hidden" name="action" value="add_goal">
                        <input class="form-control" name="goal_name" placeholder="Goal name" required>
                        <input class="form-control" type="number" name="target_hours" placeholder="Target hours" required>
                        <input class="form-control" type="date" name="deadline">
                        <button class="btn btn-save btn-goals w-100 mt-auto">Save</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Add Expense -->
        <div class="col-md-6 col-lg-3">
            <div class="panel panel-expenses h-100">
                <div class="panel-accent accent-expenses"></div>
                <div class="panel-body h-100 d-flex flex-column">
                    <h6 class="fw-semibold font-display mb-3">Add Expense</h6>
                    <form method="post" class="vstack gap-2 flex-grow-1">
                        <input type="hidden" name="action" value="add_expense">
                        <input class="form-control" name="title" placeholder="Title" required>
                        <input class="form-control" name="category" placeholder="Category">
                        <input class="form-control" type="number" step="0.01" name="amount" placeholder="Amount ($)" required>
                        <input class="form-control" type="date" name="expense_date" required>
                        <button class="btn btn-save btn-expenses w-100 mt-auto">Save</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Add Planner -->
        <div class="col-md-6 col-lg-3">
            <div class="panel panel-planner h-100">
                <div class="panel-accent accent-planner"></div>
                <div class="panel-body h-100 d-flex flex-column">
                    <h6 class="fw-semibold font-display mb-3">Add Planner Item</h6>
                    <form method="post" class="vstack gap-2 flex-grow-1">
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
                        <button class="btn btn-save btn-planner w-100 mt-auto">Save</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Overview Lists Section -->
    <div class="row g-3">
       <!-- Planner List -->
<div class="col-md-6">
    <div class="panel">
        <div class="panel-accent accent-planner"></div>
        <div class="panel-head">
            <h6 class="mb-0 fw-semibold text-uppercase extra-small tracking-wider" style="color: var(--ink-soft);">Planner Items</h6>
            <span class="count-pill"><?= count($plannerRows) ?> total</span>
        </div>
        <div>
            <?php foreach ($plannerRows as $p): 
                $studyDateFmt = date('D, j M Y', strtotime($p['study_date']));
                $startFmt = date('h:i A', strtotime($p['start_time']));
                $endFmt = date('h:i A', strtotime($p['end_time']));
            ?>
                <div class="row-item">
                    <div class="text-truncate">
                        <span class="row-title text-truncate d-block"><span class="row-dot" style="background:var(--c-planner);"></span><?= htmlspecialchars($p['subject_name']) ?> &bull; <span class="fw-normal" style="color:var(--ink-soft);"><?= htmlspecialchars($p['topic']) ?></span></span>
                        <span class="row-sub d-block"><?= htmlspecialchars($studyDateFmt . '  ·  ' . $startFmt . ' - ' . $endFmt) ?></span>
                    </div>
                    <form method="post" onsubmit="return confirm('Delete this planner item?');" class="flex-shrink-0">
                        <input type="hidden" name="action" value="delete_planner">
                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                        <button type="submit" class="btn-del" title="Delete">
                            <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" fill="currentColor" viewBox="0 0 16 16">
                                <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/>
                                <path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/>
                            </svg>
                            Delete
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
            <?php if (!$plannerRows): ?>
                <div class="empty-state">No planner items added.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

        <!-- Subjects List -->
        <div class="col-md-6">
            <div class="panel">
                <div class="panel-accent accent-subjects"></div>
                <div class="panel-head">
                    <h6 class="mb-0 fw-semibold text-uppercase extra-small tracking-wider" style="color: var(--ink-soft);">Subjects</h6>
                    <span class="count-pill"><?= count($subjects) ?> total</span>
                </div>
                <div>
                    <?php foreach ($subjects as $s): ?>
                        <div class="row-item">
                            <div class="text-truncate">
                                <span class="row-title text-truncate d-block"><span class="row-dot" style="background:var(--c-subjects);"></span><?= htmlspecialchars($s['name']) ?></span>
                                <?php if (!empty($s['description'])): ?>
                                    <span class="row-sub text-truncate d-block"><?= htmlspecialchars($s['description']) ?></span>
                                <?php endif; ?>
                            </div>
                            <form method="post" onsubmit="return confirm('Delete this subject?');" class="flex-shrink-0">
                                <input type="hidden" name="action" value="delete_subject">
                                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                <button type="submit" class="btn-del" title="Delete">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" fill="currentColor" viewBox="0 0 16 16">
                                        <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/>
                                        <path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/>
                                    </svg>
                                    Delete
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$subjects): ?>
                        <div class="empty-state">No subjects added.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Goals List -->
        <div class="col-md-6">
            <div class="panel">
                <div class="panel-accent accent-goals"></div>
                <div class="panel-head">
                    <h6 class="mb-0 fw-semibold text-uppercase extra-small tracking-wider" style="color: var(--ink-soft);">Goals</h6>
                    <span class="count-pill"><?= count($goals) ?> total</span>
                </div>
                <div>
                    <?php foreach ($goals as $g): ?>
                        <div class="row-item">
                            <div class="text-truncate">
                                <span class="row-title text-truncate d-block"><span class="row-dot" style="background:var(--c-goals);"></span><?= htmlspecialchars($g['goal_name']) ?></span>
                                <span class="row-sub d-block">
                                    Target: <span class="fw-medium" style="color:var(--ink-soft);"><?= (int)$g['target_hours'] ?>h</span> &bull; Progress: <span class="fw-medium" style="color:var(--ink-soft);"><?= (int)$g['progress'] ?>%</span>
                                </span>
                            </div>
                            <form method="post" onsubmit="return confirm('Delete this goal?');" class="flex-shrink-0">
                                <input type="hidden" name="action" value="delete_goal">
                                <input type="hidden" name="id" value="<?= $g['id'] ?>">
                                <button type="submit" class="btn-del" title="Delete">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" fill="currentColor" viewBox="0 0 16 16">
                                        <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/>
                                        <path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/>
                                    </svg>
                                    Delete
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$goals): ?>
                        <div class="empty-state">No goals added.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Expenses List -->
        <div class="col-md-6">
            <div class="panel">
                <div class="panel-accent accent-expenses"></div>
                <div class="panel-head">
                    <h6 class="mb-0 fw-semibold text-uppercase extra-small tracking-wider" style="color: var(--ink-soft);">Expenses</h6>
                    <span class="count-pill"><?= count($expenses) ?> total</span>
                </div>
                <div>
                    <?php foreach ($expenses as $e): ?>
                        <div class="row-item">
                            <div class="text-truncate">
                                <span class="row-title text-truncate d-block"><span class="row-dot" style="background:var(--c-expenses);"></span><?= htmlspecialchars($e['title']) ?></span>
                                <span class="row-sub d-block"><?= htmlspecialchars($e['category'] ?? 'Uncategorized') ?> &bull; <?= htmlspecialchars($e['expense_date'] ?? '') ?></span>
                            </div>
                            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                <span class="fw-semibold small" style="color: var(--c-expenses);">-$<?= number_format((float)$e['amount'], 2) ?></span>
                                <form method="post" onsubmit="return confirm('Delete this expense?');">
                                    <input type="hidden" name="action" value="delete_expense">
                                    <input type="hidden" name="id" value="<?= $e['id'] ?>">
                                    <button type="submit" class="btn-del" title="Delete">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" fill="currentColor" viewBox="0 0 16 16">
                                            <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/>
                                            <path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/>
                                        </svg>
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$expenses): ?>
                        <div class="empty-state">No expenses added.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
    const weeklyLabels = <?= json_encode($studyChart['labels']) ?>;
    const weeklyValues = <?= json_encode($studyChart['values']) ?>;
    const subjectLabels = <?= json_encode($subjectLabels) ?>;
    const subjectValues = <?= json_encode($subjectValues) ?>;
    const palette = ['#5457E5', '#0F9B8E', '#E8A93A', '#E15B5B', '#8B5CF6'];

    function chartTheme() {
        const dark = document.body.getAttribute('data-theme') === 'dark';
        return dark ? {
            grid: '#243047',
            tick: '#94a3b8',
            tooltipBg: '#0f172a',
            pointBorder: '#0f172a',
            lineFill: 'rgba(99,102,241,0.18)',
            doughnutBorder: '#111827'
        } : {
            grid: '#EEF0F8',
            tick: '#6B7093',
            tooltipBg: '#1D2140',
            pointBorder: '#ffffff',
            lineFill: 'rgba(84,87,229,0.10)',
            doughnutBorder: '#ffffff'
        };
    }

    function renderCharts() {
        if (!document.getElementById('weeklyChart')) return; // page left via PJAX
        const t = chartTheme();

        if (window.__dpWeeklyChart) window.__dpWeeklyChart.destroy();
        if (window.__dpSubjectChart) window.__dpSubjectChart.destroy();

        window.__dpWeeklyChart = new Chart(document.getElementById('weeklyChart'), {
            type: 'line',
            data: {
                labels: weeklyLabels,
                datasets: [{
                    data: weeklyValues,
                    borderColor: '#5457E5',
                    backgroundColor: t.lineFill,
                    fill: true,
                    tension: 0.35,
                    pointRadius: 4,
                    pointBackgroundColor: '#5457E5',
                    pointBorderColor: t.pointBorder,
                    pointBorderWidth: 2,
                    borderWidth: 2.5,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: t.tooltipBg,
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        padding: 12,
                        displayColors: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: t.grid },
                        ticks: { color: t.tick, font: { size: 11 } }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: t.tick, font: { size: 11 } }
                    }
                }
            }
        });

        if (subjectLabels.length) {
            window.__dpSubjectChart = new Chart(document.getElementById('subjectChart'), {
                type: 'doughnut',
                data: {
                    labels: subjectLabels,
                    datasets: [{
                        data: subjectValues,
                        backgroundColor: palette,
                        borderColor: t.doughnutBorder,
                        borderWidth: 3,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '68%',
                    plugins: { legend: { display: false } }
                }
            });
        }
    }

    renderCharts();
    window.addEventListener('dp:themechange', renderCharts);
</script>
</body>    
</html>
