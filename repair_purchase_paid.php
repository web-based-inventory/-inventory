<?php
/**
 * One-time repair: fix historical over-attributed purchase payments.
 *
 * Problem fixed:
 *  purchase/add.php used to store the FULL initial payment inside a single
 *  purchase_payments.paid_amount row even when the payment exceeded the
 *  purchase total (e.g. paying previous balance together with a new
 *  purchase). The excess belongs to the supplier as advance credit and is
 *  already recorded in full in supplier_payments, so keeping it inside the
 *  purchase's paid_amount made "Paid" larger than the purchase amount in
 *  the Purchase Report / Purchase History.
 *
 * What this script does (NO records are deleted):
 *  1. For every purchase whose summed purchase_payments.paid_amount exceeds
 *     its total_amount, trims the excess from the row(s) carrying
 *     advance_created > 0 (newest first) so the purchase's paid total equals
 *     exactly what applies to that purchase.
 *  2. Recomputes purchases.total_paid / remaining_balance / payment_status.
 *  3. Marks every existing purchase_payments row as migrated_to_sp = 1
 *     (all of them are already represented in supplier_payments), which
 *     prevents migrate_payments.php from inserting them a second time.
 *  4. Recalculates supplier balances and rebuilds supplier ledgers.
 *
 * Safe to run multiple times (idempotent).
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helpers.php';

exit("This repair script is disabled. Overpayments on purchases are now supported and tracked accurately in the database.\n");

if (!columnExists($conn, 'purchase_payments', 'paid_amount')) {
    exit("purchase_payments.paid_amount missing - nothing to repair.\n");
}

$amtCol = getPaymentAmountCol($conn, 'purchase_payments');

echo "=== BEFORE ===\n";
$row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(total_amount),0) AS t FROM purchases"));
$total_purchases_before = (float)$row['t'];
$row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(pp.paid_amount),0) AS t FROM purchase_payments pp"));
$total_paid_before = (float)$row['t'];
printf("Sum(purchases.total_amount)=%.2f  Sum(purchase_payments.paid_amount)=%.2f\n\n",
    $total_purchases_before, $total_paid_before);

// ── Step 1: trim excess from advance_created rows ──
$bad = fetchAll($conn, "
    SELECT p.id, p.total_amount, COALESCE(SUM(pp.$amtCol), 0) AS sum_paid
    FROM purchases p
    LEFT JOIN purchase_payments pp ON pp.purchase_id = p.id
    GROUP BY p.id, p.total_amount
    HAVING sum_paid - p.total_amount > 0.01
    ORDER BY p.id");

$repaired_purchases = [];
$suppliers_touched = [];

foreach ($bad as $b) {
    $pid = (int)$b['id'];
    $excess = round((float)$b['sum_paid'] - (float)$b['total_amount'], 2);

    // Rows created by the old overpayment path carry advance_created > 0.
    // Trim from the newest such row first, never going below zero.
    $rows = fetchAll($conn, "
        SELECT id, $amtCol AS amt, cash_amount, kbzpay_amount
        FROM purchase_payments
        WHERE purchase_id = $pid AND advance_created > 0.01 AND $amtCol > 0
        ORDER BY id DESC");

    foreach ($rows as $r) {
        if ($excess <= 0.01) break;
        $amt = (float)$r['amt'];
        $cut = min($excess, $amt);
        $new_amt = round($amt - $cut, 2);

        // Scale the informational cash/kbzpay split proportionally
        $cash = (float)$r['cash_amount'];
        $kbz = (float)$r['kbzpay_amount'];
        $split = ($amt > 0) ? ($new_amt / $amt) : 0;
        $new_cash = round($cash * $split, 2);
        $new_kbz = round($new_amt - $new_cash, 2);

        $stmt = $conn->prepare("UPDATE purchase_payments SET $amtCol = ?, cash_amount = ?, kbzpay_amount = ? WHERE id = ?");
        $stmt->bind_param("dddi", $new_amt, $new_cash, $new_kbz, $r['id']);
        $stmt->execute();

        $excess = round($excess - $cut, 2);
    }

    $sup = mysqli_fetch_assoc(mysqli_query($conn, "SELECT supplier_id FROM purchases WHERE id = $pid"));
    if ($sup) $suppliers_touched[(int)$sup['supplier_id']] = true;

    $repaired_purchases[] = sprintf("Purchase #%d (%s): trimmed %.2f",
        $pid, $b['invoice_no'] ?? '', (float)$b['sum_paid'] - (float)$b['total_amount']);
}

foreach ($repaired_purchases as $msg) echo "$msg\n";
if (!$repaired_purchases) echo "No over-attributed purchase payments found.\n";

// ── Step 1b: trim residual excess from newest plain-cash rows ──
// Some legacy purchases received an advance application AND a later cash
// payment covering the same remainder (double-applied). Any excess still
// left after Step 1 is trimmed from the newest ordinary payment rows.
$bad2 = fetchAll($conn, "
    SELECT t.id, t.supplier_id, t.sum_paid - t.total_amount AS excess
    FROM (
        SELECT p.id, p.supplier_id, p.total_amount,
               COALESCE(SUM(pp.$amtCol + COALESCE(pp.advance_applied, 0)), 0) AS sum_paid
        FROM purchases p
        LEFT JOIN purchase_payments pp ON pp.purchase_id = p.id
        GROUP BY p.id, p.supplier_id, p.total_amount
    ) t
    WHERE t.sum_paid - t.total_amount > 0.01
    ORDER BY t.id");

$extra_repaired = [];
foreach ($bad2 as $b) {
    $pid = (int)$b['id'];
    $excess = round((float)$b['excess'], 2);

    // Total advance_applied already recorded for this purchase must not be reduced;
    // excess comes out of cash rows only.
    $rows = fetchAll($conn, "
        SELECT id, $amtCol AS amt, cash_amount, kbzpay_amount
        FROM purchase_payments
        WHERE purchase_id = $pid AND $amtCol > 0 AND COALESCE(advance_created, 0) <= 0.01
        ORDER BY id DESC");

    foreach ($rows as $r) {
        if ($excess <= 0.01) break;
        $amt = (float)$r['amt'];
        $cut = min($excess, $amt);
        if ($cut <= 0.01) continue;
        $new_amt = round($amt - $cut, 2);

        $cash = (float)$r['cash_amount'];
        $kbz = (float)$r['kbzpay_amount'];
        $split = ($amt > 0) ? ($new_amt / $amt) : 0;
        $new_cash = round($cash * $split, 2);
        $new_kbz = round($new_amt - $new_cash, 2);

        $stmt = $conn->prepare("UPDATE purchase_payments SET $amtCol = ?, cash_amount = ?, kbzpay_amount = ? WHERE id = ?");
        $stmt->bind_param("dddi", $new_amt, $new_cash, $new_kbz, $r['id']);
        $stmt->execute();

        $excess = round($excess - $cut, 2);
    }

    if ($excess <= 0.01) {
        $suppliers_touched[(int)$b['supplier_id']] = true;
        $extra_repaired[] = "Purchase #$pid: trimmed residual excess from newest cash row(s)";
    }
}
foreach ($extra_repaired as $msg) echo "$msg\n";

// ── Step 2: recompute purchase-level tracking columns for ALL purchases ──
$all = mysqli_query($conn, "SELECT id FROM purchases");
$count = 0;
while ($p = mysqli_fetch_assoc($all)) {
    updatePurchasePaymentStatus($conn, (int)$p['id']);
    $count++;
}
echo "\nRecalculated payment status for $count purchases.\n";

// ── Step 3: neutralise migrate_payments.php double-insert risk ──
if (!columnExists($conn, 'purchase_payments', 'migrated_to_sp')) {
    mysqli_query($conn, "ALTER TABLE purchase_payments ADD COLUMN migrated_to_sp TINYINT(1) DEFAULT 0");
    echo "Added purchase_payments.migrated_to_sp column.\n";
}
mysqli_query($conn, "UPDATE purchase_payments SET migrated_to_sp = 1");
echo "Marked all existing purchase_payments rows as migrated_to_sp=1 (each already exists in supplier_payments).\n";

// ── Step 4: refresh supplier balances and ledgers ──
$suppliers = mysqli_query($conn, "SELECT DISTINCT id FROM suppliers");
while ($s = mysqli_fetch_assoc($suppliers)) {
    recalcSupplierBalance($conn, (int)$s['id']);
    rebuildSupplierLedger($conn, (int)$s['id']);
}
echo "Recalculated supplier balances and rebuilt ledgers.\n";

echo "\n=== AFTER ===\n";
$row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(pp.$amtCol),0) AS t FROM purchase_payments pp"));
$total_paid_after = (float)$row['t'];
printf("Sum(purchases.total_amount)=%.2f  Sum(purchase_payments.paid_amount)=%.2f\n",
    $total_purchases_before, $total_paid_after);

$still_bad = mysqli_num_rows(mysqli_query($conn, "
    SELECT p.id FROM purchases p
    LEFT JOIN purchase_payments pp ON pp.purchase_id = p.id
    GROUP BY p.id, p.total_amount
    HAVING COALESCE(SUM(pp.$amtCol),0) - p.total_amount > 0.01"));
echo $still_bad === 0
    ? "OK: no purchase has Paid greater than its Purchase Amount.\n"
    : "WARNING: $still_bad purchases still exceed their total.\n";

$conn->close();
