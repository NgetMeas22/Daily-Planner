<?php
$server = 'localhost';
$user = 'root';
$pass = '';
$dbName = 'daily_planner';

$conn = new mysqli($server, $user, $pass);

if ($conn->connect_error) {
    die('Database connection failed.');
}

$conn->query("CREATE DATABASE IF NOT EXISTS `{$dbName}`");
$conn->select_db($dbName);
$conn->set_charset('utf8mb4');

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
        title VARCHAR(200) NOT NULL,
        content TEXT NOT NULL,
        type ENUM('simple','secure') NOT NULL DEFAULT 'simple',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_notes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

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
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_goals_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Add new goal fields to databases created before category and priority existed.
$hasGoalCategory = $conn->query("SHOW COLUMNS FROM goals LIKE 'category'");
if ($hasGoalCategory && $hasGoalCategory->num_rows === 0) {
    $conn->query("ALTER TABLE goals ADD COLUMN category VARCHAR(50) NOT NULL DEFAULT 'General' AFTER goal_name");
}

$hasGoalPriority = $conn->query("SHOW COLUMNS FROM goals LIKE 'priority'");
if ($hasGoalPriority && $hasGoalPriority->num_rows === 0) {
    $conn->query("ALTER TABLE goals ADD COLUMN priority VARCHAR(20) NOT NULL DEFAULT 'Medium' AFTER category");
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

// Add the type column to existing expense tables.
$hasExpenseType = $conn->query("SHOW COLUMNS FROM expenses LIKE 'type'");
if ($hasExpenseType && $hasExpenseType->num_rows === 0) {
    $conn->query("ALTER TABLE expenses ADD COLUMN type ENUM('expense','income') NOT NULL DEFAULT 'expense' AFTER amount");
}