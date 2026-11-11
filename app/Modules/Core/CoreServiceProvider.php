<?php

namespace App\Modules\Core;

use App\Modules\Core\Console\SyncCommand;
use App\Modules\Core\Support\ModuleRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Nạp mọi module trong app/Modules: migration, route, (tùy chọn) provider riêng.
 * Thêm module mới = tạo thư mục app/Modules/<Ten> kèm module.php và routes.php, không sửa file nào khác.
 */
class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class, fn () => new ModuleRegistry(app_path('Modules')));

        // Module nào có <Ten>ServiceProvider riêng thì đăng ký luôn
        foreach (glob(app_path('Modules').'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            $provider = "App\\Modules\\{$name}\\{$name}ServiceProvider";

            if ($name !== 'Core' && class_exists($provider)) {
                $this->app->register($provider);
            }
        }
    }

    public function boot(): void
    {
        // Quản trị viên luôn có mọi quyền (kể cả quyền của module thêm sau này)
        Gate::before(fn ($user) => method_exists($user, 'hasRole') && $user->hasRole('admin') ? true : null);

        foreach ($this->app->make(ModuleRegistry::class)->modules() as $module) {
            $migrations = $module['path'].'/Database/migrations';
            if (is_dir($migrations)) {
                $this->loadMigrationsFrom($migrations);
            }

            $routes = $module['path'].'/routes.php';
            if (is_file($routes) && ! $this->app->routesAreCached()) {
                Route::middleware('web')->group($routes);
            }
        }

        $this->commands([SyncCommand::class]);
    }
}
