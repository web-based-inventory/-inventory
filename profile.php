<?php
include "includes/auth_check.php";
include "config/database.php";
include "config/helpers.php";
$page_title = "My Profile";

$success = '';
$error = '';

// Ensure profile_image column exists
$col_check = $conn->query("SHOW COLUMNS FROM users LIKE 'profile_image'");
if ($col_check && $col_check->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN profile_image VARCHAR(255) DEFAULT NULL AFTER email");
}

// Fetch current user data
$stmt = $conn->prepare("SELECT id, name, username, email, profile_image, role, created_at FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    header("Location: dashboard/index.php");
    exit;
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $errors = [];

    if (empty($name)) {
        $errors[] = 'Full name is required.';
    }
    if (empty($username)) {
        $errors[] = 'Username is required.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors[] = 'Username can only contain letters, numbers, and underscores.';
    }
    if (empty($email)) {
        $errors[] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format.';
    }

    // Check username uniqueness (exclude current user)
    if (empty($errors) && $username !== $user['username']) {
        $check = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
        $check->bind_param("si", $username, $_SESSION['user_id']);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $errors[] = 'Username is already taken.';
        }
        $check->close();
    }

    // Check email uniqueness (exclude current user)
    if (empty($errors) && $email !== $user['email']) {
        $check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
        $check->bind_param("si", $email, $_SESSION['user_id']);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $errors[] = 'Email is already in use by another account.';
        }
        $check->close();
    }

    // Handle profile image upload
    $profile_img = $user['profile_image'];
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $file_ext = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
        if (!in_array($file_ext, $allowed)) {
            $errors[] = 'Profile image must be JPG, JPEG, PNG, or WebP.';
        } elseif ($_FILES['profile_image']['size'] > 2 * 1024 * 1024) {
            $errors[] = 'Profile image must be under 2MB.';
        } else {
            $upload_dir = 'uploads/profile/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $filename = 'user_' . $_SESSION['user_id'] . '_' . time() . '.' . $file_ext;
            $dest = $upload_dir . $filename;
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $dest)) {
                // Delete old image if exists
                if ($profile_img && file_exists($profile_img)) {
                    unlink($profile_img);
                }
                $profile_img = $dest;
            } else {
                $errors[] = 'Failed to upload profile image.';
            }
        }
    }

    if (empty($errors)) {
        $update = $conn->prepare("UPDATE users SET name = ?, username = ?, profile_image = ? WHERE id = ?");
        $update->bind_param("sssi", $name, $username, $profile_img, $_SESSION['user_id']);
        if ($update->execute()) {
            $_SESSION['name'] = $name;
            $_SESSION['username'] = $username;
            $_SESSION['profile_image'] = $profile_img;
            $user['name'] = $name;
            $user['username'] = $username;
            $user['profile_image'] = $profile_img;
            $success = 'Profile updated successfully.';
        } else {
            $error = 'Failed to update profile.';
        }
        $update->close();
    } else {
        $error = implode(' ', $errors);
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - <?= htmlspecialchars(getShopSettings($conn)['shop_name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include "includes/theme-init.php"; ?>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body class="bg-gray-50 dark:bg-slate-900">
    <div class="flex min-h-screen">
        <?php include "includes/sidebar.php"; ?>
        <div class="flex-1 flex flex-col min-w-0">
            <?php include "includes/header.php"; ?>
            <main class="p-4 lg:p-6">
                <div class="max-w-3xl mx-auto">
                    <div class="card">
                        <div class="card-header">
                            <h2 class="text-base font-bold text-gray-900 dark:text-gray-100">Profile Information</h2>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Manage your personal details</p>
                        </div>
                        <div class="card-body">
                            <form method="POST" enctype="multipart/form-data" class="space-y-6" data-form-guard="true">
                                <!-- Profile Image -->
                                <div class="flex items-center gap-6">
                                    <div class="relative">
                                        <?php if (!empty($user['profile_image']) && file_exists($user['profile_image'])): ?>
                                            <img src="<?= htmlspecialchars($user['profile_image']) ?>" alt="Profile"
                                                class="w-20 h-20 rounded-full object-cover border-2 border-gray-200">
                                        <?php else: ?>
                                            <div class="w-20 h-20 bg-indigo-600 rounded-full flex items-center justify-center text-white font-bold text-2xl">
                                                <?= strtoupper(substr($user['name'], 0, 1)) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Profile Image</label>
                                        <input type="file" name="profile_image" accept="image/jpeg,image/png,image/webp"
                                            class="block w-full text-sm text-gray-500 dark:text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 transition">
                                        <p class="text-xs text-gray-400 mt-1">JPG, JPEG, PNG, WebP. Max 2MB.</p>
                                    </div>
                                </div>

                                <hr class="border-gray-100">

                                <!-- Full Name -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Full Name</label>
                                    <input type="text" name="name" value="<?= htmlspecialchars($user['name']) ?>" required
                                        class="w-full border rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                </div>

                                <!-- Username -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Username</label>
                                    <input type="text" name="username" value="<?= htmlspecialchars($user['username']) ?>" required
                                        class="w-full border rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                </div>

                                <!--Email-->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Email</label>
                                    <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" readonly
                                        class="w-full border rounded-lg px-4 py-2.5 bg-gray-50 text-gray-500 dark:text-gray-400 cursor-not-allowed">
                                </div>

                                <!-- Role (read-only) -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Role</label>
                                    <input type="text" value="<?= ucfirst($user['role']) ?>" readonly
                                        class="w-full border rounded-lg px-4 py-2.5 bg-gray-50 text-gray-500 dark:text-gray-400 cursor-not-allowed">
                                </div>

                                <!-- Account Created -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Account Created</label>
                                    <input type="text" value="<?= date('F d, Y', strtotime($user['created_at'])) ?>" readonly
                                        class="w-full border rounded-lg px-4 py-2.5 bg-gray-50 text-gray-500 dark:text-gray-400 cursor-not-allowed">
                                </div>

                                <div class="flex items-center gap-3 pt-2">
                                    <button type="submit" name="update_profile"
                                        class="px-6 py-2.5 bg-indigo-600 text-white font-medium rounded-lg hover:bg-indigo-700 transition">
                                        Save Changes
                                    </button>
                                    <a href="dashboard/index.php"
                                        class="px-6 py-2.5 bg-gray-200 text-gray-700 font-medium rounded-lg hover:bg-gray-300 transition">
                                        Cancel
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Account Details Card -->
                    <div class="card mt-6">
                        <div class="card-header">
                            <h2 class="text-base font-bold text-gray-900 dark:text-gray-100">Account Details</h2>
                        </div>
                        <div class="card-body">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div class="bg-gray-50 rounded-lg p-4">
                                    <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide font-semibold">Status</p>
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100 mt-1">Active</p>
                                </div>
                                <div class="bg-gray-50 rounded-lg p-4">
                                    <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide font-semibold">Member Since</p>
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100 mt-1"><?= date('F d, Y', strtotime($user['created_at'])) ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <?php if ($success): ?>
        <script>
            showToast('success', '<?= htmlspecialchars($success, ENT_QUOTES) ?>');
        </script>
    <?php endif; ?>
    <?php if ($error): ?>
        <script>
            showToast('error', '<?= htmlspecialchars($error, ENT_QUOTES) ?>');
        </script>
    <?php endif; ?>

    <?php include "includes/toast.php"; ?>
    <?php include "includes/form_guard.php"; ?>
    <?php include "includes/footer.php"; ?>
</body>

</html>