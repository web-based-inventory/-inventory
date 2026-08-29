<?php
include "../includes/auth_check.php";
protectCategories('view');
include "../config/database.php";
include "../config/helpers.php";

$page_title = "View Category";

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$category = fetchOne($conn, "SELECT c.*, COUNT(p.id) AS product_count FROM categories c LEFT JOIN products p ON c.id = p.category_id WHERE c.id = ? GROUP BY c.id", [$id], "i");

if (!$category) {
    header("Location: index.php?error=" . urlencode("Category not found."));
    exit;
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

<body class="bg-[#eaf4fc] dark:bg-slate-900">
    <div class="flex min-h-screen">
        <?php include "../includes/sidebar.php"; ?>
        <div class="flex-1 flex flex-col min-w-0">
            <?php include "../includes/header.php"; ?>
            <main class="p-4 lg:p-6">
                <div class="flex justify-end gap-3 mb-6">
                    <a href="edit.php?id=<?= $category['id'] ?>" class="btn btn-primary">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        Edit
                    </a>
                    <a href="index.php" class="btn btn-secondary">Back to List</a>
                </div>

                <div class="card">
                    <div class="card-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="text-sm font-semibold text-gray-500">Category Name</label>
                                <p class="text-lg font-bold text-gray-900 mt-1"><?= htmlspecialchars($category['name']) ?></p>
                            </div>
                            <div>
                                <label class="text-sm font-semibold text-gray-500">Status</label>
                                <p class="mt-1">
                                    <?php if ($category['status'] === 'Active'): ?>
                                        <span class="badge badge-success"><span class="badge-dot"></span> Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-gray"><span class="badge-dot"></span> Inactive</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div>
                                <label class="text-sm font-semibold text-gray-500">Products Count</label>
                                <p class="mt-1">
                                    <?php $pcount = (int)($category['product_count'] ?? 0); ?>
                                    <?php if ($pcount > 0): ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-700">
                                            <?= $pcount ?> product<?= $pcount !== 1 ? 's' : '' ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-500">No products</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div>
                                <label class="text-sm font-semibold text-gray-500">Created Date</label>
                                <p class="text-gray-900 mt-1"><?= date('d M Y', strtotime($category['created_at'])) ?></p>
                            </div>
                            <div class="md:col-span-2">
                                <label class="text-sm font-semibold text-gray-500">Description</label>
                                <p class="text-gray-900 mt-1 whitespace-pre-wrap"><?= !empty($category['description']) ? htmlspecialchars($category['description']) : '<span class="text-gray-300">No description provided</span>' ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <?php include "../includes/toast.php"; ?>
    <?php include "../includes/modal.php"; ?>
    <?php include "../includes/footer.php"; ?>
</body>

</html>
