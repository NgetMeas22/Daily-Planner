<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$currentLang = $_SESSION['lang'] ?? 'en';

$userId = (int) $_SESSION['user_id'];
$userName = $_SESSION['user_name'] ?? 'User';
$errors = [];
$success = '';

// How long an unlock lasts, in seconds.
const SECURE_UNLOCK_SECONDS = 180; // 3 minutes
const NOTE_ENCRYPTION_CIPHER = 'aes-256-cbc';
const NOTE_ENCRYPTION_SECRET = 'daily_planner_notes_v1';

// --- CSRF TOKEN ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function csrf_valid(): bool {
    return isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

function format_note_date(?string $datetime): string {
    if (!$datetime) return '';
    $ts = strtotime($datetime);
    if ($ts === false) return '';
    return date('M j, Y \a\t g:i A', $ts);
}

// Process the optional note image upload.
// Returns: string data-URI on success, null when no file sent, false when the file is invalid.
function upload_note_image()
{
    if (!isset($_FILES['note_image']) || $_FILES['note_image']['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $file = $_FILES['note_image'];
    if ($file['size'] <= 0 || $file['size'] > 2 * 1024 * 1024) {
        return false;
    }
    $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) {
        return false;
    }
    return 'data:' . $allowed[$ext] . ';base64,' . base64_encode(file_get_contents($file['tmp_name']));
}

function note_encryption_key(): string
{
    return hash('sha256', NOTE_ENCRYPTION_SECRET, true);
}

function encrypt_note_value(string $value): string
{
    $ivLength = openssl_cipher_iv_length(NOTE_ENCRYPTION_CIPHER);
    if ($ivLength === false || $ivLength <= 0) {
        return $value;
    }

    $iv = random_bytes($ivLength);
    $ciphertext = openssl_encrypt($value, NOTE_ENCRYPTION_CIPHER, note_encryption_key(), OPENSSL_RAW_DATA, $iv);
    if ($ciphertext === false) {
        return $value;
    }

    return 'enc:' . base64_encode($iv . $ciphertext);
}

function decrypt_note_value(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    if (strncmp($value, 'enc:', 4) !== 0) {
        return $value;
    }

    $payload = base64_decode(substr($value, 4), true);
    if ($payload === false) {
        return '';
    }

    $ivLength = openssl_cipher_iv_length(NOTE_ENCRYPTION_CIPHER);
    if ($ivLength === false || $ivLength <= 0 || strlen($payload) <= $ivLength) {
        return '';
    }

    $iv = substr($payload, 0, $ivLength);
    $ciphertext = substr($payload, $ivLength);
    $plaintext = openssl_decrypt($ciphertext, NOTE_ENCRYPTION_CIPHER, note_encryption_key(), OPENSSL_RAW_DATA, $iv);

    return $plaintext === false ? '' : $plaintext;
}

function posted_note_text(string $primaryKey, string $fallbackKey = ''): string
{
    $value = trim((string) ($_POST[$primaryKey] ?? ''));
    if ($value === '' && $fallbackKey !== '') {
        $value = trim((string) ($_POST[$fallbackKey] ?? ''));
    }
    return $value;
}

// --- ADD NOTE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_note'])) {
    if (!csrf_valid()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $title = posted_note_text('note_title', 'title');
        $content = posted_note_text('note_content', 'content');
        $type = ($_POST['type'] ?? '') === 'secure' ? 'secure' : 'simple';

        if ($title === '' || $content === '') {
            $errors[] = 'Title and content are required.';
        } elseif (mb_strlen($title) > 190) {
            $errors[] = 'Title must be 190 characters or fewer.';
        } elseif (mb_strlen($content) > 10000) {
            $errors[] = 'Content must be 10,000 characters or fewer.';
        } else {
            $encryptedTitle = encrypt_note_value($title);
            $encryptedContent = encrypt_note_value($content);
            $image = upload_note_image();

            if ($image === false) {
                $errors[] = 'Invalid image. Only JPG, PNG, WEBP or GIF files up to 2MB are allowed.';
            } else {
                $stmt = $conn->prepare('INSERT INTO notes (user_id, title, content, image_data, type) VALUES (?, ?, ?, ?, ?)');
                $stmt->bind_param('issss', $userId, $encryptedTitle, $encryptedContent, $image, $type);
                $stmt->execute();
                redirect('notes.php');
            }
        }
    }
}

// --- EDIT NOTE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_note'])) {
    if (!csrf_valid()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $id = (int) ($_POST['note_id'] ?? 0);
        $title = posted_note_text('note_title', 'title');
        $content = posted_note_text('note_content', 'content');
        $type = ($_POST['type'] ?? '') === 'secure' ? 'secure' : 'simple';

        if ($title === '' || $content === '') {
            $errors[] = 'Title and content are required.';
        } elseif (mb_strlen($title) > 190) {
            $errors[] = 'Title must be 190 characters or fewer.';
        } elseif (mb_strlen($content) > 10000) {
            $errors[] = 'Content must be 10,000 characters or fewer.';
        } else {
            $encryptedTitle = encrypt_note_value($title);
            $encryptedContent = encrypt_note_value($content);
            $newImage = upload_note_image();

            if ($newImage === false) {
                $errors[] = 'Invalid image. Only JPG, PNG, WEBP or GIF files up to 2MB are allowed.';
            } elseif (isset($_POST['remove_image'])) {
                // User chose to remove the existing image.
                $stmt = $conn->prepare('UPDATE notes SET title = ?, content = ?, type = ?, image_data = NULL, updated_at = NOW() WHERE id = ? AND user_id = ?');
                $stmt->bind_param('sssii', $encryptedTitle, $encryptedContent, $type, $id, $userId);
                $stmt->execute();
                redirect('notes.php');
            } elseif ($newImage !== null) {
                // A new image was uploaded — replace the old one.
                $stmt = $conn->prepare('UPDATE notes SET title = ?, content = ?, type = ?, image_data = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');
                $stmt->bind_param('ssssii', $encryptedTitle, $encryptedContent, $type, $newImage, $id, $userId);
                $stmt->execute();
                redirect('notes.php');
            } else {
                // No new image — keep the existing one.
                $stmt = $conn->prepare('UPDATE notes SET title = ?, content = ?, type = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');
                $stmt->bind_param('sssii', $encryptedTitle, $encryptedContent, $type, $id, $userId);
                $stmt->execute();
                redirect('notes.php');
            }
        }
    }
}

// --- DELETE NOTE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_note'])) {
    if (!csrf_valid()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $id = (int) ($_POST['note_id'] ?? 0);
        $stmt = $conn->prepare('DELETE FROM notes WHERE id = ? AND user_id = ?');
        $stmt->bind_param('ii', $id, $userId);
        $stmt->execute();
        redirect('notes.php');
    }
}

// --- UNLOCK ALL SECURE NOTES (requires account password, entered once) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlock_secure'])) {
    if (!csrf_valid()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $password = $_POST['password'] ?? '';

        if ($password === '') {
            $errors[] = 'Password is required to view secure notes.';
        } else {
            $pwStmt = $conn->prepare('SELECT password FROM users WHERE id = ?');
            $pwStmt->bind_param('i', $userId);
            $pwStmt->execute();
            $pwRow = $pwStmt->get_result()->fetch_assoc();

            if ($pwRow && password_verify($password, $pwRow['password'])) {
                // One password entry unlocks every secure note for the whole window below.
                $_SESSION['secure_notes_unlocked_at'] = time();
                $success = 'Secure notes unlocked for 3 minutes.';
                redirect('notes.php');
            } else {
                $errors[] = 'Incorrect password.';
            }
        }
    }
}

// --- LOCK ALL SECURE NOTES ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lock_secure'])) {
    if (!csrf_valid()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        unset($_SESSION['secure_notes_unlocked_at']);
        redirect('notes.php');
    }
}

// --- EDIT LOAD ---
$editNote = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $conn->prepare('SELECT id, title, content, image_data, type FROM notes WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $editId, $userId);
    $stmt->execute();
    $editNote = $stmt->get_result()->fetch_assoc();
    if ($editNote) {
        $editNote['title'] = decrypt_note_value($editNote['title'] ?? '');
        $editNote['content'] = decrypt_note_value($editNote['content'] ?? '');
    }
}

// --- FETCH ALL NOTES ---
$stmt = $conn->prepare('SELECT id, title, content, image_data, type, created_at, updated_at FROM notes WHERE user_id = ? ORDER BY updated_at DESC');
$stmt->bind_param('i', $userId);
$stmt->execute();
$notes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

foreach ($notes as &$note) {
    $note['title'] = decrypt_note_value($note['title'] ?? '');
    $note['content'] = decrypt_note_value($note['content'] ?? '');
}
unset($note);

$simpleNotes = array_filter($notes, fn($n) => $n['type'] === 'simple');
$secureNotes = array_filter($notes, fn($n) => $n['type'] === 'secure');

// Whether the whole "Secure Notes" section is currently unlocked.
// The unlock is single-use: it only lasts for this page view and is
// consumed right after rendering (see the end of the file), so refreshing
// or navigating away re-locks the notes. SECURE_UNLOCK_SECONDS is a
// secondary safety net — the JS below also re-locks the view in place.
$secureUnlockedAt = $_SESSION['secure_notes_unlocked_at'] ?? null;
$secureUnlocked = $secureUnlockedAt !== null && (time() - $secureUnlockedAt <= SECURE_UNLOCK_SECONDS);
$secureUnlockRemaining = $secureUnlocked ? SECURE_UNLOCK_SECONDS - (time() - $secureUnlockedAt) : 0;
if ($secureUnlockedAt !== null && !$secureUnlocked) {
    // Window expired — clean up so future checks are cheap.
    unset($_SESSION['secure_notes_unlocked_at']);
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Khmer:wght@400;500;600;700;800&display=swap');
        body { background: #f8fafc; font-family: 'Inter', sans-serif; }
        html[lang="kh"] body { font-family: 'Noto Sans Khmer', 'Inter', sans-serif; }
        .note-card { border-radius: 14px; border: 1px solid #e2e8f0; box-shadow: 0 4px 12px rgba(0,0,0,.03); }
        .note-card.secure { border-left: 5px solid #0d6efd; }
        .note-card.simple { border-left: 5px solid #10b981; }
        .note-body { white-space: pre-wrap; word-break: break-word; }
        .badge-secure { background: #e0edff; color: #0d6efd; }
        .badge-simple { background: #d1fae5; color: #059669; }
        .note-date { font-size: .74rem; color: #94a3b8; }
        .note-card.d-none { display: none !important; }
        .note-img { cursor: pointer; transition: opacity .15s; }
        .note-img:hover { opacity: .85; }
        .secure-unlock-bar { border-radius: 14px; border: 1px solid #e2e8f0; background: #fff; }
    </style>
</head>
<body>
<?php $activePage = 'notes'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="container py-4 py-md-5">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h2 class="h3 fw-bold mb-1">Notes</h2>
            <p class="text-muted small mb-0">Logged in as <strong class="text-dark"><?php echo htmlspecialchars($userName); ?></strong></p>
        </div>
        <div style="min-width: 240px;">
            <input type="search" id="noteSearch" class="form-control rounded-3" placeholder="Search notes by title...">
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Add / Edit Note Form Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <h6 class="card-subtitle text-uppercase text-muted fw-bold mb-3 small">
                <?= $editNote ? 'Edit Note' : 'Add New Note' ?>
            </h6>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="<?= $editNote ? 'edit_note' : 'add_note' ?>" value="1">
                <input type="text" name="username_fake" value="" autocomplete="username" tabindex="-1" aria-hidden="true" class="d-none">
                <input type="password" name="password_fake" value="" autocomplete="new-password" tabindex="-1" aria-hidden="true" class="d-none">
                <?php if ($editNote): ?>
                    <input type="hidden" name="note_id" value="<?= (int) $editNote['id'] ?>">
                <?php endif; ?>
                <div class="row g-3">
                    <div class="col-12 col-md-5">
                        <label class="form-label small fw-semibold text-secondary">Title</label>
                        <input class="form-control rounded-3" name="note_title" placeholder="Note title" maxlength="190" required autocomplete="off" autocapitalize="off" spellcheck="false"
                               value="<?= $editNote ? htmlspecialchars($editNote['title']) : '' ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label small fw-semibold text-secondary">Type</label>
                        <select class="form-select rounded-3" name="type">
                            <option value="simple" <?= $editNote && $editNote['type'] === 'simple' ? 'selected' : '' ?>>Simple (no password)</option>
                            <option value="secure" <?= $editNote && $editNote['type'] === 'secure' ? 'selected' : '' ?>>Secure (needs password)</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold text-secondary">Content</label>
                        <textarea class="form-control rounded-3" name="note_content" rows="4" maxlength="10000" placeholder="Write your note here..." required autocomplete="off" autocapitalize="off" spellcheck="false"><?= $editNote ? htmlspecialchars($editNote['content']) : '' ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold text-secondary">Image (optional, stored in DB)</label>
                        <?php if ($editNote && $editNote['image_data']): ?>
                            <div class="d-flex align-items-center gap-3 mb-2">
                                <img src="<?= htmlspecialchars($editNote['image_data']) ?>" alt="Note image" class="rounded-3" style="width:90px;height:90px;object-fit:cover;border:1px solid #e2e8f0;">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="remove_image" value="1" id="removeNoteImage">
                                    <label class="form-check-label small" for="removeNoteImage">Remove image</label>
                                </div>
                            </div>
                        <?php endif; ?>
                        <input type="file" class="form-control" name="note_image" accept="image/*">
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-primary rounded-3 fw-medium px-4"><?= $editNote ? 'Update Note' : 'Save Note' ?></button>
                        <?php if ($editNote): ?>
                            <a class="btn btn-outline-secondary rounded-3" href="notes.php">Cancel</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- SIMPLE NOTES -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="fw-bold mb-0"><span class="badge badge-simple rounded-pill me-2">Simple</span> Simple Notes</h5>
        <span class="badge bg-light text-dark border px-3 py-2 rounded-pill"><?= count($simpleNotes) ?> Total</span>
    </div>
    <div class="row g-3 mb-5">
        <?php foreach ($simpleNotes as $note): ?>
            <div class="col-12 col-md-6 col-lg-4 note-item" data-title="<?= htmlspecialchars(mb_strtolower($note['title'])) ?>">
                <div class="card note-card simple h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h6 class="fw-bold mb-0"><?= htmlspecialchars($note['title']) ?></h6>
                        </div>
                        <?php if ($note['image_data']): ?>
                            <img src="<?= htmlspecialchars($note['image_data']) ?>" alt="Note image" class="img-fluid rounded-3 mb-2 note-img" style="max-height:180px; object-fit:cover; width:100%;" onclick="openImage(this)">
                        <?php endif; ?>
                        <p class="small text-secondary note-body flex-grow-1"><?= nl2br(htmlspecialchars($note['content'])) ?></p>
                        <p class="note-date mb-2">
                            <?php if ($note['created_at'] !== $note['updated_at']): ?>
                                Updated <?= htmlspecialchars(format_note_date($note['updated_at'])) ?>
                            <?php else: ?>
                                Created <?= htmlspecialchars(format_note_date($note['created_at'])) ?>
                            <?php endif; ?>
                        </p>
                        <div class="d-flex justify-content-end gap-2 pt-2 border-top">
                            <a class="btn btn-sm btn-outline-primary" href="notes.php?edit=<?= (int) $note['id'] ?>">Edit</a>
                            <form method="post" onsubmit="return confirm('Delete this note?');" class="m-0">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="delete_note" value="1">
                                <input type="hidden" name="note_id" value="<?= (int) $note['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger">Delete</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$simpleNotes): ?>
            <div class="col-12 text-center text-muted py-4">No simple notes yet.</div>
        <?php endif; ?>
    </div>

    <!-- SECURE NOTES -->
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <h5 class="fw-bold mb-0"><span class="badge badge-secure rounded-pill me-2">Secure</span> Secure Notes <span class="text-muted small fw-normal">(password protected)</span></h5>
        <span class="badge bg-light text-dark border px-3 py-2 rounded-pill"><?= count($secureNotes) ?> Total</span>
    </div>

    <!-- Single unlock/lock bar for the whole Secure Notes section -->
    <div class="secure-unlock-bar p-3 mb-3">
        <?php if ($secureUnlocked): ?>
            <form method="post" class="d-flex flex-wrap align-items-center gap-3 m-0">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="lock_secure" value="1">
                <span class="small text-success fw-semibold">
                    <i class="bi bi-unlock-fill me-1"></i>All secure notes unlocked
                    (<?= (int) ceil($secureUnlockRemaining / 60) ?> min left)
                </span>
                <button class="btn btn-sm btn-outline-secondary ms-auto">Lock all</button>
            </form>
        <?php else: ?>
            <form method="post" class="d-flex flex-wrap align-items-end gap-2 m-0">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="unlock_secure" value="1">
                <div class="flex-grow-1" style="min-width:220px;">
                    <label class="form-label small text-muted mb-1">Enter your account password once to view all secure notes for 3 minutes.</label>
                    <input type="password" class="form-control form-control-sm rounded-3" name="password" placeholder="Account password" required>
                </div>
                <button class="btn btn-sm btn-primary rounded-3">Unlock all</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="row g-3">
        <?php foreach ($secureNotes as $note): ?>
            <div class="col-12 col-md-6 col-lg-4 note-item <?= $secureUnlocked ? 'unlocked-card' : '' ?>" data-title="<?= $secureUnlocked ? htmlspecialchars(mb_strtolower($note['title'])) : '' ?>">
                <div class="card note-card secure h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h6 class="fw-bold mb-0">
                                <?php if ($secureUnlocked): ?>
                                    <?= htmlspecialchars($note['title']) ?>
                                <?php else: ?>
                                    <span class="text-muted">Locked note</span>
                                <?php endif; ?>
                            </h6>
                            <span class="badge bg-secondary-subtle text-secondary"><?= $secureUnlocked ? 'Unlocked' : 'Locked' ?></span>
                        </div>

                        <?php if ($secureUnlocked): ?>
                            <?php if ($note['image_data']): ?>
                                <img src="<?= htmlspecialchars($note['image_data']) ?>" alt="Note image" class="img-fluid rounded-3 mb-2 note-img" style="max-height:180px; object-fit:cover; width:100%;" onclick="openImage(this)">
                            <?php endif; ?>
                            <p class="small text-secondary note-body flex-grow-1"><?= nl2br(htmlspecialchars($note['content'])) ?></p>
                            <p class="note-date mb-2">
                                <?php if ($note['created_at'] !== $note['updated_at']): ?>
                                    Updated <?= htmlspecialchars(format_note_date($note['updated_at'])) ?>
                                <?php else: ?>
                                    Created <?= htmlspecialchars(format_note_date($note['created_at'])) ?>
                                <?php endif; ?>
                            </p>
                            <div class="d-flex justify-content-end gap-2 pt-2 border-top">
                                <a class="btn btn-sm btn-outline-primary" href="notes.php?edit=<?= (int) $note['id'] ?>">Edit</a>
                                <form method="post" onsubmit="return confirm('Delete this note?');" class="m-0">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="delete_note" value="1">
                                    <input type="hidden" name="note_id" value="<?= (int) $note['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <p class="note-date mb-2">
                                <?php if ($note['created_at'] !== $note['updated_at']): ?>
                                    Updated <?= htmlspecialchars(format_note_date($note['updated_at'])) ?>
                                <?php else: ?>
                                    Created <?= htmlspecialchars(format_note_date($note['created_at'])) ?>
                                <?php endif; ?>
                            </p>
                            <div class="flex-grow-1 d-flex align-items-center justify-content-center text-muted small py-3">
                                <i class="bi bi-lock-fill me-2"></i>Enter the account password above to view
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$secureNotes): ?>
            <div class="col-12 text-center text-muted py-4">No secure notes yet.</div>
        <?php endif; ?>
    </div>
</div>

<!-- Full-image viewer (lightbox) -->
<div class="modal fade" id="fullImageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0" style="background:transparent;">
            <div class="text-end mb-2">
                <button type="button" class="btn btn-sm btn-dark rounded-3" data-bs-dismiss="modal"><i class="bi bi-x-lg me-1"></i>Close</button>
            </div>
            <div class="modal-body p-0 rounded-4 overflow-hidden" style="background:#0f172a;">
                <img id="fullImageModalImg" src="" alt="Full image" class="w-100" style="max-height:82vh; object-fit:contain; display:block;">
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
// Auto re-lock the view once the 3-minute unlock window elapses.
<?php if ($secureUnlocked): ?>
setTimeout(function () { location.reload(); }, <?= (int) ($secureUnlockRemaining * 1000) ?>);
<?php endif; ?>
document.getElementById('noteSearch').addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    document.querySelectorAll('.note-item').forEach(function (item) {
        const title = item.getAttribute('data-title') || '';
        item.classList.toggle('d-none', q !== '' && !title.includes(q));
    });
});
</script>
</body>
</html>
<?php
// Consume the single-use unlock: the notes stay open only for this page
// view, so refreshing the page or navigating away re-locks them. A new
// password entry is required to unlock again.
unset($_SESSION['secure_notes_unlocked_at']);
?>
