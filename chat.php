<?php
/**
 * QuickServe - Chat System
 * Real-time messaging between customers and providers
 */

// Enable error display for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/auth.php';
require_once 'config/database.php';

$auth = new Auth();
$auth->requireLogin();

$user = $auth->getCurrentUser();
$db = new Database();
$conn = $db->getConnection();

// Handle AJAX message submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_send'])) {
    header('Content-Type: application/json');
    
    $conversation_id = intval($_POST['conversation_id']);
    $message_text = trim($_POST['message_text']);
    
    if ($conversation_id && $message_text) {
        // Detect message type (default text, but check if it's an audio URL)
        $message_type = 'text';
        if (strpos($message_text, 'uploads/audio/') !== false) {
            $message_type = 'audio';
        }
        
        $insert_sql = "INSERT INTO messages (conversation_id, sender_id, message_text, message_type, created_at) 
                      VALUES (?, ?, ?, ?, NOW())";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("iiss", $conversation_id, $user['id'], $message_text, $message_type);
        
        if ($insert_stmt->execute()) {
            // Update conversation timestamp
            $update_sql = "UPDATE conversations SET updated_at = NOW() WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("i", $conversation_id);
            $update_stmt->execute();
            
            echo json_encode([
                'success' => true,
                'message' => 'Message sent',
                'message_id' => mysqli_insert_id($conn)
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to send']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid data']);
    }
    exit;
}

// Handle AJAX get new messages
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax_get_messages'])) {
    header('Content-Type: application/json');
    
    $conversation_id = intval($_GET['conversation_id']);
    $since_id = isset($_GET['since_id']) ? intval($_GET['since_id']) : 0;
    
    $msg_sql = "SELECT m.*, u.full_name as sender_name 
               FROM messages m
               LEFT JOIN users u ON m.sender_id = u.id
               WHERE m.conversation_id = ? AND m.id > ?
               ORDER BY m.created_at ASC";
    $msg_stmt = $conn->prepare($msg_sql);
    $msg_stmt->bind_param("ii", $conversation_id, $since_id);
    $msg_stmt->execute();
    $result = $msg_stmt->get_result();
    
    $messages = [];
    while ($msg = $result->fetch_assoc()) {
        $messages[] = [
            'id' => $msg['id'],
            'sender_id' => $msg['sender_id'],
            'message_text' => $msg['message_text'],
            'message_type' => $msg['message_type'],
            'is_mine' => ($msg['sender_id'] == $user['id']),
            'created_at' => $msg['created_at']
        ];
    }
    
    echo json_encode(['success' => true, 'messages' => $messages]);
    exit;
}

// Get conversation_id from URL if provided
$active_conversation_id = isset($_GET['conversation_id']) ? intval($_GET['conversation_id']) : null;

// Get parameters for creating new conversation
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : null;
$service_id = isset($_GET['service_id']) ? intval($_GET['service_id']) : null;
$provider_id = isset($_GET['provider_id']) ? intval($_GET['provider_id']) : null;

// Auto-create conversation if customer_id and service_id provided
if (($customer_id && $service_id) || ($provider_id && $service_id)) {
    // Determine customer and provider IDs
    if ($user['role'] === 'provider') {
        $final_customer_id = $customer_id;
        $final_provider_id = $user['id'];
    } else {
        $final_customer_id = $user['id'];
        $final_provider_id = $provider_id ?: $customer_id; // fallback
    }
    
    // DEBUG: Log the values
    error_log("[CHAT DEBUG] User Role: " . $user['role']);
    error_log("[CHAT DEBUG] Final Customer ID: " . $final_customer_id);
    error_log("[CHAT DEBUG] Final Provider ID: " . $final_provider_id);
    error_log("[CHAT DEBUG] Service ID: " . $service_id);
    
    // Check if conversation already exists
    $check_sql = "SELECT id FROM conversations WHERE customer_id = ? AND provider_id = ? AND service_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    
    if (!$check_stmt) {
        error_log("[CHAT ERROR] Prepare failed: " . $conn->error);
        die("Database error: Unable to prepare statement. Check if conversations table exists.");
    }
    
    $check_stmt->bind_param("iii", $final_customer_id, $final_provider_id, $service_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        // Conversation exists, get its ID
        $existing_conv = $check_result->fetch_assoc();
        $active_conversation_id = $existing_conv['id'];
        error_log("[CHAT DEBUG] Found existing conversation: " . $active_conversation_id);
    } else {
        // Create new conversation
        error_log("[CHAT DEBUG] Creating new conversation...");
        $create_sql = "INSERT INTO conversations (customer_id, provider_id, service_id, created_at, updated_at) 
                      VALUES (?, ?, ?, NOW(), NOW())";
        $create_stmt = $conn->prepare($create_sql);
        
        if (!$create_stmt) {
            error_log("[CHAT ERROR] Insert prepare failed: " . $conn->error);
            die("Database error: Unable to create conversation. Check if conversations table exists.");
        }
        
        $create_stmt->bind_param("iii", $final_customer_id, $final_provider_id, $service_id);
        
        if ($create_stmt->execute()) {
            $active_conversation_id = mysqli_insert_id($conn);
            error_log("[CHAT DEBUG] Created new conversation: " . $active_conversation_id);
        } else {
            error_log("[CHAT ERROR] Insert execution failed: " . $create_stmt->error);
            die("Database error: " . $create_stmt->error);
        }
    }
    
    // Redirect to clean URL with conversation_id
    if ($active_conversation_id) {
        error_log("[CHAT DEBUG] Redirecting to conversation: " . $active_conversation_id);
        header("Location: chat.php?conversation_id=" . $active_conversation_id);
        exit;
    } else {
        error_log("[CHAT ERROR] No conversation ID available for redirect");
        die("Error: Unable to create or find conversation.");
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - QuickServe</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time() . rand(); ?>">
    <style>
        .chat-container {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 20px;
            height: calc(100vh - 200px);
            max-height: 700px;
        }
        
        /* Conversations List */
        .conversations-list {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 20px;
            overflow-y: auto;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .conversation-item {
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid transparent;
            display: flex;
            gap: 12px;
            align-items: start;
        }
        
        .conversation-item:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateX(5px);
        }
        
        .conversation-item.active {
            background: rgba(76, 175, 80, 0.3);
            border-color: #4CAF50;
        }
        
        .conversation-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }
        
        .conversation-info {
            flex: 1;
            overflow: hidden;
        }
        
        .conversation-name {
            font-weight: 600;
            margin-bottom: 5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .conversation-last-message {
            font-size: 0.85rem;
            opacity: 0.8;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .conversation-time {
            font-size: 0.75rem;
            opacity: 0.6;
        }
        
        .unread-badge {
            background: #f44336;
            color: white;
            border-radius: 12px;
            padding: 2px 8px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        /* Chat Window */
        .chat-window {
            display: flex;
            flex-direction: column;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            overflow: hidden;
        }
        
        .chat-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            background: rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .chat-header-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .chat-header-info h3 {
            margin: 0 0 5px 0;
        }
        
        .chat-header-info p {
            margin: 0;
            font-size: 0.85rem;
            opacity: 0.8;
        }
        
        /* Messages Area */
        .messages-container {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .message {
            display: flex;
            gap: 10px;
            max-width: 70%;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .message.mine {
            align-self: flex-end;
            flex-direction: row-reverse;
        }
        
        .message-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .message-content {
            background: rgba(255, 255, 255, 0.2);
            padding: 12px 16px;
            border-radius: 16px;
            max-width: 100%;
            word-wrap: break-word;
        }
        
        .message.mine .message-content {
            background: rgba(76, 175, 80, 0.3);
        }
        
        .message-text {
            margin-bottom: 5px;
        }
        
        .message-time {
            font-size: 0.75rem;
            opacity: 0.6;
        }
        
        .message-location {
            background: rgba(33, 150, 243, 0.2);
            padding: 10px;
            border-radius: 10px;
            margin-top: 5px;
        }
        
        .message-location a {
            color: #64B5F6;
            text-decoration: none;
            font-weight: 600;
        }
        
        /* Message Input */
        .message-input-container {
            padding: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
            background: rgba(255, 255, 255, 0.1);
        }
        
        .message-input-form {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .message-input {
            flex: 1;
            padding: 12px 20px;
            border-radius: 25px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            background: #ffffff; /* Solid white background */
            color: #333; /* Dark text */
            font-size: 1rem;
            font-weight: 500;
        }
        
        .message-input:focus {
            outline: none;
            border-color: #4CAF50;
            background: #ffffff;
        }
        
        .message-input::placeholder {
            color: #888; /* Darker placeholder */
        }
        
        .btn-send {
            padding: 12px 24px;
            border-radius: 25px;
            background: #4CAF50;
            color: white;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-send:hover {
            background: #45a049;
            transform: scale(1.05);
        }
        
        .btn-location {
            padding: 12px;
            border-radius: 50%;
            background: rgba(33, 150, 243, 0.3);
            color: white;
            border: none;
            cursor: pointer;
            font-size: 1.2rem;
            transition: all 0.3s ease;
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-mic {
             padding: 12px;
             border-radius: 50%;
             background: #ff5722; /* Different color for mic */
             color: white;
             border: none;
             cursor: pointer;
             font-size: 1.2rem;
             width: 45px;
             height: 45px;
             display: flex;
             align-items: center;
             justify-content: center;
             margin-right: 5px;
             transition: all 0.2s;
        }
        
        .btn-mic:active {
            transform: scale(1.2);
            background: red;
        }

        .message-menu {
            display: inline-block;
            margin-left: 10px;
            opacity: 0.5;
            font-size: 0.8rem;
        }
        
        .message:hover .message-menu {
            opacity: 1;
        }
        
        .message-menu button {
            background: none;
            border: none;
            cursor: pointer;
            padding: 2px;
        }
        
        .btn-location:hover {
            background: rgba(33, 150, 243, 0.5);
            transform: scale(1.1);
        }
        
        .empty-chat {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            opacity: 0.6;
        }
        
        .empty-chat-icon {
            font-size: 4rem;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .chat-container {
                grid-template-columns: 1fr;
            }
            
            .conversations-list {
                display: none;
            }
            
            .conversations-list.mobile-show {
                display: block;
            }
            
            .chat-window {
                display: none;
            }
            
            .chat-window.mobile-show {
                display: flex;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Navigation -->
        <nav class="navbar">
            <div class="navbar-content glass">
                <div class="logo">
                    <span class="logo-icon logo-animate">🚀</span>
                    <span class="text-wave">QuickServe</span>
                </div>
                <ul class="nav-links">
                    <li><a href="index.php">🏠 Home</a></li>
                    <?php if ($user['role'] === 'customer'): ?>
                        <li><a href="customer-dashboard.php">📊 Dashboard</a></li>
                    <?php elseif ($user['role'] === 'provider'): ?>
                        <li><a href="provider-dashboard.php">💼 Dashboard</a></li>
                    <?php endif; ?>
                    <li><a href="chat.php" class="active">💬 Messages</a></li>
                    <li><a href="logout.php" class="btn btn-secondary">🚪 Logout</a></li>
                </ul>
            </div>
        </nav>

        <h1 style="margin: 30px 0;">💬 Messages</h1>

        <div class="chat-container">
            <!-- Conversations List -->
            <div class="conversations-list" id="conversationsList">
                <h3 style="margin-bottom: 20px;">Your Conversations</h3>
                <div id="conversationsContent">
                    <?php
                    // Load conversations directly from PHP
                    $conv_sql = "SELECT 
                                    c.id,
                                    c.customer_id,
                                    c.provider_id,
                                    c.service_id,
                                    c.updated_at,
                                    cu.full_name as customer_name,
                                    p.full_name as provider_name,
                                    s.title as service_title
                                FROM conversations c
                                LEFT JOIN users cu ON c.customer_id = cu.id
                                LEFT JOIN users p ON c.provider_id = p.id
                                LEFT JOIN services s ON c.service_id = s.id
                                WHERE c.customer_id = ? OR c.provider_id = ?
                                ORDER BY c.updated_at DESC";
                    
                    $conv_stmt = $conn->prepare($conv_sql);
                    $conv_stmt->bind_param("ii", $user['id'], $user['id']);
                    $conv_stmt->execute();
                    $conv_result = $conv_stmt->get_result();
                    
                    if ($conv_result->num_rows > 0):
                        while ($conv = $conv_result->fetch_assoc()):
                            // Determine other user
                            if ($conv['customer_id'] == $user['id']) {
                                $other_name = $conv['provider_name'];
                                $other_id = $conv['provider_id'];
                                $other_role = 'provider';
                            } else {
                                $other_name = $conv['customer_name'];
                                $other_id = $conv['customer_id'];
                                $other_role = 'customer';
                            }
                            
                            $time_ago = 'Just now';
                            $is_active = ($active_conversation_id == $conv['id']) ? 'active' : '';
                    ?>
                        <div class="conversation-item <?php echo $is_active; ?>" 
                             onclick="window.location.href='chat.php?conversation_id=<?php echo $conv['id']; ?>'" style="cursor: pointer;">
                            <div class="conversation-avatar">
                                <?php echo $other_role === 'provider' ? '👨‍🔧' : '👤'; ?>
                            </div>
                            <div class="conversation-info">
                                <div class="conversation-name">
                                    <span><?php echo htmlspecialchars($other_name); ?></span>
                                </div>
                                <div style="font-size: 0.75rem; opacity: 0.7; margin-bottom: 3px;">📋 <?php echo htmlspecialchars($conv['service_title']); ?></div>
                                <div class="conversation-last-message">Start chatting</div>
                                <div class="conversation-time"><?php echo $time_ago; ?></div>
                            </div>
                        </div>
                    <?php 
                        endwhile;
                    else:
                    ?>
                        <div style="text-align: center; padding: 20px; opacity: 0.6;">
                            <p>No conversations yet</p>
                            <p style="font-size: 0.85rem;">Start chatting with service providers!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Chat Window -->
            <div class="chat-window" id="chatWindow">
                <?php if ($active_conversation_id): 
                    // Load conversation details and messages directly
                    $conv_detail_sql = "SELECT c.*, cu.full_name as customer_name, p.full_name as provider_name, s.title as service_title
                                       FROM conversations c
                                       LEFT JOIN users cu ON c.customer_id = cu.id
                                       LEFT JOIN users p ON c.provider_id = p.id
                                       LEFT JOIN services s ON c.service_id = s.id
                                       WHERE c.id = ?";
                    $conv_detail_stmt = $conn->prepare($conv_detail_sql);
                    $conv_detail_stmt->bind_param("i", $active_conversation_id);
                    $conv_detail_stmt->execute();
                    $conv_detail = $conv_detail_stmt->get_result()->fetch_assoc();
                    
                    if ($conv_detail):
                        // Determine other user
                        if ($conv_detail['customer_id'] == $user['id']) {
                            $other_name = $conv_detail['provider_name'];
                            $other_role = 'provider';
                        } else {
                            $other_name = $conv_detail['customer_name'];
                            $other_role = 'customer';
                        }
                        
                        // Load messages (exclude deleted)
                        $msg_sql = "SELECT m.*, u.full_name as sender_name 
                                   FROM messages m
                                   LEFT JOIN users u ON m.sender_id = u.id
                                   WHERE m.conversation_id = ? AND m.is_deleted = 0
                                   ORDER BY m.created_at ASC";
                        $msg_stmt = $conn->prepare($msg_sql);
                        $msg_stmt->bind_param("i", $active_conversation_id);
                        $msg_stmt->execute();
                        $messages = $msg_stmt->get_result();
                ?>
                    <div class="chat-header">
                        <div class="chat-header-avatar online-ripple">
                            <?php echo $other_role === 'provider' ? '👨‍🔧' : '👤'; ?>
                        </div>
                        <div class="chat-header-info">
                            <h3><?php echo htmlspecialchars($other_name); ?></h3>
                            <p><?php echo $other_role === 'provider' ? 'Service Provider' : 'Customer'; ?></p>
                        </div>
                        <div style="margin-left: auto; display: flex; gap: 10px;">
                             <button class="btn-location" onclick="window.location.href='tel:'" title="Call Phone" style="background: #e91e63;">📞</button>
                             <button class="btn-location" onclick="deleteConversation(<?php echo $active_conversation_id; ?>)" title="Delete Conversation" style="background: #f44336;">🗑️</button>
                        </div>
                    </div>
                    
                    <div class="messages-container" id="messagesContainer">
                        <?php if ($messages->num_rows === 0): ?>
                            <div style="text-align: center; opacity: 0.6; margin: auto;">
                                <p>No messages yet. Start the conversation!</p>
                            </div>
                        <?php else:
                            while ($msg = $messages->fetch_assoc()):
                                $is_mine = ($msg['sender_id'] == $user['id']);
                        ?>
                            <div class="message <?php echo $is_mine ? 'mine' : ''; ?> message-enter">
                                <div class="message-avatar">
                                    <?php echo $is_mine ? '😊' : '👤'; ?>
                                </div>
                                <div class="message-content">
                                    <?php 
                                        $msg_text = htmlspecialchars($msg['message_text']);
                                        // Auto-linkify URLs
                                        $msg_text = preg_replace(
                                            '/\b(https?:\/\/\S+)\b/i', 
                                            '<a href="$1" target="_blank" style="color: blue; text-decoration: underline;">$1</a>', 
                                            $msg_text
                                        );
                                    ?>
                                    <?php if ($msg['message_type'] === 'location'): ?>
                                        <div class="message-location">
                                            📍 <strong>Location Shared</strong><br>
                                            <?php echo htmlspecialchars($msg['location_address'] ?? 'View on map'); ?><br>
                                            <a href="https://www.google.com/maps?q=<?php echo $msg['location_lat']; ?>,<?php echo $msg['location_lng']; ?>" target="_blank">
                                                Open in Google Maps →
                                            </a>
                                        </div>
                                    <?php elseif ($msg['message_type'] === 'audio'): ?>
                                        <div>
                                            🎤 <strong>Voice Note</strong><br>
                                            <audio controls src="<?php echo htmlspecialchars($msg['message_text']); ?>" class="audio-player"></audio>
                                        </div>
                                    <?php else: ?>
                                        <div class="message-text"><?php echo $msg_text; ?></div>
                                    <?php endif; ?>
                                    <div class="message-time">Just now</div>
                                </div>
                            </div>
                        <?php 
                            endwhile;
                        endif; 
                        ?>
                    </div>
                    
                    <div class="message-input-container">
                        <form class="message-input-form" id="messageForm" onsubmit="sendMessageAjax(event)">
                            <button type="button" class="btn-location" onclick="getLocation()" title="Share Location">📍</button>
                            
                            <!-- Voice 2.0: Press & Hold Mic -->
                            <button type="button" class="btn-mic" id="recordBtn" title="Hold to Record">🎤</button>
                            <!-- Audio File Upload (Backup) -->
                            <label for="audioUpload" class="btn-mic" style="margin: 0; display: flex; align-items: center; justify-content: center; cursor: pointer; background: #2196F3;" title="Upload Audio File">
                                📁
                            </label>
                            <input type="file" id="audioUpload" accept="audio/*" style="display: none;" onchange="handleAudioUpload(this)">

                            <input type="hidden" id="conversationId" value="<?php echo $active_conversation_id; ?>">
                            <input type="text" class="message-input input-wave-focus" id="messageInput" 
                                   placeholder="Type a message..." autocomplete="off">
                            <button type="submit" class="btn-send" id="sendBtn">Send 📤</button>
                        </form>
                        <div id="recordingStatus" style="display: none; color: red; text-align: center; margin-top: 5px; font-weight: bold;">🔴 Recording... (Release to Send)</div>
                    </div>
                <?php 
                    endif;
                else: 
                ?>
                    <div class="empty-chat">
                        <div class="empty-chat-icon">💬</div>
                        <h3>Select a conversation to start chatting</h3>
                        <p>Choose a conversation from the list or start a new one</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer" style="margin-top: 50px;">
            <p>&copy; 2026 QuickServe - Your Local Service Marketplace</p>
            <p>Developed by Ajeet Kumar, Gagan Jha, Siddhi Panchal</p>
        </div>
    </div>

    <script>
        let currentConversationId = <?php echo $active_conversation_id ?? 'null'; ?>;
        let currentUserId = <?php echo $user['id']; ?>;
        let lastMessageId = <?php 
            // Get last message ID if conversation is active
            if ($active_conversation_id) {
                $last_msg_sql = "SELECT MAX(id) as last_id FROM messages WHERE conversation_id = ?";
                $last_msg_stmt = $conn->prepare($last_msg_sql);
                $last_msg_stmt->bind_param("i", $active_conversation_id);
                $last_msg_stmt->execute();
                $last_msg_result = $last_msg_stmt->get_result()->fetch_assoc();
                echo $last_msg_result['last_id'] ?? 0;
            } else {
                echo 0;
            }
        ?>;
        let refreshInterval = null;

        // --- 1. Audio Upload Logic ---
        function handleAudioUpload(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const formData = new FormData();
                formData.append('audio', file);
                
                // Show uploading state
                 const msgContainer = document.getElementById('messagesContainer');
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = '<div class="message mine"><div class="message-content">🎤 Uploading voice note...</div></div>';
                msgContainer.appendChild(tempDiv);
                msgContainer.scrollTop = msgContainer.scrollHeight;

                fetch('api/chat-upload-audio.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        sendAudioMessage(data.file_url);
                        tempDiv.remove();
                    } else {
                        alert('Upload failed: ' + data.message);
                        tempDiv.remove();
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Upload error');
                    tempDiv.remove();
                });
            }
        }

        function sendAudioMessage(fileUrl) {
            const formData = new FormData();
            formData.append('ajax_send', '1');
            formData.append('conversation_id', currentConversationId);
            formData.append('message_text', fileUrl); 
            
            fetch('chat.php', {
                method: 'POST',
                body: formData
            }).then(() => location.reload());
        }

        // Load conversations on page load
        document.addEventListener('DOMContentLoaded', function() {
            console.log('[CHAT] Real-time chat ready');
            console.log('[CHAT] Current conversation ID:', currentConversationId);
            
            // Start polling for new messages if conversation is active
            if (currentConversationId) {
                // Get last message ID
                const messages = document.querySelectorAll('.message');
                if (messages.length > 0) {
                    const lastMsg = messages[messages.length - 1];
                    // We'll track with a global var
                }
                
                // Poll for new messages every 3 seconds
                setInterval(pollNewMessages, 3000);
            }
        });

        // --- Unified Message Renderer ---
        // Uses createMessageHTML for consistency across polling and sending

        // Create message HTML
        function createMessageHTML(msg) {
            const isMine = msg.is_mine;
            const menuHtml = isMine ? `
                <div class="message-menu">
                    <button onclick="deleteMessage(${msg.id})">🗑️</button>
                    ${msg.message_type === 'text' ? `<button onclick="editMessage(${msg.id}, '${escapeHtml(msg.message_text)}')">✏️</button>` : ''}
                </div>
            ` : '';

            return `
                <div class="message ${isMine ? 'mine' : ''}" id="msg-${msg.id}">
                    <div class="message-avatar">
                        ${isMine ? '😊' : '👤'}
                    </div>
                    <div class="message-content">
                        ${msg.message_type === 'location' ? `
                            <div class="message-location">
                                📍 <strong>Location Shared</strong><br>
                                ${msg.location_address || 'View on map'}<br>
                                <a href="https://www.google.com/maps?q=${msg.location_lat},${msg.location_lng}" target="_blank">
                                    Open in Google Maps →
                                </a>
                            </div>
                        ` : (msg.message_type === 'audio' ? `
                            <div>
                                🎤 <strong>Voice Note</strong><br>
                                <audio controls src="${escapeHtml(msg.message_text)}" class="audio-player"></audio>
                            </div>
                        ` : `
                            <div class="message-text">${msg.is_edited ? '<small>(edited)</small> ' : ''}${linkify(msg.message_text)}</div>
                        `)}
                        <div class="message-time">${msg.created_at || 'Just now'} ${menuHtml}</div>
                    </div>
                </div>
            `;
        }

        // --- Linkify Helper ---
        function linkify(text) {
             const urlRegex = /(https?:\/\/[^\s]+)/g;
             return text.replace(urlRegex, function(url) {
                 return '<a href="' + url + '" target="_blank" style="color: blue; text-decoration: underline;">' + url + '</a>';
             });
        }

        // --- Voice Recording v2.0 Logic ---
        const recordBtn = document.getElementById('recordBtn');
        const recordingStatus = document.getElementById('recordingStatus');
        let mediaRecorder;
        let audioChunks = [];

        if (recordBtn) {
            recordBtn.addEventListener('mousedown', startRecording);
            recordBtn.addEventListener('mouseup', stopRecording);
            recordBtn.addEventListener('touchstart', startRecording);
            recordBtn.addEventListener('touchend', stopRecording);
        }

        function startRecording(e) {
            e.preventDefault();
            navigator.mediaDevices.getUserMedia({ audio: true })
                .then(stream => {
                    mediaRecorder = new MediaRecorder(stream);
                    mediaRecorder.start();
                    recordingStatus.style.display = 'block';
                    recordBtn.style.background = 'red';
                    
                    audioChunks = [];
                    mediaRecorder.addEventListener("dataavailable", event => {
                        audioChunks.push(event.data);
                    });
                });
        }

        function stopRecording(e) {
            e.preventDefault();
            if (!mediaRecorder) return;
            
            mediaRecorder.stop();
            recordingStatus.style.display = 'none';
            recordBtn.style.background = ''; // reset
            
            mediaRecorder.addEventListener("stop", () => {
                const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
                const file = new File([audioBlob], "voice_note.webm", { type: "audio/webm" });
                
                // Reuse existing upload logic
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                handleAudioUpload({ files: dataTransfer.files });
            });
        }

        // --- Message Actions ---
        function deleteMessage(messageId) {
            if(!confirm('Delete this message?')) return;
            
            fetch('api/chat-delete-message.php', {
                method: 'POST',
                body: JSON.stringify({ message_id: messageId })
            })
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    document.getElementById('msg-' + messageId).remove();
                } else {
                    alert('Failed to delete');
                }
            });
        }

        function editMessage(messageId, oldText) {
            const newText = prompt('Edit message:', oldText);
            if (newText && newText !== oldText) {
                fetch('api/chat-edit-message.php', {
                    method: 'POST',
                    body: JSON.stringify({ message_id: messageId, message_text: newText })
                })
                .then(res => res.json())
                .then(data => {
                    if(data.success) {
                        location.reload(); // Simple reload to reflect changes
                    } else {
                        alert('Failed to edit');
                    }
                });
            }
        }

        function deleteConversation(convId) {
            if(!confirm('Delete this entire conversation? It will disappear for you.')) return;
            
            fetch('api/chat-delete-conversation.php', {
                method: 'POST',
                body: JSON.stringify({ conversation_id: convId })
            })
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    window.location.href = 'chat.php'; // Redirect to clear active chat
                } else {
                    alert('Failed to delete conversation');
                }
            });
        }

        // --- Restored Send Message Function ---
        function sendMessageAjax(event) {
            event.preventDefault();
            
            const input = document.getElementById('messageInput');
            const sendBtn = document.getElementById('sendBtn');
            const conversationId = document.getElementById('conversationId').value;
            const messageText = input.value.trim();
            
            if (!messageText) return;
            
            // Disable button
            sendBtn.disabled = true;
            sendBtn.textContent = 'Sending...';
            
            // Send via AJAX
            const formData = new FormData();
            formData.append('ajax_send', '1');
            formData.append('conversation_id', conversationId);
            formData.append('message_text', messageText);
            
            fetch('chat.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Clear input
                    input.value = '';
                    
                    // Add message to UI immediately
                    addMessageToUI({
                        id: data.message_id,
                        message_text: messageText,
                        is_mine: true,
                        message_type: 'text',
                        created_at: 'Just now'
                    });
                    
                    // Update last message ID
                    lastMessageId = data.message_id;
                    
                    // Scroll to bottom
                    scrollToBottom();
                } else {
                    alert('Failed to send message: ' + data.message);
                }
            })
            .catch(error => {
                console.error('[CHAT] Send error:', error);
                alert('Network error sending message');
            })
            .finally(() => {
                // Re-enable button
                sendBtn.disabled = false;
                sendBtn.textContent = 'Send 📤';
            });
        }

        function getLocation() {
            if (!navigator.geolocation) {
                alert('Geolocation is not supported by your browser');
                return;
            }
            
            navigator.geolocation.getCurrentPosition(function(position) {
                 const conversationId = document.getElementById('conversationId').value;
                 // Send location as a special message
                 const formData = new FormData();
                 formData.append('ajax_send', '1');
                 formData.append('conversation_id', conversationId);
                 // We will send a formatted string that the server/UI can parse or just use text for now
                 // Ideally this should use a proper message_type, but to keep it simple with existing PHP handler:
                 // We'll trust the existing PHP handler interprets 'message_type' if we pass it, 
                 // BUT the current PHP top block only looks for 'message_text'.
                 // Let's send a text representation for now or duplicate the logic if needed.
                 // Actually, let's implement the specific endpoint or just send text.
                 // The PHP block at top handles `strpos(message_text, 'uploads/audio/')` logic.
                 // Let's add specific handling in PHP if we can, or just send a text link.
                 
                 const locUrl = `https://www.google.com/maps?q=${position.coords.latitude},${position.coords.longitude}`;
                 formData.append('message_text', locUrl);
                 
                 fetch('chat.php', { method: 'POST', body: formData })
                 .then(r => r.json())
                 .then(d => {
                     if(d.success) location.reload();
                 });
            }, function(error) {
                alert('Unable to get your location: ' + error.message);
            });
        }

        // --- Polling Logic Restored ---
        document.addEventListener('DOMContentLoaded', function() {
            if (currentConversationId) {
                // Scroll to bottom initially
                scrollToBottom();
                // Start polling
                setInterval(pollNewMessages, 3000);
            }
        });

        function pollNewMessages() {
            if (!currentConversationId) return;
            
            const url = `chat.php?ajax_get_messages=1&conversation_id=${currentConversationId}&since_id=${lastMessageId}`;
            
            fetch(url)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.messages.length > 0) {
                    data.messages.forEach(msg => {
                        addMessageToUI(msg);
                        lastMessageId = Math.max(lastMessageId, msg.id);
                    });
                    scrollToBottom();
                }
            });
        }

        // Send message via AJAX
        function sendMessageAjax(event) {
            event.preventDefault();
            
            const form = event.target;
            const input = document.getElementById('messageInput');
            const sendBtn = document.getElementById('sendBtn');
            const conversationId = document.getElementById('conversationId').value;
            const messageText = input.value.trim();
            
            if (!messageText) return;
            
            // Disable button
            sendBtn.disabled = true;
            sendBtn.textContent = 'Sending...';
            
            // Send via AJAX
            const formData = new FormData();
            formData.append('ajax_send', '1');
            formData.append('conversation_id', conversationId);
            formData.append('message_text', messageText);
            
            fetch('chat.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                console.log('[CHAT] Send response:', data);
                if (data.success) {
                    // Clear input
                    input.value = '';
                    
                    // Add message to UI immediately
                    addMessageToUI({
                        id: data.message_id,
                        message_text: messageText,
                        is_mine: true,
                        message_type: 'text'
                    });
                    
                    // Update last message ID
                    lastMessageId = data.message_id;
                    
                    // Scroll to bottom
                    scrollToBottom();
                } else {
                    alert('Failed to send message: ' + data.message);
                }
            })
            .catch(error => {
                console.error('[CHAT] Send error:', error);
                alert('Network error sending message');
            })
            .finally(() => {
                // Re-enable button
                sendBtn.disabled = false;
                sendBtn.textContent = 'Send 📤';
            });
        }
        
        // Poll for new messages
        function pollNewMessages() {
            if (!currentConversationId) return;
            
            const url = `chat.php?ajax_get_messages=1&conversation_id=${currentConversationId}&since_id=${lastMessageId}`;
            
            fetch(url)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.messages.length > 0) {
                    console.log('[CHAT] New messages:', data.messages.length);
                    data.messages.forEach(msg => {
                        addMessageToUI(msg);
                        lastMessageId = Math.max(lastMessageId, msg.id);
                    });
                    scrollToBottom();
                }
            })
            .catch(error => {
                console.error('[CHAT] Poll error:', error);
            });
        }
        
        // Add message to UI
        function addMessageToUI(msg) {
            const container = document.getElementById('messagesContainer');
            // Use the shared createMessageHTML function to ensure consistent formatting & features (Edit/Delete)
            const messageHTML = createMessageHTML(msg);
            container.insertAdjacentHTML('beforeend', messageHTML);
        }

        // --- Removed duplicate/legacy sendMessage, shareLocation ---

        // Helper functions
        function scrollToBottom() {
            const container = document.getElementById('messagesContainer');
            if (container) {
                setTimeout(() => {
                    container.scrollTop = container.scrollHeight;
                }, 100);
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
