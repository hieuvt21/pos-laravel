<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Services\CatalogService;
use App\Modules\Core\Support\Xlsx;
use App\Modules\Inventory\Exceptions\StockException;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\StockDocumentService;
use App\Modules\Inventory\Support\StockLabels;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Nhập nhiều sản phẩm vào kho từ Excel (.xlsx) hoặc .csv, thường dùng cho tồn đầu kỳ.
 * Cả file là một phiếu nhập: có dòng lỗi thì không nhập gì, báo rõ từng dòng để sửa file.
 */
class StockImportController extends Controller
{
    private const MAX_ROWS = 2000;

    private const MAX_ERRORS = 100;

    /** Tên cột nhận diện được (đã bỏ dấu, viết thường). */
    private const HEADERS = [
        'code' => ['ma san pham', 'ma', 'ma vach', 'ma san pham hoac ma vach', 'sku', 'barcode', 'code'],
        'qty' => ['so luong', 'sl', 'qty', 'ton', 'ton dau', 'ton dau ky', 'so luong ton'],
        'cost' => ['don gia nhap', 'don gia nhap khong bat buoc', 'gia nhap', 'don gia', 'gia von', 'cost'],
    ];

    public function __construct(
        private readonly CatalogService $catalog,
        private readonly StockDocumentService $documents,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Inventory/Import', [
            'result' => session('import_result'),
            'maxRows' => self::MAX_ROWS,
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn (Supplier $s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'reasons' => collect(StockLabels::RECEIPT_REASONS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }

    public function template(): BinaryFileResponse
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tk');
        Xlsx::write($tmp, [['Mã sản phẩm hoặc mã vạch', 'Số lượng', 'Đơn giá nhập (không bắt buộc)']], [30, 14, 28]);

        return response()
            ->download($tmp, 'mau-nhap-ton-kho.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
            ->deleteFileAfterSend(true);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120'],
            'reason' => ['required', Rule::in(array_keys(StockLabels::RECEIPT_REASONS))],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('is_active', true)],
        ], [
            'supplier_id.exists' => 'Nhà cung cấp không hợp lệ hoặc đã ngừng sử dụng.',
            'file.required' => 'Hãy chọn file cần nhập.',
            'file.max' => 'File tối đa 5 MB.',
            'reason.required' => 'Vui lòng chọn lý do.',
            'reason.in' => 'Lý do không hợp lệ.',
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['xlsx', 'csv'], true)) {
            return back()->withErrors(['file' => 'Chỉ nhận file .xlsx hoặc .csv.']);
        }

        try {
            $rows = $ext === 'xlsx' ? Xlsx::read($file->getRealPath()) : $this->readCsv($file->getRealPath());
        } catch (Throwable) {
            return back()->withErrors(['file' => 'Không đọc được file. Hãy dùng file mẫu.']);
        }

        $rows = array_values(array_filter($rows, fn ($r) => array_filter($r['cells'], fn ($v) => $v !== '') !== []));
        $head = array_shift($rows);
        $map = $head ? $this->mapColumns($head['cells']) : [];

        if (! isset($map['code']) || ! isset($map['qty'])) {
            return back()->withErrors(['file' => 'Không thấy cột "Mã sản phẩm" và "Số lượng" ở dòng tiêu đề. Hãy dùng file mẫu.']);
        }
        if ($rows === []) {
            return back()->withErrors(['file' => 'File không có dòng dữ liệu nào.']);
        }
        if (count($rows) > self::MAX_ROWS) {
            return back()->withErrors(['file' => 'Mỗi lần nhập tối đa '.self::MAX_ROWS.' dòng.']);
        }

        $cell = fn (array $row, string $key) => isset($map[$key]) ? trim((string) ($row['cells'][$map[$key]] ?? '')) : '';
        $products = $this->catalog->lookupByCodes(array_map(fn ($r) => $cell($r, 'code'), $rows));

        $lines = [];
        $errors = [];
        $seen = [];

        foreach ($rows as $row) {
            $key = $cell($row, 'code');
            $product = $products[mb_strtolower($key)] ?? null;
            $fail = function (string $message) use (&$errors, $row) {
                $errors[] = ['row' => $row['row'], 'message' => $message];
            };

            if ($key === '') {
                $fail('Thiếu mã sản phẩm.');

                continue;
            }
            if (! $product) {
                $fail("Không tìm thấy sản phẩm có mã hoặc mã vạch \"{$key}\".");

                continue;
            }
            if (! $product['track_stock']) {
                $fail("{$product['name']}: chưa bật quản lý tồn kho.");

                continue;
            }
            if (isset($seen[$product['id']])) {
                $fail("{$product['name']}: trùng với dòng {$seen[$product['id']]}.");

                continue;
            }

            $qty = $this->parseNumber($cell($row, 'qty'));
            if ($qty === null || $qty <= 0 || $qty > 999999999) {
                $fail("{$product['name']}: số lượng \"{$cell($row, 'qty')}\" không hợp lệ (phải là số lớn hơn 0).");

                continue;
            }
            if (abs($qty - round($qty, 3)) > 1e-9) {
                $fail("{$product['name']}: số lượng tối đa 3 chữ số thập phân.");

                continue;
            }
            if (! $product['allow_decimal'] && floor($qty) != $qty) {
                $fail("{$product['name']}: đơn vị \"{$product['unit']}\" không cho phép số lượng lẻ.");

                continue;
            }

            $cost = null;
            if ($cell($row, 'cost') !== '') {
                $cost = $this->parseNumber($cell($row, 'cost'));
                if ($cost === null || $cost < 0 || $cost > 999999999 || abs($cost - round($cost, 2)) > 1e-9) {
                    $fail("{$product['name']}: đơn giá \"{$cell($row, 'cost')}\" không hợp lệ.");

                    continue;
                }
            }

            $seen[$product['id']] = $row['row'];
            $lines[] = ['product_id' => $product['id'], 'qty' => $qty, 'unit_cost' => $cost];
        }

        if ($errors !== []) {
            return redirect()->route('inventory.import.index')
                ->with('import_result', ['created' => 0, 'errors' => array_slice($errors, 0, self::MAX_ERRORS), 'more_errors' => max(0, count($errors) - self::MAX_ERRORS)])
                ->with('error', 'Không nhập gì cả vì file có '.count($errors).' dòng lỗi. Sửa file rồi nhập lại.');
        }

        try {
            $document = $this->documents->create('receipt', [
                'doc_date' => now()->toDateString(),
                'reason' => $request->input('reason'),
                'supplier_id' => $request->input('supplier_id') ?: null,
                'note' => Str::limit('Nhập từ file '.$file->getClientOriginalName(), 500, ''),
                'lines' => $lines,
            ]);
        } catch (StockException $e) {
            return redirect()->route('inventory.import.index')
                ->with('import_result', ['created' => 0, 'errors' => [['row' => 0, 'message' => $e->getMessage()]], 'more_errors' => 0])
                ->with('error', 'Không nhập được: '.$e->getMessage());
        }

        return redirect()->route('inventory.documents.show', $document)
            ->with('success', 'Đã nhập '.count($lines)." sản phẩm vào kho (phiếu {$document->code}).");
    }

    /** @return array<string, int> */
    private function mapColumns(array $cells): array
    {
        $map = [];
        foreach ($cells as $i => $title) {
            $key = trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii((string) $title))));
            foreach (self::HEADERS as $field => $aliases) {
                if (! isset($map[$field]) && in_array($key, $aliases, true)) {
                    $map[$field] = $i;
                }
            }
        }

        return $map;
    }

    private function readCsv(string $path): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($path));
        $first = strtok($text, "\n") ?: '';
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $text);
        rewind($stream);

        $rows = [];
        $n = 0;
        while (($cells = fgetcsv($stream, 0, $delimiter)) !== false) {
            $n++;
            $rows[] = ['row' => $n, 'cells' => array_map(fn ($v) => trim((string) $v), $cells)];
        }
        fclose($stream);

        return $rows;
    }

    /** Số kiểu Việt hoặc kiểu máy tính: "1.500", "1.500,5", "1,5", "1500.5". Không hiểu thì null. */
    private function parseNumber(string $value): ?float
    {
        $value = str_replace([' ', "\u{00A0}"], '', trim($value));
        if ($value === '' || ! preg_match('/^-?[\d.,]+$/', $value)) {
            return null;
        }

        if (str_contains($value, '.') && str_contains($value, ',')) {
            $value = strrpos($value, ',') > strrpos($value, '.')
                ? str_replace(',', '.', str_replace('.', '', $value))   // 1.500,5
                : str_replace(',', '', $value);                         // 1,500.5
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);                     // 1,5
        } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $value)) {
            $value = str_replace('.', '', $value);                      // 1.500 = một nghìn năm trăm
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
