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

it('hạng thành viên: tính hạng và % giảm theo tổng chi tiêu', function () {
    $t = \App\Modules\Core\Support\CustomerTiers::for(2500000);

    expect($t['name'])->toBe('Bạc')
        ->and($t['discount'])->toBe(1.0)
        ->and($t['next']['name'])->toBe('Vàng')
        ->and(\App\Modules\Core\Support\CustomerTiers::discountFor(0))->toBe(0.0);
});

it('tên hiển thị tối đa 12 ký tự và màu tự chọn phải là mã hex', function () {
    $this->artisan('pos:sync');
    $admin = User::where('username', 'admin')->first();
    $admin->forceFill(['must_change_password' => false])->save();
    $ok = ['shop_name' => 'Pos', 'theme' => 'custom', 'theme_custom' => '#112233', 'logo_type' => 'icon', 'logo_icon' => \App\Modules\Core\Models\Setting::LOGO_ICONS[0]];

    $this->actingAs($admin)->post(route('appearance.branding'), array_merge($ok, ['shop_name' => 'Tên quá dài vượt mức']))->assertSessionHasErrors('shop_name');
    $this->actingAs($admin)->post(route('appearance.branding'), array_merge($ok, ['theme_custom' => 'xanh']))->assertSessionHasErrors('theme_custom');
    $this->actingAs($admin)->post(route('appearance.branding'), $ok)->assertSessionDoesntHaveErrors();
});

it('tắt áp dụng giảm giá: vẫn có hạng nhưng giảm 0%', function () {
    \App\Modules\Core\Models\Setting::putJson('customer_tiers', ['enabled' => false, 'tiers' => \App\Modules\Core\Support\CustomerTiers::defaults()['tiers']]);

    $t = \App\Modules\Core\Support\CustomerTiers::for(6000000);
    expect($t['name'])->toBe('Vàng')
        ->and($t['discount'])->toBe(0.0)
        ->and(\App\Modules\Core\Support\CustomerTiers::discountFor(6000000))->toBe(0.0);
});
