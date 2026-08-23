<?php
include "../includes/auth_check.php";
protectPurchases('add');
include "../config/database.php";
include "../config/helpers.php";

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// ============ ADD TO CART ============
if (isset($_POST['add_cart'])) {
    $product_id = (int)($_POST['product_id'] ?? 0);
    $qty = (int)($_POST['quantity'] ?? 0);
    $price = (float)($_POST['purchase_price'] ?? 0);

    if ($product_id > 0 && $qty > 0 && $price > 0) {
        $product = mysqli_fetch_assoc(
            mysqli_query($conn, "SELECT product_name FROM products WHERE id='$product_id'")
        );
        if ($product) {
            $_SESSION['cart'][] = [
                "product_id" => $product_id,
                "product_name" => $product['product_name'],
                "quantity" => $qty,
                "price" => $price,
                "subtotal" => $qty * $price
            ];
        }
    }
    $params = [];
    foreach (['supplier_id', 'payment_method', 'purchase_date'] as $f) {
        if (!empty($_POST[$f])) $params[$f] = $_POST[$f];
    }
    $query = $params ? '?' . http_build_query($params) : '';
    header("Location: add.php" . $query);
    exit;
}

// ============ REMOVE FROM CART ============
if (isset($_GET['remove'])) {
    $key = (int)$_GET['remove'];
    if (isset($_SESSION['cart'][$key])) {
        unset($_SESSION['cart'][$key]);
        $_SESSION['cart'] = array_values($_SESSION['cart']);
    }
    header("Location: add.php");
    exit;
}

$old_method = $_POST['payment_method'] ?? $_GET['payment_method'] ?? 'Cash';
$old_paid   = $_POST['paid_amount'] ?? $_GET['paid_amount'] ?? '';

// ============ SAVE PURCHASE ============
$error_msg = '';
if (isset($_POST['save_purchase'])) {
    $supplier_id = (int)$_POST['supplier_id'];
    $purchase_date = $_POST['purchase_date'] ?? date('Y-m-d');
    $payment_method = $_POST['payment_method'] ?? 'Cash';
    $paid_amount = (float)($_POST['paid_amount'] ?? 0);

    if ($supplier_id <= 0) {
        $error_msg = 'Please select a supplier.';
    } elseif (count($_SESSION['cart']) === 0) {
        $error_msg = 'Please add at least one product.';
    } elseif (!in_array($payment_method, ['Cash', 'KBZPay'])) {
        $error_msg = 'Please select a valid payment method.';
    } elseif ($paid_amount < 0) {
        $error_msg = 'Paid amount cannot be negative.';
    } else {
        $conn->begin_transaction();
        try {
            $date_prefix = date('Ymd');
            $inv_result = mysqli_query($conn, "SELECT invoice_no FROM purchases WHERE invoice_no LIKE 'INV-$date_prefix-%' ORDER BY id DESC LIMIT 1");
            if ($inv_result && $row = mysqli_fetch_assoc($inv_result)) {
                $last_num = (int)substr($row['invoice_no'], -4);
                $next_num = $last_num + 1;
            } else {
                $next_num = 1;
            }
            $invoice_no = 'INV-' . $date_prefix . '-' . str_pad($next_num, 4, '0', STR_PAD_LEFT);

            $total = 0;
            foreach ($_SESSION['cart'] as $item) {
                $total += $item['subtotal'];
            }

            // ── Auto-apply supplier advance credit ──
            // Recalculate first so advance_credit is always real-time
            recalcSupplierBalance($conn, $supplier_id);
            $sup_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT advance_credit FROM suppliers WHERE id = $supplier_id"));
            $available_advance = $sup_row ? (float)$sup_row['advance_credit'] : 0;

            $advance_applied = 0;
            if ($available_advance > 0.01 && $total > 0.01) {
                $advance_applied = min($available_advance, $total);
            }

            // ── Calculate balances ──
            $effective_total = max(0, $total - $advance_applied);
            $total_paid = $paid_amount + $advance_applied;
            $remaining_balance = max(0, round($effective_total - $paid_amount, 2));

            // ── Determine payment status and overpayment ──
            $advance_created = 0;
            if ($total_paid >= $effective_total - 0.01) {
                $payment_status = 'Paid';
                $advance_created = max(0, round($total_paid - $effective_total, 2));
                $remaining_balance = 0;
            } elseif ($total_paid > 0.01) {
                $payment_status = 'Partial';
            } else {
                $payment_status = 'Unpaid';
            }

            // Cash actually applied to THIS purchase is capped at what the
            // purchase owes. Any excess (advance_created) belongs to the
            // supplier as advance credit — it is already recorded in full in
            // supplier_payments, so it must NOT also be stored inside this
            // purchase's purchase_payments row, otherwise the same money is
            // counted twice and "Paid" exceeds the purchase amount.
            $cash_applied = min($paid_amount, $effective_total);

            $user_id = (int)($_SESSION['user_id'] ?? 0);
            ensurePurchasePaymentColumns($conn);

            // ── Insert purchase record ──
            // total_paid only ever counts money applied to THIS purchase
            // (capped cash + advance credit), never the overpayment excess.
            $purchase_total_paid = min($total, $total_paid);
            $ins_cols = "invoice_no, supplier_id, user_id, purchase_date, total_amount, total_paid, remaining_balance, payment_status";
            $ins_vals = "'$invoice_no', '$supplier_id', $user_id, '$purchase_date', '$total', $purchase_total_paid, $remaining_balance, '$payment_status'";
            if (columnExists($conn, 'purchases', 'status')) {
                $ins_cols .= ", status";
                $ins_vals .= ", 'completed'";
            }
            mysqli_query($conn, "INSERT INTO purchases($ins_cols) VALUES($ins_vals)");
            $purchase_id = mysqli_insert_id($conn);

            if (!$purchase_id) {
                throw new Exception('Failed to create purchase record.');
            }

            // ── Insert purchase_payments record (cash + advance) ──
            $insAmtCol = getPaymentAmountCol($conn, 'purchase_payments');
            $hasCash = columnExists($conn, 'purchase_payments', 'cash_amount');
            $hasNotes = columnExists($conn, 'purchase_payments', 'notes');
            $hasAdvance = columnExists($conn, 'purchase_payments', 'advance_applied');

            $cash_amt = ($payment_method === 'Cash') ? $cash_applied : 0;
            $kbz_amt = ($payment_method === 'KBZPay') ? $cash_applied : 0;

            if ($paid_amount > 0.01 || $advance_applied > 0.01) {
                if ($hasCash && $hasNotes) {
                    $cols = "purchase_id, payment_method, cash_amount, kbzpay_amount, $insAmtCol, remaining_balance, payment_status, payment_date, notes";
                    $vals = "'$purchase_id', '$payment_method', $cash_amt, $kbz_amt, $cash_applied, $remaining_balance, '$payment_status', '$purchase_date', ''";
                    if ($hasAdvance) {
                        $cols .= ", advance_applied, advance_created";
                        $vals .= ", $advance_applied, $advance_created";
                    }
                } elseif ($hasCash) {
                    $cols = "purchase_id, payment_method, cash_amount, kbzpay_amount, $insAmtCol, remaining_balance, payment_status, payment_date";
                    $vals = "'$purchase_id', '$payment_method', $cash_amt, $kbz_amt, $cash_applied, $remaining_balance, '$payment_status', '$purchase_date'";
                    if ($hasAdvance) {
                        $cols .= ", advance_applied, advance_created";
                        $vals .= ", $advance_applied, $advance_created";
                    }
                } else {
                    $cols = "purchase_id, payment_method, $insAmtCol, remaining_balance, payment_status, payment_date";
                    $vals = "'$purchase_id', '$payment_method', $cash_applied, $remaining_balance, '$payment_status', '$purchase_date'";
                    if ($hasAdvance) {
                        $cols .= ", advance_applied, advance_created";
                        $vals .= ", $advance_applied, $advance_created";
                    }
                }
                mysqli_query($conn, "INSERT INTO purchase_payments($cols) VALUES($vals)");
            }

            // ── Insert supplier_payments record for the cash paid ──
            // This ensures supplier_payments is the single source of truth for financial transactions.
            if ($paid_amount > 0.01 && columnExists($conn, 'supplier_payments', 'supplier_id')) {
                $hasNotesSP = columnExists($conn, 'supplier_payments', 'notes');
                if ($hasNotesSP) {
                    $sql_direct = "INSERT INTO supplier_payments 
                        (supplier_id, payment_method, cash_amount, kbzpay_amount, paid_amount, ref_no, payment_date, notes)
                        VALUES ($supplier_id, '$payment_method', $cash_amt, $kbz_amt, $paid_amount, '$invoice_no', '$purchase_date', '')";
                } else {
                    $sql_direct = "INSERT INTO supplier_payments 
                        (supplier_id, payment_method, cash_amount, kbzpay_amount, paid_amount, ref_no, payment_date)
                        VALUES ($supplier_id, '$payment_method', $cash_amt, $kbz_amt, $paid_amount, '$invoice_no', '$purchase_date')";
                }
                mysqli_query($conn, $sql_direct);
            }

            // ── Insert product details ──
            foreach ($_SESSION['cart'] as $item) {
                $pid = (int)$item['product_id'];
                $qty = (int)$item['quantity'];
                $price = (float)$item['price'];
                $subtotal = (float)$item['subtotal'];

                mysqli_query($conn, "INSERT INTO purchase_details(purchase_id, product_id, quantity, purchase_price, subtotal)
                    VALUES('$purchase_id', '$pid', '$qty', '$price', '$subtotal')");

                $check = mysqli_fetch_assoc(mysqli_query($conn, "SELECT selling_price FROM products WHERE id='$pid'"));
                $sp = $check ? (float)$check['selling_price'] : 0;

                mysqli_query($conn, "UPDATE products SET current_stock = current_stock + $qty, purchase_price = '$price' WHERE id='$pid'");
            }

            // ── Update supplier balance & ledger ──
            recalcSupplierBalance($conn, $supplier_id);
            rebuildSupplierLedger($conn, $supplier_id);

            $conn->commit();
            unset($_SESSION['cart']);
            header("Location: index.php?view_id=$purchase_id&success=" . urlencode("Purchase #$invoice_no created successfully."));
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = 'Failed to create purchase: ' . $e->getMessage();
        }
    }
}

// ============ FETCH DATA ============
$suppliers = mysqli_query($conn, "SELECT * FROM suppliers WHERE status='Active'");
$products = mysqli_query($conn, "SELECT * FROM products WHERE status='Active'");
$logged_user = $_SESSION['name'] ?? 'User';

$supplier_balances = [];
$sup_bal_query = mysqli_query($conn, "SELECT id, outstanding_balance, advance_credit FROM suppliers WHERE status='Active'");
while ($sb = mysqli_fetch_assoc($sup_bal_query)) {
    $supplier_balances[$sb['id']] = [
        'balance' => (float)$sb['outstanding_balance'],
        'advance' => (float)($sb['advance_credit'] ?? 0)
    ];
}

$cart_subtotal = 0;
foreach ($_SESSION['cart'] as $item) {
    $cart_subtotal += $item['subtotal'];
}

$old_supplier = $_POST['supplier_id'] ?? $_GET['supplier_id'] ?? '';
$old_date     = $_POST['purchase_date'] ?? $_GET['purchase_date'] ?? date('Y-m-d');

$page_title = "New Purchase";
$pur_settings = getShopSettings($conn);
$pur_shop_name = htmlspecialchars($pur_settings['shop_name']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Purchase - <?= $pur_shop_name ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include "../includes/theme-init.php"; ?>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .summary-sticky {
            position: sticky;
            top: 6rem;
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
                    <div class="flex justify-end mb-6">
                        <a href="index.php" class="btn btn-outline gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                            Back to Purchases
                        </a>
                    </div>

                    <?php if ($error_msg): ?>
                        <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-5 py-4 rounded-xl flex items-start gap-3 shadow-sm">
                            <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span class="text-sm font-medium"><?= $error_msg ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="POST" id="purchaseForm" onsubmit="return handleFormSubmit(event)" data-form-guard="true">
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            <!-- ===== LEFT COLUMN (2/3) ===== -->
                            <div class="lg:col-span-2 space-y-6">

                                <!-- ===== CARD: PURCHASE INFORMATION ===== -->
                                <div class="card">
                                    <div class="card-header">
                                        <h2 class="text-base font-bold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                            <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                            </svg>
                                            Purchase Information
                                        </h2>
                                        <span class="text-xs text-gray-400 bg-gray-100 px-2.5 py-1 rounded-full font-medium">New</span>
                                    </div>
                                    <div class="card-body">
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                                            <div>
                                                <label class="form-label">Invoice Number</label>
                                                <input type="text" value="<?= 'INV-' . date('Ymd') . '-....' ?>" readonly
                                                    class="form-input bg-gray-50 text-gray-500 dark:text-gray-400 cursor-not-allowed text-sm">
                                            </div>
                                            <div>
                                                <label class="form-label">Purchase Date</label>
                                                <input type="date" name="purchase_date" value="<?= $old_date ?>"
                                                    class="form-input text-sm">
                                            </div>
                                            <div>
                                                <label class="form-label">Supplier <span class="text-red-500">*</span></label>
                                                <select name="supplier_id" id="supplier_id" class="form-input text-sm" onchange="onSupplierChange()">
                                                    <option value="">-- Select Supplier --</option>
                                                    <?php mysqli_data_seek($suppliers, 0);
                                                    while ($s = mysqli_fetch_assoc($suppliers)) { ?>
                                                        <option value="<?= $s['id'] ?>" <?= $old_supplier == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['supplier_name']) ?></option>
                                                    <?php } ?>
                                                </select>
                                                <p class="form-error hidden" id="supplierError">Please select a supplier.</p>
                                            </div>
                                            <div>
                                                <label class="form-label">Staff</label>
                                                <input type="text" value="<?= htmlspecialchars($logged_user) ?>" readonly
                                                    class="form-input bg-gray-50 text-gray-500 dark:text-gray-400 cursor-not-allowed text-sm">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- ===== CARD: PRODUCT SECTION ===== -->
                                <div class="card">
                                    <div class="card-header">
                                        <h2 class="text-base font-bold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                            <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                            </svg>
                                            Product Items
                                        </h2>
                                        <span class="text-xs text-gray-400"><?= count($_SESSION['cart']) ?> item(s)</span>
                                    </div>
                                    <div class="card-body">
                                        <!-- Add Product Row -->
                                        <div class="grid grid-cols-12 gap-3 mb-5">
                                            <div class="col-span-12 sm:col-span-4">
                                                <label class="text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1 block">Product <span class="text-red-500">*</span></label>
                                                <select name="product_id" id="add_product" class="form-input text-sm" required onchange="autoFillPrice(this)">
                                                    <option value="">-- Select Product --</option>
                                                    <?php mysqli_data_seek($products, 0);
                                                    while ($p = mysqli_fetch_assoc($products)) { ?>
                                                        <option value="<?= $p['id'] ?>" data-price="<?= $p['purchase_price'] ?? 0 ?>"><?= htmlspecialchars($p['product_name']) ?> (Stock: <?= $p['current_stock'] ?? 0 ?>)</option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                            <div class="col-span-6 sm:col-span-3">
                                                <label class="text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1 block">Quantity <span class="text-red-500">*</span></label>
                                                <input type="number" name="quantity" id="add_qty" value="1" min="1" class="form-input text-sm">
                                            </div>
                                            <div class="col-span-6 sm:col-span-3">
                                                <label class="text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1 block">Purchase Price <span class="text-red-500">*</span></label>
                                                <div class="relative">
                                                    <input type="number" name="purchase_price" id="add_price" value="0" min="0" step="0.01" class="form-input text-sm">
                                                </div>
                                            </div>
                                            <div class="col-span-12 sm:col-span-2 flex items-end">
                                                <button type="submit" name="add_cart" class="btn btn-primary w-full justify-center text-sm h-[42px]">
                                                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                                    </svg>
                                                    <span class="hidden sm:inline">Add</span>
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Products Table -->
                                        <div class="table-wrap">
                                            <table class="data-table w-full">
                                                <thead>
                                                    <tr>
                                                        <th>#</th>
                                                        <th>Product</th>
                                                        <th class="center">Qty</th>
                                                        <th class="num">Purchase Price</th>
                                                        <th class="num">Total</th>
                                                        <th class="center">Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (count($_SESSION['cart']) > 0): $i = 1; ?>
                                                        <?php foreach ($_SESSION['cart'] as $key => $item): ?>
                                                            <tr data-product-id="<?= $item['product_id'] ?>">
                                                                <td><?= sprintf('%02d', $i++) ?></td>
                                                                <td class="font-medium"><?= htmlspecialchars($item['product_name']) ?></td>
                                                                <td class="center"><?= $item['quantity'] ?></td>
                                                                <td class="num"><?= number_format($item['price'], 2) ?></td>
                                                                <td class="num"><?= number_format($item['subtotal'], 2) ?></td>
                                                                <td class="center">
                                                                    <button type="button" onclick="removeCartItem(<?= $key ?>)" class="text-red-400 hover:text-red-600 transition p-1" title="Remove">
                                                                        <svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                                        </svg>
                                                                    </button>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <tr>
                                                            <td colspan="6" class="text-center py-10 text-gray-400">
                                                                <svg class="w-10 h-10 mx-auto mb-2 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                                                </svg>
                                                                <p class="text-sm">No products added yet.</p>
                                                                <p class="text-xs mt-0.5">Search and add products above.</p>
                                                            </td>
                                                        </tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <!-- ===== CARD: PAYMENT INFORMATION ===== -->
                                <div class="card">
                                    <div class="card-header">
                                        <h2 class="text-base font-bold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                            <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                                            </svg>
                                            Payment Information
                                        </h2>
                                    </div>
                                    <div class="card-body">
                                        <!-- Previous Supplier Balance Info (hidden by default) -->
                                        <div id="prevBalanceSection" class="hidden mb-5 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl p-4">
                                            <h4 class="text-sm font-bold text-amber-700 dark:text-amber-400 mb-3 flex items-center gap-2">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                                Supplier Previous Balance
                                            </h4>
                                            <div class="grid grid-cols-3 gap-3 text-sm">
                                                <div class="text-center">
                                                    <p class="text-xs text-amber-600 dark:text-amber-400 mb-1">Previous Balance</p>
                                                    <p class="font-bold text-amber-700 dark:text-amber-300" id="prevBalance">0.00</p>
                                                </div>
                                                <div class="text-center">
                                                    <p class="text-xs text-amber-600 dark:text-amber-400 mb-1">New Purchase Total</p>
                                                    <p class="font-bold text-amber-700 dark:text-amber-300" id="prevNewTotal">0.00</p>
                                                </div>
                                                <div class="text-center">
                                                    <p class="text-xs text-amber-600 dark:text-amber-400 mb-1">Total Amount Due</p>
                                                    <p class="font-bold text-amber-700 dark:text-amber-300" id="prevTotalDue">0.00</p>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Supplier Advance Balance (hidden by default) -->
                                        <div id="advanceSection" class="hidden mb-5 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-4">
                                            <h4 class="text-sm font-bold text-blue-700 dark:text-blue-400 mb-3 flex items-center gap-2">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                                Supplier Advance Balance
                                            </h4>
                                            <div class="flex items-center justify-between">
                                                <div>
                                                    <p class="text-xs text-blue-600 dark:text-blue-400">Available Credit</p>
                                                    <p class="text-lg font-bold text-blue-700 dark:text-blue-300" id="advanceBalance">0.00 MMK</p>
                                                </div>
                                                <label class="flex items-center gap-2 cursor-pointer">
                                                    <input type="checkbox" id="applyAdvance" name="apply_advance" value="1"
                                                        class="w-4 h-4 text-blue-600 bg-white border-gray-300 rounded focus:ring-blue-500"
                                                        onchange="recalcTotals()">
                                                    <span class="text-sm font-medium text-blue-700 dark:text-blue-300">Apply to this purchase</span>
                                                </label>
                                            </div>
                                        </div>

                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                                            <div>
                                                <label class="form-label">Payment Method <span class="text-red-500">*</span></label>
                                                <select name="payment_method" id="paymentMethod" class="form-input text-sm" onchange="recalcTotals()">
                                                    <option value="Cash" <?= $old_method == 'Cash' ? 'selected' : '' ?>>Cash</option>
                                                    <option value="KBZPay" <?= $old_method == 'KBZPay' ? 'selected' : '' ?>>KBZPay</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="form-label">Paid Amount <span class="text-red-500">*</span></label>
                                                <input type="number" name="paid_amount" id="paidAmount" value="<?= $old_paid ?>" min="0" step="0.01"
                                                    class="form-input text-sm" oninput="recalcTotals()" placeholder="Enter paid amount">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ===== RIGHT COLUMN (1/3) — STICKY SUMMARY ===== -->
                            <div class="lg:col-span-1">
                                <div class="summary-sticky">
                                    <div class="card">
                                        <div class="card-header">
                                            <h2 class="text-base font-bold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                                <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                                </svg>
                                                Purchase Summary
                                            </h2>
                                        </div>
                                        <div class="card-body space-y-4">
                                            <div>
                                                <label class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Subtotal</label>
                                                <div class="mt-1 flex items-center justify-between bg-gray-50 rounded-lg px-4 py-2.5">
                                                    <span class="text-sm text-gray-500 dark:text-gray-400">Items total</span>
                                                    <span class="text-lg font-bold text-gray-800 dark:text-gray-200" id="summarySubtotal"><?= number_format($cart_subtotal, 2) ?></span>
                                                </div>
                                            </div>
                                            <div class="border-t border-gray-200"></div>
                                            <div>
                                                <div class="flex items-center justify-between mb-1">
                                                    <label class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Grand Total</label>
                                                </div>
                                                <div class="flex items-center justify-between bg-indigo-50 rounded-xl px-4 py-3 border border-indigo-100">
                                                    <span class="text-sm font-medium text-indigo-600">Total amount</span>
                                                    <span class="text-2xl font-extrabold text-indigo-700" id="grandTotal"><?= number_format($cart_subtotal, 2) ?></span>
                                                </div>
                                            </div>

                                            <!-- Advance Applied (hidden by default) -->
                                            <div id="advanceAppliedSection" class="hidden">
                                                <label class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Advance Applied</label>
                                                <div class="mt-1 flex items-center justify-between bg-blue-50 rounded-xl px-4 py-3 border border-blue-200">
                                                    <span class="text-sm font-medium text-blue-600">Supplier credit</span>
                                                    <span class="text-lg font-extrabold text-blue-700" id="advanceApplied">0.00</span>
                                                </div>
                                            </div>

                                            <!-- Remaining After Advance (shown when advance is applied) -->
                                            <div id="remainingAfterAdvanceSection" class="hidden">
                                                <label class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Remaining to Pay</label>
                                                <div class="mt-1 flex items-center justify-between bg-gray-50 rounded-lg px-4 py-2.5">
                                                    <span class="text-sm text-gray-500 dark:text-gray-400">After advance</span>
                                                    <span class="text-lg font-bold text-gray-800 dark:text-gray-200" id="remainingAfterAdvance">0.00</span>
                                                </div>
                                            </div>

                                            <div>
                                                <label class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Payment Status</label>
                                                <div class="mt-1">
                                                    <input type="text" id="paymentStatusDisplay" readonly
                                                        class="form-input bg-gray-50 cursor-not-allowed text-sm font-semibold text-red-600 bg-red-50">
                                                    <input type="hidden" name="payment_status" id="paymentStatusInput" value="Unpaid">
                                                </div>
                                            </div>

                                            <div id="changeAmountSection" class="hidden">
                                                <label class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Change Amount</label>
                                                <div class="mt-1 flex items-center justify-between bg-emerald-50 rounded-xl px-4 py-3 border border-emerald-200">
                                                    <span class="text-sm font-medium text-emerald-600">Change</span>
                                                    <span class="text-xl font-extrabold text-emerald-700" id="changeAmount">0.00</span>
                                                </div>
                                            </div>

                                            <input type="hidden" name="save_purchase" id="savePurchaseField" value="0">
                                            <button type="button" onclick="checkPricesThenConfirm()" id="saveBtn" class="btn btn-primary w-full justify-center btn-lg gap-2 mt-2">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4" />
                                                </svg>
                                                Save Purchase
                                            </button>
                                            <p class="text-xs text-center text-gray-400">Review details before saving</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </main>
        </div>
    </div>

    <!-- ===== CONFIRMATION MODAL ===== -->
    <div id="confirmModal" class="modal-overlay hidden">
        <div class="bg-white dark:bg-slate-800 rounded-2xl p-6 w-full max-w-lg mx-4 shadow-2xl" style="animation: modalIn 0.2s ease-out;">
            <!-- Header -->
            <div class="flex items-center gap-4 mb-5">
                <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900/50 rounded-full flex items-center justify-center flex-shrink-0">
                    <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-gray-800 dark:text-gray-200">Confirm Purchase</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Please review all details before saving.</p>
                </div>
            </div>

            <!-- Purchase Details -->
            <div class="bg-gray-50 dark:bg-slate-700/50 rounded-xl p-4 mb-4">
                <h4 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">Purchase Details</h4>
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">Invoice No</span>
                        <p class="font-semibold text-gray-800 dark:text-gray-200" id="confirmInvoice">-</p>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">Supplier</span>
                        <p class="font-semibold text-gray-800 dark:text-gray-200" id="confirmSupplier">-</p>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">Purchase Date</span>
                        <p class="font-semibold text-gray-800 dark:text-gray-200" id="confirmDate">-</p>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">Products</span>
                        <p class="font-semibold text-gray-800 dark:text-gray-200"><span id="confirmProductCount">0</span> items (<span id="confirmTotalQty">0</span> total qty)</p>
                    </div>
                </div>
            </div>

            <!-- Payment Summary -->
            <div class="bg-indigo-50 dark:bg-indigo-900/20 rounded-xl p-4 mb-4">
                <h4 class="text-xs font-semibold text-indigo-600 dark:text-indigo-400 uppercase tracking-wider mb-3">Payment Summary</h4>
                <div class="space-y-2 text-sm">
                    <div id="confirmPrevBalanceRow" class="flex justify-between hidden">
                        <span class="text-gray-600 dark:text-gray-400">Previous Balance</span>
                        <span class="font-semibold text-amber-600" id="confirmPrevBalance">0.00</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600 dark:text-gray-400">Purchase Total</span>
                        <span class="font-bold text-indigo-700 dark:text-indigo-400 text-lg" id="confirmTotal">0.00</span>
                    </div>
                    <div id="confirmTotalDueRow" class="flex justify-between hidden">
                        <span class="text-gray-600 dark:text-gray-400 font-bold">Total Amount Due</span>
                        <span class="font-bold text-indigo-700 dark:text-indigo-400 text-lg" id="confirmTotalDue">0.00</span>
                    </div>
                    <div class="border-t border-indigo-200 dark:border-indigo-800 pt-2">
                        <div class="flex justify-between">
                            <span class="text-gray-600 dark:text-gray-400">Payment Method</span>
                            <span class="font-semibold text-gray-800 dark:text-gray-200" id="confirmMethod">Cash</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600 dark:text-gray-400">Total Paid</span>
                            <span class="font-semibold text-emerald-600" id="confirmPaid">0.00</span>
                        </div>
                        <div id="confirmAdvanceRow" class="flex justify-between hidden">
                            <span class="text-gray-600 dark:text-gray-400">Advance Applied</span>
                            <span class="font-semibold text-blue-600" id="confirmAdvanceApplied">0.00</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600 dark:text-gray-400">Remaining Balance</span>
                            <span class="font-semibold" id="confirmBalance">0.00</span>
                        </div>
                        <div id="confirmAdvanceCreatedRow" class="flex justify-between hidden">
                            <span class="text-gray-600 dark:text-gray-400">Advance Created</span>
                            <span class="font-semibold text-blue-600" id="confirmAdvanceCreated">0.00</span>
                        </div>
                    </div>
                    <div class="border-t border-indigo-200 dark:border-indigo-800 pt-2 flex justify-between">
                        <span class="font-bold text-gray-800 dark:text-gray-200">Payment Status</span>
                        <span class="font-bold px-3 py-1 rounded-full text-xs" id="confirmStatus">Unpaid</span>
                    </div>
                </div>
            </div>

            <!-- Warning -->
            <!-- <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl p-4 mb-5">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-amber-500 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <div>
                        <p class="text-sm font-semibold text-amber-700 dark:text-amber-400">Warning</p>
                        <p class="text-xs text-amber-600 dark:text-amber-500 mt-1">This action will save the purchase, update product stock automatically, record the payment, and cannot be undone.</p>
                    </div>
                </div>
            </div> -->

            <!-- Buttons -->
            <div class="flex gap-3">
                <button type="button" onclick="closeConfirmModal()" class="btn btn-secondary flex-1 justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                    Cancel
                </button>
                <button type="button" onclick="submitForm()" class="btn btn-primary flex-1 justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    Confirm Purchase
                </button>
            </div>
        </div>
    </div>

    <!-- ===== REMOVE CONFIRM MODAL ===== -->
    <div id="removeModal" class="modal-overlay hidden">
        <div class="bg-white rounded-2xl p-6 w-full max-w-sm mx-4 shadow-2xl" style="animation: modalIn 0.2s ease-out;">
            <div class="text-center mb-5">
                <div class="w-14 h-14 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-3">
                    <svg class="w-7 h-7 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <h3 class="text-lg font-bold text-gray-800 dark:text-gray-200">Remove Item</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">This item will be removed from the cart.</p>
            </div>
            <div class="flex gap-3">
                <button type="button" onclick="closeRemoveModal()" class="btn btn-secondary flex-1 justify-center">Keep</button>
                <a href="#" id="removeConfirmLink" class="btn btn-danger flex-1 justify-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                    Remove
                </a>
            </div>
        </div>
    </div>

    <!-- ===== PRICE WARNING MODAL ===== -->
    <div id="priceWarningModal" class="modal-overlay hidden">
        <div class="bg-white dark:bg-slate-800 rounded-2xl p-6 w-full max-w-lg mx-4 shadow-2xl" style="animation: modalIn 0.2s ease-out;">
            <!-- Header -->
            <div class="flex items-center gap-4 mb-5">
                <div id="priceWarningIcon" class="w-12 h-12 bg-amber-100 dark:bg-amber-900/50 rounded-full flex items-center justify-center flex-shrink-0">
                    <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-gray-800 dark:text-gray-200" id="priceWarningTitle">Price Warning</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400" id="priceWarningSubtitle">Review pricing before continuing</p>
                </div>
            </div>

            <!-- Warning Message -->
            <div id="priceWarningMessage" class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl p-4 mb-5">
                <p class="text-sm text-amber-700 dark:text-amber-400" id="priceWarningText"></p>
            </div>

            <!-- Affected Products Table -->
            <div id="priceWarningItems" class="bg-gray-50 dark:bg-slate-700/50 rounded-xl p-4 mb-5 max-h-48 overflow-y-auto">
                <!-- Populated by JS -->
            </div>

            <!-- Inline Update Section (hidden by default) -->
            <div id="priceUpdateSection" class="hidden mb-5">
                <div class="bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800 rounded-xl p-4">
                    <h4 class="text-sm font-bold text-indigo-700 dark:text-indigo-400 mb-3 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                        Update Selling Price
                    </h4>
                    <div id="priceUpdateList" class="space-y-3">
                        <!-- Populated by JS -->
                    </div>
                    <div class="flex justify-end gap-2 mt-3">
                        <button type="button" onclick="cancelPriceUpdate()" class="btn btn-secondary btn-sm">Cancel</button>
                        <button type="button" onclick="savePriceUpdates()" class="btn btn-primary btn-sm gap-1.5" id="savePriceUpdateBtn">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            Save & Continue
                        </button>
                    </div>
                </div>
            </div>

            <!-- Buttons -->
            <div class="flex gap-3" id="priceWarningButtons">
                <button type="button" onclick="closePriceWarning()" class="btn btn-secondary flex-1 justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                    Cancel
                </button>
                <button type="button" onclick="showPriceUpdateForm()" class="btn bg-indigo-600 hover:bg-indigo-700 text-white flex-1 justify-center gap-2" id="updatePriceBtn">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                    Update Selling Price
                </button>
                <button type="button" onclick="proceedWithPurchase()" class="btn bg-red-600 hover:bg-red-700 text-white flex-1 justify-center gap-2" id="continueAnywayBtn">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    Continue Anyway
                </button>
            </div>
        </div>
    </div>

    <?php include "../includes/toast.php"; ?>
    <?php include "../includes/form_guard.php"; ?>
    <?php include "../includes/footer.php"; ?>

    <script>
        // Product selling prices map: { product_id: selling_price }
        const profitMargin = <?= (float)getProfitMargin($conn); ?>;
        const sellingPrices = {
            <?php
            $sp_res = @mysqli_query($conn, "SELECT id, selling_price FROM products WHERE status='Active'");
            $first = true;
            if ($sp_res) {
                while ($p = mysqli_fetch_assoc($sp_res)) {
                    if (!$first) echo ',';
                    echo $p['id'] . ':' . (float)$p['selling_price'];
                    $first = false;
                }
            }
            ?>
        };

        const supplierBalances = {
            <?php
            $first = true;
            foreach ($supplier_balances as $sid => $sdata) {
                if (!$first) echo ',';
                echo $sid . ':{balance:' . $sdata['balance'] . ',advance:' . $sdata['advance'] . '}';
                $first = false;
            }
            ?>
        };

        let selectedPrevBalance = 0;
        let selectedAdvance = 0;

        function onSupplierChange() {
            const supplierId = document.getElementById('supplier_id').value;
            const section = document.getElementById('prevBalanceSection');
            const prevBalEl = document.getElementById('prevBalance');
            const prevNewTotalEl = document.getElementById('prevNewTotal');
            const prevTotalDueEl = document.getElementById('prevTotalDue');
            const advanceSection = document.getElementById('advanceSection');
            const advanceBalEl = document.getElementById('advanceBalance');
            const applyAdvanceCb = document.getElementById('applyAdvance');
            selectedPrevBalance = 0;
            selectedAdvance = 0;

            if (supplierId && supplierBalances[supplierId]) {
                const supData = supplierBalances[supplierId];
                // Show previous balance if positive
                if (supData.balance > 0) {
                    selectedPrevBalance = supData.balance;
                    prevBalEl.textContent = parseFloat(selectedPrevBalance).toLocaleString('en', {
                        minimumFractionDigits: 2
                    });
                    prevNewTotalEl.textContent = '0.00';
                    prevTotalDueEl.textContent = parseFloat(selectedPrevBalance).toLocaleString('en', {
                        minimumFractionDigits: 2
                    });
                    section.classList.remove('hidden');
                } else {
                    section.classList.add('hidden');
                    prevBalEl.textContent = '0.00';
                    prevNewTotalEl.textContent = '0.00';
                    prevTotalDueEl.textContent = '0.00';
                }
                // Show advance balance if positive
                if (supData.advance > 0) {
                    selectedAdvance = supData.advance;
                    advanceBalEl.textContent = parseFloat(selectedAdvance).toLocaleString('en', {
                        minimumFractionDigits: 2
                    }) + ' MMK';
                    applyAdvanceCb.checked = true;
                    advanceSection.classList.remove('hidden');
                } else {
                    selectedAdvance = 0;
                    applyAdvanceCb.checked = false;
                    advanceSection.classList.add('hidden');
                }
            } else {
                section.classList.add('hidden');
                advanceSection.classList.add('hidden');
                prevBalEl.textContent = '0.00';
                prevNewTotalEl.textContent = '0.00';
                prevTotalDueEl.textContent = '0.00';
            }
            recalcTotals();
        }

        function autoFillPrice(el) {
            const price = el.options[el.selectedIndex]?.dataset.price;
            if (price && parseFloat(price) > 0) {
                document.getElementById('add_price').value = parseFloat(price).toFixed(2);
            } else {
                document.getElementById('add_price').value = '0';
            }
        }

        // Track which products had price warnings
        let priceWarningProductIds = [];

        function checkPricesThenConfirm() {
            const rows = document.querySelectorAll('.data-table tbody tr');
            const hasItems = rows.length > 0 && !(rows.length === 1 && rows[0].querySelector('td[colspan]'));
            if (!hasItems) {
                showToast('error', 'Please add at least one product to the cart.');
                return;
            }

            // Categorize cart items by price comparison
            const equalWarnings = [];
            const lossWarnings = [];
            priceWarningProductIds = [];

            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length < 4) return;
                const productId = row.dataset.productId;
                const productName = cells[1]?.textContent?.trim() || '';
                const purchasePrice = parseFloat(cells[3]?.textContent?.replace(/,/g, '')) || 0;
                const sp = sellingPrices[productId] || 0;

                if (sp > 0 && purchasePrice === sp) {
                    equalWarnings.push({
                        id: productId,
                        name: productName,
                        purchase: purchasePrice,
                        selling: sp
                    });
                } else if (sp > 0 && purchasePrice > sp) {
                    lossWarnings.push({
                        id: productId,
                        name: productName,
                        purchase: purchasePrice,
                        selling: sp
                    });
                }
            });

            // If no warnings, proceed to confirm
            if (equalWarnings.length === 0 && lossWarnings.length === 0) {
                showConfirmModal();
                return;
            }

            // Collect all affected product IDs
            equalWarnings.forEach(w => priceWarningProductIds.push(w.id));
            lossWarnings.forEach(w => priceWarningProductIds.push(w.id));

            // Set modal appearance based on severity
            const iconEl = document.getElementById('priceWarningIcon');
            const titleEl = document.getElementById('priceWarningTitle');
            const subtitleEl = document.getElementById('priceWarningSubtitle');
            const messageEl = document.getElementById('priceWarningMessage');
            const messageText = document.getElementById('priceWarningText');

            const hasLoss = lossWarnings.length > 0;
            const hasEqual = equalWarnings.length > 0;

            if (hasLoss) {
                iconEl.className = 'w-12 h-12 bg-red-100 dark:bg-red-900/50 rounded-full flex items-center justify-center flex-shrink-0';
                iconEl.querySelector('svg').className.baseVal = 'w-6 h-6 text-red-600';
                titleEl.textContent = 'Price Warning';
                subtitleEl.textContent = 'Review pricing before continuing';
                messageEl.className = 'bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl p-4 mb-5';
                messageText.className = 'text-sm text-red-700 dark:text-red-400';
                messageText.textContent = 'Some products have a purchase price higher than the selling price, which will result in a loss.';
            } else {
                iconEl.className = 'w-12 h-12 bg-amber-100 dark:bg-amber-900/50 rounded-full flex items-center justify-center flex-shrink-0';
                iconEl.querySelector('svg').className.baseVal = 'w-6 h-6 text-amber-600';
                titleEl.textContent = 'Price Warning';
                subtitleEl.textContent = 'Review pricing before continuing';
                messageEl.className = 'bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl p-4 mb-5';
                messageText.className = 'text-sm text-amber-700 dark:text-amber-400';
                messageText.textContent = 'Some products have a purchase price equal to the selling price, resulting in zero profit.';
            }

            // Build warning table
            let html = '<table class="w-full text-sm"><thead><tr class="border-b border-gray-200 dark:border-slate-600">';
            html += '<th class="text-left py-2 font-semibold text-gray-600 dark:text-gray-400">Product</th>';
            html += '<th class="text-right py-2 font-semibold text-gray-600 dark:text-gray-400">Purchase</th>';
            html += '<th class="text-right py-2 font-semibold text-gray-600 dark:text-gray-400">Selling</th>';
            html += '<th class="text-right py-2 font-semibold text-gray-600 dark:text-gray-400">Status</th>';
            html += '</tr></thead><tbody>';

            lossWarnings.forEach(w => {
                html += '<tr class="border-b border-gray-100 dark:border-slate-700">';
                html += '<td class="py-2 font-medium text-gray-800 dark:text-gray-200">' + w.name + '</td>';
                html += '<td class="py-2 text-right text-red-600 font-semibold">' + w.purchase.toLocaleString() + ' Ks</td>';
                html += '<td class="py-2 text-right text-gray-600 dark:text-gray-400">' + w.selling.toLocaleString() + ' Ks</td>';
                html += '<td class="py-2 text-right"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-700">Loss Risk</span></td>';
                html += '</tr>';
            });
            equalWarnings.forEach(w => {
                html += '<tr class="border-b border-gray-100 dark:border-slate-700">';
                html += '<td class="py-2 font-medium text-gray-800 dark:text-gray-200">' + w.name + '</td>';
                html += '<td class="py-2 text-right text-orange-600 font-semibold">' + w.purchase.toLocaleString() + ' Ks</td>';
                html += '<td class="py-2 text-right text-gray-600 dark:text-gray-400">' + w.selling.toLocaleString() + ' Ks</td>';
                html += '<td class="py-2 text-right"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-orange-100 text-orange-700">No Profit</span></td>';
                html += '</tr>';
            });
            html += '</tbody></table>';

            document.getElementById('priceWarningItems').innerHTML = html;
            document.getElementById('priceUpdateSection').classList.add('hidden');
            document.getElementById('priceWarningButtons').classList.remove('hidden');
            document.getElementById('priceWarningModal').classList.remove('hidden');
        }

        function closePriceWarning() {
            document.getElementById('priceWarningModal').classList.add('hidden');
            document.getElementById('priceUpdateSection').classList.add('hidden');
            document.getElementById('priceWarningButtons').classList.remove('hidden');
        }

        function proceedWithPurchase() {
            document.getElementById('priceWarningModal').classList.add('hidden');
            showConfirmModal();
        }

        function showPriceUpdateForm() {
            const allWarnings = [];
            priceWarningProductIds.forEach(id => {
                const sp = sellingPrices[id] || 0;
                // Find product name from the warning items
                const row = document.querySelector('.data-table tbody tr[data-product-id="' + id + '"]');
                const name = row ? row.querySelector('td:nth-child(2)')?.textContent?.trim() : 'Product';
                const purchasePrice = row ? parseFloat(row.querySelector('td:nth-child(4)')?.textContent?.replace(/,/g, '')) || 0 : 0;
                allWarnings.push({
                    id: id,
                    name: name,
                    purchase: purchasePrice,
                    selling: sp
                });
            });

            let html = '';
            allWarnings.forEach(w => {
                const suggestedPrice = Math.ceil(w.purchase * (1 + profitMargin / 100));
                html += '<div class="flex items-center gap-3 p-3 bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-600">';
                html += '<div class="flex-1 min-w-0">';
                html += '<p class="text-sm font-semibold text-gray-800 dark:text-gray-200 truncate">' + w.name + '</p>';
                html += '<p class="text-xs text-gray-500 dark:text-gray-400">Purchase: ' + w.purchase.toLocaleString() + ' Ks</p>';
                html += '</div>';
                html += '<div class="relative">';
                html += '<input type="number" data-product-id="' + w.id + '" value="' + suggestedPrice + '" min="0" step="100" class="form-input text-sm pr-8 price-update-input">';
                html += '<span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">Ks</span>';
                html += '</div>';
                html += '</div>';
            });

            document.getElementById('priceUpdateList').innerHTML = html;
            document.getElementById('priceUpdateSection').classList.remove('hidden');
            document.getElementById('priceWarningButtons').classList.add('hidden');
        }

        function cancelPriceUpdate() {
            document.getElementById('priceUpdateSection').classList.add('hidden');
            document.getElementById('priceWarningButtons').classList.remove('hidden');
        }

        async function savePriceUpdates() {
            const inputs = document.querySelectorAll('.price-update-input');
            const saveBtn = document.getElementById('savePriceUpdateBtn');
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<svg class="w-3.5 h-3.5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Saving...';

            let allSuccess = true;
            for (const input of inputs) {
                const productId = input.dataset.productId;
                const newPrice = parseFloat(input.value) || 0;

                if (newPrice <= 0) {
                    allSuccess = false;
                    continue;
                }

                try {
                    const resp = await fetch('../ajax/update_selling_price.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            product_id: parseInt(productId),
                            selling_price: newPrice
                        })
                    });
                    const data = await resp.json();
                    if (data.success) {
                        sellingPrices[productId] = newPrice;
                    } else {
                        allSuccess = false;
                        showToast('error', data.message || 'Failed to update price');
                    }
                } catch (e) {
                    allSuccess = false;
                    showToast('error', 'Network error updating price');
                }
            }

            saveBtn.disabled = false;
            saveBtn.innerHTML = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg> Save & Continue';

            if (allSuccess) {
                showToast('success', 'Selling prices updated successfully');
                document.getElementById('priceWarningModal').classList.add('hidden');
                showConfirmModal();
            }
        }

        function handleFormSubmit(e) {
            if (e.submitter && e.submitter.name === 'add_cart') return true;
            e.preventDefault();
            showConfirmModal();
            return false;
        }

        function recalcTotals() {
            const subtotalTxt = document.getElementById('summarySubtotal').textContent.replace(/,/g, '');
            const grand = parseFloat(subtotalTxt) || 0;
            document.getElementById('grandTotal').textContent = grand.toFixed(2);

            // Previous balance info
            const totalDue = selectedPrevBalance + grand;
            if (selectedPrevBalance > 0) {
                document.getElementById('prevNewTotal').textContent = grand.toLocaleString('en', {
                    minimumFractionDigits: 2
                });
                document.getElementById('prevTotalDue').textContent = totalDue.toLocaleString('en', {
                    minimumFractionDigits: 2
                });
            }

            // Advance calculation
            const applyAdvance = document.getElementById('applyAdvance').checked;
            const advanceAppliedSection = document.getElementById('advanceAppliedSection');
            const advanceAppliedEl = document.getElementById('advanceApplied');
            const remainingAfterAdvanceSection = document.getElementById('remainingAfterAdvanceSection');
            const remainingAfterAdvanceEl = document.getElementById('remainingAfterAdvance');

            let advanceToApply = 0;
            if (applyAdvance && selectedAdvance > 0 && grand > 0) {
                advanceToApply = Math.min(selectedAdvance, grand);
                advanceAppliedEl.textContent = advanceToApply.toLocaleString('en', {
                    minimumFractionDigits: 2
                });
                advanceAppliedSection.classList.remove('hidden');

                const remainingAfter = grand - advanceToApply;
                remainingAfterAdvanceEl.textContent = remainingAfter.toLocaleString('en', {
                    minimumFractionDigits: 2
                });
                remainingAfterAdvanceSection.classList.remove('hidden');
            } else {
                advanceAppliedSection.classList.add('hidden');
                remainingAfterAdvanceSection.classList.add('hidden');
            }

            const paidAmount = parseFloat(document.getElementById('paidAmount').value) || 0;
            const effectiveTotal = grand - advanceToApply;
            const totalPayment = paidAmount + advanceToApply;
            const remainingBalance = Math.max(0, effectiveTotal - paidAmount);

            const statusDisplay = document.getElementById('paymentStatusDisplay');
            const statusInput = document.getElementById('paymentStatusInput');
            const changeSection = document.getElementById('changeAmountSection');
            const changeDisplay = document.getElementById('changeAmount');

            let status = 'Unpaid';
            if (totalPayment >= effectiveTotal && effectiveTotal > 0) {
                status = 'Paid';
            } else if (paidAmount > 0 || advanceToApply > 0) {
                status = 'Partial';
            }

            statusInput.value = status;
            statusDisplay.value = status;

            // Status colors
            statusDisplay.className = 'form-input bg-gray-50 cursor-not-allowed text-sm font-semibold ';
            if (status === 'Paid') {
                statusDisplay.className += 'text-emerald-600 bg-emerald-50';
            } else if (status === 'Partial') {
                statusDisplay.className += 'text-amber-600 bg-amber-50';
            } else {
                statusDisplay.className += 'text-red-600 bg-red-50';
            }

            // Change amount / advance created (only for Cash, when paid > effective total)
            const method = document.getElementById('paymentMethod').value;
            if (method === 'Cash' && paidAmount > effectiveTotal && effectiveTotal > 0) {
                changeDisplay.textContent = (paidAmount - effectiveTotal).toFixed(2);
                changeSection.classList.remove('hidden');
                changeSection.querySelector('label').textContent = 'Advance Created';
            } else if (advanceToApply > 0 && paidAmount > 0 && totalPayment > grand) {
                // Overpayment after advance
                changeDisplay.textContent = (totalPayment - grand).toFixed(2);
                changeSection.classList.remove('hidden');
                changeSection.querySelector('label').textContent = 'Advance Created';
            } else {
                changeSection.classList.add('hidden');
            }
        }

        let removeKey = null;

        function removeCartItem(key) {
            removeKey = key;
            document.getElementById('removeConfirmLink').href = '?remove=' + key;
            document.getElementById('removeModal').classList.remove('hidden');
        }

        function closeRemoveModal() {
            document.getElementById('removeModal').classList.add('hidden');
            removeKey = null;
        }

        function showConfirmModal() {
            const supplierId = document.getElementById('supplier_id').value;
            if (!supplierId) {
                showToast('error', 'Please select a supplier.');
                document.getElementById('supplier_id').focus();
                return;
            }

            const purchaseDate = document.querySelector('input[name="purchase_date"]').value;
            if (!purchaseDate) {
                showToast('error', 'Please select a purchase date.');
                document.querySelector('input[name="purchase_date"]').focus();
                return;
            }

            const rows = document.querySelectorAll('.data-table tbody tr');
            const hasItems = rows.length > 0 && !(rows.length === 1 && rows[0].querySelector('td[colspan]'));
            if (!hasItems) {
                showToast('error', 'Please add at least one product to the cart.');
                return;
            }

            const method = document.getElementById('paymentMethod').value;
            if (!method) {
                showToast('error', 'Please select a payment method.');
                document.getElementById('paymentMethod').focus();
                return;
            }

            const paidAmount = parseFloat(document.getElementById('paidAmount').value) || 0;

            // Gather data
            const items = document.querySelectorAll('.data-table tbody tr:not(:has(td[colspan]))');
            const grand = parseFloat(document.getElementById('grandTotal').textContent.replace(/,/g, '')) || 0;
            const applyAdvance = document.getElementById('applyAdvance').checked;
            let advanceToApply = 0;
            if (applyAdvance && selectedAdvance > 0 && grand > 0) {
                advanceToApply = Math.min(selectedAdvance, grand);
            }
            const effectiveTotal = grand - advanceToApply;
            const totalPayment = paidAmount + advanceToApply;
            const remainingBalance = Math.max(0, effectiveTotal - paidAmount);
            const advanceCreated = Math.max(0, totalPayment - effectiveTotal);
            const totalDue = selectedPrevBalance + grand;
            const status = document.getElementById('paymentStatusInput').value;
            const supplierName = document.getElementById('supplier_id').options[document.getElementById('supplier_id').selectedIndex].text;
            const today = new Date();
            const invoiceNo = 'INV-' + today.getFullYear() + String(today.getMonth() + 1).padStart(2, '0') + String(today.getDate()).padStart(2, '0') + '-....';

            // Calculate total quantity
            let totalQty = 0;
            items.forEach(row => {
                const qtyCell = row.querySelectorAll('td')[2];
                if (qtyCell) totalQty += parseInt(qtyCell.textContent) || 0;
            });

            // Populate modal
            document.getElementById('confirmInvoice').textContent = invoiceNo;
            document.getElementById('confirmSupplier').textContent = supplierName || '-';
            document.getElementById('confirmDate').textContent = purchaseDate || '-';
            document.getElementById('confirmProductCount').textContent = items.length;
            document.getElementById('confirmTotalQty').textContent = totalQty;
            document.getElementById('confirmTotal').textContent = grand.toFixed(2);
            document.getElementById('confirmPaid').textContent = totalPayment.toFixed(2);
            document.getElementById('confirmMethod').textContent = advanceToApply > 0 && paidAmount === 0 ? 'Advance Only' : method;
            document.getElementById('confirmBalance').textContent = remainingBalance.toFixed(2);
            document.getElementById('confirmStatus').textContent = status;

            // Show/hide previous balance rows
            const prevBalRow = document.getElementById('confirmPrevBalanceRow');
            const totalDueRow = document.getElementById('confirmTotalDueRow');
            if (selectedPrevBalance > 0) {
                prevBalRow.classList.remove('hidden');
                document.getElementById('confirmPrevBalance').textContent = selectedPrevBalance.toFixed(2);
                totalDueRow.classList.remove('hidden');
                document.getElementById('confirmTotalDue').textContent = totalDue.toFixed(2);
            } else {
                prevBalRow.classList.add('hidden');
                totalDueRow.classList.add('hidden');
            }

            // Show/hide advance applied row
            const advRow = document.getElementById('confirmAdvanceRow');
            if (advanceToApply > 0) {
                advRow.classList.remove('hidden');
                document.getElementById('confirmAdvanceApplied').textContent = advanceToApply.toFixed(2);
            } else {
                advRow.classList.add('hidden');
            }

            // Show/hide advance created row
            const advCreatedRow = document.getElementById('confirmAdvanceCreatedRow');
            if (advanceCreated > 0) {
                advCreatedRow.classList.remove('hidden');
                document.getElementById('confirmAdvanceCreated').textContent = advanceCreated.toFixed(2);
            } else {
                advCreatedRow.classList.add('hidden');
            }

            // Status badge styling
            const statusEl = document.getElementById('confirmStatus');
            statusEl.className = 'font-bold px-3 py-1 rounded-full text-xs ';
            if (status === 'Paid') {
                statusEl.className += 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-400';
            } else if (status === 'Partial') {
                statusEl.className += 'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-400';
            } else {
                statusEl.className += 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-400';
            }

            document.getElementById('confirmModal').classList.remove('hidden');
        }

        function closeConfirmModal() {
            document.getElementById('confirmModal').classList.add('hidden');
        }

        function submitForm() {
            document.getElementById('savePurchaseField').value = '1';
            // Disable all buttons and show loading
            const btn = document.getElementById('saveBtn');
            btn.disabled = true;
            btn.innerHTML = '<svg class="w-5 h-5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Saving...';

            // Disable modal buttons
            const modalBtns = document.querySelectorAll('#confirmModal button');
            modalBtns.forEach(b => {
                b.disabled = true;
            });

            document.getElementById('purchaseForm').submit();
        }
    </script>
</body>

</html>