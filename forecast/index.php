<?php
include "../includes/auth_check.php";
protectForecast();
include "../config/database.php";
include "../config/helpers.php";

$page_title = "Demand Forecast";
$action = $_GET['action'] ?? 'dashboard';

// Calculate forecast for a product using moving average (last N days)
function calculateForecast($conn, $product_id, $days = 30)
{
    // Retrieve historical sales aggregated by day
    $query = "
        SELECT DATE(s.created_at) as sale_date, SUM(sd.quantity) AS daily_qty
        FROM sale_details sd
        JOIN sales s ON sd.sale_id = s.id
        WHERE sd.product_id = '$product_id'
        AND s.created_at >= DATE_SUB(CURDATE(), INTERVAL $days DAY)
        GROUP BY DATE(s.created_at)
        ORDER BY sale_date ASC
    ";
    $result = mysqli_query($conn, $query);
    
    $historical_quantities = [];
    $total = 0;
    $sales_count = 0;
    
    while ($row = mysqli_fetch_assoc($result)) {
        $qty = (int)$row['daily_qty'];
        $historical_quantities[$row['sale_date']] = $qty;
        $total += $qty;
        $sales_count++;
    }

    // Handle Insufficient Data (e.g. no sales at all)
    if ($sales_count == 0) {
        $daily_avg = 0;
        $forecast_30 = 0;
        $insufficient = true;
    } else {
        $daily_avg = $days > 0 ? $total / $days : 0;
        $forecast_30 = round($daily_avg * 30);
        $insufficient = false;
    }

    // Debugging info to verify forecasting input for the user
    $debug_info = [
        'product_id' => $product_id,
        'historical_sales_days_count' => $sales_count,
        'historical_quantities' => $historical_quantities,
        'forecast_input_total_sold' => $total,
        'forecast_result_30_days' => $forecast_30,
        'insufficient_data' => $insufficient
    ];
    
    // Write debug info to a file (append)
    $debug_log_path = __DIR__ . '/forecast_debug.log';
    if (!file_exists($debug_log_path) || filesize($debug_log_path) == 0) {
        file_put_contents($debug_log_path, "=== Forecast Debug Log ===\n");
    }
    file_put_contents($debug_log_path, json_encode($debug_info, JSON_PRETTY_PRINT) . "\n\n", FILE_APPEND);

    return [
        'total_sold' => $total,
        'daily_avg' => round($daily_avg, 2),
        'forecast_7' => round($daily_avg * 7),
        'forecast_30' => $forecast_30,
        'insufficient' => $insufficient,
        'debug' => $debug_info
    ];
}

// Run forecast for all products
if ($action === 'generate') {
    $products = mysqli_query($conn, "SELECT id, product_name, current_stock, reorder_level FROM products WHERE status='Active'");

    // Clear old forecasts for today so we can regenerate
    mysqli_query($conn, "DELETE FROM forecasts WHERE forecast_date = CURDATE()");
    
    // Clear debug log on new generation
    $debug_log_path = __DIR__ . '/forecast_debug.log';
    file_put_contents($debug_log_path, "=== Forecast Debug Log (Generated: " . date('Y-m-d H:i:s') . ") ===\n\n");

    while ($p = mysqli_fetch_assoc($products)) {
        $forecast = calculateForecast($conn, $p['id'], 30);
        
        if ($forecast['insufficient']) {
            $demand = 'Insufficient';
            $forecast_qty = 0;
            // Provide a fallback recommendation
            $recommended = max(0, $p['reorder_level'] * 2 - $p['current_stock']);
        } else {
            $daily_avg = $forecast['daily_avg'];
            
            // Determine demand level based on daily average
            if ($daily_avg >= 5) $demand = 'High';
            elseif ($daily_avg >= 1) $demand = 'Medium';
            else $demand = 'Low';

            $forecast_qty = $forecast['forecast_30'];
            
            // Recommended stock: at least 2x min_stock, or enough to cover 30-day forecast
            $recommended = max($forecast_qty, $p['reorder_level'] * 2);
            if ($p['current_stock'] < $p['reorder_level']) {
                $recommended = max($recommended, $p['reorder_level'] * 3 - $p['current_stock']);
            }
        }

        $stmt = $conn->prepare("INSERT INTO forecasts (product_id, forecast_date, forecast_quantity, demand_level, recommended_stock, method)
            VALUES (?, CURDATE(), ?, ?, ?, 'moving_average')
            ON DUPLICATE KEY UPDATE forecast_quantity = VALUES(forecast_quantity), demand_level = VALUES(demand_level), recommended_stock = VALUES(recommended_stock)");
        $stmt->bind_param("iisi", $p['id'], $forecast_qty, $demand, $recommended);
        $stmt->execute();
    }

    // AJAX: return JSON; normal: redirect
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Forecast generated successfully.']);
        exit;
    }
    header("Location: index.php?generated=1");
    exit;
}

$generated = isset($_GET['generated']);

// ============ Dashboard Stats ============

// Total active products
$total_products = mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT COUNT(*) AS count FROM products WHERE status='Active'"
))['count'];

// Forecast generated today?
$has_forecast = mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT COUNT(*) AS count FROM forecasts WHERE forecast_date = CURDATE()"
))['count'] > 0;

// Total expected demand (30-day forecast sum)
$total_forecast = mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT COALESCE(SUM(forecast_quantity), 0) AS total FROM forecasts WHERE forecast_date = CURDATE()"
))['total'];

// High Demand: forecast_quantity > current stock AND daily_avg >= 5 (demand threshold)
$high_demand = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) AS count FROM forecasts f
    JOIN products p ON f.product_id = p.id
    WHERE f.forecast_date = CURDATE()
    AND f.forecast_quantity > p.current_stock
    AND f.demand_level = 'High'
"))['count'];

// Need Restock: current stock <= reorder_level (system restocking rule)
$need_restock = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) AS count FROM forecasts f
    JOIN products p ON f.product_id = p.id
    WHERE f.forecast_date = CURDATE()
    AND p.current_stock <= p.reorder_level
"))['count'];

// ============ High Demand Products ============
$high_demand_products = mysqli_query($conn, "
    SELECT p.product_name, p.current_stock, p.reorder_level, f.forecast_quantity, f.recommended_stock, f.demand_level
    FROM forecasts f
    JOIN products p ON f.product_id = p.id
    WHERE f.forecast_date = CURDATE()
    AND f.forecast_quantity > p.current_stock
    AND f.demand_level = 'High'
    ORDER BY f.forecast_quantity DESC
");

// ============ Products Need Restock ============
$need_restock_products = mysqli_query($conn, "
    SELECT p.product_name, p.current_stock, p.reorder_level, f.forecast_quantity, f.recommended_stock
    FROM forecasts f
    JOIN products p ON f.product_id = p.id
    WHERE f.forecast_date = CURDATE()
    AND p.current_stock <= p.reorder_level
    ORDER BY (f.forecast_quantity - p.current_stock) DESC LIMIT 10
");

// ============ All Forecasts (for chart + table, deduplicated) ============
$products_forecast = mysqli_query($conn, "
    SELECT p.product_name, p.current_stock, f.forecast_quantity, f.demand_level, f.recommended_stock
    FROM forecasts f
    JOIN products p ON f.product_id = p.id
    WHERE f.forecast_date = CURDATE()
    ORDER BY f.forecast_quantity DESC LIMIT 15
");

// Chart data
$chart_labels = [];
$chart_forecast = [];
$chart_current = [];
while ($p = mysqli_fetch_assoc($products_forecast)) {
    $chart_labels[] = $p['product_name'];
    $chart_forecast[] = (int)$p['forecast_quantity'];
    $chart_current[] = (int)$p['current_stock'];
}

// ============ Sales Trend (30 days) ============
$sales_trend = mysqli_query($conn, "
    SELECT DATE(created_at) AS day, COALESCE(SUM(total_amount), 0) AS total
    FROM sales
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at) ORDER BY day ASC
");
$trend_labels = [];
$trend_values = [];
while ($t = mysqli_fetch_assoc($sales_trend)) {
    $trend_labels[] = date('d M', strtotime($t['day']));
    $trend_values[] = (float)$t['total'];
}

// ============ Forecast Accuracy ============
// Compare past forecasts with actual sales
$accuracy_data = mysqli_query($conn, "
    SELECT f.forecast_quantity, f.forecast_date, f.product_id,
           COALESCE((
               SELECT SUM(sd.quantity)
               FROM sale_details sd
               JOIN sales s ON sd.sale_id = s.id
               WHERE sd.product_id = f.product_id
               AND s.created_at >= f.forecast_date
               AND s.created_at < DATE_ADD(f.forecast_date, INTERVAL 30 DAY)
           ), 0) AS actual_sold
    FROM forecasts f
    WHERE f.forecast_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
    AND f.forecast_quantity > 0
    LIMIT 50
");

$total_error = 0;
$accuracy_count = 0;
while ($a = mysqli_fetch_assoc($accuracy_data)) {
    $diff = abs($a['forecast_quantity'] - $a['actual_sold']);
    $total_error += $diff / max($a['forecast_quantity'], 1);
    $accuracy_count++;
}

// Only show accuracy if we have actual sales data to compare
if ($accuracy_count > 0) {
    $accuracy = max(0, min(100, 100 - ($total_error / $accuracy_count) * 100));
    $accuracy_display = number_format($accuracy, 1) . '%';
    $accuracy_class = $accuracy >= 70 ? 'text-emerald-600' : ($accuracy >= 50 ? 'text-amber-600' : 'text-red-600');
} else {
    $accuracy = null;
    $accuracy_display = 'N/A';
    $accuracy_class = 'text-gray-400';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - <?= htmlspecialchars(getShopSettings($conn)['shop_name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include "../includes/theme-init.php"; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .stat-card {
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .animate-spin {
            animation: spin 0.8s linear infinite;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in {
            animation: fadeIn 0.4s ease-out both;
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
                        <button onclick="generateForecast()" id="generateBtn" class="btn btn-primary gap-2">
                            <svg id="btnIcon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                            <span id="btnText">Generate Forecast</span>
                        </button>
                    </div>

                    <?php if ($generated): ?>
                        <div class="mb-6 bg-emerald-50 border border-emerald-200 text-emerald-700 px-5 py-4 rounded-xl flex items-start gap-3 shadow-sm">
                            <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <span class="text-sm font-medium">Forecast generated successfully using Moving Average method.</span>
                        </div>
                    <?php endif; ?>

                    <?php if (!$has_forecast): ?>
                        <!-- No forecast yet -->
                        <div class="card mb-6">
                            <div class="card-body text-center py-16">
                                <div class="w-20 h-20 bg-indigo-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <svg class="w-10 h-10 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                                    </svg>
                                </div>
                                <h2 class="text-xl font-bold text-gray-800 dark:text-gray-200 mb-2">No Forecast Data</h2>
                                <p class="text-gray-500 dark:text-gray-400 mb-6 max-w-md mx-auto">Generate a forecast to predict future demand based on your sales history. The algorithm analyzes the last 30 days of sales data.</p>
                                <button onclick="generateForecast()" id="generateBtnEmpty" class="btn btn-primary btn-lg gap-2">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                    Generate Forecast Now
                                </button>
                            </div>
                        </div>
                    <?php else: ?>

                        <!-- Dashboard Stats -->
                        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                            <div class="stat-card bg-white rounded-xl border border-gray-200 p-5 fade-in">
                                <div class="flex items-center gap-3">
                                    <div class="w-11 h-11 bg-indigo-100 rounded-xl flex items-center justify-center flex-shrink-0">
                                        <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                        </svg>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">Expected Demand</p>
                                        <p class="text-xl font-bold text-indigo-600 mt-0.5"><?= number_format($total_forecast) ?></p>
                                        <p class="text-[11px] text-gray-400">units (30 days)</p>
                                    </div>
                                </div>
                            </div>
                            <div class="stat-card bg-white rounded-xl border border-gray-200 p-5 fade-in" style="animation-delay: 0.05s">
                                <div class="flex items-center gap-3">
                                    <div class="w-11 h-11 bg-red-100 rounded-xl flex items-center justify-center flex-shrink-0">
                                        <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                        </svg>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">High Demand</p>
                                        <p class="text-xl font-bold text-red-600 mt-0.5"><?= $high_demand ?></p>
                                        <p class="text-[11px] text-gray-400">products</p>
                                    </div>
                                </div>
                            </div>
                            <div class="stat-card bg-white rounded-xl border border-gray-200 p-5 fade-in" style="animation-delay: 0.1s">
                                <div class="flex items-center gap-3">
                                    <div class="w-11 h-11 bg-amber-100 rounded-xl flex items-center justify-center flex-shrink-0">
                                        <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                                        </svg>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">Need Restock</p>
                                        <p class="text-xl font-bold text-amber-600 mt-0.5"><?= $need_restock ?></p>
                                        <p class="text-[11px] text-gray-400">products</p>
                                    </div>
                                </div>
                            </div>
                            <div class="stat-card bg-white rounded-xl border border-gray-200 p-5 fade-in" style="animation-delay: 0.15s">
                                <div class="flex items-center gap-3">
                                    <div class="w-11 h-11 bg-emerald-100 rounded-xl flex items-center justify-center flex-shrink-0">
                                        <svg class="w-6 h-6 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">Forecast Accuracy</p>
                                        <p class="text-xl font-bold <?= $accuracy_class ?> mt-0.5"><?= $accuracy_display ?></p>
                                        <p class="text-[11px] text-gray-400"><?= $accuracy !== null ? 'based on history' : 'no data yet' ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Charts Row -->
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                            <div class="card">
                                <div class="card-header">
                                    <h2 class="text-base font-bold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                        <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                                        </svg>
                                        Sales Trend (30 Days)
                                    </h2>
                                </div>
                                <div class="card-body">
                                    <canvas id="salesTrendChart" height="130"></canvas>
                                </div>
                            </div>
                            <div class="card">
                                <div class="card-header">
                                    <h2 class="text-base font-bold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                        <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                        </svg>
                                        Product Demand Forecast
                                    </h2>
                                </div>
                                <div class="card-body">
                                    <?php if (count($chart_labels) > 0): ?>
                                        <canvas id="forecastChart" height="130"></canvas>
                                    <?php else: ?>
                                        <p class="text-center text-gray-400 py-8">No forecast data available</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- High Demand Products -->
                        <?php if (mysqli_num_rows($high_demand_products) > 0): ?>
                            <div class="card mb-6">
                                <div class="card-header">
                                    <h2 class="text-base font-bold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                        <div class="w-2 h-2 bg-red-500 rounded-full"></div>
                                        High Demand Products
                                    </h2>
                                    <span class="badge badge-danger"><span class="badge-dot"></span> <?= $high_demand ?> items</span>
                                </div>
                                <div class="table-wrap">
                                    <table class="data-table w-full">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Product</th>
                                                <th class="num">Current Stock</th>
                                                <th class="num">Forecast Qty</th>
                                                <th class="num">Recommended</th>
                                                <th class="center">Demand</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $hd_count = 1;
                                            while ($h = mysqli_fetch_assoc($high_demand_products)): 
                                                $rec_purch = max(0, $h['forecast_quantity'] - $h['current_stock']);
                                            ?>
                                                <tr>
                                                    <td class="text-gray-400 font-mono"><?= $hd_count++ ?></td>
                                                    <td class="font-semibold text-gray-900 dark:text-gray-100"><?= htmlspecialchars($h['product_name']) ?></td>
                                                    <td class="num <?= $h['current_stock'] <= $h['reorder_level'] ? 'text-red-600 font-bold' : 'text-gray-700 dark:text-gray-300' ?>"><?= $h['current_stock'] ?></td>
                                                    <td class="num font-bold text-red-600"><?= number_format($h['forecast_quantity']) ?></td>
                                                    <td class="num font-bold text-emerald-600"><?= number_format($rec_purch) ?></td>
                                                    <td class="center">
                                                        <span class="badge badge-danger"><span class="badge-dot"></span> <?= $h['demand_level'] ?></span>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Products Need Restock -->
                        <?php if (mysqli_num_rows($need_restock_products) > 0): ?>
                            <div class="card mb-6">
                                <div class="card-header">
                                    <h2 class="text-base font-bold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                        <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                                        </svg>
                                        Products Need Restock
                                    </h2>
                                    <span class="badge badge-warning"><span class="badge-dot"></span> <?= $need_restock ?> items</span>
                                </div>
                                <div class="table-wrap">
                                    <table class="data-table w-full">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Product</th>
                                                <th class="num">Current Stock</th>
                                                <th class="num">Min Stock</th>
                                                <th class="num">Forecast Demand</th>
                                                <th class="num">Recommended</th>
                                                <th class="num">Shortage</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $rs_count = 1;
                                            while ($l = mysqli_fetch_assoc($need_restock_products)):
                                                $rec_purch = max(0, $l['forecast_quantity'] - $l['current_stock']);
                                                $shortage = $rec_purch;
                                            ?>
                                                <tr>
                                                    <td class="text-gray-400 font-mono"><?= $rs_count++ ?></td>
                                                    <td class="font-semibold text-gray-900 dark:text-gray-100"><?= htmlspecialchars($l['product_name']) ?></td>
                                                    <td class="num <?= $l['current_stock'] <= $l['reorder_level'] ? 'text-red-600 font-bold' : 'text-gray-700 dark:text-gray-300' ?>"><?= $l['current_stock'] ?></td>
                                                    <td class="num text-gray-500 dark:text-gray-400"><?= $l['reorder_level'] ?></td>
                                                    <td class="num font-semibold text-amber-600"><?= number_format($l['forecast_quantity']) ?></td>
                                                    <td class="num font-semibold text-emerald-600"><?= number_format($rec_purch) ?></td>
                                                    <td class="num font-bold text-red-600"><?= number_format($shortage) ?></td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- All Product Forecasts -->
                        <div class="card">
                            <div class="card-header">
                                <h2 class="text-base font-bold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                    <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                    </svg>
                                    All Product Forecasts
                                </h2>
                            </div>
                            <div class="table-wrap">
                                <table class="data-table w-full">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Product Name</th>
                                            <th class="num">Current Stock</th>
                                            <th class="num">Forecast Quantity</th>
                                            <th class="num">Recommended Purchase Qty</th>
                                            <th class="center">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $all_forecasts = mysqli_query($conn, "
                                        SELECT p.product_name, p.current_stock, p.reorder_level, f.forecast_quantity, f.demand_level, f.recommended_stock
                                        FROM forecasts f
                                        JOIN products p ON f.product_id = p.id
                                        WHERE f.forecast_date = CURDATE()
                                        ORDER BY f.forecast_quantity DESC
                                    ");
                                        $af_count = 1;
                                        if (mysqli_num_rows($all_forecasts) > 0): while ($f = mysqli_fetch_assoc($all_forecasts)):
                                                $current_stock = (int)$f['current_stock'];
                                                $reorder_level = (int)$f['reorder_level'];
                                                $forecast_qty = (int)$f['forecast_quantity'];
                                                $recommended_purchase = max(0, $forecast_qty - $current_stock);
                                                $insufficient = ($f['demand_level'] === 'Insufficient');
                                                $needs_reorder = ($current_stock <= $reorder_level) && !$insufficient;
                                        ?>
                                                <tr class="<?= $needs_reorder ? 'bg-red-50 dark:bg-red-900/10' : '' ?>">
                                                    <td class="text-gray-400 font-mono"><?= $af_count++ ?></td>
                                                    <td class="font-semibold text-gray-900 dark:text-gray-100"><?= htmlspecialchars($f['product_name']) ?></td>
                                                    <td class="num <?= $current_stock < 5 ? 'text-red-600 font-bold' : 'text-gray-700 dark:text-gray-300' ?>"><?= $current_stock ?></td>
                                                    <?php if ($insufficient): ?>
                                                        <td colspan="2" class="text-center text-gray-500 text-sm italic py-2">Insufficient historical data</td>
                                                    <?php else: ?>
                                                        <td class="num font-semibold"><?= number_format($forecast_qty) ?></td>
                                                        <td class="num font-bold <?= $needs_reorder ? 'text-red-600' : 'text-gray-500' ?>"><?= number_format($recommended_purchase) ?></td>
                                                    <?php endif; ?>
                                                    <td class="center">
                                                        <?php if ($insufficient): ?>
                                                            <span class="badge badge-secondary" style="background-color: #f3f4f6; color: #6b7280;"><span class="badge-dot" style="background-color: #9ca3af;"></span> No Data</span>
                                                        <?php elseif ($needs_reorder): ?>
                                                            <span class="badge badge-danger"><span class="badge-dot"></span> Reorder Required</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-success"><span class="badge-dot"></span> Enough Stock</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endwhile;
                                        else: ?>
                                            <tr>
                                                <td colspan="6" class="text-center py-8 text-gray-400">No forecast data. Click "Generate Forecast" to start.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <?php include "../includes/toast.php"; ?>
    <?php include "../includes/footer.php"; ?>

    <script>
        // Generate Forecast via AJAX
        function generateForecast() {
            const btn = document.getElementById('generateBtn') || document.getElementById('generateBtnEmpty');
            const btnText = document.getElementById('btnText');
            const btnIcon = document.getElementById('btnIcon');

            if (btn) {
                btn.disabled = true;
                if (btnText) btnText.textContent = 'Generating...';
                if (btnIcon) btnIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>';
                btnIcon.classList.add('animate-spin');
            }

            fetch('?action=generate', {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showToast('success', 'Forecast generated successfully!');
                        setTimeout(() => {
                            window.location.href = 'index.php?generated=1';
                        }, 800);
                    } else {
                        showToast('error', 'Failed to generate forecast.');
                        if (btn) btn.disabled = false;
                        if (btnText) btnText.textContent = 'Generate Forecast';
                        if (btnIcon) btnIcon.classList.remove('animate-spin');
                    }
                })
                .catch(() => {
                    showToast('error', 'An error occurred. Please try again.');
                    if (btn) btn.disabled = false;
                    if (btnText) btnText.textContent = 'Generate Forecast';
                    if (btnIcon) btnIcon.classList.remove('animate-spin');
                });
        }

        // Toast helper (fallback if toast.php doesn't have showToast)
        function showToast(type, message) {
            const toast = document.createElement('div');
            const bg = type === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-red-50 border-red-200 text-red-700';
            const icon = type === 'success' ?
                '<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>' :
                '<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
            toast.className = `${bg} border px-5 py-4 rounded-xl flex items-center gap-3 shadow-lg fixed top-4 right-4 z-[100] max-w-sm`;
            toast.innerHTML = `${icon}<span class="text-sm font-medium">${message}</span>`;
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transition = 'opacity 0.3s';
            }, 3000);
            setTimeout(() => toast.remove(), 3500);
        }

        <?php if ($has_forecast): ?>
            // Sales Trend Chart
            const trendCtx = document.getElementById('salesTrendChart').getContext('2d');
            new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: <?= json_encode($trend_labels) ?>,
                    datasets: [{
                        label: 'Sales Revenue',
                        data: <?= json_encode($trend_values) ?>,
                        borderColor: '#4f46e5',
                        backgroundColor: 'rgba(79, 70, 229, 0.08)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 3,
                        pointBackgroundColor: '#4f46e5',
                        borderWidth: 2,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => ctx.dataset.label + ': ' + Number(ctx.raw).toLocaleString() + ' Ks'
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0,0,0,0.05)'
                            },
                            ticks: {
                                callback: v => v.toLocaleString() + ' Ks'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                maxTicksLimit: 15
                            }
                        }
                    }
                }
            });

            <?php if (count($chart_labels) > 0): ?>
                // Product Demand Forecast Chart
                const forecastCtx = document.getElementById('forecastChart').getContext('2d');
                new Chart(forecastCtx, {
                    type: 'bar',
                    data: {
                        labels: <?= json_encode($chart_labels) ?>,
                        datasets: [{
                                label: 'Current Stock',
                                data: <?= json_encode($chart_current) ?>,
                                backgroundColor: 'rgba(99, 102, 241, 0.7)',
                                borderColor: '#6366f1',
                                borderWidth: 1,
                                borderRadius: 4,
                            },
                            {
                                label: 'Forecast Demand',
                                data: <?= json_encode($chart_forecast) ?>,
                                backgroundColor: 'rgba(245, 158, 11, 0.7)',
                                borderColor: '#f59e0b',
                                borderWidth: 1,
                                borderRadius: 4,
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: {
                                    boxWidth: 12,
                                    padding: 10
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: (ctx) => ctx.dataset.label + ': ' + Number(ctx.raw).toLocaleString() + ' units'
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: 'rgba(0,0,0,0.05)'
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                },
                                ticks: {
                                    maxTicksLimit: 10
                                }
                            }
                        }
                    }
                });
            <?php endif; ?>
        <?php endif; ?>
    </script>
</body>

</html>