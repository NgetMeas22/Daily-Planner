<?php
/**
 * Lightweight JSON endpoint that powers the Dashboard charts.
 *
 * The dashboard HTML shell loads first (fast), then the browser fetches this
 * endpoint asynchronously via fetch() so the heavy aggregate queries below do
 * not block the initial page render. Session auth is required.
 *
 * Query params:
 *   ?range=day|week|month   which study-hours window to aggregate (default week)
 *
 * Response shape:
 *   {
 *     "study":   { "label", "summary", "labels": [], "values": [], "totalHours" },
 *     "subject": { "labels": [], "values": [] }
 *   }
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();
$userId = (int) $_SESSION['user_id'];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

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
    $stmt = $conn->prepare("
        SELECT start_time, end_time, GREATEST(TIMESTAMPDIFF(MINUTE, start_time, end_time), 0) / 60 AS hours
        FROM planner
        WHERE user_id = ? AND study_date = ?
        AND end_time > start_time
        ORDER BY start_time ASC
    ");
    $stmt->bind_param('is', $userId, $today);
    $stmt->execute();
    $dayRaw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

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
    $stmt = $conn->prepare("
        SELECT study_date, SUM(GREATEST(TIMESTAMPDIFF(MINUTE, start_time, end_time), 0)) / 60 AS hours
        FROM planner
        WHERE user_id = ? AND study_date BETWEEN ? AND ?
        AND end_time > start_time
        GROUP BY study_date
        ORDER BY study_date ASC
    ");
    $stmt->bind_param('iss', $userId, $monthStart, $monthEnd);
    $stmt->execute();
    $monthRaw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

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
    $weekEnd = date('Y-m-d', strtotime('sunday this week'));
    $stmt = $conn->prepare("
        SELECT day_name, SUM(GREATEST(TIME_TO_SEC(TIMEDIFF(end_time, start_time)), 0)) / 3600 AS hours
        FROM planner
        WHERE user_id = ? AND study_date BETWEEN ? AND ?
        AND end_time > start_time
        GROUP BY day_name
    ");
    $stmt->bind_param('iss', $userId, $weekStart, $weekEnd);
    $stmt->execute();
    $weeklyRaw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

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

// Subject distribution (top 5 by session count)
$subjectLabels = [];
$subjectValues = [];
$stmt = $conn->prepare("
    SELECT s.name, COUNT(*) AS c
    FROM planner p INNER JOIN subjects s ON s.id = p.subject_id
    WHERE p.user_id = ?
    GROUP BY s.name ORDER BY c DESC LIMIT 5
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$subjectDistRaw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($subjectDistRaw as $r) {
    $subjectLabels[] = $r['name'];
    $subjectValues[] = (int) $r['c'];
}

echo json_encode([
    'study' => [
        'label' => $studyChart['label'],
        'summary' => $studyChart['summary'],
        'labels' => $studyChart['labels'],
        'values' => array_map(fn($v) => max(0, round((float) $v, 1)), $studyChart['values']),
        'totalHours' => max(0, round((float) $studyChart['totalHours'], 1)),
    ],
    'subject' => [
        'labels' => $subjectLabels,
        'values' => $subjectValues,
    ],
]);
