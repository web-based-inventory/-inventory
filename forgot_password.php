<?php
session_start();
require_once 'config/database.php';
require_once 'config/helpers.php';
require_once 'config/mail.php';

$login_settings = getShopSettings($conn);
$login_shop_name = htmlspecialchars($login_settings['shop_name']);
$login_logo = trim($login_settings['logo'] ?? '');
$login_logo_path = !empty($login_logo) ? __DIR__ . '/img/' . $login_logo : '';
$login_has_logo = !empty($login_logo) && file_exists($login_logo_path);

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $message = 'Please enter your email address.';
        $message_type = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Invalid email format.';
        $message_type = 'error';
    } else {
        // Find user
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND status = 'Active' LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        
        // Always show the same success message to prevent user enumeration
        $message = 'If that email address is in our database, we have sent you a link to reset your password.';
        $message_type = 'success';
        
        if ($user) {
            // Delete existing active tokens for this user
            $delete_stmt = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
            $delete_stmt->bind_param("i", $user['id']);
            $delete_stmt->execute();
            $delete_stmt->close();
            
            // Generate token
            $token = bin2hex(random_bytes(32));
            $token_hash = hash('sha256', $token);
            
            $insert_stmt = $conn->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))");
            $insert_stmt->bind_param("is", $user['id'], $token_hash);
            if ($insert_stmt->execute()) {
                $reset_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password.php?token=" . $token;
                
                if (!sendResetEmail($email, $reset_link, $login_shop_name)) {
                    $message = 'Failed to send the reset email due to a server error. Please try again later.';
                    $message_type = 'error';
                }
            }
            $insert_stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $login_shop_name ?> - Forgot Password</title>
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
<body class="bg-gray-50 dark:bg-slate-900 text-gray-900 dark:text-gray-100 min-h-screen flex items-center justify-center p-4">
    <div class="max-w-md w-full bg-white dark:bg-slate-800 rounded-3xl shadow-2xl overflow-hidden border border-gray-100 dark:border-slate-700">
        <div class="p-8 sm:p-10">
            <div class="text-center mb-8">
                <?php if ($login_has_logo): ?>
                    <img src="img/<?= htmlspecialchars($login_logo) ?>" alt="<?= $login_shop_name ?>" class="w-16 h-16 rounded-xl shadow-sm object-cover bg-white mx-auto mb-4">
                <?php else: ?>
                    <div class="w-16 h-16 bg-indigo-600 rounded-xl shadow-sm flex items-center justify-center mx-auto mb-4">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                    </div>
                <?php endif; ?>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">Forgot Password</h1>
                <p class="text-gray-500 dark:text-gray-400 text-sm">Enter your email address and we'll send you a link to reset your password.</p>
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
            <?php endif; ?>

            <form method="POST" class="space-y-5" autocomplete="off">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Email Address</label>
                    <input type="email" name="email" required
                        class="login-input w-full px-4 py-3 rounded-xl border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-slate-700/50 text-gray-900 dark:text-white text-sm focus:bg-white dark:focus:bg-slate-700 transition-all duration-200"
                        placeholder="Enter your email">
                </div>

                <button type="submit"
                    class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-3 px-5 rounded-xl transition-colors duration-200 shadow-sm flex items-center justify-center gap-2 text-[15px] mt-6">
                    <span>Send Reset Link</span>
                </button>
                
                <div class="text-center mt-6">
                    <a href="login.php" class="text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 font-semibold transition-colors">
                        &larr; Back to Login
                    </a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
