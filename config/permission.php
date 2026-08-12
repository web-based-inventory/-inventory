<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Role-Based Access Control (RBAC) Configuration
 * 
 * Roles: admin, staff, cashier
 * Modules: dashboard, categories, units, products, suppliers, purchases, sales, reports, forecast, users, settings
 * Actions: view, add, edit, delete, export, update_price
 */

function getPermissions()
{
    return [
        'admin' => [
            'dashboard'  => ['view'],
            'categories' => ['view', 'add', 'edit', 'delete'],
            'units'      => ['view', 'add', 'edit', 'delete'],
            'products'   => ['view', 'add', 'edit', 'delete', 'update_price', 'export'],
            'suppliers'  => ['view', 'add', 'edit', 'delete'],
            'purchases'  => ['view', 'add', 'edit', 'delete', 'export'],
            'sales'      => [],
            'reports'    => ['view', 'export'],
            'forecast'   => ['view'],
            'users'      => ['view', 'add', 'edit', 'delete'],
            'settings'   => ['view', 'edit'],
        ],
        'staff' => [
            'dashboard'  => ['view'],
            'categories' => ['view'],
            'units'      => ['view'],
            'products'   => ['view'],
            'suppliers'  => ['view'],
            'purchases'  => ['view', 'add', 'edit'],
            'sales'      => [],
            'reports'    => ['view'],
            'forecast'   => [],
            'users'      => [],
            'settings'   => [],
        ],
        'cashier' => [
            'dashboard'  => ['view'],
            'categories' => [],
            'units'      => [],
            'products'   => ['view'],
            'suppliers'  => [],
            'purchases'  => [],
            'sales'      => ['view', 'add'],
            'reports'    => [],
            'forecast'   => [],
            'users'      => [],
            'settings'   => [],
        ],
    ];
}

/**
 * Check if current user has permission for a module and action
 */
function checkPermission($module, $action = 'view')
{
    if (!isset($_SESSION['role'])) {
        return false;
    }

    $role = $_SESSION['role'];
    $perms = getPermissions();

    if (!isset($perms[$role][$module])) {
        return false;
    }

    return in_array($action, $perms[$role][$module]);
}

/**
 * Get allowed actions for a module
 */
function getAllowedActions($module)
{
    if (!isset($_SESSION['role'])) {
        return [];
    }

    $role = $_SESSION['role'];
    $perms = getPermissions();

    return $perms[$role][$module] ?? [];
}

/**
 * Check if user can access a specific page (maps pages to modules)
 */
function canAccessPage($page)
{
    $pageMap = [
        'dashboard'       => ['module' => 'dashboard',  'action' => 'view'],
        'categories'      => ['module' => 'categories', 'action' => 'view'],
        'categories/add'  => ['module' => 'categories', 'action' => 'add'],
        'categories/edit' => ['module' => 'categories', 'action' => 'edit'],
        'units'           => ['module' => 'units',      'action' => 'view'],
        'products'        => ['module' => 'products',   'action' => 'view'],
        'products/add'    => ['module' => 'products',   'action' => 'add'],
        'products/edit'   => ['module' => 'products',   'action' => 'edit'],
        'suppliers'       => ['module' => 'suppliers',  'action' => 'view'],
        'suppliers/add'   => ['module' => 'suppliers',  'action' => 'add'],
        'suppliers/edit'  => ['module' => 'suppliers',  'action' => 'edit'],
        'suppliers/ledger' => ['module' => 'suppliers',  'action' => 'view'],
        'purchases'       => ['module' => 'purchases',  'action' => 'view'],
        'purchases/add'   => ['module' => 'purchases',  'action' => 'add'],
        'sales'           => ['module' => 'sales',      'action' => 'view'],
        'sales/pos'       => ['module' => 'sales',      'action' => 'add'],
        'reports'         => ['module' => 'reports',    'action' => 'view'],
        'forecast'        => ['module' => 'forecast',   'action' => 'view'],
        'users'           => ['module' => 'users',      'action' => 'view'],
        'settings'        => ['module' => 'settings',   'action' => 'view'],
    ];

    if (!isset($pageMap[$page])) {
        return false;
    }

    $config = $pageMap[$page];
    return checkPermission($config['module'], $config['action']);
}
