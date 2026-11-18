<?php

namespace App\Modules\ServiceCatalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\Commission;
use App\Modules\Core\Support\DocumentNumber;
use App\Modules\Core\Support\UniqueName;
use App\Modules\ServiceCatalog\Models\Service;
use App\Modules\ServiceCatalog\Models\ServiceCategory;
use App\Modules\ServiceCatalog\Models\ServicePackage;
use App\Modules\ServiceCatalog\Services\ServiceCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Mẫu gói bán trước: Combo (1 dịch vụ) và Liệu trình (nhiều dịch vụ). Hai menu, một controller. */
class ServicePackageController extends Controller
{
    public function __construct(private readonly ServiceCatalogService $catalog) {}

    public function index(Request $request, string $type): Response
    {
        $type = $type === ServicePackage::COURSE ? ServicePackage::COURSE : ServicePackage::COMBO;
        $search = trim((string) $request->query('search', ''));
        $category = is_numeric($request->query('category')) ? (int) $request->query('category') : null;
        $status = in_array($request->query('status'), ['active', 'inactive'], true) ? $request->query('status') : null;
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';

        $packages = ServicePackage::query()
            ->with(['category:id,name', 'items.service'])
            ->where('type', $type)
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('code', 'like', $like)))
            ->when($category, fn ($q) => $q->where('service_category_id', $category))
            ->when($status, fn ($q) => $q->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->paginate(UniqueName::perPage($request->query('per_page')))
            ->withQueryString()
            ->through(fn (ServicePackage $p) => $this->catalog->packageArray($p));

        return Inertia::render('Services/Packages', [
            'type' => $type,
            'packages' => $packages,
            'filters' => ['search' => $search, 'category' => $category, 'status' => $status],
            'categories' => ServiceCategory::orderBy('name')->get(['id', 'name', 'is_active'])
                ->map(fn (ServiceCategory $c) => ['id' => $c->id, 'name' => $c->name, 'is_active' => $c->is_active])->values(),
            // Dịch vụ chọn được khi lập gói (đang bán); kèm định mức để điền sẵn hoa hồng
            'services' => Service::where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'price', 'commission_type', 'commission_value'])
                ->map(fn (Service $s) => [
                    'id' => $s->id, 'name' => $s->name, 'price' => $s->price,
                    'commission_type' => $s->commission_type, 'commission_value' => $s->commission_value,
                ])->values(),
        ]);
    }

    /** Kiểm tra trùng tên ngay khi nhập (bỏ qua gói đã xóa). */
    public function check(Request $request): JsonResponse
    {
        $name = trim((string) $request->input('value'));
        if ($name === '') {
            return response()->json(['valid' => true, 'available' => true]);
        }

        $taken = UniqueName::exists('service_packages', $name, $request->integer('ignore') ?: null, softDeletes: true);

        return response()->json(['valid' => true, 'available' => ! $taken, 'message' => $taken ? 'Tên gói này đã có.' : null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $type = $request->validate(
            ['type' => ['required', Rule::in(ServicePackage::TYPES)]],
            ['type.required' => 'Thiếu loại gói.', 'type.in' => 'Loại gói không hợp lệ.'],
        )['type'];

        [$attrs, $items] = $this->validated($request, $type);

        DB::transaction(function () use ($attrs, $items, $type) {
            $package = ServicePackage::create($attrs + ['type' => $type, 'code' => DocumentNumber::next('service_package')]);
            $package->items()->createMany($items);
        });

        return back()->with('success', $type === ServicePackage::COMBO ? 'Đã thêm combo.' : 'Đã thêm liệu trình.');
    }

    public function update(Request $request, ServicePackage $package): RedirectResponse
    {
        // Loại gói không đổi được sau khi tạo
        [$attrs, $items] = $this->validated($request, $package->type, $package);

        DB::transaction(function () use ($package, $attrs, $items) {
            $package->update($attrs);
            $package->items()->delete();
            $package->items()->createMany($items);
        });

        return back()->with('success', $package->type === ServicePackage::COMBO ? 'Đã cập nhật combo.' : 'Đã cập nhật liệu trình.');
    }

    public function destroy(ServicePackage $package): RedirectResponse
    {
        $package->delete(); // xóa mềm: gói khách đã mua (Bước B) vẫn giữ bản chụp

        return back()->with('success', $package->type === ServicePackage::COMBO ? 'Đã xóa combo.' : 'Đã xóa liệu trình.');
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} [thuộc tính gói, các dòng dịch vụ]
     */
    private function validated(Request $request, string $type, ?ServicePackage $package = null): array
    {
        $isCombo = $type === ServicePackage::COMBO;
        $noun = $isCombo ? 'combo' : 'liệu trình';

        // Dịch vụ chọn được: đang bán, hoặc đang có sẵn trong gói này (khi sửa)
        $allowedServices = Service::where('is_active', true)->pluck('id')
            ->merge($package?->items()->pluck('service_id') ?? [])
            ->map(fn ($id) => (int) $id)->all();

        $money = ['required', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'];

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:150', fn ($attr, $value, $fail) => UniqueName::exists('service_packages', (string) $value, $package?->id, softDeletes: true) ? $fail('Tên gói này đã có.') : null],
            'service_category_id' => ['bail', 'required', 'integer', function ($attr, $value, $fail) use ($package) {
                $category = ServiceCategory::find($value);
                if (! $category || (! $category->is_active && $category->id !== $package?->service_category_id)) {
                    $fail('Danh mục không hợp lệ hoặc đã ngừng sử dụng.');
                }
            }],
            'price' => $money,
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'warranty_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'sale_commission_type' => ['required', Rule::in(Commission::TYPES)],
            'sale_commission_value' => $money,
            'usage_commission_mode' => ['required', Rule::in(ServicePackage::MODES)],
            'usage_commission_type' => ['required', Rule::in(Commission::TYPES)],
            'usage_commission_value' => $money,
            'note' => ['nullable', 'string', 'max:500'],
            'is_active' => ['required', 'boolean'],
            'items' => ['required', 'array', $isCombo ? 'size:1' : 'min:2', 'max:30'],
            'items.*.service_id' => ['required', 'integer', 'distinct', fn ($attr, $value, $fail) => in_array((int) $value, $allowedServices, true) ? null : $fail('Dịch vụ không hợp lệ hoặc đã ngừng bán.')],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'items.*.commission_type' => ['required', Rule::in(Commission::TYPES)],
            'items.*.commission_value' => $money,
        ], [
            'name.required' => "Vui lòng nhập tên {$noun}.",
            'name.max' => 'Tên tối đa 150 ký tự.',
            'service_category_id.required' => 'Vui lòng chọn danh mục dịch vụ.',
            'service_category_id.integer' => 'Danh mục không hợp lệ.',
            'price.required' => 'Vui lòng nhập giá gói.',
            'price.numeric' => 'Giá phải là số.',
            'price.min' => 'Giá không được âm.',
            'price.max' => 'Giá quá lớn.',
            'price.decimal' => 'Giá tối đa 2 chữ số thập phân.',
            'validity_days.integer' => 'Hạn sử dụng phải là số ngày.',
            'validity_days.min' => 'Hạn sử dụng tối thiểu 1 ngày.',
            'validity_days.max' => 'Hạn sử dụng tối đa 3650 ngày.',
            'warranty_days.integer' => 'Thời gian bảo hành phải là số ngày.',
            'warranty_days.min' => 'Thời gian bảo hành tối thiểu 1 ngày.',
            'warranty_days.max' => 'Thời gian bảo hành tối đa 3650 ngày.',
            'sale_commission_type.required' => 'Vui lòng chọn đơn vị định mức.',
            'sale_commission_type.in' => 'Đơn vị định mức không hợp lệ.',
            'sale_commission_value.required' => 'Vui lòng nhập định mức (nhập 0 nếu không có hoa hồng).',
            'sale_commission_value.numeric' => 'Định mức phải là số.',
            'sale_commission_value.min' => 'Định mức không được âm.',
            'sale_commission_value.max' => 'Định mức quá lớn.',
            'sale_commission_value.decimal' => 'Định mức tối đa 2 chữ số thập phân.',
            'usage_commission_mode.required' => 'Vui lòng chọn cách tính hoa hồng sử dụng.',
            'usage_commission_mode.in' => 'Cách tính hoa hồng sử dụng không hợp lệ.',
            'usage_commission_type.in' => 'Đơn vị định mức không hợp lệ.',
            'usage_commission_value.numeric' => 'Định mức phải là số.',
            'usage_commission_value.min' => 'Định mức không được âm.',
            'usage_commission_value.max' => 'Định mức quá lớn.',
            'usage_commission_value.decimal' => 'Định mức tối đa 2 chữ số thập phân.',
            'note.max' => 'Ghi chú tối đa 500 ký tự.',
            'is_active.required' => 'Vui lòng chọn trạng thái.',
            'is_active.boolean' => 'Trạng thái không hợp lệ.',
            'items.required' => 'Vui lòng chọn dịch vụ cho gói.',
            'items.array' => 'Danh sách dịch vụ không hợp lệ.',
            'items.size' => 'Combo chỉ gồm đúng 1 dịch vụ.',
            'items.min' => 'Liệu trình cần ít nhất 2 dịch vụ.',
            'items.max' => 'Một gói tối đa 30 dịch vụ.',
            'items.*.service_id.required' => 'Vui lòng chọn dịch vụ.',
            'items.*.service_id.integer' => 'Dịch vụ không hợp lệ.',
            'items.*.service_id.distinct' => 'Dịch vụ này đã được chọn trong gói.',
            'items.*.quantity.integer' => 'Số lần phải là số nguyên.',
            'items.*.quantity.min' => 'Số lần tối thiểu là 1.',
            'items.*.quantity.max' => 'Số lần tối đa là 9999.',
            'items.*.commission_type.required' => 'Vui lòng chọn đơn vị định mức.',
            'items.*.commission_type.in' => 'Đơn vị định mức không hợp lệ.',
            'items.*.commission_value.required' => 'Vui lòng nhập định mức (nhập 0 nếu không có hoa hồng).',
            'items.*.commission_value.numeric' => 'Định mức phải là số.',
            'items.*.commission_value.min' => 'Định mức không được âm.',
            'items.*.commission_value.max' => 'Định mức quá lớn.',
            'items.*.commission_value.decimal' => 'Định mức tối đa 2 chữ số thập phân.',
        ]);

        $perItem = $isCombo || $request->input('usage_commission_mode') !== ServicePackage::MODE_COMMON;

        $validator->after(function ($v) use ($request, $perItem) {
            if ($v->errors()->isNotEmpty()) {
                return; // sửa lỗi cơ bản trước, các kiểm tra chéo dưới đây cần dữ liệu hợp lệ
            }

            $price = (float) $request->input('price');
            $items = array_values($request->input('items'));
            $services = Service::withTrashed()->whereIn('id', array_column($items, 'service_id'))->get()->keyBy('id');

            // Hoa hồng người bán: tính trên giá gói
            $this->checkQuota($v, 'sale_commission_value', $request->input('sale_commission_type'), (float) $request->input('sale_commission_value'), $price, 'giá gói');

            // Gói không có hạn thì mọi dịch vụ phải giới hạn số lần (gói phải có ít nhất một giới hạn)
            if ($request->input('validity_days') === null || $request->input('validity_days') === '') {
                foreach ($items as $i => $item) {
                    if (($item['quantity'] ?? null) === null || $item['quantity'] === '') {
                        $v->errors()->add("items.$i.quantity", 'Gói không có hạn sử dụng thì phải giới hạn số lần.');
                    }
                }
            }

            if ($perItem) {
                // Mỗi dịch vụ một định mức, tính trên giá lẻ của chính dịch vụ đó
                foreach ($items as $i => $item) {
                    $this->checkQuota($v, "items.$i.commission_value", $item['commission_type'], (float) $item['commission_value'], (float) $services[$item['service_id']]->price, 'giá dịch vụ');
                }
            } else {
                // Định mức chung: tiền không được lớn hơn giá dịch vụ thấp nhất trong gói
                $min = (float) $services->min('price');
                $this->checkQuota($v, 'usage_commission_value', $request->input('usage_commission_type'), (float) $request->input('usage_commission_value'), $min, 'giá dịch vụ thấp nhất trong gói');
            }
        });

        $data = $validator->validate();

        $attrs = [
            'name' => $data['name'],
            'service_category_id' => $data['service_category_id'],
            'price' => $data['price'],
            'validity_days' => $data['validity_days'] ?? null,
            'warranty_days' => $data['warranty_days'] ?? null,
            'sale_commission_type' => $data['sale_commission_type'],
            'sale_commission_value' => $data['sale_commission_value'],
            'usage_commission_mode' => $perItem ? ServicePackage::MODE_PER_ITEM : ServicePackage::MODE_COMMON,
            // Chỉ lưu định mức chung khi dùng chế độ chung; ngược lại đặt về 0 để khỏi nhầm
            'usage_commission_type' => $perItem ? Commission::AMOUNT : $data['usage_commission_type'],
            'usage_commission_value' => $perItem ? 0 : $data['usage_commission_value'],
            'note' => $data['note'] ?? null,
            'is_active' => $data['is_active'],
        ];

        $items = array_map(fn (array $item) => [
            'service_id' => (int) $item['service_id'],
            'quantity' => ($item['quantity'] ?? null) === null ? null : (int) $item['quantity'],
            'commission_type' => $perItem ? $item['commission_type'] : Commission::AMOUNT,
            'commission_value' => $perItem ? $item['commission_value'] : 0,
        ], array_values($data['items']));

        return [$attrs, $items];
    }

    /** % tối đa 100; định mức tiền tối đa bằng giá dùng để tính hoa hồng. */
    private function checkQuota($validator, string $field, string $type, float $value, float $price, string $priceLabel): void
    {
        if ($type === Commission::PERCENT) {
            if ($value > 100) {
                $validator->errors()->add($field, 'Phần trăm hoa hồng không được vượt quá 100%.');
            }
        } elseif ($value > $price) {
            $validator->errors()->add($field, "Hoa hồng không được lớn hơn {$priceLabel}.");
        }
    }
}
