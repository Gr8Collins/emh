<?php
session_start();
require 'config.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php?msg=error&error=Please login to view your inquiries');
    exit();
}

$user_id = $_SESSION['user_id'];

// Handle reply to inquiry
if (isset($_POST['send_reply'])) {
    $reply_message = trim($_POST['reply_message']);
    $receiver_id = intval($_POST['receiver_id']);
    $listing_id = intval($_POST['listing_id']);
    $original_message_id = intval($_POST['message_id']);
    
    if (!empty($reply_message)) {
        // Insert reply as a new message
        $reply_query = "INSERT INTO messages (sender_id, receiver_id, listing_id, message, is_read, created_at) 
                       VALUES (?, ?, ?, ?, 0, NOW())";
        $stmt = $conn->prepare($reply_query);
        if ($stmt) {
            $stmt->bind_param("iiis", $user_id, $receiver_id, $listing_id, $reply_message);
            if ($stmt->execute()) {
                $_SESSION['alert'] = [
                    'type' => 'success',
                    'message' => 'Reply sent successfully!'
                ];
            } else {
                $_SESSION['alert'] = [
                    'type' => 'danger',
                    'message' => 'Failed to send reply. Please try again.'
                ];
            }
            $stmt->close();
        }
    }
    header('Location: property_inquiries.php');
    exit();
}

// Handle mark as read
if (isset($_GET['action']) && $_GET['action'] === 'mark_read' && isset($_GET['id'])) {
    $message_id = intval($_GET['id']);
    $update_query = "UPDATE messages SET is_read = 1 WHERE id = ? AND receiver_id = ?";
    $stmt = $conn->prepare($update_query);
    if ($stmt) {
        $stmt->bind_param("ii", $message_id, $user_id);
        $stmt->execute();
        $stmt->close();
    }
    header('Location: property_inquiries.php');
    exit();
}

// Handle delete
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $message_id = intval($_GET['id']);
    $delete_query = "DELETE FROM messages WHERE id = ? AND receiver_id = ?";
    $stmt = $conn->prepare($delete_query);
    if ($stmt) {
        $stmt->bind_param("ii", $message_id, $user_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['alert'] = [
            'type' => 'success',
            'message' => 'Message deleted successfully!'
        ];
    }
    header('Location: property_inquiries.php');
    exit();
}

// Get current tab
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'inbox';

// Fetch inquiries received by the user
$inquiries_query = "SELECT m.*, 
                    u.username as sender_username, u.first_name as sender_first_name, 
                    u.last_name as sender_last_name, u.email as sender_email, u.phone as sender_phone,
                    l.title as listing_title, l.id as listing_id, l.price as listing_price, 
                    l.location as listing_location, l.property_type, l.listing_type
                    FROM messages m
                    LEFT JOIN users u ON m.sender_id = u.id
                    LEFT JOIN listings l ON m.listing_id = l.id
                    WHERE m.receiver_id = ?
                    ORDER BY m.created_at DESC";

$stmt = $conn->prepare($inquiries_query);
$inquiries = [];
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $inquiries[] = $row;
    }
    $stmt->close();
}

// Fetch sent messages
$sent_query = "SELECT m.*, 
               u.username as receiver_username, u.first_name as receiver_first_name, 
               u.last_name as receiver_last_name,
               l.title as listing_title, l.id as listing_id
               FROM messages m
               LEFT JOIN users u ON m.receiver_id = u.id
               LEFT JOIN listings l ON m.listing_id = l.id
               WHERE m.sender_id = ?
               ORDER BY m.created_at DESC";

$stmt = $conn->prepare($sent_query);
$sent_messages = [];
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $sent_messages[] = $row;
    }
    $stmt->close();
}

// Get unread count for badge
$unread_query = "SELECT COUNT(*) as count FROM messages WHERE receiver_id = ? AND is_read = 0";
$stmt = $conn->prepare($unread_query);
$unread_count = 0;
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $unread_count = $result->fetch_assoc()['count'];
    $stmt->close();
}

// Format date function
function formatMessageDate($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y \a\t g:i A', $time);
    }
}

// Get listing type badge
function getListingBadge($type) {
    switch($type) {
        case 'sale': return 'bg-success';
        case 'rent': return 'bg-info';
        case 'lease': return 'bg-warning';
        default: return 'bg-secondary';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Property Inquiries - Ebonyi Property Hub</title>
    
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
        
        .inquiries-wrapper {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        
        .page-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 30px;
            border-radius: 16px;
            margin-bottom: 30px;
            box-shadow: 0 5px 20px rgba(255, 123, 0, 0.3);
        }
        
        .stats-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            height: 100%;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .stats-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 15px;
        }
        
        .stats-number {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--secondary);
            margin-bottom: 5px;
        }
        
        .stats-label {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 0;
        }
        
        .inquiry-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
        }
        
        .inquiry-card:hover {
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .inquiry-card.unread {
            border-left-color: var(--primary);
            background: #fff9f2;
        }
        
        .inquiry-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }
        
        .sender-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .sender-avatar {
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
        }
        
        .sender-details h5 {
            margin-bottom: 5px;
            color: var(--secondary);
        }
        
        .sender-details .text-muted {
            font-size: 0.85rem;
        }
        
        .listing-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-right: 8px;
        }
        
        .inquiry-message {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin: 15px 0;
            font-size: 0.95rem;
            line-height: 1.6;
            color: #444;
        }
        
        .inquiry-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }
        
        .inquiry-time {
            color: #666;
            font-size: 0.85rem;
        }
        
        .btn-action {
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }
        
        .btn-action i {
            margin-right: 5px;
        }
        
        .reply-form {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-top: 15px;
            display: none;
        }
        
        .reply-form.show {
            display: block;
        }
        
        .nav-tabs-custom {
            border-bottom: 2px solid #dee2e6;
            margin-bottom: 25px;
        }
        
        .nav-tabs-custom .nav-link {
            border: none;
            color: #666;
            padding: 12px 20px;
            font-weight: 600;
            position: relative;
        }
        
        .nav-tabs-custom .nav-link i {
            margin-right: 8px;
        }
        
        .nav-tabs-custom .nav-link.active {
            color: var(--primary);
            background: none;
            border-bottom: 3px solid var(--primary);
        }
        
        .nav-tabs-custom .nav-link:hover {
            border: none;
            color: var(--primary);
        }
        
        .badge-count {
            background: var(--primary);
            color: white;
            border-radius: 20px;
            padding: 2px 10px;
            font-size: 0.75rem;
            margin-left: 8px;
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
        
        .empty-state h4 {
            color: #666;
            margin-bottom: 10px;
        }
        
        .empty-state p {
            color: #999;
            margin-bottom: 20px;
        }
        
        .property-info {
            background: white;
            border-radius: 12px;
            padding: 15px;
            margin-top: 15px;
            border: 1px solid #eee;
        }
        
        .property-info-title {
            font-weight: 600;
            color: var(--secondary);
            margin-bottom: 10px;
        }
        
        .property-info-details {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            font-size: 0.9rem;
            color: #666;
        }
        
        .alert {
            border-radius: 12px;
            border: none;
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
            animation: slideDown 0.5s ease-in-out;
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
            .inquiries-wrapper {
                padding: 15px;
            }
            
            .page-header {
                padding: 20px;
            }
            
            .inquiry-header {
                flex-direction: column;
            }
            
            .sender-info {
                margin-bottom: 15px;
            }
            
            .inquiry-footer {
                flex-direction: column;
                gap: 15px;
            }
            
            .action-buttons {
                width: 100%;
                display: flex;
                gap: 10px;
            }
            
            .action-buttons .btn {
                flex: 1;
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
                    <a class="nav-link active" href="property_inquiries.php">
                        <i class="fas fa-envelope me-1"></i> Inquiries
                        <?php if ($unread_count > 0): ?>
                            <span class="badge bg-danger ms-1"><?php echo $unread_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="my_listings.php">
                        <i class="fas fa-list me-1"></i> My Listings
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

<div class="inquiries-wrapper">
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

    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h2 class="mb-2"><i class="fas fa-envelope me-2"></i> Property Inquiries</h2>
                <p class="mb-0 opacity-75">Manage messages and inquiries from potential buyers and tenants</p>
            </div>
            <div>
                <span class="badge bg-white text-dark p-3">
                    <i class="fas fa-user me-2"></i> Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-4 mb-4">
        <?php
        // Get counts
        $total_query = "SELECT COUNT(*) as count FROM messages WHERE receiver_id = ?";
        $stmt = $conn->prepare($total_query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $total_inquiries = $stmt->get_result()->fetch_assoc()['count'];
        $stmt->close();
        
        $unread_query2 = "SELECT COUNT(*) as count FROM messages WHERE receiver_id = ? AND is_read = 0";
        $stmt = $conn->prepare($unread_query2);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $unread_total = $stmt->get_result()->fetch_assoc()['count'];
        $stmt->close();
        
        $sent_query_count = "SELECT COUNT(*) as count FROM messages WHERE sender_id = ?";
        $stmt = $conn->prepare($sent_query_count);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $sent_count = $stmt->get_result()->fetch_assoc()['count'];
        $stmt->close();
        ?>
        
        <div class="col-sm-6 col-lg-4">
            <div class="stats-card">
                <div class="stats-icon bg-primary bg-opacity-10 text-primary">
                    <i class="fas fa-inbox"></i>
                </div>
                <div class="stats-number"><?php echo $total_inquiries; ?></div>
                <p class="stats-label">Total Inquiries Received</p>
            </div>
        </div>
        
        <div class="col-sm-6 col-lg-4">
            <div class="stats-card">
                <div class="stats-icon bg-warning bg-opacity-10 text-warning">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stats-number"><?php echo $unread_total; ?></div>
                <p class="stats-label">Unread Messages</p>
            </div>
        </div>
        
        <div class="col-sm-6 col-lg-4">
            <div class="stats-card">
                <div class="stats-icon bg-success bg-opacity-10 text-success">
                    <i class="fas fa-paper-plane"></i>
                </div>
                <div class="stats-number"><?php echo $sent_count; ?></div>
                <p class="stats-label">Messages Sent</p>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs-custom">
        <li class="nav-item">
            <a class="nav-link <?php echo $current_tab == 'inbox' ? 'active' : ''; ?>" href="?tab=inbox">
                <i class="fas fa-inbox"></i> Inbox
                <?php if ($unread_total > 0): ?>
                    <span class="badge-count"><?php echo $unread_total; ?></span>
                <?php endif; ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $current_tab == 'sent' ? 'active' : ''; ?>" href="?tab=sent">
                <i class="fas fa-paper-plane"></i> Sent
            </a>
        </li>
    </ul>

    <!-- Inquiries List -->
    <?php if ($current_tab == 'inbox'): ?>
        <?php if (empty($inquiries)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h4>No Inquiries Yet</h4>
                <p>You haven't received any inquiries. When buyers contact you about your listings, they'll appear here.</p>
                <a href="my_listings.php" class="btn btn-primary">
                    <i class="fas fa-list me-2"></i> View My Listings
                </a>
            </div>
        <?php else: ?>
            <?php foreach ($inquiries as $inquiry): ?>
                <div class="inquiry-card <?php echo !$inquiry['is_read'] ? 'unread' : ''; ?>" id="inquiry-<?php echo $inquiry['id']; ?>">
                    <div class="inquiry-header">
                        <div class="sender-info">
                            <div class="sender-avatar">
                                <?php echo strtoupper(substr($inquiry['sender_first_name'] ?? $inquiry['sender_username'] ?? 'U', 0, 1)); ?>
                            </div>
                            <div class="sender-details">
                                <h5 class="mb-1">
                                    <?php 
                                    $sender_name = trim(($inquiry['sender_first_name'] ?? '') . ' ' . ($inquiry['sender_last_name'] ?? ''));
                                    if (empty($sender_name)) {
                                        $sender_name = $inquiry['sender_username'] ?? 'User';
                                    }
                                    echo htmlspecialchars($sender_name);
                                    ?>
                                </h5>
                                <div class="text-muted">
                                    <small>
                                        <i class="fas fa-envelope me-1"></i> 
                                        <?php echo htmlspecialchars($inquiry['sender_email'] ?? 'Email hidden'); ?>
                                    </small>
                                    <?php if (!empty($inquiry['sender_phone'])): ?>
                                        <span class="mx-2">•</span>
                                        <small><i class="fas fa-phone me-1"></i> <?php echo htmlspecialchars($inquiry['sender_phone']); ?></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (!$inquiry['is_read']): ?>
                            <span class="badge bg-primary">New</span>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Property Info -->
                    <?php if ($inquiry['listing_id']): ?>
                        <div class="property-info">
                            <div class="property-info-title">
                                <i class="fas fa-home me-2 text-primary"></i>
                                Regarding: <a href="property_details.php?id=<?php echo $inquiry['listing_id']; ?>" class="text-decoration-none fw-bold">
                                    <?php echo htmlspecialchars($inquiry['listing_title'] ?? 'Property Listing'); ?>
                                </a>
                            </div>
                            <div class="property-info-details">
                                <?php if ($inquiry['listing_price']): ?>
                                    <span><i class="fas fa-tag me-1"></i> ₦<?php echo number_format($inquiry['listing_price']); ?></span>
                                <?php endif; ?>
                                <?php if ($inquiry['listing_location']): ?>
                                    <span><i class="fas fa-map-marker-alt me-1"></i> <?php echo htmlspecialchars($inquiry['listing_location']); ?></span>
                                <?php endif; ?>
                                <?php if ($inquiry['listing_type']): ?>
                                    <span class="listing-badge <?php echo getListingBadge($inquiry['listing_type']); ?>">
                                        For <?php echo ucfirst($inquiry['listing_type']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Message -->
                    <div class="inquiry-message">
                        <?php echo nl2br(htmlspecialchars($inquiry['message'])); ?>
                    </div>
                    
                    <div class="inquiry-footer">
                        <div class="inquiry-time">
                            <i class="fas fa-clock me-1"></i> <?php echo formatMessageDate($inquiry['created_at']); ?>
                        </div>
                        
                        <div class="action-buttons">
                            <?php if (!$inquiry['is_read']): ?>
                                <a href="?action=mark_read&id=<?php echo $inquiry['id']; ?>" class="btn btn-sm btn-outline-secondary btn-action" title="Mark as read">
                                    <i class="fas fa-check"></i> Mark Read
                                </a>
                            <?php endif; ?>
                            
                            <button class="btn btn-sm btn-outline-primary btn-action" onclick="toggleReplyForm(<?php echo $inquiry['id']; ?>)">
                                <i class="fas fa-reply"></i> Reply
                            </button>
                            
                            <a href="?action=delete&id=<?php echo $inquiry['id']; ?>" class="btn btn-sm btn-outline-danger btn-action" onclick="return confirm('Are you sure you want to delete this message?');">
                                <i class="fas fa-trash"></i> Delete
                            </a>
                        </div>
                    </div>
                    
                    <!-- Reply Form -->
                    <div id="reply-form-<?php echo $inquiry['id']; ?>" class="reply-form">
                        <form method="POST" action="property_inquiries.php">
                            <input type="hidden" name="message_id" value="<?php echo $inquiry['id']; ?>">
                            <input type="hidden" name="receiver_id" value="<?php echo $inquiry['sender_id']; ?>">
                            <input type="hidden" name="listing_id" value="<?php echo $inquiry['listing_id']; ?>">
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Your Reply</label>
                                <textarea class="form-control" name="reply_message" rows="4" required 
                                          placeholder="Type your reply here..."></textarea>
                            </div>
                            
                            <div class="d-flex gap-2">
                                <button type="submit" name="send_reply" class="btn btn-primary">
                                    <i class="fas fa-paper-plane me-2"></i> Send Reply
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="toggleReplyForm(<?php echo $inquiry['id']; ?>)">
                                    Cancel
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
    <?php else: ?>
        <!-- Sent Messages Tab -->
        <?php if (empty($sent_messages)): ?>
            <div class="empty-state">
                <i class="fas fa-paper-plane"></i>
                <h4>No Sent Messages</h4>
                <p>You haven't sent any messages yet.</p>
                <a href="properties.php" class="btn btn-primary">
                    <i class="fas fa-search me-2"></i> Browse Properties
                </a>
            </div>
        <?php else: ?>
            <?php foreach ($sent_messages as $message): ?>
                <div class="inquiry-card">
                    <div class="inquiry-header">
                        <div class="sender-info">
                            <div class="sender-avatar" style="background: linear-gradient(135deg, #6c757d 0%, #495057 100%);">
                                <?php echo strtoupper(substr($message['receiver_first_name'] ?? $message['receiver_username'] ?? 'U', 0, 1)); ?>
                            </div>
                            <div class="sender-details">
                                <h5 class="mb-1">
                                    To: <?php 
                                    $receiver_name = trim(($message['receiver_first_name'] ?? '') . ' ' . ($message['receiver_last_name'] ?? ''));
                                    if (empty($receiver_name)) {
                                        $receiver_name = $message['receiver_username'] ?? 'User';
                                    }
                                    echo htmlspecialchars($receiver_name);
                                    ?>
                                </h5>
                                <div class="text-muted">
                                    <small>
                                        <i class="fas fa-envelope me-1"></i> 
                                        <?php echo htmlspecialchars($message['receiver_username'] ?? 'User'); ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                        <span class="badge bg-secondary">Sent</span>
                    </div>
                    
                    <!-- Property Info -->
                    <?php if ($message['listing_id']): ?>
                        <div class="property-info">
                            <div class="property-info-title">
                                <i class="fas fa-home me-2 text-primary"></i>
                                Regarding: <a href="property_details.php?id=<?php echo $message['listing_id']; ?>" class="text-decoration-none">
                                    <?php echo htmlspecialchars($message['listing_title'] ?? 'Property Listing'); ?>
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Message -->
                    <div class="inquiry-message">
                        <?php echo nl2br(htmlspecialchars($message['message'])); ?>
                    </div>
                    
                    <div class="inquiry-footer">
                        <div class="inquiry-time">
                            <i class="fas fa-clock me-1"></i> Sent <?php echo formatMessageDate($message['created_at']); ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
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
    // Toggle reply form
    function toggleReplyForm(inquiryId) {
        const form = document.getElementById('reply-form-' + inquiryId);
        if (form) {
            form.classList.toggle('show');
            
            // Focus on textarea when opened
            if (form.classList.contains('show')) {
                const textarea = form.querySelector('textarea');
                if (textarea) {
                    textarea.focus();
                }
            }
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
</script>

<?php
$conn->close();
?>
</body>
</html>