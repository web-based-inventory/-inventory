<?php
include "../includes/auth_check.php";
protectReports();
include "../config/database.php";
include "../config/helpers.php";

$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-t');
$safe_from = mysqli_real_escape_string($conn, $date_from);
$safe_to = mysqli_real_escape_string($conn, $date_to);

// ============ TODAY'S SALES ============
$today_stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) AS count, COALESCE(SUM(total_amount), 0) AS revenue
    FROM sales WHERE DATE(created_at) = CURDATE()
"));

// ============ WEEKLY SALES (last 7 days) ============
$week_stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) AS count, COALESCE(SUM(total_amount), 0) AS revenue
    FROM sales WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
"));

// ============ MONTHLY SALES (current month) ============
$month_stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) AS count, COALESCE(SUM(total_amount), 0) AS revenue
    FROM sales WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())
"));

// ============ OVERALL REVENUE (filtered range) ============
$revenue_stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) AS total_sales, COALESCE(SUM(total_amount), 0) AS total_revenue
    FROM sales WHERE DATE(created_at) BETWEEN '$safe_from' AND '$safe_to'
"));

// ============ PROFIT ============
$profit_stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(sd.subtotal), 0) AS revenue,
           COALESCE(SUM(sd.purchase_price * sd.quantity), 0) AS cost,
           COALESCE(SUM(sd.profit), 0) AS profit
    FROM sale_details sd
    JOIN sales s ON sd.sale_id = s.id
    WHERE DATE(s.created_at) BETWEEN '$safe_from' AND '$safe_to'
"));

// ============ BEST SELLING PRODUCTS ============
$top_products = mysqli_query($conn, "
    SELECT p.product_name, p.sku, SUM(sd.quantity) AS total_qty,
           SUM(sd.subtotal) AS total_revenue,
           SUM(sd.profit) AS total_profit
    FROM sale_details sd
    JOIN products p ON sd.product_id = p.id
    JOIN sales s ON sd.sale_id = s.id
    WHERE DATE(s.created_at) BETWEEN '$safe_from' AND '$safe_to'
    GROUP BY sd.product_id
    ORDER BY total_qty DESC LIMIT 10
");

// ============ SALES BY CATEGORY ============
$category_sales = mysqli_query($conn, "
    SELECT c.name AS category_name, SUM(sd.quantity) AS total_qty,
           SUM(sd.subtotal) AS total_revenue,
           COUNT(DISTINCT s.id) AS sale_count
    FROM sale_details sd
    JOIN products p ON sd.product_id = p.id
    JOIN categories c ON p.category_id = c.id
    JOIN sales s ON sd.sale_id = s.id
    WHERE DATE(s.created_at) BETWEEN '$safe_from' AND '$safe_to'
    GROUP BY c.id
    ORDER BY total_revenue DESC
");

// ============ PAYMENT METHOD SUMMARY ============
$spAmtCol = getPaymentAmountCol($conn, 'sale_payments');
$payment_summary = mysqli_query($conn, "
    SELECT COALESCE(sp.payment_method, 'Cash') AS payment_method,
           COALESCE(SUM(sp.$spAmtCol), s.total_amount) AS total, COUNT(*) AS count
    FROM sales s
    LEFT JOIN sale_payments sp ON sp.id = (
        SELECT id FROM sale_payments WHERE sale_id = s.id ORDER BY id ASC LIMIT 1
    )
    WHERE DATE(s.created_at) BETWEEN '$safe_from' AND '$safe_to'
    GROUP BY COALESCE(sp.payment_method, 'Cash')
");
$payment_totals = ['Cash' => 0, 'KBZPay' => 0, 'Mixed' => 0];
$payment_counts = ['Cash' => 0, 'KBZPay' => 0, 'Mixed' => 0];
while ($pt = mysqli_fetch_assoc($payment_summary)) {
    $pm = $pt['payment_method'] ?? 'Cash';
    if (!isset($payment_totals[$pm])) $payment_totals[$pm] = 0;
    if (!isset($payment_counts[$pm])) $payment_counts[$pm] = 0;
    $payment_totals[$pm] += (float)$pt['total'];
    $payment_counts[$pm] += (int)$pt['count'];
}
$has_payments = array_sum($payment_totals) > 0;

// ============ DAILY SALES ============
$daily_sales = mysqli_query($conn, "
    SELECT DATE(created_at) AS day, COUNT(*) AS count, SUM(total_amount) AS total
    FROM sales WHERE DATE(created_at) BETWEEN '$safe_from' AND '$safe_to'
    GROUP BY DATE(created_at) ORDER BY day DESC
");

$page_title = "Sales Reports";
$report_settings = getShopSettings($conn);
$report_shop_name = htmlspecialchars($report_settings['shop_name']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Reports - <?= $report_shop_name ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include "../includes/theme-init.php"; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Inter', system-ui, sans-serif;
        }

        .stat-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(229, 231, 235, 0.8);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.08);
            border-color: rgba(99, 102, 241, 0.2);
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(16px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in {
            animation: fadeIn 0.5s cubic-bezier(0.4, 0, 0.2, 1) both;
        }

        .delay-1 {
            animation-delay: 0.05s;
        }

        .delay-2 {
            animation-delay: 0.1s;
        }

        .delay-3 {
            animation-delay: 0.15s;
        }

        .delay-4 {
            animation-delay: 0.2s;
        }

        .progress-bar {
            height: 10px;
            border-radius: 5px;
            background: #f3f4f6;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 5px;
            transition: width 1s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .card {
            background: white;
            border-radius: 20px;
            border: 1px solid #f0f0f0;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .card:hover {
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.06);
        }

        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid #f3f4f6;
            background: linear-gradient(to right, #fafafa, #fff);
        }

        .card-body {
            padding: 24px;
        }

        .btn {
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
        }

        .btn-primary {
            background: linear-gradient(135deg, #6366f1, #4f46e5) !important;
            box-shadow: 0 4px 14px rgba(99, 102, 241, 0.3) !important;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4) !important;
        }

        .btn-outline {
            border: 1.5px solid #e5e7eb !important;
        }

        .btn-outline:hover {
            border-color: #6366f1 !important;
            background: #f5f3ff !important;
            color: #4f46e5 !important;
        }

        .form-input {
            border-radius: 12px !important;
            border: 1.5px solid #e5e7eb !important;
            padding: 10px 16px !important;
            transition: all 0.2s ease !important;
        }

        .form-input:focus {
            border-color: #6366f1 !important;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1) !important;
        }

        .data-table thead th {
            background: #f8fafc !important;
            font-size: 11px !important;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b !important;
            padding: 14px 16px !important;
            font-weight: 600 !important;
            border-bottom: 2px solid #e2e8f0 !important;
        }

        .data-table tbody td {
            padding: 14px 16px !important;
            font-size: 13px !important;
        }

        .data-table tbody tr {
            transition: all 0.15s ease;
        }

        .data-table tbody tr:hover {
            background: #f8faff !important;
        }

        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: #d1d5db;
            border-radius: 3px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #9ca3af;
        }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-900">
    <div class="flex min-h-screen">
        <?php include "../includes/sidebar.php"; ?>
        <div class="flex-1 flex flex-col">
            <?php include "../includes/header.php"; ?>
            <main class="p-4 lg:p-6">
                <div class="max-w-7xl mx-auto">
                    <div class="flex flex-wrap items-center justify-between gap-4 mb-8 p-5 bg-white rounded-2xl border border-gray-100 shadow-sm">
                        <div class="flex flex-wrap items-end gap-4">
                            <div>
                                <label class="text-xs font-semibold text-gray-500 mb-1.5 block">From Date</label>
                                <input type="date" name="date_from" value="<?= $date_from ?>" class="form-input text-sm" form="reportForm">
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-gray-500 mb-1.5 block">To Date</label>
                                <input type="date" name="date_to" value="<?= $date_to ?>" class="form-input text-sm" form="reportForm">
                            </div>
                            <div class="flex gap-2 items-end">
                                <button form="reportForm" class="btn btn-primary text-sm">Generate Report</button>
                                <a href="salereport.php" class="btn btn-outline text-sm">Reset</a>
                            </div>
                        </div>
                        <button onclick="exportExcel()" class="btn btn-outline gap-2 text-sm whitespace-nowrap">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            Export Excel
                        </button>
                    </div>
                    <form method="GET" id="reportForm"></form>

                    <!-- Quick Stats Row -->
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6" id="exportArea">
                        <!-- Today's Sales -->
                        <div class="stat-card bg-emerald-50 dark:bg-emerald-900/30 rounded-xl p-5">
                            <div class="flex items-center gap-3">
                                <svg class="w-12 h-12 text-emerald-600 dark:text-emerald-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <div>
                                    <p class="text-sm text-emerald-600">Today's Sales</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white leading-none"><?= number_format($today_stats['count']) ?></p>
                                    <p class="text-xs text-emerald-600 dark:text-emerald-400 mt-1"><?= number_format($today_stats['revenue']) ?> Ks</p>
                                </div>
                            </div>
                        </div>
                        <!-- Weekly Sales -->
                        <div class="stat-card bg-blue-50 dark:bg-blue-900/30 rounded-xl p-5">
                            <div class="flex items-center gap-3">
                                <svg class="w-12 h-12 text-blue-600 dark:text-blue-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                                <div>
                                    <p class="text-sm text-blue-600">Weekly Sales</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white leading-none"><?= number_format($week_stats['count']) ?></p>
                                    <p class="text-xs text-blue-600 dark:text-blue-400 mt-1"><?= number_format($week_stats['revenue']) ?> Ks</p>
                                </div>
                            </div>
                        </div>
                        <!-- Monthly Sales -->
                        <div class="stat-card bg-indigo-50 dark:bg-indigo-900/30 rounded-xl p-5">
                            <div class="flex items-center gap-3">
                                <svg class="w-12 h-12 text-indigo-600 dark:text-indigo-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                </svg>
                                <div>
                                    <p class="text-sm text-indigo-600">Monthly Sales</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white leading-none"><?= number_format($month_stats['count']) ?></p>
                                    <p class="text-xs text-indigo-600 dark:text-indigo-400 mt-1"><?= number_format($month_stats['revenue']) ?> Ks</p>
                                </div>
                            </div>
                        </div>
                        <!-- Period Revenue -->
                        <div class="stat-card bg-amber-50 dark:bg-amber-900/30 rounded-xl p-5">
                            <div class="flex items-center gap-3">
                                <svg class="w-12 h-12 text-amber-600 dark:text-amber-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <div>
                                    <p class="text-sm text-amber-600">Period Revenue</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white leading-none"><?= number_format($revenue_stats['total_revenue']) ?> <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Ks</span></p>
                                    <p class="text-xs text-amber-600 dark:text-amber-400 mt-1"><?= $revenue_stats['total_sales'] ?> sales</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Profit + Payment Methods Row -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                        <!-- Profit Card -->
                        <div class="card">
                            <div class="card-header">
                                <h2 class="text-base font-bold text-gray-800 flex items-center gap-2">
                                    <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center shadow-sm">
                                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                                        </svg>
                                    </div>
                                    Profit Overview
                                </h2>
                            </div>
                            <div class="card-body">
                                <div class="grid grid-cols-3 gap-4 mb-6">
                                    <div class="text-center p-4 bg-gradient-to-br from-emerald-50 to-emerald-100/50 rounded-2xl border border-emerald-200/50">
                                        <p class="text-xs font-semibold text-emerald-700 mb-1">Revenue</p>
                                        <p class="text-xl font-extrabold text-emerald-700"><?= number_format($profit_stats['revenue']) ?></p>
                                    </div>
                                    <div class="text-center p-4 bg-gradient-to-br from-red-50 to-red-100/50 rounded-2xl border border-red-200/50">
                                        <p class="text-xs font-semibold text-red-700 mb-1">Cost</p>
                                        <p class="text-xl font-extrabold text-red-700"><?= number_format($profit_stats['cost']) ?></p>
                                    </div>
                                    <div class="text-center p-4 bg-gradient-to-br <?= $profit_stats['profit'] < 0 ? 'from-red-50 to-red-100/50 border-red-200/50' : 'from-indigo-50 to-indigo-100/50 border-indigo-200/50' ?> rounded-2xl border">
                                        <p class="text-xs font-semibold <?= $profit_stats['profit'] < 0 ? 'text-red-700' : 'text-indigo-700' ?> mb-1"><?= $profit_stats['profit'] < 0 ? 'Net Loss' : 'Profit' ?></p>
                                        <p class="text-xl font-extrabold <?= $profit_stats['profit'] < 0 ? 'text-red-700' : 'text-indigo-700' ?>"><?= number_format($profit_stats['profit']) ?></p>
                                    </div>
                                </div>
                                <?php
                                $margin = $profit_stats['revenue'] > 0 ? ($profit_stats['profit'] / $profit_stats['revenue']) * 100 : 0;
                                $margin_color = $margin >= 20 ? 'from-emerald-500 to-emerald-600' : ($margin >= 10 ? 'from-amber-500 to-amber-600' : 'from-red-500 to-red-600');
                                $margin_text = $margin >= 20 ? 'text-emerald-700' : ($margin >= 10 ? 'text-amber-700' : 'text-red-700');
                                ?>
                                <div>
                                    <div class="flex justify-between items-center mb-2">
                                        <span class="text-sm font-semibold text-gray-700">Profit Margin</span>
                                        <span class="text-sm font-extrabold <?= $margin_text ?>"><?= number_format($margin, 1) ?>%</span>
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-fill bg-gradient-to-r <?= $margin_color ?>" style="width: <?= min(100, $margin) ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Payment Methods -->
                        <div class="card">
                            <div class="card-header">
                                <h2 class="text-base font-bold text-gray-800 flex items-center gap-2">
                                    <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-sm">
                                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                                        </svg>
                                    </div>
                                    Payment Methods
                                </h2>
                            </div>
                            <div class="card-body">
                                <div class="space-y-5">
                                    <?php
                                    $total_payment = array_sum($payment_totals);
                                    $payment_colors = [
                                        'Cash' => ['gradient' => 'from-emerald-50 to-emerald-100/50', 'border' => 'border-emerald-200/50', 'icon_bg' => 'from-emerald-500 to-emerald-600', 'text' => 'text-emerald-700', 'fill' => 'bg-gradient-to-r from-emerald-500 to-emerald-600', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>'],
                                        'KBZPay' => ['gradient' => 'from-blue-50 to-blue-100/50', 'border' => 'border-blue-200/50', 'icon_bg' => 'from-blue-500 to-blue-600', 'text' => 'text-blue-700', 'fill' => 'bg-gradient-to-r from-blue-500 to-blue-600', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>'],
                                        'Mixed' => ['gradient' => 'from-purple-50 to-purple-100/50', 'border' => 'border-purple-200/50', 'icon_bg' => 'from-purple-500 to-purple-600', 'text' => 'text-purple-700', 'fill' => 'bg-gradient-to-r from-purple-500 to-purple-600', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>']
                                    ];
                                    foreach ($payment_totals as $method => $amount):
                                        $pct = $total_payment > 0 ? ($amount / $total_payment) * 100 : 0;
                                        $c = $payment_colors[$method] ?? ['gradient' => 'from-gray-50 to-gray-100/50', 'border' => 'border-gray-200/50', 'icon_bg' => 'from-gray-500 to-gray-600', 'text' => 'text-gray-700', 'fill' => 'bg-gradient-to-r from-gray-500 to-gray-600', 'icon' => ''];
                                    ?>
                                        <div class="flex items-center gap-4 p-4 bg-gradient-to-br <?= $c['gradient'] ?> rounded-2xl border <?= $c['border'] ?>">
                                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br <?= $c['icon_bg'] ?> flex items-center justify-center flex-shrink-0 shadow-sm">
                                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><?= $c['icon'] ?></svg>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <div class="flex justify-between items-center mb-2">
                                                    <span class="text-sm font-bold text-gray-800"><?= $method ?></span>
                                                    <span class="text-sm font-extrabold <?= $c['text'] ?>"><?= number_format($amount) ?> Ks</span>
                                                </div>
                                                <div class="progress-bar">
                                                    <div class="progress-fill <?= $c['fill'] ?>" style="width: <?= $pct ?>%"></div>
                                                </div>
                                                <p class="text-xs text-gray-500 mt-1.5 font-medium"><?= $payment_counts[$method] ?> transactions &middot; <?= number_format($pct, 1) ?>% share</p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Best Selling Products -->
                    <div class="card mb-6">
                        <div class="card-header">
                            <h2 class="text-base font-bold text-gray-800 flex items-center gap-2">
                                <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-sm">
                                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                                    </svg>
                                </div>
                                Best Selling Products
                            </h2>
                        </div>
                        <div class="table-wrap">
                            <table class="data-table w-full">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Product</th>
                                        <th>SKU</th>
                                        <th class="num">Qty Sold</th>
                                        <th class="num">Revenue</th>
                                        <th class="num">Profit</th>
                                        <th class="w-40">Share</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $max_revenue = 0;
                                    $tp_rows = [];
                                    while ($tp = mysqli_fetch_assoc($top_products)) {
                                        $tp_rows[] = $tp;
                                        if ($tp['total_revenue'] > $max_revenue) $max_revenue = $tp['total_revenue'];
                                    }
                                    $total_revenue_all = array_sum(array_column($tp_rows, 'total_revenue'));
                                    $rank = 1;
                                    foreach ($tp_rows as $tp):
                                        $share = $total_revenue_all > 0 ? ($tp['total_revenue'] / $total_revenue_all) * 100 : 0;
                                    ?>
                                        <tr>
                                            <td><?= $rank++ ?></td>
                                            <td><?= htmlspecialchars($tp['product_name']) ?></td>
                                            <td><?= htmlspecialchars($tp['sku'] ?? 'N/A') ?></td>
                                            <td class="num"><?= number_format($tp['total_qty']) ?></td>
                                            <td class="num"><?= number_format($tp['total_revenue']) ?> Ks</td>
                                            <td class="num <?= $tp['total_profit'] < 0 ? 'text-red-600' : '' ?>"><?= ($tp['total_profit'] < 0 ? 'Loss ' : '') . number_format($tp['total_profit']) ?> Ks</td>
                                            <td>
                                                <div class="flex items-center gap-2">
                                                    <div class="progress-bar flex-1">
                                                        <div class="progress-fill bg-indigo-500" style="width: <?= $share ?>%"></div>
                                                    </div>
                                                    <span class="text-xs text-gray-500 dark:text-gray-400 w-10 text-right"><?= number_format($share, 1) ?>%</span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($tp_rows)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-16">
                                                <div class="flex flex-col items-center">
                                                    <div class="w-14 h-14 rounded-2xl bg-amber-50 flex items-center justify-center mb-4">
                                                        <svg class="w-7 h-7 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                                                        </svg>
                                                    </div>
                                                    <h3 class="text-base font-semibold text-gray-500">No product sales found</h3>
                                                    <p class="text-sm text-gray-400 mt-1">No products were sold in this period.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Sales by Category -->
                    <div class="card mb-6">
                        <div class="card-header">
                            <h2 class="text-base font-bold text-gray-800 flex items-center gap-2">
                                <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-sky-500 to-cyan-600 flex items-center justify-center shadow-sm">
                                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                                    </svg>
                                </div>
                                Sales by Category
                            </h2>
                        </div>
                        <div class="table-wrap">
                            <table class="data-table w-full">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Category</th>
                                        <th class="num">Count</th>
                                        <th class="num">Qty Sold</th>
                                        <th class="num">Revenue</th>
                                        <th class="w-48">Share</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $cat_rows = [];
                                    while ($cs = mysqli_fetch_assoc($category_sales)) $cat_rows[] = $cs;
                                    $cat_total = array_sum(array_column($cat_rows, 'total_revenue'));
                                    $cat_colors = ['bg-indigo-500', 'bg-emerald-500', 'bg-blue-500', 'bg-amber-500', 'bg-purple-500', 'bg-red-500', 'bg-cyan-500'];
                                    $ci = 0;
                                    foreach ($cat_rows as $cs):
                                        $share = $cat_total > 0 ? ($cs['total_revenue'] / $cat_total) * 100 : 0;
                                        $color = $cat_colors[$ci % count($cat_colors)];
                                        $ci++;
                                    ?>
                                        <tr>
                                            <td><?= $ci ?></td>
                                            <td><?= htmlspecialchars($cs['category_name']) ?></td>
                                            <td class="num"><?= number_format($cs['sale_count']) ?></td>
                                            <td class="num"><?= number_format($cs['total_qty']) ?></td>
                                            <td class="num"><?= number_format($cs['total_revenue']) ?> Ks</td>
                                            <td>
                                                <div class="flex items-center gap-2">
                                                    <div class="progress-bar flex-1">
                                                        <div class="progress-fill <?= $color ?>" style="width: <?= $share ?>%"></div>
                                                    </div>
                                                    <span class="text-xs text-gray-500 dark:text-gray-400 w-10 text-right"><?= number_format($share, 1) ?>%</span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($cat_rows)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-16">
                                                <div class="flex flex-col items-center">
                                                    <div class="w-14 h-14 rounded-2xl bg-sky-50 flex items-center justify-center mb-4">
                                                        <svg class="w-7 h-7 text-sky-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                                                        </svg>
                                                    </div>
                                                    <h3 class="text-base font-semibold text-gray-500">No category data</h3>
                                                    <p class="text-sm text-gray-400 mt-1">No categories were sold in this period.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Daily Sales Breakdown -->
                    <div class="card mb-6">
                        <div class="card-header">
                            <h2 class="text-base font-bold text-gray-800 flex items-center gap-2">
                                <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center shadow-sm">
                                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                </div>
                                Daily Sales
                            </h2>
                        </div>
                        <div class="card-body">
                            <div class="h-64 mb-4">
                                <canvas id="dailyChart"></canvas>
                            </div>
                            <div class="table-wrap">
                                <table class="data-table w-full">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th class="num">Sales</th>
                                            <th class="num">Revenue</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($d = mysqli_fetch_assoc($daily_sales)): ?>
                                            <tr>
                                                <td><?= date('d M Y (D)', strtotime($d['day'])) ?></td>
                                                <td class="num"><?= $d['count'] ?></td>
                                                <td class="num"><?= number_format($d['total']) ?> Ks</td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <?php include "../includes/toast.php"; ?>
    <?php include "../includes/modal.php"; ?>
    <?php include "../includes/footer.php"; ?>

    <script>
        // Daily Sales Chart
        <?php
        $chart_labels = [];
        $chart_data = [];
        mysqli_data_seek($daily_sales, 0);
        while ($d = mysqli_fetch_assoc($daily_sales)) {
            $chart_labels[] = date('d M', strtotime($d['day']));
            $chart_data[] = (float)$d['total'];
        }
        ?>
        const ctx = document.getElementById('dailyChart');
        if (ctx) {
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode(array_reverse($chart_labels)) ?>,
                    datasets: [{
                        label: 'Revenue (Ks)',
                        data: <?= json_encode(array_reverse($chart_data)) ?>,
                        backgroundColor: 'rgba(99, 102, 241, 0.8)',
                        borderColor: 'rgb(99, 102, 241)',
                        borderWidth: 1,
                        borderRadius: 6,
                        barThickness: 'flex',
                        maxBarThickness: 40,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(ctx) {
                                    return ctx.parsed.y.toLocaleString() + ' Ks';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(val) {
                                    return val.toLocaleString();
                                }
                            },
                            grid: {
                                color: 'rgba(0,0,0,0.05)'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }

        // Export Excel (CSV download)
        function exportExcel() {
            const rows = [];
            rows.push(['Sales Report - <?= $date_from ?> to <?= $date_to ?>']);
            rows.push([]);
            rows.push(['Summary']);
            rows.push(['Total Sales', <?= $revenue_stats['total_sales'] ?>]);
            rows.push(['Total Revenue', <?= $revenue_stats['total_revenue'] ?>]);
            rows.push(['Total Profit', <?= $profit_stats['profit'] ?>]);
            rows.push(['Profit Margin', '<?= number_format($margin, 1) ?>%']);
            rows.push([]);
            rows.push(['Payment Method', 'Amount', 'Transactions']);
            <?php foreach ($payment_totals as $method => $amount): ?>
                rows.push(['<?= $method ?>', <?= $amount ?>, <?= $payment_counts[$method] ?>]);
            <?php endforeach; ?>
            rows.push([]);
            rows.push(['Top Products', 'Qty Sold', 'Revenue', 'Profit']);
            <?php foreach ($tp_rows as $tp): ?>
                rows.push(['<?= addslashes($tp['product_name']) ?>', <?= $tp['total_qty'] ?>, <?= $tp['total_revenue'] ?>, <?= $tp['total_profit'] ?>]);
            <?php endforeach; ?>

            const csv = rows.map(r => r.map(c => '"' + String(c).replace(/"/g, '""') + '"').join(',')).join('\n');
            const blob = new Blob([csv], {
                type: 'text/csv;charset=utf-8;'
            });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'sales_report_<?= $date_from ?>_to_<?= $date_to ?>.csv';
            link.click();
        }
    </script>
</body>

</html>