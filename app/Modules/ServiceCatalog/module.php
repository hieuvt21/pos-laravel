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
        'service_packages' => [
            'label' => 'Combo và liệu trình',
            'actions' => ['view' => 'Xem', 'create' => 'Thêm', 'update' => 'Sửa', 'delete' => 'Xóa'],
        ],
    ],

    'default_roles' => [
        'manager' => [
            'services.view', 'services.create', 'services.update', 'services.delete', 'service_categories.manage',
            'service_packages.view', 'service_packages.create', 'service_packages.update', 'service_packages.delete',
        ],
        'cashier' => ['services.view', 'service_packages.view'],
    ],

    'navigation' => [
        [
            'group' => 'Dịch vụ',
            'items' => [
                ['label' => 'Dịch vụ', 'icon' => 'pi pi-briefcase', 'route' => 'services.index', 'match' => 'services.*', 'can' => 'services.view', 'order' => 10],
                ['label' => 'Danh mục dịch vụ', 'icon' => 'pi pi-tags', 'route' => 'service_categories.index', 'match' => 'service_categories.*', 'can' => 'service_categories.manage', 'order' => 20],
                ['label' => 'Combo', 'icon' => 'pi pi-clone', 'route' => 'service_combos.index', 'match' => 'service_combos.*', 'can' => 'service_packages.view', 'order' => 30],
                ['label' => 'Liệu trình', 'icon' => 'pi pi-list-check', 'route' => 'service_courses.index', 'match' => 'service_courses.*', 'can' => 'service_packages.view', 'order' => 40],
            ],
        ],
    ],
];
