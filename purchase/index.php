<?php
include "../includes/auth_check.php";
protectPurchases('view');
include "../config/database.php";
include "../config/helpers.php";

$is_admin = isAdmin();
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Ensure purchases table has payment tracking columns
ensurePurchasePaymentColumns($conn);
$show_edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : null;
$show_view_id = isset($_GET['view_id']) ? (int)$_GET['view_id'] : null;

// ============ DELETE ============
if (isset($_GET['confirm_delete'])) {
    $id = (int)$_GET['confirm_delete'];

    // Get supplier_id before deletion
    $del_sup = mysqli_fetch_assoc(mysqli_query($conn, "SELECT supplier_id FROM purchases WHERE id='$id'"));
    $del_supplier_id = $del_sup ? (int)$del_sup['supplier_id'] : 0;

    $details = mysqli_query($conn, "SELECT * FROM purchase_details WHERE purchase_id='$id'");
    while ($row = mysqli_fetch_assoc($details)) {
        $product_id = $row['product_id'];
        $qty = $row['quantity'];
        mysqli_query($conn, "UPDATE products SET current_stock = current_stock - $qty WHERE id='$product_id'");
    }

    mysqli_query($conn, "DELETE FROM purchase_payments WHERE purchase_id='$id'");
    mysqli_query($conn, "DELETE FROM purchase_details WHERE purchase_id='$id'");
    mysqli_query($conn, "DELETE FROM purchases WHERE id='$id'");

    // Recalculate supplier outstanding balance after deletion
    if ($del_supplier_id > 0) {
        recalcSupplierBalance($conn, $del_supplier_id);
        rebuildSupplierLedger($conn, $del_supplier_id);
    }

    header("Location: index.php?success=" . urlencode("Purchase deleted and stock rolled back."));
    exit;
}

// ============ UPDATE (EDIT) ============
if (isset($_POST['update'])) {
    $id = (int)$_POST['id'];
    $supplier_id = (int)$_POST['supplier_id'];
    $detail_ids = $_POST['detail_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $prices = $_POST['purchase_price'] ?? [];
    $product_ids = $_POST['product_id'] ?? [];

    $new_total = 0;
    foreach ($detail_ids as $i => $detail_id) {
        $detail_id = (int)$detail_id;
        $qty = (int)($quantities[$i] ?? 0);
        $price = (float)($prices[$i] ?? 0);
        $product_id = (int)($product_ids[$i] ?? 0);
        if ($detail_id <= 0 || $product_id <= 0) continue;
        $subtotal = $qty * $price;

        $old = mysqli_fetch_assoc(mysqli_query($conn, "SELECT quantity FROM purchase_details WHERE id='$detail_id'"));
        $old_qty = $old ? (int)$old['quantity'] : 0;
        $diff = $qty - $old_qty;

        $prod_check = mysqli_fetch_assoc(mysqli_query($conn, "SELECT selling_price FROM products WHERE id='$product_id'"));
        $prod_sp = $prod_check ? (float)$prod_check['selling_price'] : 0;

        if ($prod_sp <= $price) {
            $recommended = calculateSellingPrice($conn, $price);
            mysqli_query($conn, "UPDATE products SET current_stock = current_stock + $diff, purchase_price='$price', selling_price=$recommended, price_update_required=0 WHERE id='$product_id'");
        } else {
            mysqli_query($conn, "UPDATE products SET current_stock = current_stock + $diff, purchase_price='$price' WHERE id='$product_id'");
        }
        mysqli_query($conn, "UPDATE purchase_details SET quantity='$qty', purchase_price='$price', subtotal='$subtotal' WHERE id='$detail_id'");
        $new_total += $subtotal;
    }

    $old_sup = mysqli_fetch_assoc(mysqli_query($conn, "SELECT supplier_id FROM purchases WHERE id='$id'"));
    $old_supplier_id = $old_sup ? (int)$old_sup['supplier_id'] : 0;

    mysqli_query($conn, "UPDATE purchases SET supplier_id='$supplier_id', total_amount='$new_total' WHERE id='$id'");

    // Recalculate purchase payment tracking columns (total_paid, remaining_balance, payment_status)
    updatePurchasePaymentStatus($conn, $id);

    // Recalculate supplier outstanding balance (old + new supplier in case supplier changed)
    if ($old_supplier_id > 0 && $old_supplier_id !== $supplier_id) {
        recalcSupplierBalance($conn, $old_supplier_id);
        rebuildSupplierLedger($conn, $old_supplier_id);
    }
    if ($supplier_id > 0) {
        recalcSupplierBalance($conn, $supplier_id);
        rebuildSupplierLedger($conn, $supplier_id);
    }

    header("Location: index.php?success=" . urlencode("Purchase #$id updated successfully."));
    exit;
}

// ============ SEARCH / FILTER ============
$sql = "SELECT p.*, s.supplier_name FROM purchases p LEFT JOIN suppliers s ON p.supplier_id = s.id WHERE 1";
if ($search) {
    $safe = mysqli_real_escape_string($conn, $search);
    $sql .= " AND (s.supplier_name LIKE '%$safe%' OR p.invoice_no LIKE '%$safe%')";
}
if ($status_filter && columnExists($conn, 'purchases', 'status')) {
    $sql .= " AND p.status = '$status_filter'";
}
$sql .= " ORDER BY p.id DESC";
$result = mysqli_query($conn, $sql);

$all_products = mysqli_query($conn, "SELECT * FROM products WHERE status='Active'");
$all_suppliers = mysqli_query($conn, "SELECT * FROM suppliers WHERE status='Active'");

// ============ EDIT MODAL DATA ============
$edit_purchase = null;
$edit_details = null;
if ($show_edit_id) {
    $edit_purchase = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM purchases WHERE id='$show_edit_id'"));
    if ($edit_purchase) {
        $edit_details = mysqli_query(
            $conn,
            "SELECT d.*, p.product_name FROM purchase_details d
             INNER JOIN products p ON d.product_id = p.id
             WHERE d.purchase_id='$show_edit_id'"
        );
    }
}

// ============ VIEW MODAL DATA ============
$view_purchase = null;
$view_details = null;
$view_payments = null;
if ($show_view_id) {
    $view_purchase = mysqli_fetch_assoc(mysqli_query(
        $conn,
        "SELECT pu.*, s.supplier_name, s.phone, s.address
         FROM purchases pu
         LEFT JOIN suppliers s ON pu.supplier_id = s.id
         WHERE pu.id='$show_view_id'"
    ));
    if ($view_purchase) {
        $view_details = mysqli_query(
            $conn,
            "SELECT d.*, p.product_name FROM purchase_details d
             INNER JOIN products p ON d.product_id = p.id
             WHERE d.purchase_id='$show_view_id'"
        );
        $view_payments = mysqli_query(
            $conn,
            "SELECT * FROM purchase_payments WHERE purchase_id='$show_view_id' ORDER BY payment_date ASC, id ASC"
        );
    }
}

$success_msg = $_GET['success'] ?? '';
$page_title = "Purchase Management";
$pur_settings = getShopSettings($conn);
$pur_shop_name = htmlspecialchars($pur_settings['shop_name']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Management - <?= $pur_shop_name ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include "../includes/theme-init.php"; ?>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body class="bg-gray-50 dark:bg-slate-900">
    <div class="flex min-h-screen">
        <?php include "../includes/sidebar.php"; ?>
        <div class="flex-1 flex flex-col">
            <?php include "../includes/header.php"; ?>
            <main class="p-4 lg:p-6">
                <div class="max-w-7xl mx-auto">
                    <?php if ($success_msg): ?>
                        <div class="mb-6 bg-emerald-50 border border-emerald-200 text-emerald-700 px-5 py-4 rounded-xl flex items-start gap-3 shadow-sm">
                            <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span class="text-sm font-medium"><?= htmlspecialchars($success_msg) ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
                        <form method="GET" class="flex flex-wrap items-center gap-3 flex-1">
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                                placeholder="Search supplier or invoice..." class="form-input flex-1 min-w-[200px]">
                            <select name="status" class="form-input w-auto">
                                <option value="">All Status</option>
                                <option value="Paid" <?= $status_filter === 'Paid' ? 'selected' : '' ?>>Paid</option>
                                <option value="Partial" <?= $status_filter === 'Partial' ? 'selected' : '' ?>>Partial</option>
                                <option value="Unpaid" <?= $status_filter === 'Unpaid' ? 'selected' : '' ?>>Unpaid</option>
                            </select>
                            <button class="btn btn-primary">Search</button>
                            <a href="index.php" class="btn btn-outline">Reset</a>
                        </form>
                        <a href="add.php" class="btn btn-primary">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                            New Purchase
                        </a>
                    </div>

                    <!-- Table -->
                    <div class="card overflow-hidden">
                        <div class="table-wrap">
                            <table class="data-table w-full">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Invoice</th>
                                        <th>Date</th>
                                        <th>Supplier</th>
                                        <th class="num">Amount</th>
                                        <th class="center">Payment</th>
                                        <th class="center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (mysqli_num_rows($result) > 0): $count = 1;
                                        while ($row = mysqli_fetch_assoc($result)):
                                            $amtCol = getPaymentAmountCol($conn, 'purchase_payments');
                                            $adv_expr = columnExists($conn, 'purchase_payments', 'advance_applied') ? " + COALESCE(advance_applied, 0)" : "";
                                            $total_paid_q = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM($amtCol$adv_expr), 0) AS tp FROM purchase_payments WHERE purchase_id='{$row['id']}'"));
                                            $total_paid = (float)$total_paid_q['tp'];
                                            $remaining = max(0, (float)$row['total_amount'] - $total_paid);
                                        ?>
                                            <tr>
                                                <td><?= $count++ ?></td>
                                                <td class="font-semibold"><?= htmlspecialchars($row['invoice_no'] ?? '#' . $row['id']) ?></td>
                                                <td><?= $row['purchase_date'] ?></td>
                                                <td><?= htmlspecialchars($row['supplier_name']) ?></td>
                                                <td class="num">
                                                    <div class="text-sm font-bold"><?= number_format($row['total_amount'], 2) ?></div>
                                                    <?php if ($total_paid > 0 && $remaining > 0): ?>
                                                        <div class="text-xs text-amber-600">Paid: <?= number_format($total_paid, 2) ?> | Bal: <?= number_format($remaining, 2) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="center">
                                                    <?php
                                                    $ta = (float)$row['total_amount'];
                                                    if ($ta > 0 && $total_paid >= $ta) $row_status = 'Paid';
                                                    elseif ($total_paid > 0) $row_status = 'Partial';
                                                    else $row_status = 'Unpaid';
                                                    $statusClass = match($row_status) {
                                                        'Paid' => 'badge-success',
                                                        'Partial' => 'badge-warning',
                                                        default => 'badge-danger'
                                                    };
                                                    ?>
                                                    <span class="badge <?= $statusClass ?>">
                                                        <span class="badge-dot"></span>
                                                        <?= $row_status ?>
                                                    </span>
                                                </td>
                                                <td class="center">
                                                    <div class="actions">
                                                        <a href="?view_id=<?= $row['id'] ?>" class="btn btn-sm bg-blue-50 text-blue-600 hover:bg-blue-100 rounded-lg">View</a>
                                                        <?php if (checkPermission('purchases', 'edit')): ?>
                                                            <a href="?edit_id=<?= $row['id'] ?>" class="btn btn-sm bg-green-50 text-green-600 hover:bg-green-100 rounded-lg">Edit</a>
                                                        <?php endif; ?>
                                                        <?php if (checkPermission('purchases', 'delete')): ?>
                                                            <button onclick="openDeleteModal(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['invoice_no'])) ?>', 'index.php')" class="btn btn-sm bg-red-50 text-red-600 hover:bg-red-100 rounded-lg">Delete</button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile;
                                    else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-12">
                                                <div class="empty-state">
                                                    <svg class="w-12 h-12 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                                                    </svg>
                                                    <h3>No purchases found</h3>
                                                    <p>Create your first purchase to get started.</p>
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

    <!-- ============ VIEW MODAL ============ -->
    <?php if ($view_purchase): ?>
        <?php
        $vp_total_paid = 0;
        $vpAmtCol = getPaymentAmountCol($conn, 'purchase_payments');
        $vpHasAdv = columnExists($conn, 'purchase_payments', 'advance_applied');
        while ($vp = mysqli_fetch_assoc($view_payments)) {
            $vp_total_paid += (float)$vp[$vpAmtCol];
            if ($vpHasAdv) $vp_total_paid += (float)($vp['advance_applied'] ?? 0);
        }
        mysqli_data_seek($view_payments, 0);
        $vp_balance = max(0, (float)$view_purchase['total_amount'] - $vp_total_paid);
        ?>
        <div id="viewModal" class="modal-overlay">
            <div class="bg-white dark:bg-slate-800 rounded-2xl w-full max-w-4xl relative mx-4 shadow-2xl max-h-[90vh] overflow-y-auto">
                <!-- Invoice Header -->
                <div class="bg-gradient-to-r from-indigo-600 to-indigo-700 rounded-t-2xl p-6 text-white">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                        <div>
                            <h2 class="text-2xl font-bold">Purchase Invoice</h2>
                            <p class="text-indigo-100 mt-1">#<?= htmlspecialchars($view_purchase['invoice_no'] ?? 'ID: ' . $view_purchase['id']) ?></p>
                            <p class="text-indigo-200 text-sm mt-1"><?= date('d M Y', strtotime($view_purchase['purchase_date'])) ?></p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <?php
                            $vp_ta = (float)$view_purchase['total_amount'];
                            if ($vp_ta > 0 && $vp_total_paid >= $vp_ta) $vp_status = 'Paid';
                            elseif ($vp_total_paid > 0) $vp_status = 'Partial';
                            else $vp_status = 'Unpaid';
                            $statusBadgeClass = match($vp_status) {
                                'Paid' => 'bg-emerald-500',
                                'Partial' => 'bg-amber-500',
                                default => 'bg-red-500'
                            };
                            ?>
                            <span class="px-3 py-1 rounded-full text-sm font-semibold <?= $statusBadgeClass ?>"><?= $vp_status ?></span>
                        </div>
                    </div>
                    <!-- Action Buttons -->
                    <div class="flex flex-wrap gap-2 mt-4">
                        <button onclick="window.print()" class="px-3 py-1.5 bg-white/20 hover:bg-white/30 rounded-lg text-sm font-medium transition flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                            Print
                        </button>
                        <?php if ($vp_balance > 0): ?>
                        <a href="../supplier/ledger.php?id=<?= $view_purchase['supplier_id'] ?>" class="px-3 py-1.5 bg-emerald-500 hover:bg-emerald-600 rounded-lg text-sm font-medium transition flex items-center gap-1.5 text-white">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Make Payment
                        </a>
                        <?php endif; ?>
                        <a href="index.php" class="px-3 py-1.5 bg-white/20 hover:bg-white/30 rounded-lg text-sm font-medium transition flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                            Back
                        </a>
                    </div>
                </div>

                <div class="p-6">
                    <!-- Supplier & Purchase Info -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div class="bg-gray-50 dark:bg-slate-700/50 rounded-xl p-4">
                            <h4 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3 flex items-center gap-2">
                                <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                Supplier Information
                            </h4>
                            <p class="font-semibold text-gray-900 dark:text-gray-100"><?= htmlspecialchars($view_purchase['supplier_name']) ?></p>
                            <?php if ($view_purchase['phone']): ?>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Phone: <?= htmlspecialchars($view_purchase['phone']) ?></p>
                            <?php endif; ?>
                            <?php if ($view_purchase['address']): ?>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Address: <?= htmlspecialchars($view_purchase['address']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="bg-gray-50 dark:bg-slate-700/50 rounded-xl p-4">
                            <h4 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3 flex items-center gap-2">
                                <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                Purchase Details
                            </h4>
                            <p class="text-sm text-gray-600 dark:text-gray-300">Date: <span class="font-semibold text-gray-900 dark:text-gray-100"><?= date('d M Y', strtotime($view_purchase['purchase_date'])) ?></span></p>
                            <p class="text-sm text-gray-600 dark:text-gray-300">Created: <span class="font-semibold text-gray-900 dark:text-gray-100"><?= date('d M Y, h:i A', strtotime($view_purchase['created_at'])) ?></span></p>
                        </div>
                    </div>

                    <!-- Payment Summary Cards -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                        <div class="bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-800 rounded-xl p-4 text-center hover:shadow-md transition-shadow">
                            <div class="w-10 h-10 bg-blue-100 dark:bg-blue-800 rounded-full flex items-center justify-center mx-auto mb-2">
                                <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </div>
                            <p class="text-xs text-blue-600 dark:text-blue-400 font-medium">Grand Total</p>
                            <p class="text-xl font-bold text-blue-700 dark:text-blue-300 mt-1"><?= number_format($view_purchase['total_amount'], 2) ?> Ks</p>
                        </div>
                        <div class="bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800 rounded-xl p-4 text-center hover:shadow-md transition-shadow">
                            <div class="w-10 h-10 bg-emerald-100 dark:bg-emerald-800 rounded-full flex items-center justify-center mx-auto mb-2">
                                <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </div>
                            <p class="text-xs text-emerald-600 dark:text-emerald-400 font-medium">Total Paid</p>
                            <p class="text-xl font-bold text-emerald-700 dark:text-emerald-300 mt-1"><?= number_format($vp_total_paid, 2) ?> Ks</p>
                        </div>
                        <div class="bg-<?= $vp_balance > 0 ? 'red' : 'emerald' ?>-50 dark:bg-<?= $vp_balance > 0 ? 'red' : 'emerald' ?>-900/30 border border-<?= $vp_balance > 0 ? 'red' : 'emerald' ?>-200 dark:border-<?= $vp_balance > 0 ? 'red' : 'emerald' ?>-800 rounded-xl p-4 text-center hover:shadow-md transition-shadow">
                            <div class="w-10 h-10 bg-<?= $vp_balance > 0 ? 'red' : 'emerald' ?>-100 dark:bg-<?= $vp_balance > 0 ? 'red' : 'emerald' ?>-800 rounded-full flex items-center justify-center mx-auto mb-2">
                                <svg class="w-5 h-5 text-<?= $vp_balance > 0 ? 'red' : 'emerald' ?>-600 dark:text-<?= $vp_balance > 0 ? 'red' : 'emerald' ?>-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </div>
                            <p class="text-xs text-<?= $vp_balance > 0 ? 'red' : 'emerald' ?>-600 dark:text-<?= $vp_balance > 0 ? 'red' : 'emerald' ?>-400 font-medium">Remaining Balance</p>
                            <p class="text-xl font-bold text-<?= $vp_balance > 0 ? 'red' : 'emerald' ?>-700 dark:text-<?= $vp_balance > 0 ? 'red' : 'emerald' ?>-300 mt-1"><?= number_format($vp_balance, 2) ?> Ks</p>
                        </div>
                    </div>

                    <!-- Purchased Items Table -->
                    <div class="mb-6">
                        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3 flex items-center gap-2">
                            <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                            Purchased Items
                        </h4>
                        <div class="table-wrap rounded-xl overflow-hidden border border-gray-200 dark:border-slate-700">
                            <table class="data-table w-full">
                                <thead>
                                    <tr>
                                        <th class="bg-gray-100 dark:bg-slate-700">#</th>
                                        <th class="bg-gray-100 dark:bg-slate-700">Product</th>
                                        <th class="bg-gray-100 dark:bg-slate-700 num">Qty</th>
                                        <th class="bg-gray-100 dark:bg-slate-700 num">Price</th>
                                        <th class="bg-gray-100 dark:bg-slate-700 num">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $vi = 1; while ($row = mysqli_fetch_assoc($view_details)): ?>
                                        <tr class="hover:bg-indigo-50/50 dark:hover:bg-slate-700/50 transition-colors">
                                            <td class="text-gray-500"><?= $vi++ ?></td>
                                            <td class="font-medium text-gray-900 dark:text-gray-100"><?= htmlspecialchars($row['product_name']) ?></td>
                                            <td class="num text-gray-700 dark:text-gray-300"><?= $row['quantity'] ?></td>
                                            <td class="num text-gray-700 dark:text-gray-300"><?= number_format($row['purchase_price'], 2) ?></td>
                                            <td class="num font-semibold text-gray-900 dark:text-gray-100"><?= number_format($row['subtotal'], 2) ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="bg-gray-50 dark:bg-slate-700/50">
                                        <td colspan="4" class="text-right font-bold text-gray-700 dark:text-gray-300">Total:</td>
                                        <td class="num font-bold text-indigo-600 dark:text-indigo-400"><?= number_format($view_purchase['total_amount'], 2) ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <!-- Payment History -->
                    <div class="mb-6">
                        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3 flex items-center gap-2">
                            <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                            Payment History
                        </h4>
                        <?php if (mysqli_num_rows($view_payments) > 0): ?>
                            <div class="table-wrap rounded-xl overflow-hidden border border-gray-200 dark:border-slate-700">
                                <table class="data-table w-full">
                                    <thead>
                                        <tr>
                                            <th class="bg-gray-100 dark:bg-slate-700">#</th>
                                            <th class="bg-gray-100 dark:bg-slate-700">Date</th>
                                            <th class="bg-gray-100 dark:bg-slate-700">Method</th>
                                            <th class="bg-gray-100 dark:bg-slate-700 num">Amount</th>
                                            <?php if (columnExists($conn, 'purchase_payments', 'advance_applied')): ?>
                                                <th class="bg-gray-100 dark:bg-slate-700 num">Advance</th>
                                            <?php endif; ?>
                                            <th class="bg-gray-100 dark:bg-slate-700">Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $pi = 1; while ($pmt = mysqli_fetch_assoc($view_payments)): ?>
                                            <tr class="hover:bg-indigo-50/50 dark:hover:bg-slate-700/50 transition-colors">
                                                <td class="text-gray-500"><?= $pi++ ?></td>
                                                <td class="text-gray-700 dark:text-gray-300"><?= date('d M Y', strtotime($pmt['payment_date'])) ?></td>
                                                <td>
                                                    <span class="badge <?= $pmt['payment_method'] === 'Cash' ? 'badge-success' : 'badge-info' ?>">
                                                        <?= $pmt['payment_method'] ?>
                                                    </span>
                                                </td>
                                                <td class="num font-semibold text-emerald-600 dark:text-emerald-400"><?= number_format($pmt[$vpAmtCol], 2) ?></td>
                                                <?php if (columnExists($conn, 'purchase_payments', 'advance_applied')): ?>
                                                    <td class="num">
                                                        <?php
                                                        $adv_applied = (float)($pmt['advance_applied'] ?? 0);
                                                        $adv_created = (float)($pmt['advance_created'] ?? 0);
                                                        if ($adv_applied > 0): ?>
                                                            <span class="text-blue-600 dark:text-blue-400 font-semibold">-<?= number_format($adv_applied, 2) ?></span>
                                                        <?php elseif ($adv_created > 0): ?>
                                                            <span class="text-emerald-600 dark:text-emerald-400 font-semibold">+<?= number_format($adv_created, 2) ?></span>
                                                        <?php else: ?>
                                                            <span class="text-gray-400">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endif; ?>
                                                <td class="text-gray-500 dark:text-gray-400 text-sm"><?= htmlspecialchars($pmt['notes'] ?? '-') ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="bg-gray-50 dark:bg-slate-700/50 rounded-xl p-8 text-center border border-dashed border-gray-300 dark:border-slate-600">
                                <svg class="w-12 h-12 mx-auto text-gray-300 dark:text-gray-500 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                                <p class="text-gray-500 dark:text-gray-400 font-medium">No payments recorded yet</p>
                                <p class="text-sm text-gray-400 dark:text-gray-500 mt-1">Payments will appear here once recorded.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Footer Summary -->
                    <div class="bg-gray-50 dark:bg-slate-700/50 rounded-xl p-4 border-t border-gray-200 dark:border-slate-600">
                        <div class="flex flex-wrap justify-between items-center gap-4">
                            <div class="flex gap-6">
                                <div>
                                    <span class="text-sm text-gray-500 dark:text-gray-400">Total Amount:</span>
                                    <span class="ml-2 font-bold text-gray-900 dark:text-gray-100"><?= number_format($view_purchase['total_amount'], 2) ?> Ks</span>
                                </div>
                                <div>
                                    <span class="text-sm text-gray-500 dark:text-gray-400">Paid:</span>
                                    <span class="ml-2 font-bold text-emerald-600 dark:text-emerald-400"><?= number_format($vp_total_paid, 2) ?> Ks</span>
                                </div>
                                <div>
                                    <span class="text-sm text-gray-500 dark:text-gray-400">Remaining:</span>
                                    <span class="ml-2 font-bold <?= $vp_balance > 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' ?>"><?= number_format($vp_balance, 2) ?> Ks</span>
                                </div>
                            </div>
                            <a href="index.php" class="btn btn-secondary">Close</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ============ EDIT MODAL ============ -->
    <?php if ($edit_purchase && $edit_details): ?>
        <div id="editModal" class="modal-overlay">
            <div class="bg-white rounded-2xl p-6 lg:p-8 w-full max-w-4xl relative mx-4 shadow-2xl max-h-[90vh] overflow-y-auto">
                <a href="index.php" class="absolute top-4 right-4 text-gray-400 hover:text-gray-700 dark:text-gray-300 text-2xl leading-none">&times;</a>
                <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-6">Edit Purchase #<?= htmlspecialchars($edit_purchase['invoice_no'] ?? $edit_purchase['id']) ?></h2>

                <form method="POST">
                    <input type="hidden" name="id" value="<?= $edit_purchase['id'] ?>">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                        <div>
                            <label class="form-label">Supplier</label>
                            <select name="supplier_id" class="form-input">
                                <?php mysqli_data_seek($all_suppliers, 0);
                                while ($s = mysqli_fetch_assoc($all_suppliers)): ?>
                                    <option value="<?= $s['id'] ?>" <?= $s['id'] == $edit_purchase['supplier_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['supplier_name']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>

                    <h4 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">Products</h4>
                    <div class="table-wrap mb-6">
                        <table class="data-table w-full">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Product</th>
                                    <th class="num">Quantity</th>
                                    <th class="num">Price</th>
                                    <th class="num">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $edit_total = 0; $ei = 1;
                                while ($row = mysqli_fetch_assoc($edit_details)): $edit_total += $row['subtotal']; ?>
                                    <tr>
                                        <td><?= $ei++ ?></td>
                                        <td>
                                            <select name="product_id[]" class="form-input text-sm">
                                                <?php mysqli_data_seek($all_products, 0);
                                                while ($p = mysqli_fetch_assoc($all_products)): ?>
                                                    <option value="<?= $p['id'] ?>" <?= $p['id'] == $row['product_id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($p['product_name']) ?>
                                                    </option>
                                                <?php endwhile; ?>
                                            </select>
                                            <input type="hidden" name="detail_id[]" value="<?= $row['id'] ?>">
                                        </td>
                                        <td><input type="number" name="quantity[]" value="<?= $row['quantity'] ?>" min="0" class="form-input text-sm text-center"></td>
                                        <td><input type="number" name="purchase_price[]" value="<?= $row['purchase_price'] ?>" step="0.01" min="0" class="form-input text-sm text-center"></td>
                                        <td class="num"><?= number_format($row['subtotal'], 2) ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex items-center justify-between mb-6">
                        <span class="text-lg font-bold text-gray-800 dark:text-gray-200">Total: <span class="text-indigo-600"><?= number_format($edit_total, 2) ?></span></span>
                    </div>

                    <div class="flex justify-end gap-3">
                        <a href="index.php" class="btn btn-secondary">Cancel</a>
                        <button name="update" class="btn btn-primary">Update Purchase</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php include "../includes/toast.php"; ?>
    <?php include "../includes/modal.php"; ?>
    <?php include "../includes/footer.php"; ?>
</body>

</html>
