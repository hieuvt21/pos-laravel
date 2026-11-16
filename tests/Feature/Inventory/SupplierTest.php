<?php

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Core\Support\ModuleRegistry;
use App\Modules\Core\Support\Xlsx;
use App\Modules\Inventory\Models\StockDocument;
use App\Modules\Inventory\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('pos:sync');
    $category = Category::create(['name' => 'Tạp hóa', 'is_active' => true]);
    $unit = Unit::where('is_default', true)->first();
    $this->gao = Product::create(['code' => 'SPGAO', 'name' => 'A Gạo', 'category_id' => $category->id, 'unit_id' => $unit->id, 'track_stock' => true, 'cost_price' => 20000]);
    $this->user = spUser('warehouse');
});

function spUser(?string $role): User
{
    $user = User::factory()->create();
    if ($role) {
        $user->assignRole($role);
    }

    return $user;
}

function spSupplier(array $over = []): Supplier
{
    static $n = 0;
    $n++;

    return Supplier::create(array_merge(['code' => 'NCC-T'.$n, 'name' => 'Nhà cung cấp '.$n, 'is_active' => true], $over));
}

/** Lập phiếu nhập qua route (có hoặc không có nhà cung cấp). */
function spReceipt(?int $supplierId, string $reason = 'purchase')
{
    return test()->actingAs(test()->user)->post(route('inventory.receipts.store'), array_filter([
        'doc_date' => now()->toDateString(),
        'reason' => $reason,
        'supplier_id' => $supplierId,
        'lines' => [['product_id' => test()->gao->id, 'qty' => 5, 'unit_cost' => 21000]],
    ], fn ($v) => $v !== null));
}

it('chỉ người có quyền nhập kho mới quản lý nhà cung cấp', function () {
    $s = spSupplier();
    $cashier = spUser('cashier');

    $this->actingAs($cashier)->get(route('inventory.suppliers.index'))->assertForbidden();
    $this->actingAs($cashier)->getJson(route('inventory.suppliers.check', ['value' => 'x']))->assertForbidden();
    $this->actingAs($cashier)->post(route('inventory.suppliers.store'), ['name' => 'X', 'is_active' => true])->assertForbidden();
    $this->actingAs($cashier)->put(route('inventory.suppliers.update', $s), ['name' => 'Y', 'is_active' => true])->assertForbidden();
    $this->actingAs($cashier)->delete(route('inventory.suppliers.destroy', $s))->assertForbidden();
    $this->actingAs(spUser(null))->get(route('inventory.suppliers.index'))->assertForbidden();

    $this->actingAs($this->user)->get(route('inventory.suppliers.index'))->assertOk();
    expect(Supplier::count())->toBe(1);
});

it('thêm nhà cung cấp: tự cấp mã NCC, lưu thông tin', function () {
    $this->actingAs($this->user)->post(route('inventory.suppliers.store'), [
        'name' => 'Công ty Gạo Việt', 'phone' => '0901 234 567', 'address' => '12 Lê Lợi, Hà Nội', 'note' => 'Giao hàng sáng thứ 2', 'is_active' => true,
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post(route('inventory.suppliers.store'), ['name' => 'Đại lý Đường', 'is_active' => false])->assertSessionHasNoErrors();

    $first = Supplier::where('name', 'Công ty Gạo Việt')->first();
    $second = Supplier::where('name', 'Đại lý Đường')->first();

    expect($first->code)->toStartWith('NCC-')->toEndWith('0001')
        ->and($second->code)->toStartWith('NCC-')->toEndWith('0002')
        ->and($first->phone)->toBe('0901 234 567')
        ->and($first->address)->toBe('12 Lê Lợi, Hà Nội')
        ->and($first->note)->toBe('Giao hàng sáng thứ 2')
        ->and($first->is_active)->toBeTrue()
        ->and($second->is_active)->toBeFalse()
        ->and($second->phone)->toBeNull();
});

it('kiểm tra dữ liệu nhà cung cấp: tên bắt buộc, không trùng, số điện thoại, độ dài', function () {
    spSupplier(['name' => 'Công ty ABC']);
    $post = fn (array $over) => $this->actingAs($this->user)->post(route('inventory.suppliers.store'), array_merge(['name' => 'Mới', 'is_active' => true], $over));

    $post(['name' => ''])->assertSessionHasErrors('name');
    $post(['name' => 'công ty abc'])->assertSessionHasErrors('name');          // trùng, không phân biệt hoa thường
    $post(['name' => str_repeat('a', 151)])->assertSessionHasErrors('name');
    $post(['phone' => 'abc'])->assertSessionHasErrors('phone');
    $post(['phone' => str_repeat('1', 21)])->assertSessionHasErrors('phone');
    $post(['address' => str_repeat('a', 256)])->assertSessionHasErrors('address');
    $post(['note' => str_repeat('a', 501)])->assertSessionHasErrors('note');
    $post(['is_active' => null])->assertSessionHasErrors('is_active');
    $post(['phone' => '+84 (90) 123-4567'])->assertSessionHasNoErrors();         // định dạng quốc tế vẫn nhận

    expect(Supplier::count())->toBe(2);
});

it('sửa nhà cung cấp: giữ mã, cho giữ nguyên tên, không đặt trùng tên nhà cung cấp khác', function () {
    $a = spSupplier(['name' => 'Công ty A']);
    spSupplier(['name' => 'Công ty B']);

    $this->actingAs($this->user)->put(route('inventory.suppliers.update', $a), ['name' => 'Công ty A', 'phone' => '0912345678', 'is_active' => false])->assertSessionHasNoErrors();
    expect($a->fresh()->phone)->toBe('0912345678')->and($a->fresh()->is_active)->toBeFalse()->and($a->fresh()->code)->toBe($a->code);

    $this->actingAs($this->user)->put(route('inventory.suppliers.update', $a), ['name' => 'công ty b', 'is_active' => true])->assertSessionHasErrors('name');
    expect($a->fresh()->name)->toBe('Công ty A');
});

it('kiểm tra trùng tên khi đang nhập (có bỏ qua chính nó khi sửa)', function () {
    $a = spSupplier(['name' => 'Công ty A']);

    $this->actingAs($this->user)->getJson(route('inventory.suppliers.check', ['value' => 'CÔNG TY A']))->assertJson(['available' => false, 'message' => 'Tên nhà cung cấp này đã có.']);
    $this->actingAs($this->user)->getJson(route('inventory.suppliers.check', ['value' => 'Công ty A', 'ignore' => $a->id]))->assertJson(['available' => true]);
    $this->actingAs($this->user)->getJson(route('inventory.suppliers.check', ['value' => 'Công ty Z']))->assertJson(['available' => true]);
    $this->actingAs($this->user)->getJson(route('inventory.suppliers.check', ['value' => '']))->assertJson(['available' => true]);
});

it('danh sách nhà cung cấp theo tên, kèm số phiếu nhập và ngày nhập gần nhất', function () {
    $b = spSupplier(['name' => 'B Công ty']);
    $a = spSupplier(['name' => 'A Công ty']);
    spReceipt($a->id);
    spReceipt($a->id);
    spReceipt($b->id);

    $this->actingAs($this->user)->get(route('inventory.suppliers.index'))
        ->assertInertia(fn ($page) => $page
            ->has('suppliers', 2)
            ->where('suppliers.0.name', 'A Công ty')
            ->where('suppliers.0.documents_count', 2)
            ->where('suppliers.0.last_doc_date', now()->format('d/m/Y'))
            ->where('suppliers.1.documents_count', 1));
});

it('xóa được nhà cung cấp chưa có phiếu nhập; đã có phiếu thì không xóa', function () {
    $unused = spSupplier();
    $used = spSupplier();
    spReceipt($used->id);

    $this->actingAs($this->user)->delete(route('inventory.suppliers.destroy', $unused))->assertSessionHas('success');
    $this->actingAs($this->user)->delete(route('inventory.suppliers.destroy', $used))->assertSessionHas('error');

    expect(Supplier::find($unused->id))->toBeNull()->and(Supplier::find($used->id))->not->toBeNull();
});

it('phiếu nhập ghi nhà cung cấp; hiện ở danh sách, chi tiết phiếu và thẻ kho', function () {
    $s = spSupplier(['name' => 'Công ty Gạo Việt', 'phone' => '0901234567']);

    spReceipt($s->id)->assertSessionHasNoErrors();
    $doc = StockDocument::first();
    expect($doc->supplier_id)->toBe($s->id);

    $this->actingAs($this->user)->get(route('inventory.documents.index'))
        ->assertInertia(fn ($page) => $page->where('documents.data.0.supplier', 'Công ty Gạo Việt'));

    $this->actingAs($this->user)->get(route('inventory.documents.show', $doc))
        ->assertInertia(fn ($page) => $page->where('document.supplier.name', 'Công ty Gạo Việt')->where('document.supplier.phone', '0901234567'));

    $this->actingAs($this->user)->getJson(route('inventory.card', ['productId' => $this->gao->id]))
        ->assertJsonPath('movements.data.0.supplier', 'Công ty Gạo Việt');
});

it('phiếu nhập không chọn nhà cung cấp vẫn được', function () {
    spReceipt(null)->assertSessionHasNoErrors();

    expect(StockDocument::first()->supplier_id)->toBeNull();
    $this->actingAs($this->user)->get(route('inventory.documents.show', StockDocument::first()))->assertInertia(fn ($page) => $page->where('document.supplier', null));
});

it('từ chối nhà cung cấp không tồn tại hoặc đã ngừng sử dụng', function () {
    $inactive = spSupplier(['is_active' => false]);

    spReceipt(999999)->assertSessionHasErrors('supplier_id');
    spReceipt($inactive->id)->assertSessionHasErrors('supplier_id');

    expect(StockDocument::count())->toBe(0);
});

it('phiếu xuất không có nhà cung cấp: giá trị gửi lên bị bỏ qua', function () {
    $s = spSupplier();

    $this->actingAs($this->user)->post(route('inventory.issues.store'), [
        'doc_date' => now()->toDateString(), 'reason' => 'damaged', 'supplier_id' => $s->id,
        'lines' => [['product_id' => $this->gao->id, 'qty' => 1]],
    ]);
    spReceipt(null);   // có tồn để xuất
    $this->actingAs($this->user)->post(route('inventory.issues.store'), [
        'doc_date' => now()->toDateString(), 'reason' => 'damaged', 'supplier_id' => $s->id,
        'lines' => [['product_id' => $this->gao->id, 'qty' => 1]],
    ])->assertSessionHasNoErrors();

    expect(StockDocument::where('type', 'issue')->first()->supplier_id)->toBeNull();
});

it('form nhập chỉ liệt kê nhà cung cấp đang sử dụng; form xuất không có danh sách', function () {
    spSupplier(['name' => 'B đang dùng']);
    spSupplier(['name' => 'A đang dùng']);
    spSupplier(['name' => 'Z ngừng', 'is_active' => false]);

    $this->actingAs($this->user)->get(route('inventory.receipts.create'))
        ->assertInertia(fn ($page) => $page->has('suppliers', 2)->where('suppliers.0.name', 'A đang dùng'));
    $this->actingAs($this->user)->get(route('inventory.issues.create'))->assertInertia(fn ($page) => $page->has('suppliers', 0));
});

it('lọc danh sách phiếu theo nhà cung cấp; bộ lọc có cả nhà cung cấp đã ngừng', function () {
    $a = spSupplier(['name' => 'A Công ty']);
    $b = spSupplier(['name' => 'B Công ty']);
    spReceipt($a->id);
    spReceipt($b->id);
    spReceipt(null);
    $b->update(['is_active' => false]);   // ngừng sử dụng sau khi đã có phiếu: bộ lọc vẫn phải liệt kê

    $this->actingAs($this->user)->get(route('inventory.documents.index', ['supplier' => $a->id]))
        ->assertInertia(fn ($page) => $page->has('documents.data', 1)->where('documents.data.0.supplier', 'A Công ty')->where('filters.supplier', $a->id)->has('suppliers', 2));

    $this->actingAs($this->user)->get(route('inventory.documents.index', ['supplier' => 'abc']))
        ->assertInertia(fn ($page) => $page->has('documents.data', 3)->where('filters.supplier', null));
});

it('nhập Excel ghi nhà cung cấp lên phiếu nhập', function () {
    $s = spSupplier(['name' => 'Công ty Gạo Việt']);
    $path = tempnam(sys_get_temp_dir(), 'sp').'.xlsx';
    Xlsx::write($path, [['Mã sản phẩm hoặc mã vạch', 'Số lượng'], ['SPGAO', '8']], [24, 12]);
    $file = new UploadedFile($path, 'ton.xlsx', null, null, true);

    $this->actingAs($this->user)->post(route('inventory.import.store'), ['file' => $file, 'reason' => 'purchase', 'supplier_id' => $s->id])->assertSessionHasNoErrors();

    expect(StockDocument::first()->supplier_id)->toBe($s->id);

    $bad = new UploadedFile($path, 'ton.xlsx', null, null, true);
    $this->actingAs($this->user)->post(route('inventory.import.store'), ['file' => $bad, 'reason' => 'purchase', 'supplier_id' => 999999])->assertSessionHasErrors('supplier_id');
    expect(StockDocument::count())->toBe(1);

    $this->actingAs($this->user)->get(route('inventory.import.index'))->assertInertia(fn ($page) => $page->has('suppliers', 1));
});

it('menu Kho có mục Nhà cung cấp cho người được nhập kho', function () {
    $routes = fn (?string $role) => collect(collect(app(ModuleRegistry::class)->navigation(spUser($role)))->firstWhere('label', 'Kho')['items'])->pluck('route')->all();

    expect($routes('warehouse'))->toContain('inventory.suppliers.index')
        ->and($routes('cashier'))->not->toContain('inventory.suppliers.index');
});
