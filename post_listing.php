<?php
session_start();
require 'config.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php?msg=error&error=Please login to post a listing');
    exit();
}

// Check if form was submitted
if (isset($_POST['post_ad'])) {
    $user_id = $_SESSION['user_id'];
    
    // Get form data
    $category = mysqli_real_escape_string($conn, $_POST['category']);
    $title = mysqli_real_escape_string($conn, $_POST['title']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $price = !empty($_POST['price']) ? floatval($_POST['price']) : null;
    $location = mysqli_real_escape_string($conn, $_POST['location']);
    $contact_name = mysqli_real_escape_string($conn, $_POST['contact_name']);
    $contact_phone = mysqli_real_escape_string($conn, $_POST['contact_phone']);
    $contact_email = mysqli_real_escape_string($conn, $_POST['contact_email'] ?? '');
    
    // Generate slug from title
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title))) . '-' . time();
    
    // Property-specific fields
    $bedrooms = isset($_POST['bedrooms']) && !empty($_POST['bedrooms']) ? intval($_POST['bedrooms']) : null;
    $bathrooms = isset($_POST['bathrooms']) && !empty($_POST['bathrooms']) ? intval($_POST['bathrooms']) : null;
    $area = isset($_POST['area']) && !empty($_POST['area']) ? floatval($_POST['area']) : null;
    
    // Item-specific fields
    $condition = isset($_POST['condition']) ? mysqli_real_escape_string($conn, $_POST['condition']) : 'used';
    $brand = isset($_POST['brand']) ? mysqli_real_escape_string($conn, $_POST['brand']) : '';
    
    // Set property_type and listing_type based on category
    $property_type = 'house';
    $listing_type = 'sale';
    
    if ($category == 'apartment') {
        $property_type = 'apartment';
        $listing_type = 'rent';
    } elseif ($category == 'land') {
        $property_type = 'land';
        $listing_type = 'sale';
    } elseif ($category == 'property') {
        $property_type = 'house';
        $listing_type = 'sale';
    } elseif ($category == 'item') {
        $property_type = 'other';
        $listing_type = 'sale';
    }
    
    // Handle image upload
    $image_path = null;
    if (isset($_FILES['images']) && $_FILES['images']['error'][0] == 0) {
        $target_dir = "uploads/listings/";
        
        // Create directory if it doesn't exist
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        $file_name = time() . '_' . basename($_FILES['images']['name'][0]);
        $target_file = $target_dir . $file_name;
        
        // Validate image
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif'];
        $file_type = $_FILES['images']['type'][0];
        
        if (in_array($file_type, $allowed_types)) {
            if (move_uploaded_file($_FILES['images']['tmp_name'][0], $target_file)) {
                $image_path = $target_file;
            }
        }
    }
    
    // Get category_id from categories table based on category name
    $category_id = null;
    $category_id_query = "SELECT id FROM categories WHERE LOWER(name) = LOWER(?) OR LOWER(slug) = LOWER(?)";
    $stmt = $conn->prepare($category_id_query);
    if ($stmt) {
        $cat_name = $category;
        if ($category == 'property') $cat_name = 'Real Estate';
        if ($category == 'apartment') $cat_name = 'Apartments';
        if ($category == 'land') $cat_name = 'Lands';
        if ($category == 'item') $cat_name = 'Used Items';
        
        $stmt->bind_param("ss", $cat_name, $cat_name);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $category_id = $row['id'];
        }
        $stmt->close();
    }
    
    // FIXED: Correct SQL query with exact number of placeholders
    $query = "INSERT INTO listings (
        user_id, category, category_id, title, slug, description, 
        price, location, property_type, listing_type, 
        bedrooms, bathrooms, floor_area, 
        item_condition, brand, image, 
        contact_name, contact_phone, contact_email,
        status, created_at
    ) VALUES (
        ?, ?, ?, ?, ?, ?, 
        ?, ?, ?, ?, 
        ?, ?, ?, 
        ?, ?, ?, 
        ?, ?, ?,
        'active', NOW()
    )";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        header('Location: index.php?msg=error&error=' . urlencode('Database error: ' . $conn->error));
        exit();
    }
    
    // FIXED: Correct bind_param with 19 parameters (matching 19 placeholders)
    // NOTE: position 4 (title) corrected from 'i' to 's' — it was being cast to
    // an integer, which silently turned every non-numeric title into 0.
    $stmt->bind_param("isisssdsssiidssssss", 
        $user_id,           // 1. int
        $category,          // 2. string
        $category_id,       // 3. int
        $title,            // 4. string  <-- FIXED (was 'i', now 's')
        $slug,             // 5. string
        $description,      // 6. string
        $price,            // 7. double
        $location,         // 8. string
        $property_type,    // 9. string
        $listing_type,     // 10. string
        $bedrooms,         // 11. int
        $bathrooms,        // 12. int
        $area,             // 13. double
        $condition,        // 14. string
        $brand,            // 15. string
        $image_path,       // 16. string
        $contact_name,     // 17. string
        $contact_phone,    // 18. string
        $contact_email     // 19. string
    );
    
    if ($stmt->execute()) {
        $listing_id = $stmt->insert_id;
        
        // Handle multiple images
        if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
            $target_dir = "uploads/listings/";
            
            // Insert additional images into listing_images table
            $image_query = "INSERT INTO listing_images (listing_id, image_url, is_primary, sort_order) VALUES (?, ?, ?, ?)";
            $image_stmt = $conn->prepare($image_query);
            
            if ($image_stmt) {
                $is_primary = true;
                $sort_order = 0;
                
                // First image is already saved as main image, mark as primary
                if ($image_path) {
                    $image_stmt->bind_param("isii", $listing_id, $image_path, $is_primary, $sort_order);
                    $image_stmt->execute();
                    $sort_order++;
                }
                
                // Handle remaining images
                for ($i = 1; $i < count($_FILES['images']['name']); $i++) {
                    if ($_FILES['images']['error'][$i] == 0) {
                        $file_name = time() . '_' . $i . '_' . basename($_FILES['images']['name'][$i]);
                        $target_file = $target_dir . $file_name;
                        
                        if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $target_file)) {
                            $is_primary = false;
                            $image_stmt->bind_param("isii", $listing_id, $target_file, $is_primary, $sort_order);
                            $image_stmt->execute();
                            $sort_order++;
                        }
                    }
                }
                $image_stmt->close();
            }
        }
        
        header('Location: index.php?msg=success');
    } else {
        header('Location: index.php?msg=error&error=' . urlencode($stmt->error));
    }
    
    $stmt->close();
} else {
    header('Location: index.php');
}

$conn->close();
?>