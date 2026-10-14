<?php
session_start();
require 'config.php';

// ============================================
// CHECK IF ORDER WAS JUST PLACED
// ============================================
if (!isset($_SESSION['user_id']) || !isset($_SESSION['order_success'])) {
    header('Location: index.php');
    exit;
}

$userId = $_SESSION['user_id'];
$orderNumber = $_SESSION['last_order_number'] ?? '';
$orderTotal = $_SESSION['last_order_total'] ?? 0;
$orderId = $_SESSION['last_order_id'] ?? 0;

// Get order details from database
$orderDetails = null;
if ($orderNumber) {
    $query = "SELECT o.*, 
              GROUP_CONCAT(l.title SEPARATOR ', ') as items,
              u.username as buyer_name,
              u.email as buyer_email,
              u.phone as buyer_phone
              FROM orders o
              JOIN listings l ON o.listing_id = l.id
              JOIN users u ON o.user_id = u.id
              WHERE o.order_number = ? AND o.user_id = ?
              GROUP BY o.id
              ORDER BY o.created_at DESC
              LIMIT 1";
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("si", $orderNumber, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $orderDetails = $result->fetch_assoc();
        $stmt->close();
    }
}

// If no order found, redirect
if (!$orderDetails && empty($orderNumber)) {
    header('Location: index.php');
    exit;
}

// Get all items in this order
$orderItems = [];
if ($orderNumber) {
    $itemsQuery = "SELECT o.*, l.title, l.price, l.image, l.category,
                   u.username as seller_name
                   FROM orders o
                   JOIN listings l ON o.listing_id = l.id
                   JOIN users u ON l.user_id = u.id
                   WHERE o.order_number = ?
                   ORDER BY o.created_at DESC";
    
    $stmt = $conn->prepare($itemsQuery);
    if ($stmt) {
        $stmt->bind_param("s", $orderNumber);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $orderItems[] = $row;
        }
        $stmt->close();
    }
}

// Clear the session flag so it doesn't show again on refresh
unset($_SESSION['order_success']);
// Keep the order data for display
// $_SESSION['last_order_number'] and $_SESSION['last_order_total'] remain

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmed - Ebonyi Market Hub</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        :root {
            --primary: #ff7b00;
            --primary-dark: #e66a00;
            --primary-light: #ff9500;
            --secondary: #2c3e50;
            --success: #28a745;
            --success-dark: #1e7e34;
        }
        
        body {
            background: #f5f7fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
        }
        .btn-primary:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
        }
        .btn-success {
            background: var(--success);
            border-color: var(--success);
        }
        .btn-success:hover {
            background: var(--success-dark);
            border-color: var(--success-dark);
        }
        
        .confirmation-wrapper {
            max-width: 900px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        
        .success-icon {
            font-size: 5rem;
            color: #28a745;
            animation: scaleIn 0.5s ease;
        }
        
        @keyframes scaleIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); opacity: 1; }
        }
        
        .order-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-top: 2rem;
        }
        
        .order-card-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 1.5rem 2rem;
        }
        
        .order-card-header h5 {
            margin: 0;
            font-weight: 600;
        }
        
        .order-card-header .order-number {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .order-card-body {
            padding: 2rem;
        }
        
        .order-item {
            display: flex;
            align-items: center;
            padding: 1rem 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .order-item:last-child {
            border-bottom: none;
        }
        
        .order-item img {
            width: 70px;
            height: 70px;
            object-fit: cover;
            border-radius: 10px;
            margin-right: 1rem;
        }
        
        .order-item .item-details {
            flex: 1;
        }
        
        .order-item .item-details h6 {
            margin: 0;
            font-weight: 600;
        }
        
        .order-item .item-details small {
            color: #6c757d;
        }
        
        .order-item .item-price {
            font-weight: 700;
            color: var(--primary);
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
        }
        
        .summary-row.total {
            font-weight: 700;
            font-size: 1.2rem;
            border-top: 2px solid #e9ecef;
            padding-top: 1rem;
            margin-top: 0.5rem;
        }
        
        .summary-row.total .amount {
            color: var(--primary);
            font-size: 1.4rem;
        }
        
        .delivery-info {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1.5rem;
            margin-top: 1.5rem;
        }
        
        .delivery-info h6 {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .delivery-info p {
            margin: 0;
            color: #495057;
        }
        
        .action-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 2rem;
            justify-content: center;
        }
        
        .action-buttons .btn {
            padding: 0.6rem 1.8rem;
            font-weight: 600;
            min-width: 180px;
        }
        
        @media (max-width: 768px) {
            .order-card-body {
                padding: 1rem;
            }
            .order-item img {
                width: 50px;
                height: 50px;
            }
            .action-buttons .btn {
                min-width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
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
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
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

    <div class="confirmation-wrapper">
        <!-- Success Message -->
        <div class="text-center">
            <div class="success-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h2 class="mt-3 fw-bold" style="color: var(--secondary);">Order Placed Successfully!</h2>
            <p class="text-muted">Thank you for your order. We'll process it as soon as possible.</p>
        </div>

        <!-- Order Details Card -->
        <div class="order-card">
            <div class="order-card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5><i class="fas fa-file-invoice me-2"></i>Order Details</h5>
                    <span class="order-number">Order #: <?php echo htmlspecialchars($orderNumber); ?></span>
                </div>
                <span class="badge bg-light text-dark px-3 py-2">
                    <i class="fas fa-clock me-1"></i>
                    <?php echo date('M d, Y g:i A', strtotime($orderDetails['created_at'] ?? 'now')); ?>
                </span>
            </div>
            
            <div class="order-card-body">
                <!-- Order Items -->
                <h6 class="fw-bold mb-3"><i class="fas fa-shopping-bag me-2 text-primary"></i>Items Ordered</h6>
                
                <?php if (!empty($orderItems)): ?>
                    <?php foreach ($orderItems as $item): ?>
                        <div class="order-item">
                            <img src="<?php echo htmlspecialchars($item['image'] ?? 'https://images.unsplash.com/photo-1560518883-ce09059eeffa?ixlib=rb-4.0.3&auto=format&fit=crop&w=200&q=80'); ?>" 
                                 alt="<?php echo htmlspecialchars($item['title']); ?>">
                            <div class="item-details">
                                <h6><?php echo htmlspecialchars($item['title']); ?></h6>
                                <small>
                                    <i class="fas fa-store me-1"></i>
                                    <?php echo htmlspecialchars($item['seller_name'] ?? 'Vendor'); ?>
                                </small>
                                <br>
                                <small>Qty: <?php echo $item['quantity']; ?></small>
                            </div>
                            <div class="item-price">₦<?php echo number_format($item['subtotal'] ?? ($item['price'] * $item['quantity']), 2); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-3">
                        <p class="text-muted">Order items loaded successfully.</p>
                    </div>
                <?php endif; ?>
                
                <!-- Order Summary -->
                <hr>
                <div class="summary-row">
                    <span>Subtotal</span>
                    <span>₦<?php echo number_format($orderTotal, 2); ?></span>
                </div>
                <div class="summary-row">
                    <span>Delivery Fee</span>
                    <span>₦0.00</span>
                </div>
                <div class="summary-row total">
                    <span>Total</span>
                    <span class="amount">₦<?php echo number_format($orderTotal, 2); ?></span>
                </div>
                
                <!-- Delivery Information -->
                <div class="delivery-info">
                    <h6><i class="fas fa-map-marker-alt me-2 text-primary"></i>Delivery Address</h6>
                    <p>
                        <?php 
                        $address = $orderDetails['delivery_address'] ?? $_SESSION['address'] ?? 'No address provided';
                        echo nl2br(htmlspecialchars($address));
                        ?>
                    </p>
                    <hr class="my-2">
                    <div class="row">
                        <div class="col-md-6">
                            <small class="text-muted">Customer</small>
                            <p class="mb-0">
                                <?php echo htmlspecialchars($orderDetails['buyer_name'] ?? $_SESSION['username'] ?? ''); ?>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted">Contact</small>
                            <p class="mb-0">
                                <?php echo htmlspecialchars($orderDetails['buyer_phone'] ?? $_SESSION['phone'] ?? 'No phone provided'); ?>
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- Order Status -->
                <div class="mt-3">
                    <span class="badge bg-warning text-dark px-3 py-2">
                        <i class="fas fa-spinner fa-spin me-1"></i>
                        Status: <?php echo ucfirst($orderDetails['status'] ?? 'Pending'); ?>
                    </span>
                    <span class="badge bg-info text-dark px-3 py-2 ms-2">
                        <i class="fas fa-credit-card me-1"></i>
                        Payment: <?php echo ucfirst($orderDetails['payment_status'] ?? 'Pending'); ?>
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Action Buttons -->
        <div class="action-buttons">
            <a href="dashboard.php" class="btn btn-primary">
                <i class="fas fa-tachometer-alt me-2"></i>View My Orders
            </a>
            <a href="index.php" class="btn btn-success">
                <i class="fas fa-arrow-left me-2"></i>Continue Shopping
            </a>
            <a href="cart_items.php" class="btn btn-outline-secondary">
                <i class="fas fa-shopping-cart me-2"></i>View Cart
            </a>
        </div>
        
        <!-- Order Confirmation Note -->
        <div class="text-center mt-4">
            <small class="text-muted">
                <i class="fas fa-envelope me-1"></i>
                A confirmation email has been sent to your registered email address.
            </small>
            <br>
            <small class="text-muted">
                Order #: <?php echo htmlspecialchars($orderNumber); ?>
            </small>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-dark text-white mt-5 py-4">
        <div class="container text-center">
            <p class="mb-0">&copy; <?php echo date('Y'); ?> Ebonyi Market Hub. All rights reserved.</p>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Print order details if needed
        function printOrder() {
            window.print();
        }
        
        // Auto redirect to dashboard after 30 seconds if user doesn't click anything
        setTimeout(function() {
            // Check if user is still on the page
            if (document.querySelector('.confirmation-wrapper')) {
                // Show a small notification
                const note = document.createElement('div');
                note.className = 'alert alert-info alert-dismissible fade show position-fixed bottom-0 end-0 m-4';
                note.style.maxWidth = '350px';
                note.innerHTML = `
                    <i class="fas fa-info-circle me-2"></i>
                    You will be redirected to dashboard in 30 seconds.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                `;
                document.body.appendChild(note);
                
                // Auto redirect after 30 seconds
                setTimeout(function() {
                    window.location.href = 'dashboard.php';
                }, 30000);
            }
        }, 5000);
    </script>
</body>
</html>