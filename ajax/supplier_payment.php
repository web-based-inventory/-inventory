<?php
/**
 * Supplier Payment Handler
 * Handles direct payments to suppliers with proper ledger entries and balance updates.
 */
session_start();
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

include "../config/database.php";
include "../config/helpers.php";

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get input
$supplier_id    = (int)($_POST['supplier_id'] ?? 0);
$payment_method = trim($_POST['payment_method'] ?? '');
$payment_date   = trim($_POST['payment_date'] ?? '');
$cash_amount    = (float)($_POST['cash_amount'] ?? 0);
$kbzpay_amount  = (float)($_POST['kbzpay_amount'] ?? 0);
$paid_amount    = (float)($_POST['paid_amount'] ?? 0);
$notes          = trim($_POST['notes'] ?? '');

// ── Validation ──
if ($supplier_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid supplier ID']);
    exit;
}

if (!in_array($payment_method, ['Cash', 'KBZPay', 'Mixed'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid payment method']);
    exit;
}

if (empty($payment_date)) {
    echo json_encode(['success' => false, 'message' => 'Payment date is required']);
    exit;
}

if ($paid_amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Payment amount must be greater than zero']);
    exit;
}

// Validate split amounts for Mixed payment
if ($payment_method === 'Mixed') {
    $total_split = $cash_amount + $kbzpay_amount;
    if (abs($total_split - $paid_amount) > 0.01) {
        echo json_encode(['success' => false, 'message' => 'Cash + KBZPay amounts must equal the paid amount']);
        exit;
    }
} elseif ($payment_method === 'Cash') {
    $cash_amount = $paid_amount;
    $kbzpay_amount = 0;
} elseif ($payment_method === 'KBZPay') {
    $kbzpay_amount = $paid_amount;
    $cash_amount = 0;
}

// ── Verify supplier exists ──
$supplier = fetchOne($conn, "SELECT * FROM suppliers WHERE id = ? AND status = 'Active'", [$supplier_id], "i");
if (!$supplier) {
    echo json_encode(['success' => false, 'message' => 'Supplier not found or inactive']);
    exit;
}

// Recalculate balances from real-time data so advance/outstanding are current
recalcSupplierBalance($conn, $supplier_id);
$supplier = fetchOne($conn, "SELECT * FROM suppliers WHERE id = ? AND status = 'Active'", [$supplier_id], "i");

$current_advance = (float)($supplier['advance_credit'] ?? $supplier['advance_balance'] ?? 0);
$current_outstanding = (float)($supplier['outstanding_balance'] ?? 0);

// Ensure supplier_ledger table exists and purchases has payment columns
createSupplierLedgerTable($conn);
ensurePurchasePaymentColumns($conn);

// ── Get table structure for debugging ──
$table_check = [];
$pp_cols = [];
$pp_result = mysqli_query($conn, "SHOW COLUMNS FROM purchase_payments");
if ($pp_result) {
    while ($col = mysqli_fetch_assoc($pp_result)) {
        $pp_cols[] = $col['Field'];
    }
}
$table_check['purchase_payments_columns'] = $pp_cols;

$sp_cols = [];
if (columnExists($conn, 'supplier_payments', 'supplier_id')) {
    $sp_result = mysqli_query($conn, "SHOW COLUMNS FROM supplier_payments");
    if ($sp_result) {
        while ($col = mysqli_fetch_assoc($sp_result)) {
            $sp_cols[] = $col['Field'];
        }
    }
}
$table_check['supplier_payments_columns'] = $sp_cols;

// ── Get unpaid purchases for this supplier (oldest first for FIFO) ──
$amtCol = getPaymentAmountCol($conn, 'purchase_payments');
$unpaid_sql = "
    SELECT p.id, p.invoice_no, p.total_amount,
           COALESCE(pp.total_paid, 0) AS total_paid,
           (p.total_amount - COALESCE(pp.total_paid, 0)) AS remaining
    FROM purchases p
    LEFT JOIN (
        SELECT purchase_id, SUM($amtCol + COALESCE(advance_applied, 0)) AS total_paid
        FROM purchase_payments
        GROUP BY purchase_id
    ) pp ON p.id = pp.purchase_id
    WHERE p.supplier_id = $supplier_id
      AND (p.total_amount - COALESCE(pp.total_paid, 0)) > 0.01
    ORDER BY p.purchase_date ASC, p.id ASC
";

$unpaid_result = $conn->query($unpaid_sql);
if (!$unpaid_result) {
    echo json_encode(['success' => false, 'message' => 'Failed to fetch unpaid purchases: ' . $conn->error]);
    exit;
}

$unpaid_purchases = [];
while ($row = $unpaid_result->fetch_assoc()) {
    $unpaid_purchases[] = $row;
}

// ── Calculate total outstanding ──
$total_outstanding = 0;
foreach ($unpaid_purchases as $up) {
    $total_outstanding += (float)$up['remaining'];
}

// ── Start transaction ──
$conn->begin_transaction();

$debug_log = [];
$debug_log[] = "=== PAYMENT DEBUG START ===";
$debug_log[] = "supplier_id: $supplier_id";
$debug_log[] = "payment_method: $payment_method";
$debug_log[] = "payment_date: $payment_date";
$debug_log[] = "paid_amount: $paid_amount";
$debug_log[] = "cash_amount: $cash_amount";
$debug_log[] = "kbzpay_amount: $kbzpay_amount";
$debug_log[] = "notes: '$notes'";
$debug_log[] = "notes_empty: " . (empty($notes) ? 'YES' : 'NO');
$debug_log[] = "current_advance: $current_advance";
$debug_log[] = "unpaid_count: " . count($unpaid_purchases);
$debug_log[] = "amtCol: $amtCol";
$debug_log[] = "has_cash_amount_col: " . (columnExists($conn, 'purchase_payments', 'cash_amount') ? 'YES' : 'NO');
$debug_log[] = "has_notes_col: " . (columnExists($conn, 'purchase_payments', 'notes') ? 'YES' : 'NO');
$debug_log[] = "table_check: " . json_encode($table_check);

try {
    $remaining_to_pay = $paid_amount;
    $advance_applied = 0;
    $advance_created = 0;
    $payments_made = [];
    $payment_datetime = date('Y-m-d H:i:s', strtotime($payment_date));
    $payment_ref_no = 'DP-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

    $debug_log[] = "payment_datetime: $payment_datetime";
    $debug_log[] = "payment_ref_no: $payment_ref_no";

    // Step 1: Apply existing advance to unpaid purchases first
    if ($current_advance > 0.01 && count($unpaid_purchases) > 0) {
        $advance_remaining = $current_advance;
        
        foreach ($unpaid_purchases as &$up) {
            if ($advance_remaining <= 0.01) break;
            
            $purchase_remaining = (float)$up['remaining'];
            if ($purchase_remaining <= 0.01) continue;
            
            $apply_now = min($advance_remaining, $purchase_remaining);
            if ($apply_now <= 0.01) continue;
            
            $new_total_paid = (float)$up['total_paid'] + $apply_now;
            $new_remaining = max(0, (float)$up['total_amount'] - $new_total_paid);
            $new_status = ($new_remaining <= 0.01) ? 'Paid' : 'Partial';
            
            // Build INSERT query for advance payment
            $escaped_advance_notes = 'Advance applied from supplier credit';
            $has_cash = columnExists($conn, 'purchase_payments', 'cash_amount');
            $has_notes = columnExists($conn, 'purchase_payments', 'notes');
            
            if ($has_cash && $has_notes) {
                $sql_advance = "INSERT INTO purchase_payments 
                    (purchase_id, payment_method, cash_amount, kbzpay_amount, paid_amount, advance_applied, advance_created, remaining_balance, payment_status, payment_date, notes)
                    VALUES ({$up['id']}, 'Cash', 0, 0, 0, $apply_now, 0, $new_remaining, '$new_status', '$payment_datetime', '$escaped_advance_notes')";
            } elseif ($has_cash) {
                $sql_advance = "INSERT INTO purchase_payments 
                    (purchase_id, payment_method, cash_amount, kbzpay_amount, paid_amount, advance_applied, advance_created, remaining_balance, payment_status, payment_date)
                    VALUES ({$up['id']}, 'Cash', 0, 0, 0, $apply_now, 0, $new_remaining, '$new_status', '$payment_datetime')";
            } else {
                $sql_advance = "INSERT INTO purchase_payments 
                    (purchase_id, payment_method, $amtCol, advance_applied, remaining_balance, payment_status, payment_date)
                    VALUES ({$up['id']}, 'Cash', 0, $apply_now, $new_remaining, '$new_status', '$payment_datetime')";
            }
            
            $debug_log[] = "STEP1_ADVANCE_SQL: $sql_advance";
            $result = $conn->query($sql_advance);
            if (!$result) {
                $debug_log[] = "STEP1_ADVANCE_ERROR: " . $conn->error;
                throw new Exception("Failed to insert advance payment: " . $conn->error);
            }

            // Update the purchase's payment tracking columns
            updatePurchasePaymentStatus($conn, $up['id']);
            
            $advance_applied += $apply_now;
            $advance_remaining -= $apply_now;
            $up['total_paid'] = $new_total_paid;
            $up['remaining'] = $new_remaining;
        }
        unset($up);
    }

    // Step 2: Apply new cash payment to remaining unpaid purchases (FIFO)
    if ($remaining_to_pay > 0.01 && count($unpaid_purchases) > 0) {
        foreach ($unpaid_purchases as &$up) {
            if ($remaining_to_pay <= 0.01) break;
            
            $purchase_remaining = (float)$up['remaining'];
            if ($purchase_remaining <= 0.01) continue;
            
            $apply_now = min($remaining_to_pay, $purchase_remaining);
            if ($apply_now <= 0.01) continue;
            
            $new_total_paid = (float)$up['total_paid'] + $apply_now;
            $new_remaining = max(0, (float)$up['total_amount'] - $new_total_paid);
            $new_status = ($new_remaining <= 0.01) ? 'Paid' : 'Partial';
            
            // Determine cash/kbzpay split for this portion
            if ($payment_method === 'Mixed') {
                $ratio = ($paid_amount > 0) ? $apply_now / $paid_amount : 0;
                $cash_for_this = round($cash_amount * $ratio, 2);
                $kbzpay_for_this = round($apply_now - $cash_for_this, 2);
            } elseif ($payment_method === 'KBZPay') {
                $cash_for_this = 0;
                $kbzpay_for_this = $apply_now;
            } else {
                $cash_for_this = $apply_now;
                $kbzpay_for_this = 0;
            }
            
            // Build INSERT query for payment
            $escaped_notes = $conn->real_escape_string($notes);
            $has_cash = columnExists($conn, 'purchase_payments', 'cash_amount');
            $has_notes = columnExists($conn, 'purchase_payments', 'notes');
            
            if ($has_cash && $has_notes) {
                $notes_sql_val = empty($notes) ? "''" : "'$escaped_notes'";
                $sql_payment = "INSERT INTO purchase_payments 
                    (purchase_id, payment_method, cash_amount, kbzpay_amount, $amtCol, advance_applied, advance_created, remaining_balance, payment_status, payment_date, notes)
                    VALUES ({$up['id']}, '$payment_method', $cash_for_this, $kbzpay_for_this, $apply_now, 0, 0, $new_remaining, '$new_status', '$payment_datetime', $notes_sql_val)";
            } elseif ($has_cash) {
                $sql_payment = "INSERT INTO purchase_payments 
                    (purchase_id, payment_method, cash_amount, kbzpay_amount, $amtCol, advance_applied, advance_created, remaining_balance, payment_status, payment_date)
                    VALUES ({$up['id']}, '$payment_method', $cash_for_this, $kbzpay_for_this, $apply_now, 0, 0, $new_remaining, '$new_status', '$payment_datetime')";
            } else {
                $sql_payment = "INSERT INTO purchase_payments 
                    (purchase_id, payment_method, $amtCol, advance_applied, remaining_balance, payment_status, payment_date)
                    VALUES ({$up['id']}, '$payment_method', $apply_now, 0, $new_remaining, '$new_status', '$payment_datetime')";
            }
            
            $debug_log[] = "STEP2_PAYMENT_SQL: $sql_payment";
            $result = $conn->query($sql_payment);
            if (!$result) {
                $debug_log[] = "STEP2_PAYMENT_ERROR: " . $conn->error;
                throw new Exception("Failed to insert payment: " . $conn->error);
            }

            // Update the purchase's payment tracking columns
            updatePurchasePaymentStatus($conn, $up['id']);

            $payments_made[] = [
                'invoice_no' => $up['invoice_no'],
                'amount' => $apply_now,
                'status' => $new_status
            ];
            
            $remaining_to_pay -= $apply_now;
        }
        unset($up);
    }

    // Step 3: If there's still remaining payment, store as advance
    if ($remaining_to_pay > 0.01) {
        $advance_created = $remaining_to_pay;
    }

    // Step 4: Record the FULL cash payment in supplier_payments
    // (This acts as the single source of truth for the financial transaction)
    if ($paid_amount > 0.01 && columnExists($conn, 'supplier_payments', 'supplier_id')) {
        $escaped_notes = $conn->real_escape_string($notes);
        $has_notes = columnExists($conn, 'supplier_payments', 'notes');
        
        if ($has_notes) {
            $notes_sql_val = empty($notes) ? "''" : "'$escaped_notes'";
            $sql_direct = "INSERT INTO supplier_payments 
                (supplier_id, payment_method, cash_amount, kbzpay_amount, paid_amount, ref_no, payment_date, notes)
                VALUES ($supplier_id, '$payment_method', $cash_amount, $kbzpay_amount, $paid_amount, '$payment_ref_no', '$payment_datetime', $notes_sql_val)";
        } else {
            $sql_direct = "INSERT INTO supplier_payments 
                (supplier_id, payment_method, cash_amount, kbzpay_amount, paid_amount, ref_no, payment_date)
                VALUES ($supplier_id, '$payment_method', $cash_amount, $kbzpay_amount, $paid_amount, '$payment_ref_no', '$payment_datetime')";
        }
        
        $debug_log[] = "STEP4_DIRECT_SQL: $sql_direct";
        $result = $conn->query($sql_direct);
        if (!$result) {
            $debug_log[] = "STEP4_DIRECT_ERROR: " . $conn->error;
            throw new Exception("Failed to insert direct payment: " . $conn->error);
        }
    }

    // Step 5: Update supplier balance using single source of truth
    recalcSupplierBalance($conn, $supplier_id);

    // Rebuild the supplier ledger from real-time data
    rebuildSupplierLedger($conn, $supplier_id);

    // Verify the update worked
    $verify = fetchOne($conn, "SELECT outstanding_balance, advance_credit FROM suppliers WHERE id = ?", [$supplier_id], "i");
    if (!$verify) {
        throw new Exception("Failed to verify supplier balance update");
    }

    $conn->commit();

    // Build success message
    $message = "Payment of " . number_format($paid_amount, 2) . " MMK recorded successfully.";
    
    if (count($payments_made) === 1) {
        $p = $payments_made[0];
        $message = "Payment of " . number_format($p['amount'], 2) . " MMK applied to {$p['invoice_no']}. Status: {$p['status']}.";
    } elseif (count($payments_made) > 1) {
        $message = "Payment of " . number_format($paid_amount - $advance_created, 2) . " MMK applied to " . count($payments_made) . " purchases.";
    }
    
    if ($advance_applied > 0) {
        $message .= " Advance applied: " . number_format($advance_applied, 2) . " MMK.";
    }
    if ($advance_created > 0) {
        $message .= " Overpayment of " . number_format($advance_created, 2) . " MMK stored as advance credit.";
    }

    // Get updated balance values
    $resp_outstanding = (float)($verify['outstanding_balance'] ?? 0);
    $resp_advance = (float)($verify['advance_credit'] ?? 0);

    $debug_log[] = "=== PAYMENT SUCCESS ===";

    echo json_encode([
        'success' => true,
        'message' => $message,
        'outstanding_balance' => $resp_outstanding,
        'advance_credit' => $resp_advance,
        'advance_created' => $advance_created,
        'advance_applied' => $advance_applied,
        'payments_count' => count($payments_made),
        'redirect' => '../supplier/ledger.php?id=' . $supplier_id,
        'debug_log' => $debug_log
    ]);

} catch (Exception $e) {
    $conn->rollback();
    $debug_log[] = "=== PAYMENT FAILED ===";
    $debug_log[] = "ERROR: " . $e->getMessage();
    echo json_encode(['success' => false, 'message' => 'Payment failed: ' . $e->getMessage(), 'debug_log' => $debug_log]);
}

$conn->close();
