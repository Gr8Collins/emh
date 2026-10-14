<?php
session_start();
require 'config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// ============================================
// Handle profile picture upload - store in avatar_url
// ============================================
$avatar_path = null;

if (isset($_FILES['avatar_url']) && $_FILES['avatar_url']['error'] !== UPLOAD_ERR_NO_FILE) {

    $upload_error = $_FILES['avatar_url']['error'];

    if ($upload_error !== UPLOAD_ERR_OK) {
        // Give a specific reason instead of failing silently
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE   => 'The file is larger than this server allows (upload_max_filesize).',
            UPLOAD_ERR_FORM_SIZE  => 'The file is larger than the form allows.',
            UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded. Try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary folder for uploads.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write the file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A server extension blocked the upload.',
        ];
        $_SESSION['settings_message'] = 'Profile picture upload failed: ' . ($upload_errors[$upload_error] ?? 'Unknown upload error.');
        $_SESSION['settings_type'] = 'error';
    } else {
        $upload_dir = __DIR__ . '/uploads/avatars/';
        $relative_dir = 'uploads/avatars/';

        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
                $_SESSION['settings_message'] = 'Could not create uploads/avatars/ folder. Check folder permissions.';
                $_SESSION['settings_type'] = 'error';
            }
        }

        if (is_dir($upload_dir) && !is_writable($upload_dir)) {
            $_SESSION['settings_message'] = 'The uploads/avatars/ folder is not writable. Set permissions to 755 (or 775) on the server.';
            $_SESSION['settings_type'] = 'error';
        }

        if (is_dir($upload_dir) && is_writable($upload_dir)) {
            $file_ext = strtolower(pathinfo($_FILES['avatar_url']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $max_size = 2 * 1024 * 1024; // 2MB

            if (!in_array($file_ext, $allowed)) {
                $_SESSION['settings_message'] = 'Invalid file type. Use JPG, PNG, GIF or WEBP.';
                $_SESSION['settings_type'] = 'error';
            } elseif ($_FILES['avatar_url']['size'] > $max_size) {
                $_SESSION['settings_message'] = 'File is too large. Max size is 2MB.';
                $_SESSION['settings_type'] = 'error';
            } else {
                $filename = 'avatar_' . $user_id . '_' . time() . '.' . $file_ext;
                $full_path = $upload_dir . $filename;
                $relative_path = $relative_dir . $filename;

                if (move_uploaded_file($_FILES['avatar_url']['tmp_name'], $full_path)) {
                    // Delete old avatar file if one exists, to avoid piling up unused files
                    if (!empty($_SESSION['avatar_url']) && file_exists(__DIR__ . '/' . $_SESSION['avatar_url'])) {
                        @unlink(__DIR__ . '/' . $_SESSION['avatar_url']);
                    }
                    $avatar_path = $relative_path;
                } else {
                    $_SESSION['settings_message'] = 'Failed to move uploaded file. Check folder permissions.';
                    $_SESSION['settings_type'] = 'error';
                }
            }
        }
    }
}

// ============================================
// Profile fields
// ============================================
$first_name  = trim($_POST['first_name'] ?? '');
$last_name   = trim($_POST['last_name'] ?? '');
$email       = trim($_POST['email'] ?? '');
$phone       = trim($_POST['phone'] ?? '');
$store_name  = trim($_POST['store_name'] ?? '');
$store_slogan = trim($_POST['store_slogan'] ?? '');
$store_description = trim($_POST['store_description'] ?? '');

// ============================================
// Build dynamic prepared statement
// ============================================
$fields = [];   // column = ? fragments
$types  = '';   // bind_param type string
$values = [];   // bind_param values

if ($avatar_path !== null) {
    $fields[] = 'avatar_url = ?';
    $types   .= 's';
    $values[] = $avatar_path;
}

if ($first_name !== '') {
    $fields[] = 'first_name = ?';
    $types   .= 's';
    $values[] = $first_name;
}
if ($last_name !== '') {
    $fields[] = 'last_name = ?';
    $types   .= 's';
    $values[] = $last_name;
}
if ($email !== '') {
    $fields[] = 'email = ?';
    $types   .= 's';
    $values[] = $email;
}
if ($phone !== '') {
    $fields[] = 'phone = ?';
    $types   .= 's';
    $values[] = $phone;
}
if ($store_name !== '') {
    $fields[] = 'store_name = ?';
    $types   .= 's';
    $values[] = $store_name;
}
if ($store_slogan !== '') {
    $fields[] = 'store_slogan = ?';
    $types   .= 's';
    $values[] = $store_slogan;
}
if ($store_description !== '') {
    $fields[] = 'store_description = ?';
    $types   .= 's';
    $values[] = $store_description;
}

// WHERE id = ?
$types   .= 'i';
$values[] = $user_id;

$sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?";

if ($stmt = $conn->prepare($sql)) {

    // Reliable reference-based binding (works on every PHP version,
    // unlike spreading an array directly into bind_param).
    $bind_params = [$types];
    foreach ($values as $key => $value) {
        $bind_params[] = &$values[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind_params);

    if ($stmt->execute()) {
        // Update session AFTER a successful DB write, not before
        if ($avatar_path !== null) $_SESSION['avatar_url'] = $avatar_path;
        if ($first_name !== '') $_SESSION['first_name'] = $first_name;
        if ($last_name !== '') $_SESSION['last_name'] = $last_name;
        if ($email !== '') $_SESSION['email'] = $email;
        if ($phone !== '') $_SESSION['phone'] = $phone;
        if ($store_name !== '') $_SESSION['store_name'] = $store_name;
        if ($store_slogan !== '') $_SESSION['store_slogan'] = $store_slogan;
        if ($store_description !== '') $_SESSION['store_description'] = $store_description;

        // Don't overwrite an upload-validation error message set above
        if (!isset($_SESSION['settings_type']) || $_SESSION['settings_type'] !== 'error') {
            $_SESSION['settings_message'] = 'Settings updated successfully!';
            $_SESSION['settings_type'] = 'success';
        }
    } else {
        $_SESSION['settings_message'] = 'Error updating settings: ' . $stmt->error;
        $_SESSION['settings_type'] = 'error';
    }

    $stmt->close();
} else {
    $_SESSION['settings_message'] = 'Error preparing update: ' . $conn->error;
    $_SESSION['settings_type'] = 'error';
}

header('Location: dashboard.php');
exit();