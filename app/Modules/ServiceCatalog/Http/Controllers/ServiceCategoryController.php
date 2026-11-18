<?php

namespace App\Modules\ServiceCatalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\UniqueName;
use App\Modules\ServiceCatalog\Models\ServiceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Danh mục dịch vụ (riêng, không dùng chung danh mục sản phẩm). */
class ServiceCategoryController extends Controller
{
    public function index(): Response
    {
        $categories = ServiceCategory::query()
            ->withCount('services')
            ->orderBy('name')
            ->get()
            ->map(fn (ServiceCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'description' => $c->description,
                'is_active' => $c->is_active,
                'services_count' => $c->services_count,
            ]);

        return Inertia::render('Services/Categories', ['categories' => $categories]);
    }

    /** Kiểm tra trùng tên ngay khi nhập (cùng định dạng với các endpoint check khác để dùng chung useUniqueCheck). */
    public function check(Request $request): JsonResponse
    {
        $name = trim((string) $request->input('value'));
        if ($name === '') {
            return response()->json(['valid' => true, 'available' => true]);
        }

        $taken = UniqueName::exists('service_categories', $name, $request->integer('ignore') ?: null);

        return response()->json(['valid' => true, 'available' => ! $taken, 'message' => $taken ? 'Tên danh mục này đã có.' : null]);
    }

    public function store(Request $request): RedirectResponse
    {
        ServiceCategory::create($this->validated($request));

        return back()->with('success', 'Đã thêm danh mục dịch vụ.');
    }

    public function update(Request $request, ServiceCategory $category): RedirectResponse
    {
        $category->update($this->validated($request, $category));

        return back()->with('success', 'Đã cập nhật danh mục dịch vụ.');
    }

    public function destroy(ServiceCategory $category): RedirectResponse
    {
        // Tính cả dịch vụ đã xóa mềm vì khóa ngoại vẫn trỏ tới danh mục
        if ($category->services()->withTrashed()->exists()) {
            return back()->with('error', 'Danh mục này đang có dịch vụ nên không xóa được. Hãy chuyển dịch vụ sang danh mục khác hoặc bỏ chọn "Đang sử dụng".');
        }

        // Combo / liệu trình dùng chung danh mục dịch vụ
        if ($category->packages()->withTrashed()->exists()) {
            return back()->with('error', 'Danh mục này đang có combo hoặc liệu trình nên không xóa được. Hãy chuyển gói sang danh mục khác hoặc bỏ chọn "Đang sử dụng".');
        }

        $category->delete();

        return back()->with('success', 'Đã xóa danh mục dịch vụ.');
    }

    private function validated(Request $request, ?ServiceCategory $category = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150', fn ($attr, $value, $fail) => UniqueName::exists('service_categories', (string) $value, $category?->id) ? $fail('Tên danh mục này đã có.') : null],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ], [
            'name.required' => 'Vui lòng nhập tên danh mục.',
            'name.max' => 'Tên tối đa 150 ký tự.',
            'description.max' => 'Mô tả tối đa 255 ký tự.',
            'is_active.required' => 'Vui lòng chọn trạng thái.',
            'is_active.boolean' => 'Trạng thái không hợp lệ.',
        ]);
    }
}
