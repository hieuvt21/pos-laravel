<?php

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Core\Support\ModuleRegistry;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Support\StockAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('pos:sync');
    StockAlerts::forget();
    $category = Category::create(['name' => 'Tạp hóa', 'is_active' => true]);
    $unit = Unit::where('is_default', true)->first();
    $make = fn (array $over) => Product::create(array_merge([
        'category_id' => $category->id, 'unit_id' => $unit->id, 'track_stock' => true, 'min_stock' => 5, 'cost_price' => 1000,
    ], $over));
    $this->stock = app(StockService::class);

    $this->ok = $make(['code' => 'AL1', 'name' => 'Còn nhiều']);
    $this->low = $make(['code' => 'AL2', 'name' => 'Sắp hết']);
    $this->out = $make(['code' => 'AL3', 'name' => 'Hết hàng']);
    $this->inactive = $make(['code' => 'AL4', 'name' => 'Ngừng bán', 'is_active' => false]);   // không tính cảnh báo
    $this->untracked = $make(['code' => 'AL5', 'name' => 'Không quản lý tồn', 'track_stock' => false]);

    $this->stock->receive($this->ok->id, 10, null, 'opening');
    $this->stock->receive($this->low->id, 3, null, 'opening');
    // $this->out: chưa nhập nên tồn 0
});

function alertUser(?string $role): User
{
    $user = User::factory()->create();
    if ($role) {
        $user->assignRole($role);
    }

    return $user;
}

function khoBadge(?string $role): ?int
{
    $group = collect(app(ModuleRegistry::class)->navigation(alertUser($role)))->firstWhere('label', 'Kho');

    return $group ? collect($group['items'])->firstWhere('route', 'inventory.index')['badge'] : null;
}

it('phân loại tình trạng: hết khi tồn ≤ 0, sắp hết khi tồn ≤ ngưỡng (ngưỡng > 0)', function () {
    expect(StockAlerts::status(0, 5))->toBe('out')
        ->and(StockAlerts::status(-2, 0))->toBe('out')
        ->and(StockAlerts::status(5, 5))->toBe('low')
        ->and(StockAlerts::status(5.001, 5))->toBe('ok')
        ->and(StockAlerts::status(3, 0))->toBe('ok');   // ngưỡng 0 = không cảnh báo
});

it('đếm sản phẩm sắp hết và hết hàng, bỏ qua sản phẩm ngừng bán và không quản lý tồn', function () {
    expect(StockAlerts::counts())->toBe(['low' => 1, 'out' => 1])
        ->and(StockAlerts::badge())->toBe(2);
});

it('mục Tồn kho trên menu có huy hiệu số sản phẩm cần chú ý', function () {
    expect(khoBadge('manager'))->toBe(2)
        ->and(khoBadge('cashier'))->toBe(2)
        ->and(khoBadge(null))->toBeNull();      // không có quyền xem kho thì không có nhóm Kho
});

it('huy hiệu bằng null khi không có sản phẩm nào cần chú ý', function () {
    $this->stock->receive($this->low->id, 10, null, 'purchase');
    $this->stock->receive($this->out->id, 10, null, 'purchase');

    expect(khoBadge('manager'))->toBeNull();
});

it('huy hiệu tự cập nhật ngay khi có nhập hoặc xuất kho', function () {
    expect(khoBadge('manager'))->toBe(2);

    $this->stock->receive($this->low->id, 10, null, 'purchase');            // Sắp hết -> còn hàng
    expect(khoBadge('manager'))->toBe(1);

    $this->stock->issue($this->ok->id, 8, 'sale');                          // 10 -> 2 (≤ 5): sắp hết
    expect(khoBadge('manager'))->toBe(2);

    $this->stock->issue($this->ok->id, 2, 'sale');                          // 2 -> 0: hết hàng
    expect(StockAlerts::counts())->toBe(['low' => 0, 'out' => 2])
        ->and(khoBadge('manager'))->toBe(2);
});

it('lỗi khi tính huy hiệu không làm hỏng menu', function () {
    $items = collect(app(ModuleRegistry::class)->navigation(alertUser('manager')))->flatMap(fn ($g) => $g['items']);

    expect($items->every(fn ($i) => array_key_exists('badge', $i)))->toBeTrue()
        ->and($items->where('route', '!=', 'inventory.index')->pluck('badge')->filter()->all())->toBe([]);   // các mục khác không có huy hiệu
});
