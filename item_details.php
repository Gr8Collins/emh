<?php
session_start();
require 'config.php';

// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(0);

// Check if user is logged in
$isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

// Get item ID from URL
$item_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($item_id <= 0) {
    header("Location: used_items.php");
    exit();
}

// Fetch item details from database
$query = "SELECT * FROM listings WHERE id = ? AND status = 'active' AND category = 'item'";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $item_id);
$stmt->execute();
$result = $stmt->get_result();
$item = $result->fetch_assoc();
$stmt->close();

// If item not found, redirect
if (!$item) {
    header("Location: used_items.php");
    exit();
}

// ============================================
// CHECK IF THE ITEM BELONGS TO THE LOGGED IN USER
// ============================================
$isOwner = false;
if ($isLoggedIn && isset($_SESSION['user_id']) && isset($item['user_id'])) {
    $isOwner = ($_SESSION['user_id'] == $item['user_id']);
}

// Fetch seller information
$seller = null;
if (!empty($item['user_id'])) {
    $seller_query = "SELECT username, email, phone, first_name, last_name FROM users WHERE id = ?";
    $seller_stmt = $conn->prepare($seller_query);
    $seller_stmt->bind_param("i", $item['user_id']);
    $seller_stmt->execute();
    $seller_result = $seller_stmt->get_result();
    $seller = $seller_result->fetch_assoc();
    $seller_stmt->close();
}

// ============================================
// CHECK IF USER CAME BACK FROM LOGIN WITH add_to_cart
// ============================================
if (isset($_GET['add_to_cart']) && $isLoggedIn) {
    // Check if user is trying to add their own item
    if ($isOwner) {
        $_SESSION['error_message'] = "You cannot add your own item to cart!";
        header("Location: item_details.php?id=" . $item_id);
        exit();
    }
    
    // User just logged in and came back to add to cart
    // Automatically add to cart
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
        $message = "Quantity updated in cart!";
    } else {
        $insert_query = "INSERT INTO cart_items (user_id, listing_id, quantity) VALUES (?, ?, 1)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("ii", $user_id, $item_id);
        $insert_stmt->execute();
        $insert_stmt->close();
        $message = "Item added to cart!";
    }
    $check_stmt->close();
    
    $_SESSION['success_message'] = $message;
    // Redirect to remove the add_to_cart parameter from URL
    header("Location: item_details.php?id=" . $item_id);
    exit();
}

// Handle Add to Cart (for logged-in users)
if (isset($_POST['add_to_cart']) && $isLoggedIn) {
    // Check if user is trying to add their own item
    if ($isOwner) {
        $_SESSION['error_message'] = "You cannot add your own item to cart!";
        header("Location: item_details.php?id=" . $item_id);
        exit();
    }
    
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
        $message = "Quantity updated in cart!";
    } else {
        $insert_query = "INSERT INTO cart_items (user_id, listing_id, quantity) VALUES (?, ?, 1)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("ii", $user_id, $item_id);
        $insert_stmt->execute();
        $insert_stmt->close();
        $message = "Item added to cart!";
    }
    $check_stmt->close();
    
    $_SESSION['success_message'] = $message;
    header("Location: item_details.php?id=" . $item_id);
    exit();
}

// Display success/info/error messages
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($item['title']); ?> - Used Items | Ebonyi Property Hub</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #ff7b00;
            --primary-dark: #e66a00;
            --secondary: #2c3e50;
        }

        body {
            background-color: #f5f5f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .item-header {
            background: linear-gradient(135deg, var(--secondary) 0%, #1a2632 100%);
            color: white;
            padding: 40px 0;
            margin-bottom: 40px;
        }

        .item-gallery {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }

        .main-image {
            width: 100%;
            height: 400px;
            object-fit: cover;
        }

        .thumbnail-images {
            display: flex;
            gap: 10px;
            padding: 15px;
            overflow-x: auto;
        }

        .thumbnail {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 5px;
            cursor: pointer;
            border: 2px solid transparent;
        }

        .thumbnail:hover, .thumbnail.active {
            border-color: var(--primary);
        }

        .item-info {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }

        .item-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--secondary);
            margin-bottom: 15px;
        }

        .item-price {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 20px;
        }

        .condition-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 20px;
        }

        .condition-new { background: #28a745; color: white; }
        .condition-used { background: #ffc107; color: #333; }
        .condition-fair { background: #fd7e14; color: white; }
        .condition-refurbished { background: #17a2b8; color: white; }

        .item-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            padding: 20px 0;
            border-top: 1px solid #eee;
            border-bottom: 1px solid #eee;
            margin-bottom: 20px;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .meta-item i {
            font-size: 1.2rem;
            color: var(--primary);
        }

        .sidebar-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            position: sticky;
            top: 20px;
        }

        .btn-contact {
            width: 100%;
            padding: 12px;
            margin-bottom: 10px;
            border-radius: 5px;
            font-weight: 600;
        }

        .btn-whatsapp {
            background: #25D366;
            color: white;
        }

        .btn-owner {
            background: #6c757d;
            color: white;
            cursor: not-allowed;
            opacity: 0.7;
        }

        .alert {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            animation: slideIn 0.3s ease-out;
        }

        .owner-badge {
            display: inline-block;
            background: #28a745;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 15px;
        }

        @keyframes slideIn {
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
            .main-image {
                height: 250px;
            }
            .item-title {
                font-size: 1.3rem;
            }
            .item-price {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <?php if (isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="item-header">
        <div class="container">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php" class="text-white">Home</a></li>
                    <li class="breadcrumb-item"><a href="used_items.php" class="text-white">Used Items</a></li>
                    <li class="breadcrumb-item active text-white"><?php echo htmlspecialchars(substr($item['title'], 0, 50)); ?></li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="container mb-5">
        <div class="row">
            <div class="col-lg-8">
                <div class="item-gallery">
                    <?php
                    // Get images from database
                    $images = [];
                    if (!empty($item['image'])) {
                        if (strpos($item['image'], '[') === 0) {
                            $decoded = json_decode($item['image'], true);
                            if (is_array($decoded)) {
                                $images = $decoded;
                            } else {
                                $images[] = $item['image'];
                            }
                        } else {
                            $images[] = $item['image'];
                        }
                    }
                    
                    if (empty($images)) {
                        if (!empty($item['image_url'])) {
                            $images[] = $item['image_url'];
                        } elseif (!empty($item['images'])) {
                            $decoded = json_decode($item['images'], true);
                            if (is_array($decoded)) {
                                $images = $decoded;
                            }
                        }
                    }
                    
                    if (empty($images)) {
                        $default_images = [
                            'electronics' => 'https://images.unsplash.com/photo-1498049794561-7780e7231661?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80',
                            'vehicle' => 'https://images.unsplash.com/photo-1492144534655-ae79c964c9d7?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80',
                            'furniture' => 'https://images.unsplash.com/photo-1555041469-a586c61ea9bc?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80'
                        ];
                        $images[] = $default_images[$item['subcategory'] ?? ''] ?? 'https://images.unsplash.com/photo-1563013544-824ae1b704d3?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80';
                    }
                    ?>
                    
                    <img id="mainImage" src="<?php echo htmlspecialchars($images[0]); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>" class="main-image">
                    
                    <?php if (count($images) > 1): ?>
                        <div class="thumbnail-images">
                            <?php foreach ($images as $index => $img): ?>
                                <img src="<?php echo htmlspecialchars($img); ?>" class="thumbnail <?php echo $index === 0 ? 'active' : ''; ?>" 
                                     onclick="changeImage(this.src, this)">
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="item-info">
                    <?php if ($isOwner): ?>
                        <span class="owner-badge"><i class="fas fa-user-check me-1"></i> Your Listing</span>
                    <?php endif; ?>
                    
                    <h1 class="item-title"><?php echo htmlspecialchars($item['title']); ?></h1>
                    <div class="item-price">₦<?php echo number_format($item['price'], 2); ?></div>
                    
                    <?php if (!empty($item['condition'])): ?>
                        <span class="condition-badge condition-<?php echo $item['condition']; ?>">
                            <?php echo ucfirst($item['condition']); ?> Condition
                        </span>
                    <?php endif; ?>
                    
                    <div class="item-meta">
                        <div class="meta-item">
                            <i class="fas fa-map-marker-alt"></i>
                            <div>
                                <span>Location</span><br>
                                <strong><?php echo htmlspecialchars($item['location']); ?></strong>
                            </div>
                        </div>
                        
                        <?php if (!empty($item['subcategory'])): ?>
                            <div class="meta-item">
                                <i class="fas fa-tag"></i>
                                <div>
                                    <span>Category</span><br>
                                    <strong><?php echo ucfirst(htmlspecialchars($item['subcategory'])); ?></strong>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($item['brand'])): ?>
                            <div class="meta-item">
                                <i class="fas fa-building"></i>
                                <div>
                                    <span>Brand</span><br>
                                    <strong><?php echo htmlspecialchars($item['brand']); ?></strong>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="property-description">
                        <h4>Description</h4>
                        <p><?php echo nl2br(htmlspecialchars($item['description'] ?? 'No description available.')); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4">
                <div class="sidebar-card">
                    <h5 class="mb-3">Seller Information</h5>
                    <div class="text-center mb-3">
                        <i class="fas fa-user-circle fa-4x text-primary"></i>
                        <h6 class="mt-2"><?php echo htmlspecialchars($seller['first_name'] ?? $seller['username'] ?? 'Verified Seller'); ?></h6>
                        <?php if ($isOwner): ?>
                            <span class="badge bg-success mt-1"><i class="fas fa-check"></i> This is you</span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <?php if ($isOwner): ?>
                            <!-- ============================================ -->
                            <!-- OWNER - Cannot add to cart -->
                            <!-- ============================================ -->
                            <button class="btn btn-owner btn-contact" disabled>
                                <i class="fas fa-user me-2"></i>This is your listing
                            </button>
                            <a href="edit_listing.php?id=<?php echo $item_id; ?>" class="btn btn-primary btn-contact">
                                <i class="fas fa-edit me-2"></i>Edit Listing
                            </a>
                        <?php elseif ($isLoggedIn): ?>
                            <!-- ============================================ -->
                            <!-- LOGGED IN USER (not owner) -->
                            <!-- ============================================ -->
                            <form method="POST" action="">
                                <button type="submit" name="add_to_cart" class="btn btn-primary btn-contact">
                                    <i class="fas fa-shopping-cart me-2"></i>Add to Cart
                                </button>
                            </form>
                        <?php else: ?>
                            <!-- ============================================ -->
                            <!-- NOT LOGGED IN -->
                            <!-- ============================================ -->
                            <a href="login.php?redirect=<?php echo urlencode('item_details.php?id=' . $item_id . '&add_to_cart=1'); ?>" class="btn btn-primary btn-contact">
                                <i class="fas fa-shopping-cart me-2"></i>Login to Buy
                            </a>
                        <?php endif; ?>
                        
                        <?php if (!empty($seller['phone'])): ?>
                            <a href="tel:<?php echo $seller['phone']; ?>" class="btn btn-outline-secondary btn-contact">
                                <i class="fas fa-phone me-2"></i>Call Seller
                            </a>
                            <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $seller['phone']); ?>" target="_blank" class="btn btn-whatsapp btn-contact">
                                <i class="fab fa-whatsapp me-2"></i>Chat on WhatsApp
                            </a>
                        <?php endif; ?>
                    </div>
                    
                    <hr>
                    <small class="text-muted d-block text-center">Listing ID: #<?php echo $item['id']; ?></small>
                    <small class="text-muted d-block text-center">Posted: <?php echo date('F j, Y', strtotime($item['created_at'])); ?></small>
                </div>
            </div>
        </div>
    </div>

    <?php include 'footer.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const isLoggedIn = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;
        const isOwner = <?php echo $isOwner ? 'true' : 'false'; ?>;
        
        function changeImage(src, element) {
            document.getElementById('mainImage').src = src;
            document.querySelectorAll('.thumbnail').forEach(thumb => {
                thumb.classList.remove('active');
            });
            element.classList.add('active');
        }
        
        function showLoginRequired() {
            // Redirect to login with return URL
            window.location.href = 'login.php?redirect=<?php echo urlencode('item_details.php?id=' . $item_id . '&add_to_cart=1'); ?>';
        }
        
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        if (alert.parentNode) alert.remove();
                    }, 300);
                }, 3000);
            });
        });
    </script>
</body>
</html>