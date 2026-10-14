<?php
require_once 'config.php';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username   = trim($_POST['username']);
    $email      = trim($_POST['email']);
    $password   = password_hash($_POST['password'], PASSWORD_BCRYPT);
    $first_name = trim($_POST['first_name']);
    $last_name  = trim($_POST['last_name']);
    $phone      = trim($_POST['phone']);
    $role       = $_POST['role'] ?? 'user';

    $sql = "INSERT INTO users (username, email, password, first_name, last_name, phone, role) 
            VALUES (?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssssss", $username, $email, $password, $first_name, $last_name, $phone, $role);

    if ($stmt->execute()) {
        echo "Signup successful! You can now login.";
    } else {
        echo "Error: " . $stmt->error;
    }
}
