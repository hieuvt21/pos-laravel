<?php

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Core\Support\ModuleRegistry;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Support\InventorySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('pos:sync');
    $this->catFood = Category::create(['name' => 'Tạp hóa', 'is_active' => true]);
    $this->catDrink = Category::create(['name' => 'Đồ uống', 'is_active' => true]);
    $this->unit = Unit::where('is_default', true)->first();
    $stock = app(StockService::class);

    // Tên bắt đầu bằng A/B/C để thứ tự theo tên giống nhau ở mọi cơ sở dữ liệu
    $this->gao = invProduct(['name' => 'A Gạo', 'category_id' => $this->catFood->id, 'min_stock' => 5, 'cost_price' => 20000]);
    $this->duong = invProduct(['name' => 'B Đường', 'category_id' => $this->catFood->id, 'min_stock' => 5, 'cost_price' => 10000]);
    $this->nuoc = invProduct(['name' => 'C Nước', 'category_id' => $this->catDrink->id, 'min_stock' => 5, 'cost_price' => 5000]);
    $this->muoi = invProduct(['name' => 'D Muối', 'track_stock' => false]);

    $stock->receive($this->gao->id, 10, null, 'opening');   // còn hàng
    $stock->receive($this->duong->id, 3, null, 'opening');  // sắp hết (3 <= 5)
    // Nước: chưa nhập -> hết hàng
});

function invProduct(array $over = []): Product
{
    static $n = 0;
    $n++;

    return Product::create(array_merge([
        'code' => 'IV'.$n,
        'name' => 'Sản phẩm '.$n,
        'category_id' => test()->catFood->id,
        'unit_id' => test()->unit->id,
        'track_stock' => true,
        'cost_price' => 0,
    ], $over));
}

function invUser(?string $role): User
{
    $user = User::factory()->create();
    if ($role) {
        $user->assignRole($role);
    }

    return $user;
}

it('khách chưa đăng nhập bị chuyển về trang đăng nhập, người không có quyền bị chặn', function () {
    $this->get(route('inventory.index'))->assertRedirect(route('login'));
    $this->actingAs(invUser(null))->get(route('inventory.index'))->assertForbidden();
});

it('thu ngân xem được tồn kho nhưng không thấy giá vốn và giá trị tồn', function () {
    $this->actingAs(invUser('cashier'))->get(route('inventory.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canCost', false)
            ->where('stats.total', 3)
            ->has('products.data', 3)
            ->missing('stats.total_value')
            ->missing('products.data.0.cost_price')
            ->missing('products.data.0.stock_value'));
});

it('quản lý thấy giá vốn, giá trị từng dòng và tổng giá trị tồn', function () {
    $this->actingAs(invUser('manager'))->get(route('inventory.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canCost', true)
            ->where('stats.total_value', 230000)            // 10 × 20.000 + 3 × 10.000
            ->where('products.data.0.name', 'A Gạo')
            ->where('products.data.0.cost_price', 20000)
            ->where('products.data.0.stock_value', 200000));
});

it('chỉ liệt kê sản phẩm bật quản lý tồn kho và tính đúng tình trạng', function () {
    $this->actingAs(invUser('cashier'))->get(route('inventory.index'))
        ->assertInertia(fn ($page) => $page
            ->where('stats.total', 3)
            ->where('stats.ok', 1)
            ->where('stats.low', 1)
            ->where('stats.out', 1)
            ->where('products.data.0.status', 'ok')
            ->where('products.data.1.status', 'low')
            ->where('products.data.2.status', 'out'));
});

it('lọc theo tình trạng nhưng số liệu đầu trang vẫn tính trên toàn bộ', function () {
    $this->actingAs(invUser('cashier'))->get(route('inventory.index', ['status' => 'low']))
        ->assertInertia(fn ($page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.name', 'B Đường')
            ->where('stats.total', 3)
            ->where('filters.status', 'low'));

    $this->actingAs(invUser('cashier'))->get(route('inventory.index', ['status' => 'xyz']))
        ->assertInertia(fn ($page) => $page->has('products.data', 3)->where('filters.status', null));
});

it('tìm theo tên và lọc theo danh mục; số liệu đầu trang theo kết quả tìm', function () {
    $this->actingAs(invUser('cashier'))->get(route('inventory.index', ['search' => 'Nước']))
        ->assertInertia(fn ($page) => $page->has('products.data', 1)->where('products.data.0.name', 'C Nước')->where('stats.total', 1));

    $this->actingAs(invUser('cashier'))->get(route('inventory.index', ['category' => $this->catDrink->id]))
        ->assertInertia(fn ($page) => $page->has('products.data', 1)->where('products.data.0.name', 'C Nước'));
});

it('sắp xếp theo tồn; giá trị tồn chỉ dùng được khi có quyền xem giá vốn', function () {
    $this->actingAs(invUser('cashier'))->get(route('inventory.index', ['sort' => 'stock_asc']))
        ->assertInertia(fn ($page) => $page->where('products.data.0.name', 'C Nước')->where('products.data.2.name', 'A Gạo'));

    $this->actingAs(invUser('cashier'))->get(route('inventory.index', ['sort' => 'value_desc']))
        ->assertInertia(fn ($page) => $page->where('filters.sort', 'name'));

    $this->actingAs(invUser('manager'))->get(route('inventory.index', ['sort' => 'value_desc']))
        ->assertInertia(fn ($page) => $page->where('filters.sort', 'value_desc')->where('products.data.0.name', 'A Gạo'));
});

it('thẻ kho trả lịch sử mới nhất trước, kèm tồn sau mỗi dòng', function () {
    app(StockService::class)->issue($this->gao->id, 2, 'sale');

    $this->actingAs(invUser('manager'))->getJson(route('inventory.card', ['productId' => $this->gao->id]))
        ->assertOk()
        ->assertJsonPath('product.name', 'A Gạo')
        ->assertJsonPath('product.on_hand', 8)
        ->assertJsonPath('movements.total', 2)
        ->assertJsonPath('movements.data.0.qty', -2)
        ->assertJsonPath('movements.data.0.balance_after', 8)
        ->assertJsonPath('movements.data.0.reason_label', 'Bán hàng')
        ->assertJsonPath('movements.data.0.unit_cost', 20000)
        ->assertJsonPath('movements.data.1.qty', 10)
        ->assertJsonPath('movements.data.1.type_label', 'Nhập kho')
        ->assertJsonPath('movements.data.1.reason_label', 'Tồn đầu kỳ');
});

it('thẻ kho ẩn giá vốn với người không có quyền, 404 khi không có sản phẩm, 403 khi không có quyền xem', function () {
    $this->actingAs(invUser('cashier'))->getJson(route('inventory.card', ['productId' => $this->gao->id]))
        ->assertOk()
        ->assertJsonMissingPath('movements.data.0.unit_cost');

    $this->actingAs(invUser('manager'))->getJson(route('inventory.card', ['productId' => 999999]))->assertNotFound();
    $this->actingAs(invUser(null))->getJson(route('inventory.card', ['productId' => $this->gao->id]))->assertForbidden();
});

it('quản lý bật/tắt cho phép tồn âm; thủ kho và thu ngân không được', function () {
    expect(InventorySettings::allowNegative())->toBeFalse();

    $this->actingAs(invUser('manager'))->put(route('inventory.settings'), ['allow_negative' => true])->assertRedirect();
    expect(InventorySettings::allowNegative())->toBeTrue();

    $this->actingAs(invUser('manager'))->get(route('inventory.index'))->assertInertia(fn ($page) => $page->where('allowNegative', true));

    $this->actingAs(invUser('manager'))->put(route('inventory.settings'), ['allow_negative' => false])->assertRedirect();
    expect(InventorySettings::allowNegative())->toBeFalse();

    $this->actingAs(invUser('warehouse'))->put(route('inventory.settings'), ['allow_negative' => true])->assertForbidden();
    $this->actingAs(invUser('cashier'))->put(route('inventory.settings'), ['allow_negative' => true])->assertForbidden();
    expect(InventorySettings::allowNegative())->toBeFalse();
});

it('cài đặt kho kiểm tra dữ liệu gửi lên', function () {
    $this->actingAs(invUser('manager'))->put(route('inventory.settings'), [])->assertSessionHasErrors('allow_negative');
    $this->actingAs(invUser('manager'))->put(route('inventory.settings'), ['allow_negative' => 'có'])->assertSessionHasErrors('allow_negative');
});

it('menu Kho chỉ hiện cho người có quyền xem tồn kho', function () {
    $labels = fn (?string $role) => collect(app(ModuleRegistry::class)->navigation(invUser($role)))
        ->firstWhere('label', 'Kho');

    $group = $labels('cashier');
    expect($group)->not->toBeNull()
        ->and($group['items'][0]['route'])->toBe('inventory.index')
        ->and($labels(null))->toBeNull();
});
