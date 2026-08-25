<?php
include "../includes/auth_check.php";
protectCategories('view');
include "../config/database.php";
include "../config/helpers.php";

$page_title = "Categories";

$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';

if (isset($_GET['confirm_delete'])) {
    protectCategories('delete');
    $delete_id = (int)$_GET['confirm_delete'];
    $del_check = mysqli_query($conn, "SELECT name, image FROM categories WHERE id = $delete_id");
    if (mysqli_num_rows($del_check) > 0) {
        $del_row = mysqli_fetch_assoc($del_check);
        mysqli_query($conn, "DELETE FROM categories WHERE id = $delete_id");
        // Remove the category image file (only paths managed by this module)
        if (!empty($del_row['image']) && strpos($del_row['image'], 'categories/') === 0 && is_file("../img/" . $del_row['image'])) {
            @unlink("../img/" . $del_row['image']);
        }
        header("Location: index.php?success=" . urlencode("Category deleted successfully"));
        exit;
    }
}

$where = "WHERE 1";
$params = [];
$types = "";

if ($search !== '') {
    $where .= " AND name LIKE ?";
    $params[] = "%$search%";
    $types .= "s";
}
if ($status_filter !== '') {
    $where .= " AND c.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

$sql = "SELECT c.*, COUNT(p.id) AS product_count FROM categories c LEFT JOIN products p ON c.id = p.category_id $where GROUP BY c.id ORDER BY c.id DESC";
$result = executeQuery($conn, $sql, $params, $types);
if (!$result) {
    $result = mysqli_query($conn, "SELECT c.*, COUNT(p.id) AS product_count FROM categories c LEFT JOIN products p ON c.id = p.category_id GROUP BY c.id ORDER BY c.id DESC");
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
        <div class="flex-1 flex flex-col min-w-0">
            <?php include "../includes/header.php"; ?>
            <main class="p-4 lg:p-6">
                <!-- Header Actions -->
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Categories</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Manage your product categories and groupings.</p>
                    </div>
                    <?php if (checkPermission('categories', 'add')): ?>
                        <a href="add.php" class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2.5 rounded-xl font-semibold text-sm transition shadow-sm flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            Add Category
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Search and Filter Toolbar -->
                <form method="GET" class="bg-white dark:bg-slate-800 p-4 rounded-xl shadow-sm border border-gray-200 dark:border-slate-700 mb-8 flex flex-col md:flex-row gap-4 items-center">
                    <div class="flex-1 w-full relative">
                        <svg class="w-5 h-5 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="w-full border border-gray-300 dark:border-slate-600 rounded-lg pl-10 pr-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-slate-700 dark:text-white transition" placeholder="Search categories...">
                    </div>
                    <div class="w-full md:w-auto flex flex-col sm:flex-row gap-3">
                        <select name="status" class="border border-gray-300 dark:border-slate-600 rounded-lg px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-slate-700 dark:text-white transition">
                            <option value="">All Status</option>
                            <option value="Active" <?= $status_filter === 'Active' ? 'selected' : '' ?>>Active</option>
                            <option value="Inactive" <?= $status_filter === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                        <button type="submit" class="bg-gray-900 dark:bg-white text-white dark:text-gray-900 px-5 py-2 rounded-lg text-sm font-semibold hover:bg-gray-800 dark:hover:bg-gray-100 transition whitespace-nowrap">Filter</button>
                        <a href="index.php" class="border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-gray-300 px-5 py-2 rounded-lg text-sm font-semibold hover:bg-gray-50 dark:hover:bg-slate-700 transition whitespace-nowrap text-center">Reset</a>
                    </div>
                </form>

                <!-- Category Card Grid -->
                <?php if ($result && mysqli_num_rows($result) > 0): ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
                        <?php while ($row = mysqli_fetch_assoc($result)): ?>
                            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 h-full flex flex-col hover:shadow-lg transition-all duration-200 group overflow-hidden">

                                <!-- Category Image Banner -->
                                <div class="relative h-36 bg-indigo-50 dark:bg-indigo-500/10 overflow-hidden flex-shrink-0">
                                    <?php $cat_image_ok = !empty($row['image']) && file_exists("../img/" . $row['image']); ?>
                                    <?php if ($cat_image_ok): ?>
                                        <img src="../img/<?= htmlspecialchars($row['image'] ?? '') ?>"
                                            class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                                            alt="<?= htmlspecialchars($row['name']) ?>" loading="lazy">
                                    <?php else: ?>
                                        <!-- Default placeholder when no image / missing file / broken path -->
                                        <div class="w-full h-full flex items-center justify-center">
                                            <svg class="w-12 h-12 text-indigo-300 dark:text-indigo-500/50 group-hover:scale-110 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
                                            </svg>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Badges -->
                                    <div class="absolute top-3 right-3 z-10 flex flex-col gap-2">
                                        <?php if ($row['status'] === 'Active'): ?>
                                            <span class="px-2.5 py-1 bg-emerald-100/90 text-emerald-700 text-[11px] font-bold rounded shadow-sm border border-emerald-200 uppercase tracking-wider backdrop-blur-sm">Active</span>
                                        <?php else: ?>
                                            <span class="px-2.5 py-1 bg-gray-100/90 text-gray-600 text-[11px] font-bold rounded shadow-sm border border-gray-200 uppercase tracking-wider backdrop-blur-sm">Inactive</span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Quick Actions -->
                                    <div class="absolute top-3 left-3 z-10 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                        <?php if (checkPermission('categories', 'edit')): ?>
                                            <a href="edit.php?id=<?= $row['id'] ?>" class="p-1.5 bg-white/90 dark:bg-slate-800/90 text-gray-600 dark:text-gray-300 rounded-lg shadow-sm hover:text-indigo-600 dark:hover:text-indigo-400 transition backdrop-blur-sm border border-gray-200 dark:border-slate-600" title="Edit">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                            </a>
                                        <?php endif; ?>
                                        <?php if (checkPermission('categories', 'delete')): ?>
                                            <button onclick="openDeleteModal(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['name'])) ?>', 'index.php')" class="p-1.5 bg-white/90 dark:bg-slate-800/90 text-gray-600 dark:text-gray-300 rounded-lg shadow-sm hover:text-red-600 dark:hover:text-red-400 transition backdrop-blur-sm border border-gray-200 dark:border-slate-600" title="Delete">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="p-6 flex-1 flex flex-col relative">
                                    <!-- Details -->
                                    <div class="text-center mb-4">
                                        <h3 class="text-[17px] font-bold text-gray-900 dark:text-white mb-1.5">
                                            <?= htmlspecialchars($row['name']) ?>
                                        </h3>
                                        <p class="text-[13px] text-gray-500 dark:text-gray-400 line-clamp-2 min-h-[2.5rem]">
                                            <?= !empty($row['description']) ? htmlspecialchars($row['description']) : '<span class="italic opacity-70">No description provided.</span>' ?>
                                        </p>
                                    </div>
                                    
                                    <div class="mt-auto">
                                        <div class="flex justify-center mb-5">
                                            <?php $pcount = (int)($row['product_count'] ?? 0); ?>
                                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-semibold <?= $pcount > 0 ? 'bg-blue-50 text-blue-700 dark:bg-slate-700 dark:text-blue-400 border border-blue-100 dark:border-slate-600' : 'bg-gray-50 text-gray-500 dark:bg-slate-700 dark:text-gray-400 border border-gray-100 dark:border-slate-600' ?>">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                                <?= $pcount ?> Product<?= $pcount !== 1 ? 's' : '' ?>
                                            </span>
                                        </div>

                                        <a href="../product/index.php?category=<?= $row['id'] ?>" class="block w-full py-2.5 bg-gray-50 hover:bg-indigo-50 dark:bg-slate-700/50 dark:hover:bg-slate-600 text-gray-700 hover:text-indigo-600 dark:text-gray-300 dark:hover:text-white font-semibold text-center rounded-xl border border-gray-200 dark:border-slate-600 hover:border-indigo-200 dark:hover:border-slate-500 transition-colors text-[13px] flex items-center justify-center gap-2">
                                            View Products
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 p-12 text-center">
                        <div class="w-16 h-16 bg-gray-50 dark:bg-slate-700 rounded-full flex items-center justify-center mx-auto mb-4">
                            <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
                            </svg>
                        </div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">No categories found</h3>
                        <p class="text-gray-500 dark:text-gray-400 mb-6 text-sm">We couldn't find any categories matching your current filters.</p>
                        <?php if (checkPermission('categories', 'add')): ?>
                            <a href="add.php" class="inline-flex items-center gap-2 bg-indigo-600 text-white px-5 py-2.5 rounded-xl font-semibold text-sm hover:bg-indigo-700 transition shadow-sm">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                Add Category
                            </a>
                        <?php endif; ?>
                        <a href="index.php" class="inline-flex items-center gap-2 bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-gray-300 px-5 py-2.5 rounded-xl font-semibold text-sm hover:bg-gray-50 dark:hover:bg-slate-700 transition ml-2 shadow-sm">
                            Clear Filters
                        </a>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <?php include "../includes/toast.php"; ?>
    <?php include "../includes/modal.php"; ?>
    <?php include "../includes/footer.php"; ?>
</body>

</html>