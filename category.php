<?php
session_start();
require 'config.php';

// Get category name from URL
$category_name = isset($_GET['name']) ? trim($_GET['name']) : '';
$category_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// If no category is specified, redirect to all categories page (only once)
if (empty($category_name) && $category_id == 0) {
    header('Location: all-categories.php');
    exit();
}

// If we have an ID but no name, get the name from the ID
if (empty($category_name) && $category_id > 0) {
    $stmt = $conn->prepare("SELECT name FROM categories WHERE id = ? AND status = 'active'");
    $stmt->bind_param("i", $category_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $category_name = $row['name'];
    }
    $stmt->close();
}

// If still no category name, redirect to all categories
if (empty($category_name)) {
    header('Location: all-categories.php');
    exit();
}

// Get category details
$category = null;
$stmt = $conn->prepare("SELECT * FROM categories WHERE name = ? AND status = 'active'");
$stmt->bind_param("s", $category_name);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $category = $row;
}
$stmt->close();

// If category not found, redirect to all categories
if (!$category) {
    $_SESSION['alert'] = ['type' => 'danger', 'message' => 'Category not found.'];
    header('Location: all-categories.php');
    exit();
}

// Get listings in this category
$listings = [];
$listings_query = "SELECT l.*, u.username, u.store_name, u.store_verified, u.id as user_id, u.avatar_url 
                   FROM listings l 
                   LEFT JOIN users u ON l.user_id = u.id 
                   WHERE l.category = ? AND l.status = 'active' 
                   ORDER BY l.created_at DESC";
$stmt = $conn->prepare($listings_query);
$stmt->bind_param("s", $category_name);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $listings[] = $row;
}
$stmt->close();

// Count listings
$count = count($listings);

// Check if user is logged in
$isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

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

// Helper function for category icon - DIFFERENT NAME to avoid conflict
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
        'Jobs' => 'fa-briefcase'
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($category_name); ?> - Ebonyi Market Hub</title>
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
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f7fa; font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        
        .top-info-bar {
            background: var(--secondary);
            color: white;
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
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.5rem;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-size: 0.85rem;
        }
        .info-items li a:hover {
            background: rgba(255,255,255,0.1);
            color: #ff7b00;
        }
        
        .navbar {
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            position: sticky;
            top: 0;
            z-index: 1030;
            padding: 0.75rem 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .logo img { height: 40px; }
        .nav-actions { display: flex; align-items: center; gap: 0.6rem; }
        .search-box {
            display: flex;
            align-items: center;
            background: var(--light);
            border-radius: 50px;
            padding: 0.4rem 0.75rem;
            border: 1px solid transparent;
            transition: all 0.3s ease;
        }
        .search-box:focus-within {
            border-color: var(--primary);
            background: white;
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
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            text-decoration: none;
        }
        .btn-primary {
            background: var(--primary);
            color: white;
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
            color: white;
            transform: translateY(-2px);
        }
        
        .page-header {
            background: linear-gradient(135deg, var(--secondary) 0%, #1a2632 100%);
            color: white;
            padding: 3rem 5%;
            text-align: center;
        }
        .page-header h1 { font-weight: 700; font-size: clamp(2rem, 4vw, 3rem); }
        .page-header .count-badge {
            background: rgba(255,255,255,0.15);
            padding: 0.5rem 1.5rem;
            border-radius: 50px;
            display: inline-block;
            margin-top: 1rem;
        }
        
        .listings-section { padding: 3rem 5%; max-width: 1440px; margin: 0 auto; }
        .listings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 2rem;
        }
        .listing-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
        }
        .listing-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }
        .listing-img {
            height: 200px;
            overflow: hidden;
            position: relative;
        }
        .listing-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .listing-img .badge-category {
            position: absolute;
            top: 1rem;
            left: 1rem;
            background: var(--primary);
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .listing-content { padding: 1.5rem; }
        .listing-content h3 {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }
        .listing-content .price {
            font-weight: 700;
            color: var(--primary);
            font-size: 1.2rem;
        }
        .listing-content .meta {
            color: var(--gray);
            font-size: 0.85rem;
        }
        
        .empty-state {
            text-align: center;
            padding: 4rem;
            grid-column: 1 / -1;
        }
        .empty-state i { font-size: 4rem; color: #dee2e6; margin-bottom: 1rem; }
        .empty-state h3 { color: var(--secondary); margin-bottom: 0.5rem; }
        .empty-state p { color: var(--gray); }
        
        .footer {
            background: var(--dark);
            color: white;
            padding: 3rem 5% 1.5rem;
            margin-top: 2rem;
        }
        .footer-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }
        .footer-bottom {
            text-align: center;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255,255,255,0.1);
            font-size: 0.85rem;
            color: rgba(255,255,255,0.6);
        }
        .footer a { color: rgba(255,255,255,0.7); text-decoration: none; }
        .footer a:hover { color: var(--primary); }
        .footer ul { list-style: none; padding: 0; }
        .footer ul li { margin-bottom: 0.75rem; }
        
        .alert {
            border-radius: 12px;
            border: none;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            animation: slideDown 0.5s ease-in-out;
            z-index: 9999 !important;
        }
        @keyframes slideDown {
            from { transform: translateY(-100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        @media (max-width: 768px) {
            .listings-grid { grid-template-columns: 1fr 1fr; gap: 1rem; }
            .top-info-bar { flex-direction: column; text-align: center; }
            .info-items { justify-content: center; }
            .nav-actions .search-box input { width: 100px; }
        }
        @media (max-width: 576px) {
            .listings-grid { grid-template-columns: 1fr; }
            .navbar { flex-wrap: wrap; gap: 0.5rem; }
            .nav-actions { width: 100%; justify-content: center; flex-wrap: wrap; }
        }
    </style>
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
            <a href="all-categories.php" class="btn btn-outline btn-sm">
                <i class="fas fa-arrow-left"></i>
                <span class="d-none d-md-inline">All Categories</span>
            </a>
            <?php if (!$isLoggedIn): ?>
                <a href="login.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-sign-in-alt"></i>
                    <span class="d-none d-md-inline">Login</span>
                </a>
            <?php endif; ?>
        </div>
    </nav>

    <!-- Page Header -->
    <section class="page-header">
        <h1>
            <i class="fas <?php echo htmlspecialchars(getCategoryIconFromDB($category)); ?> me-3"></i>
            <?php echo htmlspecialchars($category_name); ?>
        </h1>
        <?php if (!empty($category['description'])): ?>
            <p style="max-width: 700px; margin: 0.5rem auto; opacity: 0.9;"><?php echo htmlspecialchars($category['description']); ?></p>
        <?php endif; ?>
        <div class="count-badge">
            <i class="fas fa-box me-2"></i><?php echo $count; ?> listing<?php echo $count != 1 ? 's' : ''; ?>
        </div>
    </section>

    <!-- Listings Section -->
    <section class="listings-section">
        <?php if (!empty($listings)): ?>
            <div class="listings-grid">
                <?php foreach ($listings as $listing): ?>
                    <div class="listing-card">
                        <div class="listing-img">
                            <?php if (!empty($listing['image'])): ?>
                                <img src="<?php echo htmlspecialchars($listing['image']); ?>" alt="<?php echo htmlspecialchars($listing['title']); ?>" loading="lazy">
                            <?php else: ?>
                                <img src="https://images.unsplash.com/photo-1560518883-ce09059eeffa?ixlib=rb-4.0.3&auto=format&fit=crop&w=1000&q=80" alt="Default" loading="lazy">
                            <?php endif; ?>
                            <span class="badge-category"><?php echo htmlspecialchars($listing['category']); ?></span>
                        </div>
                        <div class="listing-content">
                            <h3><?php echo htmlspecialchars(substr($listing['title'], 0, 50)) . (strlen($listing['title']) > 50 ? '...' : ''); ?></h3>
                            <div class="meta">
                                <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($listing['location']); ?>
                                <?php if (!empty($listing['bedrooms'])): ?>
                                    <span class="ms-2"><i class="fas fa-bed"></i> <?php echo $listing['bedrooms']; ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($listing['price'])): ?>
                                <div class="price mt-2">₦<?php echo number_format($listing['price']); ?></div>
                            <?php endif; ?>
                            <div style="margin-top: 1rem; display: flex; gap: 0.5rem;">
                                <a href="listing_details.php?id=<?php echo $listing['id']; ?>" class="btn-primary" style="flex: 1; justify-content: center; font-size: 0.85rem; padding: 0.4rem 0.8rem; text-decoration: none;">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <?php if ($isLoggedIn): ?>
                                    <a href="cart_items.php?action=add&listing_id=<?php echo $listing['id']; ?>&quantity=1" class="btn-outline" style="flex: 1; justify-content: center; font-size: 0.85rem; padding: 0.4rem 0.8rem; text-decoration: none;">
                                        <i class="fas fa-shopping-cart"></i> Add
                                    </a>
                                <?php else: ?>
                                    <button class="btn-outline" style="flex: 1; justify-content: center; font-size: 0.85rem; padding: 0.4rem 0.8rem; cursor: pointer;" onclick="alert('Please login to add items to cart.');">
                                        <i class="fas fa-shopping-cart"></i> Add
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-box-open"></i>
                <h3>No listings found</h3>
                <p>There are currently no active listings in this category.</p>
                <a href="all-categories.php" class="btn-primary" style="text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1.25rem; border-radius: 50px;">
                    <i class="fas fa-arrow-left"></i> Browse Other Categories
                </a>
            </div>
        <?php endif; ?>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-grid">
            <div>
                <h3>Ebonyi Market Hub</h3>
                <p style="color: rgba(255,255,255,0.7);">Your trusted marketplace for buying, selling, and renting properties, lands, apartments, and used items in Ebonyi State.</p>
            </div>
            <div>
                <h3>Quick Links</h3>
                <ul>
                    <li><a href="index.php"><i class="fas fa-chevron-right me-2"></i>Home</a></li>
                    <li><a href="all-categories.php"><i class="fas fa-chevron-right me-2"></i>Categories</a></li>
                    <li><a href="stores.php"><i class="fas fa-chevron-right me-2"></i>Stores</a></li>
                    <li><a href="rentals.php"><i class="fas fa-chevron-right me-2"></i>Rentals</a></li>
                </ul>
            </div>
            <div>
                <h3>Contact</h3>
                <ul style="color: rgba(255,255,255,0.7);">
                    <li><i class="fas fa-map-marker-alt me-2"></i> Abakaliki, Ebonyi State</li>
                    <li><i class="fas fa-phone me-2"></i> (+234) 703 931 2901</li>
                    <li><i class="fas fa-envelope me-2"></i> info@ebonyimarkethub.com</li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; <?php echo date('Y'); ?> Ebonyi Market Hub. All rights reserved.</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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