<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$currentLang = $_SESSION['lang'] ?? 'en';

$userId = (int) $_SESSION['user_id'];
$userName = $_SESSION['user_name'] ?? 'User';
$errors = [];

// Conversion rate (1 USD = 4,050 KHR)
$khrRate = 4050;

$budgetStmt = $conn->prepare('SELECT monthly_budget FROM users WHERE id = ?');
$budgetStmt->bind_param('i', $userId);
$budgetStmt->execute();
$monthlyBudget = (float) ($budgetStmt->get_result()->fetch_assoc()['monthly_budget'] ?? 150.00);

$selectedDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}

// Handle Add Expense
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_expense'])) {
    $title = trim($_POST['title'] ?? '');
    $amountUsd = (float) ($_POST['amount_usd'] ?? 0);
    $amountKhr = (float) ($_POST['amount_khr'] ?? 0);
    $expenseDate = $_POST['expense_date'] ?? '';

    // If USD is empty/zero, convert KHR input to USD
    if ($amountUsd <= 0 && $amountKhr > 0) {
        $amountUsd = $amountKhr / $khrRate;
    }

    if ($title === '' || $amountUsd <= 0 || $expenseDate === '') {
        $errors[] = 'Title, a valid amount (USD or KHR), and date are required.';
    } else {
        $stmt = $conn->prepare('INSERT INTO expenses (user_id, title, amount, expense_date) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('isds', $userId, $title, $amountUsd, $expenseDate);
        $stmt->execute();
        redirect('expenses.php?date=' . urlencode($selectedDate));
    }
}

// Handle updating the logged-in user's monthly budget.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_budget'])) {
    $newBudget = (float) ($_POST['monthly_budget'] ?? 0);

    if ($newBudget <= 0) {
        $errors[] = 'Monthly budget must be greater than 0.';
    } else {
        $stmt = $conn->prepare('UPDATE users SET monthly_budget = ? WHERE id = ?');
        $stmt->bind_param('di', $newBudget, $userId);
        $stmt->execute();
        redirect('expenses.php?date=' . urlencode($selectedDate));
    }
}

// Handle Delete Expense
if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $stmt = $conn->prepare('DELETE FROM expenses WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $id, $userId);
    $stmt->execute();
    redirect('expenses.php?date=' . urlencode($selectedDate));
}

// Fetch expenses for selected date
$stmt = $conn->prepare("
    SELECT id, title, amount, expense_date
    FROM expenses
    WHERE user_id = ? AND expense_date = ?
    ORDER BY id DESC
");
$stmt->bind_param('is', $userId, $selectedDate);
$stmt->execute();
$dateExpenses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch all expenses for calculation & report history
$stmtAll = $conn->prepare("SELECT id, title, amount, expense_date FROM expenses WHERE user_id = ? ORDER BY expense_date DESC");
$stmtAll->bind_param('i', $userId);
$stmtAll->execute();
$allExpenses = $stmtAll->get_result()->fetch_all(MYSQLI_ASSOC);

$dateTotal = array_sum(array_column($dateExpenses, 'amount'));
$allTotal = array_sum(array_column($allExpenses, 'amount'));
$monthStart = date('Y-m-01', strtotime($selectedDate));
$monthEnd = date('Y-m-t', strtotime($selectedDate));
$monthlyTotalStmt = $conn->prepare('SELECT COALESCE(SUM(amount), 0) AS total FROM expenses WHERE user_id = ? AND expense_date BETWEEN ? AND ?');
$monthlyTotalStmt->bind_param('iss', $userId, $monthStart, $monthEnd);
$monthlyTotalStmt->execute();
$monthlyTotal = (float) ($monthlyTotalStmt->get_result()->fetch_assoc()['total'] ?? 0);
$remaining = max(0, $monthlyBudget - $monthlyTotal);

// Group Expenses by Date for the Report Modal / Breakdown Card
$reportByDate = [];
foreach ($allExpenses as $item) {
    $reportByDate[$item['expense_date']][] = $item;
}

// Calculate daily total
$totalUsd = 0;
foreach ($dateExpenses as $expense) {
    $totalUsd += (float)$expense['amount'];
}
$totalKhr = $totalUsd * $khrRate;

?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expenses & Reports</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Noto+Sans+Khmer:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        html[lang="kh"] body { font-family: 'Noto Sans Khmer', 'Inter', sans-serif; }
    </style>
</head>
<body class="text-gray-800 antialiased min-h-screen">

<?php $activePage = 'expenses'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="max-w-6xl mx-auto px-4 py-8 space-y-8">

    <!-- Top Header Bar -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Expenses</h1>
            <p class="text-sm text-gray-500 mt-1">Track daily expenses, control budget, and generate reports.</p>
        </div>
        <form method="get" class="flex items-center gap-2 w-full sm:w-auto">
            <input type="date" class="border border-gray-300 rounded-xl px-4 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none w-full sm:w-auto" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>">
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-medium px-5 py-2 rounded-xl text-sm transition">View</button>
        </form>
    </div>

    <?php if ($errors): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl text-sm">
            <?php echo htmlspecialchars(implode(' ', $errors)); ?>
        </div>
    <?php endif; ?>

    <!-- Summary Metric Cards with Report Action -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-sm flex flex-col justify-between">
            <div>
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Selected Date Spent</p>
                <div class="text-3xl font-extrabold text-blue-600 mt-2">$<?php echo number_format($dateTotal, 2); ?></div>
            </div>
            <p class="text-xs text-gray-500 mt-4"><?php echo count($dateExpenses); ?> item(s) logged on this date</p>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-sm flex flex-col justify-between">
            <div>
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Total Spent (All-Time)</p>
                <div class="text-3xl font-extrabold text-gray-800 mt-2">$<?php echo number_format($allTotal, 2); ?></div>
            </div>
            <p class="text-xs text-gray-500 mt-4">Accumulated total spending</p>
        </div>

        <!-- Remaining Budget Card with Button Report -->
        <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-sm flex flex-col justify-between">
            <div>
                <div class="flex justify-between items-center">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Remaining Budget</p>

                    <button onclick="openReportModal()" class="bg-blue-50 hover:bg-blue-100 text-blue-600 border border-blue-200 font-semibold px-3 py-1 rounded-lg text-xs flex items-center space-x-1.5 transition">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        <span>Report</span>
                    </button>
                </div>
                <div class="text-3xl font-extrabold text-emerald-600 mt-2">$<?php echo number_format($remaining, 2); ?></div>
            </div>
            <!-- FIXED: Added / Month -->
            <p class="text-xs text-gray-500 mt-4">Target limit set at $<?php echo number_format($monthlyBudget, 2); ?> / Month</p>
        </div>
    </div>

    <!-- Form: Set Monthly Budget -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h2 class="text-lg font-bold text-gray-900">Monthly Budget</h2>
                <p class="text-sm text-gray-500 mt-1">Set your own spending limit for each month.</p>
            </div>
            <form method="post" class="flex flex-col sm:flex-row gap-2">
                <input type="hidden" name="save_budget" value="1">
                <input class="border border-gray-300 rounded-xl px-4 py-2.5 text-sm" type="number" name="monthly_budget" min="0.01" step="0.01" value="<?php echo htmlspecialchars(number_format($monthlyBudget, 2, '.', '')); ?>" required>
                <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold px-5 py-2.5 rounded-xl text-sm">Save Budget</button>
            </form>
        </div>
    </div>

    <!-- Form: Add Expense (Updated with KHR Input and Auto Sync) -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
        <h2 class="text-lg font-bold text-gray-900 mb-4">Add Expense</h2>
        <form method="post" class="grid grid-cols-1 sm:grid-cols-12 gap-4">
            <input type="hidden" name="add_expense" value="1">

            <div class="sm:col-span-4">
                <input class="w-full border border-gray-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none" name="title" placeholder="Title / Item Name" required>
            </div>

            <div class="sm:col-span-3">
                <input id="amount_usd" class="w-full border border-gray-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none" type="number" step="0.01" name="amount_usd" placeholder="Amount ($)" oninput="syncFromUsd()">
            </div>

            <div class="sm:col-span-3">
                <input id="amount_khr" class="w-full border border-gray-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none" type="number" step="100" name="amount_khr" placeholder="Amount (KHR ៛)" oninput="syncFromKhr()">
            </div>

            <div class="sm:col-span-2">
                <input class="w-full border border-gray-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none" type="date" name="expense_date" value="<?php echo htmlspecialchars($selectedDate); ?>" required>
            </div>

            <div class="sm:col-span-12">
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-xl shadow-sm text-sm transition">
                    Save Expense
                </button>
            </div>
        </form>
    </div>

    <!-- Table Card: Expenses for Selected Date -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center p-4 sm:p-6 border-b border-gray-100 gap-2 sm:gap-0">
            <div>
                <h3 class="text-base sm:text-lg font-bold text-gray-900">Expenses for <?php echo htmlspecialchars($selectedDate); ?></h3>
                <p class="text-xs text-gray-400">Track daily spending in USD and KHR</p>
            </div>
            <div class="flex items-center">
                <span class="text-xs font-semibold px-3 py-1 bg-gray-100 text-gray-600 rounded-full">
                    <?php echo count($dateExpenses); ?> items
                </span>
            </div>
        </div>

        <!-- Mobile View -->
        <div class="block md:hidden divide-y divide-gray-100">
            <?php foreach ($dateExpenses as $expense):
                $amountUsd = (float)$expense['amount'];
                $amountKhr = $amountUsd * $khrRate;
            ?>
                <div class="p-4 hover:bg-gray-50/50 transition duration-150">
                    <div class="flex justify-between items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <h4 class="font-semibold text-sm text-gray-800 truncate">
                                <?php echo htmlspecialchars($expense['title']); ?>
                            </h4>
                            <div class="flex items-center space-x-1 mt-1 text-xs text-gray-400">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                                <span><?php echo htmlspecialchars($expense['expense_date']); ?></span>
                            </div>
                        </div>

                        <div class="text-right flex-shrink-0">
                            <div class="font-semibold text-sm text-red-500">
                                -$<?php echo number_format($amountUsd, 2); ?>
                            </div>
                            <div class="text-[11px] text-gray-400 font-normal">
                                -<?php echo number_format($amountKhr); ?> ៛
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end mt-3 pt-2 border-t border-gray-50">
                        <a class="inline-flex items-center text-xs font-medium text-red-500 hover:text-red-700 p-1 rounded-md hover:bg-red-50 transition"
                           href="expenses.php?date=<?php echo urlencode($selectedDate); ?>&delete=<?php echo (int)$expense['id']; ?>"
                           onclick="return confirm('Delete this expense?')">
                            <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                            Delete
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (!$dateExpenses): ?>
                <div class="text-center py-8 px-4">
                    <div class="flex flex-col items-center justify-center text-gray-400 space-y-2">
                        <svg class="w-8 h-8 stroke-current" fill="none" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span class="text-xs font-medium">No expenses logged for this date.</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Desktop View -->
        <div class="hidden md:block overflow-x-auto">
            <table class="w-full text-left text-sm border-collapse">
                <thead>
                    <tr class="bg-gray-50/70 text-gray-400 font-semibold text-xs uppercase tracking-wider border-b border-gray-100">
                        <th class="py-3 px-6">Title</th>
                        <th class="py-3 px-6">Date</th>
                        <th class="py-3 px-6 text-right">Amount</th>
                        <th class="py-3 px-6 text-right w-28">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($dateExpenses as $expense):
                        $amountUsd = (float)$expense['amount'];
                        $amountKhr = $amountUsd * $khrRate;
                    ?>
                        <tr class="hover:bg-gray-50/80 transition-colors duration-150">
                            <td class="py-3.5 px-6 font-medium text-gray-800 align-middle">
                                <?php echo htmlspecialchars($expense['title']); ?>
                            </td>
                            <td class="py-3.5 px-6 text-gray-500 text-xs align-middle">
                                <?php echo htmlspecialchars($expense['expense_date']); ?>
                            </td>
                            <td class="py-3.5 px-6 text-right align-middle">
                                <div class="font-semibold text-red-500">
                                    -$<?php echo number_format($amountUsd, 2); ?>
                                </div>
                                <div class="text-xs text-gray-400 font-normal">
                                    -<?php echo number_format($amountKhr); ?> ៛
                                </div>
                            </td>
                            <td class="py-3.5 px-6 text-right align-middle">
                                <a class="inline-flex items-center text-xs font-medium text-red-500 hover:text-red-700 hover:bg-red-50 px-2 py-1 rounded-md transition"
                                   href="expenses.php?date=<?php echo urlencode($selectedDate); ?>&delete=<?php echo (int)$expense['id']; ?>"
                                   onclick="return confirm('Delete this expense?')">
                                    <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                    Delete
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$dateExpenses): ?>
                        <tr>
                            <td colspan="4" class="text-center py-10">
                                <div class="flex flex-col items-center justify-center text-gray-400 space-y-2">
                                    <svg class="w-8 h-8 stroke-current" fill="none" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <span class="text-sm font-medium">No expenses logged for this date.</span>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($dateExpenses): ?>
            <div class="p-4 bg-gray-50/50 border-t border-gray-100 flex justify-between items-center">
                <span class="text-xs text-gray-500 font-medium">Total Spent Today:</span>
                <div class="text-right">
                    <span class="text-sm sm:text-base font-bold text-red-600">-$<?php echo number_format($totalUsd, 2); ?></span>
                    <span class="text-xs text-gray-400 ml-1 block sm:inline">(-<?php echo number_format($totalKhr); ?> ៛)</span>
                </div>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- REPORT MODAL -->
<div id="reportModal" class="fixed inset-0 bg-gray-900/50 backdrop-blur-sm z-50 flex items-center justify-center hidden">
    <div class="bg-white rounded-2xl max-w-2xl w-full mx-4 shadow-xl overflow-hidden max-h-[85vh] flex flex-col">
        <div class="p-5 border-b border-gray-100 flex justify-between items-center">
            <div>
                <h3 class="text-lg font-bold text-gray-900">Expense Report Summary</h3>
                <p class="text-xs text-gray-500">Breakdown of historical expenses by date</p>
            </div>
            <button onclick="closeReportModal()" class="text-gray-400 hover:text-gray-600 p-1 rounded-lg">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="p-6 overflow-y-auto space-y-6">
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-blue-50/50 p-4 rounded-xl border border-blue-100">
                    <span class="text-xs font-semibold text-blue-600 uppercase">Total Expenses</span>
                    <div class="text-xl font-bold text-blue-700 mt-1">$<?php echo number_format($allTotal, 2); ?></div>
                </div>
                <div class="bg-emerald-50/50 p-4 rounded-xl border border-emerald-100">
                    <span class="text-xs font-semibold text-emerald-600 uppercase">Remaining Budget</span>
                    <div class="text-xl font-bold text-emerald-700 mt-1">$<?php echo number_format($remaining, 2); ?></div>
                </div>
            </div>

            <div class="space-y-4">
                <h4 class="text-sm font-bold text-gray-700">Detailed Daily Breakdown</h4>
                <?php foreach ($reportByDate as $groupDate => $items):
                    $subTotal = array_sum(array_column($items, 'amount'));
                ?>
                    <div class="border border-gray-100 rounded-xl p-4 bg-gray-50/50 expense-day-card" data-report-date="<?php echo htmlspecialchars($groupDate); ?>">
                        <div class="flex justify-between items-center border-b border-gray-200/60 pb-2 mb-2 gap-2">
                            <span class="text-xs font-bold text-gray-700"><?php echo htmlspecialchars(date('l, F j, Y', strtotime($groupDate))); ?></span>
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-bold text-red-500">Subtotal: -$<?php echo number_format($subTotal, 2); ?></span>
                                <button type="button" onclick="downloadExpenseDayPdf('<?php echo htmlspecialchars($groupDate); ?>')" class="bg-blue-600 hover:bg-blue-700 text-white text-[11px] font-semibold px-3 py-1.5 rounded-lg">
                                    Download
                                </button>
                            </div>
                        </div>
                        <ul class="divide-y divide-gray-100 text-xs">
                            <?php foreach ($items as $it): ?>
                                <li class="py-1.5 flex justify-between text-gray-600">
                                    <span><?php echo htmlspecialchars($it['title']); ?></span>
                                    <span class="font-medium text-gray-800">$<?php echo number_format($it['amount'], 2); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="p-4 bg-gray-50 border-t border-gray-100 flex justify-end">
            <button onclick="closeReportModal()" class="bg-gray-200 hover:bg-gray-300 text-gray-700 font-semibold px-4 py-2 rounded-xl text-xs transition">
                Close Report
            </button>
        </div>
    </div>
</div>

<script>
    const KHR_RATE = <?php echo $khrRate; ?>;

    // Synchronize inputs dynamically as the user types
    function syncFromUsd() {
        const usdInput = document.getElementById('amount_usd').value;
        if (usdInput !== '') {
            document.getElementById('amount_khr').value = Math.round(parseFloat(usdInput) * KHR_RATE);
        } else {
            document.getElementById('amount_khr').value = '';
        }
    }

    function syncFromKhr() {
        const khrInput = document.getElementById('amount_khr').value;
        if (khrInput !== '') {
            document.getElementById('amount_usd').value = (parseFloat(khrInput) / KHR_RATE).toFixed(2);
        } else {
            document.getElementById('amount_usd').value = '';
        }
    }

    function openReportModal() {
        document.getElementById('reportModal').classList.remove('hidden');
    }

    function closeReportModal() {
        document.getElementById('reportModal').classList.add('hidden');
    }

    function downloadExpenseDayPdf(dateKey) {
        const source = document.querySelector('[data-report-date="' + dateKey + '"]');
        if (!source) return;

        const clone = source.cloneNode(true);
        const button = clone.querySelector('button');
        if (button) button.remove();

        const popup = window.open('', '_blank', 'width=900,height=700');
        popup.document.write(`
            <html>
                <head>
                    <title>Expense Report ${dateKey}</title>
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
                        .rounded-xl { border-radius: 12px; }
                        .p-4 { padding: 20px; }
                        .mb-2 { margin-bottom: 12px; }
                        .pb-2 { padding-bottom: 12px; }
                        .border-b { border-bottom: 1px solid #e5e7eb; }
                        .flex { display: flex; }
                        .justify-between { justify-content: space-between; }
                        .items-center { align-items: center; }
                        .gap-2 { gap: 8px; }
                        .text-xs { font-size: 16px; }
                        .text-\\[11px\\] { font-size: 15px; }
                        .font-bold { font-weight: 700; }
                        .font-semibold { font-weight: 600; }
                        .text-gray-700 { color: #374151; }
                        .text-gray-800 { color: #1f2937; }
                        .text-red-500 { color: #ef4444; }
                        .bg-gray-50 { background: #f9fafb; }
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

</body>
</html>