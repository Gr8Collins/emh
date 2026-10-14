<?php
session_start();
require 'config.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php?msg=error&error=Please login to view your inquiries');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get current conversation ID if any
$current_conversation = isset($_GET['conversation']) ? intval($_GET['conversation']) : 0;
$current_listing_id = isset($_GET['listing_id']) ? intval($_GET['listing_id']) : 0;

// Fetch all conversations (grouped by listing and other party)
$conversations_query = "SELECT 
                        m.listing_id,
                        l.title as listing_title,
                        l.id as listing_id,
                        l.price,
                        l.location,
                        l.property_type,
                        l.listing_type,
                        l.image as listing_image,
                        -- Other party details
                        CASE 
                            WHEN m.sender_id = ? THEN m.receiver_id
                            ELSE m.sender_id
                        END as other_user_id,
                        u.username as other_username,
                        u.first_name as other_first_name,
                        u.last_name as other_last_name,
                        u.avatar_url as other_avatar,
                        -- Last message details
                        MAX(m.created_at) as last_message_time,
                        (SELECT message FROM messages m2 
                         WHERE (m2.sender_id = m.sender_id AND m2.receiver_id = m.receiver_id)
                            OR (m2.sender_id = m.receiver_id AND m2.receiver_id = m.sender_id)
                            AND m2.listing_id = m.listing_id
                         ORDER BY m2.created_at DESC LIMIT 1) as last_message,
                        (SELECT sender_id FROM messages m3 
                         WHERE (m3.sender_id = m.sender_id AND m3.receiver_id = m.receiver_id)
                            OR (m3.sender_id = m.receiver_id AND m3.receiver_id = m.sender_id)
                            AND m3.listing_id = m.listing_id
                         ORDER BY m3.created_at DESC LIMIT 1) as last_message_sender,
                        -- Unread count
                        (SELECT COUNT(*) FROM messages m4 
                         WHERE m4.receiver_id = ? 
                         AND m4.sender_id = CASE 
                                WHEN m.sender_id = ? THEN m.receiver_id
                                ELSE m.sender_id
                            END
                         AND m4.listing_id = m.listing_id
                         AND m4.is_read = 0) as unread_count
                        FROM messages m
                        LEFT JOIN listings l ON m.listing_id = l.id
                        LEFT JOIN users u ON u.id = CASE 
                            WHEN m.sender_id = ? THEN m.receiver_id
                            ELSE m.sender_id
                        END
                        WHERE m.sender_id = ? OR m.receiver_id = ?
                        GROUP BY m.listing_id, other_user_id
                        ORDER BY last_message_time DESC";

$stmt = $conn->prepare($conversations_query);
$stmt->bind_param("iiiiiii", $user_id, $user_id, $user_id, $user_id, $user_id, $user_id);
$stmt->execute();
$conversations_result = $stmt->get_result();
$conversations = [];
while ($row = $conversations_result->fetch_assoc()) {
    $conversations[] = $row;
}
$stmt->close();

// Fetch messages for current conversation
$messages = [];
$other_user = null;
$listing_details = null;

if ($current_conversation > 0 && $current_listing_id > 0) {
    // Get other user details
    $other_user_query = "SELECT id, username, first_name, last_name, avatar_url, phone, email 
                        FROM users WHERE id = ?";
    $stmt = $conn->prepare($other_user_query);
    $stmt->bind_param("i", $current_conversation);
    $stmt->execute();
    $other_user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // Get listing details
    $listing_query = "SELECT * FROM listings WHERE id = ?";
    $stmt = $conn->prepare($listing_query);
    $stmt->bind_param("i", $current_listing_id);
    $stmt->execute();
    $listing_details = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // Fetch all messages in this conversation
    $messages_query = "SELECT m.*, 
                      u.username, u.first_name, u.last_name, u.avatar_url
                      FROM messages m
                      LEFT JOIN users u ON m.sender_id = u.id
                      WHERE m.listing_id = ? 
                      AND ((m.sender_id = ? AND m.receiver_id = ?) 
                        OR (m.sender_id = ? AND m.receiver_id = ?))
                      ORDER BY m.created_at ASC";
    $stmt = $conn->prepare($messages_query);
    $stmt->bind_param("iiiii", $current_listing_id, $user_id, $current_conversation, $current_conversation, $user_id);
    $stmt->execute();
    $messages_result = $stmt->get_result();
    while ($row = $messages_result->fetch_assoc()) {
        $messages[] = $row;
    }
    $stmt->close();
    
    // Mark all messages in this conversation as read
    $mark_read_query = "UPDATE messages SET is_read = 1 
                       WHERE receiver_id = ? AND sender_id = ? AND listing_id = ? AND is_read = 0";
    $stmt = $conn->prepare($mark_read_query);
    $stmt->bind_param("iii", $user_id, $current_conversation, $current_listing_id);
    $stmt->execute();
    $stmt->close();
}

// Handle sending reply from buyer
if (isset($_POST['send_reply'])) {
    $message = trim($_POST['message']);
    $receiver_id = intval($_POST['receiver_id']);
    $listing_id = intval($_POST['listing_id']);
    
    if (!empty($message)) {
        $reply_query = "INSERT INTO messages (sender_id, receiver_id, listing_id, message, is_read, created_at) 
                       VALUES (?, ?, ?, ?, 0, NOW())";
        $stmt = $conn->prepare($reply_query);
        if ($stmt) {
            $stmt->bind_param("iiis", $user_id, $receiver_id, $listing_id, $message);
            if ($stmt->execute()) {
                $_SESSION['alert'] = [
                    'type' => 'success',
                    'message' => 'Reply sent successfully!'
                ];
            }
            $stmt->close();
        }
        header('Location: my_inquiries.php?conversation=' . $receiver_id . '&listing_id=' . $listing_id);
        exit();
    }
}

// Format date function
function formatMessageTime($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' min ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $time);
    }
}

// Get total unread count for badge
$total_unread_query = "SELECT COUNT(*) as count FROM messages WHERE receiver_id = ? AND is_read = 0";
$stmt = $conn->prepare($total_unread_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$total_unread = $stmt->get_result()->fetch_assoc()['count'];
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Inquiries - Ebonyi Property Hub</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <style>
        :root {
            --primary: #ff7b00;
            --primary-dark: #e66a00;
            --secondary: #2c3e50;
        }
        
        body {
            background-color: #f5f7fa;
            font-family: 'Inter', -apple-system, sans-serif;
        }
        
        .inbox-container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 20px;
        }
        
        .page-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 25px;
            border-radius: 16px;
            margin-bottom: 25px;
            box-shadow: 0 5px 20px rgba(255, 123, 0, 0.3);
        }
        
        .conversations-sidebar {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            height: calc(100vh - 200px);
            display: flex;
            flex-direction: column;
        }
        
        .conversations-list {
            overflow-y: auto;
            flex: 1;
        }
        
        .conversation-item {
            display: flex;
            padding: 15px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
        }
        
        .conversation-item:hover {
            background: #fff9f2;
        }
        
        .conversation-item.active {
            background: #fff0e0;
            border-left: 4px solid var(--primary);
        }
        
        .conversation-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            font-weight: 600;
            margin-right: 12px;
            flex-shrink: 0;
        }
        
        .conversation-details {
            flex: 1;
            min-width: 0;
        }
        
        .conversation-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        
        .conversation-name {
            font-weight: 600;
            color: var(--secondary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .conversation-time {
            font-size: 0.75rem;
            color: #999;
            white-space: nowrap;
        }
        
        .conversation-property {
            font-size: 0.8rem;
            color: var(--primary);
            margin-bottom: 5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .conversation-last-message {
            font-size: 0.85rem;
            color: #666;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .unread-badge {
            background: var(--primary);
            color: white;
            border-radius: 20px;
            padding: 2px 8px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 8px;
        }
        
        .chat-area {
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            height: calc(100vh - 200px);
            display: flex;
            flex-direction: column;
        }
        
        .chat-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
        }
        
        .chat-header-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            margin-right: 15px;
            object-fit: cover;
        }
        
        .chat-header-info h5 {
            margin-bottom: 5px;
            color: var(--secondary);
        }
        
        .chat-header-info p {
            margin-bottom: 0;
            font-size: 0.85rem;
            color: #666;
        }
        
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            background: #f8f9fa;
        }
        
        .message {
            display: flex;
            margin-bottom: 20px;
        }
        
        .message.sent {
            justify-content: flex-end;
        }
        
        .message-bubble {
            max-width: 70%;
            padding: 12px 16px;
            border-radius: 18px;
            position: relative;
        }
        
        .message.sent .message-bubble {
            background: var(--primary);
            color: white;
            border-bottom-right-radius: 4px;
        }
        
        .message.received .message-bubble {
            background: white;
            border: 1px solid #e0e0e0;
            border-bottom-left-radius: 4px;
        }
        
        .message-time {
            font-size: 0.7rem;
            margin-top: 5px;
            opacity: 0.7;
        }
        
        .message.sent .message-time {
            text-align: right;
            color: rgba(255,255,255,0.8);
        }
        
        .message.received .message-time {
            color: #999;
        }
        
        .chat-footer {
            padding: 20px;
            border-top: 1px solid #eee;
        }
        
        .reply-form {
            display: flex;
            gap: 10px;
        }
        
        .reply-form textarea {
            flex: 1;
            border: 1px solid #e0e0e0;
            border-radius: 24px;
            padding: 12px 20px;
            resize: none;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }
        
        .reply-form textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255,123,0,0.1);
        }
        
        .reply-form button {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }
        
        .reply-form button:hover {
            background: var(--primary-dark);
            transform: scale(1.1);
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 16px;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #ccc;
            margin-bottom: 20px;
        }
        
        .property-badge {
            background: #f0f0f0;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            color: var(--secondary);
        }
        
        .alert {
            border-radius: 12px;
            border: none;
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
            animation: slideDown 0.5s ease-in-out;
            z-index: 9999;
        }
        
        @keyframes slideDown {
            from {
                transform: translateY(-100%);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        @media (max-width: 768px) {
            .inbox-container {
                padding: 10px;
            }
            
            .conversations-sidebar {
                height: 300px;
                margin-bottom: 20px;
            }
            
            .chat-area {
                height: 500px;
            }
            
            .message-bubble {
                max-width: 85%;
            }
        }
    </style>
</head>
<body>

<!-- Navigation -->
<nav class="navbar navbar-expand-lg bg-white shadow-sm">
    <div class="container">
        <a class="navbar-brand" href="index.php">
            <img src="img/log.png" alt="Ebonyi Property Hub" height="40">
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link" href="index.php">
                        <i class="fas fa-home me-1"></i> Home
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="dashboard.php">
                        <i class="fas fa-tachometer-alt me-1"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="my_inquiries.php">
                        <i class="fas fa-comments me-1"></i> My Inquiries
                        <?php if ($total_unread > 0): ?>
                            <span class="badge bg-danger ms-1"><?php echo $total_unread; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="favorites.php">
                        <i class="fas fa-heart me-1"></i> Favorites
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="logout.php">
                        <i class="fas fa-sign-out-alt me-1"></i> Logout
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="inbox-container">
    <?php
    // Display alert messages from session
    if (isset($_SESSION['alert'])) {
        $alert = $_SESSION['alert'];
        echo '<div id="alertBox" class="alert alert-' . $alert['type'] . ' alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-4 z-3" style="max-width: 90%; width: 500px;" role="alert">
                <div class="d-flex align-items-center">
                    <i class="fas ' . ($alert['type'] == 'success' ? 'fa-check-circle' : ($alert['type'] == 'warning' ? 'fa-exclamation-triangle' : 'fa-exclamation-circle')) . ' me-2"></i>
                    <span>' . $alert['message'] . '</span>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
              </div>';
        unset($_SESSION['alert']);
    }
    ?>

    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h2 class="mb-2"><i class="fas fa-comments me-2"></i> My Inquiries</h2>
                <p class="mb-0 opacity-75">Track all your conversations with property owners and sellers</p>
            </div>
            <div>
                <span class="badge bg-white text-dark p-3">
                    <i class="fas fa-user me-2"></i> <?php echo htmlspecialchars($_SESSION['username']); ?>
                </span>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Conversations List -->
        <div class="col-md-4 mb-4">
            <div class="conversations-sidebar">
                <div class="p-3 bg-light border-bottom">
                    <h6 class="mb-0"><i class="fas fa-inbox me-2"></i> Conversations</h6>
                </div>
                <div class="conversations-list">
                    <?php if (empty($conversations)): ?>
                        <div class="text-center p-4">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No conversations yet</p>
                            <a href="properties.php" class="btn btn-primary btn-sm">
                                <i class="fas fa-search me-2"></i>Browse Properties
                            </a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($conversations as $conv): ?>
                            <a href="my_inquiries.php?conversation=<?php echo $conv['other_user_id']; ?>&listing_id=<?php echo $conv['listing_id']; ?>" 
                               class="conversation-item <?php echo ($current_conversation == $conv['other_user_id'] && $current_listing_id == $conv['listing_id']) ? 'active' : ''; ?>">
                                <div class="conversation-avatar">
                                    <?php echo strtoupper(substr($conv['other_first_name'] ?? $conv['other_username'] ?? 'U', 0, 1)); ?>
                                </div>
                                <div class="conversation-details">
                                    <div class="conversation-header">
                                        <span class="conversation-name">
                                            <?php 
                                            $name = trim(($conv['other_first_name'] ?? '') . ' ' . ($conv['other_last_name'] ?? ''));
                                            echo htmlspecialchars($name ?: ($conv['other_username'] ?? 'User'));
                                            ?>
                                        </span>
                                        <span class="conversation-time">
                                            <?php echo formatMessageTime($conv['last_message_time']); ?>
                                        </span>
                                    </div>
                                    <div class="conversation-property">
                                        <i class="fas fa-home me-1"></i>
                                        <?php echo htmlspecialchars(substr($conv['listing_title'] ?? 'Property', 0, 30)); ?>
                                    </div>
                                    <div class="conversation-last-message">
                                        <?php if ($conv['last_message_sender'] == $user_id): ?>
                                            <span class="text-muted">You: </span>
                                        <?php endif; ?>
                                        <?php echo htmlspecialchars(substr($conv['last_message'] ?? '', 0, 40)); ?>...
                                    </div>
                                </div>
                                <?php if ($conv['unread_count'] > 0): ?>
                                    <span class="unread-badge"><?php echo $conv['unread_count']; ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Chat Area -->
        <div class="col-md-8 mb-4">
            <?php if ($current_conversation > 0 && $other_user && $listing_details): ?>
                <div class="chat-area">
                    <!-- Chat Header -->
                    <div class="chat-header">
                        <img src="<?php echo !empty($other_user['avatar_url']) ? htmlspecialchars($other_user['avatar_url']) : 'https://ui-avatars.com/api/?name=' . urlencode($other_user['first_name'] ?? $other_user['username']); ?>" 
                             alt="Avatar" class="chat-header-avatar">
                        <div class="chat-header-info">
                            <h5 class="mb-1">
                                <?php 
                                $other_name = trim(($other_user['first_name'] ?? '') . ' ' . ($other_user['last_name'] ?? ''));
                                echo htmlspecialchars($other_name ?: $other_user['username']);
                                ?>
                            </h5>
                            <p>
                                <i class="fas fa-home me-1"></i> 
                                <a href="property_details.php?id=<?php echo $listing_details['id']; ?>" class="text-decoration-none">
                                    <?php echo htmlspecialchars($listing_details['title']); ?>
                                </a>
                                <span class="property-badge ms-2">
                                    ₦<?php echo number_format($listing_details['price']); ?>
                                </span>
                            </p>
                        </div>
                    </div>
                    
                    <!-- Messages -->
                    <div class="chat-messages" id="chatMessages">
                        <?php if (empty($messages)): ?>
                            <div class="text-center text-muted py-5">
                                <i class="fas fa-comments fa-3x mb-3"></i>
                                <p>No messages yet. Start the conversation!</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($messages as $msg): ?>
                                <div class="message <?php echo $msg['sender_id'] == $user_id ? 'sent' : 'received'; ?>">
                                    <div class="message-bubble">
                                        <div class="message-text">
                                            <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                                        </div>
                                        <div class="message-time">
                                            <?php echo formatMessageTime($msg['created_at']); ?>
                                            <?php if ($msg['sender_id'] == $user_id && $msg['is_read']): ?>
                                                <i class="fas fa-check-circle ms-1" title="Read"></i>
                                            <?php elseif ($msg['sender_id'] == $user_id): ?>
                                                <i class="fas fa-check ms-1" title="Sent"></i>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Reply Form -->
                    <div class="chat-footer">
                        <form method="POST" action="my_inquiries.php" class="reply-form">
                            <input type="hidden" name="receiver_id" value="<?php echo $current_conversation; ?>">
                            <input type="hidden" name="listing_id" value="<?php echo $current_listing_id; ?>">
                            <textarea name="message" rows="1" placeholder="Type your message..." required></textarea>
                            <button type="submit" name="send_reply">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="chat-area d-flex align-items-center justify-content-center">
                    <div class="text-center text-muted">
                        <i class="fas fa-comment-dots fa-4x mb-3"></i>
                        <h5>Select a conversation</h5>
                        <p>Choose a conversation from the left to view messages</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Footer -->
<footer class="bg-dark text-white mt-5 py-4">
    <div class="container">
        <div class="row">
            <div class="col-md-6">
                <p>&copy; <?php echo date('Y'); ?> Ebonyi Property Hub. All rights reserved.</p>
            </div>
            <div class="col-md-6 text-md-end">
                <a href="index.php" class="text-white text-decoration-none me-3">Home</a>
                <a href="about.php" class="text-white text-decoration-none me-3">About</a>
                <a href="contact.php" class="text-white text-decoration-none">Contact</a>
            </div>
        </div>
    </div>
</footer>

<script>
    // Auto-scroll to bottom of messages
    function scrollToBottom() {
        const chatMessages = document.getElementById('chatMessages');
        if (chatMessages) {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
    }
    
    // Auto-hide alerts
    setTimeout(function() {
        const alertBox = document.getElementById("alertBox");
        if (alertBox) {
            alertBox.style.transition = "opacity 0.5s ease";
            alertBox.style.opacity = "0";
            setTimeout(() => {
                if (alertBox.parentNode) {
                    alertBox.remove();
                }
            }, 500);
        }
    }, 5000);
    
    // Scroll to bottom on page load
    document.addEventListener('DOMContentLoaded', function() {
        scrollToBottom();
    });
    
    // Auto-resize textarea
    const textarea = document.querySelector('.reply-form textarea');
    if (textarea) {
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });
    }
</script>

<?php
$conn->close();
?>
</body>
</html>