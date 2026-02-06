<?php
/**
 * Upload Audio API
 * Handles voice note uploads
 */

require_once '../config/auth.php';
require_once '../config/database.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}

if (!isset($_FILES['audio'])) {
    echo json_encode(['success' => false, 'message' => 'No audio file received']);
    exit;
}

$file = $_FILES['audio'];
$upload_dir = '../uploads/audio/';

if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Generate unique filename
$filename = uniqid('voice_') . '.webm';
$target_path = $upload_dir . $filename;

if (move_uploaded_file($file['tmp_name'], $target_path)) {
    // Return the relative path for storing in DB
    echo json_encode([
        'success' => true, 
        'file_url' => 'uploads/audio/' . $filename
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Upload failed']);
}
?>
