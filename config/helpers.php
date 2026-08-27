<?php
// Include supplier ledger functions
require_once __DIR__ . '/supplier_ledger.php';

/**
 * Get shop settings from the settings table (single source of truth).
 * Returns an associative array with shop_name, logo, phone, email, address, currency.
 * Always fetches fresh data to avoid stale cache after settings updates.
 */
function getShopSettings($conn) {
    $result = mysqli_query($conn, "SELECT shop_name, logo, phone, email, address, currency FROM settings WHERE id=1");
    if ($result && mysqli_num_rows($result) > 0) {
        $settings = mysqli_fetch_assoc($result);
    } else {
        $settings = [
            'shop_name' => 'Smart Inventory',
            'logo' => '',
            'phone' => '',
            'email' => '',
            'address' => '',
            'currency' => 'Ks',
        ];
    }
    // Apply defaults for empty values
    if (empty($settings['shop_name'])) $settings['shop_name'] = 'Smart Inventory';
    if (empty($settings['currency'])) $settings['currency'] = 'Ks';

    return $settings;
}

/**
 * Reset the shop settings cache. Call after updating settings.
 * (No-op since getShopSettings always fetches fresh data)
 */
function resetShopSettingsCache() {
    // No-op: getShopSettings always fetches fresh data
}

/**
 * Get the URL path to the shop logo, or empty string if no logo.
 * Works from any depth in the project directory.
 */
function getShopLogoUrl($conn, $depth = 0) {
    $settings = getShopSettings($conn);
    $logo = trim($settings['logo'] ?? '');
    if (empty($logo)) return '';
    $prefix = str_repeat('../', $depth);
    return $prefix . 'img/' . $logo;
}

/**
 * Get the filesystem path to the shop logo for file_exists checks.
 */
function getShopLogoPath($conn) {
    $settings = getShopSettings($conn);
    $logo = trim($settings['logo'] ?? '');
    if (empty($logo)) return '';
    return dirname(__DIR__) . '/img/' . $logo;
}

/**
 * Get the current profit margin percentage (%) from the settings table.
 * Falls back to 10.00 if no settings row exists.
 */
function getProfitMargin($conn) {
    $row = fetchOne($conn, "SELECT minimum_profit_margin FROM settings WHERE id = 1");
    return $row ? (float)$row['minimum_profit_margin'] : 10;
}

/**
 * Calculate the recommended selling price for a purchase price using the
 * Settings profit margin:
 * Selling Price = Purchase Price + (Purchase Price x Margin / 100)
 */
function calculateSellingPrice($conn, $purchase_price) {
    $purchase_price = max(0, (float)$purchase_price);
    $margin = getProfitMargin($conn);
    return round($purchase_price + ($purchase_price * $margin / 100), 2);
}

function sanitize($conn, $value) {
    return mysqli_real_escape_string($conn, trim($value));
}

function executeQuery($conn, $sql, $params = [], $types = '') {
    if (empty($params)) {
        return mysqli_query($conn, $sql);
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    if (!empty($types)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();

    $trimmed = strtoupper(ltrim($sql));
    if (preg_match('/^(INSERT|UPDATE|DELETE|REPLACE)\s/', $trimmed)) {
        return $stmt->errno === 0;
    }

    return $stmt->get_result();
}

function fetchOne($conn, $sql, $params = [], $types = '') {
    $result = executeQuery($conn, $sql, $params, $types);
    if ($result && mysqli_num_rows($result) > 0) {
        return mysqli_fetch_assoc($result);
    }
    return null;
}

function fetchAll($conn, $sql, $params = [], $types = '') {
    $result = executeQuery($conn, $sql, $params, $types);
    $data = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
    }
    return $data;
}

function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Get the payment amount column name for a given table.
 * Handles both old schema (amount) and new schema (paid_amount).
 */
function getPaymentAmountCol($conn, $table) {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];

    $check = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE 'paid_amount'");
    $cache[$table] = (mysqli_num_rows($check) > 0) ? 'paid_amount' : 'amount';
    return $cache[$table];
}

/**
 * Check if a column exists in a table.
 */
function columnExists($conn, $table, $column) {
    static $cache = [];
    $key = "$table.$column";
    if (isset($cache[$key])) return $cache[$key];

    $result = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    $cache[$key] = (mysqli_num_rows($result) > 0);
    return $cache[$key];
}

/**
 * Ensure the purchases table has the payment tracking columns.
 * Safe to call multiple times — only adds missing columns.
 */
function ensurePurchasePaymentColumns($conn) {
    if (!columnExists($conn, 'purchases', 'total_paid')) {
        mysqli_query($conn, "ALTER TABLE purchases ADD COLUMN total_paid DECIMAL(15,2) DEFAULT 0 AFTER total_amount");
    }
    if (!columnExists($conn, 'purchases', 'remaining_balance')) {
        mysqli_query($conn, "ALTER TABLE purchases ADD COLUMN remaining_balance DECIMAL(15,2) DEFAULT 0 AFTER total_paid");
    }
    if (!columnExists($conn, 'purchases', 'payment_status')) {
        mysqli_query($conn, "ALTER TABLE purchases ADD COLUMN payment_status ENUM('Unpaid','Partial','Paid') DEFAULT 'Unpaid' AFTER remaining_balance");
    } else {
        $col_info = mysqli_fetch_assoc(mysqli_query($conn, "SHOW COLUMNS FROM purchases LIKE 'payment_status'"));
        $col_type = strtolower($col_info['Type'] ?? '');
        if ($col_type !== '' && strpos($col_type, 'partial') === false) {
            mysqli_query($conn, "ALTER TABLE purchases MODIFY COLUMN payment_status ENUM('Unpaid','Partial','Paid') DEFAULT 'Unpaid'");
        }
    }
}

/**
 * Update a single purchase's total_paid, remaining_balance, and payment_status
 * from its purchase_payments records. Single source of truth.
 *
 * total_paid = paid_amount (cash) + advance_applied (supplier credit used)
 */
function updatePurchasePaymentStatus($conn, $purchase_id) {
    if ($purchase_id <= 0) return;

    $amtCol = getPaymentAmountCol($conn, 'purchase_payments');
    $has_advance = columnExists($conn, 'purchase_payments', 'advance_applied');

    $purchase = mysqli_fetch_assoc(mysqli_query($conn, "SELECT total_amount FROM purchases WHERE id = $purchase_id"));
    if (!$purchase) return;

    $total_amount = (float)$purchase['total_amount'];

    // total_paid = cash paid + advance credit applied,
    // capped at the purchase total. Money beyond the purchase total belongs
    // to the supplier as advance credit (supplier_payments) and must never
    // be attributed to this purchase a second time.
    $sum_expr = $has_advance ? "SUM($amtCol + COALESCE(advance_applied, 0))" : "SUM($amtCol)";
    $pay_res = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE($sum_expr, 0) AS total_paid FROM purchase_payments WHERE purchase_id = $purchase_id"));
    $total_paid = max(0, (float)$pay_res['total_paid']);
    $total_paid = (float)$pay_res['total_paid'];

    $remaining_balance = round($total_amount - $total_paid, 2);

    if ($total_amount > 0 && $total_paid >= $total_amount) {
        $payment_status = 'Paid';
    } elseif ($total_paid > 0) {
        $payment_status = 'Partial';
    } else {
        $payment_status = 'Unpaid';
    }

    mysqli_query($conn, "UPDATE purchases SET
        total_paid = $total_paid,
        remaining_balance = $remaining_balance,
        payment_status = '$payment_status'
        WHERE id = $purchase_id");
}

/**
 * Recalculate a supplier's outstanding_balance and advance_credit from all their purchases and payments.
 * This is the SINGLE SOURCE OF TRUTH — always use this instead of setting balance fields directly.
 *
 * Balance logic:
 * - outstanding_balance: total_purchases - total_payments (only if positive, else 0)
 * - advance_credit: total_payments - total_purchases (only if positive, else 0)
 * - current_balance = outstanding_balance - advance_credit (for backward compat)
 */
function recalcSupplierBalance($conn, $supplier_id) {
    if ($supplier_id <= 0) return;

    $amtCol = getPaymentAmountCol($conn, 'purchase_payments');

    // Total purchases amount
    $purch_res = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_amount), 0) AS total FROM purchases WHERE supplier_id = $supplier_id"));
    $total_purchases = max(0, (float)$purch_res['total']);

    // Primary source: supplier_payments (tracks ALL cash payments)
    if (columnExists($conn, 'supplier_payments', 'supplier_id')) {
        $pay_res = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(paid_amount), 0) AS total FROM supplier_payments WHERE supplier_id = $supplier_id"));
        $total_payments = max(0, (float)$pay_res['total']);
    } else {
        $hasAdvance = columnExists($conn, 'purchase_payments', 'advance_applied');
        $advanceExpr = $hasAdvance ? " + COALESCE(pp.advance_applied, 0)" : "";
        $pay_res = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(pp.$amtCol$advanceExpr), 0) AS total FROM purchase_payments pp INNER JOIN purchases p ON pp.purchase_id = p.id WHERE p.supplier_id = $supplier_id"));
        $total_payments = max(0, (float)$pay_res['total']);
    }

    // Outstanding Balance vs Advance Credit (never mixed)
    if ($total_purchases > $total_payments) {
        $outstanding_balance = round($total_purchases - $total_payments, 2);
        $advance_credit = 0;
    } else {
        $outstanding_balance = 0;
        $advance_credit = round($total_payments - $total_purchases, 2);
    }

    // Backward compat fields
    $current_balance = max(0, $outstanding_balance - $advance_credit);
    if ($outstanding_balance > 0.01) {
        $new_type = 'Payable';
    } elseif ($advance_credit > 0.01) {
        $new_type = 'Advance';
    } else {
        $new_type = 'Clear';
        $outstanding_balance = 0;
        $advance_credit = 0;
        $current_balance = 0;
    }

    mysqli_query($conn, "UPDATE suppliers SET
        current_balance = $current_balance,
        advance_balance = $advance_credit,
        outstanding_balance = $outstanding_balance,
        advance_credit = $advance_credit,
        balance_type = '$new_type'
        WHERE id = $supplier_id");
}

/**
 * Get supplier balance from the single source of truth (suppliers table).
 * Use this function everywhere to read supplier balance.
 */
function getSupplierBalance($conn, $supplier_id) {
    $supplier = fetchOne($conn, "SELECT outstanding_balance, advance_credit, current_balance, balance_type FROM suppliers WHERE id = ?", [$supplier_id], "i");
    if (!$supplier) {
        return [
            'outstanding_balance' => 0,
            'advance_credit' => 0,
            'current_balance' => 0,
            'balance_type' => 'Clear'
        ];
    }
    return [
        'outstanding_balance' => (float)$supplier['outstanding_balance'],
        'advance_credit' => (float)$supplier['advance_credit'],
        'current_balance' => (float)$supplier['current_balance'],
        'balance_type' => $supplier['balance_type'] ?? 'Clear'
    ];
}

/**
 * Recalculate balances for ALL suppliers.
 * Call this on pages that list suppliers to ensure all balances are up-to-date.
 */
function recalcAllSupplierBalances($conn) {
    $suppliers = mysqli_query($conn, "SELECT id FROM suppliers");
    if ($suppliers) {
        while ($row = mysqli_fetch_assoc($suppliers)) {
            recalcSupplierBalance($conn, $row['id']);
        }
    }
}

/**
 * Dump the given tables to a restorable .sql file (DROP TABLE + CREATE TABLE
 * + INSERT rows). Used as an automatic safety snapshot BEFORE running repairs.
 *
 * @return string|false Absolute path of the written file, or false on failure.
 */
function backupTablesToSql($conn, array $tables, $destDir = null) {
    $destDir = $destDir ?: (__DIR__ . '/../backups');
    if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) {
        error_log("BACKUP ERROR: cannot create directory $destDir");
        return false;
    }

    $filename = $destDir . '/repair_backup_' . date('Ymd_His') . '.sql';
    $out = "-- Repair backup generated " . date('Y-m-d H:i:s') . "\n";
    $out .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        $table = preg_replace('/[^a-z0-9_]/i', '', $table); // only safe identifiers
        $createRes = mysqli_query($conn, "SHOW CREATE TABLE `$table`");
        if (!$createRes || mysqli_num_rows($createRes) === 0) {
            continue; // table doesn't exist yet — skip
        }
        $createRow = mysqli_fetch_assoc($createRes);
        $out .= "DROP TABLE IF EXISTS `$table`;\n";
        $out .= $createRow['Create Table'] . ";\n\n";

        $rowsRes = mysqli_query($conn, "SELECT * FROM `$table`");
        if ($rowsRes && mysqli_num_rows($rowsRes) > 0) {
            while ($row = mysqli_fetch_assoc($rowsRes)) {
                $vals = array();
                foreach ($row as $v) {
                    $vals[] = $v === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, $v) . "'";
                }
                $out .= "INSERT INTO `$table` VALUES (" . implode(',', $vals) . ");\n";
            }
            $out .= "\n";
        }
    }

    $out .= "SET FOREIGN_KEY_CHECKS=1;\n";

    if (@file_put_contents($filename, $out) === false) {
        error_log("BACKUP ERROR: cannot write $filename");
        return false;
    }
    return $filename;
}

/**
 * Compact money format for display only (exact value is never changed):
 * 11,247,506 -> 11.25M | 850,000 -> 850K | 25,500 -> 25.5K | 950 -> 950
 */
function compactMoney($amount) {
    $amount = (float)$amount;
    if (abs($amount) >= 999950) {
        return rtrim(rtrim(number_format($amount / 1000000, 2), '0'), '.') . 'M';
    }
    if (abs($amount) >= 1000) {
        return rtrim(rtrim(number_format($amount / 1000, 1), '0'), '.') . 'K';
    }
    return number_format($amount);
}

/**
 * Validate and store a category image upload.
 * - Optional: an empty/absent file returns ['ok' => true, 'path' => null]
 * - Allowed: JPG, JPEG, PNG, WEBP only (extension + real MIME + content check)
 * - Max size: 2MB
 * - Filename is generated server-side (original name is never trusted)
 * - Files are stored under img/categories/, DB stores the relative path
 *   inside img/ (e.g. "categories/cat_1690000000_a1b2c3d4.png")
 * - When $old_image is given and a new file is saved, the old file is deleted
 *
 * @return array ['ok' => bool, 'path' => ?string, 'error' => ?string]
 */
function handleCategoryImageUpload($file, $old_image = null) {
    // No file chosen -> keep whatever exists (optional field)
    if (!isset($file) || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null, 'error' => null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'path' => null, 'error' => 'Image upload failed. Please try again.'];
    }
    if (($file['size'] ?? 0) <= 0 || $file['size'] > 2 * 1024 * 1024) {
        return ['ok' => false, 'path' => null, 'error' => 'Image must be under 2MB.'];
    }

    $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];
    $allowed_mime = ['image/jpeg', 'image/png', 'image/webp'];

    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext, true)) {
        return ['ok' => false, 'path' => null, 'error' => 'Image must be JPG, JPEG, PNG, or WebP.'];
    }

    // Real content checks: reject executables/scripts renamed to .png etc.
    $tmp = $file['tmp_name'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    if (!in_array($mime, $allowed_mime, true)) {
        return ['ok' => false, 'path' => null, 'error' => 'The uploaded file is not a valid image.'];
    }
    if (@getimagesize($tmp) === false) {
        return ['ok' => false, 'path' => null, 'error' => 'The uploaded file is not a valid image.'];
    }

    $upload_dir = __DIR__ . '/../img/categories';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
        return ['ok' => false, 'path' => null, 'error' => 'Failed to create the image storage folder.'];
    }

    $filename = 'cat_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $upload_dir . '/' . $filename;
    if (!move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'path' => null, 'error' => 'Failed to save the uploaded image.'];
    }
    @chmod($dest, 0644);

    // Replace: remove the previous image file (only paths we manage)
    if ($old_image && strpos($old_image, 'categories/') === 0) {
        $old_abs = __DIR__ . '/../img/' . $old_image;
        if (is_file($old_abs)) {
            @unlink($old_abs);
        }
    }

    return ['ok' => true, 'path' => 'categories/' . $filename, 'error' => null];
}
