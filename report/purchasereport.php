<?php
include "../includes/auth_check.php";
protectReports();
include "../config/database.php";
include "../config/helpers.php";

$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-t');
$safe_from = mysqli_real_escape_string($conn, $date_from);
$safe_to = mysqli_real_escape_string($conn, $date_to);

// Total purchases in range
$purchase_summary = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) AS total_purchases, COALESCE(SUM(total_amount), 0) AS total_amount
    FROM purchases WHERE purchase_date BETWEEN '$safe_from' AND '$safe_to'
"));

// Paid vs Unpaid - calculate from payment data
$paid_stats = ['Paid' => ['count' => 0, 'amount' => 0], 'Partial' => ['count' => 0, 'amount' => 0], 'Unpaid' => ['count' => 0, 'amount' => 0]];
$purAmtCol = getPaymentAmountCol($conn, 'purchase_payments');
$hasAdvance = columnExists($conn, 'purchase_payments', 'advance_applied');

// total_paid includes cash paid + advance credit applied (matches updatePurchasePaymentStatus logic)
$advanceExpr = $hasAdvance ? " + COALESCE(pp.advance_applied, 0)" : "";
$paid_summary = mysqli_query($conn, "
    SELECT p.id, p.total_amount,
           COALESCE(SUM(pp.$purAmtCol$advanceExpr), 0) AS total_paid,
           COALESCE(SUM(pp.$purAmtCol), 0) AS cash_paid
    FROM purchases p
    LEFT JOIN purchase_payments pp ON pp.purchase_id = p.id
    WHERE p.purchase_date BETWEEN '$safe_from' AND '$safe_to'
    GROUP BY p.id, p.total_amount
");
$outstanding_balance = 0;
$outstanding_count = 0;
$total_paid_amount = 0;

while ($ps = mysqli_fetch_assoc($paid_summary)) {
    $ta = (float)$ps['total_amount'];
    $tp = (float)$ps['total_paid'];
    $remaining = max(0, round($ta - $tp, 2));

    // Paid Amount = total amount allocated to purchases (cash + advance credit)
    // Capped at purchase total to handle any floating-point edge cases.
    $total_paid_amount += min($ta, $tp);

    if ($remaining <= 0.01) {
        $paid_stats['Paid']['count']++;
        $paid_stats['Paid']['amount'] += $ta;
    } elseif ($tp > 0.01) {
        $paid_stats['Partial']['count']++;
        $paid_stats['Partial']['amount'] += $remaining;
        $outstanding_balance += $remaining;
        $outstanding_count++;
    } else {
        $paid_stats['Unpaid']['count']++;
        $paid_stats['Unpaid']['amount'] += $ta;
        $outstanding_balance += $ta;
        $outstanding_count++;
    }
}
// Top suppliers
$top_suppliers = mysqli_query($conn, "
    SELECT s.supplier_name, COUNT(*) AS purchase_count, SUM(p.total_amount) AS total_spent
    FROM purchases p
    JOIN suppliers s ON p.supplier_id = s.id
    WHERE p.purchase_date BETWEEN '$safe_from' AND '$safe_to'
    GROUP BY p.supplier_id
    ORDER BY total_spent DESC LIMIT 10
");

// Top purchased products
$top_products = mysqli_query($conn, "
    SELECT pr.product_name, SUM(pd.quantity) AS total_qty, SUM(pd.subtotal) AS total_cost
    FROM purchase_details pd
    JOIN products pr ON pd.product_id = pr.id
    JOIN purchases p ON pd.purchase_id = p.id
    WHERE p.purchase_date BETWEEN '$safe_from' AND '$safe_to'
    GROUP BY pd.product_id
    ORDER BY total_qty DESC LIMIT 10
");

// Daily purchases
$daily_purchases = mysqli_query($conn, "
    SELECT DATE(purchase_date) AS day, COUNT(*) AS count, SUM(total_amount) AS total
    FROM purchases WHERE purchase_date BETWEEN '$safe_from' AND '$safe_to'
    GROUP BY DATE(purchase_date) ORDER BY day DESC
");

// Monthly trend
$monthly_trend = mysqli_query($conn, "
    SELECT DATE_FORMAT(purchase_date, '%Y-%m') AS month, COUNT(*) AS count, SUM(total_amount) AS total
    FROM purchases WHERE purchase_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(purchase_date, '%Y-%m') ORDER BY month ASC
");

$page_title = "Purchase Reports";
$report_settings = getShopSettings($conn);
$report_shop_name = htmlspecialchars($report_settings['shop_name']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Reports - <?= $report_shop_name ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include "../includes/theme-init.php"; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .stat-card {
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
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
                    <!-- Header & Date Filter -->
                    <div class="flex flex-wrap items-center justify-between gap-4 mb-6 p-5 bg-white dark:bg-slate-800 rounded-2xl border border-gray-100 dark:border-slate-700 shadow-sm">
                        <div class="flex flex-wrap items-end gap-4">
                            <div>
                                <label class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 block">From Date</label>
                                <input type="date" name="date_from" value="<?= $date_from ?>" class="form-input text-sm" form="reportForm">
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 block">To Date</label>
                                <input type="date" name="date_to" value="<?= $date_to ?>" class="form-input text-sm" form="reportForm">
                            </div>
                            <div class="flex gap-2 items-end">
                                <button form="reportForm" class="btn btn-primary text-sm">Generate Report</button>
                                <a href="purchasereport.php" class="btn btn-outline text-sm">Reset</a>
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

                    <!-- Summary Cards -->
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                        <!-- Total Purchases -->
                        <div class="stat-card bg-blue-50 dark:bg-blue-900/30 rounded-xl p-5">
                            <div class="flex items-center gap-3">
                                <svg class="w-12 h-12 text-blue-600 dark:text-blue-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                                </svg>
                                <div>
                                    <p class="text-sm text-blue-600">Total Purchase</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white leading-none"><?= number_format($purchase_summary['total_purchases']) ?></p>
                                </div>
                            </div>
                        </div>
                        <!-- Total Purchase Amount -->
                        <div class="stat-card bg-emerald-50 dark:bg-emerald-900/30 rounded-xl p-5">
                            <div class="flex items-center gap-3">
                                <svg class="w-12 h-12 text-emerald-600 dark:text-emerald-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <div>
                                    <p class="text-sm text-emerald-600">Total Purchase Amount</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white leading-none truncate" title="Full amount: <?= number_format($purchase_summary['total_amount']) ?> Ks"><?= compactMoney($purchase_summary['total_amount']) ?> <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Ks</span></p>
                                </div>
                            </div>
                        </div>
                        <!-- Outstanding Balance -->
                        <div class="stat-card bg-red-50 dark:bg-red-900/30 rounded-xl p-5">
                            <div class="flex items-center gap-3">
                                <svg class="w-12 h-12 text-red-600 dark:text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <div>
                                    <p class="text-sm text-red-600">Outstanding Balance</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white leading-none truncate" title="Full amount: <?= number_format($outstanding_balance) ?> Ks"><?= compactMoney($outstanding_balance) ?> <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Ks</span></p>
                                    <p class="text-xs text-red-500 dark:text-red-400 mt-1"><?= $outstanding_count ?> purchases</p>
                                </div>
                            </div>
                        </div>
                        <!-- Advance Payment -->
                        <div class="stat-card bg-amber-50 dark:bg-amber-900/30 rounded-xl p-5">
                            <div class="flex items-center gap-3">
                                <svg class="w-12 h-12 text-amber-600 dark:text-amber-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <div>
                                    <p class="text-sm text-amber-600">Paid Amount</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white leading-none truncate" title="Full amount: <?= number_format($total_paid_amount) ?> Ks"><?= compactMoney($total_paid_amount) ?> <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Ks</span></p>
                                    <p class="text-xs text-amber-600 dark:text-amber-400 mt-1"><?= $paid_stats['Paid']['count'] ?> purchases</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                        <div class="card">
                            <div class="card-header">
                                <h2 class="text-base font-bold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                    <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                                    </svg>
                                    Monthly Purchase Trend
                                </h2>
                            </div>
                            <div class="card-body">
                                <canvas id="monthlyChart" height="140"></canvas>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-header">
                                <h2 class="text-base font-bold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                    <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                    </svg>
                                    Top Suppliers
                                </h2>
                            </div>
                            <div class="card-body">
                                <canvas id="supplierChart" height="140"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Top Purchased Products -->
                    <div class="card mb-6">
                        <div class="card-header">
                            <h2 class="text-base font-bold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                </svg>
                                Top Purchased Products
                            </h2>
                        </div>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th class="w-[6%]">#</th>
                                        <th class="w-[30%]">Product</th>
                                        <th class="num w-[14%]">Total Qty</th>
                                        <th class="num w-[21%]">Total Spent</th>
                                        <th class="num w-[21%]">Avg Price</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $tp_count = 1;
                                    while ($tp = mysqli_fetch_assoc($top_products)): ?>
                                        <tr>
                                            <td><?= $tp_count++ ?></td>
                                            <td class="font-medium truncate-cell" title="<?= htmlspecialchars($tp['product_name']) ?>"><?= htmlspecialchars($tp['product_name']) ?></td>
                                            <td class="num"><?= number_format($tp['total_qty']) ?></td>
                                            <td class="num"><?= number_format($tp['total_cost']) ?> Ks</td>
                                            <td class="num"><?= $tp['total_qty'] > 0 ? number_format($tp['total_cost'] / $tp['total_qty'], 2) : '0.00' ?> Ks</td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Top Suppliers Table -->
                    <div class="card mb-6">
                        <div class="card-header">
                            <h2 class="text-base font-bold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                                </svg>
                                Supplier Performance
                            </h2>
                        </div>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th class="w-[25%]">#</th>
                                        <th class="w-[25%]">Supplier</th>
                                        <th class="num w-[25%]">Purchases Count</th>
                                        <th class="num w-[25%]">Total Spent</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $ts_count = 1;
                                    while ($ts = mysqli_fetch_assoc($top_suppliers)): ?>
                                        <tr>
                                            <td><?= $ts_count++ ?></td>
                                            <td class="font-medium truncate-cell" title="<?= htmlspecialchars($ts['supplier_name']) ?>"><?= htmlspecialchars($ts['supplier_name']) ?></td>
                                            <td class="num"><?= $ts['purchase_count'] ?></td>
                                            <td class="num"><?= number_format($ts['total_spent']) ?> Ks</td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Daily Purchases -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="text-base font-bold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                                Daily Purchases
                            </h2>
                        </div>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th class="w-[8%]">#</th>
                                        <th class="w-[42%]">Date</th>
                                        <th class="num w-[20%]">Count</th>
                                        <th class="num w-[30%]">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $dc = 1;
                                    while ($dp = mysqli_fetch_assoc($daily_purchases)): ?>
                                        <tr>
                                            <td><?= $dc++ ?></td>
                                            <td class="font-medium"><?= date('d M Y (D)', strtotime($dp['day'])) ?></td>
                                            <td class="num"><?= $dp['count'] ?></td>
                                            <td class="num"><?= number_format($dp['total']) ?> Ks</td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <?php include "../includes/toast.php"; ?>
    <?php include "../includes/footer.php"; ?>

    <script>
        <?php
        $m_labels = [];
        $m_values = [];
        mysqli_data_seek($monthly_trend, 0);
        while ($m = mysqli_fetch_assoc($monthly_trend)) {
            $m_labels[] = date('M Y', strtotime($m['month'] . '-01'));
            $m_values[] = (float)$m['total'];
        }
        ?>
        // Monthly Trend Chart
        const mCtx = document.getElementById('monthlyChart');
        if (mCtx) {
            new Chart(mCtx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($m_labels) ?>,
                    datasets: [{
                        label: 'Purchase Amount (Ks)',
                        data: <?= json_encode($m_values) ?>,
                        backgroundColor: 'rgba(99, 102, 241, 0.7)',
                        borderColor: '#6366f1',
                        borderWidth: 1,
                        borderRadius: 6,
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
                                label: (ctx) => ctx.parsed.y.toLocaleString() + ' Ks'
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: v => v.toLocaleString()
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

        <?php
        $s_labels = [];
        $s_values = [];
        mysqli_data_seek($top_suppliers, 0);
        while ($s = mysqli_fetch_assoc($top_suppliers)) {
            $s_labels[] = $s['supplier_name'];
            $s_values[] = (float)$s['total_spent'];
        }
        ?>
        // Supplier Chart
        const sCtx = document.getElementById('supplierChart');
        if (sCtx) {
            new Chart(sCtx, {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode($s_labels) ?>,
                    datasets: [{
                        data: <?= json_encode($s_values) ?>,
                        backgroundColor: ['#6366f1', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#f97316', '#ec4899', '#14b8a6', '#64748b'],
                        borderWidth: 0,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                boxWidth: 12,
                                padding: 8,
                                font: {
                                    size: 11
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => ctx.label + ': ' + ctx.parsed.toLocaleString() + ' Ks'
                            }
                        }
                    }
                }
            });
        }


        // Export Excel
        function exportExcel() {
            const rows = [];
            rows.push(['Purchase Report - <?= $date_from ?> to <?= $date_to ?>']);
            rows.push([]);
            rows.push(['Summary']);
            rows.push(['Total Purchases', <?= $purchase_summary['total_purchases'] ?>]);
            rows.push(['Total Spent', <?= $purchase_summary['total_amount'] ?>]);
            rows.push(['Paid', <?= $paid_stats['Paid']['amount'] ?>]);
            rows.push(['Unpaid', <?= $paid_stats['Unpaid']['amount'] ?>]);
            rows.push([]);
            rows.push(['Top Purchased Products']);
            rows.push(['#', 'Product', 'Qty Purchased', 'Total Cost']);
            <?php
            mysqli_data_seek($top_products, 0);
            $tp_idx = 1;
            while ($tp = mysqli_fetch_assoc($top_products)):
            ?>
                rows.push([<?= $tp_idx++ ?>, '<?= addslashes($tp['product_name']) ?>', <?= $tp['total_qty'] ?>, <?= $tp['total_cost'] ?>]);
            <?php endwhile; ?>
            rows.push([]);
            rows.push(['Supplier Performance']);
            rows.push(['#', 'Supplier', 'Purchases', 'Total Spent']);
            <?php
            mysqli_data_seek($top_suppliers, 0);
            $ts_idx = 1;
            while ($ts = mysqli_fetch_assoc($top_suppliers)):
            ?>
                rows.push([<?= $ts_idx++ ?>, '<?= addslashes($ts['supplier_name']) ?>', <?= $ts['purchase_count'] ?>, <?= $ts['total_spent'] ?>]);
            <?php endwhile; ?>
            rows.push([]);
            rows.push(['Daily Purchases']);
            rows.push(['Date', 'Purchases', 'Total']);
            <?php
            mysqli_data_seek($daily_purchases, 0);
            while ($dp = mysqli_fetch_assoc($daily_purchases)):
            ?>
                rows.push(['<?= date('d M Y', strtotime($dp['day'])) ?>', <?= $dp['count'] ?>, <?= $dp['total'] ?>]);
            <?php endwhile; ?>

            const csv = rows.map(r => r.map(c => '"' + String(c).replace(/"/g, '""') + '"').join(',')).join('\n');
            const blob = new Blob(['\uFEFF' + csv], {
                type: 'text/csv;charset=utf-8;'
            });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'purchase_report_<?= $date_from ?>_to_<?= $date_to ?>.csv';
            link.click();
        }
    </script>
</body>

</html>