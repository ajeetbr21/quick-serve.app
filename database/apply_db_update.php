<?php
require_once __DIR__ . '/../config/database.php';
$db = new Database();
$conn = $db->getConnection();

$sqls = [
    "ALTER TABLE users ADD COLUMN last_activity DATETIME DEFAULT NULL",
    "ALTER TABLE users ADD INDEX idx_last_activity (last_activity)"
];

foreach ($sqls as $sql) {
    if ($conn->query($sql)) {
        echo "Success: $sql\n";
    } else {
        echo "Error/Exists: " . $conn->error . "\n";
    }
}
?>
