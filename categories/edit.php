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
    $current_image = $category['image'] ?? null;

    if ($category_name === '') {
        $errors['name'] = 'Category name is required.';
    }

    // Optional image upload. When no new file is chosen the helper returns
    // path = null and the current image is kept untouched.
    $upload = handleCategoryImageUpload($_FILES['image'] ?? null, $current_image);
    if (!$upload['ok']) {
        $errors['image'] = $upload['error'];
    }
    $new_image = $upload['path'] ?? $current_image;

    if (empty($errors)) {
        $check = fetchOne($conn, "SELECT id FROM categories WHERE name = ? AND id != ?", [$category_name, $id], "si");
        if ($check) {
            // A rejected/unused upload must not leave orphan files behind
            if ($upload['path']) {
                @unlink(__DIR__ . '/../img/' . $upload['path']);
            }
            $errors['name'] = 'This category name already exists.';
        } else {
            $sql = "UPDATE categories SET name = ?, description = ?, image = ?, status = ? WHERE id = ?";
            if (executeQuery($conn, $sql, [$category_name, $description, $new_image, $status, $id], "ssssi")) {
                header("Location: index.php?success=" . urlencode("Category has been updated successfully."));
                exit;
            }
            $errors['general'] = 'Failed to update category. Please try again.';
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
                                    placeholder="Enter category description"
                                    class="form-input"><?= htmlspecialchars($description) ?></textarea>
                            </div>

                            <div class="mb-5">
                                <label for="image" class="form-label">Category Image <span class="text-gray-400 font-normal">(optional)</span></label>
                                <div class="flex items-center gap-4">
                                    <div class="w-24 h-24 flex-shrink-0 rounded-xl border border-dashed border-gray-300 dark:border-slate-600 bg-gray-50 dark:bg-slate-700/50 flex items-center justify-center overflow-hidden">
                                        <?php $has_image = !empty($category['image']) && file_exists("../img/" . $category['image']); ?>
                                        <img id="imagePreview"
                                             src="<?= $has_image ? '../img/' . htmlspecialchars($category['image']) : '' ?>"
                                             alt="Preview"
                                             class="<?= $has_image ? 'w-full h-full object-cover' : 'hidden' ?>">
                                        <svg id="imagePlaceholderIcon" class="w-8 h-8 text-gray-400 <?= $has_image ? 'hidden' : '' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                    </div>
                                    <div class="flex-1">
                                        <input type="file" id="image" name="image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                            class="form-input text-sm file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 dark:file:bg-slate-700 dark:file:text-indigo-300">
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1.5">
                                            JPG, JPEG, PNG, or WebP &middot; max 2MB
                                            <?= $has_image ? '&middot; Upload a new image to replace the current one' : '' ?>
                                        </p>
                                    </div>
                                </div>
                                <?php if (isset($errors['image'])): ?>
                                    <p class="form-error"><?= $errors['image'] ?></p>
                                <?php endif; ?>
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

    <script>
        document.getElementById('image')?.addEventListener('change', function() {
            const preview = document.getElementById('imagePreview');
            const icon = document.getElementById('imagePlaceholderIcon');
            if (this.files && this.files[0]) {
                preview.src = URL.createObjectURL(this.files[0]);
                preview.classList.remove('hidden');
                icon.classList.add('hidden');
            } else {
                preview.classList.toggle('hidden', !preview.getAttribute('src'));
                icon.classList.remove('hidden');
            }
        });
    </script>

    <?php include "../includes/footer.php"; ?>