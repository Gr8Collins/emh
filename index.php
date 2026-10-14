<?php
session_start();

// Database connection
require 'config.php';

// ============================================
// HANDLE REDIRECT AFTER LOGIN
// ============================================
// Store the redirect URL in session if user came from a specific page
if (!isset($_SESSION['redirect_after_login']) && isset($_SERVER['HTTP_REFERER'])) {
    $referer = $_SERVER['HTTP_REFERER'];
    // Check if user was trying to add to cart
    if (strpos($referer, 'item_details.php') !== false && (isset($_GET['add_to_cart']) || strpos($referer, 'add_to_cart') !== false)) {
        $_SESSION['redirect_after_login'] = $referer;
    }
}

// Handle login - UPDATED
if (isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['phone'] = $user['phone'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['logged_in'] = true;
            $_SESSION['avatar_url'] = $user['avatar_url'] ?? null;
            $_SESSION['store_description'] = $user['store_description'] ?? null;
            
            $_SESSION['store_name'] = $user['store_name'] ?? $user['username'] . "'s Store";
            $_SESSION['store_verified'] = $user['store_verified'] ?? 0;
            $_SESSION['is_vendor'] = $user['is_vendor'] ?? 0;
            
            $_SESSION['alert'] = [
                'type' => 'success',
                'message' => 'Login successful! Welcome back ' . $user['username'] . '.'
            ];
            
            // ============================================
            // DETERMINE WHERE TO REDIRECT
            // ============================================
            if (isset($_SESSION['redirect_after_login']) && !empty($_SESSION['redirect_after_login'])) {
                $redirect_url = $_SESSION['redirect_after_login'];
                unset($_SESSION['redirect_after_login']); // Clear it after use
                header('Location: ' . $redirect_url);
                exit();
            } else {
                // Normal login from home page → go to dashboard
                header('Location: dashboard.php');
                exit();
            }
        } else {
            $_SESSION['alert'] = [
                'type' => 'danger',
                'message' => 'Invalid password.'
            ];
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
            exit();
        }
    } else {
        $_SESSION['alert'] = [
            'type' => 'danger',
            'message' => 'User not found.'
        ];
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
        exit();
    }
    $stmt->close();
}

// Handle registration with storefront creation - UPDATED
if (isset($_POST['signup'])) {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = $_POST['role'] ?? 'user';
    $is_vendor = ($role === 'vendor') ? 1 : 0;
    $store_name = trim($_POST['store_name'] ?? $username . "'s Store");
    
    if (empty($username) || empty($email) || empty($password)) {
        $_SESSION['alert'] = [
            'type' => 'danger',
            'message' => 'All required fields must be filled.'
        ];
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
        exit();
    } else {
        $check_stmt = $conn->prepare("SELECT id, username, email FROM users WHERE email = ? OR username = ?");
        if (!$check_stmt) {
            $_SESSION['alert'] = [
                'type' => 'danger',
                'message' => 'Database error: ' . $conn->error
            ];
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
            exit();
        } else {
            $check_stmt->bind_param("ss", $email, $username);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $existing_user = $check_result->fetch_assoc();
                $message = 'User with ';
                if ($existing_user['email'] == $email) {
                    $message .= 'email "' . $email . '"';
                }
                if ($existing_user['email'] == $email && $existing_user['username'] == $username) {
                    $message .= ' and ';
                }
                if ($existing_user['username'] == $username) {
                    $message .= 'username "' . $username . '"';
                }
                $message .= ' already exists.';
                
                $_SESSION['alert'] = [
                    'type' => 'warning',
                    'message' => $message
                ];
                header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
                exit();
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $conn->prepare("INSERT INTO users (username, email, password, first_name, last_name, phone, role, status, is_vendor, store_name, store_verified, store_description) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, 0, ?)");
                
                if (!$stmt) {
                    $_SESSION['alert'] = [
                        'type' => 'danger',
                        'message' => 'Prepare error: ' . $conn->error
                    ];
                    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
                    exit();
                } else {
                    $store_description = "Welcome to my store! Quality products and services.";
                    $stmt->bind_param("sssssssiss", $username, $email, $hashed_password, $first_name, $last_name, $phone, $role, $is_vendor, $store_name, $store_description);
                    
                    if ($stmt->execute()) {
                        $user_id = $stmt->insert_id;
                        
                        $store_stmt = $conn->prepare("INSERT INTO store_settings (user_id, store_name, store_slogan, store_description) VALUES (?, ?, ?, ?)");
                        $store_slogan = "Quality products at great prices";
                        $store_stmt->bind_param("isss", $user_id, $store_name, $store_slogan, $store_description);
                        $store_stmt->execute();
                        $store_stmt->close();
                        
                        $_SESSION['user_id'] = $user_id;
                        $_SESSION['username'] = $username;
                        $_SESSION['role'] = $role;
                        $_SESSION['first_name'] = $first_name;
                        $_SESSION['last_name'] = $last_name;
                        $_SESSION['phone'] = $phone;
                        $_SESSION['email'] = $email;
                        $_SESSION['logged_in'] = true;
                        $_SESSION['is_vendor'] = $is_vendor;
                        $_SESSION['store_name'] = $store_name;
                        $_SESSION['store_verified'] = 0;
                        $_SESSION['store_description'] = $store_description;
                        $_SESSION['avatar_url'] = null;
                        
                        $_SESSION['alert'] = [
                            'type' => 'success',
                            'message' => 'Registration successful! Welcome to Ebonyi Market Hub, ' . $username . '. Your store "' . $store_name . '" has been created.'
                        ];
                        
                        // After registration, check if there's a redirect
                        if (isset($_SESSION['redirect_after_login']) && !empty($_SESSION['redirect_after_login'])) {
                            $redirect_url = $_SESSION['redirect_after_login'];
                            unset($_SESSION['redirect_after_login']);
                            header('Location: ' . $redirect_url);
                            exit();
                        } else {
                            header('Location: dashboard.php');
                            exit();
                        }
                    } else {
                        $_SESSION['alert'] = [
                            'type' => 'danger',
                            'message' => 'Error: ' . $stmt->error
                        ];
                        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
                        exit();
                    }
                    $stmt->close();
                }
            }
            $check_stmt->close();
        }
    }
}

// ============================================
// FETCH ALL CATEGORIES FROM DATABASE
// ============================================
$categories = [];
$categories_query = "SELECT * FROM categories WHERE status = 'active' ORDER BY name ASC";
$categories_result = $conn->query($categories_query);
if ($categories_result && $categories_result->num_rows > 0) {
    while ($row = $categories_result->fetch_assoc()) {
        $categories[] = $row;
    }
}

// ============================================
// GET LISTING COUNTS FOR EACH CATEGORY
// ============================================
$category_counts = [];
if (!empty($categories)) {
    foreach ($categories as $category) {
        $count_query = "SELECT COUNT(*) as count FROM listings WHERE category = ? AND status = 'active'";
        $stmt = $conn->prepare($count_query);
        if ($stmt) {
            $stmt->bind_param("s", $category['name']);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $category_counts[$category['name']] = $row['count'] ?? 0;
            $stmt->close();
        }
    }
}

// ============================================
// FETCH FEATURED LISTINGS - GROUPED BY CATEGORY
// ============================================
$featured_listings_by_category = [];

// Get all distinct categories from listings that have active listings
$distinct_categories_query = "SELECT DISTINCT category FROM listings WHERE status = 'active'";
$distinct_result = $conn->query($distinct_categories_query);

if ($distinct_result && $distinct_result->num_rows > 0) {
    while ($cat_row = $distinct_result->fetch_assoc()) {
        $category_name = $cat_row['category'];
        
        // Get listings for this category
        $query = "SELECT l.*, u.username, u.store_name, u.store_verified, u.id as user_id, u.avatar_url, u.store_description 
                  FROM listings l 
                  LEFT JOIN users u ON l.user_id = u.id 
                  WHERE l.category = ? AND l.status = 'active' 
                  ORDER BY l.created_at DESC LIMIT 6";
        $stmt = $conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("s", $category_name);
            $stmt->execute();
            $result = $stmt->get_result();
            $listings = [];
            while ($row = $result->fetch_assoc()) {
                $listings[] = $row;
            }
            if (!empty($listings)) {
                $featured_listings_by_category[$category_name] = $listings;
            }
            $stmt->close();
        }
    }
}

// Fetch featured stores (vendors AND landlords with active listings)
$featured_stores = [];
$stores_query = "SELECT u.id, u.username, u.store_name, u.store_verified, u.store_rating, u.role, u.avatar_url, u.store_description,
                 COUNT(l.id) as listing_count,
                 (SELECT COUNT(*) FROM store_reviews WHERE store_id = u.id AND is_approved = 1) as review_count
                 FROM users u
                 LEFT JOIN listings l ON u.id = l.user_id AND l.status = 'active'
                 WHERE (u.is_vendor = 1 OR u.role = 'landlord') AND u.status = 'active'
                 GROUP BY u.id
                 ORDER BY listing_count DESC, u.store_rating DESC
                 LIMIT 8";
$stores_result = $conn->query($stores_query);
if ($stores_result && $stores_result->num_rows > 0) {
    while ($row = $stores_result->fetch_assoc()) {
        $featured_stores[] = $row;
    }
}

// Check if user is logged in
$isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

// ============================================
// GET CART COUNT FOR LOGGED IN USER
// ============================================
$cartCount = 0;
if ($isLoggedIn && isset($_SESSION['user_id'])) {
    // Ensure cart_items table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'cart_items'");
    if ($tableCheck->num_rows === 0) {
        // Create cart_items table if it doesn't exist
        $createTable = "CREATE TABLE cart_items (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            listing_id INT(11) NOT NULL,
            quantity INT(11) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_cart_item (user_id, listing_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $conn->query($createTable);
    }
    
    // Also ensure orders table has required columns
    $colCheck = $conn->query("SHOW COLUMNS FROM orders LIKE 'quantity'");
    if ($colCheck->num_rows === 0) {
        $conn->query("ALTER TABLE orders ADD COLUMN quantity INT(11) NOT NULL DEFAULT 1");
    }
    $colCheck = $conn->query("SHOW COLUMNS FROM orders LIKE 'delivery_address'");
    if ($colCheck->num_rows === 0) {
        $conn->query("ALTER TABLE orders ADD COLUMN delivery_address TEXT");
    }
    $colCheck = $conn->query("SHOW COLUMNS FROM orders LIKE 'subtotal'");
    if ($colCheck->num_rows === 0) {
        $conn->query("ALTER TABLE orders ADD COLUMN subtotal DECIMAL(10,2) DEFAULT 0");
    }
    $colCheck = $conn->query("SHOW COLUMNS FROM orders LIKE 'order_number'");
    if ($colCheck->num_rows === 0) {
        $conn->query("ALTER TABLE orders ADD COLUMN order_number VARCHAR(50) UNIQUE");
    }
    
    $countStmt = $conn->prepare("SELECT SUM(quantity) as total FROM cart_items WHERE user_id = ?");
    if ($countStmt) {
        $countStmt->bind_param("i", $_SESSION['user_id']);
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $countRow = $countResult->fetch_assoc();
        $cartCount = (int)($countRow['total'] ?? 0);
        $countStmt->close();
    }
}

// Helper functions
function getStoreOwnerImage($user) {
    if (!empty($user['avatar_url'])) {
        return htmlspecialchars($user['avatar_url']);
    }
    return 'https://ui-avatars.com/api/?name=' . urlencode($user['username'] ?? 'User') . '&background=ff7b00&color=fff&size=80';
}

function getStoreDescription($user) {
    if (!empty($user['store_description'])) {
        return htmlspecialchars($user['store_description']);
    }
    return 'Welcome to my store!';
}

function getStoreName($user) {
    if (!empty($user['store_name'])) {
        return htmlspecialchars($user['store_name']);
    }
    return htmlspecialchars($user['username'] . "'s Store");
}

// Get the first category with listings for default active tab
$first_category = !empty($featured_listings_by_category) ? array_key_first($featured_listings_by_category) : '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="description" content="Ebonyi Market Hub - Buy, Sell & Rent Properties, Lands, Apartments and Used Items in Ebonyi State">
    <meta name="theme-color" content="#ff7b00">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
  <style>
        :root {
            --primary: #ff7b00;
            --primary-dark: #e66a00;
            --primary-light: #ff9500;
            --secondary: #2c3e50;
            --accent: #3498db;
            --light: #f8f9fa;
            --dark: #343a40;
            --success: #28a745;
            --danger: #dc3545;
            --gray: #6c757d;
            --light-gray: #e9ecef;
            --white: #ffffff;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.05);
            --shadow-md: 0 5px 15px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 25px rgba(0,0,0,0.15);
            --border-radius: 12px;
            --border-radius-sm: 8px;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: var(--dark);
            line-height: 1.6;
            overflow-x: hidden;
            width: 100%;
        }

        h1 { font-size: clamp(1.8rem, 5vw, 3.2rem); }
        h2 { font-size: clamp(1.5rem, 4vw, 2.5rem); }
        h3 { font-size: clamp(1.2rem, 3vw, 1.5rem); }
        p { font-size: clamp(0.9rem, 2vw, 1rem); }

        .top-info-bar {
            background: var(--secondary);
            color: var(--white);
            padding: 0.5rem 5%;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            font-size: 0.85rem;
            gap: 0.5rem;
        }

        .info-items {
            display: flex;
            flex-wrap: wrap;
            list-style: none;
            gap: 1rem;
            margin: 0;
            padding: 0;
        }

        .info-items li a {
            color: var(--white);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.5rem;
            border-radius: var(--border-radius-sm);
            transition: var(--transition);
            font-size: 0.85rem;
        }

        .info-items li a:hover {
            background: rgba(255,255,255,0.1);
            color: var(--primary);
        }

        .navbar {
            background: var(--white);
            box-shadow: var(--shadow-md);
            position: sticky;
            top: 0;
            z-index: 1030;
            padding: 0;
        }

        .navbar-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 5%;
            max-width: 1440px;
            margin: 0 auto;
            width: 100%;
            gap: 1rem;
        }

        .logo {
            flex-shrink: 0;
        }

        .logo img {
            height: clamp(35px, 6vw, 45px);
            width: auto;
            transition: var(--transition);
        }

        .hamburger-lines {
            display: none;
            flex-direction: column;
            cursor: pointer;
            padding: 0.5rem;
            z-index: 1031;
        }

        .hamburger-lines span {
            display: block;
            height: 3px;
            width: 25px;
            background: var(--dark);
            margin: 3px 0;
            border-radius: 5px;
            transition: var(--transition);
        }

        .hamburger-lines.active span:nth-child(1) {
            transform: rotate(45deg) translate(5px, 5px);
        }

        .hamburger-lines.active span:nth-child(2) {
            opacity: 0;
        }

        .hamburger-lines.active span:nth-child(3) {
            transform: rotate(-45deg) translate(7px, -6px);
        }

        .menu-items {
            display: flex;
            list-style: none;
            align-items: center;
            margin: 0;
            padding: 0;
            gap: 0.15rem;
            flex: 1;
            justify-content: center;
        }

        .menu-items li {
            position: relative;
        }

        .menu-items a {
            text-decoration: none;
            color: var(--dark);
            font-weight: 500;
            padding: 0.5rem 0.6rem;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.25rem;
            border-radius: var(--border-radius-sm);
            font-size: 0.9rem;
            white-space: nowrap;
        }

        .menu-items a:hover {
            color: var(--primary);
            background: rgba(255,123,0,0.05);
        }

        .dropdown {
            position: relative;
        }

        .dropdown-menu {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            background: var(--white);
            min-width: 220px;
            box-shadow: var(--shadow-md);
            border-radius: var(--border-radius-sm);
            padding: 0.5rem 0;
            z-index: 100;
            border: 1px solid rgba(0,0,0,0.05);
            list-style: none;
        }

        .dropdown.active .dropdown-menu {
            display: block;
        }

        .dropdown-menu li {
            margin: 0;
        }

        .dropdown-menu a {
            padding: 0.5rem 1rem;
            border-radius: 0;
            font-size: 0.9rem;
            white-space: nowrap;
        }

        .dropdown-menu a:hover {
            background: var(--light);
            color: var(--primary);
        }

        /* Mobile dropdown fix */
        @media (max-width: 992px) {
            .dropdown.active .dropdown-menu {
                position: static;
                width: 100%;
                margin-top: 0.25rem;
                box-shadow: none;
                background: var(--light);
                padding: 0.25rem 0;
                border: none;
                border-radius: var(--border-radius-sm);
            }
            .dropdown.active .dropdown-menu a {
                padding: 0.5rem 0.8rem;
                font-size: 0.85rem;
            }
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            flex-shrink: 0;
        }

        .search-box {
            display: flex;
            align-items: center;
            background: var(--light);
            border-radius: 50px;
            padding: 0.4rem 0.75rem;
            border: 1px solid transparent;
            transition: var(--transition);
        }

        .search-box:hover,
        .search-box:focus-within {
            border-color: var(--primary);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(255,123,0,0.1);
        }

        .search-box i {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .search-box input {
            border: none;
            background: transparent;
            outline: none;
            padding: 0.3rem 0.5rem;
            width: 140px;
            font-size: 0.9rem;
        }

        .search-box input::placeholder {
            color: var(--gray);
        }

        .btn {
            padding: 0.5rem 1.25rem;
            border-radius: 50px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: var(--white);
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255,123,0,0.3);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: var(--white);
            transform: translateY(-2px);
        }

        .btn-success {
            background: var(--success);
            color: var(--white);
        }

        .btn-success:hover {
            background: #218838;
            transform: translateY(-2px);
        }

        /* ============================================ */
        /* HERO SECTION - IMPROVED */
        /* ============================================ */
        .hero {
            position: relative;
            width: 100%;
            min-height: 400px;
            height: 80vh;
            max-height: 700px;
            overflow: hidden;
        }

        .hero-slider {
            height: 100%;
            width: 100%;
        }

        .hero-slider .swiper-slide {
            height: 100%;
            width: 100%;
            position: relative;
        }

        .hero-slider .swiper-slide img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            filter: brightness(0.65);
        }

        .hero-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            color: var(--white);
            width: 90%;
            max-width: 900px;
            padding: 0 1rem;
            z-index: 10;
        }

        .hero-content h1 {
            font-weight: 700;
            margin-bottom: 1.5rem;
            text-shadow: 2px 2px 10px rgba(0,0,0,0.5);
        }

        .hero-content p {
            font-size: clamp(1rem, 3vw, 1.2rem);
            margin-bottom: 2rem;
            text-shadow: 1px 1px 5px rgba(0,0,0,0.5);
        }

        .hero-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            justify-content: center;
        }

        .hero-buttons .btn {
            padding: 0.75rem 1.75rem;
            font-size: 1rem;
        }

        section {
            padding: clamp(2rem, 6vw, 4rem) 5%;
        }

        .section-title {
            text-align: center;
            margin-bottom: clamp(1.5rem, 4vw, 2.5rem);
        }

        .section-title h2 {
            font-weight: 700;
            color: var(--secondary);
            margin-bottom: 1rem;
        }

        .section-title p {
            color: var(--gray);
            max-width: 700px;
            margin: 0 auto;
            font-size: 1.1rem;
        }

        /* ============================================ */
        /* FEATURES GRID */
        /* ============================================ */
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: clamp(1rem, 3vw, 2rem);
        }

        .feature-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .feature-icon {
            font-size: 2.5rem;
            color: var(--primary);
            margin-bottom: 1.25rem;
        }

        .feature-card h3 {
            margin-bottom: 0.75rem;
            color: var(--secondary);
            font-weight: 600;
        }

        .feature-card p {
            color: var(--gray);
            line-height: 1.6;
            flex-grow: 1;
        }

        /* ============================================ */
        /* CATEGORIES SECTION - SMALLER CARDS */
        /* ============================================ */
        .categories-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 1rem;
        }

        .category-card {
            padding: 0;
            overflow: hidden;
            background: var(--white);
            border-radius: 10px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            height: 100%;
            display: flex;
            flex-direction: column;
            cursor: pointer;
            text-decoration: none;
            color: var(--dark);
        }

        .category-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
            color: var(--dark);
        }

        .category-icon-wrapper {
            width: 100%;
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f0f2f5;
            border-radius: 10px 10px 0 0;
            transition: var(--transition);
        }

        .category-card:hover .category-icon-wrapper {
            background: rgba(255, 123, 0, 0.08);
        }

        .category-icon-wrapper i {
            font-size: 2rem;
            color: var(--primary);
            transition: var(--transition);
        }

        .category-card:hover .category-icon-wrapper i {
            transform: scale(1.1);
            color: var(--primary-dark);
        }

        .category-content {
            padding: 0.7rem 0.7rem 0.9rem;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            text-align: center;
        }

        .category-content h3 {
            margin-bottom: 0.2rem;
            color: var(--secondary);
            font-weight: 600;
            font-size: 0.85rem;
            line-height: 1.2;
        }

        .category-content .listing-count {
            color: var(--gray);
            font-size: 0.7rem;
            margin-bottom: 0.3rem;
        }

        .category-content .description {
            color: var(--gray);
            font-size: 0.65rem;
            margin-bottom: 0.5rem;
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .category-content .btn {
            margin-top: auto;
            align-self: center;
            font-size: 0.65rem;
            padding: 0.25rem 0.7rem;
            border-radius: 50px;
            width: 100%;
            max-width: 100px;
            border: 1.5px solid var(--primary);
            color: var(--primary);
            background: transparent;
            transition: var(--transition);
        }

        .category-content .btn:hover {
            background: var(--primary);
            color: var(--white);
        }

        /* ============================================ */
        /* FEATURED VENDORS & LANDLORDS - SMALLER CARDS */
        /* ============================================ */
        .featured-stores-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 1rem;
        }

        .store-card {
            background: var(--white);
            border-radius: 10px;
            padding: 1rem 0.8rem;
            text-align: center;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            text-decoration: none;
            color: var(--dark);
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .store-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
            color: var(--dark);
        }

        .store-card .store-avatar {
            width: 55px;
            height: 55px;
            border-radius: 50%;
            margin: 0 auto 0.6rem;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            font-weight: 600;
            overflow: hidden;
        }

        .store-card .store-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .store-card .role-pill {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 2px 8px;
            border-radius: 50px;
            font-size: 0.55rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 0.4rem;
        }

        .store-card .store-name {
            font-weight: 600;
            font-size: 0.85rem;
            margin-bottom: 0.2rem;
        }

        .store-card .store-stats {
            font-size: 0.7rem;
            color: var(--gray);
        }

        .store-card .store-rating {
            color: #f1c40f;
            margin-top: 0.3rem;
            font-size: 0.7rem;
        }

        .store-card .store-cta {
            margin-top: 0.6rem;
            font-size: 0.65rem;
            font-weight: 600;
            color: var(--primary);
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .store-card .store-description {
            font-size: 0.65rem;
            color: var(--gray);
            margin: 0.2rem 0 0.3rem;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        /* ============================================ */
        /* LISTINGS CARDS - SMALLER */
        /* ============================================ */
        .listings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 1.2rem;
        }

        .listing-card {
            background: var(--white);
            border-radius: 10px;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .listing-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }

        .listing-img {
            height: 160px;
            position: relative;
            overflow: hidden;
        }

        .listing-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .listing-card:hover .listing-img img {
            transform: scale(1.05);
        }

        .listing-badge {
            position: absolute;
            top: 0.6rem;
            left: 0.6rem;
            background: var(--primary);
            color: var(--white);
            padding: 0.2rem 0.6rem;
            border-radius: 50px;
            font-size: 0.6rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .listing-content {
            padding: 0.8rem 1rem 1rem;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }

        .listing-content h3 {
            margin-bottom: 0.3rem;
            color: var(--secondary);
            font-weight: 600;
            font-size: 0.95rem;
            line-height: 1.3;
        }

        .listing-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            color: var(--gray);
            font-size: 0.75rem;
            margin-bottom: 0.5rem;
        }

        .listing-meta span {
            display: flex;
            align-items: center;
            gap: 0.2rem;
        }

        .listing-price {
            font-weight: 700;
            color: var(--primary);
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }

        .listing-seller {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            background: var(--light);
            border-radius: 8px;
            margin-top: 6px;
            transition: var(--transition);
        }

        .listing-seller:hover {
            background: rgba(255,123,0,0.05);
        }

        .listing-seller .seller-avatar-img {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary);
            flex-shrink: 0;
        }

        .listing-seller .seller-avatar-placeholder {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.8rem;
            flex-shrink: 0;
        }

        .listing-seller .seller-info {
            flex: 1;
            min-width: 0;
        }

        .listing-seller .seller-name {
            font-weight: 600;
            font-size: 0.75rem;
            text-decoration: none;
            color: var(--dark);
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 3px;
        }

        .listing-seller .seller-name:hover {
            color: var(--primary);
        }

        .listing-seller .seller-description {
            font-size: 0.65rem;
            color: var(--gray);
            margin: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 120px;
        }

        .listing-content .btn {
            font-size: 0.7rem;
            padding: 0.25rem 0.7rem;
        }

        .listing-content .add-to-cart-btn {
            font-size: 0.7rem;
            padding: 0.25rem 0.7rem;
        }

        /* ============================================ */
        /* TABS - SMALLER */
        /* ============================================ */
        .tabs {
            display: flex;
            justify-content: center;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid var(--light-gray);
            flex-wrap: wrap;
            gap: 0.15rem;
        }

        .tab-btn {
            padding: 0.5rem 1.2rem;
            background: transparent;
            border: none;
            font-weight: 600;
            color: var(--gray);
            cursor: pointer;
            position: relative;
            transition: var(--transition);
            font-size: 0.85rem;
            border-radius: 6px 6px 0 0;
        }

        .tab-btn.active {
            color: var(--primary);
            background: rgba(255,123,0,0.05);
        }

        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            width: 100%;
            height: 2px;
            background: var(--primary);
        }

        .tab-btn .badge {
            margin-left: 4px;
            background: var(--primary);
            color: white;
            font-size: 0.6rem;
            padding: 1px 6px;
            border-radius: 50px;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .testimonials {
            background: var(--light);
        }

        .testimonial-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            height: 100%;
            display: flex;
            flex-direction: column;
            margin: 0.5rem;
        }

        .testimonial-text {
            font-style: italic;
            margin-bottom: 1.5rem;
            color: var(--dark);
            line-height: 1.6;
            position: relative;
            padding-left: 1rem;
            border-left: 3px solid var(--primary);
        }

        .testimonial-author {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .author-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid var(--primary-light);
        }

        .author-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .author-info h4 {
            color: var(--secondary);
            font-weight: 600;
            margin-bottom: 0.25rem;
            font-size: 1rem;
        }

        .author-info p {
            color: var(--gray);
            font-size: 0.8rem;
        }

        .newsletter {
            background: linear-gradient(135deg, var(--secondary) 0%, #1a2632 100%);
            color: var(--white);
            text-align: center;
            padding: 3rem 5%;
        }

        .newsletter h2 {
            margin-bottom: 1rem;
            font-weight: 700;
        }

        .newsletter p {
            max-width: 600px;
            margin: 0 auto 2rem;
            opacity: 0.9;
        }

        .newsletter-form {
            display: flex;
            max-width: 500px;
            margin: 0 auto;
            flex-wrap: wrap;
        }

        .newsletter-form input {
            flex: 1;
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 50px 0 0 50px;
            outline: none;
            font-size: 0.95rem;
        }

        .newsletter-form button {
            padding: 0 1.5rem;
            border-radius: 0 50px 50px 0;
            border: none;
            background: var(--primary);
            color: var(--white);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.95rem;
        }

        .newsletter-form button:hover {
            background: var(--primary-dark);
        }

        .footer {
            background: var(--dark);
            color: var(--white);
            padding: 3rem 5% 1.5rem;
        }

        .footer-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .footer-col h3 {
            margin-bottom: 1.25rem;
            font-size: 1.1rem;
            font-weight: 600;
            position: relative;
            padding-bottom: 0.5rem;
        }

        .footer-col h3::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 40px;
            height: 2px;
            background: var(--primary);
        }

        .footer-links {
            list-style: none;
            padding: 0;
        }

        .footer-links li {
            margin-bottom: 0.75rem;
        }

        .footer-links a {
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .footer-links a:hover {
            color: var(--primary);
            transform: translateX(5px);
        }

        .social-links {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .social-links a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            color: var(--white);
            transition: var(--transition);
            font-size: 1rem;
        }

        .social-links a:hover {
            background: var(--primary);
            transform: translateY(-3px);
        }

        .footer-bottom {
            text-align: center;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255,255,255,0.1);
            font-size: 0.85rem;
            color: rgba(255,255,255,0.6);
        }

        .modal-content {
            border-radius: var(--border-radius);
            border: none;
        }

        .modal-header {
            border-bottom: 1px solid var(--light-gray);
            padding: 1rem 1.5rem;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            border-top: 1px solid var(--light-gray);
            padding: 1rem 1.5rem;
        }

        .form-control,
        .form-select {
            border-radius: var(--border-radius-sm);
            border: 1px solid var(--light-gray);
            padding: 0.6rem 1rem;
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255,123,0,0.1);
            outline: none;
        }

        .form-label {
            font-weight: 500;
            margin-bottom: 0.4rem;
            color: var(--secondary);
            font-size: 0.9rem;
        }

        .alert {
            border-radius: var(--border-radius);
            border: none;
            box-shadow: var(--shadow-lg);
            animation: slideDown 0.5s ease-in-out;
            z-index: 9999 !important;
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

        .swiper-button-next,
        .swiper-button-prev {
            color: var(--white);
            background: rgba(0,0,0,0.3);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            transition: var(--transition);
        }

        .swiper-button-next:hover,
        .swiper-button-prev:hover {
            background: var(--primary);
        }

        .swiper-button-next:after,
        .swiper-button-prev:after {
            font-size: 1.2rem;
        }

        .swiper-pagination-bullet {
            width: 10px;
            height: 10px;
            background: var(--white);
            opacity: 0.7;
        }

        .swiper-pagination-bullet-active {
            background: var(--primary);
            opacity: 1;
        }

        .store-badge {
            padding: 2px 8px;
            border-radius: 50px;
            font-size: 0.65rem;
            font-weight: 600;
            margin-left: 5px;
            white-space: nowrap;
        }
        
        .store-verified {
            background: #28a745;
            color: white;
        }

        .store-unverified {
            background: var(--gray);
            color: white;
        }

        .role-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 50px;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 6px;
        }

        .role-pill.vendor {
            background: rgba(255,123,0,0.12);
            color: var(--primary-dark);
        }

        .role-pill.landlord {
            background: rgba(52,152,219,0.12);
            color: #1f6fa8;
        }

        .empty-listings {
            text-align: center;
            padding: 3rem 1rem;
            grid-column: 1 / -1;
        }

        .empty-listings i {
            font-size: 3rem;
            color: #dee2e6;
            margin-bottom: 1rem;
        }

        .empty-listings h5 {
            color: var(--secondary);
            margin-bottom: 0.5rem;
        }

        .empty-listings p {
            color: var(--gray);
        }

        /* Category Modal Styles */
        .category-modal-title {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .category-modal-title .category-icon {
            font-size: 2rem;
            color: var(--primary);
        }

        .category-modal-title .category-count {
            font-size: 0.9rem;
            color: var(--gray);
            font-weight: normal;
        }

        .modal-loading {
            text-align: center;
            padding: 2rem;
        }

        .modal-loading .spinner {
            width: 3rem;
            height: 3rem;
        }

        .modal-listings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: clamp(1rem, 3vw, 2rem);
        }

        /* ============================================ */
        /* CART STYLES */
        /* ============================================ */
        .cart-badge {
            position: relative;
        }

        .cart-badge .badge {
            font-size: 0.6rem;
            min-width: 18px;
        }

        .add-to-cart-btn {
            transition: all 0.3s ease;
        }

        .add-to-cart-btn:hover {
            background: var(--primary) !important;
            color: white !important;
            border-color: var(--primary) !important;
        }

        .add-to-cart-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        /* Toast notification styles */
        .toast-notification {
            position: fixed;
            top: 80px;
            right: 20px;
            background: white;
            border-radius: 8px;
            padding: 1rem 1.5rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 9999;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            min-width: 300px;
            max-width: 90%;
            animation: slideInRight 0.3s ease;
            border-left: 4px solid #17a2b8;
        }

        .toast-notification.success { border-left-color: #28a745; }
        .toast-notification.error { border-left-color: #dc3545; }
        .toast-notification.warning { border-left-color: #ffc107; }
        .toast-notification.info { border-left-color: #17a2b8; }
        
        .toast-notification i {
            font-size: 1.2rem;
        }

        .toast-notification.success i { color: #28a745; }
        .toast-notification.error i { color: #dc3545; }
        .toast-notification.warning i { color: #ffc107; }
        .toast-notification.info i { color: #17a2b8; }
        
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        /* Menu overlay for mobile */
        .menu-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1039;
        }

        .menu-overlay.active {
            display: block;
        }

        /* Mobile search toggle */
        #mobileSearchToggle {
            display: none;
        }

        /* ============================================ */
        /* RESPONSIVE - TABLET */
        /* ============================================ */
        @media (max-width: 992px) {
            .hamburger-lines {
                display: flex;
            }

            .navbar-container {
                padding: 0.5rem 1rem;
                flex-wrap: nowrap;
            }

            .menu-items {
                position: fixed;
                top: 0;
                left: -100%;
                flex-direction: column;
                background: var(--white);
                width: 85%;
                max-width: 350px;
                height: 100vh;
                padding: 80px 1.5rem 2rem;
                box-shadow: var(--shadow-lg);
                transition: left 0.3s ease;
                overflow-y: auto;
                align-items: flex-start;
                gap: 0.25rem;
                z-index: 1040;
                border-right: 1px solid var(--light-gray);
            }

            .menu-items.active {
                left: 0;
            }

            .menu-items li {
                width: 100%;
                margin: 0.15rem 0;
            }

            .menu-items a {
                padding: 0.6rem 0.8rem;
                width: 100%;
                font-size: 0.95rem;
                border-radius: var(--border-radius-sm);
            }

            .menu-items a:hover {
                background: var(--light);
            }

            .menu-items .dropdown {
                width: 100%;
            }

            .menu-items .dropdown .dropdown-menu {
                position: static;
                width: 100%;
                margin-top: 0.25rem;
                box-shadow: none;
                background: var(--light);
                padding: 0.25rem 0;
                border: none;
                border-radius: var(--border-radius-sm);
            }

            .menu-items .dropdown .dropdown-menu a {
                padding: 0.5rem 0.8rem;
                font-size: 0.85rem;
            }

            /* Nav actions - show on mobile */
            .nav-actions {
                display: flex !important;
                align-items: center;
                gap: 0.4rem;
                flex-shrink: 0;
            }

            .nav-actions .search-box {
                display: none;
            }

            .nav-actions .btn {
                padding: 0.3rem 0.6rem;
                font-size: 0.8rem;
                min-width: 36px;
                justify-content: center;
            }

            .nav-actions .btn .d-none {
                display: none !important;
            }

            .nav-actions .btn-outline {
                border-width: 1.5px;
            }

            /* Cart button - always visible */
            .nav-actions .btn[title="Cart"] {
                position: relative;
            }

            .nav-actions .btn[title="Cart"] .badge {
                font-size: 0.5rem !important;
                min-width: 16px !important;
                padding: 1px 5px !important;
            }

            #mobileSearchToggle {
                display: flex !important;
            }

            .hero {
                min-height: 350px;
                height: 60vh;
                max-height: 500px;
            }

            .hero-content h1 {
                font-size: clamp(1.5rem, 5vw, 2.2rem);
            }

            .top-info-bar {
                flex-direction: column;
                text-align: center;
                padding: 0.5rem;
            }

            .info-items {
                justify-content: center;
                width: 100%;
            }

            .features-grid,
            .modal-listings-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            }

            .categories-grid {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
                gap: 0.8rem;
            }

            .category-icon-wrapper {
                height: 60px;
            }

            .category-icon-wrapper i {
                font-size: 1.8rem;
            }

            .category-content {
                padding: 0.6rem 0.6rem 0.8rem;
            }

            .category-content h3 {
                font-size: 0.8rem;
            }

            .featured-stores-grid {
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
                gap: 0.8rem;
            }

            .store-card .store-avatar {
                width: 48px;
                height: 48px;
                font-size: 1rem;
            }

            .store-card .store-name {
                font-size: 0.8rem;
            }

            .listings-grid {
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                gap: 1rem;
            }

            .listing-img {
                height: 140px;
            }

            .toast-notification {
                min-width: auto;
                width: 90%;
                right: 5%;
                top: 70px;
            }

            .swiper-button-next,
            .swiper-button-prev {
                display: none !important;
            }
        }

        /* ============================================ */
        /* RESPONSIVE - MOBILE (Tablet Portrait) */
        /* ============================================ */
        @media (max-width: 768px) {
            .hero {
                min-height: 300px;
                height: 50vh;
                max-height: 450px;
            }

            .hero-content {
                width: 95%;
                padding: 0.5rem;
            }

            .hero-content h1 {
                font-size: clamp(1.2rem, 4.5vw, 1.8rem);
                margin-bottom: 0.75rem;
            }

            .hero-content p {
                font-size: clamp(0.8rem, 2.5vw, 1rem);
                margin-bottom: 1.25rem;
                padding: 0 0.5rem;
            }

            .hero-buttons {
                flex-direction: column;
                align-items: center;
                gap: 0.6rem;
                width: 100%;
                max-width: 280px;
                margin: 0 auto;
            }

            .hero-buttons .btn {
                width: 100%;
                padding: 0.6rem 1rem;
                font-size: 0.85rem;
                justify-content: center;
            }

            .swiper-pagination-bullet {
                width: 8px;
                height: 8px;
            }

            .newsletter-form {
                flex-direction: column;
                gap: 0.75rem;
            }

            .newsletter-form input,
            .newsletter-form button {
                border-radius: 50px !important;
                width: 100%;
            }

            .tabs {
                flex-direction: column;
                align-items: stretch;
                border-bottom: none;
                gap: 0.3rem;
            }

            .tab-btn {
                border-radius: 50px;
                background: var(--light);
                text-align: center;
                padding: 0.4rem 0.8rem;
                font-size: 0.8rem;
            }

            .tab-btn.active {
                background: var(--primary);
                color: var(--white);
            }

            .tab-btn.active::after {
                display: none;
            }

            section {
                padding: 2rem 1rem;
            }

            .footer-grid {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .footer-col h3::after {
                left: 50%;
                transform: translateX(-50%);
            }

            .social-links {
                justify-content: center;
            }

            .footer-links a {
                justify-content: center;
                width: 100%;
            }

            .categories-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 0.7rem;
            }

            .category-icon-wrapper {
                height: 55px;
            }

            .category-icon-wrapper i {
                font-size: 1.5rem;
            }

            .category-content {
                padding: 0.5rem 0.5rem 0.7rem;
            }

            .category-content h3 {
                font-size: 0.75rem;
            }

            .category-content .listing-count {
                font-size: 0.6rem;
            }

            .category-content .btn {
                font-size: 0.6rem;
                padding: 0.2rem 0.5rem;
                max-width: 85px;
            }

            .featured-stores-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 0.7rem;
            }

            .store-card {
                padding: 0.8rem 0.5rem;
            }

            .store-card .store-avatar {
                width: 45px;
                height: 45px;
                font-size: 0.9rem;
            }

            .store-card .store-name {
                font-size: 0.75rem;
            }

            .store-card .store-stats {
                font-size: 0.6rem;
            }

            .store-card .store-cta {
                font-size: 0.6rem;
            }

            .listings-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.8rem;
            }

            .listing-img {
                height: 120px;
            }

            .listing-content {
                padding: 0.6rem 0.7rem 0.8rem;
            }

            .listing-content h3 {
                font-size: 0.85rem;
            }

            .listing-price {
                font-size: 0.9rem;
            }

            .modal-listings-grid {
                grid-template-columns: 1fr;
            }

            .swiper-button-next,
            .swiper-button-prev {
                display: none !important;
            }
        }

        /* ============================================ */
        /* RESPONSIVE - SMALL MOBILE */
        /* ============================================ */
        @media (max-width: 576px) {
            .top-info-bar {
                font-size: 0.7rem;
                padding: 0.35rem 0.5rem;
            }

            .info-items {
                gap: 0.4rem;
            }

            .info-items li a {
                font-size: 0.7rem;
                padding: 0.15rem 0.3rem;
            }

            .navbar-container {
                padding: 0.4rem 0.6rem;
            }

            .logo img {
                height: 30px;
            }

            .nav-actions .btn {
                padding: 0.2rem 0.4rem;
                font-size: 0.7rem;
                min-width: 30px;
            }

            .nav-actions .btn i {
                font-size: 0.85rem;
            }

            .nav-actions .btn[title="Cart"] .badge {
                font-size: 0.45rem !important;
                min-width: 14px !important;
                padding: 1px 4px !important;
            }

            .hero {
                min-height: 200px;
                height: 40vh;
                max-height: 300px;
            }

            .hero-content h1 {
                font-size: 1rem;
                margin-bottom: 0.4rem;
            }

            .hero-content p {
                font-size: 0.7rem;
                margin-bottom: 0.75rem;
            }

            .hero-buttons .btn {
                padding: 0.4rem 0.8rem;
                font-size: 0.7rem;
            }

            .features-grid,
            .modal-listings-grid {
                grid-template-columns: 1fr;
                gap: 0.8rem;
            }

            .categories-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.5rem;
            }

            .category-icon-wrapper {
                height: 45px;
            }

            .category-icon-wrapper i {
                font-size: 1.2rem;
            }

            .category-content {
                padding: 0.3rem 0.3rem 0.5rem;
            }

            .category-content h3 {
                font-size: 0.65rem;
            }

            .category-content .listing-count {
                font-size: 0.5rem;
            }

            .category-content .description {
                font-size: 0.5rem;
                -webkit-line-clamp: 1;
            }

            .category-content .btn {
                font-size: 0.5rem;
                padding: 0.15rem 0.3rem;
                max-width: 65px;
            }

            .featured-stores-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.5rem;
            }

            .store-card {
                padding: 0.6rem 0.3rem;
            }

            .store-card .store-avatar {
                width: 35px;
                height: 35px;
                font-size: 0.7rem;
            }

            .store-card .store-name {
                font-size: 0.65rem;
            }

            .store-card .role-pill {
                font-size: 0.45rem;
                padding: 1px 5px;
            }

            .store-card .store-stats {
                font-size: 0.5rem;
            }

            .store-card .store-cta {
                font-size: 0.5rem;
            }

            .listings-grid {
                grid-template-columns: 1fr;
                gap: 0.8rem;
            }

            .listing-img {
                height: 160px;
            }

            .listing-content h3 {
                font-size: 0.95rem;
            }

            .modal-dialog {
                margin: 0.5rem;
            }

            .modal-body {
                padding: 0.75rem;
            }

            .row {
                margin: 0 -0.5rem;
            }

            .col-6,
            .col-12,
            .col-md-4,
            .col-md-6 {
                padding: 0 0.5rem;
            }

            .btn {
                padding: 0.4rem 0.8rem;
                font-size: 0.8rem;
            }

            .section-title h2 {
                font-size: 1.3rem;
            }

            .section-title p {
                font-size: 0.85rem;
            }

            .footer {
                padding: 1.5rem 1rem 1rem;
            }

            .swiper-button-next,
            .swiper-button-prev {
                display: none !important;
            }
        }

        /* ============================================ */
        /* RESPONSIVE - VERY SMALL MOBILE */
        /* ============================================ */
        @media (max-width: 400px) {
            .top-info-bar {
                padding: 0.25rem 0.3rem;
            }

            .info-items {
                gap: 0.3rem;
            }

            .info-items li a {
                font-size: 0.6rem;
                padding: 0.1rem 0.2rem;
            }

            .hero {
                min-height: 150px;
                height: 35vh;
                max-height: 250px;
            }

            .hero-content h1 {
                font-size: 0.85rem;
                margin-bottom: 0.3rem;
            }

            .hero-content p {
                font-size: 0.6rem;
                margin-bottom: 0.5rem;
            }

            .hero-buttons .btn {
                padding: 0.3rem 0.6rem;
                font-size: 0.6rem;
            }
        }

        @media (max-height: 600px) and (orientation: landscape) {
            .hero {
                min-height: 200px;
                height: 100vh;
                max-height: 400px;
            }

            .menu-items {
                height: calc(100vh - 70px);
            }

            .hero-content h1 {
                font-size: 1.3rem;
                margin-bottom: 0.5rem;
            }

            .hero-content p {
                margin-bottom: 0.75rem;
            }

            .hero-buttons {
                margin-top: 0.3rem;
            }

            .hero-buttons .btn {
                padding: 0.4rem 1rem;
                font-size: 0.8rem;
            }
        }

        .text-primary { color: var(--primary) !important; }
        .bg-primary { background: var(--primary) !important; }
        .shadow-hover { transition: var(--transition); }
        .shadow-hover:hover { box-shadow: var(--shadow-lg); }
        .cursor-pointer { cursor: pointer; }
        .z-index-high { z-index: 1050; }
        .d-lg-none { display: none !important; }

        @media (max-width: 992px) {
            .d-lg-none { display: flex !important; }
        }
    </style>

    <title>Ebonyi Market Hub - Buy, Sell & Rent Properties in Ebonyi State</title>
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

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'success'): ?>
        <div id="alertBox" class="alert alert-success alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-4 z-3" style="max-width: 90%; width: 500px;" role="alert">
            <div class="d-flex align-items-center">
                <i class="fas fa-check-circle me-2"></i>
                <span>✅ Listing posted successfully!</span>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'error'): ?>
        <div id="alertBox" class="alert alert-danger alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-4 z-3" style="max-width: 90%; width: 500px;" role="alert">
            <div class="d-flex align-items-center">
                <i class="fas fa-exclamation-circle me-2"></i>
                <span>❌ Error: <?php echo isset($_GET['error']) ? htmlspecialchars($_GET['error']) : 'Could not post listing.'; ?></span>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Top Info Bar -->
    <div class="top-info-bar">
        <ul class="info-items">
            <li><a href="mailto:info@ebonyipropertyhub.com"><i class="fas fa-envelope"></i> <span class="d-none d-sm-inline">info@ebonyipropertyhub.com</span></a></li>
            <li><a href="tel:+2347039312901"><i class="fas fa-phone"></i> <span class="d-none d-sm-inline">(+234) 703-931-2901</span></a></li>
        </ul>
        <ul class="info-items">
            <?php if ($isLoggedIn): ?>
                <li><a href="dashboard.php" class="logged-in"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($_SESSION['username']); ?></a></li>
                <?php if (isset($_SESSION['is_vendor']) && $_SESSION['is_vendor'] == 1): ?>
                    <li><a href="stores.php?user_id=<?php echo $_SESSION['user_id']; ?>" target="_blank"><i class="fas fa-store"></i> My Store</a></li>
                <?php endif; ?>
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            <?php else: ?>
                <li><a href="#" class="open-login-modal"><i class="fas fa-sign-in-alt"></i> Login</a></li>
                <li><a href="#" id="openSignup"><i class="fas fa-user-plus"></i> Register</a></li>
            <?php endif; ?>
            <li><a href="#" data-bs-toggle="modal" data-bs-target="#postAdModal" onclick="return <?php echo $isLoggedIn ? 'true' : 'showLoginRequired(\'post\', \'ad\')'; ?>"><i class="fas fa-plus-circle"></i> Post Ad</a></li>
        </ul>
    </div>

    <!-- Navigation -->
    <nav class="navbar">
        <div class="navbar-container">
            <div class="logo">
                <a href="index.php">
                    <img src="img/log.png" alt="Ebonyi Market Hub" loading="lazy">
                </a>
            </div>
            
            <div class="hamburger-lines" id="hamburger-menu">
                <span class="line line1"></span>
                <span class="line line2"></span>
                <span class="line line3"></span>
            </div>
            
            <ul class="menu-items" id="menu-items">
                <li><a href="index.php" style="color: var(--primary);"><i class="fas fa-home d-none d-lg-inline me-1"></i> Home</a></li>
                
                <li class="dropdown">
                    <a href="#" class="dropdown-btn"><i class="fas fa-th-large me-1"></i> Categories <i class="fas fa-chevron-down ms-1"></i></a>
                    <ul class="dropdown-menu">
                        <?php if (!empty($categories)): ?>
                            <?php foreach (array_slice($categories, 0, 6) as $category): ?>
                                <li><a href="javascript:void(0)" onclick="openCategoryModal(<?php echo $category['id']; ?>, '<?php echo htmlspecialchars($category['name']); ?>')">
                                    <?php echo htmlspecialchars($category['name']); ?>
                                    <?php if (isset($category_counts[$category['name']]) && $category_counts[$category['name']] > 0): ?>
                                        <span class="badge bg-primary float-end"><?php echo $category_counts[$category['name']]; ?></span>
                                    <?php endif; ?>
                                </a></li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li><a href="category.php?name=Real Estate">Real Estate</a></li>
                            <li><a href="category.php?name=Vehicles">Vehicles</a></li>
                            <li><a href="category.php?name=Electronics">Electronics</a></li>
                            <li><a href="category.php?name=Furniture">Furniture</a></li>
                            <li><a href="category.php?name=Services">Services</a></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a href="all-categories.php">View All <i class="fas fa-arrow-right ms-2"></i></a></li>
                    </ul>
                </li>
                
                <li><a href="stores.php"><i class="fas fa-store me-1"></i> Stores</a></li>
                
                <li class="dropdown">
                    <a href="#" class="dropdown-btn"><i class="fas fa-building me-1"></i> Listings <i class="fas fa-chevron-down ms-1"></i></a>
                    <ul class="dropdown-menu">
                        <li><a href="rentals.php"><i class="fas fa-building me-2"></i>Rentals</a></li>
                        <li><a href="lands.php"><i class="fas fa-tree me-2"></i>Lands</a></li>
                        <li><a href="used-items.php"><i class="fas fa-box-open me-2"></i>Used Items</a></li>
                    </ul>
                </li>
                
                <li><a href="about.php"><i class="fas fa-info-circle me-1"></i> About</a></li>
                <li><a href="contact.php"><i class="fas fa-envelope me-1"></i> Contact</a></li>
                
                <?php if ($isLoggedIn): ?>
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt me-1"></i> Dashboard</a></li>
                <?php endif; ?>
                
                <?php if ($isLoggedIn): ?>
                    <?php if (isset($_SESSION['is_vendor']) && $_SESSION['is_vendor'] == 1): ?>
                        <li class="d-lg-none"><a href="stores.php?user_id=<?php echo $_SESSION['user_id']; ?>" target="_blank"><i class="fas fa-store me-1"></i> My Store</a></li>
                    <?php endif; ?>
                    <li class="d-lg-none"><a href="logout.php"><i class="fas fa-sign-out-alt me-1"></i> Logout</a></li>
                <?php else: ?>
                    <li class="d-lg-none"><a href="#" class="open-login-modal"><i class="fas fa-sign-in-alt me-1"></i> Login</a></li>
                    <li class="d-lg-none"><a href="#" id="mobileSignup"><i class="fas fa-user-plus me-1"></i> Register</a></li>
                <?php endif; ?>
                <li class="d-lg-none"><a href="#" data-bs-toggle="modal" data-bs-target="#postAdModal" onclick="return <?php echo $isLoggedIn ? 'true' : 'showLoginRequired(\'post\', \'ad\')'; ?>"><i class="fas fa-plus-circle me-1"></i> Post an Ad</a></li>
            </ul>
            
            <div class="nav-actions">
                <form action="search.php" method="GET" class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" name="q" placeholder="Search..." aria-label="Search">
                </form>
                
                <!-- Mobile search toggle -->
                <button class="btn btn-outline btn-sm d-lg-none" id="mobileSearchToggle" onclick="toggleMobileSearch()" aria-label="Search">
                    <i class="fas fa-search"></i>
                </button>
                
                <!-- ====== CART LINK WITH BADGE - ALWAYS VISIBLE ====== -->
                <a href="cart_items.php" class="btn btn-outline btn-sm position-relative" title="Cart">
                    <i class="fas fa-shopping-cart"></i>
                    <?php if ($isLoggedIn && $cartCount > 0): ?>
                        <span class="badge bg-danger position-absolute top-0 start-100 translate-middle rounded-pill" style="font-size: 0.6rem; min-width: 18px;">
                            <?php echo $cartCount; ?>
                        </span>
                    <?php endif; ?>
                </a>

                <?php if (!$isLoggedIn): ?>
                    <a href="#" class="btn btn-outline btn-sm open-login-modal">
                        <i class="fas fa-sign-in-alt"></i>
                        <span class="d-none d-xl-inline">Login</span>
                    </a>
                    <button class="btn btn-primary btn-sm" id="openSignupNav">
                        <i class="fas fa-user-plus"></i>
                        <span class="d-none d-xl-inline">Sign Up</span>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Menu Overlay for mobile -->
    <div class="menu-overlay" id="menuOverlay"></div>

    <!-- ============================================ -->
    <!-- LOGIN MODAL -->
    <!-- ============================================ -->
    <div class="modal fade" id="loginModal" tabindex="-1" aria-labelledby="loginModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="loginModalLabel">
                        <i class="fas fa-sign-in-alt me-2 text-primary"></i>Login Required
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <div class="text-center mb-3">
                            <p class="text-muted" id="loginModalMessage">Please login to continue</p>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email Address</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                <input type="email" class="form-control" name="email" placeholder="Enter your email" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                <input type="password" class="form-control" name="password" placeholder="Enter your password" required>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="rememberMe">
                                <label class="form-check-label" for="rememberMe">Remember me</label>
                            </div>
                            <a href="forgot-password.php" class="text-primary">Forgot password?</a>
                        </div>
                        <input type="hidden" name="redirect_url" id="loginRedirectUrl" value="">
                        <div class="d-grid">
                            <button type="submit" name="login" class="btn btn-primary btn-lg">
                                <i class="fas fa-sign-in-alt me-2"></i>Login
                            </button>
                        </div>
                        <div class="text-center mt-3">
                            <p class="mb-0">Don't have an account? 
                                <a href="#" onclick="switchToSignup()" class="text-primary fw-bold">Sign up here</a>
                            </p>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Hero Section with Slider -->
    <section class="hero">
        <div class="swiper hero-slider">
            <div class="swiper-wrapper">
                <div class="swiper-slide">
                    <img src="img/10.jpg" alt="Ebonyi Market Hub - Real Estate" loading="lazy">
                    <div class="hero-content">
                        <h1>Buy, Sell & Rent Properties in Ebonyi</h1>
                        <p>Your trusted marketplace for real estate, lands, apartments, and used items in Ebonyi State</p>
                        <div class="hero-buttons">
                            <a href="properties.php" class="btn btn-primary">
                                <i class="fas fa-search me-2"></i>Browse Properties
                            </a>
                            <a href="stores.php" class="btn btn-outline">
                                <i class="fas fa-store me-2"></i>Browse Stores
                            </a>
                            <button class="btn btn-outline" onclick="checkLoginBeforePost('property')">
                                <i class="fas fa-plus-circle me-2"></i>Post Property
                            </button>
                        </div>
                    </div>
                </div>
                <div class="swiper-slide">
                    <img src="https://images.unsplash.com/photo-1560518883-ce09059eeffa?ixlib=rb-4.0.3&auto=format&fit=crop&w=1000&q=80" alt="Dream Properties" loading="lazy">
                    <div class="hero-content">
                        <h1>Find Your Dream Home Today</h1>
                        <p>Discover hundreds of verified properties, lands, and apartments across Ebonyi State</p>
                        <div class="hero-buttons">
                            <a href="properties.php" class="btn btn-primary">
                                <i class="fas fa-home me-2"></i>View Properties
                            </a>
                            <button class="btn btn-outline" onclick="checkLoginBeforePost('apartment')">
                                <i class="fas fa-key me-2"></i>Rent Out
                            </button>
                        </div>
                    </div>
                </div>
                <div class="swiper-slide">
                    <img src="https://images.unsplash.com/photo-1556228453-efd6c1ff04f6?ixlib=rb-4.0.3&auto=format&fit=crop&w=1000&q=80" alt="Used Items Marketplace" loading="lazy">
                    <div class="hero-content">
                        <h1>Buy & Sell Used Items</h1>
                        <p>Great deals on electronics, furniture, vehicles, and more - all verified and quality checked</p>
                        <div class="hero-buttons">
                            <a href="used_items.php" class="btn btn-primary">
                                <i class="fas fa-shopping-bag me-2"></i>Browse Items
                            </a>
                            <button class="btn btn-outline" onclick="checkLoginBeforePost('item')">
                                <i class="fas fa-tag me-2"></i>Sell Item
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="swiper-pagination"></div>
            <div class="swiper-button-next"></div>
            <div class="swiper-button-prev"></div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features">
        <div class="section-title">
            <h2>Why Choose Ebonyi Market Hub?</h2>
            <p>We make property and item transactions seamless, secure, and stress-free</p>
        </div>
        
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-home"></i>
                </div>
                <h3>Real Estate Solutions</h3>
                <p>Find your dream property, land, or apartment with verified listings, virtual tours, and expert guidance.</p>
            </div>
            
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-key"></i>
                </div>
                <h3>Rental Services</h3>
                <p>Discover apartments for rent or list your property for tenants with our secure, user-friendly platform.</p>
            </div>
            
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-store"></i>
                </div>
                <h3>Seller Storefronts</h3>
                <p>Every registered user gets their own storefront to showcase products, build brand, and grow their business.</p>
            </div>
            
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-shopping-cart"></i>
                </div>
                <h3>Used Items Marketplace</h3>
                <p>Buy and sell quality used items including electronics, furniture, vehicles, and more from trusted sellers.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <h3>Verified Vendors</h3>
                <p>Every vendor goes through a verification check before earning a "Verified" badge, so you always know exactly who you're dealing with before you buy.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-handshake"></i>
                </div>
                <h3>Seamless Transactions</h3>
                <p>From browsing to checkout, our streamlined process makes buying, selling, and renting fast and hassle-free — no unnecessary steps, no confusion.</p>
            </div>
        </div>
    </section>

    <!-- Featured Vendors & Landlords Section -->
    <?php if (!empty($featured_stores)): ?>
    <section class="featured-stores">
        <div class="section-title">
            <h2>Featured Vendors &amp; Landlords</h2>
            <p>Browse trusted sellers and property owners — click through to see everything they've listed and place an order or inquiry directly</p>
        </div>
        
        <div class="featured-stores-grid">
            <?php foreach ($featured_stores as $store): ?>
                <a href="stores.php?user_id=<?php echo $store['id']; ?>" class="store-card">
                    <span class="role-pill <?php echo $store['role'] === 'landlord' ? 'landlord' : 'vendor'; ?>">
                        <i class="fas <?php echo $store['role'] === 'landlord' ? 'fa-building' : 'fa-store'; ?>"></i>
                        <?php echo $store['role'] === 'landlord' ? 'Landlord' : 'Vendor'; ?>
                    </span>
                    <div class="store-avatar">
                        <?php if (!empty($store['avatar_url'])): ?>
                            <img src="<?php echo htmlspecialchars($store['avatar_url']); ?>" alt="<?php echo htmlspecialchars($store['store_name'] ?? $store['username']); ?>">
                        <?php else: ?>
                            <?php echo strtoupper(substr($store['store_name'] ?? $store['username'], 0, 2)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="store-name">
                        <?php echo htmlspecialchars($store['store_name'] ?? $store['username']); ?>
                        <?php if ($store['store_verified'] == 1): ?>
                            <span class="store-badge store-verified"><i class="fas fa-check"></i> Verified</span>
                        <?php else: ?>
                            <span class="store-badge store-unverified"><i class="fas fa-clock"></i> Not Verified</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($store['store_description'])): ?>
                        <div class="store-description" style="font-size: 0.8rem; color: var(--gray); margin: 0.25rem 0 0.5rem; max-width: 100%; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;">
                            <?php echo htmlspecialchars($store['store_description']); ?>
                        </div>
                    <?php endif; ?>
                    <div class="store-stats">
                        <?php echo $store['listing_count']; ?> listings • <?php echo $store['review_count']; ?> reviews
                    </div>
                    <?php if ($store['store_rating'] > 0): ?>
                        <div class="store-rating">
                            <?php 
                            $rating = round($store['store_rating']);
                            for ($i = 1; $i <= 5; $i++) {
                                echo $i <= $rating ? '<i class="fas fa-star"></i>' : '<i class="far fa-star"></i>';
                            }
                            ?>
                        </div>
                    <?php endif; ?>
                    <span class="store-cta">View listings <i class="fas fa-arrow-right"></i></span>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="text-center mt-4">
            <a href="stores.php" class="btn btn-primary">
                View All Vendors &amp; Landlords <i class="fas fa-arrow-right ms-2"></i>
            </a>
        </div>
    </section>
    <?php endif; ?>

   
<!-- Categories Section -->
<section class="categories">
    <div class="section-title">
        <h2>Browse All Categories</h2>
        <p>Explore our complete range of categories with active listings</p>
    </div>
    
    <div class="categories-grid">
        <?php if (!empty($categories)): ?>
            <?php foreach ($categories as $category): ?>
                <?php 
                $count = $category_counts[$category['name']] ?? 0;
                // Get icon from database or use fallback
                $icon = !empty($category['icon']) ? $category['icon'] : 'fa-tag';
                ?>
                <div class="category-card" onclick="openCategoryModal(<?php echo $category['id']; ?>, '<?php echo htmlspecialchars($category['name']); ?>')">
                    <div class="category-icon-wrapper">
                        <i class="fas <?php echo htmlspecialchars($icon); ?>"></i>
                    </div>
                    <div class="category-content">
                        <h3><?php echo htmlspecialchars($category['name']); ?></h3>
                        <div class="listing-count">
                            <i class="fas fa-tag me-1"></i> 
                            <?php echo $count; ?> listing<?php echo $count != 1 ? 's' : ''; ?>
                        </div>
                        <?php if (!empty($category['description'])): ?>
                            <div class="description"><?php echo htmlspecialchars(substr($category['description'], 0, 50)) . (strlen($category['description']) > 50 ? '...' : ''); ?></div>
                        <?php endif; ?>
                        <button class="btn btn-outline btn-sm" onclick="event.stopPropagation(); openCategoryModal(<?php echo $category['id']; ?>, '<?php echo htmlspecialchars($category['name']); ?>')">
                            Browse <i class="fas fa-arrow-right ms-1"></i>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <?php
            $fallback_categories = [
                ['name' => 'Real Estate', 'desc' => 'Houses & commercial properties', 'icon' => 'fa-building'],
                ['name' => 'Lands', 'desc' => 'Plots & farmlands', 'icon' => 'fa-tree'],
                ['name' => 'Apartments', 'desc' => 'Studio to 4-bedroom', 'icon' => 'fa-home'],
                ['name' => 'Vehicles', 'desc' => 'Cars & motorcycles', 'icon' => 'fa-car'],
                ['name' => 'Electronics', 'desc' => 'Phones & gadgets', 'icon' => 'fa-laptop'],
                ['name' => 'Furniture', 'desc' => 'Home & office', 'icon' => 'fa-couch'],
            ];
            
            foreach ($fallback_categories as $category): ?>
            <div class="category-card" onclick="openCategoryModal(0, '<?php echo htmlspecialchars($category['name']); ?>')">
                <div class="category-icon-wrapper">
                    <i class="fas <?php echo $category['icon']; ?>"></i>
                </div>
                <div class="category-content">
                    <h3><?php echo $category['name']; ?></h3>
                    <div class="listing-count"><?php echo $category['desc']; ?></div>
                    <button class="btn btn-outline btn-sm" onclick="event.stopPropagation(); openCategoryModal(0, '<?php echo htmlspecialchars($category['name']); ?>')">
                        Browse <i class="fas fa-arrow-right ms-1"></i>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <div class="text-center mt-4">
        <a href="all-categories.php" class="btn btn-primary">
            View All Categories <i class="fas fa-arrow-right ms-2"></i>
        </a>
    </div>
</section>

    <!-- Featured Listings Section - DYNAMIC CATEGORY TABS -->
    <section class="listings">
        <div class="section-title">
            <h2>Featured Listings</h2>
            <p>Discover our latest and most popular listings across all categories</p>
        </div>
        
        <?php if (!empty($featured_listings_by_category)): ?>
            <div class="tabs">
                <?php $is_first = true; foreach ($featured_listings_by_category as $cat_name => $listings): ?>
                    <button class="tab-btn <?php echo $is_first ? 'active' : ''; ?>" data-tab="<?php echo md5($cat_name); ?>">
                        <?php echo htmlspecialchars(ucfirst($cat_name)); ?>
                        <span class="badge"><?php echo count($listings); ?></span>
                    </button>
                    <?php $is_first = false; ?>
                <?php endforeach; ?>
            </div>
            
            <?php $is_first = true; foreach ($featured_listings_by_category as $cat_name => $listings): ?>
                <div id="<?php echo md5($cat_name); ?>" class="tab-content <?php echo $is_first ? 'active' : ''; ?>">
                    <div class="listings-grid">
                        <?php foreach ($listings as $listing): 
                            // ============================================
                            // CHECK IF USER IS THE OWNER OF THIS LISTING
                            // ============================================
                            $isOwner = false;
                            if ($isLoggedIn && isset($_SESSION['user_id']) && isset($listing['user_id'])) {
                                $isOwner = ($_SESSION['user_id'] == $listing['user_id']);
                            }
                        ?>
                            <div class="listing-card">
                                <div class="listing-img">
                                    <?php if (!empty($listing['image'])): ?>
                                        <img src="<?php echo htmlspecialchars($listing['image']); ?>" alt="<?php echo htmlspecialchars($listing['title']); ?>" loading="lazy">
                                    <?php else: ?>
                                        <img src="https://images.unsplash.com/photo-1560518883-ce09059eeffa?ixlib=rb-4.0.3&auto=format&fit=crop&w=1000&q=80" alt="<?php echo htmlspecialchars($listing['title']); ?>" loading="lazy">
                                    <?php endif; ?>
                                    <span class="listing-badge">
                                        <?php echo htmlspecialchars(ucfirst($listing['category'])); ?>
                                    </span>
                                </div>
                                <div class="listing-content">
                                    <h3><?php echo htmlspecialchars(substr($listing['title'], 0, 50)) . (strlen($listing['title']) > 50 ? '...' : ''); ?></h3>
                                    <div class="listing-meta">
                                        <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($listing['location']); ?></span>
                                        <?php if (!empty($listing['bedrooms'])): ?>
                                            <span><i class="fas fa-bed"></i> <?php echo $listing['bedrooms']; ?> beds</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($listing['price'])): ?>
                                        <div class="listing-price">₦<?php echo number_format($listing['price']); ?></div>
                                    <?php endif; ?>
                                    
                                    <!-- Enhanced Seller Info -->
                                    <?php if (!empty($listing['username'])): ?>
                                        <div class="listing-seller">
                                            <?php if (!empty($listing['avatar_url'])): ?>
                                                <img src="<?php echo htmlspecialchars($listing['avatar_url']); ?>" alt="<?php echo htmlspecialchars($listing['username']); ?>" class="seller-avatar-img">
                                            <?php else: ?>
                                                <div class="seller-avatar-placeholder">
                                                    <?php echo strtoupper(substr($listing['username'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="seller-info">
                                                <a href="stores.php?user_id=<?php echo $listing['user_id']; ?>" class="seller-name">
                                                    <?php echo htmlspecialchars($listing['store_name'] ?? $listing['username']); ?>
                                                    <?php if ($listing['store_verified'] == 1): ?>
                                                        <span class="store-badge store-verified"><i class="fas fa-check"></i> Verified</span>
                                                    <?php else: ?>
                                                        <span class="store-badge store-unverified"><i class="fas fa-clock"></i> Not Verified</span>
                                                    <?php endif; ?>
                                                </a>
                                                <?php if (!empty($listing['store_description'])): ?>
                                                    <p class="seller-description"><?php echo htmlspecialchars($listing['store_description']); ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- ====== ADD TO CART BUTTON - WITH OWNER CHECK ====== -->
                                    <div class="d-flex gap-2 mt-auto">
                                        <?php if ($isOwner): ?>
                                            <!-- Owner cannot add to cart -->
                                            <button class="btn btn-outline-secondary btn-sm add-to-cart-btn flex-grow-1" disabled style="opacity:0.6; cursor:not-allowed;">
                                                <i class="fas fa-user me-1"></i> Your Item
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-outline btn-sm add-to-cart-btn flex-grow-1" 
                                                    onclick="handleAddToCart(<?php echo $listing['id']; ?>, '<?php echo $listing['category']; ?>')">
                                                <i class="fas fa-shopping-cart me-1"></i> Add
                                            </button>
                                        <?php endif; ?>
                                        <button class="btn btn-outline btn-sm" onclick="checkLoginBeforeViewListing(<?php echo $listing['id']; ?>, '<?php echo $listing['category']; ?>')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($listings) >= 6): ?>
                        <div class="text-center mt-4">
                            <?php 
                            // Find category ID for the "View All" link
                            $cat_id = '';
                            foreach ($categories as $cat) {
                                if (strtolower($cat['name']) === strtolower($cat_name)) {
                                    $cat_id = $cat['id'];
                                    break;
                                }
                            }
                            ?>
                            <button class="btn btn-primary" onclick="openCategoryModal(<?php echo $cat_id; ?>, '<?php echo htmlspecialchars($cat_name); ?>')">
                                View All <?php echo htmlspecialchars(ucfirst($cat_name)); ?> <i class="fas fa-arrow-right ms-2"></i>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
                <?php $is_first = false; ?>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="text-center py-5">
                <i class="fas fa-box-open fa-4x text-muted mb-3"></i>
                <h3>No listings available</h3>
                <p class="text-muted">Be the first to post a listing!</p>
                <button class="btn btn-primary mt-3" onclick="checkLoginBeforePost('property')">
                    <i class="fas fa-plus-circle me-2"></i>Post a Listing
                </button>
            </div>
        <?php endif; ?>
    </section>

    <!-- Testimonials Section -->
    <section class="testimonials">
        <div class="section-title">
            <h2>What Our Users Say</h2>
            <p>Trusted by thousands of buyers, sellers, landlords, and tenants</p>
        </div>
        
        <div class="swiper testimonials-slider">
            <div class="swiper-wrapper">
                <?php
                $testimonials_query = "SELECT * FROM testimonials WHERE status = 'approved' ORDER BY created_at DESC LIMIT 3";
                $testimonials_result = $conn->query($testimonials_query);
                
                if ($testimonials_result && $testimonials_result->num_rows > 0):
                    while ($testimonial = $testimonials_result->fetch_assoc()):
                ?>
                <div class="swiper-slide">
                    <div class="testimonial-card">
                        <p class="testimonial-text">"<?php echo htmlspecialchars($testimonial['content']); ?>"</p>
                        <div class="testimonial-author">
                            <div class="author-avatar">
                                <?php if (!empty($testimonial['avatar_url'])): ?>
                                    <img src="<?php echo htmlspecialchars($testimonial['avatar_url']); ?>" alt="<?php echo htmlspecialchars($testimonial['author']); ?>" loading="lazy">
                                <?php else: ?>
                                    <img src="https://randomuser.me/api/portraits/men/32.jpg" alt="<?php echo htmlspecialchars($testimonial['author']); ?>" loading="lazy">
                                <?php endif; ?>
                            </div>
                            <div class="author-info">
                                <h4><?php echo htmlspecialchars($testimonial['author']); ?></h4>
                                <p><?php echo htmlspecialchars($testimonial['position'] ?? 'Verified User'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
                <?php else: ?>
                <div class="swiper-slide">
                    <div class="testimonial-card">
                        <p class="testimonial-text">"Ebonyi Market Hub helped me find my dream home in just two weeks. The process was smooth and the agents were very professional."</p>
                        <div class="testimonial-author">
                            <div class="author-avatar">
                                <img src="https://randomuser.me/api/portraits/men/32.jpg" alt="John Doe" loading="lazy">
                            </div>
                            <div class="author-info">
                                <h4>John Doe</h4>
                                <p>Property Buyer</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="swiper-slide">
                    <div class="testimonial-card">
                        <p class="testimonial-text">"I rented out my apartment within days using this platform. It's easy to use and I found reliable tenants quickly."</p>
                        <div class="testimonial-author">
                            <div class="author-avatar">
                                <img src="https://randomuser.me/api/portraits/women/44.jpg" alt="Jane Smith" loading="lazy">
                            </div>
                            <div class="author-info">
                                <h4>Jane Smith</h4>
                                <p>Landlord</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="swiper-slide">
                    <div class="testimonial-card">
                        <p class="testimonial-text">"I sold my car and furniture within a week on Ebonyi Market Hub. The platform is user-friendly and I got multiple serious offers."</p>
                        <div class="testimonial-author">
                            <div class="author-avatar">
                                <img src="https://randomuser.me/api/portraits/men/62.jpg" alt="Michael Johnson" loading="lazy">
                            </div>
                            <div class="author-info">
                                <h4>Michael Johnson</h4>
                                <p>Seller</p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="swiper-pagination"></div>
        </div>
    </section>

    <!-- Newsletter Section -->
    <section class="newsletter">
        <div class="container">
            <h2>Stay Updated</h2>
            <p>Subscribe to receive new property listings, rental opportunities, and marketplace deals</p>
            <form class="newsletter-form" method="POST" action="subscribe.php">
                <input type="email" name="email" placeholder="Enter your email address" required>
                <button type="submit">
                    <i class="fas fa-paper-plane me-2"></i>Subscribe
                </button>
            </form>
        </div>
    </section>

    <!-- Category Listings Modal -->
    <div class="modal fade" id="categoryModal" tabindex="-1" aria-labelledby="categoryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="categoryModalLabel">
                        <i class="fas fa-folder-open text-primary me-2"></i>
                        <span id="categoryModalTitle">Category Listings</span>
                        <span class="category-count ms-2" id="categoryModalCount"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="categoryModalBody">
                    <div class="modal-loading">
                        <div class="spinner-border text-primary spinner" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2 text-muted">Loading listings...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Registration Modal -->
    <div class="modal fade" id="signupModal" tabindex="-1" aria-labelledby="signupModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="signupModalLabel">
                        <i class="fas fa-user-plus me-2 text-success"></i>Create Account & Store
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Username <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                                    <input type="text" class="form-control" name="username" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                    <input type="email" class="form-control" name="email" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    <input type="password" class="form-control" name="password" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Account Type</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-tag"></i></span>
                                    <select class="form-select" name="role" id="signupRole">
                                        <option value="buyer">Buyer</option>
                                        <option value="vendor">Vendor</option>
                                        <option value="landlord">Landlord</option>
                                        <option value="tenant">Tenant</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">First Name</label>
                                <input type="text" class="form-control" name="first_name" placeholder="Your First Name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Last Name</label>
                                <input type="text" class="form-control" name="last_name" placeholder="Your Last Name" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Phone</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-phone"></i></span>
                                    <input type="text" class="form-control" name="phone" placeholder="Input Phone Number" required>
                                </div>
                            </div>
                            
                            <div class="col-12 mt-2" id="isVendorWrap">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_vendor" value="1" id="isVendor" checked>
                                    <label class="form-check-label" for="isVendor">
                                        <i class="fas fa-store text-primary me-1"></i> Create a Storefront
                                        <small class="d-block text-muted">Get your own store page to showcase your products</small>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="col-12" id="storeNameField">
                                <label class="form-label">Store Name</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-store"></i></span>
                                    <input type="text" class="form-control" name="store_name" placeholder="Your Store Name" value="">
                                </div>
                                <small class="text-muted">Leave blank to use your username</small>
                            </div>
                        </div>
                        <div class="d-grid mt-4">
                            <button type="submit" name="signup" class="btn btn-success btn-lg">
                                <i class="fas fa-user-plus me-2"></i>Create Account & Store
                            </button>
                        </div>
                        <div class="text-center mt-3">
                            <p class="mb-0">Already have an account? 
                                <a href="#" onclick="switchToLogin()" class="text-primary fw-bold">Login here</a>
                            </p>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Post Ad Modal -->
    <div class="modal fade" id="postAdModal" tabindex="-1" aria-labelledby="postAdModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="postAdModalLabel">
                        <i class="fas fa-plus-circle me-2 text-success"></i>Post a New Listing
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="post_listing.php" id="adPostForm" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Category <span class="text-danger">*</span></label>
                                <select class="form-select" name="category" id="adCategory" required onchange="updateFormFields()">
                                    <option value="">Select Category</option>
                                    <?php if (!empty($categories)): ?>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?php echo htmlspecialchars($category['name']); ?>">
                                                <?php echo htmlspecialchars($category['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <option value="property">Property</option>
                                        <option value="land">Land</option>
                                        <option value="apartment">Apartment for Rent</option>
                                        <option value="item">Used Item</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="title" required placeholder="E.g., 3-Bedroom House, iPhone 12, Land for Sale">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description <span class="text-danger">*</span></label>
                                <textarea class="form-control" name="description" rows="3" required placeholder="Provide detailed description..."></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Price (₦) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="price" required step="0.01" placeholder="0.00">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Location <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="location" required placeholder="City, State">
                            </div>
                            
                            <div id="propertyFields" style="display: none;">
                                <div class="row">
                                    <div class="col-md-4">
                                        <label class="form-label">Bedrooms</label>
                                        <input type="number" class="form-control" name="bedrooms" placeholder="e.g., 3">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Bathrooms</label>
                                        <input type="number" class="form-control" name="bathrooms" placeholder="e.g., 2">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Area (sq.m)</label>
                                        <input type="number" step="0.01" class="form-control" name="area" placeholder="e.g., 150">
                                    </div>
                                </div>
                            </div>
                            
                            <div id="itemFields" style="display: none;">
                                <div class="row">
                                    <div class="col-md-6">
                                        <label class="form-label">Item Condition</label>
                                        <select class="form-select" name="condition">
                                            <option value="new">New</option>
                                            <option value="used" selected>Used - Good</option>
                                            <option value="fair">Used - Fair</option>
                                            <option value="refurbished">Refurbished</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Brand/Make</label>
                                        <input type="text" class="form-control" name="brand" placeholder="e.g., Toyota, Samsung">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">Upload Images</label>
                                <input type="file" class="form-control" name="images[]" multiple accept="image/*">
                                <small class="text-muted">You can upload multiple images (Max 5)</small>
                            </div>
                            
                            <div class="col-12">
                                <h6 class="mt-2 mb-3">Contact Information</h6>
                                <div class="row">
                                    <div class="col-md-4 mb-2">
                                        <input type="text" name="contact_name" id="contact_name" class="form-control" placeholder="Contact Name *" required 
                                               value="<?php echo $isLoggedIn ? htmlspecialchars(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) : ''; ?>">
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <input type="text" name="contact_phone" id="contact_phone" class="form-control" placeholder="Phone *" required 
                                               value="<?php echo $isLoggedIn ? htmlspecialchars($_SESSION['phone'] ?? '') : ''; ?>">
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <input type="email" name="contact_email" id="contact_email" class="form-control" placeholder="Email (Optional)" 
                                               value="<?php echo $isLoggedIn ? htmlspecialchars($_SESSION['email'] ?? '') : ''; ?>">
                                    </div>
                                </div>
                            </div>
                            <?php if ($isLoggedIn): ?>
                                <input type="hidden" name="user_id" value="<?php echo $_SESSION['user_id']; ?>">
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-2"></i>Cancel
                        </button>
                        <button type="submit" name="post_ad" class="btn btn-success">
                            <i class="fas fa-check-circle me-2"></i>Post Listing
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-grid">
            <div class="footer-col">
                <h3>Ebonyi Market Hub</h3>
                <p style="color: rgba(255,255,255,0.7);">Your trusted marketplace for buying, selling, and renting properties, lands, apartments, and used items in Ebonyi State.</p>
                <div class="social-links">
                    <a href="#" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                    <a href="#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                    <a href="#" aria-label="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
                    <a href="#" aria-label="YouTube"><i class="fab fa-youtube"></i></a>
                </div>
            </div>
            
            <div class="footer-col">
                <h3>Quick Links</h3>
                <ul class="footer-links">
                    <li><a href="index.php"><i class="fas fa-chevron-right me-2"></i>Home</a></li>
                    <li><a href="stores.php"><i class="fas fa-chevron-right me-2"></i>Stores</a></li>
                    <li><a href="rentals.php"><i class="fas fa-chevron-right me-2"></i>Rentals</a></li>
                    <li><a href="lands.php"><i class="fas fa-chevron-right me-2"></i>Lands</a></li>
                    <li><a href="used-items.php"><i class="fas fa-chevron-right me-2"></i>Used Items</a></li>
                    <li><a href="about.php"><i class="fas fa-chevron-right me-2"></i>About Us</a></li>
                    <li><a href="contact.php"><i class="fas fa-chevron-right me-2"></i>Contact</a></li>
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</a></li>
                </ul>
            </div>
            
            <div class="footer-col">
                <h3>Categories</h3>
                <ul class="footer-links">
                    <?php if (!empty($categories)): ?>
                        <?php foreach (array_slice($categories, 0, 5) as $category): ?>
                            <li><a href="javascript:void(0)" onclick="openCategoryModal(<?php echo $category['id']; ?>, '<?php echo htmlspecialchars($category['name']); ?>')"><i class="fas fa-chevron-right me-2"></i><?php echo htmlspecialchars($category['name']); ?></a></li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li><a href="category.php?name=Real Estate"><i class="fas fa-chevron-right me-2"></i>Real Estate</a></li>
                        <li><a href="category.php?name=Lands"><i class="fas fa-chevron-right me-2"></i>Lands</a></li>
                        <li><a href="category.php?name=Apartments"><i class="fas fa-chevron-right me-2"></i>Apartments</a></li>
                        <li><a href="category.php?name=Vehicles"><i class="fas fa-chevron-right me-2"></i>Vehicles</a></li>
                        <li><a href="category.php?name=Electronics"><i class="fas fa-chevron-right me-2"></i>Electronics</a></li>
                    <?php endif; ?>
                    <li><a href="all-categories.php"><i class="fas fa-chevron-right me-2"></i>View All</a></li>
                </ul>
            </div>
            
            <div class="footer-col">
                <h3>Contact Info</h3>
                <ul class="footer-links">
                    <li><i class="fas fa-map-marker-alt me-2"></i> Abakaliki, Ebonyi State, Nigeria</li>
                    <li><i class="fas fa-phone me-2"></i> (+234) 703 931 2901</li>
                    <li><i class="fas fa-envelope me-2"></i> info@ebonyimarkethub.com</li>
                    <li><i class="fas fa-clock me-2"></i> Mon - Fri: 8am - 6pm</li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; <?php echo date('Y'); ?> Ebonyi Market Hub. All rights reserved. | Designed with <i class="fas fa-heart text-danger"></i> in Ebonyi</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        const isLoggedIn = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;
        
        document.addEventListener('DOMContentLoaded', function() {
            const heroSwiper = new Swiper('.hero-slider', {
                loop: true,
                autoplay: { delay: 5000, disableOnInteraction: false },
                pagination: { el: '.swiper-pagination', clickable: true },
                navigation: { nextEl: '.swiper-button-next', prevEl: '.swiper-button-prev' },
                effect: 'fade',
                fadeEffect: { crossFade: true },
                speed: 1000,
            });
            
            const testimonialSwiper = new Swiper('.testimonials-slider', {
                loop: true,
                autoplay: { delay: 4000, disableOnInteraction: false },
                pagination: { el: '.swiper-pagination', clickable: true },
                breakpoints: {
                    320: { slidesPerView: 1, spaceBetween: 20 },
                    768: { slidesPerView: 2, spaceBetween: 30 },
                    1024: { slidesPerView: 3, spaceBetween: 30 },
                },
                speed: 800,
            });
            
            // Mobile menu toggle with overlay
            const hamburger = document.querySelector('.hamburger-lines');
            const menuItems = document.querySelector('.menu-items');
            const menuOverlay = document.getElementById('menuOverlay');
            
            if (hamburger && menuItems) {
                function toggleMenu() {
                    hamburger.classList.toggle('active');
                    menuItems.classList.toggle('active');
                    if (menuOverlay) {
                        menuOverlay.classList.toggle('active');
                    }
                    document.body.style.overflow = menuItems.classList.contains('active') ? 'hidden' : '';
                }
                
                hamburger.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleMenu();
                });
                
                // Close menu when clicking overlay
                if (menuOverlay) {
                    menuOverlay.addEventListener('click', function() {
                        if (menuItems.classList.contains('active')) {
                            toggleMenu();
                        }
                    });
                }
                
                // Close menu when clicking a link (on mobile)
                menuItems.querySelectorAll('a').forEach(link => {
                    link.addEventListener('click', function() {
                        if (window.innerWidth <= 992 && menuItems.classList.contains('active')) {
                            toggleMenu();
                        }
                    });
                });
                
                // Close menu on escape key
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && menuItems.classList.contains('active')) {
                        toggleMenu();
                    }
                });
            }
            
            const dropdowns = document.querySelectorAll('.dropdown');
            dropdowns.forEach(dropdown => {
                const btn = dropdown.querySelector('.dropdown-btn');
                if (btn) {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        dropdowns.forEach(d => { if (d !== dropdown) d.classList.remove('active'); });
                        dropdown.classList.toggle('active');
                    });
                }
            });
            
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.dropdown')) {
                    dropdowns.forEach(dropdown => dropdown.classList.remove('active'));
                }
                if (window.innerWidth <= 992) {
                    if (!e.target.closest('.menu-items') && !e.target.closest('.hamburger-lines') && !e.target.closest('.menu-overlay')) {
                        if (menuItems && menuItems.classList.contains('active')) {
                            const menuOverlay = document.getElementById('menuOverlay');
                            if (menuOverlay) menuOverlay.classList.remove('active');
                            menuItems.classList.remove('active');
                            if (hamburger) hamburger.classList.remove('active');
                            document.body.style.overflow = '';
                        }
                    }
                }
            });
            
            // Tab functionality
            const tabBtns = document.querySelectorAll('.tab-btn');
            const tabContents = document.querySelectorAll('.tab-content');
            
            tabBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    tabBtns.forEach(b => b.classList.remove('active'));
                    tabContents.forEach(c => c.classList.remove('active'));
                    this.classList.add('active');
                    const tabId = this.getAttribute('data-tab');
                    document.getElementById(tabId).classList.add('active');
                });
            });
            
            setTimeout(function() {
                const alertBox = document.getElementById("alertBox");
                if (alertBox) {
                    alertBox.style.transition = "opacity 0.5s ease";
                    alertBox.style.opacity = "0";
                    setTimeout(() => { if (alertBox.parentNode) alertBox.remove(); }, 500);
                }
            }, 5000);
            
            const openSignupBtns = document.querySelectorAll('[id^="openSignup"], #mobileSignup');
            openSignupBtns.forEach(btn => {
                if (btn) {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        const signupModal = new bootstrap.Modal(document.getElementById('signupModal'));
                        signupModal.show();
                        if (menuItems) menuItems.classList.remove('active');
                        if (hamburger) hamburger.classList.remove('active');
                        if (menuOverlay) menuOverlay.classList.remove('active');
                        document.body.style.overflow = '';
                    });
                }
            });
            
            // Open login modal from any .open-login-modal link
            document.querySelectorAll('.open-login-modal').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    showLoginModal('Please login to continue', '');
                });
            });
            
            const signupRole = document.getElementById('signupRole');
            const isVendorWrap = document.getElementById('isVendorWrap');
            const isVendorCheckbox = document.getElementById('isVendor');
            const storeNameField = document.getElementById('storeNameField');

            function refreshVendorFields() {
                const role = signupRole ? signupRole.value : 'buyer';
                if (role === 'vendor') {
                    if (isVendorWrap) isVendorWrap.style.display = 'block';
                    if (isVendorCheckbox) isVendorCheckbox.checked = true;
                    if (storeNameField) storeNameField.style.display = 'block';
                } else {
                    if (isVendorWrap) isVendorWrap.style.display = 'none';
                    if (isVendorCheckbox) isVendorCheckbox.checked = false;
                    if (storeNameField) storeNameField.style.display = 'none';
                }
            }

            if (signupRole) {
                signupRole.addEventListener('change', refreshVendorFields);
                refreshVendorFields();
            }
            
            const adPostForm = document.getElementById('adPostForm');
            if (adPostForm) {
                adPostForm.addEventListener('submit', function(e) {
                    if (!isLoggedIn) {
                        e.preventDefault();
                        showLoginRequired('post', 'ad');
                        return false;
                    }
                });
            }

            const postAdModal = document.getElementById('postAdModal');
            if (postAdModal) {
                postAdModal.addEventListener('show.bs.modal', function() {
                    if (isLoggedIn) {
                        const contactName = document.getElementById('contact_name');
                        const contactPhone = document.getElementById('contact_phone');
                        const contactEmail = document.getElementById('contact_email');
                        
                        <?php if ($isLoggedIn): ?>
                            if (contactName && contactName.value === '') {
                                contactName.value = '<?php echo htmlspecialchars(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')); ?>';
                            }
                            if (contactPhone && contactPhone.value === '') {
                                contactPhone.value = '<?php echo htmlspecialchars($_SESSION['phone'] ?? ''); ?>';
                            }
                            if (contactEmail && contactEmail.value === '') {
                                contactEmail.value = '<?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?>';
                            }
                        <?php endif; ?>
                    }
                });
            }
        });
        
        // ============================================
        // MOBILE SEARCH TOGGLE
        // ============================================
        function toggleMobileSearch() {
            const searchBox = document.querySelector('.search-box');
            if (searchBox) {
                if (searchBox.style.display === 'flex' || searchBox.style.display === '') {
                    searchBox.style.display = 'none';
                } else {
                    searchBox.style.display = 'flex';
                    const input = searchBox.querySelector('input');
                    if (input) setTimeout(() => input.focus(), 100);
                }
            }
        }
        
        // ============================================
        // HANDLE ADD TO CART
        // ============================================
        function handleAddToCart(listingId, category) {
            if (!isLoggedIn) {
                // Not logged in - redirect to item_details.php with add_to_cart parameter
                const redirectUrl = 'item_details.php?id=' + listingId + '&add_to_cart=1';
                window.location.href = redirectUrl;
                return;
            }
            
            // User is logged in - proceed with AJAX add to cart
            const btn = event?.target?.closest('.add-to-cart-btn') || document.querySelector(`[data-listing-id="${listingId}"]`);
            if (btn) {
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
                btn.disabled = true;
                
                $.ajax({
                    url: 'cart_items.php',
                    method: 'POST',
                    data: {
                        action: 'add',
                        listing_id: listingId,
                        quantity: 1
                    },
                    dataType: 'json',
                    success: function(response) {
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                        
                        if (response.success) {
                            showToast(response.message || 'Added to cart!', 'success');
                            updateCartBadge(response.cart_count);
                        } else {
                            showToast(response.message || 'Failed to add to cart', 'error');
                        }
                    },
                    error: function() {
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                        showToast('An error occurred. Please try again.', 'error');
                    }
                });
            }
        }

        // ============================================
        // SHOW LOGIN MODAL
        // ============================================
        function showLoginModal(message, redirectUrl) {
            const modal = new bootstrap.Modal(document.getElementById('loginModal'));
            document.getElementById('loginModalMessage').textContent = message || 'Please login to continue';
            document.getElementById('loginRedirectUrl').value = redirectUrl || '';
            modal.show();
        }

        // ============================================
        // SWITCH BETWEEN LOGIN AND SIGNUP
        // ============================================
        function switchToSignup() {
            const loginModal = bootstrap.Modal.getInstance(document.getElementById('loginModal'));
            if (loginModal) loginModal.hide();
            const signupModal = new bootstrap.Modal(document.getElementById('signupModal'));
            signupModal.show();
        }

        function switchToLogin() {
            const signupModal = bootstrap.Modal.getInstance(document.getElementById('signupModal'));
            if (signupModal) signupModal.hide();
            const loginModal = new bootstrap.Modal(document.getElementById('loginModal'));
            loginModal.show();
        }

        // ============================================
        // TOAST NOTIFICATION
        // ============================================
        function showToast(message, type = 'info') {
            $('.toast-notification').remove();
            const icons = {
                success: 'fa-check-circle',
                error: 'fa-exclamation-circle',
                info: 'fa-info-circle',
                warning: 'fa-exclamation-triangle'
            };
            const toast = $(`
                <div class="toast-notification ${type}">
                    <i class="fas ${icons[type] || 'fa-info-circle'}"></i>
                    <span>${message}</span>
                </div>
            `);
            $('body').append(toast);
            setTimeout(() => {
                toast.fadeOut(300, function() { $(this).remove(); });
            }, 4000);
        }

        // ============================================
        // UPDATE CART BADGE
        // ============================================
        function updateCartBadge(count) {
            const badge = $('.btn[title="Cart"] .badge');
            if (count > 0) {
                if (badge.length === 0) {
                    $('.btn[title="Cart"]').append(
                        '<span class="badge bg-danger position-absolute top-0 start-100 translate-middle rounded-pill" style="font-size: 0.6rem; min-width: 18px;">' + 
                        count + 
                        '</span>'
                    );
                } else {
                    badge.text(count);
                }
            } else {
                badge.remove();
            }
        }

        // ============================================
        // CATEGORY MODAL FUNCTION
        // ============================================
        function openCategoryModal(categoryId, categoryName) {
            const modal = new bootstrap.Modal(document.getElementById('categoryModal'));
            const modalTitle = document.getElementById('categoryModalTitle');
            const modalCount = document.getElementById('categoryModalCount');
            const modalBody = document.getElementById('categoryModalBody');
            
            modalTitle.textContent = categoryName || 'Category';
            modalCount.textContent = '';
            modalBody.innerHTML = `
                <div class="modal-loading">
                    <div class="spinner-border text-primary spinner" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading listings...</p>
                </div>
            `;
            
            modal.show();
            
            const url = `get_category_listings.php?id=${categoryId}&name=${encodeURIComponent(categoryName)}`;
            
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const listings = data.listings;
                        const count = listings.length;
                        
                        modalCount.textContent = `(${count} listing${count !== 1 ? 's' : ''})`;
                        
                        if (count > 0) {
                            let html = `<div class="modal-listings-grid">`;
                            listings.forEach(listing => {
                                const isOwner = <?php echo $isLoggedIn ? 'true' : 'false'; ?> && listing.user_id === <?php echo $_SESSION['user_id'] ?? 0; ?>;
                                const image = listing.image || 'https://images.unsplash.com/photo-1560518883-ce09059eeffa?ixlib=rb-4.0.3&auto=format&fit=crop&w=1000&q=80';
                                const price = listing.price ? `₦${Number(listing.price).toLocaleString()}` : '';
                                const sellerName = listing.store_name || listing.username || 'Unknown Seller';
                                const avatar = listing.avatar_url || '';
                                const verified = listing.store_verified == 1;
                                
                                html += `
                                    <div class="listing-card">
                                        <div class="listing-img">
                                            <img src="${image}" alt="${listing.title}" loading="lazy">
                                            <span class="listing-badge">${listing.category || 'General'}</span>
                                        </div>
                                        <div class="listing-content">
                                            <h3>${listing.title ? listing.title.substring(0, 50) + (listing.title.length > 50 ? '...' : '') : 'Untitled'}</h3>
                                            <div class="listing-meta">
                                                <span><i class="fas fa-map-marker-alt"></i> ${listing.location || 'N/A'}</span>
                                                ${listing.bedrooms ? `<span><i class="fas fa-bed"></i> ${listing.bedrooms} beds</span>` : ''}
                                            </div>
                                            ${price ? `<div class="listing-price">${price}</div>` : ''}
                                            
                                            <div class="listing-seller">
                                                ${avatar ? 
                                                    `<img src="${avatar}" alt="${sellerName}" class="seller-avatar-img">` :
                                                    `<div class="seller-avatar-placeholder">${sellerName.charAt(0).toUpperCase()}</div>`
                                                }
                                                <div class="seller-info">
                                                    <a href="stores.php?user_id=${listing.user_id}" class="seller-name">
                                                        ${sellerName}
                                                        ${verified ? 
                                                            `<span class="store-badge store-verified"><i class="fas fa-check"></i> Verified</span>` :
                                                            `<span class="store-badge store-unverified"><i class="fas fa-clock"></i> Not Verified</span>`
                                                        }
                                                    </a>
                                                    ${listing.store_description ? `<p class="seller-description">${listing.store_description}</p>` : ''}
                                                </div>
                                            </div>
                                            
                                            <div class="d-flex gap-2 mt-auto">
                                                ${isOwner ? 
                                                    `<button class="btn btn-outline-secondary btn-sm add-to-cart-btn flex-grow-1" disabled style="opacity:0.6; cursor:not-allowed;">
                                                        <i class="fas fa-user me-1"></i> Your Item
                                                    </button>` :
                                                    `<button class="btn btn-outline btn-sm add-to-cart-btn flex-grow-1" 
                                                            onclick="handleAddToCart(${listing.id}, '${listing.category}')">
                                                        <i class="fas fa-shopping-cart me-1"></i> Add
                                                    </button>`
                                                }
                                                <button class="btn btn-outline btn-sm" onclick="checkLoginBeforeViewListing(${listing.id}, '${listing.category}')">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                `;
                            });
                            html += `</div>`;
                            modalBody.innerHTML = html;
                        } else {
                            modalBody.innerHTML = `
                                <div class="empty-listings">
                                    <i class="fas fa-box-open"></i>
                                    <h5>No listings found</h5>
                                    <p>There are currently no listings in this category.</p>
                                </div>
                            `;
                        }
                    } else {
                        modalBody.innerHTML = `
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                ${data.message || 'Failed to load listings.'}
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    modalBody.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            An error occurred while loading listings. Please try again.
                        </div>
                    `;
                });
        }
        
        function checkLoginBeforePost(type) {
            if (!isLoggedIn) {
                showLoginRequired('post', type);
                return false;
            }
            const adModal = new bootstrap.Modal(document.getElementById('postAdModal'));
            const categorySelect = document.getElementById('adCategory');
            if (categorySelect) {
                categorySelect.value = type;
                updateFormFields();
            }
            adModal.show();
            return true;
        }
        
        function checkLoginBeforeViewListing(listingId, category) {
            if (!isLoggedIn) {
                // Not logged in - redirect to item_details.php with login required
                window.location.href = 'item_details.php?id=' + listingId + '&login_required=1';
                return false;
            }
            if (category === 'property' || category === 'land' || category === 'apartment') {
                window.location.href = 'property_details.php?id=' + listingId;
            } else {
                window.location.href = 'item_details.php?id=' + listingId;
            }
            return true;
        }
        
        function showLoginRequired(action, type = null) {
            let message = '';
            if (action === 'post') {
                message = `You need to login to post a ${type === 'item' ? 'used item' : 'property listing'}.`;
            } else if (action === 'buy') {
                message = 'You need to login to purchase items or view details.';
            } else if (action === 'view') {
                message = 'Please login to view full details of this listing.';
            } else {
                message = 'Please login to continue.';
            }
            showLoginModal(message, '');
        }
        
        function updateFormFields() {
            const category = document.getElementById('adCategory').value;
            const propertyFields = document.getElementById('propertyFields');
            const itemFields = document.getElementById('itemFields');
            if (propertyFields) propertyFields.style.display = 'none';
            if (itemFields) itemFields.style.display = 'none';
            if (category === 'property' || category === 'apartment') {
                if (propertyFields) propertyFields.style.display = 'block';
            } else if (category === 'item') {
                if (itemFields) itemFields.style.display = 'block';
            }
        }
        
        function openSignupModal() {
            const loginModal = bootstrap.Modal.getInstance(document.getElementById('loginModal'));
            if (loginModal) loginModal.hide();
            const signupModal = new bootstrap.Modal(document.getElementById('signupModal'));
            signupModal.show();
        }
        
        window.addEventListener('resize', function() {
            if (window.innerWidth > 992) {
                const menuItems = document.querySelector('.menu-items');
                const hamburger = document.querySelector('.hamburger-lines');
                const menuOverlay = document.getElementById('menuOverlay');
                if (menuItems) menuItems.classList.remove('active');
                if (hamburger) hamburger.classList.remove('active');
                if (menuOverlay) menuOverlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
        
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                const href = this.getAttribute('href');
                if (href !== '#') {
                    e.preventDefault();
                    document.querySelector(href).scrollIntoView({ behavior: 'smooth' });
                }
            });
        });

        console.log('✅ Ebonyi Market Hub loaded successfully');
        console.log('✅ User logged in:', isLoggedIn);
    </script>
    
    <?php
    $conn->close();
    ?>
</body>
</html>