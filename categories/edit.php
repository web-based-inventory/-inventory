<?php
include "../includes/auth_check.php";
protectCategories('edit');
include "../config/database.php";
include "../config/helpers.php";

$page_title = "Edit Category";

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$category = fetchOne($conn, "SELECT * FROM categories WHERE id = ?", [$id], "i");

if (!$category) {
    header("Location: index.php?error=" . urlencode("Category not found."));
    exit;
}

$category_name = $category['name'];
$description = $category['description'];
$status = $category['status'];
$errors = [];

if (isset($_POST['update'])) {
    $category_name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'Active';

    if ($category_name === '') {
        $errors['name'] = 'Category name is required.';
    }

    if (empty($errors)) {
        $check = fetchOne($conn, "SELECT id FROM categories WHERE name = ? AND id != ?", [$category_name, $id], "si");
        if ($check) {
            $errors['name'] = 'This category name already exists.';
        } else {
            $sql = "UPDATE categories SET name = ?, description = ?, status = ? WHERE id = ?";
            if (executeQuery($conn, $sql, [$category_name, $description, $status, $id], "sssi")) {
                header("Location: index.php?success=" . urlencode("Category has been updated successfully."));
                exit;
            } else {
                $errors['general'] = 'Failed to update category. Please try again.';
            }
        }
    }
}
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
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <form method="POST" novalidate data-form-guard="true">
                            <div class="mb-5">
                                <label for="name" class="form-label">Category Name <span class="text-red-500">*</span></label>
                                <input type="text" id="name" name="name" value="<?= htmlspecialchars($category_name) ?>"
                                    placeholder="Enter category name"
                                    class="form-input <?= isset($errors['name']) ? 'error' : '' ?>">
                                <?php if (isset($errors['name'])): ?>
                                    <p class="form-error"><?= $errors['name'] ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="mb-5">
                                <label for="description" class="form-label">Description</label>
                                <textarea id="description" name="description" rows="4"
                                    placeholder="Enter category description"
                                    class="form-input"><?= htmlspecialchars($description) ?></textarea>
                            </div>

                            <div class="mb-6">
                                <label for="status" class="form-label">Status</label>
                                <select id="status" name="status" class="form-input">
                                    <option value="Active" <?= $status === 'Active' ? 'selected' : '' ?>>Active</option>
                                    <option value="Inactive" <?= $status === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                                </select>
                            </div>

                            <div class="flex gap-3">
                                <button type="submit" name="update" class="btn-primary py-1 px-3 rounded-lg">
                                    Update Category
                                </button>
                                <a href="index.php" class="btn-secondary py-1 px-3 rounded-lg">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <?php include "../includes/form_guard.php"; ?>
    <?php include "../includes/footer.php"; ?>