<?php

use App\Models\User;
use App\Modules\Core\Support\DocumentNumber;
use App\Modules\Core\Support\ModuleRegistry;
use App\Modules\Core\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('pos:sync tạo quyền, vai trò mặc định, admin và chạy lại an toàn', function () {
    $this->artisan('pos:sync')->assertSuccessful();
    $this->artisan('pos:sync')->assertSuccessful();

    $registry = app(ModuleRegistry::class);

    expect(Role::pluck('name')->all())->toContain('admin', 'manager', 'cashier', 'warehouse')
        ->and(Role::findByName('admin')->permissions)->toHaveCount(count($registry->permissionNames()))
        ->and(User::where('username', 'admin')->count())->toBe(1)
        ->and(User::where('username', 'admin')->first()->hasRole('admin'))->toBeTrue();
});

it('cấp mã chứng từ tăng dần', function () {
    expect(DocumentNumber::next('product'))->toBe('SP-00001')
        ->and(DocumentNumber::next('product'))->toBe('SP-00002');
});

it('định dạng tiền theo cài đặt mặc định', function () {
    expect(Money::format(1234567))->toBe('1.234.567 ₫')
        ->and(Money::round(1234))->toBe(1234);
});

it('menu chỉ hiện mục đủ quyền', function () {
    $this->artisan('pos:sync')->assertSuccessful();

    $labels = fn ($user) => collect(app(ModuleRegistry::class)->navigation($user))
        ->flatMap(fn ($g) => collect($g['items'])->pluck('label'))->all();

    $admin = User::where('username', 'admin')->first();
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');

    expect($labels($admin))->toContain('Người dùng', 'Vai trò & quyền')
        ->and($labels($cashier))->not->toContain('Người dùng')
        ->and($labels($cashier))->toContain('Bảng điều khiển');
});
