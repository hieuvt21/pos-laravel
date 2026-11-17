<?php

use App\Modules\Core\Models\Setting;
use App\Modules\Core\Support\Commission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('định mức là số tiền thì hoa hồng bằng đúng số tiền đó', function () {
    expect(Commission::amount('amount', 100000, 150000))->toBe(100000.0)
        ->and(Commission::amount('amount', 0, 150000))->toBe(0.0)
        ->and(Commission::amount('amount', 150000, 150000))->toBe(150000.0);
});

it('định mức là phần trăm thì hoa hồng bằng phần trăm của giá dịch vụ', function () {
    expect(Commission::amount('percent', 10, 150000))->toBe(15000.0)
        ->and(Commission::amount('percent', 100, 150000))->toBe(150000.0)
        ->and(Commission::amount('percent', 0, 150000))->toBe(0.0)
        ->and(Commission::amount('percent', 33.33, 100000))->toBe(33330.0);
});

it('hoa hồng không bao giờ vượt giá dịch vụ hoặc âm', function () {
    expect(Commission::amount('amount', 200000, 150000))->toBe(150000.0)     // giá bị sửa xuống thấp hơn định mức
        ->and(Commission::amount('percent', 150, 150000))->toBe(150000.0)
        ->and(Commission::amount('amount', -5, 150000))->toBe(0.0)
        ->and(Commission::amount('amount', 10, 0))->toBe(0.0);
});

it('làm tròn theo số lẻ của tiền tệ trong cài đặt cửa hàng', function () {
    // mặc định không có số lẻ: 12,5% của 99 = 12,375 -> 12
    expect(Commission::amount('percent', 12.5, 99))->toBe(12.0);

    Setting::putJson('finance', array_merge(Setting::finance(), ['decimals' => 2]));
    expect(Commission::amount('percent', 12.5, 99))->toBe(12.38);
});
