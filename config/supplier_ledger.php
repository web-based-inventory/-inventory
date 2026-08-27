<?php
/**
 * Supplier Ledger Functions
 * Handles all ledger transactions for suppliers
 */

/**
 * Create the supplier_ledger table if it doesn't exist
 */
function createSupplierLedgerTable($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS supplier_ledger (
        id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_id INT NOT NULL,
        transaction_type ENUM('Purchase', 'Payment', 'Advance Applied', 'Advance Created', 'Adjustment') NOT NULL,
        reference_type VARCHAR(50) NULL,
        reference_id INT NULL,
        reference_no VARCHAR(100) NULL,
        debit DECIMAL(15,2) DEFAULT 0,
        credit DECIMAL(15,2) DEFAULT 0,
        balance DECIMAL(15,2) DEFAULT 0,
        description TEXT NULL,
        transaction_date DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_supplier_id (supplier_id),
        INDEX idx_transaction_date (transaction_date),
        INDEX idx_reference (reference_type, reference_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    return mysqli_query($conn, $sql);
}

/**
 * Add a ledger entry for a supplier
 * 
 * @param mysqli $conn Database connection
 * @param int $supplier_id Supplier ID
 * @param string $type Transaction type: Purchase, Payment, Advance Applied, Advance Created, Adjustment
 * @param string $reference_type Reference type: purchase, purchase_payment, supplier_payment
 * @param int $reference_id Reference ID
 * @param string $reference_no Reference number (invoice no, etc.)
 * @param float $debit Debit amount (increases what we owe)
 * @param float $credit Credit amount (decreases what we owe)
 * @param string $description Description of the transaction
 * @param string $transaction_date Transaction date (Y-m-d format)
 * @return int|false The inserted ledger entry ID or false on failure
 */
function addSupplierLedgerEntry($conn, $supplier_id, $type, $reference_type, $reference_id, $reference_no, $debit, $credit, $description, $transaction_date) {
    // Get the current running balance for this supplier
    $last_balance = getSupplierLastBalance($conn, $supplier_id);

    // Calculate new balance: debit increases balance (we owe more), credit decreases (we pay)
    $new_balance = $last_balance + $debit - $credit;

    $sql = "INSERT INTO supplier_ledger
        (supplier_id, transaction_type, reference_type, reference_id, reference_no,
         debit, credit, balance, description, transaction_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("LEDGER PREPARE ERROR: " . $conn->error . " SQL: $sql");
        return false;
    }

    $stmt->bind_param("issisddsss",
        $supplier_id, $type, $reference_type, $reference_id, $reference_no,
        $debit, $credit, $new_balance, $description, $transaction_date
    );

    if ($stmt->execute()) {
        return $conn->insert_id;
    }
    error_log("LEDGER EXECUTE ERROR: " . $stmt->error);
    return false;
}

/**
 * Get the last balance for a supplier from the ledger
 */
function getSupplierLastBalance($conn, $supplier_id) {
    $result = mysqli_query($conn, "SELECT balance FROM supplier_ledger WHERE supplier_id = $supplier_id ORDER BY id DESC LIMIT 1");
    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        return (float)$row['balance'];
    }
    return 0;
}

/**
 * Add a purchase ledger entry
 * Called when a purchase is created
 */
function addPurchaseLedgerEntry($conn, $supplier_id, $purchase_id, $invoice_no, $total_amount, $advance_applied, $purchase_date) {
    // Add the purchase as a debit (we owe this amount)
    // Note: advance_applied is not recorded as a separate ledger entry here.
    // It is already included as part of the purchase_payments credit entries
    // (rebuildSupplierLedger credits the full paid_amount per payment row),
    // so creating a separate "Advance Applied" entry here would double-count it.
    $description = "Purchase #$invoice_no";
    
    addSupplierLedgerEntry(
        $conn, 
        $supplier_id, 
        'Purchase', 
        'purchase', 
        $purchase_id, 
        $invoice_no, 
        $total_amount, 
        0, 
        $description, 
        $purchase_date
    );
    
    return true;
}

/**
 * Add a purchase payment ledger entry
 * Called when a payment is made against a purchase
 */
function addPurchasePaymentLedgerEntry($conn, $supplier_id, $purchase_id, $payment_id, $invoice_no, $payment_amount, $payment_method, $payment_date) {
    $description = "Payment for Purchase #$invoice_no via $payment_method";
    
    addSupplierLedgerEntry(
        $conn, 
        $supplier_id, 
        'Payment', 
        'purchase_payment', 
        $payment_id, 
        $invoice_no, 
        0, 
        $payment_amount, 
        $description, 
        $payment_date
    );
    
    return true;
}

/**
 * Add a direct supplier payment ledger entry
 * Called when a direct payment is made to supplier (not linked to specific purchase)
 */
function addDirectPaymentLedgerEntry($conn, $supplier_id, $payment_id, $reference_no, $payment_amount, $payment_method, $payment_date) {
    $description = "Direct payment via $payment_method";
    
    addSupplierLedgerEntry(
        $conn, 
        $supplier_id, 
        'Payment', 
        'supplier_payment', 
        $payment_id, 
        $reference_no, 
        0, 
        $payment_amount, 
        $description, 
        $payment_date
    );
    
    return true;
}

/**
 * Add an advance created ledger entry
 * Called when an overpayment creates advance credit
 */
function addAdvanceCreatedLedgerEntry($conn, $supplier_id, $reference_type, $reference_id, $reference_no, $advance_amount, $transaction_date) {
    $description = "Advance credit of " . number_format($advance_amount, 2) . " created";
    
    addSupplierLedgerEntry(
        $conn, 
        $supplier_id, 
        'Advance Created', 
        $reference_type, 
        $reference_id, 
        $reference_no, 
        0, 
        $advance_amount, 
        $description, 
        $transaction_date
    );
    
    return true;
}

/**
 * Get all ledger entries for a supplier
 */
function getSupplierLedgerEntries($conn, $supplier_id, $date_from = '', $date_to = '') {
    $sql = "SELECT * FROM supplier_ledger WHERE supplier_id = $supplier_id";
    
    if ($date_from !== '') {
        $sql .= " AND transaction_date >= '" . mysqli_real_escape_string($conn, $date_from) . "'";
    }
    if ($date_to !== '') {
        $sql .= " AND transaction_date <= '" . mysqli_real_escape_string($conn, $date_to) . "'";
    }
    
    $sql .= " ORDER BY id ASC";
    
    return mysqli_query($conn, $sql);
}

/**
 * Recalculate ledger balance from scratch
 * This rebuilds the running balance for all entries
 */
function recalculateLedgerBalance($conn, $supplier_id) {
    // Get all entries ordered by ID
    $result = mysqli_query($conn, "SELECT id, debit, credit FROM supplier_ledger WHERE supplier_id = $supplier_id ORDER BY id ASC");
    
    if (!$result) return false;
    
    $running_balance = 0;
    while ($row = mysqli_fetch_assoc($result)) {
        $running_balance += (float)$row['debit'] - (float)$row['credit'];
        mysqli_query($conn, "UPDATE supplier_ledger SET balance = $running_balance WHERE id = {$row['id']}");
    }
    
    return true;
}

/**
 * Initialize ledger from existing data
 * This creates initial ledger entries from existing purchases and payments
 * For backwards compatibility it is an alias for rebuildSupplierLedger().
 */
function initializeLedgerFromExistingData($conn, $supplier_id) {
    return rebuildSupplierLedger($conn, $supplier_id);
}

/**
 * Rebuild the supplier ledger from scratch using real-time data
 * Deletes all existing ledger entries for the supplier and recreates them from:
 *   - purchases (debit: we owe)
 *   - purchase_payments (credit: cash/khalti payments against a purchase)
 *   - supplier_payments (credit: direct payments to the supplier)
 * The running balance is recalculated in chronological order, so the ledger
 * always matches suppliers.outstanding_balance / advance_credit and the
 * purchase invoice outstanding values.
 */
function rebuildSupplierLedger($conn, $supplier_id) {
    $supplier_id = (int)$supplier_id;
    if ($supplier_id <= 0) return false;

    createSupplierLedgerTable($conn);

    if (!mysqli_query($conn, "DELETE FROM supplier_ledger WHERE supplier_id = $supplier_id")) {
        return false;
    }

    $entries = array();

    // 1. Purchases (debit)
    $purchases = mysqli_query($conn, "
        SELECT id, invoice_no, total_amount, purchase_date
        FROM purchases
        WHERE supplier_id = $supplier_id
        ORDER BY purchase_date ASC, id ASC
    ");
    if (!$purchases) return false;

    while ($p = mysqli_fetch_assoc($purchases)) {
        $entries[] = array(
            'transaction_type' => 'Purchase',
            'reference_type'   => 'purchase',
            'reference_id'     => (int)$p['id'],
            'reference_no'     => $p['invoice_no'],
            'debit'            => (float)$p['total_amount'],
            'credit'           => 0,
            'description'      => "Purchase #" . $p['invoice_no'],
            'transaction_date' => $p['purchase_date'],
            'seq'              => 1,
            'sort_id'          => (int)$p['id'],
        );
    }

    // 2. Supplier payments (credit) - actual cash payments
    if (columnExists($conn, 'supplier_payments', 'supplier_id')) {
        $payments = mysqli_query($conn, "
            SELECT id, ref_no, paid_amount, payment_method, payment_date, notes
            FROM supplier_payments
            WHERE supplier_id = $supplier_id AND paid_amount > 0
            ORDER BY payment_date ASC, id ASC
        ");
        if ($payments) {
            while ($pm = mysqli_fetch_assoc($payments)) {
                $date = date('Y-m-d', strtotime($pm['payment_date']));
                $method = $pm['payment_method'] !== '' && $pm['payment_method'] !== null
                    ? $pm['payment_method']
                    : 'Cash';
                $credit_total = (float)$pm['paid_amount'];

                $desc = "Payment via " . $method;
                if (!empty($pm['ref_no'])) {
                    $desc .= " (Ref: " . $pm['ref_no'] . ")";
                }

                $entries[] = array(
                    'transaction_type' => 'Payment',
                    'reference_type'   => 'supplier_payment',
                    'reference_id'     => (int)$pm['id'],
                    'reference_no'     => $pm['ref_no'],
                    'debit'            => 0,
                    'credit'           => $credit_total,
                    'description'      => $desc,
                    'transaction_date' => $date,
                    'seq'              => 2,
                    'sort_id'          => (int)$pm['id'],
                );
            }
        }
    } else {
        // Fallback to purchase_payments if supplier_payments doesn't exist
        $amtCol = getPaymentAmountCol($conn, 'purchase_payments');
        if ($amtCol !== '') {
            $hasAdvance = columnExists($conn, 'purchase_payments', 'advance_applied');
            $advanceExpr = $hasAdvance ? " + COALESCE(pp.advance_applied, 0)" : "";
            $advanceSelect = $hasAdvance ? "COALESCE(pp.advance_applied, 0)" : "0";
            $payments = mysqli_query($conn, "
                SELECT pp.id, pp.purchase_id, pu.invoice_no, pp.$amtCol AS paid_amount,
                       $advanceSelect AS advance_applied,
                       pp.payment_method, pp.payment_date
                FROM purchase_payments pp
                INNER JOIN purchases pu ON pp.purchase_id = pu.id
                WHERE pu.supplier_id = $supplier_id AND (pp.$amtCol$advanceExpr) > 0
                ORDER BY pp.payment_date ASC, pp.id ASC
            ");
            if ($payments) {
                while ($pm = mysqli_fetch_assoc($payments)) {
                    $date = date('Y-m-d', strtotime($pm['payment_date']));
                    $method = $pm['payment_method'] !== '' && $pm['payment_method'] !== null
                        ? $pm['payment_method']
                        : 'Cash';
                    $cash_paid = (float)$pm['paid_amount'];
                    $adv_applied = (float)$pm['advance_applied'];
                    $credit_total = $cash_paid + $adv_applied;

                    if ($adv_applied > 0 && $cash_paid > 0) {
                        $desc = "Payment for Purchase #" . $pm['invoice_no'] . " — " .
                                number_format($adv_applied, 2) . " advance + " .
                                number_format($cash_paid, 2) . " cash via " . $method;
                    } elseif ($adv_applied > 0) {
                        $desc = "Advance applied to Purchase #" . $pm['invoice_no'];
                    } else {
                        $desc = "Payment for Purchase #" . $pm['invoice_no'] . " via " . $method;
                    }

                    $entries[] = array(
                        'transaction_type' => 'Payment',
                        'reference_type'   => 'purchase_payment',
                        'reference_id'     => (int)$pm['id'],
                        'reference_no'     => $pm['invoice_no'],
                        'debit'            => 0,
                        'credit'           => $credit_total,
                        'description'      => $desc,
                        'transaction_date' => $date,
                        'seq'              => 2,
                        'sort_id'          => (int)$pm['id'],
                    );
                }
            }
        }
    }

    // Sort chronologically (purchase before payments on the same day)
    usort($entries, function ($a, $b) {
        if ($a['transaction_date'] !== $b['transaction_date']) {
            return strcmp($a['transaction_date'], $b['transaction_date']);
        }
        if ($a['seq'] !== $b['seq']) return $a['seq'] - $b['seq'];
        return $a['sort_id'] - $b['sort_id'];
    });

    $running_balance = 0;
    foreach ($entries as $e) {
        $running_balance += $e['debit'] - $e['credit'];

        $sql = "INSERT INTO supplier_ledger
            (supplier_id, transaction_type, reference_type, reference_id, reference_no,
             debit, credit, balance, description, transaction_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("LEDGER REBUILD PREPARE ERROR: " . $conn->error);
            return false;
        }

        $stmt->bind_param("issisddsss",
            $supplier_id,
            $e['transaction_type'],
            $e['reference_type'],
            $e['reference_id'],
            $e['reference_no'],
            $e['debit'],
            $e['credit'],
            $running_balance,
            $e['description'],
            $e['transaction_date']
        );

        if (!$stmt->execute()) {
            error_log("LEDGER REBUILD EXECUTE ERROR: " . $stmt->error);
            return false;
        }
    }

    return true;
}
