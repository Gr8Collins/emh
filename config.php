<?php
// Database configuration
$host = "localhost";        // or 127.0.0.1
$dbname = "ebonyi_market_hub"; // match your created DB
$username = "root";         // Default XAMPP username
$password = "";             // Default XAMPP has no password

// Create connection
$conn = new mysqli($host, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
