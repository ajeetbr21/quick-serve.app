<?php
require_once '../config/database.php';
$db = new Database();
$conn = $db->getConnection();

echo "Starting Chat V2 Database Update...\n";

// 1. Add deleted/edited flags to messages
$sql = "ALTER TABLE messages 
        ADD COLUMN is_deleted TINYINT(1) DEFAULT 0,
        ADD COLUMN is_edited TINYINT(1) DEFAULT 0,
        ADD COLUMN original_text TEXT NULL";

if ($conn->query($sql)) {
    echo "Success: Added columns to messages table.\n";
} else {
    echo "Notice: " . $conn->error . "\n";
}

// 2. Add deleted flags to conversations
$sql2 = "ALTER TABLE conversations 
         ADD COLUMN deleted_by_customer TINYINT(1) DEFAULT 0,
         ADD COLUMN deleted_by_provider TINYINT(1) DEFAULT 0";

if ($conn->query($sql2)) {
    echo "Success: Added columns to conversations table.\n";
} else {
    echo "Notice: " . $conn->error . "\n";
}

echo "Database update complete.\n";
?>
