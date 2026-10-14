<?php
session_start();
require 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please login']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$store_id = $data['store_id'] ?? 0;
$action = $data['action'] ?? 'follow';
$follower_id = $_SESSION['user_id'];

if ($store_id == 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid store']);
    exit();
}

if ($action == 'follow') {
    // Check if already following
    $check_query = "SELECT * FROM store_followers WHERE store_id = ? AND follower_id = ?";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("ii", $store_id, $follower_id);
    $stmt->execute();
    
    if ($stmt->get_result()->num_rows == 0) {
        $insert_query = "INSERT INTO store_followers (store_id, follower_id) VALUES (?, ?)";
        $stmt = $conn->prepare($insert_query);
        $stmt->bind_param("ii", $store_id, $follower_id);
        $stmt->execute();
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Already following']);
    }
} else {
    // Unfollow
    $delete_query = "DELETE FROM store_followers WHERE store_id = ? AND follower_id = ?";
    $stmt = $conn->prepare($delete_query);
    $stmt->bind_param("ii", $store_id, $follower_id);
    $stmt->execute();
    echo json_encode(['success' => true]);
}
?>