<?php
include "../includes/auth_check.php";
$action = $_GET['action'] ?? 'list';
$is_admin = isAdmin();

$page_title = "Products";
if ($action === 'add') {
    protectProducts('add');
    $page_title = "Add Product";
} elseif ($action === 'edit') {
    protectProducts('edit');
    $page_title = "Edit Product";
} elseif ($action === 'view') {
    protectProducts('view');
    $page_title = "Product Details";
} else {
    protectProducts('view');
}

include "../config/database.php";

if (isset($_GET['confirm_delete'])) {
    protectProducts('delete');
    $id = (int)$_GET['confirm_delete'];
    
    $p = mysqli_fetch_assoc(mysqli_query($conn, "SELECT image, current_stock FROM products WHERE id=$id"));
    if ($p) {
        if ($p['current_stock'] > 0) {
            header("Location:index.php?error=" . urlencode("Cannot delete product. Stock must be zero."));
            exit;
        }
        
        if ($p['image'] && file_exists("../img/" . $p['image'])) {
            unlink("../img/" . $p['image']);
        }
        mysqli_query($conn, "DELETE FROM products WHERE id=$id");
    }
    header("Location:index.php");
    exit;
}

if ($action === 'edit' && isset($_POST['update'])) {
    $id = (int)$_GET['id'];
    $category_id = (int)$_POST['category_id'];
    $product_name = mysqli_real_escape_string($conn, $_POST['product_name']);
    $sku = mysqli_real_escape_string($conn, $_POST['sku']);
    $barcode = mysqli_real_escape_string($conn, $_POST['barcode']);
    $unit_id = (int)$_POST['unit_id'];
    $reorder_level = (int)$_POST['reorder_level'];
    $selling_price = (float)$_POST['selling_price'];
    $status = mysqli_real_escape_string($conn, $_POST['status']);
    $old = mysqli_fetch_assoc(mysqli_query($conn, "SELECT image FROM products WHERE id=$id"));
    $image = $old['image'] ?? '';

    if ($_FILES['image']['name'] != "") {
        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $image = 'prod_' . time() . '_' . $id . '.' . $ext;
        move_uploaded_file($_FILES['image']['tmp_name'], "../img/" . $image);
    }

    $sku_check = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM products WHERE sku='$sku' AND id != $id"));
    if ($sku_check) {
        header("Location:index.php?action=edit&id=$id&error=" . urlencode("SKU already exists. Please use a different SKU."));
        exit;
    }

    mysqli_query($conn, "UPDATE products SET category_id=$category_id, product_name='$product_name', sku='$sku', barcode='$barcode', unit_id=$unit_id, reorder_level=$reorder_level, selling_price=$selling_price, price_update_required = CASE WHEN purchase_price > 0 AND $selling_price <= purchase_price THEN 1 ELSE 0 END, image='$image', status='$status' WHERE id=$id");
    header("Location:index.php");
    exit;
}

if ($action === 'add' && isset($_POST['save'])) {
    $category_id = (int)$_POST['category_id'];
    $product_name = mysqli_real_escape_string($conn, $_POST['product_name']);
    $sku = mysqli_real_escape_string($conn, $_POST['sku']);
    $barcode = mysqli_real_escape_string($conn, $_POST['barcode']);
    $unit_id = (int)$_POST['unit_id'];
    $reorder_level = (int)$_POST['reorder_level'];
    $selling_price = (float)$_POST['selling_price'];
    $status = 'Active';
    $image = "";

    if ($_FILES['image']['name'] != "") {
        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $image = 'prod_' . time() . '.' . $ext;
        move_uploaded_file($_FILES['image']['tmp_name'], "../img/" . $image);
    }

    $sku_check = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM products WHERE sku='$sku'"));
    if ($sku_check) {
        header("Location:index.php?action=add&error=" . urlencode("SKU already exists. Please use a different SKU."));
        exit;
    }

    mysqli_query($conn, "INSERT INTO products (category_id, product_name, sku, barcode, unit_id, reorder_level, selling_price, image, status) VALUES ($category_id, '$product_name', '$sku', '$barcode', $unit_id, $reorder_level, $selling_price, '$image', '$status')");
    header("Location:index.php");
    exit;
}

// ========== EDIT FORM DATA ==========
$product = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $id = $_GET['id'];
    $sql = "SELECT * FROM products WHERE id='$id'";
    $result = mysqli_query($conn, $sql);
    $product = mysqli_fetch_assoc($result);
    if (!$product) {
        die("Product not found");
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include "../includes/theme-init.php"; ?>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body class="bg-[#eaf4fc] dark:bg-slate-900">
    <div class="flex min-h-screen">
        <?php include "../includes/sidebar.php"; ?>
        <div class="flex-1 flex flex-col">
            <?php include "../includes/header.php"; ?>
            <main class="p-6">

                <?php if ($action === 'view'): ?>
                    <?php
                    $view_id = (int)($_GET['id'] ?? 0);
                    $view_product = mysqli_fetch_assoc(mysqli_query($conn, "SELECT p.*, c.name AS category_name, u.unit_name, u.unit_symbol FROM products p LEFT JOIN categories c ON p.category_id = c.id LEFT JOIN units u ON p.unit_id = u.unit_id WHERE p.id = $view_id"));
                    if (!$view_product) {
                        echo '<div class="text-center py-20"><h2 class="text-2xl font-bold text-gray-400">Product not found</h2><a href="index.php" class="text-indigo-600 mt-4 inline-block">&larr; Back to Products</a></div>';
                    } else {
                        $vqty = (int)$view_product['current_stock'];
                        $vmin = (int)$view_product['reorder_level'];
                        if ($vqty == 0) {
                            $vstockLabel = 'Out of Stock';
                            $vstockClass = 'bg-red-100 text-red-700';
                        } elseif ($vqty <= $vmin) {
                            $vstockLabel = 'Low Stock';
                            $vstockClass = 'bg-amber-100 text-amber-700';
                        } else {
                            $vstockLabel = 'In Stock';
                            $vstockClass = 'bg-green-100 text-green-700';
                        }

                        // Recent purchases
                        $recent_purchases = [];
                        $rp_res = mysqli_query($conn, "SELECT pd.*, pu.invoice_no, pu.purchase_date, s.supplier_name FROM purchase_details pd JOIN purchases pu ON pd.purchase_id=pu.id LEFT JOIN suppliers s ON pu.supplier_id=s.id WHERE pd.product_id=$view_id ORDER BY pu.purchase_date DESC LIMIT 5");
                        while ($r = mysqli_fetch_assoc($rp_res)) $recent_purchases[] = $r;

                        // Recent sales
                        $recent_sales = [];
                        $rs_res = mysqli_query($conn, "SELECT sd.*, sa.invoice_no, sa.created_at FROM sale_details sd JOIN sales sa ON sd.sale_id=sa.id WHERE sd.product_id=$view_id ORDER BY sa.created_at DESC LIMIT 5");
                        while ($r = mysqli_fetch_assoc($rs_res)) $recent_sales[] = $r;

                        // Total purchased & sold
                        $total_purchased = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(quantity),0) AS qty, COALESCE(SUM(subtotal),0) AS amount FROM purchase_details WHERE product_id=$view_id"));
                        $total_sold = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(quantity),0) AS qty, COALESCE(SUM(subtotal),0) AS amount, COALESCE(SUM(profit),0) AS profit FROM sale_details WHERE product_id=$view_id"));
                    ?>
                        <!-- Breadcrumb -->
                        <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 mb-6">
                            <a href="index.php" class="hover:text-indigo-600 transition">Products</a>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            <span class="text-gray-900 dark:text-white font-semibold"><?= htmlspecialchars($view_product['product_name']) ?></span>
                        </div>

                        <div class="max-w-5xl mx-auto space-y-6">

                            <!-- Product Header -->
                            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 p-6">
                                <div class="flex flex-col md:flex-row gap-6">
                                    <!-- Image -->
                                    <div class="flex-shrink-0">
                                        <?php if ($view_product['image']): ?>
                                            <img src="../img/<?= htmlspecialchars($view_product['image'] ?? '') ?>" class="w-40 h-40 object-cover rounded-2xl shadow-lg">
                                        <?php else: ?>
                                            <div class="w-40 h-40 rounded-2xl bg-gray-100 dark:bg-slate-700 flex items-center justify-center text-gray-400">
                                                <svg class="w-16 h-16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                </svg>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Info -->
                                    <div class="flex-1 min-w-0">
                                        <div class="flex flex-wrap items-center gap-2 mb-2">
                                            <span class="badge badge-info"><span class="badge-dot"></span> <?= htmlspecialchars($view_product['category_name'] ?? '—') ?></span>
                                            <?php if ($view_product['status'] === 'Active'): ?>
                                                <span class="badge badge-success"><span class="badge-dot"></span> Active</span>
                                            <?php else: ?>
                                                <span class="badge badge-gray"><span class="badge-dot"></span> Inactive</span>
                                            <?php endif; ?>
                                            <span class="<?= $vstockClass ?> px-3 py-1 rounded-full text-xs font-semibold"><?= $vstockLabel ?></span>
                                        </div>
                                        <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-1"><?= htmlspecialchars($view_product['product_name']) ?></h1>
                                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4"><?= htmlspecialchars($view_product['sku'] ?? '') ?> <?= $view_product['barcode'] ? ' &middot; ' . htmlspecialchars($view_product['barcode'] ?? '') : '' ?></p>

                                        <?php if (!empty($view_product['description'])): ?>
                                            <p class="text-sm text-gray-600 dark:text-gray-300 leading-relaxed"><?= nl2br(htmlspecialchars($view_product['description'] ?? '')) ?></p>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Price -->
                                    <div class="flex-shrink-0 text-right">
                                        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">Selling Price</p>
                                        <p class="text-3xl font-bold text-emerald-600 mt-1"><?= number_format($view_product['selling_price']) ?> Ks</p>
                                        <p class="text-xs text-gray-400 mt-1">Purchase: <?= number_format($view_product['purchase_price']) ?> Ks</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Info Grid -->
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 p-5 text-center">
                                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Current Stock</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1"><?= $vqty ?></p>
                                    <p class="text-xs text-gray-400 mt-0.5"><?= htmlspecialchars($view_product['unit_symbol'] ?? '') ?></p>
                                </div>
                                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 p-5 text-center">
                                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Reorder Level</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1"><?= $view_product['reorder_level'] ?></p>
                                    <p class="text-xs text-gray-400 mt-0.5">Minimum</p>
                                </div>
                                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 p-5 text-center">
                                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total Purchased</p>
                                    <p class="text-2xl font-bold text-blue-600 mt-1"><?= number_format($total_purchased['qty']) ?></p>
                                    <p class="text-xs text-gray-400 mt-0.5"><?= number_format($total_purchased['amount']) ?> Ks</p>
                                </div>
                                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 p-5 text-center">
                                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total Sold</p>
                                    <p class="text-2xl font-bold text-emerald-600 mt-1"><?= number_format($total_sold['qty']) ?></p>
                                    <p class="text-xs text-gray-400 mt-0.5">Profit: <?= number_format($total_sold['profit']) ?> Ks</p>
                                </div>
                            </div>

                            <!-- Details Grid -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- Product Details -->
                                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 overflow-hidden">
                                    <div class="px-6 py-4 border-b border-gray-100 dark:border-slate-700 bg-gray-50 dark:bg-slate-700/50">
                                        <h3 class="text-sm font-bold text-gray-700 dark:text-gray-200 uppercase tracking-wider">Product Details</h3>
                                    </div>
                                    <div class="p-6 space-y-3">
                                        <div class="flex justify-between py-2 border-b border-gray-100 dark:border-slate-700">
                                            <span class="text-sm text-gray-500">SKU</span>
                                            <span class="text-sm font-semibold text-gray-900 dark:text-white font-mono"><?= htmlspecialchars($view_product['sku'] ?? '') ?></span>
                                        </div>
                                        <div class="flex justify-between py-2 border-b border-gray-100 dark:border-slate-700">
                                            <span class="text-sm text-gray-500">Barcode</span>
                                            <span class="text-sm font-semibold text-gray-900 dark:text-white font-mono"><?= htmlspecialchars($view_product['barcode'] ?: '—') ?></span>
                                        </div>
                                        <div class="flex justify-between py-2 border-b border-gray-100 dark:border-slate-700">
                                            <span class="text-sm text-gray-500">Unit</span>
                                            <span class="text-sm font-semibold text-gray-900 dark:text-white"><?= htmlspecialchars($view_product['unit_name'] ?? '') ?></span>
                                        </div>
                                        <div class="flex justify-between py-2 border-b border-gray-100 dark:border-slate-700">
                                            <span class="text-sm text-gray-500">Category</span>
                                            <span class="text-sm font-semibold text-gray-900 dark:text-white"><?= htmlspecialchars($view_product['category_name'] ?? '—') ?></span>
                                        </div>
                                        <div class="flex justify-between py-2">
                                            <span class="text-sm text-gray-500">Created</span>
                                            <span class="text-sm font-semibold text-gray-900 dark:text-white"><?= date('d M Y', strtotime($view_product['created_at'])) ?></span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Pricing -->
                                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 overflow-hidden">
                                    <div class="px-6 py-4 border-b border-gray-100 dark:border-slate-700 bg-gray-50 dark:bg-slate-700/50">
                                        <h3 class="text-sm font-bold text-gray-700 dark:text-gray-200 uppercase tracking-wider">Pricing</h3>
                                    </div>
                                    <div class="p-6 space-y-3">
                                        <div class="flex justify-between py-2 border-b border-gray-100 dark:border-slate-700">
                                            <span class="text-sm text-gray-500">Selling Price</span>
                                            <span class="text-sm font-bold text-emerald-600"><?= number_format($view_product['selling_price']) ?> Ks</span>
                                        </div>
                                        <div class="flex justify-between py-2 border-b border-gray-100 dark:border-slate-700">
                                            <span class="text-sm text-gray-500">Purchase Price</span>
                                            <span class="text-sm font-semibold text-gray-900 dark:text-white"><?= number_format($view_product['purchase_price']) ?> Ks</span>
                                        </div>
                                        <?php if ($view_product['purchase_price'] > 0 && (!isset($_SESSION['role']) || $_SESSION['role'] !== 'staff')): ?>
                                        <div class="flex justify-between py-2">
                                            <span class="text-sm text-gray-500">Margin</span>
                                            <span class="text-sm font-semibold text-emerald-600"><?= number_format($view_product['selling_price'] - $view_product['purchase_price']) ?> Ks (<?= number_format((($view_product['selling_price'] - $view_product['purchase_price']) / $view_product['purchase_price']) * 100, 1) ?>%)</span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Recent Purchases -->
                            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 overflow-hidden">
                                <div class="px-6 py-4 border-b border-gray-100 dark:border-slate-700 bg-gray-50 dark:bg-slate-700/50">
                                    <h3 class="text-sm font-bold text-gray-700 dark:text-gray-200 uppercase tracking-wider">Recent Purchases</h3>
                                </div>
                                <?php if (empty($recent_purchases)): ?>
                                    <div class="p-8 text-center text-gray-400 text-sm">No purchase history</div>
                                <?php else: ?>
                                    <div class="table-wrap">
                                        <table class="data-table w-full">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Invoice</th>
                                                    <th>Supplier</th>
                                                    <th class="num">Qty</th>
                                                    <th class="num">Price</th>
                                                    <th class="num">Subtotal</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recent_purchases as $rp): ?>
                                                <tr>
                                                    <td class="px-4 py-3 text-sm"><?= date('d M Y', strtotime($rp['purchase_date'])) ?></td>
                                                    <td class="px-4 py-3 text-sm font-mono"><?= htmlspecialchars($rp['invoice_no']) ?></td>
                                                    <td class="px-4 py-3 text-sm"><?= htmlspecialchars($rp['supplier_name'] ?? '—') ?></td>
                                                    <td class="px-4 py-3 text-sm num"><?= $rp['quantity'] ?></td>
                                                    <td class="px-4 py-3 text-sm num"><?= number_format($rp['purchase_price']) ?> Ks</td>
                                                    <td class="px-4 py-3 text-sm num font-semibold"><?= number_format($rp['subtotal']) ?> Ks</td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Recent Sales -->
                            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 overflow-hidden">
                                <div class="px-6 py-4 border-b border-gray-100 dark:border-slate-700 bg-gray-50 dark:bg-slate-700/50">
                                    <h3 class="text-sm font-bold text-gray-700 dark:text-gray-200 uppercase tracking-wider">Recent Sales</h3>
                                </div>
                                <?php if (empty($recent_sales)): ?>
                                    <div class="p-8 text-center text-gray-400 text-sm">No sales history</div>
                                <?php else: ?>
                                    <div class="table-wrap">
                                        <table class="data-table w-full">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Invoice</th>
                                                    <th class="num">Qty</th>
                                                    <th class="num">Price</th>
                                                    <th class="num">Subtotal</th>
                                                    <th class="num">Profit</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recent_sales as $rs): ?>
                                                <tr>
                                                    <td class="px-4 py-3 text-sm"><?= date('d M Y', strtotime($rs['created_at'])) ?></td>
                                                    <td class="px-4 py-3 text-sm font-mono"><?= htmlspecialchars($rs['invoice_no']) ?></td>
                                                    <td class="px-4 py-3 text-sm num"><?= $rs['quantity'] ?></td>
                                                    <td class="px-4 py-3 text-sm num"><?= number_format($rs['selling_price']) ?> Ks</td>
                                                    <td class="px-4 py-3 text-sm num font-semibold"><?= number_format($rs['subtotal']) ?> Ks</td>
                                                    <td class="px-4 py-3 text-sm num text-emerald-600 font-semibold"><?= number_format($rs['profit']) ?> Ks</td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Action Buttons -->
                            <div class="flex justify-end gap-3 pb-6">
                                <a href="index.php" class="px-6 py-3 bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-gray-300 rounded-xl text-sm font-semibold hover:bg-gray-200 dark:hover:bg-slate-600 transition">Back to Products</a>
                                <?php if (checkPermission('products', 'edit')): ?>
                                    <a href="?action=edit&id=<?= $view_product['id'] ?>" class="px-6 py-3 bg-indigo-600 text-white rounded-xl text-sm font-semibold hover:bg-indigo-700 transition shadow-sm">Edit Product</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php } ?>

                <?php elseif ($action === 'add' || $action === 'edit'): ?>

                    <?php $is_edit = ($action === 'edit' && $product); ?>
                    <div class="max-w-5xl mx-auto">
                        <form method="POST" enctype="multipart/form-data" data-form-guard="true">
                            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                                <!-- Left Column: Product Information -->
                                <div class="lg:col-span-2 space-y-6">
                                    <!-- Product Information Card -->
                                    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 overflow-hidden">
                                        <div class="px-6 py-4 border-b border-gray-100 dark:border-slate-700 bg-gray-50 dark:bg-slate-700/50">
                                            <h3 class="text-sm font-bold text-gray-700 dark:text-gray-200 uppercase tracking-wider flex items-center gap-2">
                                                <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                                </svg>
                                                Product Information
                                            </h3>
                                        </div>
                                        <div class="p-6">
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                                <!-- Product Image -->
                                                <div class="md:col-span-2">
                                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Product Image</label>
                                                    <div class="flex items-center gap-4">
                                                        <div class="relative">
                                                            <input type="file" name="image" id="imageInput" accept="image/*" class="hidden" onchange="previewImage(this)">
                                                            <label for="imageInput" class="cursor-pointer block w-24 h-24 rounded-xl border-2 border-dashed border-gray-300 dark:border-slate-600 hover:border-indigo-400 transition-colors overflow-hidden flex items-center justify-center bg-gray-50 dark:bg-slate-700">
                                                                <?php if ($is_edit && $product['image']): ?>
                                                                    <img id="imagePreview" src="../img/<?= htmlspecialchars($product['image'] ?? '') ?>" class="w-full h-full object-cover">
                                                                <?php else: ?>
                                                                    <img id="imagePreview" class="hidden w-full h-full object-cover">
                                                                    <div id="imagePlaceholder" class="text-center">
                                                                        <svg class="w-8 h-8 mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                                        </svg>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </label>
                                                        </div>
                                                        <div class="text-sm text-gray-500 dark:text-gray-400">
                                                            <p>Click to upload image</p>
                                                            <p class="text-xs mt-1">PNG, JPG up to 5MB</p>
                                                            <?php if ($is_edit && $product['image']): ?>
                                                                <p class="text-xs text-amber-500 mt-1">Leave empty to keep current image</p>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Product Name -->
                                                <div class="md:col-span-2">
                                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Product Name <span class="text-red-500">*</span></label>
                                                    <input type="text" name="product_name" value="<?= $is_edit ? htmlspecialchars($product['product_name'] ?? '') : '' ?>" class="w-full border border-gray-300 dark:border-slate-600 rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-slate-700 dark:text-white transition" placeholder="Enter product name" required>
                                                </div>

                                                <!-- SKU -->
                                                <div>
                                                    <label class="flex justify-between items-center mb-2">
                                                        <span class="block text-sm font-semibold text-gray-700 dark:text-gray-300">SKU <span class="text-red-500">*</span></span>
                                                        <button type="button" onclick="generateSKU()" class="text-xs text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 font-semibold bg-indigo-50 dark:bg-indigo-500/10 px-2 py-1 rounded transition">Auto Generate</button>
                                                    </label>
                                                    <input type="text" name="sku" id="skuInput" value="<?= $is_edit ? htmlspecialchars($product['sku'] ?? '') : '' ?>" class="w-full border border-gray-300 dark:border-slate-600 rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-slate-700 dark:text-white font-mono transition" placeholder="e.g. DRK001" required maxlength="12">
                                                </div>

                                                <!-- Barcode -->
                                                <div>
                                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Barcode</label>
                                                    <input type="text" name="barcode" value="<?= $is_edit ? htmlspecialchars($product['barcode'] ?? '') : '' ?>" class="w-full border border-gray-300 dark:border-slate-600 rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-slate-700 dark:text-white font-mono transition" placeholder="Enter barcode">
                                                </div>

                                                <!-- Category -->
                                                <div>
                                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Category <span class="text-red-500">*</span></label>
                                                    <select name="category_id" class="w-full border border-gray-300 dark:border-slate-600 rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-slate-700 dark:text-white transition" required>
                                                        <option value="">Select Category</option>
                                                        <?php
                                                        $cat_sql = "SELECT * FROM categories WHERE status='Active' ORDER BY name ASC";
                                                        $categories = mysqli_query($conn, $cat_sql);
                                                        while ($cat = mysqli_fetch_assoc($categories)) {
                                                            $sel = ($is_edit && $cat['id'] == $product['category_id']) ? 'selected' : '';
                                                        ?>
                                                            <option value="<?= $cat['id'] ?>" <?= $sel ?>><?= htmlspecialchars($cat['name']) ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </div>

                                                <!-- Unit -->
                                                <div>
                                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Unit <span class="text-red-500">*</span></label>
                                                    <select name="unit_id" class="w-full border border-gray-300 dark:border-slate-600 rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-slate-700 dark:text-white transition" required>
                                                        <option value="">Select Unit</option>
                                                        <?php
                                                        $unit_sql = "SELECT * FROM units WHERE status='Active' ORDER BY unit_name ASC";
                                                        $units_result = mysqli_query($conn, $unit_sql);
                                                        while ($u = mysqli_fetch_assoc($units_result)) {
                                                            $sel = ($is_edit && $product['unit_id'] == $u['unit_id']) ? 'selected' : '';
                                                        ?>
                                                            <option value="<?= $u['unit_id'] ?>" <?= $sel ?>><?= htmlspecialchars($u['unit_name']) ?> (<?= htmlspecialchars($u['unit_symbol']) ?>)</option>
                                                        <?php } ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Right Column: Pricing & Inventory -->
                                <div class="space-y-6">
                                    <!-- Pricing Card -->
                                    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 overflow-hidden">
                                        <div class="px-6 py-4 border-b border-gray-100 dark:border-slate-700 bg-gray-50 dark:bg-slate-700/50">
                                            <h3 class="text-sm font-bold text-gray-700 dark:text-gray-200 uppercase tracking-wider flex items-center gap-2">
                                                <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                                Pricing
                                            </h3>
                                        </div>
                                        <div class="p-6 space-y-4">
                                            <div>
                                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Selling Price <span class="text-red-500">*</span></label>
                                                <div class="relative">
                                                    <input type="number" name="selling_price" value="<?= $is_edit ? $product['selling_price'] : '' ?>" class="w-full border border-gray-300 dark:border-slate-600 rounded-xl px-4 py-3 pr-12 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-slate-700 dark:text-white transition" placeholder="0" min="0" step="0.01" required>
                                                    <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-gray-400 font-medium">Ks</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Inventory Card -->
                                    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 overflow-hidden">
                                        <div class="px-6 py-4 border-b border-gray-100 dark:border-slate-700 bg-gray-50 dark:bg-slate-700/50">
                                            <h3 class="text-sm font-bold text-gray-700 dark:text-gray-200 uppercase tracking-wider flex items-center gap-2">
                                                <svg class="w-4 h-4 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                                </svg>
                                                Inventory
                                            </h3>
                                        </div>
                                        <div class="p-6 space-y-4">
                                            <div>
                                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Reorder Level <span class="text-red-500">*</span></label>
                                                <input type="number" name="reorder_level" value="<?= $is_edit ? $product['reorder_level'] : '10' ?>" class="w-full border border-gray-300 dark:border-slate-600 rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-slate-700 dark:text-white transition" placeholder="10" min="0" required>
                                                <p class="text-xs text-gray-400 mt-1.5">Alert when stock falls below this level</p>
                                            </div>
                                            <?php if ($is_edit): ?>
                                                <div>
                                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Status <span class="text-red-500">*</span></label>
                                                    <select name="status" class="w-full border border-gray-300 dark:border-slate-600 rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-slate-700 dark:text-white transition" required>
                                                        <option value="Active" <?= ($product['status'] == 'Active') ? 'selected' : '' ?>>Active</option>
                                                        <option value="Inactive" <?= ($product['status'] == 'Inactive') ? 'selected' : '' ?>>Inactive</option>
                                                    </select>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Info Note -->
                                    <div class="bg-blue-50 dark:bg-blue-500/10 border border-blue-200 dark:border-blue-500/30 rounded-2xl p-4">
                                        <div class="flex gap-3">
                                            <svg class="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            <div class="text-sm text-blue-700 dark:text-blue-300">
                                                <p class="font-semibold">Stock Management</p>
                                                <p class="mt-1 text-xs">Stock quantity and purchase price are managed through the <strong>Stock In (Purchase)</strong> module.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="flex justify-end gap-3 mt-8 pt-6 border-t border-gray-200 dark:border-slate-700">
                                <a href="index.php" class="px-6 py-3 bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-gray-300 rounded-xl text-sm font-semibold hover:bg-gray-200 dark:hover:bg-slate-600 transition">Cancel</a>
                                <button name="<?= $is_edit ? 'update' : 'save' ?>" class="px-8 py-3 bg-indigo-600 text-white rounded-xl text-sm font-semibold hover:bg-indigo-700 transition shadow-sm shadow-indigo-200 dark:shadow-indigo-500/20">
                                    <?= $is_edit ? 'Update Product' : 'Save Product' ?>
                                </button>
                            </div>
                        </form>
                    </div>

                    <script>
                        function previewImage(input) {
                            if (input.files && input.files[0]) {
                                var reader = new FileReader();
                                reader.onload = function(e) {
                                    var preview = document.getElementById('imagePreview');
                                    var placeholder = document.getElementById('imagePlaceholder');
                                    preview.src = e.target.result;
                                    preview.classList.remove('hidden');
                                    if (placeholder) placeholder.classList.add('hidden');
                                }
                                reader.readAsDataURL(input.files[0]);
                            }
                        }

                        function generateSKU() {
                            const categorySelect = document.querySelector('select[name="category_id"]');
                            const catId = categorySelect ? categorySelect.value : '';
                            
                            fetch(`../ajax/generate_sku.php?category_id=${catId}`)
                                .then(res => res.json())
                                .then(data => {
                                    if(data.success) {
                                        document.getElementById('skuInput').value = data.sku;
                                    }
                                })
                                .catch(err => console.error(err));
                        }
                    </script>

                <?php else: ?>

                    <?php
                    $search = mysqli_real_escape_string($conn, $_GET['search'] ?? '');
                    $category = mysqli_real_escape_string($conn, $_GET['category'] ?? '');
                    $status = mysqli_real_escape_string($conn, $_GET['status'] ?? '');

                    $sql = "
SELECT products.*, categories.name, units.unit_name, units.unit_symbol
FROM products
INNER JOIN categories ON products.category_id = categories.id
LEFT JOIN units ON products.unit_id = units.unit_id
WHERE 1=1
";
                    if ($search != "") {
                        $sql .= " AND (products.product_name LIKE '%$search%' OR products.sku LIKE '%$search%')";
                    }
                    if ($category != "") {
                        $sql .= " AND products.category_id = '$category'";
                    }
                    if ($status != "") {
                        $sql .= " AND products.status = '$status'";
                    }
                    $sql .= " ORDER BY products.id DESC";
                    $result = mysqli_query($conn, $sql);

                    $total_product = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM products"));
                    $active_product = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM products WHERE status='Active'"));
                    $low_stock = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM products WHERE current_stock > 0 AND current_stock <= reorder_level"));
                    $out_of_stock = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM products WHERE current_stock = 0"));
                    $total_quantity = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(current_stock) AS total FROM products"));
                    ?>

                    <!-- Header Actions -->
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
                        <div>
                            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">All Products</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Manage your products and inventory efficiently.</p>
                        </div>
                        <?php if (checkPermission('products', 'add')): ?>
                            <a href="?action=add" class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2.5 rounded-xl font-semibold text-sm transition shadow-sm flex items-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                Add Product
                            </a>
                        <?php endif; ?>
                    </div>

                    <!-- Compact Summary -->
                    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-gray-200 dark:border-slate-700 p-4 mb-6">
                        <div class="flex flex-wrap items-center gap-6 text-sm divide-x divide-gray-200 dark:divide-slate-700">
                            <div class="flex flex-col px-4 first:pl-0">
                                <span class="text-gray-500 dark:text-gray-400">Total Products</span>
                                <span class="font-bold text-gray-900 dark:text-white text-lg"><?= $total_product['total']; ?></span>
                            </div>
                            <div class="flex flex-col pl-6">
                                <span class="text-gray-500 dark:text-gray-400">Active</span>
                                <span class="font-bold text-emerald-600 text-lg"><?= $active_product['total']; ?></span>
                            </div>
                            <div class="flex flex-col pl-6">
                                <span class="text-gray-500 dark:text-gray-400">Low Stock</span>
                                <span class="font-bold text-amber-600 text-lg"><?= $low_stock['total']; ?></span>
                            </div>
                            <div class="flex flex-col pl-6">
                                <span class="text-gray-500 dark:text-gray-400">Out of Stock</span>
                                <span class="font-bold text-red-600 text-lg"><?= $out_of_stock['total']; ?></span>
                            </div>
                            <div class="flex flex-col pl-6">
                                <span class="text-gray-500 dark:text-gray-400">Total Stock Qty</span>
                                <span class="font-bold text-blue-600 text-lg"><?= $total_quantity['total'] ?? 0; ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Search and Filter Toolbar -->
                    <form method="GET" class="bg-white dark:bg-slate-800 p-4 rounded-xl shadow-sm border border-gray-200 dark:border-slate-700 mb-8 flex flex-col md:flex-row gap-4 items-center">
                        <div class="flex-1 w-full relative">
                            <svg class="w-5 h-5 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            <input type="text" name="search" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" class="w-full border border-gray-300 dark:border-slate-600 rounded-lg pl-10 pr-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-slate-700 dark:text-white transition" placeholder="Search product name, SKU...">
                        </div>
                        <div class="w-full md:w-auto flex flex-col sm:flex-row gap-3">
                            <select name="category" class="border border-gray-300 dark:border-slate-600 rounded-lg px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-slate-700 dark:text-white transition">
                                <option value="">All Categories</option>
                                <?php
                                $cat = mysqli_query($conn, "SELECT * FROM categories");
                                while ($c = mysqli_fetch_assoc($cat)) {
                                ?>
                                    <option value="<?= $c['id'] ?>" <?= (isset($_GET['category']) && $_GET['category'] == $c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                                <?php } ?>
                            </select>
                            <select name="status" class="border border-gray-300 dark:border-slate-600 rounded-lg px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-slate-700 dark:text-white transition">
                                <option value="">All Status</option>
                                <option value="Active" <?= (($_GET['status'] ?? '') == 'Active') ? 'selected' : '' ?>>Active</option>
                                <option value="Inactive" <?= (($_GET['status'] ?? '') == 'Inactive') ? 'selected' : '' ?>>Inactive</option>
                            </select>
                            <button type="submit" class="bg-gray-900 dark:bg-white text-white dark:text-gray-900 px-5 py-2 rounded-lg text-sm font-semibold hover:bg-gray-800 dark:hover:bg-gray-100 transition whitespace-nowrap">Filter</button>
                            <a href="index.php" class="border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-gray-300 px-5 py-2 rounded-lg text-sm font-semibold hover:bg-gray-50 dark:hover:bg-slate-700 transition whitespace-nowrap text-center">Reset</a>
                        </div>
                    </form>

                    <!-- Product Card Grid -->
                    <?php if (mysqli_num_rows($result) > 0): ?>
                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
                            <?php while ($row = mysqli_fetch_assoc($result)) {
                                $qty = (int)$row['current_stock'];
                                $minStock = (int)$row['reorder_level'];
                                if ($qty == 0) {
                                    $stockBadge = '<span class="px-2.5 py-1 bg-red-100/90 text-red-700 text-[11px] font-bold rounded shadow-sm border border-red-200 backdrop-blur-sm">Out of Stock</span>';
                                } elseif ($qty <= $minStock) {
                                    $stockBadge = '<span class="px-2.5 py-1 bg-amber-100/90 text-amber-700 text-[11px] font-bold rounded shadow-sm border border-amber-200 backdrop-blur-sm">Low Stock</span>';
                                } else {
                                    $stockBadge = '<span class="px-2.5 py-1 bg-emerald-100/90 text-emerald-700 text-[11px] font-bold rounded shadow-sm border border-emerald-200 backdrop-blur-sm">In Stock</span>';
                                }
                            ?>
                                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 h-full flex flex-col hover:shadow-lg transition-all duration-200 group overflow-hidden">
                                    <!-- Image Area -->
                                    <div class="h-48 w-full bg-gray-50 dark:bg-slate-700/30 relative flex items-center justify-center p-4 border-b border-gray-100 dark:border-slate-700/50">
                                        <!-- Badges -->
                                        <div class="absolute top-3 left-3 z-10 flex flex-col gap-2">
                                            <?= $stockBadge ?>
                                            <?php if ($row['status'] === 'Inactive'): ?>
                                                <span class="px-2.5 py-1 bg-gray-100/90 text-gray-600 text-[11px] font-bold rounded shadow-sm border border-gray-200 uppercase tracking-wider backdrop-blur-sm">Inactive</span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Quick Actions -->
                                        <div class="absolute top-3 right-3 z-10 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                            <?php if (checkPermission('products', 'edit')): ?>
                                                <a href="?action=edit&id=<?= $row['id'] ?>" class="p-1.5 bg-white/90 dark:bg-slate-800/90 text-gray-600 dark:text-gray-300 rounded-lg shadow-sm hover:text-indigo-600 dark:hover:text-indigo-400 transition backdrop-blur-sm" title="Edit">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                                </a>
                                            <?php endif; ?>
                                            <?php if (checkPermission('products', 'delete')): ?>
                                                <?php if ($qty > 0): ?>
                                                    <button onclick="alert('Cannot delete this product because there is still stock remaining (<?= $qty ?>). Please adjust stock to 0 first.')" class="p-1.5 bg-white/50 dark:bg-slate-800/50 text-gray-400 dark:text-gray-500 rounded-lg shadow-sm cursor-not-allowed backdrop-blur-sm" title="Cannot delete: Stock is > 0">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                    </button>
                                                <?php else: ?>
                                                    <button onclick="openDeleteModal(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['product_name'])) ?>', 'index.php')" class="p-1.5 bg-white/90 dark:bg-slate-800/90 text-gray-600 dark:text-gray-300 rounded-lg shadow-sm hover:text-red-600 dark:hover:text-red-400 transition backdrop-blur-sm" title="Delete">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                    </button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($row['image']): ?>
                                            <img src="../img/<?= htmlspecialchars($row['image'] ?? '') ?>" class="w-full h-full object-contain group-hover:scale-105 transition-transform duration-300" alt="<?= htmlspecialchars($row['product_name']) ?>">
                                        <?php else: ?>
                                            <div class="w-16 h-16 rounded-xl bg-gray-200 dark:bg-slate-600 flex items-center justify-center text-gray-400">
                                                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                </svg>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Content Area -->
                                    <div class="p-5 flex-1 flex flex-col">
                                        <h3 class="text-[15px] font-bold text-gray-900 dark:text-white leading-snug line-clamp-2 min-h-[2.5rem] mb-2">
                                            <?= htmlspecialchars($row['product_name']) ?>
                                        </h3>
                                        
                                        <div class="space-y-1 mb-4">
                                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                                <span class="font-mono text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-slate-700/50 px-1.5 py-0.5 rounded text-[10px] uppercase tracking-wider">SKU: <?= htmlspecialchars($row['sku'] ?? '') ?></span>
                                            </div>
                                            <div class="text-[13px] text-gray-600 dark:text-gray-300 font-medium pt-1">
                                                <?= htmlspecialchars($row['name']) ?> &middot; <?= htmlspecialchars($row['unit_symbol'] ?? '') ?>
                                            </div>
                                        </div>
                                        
                                        <div class="mt-auto">
                                            <?php if (!empty($row['price_update_required'])): ?>
                                                <div class="mb-2">
                                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-700 border border-amber-200">
                                                        <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                                                        Price Update Required
                                                    </span>
                                                </div>
                                            <?php endif; ?>

                                            <div class="flex items-end justify-between mb-4">
                                                <div>
                                                    <p class="text-lg font-bold text-emerald-600 dark:text-emerald-400"><?= number_format($row['selling_price']) ?> <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">Ks</span></p>
                                                </div>
                                                <div class="text-right">
                                                    <p class="text-xs font-semibold text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-slate-700 px-2 py-1 rounded-lg border border-gray-100 dark:border-slate-600">
                                                        Stock: <?= $qty ?>
                                                    </p>
                                                </div>
                                            </div>

                                            <a href="?action=view&id=<?= $row['id'] ?>" class="block w-full py-2 bg-gray-50 hover:bg-indigo-50 dark:bg-slate-700/50 dark:hover:bg-slate-600 text-gray-700 hover:text-indigo-600 dark:text-gray-300 dark:hover:text-white font-semibold text-center rounded-xl border border-gray-200 dark:border-slate-600 hover:border-indigo-200 dark:hover:border-slate-500 transition-colors text-sm">
                                                View Details
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>
                    <?php else: ?>
                        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 p-12 text-center">
                            <div class="w-16 h-16 bg-gray-50 dark:bg-slate-700 rounded-full flex items-center justify-center mx-auto mb-4">
                                <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                </svg>
                            </div>
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">No products found</h3>
                            <p class="text-gray-500 dark:text-gray-400 mb-6 text-sm">We couldn't find any products matching your current filters.</p>
                            <?php if (checkPermission('products', 'add')): ?>
                                <a href="?action=add" class="inline-flex items-center gap-2 bg-indigo-600 text-white px-5 py-2.5 rounded-xl font-semibold text-sm hover:bg-indigo-700 transition shadow-sm">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                    Add Product
                                </a>
                            <?php endif; ?>
                            <a href="index.php" class="inline-flex items-center gap-2 bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-gray-300 px-5 py-2.5 rounded-xl font-semibold text-sm hover:bg-gray-50 dark:hover:bg-slate-700 transition ml-2 shadow-sm">
                                Clear Filters
                            </a>
                        </div>
                    <?php endif; ?>

                <?php endif; ?>

            </main>
        </div>
    </div>
    <?php include "../includes/toast.php"; ?>
    <?php include "../includes/modal.php"; ?>
    <?php include "../includes/form_guard.php"; ?>
    <?php include "../includes/footer.php"; ?>
</body>
</html>
