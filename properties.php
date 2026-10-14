<?php
session_start();
require 'config.php';

// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(0);

// Check if user is logged in
$isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

// Handle search and filtering
$search_query = isset($_GET['q']) ? trim($_GET['q']) : '';
$min_price = isset($_GET['min_price']) ? floatval($_GET['min_price']) : 0;
$max_price = isset($_GET['max_price']) ? floatval($_GET['max_price']) : 0;
$location = isset($_GET['location']) ? trim($_GET['location']) : '';
$property_type = isset($_GET['type']) ? trim($_GET['type']) : '';
$bedrooms = isset($_GET['bedrooms']) ? intval($_GET['bedrooms']) : 0;
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'newest';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$items_per_page = 12;
$offset = ($page - 1) * $items_per_page;

// Build query for properties (category = 'property', 'land', or 'apartment')
$query = "SELECT l.*, 
          (SELECT image_url FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) as primary_image
          FROM listings l 
          WHERE l.status = 'active' AND l.category IN ('property', 'land', 'apartment')";
$params = [];
$types = '';

// Add search query filter
if (!empty($search_query)) {
    $query .= " AND (l.title LIKE ? OR l.description LIKE ? OR l.location LIKE ?)";
    $search_param = "%$search_query%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'sss';
}

// Add price filters
if ($min_price > 0) {
    $query .= " AND l.price >= ?";
    $params[] = $min_price;
    $types .= 'd';
}

if ($max_price > 0) {
    $query .= " AND l.price <= ?";
    $params[] = $max_price;
    $types .= 'd';
}

// Add location filter
if (!empty($location)) {
    $query .= " AND l.location LIKE ?";
    $params[] = "%$location%";
    $types .= 's';
}

// Add property type filter
if (!empty($property_type)) {
    $query .= " AND l.category = ?";
    $params[] = $property_type;
    $types .= 's';
}

// Add bedrooms filter
if ($bedrooms > 0) {
    $query .= " AND l.bedrooms >= ?";
    $params[] = $bedrooms;
    $types .= 'i';
}

// Add sorting
switch ($sort_by) {
    case 'price_low':
        $query .= " ORDER BY l.price ASC";
        break;
    case 'price_high':
        $query .= " ORDER BY l.price DESC";
        break;
    case 'oldest':
        $query .= " ORDER BY l.created_at ASC";
        break;
    default:
        $query .= " ORDER BY l.created_at DESC";
        break;
}

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM listings l WHERE l.status = 'active' AND l.category IN ('property', 'land', 'apartment')";
// Add the same filters to count query
$count_params = [];
$count_types = '';
if (!empty($search_query)) {
    $count_query .= " AND (l.title LIKE ? OR l.description LIKE ? OR l.location LIKE ?)";
    $count_params[] = $search_param;
    $count_params[] = $search_param;
    $count_params[] = $search_param;
    $count_types .= 'sss';
}
if ($min_price > 0) {
    $count_query .= " AND l.price >= ?";
    $count_params[] = $min_price;
    $count_types .= 'd';
}
if ($max_price > 0) {
    $count_query .= " AND l.price <= ?";
    $count_params[] = $max_price;
    $count_types .= 'd';
}
if (!empty($location)) {
    $count_query .= " AND l.location LIKE ?";
    $count_params[] = "%$location%";
    $count_types .= 's';
}
if (!empty($property_type)) {
    $count_query .= " AND l.category = ?";
    $count_params[] = $property_type;
    $count_types .= 's';
}
if ($bedrooms > 0) {
    $count_query .= " AND l.bedrooms >= ?";
    $count_params[] = $bedrooms;
    $count_types .= 'i';
}

$count_stmt = $conn->prepare($count_query);
if (!empty($count_params)) {
    $count_stmt->bind_param($count_types, ...$count_params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_count = $count_result->fetch_assoc()['total'];
$count_stmt->close();

// Add pagination to main query
$query .= " LIMIT ? OFFSET ?";
$params[] = $items_per_page;
$params[] = $offset;
$types .= 'ii';

// Prepare and execute query
$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$properties_result = $stmt->get_result();

$properties = [];
if ($properties_result) {
    while ($row = $properties_result->fetch_assoc()) {
        $properties[] = $row;
    }
}
$stmt->close();

// Calculate total pages
$total_pages = ceil($total_count / $items_per_page);

// Get unique locations for filter
$locations_query = "SELECT DISTINCT location FROM listings WHERE status = 'active' AND category IN ('property', 'land', 'apartment') AND location IS NOT NULL AND location != '' ORDER BY location ASC";
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
    <title>Properties - Ebonyi Property Hub</title>
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

        .property-card {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.3s, box-shadow 0.3s;
            margin-bottom: 30px;
            height: 100%;
        }

        .property-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }

        .property-image {
            height: 220px;
            overflow: hidden;
            position: relative;
            background-color: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .property-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s;
        }

        .property-image .no-image {
            text-align: center;
            color: #999;
            font-size: 0.9rem;
        }

        .property-image .no-image i {
            font-size: 2rem;
            margin-bottom: 5px;
            display: block;
        }

        .property-card:hover .property-image img {
            transform: scale(1.05);
        }

        .property-badge {
            position: absolute;
            top: 15px;
            left: 15px;
            background: var(--primary);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            z-index: 2;
        }

        .property-content {
            padding: 20px;
        }

        .property-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--secondary);
        }

        .property-title a {
            color: inherit;
            text-decoration: none;
            transition: color 0.3s;
        }

        .property-title a:hover {
            color: var(--primary);
        }

        .property-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            color: var(--gray);
            font-size: 0.85rem;
            margin-bottom: 10px;
        }

        .property-meta i {
            color: var(--primary);
            margin-right: 3px;
        }

        .property-price {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary);
            margin: 10px 0;
        }

        .property-features {
            display: flex;
            gap: 15px;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #eee;
        }

        .property-features span {
            font-size: 0.85rem;
            color: var(--gray);
        }

        .property-features i {
            color: var(--primary);
            margin-right: 5px;
        }

        .btn-view {
            width: 100%;
            margin-top: 15px;
            padding: 8px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 5px;
            font-weight: 600;
            transition: background 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn-view:hover {
            background: var(--primary-dark);
            color: white;
        }

        .no-properties {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 10px;
        }

        .no-properties i {
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

        @media (max-width: 768px) {
            .filter-sidebar {
                margin-bottom: 20px;
                position: static;
            }
            
            .property-image {
                height: 180px;
            }
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <!-- Page Header -->
    <div class="page-header">
        <div class="container">
            <div class="row">
                <div class="col-md-8">
                    <h1><i class="fas fa-home me-2"></i>Properties for Sale & Rent</h1>
                    <p>Discover the best properties, lands, and apartments in Ebonyi State</p>
                </div>
                <div class="col-md-4">
                    <form action="" method="GET" class="d-flex">
                        <input type="text" name="q" class="form-control me-2" placeholder="Search properties..." value="<?php echo htmlspecialchars($search_query); ?>">
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
                    <h5 className="filter-title">Filter Properties</h5>
                    
                    <form action="" method="GET" id="filterForm">
                        <?php if (!empty($search_query)): ?>
                            <input type="hidden" name="q" value="<?php echo htmlspecialchars($search_query); ?>">
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label class="form-label">Property Type</label>
                            <select name="type" class="form-select" onchange="this.form.submit()">
                                <option value="">All Types</option>
                                <option value="property" <?php echo $property_type == 'property' ? 'selected' : ''; ?>>Property</option>
                                <option value="land" <?php echo $property_type == 'land' ? 'selected' : ''; ?>>Land</option>
                                <option value="apartment" <?php echo $property_type == 'apartment' ? 'selected' : ''; ?>>Apartment</option>
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
                            <label class="form-label">Bedrooms</label>
                            <select name="bedrooms" class="form-select" onchange="this.form.submit()">
                                <option value="0">Any</option>
                                <option value="1" <?php echo $bedrooms == 1 ? 'selected' : ''; ?>>1+ Bedroom</option>
                                <option value="2" <?php echo $bedrooms == 2 ? 'selected' : ''; ?>>2+ Bedrooms</option>
                                <option value="3" <?php echo $bedrooms == 3 ? 'selected' : ''; ?>>3+ Bedrooms</option>
                                <option value="4" <?php echo $bedrooms == 4 ? 'selected' : ''; ?>>4+ Bedrooms</option>
                                <option value="5" <?php echo $bedrooms == 5 ? 'selected' : ''; ?>>5+ Bedrooms</option>
                            </select>
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
                        <?php if ($min_price > 0 || $max_price > 0 || !empty($location) || !empty($property_type) || $bedrooms > 0): ?>
                            <a href="properties.php" class="btn btn-outline-secondary w-100 mt-2">Clear Filters</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            
            <!-- Properties Grid -->
            <div class="col-lg-9">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4 class="mb-0"><?php echo $total_count; ?> Properties Found</h4>
                </div>
                
                <?php if (!empty($properties)): ?>
                    <div class="row">
                        <?php foreach ($properties as $property): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="property-card">
                                    <div class="property-image">
                                        <?php 
                                        // Check if primary_image exists from the subquery
                                        $image_url = !empty($property['primary_image']) ? $property['primary_image'] : '';
                                        
                                        // If no primary_image, check the main image_url column
                                        if (empty($image_url) && !empty($property['image_url'])) {
                                            $image_url = $property['image_url'];
                                        }
                                        
                                        // If there's an image in database, show it
                                        if (!empty($image_url)): 
                                        ?>
                                            <img src="<?php echo htmlspecialchars($image_url); ?>" alt="<?php echo htmlspecialchars($property['title']); ?>" loading="lazy">
                                        <?php else: ?>
                                            <!-- Show "No image available" instead of placeholder -->
                                            <div class="no-image d-flex flex-column align-items-center justify-content-center h-100">
                                                <i class="fas fa-image"></i>
                                                <span class="mt-2">No image available</span>
                                            </div>
                                        <?php endif; ?>
                                        <span class="property-badge">
                                            <?php echo ucfirst($property['category']); ?>
                                        </span>
                                    </div>
                                    <div class="property-content">
                                        <h3 class="property-title">
                                            <a href="property_details.php?id=<?php echo $property['id']; ?>">
                                                <?php echo htmlspecialchars(substr($property['title'], 0, 50)); ?>
                                            </a>
                                        </h3>
                                        
                                        <div class="property-meta">
                                            <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($property['location']); ?></span>
                                        </div>
                                        
                                        <div class="property-price">
                                            ₦<?php echo number_format($property['price'], 2); ?>
                                        </div>
                                        
                                        <?php if (!empty($property['bedrooms']) || !empty($property['bathrooms'])): ?>
                                            <div class="property-features">
                                                <?php if (!empty($property['bedrooms'])): ?>
                                                    <span><i class="fas fa-bed"></i> <?php echo $property['bedrooms']; ?> beds</span>
                                                <?php endif; ?>
                                                <?php if (!empty($property['bathrooms'])): ?>
                                                    <span><i class="fas fa-bath"></i> <?php echo $property['bathrooms']; ?> baths</span>
                                                <?php endif; ?>
                                                <?php if (!empty($property['floor_area'])): ?>
                                                    <span><i class="fas fa-ruler-combined"></i> <?php echo $property['floor_area']; ?> sqm</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <a href="property_details.php?id=<?php echo $property['id']; ?>" class="btn-view">
                                            View Details <i class="fas fa-arrow-right ms-2"></i>
                                        </a>
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
                                
                                <?php for ($i = 1; $i <= min(5, $total_pages); $i++): ?>
                                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>&<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $page + 1; ?>&<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next</a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <div class="no-properties">
                        <i class="fas fa-home"></i>
                        <h3>No Properties Found</h3>
                        <p>We couldn't find any properties matching your criteria.</p>
                        <a href="properties.php" class="btn btn-primary">View All Properties</a>
                        <button class="btn btn-outline-primary ms-2" onclick="checkLoginBeforePost('property')">Post a Property</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php include 'footer.php'; ?>
    
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
    </script>
</body>
</html>

<?php $conn->close(); ?>