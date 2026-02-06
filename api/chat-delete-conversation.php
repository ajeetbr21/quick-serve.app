<?php
/**
 * Delete Conversation API
 * Soft deletes conversation for the requesting user
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
$conversation_id = $data['conversation_id'] ?? null;
$user = $auth->getCurrentUser();
$user_id = $user['id'];
$role = $user['role'];

if (!$conversation_id) {
    echo json_encode(['success' => false, 'message' => 'Conversation ID required']);
    exit;
}

$db = new Database();
$conn = $db->getConnection();

// Determine which column to update based on role
$column = ($role === 'provider') ? 'deleted_by_provider' : 'deleted_by_customer';

// Update conversation
// Ensure user is actually part of the conversation
$check_sql = "SELECT id FROM conversations WHERE id = ? AND (customer_id = ? OR provider_id = ?)";
$check_stmt = $conn->prepare($check_sql);
$check_stmt->bind_param("iii", $conversation_id, $user_id, $user_id);
$check_stmt->execute();

if ($check_stmt->get_result()->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Conversation not found or access denied']);
    exit;
}

$update_sql = "UPDATE conversations SET $column = 1 WHERE id = ?";
$update_stmt = $conn->prepare($update_sql);
$update_stmt->bind_param("i", $conversation_id);

if ($update_stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to delete conversation']);
}
?>
