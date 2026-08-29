<?php
session_start();
require_once 'config/database.php';
require_once 'config/helpers.php';

$login_settings = getShopSettings($conn);
$login_shop_name = htmlspecialchars($login_settings['shop_name']);
$login_logo = trim($login_settings['logo'] ?? '');
$login_logo_path = !empty($login_logo) ? __DIR__ . '/img/' . $login_logo : '';
$login_has_logo = !empty($login_logo) && file_exists($login_logo_path);

$message = '';
$message_type = '';
$token = $_GET['token'] ?? '';
$is_valid_token = false;
$user_id = null;

if (empty($token)) {
    $message = 'Invalid or missing password reset token.';
    $message_type = 'error';
} else {
    $token_hash = hash('sha256', $token);
    
    // Check if token exists, is not used, and is not expired
    $stmt = $conn->prepare("SELECT id, user_id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1");
    $stmt->bind_param("s", $token_hash);
    $stmt->execute();
    $result = $stmt->get_result();
    $reset_record = $result->fetch_assoc();
    $stmt->close();
    
    if ($reset_record) {
        $is_valid_token = true;
        $user_id = $reset_record['user_id'];
    } else {
        $message = 'This password reset link is invalid or has expired.';
        $message_type = 'error';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_valid_token) {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($password) || empty($confirm_password)) {
        $message = 'Please enter and confirm your new password.';
        $message_type = 'error';
    } elseif ($password !== $confirm_password) {
        $message = 'Passwords do not match.';
        $message_type = 'error';
    } elseif (strlen($password) < 6) {
        $message = 'Password must be at least 6 characters long.';
        $message_type = 'error';
    } else {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Update user password
        $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $update_stmt->bind_param("si", $hashed_password, $user_id);
        
        if ($update_stmt->execute()) {
            // Mark token as used
            $mark_used = $conn->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?");
            $mark_used->bind_param("i", $reset_record['id']);
            $mark_used->execute();
            $mark_used->close();
            
            $message = 'Your password has been reset successfully. You can now login.';
            $message_type = 'success';
            $is_valid_token = false; // Hide the form
        } else {
            $message = 'An error occurred while resetting your password. Please try again.';
            $message_type = 'error';
        }
        $update_stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $login_shop_name ?> - Reset Password</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include "includes/theme-init.php"; ?>
    <style>
        .login-input:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
        }
        .dark .login-input:focus {
            border-color: #818cf8;
            box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.15);
        }
    </style>
</head>
<body class="bg-[#eaf4fc] dark:bg-slate-900 text-gray-900 dark:text-gray-100 min-h-screen flex items-center justify-center p-4">
    <div class="max-w-md w-full bg-white dark:bg-slate-800 rounded-3xl shadow-2xl overflow-hidden border border-gray-100 dark:border-slate-700">
        <div class="p-8 sm:p-10">
            <div class="text-center mb-8">
                <?php if ($login_has_logo): ?>
                    <img src="img/<?= htmlspecialchars($login_logo) ?>" alt="<?= $login_shop_name ?>" class="w-16 h-16 rounded-xl shadow-sm object-cover bg-white mx-auto mb-4">
                <?php else: ?>
                    <div class="w-16 h-16 bg-indigo-600 rounded-xl shadow-sm flex items-center justify-center mx-auto mb-4">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                        </svg>
                    </div>
                <?php endif; ?>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">Reset Password</h1>
                <p class="text-gray-500 dark:text-gray-400 text-sm">Enter your new password below.</p>
            </div>

            <?php if ($message): ?>
                <div class="flex items-center gap-2 px-4 py-3 rounded-xl mb-6 text-sm font-medium <?= $message_type === 'success' ? 'bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 text-green-600 dark:text-green-400' : 'bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-600 dark:text-red-400' ?>">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <?php if ($message_type === 'success'): ?>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        <?php else: ?>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        <?php endif; ?>
                    </svg>
                    <span><?= htmlspecialchars($message) ?></span>
                </div>
                <?php if ($message_type === 'success' && !$is_valid_token): ?>
                    <script>
                        setTimeout(function() {
                            window.location.href = 'login.php';
                        }, 3000);
                    </script>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($is_valid_token): ?>
            <form method="POST" class="space-y-5" autocomplete="off">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">New Password</label>
                    <input type="password" name="password" required minlength="6"
                        class="login-input w-full px-4 py-3 rounded-xl border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-slate-700/50 text-gray-900 dark:text-white text-sm focus:bg-white dark:focus:bg-slate-700 transition-all duration-200"
                        placeholder="Enter new password">
                </div>
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Confirm Password</label>
                    <input type="password" name="confirm_password" required minlength="6"
                        class="login-input w-full px-4 py-3 rounded-xl border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-slate-700/50 text-gray-900 dark:text-white text-sm focus:bg-white dark:focus:bg-slate-700 transition-all duration-200"
                        placeholder="Confirm new password">
                </div>

                <button type="submit"
                    class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-3 px-5 rounded-xl transition-colors duration-200 shadow-sm flex items-center justify-center gap-2 text-[15px] mt-6">
                    <span>Reset Password</span>
                </button>
            </form>
            <?php endif; ?>
            
            <div class="text-center mt-6">
                <a href="login.php" class="text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 font-semibold transition-colors">
                    &larr; Back to Login
                </a>
            </div>
        </div>
    </div>
</body>
</html>
