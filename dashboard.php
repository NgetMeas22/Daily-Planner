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
$expensesPct = $budget > 0 ? (int) round(($spentThisMonth / $budget) * 100) : 0;

$ringOffset = fn($pct) => (string) round(113 - 113 * $pct / 100, 1);
$pctText = fn($pct) => $pct . '%';
$overBudget = $expensesPct >= 100;

// Fetch recent records
$subjects = $conn->query("SELECT id, name, description FROM subjects WHERE user_id = {$userId} ORDER BY id DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);
$goals = $conn->query("SELECT id, goal_name, target_hours, progress, deadline FROM goals WHERE user_id = {$userId} ORDER BY id DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);
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
        SELECT start_time, end_time, GREATEST(TIMESTAMPDIFF(MINUTE, start_time, end_time), 0) / 60 AS hours
        FROM planner
        WHERE user_id = {$userId} AND study_date = '{$today}'
        AND end_time > start_time
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
        SELECT study_date, SUM(GREATEST(TIMESTAMPDIFF(MINUTE, start_time, end_time), 0)) / 60 AS hours
        FROM planner
        WHERE user_id = {$userId} AND study_date BETWEEN '{$monthStart}' AND '{$monthEnd}'
        AND end_time > start_time
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
        SELECT day_name, SUM(GREATEST(TIME_TO_SEC(TIMEDIFF(end_time, start_time)), 0)) / 3600 AS hours
        FROM planner
        WHERE user_id = {$userId} AND study_date BETWEEN '{$weekStart}' AND '{$weekEnd}'
        AND end_time > start_time
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

// ---- Subject distribution ----
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Khmer:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"
        onerror="this.onerror=null;var s=document.createElement('script');s.src='https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js';document.head.appendChild(s);"></script>
    <style>
        :root {
            --paper: #F5F7FA;
            --surface: #FFFFFF;
            --ink: #1A1D2E;
            --ink-soft: #6B7190;
            --border: #E8EBF2;

            --c-subjects: #6C5CE7;
            --c-planner: #0984E3;
            --c-goals: #FDCB6E;
            --c-expenses: #FF6B6B;

            --c-subjects-light: #6C5CE718;
            --c-planner-light: #0984E318;
            --c-goals-light: #FDCB6E22;
            --c-expenses-light: #FF6B6B18;

            --radius: 16px;
            --radius-sm: 10px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,.04), 0 1px 2px rgba(0,0,0,.03);
            --shadow-md: 0 4px 16px rgba(0,0,0,.06), 0 1px 4px rgba(0,0,0,.04);
            --shadow-lg: 0 12px 40px rgba(0,0,0,.08), 0 4px 12px rgba(0,0,0,.04);
            --transition: .2s cubic-bezier(.4,0,.2,1);
        }

        html, body { background: var(--paper); }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            color: var(--ink);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        html[lang="kh"] body { font-family: 'Noto Sans Khmer', 'Inter', sans-serif; }

        /* ---- Animations ---- */
        @keyframes fadeSlideUp {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes scaleIn {
            from { opacity: 0; transform: scale(.95); }
            to { opacity: 1; transform: scale(1); }
        }
        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }
        .anim-up { animation: fadeSlideUp .45s cubic-bezier(.22,1,.36,1) both; }
        .anim-up-1 { animation-delay: .05s; }
        .anim-up-2 { animation-delay: .1s; }
        .anim-up-3 { animation-delay: .15s; }
        .anim-up-4 { animation-delay: .2s; }
        .anim-up-5 { animation-delay: .25s; }
        .anim-up-6 { animation-delay: .3s; }

        /* ---- Hero Panel ---- */
        .hero-panel {
            background: linear-gradient(135deg, #1A1D2E 0%, #2D3156 50%, #1A1D2E 100%);
            border-radius: var(--radius);
            padding: 32px 36px;
            color: #fff;
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
        }
        .hero-panel::before {
            content: "";
            position: absolute;
            top: -80px; right: -40px;
            width: 280px; height: 280px;
            border-radius: 50%;
            background: radial-gradient(circle, var(--c-planner) 0%, transparent 70%);
            opacity: .2;
        }
        .hero-panel::after {
            content: "";
            position: absolute;
            bottom: -60px; left: 30%;
            width: 200px; height: 200px;
            border-radius: 50%;
            background: radial-gradient(circle, var(--c-subjects) 0%, transparent 70%);
            opacity: .15;
        }
        .hero-panel h1 { letter-spacing: -.025em; font-weight: 800; }
        .hero-date {
            background: rgba(255,255,255,.1);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,.15);
            border-radius: 999px;
            padding: 8px 18px;
            font-size: .82rem;
            font-weight: 600;
            white-space: nowrap;
            transition: var(--transition);
        }
        .hero-date:hover { background: rgba(255,255,255,.18); }

        /* ---- Stat Cards ---- */
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px 22px;
            display: flex;
            align-items: center;
            gap: 16px;
            height: 100%;
            position: relative;
            overflow: hidden;
            transition: transform var(--transition), box-shadow var(--transition), border-color var(--transition);
            cursor: default;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
            border-color: transparent;
        }
        .stat-card::after {
            content: '';
            position: absolute;
            right: 0; top: 20%; height: 60%; width: 4px;
            border-radius: 4px 0 0 4px;
            transition: height var(--transition);
        }
        .stat-card:hover::after { height: 80%; }
        .stat-card.stat-subjects::after { background: var(--c-subjects); }
        .stat-card.stat-planner::after { background: var(--c-planner); }
        .stat-card.stat-goals::after { background: var(--c-goals); }
        .stat-card.stat-expenses::after { background: var(--c-expenses); }

        .stat-icon {
            width: 48px; height: 48px;
            border-radius: 13px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            transition: transform var(--transition);
        }
        .stat-card:hover .stat-icon { transform: scale(1.05); }
        .stat-subjects .stat-icon { background: var(--c-subjects-light); color: var(--c-subjects); }
        .stat-planner .stat-icon { background: var(--c-planner-light); color: var(--c-planner); }
        .stat-goals .stat-icon { background: var(--c-goals-light); color: var(--c-goals); }
        .stat-expenses .stat-icon { background: var(--c-expenses-light); color: var(--c-expenses); }

        .stat-info { min-width: 0; }
        .stat-label { color: var(--ink-soft); font-size: .78rem; font-weight: 500; letter-spacing: .02em; margin-bottom: 2px; }
        .stat-value { font-size: 1.65rem; line-height: 1.15; font-weight: 800; color: var(--ink); letter-spacing: -.02em; }
        .stat-sub { font-size: .72rem; color: var(--ink-soft); margin-top: 3px; }

        .stat-progress-ring { width: 46px; height: 46px; position: relative; flex-shrink: 0; margin-left: auto; }
        .stat-progress-ring svg { transform: rotate(-90deg); width: 100%; height: 100%; }
        .stat-progress-ring circle { fill: none; stroke-width: 4.5; stroke-linecap: round; }
        .stat-progress-ring .bg-circle { stroke: var(--border); }
        .stat-progress-ring .val-circle { stroke-dasharray: 113; transition: stroke-dashoffset .6s cubic-bezier(.22,1,.36,1); }
        .stat-subjects .val-circle { stroke: var(--c-subjects); }
        .stat-planner .val-circle { stroke: var(--c-planner); }
        .stat-goals .val-circle { stroke: var(--c-goals); }
        .stat-expenses .val-circle { stroke: var(--c-expenses); }
        .stat-progress-text { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: .65rem; font-weight: 700; color: var(--ink); }
        .stat-progress-text.negative { color: #E17055; }

        /* ---- Panel ---- */
        .panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            height: 100%;
            transition: box-shadow var(--transition);
        }
        .panel:hover { box-shadow: var(--shadow-sm); }
        .panel-head {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .panel-body { padding: 20px; }
        .panel-accent { height: 3px; width: 100%; }
        .accent-subjects { background: linear-gradient(90deg, var(--c-subjects), #A29BFE); }
        .accent-planner { background: linear-gradient(90deg, var(--c-planner), #74B9FF); }
        .accent-goals { background: linear-gradient(90deg, var(--c-goals), #FFEAA7); }
        .accent-expenses { background: linear-gradient(90deg, var(--c-expenses), #FAB1A0); }

        .count-pill {
            background: var(--paper);
            border: 1px solid var(--border);
            color: var(--ink-soft);
            border-radius: 999px;
            padding: 4px 12px;
            font-size: .72rem;
            font-weight: 600;
        }

        /* ---- Forms ---- */
        .form-control, .form-select {
            border-color: var(--border);
            font-size: .85rem;
            border-radius: var(--radius-sm);
            padding: .55rem .85rem;
            transition: border-color var(--transition), box-shadow var(--transition);
        }
        .form-control:focus, .form-select:focus {
            box-shadow: 0 0 0 3px var(--focus-ring, #6C5CE718);
            border-color: var(--focus-border, #6C5CE7);
        }
        .panel-subjects .form-control:focus { --focus-ring: #6C5CE718; --focus-border: #6C5CE7; }
        .panel-planner .form-control:focus, .panel-planner .form-select:focus { --focus-ring: #0984E318; --focus-border: #0984E3; }
        .panel-goals .form-control:focus { --focus-ring: #FDCB6E22; --focus-border: #E17055; }
        .panel-expenses .form-control:focus { --focus-ring: #FF6B6B18; --focus-border: #FF6B6B; }

        .btn-save {
            border: none;
            border-radius: var(--radius-sm);
            font-size: .85rem;
            font-weight: 600;
            padding: .55rem;
            color: #fff;
            transition: filter var(--transition), transform var(--transition);
        }
        .btn-save:hover { filter: brightness(1.08); transform: translateY(-1px); }
        .btn-save:active { transform: translateY(0); }
        .btn-subjects { background: linear-gradient(135deg, #6C5CE7, #A29BFE); }
        .btn-planner { background: linear-gradient(135deg, #0984E3, #74B9FF); }
        .btn-goals { background: linear-gradient(135deg, #E17055, #FDCB6E); }
        .btn-expenses { background: linear-gradient(135deg, #FF6B6B, #FAB1A0); }

        /* ---- Quick-add toggle ---- */
        .quick-add-toggle {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: .82rem;
            font-weight: 600;
            color: var(--ink-soft);
            cursor: pointer;
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            border: 1px dashed var(--border);
            background: transparent;
            transition: var(--transition);
            width: 100%;
            justify-content: center;
        }
        .quick-add-toggle:hover { border-color: var(--c-planner); color: var(--c-planner); background: #0984E308; }
        .quick-add-toggle svg { width: 16px; height: 16px; transition: transform .3s ease; }
        .quick-add-toggle[aria-expanded="true"] svg { transform: rotate(45deg); }
        .quick-add-form { display: none; }
        .quick-add-form.show { display: block; animation: fadeSlideUp .3s ease both; }

        /* ---- List rows ---- */
        .row-item {
            padding: 12px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            transition: background var(--transition);
        }
        .row-item:last-child { border-bottom: none; }
        .row-item:hover { background: var(--paper); }
        .row-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
            flex-shrink: 0;
        }
        .row-title { font-weight: 600; font-size: .85rem; color: var(--ink); }
        .row-sub { color: var(--ink-soft); font-size: .74rem; margin-top: 2px; }

        .btn-del {
            border: 1px solid var(--border);
            background: transparent;
            color: #B5495B;
            border-radius: 8px;
            font-size: .7rem;
            font-weight: 600;
            padding: 4px 10px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            flex-shrink: 0;
            transition: var(--transition);
        }
        .btn-del:hover { background: #FF6B6B12; border-color: #FF6B6B44; color: #D63031; }

        .empty-state { text-align: center; color: var(--ink-soft); padding: 40px 16px; font-size: .82rem; }
        .empty-state svg { width: 48px; height: 48px; opacity: .3; margin-bottom: 12px; }

        /* ---- Chart panels ---- */
        .chart-wrap { position: relative; height: 220px; }
        .chart-wrap canvas { width: 100% !important; height: 100% !important; }
        .legend-dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; margin-right: 6px; }

        .range-switch {
            display: inline-flex;
            gap: 4px;
            background: var(--paper);
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 4px;
        }
        .range-switch a {
            text-decoration: none;
            color: var(--ink-soft);
            font-size: .74rem;
            font-weight: 600;
            padding: 6px 13px;
            border-radius: 999px;
            transition: var(--transition);
        }
        .range-switch a:hover { color: var(--ink); }
        .range-switch a.active {
            background: var(--surface);
            color: var(--ink);
            box-shadow: 0 1px 4px rgba(0,0,0,.08);
        }

        /* ---- Section headers ---- */
        .section-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 16px;
        }
        .section-header h5 {
            font-size: .9rem;
            font-weight: 700;
            color: var(--ink);
            margin: 0;
            letter-spacing: -.01em;
        }
        .section-header .section-line {
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        /* ---- Activity filter chips ---- */
        .activity-filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 16px;
        }
        .filter-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--ink-soft);
            font-size: .78rem;
            font-weight: 600;
            padding: 7px 14px;
            border-radius: 999px;
            cursor: pointer;
            transition: var(--transition);
        }
        .filter-chip:hover { border-color: var(--c-planner); color: var(--c-planner); }
        .filter-chip.active { background: var(--ink); color: #fff; border-color: var(--ink); }
        .filter-chip .chip-count {
            background: rgba(0,0,0,.06);
            color: inherit;
            border-radius: 999px;
            padding: 1px 7px;
            font-size: .68rem;
            font-weight: 700;
        }
        .filter-chip.active .chip-count { background: rgba(255,255,255,.18); }
        .activity-empty-filtered { display: none; }

        /* ---- Responsive ---- */
        /* Activity Feed - Card Grid */
        .activity-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(270px, 1fr));
            gap: 16px;
        }
        .activity-item {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            position: relative;
            transition: transform var(--transition), box-shadow var(--transition);
            animation: fadeSlideUp .4s cubic-bezier(.22,1,.36,1) both;
        }
        .activity-item:hover { transform: translateY(-3px); box-shadow: 0 8px 28px rgba(0,0,0,.08); }
        .activity-item::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
        }
        .activity-item.type-planner::before { background: linear-gradient(90deg, #0984E3, #74B9FF); }
        .activity-item.type-subject::before { background: linear-gradient(90deg, #6C5CE7, #A29BFE); }
        .activity-item.type-goal::before { background: linear-gradient(90deg, #FDCB6E, #FFEAA7); }
        .activity-item.type-expense::before { background: linear-gradient(90deg, #FF6B6B, #FAB1A0); }
        .activity-item-body { padding: 16px 18px; }
        .activity-item-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        .activity-item-icon {
            width: 34px; height: 34px;
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .activity-item-icon svg { width: 16px; height: 16px; }
        .type-planner .activity-item-icon { background: #0984E314; color: #0984E3; }
        .type-subject .activity-item-icon { background: #6C5CE714; color: #6C5CE7; }
        .type-goal .activity-item-icon { background: #FDCB6E18; color: #E17055; }
        .type-expense .activity-item-icon { background: #FF6B6B14; color: #FF6B6B; }
        .activity-item-type {
            font-size: .68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .type-planner .activity-item-type { color: #0984E3; }
        .type-subject .activity-item-type { color: #6C5CE7; }
        .type-goal .activity-item-type { color: #E17055; }
        .type-expense .activity-item-type { color: #FF6B6B; }
        .activity-item-title {
            font-size: .88rem;
            font-weight: 600;
            color: var(--ink);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .activity-item-meta {
            font-size: .75rem;
            color: var(--ink-soft);
            margin-top: 4px;
        }
        .activity-item-progress {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid var(--border);
        }
        .activity-item-progress-bar {
            flex-grow: 1;
            height: 5px;
            background: var(--border);
            border-radius: 99px;
            overflow: hidden;
        }
        .activity-item-progress-fill { height: 100%; border-radius: 99px; transition: width .5s ease; }
        .activity-item-progress-text {
            font-size: .72rem;
            font-weight: 600;
            color: var(--ink-soft);
            min-width: 28px;
        }
        .activity-item-amount {
            font-size: .88rem;
            font-weight: 700;
            color: var(--c-expenses);
            margin-top: 6px;
        }
        .activity-item-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 18px;
            border-top: 1px solid var(--border);
            background: var(--paper);
        }
        .activity-item-date { font-size: .7rem; color: var(--ink-soft); }
        .btn-activity-del {
            padding: 4px 10px;
            border-radius: 7px;
            font-size: .68rem;
            font-weight: 600;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--ink-soft);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: var(--transition);
            opacity: 0;
        }
        .activity-item:hover .btn-activity-del { opacity: 1; }
        .btn-activity-del:hover { border-color: #FF6B6B; color: #FF6B6B; background: #FF6B6B08; }

        @media (max-width: 767.98px) {
            .hero-panel { padding: 22px 20px; }
            .hero-panel h1 { font-size: 1.3rem; }
            .stat-card { padding: 14px 16px; gap: 12px; }
            .stat-value { font-size: 1.35rem; }
            .stat-icon { width: 40px; height: 40px; }
            .stat-progress-ring { width: 38px; height: 38px; }
            .activity-grid { grid-template-columns: 1fr; }
            .btn-activity-del { opacity: 1; }
        }
    </style>
</head>
<body data-theme="<?php echo htmlspecialchars(current_theme()); ?>">
<?php $activePage = 'dashboard'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="container py-4" style="max-width: 1280px;">

    <!-- Hero -->
    <div class="hero-panel mb-4 d-flex align-items-center justify-content-between flex-wrap gap-3 anim-up">
        <div>
            <h1 class="h3 fw-bold mb-1">Hello, <?= htmlspecialchars($userName) ?></h1>
            <p class="mb-0 small" style="color: rgba(255,255,255,.65);">Here's your study overview at a glance.</p>
        </div>
        <span class="hero-date"><?= date('l, j M Y') ?></span>
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

    <!-- Charts -->
    <div class="row g-3 mb-4">
        <div class="col-lg-7 anim-up anim-up-5">
            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h6 class="mb-1 fw-bold" style="letter-spacing:-.01em;"><?= htmlspecialchars($studyChart['label']) ?> Study Hours</h6>
                        <small style="color:var(--ink-soft);"><?= htmlspecialchars($studyChart['summary']) ?></small>
                    </div>
                    <div class="range-switch" aria-label="Study time range">
                        <a href="?range=day" class="<?= $studyRange === 'day' ? 'active' : '' ?>">Day</a>
                        <a href="?range=week" class="<?= $studyRange === 'week' ? 'active' : '' ?>">Week</a>
                        <a href="?range=month" class="<?= $studyRange === 'month' ? 'active' : '' ?>">Month</a>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="d-flex justify-content-between align-items-center mb-2" style="font-size:.72rem;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.06em;font-weight:600;">
                        <span>Total study time</span>
                        <span style="color:var(--ink);font-weight:700;"><?= number_format(max(0, $studyChart['totalHours']), 1) ?>h</span>
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
                <div class="panel-body">
                    <?php if ($subjectLabels): ?>
                        <div class="chart-wrap" style="height:175px;"><canvas id="subjectChart"></canvas></div>
                        <div class="d-flex flex-wrap gap-3 justify-content-center mt-3" style="font-size:.72rem;">
                            <?php
                            $palette = ['#6C5CE7', '#0984E3', '#E17055', '#FF6B6B', '#00B894'];
                            foreach ($subjectLabels as $i => $lbl): ?>
                                <span><span class="legend-dot" style="background:<?= $palette[$i % count($palette)] ?>;"></span><?= htmlspecialchars($lbl) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 12h8M12 8v8"/></svg>
                            <div>No planner sessions yet.<br>Add one to see the breakdown.</div>
                        </div>
                    <?php endif; ?>
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
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
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
    <div class="empty-state activity-empty-filtered" id="activityEmptyFiltered">
        Nothing here yet for this filter.
    </div>
    <?php endif; ?>
</div>

<script>
    const weeklyLabels = <?= json_encode($studyChart['labels']) ?>;
    const weeklyValues = <?= json_encode($studyChart['values']) ?>.map(v => Math.max(0, v));
    const subjectLabels = <?= json_encode($subjectLabels) ?>;
    const subjectValues = <?= json_encode($subjectValues) ?>;
    const palette = ['#6C5CE7', '#0984E3', '#E17055', '#FF6B6B', '#00B894'];

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

    // Single, defensive entry point for chart rendering. Retries briefly if
    // Chart.js hasn't finished loading yet (slow network / CDN hiccup),
    // and fails visibly instead of leaving a silent blank canvas.
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
                    labels: weeklyLabels,
                    datasets: [{
                        data: weeklyValues,
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

            const subjectCanvas = document.getElementById('subjectChart');
            if (subjectCanvas && subjectLabels.length) {
                window.__dpSubjectChart = new Chart(subjectCanvas, {
                    type: 'doughnut',
                    data: {
                        labels: subjectLabels,
                        datasets: [{
                            data: subjectValues,
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
        } catch (err) {
            console.error('Dashboard chart render failed:', err);
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        renderCharts();

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
    });

    window.addEventListener('dp:themechange', renderCharts);
</script>
</body>
</html>