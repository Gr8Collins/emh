<?php
session_start();
require 'config.php';

// ============================================
// CART HELPER FUNCTIONS
// ============================================

function ensureCartTables($conn) {
    $errors = [];
    
    // Check if cart_items table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'cart_items'");
    if ($tableCheck->num_rows === 0) {
        $createTable = "CREATE TABLE cart_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            listing_id INT NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
            UNIQUE KEY unique_cart_item (user_id, listing_id),
            INDEX idx_user (user_id),
            INDEX idx_listing (listing_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        if (!$conn->query($createTable)) {
            $errors[] = "Failed to create cart_items table: " . $conn->error;
        }
    } else {
        // Check if listing_id column exists (might be missing)
        $colCheck = $conn->query("SHOW COLUMNS FROM cart_items LIKE 'listing_id'");
        if ($colCheck->num_rows === 0) {
            $alterTable = "ALTER TABLE cart_items ADD COLUMN listing_id INT NOT NULL AFTER user_id";
            if (!$conn->query($alterTable)) {
                $errors[] = "Failed to add listing_id column: " . $conn->error;
            }
        }
        // Check if updated_at column exists
        $colCheck = $conn->query("SHOW COLUMNS FROM cart_items LIKE 'updated_at'");
        if ($colCheck->num_rows === 0) {
            $alterTable = "ALTER TABLE cart_items ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
            if (!$conn->query($alterTable)) {
                $errors[] = "Failed to add updated_at column: " . $conn->error;
            }
        }
        // Check if unique constraint exists
        $constraintCheck = $conn->query("SHOW INDEX FROM cart_items WHERE Key_name = 'unique_cart_item'");
        if ($constraintCheck->num_rows === 0) {
            $addConstraint = "ALTER TABLE cart_items ADD UNIQUE KEY unique_cart_item (user_id, listing_id)";
            if (!$conn->query($addConstraint)) {
                $errors[] = "Failed to add unique constraint: " . $conn->error;
            }
        }
    }
    
    // Check if quantity column exists in orders table
    $colCheck = $conn->query("SHOW COLUMNS FROM orders LIKE 'quantity'");
    if ($colCheck->num_rows === 0) {
        $alterTable = "ALTER TABLE orders ADD COLUMN quantity INT NOT NULL DEFAULT 1";
        if (!$conn->query($alterTable)) {
            $errors[] = "Failed to add quantity column to orders: " . $conn->error;
        }
    }
    
    // Check if delivery_address column exists in orders table
    $colCheck = $conn->query("SHOW COLUMNS FROM orders LIKE 'delivery_address'");
    if ($colCheck->num_rows === 0) {
        $alterTable = "ALTER TABLE orders ADD COLUMN delivery_address TEXT";
        if (!$conn->query($alterTable)) {
            $errors[] = "Failed to add delivery_address column to orders: " . $conn->error;
        }
    }
    
    // Check if subtotal column exists in orders table
    $colCheck = $conn->query("SHOW COLUMNS FROM orders LIKE 'subtotal'");
    if ($colCheck->num_rows === 0) {
        $alterTable = "ALTER TABLE orders ADD COLUMN subtotal DECIMAL(10,2) DEFAULT 0";
        if (!$conn->query($alterTable)) {
            $errors[] = "Failed to add subtotal column to orders: " . $conn->error;
        }
    }
    
    // Check if order_number column exists in orders table
    $colCheck = $conn->query("SHOW COLUMNS FROM orders LIKE 'order_number'");
    if ($colCheck->num_rows === 0) {
        $alterTable = "ALTER TABLE orders ADD COLUMN order_number VARCHAR(50) UNIQUE";
        if (!$conn->query($alterTable)) {
            $errors[] = "Failed to add order_number column to orders: " . $conn->error;
        }
    }
    
    // Check if store_id column exists in orders table
    $colCheck = $conn->query("SHOW COLUMNS FROM orders LIKE 'store_id'");
    if ($colCheck->num_rows === 0) {
        $alterTable = "ALTER TABLE orders ADD COLUMN store_id INT NOT NULL AFTER listing_id";
        if (!$conn->query($alterTable)) {
            $errors[] = "Failed to add store_id column to orders: " . $conn->error;
        }
    }
    
    return $errors;
}

function getCartCount($conn, $userId) {
    if (!$userId) return 0;
    $stmt = $conn->prepare("SELECT SUM(quantity) as total FROM cart_items WHERE user_id = ?");
    if (!$stmt) return 0;
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return (int)($row['total'] ?? 0);
}

function getCartItems($conn, $userId) {
    if (!$userId) return [];
    
    // UPDATED: Include seller_id and store_id
    $query = "SELECT ci.*, l.title, l.price, l.image, l.category, 
              u.id as seller_id, u.username as seller_name, u.store_name,
              u.id as store_id
              FROM cart_items ci
              JOIN listings l ON ci.listing_id = l.id
              JOIN users u ON l.user_id = u.id
              WHERE ci.user_id = ?
              ORDER BY ci.added_at DESC";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("SQL Error: " . $conn->error);
        return [];
    }
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

function getCartTotal($conn, $userId) {
    if (!$userId) return 0;
    
    $query = "SELECT SUM(ci.quantity * l.price) as total 
              FROM cart_items ci
              JOIN listings l ON ci.listing_id = l.id
              WHERE ci.user_id = ?";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) return 0;
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return (float)($row['total'] ?? 0);
}

// Generate unique order number
function generateOrderNumber() {
    return 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}

// ============================================
// AJAX HANDLERS (same file)
// ============================================

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

if ($isAjax) {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Please login to continue']);
        exit;
    }
    
    $userId = $_SESSION['user_id'];
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    
    // Ensure tables exist
    ensureCartTables($conn);
    
    switch ($action) {
        case 'add':
            // Add to cart
            $listingId = isset($_POST['listing_id']) ? (int)$_POST['listing_id'] : 0;
            $quantity = isset($_POST['quantity']) ? max(1, (int)$_POST['quantity']) : 1;
            
            if ($listingId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid listing']);
                exit;
            }
            
            // Validate listing exists and is active
            $check = $conn->prepare("SELECT id, title, price FROM listings WHERE id = ? AND status = 'active'");
            if (!$check) {
                echo json_encode(['success' => false, 'message' => 'Database error']);
                exit;
            }
            $check->bind_param("i", $listingId);
            $check->execute();
            $result = $check->get_result();
            
            if ($result->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'Listing not found or unavailable']);
                exit;
            }
            
            // Check if item already in cart
            $existing = $conn->prepare("SELECT id, quantity FROM cart_items WHERE user_id = ? AND listing_id = ?");
            if (!$existing) {
                echo json_encode(['success' => false, 'message' => 'Database error']);
                exit;
            }
            $existing->bind_param("ii", $userId, $listingId);
            $existing->execute();
            $existingResult = $existing->get_result();
            
            if ($existingResult->num_rows > 0) {
                $row = $existingResult->fetch_assoc();
                $newQty = $row['quantity'] + $quantity;
                $update = $conn->prepare("UPDATE cart_items SET quantity = ? WHERE id = ?");
                if ($update) {
                    $update->bind_param("ii", $newQty, $row['id']);
                    $update->execute();
                }
            } else {
                $insert = $conn->prepare("INSERT INTO cart_items (user_id, listing_id, quantity) VALUES (?, ?, ?)");
                if ($insert) {
                    $insert->bind_param("iii", $userId, $listingId, $quantity);
                    $insert->execute();
                }
            }
            
            $cartCount = getCartCount($conn, $userId);
            echo json_encode([
                'success' => true,
                'message' => 'Added to cart successfully',
                'cart_count' => $cartCount
            ]);
            break;
            
        case 'update':
            // Update quantity
            $cartItemId = isset($_POST['cart_item_id']) ? (int)$_POST['cart_item_id'] : 0;
            $updateAction = isset($_POST['update_action']) ? $_POST['update_action'] : '';
            $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 0;
            
            if ($cartItemId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid cart item']);
                exit;
            }
            
            $verify = $conn->prepare("SELECT id, quantity FROM cart_items WHERE id = ? AND user_id = ?");
            if (!$verify) {
                echo json_encode(['success' => false, 'message' => 'Database error']);
                exit;
            }
            $verify->bind_param("ii", $cartItemId, $userId);
            $verify->execute();
            $result = $verify->get_result();
            
            if ($result->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'Cart item not found']);
                exit;
            }
            
            $row = $result->fetch_assoc();
            $currentQty = $row['quantity'];
            $newQty = $currentQty;
            
            switch ($updateAction) {
                case 'increase':
                    $newQty = $currentQty + 1;
                    break;
                case 'decrease':
                    $newQty = $currentQty - 1;
                    break;
                case 'set':
                    $newQty = max(0, $quantity);
                    break;
                default:
                    echo json_encode(['success' => false, 'message' => 'Invalid action']);
                    exit;
            }
            
            if ($newQty <= 0) {
                $delete = $conn->prepare("DELETE FROM cart_items WHERE id = ?");
                if ($delete) {
                    $delete->bind_param("i", $cartItemId);
                    $delete->execute();
                }
                $removed = true;
            } else {
                $update = $conn->prepare("UPDATE cart_items SET quantity = ? WHERE id = ?");
                if ($update) {
                    $update->bind_param("ii", $newQty, $cartItemId);
                    $update->execute();
                }
                $removed = false;
            }
            
            $cartCount = getCartCount($conn, $userId);
            $cartTotal = getCartTotal($conn, $userId);
            
            echo json_encode([
                'success' => true,
                'cart_count' => $cartCount,
                'cart_total' => $cartTotal,
                'removed' => $removed,
                'new_quantity' => $newQty
            ]);
            break;
            
        case 'remove':
            // Remove item
            $cartItemId = isset($_POST['cart_item_id']) ? (int)$_POST['cart_item_id'] : 0;
            
            if ($cartItemId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid cart item']);
                exit;
            }
            
            $delete = $conn->prepare("DELETE FROM cart_items WHERE id = ? AND user_id = ?");
            if (!$delete) {
                echo json_encode(['success' => false, 'message' => 'Database error']);
                exit;
            }
            $delete->bind_param("ii", $cartItemId, $userId);
            
            if ($delete->execute() && $delete->affected_rows > 0) {
                $cartCount = getCartCount($conn, $userId);
                $cartTotal = getCartTotal($conn, $userId);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Item removed from cart',
                    'cart_count' => $cartCount,
                    'cart_total' => $cartTotal
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to remove item']);
            }
            break;
            
        case 'checkout':
            // Process checkout - UPDATED with store_id and total_amount
            $deliveryAddress = isset($_POST['delivery_address']) ? trim($_POST['delivery_address']) : '';
            
            if (empty($deliveryAddress)) {
                echo json_encode(['success' => false, 'message' => 'Please enter a delivery address']);
                exit;
            }
            
            $cartItems = getCartItems($conn, $userId);
            if (empty($cartItems)) {
                echo json_encode(['success' => false, 'message' => 'Your cart is empty']);
                exit;
            }
            
            $conn->begin_transaction();
            
            try {
                $orderNumber = generateOrderNumber();
                $totalAmount = 0;
                $orderIds = [];
                
                foreach ($cartItems as $item) {
                    $subtotal = $item['price'] * $item['quantity'];
                    $totalAmount += $subtotal;
                    $storeId = $item['seller_id']; // Get vendor ID from the item
                    
                    // ============================================
                    // UPDATED: Include store_id and total_amount
                    // ============================================
                    $stmt = $conn->prepare("INSERT INTO orders (user_id, listing_id, store_id, quantity, subtotal, total_amount, delivery_address, order_number, status, created_at) 
                                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
                    if (!$stmt) {
                        throw new Exception("Failed to prepare order statement: " . $conn->error);
                    }
                    $stmt->bind_param("iiiiidss", $userId, $item['listing_id'], $storeId, $item['quantity'], $subtotal, $subtotal, $deliveryAddress, $orderNumber);
                    $stmt->execute();
                    
                    if ($stmt->affected_rows === 0) {
                        throw new Exception("Failed to create order for item: " . $item['title']);
                    }
                    
                    $orderIds[] = $stmt->insert_id;
                    $stmt->close();
                }
                
                // Update total_amount for all orders with the same order_number
                if (!empty($orderIds)) {
                    $updateTotal = $conn->prepare("UPDATE orders SET total_amount = ? WHERE order_number = ?");
                    if ($updateTotal) {
                        $updateTotal->bind_param("ds", $totalAmount, $orderNumber);
                        $updateTotal->execute();
                        $updateTotal->close();
                    }
                }
                
                // Clear cart
                $delete = $conn->prepare("DELETE FROM cart_items WHERE user_id = ?");
                if ($delete) {
                    $delete->bind_param("i", $userId);
                    $delete->execute();
                    $delete->close();
                }
                
                $conn->commit();
                
                // ============================================
                // NEW: Create notification for vendor
                // ============================================
                foreach ($cartItems as $item) {
                    $vendorId = $item['seller_id'];
                    $notifyStmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, message, link, is_read, created_at) 
                                                  VALUES (?, 'new_order', ?, ?, 'dashboard.php?tab=orders', 0, NOW())");
                    if ($notifyStmt) {
                        $title = "New Order Received!";
                        $message = "You have a new order for '" . $item['title'] . "' (Order #" . str_pad(end($orderIds), 5, '0', STR_PAD_LEFT) . ")";
                        $notifyStmt->bind_param("iss", $vendorId, $title, $message);
                        $notifyStmt->execute();
                        $notifyStmt->close();
                    }
                }
                
                // Set session flag for confirmation page
                $_SESSION['order_success'] = true;
                $_SESSION['order_success_message'] = "Order #" . str_pad(end($orderIds), 5, '0', STR_PAD_LEFT) . " placed successfully!";
                $_SESSION['last_order_number'] = $orderNumber;
                $_SESSION['last_order_total'] = $totalAmount;
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Order placed successfully!',
                    'order_number' => $orderNumber,
                    'order_id' => end($orderIds),
                    'total_amount' => $totalAmount,
                    'redirect' => 'order_confirmation.php'
                ]);
                exit;
                
            } catch (Exception $e) {
                $conn->rollback();
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
    exit;
}

// ============================================
// PAGE DISPLAY (cart page)
// ============================================

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$userName = $_SESSION['username'] ?? 'User';

// Ensure cart tables exist
ensureCartTables($conn);

// Get cart data
$cartItems = getCartItems($conn, $userId);
$cartTotal = getCartTotal($conn, $userId);
$cartCount = getCartCount($conn, $userId);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping Cart - Ebonyi Market Hub</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        :root {
            --primary: #ff7b00;
            --primary-dark: #e66a00;
            --primary-light: #ff9500;
            --secondary: #2c3e50;
        }
        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
        }
        .btn-primary:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
        }
        .btn-outline-primary {
            color: var(--primary);
            border-color: var(--primary);
        }
        .btn-outline-primary:hover {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        .text-primary { color: var(--primary) !important; }
        
        .cart-item {
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
            background: white;
        }
        .cart-item:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        .cart-item img {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 8px;
        }
        .quantity-control {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .quantity-control button {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: 1px solid #dee2e6;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        .quantity-control button:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        .quantity-control button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .quantity-control .qty-display {
            min-width: 36px;
            text-align: center;
            font-weight: 600;
            font-size: 1.1rem;
        }
        .cart-summary {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1.5rem;
            position: sticky;
            top: 1rem;
        }
        .cart-empty {
            text-align: center;
            padding: 4rem 1rem;
        }
        .cart-empty i {
            font-size: 4rem;
            color: #dee2e6;
            margin-bottom: 1rem;
        }
        .item-price {
            font-weight: 700;
            color: var(--primary);
            font-size: 1.15rem;
        }
        .item-total {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--dark);
        }
        
        /* Checkout Modal Styles */
        .modal-content {
            border-radius: 12px;
            border: none;
        }
        .modal-header {
            border-bottom: 1px solid #e9ecef;
        }
        .modal-footer {
            border-top: 1px solid #e9ecef;
        }
        .checkout-item {
            padding: 0.75rem 0;
            border-bottom: 1px solid #e9ecef;
        }
        .checkout-item:last-child {
            border-bottom: none;
        }
        .checkout-item img {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 8px;
        }
        
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }
        .toast {
            background: white;
            border-radius: 8px;
            padding: 1rem 1.5rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            margin-bottom: 0.5rem;
            min-width: 300px;
            animation: slideIn 0.3s ease;
            display: flex;
            align-items: center;
        }
        .toast.success { border-left: 4px solid #28a745; }
        .toast.error { border-left: 4px solid #dc3545; }
        .toast.info { border-left: 4px solid #17a2b8; }
        .toast.warning { border-left: 4px solid #ffc107; }
        
        .toast i {
            font-size: 1.2rem;
            margin-right: 0.75rem;
        }
        .toast.success i { color: #28a745; }
        .toast.error i { color: #dc3545; }
        .toast.info i { color: #17a2b8; }
        .toast.warning i { color: #ffc107; }
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        /* Loading spinner overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9998;
        }
        .loading-overlay.active {
            display: flex;
        }
        .loading-spinner {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            text-align: center;
        }
        .loading-spinner .spinner {
            width: 3rem;
            height: 3rem;
        }
        
        @media (max-width: 768px) {
            .cart-item img {
                width: 60px;
                height: 60px;
            }
            .quantity-control button {
                width: 30px;
                height: 30px;
            }
            .cart-summary {
                position: static;
                margin-top: 1rem;
            }
            .toast {
                min-width: auto;
                width: 90%;
            }
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner-border spinner text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2 mb-0">Processing...</p>
        </div>
    </div>

    <!-- Toast Container for notifications -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm sticky-top">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php">
                <span style="color: var(--primary);">Ebonyi</span> Market Hub
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">
                            <i class="fas fa-home"></i> Home
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="cart_items.php">
                            <i class="fas fa-shopping-cart"></i> Cart
                            <?php if ($cartCount > 0): ?>
                                <span class="badge bg-danger ms-1" id="navbarCartBadge"><?php echo $cartCount; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($userName); ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="fas fa-shopping-cart text-primary me-2"></i>Your Cart</h1>
            <span class="badge bg-primary fs-6" id="cartCountBadge">
                <?php echo $cartCount; ?> item<?php echo $cartCount != 1 ? 's' : ''; ?>
            </span>
        </div>

        <?php if (empty($cartItems)): ?>
            <div class="cart-empty">
                <i class="fas fa-shopping-cart"></i>
                <h3>Your cart is empty</h3>
                <p class="text-muted">Browse our listings and add items you love.</p>
                <a href="index.php" class="btn btn-primary mt-3">
                    <i class="fas fa-arrow-left me-2"></i>Start Shopping
                </a>
            </div>
        <?php else: ?>
            <div class="row">
                <div class="col-lg-8">
                    <div id="cartItemsContainer">
                        <?php foreach ($cartItems as $item): ?>
                            <div class="cart-item" data-cart-id="<?php echo $item['id']; ?>">
                                <div class="row align-items-center">
                                    <div class="col-md-2 col-3">
                                        <img src="<?php echo htmlspecialchars($item['image'] ?? 'https://images.unsplash.com/photo-1560518883-ce09059eeffa?ixlib=rb-4.0.3&auto=format&fit=crop&w=200&q=80'); ?>" 
                                             alt="<?php echo htmlspecialchars($item['title']); ?>" loading="lazy">
                                    </div>
                                    <div class="col-md-4 col-9">
                                        <h6 class="mb-1">
    <?php 
    $displayTitle = trim((string)($item['title'] ?? ''));
    echo htmlspecialchars(($displayTitle !== '' && $displayTitle !== '0') ? $displayTitle : 'Untitled Listing');
    ?>
</h6>
                                        <small class="text-muted">
                                            <i class="fas fa-store me-1"></i>
                                            <?php echo htmlspecialchars($item['store_name'] ?? $item['seller_name']); ?>
                                        </small>
                                        <div class="mt-1">
                                            <span class="badge bg-light text-dark"><?php echo htmlspecialchars($item['category']); ?></span>
                                        </div>
                                    </div>
                                    <div class="col-md-2 col-4 text-center">
                                        <div class="item-price">₦<?php echo number_format($item['price'], 2); ?></div>
                                    </div>
                                    <div class="col-md-2 col-5">
                                        <div class="quantity-control">
                                            <button class="btn-decrease" data-id="<?php echo $item['id']; ?>" title="Decrease quantity">
                                                <i class="fas fa-minus"></i>
                                            </button>
                                            <span class="qty-display" id="qty-<?php echo $item['id']; ?>">
                                                <?php echo $item['quantity']; ?>
                                            </span>
                                            <button class="btn-increase" data-id="<?php echo $item['id']; ?>" title="Increase quantity">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-2 col-3 text-end">
                                        <div class="item-total" id="total-<?php echo $item['id']; ?>">
                                            ₦<?php echo number_format($item['price'] * $item['quantity'], 2); ?>
                                        </div>
                                        <button class="btn btn-danger btn-sm mt-1 btn-remove" data-id="<?php echo $item['id']; ?>" title="Remove item">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <div class="cart-summary">
                        <h5 class="mb-3">Order Summary</h5>
                        <hr>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Subtotal (<span id="itemCount"><?php echo $cartCount; ?></span> items)</span>
                            <span id="cartSubtotal">₦<?php echo number_format($cartTotal, 2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-3">
                            <span>Delivery Fee</span>
                            <span>₦0.00</span>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between mb-3">
                            <strong>Total</strong>
                            <strong id="cartTotalDisplay">₦<?php echo number_format($cartTotal, 2); ?></strong>
                        </div>
                        <button class="btn btn-primary w-100 btn-lg" id="checkoutBtn">
                            <i class="fas fa-credit-card me-2"></i>Proceed to Checkout
                        </button>
                        <a href="index.php" class="btn btn-outline-secondary w-100 mt-2">
                            <i class="fas fa-arrow-left me-2"></i>Continue Shopping
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Checkout Modal -->
    <div class="modal fade" id="checkoutModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-credit-card text-primary me-2"></i>Checkout
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-lg-7">
                            <h6><i class="fas fa-map-marker-alt me-2 text-primary"></i>Delivery Address</h6>
                            <textarea class="form-control" id="deliveryAddress" rows="3" 
                                      placeholder="Enter your full delivery address (Street, City, State)"><?php echo $_SESSION['address'] ?? ''; ?></textarea>
                            <small class="text-muted">We'll use this address to deliver your items.</small>
                            
                            <hr>
                            <h6><i class="fas fa-shopping-bag me-2 text-primary"></i>Order Items</h6>
                            <div id="checkoutItemsList">
                                <?php foreach ($cartItems as $item): ?>
                                    <div class="checkout-item d-flex align-items-center">
                                        <img src="<?php echo htmlspecialchars($item['image'] ?? 'https://images.unsplash.com/photo-1560518883-ce09059eeffa?ixlib=rb-4.0.3&auto=format&fit=crop&w=200&q=80'); ?>" 
                                             alt="<?php echo htmlspecialchars($item['title']); ?>" class="me-3">
                                        <div class="flex-grow-1">
                                            
                                            <small class="text-muted">Qty: <?php echo $item['quantity']; ?></small>
                                        </div>
                                        <div class="text-end">
                                            <span>₦<?php echo number_format($item['price'] * $item['quantity'], 2); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <div class="bg-light p-3 rounded">
                                <h6>Order Summary</h6>
                                <div class="d-flex justify-content-between">
                                    <span>Items</span>
                                    <span id="checkoutItemCount"><?php echo $cartCount; ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Subtotal</span>
                                    <span id="checkoutSubtotal">₦<?php echo number_format($cartTotal, 2); ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Delivery Fee</span>
                                    <span>₦0.00</span>
                                </div>
                                <hr>
                                <div class="d-flex justify-content-between">
                                    <strong>Total</strong>
                                    <strong class="text-primary" id="checkoutTotal">₦<?php echo number_format($cartTotal, 2); ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary btn-lg" id="placeOrderBtn">
                        <i class="fas fa-check-circle me-2"></i>Place Order
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-dark text-white mt-5 py-4">
        <div class="container text-center">
            <p class="mb-0">&copy; <?php echo date('Y'); ?> Ebonyi Market Hub. All rights reserved.</p>
        </div>
    </footer>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
    $(document).ready(function() {
        // ============================================
        // TOAST NOTIFICATION SYSTEM
        // ============================================
        function showToast(message, type = 'info') {
            const icons = {
                success: 'fa-check-circle',
                error: 'fa-exclamation-circle',
                info: 'fa-info-circle',
                warning: 'fa-exclamation-triangle'
            };
            const toast = `
                <div class="toast ${type}">
                    <i class="fas ${icons[type] || 'fa-info-circle'}"></i>
                    <span>${message}</span>
                </div>
            `;
            $('#toastContainer').append(toast);
            setTimeout(function() {
                $('.toast').first().fadeOut(300, function() {
                    $(this).remove();
                });
            }, 4000);
        }

        // ============================================
        // LOADING OVERLAY
        // ============================================
        function showLoading(show) {
            if (show) {
                $('#loadingOverlay').addClass('active');
            } else {
                $('#loadingOverlay').removeClass('active');
            }
        }

        // ============================================
        // UPDATE CART DISPLAY
        // ============================================
        function updateCartTotals() {
            let subtotal = 0;
            let itemCount = 0;
            
            $('.cart-item').each(function() {
                const price = parseFloat($(this).find('.item-price').text().replace(/[₦,]/g, ''));
                const qty = parseInt($(this).find('.qty-display').text());
                if (!isNaN(price) && !isNaN(qty)) {
                    subtotal += price * qty;
                    itemCount += qty;
                }
            });
            
            const formattedTotal = '₦' + subtotal.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            
            $('#cartSubtotal').text(formattedTotal);
            $('#cartTotalDisplay').text(formattedTotal);
            $('#checkoutSubtotal').text(formattedTotal);
            $('#checkoutTotal').text(formattedTotal);
            $('#itemCount').text(itemCount);
            $('#checkoutItemCount').text(itemCount);
            
            const badgeText = itemCount + ' item' + (itemCount !== 1 ? 's' : '');
            $('#cartCountBadge').text(badgeText);
            
            // Update navbar badge
            if (itemCount > 0) {
                if ($('#navbarCartBadge').length === 0) {
                    $('.nav-item .fa-shopping-cart').parent().append('<span class="badge bg-danger ms-1" id="navbarCartBadge">' + itemCount + '</span>');
                } else {
                    $('#navbarCartBadge').text(itemCount);
                }
            } else {
                $('#navbarCartBadge').remove();
            }
        }

        // ============================================
        // AJAX CART OPERATIONS
        // ============================================
        function ajaxCart(action, data, callback) {
            data.action = action;
            showLoading(true);
            
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: data,
                dataType: 'json',
                success: function(response) {
                    showLoading(false);
                    if (response.success) {
                        if (callback) callback(response);
                    } else {
                        showToast(response.message || 'Operation failed', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    showLoading(false);
                    console.error('AJAX Error:', error);
                    console.error('Response:', xhr.responseText);
                    showToast('An error occurred. Please try again.', 'error');
                }
            });
        }

        // ============================================
        // ADD TO CART (from listing pages)
        // ============================================
        $(document).on('click', '.add-to-cart-btn', function() {
            const listingId = $(this).data('listing-id');
            const quantity = $(this).data('quantity') || 1;
            
            if (!listingId) {
                showToast('Invalid listing', 'error');
                return;
            }
            
            const btn = $(this);
            const originalText = btn.html();
            btn.html('<i class="fas fa-spinner fa-spin"></i> Adding...').prop('disabled', true);
            
            ajaxCart('add', { listing_id: listingId, quantity: quantity }, function(response) {
                btn.html(originalText).prop('disabled', false);
                showToast(response.message || 'Added to cart!', 'success');
                if (response.cart_count !== undefined) {
                    // Update badge
                    if (response.cart_count > 0) {
                        if ($('#navbarCartBadge').length === 0) {
                            $('.nav-item .fa-shopping-cart').parent().append('<span class="badge bg-danger ms-1" id="navbarCartBadge">' + response.cart_count + '</span>');
                        } else {
                            $('#navbarCartBadge').text(response.cart_count);
                        }
                    }
                }
            });
        });

        // ============================================
        // INCREASE QUANTITY
        // ============================================
        $(document).on('click', '.btn-increase', function() {
            const id = $(this).data('id');
            const btn = $(this);
            btn.prop('disabled', true);
            
            ajaxCart('update', { cart_item_id: id, update_action: 'increase' }, function(response) {
                btn.prop('disabled', false);
                if (!response.removed) {
                    $('#qty-' + id).text(response.new_quantity);
                    const price = parseFloat($('.cart-item[data-cart-id="' + id + '"] .item-price').text().replace(/[₦,]/g, ''));
                    if (!isNaN(price)) {
                        $('#total-' + id).text('₦' + (price * response.new_quantity).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','));
                    }
                    updateCartTotals();
                }
            });
        });

        // ============================================
        // DECREASE QUANTITY
        // ============================================
        $(document).on('click', '.btn-decrease', function() {
            const id = $(this).data('id');
            const currentQty = parseInt($('#qty-' + id).text());
            const btn = $(this);
            btn.prop('disabled', true);
            
            if (currentQty <= 1) {
                if (confirm('Remove this item from cart?')) {
                    ajaxCart('update', { cart_item_id: id, update_action: 'decrease' }, function(response) {
                        btn.prop('disabled', false);
                        if (response.removed) {
                            $('.cart-item[data-cart-id="' + id + '"]').fadeOut(300, function() {
                                $(this).remove();
                                if ($('.cart-item').length === 0) {
                                    // Reload to show empty cart
                                    location.reload();
                                }
                                updateCartTotals();
                            });
                        }
                    });
                } else {
                    btn.prop('disabled', false);
                }
            } else {
                ajaxCart('update', { cart_item_id: id, update_action: 'decrease' }, function(response) {
                    btn.prop('disabled', false);
                    if (!response.removed) {
                        $('#qty-' + id).text(response.new_quantity);
                        const price = parseFloat($('.cart-item[data-cart-id="' + id + '"] .item-price').text().replace(/[₦,]/g, ''));
                        if (!isNaN(price)) {
                            $('#total-' + id).text('₦' + (price * response.new_quantity).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','));
                        }
                        updateCartTotals();
                    }
                });
            }
        });

        // ============================================
        // REMOVE ITEM
        // ============================================
        $(document).on('click', '.btn-remove', function() {
            const id = $(this).data('id');
            if (confirm('Remove this item from your cart?')) {
                const btn = $(this);
                btn.prop('disabled', true);
                
                ajaxCart('remove', { cart_item_id: id }, function(response) {
                    btn.prop('disabled', false);
                    $('.cart-item[data-cart-id="' + id + '"]').fadeOut(300, function() {
                        $(this).remove();
                        if ($('.cart-item').length === 0) {
                            location.reload();
                        }
                        updateCartTotals();
                        showToast(response.message || 'Item removed', 'success');
                    });
                });
            }
        });

        // ============================================
        // CHECKOUT MODAL
        // ============================================
        $('#checkoutBtn').on('click', function() {
            if ($('.cart-item').length === 0) {
                showToast('Your cart is empty!', 'error');
                return;
            }
            const checkoutModal = new bootstrap.Modal(document.getElementById('checkoutModal'));
            checkoutModal.show();
        });

        // ============================================
        // PLACE ORDER
        // ============================================
        $('#placeOrderBtn').on('click', function() {
            const address = $('#deliveryAddress').val().trim();
            if (!address) {
                showToast('Please enter a delivery address.', 'error');
                $('#deliveryAddress').focus();
                return;
            }
            
            // Confirm before placing order
            if (!confirm('Are you sure you want to place this order?')) {
                return;
            }
            
            const btn = $(this);
            const originalText = btn.html();
            btn.html('<i class="fas fa-spinner fa-spin me-2"></i>Processing...').prop('disabled', true);
            
            ajaxCart('checkout', { delivery_address: address }, function(response) {
                btn.html(originalText).prop('disabled', false);
                if (response.redirect) {
                    // Close modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('checkoutModal'));
                    if (modal) modal.hide();
                    showToast('Order placed successfully! Redirecting...', 'success');
                    setTimeout(function() {
                        window.location.href = response.redirect;
                    }, 1000);
                } else {
                    showToast(response.message || 'Order placed successfully!', 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                }
            });
        });

        // ============================================
        // ENTER KEY ON ADDRESS FIELD
        // ============================================
        $('#deliveryAddress').on('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                $('#placeOrderBtn').click();
            }
        });

        // ============================================
        // INITIAL CART TOTALS
        // ============================================
        updateCartTotals();
        
        // ============================================
        // KEYBOARD SHORTCUTS
        // ============================================
        $(document).on('keydown', function(e) {
            // ESC key to close modal
            if (e.key === 'Escape') {
                const modal = bootstrap.Modal.getInstance(document.getElementById('checkoutModal'));
                if (modal) modal.hide();
            }
        });
    });
    </script>
</body>
</html>