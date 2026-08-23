<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$role = $_SESSION['role'] ?? 'staff';
$name = $_SESSION['name'] ?? 'User';
$page_title = $page_title ?? 'Dashboard';

$breadcrumb_parts = ['Dashboard'];
if ($page_title !== 'Dashboard') {
    $breadcrumb_parts[] = $page_title;
}

$notif_count = 0;
$low_stock_products = [];
$out_of_stock_products = [];
$price_update_products = [];
$supplier_payment_due = [];

if (isset($conn)) {
    // Low stock notifications (all roles)
    $ls_count_result = mysqli_query($conn, "SELECT COUNT(*) AS count FROM products WHERE current_stock > 0 AND current_stock <= reorder_level AND status='Active'");
    $ls_count = 0;
    if ($ls_count_result) {
        $ls_count = (int)mysqli_fetch_assoc($ls_count_result)['count'];
    }
    if ($ls_count > 0) {
        $ls_result = mysqli_query($conn, "SELECT id, product_name, current_stock, reorder_level FROM products WHERE current_stock > 0 AND current_stock <= reorder_level AND status='Active' ORDER BY current_stock ASC LIMIT 10");
        while ($row = mysqli_fetch_assoc($ls_result)) {
            $low_stock_products[] = $row;
        }
    }

    // Out of stock notifications (all roles)
    $os_count_result = mysqli_query($conn, "SELECT COUNT(*) AS count FROM products WHERE current_stock = 0 AND status='Active'");
    $os_count = 0;
    if ($os_count_result) {
        $os_count = (int)mysqli_fetch_assoc($os_count_result)['count'];
    }
    if ($os_count > 0) {
        $os_result = mysqli_query($conn, "SELECT id, product_name, current_stock, reorder_level FROM products WHERE current_stock = 0 AND status='Active' ORDER BY product_name ASC LIMIT 10");
        while ($row = mysqli_fetch_assoc($os_result)) {
            $out_of_stock_products[] = $row;
        }
    }

    // Profit/Loss alerts (admin only)
    $pu_count = 0;
    if ($role === 'admin') {
        $pu_count_result = mysqli_query($conn, "SELECT COUNT(*) AS count FROM products WHERE selling_price <= purchase_price AND purchase_price > 0 AND status='Active'");
        if ($pu_count_result) {
            $pu_count = (int)mysqli_fetch_assoc($pu_count_result)['count'];
        }
        if ($pu_count > 0) {
            $pu_result = mysqli_query($conn, "SELECT id, product_name, purchase_price, selling_price FROM products WHERE selling_price <= purchase_price AND purchase_price > 0 AND status='Active' ORDER BY product_name ASC LIMIT 10");
            while ($row = mysqli_fetch_assoc($pu_result)) {
                $price_update_products[] = $row;
            }
        }
    }

    // Supplier payment due notifications (admin and staff)
    $spd_count = 0;
    if (in_array($role, ['admin', 'staff'])) {
        // Refresh supplier balances from real-time data so notifications are accurate
        if (isset($conn) && function_exists('recalcAllSupplierBalances')) {
            recalcAllSupplierBalances($conn);
        }
        $spd_count_result = mysqli_query($conn, "SELECT COUNT(*) AS count FROM suppliers WHERE outstanding_balance > 0 AND status = 'Active'");
        if ($spd_count_result) {
            $spd_count = (int)mysqli_fetch_assoc($spd_count_result)['count'];
        }
        if ($spd_count > 0) {
            $spd_result = mysqli_query($conn, "SELECT id, supplier_name, outstanding_balance FROM suppliers WHERE outstanding_balance > 0 AND status = 'Active' ORDER BY outstanding_balance DESC LIMIT 10");
            while ($row = mysqli_fetch_assoc($spd_result)) {
                $supplier_payment_due[] = $row;
            }
        }
    }

    // Calculate total notification count based on role
    $notif_count = $ls_count + $os_count;
    if ($role === 'admin') {
        $notif_count += $pu_count;
    }
    if (in_array($role, ['admin', 'staff'])) {
        $notif_count += $spd_count;
    }
}
?>
<header class="sticky top-0 z-30 bg-white/80 dark:bg-slate-800/80 backdrop-blur-lg border-b border-gray-200/60 dark:border-slate-700/60 shadow-sm">
    <div class="px-4 lg:px-6 h-16 flex items-center justify-between">
        <!-- Left: Mobile Menu + Title & Breadcrumbs -->
        <div class="flex items-center gap-4">
            <button onclick="toggleMobileSidebar()" class="lg:hidden p-2 -ml-2 text-gray-500 dark:text-slate-400 hover:text-indigo-600 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-700 transition-all duration-200">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
            <div>
                <h1 class="text-lg font-bold text-gray-900 dark:text-slate-100 leading-tight"><?= htmlspecialchars($page_title) ?></h1>
                <nav class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-slate-400 mt-0.5">
                    <?php foreach ($breadcrumb_parts as $i => $part): ?>
                        <?php if ($i > 0): ?>
                            <svg class="w-3 h-3 text-gray-300 dark:text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        <?php endif; ?>
                        <?php if ($i === 0): ?>
                            <a href="../dashboard/index.php" class="hover:text-indigo-600 transition-colors duration-200"><?= htmlspecialchars($part) ?></a>
                        <?php else: ?>
                            <span class="<?= $i === count($breadcrumb_parts) - 1 ? 'text-gray-700 dark:text-slate-200 font-medium' : '' ?>"><?= htmlspecialchars($part) ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </nav>
            </div>
        </div>

        <!-- Right: Actions -->
        <div class="flex items-center gap-3">
            <!-- Theme Toggle -->
            <button onclick="toggleTheme()" id="themeToggleBtn" class="header-btn relative flex items-center justify-center w-10 h-10 text-gray-500 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 rounded-xl border border-gray-200 dark:border-slate-600 hover:border-indigo-300 dark:hover:border-indigo-500 bg-white dark:bg-slate-700/50 hover:bg-gray-50 dark:hover:bg-slate-700 transition-all duration-200 shadow-sm hover:shadow" title="Toggle Theme">
                <!-- Sun (light mode) -->
                <svg id="iconLight" class="w-[18px] h-[18px] absolute inset-0 m-auto" style="opacity:0;transition:opacity .3s" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="5"/>
                    <line x1="12" y1="1" x2="12" y2="3"/>
                    <line x1="12" y1="21" x2="12" y2="23"/>
                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
                    <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                    <line x1="1" y1="12" x2="3" y2="12"/>
                    <line x1="21" y1="12" x2="23" y2="12"/>
                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
                    <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                </svg>
                <!-- Moon (dark mode) -->
                <svg id="iconDark" class="w-[18px] h-[18px] absolute inset-0 m-auto" style="opacity:0;transition:opacity .3s" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                </svg>
            </button>
            <script>
            (function(){
                var t=localStorage.getItem('theme')||'dark';
                var s=document.getElementById('iconLight');
                var m=document.getElementById('iconDark');
                if(!s||!m)return;
                if(t==='light'){s.style.opacity='1';m.style.opacity='0';}
                else{m.style.opacity='1';s.style.opacity='0';}
            })();
            </script>

            <!-- Notifications -->
            <div class="relative">
                <button onclick="toggleDropdown('notifDropdown')" class="header-btn relative flex items-center justify-center w-10 h-10 text-gray-500 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 rounded-xl border border-gray-200 dark:border-slate-600 hover:border-indigo-300 dark:hover:border-indigo-500 bg-white dark:bg-slate-700/50 hover:bg-gray-50 dark:hover:bg-slate-700 transition-all duration-200 shadow-sm hover:shadow" title="Notifications">
                    <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                    <?php if ($notif_count > 0): ?>
                        <span class="absolute -top-1 -right-1 bg-red-500 text-white text-[10px] font-bold flex items-center justify-center rounded-full shadow-sm ring-2 ring-white dark:ring-slate-800" style="min-width:18px;height:18px;padding:0 4px"><?= $notif_count ?></span>
                    <?php endif; ?>
                </button>
                <div id="notifDropdown" class="hidden absolute right-0 mt-2 w-96 max-w-[calc(100vw-2rem)] bg-white dark:bg-slate-800 rounded-xl shadow-xl border border-gray-200 dark:border-slate-700 z-50 overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-100 dark:border-slate-700 bg-gray-50/50 dark:bg-slate-700/30">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-semibold text-gray-800 dark:text-slate-200">Notifications</p>
                            <?php if ($notif_count > 0): ?>
                                <span class="text-xs font-medium text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-500/10 px-2 py-0.5 rounded-full"><?= $notif_count ?> new</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="max-h-[28rem] overflow-y-auto divide-y divide-gray-50 dark:divide-slate-700/50">
                        <?php if (count($out_of_stock_products) > 0): ?>
                            <div class="px-4 py-2 bg-red-50/60 dark:bg-red-500/5">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-red-600 dark:text-red-400 flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full bg-red-400 flex-shrink-0"></span>
                                    Out of Stock
                                    <span class="ml-auto bg-red-100 dark:bg-red-500/20 text-red-600 dark:text-red-400 px-1.5 py-0.5 rounded-full text-[10px] font-bold"><?= count($out_of_stock_products) ?></span>
                                </p>
                            </div>
                            <?php foreach ($out_of_stock_products as $item): ?>
                                <div class="px-4 py-3 hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors duration-150 group">
                                    <div class="flex items-start gap-3">
                                        <div class="w-8 h-8 rounded-lg bg-red-50 dark:bg-red-500/10 flex items-center justify-center flex-shrink-0 mt-0.5 ring-1 ring-red-200 dark:ring-red-500/20">
                                            <svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                            </svg>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-semibold text-gray-800 dark:text-slate-200 truncate"><?= htmlspecialchars($item['product_name']) ?></p>
                                            <div class="flex items-center gap-3 mt-1">
                                                <span class="inline-flex items-center gap-1 text-[11px] font-medium text-red-600 dark:text-red-400">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                                    Out of Stock
                                                </span>
                                            </div>
                                            <a href="../purchase/add.php" class="mt-2 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-semibold bg-red-500 hover:bg-red-600 text-white transition-all duration-150 shadow-sm hover:shadow">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                                Restock Now
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (count($low_stock_products) > 0): ?>
                            <div class="px-4 py-2 bg-orange-50/60 dark:bg-orange-500/5">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-orange-600 dark:text-orange-400 flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full bg-orange-400 flex-shrink-0"></span>
                                    Low Stock Alerts
                                    <span class="ml-auto bg-orange-100 dark:bg-orange-500/20 text-orange-600 dark:text-orange-400 px-1.5 py-0.5 rounded-full text-[10px] font-bold"><?= count($low_stock_products) ?></span>
                                </p>
                            </div>
                            <?php foreach ($low_stock_products as $item): ?>
                                <div class="px-4 py-3 hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors duration-150 group">
                                    <div class="flex items-start gap-3">
                                        <div class="w-8 h-8 rounded-lg bg-orange-50 dark:bg-orange-500/10 flex items-center justify-center flex-shrink-0 mt-0.5 ring-1 ring-orange-200 dark:ring-orange-500/20">
                                            <svg class="w-4 h-4 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                            </svg>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2">
                                                <p class="text-sm font-semibold text-gray-800 dark:text-slate-200 truncate"><?= htmlspecialchars($item['product_name']) ?></p>
                                            </div>
                                            <div class="flex items-center gap-3 mt-1">
                                                <span class="inline-flex items-center gap-1 text-[11px] font-medium text-orange-600 dark:text-orange-400">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                                    Stock: <?= $item['current_stock'] ?>
                                                </span>
                                                <span class="text-gray-300 dark:text-slate-600">|</span>
                                                <span class="text-[11px] font-medium text-gray-500 dark:text-slate-400">Min: <?= $item['reorder_level'] ?></span>
                                            </div>
                                            <a href="../purchase/add.php" class="mt-2 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-semibold bg-orange-500 hover:bg-orange-600 text-white transition-all duration-150 shadow-sm hover:shadow">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                                Restock
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (count($supplier_payment_due) > 0): ?>
                            <div class="px-4 py-2 bg-purple-50/60 dark:bg-purple-500/5">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-purple-600 dark:text-purple-400 flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full bg-purple-400 flex-shrink-0"></span>
                                    Supplier Payment Due
                                    <span class="ml-auto bg-purple-100 dark:bg-purple-500/20 text-purple-600 dark:text-purple-400 px-1.5 py-0.5 rounded-full text-[10px] font-bold"><?= count($supplier_payment_due) ?></span>
                                </p>
                            </div>
                            <?php foreach ($supplier_payment_due as $item): ?>
                                <div class="px-4 py-3 hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors duration-150 group">
                                    <div class="flex items-start gap-3">
                                        <div class="w-8 h-8 rounded-lg bg-purple-50 dark:bg-purple-500/10 flex items-center justify-center flex-shrink-0 mt-0.5 ring-1 ring-purple-200 dark:ring-purple-500/20">
                                            <svg class="w-4 h-4 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                                            </svg>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-semibold text-gray-800 dark:text-slate-200 truncate"><?= htmlspecialchars($item['supplier_name']) ?></p>
                                            <div class="flex items-center gap-3 mt-1">
                                                <span class="inline-flex items-center gap-1 text-[11px] font-medium text-purple-600 dark:text-purple-400">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                    <?= number_format($item['outstanding_balance'], 0) ?> MMK due
                                                </span>
                                            </div>
                                            <a href="../supplier/ledger.php?id=<?= $item['id'] ?>" class="mt-2 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-semibold bg-purple-500 hover:bg-purple-600 text-white transition-all duration-150 shadow-sm hover:shadow">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                                View Ledger
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (count($price_update_products) > 0): ?>
                            <?php
                                $pp_items = [];
                                $sp_items = [];
                                foreach ($price_update_products as $item) {
                                    $pp = (float)$item['purchase_price'];
                                    $sp = (float)$item['selling_price'];
                                    if ($pp == $sp) {
                                        $pp_items[] = $item;
                                    } else {
                                        $sp_items[] = $item;
                                    }
                                }
                            ?>
                            <?php if (count($sp_items) > 0): ?>
                                <div class="px-4 py-2 bg-red-50/60 dark:bg-red-500/5">
                                    <p class="text-[11px] font-bold uppercase tracking-wider text-red-600 dark:text-red-400 flex items-center gap-1.5">
                                        <span class="w-2 h-2 rounded-full bg-red-400 flex-shrink-0"></span>
                                        🔴 Loss Risk
                                        <span class="ml-auto bg-red-100 dark:bg-red-500/20 text-red-600 dark:text-red-400 px-1.5 py-0.5 rounded-full text-[10px] font-bold"><?= count($sp_items) ?></span>
                                    </p>
                                </div>
                                <?php foreach ($sp_items as $item): ?>
                                    <?php
                                        $pp = (float)$item['purchase_price'];
                                        $sp = (float)$item['selling_price'];
                                    ?>
                                    <div class="px-4 py-3 hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors duration-150">
                                        <div class="flex items-start gap-3">
                                            <div class="w-8 h-8 rounded-lg bg-red-50 dark:bg-red-500/10 flex items-center justify-center flex-shrink-0 mt-0.5 ring-1 ring-red-200 dark:ring-red-500/20">
                                                <svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6" />
                                                </svg>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-semibold text-gray-800 dark:text-slate-200 truncate"><?= htmlspecialchars($item['product_name']) ?></p>
                                                <div class="flex items-center gap-2 mt-1">
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-red-100 dark:bg-red-500/20 text-red-600 dark:text-red-400">🔴 Loss Risk</span>
                                                </div>
                                                <div class="flex items-center gap-3 mt-1.5 text-[11px]">
                                                    <span class="text-gray-500 dark:text-slate-400">Purchase: <span class="font-bold text-red-600 dark:text-red-400"><?= number_format($pp) ?> Ks</span></span>
                                                    <span class="text-gray-500 dark:text-slate-400">Selling: <span class="font-bold text-gray-700 dark:text-slate-300"><?= number_format($sp) ?> Ks</span></span>
                                                </div>
                                                <a href="../product/index.php?action=edit&id=<?= $item['id'] ?>" class="mt-2 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-semibold bg-red-500 hover:bg-red-600 text-white transition-all duration-150 shadow-sm hover:shadow">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                                    Update Price
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <?php if (count($pp_items) > 0): ?>
                                <div class="px-4 py-2 bg-amber-50/60 dark:bg-amber-500/5">
                                    <p class="text-[11px] font-bold uppercase tracking-wider text-amber-600 dark:text-amber-400 flex items-center gap-1.5">
                                        <span class="w-2 h-2 rounded-full bg-amber-400 flex-shrink-0"></span>
                                        ⚠️ No Profit
                                        <span class="ml-auto bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400 px-1.5 py-0.5 rounded-full text-[10px] font-bold"><?= count($pp_items) ?></span>
                                    </p>
                                </div>
                                <?php foreach ($pp_items as $item): ?>
                                    <?php
                                        $pp = (float)$item['purchase_price'];
                                        $sp = (float)$item['selling_price'];
                                    ?>
                                    <div class="px-4 py-3 hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors duration-150">
                                        <div class="flex items-start gap-3">
                                            <div class="w-8 h-8 rounded-lg bg-amber-50 dark:bg-amber-500/10 flex items-center justify-center flex-shrink-0 mt-0.5 ring-1 ring-amber-200 dark:ring-amber-500/20">
                                                <svg class="w-4 h-4 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                                                </svg>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-semibold text-gray-800 dark:text-slate-200 truncate"><?= htmlspecialchars($item['product_name']) ?></p>
                                                <div class="flex items-center gap-2 mt-1">
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400">⚠️ No Profit</span>
                                                </div>
                                                <div class="flex items-center gap-3 mt-1.5 text-[11px]">
                                                    <span class="text-gray-500 dark:text-slate-400">Purchase: <span class="font-bold text-amber-600 dark:text-amber-400"><?= number_format($pp) ?> Ks</span></span>
                                                    <span class="text-gray-500 dark:text-slate-400">Selling: <span class="font-bold text-gray-700 dark:text-slate-300"><?= number_format($sp) ?> Ks</span></span>
                                                </div>
                                                <a href="../product/index.php?action=edit&id=<?= $item['id'] ?>" class="mt-2 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-semibold bg-amber-500 hover:bg-amber-600 text-white transition-all duration-150 shadow-sm hover:shadow">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                                    Update Price
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($notif_count === 0): ?>
                            <div class="px-4 py-10 text-center">
                                <div class="w-14 h-14 bg-gray-100 dark:bg-slate-700 rounded-2xl flex items-center justify-center mx-auto mb-3">
                                    <svg class="w-7 h-7 text-gray-300 dark:text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                                    </svg>
                                </div>
                                <p class="text-sm font-medium text-gray-500 dark:text-slate-400">No notifications</p>
                                <p class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">All caught up!</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Divider -->
            <div class="w-px h-8 bg-gray-200 dark:bg-slate-600/50"></div>

            <!-- User Profile -->
            <div class="relative">
                <button onclick="toggleDropdown('userDropdown')" class="header-btn flex items-center gap-2.5 pl-1.5 pr-3 py-1.5 rounded-xl border border-gray-200 dark:border-slate-600 hover:border-indigo-300 dark:hover:border-indigo-500 bg-white dark:bg-slate-700/50 hover:bg-gray-50 dark:hover:bg-slate-700 transition-all duration-200 shadow-sm hover:shadow">
                    <?php
                    $header_profile_img = null;
                    if (isset($conn) && isset($_SESSION['user_id'])) {
                        $uid = (int)$_SESSION['user_id'];
                        $pi_result = mysqli_query($conn, "SELECT profile_image FROM users WHERE id = $uid LIMIT 1");
                        if ($pi_result && mysqli_num_rows($pi_result) > 0) {
                            $pi_row = mysqli_fetch_assoc($pi_result);
                            $header_profile_img = $pi_row['profile_image'] ?? null;
                        }
                    }
                    // Resolve absolute path for file_exists check
                    $_proj_root = str_replace('\\', '/', dirname(__DIR__));
                    $_abs_check = $_proj_root . '/' . ltrim($header_profile_img ?? '', '/');
                    $has_profile_img = !empty($header_profile_img) && file_exists($_abs_check);

                    // Compute relative URL prefix: compare script dir to project root
                    $_script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_FILENAME'] ?? ''));
                    $_rel = str_replace($_proj_root, '', $_script_dir);
                    $_depth = $_rel === '' || $_rel === '/' ? 0 : substr_count(trim($_rel, '/'), '/') + 1;
                    $_prefix = str_repeat('../', $_depth);
                    ?>
                    <?php if ($has_profile_img): ?>
                        <img src="<?= htmlspecialchars($_prefix . $header_profile_img) ?>" alt="<?= htmlspecialchars($name) ?>"
                            class="w-10 h-10 rounded-full object-cover border border-gray-200 dark:border-slate-600 shadow-sm">
                    <?php else: ?>
                        <div class="w-10 h-10 bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-full flex items-center justify-center text-white font-bold text-sm shadow-sm">
                            <?= strtoupper(substr($name, 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    <div class="text-left hidden md:block">
                        <p class="text-sm font-semibold text-gray-800 dark:text-slate-200 leading-tight"><?= htmlspecialchars($name) ?></p>
                        <p class="text-[11px] text-gray-500 dark:text-slate-400 capitalize leading-tight"><?= $role ?></p>
                    </div>
                    <svg class="w-4 h-4 text-gray-400 dark:text-slate-500 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>
                <div id="userDropdown" class="hidden absolute right-0 mt-2 w-60 bg-white dark:bg-slate-800 rounded-xl shadow-xl border border-gray-200 dark:border-slate-700 py-1.5 z-50 overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-100 dark:border-slate-700 bg-gray-50/50 dark:bg-slate-700/30">
                        <p class="text-sm font-semibold text-gray-900 dark:text-slate-100"><?= htmlspecialchars($name) ?></p>
                        <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5"><?= htmlspecialchars($_SESSION['email'] ?? '') ?></p>
                    </div>
                    <a href="<?= $_prefix ?>profile.php" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors duration-150 cursor-pointer block">
                        <svg class="w-4 h-4 text-gray-400 dark:text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                        My Profile
                    </a>
                    <a href="<?= $_prefix ?>change_password.php" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors duration-150 cursor-pointer block">
                        <svg class="w-4 h-4 text-gray-400 dark:text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                        Change Password
                    </a>
                    <a href="<?= $_prefix ?>settings/index.php" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors duration-150 cursor-pointer block">
                        <svg class="w-4 h-4 text-gray-400 dark:text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        Settings
                    </a>
                    <div class="my-1.5 border-t border-gray-100 dark:border-slate-700"></div>
                    <a href="<?= $_prefix ?>logout.php" class="flex items-center gap-3 px-4 py-2.5 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 transition-colors duration-150 cursor-pointer block">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                        </svg>
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </div>
</header>