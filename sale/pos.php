<?php
include "../includes/auth_check.php";
protectSales('add');
include "../config/database.php";
include "../config/helpers.php";

if (!isset($_SESSION['sale_cart'])) {
    $_SESSION['sale_cart'] = [];
}

function generateInvoiceNo($conn)
{
    $result = mysqli_query($conn, "SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM sales");
    $row = mysqli_fetch_assoc($result);
    return 'INV-' . date('Ymd') . '-' . str_pad($row['next_id'], 4, '0', STR_PAD_LEFT);
}

// ============ ADD TO CART ============
if (isset($_POST['add_cart'])) {
    $product_id = (int)$_POST['product_id'];
    $qty = max(1, (int)($_POST['quantity'] ?? 1));

    $found = false;
    foreach ($_SESSION['sale_cart'] as &$item) {
        if ($item['product_id'] == $product_id) {
            $item['quantity'] += $qty;
            $item['total'] = $item['quantity'] * $item['price'];
            $found = true;
            break;
        }
    }
    unset($item);

    if (!$found) {
        $p = mysqli_fetch_assoc(mysqli_query(
            $conn,
            "SELECT id, product_name, selling_price, purchase_price, current_stock AS stock FROM products WHERE id='$product_id' AND status='Active'"
        ));
        if ($p && $p['stock'] >= $qty) {
            $_SESSION['sale_cart'][] = [
                'product_id' => $p['id'],
                'product_name' => $p['product_name'],
                'price' => $p['selling_price'],
                'purchase_price' => $p['purchase_price'],
                'quantity' => $qty,
                'total' => $qty * $p['selling_price']
            ];
        }
    }
    header("Location: pos.php");
    exit;
}

// ============ AJAX UPDATE CART QUANTITY ============
if (isset($_POST['ajax_update_qty'])) {
    $key = (int)$_POST['item_key'];
    $qty = max(1, (int)$_POST['quantity']);
    if (isset($_SESSION['sale_cart'][$key])) {
        $_SESSION['sale_cart'][$key]['quantity'] = $qty;
        $_SESSION['sale_cart'][$key]['total'] = $qty * $_SESSION['sale_cart'][$key]['price'];
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// ============ REMOVE FROM CART ============
if (isset($_GET['remove'])) {
    $key = (int)$_GET['remove'];
    if (isset($_SESSION['sale_cart'][$key])) {
        unset($_SESSION['sale_cart'][$key]);
        $_SESSION['sale_cart'] = array_values($_SESSION['sale_cart']);
    }
    header("Location: pos.php");
    exit;
}

// ============ UPDATE CART QUANTITIES ============
if (isset($_POST['update_cart'])) {
    foreach (($_POST['quantity'] ?? []) as $key => $qty) {
        $qty = max(0, (int)$qty);
        if (isset($_SESSION['sale_cart'][$key])) {
            if ($qty <= 0) {
                unset($_SESSION['sale_cart'][$key]);
            } else {
                $_SESSION['sale_cart'][$key]['quantity'] = $qty;
                $_SESSION['sale_cart'][$key]['total'] = $qty * $_SESSION['sale_cart'][$key]['price'];
            }
        }
    }
    $_SESSION['sale_cart'] = array_values($_SESSION['sale_cart']);
    header("Location: pos.php");
    exit;
}

// ============ CLEAR CART ============
if (isset($_GET['clear_cart'])) {
    unset($_SESSION['sale_cart']);
    header("Location: pos.php");
    exit;
}

// ============ COMPLETE SALE ============
$error = '';
if (isset($_POST['complete_sale'])) {
    if (count($_SESSION['sale_cart']) > 0) {
        $insufficient = [];
        foreach ($_SESSION['sale_cart'] as $item) {
            $prod = mysqli_fetch_assoc(mysqli_query(
                $conn,
                "SELECT product_name, current_stock FROM products WHERE id='{$item['product_id']}'"
            ));
            if ($prod && $prod['current_stock'] < $item['quantity']) {
                $insufficient[] = $prod['product_name'] . " (available: {$prod['current_stock']})";
            }
        }

        if (count($insufficient) > 0) {
            $error = "Insufficient stock: " . implode(", ", $insufficient);
        } else {
            $grand_total = array_sum(array_column($_SESSION['sale_cart'], 'total'));
            $discount = (float)($_POST['discount'] ?? 0);
            $discount = min(100, max(0, $discount));
            $discount_amount = $grand_total * ($discount / 100);
            $grand_total -= $discount_amount;
            if ($grand_total < 0) $grand_total = 0;

            $payment_method = $_POST['payment_method'] ?? 'Cash';
            $payment_cash = (float)($_POST['payment_cash'] ?? 0);
            $payment_kbzpay = (float)($_POST['payment_kbzpay'] ?? 0);
            $mixed_cash = (float)($_POST['mixed_cash'] ?? 0);
            $mixed_kbzpay = (float)($_POST['mixed_kbzpay'] ?? 0);

            if ($payment_method === 'Cash') {
                $total_paid = $payment_cash;
            } elseif ($payment_method === 'KBZPay') {
                $total_paid = $payment_kbzpay;
            } else {
                $total_paid = $mixed_cash + $mixed_kbzpay;
            }

            if ($total_paid < $grand_total - 0.01) {
                $error = "Payment amount is not enough.";
            } elseif ($payment_method !== 'Cash' && $total_paid > $grand_total + 0.01) {
                $error = "Payment amount exceeds total.";
            } else {
                $invoice_no = generateInvoiceNo($conn);
                $user_id = $_SESSION['user_id'] ?? null;

                if ($payment_method === 'Mixed') {
                    $primary_method = 'Mixed';
                } else {
                    $primary_method = $payment_method;
                }

                mysqli_begin_transaction($conn);
                try {
                    $subtotal = array_sum(array_column($_SESSION['sale_cart'], 'total'));
                    $stmt = $conn->prepare("INSERT INTO sales (invoice_no, user_id, subtotal, total_amount, discount, created_at)
                        VALUES (?, ?, ?, ?, ?, NOW())");
                    $stmt->bind_param("siddd", $invoice_no, $user_id, $subtotal, $grand_total, $discount);
                    $stmt->execute();
                    $sale_id = $conn->insert_id;

                    if (!$sale_id) throw new Exception("Failed to create sale record.");

                    foreach ($_SESSION['sale_cart'] as $item) {
                        $prod = mysqli_fetch_assoc(mysqli_query($conn, "SELECT purchase_price, selling_price FROM products WHERE id=" . (int)$item['product_id']));
                        $selling_price = $prod['selling_price'] ?? $item['price'];
                        $purchase_price = $prod['purchase_price'] ?? 0;
                        $subtotal = $item['quantity'] * $selling_price;
                        $profit = ($selling_price - $purchase_price) * $item['quantity'];
                        $sd_stmt = $conn->prepare("INSERT INTO sale_details (sale_id, product_id, quantity, purchase_price, selling_price, subtotal, profit)
                            VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $sd_stmt->bind_param("iiidddd", $sale_id, $item['product_id'], $item['quantity'], $purchase_price, $selling_price, $subtotal, $profit);
                        $sd_stmt->execute();

                        $stock_stmt = $conn->prepare("UPDATE products SET current_stock = current_stock - ? WHERE id = ?");
                        $stock_stmt->bind_param("ii", $item['quantity'], $item['product_id']);
                        $stock_stmt->execute();
                    }

                    $insAmtCol = getPaymentAmountCol($conn, 'sale_payments');
                    $hasSaleExtra = columnExists($conn, 'sale_payments', 'change_amount');
                    if ($payment_method === 'Mixed') {
                        $change = max(0, $mixed_cash + $mixed_kbzpay - $grand_total);
                        if ($hasSaleExtra && columnExists($conn, 'sale_payments', 'cash_amount')) {
                            $pmt_stmt = $conn->prepare("INSERT INTO sale_payments (sale_id, payment_method, cash_amount, kbzpay_amount, $insAmtCol, change_amount, payment_status) VALUES (?, 'Mixed', ?, ?, ?, ?, 'Paid')");
                            $pmt_stmt->bind_param("idddd", $sale_id, $mixed_cash, $mixed_kbzpay, $total_paid, $change);
                        } else {
                            $pmt_stmt = $conn->prepare("INSERT INTO sale_payments (sale_id, payment_method, $insAmtCol) VALUES (?, 'Mixed', ?)");
                            $pmt_stmt->bind_param("id", $sale_id, $total_paid);
                        }
                        $pmt_stmt->execute();
                    } else {
                        $change = max(0, $total_paid - $grand_total);
                        if ($hasSaleExtra && columnExists($conn, 'sale_payments', 'cash_amount')) {
                            $cash = ($payment_method === 'Cash') ? $total_paid : 0;
                            $kbz = ($payment_method === 'KBZPay') ? $total_paid : 0;
                            $pmt_stmt = $conn->prepare("INSERT INTO sale_payments (sale_id, payment_method, cash_amount, kbzpay_amount, $insAmtCol, change_amount, payment_status) VALUES (?, ?, ?, ?, ?, ?, 'Paid')");
                            $pmt_stmt->bind_param("isiddd", $sale_id, $primary_method, $cash, $kbz, $total_paid, $change);
                        } else {
                            $pmt_stmt = $conn->prepare("INSERT INTO sale_payments (sale_id, payment_method, $insAmtCol) VALUES (?, ?, ?)");
                            $pmt_stmt->bind_param("isd", $sale_id, $primary_method, $total_paid);
                        }
                        $pmt_stmt->execute();
                    }

                    mysqli_commit($conn);
                    $_SESSION['last_sale_id'] = $sale_id;
                    $_SESSION['sale_cart'] = [];
                    header("Location: invoice.php?id=$sale_id");
                    exit;
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $error = "Sale failed. Please try again.";
                }
            }
        }
    } else {
        $error = "Cart is empty.";
    }
}

// Fetch products
$search = $_GET['search'] ?? '';
$category_filter = $_GET['category'] ?? '';

$product_query = "SELECT * FROM products WHERE status='Active'";
if ($search) {
    $safe_search = mysqli_real_escape_string($conn, $search);
    $product_query .= " AND (product_name LIKE '%$safe_search%' OR sku LIKE '%$safe_search%' OR barcode LIKE '%$safe_search%')";
}
if ($category_filter) {
    $product_query .= " AND category_id = '$category_filter'";
}
$product_query .= " ORDER BY product_name ASC";
$products = mysqli_query($conn, $product_query);

$categories = mysqli_query($conn, "SELECT * FROM categories WHERE status='Active' ORDER BY name");

$cart_subtotal = array_sum(array_column($_SESSION['sale_cart'] ?? [], 'total'));
$invoice_no = generateInvoiceNo($conn);
$page_title = "New Sale (POS)";
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Sale (POS) - <?= htmlspecialchars(getShopSettings($conn)['shop_name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include "../includes/theme-init.php"; ?>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
            gap: 12px;
        }

        .product-card {
            transition: all 0.2s;
            cursor: pointer;
        }

        .product-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .product-card.out-of-stock {
            opacity: 0.45;
            pointer-events: none;
        }

        .cart-item {
            transition: background 0.15s;
        }

        .cart-item:hover {
            background: #f9fafb;
        }

        .payment-method-btn:hover {
            transform: translateY(-1px);
        }

        .payment-input {
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .payment-input:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
            outline: none;
        }

        .search-glow:focus {
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
        }

        .qty-btn {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.15s;
            cursor: pointer;
        }

        .qty-btn:hover {
            background: #e5e7eb;
        }

        .qty-btn:active {
            transform: scale(0.95);
        }

        @keyframes cartBounce {

            0%,
            100% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.05);
            }
        }

        .cart-bounce {
            animation: cartBounce 0.3s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .slide-up {
            animation: slideUp 0.2s ease-out;
        }
    </style>
</head>

<body class="bg-gray-100 dark:bg-slate-900">
    <div class="flex h-screen overflow-hidden">
        <?php include "../includes/sidebar.php"; ?>

        <main class="flex-1 flex flex-col overflow-hidden">
            <!-- Top Bar -->
            <div class="bg-white border-b border-gray-200 px-6 py-3 flex items-center justify-between flex-shrink-0">
                <div class="flex items-center gap-4">
                    <h1 class="text-lg font-bold text-gray-900 dark:text-gray-100">New Sale</h1>
                    <span class="bg-indigo-100 text-indigo-700 px-3 py-1 rounded-full text-xs font-semibold"><?= $invoice_no ?></span>
                </div>
                <div class="flex items-center gap-3">
                    <div class="text-sm text-gray-500">
                        <span class="font-medium text-gray-700"><?= date('d M Y') ?></span>
                        <span class="mx-1">|</span>
                        <?= date('h:i A') ?>
                    </div>
                    <div class="w-8 h-8 bg-indigo-600 rounded-full flex items-center justify-center text-white font-bold text-xs">
                        <?= strtoupper(substr($_SESSION['name'] ?? 'U', 0, 1)) ?>
                    </div>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="bg-red-50 border-b border-red-200 text-red-700 px-6 py-3 text-sm font-medium flex items-center gap-2 flex-shrink-0">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- Main Content: Products + Cart -->
            <div class="flex-1 flex overflow-hidden">
                <!-- Left: Product Area -->
                <div class="flex-1 p-4 overflow-y-auto">
                    <!-- Search Bar -->
                    <div class="flex gap-2 mb-4">
                        <form method="GET" class="flex-1 flex gap-2">
                            <div class="relative flex-1">
                                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name, SKU, or barcode..." class="w-full border border-gray-300 rounded-xl pl-10 pr-4 py-2.5 text-sm focus:outline-none search-glow">
                            </div>
                            <select name="category" class="border border-gray-300 rounded-xl px-3 py-2.5 text-sm focus:outline-none bg-white" onchange="this.form.submit()">
                                <option value="">All Categories</option>
                                <?php while ($c = mysqli_fetch_assoc($categories)): ?>
                                    <option value="<?= $c['id'] ?>" <?= $category_filter == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                            <button class="bg-indigo-600 text-white px-5 py-2.5 rounded-xl hover:bg-indigo-700 text-sm font-medium transition">Search</button>
                            <a href="pos.php" class="border border-gray-300 px-4 py-2.5 rounded-xl text-sm font-medium hover:bg-gray-50 transition">Reset</a>
                        </form>
                    </div>

                    <!-- Product Grid -->
                    <div class="product-grid">
                        <?php if (mysqli_num_rows($products) > 0): while ($p = mysqli_fetch_assoc($products)):
                                $stock = $p['current_stock'] ?? 0;
                                $reorder = $p['reorder_level'] ?? 10;
                                $oos = $stock <= 0;
                                $low_stock = $stock <= $reorder && !$oos;
                        ?>
                                <div class="bg-white rounded-xl border border-gray-200 p-3 product-card slide-up <?= $oos ? 'out-of-stock' : '' ?>">
                                    <div class="h-26 bg-gradient-to-br from-gray-50 to-gray-100 rounded-lg mb-2 flex items-center justify-center overflow-hidden">
                                        <?php if ($p['image']): ?>
                                            <img src="../img/<?= htmlspecialchars($p['image']) ?>" alt="" class="h-full w-full object-cover rounded-lg" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">

                                        <?php else: ?>
                                            <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                            </svg>
                                        <?php endif; ?>
                                    </div>
                                    <h3 class="font-semibold text-xs text-gray-800 truncate" title="<?= htmlspecialchars($p['product_name']) ?>"><?= htmlspecialchars($p['product_name']) ?></h3>
                                    <p class="text-[11px] text-gray-400 mt-0.5">SKU: <?= htmlspecialchars($p['sku'] ?? 'N/A') ?></p>
                                    <p class="text-[11px] <?= $low_stock ? 'text-amber-500 font-medium' : ($oos ? 'text-red-500 font-medium' : 'text-gray-400') ?>">
                                        Stock: <?= $stock ?>
                                        <?= $oos ? ' (Out)' : ($low_stock ? ' (Low)' : '') ?>
                                    </p>
                                    <p class="text-indigo-600 font-bold text-sm mt-1"><?= number_format($p['selling_price']) ?> Ks</p>
                                    <form method="POST" class="mt-2 flex gap-1">
                                        <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                        <input type="number" name="quantity" value="1" min="1" max="<?= $stock ?>" class="border border-gray-300 rounded-lg w-14 text-center p-1.5 text-xs focus:outline-none focus:border-indigo-400">
                                        <button name="add_cart" class="bg-emerald-600 text-white px-3 py-1.5 rounded-lg text-xs font-medium hover:bg-emerald-700 flex-1 transition <?= $oos ? 'opacity-50' : '' ?>">+ Add</button>
                                    </form>
                                </div>
                            <?php endwhile;
                        else: ?>
                            <div class="col-span-full text-center py-16">
                                <div class="text-5xl mb-4">🔍</div>
                                <p class="text-gray-500 font-medium">No products found</p>
                                <p class="text-sm text-gray-400 mt-1">Try a different search term</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right: Cart Sidebar -->
                <div class="w-[380px] bg-white border-l border-gray-200 flex flex-col flex-shrink-0">
                    <!-- Cart Header -->
                    <div class="px-4 py-3 border-b border-gray-200 flex items-center justify-between">
                        <div>
                            <h2 class="font-bold text-gray-900 dark:text-gray-100">Current Order</h2>
                            <p class="text-xs text-gray-500"><?= count($_SESSION['sale_cart'] ?? []) ?> item(s) in cart</p>
                        </div>
                        <?php if (count($_SESSION['sale_cart'] ?? []) > 0): ?>
                            <a href="?clear_cart=1" class="text-xs text-red-500 hover:text-red-700 font-medium px-2 py-1 rounded-lg hover:bg-red-50 transition" onclick="return confirm('Clear entire cart?')">Clear All</a>
                        <?php endif; ?>
                    </div>

                    <!-- Cart Items -->
                    <div class="flex-1 overflow-y-auto px-4 py-2">
                        <?php if (count($_SESSION['sale_cart'] ?? []) > 0): ?>
                            <form method="POST" id="cartForm">
                                <?php foreach ($_SESSION['sale_cart'] as $key => $item): ?>
                                    <div class="cart-item rounded-lg p-3 mb-2 border border-gray-100 slide-up">
                                        <div class="flex justify-between items-start">
                                            <div class="flex-1 min-w-0">
                                                <p class="font-semibold text-sm text-gray-800 truncate"><?= htmlspecialchars($item['product_name']) ?></p>
                                                <p class="text-indigo-600 font-bold text-sm"><?= number_format($item['price']) ?> Ks</p>
                                            </div>
                                            <a href="?remove=<?= $key ?>" class="text-gray-300 hover:text-red-500 ml-2 transition p-1" title="Remove">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </a>
                                        </div>
                                        <div class="flex items-center justify-between mt-2">
                                            <div class="flex items-center gap-1">
                                                <button type="button" class="qty-btn bg-gray-100 text-gray-600 hover:bg-gray-200" onclick="changeQty(this, -1)">−</button>
                                                <input type="number" name="quantity[<?= $key ?>]" value="<?= $item['quantity'] ?>" min="1" data-price="<?= $item['price'] ?>" data-key="<?= $key ?>" class="cart-qty border border-gray-200 rounded-lg w-14 p-1 text-center text-sm focus:outline-none focus:border-indigo-400">
                                                <button type="button" class="qty-btn bg-gray-100 text-gray-600 hover:bg-gray-200" onclick="changeQty(this, 1)">+</button>
                                            </div>
                                            <span class="font-bold text-sm text-gray-800 item-total-<?= $key ?>"><?= number_format($item['total']) ?> Ks</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </form>
                        <?php else: ?>
                            <div class="flex flex-col items-center justify-center h-full text-gray-400 py-12">
                                <div class="text-5xl mb-3">🛒</div>
                                <p class="font-medium">Cart is empty</p>
                                <p class="text-xs mt-1">Add products to start a sale</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (count($_SESSION['sale_cart'] ?? []) > 0): ?>
                        <!-- Cart Summary -->
                        <div class="border-t border-gray-200 px-4 py-3 space-y-2">
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500">Subtotal</span>
                                <span class="font-semibold text-gray-800" id="cartSubtotal"><?= number_format($cart_subtotal) ?> Ks</span>
                            </div>
                            <div class="flex justify-between text-sm items-center">
                                <span class="text-gray-500">Discount (%)</span>
                                <div class="flex items-center gap-1">
                                    <input type="number" form="paymentForm" name="discount" id="discountInput" value="0" min="0" max="100" step="0.01" class="border border-gray-200 rounded-lg w-20 text-right p-1.5 text-sm focus:outline-none focus:border-indigo-400" oninput="updateTotals()">
                                    <span class="text-gray-400 text-xs">%</span>
                                </div>
                            </div>
                            <div class="border-t border-gray-100 pt-2 flex justify-between items-center">
                                <span class="font-bold text-gray-900 dark:text-gray-100">Total</span>
                                <span class="font-bold text-xl text-indigo-600" id="grandTotalDisplay"><?= number_format($cart_subtotal) ?> Ks</span>
                            </div>
                            <button onclick="showPaymentModal()" class="w-full bg-indigo-600 text-white py-3 rounded-xl hover:bg-indigo-700 font-bold text-sm flex items-center justify-center gap-2 transition mt-1">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z" />
                                </svg>
                                Checkout
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Payment Modal -->
    <div id="paymentModal" class="modal-overlay hidden">
        <div class="bg-white rounded-2xl w-full max-w-md p-6 slide-up max-h-90v">
            <div class="text-center mb-2">
                <div class="w-14 h-14 bg-indigo-100 rounded-full flex items-center justify-center mx-auto mb-3">
                    <svg class="w-7 h-7 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z" />
                    </svg>
                </div>
                <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100">Complete Payment</h2>
                <p class="text-sm text-gray-500 mt-1">Review and confirm the sale</p>
            </div>

            <form method="POST" id="paymentForm">
                <div class="space-y-2 ">
                    <div>
                        <label class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Invoice No</label>
                        <div class="bg-gray-50 px-4 py-2.5 rounded-xl text-sm font-semibold text-gray-700 mt-1 border border-gray-100"><?= $invoice_no ?></div>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Grand Total</label>
                        <div class="text-emerald-600 text-2xl font-bold mt-1" id="modalTotal"><?= number_format($cart_subtotal) ?> Ks</div>
                    </div>

                    <div class="border-t border-gray-100 pt-3 space-y-3">
                        <label class="text-xs font-semibold text-gray-500 uppercase tracking-wider block">Payment Method</label>

                        <div class="flex gap-2">
                            <button type="button" onclick="selectPayment('Cash')" id="btnCash" class="payment-method-btn flex-1 py-2.5 rounded-xl text-sm font-semibold border-2 border-emerald-500 bg-emerald-50 text-emerald-700 transition">Cash</button>
                            <button type="button" onclick="selectPayment('KBZPay')" id="btnKBZPay" class="payment-method-btn flex-1 py-2.5 rounded-xl text-sm font-semibold border-2 border-gray-200 bg-white text-gray-600 transition">KBZPay</button>
                            <button type="button" onclick="selectPayment('Mixed')" id="btnMixed" class="payment-method-btn flex-1 py-2.5 rounded-xl text-sm font-semibold border-2 border-gray-200 bg-white text-gray-600 transition">Mixed</button>
                        </div>
                        <input type="hidden" name="payment_method" id="paymentMethod" value="Cash">

                        <div id="cashSection">
                            <div class="flex items-center gap-3">
                                <div class="w-16 text-sm font-medium text-gray-700">Cash</div>
                                <input type="number" name="payment_cash" id="paymentCash" value="0" min="0" step="0.01" class="payment-input flex-1 border border-gray-200 rounded-xl p-2.5 text-sm" oninput="calculatePayments()">
                                <span class="text-xs text-gray-400 w-8 text-right">Ks</span>
                            </div>
                        </div>

                        <div id="kbzSection" class="hidden">
                            <div class="flex items-center gap-3">
                                <div class="w-16 text-sm font-medium text-gray-700">KBZPay</div>
                                <input type="number" name="payment_kbzpay" id="paymentKBZPay" value="0" min="0" step="0.01" class="payment-input flex-1 border border-gray-200 rounded-xl p-2.5 text-sm" oninput="calculatePayments()">
                                <span class="text-xs text-gray-400 w-8 text-right">Ks</span>
                            </div>
                        </div>

                        <div id="mixedSection" class="hidden space-y-2">
                            <div class="flex items-center gap-3">
                                <div class="w-16 text-sm font-medium text-gray-700">Cash</div>
                                <input type="number" name="mixed_cash" id="mixedCash" value="0" min="0" step="0.01" class="payment-input flex-1 border border-gray-200 rounded-xl p-2.5 text-sm" oninput="calculatePayments()">
                                <span class="text-xs text-gray-400 w-8 text-right">Ks</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="w-16 text-sm font-medium text-gray-700">KBZPay</div>
                                <input type="number" name="mixed_kbzpay" id="mixedKBZPay" value="0" min="0" step="0.01" class="payment-input flex-1 border border-gray-200 rounded-xl p-2.5 text-sm" oninput="calculatePayments()">
                                <span class="text-xs text-gray-400 w-8 text-right">Ks</span>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-gray-100 pt-4 space-y-2">
                        <div class="flex justify-between text-sm">
                            <span class="font-semibold text-gray-600">Total Paid</span>
                            <span class="font-bold text-indigo-600" id="totalPaidDisplay">0 Ks</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="font-semibold text-gray-600">Balance</span>
                            <span class="font-bold" id="balanceDisplay">0 Ks</span>
                        </div>
                        <div id="paymentError" class="text-red-600 text-xs font-medium hidden"></div>
                    </div>
                </div>

                <div class="flex gap-3 mt-6">
                    <button type="button" onclick="hidePaymentModal()" class="flex-1 py-3 border border-gray-200 rounded-xl text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">Cancel</button>
                    <button type="submit" name="complete_sale" id="completeSaleBtn" class="flex-1 py-3 bg-emerald-600 text-white rounded-xl text-sm font-bold hover:bg-emerald-700 transition disabled:opacity-50 disabled:cursor-not-allowed">Confirm Sale</button>
                </div>
            </form>
        </div>
    </div>

    <?php include "../includes/toast.php"; ?>
    <?php include "../includes/footer.php"; ?>

    <script>
        function calculateSubtotal() {
            let total = 0;
            document.querySelectorAll('.cart-qty').forEach(function(input) {
                const qty = parseInt(input.value) || 0;
                const price = parseFloat(input.dataset.price) || 0;
                total += qty * price;
            });
            return total;
        }

        function updateItemTotal(input) {
            const key = input.dataset.key;
            const qty = parseInt(input.value) || 0;
            const price = parseFloat(input.dataset.price) || 0;
            const el = document.querySelector('.item-total-' + key);
            if (el) el.textContent = (qty * price).toLocaleString() + ' Ks';
        }

        function updateTotals() {
            const subtotal = calculateSubtotal();
            document.getElementById('cartSubtotal').textContent = subtotal.toLocaleString() + ' Ks';
            const discountPercent = parseFloat(document.getElementById('discountInput').value) || 0;
            const discountAmount = subtotal * (discountPercent / 100);
            const total = Math.max(0, subtotal - discountAmount);
            document.getElementById('grandTotalDisplay').textContent = total.toLocaleString() + ' Ks';
            document.getElementById('modalTotal').textContent = total.toLocaleString() + ' Ks';
            if (!document.getElementById('paymentModal').classList.contains('hidden')) {
                calculatePayments();
            }
        }

        function changeQty(btn, delta) {
            const input = btn.parentElement.querySelector('.cart-qty');
            let val = parseInt(input.value) || 1;
            val = Math.max(1, val + delta);
            input.value = val;
            autoUpdateCart(input);
        }

        function autoUpdateCart(input) {
            let val = parseInt(input.value) || 1;
            if (val < 1) {
                val = 1;
                input.value = 1;
            }
            updateItemTotal(input);
            updateTotals();
            const formData = new FormData();
            formData.append('ajax_update_qty', '1');
            formData.append('item_key', input.dataset.key);
            formData.append('quantity', val);
            fetch('pos.php', {
                method: 'POST',
                body: formData
            });
        }

        function showPaymentModal() {
            document.getElementById('paymentModal').classList.remove('hidden');
            selectPayment('Cash');
            calculatePayments();
        }

        function hidePaymentModal() {
            document.getElementById('paymentModal').classList.add('hidden');
        }

        function selectPayment(method) {
            document.getElementById('paymentMethod').value = method;
            document.querySelectorAll('.payment-method-btn').forEach(function(btn) {
                btn.className = 'payment-method-btn flex-1 py-2.5 rounded-xl text-sm font-semibold border-2 border-gray-200 bg-white text-gray-600 transition';
            });
            var activeBtn = document.getElementById('btn' + method);
            if (activeBtn) {
                activeBtn.className = 'payment-method-btn flex-1 py-2.5 rounded-xl text-sm font-semibold border-2 border-emerald-500 bg-emerald-50 text-emerald-700 transition';
            }
            document.getElementById('cashSection').classList.toggle('hidden', method !== 'Cash');
            document.getElementById('kbzSection').classList.toggle('hidden', method !== 'KBZPay');
            document.getElementById('mixedSection').classList.toggle('hidden', method !== 'Mixed');

            if (method === 'Cash') {
                var total = getTotal();
                document.getElementById('paymentCash').value = total;
            } else if (method === 'KBZPay') {
                var total = getTotal();
                document.getElementById('paymentKBZPay').value = total;
            } else if (method === 'Mixed') {
                document.getElementById('mixedCash').value = '0';
                document.getElementById('mixedKBZPay').value = '0';
            }
            calculatePayments();
        }

        function getTotal() {
            const subtotal = calculateSubtotal();
            const discountPercent = parseFloat(document.getElementById('discountInput').value) || 0;
            const discountAmount = subtotal * (discountPercent / 100);
            return Math.max(0, subtotal - discountAmount);
        }

        function calculatePayments() {
            const total = getTotal();
            const method = document.getElementById('paymentMethod').value;
            var totalPaid = 0;

            if (method === 'Cash') {
                totalPaid = parseFloat(document.getElementById('paymentCash').value) || 0;
            } else if (method === 'KBZPay') {
                totalPaid = parseFloat(document.getElementById('paymentKBZPay').value) || 0;
            } else if (method === 'Mixed') {
                totalPaid = (parseFloat(document.getElementById('mixedCash').value) || 0) +
                    (parseFloat(document.getElementById('mixedKBZPay').value) || 0);
            }

            document.getElementById('totalPaidDisplay').textContent = totalPaid.toLocaleString() + ' Ks';

            const balance = totalPaid - total;
            const balanceEl = document.getElementById('balanceDisplay');
            const errorEl = document.getElementById('paymentError');
            const btn = document.getElementById('completeSaleBtn');

            balanceEl.textContent = Math.abs(balance).toLocaleString() + ' Ks';

            if (Math.abs(balance) < 0.01) {
                balanceEl.className = 'font-bold text-emerald-600';
                errorEl.classList.add('hidden');
                btn.disabled = false;
            } else if (balance < 0) {
                balanceEl.className = 'font-bold text-red-600';
                errorEl.textContent = 'Payment amount is not enough.';
                errorEl.classList.remove('hidden');
                btn.disabled = true;
            } else if (method === 'Cash') {
                balanceEl.className = 'font-bold text-amber-600';
                errorEl.textContent = 'Payment amount exceeds total (change: ' + balance.toLocaleString() + ' Ks)';
                errorEl.classList.remove('hidden');
                btn.disabled = false;
            } else {
                balanceEl.className = 'font-bold text-red-600';
                errorEl.textContent = 'Payment amount exceeds total.';
                errorEl.classList.remove('hidden');
                btn.disabled = true;
            }
        }

        function setupCartQtyListeners() {
            document.querySelectorAll('.cart-qty').forEach(function(input) {
                input.addEventListener('input', function() {
                    let val = parseInt(this.value) || 1;
                    if (val < 1) {
                        val = 1;
                        this.value = 1;
                    }
                    autoUpdateCart(this);
                });
                input.addEventListener('change', function() {
                    let val = parseInt(this.value) || 1;
                    if (val < 1) {
                        val = 1;
                        this.value = 1;
                    }
                    autoUpdateCart(this);
                });
                input.addEventListener('wheel', function(e) {
                    e.preventDefault();
                    const delta = e.deltaY > 0 ? -1 : 1;
                    let val = parseInt(this.value) || 1;
                    val = Math.max(1, val + delta);
                    this.value = val;
                    autoUpdateCart(this);
                });
            });
        }

        document.addEventListener('DOMContentLoaded', setupCartQtyListeners);

        // Keyboard shortcut: F2 to focus search
        document.addEventListener('keydown', function(e) {
            if (e.key === 'F2') {
                e.preventDefault();
                document.querySelector('input[name="search"]').focus();
            }
            if (e.key === 'Escape') {
                hidePaymentModal();
            }
        });

        <?php if ($error): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showPaymentModal();
            });
        <?php endif; ?>
    </script>
</body>

</html>