<?php
// get_category_listings.php
session_start();
require 'config.php';

header('Content-Type: application/json');

$response = ['success' => false, 'listings' => [], 'message' => ''];

// Get category ID or name
$category_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$category_name = isset($_GET['name']) ? trim($_GET['name']) : '';

if ($category_id > 0) {
    // Get category name from ID
    $cat_query = "SELECT name FROM categories WHERE id = ? AND status = 'active'";
    $stmt = $conn->prepare($cat_query);
    if ($stmt) {
        $stmt->bind_param("i", $category_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $category_name = $row['name'];
        }
        $stmt->close();
    }
}

if (empty($category_name)) {
    $response['message'] = 'Category not found.';
    echo json_encode($response);
    exit();
}

// Fetch listings for this category
$query = "SELECT l.*, u.username, u.store_name, u.store_verified, u.id as user_id, u.avatar_url, u.store_description 
          FROM listings l 
          LEFT JOIN users u ON l.user_id = u.id 
          WHERE l.category = ? AND l.status = 'active' 
          ORDER BY l.created_at DESC";

$stmt = $conn->prepare($query);
if ($stmt) {
    $stmt->bind_param("s", $category_name);
    $stmt->execute();
    $result = $stmt->get_result();
    $listings = [];
    while ($row = $result->fetch_assoc()) {
        $listings[] = $row;
    }
    $stmt->close();
    
    $response['success'] = true;
    $response['listings'] = $listings;
    $response['count'] = count($listings);
} else {
    $response['message'] = 'Failed to fetch listings.';
}

echo json_encode($response);
?>