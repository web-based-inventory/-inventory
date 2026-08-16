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
        html,
        body {
            height: 100%;
            overflow: hidden;
        }

        .login-bg {
            background: linear-gradient(-45deg, #0f172a, #1e1b4b, #312e81, #1e293b);
            background-size: 400% 400%;
            animation: gradientShift 15s ease infinite;
        }

        .dark .login-bg {
            background: linear-gradient(-45deg, #020617, #0f172a, #1e1b4b, #0f172a);
            background-size: 400% 400%;
            animation: gradientShift 15s ease infinite;
        }

        @keyframes gradientShift {
            0% {
                background-position: 0% 50%;
            }

            50% {
                background-position: 100% 50%;
            }

            100% {
                background-position: 0% 50%;
            }
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .dark .glass-card {
            background: rgba(30, 41, 59, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        @keyframes float {

            0%,
            100% {
                transform: translateY(0px);
            }

            50% {
                transform: translateY(-12px);
            }
        }

        .float-animation {
            animation: float 6s ease-in-out infinite;
        }

        @keyframes pulseGlow {

            0%,
            100% {
                box-shadow: 0 0 15px rgba(99, 102, 241, 0.3);
            }

            50% {
                box-shadow: 0 0 30px rgba(99, 102, 241, 0.5);
            }
        }

        .pulse-glow {
            animation: pulseGlow 3s ease-in-out infinite;
        }

        .login-input:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
        }

        .dark .login-input:focus {
            border-color: #818cf8;
            box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.15);
        }

        .login-btn {
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .login-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }

        .login-btn:hover::before {
            left: 100%;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(99, 102, 241, 0.4);
        }

        .login-btn:active {
            transform: translateY(0);
        }

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
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
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

        .forgot-disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .grid-pattern {
            background-image:
                linear-gradient(rgba(255, 255, 255, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.03) 1px, transparent 1px);
            background-size: 50px 50px;
        }
    </style>
</head>

<body class="login-bg h-screen grid-pattern overflow-hidden">
    <div class="h-screen flex flex-col lg:flex-row overflow-hidden">

        <!-- Left Side - Branding & Illustration -->
        <div class="hidden lg:flex lg:w-1/2 xl:w-[55%] relative overflow-hidden items-center justify-center p-6 xl:p-10">
            <!-- Decorative elements -->
            <div class="absolute top-16 left-16 w-64 h-64 bg-indigo-500/20 rounded-full blur-3xl"></div>
            <div class="absolute bottom-16 right-16 w-80 h-80 bg-purple-500/20 rounded-full blur-3xl"></div>

            <div class="relative z-10 text-center max-w-md">
                <!-- Title -->
                <h1 class="text-3xl xl:text-4xl font-bold text-gray-900 dark:text-white mb-3 leading-tight">
                    <?= $login_shop_name ?><br>
                    <span class="text-transparent bg-clip-text bg-gradient-to-r from-indigo-400 to-purple-400">Management System</span>
                </h1>

                <!-- Tagline -->
                <p class="text-sm text-slate-300 mb-6 leading-relaxed">
                    Streamline your inventory operations with real-time tracking,
                    smart analytics, and effortless management.
                </p>

                <!-- Illustration -->
                <div class="float-animation relative">
                    <svg viewBox="0 0 360 220" class="w-full max-w-sm mx-auto drop-shadow-2xl">
                        <rect x="40" y="20" width="280" height="160" rx="10" fill="rgba(255,255,255,0.1)" stroke="rgba(255,255,255,0.2)" stroke-width="1" />
                        <rect x="55" y="38" width="100" height="6" rx="2" fill="rgba(99,102,241,0.6)" />
                        <rect x="55" y="50" width="65" height="5" rx="2" fill="rgba(148,163,184,0.4)" />
                        <rect x="60" y="105" width="25" height="50" rx="3" fill="url(#barGradient1)" />
                        <rect x="95" y="85" width="25" height="70" rx="3" fill="url(#barGradient2)" />
                        <rect x="130" y="70" width="25" height="85" rx="3" fill="url(#barGradient1)" />
                        <rect x="165" y="95" width="25" height="60" rx="3" fill="url(#barGradient2)" />
                        <rect x="200" y="60" width="25" height="95" rx="3" fill="url(#barGradient1)" />
                        <rect x="240" y="38" width="50" height="40" rx="5" fill="rgba(34,197,94,0.2)" stroke="rgba(34,197,94,0.4)" stroke-width="1" />
                        <rect x="247" y="46" width="25" height="4" rx="1" fill="rgba(34,197,94,0.6)" />
                        <rect x="247" y="54" width="35" height="7" rx="2" fill="rgba(255,255,255,0.8)" />
                        <rect x="240" y="88" width="50" height="40" rx="5" fill="rgba(239,68,68,0.2)" stroke="rgba(239,68,68,0.4)" stroke-width="1" />
                        <rect x="247" y="96" width="20" height="4" rx="1" fill="rgba(239,68,68,0.6)" />
                        <rect x="247" y="104" width="30" height="7" rx="2" fill="rgba(255,255,255,0.8)" />
                        <circle cx="280" cy="150" r="20" fill="rgba(99,102,241,0.3)" stroke="rgba(99,102,241,0.5)" stroke-width="1" />
                        <path d="M276 150 L280 146 L284 150 L280 154 Z" fill="rgba(255,255,255,0.8)" />
                        <rect x="55" y="130" width="35" height="30" rx="3" fill="rgba(168,85,247,0.3)" stroke="rgba(168,85,247,0.5)" stroke-width="1" />
                        <rect x="100" y="135" width="30" height="25" rx="3" fill="rgba(59,130,246,0.3)" stroke="rgba(59,130,246,0.5)" stroke-width="1" />
                        <rect x="140" y="128" width="32" height="32" rx="3" fill="rgba(16,185,129,0.3)" stroke="rgba(16,185,129,0.5)" stroke-width="1" />
                        <circle cx="72" cy="145" r="7" fill="rgba(168,85,247,0.5)" />
                        <path d="M69 145 L71 147 L75 142" stroke="white" stroke-width="1.5" fill="none" stroke-linecap="round" />
                        <defs>
                            <linearGradient id="barGradient1" x1="0%" y1="0%" x2="0%" y2="100%">
                                <stop offset="0%" stop-color="#818cf8" />
                                <stop offset="100%" stop-color="#6366f1" />
                            </linearGradient>
                            <linearGradient id="barGradient2" x1="0%" y1="0%" x2="0%" y2="100%">
                                <stop offset="0%" stop-color="#a78bfa" />
                                <stop offset="100%" stop-color="#8b5cf6" />
                            </linearGradient>
                        </defs>
                    </svg>
                </div>

                <!-- Features -->
                <div class="flex items-center justify-center gap-5 mt-5 text-xs text-slate-400">
                    <div class="flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>Real-time Tracking</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                        <span>Smart Analytics</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                        </svg>
                        <span>Easy Management</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Side - Login Form -->
        <div class="flex-1 flex items-center justify-center p-4 sm:p-6 lg:p-8">
            <div class="w-full max-w-sm">
                <!-- Mobile Logo -->
                <div class="lg:hidden text-center mb-5">
                    <?php if ($login_has_logo): ?>
                        <img src="img/<?= htmlspecialchars($login_logo) ?>" alt="<?= $login_shop_name ?>"
                            class="w-12 h-12 rounded-full mb-3 shadow-lg object-cover mx-auto">
                    <?php else: ?>
                        <div class="inline-flex items-center justify-center w-12 h-12 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-xl mb-3 shadow-lg">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                            </svg>
                        </div>
                    <?php endif; ?>
                    <h2 class="text-lg font-bold text-white"><?= $login_shop_name ?></h2>
                </div>

                <!-- Login Card -->
                <div class="glass-card rounded-2xl shadow-2xl p-6 sm:p-7">
                    <!-- Header -->
                    <div class="text-center mb-5">
                        <?php if ($login_has_logo): ?>
                            <img src="img/<?= htmlspecialchars($login_logo) ?>" alt="<?= $login_shop_name ?>"
                                class="w-11 h-11 rounded-xl mb-3 shadow-lg object-cover mx-auto">
                        <?php else: ?>
                            <div class="inline-flex items-center justify-center w-11 h-11 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-xl mb-3 shadow-lg">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                </svg>
                            </div>
                        <?php endif; ?>
                        <h2 class="text-xl font-bold text-gray-900 dark:text-white">Welcome Back</h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Sign in to continue to your dashboard</p>
                    </div>

                    <!-- Error Message -->
                    <?php if ($error): ?>
                        <div class="flex items-center gap-2 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-600 dark:text-red-400 px-3 py-2.5 rounded-lg mb-4 text-xs font-medium">
                            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span><?= htmlspecialchars($error) ?></span>
                        </div>
                    <?php endif; ?>

                    <!-- Login Form -->
                    <form method="POST" class="space-y-3.5" autocomplete="off">
                        <!-- Email -->
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Email Address</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207" />
                                    </svg>
                                </div>
                                <input type="email" name="email" required
                                    value="<?= htmlspecialchars($email ?: $remembered_email) ?>"
                                    class="login-input w-full pl-10 pr-3 py-2.5 rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-slate-700 text-gray-900 dark:text-white text-sm placeholder-gray-400 dark:placeholder-gray-500 transition-all duration-200"
                                    placeholder="you@example.com">
                            </div>
                        </div>

                        <!-- Password -->
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Password</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                    </svg>
                                </div>
                                <input type="password" name="password" required id="password"
                                    class="login-input w-full pl-10 pr-10 py-2.5 rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-slate-700 text-gray-900 dark:text-white text-sm placeholder-gray-400 dark:placeholder-gray-500 transition-all duration-200"
                                    placeholder="Enter your password">
                                <button type="button" onclick="togglePassword()"
                                    class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                                    <svg class="w-4 h-4" id="eyeIcon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- Remember & Forgot -->
                        <div class="flex items-center justify-between">
                            <label class="flex items-center gap-2 cursor-pointer group">
                                <input type="checkbox" name="remember" value="1"
                                    class="custom-checkbox"
                                    <?= $remembered_email ? 'checked' : '' ?>>
                                <span class="text-xs text-gray-600 dark:text-gray-400 group-hover:text-gray-900 dark:group-hover:text-white transition-colors">Remember me</span>
                            </label>
                            <button type="button" onclick="document.getElementById('forgotModal').classList.remove('hidden')" class="text-xs text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 font-medium transition-colors">
                                Forgot Password?
                            </button>
                        </div>

                        <!-- Login Button -->
                        <button type="submit"
                            class="login-btn w-full bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white font-semibold py-2.5 px-5 rounded-lg shadow-lg shadow-indigo-500/30 flex items-center justify-center gap-2 text-sm">
                            <span>Sign In</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                            </svg>
                        </button>
                    </form>

                    <!-- Demo Info -->
                    <div class="mt-4 pt-3 border-t border-gray-100 dark:border-gray-700">
                        <div class="flex items-center gap-2">
                            <div class="w-6 h-6 bg-indigo-100 dark:bg-indigo-900/50 rounded flex items-center justify-center flex-shrink-0">
                                <svg class="w-3 h-3 text-indigo-600 dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <div class="text-[10px] text-gray-500 dark:text-gray-400">
                                <span class="font-medium text-gray-700 dark:text-gray-300">Admin:</span>
                                <span class="font-mono">admin@smartinventory.com / admin123</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <p class="text-center text-[10px] text-slate-400 mt-4">
                    &copy; <?= date('Y') ?> <?= $login_shop_name ?> Management System
                </p>
            </div>
        </div>
    </div>

    <!-- Forgot Password Modal -->
    <div id="forgotModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="document.getElementById('forgotModal').classList.add('hidden')"></div>
        <!-- Modal Card -->
        <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-sm p-6 text-center">
            <!-- Icon -->
            <div class="inline-flex items-center justify-center w-14 h-14 bg-indigo-100 dark:bg-indigo-900/50 rounded-full mb-4">
                <svg class="w-7 h-7 text-indigo-600 dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <!-- Title -->
            <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Forgot your password?</h3>
            <!-- Message -->
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">
                Please contact the <span class="font-semibold text-gray-700 dark:text-gray-300">System Administrator</span> to reset your password.
            </p>
            <p class="text-xs text-gray-400 dark:text-gray-500 mb-6">
                Only the Administrator can reset user passwords.
            </p>
            <!-- Close Button -->
            <button onclick="document.getElementById('forgotModal').classList.add('hidden')"
                class="w-full bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white font-semibold py-2.5 px-5 rounded-xl transition-all shadow-lg shadow-indigo-500/30 text-sm">
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