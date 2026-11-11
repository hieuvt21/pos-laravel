<?php

namespace App\Modules\Core\Console;

use App\Models\User;
use App\Modules\Core\Support\ModuleRegistry;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Đồng bộ quyền, vai trò mặc định và tài khoản admin từ file module.php của các module.
 * An toàn khi chạy lại nhiều lần (mỗi lần deploy):
 *  - quyền: chỉ thêm quyền còn thiếu;
 *  - admin: luôn đủ mọi quyền;
 *  - vai trò mặc định khác: chỉ gán quyền mặc định cho vai trò MỚI tạo hoặc cho quyền MỚI thêm,
 *    không ghi đè phần đã chỉnh trên giao diện;
 *  - tài khoản admin: chỉ tạo khi chưa có, không bao giờ đặt lại mật khẩu.
 */
class SyncCommand extends Command
{
    protected $signature = 'pos:sync {--admin-password=admin : Mật khẩu ban đầu của tài khoản admin (chỉ dùng khi tạo mới)}';

    protected $description = 'Đồng bộ quyền, vai trò mặc định và tài khoản admin từ các module';

    public function handle(ModuleRegistry $registry): int
    {
        $permissions = app(PermissionRegistrar::class);
        $permissions->forgetCachedPermissions();

        $created = [];
        foreach ($registry->permissionNames() as $name) {
            $permission = Permission::findOrCreate($name, 'web');
            if ($permission->wasRecentlyCreated) {
                $created[] = $name;
            }
        }
        $permissions->forgetCachedPermissions();

        Role::findOrCreate('admin', 'web')->syncPermissions(Permission::all());

        $defaults = $registry->defaultRoles();
        foreach ($registry->systemRoles() as $name) {
            if ($name === 'admin') {
                continue;
            }

            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $wanted = $defaults[$name] ?? [];
            $give = $role->wasRecentlyCreated ? $wanted : array_values(array_intersect($wanted, $created));

            if ($give) {
                $role->givePermissionTo($give);
            }
        }

        $admin = User::firstOrCreate(
            ['username' => 'admin'],
            ['name' => 'Quản trị viên', 'password' => (string) $this->option('admin-password')],
        );

        if ($admin->wasRecentlyCreated) {
            $admin->forceFill(['email_verified_at' => now(), 'must_change_password' => true])->save();
            $this->warn('Đã tạo tài khoản "admin". Hệ thống sẽ bắt đổi mật khẩu ở lần đăng nhập đầu.');
        }

        if (! $admin->hasRole('admin')) {
            $admin->assignRole('admin');
        }

        $permissions->forgetCachedPermissions();

        $this->info('Đã đồng bộ '.count($registry->permissionNames()).' quyền ('.count($created).' quyền mới).');

        return self::SUCCESS;
    }
}
