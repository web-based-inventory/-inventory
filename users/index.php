<?php
include "../includes/auth_check.php";
protectUsers('view');
include "../config/database.php";
include "../config/helpers.php";

$search = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? '';
$status_filter = $_GET['status'] ?? '';
$alert = '';

// Delete
if (isset($_GET['confirm_delete'])) {
    $id = (int)$_GET['confirm_delete'];
    if ($id != $_SESSION['user_id']) {
        $sales_check = mysqli_query($conn, "SELECT COUNT(*) as count FROM sales WHERE user_id=$id");
        $sales_row = mysqli_fetch_assoc($sales_check);
        $pur_check = mysqli_query($conn, "SELECT COUNT(*) as count FROM purchases WHERE user_id=$id");
        $pur_row = mysqli_fetch_assoc($pur_check);
        
        if ($sales_row['count'] > 0 || $pur_row['count'] > 0) {
            $alert = "Cannot delete user: They have " . $sales_row['count'] . " sale(s) and " . $pur_row['count'] . " purchase(s). Please set their status to Inactive instead.";
        } else {
            mysqli_query($conn, "DELETE FROM users WHERE id=$id");
            header("Location: index.php?success=" . urlencode("User deleted successfully."));
            exit;
        }
    } else {
        $alert = "Cannot delete your own account!";
    }
}

// Add
if (isset($_POST['save'])) {
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $password = $_POST['password'];
    $role = $_POST['role'];
    $status = $_POST['status'];

    $check = mysqli_query($conn, "SELECT id FROM users WHERE username='$username' OR email='$email'");
    if (mysqli_num_rows($check) > 0) {
        $alert = "Username or email already exists!";
    } else {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        if (mysqli_query($conn, "INSERT INTO users (username, name, email, password, role, status) VALUES ('$username', '$name', '$email', '$hashed', '$role', '$status')")) {
            header("Location: index.php");
            exit;
        }
    }
}

// Edit
if (isset($_POST['update'])) {
    $id = (int)$_POST['id'];
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $role = $_POST['role'];
    $status = $_POST['status'];
    $password = $_POST['password'];

    $check = mysqli_query($conn, "SELECT id FROM users WHERE (username='$username' OR email='$email') AND id != $id");
    if (mysqli_num_rows($check) > 0) {
        $alert = "Username or email already in use!";
    } else {
        if (!empty($password)) {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            mysqli_query($conn, "UPDATE users SET username='$username', name='$name', email='$email', password='$hashed', role='$role', status='$status' WHERE id=$id");
        } else {
            mysqli_query($conn, "UPDATE users SET username='$username', name='$name', email='$email', role='$role', status='$status' WHERE id=$id");
        }
        header("Location: index.php");
        exit;
    }
}

$sql = "SELECT u.*, 
        (SELECT COUNT(*) FROM sales WHERE user_id = u.id) as sales_count,
        (SELECT COUNT(*) FROM purchases WHERE user_id = u.id) as pur_count
        FROM users u WHERE 1";
if ($search) {
    $safe = mysqli_real_escape_string($conn, $search);
    $sql .= " AND (u.username LIKE '%$safe%' OR u.name LIKE '%$safe%' OR u.email LIKE '%$safe%')";
}
if ($role_filter) $sql .= " AND u.role='$role_filter'";
if ($status_filter) $sql .= " AND u.status='$status_filter'";
$sql .= " ORDER BY u.id DESC";
$page_title = "Users";
$result = mysqli_query($conn, $sql);
$edit_row = null;
if (isset($_GET['edit_id'])) {
    $edit_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE id=" . (int)$_GET['edit_id']));
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users - <?= htmlspecialchars(getShopSettings($conn)['shop_name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include "../includes/theme-init.php"; ?>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body class="bg-[#eaf4fc] dark:bg-slate-900">
    <div class="flex min-h-screen">
        <?php include "../includes/sidebar.php"; ?>
        <div class="flex-1 flex flex-col">
            <?php include "../includes/header.php"; ?>
            <main class="p-6">
                <div class="flex justify-end mb-6">
                    <button onclick="openModal('addModal')" class="bg-indigo-600 text-white px-5 py-2.5 rounded-xl hover:bg-indigo-700">+ Add User</button>
                </div>

                <?php if ($alert): ?>
                    <div class="bg-red-50 border border-red-200 text-red-700 px-5 py-3 rounded-xl mb-6"><?= $alert ?></div>
                <?php endif; ?>

                <form method="GET" class="bg-white p-4 rounded-xl shadow-sm flex gap-4 mb-6">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search users..." class="flex-1 border rounded-lg p-2.5">
                    <select name="role" class="border rounded-lg px-4 p-2.5">
                        <option value="">All Roles</option>
                        <option value="admin" <?= $role_filter == 'admin' ? 'selected' : '' ?>>Admin</option>
                        <option value="staff" <?= $role_filter == 'staff' ? 'selected' : '' ?>>Staff</option>
                        <option value="cashier" <?= $role_filter == 'cashier' ? 'selected' : '' ?>>Cashier</option>
                    </select>
                    <select name="status" class="border rounded-lg px-4 p-2.5">
                        <option value="">All Status</option>
                        <option value="Active" <?= $status_filter == 'Active' ? 'selected' : '' ?>>Active</option>
                        <option value="Inactive" <?= $status_filter == 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                    <button class="bg-indigo-600 text-white px-5 rounded-lg">Search</button>
                    <a href="index.php" class="border px-5 py-2.5 rounded-lg">Reset</a>
                </form>

                <div class="bg-white rounded-xl shadow-sm">
                    <div class="table-wrap">
                        <table class="data-table w-full">
                            <thead>
                                <tr>
                                    <th class="w-[3%]">#</th>
                                    <th class="w-[12%]">Username</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th class="w-[5%]">Role</th>
                                    <th class="center">Status</th>
                                    <th>Created</th>
                                    <th class="center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (mysqli_num_rows($result) > 0): $count = 1;
                                    while ($row = mysqli_fetch_assoc($result)): ?>
                                        <tr>
                                            <td><?= $count++ ?></td>
                                            <td class="font-semibold"><?= htmlspecialchars($row['username']) ?></td>
                                            <td><?= htmlspecialchars($row['name']) ?></td>
                                            <td><?= htmlspecialchars($row['email']) ?></td>
                                            <td class="center">
                                                <span class="<?= $row['role'] == 'admin' ? 'bg-purple-100 text-purple-600' : ($row['role'] == 'staff' ? 'bg-blue-100 text-blue-600' : 'bg-green-100 text-green-600') ?> px-3 py-1 rounded-full text-xs font-semibold">
                                                    <?= ucfirst($row['role']) ?>
                                                </span>
                                            </td>
                                            <td class="center">
                                                <span class="<?= $row['status'] == 'Active' ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600' ?> px-3 py-1 rounded-full text-xs font-semibold">
                                                    <?= $row['status'] ?>
                                                </span>
                                            </td>
                                            <td><?= date('d-m-Y', strtotime($row['created_at'])) ?></td>
                                            <td class="center">
                                                <div class="actions">
                                                    <a href="?edit_id=<?= $row['id'] ?>" class="bg-blue-100 text-blue-600 px-3 py-1.5 rounded text-sm">Edit</a>
                                                    <?php if ($row['id'] != $_SESSION['user_id']): ?>
                                                        <?php if ((int)$row['sales_count'] > 0 || (int)$row['pur_count'] > 0): ?>
                                                            <button onclick="alert('Cannot delete this user because they have <?= $row['sales_count'] ?> sale(s) and <?= $row['pur_count'] ?> purchase(s). Please set their status to Inactive instead.')" title="Cannot delete: User has transactions" class="bg-gray-100 text-gray-400 px-3 py-1.5 rounded text-sm cursor-not-allowed">Delete</button>
                                                        <?php else: ?>
                                                            <button onclick="openDeleteModal(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['name'])) ?>', 'index.php')" title="Delete" class="bg-red-100 text-red-600 px-3 py-1.5 rounded text-sm">Delete</button>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile;
                                else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-10 text-gray-500 dark:text-gray-400">No users found</td>
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
    <?php include "../includes/form_guard.php"; ?>
    <?php include "../includes/footer.php"; ?>

    <!-- Add Modal -->
    <div id="addModal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-2xl p-6 w-full max-w-lg">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-bold">Add User</h2>
                <button onclick="closeModal('addModal')" class="text-gray-400 hover:text-gray-700 dark:text-gray-300 text-2xl">&times;</button>
            </div>
            <form method="POST" class="space-y-3" data-form-guard="true">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-sm font-semibold text-gray-700 dark:text-gray-300">Username</label>
                        <input type="text" name="username" required class="w-full border rounded-lg p-2.5 mt-1">
                    </div>
                    <div>
                        <label class="text-sm font-semibold text-gray-700 dark:text-gray-300">Full Name</label>
                        <input type="text" name="name" required class="w-full border rounded-lg p-2.5 mt-1">
                    </div>
                </div>
                <div>
                    <label class="text-sm font-semibold text-gray-700 dark:text-gray-300">Email</label>
                    <input type="email" name="email" required class="w-full border rounded-lg p-2.5 mt-1">
                </div>
                <div>
                    <label class="text-sm font-semibold text-gray-700 dark:text-gray-300">Password</label>
                    <input type="password" name="password" required minlength="6" class="w-full border rounded-lg p-2.5 mt-1">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-sm font-semibold text-gray-700 dark:text-gray-300">Role</label>
                        <select name="role" class="w-full border rounded-lg p-2.5 mt-1">
                            <option value="staff">Staff</option>
                            <option value="admin">Admin</option>
                            <option value="cashier">Cashier</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-sm font-semibold text-gray-700 dark:text-gray-300">Status</label>
                        <select name="status" class="w-full border rounded-lg p-2.5 mt-1">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <button type="submit" name="save" class="w-full bg-indigo-600 text-white py-2.5 rounded-lg hover:bg-indigo-700">Save User</button>
            </form>
        </div>
    </div>

    <!-- Edit Modal -->
    <?php if ($edit_row): ?>
        <div id="editModal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
            <div class="bg-white rounded-2xl p-6 w-full max-w-lg">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-bold">Edit User</h2>
                    <a href="index.php" class="text-gray-400 hover:text-gray-700 dark:text-gray-300 text-2xl">&times;</a>
                </div>
                <form method="POST" class="space-y-3" data-form-guard="true">
                    <input type="hidden" name="id" value="<?= $edit_row['id'] ?>">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-sm font-semibold text-gray-700 dark:text-gray-300">Username</label>
                            <input type="text" name="username" value="<?= htmlspecialchars($edit_row['username']) ?>" required class="w-full border rounded-lg p-2.5 mt-1">
                        </div>
                        <div>
                            <label class="text-sm font-semibold text-gray-700 dark:text-gray-300">Full Name</label>
                            <input type="text" name="name" value="<?= htmlspecialchars($edit_row['name']) ?>" required class="w-full border rounded-lg p-2.5 mt-1">
                        </div>
                    </div>
                    <div>
                        <label class="text-sm font-semibold text-gray-700 dark:text-gray-300">Email</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($edit_row['email']) ?>" required class="w-full border rounded-lg p-2.5 mt-1">
                    </div>
                    <div>
                        <label class="text-sm font-semibold text-gray-700 dark:text-gray-300">Password <span class="text-gray-400 font-normal">(leave blank to keep)</span></label>
                        <input type="password" name="password" class="w-full border rounded-lg p-2.5 mt-1">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-sm font-semibold text-gray-700 dark:text-gray-300">Role</label>
                            <select name="role" class="w-full border rounded-lg p-2.5 mt-1">
                                <option value="staff" <?= $edit_row['role'] == 'staff' ? 'selected' : '' ?>>Staff</option>
                                <option value="admin" <?= $edit_row['role'] == 'admin' ? 'selected' : '' ?>>Admin</option>
                                <option value="cashier" <?= $edit_row['role'] == 'cashier' ? 'selected' : '' ?>>Cashier</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm font-semibold text-gray-700 dark:text-gray-300">Status</label>
                            <select name="status" class="w-full border rounded-lg p-2.5 mt-1">
                                <option value="Active" <?= $edit_row['status'] == 'Active' ? 'selected' : '' ?>>Active</option>
                                <option value="Inactive" <?= $edit_row['status'] == 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" name="update" class="w-full bg-indigo-600 text-white py-2.5 rounded-lg hover:bg-indigo-700">Update User</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <script>
        function openModal(id) {
            document.getElementById(id).classList.remove('hidden');
        }

        function closeModal(id) {
            document.getElementById(id).classList.add('hidden');
        }
    </script>
</body>

</html>