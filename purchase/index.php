<?php
include "../includes/auth_check.php";
protectPurchases('view');
include "../config/database.php";
include "../config/helpers.php";

$is_admin = isAdmin();
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Ensure purchases table has payment tracking columns
ensurePurchasePaymentColumns($conn);
$show_edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : null;
$show_view_id = isset($_GET['view_id']) ? (int)$_GET['view_id'] : null;

// ============ DELETE ============
if (isset($_GET['confirm_delete'])) {
    $id = (int)$_GET['confirm_delete'];

    // 1. Validation check: Prevent negative stock
    $validation_query = mysqli_query($conn, "SELECT pd.quantity, p.product_name, p.current_stock FROM purchase_details pd JOIN products p ON pd.product_id = p.id WHERE pd.purchase_id='$id'");
    $can_delete = true;
    $error_msg = "";
    while ($v_row = mysqli_fetch_assoc($validation_query)) {
        if ($v_row['current_stock'] < $v_row['quantity']) {
            $can_delete = false;
            $error_msg = "Cannot delete purchase. The product '" . $v_row['product_name'] . "' has already been sold (Current Stock: " . $v_row['current_stock'] . ", Purchased: " . $v_row['quantity'] . ").";
            break;
        }
    }

    if (!$can_delete) {
        header("Location: index.php?error=" . urlencode($error_msg));
        exit;
    }

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

    // 1. Validation check for negative stock
    $can_update = true;
    $error_msg = "";
    foreach ($detail_ids as $i => $detail_id) {
        $detail_id = (int)$detail_id;
        $qty = (int)($quantities[$i] ?? 0);
        $product_id = (int)($product_ids[$i] ?? 0);
        if ($detail_id <= 0 || $product_id <= 0) continue;

        $old = mysqli_fetch_assoc(mysqli_query($conn, "SELECT quantity FROM purchase_details WHERE id='$detail_id'"));
        $old_qty = $old ? (int)$old['quantity'] : 0;
        $diff = $qty - $old_qty;

        if ($diff < 0) {
            $prod_check = mysqli_fetch_assoc(mysqli_query($conn, "SELECT product_name, current_stock FROM products WHERE id='$product_id'"));
            $current_stock = $prod_check ? (int)$prod_check['current_stock'] : 0;
            
            if ($current_stock < abs($diff)) {
                $can_update = false;
                $error_msg = "Cannot reduce quantity for '" . $prod_check['product_name'] . "'. Stock would become negative. (Current Stock: " . $current_stock . ", Trying to reduce by: " . abs($diff) . ")";
                break;
            }
        }
    }

    if (!$can_update) {
        header("Location: index.php?error=" . urlencode($error_msg));
        exit;
    }

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

        mysqli_query($conn, "UPDATE products SET current_stock = current_stock + $diff, purchase_price='$price' WHERE id='$product_id'");
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
if ($status_filter && columnExists($conn, 'purchases', 'payment_status')) {
    $sql .= " AND p.payment_status = '$status_filter'";
}
// Safe date-range filter on the real purchase_date column.
// purchase_date >= start  AND  purchase_date < (end + 1 day)
// -> includes every purchase made on the end date (00:00:00 through 23:59:59).
if ($date_from !== '') {
    $safe_from = mysqli_real_escape_string($conn, $date_from);
    $sql .= " AND p.purchase_date >= '$safe_from'";
}
if ($date_to !== '') {
    $safe_to = mysqli_real_escape_string($conn, $date_to);
    $sql .= " AND p.purchase_date < '$safe_to' + INTERVAL 1 DAY";
}

// Display limit: latest 10 purchases by default, "View More" shows everything
// that matches the active filters. Database records are never limited/deleted.
$DISPLAY_LIMIT = 10;
$show_all = (($_GET['view'] ?? '') === 'all');
$sql .= " ORDER BY p.purchase_date DESC, p.id DESC";
if (!$show_all) {
    // Fetch one extra row purely to detect whether more than 10 records exist
    $sql .= " LIMIT " . ($DISPLAY_LIMIT + 1);
}
$result = mysqli_query($conn, $sql);

$purchases = [];
while ($row = mysqli_fetch_assoc($result)) $purchases[] = $row;

$has_more = !$show_all && count($purchases) > $DISPLAY_LIMIT;
$display_purchases = $show_all ? $purchases : array_slice($purchases, 0, $DISPLAY_LIMIT);

// View More / Show Less links keep every active filter (search, status, dates)
$view_more_params = $_GET;
$view_less_params = $_GET;
unset($view_less_params['view']);
if (!$show_all) {
    $view_more_params['view'] = 'all';
}
$view_more_url = 'index.php' . (!empty($view_more_params) ? '?' . http_build_query($view_more_params) : '');
$view_less_url = 'index.php' . (!empty($view_less_params) ? '?' . http_build_query($view_less_params) : '');

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
    <style>
        /* ============ Purchase Invoice — Print ============ */
        @media print {
            @page {
                margin: 0.5in;
            }

            .no-print {
                display: none !important;
            }

            /* When the invoice modal is open, print only the invoice */
            body.print-invoice-mode>.flex.min-h-screen {
                display: none !important;
            }

            body.print-invoice-mode #viewModal {
                position: static !important;
                inset: auto !important;
                background: #fff !important;
                padding: 0 !important;
                overflow: visible !important;
                display: block !important;
            }

            body.print-invoice-mode #viewModal>div {
                max-width: 100% !important;
                max-height: none !important;
                margin: 0 !important;
                box-shadow: none !important;
                overflow: visible !important;
            }

            /* Themed header tint that prints reliably and stays readable */
            #viewModal .data-table th {
                background-color: #e0e7ff !important;
                color: #1e293b !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            /* Clean borders, subtle alternating rows */
            #viewModal .data-table td,
            #viewModal .data-table th {
                border-bottom: 1px solid #cbd5e1 !important;
            }

            #viewModal .data-table td {
                color: #111827 !important;
            }

            #viewModal .data-table tbody tr:nth-child(even) td {
                background-color: #f8fafc !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            /* Hover effects must never appear on paper */
            #viewModal .data-table tbody tr:hover td {
                background-color: transparent !important;
            }

            /* Strong total row */
            #viewModal .data-table tfoot td {
                background-color: #eef2ff !important;
                border-top: 2px solid #6366f1 !important;
                border-bottom: none !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>

<body class="bg-[#eaf4fc] dark:bg-slate-900 <?= (isset($_GET['view_id']) && $view_purchase) ? 'print-invoice-mode' : '' ?>">
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
                        <form method="GET" class="flex flex-wrap items-end gap-3 flex-1">
                            <div class="flex-1 min-w-[200px]">
                                <label class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1 block">Search</label>
                                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                                    placeholder="Supplier or invoice..." class="form-input text-sm w-full">
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1 block">Payment</label>
                                <select name="status" class="form-input text-sm">
                                    <option value="">All Status</option>
                                    <option value="Paid" <?= $status_filter === 'Paid' ? 'selected' : '' ?>>Paid</option>
                                    <option value="Partial" <?= $status_filter === 'Partial' ? 'selected' : '' ?>>Partial</option>
                                    <option value="Unpaid" <?= $status_filter === 'Unpaid' ? 'selected' : '' ?>>Unpaid</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1 block">From Date</label>
                                <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="form-input text-sm">
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1 block">To Date</label>
                                <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="form-input text-sm">
                            </div>
                            <div class="flex gap-2">
                                <button class="btn btn-primary text-sm">Filter</button>
                                <a href="index.php" class="btn btn-outline text-sm">Reset</a>
                            </div>
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
                        <div class="table-wrap overflow-x-auto">
                            <table class="data-table w-full table-fixed min-w-[1000px]">
                                <thead>
                                    <tr>
                                        <th style="width: 5%">#</th>
                                        <th style="width: 15%">Invoice</th>
                                        <th style="width: 12%">Date</th>
                                        <th style="width: 12%">Supplier</th>
                                        <th class="num" style="width: 18%">Amount</th>
                                        <th class="center" style="width: 10%">Payment</th>
                                        <th class="center" style="width: 20%">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($display_purchases) > 0): $count = 1;
                                        foreach ($display_purchases as $row):
                                            $amtCol = getPaymentAmountCol($conn, 'purchase_payments');
                                            $adv_expr = columnExists($conn, 'purchase_payments', 'advance_applied') ? " + COALESCE(advance_applied, 0)" : "";
                                            $total_paid_q = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM($amtCol$adv_expr), 0) AS tp FROM purchase_payments WHERE purchase_id='{$row['id']}'"));
                                            $total_paid = (float)$total_paid_q['tp'];
                                            $remaining = max(0, (float)$row['total_amount'] - $total_paid);
                                    ?>
                                            <tr>
                                                <td><?= $count++ ?></td>
                                                <td class="font-semibold whitespace-nowrap"><?= htmlspecialchars($row['invoice_no'] ?? '#' . $row['id']) ?></td>
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
                                                    $statusClass = match ($row_status) {
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
                                                    <div class="flex items-center justify-center gap-2 whitespace-nowrap">
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
                                        <?php endforeach;
                                    else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-12">
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

                    <!-- Showing / View More -->
                    <?php if (count($display_purchases) > 0): ?>
                        <div class="flex flex-col sm:flex-row items-center justify-center gap-3 mt-5">
                            <p class="text-sm text-gray-500 dark:text-gray-400 font-medium text-center">
                                <?php if ($show_all): ?>
                                    Showing all <?= count($purchases) ?> purchase<?= count($purchases) === 1 ? '' : 's' ?><?= ($date_from !== '' || $date_to !== '') ? ' within selected date range' : '' ?>
                                <?php else: ?>
                                    Showing latest <?= count($display_purchases) ?> purchase<?= count($display_purchases) === 1 ? '' : 's' ?><?= ($date_from !== '' || $date_to !== '') ? ' within selected date range' : '' ?>
                                <?php endif; ?>
                            </p>
                            <?php if ($has_more): ?>
                                <a href="<?= $view_more_url ?>" class="btn btn-outline gap-2 text-sm">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                    </svg>
                                    View More
                                </a>
                            <?php elseif ($show_all && $has_more === false && count($purchases) > $DISPLAY_LIMIT): ?>
                                <a href="<?= $view_less_url ?>" class="btn btn-outline gap-2 text-sm">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                                    </svg>
                                    Show Less
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
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
                            $statusBadgeClass = match ($vp_status) {
                                'Paid' => 'bg-emerald-500',
                                'Partial' => 'bg-amber-500',
                                default => 'bg-red-500'
                            };
                            ?>
                            <span class="px-3 py-1 rounded-full text-sm font-semibold <?= $statusBadgeClass ?>"><?= $vp_status ?></span>
                        </div>
                    </div>
                    <!-- Action Buttons -->
                    <div class="flex flex-wrap gap-2 mt-4 no-print">
                        <button onclick="window.print()" class="px-3 py-1.5 bg-white/20 hover:bg-white/30 rounded-lg text-sm font-medium transition flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                            </svg>
                            Print
                        </button>
                        <?php if ($vp_balance > 0): ?>
                            <a href="../supplier/ledger.php?id=<?= $view_purchase['supplier_id'] ?>" class="px-3 py-1.5 bg-emerald-500 hover:bg-emerald-600 rounded-lg text-sm font-medium transition flex items-center gap-1.5 text-white">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                Make Payment
                            </a>
                        <?php endif; ?>
                        <a href="index.php" class="px-3 py-1.5 bg-white/20 hover:bg-white/30 rounded-lg text-sm font-medium transition flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                            Back
                        </a>
                    </div>
                </div>

                <div class="p-6">
                    <!-- Supplier & Purchase Info -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div class="bg-gray-50 dark:bg-slate-700/50 rounded-xl p-4">
                            <h4 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3 flex items-center gap-2">
                                <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                </svg>
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
                                <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
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
                                <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <p class="text-xs text-blue-600 dark:text-blue-400 font-medium">Grand Total</p>
                            <p class="text-xl font-bold text-blue-700 dark:text-blue-300 mt-1"><?= number_format($view_purchase['total_amount'], 2) ?> Ks</p>
                        </div>
                        <div class="bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800 rounded-xl p-4 text-center hover:shadow-md transition-shadow">
                            <div class="w-10 h-10 bg-emerald-100 dark:bg-emerald-800 rounded-full flex items-center justify-center mx-auto mb-2">
                                <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <p class="text-xs text-emerald-600 dark:text-emerald-400 font-medium">Total Paid</p>
                            <p class="text-xl font-bold text-emerald-700 dark:text-emerald-300 mt-1"><?= number_format($vp_total_paid, 2) ?> Ks</p>
                        </div>
                        <div class="bg-<?= $vp_balance > 0 ? 'red' : 'emerald' ?>-50 dark:bg-<?= $vp_balance > 0 ? 'red' : 'emerald' ?>-900/30 border border-<?= $vp_balance > 0 ? 'red' : 'emerald' ?>-200 dark:border-<?= $vp_balance > 0 ? 'red' : 'emerald' ?>-800 rounded-xl p-4 text-center hover:shadow-md transition-shadow">
                            <div class="w-10 h-10 bg-<?= $vp_balance > 0 ? 'red' : 'emerald' ?>-100 dark:bg-<?= $vp_balance > 0 ? 'red' : 'emerald' ?>-800 rounded-full flex items-center justify-center mx-auto mb-2">
                                <svg class="w-5 h-5 text-<?= $vp_balance > 0 ? 'red' : 'emerald' ?>-600 dark:text-<?= $vp_balance > 0 ? 'red' : 'emerald' ?>-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <p class="text-xs text-<?= $vp_balance > 0 ? 'red' : 'emerald' ?>-600 dark:text-<?= $vp_balance > 0 ? 'red' : 'emerald' ?>-400 font-medium">Remaining Balance</p>
                            <p class="text-xl font-bold text-<?= $vp_balance > 0 ? 'red' : 'emerald' ?>-700 dark:text-<?= $vp_balance > 0 ? 'red' : 'emerald' ?>-300 mt-1"><?= number_format($vp_balance, 2) ?> Ks</p>
                        </div>
                    </div>

                    <!-- Purchased Items Table -->
                    <div class="mb-6">
                        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3 flex items-center gap-2">
                            <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                            </svg>
                            Purchased Items
                        </h4>
                        <div class="table-wrap rounded-xl overflow-hidden border border-gray-200 dark:border-slate-700">
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
                                        <tr class="transition-colors">
                                            <td class="text-gray-500"><?= $vi++ ?></td>
                                            <td class="font-medium text-gray-900 dark:text-gray-100"><?= htmlspecialchars($row['product_name']) ?></td>
                                            <td class="num text-gray-700 dark:text-gray-300"><?= $row['quantity'] ?></td>
                                            <td class="num text-gray-700 dark:text-gray-300"><?= number_format($row['purchase_price'], 2) ?></td>
                                            <td class="num font-semibold text-gray-900 dark:text-gray-100"><?= number_format($row['subtotal'], 2) ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="bg-indigo-50/80 dark:bg-indigo-500/10">
                                        <td colspan="4" class="text-right font-bold text-indigo-700 dark:text-indigo-300 uppercase text-xs tracking-wider border-t-2 border-indigo-300 dark:border-slate-600">Total:</td>
                                        <td class="num font-extrabold text-indigo-600 dark:text-indigo-400 text-base border-t-2 border-indigo-300 dark:border-slate-600"><?= number_format($view_purchase['total_amount'], 2) ?> Ks</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <!-- Payment History -->
                    <div class="mb-6">
                        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3 flex items-center gap-2">
                            <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                            Payment History
                        </h4>
                        <?php if (mysqli_num_rows($view_payments) > 0): ?>
                            <div class="table-wrap rounded-xl overflow-hidden border border-gray-200 dark:border-slate-700">
                                <table class="data-table w-full">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Date</th>
                                            <th>Method</th>
                                            <th class="num">Amount</th>
                                            <?php if (columnExists($conn, 'purchase_payments', 'advance_applied')): ?>
                                                <th class="num">Advance</th>
                                            <?php endif; ?>
                                            <th>Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $pi = 1;
                                        while ($pmt = mysqli_fetch_assoc($view_payments)): ?>
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
                                <svg class="w-12 h-12 mx-auto text-gray-300 dark:text-gray-500 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                                </svg>
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
                <button onclick="window.location.href='index.php'" class="no-print absolute top-4 right-4 text-gray-400 hover:text-gray-700 dark:text-gray-300 text-2xl leading-none">&times;</button>
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
                                <?php $edit_total = 0;
                                $ei = 1;
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