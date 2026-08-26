<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$currentLang = $_SESSION['lang'] ?? 'en';

$userName = $_SESSION['user_name'] ?? 'User';
$userId = (int) $_SESSION['user_id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_subject'])) {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($name === '') {
        $errors[] = 'Subject name is required.';
    } else {
        $stmt = $conn->prepare('INSERT INTO subjects (user_id, name, description) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $userId, $name, $description);
        $stmt->execute();
    }
}

if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $stmt = $conn->prepare('DELETE FROM subjects WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $id, $userId);
    $stmt->execute();
    redirect('subjects.php');
}

$stmt = $conn->prepare('SELECT id, name, description, created_at FROM subjects WHERE user_id = ? ORDER BY id DESC');
$stmt->bind_param('i', $userId);
$stmt->execute();
$subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subjects</title>
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
            --accent: #6C5CE7;
            --accent-light: #6C5CE718;
            --radius: 16px;
            --radius-sm: 10px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,.04);
            --shadow-md: 0 4px 16px rgba(0,0,0,.06);
            --shadow-lg: 0 12px 40px rgba(0,0,0,.08);
            --transition: .22s cubic-bezier(.4,0,.2,1);
        }
        html, body { background: var(--paper); }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; color: var(--ink); -webkit-font-smoothing: antialiased; }
        html[lang="kh"] body { font-family: 'Noto Sans Khmer', 'Inter', sans-serif; }

        @keyframes fadeSlideUp { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes scaleIn { from { opacity: 0; transform: scale(.96); } to { opacity: 1; transform: scale(1); } }
        .anim-up { animation: fadeSlideUp .4s cubic-bezier(.22,1,.36,1) both; }
        .anim-1 { animation-delay: .05s; }
        .anim-2 { animation-delay: .1s; }
        .anim-3 { animation-delay: .15s; }
        .anim-4 { animation-delay: .2s; }

        /* Hero */
        .subjects-hero {
            background: linear-gradient(135deg, #2D1B69 0%, #6C5CE7 50%, #A29BFE 100%);
            border-radius: var(--radius);
            padding: 32px 36px;
            color: #fff;
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
        }
        .subjects-hero::after {
            content: "";
            position: absolute;
            top: -60px; right: -40px;
            width: 220px; height: 220px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255,255,255,.15) 0%, transparent 70%);
        }

        /* Collapsible Form */
        .add-form-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            transition: box-shadow var(--transition);
        }
        .add-form-card:hover { box-shadow: var(--shadow-md); }
        .add-form-toggle {
            padding: 18px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            user-select: none;
        }
        .add-form-toggle h6 { margin: 0; font-weight: 700; font-size: .9rem; display: flex; align-items: center; gap: 8px; }
        .add-form-toggle .chevron { transition: transform .25s ease; color: var(--ink-soft); }
        .add-form-toggle.open .chevron { transform: rotate(180deg); }
        .add-form-body { padding: 0 24px 24px; display: none; }
        .add-form-body.show { display: block; animation: fadeSlideUp .3s ease both; }

        .form-control, .form-select {
            border-color: var(--border);
            font-size: .85rem;
            border-radius: var(--radius-sm);
            padding: .6rem .9rem;
            transition: var(--transition);
        }
        .form-control:focus, .form-select:focus {
            box-shadow: 0 0 0 3px var(--accent-light);
            border-color: var(--accent);
        }
        .btn-accent {
            background: linear-gradient(135deg, #6C5CE7, #A29BFE);
            border: none;
            border-radius: var(--radius-sm);
            padding: .6rem 1.5rem;
            font-weight: 600;
            font-size: .85rem;
            color: #fff;
            transition: var(--transition);
        }
        .btn-accent:hover { filter: brightness(1.08); transform: translateY(-1px); color: #fff; }

        /* Subject Grid Cards */
        .subject-grid-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px;
            position: relative;
            overflow: hidden;
            transition: transform var(--transition), box-shadow var(--transition);
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        .subject-grid-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }
        .subject-grid-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; height: 3px;
        }
        .subject-grid-card:nth-child(6n+1)::before { background: linear-gradient(90deg, #6C5CE7, #A29BFE); }
        .subject-grid-card:nth-child(6n+2)::before { background: linear-gradient(90deg, #0984E3, #74B9FF); }
        .subject-grid-card:nth-child(6n+3)::before { background: linear-gradient(90deg, #00B894, #55EFC4); }
        .subject-grid-card:nth-child(6n+4)::before { background: linear-gradient(90deg, #E17055, #FAB1A0); }
        .subject-grid-card:nth-child(6n+5)::before { background: linear-gradient(90deg, #FF6B6B, #FAB1A0); }
        .subject-grid-card:nth-child(6n+6)::before { background: linear-gradient(90deg, #FDCB6E, #FFEAA7); }

        .subject-icon {
            width: 44px; height: 44px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 1.1rem;
            flex-shrink: 0;
            transition: transform var(--transition);
        }
        .subject-grid-card:hover .subject-icon { transform: scale(1.05); }
        .subject-grid-card:nth-child(6n+1) .subject-icon { background: #6C5CE714; color: #6C5CE7; }
        .subject-grid-card:nth-child(6n+2) .subject-icon { background: #0984E314; color: #0984E3; }
        .subject-grid-card:nth-child(6n+3) .subject-icon { background: #00B89414; color: #00B894; }
        .subject-grid-card:nth-child(6n+4) .subject-icon { background: #E1705514; color: #E17055; }
        .subject-grid-card:nth-child(6n+5) .subject-icon { background: #FF6B6B14; color: #FF6B6B; }
        .subject-grid-card:nth-child(6n+6) .subject-icon { background: #FDCB6E22; color: #E17055; }

        .subject-name { font-weight: 700; font-size: .95rem; margin-bottom: 4px; color: var(--ink); }
        .subject-desc { font-size: .8rem; color: var(--ink-soft); line-height: 1.45; flex-grow: 1; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .subject-meta { font-size: .7rem; color: var(--ink-soft); margin-top: 12px; display: flex; align-items: center; justify-content: space-between; }

        .btn-card-del {
            padding: 6px 12px;
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
        .btn-card-del:hover { border-color: #FF6B6B; color: #FF6B6B; background: #FF6B6B08; }

        /* Stats bar */
        .stats-bar {
            display: flex;
            gap: 16px;
            align-items: center;
        }
        .stats-chip {
            background: rgba(255,255,255,.15);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,.2);
            border-radius: 999px;
            padding: 6px 16px;
            font-size: .78rem;
            font-weight: 600;
            color: #fff;
        }

        /* Empty state */
        .empty-state { text-align: center; padding: 56px 16px; color: var(--ink-soft); }
        .empty-state svg { width: 56px; height: 56px; opacity: .2; margin-bottom: 14px; }
        .empty-state p { font-size: .88rem; margin: 0; }

        @media (max-width: 767.98px) {
            .subjects-hero { padding: 22px 20px; }
            .subject-grid-card { padding: 16px; }
        }
    </style>
</head>
<body data-theme="<?php echo htmlspecialchars(current_theme()); ?>">
<?php $activePage = 'subjects'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="container py-4" style="max-width: 1200px;">

    <!-- Hero -->
    <div class="subjects-hero mb-4 d-flex align-items-center justify-content-between flex-wrap gap-3 anim-up">
        <div>
            <h2 class="h3 fw-bold mb-1">Subjects</h2>
            <p class="mb-0 small" style="color:rgba(255,255,255,.7);">Manage your study subjects and courses.</p>
        </div>
        <div class="stats-bar">
            <span class="stats-chip"><?= count($subjects) ?> Total</span>
            <span class="stats-chip"><?= count(array_filter($subjects, fn($s) => !empty($s['description']))) ?> Described</span>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2 anim-up anim-1"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
    <?php endif; ?>

    <!-- Add Subject Form -->
    <div class="add-form-card mb-4 anim-up anim-1">
        <div class="add-form-toggle" id="formToggle">
            <h6>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                Add New Subject
            </h6>
            <svg class="chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 9l6 6 6-6"/></svg>
        </div>
        <div class="add-form-body" id="formBody">
            <form method="post">
                <input type="hidden" name="add_subject" value="1">
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label small fw-semibold" style="color:var(--ink-soft);">Subject Name</label>
                        <input class="form-control" name="name" placeholder="e.g. Mathematics" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-semibold" style="color:var(--ink-soft);">Description</label>
                        <input class="form-control" name="description" placeholder="Optional brief description">
                    </div>
                    <div class="col-12 col-md-2 d-grid align-self-end">
                        <button class="btn-accent w-100" type="submit">
                            <i class="bi bi-plus-lg me-1"></i> Add
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Subject Grid -->
    <?php if ($subjects): ?>
        <div class="row g-3">
            <?php foreach ($subjects as $i => $subject): ?>
                <div class="col-12 col-sm-6 col-lg-4 anim-up anim-<?= min(4, ($i % 4) + 1) ?>">
                    <div class="subject-grid-card">
                        <div class="d-flex align-items-start gap-3 mb-3">
                            <div class="subject-icon"><?= strtoupper(mb_substr($subject['name'], 0, 1)) ?></div>
                            <div class="min-w-0 flex-grow-1">
                                <div class="subject-name"><?= htmlspecialchars($subject['name']) ?></div>
                                <?php if (!empty($subject['description'])): ?>
                                    <div class="subject-desc"><?= htmlspecialchars($subject['description']) ?></div>
                                <?php else: ?>
                                    <div class="subject-desc" style="font-style:italic;opacity:.6;">No description</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="subject-meta">
                            <span>
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-1px;"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                                <?= date('M d, Y', strtotime($subject['created_at'])) ?>
                            </span>
                            <a class="btn-card-del" href="subjects.php?delete=<?= (int)$subject['id']; ?>" onclick="return confirm('Delete this subject?')">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                Delete
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state anim-up anim-2">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
            <p>No subjects added yet. Create your first subject above!</p>
        </div>
    <?php endif; ?>
</div>

<script>
document.getElementById('formToggle').addEventListener('click', function() {
    var body = document.getElementById('formBody');
    var isOpen = body.classList.contains('show');
    body.classList.toggle('show', !isOpen);
    this.classList.toggle('open', !isOpen);
    if (!isOpen) {
        var first = body.querySelector('input:not([type=hidden])');
        if (first) setTimeout(function() { first.focus(); }, 150);
    }
});
</script>
</body>
</html>
