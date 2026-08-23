<?php
include "../includes/auth_check.php";
protectCategories('add');
include "../config/database.php";
include "../config/helpers.php";

$page_title = "Add Category";

$category_name = '';
$description = '';
$errors = [];

if (isset($_POST['save'])) {
    $category_name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($category_name === '') {
        $errors['name'] = 'Category name is required.';
    }

    // Optional image upload (validated inside the helper)
    $upload = handleCategoryImageUpload($_FILES['image'] ?? null);
    if (!$upload['ok']) {
        $errors['image'] = $upload['error'];
    }

    if (empty($errors)) {
        $check = fetchOne($conn, "SELECT id FROM categories WHERE name = ?", [$category_name], "s");
        if ($check) {
            $errors['name'] = 'This category name already exists.';
        } else {
            $sql = "INSERT INTO categories (name, description, image, status) VALUES (?, ?, ?, 'Active')";
            if (executeQuery($conn, $sql, [$category_name, $description, $upload['path']], "sss")) {
                header("Location: index.php?success=" . urlencode("Category has been saved successfully."));
                exit;
            }
            $errors['general'] = 'Failed to save category. Please try again.';
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
                        <form method="POST" enctype="multipart/form-data" novalidate data-form-guard="true">
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
                                    placeholder="Enter category description (optional)"
                                    class="form-input"><?= htmlspecialchars($description) ?></textarea>
                            </div>

                            <div class="mb-6">
                                <label for="image" class="form-label">Category Image <span class="text-gray-400 font-normal">(optional)</span></label>
                                <div class="flex items-center gap-4">
                                    <div id="imagePreviewBox" class="w-24 h-24 flex-shrink-0 rounded-xl border border-dashed border-gray-300 dark:border-slate-600 bg-gray-50 dark:bg-slate-700/50 flex items-center justify-center overflow-hidden">
                                        <svg id="imagePlaceholderIcon" class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                        <img id="imagePreview" src="" alt="Preview" class="hidden w-full h-full object-cover">
                                    </div>
                                    <div class="flex-1">
                                        <input type="file" id="image" name="image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                            class="form-input text-sm file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 dark:file:bg-slate-700 dark:file:text-indigo-300">
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1.5">JPG, JPEG, PNG, or WebP &middot; max 2MB</p>
                                    </div>
                                </div>
                                <?php if (isset($errors['image'])): ?>
                                    <p class="form-error"><?= $errors['image'] ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="flex gap-3">
                                <button type="submit" name="save" class="btn-primary p-2 rounded-lg text-sm">
                                    Save Category
                                </button>
                                <a href="index.php" class="btn-secondary p-2 rounded-lg text-sm">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <?php include "../includes/form_guard.php"; ?>

    <script>
        document.getElementById('image')?.addEventListener('change', function() {
            const preview = document.getElementById('imagePreview');
            const icon = document.getElementById('imagePlaceholderIcon');
            if (this.files && this.files[0]) {
                preview.src = URL.createObjectURL(this.files[0]);
                preview.classList.remove('hidden');
                icon.classList.add('hidden');
            } else {
                preview.classList.add('hidden');
                icon.classList.remove('hidden');
            }
        });
    </script>

    <?php include "../includes/footer.php"; ?>