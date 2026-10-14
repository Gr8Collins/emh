<?php
session_start();

// If already logged in as admin, redirect to dashboard
if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1) {
    header('Location: admindashboard.php');
    exit();
}

// Load config - adjust path as needed
require 'config.php';

$error = '';

if (isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    // Check for admin specifically
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? AND is_admin = 1 AND status = 'active'");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['is_admin'] = $user['is_admin'];
            $_SESSION['logged_in'] = true;
            
            header('Location: admindashboard.php');
            exit();
        } else {
            $error = 'Invalid password.';
        }
    } else {
        $error = 'Admin account not found.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Ebonyi Market Hub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-container {
            background: white;
            border-radius: 20px;
            padding: 3rem 2.5rem;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideUp 0.5s ease;
        }
        @keyframes slideUp {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .login-header {
            text-align: center;
            margin-bottom: 2.5rem;
        }
        .login-header .logo {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #ff7b00, #ff9500);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            color: white;
            font-size: 2.5rem;
            box-shadow: 0 5px 20px rgba(255,123,0,0.3);
        }
        .login-header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #2c3e50;
        }
        .login-header p {
            color: #6c757d;
            font-size: 0.95rem;
        }
        .form-control {
            border-radius: 12px;
            padding: 0.8rem 1rem;
            border: 2px solid #e9ecef;
            transition: all 0.3s ease;
        }
        .form-control:focus {
            border-color: #ff7b00;
            box-shadow: 0 0 0 3px rgba(255,123,0,0.1);
        }
        .btn-login {
            background: linear-gradient(135deg, #ff7b00, #ff9500);
            color: white;
            border: none;
            padding: 0.8rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            width: 100%;
            transition: all 0.3s ease;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(255,123,0,0.3);
            color: white;
        }
        .alert {
            border-radius: 12px;
            border: none;
        }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 1.5rem;
            color: #6c757d;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        .back-link:hover {
            color: #ff7b00;
        }
        .admin-badge {
            display: inline-block;
            background: #dc3545;
            color: white;
            padding: 2px 12px;
            border-radius: 50px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-left: 5px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo">
                <i class="fas fa-store"></i>
            </div>
            <h1>
                Admin Login
                <span class="admin-badge">Admin</span>
            </h1>
            <p>Access the admin control panel</p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="mb-3">
                <label class="form-label fw-bold">Email Address</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-2 border-end-0" style="border-radius: 12px 0 0 12px;">
                        <i class="fas fa-envelope text-muted"></i>
                    </span>
                    <input type="email" class="form-control border-2 border-start-0" 
                           name="email" placeholder="admin@ebonyimarkethub.com" 
                           value="admin@ebonyimarkethub.com" required 
                           style="border-radius: 0 12px 12px 0;">
                </div>
            </div>
            
            <div class="mb-4">
                <label class="form-label fw-bold">Password</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-2 border-end-0" style="border-radius: 12px 0 0 12px;">
                        <i class="fas fa-lock text-muted"></i>
                    </span>
                    <input type="password" class="form-control border-2 border-start-0" 
                           name="password" placeholder="••••••••" required 
                           style="border-radius: 0 12px 12px 0;" value="admin123">
                </div>
            </div>
            
            <button type="submit" name="login" class="btn-login">
                <i class="fas fa-sign-in-alt me-2"></i> Login to Admin Panel
            </button>
        </form>
        
        <a href="index.php" class="back-link">
            <i class="fas fa-arrow-left me-2"></i> Back to Website
        </a>
        
        <div class="text-center mt-3" style="font-size: 0.8rem; color: #6c757d;">
            <i class="fas fa-shield-alt me-1"></i> 
            Default: admin@ebonyimarkethub.com / admin123
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>