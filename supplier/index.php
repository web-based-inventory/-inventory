<?php
include "../includes/auth_check.php";
protectSuppliers('view');
include "../config/database.php";
include "../config/helpers.php";
$page_title = "Suppliers";

// Ensure all supplier balances are up-to-date before displaying
recalcAllSupplierBalances($conn);





// REPAIR ALL SUPPLIER BALANCES & LEDGERS

if (isset($_GET['repair'])) {
    protectSuppliers('edit');

    // 0. SAFETY SNAPSHOT — save current data before touching anything.
    //    If the snapshot cannot be written, refuse to repair.
    $backup_file = backupTablesToSql($conn, [
        'suppliers',
        'purchases',
        'purchase_payments',
        'supplier_payments',
        'supplier_ledger'
    ]);
    if ($backup_file === false) {
        header("Location:index.php?error=" . urlencode("Could not create the pre-repair backup (check backups/ folder permissions). Repair aborted - nothing was changed."));
        exit;
    }

    // 1. Re-derive per-purchase tracking (total_paid / remaining / status)
    //    from purchase_payments real-time data.
    $p_res = mysqli_query($conn, "SELECT id FROM purchases");
    while ($p = mysqli_fetch_assoc($p_res)) {
        updatePurchasePaymentStatus($conn, (int)$p['id']);
    }

    createSupplierLedgerTable($conn);

    // 2. For every supplier: recompute balance columns from purchases +
    //    supplier_payments (single source of truth), then rebuild the
    //    ledger from the same sources so both always match.
    $s_res = mysqli_query($conn, "SELECT id FROM suppliers");
    while ($s = mysqli_fetch_assoc($s_res)) {
        $sid = (int)$s['id'];
        recalcSupplierBalance($conn, $sid);
        rebuildSupplierLedger($conn, $sid);
    }

    $backup_name = basename($backup_file);
    header("Location:index.php?success=" . urlencode("All supplier balances and ledgers have been repaired. Backup saved as backups/$backup_name"));
    exit;
}

// DELETE SUPPLIER


if (isset($_GET['confirm_delete'])) {
    protectSuppliers('delete');
    $id = (int)$_GET['confirm_delete'];

    // Check if supplier has purchases
    $check = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM purchases WHERE supplier_id=$id");
    $row = mysqli_fetch_assoc($check);
    if ($row['cnt'] > 0) {
        header("Location:index.php?error=" . urlencode("Cannot delete supplier: it has " . $row['cnt'] . " purchase(s)."));
        exit;
    }

    mysqli_query(

        $conn,

        "DELETE FROM suppliers WHERE id=$id"



    );



    header("Location:index.php?success=" . urlencode("Supplier deleted successfully"));
    exit;
}








// UPDATE SUPPLIER


if (isset($_POST['update_supplier'])) {


    $id = $_POST['id'];

    $name = $_POST['supplier_name'];

    $phone = $_POST['phone'];

    $email = $_POST['email'];

    $address = $_POST['address'];

    $status = $_POST['status'];



    $sql = "

UPDATE suppliers SET

supplier_name='$name',

phone='$phone',

email='$email',

address='$address',

status='$status'


WHERE id='$id'

";



    mysqli_query($conn, $sql);



    header("Location:index.php");
}








// SEARCH FILTER


$search = "";

$status = "";



if (isset($_GET['search'])) {

    $search = $_GET['search'];
}


if (isset($_GET['status'])) {

    $status = $_GET['status'];
}



$sql = "

SELECT * FROM suppliers

WHERE 1

";



if ($search != "") {


    $sql .= "

AND (

supplier_name LIKE '%$search%'

OR phone LIKE '%$search%'

OR email LIKE '%$search%'

)

";
}



if ($status != "") {


    $sql .= " AND status='$status'";
}



$sql .= " ORDER BY id DESC";




$result = mysqli_query($conn, $sql);



?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <title>Supplier Management</title>

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
                <?php if (isset($_GET['success'])): ?>
                    <div class="mb-4 bg-emerald-50 border border-emerald-200 text-emerald-700 px-5 py-4 rounded-xl flex items-start gap-3 shadow-sm">
                        <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span class="text-sm font-medium"><?= htmlspecialchars($_GET['success']) ?></span>
                    </div>
                <?php endif; ?>
                <?php if (isset($_GET['error'])): ?>
                    <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-5 py-4 rounded-xl flex items-start gap-3 shadow-sm">
                        <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span class="text-sm font-medium"><?= htmlspecialchars($_GET['error']) ?></span>
                    </div>
                <?php endif; ?>
                <div class="flex justify-end items-center">
                    <a href="add.php"
                        class="bg-indigo-600 text-white px-6 py-3 rounded-xl">
                        + Add Supplier
                    </a>
                </div>

                <!-- Search -->
                <form method="GET"
                    class="bg-white p-5 rounded-2xl shadow mt-8 flex gap-4">
                    <input
                        name="search"
                        value="<?= $search ?>"
                        placeholder="Search supplier..."
                        class="flex-1 border rounded-xl px-4 py-3">
                    <select
                        name="status"
                        class="border rounded-xl px-4 py-3">
                        <option value="">All Status</option>
                        <option value="Active" <?= $status === 'Active' ? 'selected' : '' ?>>Active</option>
                        <option value="Inactive" <?= $status === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                    <button class="bg-indigo-600 text-white px-6 py-3 rounded-xl">Search</button>
                    <a href="index.php" class="border px-6 py-3 rounded-xl">Reset</a>
                </form>
                <!-- Table -->
                <div class="bg-white rounded-2xl shadow mt-8 p-6">
                    <div class="table-wrap">
                        <table class="data-table w-full">
                            <thead>
                                <tr>
                                    <th class="w-[6%]">#</th>
                                    <th>Supplier</th>
                                    <th>Contact Person</th>
                                    <th class="w-[8%]">Phone</th>
                                    <th class="center">Status</th>
                                    <th class="center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $count = 1;
                                while ($row = mysqli_fetch_assoc($result)) {
                                ?>
                                    <tr>
                                        <td><?= $count++ ?></td>
                                        <td class="font-semibold"><?= htmlspecialchars($row['supplier_name']) ?></td>
                                        <td><?= htmlspecialchars($row['contact_person'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($row['phone']) ?></td>
                                        <td class="center">
                                            <?php if ($row['status'] == "Active") { ?>
                                                <span class="badge badge-success"><span class="badge-dot"></span> Active</span>
                                            <?php } else { ?>
                                                <span class="badge badge-danger"><span class="badge-dot"></span> Inactive</span>
                                            <?php } ?>
                                        </td>
                                        <!-- <td class="center">
                                            <?php
                                            $outstanding = (float)($row['outstanding_balance'] ?? 0);
                                            if ($outstanding > 0) { ?>
                                                <span class="badge badge-danger"><span class="badge-dot"></span> <?= number_format($outstanding, 0) ?> MMK</span>
                                            <?php } else { ?>
                                                <span class="badge badge-success"><span class="badge-dot"></span> 0 MMK</span>
                                            <?php } ?>
                                        </td>
                                        <td class="center">
                                            <?php
                                            $adv_credit = (float)($row['advance_credit'] ?? 0);
                                            if ($adv_credit > 0) { ?>
                                                <span class="badge badge-success"><span class="badge-dot"></span> <?= number_format($adv_credit, 0) ?> MMK</span>
                                            <?php } else { ?>
                                                <span class="text-sm text-gray-500 dark:text-gray-400">0 MMK</span>
                                            <?php } ?>
                                        </td> -->
                                        <td class="center">
                                            <div class="actions flex gap-1">
                                                <a href="view.php?id=<?= $row['id'] ?>" class="btn btn-sm bg-indigo-100 text-indigo-600 hover:bg-indigo-200 rounded-lg">View</a>
                                                <?php if (checkPermission('suppliers', 'edit')): ?>
                                                    <a href="edit.php?id=<?= $row['id'] ?>" class="btn btn-sm bg-blue-100 text-blue-600 hover:bg-blue-200 rounded-lg">Edit</a>
                                                <?php endif; ?>
                                                <?php if (checkPermission('suppliers', 'delete')): ?>
                                                    <button onclick="openDeleteModal(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['supplier_name'])) ?>', 'index.php')" title="Delete" class="btn btn-sm bg-red-100 text-red-600 hover:bg-red-200 rounded-lg">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                        </svg>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php } ?>
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