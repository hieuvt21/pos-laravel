<?php

return [
    'label' => 'Dịch vụ',
    'depends_on' => ['Core'],

    'permissions' => [
        'services' => [
            'label' => 'Dịch vụ',
            'actions' => ['view' => 'Xem', 'create' => 'Thêm', 'update' => 'Sửa', 'delete' => 'Xóa'],
        ],
        'service_categories' => [
            'label' => 'Danh mục dịch vụ',
            'actions' => ['manage' => 'Quản lý'],
        ],
    ],

    'default_roles' => [
        'manager' => ['services.view', 'services.create', 'services.update', 'services.delete', 'service_categories.manage'],
        'cashier' => ['services.view'],
    ],

    'navigation' => [
        [
            'group' => 'Dịch vụ',
            'items' => [
                ['label' => 'Dịch vụ', 'icon' => 'pi pi-briefcase', 'route' => 'services.index', 'match' => 'services.*', 'can' => 'services.view', 'order' => 10],
                ['label' => 'Danh mục dịch vụ', 'icon' => 'pi pi-tags', 'route' => 'service_categories.index', 'match' => 'service_categories.*', 'can' => 'service_categories.manage', 'order' => 20],
            ],
        ],
    ],
];
