<?php

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Core\Support\Xlsx;
use App\Modules\Inventory\Models\StockDocument;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('pos:sync');
    $this->category = Category::create(['name' => 'Tạp hóa', 'is_active' => true]);
    $this->unitCai = Unit::where('is_default', true)->first();
    $this->unitKg = Unit::create(['name' => 'Kg', 'is_default' => false, 'is_active' => true, 'allow_decimal' => true]);
    $this->stock = app(StockService::class);
    $this->user = impUser('warehouse');

    $this->gao = impProduct(['code' => 'IMPGAO', 'name' => 'A Gạo', 'barcode' => '8930001', 'cost_price' => 20000]);
    $this->duong = impProduct(['code' => 'IMPDUONG', 'name' => 'B Đường', 'cost_price' => 10000]);
    $this->rau = impProduct(['code' => 'IMPRAU', 'name' => 'C Rau', 'unit_id' => $this->unitKg->id, 'cost_price' => 12000]);
    $this->muoi = impProduct(['code' => 'IMPMUOI', 'name' => 'D Muối', 'track_stock' => false]);
});

function impProduct(array $over = []): Product
{
    return Product::create(array_merge([
        'category_id' => test()->category->id,
        'unit_id' => test()->unitCai->id,
        'track_stock' => true,
        'cost_price' => 0,
    ], $over));
}

function impUser(?string $role): User
{
    $user = User::factory()->create();
    if ($role) {
        $user->assignRole($role);
    }

    return $user;
}

/** Tạo file .xlsx thật từ các dòng (dòng đầu là tiêu đề). */
function impXlsx(array $rows): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
    Xlsx::write($path, $rows, [24, 12, 16]);

    return new UploadedFile($path, 'ton-dau-ky.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

function impPost(array $rows, string $reason = 'opening')
{
    return test()->actingAs(test()->user)->post(route('inventory.import.store'), ['file' => impXlsx($rows), 'reason' => $reason]);
}

const IMP_HEAD = ['Mã sản phẩm hoặc mã vạch', 'Số lượng', 'Đơn giá nhập (không bắt buộc)'];

it('chỉ người có quyền nhập kho mới mở và nhập được', function () {
    $cashier = impUser('cashier');
    $this->actingAs($cashier)->get(route('inventory.import.index'))->assertForbidden();
    $this->actingAs($cashier)->get(route('inventory.import.template'))->assertForbidden();
    $this->actingAs($cashier)->post(route('inventory.import.store'), ['file' => impXlsx([IMP_HEAD, ['IMPGAO', '5']]), 'reason' => 'opening'])->assertForbidden();

    $this->actingAs($this->user)->get(route('inventory.import.index'))->assertOk();
    expect(StockDocument::count())->toBe(0);
});

it('tải được file mẫu', function () {
    $response = $this->actingAs($this->user)->get(route('inventory.import.template'));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('mau-nhap-ton-kho.xlsx');
});

it('nhập thành công: một phiếu nhập NK, tra theo mã sản phẩm hoặc mã vạch, cập nhật tồn và giá vốn', function () {
    $response = impPost([
        IMP_HEAD,
        ['IMPGAO', '10', '25000'],     // theo mã sản phẩm, có đơn giá
        ['impduong', '3', ''],         // không phân biệt hoa thường, không có đơn giá
        ['IMPRAU', '2,5', ''],         // 2,5 kg
    ]);

    $doc = StockDocument::with('lines')->first();
    $response->assertRedirect(route('inventory.documents.show', $doc));

    expect($doc->code)->toStartWith('NK-')
        ->and($doc->type)->toBe('receipt')
        ->and($doc->reason)->toBe('opening')
        ->and($doc->note)->toContain('ton-dau-ky.xlsx')
        ->and($doc->lines)->toHaveCount(3)
        ->and($this->stock->onHand($this->gao->id))->toBe(10.0)
        ->and($this->stock->onHand($this->duong->id))->toBe(3.0)
        ->and($this->stock->onHand($this->rau->id))->toBe(2.5)
        ->and((float) $this->gao->fresh()->cost_price)->toBe(25000.0)      // tồn cũ 0 nên lấy giá nhập
        ->and((float) $this->duong->fresh()->cost_price)->toBe(10000.0)    // không có đơn giá: giữ giá vốn
        ->and(StockMovement::where('stock_document_id', $doc->id)->count())->toBe(3);
});

it('nhận mã vạch, số kiểu Việt (1.500 và 12.000,5) và lý do chọn', function () {
    impPost([IMP_HEAD, ['8930001', '1.500', '12.000,5']], 'purchase')->assertSessionHasNoErrors();

    $doc = StockDocument::with('lines')->first();
    expect($doc->reason)->toBe('purchase')
        ->and($doc->lines[0]->product_id)->toBe($this->gao->id)
        ->and($doc->lines[0]->qty)->toBe(1500.0)
        ->and($doc->lines[0]->unit_cost)->toBe(12000.5);
});

it('có dòng lỗi thì không nhập gì và báo rõ từng dòng', function () {
    $this->actingAs($this->user)->post(route('inventory.import.store'), ['file' => impXlsx([
        IMP_HEAD,
        ['IMPGAO', '5', ''],             // dòng 2: hợp lệ
        ['KHONGCO', '1', ''],            // dòng 3: không tìm thấy
        ['IMPMUOI', '1', ''],            // dòng 4: không quản lý tồn kho
        ['IMPDUONG', '0', ''],           // dòng 5: số lượng 0
        ['IMPDUONG', '1,5', ''],         // dòng 6: số lẻ với đơn vị Cái
        ['IMPGAO', '2', ''],             // dòng 7: trùng dòng 2
        ['IMPRAU', '1', '-5'],           // dòng 8: đơn giá âm
        ['', '1', ''],                   // dòng 9: thiếu mã
    ]), 'reason' => 'opening'])->assertRedirect(route('inventory.import.index'))->assertSessionHas('error');

    $result = session('import_result');
    $byRow = collect($result['errors'])->pluck('message', 'row');

    expect($result['created'])->toBe(0)
        ->and($result['errors'])->toHaveCount(7)
        ->and($byRow[3])->toContain('KHONGCO')
        ->and($byRow[4])->toContain('D Muối')->toContain('chưa bật quản lý tồn kho')
        ->and($byRow[5])->toContain('B Đường')->toContain('không hợp lệ')
        ->and($byRow[6])->toContain('không cho phép số lượng lẻ')
        ->and($byRow[7])->toContain('trùng với dòng 2')
        ->and($byRow[8])->toContain('đơn giá')
        ->and($byRow[9])->toContain('Thiếu mã')
        ->and(StockDocument::count())->toBe(0)
        ->and($this->stock->onHand($this->gao->id))->toBe(0.0);          // dòng hợp lệ cũng không được nhập

    // trang nhập nhận kết quả lỗi để hiện bảng
    $this->actingAs($this->user)->get(route('inventory.import.index'))->assertInertia(fn ($page) => $page->has('result.errors', 7)->where('result.errors.0.row', 3));
});

it('đọc được file CSV có BOM và dấu chấm phẩy', function () {
    $csv = UploadedFile::fake()->createWithContent('ton.csv', "\xEF\xBB\xBFMã sản phẩm;Số lượng;Đơn giá nhập\nIMPGAO;4;20000\nIMPRAU;1,25;\n");

    $this->actingAs($this->user)->post(route('inventory.import.store'), ['file' => $csv, 'reason' => 'opening'])->assertSessionHasNoErrors();

    expect($this->stock->onHand($this->gao->id))->toBe(4.0)->and($this->stock->onHand($this->rau->id))->toBe(1.25);
});

it('từ chối file thiếu cột, không có dữ liệu, sai định dạng hoặc lý do không hợp lệ', function () {
    impPost([['Tên', 'Giá'], ['Gạo', '1']])->assertSessionHasErrors('file');
    impPost([IMP_HEAD])->assertSessionHasErrors('file');
    impPost([IMP_HEAD, ['IMPGAO', '1']], 'damaged')->assertSessionHasErrors('reason');

    $this->actingAs($this->user)->post(route('inventory.import.store'), ['file' => UploadedFile::fake()->create('ton.pdf', 10), 'reason' => 'opening'])->assertSessionHasErrors('file');
    $this->actingAs($this->user)->post(route('inventory.import.store'), ['reason' => 'opening'])->assertSessionHasErrors('file');

    expect(StockDocument::count())->toBe(0);
});
