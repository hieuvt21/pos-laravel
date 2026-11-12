<?php

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Models\Customer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * API công khai của module Khách hàng. Module khác (Bán hàng, Thu chi...) chỉ gọi qua lớp này.
 */
class CustomerService
{
    public function find(int $id): ?Customer
    {
        return Customer::find($id);
    }

    /** Tìm nhanh theo tên, mã, số điện thoại (ô chọn khách ở màn POS). */
    public function search(string $term, int $limit = 10): Collection
    {
        return Customer::query()->search($term)->orderedBy('name')->limit($limit)->get();
    }

    /** Cộng dồn tổng chi tiêu khi thanh toán xong. Gọi trong transaction của hóa đơn. */
    public function addSpent(int $id, float $amount): void
    {
        DB::transaction(fn () => Customer::whereKey($id)->lockForUpdate()->firstOrFail()->increment('total_spent', $amount));
    }

    /** Trừ chi tiêu khi trả hàng / hủy hóa đơn (không để âm). */
    public function subtractSpent(int $id, float $amount): void
    {
        DB::transaction(function () use ($id, $amount) {
            $customer = Customer::whereKey($id)->lockForUpdate()->firstOrFail();
            $customer->update(['total_spent' => max(0, (float) $customer->total_spent - $amount)]);
        });
    }
}
