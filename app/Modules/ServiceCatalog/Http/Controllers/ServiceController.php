<?php

namespace App\Modules\ServiceCatalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\DocumentNumber;
use App\Modules\Core\Support\UniqueName;
use App\Modules\ServiceCatalog\Models\Service;
use App\Modules\ServiceCatalog\Models\ServiceCategory;
use App\Modules\ServiceCatalog\Support\Commission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $category = is_numeric($request->query('category')) ? (int) $request->query('category') : null;
        $status = in_array($request->query('status'), ['active', 'inactive'], true) ? $request->query('status') : null;
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';

        $services = Service::query()
            ->with('category:id,name')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('code', 'like', $like)))
            ->when($category, fn ($q) => $q->where('service_category_id', $category))
            ->when($status, fn ($q) => $q->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->paginate(UniqueName::perPage($request->query('per_page')))
            ->withQueryString()
            ->through(fn (Service $s) => [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'service_category_id' => $s->service_category_id,
                'category' => $s->category?->name,
                'price' => $s->price,
                'commission_type' => $s->commission_type,
                'commission_value' => $s->commission_value,
                'commission' => $s->commission, // tự tính, chỉ để hiển thị
                'note' => $s->note,
                'is_active' => $s->is_active,
            ]);

        return Inertia::render('Services/Index', [
            'services' => $services,
            'filters' => ['search' => $search, 'category' => $category, 'status' => $status],
            'categories' => ServiceCategory::orderBy('name')->get(['id', 'name', 'is_active'])
                ->map(fn (ServiceCategory $c) => ['id' => $c->id, 'name' => $c->name, 'is_active' => $c->is_active])->values(),
        ]);
    }

    /** Kiểm tra trùng tên ngay khi nhập (bỏ qua dịch vụ đã xóa). */
    public function check(Request $request): JsonResponse
    {
        $name = trim((string) $request->input('value'));
        if ($name === '') {
            return response()->json(['valid' => true, 'available' => true]);
        }

        $taken = UniqueName::exists('services', $name, $request->integer('ignore') ?: null, softDeletes: true);

        return response()->json(['valid' => true, 'available' => ! $taken, 'message' => $taken ? 'Tên dịch vụ này đã có.' : null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data) {
            Service::create($data + ['code' => DocumentNumber::next('service')]);
        });

        return back()->with('success', 'Đã thêm dịch vụ.');
    }

    public function update(Request $request, Service $service): RedirectResponse
    {
        $service->update($this->validated($request, $service));

        return back()->with('success', 'Đã cập nhật dịch vụ.');
    }

    public function destroy(Service $service): RedirectResponse
    {
        $service->delete(); // xóa mềm: hóa đơn cũ vẫn xem lại được

        return back()->with('success', 'Đã xóa dịch vụ.');
    }

    private function validated(Request $request, ?Service $service = null): array
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:150', fn ($attr, $value, $fail) => UniqueName::exists('services', (string) $value, $service?->id, softDeletes: true) ? $fail('Tên dịch vụ này đã có.') : null],
            // Bắt buộc chọn danh mục đang sử dụng (dịch vụ đang sửa được giữ danh mục hiện tại dù đã ngừng sử dụng)
            'service_category_id' => ['bail', 'required', 'integer', function ($attr, $value, $fail) use ($service) {
                $category = ServiceCategory::find($value);
                if (! $category || (! $category->is_active && $category->id !== $service?->service_category_id)) {
                    $fail('Danh mục không hợp lệ hoặc đã ngừng sử dụng.');
                }
            }],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'commission_type' => ['required', Rule::in(Commission::TYPES)],
            'commission_value' => ['required', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'note' => ['nullable', 'string', 'max:500'],
            'is_active' => ['required', 'boolean'],
        ], [
            'name.required' => 'Vui lòng nhập tên dịch vụ.',
            'name.max' => 'Tên tối đa 150 ký tự.',
            'service_category_id.required' => 'Vui lòng chọn danh mục dịch vụ.',
            'service_category_id.integer' => 'Danh mục không hợp lệ.',
            'price.required' => 'Vui lòng nhập giá dịch vụ.',
            'price.numeric' => 'Giá phải là số.',
            'price.min' => 'Giá không được âm.',
            'price.max' => 'Giá quá lớn.',
            'price.decimal' => 'Giá tối đa 2 chữ số thập phân.',
            'commission_type.required' => 'Vui lòng chọn đơn vị định mức.',
            'commission_type.in' => 'Đơn vị định mức không hợp lệ.',
            'commission_value.required' => 'Vui lòng nhập định mức (nhập 0 nếu không có hoa hồng).',
            'commission_value.numeric' => 'Định mức phải là số.',
            'commission_value.min' => 'Định mức không được âm.',
            'commission_value.max' => 'Định mức quá lớn.',
            'commission_value.decimal' => 'Định mức tối đa 2 chữ số thập phân.',
            'note.max' => 'Ghi chú tối đa 500 ký tự.',
            'is_active.required' => 'Vui lòng chọn trạng thái.',
            'is_active.boolean' => 'Trạng thái không hợp lệ.',
        ]);

        // Hoa hồng không được vượt giá dịch vụ: định mức % tối đa 100, định mức tiền tối đa bằng giá
        $validator->after(function ($v) use ($request) {
            if ($v->errors()->hasAny(['price', 'commission_type', 'commission_value'])) {
                return;
            }

            $value = (float) $request->input('commission_value');
            if ($request->input('commission_type') === Commission::PERCENT) {
                if ($value > 100) {
                    $v->errors()->add('commission_value', 'Phần trăm hoa hồng không được vượt quá 100%.');
                }
            } elseif ($value > (float) $request->input('price')) {
                $v->errors()->add('commission_value', 'Hoa hồng không được lớn hơn giá dịch vụ.');
            }
        });

        return $validator->validate();
    }
}
