<?php

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\PriceList;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Catalog\Services\CatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('pos:sync');
    $this->admin = User::where('username', 'admin')->first();
    $this->admin->forceFill(['must_change_password' => false])->save();
    $this->retail = PriceList::where('is_default', true)->first();
    $this->category = Category::create(['name' => 'Tạp hóa', 'is_active' => true]);
    $this->unit = Unit::where('is_default', true)->first();
});

function stockPayload(array $over = []): array
{
    return array_merge([
        'name' => 'Gạo thơm',
        'category_id' => test()->category->id,
        'unit_id' => test()->unit->id,
        'tax_rate' => 0,
        'is_active' => true,
        'track_stock' => true,
        'prices' => [['price_list_id' => test()->retail->id, 'price' => 20000]],
    ], $over);
}

it('đơn vị tính có tùy chọn cho phép số lẻ, mặc định tắt và giữ nguyên khi sửa mà không gửi lại', function () {
    expect($this->unit->allow_decimal)->toBeFalse();

    $this->actingAs($this->admin)->post(route('catalog.store', ['type' => 'units']), ['name' => 'Kg', 'is_default' => false, 'is_active' => true, 'allow_decimal' => true])
        ->assertSessionDoesntHaveErrors();
    $kg = Unit::where('name', 'Kg')->first();
    expect($kg->allow_decimal)->toBeTrue();

    $this->actingAs($this->admin)->put(route('catalog.update', ['type' => 'units', 'id' => $kg->id]), ['name' => 'Kilogram', 'is_default' => false, 'is_active' => true])
        ->assertSessionDoesntHaveErrors();
    expect($kg->fresh()->allow_decimal)->toBeTrue()->and($kg->fresh()->name)->toBe('Kilogram');
});

it('sản phẩm lưu tồn tối thiểu, chặn số âm và quá 3 chữ số thập phân', function () {
    $this->actingAs($this->admin)->post(route('products.store'), stockPayload(['min_stock' => 2.5]))->assertSessionDoesntHaveErrors();
    expect(Product::first()->min_stock)->toBe(2.5);

    $this->actingAs($this->admin)->post(route('products.store'), stockPayload(['name' => 'B', 'min_stock' => -1]))->assertSessionHasErrors('min_stock');
    $this->actingAs($this->admin)->post(route('products.store'), stockPayload(['name' => 'C', 'min_stock' => 1.2345]))->assertSessionHasErrors('min_stock');

    $this->actingAs($this->admin)->post(route('products.store'), stockPayload(['name' => 'D']))->assertSessionDoesntHaveErrors();
    expect(Product::where('name', 'D')->first()->min_stock)->toBe(0.0);
});

it('CatalogService cấp thông tin kho dạng mảng và cập nhật giá vốn, kể cả sản phẩm đã xóa mềm', function () {
    $kg = Unit::create(['name' => 'Kg', 'is_default' => false, 'is_active' => true, 'allow_decimal' => true]);
    $p = Product::create(['code' => 'G1', 'name' => 'Gạo', 'category_id' => $this->category->id, 'unit_id' => $kg->id, 'track_stock' => true, 'min_stock' => 5, 'cost_price' => 100]);
    $svc = app(CatalogService::class);

    expect($svc->stockProfile($p->id))->toBe([
        'id' => $p->id, 'track_stock' => true, 'allow_decimal' => true, 'cost_price' => 100.0, 'min_stock' => 5.0,
    ]);

    $svc->setCostPrice($p->id, 1234.567);
    expect((float) $p->fresh()->cost_price)->toBe(1234.57);

    $p->delete();
    expect($svc->stockProfile($p->id)['track_stock'])->toBeTrue()
        ->and($svc->stockProfile(999999))->toBeNull();
});

it('giá vốn chỉ hiện và sửa được với quyền xem giá vốn của kho; thiếu quyền thì giữ nguyên giá vốn cũ', function () {
    // vai trò chỉ được sửa sản phẩm, không có inventory.cost
    Role::create(['name' => 'editor'])->givePermissionTo(['products.view', 'products.create', 'products.update']);
    $editor = User::factory()->create();
    $editor->assignRole('editor');
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    $p = Product::create(['code' => 'GV1', 'name' => 'Gạo', 'category_id' => $this->category->id, 'unit_id' => $this->unit->id, 'cost_price' => 3000]);

    // không có quyền: không thấy giá vốn, form không có ô giá vốn
    $this->actingAs($editor)->get(route('products.index'))
        ->assertInertia(fn ($page) => $page->where('canCost', false)->where('products.data.0.cost_price', null));

    // sửa sản phẩm kèm giá vốn lạ: bị bỏ qua, giá vốn cũ giữ nguyên (không bị ghi về 0)
    $this->actingAs($editor)->put(route('products.update', $p), stockPayload(['name' => 'Gạo thơm', 'cost_price' => 99999]))->assertSessionHasNoErrors();
    expect($p->fresh()->name)->toBe('Gạo thơm')->and((float) $p->fresh()->cost_price)->toBe(3000.0);

    // thêm sản phẩm mới kèm giá vốn: không có quyền nên giá vốn = 0
    $this->actingAs($editor)->post(route('products.store'), stockPayload(['name' => 'Đường', 'cost_price' => 5000]))->assertSessionHasNoErrors();
    expect((float) Product::where('name', 'Đường')->first()->cost_price)->toBe(0.0);

    // có quyền (quản lý): thấy và sửa được
    $this->actingAs($manager)->get(route('products.index'))
        ->assertInertia(fn ($page) => $page->where('canCost', true));
    $this->actingAs($manager)->put(route('products.update', $p), stockPayload(['name' => 'Gạo thơm', 'cost_price' => 3500]))->assertSessionHasNoErrors();
    expect((float) $p->fresh()->cost_price)->toBe(3500.0);
});
