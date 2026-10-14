<?php
session_start();
require 'config.php';

// Check if user is logged in
$isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

// Get search parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$location = isset($_GET['location']) ? trim($_GET['location']) : '';
$job_type = isset($_GET['job_type']) ? trim($_GET['job_type']) : '';
$category = isset($_GET['category']) ? trim($_GET['category']) : '';

// Build query with filters
$query = "SELECT * FROM listings WHERE category = 'job' AND status = 'active'";
$params = [];
$types = '';

if (!empty($search)) {
    $query .= " AND (title LIKE ? OR description LIKE ? OR company LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'sss';
}

if (!empty($location)) {
    $query .= " AND location LIKE ?";
    $params[] = "%$location%";
    $types .= 's';
}

if (!empty($job_type) && $job_type !== 'all') {
    $query .= " AND job_type = ?";
    $params[] = $job_type;
    $types .= 's';
}

if (!empty($category) && $category !== 'all') {
    $query .= " AND subcategory = ?";
    $params[] = $category;
    $types .= 's';
}

// Add sorting
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'newest';
switch ($sort) {
    case 'newest':
        $query .= " ORDER BY created_at DESC";
        break;
    case 'oldest':
        $query .= " ORDER BY created_at ASC";
        break;
    case 'salary_high':
        $query .= " ORDER BY salary DESC";
        break;
    case 'salary_low':
        $query .= " ORDER BY salary ASC";
        break;
    default:
        $query .= " ORDER BY created_at DESC";
}

// Prepare and execute query
$jobs = [];
if ($stmt = $conn->prepare($query)) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $jobs[] = $row;
        }
    }
    $stmt->close();
} else {
    // Log error for debugging
    error_log("Query preparation failed: " . $conn->error);
}

// Get unique job categories for filter
$categories = [];
$categories_result = $conn->query("SELECT DISTINCT subcategory FROM listings WHERE category = 'job' AND subcategory IS NOT NULL AND subcategory != ''");
if ($categories_result) {
    while ($row = $categories_result->fetch_assoc()) {
        $categories[] = $row['subcategory'];
    }
}

// Get unique locations for filter
$locations = [];
$locations_result = $conn->query("SELECT DISTINCT location FROM listings WHERE category = 'job' AND location IS NOT NULL AND location != ''");
if ($locations_result) {
    while ($row = $locations_result->fetch_assoc()) {
        $locations[] = $row['location'];
    }
}

// Get user details if logged in (for pre-filling application form)
$user_name = '';
$user_email = '';
$user_phone = '';

if ($isLoggedIn && isset($_SESSION['user_id'])) {
    $user_id = intval($_SESSION['user_id']);
    $user_query = $conn->prepare("SELECT username, email, phone FROM users WHERE id = ?");
    if ($user_query) {
        $user_query->bind_param("i", $user_id);
        $user_query->execute();
        $user_result = $user_query->get_result();
        if ($user_result && $user_row = $user_result->fetch_assoc()) {
            $user_name = $user_row['username'] ?? '';
            $user_email = $user_row['email'] ?? '';
            $user_phone = $user_row['phone'] ?? '';
        }
        $user_query->close();
    }
}

// Handle job application
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_job'])) {
    if (!$isLoggedIn) {
        $_SESSION['error'] = 'Please login to apply for jobs';
        header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit();
    }
    
    $job_id = intval($_POST['job_id']);
    $user_id = intval($_SESSION['user_id']);
    $applicant_name = trim($_POST['applicant_name']);
    $applicant_email = trim($_POST['applicant_email']);
    $applicant_phone = trim($_POST['applicant_phone']);
    $cover_letter = trim($_POST['cover_letter']);
    
    // If phone is empty, try to get it from user's database record
    if (empty($applicant_phone) && !empty($user_phone)) {
        $applicant_phone = $user_phone;
    }
    
    // Validate phone number
    if (empty($applicant_phone)) {
        $_SESSION['error'] = 'Phone number is required. Please update your profile or enter your phone number.';
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit();
    }
    
    // Handle file upload
    $resume_path = '';
    if (isset($_FILES['resume']) && $_FILES['resume']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        if (in_array($_FILES['resume']['type'], $allowed_types) && $_FILES['resume']['size'] <= $max_size) {
            $upload_dir = 'uploads/resumes/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_ext = pathinfo($_FILES['resume']['name'], PATHINFO_EXTENSION);
            $filename = 'resume_' . $user_id . '_' . time() . '.' . $file_ext;
            $resume_path = $upload_dir . $filename;
            
            if (!move_uploaded_file($_FILES['resume']['tmp_name'], $resume_path)) {
                $resume_path = '';
                $_SESSION['error'] = 'Failed to upload resume. Please try again.';
            }
        } else {
            $_SESSION['error'] = 'Invalid file type or file too large (max 5MB). Please upload PDF or DOC file.';
        }
    }
    
    // Save application to database
    if (!isset($_SESSION['error'])) {
        $stmt = $conn->prepare("INSERT INTO job_applications (job_id, user_id, name, email, phone, resume_path, cover_letter, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
        
        if ($stmt) {
            $stmt->bind_param("iisssss", $job_id, $user_id, $applicant_name, $applicant_email, $applicant_phone, $resume_path, $cover_letter);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = 'Application submitted successfully!';
            } else {
                $_SESSION['error'] = 'Failed to submit application. Please try again. Error: ' . $stmt->error;
            }
            $stmt->close();
        } else {
            $_SESSION['error'] = 'Database error. Please try again later. Error: ' . $conn->error;
        }
    }
    
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit();
}

// Get filter values from form
$job_type_filter = isset($_GET['job_type_filter']) ? $_GET['job_type_filter'] : '';
$location_filter = isset($_GET['location_filter']) ? $_GET['location_filter'] : '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Listings - Ebonyi Market Hub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #ff7b00;
            --primary-dark: #e66a00;
            --secondary: #2c3e50;
            --light: #f8f9fa;
            --dark: #343a40;
            --gray: #6c757d;
            --light-gray: #e9ecef;
        }
        
        body {
            background-color: #f5f5f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .navbar {
            background-color: white;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.1);
        }
        
        .top-info-bar {
            background: var(--secondary);
            color: white;
            padding: 0.5rem 5%;
            font-size: 0.9rem;
        }
        
        .info-items {
            display: flex;
            list-style: none;
            gap: 2rem;
            margin: 0;
            padding: 0;
        }
        
        .info-items a {
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .info-items a:hover {
            color: var(--primary);
        }
        
        .jobs-header {
            background: linear-gradient(rgba(0,0,0,0.7), rgba(0,0,0,0.7)), url('https://images.unsplash.com/photo-1507679799987-c73779587ccf?ixlib=rb-4.0.3&auto=format&fit=crop&w=1200&q=80');
            background-size: cover;
            background-position: center;
            color: white;
            padding: 80px 0;
            text-align: center;
        }
        
        .search-section {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-top: -50px;
            position: relative;
            z-index: 100;
        }
        
        .job-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            border: 1px solid #eee;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .job-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        
        .job-type-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 15px;
        }
        
        .full-time { background: #d4edda; color: #155724; }
        .part-time { background: #fff3cd; color: #856404; }
        .contract { background: #d1ecf1; color: #0c5460; }
        .freelance { background: #f8d7da; color: #721c24; }
        
        .job-salary {
            color: var(--primary);
            font-weight: 700;
            font-size: 1.1rem;
        }
        
        .company-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .company-logo {
            width: 50px;
            height: 50px;
            border-radius: 8px;
            overflow: hidden;
            background: var(--light-gray);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: var(--primary);
        }
        
        .company-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .filter-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
        }
        
        .filter-title {
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--secondary);
            padding-bottom: 10px;
            border-bottom: 2px solid var(--light-gray);
        }
        
        .btn-primary {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
            border-color: var(--primary-dark);
        }
        
        .btn-outline-primary {
            color: var(--primary);
            border-color: var(--primary);
        }
        
        .btn-outline-primary:hover {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        
        .results-count {
            color: var(--gray);
            font-size: 0.9rem;
        }
        
        .no-jobs {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 10px;
        }
        
        .no-jobs i {
            font-size: 60px;
            color: var(--light-gray);
            margin-bottom: 20px;
        }
        
        .apply-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
            font-weight: 600;
            transition: background 0.3s;
        }
        
        .apply-btn:hover {
            background: #218838;
            color: white;
        }
        
        .apply-btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }
        
        .job-meta {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        
        .job-meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
            color: var(--gray);
            font-size: 0.9rem;
        }
        
        @media (max-width: 768px) {
            .search-section {
                margin-top: 0;
                padding: 20px;
            }
            
            .jobs-header {
                padding: 50px 0;
            }
            
            .job-card {
                padding: 20px;
            }
            
            .job-meta {
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <!-- Top Info Bar -->
    <div class="top-info-bar">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <ul class="info-items">
                    <li><a href="#"><i class="fas fa-envelope"></i> info@ebonyimarkethub.com</a></li>
                    <li><a href="#"><i class="fas fa-phone"></i> (+234) 7039312901</a></li>
                </ul>
                <ul class="info-items">
                    <?php if ($isLoggedIn): ?>
                        <li><a href="#" class="logged-in"><i class="fas fa-user"></i> Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></a></li>
                        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    <?php else: ?>
                        <li><a href="login.php"><i class="fas fa-sign-in-alt"></i> Login</a></li>
                        <li><a href="register.php"><i class="fas fa-user-plus"></i> Register</a></li>
                    <?php endif; ?>
                    <li><a href="index.php#postJobModal" data-bs-toggle="modal"><i class="fas fa-plus"></i> Post a Job</a></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-light">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <img src="img/log.png" alt="Ebonyi Market Hub" height="50">
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">Home</a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                            Categories
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="properties.php">Real Estate</a></li>
                            <li><a class="dropdown-item" href="vehicles.php">Vehicles</a></li>
                            <li><a class="dropdown-item" href="electronics.php">Electronics</a></li>
                            <li><a class="dropdown-item active" href="jobs.php">Jobs</a></li>
                            <li><a class="dropdown-item" href="services.php">Services</a></li>
                        </ul>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="about.php">About Us</a></li>
                    <li class="nav-item">
                        <a class="nav-link" href="contact.php">Contact</a>
                    </li>
                    <li class="nav-item">
                        <a href="cart_items.php" class="nav-link">
                            <i class="fas fa-shopping-cart"></i> Cart
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Header Section -->
    <section class="jobs-header">
        <div class="container">
            <h1 class="display-4 mb-3">Find Your Dream Job</h1>
            <p class="lead mb-4">Browse through hundreds of job opportunities in Ebonyi State and beyond</p>
            <a href="index.php#postJobModal" class="btn btn-primary btn-lg" data-bs-toggle="modal">
                <i class="fas fa-plus"></i> Post a Job
            </a>
        </div>
    </section>

    <!-- Search Section -->
    <section class="container search-section">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-4">
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control" name="search" placeholder="Job title, company, or keyword" value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="col-md-3">
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-map-marker-alt"></i></span>
                    <input type="text" class="form-control" name="location" placeholder="Location" value="<?php echo htmlspecialchars($location); ?>">
                </div>
            </div>
            <div class="col-md-3">
                <select class="form-select" name="job_type">
                    <option value="all">All Job Types</option>
                    <option value="full-time" <?php echo $job_type === 'full-time' ? 'selected' : ''; ?>>Full-Time</option>
                    <option value="part-time" <?php echo $job_type === 'part-time' ? 'selected' : ''; ?>>Part-Time</option>
                    <option value="contract" <?php echo $job_type === 'contract' ? 'selected' : ''; ?>>Contract</option>
                    <option value="freelance" <?php echo $job_type === 'freelance' ? 'selected' : ''; ?>>Freelance</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i> Search
                </button>
            </div>
        </form>
    </section>

    <!-- Main Content -->
    <section class="container py-5">
        <div class="row">
            <!-- Filters Sidebar -->
            <div class="col-lg-3 mb-4">
                <div class="filter-card">
                    <h5 class="filter-title"><i class="fas fa-filter"></i> Filters</h5>
                    
                    <!-- Job Type Filter -->
                    <div class="mb-4">
                        <h6 class="fw-bold mb-3">Job Type</h6>
                        <form method="GET" action="" id="filterForm">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="job_type_filter" id="all_types" value="all" <?php echo (empty($job_type_filter) || $job_type_filter === 'all') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="all_types">All Types</label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="job_type_filter" id="full_time" value="full-time" <?php echo $job_type_filter === 'full-time' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="full_time">Full-Time</label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="job_type_filter" id="part_time" value="part-time" <?php echo $job_type_filter === 'part-time' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="part_time">Part-Time</label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="job_type_filter" id="contract" value="contract" <?php echo $job_type_filter === 'contract' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="contract">Contract</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="job_type_filter" id="freelance" value="freelance" <?php echo $job_type_filter === 'freelance' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="freelance">Freelance</label>
                            </div>
                            
                            <!-- Location Filter -->
                            <?php if (!empty($locations)): ?>
                            <div class="mb-4 mt-4">
                                <h6 class="fw-bold mb-3">Location</h6>
                                <select class="form-select" name="location_filter" id="location_filter">
                                    <option value="">All Locations</option>
                                    <?php foreach ($locations as $loc): ?>
                                    <option value="<?php echo htmlspecialchars($loc); ?>" <?php echo $location_filter === $loc ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($loc); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Category Filter -->
                            <?php if (!empty($categories)): ?>
                            <div class="mb-4">
                                <h6 class="fw-bold mb-3">Category</h6>
                                <select class="form-select" name="category" id="category_filter">
                                    <option value="all">All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category === $cat ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Sort Filter -->
                            <div class="mb-3">
                                <h6 class="fw-bold mb-3">Sort By</h6>
                                <select class="form-select" name="sort" id="sort_filter">
                                    <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                                    <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                                    <option value="salary_high" <?php echo $sort === 'salary_high' ? 'selected' : ''; ?>>Salary (High to Low)</option>
                                    <option value="salary_low" <?php echo $sort === 'salary_low' ? 'selected' : ''; ?>>Salary (Low to High)</option>
                                </select>
                            </div>
                            
                            <!-- Hidden fields for other filters -->
                            <?php if (!empty($search)): ?>
                                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                            <?php endif; ?>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">Apply Filters</button>
                                <a href="jobs.php" class="btn btn-outline-primary">
                                    <i class="fas fa-times"></i> Clear Filters
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Post Job CTA -->
                <div class="filter-card bg-primary text-white">
                    <h5 class="filter-title text-white">Looking to Hire?</h5>
                    <p class="mb-3">Post a job and find the perfect candidate for your company.</p>
                    <a href="index.php#postJobModal" class="btn btn-light w-100" data-bs-toggle="modal">
                        <i class="fas fa-plus"></i> Post a Job
                    </a>
                </div>
            </div>
            
            <!-- Job Listings -->
            <div class="col-lg-9">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4 class="mb-0">Job Listings</h4>
                    <div class="results-count">
                        <?php echo count($jobs); ?> job<?php echo count($jobs) !== 1 ? 's' : ''; ?> found
                    </div>
                </div>
                
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <?php echo $_SESSION['success']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?php echo $_SESSION['error']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>
                
                <?php if (empty($jobs)): ?>
                    <div class="no-jobs">
                        <i class="fas fa-briefcase"></i>
                        <h4 class="mt-3 mb-2">No Jobs Found</h4>
                        <p class="text-muted">Try adjusting your search filters or check back later for new opportunities.</p>
                        <a href="jobs.php" class="btn btn-primary mt-2">
                            <i class="fas fa-redo"></i> Clear Filters
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($jobs as $job): ?>
                    <div class="job-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="company-info">
                                <div class="company-logo">
                                    <?php if (!empty($job['company_logo'])): ?>
                                        <img src="<?php echo htmlspecialchars($job['company_logo']); ?>" alt="<?php echo htmlspecialchars($job['company']); ?>">
                                    <?php else: ?>
                                        <i class="fas fa-building"></i>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <h5 class="mb-1"><?php echo htmlspecialchars($job['title']); ?></h5>
                                    <p class="mb-0 text-muted"><?php echo htmlspecialchars($job['company'] ?? 'Company not specified'); ?></p>
                                </div>
                            </div>
                            <div class="text-end">
                                <?php if (!empty($job['salary'])): ?>
                                    <div class="job-salary mb-2">₦<?php echo number_format($job['salary'], 2); ?></div>
                                <?php endif; ?>
                                <span class="job-type-badge <?php echo htmlspecialchars($job['job_type'] ?? 'full-time'); ?>">
                                    <?php echo ucfirst(htmlspecialchars($job['job_type'] ?? 'Full-Time')); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="job-meta">
                            <div class="job-meta-item">
                                <i class="fas fa-map-marker-alt"></i>
                                <?php echo htmlspecialchars($job['location']); ?>
                            </div>
                            <div class="job-meta-item">
                                <i class="fas fa-clock"></i>
                                Posted <?php echo date('M d, Y', strtotime($job['created_at'])); ?>
                            </div>
                            <?php if (!empty($job['experience'])): ?>
                            <div class="job-meta-item">
                                <i class="fas fa-chart-line"></i>
                                <?php echo htmlspecialchars($job['experience']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <p class="mb-3"><?php echo htmlspecialchars(substr($job['description'] ?? 'No description available', 0, 200)); ?>...</p>
                        
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <?php if (!empty($job['tags'])): ?>
                                    <?php 
                                    $tags = explode(',', $job['tags']);
                                    foreach (array_slice($tags, 0, 3) as $tag):
                                    ?>
                                    <span class="badge bg-light text-dark me-1"><?php echo htmlspecialchars(trim($tag)); ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <div>
                                <button type="button" class="btn btn-outline-primary me-2" data-bs-toggle="modal" data-bs-target="#jobModal<?php echo $job['id']; ?>">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button type="button" class="btn btn-success apply-btn" data-bs-toggle="modal" data-bs-target="#applyModal<?php echo $job['id']; ?>" <?php echo !$isLoggedIn ? 'disabled' : ''; ?>>
                                    <i class="fas fa-paper-plane"></i> Apply Now
                                </button>
                                <?php if (!$isLoggedIn): ?>
                                    <small class="text-muted d-block mt-1">Login to apply</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Job Details Modal -->
                    <div class="modal fade" id="jobModal<?php echo $job['id']; ?>" tabindex="-1">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title"><?php echo htmlspecialchars($job['title']); ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="company-info mb-4">
                                        <div class="company-logo">
                                            <?php if (!empty($job['company_logo'])): ?>
                                                <img src="<?php echo htmlspecialchars($job['company_logo']); ?>" alt="<?php echo htmlspecialchars($job['company']); ?>">
                                            <?php else: ?>
                                                <i class="fas fa-building"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <h5 class="mb-1"><?php echo htmlspecialchars($job['company'] ?? 'Company not specified'); ?></h5>
                                            <p class="mb-0 text-muted">
                                                <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($job['location']); ?>
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-4">
                                        <span class="job-type-badge <?php echo htmlspecialchars($job['job_type'] ?? 'full-time'); ?> me-2">
                                            <?php echo ucfirst(htmlspecialchars($job['job_type'] ?? 'Full-Time')); ?>
                                        </span>
                                        <?php if (!empty($job['salary'])): ?>
                                            <span class="job-salary">₦<?php echo number_format($job['salary'], 2); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="mb-4">
                                        <h6>Job Description</h6>
                                        <p><?php echo nl2br(htmlspecialchars($job['description'] ?? 'No description available')); ?></p>
                                    </div>
                                    
                                    <?php if (!empty($job['requirements'])): ?>
                                    <div class="mb-4">
                                        <h6>Requirements</h6>
                                        <p><?php echo nl2br(htmlspecialchars($job['requirements'])); ?></p>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($job['benefits'])): ?>
                                    <div class="mb-4">
                                        <h6>Benefits</h6>
                                        <p><?php echo nl2br(htmlspecialchars($job['benefits'])); ?></p>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="row">
                                        <?php if (!empty($job['application_deadline'])): ?>
                                        <div class="col-md-6 mb-3">
                                            <h6>Application Deadline</h6>
                                            <p><?php echo date('F d, Y', strtotime($job['application_deadline'])); ?></p>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($job['contact_email'])): ?>
                                        <div class="col-md-6 mb-3">
                                            <h6>Contact Email</h6>
                                            <p><?php echo htmlspecialchars($job['contact_email']); ?></p>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if (!empty($job['tags'])): ?>
                                    <div class="mb-3">
                                        <h6>Tags</h6>
                                        <?php 
                                        $tags = explode(',', $job['tags']);
                                        foreach ($tags as $tag):
                                        ?>
                                        <span class="badge bg-light text-dark me-1"><?php echo htmlspecialchars(trim($tag)); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#applyModal<?php echo $job['id']; ?>" <?php echo !$isLoggedIn ? 'disabled' : ''; ?>>
                                        Apply Now
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Apply Job Modal -->
                    <div class="modal fade" id="applyModal<?php echo $job['id']; ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Apply for <?php echo htmlspecialchars($job['title']); ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <form method="POST" action="" enctype="multipart/form-data">
                                    <div class="modal-body">
                                        <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                                        <div class="mb-3">
                                            <label class="form-label">Full Name *</label>
                                            <input type="text" class="form-control" name="applicant_name" required value="<?php echo htmlspecialchars($user_name); ?>">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Email *</label>
                                            <input type="email" class="form-control" name="applicant_email" required value="<?php echo htmlspecialchars($user_email); ?>">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Phone Number *</label>
                                            <input type="tel" class="form-control" name="applicant_phone" required value="<?php echo htmlspecialchars($user_phone); ?>">
                                            <?php if (empty($user_phone)): ?>
                                                <small class="text-danger">
                                                    <i class="fas fa-exclamation-triangle"></i> 
                                                    Phone number not found in your profile. Please enter it below or update your profile.
                                                </small>
                                            <?php else: ?>
                                                <small class="text-muted">Phone number fetched from your profile</small>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Cover Letter</label>
                                            <textarea class="form-control" name="cover_letter" rows="4" placeholder="Tell us why you're the perfect candidate..."></textarea>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Resume/CV (PDF, DOC, DOCX - Max 5MB)</label>
                                            <input type="file" class="form-control" name="resume" accept=".pdf,.doc,.docx">
                                            <small class="text-muted">Optional but recommended</small>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" name="apply_job" class="btn btn-success">Submit Application</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <!-- Simple Pagination -->
                    <div class="d-flex justify-content-center mt-4">
                        <nav>
                            <ul class="pagination">
                                <li class="page-item disabled">
                                    <a class="page-link" href="#" aria-label="Previous">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                                <li class="page-item active"><a class="page-link" href="#">1</a></li>
                                <li class="page-item"><a class="page-link" href="#">2</a></li>
                                <li class="page-item"><a class="page-link" href="#">3</a></li>
                                <li class="page-item">
                                    <a class="page-link" href="#" aria-label="Next">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-dark text-white py-5">
        <div class="container">
            <div class="row">
                <div class="col-lg-4 mb-4">
                    <h5>Ebonyi Market Hub</h5>
                    <p class="text-muted">Your one-stop marketplace for buying, selling, finding jobs, and properties in Ebonyi State.</p>
                    <div class="social-links">
                        <a href="#" class="text-white me-3"><i class="fab fa-facebook"></i></a>
                        <a href="#" class="text-white me-3"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="text-white me-3"><i class="fab fa-instagram"></i></a>
                        <a href="#" class="text-white"><i class="fab fa-linkedin"></i></a>
                    </div>
                </div>
                <div class="col-lg-2 col-md-6 mb-4">
                    <h5>Quick Links</h5>
                    <ul class="list-unstyled">
                        <li><a href="index.php" class="text-muted text-decoration-none">Home</a></li>
                        <li><a href="about.php" class="text-muted text-decoration-none">About Us</a></li>
                        <li><a href="contact.php" class="text-muted text-decoration-none">Contact</a></li>
                        <li><a href="faq.php" class="text-muted text-decoration-none">FAQ</a></li>
                    </ul>
                </div>
                <div class="col-lg-2 col-md-6 mb-4">
                    <h5>Categories</h5>
                    <ul class="list-unstyled">
                        <li><a href="properties.php" class="text-muted text-decoration-none">Real Estate</a></li>
                        <li><a href="vehicles.php" class="text-muted text-decoration-none">Vehicles</a></li>
                        <li><a href="electronics.php" class="text-muted text-decoration-none">Electronics</a></li>
                        <li><a href="jobs.php" class="text-muted text-decoration-none">Jobs</a></li>
                    </ul>
                </div>
                <div class="col-lg-4 mb-4">
                    <h5>Contact Us</h5>
                    <ul class="list-unstyled text-muted">
                        <li><i class="fas fa-map-marker-alt me-2"></i> Abakaliki, Ebonyi State, Nigeria</li>
                        <li><i class="fas fa-phone me-2"></i> (+234) 7039312901</li>
                        <li><i class="fas fa-envelope me-2"></i> info@ebonyimarkethub.com</li>
                    </ul>
                </div>
            </div>
            <hr class="bg-light">
            <div class="text-center text-muted">
                <p>&copy; 2025 Ebonyi Market Hub. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
        
        // Update form submission for filter forms
        document.addEventListener('DOMContentLoaded', function() {
            // Handle radio button changes
            const radioButtons = document.querySelectorAll('input[name="job_type_filter"]');
            radioButtons.forEach(radio => {
                radio.addEventListener('change', function() {
                    document.getElementById('filterForm').submit();
                });
            });
            
            // Handle select changes
            const selectElements = document.querySelectorAll('#filterForm select');
            selectElements.forEach(select => {
                select.addEventListener('change', function() {
                    document.getElementById('filterForm').submit();
                });
            });
            
            // Auto-fill form data when modal opens (fallback)
            document.querySelectorAll('[data-bs-target^="#applyModal"]').forEach(button => {
                button.addEventListener('click', function() {
                    // Form is already pre-filled by PHP, but this is a backup
                    console.log('Apply button clicked');
                });
            });
        });
    </script>
</body>
</html>
<?php
// Close connection
$conn->close();
?>