<?php
session_start();
require 'config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    die(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$user_id = $_SESSION['user_id'];
$key = $_POST['key'] ?? '';
$value = $_POST['value'] ?? '';

if (!$key) {
    die(json_encode(['success' => false, 'message' => 'Missing key']));
}

// Get current settings
$result = $conn->query("SELECT dashboard_settings FROM users WHERE id = $user_id");
$row = $result->fetch_assoc();
$settings = $row['dashboard_settings'] ? json_decode($row['dashboard_settings'], true) : [];

// Update the specific setting
$decoded_value = json_decode($value, true);
if (json_last_error() === JSON_ERROR_NONE) {
    $settings[$key] = $decoded_value;
} else {
    $settings[$key] = $value;
}

$settings_json = json_encode($settings);
$conn->query("UPDATE users SET dashboard_settings = '$settings_json' WHERE id = $user_id");

if ($conn->affected_rows >= 0) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update settings']);
}
?>