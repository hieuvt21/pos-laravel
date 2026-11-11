<?php

namespace App\Modules\Core\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;

/**
 * Đọc file module.php của các module trong app/Modules và gom lại:
 * quyền, vai trò mặc định, menu. Thêm module mới không cần sửa file chung nào.
 */
final class ModuleRegistry
{
    /** Thứ tự nhóm menu; nhóm lạ nằm trước "Hệ thống". */
    private const GROUP_ORDER = [
        'Tổng quan' => 10,
        'Bán hàng' => 20,
        'Hàng hóa' => 30,
        'Báo cáo' => 40,
        'Hệ thống' => 90,
    ];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $modules = null;

    public function __construct(private readonly string $path) {}

    /**
     * Tất cả module (Core đứng đầu, còn lại theo tên). Mỗi phần tử là nội dung module.php
     * kèm 'name' và 'path'.
     *
     * @return array<string, array<string, mixed>>
     */
    public function modules(): array
    {
        if ($this->modules !== null) {
            return $this->modules;
        }

        $modules = [];
        $dirs = glob($this->path.'/*', GLOB_ONLYDIR) ?: [];
        sort($dirs);

        foreach ($dirs as $dir) {
            $name = basename($dir);
            $file = $dir.'/module.php';
            if (! is_file($file)) {
                continue;
            }
            $manifest = require $file;
            $modules[$name] = (is_array($manifest) ? $manifest : []) + ['name' => $name, 'path' => $dir];
        }

        // Core luôn đứng đầu
        if (isset($modules['Core'])) {
            $modules = ['Core' => $modules['Core']] + $modules;
        }

        return $this->modules = $modules;
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        $names = [];
        foreach ($this->modules() as $module) {
            foreach ($module['permissions'] ?? [] as $group => $def) {
                foreach (array_keys($def['actions'] ?? []) as $action) {
                    $names[] = "{$group}.{$action}";
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Quyền chia nhóm kèm nhãn tiếng Việt, dùng cho trang Vai trò & quyền.
     *
     * @return list<array{key: string, label: string, items: list<array{name: string, label: string}>}>
     */
    public function permissionGroups(): array
    {
        $groups = [];
        foreach ($this->modules() as $module) {
            foreach ($module['permissions'] ?? [] as $group => $def) {
                $groups[$group] ??= ['key' => $group, 'label' => $def['label'] ?? $group, 'items' => []];
                foreach ($def['actions'] ?? [] as $action => $label) {
                    $groups[$group]['items'][] = ['name' => "{$group}.{$action}", 'label' => $label];
                }
            }
        }

        return array_values($groups);
    }

    /** @return list<string> */
    public function systemRoles(): array
    {
        $roles = [];
        foreach ($this->modules() as $module) {
            array_push($roles, ...($module['roles'] ?? []));
        }

        return array_values(array_unique($roles));
    }

    /**
     * Quyền mặc định của từng vai trò, gộp từ mọi module.
     *
     * @return array<string, list<string>>
     */
    public function defaultRoles(): array
    {
        $roles = [];
        foreach ($this->modules() as $module) {
            foreach ($module['default_roles'] ?? [] as $role => $permissions) {
                $roles[$role] = array_values(array_unique([...($roles[$role] ?? []), ...$permissions]));
            }
        }

        return $roles;
    }

    /**
     * Menu cho người dùng hiện tại: bỏ mục không đủ quyền hoặc chưa có route.
     *
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    public function navigation(?Authenticatable $user): array
    {
        $groups = [];

        foreach ($this->modules() as $module) {
            foreach ($module['navigation'] ?? [] as $group) {
                $label = $group['group'];
                $groups[$label] ??= ['label' => $label, 'order' => $group['order'] ?? self::GROUP_ORDER[$label] ?? 80, 'items' => []];

                foreach ($group['items'] ?? [] as $item) {
                    $allowed = ! isset($item['can']) || ($user && $user->can($item['can']));
                    if ($allowed && Route::has($item['route'])) {
                        $groups[$label]['items'][] = $item;
                    }
                }
            }
        }

        $groups = array_filter($groups, fn (array $g) => $g['items'] !== []);
        uasort($groups, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return array_values(array_map(function (array $g) {
            usort($g['items'], fn (array $a, array $b) => ($a['order'] ?? 50) <=> ($b['order'] ?? 50));

            return [
                'label' => $g['label'],
                'items' => array_map(fn (array $i) => [
                    'label' => $i['label'],
                    'icon' => $i['icon'] ?? 'pi pi-circle',
                    'route' => $i['route'],
                    'match' => $i['match'] ?? $i['route'],
                ], $g['items']),
            ];
        }, $groups));
    }
}
