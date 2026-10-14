<?php
$host = "localhost";
$user = "root";
$password = "";
$dbname = "ebonyi_market_hub";

// Connect to MySQL server
$conn = new mysqli($host, $user, $password);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>Ebonyi Market Hub - Complete Database Setup</h2>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
    .success { color: green; font-weight: bold; }
    .error { color: red; font-weight: bold; }
    .info { color: blue; }
    .section { background-color: #f5f5f5; padding: 10px; margin: 10px 0; border-radius: 5px; }
    table { border-collapse: collapse; width: 100%; margin: 10px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #4CAF50; color: white; }
    tr:nth-child(even) { background-color: #f2f2f2; }
    code { background-color: #f4f4f4; padding: 2px 5px; border-radius: 3px; }
</style>";

// Create the database if it doesn't exist
echo "<div class='section'>";
echo "<h3>Step 1: Database Creation</h3>";
$sql = "CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
if (!$conn->query($sql)) {
    die("<span class='error'>✗ Error creating database: " . $conn->error . "</span>");
}
echo "<span class='success'>✓ Database '$dbname' created or already exists</span><br>";
echo "</div>";

// Select the database
if (!$conn->select_db($dbname)) {
    die("<span class='error'>✗ Error selecting database: " . $conn->error . "</span>");
}

// Drop all existing tables to start fresh (in reverse order of dependencies)
echo "<div class='section'>";
echo "<h3>Step 2: Cleaning Existing Tables</h3>";
$tables = [
    'store_reviews', 'store_followers', 'store_analytics', 'store_settings',
    'cart_items', 'cart', 'wishlist', 'order_items', 'orders', 
    'product_reviews', 'product_images', 'product_submissions', 'products',
    'favorites', 'listing_images', 'messages', 'job_applications',
    'property_inquiries', 'coupons', 'listings', 'categories',
    'newsletter_subscribers', 'testimonials', 'site_settings', 'users'
];

foreach ($tables as $table) {
    $conn->query("DROP TABLE IF EXISTS `$table`");
}
echo "<span class='success'>✓ All existing tables removed</span><br>";
echo "</div>";

// ===== CREATE ALL TABLES =====
echo "<div class='section'>";
echo "<h3>Step 3: Creating Database Tables</h3>";

// 1. CREATE USERS TABLE (with storefront columns)
echo "<h4>3.1 Creating users table with storefront features...</h4>";
$create_users = "CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    first_name VARCHAR(50),
    last_name VARCHAR(50),
    phone VARCHAR(20),
    avatar_url VARCHAR(255),
    role ENUM('admin','user','agent','employer','vendor') DEFAULT 'user',
    status ENUM('active','inactive','suspended') DEFAULT 'active',
    address TEXT,
    city VARCHAR(50),
    state VARCHAR(50),
    zip_code VARCHAR(20),
    profile_image VARCHAR(255),
    is_vendor TINYINT(1) DEFAULT 0,
    is_admin TINYINT(1) DEFAULT 0,
    
    -- NEW STOREFRONT COLUMNS
    store_name VARCHAR(100) NULL,
    store_description TEXT NULL,
    store_logo VARCHAR(255) NULL,
    store_banner VARCHAR(255) NULL,
    store_slogan VARCHAR(255) NULL,
    store_verified TINYINT(1) DEFAULT 0,
    store_rating DECIMAL(2,1) DEFAULT 0,
    total_sales INT DEFAULT 0,
    total_products INT DEFAULT 0,
    store_views INT DEFAULT 0,
    store_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    store_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_username (username),
    INDEX idx_role (role),
    INDEX idx_is_vendor (is_vendor),
    INDEX idx_store_verified (store_verified)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_users)) {
    echo "<span class='success'>✓ Users table created with storefront features</span><br>";
} else {
    echo "<span class='error'>✗ Error: " . $conn->error . "</span><br>";
}

// 2. CREATE CATEGORIES TABLE
echo "<h4>3.2 Creating categories table...</h4>";
$create_categories = "CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    image VARCHAR(255),
    icon VARCHAR(100),
    status ENUM('active','inactive') DEFAULT 'active',
    parent_id INT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_parent (parent_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_categories)) {
    echo "<span class='success'>✓ Categories table created</span><br>";
} else {
    echo "<span class='error'>✗ Error: " . $conn->error . "</span><br>";
}

// 3. CREATE LISTINGS TABLE
echo "<h4>3.3 Creating listings table...</h4>";
$create_listings = "CREATE TABLE listings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category VARCHAR(50),
    category_id INT,
    title VARCHAR(200) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    description TEXT NOT NULL,
    price DECIMAL(15,2),
    location VARCHAR(255) NOT NULL,
    city VARCHAR(100),
    state VARCHAR(100) DEFAULT 'Ebonyi',
    property_type ENUM('house','apartment','commercial','land','other') DEFAULT 'house',
    listing_type ENUM('sale','rent','lease') DEFAULT 'sale',
    bedrooms INT,
    bathrooms INT,
    floor_area DECIMAL(10,2),
    land_area DECIMAL(10,2),
    item_condition ENUM('new','used','refurbished') DEFAULT 'used',
    brand VARCHAR(100),
    contact_name VARCHAR(100),
    contact_phone VARCHAR(20) NOT NULL,
    contact_email VARCHAR(100),
    image VARCHAR(255),
    status ENUM('active','pending','sold','rented','expired') DEFAULT 'pending',
    is_featured TINYINT(1) DEFAULT 0,
    views_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_category (category_id),
    INDEX idx_status (status),
    INDEX idx_price (price),
    INDEX idx_location (location),
    INDEX idx_featured (is_featured)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_listings)) {
    echo "<span class='success'>✓ Listings table created</span><br>";
} else {
    echo "<span class='error'>✗ Error: " . $conn->error . "</span><br>";
}

// 4. CREATE LISTING IMAGES TABLE
echo "<h4>3.4 Creating listing_images table...</h4>";
$create_listing_images = "CREATE TABLE listing_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    listing_id INT NOT NULL,
    image_url VARCHAR(255) NOT NULL,
    is_primary TINYINT(1) DEFAULT 0,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    INDEX idx_listing (listing_id),
    INDEX idx_primary (is_primary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_listing_images)) {
    echo "<span class='success'>✓ Listing images table created</span><br>";
} else {
    echo "<span class='error'>✗ Error: " . $conn->error . "</span><br>";
}

// 5. CREATE STORE SETTINGS TABLE (NEW)
echo "<h4>3.5 Creating store_settings table...</h4>";
$create_store_settings = "CREATE TABLE store_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    store_name VARCHAR(100) NOT NULL,
    store_slogan VARCHAR(255),
    store_description TEXT,
    store_logo VARCHAR(255),
    store_banner VARCHAR(255),
    store_color_primary VARCHAR(7) DEFAULT '#ff7b00',
    store_color_secondary VARCHAR(7) DEFAULT '#2c3e50',
    store_layout VARCHAR(20) DEFAULT 'grid',
    products_per_page INT DEFAULT 12,
    enable_reviews TINYINT(1) DEFAULT 1,
    enable_ratings TINYINT(1) DEFAULT 1,
    enable_contact_form TINYINT(1) DEFAULT 1,
    enable_social_links TINYINT(1) DEFAULT 1,
    facebook_url VARCHAR(255),
    twitter_url VARCHAR(255),
    instagram_url VARCHAR(255),
    youtube_url VARCHAR(255),
    linkedin_url VARCHAR(255),
    whatsapp_number VARCHAR(20),
    opening_hours TEXT,
    shipping_policy TEXT,
    return_policy TEXT,
    payment_info TEXT,
    custom_css TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_store (user_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_store_settings)) {
    echo "<span class='success'>✓ Store settings table created</span><br>";
} else {
    echo "<span class='error'>✗ Error: " . $conn->error . "</span><br>";
}

// 6. CREATE STORE FOLLOWERS TABLE (NEW)
echo "<h4>3.6 Creating store_followers table...</h4>";
$create_store_followers = "CREATE TABLE store_followers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    store_id INT NOT NULL,
    follower_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_follow (store_id, follower_id),
    INDEX idx_store (store_id),
    INDEX idx_follower (follower_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_store_followers)) {
    echo "<span class='success'>✓ Store followers table created</span><br>";
} else {
    echo "<span class='error'>✗ Error: " . $conn->error . "</span><br>";
}

// 7. CREATE STORE REVIEWS TABLE (NEW)
echo "<h4>3.7 Creating store_reviews table...</h4>";
$create_store_reviews = "CREATE TABLE store_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    store_id INT NOT NULL,
    reviewer_id INT NOT NULL,
    order_id INT NULL,
    rating INT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    review_text TEXT,
    response_text TEXT,
    response_date TIMESTAMP NULL,
    is_approved TINYINT(1) DEFAULT 1,
    helpful_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_store (store_id),
    INDEX idx_reviewer (reviewer_id),
    INDEX idx_rating (rating),
    INDEX idx_approved (is_approved)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_store_reviews)) {
    echo "<span class='success'>✓ Store reviews table created</span><br>";
} else {
    echo "<span class='error'>✗ Error: " . $conn->error . "</span><br>";
}

// 8. CREATE STORE ANALYTICS TABLE (NEW)
echo "<h4>3.8 Creating store_analytics table...</h4>";
$create_store_analytics = "CREATE TABLE store_analytics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    store_id INT NOT NULL,
    date DATE NOT NULL,
    views INT DEFAULT 0,
    unique_visitors INT DEFAULT 0,
    listing_views INT DEFAULT 0,
    inquiries INT DEFAULT 0,
    orders INT DEFAULT 0,
    revenue DECIMAL(15,2) DEFAULT 0,
    conversion_rate DECIMAL(5,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_store_date (store_id, date),
    INDEX idx_store (store_id),
    INDEX idx_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_store_analytics)) {
    echo "<span class='success'>✓ Store analytics table created</span><br>";
} else {
    echo "<span class='error'>✗ Error: " . $conn->error . "</span><br>";
}

// 9. CREATE PRODUCTS TABLE
echo "<h4>3.9 Creating products table...</h4>";
$create_products = "CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    compare_price DECIMAL(10,2),
    cost_per_item DECIMAL(10,2),
    category_id INT,
    vendor_id INT,
    sku VARCHAR(100) UNIQUE,
    barcode VARCHAR(100),
    quantity INT DEFAULT 0,
    track_quantity TINYINT(1) DEFAULT 1,
    allow_backorders TINYINT(1) DEFAULT 0,
    weight DECIMAL(8,2),
    dimensions VARCHAR(100),
    featured TINYINT(1) DEFAULT 0,
    status ENUM('active','draft','archived') DEFAULT 'active',
    meta_title VARCHAR(255),
    meta_description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (vendor_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_category (category_id),
    INDEX idx_vendor (vendor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_products)) {
    echo "<span class='success'>✓ Products table created</span><br>";
} else {
    echo "<span class='error'>✗ Error: " . $conn->error . "</span><br>";
}

// 10. CREATE PRODUCT IMAGES TABLE
echo "<h4>3.10 Creating product_images table...</h4>";
$create_product_images = "CREATE TABLE product_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT,
    image_url VARCHAR(255) NOT NULL,
    is_primary TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_product_images)) {
    echo "<span class='success'>✓ Product images table created</span><br>";
} else {
    echo "<span class='error'>✗ Error: " . $conn->error . "</span><br>";
}

// 11. CREATE PRODUCT SUBMISSIONS TABLE
echo "<h4>3.11 Creating product_submissions table...</h4>";
$create_product_submissions = "CREATE TABLE product_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    image_url VARCHAR(500),
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    admin_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (vendor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_product_submissions)) {
    echo "<span class='success'>✓ Product submissions table created</span><br>";
} else {
    echo "<span class='error'>✗ Error: " . $conn->error . "</span><br>";
}

// 12. CREATE PRODUCT REVIEWS TABLE
echo "<h4>3.12 Creating product_reviews table...</h4>";
$create_product_reviews = "CREATE TABLE product_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT,
    user_id INT,
    rating INT CHECK (rating >= 1 AND rating <= 5),
    review_text TEXT,
    is_approved TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_product (product_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_product_reviews)) {
    echo "<span class='success'>✓ Product reviews table created</span><br>";
} else {
    echo "<span class='error'>✗ Error: " . $conn->error . "</span><br>";
}

// 13. CREATE CART TABLE
echo "<h4>3.13 Creating cart table...</h4>";
$create_cart = "CREATE TABLE cart (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    product_id INT,
    quantity INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_cart)) {
    echo "<span class='success'>✓ Cart table created</span><br>";
} else {
    echo "<span class='error'>✗ Error: " . $conn->error . "</span><br>";
}

// 14. CREATE CART ITEMS TABLE
echo "<h4>3.14 Creating cart_items table...</h4>";
$create_cart_items = "CREATE TABLE cart_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    listing_id INT,
    quantity INT DEFAULT 1,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_cart_items)) {
    echo "<span class='success'>✓ Cart items table created</span><br>";
} else {
    echo "<span class='error'>✗ Error: " . $conn->error . "</span><br>";
}

// 15. CREATE ORDERS TABLE
echo "<h4>3.15 Creating orders table...</h4>";
$create_orders = "CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(50) NOT NULL UNIQUE,
    user_id INT,
    store_id INT,
    total_amount DECIMAL(10,2) NOT NULL,
    status ENUM('pending','confirmed','processing','shipped','delivered','cancelled') DEFAULT 'pending',
    payment_status ENUM('pending','paid','failed','refunded') DEFAULT 'pending',
    shipping_address TEXT,
    billing_address TEXT,
    shipping_method VARCHAR(100),
    payment_method VARCHAR(100),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (store_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_store (store_id),
    INDEX idx_order_number (order_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_orders)) {
    echo "<span class='success'>✓ Orders table created</span><br>";
} else {
    echo "<span class='error'>✗ Error: " . $conn->error . "</span><br>";
}

// 16. CREATE ORDER ITEMS TABLE
echo "<h4>3.16 Creating order_items table...</h4>";
$create_order_items = "CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT,
    product_id INT,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    total_price DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    INDEX idx_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_order_items)) {
    echo "<span class='success'>✓ Order items table created</span><br>";
} else {
    echo "<span class='error'>✗ Error: " . $conn->error . "</span><br>";
}

// 17. CREATE FAVORITES TABLE
echo "<h4>3.17 Creating favorites table...</h4>";
$create_favorites = "CREATE TABLE favorites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    listing_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    UNIQUE KEY unique_favorite (user_id, listing_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_favorites)) {
    echo "<span class='success'>✓ Favorites table created</span><br>";
} else {
    echo "<span class='error'>✗ Error: " . $conn->error . "</span><br>";
}

// 18. CREATE WISHLIST TABLE
echo "<h4>3.18 Creating wishlist table...</h4>";
$create_wishlist = "CREATE TABLE wishlist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    product_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_wishlist)) {
    echo "<span class='success'>✓ Wishlist table created</span><br>";
} else {
    echo "<span class='error'>✗ Error: " . $conn->error . "</span><br>";
}

// 19. CREATE MESSAGES TABLE
echo "<h4>3.19 Creating messages table...</h4>";
$create_messages = "CREATE TABLE messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    listing_id INT,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE SET NULL,
    INDEX idx_sender (sender_id),
    INDEX idx_receiver (receiver_id),
    INDEX idx_listing (listing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_messages)) {
    echo "<span class='success'>✓ Messages table created</span><br>";
} else {
    echo "<span class='error'>✗ Error: " . $conn->error . "</span><br>";
}

// 20. CREATE JOB APPLICATIONS TABLE
echo "<h4>3.20 Creating job_applications table...</h4>";
$create_job_applications = "CREATE TABLE job_applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id INT,
    user_id INT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    resume_path VARCHAR(255),
    cover_letter TEXT,
    status ENUM('pending','reviewed','shortlisted','rejected','hired') DEFAULT 'pending',
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_job_applications)) {
    echo "<span class='success'>✓ Job applications table created</span><br>";
} else {
    echo "<span class='error'>✗ Error: " . $conn->error . "</span><br>";
}

// 21. CREATE PROPERTY INQUIRIES TABLE
echo "<h4>3.21 Creating property_inquiries table...</h4>";
$create_property_inquiries = "CREATE TABLE property_inquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT,
    user_id INT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    message TEXT,
    inquiry_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('new','contacted','viewed','closed') DEFAULT 'new',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_property_inquiries)) {
    echo "<span class='success'>✓ Property inquiries table created</span><br>";
} else {
    echo "<span class='error'>✗ Error: " . $conn->error . "</span><br>";
}

// 22. CREATE COUPONS TABLE
echo "<h4>3.22 Creating coupons table...</h4>";
$create_coupons = "CREATE TABLE coupons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    discount_type ENUM('percentage','fixed') DEFAULT 'percentage',
    discount_value DECIMAL(10,2) NOT NULL,
    min_order_amount DECIMAL(10,2) DEFAULT 0.00,
    max_discount_amount DECIMAL(10,2),
    usage_limit INT,
    used_count INT DEFAULT 0,
    valid_from DATE,
    valid_until DATE,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_coupons)) {
    echo "<span class='success'>✓ Coupons table created</span><br>";
} else {
    echo "<span class='error'>✗ Error: " . $conn->error . "</span><br>";
}

// 23. CREATE NEWSLETTER SUBSCRIBERS TABLE
echo "<h4>3.23 Creating newsletter_subscribers table...</h4>";
$create_newsletter = "CREATE TABLE newsletter_subscribers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL UNIQUE,
    subscribed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active','unsubscribed') DEFAULT 'active',
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_newsletter)) {
    echo "<span class='success'>✓ Newsletter subscribers table created</span><br>";
} else {
    echo "<span class='error'>✗ Error: " . $conn->error . "</span><br>";
}

// 24. CREATE SITE SETTINGS TABLE
echo "<h4>3.24 Creating site_settings table...</h4>";
$create_site_settings = "CREATE TABLE site_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    category VARCHAR(50) DEFAULT 'general',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_site_settings)) {
    echo "<span class='success'>✓ Site settings table created</span><br>";
} else {
    echo "<span class='error'>✗ Error: " . $conn->error . "</span><br>";
}

// 25. CREATE TESTIMONIALS TABLE
echo "<h4>3.25 Creating testimonials table...</h4>";
$create_testimonials = "CREATE TABLE testimonials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    author VARCHAR(100) NOT NULL,
    content TEXT NOT NULL,
    position VARCHAR(100),
    rating INT,
    status ENUM('pending','approved') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_testimonials)) {
    echo "<span class='success'>✓ Testimonials table created</span><br>";
} else {
    echo "<span class='error'>✗ Error: " . $conn->error . "</span><br>";
}

echo "</div>";

// ===== INSERT SAMPLE/DEFAULT DATA =====
echo "<div class='section'>";
echo "<h3>Step 4: Inserting Default Data</h3>";

// Insert admin user
echo "<h4>4.1 Creating admin user...</h4>";
$admin_password = password_hash('admin123', PASSWORD_DEFAULT);
$insert_admin = "INSERT INTO users (username, email, password, first_name, last_name, role, is_admin, status, is_vendor, store_verified) VALUES 
    ('admin', 'admin@ebonyimarkethub.com', '$admin_password', 'System', 'Administrator', 'admin', 1, 'active', 1, 1)";

if ($conn->query($insert_admin)) {
    $admin_id = $conn->insert_id;
    echo "<span class='success'>✓ Admin user created (ID: $admin_id)</span><br>";
    
    // Create store settings for admin
    $insert_store = "INSERT INTO store_settings (user_id, store_name, store_slogan, store_description, store_verified) VALUES 
        ($admin_id, 'Ebonyi Market Hub Official', 'Your Trusted Marketplace', 'Official store of Ebonyi Market Hub', 1)";
    
    if ($conn->query($insert_store)) {
        echo "<span class='success'>✓ Admin store settings created</span><br>";
    } else {
        echo "<span class='error'>✗ Error creating admin store: " . $conn->error . "</span><br>";
    }
} else {
    echo "<span class='error'>✗ Error creating admin: " . $conn->error . "</span><br>";
}

// Insert site settings
echo "<h4>4.2 Creating site settings...</h4>";
$insert_settings = "INSERT INTO site_settings (setting_key, setting_value, category) VALUES 
    ('site_name', 'Ebonyi Market Hub', 'general'),
    ('site_email', 'info@ebonyimarkethub.com', 'general'),
    ('currency', 'NGN', 'general'),
    ('currency_symbol', '₦', 'general'),
    ('enable_storefronts', '1', 'storefront'),
    ('enable_vendor_registration', '1', 'storefront'),
    ('store_verification_required', '1', 'storefront'),
    ('max_products_free', '5', 'storefront'),
    ('max_products_premium', '100', 'storefront')";

if ($conn->query($insert_settings)) {
    echo "<span class='success'>✓ Site settings created</span><br>";
} else {
    echo "<span class='success'>✓ Site settings created (some may already exist)</span><br>";
}

// Insert sample categories
echo "<h4>4.3 Creating sample categories...</h4>";
$insert_categories = "INSERT INTO categories (name, description, icon, status) VALUES 
    ('Real Estate', 'Properties for sale and rent', 'fa-home', 'active'),
    ('Electronics', 'Phones, laptops, and gadgets', 'fa-mobile', 'active'),
    ('Fashion', 'Clothing, shoes, and accessories', 'fa-tshirt', 'active'),
    ('Vehicles', 'Cars, motorcycles, and parts', 'fa-car', 'active'),
    ('Jobs', 'Employment opportunities', 'fa-briefcase', 'active'),
    ('Services', 'Professional services', 'fa-cogs', 'active'),
    ('Furniture', 'Home and office furniture', 'fa-couch', 'active'),
    ('Books', 'Books, magazines, and educational materials', 'fa-book', 'active')";

if ($conn->query($insert_categories)) {
    echo "<span class='success'>✓ Sample categories created</span><br>";
} else {
    echo "<span class='error'>✗ Error creating categories: " . $conn->error . "</span><br>";
}

// Insert sample products
echo "<h4>4.4 Creating sample products...</h4>";
$insert_products = "INSERT INTO products (name, description, price, vendor_id, status) VALUES 
    ('Sample Product 1', 'This is a sample product description', 5000.00, $admin_id, 'active'),
    ('Sample Product 2', 'Another sample product', 7500.00, $admin_id, 'active'),
    ('Sample Product 3', 'Third sample product', 12000.00, $admin_id, 'active')";

if ($conn->query($insert_products)) {
    echo "<span class='success'>✓ Sample products created</span><br>";
} else {
    echo "<span class='error'>✗ Error creating products: " . $conn->error . "</span><br>";
}

// Insert sample store reviews
echo "<h4>4.5 Creating sample store reviews...</h4>";
$insert_reviews = "INSERT INTO store_reviews (store_id, reviewer_id, rating, review_text, is_approved) VALUES 
    ($admin_id, $admin_id, 5, 'Excellent store! Highly recommended.', 1),
    ($admin_id, $admin_id, 4, 'Good products and fast delivery.', 1)";

if ($conn->query($insert_reviews)) {
    echo "<span class='success'>✓ Sample store reviews created</span><br>";
} else {
    echo "<span class='error'>✗ Error creating reviews: " . $conn->error . "</span><br>";
}

echo "</div>";

// ===== FINAL VERIFICATION =====
echo "<div class='section'>";
echo "<h3>Step 5: Final Verification</h3>";

// Check all tables were created
$required_tables = [
    'users', 'categories', 'listings', 'listing_images', 
    'store_settings', 'store_followers', 'store_reviews', 'store_analytics',
    'products', 'product_images', 'product_submissions', 'product_reviews',
    'cart', 'cart_items', 'orders', 'order_items',
    'favorites', 'wishlist', 'messages', 'job_applications',
    'property_inquiries', 'coupons', 'newsletter_subscribers', 'site_settings', 'testimonials'
];

echo "<table>";
echo "<tr><th>Table Name</th><th>Status</th><th>Records</th></tr>";

$all_good = true;
$total_tables = 0;
$successful_tables = 0;

foreach ($required_tables as $table) {
    $total_tables++;
    $check = $conn->query("SHOW TABLES LIKE '$table'");
    
    if ($check && $check->num_rows > 0) {
        // Count records
        $count_result = $conn->query("SELECT COUNT(*) as count FROM $table");
        $count = $count_result->fetch_assoc()['count'];
        
        echo "<tr>";
        echo "<td><strong>$table</strong></td>";
        echo "<td><span class='success'>✓ EXISTS</span></td>";
        echo "<td>$count records</td>";
        echo "</tr>";
        
        $successful_tables++;
    } else {
        echo "<tr>";
        echo "<td><strong>$table</strong></td>";
        echo "<td><span class='error'>✗ MISSING</span></td>";
        echo "<td>-</td>";
        echo "</tr>";
        
        $all_good = false;
    }
}

echo "</table>";

echo "<br>";
echo "<div style='background-color: #e7f3ff; padding: 15px; border-left: 4px solid #2196F3;'>";

if ($all_good && $successful_tables == $total_tables) {
    echo "<h2 class='success'>✅ DATABASE SETUP COMPLETE!</h2>";
    echo "<p>All $successful_tables tables have been successfully created.</p>";
    
    echo "<h4>📊 Database Summary:</h4>";
    echo "<ul>";
    echo "<li><strong>Database Name:</strong> $dbname</li>";
    echo "<li><strong>Total Tables:</strong> $successful_tables</li>";
    echo "<li><strong>Character Set:</strong> utf8mb4</li>";
    echo "<li><strong>Collation:</strong> utf8mb4_unicode_ci</li>";
    echo "</ul>";
    
    echo "<h4>🔑 Default Admin Login:</h4>";
    echo "<ul>";
    echo "<li><strong>Username:</strong> <code>admin</code></li>";
    echo "<li><strong>Password:</strong> <code>admin123</code></li>";
    echo "<li><strong>Email:</strong> admin@ebonyimarkethub.com</li>";
    echo "</ul>";
    
    echo "<h4>🏪 Storefront Features Added:</h4>";
    echo "<ul>";
    echo "<li><strong>store_settings</strong> - Customize each store's appearance</li>";
    echo "<li><strong>store_followers</strong> - Users can follow their favorite stores</li>";
    echo "<li><strong>store_reviews</strong> - Customer reviews and ratings for stores</li>";
    echo "<li><strong>store_analytics</strong> - Track store performance metrics</li>";
    echo "<li><strong>store_verified</strong> - Verified store badge for trust</li>";
    echo "</ul>";
    
    echo "<h4>⚠️ IMPORTANT SECURITY NOTES:</h4>";
    echo "<ol>";
    echo "<li><strong>Change the admin password immediately after first login</strong></li>";
    echo "<li>This setup script should be deleted after successful installation</li>";
    echo "<li>Configure your database connection in your application's config file</li>";
    echo "<li>Review and customize site settings in the admin panel</li>";
    echo "</ol>";
    
    echo "<h4>📝 Next Steps:</h4>";
    echo "<ol>";
    echo "<li>Add more categories through the admin panel</li>";
    echo "<li>Create additional user accounts</li>";
    echo "<li>Start adding listings and products</li>";
    echo "<li>Configure email settings for notifications</li>";
    echo "<li>Set up payment gateways if needed</li>";
    echo "<li>Enable storefront features in site settings</li>";
    echo "</ol>";
    
} else {
    echo "<h2 class='error'>⚠ SETUP INCOMPLETE</h2>";
    echo "<p>Some tables are missing. Created: $successful_tables/$total_tables tables.</p>";
    echo "<p>Please check the errors above and try again.</p>";
}

echo "</div>";
echo "</div>";

// Show connection info
echo "<div class='section'>";
echo "<h3>Database Connection Information</h3>";
echo "<p>Use these details in your application configuration:</p>";
echo "<ul>";
echo "<li><strong>Host:</strong> $host</li>";
echo "<li><strong>Database:</strong> $dbname</li>";
echo "<li><strong>Username:</strong> $user</li>";
echo "<li><strong>Password:</strong> " . ($password ? '********' : '(empty)') . "</li>";
echo "</ul>";
echo "</div>";

// Close connection
$conn->close();

echo "<div style='margin-top: 20px; padding: 10px; background-color: #f0f0f0; text-align: center;'>";
echo "<small>Ebonyi Market Hub Database Setup Script | Run on: " . date('Y-m-d H:i:s') . "</small>";
echo "</div>";
?>