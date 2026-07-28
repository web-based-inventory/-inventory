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
    $del_check = mysqli_query($conn, "SELECT name FROM categories WHERE id = $delete_id");
    if (mysqli_num_rows($del_check) > 0) {
        mysqli_query($conn, "DELETE FROM categories WHERE id = $delete_id");
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
    $where .= " AND status = ?";
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
                <div class="flex justify-end items-center mb-6">
                    <?php if (checkPermission('categories', 'add')): ?>
                    <a href="add.php" class="bg-indigo-600 text-white px-6 py-3 rounded-xl">+ Add Category</a>
                    <?php endif; ?>
                </div>

                <form method="GET" class="bg-white p-5 rounded-2xl shadow mb-6 flex gap-4">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search category..." class="flex-1 border rounded-xl px-4 py-3">
                    <select name="status" class="border rounded-xl px-4 py-3">
                        <option value="">All Status</option>
                        <option value="Active" <?= $status_filter === 'Active' ? 'selected' : '' ?>>Active</option>
                        <option value="Inactive" <?= $status_filter === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                    <button type="submit" class="bg-indigo-600 text-white px-6 py-3 rounded-xl">Search</button>
                    <a href="index.php" class="border px-6 py-3 rounded-xl">Reset</a>
                </form>

                <div class="bg-white rounded-2xl shadow p-6">
                    <div class="table-wrap">
                        <table class="data-table w-full">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Category Name</th>
                                    <th>Description</th>
                                    <th class="num">Products</th>
                                    <th class="center">Status</th>
                                    <th>Created</th>
                                    <?php if (checkPermission('categories', 'edit') || checkPermission('categories', 'delete')): ?>
                                    <th class="center">Action</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($result && mysqli_num_rows($result) > 0): ?>
                                    <?php $count = 1; ?>
                                    <?php while ($row = mysqli_fetch_assoc($result)): ?>
                                        <tr>
                                            <td><?= $count++ ?></td>
                                            <td class="font-semibold"><?= htmlspecialchars($row['name']) ?></td>
                                            <td class="text-sm"><?= !empty($row['description']) ? htmlspecialchars($row['description']) : '<span class="text-gray-300">—</span>' ?></td>
                                            <td class="num">
                                                <?php $pcount = (int)($row['product_count'] ?? 0); ?>
                                                <?php if ($pcount > 0): ?>
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-700">
                                                        <?= $pcount ?> product<?= $pcount !== 1 ? 's' : '' ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-500">0</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="center">
                                                <?php if ($row['status'] === 'Active'): ?>
                                                    <span class="badge badge-success"><span class="badge-dot"></span> Active</span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger"><span class="badge-dot"></span> Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= date('d M Y', strtotime($row['created_at'])) ?></td>
                                            <?php if (checkPermission('categories', 'edit') || checkPermission('categories', 'delete')): ?>
                                            <td class="center">
                                                <div class="actions flex gap-1">
                                                    <?php if (checkPermission('categories', 'edit')): ?>
                                                    <a href="edit.php?id=<?= $row['id'] ?>" class="btn btn-sm bg-blue-100 text-blue-600 hover:bg-blue-200 rounded-lg">Edit</a>
                                                    <?php endif; ?>
                                                    <?php if (checkPermission('categories', 'delete')): ?>
                                                    <button onclick="openDeleteModal(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['name'])) ?>', 'index.php')" title="Delete" class="btn btn-sm bg-red-100 text-red-600 hover:bg-red-200 rounded-lg">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="<?= (checkPermission('categories', 'edit') || checkPermission('categories', 'delete')) ? 7 : 6 ?>" class="px-6 py-12 text-center text-gray-400">No categories found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
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