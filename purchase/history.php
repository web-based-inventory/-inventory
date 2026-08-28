<?php
include "../includes/auth_check.php";
include "../config/database.php";
include "../config/helpers.php";
if (!isAdmin() && !isStaff()) {
    header("Location: ../dashboard/index.php");
    exit;
}

// ============ DELETE PURCHASE ============
if (isset($_GET['confirm_delete']) && isAdmin()) {
    $id = (int)$_GET['confirm_delete'];

    // Get supplier_id before deletion
    $del_sup = mysqli_fetch_assoc(mysqli_query($conn, "SELECT supplier_id FROM purchases WHERE id='$id'"));
    $del_supplier_id = $del_sup ? (int)$del_sup['supplier_id'] : 0;

    $details = mysqli_query($conn, "SELECT * FROM purchase_details WHERE purchase_id='$id'");
    while ($row = mysqli_fetch_assoc($details)) {
        $pid = $row['product_id'];
        $qty = $row['quantity'];
        mysqli_query($conn, "UPDATE products SET current_stock = current_stock - $qty WHERE id='$pid'");
    }
    mysqli_query($conn, "DELETE FROM purchase_payments WHERE purchase_id='$id'");
    mysqli_query($conn, "DELETE FROM purchase_details WHERE purchase_id='$id'");
    mysqli_query($conn, "DELETE FROM purchases WHERE id='$id'");

    // Recalculate supplier balance after deletion
    if ($del_supplier_id > 0) {
        recalcSupplierBalance($conn, $del_supplier_id);
        rebuildSupplierLedger($conn, $del_supplier_id);
    }

    header("Location: history.php?success=deleted");
    exit;
}

// Filters
$search = $_GET['search'] ?? '';
$supplier = $_GET['supplier'] ?? '';
$payment_status = $_GET['payment_status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$histFilterAmtCol = getPaymentAmountCol($conn, 'purchase_payments');
$histFilterAdv = columnExists($conn, 'purchase_payments', 'advance_applied') ? " + COALESCE(pp.advance_applied, 0)" : "";

$sql = "SELECT p.*, s.supplier_name,
        COALESCE(SUM(pp.$histFilterAmtCol$histFilterAdv), 0) AS computed_paid
        FROM purchases p
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        LEFT JOIN purchase_payments pp ON pp.purchase_id = p.id
        WHERE 1";

if ($search !== '') {
    $safe = mysqli_real_escape_string($conn, $search);
    $sql .= " AND p.invoice_no LIKE '%$safe%'";
}
if ($supplier !== '') {
    $safe = mysqli_real_escape_string($conn, $supplier);
    $sql .= " AND s.supplier_name LIKE '%$safe%'";
}
if ($date_from !== '') {
    $sql .= " AND DATE(p.purchase_date) >= '$date_from'";
}
if ($date_to !== '') {
    $sql .= " AND DATE(p.purchase_date) <= '$date_to'";
}

$sql .= " GROUP BY p.id";

// Filter by real-time payment status (computed from purchase_payments)
if ($payment_status !== '') {
    $safe = mysqli_real_escape_string($conn, $payment_status);
    if ($safe === 'Paid') {
        $sql .= " HAVING computed_paid >= p.total_amount";
    } elseif ($safe === 'Partial') {
        $sql .= " HAVING computed_paid > 0 AND computed_paid < p.total_amount";
    } elseif ($safe === 'Unpaid') {
        $sql .= " HAVING computed_paid <= 0";
    }
}

$sql .= " ORDER BY p.id DESC";
$result = mysqli_query($conn, $sql);

// Summary stats
$stats = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total_purchases, COALESCE(SUM(total_amount), 0) AS total_spent FROM purchases"));

$page_title = "Purchase History";
$pur_settings = getShopSettings($conn);
$pur_shop_name = htmlspecialchars($pur_settings['shop_name']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase History - <?= $pur_shop_name ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include "../includes/theme-init.php"; ?>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body class="bg-slate-50">
    <div class="flex min-h-screen">
        <?php include "../includes/sidebar.php"; ?>
        <div class="flex-1 flex flex-col">
            <?php include "../includes/header.php"; ?>
            <main class="p-4 lg:p-6">
                <div class="max-w-7xl mx-auto">
                    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
                        <div class="flex gap-2">
                            <button onclick="exportExcel()" class="btn btn-outline gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                Export Excel
                            </button>
                            <a href="add.php" class="btn btn-primary gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                </svg>
                                New Purchase
                            </a>
                        </div>
                    </div>

                    <?php if (isset($_GET['success'])): ?>
                        <div class="mb-6 bg-green-50 border border-green-200 text-green-700 px-5 py-4 rounded-xl flex items-start gap-3 shadow-sm">
                            <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <span class="text-sm font-medium">Purchase deleted successfully.</span>
                        </div>
                    <?php endif; ?>

                    <!-- Stats Cards -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                        <div class="bg-white rounded-xl border border-gray-200 p-5">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-indigo-100 rounded-full flex items-center justify-center">
                                    <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 font-medium">Total Purchases</p>
                                    <p class="text-xl font-bold text-gray-900 dark:text-gray-100"><?= number_format($stats['total_purchases']) ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="bg-white rounded-xl border border-gray-200 p-5">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-emerald-100 rounded-full flex items-center justify-center">
                                    <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 font-medium">Total Spent</p>
                                    <p class="text-xl font-bold text-emerald-600"><?= number_format($stats['total_spent']) ?> Ks</p>
                                </div>
                            </div>
                        </div>
                        <div class="bg-white rounded-xl border border-gray-200 p-5">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center">
                                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 font-medium">Average Purchase</p>
                                    <p class="text-xl font-bold text-blue-600">
                                        <?= $stats['total_purchases'] > 0 ? number_format($stats['total_spent'] / $stats['total_purchases']) : '0' ?> Ks
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="bg-white rounded-xl border border-gray-200 p-5">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-amber-100 rounded-full flex items-center justify-center">
                                    <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 font-medium">Today's Purchases</p>
                                    <p class="text-xl font-bold text-amber-600">
                                        <?php
                                        $today = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM purchases WHERE DATE(purchase_date) = CURDATE()"));
                                        echo number_format($today['cnt']);
                                        ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Search & Filter -->
                    <form method="GET" class="filter-bar mb-6">
                        <div class="grid sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-2">
                            <div class=" min-w-[200px]">
                                <label class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1 block">Invoice No</label>
                                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search invoice..." class="form-input text-sm">
                            </div>
                            <div class="min-w-[160px]">
                                <label class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1 block">Supplier</label>
                                <input type="text" name="supplier" value="<?= htmlspecialchars($supplier) ?>" placeholder="Supplier name..." class="form-input text-sm">
                            </div>
                            <div class="min-w-[150px]">
                                <label class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1 block">Payment</label>
                                <select name="payment_status" class="form-input text-sm">
                                    <option value="">All Status</option>
                                    <option value="Paid" <?= $payment_status === 'Paid' ? 'selected' : '' ?>>Paid</option>
                                    <option value="Partial" <?= $payment_status === 'Partial' ? 'selected' : '' ?>>Partial</option>
                                    <option value="Unpaid" <?= $payment_status === 'Unpaid' ? 'selected' : '' ?>>Unpaid</option>
                                </select>
                            </div>
                            <div class="min-w-[150px]">
                                <label class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1 block">From Date</label>
                                <input type="date" name="date_from" value="<?= $date_from ?>" class="form-input text-sm">
                            </div>
                            <div class="min-w-[150px]">
                                <label class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1 block">To Date</label>
                                <input type="date" name="date_to" value="<?= $date_to ?>" class="form-input text-sm">
                            </div>
                        </div>
                        <div class="flex gap-2 items-end">
                            <button class="btn btn-primary text-sm">Search</button>
                            <a href="history.php" class="btn btn-outline text-sm">Reset</a>
                        </div>
                    </form>

                    <!-- Purchases Table -->
                    <div class="card overflow-hidden">
                        <div class="table-wrap">
                            <table class="data-table w-full">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Invoice No</th>
                                        <th>Date</th>
                                        <th>Supplier</th>
                                        <th class="num">Amount</th>
                                        <th class="center">Status</th>
                                        <th class="center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (mysqli_num_rows($result) > 0): $count = 1;
                                        while ($row = mysqli_fetch_assoc($result)): ?>
                                            <tr>
                                                <td><?= $count++ ?></td>
                                                <td class="font-semibold"><?= htmlspecialchars($row['invoice_no'] ?? '#' . $row['id']) ?></td>
                                                <td><?= date('d M Y, h:i A', strtotime($row['purchase_date'])) ?></td>
                                                <td><?= htmlspecialchars($row['supplier_name'] ?? '-') ?></td>
                                                <td class="num"><?= number_format($row['total_amount'], 2) ?> Ks</td>
                                                <td class="center">
                                                    <?php
                                                    $histAmtCol = getPaymentAmountCol($conn, 'purchase_payments');
                                                    $histAdv = columnExists($conn, 'purchase_payments', 'advance_applied') ? " + COALESCE(advance_applied, 0)" : "";
                                                    $hist_paid = (float)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM($histAmtCol$histAdv), 0) AS tp FROM purchase_payments WHERE purchase_id='{$row['id']}'"))['tp'];
                                                    $hist_ta = (float)$row['total_amount'];
                                                    if ($hist_ta > 0 && $hist_paid >= $hist_ta) $status = 'Paid';
                                                    elseif ($hist_paid > 0) $status = 'Partial';
                                                    else $status = 'Unpaid';
                                                    $badge = $status === 'Paid' ? 'badge-success' : 'badge-danger';
                                                    ?>
                                                    <span class="badge <?= $badge ?>">
                                                        <span class="badge-dot"></span>
                                                        <?= $status ?>
                                                    </span>
                                                </td>
                                                <td class="center">
                                                    <div class="actions">
                                                        <a href="?view_id=<?= $row['id'] ?>" class="btn btn-sm bg-blue-50 text-blue-600 hover:bg-blue-100 rounded-lg">View</a>
                                                        <?php if (isAdmin()): ?>
                                                            <button onclick="openDeleteModal(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['invoice_no'] ?? '#' . $row['id'])) ?>', 'history.php')" class="btn btn-sm bg-red-50 text-red-600 hover:bg-red-100 rounded-lg">Delete</button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile;
                                    else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-16">
                                                <div class="empty-state">
                                                    <svg class="w-12 h-12 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                                                    </svg>
                                                    <h3>No purchases found</h3>
                                                    <p>No purchases match your filters. Try adjusting the search criteria.</p>
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

    <!-- View Modal -->
    <?php if (isset($_GET['view_id'])):
        $view_id = (int)$_GET['view_id'];
        $view_purchase = mysqli_fetch_assoc(mysqli_query(
            $conn,
            "SELECT pu.*, s.supplier_name, s.phone, s.address
             FROM purchases pu
             LEFT JOIN suppliers s ON pu.supplier_id = s.id
             WHERE pu.id='$view_id'"
        ));
        if ($view_purchase):
            $view_details = mysqli_query(
                $conn,
                "SELECT d.*, p.product_name FROM purchase_details d
                 INNER JOIN products p ON d.product_id = p.id
                 WHERE d.purchase_id='$view_id'"
            );
            $histViewAmtCol = getPaymentAmountCol($conn, 'purchase_payments');
            $histViewAdv = columnExists($conn, 'purchase_payments', 'advance_applied') ? " + COALESCE(advance_applied, 0)" : "";
            $histViewPaid = (float)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM($histViewAmtCol$histViewAdv), 0) AS tp FROM purchase_payments WHERE purchase_id='$view_id'"))['tp'];
            $histViewTa = (float)$view_purchase['total_amount'];
            if ($histViewTa > 0 && $histViewPaid >= $histViewTa) $histViewStatus = 'Paid';
            elseif ($histViewPaid > 0) $histViewStatus = 'Partial';
            else $histViewStatus = 'Unpaid';
    ?>
            <div id="viewModal" class="modal-overlay">
                <div class="bg-white rounded-2xl p-6 lg:p-8 w-full max-w-3xl relative mx-4 shadow-2xl max-h-[90vh] overflow-y-auto">
                    <button onclick="window.location.href='history.php'" class="absolute top-4 right-4 text-gray-400 hover:text-gray-700 dark:text-gray-300 text-2xl leading-none">&times;</button>

                    <div class="flex justify-between items-start mb-6">
                        <div>
                            <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Purchase Invoice</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">#<?= htmlspecialchars($view_purchase['invoice_no'] ?? 'ID: ' . $view_purchase['id']) ?></p>
                        </div>
                        <span class="badge <?= $histViewStatus === 'Paid' ? 'badge-success' : 'badge-danger' ?>">
                            <span class="badge-dot"></span><?= $histViewStatus ?>
                        </span>
                    </div>

                    <hr class="mb-6">

                    <div class="grid grid-cols-2 gap-6 mb-6">
                        <div>
                            <h4 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Supplier</h4>
                            <p class="font-medium"><?= htmlspecialchars($view_purchase['supplier_name']) ?></p>
                            <?php if ($view_purchase['phone']): ?><p class="text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars($view_purchase['phone']) ?></p><?php endif; ?>
                            <?php if ($view_purchase['address']): ?><p class="text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars($view_purchase['address']) ?></p><?php endif; ?>
                        </div>
                        <div>
                            <h4 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Details</h4>
                            <p class="text-sm">Date: <span class="font-medium"><?= $view_purchase['purchase_date'] ?></span></p>
                            <p class="text-sm">Created: <span class="font-medium"><?= $view_purchase['created_at'] ?? '-' ?></span></p>
                        </div>
                    </div>

                    <h4 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">Items</h4>
                    <table class="data-table w-full">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th class="num">Qty</th>
                                <th class="num">Price</th>
                                <th class="num">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $vi = 1;
                            while ($row = mysqli_fetch_assoc($view_details)): ?>
                                <tr>
                                    <td><?= $vi++ ?></td>
                                    <td class="font-medium"><?= htmlspecialchars($row['product_name']) ?></td>
                                    <td class="num"><?= $row['quantity'] ?></td>
                                    <td class="num"><?= number_format($row['purchase_price'], 2) ?></td>
                                    <td class="num"><?= number_format($row['subtotal'], 2) ?> Ks</td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>

                    <div class="text-right border-t pt-4">
                        <p class="text-lg text-gray-500 dark:text-gray-400">Total Amount</p>
                        <p class="text-3xl font-extrabold text-indigo-600"><?= number_format($view_purchase['total_amount'], 2) ?> Ks</p>
                    </div>
                </div>
            </div>
    <?php endif;
    endif; ?>

    <?php include "../includes/toast.php"; ?>
    <?php include "../includes/modal.php"; ?>
    <?php include "../includes/footer.php"; ?>

    <script>
        function exportExcel() {
            const rows = [];
            rows.push(['Purchase History Report']);
            rows.push([]);
            rows.push(['Summary']);
            rows.push(['Total Purchases', <?= $stats['total_purchases'] ?>]);
            rows.push(['Total Spent', <?= $stats['total_spent'] ?>]);
            rows.push(['Average Purchase', <?= $stats['total_purchases'] > 0 ? round($stats['total_spent'] / $stats['total_purchases']) : 0 ?>]);
            rows.push([]);
            rows.push(['#', 'Invoice No', 'Date', 'Supplier', 'Amount', 'Payment Status']);
            <?php
            mysqli_data_seek($result, 0);
            $row_num = 1;
            $expAmtCol = getPaymentAmountCol($conn, 'purchase_payments');
            $expAdv = columnExists($conn, 'purchase_payments', 'advance_applied') ? " + COALESCE(advance_applied, 0)" : "";
            while ($row = mysqli_fetch_assoc($result)):
                $exp_paid = (float)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM($expAmtCol$expAdv), 0) AS tp FROM purchase_payments WHERE purchase_id='{$row['id']}'"))['tp'];
                $exp_ta = (float)$row['total_amount'];
                if ($exp_ta > 0 && $exp_paid >= $exp_ta) $exp_status = 'Paid';
                elseif ($exp_paid > 0) $exp_status = 'Partial';
                else $exp_status = 'Unpaid';
            ?>
                rows.push([<?= $row_num++ ?>, '<?= addslashes($row['invoice_no'] ?? '#' . $row['id']) ?>', '<?= date('d M Y', strtotime($row['purchase_date'])) ?>', '<?= addslashes($row['supplier_name'] ?? '-') ?>', <?= $row['total_amount'] ?>, '<?= $exp_status ?>']);
            <?php endwhile; ?>

            const csv = rows.map(r => r.map(c => '"' + String(c).replace(/"/g, '""') + '"').join(',')).join('\n');
            const blob = new Blob(['\uFEFF' + csv], {
                type: 'text/csv;charset=utf-8;'
            });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'purchase_history_<?= date('Y-m-d') ?>.csv';
            link.click();
        }
    </script>
</body>

</html>