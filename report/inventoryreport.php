<?php
include "../includes/auth_check.php";
protectReports();
include "../config/database.php";
include "../config/helpers.php";

// ============ INVENTORY STATS ============
$total_products = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM products WHERE status='Active'"));
$total_stock = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(current_stock), 0) AS total FROM products WHERE status='Active'"));
$total_stock_value = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(current_stock * purchase_price), 0) AS total FROM products WHERE status='Active' AND current_stock > 0"));
$total_stock_retail = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(current_stock * selling_price), 0) AS total FROM products WHERE status='Active' AND current_stock > 0"));

// ============ LOW STOCK PRODUCTS ============
$low_stock = mysqli_query($conn, "
    SELECT p.product_name, p.sku, p.current_stock, p.purchase_price, p.selling_price,
           c.name AS category_name, u.unit_symbol
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN units u ON p.unit_id = u.unit_id
    WHERE p.status = 'Active' AND p.current_stock <= 10
    ORDER BY p.current_stock ASC
");
$low_stock_count = mysqli_num_rows($low_stock);

// ============ OUT OF STOCK ============
$out_of_stock = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM products WHERE status='Active' AND current_stock = 0"));

// ============ STOCK BY CATEGORY ============
$category_stock = mysqli_query($conn, "
    SELECT c.name AS category_name, COUNT(p.id) AS product_count,
           COALESCE(SUM(p.current_stock), 0) AS total_stock,
           COALESCE(SUM(p.current_stock * p.purchase_price), 0) AS stock_value
    FROM categories c
    LEFT JOIN products p ON c.id = p.category_id AND p.status = 'Active'
    WHERE c.status = 'Active'
    GROUP BY c.id
    ORDER BY stock_value DESC
");

$page_title = "Inventory Reports";
$report_settings = getShopSettings($conn);
$report_shop_name = htmlspecialchars($report_settings['shop_name']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Reports - <?= $report_shop_name ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include "../includes/theme-init.php"; ?>
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
                    <div class="gap-4 mb-2  flex justify-end ">
                        <button onclick="exportExcel()" class="btn btn-outline gap-2 text-sm whitespace-nowrap">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            Export Excel
                        </button>
                    </div>

                    <!-- Quick Stats Row -->
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6" id="exportArea">
                        <!-- Total Products -->
                        <div class="stat-card bg-blue-50 dark:bg-blue-900/30 rounded-xl p-5">
                            <div class="flex items-center gap-3">
                                <svg class="w-12 h-12 text-blue-600 dark:text-blue-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                </svg>
                                <div>
                                    <p class="text-sm text-blue-600">Total Product</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white leading-none"><?= number_format($total_products['cnt']) ?></p>
                                </div>
                            </div>
                        </div>
                        <!-- Total Stock -->
                        <div class="stat-card bg-emerald-50 dark:bg-emerald-900/30 rounded-xl p-5">
                            <div class="flex items-center gap-3">
                                <svg class="w-12 h-12 text-emerald-600 dark:text-emerald-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
                                </svg>
                                <div>
                                    <p class="text-green-700 text-sm">Total Stock</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white leading-none"><?= number_format($total_stock['total']) ?></p>
                                    <p class="text-xs text-emerald-600 dark:text-emerald-400 mt-1"><?= number_format($total_stock_value['total']) ?> Ks value</p>
                                </div>
                            </div>
                        </div>
                        <!-- Low Stock -->
                        <div class="stat-card bg-amber-50 dark:bg-amber-900/30 rounded-xl p-5">
                            <div class="flex items-center gap-3">
                                <svg class="w-12 h-12 text-amber-600 dark:text-amber-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <div>
                                    <p class="text-sm text-amber-600">Low Stock</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white leading-none"><?= number_format($low_stock_count) ?></p>
                                    <p class="text-xs text-amber-600 dark:text-amber-400 mt-1">products low</p>
                                </div>
                            </div>
                        </div>
                        <!-- Out of Stock -->
                        <div class="stat-card bg-red-50 dark:bg-red-900/30 rounded-xl p-5">
                            <div class="flex items-center gap-3">
                                <svg class="w-12 h-12 text-red-600 dark:text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <div>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white leading-none"><?= number_format($out_of_stock['cnt']) ?></p>
                                    <p class="text-xs text-red-600 dark:text-red-400 mt-1">out of stock</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Out of Stock Alert -->
                    <?php if ($out_of_stock['cnt'] > 0): ?>
                        <div class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl flex items-center gap-3">
                            <svg class="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <div>
                                <p class="text-sm font-semibold text-red-700 dark:text-red-300"><?= $out_of_stock['cnt'] ?> product(s) are out of stock</p>
                                <p class="text-xs text-red-600 dark:text-red-400">Consider restocking these items.</p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Low Stock Products -->
                    <div class="card mb-6">
                        <div class="card-header">
                            <h2 class="text-base font-bold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                                Low Stock Products (10 or fewer units)
                            </h2>
                        </div>
                        <div class="table-wrap">
                            <table class="data-table w-full">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Product</th>
                                        <th>SKU</th>
                                        <th>Category</th>
                                        <th class="num">Stock</th>
                                        <th class="num">Cost Price</th>
                                        <th class="num">Retail Price</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $i = 1;
                                    while ($row = mysqli_fetch_assoc($low_stock)): ?>
                                        <tr>
                                            <td><?= $i++ ?></td>
                                            <td class="font-medium"><?= htmlspecialchars($row['product_name']) ?></td>
                                            <td><?= htmlspecialchars($row['sku'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($row['category_name'] ?? 'N/A') ?></td>
                                            <td class="num">
                                                <?php if ($row['current_stock'] == 0): ?>
                                                    <span class="text-red-600 font-bold">0 (Out of Stock)</span>
                                                <?php else: ?>
                                                    <span class="text-amber-600 font-semibold"><?= $row['current_stock'] ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="num"><?= number_format($row['purchase_price'], 2) ?> Ks</td>
                                            <td class="num"><?= number_format($row['selling_price'], 2) ?> Ks</td>
                                        </tr>
                                    <?php endwhile; ?>
                                    <?php if ($i == 1): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-16">
                                                <div class="flex flex-col items-center">
                                                    <div class="w-14 h-14 rounded-2xl bg-emerald-50 flex items-center justify-center mb-4">
                                                        <svg class="w-7 h-7 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                        </svg>
                                                    </div>
                                                    <h3 class="text-base font-semibold text-gray-500">All products are well stocked</h3>
                                                    <p class="text-sm text-gray-400 mt-1">No products with 10 or fewer units.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Stock by Category -->
                    <div class="card mb-6">
                        <div class="card-header">
                            <h2 class="text-base font-bold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                                </svg>
                                Stock by Category
                            </h2>
                        </div>
                        <div class="table-wrap">
                            <table class="data-table w-full">
                                <thead class="">
                                    <tr>
                                        <th>#</th>
                                        <th>Category</th>
                                        <th class="num">Products</th>
                                        <th class="num">Total Stock</th>
                                        <th class="num">Stock Value</th>
                                        <th class="w-48">Share</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $cat_rows = [];
                                    while ($cs = mysqli_fetch_assoc($category_stock)) $cat_rows[] = $cs;
                                    $cat_total = array_sum(array_column($cat_rows, 'stock_value'));
                                    $cat_colors = ['bg-indigo-500', 'bg-emerald-500', 'bg-blue-500', 'bg-amber-500', 'bg-purple-500', 'bg-red-500', 'bg-cyan-500'];
                                    $ci = 0;
                                    foreach ($cat_rows as $cs):
                                        $share = $cat_total > 0 ? ($cs['stock_value'] / $cat_total) * 100 : 0;
                                        $color = $cat_colors[$ci % count($cat_colors)];
                                        $ci++;
                                    ?>
                                        <tr>
                                            <td><?= $ci ?></td>
                                            <td class="font-medium"><?= htmlspecialchars($cs['category_name']) ?></td>
                                            <td class="num"><?= number_format($cs['product_count']) ?></td>
                                            <td class="num"><?= number_format($cs['total_stock']) ?></td>
                                            <td class="num"><?= number_format($cs['stock_value']) ?> Ks</td>
                                            <td>
                                                <div class="flex items-center gap-2">
                                                    <div class="progress-bar flex-1">
                                                        <div class="progress-fill <?= $color ?>" style="width: <?= $share ?>%"></div>
                                                    </div>
                                                    <span class="text-xs text-gray-500 w-10 text-right"><?= number_format($share, 1) ?>%</span>
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
                                                    <p class="text-sm text-gray-400 mt-1">No categories found.</p>
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
        function exportExcel() {
            const rows = [];
            rows.push(['Inventory Report']);
            rows.push([]);
            rows.push(['Total Products', <?= $total_products['cnt'] ?>]);
            rows.push(['Total Stock Units', <?= $total_stock['total'] ?>]);
            rows.push(['Stock Value (Cost)', <?= $total_stock_value['total'] ?>]);
            rows.push(['Stock Value (Retail)', <?= $total_stock_retail['total'] ?>]);
            rows.push([]);
            rows.push(['Low Stock Products']);
            rows.push(['Product', 'SKU', 'Category', 'Stock', 'Cost Price', 'Retail Price']);
            <?php
            mysqli_data_seek($low_stock, 0);
            while ($row = mysqli_fetch_assoc($low_stock)):
            ?>
                rows.push(['<?= addslashes($row['product_name']) ?>', '<?= addslashes($row['sku'] ?? '') ?>', '<?= addslashes($row['category_name'] ?? '') ?>', <?= $row['current_stock'] ?>, <?= $row['purchase_price'] ?>, <?= $row['selling_price'] ?>]);
            <?php endwhile; ?>
            rows.push([]);
            rows.push(['Stock by Category']);
            rows.push(['Category', 'Products', 'Total Stock', 'Stock Value']);
            <?php foreach ($cat_rows as $cs): ?>
                rows.push(['<?= addslashes($cs['category_name']) ?>', <?= $cs['product_count'] ?>, <?= $cs['total_stock'] ?>, <?= $cs['stock_value'] ?>]);
            <?php endforeach; ?>

            const csv = rows.map(r => r.map(c => '"' + String(c).replace(/"/g, '""') + '"').join(',')).join('\n');
            const blob = new Blob([csv], {
                type: 'text/csv;charset=utf-8;'
            });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'inventory_report_<?= date('Y-m-d') ?>.csv';
            link.click();
        }
    </script>
</body>

</html>