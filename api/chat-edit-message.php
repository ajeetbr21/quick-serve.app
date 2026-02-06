<?php
/**
 * Edit Message API
 * Updates message text and marks as edited
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

$data = json_decode(file_get_contents('php://input'), true);
$message_id = $data['message_id'] ?? null;
$new_text = $data['message_text'] ?? null;
$user_id = $auth->getCurrentUser()['id'];

if (!$message_id || !isset($new_text)) {
    echo json_encode(['success' => false, 'message' => 'ID and text required']);
    exit;
}

$new_text = trim($new_text);
if ($new_text === '') {
    echo json_encode(['success' => false, 'message' => 'Message cannot be empty']);
    exit;
}

$db = new Database();
$conn = $db->getConnection();

// Verify ownership and update
// Only text messages can be edited (not audio/location/deleted)
$sql = "UPDATE messages 
        SET message_text = ?, is_edited = 1, updated_at = NOW() 
        WHERE id = ? AND sender_id = ? AND is_deleted = 0 AND message_type = 'text'";

$stmt = $conn->prepare($sql);
$stmt->bind_param("sii", $new_text, $message_id, $user_id);

if ($stmt->execute() && $stmt->affected_rows > 0) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to edit or permission denied']);
}
?>
