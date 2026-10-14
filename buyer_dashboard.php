<?php
session_start();
require 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_after_login'] = 'buyer_dashboard.php';
    header('Location: login.php');
    exit();
}

// Check if user is a buyer (not vendor or admin)
if ($_SESSION['is_vendor'] == 1 || $_SESSION['is_admin'] == 1) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$error = '';
$success = '';

// ============================================
// FETCH BUYER STATISTICS
// ============================================

// Total orders
$orders_query = "SELECT COUNT(*) as total FROM orders WHERE user_id = ?";
$stmt = $conn->prepare($orders_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$total_orders = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

// Total spent
$spent_query = "SELECT COALESCE(SUM(total_amount), 0) as total FROM orders WHERE user_id = ? AND status = 'delivered'";
$stmt = $conn->prepare($spent_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$total_spent = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

// Pending orders
$pending_query = "SELECT COUNT(*) as total FROM orders WHERE user_id = ? AND status = 'pending'";
$stmt = $conn->prepare($pending_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$pending_orders = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

// Wishlist count
$wishlist_query = "SELECT COUNT(*) as total FROM wishlist WHERE user_id = ?";
$stmt = $conn->prepare($wishlist_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$wishlist_count = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

// Cart count
$cart_query = "SELECT COUNT(*) as total FROM cart WHERE user_id = ?";
$stmt = $conn->prepare($cart_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$cart_count = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

// ============================================
// FETCH RECENT ORDERS
// ============================================
$recent_orders_query = "SELECT o.*, 
                        (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as items_count 
                        FROM orders o 
                        WHERE o.user_id = ? 
                        ORDER BY o.created_at DESC LIMIT 5";
$stmt = $conn->prepare($recent_orders_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$recent_orders = $stmt->get_result();

// ============================================
// FETCH WISHLIST ITEMS
// ============================================
$wishlist_query = "SELECT w.*, l.title, l.price, l.image, l.location, l.created_at as listing_created 
                   FROM wishlist w 
                   JOIN listings l ON w.listing_id = l.id 
                   WHERE w.user_id = ? 
                   ORDER BY w.created_at DESC LIMIT 4";
$stmt = $conn->prepare($wishlist_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$wishlist_items = $stmt->get_result();

// ============================================
// FETCH RECENTLY VIEWED (if table exists)
// ============================================
$recently_viewed = null;
$table_check = $conn->query("SHOW TABLES LIKE 'recently_viewed'");
if ($table_check && $table_check->num_rows > 0) {
    $recent_query = "SELECT r.*, l.title, l.price, l.image 
                     FROM recently_viewed r 
                     JOIN listings l ON r.listing_id = l.id 
                     WHERE r.user_id = ? 
                     ORDER BY r.viewed_at DESC LIMIT 4";
    $stmt = $conn->prepare($recent_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $recently_viewed = $stmt->get_result();
}

// ============================================
// FETCH USER PROFILE DATA
// ============================================
$profile_query = "SELECT * FROM users WHERE id = ?";
$stmt = $conn->prepare($profile_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_profile = $stmt->get_result()->fetch_assoc();

// ============================================
// HANDLE PROFILE UPDATE
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $zip_code = trim($_POST['zip_code'] ?? '');

    $update_query = "UPDATE users SET 
                     full_name = ?, phone = ?, address = ?, 
                     city = ?, state = ?, country = ?, zip_code = ? 
                     WHERE id = ?";
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param("sssssssi", $full_name, $phone, $address, $city, $state, $country, $zip_code, $user_id);
    
    if ($stmt->execute()) {
        $success = 'Profile updated successfully!';
        $_SESSION['full_name'] = $full_name;
        // Refresh profile data
        $profile_query = "SELECT * FROM users WHERE id = ?";
        $stmt = $conn->prepare($profile_query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $user_profile = $stmt->get_result()->fetch_assoc();
    } else {
        $error = 'Error updating profile: ' . $stmt->error;
    }
}

// ============================================
// HANDLE PASSWORD CHANGE
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = 'All password fields are required.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'New passwords do not match.';
    } elseif (strlen($new_password) < 4) {
        $error = 'New password must be at least 4 characters.';
    } else {
        // Verify current password
        $pass_query = "SELECT password FROM users WHERE id = ?";
        $stmt = $conn->prepare($pass_query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if (password_verify($current_password, $result['password']) || $result['password'] === $current_password) {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $update_query = "UPDATE users SET password = ? WHERE id = ?";
            $stmt = $conn->prepare($update_query);
            $stmt->bind_param("si", $hashed, $user_id);
            if ($stmt->execute()) {
                $success = 'Password changed successfully!';
            } else {
                $error = 'Error changing password.';
            }
        } else {
            $error = 'Current password is incorrect.';
        }
    }
}

// ============================================
// HANDLE ADD TO CART FROM WISHLIST
// ============================================
if (isset($_GET['add_to_cart']) && isset($_GET['listing_id'])) {
    $listing_id = intval($_GET['listing_id']);
    
    // Check if already in cart
    $check_query = "SELECT * FROM cart WHERE user_id = ? AND listing_id = ?";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("ii", $user_id, $listing_id);
    $stmt->execute();
    $cart_check = $stmt->get_result();
    
    if ($cart_check->num_rows > 0) {
        // Update quantity
        $update_query = "UPDATE cart SET quantity = quantity + 1 WHERE user_id = ? AND listing_id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("ii", $user_id, $listing_id);
        $stmt->execute();
    } else {
        // Add to cart
        $insert_query = "INSERT INTO cart (user_id, listing_id, quantity) VALUES (?, ?, 1)";
        $stmt = $conn->prepare($insert_query);
        $stmt->bind_param("ii", $user_id, $listing_id);
        $stmt->execute();
    }
    $success = 'Item added to cart!';
    header('Location: buyer_dashboard.php?tab=wishlist');
    exit();
}

// ============================================
// HANDLE REMOVE FROM WISHLIST
// ============================================
if (isset($_GET['remove_wishlist']) && isset($_GET['listing_id'])) {
    $listing_id = intval($_GET['listing_id']);
    $delete_query = "DELETE FROM wishlist WHERE user_id = ? AND listing_id = ?";
    $stmt = $conn->prepare($delete_query);
    $stmt->bind_param("ii", $user_id, $listing_id);
    $stmt->execute();
    header('Location: buyer_dashboard.php?tab=wishlist');
    exit();
}

// Get current tab from URL
$active_tab = $_GET['tab'] ?? 'overview';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Dashboard - Buyer</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:ital,wght@0,600;0,700;1,600&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --bg: #f7f5f0;
            --surface: #ffffff;
            --surface-alt: #f0ede6;
            --text: #1a1a1a;
            --text-muted: #6b6b6b;
            --border: #e5e0d6;
            --accent: #c49b3f;
            --accent-light: #f5ede0;
            --accent-dark: #9a7a2e;
            --rose: #c44536;
            --rose-light: #f5e0db;
            --forest: #2d5a4c;
            --forest-light: #dce8e3;
            --shadow: 0 2px 12px rgba(0,0,0,0.06);
            --shadow-hover: 0 8px 32px rgba(0,0,0,0.10);
            --radius: 16px;
            --radius-sm: 10px;
            --font-body: 'Inter', -apple-system, sans-serif;
            --font-display: 'Playfair Display', Georgia, serif;
            --transition: all 0.3s ease;
        }

        body {
            background: var(--bg);
            font-family: var(--font-body);
            color: var(--text);
            min-height: 100vh;
        }

        /* ============================================
           HEADER / NAVIGATION
           ============================================ */
        .dashboard-header {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 0.8rem 2rem;
            position: sticky;
            top: 0;
            z-index: 100;
            backdrop-filter: blur(12px);
            background: rgba(255,255,255,0.95);
        }

        .header-inner {
            max-width: 1280px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.8rem;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .header-left .logo {
            width: 42px;
            height: 42px;
            background: var(--accent);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            font-weight: 700;
        }

        .header-left h1 {
            font-size: 1.2rem;
            font-weight: 700;
            font-family: var(--font-display);
        }

        .header-left h1 small {
            font-size: 0.7rem;
            font-weight: 400;
            color: var(--text-muted);
            font-family: var(--font-body);
            display: block;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.5rem 1.2rem;
            border-radius: 40px;
            font-weight: 600;
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            background: var(--surface-alt);
            color: var(--text);
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow);
        }

        .btn-primary {
            background: var(--accent);
            color: white;
        }
        .btn-primary:hover { background: var(--accent-dark); }

        .btn-rose {
            background: var(--rose);
            color: white;
        }
        .btn-rose:hover { background: #a83a2a; }

        .btn-forest {
            background: var(--forest);
            color: white;
        }
        .btn-forest:hover { background: #1f4539; }

        .btn-outline {
            background: transparent;
            border: 1.5px solid var(--border);
        }
        .btn-outline:hover { background: var(--surface); border-color: var(--text); }

        .btn-sm {
            padding: 0.3rem 0.9rem;
            font-size: 0.75rem;
        }

        .btn-cart {
            background: var(--accent);
            color: white;
            position: relative;
        }
        .btn-cart .badge {
            position: absolute;
            top: -6px;
            right: -6px;
            background: var(--rose);
            color: white;
            font-size: 0.6rem;
            font-weight: 700;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* ============================================
           DASHBOARD LAYOUT
           ============================================ */
        .dashboard-body {
            max-width: 1280px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, var(--surface), var(--accent-light));
            border-radius: var(--radius);
            padding: 1.5rem 2rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .welcome-banner h2 {
            font-family: var(--font-display);
            font-size: 1.6rem;
            font-weight: 700;
        }

        .welcome-banner p {
            color: var(--text-muted);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--surface);
            padding: 1.2rem;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            text-align: center;
            transition: var(--transition);
        }

        .stat-card:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        .stat-card .icon {
            font-size: 1.8rem;
            color: var(--accent);
            margin-bottom: 0.3rem;
        }

        .stat-card .number {
            font-family: var(--font-display);
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text);
        }

        .stat-card .label {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 0.3rem;
            border-bottom: 1px solid var(--border);
            margin-bottom: 2rem;
            overflow-x: auto;
            padding-bottom: 0.3rem;
        }

        .tabs a {
            padding: 0.6rem 1.2rem;
            border-radius: 40px;
            font-weight: 500;
            font-size: 0.85rem;
            color: var(--text-muted);
            text-decoration: none;
            transition: var(--transition);
            white-space: nowrap;
        }

        .tabs a:hover {
            color: var(--text);
            background: var(--surface-alt);
        }

        .tabs a.active {
            color: var(--text);
            background: var(--surface);
            border: 1px solid var(--border);
            font-weight: 600;
        }

        /* Tab Content */
        .tab-content {
            display: none;
            animation: fadeUp 0.4s ease;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.8rem;
            margin-bottom: 1.2rem;
        }

        .section-header h3 {
            font-family: var(--font-display);
            font-size: 1.3rem;
            font-weight: 700;
        }

        /* Orders Table */
        .orders-table-wrap {
            background: var(--surface);
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            overflow: hidden;
        }

        .orders-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .orders-table th {
            text-align: left;
            padding: 0.8rem 1.2rem;
            background: var(--surface-alt);
            font-weight: 600;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--text-muted);
        }

        .orders-table td {
            padding: 0.8rem 1.2rem;
            border-top: 1px solid var(--border);
        }

        .orders-table .status {
            padding: 0.15rem 0.7rem;
            border-radius: 40px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-delivered { background: var(--forest-light); color: var(--forest); }
        .status-cancelled { background: var(--rose-light); color: var(--rose); }
        .status-pending { background: var(--accent-light); color: var(--accent-dark); }
        .status-shipped { background: #e3edf5; color: #2c6b8a; }
        .status-other { background: var(--surface-alt); color: var(--text-muted); }

        .order-link {
            color: var(--accent);
            text-decoration: none;
            font-weight: 500;
        }
        .order-link:hover { text-decoration: underline; }

        /* Wishlist Grid */
        .wishlist-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 1.2rem;
        }

        .wishlist-item {
            background: var(--surface);
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            overflow: hidden;
            transition: var(--transition);
        }

        .wishlist-item:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        .wishlist-item img {
            width: 100%;
            height: 160px;
            object-fit: cover;
            background: var(--surface-alt);
        }

        .wishlist-item .info {
            padding: 0.8rem 1rem;
        }

        .wishlist-item .info h4 {
            font-size: 0.95rem;
            font-weight: 600;
            margin-bottom: 0.2rem;
        }

        .wishlist-item .info .price {
            font-weight: 700;
            color: var(--rose);
            font-size: 1.05rem;
        }

        .wishlist-item .info .location {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        .wishlist-item .actions {
            display: flex;
            gap: 0.4rem;
            padding: 0.4rem 1rem 0.8rem;
            flex-wrap: wrap;
        }

        /* Profile Form */
        .profile-form {
            background: var(--surface);
            padding: 1.5rem;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            max-width: 600px;
        }

        .profile-form .form-group {
            margin-bottom: 1rem;
        }

        .profile-form label {
            display: block;
            font-weight: 600;
            font-size: 0.85rem;
            margin-bottom: 0.2rem;
        }

        .profile-form input,
        .profile-form textarea {
            width: 100%;
            padding: 0.7rem 1rem;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 0.9rem;
            font-family: var(--font-body);
            transition: var(--transition);
            background: var(--bg);
        }

        .profile-form input:focus,
        .profile-form textarea:focus {
            outline: none;
            border-color: var(--accent);
            background: var(--surface);
        }

        .profile-form .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.8rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2.5rem 2rem;
            background: var(--surface);
            border-radius: var(--radius-sm);
            border: 1px dashed var(--border);
        }

        .empty-state i {
            font-size: 2.5rem;
            color: var(--border);
            margin-bottom: 0.5rem;
        }

        .empty-state h4 {
            font-family: var(--font-display);
            font-size: 1.1rem;
        }

        .empty-state p {
            color: var(--text-muted);
            margin-bottom: 0.8rem;
        }

        /* Alerts */
        .alert {
            padding: 0.8rem 1rem;
            border-radius: var(--radius-sm);
            font-size: 0.9rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success {
            background: var(--forest-light);
            color: var(--forest);
            border: 1px solid #bcd5cc;
        }

        .alert-error {
            background: var(--rose-light);
            color: var(--rose);
            border: 1px solid #f5d0ca;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .dashboard-header { padding: 0.8rem 1rem; }
            .dashboard-body { padding: 1rem; }
            .welcome-banner { flex-direction: column; text-align: center; }
            .profile-form .form-row { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .wishlist-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .header-actions .btn span { display: none; }
        }

        /* Avatar */
        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--accent-light);
            color: var(--accent-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1rem;
        }
    </style>
</head>
<body>

<!-- HEADER -->
<header class="dashboard-header">
    <div class="header-inner">
        <div class="header-left">
            <div class="logo"><i class="fas fa-user"></i></div>
            <h1>
                My Dashboard
                <small>Welcome back, <?php echo htmlspecialchars($username); ?></small>
            </h1>
        </div>
        <div class="header-actions">
            <a href="cart.php" class="btn btn-cart">
                <i class="fas fa-shopping-cart"></i>
                <span>Cart</span>
                <?php if ($cart_count > 0): ?>
                    <span class="badge"><?php echo $cart_count; ?></span>
                <?php endif; ?>
            </a>
            <a href="index.php" class="btn btn-outline">
                <i class="fas fa-store"></i> Browse Store
            </a>
            <a href="logout.php" class="btn btn-rose btn-sm">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>
</header>

<!-- DASHBOARD BODY -->
<div class="dashboard-body">

    <!-- Welcome Banner -->
    <div class="welcome-banner">
        <div>
            <h2>👋 Hello, <?php echo htmlspecialchars($username); ?>!</h2>
            <p>Here's what's happening with your account</p>
        </div>
        <div style="display:flex; gap:0.6rem; flex-wrap:wrap;">
            <a href="index.php" class="btn btn-primary"><i class="fas fa-search"></i> Browse Products</a>
            <a href="cart.php" class="btn btn-forest"><i class="fas fa-shopping-cart"></i> View Cart</a>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon"><i class="fas fa-shopping-bag"></i></div>
            <div class="number"><?php echo $total_orders; ?></div>
            <div class="label">Total Orders</div>
        </div>
        <div class="stat-card">
            <div class="icon" style="color:var(--rose);"><i class="fas fa-clock"></i></div>
            <div class="number"><?php echo $pending_orders; ?></div>
            <div class="label">Pending Orders</div>
        </div>
        <div class="stat-card">
            <div class="icon" style="color:var(--forest);"><i class="fas fa-heart"></i></div>
            <div class="number"><?php echo $wishlist_count; ?></div>
            <div class="label">Wishlist Items</div>
        </div>
        <div class="stat-card">
            <div class="icon" style="color:var(--accent);"><i class="fas fa-money-bill-wave"></i></div>
            <div class="number">₦<?php echo number_format($total_spent); ?></div>
            <div class="label">Total Spent</div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="tabs">
        <a href="?tab=overview" class="<?php echo $active_tab === 'overview' ? 'active' : ''; ?>">
            <i class="fas fa-th-large"></i> Overview
        </a>
        <a href="?tab=orders" class="<?php echo $active_tab === 'orders' ? 'active' : ''; ?>">
            <i class="fas fa-list"></i> Orders
        </a>
        <a href="?tab=wishlist" class="<?php echo $active_tab === 'wishlist' ? 'active' : ''; ?>">
            <i class="fas fa-heart"></i> Wishlist
        </a>
        <a href="?tab=profile" class="<?php echo $active_tab === 'profile' ? 'active' : ''; ?>">
            <i class="fas fa-user-cog"></i> Profile
        </a>
        <a href="?tab=security" class="<?php echo $active_tab === 'security' ? 'active' : ''; ?>">
            <i class="fas fa-shield-alt"></i> Security
        </a>
    </div>

    <!-- ============================================
    TAB 1: OVERVIEW
    ============================================ -->
    <div class="tab-content <?php echo $active_tab === 'overview' ? 'active' : ''; ?>" id="tab-overview">
        <?php if ($recent_orders && $recent_orders->num_rows > 0): ?>
            <div class="section-header">
                <h3>Recent Orders</h3>
                <a href="?tab=orders" class="btn btn-outline btn-sm">View All <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="orders-table-wrap">
                <table class="orders-table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Date</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($order = $recent_orders->fetch_assoc()): ?>
                            <tr>
                                <td><strong>#<?php echo $order['order_number']; ?></strong></td>
                                <td><?php echo date('M d, Y', strtotime($order['created_at'])); ?></td>
                                <td><?php echo $order['items_count']; ?> items</td>
                                <td><strong>₦<?php echo number_format($order['total_amount']); ?></strong></td>
                                <td>
                                    <span class="status <?php 
                                        echo $order['status'] == 'delivered' ? 'status-delivered' : 
                                             ($order['status'] == 'cancelled' ? 'status-cancelled' : 
                                             ($order['status'] == 'pending' ? 'status-pending' : 
                                             ($order['status'] == 'shipped' ? 'status-shipped' : 'status-other')));
                                    ?>">
                                        <?php echo ucfirst($order['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="order_details.php?id=<?php echo $order['id']; ?>" class="order-link">
                                        View <i class="fas fa-arrow-right"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-shopping-bag"></i>
                <h4>No orders yet</h4>
                <p>Start shopping to see your orders here.</p>
                <a href="index.php" class="btn btn-primary"><i class="fas fa-store"></i> Browse Products</a>
            </div>
        <?php endif; ?>

        <?php if ($wishlist_items && $wishlist_items->num_rows > 0): ?>
            <div class="section-header" style="margin-top: 2rem;">
                <h3>Wishlist Items</h3>
                <a href="?tab=wishlist" class="btn btn-outline btn-sm">View All <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="wishlist-grid">
                <?php 
                $wishlist_items->data_seek(0);
                while ($item = $wishlist_items->fetch_assoc()): 
                ?>
                    <div class="wishlist-item">
                        <?php if (!empty($item['image'])): ?>
                            <img src="<?php echo htmlspecialchars($item['image']); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>">
                        <?php else: ?>
                            <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='220' height='160'%3E%3Crect width='220' height='160' fill='%23f0ede8'/%3E%3Ctext x='110' y='85' text-anchor='middle' font-family='sans-serif' font-size='14' fill='%23999'%3ENo Image%3C/text%3E%3C/svg%3E" alt="No image">
                        <?php endif; ?>
                        <div class="info">
                            <h4><?php echo htmlspecialchars($item['title']); ?></h4>
                            <div class="location"><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars($item['location'] ?? 'N/A'); ?></div>
                            <div class="price">₦<?php echo number_format($item['price']); ?></div>
                        </div>
                        <div class="actions">
                            <a href="property_details.php?id=<?php echo $item['listing_id']; ?>" class="btn btn-sm btn-outline">
                                <i class="fas fa-eye"></i> View
                            </a>
                            <a href="?add_to_cart=1&listing_id=<?php echo $item['listing_id']; ?>" class="btn btn-sm btn-forest">
                                <i class="fas fa-cart-plus"></i> Add to Cart
                            </a>
                            <a href="?remove_wishlist=1&listing_id=<?php echo $item['listing_id']; ?>" class="btn btn-sm btn-rose" 
                               onclick="return confirm('Remove from wishlist?')">
                                <i class="fas fa-trash"></i>
                            </a>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ============================================
    TAB 2: ORDERS
    ============================================ -->
    <div class="tab-content <?php echo $active_tab === 'orders' ? 'active' : ''; ?>" id="tab-orders">
        <div class="section-header">
            <h3>All Orders</h3>
            <div>
                <a href="?tab=orders&filter=pending" class="btn btn-sm btn-outline">Pending</a>
                <a href="?tab=orders&filter=delivered" class="btn btn-sm btn-outline">Delivered</a>
                <a href="?tab=orders" class="btn btn-sm btn-primary">All</a>
            </div>
        </div>

        <?php
        // Fetch all orders with filter
        $filter = $_GET['filter'] ?? 'all';
        $orders_query = "SELECT o.*, 
                        (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as items_count 
                        FROM orders o 
                        WHERE o.user_id = ?";
        if ($filter !== 'all') {
            $orders_query .= " AND o.status = ?";
        }
        $orders_query .= " ORDER BY o.created_at DESC";
        
        $stmt = $conn->prepare($orders_query);
        if ($filter !== 'all') {
            $stmt->bind_param("is", $user_id, $filter);
        } else {
            $stmt->bind_param("i", $user_id);
        }
        $stmt->execute();
        $all_orders = $stmt->get_result();
        ?>

        <?php if ($all_orders && $all_orders->num_rows > 0): ?>
            <div class="orders-table-wrap">
                <table class="orders-table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Date</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($order = $all_orders->fetch_assoc()): ?>
                            <tr>
                                <td><strong>#<?php echo $order['order_number']; ?></strong></td>
                                <td><?php echo date('M d, Y', strtotime($order['created_at'])); ?></td>
                                <td><?php echo $order['items_count']; ?> items</td>
                                <td><strong>₦<?php echo number_format($order['total_amount']); ?></strong></td>
                                <td>
                                    <span class="status <?php 
                                        echo $order['status'] == 'delivered' ? 'status-delivered' : 
                                             ($order['status'] == 'cancelled' ? 'status-cancelled' : 
                                             ($order['status'] == 'pending' ? 'status-pending' : 
                                             ($order['status'] == 'shipped' ? 'status-shipped' : 'status-other')));
                                    ?>">
                                        <?php echo ucfirst($order['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="order_details.php?id=<?php echo $order['id']; ?>" class="order-link">
                                        View <i class="fas fa-arrow-right"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-shopping-bag"></i>
                <h4>No orders found</h4>
                <p>You haven't placed any orders yet.</p>
                <a href="index.php" class="btn btn-primary">Start Shopping</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- ============================================
    TAB 3: WISHLIST
    ============================================ -->
    <div class="tab-content <?php echo $active_tab === 'wishlist' ? 'active' : ''; ?>" id="tab-wishlist">
        <div class="section-header">
            <h3>My Wishlist</h3>
            <span class="text-muted"><?php echo $wishlist_count; ?> items</span>
        </div>

        <?php
        // Fetch all wishlist items
        $wishlist_query = "SELECT w.*, l.title, l.price, l.image, l.location, l.created_at as listing_created 
                          FROM wishlist w 
                          JOIN listings l ON w.listing_id = l.id 
                          WHERE w.user_id = ? 
                          ORDER BY w.created_at DESC";
        $stmt = $conn->prepare($wishlist_query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $all_wishlist = $stmt->get_result();
        ?>

        <?php if ($all_wishlist && $all_wishlist->num_rows > 0): ?>
            <div class="wishlist-grid">
                <?php while ($item = $all_wishlist->fetch_assoc()): ?>
                    <div class="wishlist-item">
                        <?php if (!empty($item['image'])): ?>
                            <img src="<?php echo htmlspecialchars($item['image']); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>">
                        <?php else: ?>
                            <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='220' height='160'%3E%3Crect width='220' height='160' fill='%23f0ede8'/%3E%3Ctext x='110' y='85' text-anchor='middle' font-family='sans-serif' font-size='14' fill='%23999'%3ENo Image%3C/text%3E%3C/svg%3E" alt="No image">
                        <?php endif; ?>
                        <div class="info">
                            <h4><?php echo htmlspecialchars($item['title']); ?></h4>
                            <div class="location"><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars($item['location'] ?? 'N/A'); ?></div>
                            <div class="price">₦<?php echo number_format($item['price']); ?></div>
                        </div>
                        <div class="actions">
                            <a href="property_details.php?id=<?php echo $item['listing_id']; ?>" class="btn btn-sm btn-outline">
                                <i class="fas fa-eye"></i> View
                            </a>
                            <a href="?add_to_cart=1&listing_id=<?php echo $item['listing_id']; ?>" class="btn btn-sm btn-forest">
                                <i class="fas fa-cart-plus"></i> Add to Cart
                            </a>
                            <a href="?remove_wishlist=1&listing_id=<?php echo $item['listing_id']; ?>" class="btn btn-sm btn-rose" 
                               onclick="return confirm('Remove from wishlist?')">
                                <i class="fas fa-trash"></i>
                            </a>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-heart"></i>
                <h4>Wishlist is empty</h4>
                <p>Save your favorite items to your wishlist.</p>
                <a href="index.php" class="btn btn-primary"><i class="fas fa-store"></i> Browse Products</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- ============================================
    TAB 4: PROFILE
    ============================================ -->
    <div class="tab-content <?php echo $active_tab === 'profile' ? 'active' : ''; ?>" id="tab-profile">
        <div class="section-header">
            <h3>Profile Settings</h3>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST" class="profile-form">
            <input type="hidden" name="update_profile" value="1">
            
            <div class="form-group">
                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="full_name" 
                       value="<?php echo htmlspecialchars($user_profile['full_name'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" value="<?php echo htmlspecialchars($user_profile['username']); ?>" disabled>
                <small style="color:var(--text-muted); font-size:0.75rem;">Username cannot be changed</small>
            </div>

            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" value="<?php echo htmlspecialchars($user_profile['email']); ?>" disabled>
                <small style="color:var(--text-muted); font-size:0.75rem;">Email cannot be changed</small>
            </div>

            <div class="form-group">
                <label for="phone">Phone Number</label>
                <input type="tel" id="phone" name="phone" 
                       value="<?php echo htmlspecialchars($user_profile['phone'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="address">Address</label>
                <input type="text" id="address" name="address" 
                       value="<?php echo htmlspecialchars($user_profile['address'] ?? ''); ?>">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="city">City</label>
                    <input type="text" id="city" name="city" 
                           value="<?php echo htmlspecialchars($user_profile['city'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="state">State</label>
                    <input type="text" id="state" name="state" 
                           value="<?php echo htmlspecialchars($user_profile['state'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="country">Country</label>
                    <input type="text" id="country" name="country" 
                           value="<?php echo htmlspecialchars($user_profile['country'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="zip_code">ZIP Code</label>
                    <input type="text" id="zip_code" name="zip_code" 
                           value="<?php echo htmlspecialchars($user_profile['zip_code'] ?? ''); ?>">
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Update Profile
            </button>
        </form>
    </div>

    <!-- ============================================
    TAB 5: SECURITY
    ============================================ -->
    <div class="tab-content <?php echo $active_tab === 'security' ? 'active' : ''; ?>" id="tab-security">
        <div class="section-header">
            <h3>Security Settings</h3>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST" class="profile-form" onsubmit="return validatePasswordForm()">
            <input type="hidden" name="change_password" value="1">
            
            <div class="form-group">
                <label for="current_password">Current Password</label>
                <input type="password" id="current_password" name="current_password" placeholder="Enter current password" required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" placeholder="Min 4 characters" required>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm new password" required>
                </div>
            </div>

            <button type="submit" class="btn btn-rose">
                <i class="fas fa-key"></i> Change Password
            </button>
        </form>

        <div style="margin-top: 2rem; background:var(--surface); padding:1.5rem; border-radius:var(--radius-sm); border:1px solid var(--border);">
            <h4 style="font-weight:600; margin-bottom:0.5rem;">Account Information</h4>
            <p style="color:var(--text-muted); font-size:0.9rem;">
                <i class="fas fa-user"></i> Member since: <?php echo date('F d, Y', strtotime($user_profile['created_at'])); ?><br>
                <i class="fas fa-<?php echo $user_profile['is_vendor'] ? 'store' : 'user'; ?>"></i> Account type: <?php echo $user_profile['is_vendor'] ? 'Vendor' : 'Buyer'; ?>
            </p>
        </div>
    </div>

</div>

<script>
    // Password validation for security tab
    function validatePasswordForm() {
        const current = document.getElementById('current_password').value;
        const newPass = document.getElementById('new_password').value;
        const confirm = document.getElementById('confirm_password').value;
        
        if (!current || !newPass || !confirm) {
            alert('Please fill in all password fields.');
            return false;
        }
        
        if (newPass.length < 4) {
            alert('New password must be at least 4 characters.');
            return false;
        }
        
        if (newPass !== confirm) {
            alert('New passwords do not match.');
            return false;
        }
        
        return true;
    }

    // Confirm remove from wishlist
    document.querySelectorAll('.remove-wishlist').forEach(link => {
        link.addEventListener('click', function(e) {
            if (!confirm('Remove this item from your wishlist?')) {
                e.preventDefault();
            }
        });
    });
</script>

</body>
</html>