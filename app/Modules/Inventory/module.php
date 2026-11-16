<?php

use App\Modules\Inventory\Support\StockAlerts;

return [
    'label' => 'Kho',
    'depends_on' => ['Core', 'Catalog'],

    'permissions' => [
        'inventory' => [
            'label' => 'Kho',
            'actions' => [
                'view' => 'Xem tồn kho',
                'receive' => 'Nhập kho',
                'issue' => 'Xuất kho',
                'count' => 'Kiểm kho',
                'cost' => 'Xem giá vốn',
                'settings' => 'Cài đặt kho',
            ],
        ],
    ],

    'default_roles' => [
        'manager' => ['inventory.view', 'inventory.receive', 'inventory.issue', 'inventory.count', 'inventory.cost', 'inventory.settings'],
        'warehouse' => ['inventory.view', 'inventory.receive', 'inventory.issue', 'inventory.count', 'inventory.cost'],
        'cashier' => ['inventory.view'],
    ],

    'navigation' => [
        [
            'group' => 'Kho',
            'items' => [
                ['label' => 'Tồn kho', 'icon' => 'pi pi-database', 'route' => 'inventory.index', 'can' => 'inventory.view', 'order' => 10, 'badge' => [StockAlerts::class, 'badge']],
                ['label' => 'Phiếu kho', 'icon' => 'pi pi-list', 'route' => 'inventory.documents.index', 'match' => 'inventory.documents.*', 'can' => 'inventory.view', 'order' => 20],
                ['label' => 'Nhập kho', 'icon' => 'pi pi-download', 'route' => 'inventory.receipts.create', 'match' => 'inventory.receipts.*', 'can' => 'inventory.receive', 'order' => 30],
                ['label' => 'Xuất kho', 'icon' => 'pi pi-upload', 'route' => 'inventory.issues.create', 'match' => 'inventory.issues.*', 'can' => 'inventory.issue', 'order' => 40],
                ['label' => 'Kiểm kho', 'icon' => 'pi pi-check-square', 'route' => 'inventory.counts.create', 'match' => 'inventory.counts.*', 'can' => 'inventory.count', 'order' => 50],
                ['label' => 'Nhà cung cấp', 'icon' => 'pi pi-truck', 'route' => 'inventory.suppliers.index', 'match' => 'inventory.suppliers.*', 'can' => 'inventory.receive', 'order' => 60],
            ],
        ],
    ],
];
