<?php
include "../includes/auth_check.php";
protectSuppliers('add');
include "../config/database.php";
include "../config/helpers.php";

$page_title = "Add Supplier";

$supplier_name = '';
$contact_person = '';
$phone = '';
$email = '';
$address = '';
$status = 'Active';
$current_balance = 0.00;
$balance_type = 'Clear';
$outstanding_balance = 0.00;
$advance_credit_val = 0.00;
$errors = [];

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Prevent double submission using session token
    if (!isset($_POST['save_token']) || !isset($_SESSION['supplier_save_token']) || $_POST['save_token'] !== $_SESSION['supplier_save_token']) {
        $errors['general'] = 'Duplicate submission detected. Please refresh and try again.';
    } else {
        // Only consume token if validation succeeds or we are handling a real submit
        $supplier_name = trim($_POST['supplier_name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $phone = preg_replace('/\s+/', '', trim($_POST['phone'] ?? ''));
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $status = $_POST['status'] ?? 'Active';

        if ($supplier_name === '') {
            $errors['supplier_name'] = 'Supplier name is required.';
        }

        if ($phone === '') {
            $errors['phone'] = 'Phone number is required.';
        } elseif (!preg_match('/^[0-9]{7,11}$/', $phone)) {
            $errors['phone'] = 'Phone number must be between 7 and 11 digits.';
        }

        if (empty($errors)) {
            // Unset token only after successful validation & insertion
            unset($_SESSION['supplier_save_token']);

            $sql = "INSERT INTO suppliers (supplier_name, contact_person, phone, email, address, status, current_balance, balance_type, outstanding_balance, advance_credit) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            if (executeQuery($conn, $sql, [$supplier_name, $contact_person, $phone, $email, $address, $status, $current_balance, $balance_type, $outstanding_balance, $advance_credit_val], "ssssssdddd")) {
                header("Location: index.php?success=" . urlencode("Supplier has been saved successfully."));
                exit;
            } else {
                $errors['general'] = 'Failed to save supplier. Please try again.';
            }
        }
    }
}

// Ensure token exists or regenerate if invalidated/consumed
if (!isset($_SESSION['supplier_save_token'])) {
    $_SESSION['supplier_save_token'] = bin2hex(random_bytes(16));
}
$save_token = $_SESSION['supplier_save_token'];
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
</head>

<body class="bg-gray-50 dark:bg-slate-900">
    <div class="flex min-h-screen">
        <?php include "../includes/sidebar.php"; ?>

        <div class="flex-1 flex flex-col">
            <?php include "../includes/header.php"; ?>

            <main class="p-6">
                <div class="max-w-2xl mx-auto">
                    <div class="card">
                        <div class="card-body">
                            <?php if (isset($errors['general'])): ?>
                                <div class="p-3 mb-4 text-sm text-red-700 bg-red-100 rounded-lg dark:bg-red-200 dark:text-red-800" role="alert">
                                    <?= $errors['general'] ?>
                                </div>
                            <?php endif; ?>

                            <form method="POST" action="" novalidate data-form-guard="true" id="supplierForm">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

                                    <div>
                                        <label for="supplier_name" class="form-label">Supplier Name <span class="text-red-500">*</span></label>
                                        <input type="text" id="supplier_name" name="supplier_name" value="<?= htmlspecialchars($supplier_name) ?>"
                                            placeholder="Enter supplier name"
                                            class="form-input <?= isset($errors['supplier_name']) ? 'error' : '' ?>">
                                        <?php if (isset($errors['supplier_name'])): ?>
                                            <p class="form-error text-red-500 text-xs mt-1"><?= $errors['supplier_name'] ?></p>
                                        <?php endif; ?>
                                    </div>

                                    <div>
                                        <label for="contact_person" class="form-label">Contact Person</label>
                                        <input type="text" id="contact_person" name="contact_person" value="<?= htmlspecialchars($contact_person) ?>"
                                            placeholder="Enter contact person name"
                                            class="form-input">
                                    </div>

                                    <div>
                                        <label for="phone" class="form-label">Phone Number <span class="text-red-500">*</span></label>
                                        <input type="tel" id="phone" name="phone" value="<?= htmlspecialchars($phone) ?>"
                                            placeholder="Enter phone number"
                                            maxlength="11" inputmode="numeric" pattern="[0-9]{7,11}"
                                            class="form-input <?= isset($errors['phone']) ? 'error' : '' ?>">
                                        <?php if (isset($errors['phone'])): ?>
                                            <p class="form-error text-red-500 text-xs mt-1"><?= $errors['phone'] ?></p>
                                        <?php endif; ?>
                                    </div>

                                    <div>
                                        <label for="email" class="form-label">Email</label>
                                        <input type="email" id="email" name="email" value="<?= htmlspecialchars($email) ?>"
                                            placeholder="Enter email address"
                                            class="form-input">
                                    </div>

                                    <div class="md:col-span-2">
                                        <label for="address" class="form-label">Address</label>
                                        <textarea id="address" name="address" rows="3"
                                            placeholder="Enter address"
                                            class="form-input"><?= htmlspecialchars($address) ?></textarea>
                                    </div>

                                    <div class="md:col-span-2">
                                        <label for="status" class="form-label">Status</label>
                                        <select id="status" name="status" class="form-input">
                                            <option value="Active" <?= $status === 'Active' ? 'selected' : '' ?>>Active</option>
                                            <option value="Inactive" <?= $status === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="flex gap-3 mt-6">
                                    <button type="submit" name="save" class="btn-primary p-2 rounded-lg text-sm" id="saveBtn">
                                        Save Supplier
                                    </button>
                                    <a href="index.php" class="btn-secondary p-2 rounded-lg text-sm">Cancel</a>
                                </div>
                                <input type="hidden" name="save_token" value="<?= htmlspecialchars($save_token) ?>">
                            </form>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <?php include "../includes/form_guard.php"; ?>
    <script>
        document.getElementById('supplierForm').addEventListener('submit', function() {
            var btn = document.getElementById('saveBtn');
            // Delay disabling slightly to allow button name/value submission to go through
            setTimeout(function() {
                btn.disabled = true;
                btn.textContent = 'Saving...';
            }, 10);
        });
    </script>
    <?php include "../includes/footer.php"; ?>
</body>

</html>