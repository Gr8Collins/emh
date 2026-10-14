<?php
require 'config.php';
// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Escape and collect form inputs
    $title          = $conn->real_escape_string($_POST['title']);
    $description    = $conn->real_escape_string($_POST['description']);
    $price          = !empty($_POST['price']) ? $_POST['price'] : 0;
    $location       = $conn->real_escape_string($_POST['location']);
    $bedrooms       = !empty($_POST['bedrooms']) ? $_POST['bedrooms'] : 0;
    $bathrooms      = !empty($_POST['bathrooms']) ? $_POST['bathrooms'] : 0;
    $area           = !empty($_POST['area']) ? $_POST['area'] : 0;
    $type           = $_POST['type'];
    $condition      = $_POST['condition'];
    $contact_name   = $conn->real_escape_string($_POST['contact_name']);
    $contact_phone  = $conn->real_escape_string($_POST['contact_phone']);
    $contact_email  = $conn->real_escape_string($_POST['contact_email']);

    // Handle image upload
    $image_url = NULL;
    if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $targetDir = "uploads/";
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true); // create directory if not exists
        }
        $fileName = time() . "_" . basename($_FILES['image']['name']);
        $targetFile = $targetDir . $fileName;

        if (move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
            $image_url = $targetFile;
        }
    }

    // Insert into listings table
    $sql = "INSERT INTO listings (
                title, description, category, subcategory, price, location,
                image_url, type, bedrooms, bathrooms, area, `condition`,
                contact_name, contact_phone, contact_email
            ) VALUES (
                '$title', '$description', 'property', NULL, '$price', '$location',
                '$image_url', '$type', '$bedrooms', '$bathrooms', '$area', '$condition',
                '$contact_name', '$contact_phone', '$contact_email'
            )";

    if ($conn->query($sql) === TRUE) {
        // Redirect back to index with success
        header("Location: index.php?msg=Listing+posted+successfully");
        exit();
    } else {
        echo "Error: " . $conn->error;
    }
}

$conn->close();
?>
