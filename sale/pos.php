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

// ============ AJAX HANDLERS ============
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if (isset($_POST['add_cart']) || isset($_GET['remove']) || isset($_GET['clear_cart']) || isset($_POST['ajax_update_qty'])) {
    
    // Add to cart
    if (isset($_POST['add_cart'])) {
        $product_id = (int)$_POST['product_id'];
        $qty = max(1, (int)($_POST['quantity'] ?? 1));

        $p = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, product_name, selling_price, purchase_price, current_stock AS stock FROM products WHERE id='$product_id' AND status='Active'"));

        if ($p) {
            $found = false;
            foreach ($_SESSION['sale_cart'] as &$item) {
                if ($item['product_id'] == $product_id) {
                    if ($item['quantity'] + $qty <= $p['stock']) {
                        $item['quantity'] += $qty;
                    } else {
                        $item['quantity'] = $p['stock'];
                    }
                    $item['total'] = $item['quantity'] * $item['price'];
                    $item['stock'] = $p['stock'];
                    $found = true;
                    break;
                }
            }
            unset($item);

            if (!$found) {
                if ($p['stock'] >= $qty) {
                    $_SESSION['sale_cart'][] = [
                        'product_id' => $p['id'],
                        'product_name' => $p['product_name'],
                        'price' => $p['selling_price'],
                        'purchase_price' => $p['purchase_price'],
                        'quantity' => $qty,
                        'total' => $qty * $p['selling_price'],
                        'stock' => $p['stock']
                    ];
                }
            }
        }
    }

    // Update Qty
    if (isset($_POST['ajax_update_qty'])) {
        $key = (int)$_POST['item_key'];
        $qty = max(1, (int)$_POST['quantity']);
        if (isset($_SESSION['sale_cart'][$key])) {
            $stock = $_SESSION['sale_cart'][$key]['stock'] ?? 999999;
            if ($qty > $stock) {
                $qty = $stock;
            }
            $_SESSION['sale_cart'][$key]['quantity'] = $qty;
            $_SESSION['sale_cart'][$key]['total'] = $qty * $_SESSION['sale_cart'][$key]['price'];
        }
    }

    // Remove item
    if (isset($_GET['remove'])) {
        $key = (int)$_GET['remove'];
        if (isset($_SESSION['sale_cart'][$key])) {
            unset($_SESSION['sale_cart'][$key]);
            $_SESSION['sale_cart'] = array_values($_SESSION['sale_cart']);
        }
    }

    // Clear cart
    if (isset($_GET['clear_cart'])) {
        unset($_SESSION['sale_cart']);
    }

    if ($is_ajax && !isset($_POST['ajax_update_qty'])) {
        // If it was a cart modification and it's AJAX, we just return the updated cart HTML.
        // We will render it at the bottom.
        $render_cart_only = true;
    } elseif ($is_ajax && isset($_POST['ajax_update_qty'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
}

// Check for Product Grid AJAX Request
if ($is_ajax && isset($_GET['action']) && $_GET['action'] == 'fetch_products') {
    $render_products_only = true;
}

// Fetch products logic
$search = $_GET['search'] ?? '';
$category_filter = $_GET['category'] ?? '';

$product_query = "SELECT * FROM products WHERE status='Active'";
if ($search) {
    $safe_search = mysqli_real_escape_string($conn, $search);
    $product_query .= " AND (product_name LIKE '%$safe_search%' OR sku LIKE '%$safe_search%' OR barcode LIKE '%$safe_search%')";
}
if ($category_filter) {
    $product_query .= " AND category_id = '" . mysqli_real_escape_string($conn, $category_filter) . "'";
}
$product_query .= " ORDER BY product_name ASC";
$products = mysqli_query($conn, $product_query);

$cart_subtotal = array_sum(array_column($_SESSION['sale_cart'] ?? [], 'total'));
$invoice_no = generateInvoiceNo($conn);

// ============ COMPLETE SALE ============
$error = '';
if (isset($_POST['complete_sale'])) {
    if (count($_SESSION['sale_cart']) > 0) {
        $insufficient = [];
        foreach ($_SESSION['sale_cart'] as $item) {
            $prod = mysqli_fetch_assoc(mysqli_query($conn, "SELECT product_name, current_stock FROM products WHERE id='{$item['product_id']}'"));
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

            $total_paid = $payment_method === 'Cash' ? $payment_cash : ($payment_method === 'KBZPay' ? $payment_kbzpay : $mixed_cash + $mixed_kbzpay);

            if ($total_paid < $grand_total - 0.01) {
                $error = "Insufficient payment.";
            } else {
                $user_id = $_SESSION['user_id'] ?? null;
                $primary_method = $payment_method === 'Mixed' ? 'Mixed' : $payment_method;

                mysqli_begin_transaction($conn);
                try {
                    $subtotal = array_sum(array_column($_SESSION['sale_cart'], 'total'));
                    $stmt = $conn->prepare("INSERT INTO sales (invoice_no, user_id, subtotal, total_amount, discount, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                    $stmt->bind_param("siddd", $invoice_no, $user_id, $subtotal, $grand_total, $discount);
                    $stmt->execute();
                    $sale_id = $conn->insert_id;

                    if (!$sale_id) throw new Exception("Failed to create sale record.");

                    foreach ($_SESSION['sale_cart'] as $item) {
                        $prod = mysqli_fetch_assoc(mysqli_query($conn, "SELECT purchase_price, selling_price FROM products WHERE id=" . (int)$item['product_id']));
                        $selling_price = $prod['selling_price'] ?? $item['price'];
                        $purchase_price = $prod['purchase_price'] ?? 0;
                        $subtotal_item = $item['quantity'] * $selling_price;
                        $profit = ($selling_price - $purchase_price) * $item['quantity'];
                        
                        $sd_stmt = $conn->prepare("INSERT INTO sale_details (sale_id, product_id, quantity, purchase_price, selling_price, subtotal, profit) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $sd_stmt->bind_param("iiidddd", $sale_id, $item['product_id'], $item['quantity'], $purchase_price, $selling_price, $subtotal_item, $profit);
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

// --- PARTIAL RENDERERS FOR AJAX ---
if (isset($render_products_only) && $render_products_only) {
    if (mysqli_num_rows($products) > 0) {
        while ($p = mysqli_fetch_assoc($products)) {
            $stock = $p['current_stock'] ?? 0;
            $reorder = $p['reorder_level'] ?? 10;
            $oos = $stock <= 0;
            $low_stock = $stock <= $reorder && !$oos;
            ?>
            <div class="bg-white rounded-xl border border-gray-200 p-3 product-card <?= $oos ? 'out-of-stock' : '' ?> h-full flex flex-col">
                <div class="h-32 w-full bg-gradient-to-br from-gray-50 to-gray-100 rounded-lg mb-3 flex items-center justify-center overflow-hidden shrink-0">
                    <?php if ($p['image']): ?>
                        <img src="../img/<?= htmlspecialchars($p['image']) ?>" alt="" class="h-full w-full object-contain p-2" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                    <?php else: ?>
                        <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" /></svg>
                    <?php endif; ?>
                </div>
                <div class="flex-1 flex flex-col">
                    <h3 class="font-semibold text-xs text-gray-800 line-clamp-2 min-h-[2.5rem]" title="<?= htmlspecialchars($p['product_name']) ?>"><?= htmlspecialchars($p['product_name']) ?></h3>
                    <p class="text-[11px] text-gray-400 mt-1">SKU: <?= htmlspecialchars($p['sku'] ?? 'N/A') ?></p>
                    <p class="text-[11px] <?= $low_stock ? 'text-amber-500 font-medium' : ($oos ? 'text-red-500 font-medium' : 'text-gray-400') ?> mt-0.5">
                        Stock: <?= $stock ?> <?= $oos ? ' (Out)' : ($low_stock ? ' (Low)' : '') ?>
                    </p>
                    <p class="text-indigo-600 font-bold text-sm mt-1.5"><?= number_format($p['selling_price']) ?> Ks</p>
                </div>
                <form class="mt-auto pt-3 flex gap-1 w-full add-cart-form" onsubmit="handleAddToCart(event, this)">
                    <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                    <input type="number" name="quantity" value="1" min="1" max="<?= $stock ?>" class="border border-gray-300 rounded-lg w-14 text-center p-1.5 text-xs focus:outline-none focus:border-indigo-400 shrink-0">
                    <button type="submit" name="add_cart" class="bg-emerald-600 text-white px-3 py-1.5 rounded-lg text-xs font-medium hover:bg-emerald-700 flex-1 transition <?= $oos ? 'opacity-50' : '' ?> shrink-0 whitespace-nowrap" <?= $oos ? 'disabled' : '' ?>>+ Add</button>
                </form>
            </div>
            <?php
        }
    } else {
        ?>
        <div class="col-span-full text-center py-16">
            <div class="text-5xl mb-4">🔍</div>
            <p class="text-gray-500 font-medium">No products found</p>
            <p class="text-sm text-gray-400 mt-1">Try a different search term</p>
        </div>
        <?php
    }
    exit;
}

if (isset($render_cart_only) && $render_cart_only) {
    include 'pos_cart_partial.php';
    exit;
}
// --- END PARTIAL RENDERERS ---

$categories = mysqli_query($conn, "SELECT * FROM categories WHERE status='Active' ORDER BY name");
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
        /* Preloader to hide FOUC and give feedback while Tailwind CDN loads */
        #pos-preloader {
            position: fixed; top: 0; left: 0; width: 100%; height: 100vh;
            background-color: #f3f4f6; z-index: 99999;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            transition: opacity 0.3s ease-out;
        }
        .pos-spinner {
            width: 44px; height: 44px;
            border: 4px solid #e5e7eb; border-top-color: #4f46e5;
            border-radius: 50%;
            animation: pos-spin 1s linear infinite;
        }
        @keyframes pos-spin { to { transform: rotate(360deg); } }
        /* Dark mode support for preloader */
        @media (prefers-color-scheme: dark) {
            #pos-preloader { background-color: #0f172a; }
            .pos-spinner { border-color: #334155; border-top-color: #818cf8; }
        }
        
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
            gap: 12px;
        }
        .product-card {
            transition: all 0.2s;
        }
        .product-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        .product-card.out-of-stock {
            opacity: 0.6;
        }
        .cart-item:hover { background: #f9fafb; }
        .payment-method-btn:hover { transform: translateY(-1px); }
        .payment-input:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
            outline: none;
        }
        .search-glow:focus { box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2); }
        
        /* Stable Independent Scrolling */
        .pos-container {
            height: calc(100vh - 64px); /* Assuming topbar is ~64px */
        }
        .product-area {
            overflow-y: auto;
            height: 100%;
        }
        .cart-area {
            overflow-y: auto;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .qty-btn {
            width: 32px; height: 32px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 8px; font-weight: 600; font-size: 14px;
            transition: all 0.15s; cursor: pointer;
        }
        .qty-btn:hover { background: #e5e7eb; }
        .qty-btn:active { transform: scale(0.95); }
        .slide-up { animation: slideUp 0.2s ease-out; }
        @keyframes slideUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
    <script>
        // Preloader script: wait for Tailwind CDN, then hide preloader
        document.addEventListener('DOMContentLoaded', function() {
            const preloader = document.getElementById('pos-preloader');
            const hidePreloader = () => {
                if(preloader) {
                    preloader.style.opacity = '0';
                    setTimeout(() => preloader.remove(), 300);
                }
            };
            if (document.getElementById('tailwind-play-cdn')) {
                hidePreloader();
            } else {
                const observer = new MutationObserver(function(mutations, obs) {
                    if (document.getElementById('tailwind-play-cdn')) {
                        hidePreloader();
                        obs.disconnect();
                    }
                });
                observer.observe(document.head, { childList: true });
                setTimeout(hidePreloader, 3000); // 3s fallback timeout
            }
        });
    </script>
</head>
<body class="bg-[#eaf4fc] dark:bg-slate-900 overflow-hidden">
    <!-- Preloader Overlay -->
    <div id="pos-preloader">
        <div class="pos-spinner"></div>
        <div style="margin-top: 16px; font-family: sans-serif; font-size: 14px; color: #6b7280; font-weight: 600;">Loading POS...</div>
    </div>

    <div class="flex h-screen overflow-hidden">
        <?php include "../includes/sidebar.php"; ?>

        <main class="flex-1 flex flex-col h-screen">
            <!-- Top Bar (Fixed) -->
            <div class="bg-white border-b border-gray-200 px-6 py-3 flex items-center justify-between shrink-0 h-16">
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
                <div class="bg-red-50 border-b border-red-200 text-red-700 px-6 py-3 text-sm font-medium flex items-center gap-2 shrink-0">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- Main POS Layout (Independent Scrolling Areas) -->
            <div class="flex-1 flex flex-col lg:flex-row pos-container">
                
                <!-- Left: Product Area -->
                <div class="flex-1 p-4 product-area bg-[#eaf4fc] dark:bg-slate-900">
                    <!-- Search Bar (Sticky within area) -->
                    <div class="sticky top-0 z-10 bg-[#eaf4fc] dark:bg-slate-900 pb-4 mb-2">
                        <form id="searchForm" onsubmit="handleSearch(event)" class="flex gap-2 flex-wrap">
                            <div class="relative flex-1">
                                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                                <input type="text" name="search" id="searchInput" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name, SKU, or barcode..." class="w-full border border-gray-300 rounded-xl pl-10 pr-4 py-2.5 text-sm focus:outline-none search-glow" onkeyup="debounceSearch()">
                            </div>
                            <select name="category" id="categorySelect" class="border border-gray-300 rounded-xl px-3 py-2.5 text-sm focus:outline-none bg-white" onchange="handleSearch(event)">
                                <option value="">All Categories</option>
                                <?php while ($c = mysqli_fetch_assoc($categories)): ?>
                                    <option value="<?= $c['id'] ?>" <?= $category_filter == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                            <button type="submit" class="bg-indigo-600 text-white px-5 py-2.5 rounded-xl hover:bg-indigo-700 text-sm font-medium transition">Search</button>
                            <button type="button" onclick="resetSearch()" class="border border-gray-300 bg-white px-4 py-2.5 rounded-xl text-sm font-medium hover:bg-gray-50 transition">Reset</button>
                        </form>
                    </div>

                    <!-- Product Grid (AJAX updated) -->
                    <div class="product-grid" id="productGridContainer">
                        <?php 
                        // Simulate AJAX call to render initial products
                        $render_products_only = true;
                        ob_start();
                        if (mysqli_num_rows($products) > 0) {
                            while ($p = mysqli_fetch_assoc($products)) {
                                $stock = $p['current_stock'] ?? 0;
                                $reorder = $p['reorder_level'] ?? 10;
                                $oos = $stock <= 0;
                                $low_stock = $stock <= $reorder && !$oos;
                                ?>
                                <div class="bg-white rounded-xl border border-gray-200 p-3 product-card <?= $oos ? 'out-of-stock' : '' ?> h-full flex flex-col">
                                    <div class="h-32 w-full bg-gradient-to-br from-gray-50 to-gray-100 rounded-lg mb-3 flex items-center justify-center overflow-hidden shrink-0">
                                        <?php if ($p['image']): ?>
                                            <img src="../img/<?= htmlspecialchars($p['image']) ?>" alt="" class="h-full w-full object-contain p-2" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                        <?php else: ?>
                                            <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" /></svg>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex-1 flex flex-col">
                                        <h3 class="font-semibold text-xs text-gray-800 line-clamp-2 min-h-[2.5rem]" title="<?= htmlspecialchars($p['product_name']) ?>"><?= htmlspecialchars($p['product_name']) ?></h3>
                                        <p class="text-[11px] text-gray-400 mt-1">SKU: <?= htmlspecialchars($p['sku'] ?? 'N/A') ?></p>
                                        <p class="text-[11px] <?= $low_stock ? 'text-amber-500 font-medium' : ($oos ? 'text-red-500 font-medium' : 'text-gray-400') ?> mt-0.5">
                                            Stock: <?= $stock ?> <?= $oos ? ' (Out)' : ($low_stock ? ' (Low)' : '') ?>
                                        </p>
                                        <p class="text-indigo-600 font-bold text-sm mt-1.5"><?= number_format($p['selling_price']) ?> Ks</p>
                                    </div>
                                    <form class="mt-auto pt-3 flex gap-1 w-full add-cart-form" onsubmit="handleAddToCart(event, this)">
                                        <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                        <input type="number" name="quantity" value="1" min="1" max="<?= $stock ?>" class="border border-gray-300 rounded-lg w-14 text-center p-1.5 text-xs focus:outline-none focus:border-indigo-400 shrink-0">
                                        <button type="submit" name="add_cart" class="bg-emerald-600 text-white px-3 py-1.5 rounded-lg text-xs font-medium hover:bg-emerald-700 flex-1 transition <?= $oos ? 'opacity-50' : '' ?> shrink-0 whitespace-nowrap" <?= $oos ? 'disabled' : '' ?>>+ Add</button>
                                    </form>
                                </div>
                                <?php
                            }
                        } else {
                            ?>
                            <div class="col-span-full text-center py-16">
                                <div class="text-5xl mb-4">🔍</div>
                                <p class="text-gray-500 font-medium">No products found</p>
                                <p class="text-sm text-gray-400 mt-1">Try a different search term</p>
                            </div>
                            <?php
                        }
                        echo ob_get_clean();
                        ?>
                    </div>
                </div>

                <!-- Right: Cart Sidebar -->
                <div id="cartSidebarContainer" class="w-full lg:w-[380px] bg-white border-l border-gray-200 cart-area">
                    <?php include 'pos_cart_partial.php'; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Payment Modal -->
    <div id="paymentModal" class="modal-overlay hidden">
        <div class="bg-white rounded-2xl w-full max-w-md p-6 slide-up max-h-90v overflow-y-auto mx-auto mt-10">
            <div class="text-center mb-2">
                <div class="w-14 h-14 bg-indigo-100 rounded-full flex items-center justify-center mx-auto mb-3">
                    <svg class="w-7 h-7 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z" /></svg>
                </div>
                <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100">Complete Payment</h2>
                <p class="text-sm text-gray-500 mt-1">Review and confirm the sale</p>
            </div>

            <form method="POST" id="paymentForm">
                <div class="space-y-2">
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

                        <div class="min-h-[105px]">
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
                    </div>

                    <div class="border-t border-gray-100 pt-4 space-y-2">
                        <div class="flex justify-between text-sm">
                            <span class="font-semibold text-gray-600">Total Paid</span>
                            <span class="font-bold text-indigo-600" id="totalPaidDisplay">0 Ks</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="font-semibold text-gray-600">Change Amount</span>
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
    <script>
        let searchTimeout;
        
        function debounceSearch() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                handleSearch(new Event('submit'));
            }, 300);
        }

        function resetSearch() {
            document.getElementById('searchInput').value = '';
            document.getElementById('categorySelect').value = '';
            handleSearch(new Event('submit'));
        }

        function handleSearch(e) {
            e.preventDefault();
            const search = document.getElementById('searchInput').value;
            const category = document.getElementById('categorySelect').value;
            
            // Add a subtle opacity to grid while loading
            const grid = document.getElementById('productGridContainer');
            grid.style.opacity = '0.5';

            fetch(`pos.php?action=fetch_products&search=${encodeURIComponent(search)}&category=${encodeURIComponent(category)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => res.text())
            .then(html => {
                grid.innerHTML = html;
                grid.style.opacity = '1';
            });
        }

        function handleAddToCart(e, form) {
            e.preventDefault();
            const formData = new FormData(form);
            formData.append('add_cart', '1');
            
            fetch('pos.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => res.text())
            .then(html => {
                document.getElementById('cartSidebarContainer').innerHTML = html;
                updateTotals();
            });
        }

        function handleRemoveFromCart(e, url) {
            e.preventDefault();
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(res => res.text())
            .then(html => {
                document.getElementById('cartSidebarContainer').innerHTML = html;
                updateTotals();
            });
        }

        function changeQty(btn, delta) {
            const input = btn.parentElement.querySelector('.cart-qty');
            let val = parseInt(input.value) || 1;
            const max = parseInt(input.getAttribute('max')) || 999999;
            
            if (val + delta > max) {
                alert("Out of stock! Maximum available is " + max);
                return;
            }
            
            val = Math.max(1, Math.min(max, val + delta));
            input.value = val;
            autoUpdateCart(input);
        }

        function autoUpdateCart(input) {
            let val = parseInt(input.value) || 1;
            const max = parseInt(input.getAttribute('max')) || 999999;
            
            if (val < 1) { val = 1; input.value = 1; }
            if (val > max) { 
                val = max; 
                input.value = max; 
                alert("Out of stock! Maximum available is " + max);
            }
            
            // Optimistic UI update
            const price = parseFloat(input.dataset.price) || 0;
            const key = input.dataset.key;
            const el = document.querySelector('.item-total-' + key);
            if (el) el.textContent = (val * price).toLocaleString() + ' Ks';
            updateTotals();

            // Background server sync
            const formData = new FormData();
            formData.append('ajax_update_qty', '1');
            formData.append('item_key', key);
            formData.append('quantity', val);
            
            fetch('pos.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
        }

        function calculateSubtotal() {
            let total = 0;
            document.querySelectorAll('.cart-qty').forEach(function(input) {
                const qty = parseInt(input.value) || 0;
                const price = parseFloat(input.dataset.price) || 0;
                total += qty * price;
            });
            return total;
        }

        function updateTotals() {
            const subtotal = calculateSubtotal();
            const subtotalEl = document.getElementById('cartSubtotal');
            if(subtotalEl) subtotalEl.textContent = subtotal.toLocaleString() + ' Ks';
            
            const discountInput = document.getElementById('discountInput');
            const discountPercent = discountInput ? (parseFloat(discountInput.value) || 0) : 0;
            const discountAmount = subtotal * (discountPercent / 100);
            const total = Math.max(0, subtotal - discountAmount);
            
            const grandTotalEl = document.getElementById('grandTotalDisplay');
            if(grandTotalEl) grandTotalEl.textContent = total.toLocaleString() + ' Ks';
            
            const modalTotalEl = document.getElementById('modalTotal');
            if(modalTotalEl) modalTotalEl.textContent = total.toLocaleString() + ' Ks';

            if (!document.getElementById('paymentModal').classList.contains('hidden')) {
                calculatePayments();
            }
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
                document.getElementById('paymentCash').value = getTotal();
            } else if (method === 'KBZPay') {
                document.getElementById('paymentKBZPay').value = getTotal();
            } else if (method === 'Mixed') {
                document.getElementById('mixedCash').value = '0';
                document.getElementById('mixedKBZPay').value = '0';
            }
            calculatePayments();
        }

        function getTotal() {
            const subtotal = calculateSubtotal();
            const discountPercent = parseFloat(document.getElementById('discountInput').value) || 0;
            return Math.max(0, subtotal - (subtotal * (discountPercent / 100)));
        }

        function calculatePayments() {
            const total = getTotal();
            const method = document.getElementById('paymentMethod').value;
            var totalPaid = 0;

            if (method === 'Cash') totalPaid = parseFloat(document.getElementById('paymentCash').value) || 0;
            else if (method === 'KBZPay') totalPaid = parseFloat(document.getElementById('paymentKBZPay').value) || 0;
            else if (method === 'Mixed') totalPaid = (parseFloat(document.getElementById('mixedCash').value) || 0) + (parseFloat(document.getElementById('mixedKBZPay').value) || 0);

            document.getElementById('totalPaidDisplay').textContent = totalPaid.toLocaleString() + ' Ks';

            const balance = totalPaid - total;
            const balanceEl = document.getElementById('balanceDisplay');
            const errorEl = document.getElementById('paymentError');
            const btn = document.getElementById('completeSaleBtn');

            if (balance < 0) {
                balanceEl.textContent = '0 Ks';
                balanceEl.className = 'font-bold text-red-600';
                errorEl.textContent = 'Insufficient payment.';
                errorEl.classList.remove('hidden');
                btn.disabled = true;
            } else if (Math.abs(balance) < 0.01) {
                balanceEl.textContent = '0 Ks';
                balanceEl.className = 'font-bold text-emerald-600';
                errorEl.classList.add('hidden');
                btn.disabled = false;
            } else {
                balanceEl.textContent = balance.toLocaleString() + ' Ks';
                balanceEl.className = 'font-bold text-amber-600';
                errorEl.classList.add('hidden');
                btn.disabled = false;
            }
        }

        // Global listeners for robust cart operations
        document.addEventListener('input', function(e) {
            if (e.target.classList.contains('cart-qty')) {
                let val = parseInt(e.target.value) || 1;
                if (val < 1) e.target.value = 1;
                autoUpdateCart(e.target);
            }
        });

        // F2 to search, Escape to close modal
        document.addEventListener('keydown', function(e) {
            if (e.key === 'F2') {
                e.preventDefault();
                document.getElementById('searchInput').focus();
            }
            if (e.key === 'Escape') hidePaymentModal();
        });

        <?php if ($error): ?>
            document.addEventListener('DOMContentLoaded', showPaymentModal);
        <?php endif; ?>
    </script>
</body>
</html>