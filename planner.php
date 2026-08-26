<?php
    require_once __DIR__ . '/includes/auth.php';
    require_login();
    $currentLang = $_SESSION['lang'] ?? 'en';

    $userId = (int) $_SESSION['user_id'];
    $userName = $_SESSION['user_name'] ?? 'User';
    $errors = [];
    $successMsg = '';

    // Selected Date Handling
    $selectedDate = $_GET['date'] ?? date('Y-m-d');
    $selectedDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate) ? $selectedDate : date('Y-m-d');

    $copyFromDate = $_GET['copy_from'] ?? '';
    $copyFromDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $copyFromDate) ? $copyFromDate : '';

    // Day navigation helpers
    $selectedTs = strtotime($selectedDate);
    $prevDate = date('Y-m-d', strtotime('-1 day', $selectedTs));
    $nextDate = date('Y-m-d', strtotime('+1 day', $selectedTs));
    $todayDate = date('Y-m-d');

    // --- POST REQUEST HANDLERS ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        // Action 1: Add Tasks to Planner
        if ($action === 'add_planner') {
            $selectedSubjects = $_POST['subject_ids'] ?? [];
            $startTimes = $_POST['start_time'] ?? [];
            $endTimes = $_POST['end_time'] ?? [];
            $studyDate = $_POST['study_date'] ?? $selectedDate;
            $dayName = $_POST['day_name'] ?? '';

            if (empty($selectedSubjects) || empty($studyDate) || empty($dayName)) {
                $errors[] = 'Please select at least one subject and set its time.';
            } else {
                $stmt = $conn->prepare('INSERT INTO planner (user_id, subject_id, study_date, day_name, start_time, end_time, topic, goal, progress, status, result) VALUES (?, ?, ?, ?, ?, ?, "", "", 0, "Pending", "")');
                
                foreach ($selectedSubjects as $subId) {
                    $subjectId = (int)$subId;
                    $sTime = $startTimes[$subjectId] ?? '';
                    $eTime = $endTimes[$subjectId] ?? '';

                    if ($subjectId > 0 && !empty($sTime) && !empty($eTime)) {
                        $stmt->bind_param('iissss', $userId, $subjectId, $studyDate, $dayName, $sTime, $eTime);
                        $stmt->execute();
                    }
                }
                $stmt->close();
                redirect('planner.php?date=' . urlencode($studyDate));
            }
        }

        // Action 2: Toggle Done Status
        if ($action === 'toggle_done') {
            $id = (int) ($_POST['planner_id'] ?? 0);
            $done = isset($_POST['done']) ? 1 : 0;
            $progress = $done ? 100 : 0;
            $status = $done ? 'Completed' : 'Pending';
            $result = trim($_POST['result'] ?? '');

            $stmt = $conn->prepare('UPDATE planner SET progress = ?, status = ?, result = ? WHERE id = ? AND user_id = ?');
            $stmt->bind_param('issii', $progress, $status, $result, $id, $userId);
            $stmt->execute();
            redirect('planner.php?date=' . urlencode($selectedDate));
        }
    }

    // --- DELETE HANDLER ---
    if (isset($_GET['delete'])) {
        $id = (int) $_GET['delete'];
        $stmt = $conn->prepare('DELETE FROM planner WHERE id = ? AND user_id = ?');
        $stmt->bind_param('ii', $id, $userId);
        $stmt->execute();
        redirect('planner.php?date=' . urlencode($selectedDate));
    }

    // --- DATA FETCHING ---
    // Fetch Subjects List
    $subjectsStmt = $conn->prepare('SELECT id, name FROM subjects WHERE user_id = ? ORDER BY name ASC');
    $subjectsStmt->bind_param('i', $userId);
    $subjectsStmt->execute();
    $subjects = $subjectsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Fetch Today's Tasks
    $dayRowsStmt = $conn->prepare("
        SELECT p.id, p.study_date, p.day_name, p.start_time, p.end_time, p.progress, p.status, s.name AS subject_name
        FROM planner p
        INNER JOIN subjects s ON s.id = p.subject_id
        WHERE p.user_id = ? AND p.study_date = ?
        ORDER BY p.start_time ASC, p.id ASC
    ");
    $dayRowsStmt->bind_param('is', $userId, $selectedDate);
    $dayRowsStmt->execute();
    $dayRows = $dayRowsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Today Summary Calculations
    $totalToday = count($dayRows);
    $doneToday = 0;
    $totalSecondsSpent = 0;

    foreach ($dayRows as $row) {
        if ((int)$row['progress'] >= 100 || $row['status'] === 'Completed') {
            $doneToday++;
        }
        
        if (!empty($row['start_time']) && !empty($row['end_time'])) {
            $t1 = strtotime($row['start_time']);
            $t2 = strtotime($row['end_time']);
            if ($t2 > $t1) {
                $totalSecondsSpent += ($t2 - $t1);
            }
        }
    }

    $dayPercent = $totalToday > 0 ? (int) round(($doneToday / $totalToday) * 100) : 0;
    $hoursSpent = floor($totalSecondsSpent / 3600);
    $minutesSpent = floor(($totalSecondsSpent % 3600) / 60);
    $secondsDone = 0;
    $secondsNotDone = 0;

    foreach ($dayRows as $row) {
        $taskSeconds = 0;
        if (!empty($row['start_time']) && !empty($row['end_time'])) {
            $startTs = strtotime($row['start_time']);
            $endTs = strtotime($row['end_time']);
            if ($endTs > $startTs) {
                $taskSeconds = $endTs - $startTs;
            }
        }

        if ((int)$row['progress'] >= 100 || $row['status'] === 'Completed') {
            $secondsDone += $taskSeconds;
        } else {
            $secondsNotDone += $taskSeconds;
        }
    }

    $hoursDone = floor($secondsDone / 3600);
    $minutesDone = floor(($secondsDone % 3600) / 60);
    $hoursNotDone = floor($secondsNotDone / 3600);
    $minutesNotDone = floor(($secondsNotDone % 3600) / 60);

    // Fetch History Data (limited to a rolling 30-day window so the page stays fast)
    $historyStart = date('Y-m-d', strtotime('-29 days', $selectedTs));
    $historyStmt = $conn->prepare("
        SELECT p.id, p.study_date, p.day_name, p.start_time, p.end_time, p.progress, p.status, s.name AS subject_name
        FROM planner p
        INNER JOIN subjects s ON s.id = p.subject_id
        WHERE p.user_id = ? AND p.study_date <= ? AND p.study_date >= ?
        ORDER BY p.study_date DESC, p.start_time ASC
    ");
    $historyStmt->bind_param('iss', $userId, $selectedDate, $historyStart);
    $historyStmt->execute();
    $allHistoryRows = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Group history by date
    $historyByDate = [];
    foreach ($allHistoryRows as $hRow) {
        $historyByDate[$hRow['study_date']][] = $hRow;
    }

    $recentPlanDates = [];
    foreach (array_keys($historyByDate) as $dateKey) {
        if ($dateKey !== $selectedDate) {
            $recentPlanDates[] = $dateKey;
        }
    }
    $recentPlanDates = array_slice($recentPlanDates, 0, 7);

    $copyTemplateRows = [];
    if ($copyFromDate && $copyFromDate !== $selectedDate) {
        $copyStmt = $conn->prepare("
            SELECT p.subject_id, p.start_time, p.end_time, s.name AS subject_name
            FROM planner p
            INNER JOIN subjects s ON s.id = p.subject_id
            WHERE p.user_id = ? AND p.study_date = ?
            ORDER BY p.start_time ASC, p.id ASC
        ");
        $copyStmt->bind_param('is', $userId, $copyFromDate);
        $copyStmt->execute();
        $copyTemplateRows = $copyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $copyStmt->close();
    }

    $copyTemplateMap = [];
    foreach ($copyTemplateRows as $templateRow) {
        $copyTemplateMap[(int) $templateRow['subject_id']] = $templateRow;
    }

    // Overall completion totals across ALL history (cheap aggregate used in the "Download All" grand summary)
    $aggStmt = $conn->prepare("
        SELECT COUNT(*) AS total,
               COALESCE(SUM(CASE WHEN progress >= 100 OR status = 'Completed' THEN 1 ELSE 0 END), 0) AS done
        FROM planner WHERE user_id = ? AND study_date <= ?
    ");
    $aggStmt->bind_param('is', $userId, $selectedDate);
    $aggStmt->execute();
    $aggRow = $aggStmt->get_result()->fetch_assoc();
    $aggStmt->close();
    $allHistoryTotal = (int) ($aggRow['total'] ?? 0);
    $allHistoryDone = (int) ($aggRow['done'] ?? 0);
    ?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Planner & History</title>
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
            --radius: 16px;
            --radius-sm: 10px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,.04);
            --shadow-md: 0 4px 16px rgba(0,0,0,.06);
            --transition: .2s cubic-bezier(.4,0,.2,1);
            --success: #00B894;
            --danger: #FF6B6B;
            --warning: #FDCB6E;
        }

        html, body { background: var(--paper); }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; color: var(--ink); -webkit-font-smoothing: antialiased; }
        html[lang="kh"] body { font-family: 'Noto Sans Khmer', 'Inter', sans-serif; }

        @keyframes fadeSlideUp { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: translateY(0); } }
        .anim-up { animation: fadeSlideUp .4s cubic-bezier(.22,1,.36,1) both; }
        .anim-1 { animation-delay: .05s; }
        .anim-2 { animation-delay: .1s; }
        .anim-3 { animation-delay: .15s; }
        .anim-4 { animation-delay: .2s; }
        .anim-5 { animation-delay: .25s; }

        /* ---- Hero ---- */
        .planner-hero {
            background: linear-gradient(135deg, #0D47A1 0%, #0984E3 50%, #74B9FF 100%);
            border-radius: var(--radius);
            padding: 28px 32px;
            color: #fff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 12px 40px rgba(9,132,227,.15);
        }
        .planner-hero::after {
            content: "";
            position: absolute;
            top: -50px; right: -30px;
            width: 200px; height: 200px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255,255,255,.18) 0%, transparent 70%);
        }
        .planner-hero h2 { margin: 0; font-weight: 700; }
        .planner-hero p { margin: 0; opacity: .7; font-size: .85rem; }
        .hero-badge {
            background: rgba(255,255,255,.1);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,.15);
            border-radius: 999px;
            padding: 8px 18px;
            font-size: .82rem;
            font-weight: 600;
            color: #fff;
        }

        /* ---- Date Nav ---- */
        .date-nav-btn {
            width: 36px; height: 36px;
            display: inline-flex; align-items: center; justify-content: center;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--ink-soft);
            text-decoration: none;
            transition: var(--transition);
        }
        .date-nav-btn:hover { background: var(--paper); color: var(--ink); border-color: var(--ink-soft); }

        /* ---- Stat Cards ---- */
        .stat-card {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            padding: 24px;
            position: relative;
            overflow: hidden;
            transition: box-shadow var(--transition), transform var(--transition);
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .stat-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
        .stat-card::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 5px;
        }
        .stat-card.stat-accent::before { background: linear-gradient(180deg, #0984E3, #74B9FF); }
        .stat-card.stat-success::before { background: linear-gradient(180deg, #00B894, #55EFC4); }
        .stat-card.stat-neutral::before { background: linear-gradient(180deg, #6B7190, #a0a8c0); }
        .stat-card .stat-icon-wrap {
            width: 40px; height: 40px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem;
            margin-bottom: 8px;
        }
        .stat-card.stat-accent .stat-icon-wrap { background: rgba(9,132,227,.1); color: var(--accent); }
        .stat-card.stat-success .stat-icon-wrap { background: rgba(0,184,148,.1); color: var(--success); }
        .stat-card.stat-neutral .stat-icon-wrap { background: rgba(107,113,144,.1); color: var(--ink-soft); }
        .stat-label { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--ink-soft); margin-bottom: 2px; }
        .stat-value { font-size: 2.2rem; font-weight: 800; line-height: 1.1; }
        .stat-value.text-accent { color: var(--accent); }
        .stat-value.text-success { color: var(--success); }
        .stat-value.text-dark { color: var(--ink); }
        .stat-sub { font-size: .75rem; color: var(--ink-soft); margin-top: 4px; }
        .stat-progress { height: 8px; background: var(--paper); border-radius: 99px; margin-top: 12px; overflow: hidden; }
        .stat-progress-bar { height: 100%; background: linear-gradient(90deg, #0984E3, #74B9FF); border-radius: 99px; transition: width .5s ease; }

        /* ---- Section Header ---- */
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--border);
        }
        .section-header h5 { margin: 0; font-weight: 700; font-size: .95rem; }

        /* ---- Cards ---- */
        .card-surface {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            transition: box-shadow var(--transition);
        }
        .card-surface:hover { box-shadow: var(--shadow-md); }

        /* ---- Add Form ---- */
        .add-form-card {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            transition: box-shadow var(--transition);
        }
        .add-form-card:hover { box-shadow: var(--shadow-md); }
        .add-form-toggle {
            padding: 18px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            user-select: none;
        }
        .add-form-toggle h6 { margin: 0; font-weight: 700; font-size: .9rem; }
        .add-form-body { padding: 24px; display: none; }
        .add-form-body.show { display: block; animation: fadeSlideUp .3s ease both; }

        /* ---- Copy Box ---- */
        .copy-box {
            border: 2px dashed #90caf9;
            border-radius: var(--radius);
            background: #e3f2fd08;
            padding: 16px 20px;
        }

        /* ---- Subject Grid Card ---- */
        .subject-card {
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 12px;
            background: var(--surface);
            transition: var(--transition);
        }
        .subject-card.checked { border-color: var(--accent); background: rgba(9,132,227,.03); }
        .subject-card label { cursor: pointer; margin: 0; width: 100%; display: flex; align-items: center; gap: 8px; }
        .subject-card .form-label { margin: 0; font-size: .82rem; font-weight: 600; color: var(--ink); }
        .time-group { display: flex; align-items: center; gap: 6px; margin-top: 8px; }
        .time-group .time-sep { font-size: .72rem; color: var(--ink-soft); }
        .time-group input[type="time"] {
            flex: 1;
            padding: 6px 8px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: .78rem;
            background: var(--surface);
            color: var(--ink);
            transition: var(--transition);
        }
        .time-group input[type="time"]:disabled { background: var(--paper); color: var(--ink-soft); opacity: .5; }
        .time-group input[type="time"]:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(9,132,227,.12); }

        /* ---- Form Controls ---- */
        .form-control, .form-select {
            border-color: var(--border);
            font-size: .85rem;
            border-radius: var(--radius-sm);
            padding: .6rem .9rem;
            transition: var(--transition);
        }
        .form-control:focus, .form-select:focus {
            box-shadow: 0 0 0 3px rgba(9,132,227,.12);
            border-color: var(--accent);
        }

        /* ---- Buttons ---- */
        .btn-accent {
            background: linear-gradient(135deg, #0984E3, #74B9FF);
            border: none;
            border-radius: var(--radius-sm);
            padding: .6rem 1.5rem;
            font-weight: 600;
            font-size: .85rem;
            color: #fff;
            transition: var(--transition);
        }
        .btn-accent:hover { filter: brightness(1.08); transform: translateY(-1px); color: #fff; }
        .btn-outline-soft {
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: .55rem 1.2rem;
            font-weight: 500;
            font-size: .82rem;
            color: var(--ink-soft);
            background: var(--surface);
            transition: var(--transition);
        }
        .btn-outline-soft:hover { border-color: var(--ink-soft); color: var(--ink); }

        /* ---- Checklist ---- */
        .checklist-card {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
        }
        .task-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            border-bottom: 1px solid var(--border);
            transition: background var(--transition);
        }
        .task-row:last-child { border-bottom: none; }
        .task-row:hover { background: rgba(9,132,227,.02); }
        .task-row.done { background: rgba(0,184,148,.03); }
        .task-subject {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 8px;
            font-size: .78rem;
            font-weight: 600;
            border: 1px solid #e3f2fd;
            background: #e3f2fd;
            color: #1565c0;
        }
        .task-row.done .task-subject {
            background: var(--paper);
            color: var(--ink-soft);
            border-color: var(--border);
            text-decoration: line-through;
        }
        .task-time {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 6px;
            font-size: .72rem;
            font-weight: 600;
            background: var(--paper);
            color: var(--ink-soft);
            border: 1px solid var(--border);
        }
        .task-time i { font-size: .68rem; }
        .task-delete {
            padding: 5px 10px;
            border-radius: 8px;
            font-size: .72rem;
            font-weight: 600;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--ink-soft);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: var(--transition);
        }
        .task-delete:hover { border-color: var(--danger); color: var(--danger); background: #FF6B6B08; }

        /* ---- Empty State ---- */
        .empty-state {
            text-align: center;
            padding: 48px 16px;
            color: var(--ink-soft);
        }
        .empty-state svg { width: 52px; height: 52px; opacity: .2; margin-bottom: 14px; }
        .empty-state p { font-size: .85rem; margin: 0; }

        /* ---- Footer ---- */
        .checklist-footer {
            padding: 14px 20px;
            background: var(--paper);
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }
        .checklist-footer .footer-stats { font-size: .75rem; color: var(--ink-soft); display: flex; flex-wrap: wrap; gap: 4px 14px; }
        .checklist-footer .footer-stats strong { color: var(--ink); }
        .checklist-footer .footer-stats .text-success { color: var(--success); }
        .checklist-footer .footer-stats .text-danger { color: var(--danger); }

        /* ---- Alert ---- */
        .alert-error {
            background: #fff5f5;
            border: 1px solid #fed7d7;
            color: #c53030;
            border-radius: var(--radius-sm);
            padding: 12px 16px;
            font-size: .85rem;
        }

        /* ---- Report Modal ---- */
        #plannerReportModal .modal-content {
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--ink);
        }
        #plannerReportModal .modal-body { background: var(--surface); color: var(--ink); }
        #plannerReportModal .modal-header { border-bottom: 1px solid var(--border); }
        #plannerReportModal .modal-footer { background: var(--paper); border-top: 1px solid var(--border); }
        .report-stat-card {
            border-radius: var(--radius);
            padding: 18px 20px;
            border: 1.5px solid var(--border);
            background: var(--surface);
            transition: box-shadow var(--transition);
        }
        .report-stat-card:hover { box-shadow: var(--shadow-sm); }
        .report-stat-card.bg-accent-light { background: rgba(9,132,227,.06); border-color: rgba(9,132,227,.2); }
        .report-stat-card.bg-success-light { background: rgba(0,184,148,.06); border-color: rgba(0,184,148,.2); }
        .report-day-card {
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 16px;
            background: var(--surface);
            margin-bottom: 12px;
            transition: box-shadow var(--transition);
        }
        .report-day-card:hover { box-shadow: var(--shadow-sm); }
        .report-day-card:last-child { margin-bottom: 0; }
        .report-day-card .day-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 10px;
            flex-wrap: wrap;
            gap: 8px;
        }

        /* ---- Modal ---- */
        #plannerReportModal .modal-content {
            background: var(--surface);
            border: 1px solid var(--border);
        }
        #plannerReportModal .modal-body { background: var(--surface); color: var(--ink); }
        #plannerReportModal .modal-footer { background: var(--paper); }

        /* ---- Dark Mode ---- */
        body[data-theme="dark"] {
            --paper: #0b1120;
            --surface: #111827;
            --ink: #e2e8f0;
            --ink-soft: #94a3b8;
            --border: #243047;
        }
        body[data-theme="dark"] .subject-card.checked { background: rgba(9,132,227,.08); }
        body[data-theme="dark"] .task-row:hover { background: rgba(9,132,227,.05); }
        body[data-theme="dark"] .task-row.done { background: rgba(0,184,148,.05); }
        body[data-theme="dark"] .task-subject { background: rgba(9,132,227,.15); border-color: rgba(9,132,227,.25); color: #74b9ff; }
        body[data-theme="dark"] .task-row.done .task-subject { background: var(--paper); color: var(--ink-soft); border-color: var(--border); }
        body[data-theme="dark"] .copy-box { border-color: rgba(9,132,227,.35); background: rgba(9,132,227,.05); }
        body[data-theme="dark"] .stat-progress { background: rgba(255,255,255,.06); }
        body[data-theme="dark"] .report-day-card { background: var(--surface); }
        body[data-theme="dark"] #plannerReportModal .modal-content { background: var(--surface); border-color: var(--border); }

        /* ---- Responsive ---- */
        @media (max-width: 767.98px) {
            .planner-hero { padding: 20px; }
            .add-form-body { padding: 16px; }
            .task-row { padding: 10px 14px; }
            .checklist-footer { padding: 12px 14px; }
            .stat-card { padding: 18px; }
            .stat-value { font-size: 1.7rem; }
        }
    </style>
</head>
<body data-theme="<?php echo htmlspecialchars(current_theme()); ?>">

<?php $activePage = 'planner'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="container py-4 py-md-5" style="max-width: 1200px;">

    <!-- Hero -->
    <div class="planner-hero mb-4 d-flex align-items-center justify-content-between flex-wrap gap-3 anim-up">
        <div>
            <h2 class="h3 fw-bold mb-1">Daily Planner</h2>
            <p class="mb-0">Organize and manage your daily activities easily.</p>
        </div>
        <form method="get" class="d-flex align-items-center gap-2 flex-wrap" style="z-index:2;position:relative;">
            <div class="d-flex align-items-center gap-1">
                <a href="planner.php?date=<?php echo urlencode($prevDate); ?>" title="Previous day" class="date-nav-btn" style="border-color:rgba(255,255,255,.2);background:rgba(255,255,255,.1);color:#fff;">
                    <i class="bi bi-chevron-left"></i>
                </a>
                <input type="date" class="form-control form-control-sm" style="border-color:rgba(255,255,255,.2);background:rgba(255,255,255,.1);color:#fff;border-radius:var(--radius-sm);width:auto;" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>">
                <a href="planner.php?date=<?php echo urlencode($nextDate); ?>" title="Next day" class="date-nav-btn" style="border-color:rgba(255,255,255,.2);background:rgba(255,255,255,.1);color:#fff;">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </div>
            <button type="submit" class="btn btn-sm fw-semibold" style="background:rgba(255,255,255,.2);color:#fff;border:1px solid rgba(255,255,255,.2);border-radius:var(--radius-sm);">
                <i class="bi bi-eye me-1"></i>View
            </button>
            <?php if ($selectedDate !== $todayDate): ?>
                <a href="planner.php" class="btn btn-sm fw-semibold" style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.15);border-radius:var(--radius-sm);">
                    <i class="bi bi-calendar-day me-1"></i>Today
                </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Errors -->
    <?php if ($errors): ?>
        <div class="alert alert-error mb-4 anim-up anim-1">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            <?php echo htmlspecialchars(implode(' ', $errors)); ?>
        </div>
    <?php endif; ?>

    <!-- Stat Cards -->
    <div class="row g-3 mb-4 anim-up anim-1">
        <div class="col-12 col-md-4">
            <div class="stat-card stat-accent h-100">
                <div class="stat-icon-wrap"><i class="bi bi-pie-chart-fill"></i></div>
                <div class="stat-label">Date Summary</div>
                <div class="stat-value text-accent"><?php echo $dayPercent; ?>%</div>
                <div class="stat-progress">
                    <div class="stat-progress-bar" style="width: <?php echo $dayPercent; ?>%;"></div>
                </div>
                <div class="stat-sub"><?php echo $doneToday; ?> / <?php echo $totalToday; ?> tasks done &middot; <?php echo $hoursDone; ?>h <?php echo $minutesDone; ?>m logged</div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="stat-card stat-success h-100">
                <div class="stat-icon-wrap"><i class="bi bi-check-circle-fill"></i></div>
                <div class="stat-label">Tasks Completed</div>
                <div class="stat-value text-success"><?php echo $doneToday; ?></div>
                <div class="stat-sub">Marked completed with <i class="bi bi-check-circle-fill" style="color:var(--success);"></i></div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="stat-card stat-neutral h-100">
                <div class="stat-icon-wrap"><i class="bi bi-list-task"></i></div>
                <div class="stat-label">Total Tasks</div>
                <div class="stat-value text-dark"><?php echo $totalToday; ?></div>
                <div class="stat-sub">Tasks planned for <?php echo htmlspecialchars(date('M j', strtotime($selectedDate))); ?></div>
            </div>
        </div>
    </div>

    <!-- Add Daily Tasks Form -->
    <div class="add-form-card mb-4 anim-up anim-2">
        <div class="add-form-toggle" id="addFormToggle">
            <h6 class="d-flex align-items-center gap-2">
                <i class="bi bi-plus-circle" style="color:var(--accent);"></i>
                Add Daily Tasks
            </h6>
            <div class="d-flex align-items-center gap-3">
                <span class="hero-badge" style="background:rgba(9,132,227,.08);border-color:rgba(9,132,227,.15);color:var(--accent);font-size:.75rem;padding:5px 14px;">
                    <?php echo htmlspecialchars(date('l, F j, Y', strtotime($selectedDate))); ?>
                </span>
                <i id="addFormChevron" class="bi bi-chevron-down" style="color:var(--ink-soft);transition:transform .2s ease;"></i>
            </div>
        </div>
        <div class="add-form-body show" id="addFormBody">

            <!-- Copy from previous day -->
            <div class="copy-box mb-4">
                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
                    <div>
                        <p class="fw-semibold small mb-1" style="color:var(--accent);"><i class="bi bi-calendar2-check me-1"></i>Follow previous day</p>
                        <p class="mb-0" style="font-size:.78rem;color:var(--ink-soft);">Copy subjects and times from an older plan, then adjust if needed.</p>
                    </div>
                    <form method="get" class="d-flex flex-column flex-sm-row gap-2 align-items-stretch align-items-sm-center">
                        <input type="hidden" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>">
                        <select name="copy_from" class="form-select form-select-sm" style="min-width:180px;">
                            <option value="">Choose a previous day</option>
                            <?php foreach ($recentPlanDates as $dateKey): ?>
                                <option value="<?php echo htmlspecialchars($dateKey); ?>" <?php echo $copyFromDate === $dateKey ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(date('D, M j', strtotime($dateKey))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-accent btn-sm">
                            <i class="bi bi-arrow-repeat me-1"></i>
                        </button>
                    </form>
                </div>
                <?php if ($copyFromDate && $copyTemplateRows): ?>
                    <div class="mt-3" style="font-size:.78rem;color:var(--accent);">
                        <i class="bi bi-check-circle-fill me-1"></i>
                        Loaded from <strong><?php echo htmlspecialchars(date('l, F j, Y', strtotime($copyFromDate))); ?></strong>.
                    </div>
                <?php elseif ($copyFromDate): ?>
                    <div class="mt-3" style="font-size:.78rem;color:#d97706;">
                        <i class="bi bi-exclamation-circle me-1"></i>
                        No saved plan found for <strong><?php echo htmlspecialchars(date('l, F j, Y', strtotime($copyFromDate))); ?></strong>.
                    </div>
                <?php endif; ?>
            </div>

            <form method="post">
                <input type="hidden" name="action" value="add_planner">
                <input type="hidden" name="study_date" value="<?php echo htmlspecialchars($selectedDate); ?>">

                <!-- Subject Grid -->
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <label class="form-label fw-semibold mb-0" style="font-size:.85rem;color:var(--ink);">Select Subjects & Set Time</label>
                        <label class="form-check-label d-inline-flex align-items-center gap-2" style="font-size:.82rem;color:var(--accent);cursor:pointer;font-weight:600;">
                            <input type="checkbox" id="selectAllSubjects" class="form-check-input">
                            Select All
                        </label>
                    </div>
                    <div class="row g-3">
                        <?php foreach ($subjects as $subject): 
                            $sId = (int)$subject['id'];
                            $template = $copyTemplateMap[$sId] ?? null;
                            $isChecked = $template !== null;
                            $startValue = $template['start_time'] ?? '';
                            $endValue = $template['end_time'] ?? '';
                        ?>
                            <div class="col-12 col-md-6 col-lg-4">
                                <div class="subject-card <?php echo $isChecked ? 'checked' : ''; ?>" id="card-<?php echo $sId; ?>">
                                    <label>
                                        <input type="checkbox" class="form-check-input subject-checkbox" name="subject_ids[]" value="<?php echo $sId; ?>" id="sub-<?php echo $sId; ?>" <?php echo $isChecked ? 'checked' : ''; ?> onchange="toggleTimeInputs(<?php echo $sId; ?>)">
                                        <span class="form-label"><?php echo htmlspecialchars($subject['name']); ?></span>
                                    </label>
                                    <div class="time-group" id="time-group-<?php echo $sId; ?>">
                                        <input type="time" name="start_time[<?php echo $sId; ?>]" <?php echo $isChecked ? '' : 'disabled'; ?> value="<?php echo htmlspecialchars($startValue); ?>" required>
                                        <span class="time-sep">to</span>
                                        <input type="time" name="end_time[<?php echo $sId; ?>]" <?php echo $isChecked ? '' : 'disabled'; ?> value="<?php echo htmlspecialchars($endValue); ?>" required>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Day Selector -->
                <div class="mb-4" style="max-width:280px;">
                    <label class="form-label fw-semibold" style="font-size:.85rem;">Day</label>
                    <select class="form-select form-select-sm" name="day_name" required>
                        <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $day): ?>
                            <option value="<?php echo $day; ?>" <?php echo (date('l', strtotime($selectedDate)) === $day) ? 'selected' : ''; ?>><?php echo $day; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-accent w-100 fw-bold">
                    <i class="bi bi-plus-lg me-1"></i>Save All Planned Tasks
                </button>
            </form>
        </div>
    </div>

    <!-- Today's Checklist -->
    <div class="checklist-card mb-4 anim-up anim-3">
        <!-- Header -->
        <div class="d-flex flex-column flex-sm-row justify-content-sm-between align-items-sm-center p-4 gap-2">
            <div>
                <h5 class="fw-bold mb-0" style="font-size:.95rem;"><i class="bi bi-list-check me-2" style="color:var(--accent);"></i>Today's Checklist</h5>
                <p class="mb-0 mt-1" style="font-size:.75rem;color:var(--ink-soft);">Manage your schedule and track progress</p>
            </div>
            <span class="badge" style="background:var(--paper);color:var(--ink-soft);border:1px solid var(--border);border-radius:999px;padding:5px 14px;font-size:.75rem;font-weight:600;">
                <i class="bi bi-calendar3 me-1"></i><?php echo htmlspecialchars($selectedDate); ?>
            </span>
        </div>

        <!-- Mobile View -->
        <div class="d-block d-md-none">
            <?php foreach ($dayRows as $row): 
                $isDone = ((int)$row['progress'] >= 100 || $row['status'] === 'Completed');
                $formattedStart = !empty($row['start_time']) ? date('h:i A', strtotime($row['start_time'])) : '';
                $formattedEnd   = !empty($row['end_time'])   ? date('h:i A', strtotime($row['end_time']))   : '';
            ?>
                <div class="task-row <?php echo $isDone ? 'done' : ''; ?>">
                    <form method="post" class="m-0 flex-shrink-0">
                        <input type="hidden" name="action" value="toggle_done">
                        <input type="hidden" name="planner_id" value="<?php echo (int)$row['id']; ?>">
                        <input type="checkbox" class="form-check-input" name="done" value="1" <?php echo $isDone ? 'checked' : ''; ?> onchange="this.form.submit()" style="cursor:pointer;">
                    </form>
                    <div class="flex-grow-1 min-w-0">
                        <div class="mb-1">
                            <span class="task-subject"><?php echo htmlspecialchars($row['subject_name']); ?></span>
                        </div>
                        <div class="task-time">
                            <i class="bi bi-clock"></i>
                            <?php echo htmlspecialchars($formattedStart . ' - ' . $formattedEnd); ?>
                        </div>
                    </div>
                    <a class="task-delete flex-shrink-0"
                       href="planner.php?date=<?php echo urlencode($selectedDate); ?>&delete=<?php echo (int)$row['id']; ?>"
                       onclick="return confirm('Delete this task?')">
                        <i class="bi bi-trash3"></i>
                    </a>
                </div>
            <?php endforeach; ?>

            <?php if (!$dayRows): ?>
                <div class="empty-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                    <p>No tasks planned for this day yet.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Desktop View -->
        <div class="d-none d-md-block">
            <?php foreach ($dayRows as $row): 
                $isDone = ((int)$row['progress'] >= 100 || $row['status'] === 'Completed');
                $formattedStart = !empty($row['start_time']) ? date('h:i A', strtotime($row['start_time'])) : '';
                $formattedEnd   = !empty($row['end_time'])   ? date('h:i A', strtotime($row['end_time']))   : '';
            ?>
                <div class="task-row <?php echo $isDone ? 'done' : ''; ?>">
                    <form method="post" class="m-0 flex-shrink-0">
                        <input type="hidden" name="action" value="toggle_done">
                        <input type="hidden" name="planner_id" value="<?php echo (int)$row['id']; ?>">
                        <input type="checkbox" class="form-check-input" name="done" value="1" <?php echo $isDone ? 'checked' : ''; ?> onchange="this.form.submit()" style="cursor:pointer;">
                    </form>
                    <div class="task-time" style="min-width:150px;">
                        <i class="bi bi-clock"></i>
                        <?php echo htmlspecialchars($formattedStart . ' - ' . $formattedEnd); ?>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <span class="task-subject"><?php echo htmlspecialchars($row['subject_name']); ?></span>
                    </div>
                    <a class="task-delete flex-shrink-0"
                       href="planner.php?date=<?php echo urlencode($selectedDate); ?>&delete=<?php echo (int)$row['id']; ?>"
                       onclick="return confirm('Delete this task?')">
                        <i class="bi bi-trash3"></i> Delete
                    </a>
                </div>
            <?php endforeach; ?>

            <?php if (!$dayRows): ?>
                <div class="empty-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                    <p>No tasks planned for this day yet.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="checklist-footer">
            <div class="footer-stats">
                <span>Total tasks: <strong><?php echo count($dayRows); ?></strong></span>
                <span>Total hours: <strong><?php echo $hoursSpent; ?>h <?php echo $minutesSpent; ?>mn</strong></span>
                <span>Done: <strong class="text-success"><?php echo $hoursDone; ?>h <?php echo $minutesDone; ?>mn</strong></span>
                <span>Not done: <strong class="text-danger"><?php echo $hoursNotDone; ?>h <?php echo $minutesNotDone; ?>mn</strong></span>
            </div>
            <button type="button" class="btn btn-accent btn-sm d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#plannerReportModal">
                <i class="bi bi-bar-chart-line"></i>
                View Report Summary
            </button>
        </div>
    </div>

</div>

<!-- REPORT SUMMARY MODAL -->
<div class="modal fade" id="plannerReportModal" tabindex="-1" aria-labelledby="plannerReportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content overflow-hidden" style="border-radius:var(--radius);">
            <div class="modal-header" style="border-bottom:1px solid var(--border);padding:18px 24px;">
                <div>
                    <h5 class="modal-title fw-bold" style="font-size:1.05rem;color:var(--ink);" id="plannerReportModalLabel">
                        <i class="bi bi-bar-chart-line me-2" style="color:var(--accent);"></i>Planner Report Summary
                    </h5>
                    <p class="mb-0" style="font-size:.75rem;color:var(--ink-soft);">Breakdown of historical daily planned tasks</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body" style="padding:24px;">
                <!-- Stat Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-6">
                        <div class="report-stat-card bg-accent-light">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <div style="width:32px;height:32px;border-radius:8px;background:rgba(9,132,227,.12);display:flex;align-items:center;justify-content:center;color:var(--accent);"><i class="bi bi-list-task"></i></div>
                                <div class="stat-label" style="color:var(--accent);margin-bottom:0;">Total Planned Tasks</div>
                            </div>
                            <div class="stat-value" style="font-size:1.6rem;color:var(--accent);font-weight:800;"><?php echo $totalToday; ?></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="report-stat-card bg-success-light">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <div style="width:32px;height:32px;border-radius:8px;background:rgba(0,184,148,.12);display:flex;align-items:center;justify-content:center;color:var(--success);"><i class="bi bi-check-circle-fill"></i></div>
                                <div class="stat-label" style="color:var(--success);margin-bottom:0;">Completed Tasks</div>
                            </div>
                            <div class="stat-value" style="font-size:1.6rem;color:var(--success);font-weight:800;"><?php echo $doneToday; ?></div>
                        </div>
                    </div>
                    <?php if ($allHistoryTotal > 0): ?>
                    <div class="col-12">
                        <div class="report-stat-card" style="background:rgba(253,203,110,.06);border-color:rgba(253,203,110,.25);">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <div style="width:32px;height:32px;border-radius:8px;background:rgba(253,203,110,.15);display:flex;align-items:center;justify-content:center;color:#E17055;"><i class="bi bi-trophy-fill"></i></div>
                                <div class="stat-label" style="color:#E17055;margin-bottom:0;">All-Time Completion</div>
                            </div>
                            <div class="d-flex align-items-baseline gap-2">
                                <div class="stat-value" style="font-size:1.6rem;color:#E17055;font-weight:800;"><?php echo $allHistoryDone; ?> / <?php echo $allHistoryTotal; ?></div>
                                <div style="font-size:.78rem;color:var(--ink-soft);">tasks completed (<?php echo $allHistoryTotal > 0 ? round(($allHistoryDone / $allHistoryTotal) * 100) : 0; ?>%)</div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Detailed Daily Breakdown -->
                <div>
                    <div class="d-flex justify-content-between align-items-center mb-3 gap-2">
                        <h6 class="fw-bold mb-0" style="font-size:.85rem;">Detailed Daily Breakdown</h6>
                        <?php if (!empty($historyByDate)): ?>
                            <button type="button" onclick="downloadAllPlannerPdf()" class="btn btn-sm fw-semibold d-flex align-items-center gap-1" style="background:linear-gradient(135deg,#0984E3,#74B9FF);color:#fff;border-radius:8px;padding:5px 12px;font-size:.72rem;border:none;">
                                <i class="bi bi-download"></i>
                                Download All
                            </button>
                        <?php endif; ?>
                    </div>

                    <div style="max-height:320px;overflow-y:auto;">
                        <?php if (empty($historyByDate)): ?>
                            <p class="text-center py-4" style="font-size:.82rem;color:var(--ink-soft);">No historical records available.</p>
                        <?php else: ?>
                            <?php foreach ($historyByDate as $dateKey => $tasks): 
                                $subTotal = count($tasks);
                                $completedCount = 0;
                                foreach ($tasks as $t) {
                                    if ((int)$t['progress'] >= 100 || $t['status'] === 'Completed') $completedCount++;
                                }
                            ?>
                                <div class="report-day-card mb-3 planner-day-card" data-report-date="<?php echo htmlspecialchars($dateKey); ?>">
                                    <div class="day-header">
                                        <span class="fw-bold" style="font-size:.8rem;">
                                            <?php echo htmlspecialchars(date('l, F j, Y', strtotime($dateKey))); ?>
                                        </span>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge" style="background:rgba(9,132,227,.08);color:var(--accent);font-size:.7rem;padding:3px 10px;border-radius:6px;">
                                                Progress: <?php echo $completedCount; ?>/<?php echo $subTotal; ?> Done
                                            </span>
                                            <button type="button" onclick="downloadPlannerDayPdf('<?php echo htmlspecialchars($dateKey); ?>')" class="btn btn-sm fw-semibold" style="background:linear-gradient(135deg,#6C5CE7,#A29BFE);color:#fff;border-radius:8px;padding:3px 10px;font-size:.68rem;border:none;">
                                                <i class="bi bi-download me-1"></i>Download
                                            </button>
                                        </div>
                                    </div>
                                    <ul class="list-unstyled mb-0" style="font-size:.78rem;">
                                        <?php foreach ($tasks as $task): 
                                            $isTaskDone = ((int)$task['progress'] >= 100 || $task['status'] === 'Completed');
                                            $startTimeFormatted = !empty($task['start_time']) ? date('h:i A', strtotime($task['start_time'])) : '';
                                            $endTimeFormatted = !empty($task['end_time']) ? date('h:i A', strtotime($task['end_time'])) : '';
                                            $timeRange = trim($startTimeFormatted . ' - ' . $endTimeFormatted, ' -');
                                        ?>
                                            <li class="d-flex justify-content-between align-items-center py-2" style="border-bottom:1px solid var(--border);">
                                                <span style="color:var(--ink);">
                                                    <?php echo htmlspecialchars($task['subject_name']); ?>
                                                    <?php if ($timeRange): ?>
                                                        <span style="color:var(--ink-soft);font-weight:400;margin-left:4px;">(<?php echo htmlspecialchars($timeRange); ?>)</span>
                                                    <?php endif; ?>
                                                </span>
                                                <span class="fw-bold" style="color:<?php echo $isTaskDone ? 'var(--success)' : '#d97706'; ?>;">
                                                    <?php echo $isTaskDone ? 'Completed' : 'Pending'; ?>
                                                </span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="modal-footer" style="border-top:1px solid var(--border);padding:14px 24px;background:var(--paper);">
                <button type="button" class="btn btn-sm fw-semibold" style="border:1px solid var(--border);color:var(--ink-soft);background:var(--surface);border-radius:var(--radius-sm);" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg me-1"></i>Close Report
                </button>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const PLANNER_TOTAL_ALL = <?php echo (int) $allHistoryTotal; ?>;
    const PLANNER_DONE_ALL = <?php echo (int) $allHistoryDone; ?>;
    const PLANNER_REPORT_DATA = <?php echo json_encode($historyByDate, JSON_UNESCAPED_UNICODE); ?>;

    /* ---- Form Toggle ---- */
    document.getElementById('addFormToggle').addEventListener('click', function() {
        var body = document.getElementById('addFormBody');
        var chevron = document.getElementById('addFormChevron');
        var isOpen = body.classList.contains('show');
        body.classList.toggle('show', !isOpen);
        chevron.style.transform = isOpen ? '' : 'rotate(180deg)';
    });

    function toggleTimeInputs(id) {
        var checkbox = document.getElementById('sub-' + id);
        var card = document.getElementById('card-' + id);
        var inputs = document.querySelectorAll('#time-group-' + id + ' input');

        if (checkbox.checked) {
            card.classList.add('checked');
            inputs.forEach(function(input) { input.disabled = false; });
        } else {
            card.classList.remove('checked');
            inputs.forEach(function(input) {
                input.disabled = true;
                input.value = '';
            });
        }
    }

    document.querySelectorAll('.subject-checkbox').forEach(function(checkbox) {
        if (checkbox.checked) {
            toggleTimeInputs(checkbox.value);
        }
    });

    document.getElementById('selectAllSubjects')?.addEventListener('change', function() {
        var checkboxes = document.querySelectorAll('.subject-checkbox');
        checkboxes.forEach(function(cb) {
            cb.checked = this.checked;
            toggleTimeInputs(cb.value);
        }.bind(this));
    });

    /* ---- PDF Report Functions (data-driven, no CSS vars, self-contained styling) ---- */
    function plnEscapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function plnFmtDate(dateKey) {
        var d = new Date(dateKey + 'T00:00:00');
        if (isNaN(d)) return dateKey;
        return d.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    }
    function plnFmtTime(t) {
        if (!t) return '';
        var parts = t.split(':');
        var h = parseInt(parts[0], 10);
        var m = parts[1] || '00';
        var ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        return h + ':' + m + ' ' + ampm;
    }
    function plnIsDone(task) {
        return parseInt(task.progress) >= 100 || task.status === 'Completed';
    }
    function plnTaskHours(task) {
        if (!task.start_time || !task.end_time) return 0;
        var s = task.start_time.split(':').map(Number);
        var e = task.end_time.split(':').map(Number);
        var sec = (e[0]*3600 + e[1]*60) - (s[0]*3600 + s[1]*60);
        return sec > 0 ? sec / 3600 : 0;
    }

    function plnBuildTaskRow(task) {
        var done = plnIsDone(task);
        var timeRange = '';
        if (task.start_time || task.end_time) {
            timeRange = plnFmtTime(task.start_time) + ' - ' + plnFmtTime(task.end_time);
        }
        var hrs = plnTaskHours(task);
        var statusColor = done ? '#059669' : '#d97706';
        var statusBg = done ? '#ecfdf5' : '#fffbeb';
        var statusLabel = done ? 'Completed' : 'Pending';
        return '<div class="task-row">' +
            '<div class="task-left">' +
                '<span class="task-subject">' + plnEscapeHtml(task.subject_name) + '</span>' +
                (task.topic ? '<span class="task-topic">' + plnEscapeHtml(task.topic) + '</span>' : '') +
                (timeRange ? '<span class="task-time">' + plnEscapeHtml(timeRange) + '</span>' : '') +
            '</div>' +
            '<div class="task-right">' +
                '<span class="task-hrs">' + (hrs > 0 ? hrs.toFixed(1) + 'h' : '') + '</span>' +
                '<span class="task-status" style="background:' + statusBg + ';color:' + statusColor + ';">' + statusLabel + '</span>' +
            '</div>' +
        '</div>';
    }

    function plnBuildDayBlock(dateKey, tasks) {
        var done = 0, totalHrs = 0;
        tasks.forEach(function(t) {
            if (plnIsDone(t)) done++;
            totalHrs += plnTaskHours(t);
        });
        var pct = tasks.length ? Math.round(done / tasks.length * 100) : 0;
        var taskRows = tasks.map(plnBuildTaskRow).join('');
        return '<div class="day-card">' +
            '<div class="day-card-header">' +
                '<div class="day-card-left">' +
                    '<span class="day-card-date">' + plnFmtDate(dateKey) + '</span>' +
                    '<span class="day-card-stats">' +
                        '<span class="stat-badge">' + done + '/' + tasks.length + ' done</span>' +
                        (totalHrs > 0 ? '<span class="stat-badge stat-hrs">' + totalHrs.toFixed(1) + 'h</span>' : '') +
                    '</span>' +
                '</div>' +
                '<div class="day-card-pct" style="color:' + (pct >= 80 ? '#059669' : pct >= 40 ? '#d97706' : '#dc2626') + ';">' + pct + '%</div>' +
            '</div>' +
            '<div class="day-card-body">' + taskRows + '</div>' +
        '</div>';
    }

    function plnBuildSummaryStrip(allTasks) {
        var totalDone = 0, totalAll = 0, totalHrs = 0;
        Object.keys(PLANNER_REPORT_DATA).forEach(function(dk) {
            PLANNER_REPORT_DATA[dk].forEach(function(t) {
                totalAll++;
                if (plnIsDone(t)) totalDone++;
                totalHrs += plnTaskHours(t);
            });
        });
        var pct = totalAll ? Math.round(totalDone / totalAll * 100) : 0;
        return '<div class="summary-strip">' +
            '<div class="summary-card summary-done">' +
                '<span class="summary-label">Completed Tasks</span>' +
                '<div class="summary-value" style="color:#059669;">' + totalDone + '</div>' +
            '</div>' +
            '<div class="summary-card summary-total">' +
                '<span class="summary-label">Total Tasks</span>' +
                '<div class="summary-value" style="color:#2563eb;">' + totalAll + '</div>' +
            '</div>' +
            '<div class="summary-card summary-hrs">' +
                '<span class="summary-label">Total Hours</span>' +
                '<div class="summary-value" style="color:#7c3aed;">' + totalHrs.toFixed(1) + 'h</div>' +
            '</div>' +
            '<div class="summary-card summary-pct">' +
                '<span class="summary-label">Completion Rate</span>' +
                '<div class="summary-value" style="color:' + (pct >= 80 ? '#059669' : pct >= 40 ? '#d97706' : '#dc2626') + ';">' + pct + '%</div>' +
            '</div>' +
        '</div>';
    }

    function plnBuildReportHtml(title, subtitle, daysHtml, summaryHtml) {
        var generated = new Date().toLocaleString('en-US', { dateStyle: 'medium', timeStyle: 'short' });
        return '<html><head><meta charset="UTF-8"><title>' + plnEscapeHtml(title) + '</title>' +
        '<style>' +
            '* { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }' +
            'body { font-family: "Inter", Arial, sans-serif; margin: 0; padding: 0; background: #f5f7fa; color: #1a1d2e; }' +
            '.page { max-width: 820px; margin: 0 auto; padding: 32px 28px 60px; }' +
            '.toolbar { display: flex; justify-content: flex-end; gap: 8px; padding: 16px 28px 0; max-width: 820px; margin: 0 auto; }' +
            '.print-btn { border: none; background: #2563eb; color: #fff; font-size: 14px; font-weight: 600; padding: 9px 18px; border-radius: 8px; cursor: pointer; }' +
            '.print-btn:hover { background: #1d4ed8; }' +
            '.close-btn { width: 36px; height: 36px; border-radius: 999px; border: 1px solid #e5e7eb; background: #fff; color: #374151; font-size: 18px; font-weight: bold; line-height: 1; cursor: pointer; }' +
            '.close-btn:hover { background: #f3f4f6; }' +
            '.report-header { margin-bottom: 24px; padding-bottom: 20px; border-bottom: 2px solid #e8ebf2; }' +
            '.report-title { font-size: 24px; font-weight: 800; margin: 0 0 4px; }' +
            '.report-subtitle { font-size: 13px; color: #6b7190; margin: 0 0 2px; }' +
            '.report-generated { font-size: 11px; color: #9ca3af; }' +
            '.summary-strip { display: flex; gap: 12px; margin-bottom: 28px; }' +
            '.summary-card { flex: 1; background: #fff; border: 1px solid #e8ebf2; border-radius: 12px; padding: 14px 16px; border-top: 3px solid #d1d5db; }' +
            '.summary-done { border-top-color: #10b981; }' +
            '.summary-total { border-top-color: #3b82f6; }' +
            '.summary-hrs { border-top-color: #8b5cf6; }' +
            '.summary-pct { border-top-color: #f59e0b; }' +
            '.summary-label { font-size: 10px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: #6b7190; }' +
            '.summary-value { font-size: 19px; font-weight: 800; margin-top: 4px; }' +
            '.section-label { font-size: 12px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #6b7190; margin: 0 0 14px; }' +
            '.day-card { background: #fff; border: 1px solid #e8ebf2; border-radius: 12px; margin-bottom: 14px; overflow: hidden; page-break-inside: avoid; }' +
            '.day-card-header { display: flex; justify-content: space-between; align-items: center; padding: 13px 18px; background: #f9fafb; border-bottom: 1px solid #e8ebf2; }' +
            '.day-card-left { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }' +
            '.day-card-date { font-size: 13.5px; font-weight: 700; color: #1a1d2e; }' +
            '.day-card-stats { display: flex; gap: 6px; }' +
            '.stat-badge { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: #eff6ff; color: #2563eb; }' +
            '.stat-hrs { background: #f3e8ff; color: #7c3aed; }' +
            '.day-card-pct { font-size: 15px; font-weight: 800; }' +
            '.day-card-body { padding: 4px 18px; }' +
            '.task-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 11px 0; border-bottom: 1px solid #f1f3f6; }' +
            '.task-row:last-child { border-bottom: none; }' +
            '.task-left { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; min-width: 0; }' +
            '.task-subject { font-size: 13px; font-weight: 600; color: #1a1d2e; }' +
            '.task-topic { font-size: 11px; color: #6b7190; background: #f3f4f6; padding: 1px 7px; border-radius: 999px; }' +
            '.task-time { font-size: 11px; color: #9ca3af; }' +
            '.task-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }' +
            '.task-hrs { font-size: 12px; font-weight: 600; color: #7c3aed; }' +
            '.task-status { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 999px; white-space: nowrap; }' +
            '.empty-note { text-align: center; color: #9ca3af; font-size: 13px; padding: 40px 0; }' +
            '@media print { body { background: #fff; } .toolbar { display: none; } .page { padding: 0; max-width: none; } .day-card { box-shadow: none; } }' +
        '</style></head><body>' +
        '<div class="toolbar">' +
            '<button class="print-btn" onclick="window.print()">Print / Save PDF</button>' +
            '<button class="close-btn" onclick="window.close()" aria-label="Close">&times;</button>' +
        '</div>' +
        '<div class="page">' +
            '<div class="report-header">' +
                '<h1 class="report-title">' + plnEscapeHtml(title) + '</h1>' +
                '<p class="report-subtitle">' + plnEscapeHtml(subtitle) + '</p>' +
                '<p class="report-generated">Generated ' + plnEscapeHtml(generated) + '</p>' +
            '</div>' +
            summaryHtml +
            '<p class="section-label">Daily Breakdown</p>' +
            (daysHtml || '<div class="empty-note">No entries to show.</div>') +
        '</div></body></html>';
    }

    function downloadPlannerDayPdf(dateKey) {
        var tasks = PLANNER_REPORT_DATA[dateKey];
        if (!tasks || !tasks.length) return;
        var daysHtml = plnBuildDayBlock(dateKey, tasks);
        var sumDone = 0, sumHrs = 0;
        tasks.forEach(function(t) { if (plnIsDone(t)) sumDone++; sumHrs += plnTaskHours(t); });
        var pct = tasks.length ? Math.round(sumDone / tasks.length * 100) : 0;
        var summaryHtml = '<div class="summary-strip">' +
            '<div class="summary-card summary-done"><span class="summary-label">Completed</span><div class="summary-value" style="color:#059669;">' + sumDone + '</div></div>' +
            '<div class="summary-card summary-total"><span class="summary-label">Total Tasks</span><div class="summary-value" style="color:#2563eb;">' + tasks.length + '</div></div>' +
            '<div class="summary-card summary-hrs"><span class="summary-label">Hours Logged</span><div class="summary-value" style="color:#7c3aed;">' + sumHrs.toFixed(1) + 'h</div></div>' +
            '<div class="summary-card summary-pct"><span class="summary-label">Completion</span><div class="summary-value" style="color:' + (pct >= 80 ? '#059669' : pct >= 40 ? '#d97706' : '#dc2626') + ';">' + pct + '%</div></div>' +
        '</div>';
        var popup = window.open('', '_blank', 'width=900,height=700');
        popup.document.write(plnBuildReportHtml('Planner Report — ' + plnFmtDate(dateKey), dateKey, daysHtml, summaryHtml));
        popup.document.close();
    }

    function downloadAllPlannerPdf() {
        var dateKeys = Object.keys(PLANNER_REPORT_DATA);
        if (!dateKeys.length) return;
        var daysHtml = '';
        dateKeys.forEach(function(dk) { daysHtml += plnBuildDayBlock(dk, PLANNER_REPORT_DATA[dk]); });
        var summaryHtml = plnBuildSummaryStrip();
        var popup = window.open('', '_blank', 'width=900,height=700');
        popup.document.write(plnBuildReportHtml('Full Planner Report (All Days)', dateKeys.length + ' day(s) with activity', daysHtml, summaryHtml));
        popup.document.close();
    }
</script>
</body>
</html>
