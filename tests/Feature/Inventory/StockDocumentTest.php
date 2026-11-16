<?php

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Core\Support\ModuleRegistry;
use App\Modules\Inventory\Models\StockDocument;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Support\InventorySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('pos:sync');
    $this->category = Category::create(['name' => 'Tạp hóa', 'is_active' => true]);
    $this->unitCai = Unit::where('is_default', true)->first();
    $this->unitKg = Unit::create(['name' => 'Kg', 'is_default' => false, 'is_active' => true, 'allow_decimal' => true]);
    $this->stock = app(StockService::class);

    $this->gao = docProduct(['name' => 'A Gạo', 'cost_price' => 20000]);
    $this->duong = docProduct(['name' => 'B Đường', 'cost_price' => 10000]);
    $this->rau = docProduct(['name' => 'C Rau', 'unit_id' => $this->unitKg->id, 'cost_price' => 12000]);
    $this->muoi = docProduct(['name' => 'D Muối', 'track_stock' => false]);

    $this->stock->receive($this->gao->id, 10, null, 'opening');
    $this->stock->receive($this->duong->id, 3, null, 'opening');
});

function docProduct(array $over = []): Product
{
    static $n = 0;
    $n++;

    return Product::create(array_merge([
        'code' => 'PK'.$n,
        'name' => 'Sản phẩm '.$n,
        'category_id' => test()->category->id,
        'unit_id' => test()->unitCai->id,
        'track_stock' => true,
        'cost_price' => 0,
    ], $over));
}

function docUser(?string $role): User
{
    $user = User::factory()->create();
    if ($role) {
        $user->assignRole($role);
    }

    return $user;
}

function docPayload(array $over = []): array
{
    return array_merge([
        'doc_date' => now()->toDateString(),
        'reason' => 'purchase',
        'note' => 'Nhập chợ sáng',
        'lines' => [['product_id' => test()->gao->id, 'qty' => 5, 'unit_cost' => 30000]],
    ], $over);
}

it('chỉ người có quyền nhập/xuất mới mở và lập được phiếu', function () {
    $cashier = docUser('cashier');
    $this->actingAs($cashier)->get(route('inventory.receipts.create'))->assertForbidden();
    $this->actingAs($cashier)->get(route('inventory.issues.create'))->assertForbidden();
    $this->actingAs($cashier)->post(route('inventory.receipts.store'), docPayload())->assertForbidden();
    $this->actingAs($cashier)->post(route('inventory.issues.store'), docPayload(['reason' => 'damaged']))->assertForbidden();

    $warehouse = docUser('warehouse');
    $this->actingAs($warehouse)->get(route('inventory.receipts.create'))->assertOk();
    $this->actingAs($warehouse)->get(route('inventory.issues.create'))->assertOk();

    expect(StockDocument::count())->toBe(0);
});

it('lập phiếu nhập: cấp số NK, ghi sổ kho, cộng tồn và cập nhật giá vốn bình quân', function () {
    $this->actingAs(docUser('warehouse'))->post(route('inventory.receipts.store'), docPayload())->assertSessionHasNoErrors();

    $doc = StockDocument::with('lines')->first();
    expect($doc->code)->toStartWith('NK-')
        ->and($doc->type)->toBe('receipt')
        ->and($doc->status)->toBe('confirmed')
        ->and($doc->reason)->toBe('purchase')
        ->and($doc->note)->toBe('Nhập chợ sáng')
        ->and($doc->lines)->toHaveCount(1)
        ->and($doc->lines[0]->qty)->toBe(5.0)
        ->and($doc->lines[0]->unit_cost)->toBe(30000.0);

    // tồn 10 giá 20.000, nhập 5 giá 30.000 -> (10×20.000 + 5×30.000) / 15
    expect($this->stock->onHand($this->gao->id))->toBe(15.0)
        ->and((float) $this->gao->fresh()->cost_price)->toBe(23333.33);

    $movement = StockMovement::where('stock_document_id', $doc->id)->first();
    expect($movement->type)->toBe('receipt')->and($movement->qty)->toBe(5.0)->and($movement->balance_after)->toBe(15.0);
});

it('sau khi lập phiếu thì chuyển tới trang chi tiết phiếu', function () {
    $response = $this->actingAs(docUser('warehouse'))->post(route('inventory.receipts.store'), docPayload());

    $response->assertRedirect(route('inventory.documents.show', StockDocument::first()));
});

it('phiếu nhập không có đơn giá thì giữ nguyên giá vốn', function () {
    $this->actingAs(docUser('warehouse'))->post(route('inventory.receipts.store'), docPayload([
        'lines' => [['product_id' => $this->gao->id, 'qty' => 2, 'unit_cost' => null]],
    ]))->assertSessionHasNoErrors();

    expect((float) $this->gao->fresh()->cost_price)->toBe(20000.0)
        ->and($this->stock->onHand($this->gao->id))->toBe(12.0)
        ->and(StockDocument::first()->lines[0]->unit_cost)->toBeNull();
});

it('lập phiếu xuất: cấp số XK, trừ tồn và lưu giá vốn tại lúc xuất', function () {
    $this->actingAs(docUser('warehouse'))->post(route('inventory.issues.store'), docPayload([
        'reason' => 'damaged',
        'lines' => [['product_id' => $this->gao->id, 'qty' => 4]],
    ]))->assertSessionHasNoErrors();

    $doc = StockDocument::with('lines')->first();
    expect($doc->code)->toStartWith('XK-')
        ->and($doc->type)->toBe('issue')
        ->and($doc->lines[0]->unit_cost)->toBe(20000.0)
        ->and($this->stock->onHand($this->gao->id))->toBe(6.0)
        ->and((float) $this->gao->fresh()->cost_price)->toBe(20000.0);

    expect(StockMovement::where('stock_document_id', $doc->id)->first()->reason)->toBe('damaged');
});

it('phiếu nhiều dòng: số lẻ theo đơn vị, ghi cả phiếu', function () {
    $this->actingAs(docUser('warehouse'))->post(route('inventory.receipts.store'), docPayload([
        'lines' => [
            ['product_id' => $this->rau->id, 'qty' => 2.5, 'unit_cost' => 12000],
            ['product_id' => $this->duong->id, 'qty' => 7, 'unit_cost' => 10000],
        ],
    ]))->assertSessionHasNoErrors();

    expect(StockDocument::first()->lines)->toHaveCount(2)
        ->and($this->stock->onHand($this->rau->id))->toBe(2.5)
        ->and($this->stock->onHand($this->duong->id))->toBe(10.0);
});

it('xuất quá tồn bị chặn và không ghi gì: kể cả dòng hợp lệ trước đó và số phiếu', function () {
    $user = docUser('warehouse');
    $this->actingAs($user)->post(route('inventory.issues.store'), docPayload([
        'reason' => 'damaged',
        'lines' => [
            ['product_id' => $this->gao->id, 'qty' => 1],
            ['product_id' => $this->duong->id, 'qty' => 99],
        ],
    ]))->assertSessionHasErrors('lines');

    expect(session('errors')->first('lines'))->toContain('B Đường')->toContain('Không đủ tồn kho')
        ->and(StockDocument::count())->toBe(0)
        ->and($this->stock->onHand($this->gao->id))->toBe(10.0)
        ->and($this->stock->onHand($this->duong->id))->toBe(3.0)
        ->and(StockMovement::count())->toBe(2); // chỉ 2 dòng tồn đầu kỳ ở beforeEach

    // số phiếu không bị "ăn" khi lập lỗi: phiếu đầu tiên vẫn là số 0001
    $this->actingAs($user)->post(route('inventory.issues.store'), docPayload(['reason' => 'damaged', 'lines' => [['product_id' => $this->gao->id, 'qty' => 1]]]));
    expect(StockDocument::first()->code)->toEndWith('0001');
});

it('bật cho phép tồn âm thì xuất quá tồn được', function () {
    InventorySettings::save(['allow_negative' => true]);

    $this->actingAs(docUser('warehouse'))->post(route('inventory.issues.store'), docPayload([
        'reason' => 'internal',
        'lines' => [['product_id' => $this->duong->id, 'qty' => 5]],
    ]))->assertSessionHasNoErrors();

    expect($this->stock->onHand($this->duong->id))->toBe(-2.0);
});

it('kiểm tra dữ liệu phiếu: ngày, lý do, dòng, số lượng, đơn giá, trùng sản phẩm', function () {
    $user = docUser('warehouse');
    $post = fn (array $over, string $route = 'inventory.receipts.store') => $this->actingAs($user)->post(route($route), docPayload($over));

    $post(['lines' => []])->assertSessionHasErrors('lines');
    $post(['doc_date' => now()->addDay()->toDateString()])->assertSessionHasErrors('doc_date');
    $post(['doc_date' => 'hôm qua'])->assertSessionHasErrors('doc_date');
    $post(['reason' => 'damaged'])->assertSessionHasErrors('reason');                            // lý do của phiếu xuất, không dùng cho phiếu nhập
    $post(['reason' => 'purchase'], 'inventory.issues.store')->assertSessionHasErrors('reason');   // và ngược lại
    $post(['lines' => [['product_id' => $this->gao->id, 'qty' => 0]]])->assertSessionHasErrors('lines.0.qty');
    $post(['lines' => [['product_id' => $this->gao->id, 'qty' => 1.0001]]])->assertSessionHasErrors('lines.0.qty');
    $post(['lines' => [['product_id' => $this->gao->id, 'qty' => 1, 'unit_cost' => -5]]])->assertSessionHasErrors('lines.0.unit_cost');
    $post(['lines' => [['product_id' => $this->gao->id, 'qty' => 1], ['product_id' => $this->gao->id, 'qty' => 2]]])->assertSessionHasErrors('lines.1.product_id');
    $post(['note' => str_repeat('a', 501)])->assertSessionHasErrors('note');

    expect(StockDocument::count())->toBe(0);
});

it('báo lỗi kèm tên sản phẩm: số lẻ với đơn vị không cho phép, sản phẩm không quản lý tồn, sản phẩm không tồn tại', function () {
    $user = docUser('warehouse');

    $this->actingAs($user)->post(route('inventory.receipts.store'), docPayload(['lines' => [['product_id' => $this->gao->id, 'qty' => 1.5]]]))
        ->assertSessionHasErrors('lines');
    expect(session('errors')->first('lines'))->toContain('A Gạo')->toContain('không cho phép số lượng lẻ');

    $this->actingAs($user)->post(route('inventory.receipts.store'), docPayload(['lines' => [['product_id' => $this->muoi->id, 'qty' => 1]]]))
        ->assertSessionHasErrors('lines');
    expect(session('errors')->first('lines'))->toContain('D Muối')->toContain('chưa bật quản lý tồn kho');

    $this->actingAs($user)->post(route('inventory.receipts.store'), docPayload(['lines' => [['product_id' => 999999, 'qty' => 1]]]))
        ->assertSessionHasErrors('lines');

    expect(StockDocument::count())->toBe(0);
});

it('danh sách phiếu: lọc theo loại, mã, ngày; thu ngân xem được', function () {
    $user = docUser('warehouse');
    $this->actingAs($user)->post(route('inventory.receipts.store'), docPayload());
    $this->actingAs($user)->post(route('inventory.issues.store'), docPayload(['reason' => 'damaged', 'lines' => [['product_id' => $this->gao->id, 'qty' => 1]]]));
    $cashier = docUser('cashier');

    $this->actingAs($cashier)->get(route('inventory.documents.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('documents.data', 2)->where('documents.data.0.type', 'issue')->where('documents.data.0.lines_count', 1));

    $this->actingAs($cashier)->get(route('inventory.documents.index', ['type' => 'receipt']))
        ->assertInertia(fn ($page) => $page->has('documents.data', 1)->where('documents.data.0.type_label', 'Phiếu nhập kho'));

    $this->actingAs($cashier)->get(route('inventory.documents.index', ['search' => 'XK-']))
        ->assertInertia(fn ($page) => $page->has('documents.data', 1)->where('documents.data.0.type', 'issue'));

    $this->actingAs($cashier)->get(route('inventory.documents.index', ['from' => now()->addDay()->toDateString()]))
        ->assertInertia(fn ($page) => $page->has('documents.data', 0));

    $this->actingAs($cashier)->get(route('inventory.documents.index', ['type' => 'bậy', 'from' => 'abc']))
        ->assertInertia(fn ($page) => $page->has('documents.data', 2)->where('filters.type', null)->where('filters.from', null));

    $this->actingAs(docUser(null))->get(route('inventory.documents.index'))->assertForbidden();
});

it('chi tiết phiếu: người có quyền xem giá vốn thấy đơn giá và thành tiền, thu ngân không thấy', function () {
    $this->actingAs(docUser('warehouse'))->post(route('inventory.receipts.store'), docPayload());
    $doc = StockDocument::first();

    $this->actingAs(docUser('manager'))->get(route('inventory.documents.show', $doc))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('document.code', $doc->code)
            ->where('document.reason_label', 'Nhập hàng')
            ->where('canCost', true)
            ->where('lines.0.name', 'A Gạo')
            ->where('lines.0.qty', 5)
            ->where('lines.0.unit_cost', 30000)
            ->where('lines.0.line_total', 150000)
            ->where('total_value', 150000));

    $this->actingAs(docUser('cashier'))->get(route('inventory.documents.show', $doc))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canCost', false)->where('total_value', null)->missing('lines.0.unit_cost')->missing('lines.0.line_total'));

    $this->actingAs(docUser('manager'))->get(route('inventory.documents.show', 999999))->assertNotFound();
});

it('tìm sản phẩm khi lập phiếu: chỉ sản phẩm quản lý tồn, khớp chính xác mã vạch lên đầu, giá vốn theo quyền', function () {
    $this->gao->update(['barcode' => '8930001']);
    $this->duong->update(['barcode' => '89300019']); // chứa chuỗi '8930001' nhưng không khớp chính xác

    $user = docUser('warehouse');
    $this->actingAs($user)->getJson(route('inventory.products.search', ['q' => '8930001']))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'A Gạo')
        ->assertJsonPath('data.0.on_hand', 10)
        ->assertJsonPath('data.0.unit', 'Cái')
        ->assertJsonPath('data.0.allow_decimal', false)
        ->assertJsonPath('data.0.cost_price', 20000);

    $this->actingAs($user)->getJson(route('inventory.products.search', ['q' => 'Muối']))->assertJsonCount(0, 'data');   // không quản lý tồn
    $this->actingAs($user)->getJson(route('inventory.products.search', ['q' => '']))->assertJsonCount(0, 'data');
    $this->actingAs($user)->getJson(route('inventory.products.search', ['q' => 'Rau']))->assertJsonPath('data.0.allow_decimal', true);

    $this->actingAs(docUser('cashier'))->getJson(route('inventory.products.search', ['q' => 'Gạo']))
        ->assertOk()
        ->assertJsonMissingPath('data.0.cost_price');

    $this->actingAs(docUser(null))->getJson(route('inventory.products.search', ['q' => 'Gạo']))->assertForbidden();
});

it('menu Kho: thủ kho có Nhập/Xuất/Kiểm kho, thu ngân chỉ Tồn kho và Phiếu kho', function () {
    $routes = fn (?string $role) => collect(collect(app(ModuleRegistry::class)->navigation(docUser($role)))->firstWhere('label', 'Kho')['items'])->pluck('route')->all();

    expect($routes('warehouse'))->toBe(['inventory.index', 'inventory.documents.index', 'inventory.receipts.create', 'inventory.issues.create', 'inventory.counts.create', 'inventory.suppliers.index'])
        ->and($routes('cashier'))->toBe(['inventory.index', 'inventory.documents.index']);
});

it('thứ tự nhóm menu: Khách hàng, Sản phẩm, Kho', function () {
    $labels = collect(app(ModuleRegistry::class)->navigation(docUser('manager')))->pluck('label')->all();

    $customers = array_search('Khách hàng', $labels, true);
    $products = array_search('Sản phẩm', $labels, true);
    $inventory = array_search('Kho', $labels, true);

    expect($customers)->not->toBeFalse()
        ->and($customers)->toBeLessThan($products)
        ->and($products)->toBeLessThan($inventory);
});
