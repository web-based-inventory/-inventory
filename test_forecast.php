<?php
include "config/database.php";

$query = "
    SELECT p.id, p.product_name, SUM(sd.quantity) as total_qty
    FROM sale_details sd
    JOIN sales s ON sd.sale_id = s.id
    JOIN products p ON sd.product_id = p.id
    WHERE s.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    AND s.created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
    GROUP BY p.id
    ORDER BY total_qty DESC
    LIMIT 1
";
$result = $conn->query($query);
if ($row = $result->fetch_assoc()) {
    $product_id = $row['id'];
    echo "Found Product: " . $row['product_name'] . " (ID: $product_id)\n";
    echo "Total actual quantity in the last 30 days = " . $row['total_qty'] . " units\n";
    
    $daily_avg = $row['total_qty'] / 30;
    echo "Daily Average = " . $row['total_qty'] . " / 30 = " . round($daily_avg, 4) . " units/day\n";
    
    $forecast_7 = round($daily_avg * 7);
    echo "7-Day Forecast = " . round($daily_avg, 4) . " * 7 = " . $forecast_7 . " units\n";
    
    $forecast_30 = round($daily_avg * 30);
    echo "30-Day Forecast = " . round($daily_avg, 4) . " * 30 = " . $forecast_30 . " units\n";
} else {
    echo "No recent sales found for any product.\n";
}
