<?php
include "includes/auth_check.php";
include "config/database.php";
include "config/helpers.php";
$page_title = "Change Password";

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $errors = [];

    // Fetch current user's password hash
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        $errors[] = 'User not found.';
    }

    // Verify current password
    if (empty($current_password)) {
        $errors[] = 'Current password is required.';
    } elseif (!password_verify($current_password, $user['password'])) {
        $errors[] = 'Current password is incorrect.';
    }

    // Validate new password
    if (empty($new_password)) {
        $errors[] = 'New password is required.';
    } elseif (strlen($new_password) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    }

    // Confirm password match
    if ($new_password !== $confirm_password) {
        $errors[] = 'New password and confirmation do not match.';
    }

    // Don't allow same password
    if (empty($errors) && password_verify($new_password, $user['password'])) {
        $errors[] = 'New password must be different from your current password.';
    }

    if (empty($errors)) {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $update->bind_param("si", $hashed, $_SESSION['user_id']);
        if ($update->execute()) {
            $success = 'Password changed successfully.';
        } else {
            $error = 'Failed to update password. Please try again.';
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
    <title>Change Password - <?= htmlspecialchars(getShopSettings($conn)['shop_name']) ?></title>
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
                <div class="max-w-2xl mx-auto">
                    <div class="card">
                        <div class="card-header">
                            <h2 class="text-base font-bold text-gray-900 dark:text-gray-100">Update Password</h2>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Ensure your account is using a strong password</p>
                        </div>
                        <div class="card-body">
                            <form method="POST" class="space-y-5" data-form-guard="true">
                                <!-- Current Password -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Current Password</label>
                                    <input type="password" name="current_password" required
                                        class="w-full border rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                        placeholder="Enter your current password">
                                </div>

                                <!-- New Password -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">New Password</label>
                                    <input type="password" name="new_password" required minlength="8"
                                        class="w-full border rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                        placeholder="Enter new password (min 8 characters)">
                                    <p class="text-xs text-gray-400 mt-1">Minimum 8 characters</p>
                                </div>

                                <!-- Confirm New Password -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Confirm New Password</label>
                                    <input type="password" name="confirm_password" required
                                        class="w-full border rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                        placeholder="Re-enter new password">
                                </div>

                                <div class="flex items-center gap-3 pt-2">
                                    <button type="submit" name="change_password"
                                        class="px-6 py-2.5 bg-indigo-600 text-white font-medium rounded-lg hover:bg-indigo-700 transition">
                                        Update Password
                                    </button>
                                    <a href="dashboard/index.php"
                                        class="px-6 py-2.5 bg-gray-200 text-gray-700 font-medium rounded-lg hover:bg-gray-300 transition">
                                        Cancel
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Password Tips -->
                    <div class="card mt-6">
                        <div class="card-header">
                            <h2 class="text-base font-bold text-gray-900 dark:text-gray-100">Password Tips</h2>
                        </div>
                        <div class="card-body">
                            <ul class="space-y-2 text-sm text-gray-600 dark:text-gray-400">
                                <li class="flex items-start gap-2">
                                    <svg class="w-4 h-4 text-indigo-500 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    Use at least 8 characters
                                </li>
                                <li class="flex items-start gap-2">
                                    <svg class="w-4 h-4 text-indigo-500 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    Mix uppercase, lowercase, numbers, and symbols
                                </li>
                                <li class="flex items-start gap-2">
                                    <svg class="w-4 h-4 text-indigo-500 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    Do not share your password with others
                                </li>
                                <li class="flex items-start gap-2">
                                    <svg class="w-4 h-4 text-indigo-500 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    Use a different password for each account
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <?php if ($success): ?>
    <script>showToast('success', '<?= htmlspecialchars($success, ENT_QUOTES) ?>');</script>
    <?php endif; ?>
    <?php if ($error): ?>
    <script>showToast('error', '<?= htmlspecialchars($error, ENT_QUOTES) ?>');</script>
    <?php endif; ?>

    <?php include "includes/toast.php"; ?>
    <?php include "includes/form_guard.php"; ?>
    <?php include "includes/footer.php"; ?>
</body>
</html>
