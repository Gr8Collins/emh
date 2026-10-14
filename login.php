<?php
session_start();
require 'config.php';

// ============================================
// CHECK FOR REDIRECT PARAMETER - ADD THIS AT THE TOP
// ============================================
if (isset($_GET['redirect']) && !empty($_GET['redirect'])) {
    $_SESSION['redirect_after_login'] = $_GET['redirect'];
}

$error = '';
$success = '';

// If already logged in, check for redirect or go to dashboard
if (isset($_SESSION['user_id']) && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    if (isset($_SESSION['redirect_after_login']) && !empty($_SESSION['redirect_after_login'])) {
        $redirect = $_SESSION['redirect_after_login'];
        unset($_SESSION['redirect_after_login']);
        header('Location: ' . $redirect);
        exit();
    } else {
        header('Location: dashboard.php');
        exit();
    }
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } else {
        $query = "SELECT id, username, email, password, status, role, is_vendor, 
                         first_name, last_name, phone, store_name, store_verified 
                  FROM users 
                  WHERE (email = ? OR username = ?) AND status = 'active'";

        $stmt = $conn->prepare($query);

        if ($stmt === false) {
            $error = 'Database error: ' . $conn->error;
        } else {
            $stmt->bind_param("ss", $email, $email);

            if (!$stmt->execute()) {
                $error = 'Database error: ' . $stmt->error;
            } else {
                $result = $stmt->get_result();

                if ($result->num_rows == 1) {
                    $user = $result->fetch_assoc();
                    $password_valid = false;

                    if (isset($user['password']) && !empty($user['password'])) {
                        if (password_verify($password, $user['password'])) {
                            $password_valid = true;
                        } elseif ($user['password'] === $password) {
                            // Legacy plain-text password: verify then upgrade to a hash
                            $password_valid = true;
                            $hashed = password_hash($password, PASSWORD_DEFAULT);
                            $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                            if ($update_stmt) {
                                $update_stmt->bind_param("si", $hashed, $user['id']);
                                $update_stmt->execute();
                                $update_stmt->close();
                            }
                        }
                    }

                    if ($password_valid) {
                        // Role always comes from the database
                        $actual_role = $user['role'] ?? 'buyer';
                        if (empty($actual_role) && isset($user['is_vendor']) && $user['is_vendor'] == 1) {
                            $actual_role = 'vendor';
                        }

                        // Set session variables
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['role'] = $actual_role;
                        $_SESSION['is_vendor'] = isset($user['is_vendor']) ? intval($user['is_vendor']) : 0;
                        $_SESSION['first_name'] = $user['first_name'] ?? '';
                        $_SESSION['last_name'] = $user['last_name'] ?? '';
                        $_SESSION['phone'] = $user['phone'] ?? '';
                        $_SESSION['store_name'] = $user['store_name'] ?? $user['username'];
                        $_SESSION['store_verified'] = isset($user['store_verified']) ? intval($user['store_verified']) : 0;
                        $_SESSION['logged_in'] = true;
                        $_SESSION['last_activity'] = time();

                        // Remember me
                        if ($remember) {
                            $token = bin2hex(random_bytes(32));
                            $expiry = date('Y-m-d H:i:s', strtotime('+30 days'));

                            $col_check = $conn->query("SHOW COLUMNS FROM users LIKE 'remember_token'");
                            if ($col_check && $col_check->num_rows > 0) {
                                $update_token = "UPDATE users SET remember_token = ?, remember_expiry = ? WHERE id = ?";
                                $rstmt = $conn->prepare($update_token);
                                if ($rstmt) {
                                    $rstmt->bind_param("ssi", $token, $expiry, $user['id']);
                                    $rstmt->execute();
                                    $rstmt->close();
                                }
                            }
                            setcookie('remember_token', $token, time() + (86400 * 30), '/', '', false, true);
                        }

                        // Log the login attempt if a login_logs table exists
                        $table_check = $conn->query("SHOW TABLES LIKE 'login_logs'");
                        if ($table_check && $table_check->num_rows > 0) {
                            $login_log = "INSERT INTO login_logs (user_id, ip_address, user_agent, login_time) VALUES (?, ?, ?, NOW())";
                            $lstmt = $conn->prepare($login_log);
                            if ($lstmt) {
                                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                                $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                                $lstmt->bind_param("iss", $user['id'], $ip, $user_agent);
                                $lstmt->execute();
                                $lstmt->close();
                            }
                        }

                        // ============================================
                        // CHECK FOR REDIRECT AFTER LOGIN
                        // ============================================
                        if (isset($_SESSION['redirect_after_login']) && !empty($_SESSION['redirect_after_login'])) {
                            $redirect = $_SESSION['redirect_after_login'];
                            unset($_SESSION['redirect_after_login']);
                            header('Location: ' . $redirect);
                            exit();
                        } else {
                            header('Location: dashboard.php');
                            exit();
                        }
                    } else {
                        $error = 'Invalid email/username or password.';
                    }
                } else {
                    $error = 'No active account found with this email/username.';
                }
                $stmt->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In — Ebonyi Market Hub</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ... ALL YOUR EXISTING CSS ... */
:root{
  --ink:#0e1a2b;
  --ink-soft:#152540;
  --paper:#ffffff;
  --paper-soft:#f6f7f9;
  --text:#101828;
  --text-muted:#667085;
  --border:#e4e7ec;
  --gold:#ff7b00;
  --gold-deep:#d96600;
  --line:rgba(255,255,255,.08);
  --radius:14px;
  --radius-sm:10px;
  --shadow:0 20px 60px rgba(14,26,43,.12);
  --font-display:'Sora',sans-serif;
  --font-body:'Inter',sans-serif;
}
*{margin:0;padding:0;box-sizing:border-box;}
body{
  font-family:var(--font-body);
  color:var(--text);
  background:var(--paper-soft);
  min-height:100vh;
}
.auth-shell{
  min-height:100vh;
  display:grid;
  grid-template-columns:minmax(0,44%) minmax(0,56%);
}

/* ============ LEFT / VISUAL PANE ============ */
.auth-visual{
  position:relative;
  background:linear-gradient(160deg,var(--ink) 0%,var(--ink-soft) 100%);
  color:#fff;
  padding:3rem 3rem 2.5rem;
  display:flex;
  flex-direction:column;
  justify-content:space-between;
  overflow:hidden;
}
.auth-visual::before{
  content:'';
  position:absolute; inset:0;
  background-image:
    repeating-linear-gradient(115deg, var(--line) 0 1px, transparent 1px 64px),
    repeating-linear-gradient(25deg, var(--line) 0 1px, transparent 1px 64px);
  opacity:.6;
  pointer-events:none;
}
.auth-visual::after{
  content:'';
  position:absolute;
  width:520px; height:520px;
  right:-180px; bottom:-180px;
  background:radial-gradient(circle, rgba(255,123,0,.18) 0%, transparent 70%);
  pointer-events:none;
}
.brand{
  position:relative; z-index:1;
  display:flex; align-items:center; gap:.75rem;
}
.brand-mark{
  width:44px; height:44px; border-radius:12px;
  background:var(--gold);
  display:flex; align-items:center; justify-content:center;
  font-family:var(--font-display); font-weight:800; font-size:1.1rem;
  box-shadow:0 8px 20px rgba(255,123,0,.35);
}
.brand-name{ font-family:var(--font-display); font-weight:700; font-size:1.05rem; letter-spacing:.01em; }
.brand-name small{ display:block; font-family:var(--font-body); font-weight:400; font-size:.72rem; color:rgba(255,255,255,.55); letter-spacing:.04em; text-transform:uppercase; margin-top:2px;}

.visual-copy{ position:relative; z-index:1; max-width:400px; }
.visual-copy .eyebrow{
  font-size:.72rem; text-transform:uppercase; letter-spacing:.14em; color:var(--gold);
  font-weight:600; margin-bottom:1rem; display:block;
}
.visual-copy h1{
  font-family:var(--font-display); font-weight:700; font-size:2.15rem; line-height:1.2;
  letter-spacing:-.01em; margin-bottom:1rem;
}
.visual-copy p{ color:rgba(255,255,255,.68); font-size:.95rem; line-height:1.6; }

.role-strip{ position:relative; z-index:1; display:flex; flex-direction:column; gap:.9rem; }
.role-strip .row{ display:flex; align-items:center; gap:.75rem; font-size:.85rem; color:rgba(255,255,255,.8);}
.role-strip .row i{ width:32px; height:32px; border-radius:8px; background:rgba(255,255,255,.08); display:flex; align-items:center; justify-content:center; color:var(--gold); font-size:.85rem;}
.visual-footer{ position:relative; z-index:1; font-size:.75rem; color:rgba(255,255,255,.4); }

/* ============ RIGHT / FORM PANE ============ */
.auth-form-pane{
  background:var(--paper);
  display:flex; align-items:center; justify-content:center;
  padding:2.5rem 2rem;
}
.auth-form{
  width:100%; max-width:400px;
}
.form-head{ margin-bottom:1.75rem; }
.form-head h2{ font-family:var(--font-display); font-weight:700; font-size:1.5rem; letter-spacing:-.01em; }
.form-head p{ color:var(--text-muted); font-size:.9rem; margin-top:.3rem; }

.alert{
  padding:.75rem 1rem; border-radius:var(--radius-sm); font-size:.85rem;
  margin-bottom:1.25rem; display:flex; align-items:flex-start; gap:.6rem;
  border:1px solid transparent;
}
.alert-error{ background:#fef2f1; color:#b3261e; border-color:#f6d3d1; }
.alert-success{ background:#eaf6ef; color:#1f7a5c; border-color:#c9e8d5; }
.alert i{ margin-top:2px; }

.field{ margin-bottom:1.15rem; }
.field label{ display:block; font-size:.8rem; font-weight:600; margin-bottom:.4rem; color:var(--text); }
.field .wrap{ position:relative; }
.field .wrap i.leading{ position:absolute; left:.95rem; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:.9rem; }
.field input{
  width:100%; padding:.75rem 1rem .75rem 2.65rem; border:1.5px solid var(--border);
  border-radius:var(--radius-sm); font-size:.9rem; font-family:var(--font-body);
  background:var(--paper-soft); color:var(--text); transition:all .18s ease;
}
.field input:focus{ outline:none; border-color:var(--gold); background:var(--paper); box-shadow:0 0 0 4px rgba(255,123,0,.1); }
.field .toggle-pw{
  position:absolute; right:.9rem; top:50%; transform:translateY(-50%);
  background:none; border:none; color:var(--text-muted); cursor:pointer; padding:.2rem;
}

.form-options{ display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; font-size:.82rem; }
.form-options label{ display:flex; align-items:center; gap:.45rem; color:var(--text-muted); cursor:pointer; }
.form-options input[type="checkbox"]{ width:15px; height:15px; accent-color:var(--gold); }
.form-options a{ color:var(--gold-deep); text-decoration:none; font-weight:600; }
.form-options a:hover{ text-decoration:underline; }

.btn-submit{
  width:100%; padding:.85rem; background:var(--gold); color:#fff; border:none;
  border-radius:var(--radius-sm); font-size:.92rem; font-weight:700; font-family:var(--font-body);
  cursor:pointer; transition:all .18s ease; letter-spacing:.01em;
}
.btn-submit:hover{ background:var(--gold-deep); transform:translateY(-1px); box-shadow:0 10px 24px rgba(255,123,0,.28); }
.btn-submit:disabled{ opacity:.7; cursor:not-allowed; transform:none; }

.form-foot{ text-align:center; margin-top:1.5rem; font-size:.87rem; color:var(--text-muted); }
.form-foot a{ color:var(--gold-deep); font-weight:700; text-decoration:none; }
.form-foot a:hover{ text-decoration:underline; }

@media (max-width:900px){
  .auth-shell{ grid-template-columns:1fr; }
  .auth-visual{ padding:2rem 1.5rem; }
  .visual-copy h1{ font-size:1.6rem; }
  .role-strip{ display:none; }
  .auth-form-pane{ padding:2rem 1.25rem 3rem; }
}
</style>
</head>
<body>

<div class="auth-shell">

  <!-- Visual / brand pane -->
  <aside class="auth-visual">
    <div class="brand">
      <div class="brand-mark">EM</div>
      <div class="brand-name">Ebonyi Market Hub<small>Property &amp; Marketplace Network</small></div>
    </div>

    <div class="visual-copy">
      <span class="eyebrow">Ebonyi State, Nigeria</span>
      <h1>One account.<br>Every side of the market.</h1>
      <p>Sign in once — your dashboard automatically shows what matters for your account, whether you buy, sell, let or rent.</p>
    </div>

    <div class="role-strip">
      <div class="row"><i class="fas fa-user"></i> Buyers browse and purchase listings</div>
      <div class="row"><i class="fas fa-store"></i> Vendors manage products and orders</div>
      <div class="row"><i class="fas fa-building"></i> Landlords list and track rentals</div>
      <div class="row"><i class="fas fa-home"></i> Tenants search and enquire directly</div>
    </div>

    <div class="visual-footer">&copy; <?php echo date('Y'); ?> Ebonyi Market Hub. All rights reserved.</div>
  </aside>

  <!-- Form pane -->
  <main class="auth-form-pane">
    <div class="auth-form">
      <div class="form-head">
        <h2>Sign in</h2>
        <p>Enter your details to access your dashboard.</p>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($error); ?></span></div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?php echo htmlspecialchars($success); ?></span></div>
      <?php endif; ?>

      <form method="POST" action="" id="loginForm" onsubmit="return validateForm()">

        <div class="field">
          <label for="email">Email or username</label>
          <div class="wrap">
            <i class="fas fa-envelope leading"></i>
            <input type="text" id="email" name="email" placeholder="you@example.com"
                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
          </div>
        </div>

        <div class="field">
          <label for="password">Password</label>
          <div class="wrap">
            <i class="fas fa-lock leading"></i>
            <input type="password" id="password" name="password" placeholder="••••••••" required>
            <button type="button" class="toggle-pw" onclick="togglePassword()"><i class="fas fa-eye"></i></button>
          </div>
        </div>

        <div class="form-options">
          <label><input type="checkbox" name="remember" <?php echo isset($_POST['remember'])?'checked':''; ?>> Remember me</label>
          <a href="forgot_password.php">Forgot password?</a>
        </div>

        <button type="submit" class="btn-submit" id="loginBtn">Sign in</button>
      </form>

      <div class="form-foot">Don't have an account? <a href="index.php">Create one</a></div>
    </div>
  </main>

</div>

<script>
function togglePassword(){
  const pw = document.getElementById('password');
  const icon = document.querySelector('.toggle-pw i');
  if(pw.type === 'password'){ pw.type='text'; icon.className='fas fa-eye-slash'; }
  else { pw.type='password'; icon.className='fas fa-eye'; }
}
function validateForm(){
  const email = document.getElementById('email').value.trim();
  const password = document.getElementById('password').value;
  if(!email || !password){ alert('Please fill in all fields.'); return false; }
  if(password.length < 4){ alert('Password must be at least 4 characters.'); return false; }
  const btn = document.getElementById('loginBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Signing in...';
  return true;
}
</script>

</body>
</html>