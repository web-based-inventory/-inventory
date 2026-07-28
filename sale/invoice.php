<?php
include "../includes/auth_check.php";
protectSales('view');
include "../config/database.php";
include "../config/helpers.php";

$sale_id = (int)($_GET['id'] ?? 0);
if ($sale_id <= 0) {
    header("Location: index.php");
    exit;
}

$sale = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT s.*, u.name AS cashier
    FROM sales s
    LEFT JOIN users u ON s.user_id = u.id
    WHERE s.id='$sale_id'
"));
if (!$sale) {
    echo "Sale not found.";
    exit;
}

$items = mysqli_query($conn, "
    SELECT sd.*, p.product_name, p.sku
    FROM sale_details sd
    LEFT JOIN products p ON sd.product_id = p.id
    WHERE sd.sale_id='$sale_id'
");

$subtotal = 0;
$item_rows = [];
while ($row = mysqli_fetch_assoc($items)) {
    $subtotal += $row['subtotal'];
    $item_rows[] = $row;
}

$amtCol = getPaymentAmountCol($conn, 'sale_payments');
$payments = mysqli_query($conn, "
    SELECT * FROM sale_payments
    WHERE sale_id='$sale_id'
    ORDER BY id ASC
");

$payment_details = [];
$total_paid = 0;
$payment_method_display = 'Cash';
if (mysqli_num_rows($payments) > 0) {
    while ($p = mysqli_fetch_assoc($payments)) {
        $payment_details[] = $p;
        $total_paid += $p[$amtCol];
        $payment_method_display = $p['payment_method'] ?? 'Cash';
    }
}

$discount = (float)($sale['discount'] ?? 0);
$grand_total = (float)$sale['total_amount'];
$change = max(0, $total_paid - $grand_total);

// Fetch shop settings
$invoice_settings = getShopSettings($conn);
$invoice_shop_name = htmlspecialchars($invoice_settings['shop_name']);
$invoice_logo = trim($invoice_settings['logo'] ?? '');
$invoice_logo_path = !empty($invoice_logo) ? dirname(__DIR__) . '/img/' . $invoice_logo : '';
$invoice_has_logo = !empty($invoice_logo) && file_exists($invoice_logo_path);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $invoice_shop_name ?> - Invoice #<?= htmlspecialchars($sale['invoice_no']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include "../includes/theme-init.php"; ?>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        @media print {
            .no-print {
                display: none !important;
            }

            body {
                background: #fff !important;
            }
        }

        @media print {
            @page {
                margin: 0.5in;
            }
        }
    </style>
</head>

<body class="bg-gray-100 dark:bg-slate-900 min-h-screen flex items-start justify-center p-4 sm:p-6 md:p-8">

    <div class="w-full max-w-[580px] flex flex-col items-center">

        <!-- Invoice Card -->
        <div id="invoice-area" class="bg-white w-full rounded-2xl shadow-lg border border-gray-200/80 overflow-hidden">

            <!-- Header -->
            <div class="bg-gradient-to-r from-indigo-600 to-indigo-700 px-6 py-5 text-center">
                <?php if ($invoice_has_logo): ?>
                    <img src="../img/<?= htmlspecialchars($invoice_logo) ?>" alt="<?= $invoice_shop_name ?>"
                        class="w-12 h-12 rounded-xl object-cover mx-auto mb-2 shadow-lg">
                <?php endif; ?>
                <h1 class="text-lg font-bold text-white tracking-wide"><?= $invoice_shop_name ?></h1>
                <?php if (!empty($invoice_settings['phone']) || !empty($invoice_settings['address'])): ?>
                    <p class="text-indigo-200 text-[11px] mt-0.5">
                        <?= htmlspecialchars(implode(' | ', array_filter([$invoice_settings['phone'], $invoice_settings['address']]))) ?>
                    </p>
                <?php endif; ?>
                <p class="text-indigo-200 text-xs mt-0.5">Sales Invoice</p>
            </div>

            <div class="px-6 py-5">

                <!-- Info Grid -->
                <div class="grid grid-cols-2 gap-y-2.5 gap-x-4 text-sm mb-5">
                    <div>
                        <p class="text-[11px] font-semibold text-gray-500 uppercase tracking-wider">Invoice No</p>
                        <p class="font-bold text-gray-900 mt-0.5"><?= htmlspecialchars($sale['invoice_no']) ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-[11px] font-semibold text-gray-500 uppercase tracking-wider">Date & Time</p>
                        <p class="font-semibold text-gray-900 mt-0.5 text-sm"><?= date('d M Y, h:i A', strtotime($sale['created_at'])) ?></p>
                    </div>
                    <div>
                        <p class="text-[11px] font-semibold text-gray-500 uppercase tracking-wider">Cashier</p>
                        <p class="font-semibold text-gray-900 mt-0.5"><?= htmlspecialchars($sale['cashier'] ?? 'Admin') ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-[11px] font-semibold text-gray-500 uppercase tracking-wider">Payment</p>
                        <span class="inline-flex items-center gap-1.5 mt-0.5 px-2.5 py-1 bg-emerald-50 text-emerald-700 rounded-full text-xs font-semibold">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                            <?= htmlspecialchars($payment_method_display) ?>
                        </span>
                    </div>
                </div>

                <!-- Divider -->
                <div class="border-t border-dashed border-gray-300 mb-4"></div>

                <!-- Items Table -->
                <div class="table-wrap">
                    <table class="data-table w-full">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th>SKU</th>
                                <th class="num">Qty</th>
                                <th class="num">Unit Price</th>
                                <th class="num">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $row_idx = 0;
                            foreach ($item_rows as $item): $row_idx++; ?>
                                <tr>
                                    <td><?= $row_idx ?></td>
                                    <td><?= htmlspecialchars($item['product_name']) ?></td>
                                    <td><?= htmlspecialchars($item['sku'] ?? '—') ?></td>
                                    <td class="num"><?= (int)$item['quantity'] ?></td>
                                    <td class="num"><?= number_format((float)$item['selling_price']) ?> Ks</td>
                                    <td class="num"><?= number_format((float)$item['subtotal']) ?> Ks</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Divider -->
                <div class="border-t border-dashed border-gray-300 my-4"></div>

                <!-- Payment Summary -->
                <div class="space-y-1.5 max-w-[260px] ml-auto">
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500">Subtotal</span>
                        <span class="font-semibold text-gray-800"><?= number_format($subtotal) ?> Ks</span>
                    </div>
                    <?php if ($discount > 0): ?>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500">Discount (<?= number_format($discount, 2) ?>%)</span>
                            <span class="font-semibold text-red-500">- <?= number_format($subtotal * $discount / 100) ?> Ks</span>
                        </div>
                    <?php endif; ?>
                    <div class="flex justify-between text-base font-bold pt-2 border-t border-gray-200">
                        <span class="text-gray-900">Grand Total</span>
                        <span class="text-emerald-600"><?= number_format($grand_total) ?> Ks</span>
                    </div>
                    <div class="border-t border-dashed border-gray-300 my-2"></div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500">Amount Paid</span>
                        <span class="font-semibold text-gray-800"><?= number_format($total_paid) ?> Ks</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500">Change</span>
                        <span class="font-semibold <?= $change > 0 ? 'text-emerald-600' : 'text-gray-800' ?>"><?= number_format($change) ?> Ks</span>
                    </div>
                </div>

                <!-- Footer -->
                <div class="border-t border-dashed border-gray-300 mt-5 pt-4 text-center">
                    <p class="text-sm font-semibold text-gray-900">Thank you for your purchase!</p>
                </div>

            </div>
        </div>

        <!-- Buttons -->
        <div class="flex gap-3 mt-5 w-full no-print">
            <button onclick="window.print()" class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold py-3 px-5 rounded-xl flex items-center justify-center gap-2 transition active:scale-[0.98] shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                </svg>
                Print Invoice
            </button>
            <a href="history.php" class="flex-1 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-semibold py-3 px-5 rounded-xl flex items-center justify-center gap-2 transition active:scale-[0.98] shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Back
            </a>
        </div>
    </div>
</body>

</html>