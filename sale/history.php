<?php
include "../includes/auth_check.php";
protectSales('view');
include "../config/database.php";
include "../config/helpers.php";

// ============ DELETE SALE ============
if (isset($_GET['delete_id'])) {
    protectSales('delete');
    $id = (int)$_GET['delete_id'];
    $details = mysqli_query($conn, "SELECT * FROM sale_details WHERE sale_id='$id'");
    while ($row = mysqli_fetch_assoc($details)) {
        $pid = $row['product_id'];
        $qty = $row['current_stock'] ?? $row['quantity'] ?? 0;
        mysqli_query($conn, "UPDATE products SET current_stock = current_stock + $qty WHERE id='$pid'");
    }
    mysqli_query($conn, "DELETE FROM sale_payments WHERE sale_id='$id'");
    mysqli_query($conn, "DELETE FROM sale_details WHERE sale_id='$id'");
    mysqli_query($conn, "DELETE FROM sales WHERE id='$id'");
    header("Location: history.php?success=deleted");
    exit;
}

// ============ AJAX INVOICE DETAILS ============
if (isset($_GET['view_id'])) {
    header('Content-Type: application/json');
    $vid = (int)$_GET['view_id'];
    $sale = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT s.*, u.name AS cashier_name
        FROM sales s
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.id = $vid
    "));
    if (!$sale) {
        echo json_encode(['error' => 'Sale not found']);
        exit;
    }
    $details = mysqli_query($conn, "
        SELECT sd.*, p.product_name, p.sku
        FROM sale_details sd
        LEFT JOIN products p ON sd.product_id = p.id
        WHERE sd.sale_id = $vid
        ORDER BY sd.id ASC
    ");
    $items = [];
    while ($d = mysqli_fetch_assoc($details)) $items[] = $d;

    // Payment info
    $amtCol = getPaymentAmountCol($conn, 'sale_payments');
    $payments = mysqli_query($conn, "SELECT * FROM sale_payments WHERE sale_id = $vid");
    $total_paid = 0;
    $payment_method = 'Cash';
    $cash_received = 0;
    $kbzpay_received = 0;
    if (mysqli_num_rows($payments) > 0) {
        while ($p = mysqli_fetch_assoc($payments)) {
            $total_paid += $p[$amtCol];
            $payment_method = $p['payment_method'] ?? 'Cash';
            $cash_received += (float)($p['cash_amount'] ?? 0);
            $kbzpay_received += (float)($p['kbzpay_amount'] ?? 0);
        }
    }
    $grand_total = floatval($sale['total_amount']);
    $change = max(0, $total_paid - $grand_total);
    $has_split = columnExists($conn, 'sale_payments', 'cash_amount') && columnExists($conn, 'sale_payments', 'kbzpay_amount');

    echo json_encode([
        'sale' => $sale,
        'items' => $items,
        'total_paid' => $total_paid,
        'payment_method' => $payment_method,
        'change' => $change,
        'cash_received' => $cash_received,
        'kbzpay_received' => $kbzpay_received,
        'has_split' => $has_split
    ]);
    exit;
}

// Filters
$search = $_GET['search'] ?? '';
$cashier = $_GET['cashier'] ?? '';
$payment_method = $_GET['payment_method'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$sql = "SELECT s.*, u.name AS cashier_name,
               COALESCE(d.items_count, 0) AS items_count,
               COALESCE(d.total_qty, 0) AS total_qty,
               COALESCE(d.subtotal, 0) AS subtotal,
               COALESCE(sp.payment_method, 'Cash') AS payment_method
        FROM sales s
        LEFT JOIN users u ON s.user_id = u.id
        INNER JOIN (
            SELECT sale_id, COUNT(*) AS items_count, SUM(quantity) AS total_qty, SUM(subtotal) AS subtotal
            FROM sale_details GROUP BY sale_id
        ) d ON d.sale_id = s.id
        LEFT JOIN sale_payments sp ON sp.id = (
            SELECT id FROM sale_payments WHERE sale_id = s.id ORDER BY id ASC LIMIT 1
        )
        WHERE 1";

if ($search !== '') {
    $safe = mysqli_real_escape_string($conn, $search);
    $sql .= " AND s.invoice_no LIKE '%$safe%'";
}
if ($cashier !== '') {
    $safe = mysqli_real_escape_string($conn, $cashier);
    $sql .= " AND u.name LIKE '%$safe%'";
}
if ($payment_method !== '') {
    $safe = mysqli_real_escape_string($conn, $payment_method);
    $sql .= " AND COALESCE(sp.payment_method, 'Cash') = '$safe'";
}
if ($date_from !== '') {
    $sql .= " AND DATE(s.created_at) >= '$date_from'";
}
if ($date_to !== '') {
    $sql .= " AND DATE(s.created_at) <= '$date_to'";
}

$sql .= " ORDER BY s.id DESC";
$result = mysqli_query($conn, $sql);

// Overall stats
$stats = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total_sales, COALESCE(SUM(total_amount), 0) AS total_revenue FROM sales"));

// Today's sales count
$today_stats = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count, COALESCE(SUM(total_amount), 0) AS revenue FROM sales WHERE DATE(created_at) = CURDATE()"));

// Filtered period stats (for the current filter range, for summary cards)
$period_sql = "SELECT COUNT(*) AS total_sales, COALESCE(SUM(total_amount), 0) AS total_revenue FROM sales WHERE 1";
if ($date_from !== '') $period_sql .= " AND DATE(created_at) >= '$date_from'";
if ($date_to !== '') $period_sql .= " AND DATE(created_at) <= '$date_to'";
$period_stats = mysqli_fetch_assoc(mysqli_query($conn, $period_sql));

$avg_sale = $period_stats['total_sales'] > 0 ? round($period_stats['total_revenue'] / $period_stats['total_sales']) : 0;



// Cashier list for filter dropdown
$cashiers = mysqli_query($conn, "SELECT DISTINCT u.id, u.name, u.role FROM sales s JOIN users u ON s.user_id = u.id WHERE u.name IS NOT NULL ORDER BY u.name");

$page_title = "Sales History";
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales History - <?= htmlspecialchars(getShopSettings($conn)['shop_name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include "../includes/theme-init.php"; ?>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .table-header-sticky th {
            position: sticky;
            top: 0;
            z-index: 10;
        }
    </style>
</head>

<body class="bg-[#eaf4fc] dark:bg-slate-900">
    <div class="flex min-h-screen">
        <?php include "../includes/sidebar.php"; ?>
        <div class="flex-1 flex flex-col">
            <?php include "../includes/header.php"; ?>
            <main class="p-4 lg:p-6">
                <div class="max-w-7xl mx-auto">
                    <?php if (isset($_GET['success'])): ?>
                        <div class="mb-6 bg-green-50 border border-green-200 text-green-700 px-5 py-4 rounded-xl flex items-start gap-3 shadow-sm">
                            <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <span class="text-sm font-medium">Sale deleted successfully.</span>
                        </div>
                    <?php endif; ?>

                    <div class="flex justify-end gap-2 mb-6">
                        <button onclick="exportExcel()" class="btn btn-outline gap-2 text-sm">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            Export Excel
                        </button>
                        <a href="pos.php" class="btn btn-primary gap-2 text-sm">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                            New Sale
                        </a>
                    </div>

                    <!-- Summary Cards -->
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                        <div class="stat-card bg-blue-50 dark:bg-blue-900/30 rounded-2xl border border-blue-200 dark:border-blue-800 p-5 shadow-md hover:shadow-xl hover:-translate-y-1 transition-all duration-300">
                            <div class="flex items-center gap-4">
                                <div class="flex-shrink-0">
                                    <svg class="w-11 h-11 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-xs text-blue-600 dark:text-blue-400 font-medium">Today's Sales</p>
                                    <p class="text-xl font-bold text-gray-900 dark:text-gray-100 mt-0.5"><?= number_format($today_stats['count']) ?></p>
                                    <p class="text-sm font-semibold text-blue-600 dark:text-blue-400"><?= number_format($today_stats['revenue']) ?> Ks</p>
                                </div>
                            </div>
                        </div>
                        <div class="stat-card bg-emerald-50 dark:bg-emerald-900/30 rounded-2xl border border-emerald-200 dark:border-emerald-800 p-5 shadow-md hover:shadow-xl hover:-translate-y-1 transition-all duration-300">
                            <div class="flex items-center gap-4">
                                <div class="flex-shrink-0">
                                    <svg class="w-11 h-11 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-xs text-emerald-600 dark:text-emerald-400 font-medium">Total Revenue</p>
                                    <?php $full_revenue = $period_stats['total_revenue']; ?>
                                    <p class="text-xl font-bold text-gray-900 dark:text-gray-100 mt-0.5 truncate" title="Full amount: <?= number_format($full_revenue) ?> Ks"><?= compactMoney($full_revenue) ?> Ks</p>
                                    <p class="text-sm text-emerald-600/70 dark:text-emerald-400/70"><?= $date_from || $date_to ? 'Filtered period' : 'All time' ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="stat-card bg-purple-50 dark:bg-purple-900/30 rounded-2xl border border-purple-200 dark:border-purple-800 p-5 shadow-md hover:shadow-xl hover:-translate-y-1 transition-all duration-300">
                            <div class="flex items-center gap-4">
                                <div class="flex-shrink-0">
                                    <svg class="w-11 h-11 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-xs text-purple-600 dark:text-purple-400 font-medium">Total Transactions</p>
                                    <p class="text-xl font-bold text-gray-900 dark:text-gray-100 mt-0.5"><?= number_format($period_stats['total_sales']) ?></p>
                                    <p class="text-sm text-purple-600/70 dark:text-purple-400/70"><?= $date_from || $date_to ? 'Filtered period' : 'All time' ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="stat-card bg-orange-50 dark:bg-orange-900/30 rounded-2xl border border-orange-200 dark:border-orange-800 p-5 shadow-md hover:shadow-xl hover:-translate-y-1 transition-all duration-300">
                            <div class="flex items-center gap-4">
                                <div class="flex-shrink-0">
                                    <svg class="w-11 h-11 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-xs text-orange-600 dark:text-orange-400 font-medium">Average Sale</p>
                                    <p class="text-xl font-bold text-gray-900 dark:text-gray-100 mt-0.5"><?= number_format($avg_sale) ?> Ks</p>
                                    <p class="text-sm text-orange-600/70 dark:text-orange-400/70">Per transaction</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="bg-white dark:bg-slate-800 rounded-2xl border border-gray-200 dark:border-slate-700 shadow-sm mb-6 overflow-hidden">
                        <div class="px-5 py-4 border-b border-gray-100 dark:border-slate-700 flex items-center gap-2.5">
                            <div class="w-8 h-8 rounded-lg bg-indigo-50 dark:bg-indigo-500/10 flex items-center justify-center">
                                <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                                </svg>
                            </div>
                            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Filters</h3>
                        </div>
                        <form method="GET" class="p-5">
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                                <div>
                                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1.5 block">From Date</label>
                                    <input type="date" name="date_from" value="<?= $date_from ?>" class="form-input text-sm">
                                </div>
                                <div>
                                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1.5 block">To Date</label>
                                    <input type="date" name="date_to" value="<?= $date_to ?>" class="form-input text-sm">
                                </div>
                                <div>
                                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1.5 block">Payment Method</label>
                                    <select name="payment_method" class="form-input text-sm">
                                        <option value="">All Methods</option>
                                        <option value="Cash" <?= $payment_method === 'Cash' ? 'selected' : '' ?>>Cash</option>
                                        <option value="KBZPay" <?= $payment_method === 'KBZPay' ? 'selected' : '' ?>>KBZPay</option>
                                        <option value="Mixed" <?= $payment_method === 'Mixed' ? 'selected' : '' ?>>Mixed</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1.5 block">Cashier</label>
                                    <select name="cashier" class="form-input text-sm">
                                        <option value="">All Cashiers</option>
                                        <?php mysqli_data_seek($cashiers, 0);
                                        while ($ca = mysqli_fetch_assoc($cashiers)): ?>
                                            <option value="<?= htmlspecialchars($ca['name']) ?>" <?= $cashier === $ca['name'] ? 'selected' : '' ?>><?= htmlspecialchars($ca['name']) ?> (<?= htmlspecialchars($ca['role']) ?>)</option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1.5 block">Search Invoice</label>
                                    <div class="relative">
                                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Invoice no..." class="form-input text-sm pl-9">
                                        <svg class="w-4 h-4 text-gray-400 dark:text-gray-500 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                        </svg>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-3 mt-5 pt-4 border-t border-gray-100 dark:border-slate-700">
                                <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-500 hover:bg-indigo-600 text-white text-sm font-medium rounded-xl transition-all duration-200 shadow-sm hover:shadow-md hover:shadow-indigo-500/25">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                    Search
                                </button>
                                <a href="history.php" class="inline-flex items-center gap-2 px-5 py-2.5 bg-gray-100 dark:bg-slate-700 hover:bg-gray-200 dark:hover:bg-slate-600 text-gray-700 dark:text-gray-300 text-sm font-medium rounded-xl transition-all duration-200">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                    Reset
                                </a>
                            </div>
                        </form>
                    </div>

                    <!-- Sales Table -->
                    <div class="card overflow-hidden shadow-sm border border-gray-200 rounded-xl">
                        <div class="table-wrap">
                            <table class="data-table w-full">
                                <thead class="bg-gray-100 border-b border-gray-200">
                                    <tr>
                                        <th>#</th>
                                        <th>Invoice No</th>
                                        <th>Date</th>
                                        <th>Cashier</th>
                                        <th class="num">Subtotal</th>
                                        <th class="num">Discount</th>
                                        <th class="num">Grand Total</th>
                                        <th class="center">Payment</th>
                                        <th class="center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (mysqli_num_rows($result) > 0): $count = 1;
                                        while ($row = mysqli_fetch_assoc($result)):
                                            $method = $row['payment_method'] ?? 'Cash';
                                            $method_badge = match ($method) {
                                                'KBZPay' => 'badge-info',
                                                'Mixed' => 'badge-purple',
                                                default => 'badge-success',
                                            };
                                    ?>
                                            <tr class="hover:bg-indigo-50/40 transition-colors border-b border-gray-100 last:border-0">
                                                <td><?= $count++ ?></td>
                                                <td><?= htmlspecialchars($row['invoice_no']) ?></td>
                                                <td><?= date('d M Y, h:i A', strtotime($row['created_at'])) ?></td>
                                                <td><?= htmlspecialchars($row['cashier_name'] ?? '—') ?></td>
                                                <td class="num"><?= number_format((float)$row['subtotal']) ?> Ks</td>
                                                <td class="num text-red-500"><?= (float)$row['discount'] > 0 ? '-' . number_format((float)$row['discount'], 2) . '%' : '—' ?></td>
                                                <td class="num font-semibold"><?= number_format((float)$row['total_amount']) ?> Ks</td>
                                                <td class="center">
                                                    <span class="badge <?= $method_badge ?> whitespace-nowrap text-xs">
                                                        <span class="badge-dot"></span>
                                                        <?= $method ?>
                                                    </span>
                                                </td>
                                                <td class="center">
                                                    <div class="actions">
                                                        <a href="invoice.php?id=<?= $row['id'] ?>" target="_blank" class="btn btn-sm bg-indigo-50 text-indigo-600 hover:bg-indigo-100 rounded-lg font-medium text-xs px-3">View</a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile;
                                    else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-16">
                                                <div class="flex flex-col items-center">
                                                    <svg class="w-14 h-14 text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z" />
                                                    </svg>
                                                    <h3 class="text-base font-semibold text-gray-500 dark:text-gray-400">No sales found</h3>
                                                    <p class="text-sm text-gray-400 mt-1">No sales match your filters. Try adjusting the search criteria.</p>
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

    <style>
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }
    </style>

    <?php include "../includes/toast.php"; ?>
    <?php include "../includes/footer.php"; ?>

    <script>
        // ============ Invoice Modal ============
        function viewInvoice(id) {
            const modal = document.getElementById('invoiceModal');
            const body = document.getElementById('modalBody');
            modal.classList.remove('hidden');
            body.innerHTML = '<div class="text-center py-8 text-gray-400"><div class="w-8 h-8 border-2 border-indigo-500 border-t-transparent rounded-full animate-spin mx-auto mb-3"></div><p class="text-sm">Loading invoice...</p></div>';

            fetch('history.php?view_id=' + id)
                .then(function(r) {
                    return r.json();
                })
                .then(function(data) {
                    if (data.error) {
                        body.innerHTML = '<p class="text-center text-red-500 py-8">' + data.error + '</p>';
                        return;
                    }
                    var s = data.sale,
                        items = data.items;
                    document.getElementById('modalInvoiceNo').textContent = s.invoice_no;

                    var subtotal = 0;
                    var totalProfit = 0;
                    for (var i = 0; i < items.length; i++) {
                        subtotal += parseFloat(items[i].subtotal) || 0;
                        totalProfit += parseFloat(items[i].profit) || 0;
                    }
                    var discount = parseFloat(s.discount) || 0;
                    var grandTotal = parseFloat(s.total_amount) || 0;
                    var totalPaid = parseFloat(data.total_paid) || 0;
                    var change = parseFloat(data.change) || 0;

                    var html = '';
                    // Info header
                    html += '<div class="grid grid-cols-2 gap-4 mb-5 pb-5 border-b border-gray-100 dark:border-slate-700">';
                    html += '<div><p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Invoice No</p><p class="text-sm font-bold text-gray-900 dark:text-gray-100 mt-0.5">' + s.invoice_no + '</p></div>';
                    html += '<div class="text-right"><p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date & Time</p><p class="text-sm font-semibold text-gray-900 dark:text-gray-100 mt-0.5">' + new Date(s.created_at).toLocaleString('en-US', {
                        day: '2-digit',
                        month: 'short',
                        year: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    }) + '</p></div>';
                    html += '<div><p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Cashier</p><p class="text-sm font-semibold text-gray-900 dark:text-gray-100 mt-0.5">' + (s.cashier_name || '—') + '</p></div>';
                    html += '<div class="text-right"><p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Payment Method</p><p class="text-sm font-semibold text-gray-900 dark:text-gray-100 mt-0.5">' + (data.payment_method || 'Cash') + '</p></div>';
                    html += '</div>';

                    // Items table
                    html += '<div class="overflow-x-auto mb-5"><table class="w-full text-sm">';
                    html += '<thead><tr class="bg-gray-50 dark:bg-slate-700/50 border-b border-gray-200 dark:border-slate-600">';
                    html += '<th class="text-left py-2.5 px-3 font-semibold text-gray-600 dark:text-gray-400 text-xs uppercase">#</th>';
                    html += '<th class="text-left py-2.5 px-3 font-semibold text-gray-600 dark:text-gray-400 text-xs uppercase">Product</th>';
                    html += '<th class="text-left py-2.5 px-3 font-semibold text-gray-600 dark:text-gray-400 text-xs uppercase">SKU</th>';
                    html += '<th class="text-center py-2.5 px-3 font-semibold text-gray-600 dark:text-gray-400 text-xs uppercase">Qty</th>';
                    html += '<th class="text-right py-2.5 px-3 font-semibold text-gray-600 dark:text-gray-400 text-xs uppercase">Unit Price</th>';
                    html += '<th class="text-right py-2.5 px-3 font-semibold text-gray-600 dark:text-gray-400 text-xs uppercase">Subtotal</th>';
                    html += '<th class="text-right py-2.5 px-3 font-semibold text-gray-600 dark:text-gray-400 text-xs uppercase">Profit</th>';
                    html += '</tr></thead><tbody>';
                    for (var i = 0; i < items.length; i++) {
                        var it = items[i];
                        html += '<tr class="border-b border-gray-50 dark:border-slate-700 hover:bg-gray-50/50 dark:hover:bg-slate-700/30">';
                        html += '<td class="py-2.5 px-3 text-gray-400 font-mono">' + (i + 1) + '</td>';
                        html += '<td class="py-2.5 px-3 font-medium text-gray-800 dark:text-gray-200">' + (it.product_name || 'Product #' + it.product_id) + '</td>';
                        html += '<td class="py-2.5 px-3 text-gray-500 dark:text-gray-400 text-xs">' + (it.sku || '—') + '</td>';
                        html += '<td class="py-2.5 px-3 text-center font-semibold text-gray-800 dark:text-gray-200">' + it.quantity + '</td>';
                        html += '<td class="py-2.5 px-3 text-right text-gray-700 dark:text-gray-300">' + Number(it.selling_price).toLocaleString() + ' Ks</td>';
                        html += '<td class="py-2.5 px-3 text-right font-semibold text-gray-800 dark:text-gray-200">' + Number(it.subtotal).toLocaleString() + ' Ks</td>';
                        var pVal = parseFloat(it.profit) || 0;
                        var pColor = pVal < 0 ? 'text-red-600' : 'text-emerald-600';
                        var pLabel = pVal < 0 ? 'Loss ' : '';
                        html += '<td class="py-2.5 px-3 text-right font-semibold ' + pColor + '">' + pLabel + pVal.toLocaleString() + ' Ks</td>';
                        html += '</tr>';
                    }
                    html += '</tbody></table></div>';

                    // Payment Summary
                    html += '<div class="border-t border-gray-200 dark:border-slate-600 pt-4 space-y-1.5 max-w-[280px] ml-auto">';
                    html += '<div class="flex justify-between text-sm"><span class="text-gray-500 dark:text-gray-400">Subtotal</span><span class="font-semibold text-gray-800 dark:text-gray-200">' + subtotal.toLocaleString() + ' Ks</span></div>';
                    if (discount > 0) html += '<div class="flex justify-between text-sm"><span class="text-gray-500 dark:text-gray-400">Discount (' + discount.toFixed(2) + '%)</span><span class="font-semibold text-red-500">- ' + (subtotal * discount / 100).toLocaleString() + ' Ks</span></div>';
                    html += '<div class="flex justify-between text-sm"><span class="text-gray-500 dark:text-gray-400">Tax</span><span class="text-gray-400">— Ks</span></div>';
                    html += '<div class="flex justify-between text-base pt-2 border-t border-gray-100 dark:border-slate-600"><span class="font-bold text-gray-900 dark:text-gray-100">Grand Total</span><span class="font-bold text-emerald-600">' + grandTotal.toLocaleString() + ' Ks</span></div>';
                    var profitLabel = totalProfit < 0 ? 'Loss' : 'Profit';
                    var profitColor = totalProfit < 0 ? 'text-red-600' : 'text-emerald-600';
                    html += '<div class="flex justify-between text-sm"><span class="text-gray-500 dark:text-gray-400">' + profitLabel + '</span><span class="font-semibold ' + profitColor + '">' + totalProfit.toLocaleString() + ' Ks</span></div>';
                    html += '<div class="border-t border-dashed border-gray-300 dark:border-slate-600 my-2"></div>';
                    html += '<div class="flex justify-between text-sm"><span class="text-gray-500 dark:text-gray-400">Amount Paid</span><span class="font-semibold text-gray-800 dark:text-gray-200">' + totalPaid.toLocaleString() + ' Ks</span></div>';
                    if (data.has_split && data.payment_method === 'Mixed') {
                        html += '<div class="flex justify-between text-sm"><span class="text-gray-500 dark:text-gray-400">Cash</span><span class="font-semibold text-gray-800 dark:text-gray-200">' + (parseFloat(data.cash_received) || 0).toLocaleString() + ' Ks</span></div>';
                        html += '<div class="flex justify-between text-sm"><span class="text-gray-500 dark:text-gray-400">KBZPay</span><span class="font-semibold text-gray-800 dark:text-gray-200">' + (parseFloat(data.kbzpay_received) || 0).toLocaleString() + ' Ks</span></div>';
                    }
                    html += '<div class="flex justify-between text-sm"><span class="text-gray-500 dark:text-gray-400">Change</span><span class="font-semibold ' + (change > 0 ? 'text-emerald-600' : 'text-gray-800 dark:text-gray-200') + '">' + change.toLocaleString() + ' Ks</span></div>';
                    html += '</div>';

                    // Action buttons
                    html += '<div class="flex mt-6 pt-5 border-t border-gray-200 dark:border-slate-600">';
                    html += '<a href="history.php" class="w-full bg-white dark:bg-slate-700 border border-gray-300 dark:border-slate-600 hover:bg-gray-50 dark:hover:bg-slate-600 text-gray-700 dark:text-gray-300 text-sm font-semibold py-2.5 px-4 rounded-xl flex items-center justify-center gap-2 transition shadow-sm"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>Back</a>';
                    html += '</div>';

                    body.innerHTML = html;
                })
                .catch(function() {
                    body.innerHTML = '<p class="text-center text-red-500 py-8">Failed to load invoice. Please try again.</p>';
                });
        }

        function closeInvoiceModal() {
            document.getElementById('invoiceModal').classList.add('hidden');
        }

        // Close modal on Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeInvoiceModal();
        });

        // ============ Export Excel ============
        function exportExcel() {
            const rows = [];
            rows.push(['Sales Report']);
            rows.push([]);
            rows.push(['Summary']);
            rows.push(['Today\'s Sales', <?= $today_stats['count'] ?>]);
            rows.push(['Total Revenue', <?= $period_stats['total_revenue'] ?>]);
            rows.push(['Total Transactions', <?= $period_stats['total_sales'] ?>]);
            rows.push(['Average Sale', <?= $avg_sale ?>]);
            rows.push([]);
            rows.push(['No', 'Invoice No', 'Date', 'Cashier', 'Grand Total', 'Payment Method']);
            <?php
            mysqli_data_seek($result, 0);
            $row_num = 1;
            while ($row = mysqli_fetch_assoc($result)):
            ?>
                rows.push([<?= $row_num++ ?>, '<?= addslashes($row['invoice_no']) ?>', '<?= date('d M Y, h:i A', strtotime($row['created_at'])) ?>', '<?= addslashes($row['cashier_name'] ?? '—') ?>', <?= (float)$row['total_amount'] ?>, '<?= $row['payment_method'] ?? 'Cash' ?>']);
            <?php endwhile; ?>

            const csv = rows.map(r => r.map(c => '"' + String(c).replace(/"/g, '""') + '"').join(',')).join('\n');
            const blob = new Blob(['\uFEFF' + csv], {
                type: 'text/csv;charset=utf-8;'
            });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'sales_report_<?= date('Y-m-d') ?>.csv';
            link.click();
        }
    </script>
</body>

</html>