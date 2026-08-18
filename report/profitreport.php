<?php
include "../includes/auth_check.php";
protectReports();
include "../config/database.php";
include "../config/helpers.php";

$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-t');
$safe_from = mysqli_real_escape_string($conn, $date_from);
$safe_to = mysqli_real_escape_string($conn, $date_to);

// ============ PROFIT SUMMARY ============
$profit_summary = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(sd.subtotal), 0) AS revenue,
           COALESCE(SUM(sd.purchase_price * sd.quantity), 0) AS cost,
           COALESCE(SUM(sd.profit), 0) AS profit
    FROM sale_details sd
    JOIN sales s ON sd.sale_id = s.id
    WHERE DATE(s.created_at) BETWEEN '$safe_from' AND '$safe_to'
"));

// ============ PROFIT BY PRODUCT ============
$profit_by_product = mysqli_query($conn, "
    SELECT p.product_name, p.sku,
           SUM(sd.quantity) AS total_qty,
           SUM(sd.subtotal) AS revenue,
           SUM(sd.purchase_price * sd.quantity) AS cost,
           SUM(sd.profit) AS profit
    FROM sale_details sd
    JOIN products p ON sd.product_id = p.id
    JOIN sales s ON sd.sale_id = s.id
    WHERE DATE(s.created_at) BETWEEN '$safe_from' AND '$safe_to'
    GROUP BY sd.product_id
    ORDER BY profit DESC
");

// ============ PROFIT BY CATEGORY ============
$profit_by_category = mysqli_query($conn, "
    SELECT c.name AS category_name,
           SUM(sd.quantity) AS total_qty,
           SUM(sd.subtotal) AS revenue,
           SUM(sd.purchase_price * sd.quantity) AS cost,
           SUM(sd.profit) AS profit
    FROM sale_details sd
    JOIN products p ON sd.product_id = p.id
    JOIN categories c ON p.category_id = c.id
    JOIN sales s ON sd.sale_id = s.id
    WHERE DATE(s.created_at) BETWEEN '$safe_from' AND '$safe_to'
    GROUP BY c.id
    ORDER BY profit DESC
");

// ============ DAILY PROFIT ============
$daily_profit = mysqli_query($conn, "
    SELECT DATE(s.created_at) AS day,
           SUM(sd.subtotal) AS revenue,
           SUM(sd.purchase_price * sd.quantity) AS cost,
           SUM(sd.profit) AS profit
    FROM sale_details sd
    JOIN sales s ON sd.sale_id = s.id
    WHERE DATE(s.created_at) BETWEEN '$safe_from' AND '$safe_to'
    GROUP BY DATE(s.created_at)
    ORDER BY day ASC
");

$page_title = "Profit Reports";
$report_settings = getShopSettings($conn);
$report_shop_name = htmlspecialchars($report_settings['shop_name']);
$gross_profit = $profit_summary['revenue'] - $profit_summary['cost'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profit Reports - <?= $report_shop_name ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include "../includes/theme-init.php"; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="../assets/css/style.css">
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
                                <a href="profitreport.php" class="btn btn-outline text-sm">Reset</a>
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

                    <!-- Summary Stats -->
                    <?php
                    $margin = $profit_summary['revenue'] > 0 ? ($profit_summary['profit'] / $profit_summary['revenue']) * 100 : 0;
                    ?>
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6" id="exportArea">
                        <!-- Total Revenue -->
                        <div class="stat-card bg-emerald-50 dark:bg-emerald-900/30 rounded-xl p-5">
                            <div class="flex items-center gap-3">
                                <svg class="w-12 h-12 text-emerald-600 dark:text-emerald-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <div>
                                    <p class="text-sm text-emerald-600">Total Revenue</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white leading-none truncate" title="Full amount: <?= number_format($profit_summary['revenue']) ?> Ks"><?= compactMoney($profit_summary['revenue']) ?> <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Ks</span></p>
                                </div>
                            </div>
                        </div>
                        <!-- Total Cost -->
                        <div class="stat-card bg-orange-50 dark:bg-orange-900/30 rounded-xl p-5">
                            <div class="flex items-center gap-3">
                                <svg class="w-12 h-12 text-orange-600 dark:text-orange-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                                </svg>
                                <div>
                                    <p class="text-sm text-orange-600">Total Cost</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white leading-none truncate" title="Full amount: <?= number_format($profit_summary['cost']) ?> Ks"><?= compactMoney($profit_summary['cost']) ?> <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Ks</span></p>
                                </div>
                            </div>
                        </div>
                        <!-- Gross Profit -->
                        <div class="stat-card bg-blue-50 dark:bg-blue-900/30 rounded-xl p-5">
                            <div class="flex items-center gap-3">
                                <svg class="w-12 h-12 text-blue-600 dark:text-blue-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                                </svg>
                                <div>
                                    <p class="text-sm text-blue-600">Gross Profit</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white leading-none truncate" title="Full amount: <?= number_format($gross_profit) ?> Ks"><?= compactMoney($gross_profit) ?> <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Ks</span></p>
                                </div>
                            </div>
                        </div>
                        <!-- Net Profit -->
                        <div class="stat-card bg-purple-50 dark:bg-purple-900/30 rounded-xl p-5">
                            <div class="flex items-center gap-3">
                                <svg class="w-12 h-12 text-purple-600 dark:text-purple-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                </svg>
                                <div>
                                    <p class="text-sm text-purple-600">Net Profit</p>
                                    <p class="text-2xl font-bold <?= $profit_summary['profit'] < 0 ? 'text-red-600' : 'text-gray-900 dark:text-white' ?> leading-none truncate" title="Full amount: <?= number_format($profit_summary['profit']) ?> Ks"><?= compactMoney($profit_summary['profit']) ?> <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Ks</span></p>
                                    <p class="text-xs <?= $margin >= 20 ? 'text-emerald-600 dark:text-emerald-400' : ($margin >= 10 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400') ?> mt-1"><?= number_format($margin, 1) ?>% margin</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Daily Profit Chart -->
                    <div class="card mb-6">
                        <div class="card-header">
                            <h2 class="text-base font-bold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                </svg>
                                Daily Profit Trend
                            </h2>
                        </div>
                        <div class="card-body">
                            <div class="h-64 mb-4">
                                <canvas id="dailyProfitChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Profit by Product -->
                    <div class="card mb-6">
                        <div class="card-header">
                            <h2 class="text-base font-bold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                                </svg>
                                Profit by Product
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
                                        <th class="num">Cost</th>
                                        <th class="num">Profit</th>
                                        <th class="num">Margin</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $prod_rows = [];
                                    while ($pr = mysqli_fetch_assoc($profit_by_product)) $prod_rows[] = $pr;
                                    $rank = 1;
                                    foreach ($prod_rows as $pr):
                                        $pm = $pr['revenue'] > 0 ? ($pr['profit'] / $pr['revenue']) * 100 : 0;
                                    ?>
                                        <tr>
                                            <td><?= $rank++ ?></td>
                                            <td class="font-medium"><?= htmlspecialchars($pr['product_name']) ?></td>
                                            <td><?= htmlspecialchars($pr['sku'] ?? 'N/A') ?></td>
                                            <td class="num"><?= number_format($pr['total_qty']) ?></td>
                                            <td class="num"><?= number_format($pr['revenue']) ?> Ks</td>
                                            <td class="num"><?= number_format($pr['cost']) ?> Ks</td>
                                            <td class="num font-semibold <?= $pr['profit'] < 0 ? 'text-red-600' : 'text-emerald-600' ?>"><?= number_format($pr['profit']) ?> Ks</td>
                                            <td class="num <?= $pm < 0 ? 'text-red-600' : ($pm < 10 ? 'text-amber-600' : '') ?>"><?= number_format($pm, 1) ?>%</td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($prod_rows)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-16">
                                                <div class="flex flex-col items-center">
                                                    <div class="w-14 h-14 rounded-2xl bg-amber-50 flex items-center justify-center mb-4">
                                                        <svg class="w-7 h-7 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                                                        </svg>
                                                    </div>
                                                    <h3 class="text-base font-semibold text-gray-500">No sales data</h3>
                                                    <p class="text-sm text-gray-400 mt-1">No products were sold in this period.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Profit by Category -->
                    <div class="card mb-6">
                        <div class="card-header">
                            <h2 class="text-base font-bold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                                </svg>
                                Profit by Category
                            </h2>
                        </div>
                        <div class="table-wrap">
                            <table class="data-table w-full">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Category</th>
                                        <th class="num">Qty Sold</th>
                                        <th class="num">Revenue</th>
                                        <th class="num">Cost</th>
                                        <th class="num">Profit</th>
                                        <th class="w-48">Margin</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $cat_rows = [];
                                    while ($cr = mysqli_fetch_assoc($profit_by_category)) $cat_rows[] = $cr;
                                    $ci = 0;
                                    $cat_colors = ['bg-indigo-500', 'bg-emerald-500', 'bg-blue-500', 'bg-amber-500', 'bg-purple-500', 'bg-red-500', 'bg-cyan-500'];
                                    foreach ($cat_rows as $cr):
                                        $cm = $cr['revenue'] > 0 ? ($cr['profit'] / $cr['revenue']) * 100 : 0;
                                        $color = $cat_colors[$ci % count($cat_colors)];
                                        $ci++;
                                    ?>
                                        <tr>
                                            <td><?= $ci ?></td>
                                            <td class="font-medium"><?= htmlspecialchars($cr['category_name']) ?></td>
                                            <td class="num"><?= number_format($cr['total_qty']) ?></td>
                                            <td class="num"><?= number_format($cr['revenue']) ?> Ks</td>
                                            <td class="num"><?= number_format($cr['cost']) ?> Ks</td>
                                            <td class="num font-semibold <?= $cr['profit'] < 0 ? 'text-red-600' : 'text-emerald-600' ?>"><?= number_format($cr['profit']) ?> Ks</td>
                                            <td>
                                                <div class="flex items-center gap-2">
                                                    <div class="progress-bar flex-1">
                                                        <div class="progress-fill <?= $cm >= 20 ? 'bg-emerald-500' : ($cm >= 10 ? 'bg-amber-500' : 'bg-red-500') ?>" style="width: <?= min(100, max(0, $cm)) ?>%"></div>
                                                    </div>
                                                    <span class="text-xs text-gray-500 w-10 text-right"><?= number_format($cm, 1) ?>%</span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($cat_rows)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-16">
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
                </div>
            </main>
        </div>
    </div>

    <?php include "../includes/toast.php"; ?>
    <?php include "../includes/modal.php"; ?>
    <?php include "../includes/footer.php"; ?>

    <script>
        // Daily Profit Chart
        <?php
        $chart_labels = [];
        $chart_revenue = [];
        $chart_cost = [];
        $chart_profit = [];
        mysqli_data_seek($daily_profit, 0);
        while ($d = mysqli_fetch_assoc($daily_profit)) {
            $chart_labels[] = date('d M', strtotime($d['day']));
            $chart_revenue[] = (float)$d['revenue'];
            $chart_cost[] = (float)$d['cost'];
            $chart_profit[] = (float)$d['profit'];
        }
        ?>
        const ctx = document.getElementById('dailyProfitChart');
        if (ctx) {
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?= json_encode($chart_labels) ?>,
                    datasets: [{
                            label: 'Revenue',
                            data: <?= json_encode($chart_revenue) ?>,
                            borderColor: 'rgb(16, 185, 129)',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            fill: true,
                            tension: 0.4,
                            pointRadius: 3,
                        },
                        {
                            label: 'Cost',
                            data: <?= json_encode($chart_cost) ?>,
                            borderColor: 'rgb(239, 68, 68)',
                            backgroundColor: 'rgba(239, 68, 68, 0.1)',
                            fill: true,
                            tension: 0.4,
                            pointRadius: 3,
                        },
                        {
                            label: 'Profit',
                            data: <?= json_encode($chart_profit) ?>,
                            borderColor: 'rgb(99, 102, 241)',
                            backgroundColor: 'rgba(99, 102, 241, 0.1)',
                            fill: true,
                            tension: 0.4,
                            pointRadius: 3,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(ctx) {
                                    return ctx.dataset.label + ': ' + ctx.parsed.y.toLocaleString() + ' Ks';
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

        function exportExcel() {
            const rows = [];
            rows.push(['Profit Report - <?= $date_from ?> to <?= $date_to ?>']);
            rows.push([]);
            rows.push(['Summary']);
            rows.push(['Total Revenue', <?= $profit_summary['revenue'] ?>]);
            rows.push(['Total Cost', <?= $profit_summary['cost'] ?>]);
            rows.push(['Net Profit', <?= $profit_summary['profit'] ?>]);
            rows.push(['Profit Margin', '<?= number_format($margin, 1) ?>%']);
            rows.push([]);
            rows.push(['Profit by Product']);
            rows.push(['Product', 'SKU', 'Qty Sold', 'Revenue', 'Cost', 'Profit', 'Margin']);
            <?php foreach ($prod_rows as $pr): ?>
                $pm = <?= $pr['revenue'] ?> > 0 ? (<?= $pr['profit'] ?> / <?= $pr['revenue'] ?>) * 100 : 0;
                rows.push(['<?= addslashes($pr['product_name']) ?>', '<?= addslashes($pr['sku'] ?? '') ?>', <?= $pr['total_qty'] ?>, <?= $pr['revenue'] ?>, <?= $pr['cost'] ?>, <?= $pr['profit'] ?>, $pm.toFixed(1) + '%']);
            <?php endforeach; ?>
            rows.push([]);
            rows.push(['Profit by Category']);
            rows.push(['Category', 'Qty Sold', 'Revenue', 'Cost', 'Profit', 'Margin']);
            <?php foreach ($cat_rows as $cr): ?>
                rows.push(['<?= addslashes($cr['category_name']) ?>', <?= $cr['total_qty'] ?>, <?= $cr['revenue'] ?>, <?= $cr['cost'] ?>, <?= $cr['profit'] ?>, '<?= $cr['revenue'] > 0 ? number_format(($cr['profit'] / $cr['revenue']) * 100, 1) : '0.0' ?>%']);
            <?php endforeach; ?>

            const csv = rows.map(r => r.map(c => '"' + String(c).replace(/"/g, '""') + '"').join(',')).join('\n');
            const blob = new Blob([csv], {
                type: 'text/csv;charset=utf-8;'
            });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'profit_report_<?= $date_from ?>_to_<?= $date_to ?>.csv';
            link.click();
        }
    </script>
</body>

</html>