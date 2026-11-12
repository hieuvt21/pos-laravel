<?php

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\PriceList;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Catalog\Services\CatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('pos:sync');
    $this->admin = User::where('username', 'admin')->first();
    $this->admin->forceFill(['must_change_password' => false])->save();
    $this->retail = PriceList::where('is_default', true)->first();
    $this->wholesale = PriceList::create(['name' => 'Giá sỉ', 'is_default' => false, 'is_active' => true]);
    $this->category = Category::create(['name' => 'Nước giải khát', 'is_active' => true]);
    $this->unit = Unit::where('is_default', true)->first();
});

function payload(array $over = []): array
{
    return array_merge([
        'name' => 'Nước suối',
        'category_id' => test()->category->id,
        'unit_id' => test()->unit->id,
        'tax_rate' => 0,
        'is_active' => true,
        'prices' => [['price_list_id' => test()->retail->id, 'price' => 5000]],
    ], $over);
}

it('có sẵn đơn vị Cái và bảng giá Bán lẻ mặc định', function () {
    expect($this->unit->name)->toBe('Cái')->and($this->retail->name)->toBe('Bán lẻ');
});

it('tạo sản phẩm: tự cấp mã, bắt buộc giá bán lẻ, không trùng mã vạch', function () {
    $this->actingAs($this->admin)->post(route('products.store'), payload(['barcode' => '893100']))->assertSessionDoesntHaveErrors();
    expect(Product::first()->code)->toBe('SP-00001');

    $this->actingAs($this->admin)->post(route('products.store'), payload(['name' => 'B', 'barcode' => '893100']))->assertSessionHasErrors('barcode');
    $this->actingAs($this->admin)->post(route('products.store'), payload(['name' => 'C', 'prices' => [['price_list_id' => $this->retail->id, 'price' => '']]]))
        ->assertSessionHasErrors('prices.0.price');
});

it('xóa mềm sản phẩm và giải phóng mã vạch', function () {
    $this->actingAs($this->admin)->post(route('products.store'), payload(['barcode' => '111']));
    $p = Product::first();

    $this->actingAs($this->admin)->delete(route('products.destroy', $p));

    expect(Product::count())->toBe(0)->and(Product::withTrashed()->first()->barcode)->toBeNull();
});

it('quét mã: ưu tiên mã vạch, không có thì dùng mã sản phẩm, bỏ qua sản phẩm ngừng bán', function () {
    $this->actingAs($this->admin)->post(route('products.store'), payload(['barcode' => '893100']));
    $this->actingAs($this->admin)->post(route('products.store'), payload(['name' => 'Rau']));
    $svc = app(CatalogService::class);

    expect($svc->findByBarcode('893100')->name)->toBe('Nước suối')
        ->and($svc->findByBarcode('SP-00002')->name)->toBe('Rau')
        ->and($svc->findByBarcode('khong-co'))->toBeNull();

    Product::where('name', 'Rau')->update(['is_active' => false]);
    expect($svc->findByBarcode('SP-00002'))->toBeNull();
});

it('giá theo số lượng: chọn mức cao nhất đạt được, bảng giá chỉ định ưu tiên', function () {
    $this->actingAs($this->admin)->post(route('products.store'), payload([
        'qty_pricing' => true,
        'prices' => [
            ['price_list_id' => $this->retail->id, 'price' => 5000],
            ['price_list_id' => $this->wholesale->id, 'price' => 4000, 'min_qty' => 10],
        ],
    ]))->assertSessionDoesntHaveErrors();
    $p = Product::first();
    $svc = app(CatalogService::class);

    expect($svc->priceFor($p, 1))->toBe(5000.0)
        ->and($svc->priceFor($p, 9))->toBe(5000.0)
        ->and($svc->priceFor($p, 10))->toBe(4000.0)
        ->and($svc->priceFor($p, 1, $this->wholesale->id))->toBe(4000.0);
});

it('đơn vị mặc định không xóa được; thu ngân chỉ xem', function () {
    $this->actingAs($this->admin)->delete(route('catalog.destroy', ['type' => 'units', 'id' => $this->unit->id]));
    expect(Unit::count())->toBe(1);

    $u = (new User)->forceFill(['name' => 'Thu ngân', 'username' => 'thungan', 'password' => bcrypt('123456'), 'must_change_password' => false]);
    $u->save();
    $u->assignRole('cashier');

    $this->actingAs($u)->get(route('products.index'))->assertOk();
    $this->actingAs($u)->post(route('products.store'), payload())->assertForbidden();
    $this->actingAs($u)->post(route('catalog.store', ['type' => 'categories']), ['name' => 'X', 'is_active' => true])->assertForbidden();
});

it('chặn trùng tên sản phẩm, danh mục, đơn vị, bảng giá: không phân biệt hoa thường, phân biệt dấu', function () {
    $this->actingAs($this->admin)->post(route('products.store'), payload(['name' => 'Cá hộp']))->assertSessionDoesntHaveErrors();

    $this->actingAs($this->admin)->post(route('products.store'), payload(['name' => 'cá HỘP']))->assertSessionHasErrors('name');
    $this->actingAs($this->admin)->post(route('products.store'), payload(['name' => 'Ca hộp']))->assertSessionDoesntHaveErrors();

    $this->actingAs($this->admin)->post(route('catalog.store', ['type' => 'categories']), ['name' => 'nước giải khát', 'is_active' => true])->assertSessionHasErrors('name');
    $this->actingAs($this->admin)->post(route('catalog.store', ['type' => 'units']), ['name' => 'cái', 'is_active' => true])->assertSessionHasErrors('name');
    $this->actingAs($this->admin)->post(route('catalog.store', ['type' => 'price-lists']), ['name' => 'bán lẻ', 'is_active' => true])->assertSessionHasErrors('name');
});

it('API kiểm tra trùng tên khi đang nhập, bỏ qua chính bản ghi đang sửa', function () {
    $this->actingAs($this->admin)->getJson(route('catalog.check', ['type' => 'categories', 'field' => 'name', 'value' => 'Nước Giải Khát']))
        ->assertJson(['available' => false]);
    $this->actingAs($this->admin)->getJson(route('catalog.check', ['type' => 'categories', 'field' => 'name', 'value' => 'Nước giải khát', 'ignore' => $this->category->id]))
        ->assertJson(['available' => true]);
    $this->actingAs($this->admin)->getJson(route('products.check', ['field' => 'name', 'value' => 'Chưa có']))
        ->assertJson(['available' => true]);
});

it('chọn số dòng mỗi trang, giá trị lạ về mặc định 15', function () {
    foreach (range(1, 12) as $i) {
        Product::create(['code' => "SP-$i", 'name' => "SP $i", 'category_id' => $this->category->id, 'unit_id' => $this->unit->id]);
    }

    $this->actingAs($this->admin)->get(route('products.index', ['per_page' => 10]))
        ->assertInertia(fn ($page) => $page->where('products.per_page', 10)->where('products.total', 12));
    $this->actingAs($this->admin)->get(route('products.index', ['per_page' => 7]))
        ->assertInertia(fn ($page) => $page->where('products.per_page', 15));
});

it('ngừng bán / bán lại; giá vốn chỉ gửi cho người có quyền sửa', function () {
    $this->actingAs($this->admin)->post(route('products.store'), payload(['cost_price' => 3000]));
    $p = Product::first();

    $this->actingAs($this->admin)->patch(route('products.toggle', $p));
    expect($p->fresh()->is_active)->toBeFalse();

    $this->actingAs($this->admin)->get(route('products.index'))
        ->assertInertia(fn ($page) => $page->where('products.data.0.cost_price', '3000.00'));

    $u = (new User)->forceFill(['name' => 'Thu ngân', 'username' => 'thungan2', 'password' => bcrypt('123456'), 'must_change_password' => false]);
    $u->save();
    $u->assignRole('cashier');

    $this->actingAs($u)->get(route('products.index'))->assertInertia(fn ($page) => $page->where('products.data.0.cost_price', null));
    $this->actingAs($u)->patch(route('products.toggle', $p))->assertForbidden();
});
