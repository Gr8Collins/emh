<?php
session_start();

// Check if user is admin
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
    header('Location: admin.php');
    exit();
}
require 'config.php';

// Handle POST actions with PHP
$action_message = '';
$action_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        switch ($action) {
            case 'delete_user':
                $user_id = intval($_POST['user_id']);
                $sql = "DELETE FROM users WHERE id = ? AND is_admin = 0";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $user_id);
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $action_message = 'User deleted successfully';
                    $action_type = 'success';
                } else {
                    $action_message = 'Cannot delete admin user or user not found';
                    $action_type = 'error';
                }
                break;
                
            case 'block_user':
                $user_id = intval($_POST['user_id']);
                $sql = "UPDATE users SET status = 'blocked' WHERE id = ? AND is_admin = 0";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $user_id);
                if ($stmt->execute()) {
                    $action_message = 'User blocked successfully';
                    $action_type = 'success';
                } else {
                    $action_message = 'Error blocking user';
                    $action_type = 'error';
                }
                break;
                
            case 'unblock_user':
                $user_id = intval($_POST['user_id']);
                $sql = "UPDATE users SET status = 'active' WHERE id = ? AND is_admin = 0";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $user_id);
                if ($stmt->execute()) {
                    $action_message = 'User unblocked successfully';
                    $action_type = 'success';
                } else {
                    $action_message = 'Error unblocking user';
                    $action_type = 'error';
                }
                break;
                
            case 'toggle_user_status':
                $user_id = intval($_POST['user_id']);
                $status = $_POST['status'];
                
                $allowed_statuses = ['active', 'inactive', 'suspended', 'blocked'];
                if (!in_array($status, $allowed_statuses)) {
                    $action_message = 'Invalid status';
                    $action_type = 'error';
                    break;
                }
                
                $sql = "UPDATE users SET status = ? WHERE id = ? AND is_admin = 0";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("si", $status, $user_id);
                if ($stmt->execute()) {
                    $action_message = 'User status updated successfully';
                    $action_type = 'success';
                } else {
                    $action_message = 'Error updating user status';
                    $action_type = 'error';
                }
                break;
                
            case 'delete_listing':
                $listing_id = intval($_POST['listing_id']);
                $sql = "DELETE FROM listings WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $listing_id);
                if ($stmt->execute()) {
                    $action_message = 'Listing deleted successfully';
                    $action_type = 'success';
                } else {
                    $action_message = 'Error deleting listing';
                    $action_type = 'error';
                }
                break;
                
            case 'update_listing_status':
                $listing_id = intval($_POST['listing_id']);
                $status = $_POST['status'];
                
                $allowed_statuses = ['active', 'pending', 'sold', 'rented', 'expired'];
                if (!in_array($status, $allowed_statuses)) {
                    $action_message = 'Invalid status';
                    $action_type = 'error';
                    break;
                }
                
                $sql = "UPDATE listings SET status = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("si", $status, $listing_id);
                if ($stmt->execute()) {
                    $action_message = 'Listing status updated successfully';
                    $action_type = 'success';
                } else {
                    $action_message = 'Error updating listing status';
                    $action_type = 'error';
                }
                break;
                
            case 'delete_store':
                $user_id = intval($_POST['user_id']);
                $sql = "UPDATE users SET is_vendor = 0, store_name = NULL, store_verified = 0 WHERE id = ? AND is_admin = 0";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $user_id);
                if ($stmt->execute()) {
                    $action_message = 'Store removed successfully';
                    $action_type = 'success';
                } else {
                    $action_message = 'Error removing store';
                    $action_type = 'error';
                }
                break;
                
            case 'toggle_store_verification':
                $user_id = intval($_POST['user_id']);
                $verified = intval($_POST['verified']);
                
                $check_sql = "SELECT id, is_vendor, role FROM users WHERE id = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("i", $user_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    $action_message = 'User not found';
                    $action_type = 'error';
                    break;
                }
                
                $user_data = $check_result->fetch_assoc();
                $has_storefront = ($user_data['is_vendor'] == 1 || $user_data['role'] === 'landlord');
                if (!$has_storefront) {
                    $action_message = 'This user does not have a storefront (not a vendor or landlord), so it cannot be verified';
                    $action_type = 'error';
                    break;
                }
                
                $sql = "UPDATE users SET store_verified = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ii", $verified, $user_id);
                
                if ($stmt->execute()) {
                    $action_message = $verified ? 'Store verified successfully' : 'Store unverified successfully';
                    $action_type = 'success';
                } else {
                    $action_message = 'Error updating store verification';
                    $action_type = 'error';
                }
                break;
                
            case 'delete_review':
                $review_id = intval($_POST['review_id']);
                $sql = "DELETE FROM store_reviews WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $review_id);
                if ($stmt->execute()) {
                    $action_message = 'Review deleted successfully';
                    $action_type = 'success';
                } else {
                    $action_message = 'Error deleting review';
                    $action_type = 'error';
                }
                break;
                
            case 'approve_review':
                $review_id = intval($_POST['review_id']);
                $sql = "UPDATE store_reviews SET is_approved = 1 WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $review_id);
                if ($stmt->execute()) {
                    $action_message = 'Review approved successfully';
                    $action_type = 'success';
                } else {
                    $action_message = 'Error approving review';
                    $action_type = 'error';
                }
                break;
                
            case 'add_category':
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                $icon = trim($_POST['icon']);
                
                if (empty($name)) {
                    $action_message = 'Category name is required';
                    $action_type = 'error';
                    break;
                }
                
                $check_sql = "SELECT id FROM categories WHERE name = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("s", $name);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $action_message = 'Category with this name already exists';
                    $action_type = 'error';
                    break;
                }
                
                if (empty($icon)) {
                    $icon = 'fa-tag';
                }
                
                if (!empty($icon) && substr($icon, 0, 3) !== 'fa-') {
                    $icon = 'fa-' . $icon;
                }
                
                $sql = "INSERT INTO categories (name, description, icon, status, created_at) VALUES (?, ?, ?, 'active', NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sss", $name, $description, $icon);
                
                if ($stmt->execute()) {
                    $action_message = 'Category added successfully';
                    $action_type = 'success';
                } else {
                    $action_message = 'Error adding category: ' . $conn->error;
                    $action_type = 'error';
                }
                break;
                
            case 'delete_category':
                $category_id = intval($_POST['category_id']);
                $sql = "DELETE FROM categories WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $category_id);
                if ($stmt->execute()) {
                    $action_message = 'Category deleted successfully';
                    $action_type = 'success';
                } else {
                    $action_message = 'Error deleting category';
                    $action_type = 'error';
                }
                break;
                
            case 'update_site_settings':
                $setting_key = $_POST['setting_key'];
                $setting_value = $_POST['setting_value'];
                
                $check_sql = "SELECT id FROM site_settings WHERE setting_key = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("s", $setting_key);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $sql = "UPDATE site_settings SET setting_value = ? WHERE setting_key = ?";
                } else {
                    $sql = "INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)";
                }
                
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ss", $setting_value, $setting_key);
                if ($stmt->execute()) {
                    $action_message = 'Settings updated successfully';
                    $action_type = 'success';
                } else {
                    $action_message = 'Error updating settings';
                    $action_type = 'error';
                }
                break;
                
            case 'approve_all_pending':
                $sql = "UPDATE listings SET status = 'active' WHERE status = 'pending'";
                if ($conn->query($sql)) {
                    $count = $conn->affected_rows;
                    $action_message = $count . ' pending listings approved successfully';
                    $action_type = 'success';
                } else {
                    $action_message = 'Error approving listings';
                    $action_type = 'error';
                }
                break;
                
            // NEW: Cancel Order
            case 'cancel_order':
                $order_id = intval($_POST['order_id']);
                $sql = "UPDATE orders SET status = 'cancelled', updated_at = NOW() WHERE id = ? AND status != 'delivered'";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $order_id);
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $action_message = 'Order cancelled successfully';
                    $action_type = 'success';
                } else {
                    $action_message = 'Error cancelling order or order already delivered';
                    $action_type = 'error';
                }
                break;
                
            // NEW: Update Order Status
            case 'update_order_status':
                $order_id = intval($_POST['order_id']);
                $status = $_POST['status'];
                
                $allowed_statuses = ['pending', 'processing', 'confirmed', 'shipped', 'delivered', 'cancelled'];
                if (!in_array($status, $allowed_statuses)) {
                    $action_message = 'Invalid status';
                    $action_type = 'error';
                    break;
                }
                
                $sql = "UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("si", $status, $order_id);
                if ($stmt->execute()) {
                    $action_message = 'Order status updated successfully';
                    $action_type = 'success';
                } else {
                    $action_message = 'Error updating order status';
                    $action_type = 'error';
                }
                break;
                
            // NEW: Delete Order
            case 'delete_order':
                $order_id = intval($_POST['order_id']);
                $sql = "DELETE FROM orders WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $order_id);
                if ($stmt->execute()) {
                    $action_message = 'Order deleted successfully';
                    $action_type = 'success';
                } else {
                    $action_message = 'Error deleting order';
                    $action_type = 'error';
                }
                break;
                
            // NEW: View Order Details (gets stored in session for modal display)
            case 'view_order_details':
                $order_id = intval($_POST['order_id']);
                $_SESSION['view_order_id'] = $order_id;
                $action_message = 'Order details loaded';
                $action_type = 'success';
                break;
                
            // NEW: Export Orders as CSV
            case 'export_orders_csv':
                $export_query = "SELECT o.id, o.order_number, u.username, o.total_amount, o.status, o.payment_status, o.created_at 
                                FROM orders o 
                                LEFT JOIN users u ON o.user_id = u.id 
                                ORDER BY o.created_at DESC";
                $export_result = $conn->query($export_query);
                
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="orders_export_' . date('Y-m-d') . '.csv"');
                
                $output = fopen('php://output', 'w');
                fputcsv($output, ['Order ID', 'Order Number', 'Customer', 'Total Amount', 'Status', 'Payment Status', 'Date']);
                
                while ($row = $export_result->fetch_assoc()) {
                    fputcsv($output, $row);
                }
                fclose($output);
                exit();
                break;
        }
        
        // Redirect to refresh the page with message
        $current_tab = isset($_POST['tab']) ? $_POST['tab'] : 'admindashboard';
        header('Location: admindashboard.php?tab=' . $current_tab . '&message=' . urlencode($action_message) . '&type=' . $action_type);
        exit();
    }
}

// Get current tab from URL - default to 'admindashboard'
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'admindashboard';

// Get message from URL
$message = isset($_GET['message']) ? $_GET['message'] : '';
$message_type = isset($_GET['type']) ? $_GET['type'] : '';

// Get all statistics
$stats = [];

$users_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN is_admin = 1 THEN 1 ELSE 0 END) as admins,
    SUM(CASE WHEN is_vendor = 1 AND is_admin = 0 THEN 1 ELSE 0 END) as vendors,
    SUM(CASE WHEN is_vendor = 0 AND is_admin = 0 THEN 1 ELSE 0 END) as regular_users,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_users,
    SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_users,
    SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended_users,
    SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END) as blocked_users
    FROM users";
$stats['users'] = $conn->query($users_query)->fetch_assoc();

$listings_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold,
    SUM(CASE WHEN status = 'rented' THEN 1 ELSE 0 END) as rented,
    SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired
    FROM listings";
$stats['listings'] = $conn->query($listings_query)->fetch_assoc();

$categories_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active
    FROM categories";
$stats['categories'] = $conn->query($categories_query)->fetch_assoc();

$stores_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN store_verified = 1 THEN 1 ELSE 0 END) as verified
    FROM users WHERE is_vendor = 1 OR role = 'landlord'";
$stats['stores'] = $conn->query($stores_query)->fetch_assoc();

$reviews_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN is_approved = 1 THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN is_approved = 0 THEN 1 ELSE 0 END) as pending
    FROM store_reviews";
$stats['reviews'] = $conn->query($reviews_query)->fetch_assoc();

$orders_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
    SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
    SUM(CASE WHEN status = 'shipped' THEN 1 ELSE 0 END) as shipped,
    SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
    SUM(total_amount) as total_revenue
    FROM orders";
$stats['orders'] = $conn->query($orders_query)->fetch_assoc();

$recent_users = $conn->query("SELECT * FROM users ORDER BY created_at DESC LIMIT 10");
$recent_listings = $conn->query("SELECT l.*, u.username FROM listings l JOIN users u ON l.user_id = u.id ORDER BY l.created_at DESC LIMIT 10");
$recent_reviews = $conn->query("SELECT sr.*, u.username as store_name, r.username as reviewer_name FROM store_reviews sr JOIN users u ON sr.store_id = u.id JOIN users r ON sr.reviewer_id = r.id ORDER BY sr.created_at DESC LIMIT 10");

$vendors_query = "SELECT u.*, 
    (SELECT COUNT(*) FROM listings WHERE user_id = u.id) as listing_count,
    (SELECT COUNT(*) FROM store_reviews WHERE store_id = u.id AND is_approved = 1) as review_count,
    (SELECT AVG(rating) FROM store_reviews WHERE store_id = u.id AND is_approved = 1) as store_rating
    FROM users u 
    WHERE (u.is_vendor = 1 OR u.role = 'landlord') AND u.is_admin = 0
    ORDER BY u.created_at DESC";
$vendors = $conn->query($vendors_query);

// ALL users — every account, every role, including admins
$all_users_query = "SELECT u.*, 
    (SELECT COUNT(*) FROM listings WHERE user_id = u.id) as listing_count
    FROM users u 
    ORDER BY u.created_at DESC";
$all_users = $conn->query($all_users_query);

$all_listings_query = "SELECT l.*, u.username, u.is_vendor, u.store_name 
    FROM listings l 
    JOIN users u ON l.user_id = u.id 
    ORDER BY l.created_at DESC";
$all_listings = $conn->query($all_listings_query);

$pending_listings_query = "SELECT l.*, u.username, u.is_vendor, u.store_name 
    FROM listings l 
    JOIN users u ON l.user_id = u.id 
    WHERE l.status = 'pending'
    ORDER BY l.created_at DESC";
$pending_listings = $conn->query($pending_listings_query);

$all_categories = $conn->query("SELECT * FROM categories ORDER BY name");

$settings_query = $conn->query("SELECT * FROM site_settings");
$site_settings = [];
while ($row = $settings_query->fetch_assoc()) {
    $site_settings[$row['setting_key']] = $row['setting_value'];
}

// Role badge color map
$role_badge_colors = [
    'admin'    => '#1a1a2e',
    'vendor'   => '#27ae60',
    'landlord' => '#3498db',
    'tenant'   => '#8e44ad',
    'buyer'    => '#ff7b00',
    'agent'    => '#16a085',
    'employer' => '#d35400',
    'user'     => '#6c757d',
];

// Get orders with pagination
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

$orders_count_query = "SELECT COUNT(*) as total FROM orders";
$orders_count_result = $conn->query($orders_count_query);
$total_orders = $orders_count_result->fetch_assoc()['total'];
$total_pages = ceil($total_orders / $per_page);

$orders_query = "SELECT o.*, u.username 
    FROM orders o 
    LEFT JOIN users u ON o.user_id = u.id 
    ORDER BY o.created_at DESC 
    LIMIT ? OFFSET ?";
$stmt = $conn->prepare($orders_query);
$stmt->bind_param("ii", $per_page, $offset);
$stmt->execute();
$orders = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Ebonyi Market Hub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #ff7b00;
            --secondary: #2c3e50;
            --success: #27ae60;
            --danger: #e74c3c;
            --warning: #f39c12;
            --info: #3498db;
            --dark: #1a1a2e;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .admin-wrapper { display: flex; min-height: 100vh; }
        
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, var(--dark) 0%, #16213e 100%);
            color: white;
            padding: 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: all 0.3s ease;
            z-index: 1000;
            left: 0;
            top: 0;
        }
        .sidebar-brand { padding: 25px 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-brand h2 { font-weight: 700; color: var(--primary); }
        .sidebar-brand small { color: rgba(255,255,255,0.6); }
        .sidebar-nav { padding: 20px 0; }
        .sidebar-nav .nav-section { padding: 0 20px; margin-bottom: 20px; }
        .sidebar-nav .nav-section-title { color: rgba(255,255,255,0.4); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px; }
        .sidebar-nav .nav-item {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }
        .sidebar-nav .nav-item:hover, .sidebar-nav .nav-item.active {
            background: rgba(255,255,255,0.05);
            color: white;
            border-left-color: var(--primary);
        }
        .sidebar-nav .nav-item i { width: 25px; margin-right: 12px; font-size: 1.1rem; }
        .sidebar-nav .nav-item .badge { margin-left: auto; }
        
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            min-height: 100vh;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
            background: white;
            padding: 15px 25px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .page-header-left h1 { font-size: 1.6rem; font-weight: 700; color: var(--secondary); margin: 0; }
        .page-header-left p { margin: 0; color: #6c757d; font-size: 0.9rem; }
        .page-header-right { display: flex; align-items: center; gap: 20px; }
        .page-header-right .admin-info { display: flex; align-items: center; gap: 12px; }
        .page-header-right .admin-info .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1.1rem;
        }
        .logout-btn {
            background: transparent;
            border: 1px solid #e0e0e0;
            color: var(--danger);
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }
        .logout-btn:hover { background: var(--danger); color: white; border-color: var(--danger); }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
        }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
        .stat-card .stat-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .stat-card .stat-icon {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: white;
        }
        .stat-card .stat-number { font-size: 2rem; font-weight: 700; color: var(--secondary); }
        .stat-card .stat-label { color: #6c757d; font-size: 0.85rem; }
        .stat-card .stat-change { font-size: 0.8rem; padding: 2px 10px; border-radius: 50px; }
        .stat-card .stat-change.up { background: #d4edda; color: #155724; }
        .stat-card .stat-change.down { background: #f8d7da; color: #721c24; }
        
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        
        .section-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .section-card .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .section-card .section-header h3 { font-weight: 600; color: var(--secondary); margin: 0; }
        
        .table-responsive { overflow-x: auto; }
        .table th { font-weight: 600; color: var(--secondary); border-top: none; border-bottom: 2px solid #eee; }
        .table td { vertical-align: middle; }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-badge.active { background: #d4edda; color: #155724; }
        .status-badge.pending { background: #fff3cd; color: #856404; }
        .status-badge.inactive { background: #f8d7da; color: #721c24; }
        .status-badge.suspended { background: #f8d7da; color: #721c24; }
        .status-badge.blocked { background: #dc3545; color: #fff; }
        .status-badge.sold { background: #cce5ff; color: #004085; }
        .status-badge.rented { background: #d1ecf1; color: #0c5460; }
        .status-badge.expired { background: #e2e3e5; color: #383d41; }
        .status-badge.approved { background: #d4edda; color: #155724; }
        .status-badge.processing { background: #cce5ff; color: #004085; }
        .status-badge.confirmed { background: #d1ecf1; color: #0c5460; }
        .status-badge.shipped { background: #d4edda; color: #155724; }
        .status-badge.delivered { background: #28a745; color: #fff; }
        .status-badge.cancelled { background: #dc3545; color: #fff; }

        .role-pill {
            display: inline-block;
            padding: 3px 11px;
            border-radius: 50px;
            font-size: 0.7rem;
            font-weight: 700;
            color: #fff;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .protected-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            background: #1a1a2e;
            color: #fff;
        }
        
        .action-btn {
            padding: 6px 12px;
            border-radius: 5px;
            border: none;
            font-size: 0.8rem;
            transition: all 0.3s ease;
            cursor: pointer;
            display: inline-block;
            text-decoration: none;
        }
        .action-btn:hover { transform: scale(1.05); }
        .btn-primary { background: var(--info); color: white; }
        .btn-success { background: var(--success); color: white; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-warning { background: var(--warning); color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-info { background: var(--info); color: white; }
        .btn-dark { background: var(--dark); color: white; }
        
        .modal-content { border-radius: 15px; border: none; }
        .modal-header { border-bottom: 1px solid #eee; }
        
        .menu-toggle {
            display: none;
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1001;
            background: var(--secondary);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 10px 15px;
            cursor: pointer;
            font-size: 1.2rem;
        }
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 999;
        }
        .sidebar-overlay.active { display: block; }
        
        @media (max-width: 992px) {
            .sidebar { left: -280px; }
            .sidebar.open { left: 0; }
            .main-content { margin-left: 0; padding: 20px; }
            .menu-toggle { display: block; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 576px) { .stats-grid { grid-template-columns: 1fr; } }
        
        .alert-custom {
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 20px;
        }
        .alert-custom.success { background: #d4edda; color: #155724; border-left: 4px solid var(--success); }
        .alert-custom.error { background: #f8d7da; color: #721c24; border-left: 4px solid var(--danger); }
        
        .search-input {
            max-width: 300px;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            padding: 6px 12px;
            font-size: 0.9rem;
        }
        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255,123,0,0.1);
        }
        
        .btn-group-sm .action-btn { font-size: 0.7rem; padding: 3px 8px; }
        
        .pagination-custom {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-top: 20px;
        }
        .pagination-custom .page-link {
            padding: 8px 15px;
            border-radius: 5px;
            border: 1px solid #dee2e6;
            color: var(--secondary);
            text-decoration: none;
            transition: all 0.3s ease;
        }
        .pagination-custom .page-link:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        .pagination-custom .page-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        .pagination-custom .page-link.disabled {
            opacity: 0.5;
            pointer-events: none;
        }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <div class="admin-wrapper">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-brand">
                <h2><i class="fas fa-store"></i> Admin</h2>
                <small>Ebonyi Market Hub</small>
            </div>
            
            <nav class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Main</div>
                    <a href="?tab=admindashboard" class="nav-item <?php echo $current_tab == 'admindashboard' ? 'active' : ''; ?>">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Users</div>
                    <a href="?tab=users" class="nav-item <?php echo $current_tab == 'users' ? 'active' : ''; ?>">
                        <i class="fas fa-users"></i> All Users
                        <span class="badge bg-primary"><?php echo $stats['users']['total']; ?></span>
                    </a>
                    <a href="?tab=vendors" class="nav-item <?php echo $current_tab == 'vendors' ? 'active' : ''; ?>">
                        <i class="fas fa-store"></i> Vendors &amp; Landlords
                        <span class="badge bg-success"><?php echo $stats['stores']['total']; ?></span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Listings</div>
                    <a href="?tab=listings" class="nav-item <?php echo $current_tab == 'listings' ? 'active' : ''; ?>">
                        <i class="fas fa-list"></i> All Listings
                        <span class="badge bg-primary"><?php echo $stats['listings']['total']; ?></span>
                    </a>
                    <a href="?tab=pending_listings" class="nav-item <?php echo $current_tab == 'pending_listings' ? 'active' : ''; ?>">
                        <i class="fas fa-clock"></i> Pending
                        <?php if ($stats['listings']['pending'] > 0): ?>
                            <span class="badge bg-danger"><?php echo $stats['listings']['pending']; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Content</div>
                    <a href="?tab=categories" class="nav-item <?php echo $current_tab == 'categories' ? 'active' : ''; ?>">
                        <i class="fas fa-tags"></i> Categories
                        <span class="badge bg-primary"><?php echo $stats['categories']['total']; ?></span>
                    </a>
                    <a href="?tab=reviews" class="nav-item <?php echo $current_tab == 'reviews' ? 'active' : ''; ?>">
                        <i class="fas fa-star"></i> Reviews
                        <?php if ($stats['reviews']['pending'] > 0): ?>
                            <span class="badge bg-danger"><?php echo $stats['reviews']['pending']; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">System</div>
                    <a href="?tab=orders" class="nav-item <?php echo $current_tab == 'orders' ? 'active' : ''; ?>">
                        <i class="fas fa-shopping-cart"></i> Orders
                        <span class="badge bg-primary"><?php echo $stats['orders']['total']; ?></span>
                    </a>
                    <a href="?tab=settings" class="nav-item <?php echo $current_tab == 'settings' ? 'active' : ''; ?>">
                        <i class="fas fa-cog"></i> Settings
                    </a>
                </div>
            </nav>
        </div>
        
        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <!-- Header with Logout -->
            <div class="page-header">
                <div class="page-header-left">
                    <h1 id="pageTitle">
                        <?php
                        $tab_titles = [
                            'admindashboard' => 'Dashboard',
                            'users' => 'Users',
                            'vendors' => 'Vendors & Landlords',
                            'listings' => 'Listings',
                            'pending_listings' => 'Pending Listings',
                            'categories' => 'Categories',
                            'reviews' => 'Reviews',
                            'orders' => 'Orders',
                            'settings' => 'Settings'
                        ];
                        echo $tab_titles[$current_tab] ?? 'Dashboard';
                        ?>
                    </h1>
                    <p>Welcome back, <?php echo htmlspecialchars($_SESSION['username']); ?>!</p>
                </div>
                <div class="page-header-right">
                    <div class="admin-info">
                        <div class="avatar">
                            <?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?>
                        </div>
                        <div>
                            <div style="font-weight: 600; color: var(--secondary);"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
                            <div style="font-size: 0.8rem; color: #6c757d;">Administrator</div>
                        </div>
                    </div>
                    <a href="adminlogout.php" class="logout-btn">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>
            
            <!-- Alert Messages -->
            <?php if ($message): ?>
                <div class="alert-custom <?php echo $message_type; ?>">
                    <i class="fas fa-<?php echo $message_type == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <!-- ===== DASHBOARD TAB ===== -->
            <div id="tab-admindashboard" class="tab-content <?php echo $current_tab == 'admindashboard' ? 'active' : ''; ?>">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <span class="stat-label">Total Users</span>
                            <div class="stat-icon" style="background: var(--info);">
                                <i class="fas fa-users"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['users']['total']); ?></div>
                        <div class="stat-label">
                            <span class="stat-change up">
                                <i class="fas fa-arrow-up"></i> <?php echo $stats['users']['active_users']; ?> active
                            </span>
                            <?php if ($stats['users']['blocked_users'] > 0): ?>
                                <span class="stat-change down">
                                    <i class="fas fa-ban"></i> <?php echo $stats['users']['blocked_users']; ?> blocked
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <span class="stat-label">Vendors &amp; Landlords</span>
                            <div class="stat-icon" style="background: var(--success);">
                                <i class="fas fa-store"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['stores']['total']); ?></div>
                        <div class="stat-label">
                            <span class="stat-change up">
                                <i class="fas fa-check-circle"></i> <?php echo $stats['stores']['verified']; ?> verified
                            </span>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <span class="stat-label">Total Listings</span>
                            <div class="stat-icon" style="background: var(--warning);">
                                <i class="fas fa-list"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['listings']['total']); ?></div>
                        <div class="stat-label">
                            <span class="stat-change up">
                                <i class="fas fa-check-circle"></i> <?php echo $stats['listings']['active']; ?> active
                            </span>
                            <?php if ($stats['listings']['pending'] > 0): ?>
                                <span class="stat-change down">
                                    <i class="fas fa-clock"></i> <?php echo $stats['listings']['pending']; ?> pending
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <span class="stat-label">Total Orders</span>
                            <div class="stat-icon" style="background: var(--danger);">
                                <i class="fas fa-shopping-cart"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['orders']['total']); ?></div>
                        <div class="stat-label">
                            ₦<?php echo number_format($stats['orders']['total_revenue'] ?? 0); ?> revenue
                            <?php if ($stats['orders']['cancelled'] > 0): ?>
                                <span class="stat-change down">
                                    <i class="fas fa-times"></i> <?php echo $stats['orders']['cancelled']; ?> cancelled
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="section-card">
                            <div class="section-header">
                                <h3><i class="fas fa-user-plus"></i> Recent Users</h3>
                                <a href="?tab=users" class="action-btn btn-primary btn-sm">View All</a>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr><th>User</th><th>Role</th><th>Status</th><th>Joined</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($user = $recent_users->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                                                <?php if ($user['is_vendor']): ?>
                                                    <span class="badge bg-success">Vendor</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo ucfirst($user['role']); ?></td>
                                            <td>
                                                <span class="status-badge <?php echo $user['status']; ?>">
                                                    <?php echo ucfirst($user['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M d', strtotime($user['created_at'])); ?></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="section-card">
                            <div class="section-header">
                                <h3><i class="fas fa-list"></i> Recent Listings</h3>
                                <a href="?tab=listings" class="action-btn btn-primary btn-sm">View All</a>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr><th>Title</th><th>Seller</th><th>Status</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($listing = $recent_listings->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars(substr($listing['title'], 0, 25)); ?>...</td>
                                            <td><?php echo htmlspecialchars($listing['username']); ?></td>
                                            <td>
                                                <span class="status-badge <?php echo $listing['status']; ?>">
                                                    <?php echo ucfirst($listing['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-star"></i> Recent Reviews</h3>
                        <a href="?tab=reviews" class="action-btn btn-primary btn-sm">View All</a>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr><th>Store</th><th>Reviewer</th><th>Rating</th><th>Review</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                <?php while ($review = $recent_reviews->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($review['store_name']); ?></td>
                                    <td><?php echo htmlspecialchars($review['reviewer_name']); ?></td>
                                    <td>
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star" style="color: <?php echo $i <= $review['rating'] ? '#f1c40f' : '#ddd'; ?>;"></i>
                                        <?php endfor; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars(substr($review['review_text'], 0, 30)) . '...'; ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $review['is_approved'] ? 'active' : 'pending'; ?>">
                                            <?php echo $review['is_approved'] ? 'Approved' : 'Pending'; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- ===== USERS TAB ===== -->
            <div id="tab-users" class="tab-content <?php echo $current_tab == 'users' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-users"></i> All Users</h3>
                        <div>
                            <span class="badge bg-success">Active: <?php echo $stats['users']['active_users']; ?></span>
                            <span class="badge bg-danger">Suspended: <?php echo $stats['users']['suspended_users']; ?></span>
                            <span class="badge bg-dark">Blocked: <?php echo $stats['users']['blocked_users']; ?></span>
                            <span class="badge" style="background:#1a1a2e;">Admins: <?php echo $stats['users']['admins']; ?></span>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th><th>Username</th><th>Email</th><th>Role</th>
                                    <th>Listings</th><th>Store</th><th>Status</th><th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($user = $all_users->fetch_assoc()):
                                    $has_storefront = ($user['is_vendor'] == 1 || $user['role'] === 'landlord');
                                    $role_key = $user['role'] ?: 'user';
                                    $role_color = $role_badge_colors[$role_key] ?? '#6c757d';
                                ?>
                                <tr>
                                    <td>#<?php echo $user['id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                                        <?php if ($user['is_admin']): ?>
                                            <i class="fas fa-shield-alt text-dark ms-1" title="Administrator"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><span class="role-pill" style="background:<?php echo $role_color; ?>;"><?php echo htmlspecialchars(ucfirst($role_key)); ?></span></td>
                                    <td><?php echo $user['listing_count'] ?? 0; ?></td>
                                    <td>
                                        <?php if ($has_storefront): ?>
                                            <?php if ($user['store_verified']): ?>
                                                <span class="badge bg-success"><i class="fas fa-check-circle"></i> Verified</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary"><i class="fas fa-clock"></i> Unverified</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $user['status']; ?>">
                                            <?php echo ucfirst($user['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($user['is_admin']): ?>
                                            <span class="protected-pill"><i class="fas fa-lock"></i> Protected</span>
                                        <?php else: ?>
                                            <div class="btn-group btn-group-sm">
                                                <!-- Block/Unblock Button -->
                                                <?php if ($user['status'] != 'blocked'): ?>
                                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to block this user?');">
                                                        <input type="hidden" name="action" value="block_user">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                        <input type="hidden" name="tab" value="users">
                                                        <button type="submit" class="action-btn btn-dark btn-sm" title="Block User">
                                                            <i class="fas fa-ban"></i>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to unblock this user?');">
                                                        <input type="hidden" name="action" value="unblock_user">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                        <input type="hidden" name="tab" value="users">
                                                        <button type="submit" class="action-btn btn-success btn-sm" title="Unblock User">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <!-- Status Toggle -->
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="toggle_user_status">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <input type="hidden" name="tab" value="users">
                                                    <?php if ($user['status'] == 'active'): ?>
                                                        <button type="submit" name="status" value="inactive" class="action-btn btn-warning btn-sm" title="Deactivate">
                                                            <i class="fas fa-pause"></i>
                                                        </button>
                                                    <?php elseif ($user['status'] == 'inactive'): ?>
                                                        <button type="submit" name="status" value="active" class="action-btn btn-success btn-sm" title="Activate">
                                                            <i class="fas fa-play"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if ($user['status'] != 'suspended' && $user['status'] != 'blocked'): ?>
                                                        <button type="submit" name="status" value="suspended" class="action-btn btn-danger btn-sm" title="Suspend">
                                                            <i class="fas fa-user-slash"></i>
                                                        </button>
                                                    <?php elseif ($user['status'] == 'suspended'): ?>
                                                        <button type="submit" name="status" value="active" class="action-btn btn-success btn-sm" title="Unsuspend">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </form>

                                                <!-- Store Verification -->
                                                <?php if ($has_storefront): ?>
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="action" value="toggle_store_verification">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                        <input type="hidden" name="verified" value="<?php echo $user['store_verified'] ? 0 : 1; ?>">
                                                        <input type="hidden" name="tab" value="users">
                                                        <button type="submit" class="action-btn btn-info btn-sm" title="<?php echo $user['store_verified'] ? 'Unverify Store' : 'Verify Store'; ?>">
                                                            <i class="fas fa-<?php echo $user['store_verified'] ? 'times' : 'check'; ?>"></i>
                                                        </button>
                                                    </form>
                                                    <a href="../storefront.php?user_id=<?php echo $user['id']; ?>" class="action-btn btn-primary btn-sm" target="_blank" title="View Store">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                <?php endif; ?>

                                                <!-- Delete User -->
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this user? This action cannot be undone.');">
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <input type="hidden" name="tab" value="users">
                                                    <button type="submit" class="action-btn btn-danger btn-sm" title="Delete User">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- ===== VENDORS & LANDLORDS TAB ===== -->
            <div id="tab-vendors" class="tab-content <?php echo $current_tab == 'vendors' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-store"></i> Vendors &amp; Landlords Management</h3>
                        <span class="badge bg-success">Verified: <?php echo $stats['stores']['verified']; ?></span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Store</th><th>Owner</th><th>Type</th><th>Products</th>
                                    <th>Reviews</th><th>Rating</th><th>Verified</th><th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($vendor = $vendors->fetch_assoc()):
                                    $is_landlord = ($vendor['role'] === 'landlord');
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($vendor['store_name'] ?? $vendor['username']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($vendor['username']); ?></td>
                                    <td>
                                        <span class="role-pill" style="background:<?php echo $is_landlord ? '#3498db' : '#27ae60'; ?>;">
                                            <?php echo $is_landlord ? 'Landlord' : 'Vendor'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $vendor['listing_count']; ?></td>
                                    <td><?php echo $vendor['review_count']; ?></td>
                                    <td>
                                        <?php if ($vendor['store_rating'] > 0): ?>
                                            <?php echo number_format($vendor['store_rating'], 1); ?> ⭐
                                        <?php else: ?>
                                            <span class="text-muted">No ratings</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($vendor['store_verified']): ?>
                                            <span class="badge bg-success"><i class="fas fa-check-circle"></i> Verified</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Not Verified</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="toggle_store_verification">
                                                <input type="hidden" name="user_id" value="<?php echo $vendor['id']; ?>">
                                                <input type="hidden" name="verified" value="<?php echo $vendor['store_verified'] ? 0 : 1; ?>">
                                                <input type="hidden" name="tab" value="vendors">
                                                <button type="submit" class="action-btn btn-info btn-sm">
                                                    <i class="fas fa-<?php echo $vendor['store_verified'] ? 'times' : 'check'; ?>"></i>
                                                    <?php echo $vendor['store_verified'] ? 'Unverify' : 'Verify'; ?>
                                                </button>
                                            </form>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to remove this store?');">
                                                <input type="hidden" name="action" value="delete_store">
                                                <input type="hidden" name="user_id" value="<?php echo $vendor['id']; ?>">
                                                <input type="hidden" name="tab" value="vendors">
                                                <button type="submit" class="action-btn btn-danger btn-sm">
                                                    <i class="fas fa-store-slash"></i>
                                                </button>
                                            </form>
                                            <a href="../storefront.php?user_id=<?php echo $vendor['id']; ?>" class="action-btn btn-primary btn-sm" target="_blank">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- ===== LISTINGS TAB ===== -->
            <div id="tab-listings" class="tab-content <?php echo $current_tab == 'listings' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-list"></i> All Listings</h3>
                        <div>
                            <span class="badge bg-success">Active: <?php echo $stats['listings']['active']; ?></span>
                            <span class="badge bg-warning">Pending: <?php echo $stats['listings']['pending']; ?></span>
                            <span class="badge bg-danger">Expired: <?php echo $stats['listings']['expired']; ?></span>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th><th>Title</th><th>Seller</th>
                                    <th>Price</th><th>Category</th><th>Status</th><th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($listing = $all_listings->fetch_assoc()): ?>
                                <tr>
                                    <td>#<?php echo $listing['id']; ?></td>
                                    <td><?php echo htmlspecialchars(substr($listing['title'], 0, 30)); ?>...</td>
                                    <td>
                                        <?php echo htmlspecialchars($listing['username']); ?>
                                        <?php if ($listing['is_vendor']): ?>
                                            <span class="badge bg-success">V</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>₦<?php echo number_format($listing['price']); ?></td>
                                    <td><?php echo ucfirst($listing['category']); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $listing['status']; ?>">
                                            <?php echo ucfirst($listing['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="update_listing_status">
                                                <input type="hidden" name="listing_id" value="<?php echo $listing['id']; ?>">
                                                <input type="hidden" name="tab" value="listings">
                                                <?php if ($listing['status'] != 'active'): ?>
                                                    <button type="submit" name="status" value="active" class="action-btn btn-success btn-sm" title="Approve">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($listing['status'] != 'pending'): ?>
                                                    <button type="submit" name="status" value="pending" class="action-btn btn-warning btn-sm" title="Set Pending">
                                                        <i class="fas fa-clock"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this listing?');">
                                                <input type="hidden" name="action" value="delete_listing">
                                                <input type="hidden" name="listing_id" value="<?php echo $listing['id']; ?>">
                                                <input type="hidden" name="tab" value="listings">
                                                <button type="submit" class="action-btn btn-danger btn-sm" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                            <a href="../property_details.php?id=<?php echo $listing['id']; ?>" class="action-btn btn-primary btn-sm" target="_blank">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- ===== PENDING LISTINGS TAB ===== -->
            <div id="tab-pending_listings" class="tab-content <?php echo $current_tab == 'pending_listings' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-clock"></i> Pending Listings (<?php echo $stats['listings']['pending']; ?>)</h3>
                        <?php if ($stats['listings']['pending'] > 0): ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to approve all pending listings?');">
                                <input type="hidden" name="action" value="approve_all_pending">
                                <input type="hidden" name="tab" value="pending_listings">
                                <button type="submit" class="action-btn btn-success btn-sm">
                                    <i class="fas fa-check-double"></i> Approve All
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr><th>Title</th><th>Seller</th><th>Price</th><th>Posted</th><th>Actions</th></tr>
                            </thead>
                            <tbody>
                                <?php while ($listing = $pending_listings->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($listing['title']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($listing['username']); ?>
                                        <?php if ($listing['is_vendor']): ?>
                                            <span class="badge bg-success">V</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>₦<?php echo number_format($listing['price']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($listing['created_at'])); ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="update_listing_status">
                                                <input type="hidden" name="listing_id" value="<?php echo $listing['id']; ?>">
                                                <input type="hidden" name="status" value="active">
                                                <input type="hidden" name="tab" value="pending_listings">
                                                <button type="submit" class="action-btn btn-success btn-sm">
                                                    <i class="fas fa-check"></i> Approve
                                                </button>
                                            </form>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="update_listing_status">
                                                <input type="hidden" name="listing_id" value="<?php echo $listing['id']; ?>">
                                                <input type="hidden" name="status" value="expired">
                                                <input type="hidden" name="tab" value="pending_listings">
                                                <button type="submit" class="action-btn btn-danger btn-sm">
                                                    <i class="fas fa-times"></i> Reject
                                                </button>
                                            </form>
                                            <a href="../property_details.php?id=<?php echo $listing['id']; ?>" class="action-btn btn-primary btn-sm" target="_blank">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- ===== CATEGORIES TAB ===== -->
            <div id="tab-categories" class="tab-content <?php echo $current_tab == 'categories' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-tags"></i> Categories</h3>
                        <button class="action-btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                            <i class="fas fa-plus"></i> Add Category
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr><th>ID</th><th>Name</th><th>Description</th><th>Icon</th><th>Status</th><th>Actions</th></tr>
                            </thead>
                            <tbody>
                                <?php while ($category = $all_categories->fetch_assoc()): ?>
                                <tr>
                                    <td>#<?php echo $category['id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($category['name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($category['description']); ?></td>
                                    <td><i class="fas <?php echo $category['icon'] ?? 'fa-tag'; ?>"></i></td>
                                    <td>
                                        <span class="status-badge <?php echo $category['status']; ?>">
                                            <?php echo ucfirst($category['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this category?');">
                                            <input type="hidden" name="action" value="delete_category">
                                            <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                                            <input type="hidden" name="tab" value="categories">
                                            <button type="submit" class="action-btn btn-danger btn-sm">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- ===== REVIEWS TAB ===== -->
            <div id="tab-reviews" class="tab-content <?php echo $current_tab == 'reviews' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-star"></i> Store Reviews</h3>
                        <div>
                            <span class="badge bg-success">Approved: <?php echo $stats['reviews']['approved']; ?></span>
                            <span class="badge bg-warning">Pending: <?php echo $stats['reviews']['pending']; ?></span>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr><th>Store</th><th>Reviewer</th><th>Rating</th><th>Review</th><th>Date</th><th>Status</th><th>Actions</th></tr>
                            </thead>
                            <tbody>
                                <?php
                                $all_reviews = $conn->query("SELECT sr.*, u.username as store_name, r.username as reviewer_name 
                                    FROM store_reviews sr 
                                    JOIN users u ON sr.store_id = u.id 
                                    JOIN users r ON sr.reviewer_id = r.id 
                                    ORDER BY sr.created_at DESC");
                                while ($review = $all_reviews->fetch_assoc()):
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($review['store_name']); ?></td>
                                    <td><?php echo htmlspecialchars($review['reviewer_name']); ?></td>
                                    <td>
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star" style="color: <?php echo $i <= $review['rating'] ? '#f1c40f' : '#ddd'; ?>;"></i>
                                        <?php endfor; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars(substr($review['review_text'], 0, 40)) . '...'; ?></td>
                                    <td><?php echo date('M d, Y', strtotime($review['created_at'])); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $review['is_approved'] ? 'active' : 'pending'; ?>">
                                            <?php echo $review['is_approved'] ? 'Approved' : 'Pending'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <?php if (!$review['is_approved']): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="approve_review">
                                                    <input type="hidden" name="review_id" value="<?php echo $review['id']; ?>">
                                                    <input type="hidden" name="tab" value="reviews">
                                                    <button type="submit" class="action-btn btn-success btn-sm">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this review?');">
                                                <input type="hidden" name="action" value="delete_review">
                                                <input type="hidden" name="review_id" value="<?php echo $review['id']; ?>">
                                                <input type="hidden" name="tab" value="reviews">
                                                <button type="submit" class="action-btn btn-danger btn-sm">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- ===== ORDERS TAB ===== -->
            <div id="tab-orders" class="tab-content <?php echo $current_tab == 'orders' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-shopping-cart"></i> Orders</h3>
                        <div>
                            <span class="badge bg-warning">Pending: <?php echo $stats['orders']['pending']; ?></span>
                            <span class="badge bg-info">Processing: <?php echo $stats['orders']['processing']; ?></span>
                            <span class="badge bg-success">Delivered: <?php echo $stats['orders']['delivered']; ?></span>
                            <span class="badge bg-danger">Cancelled: <?php echo $stats['orders']['cancelled']; ?></span>
                            <a href="?tab=orders&export=1" class="action-btn btn-primary btn-sm" onclick="exportOrders(event)">
                                <i class="fas fa-file-export"></i> Export CSV
                            </a>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Customer</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Payment</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($order = $orders->fetch_assoc()): ?>
                                <tr>
                                    <td><strong>#<?php echo htmlspecialchars($order['order_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($order['username'] ?? 'Guest'); ?></td>
                                    <td>₦<?php echo number_format($order['total_amount']); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $order['status']; ?>">
                                            <?php echo ucfirst($order['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $order['payment_status']; ?>">
                                            <?php echo ucfirst($order['payment_status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($order['created_at'])); ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <!-- View Details -->
                                            <button class="action-btn btn-primary btn-sm" onclick="viewOrderDetails(<?php echo $order['id']; ?>, '<?php echo htmlspecialchars($order['order_number']); ?>')" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            
                                            <!-- Update Status -->
                                            <div class="dropdown d-inline">
                                                <button class="action-btn btn-info btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Update Status">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <li><button class="dropdown-item" onclick="updateOrderStatus(<?php echo $order['id']; ?>, 'pending')">Pending</button></li>
                                                    <li><button class="dropdown-item" onclick="updateOrderStatus(<?php echo $order['id']; ?>, 'processing')">Processing</button></li>
                                                    <li><button class="dropdown-item" onclick="updateOrderStatus(<?php echo $order['id']; ?>, 'confirmed')">Confirmed</button></li>
                                                    <li><button class="dropdown-item" onclick="updateOrderStatus(<?php echo $order['id']; ?>, 'shipped')">Shipped</button></li>
                                                    <li><button class="dropdown-item" onclick="updateOrderStatus(<?php echo $order['id']; ?>, 'delivered')">Delivered</button></li>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li><button class="dropdown-item text-danger" onclick="cancelOrder(<?php echo $order['id']; ?>)">Cancel Order</button></li>
                                                </ul>
                                            </div>
                                            
                                            <!-- Cancel Order -->
                                            <?php if ($order['status'] != 'cancelled' && $order['status'] != 'delivered'): ?>
                                                <button class="action-btn btn-danger btn-sm" onclick="cancelOrder(<?php echo $order['id']; ?>)" title="Cancel Order">
                                                    <i class="fas fa-times-circle"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <!-- Delete Order -->
                                            <button class="action-btn btn-danger btn-sm" onclick="deleteOrder(<?php echo $order['id']; ?>)" title="Delete Order">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                                <?php if ($orders->num_rows == 0): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted">No orders found</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination-custom">
                        <?php if ($page > 1): ?>
                            <a href="?tab=orders&page=<?php echo $page - 1; ?>" class="page-link">&laquo; Previous</a>
                        <?php else: ?>
                            <span class="page-link disabled">&laquo; Previous</span>
                        <?php endif; ?>
                        
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?tab=orders&page=<?php echo $i; ?>" class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?tab=orders&page=<?php echo $page + 1; ?>" class="page-link">Next &raquo;</a>
                        <?php else: ?>
                            <span class="page-link disabled">Next &raquo;</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- ===== SETTINGS TAB ===== -->
            <div id="tab-settings" class="tab-content <?php echo $current_tab == 'settings' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-cog"></i> Site Settings</h3>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_site_settings">
                        <input type="hidden" name="tab" value="settings">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Site Name</label>
                                    <input type="text" name="site_name" class="form-control" 
                                           value="<?php echo htmlspecialchars($site_settings['site_name'] ?? 'Ebonyi Market Hub'); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Site Email</label>
                                    <input type="email" name="site_email" class="form-control" 
                                           value="<?php echo htmlspecialchars($site_settings['site_email'] ?? 'admin@ebonyimarket.com'); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Currency</label>
                                    <input type="text" name="currency" class="form-control" 
                                           value="<?php echo htmlspecialchars($site_settings['currency'] ?? 'NGN'); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Currency Symbol</label>
                                    <input type="text" name="currency_symbol" class="form-control" 
                                           value="<?php echo htmlspecialchars($site_settings['currency_symbol'] ?? '₦'); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Enable Storefronts</label>
                                    <select name="enable_storefronts" class="form-select">
                                        <option value="1" <?php echo ($site_settings['enable_storefronts'] ?? 1) == 1 ? 'selected' : ''; ?>>Enabled</option>
                                        <option value="0" <?php echo ($site_settings['enable_storefronts'] ?? 1) == 0 ? 'selected' : ''; ?>>Disabled</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Enable Vendor Registration</label>
                                    <select name="enable_vendor_registration" class="form-select">
                                        <option value="1" <?php echo ($site_settings['enable_vendor_registration'] ?? 1) == 1 ? 'selected' : ''; ?>>Enabled</option>
                                        <option value="0" <?php echo ($site_settings['enable_vendor_registration'] ?? 1) == 0 ? 'selected' : ''; ?>>Disabled</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Store Verification Required</label>
                                    <select name="store_verification_required" class="form-select">
                                        <option value="1" <?php echo ($site_settings['store_verification_required'] ?? 1) == 1 ? 'selected' : ''; ?>>Required</option>
                                        <option value="0" <?php echo ($site_settings['store_verification_required'] ?? 1) == 0 ? 'selected' : ''; ?>>Not Required</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="action-btn btn-success">
                            <i class="fas fa-save"></i> Save Settings
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Category Modal -->
    <div class="modal fade" id="addCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Add Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="addCategoryForm">
                        <input type="hidden" name="action" value="add_category">
                        <input type="hidden" name="tab" value="categories">
                        <div class="mb-3">
                            <label class="form-label">Category Name *</label>
                            <input type="text" name="name" class="form-control" required>
                            <small class="text-muted">Example: Electronics, Fashion, Real Estate</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3" placeholder="Brief description of this category"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Icon (Font Awesome class)</label>
                            <input type="text" name="icon" class="form-control" placeholder="fa-home" value="fa-tag">
                            <small class="text-muted">Enter Font Awesome icon class (e.g., fa-home, fa-car, fa-laptop)</small>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="action-btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="action-btn btn-success">
                                <i class="fas fa-save"></i> Add Category
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Order Details Modal -->
    <div class="modal fade" id="orderDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Order Details: <span id="orderNumberDisplay"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="orderDetailsContent">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="action-btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile menu toggle
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('active');
        });
        
        document.getElementById('sidebarOverlay').addEventListener('click', function() {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('sidebarOverlay').classList.remove('active');
        });
        
        // Search functionality
        document.querySelectorAll('.table').forEach(table => {
            const sectionCard = table.closest('.section-card');
            if (sectionCard) {
                const header = sectionCard.querySelector('.section-header');
                if (header && !header.querySelector('.search-input')) {
                    const searchInput = document.createElement('input');
                    searchInput.className = 'search-input';
                    searchInput.placeholder = 'Search...';
                    searchInput.style.marginLeft = 'auto';
                    header.appendChild(searchInput);
                    
                    searchInput.addEventListener('keyup', function() {
                        const term = this.value.toLowerCase();
                        table.querySelectorAll('tbody tr').forEach(row => {
                            const text = row.textContent.toLowerCase();
                            row.style.display = text.includes(term) ? '' : 'none';
                        });
                    });
                }
            }
        });
        
        // Order Management Functions
        function viewOrderDetails(orderId, orderNumber) {
            document.getElementById('orderNumberDisplay').textContent = orderNumber;
            document.getElementById('orderDetailsContent').innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            `;
            
            // Show the modal
            const modal = new bootstrap.Modal(document.getElementById('orderDetailsModal'));
            modal.show();
            
            // Fetch order details
            fetch('admin_get_order_details.php?order_id=' + orderId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const order = data.order;
                        let html = `
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <strong>Order Number:</strong> ${order.order_number}
                                </div>
                                <div class="col-md-6">
                                    <strong>Customer:</strong> ${order.customer_name || 'Guest'}
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <strong>Total Amount:</strong> ₦${Number(order.total_amount).toLocaleString()}
                                </div>
                                <div class="col-md-6">
                                    <strong>Status:</strong> <span class="status-badge ${order.status}">${order.status}</span>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <strong>Payment Status:</strong> <span class="status-badge ${order.payment_status}">${order.payment_status}</span>
                                </div>
                                <div class="col-md-6">
                                    <strong>Date:</strong> ${new Date(order.created_at).toLocaleString()}
                                </div>
                            </div>
                            <hr>
                            <h6>Order Items</h6>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr><th>Product</th><th>Qty</th><th>Price</th><th>Total</th></tr>
                                    </thead>
                                    <tbody>
                        `;
                        
                        if (data.items && data.items.length > 0) {
                            data.items.forEach(item => {
                                html += `
                                    <tr>
                                        <td>${item.product_name || 'Product #' + item.listing_id}</td>
                                        <td>${item.quantity}</td>
                                        <td>₦${Number(item.price).toLocaleString()}</td>
                                        <td>₦${Number(item.total).toLocaleString()}</td>
                                    </tr>
                                `;
                            });
                        } else {
                            html += `<tr><td colspan="4" class="text-center text-muted">No items found</td></tr>`;
                        }
                        
                        html += `
                                    </tbody>
                                </table>
                            </div>
                        `;
                        
                        document.getElementById('orderDetailsContent').innerHTML = html;
                    } else {
                        document.getElementById('orderDetailsContent').innerHTML = `
                            <div class="alert alert-danger">${data.message || 'Error loading order details'}</div>
                        `;
                    }
                })
                .catch(error => {
                    document.getElementById('orderDetailsContent').innerHTML = `
                        <div class="alert alert-danger">Error loading order details. Please try again.</div>
                    `;
                });
        }
        
        function updateOrderStatus(orderId, status) {
            if (confirm('Are you sure you want to update this order status to ' + status + '?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = window.location.pathname;
                
                const inputs = {
                    action: 'update_order_status',
                    order_id: orderId,
                    status: status,
                    tab: 'orders'
                };
                
                for (const [key, value] of Object.entries(inputs)) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = key;
                    input.value = value;
                    form.appendChild(input);
                }
                
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function cancelOrder(orderId) {
            if (confirm('Are you sure you want to cancel this order? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = window.location.pathname;
                
                const inputs = {
                    action: 'cancel_order',
                    order_id: orderId,
                    tab: 'orders'
                };
                
                for (const [key, value] of Object.entries(inputs)) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = key;
                    input.value = value;
                    form.appendChild(input);
                }
                
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function deleteOrder(orderId) {
            if (confirm('Are you sure you want to delete this order? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = window.location.pathname;
                
                const inputs = {
                    action: 'delete_order',
                    order_id: orderId,
                    tab: 'orders'
                };
                
                for (const [key, value] of Object.entries(inputs)) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = key;
                    input.value = value;
                    form.appendChild(input);
                }
                
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function exportOrders(e) {
            e.preventDefault();
            window.location.href = window.location.pathname + '?tab=orders&action=export_orders_csv';
        }
    </script>
</body>
</html>