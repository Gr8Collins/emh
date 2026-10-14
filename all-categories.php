<?php
session_start();
require 'config.php';

// Check if user is logged in
$isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

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
// GET TOTAL LISTINGS COUNT
// ============================================
$total_listings = 0;
$total_query = "SELECT COUNT(*) as total FROM listings WHERE status = 'active'";
$total_result = $conn->query($total_query);
if ($total_result && $total_result->num_rows > 0) {
    $total_row = $total_result->fetch_assoc();
    $total_listings = $total_row['total'] ?? 0;
}

// ============================================
// GET RECENT LISTINGS BY CATEGORY (for preview)
// ============================================
$recent_listings_by_category = [];
foreach ($categories as $category) {
    $recent_query = "SELECT l.*, u.username, u.store_name 
                     FROM listings l 
                     LEFT JOIN users u ON l.user_id = u.id 
                     WHERE l.category = ? AND l.status = 'active' 
                     ORDER BY l.created_at DESC LIMIT 3";
    $stmt = $conn->prepare($recent_query);
    if ($stmt) {
        $stmt->bind_param("s", $category['name']);
        $stmt->execute();
        $result = $stmt->get_result();
        $listings = [];
        while ($row = $result->fetch_assoc()) {
            $listings[] = $row;
        }
        if (!empty($listings)) {
            $recent_listings_by_category[$category['name']] = $listings;
        }
        $stmt->close();
    }
}

// ============================================
// HELPER FUNCTIONS - DIFFERENT NAMES TO AVOID CONFLICT
// ============================================
function getCategoryIconFromDB($category) {
    // If icon is set in database, use it
    if (!empty($category['icon'])) {
        return $category['icon'];
    }
    
    // Fallback icons based on category name
    $icons = [
        'Real Estate' => 'fa-building',
        'Lands' => 'fa-tree',
        'Apartments' => 'fa-home',
        'Vehicles' => 'fa-car',
        'Electronics' => 'fa-laptop',
        'Furniture' => 'fa-couch',
        'Services' => 'fa-tools',
        'Clothing' => 'fa-tshirt',
        'Food' => 'fa-utensils',
        'Books' => 'fa-book',
        'Toys' => 'fa-child',
        'Sports' => 'fa-football-ball',
        'Health' => 'fa-heartbeat',
        'Education' => 'fa-graduation-cap',
        'Jobs' => 'fa-briefcase',
        'Properties' => 'fa-building',
        'Lands & Plots' => 'fa-tree',
        'Apartments & Rentals' => 'fa-home',
        'Cars & Vehicles' => 'fa-car',
        'Phones & Electronics' => 'fa-laptop',
        'Furniture & Appliances' => 'fa-couch'
    ];
    return $icons[$category['name']] ?? 'fa-tag';
}

function getCategoryColorByName($name) {
    $colors = [
        'Real Estate' => '#2ecc71',
        'Lands' => '#27ae60',
        'Apartments' => '#3498db',
        'Vehicles' => '#e67e22',
        'Electronics' => '#9b59b6',
        'Furniture' => '#e74c3c',
        'Services' => '#f1c40f',
        'Clothing' => '#1abc9c',
        'Food' => '#e74c3c',
        'Books' => '#2c3e50',
        'Toys' => '#f39c12',
        'Sports' => '#2ecc71',
        'Health' => '#e74c3c',
        'Education' => '#3498db',
        'Jobs' => '#8e44ad'
    ];
    return $colors[$name] ?? '#ff7b00';
}

// Get cart count
$cartCount = 0;
if ($isLoggedIn && isset($_SESSION['user_id'])) {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Browse all categories on Ebonyi Market Hub">
    <meta name="theme-color" content="#ff7b00">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <style>
        :root {
            --primary: #ff7b00;
            --primary-dark: #e66a00;
            --secondary: #2c3e50;
            --light: #f8f9fa;
            --dark: #343a40;
            --gray: #6c757d;
            --white: #ffffff;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.05);
            --shadow-md: 0 5px 15px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 25px rgba(0,0,0,0.15);
            --border-radius: 12px;
            --border-radius-sm: 8px;
            --transition: all 0.3s ease;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background-color: #f5f7fa; color: var(--dark); font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; }

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
            padding: 0.75rem 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .logo img { height: clamp(35px, 6vw, 45px); width: auto; }
        .nav-actions { display: flex; align-items: center; gap: 0.6rem; flex-shrink: 0; }
        .search-box {
            display: flex;
            align-items: center;
            background: var(--light);
            border-radius: 50px;
            padding: 0.4rem 0.75rem;
            border: 1px solid transparent;
            transition: var(--transition);
        }
        .search-box:focus-within {
            border-color: var(--primary);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(255,123,0,0.1);
        }
        .search-box input {
            border: none;
            background: transparent;
            outline: none;
            padding: 0.3rem 0.5rem;
            width: 140px;
            font-size: 0.9rem;
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
            color: white;
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

        .page-header {
            background: linear-gradient(135deg, var(--secondary) 0%, #1a2632 100%);
            color: var(--white);
            padding: 3rem 5%;
            text-align: center;
        }
        .page-header h1 { font-weight: 700; font-size: clamp(2rem, 4vw, 3rem); margin-bottom: 0.5rem; }
        .page-header p { font-size: clamp(1rem, 2vw, 1.2rem); opacity: 0.9; max-width: 700px; margin: 0 auto; }
        .page-header .stat-badge {
            display: inline-block;
            background: rgba(255,255,255,0.15);
            padding: 0.5rem 1.5rem;
            border-radius: 50px;
            margin-top: 1rem;
            font-size: 0.9rem;
        }

        .categories-section { padding: 3rem 5%; max-width: 1440px; margin: 0 auto; }
        .categories-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 2rem;
        }
        .category-card {
            background: var(--white);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            cursor: pointer;
            text-decoration: none;
            color: var(--dark);
            display: block;
        }
        .category-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
            color: var(--dark);
        }
        .category-card-header {
            padding: 1.5rem 1.5rem 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .category-icon-wrapper {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            color: white;
            flex-shrink: 0;
        }
        .category-card-header .category-info { flex: 1; }
        .category-card-header .category-info h3 { margin: 0; font-size: 1.1rem; font-weight: 600; }
        .category-card-header .category-info .listing-count { font-size: 0.85rem; color: var(--gray); }
        .category-card-body { padding: 0 1.5rem 1rem; }
        .category-card-body p { color: var(--gray); font-size: 0.9rem; margin: 0; line-height: 1.5; }
        .category-card-footer {
            padding: 0.75rem 1.5rem 1.5rem;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .category-card-footer .recent-items { font-size: 0.8rem; color: var(--gray); }
        .category-card-footer .recent-items img {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--white);
            margin-left: -8px;
        }
        .category-card-footer .recent-items img:first-child { margin-left: 0; }
        .category-card-footer .browse-link {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--primary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            transition: var(--transition);
        }
        .category-card-footer .browse-link:hover { gap: 0.6rem; color: var(--primary-dark); }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            grid-column: 1 / -1;
        }
        .empty-state i { font-size: 4rem; color: #dee2e6; margin-bottom: 1rem; }
        .empty-state h3 { color: var(--secondary); margin-bottom: 0.5rem; }
        .empty-state p { color: var(--gray); max-width: 500px; margin: 0 auto 1.5rem; }

        .footer {
            background: var(--dark);
            color: var(--white);
            padding: 3rem 5% 1.5rem;
            margin-top: 2rem;
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
        .footer-links { list-style: none; padding: 0; }
        .footer-links li { margin-bottom: 0.75rem; }
        .footer-links a {
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }
        .footer-links a:hover { color: var(--primary); transform: translateX(5px); }
        .footer-bottom {
            text-align: center;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255,255,255,0.1);
            font-size: 0.85rem;
            color: rgba(255,255,255,0.6);
        }

        .alert {
            border-radius: var(--border-radius);
            border: none;
            box-shadow: var(--shadow-lg);
            animation: slideDown 0.5s ease-in-out;
            z-index: 9999 !important;
        }
        @keyframes slideDown {
            from { transform: translateY(-100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @media (max-width: 992px) {
            .top-info-bar { flex-direction: column; text-align: center; padding: 0.5rem; }
            .info-items { justify-content: center; width: 100%; }
            .nav-actions .search-box input { width: 100px; }
            .categories-grid { grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 1.5rem; }
        }

        @media (max-width: 768px) {
            .categories-grid { grid-template-columns: 1fr 1fr; gap: 1rem; }
            .category-card-header { padding: 1rem; }
            .category-icon-wrapper { width: 45px; height: 45px; font-size: 1.2rem; }
            .category-card-body { padding: 0 1rem 0.75rem; }
            .category-card-footer { padding: 0.75rem 1rem 1rem; flex-direction: column; gap: 0.5rem; align-items: flex-start; }
            .page-header { padding: 2rem 1rem; }
            .navbar { flex-wrap: wrap; gap: 0.5rem; }
            .nav-actions { width: 100%; justify-content: center; flex-wrap: wrap; }
        }

        @media (max-width: 576px) {
            .categories-grid { grid-template-columns: 1fr; }
            .top-info-bar { font-size: 0.75rem; }
            .info-items { gap: 0.75rem; }
            .info-items li a { font-size: 0.75rem; padding: 0.2rem 0.4rem; }
            .footer-grid { grid-template-columns: 1fr; text-align: center; }
            .footer-col h3::after { left: 50%; transform: translateX(-50%); }
        }
    </style>

    <title>All Categories - Ebonyi Market Hub</title>
</head>
<body>

    <?php if (isset($_SESSION['alert'])): ?>
        <div id="alertBox" class="alert alert-<?php echo $_SESSION['alert']['type']; ?> alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-4 z-3" style="max-width: 90%; width: 500px;" role="alert">
            <div class="d-flex align-items-center">
                <i class="fas <?php echo $_SESSION['alert']['type'] == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> me-2"></i>
                <span><?php echo $_SESSION['alert']['message']; ?></span>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <!-- Top Info Bar -->
    <div class="top-info-bar">
        <ul class="info-items">
            <li><a href="mailto:info@ebonyipropertyhub.com"><i class="fas fa-envelope"></i> <span class="d-none d-sm-inline">info@ebonyipropertyhub.com</span></a></li>
            <li><a href="tel:+2347039312901"><i class="fas fa-phone"></i> <span class="d-none d-sm-inline">(+234) 703-931-2901</span></a></li>
        </ul>
        <ul class="info-items">
            <?php if ($isLoggedIn): ?>
                <li><a href="dashboard.php"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($_SESSION['username']); ?></a></li>
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            <?php else: ?>
                <li><a href="login.php"><i class="fas fa-sign-in-alt"></i> Login</a></li>
                <li><a href="#" id="openSignup"><i class="fas fa-user-plus"></i> Register</a></li>
            <?php endif; ?>
            <li><a href="#" data-bs-toggle="modal" data-bs-target="#postAdModal"><i class="fas fa-plus-circle"></i> Post Ad</a></li>
        </ul>
    </div>

    <!-- Navigation -->
    <nav class="navbar">
        <div class="logo">
            <a href="index.php">
                <img src="img/log.png" alt="Ebonyi Market Hub" loading="lazy">
            </a>
        </div>
        <div class="nav-actions">
            <form action="search.php" method="GET" class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" name="q" placeholder="Search..." aria-label="Search">
            </form>
            <a href="cart_items.php" class="btn btn-outline btn-sm position-relative" title="Cart">
                <i class="fas fa-shopping-cart"></i>
                <?php if ($isLoggedIn && $cartCount > 0): ?>
                    <span class="badge bg-danger position-absolute top-0 start-100 translate-middle rounded-pill" style="font-size: 0.6rem; min-width: 18px;">
                        <?php echo $cartCount; ?>
                    </span>
                <?php endif; ?>
            </a>
            <a href="index.php" class="btn btn-outline btn-sm">
                <i class="fas fa-arrow-left"></i>
                <span class="d-none d-xl-inline">Back</span>
            </a>
            <?php if (!$isLoggedIn): ?>
                <a href="login.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-sign-in-alt"></i>
                    <span class="d-none d-xl-inline">Login</span>
                </a>
            <?php endif; ?>
        </div>
    </nav>

    <!-- Page Header -->
    <section class="page-header">
        <h1><i class="fas fa-th-large me-3"></i>All Categories</h1>
        <p>Browse all categories available on Ebonyi Market Hub. Find what you're looking for!</p>
        <div class="stat-badge">
            <i class="fas fa-tags me-2"></i>
            <?php echo count($categories); ?> Categories • <?php echo $total_listings; ?> Active Listings
        </div>
    </section>

    <!-- Categories Section -->
    <section class="categories-section">
        <?php if (!empty($categories)): ?>
            <div class="categories-grid">
                <?php foreach ($categories as $category): 
                    $count = $category_counts[$category['name']] ?? 0;
                    // Get icon from database or fallback - using the new function name
                    $icon = !empty($category['icon']) ? $category['icon'] : getCategoryIconFromDB($category);
                    $color = getCategoryColorByName($category['name']);
                    $recent = $recent_listings_by_category[$category['name']] ?? [];
                ?>
                    <a href="category.php?name=<?php echo urlencode($category['name']); ?>" class="category-card">
                        <div class="category-card-header">
                            <div class="category-icon-wrapper" style="background: <?php echo $color; ?>;">
                                <i class="fas <?php echo htmlspecialchars($icon); ?>"></i>
                            </div>
                            <div class="category-info">
                                <h3><?php echo htmlspecialchars($category['name']); ?></h3>
                                <div class="listing-count">
                                    <i class="fas fa-box"></i> <?php echo $count; ?> listing<?php echo $count != 1 ? 's' : ''; ?>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (!empty($category['description'])): ?>
                            <div class="category-card-body">
                                <p><?php echo htmlspecialchars(substr($category['description'], 0, 80)) . (strlen($category['description']) > 80 ? '...' : ''); ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <div class="category-card-footer">
                            <div class="recent-items">
                                <?php if (!empty($recent)): ?>
                                    <?php foreach (array_slice($recent, 0, 3) as $item): ?>
                                        <img src="<?php echo !empty($item['image']) ? htmlspecialchars($item['image']) : 'https://ui-avatars.com/api/?name=' . urlencode($item['title'] ?? 'Item') . '&background=ff7b00&color=fff&size=28'; ?>" 
                                             alt="<?php echo htmlspecialchars($item['title'] ?? 'Item'); ?>" 
                                             title="<?php echo htmlspecialchars($item['title'] ?? 'Item'); ?>">
                                    <?php endforeach; ?>
                                    <?php if (count($recent) > 3): ?>
                                        <span style="font-size: 0.8rem; color: var(--gray); margin-left: 4px;">+<?php echo count($recent) - 3; ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="font-size: 0.8rem; color: var(--gray);">No recent listings</span>
                                <?php endif; ?>
                            </div>
                            <span class="browse-link">
                                Browse <i class="fas fa-arrow-right"></i>
                            </span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-folder-open"></i>
                <h3>No Categories Found</h3>
                <p>There are currently no categories available. Please check back later.</p>
                <a href="index.php" class="btn btn-primary">
                    <i class="fas fa-home me-2"></i>Return Home
                </a>
            </div>
        <?php endif; ?>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-grid">
            <div class="footer-col">
                <h3>Ebonyi Market Hub</h3>
                <p style="color: rgba(255,255,255,0.7);">Your trusted marketplace for buying, selling, and renting properties, lands, apartments, and used items in Ebonyi State.</p>
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

    <script>
        setTimeout(function() {
            const alertBox = document.getElementById("alertBox");
            if (alertBox) {
                alertBox.style.transition = "opacity 0.5s ease";
                alertBox.style.opacity = "0";
                setTimeout(() => { if (alertBox.parentNode) alertBox.remove(); }, 500);
            }
        }, 5000);
    </script>

</body>
</html>

<?php $conn->close(); ?>