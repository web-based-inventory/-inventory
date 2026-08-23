<?php
include "../includes/auth_check.php";
requirePermission('forecast', 'view');
include "../config/database.php";
include "../config/helpers.php";

$page_title = "Forecast Evaluation";

// 1. Completed Forecasts (Evaluation period ended)
$completed_forecasts_query = mysqli_query($conn, "
    SELECT f.id, f.forecast_quantity, f.forecast_date, f.product_id, p.product_name,
           DATE_ADD(f.forecast_date, INTERVAL 30 DAY) AS evaluation_end,
           COALESCE((
               SELECT SUM(sd2.quantity)
               FROM sale_details sd2
               JOIN sales s2 ON sd2.sale_id = s2.id
               WHERE sd2.product_id = f.product_id
                 AND DATE(s2.created_at) >= f.forecast_date
                 AND DATE(s2.created_at) <  DATE_ADD(f.forecast_date, INTERVAL 30 DAY)
           ), 0) AS actual_sold
    FROM forecasts f
    JOIN products p ON f.product_id = p.id
    WHERE DATE_ADD(f.forecast_date, INTERVAL 30 DAY) <= CURDATE()
      AND f.forecast_date >= '2026-07-20'
    ORDER BY f.forecast_date DESC
");

$total_accuracy = 0;
$accuracy_count = 0;
$best_accuracy = 0;
$completed_forecasts = [];

while ($c = mysqli_fetch_assoc($completed_forecasts_query)) {
    $forecast_qty = (float)$c['forecast_quantity'];
    $actual_sold  = (float)$c['actual_sold'];
    $error = abs($actual_sold - $forecast_qty);

    if ($forecast_qty == 0 && $actual_sold == 0) {
        $error_percentage = 0;
        $acc = 100;
    } elseif ($forecast_qty > 0 && $actual_sold == 0) {
        $error_percentage = 100;
        $acc = 0;
    } elseif ($actual_sold > 0) {
        $error_percentage = ($error / $actual_sold) * 100;
        $acc = max(0, 100 - $error_percentage);
    } else {
        $error_percentage = 100;
        $acc = 0;
    }

    $c['error']           = $error;
    $c['error_percentage'] = $error_percentage;
    $c['accuracy']        = $acc;
    $completed_forecasts[] = $c;

    $total_accuracy += $acc;
    $accuracy_count++;

    if ($acc > $best_accuracy) {
        $best_accuracy = $acc;
    }
}

$average_accuracy = $accuracy_count > 0 ? ($total_accuracy / $accuracy_count) : 0;

// 2. Pending Forecasts (Evaluation period ongoing)
$pending_forecasts = [];
$pending_query = mysqli_query($conn, "
    SELECT f.id, f.forecast_quantity, f.forecast_date, f.product_id, p.product_name,
           DATE_ADD(f.forecast_date, INTERVAL 30 DAY) AS evaluation_end
    FROM forecasts f
    JOIN products p ON f.product_id = p.id
    WHERE DATE_ADD(f.forecast_date, INTERVAL 30 DAY) > CURDATE()
      AND f.forecast_date >= '2026-07-20'
    ORDER BY f.forecast_date DESC
");
while ($p = mysqli_fetch_assoc($pending_query)) {
    $pending_forecasts[] = $p;
}

$pending_count = count($pending_forecasts);

// Combine completed and pending forecasts for the report
$all_evaluations = array_merge($completed_forecasts, $pending_forecasts);
usort($all_evaluations, function ($a, $b) {
    return strtotime($b['forecast_date']) - strtotime($a['forecast_date']);
});

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
    <style>
        .fade-in {
            animation: fadeIn 0.4s ease-out both;
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
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-900">
    <div class="flex min-h-screen">
        <?php include "../includes/sidebar.php"; ?>
        <div class="flex-1 flex flex-col">
            <?php include "../includes/header.php"; ?>
            <main class="p-4 lg:p-6">
                <div class="max-w-7xl mx-auto">
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
                        <div>
                            <div class="flex items-center gap-2">
                                <a href="index.php" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                                    </svg>
                                </a>
                                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Forecast Evaluation</h1>
                            </div>
                            <p class="text-gray-500 dark:text-gray-400 mt-1 ml-7">Compare forecast performance against actual sales.</p>
                        </div>
                    </div>

                    <!-- Summary Cards -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 lg:gap-5 mb-6">
                        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 p-5 fade-in">
                            <div class="flex items-center gap-3">
                                <div class="w-11 h-11 bg-emerald-100 dark:bg-emerald-900/30 rounded-xl flex items-center justify-center flex-shrink-0">
                                    <svg class="w-6 h-6 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">Evaluated</p>
                                    <p class="text-xl font-bold text-gray-900 dark:text-white mt-0.5">N/A</p>
                                    <p class="text-[11px] text-gray-400">Forecasts Completed</p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 p-5 fade-in" style="animation-delay: 0.05s">
                            <div class="flex items-center gap-3">
                                <div class="w-11 h-11 bg-amber-100 dark:bg-amber-900/30 rounded-xl flex items-center justify-center flex-shrink-0">
                                    <svg class="w-6 h-6 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">Pending</p>
                                    <p class="text-xl font-bold text-gray-900 dark:text-white mt-0.5"><?= $pending_count ?></p>
                                    <p class="text-[11px] text-gray-400">Period Not Complete</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Evaluation Table -->
                    <div class="card fade-in" style="animation-delay: 0.2s">
                        <div class="table-wrap">
                            <table class="data-table w-full">
                                <thead>
                                    <tr>
                                        <th>Product Name</th>
                                        <th>Forecast Date</th>
                                        <th>Evaluation End</th>
                                        <th class="num">Forecast Qty</th>
                                        <th class="num">Actual Sold</th>
                                        <th class="num">Difference</th>
                                        <th class="num">Accuracy</th>
                                        <th class="center">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($all_evaluations) > 0): ?>
                                        <?php foreach ($all_evaluations as $c):
                                            $is_completed = isset($c['actual_sold']);
                                        ?>
                                            <tr>
                                                <td class="font-semibold text-gray-900 dark:text-gray-100"><?= htmlspecialchars($c['product_name']) ?></td>
                                                <td class="text-gray-500 dark:text-gray-400"><?= date('Y-m-d', strtotime($c['forecast_date'])) ?></td>
                                                <td class="text-gray-500 dark:text-gray-400"><?= date('Y-m-d', strtotime($c['evaluation_end'])) ?></td>
                                                <td class="num font-semibold text-indigo-600"><?= number_format($c['forecast_quantity']) ?></td>

                                                <?php if ($is_completed): ?>
                                                    <td class="num font-semibold text-emerald-600"><?= number_format($c['actual_sold']) ?></td>
                                                    <td class="num text-gray-700 dark:text-gray-300"><?= number_format($c['error']) ?></td>
                                                    <td class="num font-bold <?= $c['accuracy'] >= 70 ? 'text-emerald-600' : ($c['accuracy'] >= 50 ? 'text-amber-600' : 'text-red-600') ?>">
                                                        <?= number_format($c['accuracy'], 2) ?>%
                                                    </td>
                                                    <td class="center">
                                                        <span class="badge badge-success"><span class="badge-dot"></span> Evaluated</span>
                                                    </td>
                                                <?php else: ?>
                                                    <td class="num text-gray-400">N/A</td>
                                                    <td class="num text-gray-400">N/A</td>
                                                    <td class="num text-gray-400">Pending</td>
                                                    <td class="center">
                                                        <span class="badge badge-warning"><span class="badge-dot"></span> Pending</span>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-10 text-gray-400">No evaluations available.</td>
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
    <?php include "../includes/toast.php"; ?>
    <?php include "../includes/footer.php"; ?>
</body>

</html>