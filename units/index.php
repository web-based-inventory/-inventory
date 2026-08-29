<?php
include "../includes/auth_check.php";
protectUnits('view');
include "../config/database.php";
require_once __DIR__ . '/../config/helpers.php';

$action = $_GET['action'] ?? 'list';

function tableExists($conn, $table) {
    $res = @mysqli_query($conn, "SHOW TABLES LIKE '$table'");
    return $res && mysqli_num_rows($res) > 0;
}

$migrated = columnExists($conn, 'products', 'unit_id');
$units_exists = tableExists($conn, 'units');

// Handle Delete
if (isset($_GET['confirm_delete'])) {
    $id = (int)$_GET['confirm_delete'];
    if (!$units_exists) {
        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Please run the migration first (migrate_units.php).'];
    } elseif ($migrated) {
        $in_use = mysqli_fetch_assoc(@mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM products WHERE unit_id=$id"));
        if ($in_use['cnt'] > 0) {
            $_SESSION['toast'] = ['type' => 'error', 'message' => 'Cannot delete unit: it is assigned to ' . $in_use['cnt'] . ' product(s).'];
        } else {
            mysqli_query($conn, "DELETE FROM units WHERE unit_id=$id");
            $_SESSION['toast'] = ['type' => 'success', 'message' => 'Unit deleted successfully.'];
        }
    } else {
        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Please run the migration first (migrate_units.php).'];
    }
    header("Location: index.php");
    exit;
}

// Handle Add
if ($action === 'add' && isset($_POST['save'])) {
    $unit_name = mysqli_real_escape_string($conn, trim($_POST['unit_name']));
    $unit_symbol = mysqli_real_escape_string($conn, trim($_POST['unit_symbol']));
    $status = mysqli_real_escape_string($conn, $_POST['status']);

    if (empty($unit_name) || empty($unit_symbol)) {
        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unit name and symbol are required.'];
    } else {
        $check = mysqli_fetch_assoc(mysqli_query($conn, "SELECT unit_id FROM units WHERE unit_name='$unit_name' OR unit_symbol='$unit_symbol'"));
        if ($check) {
            $_SESSION['toast'] = ['type' => 'error', 'message' => 'A unit with that name or symbol already exists.'];
        } else {
            mysqli_query($conn, "INSERT INTO units (unit_name, unit_symbol, status) VALUES ('$unit_name', '$unit_symbol', '$status')");
            $_SESSION['toast'] = ['type' => 'success', 'message' => 'Unit added successfully.'];
            header("Location: index.php");
            exit;
        }
    }
}

// Handle Edit
if ($action === 'edit' && isset($_POST['update'])) {
    $id = (int)$_GET['id'];
    $unit_name = mysqli_real_escape_string($conn, trim($_POST['unit_name']));
    $unit_symbol = mysqli_real_escape_string($conn, trim($_POST['unit_symbol']));
    $status = mysqli_real_escape_string($conn, $_POST['status']);

    if (empty($unit_name) || empty($unit_symbol)) {
        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unit name and symbol are required.'];
    } else {
        $check = mysqli_fetch_assoc(mysqli_query($conn, "SELECT unit_id FROM units WHERE (unit_name='$unit_name' OR unit_symbol='$unit_symbol') AND unit_id != $id"));
        if ($check) {
            $_SESSION['toast'] = ['type' => 'error', 'message' => 'A unit with that name or symbol already exists.'];
        } else {
            mysqli_query($conn, "UPDATE units SET unit_name='$unit_name', unit_symbol='$unit_symbol', status='$status' WHERE unit_id=$id");
            $_SESSION['toast'] = ['type' => 'success', 'message' => 'Unit updated successfully.'];
            header("Location: index.php");
            exit;
        }
    }
}

// Fetch units
$units = [];
if ($units_exists) {
    if ($migrated) {
        $res = @mysqli_query($conn, "SELECT u.*, (SELECT COUNT(*) FROM products WHERE unit_id = u.unit_id) AS product_count FROM units u ORDER BY u.unit_name ASC");
    } else {
        $res = @mysqli_query($conn, "SELECT u.*, 0 AS product_count FROM units u ORDER BY u.unit_name ASC");
    }
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $units[] = $r;
        }
    }
}

// Single unit for edit
$unit = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $unit = @mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM units WHERE unit_id=$id"));
    if (!$unit) {
        header("Location: index.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unit Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include "../includes/theme-init.php"; ?>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body class="bg-[#eaf4fc] dark:bg-slate-900">
    <div class="flex min-h-screen">
        <?php include "../includes/sidebar.php"; ?>
        <div class="flex-1 flex flex-col">
            <?php $page_title = 'Unit Management'; include "../includes/header.php"; ?>
            <main class="p-6">

                <?php if ($action === 'add' || $action === 'edit'): ?>
                    <?php $is_edit = ($action === 'edit' && $unit); ?>
                    <div class="max-w-2xl mx-auto">
                        <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 mb-6">
                            <a href="index.php" class="hover:text-indigo-600 transition">Units</a>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            <span class="text-gray-900 dark:text-white font-semibold"><?= $is_edit ? 'Edit Unit' : 'Add Unit' ?></span>
                        </div>

                        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 overflow-hidden">
                            <div class="px-6 py-4 border-b border-gray-100 dark:border-slate-700 bg-gray-50 dark:bg-slate-700/50">
                                <h3 class="text-sm font-bold text-gray-700 dark:text-gray-200 uppercase tracking-wider flex items-center gap-2">
                                    <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                    </svg>
                                    <?= $is_edit ? 'Edit Unit Information' : 'New Unit Information' ?>
                                </h3>
                            </div>
                            <div class="p-6">
                                <form method="POST" class="space-y-5">
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Unit Name <span class="text-red-500">*</span></label>
                                        <input type="text" name="unit_name" value="<?= $is_edit ? htmlspecialchars($unit['unit_name']) : '' ?>" class="w-full border border-gray-300 dark:border-slate-600 rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-slate-700 dark:text-white transition" placeholder="e.g. Kilogram" required>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Unit Symbol <span class="text-red-500">*</span></label>
                                        <input type="text" name="unit_symbol" value="<?= $is_edit ? htmlspecialchars($unit['unit_symbol']) : '' ?>" class="w-full border border-gray-300 dark:border-slate-600 rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-slate-700 dark:text-white font-mono transition" placeholder="e.g. kg" required>
                                        <p class="text-xs text-gray-400 mt-1.5">Short code displayed in product forms and reports</p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Status <span class="text-red-500">*</span></label>
                                        <select name="status" class="w-full border border-gray-300 dark:border-slate-600 rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-slate-700 dark:text-white transition" required>
                                            <option value="Active" <?= ($is_edit && $unit['status'] === 'Active') ? 'selected' : '' ?>>Active</option>
                                            <option value="Inactive" <?= ($is_edit && $unit['status'] === 'Inactive') ? 'selected' : '' ?>>Inactive</option>
                                        </select>
                                    </div>

                                    <div class="flex justify-end gap-3 pt-4 border-t border-gray-200 dark:border-slate-700">
                                        <a href="index.php" class="px-6 py-3 bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-gray-300 rounded-xl text-sm font-semibold hover:bg-gray-200 dark:hover:bg-slate-600 transition">Cancel</a>
                                        <button name="<?= $is_edit ? 'update' : 'save' ?>" class="px-8 py-3 bg-indigo-600 text-white rounded-xl text-sm font-semibold hover:bg-indigo-700 transition shadow-sm shadow-indigo-200 dark:shadow-indigo-500/20">
                                            <?= $is_edit ? 'Update Unit' : 'Save Unit' ?>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                <?php else: ?>

                    <div class="flex justify-end items-center mb-6">
                        <?php if (checkPermission('units', 'add')): ?>
                        <a href="?action=add" class="bg-indigo-600 text-white px-6 py-3 rounded-xl">+ Add Unit</a>
                        <?php endif; ?>
                    </div>

                    <?php if (!$units_exists): ?>
                    <div class="bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 rounded-2xl p-4 mb-6">
                        <div class="flex gap-3">
                            <svg class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                            </svg>
                            <div class="text-sm text-red-700 dark:text-red-300">
                                <p class="font-semibold">Units Table Not Found</p>
                                <p class="mt-1">The units table doesn't exist yet. Please run <a href="../migrate_units.php" class="underline font-semibold hover:text-red-900">migrate_units.php</a> to create it and set up the unit system.</p>
                            </div>
                        </div>
                    </div>
                    <?php elseif (!$migrated): ?>
                    <div class="bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30 rounded-2xl p-4 mb-6">
                        <div class="flex gap-3">
                            <svg class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                            </svg>
                            <div class="text-sm text-amber-700 dark:text-amber-300">
                                <p class="font-semibold">Migration Required</p>
                                <p class="mt-1">The products table hasn't been migrated yet. Please run <a href="../migrate_units.php" class="underline font-semibold hover:text-amber-900">migrate_units.php</a> to link units to products.</p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="bg-white rounded-2xl shadow p-6">
                        <div class="table-wrap">
                            <table class="data-table w-full">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Unit Name</th>
                                        <th>Symbol</th>
                                        <th class="num">Products</th>
                                        <th class="center">Status</th>
                                        <th>Created</th>
                                        <?php if (checkPermission('units', 'edit') || checkPermission('units', 'delete')): ?>
                                        <th class="center">Action</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($units)): ?>
                                        <tr><td colspan="<?= (checkPermission('units', 'edit') || checkPermission('units', 'delete')) ? 7 : 6 ?>" class="px-6 py-12 text-center text-gray-400">No units found</td></tr>
                                    <?php else: ?>
                                        <?php $count = 1;
                                        foreach ($units as $u): ?>
                                            <tr>
                                                <td><?= $count++ ?></td>
                                                <td class="font-semibold"><?= htmlspecialchars($u['unit_name']) ?></td>
                                                <td class="font-mono text-sm"><?= htmlspecialchars($u['unit_symbol']) ?></td>
                                                <td class="num">
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-700"><?= $u['product_count'] ?> products</span>
                                                </td>
                                                <td class="center">
                                                    <?php if ($u['status'] === 'Active'): ?>
                                                        <span class="badge badge-success"><span class="badge-dot"></span> Active</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-danger"><span class="badge-dot"></span> Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                                                <?php if (checkPermission('units', 'edit') || checkPermission('units', 'delete')): ?>
                                                <td class="center">
                                                    <div class="actions flex gap-1">
                                                        <?php if (checkPermission('units', 'edit')): ?>
                                                        <a href="?action=edit&id=<?= $u['unit_id'] ?>" class="btn btn-sm bg-blue-100 text-blue-600 hover:bg-blue-200 rounded-lg">Edit</a>
                                                        <?php endif; ?>
                                                        <?php if (checkPermission('units', 'delete')): ?>
                                                        <button onclick="openDeleteModal(<?= $u['unit_id'] ?>, '<?= htmlspecialchars(addslashes($u['unit_name'])) ?>', 'index.php')" title="Delete" class="btn btn-sm bg-red-100 text-red-600 hover:bg-red-200 rounded-lg">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                        </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php endif; ?>

            </main>
        </div>
    </div>
    <?php include "../includes/toast.php"; ?>
    <?php include "../includes/modal.php"; ?>
    <?php include "../includes/form_guard.php"; ?>
    <?php include "../includes/footer.php"; ?>
</body>
</html>
