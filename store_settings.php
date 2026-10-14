<?php
session_start();
require 'config.php';

// Check if user is logged in and is a vendor
if (!isset($_SESSION['user_id']) || $_SESSION['is_vendor'] != 1) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Handle form submission
if (isset($_POST['update_store'])) {
    $store_name = trim($_POST['store_name']);
    $store_slogan = trim($_POST['store_slogan']);
    $store_description = trim($_POST['store_description']);
    $store_color_primary = trim($_POST['store_color_primary']);
    $store_color_secondary = trim($_POST['store_color_secondary']);
    $facebook_url = trim($_POST['facebook_url']);
    $twitter_url = trim($_POST['twitter_url']);
    $instagram_url = trim($_POST['instagram_url']);
    $whatsapp_number = trim($_POST['whatsapp_number']);
    
    $update_query = "UPDATE store_settings SET 
                     store_name = ?, 
                     store_slogan = ?, 
                     store_description = ?,
                     store_color_primary = ?,
                     store_color_secondary = ?,
                     facebook_url = ?,
                     twitter_url = ?,
                     instagram_url = ?,
                     whatsapp_number = ?
                     WHERE user_id = ?";
    
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param("sssssssssi", 
        $store_name, 
        $store_slogan, 
        $store_description,
        $store_color_primary,
        $store_color_secondary,
        $facebook_url,
        $twitter_url,
        $instagram_url,
        $whatsapp_number,
        $user_id
    );
    
    if ($stmt->execute()) {
        // Also update users table store_name
        $update_user = $conn->prepare("UPDATE users SET store_name = ? WHERE id = ?");
        $update_user->bind_param("si", $store_name, $user_id);
        $update_user->execute();
        
        $_SESSION['alert'] = ['type' => 'success', 'message' => 'Store settings updated successfully!'];
    } else {
        $_SESSION['alert'] = ['type' => 'danger', 'message' => 'Error updating store settings.'];
    }
    
    header('Location: store_settings.php');
    exit();
}

// Fetch current store settings
$settings_query = "SELECT * FROM store_settings WHERE user_id = ?";
$stmt = $conn->prepare($settings_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$settings = $stmt->get_result()->fetch_assoc();

if (!$settings) {
    // Create default settings if none exist
    $insert_query = "INSERT INTO store_settings (user_id, store_name) 
                     VALUES (?, ?)";
    $stmt = $conn->prepare($insert_query);
    $stmt->bind_param("is", $user_id, $_SESSION['username'] . "'s Store");
    $stmt->execute();
    header('Location: store_settings.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Store Settings</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body style="background: #f5f7fa; padding: 2rem;">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="fas fa-store text-primary"></i> Store Settings</h1>
            <a href="stores.php?user_id=<?php echo $user_id; ?>" class="btn btn-primary" target="_blank">
                <i class="fas fa-eye"></i> View Store
            </a>
        </div>
        
        <div class="row">
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <div class="text-center">
                            <div style="width: 100px; height: 100px; border-radius: 50%; background: var(--primary); margin: 0 auto; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; font-weight: 700; color: white;">
                                <?php echo strtoupper(substr($settings['store_name'] ?? $_SESSION['username'], 0, 2)); ?>
                            </div>
                            <h4 class="mt-3"><?php echo htmlspecialchars($settings['store_name']); ?></h4>
                            <p class="text-muted"><?php echo htmlspecialchars($settings['store_slogan'] ?? ''); ?></p>
                        </div>
                        <hr>
                        <div class="list-group">
                            <a href="#" class="list-group-item list-group-item-action active">
                                <i class="fas fa-cog"></i> Store Settings
                            </a>
                            <a href="dashboard.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-tachometer-alt"></i> Dashboard
                            </a>
                            <a href="stores.php?user_id=<?php echo $user_id; ?>" class="list-group-item list-group-item-action" target="_blank">
                                <i class="fas fa-store"></i> View Store
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-9">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-edit"></i> Edit Store Information</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label class="form-label">Store Name *</label>
                                <input type="text" class="form-control" name="store_name" 
                                       value="<?php echo htmlspecialchars($settings['store_name'] ?? $_SESSION['username']); ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Store Slogan</label>
                                <input type="text" class="form-control" name="store_slogan" 
                                       value="<?php echo htmlspecialchars($settings['store_slogan'] ?? ''); ?>" 
                                       placeholder="Your store tagline">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Store Description</label>
                                <textarea class="form-control" name="store_description" rows="4" 
                                          placeholder="Tell customers about your store"><?php echo htmlspecialchars($settings['store_description'] ?? ''); ?></textarea>
                            </div>
                            
                            <h5 class="mt-4">Colors</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Primary Color</label>
                                        <input type="color" class="form-control" name="store_color_primary" 
                                               value="<?php echo htmlspecialchars($settings['store_color_primary'] ?? '#ff7b00'); ?>" style="height: 50px;">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Secondary Color</label>
                                        <input type="color" class="form-control" name="store_color_secondary" 
                                               value="<?php echo htmlspecialchars($settings['store_color_secondary'] ?? '#2c3e50'); ?>" style="height: 50px;">
                                    </div>
                                </div>
                            </div>
                            
                            <h5 class="mt-4">Social Links</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label"><i class="fab fa-facebook text-primary"></i> Facebook</label>
                                        <input type="url" class="form-control" name="facebook_url" 
                                               value="<?php echo htmlspecialchars($settings['facebook_url'] ?? ''); ?>" 
                                               placeholder="https://facebook.com/yourpage">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label"><i class="fab fa-twitter text-info"></i> Twitter</label>
                                        <input type="url" class="form-control" name="twitter_url" 
                                               value="<?php echo htmlspecialchars($settings['twitter_url'] ?? ''); ?>" 
                                               placeholder="https://twitter.com/yourhandle">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label"><i class="fab fa-instagram text-danger"></i> Instagram</label>
                                        <input type="url" class="form-control" name="instagram_url" 
                                               value="<?php echo htmlspecialchars($settings['instagram_url'] ?? ''); ?>" 
                                               placeholder="https://instagram.com/yourpage">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label"><i class="fab fa-whatsapp text-success"></i> WhatsApp</label>
                                        <input type="text" class="form-control" name="whatsapp_number" 
                                               value="<?php echo htmlspecialchars($settings['whatsapp_number'] ?? ''); ?>" 
                                               placeholder="+2348012345678">
                                    </div>
                                </div>
                            </div>
                            
                            <button type="submit" name="update_store" class="btn btn-primary btn-lg mt-3">
                                <i class="fas fa-save"></i> Save Settings
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>