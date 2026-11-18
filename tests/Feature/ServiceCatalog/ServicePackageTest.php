<?php

use App\Models\User;
use App\Modules\Core\Support\DocumentNumber;
use App\Modules\ServiceCatalog\Models\Service;
use App\Modules\ServiceCatalog\Models\ServiceCategory;
use App\Modules\ServiceCatalog\Models\ServicePackage;
use App\Modules\ServiceCatalog\Services\ServiceCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('pos:sync');
    $this->manager = pkUser('manager');
    $this->cat = ServiceCategory::create(['name' => 'Spa', 'is_active' => true]);
    $this->goiDau = pkService(['name' => 'Gội đầu', 'price' => 50000, 'commission_type' => 'amount', 'commission_value' => 5000]);
    $this->massage = pkService(['name' => 'Matxa', 'price' => 200000, 'commission_type' => 'percent', 'commission_value' => 10]);
});

function pkUser(?string $role): User
{
    $user = User::factory()->create();
    if ($role) {
        $user->assignRole($role);
    }

    return $user;
}

function pkService(array $over = []): Service
{
    static $n = 0;
    $n++;

    return Service::create(array_merge([
        'code' => 'DV-P'.$n, 'name' => 'Dịch vụ P'.$n, 'price' => 100000, 'commission_type' => 'amount', 'commission_value' => 10000, 'is_active' => true,
    ], $over));
}

function pkItem(Service $service, ?int $quantity = 10, string $type = 'amount', float $value = 0): array
{
    return ['service_id' => $service->id, 'quantity' => $quantity, 'commission_type' => $type, 'commission_value' => $value];
}

/** Payload hợp lệ của một combo (gội đầu 10 lần). */
function pkCombo(array $over = []): array
{
    return array_merge([
        'type' => 'combo',
        'name' => 'Combo gội đầu 10 lần',
        'service_category_id' => test()->cat->id,
        'price' => 400000,
        'validity_days' => null,
        'warranty_days' => null,
        'sale_commission_type' => 'amount',
        'sale_commission_value' => 20000,
        'usage_commission_mode' => 'per_item',
        'usage_commission_type' => 'amount',
        'usage_commission_value' => 0,
        'note' => null,
        'is_active' => true,
        'items' => [pkItem(test()->goiDau, 10, 'amount', 5000)],
    ], $over);
}

/** Payload hợp lệ của một liệu trình (gội đầu 10 lần + matxa 5 lần). */
function pkCourse(array $over = []): array
{
    return pkCombo(array_merge([
        'type' => 'course',
        'name' => 'Liệu trình spa',
        'price' => 1200000,
        'items' => [pkItem(test()->goiDau, 10, 'amount', 5000), pkItem(test()->massage, 5, 'percent', 10)],
    ], $over));
}

it('quyền: thu ngân chỉ xem, người không có quyền bị chặn, quản lý làm được mọi việc', function () {
    $pkg = ServicePackage::create(['code' => 'GOI-T1', 'type' => 'combo', 'name' => 'Gói mẫu', 'price' => 1000]);
    $cashier = pkUser('cashier');

    $this->get(route('service_combos.index'))->assertRedirect(route('login'));

    foreach (['service_combos.index', 'service_courses.index'] as $name) {
        $this->actingAs($cashier)->get(route($name))->assertOk();
    }
    $this->actingAs($cashier)->getJson(route('service_packages.check', ['value' => 'x']))->assertOk();
    $this->actingAs($cashier)->post(route('service_packages.store'), pkCombo())->assertForbidden();
    $this->actingAs($cashier)->put(route('service_packages.update', $pkg), pkCombo())->assertForbidden();
    $this->actingAs($cashier)->delete(route('service_packages.destroy', $pkg))->assertForbidden();

    foreach ([null, 'warehouse'] as $role) {
        $this->actingAs(pkUser($role))->get(route('service_combos.index'))->assertForbidden();
    }

    $this->actingAs($this->manager)->post(route('service_packages.store'), pkCombo())->assertSessionHasNoErrors();
    $this->actingAs($this->manager)->put(route('service_packages.update', $pkg), pkCombo(['name' => 'Tên mới']))->assertSessionHasNoErrors();
    $this->actingAs($this->manager)->delete(route('service_packages.destroy', $pkg))->assertSessionHasNoErrors();
});

it('thêm combo: cấp mã GOI, lưu dịch vụ, hoa hồng người bán tính từ giá gói', function () {
    $this->actingAs($this->manager)->post(route('service_packages.store'), pkCombo(['note' => 'Dùng trong 3 tháng', 'sale_commission_type' => 'percent', 'sale_commission_value' => 5]))
        ->assertSessionHasNoErrors();

    $p = ServicePackage::with('items')->first();
    expect($p->code)->toStartWith('GOI-')->toEndWith('0001')
        ->and($p->type)->toBe('combo')
        ->and($p->name)->toBe('Combo gội đầu 10 lần')
        ->and($p->service_category_id)->toBe($this->cat->id)
        ->and($p->price)->toBe(400000.0)
        ->and($p->validity_days)->toBeNull()
        ->and($p->warranty_days)->toBeNull()
        ->and($p->sale_commission)->toBe(20000.0)          // 5% của 400.000, không lưu số tiền
        ->and($p->usage_commission_mode)->toBe('per_item')
        ->and($p->items)->toHaveCount(1)
        ->and($p->items[0]->service_id)->toBe($this->goiDau->id)
        ->and($p->items[0]->quantity)->toBe(10);
});

it('thêm liệu trình: nhiều dịch vụ, hạn sử dụng và bảo hành', function () {
    $this->actingAs($this->manager)->post(route('service_packages.store'), pkCourse(['validity_days' => 90, 'warranty_days' => 30]))->assertSessionHasNoErrors();

    $p = ServicePackage::with('items')->first();
    expect($p->type)->toBe('course')->and($p->validity_days)->toBe(90)->and($p->warranty_days)->toBe(30)
        ->and($p->items->pluck('quantity')->all())->toBe([10, 5])
        ->and($p->items->pluck('service_id')->all())->toBe([$this->goiDau->id, $this->massage->id]);
});

it('hoa hồng sử dụng theo từng dịch vụ: tính trên giá lẻ của dịch vụ', function () {
    $this->actingAs($this->manager)->post(route('service_packages.store'), pkCourse())->assertSessionHasNoErrors();

    $api = app(ServiceCatalogService::class)->findPackage(ServicePackage::first()->id);
    expect($api['usage_commission_mode'])->toBe('per_item')
        ->and($api['items'][0]['commission'])->toBe(5000.0)         // 5.000 đồng cho mỗi lần gội đầu
        ->and($api['items'][1]['commission_type'])->toBe('percent')
        ->and($api['items'][1]['commission'])->toBe(20000.0);       // 10% của giá lẻ matxa 200.000
});

it('hoa hồng chung: một định mức cho mọi dịch vụ, định mức riêng bị bỏ', function () {
    $this->actingAs($this->manager)->post(route('service_packages.store'), pkCourse([
        'usage_commission_mode' => 'common', 'usage_commission_type' => 'amount', 'usage_commission_value' => 8000,
    ]))->assertSessionHasNoErrors();

    $p = ServicePackage::with('items')->first();
    expect($p->usage_commission_mode)->toBe('common')->and($p->usage_commission_value)->toBe(8000.0)
        ->and($p->items->pluck('commission_value')->all())->toBe([0.0, 0.0]);       // định mức riêng không dùng nên đặt về 0

    $api = app(ServiceCatalogService::class)->findPackage($p->id);
    expect(collect($api['items'])->pluck('commission')->all())->toBe([8000.0, 8000.0]);

    // chung theo %: mỗi dịch vụ tính trên giá lẻ của chính nó
    $this->actingAs($this->manager)->put(route('service_packages.update', $p), pkCourse(['usage_commission_mode' => 'common', 'usage_commission_type' => 'percent', 'usage_commission_value' => 10]))->assertSessionHasNoErrors();
    expect(collect(app(ServiceCatalogService::class)->findPackage($p->id)['items'])->pluck('commission')->all())->toBe([5000.0, 20000.0]);

    // quay lại theo từng dịch vụ: định mức chung bị xóa
    $this->actingAs($this->manager)->put(route('service_packages.update', $p), pkCourse())->assertSessionHasNoErrors();
    expect($p->fresh()->usage_commission_mode)->toBe('per_item')->and($p->fresh()->usage_commission_value)->toBe(0.0);
});

it('combo luôn tính hoa hồng theo dịch vụ dù gửi chế độ chung', function () {
    $this->actingAs($this->manager)->post(route('service_packages.store'), pkCombo(['usage_commission_mode' => 'common', 'usage_commission_value' => 999]))->assertSessionHasNoErrors();

    expect(ServicePackage::first()->usage_commission_mode)->toBe('per_item')->and(ServicePackage::first()->usage_commission_value)->toBe(0.0);
});

it('combo đúng 1 dịch vụ, liệu trình từ 2 dịch vụ, không chọn trùng dịch vụ', function () {
    $post = fn (array $payload) => $this->actingAs($this->manager)->post(route('service_packages.store'), $payload);

    $post(pkCombo(['name' => 'A', 'items' => [pkItem($this->goiDau), pkItem($this->massage)]]))->assertSessionHasErrors('items');
    $post(pkCombo(['name' => 'B', 'items' => []]))->assertSessionHasErrors('items');
    $post(pkCourse(['name' => 'C', 'items' => [pkItem($this->goiDau)]]))->assertSessionHasErrors('items');
    $post(pkCourse(['name' => 'D', 'items' => [pkItem($this->goiDau), pkItem($this->goiDau)]]))->assertSessionHasErrors('items.0.service_id');
    $post(pkCombo(['name' => 'E', 'type' => 'khac']))->assertSessionHasErrors('type');
    expect(ServicePackage::count())->toBe(0);
});

it('gói phải có ít nhất một giới hạn: không hạn thì mọi dịch vụ phải có số lần', function () {
    $post = fn (array $payload) => $this->actingAs($this->manager)->post(route('service_packages.store'), $payload);

    $post(pkCombo(['name' => 'A', 'validity_days' => null, 'items' => [pkItem($this->goiDau, null)]]))->assertSessionHasErrors('items.0.quantity');
    $post(pkCourse(['name' => 'B', 'validity_days' => null, 'items' => [pkItem($this->goiDau, 10), pkItem($this->massage, null)]]))->assertSessionHasErrors('items.1.quantity');
    expect(ServicePackage::count())->toBe(0);

    // có hạn thì được không giới hạn số lần
    $post(pkCombo(['name' => 'C', 'validity_days' => 30, 'items' => [pkItem($this->goiDau, null)]]))->assertSessionHasNoErrors();
    $post(pkCourse(['name' => 'D', 'validity_days' => 30, 'items' => [pkItem($this->goiDau, null), pkItem($this->massage, 3)]]))->assertSessionHasNoErrors();
    expect(ServicePackage::count())->toBe(2);

    $api = app(ServiceCatalogService::class);
    $unlimited = collect($api->searchPackages('', null, false))->firstWhere('name', 'C');
    expect($unlimited['items'][0]['quantity'])->toBeNull()->and($unlimited['retail_total'])->toBeNull();     // không giới hạn thì không có tổng giá lẻ
});

it('kiểm tra số: hạn, bảo hành, số lần, giá', function () {
    $post = fn (array $over) => $this->actingAs($this->manager)->post(route('service_packages.store'), pkCombo($over + ['name' => 'Gói mới']));

    $post(['validity_days' => 0])->assertSessionHasErrors('validity_days');
    $post(['validity_days' => 3651])->assertSessionHasErrors('validity_days');
    $post(['validity_days' => 'abc'])->assertSessionHasErrors('validity_days');
    $post(['warranty_days' => 0])->assertSessionHasErrors('warranty_days');
    $post(['warranty_days' => 3651])->assertSessionHasErrors('warranty_days');
    $post(['items' => [pkItem($this->goiDau, 0)]])->assertSessionHasErrors('items.0.quantity');
    $post(['items' => [pkItem($this->goiDau, 10000)]])->assertSessionHasErrors('items.0.quantity');
    $post(['price' => -1])->assertSessionHasErrors('price');
    $post(['price' => null])->assertSessionHasErrors('price');
    $post(['price' => 10.123])->assertSessionHasErrors('price');
    $post(['name' => ''])->assertSessionHasErrors('name');
    $post(['is_active' => null])->assertSessionHasErrors('is_active');
    expect(ServicePackage::count())->toBe(0);
});

it('hoa hồng người bán: % tối đa 100, tiền tối đa bằng giá gói', function () {
    $post = fn (array $over) => $this->actingAs($this->manager)->post(route('service_packages.store'), pkCombo($over));

    $post(['name' => 'A', 'sale_commission_type' => 'percent', 'sale_commission_value' => 100.5])->assertSessionHasErrors('sale_commission_value');
    $post(['name' => 'B', 'sale_commission_type' => 'amount', 'sale_commission_value' => 400001])->assertSessionHasErrors('sale_commission_value');
    expect(session('errors')->first('sale_commission_value'))->toContain('giá gói');
    $post(['name' => 'C', 'sale_commission_type' => 'khac'])->assertSessionHasErrors('sale_commission_type');
    expect(ServicePackage::count())->toBe(0);

    $post(['name' => 'D', 'sale_commission_type' => 'percent', 'sale_commission_value' => 100])->assertSessionHasNoErrors();
    $post(['name' => 'E', 'sale_commission_type' => 'amount', 'sale_commission_value' => 400000])->assertSessionHasNoErrors();
    $post(['name' => 'F', 'sale_commission_value' => 0])->assertSessionHasNoErrors();
    expect(ServicePackage::count())->toBe(3);
});

it('hoa hồng sử dụng theo dịch vụ: tối đa bằng giá lẻ của dịch vụ đó', function () {
    $post = fn (array $over) => $this->actingAs($this->manager)->post(route('service_packages.store'), pkCombo($over));

    $post(['name' => 'A', 'items' => [pkItem($this->goiDau, 10, 'amount', 50001)]])->assertSessionHasErrors('items.0.commission_value');
    expect(session('errors')->first('items.0.commission_value'))->toContain('giá dịch vụ');
    $post(['name' => 'B', 'items' => [pkItem($this->goiDau, 10, 'percent', 101)]])->assertSessionHasErrors('items.0.commission_value');
    expect(ServicePackage::count())->toBe(0);

    $post(['name' => 'C', 'items' => [pkItem($this->goiDau, 10, 'amount', 50000)]])->assertSessionHasNoErrors();
    expect(ServicePackage::count())->toBe(1);
});

it('hoa hồng chung dạng tiền không được lớn hơn giá dịch vụ thấp nhất trong gói', function () {
    $post = fn (array $over) => $this->actingAs($this->manager)->post(route('service_packages.store'), pkCourse(['usage_commission_mode' => 'common'] + $over));

    $post(['name' => 'A', 'usage_commission_type' => 'amount', 'usage_commission_value' => 50001])->assertSessionHasErrors('usage_commission_value');   // gội đầu 50.000 là thấp nhất
    $post(['name' => 'B', 'usage_commission_type' => 'percent', 'usage_commission_value' => 100.01])->assertSessionHasErrors('usage_commission_value');
    expect(ServicePackage::count())->toBe(0);

    $post(['name' => 'C', 'usage_commission_type' => 'amount', 'usage_commission_value' => 50000])->assertSessionHasNoErrors();
    expect(ServicePackage::count())->toBe(1);
});

it('bắt buộc chọn danh mục đang sử dụng', function () {
    $post = fn (array $over) => $this->actingAs($this->manager)->post(route('service_packages.store'), pkCombo($over));
    $inactive = ServiceCategory::create(['name' => 'Ngừng', 'is_active' => false]);

    $post(['name' => 'A', 'service_category_id' => null])->assertSessionHasErrors('service_category_id');
    $post(['name' => 'B', 'service_category_id' => 999999])->assertSessionHasErrors('service_category_id');
    $post(['name' => 'C', 'service_category_id' => $inactive->id])->assertSessionHasErrors('service_category_id');
    expect(ServicePackage::count())->toBe(0);

    $post(['name' => 'D'])->assertSessionHasNoErrors();
    expect(ServicePackage::first()->service_category_id)->toBe($this->cat->id);
});

it('chỉ thêm được dịch vụ đang bán; sửa gói vẫn giữ dịch vụ đã ngừng bán', function () {
    $stopped = pkService(['name' => 'Đã ngừng', 'price' => 80000, 'is_active' => false]);
    $post = fn (array $payload) => $this->actingAs($this->manager)->post(route('service_packages.store'), $payload);

    $post(pkCombo(['name' => 'A', 'items' => [pkItem($stopped, 5)]]))->assertSessionHasErrors('items.0.service_id');
    $post(pkCombo(['name' => 'B', 'items' => [['service_id' => 999999, 'quantity' => 5, 'commission_type' => 'amount', 'commission_value' => 0]]]))->assertSessionHasErrors('items.0.service_id');
    expect(ServicePackage::count())->toBe(0);

    // dịch vụ bị ngừng bán SAU khi đã nằm trong gói: sửa gói vẫn giữ được
    $post(pkCombo(['name' => 'C']))->assertSessionHasNoErrors();
    $p = ServicePackage::first();
    $this->goiDau->update(['is_active' => false]);

    $this->actingAs($this->manager)->put(route('service_packages.update', $p), pkCombo(['name' => 'C', 'price' => 450000]))->assertSessionHasNoErrors();
    expect($p->fresh()->price)->toBe(450000.0)->and($p->items()->first()->service_id)->toBe($this->goiDau->id);
});

it('tên gói không trùng (không phân biệt hoa thường); gói đã xóa thì dùng lại được tên', function () {
    $this->actingAs($this->manager)->post(route('service_packages.store'), pkCombo(['name' => 'Gói VIP']))->assertSessionHasNoErrors();
    $p = ServicePackage::first();

    $this->actingAs($this->manager)->post(route('service_packages.store'), pkCombo(['name' => 'gói vip']))->assertSessionHasErrors('name');
    expect(ServicePackage::count())->toBe(1);

    $this->actingAs($this->manager)->delete(route('service_packages.destroy', $p));
    $this->actingAs($this->manager)->post(route('service_packages.store'), pkCombo(['name' => 'Gói VIP']))->assertSessionHasNoErrors();
    expect(ServicePackage::count())->toBe(1);

    $this->actingAs($this->manager)->getJson(route('service_packages.check', ['value' => 'GÓI VIP']))->assertJson(['available' => false, 'message' => 'Tên gói này đã có.']);
    $this->actingAs($this->manager)->getJson(route('service_packages.check', ['value' => 'Gói VIP', 'ignore' => ServicePackage::first()->id]))->assertJson(['available' => true]);
    $this->actingAs($this->manager)->getJson(route('service_packages.check', ['value' => '']))->assertJson(['available' => true]);
});

it('sửa gói: thay toàn bộ dịch vụ, giữ mã và loại gói', function () {
    $this->actingAs($this->manager)->post(route('service_packages.store'), pkCourse())->assertSessionHasNoErrors();
    $p = ServicePackage::first();
    $code = $p->code;

    $this->actingAs($this->manager)->put(route('service_packages.update', $p), pkCourse(['type' => 'combo', 'name' => 'Liệu trình mới', 'items' => [pkItem($this->massage, 3, 'amount', 1000), pkItem($this->goiDau, 2, 'amount', 0)], 'validity_days' => 60, 'warranty_days' => 15]))
        ->assertSessionHasNoErrors();

    $p->refresh()->load('items');
    expect($p->code)->toBe($code)
        ->and($p->type)->toBe('course')                                   // loại gói không đổi được
        ->and($p->name)->toBe('Liệu trình mới')
        ->and($p->validity_days)->toBe(60)->and($p->warranty_days)->toBe(15)
        ->and($p->items->pluck('service_id')->all())->toBe([$this->massage->id, $this->goiDau->id])
        ->and($p->items->pluck('quantity')->all())->toBe([3, 2]);

    // bỏ hạn và bảo hành: để trống thì về null
    $this->actingAs($this->manager)->put(route('service_packages.update', $p), pkCourse(['name' => 'Liệu trình mới', 'validity_days' => null, 'warranty_days' => null]))->assertSessionHasNoErrors();
    expect($p->fresh()->validity_days)->toBeNull()->and($p->fresh()->warranty_days)->toBeNull();
});

it('xóa gói là xóa mềm: không còn trong danh sách nhưng API vẫn tra được', function () {
    $this->actingAs($this->manager)->post(route('service_packages.store'), pkCombo())->assertSessionHasNoErrors();
    $p = ServicePackage::first();

    $this->actingAs($this->manager)->delete(route('service_packages.destroy', $p))->assertSessionHas('success');

    expect(ServicePackage::find($p->id))->toBeNull()
        ->and(ServicePackage::withTrashed()->find($p->id))->not->toBeNull()
        ->and(app(ServiceCatalogService::class)->findPackage($p->id)['name'])->toBe('Combo gội đầu 10 lần');

    $this->actingAs($this->manager)->get(route('service_combos.index'))->assertInertia(fn ($page) => $page->has('packages.data', 0));
    $this->actingAs($this->manager)->put(route('service_packages.update', $p), pkCombo())->assertNotFound();
});

it('không xóa được danh mục hoặc dịch vụ đang nằm trong combo / liệu trình', function () {
    $this->actingAs($this->manager)->post(route('service_packages.store'), pkCourse())->assertSessionHasNoErrors();
    $p = ServicePackage::first();

    $this->actingAs($this->manager)->delete(route('services.destroy', $this->goiDau))->assertSessionHas('error');
    expect(Service::find($this->goiDau->id))->not->toBeNull();
    $this->actingAs($this->manager)->delete(route('service_categories.destroy', $this->cat))->assertSessionHas('error');
    expect(ServiceCategory::find($this->cat->id))->not->toBeNull();

    // sau khi xóa gói (xóa mềm), danh mục vẫn bị giữ vì khóa ngoại còn trỏ tới; dịch vụ thì xóa được
    $this->actingAs($this->manager)->delete(route('service_packages.destroy', $p));
    $this->actingAs($this->manager)->delete(route('service_categories.destroy', $this->cat))->assertSessionHas('error');
    $this->actingAs($this->manager)->delete(route('services.destroy', $this->goiDau))->assertSessionHas('success');
});

it('danh sách tách theo loại, hiện hoa hồng tự tính, tìm kiếm và lọc', function () {
    $this->actingAs($this->manager)->post(route('service_packages.store'), pkCombo(['name' => 'B Combo gội', 'sale_commission_type' => 'percent', 'sale_commission_value' => 5]));
    $this->actingAs($this->manager)->post(route('service_packages.store'), pkCombo(['name' => 'A Combo ngừng', 'is_active' => false]));
    $this->actingAs($this->manager)->post(route('service_packages.store'), pkCourse(['name' => 'Liệu trình spa']));

    $this->actingAs(pkUser('cashier'))->get(route('service_combos.index'))
        ->assertInertia(fn ($page) => $page
            ->where('type', 'combo')
            ->has('packages.data', 2)
            ->where('packages.data.0.name', 'A Combo ngừng')
            ->where('packages.data.1.sale_commission', 20000)
            ->where('packages.data.1.items.0.service_name', 'Gội đầu')
            ->has('categories', 1)
            ->has('services', 2));

    $this->actingAs($this->manager)->get(route('service_courses.index'))
        ->assertInertia(fn ($page) => $page->where('type', 'course')->has('packages.data', 1)->where('packages.data.0.items.1.commission', 20000));

    $this->actingAs($this->manager)->get(route('service_combos.index', ['search' => 'gội']))->assertInertia(fn ($page) => $page->has('packages.data', 1)->where('packages.data.0.name', 'B Combo gội'));
    $this->actingAs($this->manager)->get(route('service_combos.index', ['status' => 'inactive']))->assertInertia(fn ($page) => $page->has('packages.data', 1)->where('packages.data.0.name', 'A Combo ngừng'));
    $this->actingAs($this->manager)->get(route('service_combos.index', ['category' => $this->cat->id]))->assertInertia(fn ($page) => $page->has('packages.data', 2));
    $this->actingAs($this->manager)->get(route('service_combos.index', ['status' => 'xyz', 'category' => 'abc']))->assertInertia(fn ($page) => $page->has('packages.data', 2)->where('filters.status', null)->where('filters.category', null));
});

it('API cho Bước B / Bán hàng: tra một gói, tìm gói đang bán, tổng giá lẻ', function () {
    $this->actingAs($this->manager)->post(route('service_packages.store'), pkCourse(['name' => 'Liệu trình spa', 'validity_days' => 90, 'warranty_days' => 30]));
    $this->actingAs($this->manager)->post(route('service_packages.store'), pkCombo(['name' => 'Combo ngừng', 'is_active' => false]));
    $api = app(ServiceCatalogService::class);
    $course = ServicePackage::where('name', 'Liệu trình spa')->first();

    $found = $api->findPackage($course->id);
    expect($found)->toMatchArray([
        'id' => $course->id, 'code' => $course->code, 'type' => 'course', 'name' => 'Liệu trình spa', 'category' => 'Spa',
        'price' => 1200000.0, 'validity_days' => 90, 'warranty_days' => 30, 'is_active' => true,
        'retail_total' => 1500000.0,                                    // 10 × 50.000 + 5 × 200.000
    ])
        ->and($found['items'][0])->toMatchArray(['service_id' => $this->goiDau->id, 'service_name' => 'Gội đầu', 'service_price' => 50000.0, 'quantity' => 10, 'commission' => 5000.0, 'service_active' => true])
        ->and($api->findPackage(999999))->toBeNull()
        ->and(collect($api->searchPackages())->pluck('name')->all())->toBe(['Liệu trình spa'])                      // mặc định chỉ gói đang bán
        ->and(collect($api->searchPackages('', null, false))->pluck('name')->all())->toBe(['Combo ngừng', 'Liệu trình spa'])
        ->and(collect($api->searchPackages('', 'combo', false))->pluck('name')->all())->toBe(['Combo ngừng'])
        ->and(collect($api->searchPackages($course->code))->pluck('id')->all())->toBe([$course->id])
        ->and($api->searchPackages('', null, false, 1))->toHaveCount(1);
});

it('cấp mã combo / liệu trình GOI', function () {
    expect(DocumentNumber::next('service_package'))->toStartWith('GOI-');
});

it('menu: Combo và Liệu trình nằm trong nhóm Dịch vụ', function () {
    $items = fn (User $u) => collect(app(\App\Modules\Core\Support\ModuleRegistry::class)->navigation($u))->firstWhere('label', 'Dịch vụ')['items'];

    expect(collect($items($this->manager))->pluck('label')->all())->toBe(['Dịch vụ', 'Danh mục dịch vụ', 'Combo', 'Liệu trình'])
        ->and(collect($items(pkUser('cashier')))->pluck('label')->all())->toBe(['Dịch vụ', 'Combo', 'Liệu trình']);
});
