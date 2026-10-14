<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_job'])) {
    $title             = trim($_POST['title']);
    $description       = trim($_POST['description']);
    $company           = trim($_POST['company']);
    $location          = trim($_POST['location']);
    $application_email = trim($_POST['application_email']);

    $stmt = $conn->prepare("INSERT INTO listings 
        (title, description, category, company, location, application_email) 
        VALUES (?, ?, 'job', ?, ?, ?)");
    $stmt->bind_param("sssss", $title, $description, $company, $location, $application_email);

    if ($stmt->execute()) {
        // Redirect back with success message
        header("Location: index.php?msg=success");
        exit();
    } else {
        header("Location: index.php?msg=error");
        exit();
    }
    $stmt->close();
}
?>
