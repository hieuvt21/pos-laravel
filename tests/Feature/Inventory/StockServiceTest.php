<?php

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Core\Support\DocumentNumber;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\InvalidStockQuantityException;
use App\Modules\Inventory\Exceptions\StockException;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Support\InventorySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('pos:sync');
    $this->admin = User::where('username', 'admin')->first();
    $this->category = Category::create(['name' => 'Tạp hóa', 'is_active' => true]);
    $this->unitCai = Unit::where('is_default', true)->first();
    $this->unitKg = Unit::create(['name' => 'Kg', 'is_default' => false, 'is_active' => true, 'allow_decimal' => true]);
    $this->stock = app(StockService::class);
});

function newStockProduct(array $over = []): Product
{
    static $n = 0;
    $n++;

    return Product::create(array_merge([
        'code' => 'TK'.$n,
        'name' => 'Sản phẩm kho '.$n,
        'category_id' => test()->category->id,
        'unit_id' => test()->unitCai->id,
        'track_stock' => true,
        'cost_price' => 0,
    ], $over));
}

function costOf(Product $p): float
{
    return (float) $p->fresh()->cost_price;
}

it('nhập kho cộng tồn và ghi sổ kèm số dư sau mỗi dòng', function () {
    $p = newStockProduct();

    $m = $this->stock->receive($p->id, 10, 1000, 'purchase');
    $this->stock->receive($p->id, 5, 1000, 'purchase');

    expect($m->qty)->toBe(10.0)
        ->and($m->type)->toBe('receipt')
        ->and($m->unit_cost)->toBe(1000.0)
        ->and($this->stock->onHand($p->id))->toBe(15.0)
        ->and(StockMovement::where('product_id', $p->id)->orderBy('id')->pluck('balance_after')->map(fn ($v) => (float) $v)->all())->toEqual([10.0, 15.0]);
});

it('giá vốn bình quân gia quyền: nhập đổi giá vốn, xuất và bán thì không', function () {
    $p = newStockProduct();

    $this->stock->receive($p->id, 10, 1000, 'purchase');
    expect(costOf($p))->toBe(1000.0);

    $this->stock->receive($p->id, 10, 2000, 'purchase');
    expect(costOf($p))->toBe(1500.0);

    $this->stock->issue($p->id, 5, 'sale');
    expect(costOf($p))->toBe(1500.0);

    // tồn 15 giá 1500, nhập thêm 5 giá 3000 -> (15×1500 + 5×3000) / 20 = 1875
    $this->stock->receive($p->id, 5, 3000, 'purchase');
    expect(costOf($p))->toBe(1875.0);
});

it('nhập không kèm đơn giá thì giữ nguyên giá vốn', function () {
    $p = newStockProduct(['cost_price' => 800]);

    $m = $this->stock->receive($p->id, 3, null, 'other');

    expect(costOf($p))->toBe(800.0)->and($m->unit_cost)->toBe(800.0);
});

it('xuất kho trừ tồn và ghi theo giá vốn hiện tại', function () {
    $p = newStockProduct();
    $this->stock->receive($p->id, 10, 1200, 'purchase');

    $m = $this->stock->issue($p->id, 4, 'sale');

    expect($m->qty)->toBe(-4.0)
        ->and($m->type)->toBe('issue')
        ->and($m->unit_cost)->toBe(1200.0)
        ->and($m->balance_after)->toBe(6.0)
        ->and($this->stock->onHand($p->id))->toBe(6.0)
        ->and(costOf($p))->toBe(1200.0);
});

it('mặc định không cho xuất quá tồn và không ghi gì khi bị chặn', function () {
    $p = newStockProduct();
    $this->stock->receive($p->id, 2, 1000, 'purchase');

    expect(fn () => $this->stock->issue($p->id, 3, 'sale'))
        ->toThrow(InsufficientStockException::class, 'Không đủ tồn kho');

    expect($this->stock->onHand($p->id))->toBe(2.0)
        ->and(StockMovement::where('product_id', $p->id)->count())->toBe(1);
});

it('bật tồn âm thì cho bán quá tồn, lần nhập sau lấy giá nhập làm giá vốn', function () {
    InventorySettings::save(['allow_negative' => true]);
    $p = newStockProduct(['cost_price' => 500]);

    $m = $this->stock->issue($p->id, 3, 'sale');
    expect($m->balance_after)->toBe(-3.0);

    $this->stock->receive($p->id, 10, 2000, 'purchase');

    expect($this->stock->onHand($p->id))->toBe(7.0)->and(costOf($p))->toBe(2000.0);
});

it('sản phẩm không quản lý tồn kho thì không ghi sổ và không báo lỗi', function () {
    $p = newStockProduct(['track_stock' => false]);

    expect($this->stock->receive($p->id, 5, 1000, 'purchase'))->toBeNull()
        ->and($this->stock->issue($p->id, 1, 'sale'))->toBeNull()
        ->and($this->stock->returnToStock($p->id, 1, null, 'sale_return'))->toBeNull()
        ->and($this->stock->adjustToCount($p->id, 9, 'count'))->toBeNull()
        ->and(StockMovement::count())->toBe(0)
        ->and(StockLevel::count())->toBe(0);
});

it('số lẻ chỉ nhận khi đơn vị cho phép, tối đa 3 chữ số thập phân', function () {
    $cai = newStockProduct();
    $kg = newStockProduct(['unit_id' => $this->unitKg->id]);

    expect(fn () => $this->stock->receive($cai->id, 1.5, null, 'other'))
        ->toThrow(InvalidStockQuantityException::class, 'không cho phép số lượng lẻ');

    $this->stock->receive($kg->id, 0.25, 40000, 'purchase');
    $this->stock->issue($kg->id, 0.1, 'sale');
    expect($this->stock->onHand($kg->id))->toBe(0.15);

    expect(fn () => $this->stock->receive($kg->id, 0.0001, null, 'other'))->toThrow(InvalidStockQuantityException::class, '3 chữ số')
        ->and(fn () => $this->stock->receive($kg->id, 0, null, 'other'))->toThrow(InvalidStockQuantityException::class, 'lớn hơn 0')
        ->and(fn () => $this->stock->receive($kg->id, -1, null, 'other'))->toThrow(InvalidStockQuantityException::class)
        ->and(fn () => $this->stock->receive($kg->id, 'abc', null, 'other'))->toThrow(InvalidStockQuantityException::class, 'phải là số');
});

it('trả hàng nhập lại theo giá vốn gốc và không đổi giá vốn bình quân', function () {
    $p = newStockProduct();
    $this->stock->receive($p->id, 10, 1000, 'purchase');
    $sold = $this->stock->issue($p->id, 4, 'sale');          // giá vốn lúc bán: 1000
    $this->stock->receive($p->id, 10, 3000, 'purchase');     // giá vốn bình quân đổi: (6×1000 + 10×3000) / 16 = 2250
    expect(costOf($p))->toBe(2250.0);

    $m = $this->stock->returnToStock($p->id, 2, $sold->unit_cost, 'sale_return');

    expect($m->type)->toBe('return')
        ->and($m->qty)->toBe(2.0)
        ->and($m->unit_cost)->toBe(1000.0)
        ->and($this->stock->onHand($p->id))->toBe(18.0)
        ->and(costOf($p))->toBe(2250.0);
});

it('trả hàng vẫn nhập lại được kho với sản phẩm đã xóa mềm', function () {
    $p = newStockProduct();
    $this->stock->receive($p->id, 5, 1000, 'purchase');
    $this->stock->issue($p->id, 5, 'sale');
    $p->delete();

    $this->stock->returnToStock($p->id, 1, 1000, 'sale_return');

    expect($this->stock->onHand($p->id))->toBe(1.0);
});

it('kiểm kho đưa tồn về số đếm bằng dòng chênh lệch, không chênh lệch thì không ghi', function () {
    $p = newStockProduct();
    $this->stock->receive($p->id, 10, 1000, 'purchase');

    $m = $this->stock->adjustToCount($p->id, 7, 'count');

    expect($m->type)->toBe('count')
        ->and($m->qty)->toBe(-3.0)
        ->and($m->balance_after)->toBe(7.0)
        ->and($this->stock->adjustToCount($p->id, 7, 'count'))->toBeNull();

    $up = $this->stock->adjustToCount($p->id, 12, 'count');
    expect($up->qty)->toBe(5.0)->and($this->stock->onHand($p->id))->toBe(12.0);

    $zero = $this->stock->adjustToCount($p->id, 0, 'count');
    expect($zero->qty)->toBe(-12.0)->and($this->stock->onHand($p->id))->toBe(0.0)
        ->and(costOf($p))->toBe(1000.0);

    expect(fn () => $this->stock->adjustToCount($p->id, 1.5, 'count'))->toThrow(InvalidStockQuantityException::class)
        ->and(fn () => $this->stock->adjustToCount($p->id, -1, 'count'))->toThrow(InvalidStockQuantityException::class);
});

it('tồn hiện tại luôn bằng tổng các dòng sổ kho', function () {
    $p = newStockProduct();

    $this->stock->receive($p->id, 10, 1000, 'purchase');
    $this->stock->issue($p->id, 3, 'sale');
    $this->stock->returnToStock($p->id, 1, 1000, 'sale_return');
    $this->stock->adjustToCount($p->id, 5, 'count');
    $this->stock->receive($p->id, 2, null, 'other');

    expect((float) StockMovement::where('product_id', $p->id)->sum('qty'))->toBe($this->stock->onHand($p->id))
        ->and($this->stock->onHand($p->id))->toBe(7.0);
});

it('onHandMany trả tồn từng sản phẩm, chưa có phát sinh thì bằng 0', function () {
    $a = newStockProduct();
    $b = newStockProduct();
    $this->stock->receive($a->id, 4, 1000, 'purchase');

    expect($this->stock->onHandMany([$a->id, $b->id, 999999]))->toBe([$a->id => 4.0, $b->id => 0.0, 999999 => 0.0]);
});

it('lưu tham chiếu tới chứng từ gốc (hóa đơn, phiếu nhập...)', function () {
    $p = newStockProduct();

    $m = $this->stock->receive($p->id, 1, 1000, 'purchase', $this->admin);

    expect($m->reference_type)->toBe($this->admin->getMorphClass())
        ->and($m->reference_id)->toBe($this->admin->id)
        ->and($m->reference->is($this->admin))->toBeTrue();
});

it('báo lỗi tiếng Việt khi sản phẩm không tồn tại', function () {
    expect(fn () => $this->stock->receive(999999, 1, 1000, 'purchase'))->toThrow(StockException::class, 'Sản phẩm không tồn tại');
});

it('quyền kho: thủ kho đủ trừ cài đặt, thu ngân chỉ xem, quản lý đủ', function () {
    $cashier = Role::findByName('cashier');
    $warehouse = Role::findByName('warehouse');
    $manager = Role::findByName('manager');

    expect($cashier->hasPermissionTo('inventory.view'))->toBeTrue()
        ->and($cashier->hasPermissionTo('inventory.receive'))->toBeFalse()
        ->and($cashier->hasPermissionTo('inventory.cost'))->toBeFalse()
        ->and($warehouse->hasPermissionTo('inventory.count'))->toBeTrue()
        ->and($warehouse->hasPermissionTo('inventory.cost'))->toBeTrue()
        ->and($warehouse->hasPermissionTo('inventory.settings'))->toBeFalse()
        ->and($manager->hasPermissionTo('inventory.settings'))->toBeTrue();
});

it('cấp số phiếu nhập kho NK và xuất kho XK', function () {
    expect(DocumentNumber::next('stock_in'))->toStartWith('NK-')
        ->and(DocumentNumber::next('stock_out'))->toStartWith('XK-')
        ->and(DocumentNumber::next('stock_check'))->toStartWith('KK-');
});
