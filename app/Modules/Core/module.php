<?php

/**
 * Khai báo module Core. Mỗi module có một file module.php cùng thư mục (xem docs/modules/_template.md).
 *
 * - permissions: nhóm => ['label' => ..., 'actions' => [hành động => nhãn]]. Tên quyền = "nhóm.hành động".
 * - default_roles: vai trò => danh sách quyền mặc định. Chỉ gán cho vai trò MỚI tạo hoặc quyền MỚI thêm.
 * - navigation: các mục menu, gom theo nhóm; chỉ hiện khi người dùng có quyền `can` và route tồn tại.
 */
return [
    'label' => 'Hệ thống',
    'depends_on' => [],

    // Vai trò mặc định của cả hệ thống (không xóa/đổi tên được). Chỉ Core khai báo.
    'roles' => ['admin', 'manager', 'cashier', 'warehouse'],

    'permissions' => [
        'users' => [
            'label' => 'Người dùng',
            'actions' => ['view' => 'Xem', 'create' => 'Thêm', 'update' => 'Sửa', 'delete' => 'Xóa'],
        ],
        'roles' => [
            'label' => 'Vai trò',
            'actions' => ['manage' => 'Quản lý'],
        ],
        'settings' => [
            'label' => 'Cài đặt',
            'actions' => ['manage' => 'Quản lý'],
        ],
    ],

    'default_roles' => [],

    'navigation' => [
        [
            'group' => 'Tổng quan',
            'items' => [
                ['label' => 'Bảng điều khiển', 'icon' => 'pi pi-objects-column', 'route' => 'dashboard', 'order' => 10],
            ],
        ],
        [
            'group' => 'Hệ thống',
            'items' => [
                ['label' => 'Giao diện', 'icon' => 'pi pi-palette', 'route' => 'appearance.edit', 'match' => 'appearance.*', 'order' => 10],
                ['label' => 'Cửa hàng', 'icon' => 'pi pi-building', 'route' => 'store.edit', 'match' => 'store.*', 'can' => 'settings.manage', 'order' => 20],
                ['label' => 'Người dùng', 'icon' => 'pi pi-users', 'route' => 'users.index', 'match' => 'users.*', 'can' => 'users.view', 'order' => 30],
                ['label' => 'Vai trò & quyền', 'icon' => 'pi pi-shield', 'route' => 'roles.index', 'match' => 'roles.*', 'can' => 'roles.manage', 'order' => 40],
            ],
        ],
    ],
];
