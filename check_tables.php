<?php
$tables = ['categories', 'units', 'suppliers', 'users', 'products', 'purchases', 'purchase_details', 'purchase_payments', 'sales', 'sale_details', 'sale_payments', 'settings', 'forecasts', 'supplier_ledger', 'supplier_payments', 'password_resets'];

$dir = new RecursiveDirectoryIterator('c:\wamp64\www\inventory');
$ite = new RecursiveIteratorIterator($dir);
$files = new RegexIterator($ite, '/^.+\.php$/i', RecursiveRegexIterator::GET_MATCH);

$counts = array_fill_keys($tables, 0);

foreach ($files as $file) {
    $content = file_get_contents($file[0]);
    foreach ($tables as $table) {
        if (strpos($content, $table) !== false) {
            $counts[$table]++;
        }
    }
}

print_r($counts);
