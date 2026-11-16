<?php

namespace App\Modules\ServiceCatalog\Services;

use App\Modules\ServiceCatalog\Models\Service;

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
