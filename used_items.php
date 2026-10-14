<?php
session_start();
require 'config.php';

// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Make mysqli throw real exceptions on query/prepare errors
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Check if user is logged in
$isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

// ============================================
// HANDLE ITEM DETAILS VIEW
// ============================================
$view_item_id = isset($_GET['view']) ? intval($_GET['view']) : 0;
$viewing_item = false;
$item = null;

if ($view_item_id > 0) {
    // Fetch item details with user info
    // FIX: Removed categories join since category_id is NULL
    $query = "SELECT l.*, 
              u.username, u.store_name, u.store_verified, u.avatar_url, u.store_description, 
              u.first_name, u.last_name, u.phone, u.email
              FROM listings l 
              LEFT JOIN users u ON l.user_id = u.id 
              WHERE l.id = ? AND l.status = 'active'";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $view_item_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $item = $result->fetch_assoc();
        $viewing_item = true;
        
        // Increment view count
        $update_view = "UPDATE listings SET views_count = views_count + 1 WHERE id = ?";
        $view_stmt = $conn->prepare($update_view);
        $view_stmt->bind_param("i", $view_item_id);
        $view_stmt->execute();
        $view_stmt->close();
        
        // Check if item is in user's cart
        $in_cart = false;
        if ($isLoggedIn) {
            $cart_query = "SELECT * FROM cart_items WHERE user_id = ? AND listing_id = ?";
            $cart_stmt = $conn->prepare($cart_query);
            $cart_stmt->bind_param("ii", $_SESSION['user_id'], $view_item_id);
            $cart_stmt->execute();
            $cart_result = $cart_stmt->get_result();
            $in_cart = $cart_result->num_rows > 0;
            $cart_stmt->close();
        }
        
        // Check if in favorites
        $is_favorite = false;
        if ($isLoggedIn) {
            $fav_check = "SELECT * FROM favorites WHERE user_id = ? AND listing_id = ?";
            $fav_stmt = $conn->prepare($fav_check);
            $fav_stmt->bind_param("ii", $_SESSION['user_id'], $view_item_id);
            $fav_stmt->execute();
            $fav_result = $fav_stmt->get_result();
            $is_favorite = $fav_result->num_rows > 0;
            $fav_stmt->close();
        }
        
        // Get similar items - FIX: Use category column (text) instead of category_id
        if (!empty($item['category'])) {
            $similar_query = "SELECT id, title, price, location, image, category 
                              FROM listings 
                              WHERE category = ? 
                              AND id != ? 
                              AND status = 'active' 
                              ORDER BY created_at DESC LIMIT 4";
            $similar_stmt = $conn->prepare($similar_query);
            $similar_stmt->bind_param("si", $item['category'], $view_item_id);
            $similar_stmt->execute();
            $similar_items = $similar_stmt->get_result();
            $similar_stmt->close();
        }
    }
    $stmt->close();
}

// ============================================
// HANDLE ADD TO CART
// ============================================
if (isset($_POST['add_to_cart']) && $isLoggedIn) {
    $item_id = intval($_POST['item_id']);
    $user_id = $_SESSION['user_id'];
    
    $check_query = "SELECT * FROM cart_items WHERE user_id = ? AND listing_id = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ii", $user_id, $item_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $update_query = "UPDATE cart_items SET quantity = quantity + 1 WHERE user_id = ? AND listing_id = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("ii", $user_id, $item_id);
        $update_stmt->execute();
        $update_stmt->close();
    } else {
        $insert_query = "INSERT INTO cart_items (user_id, listing_id, quantity) VALUES (?, ?, 1)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("ii", $user_id, $item_id);
        $insert_stmt->execute();
        $insert_stmt->close();
    }
    $check_stmt->close();
    
    $_SESSION['alert'] = ['type' => 'success', 'message' => 'Item added to cart!'];
    header('Location: used_items.php?view=' . $item_id);
    exit();
}

// ============================================
// HANDLE FAVORITES
// ============================================
if (isset($_POST['add_to_favorites']) && $isLoggedIn) {
    $item_id = intval($_POST['item_id']);
    $user_id = $_SESSION['user_id'];
    
    $check_query = "SELECT * FROM favorites WHERE user_id = ? AND listing_id = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ii", $user_id, $item_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows == 0) {
        $insert_query = "INSERT INTO favorites (user_id, listing_id) VALUES (?, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("ii", $user_id, $item_id);
        $insert_stmt->execute();
        $insert_stmt->close();
        $_SESSION['alert'] = ['type' => 'success', 'message' => 'Added to favorites!'];
    } else {
        $_SESSION['alert'] = ['type' => 'info', 'message' => 'Already in favorites.'];
    }
    $check_stmt->close();
    
    header('Location: used_items.php?view=' . $item_id);
    exit();
}

if (isset($_POST['remove_from_favorites']) && $isLoggedIn) {
    $item_id = intval($_POST['item_id']);
    $user_id = $_SESSION['user_id'];
    
    $delete_query = "DELETE FROM favorites WHERE user_id = ? AND listing_id = ?";
    $delete_stmt = $conn->prepare($delete_query);
    $delete_stmt->bind_param("ii", $user_id, $item_id);
    $delete_stmt->execute();
    $delete_stmt->close();
    
    $_SESSION['alert'] = ['type' => 'success', 'message' => 'Removed from favorites.'];
    header('Location: used_items.php?view=' . $item_id);
    exit();
}

// ============================================
// SEARCH AND FILTERING
// ============================================
$search_query = isset($_GET['q']) ? trim($_GET['q']) : '';
$min_price = isset($_GET['min_price']) ? floatval($_GET['min_price']) : 0;
$max_price = isset($_GET['max_price']) ? floatval($_GET['max_price']) : 0;
$location = isset($_GET['location']) ? trim($_GET['location']) : '';
$category_filter = isset($_GET['category']) ? trim($_GET['category']) : ''; // FIX: Now uses text category name
$condition = isset($_GET['condition']) ? trim($_GET['condition']) : '';
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'newest';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$items_per_page = 12;
$offset = ($page - 1) * $items_per_page;

// ============================================
// FETCH CATEGORIES THAT ACTUALLY HAVE ACTIVE LISTINGS
// FIX: Use l.category (text field) directly since category_id is NULL
// ============================================
$item_categories = [];
$cat_query = "SELECT category, COUNT(id) AS listing_count
              FROM listings 
              WHERE status = 'active'
              AND category IS NOT NULL 
              AND category != ''
              GROUP BY category
              HAVING listing_count > 0
              ORDER BY category ASC";
$cat_result = $conn->query($cat_query);
if ($cat_result) {
    while ($row = $cat_result->fetch_assoc()) {
        $item_categories[] = $row;
    }
}

// ============================================
// BUILD WHERE CLAUSE ONCE - reused by BOTH the count query
// and the main item query so they can never fall out of sync.
// ============================================
$where = " WHERE l.status = 'active'";
$params = [];
$types = '';

// Search filter (title/description/location/category)
if (!empty($search_query)) {
    $where .= " AND (l.title LIKE ? OR l.description LIKE ? OR l.location LIKE ? OR l.category LIKE ?)";
    $search_param = "%$search_query%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ssss';
}

// Price filters
if ($min_price > 0) {
    $where .= " AND l.price >= ?";
    $params[] = $min_price;
    $types .= 'd';
}

if ($max_price > 0) {
    $where .= " AND l.price <= ?";
    $params[] = $max_price;
    $types .= 'd';
}

// Location filter
if (!empty($location)) {
    $where .= " AND l.location LIKE ?";
    $params[] = "%$location%";
    $types .= 's';
}

// Category filter - FIX: Now filters on the text category column
if (!empty($category_filter)) {
    $where .= " AND l.category = ?";
    $params[] = $category_filter;
    $types .= 's';
}

// Condition filter
if (!empty($condition)) {
    $where .= " AND l.item_condition = ?";
    $params[] = $condition;
    $types .= 's';
}

// Add sorting (whitelisted values only)
$order_by = " ORDER BY l.created_at DESC";
switch ($sort_by) {
    case 'price_low':
        $order_by = " ORDER BY l.price ASC";
        break;
    case 'price_high':
        $order_by = " ORDER BY l.price DESC";
        break;
    case 'oldest':
        $order_by = " ORDER BY l.created_at ASC";
        break;
}

// ============================================
// COUNT QUERY - built independently from the same $where
// ============================================
$count_query = "SELECT COUNT(*) as total 
                FROM listings l 
                LEFT JOIN users u ON l.user_id = u.id" . $where;
$count_stmt = $conn->prepare($count_query);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_count = $count_result->fetch_assoc()['total'];
$count_stmt->close();

// ============================================
// MAIN ITEM QUERY (adds pagination on top of the same $where)
// FIX: Removed categories join since category_id is NULL
// ============================================
$query = "SELECT l.*, 
          u.username, u.store_name, u.store_verified, u.avatar_url, u.store_description 
          FROM listings l 
          LEFT JOIN users u ON l.user_id = u.id" . $where . $order_by . " LIMIT ? OFFSET ?";

$main_params = $params;
$main_types = $types . 'ii';
$main_params[] = $items_per_page;
$main_params[] = $offset;

$stmt = $conn->prepare($query);
$stmt->bind_param($main_types, ...$main_params);
$stmt->execute();
$items_result = $stmt->get_result();

$items = [];
if ($items_result) {
    while ($row = $items_result->fetch_assoc()) {
        $items[] = $row;
    }
}
$stmt->close();

$total_pages = ceil($total_count / $items_per_page);

// Get unique locations for filter
$locations_query = "SELECT DISTINCT location FROM listings WHERE status = 'active' AND location IS NOT NULL AND location != '' ORDER BY location ASC";
$locations_result = $conn->query($locations_query);
$locations = [];
if ($locations_result) {
    while ($row = $locations_result->fetch_assoc()) {
        $locations[] = $row['location'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $viewing_item ? htmlspecialchars($item['title']) : 'Used Items'; ?> - Ebonyi Market Hub</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #ff7b00;
            --primary-dark: #e66a00;
            --secondary: #2c3e50;
            --light: #f8f9fa;
            --dark: #343a40;
            --gray: #6c757d;
        }

        body {
            background-color: #f5f5f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .page-header {
            background: linear-gradient(135deg, var(--secondary) 0%, #1a2632 100%);
            color: white;
            padding: 60px 0 40px;
            margin-bottom: 40px;
        }

        .page-header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }

        .filter-sidebar {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            position: sticky;
            top: 20px;
        }

        .filter-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--secondary);
            border-bottom: 2px solid var(--primary);
            padding-bottom: 10px;
        }

        .item-card {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.3s, box-shadow 0.3s;
            margin-bottom: 30px;
            height: 100%;
            position: relative;
        }

        .item-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }

        .item-image {
            height: 200px;
            overflow: hidden;
            position: relative;
        }

        .item-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s;
        }

        .item-card:hover .item-image img {
            transform: scale(1.05);
        }

        .item-badge {
            position: absolute;
            top: 15px;
            left: 15px;
            background: var(--primary);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .condition-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .item-content {
            padding: 20px;
        }

        .item-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--secondary);
        }

        .item-title a {
            color: inherit;
            text-decoration: none;
            transition: color 0.3s;
        }

        .item-title a:hover {
            color: var(--primary);
        }

        .item-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            color: var(--gray);
            font-size: 0.85rem;
            margin-bottom: 10px;
        }

        .item-meta i {
            color: var(--primary);
            margin-right: 3px;
        }

        .item-price {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary);
            margin: 10px 0;
        }

        .item-seller {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 10px 0;
            padding: 8px 10px;
            background: var(--light);
            border-radius: 8px;
        }

        .item-seller .seller-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            object-fit: cover;
        }

        .item-seller .seller-name {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--secondary);
            text-decoration: none;
        }

        .item-seller .seller-name:hover {
            color: var(--primary);
        }

        .item-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .btn-view {
            flex: 1;
            background: var(--primary);
            color: white;
            border: none;
            padding: 8px;
            border-radius: 5px;
            font-weight: 600;
            transition: background 0.3s;
            text-decoration: none;
            text-align: center;
        }

        .btn-view:hover {
            background: var(--primary-dark);
            color: white;
        }

        .btn-cart {
            flex: 1;
            background: var(--secondary);
            color: white;
            border: none;
            padding: 8px;
            border-radius: 5px;
            font-weight: 600;
            transition: background 0.3s;
        }

        .btn-cart:hover {
            background: var(--dark);
            color: white;
        }

        .no-items {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 10px;
        }

        .no-items i {
            font-size: 4rem;
            color: #ddd;
            margin-bottom: 20px;
        }

        .pagination {
            justify-content: center;
            margin-top: 30px;
        }

        .page-link {
            color: var(--primary);
        }

        .page-item.active .page-link {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .alert-success {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            animation: slideInRight 0.3s ease-out;
        }

        .store-badge {
            padding: 2px 8px;
            border-radius: 50px;
            font-size: 0.6rem;
            font-weight: 600;
            margin-left: 5px;
        }
        
        .store-verified {
            background: #28a745;
            color: white;
        }

        .store-unverified {
            background: var(--gray);
            color: white;
        }

        /* Item Detail Styles */
        .item-detail-container {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-top: 20px;
        }

        .item-image-main {
            width: 100%;
            height: 400px;
            object-fit: cover;
            border-radius: 10px;
        }

        .item-price-large {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
        }

        .seller-card {
            background: var(--light);
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
        }

        .seller-card .seller-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
        }

        .similar-item-card {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.3s;
            height: 100%;
        }

        .similar-item-card:hover {
            transform: translateY(-5px);
        }

        .similar-item-card img {
            width: 100%;
            height: 150px;
            object-fit: cover;
        }

        .similar-item-card .similar-body {
            padding: 15px;
        }

        .similar-item-card .similar-title {
            font-size: 0.95rem;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .similar-item-card .similar-price {
            color: var(--primary);
            font-weight: 700;
        }

        .status-badge {
            padding: 5px 15px;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .status-badge.active { background: #d4edda; color: #155724; }
        .status-badge.sold { background: #f8d7da; color: #721c24; }
        .status-badge.pending { background: #fff3cd; color: #856404; }

        .btn-back {
            display: inline-block;
            margin-bottom: 15px;
            color: var(--secondary);
            text-decoration: none;
        }
        .btn-back:hover {
            color: var(--primary);
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @media (max-width: 768px) {
            .filter-sidebar {
                margin-bottom: 20px;
                position: static;
            }
            
            .item-image {
                height: 180px;
            }
            
            .item-actions {
                flex-direction: column;
            }

            .item-image-main {
                height: 250px;
            }

            .item-detail-container {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <?php 
    // Fix: Check if navbar.php exists, if not use fallback navigation
    if (file_exists('navbar.php')) {
        include 'navbar.php';
    } else {
        // Simple fallback navigation if navbar.php is missing
        echo '<nav class="navbar navbar-expand-lg navbar-light bg-light">
                <div class="container">
                    <a class="navbar-brand" href="index.php">Ebonyi Market Hub</a>
                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <div class="collapse navbar-collapse" id="navbarNav">
                        <ul class="navbar-nav ms-auto">
                            <li class="nav-item"><a class="nav-link" href="index.php">Home</a></li>
                            <li class="nav-item"><a class="nav-link" href="used_items.php">Used Items</a></li>
                            ' . ($isLoggedIn ? '<li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                            <li class="nav-item"><a class="nav-link" href="logout.php">Logout</a></li>' : 
                            '<li class="nav-item"><a class="nav-link" href="login.php">Login</a></li>
                            <li class="nav-item"><a class="nav-link" href="register.php">Register</a></li>') . '
                        </ul>
                    </div>
                </div>
            </nav>';
    }
    ?>

    <?php
    // Display alert messages
    if (isset($_SESSION['alert'])) {
        $alert = $_SESSION['alert'];
        echo '<div class="alert alert-' . $alert['type'] . ' alert-dismissible fade show" id="successMessage" style="position: fixed; top: 20px; right: 20px; z-index: 9999;">
                <i class="fas ' . ($alert['type'] == 'success' ? 'fa-check-circle' : ($alert['type'] == 'danger' ? 'fa-exclamation-circle' : 'fa-info-circle')) . ' me-2"></i>
                <span>' . $alert['message'] . '</span>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
              </div>';
        unset($_SESSION['alert']);
    }
    ?>

    <?php if ($viewing_item && $item): ?>
        <!-- ============================================ -->
        <!-- ITEM DETAILS VIEW -->
        <!-- ============================================ -->
        <div class="container">
            <a href="used_items.php" class="btn-back">
                <i class="fas fa-arrow-left"></i> Back to Items
            </a>

            <div class="row">
                <div class="col-lg-8">
                    <div class="item-detail-container">
                        <!-- Image -->
                        <div class="mb-4">
                            <?php 
                            // Get image from the 'image' column
                            $item_image = '';
                            if (!empty($item['image'])) {
                                // Check if it's a JSON array
                                if (strpos($item['image'], '[') === 0) {
                                    $decoded = json_decode($item['image'], true);
                                    if (is_array($decoded) && !empty($decoded)) {
                                        $item_image = $decoded[0];
                                    }
                                } else {
                                    $item_image = $item['image'];
                                }
                            }
                            
                            if (!empty($item_image)): ?>
                                <img src="<?php echo htmlspecialchars($item_image); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>" class="item-image-main">
                            <?php else: ?>
                                <?php 
                                $default_images = [
                                    'Electronics' => 'https://images.unsplash.com/photo-1498049794561-7780e7231661?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80',
                                    'Vehicles' => 'https://images.unsplash.com/photo-1492144534655-ae79c964c9d7?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80',
                                    'Furniture' => 'https://images.unsplash.com/photo-1555041469-a586c61ea9bc?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80',
                                    'Services' => 'https://images.unsplash.com/photo-1558618666-fcd25c85cd64?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80'
                                ];
                                $cat_key = isset($item['category']) ? $item['category'] : '';
                                $img = isset($default_images[$cat_key]) ? $default_images[$cat_key] : 'https://images.unsplash.com/photo-1563013544-824ae1b704d3?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80';
                                ?>
                                <img src="<?php echo $img; ?>" alt="<?php echo htmlspecialchars($item['title']); ?>" class="item-image-main">
                            <?php endif; ?>
                        </div>

                        <!-- Title and Status -->
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <h1 class="h2"><?php echo htmlspecialchars($item['title']); ?></h1>
                            <span class="status-badge <?php echo $item['status'] ?? 'active'; ?>">
                                <?php echo ucfirst($item['status'] ?? 'Active'); ?>
                            </span>
                        </div>

                        <!-- Meta Info -->
                        <div class="d-flex flex-wrap gap-3 mb-3 text-muted">
                            <span><i class="fas fa-map-marker-alt text-primary"></i> <?php echo htmlspecialchars($item['location']); ?></span>
                            <?php if (!empty($item['category'])): ?>
                                <span><i class="fas fa-tag text-primary"></i> <?php echo htmlspecialchars($item['category']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($item['item_condition'])): ?>
                                <span><i class="fas fa-certificate text-primary"></i> <?php echo ucfirst($item['item_condition']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($item['brand'])): ?>
                                <span><i class="fas fa-industry text-primary"></i> <?php echo htmlspecialchars($item['brand']); ?></span>
                            <?php endif; ?>
                            <span><i class="fas fa-eye text-primary"></i> <?php echo number_format($item['views_count'] ?? 0); ?> views</span>
                            <span><i class="fas fa-calendar text-primary"></i> Posted <?php echo date('M d, Y', strtotime($item['created_at'])); ?></span>
                        </div>

                        <!-- Price -->
                        <div class="item-price-large mb-3">₦<?php echo number_format($item['price'], 2); ?></div>

                        <!-- Description -->
                        <div class="mb-4">
                            <h5>Description</h5>
                            <p class="text-muted" style="white-space: pre-line;"><?php echo nl2br(htmlspecialchars($item['description'])); ?></p>
                        </div>

                        <!-- Additional Details from real schema columns -->
                        <div class="mb-4">
                            <h5>Details</h5>
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <?php if (!empty($item['listing_type'])): ?>
                                        <tr><th style="width: 40%;">Listing Type</th><td><?php echo ucfirst(htmlspecialchars($item['listing_type'])); ?></td></tr>
                                    <?php endif; ?>
                                    <?php if (!empty($item['contact_name'])): ?>
                                        <tr><th>Contact Name</th><td><?php echo htmlspecialchars($item['contact_name']); ?></td></tr>
                                    <?php endif; ?>
                                    <?php if (!empty($item['contact_phone'])): ?>
                                        <tr><th>Contact Phone</th><td><?php echo htmlspecialchars($item['contact_phone']); ?></td></tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="col-lg-4">
                    <!-- Seller Info -->
                    <?php if (!empty($item['username'])): ?>
                        <div class="seller-card">
                            <h5 class="mb-3"><i class="fas fa-store text-primary"></i> Seller Information</h5>
                            <div class="d-flex align-items-center mb-3">
                                <?php if (!empty($item['avatar_url'])): ?>
                                    <img src="<?php echo htmlspecialchars($item['avatar_url']); ?>" alt="<?php echo htmlspecialchars($item['username']); ?>" class="seller-avatar">
                                <?php else: ?>
                                    <div style="width: 60px; height: 60px; border-radius: 50%; background: var(--primary); color: white; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; font-weight: 600;">
                                        <?php echo strtoupper(substr($item['username'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                                <div class="ms-3">
                                    <h6 class="mb-0">
                                        <a href="stores.php?user_id=<?php echo $item['user_id']; ?>" style="color: var(--secondary); text-decoration: none;">
                                            <?php echo htmlspecialchars($item['store_name'] ?? $item['username']); ?>
                                        </a>
                                        <?php if ($item['store_verified'] == 1): ?>
                                            <span class="store-badge store-verified"><i class="fas fa-check"></i> Verified</span>
                                        <?php endif; ?>
                                    </h6>
                                    <small class="text-muted">Member since <?php echo date('M Y', strtotime($item['created_at'])); ?></small>
                                </div>
                            </div>

                            <?php if (!empty($item['store_description'])): ?>
                                <p class="text-muted small"><?php echo htmlspecialchars($item['store_description']); ?></p>
                            <?php endif; ?>

                            <hr>
                            <div class="d-flex flex-column gap-2">
                                <?php if (!empty($item['phone'])): ?>
                                    <a href="tel:<?php echo htmlspecialchars($item['phone']); ?>" class="btn btn-outline-primary btn-sm">
                                        <i class="fas fa-phone"></i> <?php echo htmlspecialchars($item['phone']); ?>
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($item['email'])): ?>
                                    <a href="mailto:<?php echo htmlspecialchars($item['email']); ?>" class="btn btn-outline-secondary btn-sm">
                                        <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($item['email']); ?>
                                    </a>
                                <?php endif; ?>
                                <a href="stores.php?user_id=<?php echo $item['user_id']; ?>" class="btn btn-primary btn-sm">
                                    <i class="fas fa-store"></i> View Store
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Actions -->
                    <div class="item-detail-container mt-3">
                        <h5 class="mb-3">Actions</h5>
                        <div class="d-grid gap-2">
                            <?php if ($isLoggedIn): ?>
                                <?php if ($in_cart): ?>
                                    <a href="cart_items.php" class="btn btn-success">
                                        <i class="fas fa-shopping-cart"></i> Already in Cart
                                    </a>
                                <?php else: ?>
                                    <form method="POST">
                                        <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                        <button type="submit" name="add_to_cart" class="btn btn-primary w-100">
                                            <i class="fas fa-shopping-cart"></i> Add to Cart
                                        </button>
                                    </form>
                                <?php endif; ?>
                                
                                <form method="POST">
                                    <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                    <?php if ($is_favorite): ?>
                                        <button type="submit" name="remove_from_favorites" class="btn btn-outline-danger w-100">
                                            <i class="fas fa-heart"></i> Remove from Favorites
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" name="add_to_favorites" class="btn btn-outline-primary w-100">
                                            <i class="fas fa-heart"></i> Add to Favorites
                                        </button>
                                    <?php endif; ?>
                                </form>
                            <?php else: ?>
                                <button class="btn btn-primary w-100" onclick="window.location.href='login.php?redirect=<?php echo urlencode('used_items.php?view=' . $item['id']); ?>'">
                                    <i class="fas fa-sign-in-alt"></i> Login to Purchase
                                </button>
                                <button class="btn btn-outline-primary w-100" onclick="window.location.href='login.php?redirect=<?php echo urlencode('used_items.php?view=' . $item['id']); ?>'">
                                    <i class="fas fa-heart"></i> Login to Save
                                </button>
                            <?php endif; ?>
                            
                            <?php if ($isLoggedIn && $_SESSION['user_id'] == $item['user_id']): ?>
                                <a href="edit_listing.php?id=<?php echo $item['id']; ?>" class="btn btn-outline-secondary w-100">
                                    <i class="fas fa-edit"></i> Edit Listing
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Share -->
                    <div class="item-detail-container mt-3">
                        <h5 class="mb-3">Share this Item</h5>
                        <div class="d-flex gap-2 flex-wrap">
                            <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode('https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>" target="_blank" class="btn btn-outline-primary">
                                <i class="fab fa-facebook-f"></i>
                            </a>
                            <a href="https://twitter.com/intent/tweet?url=<?php echo urlencode('https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>&text=<?php echo urlencode($item['title']); ?>" target="_blank" class="btn btn-outline-info">
                                <i class="fab fa-twitter"></i>
                            </a>
                            <a href="https://wa.me/?text=<?php echo urlencode($item['title'] . ' - ' . 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>" target="_blank" class="btn btn-outline-success">
                                <i class="fab fa-whatsapp"></i>
                            </a>
                            <button class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText(window.location.href); alert('Link copied to clipboard!');">
                                <i class="fas fa-link"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Similar Items -->
            <?php if (isset($similar_items) && $similar_items && $similar_items->num_rows > 0): ?>
                <div class="mt-5">
                    <h4 class="mb-3">Similar Items</h4>
                    <div class="row">
                        <?php while ($similar = $similar_items->fetch_assoc()): ?>
                            <div class="col-md-3 col-6">
                                <a href="used_items.php?view=<?php echo $similar['id']; ?>" style="text-decoration: none; color: inherit;">
                                    <div class="similar-item-card">
                                        <?php 
                                        // Get image from 'image' column for similar items
                                        $sim_image = '';
                                        if (!empty($similar['image'])) {
                                            if (strpos($similar['image'], '[') === 0) {
                                                $decoded = json_decode($similar['image'], true);
                                                if (is_array($decoded) && !empty($decoded)) {
                                                    $sim_image = $decoded[0];
                                                }
                                            } else {
                                                $sim_image = $similar['image'];
                                            }
                                        }
                                        
                                        if (!empty($sim_image)): ?>
                                            <img src="<?php echo htmlspecialchars($sim_image); ?>" alt="<?php echo htmlspecialchars($similar['title']); ?>" loading="lazy">
                                        <?php else: ?>
                                            <img src="https://images.unsplash.com/photo-1563013544-824ae1b704d3?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80" alt="<?php echo htmlspecialchars($similar['title']); ?>" loading="lazy">
                                        <?php endif; ?>
                                        <div class="similar-body">
                                            <div class="similar-title"><?php echo htmlspecialchars(substr($similar['title'], 0, 30)); ?></div>
                                            <div class="similar-price">₦<?php echo number_format($similar['price'], 2); ?></div>
                                            <small class="text-muted"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($similar['location']); ?></small>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <!-- ============================================ -->
        <!-- ITEMS LISTING VIEW -->
        <!-- ============================================ -->
        
        <!-- Page Header -->
        <div class="page-header">
            <div class="container">
                <div class="row">
                    <div class="col-md-8">
                        <h1><i class="fas fa-box-open me-2"></i>Used Items Marketplace</h1>
                        <p>Quality second-hand electronics, furniture, vehicles, and more at affordable prices</p>
                    </div>
                    <div class="col-md-4">
                        <form action="" method="GET" class="d-flex">
                            <input type="text" name="q" class="form-control me-2" placeholder="Search items..." value="<?php echo htmlspecialchars($search_query); ?>">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="container mb-5">
            <div class="row">
                <!-- Sidebar Filters -->
                <div class="col-lg-3">
                    <div class="filter-sidebar">
                        <h5 class="filter-title">Filter Items</h5>
                        
                        <form action="" method="GET" id="filterForm">
                            <?php if (!empty($search_query)): ?>
                                <input type="hidden" name="q" value="<?php echo htmlspecialchars($search_query); ?>">
                            <?php endif; ?>
                            
                            <!-- Category Filter - FIX: Now uses text category names -->
                            <div class="mb-3">
                                <label class="form-label">Category</label>
                                <select name="category" class="form-select" onchange="this.form.submit()">
                                    <option value="">All Categories</option>
                                    <?php foreach ($item_categories as $cat): ?>
                                        <option value="<?php echo htmlspecialchars($cat['category']); ?>" <?php echo $category_filter == $cat['category'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cat['category']); ?> (<?php echo $cat['listing_count']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Condition</label>
                                <select name="condition" class="form-select" onchange="this.form.submit()">
                                    <option value="">Any Condition</option>
                                    <option value="new" <?php echo $condition == 'new' ? 'selected' : ''; ?>>New</option>
                                    <option value="used" <?php echo $condition == 'used' ? 'selected' : ''; ?>>Used</option>
                                    <option value="refurbished" <?php echo $condition == 'refurbished' ? 'selected' : ''; ?>>Refurbished</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Location</label>
                                <select name="location" class="form-select" onchange="this.form.submit()">
                                    <option value="">All Locations</option>
                                    <?php foreach ($locations as $loc): ?>
                                        <option value="<?php echo htmlspecialchars($loc); ?>" <?php echo $location == $loc ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($loc); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Min Price (₦)</label>
                                <input type="number" name="min_price" class="form-control" value="<?php echo $min_price > 0 ? $min_price : ''; ?>" placeholder="Min">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Max Price (₦)</label>
                                <input type="number" name="max_price" class="form-control" value="<?php echo $max_price > 0 ? $max_price : ''; ?>" placeholder="Max">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Sort By</label>
                                <select name="sort" class="form-select" onchange="this.form.submit()">
                                    <option value="newest" <?php echo $sort_by == 'newest' ? 'selected' : ''; ?>>Newest First</option>
                                    <option value="oldest" <?php echo $sort_by == 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                                    <option value="price_low" <?php echo $sort_by == 'price_low' ? 'selected' : ''; ?>>Price: Low to High</option>
                                    <option value="price_high" <?php echo $sort_by == 'price_high' ? 'selected' : ''; ?>>Price: High to Low</option>
                                </select>
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100">Apply Filters</button>
                            <?php if ($min_price > 0 || $max_price > 0 || !empty($location) || !empty($category_filter) || !empty($condition)): ?>
                                <a href="used_items.php" class="btn btn-outline-secondary w-100 mt-2">Clear Filters</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
                
                <!-- Items Grid -->
                <div class="col-lg-9">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="mb-0"><?php echo $total_count; ?> Items Found</h4>
                        <button class="btn btn-outline-primary" onclick="checkLoginBeforePost('item')">
                            <i class="fas fa-plus-circle me-2"></i>Sell an Item
                        </button>
                    </div>
                    
                    <?php if (!empty($items)): ?>
                        <div class="row">
                            <?php foreach ($items as $item): ?>
                                <div class="col-md-6 col-lg-4">
                                    <div class="item-card">
                                        <div class="item-image">
                                            <?php 
                                            // Get image from 'image' column
                                            $item_img = '';
                                            if (!empty($item['image'])) {
                                                if (strpos($item['image'], '[') === 0) {
                                                    $decoded = json_decode($item['image'], true);
                                                    if (is_array($decoded) && !empty($decoded)) {
                                                        $item_img = $decoded[0];
                                                    }
                                                } else {
                                                    $item_img = $item['image'];
                                                }
                                            }
                                            
                                            if (!empty($item_img)): ?>
                                                <img src="<?php echo htmlspecialchars($item_img); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>" loading="lazy">
                                            <?php else: ?>
                                                <?php 
                                                $default_images = [
                                                    'Electronics' => 'https://images.unsplash.com/photo-1498049794561-7780e7231661?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80',
                                                    'Vehicles' => 'https://images.unsplash.com/photo-1492144534655-ae79c964c9d7?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80',
                                                    'Furniture' => 'https://images.unsplash.com/photo-1555041469-a586c61ea9bc?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80',
                                                    'Services' => 'https://images.unsplash.com/photo-1558618666-fcd25c85cd64?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80'
                                                ];
                                                $cat_key = isset($item['category']) ? $item['category'] : '';
                                                $img = isset($default_images[$cat_key]) ? $default_images[$cat_key] : 'https://images.unsplash.com/photo-1563013544-824ae1b704d3?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80';
                                                ?>
                                                <img src="<?php echo $img; ?>" alt="<?php echo htmlspecialchars($item['title']); ?>" loading="lazy">
                                            <?php endif; ?>
                                            <span class="item-badge"><?php echo htmlspecialchars($item['category'] ?? 'Item'); ?></span>
                                            <?php if (!empty($item['item_condition'])): ?>
                                                <span class="condition-badge"><?php echo ucfirst($item['item_condition']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="item-content">
                                            <h3 class="item-title">
                                                <a href="used_items.php?view=<?php echo $item['id']; ?>">
                                                    <?php echo htmlspecialchars(substr($item['title'], 0, 50)); ?>
                                                </a>
                                            </h3>
                                            
                                            <div class="item-meta">
                                                <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($item['location']); ?></span>
                                                <?php if (!empty($item['brand'])): ?>
                                                    <span><i class="fas fa-tag"></i> <?php echo htmlspecialchars($item['brand']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="item-price">
                                                ₦<?php echo number_format($item['price'], 2); ?>
                                            </div>
                                            
                                            <!-- Seller Info -->
                                            <?php if (!empty($item['username'])): ?>
                                                <div class="item-seller">
                                                    <?php if (!empty($item['avatar_url'])): ?>
                                                        <img src="<?php echo htmlspecialchars($item['avatar_url']); ?>" alt="<?php echo htmlspecialchars($item['username']); ?>" class="seller-avatar">
                                                    <?php else: ?>
                                                        <div style="width: 30px; height: 30px; border-radius: 50%; background: var(--primary); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.8rem;">
                                                            <?php echo strtoupper(substr($item['username'], 0, 1)); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <a href="stores.php?user_id=<?php echo $item['user_id']; ?>" class="seller-name">
                                                        <?php echo htmlspecialchars($item['store_name'] ?? $item['username']); ?>
                                                        <?php if ($item['store_verified'] == 1): ?>
                                                            <span class="store-badge store-verified"><i class="fas fa-check"></i> Verified</span>
                                                        <?php endif; ?>
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <div class="item-actions">
                                                <a href="used_items.php?view=<?php echo $item['id']; ?>" class="btn-view">
                                                    View Details
                                                </a>
                                                <?php if ($isLoggedIn): ?>
                                                    <form method="POST" style="flex: 1;">
                                                        <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                                        <button type="submit" name="add_to_cart" class="btn-cart">
                                                            <i class="fas fa-shopping-cart"></i> Add to Cart
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <nav>
                                <ul class="pagination">
                                    <?php if ($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">Previous</a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    // Show pagination with ellipsis for many pages
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);
                                    
                                    if ($start_page > 1) {
                                        echo '<li class="page-item"><a class="page-link" href="?page=1&' . http_build_query(array_merge($_GET, ['page' => 1])) . '">1</a></li>';
                                        if ($start_page > 2) {
                                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                        }
                                    }
                                    
                                    for ($i = $start_page; $i <= $end_page; $i++): ?>
                                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $i; ?>&<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($end_page < $total_pages): ?>
                                        <?php if ($end_page < $total_pages - 1): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                        <?php endif; ?>
                                        <li class="page-item"><a class="page-link" href="?page=<?php echo $total_pages; ?>&<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>"><?php echo $total_pages; ?></a></li>
                                    <?php endif; ?>
                                    
                                    <?php if ($page < $total_pages): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next</a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                        <?php endif; ?>
                        
                    <?php else: ?>
                        <div class="no-items">
                            <i class="fas fa-box-open"></i>
                            <h3>No Items Found</h3>
                            <p>We couldn't find any items matching your criteria.</p>
                            <a href="used_items.php" class="btn btn-primary">View All Items</a>
                            <button class="btn btn-outline-primary ms-2" onclick="checkLoginBeforePost('item')">Sell an Item</button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php 
    // Fix: Check if footer.php exists
    if (file_exists('footer.php')) {
        include 'footer.php';
    } else {
        echo '<footer class="bg-dark text-white text-center py-3 mt-5">
                <div class="container">
                    <p class="mb-0">&copy; ' . date('Y') . ' Ebonyi Market Hub. All rights reserved.</p>
                </div>
            </footer>';
    }
    ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const isLoggedIn = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;
        
        function checkLoginBeforePost(type) {
            if (!isLoggedIn) {
                showLoginRequired('post', type);
                return false;
            }
            window.location.href = 'post_listing.php?type=' + type;
            return true;
        }
        
        function showLoginRequired(action, type) {
            alert('Please login to ' + action + ' a ' + type);
            window.location.href = 'login.php?redirect=' + encodeURIComponent(window.location.href);
        }
        
        // Auto-hide success message
        setTimeout(function() {
            const successMessage = document.getElementById('successMessage');
            if (successMessage) {
                successMessage.style.transition = 'opacity 0.5s ease';
                successMessage.style.opacity = '0';
                setTimeout(() => {
                    if (successMessage.parentNode) {
                        successMessage.remove();
                    }
                }, 500);
            }
        }, 5000);
    </script>
</body>
</html>

<?php $conn->close(); ?>