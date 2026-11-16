<?php

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Core\Support\ModuleRegistry;
use App\Modules\Inventory\Models\StockDocument;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('pos:sync');
    $this->catFood = Category::create(['name' => 'Tạp hóa', 'is_active' => true]);
    $this->catVeg = Category::create(['name' => 'Rau củ', 'is_active' => true]);
    $this->unitCai = Unit::where('is_default', true)->first();
    $this->unitKg = Unit::create(['name' => 'Kg', 'is_default' => false, 'is_active' => true, 'allow_decimal' => true]);
    $this->stock = app(StockService::class);
    $this->user = cntUser('warehouse');

    $this->gao = cntProduct(['name' => 'A Gạo', 'category_id' => $this->catFood->id, 'cost_price' => 20000]);
    $this->duong = cntProduct(['name' => 'B Đường', 'category_id' => $this->catFood->id, 'cost_price' => 10000]);
    $this->rau = cntProduct(['name' => 'C Rau', 'category_id' => $this->catVeg->id, 'unit_id' => $this->unitKg->id, 'cost_price' => 12000]);
    $this->muoi = cntProduct(['name' => 'D Muối', 'track_stock' => false]);

    $this->stock->receive($this->gao->id, 10, null, 'opening');
    $this->stock->receive($this->duong->id, 3, null, 'opening');
    $this->stock->receive($this->rau->id, 2.5, null, 'opening');
});

function cntProduct(array $over = []): Product
{
    static $n = 0;
    $n++;

    return Product::create(array_merge([
        'code' => 'CK'.$n,
        'name' => 'Sản phẩm '.$n,
        'category_id' => test()->catFood->id,
        'unit_id' => test()->unitCai->id,
        'track_stock' => true,
        'cost_price' => 0,
    ], $over));
}

function cntUser(?string $role): User
{
    $user = User::factory()->create();
    if ($role) {
        $user->assignRole($role);
    }

    return $user;
}

/** Tạo phiếu nháp qua route, trả về StockDocument. */
function cntDraft(array $over = []): StockDocument
{
    test()->actingAs(test()->user)->post(route('inventory.counts.store'), array_merge(['doc_date' => now()->toDateString(), 'note' => 'Kiểm cuối tháng'], $over))->assertSessionHasNoErrors();

    return StockDocument::latest('id')->first();
}

/** Số đếm theo [product_id => qty]; null = chưa đếm. */
function cntLines(array $counts): array
{
    return collect($counts)->map(fn ($qty, $id) => ['product_id' => $id, 'qty' => $qty])->values()->all();
}

it('chỉ người có quyền kiểm kho mới vào được', function () {
    $this->actingAs(cntUser('cashier'))->get(route('inventory.counts.create'))->assertForbidden();
    $this->actingAs(cntUser(null))->post(route('inventory.counts.store'), ['doc_date' => now()->toDateString()])->assertForbidden();
    $this->actingAs($this->user)->get(route('inventory.counts.create'))->assertOk();

    expect(StockDocument::count())->toBe(0);
});

it('tạo phiếu nháp: nạp mọi sản phẩm quản lý tồn kèm tồn máy, chưa đếm gì', function () {
    $response = $this->actingAs($this->user)->post(route('inventory.counts.store'), ['doc_date' => now()->toDateString(), 'note' => 'Cuối tháng']);

    $doc = StockDocument::with('lines')->first();
    $response->assertRedirect(route('inventory.counts.edit', $doc));

    expect($doc->code)->toStartWith('KK-')
        ->and($doc->type)->toBe('count')
        ->and($doc->status)->toBe('draft')
        ->and($doc->note)->toBe('Cuối tháng')
        ->and($doc->lines)->toHaveCount(3)                       // không có Muối (không quản lý tồn)
        ->and($doc->lines->pluck('qty')->filter()->count())->toBe(0)
        ->and($doc->lines->firstWhere('product_id', $this->gao->id)->system_qty)->toBe(10.0)
        ->and($doc->lines->firstWhere('product_id', $this->rau->id)->system_qty)->toBe(2.5)
        ->and(StockMovement::where('stock_document_id', $doc->id)->count())->toBe(0);   // nháp chưa ghi sổ
});

it('phạm vi theo danh mục; phạm vi không có sản phẩm nào thì báo lỗi', function () {
    $doc = cntDraft(['category_id' => $this->catVeg->id]);
    expect($doc->lines)->toHaveCount(1)->and($doc->lines[0]->product_id)->toBe($this->rau->id);

    $empty = Category::create(['name' => 'Trống', 'is_active' => true]);
    $before = StockDocument::count();
    $this->actingAs($this->user)->post(route('inventory.counts.store'), ['doc_date' => now()->toDateString(), 'category_id' => $empty->id])
        ->assertSessionHasErrors('category_id');
    expect(StockDocument::count())->toBe($before);

    $this->actingAs($this->user)->post(route('inventory.counts.store'), ['doc_date' => now()->addDay()->toDateString()])->assertSessionHasErrors('doc_date');
});

it('trang nhập số đếm hiện danh sách theo tên, kèm tồn máy', function () {
    $doc = cntDraft();

    $this->actingAs($this->user)->get(route('inventory.counts.edit', $doc))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('document.code', $doc->code)
            ->has('lines', 3)
            ->where('lines.0.name', 'A Gạo')
            ->where('lines.0.system_qty', 10)
            ->where('lines.0.qty', null)
            ->where('lines.2.allow_decimal', true)
            ->where('changed', null));
});

it('lưu nháp: ghi số đếm, để trống là chưa đếm, thêm được sản phẩm ngoài danh sách', function () {
    $doc = cntDraft(['category_id' => $this->catVeg->id]);   // chỉ có Rau

    $this->actingAs($this->user)->put(route('inventory.counts.update', $doc), [
        'doc_date' => now()->toDateString(),
        'note' => 'Đã sửa ghi chú',
        'lines' => cntLines([$this->rau->id => 2, $this->gao->id => null]),   // Gạo là dòng mới thêm
    ])->assertSessionHasNoErrors();

    $doc->refresh()->load('lines');
    expect($doc->note)->toBe('Đã sửa ghi chú')
        ->and($doc->lines)->toHaveCount(2)
        ->and($doc->lines->firstWhere('product_id', $this->rau->id)->qty)->toBe(2.0)
        ->and($doc->lines->firstWhere('product_id', $this->gao->id)->qty)->toBeNull()
        ->and($doc->lines->firstWhere('product_id', $this->gao->id)->system_qty)->toBe(10.0)
        ->and($doc->status)->toBe('draft');

    // số đếm có thể sửa lại và xóa
    $this->actingAs($this->user)->put(route('inventory.counts.update', $doc), [
        'lines' => cntLines([$this->rau->id => null, $this->gao->id => 9]),
    ])->assertSessionHasNoErrors();
    $doc->refresh()->load('lines');
    expect($doc->lines->firstWhere('product_id', $this->rau->id)->qty)->toBeNull()
        ->and($doc->lines->firstWhere('product_id', $this->gao->id)->qty)->toBe(9.0);
});

it('lưu nháp kiểm tra dữ liệu: số âm, quá 3 chữ số thập phân, sản phẩm không quản lý tồn, trùng dòng', function () {
    $doc = cntDraft();
    $put = fn (array $lines) => $this->actingAs($this->user)->put(route('inventory.counts.update', $doc), ['lines' => $lines]);

    $put([['product_id' => $this->gao->id, 'qty' => -1]])->assertSessionHasErrors('lines.0.qty');
    $put([['product_id' => $this->gao->id, 'qty' => 1.2345]])->assertSessionHasErrors('lines.0.qty');
    $put([['product_id' => $this->gao->id, 'qty' => 1], ['product_id' => $this->gao->id, 'qty' => 2]])->assertSessionHasErrors('lines.1.product_id');
    $put([])->assertSessionHasErrors('lines');

    $put([['product_id' => $this->muoi->id, 'qty' => 1]])->assertSessionHasErrors('lines');
    expect(session('errors')->first('lines'))->toContain('D Muối')->toContain('chưa bật quản lý tồn kho');
});

it('xác nhận: ghi chênh lệch của dòng đã đếm, bỏ qua dòng chưa đếm, không đổi giá vốn', function () {
    $doc = cntDraft();

    $this->actingAs($this->user)->post(route('inventory.counts.confirm', $doc), [
        'lines' => cntLines([$this->gao->id => 7, $this->duong->id => null, $this->rau->id => 2.5]),
    ])->assertSessionHasNoErrors()->assertRedirect(route('inventory.documents.show', $doc));

    $doc->refresh()->load('lines');
    expect($doc->status)->toBe('confirmed')
        ->and($doc->confirmed_at)->not->toBeNull()
        ->and($this->stock->onHand($this->gao->id))->toBe(7.0)
        ->and($this->stock->onHand($this->duong->id))->toBe(3.0)      // chưa đếm: giữ nguyên
        ->and($this->stock->onHand($this->rau->id))->toBe(2.5)        // khớp: không ghi
        ->and((float) $this->gao->fresh()->cost_price)->toBe(20000.0);

    $movements = StockMovement::where('stock_document_id', $doc->id)->get();
    expect($movements)->toHaveCount(1)                                // chỉ Gạo có chênh lệch
        ->and($movements[0]->type)->toBe('count')
        ->and($movements[0]->qty)->toBe(-3.0)
        ->and($movements[0]->balance_after)->toBe(7.0);

    $gao = $doc->lines->firstWhere('product_id', $this->gao->id);
    $rau = $doc->lines->firstWhere('product_id', $this->rau->id);
    $duong = $doc->lines->firstWhere('product_id', $this->duong->id);
    expect([$gao->system_qty, $gao->diff_qty])->toBe([10.0, -3.0])
        ->and([$rau->system_qty, $rau->diff_qty])->toBe([2.5, 0.0])
        ->and($duong->qty)->toBeNull()->and($duong->diff_qty)->toBeNull();
});

it('xác nhận cần ít nhất một sản phẩm đã đếm', function () {
    $doc = cntDraft();

    $this->actingAs($this->user)->post(route('inventory.counts.confirm', $doc), ['lines' => cntLines([$this->gao->id => null])])
        ->assertSessionHasErrors('lines');

    expect($doc->fresh()->status)->toBe('draft');
});

it('báo lỗi kèm tên sản phẩm khi số đếm sai đơn vị, không ghi gì kể cả dòng hợp lệ đứng trước, nhưng giữ số đã nhập', function () {
    $doc = cntDraft();

    // Gạo (id nhỏ hơn) hợp lệ và được xử lý trước; Đường đếm 1,5 cái nên bị chặn -> hoàn tác cả phiếu
    $this->actingAs($this->user)->post(route('inventory.counts.confirm', $doc), [
        'lines' => cntLines([$this->gao->id => 9, $this->duong->id => 1.5]),
    ])->assertSessionHasErrors('lines');

    expect(session('errors')->first('lines'))->toContain('B Đường')->toContain('không cho phép số lượng lẻ')
        ->and($doc->fresh()->status)->toBe('draft')
        ->and($this->stock->onHand($this->gao->id))->toBe(10.0)
        ->and(StockMovement::where('stock_document_id', $doc->id)->count())->toBe(0)
        ->and($doc->lines()->where('product_id', $this->duong->id)->first()->qty)->toBe(1.5)   // số đã nhập vẫn được lưu nháp
        ->and($doc->lines()->where('product_id', $this->gao->id)->first()->qty)->toBe(9.0);
});

it('tồn đã đổi từ lúc lưu nháp thì báo xem lại; đồng ý thì tính theo tồn hiện tại', function () {
    $doc = cntDraft();                                                // Gạo: tồn máy lúc nháp = 10
    $this->stock->issue($this->gao->id, 2, 'sale');                   // có người bán 2 -> tồn thật trong máy = 8
    $payload = ['lines' => cntLines([$this->gao->id => 7])];

    $this->actingAs($this->user)->post(route('inventory.counts.confirm', $doc), $payload)
        ->assertSessionHasErrors('changed')
        ->assertSessionHas('changed_lines');

    $changes = session('changed_lines');
    expect($changes)->toHaveCount(1)
        ->and($changes[0]['name'])->toBe('A Gạo')
        ->and($changes[0]['system_qty'])->toEqual(10.0)
        ->and($changes[0]['current_qty'])->toEqual(8.0)
        ->and($changes[0]['counted_qty'])->toEqual(7.0)
        ->and($doc->fresh()->status)->toBe('draft')
        ->and($this->stock->onHand($this->gao->id))->toBe(8.0)
        ->and($doc->lines()->where('product_id', $this->gao->id)->first()->qty)->toBe(7.0);   // số đếm đã được lưu

    // trang nhập số đếm nhận danh sách thay đổi để hiện cho người dùng
    $this->actingAs($this->user)->get(route('inventory.counts.edit', $doc))->assertInertia(fn ($page) => $page->has('changed', 1));

    $this->actingAs($this->user)->post(route('inventory.counts.confirm', $doc), $payload + ['acknowledge' => true])->assertSessionHasNoErrors();

    $line = $doc->refresh()->lines()->where('product_id', $this->gao->id)->first();
    expect($doc->status)->toBe('confirmed')
        ->and($this->stock->onHand($this->gao->id))->toBe(7.0)
        ->and([$line->system_qty, $line->diff_qty])->toBe([8.0, -1.0]);                       // lệch so với tồn hiện tại, không phải tồn lúc nháp
});

it('phiếu đã xác nhận không sửa, không xóa, không xác nhận lại', function () {
    $doc = cntDraft();
    $this->actingAs($this->user)->post(route('inventory.counts.confirm', $doc), ['lines' => cntLines([$this->gao->id => 7])]);
    $lines = ['lines' => cntLines([$this->gao->id => 1])];

    $this->actingAs($this->user)->put(route('inventory.counts.update', $doc), $lines)->assertSessionHasErrors('lines');
    expect(session('errors')->first('lines'))->toContain('đã được xác nhận');
    $this->actingAs($this->user)->post(route('inventory.counts.confirm', $doc), $lines)->assertSessionHasErrors('lines');
    $this->actingAs($this->user)->delete(route('inventory.counts.destroy', $doc))->assertSessionHas('error');

    expect(StockDocument::find($doc->id))->not->toBeNull()
        ->and($this->stock->onHand($this->gao->id))->toBe(7.0)
        ->and(StockMovement::where('stock_document_id', $doc->id)->count())->toBe(1);

    // mở lại trang nhập số đếm thì chuyển sang trang xem phiếu
    $this->actingAs($this->user)->get(route('inventory.counts.edit', $doc))->assertRedirect(route('inventory.documents.show', $doc));
});

it('xóa phiếu nháp', function () {
    $doc = cntDraft();
    $id = $doc->id;

    $this->actingAs($this->user)->delete(route('inventory.counts.destroy', $doc))->assertRedirect(route('inventory.counts.create'));

    expect(StockDocument::find($id))->toBeNull()
        ->and(DB::table('stock_document_lines')->where('stock_document_id', $id)->count())->toBe(0);
});

it('trang tạo phiếu liệt kê phiếu nháp để làm tiếp, kèm tiến độ đã đếm', function () {
    $doc = cntDraft();
    $this->actingAs($this->user)->put(route('inventory.counts.update', $doc), ['lines' => cntLines([$this->gao->id => 9])]);

    $this->actingAs($this->user)->get(route('inventory.counts.create'))
        ->assertInertia(fn ($page) => $page->has('drafts', 1)->where('drafts.0.code', $doc->code)->where('drafts.0.lines_count', 3)->where('drafts.0.counted_count', 1));
});

it('phiếu nháp mở thẳng trang kiểm kho với người có quyền; thu ngân xem được ở dạng nháp', function () {
    $doc = cntDraft();

    $this->actingAs($this->user)->get(route('inventory.documents.show', $doc))->assertRedirect(route('inventory.counts.edit', $doc));
    $this->actingAs(cntUser('cashier'))->get(route('inventory.documents.show', $doc))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('document.status', 'draft'));
});

it('chi tiết phiếu kiểm kho đã xác nhận có tồn máy, số đếm, chênh lệch', function () {
    $doc = cntDraft();
    $this->actingAs($this->user)->post(route('inventory.counts.confirm', $doc), ['lines' => cntLines([$this->gao->id => 7])]);

    $this->actingAs(cntUser('cashier'))->get(route('inventory.documents.show', $doc))
        ->assertInertia(fn ($page) => $page
            ->where('document.type', 'count')
            ->where('document.status', 'confirmed')
            ->where('lines.0.name', 'A Gạo')
            ->where('lines.0.system_qty', 10)
            ->where('lines.0.qty', 7)
            ->where('lines.0.diff_qty', -3)
            ->where('lines.1.qty', null));
});

it('route kiểm kho chỉ áp dụng cho phiếu kiểm kho', function () {
    $this->actingAs($this->user)->post(route('inventory.receipts.store'), [
        'doc_date' => now()->toDateString(), 'reason' => 'purchase', 'lines' => [['product_id' => $this->gao->id, 'qty' => 1]],
    ]);
    $receipt = StockDocument::where('type', 'receipt')->first();

    $this->actingAs($this->user)->get(route('inventory.counts.edit', $receipt))->assertNotFound();
    $this->actingAs($this->user)->delete(route('inventory.counts.destroy', $receipt))->assertNotFound();
});

it('menu Kho có mục Kiểm kho cho người có quyền kiểm kho', function () {
    $routes = fn (?string $role) => collect(collect(app(ModuleRegistry::class)->navigation(cntUser($role)))->firstWhere('label', 'Kho')['items'])->pluck('route')->all();

    expect($routes('warehouse'))->toContain('inventory.counts.create')
        ->and($routes('cashier'))->not->toContain('inventory.counts.create');
});
