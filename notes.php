<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$currentLang = $_SESSION['lang'] ?? 'en';
$userId = (int) $_SESSION['user_id'];
$userName = $_SESSION['user_name'] ?? 'User';
$errors = [];
$success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);

const SECURE_UNLOCK_SECONDS = 180;
const NOTE_ENCRYPTION_CIPHER = 'aes-256-cbc';
const NOTE_ENCRYPTION_SECRET = 'daily_planner_notes_v1';

function csrf_valid(): bool
{
    return isset($_POST['csrf_token']) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

function format_note_date(?string $datetime): string
{
    if (!$datetime) return '';
    $ts = strtotime($datetime);
    if ($ts === false) return '';
    return date('M j, Y \\a\\t g:i A', $ts);
}

function upload_note_image()
{
    if (!isset($_FILES['note_image']) || $_FILES['note_image']['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($_FILES['note_image']['error'] !== UPLOAD_ERR_OK) return false;
    $file = $_FILES['note_image'];
    if ($file['size'] <= 0 || $file['size'] > 2 * 1024 * 1024) return false;
    $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) return false;
    return 'data:' . $allowed[$ext] . ';base64,' . base64_encode(file_get_contents($file['tmp_name']));
}

function note_encryption_key(): string { return hash('sha256', NOTE_ENCRYPTION_SECRET, true); }

function note_value_is_encrypted(?string $value): bool
{
    return is_string($value) && (str_starts_with($value, 'enc:') || str_starts_with($value, 'otenc:'));
}

function encrypt_note_value(string $value): string
{
    $ivLength = openssl_cipher_iv_length(NOTE_ENCRYPTION_CIPHER);
    if ($ivLength === false || $ivLength <= 0) return $value;
    $iv = random_bytes($ivLength);
    $ciphertext = openssl_encrypt($value, NOTE_ENCRYPTION_CIPHER, note_encryption_key(), OPENSSL_RAW_DATA, $iv);
    if ($ciphertext === false) return $value;
    return 'otenc:' . base64_encode($iv . $ciphertext);
}

function decrypt_note_value(?string $value): string
{
    if ($value === null || $value === '') return '';
    if (!note_value_is_encrypted($value)) return $value;
    $prefixLength = str_starts_with($value, 'otenc:') ? 6 : 4;
    $payload = base64_decode(substr($value, $prefixLength), true);
    if ($payload === false) return '';
    $ivLength = openssl_cipher_iv_length(NOTE_ENCRYPTION_CIPHER);
    if ($ivLength === false || $ivLength <= 0 || strlen($payload) <= $ivLength) return '';
    $iv = substr($payload, 0, $ivLength);
    $ciphertext = substr($payload, $ivLength);
    $plaintext = openssl_decrypt($ciphertext, NOTE_ENCRYPTION_CIPHER, note_encryption_key(), OPENSSL_RAW_DATA, $iv);
    return $plaintext === false ? '' : $plaintext;
}

function posted_note_text(string $primaryKey, string $fallbackKey = ''): string
{
    $value = trim((string) ($_POST[$primaryKey] ?? ''));
    if ($value === '' && $fallbackKey !== '') $value = trim((string) ($_POST[$fallbackKey] ?? ''));
    return $value;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_note'])) {
    if (!csrf_valid()) { $errors[] = 'Your session expired. Please try again.'; }
    else {
        $title = posted_note_text('note_title', 'title');
        $content = posted_note_text('note_content', 'content');
        $type = ($_POST['type'] ?? '') === 'secure' ? 'secure' : 'simple';
        $image = upload_note_image();
        if ($title === '' || $content === '') $errors[] = 'Title and content are required.';
        elseif (mb_strlen($title) > 190) $errors[] = 'Title must be 190 characters or fewer.';
        elseif (mb_strlen($content) > 10000) $errors[] = 'Content must be 10,000 characters or fewer.';
        elseif ($image === false) $errors[] = 'Invalid image. Only JPG, PNG, WEBP or GIF files up to 2MB are allowed.';
        else {
            $encryptedTitle = encrypt_note_value($title);
            $encryptedContent = encrypt_note_value($content);
            $stmt = $conn->prepare('INSERT INTO notes (user_id, title, content, image_data, type) VALUES (?, ?, ?, ?, ?)');
            $stmt->bind_param('issss', $userId, $encryptedTitle, $encryptedContent, $image, $type);
            $stmt->execute(); $stmt->close();
            redirect('notes.php');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_note'])) {
    if (!csrf_valid()) { $errors[] = 'Your session expired. Please try again.'; }
    else {
        $noteId = (int) ($_POST['note_id'] ?? 0);
        $title = posted_note_text('note_title', 'title');
        $content = posted_note_text('note_content', 'content');
        $type = ($_POST['type'] ?? '') === 'secure' ? 'secure' : 'simple';
        $removeImage = isset($_POST['remove_image']);
        $newImage = upload_note_image();
        if ($title === '' || $content === '') $errors[] = 'Title and content are required.';
        elseif (mb_strlen($title) > 190) $errors[] = 'Title must be 190 characters or fewer.';
        elseif (mb_strlen($content) > 10000) $errors[] = 'Content must be 10,000 characters or fewer.';
        elseif ($newImage === false) $errors[] = 'Invalid image.';
        else {
            $stmt = $conn->prepare('SELECT image_data FROM notes WHERE id = ? AND user_id = ?');
            $stmt->bind_param('ii', $noteId, $userId);
            $stmt->execute();
            $current = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$current) $errors[] = 'Note not found.';
            else {
                $imageData = $current['image_data'];
                if ($removeImage) $imageData = null;
                elseif ($newImage !== null) $imageData = $newImage;
                $encryptedTitle = encrypt_note_value($title);
                $encryptedContent = encrypt_note_value($content);
                $stmt = $conn->prepare('UPDATE notes SET title = ?, content = ?, type = ?, image_data = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');
                $stmt->bind_param('ssssii', $encryptedTitle, $encryptedContent, $type, $imageData, $noteId, $userId);
                $stmt->execute(); $stmt->close();
                redirect('notes.php');
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_note'])) {
    if (!csrf_valid()) { $errors[] = 'Your session expired. Please try again.'; }
    else {
        $noteId = (int) ($_POST['note_id'] ?? 0);
        $stmt = $conn->prepare('DELETE FROM notes WHERE id = ? AND user_id = ?');
        $stmt->bind_param('ii', $noteId, $userId);
        $stmt->execute(); $stmt->close();
        redirect('notes.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlock_secure'])) {
    if (!csrf_valid()) { $errors[] = 'Your session expired. Please try again.'; }
    else {
        $password = $_POST['password'] ?? '';
        if ($password === '') $errors[] = 'Password is required to view secure notes.';
        else {
            $stmt = $conn->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && password_verify($password, $row['password'])) {
                $_SESSION['secure_notes_unlocked_at'] = time();
                $_SESSION['flash_success'] = 'Secure notes unlocked for 3 minutes.';
                redirect('notes.php');
            } else $errors[] = 'Incorrect password.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lock_secure'])) {
    if (!csrf_valid()) { $errors[] = 'Your session expired. Please try again.'; }
    else { unset($_SESSION['secure_notes_unlocked_at']); redirect('notes.php'); }
}

$editNote = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $conn->prepare('SELECT id, title, content, image_data, type FROM notes WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->bind_param('ii', $editId, $userId);
    $stmt->execute();
    $editNote = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($editNote) {
        $editNote['title'] = decrypt_note_value($editNote['title'] ?? '');
        $editNote['content'] = decrypt_note_value($editNote['content'] ?? '');
    }
}

$stmt = $conn->prepare('SELECT id, title, content, image_data, type, created_at, updated_at FROM notes WHERE user_id = ? ORDER BY updated_at DESC');
$stmt->bind_param('i', $userId);
$stmt->execute();
$notes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($notes as $index => $note) {
    $decryptedTitle = decrypt_note_value($note['title'] ?? '');
    $decryptedContent = decrypt_note_value($note['content'] ?? '');
    $shouldEncrypt = !note_value_is_encrypted($note['title'] ?? '') || !note_value_is_encrypted($note['content'] ?? '');
    if ($shouldEncrypt) {
        $encryptedTitle = encrypt_note_value($decryptedTitle);
        $encryptedContent = encrypt_note_value($decryptedContent);
        $stmt = $conn->prepare('UPDATE notes SET title = ?, content = ? WHERE id = ? AND user_id = ?');
        $stmt->bind_param('ssii', $encryptedTitle, $encryptedContent, $note['id'], $userId);
        $stmt->execute(); $stmt->close();
    }
    $notes[$index]['title'] = $decryptedTitle;
    $notes[$index]['content'] = $decryptedContent;
}

$simpleNotes = array_values(array_filter($notes, fn($n) => $n['type'] === 'simple'));
$secureNotes = array_values(array_filter($notes, fn($n) => $n['type'] === 'secure'));

$secureUnlockedAt = $_SESSION['secure_notes_unlocked_at'] ?? null;
$secureUnlocked = $secureUnlockedAt !== null && (time() - $secureUnlockedAt <= SECURE_UNLOCK_SECONDS);
$secureUnlockRemaining = $secureUnlocked ? SECURE_UNLOCK_SECONDS - (time() - $secureUnlockedAt) : 0;
if ($secureUnlockedAt !== null && !$secureUnlocked) unset($_SESSION['secure_notes_unlocked_at']);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('notes')); ?></title>
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
            --c-simple: #00B894;
            --c-secure: #6C5CE7;
            --c-danger: #FF6B6B;
            --radius: 16px;
            --radius-sm: 10px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,.04);
            --shadow-md: 0 4px 16px rgba(0,0,0,.06);
            --transition: .2s cubic-bezier(.4,0,.2,1);
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
        .notes-hero {
            background: linear-gradient(135deg, #1A1D2E 0%, #2D3156 100%);
            border-radius: var(--radius);
            padding: 28px 32px;
            color: #fff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 12px 40px rgba(0,0,0,.08);
        }
        .notes-hero::after {
            content: "";
            position: absolute;
            top: -50px; right: -30px;
            width: 200px; height: 200px;
            border-radius: 50%;
            background: radial-gradient(circle, var(--c-secure) 0%, transparent 70%);
            opacity: .2;
        }

        /* ---- Search & Filters ---- */
        .search-bar {
            position: relative;
        }
        .search-bar input {
            width: 100%;
            padding: 12px 16px 12px 44px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: .88rem;
            background: var(--surface);
            color: var(--ink);
            transition: var(--transition);
        }
        .search-bar input:focus {
            outline: none;
            border-color: var(--c-secure);
            box-shadow: 0 0 0 3px #6C5CE718;
        }
        .search-bar .search-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--ink-soft);
            pointer-events: none;
        }

        .filter-tabs {
            display: inline-flex;
            gap: 4px;
            background: var(--paper);
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 4px;
        }
        .filter-tab {
            padding: 7px 18px;
            border-radius: 999px;
            font-size: .78rem;
            font-weight: 600;
            color: var(--ink-soft);
            text-decoration: none;
            border: none;
            background: transparent;
            cursor: pointer;
            transition: var(--transition);
        }
        .filter-tab:hover { color: var(--ink); }
        .filter-tab.active {
            background: var(--surface);
            color: var(--ink);
            box-shadow: 0 1px 4px rgba(0,0,0,.08);
        }
        .filter-tab .tab-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 20px;
            border-radius: 999px;
            font-size: .68rem;
            font-weight: 700;
            margin-left: 6px;
            padding: 0 6px;
            background: var(--paper);
            border: 1px solid var(--border);
        }
        .filter-tab.active .tab-count { background: var(--c-secure); color: #fff; border-color: var(--c-secure); }

        /* ---- Form Card ---- */
        .note-form-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            transition: box-shadow var(--transition);
        }
        .note-form-card:hover { box-shadow: var(--shadow-md); }
        .note-form-header {
            padding: 18px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            user-select: none;
        }
        .note-form-header h6 { margin: 0; font-weight: 700; font-size: .9rem; }
        .note-form-body { padding: 24px; display: none; }
        .note-form-body.show { display: block; animation: fadeSlideUp .3s ease both; }

        .form-control, .form-select {
            border-color: var(--border);
            font-size: .85rem;
            border-radius: var(--radius-sm);
            padding: .6rem .9rem;
            transition: var(--transition);
        }
        .form-control:focus, .form-select:focus {
            box-shadow: 0 0 0 3px #6C5CE718;
            border-color: var(--c-secure);
        }
        textarea.form-control { min-height: 100px; resize: vertical; }

        .btn-primary-custom {
            background: linear-gradient(135deg, #6C5CE7, #A29BFE);
            border: none;
            border-radius: var(--radius-sm);
            padding: .6rem 1.5rem;
            font-weight: 600;
            font-size: .85rem;
            color: #fff;
            transition: var(--transition);
        }
        .btn-primary-custom:hover { filter: brightness(1.08); transform: translateY(-1px); color: #fff; }
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

        /* ---- Note Cards ---- */
        .note-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            transition: transform var(--transition), box-shadow var(--transition);
            position: relative;
        }
        .note-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .note-card::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0; width: 4px;
        }
        .note-card.type-simple::before { background: linear-gradient(180deg, #00B894, #55EFC4); }
        .note-card.type-secure::before { background: linear-gradient(180deg, #6C5CE7, #A29BFE); }

        .note-card-body { padding: 20px; }
        .note-card-title { font-weight: 700; font-size: .92rem; margin-bottom: 8px; color: var(--ink); }
        .note-card-content {
            font-size: .82rem;
            color: var(--ink-soft);
            line-height: 1.55;
            white-space: pre-wrap;
            word-break: break-word;
            max-height: 120px;
            overflow: hidden;
            position: relative;
        }
        .note-card-content.truncated::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 40px;
            background: linear-gradient(transparent, var(--surface));
        }
        .note-card-image {
            border-radius: var(--radius-sm);
            max-height: 160px;
            width: 100%;
            object-fit: cover;
            margin-bottom: 12px;
            cursor: pointer;
            transition: opacity var(--transition);
        }
        .note-card-image:hover { opacity: .88; }

        .note-card-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 20px;
            border-top: 1px solid var(--border);
            background: var(--paper);
        }
        .note-card-date { font-size: .72rem; color: var(--ink-soft); }
        .note-card-actions { display: flex; gap: 6px; }

        .btn-note-action {
            padding: 5px 12px;
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
        .btn-note-action:hover { border-color: var(--c-secure); color: var(--c-secure); }
        .btn-note-action.btn-note-delete:hover { border-color: var(--c-danger); color: var(--c-danger); background: #FF6B6B08; }

        .note-type-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: .68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .badge-simple { background: #00B89418; color: #00B894; }
        .badge-secure { background: #6C5CE718; color: #6C5CE7; }

        /* ---- Secure Notes Section ---- */
        .secure-bar {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px 20px;
            margin-bottom: 16px;
        }
        .secure-bar.unlocked {
            border-color: #00B89444;
            background: #00B89408;
        }

        /* ---- Empty State ---- */
        .empty-state {
            text-align: center;
            padding: 48px 16px;
            color: var(--ink-soft);
        }
        .empty-state svg { width: 52px; height: 52px; opacity: .25; margin-bottom: 14px; }
        .empty-state p { font-size: .85rem; margin: 0; }

        /* ---- Locked Card ---- */
        .locked-overlay {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 32px 16px;
            text-align: center;
            color: var(--ink-soft);
        }
        .locked-overlay svg { width: 36px; height: 36px; opacity: .3; margin-bottom: 10px; }
        .locked-overlay span { font-size: .8rem; }

        /* ---- Image Modal ---- */
        #fullImageModal .modal-content { background: transparent; border: none; }
        #fullImageModal .modal-body { padding: 0; border-radius: var(--radius); overflow: hidden; background: #0f172a; }

        /* ---- Responsive ---- */
        @media (max-width: 767.98px) {
            .notes-hero { padding: 20px; }
            .note-form-body { padding: 16px; }
            .note-card-body { padding: 16px; }
        }
    </style>
</head>
<body data-theme="<?php echo htmlspecialchars(current_theme()); ?>">
<?php $activePage = 'notes'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="container py-4 py-md-5" style="max-width: 1200px;">

    <!-- Hero -->
    <div class="notes-hero mb-4 d-flex align-items-center justify-content-between flex-wrap gap-3 anim-up">
        <div>
            <h2 class="h3 fw-bold mb-1">Notes</h2>
            <p class="mb-0 small" style="color:rgba(255,255,255,.65);">Logged in as <strong style="color:rgba(255,255,255,.9);"><?php echo htmlspecialchars($userName); ?></strong></p>
        </div>
        <span class="hero-date" style="background:rgba(255,255,255,.1);backdrop-filter:blur(8px);border:1px solid rgba(255,255,255,.15);border-radius:999px;padding:8px 18px;font-size:.82rem;font-weight:600;color:#fff;">
            <?= count($notes) ?> total notes
        </span>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2 anim-up anim-1"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success py-2 anim-up anim-1"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Add/Edit Note Form -->
    <div class="note-form-card mb-4 anim-up anim-1">
        <div class="note-form-header" id="noteFormToggle">
            <h6 class="d-flex align-items-center gap-2">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                <?= $editNote ? 'Edit Note' : 'Add New Note' ?>
            </h6>
            <svg id="formChevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="color:var(--ink-soft);transition:transform .2s ease;"><path d="M6 9l6 6 6-6"/></svg>
        </div>
        <div class="note-form-body <?= $editNote ? 'show' : '' ?>" id="noteFormBody">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="<?= $editNote ? 'edit_note' : 'add_note' ?>" value="1">
                <?php if ($editNote): ?>
                    <input type="hidden" name="note_id" value="<?= (int) $editNote['id'] ?>">
                <?php endif; ?>
                <div class="row g-3">
                    <div class="col-12 col-md-5">
                        <label class="form-label small fw-semibold" style="color:var(--ink-soft);">Title</label>
                        <input class="form-control" name="note_title" placeholder="Enter note title..." maxlength="190" required autocomplete="off" value="<?= $editNote ? htmlspecialchars($editNote['title']) : '' ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label small fw-semibold" style="color:var(--ink-soft);">Type</label>
                        <select class="form-select" name="type">
                            <option value="simple" <?= $editNote && $editNote['type'] === 'simple' ? 'selected' : '' ?>>Simple</option>
                            <option value="secure" <?= $editNote && $editNote['type'] === 'secure' ? 'selected' : '' ?>>Secure (password)</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold" style="color:var(--ink-soft);">Content</label>
                        <textarea class="form-control" name="note_content" rows="4" maxlength="10000" placeholder="Write your note here..." required><?= $editNote ? htmlspecialchars($editNote['content']) : '' ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold" style="color:var(--ink-soft);">Image (optional)</label>
                        <?php if ($editNote && $editNote['image_data']): ?>
                            <div class="d-flex align-items-center gap-3 mb-2">
                                <img src="<?= htmlspecialchars($editNote['image_data']) ?>" alt="" class="rounded-3" style="width:80px;height:80px;object-fit:cover;border:1px solid var(--border);">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="remove_image" value="1" id="removeImg">
                                    <label class="form-check-label small" for="removeImg">Remove image</label>
                                </div>
                            </div>
                        <?php endif; ?>
                        <input type="file" class="form-control" name="note_image" accept="image/*">
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn-primary-custom"><?= $editNote ? 'Update Note' : 'Save Note' ?></button>
                        <?php if ($editNote): ?>
                            <a class="btn-cancel" href="notes.php">Cancel</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Search + Filters -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4 anim-up anim-2">
        <div class="filter-tabs">
            <button class="filter-tab active" data-filter="all">All <span class="tab-count"><?= count($notes) ?></span></button>
            <button class="filter-tab" data-filter="simple">Simple <span class="tab-count"><?= count($simpleNotes) ?></span></button>
            <button class="filter-tab" data-filter="secure">Secure <span class="tab-count"><?= count($secureNotes) ?></span></button>
        </div>
        <div class="search-bar" style="min-width:260px;max-width:340px;width:100%;">
            <svg class="search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
            <input type="search" id="noteSearch" placeholder="Search notes by title...">
        </div>
    </div>

    <!-- Secure Notes Unlock Bar -->
    <?php if ($secureNotes): ?>
    <div class="secure-bar <?= $secureUnlocked ? 'unlocked' : '' ?> anim-up anim-3">
        <?php if ($secureUnlocked): ?>
            <form method="post" class="d-flex flex-wrap align-items-center gap-3 m-0">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="lock_secure" value="1">
                <span class="small fw-semibold" style="color:var(--c-simple);">
                    <i class="bi bi-shield-lock-fill me-1"></i>All secure notes unlocked
                    (<?= (int) ceil($secureUnlockRemaining / 60) ?> min left)
                </span>
                <button class="btn-cancel ms-auto" style="font-size:.78rem;padding:5px 14px;">Lock all</button>
            </form>
        <?php else: ?>
            <form method="post" class="d-flex flex-wrap align-items-end gap-3 m-0">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="unlock_secure" value="1">
                <div class="flex-grow-1" style="min-width:220px;">
                    <label class="form-label small mb-1" style="color:var(--ink-soft);">Enter your account password to view all secure notes for 3 minutes.</label>
                    <input type="password" class="form-control" name="password" placeholder="Account password" required style="font-size:.82rem;">
                </div>
                <button class="btn-primary-custom" style="font-size:.82rem;padding:8px 20px;">Unlock</button>
            </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Notes Grid -->
    <div class="row g-3" id="notesGrid">
        <?php
        $allNotesHtml = '';
        $noteIndex = 0;

        // Simple notes
        foreach ($simpleNotes as $note):
            $noteIndex++;
            $contentLen = mb_strlen($note['content']);
            $isLong = $contentLen > 200;
        ?>
            <div class="col-12 col-md-6 col-lg-4 note-item anim-up anim-<?= min(5, ($noteIndex % 5) + 1) ?>" data-title="<?= htmlspecialchars(mb_strtolower($note['title'])) ?>" data-type="simple">
                <div class="note-card type-simple h-100 d-flex flex-column">
                    <div class="note-card-body flex-grow-1">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h6 class="note-card-title mb-0"><?= htmlspecialchars($note['title']) ?></h6>
                            <span class="note-type-badge badge-simple">Simple</span>
                        </div>
                        <?php if ($note['image_data']): ?>
                            <img src="<?= htmlspecialchars($note['image_data']) ?>" alt="" class="note-card-image" onclick="openImage(this)">
                        <?php endif; ?>
                        <div class="note-card-content <?= $isLong ? 'truncated' : '' ?>"><?= nl2br(htmlspecialchars($note['content'])) ?></div>
                    </div>
                    <div class="note-card-meta">
                        <span class="note-card-date">
                            <?php if ($note['created_at'] !== $note['updated_at']): ?>
                                Updated <?= htmlspecialchars(format_note_date($note['updated_at'])) ?>
                            <?php else: ?>
                                Created <?= htmlspecialchars(format_note_date($note['created_at'])) ?>
                            <?php endif; ?>
                        </span>
                        <div class="note-card-actions">
                            <a class="btn-note-action" href="notes.php?edit=<?= (int) $note['id'] ?>">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                Edit
                            </a>
                            <form method="post" onsubmit="return confirm('Delete this note?');" class="m-0">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="delete_note" value="1">
                                <input type="hidden" name="note_id" value="<?= (int) $note['id'] ?>">
                                <button class="btn-note-action btn-note-delete" type="submit">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                    Del
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (!$simpleNotes): ?>
            <div class="col-12 empty-state">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                <p>No simple notes yet. Create one above!</p>
            </div>
        <?php endif; ?>

        <?php
        // Secure notes
        foreach ($secureNotes as $note):
            $noteIndex++;
        ?>
            <div class="col-12 col-md-6 col-lg-4 note-item anim-up anim-<?= min(5, ($noteIndex % 5) + 1) ?>" data-title="<?= $secureUnlocked ? htmlspecialchars(mb_strtolower($note['title'])) : '' ?>" data-type="secure">
                <div class="note-card type-secure h-100 d-flex flex-column">
                    <?php if ($secureUnlocked): ?>
                        <div class="note-card-body flex-grow-1">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h6 class="note-card-title mb-0"><?= htmlspecialchars($note['title']) ?></h6>
                                <span class="note-type-badge badge-secure">
                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                    Secure
                                </span>
                            </div>
                            <?php if ($note['image_data']): ?>
                                <img src="<?= htmlspecialchars($note['image_data']) ?>" alt="" class="note-card-image" onclick="openImage(this)">
                            <?php endif; ?>
                            <div class="note-card-content"><?= nl2br(htmlspecialchars($note['content'])) ?></div>
                        </div>
                        <div class="note-card-meta">
                            <span class="note-card-date">
                                <?php if ($note['created_at'] !== $note['updated_at']): ?>
                                    Updated <?= htmlspecialchars(format_note_date($note['updated_at'])) ?>
                                <?php else: ?>
                                    Created <?= htmlspecialchars(format_note_date($note['created_at'])) ?>
                                <?php endif; ?>
                            </span>
                            <div class="note-card-actions">
                                <a class="btn-note-action" href="notes.php?edit=<?= (int) $note['id'] ?>">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    Edit
                                </a>
                                <form method="post" onsubmit="return confirm('Delete this note?');" class="m-0">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="delete_note" value="1">
                                    <input type="hidden" name="note_id" value="<?= (int) $note['id'] ?>">
                                    <button class="btn-note-action btn-note-delete" type="submit">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                        Del
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="note-card-body flex-grow-1">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h6 class="note-card-title mb-0" style="color:var(--ink-soft);">Locked note</h6>
                                <span class="note-type-badge badge-secure">
                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                    Locked
                                </span>
                            </div>
                            <div class="locked-overlay">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                <span>Enter password above to view</span>
                            </div>
                        </div>
                        <div class="note-card-meta">
                            <span class="note-card-date">
                                <?php if ($note['created_at'] !== $note['updated_at']): ?>
                                    Updated <?= htmlspecialchars(format_note_date($note['updated_at'])) ?>
                                <?php else: ?>
                                    Created <?= htmlspecialchars(format_note_date($note['created_at'])) ?>
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (!$secureNotes && !$simpleNotes): ?>
            <div class="col-12 empty-state">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <p>No notes yet. Start by adding one above!</p>
            </div>
        <?php elseif (!$secureNotes): ?>
            <!-- no secure notes empty state handled by loop -->
        <?php endif; ?>
    </div>
</div>

<!-- Image Modal -->
<div class="modal fade" id="fullImageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0" style="background:transparent;">
            <div class="text-end mb-2">
                <button type="button" class="btn btn-sm btn-dark rounded-3" data-bs-dismiss="modal"><i class="bi bi-x-lg me-1"></i>Close</button>
            </div>
            <div class="modal-body p-0 rounded-4 overflow-hidden" style="background:#0f172a;">
                <img id="fullImageModalImg" src="" alt="Full image" class="w-100" style="max-height:82vh;object-fit:contain;display:block;">
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openImage(img) {
    document.getElementById('fullImageModalImg').src = img.src;
    new bootstrap.Modal(document.getElementById('fullImageModal')).show();
}

<?php if ($secureUnlocked): ?>
window.__notesReloadTimer = setTimeout(function () { location.reload(); }, <?= (int) ($secureUnlockRemaining * 1000) ?>);
<?php endif; ?>

/* ---- Form Toggle ---- */
document.getElementById('noteFormToggle').addEventListener('click', function() {
    var body = document.getElementById('noteFormBody');
    var chevron = document.getElementById('formChevron');
    var isOpen = body.classList.contains('show');
    body.classList.toggle('show', !isOpen);
    chevron.style.transform = isOpen ? '' : 'rotate(180deg)';
});

/* ---- Search ---- */
document.getElementById('noteSearch').addEventListener('input', function () {
    var q = this.value.trim().toLowerCase();
    document.querySelectorAll('.note-item').forEach(function (item) {
        var title = item.getAttribute('data-title') || '';
        item.style.display = (q !== '' && !title.includes(q)) ? 'none' : '';
    });
});

/* ---- Filter Tabs ---- */
document.querySelectorAll('.filter-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.filter-tab').forEach(function(t) { t.classList.remove('active'); });
        this.classList.add('active');
        var filter = this.getAttribute('data-filter');
        document.querySelectorAll('.note-item').forEach(function(item) {
            if (filter === 'all') { item.style.display = ''; return; }
            var type = item.getAttribute('data-type');
            item.style.display = (type === filter) ? '' : 'none';
        });
        // Reset search
        document.getElementById('noteSearch').value = '';
    });
});
</script>
</body>
</html>
<?php
unset($_SESSION['secure_notes_unlocked_at']);
?>
