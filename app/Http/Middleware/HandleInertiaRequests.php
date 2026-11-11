<?php

namespace App\Http\Middleware;

use App\Modules\Core\Models\Setting;
use App\Modules\Core\Support\ModuleRegistry;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Spatie\Permission\Models\Permission;

class HandleInertiaRequests extends Middleware
{
    /** Giao diện gốc được nạp ở lần truy cập đầu tiên. */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Dữ liệu dùng chung cho mọi trang.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'avatar_url' => $user->avatar_url,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames(),
                    'permissions' => $user->hasRole('admin')
                        ? Permission::pluck('name')
                        : $user->getAllPermissions()->pluck('name'),
                ] : null,
            ],
            // Menu do các module tự khai báo (module.php), đã lọc theo quyền
            'navigation' => fn () => $user ? app(ModuleRegistry::class)->navigation($user) : [],
            'branding' => fn () => Setting::branding(),
            'finance' => fn () => Setting::finance(),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
