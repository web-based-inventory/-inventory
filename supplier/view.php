<?php
include "../includes/auth_check.php";
protectSuppliers('view');
include "../config/database.php";
include "../config/helpers.php";

$page_title = "Supplier Details";

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$supplier = fetchOne($conn, "SELECT * FROM suppliers WHERE id = ?", [$id], "i");

if (!$supplier) {
    header("Location: index.php?error=" . urlencode("Supplier not found."));
    exit;
}

// ── Compute statistics ──
$amtCol = getPaymentAmountCol($conn, 'purchase_payments');

// Total Purchases
$total_purchases = 0;
$r = fetchOne($conn, "SELECT COALESCE(SUM(total_amount), 0) AS total FROM purchases WHERE supplier_id = ?", [$id], "i");
if ($r) $total_purchases = (float)$r['total'];

// Total Payments (paid_amount only)
$total_payments = 0;
if (columnExists($conn, 'supplier_payments', 'supplier_id')) {
    $r = fetchOne($conn, "SELECT COALESCE(SUM(paid_amount), 0) AS total FROM supplier_payments WHERE supplier_id = ?", [$id], "i");
    if ($r) $total_payments = (float)$r['total'];
} else {
    $r = fetchOne($conn, "SELECT COALESCE(SUM(pp.{$amtCol}), 0) AS total FROM purchase_payments pp INNER JOIN purchases pu ON pp.purchase_id = pu.id WHERE pu.supplier_id = ?", [$id], "i");
    if ($r) $total_payments = (float)$r['total'];
}

// Total Advance Created (overpayments)
$total_advance_created = 0;
$r = fetchOne($conn, "SELECT COALESCE(SUM(pp.advance_created), 0) AS total FROM purchase_payments pp INNER JOIN purchases pu ON pp.purchase_id = pu.id WHERE pu.supplier_id = ?", [$id], "i");
if ($r) $total_advance_created = (float)$r['total'];

// Last Purchase Date
$last_purchase = fetchOne($conn, "SELECT purchase_date FROM purchases WHERE supplier_id = ? ORDER BY purchase_date DESC LIMIT 1", [$id], "i");

// Recent Purchases (latest 5) — status computed from real-time payments
$rec_amt_col = getPaymentAmountCol($conn, 'purchase_payments');
$rec_adv = columnExists($conn, 'purchase_payments', 'advance_applied') ? " + COALESCE(pp.advance_applied, 0)" : "";
$recent_purchases = fetchAll($conn, "
    SELECT p.*, COALESCE(t.total_paid, 0) AS computed_paid
    FROM purchases p
    LEFT JOIN (
        SELECT purchase_id, SUM(pp.$rec_amt_col$rec_adv) AS total_paid
        FROM purchase_payments pp GROUP BY purchase_id
    ) t ON t.purchase_id = p.id
    WHERE p.supplier_id = ?
    ORDER BY p.purchase_date DESC LIMIT 5", [$id], "i");

// Recent Payments (latest 5)
if (columnExists($conn, 'supplier_payments', 'supplier_id')) {
    $recent_payments = fetchAll($conn, "SELECT *, ref_no AS invoice_no, 'Paid' AS payment_status FROM supplier_payments WHERE supplier_id = ? ORDER BY payment_date DESC, id DESC LIMIT 5", [$id], "i");
} else {
    $recent_payments = fetchAll($conn, "SELECT pp.*, pu.invoice_no FROM purchase_payments pp INNER JOIN purchases pu ON pp.purchase_id = pu.id WHERE pu.supplier_id = ? ORDER BY pp.payment_date DESC LIMIT 5", [$id], "i");
}

// Ensure balance is up-to-date before displaying
recalcSupplierBalance($conn, $id);
// Re-fetch supplier to get updated balance
$supplier = fetchOne($conn, "SELECT * FROM suppliers WHERE id = ?", [$id], "i");

// Use single source of truth from suppliers table
$outstanding_balance = (float)($supplier['outstanding_balance'] ?? 0);
$advance_credit = (float)($supplier['advance_credit'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - <?= htmlspecialchars(getShopSettings($conn)['shop_name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include "../includes/theme-init.php"; ?>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body class="bg-gray-50 dark:bg-slate-900">
    <div class="flex min-h-screen">
        <?php include "../includes/sidebar.php"; ?>

        <div class="flex-1 flex flex-col">
            <?php include "../includes/header.php"; ?>

            <main class="p-6">
                <div class="max-w-6xl mx-auto">

                    <!-- Back Button & Title -->
                    <div class="flex items-center justify-between mb-6">
                        <div class="flex items-center gap-3">
                            <a href="index.php" class="btn btn-outline gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                                </svg>
                                Back
                            </a>
                            <div>
                                <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100">Supplier Details</h1>
                                <p class="text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars($supplier['supplier_name']) ?></p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <a href="edit.php?id=<?= $supplier['id'] ?>" class="btn btn-primary gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                                Edit Supplier
                            </a>
                        </div>
                    </div>

                    <!-- Outstanding Balance Banner -->
                    <div class="card mb-6">
                        <div class="card-body py-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 <?= $outstanding_balance > 0 ? 'bg-red-100 dark:bg-red-900/30' : 'bg-emerald-100 dark:bg-emerald-900/30' ?>">
                                        <svg class="w-5 h-5 <?= $outstanding_balance > 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Outstanding Balance</p>
                                        <p class="text-2xl font-extrabold <?= $outstanding_balance > 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' ?>">
                                            <?= number_format($outstanding_balance, 2) ?> MMK
                                        </p>
                                    </div>
                                </div>
                                <?php if ($outstanding_balance > 0): ?>
                                    <span class="badge badge-danger text-sm">
                                        <span class="badge-dot"></span>
                                        Payable
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Advance Credit Banner -->
                    <div class="card mb-6">
                        <div class="card-body py-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 <?= $advance_credit > 0 ? 'bg-emerald-100 dark:bg-emerald-900/30' : 'bg-gray-100 dark:bg-gray-800' ?>">
                                        <svg class="w-5 h-5 <?= $advance_credit > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Advance Credit</p>
                                        <p class="text-2xl font-extrabold <?= $advance_credit > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-500 dark:text-gray-400' ?>">
                                            <?= number_format($advance_credit, 2) ?> MMK
                                        </p>
                                    </div>
                                </div>
                                <?php if ($advance_credit > 0): ?>
                                    <span class="badge badge-success text-sm">
                                        <span class="badge-dot"></span>
                                        Credit
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Supplier Information -->
                    <div class="card mb-6">
                        <div class="card-header">
                            <h2 class="text-base font-bold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                </svg>
                                Supplier Information
                            </h2>
                        </div>
                        <div class="card-body">
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                <div>
                                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Name</p>
                                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200"><?= htmlspecialchars($supplier['supplier_name']) ?></p>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Contact Person</p>
                                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200"><?= htmlspecialchars($supplier['contact_person'] ?? '-') ?></p>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Phone</p>
                                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200"><?= htmlspecialchars($supplier['phone'] ?? '-') ?></p>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Email</p>
                                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200"><?= htmlspecialchars($supplier['email'] ?? '-') ?></p>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Address</p>
                                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200"><?= htmlspecialchars($supplier['address'] ?? '-') ?></p>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Status</p>
                                    <?php if ($supplier['status'] === 'Active'): ?>
                                        <span class="badge badge-success"><span class="badge-dot"></span> Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger"><span class="badge-dot"></span> Inactive</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Statistics Cards -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                        <div class="stat-card">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center">
                                    <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Outstanding Balance</p>
                                    <p class="text-lg font-bold <?= $outstanding_balance > 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' ?>">
                                        <?= number_format($outstanding_balance, 2) ?> MMK
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900/30 rounded-full flex items-center justify-center">
                                    <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total Purchases</p>
                                    <p class="text-lg font-bold text-gray-800 dark:text-gray-200"><?= number_format($total_purchases, 2) ?> MMK</p>
                                </div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-emerald-100 dark:bg-emerald-900/30 rounded-full flex items-center justify-center">
                                    <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total Payments</p>
                                    <p class="text-lg font-bold text-gray-800 dark:text-gray-200"><?= number_format($total_payments, 2) ?> MMK</p>
                                </div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-emerald-100 dark:bg-emerald-900/30 rounded-full flex items-center justify-center">
                                    <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Advance Credit</p>
                                    <p class="text-lg font-bold <?= $advance_credit > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-800 dark:text-gray-200' ?>">
                                        <?= number_format($advance_credit, 2) ?> MMK
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Last Purchase Date -->
                    <div class="card mb-6">
                        <div class="card-body py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-purple-100 dark:bg-purple-900/30 rounded-full flex items-center justify-center">
                                    <svg class="w-5 h-5 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Last Purchase Date</p>
                                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200">
                                        <?= $last_purchase ? date('d M Y, h:i A', strtotime($last_purchase['purchase_date'])) : 'No purchases yet' ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Purchases -->
                    <div class="card mb-6">
                        <div class="card-header">
                            <h2 class="text-base font-bold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                </svg>
                                Recent Purchases
                            </h2>
                            <span class="text-xs text-gray-400 bg-gray-100 px-2.5 py-1 rounded-full font-medium"><?= count($recent_purchases) ?> purchase(s)</span>
                        </div>
                        <div class="card-body">
                            <?php if (count($recent_purchases) > 0): ?>
                                <div class="table-wrap">
                                    <table class="data-table w-full">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Invoice No</th>
                                                <th>Date</th>
                                                <th class="num">Total</th>
                                                <th class="center">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $i = 1; foreach ($recent_purchases as $p): ?>
                                                <tr>
                                                    <td><?= $i++ ?></td>
                                                    <td class="font-medium text-sm"><?= htmlspecialchars($p['invoice_no']) ?></td>
                                                    <td class="text-sm"><?= date('d M Y', strtotime($p['purchase_date'])) ?></td>
                                                    <td class="num text-sm font-semibold"><?= number_format($p['total_amount'], 2) ?></td>
                                                    <td class="center">
                                                        <?php
                                                        $cp = (float)($p['computed_paid'] ?? 0);
                                                        $cta = (float)$p['total_amount'];
                                                        if ($cta > 0 && $cp >= $cta) $p_status = 'Paid';
                                                        elseif ($cp > 0) $p_status = 'Partial';
                                                        else $p_status = 'Unpaid';
                                                        if ($p_status === 'Paid'):
                                                        ?>
                                                            <span class="badge badge-success"><span class="badge-dot"></span> Paid</span>
                                                        <?php elseif ($p_status === 'Partial'): ?>
                                                            <span class="badge badge-warning"><span class="badge-dot"></span> Partial</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-danger"><span class="badge-dot"></span> Unpaid</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-8">
                                    <svg class="w-12 h-12 mx-auto text-gray-300 dark:text-slate-600 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                    </svg>
                                    <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400">No purchases yet</h3>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Payments -->
                    <div class="card mb-6">
                        <div class="card-header">
                            <h2 class="text-base font-bold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                                </svg>
                                Recent Payments
                            </h2>
                            <span class="text-xs text-gray-400 bg-gray-100 px-2.5 py-1 rounded-full font-medium"><?= count($recent_payments) ?> payment(s)</span>
                        </div>
                        <div class="card-body">
                            <?php if (count($recent_payments) > 0): ?>
                                <div class="table-wrap">
                                    <table class="data-table w-full">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Invoice No</th>
                                                <th>Date</th>
                                                <th>Method</th>
                                                <th class="num">Cash</th>
                                                <th class="num">KBZ Pay</th>
                                                <th class="num">Total Paid</th>
                                                <th class="center">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $i = 1; foreach ($recent_payments as $pay): ?>
                                                <tr>
                                                    <td><?= $i++ ?></td>
                                                    <td class="font-medium text-sm"><?= htmlspecialchars($pay['invoice_no']) ?></td>
                                                    <td class="text-sm"><?= date('d M Y', strtotime($pay['payment_date'])) ?></td>
                                                    <td>
                                                        <span class="badge badge-info text-xs"><?= htmlspecialchars($pay['payment_method']) ?></span>
                                                    </td>
                                                    <td class="num text-sm"><?= number_format($pay['cash_amount'] ?? 0, 2) ?></td>
                                                    <td class="num text-sm"><?= number_format($pay['kbzpay_amount'] ?? 0, 2) ?></td>
                                                    <td class="num text-sm font-semibold"><?= number_format($pay['paid_amount'] ?? 0, 2) ?></td>
                                                    <td class="center">
                                                        <?php if (($pay['payment_status'] ?? '') === 'Paid'): ?>
                                                            <span class="badge badge-success"><span class="badge-dot"></span> Paid</span>
                                                        <?php elseif (($pay['payment_status'] ?? '') === 'Partial'): ?>
                                                            <span class="badge badge-warning"><span class="badge-dot"></span> Partial</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-danger"><span class="badge-dot"></span> Unpaid</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-8">
                                    <svg class="w-12 h-12 mx-auto text-gray-300 dark:text-slate-600 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                                    </svg>
                                    <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400">No payments yet</h3>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </main>
        </div>
    </div>

    <?php include "../includes/toast.php"; ?>
    <?php include "../includes/footer.php"; ?>
</body>

</html>
