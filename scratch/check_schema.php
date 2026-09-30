<?php
require __DIR__ . '/../config/db.php';
$res = $conn->query("DESCRIBE settings");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        echo $r['Field'] . ' (' . $r['Type'] . ")\n";
    }
} else {
    echo "Error: " . $conn->error . "\n";
}
