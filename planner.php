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

    // Fetch History Data
    $historyStmt = $conn->prepare("
        SELECT p.id, p.study_date, p.day_name, p.start_time, p.end_time, p.progress, p.status, s.name AS subject_name
        FROM planner p
        INNER JOIN subjects s ON s.id = p.subject_id
        WHERE p.user_id = ? AND p.study_date <= ?
        ORDER BY p.study_date DESC, p.start_time ASC
    ");
    $historyStmt->bind_param('is', $userId, $selectedDate);
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
    ?>
    <!DOCTYPE html>
    <html lang="<?php echo htmlspecialchars($currentLang); ?>">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Daily Planner & History</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <script src="https://cdn.tailwindcss.com"></script>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Noto+Sans+Khmer:wght@400;500;600;700;800&display=swap');
            body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
            html[lang="kh"] body { font-family: 'Noto Sans Khmer', 'Inter', sans-serif; }
        </style>
    </head>
    <body class="text-gray-800 antialiased min-h-screen">

        <?php $activePage = 'planner'; include __DIR__ . '/includes/navbar.php'; ?>

        <div class="max-w-6xl mx-auto px-4 py-8 space-y-8">

            <!-- Top Title & Filter Bar -->
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Daily Planner</h1>
                    <p class="text-sm text-gray-500 mt-1">Organize and manage your daily activities easily.</p>
                </div>
                <form method="get" class="flex items-center gap-2 w-full sm:w-auto">
                    <input type="date" class="border border-gray-300 rounded-xl px-4 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none w-full sm:w-auto" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-medium px-5 py-2 rounded-xl text-sm transition">View</button>
                </form>
            </div>

            <!-- Alert Notifications -->
            <?php if ($errors): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl text-sm">
                    <?php echo htmlspecialchars(implode(' ', $errors)); ?>
                </div>
            <?php endif; ?>

            <!-- Daily Metric Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-sm">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Date Summary</p>
                    <div class="text-3xl font-extrabold text-blue-600 mt-2"><?php echo $dayPercent; ?>%</div>
                    <div class="w-full bg-gray-100 h-2 rounded-full mt-3 overflow-hidden">
                        <div class="bg-blue-600 h-full rounded-full transition-all duration-300" style="width: <?php echo $dayPercent; ?>%;"></div>
                    </div>
                    <p class="text-xs text-gray-500 mt-2"><?php echo $doneToday; ?> / <?php echo $totalToday; ?> tasks done</p>
                </div>

                <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-sm">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Tasks Completed</p>
                    <div class="text-3xl font-extrabold text-emerald-600 mt-2"><?php echo $doneToday; ?></div>
                    <p class="text-xs text-gray-500 mt-4">Marked completed with ☑</p>
                </div>

                <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-sm">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Total Tasks</p>
                    <div class="text-3xl font-extrabold text-gray-800 mt-2"><?php echo $totalToday; ?></div>
                    <p class="text-xs text-gray-500 mt-4">Tasks planned for today</p>
                </div>
            </div>

            <!-- Section: Add Daily Tasks -->
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <div class="flex justify-between items-center mb-6 pb-4 border-b border-gray-100">
                    <h2 class="text-lg font-bold text-gray-900">Add Daily Tasks</h2>
                    <span class="bg-blue-50 text-blue-700 text-xs font-semibold px-3 py-1.5 rounded-full">
                        <?php echo htmlspecialchars(date('l, F j, Y', strtotime($selectedDate))); ?>
                    </span>
                </div>

                <div class="mb-6 rounded-2xl border border-dashed border-blue-200 bg-blue-50/40 p-4">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-blue-900">Follow previous day</p>
                            <p class="text-xs text-blue-700 mt-1">Copy subjects and times from yesterday or an older plan, then adjust if needed.</p>
                        </div>
                        <form method="get" class="flex flex-col sm:flex-row gap-2 items-stretch sm:items-center">
                            <input type="hidden" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>">
                            <select name="copy_from" class="border border-blue-200 rounded-xl px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none bg-white">
                                <option value="">Choose a previous day</option>
                                <?php foreach ($recentPlanDates as $dateKey): ?>
                                    <option value="<?php echo htmlspecialchars($dateKey); ?>" <?php echo $copyFromDate === $dateKey ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(date('D, M j', strtotime($dateKey))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-medium px-4 py-2 rounded-xl text-sm transition">
                                Load plan
                            </button>
                        </form>
                    </div>
                    <?php if ($copyFromDate && $copyTemplateRows): ?>
                        <div class="mt-3 text-xs text-blue-800">
                            Loaded from <strong><?php echo htmlspecialchars(date('l, F j, Y', strtotime($copyFromDate))); ?></strong>.
                        </div>
                    <?php elseif ($copyFromDate): ?>
                        <div class="mt-3 text-xs text-amber-700">
                            No saved plan found for <strong><?php echo htmlspecialchars(date('l, F j, Y', strtotime($copyFromDate))); ?></strong>.
                        </div>
                    <?php endif; ?>
                </div>

                <form method="post" class="space-y-6">
                    <input type="hidden" name="action" value="add_planner">
                    <input type="hidden" name="study_date" value="<?php echo htmlspecialchars($selectedDate); ?>">

                    <div>
                        <div class="flex justify-between items-center mb-3">
                            <label class="text-sm font-semibold text-gray-700">Select Subjects & Set Time</label>
                            <label class="inline-flex items-center space-x-2 text-sm text-blue-600 cursor-pointer font-medium hover:underline">
                                <input type="checkbox" id="selectAllSubjects" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                <span>Select All</span>
                            </label>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <?php foreach ($subjects as $subject): 
                                $sId = (int)$subject['id'];
                                $template = $copyTemplateMap[$sId] ?? null;
                                $isChecked = $template !== null;
                                $startValue = $template['start_time'] ?? '';
                                $endValue = $template['end_time'] ?? '';
                            ?>
                                <div class="border border-gray-200 rounded-xl p-3 bg-white transition hover:border-gray-300" id="card-<?php echo $sId; ?>">
                                    <label class="inline-flex items-center space-x-2 cursor-pointer w-full mb-2">
                                        <input type="checkbox" class="subject-checkbox rounded border-gray-300 text-blue-600 focus:ring-blue-500" name="subject_ids[]" value="<?php echo $sId; ?>" id="sub-<?php echo $sId; ?>" <?php echo $isChecked ? 'checked' : ''; ?> onchange="toggleTimeInputs(<?php echo $sId; ?>)">
                                        <span class="text-sm font-medium text-gray-800 truncate"><?php echo htmlspecialchars($subject['name']); ?></span>
                                    </label>
                                    
                                    <div class="flex items-center space-x-2" id="time-group-<?php echo $sId; ?>">
                                        <input type="time" class="w-full text-xs border border-gray-200 rounded-lg p-1.5 focus:ring-2 focus:ring-blue-500 focus:outline-none disabled:bg-gray-50 disabled:text-gray-300" name="start_time[<?php echo $sId; ?>]" <?php echo $isChecked ? '' : 'disabled'; ?> value="<?php echo htmlspecialchars($startValue); ?>" required>
                                        <span class="text-xs text-gray-400">to</span>
                                        <input type="time" class="w-full text-xs border border-gray-200 rounded-lg p-1.5 focus:ring-2 focus:ring-blue-500 focus:outline-none disabled:bg-gray-50 disabled:text-gray-300" name="end_time[<?php echo $sId; ?>]" <?php echo $isChecked ? '' : 'disabled'; ?> value="<?php echo htmlspecialchars($endValue); ?>" required>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="max-w-xs">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Day</label>
                        <select class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none" name="day_name" required>
                            <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $day): ?>
                                <option value="<?php echo $day; ?>" <?php echo (date('l', strtotime($selectedDate)) === $day) ? 'selected' : ''; ?>><?php echo $day; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-xl shadow-sm text-sm transition">
                        Save All Planned Tasks
                    </button>
                </form>
            </div>

            <!-- Section: Today's Checklist Table -->
    <!-- Section: Today's Checklist Card -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center p-4 sm:p-6 border-b border-gray-100 gap-2 sm:gap-0">
            <div>
                <h3 class="text-base sm:text-lg font-bold text-gray-900">Today's Checklist</h3>
                <p class="text-xs text-gray-400">Manage your schedule and track progress</p>
            </div>
            <div class="flex items-center">
                <span class="text-xs font-semibold px-3 py-1 bg-gray-100 text-gray-600 rounded-full">
                    <?php echo htmlspecialchars($selectedDate); ?>
                </span>
            </div>
        </div>

        <!-- Mobile View: Cards (Visible below md breakpoint) -->
        <div class="block md:hidden divide-y divide-gray-100">
            <?php foreach ($dayRows as $row): 
                $isDone = ((int)$row['progress'] >= 100 || $row['status'] === 'Completed');
                $formattedStart = !empty($row['start_time']) ? date('h:i A', strtotime($row['start_time'])) : '';
                $formattedEnd   = !empty($row['end_time'])   ? date('h:i A', strtotime($row['end_time']))   : '';
            ?>
                <div class="p-4 transition-colors duration-150 <?php echo $isDone ? 'bg-emerald-50/30' : 'active:bg-gray-50'; ?>">
                    <div class="flex items-start justify-between gap-3">
                        
                        <!-- Checkbox & Activity Info -->
                        <div class="flex items-start gap-3 min-w-0 flex-1">
                            <form method="post" class="m-0 pt-0.5 flex-shrink-0">
                                <input type="hidden" name="action" value="toggle_done">
                                <input type="hidden" name="planner_id" value="<?php echo (int)$row['id']; ?>">
                                <input type="checkbox" 
                                    class="w-5 h-5 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500/20 focus:ring-offset-0 cursor-pointer transition" 
                                    name="done" 
                                    value="1" 
                                    <?php echo $isDone ? 'checked' : ''; ?> 
                                    onchange="this.form.submit()">
                            </form>

                            <div class="min-w-0 flex-1 space-y-1.5">
                                <div>
                                    <span class="inline-block font-semibold text-xs px-2.5 py-1 rounded-lg border max-w-full truncate <?php echo $isDone ? 'bg-gray-100 text-gray-400 border-gray-200 line-through' : 'bg-blue-50 text-blue-700 border-blue-100'; ?>">
                                        <?php echo htmlspecialchars($row['subject_name']); ?>
                                    </span>
                                </div>
                                
                                <!-- Time Badge -->
                                <div class="flex items-center space-x-1 text-xs font-medium text-gray-500">
                                    <svg class="w-3.5 h-3.5 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <span><?php echo htmlspecialchars($formattedStart . ' - ' . $formattedEnd); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Delete Action -->
                        <div class="flex-shrink-0">
                            <a class="inline-flex items-center text-xs font-medium text-red-500 hover:text-red-700 p-1.5 rounded-lg hover:bg-red-50 transition" 
                            href="planner.php?date=<?php echo urlencode($selectedDate); ?>&delete=<?php echo (int)$row['id']; ?>" 
                            onclick="return confirm('Delete this task?')">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (!$dayRows): ?>
                <div class="text-center py-8 px-4">
                    <div class="flex flex-col items-center justify-center text-gray-400 space-y-2">
                        <svg class="w-8 h-8 stroke-current" fill="none" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                        </svg>
                        <span class="text-xs font-medium">No tasks planned for this day yet.</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Desktop View: Table (Visible on md screens and above) -->
        <div class="hidden md:block overflow-x-auto">
            <table class="w-full text-left text-sm border-collapse">
                <thead>
                    <tr class="bg-gray-50/70 text-gray-400 font-semibold text-xs uppercase tracking-wider border-b border-gray-100">
                        <th class="py-3 px-4 w-12 text-center">Done</th>
                        <th class="py-3 px-4 w-52">Time</th>
                        <th class="py-3 px-4">Subject / Activity</th>
                        <th class="py-3 px-4 text-right w-28">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($dayRows as $row): 
                        $isDone = ((int)$row['progress'] >= 100 || $row['status'] === 'Completed');
                        $formattedStart = !empty($row['start_time']) ? date('h:i A', strtotime($row['start_time'])) : '';
                        $formattedEnd   = !empty($row['end_time'])   ? date('h:i A', strtotime($row['end_time']))   : '';
                    ?>
                        <tr class="group transition-colors duration-150 <?php echo $isDone ? 'bg-emerald-50/30 hover:bg-emerald-50/50' : 'hover:bg-gray-50/80'; ?>">
                            
                            <!-- Status Checkbox -->
                            <td class="py-3.5 px-4 text-center align-middle">
                                <form method="post" class="m-0 flex items-center justify-center">
                                    <input type="hidden" name="action" value="toggle_done">
                                    <input type="hidden" name="planner_id" value="<?php echo (int)$row['id']; ?>">
                                    <input type="checkbox" 
                                        class="w-4 h-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500/20 focus:ring-offset-0 cursor-pointer transition" 
                                        name="done" 
                                        value="1" 
                                        <?php echo $isDone ? 'checked' : ''; ?> 
                                        onchange="this.form.submit()">
                                </form>
                            </td>

                            <!-- Time Badge -->
                            <td class="py-3.5 px-4 align-middle whitespace-nowrap">
                                <span class="inline-flex items-center space-x-1.5 text-xs font-medium text-gray-600 bg-gray-100/80 px-2.5 py-1 rounded-md">
                                    <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <span><?php echo htmlspecialchars($formattedStart . ' - ' . $formattedEnd); ?></span>
                                </span>
                            </td>

                            <!-- Subject / Activity -->
                            <td class="py-3.5 px-4 align-middle">
                                <span class="inline-block font-semibold text-xs px-3 py-1 rounded-lg border transition <?php echo $isDone ? 'bg-gray-100 text-gray-400 border-gray-200 line-through' : 'bg-blue-50 text-blue-700 border-blue-100'; ?>">
                                    <?php echo htmlspecialchars($row['subject_name']); ?>
                                </span>
                            </td>

                            <!-- Actions -->
                            <td class="py-3.5 px-4 text-right align-middle">
                                <div class="inline-flex items-center space-x-2">
                                    <a class="inline-flex items-center text-xs font-medium text-red-500 hover:text-red-700 hover:bg-red-50 px-2 py-1 rounded-md transition" 
                                    href="planner.php?date=<?php echo urlencode($selectedDate); ?>&delete=<?php echo (int)$row['id']; ?>" 
                                    onclick="return confirm('Delete this task?')">
                                        <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                        </svg>
                                        Delete
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <!-- Empty State Desktop -->
                    <?php if (!$dayRows): ?>
                        <tr>
                            <td colspan="4" class="text-center py-10">
                                <div class="flex flex-col items-center justify-center text-gray-400 space-y-2">
                                    <svg class="w-8 h-8 stroke-current" fill="none" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                    </svg>
                                    <span class="text-sm font-medium">No tasks planned for this day yet.</span>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

      <!-- Footer Action -->
        <div class="p-4 bg-gray-50/50 border-t border-gray-100 flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 sm:gap-0">
            <span class="text-xs text-gray-400 text-center sm:text-left">
                Total tasks: <strong class="text-gray-700"><?php echo count($dayRows); ?></strong>
                <span class="mx-2 text-gray-300">|</span>
                Total hours: <strong class="text-gray-700"><?php echo $hoursSpent; ?>h <?php echo $minutesSpent; ?>mn</strong>
                <span class="mx-2 text-gray-300">|</span>
                Done: <strong class="text-emerald-600"><?php echo $hoursDone; ?>h <?php echo $minutesDone; ?>mn</strong>
                <span class="mx-2 text-gray-300">/</span>
                Not done: <strong class="text-red-600"><?php echo $hoursNotDone; ?>h <?php echo $minutesNotDone; ?>mn</strong>
            </span>
            <button type="button" class="w-full sm:w-auto bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white font-medium px-4 py-2.5 rounded-xl shadow-sm text-sm transition flex items-center justify-center space-x-2" data-bs-toggle="modal" data-bs-target="#plannerReportModal">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <span>View Report Summary</span>
            </button>
        </div>
    </div>

        </div>

        <!-- REPORT SUMMARY MODAL -->
        <div class="modal fade" id="plannerReportModal" tabindex="-1" aria-labelledby="plannerReportModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content rounded-3xl border-0 shadow-2xl overflow-hidden p-3">
                    <div class="modal-header border-0 pb-0">
                        <div>
                            <h5 class="modal-title text-xl font-bold text-gray-900" id="plannerReportModalLabel">Planner Report Summary</h5>
                            <p class="text-xs text-gray-500 mt-0.5">Breakdown of historical daily planned tasks</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body space-y-6 pt-4">
                        <!-- Stat Cards Header -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="bg-blue-50/50 p-4 rounded-2xl border border-blue-100">
                                <p class="text-xs font-bold text-blue-600 uppercase tracking-wider">Total Planned Tasks</p>
                                <p class="text-2xl font-extrabold text-blue-700 mt-1"><?php echo $totalToday; ?></p>
                            </div>
                            <div class="bg-emerald-50/50 p-4 rounded-2xl border border-emerald-100">
                                <p class="text-xs font-bold text-emerald-600 uppercase tracking-wider">Completed Tasks</p>
                                <p class="text-2xl font-extrabold text-emerald-700 mt-1"><?php echo $doneToday; ?></p>
                            </div>
                        </div>

                        <!-- Detailed Daily Breakdown -->
                        <div>
                            <h6 class="text-sm font-bold text-gray-800 mb-3">Detailed Daily Breakdown</h6>
                            
                            <div class="space-y-4 max-h-80 overflow-y-auto pr-1">
                                <?php if (empty($historyByDate)): ?>
                                    <p class="text-xs text-gray-400 text-center py-4">No historical records available.</p>
                                <?php else: ?>
                                    <?php foreach ($historyByDate as $dateKey => $tasks): 
                                        $subTotal = count($tasks);
                                        $completedCount = 0;
                                        foreach ($tasks as $t) {
                                            if ((int)$t['progress'] >= 100 || $t['status'] === 'Completed') $completedCount++;
                                        }
                                    ?>
                                        <div class="border border-gray-100 rounded-2xl p-4 bg-gray-50/30 planner-day-card" data-report-date="<?php echo htmlspecialchars($dateKey); ?>">
                                            <div class="flex justify-between items-center pb-2 border-b border-gray-100 mb-2 gap-2">
                                                <span class="text-xs font-bold text-gray-700">
                                                    <?php echo htmlspecialchars(date('l, F j, Y', strtotime($dateKey))); ?>
                                                </span>
                                                <div class="flex items-center gap-2">
                                                    <span class="text-xs font-semibold text-blue-600 bg-blue-50 px-2 py-0.5 rounded-md">
                                                        Progress: <?php echo $completedCount; ?>/<?php echo $subTotal; ?> Done
                                                    </span>
                                                    <button type="button" onclick="downloadPlannerDayPdf('<?php echo htmlspecialchars($dateKey); ?>')" class="bg-blue-600 hover:bg-blue-700 text-white text-[11px] font-semibold px-3 py-1.5 rounded-lg">
                                                        Download
                                                    </button>
                                                </div>
                                            </div>
                                            <ul class="divide-y divide-gray-100 text-xs">
                                                <?php foreach ($tasks as $task): 
                                                    $isTaskDone = ((int)$task['progress'] >= 100 || $task['status'] === 'Completed');
                                                ?>
                                                    <li class="py-2 flex justify-between items-center">
                                                        <span class="text-gray-700 font-medium">
                                                            <?php echo htmlspecialchars($task['subject_name']); ?>
                                                            <span class="text-gray-400 font-normal ml-1">(<?php echo htmlspecialchars($task['start_time'] . ' - ' . $task['end_time']); ?>)</span>
                                                        </span>
                                                        <span class="font-bold <?php echo $isTaskDone ? 'text-emerald-600' : 'text-amber-600'; ?>">
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

                    <div class="modal-footer border-0 pt-0">
                        <button type="button" class="btn bg-gray-200 text-gray-700 border-0 rounded-xl px-4 py-2 text-xs font-semibold hover:bg-gray-300" data-bs-dismiss="modal">Close Report</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- JavaScript Handlers -->
        <script>
            function toggleTimeInputs(id) {
                const checkbox = document.getElementById('sub-' + id);
                const card = document.getElementById('card-' + id);
                const inputs = document.querySelectorAll('#time-group-' + id + ' input');

                if (checkbox.checked) {
                    card.classList.add('border-blue-500', 'bg-blue-50/20');
                    inputs.forEach(input => input.disabled = false);
                } else {
                    card.classList.remove('border-blue-500', 'bg-blue-50/20');
                    inputs.forEach(input => {
                        input.disabled = true;
                        input.value = '';
                    });
                }
            }

            document.querySelectorAll('.subject-checkbox').forEach((checkbox) => {
                if (checkbox.checked) {
                    toggleTimeInputs(checkbox.value);
                }
            });

            document.getElementById('selectAllSubjects')?.addEventListener('change', function() {
                const checkboxes = document.querySelectorAll('.subject-checkbox');
                checkboxes.forEach(cb => {
                    cb.checked = this.checked;
                    toggleTimeInputs(cb.value);
                });
            });

          function downloadPlannerDayPdf(dateKey) {
    const source = document.querySelector('[data-report-date="' + dateKey + '"]');
    if (!source) return;

    const clone = source.cloneNode(true);
    clone.querySelectorAll('button').forEach((btn) => btn.remove());

    const popup = window.open('', '_blank', 'width=900,height=700');
    popup.document.write(`
        <html>
            <head>
                <title>Planner Report ${dateKey}</title>
                <style>
                    * { box-sizing: border-box; }
                    body {
                        font-family: Arial, sans-serif;
                        margin: 0;
                        padding: 24px;
                        color: #1f2937;
                        font-size: 16px;
                    }
                    .toolbar {
                        display: flex;
                        justify-content: flex-end;
                        margin-bottom: 16px;
                    }
                    .close-btn {
                        width: 34px;
                        height: 34px;
                        border-radius: 999px;
                        border: 1px solid #e5e7eb;
                        background: #f3f4f6;
                        color: #374151;
                        font-size: 18px;
                        font-weight: bold;
                        line-height: 1;
                        cursor: pointer;
                    }
                    .close-btn:hover { background: #e5e7eb; }
                    .print-btn {
                        border: none;
                        background: #2563eb;
                        color: #fff;
                        font-size: 14px;
                        font-weight: 600;
                        padding: 8px 16px;
                        border-radius: 8px;
                        cursor: pointer;
                        margin-right: 8px;
                    }
                    .print-btn:hover { background: #1d4ed8; }
                    .border { border: 1px solid #e5e7eb; }
                    .rounded-2xl { border-radius: 16px; }
                    .p-4 { padding: 20px; }
                    .space-y-4 > * + * { margin-top: 16px; }
                    .text-xs { font-size: 16px; }
                    .font-bold { font-weight: 700; }
                    .font-semibold { font-weight: 600; }
                    .text-gray-700 { color: #374151; }
                    .text-gray-800 { color: #1f2937; }
                    .text-amber-600 { color: #d97706; }
                    .text-emerald-600 { color: #059669; }
                    .text-blue-600 { color: #2563eb; }
                    .bg-gray-50 { background: #f9fafb; }
                    .bg-blue-50 { background: #eff6ff; }
                    .border-b { border-bottom: 1px solid #e5e7eb; }
                    .pb-2 { padding-bottom: 12px; }
                    .mb-2 { margin-bottom: 12px; }
                    .flex { display: flex; }
                    .justify-between { justify-content: space-between; }
                    .items-center { align-items: center; }
                    .gap-2 { gap: 8px; }
                    ul { list-style: none; padding: 0; margin: 0; }
                    li { padding: 10px 0; font-size: 16px; }
                    @media print {
                        .toolbar { display: none; }
                    }
                </style>
            </head>
            <body>
                <div class="toolbar">
                    <button class="print-btn" onclick="window.print()">Print / Save PDF</button>
                    <button class="close-btn" onclick="window.close()" aria-label="Close">&times;</button>
                </div>
                ${clone.outerHTML}
            </body>
        </html>
    `);
    popup.document.close();
}

        </script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
