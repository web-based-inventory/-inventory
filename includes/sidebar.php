<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/permission.php';

$role = $_SESSION['role'] ?? 'staff';
$name = $_SESSION['name'] ?? 'User';
$current_dir = basename(dirname($_SERVER['PHP_SELF']));
$file = basename($_SERVER['PHP_SELF']);

$_proj_root = str_replace('\\', '/', dirname(__DIR__));
$_script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_FILENAME'] ?? ''));
$_rel = str_replace($_proj_root, '', $_script_dir);
$_depth = $_rel === '' || $_rel === '/' ? 0 : substr_count(trim($_rel, '/'), '/') + 1;
$_sidebar_prefix = str_repeat('../', $_depth);

function isActive($dirs, $check_file = null)
{
    global $current_dir, $file;
    $dirs = (array)$dirs;
    if ($check_file) {
        return in_array($current_dir, $dirs) && $file === $check_file;
    }
    return in_array($current_dir, $dirs);
}

function isGroupActive($dirs)
{
    return isActive($dirs);
}

function menuItem($href, $label, $dirs, $file_check = null, $icon = '')
{
    global $_sidebar_prefix;
    $href = preg_replace('/^\.\.\//', $_sidebar_prefix, $href);
    $active = isActive($dirs, $file_check);
    $activeClass = $active
        ? 'bg-indigo-600/20 text-indigo-400 font-semibold shadow-sm shadow-indigo-500/10'
        : 'text-slate-400 hover:bg-white/5 hover:text-slate-200';
    $dot = $active
        ? '<span class="w-1.5 h-1.5 rounded-full bg-indigo-400 flex-shrink-0 shadow-sm shadow-indigo-400/50"></span>'
        : '<span class="w-1.5 h-1.5 rounded-full bg-slate-600 flex-shrink-0"></span>';
    return <<<HTML
<a href="{$href}" class="flex items-center gap-2.5 px-3 py-2 rounded-xl text-[13px] transition-all duration-200 {$activeClass}">
    {$dot}
    {$label}
</a>
HTML;
}

?>
<aside id="sidebar" class="w-60 bg-indigo-950 border-r border-white/[0.06] flex flex-col h-screen fixed lg:sticky top-0 z-40 -translate-x-full lg:translate-x-0" style="background: linear-gradient(180deg, #172554 0%, #1e1b4b 100%);">

    <!-- Logo -->
    <div class="h-[4.2rem] flex items-center gap-3.5 px-5 border-b border-white/[0.06] flex-shrink-0">
        <?php
        // Fetch shop settings for sidebar
        if (!function_exists('getShopSettings')) {
            require_once __DIR__ . '/../config/database.php';
            require_once __DIR__ . '/../config/helpers.php';
        }
        $sidebar_settings = getShopSettings($conn);
        $sidebar_logo = trim($sidebar_settings['logo'] ?? '');
        $sidebar_logo_path = !empty($sidebar_logo) ? dirname(__DIR__, 2) . '/img/' . $sidebar_logo : '';
        $sidebar_has_logo = !empty($sidebar_logo) && !empty($sidebar_logo_path) && file_exists($sidebar_logo_path);
        ?>
        <?php if ($sidebar_has_logo): ?>
            <img src="<?= htmlspecialchars($_sidebar_prefix . 'img/' . $sidebar_logo) ?>" alt="<?= htmlspecialchars($sidebar_settings['shop_name']) ?>"
                class="w-10 h-10 rounded-xl object-cover flex-shrink-0 shadow-lg shadow-indigo-500/25">
        <?php else: ?>
            <div class="w-10 h-10 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-xl flex items-center justify-center flex-shrink-0 shadow-lg shadow-indigo-500/25">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                </svg>
            </div>
        <?php endif; ?>
        <div>
            <h1 class="text-[13px] font-bold text-white leading-tight tracking-tight"><?= htmlspecialchars($sidebar_settings['shop_name']) ?></h1>
            <p class="text-[10px] text-slate-500 leading-tight mt-0.5">Management System</p>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="flex-1 py-4 px-3 space-y-1 overflow-y-auto">

        <!-- ─── Dashboard ─── -->
        <?php if (checkPermission('dashboard', 'view')): ?>
            <a href="<?= $_sidebar_prefix ?>dashboard/index.php"
                class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 <?= isActive('dashboard') ? 'bg-indigo-600/20 text-indigo-400 shadow-sm shadow-indigo-500/10' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                </svg>
                Dashboard
            </a>
        <?php endif; ?>

        <!-- ═══════════════ ADMIN SIDEBAR ═══════════════ -->
        <?php if ($role === 'admin'): ?>

            <!-- Inventory Group -->
            <?php $inv_active = isGroupActive(['product', 'category', 'categories', 'supplier', 'suppliers', 'units']); ?>
            <div class="sidebar-group">
                <button onclick="toggleGroup(this)" data-group="grp-inventory" class="sidebar-group-btn w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 <?= $inv_active ? 'bg-indigo-600/20 text-indigo-400' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                    <span class="flex items-center gap-3">
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                        </svg>
                        Inventory
                    </span>
                    <svg class="w-4 h-4 transition-transform duration-200 <?= $inv_active ? 'rotate-90' : '' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </button>
                <div class="sidebar-group-items <?= $inv_active ? '' : 'collapsed' ?>">
                    <div class="ml-4 mt-1 space-y-0.5 border-l border-white/[0.08] pl-3">
                        <?= menuItem('../categories/index.php', 'Categories', ['category', 'categories']) ?>
                        <?= menuItem('../units/index.php', 'Units', 'units') ?>
                        <?= menuItem('../product/index.php', 'Products', 'product') ?>
                        <?= menuItem('../supplier/index.php', 'Suppliers', 'supplier', 'index.php') ?>
                    </div>
                </div>
            </div>

            <!-- Purchases Group -->
            <?php $purch_active = isGroupActive(['purchase', 'stock-in', 'supplier']); ?>
            <div class="sidebar-group">
                <button onclick="toggleGroup(this)" data-group="grp-purchase" class="sidebar-group-btn w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 <?= $purch_active ? 'bg-indigo-600/20 text-indigo-400' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                    <span class="flex items-center gap-3">
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                        </svg>
                        Purchase
                    </span>
                    <svg class="w-4 h-4 transition-transform duration-200 <?= $purch_active ? 'rotate-90' : '' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </button>
                <div class="sidebar-group-items <?= $purch_active ? '' : 'collapsed' ?>">
                    <div class="ml-4 mt-1 space-y-0.5 border-l border-white/[0.08] pl-3">
                        <?= menuItem('../purchase/add.php', 'New Purchase', 'purchase', 'add.php') ?>
                        <?= menuItem('../purchase/index.php', 'Purchase Management', 'purchase', 'index.php') ?>
                        <?= menuItem('../purchase/history.php', 'Purchase History', 'purchase', 'history.php') ?>
                        <?= menuItem('../supplier/ledger.php', 'Supplier Ledger', 'supplier', 'ledger.php') ?>
                    </div>
                </div>
            </div>

            <!-- Sales Group -->
            <?php if (checkPermission('sales', 'view')): ?>
                <?php $sale_active = isGroupActive('sale'); ?>
                <div class="sidebar-group">
                    <button onclick="toggleGroup(this)" data-group="grp-sale" class="sidebar-group-btn w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 <?= $sale_active ? 'bg-indigo-600/20 text-indigo-400' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        <span class="flex items-center gap-3">
                            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z" />
                            </svg>
                            Sale
                        </span>
                        <svg class="w-4 h-4 transition-transform duration-200 <?= $sale_active ? 'rotate-90' : '' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                    </button>
                    <div class="sidebar-group-items <?= $sale_active ? '' : 'collapsed' ?>">
                        <div class="ml-4 mt-1 space-y-0.5 border-l border-white/[0.08] pl-3">
                            <?= menuItem('../sale/pos.php', 'New Sale', 'sale', 'pos.php') ?>
                            <?= menuItem('../sale/history.php', 'Sale History', 'sale', 'history.php') ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Analytics Section -->
            <div class="pt-4 mt-2 border-t border-white/[0.06]">
                <p class="px-3 mb-2 text-[10px] font-bold text-slate-500 uppercase tracking-widest">Analytics</p>
            </div>

            <!-- Reports Group -->
            <?php $rpt_active = isGroupActive(['report', 'reports']); ?>
            <div class="sidebar-group">
                <button onclick="toggleGroup(this)" data-group="grp-reports" class="sidebar-group-btn w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 <?= $rpt_active ? 'bg-indigo-600/20 text-indigo-400' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                    <span class="flex items-center gap-3">
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                        Reports
                    </span>
                    <svg class="w-4 h-4 transition-transform duration-200 <?= $rpt_active ? 'rotate-90' : '' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </button>
                <div class="sidebar-group-items <?= $rpt_active ? '' : 'collapsed' ?>">
                    <div class="ml-4 mt-1 space-y-0.5 border-l border-white/[0.08] pl-3">
                        <?= menuItem('../report/salereport.php', 'Sales Report', 'report', 'salereport.php') ?>
                        <?= menuItem('../report/purchasereport.php', 'Purchase Report', 'report', 'purchasereport.php') ?>
                        <?= menuItem('../report/inventoryreport.php', 'Inventory Report', 'report', 'inventoryreport.php') ?>
                        <?= menuItem('../report/profitreport.php', 'Profit Report', 'report', 'profitreport.php') ?>
                    </div>
                </div>
            </div>

            <a href="<?= $_sidebar_prefix ?>forecast/index.php"
                class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 <?= isActive('forecast') ? 'bg-indigo-600/20 text-indigo-400 shadow-sm shadow-indigo-500/10' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                </svg>
                Forecast
            </a>

            <!-- Administration Section -->
            <div class="pt-4 mt-2 border-t border-white/[0.06]">
                <p class="px-3 mb-2 text-[10px] font-bold text-slate-500 uppercase tracking-widest">Administration</p>
            </div>

            <a href="<?= $_sidebar_prefix ?>users/index.php"
                class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 <?= isActive(['user', 'users']) ? 'bg-indigo-600/20 text-indigo-400 shadow-sm shadow-indigo-500/10' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
                User
            </a>



            <!-- ═══════════════ STAFF SIDEBAR ═══════════════ -->
        <?php elseif ($role === 'staff'): ?>

            <!-- Inventory Group (Staff) -->
            <?php $inv_active = isGroupActive(['product', 'category', 'categories', 'supplier', 'suppliers', 'units']); ?>
            <div class="sidebar-group">
                <button onclick="toggleGroup(this)" data-group="grp-inventory" class="sidebar-group-btn w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 <?= $inv_active ? 'bg-indigo-600/20 text-indigo-400' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                    <span class="flex items-center gap-3">
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                        </svg>
                        Inventory
                    </span>
                    <svg class="w-4 h-4 transition-transform duration-200 <?= $inv_active ? 'rotate-90' : '' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </button>
                <div class="sidebar-group-items <?= $inv_active ? '' : 'collapsed' ?>">
                    <div class="ml-4 mt-1 space-y-0.5 border-l border-white/[0.08] pl-3">
                        <?php if (checkPermission('categories', 'view')): ?>
                            <?= menuItem('../categories/index.php', 'Categories', ['category', 'categories']) ?>
                        <?php endif; ?>
                        <?php if (checkPermission('units', 'view')): ?>
                            <?= menuItem('../units/index.php', 'Units', 'units') ?>
                        <?php endif; ?>
                        <?php if (checkPermission('products', 'view')): ?>
                            <?= menuItem('../product/index.php', 'Products', 'product') ?>
                        <?php endif; ?>
                        <?php if (checkPermission('suppliers', 'view')): ?>
                            <?= menuItem('../supplier/index.php', 'Suppliers', 'supplier', 'index.php') ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Purchases Group (Staff) -->
            <?php if (checkPermission('purchases', 'view')): ?>
                <?php $purch_active = isGroupActive(['purchase', 'stock-in', 'supplier']); ?>
                <div class="sidebar-group">
                    <button onclick="toggleGroup(this)" data-group="grp-purchase" class="sidebar-group-btn w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 <?= $purch_active ? 'bg-indigo-600/20 text-indigo-400' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        <span class="flex items-center gap-3">
                            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                            </svg>
                            Purchase
                        </span>
                        <svg class="w-4 h-4 transition-transform duration-200 <?= $purch_active ? 'rotate-90' : '' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                    </button>
                    <div class="sidebar-group-items <?= $purch_active ? '' : 'collapsed' ?>">
                        <div class="ml-4 mt-1 space-y-0.5 border-l border-white/[0.08] pl-3">
                            <?= menuItem('../purchase/add.php', 'New Purchase', 'purchase', 'add.php') ?>
                            <?= menuItem('../purchase/index.php', 'Purchase Management', 'purchase', 'index.php') ?>
                            <?= menuItem('../purchase/history.php', 'Purchase History', 'purchase', 'history.php') ?>
                            <?= menuItem('../supplier/ledger.php', 'Supplier Ledger', 'supplier', 'ledger.php') ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Reports (Staff: inventory report only) -->
            <?php if (checkPermission('reports', 'view')): ?>
                <div class="pt-4 mt-2 border-t border-white/[0.06]">
                    <p class="px-3 mb-2 text-[10px] font-bold text-slate-500 uppercase tracking-widest">Reports</p>
                </div>
                <?= menuItem('../report/inventoryreport.php', 'Inventory Report', 'report', 'inventoryreport.php') ?>
            <?php endif; ?>

            <!-- ═══════════════ CASHIER SIDEBAR ═══════════════ -->
        <?php elseif ($role === 'cashier'): ?>

            <!-- Sales Group (Cashier) -->
            <?php $sale_active = isGroupActive('sale'); ?>
            <div class="sidebar-group">
                <button onclick="toggleGroup(this)" data-group="grp-sale" class="sidebar-group-btn w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 <?= $sale_active ? 'bg-indigo-600/20 text-indigo-400' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                    <span class="flex items-center gap-3">
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z" />
                        </svg>
                        Sale
                    </span>
                    <svg class="w-4 h-4 transition-transform duration-200 <?= $sale_active ? 'rotate-90' : '' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </button>
                <div class="sidebar-group-items <?= $sale_active ? '' : 'collapsed' ?>">
                    <div class="ml-4 mt-1 space-y-0.5 border-l border-white/[0.08] pl-3">
                        <?= menuItem('../sale/pos.php', 'New Sale', 'sale', 'pos.php') ?>
                        <?= menuItem('../sale/history.php', 'Sale History', 'sale', 'history.php') ?>
                    </div>
                </div>
            </div>

        <?php endif; ?>
    </nav>

    <!-- Bottom: Logout -->
    <div class="flex-shrink-0 border-t border-white/[0.06] p-3">
        <a href="<?= $_sidebar_prefix ?>logout.php"
            class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-red-400 hover:bg-red-500/10 hover:text-red-300 transition-all duration-200">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
            </svg>
            Logout
        </a>
    </div>
</aside>

<script>
    (function() {
        /* ── Smooth collapse / expand ── */
        window.toggleGroup = function(btn) {
            var id = btn.getAttribute('data-group');
            var container = btn.nextElementSibling;
            var arrow = btn.querySelector('svg:last-child');
            if (!container) return;

            var isOpen = !container.classList.contains('collapsed');

            if (isOpen) {
                container.style.maxHeight = container.scrollHeight + 'px';
                requestAnimationFrame(function() {
                    container.style.maxHeight = '0px';
                    container.style.opacity = '0';
                });
                container.classList.add('collapsed');
                if (arrow) arrow.classList.remove('rotate-90');
            } else {
                container.classList.remove('collapsed');
                container.style.maxHeight = '0px';
                container.style.opacity = '0';
                var h = container.scrollHeight;
                container.style.maxHeight = h + 'px';
                container.style.opacity = '1';
                if (arrow) arrow.classList.add('rotate-90');
                setTimeout(function() {
                    if (!container.classList.contains('collapsed')) {
                        container.style.maxHeight = 'none';
                    }
                }, 300);
            }
        };
    })();
</script>