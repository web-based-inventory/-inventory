<?php
include "../includes/auth_check.php";
protectReports();
include "../config/database.php";
include "../config/helpers.php";

$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-t');
$safe_from = mysqli_real_escape_string($conn, $date_from);
$safe_to = mysqli_real_escape_string($conn, $date_to);

// ============ BEST SELLING PRODUCTS (ALL) ============
// Same query logic as the Sales Report's Best Selling Products section,
// only without the LIMIT so every product is shown for the selected range.
$all_top_products = mysqli_query($conn, "
    SELECT p.product_name, p.sku, SUM(sd.quantity) AS total_qty,
           SUM(sd.subtotal) AS total_revenue,
           SUM(sd.profit) AS total_profit
    FROM sale_details sd
    JOIN products p ON sd.product_id = p.id
    JOIN sales s ON sd.sale_id = s.id
    WHERE DATE(s.created_at) BETWEEN '$safe_from' AND '$safe_to'
    GROUP BY sd.product_id
    ORDER BY total_qty DESC
");

$page_title = "Best Selling Products";
$report_settings = getShopSettings($conn);
$report_shop_name = htmlspecialchars($report_settings['shop_name']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Best Selling Products - <?= $report_shop_name ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include "../includes/theme-init.php"; ?>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Inter', system-ui, sans-serif;
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
                    <div class="flex flex-wrap items-center justify-between gap-4 mb-8 p-5 bg-white dark:bg-slate-800 rounded-2xl border border-gray-100 dark:border-slate-700 shadow-sm">
                        <div class="flex flex-wrap items-end gap-4">
                            <div>
                                <label class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 block">From Date</label>
                                <input type="date" name="date_from" value="<?= $date_from ?>" class="form-input text-sm dark:bg-slate-700 dark:text-gray-100" form="reportForm">
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 block">To Date</label>
                                <input type="date" name="date_to" value="<?= $date_to ?>" class="form-input text-sm dark:bg-slate-700 dark:text-gray-100" form="reportForm">
                            </div>
                            <div class="flex gap-2 items-end">
                                <button form="reportForm" class="btn btn-primary text-sm">Generate Report</button>
                                <a href="best-selling-products.php" class="btn btn-outline text-sm">Reset</a>
                            </div>
                        </div>
                        <a href="salereport.php?date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>" class="btn btn-outline gap-2 text-sm whitespace-nowrap">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                            </svg>
                            Back to Sales Report
                        </a>
                    </div>
                    <form method="GET" id="reportForm"></form>

                    <!-- All Best Selling Products -->
                    <div class="card mb-6">
                        <div class="card-header">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <h2 class="text-base font-bold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                    <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-sm">
                                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                                        </svg>
                                    </div>
                                    Best Selling Products
                                    <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 bg-gray-100 dark:bg-slate-700 px-2.5 py-1 rounded-full"><?= $date_from ?> to <?= $date_to ?></span>
                                </h2>
                            </div>
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
                                    $tp_rows = [];
                                    $max_revenue = 0;
                                    while ($tp = mysqli_fetch_assoc($all_top_products)) {
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
                                            <td class="font-medium"><?= htmlspecialchars($tp['product_name']) ?></td>
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
                                                    <h3 class="text-base font-semibold text-gray-500 dark:text-gray-400">No product sales found</h3>
                                                    <p class="text-sm text-gray-400 dark:text-gray-500 mt-1">No products were sold in this period.</p>
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
</body>

</html>
