<?php

/*
|--------------------------------------------------------------------------
| CloudBSS Navigation (single source of truth for the left menu)
|--------------------------------------------------------------------------
| Roadmap Epic 1 / Task E1.1. This file is the ONE definition of the panel
| navigation, matching Product Architecture v2 §1. Every screen appears
| exactly once. NavController::menuFor() filters it per user + tenant:
|
|   - 'menu'   (per item)  : an existing User::allMenuKeys() key. The item is
|                            shown only if the user's effectiveMenus() contains
|                            it (role/permission gate).  [Constitution R9/R10]
|   - 'module' (per group) : a tenant module flag. The whole group is hidden
|                            when the tenant has set modules[<key>] = false.
|                            UNSET = enabled (non-breaking for existing tenants).
|                            [Constitution R11 — module gate]
|
| The item->menu mappings reuse today's coarse menu keys so role gating works
| with the CURRENT role model. Epic 2 (Permission system) introduces finer
| role keys (warehouse/cashier/accountant/…); when it does, only the 'menu'
| values here change — the structure does not.
|
| Routes point at real, existing panel routes so the menu works the moment the
| sidebar is wired to it in Task E1.2. This config is NOT consumed by any UI
| yet (E1.1 ships the data + endpoint only).
*/

return [
    [
        'key' => 'home', 'label' => 'Home',
        'items' => [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'route' => '/panel', 'icon' => '🏠', 'menu' => 'dashboard'],
        ],
    ],

    [
        'key' => 'sales', 'label' => 'Sales', 'module' => 'sales',
        'items' => [
            // The Order-Trading "Orders & Margin" page is gated by the tenant setting
            // order_system_enabled (OrderCostController::ordersPage aborts 404 when off).
            // Gate the menu link on the SAME setting so we never show a link that 404s.
            ['key' => 'orders',    'label' => 'Orders',    'route' => '/panel/orders-costs', 'icon' => '🧾', 'menu' => 'orders', 'setting' => 'order_system_enabled'],
            ['key' => 'customers', 'label' => 'Customers', 'route' => '/panel/customers360', 'icon' => '👥', 'menu' => 'customers'],
            ['key' => 'invoices',  'label' => 'Invoices',  'route' => '/panel/invoices',     'icon' => '📄', 'menu' => 'finance'],
        ],
    ],

    [
        'key' => 'purchases', 'label' => 'Purchases', 'module' => 'purchases',
        'items' => [
            ['key' => 'buy-list',  'label' => 'Buy List',  'route' => '/panel/buy-list',  'icon' => '📋', 'menu' => 'purchases'],
            ['key' => 'purchases', 'label' => 'Purchases', 'route' => '/panel/purchases', 'icon' => '🛒', 'menu' => 'purchases'],
            ['key' => 'suppliers', 'label' => 'Suppliers', 'route' => '/panel/suppliers', 'icon' => '🏭', 'menu' => 'purchases'],
        ],
    ],

    [
        'key' => 'stock', 'label' => 'Stock', 'module' => 'inventory',
        'items' => [
            ['key' => 'inventory',    'label' => 'Inventory',    'route' => '/panel/inventory-count', 'icon' => '📦', 'menu' => 'products'],
            ['key' => 'consignments', 'label' => 'Consignments', 'route' => '/panel/consignment',     'icon' => '🚚', 'menu' => 'products'],
            ['key' => 'deliveries',   'label' => 'Deliveries',   'route' => '/panel/operations',      'icon' => '📮', 'menu' => 'products'],
        ],
    ],

    [
        'key' => 'money', 'label' => 'Money', 'module' => 'finance',
        'items' => [
            ['key' => 'payments', 'label' => 'Payments', 'route' => '/panel/payments',       'icon' => '💵', 'menu' => 'finance'],
            // E5.3 — Cashbook is ONE screen with tabs. Cash Position / Staff Cash / My Cash used to be
            // separate left-menu links (and separate sums, see E5.2); they are now sub-views (tabs) of
            // this one Cashbook entry, so the sidebar offers a single way in. Their standalone routes
            // still resolve for deep links but are retired as menu links (NavController::RETIRED_ROUTES).
            ['key' => 'cashbook', 'label' => 'Cashbook', 'route' => '/panel/trade-cashbook', 'icon' => '💰', 'menu' => 'cash',
                'tabs' => [
                    ['key' => 'trade',     'label' => 'Cashbook',      'route' => '/panel/trade-cashbook'],
                    ['key' => 'positions', 'label' => 'Cash Position', 'route' => '/panel/cash-position'],
                    ['key' => 'staff',     'label' => 'Staff Cash',    'route' => '/panel/staff-cash'],
                    ['key' => 'mine',      'label' => 'My Cash',       'route' => '/panel/my-cash'],
                ],
            ],
            ['key' => 'expenses', 'label' => 'Expenses', 'route' => '/panel/expenses',       'icon' => '💸', 'menu' => 'finance'],
        ],
    ],

    [
        'key' => 'reports', 'label' => 'Reports', 'module' => 'finance',
        'items' => [
            ['key' => 'reports', 'label' => 'Reports', 'route' => '/panel/pnl', 'icon' => '📊', 'menu' => 'finance'],
        ],
    ],

    [
        'key' => 'messaging', 'label' => 'Messaging', 'module' => 'whatsapp',
        'items' => [
            ['key' => 'chats',     'label' => 'Chats',     'route' => '/panel/chats',     'icon' => '💬', 'menu' => 'whatsapp'],
            ['key' => 'leads',     'label' => 'Leads',     'route' => '/panel/leads',     'icon' => '🎯', 'menu' => 'marketing'],
            ['key' => 'campaigns', 'label' => 'Campaigns', 'route' => '/panel/marketing', 'icon' => '📣', 'menu' => 'marketing'],
        ],
    ],

    [
        'key' => 'settings', 'label' => 'Settings',
        'items' => [
            ['key' => 'business', 'label' => 'Business profile', 'route' => '/panel/setup',       'icon' => '🏢', 'menu' => 'settings'],
            ['key' => 'whatsapp', 'label' => 'WhatsApp',         'route' => '/panel/setup',       'icon' => '📱', 'menu' => 'settings'],
            ['key' => 'team',     'label' => 'Team & Roles',     'route' => '/panel/staff',       'icon' => '🧑‍💼', 'menu' => 'staff'],
            ['key' => 'modules',  'label' => 'Modules',          'route' => '/panel/system',      'icon' => '🧩', 'menu' => 'settings'],
            ['key' => 'billing',  'label' => 'Billing',          'route' => '/panel/billing',     'icon' => '💳', 'menu' => 'settings'],
            ['key' => 'advanced', 'label' => 'Advanced',         'route' => '/panel/diagnostics', 'icon' => '🛠️', 'menu' => 'settings'],
        ],
    ],
];
