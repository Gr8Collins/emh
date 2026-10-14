<?php
session_start();
require 'config.php';

// Check if ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: index.php');
    exit();
}

$listing_id = intval($_GET['id']);

// Check if user is logged in for viewing contact info
$isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

// Update view count
$update_view = "UPDATE listings SET views_count = views_count + 1 WHERE id = ?";
$stmt = $conn->prepare($update_view);
if ($stmt) {
    $stmt->bind_param("i", $listing_id);
    $stmt->execute();
    $stmt->close();
}

// Fetch listing details with user info - ONLY FROM DATABASE
$query = "SELECT l.*, 
          u.username, u.first_name, u.last_name, u.phone as user_phone, u.email as user_email, u.avatar_url, u.role,
          u.created_at as user_member_since,
          c.name as category_name
          FROM listings l 
          LEFT JOIN users u ON l.user_id = u.id 
          LEFT JOIN categories c ON l.category_id = c.id
          WHERE l.id = ? AND l.status = 'active'";

$stmt = $conn->prepare($query);
if (!$stmt) {
    die("Database error: " . $conn->error);
}

$stmt->bind_param("i", $listing_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: index.php?msg=error&error=Listing not found');
    exit();
}

$listing = $result->fetch_assoc();
$stmt->close();

// Fetch listing images from database ONLY
$images_query = "SELECT * FROM listing_images WHERE listing_id = ? ORDER BY is_primary DESC, sort_order ASC";
$stmt = $conn->prepare($images_query);
$listing_images = [];
if ($stmt) {
    $stmt->bind_param("i", $listing_id);
    $stmt->execute();
    $images_result = $stmt->get_result();
    while ($row = $images_result->fetch_assoc()) {
        $listing_images[] = $row;
    }
    $stmt->close();
}

// Determine category type from property_type
$category_type = 'property';
if ($listing['property_type'] == 'land') {
    $category_type = 'land';
} elseif ($listing['property_type'] == 'apartment') {
    $category_type = 'apartment';
} elseif ($listing['listing_type'] == 'rent') {
    $category_type = 'apartment';
}

// Fetch similar listings from database
$similar_listings = [];
if ($listing['category_id']) {
    $similar_query = "SELECT l.*, 
                      (SELECT image_url FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) as primary_image
                      FROM listings l 
                      WHERE l.category_id = ? AND l.id != ? AND l.status = 'active' 
                      ORDER BY l.created_at DESC LIMIT 4";
    $stmt = $conn->prepare($similar_query);
    if ($stmt) {
        $stmt->bind_param("ii", $listing['category_id'], $listing_id);
        $stmt->execute();
        $similar_result = $stmt->get_result();
        while ($row = $similar_result->fetch_assoc()) {
            $similar_listings[] = $row;
        }
        $stmt->close();
    }
}

// Handle sending inquiry/reply
if (isset($_POST['send_inquiry']) && $isLoggedIn) {
    $message = trim($_POST['message']);
    $sender_id = $_SESSION['user_id'];
    $receiver_id = intval($_POST['receiver_id']);
    $listing_id_val = intval($_POST['listing_id']);
    
    if (!empty($message) && $sender_id != $receiver_id) {
        $inquiry_query = "INSERT INTO messages (sender_id, receiver_id, listing_id, message, is_read, created_at) 
                         VALUES (?, ?, ?, ?, 0, NOW())";
        $stmt = $conn->prepare($inquiry_query);
        if ($stmt) {
            $stmt->bind_param("iiis", $sender_id, $receiver_id, $listing_id_val, $message);
            
            if ($stmt->execute()) {
                $_SESSION['alert'] = [
                    'type' => 'success',
                    'message' => 'Your message has been sent successfully! The owner will reply shortly.'
                ];
            } else {
                $_SESSION['alert'] = [
                    'type' => 'danger',
                    'message' => 'Failed to send message. Please try again.'
                ];
            }
            $stmt->close();
        }
        header('Location: property_details.php?id=' . $listing_id_val);
        exit();
    }
}

// Handle add to favorites
if (isset($_GET['action']) && $_GET['action'] === 'add_favorite' && $isLoggedIn) {
    $user_id = $_SESSION['user_id'];
    
    $check_fav = "SELECT id FROM favorites WHERE user_id = ? AND listing_id = ?";
    $stmt = $conn->prepare($check_fav);
    if ($stmt) {
        $stmt->bind_param("ii", $user_id, $listing_id);
        $stmt->execute();
        $fav_result = $stmt->get_result();
        
        if ($fav_result->num_rows === 0) {
            $add_fav = "INSERT INTO favorites (user_id, listing_id, created_at) VALUES (?, ?, NOW())";
            $stmt = $conn->prepare($add_fav);
            if ($stmt) {
                $stmt->bind_param("ii", $user_id, $listing_id);
                $stmt->execute();
                $_SESSION['alert'] = [
                    'type' => 'success',
                    'message' => 'Added to favorites!'
                ];
            }
        }
        $stmt->close();
    }
    header('Location: property_details.php?id=' . $listing_id);
    exit();
}

// Handle remove from favorites
if (isset($_GET['action']) && $_GET['action'] === 'remove_favorite' && $isLoggedIn) {
    $user_id = $_SESSION['user_id'];
    
    $remove_fav = "DELETE FROM favorites WHERE user_id = ? AND listing_id = ?";
    $stmt = $conn->prepare($remove_fav);
    if ($stmt) {
        $stmt->bind_param("ii", $user_id, $listing_id);
        $stmt->execute();
        $stmt->close();
        
        $_SESSION['alert'] = [
            'type' => 'success',
            'message' => 'Removed from favorites!'
        ];
    }
    header('Location: property_details.php?id=' . $listing_id);
    exit();
}

// Check if listing is favorited by current user
$is_favorited = false;
if ($isLoggedIn) {
    $fav_check = "SELECT id FROM favorites WHERE user_id = ? AND listing_id = ?";
    $stmt = $conn->prepare($fav_check);
    if ($stmt) {
        $stmt->bind_param("ii", $_SESSION['user_id'], $listing_id);
        $stmt->execute();
        $fav_result = $stmt->get_result();
        $is_favorited = $fav_result->num_rows > 0;
        $stmt->close();
    }
}

// Fetch conversation data
$recent_messages = [];
$unread_count = 0;
$total_messages = 0;

if ($isLoggedIn && isset($listing['user_id']) && $listing['user_id'] != $_SESSION['user_id']) {
    $user_id = $_SESSION['user_id'];
    $owner_id = $listing['user_id'];
    
    $conversation_query = "SELECT m.*, 
                          u.first_name, u.last_name, u.username, u.avatar_url
                          FROM messages m
                          LEFT JOIN users u ON m.sender_id = u.id
                          WHERE m.listing_id = ? 
                          AND ((m.sender_id = ? AND m.receiver_id = ?) 
                            OR (m.sender_id = ? AND m.receiver_id = ?))
                          ORDER BY m.created_at DESC
                          LIMIT 10";
    
    $stmt = $conn->prepare($conversation_query);
    if ($stmt) {
        $stmt->bind_param("iiiii", $listing_id, $user_id, $owner_id, $owner_id, $user_id);
        if ($stmt->execute()) {
            $conversation_result = $stmt->get_result();
            if ($conversation_result) {
                while ($row = $conversation_result->fetch_assoc()) {
                    if ($row['sender_id'] == $user_id) {
                        $row['sender_display_name'] = 'You';
                    } else {
                        $row['sender_display_name'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                        if (empty($row['sender_display_name'])) {
                            $row['sender_display_name'] = $row['username'] ?? 'Seller';
                        }
                    }
                    $recent_messages[] = $row;
                }
            }
        }
        $stmt->close();
    }
    
    // Get total message count
    $count_query = "SELECT COUNT(*) as count FROM messages 
                   WHERE listing_id = ? 
                   AND ((sender_id = ? AND receiver_id = ?) 
                     OR (sender_id = ? AND receiver_id = ?))";
    $stmt = $conn->prepare($count_query);
    if ($stmt) {
        $stmt->bind_param("iiiii", $listing_id, $user_id, $owner_id, $owner_id, $user_id);
        if ($stmt->execute()) {
            $count_result = $stmt->get_result();
            if ($count_result) {
                $total_messages = $count_result->fetch_assoc()['count'];
            }
        }
        $stmt->close();
    }
    
    // Get unread count
    $unread_query = "SELECT COUNT(*) as count FROM messages 
                    WHERE receiver_id = ? AND sender_id = ? AND listing_id = ? AND is_read = 0";
    $stmt = $conn->prepare($unread_query);
    if ($stmt) {
        $stmt->bind_param("iii", $user_id, $owner_id, $listing_id);
        if ($stmt->execute()) {
            $unread_result = $stmt->get_result();
            if ($unread_result) {
                $unread_count = $unread_result->fetch_assoc()['count'];
            }
        }
        $stmt->close();
    }
}

// Helper functions
function formatPrice($price) {
    if (!$price) return 'Price on request';
    return '₦' . number_format($price, 0);
}

function getListingTypeBadge($type) {
    switch($type) {
        case 'sale': return 'bg-success';
        case 'rent': return 'bg-info';
        case 'lease': return 'bg-warning';
        default: return 'bg-secondary';
    }
}

function timeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $time);
    }
}

function getCategoryDisplay($property_type, $listing_type) {
    if ($property_type == 'land') return 'Land';
    if ($property_type == 'apartment') return 'Apartment';
    if ($listing_type == 'rent') return 'Rental';
    return 'Property';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($listing['title']); ?> - Ebonyi Property Hub</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <style>
        :root {
            --primary: #ff7b00;
            --primary-dark: #e66a00;
            --secondary: #2c3e50;
        }
        
        body {
            background-color: #f5f7fa;
            font-family: 'Inter', -apple-system, sans-serif;
        }
        
        .property-gallery {
            position: relative;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .main-image {
            width: 100%;
            height: 500px;
            object-fit: cover;
        }
        
        .thumbnail-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 10px;
            margin-top: 10px;
        }
        
        .thumbnail {
            height: 80px;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            opacity: 0.7;
        }
        
        .thumbnail:hover,
        .thumbnail.active {
            opacity: 1;
            box-shadow: 0 0 0 3px var(--primary);
        }
        
        .property-header {
            background: white;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .property-price {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--primary);
        }
        
        .property-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #666;
        }
        
        .meta-item i {
            width: 20px;
            color: var(--primary);
        }
        
        .badge-custom {
            padding: 8px 16px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .agent-card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            position: sticky;
            top: 90px;
        }
        
        .agent-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary);
            margin-bottom: 15px;
        }
        
        .contact-info {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 15px;
            margin-top: 20px;
        }
        
        .detail-section {
            background: white;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--secondary);
            position: relative;
            padding-bottom: 10px;
        }
        
        .section-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 3px;
            background: var(--primary);
        }
        
        .feature-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            list-style: none;
            padding: 0;
        }
        
        .feature-list li {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #555;
        }
        
        .feature-list li i {
            color: var(--primary);
        }
        
        .similar-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            height: 100%;
        }
        
        .similar-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }
        
        .similar-image {
            height: 160px;
            width: 100%;
            object-fit: cover;
        }
        
        .similar-content {
            padding: 15px;
        }
        
        .similar-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--dark);
        }
        
        .similar-price {
            font-weight: 700;
            color: var(--primary);
        }
        
        .login-required {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            margin-top: 20px;
        }
        
        .btn-favorite {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 10;
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #dc3545;
            font-size: 1.2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            cursor: pointer;
            border: none;
            text-decoration: none;
        }
        
        .btn-favorite:hover {
            transform: scale(1.1);
            background: white;
            color: #dc3545;
        }
        
        .btn-favorite.active {
            background: #dc3545;
            color: white;
        }
        
        .alert {
            border-radius: 12px;
            border: none;
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
            animation: slideDown 0.5s ease-in-out;
            z-index: 9999;
        }
        
        @keyframes slideDown {
            from {
                transform: translateY(-100%);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .conversation-preview {
            max-height: 350px;
            overflow-y: auto;
            padding-right: 5px;
        }
        
        .conversation-preview::-webkit-scrollbar {
            width: 6px;
        }
        
        .conversation-preview::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .conversation-preview::-webkit-scrollbar-thumb {
            background: #ccc;
            border-radius: 10px;
        }
        
        @media (max-width: 768px) {
            .main-image {
                height: 300px;
            }
            .thumbnail {
                height: 60px;
            }
            .property-price {
                font-size: 1.8rem;
            }
            .agent-card {
                position: static;
                margin-top: 20px;
            }
        }
    </style>
</head>
<body>

<?php
// Display alert messages from session
if (isset($_SESSION['alert'])) {
    $alert = $_SESSION['alert'];
    echo '<div id="alertBox" class="alert alert-' . $alert['type'] . ' alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-4 z-3" style="max-width: 90%; width: 500px;" role="alert">
            <div class="d-flex align-items-center">
                <i class="fas ' . ($alert['type'] == 'success' ? 'fa-check-circle' : ($alert['type'] == 'warning' ? 'fa-exclamation-triangle' : 'fa-exclamation-circle')) . ' me-2"></i>
                <span>' . $alert['message'] . '</span>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>';
    unset($_SESSION['alert']);
}
?>

<!-- Simple Navigation -->
<nav class="navbar navbar-expand-lg bg-white shadow-sm">
    <div class="container">
        <a class="navbar-brand" href="index.php">
            <img src="img/log.png" alt="Ebonyi Property Hub" height="40">
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link" href="index.php">
                        <i class="fas fa-home me-1"></i> Home
                    </a>
                </li>
                <?php if ($isLoggedIn): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-tachometer-alt me-1"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">
                            <i class="fas fa-sign-out-alt me-1"></i> Logout
                        </a>
                    </li>
                <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link" href="index.php#loginModal">
                            <i class="fas fa-sign-in-alt me-1"></i> Login
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link btn btn-primary text-white px-3" href="index.php#signupModal">
                            <i class="fas fa-user-plus me-1"></i> Register
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<!-- Main Content -->
<div class="container my-4">
    <div class="row">
        <!-- Breadcrumb -->
        <div class="col-12 mb-3">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">Home</a></li>
                    <li class="breadcrumb-item"><a href="properties.php" class="text-decoration-none">
                        <?php echo getCategoryDisplay($listing['property_type'], $listing['listing_type']); ?>
                    </a></li>
                    <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars(substr($listing['title'], 0, 50)); ?></li>
                </ol>
            </nav>
        </div>
        
        <!-- Left Column - Gallery and Details -->
        <div class="col-lg-8">
            <!-- Gallery Section - ALL IMAGES FROM DATABASE -->
            <div class="property-gallery position-relative">
                <?php if ($isLoggedIn): ?>
                    <a href="property_details.php?id=<?php echo $listing_id; ?>&action=<?php echo $is_favorited ? 'remove_favorite' : 'add_favorite'; ?>" 
                       class="btn-favorite <?php echo $is_favorited ? 'active' : ''; ?>">
                        <i class="fas fa-heart"></i>
                    </a>
                <?php endif; ?>
                
                <!-- Main Image - FROM DATABASE -->
                <?php
                // Get primary image from database ONLY
                $main_image = '';
                if (!empty($listing_images)) {
                    foreach ($listing_images as $img) {
                        if ($img['is_primary']) {
                            $main_image = $img['image_url'];
                            break;
                        }
                    }
                    // If no primary image, use first image
                    if (empty($main_image) && !empty($listing_images[0])) {
                        $main_image = $listing_images[0]['image_url'];
                    }
                }
                
                // If no images in database, show a message
                if (empty($main_image)) {
                    $main_image = ''; // No default image - will show message
                }
                ?>
                
                <?php if (!empty($main_image)): ?>
                    <img id="mainImage" src="<?php echo htmlspecialchars($main_image); ?>" 
                         alt="<?php echo htmlspecialchars($listing['title']); ?>" 
                         class="main-image w-100">
                <?php else: ?>
                    <div class="main-image w-100 bg-light d-flex align-items-center justify-content-center">
                        <div class="text-center">
                            <i class="fas fa-image fa-4x text-muted mb-3"></i>
                            <p class="text-muted">No image available</p>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Thumbnails - FROM DATABASE ONLY -->
                <?php if (!empty($listing_images) && count($listing_images) > 1): ?>
                    <div class="thumbnail-grid mt-2">
                        <?php 
                        $count = 0;
                        foreach ($listing_images as $img): 
                            if ($count >= 5) break;
                        ?>
                            <img src="<?php echo htmlspecialchars($img['image_url']); ?>" 
                                 alt="Thumbnail" 
                                 class="thumbnail w-100 <?php echo ($img['is_primary'] || ($count === 0 && empty($main_image_selected))) ? 'active' : ''; ?>"
                                 onclick="document.getElementById('mainImage').src = this.src; document.querySelectorAll('.thumbnail').forEach(t => t.classList.remove('active')); this.classList.add('active');">
                        <?php 
                            $count++;
                        endforeach; 
                        ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Property Header - ALL FROM DATABASE -->
            <div class="property-header">
                <div class="d-flex flex-wrap justify-content-between align-items-start">
                    <div>
                        <h1 class="h2 mb-2"><?php echo htmlspecialchars($listing['title']); ?></h1>
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <span class="badge bg-primary badge-custom">
                                <i class="fas fa-tag me-1"></i> For <?php echo ucfirst($listing['listing_type']); ?>
                            </span>
                            <span class="badge <?php echo getListingTypeBadge($listing['listing_type']); ?> badge-custom">
                                <i class="fas fa-home me-1"></i> <?php echo ucfirst($listing['property_type']); ?>
                            </span>
                            <?php if ($listing['is_featured']): ?>
                                <span class="badge bg-warning text-dark badge-custom">
                                    <i class="fas fa-star me-1"></i> Featured
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted">
                            <i class="fas fa-map-marker-alt me-1 text-primary"></i> 
                            <?php echo htmlspecialchars($listing['location']); ?>, <?php echo htmlspecialchars($listing['state']); ?>
                        </div>
                    </div>
                    <div class="mt-3 mt-md-0">
                        <div class="property-price"><?php echo formatPrice($listing['price']); ?></div>
                        <div class="text-muted text-end">
                            <small>Listed <?php echo timeAgo($listing['created_at']); ?></small>
                        </div>
                    </div>
                </div>
                
                <!-- Property Meta - ALL FROM DATABASE -->
                <div class="property-meta">
                    <?php if ($listing['bedrooms']): ?>
                        <div class="meta-item">
                            <i class="fas fa-bed"></i>
                            <span><strong><?php echo $listing['bedrooms']; ?></strong> Bedrooms</span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($listing['bathrooms']): ?>
                        <div class="meta-item">
                            <i class="fas fa-bath"></i>
                            <span><strong><?php echo $listing['bathrooms']; ?></strong> Bathrooms</span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($listing['floor_area']): ?>
                        <div class="meta-item">
                            <i class="fas fa-vector-square"></i>
                            <span><strong><?php echo number_format($listing['floor_area']); ?></strong> sq m</span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($listing['land_area']): ?>
                        <div class="meta-item">
                            <i class="fas fa-tree"></i>
                            <span><strong><?php echo number_format($listing['land_area']); ?></strong> sq m (Land)</span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($listing['item_condition'] && $listing['item_condition'] != 'used'): ?>
                        <div class="meta-item">
                            <i class="fas fa-certificate"></i>
                            <span><strong><?php echo ucfirst($listing['item_condition']); ?></strong> Condition</span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($listing['brand']): ?>
                        <div class="meta-item">
                            <i class="fas fa-tag"></i>
                            <span><strong><?php echo htmlspecialchars($listing['brand']); ?></strong> Brand</span>
                        </div>
                    <?php endif; ?>
                    
                    <div class="meta-item">
                        <i class="fas fa-eye"></i>
                        <span><strong><?php echo number_format($listing['views_count']); ?></strong> Views</span>
                    </div>
                </div>
            </div>
            
            <!-- Description Section - FROM DATABASE -->
            <div class="detail-section">
                <h3 class="section-title">Description</h3>
                <div class="description-content">
                    <?php echo nl2br(htmlspecialchars($listing['description'])); ?>
                </div>
            </div>
            
            <!-- Property Features - FROM DATABASE -->
            <?php if ($listing['property_type'] != 'other' && $listing['property_type'] != 'land'): ?>
            <div class="detail-section">
                <h3 class="section-title">Property Features</h3>
                <ul class="feature-list">
                    <li><i class="fas fa-check-circle"></i> <?php echo $listing['bedrooms'] ?? '0'; ?> Bedrooms</li>
                    <li><i class="fas fa-check-circle"></i> <?php echo $listing['bathrooms'] ?? '0'; ?> Bathrooms</li>
                    <li><i class="fas fa-check-circle"></i> <?php echo number_format($listing['floor_area'] ?? 0); ?> sq m Floor Area</li>
                    <li><i class="fas fa-check-circle"></i> Type: <?php echo ucfirst($listing['property_type']); ?></li>
                    <li><i class="fas fa-check-circle"></i> Listing: For <?php echo ucfirst($listing['listing_type']); ?></li>
                    <li><i class="fas fa-check-circle"></i> Listed: <?php echo date('M j, Y', strtotime($listing['created_at'])); ?></li>
                </ul>
            </div>
            <?php endif; ?>
            
            <!-- Land Features - FROM DATABASE -->
            <?php if ($listing['property_type'] == 'land'): ?>
            <div class="detail-section">
                <h3 class="section-title">Land Details</h3>
                <ul class="feature-list">
                    <li><i class="fas fa-check-circle"></i> Land Area: <?php echo number_format($listing['land_area'] ?? $listing['floor_area'] ?? 0); ?> sq m</li>
                    <li><i class="fas fa-check-circle"></i> Type: Land</li>
                    <li><i class="fas fa-check-circle"></i> Listing: For <?php echo ucfirst($listing['listing_type']); ?></li>
                    <li><i class="fas fa-check-circle"></i> Listed: <?php echo date('M j, Y', strtotime($listing['created_at'])); ?></li>
                </ul>
            </div>
            <?php endif; ?>
            
            <!-- Item Details - FROM DATABASE -->
            <?php if ($listing['property_type'] == 'other' && ($listing['item_condition'] || $listing['brand'])): ?>
            <div class="detail-section">
                <h3 class="section-title">Item Details</h3>
                <ul class="feature-list">
                    <li><i class="fas fa-check-circle"></i> Condition: <?php echo ucfirst($listing['item_condition'] ?? 'Used'); ?></li>
                    <?php if ($listing['brand']): ?>
                        <li><i class="fas fa-check-circle"></i> Brand: <?php echo htmlspecialchars($listing['brand']); ?></li>
                    <?php endif; ?>
                    <li><i class="fas fa-check-circle"></i> Price: <?php echo formatPrice($listing['price']); ?></li>
                    <li><i class="fas fa-check-circle"></i> Listed: <?php echo date('M j, Y', strtotime($listing['created_at'])); ?></li>
                </ul>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Right Column - Agent/Seller Info FROM DATABASE -->
        <div class="col-lg-4">
            <div class="agent-card">
                <!-- Seller Info - FROM DATABASE -->
                <div class="text-center">
                    <?php 
                    $avatar_url = !empty($listing['avatar_url']) ? $listing['avatar_url'] : 'https://ui-avatars.com/api/?name=' . urlencode($listing['first_name'] ?? $listing['username'] ?? 'User') . '&background=ff7b00&color=fff';
                    ?>
                    <img src="<?php echo htmlspecialchars($avatar_url); ?>" 
                         alt="<?php echo htmlspecialchars($listing['first_name'] ?? $listing['username']); ?>" 
                         class="agent-avatar">
                    <h4><?php echo htmlspecialchars(trim(($listing['first_name'] ?? '') . ' ' . ($listing['last_name'] ?? ''))); ?></h4>
                    <p class="text-muted mb-1">
                        <i class="fas fa-user-tag me-1"></i> <?php echo ucfirst($listing['role'] ?? 'Seller'); ?>
                    </p>
                    <p class="text-muted small">
                        <i class="fas fa-calendar-alt me-1"></i> Member since <?php echo date('M Y', strtotime($listing['user_member_since'] ?? $listing['created_at'])); ?>
                    </p>
                </div>
                
                <!-- Contact Information - FROM DATABASE -->
                <div class="contact-info">
                    <h5 class="mb-3"><i class="fas fa-address-card me-2 text-primary"></i> Contact Information</h5>
                    
                    <?php if ($isLoggedIn): ?>
                        <!-- Show full contact info from database to logged in users -->
                        <div class="mb-3">
                            <label class="text-muted small mb-1">Contact Person</label>
                            <p class="mb-2 fw-bold">
                                <i class="fas fa-user me-2 text-primary"></i> 
                                <?php echo htmlspecialchars($listing['contact_name'] ?? trim(($listing['first_name'] ?? '') . ' ' . ($listing['last_name'] ?? ''))); ?>
                            </p>
                        </div>
                        
                        <?php if (!empty($listing['contact_phone'])): ?>
                        <div class="mb-3">
                            <label class="text-muted small mb-1">Phone Number</label>
                            <p class="mb-2">
                                <i class="fas fa-phone me-2 text-primary"></i> 
                                <a href="tel:<?php echo htmlspecialchars($listing['contact_phone']); ?>" class="text-decoration-none">
                                    <?php echo htmlspecialchars($listing['contact_phone']); ?>
                                </a>
                            </p>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($listing['contact_email']) || !empty($listing['user_email'])): ?>
                        <div class="mb-3">
                            <label class="text-muted small mb-1">Email</label>
                            <p class="mb-2">
                                <i class="fas fa-envelope me-2 text-primary"></i>
                                <a href="mailto:<?php echo htmlspecialchars($listing['contact_email'] ?? $listing['user_email']); ?>" class="text-decoration-none">
                                    <?php echo htmlspecialchars($listing['contact_email'] ?? $listing['user_email']); ?>
                                </a>
                            </p>
                        </div>
                        <?php endif; ?>
                        
                        <hr>
                        
                        <?php if ($listing['user_id'] != $_SESSION['user_id']): ?>
                            <!-- Inquiry Form -->
                            <h5 class="mb-3"><i class="fas fa-paper-plane me-2 text-primary"></i> Send Message to Owner</h5>
                            <form method="POST" action="property_details.php?id=<?php echo $listing_id; ?>" class="inquiry-form">
                                <input type="hidden" name="receiver_id" value="<?php echo $listing['user_id']; ?>">
                                <input type="hidden" name="listing_id" value="<?php echo $listing_id; ?>">
                                
                                <div class="mb-3">
                                    <textarea class="form-control" name="message" rows="4" 
                                              placeholder="Hi, I'm interested in your listing. Is it still available? Please let me know when I can view it." 
                                              required></textarea>
                                </div>
                                <button type="submit" name="send_inquiry" class="btn btn-primary w-100">
                                    <i class="fas fa-paper-plane me-2"></i> Send Inquiry
                                </button>
                            </form>

                            <!-- Conversation History -->
                            <?php if (!empty($recent_messages)): ?>
                            <div class="mt-4">
                                <h5 class="mb-3"><i class="fas fa-comments me-2 text-primary"></i> Previous Messages</h5>
                                <div class="conversation-preview">
                                    <?php 
                                    $reversed_messages = array_reverse($recent_messages);
                                    foreach ($reversed_messages as $msg): 
                                    ?>
                                        <div class="d-flex mb-3 <?php echo $msg['sender_id'] == $_SESSION['user_id'] ? 'justify-content-end' : ''; ?>">
                                            <div class="message-bubble" style="max-width: 80%; <?php echo $msg['sender_id'] == $_SESSION['user_id'] ? 'background: var(--primary); color: white; border-radius: 15px 15px 0 15px;' : 'background: #f0f2f5; color: #333; border-radius: 15px 15px 15px 0;'; ?> padding: 10px 15px;">
                                                <div class="small fw-bold mb-1">
                                                    <?php echo htmlspecialchars($msg['sender_display_name']); ?>
                                                    <span class="small ms-2 text-muted"><?php echo timeAgo($msg['created_at']); ?></span>
                                                </div>
                                                <div><?php echo nl2br(htmlspecialchars($msg['message'])); ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i> This is your own listing.
                            </div>
                        <?php endif; ?>
                        
                    <?php else: ?>
                        <!-- Show login required message -->
                        <div class="login-required">
                            <i class="fas fa-lock fa-3x text-muted mb-3"></i>
                            <h5>Contact information is hidden</h5>
                            <p class="text-muted mb-3">Please login to view seller contact details and send inquiries.</p>
                            <a href="index.php#loginModal" class="btn btn-primary">
                                <i class="fas fa-sign-in-alt me-2"></i> Login to View
                            </a>
                            <p class="mt-3 mb-0">
                                <small>Don't have an account? 
                                    <a href="index.php#signupModal" class="text-primary">Register here</a>
                                </small>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Share Listing -->
                <div class="mt-4">
                    <h6 class="mb-2"><i class="fas fa-share-alt me-2"></i> Share this listing</h6>
                    <div class="d-flex gap-2">
                        <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>" 
                           target="_blank" class="btn btn-outline-primary flex-fill">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                        <a href="https://twitter.com/intent/tweet?url=<?php echo urlencode('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>&text=<?php echo urlencode($listing['title']); ?>" 
                           target="_blank" class="btn btn-outline-info flex-fill">
                            <i class="fab fa-twitter"></i>
                        </a>
                        <a href="https://wa.me/?text=<?php echo urlencode($listing['title'] . ' - ' . 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>" 
                           target="_blank" class="btn btn-outline-success flex-fill">
                            <i class="fab fa-whatsapp"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Similar Listings - FROM DATABASE ONLY -->
    <?php if (!empty($similar_listings)): ?>
    <div class="row mt-5">
        <div class="col-12">
            <h3 class="section-title mb-4">Similar Listings</h3>
        </div>
        
        <?php foreach ($similar_listings as $similar): ?>
        <div class="col-lg-3 col-md-6 mb-4">
            <div class="similar-card">
                <?php
                // Get image for similar listing from database
                $similar_image = '';
                $similar_img_query = "SELECT image_url FROM listing_images WHERE listing_id = ? AND is_primary = 1 LIMIT 1";
                $img_stmt = $conn->prepare($similar_img_query);
                if ($img_stmt) {
                    $img_stmt->bind_param("i", $similar['id']);
                    $img_stmt->execute();
                    $img_result = $img_stmt->get_result();
                    if ($img_row = $img_result->fetch_assoc()) {
                        $similar_image = $img_row['image_url'];
                    }
                    $img_stmt->close();
                }
                ?>
                <img src="<?php echo !empty($similar_image) ? htmlspecialchars($similar_image) : ''; ?>" 
                     alt="<?php echo htmlspecialchars($similar['title']); ?>" 
                     class="similar-image"
                     onerror="this.src='https://via.placeholder.com/300x200?text=No+Image'">
                <div class="similar-content">
                    <h5 class="similar-title"><?php echo htmlspecialchars(substr($similar['title'], 0, 40)) . (strlen($similar['title']) > 40 ? '...' : ''); ?></h5>
                    <div class="text-muted small mb-2">
                        <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($similar['location']); ?>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="similar-price"><?php echo formatPrice($similar['price']); ?></span>
                        <a href="property_details.php?id=<?php echo $similar['id']; ?>" class="btn btn-sm btn-outline-primary">
                            View <i class="fas fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Footer -->
<footer class="bg-dark text-white mt-5 py-4">
    <div class="container">
        <div class="row">
            <div class="col-md-6">
                <p>&copy; <?php echo date('Y'); ?> Ebonyi Property Hub. All rights reserved.</p>
            </div>
            <div class="col-md-6 text-md-end">
                <a href="index.php" class="text-white text-decoration-none me-3">Home</a>
                <a href="about.php" class="text-white text-decoration-none me-3">About</a>
                <a href="contact.php" class="text-white text-decoration-none">Contact</a>
            </div>
        </div>
    </div>
</footer>

<script>
    // Image change function
    function changeImage(src, element) {
        const mainImage = document.getElementById('mainImage');
        if (mainImage) {
            mainImage.src = src;
        }
        document.querySelectorAll('.thumbnail').forEach(thumb => {
            thumb.classList.remove('active');
        });
        element.classList.add('active');
    }
    
    // Auto-hide alerts
    setTimeout(function() {
        const alertBox = document.getElementById("alertBox");
        if (alertBox) {
            alertBox.style.transition = "opacity 0.5s ease";
            alertBox.style.opacity = "0";
            setTimeout(() => {
                if (alertBox.parentNode) {
                    alertBox.remove();
                }
            }, 500);
        }
    }, 5000);
</script>

<?php
// Close connection
$conn->close();
?>
</body>
</html>