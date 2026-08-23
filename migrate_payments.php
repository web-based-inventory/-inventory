<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/supplier_ledger.php';

// Only run if supplier_payments exists
if (!columnExists($conn, 'supplier_payments', 'supplier_id')) {
    echo "supplier_payments table does not exist or missing supplier_id.\n";
    exit;
}

$amtCol = getPaymentAmountCol($conn, 'purchase_payments');

// Add a column to track migration to prevent double migration
if (!columnExists($conn, 'purchase_payments', 'migrated_to_sp')) {
    mysqli_query($conn, "ALTER TABLE purchase_payments ADD COLUMN migrated_to_sp TINYINT(1) DEFAULT 0");
}

$query = "SELECT pp.*, pu.supplier_id, pu.invoice_no 
          FROM purchase_payments pp 
          INNER JOIN purchases pu ON pp.purchase_id = pu.id 
          WHERE pp.$amtCol > 0 AND pp.migrated_to_sp = 0";

$result = mysqli_query($conn, $query);

if (!$result) {
    echo "Error querying purchase_payments: " . mysqli_error($conn) . "\n";
    exit;
}

$migrated = 0;
$skipped = 0;
while ($row = mysqli_fetch_assoc($result)) {
    $supplier_id = $row['supplier_id'];
    $method = $row['payment_method'];
    $cash = isset($row['cash_amount']) ? (float)$row['cash_amount'] : 0;
    $kbz = isset($row['kbzpay_amount']) ? (float)$row['kbzpay_amount'] : 0;
    $paid = (float)$row[$amtCol];
    $date = $row['payment_date'];
    $ref_no = $row['invoice_no'];

    // Idempotency guard: skip if this exact payment already exists in
    // supplier_payments (same supplier, ref, amount and date). Prevents the
    // same real payment from ever being recorded twice.
    $dup_check_sql = "SELECT COUNT(*) AS c FROM supplier_payments
        WHERE supplier_id = $supplier_id AND ref_no = '" . mysqli_real_escape_string($conn, $ref_no) . "'
          AND ABS(paid_amount - $paid) < 0.01 AND payment_date = '$date'";
    $dup = mysqli_fetch_assoc(mysqli_query($conn, $dup_check_sql));
    if ($dup && (int)$dup['c'] > 0) {
        mysqli_query($conn, "UPDATE purchase_payments SET migrated_to_sp = 1 WHERE id = {$row['id']}");
        $skipped++;
        continue;
    }

    $hasNotesSP = columnExists($conn, 'supplier_payments', 'notes');

    $insert_sql = "";
    if ($hasNotesSP) {
        $insert_sql = "INSERT INTO supplier_payments 
            (supplier_id, payment_method, cash_amount, kbzpay_amount, paid_amount, ref_no, payment_date, notes)
            VALUES ($supplier_id, '$method', $cash, $kbz, $paid, '$ref_no', '$date', '" . mysqli_real_escape_string($conn, $notes) . "')";
    } else {
        $insert_sql = "INSERT INTO supplier_payments 
            (supplier_id, payment_method, cash_amount, kbzpay_amount, paid_amount, ref_no, payment_date)
            VALUES ($supplier_id, '$method', $cash, $kbz, $paid, '$ref_no', '$date')";
    }

    if (mysqli_query($conn, $insert_sql)) {
        $pp_id = $row['id'];
        mysqli_query($conn, "UPDATE purchase_payments SET migrated_to_sp = 1 WHERE id = $pp_id");
        $migrated++;
    } else {
        echo "Failed to migrate payment ID {$row['id']}: " . mysqli_error($conn) . "\n";
    }
}

// Recalculate all supplier balances so the ledger is accurate
recalcAllSupplierBalances($conn);

echo "Successfully migrated $migrated payments to supplier_payments ($skipped skipped as already existing).\n";
