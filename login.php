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
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format';
    } else {
        $stmt = $conn->prepare("SELECT id, name, email, password, role, status, profile_image FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if (!$user) {
            $error = 'Invalid email or password';
        } elseif ($user['status'] !== 'Active') {
            $error = 'Account is inactive. Contact administrator.';
        } elseif (!password_verify($password, $user['password'])) {
            $error = 'Invalid email or password';
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include "includes/theme-init.php"; ?>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <style>
        @keyframes blob {
            0% { transform: translate(0px, 0px) scale(1); }
            33% { transform: translate(30px, -50px) scale(1.1); }
            66% { transform: translate(-20px, 20px) scale(0.9); }
            100% { transform: translate(0px, 0px) scale(1); }
        }
        .animate-blob {
            animation: blob 7s infinite;
        }
        .animation-delay-2000 {
            animation-delay: 2s;
        }
        .animation-delay-4000 {
            animation-delay: 4s;
        }
        .glass-panel {
            background: rgba(17, 24, 39, 0.7);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        .input-field {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
        }
        .input-field:focus {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(99, 102, 241, 0.5);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }
    </style>
</head>
<body class="relative min-h-screen flex items-center justify-center overflow-hidden bg-slate-950 font-sans antialiased text-gray-100">
    <!-- Animated background -->
    <div class="fixed inset-0 z-0 overflow-hidden pointer-events-none">
        <div class="absolute top-[-10%] left-[-10%] w-[40%] h-[40%] rounded-full bg-indigo-600/20 blur-[100px] mix-blend-screen animate-blob"></div>
        <div class="absolute top-[20%] right-[-10%] w-[40%] h-[40%] rounded-full bg-blue-600/20 blur-[100px] mix-blend-screen animate-blob animation-delay-2000"></div>
        <div class="absolute bottom-[-10%] left-[20%] w-[40%] h-[40%] rounded-full bg-purple-600/20 blur-[100px] mix-blend-screen animate-blob animation-delay-4000"></div>
        <!-- Grid pattern overlay -->
        <div class="absolute inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI0MCIgaGVpZ2h0PSI0MCI+PGRlZnM+PHBhdHRlcm4gaWQ9ImdyaWQiIHdpZHRoPSI0MCIgaGVpZ2h0PSI0MCIgcGF0dGVyblVuaXRzPSJ1c2VyU3BhY2VPblVzZSI+PHBhdGggZD0iTSAwIDQwIEwgNDAgNDAgNDAgMCBMIDAgMCBaIiBmaWxsPSJub25lIi8+PHBhdGggZD0iTSA0MCA0MCBMMCA0MCBMMCAwIiBmaWxsPSJub25lIiBzdHJva2U9InJnYmEoMjU1LCAyNTUsIDI1NSwgMC4wMikiIHN0cm9rZS13aWR0aD0iMSIvPjwvcGF0dGVybj48L2RlZnM+PHJlY3Qgd2lkdGg9IjEwMCUiIGhlaWdodD0iMTAwJSIgZmlsbD0idXJsKCNncmlkKSIvPjwvc3ZnPg==')] opacity-50"></div>
    </div>

    <!-- Login Container -->
    <div class="relative z-10 w-full max-w-md px-4 sm:px-6">
        
        <!-- Glassmorphism Card -->
        <div class="glass-panel rounded-[2rem] p-8 sm:p-10 relative overflow-hidden group/card">
            <!-- Shimmer effect on card hover -->
            <div class="absolute inset-0 bg-gradient-to-tr from-white/0 via-white/5 to-white/0 opacity-0 group-hover/card:opacity-100 transition-opacity duration-1000 pointer-events-none rounded-[2rem]"></div>

            <div class="text-center mb-10 relative z-20">
                <?php if ($login_has_logo): ?>
                    <div class="inline-block relative">
                        <div class="absolute inset-0 bg-indigo-500 blur-xl opacity-40 rounded-full"></div>
                        <img src="img/<?= htmlspecialchars($login_logo) ?>" alt="<?= $login_shop_name ?>" class="relative w-20 h-20 mx-auto rounded-2xl shadow-xl mb-6 object-cover bg-slate-800 p-1 border border-white/10">
                    </div>
                <?php else: ?>
                    <div class="inline-block relative mb-6">
                        <div class="absolute inset-0 bg-indigo-500 blur-xl opacity-40 rounded-full"></div>
                        <div class="relative w-20 h-20 mx-auto bg-gradient-to-br from-indigo-500 to-purple-600 rounded-2xl shadow-xl flex items-center justify-center border border-white/20">
                            <svg class="w-10 h-10 text-white drop-shadow-md" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                            </svg>
                        </div>
                    </div>
                <?php endif; ?>
                <h1 class="text-3xl font-bold text-white tracking-tight">Welcome Back</h1>
                <p class="text-slate-400 mt-2 text-sm">Sign in to <?= $login_shop_name ?></p>
            </div>

            <!-- Error Message -->
            <?php if ($error): ?>
                <div class="flex items-center gap-3 bg-red-500/10 border border-red-500/20 text-red-400 px-4 py-3 rounded-xl mb-8 text-sm font-medium backdrop-blur-sm shadow-[0_0_20px_rgba(239,68,68,0.1)] relative z-20 animate-[fade-in_0.3s_ease-out]">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-6 relative z-20" autocomplete="off" onsubmit="showLoading()">
                <!-- Email -->
                <div class="space-y-2">
                    <label class="block text-[0.8rem] font-semibold tracking-wider text-slate-400 uppercase ml-1">Email Address</label>
                    <div class="relative group/input">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <svg class="h-5 w-5 text-slate-500 group-focus-within/input:text-indigo-400 transition-colors duration-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <input type="email" name="email" required
                            value="<?= htmlspecialchars($email ?: $remembered_email) ?>"
                            class="input-field w-full pl-12 pr-4 py-3.5 rounded-xl text-white placeholder-slate-600 focus:outline-none"
                            placeholder="you@example.com">
                    </div>
                </div>

                <!-- Password -->
                <div class="space-y-2">
                    <label class="block text-[0.8rem] font-semibold tracking-wider text-slate-400 uppercase ml-1">Password</label>
                    <div class="relative group/input">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <svg class="h-5 w-5 text-slate-500 group-focus-within/input:text-indigo-400 transition-colors duration-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                        </div>
                        <input type="password" name="password" required id="password"
                            class="input-field w-full pl-12 pr-12 py-3.5 rounded-xl text-white placeholder-slate-600 focus:outline-none"
                            placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;">
                        <button type="button" onclick="togglePassword()"
                            class="absolute inset-y-0 right-0 pr-4 flex items-center text-slate-500 hover:text-slate-300 focus:outline-none transition-colors duration-300">
                            <svg class="w-5 h-5" id="eyeIcon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Options -->
                <div class="flex items-center justify-between pt-2">
                    <label class="flex items-center gap-2.5 cursor-pointer group/checkbox">
                        <div class="relative flex items-center justify-center">
                            <input type="checkbox" name="remember" value="1"
                                class="peer sr-only"
                                <?= $remembered_email ? 'checked' : '' ?>>
                            <div class="w-5 h-5 rounded-[6px] border border-slate-600 bg-slate-800/50 peer-checked:bg-indigo-500 peer-checked:border-indigo-500 transition-all duration-200 flex items-center justify-center shadow-inner">
                                <svg class="w-3.5 h-3.5 text-white opacity-0 peer-checked:opacity-100 transition-opacity duration-200 transform scale-50 peer-checked:scale-100" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                            </div>
                        </div>
                        <span class="text-sm text-slate-400 group-hover/checkbox:text-slate-200 transition-colors">Remember me</span>
                    </label>
                    <a href="forgot_password.php" class="text-sm text-indigo-400 hover:text-indigo-300 font-medium transition-colors hover:underline decoration-indigo-400/30 underline-offset-4">
                        Forgot Password?
                    </a>
                </div>

                <!-- Submit -->
                <button type="submit" id="submitBtn"
                    class="group relative w-full overflow-hidden bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-3.5 px-5 rounded-xl transition-all duration-300 shadow-[0_8px_20px_-6px_rgba(79,70,229,0.5)] hover:shadow-[0_12px_25px_-6px_rgba(79,70,229,0.6)] transform hover:-translate-y-0.5 mt-6 border border-indigo-500/50">
                    <div class="absolute inset-0 w-full h-full bg-gradient-to-r from-transparent via-white/10 to-transparent -translate-x-full group-hover:translate-x-full transition-transform duration-1000 ease-in-out"></div>
                    <div class="flex items-center justify-center gap-2 relative z-10">
                        <svg id="loadingSpinner" class="hidden w-5 h-5 animate-spin text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span id="btnText">Sign In</span>
                        <svg id="btnIcon" class="w-5 h-5 group-hover:translate-x-1 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </div>
                </button>
            </form>
        </div>
        
        <!-- Footer -->
        <div class="text-center mt-10 relative z-20">
            <p class="text-sm text-slate-500 font-medium tracking-wide">
                &copy; <?= date('Y') ?> <?= $login_shop_name ?> System
            </p>
        </div>
    </div>

    <script>
        function togglePassword() {
            const pw = document.getElementById('password');
            const icon = document.getElementById('eyeIcon');
            if (pw.type === 'password') {
                pw.type = 'text';
                icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>';
            } else {
                pw.type = 'password';
                icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>';
            }
        }

        function showLoading() {
            document.getElementById('loadingSpinner').classList.remove('hidden');
            document.getElementById('btnIcon').classList.add('hidden');
            document.getElementById('btnText').innerText = 'Signing in...';
            document.getElementById('submitBtn').classList.add('pointer-events-none', 'opacity-80');
        }
    </script>
</body>
</html>