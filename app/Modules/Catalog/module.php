<?php

return [
    'label' => 'Sản phẩm',
    'depends_on' => ['Core'],

    'permissions' => [
        'products' => [
            'label' => 'Sản phẩm',
            'actions' => ['view' => 'Xem', 'create' => 'Thêm', 'update' => 'Sửa', 'delete' => 'Xóa'],
        ],
        'catalog' => [
            'label' => 'Danh mục, đơn vị, bảng giá',
            'actions' => ['manage' => 'Quản lý'],
        ],
    ],

    'default_roles' => [
        'manager' => ['products.view', 'products.create', 'products.update', 'products.delete', 'catalog.manage'],
        'warehouse' => ['products.view', 'products.create', 'products.update'],
        'cashier' => ['products.view'],
    ],

    'navigation' => [
        [
            'group' => 'Sản phẩm',
            'items' => [
                ['label' => 'Sản phẩm', 'icon' => 'pi pi-box', 'route' => 'products.index', 'match' => 'products.*', 'can' => 'products.view', 'order' => 10],
                ['label' => 'Danh mục & đơn vị', 'icon' => 'pi pi-tags', 'route' => 'catalog.index', 'match' => 'catalog.*', 'can' => 'products.view', 'order' => 20],
            ],
        ],
    ],
];
