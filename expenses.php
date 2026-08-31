<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$currentLang = $_SESSION['lang'] ?? 'en';

$userId = (int) $_SESSION['user_id'];
$userName = $_SESSION['user_name'] ?? 'User';
$errors = [];

// Conversion rate (1 USD = 4,050 KHR)
$khrRate = 4050;

// Per-month budget helper: returns [base_budget, carry_in] for a given 'YYYY-MM'
// month, creating a row on first access so leftover money from the previous month
// (base_budget + carry_in + income - expenses) is carried in automatically.
function expense_budget_get($month, $baseBudget) {
    global $conn, $userId;

    $stmt = $conn->prepare('SELECT base_budget, carry_in FROM budget_months WHERE user_id = ? AND budget_month = ?');
    $stmt->bind_param('is', $userId, $month);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        return [(float) $row['base_budget'], (float) $row['carry_in']];
    }

    // No row yet — create one, carrying in the leftover from the previous month (only
    // when that month has its own tracked row, so we never recurse into arbitrary history).
    $carryIn = 0.0;
    $prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
    $prevStmt = $conn->prepare('SELECT base_budget, carry_in FROM budget_months WHERE user_id = ? AND budget_month = ?');
    $prevStmt->bind_param('is', $userId, $prevMonth);
    $prevStmt->execute();
    $prevRow = $prevStmt->get_result()->fetch_assoc();

    if ($prevRow) {
        $prevBase = (float) $prevRow['base_budget'];
        $prevCarry = (float) $prevRow['carry_in'];
        $ps = $prevMonth . '-01';
        $pe = date('Y-m-t', strtotime($ps));
        $ptStmt = $conn->prepare('SELECT type, COALESCE(SUM(amount),0) AS total FROM expenses WHERE user_id = ? AND expense_date BETWEEN ? AND ? GROUP BY type');
        $ptStmt->bind_param('iss', $userId, $ps, $pe);
        $ptStmt->execute();
        $pIncome = 0.0;
        $pExpense = 0.0;
        foreach ($ptStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $pr) {
            if ($pr['type'] === 'income') {
                $pIncome = (float) $pr['total'];
            } else {
                $pExpense = (float) $pr['total'];
            }
        }
        $carryIn = max(0, $prevCarry + $prevBase + $pIncome - $pExpense);
    }

    $ins = $conn->prepare('INSERT INTO budget_months (user_id, budget_month, base_budget, carry_in) VALUES (?, ?, ?, ?)');
    $ins->bind_param('issd', $userId, $month, $baseBudget, $carryIn);
    $ins->execute();

    return [(float) $baseBudget, $carryIn];
}

// --- Auto-migrate: make sure the `type` column exists so we can tell
// ចំណាយ (expense) rows apart from ចំណូល (income) rows in the same table. ---
$colCheck = $conn->query("SHOW COLUMNS FROM expenses LIKE 'type'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE expenses ADD COLUMN type ENUM('expense','income') NOT NULL DEFAULT 'expense' AFTER amount");
}

$budgetStmt = $conn->prepare('SELECT monthly_budget FROM users WHERE id = ?');
$budgetStmt->bind_param('i', $userId);
$budgetStmt->execute();
$monthlyBudget = (float) ($budgetStmt->get_result()->fetch_assoc()['monthly_budget'] ?? 150.00);

$selectedDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}
$selectedMonth = substr($selectedDate, 0, 7);
$todayDate = date('Y-m-d');
$selectedTs = strtotime($selectedDate);
$prevDate = date('Y-m-d', strtotime('-1 day', $selectedTs));
$nextDate = date('Y-m-d', strtotime('+1 day', $selectedTs));

$success = '';

// Handle Add Expense (ចំណាយ)
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
        $type = 'expense';
        $stmt = $conn->prepare('INSERT INTO expenses (user_id, title, amount, expense_date, type) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('isdss', $userId, $title, $amountUsd, $expenseDate, $type);
        $stmt->execute();
        redirect('expenses.php?date=' . urlencode($selectedDate));
    }
}

// Handle Add Income (ចំណូល)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_income'])) {
    $title = trim($_POST['income_title'] ?? '');
    $amountUsd = (float) ($_POST['income_amount_usd'] ?? 0);
    $amountKhr = (float) ($_POST['income_amount_khr'] ?? 0);
    $incomeDate = $_POST['income_date'] ?? '';

    // If USD is empty/zero, convert KHR input to USD
    if ($amountUsd <= 0 && $amountKhr > 0) {
        $amountUsd = $amountKhr / $khrRate;
    }

    if ($title === '' || $amountUsd <= 0 || $incomeDate === '') {
        $errors[] = 'Source, a valid amount (USD or KHR), and date are required for income.';
    } else {
        $type = 'income';
        $stmt = $conn->prepare('INSERT INTO expenses (user_id, title, amount, expense_date, type) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('isdss', $userId, $title, $amountUsd, $incomeDate, $type);
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

        // Apply the new budget to the month currently being viewed so the change
        // shows up immediately. Keep the month's carried-in leftover untouched.
        $monthlyBudget = $newBudget;
        $viewMonth = substr($selectedDate, 0, 7);
        $upd = $conn->prepare('INSERT INTO budget_months (user_id, budget_month, base_budget, carry_in)
                               VALUES (?, ?, ?, 0.00)
                               ON DUPLICATE KEY UPDATE base_budget = VALUES(base_budget)');
        $upd->bind_param('isd', $userId, $viewMonth, $newBudget);
        $upd->execute();

        redirect('expenses.php?date=' . urlencode($selectedDate));
    }
}

// Handle Delete (works for both expense and income rows)
$isPrefetch = (($_SERVER['HTTP_PURPOSE'] ?? '') === 'prefetch' || ($_SERVER['HTTP_SEC_PURPOSE'] ?? '') === 'prefetch');
if (isset($_GET['delete']) && !$isPrefetch) {
    $id = (int) $_GET['delete'];
    $stmt = $conn->prepare('DELETE FROM expenses WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $id, $userId);
    $stmt->execute();
    redirect('expenses.php?date=' . urlencode($selectedDate));
}

// Handle Edit / Update (works for both expense and income rows)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_expense'])) {
    $id = (int) ($_POST['entry_id'] ?? 0);
    $title = trim($_POST['entry_title'] ?? '');
    $amountUsd = (float) ($_POST['entry_amount_usd'] ?? 0);
    $amountKhr = (float) ($_POST['entry_amount_khr'] ?? 0);
    $entryDate = $_POST['entry_date'] ?? '';
    $entryType = $_POST['entry_type'] ?? 'expense';
    if (!in_array($entryType, ['expense', 'income'], true)) {
        $entryType = 'expense';
    }

    // Convert KHR to USD when USD is empty.
    if ($amountUsd <= 0 && $amountKhr > 0) {
        $amountUsd = $amountKhr / $khrRate;
    }

    if ($id <= 0 || $title === '' || $amountUsd <= 0 || $entryDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) {
        $errors[] = 'Title, a valid amount (USD or KHR), and date are required.';
    } else {
        $stmt = $conn->prepare('UPDATE expenses SET title = ?, amount = ?, expense_date = ?, type = ? WHERE id = ? AND user_id = ?');
        $stmt->bind_param('sdssii', $title, $amountUsd, $entryDate, $entryType, $id, $userId);
        $stmt->execute();
        redirect('expenses.php?date=' . urlencode($entryDate));
    }
}

// Fetch all entries (expense + income) for the selected date
$stmt = $conn->prepare("
    SELECT id, title, amount, expense_date, type
    FROM expenses
    WHERE user_id = ? AND expense_date = ?
    ORDER BY id DESC
");
$stmt->bind_param('is', $userId, $selectedDate);
$stmt->execute();
$dateItems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// All-time totals in ONE aggregate query (avoids loading every row into PHP)
$totStmt = $conn->prepare('SELECT type, COALESCE(SUM(amount), 0) AS total FROM expenses WHERE user_id = ? GROUP BY type');
$totStmt->bind_param('i', $userId);
$totStmt->execute();
$allExpenseTotal = 0.0;
$allIncomeTotal = 0.0;
foreach ($totStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    if ($row['type'] === 'income') {
        $allIncomeTotal = (float) $row['total'];
    } else {
        $allExpenseTotal = (float) $row['total'];
    }
}
$allNet = $allIncomeTotal - $allExpenseTotal;

// Split totals by type — selected date
$dateExpenseTotal = 0.0;
$dateIncomeTotal = 0.0;
foreach ($dateItems as $item) {
    if ($item['type'] === 'income') {
        $dateIncomeTotal += (float) $item['amount'];
    } else {
        $dateExpenseTotal += (float) $item['amount'];
    }
}
$dateNet = $dateIncomeTotal - $dateExpenseTotal;

// Monthly totals (used for the Remaining Budget calculation)
$monthStart = date('Y-m-01', strtotime($selectedDate));
$monthEnd = date('Y-m-t', strtotime($selectedDate));
$monthlyStmt = $conn->prepare('SELECT type, COALESCE(SUM(amount), 0) AS total FROM expenses WHERE user_id = ? AND expense_date BETWEEN ? AND ? GROUP BY type');
$monthlyStmt->bind_param('iss', $userId, $monthStart, $monthEnd);
$monthlyStmt->execute();
$monthlyExpenseTotal = 0.0;
$monthlyIncomeTotal = 0.0;
foreach ($monthlyStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    if ($row['type'] === 'income') {
        $monthlyIncomeTotal = (float) $row['total'];
    } else {
        $monthlyExpenseTotal = (float) $row['total'];
    }
}
// Income tops up the budget instead of only expenses eating into it.
// Leftover money from the previous month (budget_carryover) rolls into the current
// month's budget automatically, so the effective budget = base + carried-in leftover.
$nowMonth = date('Y-m');
expense_budget_get($nowMonth, $monthlyBudget); // seed the current real month chain
[$dispBase, $dispCarryIn] = expense_budget_get($selectedMonth, $monthlyBudget);
$effectiveBudget = $dispBase + $dispCarryIn;
$remaining = max(0, $effectiveBudget - $monthlyExpenseTotal + $monthlyIncomeTotal);

// Group recent entries (last 90 days) by date for the Report Modal / Breakdown Card
$reportStart = date('Y-m-d', strtotime('-90 days'));
$repStmt = $conn->prepare("SELECT id, title, amount, expense_date, type FROM expenses WHERE user_id = ? AND expense_date >= ? ORDER BY expense_date DESC, id DESC");
$repStmt->bind_param('is', $userId, $reportStart);
$repStmt->execute();
$reportByDate = [];
foreach ($repStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $item) {
    $reportByDate[$item['expense_date']][] = $item;
}

// Daily totals (selected date), kept for the table footer
$totalUsd = $dateExpenseTotal;
$totalKhr = $totalUsd * $khrRate;
$incomeUsd = $dateIncomeTotal;
$incomeKhr = $incomeUsd * $khrRate;

?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expenses & Reports</title>
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
            --c-expenses: #FF6B6B;
            --c-income: #00B894;
            --radius: 16px;
            --radius-sm: 10px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,.04);
            --shadow-md: 0 4px 16px rgba(0,0,0,.06);
            --transition: .2s cubic-bezier(.4,0,.2,1);
            --transition-slow: .35s cubic-bezier(.22,1,.36,1);
        }

        html, body { background: var(--paper); }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            color: var(--ink);
            -webkit-font-smoothing: antialiased;
            transition: background var(--transition-slow), color var(--transition-slow);
        }
        html[lang="kh"] body { font-family: 'Noto Sans Khmer', 'Inter', sans-serif; }

        @keyframes fadeSlideUp { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: translateY(0); } }
        .anim-up { animation: fadeSlideUp .4s cubic-bezier(.22,1,.36,1) both; }
        .anim-1 { animation-delay: .05s; }
        .anim-2 { animation-delay: .1s; }
        .anim-3 { animation-delay: .15s; }
        .anim-4 { animation-delay: .2s; }
        .anim-5 { animation-delay: .25s; }

        /* ---- Hero ---- */
        .expenses-hero {
            background: linear-gradient(135deg, #1d557b 0%, #4e4376 50%, #e585ff 100%);
            border-radius: var(--radius);
            padding: 28px 32px;
            color: #fff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 12px 40px rgba(0,0,0,.08);
        }
        .expenses-hero::after {
            content: "";
            position: absolute;
            top: -50px; right: -30px;
            width: 200px; height: 200px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255,255,255,.2) 0%, transparent 70%);
            opacity: .4;
        }

        /* ---- Section Header ---- */
        .section-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }
        .section-header h6 {
            margin: 0;
            font-weight: 700;
            font-size: .9rem;
            color: var(--ink);
            white-space: nowrap;
        }
        .section-header::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
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
            overflow: hidden;
            transition: transform var(--transition-slow), box-shadow var(--transition-slow), background var(--transition-slow), border-color var(--transition-slow);
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
        .stat-card .stat-accent { height: 4px; width: 100%; }
        .stat-card .stat-body { padding: 18px 20px; }
        .stat-label { font-size: .7rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--ink-soft); margin-bottom: 6px; }
        .stat-value {
            font-size: 1.5rem;
            font-weight: 800;
            line-height: 1.15;
            transition: color var(--transition-slow);
        }
        .stat-sub { font-size: .74rem; color: var(--ink-soft); margin-top: auto; padding-top: 8px; }

        .accent-today { background: linear-gradient(90deg, #FF6B6B, #FAB1A0); }
        .accent-month { background: linear-gradient(90deg, #E17055, #FDCB6E); }
        .accent-alltime { background: linear-gradient(90deg, #6C5CE7, #A29BFE); }
        .accent-budget { background: linear-gradient(90deg, #00B894, #55EFC4); }

        /* ---- Collapsible Form Cards ---- */
        .add-form-card {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            transition: box-shadow var(--transition-slow), background var(--transition-slow), border-color var(--transition-slow);
        }
        .add-form-card:hover { box-shadow: var(--shadow-md); }
        .add-form-toggle {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            user-select: none;
            transition: background var(--transition);
        }
        .add-form-toggle:hover { background: rgba(0,0,0,.015); }
        body[data-theme="dark"] .add-form-toggle:hover { background: rgba(255,255,255,.02); }
        .add-form-toggle h6 { margin: 0; font-weight: 700; font-size: .9rem; display: flex; align-items: center; gap: 8px; }

        /* Smooth collapse: animate grid-template-rows instead of display:none/block */
        .add-form-wrap {
            display: grid;
            grid-template-rows: 1fr;
            transition: grid-template-rows var(--transition-slow);
        }
        .add-form-wrap.collapsed { grid-template-rows: 0fr; }
        .add-form-body {
            padding: 24px;
            overflow: hidden;
            min-height: 0;
            opacity: 1;
            transition: opacity var(--transition-slow), padding var(--transition-slow);
        }
        .add-form-wrap.collapsed .add-form-body {
            opacity: 0;
            padding-top: 0;
            padding-bottom: 0;
        }

        /* ---- Form Controls ---- */
        .form-control, .form-select {
            border-color: var(--border);
            font-size: .85rem;
            border-radius: var(--radius-sm);
            padding: .6rem .9rem;
            transition: var(--transition);
        }
        .form-control:focus, .form-select:focus {
            box-shadow: 0 0 0 3px rgba(255,107,107,.1);
            border-color: var(--c-expenses);
        }

        .btn-accent {
            background: linear-gradient(135deg, #FF6B6B, #FAB1A0);
            border: none;
            border-radius: var(--radius-sm);
            padding: .6rem 1.5rem;
            font-weight: 600;
            font-size: .85rem;
            color: #fff;
            transition: var(--transition);
        }
        .btn-accent:hover { filter: brightness(1.08); transform: translateY(-1px); color: #fff; box-shadow: 0 6px 16px rgba(255,107,107,.3); }
        .btn-accent:active { transform: translateY(0); }

        .btn-income {
            background: linear-gradient(135deg, #00B894, #55EFC4);
            border: none;
            border-radius: var(--radius-sm);
            padding: .6rem 1.5rem;
            font-weight: 600;
            font-size: .85rem;
            color: #fff;
            transition: var(--transition);
        }
        .btn-income:hover { filter: brightness(1.08); transform: translateY(-1px); color: #fff; box-shadow: 0 6px 16px rgba(0,184,148,.3); }
        .btn-income:active { transform: translateY(0); }

        .btn-cancel {
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: .6rem 1.2rem;
            font-weight: 500;
            font-size: .85rem;
            color: var(--ink-soft);
            background: var(--surface);
            transition: var(--transition);
        }
        .btn-cancel:hover { border-color: var(--ink-soft); color: var(--ink); }

        /* ---- Entry Cards & Table ---- */
        .entries-card {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            transition: box-shadow var(--transition-slow), background var(--transition-slow), border-color var(--transition-slow);
        }
        .entries-card:hover { box-shadow: var(--shadow-md); }
        .entries-header {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .item-count-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 24px;
            border-radius: 999px;
            font-size: .7rem;
            font-weight: 700;
            padding: 0 8px;
            background: var(--paper);
            border: 1px solid var(--border);
            color: var(--ink-soft);
        }

        .type-badge-expense {
            background: rgba(255,107,107,.1);
            color: #E17055;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: .68rem;
            font-weight: 700;
        }
        .type-badge-income {
            background: rgba(0,184,148,.1);
            color: #00B894;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: .68rem;
            font-weight: 700;
        }

        .entry-item {
            padding: 14px 24px;
            border-bottom: 1px solid var(--border);
            transition: background var(--transition);
        }
        .entry-item:last-child { border-bottom: none; }
        .entry-item:hover { background: rgba(245,247,250,.6); }

        .entries-footer {
            padding: 14px 24px;
            border-top: 1px solid var(--border);
            background: var(--paper);
        }

        .btn-entry-delete {
            padding: 4px 10px;
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
        .btn-entry-delete:hover { border-color: var(--c-expenses); color: var(--c-expenses); background: rgba(255,107,107,.05); }

        .btn-entry-edit {
            padding: 4px 10px;
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
        .btn-entry-edit:hover { border-color: var(--c-income); color: var(--c-income); background: rgba(0,184,148,.05); }

        table tbody tr { transition: background var(--transition); }
        table tbody tr:hover { background: rgba(245,247,250,.6); }
        body[data-theme="dark"] table tbody tr:hover { background: rgba(255,255,255,.03); }

        /* ---- Empty State ---- */
        .empty-state {
            text-align: center;
            padding: 48px 16px;
            color: var(--ink-soft);
        }
        .empty-state svg { width: 52px; height: 52px; opacity: .25; margin-bottom: 14px; }
        .empty-state p { font-size: .85rem; margin: 0; }

        /* ---- Report Modal ---- */
        #reportModal .modal-content {
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
        }
        #reportModal .modal-header {
            border-bottom: 1px solid var(--border);
            padding: 18px 24px;
        }
        #reportModal .modal-body { padding: 24px; }
        #reportModal .modal-footer {
            border-top: 1px solid var(--border);
            padding: 14px 24px;
            background: var(--paper);
        }

        .report-summary-card {
            padding: 18px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
        }
        .report-day-card {
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 16px;
            background: var(--paper);
            margin-bottom: 12px;
            transition: box-shadow var(--transition), transform var(--transition);
        }
        .report-day-card:hover { box-shadow: var(--shadow-sm); transform: translateX(2px); }
        .report-day-card:last-child { margin-bottom: 0; }

        /* ---- Responsive ---- */
        @media (max-width: 767.98px) {
            .expenses-hero { padding: 20px; }
            .add-form-body { padding: 16px; }
        }

        /* ---- Dark Mode ---- */
        body[data-theme="dark"] {
            --paper: #0F1219;
            --surface: #181D2A;
            --ink: #E4E6EF;
            --ink-soft: #8B90A5;
            --border: #2A2F3E;
            --shadow-sm: 0 1px 3px rgba(0,0,0,.15);
            --shadow-md: 0 4px 16px rgba(0,0,0,.25);
            background: var(--paper);
            color: var(--ink);
        }
        body[data-theme="dark"] .form-control,
        body[data-theme="dark"] .form-select {
            background: var(--surface);
            color: var(--ink);
            border-color: var(--border);
        }
        body[data-theme="dark"] .form-control:focus,
        body[data-theme="dark"] .form-select:focus {
            background: var(--surface);
            color: var(--ink);
        }
        body[data-theme="dark"] .stat-card,
        body[data-theme="dark"] .add-form-card,
        body[data-theme="dark"] .entries-card,
        body[data-theme="dark"] #reportModal .modal-content {
            background: var(--surface);
            border-color: var(--border);
        }
        body[data-theme="dark"] .entry-item:hover { background: rgba(255,255,255,.03); }
        body[data-theme="dark"] .btn-cancel {
            background: var(--surface);
            color: var(--ink-soft);
            border-color: var(--border);
        }
        body[data-theme="dark"] .btn-entry-delete,
        body[data-theme="dark"] .btn-entry-edit {
            background: var(--surface);
            border-color: var(--border);
        }
        body[data-theme="dark"] .report-summary-card { background: var(--surface); }
        body[data-theme="dark"] .report-day-card { background: var(--surface); }
    </style>
</head>
<body data-theme="<?php echo htmlspecialchars(current_theme()); ?>">

<?php $activePage = 'expenses'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="container py-4" style="max-width: 1200px;">

    <!-- Hero Panel -->
    <div class="expenses-hero mb-4 d-flex align-items-center justify-content-between flex-wrap gap-3 anim-up">
        <div>
            <h2 class="h3 fw-bold mb-1">Expenses & Reports</h2>
            <p class="mb-0 small" style="color:rgba(255,255,255,.65);">Track daily expenses &amp; income, control budget, and generate reports.</p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span style="background:rgba(255,255,255,.1);backdrop-filter:blur(8px);border:1px solid rgba(255,255,255,.15);border-radius:999px;padding:8px 18px;font-size:.82rem;font-weight:600;color:#fff;">
                <i class="bi bi-calendar3 me-1"></i><?php echo htmlspecialchars(date('F j, Y', strtotime($selectedDate))); ?>
            </span>
            <span style="background:rgba(255,255,255,.1);backdrop-filter:blur(8px);border:1px solid rgba(255,255,255,.15);border-radius:999px;padding:8px 18px;font-size:.82rem;font-weight:600;color:#fff;">
                <i class="bi bi-receipt me-1"></i><?php echo count($dateItems); ?> items
            </span>
            <form method="get" class="d-flex align-items-center gap-2 flex-wrap" style="z-index:2;position:relative;">
                <div class="d-flex align-items-center gap-1">
                    <a href="expenses.php?date=<?php echo urlencode($prevDate); ?>" title="Previous day" class="date-nav-btn" style="border-color:rgba(255,255,255,.2);background:rgba(255,255,255,.1);color:#fff;">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                    <input type="date" class="form-control form-control-sm" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>" style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.2);color:#fff;border-radius:999px;padding:6px 14px;font-size:.8rem;width:auto;">
                    <a href="expenses.php?date=<?php echo urlencode($nextDate); ?>" title="Next day" class="date-nav-btn" style="border-color:rgba(255,255,255,.2);background:rgba(255,255,255,.1);color:#fff;">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </div>
                <button type="submit" class="btn btn-sm" style="background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.25);color:#fff;border-radius:999px;font-weight:600;font-size:.8rem;padding:6px 16px;">
                    <i class="bi bi-eye me-1"></i>View
                </button>
                <?php if ($selectedDate !== $todayDate): ?>
                    <a href="expenses.php" class="btn btn-sm fw-semibold" style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.15);color:#fff;border-radius:999px;font-weight:600;font-size:.8rem;padding:6px 16px;">
                        <i class="bi bi-calendar-day me-1"></i>Today
                    </a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($errors): ?>
        <div class="alert alert-danger py-2 anim-up anim-1" style="border-radius:var(--radius-sm);">
            <i class="bi bi-exclamation-triangle-fill me-1"></i><?php echo htmlspecialchars(implode(' ', $errors)); ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
        <div class="alert alert-success py-2 anim-up anim-1" style="border-radius:var(--radius-sm);">
            <i class="bi bi-check-circle-fill me-1"></i><?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4 anim-up anim-1">
        <!-- Today -->
        <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card h-100">
                <div class="stat-accent accent-today"></div>
                <div class="stat-body d-flex flex-column h-100">
                    <p class="stat-label"><i class="bi bi-calendar-day me-1"></i>Total Spent Today</p>
                    <div class="stat-value" style="color:#FF6B6B;">-$<?php echo number_format($dateExpenseTotal, 2); ?></div>
                    <div class="small fw-semibold" style="color:#00B894;">+$<?php echo number_format($dateIncomeTotal, 2); ?> income</div>
                    <p class="stat-sub"><?php echo count($dateItems); ?> item(s) logged on this date</p>
                </div>
            </div>
        </div>
        <!-- This Month -->
        <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card h-100">
                <div class="stat-accent accent-month"></div>
                <div class="stat-body d-flex flex-column h-100">
                    <p class="stat-label"><i class="bi bi-calendar-month me-1"></i>Total Spent (This Month)</p>
                    <div class="stat-value" style="color:#E17055;">-$<?php echo number_format($monthlyExpenseTotal, 2); ?></div>
                    <div class="small fw-semibold" style="color:#00B894;">+$<?php echo number_format($monthlyIncomeTotal, 2); ?> income</div>
                    <p class="stat-sub"><?php echo htmlspecialchars(date('F Y', strtotime($selectedDate))); ?> totals</p>
                </div>
            </div>
        </div>
        <!-- All-Time -->
        <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card h-100">
                <div class="stat-accent accent-alltime"></div>
                <div class="stat-body d-flex flex-column h-100">
                    <p class="stat-label"><i class="bi bi-infinity me-1"></i>Total Spent (All-Time)</p>
                    <div class="stat-value" style="color:#6C5CE7;">-$<?php echo number_format($allExpenseTotal, 2); ?></div>
                    <div class="small fw-semibold" style="color:#00B894;">+$<?php echo number_format($allIncomeTotal, 2); ?> income</div>
                    <p class="stat-sub">Accumulated total spending &amp; income</p>
                </div>
            </div>
        </div>
        <!-- Remaining Budget -->
        <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card h-100">
                <div class="stat-accent accent-budget"></div>
                <div class="stat-body d-flex flex-column h-100">
                    <div class="d-flex justify-content-between align-items-center gap-2">
                        <p class="stat-label mb-0"><i class="bi bi-wallet2 me-1"></i>Remaining Budget</p>
                        <button onclick="openReportModal()" class="btn btn-sm" style="background:rgba(0,184,148,.1);color:#00B894;border:1px solid rgba(0,184,148,.2);border-radius:8px;font-weight:600;font-size:.72rem;padding:4px 10px;transition:var(--transition);">
                            <i class="bi bi-file-earmark-bar-graph me-1"></i>Report
                        </button>
                    </div>
                    <div class="stat-value" style="color:#00B894;">$<?php echo number_format($remaining, 2); ?></div>
                    <?php if ($dispCarryIn > 0): ?>
                        <p class="stat-sub">Budget $<?php echo number_format($dispBase, 2); ?> <span style="color:#00B894;">+ $<?php echo number_format($dispCarryIn, 2); ?> carried</span> - Expenses $<?php echo number_format($monthlyExpenseTotal, 2); ?> + Income $<?php echo number_format($monthlyIncomeTotal, 2); ?></p>
                    <?php else: ?>
                        <p class="stat-sub">Budget $<?php echo number_format($effectiveBudget, 2); ?> - Expenses $<?php echo number_format($monthlyExpenseTotal, 2); ?> + Income $<?php echo number_format($monthlyIncomeTotal, 2); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Monthly Budget Form -->
    <div class="add-form-card mb-4 anim-up anim-2">
        <div class="add-form-toggle" id="budgetFormToggle">
            <h6><i class="bi bi-piggy-bank" style="color:var(--c-income);"></i> Monthly Budget</h6>
            <svg id="budgetChevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="color:var(--ink-soft);transition:transform .3s ease;"><path d="M6 9l6 6 6-6"/></svg>
        </div>
        <div class="add-form-wrap" id="budgetFormWrap">
        <div class="add-form-body" id="budgetFormBody">
            <p class="small mb-3" style="color:var(--ink-soft);">Set a monthly limit. Income tops it up; remaining funds roll over.</p>
            <form method="post" class="d-flex flex-wrap align-items-end gap-3">
                <input type="hidden" name="save_budget" value="1">
                <div class="flex-grow-1" style="min-width:180px;">
                    <label class="form-label small fw-semibold" style="color:var(--ink-soft);">Monthly Budget (USD)</label>
                    <input class="form-control" type="number" name="monthly_budget" min="0.01" step="0.01" value="<?php echo htmlspecialchars(number_format($monthlyBudget, 2, '.', '')); ?>" required>
                </div>
                <button type="submit" class="btn-income" style="white-space:nowrap;"><i class="bi bi-check-lg me-1"></i>Save Budget</button>
            </form>
        </div>
        </div>
    </div>

    <!-- Add Expense Form -->
    <div class="add-form-card mb-4 anim-up anim-3">
        <div class="add-form-toggle" id="expenseFormToggle">
            <h6><i class="bi bi-dash-circle" style="color:var(--c-expenses);"></i> Add Expense <span class="type-badge-expense ms-1" style="font-size:.65rem;">ចំណាយ</span></h6>
            <svg id="expenseChevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="color:var(--ink-soft);transition:transform .3s ease;"><path d="M6 9l6 6 6-6"/></svg>
        </div>
        <div class="add-form-wrap" id="expenseFormWrap">
        <div class="add-form-body" id="expenseFormBody">
            <form method="post">
                <input type="hidden" name="add_expense" value="1">
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label small fw-semibold" style="color:var(--ink-soft);">Title / Item Name</label>
                        <input class="form-control" name="title" placeholder="e.g. Lunch, Transport..." required>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small fw-semibold" style="color:var(--ink-soft);">Amount (USD $)</label>
                        <input id="amount_usd" class="form-control" type="number" step="0.01" name="amount_usd" placeholder="0.00" oninput="syncFromUsd('amount_usd','amount_khr')">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small fw-semibold" style="color:var(--ink-soft);">Amount (KHR ៛)</label>
                        <input id="amount_khr" class="form-control" type="number" step="100" name="amount_khr" placeholder="0" oninput="syncFromKhr('amount_usd','amount_khr')">
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="form-label small fw-semibold" style="color:var(--ink-soft);">Date</label>
                        <input class="form-control" type="date" name="expense_date" value="<?php echo htmlspecialchars($selectedDate); ?>" required>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn-accent w-100"><i class="bi bi-plus-lg me-1"></i>Save Expense</button>
                    </div>
                </div>
            </form>
        </div>
        </div>
    </div>

    <!-- Add Income Form -->
    <div class="add-form-card mb-4 anim-up anim-4">
        <div class="add-form-toggle" id="incomeFormToggle">
            <h6><i class="bi bi-plus-circle" style="color:var(--c-income);"></i> Add Income <span class="type-badge-income ms-1" style="font-size:.65rem;">ចំណូល</span></h6>
            <svg id="incomeChevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="color:var(--ink-soft);transition:transform .3s ease;"><path d="M6 9l6 6 6-6"/></svg>
        </div>
        <div class="add-form-wrap" id="incomeFormWrap">
        <div class="add-form-body" id="incomeFormBody">
            <form method="post">
                <input type="hidden" name="add_income" value="1">
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label small fw-semibold" style="color:var(--ink-soft);">Title / Source Name</label>
                        <input class="form-control" name="income_title" placeholder="e.g. Freelance, Salary..." required>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small fw-semibold" style="color:var(--ink-soft);">Amount (USD $)</label>
                        <input id="income_amount_usd" class="form-control" type="number" step="0.01" name="income_amount_usd" placeholder="0.00" oninput="syncFromUsd('income_amount_usd','income_amount_khr')">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small fw-semibold" style="color:var(--ink-soft);">Amount (KHR ៛)</label>
                        <input id="income_amount_khr" class="form-control" type="number" step="100" name="income_amount_khr" placeholder="0" oninput="syncFromKhr('income_amount_usd','income_amount_khr')">
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="form-label small fw-semibold" style="color:var(--ink-soft);">Date</label>
                        <input class="form-control" type="date" name="income_date" value="<?php echo htmlspecialchars($selectedDate); ?>" required>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn-income w-100"><i class="bi bi-plus-lg me-1"></i>Save Income</button>
                    </div>
                </div>
            </form>
        </div>
        </div>
    </div>

    <!-- Entries Card: Expenses for Selected Date -->
    <div class="entries-card mb-4 anim-up anim-5">
        <div class="entries-header">
            <div>
                <h6 class="mb-0 fw-bold"><i class="bi bi-receipt-cutoff me-1" style="color:var(--c-expenses);"></i> Expenses for <?php echo htmlspecialchars($selectedDate); ?></h6>
                <small style="color:var(--ink-soft);">Track daily expenses &amp; income in USD and KHR</small>
            </div>
            <span class="item-count-badge"><?php echo count($dateItems); ?>items</span>
        </div>

        <!-- Mobile View -->
        <div class="d-block d-md-none">
            <?php if ($dateItems): ?>
                <?php foreach ($dateItems as $item):
                    $isIncome = $item['type'] === 'income';
                    $amountUsd = (float)$item['amount'];
                    $amountKhr = $amountUsd * $khrRate;
                    $sign = $isIncome ? '+' : '-';
                    $amountColor = $isIncome ? '#00B894' : '#FF6B6B';
                    $badgeClass = $isIncome ? 'type-badge-income' : 'type-badge-expense';
                    $badgeLabel = $isIncome ? 'Income · ចំណូល' : 'Expense · ចំណាយ';
                ?>
                    <div class="entry-item">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div class="min-w-0 flex-grow-1">
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <span class="fw-semibold small" style="color:var(--ink);"><?php echo htmlspecialchars($item['title']); ?></span>
                                    <span class="<?php echo $badgeClass; ?>"><?php echo $badgeLabel; ?></span>
                                </div>
                                <div class="d-flex align-items-center gap-1 mt-1" style="font-size:.75rem;color:var(--ink-soft);">
                                    <i class="bi bi-calendar3"></i>
                                    <span><?php echo htmlspecialchars($item['expense_date']); ?></span>
                                </div>
                            </div>
                            <div class="text-end flex-shrink-0">
                                <div class="fw-semibold small" style="color:<?php echo $amountColor; ?>;"><?php echo $sign; ?>$<?php echo number_format($amountUsd, 2); ?></div>
                                <div style="font-size:.72rem;color:var(--ink-soft);"><?php echo $sign; ?><?php echo number_format($amountKhr); ?> ៛</div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end gap-2 mt-2 pt-2" style="border-top:1px solid var(--border);">
                            <a class="btn-entry-edit" href="#" onclick="openEditEntry(<?php echo (int)$item['id']; ?>);return false;">
                                <i class="bi bi-pencil" style="font-size:.7rem;"></i> Edit
                            </a>
                            <a class="btn-entry-delete"
                               href="expenses.php?date=<?php echo urlencode($selectedDate); ?>&delete=<?php echo (int)$item['id']; ?>"
                               onclick="return confirm('Delete this entry?')">
                                <i class="bi bi-trash3" style="font-size:.7rem;"></i> Delete
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <p>No expenses or income logged for this date.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Desktop View -->
        <div class="d-none d-md-block overflow-auto">
            <table class="table table-borderless mb-0" style="font-size:.85rem;">
                <thead>
                    <tr style="border-bottom:1px solid var(--border);">
                        <th class="px-4 py-3 fw-semibold" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);">Title</th>
                        <th class="px-4 py-3 fw-semibold" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);">Type</th>
                        <th class="px-4 py-3 fw-semibold" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);">Date</th>
                        <th class="px-4 py-3 fw-semibold text-end" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);">Amount</th>
                        <th class="px-4 py-3 fw-semibold text-end" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);width:120px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dateItems as $item):
                        $isIncome = $item['type'] === 'income';
                        $amountUsd = (float)$item['amount'];
                        $amountKhr = $amountUsd * $khrRate;
                        $sign = $isIncome ? '+' : '-';
                        $amountColor = $isIncome ? '#00B894' : '#FF6B6B';
                        $badgeClass = $isIncome ? 'type-badge-income' : 'type-badge-expense';
                        $badgeLabel = $isIncome ? 'Income · ចំណូល' : 'Expense · ចំណាយ';
                    ?>
                        <tr style="border-bottom:1px solid var(--border);">
                            <td class="px-4 py-3 fw-medium align-middle" style="color:var(--ink);"><?php echo htmlspecialchars($item['title']); ?></td>
                            <td class="px-4 py-3 align-middle"><span class="<?php echo $badgeClass; ?>"><?php echo $badgeLabel; ?></span></td>
                            <td class="px-4 py-3 align-middle" style="color:var(--ink-soft);font-size:.8rem;"><?php echo htmlspecialchars($item['expense_date']); ?></td>
                            <td class="px-4 py-3 text-end align-middle">
                                <div class="fw-semibold" style="color:<?php echo $amountColor; ?>;"><?php echo $sign; ?>$<?php echo number_format($amountUsd, 2); ?></div>
                                <div style="font-size:.72rem;color:var(--ink-soft);"><?php echo $sign; ?><?php echo number_format($amountKhr); ?> ៛</div>
                            </td>
                            <td class="px-4 py-3 text-end align-middle">
                                <div class="d-inline-flex align-items-center gap-2">
                                    <a class="btn-entry-edit" href="#" onclick="openEditEntry(<?php echo (int)$item['id']; ?>);return false;">
                                        <i class="bi bi-pencil" style="font-size:.7rem;"></i> Edit
                                    </a>
                                    <a class="btn-entry-delete"
                                       href="expenses.php?date=<?php echo urlencode($selectedDate); ?>&delete=<?php echo (int)$item['id']; ?>"
                                       onclick="return confirm('Delete this entry?')">
                                        <i class="bi bi-trash3" style="font-size:.7rem;"></i> Delete
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$dateItems): ?>
                        <tr>
                            <td colspan="5" class="text-center py-5">
                                <div class="empty-state">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                    <p>No expenses or income logged for this date.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($dateItems): ?>
            <div class="entries-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div class="d-flex flex-wrap gap-3">
                    <span class="small fw-medium" style="color:var(--ink-soft);">Expenses: <span class="fw-bold" style="color:#FF6B6B;">-$<?php echo number_format($totalUsd, 2); ?></span> <span style="color:var(--ink-soft);">(-<?php echo number_format($totalKhr); ?> ៛)</span></span>
                    <span class="small fw-medium" style="color:var(--ink-soft);">Income: <span class="fw-bold" style="color:#00B894;">+$<?php echo number_format($incomeUsd, 2); ?></span> <span style="color:var(--ink-soft);">(+<?php echo number_format($incomeKhr); ?> ៛)</span></span>
                </div>
                <div class="text-end">
                    <span class="fw-bold" style="color:<?php echo $dateNet >= 0 ? '#00B894' : '#FF6B6B'; ?>;">
                        Net: <?php echo $dateNet >= 0 ? '+' : '-'; ?>$<?php echo number_format(abs($dateNet), 2); ?>
                    </span>
                </div>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- EDIT ENTRY MODAL -->
<div id="editEntryModal" class="modal fade" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content overflow-hidden" style="border-radius:var(--radius);">
            <div class="modal-header" style="border-bottom:1px solid var(--border);padding:18px 24px;">
                <div>
                    <h5 class="modal-title fw-bold" style="font-size:1.05rem;color:var(--ink);"><i class="bi bi-pencil-square me-2" style="color:var(--c-expenses);"></i>Edit Entry</h5>
                    <p class="mb-0" style="font-size:.75rem;color:var(--ink-soft);">Update this expense or income entry.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" id="editEntryForm">
                <input type="hidden" name="update_expense" value="1">
                <input type="hidden" name="entry_id" id="entry_id" value="">
                <div class="modal-body" style="padding:24px;">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label small fw-semibold" style="color:var(--ink-soft);">Title / Item Name</label>
                            <input class="form-control" id="entry_title" name="entry_title" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold" style="color:var(--ink-soft);">Amount (USD $)</label>
                            <input class="form-control" id="entry_amount_usd" type="number" step="0.01" name="entry_amount_usd" placeholder="0.00" oninput="syncFromUsd('entry_amount_usd','entry_amount_khr')">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold" style="color:var(--ink-soft);">Amount (KHR ៛)</label>
                            <input class="form-control" id="entry_amount_khr" type="number" step="100" name="entry_amount_khr" placeholder="0" oninput="syncFromKhr('entry_amount_usd','entry_amount_khr')">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold" style="color:var(--ink-soft);">Date</label>
                            <input class="form-control" id="entry_date" type="date" name="entry_date" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold" style="color:var(--ink-soft);">Type</label>
                            <select class="form-select" id="entry_type" name="entry_type">
                                <option value="expense">Expense · ចំណាយ</option>
                                <option value="income">Income · ចំណូល</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--border);padding:14px 24px;background:var(--paper);">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal"><i class="bi bi-x-lg me-1"></i>Cancel</button>
                    <button type="submit" class="btn-accent"><i class="bi bi-check-lg me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- REPORT MODAL -->
<div id="reportModal" class="modal fade" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title fw-bold"><i class="bi bi-file-earmark-bar-graph me-2" style="color:var(--c-expenses);"></i>Expense Report Summary</h5>
                    <small style="color:var(--ink-soft);">Breakdown of historical expenses &amp; income by date</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-4">
                    <div class="col-sm-6">
                        <div class="report-summary-card" style="background:rgba(255,107,107,.05);border-color:rgba(255,107,107,.15);">
                            <span class="small fw-bold text-uppercase" style="color:#FF6B6B;font-size:.7rem;letter-spacing:.04em;"><i class="bi bi-arrow-down-short"></i>Total Expenses</span>
                            <div class="fw-bold mt-1" style="font-size:1.3rem;color:#FF6B6B;">-$<?php echo number_format($allExpenseTotal, 2); ?></div>
                            <span class="small fw-bold text-uppercase mt-2 d-block" style="color:#00B894;font-size:.7rem;letter-spacing:.04em;"><i class="bi bi-arrow-up-short"></i>Total Income</span>
                            <div class="fw-bold mt-1" style="font-size:1.3rem;color:#00B894;">+$<?php echo number_format($allIncomeTotal, 2); ?></div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="report-summary-card" style="background:rgba(0,184,148,.05);border-color:rgba(0,184,148,.15);">
                            <span class="small fw-bold text-uppercase" style="color:var(--c-income);font-size:.7rem;letter-spacing:.04em;"><i class="bi bi-wallet2"></i> Remaining Budget</span>
                            <div class="fw-bold mt-1" style="font-size:1.3rem;color:var(--c-income);">$<?php echo number_format($remaining, 2); ?></div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold mb-0" style="font-size:.88rem;">Detailed Daily Breakdown <small class="fw-normal" style="color:var(--ink-soft);">(last 90 days)</small></h6>
                    <button type="button" onclick="downloadAllExpensesPdf()" class="btn btn-sm" style="background:linear-gradient(135deg,#FF6B6B,#FAB1A0);color:#fff;border-radius:8px;font-weight:600;font-size:.72rem;padding:5px 12px;">
                        <i class="bi bi-download me-1"></i>Download All
                    </button>
                </div>

                <?php if (!$reportByDate): ?>
                    <div class="empty-state">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        <p>No entries in the last 90 days.</p>
                    </div>
                <?php endif; ?>

                <?php foreach ($reportByDate as $groupDate => $items):
                    $dayExpense = 0.0;
                    $dayIncome = 0.0;
                    foreach ($items as $it) {
                        if ($it['type'] === 'income') {
                            $dayIncome += (float) $it['amount'];
                        } else {
                            $dayExpense += (float) $it['amount'];
                        }
                    }
                    $dayNet = $dayIncome - $dayExpense;
                ?>
                    <div class="report-day-card expense-day-card" data-report-date="<?php echo htmlspecialchars($groupDate); ?>">
                        <div class="d-flex justify-content-between align-items-center gap-2 pb-2 mb-2" style="border-bottom:1px solid var(--border);">
                            <span class="fw-bold" style="font-size:.78rem;color:var(--ink);"><?php echo htmlspecialchars(date('l, F j, Y', strtotime($groupDate))); ?></span>
                            <div class="d-flex align-items-center gap-2">
                                <span class="fw-bold" style="font-size:.78rem;color:<?php echo $dayNet >= 0 ? '#00B894' : '#FF6B6B'; ?>;">
                                    Net: <?php echo $dayNet >= 0 ? '+' : '-'; ?>$<?php echo number_format(abs($dayNet), 2); ?>
                                </span>
                                <button type="button" onclick="downloadExpenseDayPdf('<?php echo htmlspecialchars($groupDate); ?>')" class="btn btn-sm" style="background:linear-gradient(135deg,#6C5CE7,#A29BFE);color:#fff;border-radius:8px;font-weight:600;font-size:.68rem;padding:3px 10px;">
                                    <i class="bi bi-download me-1"></i>Download
                                </button>
                            </div>
                        </div>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($items as $it):
                                $isIncome = $it['type'] === 'income';
                                $sign = $isIncome ? '+' : '-';
                                $itemColor = $isIncome ? '#00B894' : 'var(--ink)';
                            ?>
                                <li class="d-flex justify-content-between py-1" style="font-size:.8rem;border-bottom:1px solid var(--border);">
                                    <span style="color:var(--ink-soft);"><?php echo htmlspecialchars($it['title']); ?> <?php echo $isIncome ? '<span style="font-size:.65rem;color:#00B894;">(ចំណូល)</span>' : ''; ?></span>
                                    <span class="fw-medium" style="color:<?php echo $itemColor; ?>;"><?php echo $sign; ?>$<?php echo number_format($it['amount'], 2); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" data-bs-dismiss="modal"><i class="bi bi-x-lg me-1"></i>Close Report</button>
            </div>
        </div>
    </div>
</div>

<script>
    const KHR_RATE = <?php echo $khrRate; ?>;
    const ALL_TIME_NET = <?php echo json_encode(number_format(abs($allNet), 2)); ?>;
    const ALL_TIME_NET_POSITIVE = <?php echo $allNet >= 0 ? 'true' : 'false'; ?>;
    // Full 90-day dataset used to build the printable report popup.
    // Built from data, not cloned DOM — the modal's cards rely on CSS
    // variables (var(--border), var(--ink)...) that only exist on THIS
    // page; a plain popup window doesn't inherit them, which is why the
    // downloaded report used to render with no borders/colors at all.
    const REPORT_DATA = <?php echo json_encode($reportByDate, JSON_UNESCAPED_UNICODE); ?>;
    const ENTRY_DATA = <?php
        $entryById = [];
        foreach ($dateItems as $e) {
            $entryById[(int) $e['id']] = [
                'id' => (int) $e['id'],
                'title' => $e['title'] ?? '',
                'amount' => (float) $e['amount'],
                'expense_date' => $e['expense_date'] ?? '',
                'type' => $e['type'] ?? 'expense',
            ];
        }
        echo json_encode($entryById, JSON_UNESCAPED_UNICODE);
    ?>;

    /* ---- Edit Entry Modal ---- */
    function openEditEntry(id) {
        var e = ENTRY_DATA[id];
        if (!e) return;
        document.getElementById('entry_id').value = e.id;
        document.getElementById('entry_title').value = e.title;
        document.getElementById('entry_amount_usd').value = e.amount;
        document.getElementById('entry_amount_khr').value = Math.round(e.amount * KHR_RATE);
        document.getElementById('entry_date').value = e.expense_date;
        document.getElementById('entry_type').value = e.type;
        new bootstrap.Modal(document.getElementById('editEntryModal')).show();
    }

    function syncFromUsd(usdId, khrId) {
        const usdInput = document.getElementById(usdId).value;
        if (usdInput !== '') {
            document.getElementById(khrId).value = Math.round(parseFloat(usdInput) * KHR_RATE);
        } else {
            document.getElementById(khrId).value = '';
        }
    }

    function syncFromKhr(usdId, khrId) {
        const khrInput = document.getElementById(khrId).value;
        if (khrInput !== '') {
            document.getElementById(usdId).value = (parseFloat(khrInput) / KHR_RATE).toFixed(2);
        } else {
            document.getElementById(usdId).value = '';
        }
    }

    function openReportModal() {
        var modal = new bootstrap.Modal(document.getElementById('reportModal'));
        modal.show();
    }

    /* ---- Form Toggles (smooth grid-template-rows collapse, no JS height math needed) ---- */
    function setupFormToggle(toggleId, wrapId, chevronId) {
        var toggle = document.getElementById(toggleId);
        var wrap = document.getElementById(wrapId);
        var chevron = document.getElementById(chevronId);
        if (!toggle || !wrap) return;
        toggle.addEventListener('click', function() {
            var willCollapse = !wrap.classList.contains('collapsed');
            wrap.classList.toggle('collapsed', willCollapse);
            if (chevron) chevron.style.transform = willCollapse ? 'rotate(-90deg)' : '';
        });
    }
    setupFormToggle('budgetFormToggle', 'budgetFormWrap', 'budgetChevron');
    setupFormToggle('expenseFormToggle', 'expenseFormWrap', 'expenseChevron');
    setupFormToggle('incomeFormToggle', 'incomeFormWrap', 'incomeChevron');

    /* ---- Printable Report Popup (fully self-contained: no shared CSS vars, no DOM cloning) ---- */

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function fmtUsd(n) {
        return '$' + Math.abs(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function fmtKhr(n) {
        return Math.round(Math.abs(n)).toLocaleString('en-US') + ' \u17DB';
    }
    function fmtDateLong(dateKey) {
        const d = new Date(dateKey + 'T00:00:00');
        if (isNaN(d)) return dateKey;
        return d.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    }

    function dayTotals(items) {
        let expense = 0, income = 0;
        items.forEach(it => {
            const amt = parseFloat(it.amount) || 0;
            if (it.type === 'income') income += amt; else expense += amt;
        });
        return { expense, income, net: income - expense };
    }

    function buildItemRowHtml(item) {
        const isIncome = item.type === 'income';
        const amt = parseFloat(item.amount) || 0;
        const sign = isIncome ? '+' : '-';
        const color = isIncome ? '#059669' : '#dc2626';
        const badgeBg = isIncome ? '#ecfdf5' : '#fef2f2';
        const badgeLabel = isIncome ? 'Income \u00B7 \u1785\u17C6\u178E\u17BC\u179B' : 'Expense \u00B7 \u1785\u17C6\u178E\u17B6\u1799';
        return `
            <div class="item-row">
                <div class="item-left">
                    <span class="item-title">${escapeHtml(item.title)}</span>
                    <span class="item-badge" style="background:${badgeBg};color:${color};">${badgeLabel}</span>
                </div>
                <div class="item-right">
                    <div class="item-amount" style="color:${color};">${sign}${fmtUsd(amt)}</div>
                    <div class="item-amount-khr">${sign}${fmtKhr(amt * KHR_RATE)}</div>
                </div>
            </div>`;
    }

    function buildDayCardHtml(dateKey, items) {
        const t = dayTotals(items);
        const netColor = t.net >= 0 ? '#059669' : '#dc2626';
        const netBg = t.net >= 0 ? '#ecfdf5' : '#fef2f2';
        const rows = items.map(buildItemRowHtml).join('');
        return `
            <div class="day-card">
                <div class="day-card-header">
                    <span class="day-card-date">${escapeHtml(fmtDateLong(dateKey))}</span>
                    <span class="day-card-net" style="background:${netBg};color:${netColor};">
                        Net: ${t.net >= 0 ? '+' : '-'}${fmtUsd(t.net)}
                    </span>
                </div>
                <div class="day-card-body">${rows}</div>
            </div>`;
    }

    function buildSummaryStripHtml(expenseTotal, incomeTotal, label) {
        const net = incomeTotal - expenseTotal;
        return `
            <div class="summary-strip">
                <div class="summary-card summary-expense">
                    <span class="summary-label">Total Expenses</span>
                    <div class="summary-value" style="color:#dc2626;">-${fmtUsd(expenseTotal)}</div>
                </div>
                <div class="summary-card summary-income">
                    <span class="summary-label">Total Income</span>
                    <div class="summary-value" style="color:#059669;">+${fmtUsd(incomeTotal)}</div>
                </div>
                <div class="summary-card summary-net">
                    <span class="summary-label">Net ${escapeHtml(label)}</span>
                    <div class="summary-value" style="color:${net >= 0 ? '#059669' : '#dc2626'};">${net >= 0 ? '+' : '-'}${fmtUsd(net)}</div>
                </div>
            </div>`;
    }

    function buildReportHtml(title, subtitle, daysHtml, summaryHtml) {
        const generated = new Date().toLocaleString('en-US', { dateStyle: 'medium', timeStyle: 'short' });
        return `
            <html>
                <head>
                    <meta charset="UTF-8">
                    <title>${escapeHtml(title)}</title>
                    <style>
                        * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                        body {
                            font-family: 'Inter', Arial, sans-serif;
                            margin: 0;
                            padding: 0;
                            background: #f5f7fa;
                            color: #1a1d2e;
                        }
                        .page { max-width: 820px; margin: 0 auto; padding: 32px 28px 60px; }
                        .toolbar {
                            display: flex;
                            justify-content: flex-end;
                            gap: 8px;
                            padding: 16px 28px 0;
                            max-width: 820px;
                            margin: 0 auto;
                        }
                        .print-btn {
                            border: none;
                            background: #2563eb;
                            color: #fff;
                            font-size: 14px;
                            font-weight: 600;
                            padding: 9px 18px;
                            border-radius: 8px;
                            cursor: pointer;
                        }
                        .print-btn:hover { background: #1d4ed8; }
                        .close-btn {
                            width: 36px; height: 36px;
                            border-radius: 999px;
                            border: 1px solid #e5e7eb;
                            background: #fff;
                            color: #374151;
                            font-size: 18px;
                            font-weight: bold;
                            line-height: 1;
                            cursor: pointer;
                        }
                        .close-btn:hover { background: #f3f4f6; }

                        .report-header { margin-bottom: 24px; padding-bottom: 20px; border-bottom: 2px solid #e8ebf2; }
                        .report-title { font-size: 24px; font-weight: 800; margin: 0 0 4px; }
                        .report-subtitle { font-size: 13px; color: #6b7190; margin: 0 0 2px; }
                        .report-generated { font-size: 11px; color: #9ca3af; }

                        .summary-strip { display: flex; gap: 12px; margin-bottom: 28px; }
                        .summary-card {
                            flex: 1;
                            background: #fff;
                            border: 1px solid #e8ebf2;
                            border-radius: 12px;
                            padding: 14px 16px;
                            border-top: 3px solid #d1d5db;
                        }
                        .summary-expense { border-top-color: #ef4444; }
                        .summary-income { border-top-color: #10b981; }
                        .summary-net { border-top-color: #6c5ce7; }
                        .summary-label { font-size: 10px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: #6b7190; }
                        .summary-value { font-size: 19px; font-weight: 800; margin-top: 4px; }

                        .section-label {
                            font-size: 12px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;
                            color: #6b7190; margin: 0 0 14px;
                        }

                        .day-card {
                            background: #fff;
                            border: 1px solid #e8ebf2;
                            border-radius: 12px;
                            margin-bottom: 14px;
                            overflow: hidden;
                            page-break-inside: avoid;
                        }
                        .day-card-header {
                            display: flex;
                            justify-content: space-between;
                            align-items: center;
                            padding: 13px 18px;
                            background: #f9fafb;
                            border-bottom: 1px solid #e8ebf2;
                        }
                        .day-card-date { font-size: 13.5px; font-weight: 700; color: #1a1d2e; }
                        .day-card-net { font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 999px; }
                        .day-card-body { padding: 4px 18px; }

                        .item-row {
                            display: flex;
                            justify-content: space-between;
                            align-items: center;
                            gap: 12px;
                            padding: 11px 0;
                            border-bottom: 1px solid #f1f3f6;
                        }
                        .item-row:last-child { border-bottom: none; }
                        .item-left { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
                        .item-title { font-size: 13.5px; font-weight: 600; color: #1a1d2e; }
                        .item-badge { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 999px; white-space: nowrap; }
                        .item-right { text-align: right; flex-shrink: 0; }
                        .item-amount { font-size: 13.5px; font-weight: 700; }
                        .item-amount-khr { font-size: 11px; color: #9ca3af; margin-top: 1px; }

                        .empty-note { text-align: center; color: #9ca3af; font-size: 13px; padding: 40px 0; }

                        @media print {
                            body { background: #fff; }
                            .toolbar { display: none; }
                            .page { padding: 0; max-width: none; }
                            .day-card { box-shadow: none; }
                        }
                    </style>
                </head>
                <body>
                    <div class="toolbar">
                        <button class="print-btn" onclick="window.print()">Print / Save PDF</button>
                        <button class="close-btn" onclick="window.close()" aria-label="Close">&times;</button>
                    </div>
                    <div class="page">
                        <div class="report-header">
                            <h1 class="report-title">${escapeHtml(title)}</h1>
                            <p class="report-subtitle">${escapeHtml(subtitle)}</p>
                            <p class="report-generated">Generated ${escapeHtml(generated)}</p>
                        </div>
                        ${summaryHtml}
                        <p class="section-label">Daily Breakdown</p>
                        ${daysHtml || '<div class="empty-note">No entries to show.</div>'}
                    </div>
                </body>
            </html>
        `;
    }

    function downloadExpenseDayPdf(dateKey) {
        const items = REPORT_DATA[dateKey] || [];
        if (!items.length) return;
        const t = dayTotals(items);
        const summaryHtml = buildSummaryStripHtml(t.expense, t.income, '(This Day)');
        const daysHtml = buildDayCardHtml(dateKey, items);
        const popup = window.open('', '_blank', 'width=900,height=700');
        popup.document.write(buildReportHtml(
            'Expense Report',
            fmtDateLong(dateKey),
            daysHtml,
            summaryHtml
        ));
        popup.document.close();
    }

    function downloadAllExpensesPdf() {
        const dateKeys = Object.keys(REPORT_DATA);
        if (!dateKeys.length) return;
        let expenseTotal = 0, incomeTotal = 0;
        let daysHtml = '';
        dateKeys.forEach(dateKey => {
            const items = REPORT_DATA[dateKey];
            const t = dayTotals(items);
            expenseTotal += t.expense;
            incomeTotal += t.income;
            daysHtml += buildDayCardHtml(dateKey, items);
        });
        const summaryHtml = buildSummaryStripHtml(expenseTotal, incomeTotal, '(Last 90 Days)');
        const popup = window.open('', '_blank', 'width=900,height=700');
        popup.document.write(buildReportHtml(
            'Full Expense Report',
            'Last 90 days \u00B7 ' + dateKeys.length + ' day(s) with activity',
            daysHtml,
            summaryHtml
        ));
        popup.document.close();
    }
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>