<?php

namespace App\Modules\ServiceCatalog\Services;

use App\Modules\Core\Support\Commission;
use App\Modules\ServiceCatalog\Models\Service;
use App\Modules\ServiceCatalog\Models\ServicePackage;

/**
 * API công khai của module Dịch vụ (Bán hàng gọi qua lớp này, không đụng bảng/Model).
 * Trả mảng thuần. Dịch vụ không có đơn vị và tồn kho; số lượng trên hóa đơn là số lần (số nguyên).
 * Khi bán, Bán hàng lưu bản chụp tên, giá, hoa hồng vào dòng hóa đơn; hoa hồng của dòng = `commission` × số lượng.
 */
class ServiceCatalogService
{
    /**
     * Một dịch vụ (kể cả đã xóa mềm, để xem lại hóa đơn cũ). Null nếu không có.
     *
     * @return array{id: int, code: string, name: string, category_id: ?int, category: ?string, price: float, commission_type: string, commission_value: float, commission: float, note: ?string, is_active: bool}|null
     */
    public function find(int $id): ?array
    {
        $service = Service::withTrashed()->with('category:id,name')->find($id);

        return $service ? $this->toArray($service) : null;
    }

    /**
     * Tìm dịch vụ theo tên hoặc mã (màn bán hàng). Mặc định chỉ lấy dịch vụ đang bán.
     *
     * @return list<array>
     */
    public function search(string $query = '', ?int $categoryId = null, bool $onlyActive = true, int $limit = 30): array
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($query)).'%';

        return Service::query()
            ->with('category:id,name')
            ->when($onlyActive, fn ($q) => $q->where('is_active', true))
            ->when(trim($query) !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('code', 'like', $like)))
            ->when($categoryId, fn ($q) => $q->where('service_category_id', $categoryId))
            ->orderBy('name')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (Service $s) => $this->toArray($s))
            ->all();
    }

    /**
     * Một combo / liệu trình (kể cả đã xóa mềm). Null nếu không có. Xem `packageArray` để biết các khóa.
     */
    public function findPackage(int $id): ?array
    {
        $package = ServicePackage::withTrashed()->with(['category:id,name', 'items.service'])->find($id);

        return $package ? $this->packageArray($package) : null;
    }

    /**
     * Tìm combo / liệu trình theo tên hoặc mã. `$type` = 'combo' | 'course' | null (cả hai). Mặc định chỉ gói đang bán.
     *
     * @return list<array>
     */
    public function searchPackages(string $query = '', ?string $type = null, bool $onlyActive = true, int $limit = 30): array
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($query)).'%';

        return ServicePackage::query()
            ->with(['category:id,name', 'items.service'])
            ->when($type !== null, fn ($q) => $q->where('type', $type))
            ->when($onlyActive, fn ($q) => $q->where('is_active', true))
            ->when(trim($query) !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('code', 'like', $like)))
            ->orderBy('name')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (ServicePackage $p) => $this->packageArray($p))
            ->all();
    }

    /**
     * Mảng thuần của một gói. Hoa hồng không lưu mà tính từ giá và định mức:
     *  - `sale_commission`: hoa hồng người bán gói (trên giá gói).
     *  - mỗi dòng `items[].commission`: hoa hồng người thực hiện cho MỘT lần dùng dịch vụ đó (định mức chung hoặc riêng, tính trên giá lẻ của dịch vụ).
     * `quantity` null = không giới hạn số lần. `retail_total` = tổng giá lẻ (null nếu có dòng không giới hạn số lần).
     * Hạn sử dụng tính từ ngày mua (`validity_days`, null = không hạn); `warranty_days` là số ngày bảo hành sau khi dùng hết gói.
     * Quan hệ `items.service` cần được nạp sẵn (with) để tránh truy vấn lặp.
     */
    public function packageArray(ServicePackage $p): array
    {
        $items = $p->items->map(function ($item) use ($p) {
            [$type, $value] = $p->usageQuota($item);
            $service = $item->service;
            $price = (float) ($service?->price ?? 0);

            return [
                'service_id' => $item->service_id,
                'service_code' => $service?->code,
                'service_name' => $service?->name,
                'service_price' => $price,
                'service_active' => (bool) ($service && $service->is_active && ! $service->trashed()),
                'quantity' => $item->quantity,
                'commission_type' => $type,
                'commission_value' => $value,
                'commission' => Commission::amount($type, $value, $price),
            ];
        })->values();

        $unlimited = $items->contains(fn (array $i) => $i['quantity'] === null);

        return [
            'id' => $p->id,
            'code' => $p->code,
            'type' => $p->type,
            'name' => $p->name,
            'category_id' => $p->service_category_id,
            'category' => $p->category?->name,
            'price' => $p->price,
            'validity_days' => $p->validity_days,
            'warranty_days' => $p->warranty_days,
            'sale_commission_type' => $p->sale_commission_type,
            'sale_commission_value' => $p->sale_commission_value,
            'sale_commission' => $p->sale_commission,
            'usage_commission_mode' => $p->usage_commission_mode,
            'usage_commission_type' => $p->usage_commission_type,
            'usage_commission_value' => $p->usage_commission_value,
            'note' => $p->note,
            'is_active' => $p->is_active,
            'retail_total' => $unlimited ? null : (float) $items->sum(fn (array $i) => $i['service_price'] * $i['quantity']),
            'items' => $items->all(),
        ];
    }

    private function toArray(Service $s): array
    {
        return [
            'id' => $s->id,
            'code' => $s->code,
            'name' => $s->name,
            'category_id' => $s->service_category_id,
            'category' => $s->category?->name,
            'price' => $s->price,
            'commission_type' => $s->commission_type,
            'commission_value' => $s->commission_value,
            'commission' => $s->commission,
            'note' => $s->note,
            'is_active' => $s->is_active,
        ];
    }
}
