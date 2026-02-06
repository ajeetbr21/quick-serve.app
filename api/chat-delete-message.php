<?php
/**
 * Delete Message API
 * Soft deletes a message (marks as deleted)
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
$user_id = $auth->getCurrentUser()['id'];

if (!$message_id) {
    echo json_encode(['success' => false, 'message' => 'Message ID required']);
    exit;
}

$db = new Database();
$conn = $db->getConnection();

// Verify ownership and delete
// Only the sender can delete their message
$sql = "UPDATE messages SET is_deleted = 1 WHERE id = ? AND sender_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $message_id, $user_id);

if ($stmt->execute() && $stmt->affected_rows > 0) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to delete or permission denied']);
}
?>
