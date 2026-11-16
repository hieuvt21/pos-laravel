<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\DocumentNumber;
use App\Modules\Core\Support\UniqueName;
use App\Modules\Inventory\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Nhà cung cấp: danh sách nhỏ để chọn khi nhập kho. Quản lý bằng quyền `inventory.receive`. */
class SupplierController extends Controller
{
    public function index(): Response
    {
        $suppliers = Supplier::query()
            ->withCount('documents')
            ->withMax('documents', 'doc_date')
            ->orderBy('name')
            ->get()
            ->map(fn (Supplier $s) => [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'phone' => $s->phone,
                'address' => $s->address,
                'note' => $s->note,
                'is_active' => $s->is_active,
                'documents_count' => $s->documents_count,
                'last_doc_date' => $s->documents_max_doc_date ? date('d/m/Y', strtotime($s->documents_max_doc_date)) : null,
            ]);

        return Inertia::render('Inventory/Suppliers/Index', ['suppliers' => $suppliers]);
    }

    /** Kiểm tra trùng tên ngay khi nhập (cùng định dạng với catalog.check để dùng chung useUniqueCheck). */
    public function check(Request $request): JsonResponse
    {
        $name = trim((string) $request->input('value'));
        if ($name === '') {
            return response()->json(['valid' => true, 'available' => true]);
        }

        $taken = UniqueName::exists('suppliers', $name, $request->integer('ignore') ?: null);

        return response()->json(['valid' => true, 'available' => ! $taken, 'message' => $taken ? 'Tên nhà cung cấp này đã có.' : null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data) {
            Supplier::create($data + ['code' => DocumentNumber::next('supplier')]);
        });

        return back()->with('success', 'Đã thêm nhà cung cấp.');
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($this->validated($request, $supplier));

        return back()->with('success', 'Đã cập nhật nhà cung cấp.');
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        if ($supplier->documents()->exists()) {
            return back()->with('error', 'Đã có phiếu nhập kho từ nhà cung cấp này nên không xóa được. Hãy bỏ chọn "Đang sử dụng" để ẩn khỏi danh sách chọn.');
        }

        $supplier->delete();

        return back()->with('success', 'Đã xóa nhà cung cấp.');
    }

    private function validated(Request $request, ?Supplier $supplier = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150', fn ($attr, $value, $fail) => UniqueName::exists('suppliers', (string) $value, $supplier?->id) ? $fail('Tên nhà cung cấp này đã có.') : null],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9 +().-]+$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
            'is_active' => ['required', 'boolean'],
        ], [
            'name.required' => 'Vui lòng nhập tên nhà cung cấp.',
            'name.max' => 'Tên tối đa 150 ký tự.',
            'phone.max' => 'Số điện thoại tối đa 20 ký tự.',
            'phone.regex' => 'Số điện thoại chỉ gồm số, dấu cách và các ký tự + ( ) . -',
            'address.max' => 'Địa chỉ tối đa 255 ký tự.',
            'note.max' => 'Ghi chú tối đa 500 ký tự.',
            'is_active.required' => 'Vui lòng chọn trạng thái.',
            'is_active.boolean' => 'Trạng thái không hợp lệ.',
        ]);
    }
}
