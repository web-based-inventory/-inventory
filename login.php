<?php
session_start();
if (isset($_SESSION['user_id'])) {
    $redirect = match ($_SESSION['role'] ?? '') {
        'admin' => 'dashboard/index.php',
        'staff' => 'dashboard/index.php',
        'cashier' => 'dashboard/index.php',
        default => 'dashboard/index.php'
    };
    header("Location: $redirect");
    exit;
}

require_once 'config/database.php';
require_once 'config/helpers.php';

$login_settings = getShopSettings($conn);
$login_shop_name = htmlspecialchars($login_settings['shop_name']);
$login_logo = trim($login_settings['logo'] ?? '');
$login_logo_path = !empty($login_logo) ? __DIR__ . '/img/' . $login_logo : '';
$login_has_logo = !empty($login_logo) && file_exists($login_logo_path);

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password';
    } else {
        $stmt = $conn->prepare("SELECT id, name, email, password, role, status, profile_image FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if (!$user) {
            $error = 'Email not found';
        } elseif ($user['status'] !== 'Active') {
            $error = 'Account is inactive. Contact administrator.';
        } elseif (!password_verify($password, $user['password'])) {
            if ($user['password'] === $password) {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $update->bind_param("si", $hashed, $user['id']);
                $update->execute();
                $update->close();
            } else {
                $error = 'Wrong password';
            }
        }

        if (empty($error)) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['theme'] = $user['theme'] ?? 'light';
            $_SESSION['profile_image'] = $user['profile_image'] ?? null;

            if ($remember) {
                setcookie('remember_email', $email, time() + 86400 * 30, '/');
            } else {
                setcookie('remember_email', '', time() - 3600, '/');
            }

            $redirect = match ($user['role']) {
                'admin' => 'dashboard/index.php',
                'staff' => 'dashboard/index.php',
                'cashier' => 'dashboard/index.php',
                default => 'dashboard/index.php'
            };
            header("Location: $redirect");
            exit;
        }
    }
}

$remembered_email = $_COOKIE['remember_email'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $login_shop_name ?> - Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include "includes/theme-init.php"; ?>
    <style>
        .custom-checkbox {
            appearance: none;
            -webkit-appearance: none;
            width: 16px;
            height: 16px;
            border: 2px solid #d1d5db;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
        }

        .custom-checkbox:checked {
            background-color: #4f46e5;
            border-color: transparent;
        }

        .custom-checkbox:checked::after {
            content: '';
            position: absolute;
            left: 4px;
            top: 1px;
            width: 4px;
            height: 8px;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }

        .dark .custom-checkbox {
            border-color: #4b5563;
        }

        .dark .custom-checkbox:checked {
            background-color: #6366f1;
        }

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
    <div class="max-w-5xl w-full bg-white dark:bg-slate-800 rounded-3xl shadow-2xl flex flex-col lg:flex-row overflow-hidden border border-gray-100 dark:border-slate-700 min-h-[600px]">

        <!-- Left Panel - System Identity -->
        <div class="lg:w-5/12 bg-gray-50 dark:bg-slate-800/50 p-8 lg:p-12 flex flex-col justify-center border-b lg:border-b-0 lg:border-r border-gray-100 dark:border-slate-700 relative overflow-hidden">
            <!-- Decorative minimal background pattern -->
            <div class="absolute inset-0 opacity-20 dark:opacity-10 pointer-events-none" style="background-image: radial-gradient(#6366f1 1px, transparent 1px); background-size: 24px 24px;"></div>

            <div class="relative z-10 max-w-sm mx-auto w-full">
                <!-- Branding -->
                <div class="flex items-center gap-4 mb-8">
                    <?php if ($login_has_logo): ?>
                        <img src="img/<?= htmlspecialchars($login_logo) ?>" alt="<?= $login_shop_name ?>" class="w-14 h-14 rounded-xl shadow-sm object-cover bg-white">
                    <?php else: ?>
                        <div class="w-14 h-14 bg-indigo-600 rounded-xl shadow-sm flex items-center justify-center">
                            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                            </svg>
                        </div>
                    <?php endif; ?>
                    <div>

                        <span class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Inventory Management and Demand Forecasting System</span>
                    </div>
                </div>

                <div class="mb-10">
                    <p class="text-gray-600 dark:text-gray-400 text-[15px] leading-relaxed">
                        Manage products, stock, purchases, sales and reports in one centralized system.
                    </p>
                </div>

                <!-- Feature Items -->
                <div class="space-y-5">
                    <div class="flex items-start gap-3 text-sm text-gray-700 dark:text-gray-300">
                        <div class="mt-0.5 bg-indigo-100 dark:bg-indigo-900/30 p-1.5 rounded-lg text-indigo-600 dark:text-indigo-400 flex-shrink-0">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
                            </svg>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900 dark:text-white mb-0.5">Inventory Management</p>
                            <p class="text-[13px] text-gray-500 dark:text-gray-400">Track stock levels and categories.</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3 text-sm text-gray-700 dark:text-gray-300">
                        <div class="mt-0.5 bg-emerald-100 dark:bg-emerald-900/30 p-1.5 rounded-lg text-emerald-600 dark:text-emerald-400 flex-shrink-0">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900 dark:text-white mb-0.5">Sales & Invoices</p>
                            <p class="text-[13px] text-gray-500 dark:text-gray-400">Process sales and generate receipts.</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3 text-sm text-gray-700 dark:text-gray-300">
                        <div class="mt-0.5 bg-blue-100 dark:bg-blue-900/30 p-1.5 rounded-lg text-blue-600 dark:text-blue-400 flex-shrink-0">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900 dark:text-white mb-0.5">Purchase Management</p>
                            <p class="text-[13px] text-gray-500 dark:text-gray-400">Manage suppliers and restocks.</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3 text-sm text-gray-700 dark:text-gray-300">
                        <div class="mt-0.5 bg-purple-100 dark:bg-purple-900/30 p-1.5 rounded-lg text-purple-600 dark:text-purple-400 flex-shrink-0">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                            </svg>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900 dark:text-white mb-0.5">Reports & Forecasting</p>
                            <p class="text-[13px] text-gray-500 dark:text-gray-400">Data-driven business insights.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Panel - Login -->
        <div class="lg:w-7/12 p-8 lg:p-14 flex flex-col justify-center relative bg-white dark:bg-slate-800">
            <div class="max-w-md w-full mx-auto">
                <div class="mb-8">
                    <h1 class="text-2xl lg:text-3xl font-bold text-gray-900 dark:text-white mb-2">Welcome back</h1>
                    <p class="text-gray-500 dark:text-gray-400 text-sm">Sign in to manage your inventory.</p>
                </div>

                <!-- Error Message -->
                <?php if ($error): ?>
                    <div class="flex items-center gap-2 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-600 dark:text-red-400 px-4 py-3 rounded-xl mb-6 text-sm font-medium">
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-5" autocomplete="off">
                    <!-- Email -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Email</label>
                        <input type="email" name="email" required
                            value="<?= htmlspecialchars($email ?: $remembered_email) ?>"
                            class="login-input w-full px-4 py-3 rounded-xl border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-slate-700/50 text-gray-900 dark:text-white text-sm focus:bg-white dark:focus:bg-slate-700 transition-all duration-200"
                            placeholder="Enter your email">
                    </div>

                    <!-- Password -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Password</label>
                        <div class="relative">
                            <input type="password" name="password" required id="password"
                                class="login-input w-full pl-4 pr-12 py-3 rounded-xl border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-slate-700/50 text-gray-900 dark:text-white text-sm focus:bg-white dark:focus:bg-slate-700 transition-all duration-200"
                                placeholder="Enter your password">
                            <button type="button" onclick="togglePassword()"
                                class="absolute inset-y-0 right-0 px-4 flex items-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                                <svg class="w-5 h-5" id="eyeIcon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Options -->
                    <div class="flex items-center justify-between mt-2">
                        <label class="flex items-center gap-2 cursor-pointer group">
                            <input type="checkbox" name="remember" value="1"
                                class="custom-checkbox"
                                <?= $remembered_email ? 'checked' : '' ?>>
                            <span class="text-sm text-gray-600 dark:text-gray-400 group-hover:text-gray-900 dark:group-hover:text-gray-200 transition-colors">Remember me</span>
                        </label>
                        <button type="button" onclick="document.getElementById('forgotModal').classList.remove('hidden')" class="text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 font-semibold transition-colors">
                            Forgot Password?
                        </button>
                    </div>

                    <!-- Submit -->
                    <button type="submit"
                        class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-3 px-5 rounded-xl transition-colors duration-200 shadow-sm flex items-center justify-center gap-2 text-[15px] mt-6">
                        <span>Sign In &rarr;</span>
                    </button>
                </form>

                <div class="mt-8 pt-6 border-t border-gray-100 dark:border-slate-700 text-center">
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        &copy; <?= date('Y') ?> <?= $login_shop_name ?> System
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Forgot Password Modal -->
    <div id="forgotModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-gray-900/50 dark:bg-black/60 backdrop-blur-sm" onclick="document.getElementById('forgotModal').classList.add('hidden')"></div>
        <!-- Modal Card -->
        <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-xl w-full max-w-sm p-6 text-center border border-gray-100 dark:border-slate-700">
            <!-- Icon -->
            <div class="inline-flex items-center justify-center w-14 h-14 bg-indigo-50 dark:bg-indigo-900/50 rounded-full mb-4">
                <svg class="w-7 h-7 text-indigo-600 dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Forgot your password?</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-6 leading-relaxed">
                Please contact the System Administrator to reset your password. Only administrators can perform password resets.
            </p>
            <button onclick="document.getElementById('forgotModal').classList.add('hidden')"
                class="w-full bg-gray-100 dark:bg-slate-700 hover:bg-gray-200 dark:hover:bg-slate-600 text-gray-800 dark:text-white font-semibold py-2.5 px-5 rounded-xl transition-colors text-sm">
                Close
            </button>
        </div>
    </div>

    <script>
        function togglePassword() {
            const pw = document.getElementById('password');
            const icon = document.getElementById('eyeIcon');
            if (pw.type === 'password') {
                pw.type = 'text';
                icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>';
            } else {
                pw.type = 'password';
                icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>';
            }
        }
    </script>
</body>

</html>