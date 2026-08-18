<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$currentLang = $_SESSION['lang'] ?? 'en';

$userId = (int) $_SESSION['user_id'];
$userName = $_SESSION['user_name'] ?? 'User';
$errors = [];
$success = '';

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

// --- ADD NOTE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_note'])) {
    if (!csrf_valid()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $type = ($_POST['type'] ?? '') === 'secure' ? 'secure' : 'simple';

        if ($title === '' || $content === '') {
            $errors[] = 'Title and content are required.';
        } elseif (mb_strlen($title) > 190) {
            $errors[] = 'Title must be 190 characters or fewer.';
        } elseif (mb_strlen($content) > 10000) {
            $errors[] = 'Content must be 10,000 characters or fewer.';
        } else {
            $image = upload_note_image();

            if ($image === false) {
                $errors[] = 'Invalid image. Only JPG, PNG, WEBP or GIF files up to 2MB are allowed.';
            } else {
                $stmt = $conn->prepare('INSERT INTO notes (user_id, title, content, image_data, type) VALUES (?, ?, ?, ?, ?)');
                $stmt->bind_param('issss', $userId, $title, $content, $image, $type);
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
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $type = ($_POST['type'] ?? '') === 'secure' ? 'secure' : 'simple';

        if ($title === '' || $content === '') {
            $errors[] = 'Title and content are required.';
        } elseif (mb_strlen($title) > 190) {
            $errors[] = 'Title must be 190 characters or fewer.';
        } elseif (mb_strlen($content) > 10000) {
            $errors[] = 'Content must be 10,000 characters or fewer.';
        } else {
            $newImage = upload_note_image();

            if ($newImage === false) {
                $errors[] = 'Invalid image. Only JPG, PNG, WEBP or GIF files up to 2MB are allowed.';
            } elseif (isset($_POST['remove_image'])) {
                // User chose to remove the existing image.
                $stmt = $conn->prepare('UPDATE notes SET title = ?, content = ?, type = ?, image_data = NULL, updated_at = NOW() WHERE id = ? AND user_id = ?');
                $stmt->bind_param('sssii', $title, $content, $type, $id, $userId);
                $stmt->execute();
                redirect('notes.php');
            } elseif ($newImage !== null) {
                // A new image was uploaded — replace the old one.
                $stmt = $conn->prepare('UPDATE notes SET title = ?, content = ?, type = ?, image_data = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');
                $stmt->bind_param('ssssii', $title, $content, $type, $newImage, $id, $userId);
                $stmt->execute();
                redirect('notes.php');
            } else {
                // No new image — keep the existing one.
                $stmt = $conn->prepare('UPDATE notes SET title = ?, content = ?, type = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');
                $stmt->bind_param('sssii', $title, $content, $type, $id, $userId);
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
        if (isset($_SESSION['unlocked_notes'][$id])) {
            unset($_SESSION['unlocked_notes'][$id]);
        }
        redirect('notes.php');
    }
}

// --- UNLOCK SECURE NOTE (requires account password) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlock_note'])) {
    if (!csrf_valid()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $id = (int) ($_POST['note_id'] ?? 0);
        $password = $_POST['password'] ?? '';

        if ($password === '') {
            $errors[] = 'Password is required to view this note.';
        } else {
            $pwStmt = $conn->prepare('SELECT password FROM users WHERE id = ?');
            $pwStmt->bind_param('i', $userId);
            $pwStmt->execute();
            $pwRow = $pwStmt->get_result()->fetch_assoc();

            if ($pwRow && password_verify($password, $pwRow['password'])) {
                $_SESSION['unlocked_notes'][$id] = true;
                $success = 'Note unlocked.';
                redirect('notes.php');
            } else {
                $errors[] = 'Incorrect password.';
            }
        }
    }
}

// --- LOCK A SECURE NOTE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lock_note'])) {
    if (!csrf_valid()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $id = (int) ($_POST['note_id'] ?? 0);
        if (isset($_SESSION['unlocked_notes'][$id])) {
            unset($_SESSION['unlocked_notes'][$id]);
        }
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
}

// --- FETCH ALL NOTES ---
$stmt = $conn->prepare('SELECT id, title, content, image_data, type, created_at, updated_at FROM notes WHERE user_id = ? ORDER BY updated_at DESC');
$stmt->bind_param('i', $userId);
$stmt->execute();
$notes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$simpleNotes = array_filter($notes, fn($n) => $n['type'] === 'simple');
$secureNotes = array_filter($notes, fn($n) => $n['type'] === 'secure');
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
                <?php if ($editNote): ?>
                    <input type="hidden" name="note_id" value="<?= (int) $editNote['id'] ?>">
                <?php endif; ?>
                <div class="row g-3">
                    <div class="col-12 col-md-5">
                        <label class="form-label small fw-semibold text-secondary">Title</label>
                        <input class="form-control rounded-3" name="title" placeholder="Note title" maxlength="190" required
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
                        <textarea class="form-control rounded-3" name="content" rows="4" maxlength="10000" placeholder="Write your note here..." required><?= $editNote ? htmlspecialchars($editNote['content']) : '' ?></textarea>
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
                            <img src="<?= htmlspecialchars($note['image_data']) ?>" alt="Note image" class="img-fluid rounded-3 mb-2" style="max-height:180px; object-fit:cover; width:100%;">
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
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="fw-bold mb-0"><span class="badge badge-secure rounded-pill me-2">Secure</span> Secure Notes <span class="text-muted small fw-normal">(password protected)</span></h5>
        <span class="badge bg-light text-dark border px-3 py-2 rounded-pill"><?= count($secureNotes) ?> Total</span>
    </div>
    <div class="row g-3">
        <?php foreach ($secureNotes as $note):
            $unlocked = isset($_SESSION['unlocked_notes'][(int) $note['id']]);
        ?>
            <div class="col-12 col-md-6 col-lg-4 note-item" data-title="<?= $unlocked ? htmlspecialchars(mb_strtolower($note['title'])) : '' ?>">
                <div class="card note-card secure h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h6 class="fw-bold mb-0">
                                <?php if ($unlocked): ?>
                                    <?= htmlspecialchars($note['title']) ?>
                                <?php else: ?>
                                    <span class="text-muted">Locked note</span>
                                <?php endif; ?>
                            </h6>
                            <span class="badge bg-secondary-subtle text-secondary"><?= $unlocked ? 'Unlocked' : 'Locked' ?></span>
                        </div>

                        <?php if ($unlocked): ?>
                            <?php if ($note['image_data']): ?>
                                <img src="<?= htmlspecialchars($note['image_data']) ?>" alt="Note image" class="img-fluid rounded-3 mb-2" style="max-height:180px; object-fit:cover; width:100%;">
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
                                <form method="post" class="m-0">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="lock_note" value="1">
                                    <input type="hidden" name="note_id" value="<?= (int) $note['id'] ?>">
                                    <button class="btn btn-sm btn-outline-secondary">Lock</button>
                                </form>
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
                            <div class="flex-grow-1 d-flex align-items-center">
                                <form method="post" class="w-100 mt-1">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="unlock_note" value="1">
                                    <input type="hidden" name="note_id" value="<?= (int) $note['id'] ?>">
                                    <label class="form-label small text-muted">This note is protected. Enter your account password to view it.</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control form-control-sm rounded-start-3" name="password" placeholder="Account password" required>
                                        <button class="btn btn-sm btn-primary rounded-end-3">Unlock</button>
                                    </div>
                                </form>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
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