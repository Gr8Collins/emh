<?php
session_start();

// ============================================
// ACCESS CONTROL - Any logged-in user allowed
// ============================================
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

// Auto logout after 30 minutes of inactivity
$inactive_timeout = 1800; // 30 minutes
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $inactive_timeout)) {
    session_unset();
    session_destroy();
    header('Location: login.php?timeout=1');
    exit();
}
$_SESSION['last_activity'] = time();

require 'config.php';

// ============================================
// HANDLE AJAX REQUESTS (POST/GET)
// ============================================
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    $action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
    $user_id = $_SESSION['user_id'];
    $response = ['success' => false, 'message' => 'Invalid request'];
    
    // ============================================
    // SEND MESSAGE
    // ============================================
    if ($action === 'send_message') {
        $sender_id = $user_id;
        $receiver_id = isset($_POST['receiver_id']) ? intval($_POST['receiver_id']) : 0;
        $message = isset($_POST['message']) ? trim($_POST['message']) : '';
        
        if ($receiver_id <= 0 || empty($message)) {
            echo json_encode(['success' => false, 'message' => 'Receiver and message are required']);
            exit();
        }
        
        if ($sender_id == $receiver_id) {
            echo json_encode(['success' => false, 'message' => 'You cannot send a message to yourself']);
            exit();
        }
        
        $check_query = "SELECT id, username FROM users WHERE id = ?";
        $stmt = $conn->prepare($check_query);
        $stmt->bind_param("i", $receiver_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Receiver not found']);
            exit();
        }
        
        $sql = "INSERT INTO messages (sender_id, receiver_id, message, created_at) VALUES (?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iis", $sender_id, $receiver_id, $message);
        
        if ($stmt->execute()) {
            $sender_name = $_SESSION['username'] ?? 'Someone';
            $notification_title = "New message from " . $sender_name;
            $notification_message = substr($message, 0, 100) . (strlen($message) > 100 ? '...' : '');
            
            $notif_sql = "INSERT INTO notifications (user_id, type, title, message, link, created_at) 
                          VALUES (?, 'message', ?, ?, 'dashboard.php', NOW())";
            $notif_stmt = $conn->prepare($notif_sql);
            $notif_stmt->bind_param("iss", $receiver_id, $notification_title, $notification_message);
            $notif_stmt->execute();
            
            echo json_encode(['success' => true, 'message' => 'Message sent successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to send message']);
        }
        exit();
    }
    
    // ============================================
    // GET CONVERSATIONS
    // ============================================
    if ($action === 'get_conversations') {
        $sql = "SELECT 
                CASE 
                    WHEN m.sender_id = ? THEN m.receiver_id 
                    ELSE m.sender_id 
                END as other_user_id,
                u.username as other_user_name,
                MAX(m.created_at) as last_message_date,
                (SELECT message FROM messages 
                 WHERE (sender_id = ? AND receiver_id = 
                        CASE WHEN m.sender_id = ? THEN m.receiver_id ELSE m.sender_id END)
                    OR (sender_id = 
                        CASE WHEN m.sender_id = ? THEN m.receiver_id ELSE m.sender_id END
                        AND receiver_id = ?)
                 ORDER BY created_at DESC LIMIT 1) as last_message,
                (SELECT COUNT(*) FROM messages 
                 WHERE receiver_id = ? AND sender_id = 
                        CASE WHEN m.sender_id = ? THEN m.receiver_id ELSE m.sender_id END
                 AND is_read = 0) as unread_count
                FROM messages m
                JOIN users u ON u.id = CASE 
                    WHEN m.sender_id = ? THEN m.receiver_id 
                    ELSE m.sender_id 
                END
                WHERE m.sender_id = ? OR m.receiver_id = ?
                GROUP BY other_user_id
                ORDER BY last_message_date DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iiiiiiiiii", $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $conversations = [];
        while ($row = $result->fetch_assoc()) {
            $conversations[] = $row;
        }
        
        echo json_encode(['success' => true, 'conversations' => $conversations]);
        exit();
    }
    
    // ============================================
    // GET MESSAGES
    // ============================================
    if ($action === 'get_messages') {
        $other_user_id = isset($_GET['conversation_id']) ? intval($_GET['conversation_id']) : 0;
        
        if ($other_user_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid conversation']);
            exit();
        }
        
        $sql = "SELECT m.*, 
                u1.username as sender_name, 
                u2.username as receiver_name
                FROM messages m
                JOIN users u1 ON m.sender_id = u1.id
                JOIN users u2 ON m.receiver_id = u2.id
                WHERE (m.sender_id = ? AND m.receiver_id = ?) 
                   OR (m.sender_id = ? AND m.receiver_id = ?)
                ORDER BY m.created_at ASC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iiii", $user_id, $other_user_id, $other_user_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $messages = [];
        while ($row = $result->fetch_assoc()) {
            if ($row['receiver_id'] == $user_id && $row['is_read'] == 0) {
                $update_sql = "UPDATE messages SET is_read = 1 WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("i", $row['id']);
                $update_stmt->execute();
                $row['is_read'] = 1;
            }
            $messages[] = $row;
        }
        
        echo json_encode([
            'success' => true,
            'messages' => $messages
        ]);
        exit();
    }
    
    // ============================================
    // SEARCH USERS
    // ============================================
    if ($action === 'search_users') {
        $search = isset($_GET['q']) ? trim($_GET['q']) : '';
        
        if (strlen($search) < 2) {
            echo json_encode(['success' => false, 'message' => 'Search term too short']);
            exit();
        }
        
        $sql = "SELECT id, username, email, role FROM users 
                WHERE (username LIKE ? OR email LIKE ?) 
                AND id != ? 
                AND status = 'active'
                LIMIT 10";
        
        $search_term = '%' . $search . '%';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssi", $search_term, $search_term, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        
        echo json_encode(['success' => true, 'users' => $users]);
        exit();
    }
    
    // ============================================
    // REPLY TO REVIEW
    // ============================================
    if ($action === 'reply_review') {
        $review_id = isset($_POST['review_id']) ? intval($_POST['review_id']) : 0;
        $reply_text = isset($_POST['reply_text']) ? trim($_POST['reply_text']) : '';
        
        if ($review_id <= 0 || empty($reply_text)) {
            echo json_encode(['success' => false, 'message' => 'Review ID and reply text are required']);
            exit();
        }
        
        $check_sql = "SELECT sr.* FROM store_reviews sr 
                      WHERE sr.id = ? AND sr.store_id = ?";
        $stmt = $conn->prepare($check_sql);
        $stmt->bind_param("ii", $review_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'You cannot reply to this review']);
            exit();
        }
        
        $review_data = $result->fetch_assoc();
        
        $sql = "UPDATE store_reviews SET reply_text = ?, reply_date = NOW() WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $reply_text, $review_id);
        
        if ($stmt->execute()) {
            $store_name = $_SESSION['store_name'] ?? $_SESSION['username'] ?? 'Store';
            $notification_title = "Reply to your review from " . $store_name;
            $notification_message = substr($reply_text, 0, 100) . (strlen($reply_text) > 100 ? '...' : '');
            
            $notif_sql = "INSERT INTO notifications (user_id, type, title, message, link, created_at) 
                          VALUES (?, 'review_reply', ?, ?, 'dashboard.php', NOW())";
            $notif_stmt = $conn->prepare($notif_sql);
            $notif_stmt->bind_param("iss", $review_data['reviewer_id'], $notification_title, $notification_message);
            $notif_stmt->execute();
            
            echo json_encode(['success' => true, 'message' => 'Reply sent successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to save reply']);
        }
        exit();
    }
    
    // ============================================
    // MARK NOTIFICATION READ
    // ============================================
    if ($action === 'mark_notification_read') {
        $notification_id = isset($_POST['notification_id']) ? intval($_POST['notification_id']) : 0;
        
        if ($notification_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
            exit();
        }
        
        $sql = "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $notification_id, $user_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to mark notification as read']);
        }
        exit();
    }
    
    // ============================================
    // MARK ALL NOTIFICATIONS READ
    // ============================================
    if ($action === 'mark_all_notifications_read') {
        $sql = "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to mark notifications as read']);
        }
        exit();
    }
    
    // ============================================
    // UPDATE ORDER STATUS
    // ============================================
    if ($action === 'update_order_status') {
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $status = isset($_POST['status']) ? $_POST['status'] : '';
        
        $allowed_statuses = ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'completed'];
        if (!in_array($status, $allowed_statuses)) {
            echo json_encode(['success' => false, 'message' => 'Invalid status']);
            exit();
        }
        
        $check_sql = "SELECT id FROM orders WHERE id = ? AND store_id = ?";
        $stmt = $conn->prepare($check_sql);
        $stmt->bind_param("ii", $order_id, $user_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit();
        }
        
        $sql = "UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $status, $order_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update order status']);
        }
        exit();
    }
    
    // ============================================
    // GET ORDER DETAILS
    // ============================================
    if ($action === 'get_order') {
        $order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        if ($order_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
            exit();
        }
        
        $sql = "SELECT o.*, l.title as listing_title, u.username as buyer_name 
                FROM orders o 
                JOIN listings l ON o.listing_id = l.id 
                JOIN users u ON o.user_id = u.id 
                WHERE o.id = ? AND o.store_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $order_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit();
        }
        
        $order = $result->fetch_assoc();
        echo json_encode(['success' => true, 'order' => $order]);
        exit();
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'buyer';
$is_vendor = (isset($_SESSION['is_vendor']) && $_SESSION['is_vendor'] == 1);

// Normalize role fallback - if is_vendor flag is set but role wasn't saved right, treat as vendor too
$show_vendor_features = ($role === 'vendor' || $is_vendor);
$show_landlord_features = ($role === 'landlord');
$show_buyer_features = ($role === 'buyer');
$show_tenant_features = ($role === 'tenant');

$stats = [];

// ============================================
// VENDOR STATS
// ============================================
if ($show_vendor_features) {
    $listings_query = "SELECT COUNT(*) as total FROM listings WHERE user_id = ?";
    if ($stmt = $conn->prepare($listings_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stats['total_listings'] = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt->close();
    } else { $stats['total_listings'] = 0; }

    $active_query = "SELECT COUNT(*) as total FROM listings WHERE user_id = ? AND status = 'active'";
    if ($stmt = $conn->prepare($active_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stats['active_listings'] = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt->close();
    } else { $stats['active_listings'] = 0; }

    $pending_query = "SELECT COUNT(*) as total FROM listings WHERE user_id = ? AND status = 'pending'";
    if ($stmt = $conn->prepare($pending_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stats['pending_listings'] = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt->close();
    } else { $stats['pending_listings'] = 0; }

    $sold_query = "SELECT COUNT(*) as total FROM listings WHERE user_id = ? AND status = 'sold'";
    if ($stmt = $conn->prepare($sold_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stats['sold_listings'] = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt->close();
    } else { $stats['sold_listings'] = 0; }

    // ORDERS QUERY - Using store_id
    $orders_query = "SELECT COUNT(DISTINCT o.id) as total 
                     FROM orders o 
                     WHERE o.store_id = ?";
    if ($stmt = $conn->prepare($orders_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stats['total_orders'] = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt->close();
    } else { 
        $stats['total_orders'] = 0; 
    }

    // REVENUE QUERY
    $revenue_query = "SELECT COALESCE(SUM(o.total_amount), 0) as total 
                      FROM orders o 
                      WHERE o.store_id = ? AND o.status = 'completed'";
    if ($stmt = $conn->prepare($revenue_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stats['total_revenue'] = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt->close();
    } else { 
        $stats['total_revenue'] = 0; 
    }

    // RECENT ORDERS - Using store_id
    $recent_orders_query = "SELECT o.*, l.title as listing_title, u.username as buyer_name 
                            FROM orders o 
                            JOIN listings l ON o.listing_id = l.id 
                            JOIN users u ON o.user_id = u.id 
                            WHERE o.store_id = ? 
                            ORDER BY o.created_at DESC LIMIT 5";
    if ($stmt = $conn->prepare($recent_orders_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $recent_orders = $stmt->get_result();
        $stmt->close();
    } else { 
        $recent_orders = false; 
    }

    // ALL ORDERS
    $all_orders_query = "SELECT o.*, l.title as listing_title, u.username as buyer_name 
                         FROM orders o 
                         JOIN listings l ON o.listing_id = l.id 
                         JOIN users u ON o.user_id = u.id 
                         WHERE o.store_id = ? 
                         ORDER BY o.created_at DESC";
    if ($stmt = $conn->prepare($all_orders_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $all_orders = $stmt->get_result();
        $stmt->close();
    } else { 
        $all_orders = false; 
    }

    // PENDING ORDERS COUNT
    $pending_orders_query = "SELECT COUNT(*) as total 
                             FROM orders 
                             WHERE store_id = ? AND status = 'pending'";
    if ($stmt = $conn->prepare($pending_orders_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stats['pending_orders'] = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt->close();
    } else { $stats['pending_orders'] = 0; }

    $reviews_query = "SELECT COUNT(*) as total FROM store_reviews WHERE store_id = ? AND is_approved = 1";
    if ($stmt = $conn->prepare($reviews_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stats['total_reviews'] = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt->close();
    } else { $stats['total_reviews'] = 0; }

    $rating_query = "SELECT COALESCE(AVG(rating), 0) as avg_rating FROM store_reviews WHERE store_id = ? AND is_approved = 1";
    if ($stmt = $conn->prepare($rating_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stats['avg_rating'] = round($stmt->get_result()->fetch_assoc()['avg_rating'] ?? 0, 1);
        $stmt->close();
    } else { $stats['avg_rating'] = 0; }

    $views_query = "SELECT COALESCE(SUM(views_count), 0) as total_views FROM listings WHERE user_id = ?";
    if ($stmt = $conn->prepare($views_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stats['total_views'] = $stmt->get_result()->fetch_assoc()['total_views'] ?? 0;
        $stmt->close();
    } else { $stats['total_views'] = 0; }

    $top_categories_query = "SELECT l.category, COUNT(*) as count 
                             FROM listings l 
                             WHERE l.user_id = ? AND l.status = 'active'
                             GROUP BY l.category 
                             ORDER BY count DESC LIMIT 3";
    if ($stmt = $conn->prepare($top_categories_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $top_categories = $stmt->get_result();
        $stmt->close();
    } else { $top_categories = false; }

    $recent_listings_query = "SELECT * FROM listings WHERE user_id = ? ORDER BY created_at DESC LIMIT 5";
    if ($stmt = $conn->prepare($recent_listings_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $recent_listings = $stmt->get_result();
        $stmt->close();
    } else { $recent_listings = false; }

    $all_listings_query = "SELECT * FROM listings WHERE user_id = ? ORDER BY created_at DESC";
    if ($stmt = $conn->prepare($all_listings_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $all_listings = $stmt->get_result();
        $stmt->close();
    } else { $all_listings = false; }

    $all_reviews_query = "SELECT r.*, u.username as reviewer_name,
                          CASE WHEN r.reply_text IS NOT NULL AND r.reply_text != '' THEN 1 ELSE 0 END as has_reply
                          FROM store_reviews r 
                          JOIN users u ON r.reviewer_id = u.id 
                          WHERE r.store_id = ? 
                          ORDER BY r.created_at DESC";
    if ($stmt = $conn->prepare($all_reviews_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $all_reviews = $stmt->get_result();
        $stmt->close();
    } else { $all_reviews = false; }
}

// ============================================
// LANDLORD STATS
// ============================================
if ($show_landlord_features) {
    $rentals_query = "SELECT COUNT(*) as total FROM listings WHERE user_id = ? AND category = 'apartment'";
    if ($stmt = $conn->prepare($rentals_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stats['total_rentals'] = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt->close();
    } else { $stats['total_rentals'] = 0; }

    $rentals_active_query = "SELECT COUNT(*) as total FROM listings WHERE user_id = ? AND category = 'apartment' AND status = 'active'";
    if ($stmt = $conn->prepare($rentals_active_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stats['active_rentals'] = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt->close();
    } else { $stats['active_rentals'] = 0; }

    $inquiries_query = "SELECT COUNT(*) as total FROM property_inquiries pi 
                        JOIN listings l ON pi.property_id = l.id 
                        WHERE l.user_id = ?";
    if ($stmt = $conn->prepare($inquiries_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stats['total_inquiries'] = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt->close();
    } else { $stats['total_inquiries'] = 0; }

    $views_query = "SELECT COALESCE(SUM(views_count), 0) as total_views FROM listings WHERE user_id = ?";
    if ($stmt = $conn->prepare($views_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stats['total_views'] = $stmt->get_result()->fetch_assoc()['total_views'] ?? 0;
        $stmt->close();
    } else { $stats['total_views'] = 0; }

    $recent_listings_query = "SELECT * FROM listings WHERE user_id = ? ORDER BY created_at DESC LIMIT 5";
    if ($stmt = $conn->prepare($recent_listings_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $recent_listings = $stmt->get_result();
        $stmt->close();
    } else { $recent_listings = false; }

    $all_listings_query = "SELECT * FROM listings WHERE user_id = ? ORDER BY created_at DESC";
    if ($stmt = $conn->prepare($all_listings_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $all_listings = $stmt->get_result();
        $stmt->close();
    } else { $all_listings = false; }

    $all_inquiries_query = "SELECT pi.*, l.title as listing_title 
                            FROM property_inquiries pi 
                            JOIN listings l ON pi.property_id = l.id 
                            WHERE l.user_id = ? 
                            ORDER BY pi.inquiry_date DESC";
    if ($stmt = $conn->prepare($all_inquiries_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $all_inquiries = $stmt->get_result();
        $stmt->close();
    } else { $all_inquiries = false; }
}

// ============================================
// BUYER STATS
// ============================================
if ($show_buyer_features) {
    $orders_placed_query = "SELECT COUNT(*) as total FROM orders WHERE user_id = ?";
    if ($stmt = $conn->prepare($orders_placed_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stats['orders_placed'] = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt->close();
    } else { $stats['orders_placed'] = 0; }

    $spent_query = "SELECT COALESCE(SUM(total_amount), 0) as total FROM orders WHERE user_id = ? AND status = 'completed'";
    if ($stmt = $conn->prepare($spent_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stats['total_spent'] = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt->close();
    } else { $stats['total_spent'] = 0; }

    $favorites_query = "SELECT COUNT(*) as total FROM favorites WHERE user_id = ?";
    if ($stmt = $conn->prepare($favorites_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stats['favorites_count'] = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt->close();
    } else { $stats['favorites_count'] = 0; }

    $recent_orders_placed_query = "SELECT o.*, l.title as listing_title 
                                   FROM orders o 
                                   JOIN listings l ON o.listing_id = l.id 
                                   WHERE o.user_id = ? 
                                   ORDER BY o.created_at DESC LIMIT 5";
    if ($stmt = $conn->prepare($recent_orders_placed_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $recent_orders_placed = $stmt->get_result();
        $stmt->close();
    } else { $recent_orders_placed = false; }

    $all_orders_placed_query = "SELECT o.*, l.title as listing_title 
                                FROM orders o 
                                JOIN listings l ON o.listing_id = l.id 
                                WHERE o.user_id = ? 
                                ORDER BY o.created_at DESC";
    if ($stmt = $conn->prepare($all_orders_placed_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $all_orders_placed = $stmt->get_result();
        $stmt->close();
    } else { $all_orders_placed = false; }

    $favorites_list_query = "SELECT f.*, l.title, l.price, l.location, l.image, l.category 
                             FROM favorites f 
                             JOIN listings l ON f.listing_id = l.id 
                             WHERE f.user_id = ? 
                             ORDER BY f.created_at DESC";
    if ($stmt = $conn->prepare($favorites_list_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $favorites_list = $stmt->get_result();
        $stmt->close();
    } else { $favorites_list = false; }
}

// ============================================
// TENANT STATS
// ============================================
if ($show_tenant_features) {
    $rented_query = "SELECT COUNT(*) as total FROM property_inquiries WHERE user_id = ?";
    if ($stmt = $conn->prepare($rented_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stats['total_inquiries_sent'] = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt->close();
    } else { $stats['total_inquiries_sent'] = 0; }

    $favorites_query = "SELECT COUNT(*) as total FROM favorites WHERE user_id = ?";
    if ($stmt = $conn->prepare($favorites_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stats['favorites_count'] = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt->close();
    } else { $stats['favorites_count'] = 0; }

    $my_inquiries_query = "SELECT pi.*, l.title as listing_title, l.location 
                           FROM property_inquiries pi 
                           JOIN listings l ON pi.property_id = l.id 
                           WHERE pi.user_id = ? 
                           ORDER BY pi.inquiry_date DESC";
    if ($stmt = $conn->prepare($my_inquiries_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $my_inquiries = $stmt->get_result();
        $stmt->close();
    } else { $my_inquiries = false; }

    $favorites_list_query = "SELECT f.*, l.title, l.price, l.location, l.image, l.category 
                             FROM favorites f 
                             JOIN listings l ON f.listing_id = l.id 
                             WHERE f.user_id = ? 
                             ORDER BY f.created_at DESC";
    if ($stmt = $conn->prepare($favorites_list_query)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $favorites_list = $stmt->get_result();
        $stmt->close();
    } else { $favorites_list = false; }
}

// ============================================
// SHARED: Messages & Notifications
// ============================================
$messages_query = "SELECT COUNT(*) as total FROM messages WHERE receiver_id = ? AND is_read = 0";
if ($stmt = $conn->prepare($messages_query)) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stats['unread_messages'] = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt->close();
} else { $stats['unread_messages'] = 0; }

// Get conversations for display
$conversations_query = "SELECT 
                        CASE 
                            WHEN m.sender_id = ? THEN m.receiver_id 
                            ELSE m.sender_id 
                        END as other_user_id,
                        u.username as other_user_name,
                        MAX(m.created_at) as last_message_date,
                        (SELECT message FROM messages 
                         WHERE (sender_id = ? AND receiver_id = 
                                CASE WHEN m.sender_id = ? THEN m.receiver_id ELSE m.sender_id END)
                            OR (sender_id = 
                                CASE WHEN m.sender_id = ? THEN m.receiver_id ELSE m.sender_id END
                                AND receiver_id = ?)
                         ORDER BY created_at DESC LIMIT 1) as last_message,
                        (SELECT COUNT(*) FROM messages 
                         WHERE receiver_id = ? AND sender_id = 
                                CASE WHEN m.sender_id = ? THEN m.receiver_id ELSE m.sender_id END
                         AND is_read = 0) as unread_count
                        FROM messages m
                        JOIN users u ON u.id = CASE 
                            WHEN m.sender_id = ? THEN m.receiver_id 
                            ELSE m.sender_id 
                        END
                        WHERE m.sender_id = ? OR m.receiver_id = ?
                        GROUP BY other_user_id
                        ORDER BY last_message_date DESC";
if ($stmt = $conn->prepare($conversations_query)) {
    $stmt->bind_param("iiiiiiiiii", $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id);
    $stmt->execute();
    $conversations = $stmt->get_result();
    $stmt->close();
} else { $conversations = false; }

$all_messages_query = "SELECT m.*, u.username as sender_name 
                       FROM messages m 
                       JOIN users u ON m.sender_id = u.id 
                       WHERE m.receiver_id = ? 
                       ORDER BY m.created_at DESC";
if ($stmt = $conn->prepare($all_messages_query)) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $all_messages = $stmt->get_result();
    $stmt->close();
} else { $all_messages = false; }

$unread_notifications_query = "SELECT COUNT(*) as total FROM notifications WHERE user_id = ? AND is_read = 0";
if ($stmt = $conn->prepare($unread_notifications_query)) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stats['unread_notifications'] = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt->close();
} else { $stats['unread_notifications'] = 0; }

$recent_notifications_query = "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5";
if ($stmt = $conn->prepare($recent_notifications_query)) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $recent_notifications = $stmt->get_result();
    $stmt->close();
} else { $recent_notifications = false; }

// Store name (vendor / landlord)
$store_query = "SELECT store_name, store_verified FROM users WHERE id = ?";
if ($stmt = $conn->prepare($store_query)) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $store_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} else {
    $store_info = ['store_name' => null, 'store_verified' => 0];
}
$store_name = $store_info['store_name'] ?? ($_SESSION['username'] . "'s Store");
$store_verified = $store_info['store_verified'] ?? 0;

// Categories (for add-listing forms)
$categories_query = "SELECT * FROM categories WHERE status = 'active' ORDER BY name ASC";
$categories_result = $conn->query($categories_query);
$categories = [];
if ($categories_result && $categories_result->num_rows > 0) {
    while ($row = $categories_result->fetch_assoc()) {
        $categories[] = $row;
    }
}

// ============================================
// ROLE LABELS / SIDEBAR CONFIG
// ============================================
$role_labels = [
    'buyer'    => 'Buyer Dashboard',
    'vendor'   => 'Vendor Dashboard',
    'landlord' => 'Landlord Dashboard',
    'tenant'   => 'Tenant Dashboard',
];
$dashboard_title = $role_labels[$role] ?? 'Dashboard';

$can_post_listings = $show_vendor_features || $show_landlord_features;

$sidebar_items = [
    ['target' => 'dashboard',  'icon' => 'fa-tachometer-alt', 'label' => 'Dashboard',    'roles' => ['buyer','vendor','landlord','tenant'], 'badge' => null],
    ['target' => 'listings',   'icon' => 'fa-list',           'label' => 'My Listings',  'roles' => ['vendor','landlord'], 'badge' => ($stats['pending_listings'] ?? 0) > 0 ? $stats['pending_listings'] : null],
    ['target' => 'orders',     'icon' => 'fa-shopping-cart',  'label' => 'Orders',       'roles' => ['vendor'], 'badge' => ($stats['pending_orders'] ?? 0) > 0 ? $stats['pending_orders'] : null],
    ['target' => 'myorders',   'icon' => 'fa-receipt',        'label' => 'My Orders',    'roles' => ['buyer'], 'badge' => ($stats['orders_placed'] ?? 0) > 0 ? $stats['orders_placed'] : null],
    ['target' => 'inquiries',  'icon' => 'fa-comments',       'label' => 'Inquiries',    'roles' => ['landlord'], 'badge' => ($stats['total_inquiries'] ?? 0) > 0 ? $stats['total_inquiries'] : null],
    ['target' => 'myinquiries','icon' => 'fa-paper-plane',    'label' => 'My Inquiries', 'roles' => ['tenant'], 'badge' => ($stats['total_inquiries_sent'] ?? 0) > 0 ? $stats['total_inquiries_sent'] : null],
    ['target' => 'favorites',  'icon' => 'fa-heart',          'label' => 'Saved Items',  'roles' => ['buyer','tenant'], 'badge' => ($stats['favorites_count'] ?? 0) > 0 ? $stats['favorites_count'] : null],
    ['target' => 'messages',   'icon' => 'fa-envelope',       'label' => 'Messages',     'roles' => ['buyer','vendor','landlord','tenant'], 'badge' => ($stats['unread_messages'] ?? 0) > 0 ? $stats['unread_messages'] : null],
    ['target' => 'notifications','icon' => 'fa-bell',         'label' => 'Notifications','roles' => ['buyer','vendor','landlord','tenant'], 'badge' => ($stats['unread_notifications'] ?? 0) > 0 ? $stats['unread_notifications'] : null],
    ['target' => 'reviews',    'icon' => 'fa-star',           'label' => 'Reviews',      'roles' => ['vendor'], 'badge' => ($stats['total_reviews'] ?? 0) > 0 ? $stats['total_reviews'] : null],
    ['target' => 'analytics',  'icon' => 'fa-chart-line',     'label' => 'Analytics',    'roles' => ['vendor','landlord'], 'badge' => null],
    ['target' => 'settings',   'icon' => 'fa-cog',            'label' => 'Settings',     'roles' => ['buyer','vendor','landlord','tenant'], 'badge' => null],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $dashboard_title; ?> - Ebonyi Market Hub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ============================================ */
        /* ALL EXISTING STYLES REMAIN THE SAME          */
        /* ============================================ */
        :root {
            --sidebar-width: 260px;
            --primary: #ff7b00;
            --secondary: #2c3e50;
            --topbar-height: 65px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f7fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; min-height: 100vh; }

        .top-navbar {
            position: fixed; top: 0; left: 0; right: 0; height: var(--topbar-height);
            background: white; border-bottom: 1px solid #e9ecef; display: flex;
            align-items: center; justify-content: space-between; padding: 0 2rem;
            z-index: 999; box-shadow: 0 2px 4px rgba(0,0,0,0.04);
        }
        .top-navbar .navbar-brand { display: flex; align-items: center; gap: 0.75rem; font-weight: 700; color: var(--secondary); text-decoration: none; }
        .top-navbar .navbar-brand i { color: var(--primary); font-size: 1.5rem; }
        .top-navbar .navbar-brand span { font-size: 1.1rem; }
        .top-navbar .navbar-right { display: flex; align-items: center; gap: 1rem; }
        .top-navbar .navbar-right .vendor-info { display: flex; align-items: center; gap: 0.5rem; color: #6c757d; font-size: 0.9rem; }
        .top-navbar .navbar-right .vendor-info .avatar {
            width: 35px; height: 35px; border-radius: 50%; background: var(--primary); color: white;
            display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.9rem;
            overflow: hidden; background-size: cover; background-position: center;
        }
        .role-badge-nav { background: #6c757d; color: white; padding: 0.25rem 0.75rem; border-radius: 50px; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .role-badge-nav.vendor { background: #28a745; }
        .role-badge-nav.landlord { background: #007bff; }
        .role-badge-nav.tenant { background: #8e44ad; }
        .role-badge-nav.buyer { background: #ff7b00; }
        .store-badge-nav { background: #28a745; color: white; padding: 0.25rem 0.75rem; border-radius: 50px; font-size: 0.7rem; font-weight: 600; }
        .store-badge-nav.verified { background: #007bff; }
        .logout-nav-btn {
            background: #dc3545; color: white; border: none; padding: 0.5rem 1.25rem; border-radius: 8px;
            font-weight: 500; font-size: 0.9rem; display: flex; align-items: center; gap: 0.5rem;
            transition: all 0.3s ease; cursor: pointer; text-decoration: none;
        }
        .logout-nav-btn:hover { background: #c82333; color: white; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3); }
        .hamburger-btn { display: none; background: none; border: none; font-size: 1.5rem; color: var(--secondary); padding: 0.5rem; cursor: pointer; }

        .sidebar {
            position: fixed; top: var(--topbar-height); left: 0; height: calc(100vh - var(--topbar-height));
            width: var(--sidebar-width); background: var(--secondary); color: white; overflow-y: auto;
            transition: transform 0.3s ease; z-index: 1000; padding: 1rem 0;
        }
        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 10px; }
        .sidebar .nav-item {
            padding: 0.75rem 1.5rem; color: rgba(255,255,255,0.7); text-decoration: none; display: flex;
            align-items: center; gap: 0.75rem; transition: all 0.3s ease; border-left: 3px solid transparent;
            cursor: pointer; background: none; border-right: none; border-top: none; border-bottom: none;
            width: 100%; text-align: left; font-size: 1rem;
        }
        .sidebar .nav-item:hover { background: rgba(255,255,255,0.05); color: white; }
        .sidebar .nav-item.active { background: rgba(255,255,255,0.1); color: white; border-left-color: var(--primary); }
        .sidebar .nav-item i { width: 20px; text-align: center; }
        .sidebar .nav-item .badge { margin-left: auto; }

        .main-content { margin-left: var(--sidebar-width); margin-top: var(--topbar-height); padding: 2rem; min-height: calc(100vh - var(--topbar-height)); }

        .stat-card { background: white; border-radius: 15px; padding: 1.5rem; box-shadow: 0 2px 10px rgba(0,0,0,0.05); transition: all 0.3s ease; border: none; height: 100%; }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
        .stat-card .stat-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: white; margin-bottom: 1rem; }
        .stat-card .stat-number { font-size: 2rem; font-weight: 700; color: var(--secondary); }
        .stat-card .stat-label { color: #6c757d; font-size: 0.9rem; font-weight: 500; }

        .table-card { background: white; border-radius: 15px; padding: 1.5rem; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-top: 2rem; }
        .table-card h5 { font-weight: 600; margin-bottom: 1rem; }

        .sidebar-overlay { display: none; position: fixed; top: var(--topbar-height); left: 0; width: 100%; height: calc(100vh - var(--topbar-height)); background: rgba(0,0,0,0.5); z-index: 999; }
        .sidebar-overlay.show { display: block; }

        .quick-actions { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
        .quick-action-btn {
            background: white; border-radius: 12px; padding: 1rem 1.5rem; text-decoration: none; color: var(--secondary);
            display: flex; align-items: center; gap: 0.75rem; box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s ease; border: 2px solid transparent; flex: 1; min-width: 150px; justify-content: center; cursor: pointer;
        }
        .quick-action-btn:hover { border-color: var(--primary); transform: translateY(-3px); box-shadow: 0 5px 20px rgba(0,0,0,0.1); color: var(--secondary); }
        .quick-action-btn i { font-size: 1.5rem; color: var(--primary); }

        .status-badge { padding: 0.25rem 0.75rem; border-radius: 50px; font-size: 0.75rem; font-weight: 600; }
        .status-badge.active { background: #d4edda; color: #155724; }
        .status-badge.pending { background: #fff3cd; color: #856404; }
        .status-badge.sold { background: #f8d7da; color: #721c24; }
        .status-badge.inactive { background: #e2e3e5; color: #383d41; }
        .status-badge.completed { background: #d4edda; color: #155724; }
        .status-badge.processing { background: #cce5ff; color: #004085; }
        .status-badge.shipped { background: #d6d8db; color: #383d41; }
        .status-badge.cancelled { background: #f8d7da; color: #721c24; }
        .status-badge.confirmed { background: #cce5ff; color: #004085; }
        .status-badge.delivered { background: #d4edda; color: #155724; }
        .status-badge.new { background: #cce5ff; color: #004085; }
        .status-badge.contacted { background: #fff3cd; color: #856404; }
        .status-badge.closed { background: #e2e3e5; color: #383d41; }

        .modal-content { border-radius: 15px; border: none; }
        .modal-header { border-bottom: 1px solid #e9ecef; padding: 1.25rem 1.5rem; }
        .modal-header .modal-title { font-weight: 600; }
        .modal-body { padding: 1.5rem; max-height: 70vh; overflow-y: auto; }
        .modal-footer { border-top: 1px solid #e9ecef; padding: 1rem 1.5rem; }

        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; padding-bottom: 0.75rem; border-bottom: 2px solid #e9ecef; }
        .section-header h6 { font-weight: 600; margin: 0; }
        .section-header .badge { font-size: 0.8rem; }

        .message-item { padding: 1rem; border-bottom: 1px solid #e9ecef; transition: background 0.3s ease; }
        .message-item:hover { background: #f8f9fa; }
        .message-item.unread { background: #f0f7ff; border-left: 3px solid #007bff; }
        .message-item .message-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.25rem; }
        .message-item .message-sender { font-weight: 600; }
        .message-item .message-date { font-size: 0.8rem; color: #6c757d; }
        .message-item .message-preview { color: #6c757d; font-size: 0.9rem; margin: 0; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

        .notification-item { padding: 0.75rem 1rem; border-bottom: 1px solid #e9ecef; transition: background 0.3s ease; cursor: pointer; }
        .notification-item:hover { background: #f8f9fa; }
        .notification-item.unread { background: #fff8e1; border-left: 3px solid #ff7b00; }
        .notification-item .notification-header { display: flex; justify-content: space-between; align-items: center; }
        .notification-item .notification-title { font-weight: 600; font-size: 0.95rem; }
        .notification-item .notification-time { font-size: 0.75rem; color: #6c757d; }
        .notification-item .notification-message { color: #495057; font-size: 0.9rem; margin: 0.25rem 0 0; }
        .notification-item .notification-link { color: var(--primary); text-decoration: none; font-weight: 500; font-size: 0.85rem; }

        .review-item { padding: 1rem 0; border-bottom: 1px solid #e9ecef; }
        .review-item:last-child { border-bottom: none; }
        .review-item .review-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
        .review-item .review-stars { color: #f1c40f; }
        .review-item .review-text { color: #495057; margin: 0; }

        /* ============================================ */
        /* NEW STYLES FOR MESSAGES & REVIEWS            */
        /* ============================================ */
        .conversation-item {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #e9ecef;
            transition: background 0.3s ease;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .conversation-item:hover { background: #f8f9fa; }
        .conversation-item .conversation-name { font-weight: 600; }
        .conversation-item .conversation-last { font-size: 0.85rem; color: #6c757d; }
        .conversation-item .conversation-date { font-size: 0.75rem; color: #6c757d; }
        .conversation-item .conversation-click {
            flex: 1;
            cursor: pointer;
        }

        .message-thread {
            max-height: 300px;
            overflow-y: auto;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
        }
        .message-bubble {
            margin-bottom: 0.75rem;
            max-width: 85%;
        }
        .message-bubble.own {
            margin-left: auto;
        }
        .message-bubble .bubble {
            padding: 0.5rem 1rem;
            border-radius: 12px;
            display: inline-block;
            word-wrap: break-word;
        }
        .message-bubble.own .bubble {
            background: var(--primary);
            color: white;
        }
        .message-bubble.other .bubble {
            background: white;
            border: 1px solid #e9ecef;
        }
        .message-bubble .time {
            font-size: 0.7rem;
            color: #6c757d;
            margin-top: 0.15rem;
            display: block;
        }
        .message-bubble.own .time { color: rgba(255,255,255,0.7); }

        .review-item .review-reply {
            background: #f0f7ff;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-top: 0.5rem;
            border-left: 3px solid var(--primary);
        }
        .review-item .review-reply .reply-label { 
            font-weight: 600; 
            font-size: 0.85rem; 
            color: var(--primary); 
        }

        .fav-card { background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.05); transition: all 0.3s ease; height: 100%; }
        .fav-card:hover { transform: translateY(-4px); box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
        .fav-card img { width: 100%; height: 150px; object-fit: cover; }
        .fav-card .fav-body { padding: 1rem; }
        .fav-card .fav-price { color: var(--primary); font-weight: 700; }

        .order-status-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.5rem; }
        .order-status-actions .btn { font-size: 0.75rem; padding: 0.25rem 0.75rem; }

        .new-order-alert { animation: pulse 1.5s ease-in-out 3; }
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        @media (max-width: 768px) {
            .top-navbar { padding: 0 1rem; }
            .hamburger-btn { display: block; }
            .sidebar { transform: translateX(-100%); width: 280px; }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 1rem; }
            .top-navbar .navbar-right .vendor-info span { display: none; }
            .logout-nav-btn span { display: none; }
            .quick-actions { flex-direction: column; }
            .quick-action-btn { min-width: unset; }
        }

        .logout-icon { font-size: 4rem; color: #dc3545; margin-bottom: 1rem; }

        .form-group { margin-bottom: 1rem; }
        .form-group label { font-weight: 500; margin-bottom: 0.25rem; display: block; }
        .form-group .form-control, .form-group .form-select { border-radius: 8px; padding: 0.6rem 1rem; border: 1px solid #ced4da; transition: border-color 0.3s ease; }
        .form-group .form-control:focus, .form-group .form-select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(255, 123, 0, 0.1); }

        .preview-image { max-width: 100px; max-height: 100px; margin: 0.5rem; border-radius: 8px; object-fit: cover; border: 1px solid #e9ecef; }
        .image-preview-container { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.5rem; }
        .btn-remove-image { position: absolute; top: -8px; right: -8px; background: #dc3545; color: white; border: none; border-radius: 50%; width: 20px; height: 20px; font-size: 0.7rem; cursor: pointer; display: flex; align-items: center; justify-content: center; }
        .image-preview-wrapper { position: relative; display: inline-block; }

        .profile-pic-preview {
            width: 100px; height: 100px; border-radius: 50%; object-fit: cover;
            border: 3px solid #e9ecef; display: block; margin: 0 auto;
        }
        .profile-pic-wrapper { text-align: center; margin-bottom: 1rem; }
        
        /* Order ID styling */
        .order-id {
            font-weight: 700;
            color: var(--primary);
        }
        .order-id-prefix {
            color: #6c757d;
            font-weight: 400;
        }

        /* New Message Modal Styles */
        #recipientSearchResults {
            max-height: 150px;
            overflow-y: auto;
        }
        #recipientSearchResults .list-group-item {
            cursor: pointer;
            padding: 0.5rem 1rem;
        }
        #recipientSearchResults .list-group-item:hover {
            background: #f0f7ff;
        }

        .reply-btn {
            white-space: nowrap;
            margin-left: 10px;
        }
    </style>
</head>
<body>
    <!-- Top Navbar -->
    <nav class="top-navbar">
        <div class="navbar-brand">
            <button class="hamburger-btn" id="hamburger"><i class="fas fa-bars"></i></button>
            <i class="fas fa-th-large"></i>
            <span><?php echo $dashboard_title; ?></span>
        </div>

        <div class="navbar-right">
            <div class="vendor-info">
                <div class="avatar" <?php if (!empty($_SESSION['avatar_url'])): ?>style="background-image:url('<?php echo htmlspecialchars($_SESSION['avatar_url']); ?>');"<?php endif; ?>>
                    <?php if (empty($_SESSION['avatar_url'])): ?>
                        <?php echo strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <span><?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></span>
                <span class="role-badge-nav <?php echo htmlspecialchars($role); ?>"><?php echo htmlspecialchars(ucfirst($role)); ?></span>
                <?php if ($show_vendor_features || $show_landlord_features): ?>
                    <span class="store-badge-nav <?php echo $store_verified ? 'verified' : ''; ?>">
                        <i class="fas <?php echo $store_verified ? 'fa-check' : 'fa-clock'; ?>"></i>
                        <?php echo $store_verified ? 'Verified' : 'Pending'; ?>
                    </span>
                <?php endif; ?>
            </div>
            <a href="logout.php" class="logout-nav-btn" onclick="return confirmLogout(event)">
                <i class="fas fa-sign-out-alt"></i><span>Logout</span>
            </a>
        </div>
    </nav>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <a href="index.php" class="nav-item" target="_blank"><i class="fas fa-external-link-alt"></i> Home</a>
        <?php foreach ($sidebar_items as $item): ?>
            <?php if (in_array($role, $item['roles']) || ($item['target'] === 'orders' && $show_vendor_features) || ($item['target'] === 'listings' && ($show_vendor_features || $show_landlord_features))): ?>
                <button class="nav-item<?php echo $item['target'] === 'dashboard' ? ' active' : ''; ?>" data-target="<?php echo $item['target']; ?>">
                    <i class="fas <?php echo $item['icon']; ?>"></i> <?php echo $item['label']; ?>
                    <?php if (!empty($item['badge'])): ?>
                        <span class="badge bg-danger"><?php echo $item['badge']; ?></span>
                    <?php endif; ?>
                </button>
            <?php endif; ?>
        <?php endforeach; ?>
        <hr style="border-color: rgba(255,255,255,0.1); margin: 1rem 1.5rem;">
        <a href="index.php" class="nav-item" target="_blank"><i class="fas fa-external-link-alt"></i> View Site</a>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <?php if (isset($_SESSION['settings_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['settings_type'] === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($_SESSION['settings_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['settings_message'], $_SESSION['settings_type']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['order_success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo $_SESSION['order_success_message'] ?? 'Order placed successfully!'; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['order_success'], $_SESSION['order_success_message']); ?>
        <?php endif; ?>

        <div id="dashboardContent">
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                <div>
                    <h2>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!</h2>
                    <p class="text-muted mb-0">
                        <?php if ($show_vendor_features || $show_landlord_features): ?>
                            <span class="text-primary"><?php echo htmlspecialchars($store_name); ?></span>
                        <?php else: ?>
                            <?php echo ucfirst($role); ?> Account
                        <?php endif; ?>
                    </p>
                </div>
                <?php if ($can_post_listings): ?>
                    <button class="btn btn-primary" onclick="openModal('addListingModal')">
                        <i class="fas fa-plus-circle"></i> Add New Listing
                    </button>
                <?php endif; ?>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <?php if ($can_post_listings): ?>
                    <button class="quick-action-btn" onclick="openModal('addListingModal')">
                        <i class="fas fa-plus-circle"></i>
                        <div><strong>New Listing</strong><div class="small text-muted">Post a new item</div></div>
                    </button>
                <?php endif; ?>

                <?php if ($show_vendor_features): ?>
                    <button class="quick-action-btn" onclick="openModal('ordersModal')">
                        <i class="fas fa-shopping-cart"></i>
                        <div>
                            <strong>View Orders</strong>
                            <div class="small text-muted">
                                <?php echo $stats['total_orders'] ?? 0; ?> total
                                <?php if (($stats['pending_orders'] ?? 0) > 0): ?>
                                    <span class="badge bg-warning text-dark ms-1"><?php echo $stats['pending_orders']; ?> pending</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </button>
                <?php endif; ?>

                <?php if ($show_buyer_features): ?>
                    <button class="quick-action-btn" onclick="openModal('myOrdersModal')">
                        <i class="fas fa-receipt"></i>
                        <div><strong>My Orders</strong><div class="small text-muted"><?php echo $stats['orders_placed'] ?? 0; ?> orders</div></div>
                    </button>
                <?php endif; ?>

                <?php if ($show_landlord_features): ?>
                    <button class="quick-action-btn" onclick="openModal('inquiriesModal')">
                        <i class="fas fa-comments"></i>
                        <div><strong>Inquiries</strong><div class="small text-muted"><?php echo $stats['total_inquiries'] ?? 0; ?> received</div></div>
                    </button>
                <?php endif; ?>

                <?php if ($show_tenant_features): ?>
                    <button class="quick-action-btn" onclick="openModal('myInquiriesModal')">
                        <i class="fas fa-paper-plane"></i>
                        <div><strong>My Inquiries</strong><div class="small text-muted"><?php echo $stats['total_inquiries_sent'] ?? 0; ?> sent</div></div>
                    </button>
                <?php endif; ?>

                <?php if ($show_buyer_features || $show_tenant_features): ?>
                    <button class="quick-action-btn" onclick="openModal('favoritesModal')">
                        <i class="fas fa-heart"></i>
                        <div><strong>Saved Items</strong><div class="small text-muted"><?php echo $stats['favorites_count'] ?? 0; ?> saved</div></div>
                    </button>
                <?php endif; ?>

                <button class="quick-action-btn" onclick="openModal('messagesModal')">
                    <i class="fas fa-envelope"></i>
                    <div><strong>Messages</strong><div class="small text-muted"><?php echo $stats['unread_messages'] ?? 0; ?> unread</div></div>
                </button>

                <button class="quick-action-btn" onclick="openModal('notificationsModal')">
                    <i class="fas fa-bell"></i>
                    <div>
                        <strong>Notifications</strong>
                        <div class="small text-muted">
                            <?php echo $stats['unread_notifications'] ?? 0; ?> unread
                            <?php if (($stats['unread_notifications'] ?? 0) > 0): ?>
                                <span class="badge bg-danger ms-1"><?php echo $stats['unread_notifications']; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </button>

                <?php if ($show_vendor_features || $show_landlord_features): ?>
                    <button class="quick-action-btn" onclick="window.open('storefront.php?user_id=<?php echo $user_id; ?>', '_blank')">
                        <i class="fas fa-store"></i>
                        <div><strong>My Store</strong><div class="small text-muted">View storefront</div></div>
                    </button>
                <?php endif; ?>
            </div>

            <!-- ============================================ -->
            <!-- STATS: VENDOR -->
            <!-- ============================================ -->
            <?php if ($show_vendor_features): ?>
            <div class="row g-4">
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #3498db, #2980b9);"><i class="fas fa-list"></i></div>
                        <div class="stat-number"><?php echo number_format($stats['total_listings']); ?></div>
                        <div class="stat-label">Total Listings</div>
                        <small class="text-muted"><?php echo $stats['active_listings']; ?> active • <?php echo $stats['pending_listings']; ?> pending</small>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #27ae60, #229954);"><i class="fas fa-shopping-cart"></i></div>
                        <div class="stat-number"><?php echo number_format($stats['total_orders']); ?></div>
                        <div class="stat-label">Total Orders</div>
                        <small class="text-muted">
                            <?php echo $stats['sold_listings']; ?> items sold
                            <?php if (($stats['pending_orders'] ?? 0) > 0): ?>
                                <span class="badge bg-warning text-dark ms-1"><?php echo $stats['pending_orders']; ?> pending</span>
                            <?php endif; ?>
                        </small>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #ff7b00, #e66a00);"><i class="fas fa-eye"></i></div>
                        <div class="stat-number"><?php echo number_format($stats['total_views']); ?></div>
                        <div class="stat-label">Total Views</div>
                        <small class="text-muted">On your listings</small>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #8e44ad, #7d3c98);"><i class="fas fa-star"></i></div>
                        <div class="stat-number"><?php echo $stats['avg_rating'] > 0 ? number_format($stats['avg_rating'], 1) : 'N/A'; ?></div>
                        <div class="stat-label">Average Rating</div>
                        <small class="text-muted"><?php echo $stats['total_reviews']; ?> reviews</small>
                    </div>
                </div>
            </div>

            <div class="row g-4 mt-2">
                <div class="col-md-6">
                    <div class="stat-card">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div class="stat-label" style="font-size: 1rem; font-weight: 600;">Total Revenue</div>
                            <div class="stat-icon" style="background: linear-gradient(135deg, #f39c12, #e67e22); width: 40px; height: 40px; font-size: 1.2rem; margin-bottom: 0;"><i class="fas fa-money-bill-wave"></i></div>
                        </div>
                        <div class="stat-number" style="font-size: 2.5rem; color: #27ae60;">₦<?php echo number_format($stats['total_revenue']); ?></div>
                        <div class="stat-label">From completed orders</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="stat-card">
                        <div class="stat-label" style="font-size: 1rem; font-weight: 600; margin-bottom: 1rem;"><i class="fas fa-tags me-2"></i>Top Categories</div>
                        <?php if ($top_categories && $top_categories->num_rows > 0): ?>
                            <?php while ($cat = $top_categories->fetch_assoc()): ?>
                                <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                    <span><i class="fas fa-tag me-2" style="color: var(--primary);"></i><?php echo ucfirst($cat['category']); ?></span>
                                    <span class="badge bg-primary"><?php echo $cat['count']; ?> items</span>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <p class="text-muted mb-0">No categories yet. Start listing your items!</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="table-card">
                <div class="section-header">
                    <h5><i class="fas fa-list me-2"></i> Recent Listings</h5>
                    <button class="btn btn-primary btn-sm" onclick="openModal('listingsModal')">View All <i class="fas fa-arrow-right ms-2"></i></button>
                </div>
                <?php if ($recent_listings && $recent_listings->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead><tr><th>Title</th><th>Category</th><th>Price</th><th>Status</th><th>Posted</th><th>Views</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php while ($listing = $recent_listings->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(substr($listing['title'], 0, 35)) . (strlen($listing['title']) > 35 ? '...' : ''); ?></td>
                                <td><?php echo ucfirst($listing['category']); ?></td>
                                <td>₦<?php echo number_format($listing['price']); ?></td>
                                <td><span class="status-badge <?php echo $listing['status'] ?? 'inactive'; ?>"><?php echo ucfirst($listing['status'] ?? 'Inactive'); ?></span></td>
                                <td><?php echo date('M d, Y', strtotime($listing['created_at'])); ?></td>
                                <td><?php echo number_format($listing['views_count'] ?? 0); ?></td>
                                <td>
                                    <a href="property_details.php?id=<?php echo $listing['id']; ?>" class="btn btn-sm btn-outline-primary" target="_blank"><i class="fas fa-eye"></i></a>
                                    <button class="btn btn-sm btn-outline-secondary" onclick="editListing(<?php echo $listing['id']; ?>)"><i class="fas fa-edit"></i></button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                        <p class="text-muted">You haven't posted any listings yet.</p>
                        <button class="btn btn-primary" onclick="openModal('addListingModal')"><i class="fas fa-plus-circle"></i> Create Your First Listing</button>
                    </div>
                <?php endif; ?>
            </div>

            <div class="table-card">
                <div class="section-header">
                    <h5>
                        <i class="fas fa-shopping-cart me-2"></i> Recent Orders
                        <?php if (($stats['pending_orders'] ?? 0) > 0): ?>
                            <span class="badge bg-warning text-dark"><?php echo $stats['pending_orders']; ?> pending</span>
                        <?php endif; ?>
                    </h5>
                    <button class="btn btn-primary btn-sm" onclick="openModal('ordersModal')">View All <i class="fas fa-arrow-right ms-2"></i></button>
                </div>
                <?php if ($recent_orders && $recent_orders->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead><tr><th>Order #</th><th>Item</th><th>Buyer</th><th>Qty</th><th>Amount</th><th>Status</th><th>Date</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php while ($order = $recent_orders->fetch_assoc()): ?>
                            <tr class="<?php echo $order['status'] === 'pending' ? 'table-warning' : ''; ?>">
                                <td>
                                    <span class="order-id-prefix">#</span>
                                    <span class="order-id"><?php echo str_pad($order['id'], 5, '0', STR_PAD_LEFT); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars(substr($order['listing_title'], 0, 25)) . (strlen($order['listing_title']) > 25 ? '...' : ''); ?></td>
                                <td><?php echo htmlspecialchars($order['buyer_name']); ?></td>
                                <td><?php echo $order['quantity'] ?? 1; ?></td>
                                <td>₦<?php echo number_format($order['total_amount']); ?></td>
                                <td><span class="status-badge <?php echo $order['status'] ?? 'pending'; ?>"><?php echo ucfirst($order['status'] ?? 'Pending'); ?></span></td>
                                <td><?php echo date('M d, Y', strtotime($order['created_at'])); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary" onclick="viewOrder(<?php echo $order['id']; ?>)"><i class="fas fa-eye"></i></button>
                                    <div class="btn-group btn-group-sm mt-1">
                                        <?php if ($order['status'] === 'pending'): ?>
                                            <button class="btn btn-outline-success" onclick="updateOrderStatus(<?php echo $order['id']; ?>, 'confirmed')" title="Confirm"><i class="fas fa-check"></i></button>
                                        <?php endif; ?>
                                        <?php if ($order['status'] === 'confirmed'): ?>
                                            <button class="btn btn-outline-info" onclick="updateOrderStatus(<?php echo $order['id']; ?>, 'processing')" title="Process"><i class="fas fa-cogs"></i></button>
                                        <?php endif; ?>
                                        <?php if ($order['status'] === 'processing'): ?>
                                            <button class="btn btn-outline-primary" onclick="updateOrderStatus(<?php echo $order['id']; ?>, 'shipped')" title="Ship"><i class="fas fa-truck"></i></button>
                                        <?php endif; ?>
                                        <?php if ($order['status'] === 'shipped'): ?>
                                            <button class="btn btn-outline-success" onclick="updateOrderStatus(<?php echo $order['id']; ?>, 'delivered')" title="Deliver"><i class="fas fa-home"></i></button>
                                        <?php endif; ?>
                                        <?php if ($order['status'] !== 'cancelled' && $order['status'] !== 'delivered' && $order['status'] !== 'completed'): ?>
                                            <button class="btn btn-outline-danger" onclick="updateOrderStatus(<?php echo $order['id']; ?>, 'cancelled')" title="Cancel"><i class="fas fa-times"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No orders yet. When customers order your items, they'll appear here.</p>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- ============================================ -->
            <!-- STATS: LANDLORD -->
            <!-- ============================================ -->
            <?php if ($show_landlord_features): ?>
            <div class="row g-4">
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #3498db, #2980b9);"><i class="fas fa-building"></i></div>
                        <div class="stat-number"><?php echo number_format($stats['total_rentals']); ?></div>
                        <div class="stat-label">Total Rental Units</div>
                        <small class="text-muted"><?php echo $stats['active_rentals']; ?> currently active</small>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #27ae60, #229954);"><i class="fas fa-comments"></i></div>
                        <div class="stat-number"><?php echo number_format($stats['total_inquiries']); ?></div>
                        <div class="stat-label">Inquiries Received</div>
                        <small class="text-muted">From prospective tenants</small>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #ff7b00, #e66a00);"><i class="fas fa-eye"></i></div>
                        <div class="stat-number"><?php echo number_format($stats['total_views']); ?></div>
                        <div class="stat-label">Total Views</div>
                        <small class="text-muted">On your listings</small>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #8e44ad, #7d3c98);"><i class="fas fa-envelope"></i></div>
                        <div class="stat-number"><?php echo number_format($stats['unread_messages']); ?></div>
                        <div class="stat-label">Unread Messages</div>
                        <small class="text-muted">Needs your reply</small>
                    </div>
                </div>
            </div>

            <div class="table-card">
                <div class="section-header">
                    <h5><i class="fas fa-building me-2"></i> Recent Listings</h5>
                    <button class="btn btn-primary btn-sm" onclick="openModal('listingsModal')">View All <i class="fas fa-arrow-right ms-2"></i></button>
                </div>
                <?php if ($recent_listings && $recent_listings->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead><tr><th>Title</th><th>Location</th><th>Price</th><th>Status</th><th>Posted</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php while ($listing = $recent_listings->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(substr($listing['title'], 0, 35)) . (strlen($listing['title']) > 35 ? '...' : ''); ?></td>
                                <td><?php echo htmlspecialchars($listing['location']); ?></td>
                                <td>₦<?php echo number_format($listing['price']); ?></td>
                                <td><span class="status-badge <?php echo $listing['status'] ?? 'inactive'; ?>"><?php echo ucfirst($listing['status'] ?? 'Inactive'); ?></span></td>
                                <td><?php echo date('M d, Y', strtotime($listing['created_at'])); ?></td>
                                <td>
                                    <a href="property_details.php?id=<?php echo $listing['id']; ?>" class="btn btn-sm btn-outline-primary" target="_blank"><i class="fas fa-eye"></i></a>
                                    <button class="btn btn-sm btn-outline-secondary" onclick="editListing(<?php echo $listing['id']; ?>)"><i class="fas fa-edit"></i></button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-building fa-3x text-muted mb-3"></i>
                        <p class="text-muted">You haven't posted any rental units yet.</p>
                        <button class="btn btn-primary" onclick="openModal('addListingModal')"><i class="fas fa-plus-circle"></i> Post a Rental</button>
                    </div>
                <?php endif; ?>
            </div>

            <div class="table-card">
                <div class="section-header">
                    <h5><i class="fas fa-comments me-2"></i> Recent Inquiries</h5>
                    <button class="btn btn-primary btn-sm" onclick="openModal('inquiriesModal')">View All <i class="fas fa-arrow-right ms-2"></i></button>
                </div>
                <?php if ($all_inquiries && $all_inquiries->num_rows > 0): ?>
                    <?php $count = 0; while ($inq = $all_inquiries->fetch_assoc()): if ($count >= 5) break; $count++; ?>
                        <div class="message-item">
                            <div class="message-header">
                                <span class="message-sender"><i class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($inq['name']); ?></span>
                                <span class="message-date"><?php echo date('M d, Y', strtotime($inq['inquiry_date'])); ?></span>
                            </div>
                            <p class="message-preview"><strong><?php echo htmlspecialchars($inq['listing_title']); ?>:</strong> <?php echo htmlspecialchars($inq['message']); ?></p>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="text-center py-4"><i class="fas fa-comments fa-3x text-muted mb-3"></i><p class="text-muted">No inquiries yet.</p></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- ============================================ -->
            <!-- STATS: BUYER -->
            <!-- ============================================ -->
            <?php if ($show_buyer_features): ?>
            <div class="row g-4">
                <div class="col-md-4 col-sm-6">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #3498db, #2980b9);"><i class="fas fa-receipt"></i></div>
                        <div class="stat-number"><?php echo number_format($stats['orders_placed']); ?></div>
                        <div class="stat-label">Orders Placed</div>
                    </div>
                </div>
                <div class="col-md-4 col-sm-6">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #27ae60, #229954);"><i class="fas fa-money-bill-wave"></i></div>
                        <div class="stat-number">₦<?php echo number_format($stats['total_spent']); ?></div>
                        <div class="stat-label">Total Spent</div>
                    </div>
                </div>
                <div class="col-md-4 col-sm-6">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #e74c3c, #c0392b);"><i class="fas fa-heart"></i></div>
                        <div class="stat-number"><?php echo number_format($stats['favorites_count']); ?></div>
                        <div class="stat-label">Saved Items</div>
                    </div>
                </div>
            </div>

            <div class="table-card">
                <div class="section-header">
                    <h5><i class="fas fa-receipt me-2"></i> Recent Orders</h5>
                    <button class="btn btn-primary btn-sm" onclick="openModal('myOrdersModal')">View All <i class="fas fa-arrow-right ms-2"></i></button>
                </div>
                <?php if ($recent_orders_placed && $recent_orders_placed->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead><tr><th>Order #</th><th>Item</th><th>Qty</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
                        <tbody>
                            <?php while ($order = $recent_orders_placed->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <span class="order-id-prefix">#</span>
                                    <span class="order-id"><?php echo str_pad($order['id'], 5, '0', STR_PAD_LEFT); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars(substr($order['listing_title'], 0, 30)); ?></td>
                                <td><?php echo $order['quantity'] ?? 1; ?></td>
                                <td>₦<?php echo number_format($order['total_amount']); ?></td>
                                <td><span class="status-badge <?php echo $order['status'] ?? 'pending'; ?>"><?php echo ucfirst($order['status'] ?? 'Pending'); ?></span></td>
                                <td><?php echo date('M d, Y', strtotime($order['created_at'])); ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-shopping-bag fa-3x text-muted mb-3"></i>
                        <p class="text-muted">You haven't placed any orders yet.</p>
                        <a href="index.php" class="btn btn-primary"><i class="fas fa-store"></i> Start Shopping</a>
                    </div>
                <?php endif; ?>
            </div>

            <div class="table-card">
                <div class="section-header">
                    <h5><i class="fas fa-heart me-2"></i> Saved Items</h5>
                    <button class="btn btn-primary btn-sm" onclick="openModal('favoritesModal')">View All <i class="fas fa-arrow-right ms-2"></i></button>
                </div>
                <?php if ($favorites_list && $favorites_list->num_rows > 0): ?>
                    <div class="row g-3">
                        <?php $fcount = 0; while ($fav = $favorites_list->fetch_assoc()): if ($fcount >= 3) break; $fcount++; ?>
                            <div class="col-md-4">
                                <div class="fav-card">
                                    <img src="<?php echo !empty($fav['image']) ? htmlspecialchars($fav['image']) : 'https://images.unsplash.com/photo-1560518883-ce09059eeffa?w=400'; ?>" alt="<?php echo htmlspecialchars($fav['title']); ?>">
                                    <div class="fav-body">
                                        <h6><?php echo htmlspecialchars(substr($fav['title'], 0, 30)); ?></h6>
                                        <div class="fav-price">₦<?php echo number_format($fav['price']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($fav['location']); ?></small>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4"><i class="fas fa-heart fa-3x text-muted mb-3"></i><p class="text-muted">No saved items yet.</p></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- ============================================ -->
            <!-- STATS: TENANT -->
            <!-- ============================================ -->
            <?php if ($show_tenant_features): ?>
            <div class="row g-4">
                <div class="col-md-4 col-sm-6">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #3498db, #2980b9);"><i class="fas fa-paper-plane"></i></div>
                        <div class="stat-number"><?php echo number_format($stats['total_inquiries_sent']); ?></div>
                        <div class="stat-label">Inquiries Sent</div>
                    </div>
                </div>
                <div class="col-md-4 col-sm-6">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #e74c3c, #c0392b);"><i class="fas fa-heart"></i></div>
                        <div class="stat-number"><?php echo number_format($stats['favorites_count']); ?></div>
                        <div class="stat-label">Saved Properties</div>
                    </div>
                </div>
                <div class="col-md-4 col-sm-6">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #8e44ad, #7d3c98);"><i class="fas fa-envelope"></i></div>
                        <div class="stat-number"><?php echo number_format($stats['unread_messages']); ?></div>
                        <div class="stat-label">Unread Messages</div>
                    </div>
                </div>
            </div>

            <div class="table-card">
                <div class="section-header">
                    <h5><i class="fas fa-paper-plane me-2"></i> My Inquiries</h5>
                    <button class="btn btn-primary btn-sm" onclick="openModal('myInquiriesModal')">View All <i class="fas fa-arrow-right ms-2"></i></button>
                </div>
                <?php if ($my_inquiries && $my_inquiries->num_rows > 0): ?>
                    <?php $icount = 0; while ($inq = $my_inquiries->fetch_assoc()): if ($icount >= 5) break; $icount++; ?>
                        <div class="message-item">
                            <div class="message-header">
                                <span class="message-sender"><i class="fas fa-home me-2"></i><?php echo htmlspecialchars($inq['listing_title']); ?></span>
                                <span class="message-date"><?php echo date('M d, Y', strtotime($inq['inquiry_date'])); ?></span>
                            </div>
                            <p class="message-preview"><?php echo htmlspecialchars($inq['message']); ?></p>
                            <span class="status-badge <?php echo $inq['status'] ?? 'new'; ?>"><?php echo ucfirst($inq['status'] ?? 'New'); ?></span>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-paper-plane fa-3x text-muted mb-3"></i>
                        <p class="text-muted">You haven't sent any rental inquiries yet.</p>
                        <a href="rentals.php" class="btn btn-primary"><i class="fas fa-search"></i> Browse Rentals</a>
                    </div>
                <?php endif; ?>
            </div>

            <div class="table-card">
                <div class="section-header">
                    <h5><i class="fas fa-heart me-2"></i> Saved Properties</h5>
                    <button class="btn btn-primary btn-sm" onclick="openModal('favoritesModal')">View All <i class="fas fa-arrow-right ms-2"></i></button>
                </div>
                <?php if ($favorites_list && $favorites_list->num_rows > 0): ?>
                    <div class="row g-3">
                        <?php $fcount = 0; while ($fav = $favorites_list->fetch_assoc()): if ($fcount >= 3) break; $fcount++; ?>
                            <div class="col-md-4">
                                <div class="fav-card">
                                    <img src="<?php echo !empty($fav['image']) ? htmlspecialchars($fav['image']) : 'https://images.unsplash.com/photo-1545324418-cc1a3fa10c00?w=400'; ?>" alt="<?php echo htmlspecialchars($fav['title']); ?>">
                                    <div class="fav-body">
                                        <h6><?php echo htmlspecialchars(substr($fav['title'], 0, 30)); ?></h6>
                                        <div class="fav-price">₦<?php echo number_format($fav['price']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($fav['location']); ?></small>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4"><i class="fas fa-heart fa-3x text-muted mb-3"></i><p class="text-muted">No saved properties yet.</p></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- ============================================ -->
    <!-- MODALS -->
    <!-- ============================================ -->

    <?php if ($can_post_listings): ?>
    <!-- Add Listing Modal -->
    <div class="modal fade" id="addListingModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle text-success me-2"></i>Add New Listing</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="post_listing.php" id="addListingForm" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <div class="form-group">
                                    <label>Category <span class="text-danger">*</span></label>
                                    <select class="form-select" name="category" id="listingCategory" required>
                                        <option value="">Select Category</option>
                                        <?php if (!empty($categories)): ?>
                                            <?php foreach ($categories as $category): ?>
                                                <option value="<?php echo htmlspecialchars($category['name']); ?>">
                                                    <?php echo htmlspecialchars($category['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="property">Property</option>
                                            <option value="land">Land</option>
                                            <option value="apartment">Apartment for Rent</option>
                                            <option value="item">Used Item</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="form-group">
                                    <label>Title <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="title" required placeholder="E.g., 3-Bedroom House, iPhone 12, Land for Sale">
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="form-group">
                                    <label>Description <span class="text-danger">*</span></label>
                                    <textarea class="form-control" name="description" rows="3" required placeholder="Provide detailed description..."></textarea>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Price (₦) <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" name="price" required step="0.01" placeholder="0.00">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Location <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="location" required placeholder="City, State">
                                </div>
                            </div>

                            <div id="propertyFields" style="display: none;">
                                <div class="row">
                                    <div class="col-md-4"><div class="form-group"><label>Bedrooms</label><input type="number" class="form-control" name="bedrooms" placeholder="e.g., 3"></div></div>
                                    <div class="col-md-4"><div class="form-group"><label>Bathrooms</label><input type="number" class="form-control" name="bathrooms" placeholder="e.g., 2"></div></div>
                                    <div class="col-md-4"><div class="form-group"><label>Area (sq.m)</label><input type="number" step="0.01" class="form-control" name="area" placeholder="e.g., 150"></div></div>
                                </div>
                            </div>

                            <div id="itemFields" style="display: none;">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>Item Condition</label>
                                            <select class="form-select" name="condition">
                                                <option value="new">New</option>
                                                <option value="used" selected>Used - Good</option>
                                                <option value="fair">Used - Fair</option>
                                                <option value="refurbished">Refurbished</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6"><div class="form-group"><label>Brand/Make</label><input type="text" class="form-control" name="brand" placeholder="e.g., Toyota, Samsung"></div></div>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="form-group">
                                    <label>Upload Images</label>
                                    <input type="file" class="form-control" name="images[]" multiple accept="image/*" id="imageInput">
                                    <small class="text-muted">You can upload multiple images (Max 5)</small>
                                    <div class="image-preview-container" id="imagePreviewContainer"></div>
                                </div>
                            </div>

                            <div class="col-12">
                                <h6 class="mt-2 mb-3">Contact Information</h6>
                                <div class="row">
                                    <div class="col-md-4 mb-2">
                                        <input type="text" name="contact_name" class="form-control" placeholder="Contact Name *" required
                                               value="<?php echo htmlspecialchars(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')); ?>">
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <input type="text" name="contact_phone" class="form-control" placeholder="Phone *" required
                                               value="<?php echo htmlspecialchars($_SESSION['phone'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <input type="email" name="contact_email" class="form-control" placeholder="Email (Optional)"
                                               value="<?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cancel</button>
                        <button type="submit" name="post_ad" class="btn btn-success"><i class="fas fa-check-circle"></i> Post Listing</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Listings Modal -->
    <div class="modal fade" id="listingsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-list text-primary me-2"></i>All My Listings</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if ($all_listings && $all_listings->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead><tr><th>Title</th><th>Category</th><th>Price</th><th>Status</th><th>Posted</th><th>Views</th><th>Action</th></tr></thead>
                            <tbody>
                                <?php while ($listing = $all_listings->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(substr($listing['title'], 0, 40)); ?></td>
                                    <td><?php echo ucfirst($listing['category']); ?></td>
                                    <td>₦<?php echo number_format($listing['price']); ?></td>
                                    <td><span class="status-badge <?php echo $listing['status'] ?? 'inactive'; ?>"><?php echo ucfirst($listing['status'] ?? 'Inactive'); ?></span></td>
                                    <td><?php echo date('M d, Y', strtotime($listing['created_at'])); ?></td>
                                    <td><?php echo number_format($listing['views_count'] ?? 0); ?></td>
                                    <td>
                                        <a href="property_details.php?id=<?php echo $listing['id']; ?>" class="btn btn-sm btn-outline-primary" target="_blank"><i class="fas fa-eye"></i></a>
                                        <button class="btn btn-sm btn-outline-secondary" onclick="editListing(<?php echo $listing['id']; ?>)"><i class="fas fa-edit"></i></button>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                            <p class="text-muted">You haven't posted any listings yet.</p>
                            <button class="btn btn-primary" onclick="closeModal('listingsModal'); openModal('addListingModal')"><i class="fas fa-plus-circle"></i> Add New Listing</button>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
            </div>
        </div>
    </div>

    <!-- Edit Listing Modal -->
    <div class="modal fade" id="editListingModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit text-primary me-2"></i>Edit Listing</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="editListingContent">
                    <div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveListing()"><i class="fas fa-save"></i> Save Changes</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($show_vendor_features): ?>
    <!-- Orders Modal (received, vendor) -->
    <div class="modal fade" id="ordersModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-shopping-cart text-primary me-2"></i>All Orders
                        <?php if (($stats['pending_orders'] ?? 0) > 0): ?>
                            <span class="badge bg-warning text-dark"><?php echo $stats['pending_orders']; ?> pending</span>
                        <?php endif; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if ($all_orders && $all_orders->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead><tr><th>Order #</th><th>Item</th><th>Buyer</th><th>Qty</th><th>Amount</th><th>Status</th><th>Date</th><th>Action</th></tr></thead>
                            <tbody>
                                <?php while ($order = $all_orders->fetch_assoc()): ?>
                                <tr class="<?php echo $order['status'] === 'pending' ? 'table-warning' : ''; ?>">
                                    <td>
                                        <span class="order-id-prefix">#</span>
                                        <span class="order-id"><?php echo str_pad($order['id'], 5, '0', STR_PAD_LEFT); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars(substr($order['listing_title'], 0, 30)); ?></td>
                                    <td><?php echo htmlspecialchars($order['buyer_name']); ?></td>
                                    <td><?php echo $order['quantity'] ?? 1; ?></td>
                                    <td>₦<?php echo number_format($order['total_amount']); ?></td>
                                    <td><span class="status-badge <?php echo $order['status'] ?? 'pending'; ?>"><?php echo ucfirst($order['status'] ?? 'Pending'); ?></span></td>
                                    <td><?php echo date('M d, Y', strtotime($order['created_at'])); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" onclick="viewOrder(<?php echo $order['id']; ?>)"><i class="fas fa-eye"></i></button>
                                        <div class="btn-group btn-group-sm mt-1">
                                            <?php if ($order['status'] === 'pending'): ?>
                                                <button class="btn btn-outline-success" onclick="updateOrderStatus(<?php echo $order['id']; ?>, 'confirmed')" title="Confirm"><i class="fas fa-check"></i></button>
                                            <?php endif; ?>
                                            <?php if ($order['status'] === 'confirmed'): ?>
                                                <button class="btn btn-outline-info" onclick="updateOrderStatus(<?php echo $order['id']; ?>, 'processing')" title="Process"><i class="fas fa-cogs"></i></button>
                                            <?php endif; ?>
                                            <?php if ($order['status'] === 'processing'): ?>
                                                <button class="btn btn-outline-primary" onclick="updateOrderStatus(<?php echo $order['id']; ?>, 'shipped')" title="Ship"><i class="fas fa-truck"></i></button>
                                            <?php endif; ?>
                                            <?php if ($order['status'] === 'shipped'): ?>
                                                <button class="btn btn-outline-success" onclick="updateOrderStatus(<?php echo $order['id']; ?>, 'delivered')" title="Deliver"><i class="fas fa-home"></i></button>
                                            <?php endif; ?>
                                            <?php if ($order['status'] !== 'cancelled' && $order['status'] !== 'delivered' && $order['status'] !== 'completed'): ?>
                                                <button class="btn btn-outline-danger" onclick="updateOrderStatus(<?php echo $order['id']; ?>, 'cancelled')" title="Cancel"><i class="fas fa-times"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No orders yet. When customers order your items, they'll appear here.</p>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
            </div>
        </div>
    </div>

    <!-- Reviews Modal -->
    <div class="modal fade" id="reviewsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-star text-warning me-2"></i>Reviews</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if ($all_reviews && $all_reviews->num_rows > 0): ?>
                        <?php while ($review = $all_reviews->fetch_assoc()): ?>
                            <div class="review-item">
                                <div class="review-header">
                                    <div>
                                        <strong><?php echo htmlspecialchars($review['reviewer_name']); ?></strong>
                                        <span class="text-muted ms-2" style="font-size: 0.85rem;"><?php echo date('M d, Y', strtotime($review['created_at'])); ?></span>
                                    </div>
                                    <div class="review-stars">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star" style="color: <?php echo $i <= $review['rating'] ? '#f1c40f' : '#ddd'; ?>;"></i>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                                <p class="review-text"><?php echo htmlspecialchars($review['review_text'] ?? ''); ?></p>
                                <?php if ($review['has_reply']): ?>
                                    <div class="review-reply">
                                        <div class="reply-label"><i class="fas fa-reply me-1"></i>Your Reply:</div>
                                        <p class="mb-0"><?php echo htmlspecialchars($review['reply_text']); ?></p>
                                        <small class="text-muted"><?php echo date('M d, Y', strtotime($review['reply_date'])); ?></small>
                                    </div>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-outline-primary mt-2" onclick="openReviewReply(<?php echo $review['id']; ?>, '<?php echo htmlspecialchars($review['reviewer_name']); ?>', <?php echo $review['rating']; ?>, '<?php echo htmlspecialchars(addslashes($review['review_text'])); ?>')">
                                        <i class="fas fa-reply"></i> Reply to Review
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="text-center py-4"><i class="fas fa-star fa-3x text-muted mb-3"></i><p class="text-muted">No reviews yet.</p></div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
            </div>
        </div>
    </div>

    <!-- Order Details Modal -->
    <div class="modal fade" id="orderDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-invoice text-primary me-2"></i>Order Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="orderDetailsContent">
                    <div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <div id="orderStatusActions" class="order-status-actions"></div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($show_buyer_features): ?>
    <!-- My Orders Modal (buyer) -->
    <div class="modal fade" id="myOrdersModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-receipt text-primary me-2"></i>My Orders</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if ($all_orders_placed && $all_orders_placed->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead><tr><th>Order #</th><th>Item</th><th>Qty</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
                            <tbody>
                                <?php while ($order = $all_orders_placed->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <span class="order-id-prefix">#</span>
                                        <span class="order-id"><?php echo str_pad($order['id'], 5, '0', STR_PAD_LEFT); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars(substr($order['listing_title'], 0, 30)); ?></td>
                                    <td><?php echo $order['quantity'] ?? 1; ?></td>
                                    <td>₦<?php echo number_format($order['total_amount']); ?></td>
                                    <td><span class="status-badge <?php echo $order['status'] ?? 'pending'; ?>"><?php echo ucfirst($order['status'] ?? 'Pending'); ?></span></td>
                                    <td><?php echo date('M d, Y', strtotime($order['created_at'])); ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                        <div class="text-center py-4"><i class="fas fa-shopping-bag fa-3x text-muted mb-3"></i><p class="text-muted">No orders yet.</p></div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($show_landlord_features): ?>
    <!-- Inquiries Modal (landlord, received) -->
    <div class="modal fade" id="inquiriesModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-comments text-primary me-2"></i>Inquiries Received</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if ($all_inquiries && $all_inquiries->num_rows > 0): ?>
                        <?php while ($inq = $all_inquiries->fetch_assoc()): ?>
                            <div class="message-item">
                                <div class="message-header">
                                    <span class="message-sender"><i class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($inq['name']); ?> (<?php echo htmlspecialchars($inq['phone']); ?>)</span>
                                    <span class="message-date"><?php echo date('M d, Y', strtotime($inq['inquiry_date'])); ?></span>
                                </div>
                                <p class="message-preview"><strong><?php echo htmlspecialchars($inq['listing_title']); ?>:</strong> <?php echo htmlspecialchars($inq['message']); ?></p>
                                <span class="status-badge <?php echo $inq['status'] ?? 'new'; ?>"><?php echo ucfirst($inq['status'] ?? 'New'); ?></span>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="text-center py-4"><i class="fas fa-comments fa-3x text-muted mb-3"></i><p class="text-muted">No inquiries yet.</p></div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($show_tenant_features): ?>
    <!-- My Inquiries Modal (tenant, sent) -->
    <div class="modal fade" id="myInquiriesModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-paper-plane text-primary me-2"></i>My Inquiries</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if ($my_inquiries && $my_inquiries->num_rows > 0): ?>
                        <?php while ($inq = $my_inquiries->fetch_assoc()): ?>
                            <div class="message-item">
                                <div class="message-header">
                                    <span class="message-sender"><i class="fas fa-home me-2"></i><?php echo htmlspecialchars($inq['listing_title']); ?></span>
                                    <span class="message-date"><?php echo date('M d, Y', strtotime($inq['inquiry_date'])); ?></span>
                                </div>
                                <p class="message-preview"><?php echo htmlspecialchars($inq['message']); ?></p>
                                <span class="status-badge <?php echo $inq['status'] ?? 'new'; ?>"><?php echo ucfirst($inq['status'] ?? 'New'); ?></span>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="text-center py-4"><i class="fas fa-paper-plane fa-3x text-muted mb-3"></i><p class="text-muted">No inquiries sent yet.</p></div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($show_buyer_features || $show_tenant_features): ?>
    <!-- Favorites Modal -->
    <div class="modal fade" id="favoritesModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-heart text-danger me-2"></i>Saved Items</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if ($favorites_list && $favorites_list->num_rows > 0): ?>
                        <div class="row g-3">
                            <?php while ($fav = $favorites_list->fetch_assoc()): ?>
                                <div class="col-md-4">
                                    <div class="fav-card">
                                        <img src="<?php echo !empty($fav['image']) ? htmlspecialchars($fav['image']) : 'https://images.unsplash.com/photo-1560518883-ce09059eeffa?w=400'; ?>" alt="<?php echo htmlspecialchars($fav['title']); ?>">
                                        <div class="fav-body">
                                            <h6><?php echo htmlspecialchars(substr($fav['title'], 0, 30)); ?></h6>
                                            <div class="fav-price">₦<?php echo number_format($fav['price']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($fav['location']); ?></small>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4"><i class="fas fa-heart fa-3x text-muted mb-3"></i><p class="text-muted">No saved items yet.</p></div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Messages Modal (everyone) - WITH REPLY BUTTON -->
    <div class="modal fade" id="messagesModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-envelope text-primary me-2"></i>Messages
                        <?php if (($stats['unread_messages'] ?? 0) > 0): ?>
                            <span class="badge bg-danger ms-2"><?php echo $stats['unread_messages']; ?> unread</span>
                        <?php endif; ?>
                    </h5>
                    <div>
                        <button class="btn btn-sm btn-success me-2" onclick="openNewMessageModal()">
                            <i class="fas fa-plus"></i> New Message
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                </div>
                <div class="modal-body" id="messagesModalBody">
                    <div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="loadConversations()"><i class="fas fa-sync"></i> Refresh</button>
                </div>
            </div>
        </div>
    </div>

    <!-- New Message Modal -->
    <div class="modal fade" id="newMessageModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-pen text-primary me-2"></i>Send New Message</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="form-group mb-3">
                        <label>Recipient</label>
                        <input type="text" class="form-control" id="newMessageRecipient" placeholder="Search for user..." autocomplete="off">
                        <div id="recipientSearchResults" class="list-group mt-2" style="max-height: 150px; overflow-y: auto; display: none;"></div>
                        <input type="hidden" id="newMessageRecipientId" value="">
                    </div>
                    <div class="form-group">
                        <label>Message</label>
                        <textarea class="form-control" id="newMessageText" rows="4" placeholder="Type your message here..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="sendNewMessage()">
                        <i class="fas fa-paper-plane"></i> Send
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Message Reply Modal -->
    <div class="modal fade" id="messageReplyModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-reply text-primary me-2"></i>Reply to <span id="replyRecipientName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="message-thread" id="conversationThread">
                        <!-- Messages will be loaded here -->
                    </div>
                    <div class="form-group mt-3">
                        <label>Your Reply</label>
                        <textarea class="form-control" id="replyMessageText" rows="3" placeholder="Type your reply here..."></textarea>
                    </div>
                    <input type="hidden" id="replyReceiverId" value="">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="sendReply()">
                        <i class="fas fa-paper-plane"></i> Send Reply
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Review Reply Modal -->
    <div class="modal fade" id="reviewReplyModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-reply text-primary me-2"></i>Reply to Review</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="card mb-3" style="background: #f8f9fa;">
                        <div class="card-body">
                            <p><strong>Reviewer:</strong> <span id="reviewReplyReviewer"></span></p>
                            <p><strong>Rating:</strong> <span id="reviewReplyRating"></span></p>
                            <p><strong>Review:</strong> <span id="reviewReplyText"></span></p>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Your Reply</label>
                        <textarea class="form-control" id="reviewReplyTextArea" rows="3" placeholder="Reply to this review..."></textarea>
                    </div>
                    <input type="hidden" id="reviewReplyId" value="">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="sendReviewReply()">
                        <i class="fas fa-paper-plane"></i> Send Reply
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================ -->
    <!-- NOTIFICATIONS MODAL -->
    <!-- ============================================ -->
    <div class="modal fade" id="notificationsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-bell text-primary me-2"></i>Notifications
                        <?php if (($stats['unread_notifications'] ?? 0) > 0): ?>
                            <span class="badge bg-danger ms-2"><?php echo $stats['unread_notifications']; ?> unread</span>
                        <?php endif; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if ($recent_notifications && $recent_notifications->num_rows > 0): ?>
                        <?php while ($notif = $recent_notifications->fetch_assoc()): ?>
                            <div class="notification-item <?php echo $notif['is_read'] == 0 ? 'unread' : ''; ?>" onclick="markNotificationRead(<?php echo $notif['id']; ?>)">
                                <div class="notification-header">
                                    <span class="notification-title">
                                        <i class="fas <?php echo $notif['type'] === 'new_order' ? 'fa-shopping-cart text-success' : 'fa-bell text-primary'; ?> me-2"></i>
                                        <?php echo htmlspecialchars($notif['title']); ?>
                                        <?php if ($notif['is_read'] == 0): ?>
                                            <span class="badge bg-primary ms-2">New</span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="notification-time"><?php echo date('M d, Y g:i A', strtotime($notif['created_at'])); ?></span>
                                </div>
                                <p class="notification-message"><?php echo htmlspecialchars($notif['message']); ?></p>
                                <?php if (!empty($notif['link'])): ?>
                                    <a href="<?php echo htmlspecialchars($notif['link']); ?>" class="notification-link">View Details <i class="fas fa-arrow-right ms-1"></i></a>
                                <?php endif; ?>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-bell-slash fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No notifications yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <?php if (($stats['unread_notifications'] ?? 0) > 0): ?>
                        <button class="btn btn-primary btn-sm" onclick="markAllNotificationsRead()">
                            <i class="fas fa-check-double me-2"></i>Mark All as Read
                        </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <?php if ($show_vendor_features || $show_landlord_features): ?>
    <!-- Analytics Modal -->
    <div class="modal fade" id="analyticsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-chart-line text-primary me-2"></i>Analytics</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="analyticsContent">
                    <div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Settings Modal (everyone) -->
    <div class="modal fade" id="settingsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-cog text-primary me-2"></i>Account Settings</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="update_settings.php" enctype="multipart/form-data">
                    <div class="modal-body">
                        <!-- Profile Picture Upload -->
                        <div class="profile-pic-wrapper">
                            <img src="<?php echo !empty($_SESSION['avatar_url']) ? htmlspecialchars($_SESSION['avatar_url']) : 'https://via.placeholder.com/100x100.png?text=' . strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1)); ?>"
                                 alt="Profile Picture" id="profilePicPreview" class="profile-pic-preview">
                            <div class="form-group mt-2" style="max-width: 280px; margin: 0.5rem auto 0;">
                                <input type="file" class="form-control form-control-sm" name="avatar_url" id="profilePicInput" accept="image/*">
                                <small class="text-muted">JPG, PNG, GIF or WEBP. Max 2MB.</small>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>First Name</label>
                            <input type="text" class="form-control" name="first_name" value="<?php echo htmlspecialchars($_SESSION['first_name'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Last Name</label>
                            <input type="text" class="form-control" name="last_name" value="<?php echo htmlspecialchars($_SESSION['last_name'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Phone</label>
                            <input type="text" class="form-control" name="phone" value="<?php echo htmlspecialchars($_SESSION['phone'] ?? ''); ?>">
                        </div>

                        <?php if ($show_vendor_features || $show_landlord_features): ?>
                            <hr>
                            <h6 class="mb-3"><i class="fas fa-store me-2"></i>Store Settings</h6>
                            <div class="form-group">
                                <label>Store Name</label>
                                <input type="text" class="form-control" name="store_name" value="<?php echo htmlspecialchars($store_name); ?>">
                            </div>
                            <div class="form-group">
                                <label>Store Slogan</label>
                                <input type="text" class="form-control" name="store_slogan" placeholder="Your store slogan">
                            </div>
                            <div class="form-group">
                                <label>Store Description</label>
                                <textarea class="form-control" name="store_description" rows="3" placeholder="Tell customers about your store"></textarea>
                            </div>
                        <?php endif; ?>

                        <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Settings</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Logout Confirmation Modal -->
    <div class="modal fade" id="logoutConfirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-sign-out-alt" style="color: #dc3545;"></i> Confirm Logout</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <div class="logout-icon"><i class="fas fa-user-shield"></i></div>
                    <h5>Are you sure you want to logout?</h5>
                    <p class="text-muted">You will be redirected to the login page.</p>
                </div>
                <div class="modal-footer justify-content-center gap-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cancel</button>
                    <a href="logout.php" class="btn btn-danger"><i class="fas fa-sign-out-alt"></i> Yes, Logout</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ============================================
        // NAVIGATION
        // ============================================
        const navItems = document.querySelectorAll('.nav-item[data-target]');

        navItems.forEach(item => {
            item.addEventListener('click', function() {
                navItems.forEach(n => n.classList.remove('active'));
                this.classList.add('active');

                const target = this.getAttribute('data-target');

                if (window.innerWidth <= 768) {
                    toggleSidebar();
                }

                switch(target) {
                    case 'dashboard':
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                        break;
                    case 'listings':
                        openModal('listingsModal');
                        break;
                    case 'orders':
                        openModal('ordersModal');
                        break;
                    case 'myorders':
                        openModal('myOrdersModal');
                        break;
                    case 'inquiries':
                        openModal('inquiriesModal');
                        break;
                    case 'myinquiries':
                        openModal('myInquiriesModal');
                        break;
                    case 'favorites':
                        openModal('favoritesModal');
                        break;
                    case 'messages':
                        openModal('messagesModal');
                        loadConversations();
                        break;
                    case 'notifications':
                        openModal('notificationsModal');
                        break;
                    case 'reviews':
                        openModal('reviewsModal');
                        break;
                    case 'analytics':
                        openModal('analyticsModal');
                        loadAnalytics();
                        break;
                    case 'settings':
                        openModal('settingsModal');
                        break;
                    default:
                        break;
                }
            });
        });

        // ============================================
        // MODAL FUNCTIONS
        // ============================================
        function openModal(modalId) {
            const el = document.getElementById(modalId);
            if (!el) return;
            const modal = new bootstrap.Modal(el);
            modal.show();
        }

        function closeModal(modalId) {
            const el = document.getElementById(modalId);
            if (!el) return;
            const modal = bootstrap.Modal.getInstance(el);
            if (modal) modal.hide();
        }

        // ============================================
        // SIDEBAR TOGGLE
        // ============================================
        const hamburger = document.getElementById('hamburger');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');

        function toggleSidebar() {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('show');
            document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
        }

        hamburger.addEventListener('click', toggleSidebar);
        overlay.addEventListener('click', toggleSidebar);

        window.addEventListener('resize', function() {
            if (window.innerWidth > 768 && sidebar.classList.contains('open')) {
                sidebar.classList.remove('open');
                overlay.classList.remove('show');
                document.body.style.overflow = '';
            }
        });

        // ============================================
        // LOGOUT
        // ============================================
        function confirmLogout(event) {
            event.preventDefault();
            const modal = new bootstrap.Modal(document.getElementById('logoutConfirmModal'));
            modal.show();
            return false;
        }

        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.shiftKey && (e.key === 'l' || e.key === 'L')) {
                e.preventDefault();
                const modal = new bootstrap.Modal(document.getElementById('logoutConfirmModal'));
                modal.show();
            }
        });

        // ============================================
        // IMAGE PREVIEW
        // ============================================
        document.getElementById('imageInput')?.addEventListener('change', function(e) {
            const container = document.getElementById('imagePreviewContainer');
            container.innerHTML = '';
            if (this.files) {
                Array.from(this.files).forEach(file => {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const wrapper = document.createElement('div');
                        wrapper.className = 'image-preview-wrapper';
                        const img = document.createElement('img');
                        img.src = e.target.result;
                        img.className = 'preview-image';
                        const removeBtn = document.createElement('button');
                        removeBtn.className = 'btn-remove-image';
                        removeBtn.innerHTML = '×';
                        removeBtn.onclick = function() { wrapper.remove(); };
                        wrapper.appendChild(img);
                        wrapper.appendChild(removeBtn);
                        container.appendChild(wrapper);
                    };
                    reader.readAsDataURL(file);
                });
            }
        });

        // Profile picture live preview in Settings modal
        document.getElementById('profilePicInput')?.addEventListener('change', function(e) {
            const file = this.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('profilePicPreview').src = e.target.result;
                };
                reader.readAsDataURL(file);
            }
        });

        // ============================================
        // CATEGORY CHANGE HANDLER
        // ============================================
        document.getElementById('listingCategory')?.addEventListener('change', function() {
            const propertyFields = document.getElementById('propertyFields');
            const itemFields = document.getElementById('itemFields');
            if (propertyFields) propertyFields.style.display = 'none';
            if (itemFields) itemFields.style.display = 'none';
            
            const category = this.value.toLowerCase();
            if (category === 'property' || category === 'apartment' || category === 'land') {
                if (propertyFields) propertyFields.style.display = 'block';
            } else if (category === 'item' || category === 'vehicle' || category === 'electronics' || category === 'furniture') {
                if (itemFields) itemFields.style.display = 'block';
            }
        });

        // ============================================
        // EDIT LISTING
        // ============================================
        function editListing(listingId) {
            openModal('editListingModal');
            fetch(`get_listing.php?id=${listingId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const content = document.getElementById('editListingContent');
                        content.innerHTML = `
                            <div class="row g-3">
                                <input type="hidden" name="listing_id" value="${data.listing.id}">
                                <div class="col-12"><div class="form-group"><label>Title</label><input type="text" class="form-control" id="editTitle" value="${data.listing.title}" required></div></div>
                                <div class="col-12"><div class="form-group"><label>Description</label><textarea class="form-control" id="editDescription" rows="3">${data.listing.description}</textarea></div></div>
                                <div class="col-md-6"><div class="form-group"><label>Price (₦)</label><input type="number" class="form-control" id="editPrice" value="${data.listing.price}" step="0.01"></div></div>
                                <div class="col-md-6"><div class="form-group"><label>Location</label><input type="text" class="form-control" id="editLocation" value="${data.listing.location}"></div></div>
                                <div class="col-md-6"><div class="form-group"><label>Status</label>
                                    <select class="form-select" id="editStatus">
                                        <option value="active" ${data.listing.status === 'active' ? 'selected' : ''}>Active</option>
                                        <option value="pending" ${data.listing.status === 'pending' ? 'selected' : ''}>Pending</option>
                                        <option value="sold" ${data.listing.status === 'sold' ? 'selected' : ''}>Sold</option>
                                        <option value="inactive" ${data.listing.status === 'inactive' ? 'selected' : ''}>Inactive</option>
                                    </select>
                                </div></div>
                                <div class="col-md-6"><div class="form-group"><label>Category</label><input type="text" class="form-control" value="${data.listing.category}" disabled></div></div>
                            </div>`;
                    } else {
                        document.getElementById('editListingContent').innerHTML = `<div class="alert alert-danger">${data.message || 'Failed to load listing'}</div>`;
                    }
                })
                .catch(() => {
                    document.getElementById('editListingContent').innerHTML = `<div class="alert alert-danger">Error loading listing</div>`;
                });
        }

        function saveListing() {
            const listingId = document.querySelector('input[name="listing_id"]')?.value;
            const title = document.getElementById('editTitle')?.value;
            const description = document.getElementById('editDescription')?.value;
            const price = document.getElementById('editPrice')?.value;
            const location = document.getElementById('editLocation')?.value;
            const status = document.getElementById('editStatus')?.value;

            if (!listingId) { alert('Listing ID not found'); return; }

            fetch('update_listing.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `id=${listingId}&title=${encodeURIComponent(title)}&description=${encodeURIComponent(description)}&price=${price}&location=${encodeURIComponent(location)}&status=${status}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Listing updated successfully!');
                    closeModal('editListingModal');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(() => alert('Error updating listing'));
        }

        // ============================================
        // MESSAGES FUNCTIONS - WITH REPLY BUTTON
        // ============================================
        function loadConversations() {
            const container = document.getElementById('messagesModalBody');
            if (container) {
                container.innerHTML = `<div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>`;
            }
            
            fetch('dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: 'action=get_conversations'
            })
            .then(response => response.json())
            .then(data => {
                const targetContainer = container || document.getElementById('conversationsContainer');
                if (!targetContainer) return;
                
                if (data.success && data.conversations && data.conversations.length > 0) {
                    let html = '';
                    data.conversations.forEach(conv => {
                        const unreadBadge = conv.unread_count > 0 ? `<span class="badge bg-danger ms-2">${conv.unread_count} new</span>` : '';
                        html += `
                            <div class="conversation-item">
                                <div class="conversation-click" onclick="openConversation(${conv.other_user_id}, '${conv.other_user_name}')">
                                    <div class="conversation-name">
                                        <i class="fas fa-user-circle me-2"></i>${conv.other_user_name}
                                        ${unreadBadge}
                                    </div>
                                    <div class="conversation-last">${conv.last_message ? conv.last_message.substring(0, 60) + (conv.last_message.length > 60 ? '...' : '') : 'No messages'}</div>
                                    <div class="conversation-date">${new Date(conv.last_message_date).toLocaleDateString()}</div>
                                </div>
                                <button class="btn btn-sm btn-primary reply-btn" onclick="event.stopPropagation(); openConversation(${conv.other_user_id}, '${conv.other_user_name}')" title="Reply to ${conv.other_user_name}">
                                    <i class="fas fa-reply"></i> Reply
                                </button>
                            </div>
                        `;
                    });
                    targetContainer.innerHTML = html;
                } else {
                    targetContainer.innerHTML = `
                        <div class="text-center py-4">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No conversations yet.</p>
                            <small class="text-muted">Messages from other users will appear here.</small>
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Error loading conversations:', error);
                const targetContainer = container || document.getElementById('conversationsContainer');
                if (targetContainer) {
                    targetContainer.innerHTML = `<div class="alert alert-danger">Error loading messages. Please refresh.</div>`;
                }
            });
        }

        function openConversation(userId, userName) {
            document.getElementById('replyRecipientName').textContent = userName;
            document.getElementById('replyReceiverId').value = userId;
            document.getElementById('replyMessageText').value = '';
            
            const thread = document.getElementById('conversationThread');
            thread.innerHTML = `<div class="text-center py-3"><div class="spinner-border text-primary spinner-border-sm" role="status"></div></div>`;
            
            fetch('dashboard.php?action=get_messages&conversation_id=' + userId, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.messages && data.messages.length > 0) {
                    let html = '';
                    const currentUserId = <?php echo $user_id; ?>;
                    data.messages.forEach(msg => {
                        const isOwn = msg.sender_id == currentUserId;
                        const align = isOwn ? 'own' : 'other';
                        html += `
                            <div class="message-bubble ${align}">
                                <div class="bubble">
                                    <div><strong>${isOwn ? 'You' : msg.sender_name}</strong></div>
                                    <div>${msg.message}</div>
                                    <span class="time">${new Date(msg.created_at).toLocaleString()}</span>
                                </div>
                            </div>
                        `;
                    });
                    thread.innerHTML = html;
                    thread.scrollTop = thread.scrollHeight;
                } else {
                    thread.innerHTML = `<div class="text-center text-muted">No messages in this conversation.</div>`;
                }
            })
            .catch(() => {
                thread.innerHTML = `<div class="alert alert-danger">Error loading messages.</div>`;
            });
            
            openModal('messageReplyModal');
        }

        function sendReply() {
            const receiverId = document.getElementById('replyReceiverId').value;
            const message = document.getElementById('replyMessageText').value.trim();
            
            if (!receiverId || !message) {
                alert('Please enter a message');
                return;
            }
            
            const btn = document.querySelector('#messageReplyModal .btn-primary');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            
            fetch('dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: `action=send_message&receiver_id=${receiverId}&message=${encodeURIComponent(message)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('replyMessageText').value = '';
                    alert('Message sent successfully!');
                    loadConversations();
                    closeModal('messageReplyModal');
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(() => alert('Error sending message'))
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Reply';
            });
        }

        // ============================================
        // NEW MESSAGE FUNCTIONS
        // ============================================
        function openNewMessageModal() {
            document.getElementById('newMessageRecipient').value = '';
            document.getElementById('newMessageRecipientId').value = '';
            document.getElementById('newMessageText').value = '';
            document.getElementById('recipientSearchResults').style.display = 'none';
            openModal('newMessageModal');
        }

        // Search for users as you type
        document.addEventListener('DOMContentLoaded', function() {
            const recipientInput = document.getElementById('newMessageRecipient');
            if (recipientInput) {
                recipientInput.addEventListener('input', function() {
                    const searchTerm = this.value.trim();
                    const resultsContainer = document.getElementById('recipientSearchResults');
                    
                    if (searchTerm.length < 2) {
                        resultsContainer.style.display = 'none';
                        return;
                    }
                    
                    fetch('dashboard.php?action=search_users&q=' + encodeURIComponent(searchTerm), {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.users && data.users.length > 0) {
                            let html = '';
                            data.users.forEach(user => {
                                html += `
                                    <a href="#" class="list-group-item list-group-item-action" 
                                       onclick="selectRecipient(${user.id}, '${user.username}'); return false;">
                                        <i class="fas fa-user-circle me-2"></i>${user.username}
                                        <small class="text-muted">(${user.email})</small>
                                    </a>
                                `;
                            });
                            resultsContainer.innerHTML = html;
                            resultsContainer.style.display = 'block';
                        } else {
                            resultsContainer.innerHTML = `<div class="list-group-item text-muted">No users found</div>`;
                            resultsContainer.style.display = 'block';
                        }
                    })
                    .catch(() => {
                        resultsContainer.innerHTML = `<div class="list-group-item text-danger">Error searching</div>`;
                        resultsContainer.style.display = 'block';
                    });
                });
            }
        });

        function selectRecipient(userId, username) {
            document.getElementById('newMessageRecipient').value = username;
            document.getElementById('newMessageRecipientId').value = userId;
            document.getElementById('recipientSearchResults').style.display = 'none';
        }

        function sendNewMessage() {
            const recipientId = document.getElementById('newMessageRecipientId').value;
            const message = document.getElementById('newMessageText').value.trim();
            
            if (!recipientId) {
                alert('Please select a recipient');
                return;
            }
            
            if (!message) {
                alert('Please enter a message');
                return;
            }
            
            const btn = document.querySelector('#newMessageModal .btn-primary');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            
            fetch('dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: `action=send_message&receiver_id=${recipientId}&message=${encodeURIComponent(message)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Message sent successfully!');
                    closeModal('newMessageModal');
                    loadConversations();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(() => alert('Error sending message'))
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send';
            });
        }

        // ============================================
        // REVIEW REPLY FUNCTIONS
        // ============================================
        function openReviewReply(reviewId, reviewerName, rating, reviewText) {
            document.getElementById('reviewReplyId').value = reviewId;
            document.getElementById('reviewReplyReviewer').textContent = reviewerName;
            const stars = '⭐'.repeat(rating) + '☆'.repeat(5 - rating);
            document.getElementById('reviewReplyRating').textContent = stars;
            document.getElementById('reviewReplyText').textContent = reviewText;
            document.getElementById('reviewReplyTextArea').value = '';
            openModal('reviewReplyModal');
        }

        function sendReviewReply() {
            const reviewId = document.getElementById('reviewReplyId').value;
            const replyText = document.getElementById('reviewReplyTextArea').value.trim();
            
            if (!replyText) {
                alert('Please enter a reply');
                return;
            }
            
            const btn = document.querySelector('#reviewReplyModal .btn-primary');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            
            fetch('dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: `action=reply_review&review_id=${reviewId}&reply_text=${encodeURIComponent(replyText)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Reply sent successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(() => alert('Error sending reply'))
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Reply';
            });
        }

        // ============================================
        // NOTIFICATION FUNCTIONS (UPDATED)
        // ============================================
        function markNotificationRead(notificationId) {
            fetch('dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: `action=mark_notification_read&notification_id=${notificationId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const item = document.querySelector(`.notification-item[onclick*="markNotificationRead(${notificationId})"]`);
                    if (item) {
                        item.classList.remove('unread');
                        const badge = item.querySelector('.badge');
                        if (badge) badge.remove();
                    }
                    const badge = document.querySelector('.nav-item[data-target="notifications"] .badge');
                    if (badge) {
                        const count = parseInt(badge.textContent) - 1;
                        if (count > 0) {
                            badge.textContent = count;
                        } else {
                            badge.remove();
                        }
                    }
                }
            })
            .catch(() => console.error('Error marking notification as read'));
        }

        function markAllNotificationsRead() {
            if (!confirm('Mark all notifications as read?')) return;

            fetch('dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: 'action=mark_all_notifications_read'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.querySelectorAll('.notification-item.unread').forEach(item => {
                        item.classList.remove('unread');
                        const badge = item.querySelector('.badge');
                        if (badge) badge.remove();
                    });
                    const badge = document.querySelector('.nav-item[data-target="notifications"] .badge');
                    if (badge) badge.remove();
                    alert('All notifications marked as read!');
                }
            })
            .catch(() => alert('Error marking notifications as read'));
        }

        // ============================================
        // ORDER FUNCTIONS (UPDATED)
        // ============================================
        function updateOrderStatus(orderId, status) {
            if (!confirm(`Are you sure you want to change this order status to ${status.toUpperCase()}?`)) {
                return;
            }

            const btn = document.querySelector(`button[onclick*="updateOrderStatus(${orderId}, '${status}')"]`);
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            }

            fetch('dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: `action=update_order_status&order_id=${orderId}&status=${status}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Order status updated successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Failed to update order status'));
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-check"></i>';
                    }
                }
            })
            .catch(error => {
                alert('Error updating order status. Please try again.');
                console.error('Error:', error);
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check"></i>';
                }
            });
        }

        function viewOrder(orderId) {
            openModal('orderDetailsModal');
            document.getElementById('orderDetailsContent').innerHTML = `
                <div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>
            `;
            document.getElementById('orderStatusActions').innerHTML = '';

            fetch('dashboard.php?action=get_order&id=' + orderId, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const order = data.order;
                    let statusActions = '';
                    
                    if (order.status === 'pending') {
                        statusActions += `<button class="btn btn-success btn-sm" onclick="updateOrderStatus(${order.id}, 'confirmed')"><i class="fas fa-check"></i> Confirm</button>`;
                    }
                    if (order.status === 'confirmed') {
                        statusActions += `<button class="btn btn-info btn-sm" onclick="updateOrderStatus(${order.id}, 'processing')"><i class="fas fa-cogs"></i> Process</button>`;
                    }
                    if (order.status === 'processing') {
                        statusActions += `<button class="btn btn-primary btn-sm" onclick="updateOrderStatus(${order.id}, 'shipped')"><i class="fas fa-truck"></i> Ship</button>`;
                    }
                    if (order.status === 'shipped') {
                        statusActions += `<button class="btn btn-success btn-sm" onclick="updateOrderStatus(${order.id}, 'delivered')"><i class="fas fa-home"></i> Deliver</button>`;
                    }
                    if (order.status !== 'cancelled' && order.status !== 'delivered' && order.status !== 'completed') {
                        statusActions += `<button class="btn btn-danger btn-sm" onclick="updateOrderStatus(${order.id}, 'cancelled')"><i class="fas fa-times"></i> Cancel</button>`;
                    }

                    const orderNum = String(order.id).padStart(5, '0');

                    document.getElementById('orderDetailsContent').innerHTML = `
                        <div class="card"><div class="card-body">
                            <h6 class="card-subtitle mb-2 text-muted">Order #${orderNum}</h6>
                            <p><strong>Item:</strong> ${order.listing_title || 'N/A'}</p>
                            <p><strong>Buyer:</strong> ${order.buyer_name || 'N/A'}</p>
                            <p><strong>Quantity:</strong> ${order.quantity || 1}</p>
                            <p><strong>Amount:</strong> ₦${Number(order.total_amount || 0).toLocaleString()}</p>
                            <p><strong>Status:</strong> <span class="status-badge ${order.status || 'pending'}">${(order.status || 'PENDING').toUpperCase()}</span></p>
                            <p><strong>Date:</strong> ${new Date(order.created_at).toLocaleDateString()}</p>
                            ${order.delivery_address ? `<p><strong>Delivery Address:</strong> ${order.delivery_address}</p>` : ''}
                            <hr>
                            <p><strong>Payment Status:</strong> <span class="status-badge ${order.payment_status || 'pending'}">${(order.payment_status || 'PENDING').toUpperCase()}</span></p>
                        </div></div>
                    `;

                    document.getElementById('orderStatusActions').innerHTML = statusActions;
                } else {
                    document.getElementById('orderDetailsContent').innerHTML = `<div class="alert alert-danger">${data.message || 'Failed to load order'}</div>`;
                }
            })
            .catch(error => {
                console.error('Error loading order:', error);
                document.getElementById('orderDetailsContent').innerHTML = `<div class="alert alert-danger">Error loading order details. Please try again.</div>`;
            });
        }

        // ============================================
        // ANALYTICS
        // ============================================
        function loadAnalytics() {
            fetch('vendor_analytics_data.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('analyticsContent').innerHTML = `
                            <div class="row g-4">
                                <div class="col-md-6"><div class="stat-card"><div class="stat-label" style="font-size: 1rem; font-weight: 600;">Total Views</div><div class="stat-number">${data.total_views}</div></div></div>
                                <div class="col-md-6"><div class="stat-card"><div class="stat-label" style="font-size: 1rem; font-weight: 600;">Total Orders</div><div class="stat-number">${data.total_orders}</div></div></div>
                                <div class="col-md-6"><div class="stat-card"><div class="stat-label" style="font-size: 1rem; font-weight: 600;">Total Revenue</div><div class="stat-number" style="color: #27ae60;">₦${Number(data.total_revenue).toLocaleString()}</div></div></div>
                                <div class="col-md-6"><div class="stat-card"><div class="stat-label" style="font-size: 1rem; font-weight: 600;">Average Rating</div><div class="stat-number">${data.avg_rating > 0 ? data.avg_rating : 'N/A'}</div></div></div>
                            </div>`;
                    } else {
                        document.getElementById('analyticsContent').innerHTML = `<div class="alert alert-info">${data.message || 'No analytics data available'}</div>`;
                    }
                })
                .catch(() => {
                    document.getElementById('analyticsContent').innerHTML = `<div class="alert alert-danger">Error loading analytics</div>`;
                });
        }

        // ============================================
        // AUTO-REFRESH FOR NEW ORDERS (every 30 seconds)
        // ============================================
        <?php if ($show_vendor_features): ?>
        setInterval(function() {
            fetch('check_new_orders.php')
                .then(response => response.json())
                .then(data => {
                    if (data.new_orders > 0) {
                        const badge = document.querySelector('.nav-item[data-target="orders"] .badge');
                        if (badge) {
                            badge.textContent = data.new_orders;
                        } else {
                            const ordersNav = document.querySelector('.nav-item[data-target="orders"]');
                            if (ordersNav) {
                                ordersNav.innerHTML += `<span class="badge bg-danger">${data.new_orders}</span>`;
                            }
                        }
                        if (data.new_orders > 0 && !document.querySelector('.new-order-alert')) {
                            const alert = document.createElement('div');
                            alert.className = 'alert alert-success alert-dismissible fade show new-order-alert';
                            alert.innerHTML = `
                                <i class="fas fa-shopping-cart me-2"></i>
                                <strong>${data.new_orders} new order${data.new_orders > 1 ? 's' : ''} received!</strong>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            `;
                            document.querySelector('.main-content').prepend(alert);
                            setTimeout(() => {
                                if (alert.parentNode) alert.remove();
                            }, 5000);
                        }
                    }
                })
                .catch(() => {});
        }, 30000);
        <?php endif; ?>

        // ============================================
        // INITIALIZE ON PAGE LOAD
        // ============================================
        document.addEventListener('DOMContentLoaded', function() {
            loadConversations();
        });
    </script>
</body>
</html>