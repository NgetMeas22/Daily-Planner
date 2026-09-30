<?php
require __DIR__ . '/../config/db.php';

// Safe migration for settings table
$conn->query("ALTER TABLE settings MODIFY theme_mode VARCHAR(20) NOT NULL DEFAULT 'dark'");

// Check if dark_bg_color column exists
$check = $conn->query("SHOW COLUMNS FROM settings LIKE 'dark_bg_color'");
if ($check->num_rows === 0) {
    $conn->query("ALTER TABLE settings ADD COLUMN dark_bg_color VARCHAR(20) NOT NULL DEFAULT '#101010'");
    echo "Added dark_bg_color\n";
}

// Check if dark_fg_color column exists
$check2 = $conn->query("SHOW COLUMNS FROM settings LIKE 'dark_fg_color'");
if ($check2->num_rows === 0) {
    $conn->query("ALTER TABLE settings ADD COLUMN dark_fg_color VARCHAR(20) NOT NULL DEFAULT '#cccccc'");
    echo "Added dark_fg_color\n";
}

echo "Settings table updated successfully.\n";
