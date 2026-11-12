<?php

return [
    'label' => 'Khách hàng',
    'depends_on' => ['Core'],

    'permissions' => [
        'customers' => [
            'label' => 'Khách hàng',
            'actions' => ['view' => 'Xem', 'create' => 'Thêm', 'update' => 'Sửa', 'delete' => 'Xóa', 'import' => 'Nhập/xuất Excel'],
        ],
    ],

    'default_roles' => [
        'manager' => ['customers.view', 'customers.create', 'customers.update', 'customers.delete', 'customers.import'],
        'cashier' => ['customers.view', 'customers.create', 'customers.update'],
    ],

    'navigation' => [
        [
            'group' => 'Khách hàng',
            'items' => [
                ['label' => 'Khách hàng', 'icon' => 'pi pi-users', 'route' => 'customers.index', 'match' => 'customers.*', 'can' => 'customers.view', 'order' => 30],
            ],
        ],
    ],
];
