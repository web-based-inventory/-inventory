<?php
include "../includes/auth_check.php";
include "../config/database.php";

header('Content-Type: application/json');

$category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;

if ($category_id > 0) {
    // Get category name
    $cat_res = mysqli_query($conn, "SELECT name FROM categories WHERE id=$category_id");
    if ($cat_res && mysqli_num_rows($cat_res) > 0) {
        $cat = mysqli_fetch_assoc($cat_res);
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $cat['name']), 0, 3));
        if (strlen($prefix) < 3) {
            $prefix = str_pad($prefix, 3, 'X');
        }
        
        // Find highest number for this prefix
        $sku_res = mysqli_query($conn, "SELECT sku FROM products WHERE sku LIKE '{$prefix}%' ORDER BY sku DESC LIMIT 1");
        if ($sku_res && mysqli_num_rows($sku_res) > 0) {
            $last_sku = mysqli_fetch_assoc($sku_res)['sku'];
            // Extract numbers from the end
            preg_match('/(\d+)$/', $last_sku, $matches);
            if(isset($matches[1])) {
                $new_num = (int)$matches[1] + 1;
            } else {
                $new_num = 1;
            }
        } else {
            $new_num = 1;
        }
        
        $new_sku = $prefix . str_pad($new_num, 4, '0', STR_PAD_LEFT);
        
        // Final check to guarantee uniqueness
        while(true) {
            $check = mysqli_query($conn, "SELECT id FROM products WHERE sku='$new_sku'");
            if (mysqli_num_rows($check) == 0) {
                break;
            }
            $new_num++;
            $new_sku = $prefix . str_pad($new_num, 4, '0', STR_PAD_LEFT);
        }
        
        echo json_encode(['success' => true, 'sku' => $new_sku]);
        exit;
    }
}

// Fallback random SKU
$new_sku = 'SKU' . mt_rand(10000, 99999);

// Final check for random SKU
while(true) {
    $check = mysqli_query($conn, "SELECT id FROM products WHERE sku='$new_sku'");
    if (mysqli_num_rows($check) == 0) {
        break;
    }
    $new_sku = 'SKU' . mt_rand(10000, 99999);
}

echo json_encode(['success' => true, 'sku' => $new_sku]);
exit;
