<?php

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\PriceList;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('pos:sync');
    $this->manager = User::factory()->create();
    $this->manager->assignRole('manager');
    $this->retail = PriceList::where('is_default', true)->first();
    $this->category = Category::create(['name' => 'Tạp hóa', 'is_active' => true]);
    $this->unit = Unit::where('is_default', true)->first();
});

function cmPayload(array $over = []): array
{
    return array_merge([
        'name' => 'Sạc điện thoại',
        'category_id' => test()->category->id,
        'unit_id' => test()->unit->id,
        'tax_rate' => 0,
        'is_active' => true,
        'prices' => [['price_list_id' => test()->retail->id, 'price' => 200000]],
    ], $over);
}

it('lưu định mức hoa hồng của sản phẩm: bằng tiền hoặc phần trăm', function () {
    $this->actingAs($this->manager)->post(route('products.store'), cmPayload(['name' => 'A', 'commission_type' => 'amount', 'commission_value' => 15000]))->assertSessionHasNoErrors();
    $this->actingAs($this->manager)->post(route('products.store'), cmPayload(['name' => 'B', 'commission_type' => 'percent', 'commission_value' => 10]))->assertSessionHasNoErrors();

    $a = Product::where('name', 'A')->first();
    $b = Product::where('name', 'B')->first();
    expect($a->commission_type)->toBe('amount')->and($a->commission_value)->toBe(15000.0)
        ->and($b->commission_type)->toBe('percent')->and($b->commission_value)->toBe(10.0);
});

it('không gửi định mức thì mặc định là 0 đồng (không có hoa hồng)', function () {
    $this->actingAs($this->manager)->post(route('products.store'), cmPayload())->assertSessionHasNoErrors();

    $p = Product::first();
    expect($p->commission_type)->toBe('amount')->and($p->commission_value)->toBe(0.0);
});

it('danh sách sản phẩm trả hoa hồng tính trên giá bán lẻ', function () {
    $this->actingAs($this->manager)->post(route('products.store'), cmPayload(['name' => 'A Tiền', 'commission_type' => 'amount', 'commission_value' => 15000]));
    $this->actingAs($this->manager)->post(route('products.store'), cmPayload(['name' => 'B Phần trăm', 'commission_type' => 'percent', 'commission_value' => 10]));
    $this->actingAs($this->manager)->post(route('products.store'), cmPayload(['name' => 'C Không']));

    $this->actingAs($this->manager)->get(route('products.index'))->assertInertia(fn ($page) => $page
        ->where('products.data.0.commission_type', 'amount')
        ->where('products.data.0.commission_value', 15000)
        ->where('products.data.0.commission', 15000)
        ->where('products.data.1.commission_type', 'percent')
        ->where('products.data.1.commission_value', 10)
        ->where('products.data.1.commission', 20000)      // 10% của giá bán lẻ 200.000
        ->where('products.data.2.commission', 0));
});

it('định mức phần trăm không được vượt quá 100% và tối đa 2 chữ số thập phân', function () {
    $post = fn (array $over) => $this->actingAs($this->manager)->post(route('products.store'), cmPayload(['commission_type' => 'percent'] + $over));

    $post(['name' => 'A', 'commission_value' => 100.01])->assertSessionHasErrors('commission_value');
    expect(session('errors')->first('commission_value'))->toContain('100%');
    $post(['name' => 'B', 'commission_value' => 150])->assertSessionHasErrors('commission_value');
    $post(['name' => 'C', 'commission_value' => 12.345])->assertSessionHasErrors('commission_value');
    expect(Product::count())->toBe(0);

    $post(['name' => 'D', 'commission_value' => 100])->assertSessionHasNoErrors();
    $post(['name' => 'E', 'commission_value' => 12.5])->assertSessionHasNoErrors();
    expect(Product::count())->toBe(2);
});

it('định mức bằng tiền không được lớn hơn giá bán lẻ', function () {
    $post = fn (array $over) => $this->actingAs($this->manager)->post(route('products.store'), cmPayload(['commission_type' => 'amount'] + $over));

    $post(['name' => 'A', 'commission_value' => 200001])->assertSessionHasErrors('commission_value');
    expect(session('errors')->first('commission_value'))->toContain('lớn hơn giá bán lẻ');
    expect(Product::count())->toBe(0);

    $post(['name' => 'B', 'commission_value' => 200000])->assertSessionHasNoErrors();   // bằng giá thì được
    expect(Product::count())->toBe(1);
});

it('kiểm tra dữ liệu định mức: đơn vị lạ, số âm, không phải số', function () {
    $post = fn (array $over) => $this->actingAs($this->manager)->post(route('products.store'), cmPayload($over + ['name' => 'X']));

    $post(['commission_type' => 'khac', 'commission_value' => 5])->assertSessionHasErrors('commission_type');
    $post(['commission_type' => 'amount', 'commission_value' => -1])->assertSessionHasErrors('commission_value');
    $post(['commission_type' => 'amount', 'commission_value' => 'abc'])->assertSessionHasErrors('commission_value');

    expect(Product::count())->toBe(0);
});

it('sửa sản phẩm: đổi giá bán lẻ thấp hơn định mức tiền thì báo lỗi; định mức phần trăm theo giá mới', function () {
    $this->actingAs($this->manager)->post(route('products.store'), cmPayload(['commission_type' => 'amount', 'commission_value' => 150000]))->assertSessionHasNoErrors();
    $p = Product::first();

    $this->actingAs($this->manager)->put(route('products.update', $p), cmPayload([
        'commission_type' => 'amount', 'commission_value' => 150000,
        'prices' => [['price_list_id' => $this->retail->id, 'price' => 100000]],
    ]))->assertSessionHasErrors('commission_value');
    expect($p->fresh()->commission_value)->toBe(150000.0);

    $this->actingAs($this->manager)->put(route('products.update', $p), cmPayload([
        'commission_type' => 'percent', 'commission_value' => 10,
        'prices' => [['price_list_id' => $this->retail->id, 'price' => 300000]],
    ]))->assertSessionHasNoErrors();
    expect($p->fresh()->commission_type)->toBe('percent');

    $this->actingAs($this->manager)->get(route('products.index'))->assertInertia(fn ($page) => $page->where('products.data.0.commission', 30000));   // 10% của 300.000
});

it('client cũ không gửi định mức thì sửa sản phẩm không làm mất hoa hồng đã có', function () {
    $this->actingAs($this->manager)->post(route('products.store'), cmPayload(['commission_type' => 'percent', 'commission_value' => 8]))->assertSessionHasNoErrors();
    $p = Product::first();

    $this->actingAs($this->manager)->put(route('products.update', $p), cmPayload(['name' => 'Sạc nhanh']))->assertSessionHasNoErrors();

    expect($p->fresh()->name)->toBe('Sạc nhanh')->and($p->fresh()->commission_type)->toBe('percent')->and($p->fresh()->commission_value)->toBe(8.0);
});

it('hoa hồng tính theo giá bán lẻ, không theo các bảng giá khác', function () {
    $wholesale = PriceList::create(['name' => 'Giá sỉ', 'is_default' => false, 'is_active' => true]);

    $this->actingAs($this->manager)->post(route('products.store'), cmPayload([
        'commission_type' => 'percent', 'commission_value' => 10,
        'prices' => [
            ['price_list_id' => $this->retail->id, 'price' => 200000],
            ['price_list_id' => $wholesale->id, 'price' => 150000],
        ],
    ]))->assertSessionHasNoErrors();

    $this->actingAs($this->manager)->get(route('products.index'))->assertInertia(fn ($page) => $page->where('products.data.0.commission', 20000));
});
