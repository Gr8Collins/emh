<?php
session_start();
require 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
$status = isset($_POST['status']) ? $_POST['status'] : '';

if (!$order_id || !$status) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Verify vendor owns this order
$verify = $conn->prepare("SELECT o.id, o.user_id as buyer_id, l.user_id as vendor_id 
                          FROM orders o 
                          JOIN listings l ON o.listing_id = l.id 
                          WHERE o.id = ?");
$verify->bind_param("i", $order_id);
$verify->execute();
$result = $verify->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Order not found']);
    exit;
}

$order = $result->fetch_assoc();

// Check if user is the vendor or admin
if ($order['vendor_id'] != $_SESSION['user_id'] && $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Update order status
$update = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
$update->bind_param("si", $status, $order_id);

if ($update->execute()) {
    echo json_encode(['success' => true, 'message' => 'Order status updated']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update order status']);
}
?>