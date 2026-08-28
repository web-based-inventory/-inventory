<?php
include "../includes/auth_check.php";
protectSettings();
include "../config/database.php";
include "../config/helpers.php";
$page_title = "Settings";

$setting = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM settings WHERE id=1"));

if (isset($_POST['save'])) {
    $error = '';
    $shop_name = trim(mysqli_real_escape_string($conn, $_POST['shop_name']));
    if (empty($shop_name)) $shop_name = 'Smart Inventory';
    
    $phone_raw = preg_replace('/\s+/', '', trim($_POST['phone'] ?? ''));
    if ($phone_raw !== '' && !preg_match('/^[0-9]{7,11}$/', $phone_raw)) {
        $error = "Phone number must be between 7 and 11 digits.";
    }
    $phone = mysqli_real_escape_string($conn, $phone_raw);
    
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $address = mysqli_real_escape_string($conn, $_POST['address']);
    $currency = mysqli_real_escape_string($conn, $_POST['currency']);
    if (empty($currency)) $currency = 'Ks';
    $minimum_profit_margin = (float)$_POST['minimum_profit_margin'];
    $logo = $setting['logo'] ?? '';

    if ($_FILES['logo']['name'] != "") {
        // Delete old logo if exists
        if (!empty($logo) && file_exists("../img/" . $logo)) {
            unlink("../img/" . $logo);
        }
        $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
        $logo = 'logo_' . time() . '.' . $ext;
        move_uploaded_file($_FILES['logo']['tmp_name'], "../img/" . $logo);
    }

    if (empty($error)) {
        if ($setting) {
            mysqli_query($conn, "UPDATE settings SET shop_name='$shop_name', logo='$logo', phone='$phone', email='$email', address='$address', currency='$currency', minimum_profit_margin=$minimum_profit_margin WHERE id=1");
        } else {
            mysqli_query($conn, "INSERT INTO settings (shop_name, logo, phone, email, address, currency, minimum_profit_margin) VALUES ('$shop_name', '$logo', '$phone', '$email', '$address', '$currency', $minimum_profit_margin)");
        }

        // Clear the getShopSettings cache so the sidebar picks up changes immediately
        $setting = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM settings WHERE id=1"));
        // Force cache refresh
        getShopSettings($conn);
        $success = "Settings updated successfully.";
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings</title>
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

                <div class="max-w-4xl mx-auto">
                    <?php if (isset($success)): ?>
                        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6"><?= $success ?></div>
                    <?php endif; ?>
                    <?php if (isset($error) && $error !== ''): ?>
                        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6"><?= $error ?></div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data" class="bg-white shadow-xl rounded-2xl p-8" data-form-guard="true">
                        <h2 class="text-lg font-bold text-gray-800 dark:text-gray-200 mb-4 border-b pb-2">Shop Information</h2>
                        <div class="grid grid-cols-2 gap-5">
                            <div>
                                <label class="font-semibold">Shop Name</label>
                                <input type="text" name="shop_name" value="<?= htmlspecialchars($setting['shop_name'] ?? '') ?>" class="w-full border rounded-lg p-3 mt-2" placeholder="Shop Name">
                            </div>
                            <div>
                                <label class="font-semibold">Logo</label>
                                <input type="file" name="logo" accept="image/*" class="w-full border rounded-lg p-3 mt-2" onchange="previewLogo(this)">
                                <div class="flex gap-3 items-center mt-3">
                                    <?php if (!empty($setting['logo']) && file_exists('../img/' . $setting['logo'])): ?>
                                        <img id="logoPreview" src="../img/<?= htmlspecialchars($setting['logo']) ?>" class="w-16 h-16 rounded-lg object-cover border border-gray-200">
                                    <?php else: ?>
                                        <div id="logoPreview" class="w-16 h-16 rounded-lg bg-gray-100 dark:bg-gray-700 flex items-center justify-center border border-gray-200">
                                            <svg class="w-8 h-8 text-gray-300 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                            </svg>
                                        </div>
                                    <?php endif; ?>
                                    <p class="text-xs text-gray-400">Leave empty to keep current logo</p>
                                </div>
                            </div>
                        </div>

                        <h2 class="text-lg font-bold text-gray-800 dark:text-gray-200 mb-4 mt-8 border-b pb-2">Contact</h2>
                        <div class="grid grid-cols-2 gap-5">
                            <div>
                                <label class="font-semibold">Phone</label>
                                <input type="tel" name="phone" value="<?= htmlspecialchars($setting['phone'] ?? '') ?>" maxlength="11" inputmode="numeric" pattern="[0-9]{7,11}" class="w-full border rounded-lg p-3 mt-2" placeholder="Phone number">
                            </div>
                            <div>
                                <label class="font-semibold">Email</label>
                                <input type="email" name="email" value="<?= $setting['email'] ?? '' ?>" class="w-full border rounded-lg p-3 mt-2" placeholder="Email address">
                            </div>
                        </div>
                        <div class="mt-5">
                            <label class="font-semibold">Address</label>
                            <textarea name="address" rows="3" class="w-full border rounded-lg p-3 mt-2" placeholder="Address"><?= $setting['address'] ?? '' ?></textarea>
                        </div>

                        <h2 class="text-lg font-bold text-gray-800 dark:text-gray-200 mb-4 mt-8 border-b pb-2">Regional</h2>
                        <div class="grid grid-cols-2 gap-5">
                            <div>
                                <label class="font-semibold">Currency</label>
                                <input type="text" name="currency" value="<?= $setting['currency'] ?? 'Ks' ?>" class="w-full border rounded-lg p-3 mt-2" placeholder="Currency symbol">
                            </div>
                            <div>
                                <label class="font-semibold">Min Profit Margin (%)</label>
                                <input type="number" step="0.01" name="minimum_profit_margin" value="<?= $setting['minimum_profit_margin'] ?? 10 ?>" class="w-full border rounded-lg p-3 mt-2" placeholder="10.00">
                                <p class="text-xs text-gray-400 mt-1">Used to calculate suggested selling price</p>
                            </div>
                        </div>

                        <div class="flex justify-end gap-4 mt-8">
                            <a href="../dashboard/index.php" class="px-6 py-3 bg-gray-200 rounded-lg">Cancel</a>
                            <button name="save" class="px-6 py-3 bg-indigo-600 text-white rounded-lg">Save Settings</button>
                        </div>
                    </form>
                </div>

            </main>
        </div>
    </div>
    <?php include "../includes/toast.php"; ?>
    <?php include "../includes/modal.php"; ?>
    <?php include "../includes/form_guard.php"; ?>
    <?php include "../includes/footer.php"; ?>
    <script>
    function previewLogo(input) {
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) {
                var preview = document.getElementById('logoPreview');
                if (preview.tagName === 'IMG') {
                    preview.src = e.target.result;
                } else {
                    var img = document.createElement('img');
                    img.id = 'logoPreview';
                    img.src = e.target.result;
                    img.className = 'w-16 h-16 rounded-lg object-cover border border-gray-200';
                    preview.parentNode.replaceChild(img, preview);
                }
            };
            reader.readAsDataURL(input.files[0]);
        }
    }
    </script>
</body>

</html>