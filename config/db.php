<?php
date_default_timezone_set('Asia/Phnom_Penh');
$server = 'localhost';
$user = 'root';
$pass = '';
$dbName = 'daily_planner';

// Use a persistent connection ("p:") so PHP can reuse an existing MySQL
// connection across requests instead of paying the connect handshake cost on
// every page load. This is a big win on shared hosts (e.g. InfinityFree) where
// connection setup is comparatively slow. The rest of the app keeps using the
// mysqli API, so nothing else needs to change.
$persistentServer = 'p:' . $server;
$conn = new mysqli($persistentServer, $user, $pass);

if ($conn->connect_error) {
    die('Database connection failed.');
}

$conn->query("CREATE DATABASE IF NOT EXISTS `{$dbName}`");
$conn->select_db($dbName);
$conn->set_charset('utf8mb4');

const SCHEMA_VERSION = '6';

$conn->query("CREATE TABLE IF NOT EXISTS meta (
    k VARCHAR(50) PRIMARY KEY,
    v VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$schemaVersion = null;
if ($res = $conn->query("SELECT v FROM meta WHERE k = 'schema_version'")) {
    $row = $res->fetch_assoc();
    $schemaVersion = $row['v'] ?? null;
}

if ($schemaVersion !== SCHEMA_VERSION) {

    $conn->query("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fullname VARCHAR(100) NOT NULL,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            avatar VARCHAR(255) DEFAULT NULL,
            monthly_budget DECIMAL(10,2) NOT NULL DEFAULT 150.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Add the profile avatar column to existing user tables.
    $hasAvatar = $conn->query("SHOW COLUMNS FROM users LIKE 'avatar'");
    if ($hasAvatar && $hasAvatar->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN avatar VARCHAR(255) DEFAULT NULL AFTER password");
    }

    // Add the personal monthly budget to existing user tables.
    $hasMonthlyBudget = $conn->query("SHOW COLUMNS FROM users LIKE 'monthly_budget'");
    if ($hasMonthlyBudget && $hasMonthlyBudget->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN monthly_budget DECIMAL(10,2) NOT NULL DEFAULT 150.00 AFTER avatar");
    }

    // Store the profile picture itself (base64 data URI) inside the database.
    $hasAvatarData = $conn->query("SHOW COLUMNS FROM users LIKE 'avatar_data'");
    if ($hasAvatarData && $hasAvatarData->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN avatar_data MEDIUMTEXT NULL AFTER avatar");
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title TEXT NOT NULL,
            content TEXT NOT NULL,
            type ENUM('simple','secure') NOT NULL DEFAULT 'simple',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_notes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Store an optional note image (base64 data URI) inside the database.
    $hasNoteTitle = $conn->query("SHOW COLUMNS FROM notes LIKE 'title'");
    if ($hasNoteTitle && $hasNoteTitle->num_rows > 0) {
        $titleColumn = $hasNoteTitle->fetch_assoc();
        $titleType = strtolower($titleColumn['Type'] ?? '');
        if (strpos($titleType, 'text') === false) {
            $conn->query("ALTER TABLE notes MODIFY COLUMN title TEXT NOT NULL");
        }
    }

    $hasNoteImage = $conn->query("SHOW COLUMNS FROM notes LIKE 'image_data'");
    if ($hasNoteImage && $hasNoteImage->num_rows === 0) {
        $conn->query("ALTER TABLE notes ADD COLUMN image_data MEDIUMTEXT NULL AFTER content");
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            deep_work TINYINT(1) NOT NULL DEFAULT 0,
            daily_reminders TINYINT(1) NOT NULL DEFAULT 1,
            focus_duration INT NOT NULL DEFAULT 45,
            theme_mode ENUM('light','dark') NOT NULL DEFAULT 'light',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $hasThemeMode = $conn->query("SHOW COLUMNS FROM settings LIKE 'theme_mode'");
    if ($hasThemeMode && $hasThemeMode->num_rows === 0) {
        $conn->query("ALTER TABLE settings ADD COLUMN theme_mode ENUM('light','dark') NOT NULL DEFAULT 'light' AFTER focus_duration");
        $conn->query("UPDATE settings SET theme_mode = 'light' WHERE theme_mode IS NULL OR theme_mode = ''");
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS subjects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_subjects_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Make subjects belong to an account in databases created with the old shared table.
    $hasSubjectUser = $conn->query("SHOW COLUMNS FROM subjects LIKE 'user_id'");
    if ($hasSubjectUser && $hasSubjectUser->num_rows === 0) {
        $conn->query("ALTER TABLE subjects ADD COLUMN user_id INT NULL AFTER id");
        $conn->query("UPDATE subjects SET user_id = (SELECT id FROM users ORDER BY id ASC LIMIT 1) WHERE user_id IS NULL");
        $conn->query("ALTER TABLE subjects ADD INDEX idx_subjects_user_id (user_id)");
        $conn->query("ALTER TABLE subjects ADD CONSTRAINT fk_subjects_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE");
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS planner (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            subject_id INT NOT NULL,
            study_date DATE NOT NULL,
            day_name ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            description TEXT,
            topic VARCHAR(255) NOT NULL,
            goal TEXT,
            result TEXT,
            progress INT DEFAULT 0,
            status ENUM('Pending','In Progress','Completed') DEFAULT 'Pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_planner_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_planner_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $hasPlannerDescription = $conn->query("SHOW COLUMNS FROM planner LIKE 'description'");
    if ($hasPlannerDescription && $hasPlannerDescription->num_rows === 0) {
        $conn->query("ALTER TABLE planner ADD COLUMN description TEXT NULL AFTER end_time");
    }

    // Speed up the planner's per-day / history queries (common filter is user_id + study_date).
    $hasPlannerDateIdx = $conn->query("SHOW INDEX FROM planner WHERE Key_name = 'idx_planner_user_date'");
    if ($hasPlannerDateIdx && $hasPlannerDateIdx->num_rows === 0) {
        $conn->query("ALTER TABLE planner ADD INDEX idx_planner_user_date (user_id, study_date)");
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS goals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            goal_name VARCHAR(200) NOT NULL,
            category VARCHAR(50) NOT NULL DEFAULT 'General',
            priority VARCHAR(20) NOT NULL DEFAULT 'Medium',
            target_hours INT NOT NULL,
            completed_hours INT DEFAULT 0,
            progress INT DEFAULT 0,
            deadline DATE,
            status ENUM('In Progress','Completed') DEFAULT 'In Progress',
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_goals_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $hasGoalsUserStatusDeadlineIdx = $conn->query("SHOW INDEX FROM goals WHERE Key_name = 'idx_goals_user_status_deadline'");
    if ($hasGoalsUserStatusDeadlineIdx && $hasGoalsUserStatusDeadlineIdx->num_rows === 0) {
        $conn->query("ALTER TABLE goals ADD INDEX idx_goals_user_status_deadline (user_id, status, deadline, id)");
    }

    // Add new goal fields to databases created before category and priority existed.
    $hasGoalCategory = $conn->query("SHOW COLUMNS FROM goals LIKE 'category'");
    if ($hasGoalCategory && $hasGoalCategory->num_rows === 0) {
        $conn->query("ALTER TABLE goals ADD COLUMN category VARCHAR(50) NOT NULL DEFAULT 'General' AFTER goal_name");
    }

    $hasGoalPriority = $conn->query("SHOW COLUMNS FROM goals LIKE 'priority'");
    if ($hasGoalPriority && $hasGoalPriority->num_rows === 0) {
        $conn->query("ALTER TABLE goals ADD COLUMN priority VARCHAR(20) NOT NULL DEFAULT 'Medium' AFTER category");
    }

    // Free-text notes for a goal (nullable — users may leave it blank).
    $hasGoalNotes = $conn->query("SHOW COLUMNS FROM goals LIKE 'notes'");
    if ($hasGoalNotes && $hasGoalNotes->num_rows === 0) {
        $conn->query("ALTER TABLE goals ADD COLUMN notes TEXT NULL AFTER status");
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS expenses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(100) NOT NULL,
            category VARCHAR(100),
            amount DECIMAL(10,2) NOT NULL,
            type ENUM('expense','income') NOT NULL DEFAULT 'expense',
            expense_date DATE NOT NULL,
            note TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_expenses_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $hasNotesUserUpdatedIdx = $conn->query("SHOW INDEX FROM notes WHERE Key_name = 'idx_notes_user_updated'");
    if ($hasNotesUserUpdatedIdx && $hasNotesUserUpdatedIdx->num_rows === 0) {
        $conn->query("ALTER TABLE notes ADD INDEX idx_notes_user_updated (user_id, updated_at)");
    }

    // Add the type column to existing expense tables.
    $hasExpenseType = $conn->query("SHOW COLUMNS FROM expenses LIKE 'type'");
    if ($hasExpenseType && $hasExpenseType->num_rows === 0) {
        $conn->query("ALTER TABLE expenses ADD COLUMN type ENUM('expense','income') NOT NULL DEFAULT 'expense' AFTER amount");
    }

    // Record the version so future loads skip all the checks above.
    $metaKey = 'schema_version';
    $metaVal = SCHEMA_VERSION;
    $stmt = $conn->prepare("INSERT INTO meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = ?");
    $stmt->bind_param('sss', $metaKey, $metaVal, $metaVal);
    $stmt->execute();
    $stmt->close();
}
